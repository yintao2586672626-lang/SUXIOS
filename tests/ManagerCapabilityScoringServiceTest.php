<?php
declare(strict_types=1);

namespace Tests;

use app\service\ManagerCapabilityScoringService;
use PHPUnit\Framework\TestCase;

final class ManagerCapabilityScoringServiceTest extends TestCase
{
    private const MIGRATION = __DIR__ . '/../database/migrations/20260822_z_create_manager_capability_scoring.sql';
    private const FOLLOWUP_MIGRATION = __DIR__ . '/../database/migrations/20260822_zz_create_manager_capability_followups.sql';
    private const OPTIMIZATION_MIGRATION = __DIR__ . '/../database/migrations/20260822_zzz_optimize_manager_capability.sql';
    private const EVENT_TIMESTAMP_MIGRATION = __DIR__ . '/../database/migrations/20260822_zzzz_refine_manager_capability_event_timestamps.sql';

    public function testGoldenClosedCaseProducesExplainableSixDimensionScore(): void
    {
        $result = (new ManagerCapabilityScoringService())->scoreCase([
            'problem_facts' => '今天上午发现两笔前台交接记录缺少复核签字，经核查是交接流程未明确主管复核责任。',
            'action_taken' => '店长安排前台主管现场演示签字标准，按清单逐项补齐2笔记录，并指定主管每班抽查。',
            'verification_status' => 'observed_result',
            'verification_text' => '次日抽查3笔记录，全部签字完整且完成时间符合要求，员工可以独立完成。',
        ]);

        self::assertSame(ManagerCapabilityScoringService::FORMULA_VERSION, $result['formula_version']);
        self::assertSame('scored', $result['status']);
        self::assertSame(6, $result['scored_dimension_count']);
        self::assertSame(90.0, $result['case_score']);
        self::assertCount(6, $result['dimensions']);

        foreach ($result['dimensions'] as $dimension) {
            self::assertSame(90, $dimension['score'], $dimension['key']);
            self::assertSame('scored', $dimension['status']);
            self::assertNotEmpty($dimension['evidence_refs']);
            self::assertNotEmpty($dimension['reasons']);
            self::assertCount(3, $dimension['rubric']);
            self::assertSame('manual_declared', $dimension['source_quality_status']);
        }
    }

    public function testPlannedVerificationAndMissingCoachingStayUnknownInsteadOfZero(): void
    {
        $result = (new ManagerCapabilityScoringService())->scoreCase([
            'problem_facts' => '今天前台发现一笔交接记录没有主管签字。',
            'action_taken' => '已安排负责人当天按清单补齐，并在每班结束时检查。',
            'verification_status' => 'planned_verification',
            'verification_text' => '计划次日抽查三笔记录并核对完成时间。',
        ]);
        $dimensions = array_column($result['dimensions'], null, 'key');

        self::assertSame('pending_verification', $result['status']);
        self::assertNull($result['case_score']);
        self::assertNull($dimensions['coaching']['score']);
        self::assertSame('not_observed', $dimensions['coaching']['status']);
        self::assertNull($dimensions['closure']['score']);
        self::assertSame('not_observed', $dimensions['closure']['status']);
        self::assertStringContainsString('不计0分', $dimensions['coaching']['reasons'][0]);
    }

    public function testProfileRequiresThreeSamplesPerDimensionAndExcludesUnknowns(): void
    {
        $service = new ManagerCapabilityScoringService();
        $golden = $service->scoreCase([
            'problem_facts' => '今天上午发现两笔前台交接记录缺少复核签字，经核查是流程未明确主管责任。',
            'action_taken' => '店长安排主管现场演示标准，按清单补齐2笔记录，并指定主管每班抽查。',
            'verification_status' => 'observed_result',
            'verification_text' => '次日抽查3笔记录，全部签字完整，员工可以独立完成。',
        ]);

        $insufficient = $service->aggregateDimensionScores([
            ['case_id' => 1, 'dimensions' => $golden['dimensions']],
            ['case_id' => 2, 'dimensions' => $golden['dimensions']],
        ]);
        self::assertSame('data_insufficient', $insufficient['status']);
        self::assertNull($insufficient['overall_score']);
        self::assertCount(6, $insufficient['data_gaps']);
        self::assertSame(2, $insufficient['dimensions'][0]['sample_count']);

        $ready = $service->aggregateDimensionScores([
            ['case_id' => 1, 'dimensions' => $golden['dimensions']],
            ['case_id' => 2, 'dimensions' => $golden['dimensions']],
            ['case_id' => 3, 'dimensions' => $golden['dimensions']],
        ]);
        self::assertSame('scored', $ready['status']);
        self::assertSame(90.0, $ready['overall_score']);
        self::assertSame('较强', $ready['label']);
        self::assertSame([], $ready['data_gaps']);
        foreach ($ready['dimensions'] as $dimension) {
            self::assertSame(3, $dimension['sample_count']);
            self::assertSame(90.0, $dimension['score']);
        }
    }

    public function testFollowupOutcomeControlsClosureWithoutOverwritingUnknowns(): void
    {
        $service = new ManagerCapabilityScoringService();
        $base = [
            'problem_facts' => '今天上午发现两笔前台交接记录缺少复核签字，经核查是流程未明确主管责任。',
            'action_taken' => '店长安排主管现场演示标准，按清单补齐2笔记录，并指定主管每班抽查。',
            'verification_text' => '本次抽查2笔记录，核对后已有明确结果。',
            'followup_sample_count' => 2,
        ];

        $stillOpen = $service->scoreCase($base + [
            'verification_status' => 'planned_verification',
            'followup_outcome' => 'still_open',
        ]);
        $stillOpenDimensions = array_column($stillOpen['dimensions'], null, 'key');
        self::assertSame('pending_verification', $stillOpen['status']);
        self::assertNull($stillOpenDimensions['closure']['score']);
        self::assertStringContainsString('继续留空', $stillOpenDimensions['closure']['reasons'][0]);

        $recurred = $service->scoreCase($base + [
            'verification_status' => 'observed_result',
            'followup_outcome' => 'recurred',
        ]);
        $recurredDimensions = array_column($recurred['dimensions'], null, 'key');
        self::assertSame(50, $recurredDimensions['closure']['score']);
        self::assertStringContainsString('再次发生', $recurredDimensions['closure']['reasons'][0]);
    }

    public function testDailySubmissionCountsActiveSameDayCasesWithoutInferringClosure(): void
    {
        $summary = (new ManagerCapabilityScoringService())->summarizeDailySubmission([
            ['id' => 11, 'business_date' => '2026-08-20', 'is_voided' => false],
            ['id' => 12, 'business_date' => '2026-08-20', 'is_voided' => false],
            ['id' => 13, 'business_date' => '2026-08-20', 'is_voided' => true],
            ['id' => 14, 'business_date' => '2026-08-21', 'is_voided' => false],
        ], '2026-08-20');

        self::assertSame('submitted', $summary['status']);
        self::assertSame('当日已提交', $summary['label']);
        self::assertSame(2, $summary['case_count']);
        self::assertSame([11, 12], $summary['case_ids']);
        self::assertSame('2026-08-20', $summary['last_submission_date']);
        self::assertSame(0, $summary['consecutive_missing_days']);
        self::assertSame('none', $summary['attention_status']);
        self::assertFalse($summary['independent_verification']);
        self::assertFalse($summary['closure_inferred']);
        self::assertStringContainsString('已提交不等于已闭环', $summary['closure_note']);
    }

    public function testDailySubmissionFlagsThreeDayGapAndKeepsNoHistoryDurationUnknown(): void
    {
        $service = new ManagerCapabilityScoringService();
        $missing = $service->summarizeDailySubmission([
            ['id' => 21, 'business_date' => '2026-08-21', 'is_voided' => false],
            ['id' => 22, 'business_date' => 'not-a-date', 'is_voided' => false],
        ], '2026-08-25');

        self::assertSame('not_submitted', $missing['status']);
        self::assertSame('2026-08-21', $missing['last_submission_date']);
        self::assertSame(4, $missing['consecutive_missing_days']);
        self::assertSame('three_day_missing', $missing['attention_status']);
        self::assertSame(1, $missing['invalid_business_date_count']);

        $noHistory = $service->summarizeDailySubmission([], '2026-08-25');
        self::assertSame('not_submitted', $noHistory['status']);
        self::assertNull($noHistory['last_submission_date']);
        self::assertNull($noHistory['consecutive_missing_days']);
        self::assertSame('no_history', $noHistory['attention_status']);
        self::assertSame('empty', $noHistory['history_status']);
    }

    public function testMigrationPersistsScopedCasesAndSnapshotsWithoutPersonnelActions(): void
    {
        $sql = (string)file_get_contents(self::MIGRATION);

        foreach ([
            'manager_capability_cases',
            'manager_capability_score_snapshots',
            '`tenant_id`',
            '`hotel_id`',
            '`manager_user_id`',
            '`input_digest`',
            '`evidence_digest`',
            'uniq_manager_capability_case_idempotency',
            'manual_management_three_questions',
            'manual_declared',
            ManagerCapabilityScoringService::SOURCE_FINGERPRINT,
        ] as $marker) {
            self::assertStringContainsString($marker, $sql);
        }

        self::assertStringNotContainsString('operation_execution_intents', $sql);
        self::assertStringNotContainsString('operation_execution_tasks', $sql);
        self::assertStringNotContainsString('ranking', strtolower($sql));
        self::assertStringNotContainsString('penalty', strtolower($sql));
    }

    public function testFollowupMigrationIsAppendOnlyAndKeepsRecurrenceScoped(): void
    {
        $sql = (string)file_get_contents(self::FOLLOWUP_MIGRATION);

        foreach ([
            'manager_capability_case_followups',
            '`case_id`',
            '`tenant_id`',
            '`hotel_id`',
            '`manager_user_id`',
            '`followup_outcome`',
            '`next_followup_date`',
            '`linked_recurrence_case_id`',
            '`parent_case_id`',
            '`origin_followup_id`',
            '`input_digest`',
            '`evidence_digest`',
            'uniq_manager_capability_followup_idempotency',
            'manual_declared',
        ] as $marker) {
            self::assertStringContainsString($marker, $sql);
        }

        self::assertStringNotContainsString('operation_execution_intents', $sql);
        self::assertStringNotContainsString('operation_execution_tasks', $sql);
        self::assertStringNotContainsString('ranking', strtolower($sql));
        self::assertStringNotContainsString('penalty', strtolower($sql));
    }

    public function testOptimizationMigrationAddsStructuredEvidenceAndAppendOnlyHumanEvents(): void
    {
        $sql = (string)file_get_contents(self::OPTIMIZATION_MIGRATION);
        foreach ([
            'manager_capability_case_adjustments',
            'manager_capability_score_reviews',
            '`evidence_type`',
            '`evidence_reference`',
            '`evidence_date`',
            '`evidence_confidence`',
            '`effective_payload_json`',
            '`is_voided`',
            '`source_score_digest`',
            'uniq_manager_capability_adjustment_idempotency',
            'uniq_manager_capability_score_review_idempotency',
        ] as $marker) {
            self::assertStringContainsString($marker, $sql);
        }
        self::assertStringNotContainsString('operation_execution_intents', $sql);
        self::assertStringNotContainsString('operation_execution_tasks', $sql);
        self::assertStringNotContainsString('ranking', strtolower($sql));
        self::assertStringNotContainsString('penalty', strtolower($sql));

        $timestampSql = (string)file_get_contents(self::EVENT_TIMESTAMP_MIGRATION);
        self::assertStringContainsString('manager_capability_case_adjustments', $timestampSql);
        self::assertStringContainsString('manager_capability_score_reviews', $timestampSql);
        self::assertStringContainsString('DATETIME(6)', $timestampSql);
    }
}
