<?php
declare(strict_types=1);

namespace Tests;

use app\service\DailyOneThingService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DailyOneThingServiceTest extends TestCase
{
    public function testSelectsExactlyOneCtripGapAndDoesNotExposeCandidatePile(): void
    {
        $service = new DailyOneThingService();
        $result = $service->select([
            $this->candidate('question:7:action:0', 'saved_question', [90, 90, 96, 20], 'meituan'),
            $this->candidate('gap:ctrip:core_facts', 'explicit_data_gap', [100, 100, 100, 18], 'ctrip'),
            $this->candidate('signal:meituan:traffic', 'strict_fact_signal', [72, 74, 94, 24], 'meituan'),
        ], '2026-08-26');

        self::assertSame('daily_one_thing.v2', $result['contract_version']);
        self::assertSame('draft', $result['status']);
        self::assertSame('gap:ctrip:core_facts', $result['selected']['candidate_key']);
        self::assertSame('draft', $result['selected']['approval_status']);
        self::assertSame('daily_one_thing.explainable.v3', $result['experience_version']);
        self::assertSame(
            'daily_one_thing_explanation.v1',
            $result['selected']['recommendation_explanation']['contract_version']
        );
        self::assertNotSame('', $result['selected']['recommendation_explanation']['why_now']['summary']);
        self::assertNotSame('', $result['selected']['recommendation_explanation']['why_recommended']['summary']);
        self::assertSame(
            'not_applied',
            $result['selected']['recommendation_explanation']['personalization']['status']
        );
        self::assertFalse(
            $result['selected']['recommendation_explanation']['personalization']['facts_changed']
        );
        self::assertSame(3, $result['candidate_count']);
        self::assertArrayNotHasKey('candidates', $result);
        self::assertFalse($result['selection_policy']['full_candidate_list_exposed']);
        self::assertFalse($result['can_execute']);
        self::assertSame(0, $result['selected']['external_write_boundary']['external_write_count_before_approval']);
    }

    #[DataProvider('rankingProvider')]
    public function testEachRankingDimensionCanDecideTheOneSelectedItem(
        array $leftScores,
        array $rightScores,
        string $expectedKey
    ): void {
        $result = (new DailyOneThingService())->select([
            $this->candidate('signal:left:item', 'strict_fact_signal', $leftScores, 'ctrip'),
            $this->candidate('signal:right:item', 'strict_fact_signal', $rightScores, 'ctrip'),
        ], '2026-08-26');

        self::assertSame($expectedKey, $result['selected']['candidate_key']);
    }

    public static function rankingProvider(): array
    {
        return [
            'impact' => [[90, 10, 10, 90], [89, 100, 100, 1], 'signal:left:item'],
            'urgency' => [[90, 80, 10, 90], [90, 79, 100, 1], 'signal:left:item'],
            'evidence strength' => [[90, 80, 70, 90], [90, 80, 69, 1], 'signal:left:item'],
            'execution cost lower wins' => [[90, 80, 70, 20], [90, 80, 70, 21], 'signal:left:item'],
        ];
    }

    public function testUnapprovedSourceTypeAndAutomaticWriteCandidateFailClosed(): void
    {
        $untrusted = $this->candidate('manual:freeform:item', 'manual_input', [100, 100, 100, 1], 'ctrip');
        $unsafe = $this->candidate('signal:unsafe:item', 'strict_fact_signal', [100, 100, 100, 1], 'ctrip');
        $unsafe['external_write_boundary']['automatic_ctrip_write'] = true;

        $result = (new DailyOneThingService())->select([$untrusted, $unsafe], '2026-08-26');

        self::assertSame('no_eligible_item', $result['status']);
        self::assertNull($result['selected']);
        self::assertSame(2, $result['rejected_candidate_count']);
        self::assertFalse($result['external_write_performed']);
    }

    public function testTieBreakIsStableByCandidateKey(): void
    {
        $scores = [80, 80, 80, 20];
        $result = (new DailyOneThingService())->select([
            $this->candidate('signal:z:item', 'strict_fact_signal', $scores, 'ctrip'),
            $this->candidate('signal:a:item', 'strict_fact_signal', $scores, 'ctrip'),
        ], '2026-08-26');

        self::assertSame('signal:a:item', $result['selected']['candidate_key']);
    }

    public function testExplicitGapMaterialIdentityIgnoresVolatileRuntimeSnapshotDigest(): void
    {
        $left = $this->candidate('gap:ctrip:core_facts', 'explicit_data_gap', [100, 100, 100, 18], 'ctrip');
        $left['source']['gap_codes'] = ['ctrip_core_facts_missing'];
        $right = $left;
        $right['source']['snapshot_digest'] = str_repeat('b', 64);
        $right['fact_basis'][0]['evidence_ref'] = 'dual_ota_field_closure:80:2026-08-26:ctrip';

        self::assertSame(
            DailyOneThingService::materialIdentityDigest($left),
            DailyOneThingService::materialIdentityDigest($right)
        );
    }

    public function testRejectsInvalidBusinessDate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new DailyOneThingService())->select([], '2026-02-30');
    }

    /** @param array{0:int,1:int,2:int,3:int} $scores @return array<string,mixed> */
    private function candidate(string $key, string $sourceType, array $scores, string $platform): array
    {
        return [
            'candidate_key' => $key,
            'source_type' => $sourceType,
            'problem' => '这是一个需要今天处理的可信问题',
            'fact_basis' => [[
                'statement' => '同酒店同平台同日期事实已精确回读，或缺口已明确记录。',
                'evidence_ref' => 'online_daily_data#101',
                'quality_status' => 'strict_readback',
            ]],
            'recommended_action' => [
                'type' => 'human_reviewed_operating_check',
                'object' => 'ota_fact_scope',
                'title' => '只读核对一项事实',
                'description' => '先核对事实，再由用户决定是否执行后续动作。',
                'steps' => ['打开同范围页面只读核对。', '把真实证据绑定原任务。'],
            ],
            'expected_observation_metric' => [
                'key' => 'detail_exposure',
                'label' => '详情曝光',
                'unit' => 'exposure_count',
                'baseline_value' => 10,
                'aggregation' => 'latest',
            ],
            'scope' => [
                'tenant_id' => 80,
                'hotel_id' => 80,
                'platform' => $platform,
                'business_date' => '2026-08-26',
                'metric_scope' => 'ota_channel',
                'scope_note' => '只属于当前平台当前酒店当前日期，不扩大为全酒店结论。',
            ],
            'risk' => [
                'level' => 'low',
                'summary' => '风险是误用不同范围事实。',
                'controls' => ['只读核对，不自动写平台。'],
                'stop_conditions' => ['身份或日期不一致时停止。'],
            ],
            'responsibility' => [
                'owner_id' => 1,
                'owner_label' => '当前确认人',
                'due_at' => '2026-08-26 23:00:00',
                'review_at' => '2026-08-27 10:00:00',
            ],
            'ranking' => [
                'impact' => $scores[0],
                'urgency' => $scores[1],
                'evidence_strength' => $scores[2],
                'execution_cost' => $scores[3],
                'reasons' => [],
            ],
            'source' => [
                'record_id' => 101,
                'record_ref' => 'online_daily_data#101',
                'snapshot_digest' => str_repeat('a', 64),
                'fact_refs' => ['online_daily_data#101'],
                'gap_codes' => [],
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
        ];
    }
}
