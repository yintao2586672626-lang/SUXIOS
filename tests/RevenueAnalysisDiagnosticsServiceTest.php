<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenueAnalysisDiagnosticsService;
use PHPUnit\Framework\TestCase;

final class RevenueAnalysisDiagnosticsServiceTest extends TestCase
{
    public function testVerifiedThreeSourceFactsProduceShareableDiagnostics(): void
    {
        $diagnostics = (new RevenueAnalysisDiagnosticsService())->build(
            $this->factLayer()
        );

        self::assertSame(
            RevenueAnalysisDiagnosticsService::CONTRACT_VERSION,
            $diagnostics['contract_version']
        );
        self::assertSame('ready_to_share', $diagnostics['overall_assessment']);
        self::assertSame('high', $diagnostics['confidence']);
        self::assertTrue(
            $diagnostics['decision_use']['revenue_analysis']['allowed']
        );
        self::assertTrue(
            $diagnostics['decision_use']['ai_manual_review']['allowed']
        );
        self::assertFalse(
            $diagnostics['decision_use']['whole_hotel_generalization']['allowed']
        );
        self::assertSame([], $diagnostics['issues']);
        self::assertSame(
            3,
            $diagnostics['evidence_summary']['readback_verified_source_count']
        );
        self::assertSame(
            ['passed', 'passed', 'passed'],
            array_column($diagnostics['source_checks'], 'status')
        );
        self::assertSame(
            0.0,
            $diagnostics['metric_diagnostics'][0]['value']
        );
    }

    public function testMissingOtaSourceBlocksConclusionAndKeepsMetricNull(): void
    {
        $layer = $this->factLayer();
        $layer['status'] = 'partial';
        $layer['revenue_analysis_status'] = 'partial';
        $layer['ai_review_status'] = 'blocked_by_required_inputs';
        $layer['all_three_sources_readback_verified'] = false;
        $layer['source_completeness']['meituan_ota'] = 'missing';
        $layer['analysis_metrics']['ota_room_revenue'] = [
            'key' => 'ota_room_revenue',
            'label' => '目标日 OTA 房费收入',
            'value' => null,
            'unit' => 'CNY',
            'status' => 'not_calculable',
            'reason' => 'three_source_ota_facts_partial',
            'scope' => 'ota_channel',
            'date_basis' => 'data_date',
            'source_channels' => ['ctrip', 'meituan'],
            'truth' => ['status' => 'unverified'],
        ];
        $gap = [
            'code' => 'meituan_ota_not_readback_verified',
            'source' => 'meituan_ota',
            'status' => 'missing',
            'category' => 'source_identity_or_readback',
            'display_reason' => '美团目标日事实尚未完成精确回读。',
            'next_action' => '完成美团目标日采集、保存和精确回读。',
        ];
        $layer['analysis_gaps'] = [$gap];
        $layer['ai_review_gaps'] = [$gap];

        $diagnostics = (new RevenueAnalysisDiagnosticsService())->build($layer);

        self::assertSame('needs_revision', $diagnostics['overall_assessment']);
        self::assertFalse(
            $diagnostics['decision_use']['revenue_analysis']['allowed']
        );
        self::assertSame(
            'blocks_revenue_analysis',
            $diagnostics['issues'][0]['decision_impact']
        );
        self::assertSame('high', $diagnostics['issues'][0]['severity']);
        self::assertSame(
            '美团目标日事实尚未完成精确回读。',
            $diagnostics['issues'][0]['message']
        );
        self::assertSame(
            '完成美团目标日采集、保存和精确回读。',
            $diagnostics['next_action']
        );
        self::assertNull($diagnostics['metric_diagnostics'][0]['value']);
        self::assertSame(
            'not_calculable',
            $diagnostics['metric_diagnostics'][0]['status']
        );
    }

    public function testFloorPriceGapIsCaveatNotFactLayerFailure(): void
    {
        $layer = $this->factLayer();
        $layer['ai_review_status'] = 'blocked_by_required_inputs';
        $layer['ai_review_gaps'] = [[
            'code' => 'floor_price_missing',
            'source' => 'pricing_guard',
            'status' => 'missing',
            'category' => 'room_type_floor_price',
        ]];

        $diagnostics = (new RevenueAnalysisDiagnosticsService())->build($layer);

        self::assertSame('share_with_caveats', $diagnostics['overall_assessment']);
        self::assertTrue(
            $diagnostics['decision_use']['revenue_analysis']['allowed']
        );
        self::assertFalse(
            $diagnostics['decision_use']['ai_manual_review']['allowed']
        );
        self::assertSame('medium', $diagnostics['issues'][0]['severity']);
        self::assertSame(
            'blocks_ai_manual_review',
            $diagnostics['issues'][0]['decision_impact']
        );
    }

    public function testSelectedMeituanPmsProducesProviderAwareSourceDiagnostics(): void
    {
        $layer = $this->factLayer();
        $layer['pms_binding'] = [
            'binding_status' => 'configured',
            'effective_provider' => 'meituan_cloud_pms',
        ];
        unset($layer['source_completeness']['dingdandao_pms']);
        $layer['source_completeness']['meituan_cloud_pms'] =
            'readback_verified';
        $layer['date_alignment']['status'] = 'aligned';
        $layer['analysis_metrics']['whole_hotel_revpar']['source_channels'] = [
            'meituan_cloud_pms',
        ];

        $diagnostics = (new RevenueAnalysisDiagnosticsService())->build($layer);

        self::assertSame('ready_to_share', $diagnostics['overall_assessment']);
        self::assertSame('meituan_cloud_pms', $diagnostics['source_checks'][0]['source']);
        self::assertSame('美团云 PMS 全酒店住宿事实', $diagnostics['source_checks'][0]['label']);
        self::assertSame('passed', $diagnostics['source_checks'][0]['status']);
        self::assertNotContains(
            'dingdandao_pms',
            array_column($diagnostics['source_checks'], 'source')
        );
        self::assertSame(
            'passed',
            array_column($diagnostics['checks'], null, 'key')
                ['target_date_alignment']['status']
        );
    }

    /** @return array<string,mixed> */
    private function factLayer(): array
    {
        return [
            'status' => 'ready',
            'revenue_analysis_status' => 'ready',
            'ai_review_status' => 'ready_for_manual_review',
            'hotel' => [
                'tenant_id' => 80,
                'system_hotel_id' => 80,
                'name' => '测试酒店',
            ],
            'business_date' => '2026-07-30',
            'date_alignment' => [
                'status' => 'same_date_key_distinct_source_semantics',
            ],
            'source_completeness' => [
                'dingdandao_pms' => 'readback_verified',
                'ctrip_ota' => 'readback_verified',
                'meituan_ota' => 'readback_verified',
            ],
            'all_three_sources_readback_verified' => true,
            'analysis_metrics' => [
                'ota_room_revenue' => [
                    'key' => 'ota_room_revenue',
                    'label' => '目标日 OTA 房费收入',
                    'value' => 0.0,
                    'unit' => 'CNY',
                    'status' => 'ok',
                    'reason' => '',
                    'scope' => 'ota_channel',
                    'date_basis' => 'data_date',
                    'source_channels' => ['ctrip', 'meituan'],
                    'truth' => ['status' => 'verified'],
                ],
                'whole_hotel_revpar' => [
                    'key' => 'whole_hotel_revpar',
                    'label' => '全酒店住宿 RevPAR',
                    'value' => 528.67,
                    'unit' => 'CNY',
                    'status' => 'ok',
                    'reason' => '',
                    'scope' => 'whole_hotel_accommodation',
                    'date_basis' => 'pms_business_date',
                    'source_channels' => ['dingdandao_pms'],
                    'truth' => ['status' => 'verified'],
                ],
            ],
            'analysis_gaps' => [],
            'ai_review_gaps' => [],
            'aggregation_policy' => [
                'pms_plus_ota_revenue_addition_allowed' => false,
                'missing_source_value' => null,
                'ota_data_may_represent_whole_hotel_revenue' => false,
            ],
        ];
    }
}
