<?php
declare(strict_types=1);

namespace tests;

use app\service\TemporalInsightService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TemporalInsightServiceTest extends TestCase
{
    public function testCoarseForecastProducesDirectionIntervalsAndConfidenceWithoutPriceActions(): void
    {
        $series = [];
        $start = new \DateTimeImmutable('2026-06-17');
        for ($day = 0; $day < 28; $day++) {
            $date = $start->modify("+{$day} days")->format('Y-m-d');
            $series[] = [
                'date' => $date,
                'ota_revenue' => 1000 + $day * 20,
                'ota_orders' => 10 + (int)floor($day / 4),
                'ota_room_nights' => 13 + (int)floor($day / 5),
            ];
        }

        $plan = (new TemporalInsightService())->buildForecastPlan($series, '2026-07-15', 7);

        self::assertSame('ready', $plan['status']);
        self::assertSame('coarse_trend_v1', $plan['model_version']);
        self::assertSame('uncalibrated_rule_index', $plan['confidence_type']);
        self::assertSame('not_calibrated', $plan['calibration_status']);
        self::assertStringContainsString('不代表预测命中概率', $plan['confidence_semantics']);
        self::assertCount(6, $plan['metrics']);
        self::assertCount(21, $plan['points']);
        self::assertSame('2026-07-16', $plan['points'][0]['target_date']);

        foreach ($plan['points'] as $point) {
            self::assertContains($point['metric_key'], ['ota_revenue', 'ota_orders', 'ota_room_nights']);
            self::assertArrayNotHasKey('price', $point);
            self::assertLessThanOrEqual($point['upper_bound'], $point['predicted_value']);
            self::assertGreaterThanOrEqual($point['lower_bound'], $point['predicted_value']);
            self::assertGreaterThanOrEqual(0.2, $point['confidence_score']);
            self::assertLessThanOrEqual(0.9, $point['confidence_score']);
            self::assertSame('uncalibrated_rule_index', $point['confidence_type']);
        }
    }

    public function testMissingMetricsStayUnavailableInsteadOfBecomingZeroForecasts(): void
    {
        $series = [];
        for ($day = 1; $day <= 8; $day++) {
            $series[] = [
                'date' => sprintf('2026-07-%02d', $day),
                'ota_revenue' => 800 + $day * 10,
                'ota_orders' => $day <= 4 ? 5 : null,
                'ota_room_nights' => null,
            ];
        }

        $plan = (new TemporalInsightService())->buildForecastPlan($series, '2026-07-15', 7);
        $metrics = [];
        foreach ($plan['metrics'] as $metric) {
            $metrics[$metric['metric_key']] = $metric;
        }

        self::assertSame('ready', $plan['status']);
        self::assertSame('ready', $metrics['ota_revenue']['status']);
        self::assertSame('insufficient_data', $metrics['ota_orders']['status']);
        self::assertSame('insufficient_data', $metrics['ota_room_nights']['status']);
        self::assertCount(7, $plan['points']);
        self::assertSame(['ota_revenue'], array_values(array_unique(array_column($plan['points'], 'metric_key'))));
    }

    public function testExplicitZeroIsAValidFactAndDoesNotCauseDivisionFallback(): void
    {
        $series = [];
        for ($day = 1; $day <= 7; $day++) {
            $series[] = ['date' => sprintf('2026-07-%02d', $day), 'ota_orders' => 0];
        }

        $plan = (new TemporalInsightService())->buildForecastPlan($series, '2026-07-15', 3);
        $orderPoints = array_values(array_filter(
            $plan['points'],
            static fn(array $point): bool => $point['metric_key'] === 'ota_orders'
        ));

        self::assertCount(3, $orderPoints);
        self::assertSame(0, $orderPoints[0]['predicted_value']);
        self::assertSame(0, $orderPoints[0]['lower_bound']);
        self::assertSame(0, $orderPoints[0]['upper_bound']);
    }

    public function testUntrustedManualOverrideFactIsExcludedFromSeriesAndForecastWithGapEvidence(): void
    {
        $service = new TemporalInsightService();
        $aggregate = new \ReflectionMethod($service, 'aggregateFacts');
        $aggregate->setAccessible(true);

        $trustedFacts = [];
        for ($day = 1; $day <= 7; $day++) {
            $trustedFacts[] = [
                'date_key' => sprintf('2026-07-%02d', $day),
                'platform_key' => 'ctrip',
                'revenue' => 100 + $day,
                'source_trace' => [
                    'row_id' => $day,
                    'saved_success' => true,
                    'failure_reasons' => [],
                ],
            ];
        }
        $untrustedManualOverride = [
            'date_key' => '2026-07-07',
            'platform_key' => 'ctrip',
            'revenue' => 999999,
            'source_trace' => [
                'row_id' => 999,
                'ingestion_method' => 'manual_override',
                'saved_success' => false,
                'failure_reasons' => [
                    'validation_status_unverified',
                    'manual_override_unverified',
                ],
            ],
        ];

        $trustedOnly = $aggregate->invoke($service, $trustedFacts);
        $withUntrusted = $aggregate->invoke($service, [...$trustedFacts, $untrustedManualOverride]);

        self::assertSame($trustedOnly['series'], $withUntrusted['series']);
        self::assertSame(7, $withUntrusted['trusted_fact_count']);
        self::assertSame(1, $withUntrusted['excluded_fact_count']);
        self::assertSame(1, $withUntrusted['trace_failures']);
        self::assertSame(1, $withUntrusted['excluded_fact_reason_counts']['manual_override_unverified']);
        self::assertSame(1, $withUntrusted['excluded_fact_reason_counts']['validation_status_unverified']);
        self::assertNotContains(999, $withUntrusted['source_row_ids']);
        self::assertContains([
            'code' => 'fact_excluded',
            'reason' => 'manual_override_unverified',
            'count' => 1,
        ], $withUntrusted['data_gaps']);

        $trustedPlan = $service->buildForecastPlan($trustedOnly['series'], '2026-07-08', 3);
        $filteredPlan = $service->buildForecastPlan($withUntrusted['series'], '2026-07-08', 3);
        self::assertSame($trustedPlan, $filteredPlan);
        self::assertSame('ready', $filteredPlan['status']);
        self::assertCount(3, $filteredPlan['points']);
    }

    public function testCompetitorAverageTrafficFactIsExcludedFromSeriesAndForecast(): void
    {
        $service = new TemporalInsightService();
        $aggregate = new \ReflectionMethod($service, 'aggregateFacts');
        $aggregate->setAccessible(true);

        $selfTrafficFacts = [];
        for ($day = 1; $day <= 7; $day++) {
            $selfTrafficFacts[] = [
                'date_key' => sprintf('2026-07-%02d', $day),
                'platform_key' => 'ctrip',
                'compare_type' => 'self',
                'list_exposure' => 1000 + $day * 10,
                'source_trace' => [
                    'row_id' => 100 + $day,
                    'saved_success' => true,
                    'failure_reasons' => [],
                ],
            ];
        }
        $competitorAverage = [
            'date_key' => '2026-07-07',
            'platform_key' => 'ctrip',
            'compare_type' => 'competitor_avg',
            'list_exposure' => 999999,
            'source_trace' => [
                'row_id' => 999,
                'saved_success' => true,
                'failure_reasons' => [],
            ],
        ];

        $selfOnly = $aggregate->invoke($service, $selfTrafficFacts);
        $withCompetitorAverage = $aggregate->invoke($service, [...$selfTrafficFacts, $competitorAverage]);

        self::assertSame($selfOnly['series'], $withCompetitorAverage['series']);
        self::assertSame(7, $withCompetitorAverage['trusted_fact_count']);
        self::assertSame(1, $withCompetitorAverage['excluded_fact_count']);
        self::assertSame(0, $withCompetitorAverage['trace_failures']);
        self::assertSame(1, $withCompetitorAverage['excluded_fact_reason_counts']['non_self_compare_type_competitor_avg']);
        self::assertNotContains(999, $withCompetitorAverage['source_row_ids']);
        self::assertContains([
            'code' => 'fact_excluded',
            'reason' => 'non_self_compare_type_competitor_avg',
            'count' => 1,
        ], $withCompetitorAverage['data_gaps']);

        $selfPlan = $service->buildForecastPlan($selfOnly['series'], '2026-07-08', 3);
        $filteredPlan = $service->buildForecastPlan($withCompetitorAverage['series'], '2026-07-08', 3);
        self::assertSame($selfPlan, $filteredPlan);
        self::assertSame('ready', $filteredPlan['status']);
        self::assertSame(
            ['ota_list_exposure'],
            array_values(array_unique(array_column($filteredPlan['points'], 'metric_key')))
        );
    }

    public function testInvalidAsOfDateIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new TemporalInsightService())->buildForecastPlan([], '2026-02-31', 7);
    }

    public function testForecastMigrationIsImmutableVersionedAndRegistered(): void
    {
        $root = dirname(__DIR__);
        $migration = (string)file_get_contents(
            $root . '/database/migrations/20260715_create_temporal_forecast_snapshots.sql'
        );
        $init = (string)file_get_contents($root . '/database/init_full.sql');

        self::assertStringContainsString('`forecast_run_id`', $migration);
        self::assertStringContainsString('`as_of_time`', $migration);
        self::assertStringContainsString('`target_date`', $migration);
        self::assertStringContainsString('`lower_bound`', $migration);
        self::assertStringContainsString('`upper_bound`', $migration);
        self::assertStringContainsString('`confidence_score`', $migration);
        self::assertStringContainsString('UNIQUE KEY `uniq_temporal_forecast_point`', $migration);
        self::assertStringNotContainsString('execution_price', $migration);
        self::assertStringContainsString(
            'SOURCE ./database/migrations/20260715_create_temporal_forecast_snapshots.sql;',
            $init
        );
    }

    public function testAuthenticatedTemporalRoutesExposeReadAndExplicitVersionGenerationOnly(): void
    {
        $routes = (string)file_get_contents(dirname(__DIR__) . '/route/app.php');

        self::assertStringContainsString("Route::group('api/temporal-insights'", $routes);
        self::assertStringContainsString("Route::get('/overview', 'TemporalInsight/overview')", $routes);
        self::assertStringContainsString("Route::post('/forecasts', 'TemporalInsight/generateForecast')", $routes);
        self::assertStringNotContainsString('TemporalInsight/executePrice', $routes);
        self::assertStringNotContainsString('TemporalInsight/writeOta', $routes);
    }

    public function testForecastReadbackRequiresFullTenantScopedBusinessRow(): void
    {
        $service = new TemporalInsightService();
        $method = new \ReflectionMethod($service, 'forecastReadbackMatches');
        $method->setAccessible(true);
        $expected = [[
            'tenant_id' => 9,
            'system_hotel_id' => 7,
            'forecast_run_id' => 'run-1',
            'metric_key' => 'ota_revenue',
            'target_date' => '2026-07-16',
            'horizon_days' => 1,
            'predicted_value' => 1234.5,
            'confidence_score' => 0.72,
            'source_refs_json' => '{"source":"online_daily_data","rows":12}',
        ]];
        $stored = [array_merge($expected[0], ['id' => 99])];

        self::assertTrue($method->invoke($service, $expected, $stored));

        $wrongValue = $stored;
        $wrongValue[0]['predicted_value'] = 999.0;
        self::assertFalse($method->invoke($service, $expected, $wrongValue));

        $wrongTenant = $stored;
        $wrongTenant[0]['tenant_id'] = 0;
        self::assertFalse($method->invoke($service, $expected, $wrongTenant));

        $wrongProvenance = $stored;
        $wrongProvenance[0]['source_refs_json'] = '{"source":"unknown","rows":12}';
        self::assertFalse($method->invoke($service, $expected, $wrongProvenance));

        $source = (string)file_get_contents(dirname(__DIR__) . '/app/service/TemporalInsightService.php');
        self::assertStringContainsString('tenant_id=0 is not permitted', $source);
        self::assertStringContainsString('Db::transaction(function', $source);
    }

    public function testHomeTemporalAxisRendersFactsSnapshotsForecastsAndReview(): void
    {
        $root = dirname(__DIR__);
        $template = (string)file_get_contents(
            $root . '/resources/frontend/templates/fragments/23a-page-compass-summary.html'
        );
        $entry = (string)file_get_contents($root . '/public/app-main.js');

        self::assertStringContainsString('data-testid="home-temporal-axis"', $template);
        self::assertStringContainsString('过去有据', $template);
        self::assertStringContainsString('如今可察', $template);
        self::assertStringContainsString('未来可观', $template);
        self::assertStringContainsString('homeTemporalReview.title', $template);
        self::assertStringContainsString("request(`/temporal-insights/overview?", $entry);
        self::assertStringContainsString("request('/temporal-insights/forecasts'", $entry);
        self::assertStringContainsString('不生成执行价格', $entry);
    }
}
