<?php
declare(strict_types=1);

namespace Tests;

use app\service\DualOtaOrderQuickAnalysisService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\RouteContractSource;

final class DualOtaOrderQuickAnalysisServiceTest extends TestCase
{
    public function testBothPlatformsProduceVerifiedMetricsAndComparableDeltas(): void
    {
        $rows = [
            $this->orderRow('ctrip', 1, [
                'room_revenue' => 1200,
                'amount' => 1200,
                'quantity' => 6,
                'book_order_num' => 5,
                'cancel_rate' => 10,
            ]),
            $this->orderRow('meituan', 2, [
                'room_revenue' => 900,
                'amount' => 900,
                'quantity' => 5,
                'book_order_num' => 3,
                'cancel_rate' => 20,
            ]),
            $this->flowRow('loss', 3, 4, 7, 800),
            $this->flowRow('inflow', 4, 2, 3, 420),
            $this->orderRow('ctrip', 5, [
                'tenant_id' => 99,
                'room_revenue' => 99999,
                'amount' => 99999,
                'book_order_num' => 99,
            ]),
            $this->orderRow('ctrip', 6, [
                'system_hotel_id' => 81,
                'room_revenue' => 88888,
                'amount' => 88888,
                'book_order_num' => 88,
            ]),
        ];

        $analysis = $this->service($rows)->analyze(
            80,
            9,
            '2026-08-20',
            '2026-08-20',
            ['name' => '测试酒店']
        );

        self::assertSame(DualOtaOrderQuickAnalysisService::CONTRACT_VERSION, $analysis['contract_version']);
        self::assertSame('ota_channel', $analysis['metric_scope']);
        self::assertSame('ready', $analysis['status']);
        self::assertTrue($analysis['comparison']['can_compare']);
        self::assertSame('测试酒店', $analysis['hotel']['name']);
        self::assertSame(['2026-08-20'], $analysis['comparison']['date_keys']);
        self::assertSame('ready', $analysis['comparison']['status']);

        $ctrip = $analysis['platforms']['ctrip'];
        $meituan = $analysis['platforms']['meituan'];
        self::assertSame(5, $ctrip['metrics']['orders']['value']);
        self::assertSame('verified', $ctrip['metrics']['orders']['status']);
        self::assertTrue($ctrip['metrics']['orders']['source_trust']['saved_success']);
        self::assertSame(1200, $ctrip['metrics']['revenue']['value']);
        self::assertSame(200, $ctrip['metrics']['adr']['value']);
        self::assertSame(3, $meituan['metrics']['orders']['value']);
        self::assertSame(900, $meituan['metrics']['revenue']['value']);

        self::assertSame('ready', $analysis['comparison']['metrics']['orders']['status']);
        self::assertSame(2, $analysis['comparison']['metrics']['orders']['delta']);
        self::assertSame('ctrip', $analysis['comparison']['metrics']['orders']['leader']);
        self::assertSame('verified', $meituan['order_flow']['status']);
        self::assertSame(4, $meituan['order_flow']['loss']['orders']);
        self::assertSame(420, $meituan['order_flow']['inflow']['amount']);
        self::assertCount(4, $analysis['actions']);
    }

    public function testOneMissingPlatformStaysPartialAndBlocksComparison(): void
    {
        $analysis = $this->service([
            $this->orderRow('ctrip', 1),
        ])->analyze(80, 9, '2026-08-20', '2026-08-20');

        self::assertSame('partial', $analysis['status']);
        self::assertSame('verified', $analysis['platforms']['ctrip']['status']);
        self::assertSame('missing', $analysis['platforms']['meituan']['status']);
        self::assertSame(
            'blocked_by_missing_platform',
            $analysis['comparison']['status']
        );
        self::assertSame(
            'meituan_order_facts_missing',
            $analysis['comparison']['reason_code']
        );
        self::assertTrue($this->action($analysis, 'meituan_order_collect')['required']);
        self::assertSame(
            'meituan-orders',
            $this->action($analysis, 'meituan_order_collect')['tab']
        );
    }

    public function testSameDatesWithDifferentFactSemanticsRemainSeparateReady(): void
    {
        $analysis = $this->service([
            $this->orderRow('ctrip', 1),
            $this->verifiedMeituanSalePriceRow(2),
        ])->analyze(80, 9, '2026-08-20', '2026-08-20');

        self::assertSame('verified', $analysis['platforms']['ctrip']['status']);
        self::assertSame('verified', $analysis['platforms']['meituan']['status']);
        self::assertSame('separate_ready', $analysis['status']);
        self::assertSame(
            'blocked_by_incomparable_scope',
            $analysis['comparison']['status']
        );
        self::assertFalse($analysis['comparison']['can_compare']);
        self::assertSame(
            'metric_definition_or_fact_basis_differs',
            $analysis['comparison']['metrics']['orders']['reason_code']
        );
        self::assertNull($analysis['comparison']['metrics']['revenue']['delta']);
    }

    public function testVerifiedZeroValuesArePreservedInsteadOfBecomingMissing(): void
    {
        $zero = [
            'room_revenue' => 0,
            'amount' => 0,
            'quantity' => 0,
            'book_order_num' => 0,
            'cancel_rate' => 0,
        ];
        $analysis = $this->service([
            $this->orderRow('ctrip', 1, $zero),
            $this->orderRow('meituan', 2, $zero),
        ])->analyze(80, 9, '2026-08-20', '2026-08-20');

        foreach (['orders', 'room_nights', 'revenue', 'cancellation_rate'] as $metric) {
            self::assertSame(0, $analysis['platforms']['ctrip']['metrics'][$metric]['value']);
            self::assertSame('verified', $analysis['platforms']['ctrip']['metrics'][$metric]['status']);
            self::assertSame(0, $analysis['platforms']['meituan']['metrics'][$metric]['value']);
            self::assertSame('verified', $analysis['platforms']['meituan']['metrics'][$metric]['status']);
            self::assertSame('ready', $analysis['comparison']['metrics'][$metric]['status']);
            self::assertSame(0, $analysis['comparison']['metrics'][$metric]['delta']);
            self::assertSame('equal', $analysis['comparison']['metrics'][$metric]['leader']);
        }
        self::assertSame('missing', $analysis['platforms']['ctrip']['metrics']['adr']['status']);
        self::assertSame('partial', $analysis['platforms']['ctrip']['status']);
        self::assertSame('partial', $analysis['status']);
        self::assertTrue($analysis['comparison']['can_compare']);
    }

    public function testUnverifiedValuesStayVisibleButCannotBeComparedOrLeakPii(): void
    {
        $analysis = $this->service([
            $this->orderRow('ctrip', 1),
            $this->orderRow('meituan', 2, [
                'readback_verified' => 0,
                'error_info' => '住客姓名：不应输出的住客姓名；电话：13800000000',
                'raw_data' => json_encode([
                    'guest_name' => '不应输出的住客姓名',
                    'phone' => '13800000000',
                ], JSON_UNESCAPED_UNICODE),
            ]),
        ])->analyze(80, 9, '2026-08-20', '2026-08-20');

        self::assertSame(
            'available_unverified',
            $analysis['platforms']['meituan']['metrics']['orders']['status']
        );
        self::assertSame(4, $analysis['platforms']['meituan']['metrics']['orders']['value']);
        self::assertSame(
            'blocked_by_incomparable_scope',
            $analysis['comparison']['metrics']['orders']['status']
        );
        $json = json_encode($analysis, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('不应输出的住客姓名', $json);
        self::assertStringNotContainsString('13800000000', $json);
        self::assertContains(
            'source_error_info_present',
            $analysis['platforms']['meituan']['metrics']['orders']['source_trust']['failure_reasons']
        );
    }

    public function testReadbackOnlyRowsRemainUnverifiedUntilStrictHistoryGatePasses(): void
    {
        $notStrict = [
            'validation_status' => '',
            'history_status' => '',
        ];
        $analysis = $this->service([
            $this->orderRow('ctrip', 1, $notStrict),
            $this->orderRow('meituan', 2, $notStrict),
            array_replace($this->flowRow('loss', 3, 2, 4, 500), $notStrict),
            array_replace($this->flowRow('inflow', 4, 1, 2, 260), $notStrict),
        ])->analyze(80, 9, '2026-08-20', '2026-08-20');

        foreach (['ctrip', 'meituan'] as $platform) {
            self::assertSame(
                'available_unverified',
                $analysis['platforms'][$platform]['metrics']['orders']['status']
            );
            self::assertSame(
                'strict_validation_status_missing',
                $analysis['platforms'][$platform]['metrics']['orders']['reason_code']
            );
            self::assertSame('partial', $analysis['platforms'][$platform]['status']);
        }
        self::assertSame('partial', $analysis['status']);
        self::assertFalse($analysis['comparison']['can_compare']);
        self::assertSame(
            'available_unverified',
            $analysis['platforms']['meituan']['order_flow']['status']
        );
        self::assertSame(
            'strict_validation_status_missing',
            $analysis['platforms']['meituan']['order_flow']['reason_code']
        );
    }

    public function testUnknownDateBasisBlocksOtherwiseVerifiedSameDateMetrics(): void
    {
        $analysis = $this->service([
            $this->orderRow('ctrip', 1),
            $this->orderRow('meituan', 2, ['raw_data' => '{}']),
        ])->analyze(80, 9, '2026-08-20', '2026-08-20');

        self::assertSame('separate_ready', $analysis['status']);
        self::assertFalse($analysis['comparison']['can_compare']);
        self::assertSame(
            'platform_date_basis_unknown',
            $analysis['comparison']['reason_code']
        );
        self::assertSame('stay_date', $analysis['platforms']['ctrip']['comparison_basis']['date_basis']);
        self::assertSame('unknown', $analysis['platforms']['meituan']['comparison_basis']['date_basis']);
    }

    public function testCtripDeepAnalysisDateBasisOverridesRawProjectionBasis(): void
    {
        $analysis = $this->service([
            $this->orderRow('ctrip', 1, [
                'raw_data' => json_encode([
                    'date_basis' => 'order_date',
                    'order_count_basis' => 'paid_orders',
                    'room_nights_basis' => 'booked_room_nights',
                    'record_kind' => 'order_daily_aggregate',
                ]),
            ]),
            $this->orderRow('meituan', 2),
        ])->analyze(80, 9, '2026-08-20', '2026-08-20');

        self::assertSame(
            'stay_date',
            $analysis['platforms']['ctrip']['comparison_basis']['date_basis']
        );
        self::assertSame(
            'ctrip_deep_analysis.date_range.basis',
            $analysis['platforms']['ctrip']['comparison_basis']['date_basis_source']
        );
        self::assertTrue($analysis['comparison']['can_compare']);
    }

    public function testOrderFlowAmountNeverEntersRevenueMetrics(): void
    {
        $analysis = $this->service([
            $this->orderRow('meituan', 1, [
                'room_revenue' => 100,
                'amount' => 100,
                'quantity' => 1,
                'book_order_num' => 1,
            ]),
            $this->flowRow('loss', 2, 8, 12, 9999),
        ])->analyze(80, 9, '2026-08-20', '2026-08-20');

        self::assertSame(100, $analysis['platforms']['meituan']['metrics']['revenue']['value']);
        self::assertSame(9999, $analysis['platforms']['meituan']['order_flow']['loss']['amount']);
        self::assertSame('available_unverified', $analysis['platforms']['meituan']['order_flow']['status']);
        self::assertContains(
            'order_flow_direction_missing',
            $analysis['platforms']['meituan']['order_flow']['source_trust']['failure_reasons']
        );
        self::assertSame(
            'ota_order_flow_non_revenue_fact',
            $analysis['platforms']['meituan']['order_flow']['calculation_basis']
        );
    }

    public function testMissingOrInvalidDateRangeFailsClosed(): void
    {
        $service = $this->service([]);

        try {
            $service->analyze(80, 9, '2026-08-20', null);
            self::fail('A half-open date range must fail closed.');
        } catch (RuntimeException $error) {
            self::assertSame(422, $error->getCode());
            self::assertStringContainsString('同时填写', $error->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(422);
        $this->expectExceptionMessage('日期范围无效');
        $service->analyze(80, 9, '2026-02-30', '2026-03-01');
    }

    public function testImplicitRangeUsesLatestScopedDateAndOnlyRecentThirtyDays(): void
    {
        $analysis = $this->service([
            $this->orderRow('ctrip', 1, ['data_date' => '2026-06-01', 'book_order_num' => 9]),
            $this->orderRow('ctrip', 2, ['data_date' => '2026-07-31', 'book_order_num' => 2]),
            $this->orderRow('meituan', 3, ['data_date' => '2026-07-31', 'book_order_num' => 1]),
            $this->orderRow('ctrip', 4, [
                'tenant_id' => 99,
                'data_date' => '2026-12-31',
                'book_order_num' => 100,
            ]),
            $this->orderRow('agoda', 5, [
                'data_date' => '2026-12-31',
                'book_order_num' => 200,
            ]),
            $this->orderRow('ctrip', 6, [
                'platform' => 'agoda',
                'data_date' => '2026-12-31',
                'book_order_num' => 300,
            ]),
        ])->analyze(80, 9);

        self::assertSame('2026-07-02', $analysis['date_range']['from']);
        self::assertSame('2026-07-31', $analysis['date_range']['to']);
        self::assertSame('latest_available_30_days', $analysis['date_range']['selection_mode']);
        self::assertSame(2, $analysis['platforms']['ctrip']['metrics']['orders']['value']);
    }

    public function testDatabaseSourceFallbackOnlyAppliesWhenPlatformIsBlank(): void
    {
        $service = (string)file_get_contents(
            __DIR__ . '/../app/service/DualOtaOrderQuickAnalysisService.php'
        );
        $scopeStart = strpos($service, 'private function applyDualPlatformQueryScope');
        $scopeEnd = strpos($service, 'private function dateKeys', (int)$scopeStart);
        self::assertNotFalse($scopeStart);
        self::assertNotFalse($scopeEnd);
        $scope = substr($service, (int)$scopeStart, (int)$scopeEnd - (int)$scopeStart);

        self::assertStringContainsString("TRIM(COALESCE(platform, '')) = ''", $scope);
        $fallbackStart = strpos($scope, '->whereOr(static function ($fallback)');
        $sourceStart = strpos($scope, '->where(static function ($source)', (int)$fallbackStart);
        self::assertNotFalse($fallbackStart);
        self::assertNotFalse($sourceStart);
        self::assertGreaterThan($fallbackStart, $sourceStart);
        self::assertStringNotContainsString(
            "->whereOr('source'",
            substr($scope, 0, (int)$fallbackStart)
        );
        self::assertStringNotContainsString("->max('data_date')", $service);
        self::assertStringContainsString("->order('data_date', 'desc')", $service);
        self::assertStringContainsString('(clone $query)->count()', $service);
        self::assertStringContainsString('private const MAX_SCOPED_ROWS = 5000;', $service);
    }

    public function testRealIngestionDateFieldsResolveToCanonicalStayDate(): void
    {
        $commonBasis = [
            'order_count_basis' => 'paid_orders',
            'room_nights_basis' => 'booked_room_nights',
            'record_kind' => 'order_daily_aggregate',
        ];
        $analysis = $this->service([
            $this->orderRow('ctrip', 1, [
                'raw_data' => json_encode($commonBasis + [
                    'business_date_basis' => 'stay_date',
                ], JSON_UNESCAPED_UNICODE),
            ]),
            $this->orderRow('meituan', 2, [
                'raw_data' => json_encode($commonBasis + [
                    'checkInDate' => '2026-08-20',
                    'date_source' => 'check_in_date',
                ], JSON_UNESCAPED_UNICODE),
            ]),
        ])->analyze(80, 9, '2026-08-20', '2026-08-20');

        self::assertSame('stay_date', $analysis['platforms']['ctrip']['comparison_basis']['date_basis']);
        self::assertSame('stay_date', $analysis['platforms']['meituan']['comparison_basis']['date_basis']);
        self::assertTrue($analysis['comparison']['can_compare']);
    }

    public function testOrderCountBasisMismatchBlocksOnlyTheUnsafeMetric(): void
    {
        $analysis = $this->service([
            $this->orderRow('ctrip', 1, [
                'raw_data' => json_encode([
                    'date_basis' => 'stay_date',
                    'order_count_basis' => 'active_non_cancelled_orders',
                    'room_nights_basis' => 'booked_room_nights',
                    'record_kind' => 'order_daily_aggregate',
                ], JSON_UNESCAPED_UNICODE),
            ]),
            $this->orderRow('meituan', 2, [
                'raw_data' => json_encode([
                    'date_basis' => 'stay_date',
                    'order_count_basis' => 'listed_orders',
                    'room_nights_basis' => 'booked_room_nights',
                    'record_kind' => 'order_daily_aggregate',
                ], JSON_UNESCAPED_UNICODE),
            ]),
        ])->analyze(80, 9, '2026-08-20', '2026-08-20');

        self::assertSame(
            'blocked_by_incomparable_scope',
            $analysis['comparison']['metrics']['orders']['status']
        );
        self::assertSame('ready', $analysis['comparison']['metrics']['room_nights']['status']);
    }

    public function testAggregateAndDetailOverlapStopsWrongPlatformTotals(): void
    {
        $aggregate = $this->orderRow('ctrip', 1, [
            'dimension' => '',
            'book_order_num' => 4,
            'raw_data' => json_encode([
                'date_basis' => 'stay_date',
                'order_count_basis' => 'paid_orders',
                'room_nights_basis' => 'booked_room_nights',
            ], JSON_UNESCAPED_UNICODE),
        ]);
        $detail = $this->orderRow('ctrip', 2, [
            'dimension' => 'order:paid:' . str_repeat('a', 64),
            'book_order_num' => 1,
            'raw_data' => json_encode([
                'date_basis' => 'stay_date',
                'order_count_basis' => 'paid_orders',
                'room_nights_basis' => 'booked_room_nights',
                'record_kind' => 'order_detail',
                'order_id_hash' => str_repeat('a', 64),
            ], JSON_UNESCAPED_UNICODE),
        ]);
        $analysis = $this->service([
            $aggregate,
            $detail,
            $this->orderRow('meituan', 3),
        ])->analyze(80, 9, '2026-08-20', '2026-08-20');

        self::assertTrue($analysis['platforms']['ctrip']['evidence']['representation_evidence']['conflict']);
        self::assertNull($analysis['platforms']['ctrip']['metrics']['orders']['value']);
        self::assertSame('missing', $analysis['platforms']['ctrip']['metrics']['orders']['status']);
        self::assertSame('representation_conflict', $analysis['platforms']['ctrip']['metrics']['orders']['reason_code']);
        self::assertTrue($this->action($analysis, 'ctrip_order_collect')['required']);
        self::assertSame(
            'ctrip_order_facts_unverified_or_conflicted',
            $this->action($analysis, 'ctrip_order_collect')['reason_code']
        );
        self::assertFalse($analysis['comparison']['can_compare']);
        self::assertSame(
            'platform_order_representation_conflict',
            $analysis['comparison']['reason_code']
        );
    }

    public function testControllerRouteAndDatabaseReadsCarryHotelAndTenantGates(): void
    {
        $concern = (string)file_get_contents(
            __DIR__ . '/../app/controller/concern/PlatformDataSourceConcern.php'
        );
        $service = (string)file_get_contents(
            __DIR__ . '/../app/service/DualOtaOrderQuickAnalysisService.php'
        );
        $routes = RouteContractSource::read(dirname(__DIR__));

        self::assertStringContainsString('public function dualOtaOrderAnalysis(): Response', $concern);
        self::assertStringContainsString("hasHotelPermission((int)\$systemHotelId, 'can_view_online_data')", $concern);
        self::assertStringContainsString("->field('id,name,tenant_id')", $concern);
        self::assertStringContainsString("->where('tenant_id', \$tenantId)", $service);
        self::assertStringContainsString("->where('system_hotel_id', \$systemHotelId)", $service);
        self::assertStringContainsString("->whereIn('data_type', ['order', 'order_flow'])", $service);
        self::assertStringContainsString(
            "Route::get('/dual-ota/order-analysis', 'OnlineData/dualOtaOrderAnalysis');",
            $routes
        );
        $dualStart = strpos($concern, 'public function dualOtaOrderAnalysis(): Response');
        $dualEnd = strpos($concern, 'public function collectionResourceCatalog(): Response', (int)$dualStart);
        self::assertNotFalse($dualStart);
        self::assertNotFalse($dualEnd);
        $dualMethod = substr($concern, (int)$dualStart, (int)$dualEnd - (int)$dualStart);
        self::assertStringNotContainsString("\$requestData['hotel_id']", $dualMethod);
        self::assertStringNotContainsString("\$requestData['hotelId']", $dualMethod);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function service(array $rows): DualOtaOrderQuickAnalysisService
    {
        return new DualOtaOrderQuickAnalysisService(
            rowProvider: static fn(): array => $rows,
            ctripAnalysisProvider: static fn(): array => [
                'status' => 'available_partial',
                'quality_status' => 'user_provided_unverified',
                'note' => '聚合深度分析可用。',
                'date_range' => ['basis' => 'stay_date'],
            ]
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function orderRow(
        string $platform,
        int $id,
        array $overrides = []
    ): array {
        return array_replace([
            'id' => $id,
            'tenant_id' => 9,
            'system_hotel_id' => 80,
            'hotel_id' => $platform . '-hotel-80',
            'hotel_name' => '测试酒店',
            'source' => $platform,
            'platform' => '',
            'data_type' => 'order',
            'data_date' => '2026-08-20',
            'compare_type' => 'self',
            'dimension' => 'order_daily',
            'amount' => 1000,
            'room_revenue' => 1000,
            'quantity' => 5,
            'book_order_num' => 4,
            'cancel_rate' => 10,
            'source_trace_id' => $platform . '-trace-' . $id,
            'readback_verified' => 1,
            'validation_status' => 'verified',
            'history_status' => 'success',
            'ingestion_method' => 'profile',
            'update_time' => '2026-08-20 12:00:' . str_pad((string)$id, 2, '0', STR_PAD_LEFT),
            'raw_data' => json_encode([
                'date_basis' => 'stay_date',
                'order_count_basis' => 'paid_orders',
                'room_nights_basis' => 'booked_room_nights',
                'record_kind' => 'order_daily_aggregate',
            ], JSON_UNESCAPED_UNICODE),
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function verifiedMeituanSalePriceRow(int $id): array
    {
        $rawRow = [
            'amount' => 1000,
            'quantity' => 5,
            'room_nights' => 5,
            'compare_type' => 'self',
            'is_self' => true,
            'date_basis' => 'stay_date',
            'order_count_basis' => 'paid_orders',
            'room_nights_basis' => 'booked_room_nights',
            'record_kind' => 'order_daily_aggregate',
            'amount_scope' => 'meituan_sale_price_total',
            'amount_source' => 'orderBasePriceModel.salePrice.price',
            'amount_source_unit' => 'cent',
            'amount_storage_unit' => 'yuan',
            'quantity_scope' => 'booked_room_nights',
            'quantity_source' => 'partRefundInfo.totalRoomNightCount',
            'pagination_complete' => true,
            'floor_price_used_as_revenue' => false,
            'guarantee_amount_used_as_revenue' => false,
        ];
        return $this->orderRow('meituan', $id, [
            'room_revenue' => null,
            'raw_data' => json_encode([
                'row' => $rawRow,
                'field_facts' => [
                    [
                        'metric_key' => 'order_amount',
                        'storage_field' => 'online_daily_data.amount',
                        'source_path' => 'data.results.amount',
                        'status' => 'captured',
                    ],
                    [
                        'metric_key' => 'room_nights',
                        'storage_field' => 'online_daily_data.quantity',
                        'source_path' => 'data.results.quantity',
                        'status' => 'captured',
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** @return array<string, mixed> */
    private function flowRow(
        string $direction,
        int $id,
        int $orders,
        int $roomNights,
        float $amount
    ): array {
        return [
            'id' => $id,
            'tenant_id' => 9,
            'system_hotel_id' => 80,
            'hotel_id' => 'meituan-hotel-80',
            'hotel_name' => '测试酒店',
            'source' => 'meituan',
            'data_type' => 'order_flow',
            'data_date' => '2026-08-20',
            'dimension' => 'order_flow:last_30_days:' . $direction . ':summary',
            'source_trace_id' => 'meituan-flow-' . $direction . '-' . $id,
            'readback_verified' => 1,
            'validation_status' => 'verified',
            'history_status' => 'success',
            'ingestion_method' => 'profile',
            'update_time' => '2026-08-20 13:00:' . str_pad((string)$id, 2, '0', STR_PAD_LEFT),
            'raw_data' => json_encode([
                'order_flow_direction' => $direction,
                'order_flow_row_type' => 'summary',
                'order_flow_period' => 'last_30_days',
                'period_start' => '2026-07-22',
                'period_end' => '2026-08-20',
                'order_count' => $orders,
                'room_nights' => $roomNights,
                'amount' => $amount,
                'order_ratio' => 12.5,
            ], JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * @param array<string, mixed> $analysis
     * @return array<string, mixed>
     */
    private function action(array $analysis, string $key): array
    {
        foreach ((array)($analysis['actions'] ?? []) as $action) {
            if (is_array($action) && ($action['key'] ?? '') === $key) {
                return $action;
            }
        }
        self::fail('Action not found: ' . $key);
    }
}
