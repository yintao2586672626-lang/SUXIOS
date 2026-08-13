<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperatingGoalMetricSnapshotService;
use PHPUnit\Framework\TestCase;

final class OperatingGoalMetricSnapshotServiceTest extends TestCase
{
    public function testWholeHotelRevenueRangeReturnsVerifiedSnapshotWithExactEvidence(): void
    {
        $layers = [
            '2026-08-01' => $this->layer('2026-08-01', whole: [
                'facts' => ['room_revenue' => 100],
                'source' => ['record_id' => 101],
            ]),
            '2026-08-02' => $this->layer('2026-08-02', whole: [
                'facts' => ['room_revenue' => 150],
                'source' => ['record_id' => 102],
            ]),
        ];
        $service = new OperatingGoalMetricSnapshotService(
            static fn(int $hotelId, string $date): ?array => $layers[$date] ?? null
        );

        $result = $service->snapshot(
            7,
            80,
            'room_revenue',
            '2026-08-01',
            '2026-08-02',
            ['baseline' => ['fact_scope' => 'whole_hotel_accommodation']]
        );

        self::assertSame('ready', $result['status']);
        self::assertSame([], $result['data_gaps']);
        self::assertSame('room_revenue', $result['snapshot']['metric_key']);
        self::assertSame('revenue', $result['snapshot']['canonical_metric_key']);
        self::assertSame(250.0, $result['snapshot']['value']);
        self::assertSame('CNY', $result['snapshot']['unit']);
        self::assertSame('2026-08-01', $result['snapshot']['period_start']);
        self::assertSame('2026-08-02', $result['snapshot']['period_end']);
        self::assertSame('whole_hotel_accommodation', $result['snapshot']['fact_scope']);
        self::assertSame('dingdandao_pms', $result['snapshot']['platform']);
        self::assertSame(80, $result['snapshot']['system_hotel_id']);
        self::assertSame('pms-80', $result['snapshot']['platform_hotel_id']);
        self::assertSame('accommodation_operating', $result['snapshot']['business_module']);
        self::assertSame('whole_hotel_accommodation', $result['snapshot']['subject']);
        self::assertSame('business_date', $result['snapshot']['date_role']);
        self::assertSame('2026-08-02 23:00:00', $result['snapshot']['captured_at']);
        self::assertSame('verified', $result['snapshot']['quality_status']);
        self::assertSame('readback_verified', $result['snapshot']['readback_status']);
        self::assertSame(2, $result['snapshot']['sample_size']);
        self::assertSame('fully_verified_business_days', $result['snapshot']['sample_size_basis']);
        self::assertSame(
            [
                'dingdandao_operating_target_captures#101',
                'dingdandao_operating_target_captures#102',
            ],
            $result['snapshot']['evidence_refs']
        );
    }

    public function testWholeHotelAdrOccupancyAndRevparUseRangeWeightedInputs(): void
    {
        $layers = [
            '2026-08-01' => $this->layer('2026-08-01', whole: [
                'facts' => [
                    'room_revenue' => 100,
                    'sold_room_nights' => 1,
                    'sellable_room_nights' => 2,
                    'occupancy_rate_percent' => 50,
                    'adr' => 100,
                    'revpar' => 50,
                ],
                'source' => ['record_id' => 111],
            ]),
            '2026-08-02' => $this->layer('2026-08-02', whole: [
                'facts' => [
                    'room_revenue' => 300,
                    'sold_room_nights' => 2,
                    'sellable_room_nights' => 4,
                    'occupancy_rate_percent' => 50,
                    'adr' => 150,
                    'revpar' => 75,
                ],
                'source' => ['record_id' => 112],
            ]),
        ];
        $service = new OperatingGoalMetricSnapshotService(
            static fn(int $hotelId, string $date): ?array => $layers[$date] ?? null
        );

        foreach ([
            'adr' => 133.33,
            'occupancy_rate' => 50.0,
            'revpar' => 66.67,
        ] as $metricKey => $expectedValue) {
            $result = $service->snapshot(7, 80, $metricKey, '2026-08-01', '2026-08-02');
            self::assertSame('ready', $result['status'], $metricKey);
            self::assertSame($metricKey, $result['snapshot']['metric_key'], $metricKey);
            self::assertSame($expectedValue, $result['snapshot']['value'], $metricKey);
            self::assertSame(2, $result['snapshot']['sample_size'], $metricKey);
        }
    }

    public function testCombinedOtaMetricsAggregateOnlyOtaEvidenceAcrossEveryDay(): void
    {
        $layers = [
            '2026-08-01' => $this->layer(
                '2026-08-01',
                ctrip: $this->otaValues(100, 2, 1, 10, 10, 201),
                meituan: $this->otaValues(50, 1, 1, 20, 5, 301)
            ),
            '2026-08-02' => $this->layer(
                '2026-08-02',
                ctrip: $this->otaValues(200, 4, 2, 5, 20, 202),
                meituan: $this->otaValues(150, 3, 3, 10, 10, 302)
            ),
        ];
        $service = new OperatingGoalMetricSnapshotService(
            static fn(int $hotelId, string $date): ?array => $layers[$date] ?? null
        );

        foreach ([
            'ota_revenue' => [500.0, 'CNY'],
            'ota_adr' => [71.43, 'CNY/room_night'],
            'ota_orders' => [10, 'count'],
            'ota_room_nights' => [7, 'room_night'],
            'cancellation_rate' => [8.89, 'percent'],
        ] as $metricKey => [$expectedValue, $expectedUnit]) {
            $result = $service->snapshot(7, 80, $metricKey, '2026-08-01', '2026-08-02');
            self::assertSame('ready', $result['status'], $metricKey);
            self::assertSame($metricKey, $result['snapshot']['metric_key'], $metricKey);
            self::assertSame($expectedValue, $result['snapshot']['value'], $metricKey);
            self::assertSame($expectedUnit, $result['snapshot']['unit'], $metricKey);
            self::assertSame('ota_channel', $result['snapshot']['fact_scope'], $metricKey);
            self::assertSame('combined', $result['snapshot']['platform'], $metricKey);
            self::assertSame(2, $result['snapshot']['sample_size'], $metricKey);
            self::assertSame(
                [
                    'online_daily_data#201',
                    'online_daily_data#202',
                    'online_daily_data#301',
                    'online_daily_data#302',
                ],
                $result['snapshot']['evidence_refs'],
                $metricKey
            );
        }
    }

    public function testBaselineScopeAndPlatformOverrideConflictingOuterContext(): void
    {
        $layers = [
            '2026-08-01' => $this->layer(
                '2026-08-01',
                ctrip: $this->otaValues(100, 2, 1, 10, 10, 211),
                meituan: $this->otaValues(900, 9, 9, 10, 10, 311)
            ),
        ];
        $service = new OperatingGoalMetricSnapshotService(
            static fn(int $hotelId, string $date): ?array => $layers[$date] ?? null
        );

        $result = $service->snapshot(7, 80, 'revenue', '2026-08-01', '2026-08-01', [
            'baseline' => ['fact_scope' => 'ota_channel', 'platform' => 'ctrip'],
            'fact_scope' => 'whole_hotel_accommodation',
            'platform' => 'meituan',
        ]);

        self::assertSame('ready', $result['status']);
        self::assertSame(100.0, $result['snapshot']['value']);
        self::assertSame('revenue', $result['snapshot']['metric_key']);
        self::assertSame('ota_channel', $result['snapshot']['fact_scope']);
        self::assertSame('ctrip', $result['snapshot']['platform']);
        self::assertSame(['online_daily_data#211'], $result['snapshot']['evidence_refs']);
    }

    public function testMissingDayReturnsPartialWithoutInventingZeroSnapshot(): void
    {
        $layers = [
            '2026-08-01' => $this->layer('2026-08-01', whole: [
                'facts' => ['room_revenue' => 125],
                'source' => ['record_id' => 121],
            ]),
        ];
        $service = new OperatingGoalMetricSnapshotService(
            static fn(int $hotelId, string $date): ?array => $layers[$date] ?? null
        );

        $result = $service->snapshot(7, 80, 'revenue', '2026-08-01', '2026-08-02');

        self::assertSame('partial', $result['status']);
        self::assertNull($result['snapshot']);
        self::assertSame('unverified', $result['quality_status']);
        self::assertSame('not_verified', $result['readback_status']);
        self::assertSame(1, $result['sample_size']);
        self::assertSame(2, $result['expected_sample_size']);
        self::assertSame(['2026-08-02'], $result['unavailable_dates']);
        self::assertSame('2026-08-02', $result['data_gaps'][0]['business_date']);
        self::assertContains('revenue_fact_layer_load_failed', $result['data_gaps'][0]['reason_codes']);
        self::assertSame(['dingdandao_operating_target_captures#121'], $result['evidence_refs']);
    }

    public function testUnsupportedProfitCashFlowAndRatingAreUnavailableWithoutLoadingFacts(): void
    {
        $calls = 0;
        $service = new OperatingGoalMetricSnapshotService(
            static function (int $hotelId, string $date) use (&$calls): array {
                $calls++;
                return [];
            }
        );

        foreach (['profit', 'cash_flow', 'rating'] as $metricKey) {
            $result = $service->snapshot(7, 80, $metricKey, '2026-08-01', '2026-08-02');
            self::assertSame('unavailable', $result['status'], $metricKey);
            self::assertNull($result['snapshot'], $metricKey);
            self::assertSame('unverified', $result['quality_status'], $metricKey);
            self::assertContains('metric_not_supported:' . $metricKey, $result['reason_codes']);
            self::assertNotEmpty($result['data_gaps'], $metricKey);
        }
        self::assertSame(0, $calls);
    }

    public function testTenantAndHotelMismatchesAreRejectedAsUnavailable(): void
    {
        $tenantMismatch = new OperatingGoalMetricSnapshotService(
            fn(int $hotelId, string $date): array => $this->layer($date, tenantId: 8)
        );
        $hotelMismatch = new OperatingGoalMetricSnapshotService(
            fn(int $hotelId, string $date): array => $this->layer($date, hotelId: 81)
        );

        $tenantResult = $tenantMismatch->snapshot(7, 80, 'revenue', '2026-08-01', '2026-08-01');
        $hotelResult = $hotelMismatch->snapshot(7, 80, 'revenue', '2026-08-01', '2026-08-01');

        self::assertSame('unavailable', $tenantResult['status']);
        self::assertNull($tenantResult['snapshot']);
        self::assertContains('fact_layer_tenant_mismatch', $tenantResult['reason_codes']);
        self::assertSame('unavailable', $hotelResult['status']);
        self::assertNull($hotelResult['snapshot']);
        self::assertContains('fact_layer_hotel_mismatch', $hotelResult['reason_codes']);
    }

    public function testExplicitVerifiedZeroSumIsReadyButZeroAdrDenominatorIsUnavailable(): void
    {
        $layer = $this->layer(
            '2026-08-01',
            ctrip: $this->otaValues(0, 0, 0, 0, 0, 221),
            meituan: $this->otaValues(0, 0, 0, 0, 0, 321)
        );
        $service = new OperatingGoalMetricSnapshotService(
            static fn(int $hotelId, string $date): array => $layer
        );

        $orders = $service->snapshot(7, 80, 'ota_orders', '2026-08-01', '2026-08-01');
        $adr = $service->snapshot(7, 80, 'ota_adr', '2026-08-01', '2026-08-01');

        self::assertSame('ready', $orders['status']);
        self::assertSame(0, $orders['snapshot']['value']);
        self::assertSame('unavailable', $adr['status']);
        self::assertNull($adr['snapshot']);
        self::assertContains('metric_denominator_zero', $adr['reason_codes']);
    }

    /** @return array<string,mixed> */
    private function layer(
        string $date,
        array $whole = [],
        array $ctrip = [],
        array $meituan = [],
        int $tenantId = 7,
        int $hotelId = 80
    ): array {
        return [
            'hotel' => [
                'tenant_id' => $tenantId,
                'system_hotel_id' => $hotelId,
            ],
            'business_date' => $date,
            'sources' => [
                'dingdandao_pms' => array_replace_recursive(
                    $this->wholeEnvelope($date, $tenantId, $hotelId),
                    $whole
                ),
                'ctrip_ota' => array_replace_recursive(
                    $this->otaEnvelope($date, 'ctrip', $tenantId, $hotelId, 200),
                    $ctrip
                ),
                'meituan_ota' => array_replace_recursive(
                    $this->otaEnvelope($date, 'meituan', $tenantId, $hotelId, 300),
                    $meituan
                ),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function wholeEnvelope(string $date, int $tenantId, int $hotelId): array
    {
        return [
            'data_status' => 'readback_verified',
            'metric_scope' => 'whole_hotel_accommodation',
            'business_date' => $date,
            'actual_business_date' => $date,
            'facts' => [
                'room_revenue' => 100,
                'sold_room_nights' => 1,
                'sellable_room_nights' => 2,
                'occupancy_rate_percent' => 50,
                'adr' => 100,
                'revpar' => 50,
            ],
            'fact_statuses' => [
                'room_revenue' => ['status' => 'readback_verified'],
                'sold_room_nights' => ['status' => 'readback_verified'],
                'sellable_room_nights' => ['status' => 'derived_verified'],
                'occupancy_rate_percent' => ['status' => 'readback_verified'],
                'adr' => ['status' => 'derived_verified'],
                'revpar' => ['status' => 'derived_verified'],
            ],
            'source' => [
                'table' => 'dingdandao_operating_target_captures',
                'record_id' => 100,
                'tenant_id' => $tenantId,
                'system_hotel_id' => $hotelId,
                'provider_hotel_id' => 'pms-' . $hotelId,
                'data_date' => $date,
                'target_business_date' => $date,
                'captured_at' => $date . ' 23:00:00',
                'readback_status' => 'readback_verified',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function otaEnvelope(
        string $date,
        string $platform,
        int $tenantId,
        int $hotelId,
        int $rowId
    ): array {
        return [
            'data_status' => 'readback_verified',
            'metric_scope' => 'ota_channel',
            'business_date' => $date,
            'actual_business_date' => $date,
            'platform' => $platform,
            'analysis_readiness' => ['allowed' => true, 'status' => 'allowed'],
            'facts' => [
                'revenue' => 100,
                'orders' => 2,
                'room_nights' => 1,
                'adr' => 100,
                'cancellation_rate_percent' => 10,
                'cancellation_gross_order_count' => 10,
            ],
            'fact_statuses' => [
                'revenue' => ['status' => 'readback_verified'],
                'orders' => ['status' => 'readback_verified'],
                'room_nights' => ['status' => 'readback_verified'],
                'adr' => ['status' => 'derived_verified'],
                'cancellation_rate_percent' => ['status' => 'readback_verified'],
                'cancellation_gross_order_count' => ['status' => 'readback_verified'],
            ],
            'source' => [
                'table' => 'online_daily_data',
                'tenant_id' => $tenantId,
                'system_hotel_id' => $hotelId,
                'data_date' => $date,
                'platform' => $platform,
                'row_ids' => [$rowId],
                'readback_status' => 'readback_verified',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function otaValues(
        int|float $revenue,
        int $orders,
        int $roomNights,
        int|float $cancellationRate,
        int $grossOrders,
        int $rowId
    ): array {
        return [
            'facts' => [
                'revenue' => $revenue,
                'orders' => $orders,
                'room_nights' => $roomNights,
                'adr' => $roomNights > 0 ? $revenue / $roomNights : 0,
                'cancellation_rate_percent' => $cancellationRate,
                'cancellation_gross_order_count' => $grossOrders,
            ],
            'source' => ['row_ids' => [$rowId]],
        ];
    }
}
