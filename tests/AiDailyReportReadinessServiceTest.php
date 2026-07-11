<?php
declare(strict_types=1);

namespace Tests;

use app\service\AiDailyReportService;
use PHPUnit\Framework\TestCase;

final class AiDailyReportReadinessServiceTest extends TestCase
{
    public function testGeneratedReportWithTransferableActionRequiresExecutionIntent(): void
    {
        $service = new AiDailyReportService();

        $readiness = $service->buildReportReadiness([
            'source_refs' => [['key' => 'operation.full_data']],
            'data_gaps' => [],
            'recommended_actions' => [[
                'title' => 'Review price',
                'can_create_execution_intent' => true,
            ]],
        ]);

        self::assertSame('pending_execution_transfer', $readiness['stage']);
        self::assertFalse($readiness['closed_loop']);
        self::assertSame(1, $readiness['transferable_count']);
        self::assertContains('execution_intent', array_column($readiness['missing_evidence'], 'code'));
    }

    public function testDataGapActionIsNotPresentedAsExecutionReady(): void
    {
        $service = new AiDailyReportService();

        $readiness = $service->buildReportReadiness([
            'source_refs' => [['key' => 'operation.full_data']],
            'data_gaps' => [['code' => 'summary_data_pending', 'message' => 'summary data pending']],
            'recommended_actions' => [[
                'title' => 'Repair data',
                'can_create_execution_intent' => false,
                'blocked_reason' => 'Data repair must happen before execution',
            ]],
        ]);

        self::assertSame('data_recheck_required', $readiness['stage']);
        self::assertFalse($readiness['closed_loop']);
        self::assertSame(0, $readiness['transferable_count']);
        self::assertContains('data_gaps', array_column($readiness['missing_evidence'], 'code'));
    }

    public function testFallbackManualReviewActionsAreInvestigationOnly(): void
    {
        $service = new AiDailyReportService();
        $method = new \ReflectionMethod($service, 'buildRecommendedActions');
        $method->setAccessible(true);

        $actions = $method->invoke($service, [], ['root_causes' => []], ['summary' => []], []);

        self::assertCount(3, $actions);
        foreach ($actions as $action) {
            self::assertSame('manual_review', $action['action_type']);
            self::assertSame('investigation', $action['recommendation_type']);
            self::assertTrue($action['is_investigation_only']);
            self::assertSame('forbidden', $action['execution_policy']);
            self::assertFalse($action['can_create_execution_intent']);
            self::assertSame('Fallback investigation item is evidence review only and cannot create an execution intent.', $action['blocked_reason']);
        }

        $readiness = $service->buildReportReadiness([
            'source_refs' => [['key' => 'operation.full_data']],
            'data_gaps' => [],
            'recommended_actions' => $actions,
        ]);

        self::assertSame(0, $readiness['transferable_count']);
        self::assertSame('investigation_only', $readiness['stage']);
        self::assertSame('仅调查，不可执行', $readiness['status_label']);
        self::assertSame(0, $readiness['blocked_count']);
        self::assertSame(3, $readiness['investigation_count']);
        self::assertSame(0, $readiness['execution_action_count']);
        self::assertSame([], $readiness['missing_evidence']);
        self::assertSame('仅生成调查项，未形成可执行建议。', $readiness['notice']);
    }

    public function testLegacyFallbackManualReviewIsStillExcludedFromExecutionDenominator(): void
    {
        $service = new AiDailyReportService();

        $readiness = $service->buildReportReadiness([
            'source_refs' => [['key' => 'operation.full_data']],
            'data_gaps' => [],
            'recommended_actions' => [[
                'title' => 'Review daily operating signal 1',
                'action_type' => 'manual_review',
                'can_create_execution_intent' => false,
                'blocked_reason' => 'Fallback manual review is investigation-only until stronger evidence is selected.',
            ]],
        ]);

        self::assertSame('investigation_only', $readiness['stage']);
        self::assertSame(1, $readiness['investigation_count']);
        self::assertSame(0, $readiness['blocked_count']);
        self::assertSame(0, $readiness['execution_action_count']);
    }

    public function testInvestigationPaddingDoesNotPreventExecutableActionClosure(): void
    {
        $service = new AiDailyReportService();

        $readiness = $service->buildReportReadiness([
            'source_refs' => [['key' => 'operation.full_data']],
            'data_gaps' => [],
            'recommended_actions' => [[
                'title' => 'Review conversion',
                'execution_intent_id' => 16,
                'can_create_execution_intent' => true,
            ], [
                'title' => 'Investigate daily operating signal 1',
                'action_type' => 'manual_review',
                'recommendation_type' => 'investigation',
                'is_investigation_only' => true,
                'can_create_execution_intent' => false,
            ]],
        ], [[
            'id' => 16,
            'stage' => 'reviewed',
            'approval' => ['status' => 'approved'],
            'execution' => ['status' => 'executed', 'task_id' => 9],
            'evidence' => ['count' => 1],
            'review' => ['status' => 'success'],
            'roi' => ['status' => 'ready', 'value' => 12.5],
        ]]);

        self::assertSame('daily_loop_closed', $readiness['stage']);
        self::assertSame(2, $readiness['action_count']);
        self::assertSame(1, $readiness['execution_action_count']);
        self::assertSame(1, $readiness['investigation_count']);
        self::assertSame(1, $readiness['roi_ready_count']);
    }

    public function testReviewedReportStillRequiresRoiEvidence(): void
    {
        $service = new AiDailyReportService();

        $readiness = $service->buildReportReadiness([
            'source_refs' => [['key' => 'operation.full_data']],
            'data_gaps' => [],
            'recommended_actions' => [[
                'title' => 'Review conversion',
                'execution_intent_id' => 15,
                'can_create_execution_intent' => true,
            ]],
        ], [[
            'id' => 15,
            'stage' => 'reviewed',
            'approval' => ['status' => 'approved'],
            'execution' => ['status' => 'executed', 'task_id' => 8],
            'evidence' => ['count' => 1],
            'review' => ['status' => 'success'],
            'roi' => ['status' => 'data_gap'],
        ]]);

        self::assertSame('reviewed_no_roi', $readiness['stage']);
        self::assertFalse($readiness['closed_loop']);
        self::assertSame(1, $readiness['reviewed_count']);
        self::assertSame(0, $readiness['roi_ready_count']);
        self::assertContains('roi_evidence', array_column($readiness['missing_evidence'], 'code'));
    }

    public function testReportIsClosedOnlyWhenEveryActionHasRoiReadyEvidence(): void
    {
        $service = new AiDailyReportService();

        $readiness = $service->buildReportReadiness([
            'source_refs' => [['key' => 'operation.full_data']],
            'data_gaps' => [],
            'recommended_actions' => [[
                'title' => 'Review conversion',
                'execution_intent_id' => 16,
                'can_create_execution_intent' => true,
            ]],
        ], [[
            'id' => 16,
            'stage' => 'reviewed',
            'approval' => ['status' => 'approved'],
            'execution' => ['status' => 'executed', 'task_id' => 9],
            'evidence' => ['count' => 1],
            'review' => ['status' => 'success'],
            'roi' => ['status' => 'ready', 'value' => 12.5],
        ]]);

        self::assertSame('daily_loop_closed', $readiness['stage']);
        self::assertTrue($readiness['closed_loop']);
        self::assertSame(100, $readiness['score']);
        self::assertSame(1, $readiness['roi_ready_count']);
        self::assertSame([], $readiness['missing_evidence']);
    }

    public function testEnrichReportRowsAddsReportAndActionReadiness(): void
    {
        $service = new AiDailyReportService();

        $rows = $service->enrichReportRows([[
            'id' => 0,
            'hotel_id' => 2,
            'created_by' => 1,
            'yesterday_result_json' => '{"metrics":[{"key":"orders","value":8}]}',
            'abnormal_metrics_json' => '[]',
            'competitor_changes_json' => '[]',
            'data_gaps_json' => '[]',
            'recommended_actions_json' => '[{"title":"Review price","can_create_execution_intent":true}]',
            'source_refs_json' => '[{"key":"operation.full_data"}]',
            'snapshot_json' => '{}',
        ]]);

        self::assertSame('pending_execution_transfer', $rows[0]['report_readiness']['stage']);
        self::assertSame('pending_transfer', $rows[0]['recommended_actions'][0]['action_readiness']['stage']);
        self::assertSame(8, $rows[0]['yesterday_result']['metrics'][0]['value']);
        self::assertSame([], $rows[0]['owner_communication_brief']);
    }

    public function testOwnerCommunicationBriefIsReturnedFromSnapshotOnly(): void
    {
        $service = new AiDailyReportService();

        $rows = $service->enrichReportRows([[
            'id' => 0,
            'hotel_id' => 2,
            'created_by' => 1,
            'yesterday_result_json' => '{"metrics":[{"key":"orders","value":8}]}',
            'abnormal_metrics_json' => '[]',
            'competitor_changes_json' => '[]',
            'data_gaps_json' => '[]',
            'recommended_actions_json' => '[{"title":"Review price","can_create_execution_intent":true}]',
            'source_refs_json' => '[{"key":"operation.full_data"}]',
            'snapshot_json' => '{"owner_communication_brief":{"status":"available","non_execution":true,"source_policy":"daily_report_operating_data_plus_owner_negotiation_playbook_reference"}}',
        ]]);

        self::assertSame('available', $rows[0]['owner_communication_brief']['status']);
        self::assertTrue($rows[0]['owner_communication_brief']['non_execution']);
        self::assertArrayNotHasKey('owner_communication_brief', $rows[0]['recommended_actions'][0]);
    }
}
