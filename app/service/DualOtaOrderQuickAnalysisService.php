<?php
declare(strict_types=1);

namespace app\service;

use Closure;
use DateTimeImmutable;
use RuntimeException;
use think\facade\Db;

/**
 * Read-only, tenant-scoped quick analysis for Ctrip and Meituan order facts.
 *
 * Order-flow rows are intentionally retained as a separate Meituan signal.
 * They never enter realised OTA revenue, room-night, order or ADR metrics.
 */
final class DualOtaOrderQuickAnalysisService
{
    public const CONTRACT_VERSION = 'dual_ota_order_quick_analysis.v1';

    private const MAX_RANGE_DAYS = 1096;
    private const MAX_SCOPED_ROWS = 5000;

    /** @var array<string, array{total_key:string,trust_key:string,unit:string}> */
    private const METRIC_MAP = [
        'orders' => [
            'total_key' => 'order_count',
            'trust_key' => 'totals.order_count',
            'unit' => 'orders',
        ],
        'room_nights' => [
            'total_key' => 'room_nights',
            'trust_key' => 'totals.room_nights',
            'unit' => 'room_nights',
        ],
        'revenue' => [
            'total_key' => 'room_revenue',
            'trust_key' => 'totals.room_revenue',
            'unit' => 'CNY',
        ],
        'adr' => [
            'total_key' => 'adr',
            'trust_key' => 'totals.adr',
            'unit' => 'CNY',
        ],
        'cancellation_rate' => [
            'total_key' => 'cancellation_rate',
            'trust_key' => 'totals.cancellation_rate',
            'unit' => '%',
        ],
    ];

    private OtaStandardEtlService $etl;
    private OtaRevenueMetricService $metricService;
    private ?Closure $rowProvider;
    private ?Closure $ctripAnalysisProvider;

    public function __construct(
        ?OtaStandardEtlService $etl = null,
        ?OtaRevenueMetricService $metricService = null,
        ?callable $rowProvider = null,
        ?callable $ctripAnalysisProvider = null
    ) {
        $this->etl = $etl ?? new OtaStandardEtlService();
        $this->metricService = $metricService ?? new OtaRevenueMetricService();
        $this->rowProvider = $rowProvider !== null
            ? Closure::fromCallable($rowProvider)
            : null;
        $this->ctripAnalysisProvider = $ctripAnalysisProvider !== null
            ? Closure::fromCallable($ctripAnalysisProvider)
            : null;
    }

    /**
     * @param array<string, mixed> $hotel
     * @return array<string, mixed>
     */
    public function analyze(
        int $systemHotelId,
        int $tenantId,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        array $hotel = []
    ): array {
        if ($systemHotelId <= 0 || $tenantId <= 0) {
            throw new RuntimeException('双平台订单快析缺少有效的酒店或租户范围。', 422);
        }

        [$dateFrom, $dateTo] = $this->validatedRange($dateFrom, $dateTo);
        $requestedFrom = $dateFrom;
        $requestedTo = $dateTo;
        $selectionMode = $dateFrom !== null
            ? 'explicit'
            : 'latest_available_30_days';

        if ($this->rowProvider !== null) {
            $provided = ($this->rowProvider)(
                $tenantId,
                $systemHotelId,
                $dateFrom,
                $dateTo
            );
            $rows = $this->arrayRows($provided);
            if ($dateFrom === null) {
                $rows = $this->scopedRows(
                    $rows,
                    $tenantId,
                    $systemHotelId,
                    null,
                    null
                );
                $rows = $this->dualPlatformRows($rows);
                [$dateFrom, $dateTo] = $this->latestThirtyDayRange($rows);
            }
        } else {
            if ($dateFrom === null) {
                [$dateFrom, $dateTo] = $this->latestThirtyDayRangeFromDatabase(
                    $tenantId,
                    $systemHotelId
                );
            }
            $rows = $dateFrom !== null
                ? $this->loadRows($tenantId, $systemHotelId, $dateFrom, (string)$dateTo)
                : [];
        }

        $rows = $this->scopedRows(
            $rows,
            $tenantId,
            $systemHotelId,
            $dateFrom,
            $dateTo
        );
        if (count($rows) > self::MAX_SCOPED_ROWS) {
            throw new RuntimeException(
                '双平台订单快析数据超过安全窗口，请缩小日期范围。',
                422
            );
        }
        $ctrip = $this->platformAnalysis(
            'ctrip',
            $this->platformRows($rows, 'ctrip')
        );
        $ctrip['deep_analysis'] = $this->ctripDeepAnalysis(
            $systemHotelId,
            $tenantId,
            $dateFrom,
            $dateTo,
            $this->platformRows($rows, 'ctrip')
        );
        $ctripDeepDateBasis = $this->normalizeDateBasis((string)(
            $ctrip['deep_analysis']['data']['date_range']['basis']
            ?? ''
        ));
        if ($ctripDeepDateBasis !== 'unknown') {
            $ctrip['comparison_basis']['date_basis'] = $ctripDeepDateBasis;
            $ctrip['comparison_basis']['date_basis_source'] =
                'ctrip_deep_analysis.date_range.basis';
        }

        $meituanRows = $this->platformRows($rows, 'meituan');
        $meituan = $this->platformAnalysis(
            'meituan',
            $meituanRows
        );
        $meituan['order_flow'] = $this->meituanOrderFlow(
            (array)($meituan['_dataset']['fact_ota_order_flow'] ?? []),
            $this->strictEvidence($meituanRows, 'order_flow')
        );

        $comparison = $this->comparison($ctrip, $meituan);
        $status = $this->overallStatus($ctrip, $meituan, $comparison);
        $actions = $this->actions($ctrip, $meituan);

        unset($ctrip['_dataset'], $ctrip['_metrics']);
        unset($meituan['_dataset'], $meituan['_metrics']);

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'metric_scope' => 'ota_channel',
            'status' => $status,
            'generated_at' => date('Y-m-d H:i:s'),
            'hotel' => [
                'id' => $systemHotelId,
                'name' => trim((string)($hotel['name'] ?? '')),
            ],
            'date_range' => [
                'from' => $dateFrom,
                'to' => $dateTo,
                'requested_from' => $requestedFrom,
                'requested_to' => $requestedTo,
                'selection_mode' => $selectionMode,
                'selection_reason' => $dateFrom !== null
                    ? ($selectionMode === 'explicit'
                        ? 'explicit_request_range'
                        : 'latest_scoped_order_or_order_flow_date_rolling_30_days')
                    : 'latest_scoped_order_or_order_flow_date_missing',
            ],
            'platforms' => [
                'ctrip' => $ctrip,
                'meituan' => $meituan,
            ],
            'comparison' => $comparison,
            'actions' => $actions,
            'scope_statement' => '仅分析当前租户、当前酒店、所列日期内已保存的携程/美团 OTA 渠道订单事实；不代表全酒店经营数据。',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function platformAnalysis(string $platform, array $rows): array
    {
        $dataset = $this->etl->buildDatasetFromRows($rows);
        $metrics = $this->metricService->summarizeDataset($dataset);
        $dailyFacts = $this->arrayRows($dataset['fact_ota_daily'] ?? []);
        $dateKeys = $this->dateKeys($dailyFacts);
        $strictOrderEvidence = $this->strictEvidence($rows, 'order');
        $representationEvidence = $this->representationEvidence($dailyFacts);
        $metricRows = [];
        foreach (self::METRIC_MAP as $key => $definition) {
            $metricRows[$key] = $this->metricEnvelope(
                $metrics,
                $definition['total_key'],
                $definition['trust_key'],
                $definition['unit'],
                $strictOrderEvidence,
                $representationEvidence
            );
            $metricRows[$key]['label'] = match ($key) {
                'orders' => 'OTA 订单数',
                'room_nights' => 'OTA 间夜',
                'revenue' => 'OTA 房费收入',
                'adr' => 'OTA ADR',
                'cancellation_rate' => 'OTA 订单取消率',
            };
            $metricRows[$key]['metric_scope'] = 'ota_channel';
        }

        $allVerified = $metricRows !== [] && count(array_filter(
            $metricRows,
            static fn(array $metric): bool => $metric['status'] !== 'verified'
        )) === 0;
        $status = $dailyFacts === []
            ? 'missing'
            : ($allVerified ? 'verified' : 'partial');

        return [
            'platform' => $platform,
            'metric_scope' => 'ota_channel',
            'status' => $status,
            'quality_label' => 'OTA 渠道口径',
            'date_keys' => $dateKeys,
            'latest_data_date' => $dateKeys !== []
                ? $dateKeys[count($dateKeys) - 1]
                : null,
            'date_range' => [
                'from' => $dateKeys[0] ?? null,
                'to' => $dateKeys !== [] ? $dateKeys[count($dateKeys) - 1] : null,
            ],
            'metrics' => $metricRows,
            'data_gaps' => array_values(array_map(
                static fn(string $key, array $metric): array => [
                    'key' => $key,
                    'status' => $metric['status'],
                    'reason' => $metric['reason'],
                ],
                array_keys(array_filter(
                    $metricRows,
                    static fn(array $metric): bool => $metric['status'] !== 'verified'
                )),
                array_values(array_filter(
                    $metricRows,
                    static fn(array $metric): bool => $metric['status'] !== 'verified'
                ))
            )),
            'evidence' => [
                'source_table' => 'online_daily_data',
                'order_fact_rows' => count($dailyFacts),
                'order_flow_fact_rows' => count($this->arrayRows($dataset['fact_ota_order_flow'] ?? [])),
                'accepted_rows' => (int)($dataset['data_quality']['accepted_rows'] ?? 0),
                'trusted_rows' => (int)($dataset['data_quality']['trusted_rows'] ?? 0),
                'untrusted_rows' => (int)($dataset['data_quality']['untrusted_rows'] ?? 0),
                'strict_order_evidence' => $strictOrderEvidence,
                'representation_evidence' => $representationEvidence,
            ],
            'comparison_basis' => $this->comparisonBasis(
                $dailyFacts,
                $metrics,
                $representationEvidence
            ),
            '_dataset' => $dataset,
            '_metrics' => $metrics,
        ];
    }

    /**
     * @param array<string, mixed> $metrics
     * @return array<string, mixed>
     */
    private function metricEnvelope(
        array $metrics,
        string $totalKey,
        string $trustKey,
        string $unit,
        array $strictEvidence,
        array $representationEvidence
    ): array {
        $value = $metrics['totals'][$totalKey] ?? null;
        $hasValue = $value !== null && is_numeric($value);
        $trust = is_array($metrics['metric_trust'][$trustKey] ?? null)
            ? $metrics['metric_trust'][$trustKey]
            : [];
        $savedSuccess = ($trust['saved_success'] ?? false) === true;
        $failureReasons = $this->safeFailureReasonCodes(
            $trust['failure_reasons']
            ?? []
        );

        if (($representationEvidence['conflict'] ?? false) === true) {
            $status = 'missing';
            $reasonCode = 'representation_conflict';
            $value = null;
        } elseif (!$hasValue) {
            $status = 'missing';
            $reasonCode = $failureReasons[0] ?? 'metric_value_missing';
            $value = null;
        } elseif ($savedSuccess && ($strictEvidence['complete'] ?? false) === true) {
            $status = 'verified';
            $reasonCode = 'saved_success';
            $value = $this->normalizedNumber($value);
        } else {
            $status = 'available_unverified';
            $reasonCode = ($strictEvidence['complete'] ?? false) !== true
                ? (string)($strictEvidence['failure_reasons'][0] ?? 'strict_fact_gate_incomplete')
                : ($failureReasons[0] ?? 'metric_source_unverified');
            $value = $this->normalizedNumber($value);
        }

        return [
            'value' => $value,
            'status' => $status,
            'reason' => $this->reasonText($reasonCode),
            'reason_code' => $reasonCode,
            'unit' => $unit,
            'metric_key' => $trustKey,
            'source_trust' => $this->safeTrustEnvelope($trust),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $dailyFacts
     * @param array<string, mixed> $metrics
     * @return array<string, mixed>
     */
    private function comparisonBasis(
        array $dailyFacts,
        array $metrics,
        array $representationEvidence
    ): array
    {
        $calculationBases = $this->factValues($dailyFacts, 'calculation_basis');
        $semanticScopes = $this->factValues($dailyFacts, 'metric_semantic_scope');
        $roomRevenueBases = $this->factValues($dailyFacts, 'room_revenue_basis');
        $orderCountBases = $this->factValues($dailyFacts, 'order_count_basis');
        $roomNightsBases = $this->factValues($dailyFacts, 'room_nights_basis');
        $recordKinds = $this->factValues($dailyFacts, 'record_kind');
        $dataTypes = $this->factValues($dailyFacts, 'data_type');
        $metricDefinitions = [];
        foreach (self::METRIC_MAP as $key => $definition) {
            $trust = is_array($metrics['metric_trust'][$definition['trust_key']] ?? null)
                ? $metrics['metric_trust'][$definition['trust_key']]
                : [];
            $metricDefinitions[$key] = trim((string)($trust['caliber'] ?? ''));
        }

        return [
            'metric_scope' => 'ota_channel',
            'date_basis' => $this->factDateBasis($dailyFacts),
            'date_basis_source' => 'fact_ota_daily.raw_data',
            'data_types' => $dataTypes,
            'calculation_bases' => $calculationBases,
            'metric_semantic_scopes' => $semanticScopes,
            'room_revenue_bases' => $roomRevenueBases,
            'order_count_bases' => $orderCountBases,
            'room_nights_bases' => $roomNightsBases,
            'record_kinds' => $recordKinds,
            'representation_conflict' =>
                ($representationEvidence['conflict'] ?? false) === true,
            'cancellation_rate_basis' => $metrics['totals']['cancellation_rate_basis'] ?? null,
            'metric_definitions' => $metricDefinitions,
        ];
    }

    /**
     * @param array<string, mixed> $ctrip
     * @param array<string, mixed> $meituan
     * @return array<string, mixed>
     */
    private function comparison(array $ctrip, array $meituan): array
    {
        $ctripHasOrders = (int)($ctrip['evidence']['order_fact_rows'] ?? 0) > 0;
        $meituanHasOrders = (int)($meituan['evidence']['order_fact_rows'] ?? 0) > 0;
        $ctripDates = $this->stringList($ctrip['date_keys'] ?? []);
        $meituanDates = $this->stringList($meituan['date_keys'] ?? []);
        $sameDates = $ctripDates !== [] && $ctripDates === $meituanDates;
        $ctripDateBasis = trim((string)(
            $ctrip['comparison_basis']['date_basis']
            ?? 'unknown'
        ));
        $meituanDateBasis = trim((string)(
            $meituan['comparison_basis']['date_basis']
            ?? 'unknown'
        ));
        $dateBasisKnown = !in_array(
            $ctripDateBasis,
            ['', 'unknown', 'mixed'],
            true
        ) && !in_array(
            $meituanDateBasis,
            ['', 'unknown', 'mixed'],
            true
        );
        $scopeCompatible = $this->sameString(
            $ctrip['comparison_basis']['metric_scope'] ?? null,
            $meituan['comparison_basis']['metric_scope'] ?? null
        ) && $dateBasisKnown
            && $ctripDateBasis === $meituanDateBasis;
        $representationConflict = ($ctrip['comparison_basis']['representation_conflict'] ?? false) === true
            || ($meituan['comparison_basis']['representation_conflict'] ?? false) === true;

        if (!$ctripHasOrders || !$meituanHasOrders) {
            $status = 'blocked_by_missing_platform';
            $reasonCode = !$ctripHasOrders && !$meituanHasOrders
                ? 'both_platform_order_facts_missing'
                : (!$ctripHasOrders
                    ? 'ctrip_order_facts_missing'
                    : 'meituan_order_facts_missing');
        } elseif ($representationConflict) {
            $status = 'blocked_by_incomparable_scope';
            $reasonCode = 'platform_order_representation_conflict';
        } elseif (!$sameDates || !$scopeCompatible) {
            $status = 'blocked_by_incomparable_scope';
            $reasonCode = !$sameDates
                ? 'platform_order_date_sets_differ'
                : (!$dateBasisKnown
                    ? 'platform_date_basis_unknown'
                    : 'platform_metric_scope_or_date_basis_differs');
        } else {
            $status = 'ready';
            $reasonCode = 'same_verified_ota_scope_and_dates';
        }

        $metricComparisons = [];
        $readyCount = 0;
        foreach (array_keys(self::METRIC_MAP) as $metricKey) {
            $row = $this->metricComparison(
                $metricKey,
                $ctrip,
                $meituan,
                $status,
                $reasonCode
            );
            if ($row['status'] === 'ready') {
                $readyCount++;
            }
            $metricComparisons[$metricKey] = $row;
        }

        if ($status === 'ready' && $readyCount === 0) {
            $status = 'blocked_by_incomparable_scope';
            $reasonCode = 'no_joint_verified_comparable_metrics';
        }

        return [
            'status' => $status,
            'can_compare' => $status === 'ready',
            'reason' => $this->reasonText($reasonCode),
            'reason_code' => $reasonCode,
            'metric_scope' => 'ota_channel',
            'date_keys' => $sameDates ? $ctripDates : [],
            'platform_date_keys' => [
                'ctrip' => $ctripDates,
                'meituan' => $meituanDates,
            ],
            'leader_basis' => 'higher_numeric_value_only_not_performance_judgment',
            'metrics' => $metricComparisons,
        ];
    }

    /**
     * @param array<string, mixed> $ctrip
     * @param array<string, mixed> $meituan
     * @return array<string, mixed>
     */
    private function metricComparison(
        string $metricKey,
        array $ctrip,
        array $meituan,
        string $comparisonStatus,
        string $comparisonReasonCode
    ): array {
        $left = is_array($ctrip['metrics'][$metricKey] ?? null)
            ? $ctrip['metrics'][$metricKey]
            : [];
        $right = is_array($meituan['metrics'][$metricKey] ?? null)
            ? $meituan['metrics'][$metricKey]
            : [];

        if ($comparisonStatus !== 'ready') {
            return $this->blockedMetricComparison(
                $comparisonStatus,
                $comparisonReasonCode,
                (string)($left['unit'] ?? $right['unit'] ?? '')
            );
        }
        if (($left['status'] ?? 'missing') === 'missing'
            || ($right['status'] ?? 'missing') === 'missing'
        ) {
            return $this->blockedMetricComparison(
                'blocked_by_missing_platform',
                'metric_missing_on_one_or_both_platforms',
                (string)($left['unit'] ?? $right['unit'] ?? '')
            );
        }
        if (($left['status'] ?? '') !== 'verified'
            || ($right['status'] ?? '') !== 'verified'
        ) {
            return $this->blockedMetricComparison(
                'blocked_by_incomparable_scope',
                'metric_not_verified_on_both_platforms',
                (string)($left['unit'] ?? $right['unit'] ?? '')
            );
        }
        if (!$this->metricBasisCompatible($metricKey, $ctrip, $meituan)) {
            return $this->blockedMetricComparison(
                'blocked_by_incomparable_scope',
                'metric_definition_or_fact_basis_differs',
                (string)($left['unit'] ?? $right['unit'] ?? '')
            );
        }

        $leftValue = (float)$left['value'];
        $rightValue = (float)$right['value'];
        $delta = round($leftValue - $rightValue, 4);
        $leader = abs($delta) < 0.000001
            ? 'equal'
            : ($delta > 0 ? 'ctrip' : 'meituan');

        return [
            'status' => 'ready',
            'reason' => $this->reasonText('both_platform_metrics_verified_and_comparable'),
            'reason_code' => 'both_platform_metrics_verified_and_comparable',
            'ctrip_value' => $this->normalizedNumber($leftValue),
            'meituan_value' => $this->normalizedNumber($rightValue),
            'delta' => $this->normalizedNumber($delta),
            'leader' => $leader,
            'unit' => (string)($left['unit'] ?? $right['unit'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $ctrip
     * @param array<string, mixed> $meituan
     */
    private function metricBasisCompatible(
        string $metricKey,
        array $ctrip,
        array $meituan
    ): bool {
        $left = is_array($ctrip['comparison_basis'] ?? null)
            ? $ctrip['comparison_basis']
            : [];
        $right = is_array($meituan['comparison_basis'] ?? null)
            ? $meituan['comparison_basis']
            : [];
        if (($left['representation_conflict'] ?? false) === true
            || ($right['representation_conflict'] ?? false) === true
            || !$this->singleKnownBasisCompatible(
                $left['record_kinds'] ?? [],
                $right['record_kinds'] ?? []
            )
        ) {
            return false;
        }
        foreach ([
            'metric_scope',
            'date_basis',
            'data_types',
            'calculation_bases',
            'metric_semantic_scopes',
        ] as $key) {
            if (($left[$key] ?? null) !== ($right[$key] ?? null)) {
                return false;
            }
        }
        if (($left['metric_definitions'][$metricKey] ?? null)
            !== ($right['metric_definitions'][$metricKey] ?? null)
        ) {
            return false;
        }
        if ($metricKey === 'orders'
            && !$this->singleKnownBasisCompatible(
                $left['order_count_bases'] ?? [],
                $right['order_count_bases'] ?? []
            )
        ) {
            return false;
        }
        if (in_array($metricKey, ['room_nights', 'adr'], true)
            && !$this->singleKnownBasisCompatible(
                $left['room_nights_bases'] ?? [],
                $right['room_nights_bases'] ?? []
            )
        ) {
            return false;
        }
        if (in_array($metricKey, ['revenue', 'adr'], true)
            && ($left['room_revenue_bases'] ?? null)
                !== ($right['room_revenue_bases'] ?? null)
        ) {
            return false;
        }
        return $metricKey !== 'cancellation_rate'
            || ($left['cancellation_rate_basis'] ?? null)
                === ($right['cancellation_rate_basis'] ?? null);
    }

    private function singleKnownBasisCompatible(mixed $left, mixed $right): bool
    {
        $leftValues = $this->stringList($left);
        $rightValues = $this->stringList($right);
        return count($leftValues) === 1
            && count($rightValues) === 1
            && !in_array($leftValues[0], ['unknown', 'mixed'], true)
            && $leftValues === $rightValues;
    }

    /** @return array<string, mixed> */
    private function blockedMetricComparison(
        string $status,
        string $reason,
        string $unit
    ): array {
        return [
            'status' => $status,
            'reason' => $this->reasonText($reason),
            'reason_code' => $reason,
            'ctrip_value' => null,
            'meituan_value' => null,
            'delta' => null,
            'leader' => null,
            'unit' => $unit,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $facts
     * @return array<string, mixed>
     */
    private function meituanOrderFlow(array $facts, array $strictEvidence): array
    {
        $summaryRows = array_values(array_filter(
            $facts,
            static fn(array $fact): bool => strtolower(trim((string)($fact['row_type'] ?? ''))) === 'summary'
                && in_array(strtolower(trim((string)($fact['direction'] ?? ''))), ['loss', 'inflow'], true)
        ));
        if ($summaryRows === []) {
            return [
                'status' => 'missing',
                'reason' => $this->reasonText('meituan_order_flow_summary_missing'),
                'reason_code' => 'meituan_order_flow_summary_missing',
                'metric_scope' => 'ota_channel_order_flow',
                'calculation_basis' => 'ota_order_flow_non_revenue_fact',
                'period' => null,
                'period_start' => null,
                'period_end' => null,
                'loss' => $this->emptyFlowDirection(),
                'inflow' => $this->emptyFlowDirection(),
                'source_trust' => [
                    'saved_success' => false,
                    'failure_reasons' => ['source_rows_missing'],
                ],
            ];
        }

        $groups = [];
        foreach ($summaryRows as $fact) {
            $key = implode('|', [
                trim((string)($fact['period'] ?? '')),
                trim((string)($fact['period_start'] ?? '')),
                trim((string)($fact['period_end'] ?? '')),
            ]);
            $groups[$key][] = $fact;
        }
        uasort($groups, function (array $left, array $right): int {
            return $this->orderFlowGroupTimestamp($right)
                <=> $this->orderFlowGroupTimestamp($left);
        });
        $selected = (array)reset($groups);
        $directions = [];
        $completeDirections = true;
        foreach (['loss', 'inflow'] as $direction) {
            $candidates = array_values(array_filter(
                $selected,
                static fn(array $fact): bool => strtolower(trim((string)($fact['direction'] ?? ''))) === $direction
            ));
            usort($candidates, fn(array $left, array $right): int =>
                $this->orderFlowFactTimestamp($right)
                <=> $this->orderFlowFactTimestamp($left));
            if ($candidates === []) {
                $completeDirections = false;
            }
            $directions[$direction] = $candidates !== []
                ? $this->flowDirection($candidates[0])
                : $this->emptyFlowDirection();
        }

        $traces = array_values(array_filter(array_map(
            static fn(array $fact): mixed => $fact['source_trace'] ?? null,
            $selected
        ), 'is_array'));
        $failureReasons = [];
        $allSaved = $traces !== [];
        foreach ($traces as $trace) {
            if (($trace['saved_success'] ?? false) === true) {
                continue;
            }
            $allSaved = false;
            $failureReasons = array_merge(
                $failureReasons,
                $this->safeFailureReasonCodes(
                    $trace['failure_reasons']
                    ?? []
                )
            );
        }
        $failureReasons = array_values(array_unique($failureReasons));
        if (!$completeDirections) {
            $failureReasons[] = 'order_flow_direction_missing';
        }
        $strictComplete = ($strictEvidence['complete'] ?? false) === true;
        $flowVerified = $allSaved && $completeDirections && $strictComplete;
        $flowReasonCode = $flowVerified
            ? 'saved_success'
            : (!$strictComplete
                ? (string)($strictEvidence['failure_reasons'][0] ?? 'strict_fact_gate_incomplete')
                : ($failureReasons[0] ?? 'order_flow_source_unverified'));
        $sample = $selected[0] ?? [];

        return [
            'status' => $flowVerified ? 'verified' : 'available_unverified',
            'reason' => $this->reasonText($flowReasonCode),
            'reason_code' => $flowReasonCode,
            'metric_scope' => 'ota_channel_order_flow',
            'calculation_basis' => 'ota_order_flow_non_revenue_fact',
            'period' => trim((string)($sample['period'] ?? '')) ?: null,
            'period_start' => trim((string)($sample['period_start'] ?? '')) ?: null,
            'period_end' => trim((string)($sample['period_end'] ?? '')) ?: null,
            'loss' => $directions['loss'],
            'inflow' => $directions['inflow'],
            'source_trust' => [
                'saved_success' => $flowVerified,
                'row_count' => count($traces),
                'failure_reasons' => $failureReasons,
                'strict_evidence' => $strictEvidence,
            ],
        ];
    }

    /** @param array<string, mixed> $fact */
    private function flowDirection(array $fact): array
    {
        return [
            'orders' => $this->nullableNormalizedNumber($fact['flow_order_count'] ?? null),
            'room_nights' => $this->nullableNormalizedNumber($fact['flow_room_nights'] ?? null),
            'amount' => $this->nullableNormalizedNumber($fact['flow_amount'] ?? null),
            'ratio' => $this->nullableNormalizedNumber($fact['flow_ratio'] ?? null),
        ];
    }

    /** @return array{orders:null,room_nights:null,amount:null,ratio:null} */
    private function emptyFlowDirection(): array
    {
        return [
            'orders' => null,
            'room_nights' => null,
            'amount' => null,
            'ratio' => null,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $ctripRows
     * @return array<string, mixed>
     */
    private function ctripDeepAnalysis(
        int $systemHotelId,
        int $tenantId,
        ?string $dateFrom,
        ?string $dateTo,
        array $ctripRows
    ): array {
        try {
            $analysis = $this->ctripAnalysisProvider !== null
                ? ($this->ctripAnalysisProvider)(
                    $systemHotelId,
                    $tenantId,
                    $dateFrom,
                    $dateTo,
                    $ctripRows
                )
                : (new CtripOrderAnalysisService())->analyzeStoredRange(
                    $systemHotelId,
                    $tenantId,
                    $dateFrom,
                    $dateTo
                );
            if (!is_array($analysis)) {
                throw new RuntimeException('ctrip_deep_analysis_contract_invalid');
            }
            $status = trim((string)($analysis['status'] ?? ''));
            return [
                'status' => $status !== '' ? $status : 'data_missing',
                'reason' => trim((string)($analysis['note'] ?? ''))
                    ?: ($status !== '' ? $status : 'ctrip_deep_analysis_missing'),
                'data' => $analysis,
            ];
        } catch (\Throwable) {
            return [
                'status' => 'error',
                'reason' => 'ctrip_deep_analysis_failed',
                'data' => null,
            ];
        }
    }

    /**
     * @param array<string, mixed> $ctrip
     * @param array<string, mixed> $meituan
     * @param array<string, mixed> $comparison
     */
    private function overallStatus(
        array $ctrip,
        array $meituan,
        array $comparison
    ): string {
        $ctripMissing = ($ctrip['status'] ?? '') === 'missing';
        $meituanMissing = ($meituan['status'] ?? '') === 'missing';
        if ($ctripMissing && $meituanMissing) {
            return 'data_missing';
        }
        if ($ctripMissing || $meituanMissing) {
            return 'partial';
        }
        if (($ctrip['status'] ?? '') !== 'verified'
            || ($meituan['status'] ?? '') !== 'verified'
        ) {
            return 'partial';
        }
        return ($comparison['status'] ?? '') === 'ready'
            ? 'ready'
            : 'separate_ready';
    }

    /**
     * @param array<string, mixed> $ctrip
     * @param array<string, mixed> $meituan
     * @return array<int, array<string, mixed>>
     */
    private function actions(array $ctrip, array $meituan): array
    {
        $ctripMissing = (int)($ctrip['evidence']['order_fact_rows'] ?? 0) === 0;
        $meituanMissing = (int)($meituan['evidence']['order_fact_rows'] ?? 0) === 0;
        $ctripOrderNeedsRemediation = $ctripMissing
            || (string)($ctrip['status'] ?? 'missing') !== 'verified';
        $meituanOrderNeedsRemediation = $meituanMissing
            || (string)($meituan['status'] ?? 'missing') !== 'verified';
        $deepStatus = strtolower(trim((string)($ctrip['deep_analysis']['status'] ?? '')));
        $ctripUploadRequired = $ctripMissing
            || in_array($deepStatus, ['no_data', 'data_missing', 'indeterminate', 'error', ''], true);
        $flowStatus = (string)($meituan['order_flow']['status'] ?? 'missing');

        return [
            [
                'key' => 'ctrip_order_upload',
                'platform' => 'ctrip',
                'label' => '上传携程订单',
                'status' => $ctripUploadRequired ? 'required' : 'available',
                'required' => $ctripUploadRequired,
                'reason' => $ctripUploadRequired
                    ? '携程订单或深度分析证据不完整，请上传原始订单文件补齐。'
                    : '携程订单深度分析已可回读。',
                'reason_code' => $ctripUploadRequired
                    ? 'ctrip_order_or_deep_analysis_missing'
                    : 'ctrip_deep_analysis_available',
                'method' => 'POST',
                'endpoint' => '/api/online-data/data-import',
                'target' => 'ctrip_order_upload',
                'page' => 'ctrip-ebooking',
                'tab' => 'data-health',
            ],
            [
                'key' => 'ctrip_order_collect',
                'platform' => 'ctrip',
                'label' => '采集携程订单',
                'status' => $ctripOrderNeedsRemediation ? 'required' : 'available',
                'required' => $ctripOrderNeedsRemediation,
                'reason' => $ctripOrderNeedsRemediation
                    ? ($ctripMissing
                        ? '当前范围缺少携程订单事实，请进入携程采集入口补齐。'
                        : '携程订单事实尚未完整核验或存在表示冲突，请重新采集并回读。')
                    : '当前范围已有携程订单事实。',
                'reason_code' => $ctripOrderNeedsRemediation
                    ? ($ctripMissing
                        ? 'ctrip_order_facts_missing'
                        : 'ctrip_order_facts_unverified_or_conflicted')
                    : 'ctrip_order_facts_available',
                'method' => 'POST',
                'endpoint' => '/api/online-data/fetch-ctrip',
                'target' => 'ctrip_collection',
                'page' => 'ctrip-ebooking',
                'tab' => 'data-health',
            ],
            [
                'key' => 'meituan_order_collect',
                'platform' => 'meituan',
                'label' => '补采美团订单',
                'status' => $meituanOrderNeedsRemediation ? 'required' : 'available',
                'required' => $meituanOrderNeedsRemediation,
                'reason' => $meituanOrderNeedsRemediation
                    ? ($meituanMissing
                        ? '当前范围缺少美团订单事实，请补采后重新分析。'
                        : '美团订单事实尚未完整核验或存在表示冲突，请补采后重新回读。')
                    : '当前范围已有美团订单事实。',
                'reason_code' => $meituanOrderNeedsRemediation
                    ? ($meituanMissing
                        ? 'meituan_order_facts_missing'
                        : 'meituan_order_facts_unverified_or_conflicted')
                    : 'meituan_order_facts_available',
                'method' => 'POST',
                'endpoint' => '/api/online-data/fetch-meituan-orders',
                'target' => 'meituan_order_collection',
                'page' => 'meituan-ebooking',
                'tab' => 'meituan-orders',
            ],
            [
                'key' => 'meituan_order_flow_collect',
                'platform' => 'meituan',
                'label' => '补采美团订单流向',
                'status' => $flowStatus === 'verified' ? 'available' : 'required',
                'required' => $flowStatus !== 'verified',
                'reason' => $flowStatus === 'verified'
                    ? '美团订单流向已完成保存回读。'
                    : '美团订单流向缺失或尚未核验，请补采流失/流入摘要。',
                'reason_code' => $flowStatus === 'verified'
                    ? 'meituan_order_flow_available'
                    : 'meituan_order_flow_missing_or_unverified',
                'method' => 'POST',
                'endpoint' => '/api/online-data/fetch-meituan-order-flow',
                'target' => 'meituan_order_flow_collection',
                'page' => 'meituan-ebooking',
                'tab' => 'meituan-order-flow',
            ],
        ];
    }

    /** @return array{0:?string,1:?string} */
    private function validatedRange(?string $dateFrom, ?string $dateTo): array
    {
        $dateFrom = $dateFrom !== null ? trim($dateFrom) : null;
        $dateTo = $dateTo !== null ? trim($dateTo) : null;
        $dateFrom = $dateFrom === '' ? null : $dateFrom;
        $dateTo = $dateTo === '' ? null : $dateTo;
        if (($dateFrom === null) !== ($dateTo === null)) {
            throw new RuntimeException('双平台订单快析开始日期和结束日期需要同时填写。', 422);
        }
        if ($dateFrom === null) {
            return [null, null];
        }
        if (!$this->validDate($dateFrom)
            || !$this->validDate((string)$dateTo)
            || $dateFrom > $dateTo
        ) {
            throw new RuntimeException('双平台订单快析日期范围无效。', 422);
        }
        $days = (new DateTimeImmutable($dateFrom))
            ->diff(new DateTimeImmutable((string)$dateTo))
            ->days;
        if (!is_int($days) || $days + 1 > self::MAX_RANGE_DAYS) {
            throw new RuntimeException('双平台订单快析日期范围最多为 1096 天。', 422);
        }
        return [$dateFrom, $dateTo];
    }

    private function validDate(string $date): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1) {
            return false;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed instanceof DateTimeImmutable
            && $parsed->format('Y-m-d') === $date;
    }

    /** @return array{0:?string,1:?string} */
    private function latestThirtyDayRangeFromDatabase(
        int $tenantId,
        int $systemHotelId
    ): array {
        try {
            $query = Db::name('online_daily_data')
                ->where('tenant_id', $tenantId)
                ->where('system_hotel_id', $systemHotelId)
                ->whereIn('data_type', ['order', 'order_flow']);
            $this->applyDualPlatformQueryScope($query);
            $latestRow = $query
                ->field('data_date')
                ->order('data_date', 'desc')
                ->find();
            $latest = is_array($latestRow)
                ? ($latestRow['data_date'] ?? null)
                : null;
        } catch (\Throwable $error) {
            throw new RuntimeException('双平台订单数据日期回读失败。', 500, $error);
        }
        $latestDate = trim((string)$latest);
        if (!$this->validDate($latestDate)) {
            return [null, null];
        }
        return [
            (new DateTimeImmutable($latestDate))->modify('-29 days')->format('Y-m-d'),
            $latestDate,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{0:?string,1:?string}
     */
    private function latestThirtyDayRange(array $rows): array
    {
        $dates = [];
        foreach ($rows as $row) {
            $date = trim((string)($row['data_date'] ?? ''));
            if ($this->validDate($date)) {
                $dates[] = $date;
            }
        }
        if ($dates === []) {
            return [null, null];
        }
        sort($dates);
        $latest = $dates[count($dates) - 1];
        return [
            (new DateTimeImmutable($latest))->modify('-29 days')->format('Y-m-d'),
            $latest,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function loadRows(
        int $tenantId,
        int $systemHotelId,
        string $dateFrom,
        string $dateTo
    ): array {
        try {
            $query = Db::name('online_daily_data')
                ->where('tenant_id', $tenantId)
                ->where('system_hotel_id', $systemHotelId)
                ->whereIn('data_type', ['order', 'order_flow'])
                ->where('data_date', '>=', $dateFrom)
                ->where('data_date', '<=', $dateTo);
            $this->applyDualPlatformQueryScope($query);
            $rowCount = (int)(clone $query)->count();
            if ($rowCount > self::MAX_SCOPED_ROWS) {
                throw new RuntimeException(
                    '双平台订单快析数据超过安全窗口，请缩小日期范围。',
                    422
                );
            }
            return $query
                ->order('data_date', 'asc')
                ->order('id', 'asc')
                ->select()
                ->toArray();
        } catch (RuntimeException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new RuntimeException('双平台订单数据回读失败。', 500, $error);
        }
    }

    /**
     * Defence-in-depth for injected/test rows and future alternate loaders.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function scopedRows(
        array $rows,
        int $tenantId,
        int $systemHotelId,
        ?string $dateFrom,
        ?string $dateTo
    ): array {
        return array_values(array_filter($rows, function (array $row) use (
            $tenantId,
            $systemHotelId,
            $dateFrom,
            $dateTo
        ): bool {
            if ((int)($row['tenant_id'] ?? 0) !== $tenantId
                || (int)($row['system_hotel_id'] ?? 0) !== $systemHotelId
            ) {
                return false;
            }
            $dataType = strtolower(trim((string)($row['data_type'] ?? '')));
            if (!in_array($dataType, ['order', 'order_flow'], true)) {
                return false;
            }
            $date = trim((string)($row['data_date'] ?? ''));
            if (!$this->validDate($date)) {
                return false;
            }
            return $dateFrom === null
                || ($date >= $dateFrom && $date <= (string)$dateTo);
        }));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function platformRows(array $rows, string $platform): array
    {
        return array_values(array_filter(
            $rows,
            static function (array $row) use ($platform): bool {
                $candidate = trim((string)($row['platform'] ?? ''));
                if ($candidate === '') {
                    $candidate = trim((string)($row['source'] ?? ''));
                }
                return OtaStandardEtlService::canonicalPlatformKey($candidate)
                    === $platform;
            }
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function dualPlatformRows(array $rows): array
    {
        return array_values(array_filter(
            $rows,
            function (array $row): bool {
                $candidate = trim((string)($row['platform'] ?? ''));
                if ($candidate === '') {
                    $candidate = trim((string)($row['source'] ?? ''));
                }
                return in_array(
                    OtaStandardEtlService::canonicalPlatformKey($candidate),
                    ['ctrip', 'meituan'],
                    true
                );
            }
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $facts
     * @return array{conflict:bool,conflict_dates:array<int,string>,record_kinds:array<int,string>}
     */
    private function representationEvidence(array $facts): array
    {
        $byDate = [];
        $allKinds = [];
        foreach ($facts as $fact) {
            $date = trim((string)($fact['date_key'] ?? ''));
            $kind = strtolower(trim((string)($fact['record_kind'] ?? 'unknown')));
            if (!$this->validDate($date)
                || !in_array($kind, ['aggregate', 'detail'], true)
            ) {
                continue;
            }
            $byDate[$date][$kind] = true;
            $allKinds[$kind] = true;
        }
        $conflictDates = [];
        foreach ($byDate as $date => $kinds) {
            if (isset($kinds['aggregate'], $kinds['detail'])) {
                $conflictDates[] = (string)$date;
            }
        }
        sort($conflictDates);
        $recordKinds = array_keys($allKinds);
        sort($recordKinds);
        return [
            'conflict' => $conflictDates !== [],
            'conflict_dates' => $conflictDates,
            'record_kinds' => $recordKinds,
        ];
    }

    /**
     * Strict facts require the complete saved-history gate. A value-level
     * readback alone remains visible, but it is not promoted to verified.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function strictEvidence(array $rows, string $dataType): array
    {
        $scoped = array_values(array_filter(
            $rows,
            static fn(array $row): bool => strtolower(trim((string)(
                $row['data_type'] ?? ''
            ))) === $dataType
        ));
        $readback = 0;
        $validated = 0;
        $history = 0;
        foreach ($scoped as $row) {
            if ((int)($row['readback_verified'] ?? 0) === 1) {
                $readback++;
            }
            if (strtolower(trim((string)($row['validation_status'] ?? '')))
                === 'verified'
            ) {
                $validated++;
            }
            if (strtolower(trim((string)($row['history_status'] ?? '')))
                === 'success'
            ) {
                $history++;
            }
        }
        $rowCount = count($scoped);
        $failureReasons = [];
        if ($rowCount === 0) {
            $failureReasons[] = 'source_rows_missing';
        } else {
            if ($readback !== $rowCount) {
                $failureReasons[] = 'strict_readback_missing';
            }
            if ($validated !== $rowCount) {
                $failureReasons[] = 'strict_validation_status_missing';
            }
            if ($history !== $rowCount) {
                $failureReasons[] = 'strict_history_status_missing';
            }
        }

        return [
            'complete' => $rowCount > 0 && $failureReasons === [],
            'row_count' => $rowCount,
            'readback_verified_count' => $readback,
            'validation_verified_count' => $validated,
            'history_success_count' => $history,
            'failure_reasons' => $failureReasons,
        ];
    }

    private function applyDualPlatformQueryScope(object $query): void
    {
        $query->where(static function ($scope): void {
            $scope
                ->whereIn('platform', [
                    'ctrip', 'Ctrip', 'CTRIP', '携程',
                    'trip', 'Trip', 'TRIP',
                    'ebooking', 'eBooking', 'EBOOKING',
                    'meituan', 'Meituan', 'MEITUAN', '美团',
                    'meituan hotel', 'Meituan Hotel', 'MEITUAN HOTEL',
                ])
                ->whereOr('platform', 'like', '%ctrip%')
                ->whereOr('platform', 'like', '%trip.com%')
                ->whereOr('platform', 'like', '%meituan%')
                ->whereOr('platform', 'like', '%dianping%')
                ->whereOr(static function ($fallback): void {
                    $fallback
                        ->whereRaw("TRIM(COALESCE(platform, '')) = ''")
                        ->where(static function ($source): void {
                            $source
                                ->where('source', 'like', '%ctrip%')
                                ->whereOr('source', 'like', '%trip.com%')
                                ->whereOr('source', 'trip')
                                ->whereOr('source', 'ebooking')
                                ->whereOr('source', '携程')
                                ->whereOr('source', 'like', '%meituan%')
                                ->whereOr('source', 'like', '%dianping%')
                                ->whereOr('source', '美团');
                        });
                });
        });
    }

    /**
     * @param array<int, array<string, mixed>> $facts
     * @return array<int, string>
     */
    private function dateKeys(array $facts): array
    {
        $dates = $this->factValues($facts, 'date_key');
        sort($dates);
        return $dates;
    }

    /** @param array<int, array<string, mixed>> $facts */
    private function factDateBasis(array $facts): string
    {
        if ($facts === []) {
            return 'unknown';
        }
        $bases = [];
        foreach ($facts as $fact) {
            $raw = $fact['raw_data'] ?? [];
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                $raw = is_array($decoded) ? $decoded : [];
            }
            $raw = is_array($raw) ? $raw : [];
            $sources = [$fact, $raw];
            if (is_array($raw['row'] ?? null)) {
                $sources[] = $raw['row'];
            }
            if (is_array($raw['detail'] ?? null)) {
                $sources[] = $raw['detail'];
            }
            $basis = 'unknown';
            foreach ($sources as $source) {
                foreach ([
                    'date_basis',
                    'date_role',
                    'data_date_basis',
                    'order_date_basis',
                    'business_date_basis',
                ] as $key) {
                    $candidate = $this->normalizeDateBasis((string)(
                        $source[$key]
                        ?? ''
                    ));
                    if ($candidate !== 'unknown') {
                        $basis = $candidate;
                        break 2;
                    }
                }
            }
            if ($basis === 'unknown') {
                foreach ($sources as $source) {
                    $candidate = $this->dateBasisFromSource((string)(
                        $source['date_source']
                        ?? $source['dateSource']
                        ?? ''
                    ));
                    if ($candidate !== 'unknown') {
                        $basis = $candidate;
                        break;
                    }
                }
            }
            if ($basis === 'unknown') {
                $basis = $this->inferredOrderDateBasis($sources);
            }
            $bases[$basis] = true;
        }
        $values = array_keys($bases);
        if ($values === ['unknown']) {
            return 'unknown';
        }
        if (in_array('unknown', $values, true) || count($values) !== 1) {
            return 'mixed';
        }
        return (string)$values[0];
    }

    private function normalizeDateBasis(string $basis): string
    {
        $basis = strtolower(trim($basis));
        if ($basis === '') {
            return 'unknown';
        }
        $basis = str_replace(['-', ' ', '.'], '_', $basis);
        return match ($basis) {
            'stay_date', 'order_date', 'checkout_date', 'business_date',
                'mixed', 'unknown' => $basis,
            'stay', 'staydate', 'checkin', 'check_in', '入住日期', '入住日' => 'stay_date',
            'booking', 'bookingdate', 'order', 'orderdate', 'create_date',
                'booking_date_fallback', '下单日期', '预订日期' => 'order_date',
            'checkout', 'check_out', '离店日期', '离店日' => 'checkout_date',
            'business', 'businessdate', '业务日期', '营业日期' => 'business_date',
            'stay_date_with_booking_date_fallback' => 'mixed',
            default => 'unknown',
        };
    }

    private function dateBasisFromSource(string $source): string
    {
        $source = strtolower(trim($source));
        if ($source === ''
            || str_contains($source, 'capture_context')
            || str_contains($source, 'visible_update')
        ) {
            return 'unknown';
        }
        if (preg_match('/(?:check[_-]?in|stay|arrival)/', $source) === 1) {
            return 'stay_date';
        }
        if (preg_match('/(?:order|booking|create|buy|purchase|starttime|endtime)/', $source) === 1) {
            return 'order_date';
        }
        if (preg_match('/(?:check[_-]?out|departure)/', $source) === 1) {
            return 'checkout_date';
        }
        if (preg_match('/(?:business|biz)/', $source) === 1) {
            return 'business_date';
        }
        return 'unknown';
    }

    /** @param array<int, array<string, mixed>> $sources */
    private function inferredOrderDateBasis(array $sources): string
    {
        foreach ($sources as $source) {
            foreach ([
                'order_time', 'orderTime', 'createTime', 'create_time',
                'buyTime', 'buy_time', 'purchase_time', 'purchaseTime',
                '购买时间',
            ] as $key) {
                if ($this->validDate(substr(trim((string)($source[$key] ?? '')), 0, 10))) {
                    return 'order_date';
                }
            }
        }
        foreach ($sources as $source) {
            foreach (['check_in_date', 'checkInDate', 'checkIn', 'stay_date', 'stayDate'] as $key) {
                if ($this->validDate(substr(trim((string)($source[$key] ?? '')), 0, 10))) {
                    return 'stay_date';
                }
            }
        }
        return 'unknown';
    }

    /**
     * @param array<int, array<string, mixed>> $facts
     * @return array<int, string>
     */
    private function factValues(array $facts, string $key): array
    {
        $values = [];
        foreach ($facts as $fact) {
            $value = trim((string)($fact[$key] ?? ''));
            if ($value !== '') {
                $values[$value] = true;
            }
        }
        $result = array_keys($values);
        sort($result);
        return $result;
    }

    /** @param array<string, mixed> $trust */
    private function safeTrustEnvelope(array $trust): array
    {
        $source = is_array($trust['source'] ?? null) ? $trust['source'] : [];
        return [
            'saved_success' => ($trust['saved_success'] ?? false) === true,
            'updated_at' => $trust['updated_at'] ?? null,
            'caliber' => trim((string)($trust['caliber'] ?? '')),
            'failure_reasons' => $this->safeFailureReasonCodes(
                $trust['failure_reasons']
                ?? []
            ),
            'source' => [
                'table' => trim((string)($source['table'] ?? '')) ?: 'online_daily_data',
                'platforms' => $this->safeCodeList($source['platforms'] ?? []),
                'data_types' => $this->safeCodeList($source['data_types'] ?? []),
                'source_methods' => $this->safeCodeList($source['source_methods'] ?? []),
                'date_range' => is_array($source['date_range'] ?? null)
                    ? $source['date_range']
                    : ['start' => null, 'end' => null],
                'row_count' => (int)($source['row_count'] ?? 0),
                'stored_count' => (int)($source['stored_count'] ?? 0),
                'readback_verified_count' => (int)($source['readback_verified_count'] ?? 0),
            ],
        ];
    }

    /** @param array<int, array<string, mixed>> $facts */
    private function orderFlowGroupTimestamp(array $facts): int
    {
        $timestamps = array_map(
            fn(array $fact): int => $this->orderFlowFactTimestamp($fact),
            $facts
        );
        return $timestamps !== [] ? max($timestamps) : 0;
    }

    /** @param array<string, mixed> $fact */
    private function orderFlowFactTimestamp(array $fact): int
    {
        foreach ([
            $fact['period_end'] ?? null,
            $fact['date_key'] ?? null,
            $fact['source_trace']['updated_at'] ?? null,
        ] as $value) {
            $timestamp = strtotime(trim((string)($value ?? '')));
            if ($timestamp !== false) {
                return $timestamp;
            }
        }
        return 0;
    }

    private function sameString(mixed $left, mixed $right): bool
    {
        $leftText = trim((string)($left ?? ''));
        $rightText = trim((string)($right ?? ''));
        return $leftText !== '' && $leftText === $rightText;
    }

    private function reasonText(string $code): string
    {
        if (str_starts_with($code, 'validation_status_')) {
            return '来源已保存，但尚未完成字段校验。';
        }
        if (str_starts_with($code, 'validation:')) {
            return '来源存在尚未通过的校验标记。';
        }
        if (str_starts_with($code, 'row_status_')) {
            return '来源记录当前处于不可核验状态。';
        }
        return match ($code) {
            'saved_success' => '已保存并完成精确回读。',
            'metric_value_missing' => '当前范围缺少该指标值。',
            'metric_source_unverified' => '指标已有数值，但来源尚未完成核验。',
            'source_rows_missing' => '当前范围没有对应来源事实。',
            'source_update_time_missing' => '来源缺少更新时间，暂不标记为已核验。',
            'readback_unverified' => '尚未完成数据库精确回读。',
            'provenance_missing' => '来源追踪信息缺失。',
            'manual_override_unverified' => '人工补录尚未完成核验。',
            'strict_fact_gate_incomplete' => '订单事实尚未完成历史、校验和精确回读三重门禁。',
            'strict_readback_missing' => '部分订单事实尚未完成数据库精确回读。',
            'strict_validation_status_missing' => '部分订单事实尚未达到 validation_status=verified。',
            'strict_history_status_missing' => '部分订单事实尚未达到 history_status=success。',
            'representation_conflict' => '同一平台同一订单日同时存在聚合与逐单表示，已停止汇总以避免重复计算。',
            'platform_order_representation_conflict' => '至少一个平台同日存在聚合与逐单订单冲突，已停止跨平台比较。',
            'room_revenue_missing' => '缺少可核验的房费收入；订单金额、参考底价或流向金额不能替代。',
            'room_revenue_partial' => '仅部分订单事实具备可核验房费收入。',
            'adr_denominator_zero' => 'OTA 间夜为零，ADR 不可计算。',
            'cancellation_fields_partial' => '取消订单证据未覆盖当前范围内的全部订单事实。',
            'cancellation_fields_missing' => '缺少完整的取消订单字段，取消率不可计算。',
            'cancellation_classification_incomplete' => '订单取消状态分类不完整。',
            'both_platform_order_facts_missing' => '携程和美团都缺少当前范围的订单事实。',
            'ctrip_order_facts_missing' => '携程缺少当前范围的订单事实。',
            'meituan_order_facts_missing' => '美团缺少当前范围的订单事实。',
            'platform_order_date_sets_differ' => '两平台实际订单日期集合不同，只能分别展示。',
            'platform_date_basis_unknown' => '一个或两个平台缺少明确的订单日期口径，只能分别展示。',
            'platform_metric_scope_or_date_basis_differs' => '两平台的 OTA 范围或日期口径不同，只能分别展示。',
            'same_verified_ota_scope_and_dates' => '两平台属于同一酒店、同一订单日期集合和同一 OTA 口径。',
            'no_joint_verified_comparable_metrics' => '两平台尚无同时核验且口径一致的共同指标。',
            'metric_missing_on_one_or_both_platforms' => '一个或两个平台缺少该指标，不能计算差值。',
            'metric_not_verified_on_both_platforms' => '该指标尚未在两个平台同时完成保存回读核验。',
            'metric_definition_or_fact_basis_differs' => '该指标的平台定义或事实口径不同，只能分别展示。',
            'both_platform_metrics_verified_and_comparable' => '两个平台的该指标均已核验且口径一致。',
            'source_error_info_present' => '来源记录包含错误信息，已隐藏原文并停止核验。',
            'source_failure_reason_present' => '来源记录包含失败原因，已隐藏原文并停止核验。',
            'source_failed_reason_present' => '来源记录包含失败原因，已隐藏原文并停止核验。',
            'source_failure_reason_redacted' => '来源失败信息不符合安全代码格式，已隐藏原文。',
            'validation_flag_present' => '来源存在无法安全展示的校验标记。',
            'meituan_order_flow_summary_missing' => '当前范围缺少美团流失/流入汇总。',
            'order_flow_direction_missing' => '美团订单流向只回读到一个方向，结果不完整。',
            'order_flow_source_unverified' => '美团订单流向已有数值，但来源尚未完成核验。',
            default => $code,
        };
    }

    /** @return array<int, string> */
    private function safeFailureReasonCodes(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }
        $safe = [];
        foreach ($values as $value) {
            $reason = strtolower(trim((string)$value));
            if ($reason === '') {
                continue;
            }
            if (str_starts_with($reason, 'error_info:')) {
                $safe[] = 'source_error_info_present';
                continue;
            }
            if (str_starts_with($reason, 'failure_reason:')) {
                $safe[] = 'source_failure_reason_present';
                continue;
            }
            if (str_starts_with($reason, 'failed_reason:')) {
                $safe[] = 'source_failed_reason_present';
                continue;
            }
            if (str_starts_with($reason, 'validation:')) {
                $flag = substr($reason, strlen('validation:'));
                $safe[] = preg_match('/^[a-z0-9_.-]{1,80}$/D', $flag) === 1
                    ? 'validation:' . $flag
                    : 'validation_flag_present';
                continue;
            }
            $safe[] = preg_match('/^[a-z0-9_.-]{1,120}$/D', $reason) === 1
                ? $reason
                : 'source_failure_reason_redacted';
        }
        return array_values(array_unique($safe));
    }

    /** @return array<int, string> */
    private function safeCodeList(mixed $values): array
    {
        return array_values(array_filter(
            $this->stringList($values),
            static fn(string $value): bool =>
                preg_match('/^[a-z0-9_.-]{1,80}$/Di', $value) === 1
        ));
    }

    /** @return array<int, string> */
    private function stringList(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            $values
        ), static fn(string $value): bool => $value !== '')));
    }

    /** @return array<int, array<string, mixed>> */
    private function arrayRows(mixed $rows): array
    {
        return array_values(array_filter(
            is_array($rows) ? $rows : [],
            'is_array'
        ));
    }

    private function nullableNormalizedNumber(mixed $value): int|float|null
    {
        return $value !== null && is_numeric($value)
            ? $this->normalizedNumber($value)
            : null;
    }

    private function normalizedNumber(mixed $value): int|float
    {
        $number = (float)$value;
        return abs($number - round($number)) < 0.0000001
            ? (int)round($number)
            : round($number, 4);
    }
}
