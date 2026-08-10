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

    public function testLegacyFutureTargetSnapshotsCannotBecomeOperatingFacts(): void
    {
        $service = new TemporalInsightService();
        $method = new \ReflectionMethod($service, 'selectOperatingFactRows');
        $method->setAccessible(true);

        $selection = $method->invoke($service, [[
            'id' => 1,
            'data_type' => 'traffic',
            'endpoint_id' => '',
            'dimension' => '',
            'raw_data' => [
                'endpoint_id' => 'traffic_search_details',
                'dimension_values' => ['target_date' => '2026-08-01'],
                'metrics' => ['future_search_uv' => 80],
            ],
        ], [
            'id' => 2,
            'data_type' => 'traffic',
            'endpoint_id' => 'traffic_overview',
            'dimension' => 'traffic:self',
            'raw_data' => ['metrics' => ['list_exposure' => 120]],
        ]]);

        self::assertSame(1, $selection['future_target_rows_excluded']);
        self::assertSame([2], array_column($selection['rows'], 'id'));
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

    public function testSevenDayComparisonRequiresFourteenNonOverlappingValidDays(): void
    {
        $service = new TemporalInsightService();
        $trendSummary = new \ReflectionMethod($service, 'trendSummary');
        $trendSummary->setAccessible(true);
        $series = [];
        for ($day = 1; $day <= 12; $day++) {
            $series[] = [
                'date' => sprintf('2026-07-%02d', $day),
                'ota_revenue' => $day * 100,
            ];
        }

        $short = $trendSummary->invoke($service, $series);
        self::assertSame(900.0, $short['ota_revenue']['recent_7_day_average']);
        self::assertNull($short['ota_revenue']['previous_7_day_average']);
        self::assertNull($short['ota_revenue']['change_percent']);

        $series[] = ['date' => '2026-07-13', 'ota_revenue' => 1300];
        $series[] = ['date' => '2026-07-14', 'ota_revenue' => 1400];
        $complete = $trendSummary->invoke($service, $series);
        self::assertSame(400.0, $complete['ota_revenue']['previous_7_day_average']);
        self::assertSame(1100.0, $complete['ota_revenue']['recent_7_day_average']);
        self::assertSame(175.0, $complete['ota_revenue']['change_percent']);
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

    public function testMetricQualityDoesNotLetUnrelatedTrafficFailureBlockRevenue(): void
    {
        $service = new TemporalInsightService();
        $aggregate = new \ReflectionMethod($service, 'aggregateFacts');
        $aggregate->setAccessible(true);
        $facts = [];
        for ($day = 1; $day <= 7; $day++) {
            $date = sprintf('2026-07-%02d', $day);
            $facts[] = [
                'date_key' => $date,
                'platform_key' => 'ctrip',
                'compare_type' => 'self',
                'revenue' => 100 + $day,
                'source_trace' => [
                    'row_id' => $day,
                    'saved_success' => true,
                    'failure_reasons' => [],
                ],
            ];
            $facts[] = [
                'date_key' => $date,
                'platform_key' => 'meituan',
                'compare_type' => 'self',
                'revenue' => 80 + $day,
                'source_trace' => [
                    'row_id' => 100 + $day,
                    'saved_success' => true,
                    'failure_reasons' => [],
                ],
            ];
        }
        $facts[] = [
            'date_key' => '2026-07-07',
            'platform_key' => 'ctrip',
            'compare_type' => 'self',
            'list_exposure' => 999,
            'source_trace' => [
                'row_id' => 999,
                'saved_success' => false,
                'failure_reasons' => ['readback_unverified'],
            ],
        ];

        $result = $aggregate->invoke($service, $facts, '2026-07-07');
        self::assertSame('ready', $result['metric_quality']['ota_revenue']['quality_status']);
        self::assertSame(0, $result['metric_quality']['ota_revenue']['excluded_fact_rows']);
        self::assertSame(7, $result['metric_quality']['ota_revenue']['trusted_days']);
        self::assertSame('insufficient', $result['metric_quality']['ota_list_exposure']['quality_status']);
        self::assertSame(1, $result['metric_quality']['ota_list_exposure']['excluded_fact_rows']);
        self::assertSame(
            1,
            $result['metric_quality']['ota_list_exposure']['excluded_fact_reason_counts']['readback_unverified']
        );
    }

    public function testMetricQualityFlagsMixedPlatformCoverageInsteadOfCallingItAllOtaReady(): void
    {
        $service = new TemporalInsightService();
        $aggregate = new \ReflectionMethod($service, 'aggregateFacts');
        $aggregate->setAccessible(true);
        $facts = [
            [
                'date_key' => '2026-07-01',
                'platform_key' => 'ctrip',
                'compare_type' => 'self',
                'revenue' => 100,
                'source_trace' => ['row_id' => 1, 'saved_success' => true, 'failure_reasons' => []],
            ],
            [
                'date_key' => '2026-07-01',
                'platform_key' => 'meituan',
                'compare_type' => 'self',
                'revenue' => 80,
                'source_trace' => ['row_id' => 2, 'saved_success' => true, 'failure_reasons' => []],
            ],
            [
                'date_key' => '2026-07-02',
                'platform_key' => 'ctrip',
                'compare_type' => 'self',
                'revenue' => 110,
                'source_trace' => ['row_id' => 3, 'saved_success' => true, 'failure_reasons' => []],
            ],
        ];

        $result = $aggregate->invoke($service, $facts, '2026-07-02');
        $quality = $result['metric_quality']['ota_revenue'];
        self::assertSame(['ctrip', 'meituan'], $quality['expected_platforms']);
        self::assertSame('partial', $quality['platform_coverage_status']);
        self::assertSame(['2026-07-02'], $quality['incomplete_platform_dates']);
        self::assertSame(1, $quality['coverage_complete_days']);
        self::assertSame('partial', $quality['quality_status']);
        self::assertSame(180.0, $result['series'][0]['ota_revenue']);
        self::assertSame(110.0, $result['series'][1]['ota_revenue']);
    }

    public function testForecastActualReadbackIsObservedEvidenceWithoutCausalityClaim(): void
    {
        $service = new TemporalInsightService();
        $method = new \ReflectionMethod($service, 'buildForecastActualReadback');
        $method->setAccessible(true);
        $forecast = [
            'id' => 77,
            'forecast_run_id' => 'run-77',
            'system_hotel_id' => 7,
            'metric_key' => 'ota_revenue',
            'target_date' => '2026-07-20',
            'predicted_value' => 100,
            'lower_bound' => 90,
            'upper_bound' => 110,
        ];
        $bundle = [
            'series' => [[
                'date' => '2026-07-20',
                'ota_revenue' => 105,
            ]],
            'metric_quality' => [
                'ota_revenue' => [
                    'quality_status' => 'ready',
                    'platform_coverage_status' => 'ready',
                    'freshness_status' => 'current',
                    'trusted_fact_rows' => 2,
                    'row_ids' => [11, 12],
                    'latest_readback_at' => '2026-07-21 08:05:00',
                ],
            ],
        ];

        $result = $method->invoke($service, $forecast, $bundle);
        self::assertSame('ready', $result['status']);
        self::assertSame(105.0, $result['actual_value']);
        self::assertTrue($result['within_range']);
        self::assertSame(5.0, $result['absolute_error']);
        self::assertSame([11, 12], $result['source_row_ids']);
        self::assertFalse($result['causality_claimed']);
        self::assertSame('observed_not_attributed', $result['effect_evidence_status']);

        $bundle['metric_quality']['ota_revenue']['platform_coverage_status'] = 'partial';
        $blocked = $method->invoke($service, $forecast, $bundle);
        self::assertSame('unavailable', $blocked['status']);
        self::assertSame('trusted_actual_quality_incomplete', $blocked['reason_code']);
    }

    public function testAggregateSixPointSevenPercentDoesNotEnableAnyMetricHorizonConclusion(): void
    {
        $forecasts = [];
        $actualsByDate = [];
        $metrics = ['ota_revenue', 'ota_orders', 'ota_room_nights'];
        $pointId = 1;
        foreach ($metrics as $metricIndex => $metricKey) {
            for ($sample = 0; $sample < 5; $sample++) {
                $target = (new \DateTimeImmutable('2026-06-01'))
                    ->modify('+' . ($metricIndex * 5 + $sample) . ' days')
                    ->format('Y-m-d');
                $horizon = $metricIndex + 1;
                $asOfDate = (new \DateTimeImmutable($target))
                    ->modify("-{$horizon} days")
                    ->format('Y-m-d');
                $sourceEnd = (new \DateTimeImmutable($asOfDate))->modify('-1 day')->format('Y-m-d');
                $sourceStart = (new \DateTimeImmutable($sourceEnd))->modify('-27 days')->format('Y-m-d');
                $actualsByDate[$target] ??= ['date' => $target];
                $actualsByDate[$target][$metricKey] = 100;
                $singleHit = $metricIndex === 0 && $sample === 0;
                $forecasts[] = [
                    'id' => $pointId++,
                    'forecast_run_id' => 'run-' . $metricIndex . '-' . $sample,
                    'as_of_date' => $asOfDate,
                    'as_of_time' => $asOfDate . ' 08:00:00',
                    'target_date' => $target,
                    'metric_key' => $metricKey,
                    'horizon_days' => $horizon,
                    'predicted_value' => $singleHit ? 100 : 200,
                    'lower_bound' => $singleHit ? 90 : 180,
                    'upper_bound' => $singleHit ? 110 : 220,
                    'sample_days' => 21,
                    'data_quality_status' => 'ready',
                    'source_start_date' => $sourceStart,
                    'source_end_date' => $sourceEnd,
                    'source_refs_json' => $this->verifiedMetricSourceRefs(
                        $metricKey,
                        [],
                        0,
                        0,
                        ['ctrip', 'meituan'],
                        $sourceEnd
                    ),
                ];
            }
        }

        $review = (new TemporalInsightService())->buildBacktestSummary(
            $forecasts,
            array_values($actualsByDate),
            $this->verifiedActualMetricQuality(array_values($actualsByDate))
        );

        self::assertSame(15, $review['matched_points']);
        self::assertSame(6.7, $review['range_hit_rate']);
        self::assertSame('disabled', $review['conclusion_status']);
        self::assertSame('disabled', $review['operational_use']);
        self::assertSame(0, $review['eligible_cohort_count']);
        self::assertCount(3, $review['cohorts']);
        foreach ($review['cohorts'] as $cohort) {
            self::assertSame(5, $cohort['matched_points']);
            self::assertSame('insufficient', $cohort['sample_status']);
            self::assertSame('disabled_insufficient_samples', $cohort['decision_status']);
            self::assertFalse($cohort['conclusion_enabled']);
            self::assertFalse($cohort['automatic_price_write']);
        }
    }

    public function testBacktestSeparatesMetricAndHorizonAndDeduplicatesSameDayRegeneration(): void
    {
        $forecasts = [];
        $actuals = [];
        for ($sample = 0; $sample < 10; $sample++) {
            $target = (new \DateTimeImmutable('2026-06-01'))->modify("+{$sample} days")->format('Y-m-d');
            $actuals[] = ['date' => $target, 'ota_revenue' => 100];
            foreach ([1, 7] as $horizon) {
                $asOfDate = (new \DateTimeImmutable($target))
                    ->modify("-{$horizon} days")
                    ->format('Y-m-d');
                $sourceEnd = (new \DateTimeImmutable($asOfDate))->modify('-1 day')->format('Y-m-d');
                $sourceStart = (new \DateTimeImmutable($sourceEnd))->modify('-27 days')->format('Y-m-d');
                $forecasts[] = [
                    'id' => count($forecasts) + 1,
                    'forecast_run_id' => "run-{$horizon}-{$sample}",
                    'as_of_date' => $asOfDate,
                    'as_of_time' => $asOfDate . ' 08:00:00',
                    'target_date' => $target,
                    'metric_key' => 'ota_revenue',
                    'horizon_days' => $horizon,
                    'predicted_value' => $horizon === 1 ? 100 : 200,
                    'lower_bound' => $horizon === 1 ? 90 : 180,
                    'upper_bound' => $horizon === 1 ? 110 : 220,
                    'sample_days' => 21,
                    'data_quality_status' => 'ready',
                    'source_start_date' => $sourceStart,
                    'source_end_date' => $sourceEnd,
                    'source_refs_json' => $this->verifiedMetricSourceRefs(
                        'ota_revenue',
                        [],
                        0,
                        0,
                        ['ctrip', 'meituan'],
                        $sourceEnd
                    ),
                ];
            }
        }
        $duplicate = $forecasts[0];
        $duplicate['id'] = 999;
        $duplicate['forecast_run_id'] = 'same-day-regeneration';
        $duplicate['as_of_time'] = $duplicate['as_of_date'] . ' 09:00:00';
        $forecasts[] = $duplicate;
        $hindsightDuplicate = $forecasts[0];
        $hindsightDuplicate['id'] = 1000;
        $hindsightDuplicate['forecast_run_id'] = 'hindsight-regeneration';
        $hindsightDuplicate['as_of_time'] = $hindsightDuplicate['target_date'] . ' 09:00:00';
        $hindsightDuplicate['predicted_value'] = 999;
        $hindsightDuplicate['lower_bound'] = 998;
        $hindsightDuplicate['upper_bound'] = 1000;
        $forecasts[] = $hindsightDuplicate;

        $review = (new TemporalInsightService())->buildBacktestSummary(
            $forecasts,
            $actuals,
            $this->verifiedActualMetricQuality($actuals)
        );
        $cohorts = [];
        foreach ($review['cohorts'] as $cohort) {
            $cohorts[$cohort['horizon_days']] = $cohort;
        }

        self::assertSame(20, $review['deduplicated_forecast_points']);
        self::assertSame('partial', $review['conclusion_status']);
        self::assertSame('eligible_for_human_review', $cohorts[1]['decision_status']);
        self::assertSame(100.0, $cohorts[1]['range_hit_rate']);
        self::assertSame('disabled_low_interval_coverage', $cohorts[7]['decision_status']);
        self::assertSame(0.0, $cohorts[7]['range_hit_rate']);
    }

    public function testBacktestKeepsUnverifiedLegacyForecastsDiagnosticButExcludesThemFromOperationalSamples(): void
    {
        $review = (new TemporalInsightService())->buildBacktestSummary([[
            'id' => 1,
            'forecast_run_id' => 'partial-source-run',
            'as_of_date' => '2026-06-01',
            'as_of_time' => '2026-06-01 08:00:00',
            'target_date' => '2026-06-02',
            'metric_key' => 'ota_revenue',
            'horizon_days' => 1,
            'predicted_value' => 100,
            'lower_bound' => 90,
            'upper_bound' => 110,
            'sample_days' => 28,
            'data_quality_status' => 'ready',
        ]], [[
            'date' => '2026-06-02',
            'ota_revenue' => 100,
        ]]);

        self::assertSame(1, $review['matched_points']);
        self::assertSame(100.0, $review['range_hit_rate']);
        self::assertSame('disabled', $review['conclusion_status']);
        self::assertSame(1, $review['cohorts'][0]['diagnostic_matched_points']);
        self::assertSame(100.0, $review['cohorts'][0]['diagnostic_range_hit_rate']);
        self::assertSame(0, $review['cohorts'][0]['matched_points']);
        self::assertNull($review['cohorts'][0]['range_hit_rate']);
        self::assertSame(1, $review['cohorts'][0]['source_quality_excluded_points']);
        self::assertSame('disabled_insufficient_samples', $review['cohorts'][0]['decision_status']);
    }

    public function testSinglePlatformActualRemainsDiagnosticAndCannotCalibrateAllOtaBacktest(): void
    {
        $forecast = [
            'id' => 1,
            'forecast_run_id' => 'all-ota-ready-source',
            'as_of_date' => '2026-06-01',
            'as_of_time' => '2026-06-01 08:00:00',
            'target_date' => '2026-06-02',
            'metric_key' => 'ota_revenue',
            'horizon_days' => 1,
            'predicted_value' => 100,
            'lower_bound' => 90,
            'upper_bound' => 110,
            'sample_days' => 21,
            'data_quality_status' => 'ready',
            'source_start_date' => '2026-05-04',
            'source_end_date' => '2026-05-31',
            'source_refs_json' => $this->verifiedMetricSourceRefs(
                'ota_revenue',
                [],
                0,
                0,
                ['ctrip', 'meituan'],
                '2026-05-31'
            ),
        ];
        $actual = [['date' => '2026-06-02', 'ota_revenue' => 100]];
        $actualQuality = $this->verifiedActualMetricQuality($actual);
        $actualQuality['ota_revenue']['platforms_by_date']['2026-06-02'] = ['ctrip'];

        $review = (new TemporalInsightService())->buildBacktestSummary(
            [$forecast],
            $actual,
            $actualQuality
        );

        self::assertSame(1, $review['matched_points']);
        self::assertSame(100.0, $review['range_hit_rate']);
        self::assertSame(0, $review['cohorts'][0]['matched_points']);
        self::assertSame(0, $review['cohorts'][0]['source_quality_excluded_points']);
        self::assertSame(1, $review['cohorts'][0]['actual_quality_excluded_points']);
        self::assertTrue($review['items'][0]['forecast_source_operationally_verified']);
        self::assertFalse($review['items'][0]['actual_operationally_verified']);
        self::assertFalse($review['items'][0]['operational_sample_eligible']);
    }

    public function testTenHistoryDaysKeepOperationalConclusionDisabledEvenWithBacktestSamples(): void
    {
        $service = new TemporalInsightService();
        $gateMethod = new \ReflectionMethod($service, 'forecastOperationalGate');
        $gateMethod->setAccessible(true);

        $gate = $gateMethod->invoke($service, [
            'metric_key' => 'ota_revenue',
            'horizon_days' => 1,
            'sample_days' => 10,
            'data_quality_status' => 'ready',
        ], [
            'cohorts' => [[
                'metric_key' => 'ota_revenue',
                'horizon_days' => 1,
                'matched_points' => 15,
                'range_hit_rate' => 80.0,
            ]],
        ]);

        self::assertSame('disabled_insufficient_evidence', $gate['status']);
        self::assertFalse($gate['can_submit_for_review']);
        self::assertContains('history_sample_lt_21', $gate['reason_codes']);
        self::assertSame(['ctrip', 'meituan'], $gate['policy']['all_ota_required_platforms']);
        self::assertFalse($gate['automatic_price_write']);
    }

    public function testForecastPointCannotBorrowAnEligibleCohortWithoutItsOwnVerifiedSourceIdentity(): void
    {
        $service = new TemporalInsightService();
        $gateMethod = new \ReflectionMethod($service, 'forecastOperationalGate');
        $gateMethod->setAccessible(true);

        $gate = $gateMethod->invoke($service, [
            'metric_key' => 'ota_revenue',
            'horizon_days' => 1,
            'sample_days' => 21,
            'data_quality_status' => 'ready',
        ], [
            'cohorts' => [[
                'metric_key' => 'ota_revenue',
                'horizon_days' => 1,
                'matched_points' => 10,
                'range_hit_rate' => 100.0,
            ]],
        ]);

        self::assertFalse($gate['can_submit_for_review']);
        self::assertContains('forecast_source_identity_not_verified', $gate['reason_codes']);
    }

    public function testForecastSourceQualityIgnoresOnlyIntentionalCompetitorComparisonExclusions(): void
    {
        $service = new TemporalInsightService();
        $qualityMethod = new \ReflectionMethod($service, 'forecastSourceQualityStatus');
        $qualityMethod->setAccessible(true);

        self::assertSame('ready', $qualityMethod->invoke($service, [
            'trusted_facts' => 21,
            'rejected_rows' => 0,
            'trace_failures' => 0,
            'excluded_fact_reason_counts' => [
                'non_self_compare_type_competitor_avg' => 331,
            ],
        ]));
        self::assertSame('partial', $qualityMethod->invoke($service, [
            'trusted_facts' => 21,
            'rejected_rows' => 0,
            'trace_failures' => 1,
            'excluded_fact_reason_counts' => [
                'non_self_compare_type_competitor_avg' => 331,
                'readback_unverified' => 257,
            ],
        ]));
        self::assertSame('insufficient', $qualityMethod->invoke($service, [
            'trusted_facts' => 0,
            'excluded_fact_reason_counts' => [],
        ]));

        $sourceRefsMethod = new \ReflectionMethod($service, 'forecastSourceRefsOperationallyVerified');
        $sourceRefsMethod->setAccessible(true);
        $forecast = [
            'metric_key' => 'ota_revenue',
            'as_of_date' => '2026-05-29',
            'as_of_time' => '2026-05-29 08:00:00',
            'target_date' => '2026-05-30',
            'horizon_days' => 1,
            'sample_days' => 21,
            'source_start_date' => '2026-05-01',
            'source_end_date' => '2026-05-28',
            'source_refs_json' => $this->verifiedMetricSourceRefs(
                'ota_revenue',
                ['non_self_compare_type_competitor_avg' => 3],
                3
            ),
        ];
        self::assertTrue($sourceRefsMethod->invoke($service, $forecast));

        $partialCoverage = $forecast;
        $partialRefs = json_decode((string)$forecast['source_refs_json'], true, flags: JSON_THROW_ON_ERROR);
        $firstTrustedDate = (string)$partialRefs['trusted_dates'][0];
        $partialRefs['platforms_by_date'][$firstTrustedDate] = ['ctrip'];
        $partialRefs['platform_coverage_status'] = 'partial';
        $partialRefs['coverage_complete_days'] = 20;
        $partialRefs['source_identity_digest'] = hash('sha256', json_encode([
            'metric_key' => $partialRefs['metric_key'],
            'trusted_dates' => $partialRefs['trusted_dates'],
            'row_ids' => $partialRefs['row_ids'],
            'platforms_by_date' => $partialRefs['platforms_by_date'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $partialCoverage['source_refs_json'] = json_encode(
            $partialRefs,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        self::assertFalse($sourceRefsMethod->invoke($service, $partialCoverage));
        self::assertTrue($sourceRefsMethod->invoke($service, $partialCoverage, false));

        $partialCoverage['data_quality_status'] = 'ready';
        $gateMethod = new \ReflectionMethod($service, 'forecastOperationalGate');
        $gateMethod->setAccessible(true);
        $partialGate = $gateMethod->invoke($service, $partialCoverage, [
            'cohorts' => [[
                'metric_key' => 'ota_revenue',
                'horizon_days' => 1,
                'matched_points' => 10,
                'range_hit_rate' => 100.0,
            ]],
        ]);
        self::assertFalse($partialGate['can_submit_for_review']);
        self::assertContains('forecast_source_identity_not_verified', $partialGate['reason_codes']);

        $hindsightGenerated = $forecast;
        $hindsightGenerated['as_of_time'] = '2026-05-30 08:00:00';
        self::assertFalse($sourceRefsMethod->invoke($service, $hindsightGenerated));

        $wrongTargetDate = $forecast;
        $wrongTargetDate['target_date'] = '2026-05-31';
        self::assertFalse($sourceRefsMethod->invoke($service, $wrongTargetDate));

        $unreadback = $forecast;
        $unreadback['source_refs_json'] = $this->verifiedMetricSourceRefs(
            'ota_revenue',
            ['readback_unverified' => 1],
            1,
            1
        );
        self::assertFalse($sourceRefsMethod->invoke($service, $unreadback));

        $wrongMetric = $forecast;
        $wrongMetric['source_refs_json'] = $this->verifiedMetricSourceRefs('ota_orders');
        self::assertFalse($sourceRefsMethod->invoke($service, $wrongMetric));

        $singlePlatform = $forecast;
        $singlePlatform['source_refs_json'] = $this->verifiedMetricSourceRefs(
            'ota_revenue',
            ['non_self_compare_type_competitor_avg' => 3],
            3,
            0,
            ['ctrip']
        );
        self::assertFalse($sourceRefsMethod->invoke($service, $singlePlatform));
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

    public function testAuthenticatedTemporalRoutesExposeForecastAndHumanReviewBridgeWithoutPriceWrite(): void
    {
        $routes = (string)file_get_contents(dirname(__DIR__) . '/route/app.php');

        self::assertStringContainsString("Route::group('api/temporal-insights'", $routes);
        self::assertStringContainsString("Route::get('/overview', 'TemporalInsight/overview')", $routes);
        self::assertStringContainsString("Route::post('/forecasts', 'TemporalInsight/generateForecast')", $routes);
        self::assertStringContainsString(
            "Route::post('/forecasts/:id/execution-intent', 'TemporalInsight/createOperationReviewIntent')",
            $routes
        );
        self::assertStringNotContainsString('TemporalInsight/executePrice', $routes);
        self::assertStringNotContainsString('TemporalInsight/writeOta', $routes);

        $service = (string)file_get_contents(dirname(__DIR__) . '/app/service/TemporalInsightService.php');
        self::assertStringContainsString('operation_task_created_only_after_explicit_intent_approval', $service);
        self::assertStringContainsString("'automatic_price_write' => false", $service);
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

    /**
     * @param array<string, int> $reasonCounts
     */
    private function verifiedMetricSourceRefs(
        string $metricKey,
        array $reasonCounts = [],
        int $excludedFactRows = 0,
        int $traceFailures = 0,
        array $platforms = ['ctrip', 'meituan'],
        string $sourceEnd = '2026-05-28'
    ): string {
        $factKey = match ($metricKey) {
            'ota_revenue' => 'revenue',
            'ota_orders' => 'order_count',
            'ota_room_nights' => 'room_nights',
            'ota_list_exposure' => 'list_exposure',
            'ota_detail_exposure' => 'detail_exposure',
            'ota_order_submit' => 'order_submit_num',
            default => throw new \InvalidArgumentException('unsupported metric fixture'),
        };
        $sourceEndDate = new \DateTimeImmutable($sourceEnd);
        $sourceStart = $sourceEndDate->modify('-27 days')->format('Y-m-d');
        $dates = [];
        $platformsByDate = [];
        $cursor = $sourceEndDate->modify('-20 days');
        for ($index = 0; $index < 21; $index++) {
            $date = $cursor->modify("+{$index} days")->format('Y-m-d');
            $dates[] = $date;
            $platformsByDate[$date] = $platforms;
        }
        $rowIds = range(1, 21 * max(1, count($platforms)));
        $digest = hash('sha256', json_encode([
            'metric_key' => $metricKey,
            'trusted_dates' => $dates,
            'row_ids' => $rowIds,
            'platforms_by_date' => $platformsByDate,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return json_encode([
            'contract_version' => 'temporal_metric_source.v1',
            'table' => 'online_daily_data',
            'metric_scope' => 'ota_channel',
            'period' => 'historical_daily',
            'is_final' => 1,
            'metric_key' => $metricKey,
            'fact_key' => $factKey,
            'start_date' => $sourceStart,
            'end_date' => $sourceEnd,
            'source_rows' => count($rowIds),
            'source_fact_rows' => count($rowIds) + $excludedFactRows,
            'fact_rows' => count($rowIds),
            'trusted_fact_rows' => count($rowIds),
            'trusted_days' => 21,
            'trusted_dates' => $dates,
            'latest_trusted_date' => $sourceEnd,
            'excluded_fact_rows' => $excludedFactRows,
            'excluded_fact_reason_counts' => $reasonCounts,
            'trace_failures' => $traceFailures,
            'expected_platforms' => $platforms,
            'observed_platforms' => $platforms,
            'platforms_by_date' => $platformsByDate,
            'platform_coverage_status' => 'ready',
            'coverage_complete_days' => 21,
            'freshness_status' => 'current',
            'row_ids' => $rowIds,
            'source_identity_digest' => $digest,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<int, array<string, mixed>> $actualSeries
     * @return array<string, array<string, mixed>>
     */
    private function verifiedActualMetricQuality(array $actualSeries): array
    {
        $result = [];
        foreach ($actualSeries as $item) {
            $date = (string)($item['date'] ?? '');
            foreach ([
                'ota_revenue',
                'ota_orders',
                'ota_room_nights',
                'ota_list_exposure',
                'ota_detail_exposure',
                'ota_order_submit',
            ] as $metricKey) {
                if (!is_numeric($item[$metricKey] ?? null)) {
                    continue;
                }
                $result[$metricKey] ??= [
                    'trusted_dates' => [],
                    'expected_platforms' => ['ctrip', 'meituan'],
                    'platforms_by_date' => [],
                ];
                $result[$metricKey]['trusted_dates'][] = $date;
                $result[$metricKey]['platforms_by_date'][$date] = ['ctrip', 'meituan'];
            }
        }
        foreach ($result as &$quality) {
            $quality['trusted_dates'] = array_values(array_unique($quality['trusted_dates']));
            sort($quality['trusted_dates'], SORT_STRING);
            ksort($quality['platforms_by_date'], SORT_STRING);
        }
        unset($quality);
        return $result;
    }

    public function testHomeTemporalAxisSeparatesYesterdayFactsTodayStatusAndFutureJudgement(): void
    {
        $root = dirname(__DIR__);
        $template = (string)file_get_contents(
            $root . '/resources/frontend/templates/fragments/23a-page-compass-summary.html'
        );
        $entry = (string)file_get_contents($root . '/public/app-main.js');
        $homeStatic = (string)file_get_contents($root . '/public/home-static.js');

        self::assertStringContainsString('<home-yesterday-operating-facts', $template);
        self::assertStringContainsString(':show-header="true"', $template);
        self::assertStringContainsString('昨天事实 / 今天状态 / 未来 AI 研判', $template);
        self::assertStringContainsString("'home-yesterday-operating-facts'", $homeStatic);
        foreach ([
            'home-yesterday-dual-scope',
            'home-whole-hotel-scope',
            'home-ota-channel-scope',
            'home-reconciliation-facts',
            'home-temporal-axis',
            'home-competitor-diagnostic-reference',
        ] as $testId) {
            self::assertStringContainsString("'data-testid': '" . $testId . "'", $homeStatic);
        }
        self::assertStringContainsString("requireHomeStatic('buildHomeBusinessTimeModel')", $entry);
        self::assertStringContainsString("request(`/temporal-insights/overview?", $entry);
        self::assertStringContainsString("request('/temporal-insights/forecasts'", $entry);
        self::assertStringContainsString('不回退旧日期', $homeStatic);
        self::assertStringContainsString('不把进行中快照写成日终经营结果', $homeStatic);
        self::assertStringContainsString('不自动执行', $homeStatic);
    }
}
