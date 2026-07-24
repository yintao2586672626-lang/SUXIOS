<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use think\facade\Db;

/**
 * Unifies final OTA facts, today's latest OTA snapshot, versioned coarse
 * forecasts and forecast review without turning forecasts into price actions.
 */
final class TemporalInsightService
{
    private const FORECAST_TABLE = 'temporal_forecast_snapshots';
    private const MODEL_VERSION = 'coarse_trend_v1';
    private const METHOD = 'weekday_recent_trend_interval';
    private const CONFIDENCE_TYPE = 'uncalibrated_rule_index';
    private const CONFIDENCE_SEMANTICS = '由样本覆盖、稳定性和新鲜度加权形成的规则指数，未经概率校准，不代表预测命中概率。';

    /** @var array<string, string> */
    private const METRICS = [
        'ota_revenue' => 'revenue',
        'ota_orders' => 'order_count',
        'ota_room_nights' => 'room_nights',
        'ota_list_exposure' => 'list_exposure',
        'ota_detail_exposure' => 'detail_exposure',
        'ota_order_submit' => 'order_submit_num',
    ];

    private OtaStandardEtlService $etl;

    public function __construct(?OtaStandardEtlService $etl = null)
    {
        $this->etl = $etl ?: new OtaStandardEtlService();
    }

    /**
     * @param array<int, int|string> $hotelIds
     * @return array<string, mixed>
     */
    public function overview(array $hotelIds, int $historyDays = 30, int $futureDays = 7, ?string $today = null): array
    {
        $scope = $this->hotelScope($hotelIds);
        $todayDate = $this->date($today ?: date('Y-m-d'), 'today');
        $historyDays = max(7, min(90, $historyDays));
        $futureDays = max(3, min(14, $futureDays));
        $historyEnd = $this->shiftDate($todayDate, -1);
        $historyStart = $this->shiftDate($historyEnd, -($historyDays - 1));

        if ($scope['blocked']) {
            return $this->emptyOverview($todayDate, $historyStart, $historyEnd, 'hotel_scope_denied');
        }

        $pastBundle = $this->loadPeriodFacts(
            $scope['ids'],
            $historyStart,
            $historyEnd,
            'historical_daily',
            true
        );
        $presentBundle = $this->loadPeriodFacts(
            $scope['ids'],
            $todayDate,
            $todayDate,
            'realtime_snapshot',
            false
        );

        $past = $this->pastView($pastBundle, $historyStart, $historyEnd);
        $present = $this->presentView($presentBundle, $pastBundle);
        $future = $this->futureView($scope['ids'], $todayDate, $futureDays);
        $review = $this->reviewView($scope['ids'], $todayDate);

        return [
            'generated_at' => date('Y-m-d H:i:s'),
            'metric_scope' => 'ota_channel',
            'scope_note' => '仅反映已授权 OTA 渠道数据，不代表酒店全口径经营结果。',
            'confidence_type' => self::CONFIDENCE_TYPE,
            'confidence_semantics' => self::CONFIDENCE_SEMANTICS,
            'calibration_status' => 'not_calibrated',
            'temporal_principle' => [
                'past' => '过去有据',
                'present' => '如今可察',
                'future' => '未来可观',
                'loop' => '预测到期后与定稿事实对照，成为下一轮历史证据。',
            ],
            'past' => $past,
            'present' => $present,
            'future' => $future,
            'review' => $review,
            'view_state' => [
                'has_past' => ($past['status'] ?? 'empty') !== 'empty',
                'has_present' => ($present['status'] ?? 'empty') !== 'empty',
                'has_future' => ($future['status'] ?? 'empty') === 'ready',
                'has_review' => ($review['status'] ?? 'empty') === 'ready',
            ],
        ];
    }

    /**
     * Generate and persist one immutable forecast version for one hotel.
     * The result predicts OTA revenue/orders/room nights only, never a price.
     *
     * @return array<string, mixed>
     */
    public function generateForecast(int $hotelId, int $createdBy = 0, ?string $asOfDate = null, int $futureDays = 7): array
    {
        if ($hotelId <= 0) {
            throw new InvalidArgumentException('生成预测前必须选择一个已授权酒店。');
        }
        if (!$this->tableExists(self::FORECAST_TABLE)) {
            throw new RuntimeException('预测版本表尚未初始化，请先执行 20260715_create_temporal_forecast_snapshots.sql。', 422);
        }

        $asOf = $this->date($asOfDate ?: date('Y-m-d'), 'as_of_date');
        $futureDays = max(3, min(14, $futureDays));
        $sourceEnd = $this->shiftDate($asOf, -1);
        $sourceStart = $this->shiftDate($sourceEnd, -27);
        $history = $this->loadPeriodFacts([$hotelId], $sourceStart, $sourceEnd, 'historical_daily', true);
        $plan = $this->buildForecastPlan($history['series'], $asOf, $futureDays);

        if (($plan['points'] ?? []) === []) {
            return [
                'status' => 'insufficient_data',
                'message' => '至少需要 7 个有效历史日才能形成粗粒度趋势区间。',
                'metric_scope' => 'ota_channel',
                'system_hotel_id' => $hotelId,
                'source_period' => ['start_date' => $sourceStart, 'end_date' => $sourceEnd],
                'saved_count' => 0,
                'readback_count' => 0,
                'metrics' => $plan['metrics'] ?? [],
                'data_quality' => $history['data_quality'] ?? [],
                'data_gaps' => $history['data_gaps'] ?? [],
                'confidence_type' => self::CONFIDENCE_TYPE,
                'confidence_semantics' => self::CONFIDENCE_SEMANTICS,
                'calibration_status' => 'not_calibrated',
            ];
        }

        $runId = 'tf_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(6)), 0, 12);
        $asOfTime = date('Y-m-d H:i:s');
        $tenantId = $this->tenantIdForHotel($hotelId);
        $sourceRefs = json_encode([
            'table' => 'online_daily_data',
            'metric_scope' => 'ota_channel',
            'period' => 'historical_daily',
            'is_final' => 1,
            'start_date' => $sourceStart,
            'end_date' => $sourceEnd,
            'source_rows' => (int)($history['source_row_count'] ?? 0),
            'source_fact_rows' => (int)($history['source_fact_count'] ?? 0),
            'fact_rows' => (int)($history['fact_count'] ?? 0),
            'trusted_fact_rows' => (int)($history['fact_count'] ?? 0),
            'excluded_fact_rows' => (int)($history['excluded_fact_count'] ?? 0),
            'excluded_fact_reason_counts' => $history['excluded_fact_reason_counts'] ?? [],
            'row_ids' => array_slice($history['source_row_ids'] ?? [], 0, 20),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $metricMeta = [];
        foreach ($plan['metrics'] as $metric) {
            $metricMeta[(string)$metric['metric_key']] = $metric;
        }
        $sourceQualityStatus = (string)($history['data_quality']['status'] ?? 'partial');

        $rows = [];
        foreach ($plan['points'] as $point) {
            $metricKey = (string)$point['metric_key'];
            $meta = $metricMeta[$metricKey] ?? [];
            $rows[] = [
                'tenant_id' => $tenantId,
                'system_hotel_id' => $hotelId,
                'metric_scope' => 'ota_channel',
                'platform' => 'all_ota',
                'metric_key' => $metricKey,
                'forecast_run_id' => $runId,
                'as_of_date' => $asOf,
                'as_of_time' => $asOfTime,
                'target_date' => (string)$point['target_date'],
                'horizon_days' => (int)$point['horizon_days'],
                'model_version' => self::MODEL_VERSION,
                'method' => self::METHOD,
                'predicted_direction' => (string)$point['direction'],
                'predicted_value' => $point['predicted_value'],
                'lower_bound' => $point['lower_bound'],
                'upper_bound' => $point['upper_bound'],
                'confidence_score' => $point['confidence_score'],
                'confidence_level' => (string)$point['confidence_level'],
                'sample_days' => (int)($meta['sample_days'] ?? 0),
                'source_start_date' => $sourceStart,
                'source_end_date' => $sourceEnd,
                'source_refs_json' => $sourceRefs,
                'data_quality_status' => $sourceQualityStatus === 'ready'
                    ? (string)($meta['data_quality_status'] ?? 'partial')
                    : 'partial',
                'created_by' => max(0, $createdBy),
                'created_at' => $asOfTime,
            ];
        }

        [$savedCount, $readbackRows] = Db::transaction(function () use ($rows, $tenantId, $hotelId, $runId): array {
            $savedCount = (int)Db::name(self::FORECAST_TABLE)->insertAll($rows);
            $readbackRows = Db::name(self::FORECAST_TABLE)
                ->where('tenant_id', $tenantId)
                ->where('system_hotel_id', $hotelId)
                ->where('forecast_run_id', $runId)
                ->order('metric_key', 'asc')
                ->order('target_date', 'asc')
                ->select()
                ->toArray();
            if ($savedCount !== count($rows) || !$this->forecastReadbackMatches($rows, $readbackRows)) {
                throw new RuntimeException('forecast snapshot persistence readback mismatch; transaction rolled back');
            }
            return [$savedCount, $readbackRows];
        });

        if ($savedCount !== count($rows) || count($readbackRows) !== count($rows)) {
            throw new RuntimeException('预测版本保存后回读数量不一致，未将本次结果标记为完成。');
        }

        return [
            'status' => 'generated',
            'message' => '已保存一版粗粒度 OTA 趋势规则情景，可在到期后与定稿事实复盘。',
            'metric_scope' => 'ota_channel',
            'system_hotel_id' => $hotelId,
            'forecast_run_id' => $runId,
            'as_of_date' => $asOf,
            'as_of_time' => $asOfTime,
            'model_version' => self::MODEL_VERSION,
            'source_period' => ['start_date' => $sourceStart, 'end_date' => $sourceEnd],
            'saved_count' => $savedCount,
            'readback_count' => count($readbackRows),
            'metrics' => $plan['metrics'],
            'points' => $this->shapeForecastRows($readbackRows),
            'data_quality' => $history['data_quality'] ?? [],
            'data_gaps' => $history['data_gaps'] ?? [],
            'confidence_type' => self::CONFIDENCE_TYPE,
            'confidence_semantics' => self::CONFIDENCE_SEMANTICS,
            'calibration_status' => 'not_calibrated',
            'boundary' => '仅提供趋势、区间与未校准规则置信指数，不生成执行价格，不自动写入 OTA。',
        ];
    }

    /**
     * Pure deterministic forecast core for tests and future scheduled jobs.
     *
     * @param array<int, array<string, mixed>> $dailySeries
     * @return array<string, mixed>
     */
    public function buildForecastPlan(array $dailySeries, string $asOfDate, int $futureDays = 7): array
    {
        $asOf = $this->date($asOfDate, 'as_of_date');
        $futureDays = max(3, min(14, $futureDays));
        usort($dailySeries, static fn(array $a, array $b): int => strcmp((string)($a['date'] ?? ''), (string)($b['date'] ?? '')));

        $metricPlans = [];
        $points = [];
        foreach (self::METRICS as $metricKey => $factKey) {
            $valuesByDate = [];
            foreach ($dailySeries as $item) {
                $date = (string)($item['date'] ?? '');
                $value = $item[$metricKey] ?? ($item[$factKey] ?? null);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && is_numeric($value)) {
                    $valuesByDate[$date] = (float)$value;
                }
            }
            ksort($valuesByDate);
            $sampleDays = count($valuesByDate);
            if ($sampleDays < 7) {
                $metricPlans[] = [
                    'metric_key' => $metricKey,
                    'status' => 'insufficient_data',
                    'sample_days' => $sampleDays,
                    'required_days' => 7,
                    'data_quality_status' => 'insufficient',
                    'confidence_type' => self::CONFIDENCE_TYPE,
                    'confidence_semantics' => self::CONFIDENCE_SEMANTICS,
                ];
                continue;
            }

            $allValues = array_values($valuesByDate);
            $mean = $this->average($allValues) ?? 0.0;
            $std = $this->standardDeviation($allValues, $mean);
            $coefficientOfVariation = $mean > 0 ? min(2.0, $std / $mean) : 1.0;
            $recentValues = $this->valuesWithin($valuesByDate, $this->shiftDate($asOf, -7), $this->shiftDate($asOf, -1));
            $previousValues = $this->valuesWithin($valuesByDate, $this->shiftDate($asOf, -14), $this->shiftDate($asOf, -8));
            $recentAverage = $this->average($recentValues) ?? $mean;
            $previousAverage = $this->average($previousValues);
            $trendRate = $previousAverage !== null && $previousAverage > 0
                ? max(-0.20, min(0.20, ($recentAverage - $previousAverage) / $previousAverage))
                : 0.0;
            $direction = $trendRate > 0.05 ? 'up' : ($trendRate < -0.05 ? 'down' : 'stable');
            $coverage = min(1.0, $sampleDays / 28);
            $recency = min(1.0, count($recentValues) / 7);
            $stability = max(0.20, 1.0 - min(1.0, $coefficientOfVariation));
            $confidence = round(max(0.20, min(0.90, 0.50 * $coverage + 0.30 * $stability + 0.20 * $recency)), 3);
            $confidenceLevel = $confidence >= 0.75 ? 'high' : ($confidence >= 0.50 ? 'medium' : 'low');
            $dataQuality = $sampleDays >= 21 && $confidence >= 0.55 ? 'ready' : 'partial';

            $metricPlans[] = [
                'metric_key' => $metricKey,
                'status' => 'ready',
                'sample_days' => $sampleDays,
                'recent_average' => $this->roundMetric($metricKey, $recentAverage),
                'previous_average' => $previousAverage !== null ? $this->roundMetric($metricKey, $previousAverage) : null,
                'trend_percent' => round($trendRate * 100, 1),
                'direction' => $direction,
                'confidence_score' => $confidence,
                'confidence_level' => $confidenceLevel,
                'confidence_type' => self::CONFIDENCE_TYPE,
                'confidence_semantics' => self::CONFIDENCE_SEMANTICS,
                'data_quality_status' => $dataQuality,
            ];

            for ($horizon = 1; $horizon <= $futureDays; $horizon++) {
                $targetDate = $this->shiftDate($asOf, $horizon);
                $weekday = (int)(new DateTimeImmutable($targetDate))->format('N');
                $weekdayValues = [];
                foreach ($valuesByDate as $date => $value) {
                    if ((int)(new DateTimeImmutable($date))->format('N') === $weekday) {
                        $weekdayValues[] = $value;
                    }
                }
                $weekdayAverage = $this->average($weekdayValues);
                $baseline = $weekdayAverage !== null
                    ? 0.60 * $weekdayAverage + 0.40 * $recentAverage
                    : $recentAverage;
                $predicted = max(0.0, $baseline * (1.0 + 0.50 * $trendRate));
                $uncertainty = max(0.12, min(0.45, 0.10 + 0.80 * $coefficientOfVariation));
                $uncertainty = min(0.60, $uncertainty + (1.0 - $confidence) * 0.15 + ($horizon - 1) * 0.015);
                $lower = max(0.0, $predicted * (1.0 - $uncertainty));
                $upper = max($lower, $predicted * (1.0 + $uncertainty));

                $points[] = [
                    'metric_key' => $metricKey,
                    'target_date' => $targetDate,
                    'horizon_days' => $horizon,
                    'direction' => $direction,
                    'predicted_value' => $this->roundMetric($metricKey, $predicted),
                    'lower_bound' => $this->roundMetric($metricKey, $lower),
                    'upper_bound' => $this->roundMetric($metricKey, $upper),
                    'confidence_score' => $confidence,
                    'confidence_level' => $confidenceLevel,
                    'confidence_type' => self::CONFIDENCE_TYPE,
                ];
            }
        }

        return [
            'status' => $points !== [] ? 'ready' : 'insufficient_data',
            'as_of_date' => $asOf,
            'future_days' => $futureDays,
            'model_version' => self::MODEL_VERSION,
            'method' => self::METHOD,
            'confidence_type' => self::CONFIDENCE_TYPE,
            'confidence_semantics' => self::CONFIDENCE_SEMANTICS,
            'calibration_status' => 'not_calibrated',
            'metrics' => $metricPlans,
            'points' => $points,
        ];
    }

    /**
     * @param array<int, int> $hotelIds
     * @return array<string, mixed>
     */
    private function futureView(array $hotelIds, string $today, int $futureDays): array
    {
        if (count($hotelIds) !== 1) {
            return [
                'status' => 'select_hotel',
                'label' => '未来可观',
                'message' => '选择一家酒店后查看其预测版本，避免把多店趋势混成一个结论。',
                'series' => [],
            ];
        }
        if (!$this->tableExists(self::FORECAST_TABLE)) {
            return [
                'status' => 'not_initialized',
                'label' => '未来可观',
                'message' => '预测版本表尚未初始化。',
                'series' => [],
            ];
        }

        $hotelId = $hotelIds[0];
        $startDate = $this->shiftDate($today, 1);
        $endDate = $this->shiftDate($today, $futureDays);
        $latest = Db::name(self::FORECAST_TABLE)
            ->where('system_hotel_id', $hotelId)
            ->where('as_of_date', '<=', $today)
            ->whereBetween('target_date', [$startDate, $endDate])
            ->order('as_of_time', 'desc')
            ->order('id', 'desc')
            ->find();
        if (!$latest) {
            return [
                'status' => 'empty',
                'label' => '未来可观',
                'message' => '尚无可用预测版本；可基于最近定稿事实生成一版粗粒度趋势。',
                'series' => [],
            ];
        }

        $rows = Db::name(self::FORECAST_TABLE)
            ->where('system_hotel_id', $hotelId)
            ->where('forecast_run_id', (string)$latest['forecast_run_id'])
            ->whereBetween('target_date', [$startDate, $endDate])
            ->order('target_date', 'asc')
            ->order('metric_key', 'asc')
            ->select()
            ->toArray();

        return [
            'status' => 'ready',
            'label' => '未来可观',
            'message' => '展示方向、区间和未校准规则置信指数；不提供执行价格。',
            'version' => [
                'forecast_run_id' => (string)$latest['forecast_run_id'],
                'as_of_date' => (string)$latest['as_of_date'],
                'as_of_time' => (string)$latest['as_of_time'],
                'model_version' => (string)$latest['model_version'],
                'source_start_date' => (string)($latest['source_start_date'] ?? ''),
                'source_end_date' => (string)($latest['source_end_date'] ?? ''),
            ],
            'series' => $this->shapeForecastRows($rows),
            'confidence_type' => self::CONFIDENCE_TYPE,
            'confidence_semantics' => self::CONFIDENCE_SEMANTICS,
            'calibration_status' => 'not_calibrated',
            'boundary' => 'AI 只解释趋势证据与不确定性，不生成自动调价动作。',
        ];
    }

    /**
     * @param array<int, int> $hotelIds
     * @return array<string, mixed>
     */
    private function reviewView(array $hotelIds, string $today): array
    {
        if (count($hotelIds) !== 1) {
            return ['status' => 'select_hotel', 'label' => '回看当时', 'items' => []];
        }
        if (!$this->tableExists(self::FORECAST_TABLE)) {
            return ['status' => 'not_initialized', 'label' => '回看当时', 'items' => []];
        }

        $hotelId = $hotelIds[0];
        $yesterday = $this->shiftDate($today, -1);
        $reviewStart = $this->shiftDate($yesterday, -29);
        $latestMatured = Db::name(self::FORECAST_TABLE)
            ->where('system_hotel_id', $hotelId)
            ->whereBetween('target_date', [$reviewStart, $yesterday])
            ->order('as_of_time', 'desc')
            ->order('id', 'desc')
            ->find();
        if (!$latestMatured) {
            return [
                'status' => 'empty',
                'label' => '回看当时',
                'message' => '预测尚未到期，或最近 30 天没有可复盘版本。',
                'items' => [],
            ];
        }

        $runId = (string)$latestMatured['forecast_run_id'];
        $forecasts = Db::name(self::FORECAST_TABLE)
            ->where('system_hotel_id', $hotelId)
            ->where('forecast_run_id', $runId)
            ->whereBetween('target_date', [$reviewStart, $yesterday])
            ->order('target_date', 'asc')
            ->order('metric_key', 'asc')
            ->select()
            ->toArray();
        if ($forecasts === []) {
            return ['status' => 'empty', 'label' => '回看当时', 'items' => []];
        }

        $dates = array_map(static fn(array $row): string => (string)$row['target_date'], $forecasts);
        $actualBundle = $this->loadPeriodFacts([$hotelId], min($dates), max($dates), 'historical_daily', true);
        $actuals = [];
        foreach ($actualBundle['series'] as $item) {
            $actuals[(string)$item['date']] = $item;
        }

        $items = [];
        $matched = 0;
        $hits = 0;
        foreach ($forecasts as $forecast) {
            $date = (string)$forecast['target_date'];
            $metricKey = (string)$forecast['metric_key'];
            $actual = $actuals[$date][$metricKey] ?? null;
            $actual = is_numeric($actual) ? (float)$actual : null;
            $lower = is_numeric($forecast['lower_bound'] ?? null) ? (float)$forecast['lower_bound'] : null;
            $upper = is_numeric($forecast['upper_bound'] ?? null) ? (float)$forecast['upper_bound'] : null;
            $point = is_numeric($forecast['predicted_value'] ?? null) ? (float)$forecast['predicted_value'] : null;
            $withinRange = null;
            $outcome = '实际事实尚未定稿';
            if ($actual !== null && $lower !== null && $upper !== null) {
                $matched++;
                $withinRange = $actual >= $lower && $actual <= $upper;
                if ($withinRange) {
                    $hits++;
                    $outcome = '实际落在当时预测区间';
                } elseif ($actual > $upper) {
                    $outcome = '实际高于当时预测区间';
                } else {
                    $outcome = '实际低于当时预测区间';
                }
            }
            $absoluteError = $actual !== null && $point !== null ? abs($actual - $point) : null;
            $errorPercent = $absoluteError !== null && $actual !== 0.0 ? $absoluteError / abs($actual) * 100 : null;
            $items[] = [
                'target_date' => $date,
                'metric_key' => $metricKey,
                'forecast_interval' => ['lower' => $lower, 'upper' => $upper],
                'predicted_value' => $point,
                'actual_value' => $actual,
                'within_range' => $withinRange,
                'absolute_error' => $absoluteError !== null ? $this->roundMetric($metricKey, $absoluteError) : null,
                'error_percent' => $errorPercent !== null ? round($errorPercent, 1) : null,
                'outcome' => $outcome,
            ];
        }

        return [
            'status' => $matched > 0 ? 'ready' : 'waiting_actual',
            'label' => '回看当时',
            'forecast_run_id' => $runId,
            'as_of_time' => (string)$latestMatured['as_of_time'],
            'model_version' => (string)$latestMatured['model_version'],
            'matched_points' => $matched,
            'range_hit_rate' => $matched > 0 ? round($hits / $matched * 100, 1) : null,
            'message' => $matched > 0
                ? '只评价当时区间是否覆盖后来事实，不用事后结果改写旧预测。'
                : '已有到期预测，但对应日期的定稿事实尚不完整。',
            'items' => $items,
        ];
    }

    /**
     * @param array<string, mixed> $bundle
     * @return array<string, mixed>
     */
    private function pastView(array $bundle, string $startDate, string $endDate): array
    {
        $series = $bundle['series'] ?? [];
        return [
            'status' => $bundle['status'] ?? 'empty',
            'label' => '过去有据',
            'period' => ['start_date' => $startDate, 'end_date' => $endDate],
            'metric_scope' => 'ota_channel',
            'metrics' => $this->trendSummary($series),
            'series' => $series,
            'data_quality' => $bundle['data_quality'] ?? [],
            'data_gaps' => $bundle['data_gaps'] ?? [],
            'source' => [
                'table' => 'online_daily_data',
                'data_period' => 'historical_daily',
                'is_final' => 1,
                'source_rows' => (int)($bundle['source_row_count'] ?? 0),
                'fact_rows' => (int)($bundle['fact_count'] ?? 0),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $presentBundle
     * @param array<string, mixed> $pastBundle
     * @return array<string, mixed>
     */
    private function presentView(array $presentBundle, array $pastBundle): array
    {
        $series = $presentBundle['series'] ?? [];
        $todayMetrics = $series !== [] ? $series[count($series) - 1] : [];
        $pastSeries = $pastBundle['series'] ?? [];
        $latestFinal = $pastSeries !== [] ? $pastSeries[count($pastSeries) - 1] : [];
        $comparison = [];
        foreach (array_keys(self::METRICS) as $metricKey) {
            $current = is_numeric($todayMetrics[$metricKey] ?? null) ? (float)$todayMetrics[$metricKey] : null;
            $previous = is_numeric($latestFinal[$metricKey] ?? null) ? (float)$latestFinal[$metricKey] : null;
            $comparison[$metricKey] = [
                'current_value' => $current,
                'latest_final_value' => $previous,
                'latest_final_date' => $latestFinal['date'] ?? null,
                'change_percent' => $current !== null && $previous !== null && $previous != 0.0
                    ? round(($current - $previous) / abs($previous) * 100, 1)
                    : null,
            ];
        }

        $rowCount = (int)($presentBundle['source_row_count'] ?? 0);
        $snapshotTime = $presentBundle['latest_snapshot_time'] ?? null;
        return [
            'status' => $presentBundle['status'] ?? 'empty',
            'label' => '如今可察',
            'as_of_time' => $snapshotTime,
            'snapshot_row_count' => $rowCount,
            'metrics' => array_intersect_key($todayMetrics, array_fill_keys(array_keys(self::METRICS), true)),
            'comparison_to_latest_final' => $comparison,
            'comparison_caveat' => '今日为累计实时快照，最近定稿日为完整日；差异仅用于观察，不直接作为执行结论。',
            'today_reason' => $rowCount > 0
                ? sprintf('今天已有 %d 条 OTA 快照进入观察，最近更新时间为 %s。', $rowCount, $snapshotTime ?: '待确认')
                : '今天尚无有效 OTA 实时快照，先确认采集状态，不把缺失显示成零。',
            'data_quality' => $presentBundle['data_quality'] ?? [],
            'data_gaps' => $presentBundle['data_gaps'] ?? [],
            'source' => [
                'table' => 'online_daily_data',
                'data_period' => 'realtime_snapshot',
                'is_final' => 0,
            ],
        ];
    }

    /**
     * @param array<int, int> $hotelIds
     * @return array<string, mixed>
     */
    private function loadPeriodFacts(
        array $hotelIds,
        string $startDate,
        string $endDate,
        string $period,
        bool $isFinal
    ): array {
        if (!$this->tableExists('online_daily_data')) {
            return $this->emptyFactBundle('table_missing');
        }

        $query = Db::name('online_daily_data')
            ->whereBetween('data_date', [$startDate, $endDate])
            ->where('data_period', $period)
            ->where('is_final', $isFinal ? 1 : 0)
            ->where('data_type', '<>', 'traffic_forecast');
        if ($hotelIds !== []) {
            $query->whereIn('system_hotel_id', $hotelIds);
        }
        $rows = $query
            ->order('data_date', 'asc')
            ->order('id', 'asc')
            ->limit(250000)
            ->select()
            ->toArray();
        if ($rows === []) {
            return $this->emptyFactBundle('no_rows');
        }

        $dataset = $this->etl->buildDatasetFromRows($rows);
        $dailyFacts = is_array($dataset['fact_ota_daily'] ?? null) ? $dataset['fact_ota_daily'] : [];
        $trafficFacts = is_array($dataset['fact_ota_traffic'] ?? null) ? $dataset['fact_ota_traffic'] : [];
        $facts = array_merge($dailyFacts, $trafficFacts);
        $aggregated = $this->aggregateFacts($facts);
        $quality = is_array($dataset['data_quality'] ?? null) ? $dataset['data_quality'] : [];
        $rejectedCount = is_array($quality['rejected_rows'] ?? null) ? count($quality['rejected_rows']) : 0;
        $traceFailures = (int)($aggregated['trace_failures'] ?? 0);
        $excludedFactCount = (int)($aggregated['excluded_fact_count'] ?? 0);
        $trustedFactCount = (int)($aggregated['trusted_fact_count'] ?? 0);
        $excludedReasonCounts = is_array($aggregated['excluded_fact_reason_counts'] ?? null)
            ? $aggregated['excluded_fact_reason_counts']
            : [];
        $dataGaps = is_array($aggregated['data_gaps'] ?? null) ? $aggregated['data_gaps'] : [];
        if ($rejectedCount > 0) {
            array_unshift($dataGaps, [
                'code' => 'etl_rows_rejected',
                'reason' => 'etl_validation_rejected',
                'count' => $rejectedCount,
            ]);
        }
        $status = $trustedFactCount === 0
            ? 'empty'
            : (($rejectedCount + $excludedFactCount) > 0 ? 'partial' : 'ready');
        $latestSnapshotTime = null;
        foreach ($rows as $row) {
            foreach (['snapshot_time', 'update_time', 'updated_at', 'create_time', 'created_at'] as $field) {
                $value = trim((string)($row[$field] ?? ''));
                if ($value !== '' && ($latestSnapshotTime === null || strcmp($value, $latestSnapshotTime) > 0)) {
                    $latestSnapshotTime = $value;
                    break;
                }
            }
        }

        return [
            'status' => $status,
            'series' => $aggregated['series'],
            'source_row_count' => count($rows),
            'source_fact_count' => count($facts),
            'fact_count' => $trustedFactCount,
            'excluded_fact_count' => $excludedFactCount,
            'excluded_fact_reason_counts' => $excludedReasonCounts,
            'source_row_ids' => $aggregated['source_row_ids'],
            'latest_snapshot_time' => $latestSnapshotTime,
            'data_gaps' => $dataGaps,
            'data_quality' => [
                'status' => $status,
                'canonical_rows' => (int)($quality['canonical_rows'] ?? count($rows)),
                'superseded_period_rows' => (int)($quality['superseded_period_rows'] ?? 0),
                'rejected_rows' => $rejectedCount,
                'trace_failures' => $traceFailures,
                'source_facts' => count($facts),
                'trusted_facts' => $trustedFactCount,
                'excluded_facts' => $excludedFactCount,
                'excluded_fact_reason_counts' => $excludedReasonCounts,
                'data_gaps' => $dataGaps,
                'missing_values_are_null' => true,
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $facts
     * @return array<string, mixed>
     */
    private function aggregateFacts(array $facts): array
    {
        $days = [];
        $sourceRowIds = [];
        $traceFailures = 0;
        $trustedFactCount = 0;
        $excludedFactCount = 0;
        $excludedReasonCounts = [];
        foreach ($facts as $fact) {
            $trace = is_array($fact['source_trace'] ?? null) ? $fact['source_trace'] : [];
            if (($trace['saved_success'] ?? false) !== true) {
                $traceFailures++;
                $excludedFactCount++;
                foreach ($this->traceFailureReasonCodes($trace) as $reason) {
                    $excludedReasonCounts[$reason] = (int)($excludedReasonCounts[$reason] ?? 0) + 1;
                }
                continue;
            }

            $compareTypeExclusion = $this->compareTypeExclusionReason($fact);
            if ($compareTypeExclusion !== null) {
                $excludedFactCount++;
                $excludedReasonCounts[$compareTypeExclusion] = (int)($excludedReasonCounts[$compareTypeExclusion] ?? 0) + 1;
                continue;
            }

            $date = (string)($fact['date_key'] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                $excludedFactCount++;
                $excludedReasonCounts['date_key_invalid'] = (int)($excludedReasonCounts['date_key_invalid'] ?? 0) + 1;
                continue;
            }
            $trustedFactCount++;
            if (!isset($days[$date])) {
                $days[$date] = [
                    'date' => $date,
                    'ota_revenue' => null,
                    'ota_orders' => null,
                    'ota_room_nights' => null,
                    'ota_list_exposure' => null,
                    'ota_detail_exposure' => null,
                    'ota_order_submit' => null,
                    '_counts' => array_fill_keys(array_keys(self::METRICS), 0),
                    '_platforms' => [],
                ];
            }
            foreach (self::METRICS as $metricKey => $factKey) {
                $value = $fact[$factKey] ?? null;
                if (is_numeric($value)) {
                    $days[$date][$metricKey] = ($days[$date][$metricKey] ?? 0.0) + (float)$value;
                    $days[$date]['_counts'][$metricKey]++;
                }
            }
            $platform = trim((string)($fact['platform_key'] ?? ''));
            if ($platform !== '') {
                $days[$date]['_platforms'][$platform] = true;
            }
            if (is_numeric($trace['row_id'] ?? null)) {
                $sourceRowIds[(int)$trace['row_id']] = true;
            }
        }

        ksort($days);
        $series = [];
        foreach ($days as $day) {
            foreach (array_keys(self::METRICS) as $metricKey) {
                if ((int)$day['_counts'][$metricKey] > 0 && is_numeric($day[$metricKey])) {
                    $day[$metricKey] = $this->roundMetric($metricKey, (float)$day[$metricKey]);
                } else {
                    $day[$metricKey] = null;
                }
            }
            $day['platforms'] = array_values(array_keys($day['_platforms']));
            unset($day['_counts'], $day['_platforms']);
            $series[] = $day;
        }

        ksort($excludedReasonCounts);
        $dataGaps = [];
        foreach ($excludedReasonCounts as $reason => $count) {
            $dataGaps[] = [
                'code' => 'fact_excluded',
                'reason' => $reason,
                'count' => $count,
            ];
        }

        return [
            'series' => $series,
            'source_row_ids' => array_values(array_keys($sourceRowIds)),
            'trace_failures' => $traceFailures,
            'trusted_fact_count' => $trustedFactCount,
            'excluded_fact_count' => $excludedFactCount,
            'excluded_fact_reason_counts' => $excludedReasonCounts,
            'data_gaps' => $dataGaps,
        ];
    }

    /** @param array<string, mixed> $trace @return array<int, string> */
    private function traceFailureReasonCodes(array $trace): array
    {
        if ($trace === []) {
            return ['source_trace_missing'];
        }

        $rawReasons = is_array($trace['failure_reasons'] ?? null) ? $trace['failure_reasons'] : [];
        $reasons = [];
        foreach ($rawReasons as $rawReason) {
            $reason = strtolower(trim((string)$rawReason));
            if ($reason === '') {
                continue;
            }
            $reason = explode(':', $reason, 2)[0];
            $reason = trim((string)preg_replace('/[^a-z0-9_]+/', '_', $reason), '_');
            if ($reason !== '') {
                $reasons[$reason] = true;
            }
        }
        if ($reasons === []) {
            $reasons['saved_success_not_true'] = true;
        }
        return array_values(array_keys($reasons));
    }

    /** @param array<string, mixed> $fact */
    private function compareTypeExclusionReason(array $fact): ?string
    {
        if (!array_key_exists('compare_type', $fact)) {
            return null;
        }

        $compareType = strtolower(trim((string)$fact['compare_type']));
        if (in_array($compareType, ['self', 'own', 'ours', 'target_hotel'], true)) {
            return null;
        }
        if ($compareType === '') {
            return 'compare_type_missing';
        }

        $safeCompareType = trim((string)preg_replace('/[^a-z0-9_]+/', '_', $compareType), '_');
        return 'non_self_compare_type_' . ($safeCompareType !== '' ? $safeCompareType : 'unknown');
    }

    /**
     * @param array<int, array<string, mixed>> $series
     * @return array<string, mixed>
     */
    private function trendSummary(array $series): array
    {
        $summary = [];
        foreach (array_keys(self::METRICS) as $metricKey) {
            $values = [];
            foreach ($series as $item) {
                if (is_numeric($item[$metricKey] ?? null)) {
                    $values[] = (float)$item[$metricKey];
                }
            }
            $recent = array_slice($values, -7);
            $previous = array_slice($values, -14, 7);
            $recentAverage = $this->average($recent);
            $previousAverage = $this->average($previous);
            $change = $recentAverage !== null && $previousAverage !== null && $previousAverage != 0.0
                ? ($recentAverage - $previousAverage) / abs($previousAverage) * 100
                : null;
            $summary[$metricKey] = [
                'latest_value' => $values !== [] ? $this->roundMetric($metricKey, $values[count($values) - 1]) : null,
                'recent_7_day_average' => $recentAverage !== null ? $this->roundMetric($metricKey, $recentAverage) : null,
                'previous_7_day_average' => $previousAverage !== null ? $this->roundMetric($metricKey, $previousAverage) : null,
                'change_percent' => $change !== null ? round($change, 1) : null,
                'direction' => $change === null ? 'unknown' : ($change > 5 ? 'up' : ($change < -5 ? 'down' : 'stable')),
                'sample_days' => count($values),
            ];
        }
        return $summary;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function shapeForecastRows(array $rows): array
    {
        $dates = [];
        foreach ($rows as $row) {
            $date = (string)$row['target_date'];
            $metricKey = (string)$row['metric_key'];
            $dates[$date] ??= ['date' => $date, 'metrics' => []];
            $dates[$date]['metrics'][$metricKey] = [
                'direction' => (string)$row['predicted_direction'],
                'lower_bound' => is_numeric($row['lower_bound'] ?? null) ? (float)$row['lower_bound'] : null,
                'upper_bound' => is_numeric($row['upper_bound'] ?? null) ? (float)$row['upper_bound'] : null,
                'confidence_score' => is_numeric($row['confidence_score'] ?? null) ? (float)$row['confidence_score'] : null,
                'confidence_level' => (string)$row['confidence_level'],
                'confidence_type' => self::CONFIDENCE_TYPE,
                'data_quality_status' => (string)$row['data_quality_status'],
            ];
        }
        ksort($dates);
        return array_values($dates);
    }

    /** @return array{ids:array<int, int>,blocked:bool} */
    private function hotelScope(array $hotelIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $hotelIds),
            static fn(int $id): bool => $id > 0
        )));
        sort($ids);
        return ['ids' => $ids, 'blocked' => $hotelIds !== [] && $ids === []];
    }

    /** @return array<string, mixed> */
    private function emptyOverview(string $today, string $historyStart, string $historyEnd, string $reason): array
    {
        return [
            'generated_at' => date('Y-m-d H:i:s'),
            'metric_scope' => 'ota_channel',
            'scope_note' => '没有可读取的授权酒店范围。',
            'temporal_principle' => ['past' => '过去有据', 'present' => '如今可察', 'future' => '未来可观'],
            'past' => ['status' => 'empty', 'label' => '过去有据', 'period' => ['start_date' => $historyStart, 'end_date' => $historyEnd], 'reason' => $reason, 'series' => []],
            'present' => ['status' => 'empty', 'label' => '如今可察', 'date' => $today, 'reason' => $reason],
            'future' => ['status' => 'empty', 'label' => '未来可观', 'reason' => $reason, 'series' => []],
            'review' => ['status' => 'empty', 'label' => '回看当时', 'reason' => $reason, 'items' => []],
            'view_state' => ['has_past' => false, 'has_present' => false, 'has_future' => false, 'has_review' => false],
        ];
    }

    /** @return array<string, mixed> */
    private function emptyFactBundle(string $reason): array
    {
        return [
            'status' => 'empty',
            'reason' => $reason,
            'series' => [],
            'source_row_count' => 0,
            'source_fact_count' => 0,
            'fact_count' => 0,
            'excluded_fact_count' => 0,
            'excluded_fact_reason_counts' => [],
            'source_row_ids' => [],
            'latest_snapshot_time' => null,
            'data_gaps' => [],
            'data_quality' => [
                'status' => 'empty',
                'source_facts' => 0,
                'trusted_facts' => 0,
                'excluded_facts' => 0,
                'excluded_fact_reason_counts' => [],
                'data_gaps' => [],
                'missing_values_are_null' => true,
            ],
        ];
    }

    /** @param array<string, float> $valuesByDate @return array<int, float> */
    private function valuesWithin(array $valuesByDate, string $startDate, string $endDate): array
    {
        $values = [];
        foreach ($valuesByDate as $date => $value) {
            if ($date >= $startDate && $date <= $endDate) {
                $values[] = (float)$value;
            }
        }
        return $values;
    }

    /** @param array<int, float|int> $values */
    private function average(array $values): ?float
    {
        return $values !== [] ? array_sum($values) / count($values) : null;
    }

    /** @param array<int, float|int> $values */
    private function standardDeviation(array $values, float $mean): float
    {
        if (count($values) < 2) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($values as $value) {
            $sum += ((float)$value - $mean) ** 2;
        }
        return sqrt($sum / count($values));
    }

    private function roundMetric(string $metricKey, float $value): float|int
    {
        return match ($metricKey) {
            'ota_orders', 'ota_list_exposure', 'ota_detail_exposure', 'ota_order_submit' => (int)round($value),
            'ota_room_nights' => round($value, 1),
            default => round($value, 2),
        };
    }

    private function date(string $value, string $field): string
    {
        $value = trim($value);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$parsed || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $parsed->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("{$field} 必须为 YYYY-MM-DD 格式。");
        }
        return $value;
    }

    private function shiftDate(string $date, int $days): string
    {
        $modifier = ($days >= 0 ? '+' : '') . $days . ' days';
        return (new DateTimeImmutable($date))->modify($modifier)->format('Y-m-d');
    }

    private function tenantIdForHotel(int $hotelId): int
    {
        try {
            $tenantId = (int)Db::name('hotels')->where('id', $hotelId)->value('tenant_id');
        } catch (Throwable $e) {
            throw new RuntimeException('forecast tenant scope could not be resolved', 422, $e);
        }
        if ($tenantId <= 0) {
            throw new RuntimeException('forecast tenant scope is missing; tenant_id=0 is not permitted', 422);
        }
        return $tenantId;
    }

    /**
     * @param array<int, array<string, mixed>> $expectedRows
     * @param array<int, array<string, mixed>> $storedRows
     */
    private function forecastReadbackMatches(array $expectedRows, array $storedRows): bool
    {
        if (count($expectedRows) !== count($storedRows)) {
            return false;
        }
        $storedByKey = [];
        foreach ($storedRows as $row) {
            if (!is_array($row)) {
                return false;
            }
            $storedByKey[$this->forecastRowIdentity($row)] = $row;
        }
        foreach ($expectedRows as $expected) {
            $stored = $storedByKey[$this->forecastRowIdentity($expected)] ?? null;
            if (!is_array($stored)) {
                return false;
            }
            foreach ($expected as $field => $expectedValue) {
                if (!array_key_exists($field, $stored)
                    || !$this->forecastStoredValueMatches($stored[$field], $expectedValue)
                ) {
                    return false;
                }
            }
        }
        return true;
    }

    /** @param array<string, mixed> $row */
    private function forecastRowIdentity(array $row): string
    {
        return implode('|', [
            (string)($row['tenant_id'] ?? ''),
            (string)($row['system_hotel_id'] ?? ''),
            (string)($row['forecast_run_id'] ?? ''),
            (string)($row['metric_key'] ?? ''),
            (string)($row['target_date'] ?? ''),
            (string)($row['horizon_days'] ?? ''),
        ]);
    }

    private function forecastStoredValueMatches(mixed $stored, mixed $expected): bool
    {
        if ($expected === null) {
            return $stored === null;
        }
        if (is_int($expected)) {
            return is_numeric($stored) && (int)$stored === $expected;
        }
        if (is_float($expected)) {
            return is_numeric($stored) && abs((float)$stored - $expected) <= 0.005;
        }
        if (is_string($expected) && $expected !== '' && in_array($expected[0], ['{', '['], true)) {
            $expectedJson = json_decode($expected, true);
            $storedJson = is_string($stored) ? json_decode($stored, true) : null;
            if (is_array($expectedJson) && is_array($storedJson)) {
                return $storedJson == $expectedJson;
            }
        }
        return (string)$stored === (string)$expected;
    }

    private function tableExists(string $table): bool
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            return false;
        }
        try {
            Db::query('SELECT 1 FROM `' . $table . '` LIMIT 1');
            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
