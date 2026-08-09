<?php
declare(strict_types=1);

namespace Tests;

use app\service\CollectionResultContractService;
use app\service\DingdandaoOperatingTargetCaptureService;
use app\service\RevenueFactLayerService;
use PHPUnit\Framework\TestCase;

final class RevenueFactLayerServiceTest extends TestCase
{
    public function testVerifiedThreeSourceFactsMakeRevenueAnalysisReadyWithoutInventingFloorPrice(): void
    {
        $layer = (new RevenueFactLayerService())->assemble(
            $this->hotel(),
            '2026-07-30',
            $this->pmsCapture(),
            $this->otaResult(),
            [],
            $this->otaOperationalMetrics()
        );

        self::assertSame('ready', $layer['revenue_analysis_status']);
        self::assertTrue($layer['all_three_sources_readback_verified']);
        self::assertTrue($layer['all_ota_analysis_gates_allowed']);
        self::assertSame(
            [
                'dingdandao_pms' => 'readback_verified',
                'ctrip_ota' => 'readback_verified',
                'meituan_ota' => 'readback_verified',
            ],
            $layer['source_completeness']
        );
        self::assertSame(
            7930.11,
            $layer['facts']['whole_hotel_accommodation']['room_revenue']
        );
        self::assertSame(
            15,
            $layer['facts']['whole_hotel_accommodation']['sellable_room_nights']
        );
        self::assertNull(
            $layer['facts']['whole_hotel_accommodation']['payment_collected_amount']
        );
        self::assertSame(
            'missing',
            $layer['sources']['dingdandao_pms']['fact_statuses']
                ['payment_collected_amount']['status']
        );
        self::assertSame(
            0.0,
            $layer['facts']['ota_channel']['ctrip']['revenue']
        );
        self::assertSame(
            1032.39,
            $layer['facts']['ota_channel']['meituan']['revenue']
        );
        self::assertSame(
            1032.39,
            $layer['facts']['ota_channel']['combined']['revenue']
        );
        self::assertSame(
            68.83,
            $layer['facts']['cross_source_comparison']
                ['ota_revenue_per_whole_hotel_sellable_room']
        );
        self::assertSame('aligned', $layer['date_alignment']['status']);
        self::assertTrue($layer['date_alignment']['comparison_allowed']);
        self::assertSame(
            6.67,
            $layer['derived_metrics']['ota_room_night_share_percent']['value']
        );
        self::assertSame(
            13.02,
            $layer['derived_metrics']['ota_room_revenue_share_percent']['value']
        );
        self::assertSame(
            12.0,
            $layer['derived_metrics']['ota_cancellation_rate_percent']['value']
        );
        self::assertSame(
            680.0,
            $layer['facts']['ota_channel']['ctrip']['detail_exposure']
        );
        self::assertSame(
            'readback_verified',
            $layer['sources']['ctrip_ota']['fact_statuses']
                ['detail_exposure']['status']
        );
        self::assertSame('partial', $layer['reconciliation']['status']);
        self::assertSame(
            'not_comparable',
            $this->reconciliationCheck($layer, 'payment_caliber')['status']
        );
        self::assertSame(
            'cross_source_comparison',
            $layer['analysis_metrics']['ota_contribution_revpar']['scope']
        );
        self::assertSame(
            'floor_price_missing',
            $layer['unique_remaining_gap']['code']
        );
        self::assertNull(
            $layer['sources']['pricing_guard']['minimum_floor_price']
        );
        self::assertFalse(
            $layer['aggregation_policy']['pms_plus_ota_revenue_addition_allowed']
        );
        self::assertFalse(
            $layer['aggregation_policy']['ota_data_may_represent_whole_hotel_revenue']
        );
        self::assertSame(
            'share_with_caveats',
            $layer['analysis_diagnostics']['overall_assessment']
        );
        self::assertTrue(
            $layer['analysis_diagnostics']['decision_use']['revenue_analysis']['allowed']
        );
        self::assertFalse(
            $layer['analysis_diagnostics']['decision_use']['ai_manual_review']['allowed']
        );
    }

    public function testPmsActualBusinessDateMismatchHardBlocksCrossSourceMetrics(): void
    {
        $layer = (new RevenueFactLayerService())->assemble(
            $this->hotel(),
            '2026-07-30',
            $this->pmsCapture('2026-07-29'),
            $this->otaResult(),
            [],
            $this->otaOperationalMetrics()
        );

        self::assertSame('blocked_date_mismatch', $layer['date_alignment']['status']);
        self::assertFalse($layer['date_alignment']['comparison_allowed']);
        self::assertSame(
            '2026-07-29',
            $layer['date_alignment']['sources']['dingdandao_pms']['observed_date']
        );
        self::assertSame(
            '2026-07-30',
            $layer['date_alignment']['sources']['ctrip_ota']['observed_date']
        );
        self::assertSame(
            '2026-07-30',
            $layer['date_alignment']['sources']['meituan_ota']['observed_date']
        );
        self::assertSame('blocked', $layer['reconciliation']['status']);
        self::assertSame(
            ['business_date_mismatch'],
            $layer['reconciliation']['hard_blockers']
        );
        self::assertContains(
            'business_date_mismatch',
            array_column($layer['analysis_gaps'], 'code')
        );
        self::assertNull(
            $layer['facts']['whole_hotel_accommodation']['room_revenue']
        );
        self::assertSame(
            'readback_verified',
            $layer['sources']['ctrip_ota']['data_status']
        );
        self::assertSame(
            1032.39,
            $layer['facts']['ota_channel']['combined']['revenue']
        );
        self::assertNull(
            $layer['derived_metrics']['ota_room_night_share_percent']['value']
        );
        self::assertNull(
            $layer['derived_metrics']['ota_room_revenue_share_percent']['value']
        );
        self::assertFalse(
            $layer['aggregation_policy']['pms_plus_ota_revenue_addition_allowed']
        );
    }

    public function testUnverifiedAdjacentPmsRowDoesNotCreateFalseDateMismatch(): void
    {
        $layer = (new RevenueFactLayerService())->assemble(
            $this->hotel(),
            '2026-07-30',
            [],
            $this->otaResult(),
            [],
            $this->otaOperationalMetrics(),
            [
                'status' => 'unverified_candidate',
                'business_date' => '2026-07-29',
                'readback_status' => 'not_verified',
                'may_block_date_alignment' => false,
            ]
        );

        self::assertSame('incomplete', $layer['date_alignment']['status']);
        self::assertSame(
            null,
            $layer['date_alignment']['sources']['dingdandao_pms']['observed_date']
        );
        self::assertSame([], $layer['date_alignment']['mismatches']);
        self::assertNotContains(
            'business_date_mismatch',
            array_column($layer['analysis_gaps'], 'code')
        );
    }

    public function testUnavailableHotelScopeReturnsACompleteBlockedContract(): void
    {
        $layer = (new RevenueFactLayerService(
            static fn(int $hotelId): ?array => null
        ))->build(80, '2026-07-30');

        self::assertSame('blocked_scope', $layer['date_alignment']['status']);
        self::assertSame('blocked', $layer['reconciliation']['status']);
        self::assertSame(
            ['system_hotel_scope_unavailable'],
            $layer['reconciliation']['hard_blockers']
        );
        self::assertArrayHasKey('analysis_diagnostics', $layer);
        self::assertSame(
            'system_hotel_scope_unavailable',
            $layer['unique_remaining_gap']['code']
        );
    }

    public function testMissingOtaSourceStaysNullAndKeepsRevenueAnalysisPartial(): void
    {
        $ota = $this->otaResult();
        $ota['rows'] = [$ota['rows'][0]];

        $layer = (new RevenueFactLayerService())->assemble(
            $this->hotel(),
            '2026-07-30',
            $this->pmsCapture(),
            $ota,
            []
        );

        self::assertSame('partial', $layer['revenue_analysis_status']);
        self::assertSame(
            'missing',
            $layer['sources']['meituan_ota']['data_status']
        );
        self::assertNull(
            $layer['facts']['ota_channel']['meituan']['revenue']
        );
        self::assertNull(
            $layer['facts']['ota_channel']['combined']['revenue']
        );
        self::assertNull(
            $layer['facts']['cross_source_comparison']
                ['ota_revenue_per_whole_hotel_sellable_room']
        );
        self::assertContains(
            'meituan_ota_not_readback_verified',
            array_column($layer['analysis_gaps'], 'code')
        );
        self::assertSame(
            ['meituan_ota_not_readback_verified'],
            array_column($layer['ai_review_gaps'], 'code')
        );
        self::assertSame(
            'meituan_ota_not_readback_verified',
            $layer['unique_remaining_gap']['code']
        );
        self::assertSame(
            'needs_revision',
            $layer['analysis_diagnostics']['overall_assessment']
        );
        self::assertNull(
            $layer['analysis_diagnostics']['metric_diagnostics'][0]['value']
        );
    }

    public function testTrustedOperationalMetricsRemainVisibleWhenPlatformRevenueIsMissing(): void
    {
        $operational = $this->otaOperationalMetrics();
        $operational['ctrip']['facts']['room_nights'] = 2.0;
        $operational['ctrip']['facts']['adr'] = 973.0;
        $operational['ctrip']['fact_statuses']['adr'] = array_merge(
            $operational['ctrip']['fact_statuses']['adr'],
            [
            'status' => 'readback_verified',
            'reason' => '',
            'updated_at' => '2026-07-30 23:31:52',
            ]
        );

        $layer = (new RevenueFactLayerService())->assemble(
            $this->hotel(),
            '2026-07-30',
            $this->pmsCapture(),
            [
                'data_status' => 'empty',
                'data_gaps' => ['trusted_ota_revenue_missing'],
                'rows' => [],
            ],
            [],
            $operational
        );

        self::assertSame('partial', $layer['sources']['ctrip_ota']['data_status']);
        self::assertSame(
            '2026-07-30',
            $layer['sources']['ctrip_ota']['actual_business_date']
        );
        self::assertNull($layer['facts']['ota_channel']['ctrip']['revenue']);
        self::assertSame(0, $layer['facts']['ota_channel']['ctrip']['orders']);
        self::assertSame(2, $layer['facts']['ota_channel']['ctrip']['room_nights']);
        self::assertSame(973.0, $layer['facts']['ota_channel']['ctrip']['adr']);
        self::assertSame(
            680.0,
            $layer['facts']['ota_channel']['ctrip']['detail_exposure']
        );
        self::assertSame(
            'readback_verified',
            $layer['sources']['ctrip_ota']['fact_statuses']['orders']['status']
        );
        self::assertSame(
            'partial_readback_verified',
            $layer['sources']['ctrip_ota']['source']['readback_status']
        );
        self::assertSame(
            ['ota_channel_metric_level_display'],
            $layer['sources']['ctrip_ota']['allowed_uses']
        );
        self::assertNull($layer['facts']['ota_channel']['combined']['orders']);
        self::assertNull($layer['derived_metrics']['ota_room_night_share_percent']['value']);
        self::assertSame('incomplete', $layer['date_alignment']['status']);
    }

    public function testTrustedOperationalRevenueStaysPlatformLevelWhenStrictEnvelopeIsMissing(): void
    {
        $operational = $this->otaOperationalMetrics();
        $operational['ctrip']['facts']['revenue'] = 8468.0;
        $operational['ctrip']['fact_statuses']['revenue']['status'] =
            'readback_verified';
        $operational['ctrip']['fact_statuses']['revenue']['reason'] = '';
        $operational['ctrip']['fact_statuses']['revenue']['source_provenance'] = [
            'table' => 'online_daily_data',
            'row_ids' => [701],
            'trace_ids' => ['ctrip:revenue:701'],
            'data_source_ids' => [25],
            'sync_task_ids' => [1001],
            'data_types' => ['business'],
            'source_methods' => ['browser_profile'],
            'stored_count' => 1,
            'readback_verified_count' => 1,
        ];
        $operational['meituan']['data_quality'] = [
            'canonicalized_traffic_projection_groups' => 1,
            'traffic_projection_policy' =>
                'same_run_meituan_funnel_prefers_structured_xhr_over_dom',
        ];

        $layer = (new RevenueFactLayerService())->assemble(
            $this->hotel(),
            '2026-07-30',
            $this->pmsCapture(),
            [
                'data_status' => 'empty',
                'data_gaps' => ['trusted_ota_revenue_missing'],
                'rows' => [],
            ],
            [],
            $operational
        );

        self::assertSame(8468.0, $layer['facts']['ota_channel']['ctrip']['revenue']);
        self::assertSame('partial', $layer['sources']['ctrip_ota']['data_status']);
        self::assertSame(
            ['ota_channel_metric_level_display'],
            $layer['sources']['ctrip_ota']['allowed_uses']
        );
        self::assertSame(
            [701],
            $layer['sources']['ctrip_ota']['source']
                ['operational_metric_provenance']['revenue']['row_ids']
        );
        self::assertSame(
            1,
            $layer['sources']['ctrip_ota']['source']
                ['operational_metric_provenance']['revenue']
                ['readback_verified_count']
        );
        self::assertNull($layer['facts']['ota_channel']['combined']['revenue']);
        self::assertNull(
            $layer['derived_metrics']['ota_room_revenue_share_percent']['value']
        );
        self::assertSame(
            1,
            $this->reconciliationCheck($layer, 'duplicate_orders')
                ['canonicalized_traffic_projection_groups']
        );
    }

    public function testFactReadbackDoesNotClaimRevenueAnalysisWhenCredibilityGateBlocks(): void
    {
        $operational = $this->otaOperationalMetrics();
        $operational['meituan']['analysis_readiness'] = [
            'allowed' => false,
            'status' => 'blocked',
        ];

        $layer = (new RevenueFactLayerService())->assemble(
            $this->hotel(),
            '2026-07-30',
            $this->pmsCapture(),
            $this->otaResult(),
            [],
            $operational
        );

        self::assertSame(
            'readback_verified',
            $layer['sources']['meituan_ota']['data_status']
        );
        self::assertSame(
            1032.39,
            $layer['facts']['ota_channel']['meituan']['revenue']
        );
        self::assertFalse(
            $layer['sources']['meituan_ota']['analysis_readiness']['allowed']
        );
        self::assertSame(
            ['ota_channel_metric_level_display'],
            $layer['sources']['meituan_ota']['allowed_uses']
        );
        self::assertTrue($layer['all_three_sources_readback_verified']);
        self::assertFalse($layer['all_ota_analysis_gates_allowed']);
        self::assertSame('partial', $layer['revenue_analysis_status']);
        self::assertContains(
            'meituan_ota_revenue_analysis_blocked',
            array_column($layer['analysis_gaps'], 'code')
        );
        self::assertSame(
            [
                'revenue' => null,
                'orders' => null,
                'room_nights' => null,
                'adr' => null,
            ],
            $layer['facts']['ota_channel']['combined']
        );
        self::assertNull(
            $layer['facts']['cross_source_comparison']
                ['ota_revenue_per_whole_hotel_sellable_room']
        );
        self::assertNull(
            $layer['derived_metrics']['ota_room_night_share_percent']['value']
        );
        self::assertNull(
            $layer['derived_metrics']['ota_room_revenue_share_percent']['value']
        );
        self::assertNull(
            $layer['analysis_metrics']['ota_contribution_revpar']['value']
        );
    }

    public function testRevenueRepresentationConflictBlocksAnalysisAndIsReconciled(): void
    {
        $operational = $this->otaOperationalMetrics();
        $operational['meituan']['data_quality'] = [
            'revenue_representation_conflicts' => [[
                'system_hotel_id' => 80,
                'business_date' => '2026-07-30',
                'winner_row_id' => 81820,
                'winner_data_type' => 'business',
                'winner_amount' => 1032.39,
                'winner_room_nights' => 1,
                'winner_order_count' => 1,
                'candidate_row_id' => 81606,
                'candidate_data_type' => 'order',
                'candidate_amount' => 1135.63,
                'candidate_room_nights' => 1,
                'candidate_order_count' => 1,
                'amount_delta' => 999999,
                'amount_delta_percent_of_winner' => 999999,
            ]],
        ];

        $layer = (new RevenueFactLayerService())->assemble(
            $this->hotel(),
            '2026-07-30',
            $this->pmsCapture(),
            $this->otaResult(),
            [],
            $operational
        );

        self::assertSame(
            'readback_verified',
            $layer['sources']['meituan_ota']['data_status']
        );
        self::assertSame(
            1032.39,
            $layer['facts']['ota_channel']['meituan']['revenue']
        );
        self::assertFalse(
            $layer['sources']['meituan_ota']['analysis_readiness']['allowed']
        );
        self::assertSame(
            'blocked_representation_conflict',
            $layer['sources']['meituan_ota']['analysis_readiness']['status']
        );
        self::assertSame(
            ['ota_channel_metric_level_display'],
            $layer['sources']['meituan_ota']['allowed_uses']
        );
        self::assertFalse($layer['all_ota_analysis_gates_allowed']);
        self::assertNull($layer['facts']['ota_channel']['combined']['revenue']);

        $check = $this->reconciliationCheck(
            $layer,
            'summary_representation'
        );
        self::assertSame('mismatch', $check['status']);
        self::assertCount(1, $check['differences']);
        self::assertSame(
            'revenue_representation',
            $check['differences'][0]['metric']
        );
        self::assertSame(1032.39, $check['differences'][0]['selected_value']);
        self::assertSame(1135.63, $check['differences'][0]['candidate_value']);
        self::assertSame(103.24, $check['differences'][0]['delta']);
        self::assertSame(
            10.0,
            $check['differences'][0]['delta_percent_of_selected']
        );
    }

    public function testOperationalMetricsRequireExactHotelPlatformAndBusinessDate(): void
    {
        $base = $this->otaOperationalMetrics()['ctrip'];
        $cases = [
            'wrong_hotel' => static function (array &$identity): void {
                $identity['system_hotel_ids'] = [81];
            },
            'wrong_platform' => static function (array &$identity): void {
                $identity['platforms'] = ['meituan'];
            },
            'wrong_date' => static function (array &$identity): void {
                $identity['date_range'] = [
                    'start' => '2026-07-29',
                    'end' => '2026-07-29',
                ];
            },
        ];

        foreach ($cases as $caseName => $mutateIdentity) {
            $ctrip = $base;
            foreach ($ctrip['fact_statuses'] as &$status) {
                $identity = is_array($status['source_identity'] ?? null)
                    ? $status['source_identity']
                    : [];
                $mutateIdentity($identity);
                $status['source_identity'] = $identity;
            }
            unset($status);

            $layer = (new RevenueFactLayerService())->assemble(
                $this->hotel(),
                '2026-07-30',
                $this->pmsCapture(),
                [
                    'data_status' => 'empty',
                    'data_gaps' => ['trusted_ota_revenue_missing'],
                    'rows' => [],
                ],
                [],
                ['ctrip' => $ctrip]
            );

            self::assertSame(
                'missing',
                $layer['sources']['ctrip_ota']['data_status'],
                $caseName
            );
            self::assertNull(
                $layer['sources']['ctrip_ota']['actual_business_date'],
                $caseName
            );
            self::assertNull(
                $layer['facts']['ota_channel']['ctrip']['orders'],
                $caseName
            );
            self::assertNull(
                $layer['facts']['ota_channel']['ctrip']['room_nights'],
                $caseName
            );
            self::assertNull(
                $layer['facts']['ota_channel']['ctrip']['detail_exposure'],
                $caseName
            );
            self::assertSame(
                'not_verified',
                $layer['sources']['ctrip_ota']['fact_statuses']['orders']['status'],
                $caseName
            );
        }
    }

    public function testDerivedStatusIsAcceptedOnlyForDeclaredDerivedOperationalMetrics(): void
    {
        $ctrip = $this->otaOperationalMetrics()['ctrip'];
        foreach ($ctrip['facts'] as $metricKey => $_value) {
            $ctrip['facts'][$metricKey] = null;
            $ctrip['fact_statuses'][$metricKey]['status'] = 'missing';
            $ctrip['fact_statuses'][$metricKey]['reason'] = 'fixture_missing';
        }
        $ctrip['facts']['orders'] = 0.0;
        $ctrip['fact_statuses']['orders']['status'] = 'derived_verified';
        $ctrip['fact_statuses']['orders']['reason'] = '';
        $ctrip['facts']['adr'] = 973.0;
        $ctrip['fact_statuses']['adr']['status'] = 'derived_verified';
        $ctrip['fact_statuses']['adr']['reason'] = '';

        $layer = (new RevenueFactLayerService())->assemble(
            $this->hotel(),
            '2026-07-30',
            $this->pmsCapture(),
            [
                'data_status' => 'empty',
                'data_gaps' => ['trusted_ota_revenue_missing'],
                'rows' => [],
            ],
            [],
            ['ctrip' => $ctrip]
        );

        self::assertNull($layer['facts']['ota_channel']['ctrip']['orders']);
        self::assertSame(973.0, $layer['facts']['ota_channel']['ctrip']['adr']);
        self::assertSame(
            'not_verified',
            $layer['sources']['ctrip_ota']['fact_statuses']['orders']['status']
        );
        self::assertSame(
            'derived_status_not_allowed_for_raw_metric',
            $layer['sources']['ctrip_ota']['fact_statuses']['orders']['reason']
        );
        self::assertSame(
            'derived_verified',
            $layer['sources']['ctrip_ota']['fact_statuses']['adr']['status']
        );
    }

    public function testDeniedPmsCollectionClaimKeepsFactsNullAndAnalysisBlocked(): void
    {
        $capture = $this->pmsCapture();
        $capture['collection_result'] =
            (new CollectionResultContractService())
                ->fromDingdandaoCapture($capture);
        $capture['collection_result']['claim'] = [
            'allowed' => false,
            'reason_codes' => ['readback_mismatch'],
        ];

        $layer = (new RevenueFactLayerService())->assemble(
            $this->hotel(),
            '2026-07-30',
            $capture,
            $this->otaResult(),
            []
        );

        self::assertSame('blocked', $layer['revenue_analysis_status']);
        self::assertSame(
            'not_verified',
            $layer['sources']['dingdandao_pms']['data_status']
        );
        self::assertNull(
            $layer['facts']['whole_hotel_accommodation']['room_revenue']
        );
        self::assertContains(
            'collection_claim_not_allowed',
            $layer['sources']['dingdandao_pms']['source']
                ['collection_claim_reason_codes']
        );
        self::assertSame(
            ['dingdandao_pms_not_readback_verified'],
            array_column($layer['ai_review_gaps'], 'code')
        );
        self::assertSame(
            'dingdandao_pms_not_readback_verified',
            $layer['unique_remaining_gap']['code']
        );
        self::assertContains(
            'collection_claim_not_allowed',
            $layer['unique_remaining_gap']['evidence_gap_codes']
        );
        self::assertStringContainsString(
            '来源声明未放行',
            $layer['unique_remaining_gap']['display_reason']
        );
        self::assertStringContainsString(
            '不得补写',
            $layer['unique_remaining_gap']['next_action']
        );
    }

    public function testHistoricalTodayOnlyPmsGapRequiresHistoricalEvidence(): void
    {
        $capture = $this->pmsCapture('2026-07-29');
        $capture['collection_result'] =
            (new CollectionResultContractService())
                ->fromDingdandaoCapture($capture);
        $capture['collection_result']['claim'] = [
            'allowed' => false,
            'reason_codes' => ['source_evidence_mismatch'],
        ];

        $ota = $this->otaResult();
        foreach ($ota['rows'] as &$row) {
            $row['data_date'] = '2026-07-29';
        }
        unset($row);

        $layer = (new RevenueFactLayerService())->assemble(
            $this->hotel(),
            '2026-07-29',
            $capture,
            $ota,
            []
        );

        $gap = $layer['unique_remaining_gap'];
        self::assertSame('dingdandao_pms_not_readback_verified', $gap['code']);
        self::assertSame(
            'historical_recollection_available',
            $gap['recovery_status']
        );
        self::assertFalse($gap['live_recollection_allowed']);
        self::assertTrue($gap['historical_recollection_allowed']);
        self::assertStringContainsString('历史业务日', $gap['next_action']);
        self::assertStringContainsString('单日经营指标补采', $gap['next_action']);
    }

    public function testOperatorFloorPriceClosesTheFactLayerReviewInputGap(): void
    {
        $layer = (new RevenueFactLayerService())->assemble(
            $this->hotel(),
            '2026-07-30',
            $this->pmsCapture(),
            $this->otaResult(),
            [[
                'id' => 3,
                'hotel_id' => 80,
                'name' => '标准间',
                'base_price' => 500,
                'min_price' => 350,
                'max_price' => 800,
                'room_count' => 15,
                'is_enabled' => 1,
            ]]
        );

        self::assertSame('ready', $layer['revenue_analysis_status']);
        self::assertSame('ready_for_manual_review', $layer['ai_review_status']);
        self::assertSame([], $layer['ai_review_gaps']);
        self::assertNull($layer['unique_remaining_gap']);
        self::assertSame(
            350.0,
            $layer['sources']['pricing_guard']['minimum_floor_price']
        );
        self::assertSame(
            'reference_only',
            $this->reconciliationCheck($layer, 'floor_vs_sales')['status']
        );
        self::assertSame(
            682.39,
            $this->reconciliationCheck($layer, 'floor_vs_sales')['reference_gap']
        );
        self::assertSame(
            'ready_to_share',
            $layer['analysis_diagnostics']['overall_assessment']
        );
    }

    /** @return array<string,mixed> */
    private function hotel(): array
    {
        return [
            'id' => 80,
            'tenant_id' => 80,
            'name' => '敦煌漠蓝新',
            'status' => 1,
        ];
    }

    /** @return array<string,mixed> */
    private function pmsCapture(string $businessDate = '2026-07-30'): array
    {
        $sourceApiPath = '/api/verified';
        $sourceUrl = DingdandaoOperatingTargetCaptureService::SOURCE_URL;
        $providerHotelId = '5206408';
        $collectionMode = 'full_diagnostic';
        $recipeEvidence =
            DingdandaoOperatingTargetCaptureService::expectedRecipeEvidence(
                $collectionMode
            );
        self::assertIsArray($recipeEvidence);
        $traceBasis = [
            'platform' => 'dingdandao',
            'section' => 'pms_full_diagnostic',
            'source_path' => $sourceApiPath . '#data',
            'capture_source' => 'existing_session_direct_post',
            'source_url_hash' => hash('sha256', $sourceUrl),
            'source_kind' => 'pms',
            'business_module' => 'accommodation_operating',
            'source_method' => 'authorized_browser_endpoint',
            'collection_mode' => $collectionMode,
            'data_date' => $businessDate,
            'provider_hotel_id_hash' => hash('sha256', $providerHotelId),
            'capture_strategy' => 'verified_endpoint_recipe',
            'fallback_from' => null,
            'fallback_reason' => null,
            'response_evidence_type' => 'structured_json',
            'recipe_plan_hash' => $recipeEvidence['recipe_plan_hash'],
            'recipe_count' => $recipeEvidence['recipe_count'],
        ];
        $sourceTraceId = 'dingdandao:' . hash(
            'sha256',
            (string)json_encode(
                $traceBasis,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_INVALID_UTF8_SUBSTITUTE
            )
        );
        return [
            'id' => 6,
            'tenant_id' => 80,
            'hotel_id' => 80,
            'provider' => 'dingdandao_pms',
            'provider_hotel_id' => $providerHotelId,
            'provider_hotel_name' => '敦煌漠蓝',
            'business_date' => $businessDate,
            'source_url' => $sourceUrl,
            'source_api_path' => $sourceApiPath,
            'source_scope' => DingdandaoOperatingTargetCaptureService::SOURCE_SCOPE,
            'collection_mode' => $collectionMode,
            'capture_method' => 'network_response',
            'capture_strategy' => 'verified_endpoint_recipe',
            'capture_status' => 'verified',
            'quality_status' => 'verified',
            'identity_status' => 'matched',
            'reconciliation_status' => 'matched',
            'readback_status' => 'readback_verified',
            'captured_at' => $businessDate . ' 01:36:11',
            'source_trace_id' => $sourceTraceId,
            'source_fingerprint' => str_repeat('c', 64),
            'detail_row_count' => 1,
            'summary' => [
                'total_room_fee' => 7930.11,
                'sold_room_nights' => 15,
                'average_daily_room_nights' => 15.0,
                'derived_sellable_room_nights' => 15,
                'occupancy_rate_percent' => 100.0,
                'adr' => 528.67,
                'revpar' => 528.67,
            ],
            'capture_evidence' => [
                'source_path' => $sourceApiPath . '#data',
                'capture_source' => 'existing_session_direct_post',
                'section' => 'pms_full_diagnostic',
                'source_kind' => 'pms',
                'business_module' => 'accommodation_operating',
                'source_method' => 'authorized_browser_endpoint',
                'collection_mode' => $collectionMode,
                'data_date' => $businessDate,
                'provider_hotel_id_hash' => hash('sha256', $providerHotelId),
                'source_url_hash' => hash('sha256', $sourceUrl),
                'capture_strategy' => 'verified_endpoint_recipe',
                'fallback_from' => null,
                'fallback_reason' => null,
                'response_evidence_type' => 'structured_json',
                'recipe_plan_hash' => $recipeEvidence['recipe_plan_hash'],
                'recipe_count' => $recipeEvidence['recipe_count'],
                'source_trace_id' => $sourceTraceId,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function otaResult(): array
    {
        return [
            'data_status' => 'ready',
            'data_gaps' => [],
            'rows' => [
                [
                    'row_id' => 66156,
                    'system_hotel_id' => 80,
                    'data_date' => '2026-07-30',
                    'amount' => 0.0,
                    'quantity' => 0.0,
                    'book_order_num' => 0.0,
                    'source' => 'ctrip',
                    'readback_verified' => true,
                    'source_trace_id' => 'ctrip-trusted-trace',
                    'data_source_id' => 25,
                    'sync_task_id' => 1001,
                    'ingestion_method' => 'profile_browser',
                ],
                [
                    'row_id' => 66635,
                    'system_hotel_id' => 80,
                    'data_date' => '2026-07-30',
                    'amount' => 1032.39,
                    'quantity' => 1.0,
                    'book_order_num' => 1.0,
                    'source' => 'meituan',
                    'readback_verified' => true,
                    'source_trace_id' => 'meituan-trusted-trace',
                    'data_source_id' => 68,
                    'sync_task_id' => 1002,
                    'ingestion_method' => 'profile_browser',
                ],
            ],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function otaOperationalMetrics(): array
    {
        $metricKeys = [
            'revenue',
            'orders',
            'room_nights',
            'adr',
            'list_exposure',
            'detail_exposure',
            'flow_rate_percent',
            'submit_rate_percent',
            'cancellation_rate_percent',
        ];
        $statusesFor = static function (string $platform) use ($metricKeys): array {
            $statuses = [];
            foreach ($metricKeys as $metric) {
                $statuses[$metric] = [
                    'status' => 'readback_verified',
                    'reason' => '',
                    'source_identity' => [
                        'status' => 'matched',
                        'system_hotel_ids' => [80],
                        'platforms' => [$platform],
                        'date_range' => [
                            'start' => '2026-07-30',
                            'end' => '2026-07-30',
                        ],
                    ],
                ];
            }
            return $statuses;
        };
        $ctripStatuses = $statusesFor('ctrip');
        $ctripStatuses['revenue'] = array_merge($ctripStatuses['revenue'], [
            'status' => 'missing',
            'reason' => 'trusted_ota_revenue_missing',
        ]);
        $ctripStatuses['adr'] = array_merge($ctripStatuses['adr'], [
            'status' => 'missing',
            'reason' => 'ota_room_nights_denominator_missing_or_zero',
        ]);
        $ctripStatuses['cancellation_rate_percent'] = array_merge(
            $ctripStatuses['cancellation_rate_percent'],
            [
            'status' => 'missing',
            'reason' => 'ota_order_count_denominator_zero',
            ]
        );
        $meituanStatuses = $statusesFor('meituan');

        return [
            'ctrip' => [
                'data_status' => 'partial',
                'analysis_readiness' => [
                    'allowed' => true,
                    'status' => 'allowed_with_data_warnings',
                ],
                'facts' => [
                    'revenue' => null,
                    'orders' => 0.0,
                    'room_nights' => 0.0,
                    'adr' => null,
                    'list_exposure' => 1200.0,
                    'detail_exposure' => 680.0,
                    'flow_rate_percent' => 56.67,
                    'submit_rate_percent' => 0.0,
                    'cancellation_rate_percent' => null,
                ],
                'fact_statuses' => $ctripStatuses,
                'data_gaps' => [],
            ],
            'meituan' => [
                'data_status' => 'ready',
                'analysis_readiness' => [
                    'allowed' => true,
                    'status' => 'allowed',
                ],
                'facts' => [
                    'revenue' => 1032.39,
                    'orders' => 1.0,
                    'room_nights' => 1.0,
                    'adr' => 1032.39,
                    'list_exposure' => 920.0,
                    'detail_exposure' => 310.0,
                    'flow_rate_percent' => 33.7,
                    'submit_rate_percent' => 4.2,
                    'cancellation_rate_percent' => 12.0,
                ],
                'fact_statuses' => $meituanStatuses,
                'data_gaps' => [],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function reconciliationCheck(array $layer, string $key): array
    {
        foreach ((array)($layer['reconciliation']['checks'] ?? []) as $check) {
            if (is_array($check) && ($check['key'] ?? null) === $key) {
                return $check;
            }
        }

        self::fail('Missing reconciliation check: ' . $key);
    }
}
