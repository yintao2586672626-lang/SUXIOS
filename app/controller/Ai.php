<?php
namespace app\controller;

use app\BaseController;
use app\service\FeasibilityReportService;
use think\Request;

class Ai extends BaseController
{
    /**
     * 智略·战略推演
     */
    public function strategy(Request $request)
    {
        $data = $request->post();
        
        $city = $data['city'] ?? '上海市 陆家嘴';
        $area = $data['area'] ?? 5000;
        $audience = $data['audience'] ?? '高端商务';
        $depth = $data['depth'] ?? '标准推演';

        // 模拟调用大模型或算法的结果
        // 在实际业务中，可以对接真正的AI服务 API
        $result = [
            'title' => "{$city}{$area}㎡物业{$audience}定位可行性分析",
            'positioning' => "建议打造\"都市绿洲\"主题中高端商务酒店。该区域传统重奢商务酒店竞争饱和，但针对特定客群的\"轻奢+社交+智能\"复合型空间供给不足。",
            'swot' => [
                'strengths' => '面积适中，单房改造成本可控；物业展示面佳，昭示性强。',
                'weaknesses' => '距离核心地铁站需步行800米，对行李较多客群有一定抗性。',
                'opportunities' => '周边500米内即将落成两座甲级写字楼，预计新增办公人群1.5万。',
                'threats' => '同街区一家老旧四星酒店有翻牌升级传闻。'
            ],
            'differentiation' => '舍弃大型餐饮和宴会厅，将一楼改造为"日咖夜酒"的开放式社交大堂；引入全套智能客控系统弥补服务人力限制；客房面积控制在30-35㎡，放大卫浴空间和睡眠体验投入。'
        ];

        return json(['code' => 200, 'message' => '推演成功', 'data' => $result]);
    }

    /**
     * 智算·量化模拟
     */
    public function simulation(Request $request)
    {
        $data = $request->post();
        
        $rooms = $data['rooms'] ?? 120;
        $adr = $data['adr'] ?? 450;
        $occ = $data['occ'] ?? 82;
        $nonRoomRevenueRatio = $data['nonRoomRevenueRatio'] ?? 15;

        // 根据前端传参模拟测算逻辑
        $annualRoomRevenue = $rooms * 365 * $adr * ($occ / 100);
        $totalRevenue = $annualRoomRevenue / (1 - ($nonRoomRevenueRatio / 100));
        
        $totalInvestment = 15000000; // 模拟固定值
        $netProfitMargin = 31.2; // 模拟固定值
        $netProfit = $totalRevenue * ($netProfitMargin / 100);
        
        $paybackPeriod = $netProfit > 0 ? round($totalInvestment / $netProfit, 1) : 0;
        $irr = 24.5; // 模拟 IRR

        $cashFlows = [
            'y1' => -40, // 假设单位为百万或相对值
            'y2' => 60,
            'y3' => 90,
            'y4' => 120,
            'y5' => 140
        ];

        return json([
            'code' => 200, 
            'message' => '测算成功', 
            'data' => [
                'totalInvestment' => round($totalInvestment / 10000, 0), // 万
                'paybackPeriod' => $paybackPeriod,
                'irr' => $irr,
                'netProfitMargin' => $netProfitMargin,
                'annualRevenue' => round($totalRevenue / 10000, 0), // 万
                'annualNetProfit' => round($netProfit / 10000, 0), // 万
                'cashFlows' => $cashFlows
            ]
        ]);
    }

    /**
     * 可行性报告
     */
    public function feasibility(Request $request)
    {
        $input = $request->post();
        $input = array_merge([
            'project_name' => $input['projectName'] ?? 'Untitled feasibility project',
            'city' => $input['city'] ?? '',
            'district' => $input['district'] ?? '',
            'address' => $input['address'] ?? '',
            'property_area' => $input['area'] ?? 5000,
            'room_count' => $input['rooms'] ?? 120,
            'monthly_rent' => $input['monthly_rent'] ?? 0,
            'lease_years' => $input['lease_years'] ?? 10,
            'decoration_budget' => $input['decoration_budget'] ?? 0,
            'transfer_fee' => $input['transfer_fee'] ?? 0,
            'opening_cost' => $input['opening_cost'] ?? 0,
            'adr' => $input['adr'] ?? 0,
            'occ' => $input['occ'] ?? 0,
        ], $input);

        try {
            $userId = (int)($request->user->id ?? 0);
            $data = (new FeasibilityReportService())->generate($input, $userId);
            return json(['code' => 200, 'message' => 'success', 'data' => $data]);
        } catch (\Throwable $e) {
            return json(['code' => 400, 'message' => $e->getMessage(), 'data' => null], 400);
        }
    }
}