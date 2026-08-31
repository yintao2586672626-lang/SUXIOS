<?php
declare(strict_types=1);

namespace Tests;

use app\service\LongitudinalEvidenceLearningService;
use app\service\OperatingOutcomeLearningRuntimeService;
use PHPUnit\Framework\TestCase;

final class OperatingOutcomeLearningRuntimeServiceTest extends TestCase
{
    public function testVerifiedExecutionFlowReviewIsLoadedAndBindsOnlyExactDailyCandidate(): void
    {
        $review = $this->review('increase', 80, 'ctrip');
        $service = new OperatingOutcomeLearningRuntimeService(
            static fn(int $tenantId, int $hotelId): array => [
                'truncated' => false,
                'list' => [[
                    'hotel_id' => $hotelId,
                    'evidence' => ['longitudinal_review' => $review],
                ]],
            ]
        );

        $loaded = $service->load(7, 80);
        self::assertSame('ready', $loaded['status']);
        self::assertSame(1, $loaded['reviewed_observation_count']);
        self::assertTrue($loaded['usable_for_tie_break']);
        self::assertFalse($loaded['causality_claimed']);
        self::assertSame(0, $loaded['external_write_count']);

        $candidate = $this->candidate();
        $bound = $service->bindDailyCandidates([$candidate], $loaded['reviewed_observations']);
        self::assertSame($review['comparison_key'], $bound[0]['outcome_learning_binding']['comparison_key']);
        self::assertSame('traffic_repair', $bound[0]['outcome_learning_binding']['action_type']);
        self::assertSame('increase', $bound[0]['outcome_learning_binding']['expected_direction']);

        $candidate['scope']['platform'] = 'meituan';
        $notBound = $service->bindDailyCandidates([$candidate], $loaded['reviewed_observations']);
        self::assertArrayNotHasKey('outcome_learning_binding', $notBound[0]);
    }

    public function testTruncatedFlowOrAmbiguousDirectionsCannotBind(): void
    {
        $blocked = (new OperatingOutcomeLearningRuntimeService(
            static fn(): array => ['truncated' => true, 'list' => []]
        ))->load(7, 80);
        self::assertSame('blocked', $blocked['status']);
        self::assertFalse($blocked['usable_for_tie_break']);
        self::assertContains('execution_flow_truncated', $blocked['data_gaps']);

        $increase = $this->review('increase', 80, 'ctrip');
        $decrease = $this->review('decrease', 80, 'ctrip');
        $decrease['action']['action_ref'] = 'operation_execution_task#2';
        $candidate = $this->candidate();
        $result = (new OperatingOutcomeLearningRuntimeService())->bindDailyCandidates(
            [$candidate],
            [$increase, $decrease]
        );
        self::assertArrayNotHasKey('outcome_learning_binding', $result[0]);
    }

    /** @return array<string,mixed> */
    private function review(string $direction, int $hotelId, string $platform): array
    {
        $baseline = [
            'system_hotel_id' => $hotelId,
            'platform' => $platform,
            'platform_hotel_id' => $platform . '-80',
            'business_module' => 'daily_one_thing',
            'subject' => 'traffic',
            'metric_key' => 'detail_exposure',
            'unit' => 'people',
            'source_method' => 'trusted_fact_readback',
            'date_role' => 'business_date',
            'fact_scope' => 'ota_channel',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-01',
            'target_stay_date' => '',
            'captured_at' => '2026-08-01 08:00:00',
            'quality_status' => 'verified',
            'readback_status' => 'readback_verified',
            'value' => 10,
            'evidence_refs' => ['online_daily_data#1'],
        ];
        $followup = [
            ...$baseline,
            'period_start' => '2026-08-02',
            'period_end' => '2026-08-02',
            'captured_at' => '2026-08-02 08:00:00',
            'value' => $direction === 'decrease' ? 8 : 12,
            'evidence_refs' => ['online_daily_data#2'],
        ];
        return (new LongitudinalEvidenceLearningService())->reviewAction(
            $baseline,
            $followup,
            [
                'action_ref' => 'operation_execution_task#1',
                'action_type' => 'traffic_repair',
                'execution_status' => 'executed',
                'executed_at' => '2026-08-01 10:00:00',
                'evidence_refs' => ['operation_execution_task#1'],
                'expected_direction' => $direction,
            ]
        );
    }

    /** @return array<string,mixed> */
    private function candidate(): array
    {
        return [
            'scope' => ['hotel_id' => 80, 'platform' => 'ctrip'],
            'expected_observation_metric' => ['key' => 'detail_exposure', 'unit' => 'people'],
            'recommended_action' => ['type' => 'traffic_repair'],
        ];
    }
}
