<?php
declare(strict_types=1);

namespace Tests;

use app\controller\StrategySimulation;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ReflectionHelper;

final class StrategySimulationTruthTest extends TestCase
{
    use ReflectionHelper;

    public function testMissingOptionalEvidenceStaysMissingAndBlocksDecision(): void
    {
        $controller = (new ReflectionClass(StrategySimulation::class))->newInstanceWithoutConstructor();
        $input = $this->invokeNonPublic($controller, 'normalizeInput', [[
            'project_name' => '测试项目',
            'city' => '杭州',
            'property_area' => 2400,
            'room_count' => 80,
            'monthly_rent' => 100000,
            'decoration_budget' => 3200000,
        ]]);

        self::assertNull($input['competitor_count']);
        self::assertNull($input['lease_years']);
        self::assertNull($input['rent_free_months']);
        self::assertSame('', $input['business_type']);
        self::assertSame('', $input['target_customer']);
        self::assertSame('', $input['target_hotel_level']);

        $scores = $this->invokeNonPublic($controller, 'calculateScores', [
            $input,
            [
                'daily_reports' => ['count' => 0],
                'online_daily_data' => ['count' => 0, 'total_quantity' => 0, 'avg_score' => null, 'competitor_hotels' => 0],
                'competitor_analysis' => ['count' => 0, 'competitor_hotels' => 0],
                'data_sources' => [],
                'missing_data' => [],
            ],
            ['used' => false, 'available' => false, 'poi_counts' => []],
        ]);

        self::assertSame('rule_simulation_index', $scores['score_type']);
        self::assertFalse($scores['decision_ready']);
        self::assertContains('竞品数量', $scores['data_gaps']);
        self::assertContains('目标客群', $scores['data_gaps']);
        self::assertContains('目标酒店档次', $scores['data_gaps']);

        $recommendation = $this->invokeNonPublic($controller, 'buildRecommendation', [$input, $scores]);
        $risk = $this->invokeNonPublic($controller, 'buildRisk', [$scores, $recommendation]);
        self::assertStringContainsString('不形成选址或投资结论', $recommendation['decision']);
        self::assertSame('待评估', $recommendation['competition_pressure']);
        self::assertSame('待评估', $risk['risk_level']);
    }

    public function testStrategyUiDoesNotAutoInventCompetitorCount(): void
    {
        $root = dirname(__DIR__);
        $appMain = (string)file_get_contents($root . '/public/app-main.js');
        $template = (string)file_get_contents($root . '/resources/frontend/templates/fragments/01-page-ai-strategy.html');
        $static = (string)file_get_contents($root . '/public/expansion-static-options.js');

        self::assertStringContainsString('未核验时请留空', $template);
        self::assertStringNotContainsString('readonly="" class="w-full', $template);
        self::assertStringContainsString('Never replace a', $appMain);
        self::assertStringContainsString('competitor_count: optionalNumber(project.competitor_count)', $static);
    }

    public function testNormalizeInputBindsOtaEvidenceToTargetHotelAndDate(): void
    {
        $controller = (new ReflectionClass(StrategySimulation::class))->newInstanceWithoutConstructor();
        $input = $this->invokeNonPublic($controller, 'normalizeInput', [[
            'project_name' => '目标门店项目',
            'city' => '杭州',
            'property_area' => 2400,
            'room_count' => 80,
            'monthly_rent' => 100000,
            'decoration_budget' => 3200000,
            'hotel_id' => 80,
            'system_hotel_id' => 80,
            'target_date' => '2026-07-18',
        ]]);

        self::assertSame(80, $input['hotel_id']);
        self::assertSame('2026-07-18', $input['ota_date_start']);
        self::assertSame('2026-07-18', $input['ota_date_end']);
        self::assertSame('target_date', $input['ota_date_window_basis']);
    }

    public function testOnlineSummaryExcludesEveryRowOutsideTrustedTargetScope(): void
    {
        $controller = (new ReflectionClass(StrategySimulation::class))->newInstanceWithoutConstructor();
        $valid = $this->trustedOtaRow(1, 80);
        $rows = [
            $valid,
            array_merge($valid, ['id' => 2, 'system_hotel_id' => 81, 'quantity' => 999]),
            array_merge($valid, ['id' => 3, 'data_date' => '2026-07-17', 'quantity' => 999]),
            array_merge($valid, ['id' => 4, 'source' => '', 'platform' => '', 'quantity' => 999]),
            array_merge($valid, ['id' => 5, 'collected_at' => '', 'snapshot_time' => '', 'raw_data' => [], 'quantity' => 999]),
            array_merge($valid, ['id' => 6, 'readback_verified' => 0, 'quantity' => 999]),
            array_merge($valid, ['id' => 7, 'validation_status' => 'partial', 'quantity' => 999]),
            array_merge($valid, ['id' => 8, 'validation_status' => 'unverified', 'quantity' => 999]),
            array_merge($valid, ['id' => 9, 'validation_status' => 'collection_failed', 'quantity' => 999]),
            array_merge($valid, ['id' => 10, 'ingestion_method' => 'manual_import', 'quantity' => 999]),
            array_merge($valid, ['id' => 11, 'failed_reason' => 'capture_failed', 'quantity' => 999]),
            array_merge($valid, [
                'id' => 12,
                'source_trace_id' => '',
                'data_source_id' => null,
                'sync_task_id' => null,
                'quantity' => 999,
            ]),
            array_merge($valid, ['id' => 13, 'ingestion_method' => 'legacy', 'quantity' => 999]),
            array_merge($valid, ['id' => 14, 'stored' => 0, 'quantity' => 999]),
        ];

        $summary = $this->invokeNonPublic($controller, 'summarizeOnlineData', [
            $rows,
            80,
            '2026-07-18',
            '2026-07-18',
        ]);

        self::assertSame(1, $summary['count']);
        self::assertSame(1, $summary['trusted_sample_count']);
        self::assertSame(14, $summary['queried_count']);
        self::assertSame(13, $summary['excluded_count']);
        self::assertSame(3, $summary['total_quantity']);
        self::assertSame(1, $summary['total_orders']);
        self::assertSame(4.8, $summary['avg_score']);
        self::assertSame(0.12, $summary['avg_conversion']);
        self::assertSame('partial', $summary['truth_context']['status']);
        self::assertSame('ota_channel', $summary['metric_scope']);
        self::assertSame(80, $summary['truth_context']['hotels'][0]['system_hotel_id']);
        self::assertSame(80, $summary['truth_context']['hotels'][0]['id']);
        self::assertSame(['ctrip'], $summary['truth_context']['platforms']);
        self::assertNotEmpty($summary['truth_context']['source_methods']);
        self::assertSame(['start' => '2026-07-18', 'end' => '2026-07-18'], $summary['truth_context']['date_range']);
        self::assertStringContainsString('不代表全酒店经营', $summary['truth_context']['scope_label']);

        $reasonCodes = array_values(array_unique(array_merge(...array_column($summary['excluded_rows'], 'reason_codes'))));
        foreach ([
            'hotel_scope_mismatch',
            'date_scope_mismatch',
            'platform_missing_or_unsupported',
            'collected_at_missing_or_imprecise',
            'readback_not_verified',
            'validation_status_partial',
            'validation_status_unverified',
            'validation_status_collection_failed',
            'ingestion_method_untrusted',
            'row_failure_present',
            'source_provenance_missing',
            'not_stored',
        ] as $reasonCode) {
            self::assertContains($reasonCode, $reasonCodes);
            self::assertContains($reasonCode, $summary['data_gaps']);
            self::assertStringContainsString($reasonCode, $summary['failure_reason']);
        }
    }

    public function testOnlineSummaryWithoutTargetHotelNeverAggregatesAcrossHotels(): void
    {
        $controller = (new ReflectionClass(StrategySimulation::class))->newInstanceWithoutConstructor();
        $summary = $this->invokeNonPublic($controller, 'summarizeOnlineData', [[
            $this->trustedOtaRow(1, 80),
            $this->trustedOtaRow(2, 81),
        ], null, '2026-07-18', '2026-07-18']);

        self::assertSame(0, $summary['count']);
        self::assertSame(0, $summary['trusted_sample_count']);
        self::assertSame(2, $summary['excluded_count']);
        self::assertNull($summary['total_quantity']);
        self::assertNull($summary['total_orders']);
        self::assertNull($summary['avg_score']);
        self::assertSame('unverified', $summary['truth_context']['status']);
        self::assertNull($summary['target_hotel_id']);
        self::assertSame([], $summary['truth_context']['hotels']);
        self::assertSame([], $summary['source_platforms']);
        self::assertContains('target_hotel_missing', $summary['data_gaps']);
    }

    public function testOnlineSummaryPreservesVerifiedZeroAndKeepsMissingMetricsNull(): void
    {
        $controller = (new ReflectionClass(StrategySimulation::class))->newInstanceWithoutConstructor();
        $summary = $this->invokeNonPublic($controller, 'summarizeOnlineData', [[
            $this->trustedOtaRow(1, 80, [
                'quantity' => 0,
                'book_order_num' => null,
                'comment_score' => null,
                'raw_data' => [],
            ]),
        ], 80, '2026-07-18', '2026-07-18']);

        self::assertSame(0, $summary['total_quantity']);
        self::assertNull($summary['total_orders']);
        self::assertNull($summary['avg_score']);
        self::assertNull($summary['avg_conversion']);
        self::assertSame('partial', $summary['truth_context']['status']);
        self::assertContains('ota_orders_missing', $summary['data_gaps']);
        self::assertStringContainsString('ota_orders_missing', $summary['failure_reason']);
    }

    /** @param array<string, mixed> $overrides */
    private function trustedOtaRow(int $id, int $hotelId, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'system_hotel_id' => $hotelId,
            'hotel_id' => 'ctrip-hotel-' . $hotelId,
            'hotel_name' => '测试门店' . $hotelId,
            'data_date' => '2026-07-18',
            'source' => 'ctrip',
            'platform' => 'ctrip',
            'data_type' => 'business',
            'compare_type' => 'self',
            'data_period' => 'historical_daily',
            'is_final' => 1,
            'validation_status' => 'verified',
            'validation_flags' => [],
            'status' => 'success',
            'stored' => 1,
            'readback_verified' => 1,
            'ingestion_method' => 'browser_profile',
            'source_trace_id' => 'trace-' . $id,
            'collected_at' => '2026-07-19 08:00:00',
            'quantity' => 3,
            'book_order_num' => 1,
            'comment_score' => 4.8,
            'qunar_comment_score' => null,
            'raw_data' => ['conversionRate' => 0.12],
            'failed_reason' => '',
            'failure_reason' => '',
            'error_info' => '',
        ], $overrides);
    }
}
