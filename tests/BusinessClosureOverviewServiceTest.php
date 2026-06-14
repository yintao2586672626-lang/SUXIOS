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
                'key' => 'opening',
                'label' => '开业管理',
                'record_count' => 2,
                'linked_execution_count' => 0,
            ],
            [
                'key' => 'feasibility_report',
                'label' => 'AI可行性报告',
                'record_count' => 1,
                'linked_execution_count' => 0,
                'data_gaps' => [
                    ['code' => 'feasibility_readiness_source_evidence', 'message' => 'source evidence missing'],
                ],
            ],
        ], ['total' => 1, 'roi_ready' => 1]);

        self::assertSame(3, $overview['summary']['module_count']);
        self::assertSame(1, $overview['summary']['closed_loop_count']);
        self::assertSame(2, $overview['summary']['not_closed_count']);
        self::assertSame('not_closed', $overview['summary']['status']);
        self::assertSame('opening', $overview['weak_modules'][0]['key']);
        self::assertSame('feasibility_report', $overview['weak_modules'][1]['key']);
    }

    public function testOverviewIncludesStaffAndAssetClosureModulesInOrder(): void
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
                'key' => 'asset_maintenance',
                'label' => '资产运维 / 能耗维护',
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
        ]);

        self::assertSame([
            'revenue_pricing',
            'staff_service',
            'asset_maintenance',
            'operation_execution',
        ], array_column($overview['modules'], 'key'));
        self::assertSame('已闭环', $overview['modules'][1]['status_label']);
        self::assertSame(3, $overview['summary']['closed_loop_count']);
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
            'staff_service',
            'asset_maintenance',
            'operation_execution',
            'transfer_investment',
            'expansion',
            'opening',
            'strategy_simulation',
            'feasibility_report',
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
