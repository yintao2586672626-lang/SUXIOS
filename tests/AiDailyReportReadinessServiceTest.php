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
    }
}
