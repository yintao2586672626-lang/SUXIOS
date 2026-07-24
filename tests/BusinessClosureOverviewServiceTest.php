<?php
declare(strict_types=1);

namespace Tests;

use app\service\BusinessClosureOverviewService;
use PHPUnit\Framework\TestCase;

final class BusinessClosureOverviewServiceTest extends TestCase
{
    public function testRecordOnlyModuleIsNotTreatedAsClosedLoop(): void
    {
        $service = new BusinessClosureOverviewService();

        $module = $service->summarizeModuleClosure([
            'key' => 'transfer_investment',
            'label' => '转让 / 投资测算',
            'source_scope' => 'transfer_records',
            'record_count' => 3,
            'linked_execution_count' => 0,
        ]);

        self::assertSame('record_only', $module['status']);
        self::assertFalse($module['closed_loop']);
        self::assertSame('bridge_to_operation_execution', $module['next_action']);
        self::assertSame('transfer_investment_execution_bridge_missing', $module['data_gaps'][0]['code']);
    }

    public function testAiSummaryFailureBlocksClosureEvenWhenExecutionCountsLookComplete(): void
    {
        $service = new BusinessClosureOverviewService();

        $module = $service->summarizeModuleClosure([
            'key' => 'ai_daily_report',
            'record_count' => 1,
            'linked_execution_count' => 1,
            'approved_count' => 1,
            'executed_count' => 1,
            'evidence_ready_count' => 1,
            'reviewed_count' => 1,
            'roi_ready_count' => 1,
            'ai_summary_failure_count' => 1,
        ]);

        self::assertSame('blocked_by_ai_summary_failure', $module['status']);
        self::assertFalse($module['closed_loop']);
        self::assertFalse($module['process_closed_loop']);
        self::assertFalse($module['roi_ready']);
        self::assertContains('blocked_by_ai_summary_failure', array_column($module['data_gaps'], 'code'));
        self::assertSame('AI汇总失败', $module['status_label']);
        self::assertSame('复核 AI 汇总失败', $module['next_action_label']);
    }

    public function testOverviewCountsAiSummaryFailureAsBlocked(): void
    {
        $overview = (new BusinessClosureOverviewService())->buildOverviewFromSignals([
            ['key' => 'ai_daily_report', 'record_count' => 1, 'linked_execution_count' => 1, 'roi_ready_count' => 1, 'ai_summary_failure_count' => 1],
        ]);

        self::assertSame(1, $overview['summary']['blocked_count']);
    }

    public function testRoiReadyIsTheOnlyClosedLoopStatus(): void
    {
        $service = new BusinessClosureOverviewService();

        $reviewed = $service->summarizeModuleClosure([
            'key' => 'revenue_pricing',
            'label' => '收益调价建议',
            'record_count' => 5,
            'linked_execution_count' => 2,
            'approved_count' => 2,
            'executed_count' => 2,
            'evidence_ready_count' => 2,
            'reviewed_count' => 1,
            'roi_ready_count' => 0,
        ]);
        $closed = $service->summarizeModuleClosure([
            'key' => 'operation_execution',
            'label' => '运营执行闭环',
            'record_count' => 2,
            'linked_execution_count' => 2,
            'approved_count' => 2,
            'executed_count' => 2,
            'evidence_ready_count' => 2,
            'reviewed_count' => 2,
            'roi_ready_count' => 1,
        ]);

        self::assertSame('reviewed_no_roi', $reviewed['status']);
        self::assertFalse($reviewed['closed_loop']);
        self::assertSame('roi_ready', $closed['status']);
        self::assertTrue($closed['closed_loop']);
    }

    public function testOverviewSeparatesProcessClosureFromRoiReadiness(): void
    {
        $service = new BusinessClosureOverviewService();

        $overview = $service->buildOverviewFromSignals([
            [
                'key' => 'revenue_pricing',
                'label' => '收益调价建议',
                'record_count' => 2,
                'linked_execution_count' => 2,
                'approved_count' => 2,
                'executed_count' => 2,
                'evidence_ready_count' => 2,
                'reviewed_count' => 1,
                'roi_ready_count' => 0,
            ],
            [
                'key' => 'operation_execution',
                'label' => '运营执行闭环',
                'record_count' => 1,
                'linked_execution_count' => 1,
                'approved_count' => 1,
                'executed_count' => 1,
                'evidence_ready_count' => 1,
                'reviewed_count' => 1,
                'roi_ready_count' => 1,
            ],
        ]);

        $modules = array_column($overview['modules'], null, 'key');

        self::assertTrue($modules['revenue_pricing']['process_closed_loop']);
        self::assertSame('closed', $modules['revenue_pricing']['process_status']);
        self::assertFalse($modules['revenue_pricing']['roi_ready']);
        self::assertSame('not_ready', $modules['revenue_pricing']['roi_status']);
        self::assertFalse($modules['revenue_pricing']['closed_loop']);
        self::assertTrue($modules['operation_execution']['process_closed_loop']);
        self::assertSame('closed', $modules['operation_execution']['process_status']);
        self::assertTrue($modules['operation_execution']['roi_ready']);
        self::assertSame('ready', $modules['operation_execution']['roi_status']);
        self::assertSame(2, $overview['summary']['process_closed_count']);
        self::assertSame('closed', $overview['summary']['process_status']);
        self::assertSame(1, $overview['summary']['roi_ready_module_count']);
        self::assertSame('not_closed', $overview['summary']['roi_status']);
        self::assertSame(1, $overview['summary']['closed_loop_count']);
    }

    public function testP0DownstreamGateBlocksDownstreamClosureClaims(): void
    {
        $service = new BusinessClosureOverviewService();

        $overview = $service->buildOverviewFromSignals([
            [
                'key' => 'ai_daily_report',
                'label' => 'AI经营日报 / AI决策',
                'record_count' => 2,
                'linked_execution_count' => 2,
                'reviewed_count' => 2,
                'roi_ready_count' => 1,
            ],
            [
                'key' => 'revenue_pricing',
                'label' => '收益调价建议',
                'record_count' => 2,
                'linked_execution_count' => 2,
                'reviewed_count' => 2,
                'roi_ready_count' => 1,
            ],
            [
                'key' => 'operation_execution',
                'label' => '运营执行闭环',
                'record_count' => 2,
                'linked_execution_count' => 2,
                'reviewed_count' => 2,
                'roi_ready_count' => 1,
            ],
        ], ['total' => 2, 'roi_ready' => 1], [], [
            'status' => 'blocked_by_p0_ota_gate',
            'current_upstream_status' => 'incomplete',
            'blocking_missing_inputs' => ['p0_field_loop_verifier_ready'],
        ]);

        $modules = array_column($overview['modules'], null, 'key');
        self::assertSame('blocked_by_p0_ota_gate', $overview['p0_downstream_gate']['status']);
        self::assertSame('blocked_by_p0_ota_gate', $overview['summary']['status']);
        self::assertSame(0, $overview['summary']['closed_loop_count']);
        self::assertSame(3, $overview['summary']['blocked_count']);
        self::assertSame('blocked_by_p0_ota_gate', $modules['revenue_pricing']['status']);
        self::assertSame('blocked_by_p0_ota_gate', $modules['ai_daily_report']['status']);
        self::assertSame('blocked_by_p0_ota_gate', $modules['operation_execution']['status']);
        self::assertFalse($modules['operation_execution']['closed_loop']);
        self::assertContains('p0_ota_gate_not_ready', array_column($modules['operation_execution']['data_gaps'], 'code'));
        self::assertContains('p0_ota_gate_not_ready', array_column($overview['data_gaps'], 'code'));
    }

    public function testOverviewSummaryKeepsWeakModulesVisible(): void
    {
        $service = new BusinessClosureOverviewService();

        $overview = $service->buildOverviewFromSignals([
            [
                'key' => 'operation_execution',
                'label' => '运营执行闭环',
                'record_count' => 1,
                'linked_execution_count' => 1,
                'approved_count' => 1,
                'executed_count' => 1,
                'evidence_ready_count' => 1,
                'reviewed_count' => 1,
                'roi_ready_count' => 1,
            ],
            [
                'key' => 'ai_daily_report',
                'label' => 'AI经营日报 / AI决策',
                'record_count' => 2,
                'linked_execution_count' => 0,
            ],
            [
                'key' => 'revenue_pricing',
                'label' => '收益调价建议',
                'record_count' => 1,
                'linked_execution_count' => 0,
                'data_gaps' => [
                    ['code' => 'price_suggestion_execution_intent_missing', 'message' => 'execution evidence missing'],
                ],
            ],
        ], ['total' => 1, 'roi_ready' => 1]);

        self::assertSame(3, $overview['summary']['module_count']);
        self::assertSame(1, $overview['summary']['closed_loop_count']);
        self::assertSame(2, $overview['summary']['not_closed_count']);
        self::assertSame('not_closed', $overview['summary']['status']);
        self::assertSame('ai_daily_report', $overview['weak_modules'][0]['key']);
        self::assertSame('revenue_pricing', $overview['weak_modules'][1]['key']);
    }

    public function testOverviewSeparatesProcessWeakModulesFromRoiWeakModules(): void
    {
        $service = new BusinessClosureOverviewService();

        $overview = $service->buildOverviewFromSignals([
            [
                'key' => 'revenue_pricing',
                'label' => '收益调价建议',
                'record_count' => 2,
                'linked_execution_count' => 2,
                'approved_count' => 2,
                'executed_count' => 2,
                'evidence_ready_count' => 2,
                'reviewed_count' => 1,
                'roi_ready_count' => 0,
            ],
            [
                'key' => 'ai_daily_report',
                'label' => 'AI经营日报 / AI决策',
                'record_count' => 1,
                'linked_execution_count' => 0,
            ],
            [
                'key' => 'operation_execution',
                'label' => '运营执行闭环',
                'record_count' => 1,
                'linked_execution_count' => 1,
                'reviewed_count' => 1,
                'roi_ready_count' => 1,
            ],
        ]);

        self::assertArrayHasKey('process_weak_modules', $overview);
        self::assertArrayHasKey('roi_weak_modules', $overview);
        self::assertSame(['ai_daily_report'], array_column($overview['process_weak_modules'], 'key'));
        self::assertSame(['ai_daily_report', 'revenue_pricing'], array_column($overview['roi_weak_modules'], 'key'));
        self::assertSame(['ai_daily_report', 'revenue_pricing'], array_column($overview['weak_modules'], 'key'));
    }

    public function testOverviewKeepsOnlyActiveCoreClosureModulesInOrder(): void
    {
        $service = new BusinessClosureOverviewService();

        $overview = $service->buildOverviewFromSignals([
            [
                'key' => 'operation_execution',
                'label' => '运营执行闭环',
                'record_count' => 1,
                'linked_execution_count' => 1,
                'roi_ready_count' => 1,
            ],
            [
                'key' => 'ai_daily_report',
                'label' => 'AI经营日报 / AI决策',
                'record_count' => 3,
                'linked_execution_count' => 2,
                'executed_count' => 1,
                'evidence_ready_count' => 1,
                'reviewed_count' => 1,
                'roi_ready_count' => 1,
            ],
            [
                'key' => 'staff_service',
                'label' => '智能员工 / 工单服务',
                'record_count' => 4,
                'linked_execution_count' => 4,
                'executed_count' => 2,
                'evidence_ready_count' => 2,
                'reviewed_count' => 1,
                'roi_ready_count' => 1,
            ],
            [
                'key' => 'revenue_pricing',
                'label' => '收益调价建议',
                'record_count' => 2,
                'linked_execution_count' => 0,
            ],
            [
                'key' => 'transfer_investment',
                'label' => '转让 / 投资测算',
                'record_count' => 1,
                'linked_execution_count' => 0,
            ],
        ]);

        self::assertSame([
            'ai_daily_report',
            'revenue_pricing',
            'operation_execution',
        ], array_column($overview['modules'], 'key'));
        self::assertSame(2, $overview['summary']['closed_loop_count']);
        self::assertSame('revenue_pricing', $overview['weak_modules'][0]['key']);
    }

    public function testModuleClosureIncludesAiTheoryAndEntryMetadata(): void
    {
        $service = new BusinessClosureOverviewService();

        $module = $service->summarizeModuleClosure([
            'key' => 'ai_daily_report',
            'label' => 'AI经营日报 / AI决策',
            'record_count' => 1,
            'linked_execution_count' => 0,
        ]);

        self::assertSame('运营管理（P0）', $module['module_group']);
        self::assertSame('ai-daily-report', $module['entry_page']);
        self::assertSame('llm_optional', $module['ai_connection']);
        self::assertSame('可接入AI日报', $module['ai_connection_label']);
        self::assertSame('verified_ota_operation_records', $module['data_basis']);
        self::assertSame('OTA/运营记录', $module['data_basis_label']);
        self::assertStringContainsString('LLM不可用', $module['theory_basis']);
        self::assertStringContainsString('转执行单', $module['closure_target']);
        self::assertSame('record_only', $module['status']);
    }

    public function testAllClosureOverviewModulesDeclareCompletionMetadata(): void
    {
        $service = new BusinessClosureOverviewService();
        $keys = [
            'ai_daily_report',
            'revenue_pricing',
            'operation_execution',
        ];

        foreach ($keys as $key) {
            $module = $service->summarizeModuleClosure([
                'key' => $key,
                'label' => $key,
                'record_count' => 0,
                'linked_execution_count' => 0,
            ]);

            self::assertNotSame('', $module['entry_page'], $key . ' entry_page');
            self::assertNotSame('not_declared', $module['ai_connection'], $key . ' ai_connection');
            self::assertNotSame('AI状态未声明', $module['ai_connection_label'], $key . ' ai_connection_label');
            self::assertNotSame('现有记录', $module['data_basis_label'], $key . ' data_basis_label');
            self::assertNotSame('', $module['closure_target'], $key . ' closure_target');
        }
    }
}
