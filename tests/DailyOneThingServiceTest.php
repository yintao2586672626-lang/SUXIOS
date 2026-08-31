<?php
declare(strict_types=1);

namespace Tests;

use app\service\DailyOneThingService;
use app\service\LongitudinalEvidenceLearningService;
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
        self::assertSame('not_calculable', $result['selected']['impact_estimate']['status']);
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

    public function testOutcomeLearningBreaksOnlyAnExactFourDimensionTieBeforeStableKey(): void
    {
        $reviews = $this->reviewedObservations();
        $stable = $this->candidate('signal:a:item', 'strict_fact_signal', [80, 80, 80, 20], 'ctrip');
        $learned = $this->candidate('signal:z:item', 'strict_fact_signal', [80, 80, 80, 20], 'ctrip');
        $learned['outcome_learning_binding'] = [
            'comparison_key' => $reviews[0]['comparison_key'],
            'action_type' => 'human_reviewed_operating_check',
            'expected_direction' => 'increase',
        ];

        $result = (new DailyOneThingService())->select(
            [$stable, $learned],
            '2026-08-26',
            $reviews
        );

        self::assertSame('signal:z:item', $result['selected']['candidate_key']);
        self::assertSame(2, $result['selection_policy']['base_tie_group_size']);
        self::assertTrue($result['selection_policy']['outcome_learning_applied']);
        self::assertSame(
            'after_exact_base_rank_tie_before_candidate_key',
            $result['selection_policy']['outcome_learning_position']
        );
        self::assertSame('pattern_candidate_applied', $result['selected']['outcome_learning']['status']);
        self::assertSame(3, $result['selected']['outcome_learning']['sample_count']);
        self::assertSame(
            'highest_base_rank_outcome_learning_tie_break',
            $result['selected']['recommendation_explanation']['why_recommended']['reason_code']
        );
        self::assertFalse($result['selected']['outcome_learning']['causality_claimed']);
        self::assertFalse($result['selected']['outcome_learning']['automatic_sop_promotion']);
        self::assertTrue($result['requires_human_approval']);
        self::assertFalse($result['can_execute']);

        $higherBase = $this->candidate('signal:y:item', 'strict_fact_signal', [81, 80, 80, 20], 'ctrip');
        $baseWins = (new DailyOneThingService())->select(
            [$learned, $higherBase],
            '2026-08-26',
            $reviews
        );
        self::assertSame('signal:y:item', $baseWins['selected']['candidate_key']);
        self::assertFalse($baseWins['selection_policy']['outcome_learning_applied']);
    }

    public function testSingleContradictoryOrIndeterminateReviewsNeverAffectTieBreak(): void
    {
        $reviews = $this->reviewedObservations();
        $stable = $this->candidate('signal:a:item', 'strict_fact_signal', [80, 80, 80, 20], 'ctrip');
        $learned = $this->candidate('signal:z:item', 'strict_fact_signal', [80, 80, 80, 20], 'ctrip');
        $learned['outcome_learning_binding'] = [
            'comparison_key' => $reviews[0]['comparison_key'],
            'action_type' => 'human_reviewed_operating_check',
            'expected_direction' => 'increase',
        ];

        $single = (new DailyOneThingService())->select(
            [$stable, $learned],
            '2026-08-26',
            [$reviews[0]]
        );
        self::assertSame('signal:a:item', $single['selected']['candidate_key']);
        self::assertFalse($single['selection_policy']['outcome_learning_applied']);
        self::assertSame(0, $single['outcome_learning_summary']['pattern_candidate_count']);

        $contradicted = $reviews[2];
        $contradicted['action']['action_ref'] = 'operation_execution_task#304';
        $contradicted['action']['evidence_refs'] = ['operation_execution_task#304'];
        $contradicted['action']['expectation_status'] = 'contradicted';
        $contradicted['followup']['captured_at'] = '2026-08-14 08:00:00';
        $contradicted['followup']['evidence_refs'] = ['online_daily_data#304'];
        $withCounterexample = (new DailyOneThingService())->select(
            [$stable, $learned],
            '2026-08-26',
            [...$reviews, $contradicted]
        );
        self::assertSame('signal:a:item', $withCounterexample['selected']['candidate_key']);
        self::assertSame(1, $withCounterexample['outcome_learning_summary']['contradictory_pattern_count']);

        $indeterminate = $contradicted;
        $indeterminate['action']['action_ref'] = 'operation_execution_task#305';
        $indeterminate['action']['evidence_refs'] = ['operation_execution_task#305'];
        $indeterminate['action']['expectation_status'] = 'indeterminate';
        $indeterminate['followup']['captured_at'] = '2026-08-15 08:00:00';
        $indeterminate['followup']['evidence_refs'] = ['online_daily_data#305'];
        $withIndeterminate = (new DailyOneThingService())->select(
            [$stable, $learned],
            '2026-08-26',
            [...$reviews, $indeterminate]
        );
        self::assertSame('signal:a:item', $withIndeterminate['selected']['candidate_key']);
        self::assertSame(1, $withIndeterminate['outcome_learning_summary']['indeterminate_review_count']);
        self::assertFalse($withIndeterminate['selection_policy']['outcome_learning_applied']);
    }

    public function testImpactProjectionDoesNotChangeRankingAndMismatchedScopeFallsBack(): void
    {
        $withImpact = $this->candidate(
            'gap:meituan:traffic_only_scope',
            'explicit_data_gap',
            [80, 80, 80, 20],
            'meituan'
        );
        $withImpact['impact_estimate'] = [
            'low' => 80,
            'high' => 80,
            'unit' => 'users',
            'formula' => 'exposure_users - detail_visitors',
            'input_refs' => ['online_daily_data#101'],
            'scope' => [
                'tenant_id' => 80,
                'hotel_id' => 80,
                'platform' => 'meituan',
                'business_date' => '2026-08-26',
                'metric_scope' => 'ota_channel',
            ],
            'status' => 'deterministic_point_estimate',
        ];
        $higherRank = $this->candidate(
            'gap:ctrip:core_facts',
            'explicit_data_gap',
            [81, 80, 80, 20],
            'ctrip'
        );
        $ranked = (new DailyOneThingService())->select([$withImpact, $higherRank], '2026-08-26');
        self::assertSame('gap:ctrip:core_facts', $ranked['selected']['candidate_key']);

        $accepted = (new DailyOneThingService())->select([$withImpact], '2026-08-26');
        self::assertSame('deterministic_point_estimate', $accepted['selected']['impact_estimate']['status']);
        self::assertSame(80.0, $accepted['selected']['impact_estimate']['low']);
        self::assertSame(80.0, $accepted['selected']['impact_estimate']['high']);

        $withImpact['impact_estimate']['scope']['hotel_id'] = 81;
        $blocked = (new DailyOneThingService())->select([$withImpact], '2026-08-26');
        self::assertSame('gap:meituan:traffic_only_scope', $blocked['selected']['candidate_key']);
        self::assertSame('not_calculable', $blocked['selected']['impact_estimate']['status']);
        self::assertNull($blocked['selected']['impact_estimate']['low']);
        self::assertNull($blocked['selected']['impact_estimate']['high']);
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

    /** @return list<array<string,mixed>> */
    private function reviewedObservations(): array
    {
        $learning = new LongitudinalEvidenceLearningService();
        $baseline = [
            'system_hotel_id' => 80,
            'platform' => 'ctrip',
            'platform_hotel_id' => 'ctrip-80',
            'business_module' => 'daily_one_thing',
            'subject' => 'ota_fact_scope',
            'metric_key' => 'detail_exposure',
            'unit' => 'exposure_count',
            'source_method' => 'trusted_fact_readback',
            'date_role' => 'business_date',
            'fact_scope' => 'ota_channel',
            'period_start' => '2026-08-10',
            'period_end' => '2026-08-10',
            'target_stay_date' => '',
            'captured_at' => '2026-08-10 08:00:00',
            'quality_status' => 'verified',
            'readback_status' => 'readback_verified',
            'value' => 10,
            'evidence_refs' => ['online_daily_data#300'],
        ];
        $followup = [
            ...$baseline,
            'period_start' => '2026-08-11',
            'period_end' => '2026-08-11',
            'captured_at' => '2026-08-11 08:00:00',
            'value' => 12,
            'evidence_refs' => ['online_daily_data#301'],
        ];
        $first = $learning->reviewAction($baseline, $followup, [
            'action_ref' => 'operation_execution_task#301',
            'action_type' => 'human_reviewed_operating_check',
            'execution_status' => 'executed',
            'executed_at' => '2026-08-10 10:00:00',
            'evidence_refs' => ['operation_execution_task#301'],
            'expected_direction' => 'increase',
        ]);
        $second = [
            ...$first,
            'action' => [
                ...$first['action'],
                'action_ref' => 'operation_execution_task#302',
                'evidence_refs' => ['operation_execution_task#302'],
            ],
            'followup' => [
                ...$first['followup'],
                'captured_at' => '2026-08-12 08:00:00',
                'evidence_refs' => ['online_daily_data#302'],
            ],
        ];
        $third = [
            ...$second,
            'action' => [
                ...$second['action'],
                'action_ref' => 'operation_execution_task#303',
                'evidence_refs' => ['operation_execution_task#303'],
            ],
            'followup' => [
                ...$second['followup'],
                'captured_at' => '2026-08-13 08:00:00',
                'evidence_refs' => ['online_daily_data#303'],
            ],
        ];
        return [$first, $second, $third];
    }
}
