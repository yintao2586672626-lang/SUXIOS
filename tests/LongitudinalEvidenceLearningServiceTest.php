<?php
declare(strict_types=1);

namespace Tests;

use app\service\LongitudinalEvidenceLearningService;
use PHPUnit\Framework\TestCase;

final class LongitudinalEvidenceLearningServiceTest extends TestCase
{
    public function testExecutedOtaActionBecomesOneReviewedObservationWithoutCausalOrSopClaim(): void
    {
        $service = new LongitudinalEvidenceLearningService();
        $baseline = $this->snapshot([
            'platform' => 'meituan',
            'platform_hotel_id' => 'poi-80',
            'business_module' => 'keyword_workbench',
            'subject' => '敦煌酒店',
            'metric_key' => 'advertising_roas',
            'unit' => 'ratio',
            'fact_scope' => 'ota_channel_advertising',
            'period_start' => '2026-07-20',
            'period_end' => '2026-07-26',
            'captured_at' => '2026-07-27 08:00:00',
            'value' => 4.0,
            'evidence_refs' => ['online_daily_data#52'],
        ]);
        $followup = $this->snapshot([
            'platform' => 'meituan',
            'platform_hotel_id' => 'poi-80',
            'business_module' => 'keyword_workbench',
            'subject' => '敦煌酒店',
            'metric_key' => 'advertising_roas',
            'unit' => 'ratio',
            'fact_scope' => 'ota_channel_advertising',
            'period_start' => '2026-07-28',
            'period_end' => '2026-08-03',
            'captured_at' => '2026-08-04 08:00:00',
            'value' => 5.0,
            'evidence_refs' => ['online_daily_data#79'],
        ]);

        $result = $service->reviewAction($baseline, $followup, [
            'action_ref' => 'operation_execution_task#91',
            'action_type' => 'advertising_budget_review',
            'execution_status' => 'executed',
            'executed_at' => '2026-07-27 10:00:00',
            'evidence_refs' => ['operation_execution_task#91'],
            'expected_direction' => 'increase',
        ]);

        self::assertSame('verified', $result['status']);
        self::assertSame('action_reviewed', $result['learning_stage']);
        self::assertSame('increase', $result['delta']['movement']);
        self::assertSame('aligned', $result['action']['expectation_status']);
        self::assertFalse($result['causality_claimed']);
        self::assertFalse($result['promotion']['eligible']);
        self::assertSame('one_review_cannot_become_sop', $result['promotion']['reason_code']);
        self::assertMatchesRegularExpression('/^longitudinal:[a-f0-9]{64}$/', $result['comparison_key']);
    }

    public function testPmsSameDaySnapshotsPreserveExplicitZeroAndRemainAnObservation(): void
    {
        $service = new LongitudinalEvidenceLearningService();
        $baseline = $this->snapshot([
            'platform' => 'dingdandao_pms',
            'platform_hotel_id' => 'HOTEL9421',
            'business_module' => 'realtime',
            'subject' => 'whole_property',
            'metric_key' => 'sold_room_nights',
            'unit' => 'room_night',
            'fact_scope' => 'accommodation_room_fee',
            'period_start' => '2026-07-30',
            'period_end' => '2026-07-30',
            'captured_at' => '2026-07-30 08:00:00',
            'value' => 0,
            'evidence_refs' => ['dingdandao_operating_target_captures#301'],
        ]);
        $followup = [
            ...$baseline,
            'captured_at' => '2026-07-30 10:00:00',
            'value' => 0,
            'evidence_refs' => ['dingdandao_operating_target_captures#302'],
        ];

        $result = $service->compareSnapshots($baseline, $followup, 'same_day_realtime');

        self::assertSame('verified', $result['status']);
        self::assertSame('observation', $result['learning_stage']);
        self::assertSame(0.0, $result['baseline']['value']);
        self::assertSame(0.0, $result['followup']['value']);
        self::assertSame('unchanged', $result['delta']['movement']);
        self::assertNull($result['delta']['relative_percent']);
    }

    public function testCrossPlatformOrDifferentWindowCannotBecomeComparableEvidence(): void
    {
        $service = new LongitudinalEvidenceLearningService();
        $baseline = $this->snapshot([
            'platform' => 'ctrip',
            'platform_hotel_id' => 'ctrip-80',
            'business_module' => 'traffic',
            'subject' => 'hotel',
            'metric_key' => 'visitor_count',
            'unit' => 'people',
            'fact_scope' => 'ota_channel_traffic',
            'period_start' => '2026-07-20',
            'period_end' => '2026-07-26',
            'captured_at' => '2026-07-27 08:00:00',
            'value' => 100,
            'evidence_refs' => ['online_daily_data#1'],
        ]);
        $followup = [
            ...$baseline,
            'platform' => 'meituan',
            'platform_hotel_id' => 'poi-80',
            'period_start' => '2026-07-27',
            'period_end' => '2026-07-27',
            'captured_at' => '2026-07-28 08:00:00',
            'value' => 120,
            'evidence_refs' => ['online_daily_data#2'],
        ];

        $crossPlatform = $service->compareSnapshots($baseline, $followup);
        self::assertSame('not_comparable', $crossPlatform['status']);
        self::assertSame('scope_mismatch:platform', $crossPlatform['reason_code']);

        $followup['platform'] = 'ctrip';
        $followup['platform_hotel_id'] = 'ctrip-80';
        $differentWindow = $service->compareSnapshots($baseline, $followup);
        self::assertSame('not_comparable', $differentWindow['status']);
        self::assertSame('period_length_mismatch', $differentWindow['reason_code']);
    }

    public function testFutureObservationRequiresTheSameTargetStayDateAndVerifiedValue(): void
    {
        $service = new LongitudinalEvidenceLearningService();
        $baseline = $this->snapshot([
            'platform' => 'meituan',
            'platform_hotel_id' => 'poi-80',
            'business_module' => 'forward',
            'subject' => 'future_uv',
            'metric_key' => 'future_uv',
            'unit' => 'people',
            'date_role' => 'target_stay_date',
            'fact_scope' => 'ota_channel_future_traffic',
            'period_start' => '2026-07-30',
            'period_end' => '2026-07-30',
            'target_stay_date' => '2026-08-15',
            'captured_at' => '2026-07-30 09:00:00',
            'value' => 12,
            'evidence_refs' => ['online_daily_data#91'],
        ]);
        $followup = [
            ...$baseline,
            'period_start' => '2026-07-31',
            'period_end' => '2026-07-31',
            'target_stay_date' => '2026-08-16',
            'captured_at' => '2026-07-31 09:00:00',
            'value' => 14,
            'evidence_refs' => ['online_daily_data#92'],
        ];

        $targetMismatch = $service->compareSnapshots(
            $baseline,
            $followup,
            'target_stay_observation'
        );
        self::assertSame('not_comparable', $targetMismatch['status']);
        self::assertSame('target_stay_date_mismatch', $targetMismatch['reason_code']);

        $followup['target_stay_date'] = '2026-08-15';
        $followup['value'] = null;
        $missingValue = $service->compareSnapshots(
            $baseline,
            $followup,
            'target_stay_observation'
        );
        self::assertSame('not_comparable', $missingValue['status']);
        self::assertSame('followup_value_missing', $missingValue['reason_code']);
    }

    public function testRepeatedIndependentReviewsCreateOnlyAPatternCandidate(): void
    {
        $service = new LongitudinalEvidenceLearningService();
        $baseline = $this->snapshot([
            'platform_hotel_id' => 'poi-80',
            'business_module' => 'keyword_workbench',
            'subject' => '敦煌酒店',
            'metric_key' => 'advertising_roas',
            'unit' => 'ratio',
            'fact_scope' => 'ota_channel_advertising',
            'period_start' => '2026-07-20',
            'period_end' => '2026-07-20',
            'captured_at' => '2026-07-20 08:00:00',
            'value' => 4,
            'evidence_refs' => ['online_daily_data#101'],
        ]);
        $followup = [
            ...$baseline,
            'period_start' => '2026-07-21',
            'period_end' => '2026-07-21',
            'captured_at' => '2026-07-21 08:00:00',
            'value' => 5,
            'evidence_refs' => ['online_daily_data#102'],
        ];
        $first = $service->reviewAction($baseline, $followup, [
            'action_ref' => 'operation_execution_task#201',
            'action_type' => 'advertising_budget_review',
            'execution_status' => 'executed',
            'executed_at' => '2026-07-20 10:00:00',
            'evidence_refs' => ['operation_execution_task#201'],
            'expected_direction' => 'increase',
        ]);
        $second = [
            ...$first,
            'action' => [
                ...$first['action'],
                'action_ref' => 'operation_execution_task#202',
                'evidence_refs' => ['operation_execution_task#202'],
            ],
            'followup' => [
                ...$first['followup'],
                'captured_at' => '2026-07-22 08:00:00',
                'evidence_refs' => ['online_daily_data#103'],
            ],
        ];
        $third = [
            ...$second,
            'action' => [
                ...$second['action'],
                'action_ref' => 'operation_execution_task#203',
                'evidence_refs' => ['operation_execution_task#203'],
            ],
            'followup' => [
                ...$second['followup'],
                'captured_at' => '2026-07-23 08:00:00',
                'evidence_refs' => ['online_daily_data#104'],
            ],
        ];
        $duplicateThird = [
            ...$third,
            'followup' => [
                ...$third['followup'],
                'evidence_refs' => ['online_daily_data#105'],
            ],
        ];
        $sameFollowupNewAction = [
            ...$third,
            'action' => [
                ...$third['action'],
                'action_ref' => 'operation_execution_task#206',
                'evidence_refs' => ['operation_execution_task#206'],
            ],
        ];

        $summary = $service->summarizeReviews([
            $first,
            $second,
            $third,
            $duplicateThird,
            $sameFollowupNewAction,
        ]);

        self::assertSame('pattern_candidate', $summary['status']);
        self::assertSame(3, $summary['reviewed_observation_count']);
        self::assertSame(2, $summary['duplicate_review_count']);
        self::assertSame(1, $summary['pattern_candidate_count']);
        self::assertSame(1, $summary['outcome_tie_break_candidate_count']);
        self::assertSame('pattern_candidate', $summary['items'][0]['learning_stage']);
        self::assertTrue($summary['items'][0]['outcome_tie_break_eligible']);
        self::assertSame(80, $summary['items'][0]['scope']['system_hotel_id']);
        self::assertSame('meituan', $summary['items'][0]['scope']['platform']);
        self::assertSame('advertising_roas', $summary['items'][0]['scope']['metric_key']);
        self::assertSame(0, $summary['indeterminate_review_count']);
        self::assertSame(
            'after_exact_four_dimension_tie_before_stable_candidate_key',
            $summary['outcome_tie_break_policy']['position']
        );
        self::assertFalse($summary['items'][0]['candidate_sop_eligible']);
        self::assertFalse($summary['automatic_sop_promotion']);

        $contradicted = [
            ...$third,
            'action' => [
                ...$third['action'],
                'action_ref' => 'operation_execution_task#204',
                'evidence_refs' => ['operation_execution_task#204'],
                'expectation_status' => 'contradicted',
            ],
            'followup' => [
                ...$third['followup'],
                'captured_at' => '2026-07-24 08:00:00',
                'evidence_refs' => ['online_daily_data#106'],
            ],
        ];
        $withContradiction = $service->summarizeReviews([$first, $second, $third, $contradicted]);
        self::assertSame('contradictory_evidence', $withContradiction['status']);
        self::assertSame('contradictory_evidence', $withContradiction['items'][0]['status']);
        self::assertSame('action_reviewed', $withContradiction['items'][0]['learning_stage']);
        self::assertFalse($withContradiction['items'][0]['outcome_tie_break_eligible']);

        $indeterminate = [
            ...$third,
            'action' => [
                ...$third['action'],
                'action_ref' => 'operation_execution_task#207',
                'evidence_refs' => ['operation_execution_task#207'],
                'expectation_status' => 'indeterminate',
            ],
            'followup' => [
                ...$third['followup'],
                'captured_at' => '2026-07-26 08:00:00',
                'evidence_refs' => ['online_daily_data#108'],
            ],
        ];
        $withIndeterminate = $service->summarizeReviews([$first, $second, $third, $indeterminate]);
        self::assertSame('accumulating', $withIndeterminate['status']);
        self::assertSame(0, $withIndeterminate['pattern_candidate_count']);
        self::assertSame(1, $withIndeterminate['indeterminate_review_count']);
        self::assertSame(1, $withIndeterminate['items'][0]['not_declared_count']);
        self::assertFalse($withIndeterminate['items'][0]['outcome_tie_break_eligible']);

        $differentAction = [
            ...$third,
            'action' => [
                ...$third['action'],
                'action_ref' => 'operation_execution_task#205',
                'action_type' => 'keyword_copy_review',
                'evidence_refs' => ['operation_execution_task#205'],
            ],
            'followup' => [
                ...$third['followup'],
                'captured_at' => '2026-07-25 08:00:00',
                'evidence_refs' => ['online_daily_data#107'],
            ],
        ];
        $splitActions = $service->summarizeReviews([$first, $second, $differentAction]);
        self::assertSame(2, count($splitActions['items']));
        self::assertSame(0, $splitActions['pattern_candidate_count']);
    }

    public function testLegacyPartialReviewsRemainVisibleButCannotDriveOutcomeTieBreak(): void
    {
        $comparisonKey = 'longitudinal:' . str_repeat('a', 64);
        $reviews = [];
        foreach ([1, 2, 3] as $index) {
            $reviews[] = [
                'status' => 'verified',
                'learning_stage' => 'action_reviewed',
                'comparison_key' => $comparisonKey,
                'causality_claimed' => false,
                'action' => [
                    'action_ref' => 'legacy_task#' . $index,
                    'action_type' => 'advertising_budget_review',
                    'expected_direction' => 'increase',
                    'expectation_status' => 'aligned',
                ],
                'followup' => [
                    'period_start' => '2026-07-' . (string)(20 + $index),
                    'period_end' => '2026-07-' . (string)(20 + $index),
                    'captured_at' => '2026-07-' . (string)(20 + $index) . ' 08:00:00',
                    'evidence_refs' => ['online_daily_data#legacy-' . $index],
                ],
                'delta' => ['movement' => 'increase'],
            ];
        }

        $summary = (new LongitudinalEvidenceLearningService())->summarizeReviews($reviews);

        self::assertSame('pattern_candidate', $summary['status']);
        self::assertSame(1, $summary['pattern_candidate_count']);
        self::assertSame(0, $summary['outcome_tie_break_candidate_count']);
        self::assertFalse($summary['items'][0]['strict_scope_verified']);
        self::assertSame([], $summary['items'][0]['scope']);
        self::assertFalse($summary['items'][0]['outcome_tie_break_eligible']);
        self::assertFalse($summary['automatic_sop_promotion']);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function snapshot(array $overrides): array
    {
        return [
            'system_hotel_id' => 80,
            'platform' => 'meituan',
            'platform_hotel_id' => 'poi-80',
            'business_module' => 'traffic',
            'subject' => 'hotel',
            'metric_key' => 'visitor_count',
            'unit' => 'people',
            'source_method' => 'profile_capture',
            'date_role' => 'business_date',
            'fact_scope' => 'ota_channel',
            'period_start' => '2026-07-30',
            'period_end' => '2026-07-30',
            'target_stay_date' => '',
            'captured_at' => '2026-07-30 08:00:00',
            'quality_status' => 'verified',
            'readback_status' => 'readback_verified',
            'value' => 1,
            'evidence_refs' => ['online_daily_data#1'],
            ...$overrides,
        ];
    }
}
