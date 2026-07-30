<?php
declare(strict_types=1);

use app\service\TemporalInsightService;
use think\App;
use think\facade\Db;

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

/** @param array<int, string> $argv @return array{hotel_id:int,date:string} */
function temporal_closure_report_options(array $argv): array
{
    $result = ['hotel_id' => 0, 'date' => date('Y-m-d')];
    foreach (array_slice($argv, 1) as $argument) {
        if (str_starts_with($argument, '--hotel-id=')) {
            $result['hotel_id'] = max(0, (int)substr($argument, strlen('--hotel-id=')));
            continue;
        }
        if (str_starts_with($argument, '--date=')) {
            $result['date'] = trim(substr($argument, strlen('--date=')));
            continue;
        }
        throw new InvalidArgumentException('unsupported argument: ' . $argument);
    }
    if ($result['hotel_id'] <= 0) {
        throw new InvalidArgumentException('--hotel-id=<positive system hotel id> is required');
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $result['date']) !== 1) {
        throw new InvalidArgumentException('--date must be YYYY-MM-DD');
    }
    return $result;
}

function temporal_closure_table_exists(string $table): bool
{
    try {
        return Db::query("SHOW TABLES LIKE '" . addslashes($table) . "'") !== [];
    } catch (Throwable) {
        return false;
    }
}

/** @return array<int, string> */
function temporal_closure_date_range(string $startDate, string $endDate): array
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) !== 1
        || preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) !== 1
        || $startDate > $endDate
    ) {
        return [];
    }

    $dates = [];
    $cursor = new DateTimeImmutable($startDate);
    $end = new DateTimeImmutable($endDate);
    while ($cursor <= $end) {
        $dates[] = $cursor->format('Y-m-d');
        $cursor = $cursor->modify('+1 day');
    }
    return $dates;
}

try {
    $options = temporal_closure_report_options($argv);
    $root = dirname(__DIR__);
    (new App($root))->initialize();
    $hotelId = $options['hotel_id'];
    $asOfDate = $options['date'];
    $overview = (new TemporalInsightService())->overview([$hotelId], 30, 7, $asOfDate);

    $metricQuality = is_array($overview['past']['metric_quality'] ?? null)
        ? $overview['past']['metric_quality']
        : [];
    $sourceStartDate = (string)($overview['past']['period']['start_date'] ?? '');
    $sourceEndDate = (string)($overview['past']['period']['end_date'] ?? '');
    $sourcePeriodDates = temporal_closure_date_range($sourceStartDate, $sourceEndDate);
    $metrics = [];
    foreach ($metricQuality as $metricKey => $quality) {
        if (!is_array($quality)) {
            continue;
        }
        $trustedDates = array_values(array_map('strval', (array)($quality['trusted_dates'] ?? [])));
        sort($trustedDates, SORT_STRING);
        $expectedPlatforms = array_values(array_map(
            static fn(mixed $platform): string => strtolower(trim((string)$platform)),
            (array)($quality['expected_platforms'] ?? [])
        ));
        $expectedPlatforms = array_values(array_filter(array_unique($expectedPlatforms)));
        sort($expectedPlatforms, SORT_STRING);
        $platformsByDate = is_array($quality['platforms_by_date'] ?? null)
            ? $quality['platforms_by_date']
            : [];
        $missingPlatformsByDate = [];
        foreach ((array)($quality['incomplete_platform_dates'] ?? []) as $date) {
            $date = (string)$date;
            $observedPlatforms = array_values(array_map(
                static fn(mixed $platform): string => strtolower(trim((string)$platform)),
                (array)($platformsByDate[$date] ?? [])
            ));
            $missingPlatforms = array_values(array_diff($expectedPlatforms, $observedPlatforms));
            if ($missingPlatforms !== []) {
                $missingPlatformsByDate[$date] = $missingPlatforms;
            }
        }
        $metrics[(string)$metricKey] = [
            'valid_days' => (int)($quality['trusted_days'] ?? 0),
            'required_operational_days' => (int)($quality['required_operational_days'] ?? 21),
            'missing_operational_days' => (int)($quality['missing_operational_days'] ?? 21),
            'trusted_dates' => $trustedDates,
            'absent_dates_in_source_window' => array_values(array_diff($sourcePeriodDates, $trustedDates)),
            'trusted_fact_rows' => (int)($quality['trusted_fact_rows'] ?? 0),
            'excluded_fact_rows' => (int)($quality['excluded_fact_rows'] ?? 0),
            'latest_trusted_date' => (string)($quality['latest_trusted_date'] ?? ''),
            'latest_readback_at' => (string)($quality['latest_readback_at'] ?? ''),
            'freshness_status' => (string)($quality['freshness_status'] ?? 'unknown'),
            'expected_platforms' => $expectedPlatforms,
            'platform_coverage_status' => (string)($quality['platform_coverage_status'] ?? 'unknown'),
            'incomplete_platform_dates' => array_values((array)($quality['incomplete_platform_dates'] ?? [])),
            'missing_platforms_by_date' => $missingPlatformsByDate,
            'quality_status' => (string)($quality['quality_status'] ?? 'insufficient'),
            'blocking_reason_counts' => array_filter(
                (array)($quality['excluded_fact_reason_counts'] ?? []),
                static fn(int $count, string $reason): bool =>
                    $count > 0 && !str_starts_with($reason, 'non_self_compare_type_'),
                ARRAY_FILTER_USE_BOTH
            ),
        ];
    }

    $review = is_array($overview['review'] ?? null) ? $overview['review'] : [];
    $cohorts = [];
    foreach ((array)($review['cohorts'] ?? []) as $cohort) {
        if (!is_array($cohort)) {
            continue;
        }
        $cohorts[] = [
            'metric_key' => (string)($cohort['metric_key'] ?? ''),
            'horizon_days' => (int)($cohort['horizon_days'] ?? 0),
            'diagnostic_matched_points' => (int)($cohort['diagnostic_matched_points'] ?? 0),
            'operational_matched_points' => (int)($cohort['matched_points'] ?? 0),
            'forecast_source_excluded_points' => (int)($cohort['source_quality_excluded_points'] ?? 0),
            'actual_quality_excluded_points' => (int)($cohort['actual_quality_excluded_points'] ?? 0),
            'range_hit_rate' => $cohort['range_hit_rate'] ?? null,
            'sample_status' => (string)($cohort['sample_status'] ?? 'insufficient'),
            'decision_status' => (string)($cohort['decision_status'] ?? 'disabled'),
            'reason_code' => (string)($cohort['reason_code'] ?? ''),
        ];
    }

    $periodRoleCounts = [];
    if (temporal_closure_table_exists('online_daily_data')) {
        $periodRoleCounts = [
            'misclassified_historical_future_search_rows' => (int)Db::name('online_daily_data')
                ->where('system_hotel_id', $hotelId)
                ->where('source', 'ctrip')
                ->where('data_type', 'traffic')
                ->where('data_period', 'historical_daily')
                ->where('is_final', 1)
                ->whereLike('dimension', 'catalog:traffic_report:traffic_search_details:%')
                ->count(),
            'future_search_rows' => (int)Db::name('online_daily_data')
                ->where('system_hotel_id', $hotelId)
                ->where('source', 'ctrip')
                ->where('data_type', 'traffic')
                ->where('data_period', 'next_30_days')
                ->whereLike('dimension', 'catalog:traffic_report:traffic_search_details:%')
                ->count(),
            'future_search_rows_readback_verified' => (int)Db::name('online_daily_data')
                ->where('system_hotel_id', $hotelId)
                ->where('source', 'ctrip')
                ->where('data_type', 'traffic')
                ->where('data_period', 'next_30_days')
                ->whereLike('dimension', 'catalog:traffic_report:traffic_search_details:%')
                ->where('readback_verified', 1)
                ->count(),
            'future_search_rows_readback_unverified' => (int)Db::name('online_daily_data')
                ->where('system_hotel_id', $hotelId)
                ->where('source', 'ctrip')
                ->where('data_type', 'traffic')
                ->where('data_period', 'next_30_days')
                ->where('is_final', 0)
                ->whereLike('dimension', 'catalog:traffic_report:traffic_search_details:%')
                ->where('readback_verified', 0)
                ->count(),
            'retained_realtime_future_search_snapshots' => (int)Db::name('online_daily_data')
                ->where('system_hotel_id', $hotelId)
                ->where('source', 'ctrip')
                ->where('data_type', 'traffic')
                ->where('data_period', 'realtime_snapshot')
                ->where('is_final', 0)
                ->whereLike('dimension', 'catalog:traffic_report:traffic_search_details:%')
                ->count(),
        ];
    }

    $operationLoop = [
        'intents' => 0,
        'intent_status_counts' => [],
        'tasks' => 0,
        'task_status_counts' => [],
        'evidence' => 0,
        'evidence_type_counts' => [],
    ];
    if (temporal_closure_table_exists('operation_execution_intents')) {
        $intentRows = Db::name('operation_execution_intents')
            ->where('hotel_id', $hotelId)
            ->where('source_module', TemporalInsightService::OPERATION_SOURCE_MODULE)
            ->whereNull('deleted_at')
            ->field('id,status')
            ->select()
            ->toArray();
        $operationLoop['intents'] = count($intentRows);
        foreach ($intentRows as $intent) {
            $status = (string)($intent['status'] ?? 'unknown');
            $operationLoop['intent_status_counts'][$status] =
                (int)($operationLoop['intent_status_counts'][$status] ?? 0) + 1;
        }
        $intentIds = array_values(array_filter(array_map(
            static fn(array $intent): int => (int)($intent['id'] ?? 0),
            $intentRows
        )));
        if ($intentIds !== [] && temporal_closure_table_exists('operation_execution_tasks')) {
            $taskRows = Db::name('operation_execution_tasks')
                ->whereIn('intent_id', $intentIds)
                ->whereNull('deleted_at')
                ->field('id,status,result_status')
                ->select()
                ->toArray();
            $operationLoop['tasks'] = count($taskRows);
            foreach ($taskRows as $task) {
                $status = (string)($task['status'] ?? 'unknown');
                $operationLoop['task_status_counts'][$status] =
                    (int)($operationLoop['task_status_counts'][$status] ?? 0) + 1;
            }
            $taskIds = array_values(array_filter(array_map(
                static fn(array $task): int => (int)($task['id'] ?? 0),
                $taskRows
            )));
            if ($taskIds !== [] && temporal_closure_table_exists('operation_execution_evidence')) {
                $evidenceRows = Db::name('operation_execution_evidence')
                    ->whereIn('task_id', $taskIds)
                    ->whereNull('deleted_at')
                    ->field('evidence_type')
                    ->select()
                    ->toArray();
                $operationLoop['evidence'] = count($evidenceRows);
                foreach ($evidenceRows as $evidence) {
                    $type = (string)($evidence['evidence_type'] ?? 'unknown');
                    $operationLoop['evidence_type_counts'][$type] =
                        (int)($operationLoop['evidence_type_counts'][$type] ?? 0) + 1;
                }
            }
        }
    }

    echo json_encode([
        'generated_at' => date('Y-m-d H:i:s'),
        'scope' => [
            'system_hotel_id' => $hotelId,
            'metric_scope' => 'ota_channel',
            'as_of_date' => $asOfDate,
        ],
        'history' => [
            'status' => (string)($overview['past']['status'] ?? 'empty'),
            'source_period' => [
                'start_date' => $sourceStartDate,
                'end_date' => $sourceEndDate,
            ],
            'gap_semantics' => [
                'missing_operational_days' => 'additional_valid_days_needed_to_reach_21',
                'absent_dates_in_source_window' => 'dates_with_no_trusted_fact_for_this_metric',
                'missing_platforms_by_date' => 'trusted_metric_date_missing_one_or_more_expected_ota_platforms',
                'blocking_reason_counts' => 'overlapping_fact_level_exclusion_reasons_not_unique_source_rows',
            ],
            'metrics' => $metrics,
            'data_gaps' => array_values((array)($overview['past']['data_gaps'] ?? [])),
        ],
        'backtest' => [
            'conclusion_status' => (string)($review['conclusion_status'] ?? 'disabled'),
            'operational_use' => (string)($review['operational_use'] ?? 'disabled'),
            'diagnostic_matched_points' => (int)($review['matched_points'] ?? 0),
            'diagnostic_range_hits' => (int)($review['range_hits'] ?? 0),
            'diagnostic_range_hit_rate' => $review['range_hit_rate'] ?? null,
            'eligible_cohort_count' => (int)($review['eligible_cohort_count'] ?? 0),
            'disabled_cohort_count' => (int)($review['disabled_cohort_count'] ?? 0),
            'cohorts' => $cohorts,
            'automatic_price_write' => false,
        ],
        'future' => [
            'status' => (string)($overview['future']['status'] ?? 'empty'),
            'forecast_run_id' => (string)($overview['future']['version']['forecast_run_id'] ?? ''),
            'as_of_date' => (string)($overview['future']['version']['as_of_date'] ?? ''),
            'source_start_date' => (string)($overview['future']['version']['source_start_date'] ?? ''),
            'source_end_date' => (string)($overview['future']['version']['source_end_date'] ?? ''),
            'operational_backtest_status' => (string)($overview['future']['operational_backtest_status'] ?? ''),
            'operation_recommendation' => $overview['future']['operation_recommendation'] ?? null,
        ],
        'period_role_repair' => $periodRoleCounts,
        'operation_loop' => $operationLoop,
        'closure_policy' => [
            'metric_horizon_independent_backtest' => true,
            'insufficient_sample_disables_conclusion' => true,
            'task_requires_explicit_human_approval' => true,
            'next_day_actual_is_observed_not_attributed' => true,
            'automatic_price_write' => false,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, '[report:temporal-operational-closure] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
