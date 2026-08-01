<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Base;
use app\controller\OtaStandard;
use app\service\OtaRevenueMetricService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

final class Tc212OtaChannelScopeL8Test extends TestCase
{
    #[DataProvider('l8VariantProvider')]
    public function testTc212KeepsRevenueMetricsInsideTheOtaChannelBoundary(
        string $caseId,
        string $actorScope,
        string $dataCompleteness,
        string $freshness,
        string $upstreamState
    ): void {
        $this->assertControllerScopeEvidence($actorScope, $caseId);

        $dataset = $this->dataset($dataCompleteness, $freshness, $upstreamState);
        $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);
        $gate = $metrics['credibility_gate'];
        $closure = $metrics['p1_revenue_closure'];

        self::assertSame('ota_channel', $gate['metric_scope'], $caseId);
        self::assertSame('ota_channel', $closure['scope'], $caseId);
        self::assertStringContainsString('OTA-channel', $closure['scope_statement'], $caseId);
        self::assertSame('OTA ADR', $closure['sections']['adr_conversion']['metrics']['adr']['label'], $caseId);
        self::assertFalse($closure['whole_hotel_guard']['allowed'], $caseId);
        self::assertSame('whole_hotel_scope_not_proved', $closure['whole_hotel_guard']['reason'], $caseId);
        self::assertContains('whole_hotel_scope_not_proved', $gate['warnings'], $caseId);

        if ($dataCompleteness === 'complete') {
            self::assertSame(6.0, $metrics['totals']['room_nights'], $caseId);
            self::assertSame(200.0, $metrics['totals']['adr'], $caseId);
            self::assertNotContains(
                'source_rows_missing',
                $metrics['metric_trust']['totals.room_nights']['failure_reasons'],
                $caseId
            );
            self::assertNotContains(
                'adr_denominator_zero',
                $metrics['metric_trust']['totals.adr']['failure_reasons'],
                $caseId
            );
        } else {
            self::assertNull($metrics['totals']['room_nights'], $caseId);
            self::assertNull($metrics['totals']['adr'], $caseId);
            self::assertContains(
                'critical_metric_untrusted:totals.room_nights',
                $gate['evidence']['failed_critical_metrics'],
                $caseId
            );
            self::assertContains(
                'critical_metric_untrusted:totals.adr',
                $gate['evidence']['failed_critical_metrics'],
                $caseId
            );
            self::assertContains(
                'required_field_missing:room_nights',
                $gate['evidence']['collection_quality']['quality_flags'],
                $caseId
            );
        }

        $expectedQualityState = $freshness === 'fresh' ? 'available' : 'stale';
        self::assertSame(
            $expectedQualityState,
            $gate['evidence']['collection_quality']['primary_quality_state'],
            $caseId
        );
        if ($freshness === 'stale') {
            self::assertContains('ota_collection_quality:stale', $gate['reason_codes'], $caseId);
            self::assertSame('2026-07-01 10:00:00', $metrics['metric_trust']['totals.revenue']['updated_at'], $caseId);
        } else {
            self::assertNotContains('ota_collection_quality:stale', $gate['reason_codes'], $caseId);
            self::assertSame('2026-07-15 10:00:00', $metrics['metric_trust']['totals.revenue']['updated_at'], $caseId);
        }

        if ($upstreamState === 'success') {
            self::assertSame('ready', $gate['evidence']['dataset_status'], $caseId);
            self::assertNotContains('ota_dataset_failed', $gate['reason_codes'], $caseId);
            self::assertNotContains(
                'upstream_collection_failed',
                $metrics['metric_trust']['totals.revenue']['failure_reasons'],
                $caseId
            );
        } else {
            self::assertSame('failed', $gate['evidence']['dataset_status'], $caseId);
            self::assertContains('ota_dataset_failed', $gate['reason_codes'], $caseId);
            self::assertFalse($metrics['metric_trust']['totals.revenue']['saved_success'], $caseId);
            self::assertContains(
                'upstream_collection_failed',
                $metrics['metric_trust']['totals.revenue']['failure_reasons'],
                $caseId
            );
        }

        $decisionAllowed = $dataCompleteness === 'complete'
            && $freshness === 'fresh'
            && $upstreamState === 'success';
        self::assertSame($decisionAllowed, $closure['calculation_allowed'], $caseId);
        self::assertSame($decisionAllowed, $gate['decision_use']['revenue_analysis']['allowed'], $caseId);

        if ($decisionAllowed) {
            self::assertSame('ready', $closure['status'], $caseId);
            self::assertSame(200.0, $closure['sections']['adr_conversion']['metrics']['adr']['value'], $caseId);
        } else {
            self::assertSame('blocked', $closure['status'], $caseId);
            self::assertNull($closure['sections']['adr_conversion']['metrics']['adr']['value'], $caseId);
        }
    }

    /**
     * @return array<string, array{0:string,1:string,2:string,3:string,4:string}>
     */
    public static function l8VariantProvider(): array
    {
        return [
            'DX-1689' => ['DX-1689', 'authorized', 'complete', 'fresh', 'success'],
            'DX-1690' => ['DX-1690', 'authorized', 'complete', 'stale', 'failure'],
            'DX-1691' => ['DX-1691', 'authorized', 'missing_required', 'fresh', 'failure'],
            'DX-1692' => ['DX-1692', 'authorized', 'missing_required', 'stale', 'success'],
            'DX-1693' => ['DX-1693', 'restricted', 'complete', 'fresh', 'failure'],
            'DX-1694' => ['DX-1694', 'restricted', 'complete', 'stale', 'success'],
            'DX-1695' => ['DX-1695', 'restricted', 'missing_required', 'fresh', 'success'],
            'DX-1696' => ['DX-1696', 'restricted', 'missing_required', 'stale', 'failure'],
        ];
    }

    private function assertControllerScopeEvidence(string $actorScope, string $caseId): void
    {
        $controller = $this->otaStandardController($actorScope === 'authorized' ? [7] : [8]);
        $method = new ReflectionMethod(OtaStandard::class, 'authorizeHotelFilters');
        $method->setAccessible(true);

        if ($actorScope === 'authorized') {
            /** @var array<string, mixed> $filters */
            $filters = $method->invoke($controller, ['system_hotel_id' => 7]);
            self::assertSame(7, $filters['system_hotel_id'], $caseId);
            self::assertSame([7], $filters['permitted_hotel_ids'], $caseId);
            return;
        }

        // Controller-guard evidence only: this is deliberately not presented as an HTTP response assertion.
        try {
            $method->invoke($controller, ['system_hotel_id' => 7]);
            self::fail($caseId . ': expected the controller hotel-scope guard to reject the actor.');
        } catch (RuntimeException $e) {
            self::assertSame(403, $e->getCode(), $caseId . ': controller guard exception code');
            self::assertSame('system_hotel_id is outside permitted scope', $e->getMessage(), $caseId);
        }
    }

    /** @param array<int, int> $permittedHotelIds */
    private function otaStandardController(array $permittedHotelIds): OtaStandard
    {
        $reflection = new ReflectionClass(OtaStandard::class);
        /** @var OtaStandard $controller */
        $controller = $reflection->newInstanceWithoutConstructor();
        $currentUser = new ReflectionProperty(Base::class, 'currentUser');
        $currentUser->setAccessible(true);
        $currentUser->setValue($controller, new class($permittedHotelIds) {
            /** @param array<int, int> $permittedHotelIds */
            public function __construct(private readonly array $permittedHotelIds)
            {
            }

            public function isSuperAdmin(): bool
            {
                return false;
            }

            /** @return array<int, int> */
            public function getPermittedHotelIds(): array
            {
                return $this->permittedHotelIds;
            }
        });

        return $controller;
    }

    /** @return array<string, mixed> */
    private function dataset(string $dataCompleteness, string $freshness, string $upstreamState): array
    {
        $date = $freshness === 'fresh' ? '2026-07-15' : '2026-07-01';
        $saved = $upstreamState === 'success';
        $failureReasons = $saved ? [] : ['upstream_collection_failed'];
        $daily = [
            'platform_key' => 'ctrip',
            'hotel_key' => 'system:7',
            'data_type' => 'business',
            'revenue' => 1200.0,
            'gross_revenue' => 1200.0,
            'room_revenue' => 1200.0,
            'net_revenue' => 1020.0,
            'commission_amount' => 180.0,
            'available_room_nights' => 10.0,
            'occupied_room_nights' => 6.0,
            'order_count' => 4,
            'cancel_order_num' => 0,
            'cancel_room_nights' => 0,
            'lead_time_days' => 2,
            'our_price' => 200.0,
            'competitor_price' => 210.0,
            'price_gap' => -10.0,
            'price_gap_rate' => -4.76,
            'source_trace' => $this->trace(21201, 'business', $date, $saved, $failureReasons),
        ];
        if ($dataCompleteness === 'complete') {
            $daily['room_nights'] = 6.0;
        }

        $qualityFlags = $dataCompleteness === 'complete'
            ? []
            : ['required_field_missing:room_nights'];

        return [
            'status' => $upstreamState === 'success' ? 'ready' : 'failed',
            'collection_quality' => [
                'primary_quality_state' => $freshness === 'fresh' ? 'available' : 'stale',
                'quality_flags' => $qualityFlags,
                'metric_scope' => 'ota_channel',
                'target_date' => '2026-07-15',
                'data_as_of' => $date,
            ],
            'data_quality' => [
                'input_rows' => 2,
                'accepted_rows' => 2,
                'rejected_rows' => [],
            ],
            'fact_ota_daily' => [$daily],
            'fact_ota_traffic' => [[
                'platform_key' => 'ctrip',
                'hotel_key' => 'system:7',
                'resource' => 'traffic',
                'flow_rate' => 20.0,
                'submit_rate' => 33.33,
                'source_trace' => $this->trace(21202, 'traffic', $date, $saved, $failureReasons),
            ]],
            'fact_ota_advertising' => [],
            'fact_ota_quality' => [],
            'fact_ota_search_keyword' => [],
            'fact_ota_peer_rank' => [],
            'fact_ota_traffic_analysis' => [],
            'fact_ota_traffic_forecast' => [],
            'fact_ota_comment' => [],
        ];
    }

    /**
     * @param array<int, string> $failureReasons
     * @return array<string, mixed>
     */
    private function trace(
        int $rowId,
        string $dataType,
        string $date,
        bool $saved,
        array $failureReasons
    ): array {
        return [
            'table' => 'online_daily_data',
            'row_id' => $rowId,
            'hotel_key' => 'system:7',
            'platform' => 'ctrip',
            'data_type' => $dataType,
            'date_key' => $date,
            'updated_at' => $date . ' 10:00:00',
            'saved_success' => $saved,
            'failure_reasons' => $failureReasons,
        ];
    }
}
