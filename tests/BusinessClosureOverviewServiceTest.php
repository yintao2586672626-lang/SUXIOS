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
}
