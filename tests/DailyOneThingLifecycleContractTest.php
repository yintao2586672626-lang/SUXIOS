<?php
declare(strict_types=1);

namespace Tests;

use app\service\DailyOneThingService;
use app\service\OperationActionLifecycleService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DailyOneThingLifecycleContractTest extends TestCase
{
    public function testV2CardFreezesEveryRequiredDailyFieldAndZeroWriteBoundary(): void
    {
        $selected = $this->selected();
        $run = [
            'id' => 901,
            'tenant_id' => 80,
            'system_hotel_id' => 80,
            'feature_key' => 'daily_one_thing',
            'business_date' => '2026-08-26',
            'input_digest' => str_repeat('1', 64),
            'result_digest' => str_repeat('2', 64),
        ];
        $card = (new OperationActionLifecycleService())->buildDailyOneThingPendingCard(
            $run,
            $selected,
            7,
            new \DateTimeImmutable('2026-08-26 09:00:00', new \DateTimeZone('Asia/Shanghai'))
        );

        self::assertSame('operation_action_card.v2', $card['contract_version']);
        self::assertSame('pending_approval', $card['status']);
        self::assertSame($selected['problem'], $card['problem']);
        self::assertSame($selected['fact_basis'], $card['fact_basis']);
        self::assertSame($selected['recommended_action']['description'], $card['action']['description']);
        self::assertSame('ctrip_strict_core_fact_count', $card['metric_contract']['metric_key']);
        self::assertSame('ota_channel_data_quality', $card['source']['source_scope']);
        self::assertSame(7, $card['responsibility']['owner_id']);
        self::assertSame('2026-08-26 23:00:00', $card['responsibility']['due_at']);
        self::assertSame('human_confirmation', $card['approval']['mode']);
        self::assertFalse($card['boundaries']['automatic_ctrip_write']);
        self::assertFalse($card['boundaries']['automatic_meituan_write']);
        self::assertFalse($card['boundaries']['automatic_pms_write']);
        self::assertFalse($card['boundaries']['automatic_wecom_message']);
        self::assertFalse($card['boundaries']['automatic_execution']);
        self::assertSame(0, $card['boundaries']['external_write_count_before_approval']);
        self::assertContains('operating_opportunity_runs#901', $card['fact_refs']);
        self::assertSame($selected['content_digest'], $card['trace']['daily_selection_digest']);
        self::assertSame(
            $selected['recommendation_explanation'],
            $card['recommendation_explanation']
        );
        self::assertSame(
            'not_applied',
            $card['recommendation_explanation']['personalization']['status']
        );
        self::assertFalse(
            $card['recommendation_explanation']['personalization']['external_write_authorized']
        );
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $card['identity_digest']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $card['content_digest']);
    }

    public function testV2LifecycleAllowsOnlyTheFixedAdjacentSequenceAndBlockedExit(): void
    {
        $service = new OperationActionLifecycleService();
        $method = new \ReflectionMethod($service, 'assertDailyTransition');
        $sequence = [
            ['', 'draft'],
            ['draft', 'pending_approval'],
            ['pending_approval', 'approved'],
            ['approved', 'executing'],
            ['executing', 'evidence_recorded'],
            ['evidence_recorded', 'review_pending'],
            ['review_pending', 'reviewed'],
        ];
        foreach ($sequence as [$from, $to]) {
            self::assertNull($method->invoke($service, $from, $to));
        }
        foreach (['draft', 'pending_approval', 'approved', 'executing', 'evidence_recorded', 'review_pending'] as $from) {
            self::assertNull($method->invoke($service, $from, 'blocked'));
        }

        $this->expectException(InvalidArgumentException::class);
        $method->invoke($service, 'pending_approval', 'evidence_recorded');
    }

    public function testV2AllowedStatusesContainNoLegacyAliases(): void
    {
        self::assertSame([
            'draft',
            'pending_approval',
            'approved',
            'executing',
            'evidence_recorded',
            'review_pending',
            'reviewed',
            'blocked',
        ], OperationActionLifecycleService::DAILY_STATUSES);
        self::assertNotContains('in_progress', OperationActionLifecycleService::DAILY_STATUSES);
        self::assertNotContains('completed', OperationActionLifecycleService::DAILY_STATUSES);
        self::assertNotContains('cancelled', OperationActionLifecycleService::DAILY_STATUSES);
    }

    public function testV2EventChainIntegrityUsesTheDailyStatusContract(): void
    {
        $service = new OperationActionLifecycleService();
        $eventDigest = new \ReflectionMethod($service, 'eventDigest');
        $verifyEventChain = new \ReflectionMethod($service, 'verifyEventChain');
        $events = [];
        $previousStatus = '';
        $previousDigest = '';
        foreach ([
            'draft',
            'pending_approval',
            'approved',
            'executing',
            'evidence_recorded',
            'review_pending',
        ] as $index => $status) {
            $event = [
                'tenant_id' => 80,
                'hotel_id' => 80,
                'intent_id' => 901,
                'task_id' => 0,
                'sequence_no' => $index + 1,
                'event_type' => $status . '_recorded',
                'from_status' => $previousStatus,
                'to_status' => $status,
                'actor_id' => 7,
                'event_payload' => [],
                'previous_digest' => $previousDigest,
                'created_at' => sprintf('2026-08-26 09:%02d:00', $index),
            ];
            $event['content_digest'] = (string)$eventDigest->invoke($service, $event);
            $events[] = $event;
            $previousStatus = $status;
            $previousDigest = $event['content_digest'];
        }

        self::assertSame(
            ['status' => 'verified', 'failure_reason' => null],
            $verifyEventChain->invoke(
                $service,
                $events,
                80,
                80,
                901,
                null,
                OperationActionLifecycleService::DAILY_STATUSES
            )
        );
        self::assertSame(
            'invalid',
            $verifyEventChain->invoke(
                $service,
                $events,
                80,
                80,
                901,
                null,
                OperationActionLifecycleService::STATUSES
            )['status']
        );
    }

    /** @return array<string,mixed> */
    private function selected(): array
    {
        $result = (new DailyOneThingService())->select([[
            'candidate_key' => 'gap:ctrip:target_date_source_rows',
            'source_type' => 'explicit_data_gap',
            'problem' => '携程目标日期可信事实尚未回读',
            'fact_basis' => [[
                'statement' => '严格事实层确认当前缺少携程目标日期核心字段。',
                'evidence_ref' => 'dual_ota_field_closure#abc123',
                'quality_status' => 'gap_readback_verified',
            ]],
            'recommended_action' => [
                'type' => 'collect_trusted_ota_facts',
                'object' => 'ctrip_target_date_strict_facts',
                'title' => '补齐携程目标日期可信事实',
                'description' => '只补齐事实，不调价、不改房态。',
                'steps' => ['读取目标日期。', '保存并回读。', '绑定原任务证据。'],
            ],
            'expected_observation_metric' => [
                'key' => 'ctrip_strict_core_fact_count',
                'label' => '携程严格核心事实数',
                'unit' => 'verified_fields',
                'baseline_value' => 0,
                'aggregation' => 'latest',
            ],
            'scope' => [
                'tenant_id' => 80,
                'hotel_id' => 80,
                'platform' => 'ctrip',
                'business_date' => '2026-08-26',
                'metric_scope' => 'ota_channel_data_quality',
                'scope_note' => '仅限携程目标日期数据完整性。',
            ],
            'risk' => [
                'level' => 'low',
                'summary' => '防止误用旧数据。',
                'controls' => ['必须精确回读。'],
                'stop_conditions' => ['酒店或日期不一致时停止。'],
            ],
            'responsibility' => [
                'owner_id' => 7,
                'owner_label' => '当前确认人',
                'due_at' => '2026-08-26 23:00:00',
                'review_at' => '2026-08-27 10:00:00',
            ],
            'ranking' => [
                'impact' => 100,
                'urgency' => 100,
                'evidence_strength' => 100,
                'execution_cost' => 18,
                'reasons' => [],
            ],
            'source' => [
                'record_id' => 0,
                'record_ref' => 'dual_ota_field_closure#abc123',
                'snapshot_digest' => str_repeat('a', 64),
                'fact_refs' => [],
                'gap_codes' => ['ctrip_target_date_source_rows_missing'],
            ],
            'external_write_boundary' => [
                'automatic_ctrip_write' => false,
                'automatic_meituan_write' => false,
                'automatic_pms_write' => false,
                'automatic_wecom_message' => false,
                'automatic_execution' => false,
                'human_confirmation_required' => true,
                'causality_claimed' => false,
            ],
        ]], '2026-08-26');
        return $result['selected'];
    }
}
