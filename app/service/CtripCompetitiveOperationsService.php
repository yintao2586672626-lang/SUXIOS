<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * Read model for Ctrip competition-circle operations.
 *
 * The service only combines stored Ctrip OTA facts. It does not widen those
 * facts into whole-hotel/PMS operating truth.
 */
final class CtripCompetitiveOperationsService
{
    public function __construct(private ?CtripPublicHotelProfileService $profileService = null)
    {
        $this->profileService ??= new CtripPublicHotelProfileService();
    }

    /** @return array<string,mixed> */
    public function build(int $systemHotelId, string $startDate, string $endDate): array
    {
        if ($systemHotelId <= 0) {
            throw new \InvalidArgumentException('System hotel ID must be positive.');
        }
        [$startDate, $endDate] = $this->normalizeDateRange($startDate, $endDate);
        $businessRows = Db::name('online_daily_data')
            ->where('system_hotel_id', $systemHotelId)
            ->where('source', 'ctrip')
            ->where('data_type', 'competitor')
            ->whereBetween('data_date', [$startDate, $endDate])
            ->order('data_date', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        $trafficRows = Db::name('online_daily_data')
            ->where('system_hotel_id', $systemHotelId)
            ->where('source', 'ctrip')
            ->where('data_type', 'traffic')
            ->whereBetween('data_date', [$startDate, $endDate])
            ->order('data_date', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        $profiles = $this->profileService->listProfiles($systemHotelId);
        $binding = $this->profileService->resolveOwnHotelBinding($systemHotelId);

        return $this->analyzeRows(
            $businessRows,
            $trafficRows,
            $profiles,
            is_string($binding['ota_hotel_id'] ?? null) ? (string)$binding['ota_hotel_id'] : '',
            [
                'system_hotel_id' => $systemHotelId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'binding_status' => (string)($binding['status'] ?? 'binding_missing'),
            ]
        );
    }

    /**
     * @param array<int,array<string,mixed>> $businessRows
     * @param array<int,array<string,mixed>> $trafficRows
     * @param array<int,array<string,mixed>> $profiles
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function analyzeRows(
        array $businessRows,
        array $trafficRows,
        array $profiles = [],
        string $ownOtaHotelId = '',
        array $context = []
    ): array {
        $profileMap = $this->profileMap($profiles);
        $business = $this->normalizeBusinessRows($businessRows, $ownOtaHotelId);
        $traffic = $this->normalizeTrafficRows($trafficRows, $ownOtaHotelId);
        $businessUsable = array_values(array_filter(
            $business,
            static fn(array $row): bool => ($row['usable_for_diagnosis'] ?? false) === true
        ));
        $trafficUsable = array_values(array_filter(
            $traffic,
            static fn(array $row): bool => ($row['usable_for_diagnosis'] ?? false) === true
        ));
        $rowCount = count($business) + count($traffic);
        $usableRowCount = count($businessUsable) + count($trafficUsable);

        // Unverified rows remain visible in coverage diagnostics, but never
        // participate in comparisons, trends, rankings, funnels or actions.
        $trend = $this->buildTrend($businessUsable);
        $ranking = $this->buildRankMonitoring($businessUsable);
        $funnel = $this->buildTrafficFunnel($trafficUsable);
        $comparison = $this->buildBusinessComparison($businessUsable, $profileMap);
        $anomalies = $this->buildAnomalyDiagnosis($trend, $ranking, $funnel);

        $competitorIds = [];
        foreach ($business as $row) {
            if ($row['compare_type'] === 'competitor' && $row['ota_hotel_id'] !== '') {
                $competitorIds[$row['ota_hotel_id']] = true;
            }
        }
        $profiledCompetitors = 0;
        foreach (array_keys($competitorIds) as $id) {
            if (isset($profileMap[$id])) {
                $profiledCompetitors++;
            }
        }

        return [
            'status' => $rowCount === 0
                ? 'data_missing'
                : ($usableRowCount === 0 ? 'unverified' : ($usableRowCount === $rowCount ? 'available' : 'partial')),
            'context' => $context,
            'business_comparison' => $comparison,
            'traffic_funnel_comparison' => $funnel,
            'trend' => $trend,
            'rank_monitoring' => $ranking,
            'anomaly_diagnosis' => $anomalies,
            'competitor_profiles' => [
                'status' => $profiles === [] ? 'data_missing' : ($profileMap === [] ? 'unverified' : (count($profileMap) === count($profiles) ? 'available' : 'partial')),
                'profile_count' => count($profiles),
                'usable_profile_count' => count($profileMap),
                'competitor_count' => count($competitorIds),
                'profiled_competitor_count' => $profiledCompetitors,
                'coverage_rate' => count($competitorIds) > 0
                    ? round($profiledCompetitors / count($competitorIds) * 100, 2)
                    : null,
                'items' => array_values($profiles),
            ],
            'data_coverage' => [
                'business_row_count' => count($business),
                'traffic_row_count' => count($traffic),
                'business_usable_count' => count(array_filter($business, static fn(array $row): bool => $row['usable_for_diagnosis'])),
                'traffic_usable_count' => count(array_filter($traffic, static fn(array $row): bool => $row['usable_for_diagnosis'])),
                'competitor_hotel_count' => count($competitorIds),
                'profile_count' => count($profiles),
                'quality_status_counts' => $this->qualityStatusCounts(array_merge($business, $traffic)),
                'decision_eligible_row_count' => $usableRowCount,
                'excluded_from_decision_count' => $rowCount - $usableRowCount,
                'decision_gate' => $usableRowCount === 0 ? 'insufficient_evidence' : 'usable_evidence_only',
            ],
            'source_scope' => 'ctrip_ota_competition_circle_only',
            'scope_notice' => '经营、流量、排名与异常均为携程 OTA 竞争圈口径，不代表全酒店/PMS经营事实；异常是排查线索，不代表已证明因果。',
            'room_count_semantics' => CtripPublicHotelProfileService::ROOM_COUNT_SEMANTICS,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function normalizeBusinessRows(array $rows, string $ownOtaHotelId): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $raw = $this->decodeRaw($row);
            $otaHotelId = trim((string)($row['hotel_id'] ?? $raw['hotelId'] ?? $raw['hotel_id'] ?? ''));
            $date = $this->normalizeDate((string)($row['data_date'] ?? $raw['dataDate'] ?? $raw['date'] ?? ''));
            if ($date === '' || $otaHotelId === '') {
                continue;
            }
            $compareType = $this->businessCompareType($row, $raw, $otaHotelId, $ownOtaHotelId);
            $amount = $this->metricNumber($row, $raw, 'amount', ['amount', 'Amount', 'totalAmount', 'total_amount', 'saleAmount']);
            $quantity = $this->metricNumber($row, $raw, 'quantity', ['quantity', 'Quantity', 'roomNights', 'room_nights']);
            $orders = $this->metricNumber($row, $raw, 'book_order_num', ['bookOrderNum', 'book_order_num', 'orderCount', 'order_count']);
            $visitors = $this->rawNumber($raw, ['totalDetailNum', 'total_detail_num', 'detailVisitors', 'visitorCount']);
            $conversion = $this->rawNumber($raw, ['convertionRate', 'conversionRate', 'conversion_rate']);
            $qualityStatus = $this->effectiveQualityStatus($row);
            $usable = $this->isUsableQualityStatus($qualityStatus);
            $normalized[] = [
                'id' => (int)($row['id'] ?? 0),
                'date' => $date,
                'ota_hotel_id' => $otaHotelId,
                'hotel_name' => trim((string)($row['hotel_name'] ?? $raw['hotelName'] ?? $raw['hotel_name'] ?? '')),
                'compare_type' => $compareType,
                'amount' => $amount,
                'room_nights' => $quantity !== null ? (int)round($quantity) : null,
                'orders' => $orders !== null ? (int)round($orders) : null,
                'adr' => $amount !== null && $quantity !== null && $quantity > 0 ? round($amount / $quantity, 2) : null,
                'detail_visitors' => $visitors !== null ? (int)round($visitors) : null,
                'conversion_rate' => $conversion,
                'ranks' => [
                    'revenue_rank' => $this->positiveInteger($this->rawNumber($raw, ['amountRank', 'amount_rank', 'bookingGMVrank'])),
                    'room_nights_rank' => $this->positiveInteger($this->rawNumber($raw, ['quantityRank', 'quantity_rank', 'stayInRNrank'])),
                    'order_rank' => $this->positiveInteger($this->rawNumber($raw, ['bookOrderNumRank', 'book_order_num_rank', 'bookingOrdersrank'])),
                    'rating_rank' => $this->positiveInteger($this->rawNumber($raw, ['commentScoreRank', 'comment_score_rank'])),
                ],
                'quality_status' => $qualityStatus !== '' ? $qualityStatus : 'unverified',
                'usable_for_diagnosis' => $usable,
            ];
        }

        return $this->deduplicateRows($normalized, static fn(array $row): string => implode('|', [
            $row['date'], $row['ota_hotel_id'], $row['compare_type'],
        ]));
    }

    /** @return array<int,array<string,mixed>> */
    private function normalizeTrafficRows(array $rows, string $ownOtaHotelId): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $raw = $this->decodeRaw($row);
            $date = $this->normalizeDate((string)($row['data_date'] ?? $raw['date'] ?? $raw['dataDate'] ?? ''));
            if ($date === '') {
                continue;
            }
            $otaHotelId = trim((string)($row['hotel_id'] ?? $raw['hotelId'] ?? $raw['hotel_id'] ?? ''));
            $compareTypeRaw = strtolower(trim((string)($row['compare_type'] ?? $raw['compareType'] ?? $raw['compare_type'] ?? '')));
            $compareType = 'competitor';
            if ($ownOtaHotelId !== '' && $otaHotelId === $ownOtaHotelId) {
                $compareType = 'self';
            } elseif ($compareTypeRaw === 'self') {
                $compareType = 'self';
            } elseif ($compareTypeRaw === 'competitor_avg' || $otaHotelId === '-1') {
                $compareType = 'competitor_avg';
            }
            $qualityStatus = $this->effectiveQualityStatus($row);
            $normalized[] = [
                'id' => (int)($row['id'] ?? 0),
                'date' => $date,
                'ota_hotel_id' => $otaHotelId,
                'compare_type' => $compareType,
                'list_exposure' => $this->metricNumber($row, $raw, 'list_exposure', ['listExposure', 'list_exposure', 'exposure']),
                'detail_exposure' => $this->metricNumber($row, $raw, 'detail_exposure', ['detailExposure', 'detail_exposure', 'detailVisitors']),
                'order_visitors' => $this->metricNumber($row, $raw, 'order_filling_num', ['orderFillingNum', 'order_filling_num', 'orderVisitors']),
                'submit_users' => $this->metricNumber($row, $raw, 'order_submit_num', ['orderSubmitNum', 'order_submit_num', 'submitUsers']),
                'quality_status' => $qualityStatus !== '' ? $qualityStatus : 'unverified',
                'usable_for_diagnosis' => $this->isUsableQualityStatus($qualityStatus),
            ];
        }

        return $this->deduplicateRows($normalized, static fn(array $row): string => implode('|', [
            $row['date'], $row['ota_hotel_id'], $row['compare_type'],
        ]));
    }

    /** @return array<string,mixed> */
    private function buildBusinessComparison(array $rows, array $profileMap): array
    {
        if ($rows === []) {
            return ['status' => 'data_missing', 'latest_date' => null, 'self' => null, 'competitor_average' => null, 'gaps' => [], 'hotels' => []];
        }
        $latestDate = max(array_column($rows, 'date'));
        $latestRows = array_values(array_filter($rows, static fn(array $row): bool => $row['date'] === $latestDate));
        $selfRows = array_values(array_filter($latestRows, static fn(array $row): bool => $row['compare_type'] === 'self'));
        $competitors = array_values(array_filter($latestRows, static fn(array $row): bool => $row['compare_type'] === 'competitor'));
        $self = $this->aggregateBusinessRows($selfRows, 'sum');
        $competitorAverage = $this->aggregateBusinessRows($competitors, 'average');
        $hotelItems = [];
        foreach ($latestRows as $row) {
            $item = $row;
            $item['profile'] = $profileMap[$row['ota_hotel_id']] ?? null;
            $hotelItems[] = $item;
        }
        usort($hotelItems, static function (array $left, array $right): int {
            if ($left['compare_type'] !== $right['compare_type']) {
                return $left['compare_type'] === 'self' ? -1 : 1;
            }
            return ($right['room_nights'] ?? -1) <=> ($left['room_nights'] ?? -1);
        });

        return [
            'status' => $self !== null && $competitorAverage !== null ? 'available' : 'partial',
            'latest_date' => $latestDate,
            'self' => $self,
            'competitor_average' => $competitorAverage,
            'gaps' => $this->metricGaps($self, $competitorAverage, ['amount', 'room_nights', 'orders', 'adr', 'detail_visitors', 'conversion_rate']),
            'hotels' => $hotelItems,
            'competitor_count' => count($competitors),
        ];
    }

    /** @return array<string,mixed> */
    private function buildTrend(array $rows): array
    {
        if ($rows === []) {
            return ['status' => 'data_missing', 'rows' => [], 'metric_keys' => ['orders', 'room_nights', 'amount', 'adr']];
        }
        $byDate = [];
        foreach ($rows as $row) {
            $byDate[$row['date']][$row['compare_type']][] = $row;
        }
        ksort($byDate);
        $trendRows = [];
        foreach ($byDate as $date => $groups) {
            $trendRows[] = [
                'date' => $date,
                'self' => $this->aggregateBusinessRows($groups['self'] ?? [], 'sum'),
                'competitor_average' => $this->aggregateBusinessRows($groups['competitor'] ?? [], 'average'),
                'self_quality_status' => $this->aggregateQualityStatus($groups['self'] ?? []),
                'competitor_quality_status' => $this->aggregateQualityStatus($groups['competitor'] ?? []),
            ];
        }

        return [
            'status' => $trendRows !== [] ? 'available' : 'data_missing',
            'metric_keys' => ['orders', 'room_nights', 'amount', 'adr'],
            'rows' => $trendRows,
        ];
    }

    /** @return array<string,mixed> */
    private function buildRankMonitoring(array $rows): array
    {
        $seriesByHotel = [];
        foreach ($rows as $row) {
            if (!array_filter($row['ranks'], static fn($value): bool => $value !== null)) {
                continue;
            }
            $key = $row['ota_hotel_id'];
            $seriesByHotel[$key]['ota_hotel_id'] = $key;
            $seriesByHotel[$key]['hotel_name'] = $row['hotel_name'];
            $seriesByHotel[$key]['compare_type'] = $row['compare_type'];
            $seriesByHotel[$key]['series'][] = [
                'date' => $row['date'],
                'ranks' => $row['ranks'],
                'quality_status' => $row['quality_status'],
            ];
        }
        $items = [];
        foreach ($seriesByHotel as $hotel) {
            usort($hotel['series'], static fn(array $left, array $right): int => strcmp($left['date'], $right['date']));
            $latest = $hotel['series'][count($hotel['series']) - 1];
            $previous = count($hotel['series']) > 1 ? $hotel['series'][count($hotel['series']) - 2] : null;
            $changes = [];
            foreach (array_keys($latest['ranks']) as $metric) {
                $latestRank = $latest['ranks'][$metric];
                $previousRank = is_array($previous) ? ($previous['ranks'][$metric] ?? null) : null;
                $changes[$metric] = $latestRank !== null && $previousRank !== null
                    ? $previousRank - $latestRank
                    : null;
            }
            $hotel['latest'] = $latest;
            $hotel['previous'] = $previous;
            $hotel['changes'] = $changes;
            $items[] = $hotel;
        }
        usort($items, static function (array $left, array $right): int {
            if ($left['compare_type'] !== $right['compare_type']) {
                return $left['compare_type'] === 'self' ? -1 : 1;
            }
            return ($left['latest']['ranks']['revenue_rank'] ?? PHP_INT_MAX)
                <=> ($right['latest']['ranks']['revenue_rank'] ?? PHP_INT_MAX);
        });

        return [
            'status' => $items !== [] ? 'available' : 'data_missing',
            'change_semantics' => 'positive_is_rank_improvement_negative_is_rank_decline',
            'items' => $items,
        ];
    }

    /** @return array<string,mixed> */
    private function buildTrafficFunnel(array $rows): array
    {
        if ($rows === []) {
            return ['status' => 'data_missing', 'self' => null, 'competitor_average' => null, 'gaps' => [], 'rows' => []];
        }
        $byDate = [];
        foreach ($rows as $row) {
            $byDate[$row['date']][$row['compare_type']][] = $row;
        }
        ksort($byDate);
        $daily = [];
        $selfRows = [];
        $competitorRows = [];
        foreach ($byDate as $date => $groups) {
            $self = $this->aggregateFunnelRows($groups['self'] ?? []);
            $competitorBase = ($groups['competitor_avg'] ?? []) !== []
                ? $groups['competitor_avg']
                : ($groups['competitor'] ?? []);
            $competitor = $this->aggregateFunnelRows($competitorBase);
            if ($self !== null) {
                $selfRows[] = $self;
            }
            if ($competitor !== null) {
                $competitorRows[] = $competitor;
            }
            $daily[] = ['date' => $date, 'self' => $self, 'competitor_average' => $competitor];
        }
        $selfSummary = $this->aggregateFunnelMetrics($selfRows);
        $competitorSummary = $this->aggregateFunnelMetrics($competitorRows);

        return [
            'status' => $selfSummary !== null || $competitorSummary !== null ? 'available' : 'data_missing',
            'self' => $selfSummary,
            'competitor_average' => $competitorSummary,
            'gaps' => $this->metricGaps($selfSummary, $competitorSummary, [
                'list_exposure', 'detail_exposure', 'detail_entry_rate', 'order_visitors', 'order_entry_rate', 'submit_users', 'submit_rate',
            ]),
            'rows' => $daily,
        ];
    }

    /** @return array<string,mixed> */
    private function buildAnomalyDiagnosis(array $trend, array $ranking, array $funnel): array
    {
        $items = [];
        $gaps = [];
        $trendRows = is_array($trend['rows'] ?? null) ? $trend['rows'] : [];
        $selfRevenueSeries = [];
        foreach ($trendRows as $row) {
            $value = $row['self']['amount'] ?? null;
            if (is_numeric($value) && ($row['self_quality_status'] ?? '') === 'usable') {
                $selfRevenueSeries[] = ['date' => $row['date'], 'value' => (float)$value];
            }
        }
        $this->appendDropAnomaly($items, $selfRevenueSeries, 'revenue_anomaly', '携程成交金额', 30.0);
        if (count($selfRevenueSeries) < 4) {
            $gaps[] = 'revenue_baseline_requires_at_least_4_usable_dates';
        }

        $funnelRows = is_array($funnel['rows'] ?? null) ? $funnel['rows'] : [];
        $exposureSeries = [];
        $conversionSeries = [];
        foreach ($funnelRows as $row) {
            $self = is_array($row['self'] ?? null) ? $row['self'] : [];
            if (($self['quality_status'] ?? '') === 'usable' && is_numeric($self['list_exposure'] ?? null)) {
                $exposureSeries[] = ['date' => $row['date'], 'value' => (float)$self['list_exposure']];
            }
            if (($self['quality_status'] ?? '') === 'usable' && is_numeric($self['detail_entry_rate'] ?? null)) {
                $conversionSeries[] = ['date' => $row['date'], 'value' => (float)$self['detail_entry_rate']];
            }
        }
        $this->appendDropAnomaly($items, $exposureSeries, 'traffic_anomaly', '携程列表曝光', 30.0);
        $this->appendDropAnomaly($items, $conversionSeries, 'conversion_anomaly', '详情进入率', 20.0);
        if (count($exposureSeries) < 4) {
            $gaps[] = 'traffic_baseline_requires_at_least_4_dates';
        }
        if (count($conversionSeries) < 4) {
            $gaps[] = 'conversion_baseline_requires_at_least_4_dates';
        }

        $selfRankItem = null;
        foreach (($ranking['items'] ?? []) as $item) {
            if (($item['compare_type'] ?? '') === 'self') {
                $selfRankItem = $item;
                break;
            }
        }
        $rankChange = is_array($selfRankItem) ? ($selfRankItem['changes']['revenue_rank'] ?? null) : null;
        $rankQualityUsable = is_array($selfRankItem)
            && $this->isUsableQualityStatus((string)($selfRankItem['latest']['quality_status'] ?? ''))
            && $this->isUsableQualityStatus((string)($selfRankItem['previous']['quality_status'] ?? ''));
        if ($rankQualityUsable && is_numeric($rankChange) && (int)$rankChange <= -3) {
            $items[] = [
                'type' => 'rank_anomaly',
                'severity' => (int)$rankChange <= -8 ? 'high' : 'medium',
                'metric' => '成交金额排名',
                'observed' => $selfRankItem['latest']['ranks']['revenue_rank'] ?? null,
                'baseline' => $selfRankItem['previous']['ranks']['revenue_rank'] ?? null,
                'change' => (int)$rankChange,
                'evidence_date' => $selfRankItem['latest']['date'] ?? null,
                'diagnosis' => '排名较上一可比日下降，建议先核对本视图已采集的价格、流量与页面因素；若另有指定入住日的携程渠道可售证据，再核验渠道可售状态。本视图未采集库存，且该信号不代表已证明原因。',
                'channel_availability_evidence_status' => 'not_collected_by_this_view',
            ];
        } elseif ($selfRankItem === null) {
            $gaps[] = 'self_rank_series_missing';
        } elseif (!$rankQualityUsable) {
            $gaps[] = 'self_rank_quality_unusable';
        }

        $selfFunnel = is_array($funnel['self'] ?? null) ? $funnel['self'] : null;
        $competitorFunnel = is_array($funnel['competitor_average'] ?? null) ? $funnel['competitor_average'] : null;
        if ($selfFunnel !== null && $competitorFunnel !== null
            && ($selfFunnel['quality_status'] ?? '') === 'usable'
            && ($competitorFunnel['quality_status'] ?? '') === 'usable'
            && is_numeric($selfFunnel['detail_entry_rate'] ?? null)
            && is_numeric($competitorFunnel['detail_entry_rate'] ?? null)
            && (float)$competitorFunnel['detail_entry_rate'] > 0
        ) {
            $gapPct = ((float)$selfFunnel['detail_entry_rate'] - (float)$competitorFunnel['detail_entry_rate'])
                / (float)$competitorFunnel['detail_entry_rate'] * 100;
            if ($gapPct <= -20) {
                $items[] = [
                    'type' => 'conversion_vs_circle_anomaly',
                    'severity' => $gapPct <= -40 ? 'high' : 'medium',
                    'metric' => '详情进入率',
                    'observed' => $selfFunnel['detail_entry_rate'],
                    'baseline' => $competitorFunnel['detail_entry_rate'],
                    'difference_pct' => round($gapPct, 2),
                    'diagnosis' => '本店详情进入率低于竞争圈平均，建议先排查主图、标题与同口径价格；若另有指定入住日的携程渠道可售证据，再核验渠道可售状态。本视图未采集库存，且该信号不代表因果。',
                    'channel_availability_evidence_status' => 'not_collected_by_this_view',
                ];
            }
        } else {
            $gaps[] = 'self_or_competitor_funnel_missing';
        }

        return [
            'status' => $items !== [] ? 'anomaly_detected' : ($gaps === [] ? 'no_rule_anomaly' : 'insufficient_data'),
            'items' => $items,
            'data_gaps' => array_values(array_unique($gaps)),
            'diagnosis_scope' => 'rule_based_screening_not_causal_proof',
        ];
    }

    private function appendDropAnomaly(array &$items, array $series, string $type, string $metric, float $threshold): void
    {
        if (count($series) < 4) {
            return;
        }
        usort($series, static fn(array $left, array $right): int => strcmp($left['date'], $right['date']));
        $latest = $series[count($series) - 1];
        $baselineRows = array_slice($series, max(0, count($series) - 8), -1);
        $baselineValues = array_column($baselineRows, 'value');
        if ($baselineValues === []) {
            return;
        }
        $baseline = array_sum($baselineValues) / count($baselineValues);
        if ($baseline <= 0) {
            return;
        }
        $differencePct = ((float)$latest['value'] - $baseline) / $baseline * 100;
        if ($differencePct > -$threshold) {
            return;
        }
        $items[] = [
            'type' => $type,
            'severity' => $differencePct <= -50 ? 'high' : 'medium',
            'metric' => $metric,
            'observed' => round((float)$latest['value'], 2),
            'baseline' => round($baseline, 2),
            'difference_pct' => round($differencePct, 2),
            'evidence_date' => $latest['date'],
            'diagnosis' => $metric . '较近期可比均值明显下降，建议结合已采集的价格、页面与活动证据逐项排查；若另有指定入住日的携程渠道可售证据，再核验渠道可售状态。本视图未采集库存，且该信号不代表已证明因果。',
            'channel_availability_evidence_status' => 'not_collected_by_this_view',
        ];
    }

    /** @return array<string,mixed>|null */
    private function aggregateBusinessRows(array $rows, string $mode): ?array
    {
        if ($rows === []) {
            return null;
        }
        $result = [];
        foreach (['amount', 'room_nights', 'orders', 'detail_visitors', 'conversion_rate'] as $metric) {
            $values = array_values(array_filter(
                array_column($rows, $metric),
                static fn($value): bool => is_numeric($value)
            ));
            $result[$metric] = $values === []
                ? null
                : ($mode === 'average' ? round(array_sum($values) / count($values), 2) : array_sum($values));
        }
        $adrValues = array_values(array_filter(array_column($rows, 'adr'), static fn($value): bool => is_numeric($value)));
        if ($mode === 'sum' && is_numeric($result['amount']) && is_numeric($result['room_nights']) && (float)$result['room_nights'] > 0) {
            $result['adr'] = round((float)$result['amount'] / (float)$result['room_nights'], 2);
        } else {
            $result['adr'] = $adrValues !== [] ? round(array_sum($adrValues) / count($adrValues), 2) : null;
        }
        $result['observed_hotel_count'] = count($rows);
        $result['usable_hotel_count'] = count(array_filter($rows, static fn(array $row): bool => $row['usable_for_diagnosis']));
        return $result;
    }

    /** @return array<string,mixed>|null */
    private function aggregateFunnelRows(array $rows): ?array
    {
        if ($rows === []) {
            return null;
        }
        $metrics = [];
        foreach (['list_exposure', 'detail_exposure', 'order_visitors', 'submit_users'] as $metric) {
            $values = array_values(array_filter(array_column($rows, $metric), static fn($value): bool => is_numeric($value)));
            $metrics[$metric] = $values !== [] ? array_sum($values) / count($values) : null;
        }
        $metrics = $this->withFunnelRates($metrics);
        $metrics['quality_status'] = $this->aggregateQualityStatus($rows);
        return $metrics;
    }

    /** @return array<string,mixed>|null */
    private function aggregateFunnelMetrics(array $rows): ?array
    {
        if ($rows === []) {
            return null;
        }
        $metrics = [];
        foreach (['list_exposure', 'detail_exposure', 'order_visitors', 'submit_users'] as $metric) {
            $values = array_values(array_filter(array_column($rows, $metric), static fn($value): bool => is_numeric($value)));
            $metrics[$metric] = $values !== [] ? array_sum($values) : null;
        }
        $metrics = $this->withFunnelRates($metrics);
        $usableCount = count(array_filter(
            $rows,
            static fn(array $row): bool => ($row['quality_status'] ?? '') === 'usable'
        ));
        $metrics['quality_status'] = $usableCount === count($rows)
            ? 'usable'
            : ($usableCount > 0 ? 'partial' : 'unverified');
        return $metrics;
    }

    /** @return array<string,mixed> */
    private function withFunnelRates(array $metrics): array
    {
        $metrics['detail_entry_rate'] = $this->rate($metrics['detail_exposure'], $metrics['list_exposure']);
        $metrics['order_entry_rate'] = $this->rate($metrics['order_visitors'], $metrics['detail_exposure']);
        $metrics['submit_rate'] = $this->rate($metrics['submit_users'], $metrics['order_visitors']);
        return $metrics;
    }

    private function rate(mixed $numerator, mixed $denominator): ?float
    {
        return is_numeric($numerator) && is_numeric($denominator) && (float)$denominator > 0
            ? round((float)$numerator / (float)$denominator * 100, 2)
            : null;
    }

    /** @return array<string,mixed> */
    private function metricGaps(?array $self, ?array $competitor, array $metrics): array
    {
        $gaps = [];
        foreach ($metrics as $metric) {
            $selfValue = is_array($self) ? ($self[$metric] ?? null) : null;
            $competitorValue = is_array($competitor) ? ($competitor[$metric] ?? null) : null;
            $gaps[$metric] = [
                'self' => is_numeric($selfValue) ? round((float)$selfValue, 2) : null,
                'competitor_average' => is_numeric($competitorValue) ? round((float)$competitorValue, 2) : null,
                'difference' => is_numeric($selfValue) && is_numeric($competitorValue)
                    ? round((float)$selfValue - (float)$competitorValue, 2)
                    : null,
                'difference_pct' => is_numeric($selfValue) && is_numeric($competitorValue) && (float)$competitorValue != 0.0
                    ? round(((float)$selfValue - (float)$competitorValue) / abs((float)$competitorValue) * 100, 2)
                    : null,
            ];
        }
        return $gaps;
    }

    /** @return array<string,array<string,mixed>> */
    private function profileMap(array $profiles): array
    {
        $map = [];
        foreach ($profiles as $profile) {
            if (!is_array($profile) || !$this->isUsableProfile($profile)) {
                continue;
            }
            $id = trim((string)($profile['ota_hotel_id'] ?? $profile['entity_key'] ?? ''));
            if ($id !== '') {
                $map[$id] = $profile;
            }
        }
        return $map;
    }

    /** @param array<string,mixed> $profile */
    private function isUsableProfile(array $profile): bool
    {
        $status = strtolower(trim((string)($profile['capture_status'] ?? '')));

        return in_array($status, ['available', 'partial', 'verified', 'ok'], true);
    }

    /** @return array<string,int> */
    private function qualityStatusCounts(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $status = (string)($row['quality_status'] ?? 'unverified');
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }
        ksort($counts);
        return $counts;
    }

    private function aggregateQualityStatus(array $rows): string
    {
        if ($rows === []) {
            return 'missing';
        }
        $usableCount = count(array_filter($rows, static fn(array $row): bool => $row['usable_for_diagnosis']));
        if ($usableCount === count($rows)) {
            return 'usable';
        }
        return $usableCount > 0 ? 'partial' : 'unverified';
    }

    /** @return array<int,array<string,mixed>> */
    private function deduplicateRows(array $rows, callable $keyBuilder): array
    {
        $deduplicated = [];
        foreach ($rows as $row) {
            $key = (string)$keyBuilder($row);
            if (!isset($deduplicated[$key]) || (int)$row['id'] >= (int)$deduplicated[$key]['id']) {
                $deduplicated[$key] = $row;
            }
        }
        usort($deduplicated, static function (array $left, array $right): int {
            $dateCompare = strcmp((string)$left['date'], (string)$right['date']);
            return $dateCompare !== 0 ? $dateCompare : ((int)$left['id'] <=> (int)$right['id']);
        });
        return array_values($deduplicated);
    }

    /** @return array<string,mixed> */
    private function decodeRaw(array $row): array
    {
        $raw = $row['raw_data'] ?? [];
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function businessCompareType(array $row, array $raw, string $otaHotelId, string $ownOtaHotelId): string
    {
        if ($ownOtaHotelId !== '' && $otaHotelId === $ownOtaHotelId) {
            return 'self';
        }
        $compareType = strtolower(trim((string)($row['compare_type'] ?? $raw['compareType'] ?? $raw['compare_type'] ?? '')));
        $name = strtolower(preg_replace('/\s+/u', '', (string)($row['hotel_name'] ?? $raw['hotelName'] ?? '')) ?? '');
        if ($compareType === 'self'
            || !empty($raw['isSelf'])
            || !empty($raw['is_self'])
            || in_array($name, ['我的酒店', '本店', 'myhotel', 'currenthotel'], true)
        ) {
            return 'self';
        }
        return 'competitor';
    }

    private function metricNumber(array $row, array $raw, string $column, array $rawAliases): ?float
    {
        $rawValue = $this->rawNumber($raw, $rawAliases);
        if ($rawValue !== null) {
            return $rawValue;
        }
        $flags = json_decode((string)($row['validation_flags'] ?? ''), true);
        if (is_array($flags)) {
            foreach ($flags as $flag) {
                $code = is_array($flag) ? (string)($flag['code'] ?? '') : (string)$flag;
                $field = is_array($flag) ? (string)($flag['field'] ?? '') : '';
                if ($code === 'field_missing:' . $column || ($field === $column && str_contains($code, 'missing'))) {
                    return null;
                }
            }
        }
        $value = $row[$column] ?? null;
        return is_numeric($value) ? (float)$value : null;
    }

    private function rawNumber(array $raw, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $raw) && is_numeric($raw[$key])) {
                return (float)$raw[$key];
            }
        }
        return null;
    }

    private function positiveInteger(?float $value): ?int
    {
        return $value !== null && $value > 0 ? (int)round($value) : null;
    }

    private function isUsableQualityStatus(string $status): bool
    {
        return in_array($status, ['normal', 'available', 'ok', 'valid', 'verified'], true);
    }

    /** @param array<string,mixed> $row */
    private function effectiveQualityStatus(array $row): string
    {
        if ((int)($row['readback_verified'] ?? 0) !== 1) {
            return 'readback_unverified';
        }

        $status = strtolower(trim((string)($row['validation_status'] ?? 'unverified')));
        return $status !== '' ? $status : 'unverified';
    }

    /** @return array{0:string,1:string} */
    private function normalizeDateRange(string $startDate, string $endDate): array
    {
        $endDate = $this->normalizeDate($endDate) ?: date('Y-m-d');
        $startDate = $this->normalizeDate($startDate) ?: date('Y-m-d', strtotime($endDate . ' -29 days'));
        if (strcmp($startDate, $endDate) > 0) {
            throw new \InvalidArgumentException('Start date cannot be after end date.');
        }
        if ((strtotime($endDate) - strtotime($startDate)) > 366 * 86400) {
            throw new \InvalidArgumentException('Date range cannot exceed 366 days.');
        }
        return [$startDate, $endDate];
    }

    private function normalizeDate(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return '';
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $parsed !== false && $parsed->format('Y-m-d') === $value ? $value : '';
    }
}
