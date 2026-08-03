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
    public const OPERATION_SOURCE_MODULE = 'temporal_forecast_recommendation';

    private const FORECAST_TABLE = 'temporal_forecast_snapshots';
    private const MODEL_VERSION = 'coarse_trend_v1';
    private const METHOD = 'weekday_recent_trend_interval';
    private const CONFIDENCE_TYPE = 'uncalibrated_rule_index';
    private const CONFIDENCE_SEMANTICS = '由样本覆盖、稳定性和新鲜度加权形成的规则指数，未经概率校准，不代表预测命中概率。';
    private const SOURCE_REFS_CONTRACT = 'temporal_metric_source.v1';
    private const ALL_OTA_EXPECTED_PLATFORMS = ['ctrip', 'meituan'];
    private const BACKTEST_LOOKBACK_DAYS = 90;
    private const MIN_OPERATIONAL_HISTORY_DAYS = 21;
    private const MIN_BACKTEST_SAMPLES_PER_COHORT = 10;
    private const MIN_RANGE_HIT_RATE_PERCENT = 60.0;

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
        $review = $this->reviewView($scope['ids'], $todayDate);
        $future = $this->futureView($scope['ids'], $todayDate, $futureDays, $review);

        return [
            'generated_at' => date('Y-m-d H:i:s'),
            'metric_scope' => 'ota_channel',
            'scope_note' => '仅反映已授权 OTA 渠道数据，不代表酒店全口径经营结果。',
            'confidence_type' => self::CONFIDENCE_TYPE,
            'confidence_semantics' => self::CONFIDENCE_SEMANTICS,
            'calibration_status' => 'not_calibrated',
            'operational_backtest_status' => (int)($review['eligible_cohort_count'] ?? 0) > 0
                ? 'empirical_backtest_available'
                : 'operational_conclusion_disabled',
            'operational_policy' => $this->operationalPolicy(),
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
                'operational_status' => 'disabled',
                'eligible_point_count' => 0,
                'operational_policy' => $this->operationalPolicy(),
                'confidence_type' => self::CONFIDENCE_TYPE,
                'confidence_semantics' => self::CONFIDENCE_SEMANTICS,
                'calibration_status' => 'not_calibrated',
            ];
        }

        $runId = 'tf_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(6)), 0, 12);
        $asOfTime = date('Y-m-d H:i:s');
        $tenantId = $this->tenantIdForHotel($hotelId);
        $metricMeta = [];
        foreach ($plan['metrics'] as $metric) {
            $metricMeta[(string)$metric['metric_key']] = $metric;
        }
        $metricQuality = is_array($history['metric_quality'] ?? null)
            ? $history['metric_quality']
            : [];

        $rows = [];
        foreach ($plan['points'] as $point) {
            $metricKey = (string)$point['metric_key'];
            $meta = $metricMeta[$metricKey] ?? [];
            $quality = is_array($metricQuality[$metricKey] ?? null)
                ? $metricQuality[$metricKey]
                : [];
            $sourceQualityStatus = $this->forecastSourceQualityStatus($quality);
            $sourceRefs = json_encode(
                $this->buildMetricForecastSourceRefs(
                    $metricKey,
                    $history,
                    $quality,
                    $sourceStart,
                    $sourceEnd
                ),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
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

        $review = $this->reviewView([$hotelId], $asOf);
        $shapedPoints = $this->shapeForecastRows($readbackRows, $review);
        $eligiblePointCount = 0;
        foreach ($shapedPoints as $day) {
            foreach ((array)($day['metrics'] ?? []) as $metric) {
                if (($metric['operational_gate']['can_submit_for_review'] ?? false) === true) {
                    $eligiblePointCount++;
                }
            }
        }

        return [
            'status' => 'generated',
            'message' => $eligiblePointCount > 0
                ? '预测版本已保存并回读；通过回测门槛的分组只能送人工审核。'
                : '预测版本已保存并回读，但样本、来源质量或命中率未过门槛，运营结论已停用。',
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
            'points' => $shapedPoints,
            'operational_status' => $eligiblePointCount > 0 ? 'human_review_only' : 'disabled',
            'eligible_point_count' => $eligiblePointCount,
            'data_quality' => $history['data_quality'] ?? [],
            'data_gaps' => $history['data_gaps'] ?? [],
            'confidence_type' => self::CONFIDENCE_TYPE,
            'confidence_semantics' => self::CONFIDENCE_SEMANTICS,
            'calibration_status' => 'not_calibrated',
            'operational_policy' => $this->operationalPolicy(),
            'boundary' => '仅提供趋势、区间与未校准规则置信指数，不生成执行价格，不自动写入 OTA。',
        ];
    }

    /**
     * Re-read the finalized actual for one immutable forecast point. This is
     * an observation/accuracy receipt, not proof that a manual action caused
     * the outcome.
     *
     * @return array<string, mixed>
     */
    public function forecastActualReadback(
        int $forecastPointId,
        int $hotelId,
        string $metricKey,
        string $targetDate
    ): array {
        $metricKey = strtolower(trim($metricKey));
        $targetDate = $this->date($targetDate, 'target_date');
        if ($forecastPointId <= 0
            || $hotelId <= 0
            || !array_key_exists($metricKey, self::METRICS)
            || !$this->tableExists(self::FORECAST_TABLE)
        ) {
            return [
                'status' => 'unavailable',
                'reason_code' => 'forecast_identity_invalid',
                'metric_key' => $metricKey,
                'target_date' => $targetDate,
            ];
        }

        $forecast = Db::name(self::FORECAST_TABLE)
            ->where('id', $forecastPointId)
            ->where('system_hotel_id', $hotelId)
            ->where('metric_key', $metricKey)
            ->where('target_date', $targetDate)
            ->find();
        if (!is_array($forecast)
            || (string)($forecast['metric_scope'] ?? '') !== 'ota_channel'
            || (string)($forecast['platform'] ?? '') !== 'all_ota'
        ) {
            return [
                'status' => 'unavailable',
                'reason_code' => 'forecast_identity_mismatch',
                'metric_key' => $metricKey,
                'target_date' => $targetDate,
            ];
        }
        if (!$this->forecastSourceRefsOperationallyVerified($forecast)) {
            return [
                'status' => 'unavailable',
                'reason_code' => 'forecast_source_contract_unverified',
                'forecast_point_id' => $forecastPointId,
                'metric_key' => $metricKey,
                'target_date' => $targetDate,
            ];
        }
        if ($targetDate >= date('Y-m-d')) {
            return [
                'status' => 'unavailable',
                'reason_code' => 'target_actual_not_final',
                'forecast_point_id' => $forecastPointId,
                'metric_key' => $metricKey,
                'target_date' => $targetDate,
            ];
        }

        $bundle = $this->loadPeriodFacts(
            [$hotelId],
            $targetDate,
            $targetDate,
            'historical_daily',
            true
        );
        return $this->buildForecastActualReadback($forecast, $bundle);
    }

    /**
     * @param array<string, mixed> $forecast
     * @param array<string, mixed> $bundle
     * @return array<string, mixed>
     */
    private function buildForecastActualReadback(array $forecast, array $bundle): array
    {
        $metricKey = strtolower(trim((string)($forecast['metric_key'] ?? '')));
        $targetDate = trim((string)($forecast['target_date'] ?? ''));
        $quality = is_array($bundle['metric_quality'][$metricKey] ?? null)
            ? $bundle['metric_quality'][$metricKey]
            : [];
        $actual = null;
        foreach (is_array($bundle['series'] ?? null) ? $bundle['series'] : [] as $day) {
            if (!is_array($day) || (string)($day['date'] ?? '') !== $targetDate) {
                continue;
            }
            $actual = is_numeric($day[$metricKey] ?? null) ? (float)$day[$metricKey] : null;
            break;
        }
        $rowIds = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): int => max(0, (int)$value),
            is_array($quality['row_ids'] ?? null) ? $quality['row_ids'] : []
        ))));
        sort($rowIds, SORT_NUMERIC);
        $readbackAt = trim((string)(
            $quality['latest_readback_at']
            ?? $bundle['latest_snapshot_time']
            ?? ''
        ));
        if ($actual === null
            || (string)($quality['quality_status'] ?? '') !== 'ready'
            || (string)($quality['platform_coverage_status'] ?? '') !== 'ready'
            || (string)($quality['freshness_status'] ?? '') !== 'current'
            || $rowIds === []
            || $readbackAt === ''
            || strtotime($readbackAt) === false
        ) {
            return [
                'status' => 'unavailable',
                'reason_code' => $actual === null
                    ? 'trusted_actual_missing'
                    : 'trusted_actual_quality_incomplete',
                'forecast_point_id' => (int)($forecast['id'] ?? 0),
                'metric_key' => $metricKey,
                'target_date' => $targetDate,
                'data_quality' => $quality,
            ];
        }

        $predicted = is_numeric($forecast['predicted_value'] ?? null)
            ? (float)$forecast['predicted_value']
            : null;
        $lower = is_numeric($forecast['lower_bound'] ?? null)
            ? (float)$forecast['lower_bound']
            : null;
        $upper = is_numeric($forecast['upper_bound'] ?? null)
            ? (float)$forecast['upper_bound']
            : null;
        if ($predicted === null || $lower === null || $upper === null) {
            return [
                'status' => 'unavailable',
                'reason_code' => 'forecast_interval_missing',
                'forecast_point_id' => (int)($forecast['id'] ?? 0),
                'metric_key' => $metricKey,
                'target_date' => $targetDate,
            ];
        }

        return [
            'status' => 'ready',
            'contract_version' => 'temporal_metric_actual.v1',
            'forecast_point_id' => (int)($forecast['id'] ?? 0),
            'forecast_run_id' => (string)($forecast['forecast_run_id'] ?? ''),
            'system_hotel_id' => (int)($forecast['system_hotel_id'] ?? 0),
            'metric_scope' => 'ota_channel',
            'metric_key' => $metricKey,
            'target_date' => $targetDate,
            'predicted_value' => $predicted,
            'lower_bound' => $lower,
            'upper_bound' => $upper,
            'actual_value' => $actual,
            'within_range' => $actual >= $lower && $actual <= $upper,
            'absolute_error' => $this->roundMetric($metricKey, abs($actual - $predicted)),
            'source_row_ids' => $rowIds,
            'readback_count' => (int)($quality['trusted_fact_rows'] ?? count($rowIds)),
            'readback_at' => date('Y-m-d H:i:s', (int)strtotime($readbackAt)),
            'data_quality' => $quality,
            'causality_claimed' => false,
            'effect_evidence_status' => 'observed_not_attributed',
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
    private function futureView(array $hotelIds, string $today, int $futureDays, array $review = []): array
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

        $series = $this->shapeForecastRows($rows, $review);
        $operationRecommendation = $this->buildOperationRecommendation($rows, $review);

        return [
            'status' => 'ready',
            'label' => '未来可观',
            'message' => ($operationRecommendation['can_submit_for_review'] ?? false) === true
                ? '该指标与周期已通过最低回测门槛，只能送人工审核，不提供执行价格。'
                : '趋势仅供观察；样本或命中率未通过运营门槛，不生成可执行结论。',
            'version' => [
                'forecast_run_id' => (string)$latest['forecast_run_id'],
                'as_of_date' => (string)$latest['as_of_date'],
                'as_of_time' => (string)$latest['as_of_time'],
                'model_version' => (string)$latest['model_version'],
                'source_start_date' => (string)($latest['source_start_date'] ?? ''),
                'source_end_date' => (string)($latest['source_end_date'] ?? ''),
            ],
            'series' => $series,
            'confidence_type' => self::CONFIDENCE_TYPE,
            'confidence_semantics' => self::CONFIDENCE_SEMANTICS,
            'calibration_status' => 'not_calibrated',
            'operational_backtest_status' => ($operationRecommendation['can_submit_for_review'] ?? false) === true
                ? 'empirical_backtest_available'
                : 'operational_conclusion_disabled',
            'operational_policy' => $this->operationalPolicy(),
            'operational_gate' => $operationRecommendation['operational_gate'] ?? [],
            'operation_recommendation' => $operationRecommendation,
            'boundary' => 'AI 只解释趋势证据与不确定性；运营建议必须人工审批后才生成任务，系统不自动调价或写入 OTA。',
        ];
    }

    /**
     * @param array<int, int> $hotelIds
     * @return array<string, mixed>
     */
    private function reviewView(array $hotelIds, string $today): array
    {
        if (count($hotelIds) !== 1) {
            return [
                'status' => 'select_hotel',
                'label' => '回看当时',
                'conclusion_status' => 'disabled',
                'policy' => $this->operationalPolicy(),
                'cohorts' => [],
                'items' => [],
            ];
        }
        if (!$this->tableExists(self::FORECAST_TABLE)) {
            return [
                'status' => 'not_initialized',
                'label' => '回看当时',
                'conclusion_status' => 'disabled',
                'policy' => $this->operationalPolicy(),
                'cohorts' => [],
                'items' => [],
            ];
        }

        $hotelId = $hotelIds[0];
        $yesterday = $this->shiftDate($today, -1);
        $reviewStart = $this->shiftDate($yesterday, -(self::BACKTEST_LOOKBACK_DAYS - 1));
        $forecasts = Db::name(self::FORECAST_TABLE)
            ->where('system_hotel_id', $hotelId)
            ->whereBetween('target_date', [$reviewStart, $yesterday])
            ->order('target_date', 'asc')
            ->order('metric_key', 'asc')
            ->order('horizon_days', 'asc')
            ->order('as_of_time', 'asc')
            ->order('id', 'asc')
            ->limit(10000)
            ->select()
            ->toArray();
        if ($forecasts === []) {
            return [
                'status' => 'empty',
                'label' => '回看当时',
                'conclusion_status' => 'disabled',
                'message' => sprintf('预测尚未到期，或最近 %d 天没有可复盘版本。', self::BACKTEST_LOOKBACK_DAYS),
                'policy' => $this->operationalPolicy(),
                'cohorts' => [],
                'items' => [],
            ];
        }

        $dates = array_map(static fn(array $row): string => (string)$row['target_date'], $forecasts);
        $actualBundle = $this->loadPeriodFacts([$hotelId], min($dates), max($dates), 'historical_daily', true);
        $review = $this->buildBacktestSummary(
            $forecasts,
            $actualBundle['series'] ?? [],
            $actualBundle['metric_quality'] ?? []
        );
        $review['status'] = (int)($review['matched_points'] ?? 0) > 0 ? 'ready' : 'waiting_actual';
        $review['label'] = '回看当时';
        $review['period'] = ['start_date' => $reviewStart, 'end_date' => $yesterday];
        $review['actual_data_quality'] = $actualBundle['data_quality'] ?? [];
        $review['actual_data_gaps'] = $actualBundle['data_gaps'] ?? [];
        $eligibleCohortCount = (int)($review['eligible_cohort_count'] ?? 0);
        $disabledCohortCount = (int)($review['disabled_cohort_count'] ?? 0);
        $review['message'] = (int)($review['matched_points'] ?? 0) > 0
            ? ($eligibleCohortCount > 0
                ? ($disabledCohortCount > 0
                    ? '各指标与预测周期独立回测；部分分组可送人工审核，其余分组继续停用。'
                    : '各指标与预测周期独立回测；通过门槛的分组只允许进入人工审核。')
                : '已有到期样本，但未通过独立回测门槛，运营结论已停用。')
            : '已有到期预测，但对应日期的定稿事实尚不完整。';

        return $review;
    }

    /**
     * Evaluate immutable forecast versions by metric and horizon. Repeated
     * regeneration on the same as-of date is deduplicated so it cannot inflate
     * the sample size.
     *
     * @param array<int, array<string, mixed>> $forecastRows
     * @param array<int, array<string, mixed>> $actualSeries
     * @param array<string, array<string, mixed>> $actualMetricQuality
     * @return array<string, mixed>
     */
    public function buildBacktestSummary(
        array $forecastRows,
        array $actualSeries,
        array $actualMetricQuality = []
    ): array
    {
        $actualByDate = [];
        foreach ($actualSeries as $item) {
            $date = trim((string)($item['date'] ?? ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
                $actualByDate[$date] = $item;
            }
        }

        $deduplicated = [];
        foreach ($forecastRows as $row) {
            $metricKey = trim((string)($row['metric_key'] ?? ''));
            $targetDate = trim((string)($row['target_date'] ?? ''));
            $asOfDate = trim((string)($row['as_of_date'] ?? ''));
            $horizonDays = (int)($row['horizon_days'] ?? 0);
            if (!array_key_exists($metricKey, self::METRICS)
                || preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate) !== 1
                || preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOfDate) !== 1
                || $horizonDays <= 0
            ) {
                continue;
            }
            $identity = implode('|', [$asOfDate, $targetDate, $metricKey, $horizonDays]);
            $existing = $deduplicated[$identity] ?? null;
            $version = sprintf(
                '%s|%020d',
                trim((string)($row['as_of_time'] ?? '')),
                (int)($row['id'] ?? 0)
            );
            $existingVersion = is_array($existing)
                ? sprintf(
                    '%s|%020d',
                    trim((string)($existing['as_of_time'] ?? '')),
                    (int)($existing['id'] ?? 0)
                )
                : '';
            $candidateTimingVerified = $this->forecastTimingOperationallyVerified($row);
            $existingTimingVerified = is_array($existing)
                && $this->forecastTimingOperationallyVerified($existing);
            if (!is_array($existing)
                || ($candidateTimingVerified && !$existingTimingVerified)
                || ($candidateTimingVerified === $existingTimingVerified
                    && strcmp($version, $existingVersion) >= 0)
            ) {
                $deduplicated[$identity] = $row;
            }
        }

        $rows = array_values($deduplicated);
        usort($rows, static function (array $left, array $right): int {
            return [
                (string)($left['metric_key'] ?? ''),
                (int)($left['horizon_days'] ?? 0),
                (string)($left['target_date'] ?? ''),
                (string)($left['as_of_date'] ?? ''),
            ] <=> [
                (string)($right['metric_key'] ?? ''),
                (int)($right['horizon_days'] ?? 0),
                (string)($right['target_date'] ?? ''),
                (string)($right['as_of_date'] ?? ''),
            ];
        });

        $items = [];
        $cohortStats = [];
        $matched = 0;
        $hits = 0;
        foreach ($rows as $forecast) {
            $date = (string)$forecast['target_date'];
            $metricKey = (string)$forecast['metric_key'];
            $horizonDays = (int)$forecast['horizon_days'];
            $cohortKey = $this->backtestCohortKey($metricKey, $horizonDays);
            $cohortStats[$cohortKey] ??= [
                'metric_key' => $metricKey,
                'horizon_days' => $horizonDays,
                'forecast_points' => 0,
                'matched_points' => 0,
                'missing_actual_points' => 0,
                'range_hits' => 0,
                'operational_matched_points' => 0,
                'operational_range_hits' => 0,
                'source_quality_excluded_points' => 0,
                'actual_quality_excluded_points' => 0,
                'absolute_error_total' => 0.0,
                'absolute_error_count' => 0,
                'absolute_percentage_error_total' => 0.0,
                'absolute_percentage_error_count' => 0,
            ];
            $cohortStats[$cohortKey]['forecast_points']++;
            $forecastSourceEligible = (int)($forecast['sample_days'] ?? 0) >= self::MIN_OPERATIONAL_HISTORY_DAYS
                && strtolower(trim((string)($forecast['data_quality_status'] ?? ''))) === 'ready'
                && $this->forecastSourceRefsOperationallyVerified($forecast);
            $actualOperationallyVerified = $this->actualMetricDateOperationallyVerified(
                $metricKey,
                $date,
                $actualMetricQuality
            );
            $operationalSampleEligible = $forecastSourceEligible && $actualOperationallyVerified;

            $actual = $actualByDate[$date][$metricKey] ?? null;
            $actual = is_numeric($actual) ? (float)$actual : null;
            $lower = is_numeric($forecast['lower_bound'] ?? null) ? (float)$forecast['lower_bound'] : null;
            $upper = is_numeric($forecast['upper_bound'] ?? null) ? (float)$forecast['upper_bound'] : null;
            $point = is_numeric($forecast['predicted_value'] ?? null) ? (float)$forecast['predicted_value'] : null;
            $withinRange = null;
            $outcome = '实际事实尚未定稿';
            if ($actual !== null && $lower !== null && $upper !== null) {
                $matched++;
                $cohortStats[$cohortKey]['matched_points']++;
                $withinRange = $actual >= $lower && $actual <= $upper;
                if ($withinRange) {
                    $hits++;
                    $cohortStats[$cohortKey]['range_hits']++;
                    $outcome = '实际落在当时预测区间';
                } elseif ($actual > $upper) {
                    $outcome = '实际高于当时预测区间';
                } else {
                    $outcome = '实际低于当时预测区间';
                }
                if ($operationalSampleEligible) {
                    $cohortStats[$cohortKey]['operational_matched_points']++;
                    if ($withinRange) {
                        $cohortStats[$cohortKey]['operational_range_hits']++;
                    }
                }
                if (!$forecastSourceEligible) {
                    $cohortStats[$cohortKey]['source_quality_excluded_points']++;
                }
                if (!$actualOperationallyVerified) {
                    $cohortStats[$cohortKey]['actual_quality_excluded_points']++;
                }
            } else {
                $cohortStats[$cohortKey]['missing_actual_points']++;
            }

            $absoluteError = $actual !== null && $point !== null ? abs($actual - $point) : null;
            $errorPercent = $absoluteError !== null && $actual !== 0.0
                ? $absoluteError / abs($actual) * 100
                : null;
            if ($operationalSampleEligible && $absoluteError !== null) {
                $cohortStats[$cohortKey]['absolute_error_total'] += $absoluteError;
                $cohortStats[$cohortKey]['absolute_error_count']++;
            }
            if ($operationalSampleEligible && $errorPercent !== null) {
                $cohortStats[$cohortKey]['absolute_percentage_error_total'] += $errorPercent;
                $cohortStats[$cohortKey]['absolute_percentage_error_count']++;
            }
            $items[] = [
                'forecast_point_id' => (int)($forecast['id'] ?? 0),
                'forecast_run_id' => (string)($forecast['forecast_run_id'] ?? ''),
                'as_of_date' => (string)($forecast['as_of_date'] ?? ''),
                'target_date' => $date,
                'metric_key' => $metricKey,
                'horizon_days' => $horizonDays,
                'forecast_interval' => ['lower' => $lower, 'upper' => $upper],
                'predicted_value' => $point,
                'actual_value' => $actual,
                'within_range' => $withinRange,
                'absolute_error' => $absoluteError !== null ? $this->roundMetric($metricKey, $absoluteError) : null,
                'error_percent' => $errorPercent !== null ? round($errorPercent, 1) : null,
                'forecast_source_operationally_verified' => $forecastSourceEligible,
                'actual_operationally_verified' => $actualOperationallyVerified,
                'operational_sample_eligible' => $operationalSampleEligible,
                'outcome' => $outcome,
            ];
        }

        $cohorts = [];
        $eligibleCohorts = 0;
        foreach ($cohortStats as $stats) {
            $diagnosticMatched = (int)$stats['matched_points'];
            $diagnosticRangeHitRate = $diagnosticMatched > 0
                ? round((int)$stats['range_hits'] / $diagnosticMatched * 100, 1)
                : null;
            $cohortMatched = (int)$stats['operational_matched_points'];
            $rangeHitRate = $cohortMatched > 0
                ? round((int)$stats['operational_range_hits'] / $cohortMatched * 100, 1)
                : null;
            $sampleSufficient = $cohortMatched >= self::MIN_BACKTEST_SAMPLES_PER_COHORT;
            $decisionStatus = 'disabled_insufficient_samples';
            $reasonCode = 'backtest_sample_lt_' . self::MIN_BACKTEST_SAMPLES_PER_COHORT;
            $reason = sprintf(
                '该指标与 T+%d 周期只有 %d 个来源合格的到期样本，至少需要 %d 个才启用运营结论。',
                (int)$stats['horizon_days'],
                $cohortMatched,
                self::MIN_BACKTEST_SAMPLES_PER_COHORT
            );
            if ($sampleSufficient && $rangeHitRate !== null && $rangeHitRate < self::MIN_RANGE_HIT_RATE_PERCENT) {
                $decisionStatus = 'disabled_low_interval_coverage';
                $reasonCode = 'range_hit_rate_below_' . (int)self::MIN_RANGE_HIT_RATE_PERCENT;
                $reason = sprintf(
                    '该指标与 T+%d 周期区间命中率为 %.1f%%，低于 %.1f%% 的内部运营门槛。',
                    (int)$stats['horizon_days'],
                    $rangeHitRate,
                    self::MIN_RANGE_HIT_RATE_PERCENT
                );
            } elseif ($sampleSufficient && $rangeHitRate !== null) {
                $decisionStatus = 'eligible_for_human_review';
                $reasonCode = '';
                $reason = '该分组通过最低样本和区间覆盖门槛，但仍需人工审核，不能自动执行。';
                $eligibleCohorts++;
            }

            $absoluteErrorCount = (int)$stats['absolute_error_count'];
            $percentageErrorCount = (int)$stats['absolute_percentage_error_count'];
            $cohorts[] = [
                'metric_key' => (string)$stats['metric_key'],
                'horizon_days' => (int)$stats['horizon_days'],
                'forecast_points' => (int)$stats['forecast_points'],
                'diagnostic_matched_points' => $diagnosticMatched,
                'diagnostic_range_hits' => (int)$stats['range_hits'],
                'diagnostic_range_hit_rate' => $diagnosticRangeHitRate,
                'matched_points' => $cohortMatched,
                'missing_actual_points' => (int)$stats['missing_actual_points'],
                'source_quality_excluded_points' => (int)$stats['source_quality_excluded_points'],
                'actual_quality_excluded_points' => (int)$stats['actual_quality_excluded_points'],
                'range_hits' => (int)$stats['operational_range_hits'],
                'range_hit_rate' => $rangeHitRate,
                'mean_absolute_error' => $absoluteErrorCount > 0
                    ? $this->roundMetric(
                        (string)$stats['metric_key'],
                        (float)$stats['absolute_error_total'] / $absoluteErrorCount
                    )
                    : null,
                'mean_absolute_percentage_error' => $percentageErrorCount > 0
                    ? round((float)$stats['absolute_percentage_error_total'] / $percentageErrorCount, 1)
                    : null,
                'sample_status' => $sampleSufficient ? 'sufficient' : 'insufficient',
                'decision_status' => $decisionStatus,
                'conclusion_enabled' => $decisionStatus === 'eligible_for_human_review',
                'reason_code' => $reasonCode,
                'reason' => $reason,
                'automatic_price_write' => false,
            ];
        }
        usort($cohorts, static fn(array $left, array $right): int =>
            array_search((string)$left['metric_key'], array_keys(self::METRICS), true)
                <=> array_search((string)$right['metric_key'], array_keys(self::METRICS), true)
            ?: (int)$left['horizon_days'] <=> (int)$right['horizon_days']
        );

        $cohortCount = count($cohorts);
        $conclusionStatus = $eligibleCohorts === 0
            ? 'disabled'
            : ($eligibleCohorts === $cohortCount ? 'eligible_for_human_review' : 'partial');

        return [
            'conclusion_status' => $conclusionStatus,
            'operational_use' => $eligibleCohorts > 0 ? 'human_review_only' : 'disabled',
            'policy' => $this->operationalPolicy(),
            'deduplicated_forecast_points' => count($rows),
            'matched_points' => $matched,
            'range_hits' => $hits,
            'range_hit_rate' => $matched > 0 ? round($hits / $matched * 100, 1) : null,
            'aggregate_is_diagnostic_only' => true,
            'eligible_cohort_count' => $eligibleCohorts,
            'disabled_cohort_count' => max(0, $cohortCount - $eligibleCohorts),
            'cohorts' => $cohorts,
            'items' => array_slice($items, -500),
            'automatic_price_write' => false,
        ];
    }

    /**
     * Create only a pending human-review intent. OperationManagementService
     * creates the task later, inside its explicit approval transaction.
     *
     * @param array<int, int|string> $permittedHotelIds Empty means super-admin scope.
     * @return array<string, mixed>
     */
    public function createOperationReviewIntent(
        int $forecastPointId,
        array $permittedHotelIds,
        int $userId
    ): array {
        if ($userId <= 0) {
            throw new InvalidArgumentException('缺少可回读的人工操作人身份，不能送审。');
        }
        if ($forecastPointId <= 0 || !$this->tableExists(self::FORECAST_TABLE)) {
            throw new InvalidArgumentException('预测点不存在或预测版本表尚未初始化。');
        }
        $row = Db::name(self::FORECAST_TABLE)->where('id', $forecastPointId)->find();
        if (!is_array($row)) {
            throw new RuntimeException('预测点不存在。', 404);
        }

        $hotelId = (int)($row['system_hotel_id'] ?? 0);
        $scope = array_values(array_unique(array_filter(
            array_map('intval', $permittedHotelIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($hotelId <= 0 || ($scope !== [] && !in_array($hotelId, $scope, true))) {
            throw new RuntimeException('无权把该预测建议送入运营审核。', 403);
        }

        $today = date('Y-m-d');
        if ((string)($row['target_date'] ?? '') <= $today) {
            throw new InvalidArgumentException('该预测已经到期，不能再生成新的运营审核意图。');
        }
        $review = $this->reviewView([$hotelId], $today);
        $recommendation = $this->buildOperationRecommendation([$row], $review);
        if (($recommendation['can_submit_for_review'] ?? false) !== true) {
            throw new InvalidArgumentException(
                (string)($recommendation['disabled_reason'] ?? '预测未通过运营回测门槛，已停用建议。')
            );
        }

        $targetDate = (string)$row['target_date'];
        $metricKey = (string)$row['metric_key'];
        $horizonDays = (int)$row['horizon_days'];
        $gate = is_array($recommendation['operational_gate'] ?? null)
            ? $recommendation['operational_gate']
            : [];
        $cohort = is_array($gate['backtest_cohort'] ?? null) ? $gate['backtest_cohort'] : [];
        $input = [
            'source_module' => self::OPERATION_SOURCE_MODULE,
            'source_record_id' => $forecastPointId,
            'hotel_id' => $hotelId,
            'platform' => 'all_ota',
            'object_type' => 'operation_checklist',
            'action_type' => 'manual_forecast_review',
            'date_start' => $today,
            'date_end' => $targetDate,
            'current_value' => [
                'forecast_run_id' => (string)$row['forecast_run_id'],
                'metric_key' => $metricKey,
                'horizon_days' => $horizonDays,
                'target_date' => $targetDate,
                'predicted_value' => is_numeric($row['predicted_value'] ?? null) ? (float)$row['predicted_value'] : null,
                'lower_bound' => is_numeric($row['lower_bound'] ?? null) ? (float)$row['lower_bound'] : null,
                'upper_bound' => is_numeric($row['upper_bound'] ?? null) ? (float)$row['upper_bound'] : null,
                'backtest_range_hit_rate' => $cohort['range_hit_rate'] ?? null,
                'backtest_samples' => (int)($cohort['matched_points'] ?? 0),
            ],
            'target_value' => [
                'title' => (string)$recommendation['title'],
                'action_text' => (string)$recommendation['action_text'],
                'target_metric' => $metricKey,
                'steps' => $recommendation['steps'],
                'acceptance_criteria' => $recommendation['acceptance_criteria'],
                'workflow_schedule' => [
                    'assignee_id' => $userId,
                    'due_at' => $targetDate . ' 20:00:00',
                    'review_at' => $this->shiftDate($targetDate, 1) . ' 10:00:00',
                    'source_policy' => 'manual_approval_then_manual_execution_evidence_then_next_day_review',
                ],
                'expected_delta_status' => 'not_quantified',
                'effect_measurement_policy' => 'next_day_target_actual_observation_without_causality_claim',
                'automatic_price_write' => false,
            ],
            'evidence' => [
                'evidence_refs' => [[
                    'table' => self::FORECAST_TABLE,
                    'row_id' => $forecastPointId,
                    'forecast_run_id' => (string)$row['forecast_run_id'],
                    'metric_scope' => (string)$row['metric_scope'],
                    'metric_key' => $metricKey,
                    'horizon_days' => $horizonDays,
                    'target_date' => $targetDate,
                ]],
                'backtest_cohort' => $cohort,
                'operational_gate' => $gate,
                'source_policy' => 'immutable_forecast_plus_metric_horizon_backtest',
                'expected_delta_status' => 'not_quantified',
                'protected_boundary' => 'Pending review only. Approval creates a manual operation checklist task; no OTA price or inventory write is performed.',
                'review_required' => true,
                'automatic_price_write' => false,
            ],
            'expected_metric' => $metricKey,
            'expected_delta' => 0,
            'risk_level' => 'medium',
        ];
        $idempotencyKey = sprintf(
            'temporal_forecast_review:%d:%s',
            $forecastPointId,
            substr(hash('sha256', (string)$row['forecast_run_id'] . '|' . $metricKey . '|' . $horizonDays), 0, 32)
        );
        $intent = (new OperationManagementService())->createExecutionIntent(
            [$hotelId],
            $hotelId,
            $input,
            $userId,
            false,
            $idempotencyKey,
            true
        );
        if ((string)($intent['status'] ?? '') !== 'pending_approval'
            || trim((string)($intent['blocked_reason'] ?? '')) !== ''
            || !empty($intent['tasks'])
        ) {
            throw new RuntimeException('预测建议未严格回读为待人工审核且无任务状态。');
        }

        return [
            'status' => 'pending_human_approval',
            'forecast_point_id' => $forecastPointId,
            'execution_intent' => $intent,
            'task_created' => false,
            'review_required' => true,
            'task_creation_policy' => 'operation_task_created_only_after_explicit_intent_approval',
            'automatic_price_write' => false,
        ];
    }

    /**
     * Re-check immutable source identity and the live backtest gate immediately
     * before OperationManagementService is allowed to approve the intent.
     *
     * @param array<string, mixed> $intent
     */
    public function assertOperationRecommendationIntentCurrent(array $intent): void
    {
        $forecastPointId = (int)($intent['source_record_id'] ?? 0);
        $hotelId = (int)($intent['hotel_id'] ?? 0);
        $row = $forecastPointId > 0 && $this->tableExists(self::FORECAST_TABLE)
            ? Db::name(self::FORECAST_TABLE)
                ->where('id', $forecastPointId)
                ->where('system_hotel_id', $hotelId)
                ->find()
            : null;
        if (!is_array($row)) {
            throw new InvalidArgumentException('预测建议来源已不存在，不能审批。');
        }
        if ((string)($row['target_date'] ?? '') <= date('Y-m-d')) {
            throw new InvalidArgumentException('预测建议已经到期，不能审批。');
        }

        $evidence = is_array($intent['evidence'] ?? null) ? $intent['evidence'] : [];
        $refs = array_values(array_filter(
            is_array($evidence['evidence_refs'] ?? null) ? $evidence['evidence_refs'] : [],
            'is_array'
        ));
        $ref = $refs[0] ?? [];
        $targetValue = is_array($intent['target_value'] ?? null) ? $intent['target_value'] : [];
        if ((string)($ref['table'] ?? '') !== self::FORECAST_TABLE
            || (int)($ref['row_id'] ?? 0) !== $forecastPointId
            || (string)($ref['forecast_run_id'] ?? '') !== (string)$row['forecast_run_id']
            || (string)($ref['metric_scope'] ?? '') !== (string)$row['metric_scope']
            || (string)($ref['metric_key'] ?? '') !== (string)$row['metric_key']
            || (int)($ref['horizon_days'] ?? 0) !== (int)$row['horizon_days']
            || (string)($ref['target_date'] ?? '') !== (string)$row['target_date']
            || ($evidence['review_required'] ?? null) !== true
            || ($evidence['automatic_price_write'] ?? null) !== false
            || ($targetValue['automatic_price_write'] ?? null) !== false
            || array_key_exists('target_price', $targetValue)
            || (string)($targetValue['target_metric'] ?? '') !== (string)$row['metric_key']
            || (string)($intent['platform'] ?? '') !== 'all_ota'
            || (string)($intent['object_type'] ?? '') !== 'operation_checklist'
            || (string)($intent['action_type'] ?? '') !== 'manual_forecast_review'
        ) {
            throw new InvalidArgumentException('预测建议来源身份或人工审核边界不一致，不能审批。');
        }

        $review = $this->reviewView([$hotelId], date('Y-m-d'));
        $gate = $this->forecastOperationalGate($row, $review);
        if (($gate['can_submit_for_review'] ?? false) !== true) {
            throw new InvalidArgumentException(
                (string)($gate['reason'] ?? '预测回测门槛已失效，不能审批。')
            );
        }
    }

    /** @return array<string, mixed> */
    private function operationalPolicy(): array
    {
        return [
            'backtest_grain' => 'metric_key+horizon_days',
            'backtest_lookback_days' => self::BACKTEST_LOOKBACK_DAYS,
            'minimum_history_days_per_metric' => self::MIN_OPERATIONAL_HISTORY_DAYS,
            'minimum_matured_samples_per_metric_horizon' => self::MIN_BACKTEST_SAMPLES_PER_COHORT,
            'minimum_range_hit_rate_percent' => self::MIN_RANGE_HIT_RATE_PERCENT,
            'all_ota_required_platforms' => self::ALL_OTA_EXPECTED_PLATFORMS,
            'insufficient_sample_action' => 'disable_operational_conclusion',
            'execution_mode' => 'human_review_only',
            'automatic_price_write' => false,
        ];
    }

    /**
     * Public read-only policy snapshot for a bounded pilot. The pilot service
     * may display this mature gate, but cannot lower or replace it.
     *
     * @return array<string, mixed>
     */
    public function matureOperationalPolicy(): array
    {
        return $this->operationalPolicy();
    }

    /**
     * Reuse the same strict source identity contract for pilot admission.
     * Fourteen history days are checked by the pilot service; this method only
     * verifies timing, all-OTA coverage, freshness, provenance and digest.
     *
     * @param array<string, mixed> $forecast
     */
    public function forecastSourceIdentityVerifiedForPilot(array $forecast): bool
    {
        return $this->forecastSourceRefsOperationallyVerified($forecast, true);
    }

    private function backtestCohortKey(string $metricKey, int $horizonDays): string
    {
        return $metricKey . '|T+' . $horizonDays;
    }

    /**
     * Intentional competitor-comparison exclusions do not lower the own-hotel
     * forecast source quality. Missing readback, provenance, or validation does.
     *
     * @param array<string, mixed> $quality
     */
    private function forecastSourceQualityStatus(array $quality): string
    {
        $trustedFacts = (int)(
            $quality['trusted_facts']
            ?? $quality['trusted_fact_rows']
            ?? 0
        );
        if ($trustedFacts <= 0) {
            return 'insufficient';
        }
        if ((int)($quality['rejected_rows'] ?? 0) > 0 || (int)($quality['trace_failures'] ?? 0) > 0) {
            return 'partial';
        }
        if (array_key_exists('platform_coverage_status', $quality)
            && (string)$quality['platform_coverage_status'] !== 'ready'
        ) {
            return 'partial';
        }
        if (array_key_exists('freshness_status', $quality)
            && !in_array((string)$quality['freshness_status'], ['current', 'not_assessed'], true)
        ) {
            return 'partial';
        }

        $reasonCounts = is_array($quality['excluded_fact_reason_counts'] ?? null)
            ? $quality['excluded_fact_reason_counts']
            : [];
        foreach ($reasonCounts as $reason => $count) {
            if ((int)$count <= 0 || str_starts_with((string)$reason, 'non_self_compare_type_')) {
                continue;
            }
            return 'partial';
        }

        return 'ready';
    }

    /** @param array<string, mixed> $forecast */
    private function forecastSourceRefsOperationallyVerified(
        array $forecast,
        bool $requireOperationalQuality = true
    ): bool
    {
        if (!$this->forecastTimingOperationallyVerified($forecast)) {
            return false;
        }

        $refs = $this->decodeArray($forecast['source_refs_json'] ?? []);
        $metricKey = strtolower(trim((string)($forecast['metric_key'] ?? '')));
        if ((string)($refs['contract_version'] ?? '') !== self::SOURCE_REFS_CONTRACT
            || !array_key_exists($metricKey, self::METRICS)
            || (string)($refs['metric_key'] ?? '') !== $metricKey
            || (string)($refs['fact_key'] ?? '') !== self::METRICS[$metricKey]
            || (string)($refs['table'] ?? '') !== 'online_daily_data'
            || (string)($refs['metric_scope'] ?? '') !== 'ota_channel'
            || (string)($refs['period'] ?? '') !== 'historical_daily'
            || (int)($refs['is_final'] ?? 0) !== 1
            || (int)($refs['trusted_fact_rows'] ?? 0) <= 0
        ) {
            return false;
        }

        $sourceStart = (string)($forecast['source_start_date'] ?? '');
        $sourceEnd = (string)($forecast['source_end_date'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $sourceStart) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $sourceEnd) !== 1
            || (string)($refs['start_date'] ?? '') !== $sourceStart
            || (string)($refs['end_date'] ?? '') !== $sourceEnd
        ) {
            return false;
        }

        $trustedDates = array_values(array_unique(array_filter(
            array_map('strval', is_array($refs['trusted_dates'] ?? null) ? $refs['trusted_dates'] : []),
            static fn(string $date): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1
        )));
        sort($trustedDates, SORT_STRING);
        $trustedDays = (int)($refs['trusted_days'] ?? 0);
        $sampleDays = (int)($forecast['sample_days'] ?? 0);
        if ($trustedDays <= 0
            || count($trustedDates) !== $trustedDays
            || $sampleDays !== $trustedDays
            || (string)($refs['latest_trusted_date'] ?? '') !== $trustedDates[$trustedDays - 1]
            || ($requireOperationalQuality && $trustedDates[$trustedDays - 1] !== $sourceEnd)
        ) {
            return false;
        }
        foreach ($trustedDates as $trustedDate) {
            if ($trustedDate < $sourceStart || $trustedDate > $sourceEnd) {
                return false;
            }
        }

        $trustedFacts = (int)$refs['trusted_fact_rows'];
        if (array_key_exists('fact_rows', $refs) && (int)$refs['fact_rows'] !== $trustedFacts) {
            return false;
        }
        $excludedFacts = max(0, (int)($refs['excluded_fact_rows'] ?? 0));
        $reasonCounts = is_array($refs['excluded_fact_reason_counts'] ?? null)
            ? $refs['excluded_fact_reason_counts']
            : [];
        $reasonTotal = 0;
        foreach ($reasonCounts as $count) {
            $reasonTotal += max(0, (int)$count);
        }
        if (($excludedFacts === 0 && $reasonTotal > 0)
            || ($excludedFacts > 0 && $reasonTotal < $excludedFacts)
        ) {
            return false;
        }

        $rowIds = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): int => max(0, (int)$value),
            is_array($refs['row_ids'] ?? null) ? $refs['row_ids'] : []
        ))));
        sort($rowIds, SORT_NUMERIC);
        $expectedPlatforms = $this->normalizedPlatformList($refs['expected_platforms'] ?? []);
        $observedPlatforms = $this->normalizedPlatformList($refs['observed_platforms'] ?? []);
        $platformsByDate = $this->normalizePlatformsByDate($refs['platforms_by_date'] ?? []);
        $coverageStatus = (string)($refs['platform_coverage_status'] ?? '');
        $coverageCompleteDays = 0;
        foreach ($platformsByDate as $platforms) {
            if ($platforms === []
                || array_diff($platforms, self::ALL_OTA_EXPECTED_PLATFORMS) !== []
            ) {
                return false;
            }
            if (array_diff(self::ALL_OTA_EXPECTED_PLATFORMS, $platforms) === []) {
                $coverageCompleteDays++;
            }
        }
        if ($rowIds === []
            || $expectedPlatforms !== self::ALL_OTA_EXPECTED_PLATFORMS
            || $observedPlatforms === []
            || !in_array($coverageStatus, ['ready', 'partial'], true)
            || (int)($refs['coverage_complete_days'] ?? -1) !== $coverageCompleteDays
            || array_keys($platformsByDate) !== $trustedDates
            || ($requireOperationalQuality && $coverageStatus !== 'ready')
            || ($requireOperationalQuality && $coverageCompleteDays !== $trustedDays)
        ) {
            return false;
        }
        $digest = trim((string)($refs['source_identity_digest'] ?? ''));
        if (preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1
            || !hash_equals(
                $this->metricSourceIdentityDigest(
                    $metricKey,
                    $trustedDates,
                    $rowIds,
                    $platformsByDate
                ),
                $digest
            )
        ) {
            return false;
        }

        if (!$requireOperationalQuality) {
            return true;
        }

        return $this->forecastSourceQualityStatus([
            'trusted_fact_rows' => $trustedFacts,
            'rejected_rows' => 0,
            'trace_failures' => (int)($refs['trace_failures'] ?? 0),
            'excluded_fact_reason_counts' => $reasonCounts,
            'platform_coverage_status' => (string)($refs['platform_coverage_status'] ?? ''),
            'freshness_status' => (string)($refs['freshness_status'] ?? ''),
        ]) === 'ready';
    }

    /** @param array<string, mixed> $forecast */
    private function forecastTimingOperationallyVerified(array $forecast): bool
    {
        $asOfDate = trim((string)($forecast['as_of_date'] ?? ''));
        $asOfTime = trim((string)($forecast['as_of_time'] ?? ''));
        $targetDate = trim((string)($forecast['target_date'] ?? ''));
        $sourceStart = trim((string)($forecast['source_start_date'] ?? ''));
        $sourceEnd = trim((string)($forecast['source_end_date'] ?? ''));
        $horizonDays = (int)($forecast['horizon_days'] ?? 0);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOfDate) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $asOfTime) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $sourceStart) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $sourceEnd) !== 1
            || $horizonDays <= 0
        ) {
            return false;
        }

        return substr($asOfTime, 0, 10) === $asOfDate
            && $targetDate === $this->shiftDate($asOfDate, $horizonDays)
            && $sourceEnd === $this->shiftDate($asOfDate, -1)
            && $sourceStart === $this->shiftDate($sourceEnd, -27);
    }

    /**
     * An operational backtest actual must be the finalized all-OTA fact for
     * the exact metric and target date. A trusted Ctrip-only or Meituan-only
     * value remains useful diagnostically, but cannot calibrate an all-OTA
     * forecast cohort.
     *
     * @param array<string, array<string, mixed>> $actualMetricQuality
     */
    private function actualMetricDateOperationallyVerified(
        string $metricKey,
        string $date,
        array $actualMetricQuality
    ): bool {
        $quality = is_array($actualMetricQuality[$metricKey] ?? null)
            ? $actualMetricQuality[$metricKey]
            : null;
        if ($quality === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return false;
        }

        $trustedDates = array_values(array_unique(array_filter(
            array_map('strval', is_array($quality['trusted_dates'] ?? null) ? $quality['trusted_dates'] : []),
            static fn(string $trustedDate): bool =>
                preg_match('/^\d{4}-\d{2}-\d{2}$/', $trustedDate) === 1
        )));
        $expectedPlatforms = $this->normalizedPlatformList($quality['expected_platforms'] ?? []);
        $platformsByDate = $this->normalizePlatformsByDate($quality['platforms_by_date'] ?? []);
        $observedPlatforms = $platformsByDate[$date] ?? [];

        return in_array($date, $trustedDates, true)
            && $expectedPlatforms === self::ALL_OTA_EXPECTED_PLATFORMS
            && array_diff(self::ALL_OTA_EXPECTED_PLATFORMS, $observedPlatforms) === [];
    }

    /**
     * A forecast point carries only the evidence for its own metric. This
     * prevents a trusted revenue row from making an unrelated traffic metric
     * look operationally verified (or vice versa).
     *
     * @param array<string, mixed> $history
     * @param array<string, mixed> $quality
     * @return array<string, mixed>
     */
    private function buildMetricForecastSourceRefs(
        string $metricKey,
        array $history,
        array $quality,
        string $sourceStart,
        string $sourceEnd
    ): array {
        $trustedDates = array_values(array_map(
            'strval',
            is_array($quality['trusted_dates'] ?? null) ? $quality['trusted_dates'] : []
        ));
        sort($trustedDates, SORT_STRING);
        $rowIds = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): int => max(0, (int)$value),
            is_array($quality['row_ids'] ?? null) ? $quality['row_ids'] : []
        ))));
        sort($rowIds, SORT_NUMERIC);
        $platformsByDate = $this->normalizePlatformsByDate($quality['platforms_by_date'] ?? []);

        return [
            'contract_version' => self::SOURCE_REFS_CONTRACT,
            'table' => 'online_daily_data',
            'metric_scope' => 'ota_channel',
            'period' => 'historical_daily',
            'is_final' => 1,
            'metric_key' => $metricKey,
            'fact_key' => self::METRICS[$metricKey] ?? '',
            'start_date' => $sourceStart,
            'end_date' => $sourceEnd,
            'source_rows' => (int)($history['source_row_count'] ?? 0),
            'source_fact_rows' => (int)($quality['source_fact_rows'] ?? 0),
            'fact_rows' => (int)($quality['trusted_fact_rows'] ?? 0),
            'trusted_fact_rows' => (int)($quality['trusted_fact_rows'] ?? 0),
            'trusted_days' => count($trustedDates),
            'trusted_dates' => $trustedDates,
            'latest_trusted_date' => (string)($quality['latest_trusted_date'] ?? ''),
            'excluded_fact_rows' => (int)($quality['excluded_fact_rows'] ?? 0),
            'excluded_fact_reason_counts' => is_array($quality['excluded_fact_reason_counts'] ?? null)
                ? $quality['excluded_fact_reason_counts']
                : [],
            'trace_failures' => (int)($quality['trace_failures'] ?? 0),
            'expected_platforms' => $this->normalizedPlatformList($quality['expected_platforms'] ?? []),
            'observed_platforms' => $this->normalizedPlatformList($quality['observed_platforms'] ?? []),
            'platforms_by_date' => $platformsByDate,
            'platform_coverage_status' => (string)($quality['platform_coverage_status'] ?? 'insufficient'),
            'coverage_complete_days' => (int)($quality['coverage_complete_days'] ?? 0),
            'freshness_status' => (string)($quality['freshness_status'] ?? 'stale'),
            'row_ids' => $rowIds,
            'source_identity_digest' => $this->metricSourceIdentityDigest(
                $metricKey,
                $trustedDates,
                $rowIds,
                $platformsByDate
            ),
        ];
    }

    /** @return array<int, string> */
    private function normalizedPlatformList(mixed $value): array
    {
        $platforms = [];
        foreach (is_array($value) ? $value : [] as $platform) {
            $platform = strtolower(trim((string)$platform));
            if ($platform !== '') {
                $platforms[$platform] = true;
            }
        }
        $result = array_values(array_keys($platforms));
        sort($result, SORT_STRING);
        return $result;
    }

    /** @return array<string, array<int, string>> */
    private function normalizePlatformsByDate(mixed $value): array
    {
        $result = [];
        foreach (is_array($value) ? $value : [] as $date => $platforms) {
            $date = trim((string)$date);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                continue;
            }
            $normalized = $this->normalizedPlatformList($platforms);
            if ($normalized !== []) {
                $result[$date] = $normalized;
            }
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    /**
     * @param array<int, string> $trustedDates
     * @param array<int, int> $rowIds
     * @param array<string, array<int, string>> $platformsByDate
     */
    private function metricSourceIdentityDigest(
        string $metricKey,
        array $trustedDates,
        array $rowIds,
        array $platformsByDate
    ): string {
        sort($trustedDates, SORT_STRING);
        sort($rowIds, SORT_NUMERIC);
        $platformsByDate = $this->normalizePlatformsByDate($platformsByDate);
        return hash('sha256', json_encode([
            'metric_key' => $metricKey,
            'trusted_dates' => array_values($trustedDates),
            'row_ids' => array_values($rowIds),
            'platforms_by_date' => $platformsByDate,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
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
            'metric_quality' => $bundle['metric_quality'] ?? [],
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
            // The temporal headline is a fact view, not a generic raw-data
            // rollup.  `business` rows can be dashboard widgets, rankings or
            // competitor snapshots whose overloaded amount/order columns are
            // not a hotel daily result.  A Ctrip daily business-overview row
            // is the explicit exception and is filtered below by endpoint,
            // section and normal validation before it can enter this fact view.
            ->whereIn('data_type', ['order', 'traffic', 'business']);
        if ($hotelIds !== []) {
            $query->whereIn('system_hotel_id', $hotelIds);
        }
        $rows = $query
            ->order('data_date', 'asc')
            ->order('id', 'asc')
            ->limit(250000)
            ->select()
            ->toArray();
        $operatingRows = $this->selectOperatingFactRows($rows);
        $rows = $operatingRows['rows'];
        $futureTargetRowsExcluded = $operatingRows['future_target_rows_excluded'];
        if ($rows === []) {
            $empty = $this->emptyFactBundle(
                $futureTargetRowsExcluded > 0 ? 'only_future_target_rows' : 'no_rows'
            );
            if ($futureTargetRowsExcluded > 0) {
                $empty['data_gaps'][] = [
                    'code' => 'future_target_rows_excluded_from_operating_period',
                    'period' => $period,
                    'count' => $futureTargetRowsExcluded,
                ];
                $empty['data_quality']['future_target_rows_excluded'] = $futureTargetRowsExcluded;
            }
            return $empty;
        }

        $dataset = $this->etl->buildDatasetFromRows($rows);
        $dailyFacts = is_array($dataset['fact_ota_daily'] ?? null) ? $dataset['fact_ota_daily'] : [];
        $trafficFacts = is_array($dataset['fact_ota_traffic'] ?? null) ? $dataset['fact_ota_traffic'] : [];
        $facts = array_merge($dailyFacts, $trafficFacts);
        $aggregated = $this->aggregateFacts($facts, $endDate);
        $quality = is_array($dataset['data_quality'] ?? null) ? $dataset['data_quality'] : [];
        $rejectedCount = is_array($quality['rejected_rows'] ?? null) ? count($quality['rejected_rows']) : 0;
        $traceFailures = (int)($aggregated['trace_failures'] ?? 0);
        $excludedFactCount = (int)($aggregated['excluded_fact_count'] ?? 0);
        $trustedFactCount = (int)($aggregated['trusted_fact_count'] ?? 0);
        $excludedReasonCounts = is_array($aggregated['excluded_fact_reason_counts'] ?? null)
            ? $aggregated['excluded_fact_reason_counts']
            : [];
        $dataGaps = is_array($aggregated['data_gaps'] ?? null) ? $aggregated['data_gaps'] : [];
        if ($futureTargetRowsExcluded > 0) {
            array_unshift($dataGaps, [
                'code' => 'future_target_rows_excluded_from_operating_period',
                'period' => $period,
                'count' => $futureTargetRowsExcluded,
            ]);
        }
        if ($rejectedCount > 0) {
            array_unshift($dataGaps, [
                'code' => 'etl_rows_rejected',
                'reason' => 'etl_validation_rejected',
                'count' => $rejectedCount,
            ]);
        }
        $metricQuality = is_array($aggregated['metric_quality'] ?? null)
            ? $aggregated['metric_quality']
            : [];
        foreach ($metricQuality as $metricKey => $metric) {
            if (!is_array($metric)) {
                continue;
            }
            $trustedDays = (int)($metric['trusted_days'] ?? 0);
            if ($trustedDays < self::MIN_OPERATIONAL_HISTORY_DAYS) {
                $dataGaps[] = [
                    'code' => 'metric_history_insufficient',
                    'metric_key' => (string)$metricKey,
                    'valid_days' => $trustedDays,
                    'required_days' => self::MIN_OPERATIONAL_HISTORY_DAYS,
                    'missing_days' => self::MIN_OPERATIONAL_HISTORY_DAYS - $trustedDays,
                ];
            }
            if ((string)($metric['platform_coverage_status'] ?? '') === 'partial') {
                $dataGaps[] = [
                    'code' => 'metric_platform_coverage_incomplete',
                    'metric_key' => (string)$metricKey,
                    'count' => count((array)($metric['incomplete_platform_dates'] ?? [])),
                    'dates' => array_values((array)($metric['incomplete_platform_dates'] ?? [])),
                ];
            }
            if ($trustedDays > 0 && (string)($metric['freshness_status'] ?? '') !== 'current') {
                $dataGaps[] = [
                    'code' => 'metric_latest_fact_stale',
                    'metric_key' => (string)$metricKey,
                    'latest_trusted_date' => (string)($metric['latest_trusted_date'] ?? ''),
                    'expected_end_date' => $endDate,
                ];
            }
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
            'metric_quality' => $metricQuality,
            'latest_snapshot_time' => $latestSnapshotTime,
            'data_gaps' => $dataGaps,
            'data_quality' => [
                'status' => $status,
                'canonical_rows' => (int)($quality['canonical_rows'] ?? count($rows)),
                'superseded_period_rows' => (int)($quality['superseded_period_rows'] ?? 0),
                'superseded_ctrip_checkout_rows' => (int)($quality['superseded_ctrip_checkout_rows'] ?? 0),
                'superseded_meituan_revenue_rows' => (int)($quality['superseded_meituan_revenue_rows'] ?? 0),
                'future_target_rows_excluded' => $futureTargetRowsExcluded,
                'rejected_rows' => $rejectedCount,
                'trace_failures' => $traceFailures,
                'source_facts' => count($facts),
                'trusted_facts' => $trustedFactCount,
                'excluded_facts' => $excludedFactCount,
                'excluded_fact_reason_counts' => $excludedReasonCounts,
                'metric_quality' => $metricQuality,
                'data_gaps' => $dataGaps,
                'missing_values_are_null' => true,
            ],
        ];
    }

    /**
     * Legacy target-date search rows can remain as non-final realtime versions
     * when an exact canonical future row already exists. They stay preserved
     * for evidence, but cannot become a current or historical operating fact.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array{rows:array<int, array<string, mixed>>,future_target_rows_excluded:int}
     */
    private function selectOperatingFactRows(array $rows): array
    {
        $selected = [];
        $excluded = 0;
        foreach ($rows as $row) {
            if (OnlineDailyDataPersistenceService::isFutureTargetRow($row)) {
                $excluded++;
                continue;
            }
            $selected[] = $row;
        }

        return [
            'rows' => $selected,
            'future_target_rows_excluded' => $excluded,
        ];
    }

    /** @return array<string, mixed> */
    private function decodeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<int, array<string, mixed>> $facts
     * @return array<string, mixed>
     */
    private function aggregateFacts(array $facts, ?string $expectedEndDate = null): array
    {
        $days = [];
        $sourceRowIds = [];
        $traceFailures = 0;
        $trustedFactCount = 0;
        $excludedFactCount = 0;
        $excludedReasonCounts = [];
        $metricQuality = [];
        foreach (self::METRICS as $metricKey => $factKey) {
            $metricQuality[$metricKey] = [
                'metric_key' => $metricKey,
                'fact_key' => $factKey,
                'source_fact_rows' => 0,
                'trusted_fact_rows' => 0,
                'excluded_fact_rows' => 0,
                'trace_failures' => 0,
                'excluded_fact_reason_counts' => [],
                '_trusted_dates' => [],
                '_expected_platforms' => array_fill_keys(self::ALL_OTA_EXPECTED_PLATFORMS, true),
                '_observed_platforms' => [],
                '_platforms_by_date' => [],
                '_row_ids' => [],
                '_latest_readback_at' => '',
            ];
        }

        foreach ($facts as $fact) {
            if (!is_array($fact)) {
                continue;
            }
            $trace = is_array($fact['source_trace'] ?? null) ? $fact['source_trace'] : [];
            $platform = strtolower(trim((string)($fact['platform_key'] ?? '')));
            $rowId = is_numeric($trace['row_id'] ?? null) ? (int)$trace['row_id'] : 0;
            $compareTypeExclusion = $this->compareTypeExclusionReason($fact);
            $metricValues = [];
            foreach (self::METRICS as $metricKey => $factKey) {
                if (is_numeric($fact[$factKey] ?? null)) {
                    $metricValues[$metricKey] = (float)$fact[$factKey];
                    $metricQuality[$metricKey]['source_fact_rows']++;
                }
            }

            if (($trace['saved_success'] ?? false) !== true) {
                $reasons = $this->traceFailureReasonCodes($trace);
                $traceFailures++;
                $excludedFactCount++;
                foreach ($reasons as $reason) {
                    $excludedReasonCounts[$reason] = (int)($excludedReasonCounts[$reason] ?? 0) + 1;
                }
                foreach (array_keys($metricValues) as $metricKey) {
                    $this->recordMetricFactExclusion($metricQuality[$metricKey], $reasons, true);
                }
                continue;
            }

            if ($compareTypeExclusion !== null) {
                $excludedFactCount++;
                $excludedReasonCounts[$compareTypeExclusion] = (int)($excludedReasonCounts[$compareTypeExclusion] ?? 0) + 1;
                foreach (array_keys($metricValues) as $metricKey) {
                    $this->recordMetricFactExclusion(
                        $metricQuality[$metricKey],
                        [$compareTypeExclusion],
                        false
                    );
                }
                continue;
            }

            $date = (string)($fact['date_key'] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                $excludedFactCount++;
                $excludedReasonCounts['date_key_invalid'] = (int)($excludedReasonCounts['date_key_invalid'] ?? 0) + 1;
                foreach (array_keys($metricValues) as $metricKey) {
                    $this->recordMetricFactExclusion(
                        $metricQuality[$metricKey],
                        ['date_key_invalid'],
                        false
                    );
                }
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
            foreach ($metricValues as $metricKey => $value) {
                $days[$date][$metricKey] = ($days[$date][$metricKey] ?? 0.0) + $value;
                $days[$date]['_counts'][$metricKey]++;
                $metricQuality[$metricKey]['trusted_fact_rows']++;
                $metricQuality[$metricKey]['_trusted_dates'][$date] = true;
                if ($platform !== '') {
                    $metricQuality[$metricKey]['_observed_platforms'][$platform] = true;
                    $metricQuality[$metricKey]['_platforms_by_date'][$date][$platform] = true;
                }
                if ($rowId > 0) {
                    $metricQuality[$metricKey]['_row_ids'][$rowId] = true;
                }
                foreach (['updated_at', 'collected_at'] as $timestampField) {
                    $timestamp = trim((string)($trace[$timestampField] ?? ''));
                    if ($timestamp !== ''
                        && strcmp($timestamp, (string)$metricQuality[$metricKey]['_latest_readback_at']) > 0
                    ) {
                        $metricQuality[$metricKey]['_latest_readback_at'] = $timestamp;
                    }
                }
            }
            if ($platform !== '') {
                $days[$date]['_platforms'][$platform] = true;
            }
            if ($rowId > 0) {
                $sourceRowIds[$rowId] = true;
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
            sort($day['platforms'], SORT_STRING);
            unset($day['_counts'], $day['_platforms']);
            $series[] = $day;
        }

        foreach ($metricQuality as $metricKey => $quality) {
            $metricQuality[$metricKey] = $this->finalizeMetricQuality(
                $quality,
                $expectedEndDate
            );
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

        $sourceRowIds = array_values(array_keys($sourceRowIds));
        sort($sourceRowIds, SORT_NUMERIC);
        return [
            'series' => $series,
            'source_row_ids' => $sourceRowIds,
            'trace_failures' => $traceFailures,
            'trusted_fact_count' => $trustedFactCount,
            'excluded_fact_count' => $excludedFactCount,
            'excluded_fact_reason_counts' => $excludedReasonCounts,
            'metric_quality' => $metricQuality,
            'data_gaps' => $dataGaps,
        ];
    }

    /**
     * @param array<string, mixed> $quality
     * @param array<int, string> $reasons
     */
    private function recordMetricFactExclusion(array &$quality, array $reasons, bool $traceFailure): void
    {
        $quality['excluded_fact_rows'] = (int)($quality['excluded_fact_rows'] ?? 0) + 1;
        if ($traceFailure) {
            $quality['trace_failures'] = (int)($quality['trace_failures'] ?? 0) + 1;
        }
        foreach ($reasons as $reason) {
            $reason = trim((string)$reason);
            if ($reason === '') {
                continue;
            }
            $quality['excluded_fact_reason_counts'][$reason] =
                (int)($quality['excluded_fact_reason_counts'][$reason] ?? 0) + 1;
        }
    }

    /**
     * @param array<string, mixed> $quality
     * @return array<string, mixed>
     */
    private function finalizeMetricQuality(array $quality, ?string $expectedEndDate): array
    {
        $trustedDates = array_values(array_keys((array)($quality['_trusted_dates'] ?? [])));
        sort($trustedDates, SORT_STRING);
        $expectedPlatforms = array_values(array_keys((array)($quality['_expected_platforms'] ?? [])));
        sort($expectedPlatforms, SORT_STRING);
        $observedPlatforms = array_values(array_keys((array)($quality['_observed_platforms'] ?? [])));
        sort($observedPlatforms, SORT_STRING);
        if ($expectedPlatforms === []) {
            $expectedPlatforms = $observedPlatforms;
        }

        $platformsByDate = [];
        foreach ((array)($quality['_platforms_by_date'] ?? []) as $date => $platforms) {
            $platformsByDate[(string)$date] = array_values(array_keys((array)$platforms));
            sort($platformsByDate[(string)$date], SORT_STRING);
        }
        ksort($platformsByDate, SORT_STRING);
        $incompleteDates = [];
        foreach ($trustedDates as $date) {
            if ($expectedPlatforms === []
                || array_diff($expectedPlatforms, $platformsByDate[$date] ?? []) !== []
            ) {
                $incompleteDates[] = $date;
            }
        }
        $coverageCompleteDays = count($trustedDates) - count($incompleteDates);
        $coverageStatus = $trustedDates === []
            ? 'insufficient'
            : ($incompleteDates === [] ? 'ready' : 'partial');
        $latestTrustedDate = $trustedDates !== [] ? $trustedDates[count($trustedDates) - 1] : '';
        $expectedEndDate = trim((string)$expectedEndDate);
        $freshnessStatus = preg_match('/^\d{4}-\d{2}-\d{2}$/', $expectedEndDate) === 1
            ? ($latestTrustedDate === $expectedEndDate ? 'current' : 'stale')
            : 'not_assessed';
        $rowIds = array_values(array_keys((array)($quality['_row_ids'] ?? [])));
        sort($rowIds, SORT_NUMERIC);
        $reasonCounts = is_array($quality['excluded_fact_reason_counts'] ?? null)
            ? $quality['excluded_fact_reason_counts']
            : [];
        ksort($reasonCounts, SORT_STRING);
        $latestReadbackAt = trim((string)($quality['_latest_readback_at'] ?? ''));

        unset(
            $quality['_trusted_dates'],
            $quality['_expected_platforms'],
            $quality['_observed_platforms'],
            $quality['_platforms_by_date'],
            $quality['_row_ids'],
            $quality['_latest_readback_at']
        );
        $quality['trusted_days'] = count($trustedDates);
        $quality['trusted_dates'] = $trustedDates;
        $quality['latest_trusted_date'] = $latestTrustedDate;
        $quality['required_operational_days'] = self::MIN_OPERATIONAL_HISTORY_DAYS;
        $quality['missing_operational_days'] = max(
            0,
            self::MIN_OPERATIONAL_HISTORY_DAYS - count($trustedDates)
        );
        $quality['expected_platforms'] = $expectedPlatforms;
        $quality['observed_platforms'] = $observedPlatforms;
        $quality['platforms_by_date'] = $platformsByDate;
        $quality['platform_coverage_status'] = $coverageStatus;
        $quality['coverage_complete_days'] = $coverageCompleteDays;
        $quality['incomplete_platform_dates'] = $incompleteDates;
        $quality['freshness_status'] = $freshnessStatus;
        $quality['row_ids'] = $rowIds;
        $quality['latest_readback_at'] = $latestReadbackAt;
        $quality['excluded_fact_reason_counts'] = $reasonCounts;
        $quality['quality_status'] = $this->forecastSourceQualityStatus($quality);
        return $quality;
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
            $previous = count($values) >= 14 ? array_slice($values, -14, 7) : [];
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
    private function shapeForecastRows(array $rows, array $review = []): array
    {
        $dates = [];
        foreach ($rows as $row) {
            $date = (string)$row['target_date'];
            $metricKey = (string)$row['metric_key'];
            $dates[$date] ??= ['date' => $date, 'metrics' => []];
            $operationalGate = $this->forecastOperationalGate($row, $review);
            $dates[$date]['metrics'][$metricKey] = [
                'forecast_point_id' => (int)($row['id'] ?? 0),
                'forecast_run_id' => (string)($row['forecast_run_id'] ?? ''),
                'horizon_days' => (int)($row['horizon_days'] ?? 0),
                'direction' => (string)$row['predicted_direction'],
                'predicted_value' => is_numeric($row['predicted_value'] ?? null) ? (float)$row['predicted_value'] : null,
                'lower_bound' => is_numeric($row['lower_bound'] ?? null) ? (float)$row['lower_bound'] : null,
                'upper_bound' => is_numeric($row['upper_bound'] ?? null) ? (float)$row['upper_bound'] : null,
                'confidence_score' => is_numeric($row['confidence_score'] ?? null) ? (float)$row['confidence_score'] : null,
                'confidence_level' => (string)$row['confidence_level'],
                'confidence_type' => self::CONFIDENCE_TYPE,
                'confidence_semantics' => self::CONFIDENCE_SEMANTICS,
                'sample_days' => (int)($row['sample_days'] ?? 0),
                'data_quality_status' => (string)$row['data_quality_status'],
                'operational_gate' => $operationalGate,
            ];
        }
        ksort($dates);
        return array_values($dates);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function buildOperationRecommendation(array $rows, array $review): array
    {
        if ($rows === []) {
            return [
                'status' => 'disabled',
                'can_submit_for_review' => false,
                'disabled_reason' => '当前没有预测点，不能生成运营建议。',
                'review_required' => true,
                'task_creation_policy' => 'operation_task_created_only_after_explicit_intent_approval',
                'automatic_price_write' => false,
            ];
        }

        $candidates = [];
        $metricPriority = array_flip(array_keys(self::METRICS));
        foreach ($rows as $row) {
            $gate = $this->forecastOperationalGate($row, $review);
            $candidates[] = [
                'row' => $row,
                'gate' => $gate,
                'eligible_rank' => ($gate['can_submit_for_review'] ?? false) === true ? 0 : 1,
                'metric_rank' => $metricPriority[(string)($row['metric_key'] ?? '')] ?? 999,
            ];
        }
        usort($candidates, static fn(array $left, array $right): int =>
            [
                (int)$left['eligible_rank'],
                (int)$left['metric_rank'],
                (int)($left['row']['horizon_days'] ?? 0),
                (string)($left['row']['target_date'] ?? ''),
            ] <=> [
                (int)$right['eligible_rank'],
                (int)$right['metric_rank'],
                (int)($right['row']['horizon_days'] ?? 0),
                (string)($right['row']['target_date'] ?? ''),
            ]
        );
        $candidate = $candidates[0];
        $row = $candidate['row'];
        $gate = $candidate['gate'];
        $metricKey = (string)$row['metric_key'];
        $horizonDays = (int)$row['horizon_days'];
        $targetDate = (string)$row['target_date'];
        $metricLabel = [
            'ota_revenue' => 'OTA收入',
            'ota_orders' => 'OTA订单',
            'ota_room_nights' => 'OTA间夜',
            'ota_list_exposure' => '列表曝光',
            'ota_detail_exposure' => '详情访问',
            'ota_order_submit' => '订单提交',
        ][$metricKey] ?? $metricKey;
        $canSubmit = ($gate['can_submit_for_review'] ?? false) === true;

        return [
            'status' => $canSubmit ? 'ready_for_human_review' : 'disabled',
            'title' => sprintf('%s T+%d 预测运营核查', $metricLabel, $horizonDays),
            'action_text' => sprintf(
                '在 %s 前人工核查%s预测信号、来源状态和可控运营因素；只记录人工动作，不自动调价。',
                $targetDate,
                $metricLabel
            ),
            'forecast_point_id' => (int)($row['id'] ?? 0),
            'forecast_run_id' => (string)($row['forecast_run_id'] ?? ''),
            'metric_key' => $metricKey,
            'horizon_days' => $horizonDays,
            'target_date' => $targetDate,
            'can_submit_for_review' => $canSubmit,
            'disabled_reason' => $canSubmit ? '' : (string)($gate['reason'] ?? '预测未通过运营门槛。'),
            'operational_gate' => $gate,
            'steps' => [
                '确认当前酒店、OTA渠道、目标日期与数据库回读状态一致。',
                '人工判断是否需要调整活动、库存展示或页面运营；禁止系统自动调价。',
                '记录实际执行动作、执行人、时间和截图或备注证据。',
                '次日在同酒店、同渠道、同指标口径下补充效果证据并复盘。',
            ],
            'acceptance_criteria' => [
                '人工审批通过后才生成运营任务。',
                '执行记录包含实际动作与证据，不把建议写成已执行。',
                '次日效果证据保持同酒店、同渠道、同指标口径。',
                '无任何自动价格或 OTA 写回。',
            ],
            'review_required' => true,
            'task_creation_policy' => 'operation_task_created_only_after_explicit_intent_approval',
            'automatic_price_write' => false,
        ];
    }

    /**
     * @param array<string, mixed> $forecast
     * @param array<string, mixed> $review
     * @return array<string, mixed>
     */
    private function forecastOperationalGate(array $forecast, array $review): array
    {
        $metricKey = (string)($forecast['metric_key'] ?? '');
        $horizonDays = (int)($forecast['horizon_days'] ?? 0);
        $sampleDays = (int)($forecast['sample_days'] ?? 0);
        $dataQualityStatus = strtolower(trim((string)($forecast['data_quality_status'] ?? 'insufficient')));
        $cohort = [];
        foreach (array_values(array_filter(
            is_array($review['cohorts'] ?? null) ? $review['cohorts'] : [],
            'is_array'
        )) as $item) {
            if ((string)($item['metric_key'] ?? '') === $metricKey
                && (int)($item['horizon_days'] ?? 0) === $horizonDays
            ) {
                $cohort = $item;
                break;
            }
        }

        $reasonCodes = [];
        $reasons = [];
        if ($sampleDays < self::MIN_OPERATIONAL_HISTORY_DAYS) {
            $reasonCodes[] = 'history_sample_lt_' . self::MIN_OPERATIONAL_HISTORY_DAYS;
            $reasons[] = sprintf(
                '该指标只有 %d 个有效历史日，至少需要 %d 个。',
                $sampleDays,
                self::MIN_OPERATIONAL_HISTORY_DAYS
            );
        }
        if ($dataQualityStatus !== 'ready') {
            $reasonCodes[] = 'forecast_source_quality_not_ready';
            $reasons[] = '预测来源质量尚未 ready；未回读、来源身份缺失或校验失败的事实不能进入运营结论。';
        }
        if (!$this->forecastSourceRefsOperationallyVerified($forecast)) {
            $reasonCodes[] = 'forecast_source_identity_not_verified';
            $reasons[] = '预测点的指标来源、实时生成时点或周期身份未通过验证，不能借用同分组结论。';
        }
        $matchedPoints = (int)($cohort['matched_points'] ?? 0);
        if ($matchedPoints < self::MIN_BACKTEST_SAMPLES_PER_COHORT) {
            $reasonCodes[] = 'backtest_sample_lt_' . self::MIN_BACKTEST_SAMPLES_PER_COHORT;
            $reasons[] = sprintf(
                '该指标与 T+%d 周期只有 %d 个到期样本，至少需要 %d 个。',
                $horizonDays,
                $matchedPoints,
                self::MIN_BACKTEST_SAMPLES_PER_COHORT
            );
        } elseif (!is_numeric($cohort['range_hit_rate'] ?? null)
            || (float)$cohort['range_hit_rate'] < self::MIN_RANGE_HIT_RATE_PERCENT
        ) {
            $reasonCodes[] = 'range_hit_rate_below_' . (int)self::MIN_RANGE_HIT_RATE_PERCENT;
            $reasons[] = sprintf(
                '该指标与 T+%d 周期区间命中率未达到 %.1f%% 的内部运营门槛。',
                $horizonDays,
                self::MIN_RANGE_HIT_RATE_PERCENT
            );
        }

        $canSubmit = $reasonCodes === [];
        $status = $canSubmit
            ? 'eligible_for_human_review'
            : (in_array('range_hit_rate_below_' . (int)self::MIN_RANGE_HIT_RATE_PERCENT, $reasonCodes, true)
                ? 'disabled_low_reliability'
                : 'disabled_insufficient_evidence');

        return [
            'status' => $status,
            'conclusion_enabled' => $canSubmit,
            'can_submit_for_review' => $canSubmit,
            'reason_codes' => $reasonCodes,
            'reason' => $canSubmit
                ? '该指标与周期通过最低证据门槛；只允许进入人工审核。'
                : implode(' ', $reasons),
            'history_sample_days' => $sampleDays,
            'source_data_quality_status' => $dataQualityStatus,
            'backtest_cohort' => $cohort,
            'empirical_reliability_percent' => $matchedPoints >= self::MIN_BACKTEST_SAMPLES_PER_COHORT
                && is_numeric($cohort['range_hit_rate'] ?? null)
                ? (float)$cohort['range_hit_rate']
                : null,
            'policy' => $this->operationalPolicy(),
            'review_required' => true,
            'automatic_price_write' => false,
        ];
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
        $metricQuality = [];
        $dataGaps = [];
        foreach (self::METRICS as $metricKey => $factKey) {
            $metricQuality[$metricKey] = [
                'metric_key' => $metricKey,
                'fact_key' => $factKey,
                'source_fact_rows' => 0,
                'trusted_fact_rows' => 0,
                'excluded_fact_rows' => 0,
                'trace_failures' => 0,
                'excluded_fact_reason_counts' => [],
                'trusted_days' => 0,
                'trusted_dates' => [],
                'latest_trusted_date' => '',
                'required_operational_days' => self::MIN_OPERATIONAL_HISTORY_DAYS,
                'missing_operational_days' => self::MIN_OPERATIONAL_HISTORY_DAYS,
                'expected_platforms' => [],
                'observed_platforms' => [],
                'platforms_by_date' => [],
                'platform_coverage_status' => 'insufficient',
                'coverage_complete_days' => 0,
                'incomplete_platform_dates' => [],
                'freshness_status' => 'stale',
                'row_ids' => [],
                'latest_readback_at' => '',
                'quality_status' => 'insufficient',
            ];
            $dataGaps[] = [
                'code' => 'metric_history_insufficient',
                'metric_key' => $metricKey,
                'valid_days' => 0,
                'required_days' => self::MIN_OPERATIONAL_HISTORY_DAYS,
                'missing_days' => self::MIN_OPERATIONAL_HISTORY_DAYS,
            ];
        }
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
            'metric_quality' => $metricQuality,
            'latest_snapshot_time' => null,
            'data_gaps' => $dataGaps,
            'data_quality' => [
                'status' => 'empty',
                'source_facts' => 0,
                'trusted_facts' => 0,
                'excluded_facts' => 0,
                'excluded_fact_reason_counts' => [],
                'metric_quality' => $metricQuality,
                'data_gaps' => $dataGaps,
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
