<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;
use think\facade\Db;

class OtaStandardEtlService
{
    private const CTRIP_MARKET_OVERVIEW_BOOKING_DIMENSION =
        'semantic:ctrip_business_market_overview:booking_order_count';
    private const CTRIP_MARKET_OVERVIEW_BOOKING_PROJECTION_VERSION =
        'ctrip_market_overview_metric_projection.v1';
    private const CTRIP_MARKET_OVERVIEW_BOOKING_SEMANTIC_KEY =
        'ctrip_market_overview_booking_order_count';

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function buildDataset(array $filters = []): array
    {
        $dataset = $this->buildDatasetFromRows($this->fetchRows($filters));
        if (is_array($dataset['data_quality']['order_dedup'] ?? null)) {
            $dataset['data_quality']['order_dedup']['evidence_status'] =
                'trusted_query_only';
        }
        return $dataset;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public function buildDatasetFromRows(array $rows): array
    {
        $inputRowCount = count($rows);
        [$rows, $semanticRejectedRows] = $this->resolveLegacyMeituanBusinessSemantics($rows);
        [$rows, $orderDedupQuality] = $this->deduplicateOrderRows($rows);
        [$rows, $supersededCtripCheckoutRows] = $this->selectCanonicalCtripCheckoutRows($rows);
        [
            $rows,
            $supersededMeituanRevenueRows,
            $meituanRevenueRepresentationConflicts,
        ] = $this->selectCanonicalMeituanRevenueRows($rows);
        [$rows, $supersededPeriodRows] = $this->selectCanonicalPeriodRows($rows);
        $hotels = [];
        $platforms = [];
        $dailyFacts = [];
        $trafficFacts = [];
        $advertisingFacts = [];
        $qualityFacts = [];
        $searchKeywordFacts = [];
        $peerRankFacts = [];
        $trafficAnalysisFacts = [];
        $trafficForecastFacts = [];
        $orderFlowFacts = [];
        $commentFacts = [];
        $rejectedRows = $semanticRejectedRows;

        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row)) {
                $rejectedRows[] = ['index' => $index, 'reason' => 'row_not_array'];
                continue;
            }

            $decodedRaw = $this->decodeJson($row['raw_data'] ?? []);
            $dataType = $this->normalizeDataType((string)($row['data_type'] ?? $decodedRaw['data_type'] ?? 'business'));
            $raw = $this->sanitizeRawData(
                $decodedRaw,
                $dataType === 'order',
                false,
                false,
                $dataType === 'business'
            );
            $source = $this->platformKey($this->firstText($row, $raw, ['platform', 'source', 'ota_source', 'otaSource']));
            $date = $this->dateValue($row['data_date'] ?? $row['date'] ?? $raw['dataDate'] ?? $raw['date'] ?? '');
            $hotelId = trim((string)($row['hotel_id'] ?? $raw['hotelId'] ?? $raw['poiId'] ?? ''));
            $hotelName = trim((string)($row['hotel_name'] ?? $raw['hotelName'] ?? $raw['poiName'] ?? ''));

            $missing = [];
            if ($source === '') {
                $missing[] = 'source';
            }
            if ($hotelId === '') {
                $missing[] = 'hotel_id';
            }
            if ($date === '') {
                $missing[] = 'data_date';
            }
            if ($missing) {
                $rejectedRows[] = ['index' => $index, 'reason' => 'missing_required_fields', 'fields' => $missing];
                continue;
            }

            $systemHotelId = (int)($row['system_hotel_id'] ?? $raw['system_hotel_id'] ?? 0);
            $hotelKey = $systemHotelId > 0 ? 'system:' . $systemHotelId : $source . ':' . $hotelId;
            $platforms[$source] = [
                'platform_key' => $source,
                'platform_name' => $this->platformName($source),
            ];
            $hotels[$hotelKey] = [
                'hotel_key' => $hotelKey,
                'system_hotel_id' => $systemHotelId > 0 ? $systemHotelId : null,
                'ota_hotel_id' => $hotelId,
                'hotel_name' => $hotelName,
                'primary_platform' => $source,
            ];

            if ($dataType === 'traffic') {
                $trafficFacts[] = $this->trafficFact($row, $raw, $hotelKey, $source, $date);
                continue;
            }
            if ($dataType === 'advertising') {
                $advertisingFacts[] = $this->advertisingFact($row, $raw, $hotelKey, $source, $date);
                continue;
            }
            if ($dataType === 'quality') {
                $qualityFacts[] = $this->qualityFact($row, $raw, $hotelKey, $source, $date);
                continue;
            }
            if ($dataType === 'search_keyword') {
                $searchKeywordFacts[] = $this->searchKeywordFact($row, $raw, $hotelKey, $source, $date);
                continue;
            }
            if ($dataType === 'peer_rank') {
                $peerRankFacts[] = $this->peerRankFact($row, $raw, $hotelKey, $source, $date);
                continue;
            }
            if ($dataType === 'traffic_analysis') {
                $trafficAnalysisFacts[] = $this->trafficAnalysisFact($row, $raw, $hotelKey, $source, $date);
                continue;
            }
            if ($dataType === 'traffic_forecast') {
                $trafficForecastFacts[] = $this->trafficForecastFact($row, $raw, $hotelKey, $source, $date);
                continue;
            }
            if ($dataType === 'order_flow') {
                $orderFlowFacts[] = $this->orderFlowFact($row, $raw, $hotelKey, $source, $date);
                continue;
            }
            if ($dataType === 'review') {
                $commentFacts[] = $this->commentFact($row, $raw, $hotelKey, $source, $date);
                continue;
            }
            if (!$this->isSelfRevenueFact($row, $raw, $dataType)) {
                $rejectedRows[] = [
                    'index' => $index,
                    'reason' => 'non_self_competitor_scope',
                    'data_type' => $dataType,
                    'compare_type' => strtolower($this->firstText($row, $raw, ['compare_type', 'compareType'])),
                ];
                continue;
            }
            $dailyFacts[] = $this->dailyFact(
                $row,
                $raw,
                $hotelKey,
                $source,
                $date,
                $dataType,
                $this->verifiedRoomRevenueBasis($row, $decodedRaw, $source, $dataType)
            );
        }

        $acceptedCount = count($dailyFacts)
            + count($trafficFacts)
            + count($advertisingFacts)
            + count($qualityFacts)
            + count($searchKeywordFacts)
            + count($peerRankFacts)
            + count($trafficAnalysisFacts)
            + count($trafficForecastFacts)
            + count($orderFlowFacts)
            + count($commentFacts);
        $acceptedFacts = array_merge(
            $dailyFacts,
            $trafficFacts,
            $advertisingFacts,
            $qualityFacts,
            $searchKeywordFacts,
            $peerRankFacts,
            $trafficAnalysisFacts,
            $trafficForecastFacts,
            $orderFlowFacts,
            $commentFacts
        );
        $trustedCount = count(array_filter(
            $acceptedFacts,
            static fn(array $fact): bool =>
                ($fact['source_trace']['saved_success'] ?? false) === true
        ));
        $datasetStatus = $acceptedCount === 0
            ? 'empty'
            : ($trustedCount === $acceptedCount
                ? 'ready'
                : ($trustedCount > 0 ? 'partial' : 'blocked'));
        return [
            'status' => $datasetStatus,
            'dim_hotel' => array_values($hotels),
            'dim_platform' => array_values($platforms),
            'fact_ota_daily' => $dailyFacts,
            'fact_ota_traffic' => $trafficFacts,
            'fact_ota_advertising' => $advertisingFacts,
            'fact_ota_quality' => $qualityFacts,
            'fact_ota_search_keyword' => $searchKeywordFacts,
            'fact_ota_peer_rank' => $peerRankFacts,
            'fact_ota_traffic_analysis' => $trafficAnalysisFacts,
            'fact_ota_traffic_forecast' => $trafficForecastFacts,
            'fact_ota_order_flow' => $orderFlowFacts,
            'fact_ota_comment' => $commentFacts,
            'data_quality' => [
                'source_input_rows' => $inputRowCount,
                'input_rows' => count($rows),
                'canonical_rows' => count($rows),
                'superseded_period_rows' => $supersededPeriodRows,
                'superseded_ctrip_checkout_rows' => $supersededCtripCheckoutRows,
                'superseded_meituan_revenue_rows' => $supersededMeituanRevenueRows,
                'meituan_revenue_representation_conflicts' =>
                    $meituanRevenueRepresentationConflicts,
                'order_dedup' => $orderDedupQuality,
                'accepted_rows' => $acceptedCount,
                'trusted_rows' => $trustedCount,
                'untrusted_rows' => $acceptedCount - $trustedCount,
                'rejected_rows' => $rejectedRows,
            ],
        ];
    }

    /**
     * Historical Meituan rank rows were stored as business rows. Reclassify
     * only rows with an explicit rank value; reject rank-shaped conflicts so
     * their amount fields cannot become OTA revenue.
     *
     * @param array<int, mixed> $rows
     * @return array{0:array<int, mixed>,1:array<int, array<string, mixed>>}
     */
    private function resolveLegacyMeituanBusinessSemantics(array $rows): array
    {
        $resolvedRows = [];
        $rejectedRows = [];
        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row)) {
                $resolvedRows[] = $row;
                continue;
            }

            $raw = $this->decodeJson($row['raw_data'] ?? []);
            $dataType = $this->normalizeDataType((string)($row['data_type'] ?? $raw['data_type'] ?? 'business'));
            $source = $this->platformKey($this->firstText($row, $raw, ['platform', 'source', 'ota_source', 'otaSource']));
            if ($source !== 'meituan' || $dataType !== 'business') {
                $resolvedRows[] = $row;
                continue;
            }

            $disposition = $this->legacyMeituanBusinessRankDisposition($row, $raw);
            if ($disposition === '') {
                $resolvedRows[] = $row;
                continue;
            }
            if ($disposition === 'peer_rank') {
                $row['data_type'] = 'peer_rank';
                $resolvedRows[] = $row;
                continue;
            }

            $rejectedRows[] = [
                'index' => $index,
                'reason' => 'semantic_type_conflict',
                'declared_data_type' => 'business',
                'detected_semantics' => 'peer_rank',
            ];
        }

        return [$resolvedRows, $rejectedRows];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     */
    private function legacyMeituanBusinessRankDisposition(array $row, array $raw): string
    {
        $detail = $this->rawDetail($raw);
        $dimension = strtolower($this->firstText($row, $detail, ['dimension', 'dimName', '_dimName', 'metricName', 'aiMetricName']));
        $rankType = $this->firstText($row, $detail, ['rank_type', 'rankType', 'rankListType']);
        $aiMetricName = strtoupper($this->firstText($row, $detail, ['aiMetricName', 'ai_metric_name']));
        $endpoint = strtolower($this->firstText($row, $detail, ['url', 'request_url', 'requestUrl', 'endpoint', 'api_url', 'apiUrl', 'source_url', 'sourceUrl']));
        $compareType = strtolower($this->firstText($row, $detail, ['compare_type', 'compareType']));
        $rank = $this->nullableNumber($row, $detail, ['rank', 'rank_no', 'rankNo', 'currentRank', 'sort']);
        $peerIdentity = $this->firstText($row, $detail, ['poiName', 'peerPoiId', 'peer_poi_id', 'poiId', 'poi_id']);
        $hasRankSignal = str_starts_with($dimension, 'peer_rank')
            || str_contains($dimension, '榜')
            || $rankType !== ''
            || str_starts_with($aiMetricName, 'P_RZ')
            || str_starts_with($aiMetricName, 'P_XS')
            || str_starts_with($aiMetricName, 'P_LL')
            || str_starts_with($aiMetricName, 'P_ZH')
            || str_contains($endpoint, '/peer/rank')
            || str_contains($endpoint, 'peerrank')
            || ($rank !== null && $peerIdentity !== '')
            || in_array($compareType, ['competitor', 'competitor_avg', 'peer'], true);
        if (!$hasRankSignal) {
            return '';
        }

        return $rank !== null && $rank > 0 ? 'peer_rank' : 'conflict';
    }

    /**
     * The Ctrip collector can persist both a catalog projection and the
     * underlying endpoint row for one response. Keep exactly one checkout fact
     * per hotel/date/run so amount/quantity are not counted twice. A newer run
     * always wins; within the same run, the row with exact captured checkout
     * field facts wins over a generic booking projection.
     *
     * @param array<int, mixed> $rows
     * @return array{0:array<int, mixed>,1:int}
     */
    private function selectCanonicalCtripCheckoutRows(array $rows): array
    {
        $selected = [];
        $grouped = [];
        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row)) {
                $selected[$index] = $row;
                continue;
            }

            $raw = $this->decodeJson($row['raw_data'] ?? []);
            $source = $this->platformKey($this->firstText($row, $raw, ['platform', 'source', 'ota_source', 'otaSource']));
            $dataType = $this->normalizeDataType((string)($row['data_type'] ?? $raw['data_type'] ?? 'business'));
            if ($this->isCtripMarketOverviewBookingProjection($row, $raw)) {
                $selected[$index] = $row;
                continue;
            }
            if ($source !== 'ctrip'
                || $dataType !== 'business'
                || $this->ctripBusinessEndpointId($row, $raw) !== 'business_market_overview'
            ) {
                $selected[$index] = $row;
                continue;
            }

            $systemHotelId = (int)($row['system_hotel_id'] ?? $raw['system_hotel_id'] ?? 0);
            $hotelIdentity = $systemHotelId > 0
                ? 'system:' . $systemHotelId
                : trim((string)($row['hotel_id'] ?? $raw['hotel_id'] ?? $raw['poiId'] ?? ''));
            $date = (string)($row['data_date'] ?? $raw['data_date'] ?? $raw['date'] ?? '');
            $grouped[implode('|', [
                (string)max(0, (int)($row['tenant_id'] ?? $raw['tenant_id'] ?? 0)),
                $source,
                $hotelIdentity,
                trim((string)($row['hotel_id'] ?? $raw['hotel_id'] ?? $raw['poiId'] ?? '')),
                $date,
            ])][] = [
                'index' => $index,
                'row' => $row,
            ];
        }

        $superseded = 0;
        foreach ($grouped as $items) {
            usort($items, function (array $left, array $right): int {
                $leftRow = $left['row'];
                $rightRow = $right['row'];
                $leftTask = max(0, (int)($leftRow['sync_task_id'] ?? 0));
                $rightTask = max(0, (int)($rightRow['sync_task_id'] ?? 0));
                if ($leftTask > 0 && $rightTask > 0 && $leftTask !== $rightTask) {
                    return $leftTask <=> $rightTask;
                }
                if ($leftTask === $rightTask && $leftTask > 0) {
                    $score = $this->ctripCheckoutFactScore($leftRow)
                        <=> $this->ctripCheckoutFactScore($rightRow);
                    if ($score !== 0) {
                        return $score;
                    }
                }

                $order = $this->periodRowOrder($leftRow) <=> $this->periodRowOrder($rightRow);
                if ($order !== 0) {
                    return $order;
                }
                return $this->ctripCheckoutFactScore($leftRow)
                    <=> $this->ctripCheckoutFactScore($rightRow);
            });
            $winner = $items[count($items) - 1];
            $selected[(int)$winner['index']] = $winner['row'];
            $superseded += max(0, count($items) - 1);
        }

        ksort($selected);
        return [array_values($selected), $superseded];
    }

    /** @param array<string, mixed> $row */
    private function ctripCheckoutFactScore(array $row): int
    {
        $raw = $this->decodeJson($row['raw_data'] ?? []);
        $score = 0;
        if ($this->hasCapturedFieldFactSource(
            $raw,
            'order_amount',
            'online_daily_data.amount',
            'amount'
        )) {
            $score++;
        }
        if ($this->hasCapturedFieldFactSource(
            $raw,
            'room_nights',
            'online_daily_data.quantity',
            'quantity'
        )) {
            $score++;
        }
        return $score;
    }

    /**
     * A booking projection is allowed to bypass checkout canonicalization only
     * when the persisted row, exact source key, semantic contract and readback
     * evidence all agree. Legacy booking-shaped market rows remain fail-closed.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     */
    private function isCtripMarketOverviewBookingProjection(array $row, array $raw): bool
    {
        $dataDate = trim((string)($row['data_date'] ?? ''));
        $systemHotelId = (int)($row['system_hotel_id'] ?? 0);
        $platformHotelId = trim((string)($row['hotel_id'] ?? ''));
        $rowTraceId = trim((string)($row['source_trace_id'] ?? ''));
        $rawTraceId = trim((string)($raw['source_trace_id'] ?? ''));
        if ((int)($row['id'] ?? 0) <= 0
            || (int)($row['tenant_id'] ?? 0) <= 0
            || $systemHotelId <= 0
            || $platformHotelId === ''
            || $this->platformKey(trim((string)($row['platform'] ?? ''))) !== 'ctrip'
            || $this->platformKey(trim((string)($row['source'] ?? ''))) !== 'ctrip'
            || $this->normalizeDataType((string)($row['data_type'] ?? '')) !== 'business'
            || $this->dateValue($dataDate) !== $dataDate
            || trim((string)($row['dimension'] ?? '')) !== self::CTRIP_MARKET_OVERVIEW_BOOKING_DIMENSION
            || $rowTraceId === ''
            || $rawTraceId !== $rowTraceId
        ) {
            return false;
        }

        $sourceRow = is_array($raw['row'] ?? null) ? $raw['row'] : [];
        if (strtolower(trim((string)($sourceRow['endpoint_id'] ?? ''))) !== 'business_market_overview'
            || strtolower(trim((string)($sourceRow['section'] ?? ''))) !== 'business_overview'
            || !array_key_exists('bookOrderNum', $sourceRow)
        ) {
            return false;
        }

        $projection = is_array($raw['metric_projection'] ?? null)
            ? $raw['metric_projection']
            : [];
        foreach ([
            'contract_version' => self::CTRIP_MARKET_OVERVIEW_BOOKING_PROJECTION_VERSION,
            'metric_family' => 'booking',
            'metric_key' => 'order_count',
            'semantic_key' => self::CTRIP_MARKET_OVERVIEW_BOOKING_SEMANTIC_KEY,
            'unit' => 'orders',
            'source_endpoint_id' => 'business_market_overview',
            'source_key' => 'bookOrderNum',
            'business_date' => $dataDate,
            'separate_from_metric_family' => 'checkout',
        ] as $key => $expected) {
            if (trim((string)($projection[$key] ?? '')) !== $expected) {
                return false;
            }
        }

        $storedOrderCount = $this->nonNegativeIntegerValue($row['book_order_num'] ?? null);
        $sourceOrderCount = $this->nonNegativeIntegerValue($sourceRow['bookOrderNum']);
        if ($storedOrderCount === null || $sourceOrderCount !== $storedOrderCount) {
            return false;
        }

        $orderFact = null;
        foreach ((array)($raw['field_facts'] ?? []) as $fact) {
            if (!is_array($fact)) {
                continue;
            }
            if (trim((string)($fact['metric_key'] ?? '')) !== 'order_count') {
                if (strtolower(trim((string)($fact['status'] ?? ''))) === 'captured'
                    || ($fact['stored_value_present'] ?? false) === true
                ) {
                    return false;
                }
                continue;
            }
            if ($orderFact !== null) {
                return false;
            }
            $orderFact = $fact;
        }
        if (!is_array($orderFact)) {
            return false;
        }

        $sourcePath = trim((string)($orderFact['source_path'] ?? ''));
        $captureEvidence = is_array($orderFact['capture_evidence'] ?? null)
            ? $orderFact['capture_evidence']
            : [];
        if (strtolower(trim((string)($orderFact['status'] ?? ''))) !== 'captured'
            || ($orderFact['stored_value_present'] ?? false) !== true
            || trim((string)($orderFact['data_type'] ?? '')) !== 'business'
            || trim((string)($orderFact['storage_field'] ?? '')) !== 'online_daily_data.book_order_num'
            || trim((string)($orderFact['normalized_field'] ?? '')) !== 'book_order_num'
            || trim((string)($orderFact['source_key'] ?? '')) !== 'bookOrderNum'
            || $sourcePath === ''
            || preg_match('/(?:^|\.)bookOrderNum$/', $sourcePath) !== 1
            || trim((string)($projection['source_path'] ?? '')) !== $sourcePath
            || trim((string)($orderFact['semantic_contract_version'] ?? '')) !== 'ota_metric_semantic_binding.v1'
            || trim((string)($orderFact['semantic_key'] ?? '')) !== self::CTRIP_MARKET_OVERVIEW_BOOKING_SEMANTIC_KEY
            || trim((string)($orderFact['unit'] ?? '')) !== 'orders'
            || trim((string)($orderFact['value_type'] ?? '')) !== 'non_negative_integer'
            || trim((string)($orderFact['source_endpoint_id'] ?? '')) !== 'business_market_overview'
            || trim((string)($captureEvidence['source_trace_id'] ?? '')) !== $rowTraceId
        ) {
            return false;
        }

        $rawSourceUrlHash = strtolower(trim((string)($raw['source_url_hash'] ?? '')));
        $factSourceUrlHash = strtolower(trim((string)($captureEvidence['source_url_hash'] ?? '')));
        if (($rawSourceUrlHash !== '' || $factSourceUrlHash !== '')
            && ($rawSourceUrlHash === ''
                || $factSourceUrlHash === ''
                || preg_match('/^[a-f0-9]{64}$/', $rawSourceUrlHash) !== 1
                || $factSourceUrlHash !== $rawSourceUrlHash)
        ) {
            return false;
        }

        $trace = $this->rowTrace(
            $row,
            'system:' . $systemHotelId,
            'ctrip',
            'business',
            $dataDate
        );
        return ($trace['saved_success'] ?? false) === true;
    }

    private function nonNegativeIntegerValue(mixed $value): ?int
    {
        if (is_string($value)) {
            $value = trim($value);
        }
        if ($value === '' || $value === null || !is_numeric($value)) {
            return null;
        }
        $number = (float)$value;
        if (!is_finite($number)
            || $number < 0
            || floor($number) !== $number
            || $number > PHP_INT_MAX
        ) {
            return null;
        }
        return (int)$number;
    }

    /**
     * Meituan can expose the same target-day sales through the official
     * business cards and a paginated order aggregate. They are alternative
     * evidence families, not additive revenue. Keep the newest family and
     * prefer the business-card readback when both were captured in one run.
     *
     * @param array<int, mixed> $rows
     * @return array{0:array<int, mixed>,1:int,2:array<int,array<string,mixed>>}
     */
    private function selectCanonicalMeituanRevenueRows(array $rows): array
    {
        $selected = [];
        $grouped = [];
        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row)) {
                $selected[$index] = $row;
                continue;
            }

            $raw = $this->decodeJson($row['raw_data'] ?? []);
            $source = $this->platformKey($this->firstText($row, $raw, ['platform', 'source', 'ota_source', 'otaSource']));
            $dataType = $this->normalizeDataType((string)($row['data_type'] ?? $raw['data_type'] ?? 'business'));
            if ($source !== 'meituan' || !$this->isMeituanRevenueSnapshotCandidate($raw, $dataType)) {
                $selected[$index] = $row;
                continue;
            }

            $systemHotelId = (int)($row['system_hotel_id'] ?? $raw['system_hotel_id'] ?? 0);
            $hotelIdentity = $systemHotelId > 0
                ? 'system:' . $systemHotelId
                : trim((string)($row['hotel_id'] ?? $raw['hotel_id'] ?? $raw['poiId'] ?? ''));
            $date = (string)($row['data_date'] ?? $raw['data_date'] ?? $raw['date'] ?? '');
            $grouped[$source . '|' . $hotelIdentity . '|' . $date][] = [
                'index' => $index,
                'row' => $row,
                'data_type' => $dataType,
            ];
        }

        $superseded = 0;
        $conflicts = [];
        foreach ($grouped as $items) {
            usort($items, function (array $left, array $right): int {
                $leftRow = $left['row'];
                $rightRow = $right['row'];
                $leftTask = max(0, (int)($leftRow['sync_task_id'] ?? 0));
                $rightTask = max(0, (int)($rightRow['sync_task_id'] ?? 0));
                if ($leftTask > 0 && $rightTask > 0 && $leftTask !== $rightTask) {
                    return $leftTask <=> $rightTask;
                }
                if ($leftTask === $rightTask && $leftTask > 0) {
                    $family = $this->meituanRevenueFamilyScore((string)$left['data_type'])
                        <=> $this->meituanRevenueFamilyScore((string)$right['data_type']);
                    if ($family !== 0) {
                        return $family;
                    }
                }

                $order = $this->periodRowOrder($leftRow) <=> $this->periodRowOrder($rightRow);
                if ($order !== 0) {
                    return $order;
                }
                return $this->meituanRevenueFamilyScore((string)$left['data_type'])
                    <=> $this->meituanRevenueFamilyScore((string)$right['data_type']);
            });
            $winner = $items[count($items) - 1];
            $selected[(int)$winner['index']] = $winner['row'];
            $superseded += max(0, count($items) - 1);
            $winnerAmount = is_numeric($winner['row']['amount'] ?? null)
                ? (float)$winner['row']['amount']
                : null;
            if ($winnerAmount === null) {
                continue;
            }
            foreach ($items as $candidate) {
                if ((int)$candidate['index'] === (int)$winner['index']) {
                    continue;
                }
                $candidateAmount = is_numeric($candidate['row']['amount'] ?? null)
                    ? (float)$candidate['row']['amount']
                    : null;
                if ($candidateAmount === null
                    || abs($candidateAmount - $winnerAmount) <= 0.01
                ) {
                    continue;
                }
                $delta = round($candidateAmount - $winnerAmount, 2);
                $conflicts[] = [
                    'system_hotel_id' => max(
                        0,
                        (int)($winner['row']['system_hotel_id'] ?? 0)
                    ) ?: null,
                    'business_date' => (string)($winner['row']['data_date'] ?? ''),
                    'winner_row_id' => max(
                        0,
                        (int)($winner['row']['id'] ?? 0)
                    ) ?: null,
                    'winner_data_type' => (string)$winner['data_type'],
                    'winner_amount' => round($winnerAmount, 2),
                    'winner_room_nights' => is_numeric(
                        $winner['row']['quantity'] ?? null
                    ) ? round((float)$winner['row']['quantity'], 2) : null,
                    'winner_order_count' => is_numeric(
                        $winner['row']['book_order_num'] ?? null
                    ) ? round((float)$winner['row']['book_order_num'], 2) : null,
                    'winner_sync_task_id' => max(
                        0,
                        (int)($winner['row']['sync_task_id'] ?? 0)
                    ) ?: null,
                    'winner_snapshot_time' => trim((string)(
                        $winner['row']['snapshot_time'] ?? ''
                    )) ?: null,
                    'winner_data_period' => trim((string)(
                        $winner['row']['data_period'] ?? ''
                    )) ?: null,
                    'winner_is_final' => $this->isFinalPeriodRow(
                        $winner['row']
                    ),
                    'candidate_row_id' => max(
                        0,
                        (int)($candidate['row']['id'] ?? 0)
                    ) ?: null,
                    'candidate_data_type' => (string)$candidate['data_type'],
                    'candidate_amount' => round($candidateAmount, 2),
                    'candidate_room_nights' => is_numeric(
                        $candidate['row']['quantity'] ?? null
                    ) ? round((float)$candidate['row']['quantity'], 2) : null,
                    'candidate_order_count' => is_numeric(
                        $candidate['row']['book_order_num'] ?? null
                    ) ? round((float)$candidate['row']['book_order_num'], 2) : null,
                    'candidate_sync_task_id' => max(
                        0,
                        (int)($candidate['row']['sync_task_id'] ?? 0)
                    ) ?: null,
                    'candidate_snapshot_time' => trim((string)(
                        $candidate['row']['snapshot_time'] ?? ''
                    )) ?: null,
                    'candidate_data_period' => trim((string)(
                        $candidate['row']['data_period'] ?? ''
                    )) ?: null,
                    'candidate_is_final' => $this->isFinalPeriodRow(
                        $candidate['row']
                    ),
                    'amount_delta' => $delta,
                    'amount_delta_percent_of_winner' => $winnerAmount > 0
                        ? round(abs($delta) / $winnerAmount * 100, 2)
                        : null,
                ];
            }
        }

        ksort($selected);
        return [array_values($selected), $superseded, $conflicts];
    }

    /** @param array<string, mixed> $raw */
    private function isMeituanRevenueSnapshotCandidate(array $raw, string $dataType): bool
    {
        $detail = $this->rawDetail($raw);
        if ($dataType === 'business') {
            return (string)($detail['business_evidence_source'] ?? '') === 'page.business_period_selection.readback'
                || (string)($detail['_capture_source'] ?? '') === 'xhr:traffic:business_data';
        }
        if ($dataType !== 'order') {
            return false;
        }
        return (string)($detail['amount_scope'] ?? '') === 'meituan_sale_price_total'
            && $this->explicitBoolean($detail['pagination_complete'] ?? null, true);
    }

    private function meituanRevenueFamilyScore(string $dataType): int
    {
        return $dataType === 'business' ? 2 : ($dataType === 'order' ? 1 : 0);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function fetchRows(array $filters): array
    {
        if (!$this->tableExists('online_daily_data')) {
            throw new RuntimeException('online_daily_data table does not exist', 422);
        }

        $columns = $this->tableColumns('online_daily_data');
        $fields = array_values(array_intersect([
            'id',
            'tenant_id',
            'system_hotel_id',
            'hotel_id',
            'hotel_name',
            'data_date',
            'amount',
            'room_revenue',
            'gross_revenue',
            'net_revenue',
            'quantity',
            'book_order_num',
            'comment_score',
            'qunar_comment_score',
            'raw_data',
            'data_value',
            'source',
            'dimension',
            'data_type',
            'platform',
            'compare_type',
            'list_exposure',
            'detail_exposure',
            'flow_rate',
            'order_filling_num',
            'order_submit_num',
            'available_rooms',
            'available_room_nights',
            'salable_rooms',
            'salable_room_nights',
            'total_rooms_count',
            'rooms_total',
            'occupied_rooms',
            'occupied_room_nights',
            'commission_amount',
            'commission',
            'commission_rate',
            'ota_commission',
            'ota_commission_rate',
            'cancel_order_num',
            'cancel_room_nights',
            'cancel_rate',
            'our_price',
            'competitor_price',
            'price_gap',
            'price_difference',
            'booking_date',
            'order_date',
            'checkin_date',
            'checkout_date',
            'lead_time_days',
            'booking_window',
            'update_time',
            'updated_at',
            'create_time',
            'created_at',
            'status',
            'save_status',
            'validation_status',
            'history_status',
            'validation_flags',
            'readback_verified',
            'readback_verified_at',
            'error_info',
            'failure_reason',
            'failed_reason',
            'data_source_id',
            'sync_task_id',
            'ingestion_method',
            'source_trace_id',
            'data_period',
            'collected_at',
            'snapshot_time',
            'snapshot_bucket',
            'is_final',
        ], array_keys($columns)));

        $query = Db::name('online_daily_data')->field($fields ?: '*');
        $strictReadbackOnly = in_array(
            $filters['strict_readback_only'] ?? false,
            [true, 1, '1', 'true'],
            true
        );
        if ($strictReadbackOnly) {
            foreach (['history_status', 'validation_status', 'readback_verified'] as $requiredColumn) {
                if (!isset($columns[$requiredColumn])) {
                    throw new RuntimeException(
                        'strict_readback_only requires online_daily_data.' . $requiredColumn,
                        422
                    );
                }
            }
            $query
                ->where('history_status', 'success')
                ->where('validation_status', 'verified')
                ->where('readback_verified', 1);
        }
        if (isset($columns['readback_verified'])) {
            $query->where('readback_verified', 1);
        }
        if (isset($columns['validation_status'])) {
            $blocked = OnlineDataTrustStatusService::quotedSqlList(OnlineDataTrustStatusService::blockingValidationStatuses());
            $query->whereRaw("(`validation_status` IS NULL OR LOWER(TRIM(`validation_status`)) NOT IN ({$blocked}))");
        }
        if (isset($columns['status'])) {
            $blocked = OnlineDataTrustStatusService::quotedSqlList(OnlineDataTrustStatusService::blockingRowStatuses());
            $query->whereRaw("(`status` IS NULL OR LOWER(TRIM(`status`)) NOT IN ({$blocked}))");
        }
        $this->applySystemHotelScopeFilter($query, $filters, $columns);
        $sourceFilter = trim((string)($filters['source'] ?? $filters['platform'] ?? ''));
        if ($sourceFilter !== '') {
            $platformFilter = $this->platformKey($sourceFilter);
            if ($platformFilter !== '' && isset($columns['platform'])) {
                $query->whereRaw('LOWER(TRIM(`platform`)) = :requested_platform', [
                    'requested_platform' => $platformFilter,
                ]);
            } elseif (isset($columns['source'])) {
                $query->whereIn('source', $this->sourceFilterValues($sourceFilter));
            }
        }
        $dataTypeFilter = trim((string)($filters['data_type'] ?? ''));
        if ($dataTypeFilter !== '' && isset($columns['data_type'])) {
            $query->where('data_type', $this->normalizeDataType($dataTypeFilter));
        }
        $hotelIdFilter = trim((string)($filters['hotel_id'] ?? ''));
        if ($hotelIdFilter !== '' && isset($columns['hotel_id'])) {
            $query->where('hotel_id', $hotelIdFilter);
        }
        if (!empty($filters['system_hotel_id']) && isset($columns['system_hotel_id'])) {
            $query->where('system_hotel_id', (int)$filters['system_hotel_id']);
        }
        if (!empty($filters['start_date']) && isset($columns['data_date'])) {
            $startDate = $this->filterDateValue($filters['start_date'], 'start_date');
            $query->where('data_date', '>=', $startDate);
        }
        if (!empty($filters['end_date']) && isset($columns['data_date'])) {
            $endDate = $this->filterDateValue($filters['end_date'], 'end_date');
            $query->where('data_date', '<=', $endDate);
        }

        $pageSize = (int)($filters['limit'] ?? 1000);
        $pageSize = max(1, min(5000, $pageSize));
        $maxRows = (int)($filters['max_rows'] ?? 100000);
        $maxRows = max($pageSize, min(250000, $maxRows));
        $rows = [];
        $offset = 0;
        while (true) {
            $batch = (clone $query)
                ->order('data_date', 'desc')
                ->order('id', 'desc')
                ->limit($offset, $pageSize)
                ->select()
                ->toArray();
            if ($batch === []) {
                break;
            }
            if (count($rows) + count($batch) > $maxRows) {
                throw new RuntimeException(
                    'OTA dataset exceeds the safe row window; narrow the hotel/date/platform scope instead of using truncated metrics.',
                    422
                );
            }
            $rows = array_merge($rows, $batch);
            if (count($batch) < $pageSize) {
                break;
            }
            $offset += $pageSize;
        }

        return $rows;
    }

    /**
     * @param object $query
     * @param array<string, mixed> $filters
     * @param array<string, mixed> $columns
     */
    private function applySystemHotelScopeFilter(object $query, array $filters, array $columns): void
    {
        $rawIds = $filters['permitted_hotel_ids'] ?? [];
        if (!is_array($rawIds)) {
            return;
        }
        $hotelIds = array_values(array_unique(array_filter(
            array_map('intval', $rawIds),
            static fn(int $hotelId): bool => $hotelId > 0
        )));
        sort($hotelIds);
        if ($hotelIds === []) {
            return;
        }
        if (!isset($columns['system_hotel_id'])) {
            throw new RuntimeException('system_hotel_id column is required for permitted hotel scope', 422);
        }
        $query->whereIn('system_hotel_id', $hotelIds);
    }

    /**
     * Keep one cumulative snapshot per business grain. Final historical rows win;
     * otherwise the latest realtime snapshot is used. Only event rows with a
     * stable business event ID bypass snapshot canonicalization.
     *
     * @param array<int, mixed> $rows
     * @return array{0:array<int, mixed>,1:int}
     */
    private function selectCanonicalPeriodRows(array $rows): array
    {
        $grouped = [];
        $selected = [];
        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row)) {
                $selected[$index] = $row;
                continue;
            }
            $dataType = $this->normalizeDataType((string)($row['data_type'] ?? 'business'));
            $period = $this->snapshotPeriod($row);
            if ($period === '') {
                $selected[$index] = $row;
                continue;
            }
            $raw = $this->decodeJson($row['raw_data'] ?? []);
            if (in_array($dataType, ['order', 'review'], true) && $this->stableEventIdentity($row, $raw, $dataType) !== '') {
                $selected[$index] = $row;
                continue;
            }
            $source = $this->platformKey($this->firstText($row, $raw, ['platform', 'source', 'ota_source', 'otaSource']));
            $systemHotelId = (int)($row['system_hotel_id'] ?? $raw['system_hotel_id'] ?? 0);
            $hotelIdentity = $systemHotelId > 0
                ? 'system:' . $systemHotelId
                : trim((string)($row['hotel_id'] ?? $raw['hotel_id'] ?? $raw['poiId'] ?? ''));
            $key = implode('|', [
                $source,
                $hotelIdentity,
                (string)($row['hotel_id'] ?? $raw['hotel_id'] ?? $raw['poiId'] ?? ''),
                (string)($row['data_date'] ?? $raw['data_date'] ?? $raw['date'] ?? ''),
                $dataType,
                (string)($row['dimension'] ?? $raw['dimension'] ?? ''),
                (string)($row['compare_type'] ?? $raw['compare_type'] ?? 'self'),
                $this->snapshotBusinessIdentity($row, $raw, $dataType),
            ]);
            $grouped[$key][] = ['index' => $index, 'row' => $row];
        }

        $superseded = 0;
        foreach ($grouped as $items) {
            $finalItems = array_values(array_filter($items, fn(array $item): bool => $this->isFinalPeriodRow($item['row'])));
            $candidates = $finalItems !== [] ? $finalItems : $items;
            usort($candidates, fn(array $left, array $right): int => $this->periodRowOrder($left['row']) <=> $this->periodRowOrder($right['row']));
            $winner = $candidates[count($candidates) - 1];
            $selected[(int)$winner['index']] = $winner['row'];
            $superseded += max(0, count($items) - 1);
        }

        ksort($selected);
        return [array_values($selected), $superseded];
    }

    /**
     * Keep the latest trusted version of each exact OTA order identity. The
     * grain is deliberately strict: system hotel, explicit platform, exact
     * business date and a normalized order hash. Incomplete or wholly
     * untrusted groups remain in the dataset and cannot claim verification.
     *
     * @param array<int, mixed> $rows
     * @return array{0:array<int,mixed>,1:array<string,mixed>}
     */
    private function deduplicateOrderRows(array $rows): array
    {
        $selected = [];
        $groups = [];
        $candidateRows = 0;
        $coveredRows = 0;

        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row)) {
                $selected[$index] = $row;
                continue;
            }
            $raw = $this->decodeJson($row['raw_data'] ?? []);
            $dataType = $this->normalizeDataType((string)(
                $row['data_type'] ?? $raw['data_type'] ?? 'business'
            ));
            if ($dataType !== 'order'
                || !$this->isSelfRevenueFact($row, $raw, $dataType)
            ) {
                $selected[$index] = $row;
                continue;
            }

            $candidateRows++;
            $source = $this->platformKey($this->firstText(
                $row,
                $this->rawDetail($raw),
                ['platform', 'source', 'ota_source', 'otaSource']
            ));
            $systemHotelId = (int)($row['system_hotel_id']
                ?? $raw['system_hotel_id']
                ?? 0);
            $businessDate = $this->dateValue(
                $row['data_date']
                ?? $row['date']
                ?? $raw['dataDate']
                ?? $raw['data_date']
                ?? $raw['date']
                ?? ''
            );
            $orderIdentityHash = $this->verifiedOrderIdentityHash($row, $raw);
            if ($systemHotelId <= 0
                || $source === ''
                || !$this->isExactBusinessDate($businessDate)
                || $orderIdentityHash === ''
            ) {
                $selected[$index] = $row;
                continue;
            }

            $trace = $this->rowTrace(
                $row,
                'system:' . $systemHotelId,
                $source,
                'order',
                $businessDate
            );
            $trusted = ($trace['saved_success'] ?? false) === true;
            if ($trusted) {
                $coveredRows++;
            }
            $key = implode('|', [
                (string)$systemHotelId,
                $source,
                $businessDate,
                $orderIdentityHash,
            ]);
            $groups[$key][] = [
                'index' => $index,
                'row' => $row,
                'trusted' => $trusted,
            ];
        }

        $verifiedGrains = 0;
        $suppressedRows = 0;
        $suppressedUntrustedRows = 0;
        $newerUntrustedRows = 0;
        foreach ($groups as $items) {
            $trustedItems = array_values(array_filter(
                $items,
                static fn(array $item): bool => $item['trusted'] === true
            ));
            if ($trustedItems === []) {
                foreach ($items as $item) {
                    $selected[(int)$item['index']] = $item['row'];
                }
                continue;
            }

            $verifiedGrains++;
            usort(
                $trustedItems,
                fn(array $left, array $right): int =>
                    $this->periodRowOrder($left['row'])
                    <=> $this->periodRowOrder($right['row'])
            );
            $winner = $trustedItems[count($trustedItems) - 1];
            $winnerOrder = $this->periodRowOrder($winner['row']);
            $selected[(int)$winner['index']] = $winner['row'];
            $suppressedRows += max(0, count($items) - 1);
            foreach ($items as $item) {
                if ((int)$item['index'] === (int)$winner['index']
                    || $item['trusted'] === true
                ) {
                    continue;
                }
                $suppressedUntrustedRows++;
                if ($this->periodRowOrder($item['row']) > $winnerOrder) {
                    $newerUntrustedRows++;
                }
            }
        }

        ksort($selected);
        $coveragePercent = $candidateRows > 0
            ? round(($coveredRows / $candidateRows) * 100, 2)
            : null;

        return [array_values($selected), [
            'policy' =>
                'latest_trusted_per_system_hotel_platform_data_date_order_id_hash',
            'coverage_unit' => 'scoped_order_evidence_rows',
            'evidence_status' => 'provided_rows_complete',
            'order_identity_candidate_rows' => $candidateRows,
            'order_identity_covered_rows' => $coveredRows,
            'order_identity_unverifiable_rows' =>
                max(0, $candidateRows - $coveredRows),
            'order_identity_coverage_percent' => $coveragePercent,
            'distinct_verified_order_grains' => $verifiedGrains,
            'suppressed_duplicate_order_rows' => $suppressedRows,
            'suppressed_untrusted_duplicate_order_rows' =>
                $suppressedUntrustedRows,
            'newer_untrusted_duplicate_order_rows' => $newerUntrustedRows,
        ]];
    }

    /** @param array<string, mixed> $row */
    private function snapshotPeriod(array $row): string
    {
        $period = strtolower(trim((string)($row['data_period'] ?? '')));
        return in_array($period, ['historical_daily', 'realtime_snapshot'], true) ? $period : '';
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     */
    private function stableEventIdentity(array $row, array $raw, string $dataType): string
    {
        if ($dataType === 'order') {
            $verifiedIdentity = $this->verifiedOrderIdentityHash($row, $raw);
            if ($verifiedIdentity !== '') {
                return 'order:' . $verifiedIdentity;
            }
        }
        $detail = $this->rawDetail($raw);
        $keys = $dataType === 'order'
            ? ['order_id_hash', 'orderIdHash', 'order_id', 'orderId', 'order_no', 'orderNo', 'order_sn', 'orderSn', 'booking_id', 'bookingId']
            : ['review_id_hash', 'reviewIdHash', 'comment_id_hash', 'commentIdHash', 'review_id', 'reviewId', 'comment_id', 'commentId'];
        $identity = $this->firstText($row, $detail, $keys);
        return $identity !== '' ? $dataType . ':' . $identity : '';
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     */
    private function verifiedOrderIdentityHash(array $row, array $raw): string
    {
        $sources = [$row, $raw];
        foreach (['row', 'metrics', 'detail', 'fields'] as $nestedKey) {
            if (is_array($raw[$nestedKey] ?? null)) {
                $sources[] = $raw[$nestedKey];
            }
        }

        foreach (['order_id_hash', 'orderIdHash', 'order_no_hash', 'booking_id_hash'] as $key) {
            foreach ($sources as $source) {
                $value = strtolower(trim((string)($source[$key] ?? '')));
                if (preg_match('/^[a-f0-9]{64}$/', $value) === 1) {
                    return $value;
                }
            }
        }

        foreach (['order_id', 'orderId', 'order_no', 'orderNo', 'order_sn', 'orderSn', 'booking_id', 'bookingId'] as $key) {
            foreach ($sources as $source) {
                $value = trim((string)($source[$key] ?? ''));
                if ($value !== '') {
                    return hash('sha256', 'ota_order|' . $value);
                }
            }
        }

        return '';
    }

    private function isExactBusinessDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date instanceof \DateTimeImmutable
            && $date->format('Y-m-d') === $value;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     */
    private function snapshotBusinessIdentity(array $row, array $raw, string $dataType): string
    {
        $detail = $this->rawDetail($raw);
        $keys = match ($dataType) {
            'advertising' => ['campaignId', 'campaign_id', 'campaignID', 'planId', 'plan_id', 'unitId', 'unit_id'],
            'peer_rank' => ['poiId', 'poi_id', 'peerPoiId', 'peer_poi_id', 'hotelId', 'hotel_id', 'shopId', 'shop_id'],
            'search_keyword' => ['keyword', 'searchKeyword', 'search_word', 'searchWord'],
            'traffic_forecast' => ['forecastDate', 'forecast_date', 'targetDate', 'target_date'],
            default => ['business_id', 'businessId', 'entity_id', 'entityId', 'item_id', 'itemId', 'room_type_id', 'roomTypeId'],
        };
        $identity = $this->firstText([], $detail, $keys);
        return $identity !== '' ? $dataType . ':' . $identity : '';
    }

    /** @param array<string, mixed> $row */
    private function isFinalPeriodRow(array $row): bool
    {
        $isFinal = $row['is_final'] ?? null;
        if (in_array($isFinal, [1, '1', true, 'true'], true)) {
            return true;
        }
        return strtolower(trim((string)($row['data_period'] ?? ''))) === 'historical_daily';
    }

    /** @param array<string, mixed> $row */
    private function periodRowOrder(array $row): int
    {
        foreach (['snapshot_time', 'snapshot_bucket', 'update_time', 'updated_at', 'create_time', 'created_at'] as $key) {
            $value = trim((string)($row[$key] ?? ''));
            if ($value !== '') {
                $time = strtotime($value);
                if ($time !== false) {
                    return $time * 1000000 + max(0, (int)($row['id'] ?? 0));
                }
            }
        }
        return max(0, (int)($row['id'] ?? 0));
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function dailyFact(
        array $row,
        array $raw,
        string $hotelKey,
        string $source,
        string $date,
        string $dataType,
        ?string $verifiedRoomRevenueBasis = null
    ): array
    {
        if ($this->isCtripMarketOverviewBookingProjection($row, $raw)) {
            return $this->ctripMarketOverviewBookingDailyFact(
                $row,
                $raw,
                $hotelKey,
                $source,
                $date,
                $dataType
            );
        }

        $grossRevenue = $this->nullableNumber($row, $raw, ['amount', 'gross_revenue', 'grossRevenue', 'revenue', 'totalAmount', 'saleAmount', 'order_amount', 'orderAmount']);
        $roomRevenue = $this->nullableNumber($row, $raw, ['room_revenue', 'roomRevenue', 'room_amount', 'roomAmount']);
        $roomNights = $this->nullableNumber($row, $raw, ['quantity', 'room_nights', 'roomNights', 'checkOutQuantity']);
        $ctripEndpointId = $source === 'ctrip' && $dataType === 'business'
            ? $this->ctripBusinessEndpointId($row, $raw)
            : '';
        $ctripOccupiedRoomNights = null;
        $metricSemanticScope = match ($verifiedRoomRevenueBasis) {
            'verified_meituan_business_sales_cards' => 'meituan_business_sales_daily',
            'verified_meituan_sale_price_total' => 'meituan_order_sale_price_daily',
            default => 'ota_daily_generic',
        };
        if ($ctripEndpointId === 'business_market_overview') {
            $metricSemanticScope = $verifiedRoomRevenueBasis === 'verified_ctrip_checkout_sales'
                ? 'ctrip_checkout_daily'
                : 'ctrip_booking_or_unverified_excluded';
            if ($verifiedRoomRevenueBasis !== 'verified_ctrip_checkout_sales') {
                $grossRevenue = null;
                $roomRevenue = null;
                $roomNights = null;
            }
        } elseif ($ctripEndpointId === 'business_capacity') {
            $metricSemanticScope = 'ctrip_capacity_daily';
            $ctripOccupiedRoomNights = $roomNights;
            $grossRevenue = null;
            $roomRevenue = null;
            $roomNights = null;
        } elseif ($ctripEndpointId !== '') {
            $metricSemanticScope = 'ctrip_non_revenue_business_fact';
            $grossRevenue = null;
            $roomRevenue = null;
            $roomNights = null;
        }
        $roomRevenueBasis = $roomRevenue !== null ? 'direct_room_revenue_field' : null;
        if ($roomRevenue === null
            && $verifiedRoomRevenueBasis !== null
            && $grossRevenue !== null
            && $roomNights !== null
            && $roomNights > 0
        ) {
            $roomRevenue = $grossRevenue;
            $roomRevenueBasis = $verifiedRoomRevenueBasis;
        }
        $orderCountValue = $this->nullableNumber($row, $raw, ['book_order_num', 'bookOrderNum', 'orderCount', 'orderNum', 'orders']);
        if ($ctripEndpointId !== '' && $ctripEndpointId !== 'business_capacity') {
            $orderCountValue = null;
        }
        $orders = $orderCountValue !== null ? (int)round($orderCountValue) : null;
        $cancelOrders = $this->nullableNumber($row, $raw, ['cancel_order_num', 'cancelOrderNum', 'cancel_orders', 'cancelOrders']);
        $grossOrderCountValue = $this->nullableNumber($row, $raw, [
            'gross_order_num',
            'grossOrderNum',
            'gross_order_count',
            'grossOrderCount',
        ]);
        $grossOrderCount = $grossOrderCountValue !== null
            ? (int)round($grossOrderCountValue)
            : null;
        $unknownStatusOrderCountValue = $this->nullableNumber($row, $raw, [
            'unknown_status_order_num',
            'unknownStatusOrderNum',
            'unknown_status_order_count',
            'unknownStatusOrderCount',
        ]);
        $unknownStatusOrderCount = $unknownStatusOrderCountValue !== null
            ? (int)round($unknownStatusOrderCountValue)
            : null;
        $cancelRateBasis = $this->firstText($row, $raw, [
            'cancel_rate_basis',
            'cancelRateBasis',
            'cancellation_rate_basis',
            'cancellationRateBasis',
        ]);
        $cancelRoomNights = $this->nullableNumber($row, $raw, ['cancel_room_nights', 'cancelRoomNights', 'cancelled_room_nights', 'cancelledRoomNights']);
        $cancelRate = $this->nullablePercent($row, $raw, ['cancel_rate', 'cancelRate', 'cancellation_rate', 'cancellationRate']);
        $availableRoomNights = $this->nullableNumber($row, $raw, [
            'available_room_nights',
            'availableRoomNights',
            'salable_room_nights',
            'salableRoomNights',
            'available_rooms',
            'availableRooms',
            'salable_rooms',
            'salableRooms',
        ]);
        $occupiedRoomNights = $ctripOccupiedRoomNights ?? $this->nullableNumber($row, $raw, [
            'occupied_room_nights',
            'occupiedRoomNights',
            'occupied_rooms',
            'occupiedRooms',
            'rooms_sold',
            'roomsSold',
        ]);
        if ($occupiedRoomNights === null
            && $ctripEndpointId === ''
            && $roomNights !== null
            && $roomNights > 0
        ) {
            $occupiedRoomNights = $roomNights;
        }
        $commissionRate = $this->nullablePercent($row, $raw, ['commission_rate', 'commissionRate', 'ota_commission_rate', 'otaCommissionRate']);
        $directCommissionAmount = $this->nullableNumber($row, $raw, ['commission_amount', 'commissionAmount', 'commission', 'ota_commission', 'otaCommission', 'channel_commission', 'channelCommission']);
        $commissionAmount = $directCommissionAmount;
        $commissionAmountBasis = $directCommissionAmount !== null ? 'direct' : null;
        if ($commissionAmount === null && $commissionRate !== null && $grossRevenue !== null) {
            $commissionAmount = round($grossRevenue * $commissionRate / 100, 2);
            $commissionAmountBasis = 'derived_from_commission_rate';
        }
        $settlementAmount = $this->nullableNumber($row, $raw, ['settlement_amount', 'settlementAmount']);
        $directNetRevenue = $this->nullableNumber($row, $raw, ['net_revenue', 'netRevenue', 'net_amount', 'netAmount', 'after_commission_revenue', 'afterCommissionRevenue']);
        $netRevenue = $directNetRevenue;
        $netRevenueBasis = $directNetRevenue !== null ? 'direct' : null;
        if ($netRevenue === null && $commissionAmount !== null && $grossRevenue !== null) {
            $netRevenue = round($grossRevenue - $commissionAmount, 2);
            $netRevenueBasis = 'derived_from_commission_amount';
        }
        $ourPrice = $this->nullableNumber($row, $raw, ['our_price', 'ourPrice', 'hotel_price', 'hotelPrice']);
        $competitorPrice = $this->nullableNumber($row, $raw, ['competitor_price', 'competitorPrice', 'market_price', 'marketPrice']);
        $priceGap = $this->nullableNumber($row, $raw, ['price_gap', 'priceGap', 'price_difference', 'priceDifference']);
        $bookingDate = $this->dateValue($this->firstText($row, $raw, ['booking_date', 'bookingDate', 'order_date', 'orderDate', 'create_date', 'createDate']));
        $checkinDate = $this->dateValue($this->firstText($row, $raw, ['checkin_date', 'checkinDate', 'arrival_date', 'arrivalDate', 'stay_date', 'stayDate']));
        if ($priceGap === null && $ourPrice !== null && $competitorPrice !== null) {
            $priceGap = round($ourPrice - $competitorPrice, 2);
        }

        return [
            'date_key' => $date,
            'hotel_key' => $hotelKey,
            'platform_key' => $source,
            'data_type' => $dataType,
            'dimension' => (string)($row['dimension'] ?? $raw['dimension'] ?? ''),
            'metric_scope' => 'ota_channel',
            'calculation_basis' => 'ota_daily_standard_fact',
            'metric_semantic_scope' => $metricSemanticScope,
            'revenue' => $grossRevenue !== null ? round($grossRevenue, 2) : null,
            'gross_revenue' => $grossRevenue !== null ? round($grossRevenue, 2) : null,
            'room_revenue' => $roomRevenue !== null ? round($roomRevenue, 2) : null,
            'room_revenue_basis' => $roomRevenueBasis,
            'net_revenue' => $netRevenue !== null ? round($netRevenue, 2) : null,
            'settlement_amount' => $settlementAmount !== null ? round($settlementAmount, 2) : null,
            'commission_amount' => $commissionAmount !== null ? round($commissionAmount, 2) : null,
            'commission_rate' => $commissionRate !== null ? round($commissionRate, 2) : null,
            'net_revenue_basis' => $netRevenueBasis,
            'commission_amount_basis' => $commissionAmountBasis,
            'room_nights' => $roomNights !== null ? round($roomNights, 2) : null,
            'available_room_nights' => $availableRoomNights !== null ? round($availableRoomNights, 2) : null,
            'occupied_room_nights' => $occupiedRoomNights !== null ? round($occupiedRoomNights, 2) : null,
            'order_count' => $orders,
            'adr' => $roomRevenue !== null && $roomNights !== null && $roomNights > 0 ? round($roomRevenue / $roomNights, 2) : null,
            'occ' => $availableRoomNights !== null && $availableRoomNights > 0 && $occupiedRoomNights !== null
                ? round($occupiedRoomNights / $availableRoomNights * 100, 2)
                : null,
            'revpar' => $roomRevenue !== null && $availableRoomNights !== null && $availableRoomNights > 0
                ? round($roomRevenue / $availableRoomNights, 2)
                : null,
            'net_revpar' => $availableRoomNights !== null && $availableRoomNights > 0 && $netRevenue !== null
                ? round($netRevenue / $availableRoomNights, 2)
                : null,
            'booking_date' => $bookingDate !== '' ? $bookingDate : null,
            'checkin_date' => $checkinDate !== '' ? $checkinDate : null,
            'lead_time_days' => $this->leadTimeDays($row, $raw),
            'comment_score' => $this->nullableNumber($row, $raw, ['comment_score', 'commentScore', 'score']),
            'data_value' => $this->nullableNumber($row, $raw, ['data_value', 'dataValue']),
            'cancel_order_num' => $cancelOrders,
            'gross_order_count' => $grossOrderCount,
            'unknown_status_order_count' => $unknownStatusOrderCount,
            'cancel_rate_basis' => $cancelRateBasis !== ''
                ? $cancelRateBasis
                : null,
            'cancel_room_nights' => $cancelRoomNights,
            'cancel_rate' => $cancelRate,
            'our_price' => $ourPrice,
            'competitor_price' => $competitorPrice,
            'price_gap' => $priceGap,
            'price_gap_rate' => $priceGap !== null && $competitorPrice !== null && $competitorPrice > 0
                ? round($priceGap / $competitorPrice * 100, 2)
                : null,
            'raw_data' => $raw,
            'source_trace' => $this->rowTrace($row, $hotelKey, $source, $dataType, $date),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function ctripMarketOverviewBookingDailyFact(
        array $row,
        array $raw,
        string $hotelKey,
        string $source,
        string $date,
        string $dataType
    ): array {
        return [
            'date_key' => $date,
            'hotel_key' => $hotelKey,
            'platform_key' => $source,
            'data_type' => $dataType,
            'dimension' => self::CTRIP_MARKET_OVERVIEW_BOOKING_DIMENSION,
            'metric_scope' => 'ota_channel',
            'calculation_basis' => 'ota_daily_standard_fact',
            'metric_semantic_scope' => 'ctrip_market_overview_booking_daily',
            'revenue' => null,
            'gross_revenue' => null,
            'room_revenue' => null,
            'room_revenue_basis' => null,
            'net_revenue' => null,
            'settlement_amount' => null,
            'commission_amount' => null,
            'commission_rate' => null,
            'net_revenue_basis' => null,
            'commission_amount_basis' => null,
            'room_nights' => null,
            'available_room_nights' => null,
            'occupied_room_nights' => null,
            'order_count' => $this->nonNegativeIntegerValue($row['book_order_num'] ?? null),
            'adr' => null,
            'occ' => null,
            'revpar' => null,
            'net_revpar' => null,
            'booking_date' => null,
            'checkin_date' => null,
            'lead_time_days' => null,
            'comment_score' => null,
            'data_value' => null,
            'cancel_order_num' => null,
            'cancel_room_nights' => null,
            'cancel_rate' => null,
            'our_price' => null,
            'competitor_price' => null,
            'price_gap' => null,
            'price_gap_rate' => null,
            'raw_data' => $raw,
            'source_trace' => $this->rowTrace($row, $hotelKey, $source, $dataType, $date),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function trafficFact(array $row, array $raw, string $hotelKey, string $source, string $date): array
    {
        $listExposure = $this->nullableNumber($row, $raw, ['list_exposure', 'listExposure', 'exposure_count', 'exposureCount']);
        $detailExposure = $this->nullableNumber($row, $raw, ['detail_exposure', 'detailExposure', 'page_views', 'pageViews']);
        $storedFlowRate = $this->nullablePercent($row, $raw, ['flow_rate', 'flowRate', 'conversion_rate', 'conversionRate']);
        $flowRate = $storedFlowRate;
        $flowRateBasis = $flowRate !== null ? 'stored_flow_rate' : 'missing';
        $flowRateValidationStatus = $flowRate !== null ? 'strict_readback' : 'missing';
        $flowRateQualityFlags = [];
        if ($source === 'meituan') {
            $detail = $this->rawDetail($raw);
            $platformExposureToBrowseRate = $this->nullablePercent([], $detail, [
                'exposure_to_browse_rate',
                'exposureToBrowseRate',
                'intentionPerExposure',
                'expose_visit_rate',
                'exposeVisitRate',
            ]);
            $calculatedExposureToBrowseRate = $listExposure !== null
                && $listExposure > 0
                && $detailExposure !== null
                && $detailExposure >= 0
                ? round($detailExposure / $listExposure * 100, 2)
                : null;

            if ($calculatedExposureToBrowseRate !== null) {
                $flowRate = $calculatedExposureToBrowseRate;
                $flowRateBasis = 'calculated_detail_exposure_over_list_exposure';
                $flowRateValidationStatus = 'verified_calculation';
                if ($platformExposureToBrowseRate !== null
                    && abs($platformExposureToBrowseRate - $calculatedExposureToBrowseRate) > 0.05
                ) {
                    $flowRate = null;
                    $flowRateBasis = 'caliber_uncertain';
                    $flowRateValidationStatus = 'caliber_uncertain';
                    $flowRateQualityFlags[] = 'platform_exposure_to_browse_rate_mismatch';
                } elseif ($platformExposureToBrowseRate !== null) {
                    $flowRateQualityFlags[] = 'verified_against_platform_exposure_to_browse_rate';
                }
            } elseif ($platformExposureToBrowseRate !== null) {
                $flowRate = $platformExposureToBrowseRate;
                $flowRateBasis = 'platform_exposure_to_browse_rate';
                $flowRateValidationStatus = 'strict_readback';
            }

            if ($storedFlowRate !== null
                && $flowRate !== null
                && abs($storedFlowRate - $flowRate) > 0.05
            ) {
                $flowRateQualityFlags[] = 'legacy_stored_flow_rate_semantic_mismatch';
            }
        }
        $orderFilling = $this->nullableNumber($row, $raw, ['order_filling_num', 'orderFillingNum', 'click_count', 'clickCount']);
        $orderSubmit = $this->nullableNumber($row, $raw, ['order_submit_num', 'orderSubmitNum', 'submit_users', 'submitUsers']);

        return [
            'date_key' => $date,
            'hotel_key' => $hotelKey,
            'platform_key' => $source,
            'compare_type' => (string)($row['compare_type'] ?? $raw['compare_type'] ?? 'self'),
            'list_exposure' => $listExposure !== null ? (int)round($listExposure) : null,
            'detail_exposure' => $detailExposure !== null ? (int)round($detailExposure) : null,
            'flow_rate' => $flowRate !== null ? round($flowRate, 2) : null,
            'stored_flow_rate' => $storedFlowRate !== null ? round($storedFlowRate, 2) : null,
            'flow_rate_basis' => $flowRateBasis,
            'flow_rate_validation_status' => $flowRateValidationStatus,
            'flow_rate_quality_flags' => array_values(array_unique($flowRateQualityFlags)),
            'order_filling_num' => $orderFilling !== null ? (int)round($orderFilling) : null,
            'order_submit_num' => $orderSubmit !== null ? (int)round($orderSubmit) : null,
            'submit_rate' => $orderFilling !== null && $orderFilling > 0 && $orderSubmit !== null
                ? round($orderSubmit / $orderFilling * 100, 2)
                : null,
            'raw_data' => $raw,
            'source_trace' => $this->rowTrace($row, $hotelKey, $source, 'traffic', $date),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function advertisingFact(array $row, array $raw, string $hotelKey, string $source, string $date): array
    {
        $detail = $this->rawDetail($raw);
        $spend = $this->nullableNumber($row, $detail, ['amount', 'todayCost', 'cost', 'ad_cost', 'adCost', 'spend']);
        $orderAmount = $this->nullableNumber($row, $detail, ['order_amount', 'orderAmount', 'saleAmount', 'revenue']);
        $impressionsValue = $this->nullableNumber($row, $detail, ['list_exposure', 'listExposure', 'impressions', 'exposure_count', 'exposureCount']);
        $clicksValue = $this->nullableNumber($row, $detail, ['detail_exposure', 'detailExposure', 'clicks', 'click_count', 'clickCount']);
        $bookingsValue = $this->nullableNumber($row, $detail, ['book_order_num', 'bookOrderNum', 'bookings', 'bookingCount', 'orderCount']);
        $impressions = $impressionsValue !== null ? (int)round($impressionsValue) : null;
        $clicks = $clicksValue !== null ? (int)round($clicksValue) : null;
        $bookings = $bookingsValue !== null ? (int)round($bookingsValue) : null;
        $roomNights = $this->nullableNumber($row, $detail, ['room_nights', 'roomNights', 'nights']);
        if ($roomNights === null && $source !== 'meituan') {
            $roomNights = $this->nullableNumber($row, $detail, ['quantity']);
        }
        $roas = $this->nullableNumber($row, $detail, ['roas', 'roi']);
        $computedRoas = $spend !== null && $spend > 0 && $orderAmount !== null
            ? $orderAmount / $spend
            : null;
        if ($source === 'meituan' && $roas !== null && $computedRoas !== null) {
            $percentScaled = $computedRoas * 100;
            $tolerance = max(0.01, abs($percentScaled) * 0.001);
            if (abs($roas - $percentScaled) <= $tolerance) {
                $roas = $computedRoas;
            }
        }
        if ($roas === null) {
            $legacyDataValue = $this->nullableNumber($row, $detail, ['data_value', 'dataValue']);
            $isMeituanExposureAlias = $source === 'meituan'
                && $legacyDataValue !== null
                && $impressions !== null
                && $impressions > 0
                && abs($legacyDataValue - $impressions) < 0.00001;
            if (!$isMeituanExposureAlias) {
                $roas = $legacyDataValue;
            }
        }

        return [
            'date_key' => $date,
            'hotel_key' => $hotelKey,
            'platform_key' => $source,
            'campaign_id' => (string)($detail['campaignId'] ?? $detail['campaign_id'] ?? $row['dimension'] ?? ''),
            'spend' => $spend !== null ? round($spend, 2) : null,
            'order_amount' => $orderAmount !== null ? round($orderAmount, 2) : null,
            'bookings' => $bookings,
            'room_nights' => $roomNights !== null ? round($roomNights, 2) : null,
            'impressions' => $impressions,
            'clicks' => $clicks,
            'ctr' => $impressions !== null && $impressions > 0 && $clicks !== null
                ? round($clicks / $impressions * 100, 2)
                : $this->nullablePercent($row, $detail, ['ctr']),
            'cvr' => $this->nullablePercent($row, $detail, ['cvr', 'conversion_rate', 'conversionRate', 'order_rate', 'orderRate'])
                ?? ($clicks !== null && $clicks > 0 && $bookings !== null ? round($bookings / $clicks * 100, 2) : null),
            'roas' => $roas !== null ? round($roas, 2) : ($computedRoas !== null ? round($computedRoas, 2) : null),
            'raw_data' => $raw,
            'source_trace' => $this->rowTrace($row, $hotelKey, $source, 'advertising', $date),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function qualityFact(array $row, array $raw, string $hotelKey, string $source, string $date): array
    {
        $detail = $this->rawDetail($raw);

        return [
            'date_key' => $date,
            'hotel_key' => $hotelKey,
            'platform_key' => $source,
            'service_score' => $this->nullableNumber($row, $detail, ['service_score', 'serviceScore']),
            'psi_score' => $this->nullableNumber($row, $detail, ['data_value', 'dataValue', 'psi_score', 'psiScore', 'psi', 'PSI']),
            'im_score' => $this->nullableNumber($row, $detail, ['im_score', 'imScore']),
            'hotel_collect' => $this->nullableNumber($row, $detail, ['hotel_collect', 'hotelCollect', 'favoriteCount']),
            'reply_rate' => $this->nullablePercent($row, $detail, ['reply_rate', 'replyRate', 'replyrate5m']),
            'raw_data' => $raw,
            'source_trace' => $this->rowTrace($row, $hotelKey, $source, 'quality', $date),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function searchKeywordFact(array $row, array $raw, string $hotelKey, string $source, string $date): array
    {
        $detail = $this->rawDetail($raw);
        $keyword = $this->firstText($row, $detail, ['dimension', 'keyword', 'searchKeyword', 'search_word', 'searchWord']);
        $rank = $this->nullableNumber($row, $detail, ['rank', 'ranking', 'search_rank', 'searchRank', 'position']);

        return [
            'date_key' => $date,
            'hotel_key' => $hotelKey,
            'platform_key' => $source,
            'keyword' => $keyword,
            'rank' => $rank !== null ? round($rank, 2) : null,
            'impressions' => ($value = $this->nullableNumber($row, $detail, ['list_exposure', 'listExposure', 'impressions', 'exposure', 'exposure_count', 'exposureCount'])) !== null ? (int)round($value) : null,
            'clicks' => ($value = $this->nullableNumber($row, $detail, ['detail_exposure', 'detailExposure', 'clicks', 'click_count', 'clickCount'])) !== null ? (int)round($value) : null,
            'order_contribution' => ($value = $this->nullableNumber($row, $detail, ['order_submit_num', 'orderSubmitNum', 'order_contribution', 'orderContribution', 'orders', 'orderCount'])) !== null ? (int)round($value) : null,
            'raw_data' => $raw,
            'source_trace' => $this->rowTrace($row, $hotelKey, $source, 'search_keyword', $date),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function peerRankFact(array $row, array $raw, string $hotelKey, string $source, string $date): array
    {
        $detail = $this->rawDetail($raw);
        $dimension = $this->firstText($row, $detail, ['dimension', 'dimName', '_dimName', 'metricName', 'aiMetricName']);
        $rankType = $this->firstText($row, $detail, ['rank_type', 'rankType', 'type', 'rankListType']);
        if ($rankType === '' && preg_match('/^peer_rank:([^:]+)/', $dimension, $matches) === 1) {
            $rankType = $matches[1];
        }
        if ($dimension === '') {
            $dimension = $rankType !== '' ? 'peer_rank:' . $rankType : 'peer_rank';
        }
        $rank = $this->supplementalNumber($row, $detail, ['rank', 'rank_no', 'rankNo', 'currentRank', 'sort']);
        $metricValue = $this->supplementalNumber($row, $detail, ['data_value', 'dataValue', 'value', 'metric_value']);

        return [
            'date_key' => $date,
            'hotel_key' => $hotelKey,
            'platform_key' => $source,
            'dimension' => $dimension,
            'rank_type' => $rankType,
            'rank' => $rank ?? $metricValue,
            'rank_percent' => $this->supplementalPercent($row, $detail, ['percent', 'ratio', 'rank_percent', 'rankPercent']),
            'metric_value' => $metricValue,
            'compare_type' => (string)($row['compare_type'] ?? $detail['compare_type'] ?? ''),
            'raw_data' => $raw,
            'source_trace' => $this->rowTrace($row, $hotelKey, $source, 'peer_rank', $date),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function trafficAnalysisFact(array $row, array $raw, string $hotelKey, string $source, string $date): array
    {
        $detail = $this->rawDetail($raw);
        $analysisType = $this->firstText($row, $detail, ['analysis_type', 'analysisType', 'type']);
        $dimension = $this->firstText($row, $detail, ['dimension', 'name']);
        if ($dimension === '') {
            $dimension = $analysisType !== '' ? 'traffic_analysis:' . $analysisType : 'traffic_analysis';
        }
        $orderFilling = $this->supplementalNumber($row, $detail, ['order_filling_num', 'orderFillingNum', 'clickCount', 'clicks']);
        $orderSubmit = $this->supplementalNumber($row, $detail, ['order_submit_num', 'orderSubmitNum', 'orderCount', 'payOrderCount', 'orders']);

        return [
            'date_key' => $date,
            'hotel_key' => $hotelKey,
            'platform_key' => $source,
            'dimension' => $dimension,
            'analysis_type' => $analysisType,
            'list_exposure' => $this->supplementalNumber($row, $detail, ['list_exposure', 'listExposure', 'exposeCount', 'exposureCount', 'exposure']),
            'detail_exposure' => $this->supplementalNumber($row, $detail, ['detail_exposure', 'detailExposure', 'visitCount', 'visitorCount', 'uv', 'pv', 'views']),
            'flow_rate' => $this->supplementalPercent($row, $detail, ['flow_rate', 'flowRate', 'visitOrderRate', 'conversionRate', 'orderConversionRate']),
            'order_filling_num' => $orderFilling,
            'order_submit_num' => $orderSubmit,
            'submit_rate' => $orderFilling !== null && $orderFilling > 0 && $orderSubmit !== null
                ? round($orderSubmit / $orderFilling * 100, 2)
                : null,
            'metric_value' => $this->supplementalNumber($row, $detail, ['data_value', 'dataValue', 'value', 'metric_value']),
            'peer_rank' => $this->supplementalNumber($row, $detail, ['peer_rank', 'peerRank', 'rank']),
            'week_over_week' => $this->supplementalNumber($row, $detail, ['week_over_week', 'weekOverWeek', 'wow']),
            'raw_data' => $raw,
            'source_trace' => $this->rowTrace($row, $hotelKey, $source, 'traffic_analysis', $date),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function trafficForecastFact(array $row, array $raw, string $hotelKey, string $source, string $date): array
    {
        $detail = $this->rawDetail($raw);
        $forecastType = $this->firstText($row, $detail, ['forecast_type', 'forecastType', 'type']);
        $dimension = $this->firstText($row, $detail, ['dimension', 'name']);
        if ($dimension === '') {
            $dimension = $forecastType !== '' ? 'traffic_forecast:' . $forecastType : 'traffic_forecast';
        }

        return [
            'date_key' => $date,
            'hotel_key' => $hotelKey,
            'platform_key' => $source,
            'dimension' => $dimension,
            'forecast_type' => $forecastType,
            'forecast_value' => $this->supplementalNumber($row, $detail, ['data_value', 'dataValue', 'current', 'value', 'metric_value']),
            'peer_avg' => $this->supplementalNumber($row, $detail, ['peer_avg', 'peerAvg', 'competitor_avg', 'competitorAvg']),
            'compare_type' => (string)($row['compare_type'] ?? $detail['compare_type'] ?? 'forecast'),
            'raw_data' => $raw,
            'source_trace' => $this->rowTrace($row, $hotelKey, $source, 'traffic_forecast', $date),
        ];
    }

    /**
     * Order-flow rows describe demand moving to or from peers. They remain
     * queryable evidence but must never share the realised-revenue fact grain.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function orderFlowFact(array $row, array $raw, string $hotelKey, string $source, string $date): array
    {
        $detail = $this->rawDetail($raw);
        $orderCount = $this->nullableNumber($row, $detail, ['order_count', 'orderCount', 'lossTotalCnt', 'lossOrderCount']);

        return [
            'date_key' => $date,
            'hotel_key' => $hotelKey,
            'platform_key' => $source,
            'dimension' => (string)($row['dimension'] ?? $detail['dimension'] ?? 'order_flow'),
            'metric_scope' => 'ota_channel_order_flow',
            'calculation_basis' => 'ota_order_flow_non_revenue_fact',
            'direction' => strtolower($this->firstText($row, $detail, ['order_flow_direction', 'orderFlowDirection', 'direction'])),
            'row_type' => strtolower($this->firstText($row, $detail, ['order_flow_row_type', 'orderFlowRowType', 'row_type', 'rowType'])),
            'period' => strtolower($this->firstText($row, $detail, ['order_flow_period', 'orderFlowPeriod', 'period'])),
            'period_start' => $this->dateValue($this->firstText($row, $detail, ['period_start', 'periodStart', 'start_date', 'startDate'])),
            'period_end' => $this->dateValue($this->firstText($row, $detail, ['period_end', 'periodEnd', 'end_date', 'endDate'])),
            'compare_type' => strtolower((string)($row['compare_type'] ?? $detail['compare_type'] ?? '')),
            'flow_order_count' => $orderCount !== null ? max(0, (int)round($orderCount)) : null,
            'flow_room_nights' => $this->nullableNumber($row, $detail, ['room_nights', 'roomNights', 'lossTotalPayRoomNight']),
            'flow_amount' => $this->nullableNumber($row, $detail, ['amount', 'lossTotalPayAmount', 'lossSinglePayAmount']),
            'flow_ratio' => $this->nullablePercent($row, $detail, ['order_ratio', 'orderRatio', 'lossOrderRatio', 'data_value', 'dataValue']),
            'raw_data' => $raw,
            'source_trace' => $this->rowTrace($row, $hotelKey, $source, 'order_flow', $date),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function commentFact(array $row, array $raw, string $hotelKey, string $source, string $date): array
    {
        $detail = $this->rawDetail($raw);
        $metrics = is_array($raw['metrics'] ?? null) ? array_merge($detail, $raw['metrics']) : $detail;

        return [
            'date_key' => $date,
            'hotel_key' => $hotelKey,
            'platform_key' => $source,
            'dimension' => (string)($row['dimension'] ?? $metrics['dimension'] ?? 'review'),
            'channel' => $this->firstText($row, $metrics, ['comment_channel', 'channel', 'channelName', 'platform', 'source']),
            'comment_score' => $this->nullableNumber($row, $metrics, ['comment_score', 'commentScore', 'score', 'rating', 'rate', 'totalScore', 'overallScore', 'star']),
            'comment_count' => $this->supplementalNumber($row, $metrics, ['comment_count', 'commentCount', 'commentsCount', 'review_count', 'reviewCount', 'totalCommentCount', 'totalCount', 'allCount', 'quantity']),
            'bad_review_count' => $this->supplementalNumber($row, $metrics, ['bad_review_count', 'badReviewCount', 'negativeCommentCount', 'negativeCount', 'badCount', 'lowScoreCount', 'noRecommendCount', 'data_value']),
            'qunar_comment_score' => $this->nullableNumber($row, $metrics, ['qunar_comment_score', 'qunarCommentScore', 'qunarRatingall']),
            'review_environment_score' => $this->nullableNumber($row, $metrics, ['review_environment_score', 'ratingLocation', 'environmentScore']),
            'review_facility_score' => $this->nullableNumber($row, $metrics, ['review_facility_score', 'ratingFacility', 'facilityScore']),
            'review_service_score' => $this->nullableNumber($row, $metrics, ['review_service_score', 'ratingService', 'reviewServiceScore']),
            'review_cleanliness_score' => $this->nullableNumber($row, $metrics, ['review_cleanliness_score', 'ratingRoom', 'cleanlinessScore']),
            'review_photo_count' => $this->supplementalNumber($row, $metrics, ['review_photo_count', 'hasPicCount', 'photoCommentCount']),
            'review_photo_rate' => $this->supplementalPercent($row, $metrics, ['review_photo_rate', 'photoRate', 'hasPicRate']),
            'raw_data' => $raw,
            'source_trace' => $this->rowTrace($row, $hotelKey, $source, 'review', $date),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function rowTrace(array $row, string $hotelKey, string $source, string $dataType, string $date): array
    {
        $failureReasons = [];
        $status = strtolower(trim((string)($row['status'] ?? $row['save_status'] ?? '')));
        if (in_array($status, OnlineDataTrustStatusService::blockingRowStatuses(), true)) {
            $failureReasons[] = 'row_status_' . $status;
        }

        $validationStatus = strtolower(trim((string)($row['validation_status'] ?? '')));
        if (in_array($validationStatus, OnlineDataTrustStatusService::blockingValidationStatuses(), true)) {
            $failureReasons[] = 'validation_status_' . $validationStatus;
            foreach ($this->validationFlagReasons($row['validation_flags'] ?? []) as $reason) {
                $failureReasons[] = $reason;
            }
        } else {
            foreach ($this->blockingValidationFlagReasons($row['validation_flags'] ?? []) as $reason) {
                $failureReasons[] = $reason;
            }
        }

        if (!array_key_exists('readback_verified', $row)
            || (int)$row['readback_verified'] !== 1
        ) {
            $failureReasons[] = 'readback_unverified';
        }

        $sourceTraceId = $this->sourceTraceId($row);
        $dataSourceId = (int)($row['data_source_id'] ?? 0);
        $syncTaskId = (int)($row['sync_task_id'] ?? 0);
        if ($sourceTraceId === '') {
            $failureReasons[] = 'provenance_missing';
        }
        if ((int)($row['system_hotel_id'] ?? 0) <= 0) {
            $failureReasons[] = 'system_hotel_id_missing';
        }
        if ($this->isManualIngestion($row)
            && !in_array($validationStatus, ['verified', 'valid', 'confirmed', 'approved', 'passed', 'success'], true)) {
            $failureReasons[] = 'manual_override_unverified';
        }

        foreach (['error_info', 'failure_reason', 'failed_reason'] as $field) {
            $reason = trim((string)($row[$field] ?? ''));
            if ($reason !== '') {
                $failureReasons[] = $field . ':' . mb_substr($reason, 0, 120);
            }
        }

        return [
            'table' => 'online_daily_data',
            'row_id' => array_key_exists('id', $row) ? (is_numeric($row['id']) ? (int)$row['id'] : (string)$row['id']) : null,
            'source_trace_id' => $sourceTraceId,
            'data_source_id' => $row['data_source_id'] ?? null,
            'sync_task_id' => $row['sync_task_id'] ?? null,
            'ingestion_method' => (string)($row['ingestion_method'] ?? ''),
            'hotel_key' => $hotelKey,
            'system_hotel_id' => max(0, (int)($row['system_hotel_id'] ?? 0)) ?: null,
            'platform_hotel_id' => trim((string)($row['hotel_id'] ?? '')),
            'hotel_name' => trim((string)($row['hotel_name'] ?? '')),
            'platform' => $source,
            'data_type' => $dataType,
            'date_key' => $date,
            'collected_at' => $this->traceCollectionTimestamp($row),
            'updated_at' => $this->traceTimestamp($row),
            'data_period' => trim((string)($row['data_period'] ?? '')),
            'is_final' => $this->isFinalPeriodRow($row),
            'stored' => isset($row['id']) && trim((string)$row['id']) !== '',
            'readback_verified' => (int)($row['readback_verified'] ?? 0) === 1,
            'saved_success' => empty($failureReasons),
            'failure_reasons' => array_values(array_unique($failureReasons)),
        ];
    }

    /**
     * @param mixed $flags
     * @return array<int, string>
     */
    private function validationFlagReasons(mixed $flags): array
    {
        $decoded = is_string($flags) ? json_decode($flags, true) : $flags;
        if (!is_array($decoded)) {
            return [];
        }

        $reasons = [];
        foreach ($decoded as $flag) {
            $code = is_array($flag)
                ? trim((string)($flag['code'] ?? $flag['field'] ?? ''))
                : trim((string)$flag);
            if ($code !== '') {
                $reasons[] = 'validation:' . $code;
            }
        }
        return $reasons;
    }

    /** @return array<int, string> */
    private function blockingValidationFlagReasons(mixed $flags): array
    {
        $blockingFragments = ['mismatch', 'wrong_hotel', 'binding', 'unverified', 'provenance', 'permission_denied', 'collection_failed', 'parse_failed'];
        return array_values(array_filter(
            $this->validationFlagReasons($flags),
            static function (string $reason) use ($blockingFragments): bool {
                $normalized = strtolower($reason);
                foreach ($blockingFragments as $fragment) {
                    if (str_contains($normalized, $fragment)) {
                        return true;
                    }
                }
                return false;
            }
        ));
    }

    /** @param array<string, mixed> $row */
    private function isManualIngestion(array $row): bool
    {
        $values = [
            (string)($row['ingestion_method'] ?? ''),
            (string)($row['source'] ?? ''),
        ];
        foreach ($values as $value) {
            $normalized = strtolower(trim($value));
            if ($normalized !== '' && (str_contains($normalized, 'manual') || str_contains($normalized, 'override'))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Competitor/peer rows can support comparison, but they must never become
     * the hotel's own daily revenue fact.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     */
    private function isSelfRevenueFact(array $row, array $raw, string $dataType): bool
    {
        if (in_array($dataType, ['competitor', 'competitor_avg', 'competition', 'peer'], true)) {
            return false;
        }

        $compareType = strtolower($this->firstText($row, $raw, ['compare_type', 'compareType']));
        if ($compareType !== '' && !in_array($compareType, ['self', 'own', 'ours', 'target_hotel'], true)) {
            return false;
        }

        $dimension = strtolower($this->firstText($row, $raw, ['dimension', 'dimName', '_dimName']));
        return !str_contains($dimension, 'competitor')
            && !str_contains($dimension, 'competition_circle_hotel')
            && !str_contains($dimension, 'peer_hotel');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function traceTimestamp(array $row): ?string
    {
        foreach (['update_time', 'updated_at', 'create_time', 'created_at'] as $field) {
            $value = trim((string)($row[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return null;
    }

    /**
     * Collection time is kept distinct from database update time. Missing
     * capture evidence stays null so downstream truth status cannot be
     * promoted by a persistence timestamp.
     *
     * @param array<string, mixed> $row
     */
    private function traceCollectionTimestamp(array $row): ?string
    {
        $raw = $this->decodeJson($row['raw_data'] ?? []);
        $meta = is_array($raw['meta'] ?? null) ? $raw['meta'] : [];
        $capture = is_array($raw['capture_evidence'] ?? null) ? $raw['capture_evidence'] : [];
        foreach ([
            $row['collected_at'] ?? null,
            $row['snapshot_time'] ?? null,
            $raw['collected_at'] ?? null,
            $raw['collectedAt'] ?? null,
            $raw['captured_at'] ?? null,
            $raw['capturedAt'] ?? null,
            $raw['fetched_at'] ?? null,
            $raw['fetch_time'] ?? null,
            $meta['collected_at'] ?? null,
            $meta['captured_at'] ?? null,
            $capture['collected_at'] ?? null,
            $capture['captured_at'] ?? null,
        ] as $value) {
            $text = trim((string)($value ?? ''));
            if ($text !== '') {
                return $text;
            }
        }
        return null;
    }

    /**
     * Canonical OTA identity shared by ETL filters and downstream consumers.
     * This is intentionally pure so persisted source aliases cannot drift
     * between ingestion and operating analysis.
     */
    public static function canonicalPlatformKey(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }
        if (in_array($value, ['携程', 'trip', 'ebooking'], true)) {
            return 'ctrip';
        }
        if (in_array($value, ['美团', 'meituan hotel'], true)) {
            return 'meituan';
        }
        if (in_array($value, ['去哪儿', 'qunar.com'], true)) {
            return 'qunar';
        }
        if (str_contains($value, 'meituan') || str_contains($value, 'dianping')) {
            return 'meituan';
        }
        if (str_contains($value, 'ctrip') || str_contains($value, 'trip.com')) {
            return 'ctrip';
        }
        if (str_contains($value, 'qunar')) {
            return 'qunar';
        }
        return in_array($value, ['ctrip', 'meituan', 'qunar'], true) ? $value : '';
    }

    private function platformKey(string $value): string
    {
        return self::canonicalPlatformKey($value);
    }

    private function platformName(string $source): string
    {
        return match ($source) {
            'ctrip' => 'Ctrip',
            'meituan' => 'Meituan',
            'qunar' => 'Qunar',
            default => $source,
        };
    }

    /**
     * @return array<int, string>
     */
    private function sourceFilterValues(string $value): array
    {
        $value = strtolower(trim($value));
        $sourceKey = $this->platformKey($value);
        $values = [$value];

        if ($sourceKey === 'meituan') {
            $values = array_merge($values, ['meituan', 'meituan_rank', 'meituan_business', 'meituan_browser_profile']);
        } elseif ($sourceKey === 'ctrip') {
            $values = array_merge($values, ['ctrip', 'ctrip_business', 'ctrip_manual_overview', 'ctrip_browser_profile']);
        } elseif ($sourceKey === 'qunar') {
            $values = array_merge($values, ['qunar']);
        }

        return array_values(array_unique(array_filter($values, static fn(string $item): bool => $item !== '')));
    }

    private function normalizeDataType(string $value): string
    {
        $value = strtolower(trim($value));
        if (in_array($value, ['traffic', 'flow'], true)) {
            return 'traffic';
        }
        if (in_array($value, [
            'order', 'orders', 'order_list', 'order-list',
            'booking', 'bookings', 'booking_list', 'booking-list', 'booking_data', 'booking-data',
            'reservation', 'reservations', 'reservation_list', 'reservation-list',
            'reservation_data', 'reservation-data',
        ], true)) {
            return 'order';
        }
        if (in_array($value, ['ad', 'ads', 'advertising', 'advertisement', 'campaign', 'campaigns'], true)) {
            return 'advertising';
        }
        if (in_array($value, ['quality', 'service', 'service_quality', 'psi'], true)) {
            return 'quality';
        }
        if (in_array($value, ['search_keyword', 'search-keyword', 'search_keywords', 'search-keywords', 'keyword', 'keywords', 'search_word', 'search_words', 'hot_word', 'hot_words'], true)) {
            return 'search_keyword';
        }
        if (in_array($value, ['peer_rank', 'peer-rank', 'peer_ranking', 'peer-ranking', 'competitor_rank', 'competitor-rank', 'rank', 'rankings'], true)) {
            return 'peer_rank';
        }
        if (in_array($value, ['traffic_analysis', 'traffic-analysis', 'flow_analysis', 'flow-analysis', 'flow_conversion', 'flow-conversion', 'flow_trend', 'flow-trend', 'flowtrend', 'flowconversion'], true)) {
            return 'traffic_analysis';
        }
        if (in_array($value, ['traffic_forecast', 'traffic-forecast', 'flow_forecast', 'flow-forecast', 'flowforecast'], true)) {
            return 'traffic_forecast';
        }
        if (in_array($value, ['review', 'reviews', 'comment', 'comments'], true)) {
            return 'review';
        }
        return $value !== '' ? $value : 'business';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function sourceTraceId(array $row): string
    {
        $traceId = trim((string)($row['source_trace_id'] ?? ''));
        if ($traceId !== '') {
            return $traceId;
        }

        $raw = $this->decodeJson($row['raw_data'] ?? []);
        return trim((string)($raw['source_trace_id'] ?? ''));
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function rawDetail(array $raw): array
    {
        return is_array($raw['row'] ?? null) ? array_merge($raw, $raw['row']) : $raw;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     */
    private function ctripBusinessEndpointId(array $row, array $raw): string
    {
        $detail = $this->rawDetail($raw);
        $catalogRaw = $this->decodeJson($detail['raw_data'] ?? []);
        $endpointId = strtolower(trim((string)(
            $detail['endpoint_id']
            ?? $detail['endpointId']
            ?? $catalogRaw['endpoint_id']
            ?? $catalogRaw['endpointId']
            ?? ''
        )));
        if ($endpointId !== '') {
            return $endpointId;
        }

        $dimension = strtolower(trim((string)($row['dimension'] ?? $detail['dimension'] ?? '')));
        if (preg_match('/^catalog:[^:]+:([^:]+)/', $dimension, $matches) === 1) {
            return trim((string)$matches[1]);
        }
        return '';
    }

    /**
     * Generic OTA order GMV must not become room revenue. Supported
     * equivalences are limited to the exact Ctrip checkout amount/quantity
     * pair and a fully paginated Meituan sale-price aggregate whose quantity
     * is explicitly sourced from booked room nights.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     */
    private function verifiedRoomRevenueBasis(
        array $row,
        array $raw,
        string $source,
        string $dataType
    ): ?string {
        if ($source === 'ctrip' && $dataType === 'business') {
            return $this->verifiedCtripCheckoutRoomRevenueBasis($row, $raw);
        }
        if ($source === 'meituan' && $dataType === 'business') {
            return $this->verifiedMeituanBusinessSalesRoomRevenueBasis($row, $raw);
        }
        if ($source !== 'meituan' || $dataType !== 'order') {
            return null;
        }

        $detail = $this->rawDetail($raw);
        $amount = $this->nullableNumber($row, [], ['amount']);
        $rawAmount = $this->nullableNumber([], $detail, ['amount']);
        $roomNights = $this->nullableNumber($row, [], ['quantity']);
        $rawRoomNights = $this->nullableNumber([], $detail, ['room_nights', 'quantity']);
        if ($amount === null
            || $rawAmount === null
            || abs($amount - $rawAmount) > 0.01
            || $roomNights === null
            || $roomNights <= 0
            || $rawRoomNights === null
            || abs($roomNights - $rawRoomNights) > 0.001
        ) {
            return null;
        }

        $compareType = strtolower(trim((string)($detail['compare_type'] ?? $row['compare_type'] ?? '')));
        if (!in_array($compareType, ['self', 'own', 'ours', 'target_hotel'], true)
            || !$this->explicitBoolean($detail['is_self'] ?? null, true)
            || !$this->explicitBoolean($detail['pagination_complete'] ?? null, true)
            || !$this->explicitBoolean($detail['floor_price_used_as_revenue'] ?? null, false)
            || !$this->explicitBoolean($detail['guarantee_amount_used_as_revenue'] ?? null, false)
            || (string)($detail['amount_scope'] ?? '') !== 'meituan_sale_price_total'
            || (string)($detail['amount_source'] ?? '') !== 'orderBasePriceModel.salePrice.price'
            || (string)($detail['amount_source_unit'] ?? '') !== 'cent'
            || (string)($detail['amount_storage_unit'] ?? '') !== 'yuan'
            || (string)($detail['quantity_scope'] ?? '') !== 'booked_room_nights'
            || (string)($detail['quantity_source'] ?? '') !== 'partRefundInfo.totalRoomNightCount'
            || !$this->hasCapturedFieldFact($raw, 'order_amount', 'online_daily_data.amount')
            || !$this->hasCapturedFieldFact($raw, 'room_nights', 'online_daily_data.quantity')
        ) {
            return null;
        }

        return 'verified_meituan_sale_price_total';
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     */
    private function verifiedMeituanBusinessSalesRoomRevenueBasis(array $row, array $raw): ?string
    {
        $detail = $this->rawDetail($raw);
        $amount = $this->nullableNumber($row, [], ['amount']);
        $salesAmount = $this->nullableNumber([], $detail, ['sales_amount', 'salesAmount']);
        $roomNights = $this->nullableNumber($row, [], ['quantity']);
        $salesRoomNights = $this->nullableNumber([], $detail, ['sales_room_nights', 'salesRoomNights']);
        $salesAvgPrice = $this->nullableNumber([], $detail, ['sales_avg_price', 'salesAvgPrice']);
        $derivedAvgPrice = $salesAmount !== null && $salesRoomNights !== null && $salesRoomNights > 0
            ? round($salesAmount / $salesRoomNights, 2)
            : null;
        $metricSources = is_array($detail['_meituan_business_metric_sources'] ?? null)
            ? $detail['_meituan_business_metric_sources']
            : [];
        $amountSource = is_array($metricSources['sales_amount'] ?? null)
            ? $metricSources['sales_amount']
            : [];
        $roomNightsSource = is_array($metricSources['sales_room_nights'] ?? null)
            ? $metricSources['sales_room_nights']
            : [];
        $compareType = strtolower(trim((string)($detail['compare_type'] ?? $row['compare_type'] ?? '')));
        $dateScope = (string)($detail['date_scope_evidence'] ?? '');

        if ($amount === null
            || $salesAmount === null
            || abs($amount - $salesAmount) > 0.01
            || $roomNights === null
            || $roomNights <= 0
            || $salesRoomNights === null
            || abs($roomNights - $salesRoomNights) > 0.001
            || ($salesAvgPrice !== null
                && $derivedAvgPrice !== null
                && abs($salesAvgPrice - $derivedAvgPrice) > 0.02)
            || !in_array($compareType, ['self', 'own', 'ours', 'target_hotel'], true)
            || !$this->explicitBoolean($detail['is_self'] ?? null, true)
            || (string)($detail['business_evidence_source'] ?? '') !== 'page.business_period_selection.readback'
            || (string)($detail['date_source'] ?? '') !== 'page.business_period_selection.readback'
            || !in_array($dateScope, [
                'meituan_business_yesterday_tab',
                'meituan_business_today_realtime_tab',
            ], true)
            || (string)($detail['_capture_source'] ?? '') !== 'xhr:traffic:business_data'
            || (string)($amountSource['source_kind'] ?? '') !== 'card'
            || trim((string)($amountSource['source_path'] ?? '')) === ''
            || (string)($roomNightsSource['source_kind'] ?? '') !== 'card'
            || trim((string)($roomNightsSource['source_path'] ?? '')) === ''
            || !$this->hasCapturedFieldFactSource(
                $raw,
                'order_amount',
                'online_daily_data.amount',
                'amount'
            )
            || !$this->hasCapturedFieldFactSource(
                $raw,
                'room_nights',
                'online_daily_data.quantity',
                'quantity'
            )
        ) {
            return null;
        }

        return 'verified_meituan_business_sales_cards';
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     */
    private function verifiedCtripCheckoutRoomRevenueBasis(array $row, array $raw): ?string
    {
        if ($this->ctripBusinessEndpointId($row, $raw) !== 'business_market_overview') {
            return null;
        }

        $detail = $this->rawDetail($raw);
        $amount = $this->nullableNumber($row, [], ['amount']);
        $rawAmount = $this->nullableNumber([], $detail, ['amount']);
        $roomNights = $this->nullableNumber($row, [], ['quantity']);
        $rawRoomNights = $this->nullableNumber([], $detail, ['quantity']);
        if ($amount === null
            || $rawAmount === null
            || abs($amount - $rawAmount) > 0.01
            || $roomNights === null
            || $roomNights <= 0
            || $rawRoomNights === null
            || abs($roomNights - $rawRoomNights) > 0.001
            || !$this->hasCapturedFieldFactSource(
                $raw,
                'order_amount',
                'online_daily_data.amount',
                'amount'
            )
            || !$this->hasCapturedFieldFactSource(
                $raw,
                'room_nights',
                'online_daily_data.quantity',
                'quantity'
            )
        ) {
            return null;
        }

        return 'verified_ctrip_checkout_sales';
    }

    private function explicitBoolean(mixed $value, bool $expected): bool
    {
        $truthy = [true, 1, '1', 'true'];
        $falsy = [false, 0, '0', 'false'];
        return in_array($value, $expected ? $truthy : $falsy, true);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function hasCapturedFieldFact(array $raw, string $metricKey, string $storageField): bool
    {
        foreach ((array)($raw['field_facts'] ?? $raw['facts'] ?? []) as $fact) {
            if (!is_array($fact)
                || trim((string)($fact['metric_key'] ?? '')) !== $metricKey
                || trim((string)($fact['storage_field'] ?? '')) !== $storageField
            ) {
                continue;
            }
            $status = strtolower(trim((string)($fact['status'] ?? '')));
            $sourcePath = trim((string)($fact['source_path'] ?? ''));
            return in_array($status, ['captured', 'ready', 'verified', 'complete'], true)
                && $sourcePath !== '';
        }
        return false;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function hasCapturedFieldFactSource(
        array $raw,
        string $metricKey,
        string $storageField,
        string $sourceKey
    ): bool {
        foreach ((array)($raw['field_facts'] ?? $raw['facts'] ?? []) as $fact) {
            if (!is_array($fact)
                || trim((string)($fact['metric_key'] ?? '')) !== $metricKey
                || trim((string)($fact['storage_field'] ?? '')) !== $storageField
                || trim((string)($fact['source_key'] ?? '')) !== $sourceKey
            ) {
                continue;
            }
            $status = strtolower(trim((string)($fact['status'] ?? '')));
            $sourcePath = trim((string)($fact['source_path'] ?? ''));
            return in_array($status, ['captured', 'ready', 'verified', 'complete'], true)
                && ($fact['stored_value_present'] ?? true) === true
                && $sourcePath !== '';
        }
        return false;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     * @param array<int, string> $keys
     */
    private function firstNumber(array $row, array $raw, array $keys): float
    {
        return (float)($this->nullableNumber($row, $raw, $keys) ?? 0.0);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     * @param array<int, string> $keys
     */
    private function nullableNumber(array $row, array $raw, array $keys): ?float
    {
        foreach ($keys as $key) {
            foreach ([$row, $raw] as $source) {
                if (array_key_exists($key, $source) && $source[$key] !== '' && $source[$key] !== null) {
                    $value = is_string($source[$key]) ? str_replace(['%', ','], '', trim($source[$key])) : $source[$key];
                    if (is_numeric($value)) {
                        return (float)$value;
                    }
                }
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     * @param array<int, string> $keys
     */
    private function nullablePercent(array $row, array $raw, array $keys): ?float
    {
        $value = $this->nullableNumber($row, $raw, $keys);
        if ($value === null) {
            return null;
        }
        if ($value < 0) {
            return null;
        }
        $percent = $value > 0 && $value <= 1 ? $value * 100 : $value;
        return $percent <= 100 ? $percent : null;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     * @param array<int, string> $keys
     */
    private function supplementalNumber(array $row, array $raw, array $keys): ?float
    {
        $rawValue = $this->nullableNumber([], $raw, $keys);
        if ($rawValue !== null) {
            return $rawValue;
        }
        $rowValue = $this->nullableNumber($row, [], $keys);
        return $rowValue !== null && $rowValue != 0.0 ? $rowValue : null;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     * @param array<int, string> $keys
     */
    private function supplementalPercent(array $row, array $raw, array $keys): ?float
    {
        $value = $this->supplementalNumber($row, $raw, $keys);
        if ($value === null || $value < 0) {
            return null;
        }
        $percent = $value > 0 && $value <= 1 ? $value * 100 : $value;
        return $percent <= 100 ? round($percent, 2) : null;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     */
    private function leadTimeDays(array $row, array $raw): ?int
    {
        $explicit = $this->nullableNumber($row, $raw, ['lead_time_days', 'leadTimeDays', 'booking_window', 'bookingWindow']);
        if ($explicit !== null) {
            $days = (int)round($explicit);
            return $days >= 0 ? $days : null;
        }

        $bookingDate = $this->dateValue($this->firstText($row, $raw, ['booking_date', 'bookingDate', 'order_date', 'orderDate', 'create_date', 'createDate']));
        $checkinDate = $this->dateValue($this->firstText($row, $raw, ['checkin_date', 'checkinDate', 'arrival_date', 'arrivalDate', 'stay_date', 'stayDate']));
        if ($bookingDate === '' || $checkinDate === '') {
            return null;
        }

        $booking = new \DateTimeImmutable($bookingDate);
        $checkin = new \DateTimeImmutable($checkinDate);
        $days = (int)$booking->diff($checkin)->format('%r%a');
        return $days >= 0 ? $days : null;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     * @param array<int, string> $keys
     */
    private function firstText(array $row, array $raw, array $keys): string
    {
        foreach ($keys as $key) {
            foreach ([$row, $raw] as $source) {
                $value = $source[$key] ?? null;
                if ($value !== null && trim((string)$value) !== '') {
                    return trim((string)$value);
                }
            }
        }
        return '';
    }

    private function dateValue(mixed $value): string
    {
        $text = trim((string)$value);
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $text) === 1 ? substr($text, 0, 10) : '';
    }

    private function filterDateValue(mixed $value, string $field): string
    {
        $date = $this->dateValue($value);
        if ($date === '') {
            throw new RuntimeException("Invalid {$field}, expected YYYY-MM-DD", 422);
        }
        return $date;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function sanitizeRawData(
        array $raw,
        bool $orderContext = false,
        bool $identityContext = false,
        bool $businessDescriptorContext = false,
        bool $businessContext = false
    ): array
    {
        $sanitized = [];
        foreach ($raw as $key => $value) {
            $keyText = (string)$key;
            if (preg_match('/cookie|token|authorization|mtgsig|password|secret/i', $keyText)) {
                continue;
            }

            $numericKey = is_int($key) || ctype_digit($keyText);
            $childOrderContext = $orderContext || (!$numericKey && $this->isOrderContainerKey($keyText));
            $identityContainer = !$numericKey
                && !($businessDescriptorContext && $this->isBusinessDescriptorWrapperKey($keyText))
                && (
                    $this->isIdentityContainerKey($keyText)
                    || ($childOrderContext && $this->isOrderRoomingIdentityContainerKey($keyText))
                );
            $childIdentityContext = $identityContext || $identityContainer;
            $childBusinessDescriptorContext = $businessDescriptorContext;
            if (!$numericKey && (
                $this->isBusinessDescriptorContainerKey($keyText)
                || $this->isBusinessDescriptorScalarKey($keyText)
            )) {
                $childBusinessDescriptorContext = true;
            }
            if ($identityContainer) {
                $childBusinessDescriptorContext = false;
            }

            if (is_array($value)) {
                if (!$numericKey && (
                    $this->isOrderIdKey($keyText)
                    || $this->isPhoneKey($keyText)
                    || $this->isSensitiveOrderCollectionKey($keyText)
                )) {
                    continue;
                }
                $child = $this->sanitizeRawData(
                    $value,
                    $childOrderContext,
                    $childIdentityContext,
                    $childBusinessDescriptorContext,
                    $businessContext
                );
                $sanitized[$key] = array_is_list($value) ? array_values($child) : $child;
                continue;
            }

            if ($numericKey && $identityContext) {
                continue;
            }

            if (!is_scalar($value) && $value !== null) {
                continue;
            }

            if (($businessContext || $childOrderContext || $childBusinessDescriptorContext)
                && !$this->isOrderIdKey($keyText)
                && !$this->isPhoneKey($keyText)
                && !($childIdentityContext && $this->isGuestNameKey($keyText, true))
                && $this->containsHighConfidencePiiValue($value)) {
                continue;
            }

            if ($childOrderContext || $identityContext || (!$numericKey && $this->isOrderPiiKey($keyText))) {
                $this->appendRedactedOrderField(
                    $sanitized,
                    $keyText,
                    $value,
                    $childOrderContext,
                    $childIdentityContext,
                    $childBusinessDescriptorContext
                );
                continue;
            }

            $sanitized[$key] = $value;
        }
        return $sanitized;
    }

    /**
     * @param array<mixed> $target
     */
    private function appendRedactedOrderField(
        array &$target,
        string $key,
        mixed $value,
        bool $orderContext = false,
        bool $identityContext = false,
        bool $businessDescriptorContext = false
    ): void
    {
        if ($this->isOrderIdKey($key)) {
            $text = trim((string)$value);
            if ($text !== '') {
                $target[$this->redactedOrderFieldName($key, 'hash')] = hash('sha256', 'ota_order|' . $text);
            }
            return;
        }
        if ($this->isPhoneKey($key)) {
            $masked = $this->maskPhone((string)$value);
            if ($masked !== '') {
                $target[$this->redactedOrderFieldName($key, 'masked')] = $masked;
            }
            return;
        }
        if ($this->isGuestNameKey(
            $key,
            $identityContext || (
                $orderContext
                && !$businessDescriptorContext
                && $this->isExplicitPersonalNameKey($key)
            )
        )) {
            $masked = $this->maskName((string)$value);
            if ($masked !== '') {
                $target[$this->redactedOrderFieldName($key, 'masked')] = $masked;
            }
            return;
        }
        if ($identityContext && $this->isOpaqueIdentityValueKey($key)) {
            return;
        }
        // Identity containers are fail-closed. Only the explicit hash/mask
        // branches above may retain identity values; arbitrary free text,
        // numbers, and provider-specific fields are not evidence-safe.
        if ($identityContext) {
            return;
        }
        if ($orderContext && $this->isWrappedOrderPiiKey($key)) {
            return;
        }
        if ($this->isSensitiveOrderTextKey($key)) {
            return;
        }

        $target[$key] = $value;
    }

    private function isOrderContainerKey(string $key): bool
    {
        return in_array($this->compactOrderFieldKey($key), [
            'order', 'orders', 'orderlist', 'orderrows', 'orderitems', 'orderdata',
            'orderdetail', 'orderdetails', 'orderinfo',
            'booking', 'bookings', 'bookinglist', 'bookingrows', 'bookingitems',
            'bookingdata', 'bookingdetail', 'bookingdetails', 'bookinginfo',
            'reservation', 'reservations', 'reservationlist', 'reservationrows',
            'reservationitems', 'reservationdata', 'reservationdetail',
            'reservationdetails', 'reservationinfo',
        ], true);
    }

    private function isIdentityContainerKey(string $key): bool
    {
        if ($this->isGuestNameKey($key) || $this->isPhoneKey($key)) {
            return false;
        }

        $segments = $this->orderFieldKeySegments($key);
        if (!$this->containsIdentitySubject($segments)) {
            return false;
        }
        return true;
    }

    private function isOrderRoomingIdentityContainerKey(string $key): bool
    {
        return in_array($this->compactOrderFieldKey($key), [
            'rooming', 'roomings', 'roominglist', 'roominglists',
            'roominginfo', 'roominginfos', 'roominginformation',
            'roomingdata', 'roomingdetail', 'roomingdetails',
            'roomingrow', 'roomingrows', 'roomingitem', 'roomingitems',
            'roomingrecord', 'roomingrecords',
            'roomingguest', 'roomingguests', 'roomoccupant', 'roomoccupants',
            'occupancylist', 'occupantlist',
        ], true);
    }

    private function isBusinessDescriptorContainerKey(string $key): bool
    {
        return in_array($this->compactOrderFieldKey($key), [
            'orderstatussummary', 'preorderstrend', 'competitorprofile',
            'roomtype', 'rateplan', 'channel', 'product',
        ], true);
    }

    private function isBusinessDescriptorScalarKey(string $key): bool
    {
        return preg_match(
            '/^(orderstatussummary|preorderstrend|competitorprofile|roomtype|rateplan|channel|product)'
                . '(name|description|value|text|label|summary|detail|details)$/',
            $this->compactOrderFieldKey($key)
        ) === 1;
    }

    private function isBusinessDescriptorWrapperKey(string $key): bool
    {
        return in_array($this->compactOrderFieldKey($key), [
            'contactinfo', 'contactinformation', 'contactdetail', 'contactdetails',
            'contactdata', 'contactvalue',
        ], true);
    }

    private function containsHighConfidencePiiValue(mixed $value): bool
    {
        if (is_int($value)) {
            $text = (string)$value;
        } elseif (is_float($value) && is_finite($value) && floor($value) === $value) {
            $text = sprintf('%.0f', $value);
        } elseif (is_string($value)) {
            $text = trim($value);
        } else {
            return false;
        }

        if ($text === '') {
            return false;
        }

        if (preg_match('/[a-z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+/iu', $text) === 1) {
            return true;
        }

        if (preg_match('/(?<!\d)(?:\+?86[\s-]?)?1[3-9](?:[\s-]?\d){9}(?!\d)/u', $text) === 1) {
            return true;
        }

        if (preg_match('/(?:身份证(?:号|号码)?|identity\s*card|national\s*id).{0,12}(?:\d{15}|\d{17}[\dXx])/iu', $text) === 1) {
            return true;
        }

        if (preg_match_all('/(?<!\d)([1-9]\d{16}[\dXx])(?!\d)/u', $text, $identityMatches) > 0) {
            foreach ($identityMatches[1] as $identityNumber) {
                if ($this->isValidChineseIdentityNumber((string)$identityNumber)) {
                    return true;
                }
            }
        }

        if (preg_match('/\bwxid[_-][a-z0-9_-]{4,}\b/iu', $text) === 1
            || preg_match('/(?:微信(?:号|账号)?|wechat(?:\s*(?:id|account))?|weixin(?:\s*(?:id|account))?|wx)\s*[:：=]\s*\S{5,}/iu', $text) === 1) {
            return true;
        }

        return preg_match('/\bqq\s*(?:号|号码|id|account)?\s*[:：=]?\s*[1-9]\d{4,11}\b/iu', $text) === 1;
    }

    private function isValidChineseIdentityNumber(string $value): bool
    {
        $value = strtoupper(trim($value));
        if (preg_match('/^[1-9]\d{16}[\dX]$/', $value) !== 1) {
            return false;
        }

        $birthDate = substr($value, 6, 8);
        $year = (int)substr($birthDate, 0, 4);
        $month = (int)substr($birthDate, 4, 2);
        $day = (int)substr($birthDate, 6, 2);
        if ($year < 1900 || $year > (int)date('Y') || !checkdate($month, $day, $year)) {
            return false;
        }

        $weights = [7, 9, 10, 5, 8, 4, 2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 2];
        $checks = ['1', '0', 'X', '9', '8', '7', '6', '5', '4', '3', '2'];
        $sum = 0;
        foreach ($weights as $index => $weight) {
            $sum += ((int)$value[$index]) * $weight;
        }
        return $checks[$sum % 11] === $value[17];
    }

    private function isOrderPiiKey(string $key, bool $orderContext = false): bool
    {
        return $this->isOrderIdKey($key)
            || $this->isPhoneKey($key)
            || $this->isGuestNameKey($key)
            || $this->isSensitiveOrderTextKey($key)
            || ($orderContext && (
                $this->isGuestNameKey($key, true)
                || $this->isWrappedOrderPiiKey($key)
            ));
    }

    private function isOrderIdKey(string $key): bool
    {
        if (in_array($this->compactOrderFieldKey($key), [
            'orderid', 'orderno', 'ordernum', 'ordernumber', 'ordersn',
            'bookingid', 'bookingno', 'bookingnumber',
            'reservationid', 'reservationno', 'reservationnumber',
        ], true)) {
            return true;
        }

        $segments = $this->orderFieldKeySegments($key);
        $last = $segments[array_key_last($segments)] ?? '';
        if (!in_array($last, ['id', 'no', 'num', 'number', 'sn'], true)) {
            return false;
        }
        $subjectPosition = count($segments) - 2;
        if (!in_array($segments[$subjectPosition] ?? '', ['order', 'booking', 'reservation'], true)) {
            return false;
        }
        return array_diff(array_slice($segments, 0, $subjectPosition), [
            'primary', 'external', 'platform', 'ota', 'source', 'original',
            'linked', 'parent', 'master',
        ]) === [];
    }

    private function isPhoneKey(string $key): bool
    {
        $compact = $this->compactOrderFieldKey($key);
        if (in_array($compact, [
            'phone', 'phoneno', 'phonenumber', 'mobile', 'mobileno', 'mobilenumber',
            'phones', 'phonenumbers', 'mobiles', 'mobilenumbers',
            'tel', 'telno', 'telephone', 'cellphone', 'contactphone', 'contactmobile',
            'guestphone', 'guestmobile', 'customerphone', 'customermobile',
            'clientphone', 'clientmobile', 'linkmanphone', 'linkmanmobile',
            'receiverphone', 'receivermobile',
        ], true)) {
            return true;
        }
        $segments = $this->orderFieldKeySegments($key);
        foreach (['phone', 'mobile', 'tel', 'telephone', 'cellphone'] as $phoneSegment) {
            $position = array_search($phoneSegment, $segments, true);
            if ($position === false) {
                continue;
            }
            $tail = array_slice($segments, $position + 1);
            if ($tail === [] || array_diff($tail, ['no', 'num', 'number']) === []) {
                return true;
            }
        }
        return in_array($compact, [
            '手机', '手机号', '手机号码', '电话', '电话号码', '联系电话', '联系手机',
        ], true);
    }

    private function isGuestNameKey(string $key, bool $allowGenericName = false): bool
    {
        $compact = $this->compactOrderFieldKey($key);
        if (in_array($compact, [
            'guestname', 'customername', 'clientname', 'personname', 'linkman',
            'linkmanname', 'contactname', 'username', 'travellername', 'travelername',
            'passengername', 'bookername', 'reservername', 'recipientname',
            'receivername', 'consigneename', 'occupantname', 'realname', 'fullname',
            'guestnames', 'customernames', 'clientnames', 'passengernames',
            '客人姓名', '住客姓名', '客户姓名', '联系人', '联系人姓名', '预订人',
            '预订人姓名', '入住人', '入住人姓名', '收件人', '收件人姓名', '姓名',
        ], true)) {
            return true;
        }
        $segments = $this->orderFieldKeySegments($key);
        $last = $segments[array_key_last($segments)] ?? '';
        if ($last === 'name' && $this->containsIdentitySubject(array_slice($segments, 0, -1))) {
            return true;
        }
        return $allowGenericName && (in_array($compact, [
            'name', 'person', 'persons', 'guest', 'guests', 'customer', 'customers',
            'client', 'clients', 'firstname', 'lastname', 'givenname', 'familyname',
            'surname', 'forename',
        ], true) || ($last === 'name' && count($segments) <= 2));
    }

    private function isExplicitPersonalNameKey(string $key): bool
    {
        return in_array($this->compactOrderFieldKey($key), [
            'firstname', 'lastname', 'givenname', 'familyname',
            'surname', 'forename',
        ], true);
    }

    private function isSensitiveOrderCollectionKey(string $key): bool
    {
        $compact = $this->compactOrderFieldKey($key);
        return in_array($compact, [
            'phones', 'phonenumbers', 'mobiles', 'mobilenumbers',
            'emails', 'emailaddresses', 'mails', 'guestnames', 'customernames',
            'clientnames', 'passengernames', 'socialaccounts', 'wechataccounts',
            'qqaccounts', 'imaccounts',
        ], true);
    }

    private function isOpaqueIdentityValueKey(string $key): bool
    {
        return in_array($this->compactOrderFieldKey($key), [
            'value', 'text', 'label', 'content', 'rawvalue', 'displayvalue',
        ], true);
    }

    /**
     * @return list<string>
     */
    private function orderFieldKeySegments(string $key): array
    {
        $normalized = $this->normalizeOrderFieldKey($key);
        return $normalized === '' ? [] : array_values(array_filter(explode('_', $normalized)));
    }

    /**
     * @param list<string> $segments
     */
    private function containsIdentitySubject(array $segments): bool
    {
        foreach ($segments as $segment) {
            if ($this->isIdentitySubjectSegment($segment)) {
                return true;
            }
        }
        return false;
    }

    private function isIdentitySubjectSegment(string $segment): bool
    {
        return in_array($segment, [
            'guest', 'guests', 'customer', 'customers', 'client', 'clients',
            'person', 'persons', 'passenger', 'passengers', 'traveller', 'travellers',
            'traveler', 'travelers', 'occupant', 'occupants', 'booker', 'bookers',
            'reserver', 'reservers', 'recipient', 'recipients', 'receiver', 'receivers',
            'consignee', 'consignees', 'contact', 'contacts', 'linkman', 'linkmen',
            'identity', 'identities', 'personal', 'personals',
        ], true);
    }

    private function isWrappedOrderPiiKey(string $key): bool
    {
        return in_array($this->compactOrderFieldKey($key), [
            'contact', 'contacts', 'contactinfo', 'contactinfos', 'contactinformation',
            'contactdetail', 'contactdetails', 'contactdata', 'contactvalue',
            'guestinfo', 'guestinformation', 'guestdetail', 'guestdetails', 'guestdata',
            'customerinfo', 'customerinformation', 'customerdata',
            'clientinfo', 'clientinformation', 'clientdata',
            'personinfo', 'personinformation', 'persondata',
        ], true);
    }

    private function isSensitiveOrderTextKey(string $key): bool
    {
        $compact = $this->compactOrderFieldKey($key);

        if (preg_match('/(email|emails|emailaddress|emailaddresses|mailaddress|mailaddresses)$/', $compact) === 1
            || in_array($compact, ['mail', 'mails', '邮箱', '电子邮箱', '邮件地址'], true)) {
            return true;
        }
        if (in_array($compact, [
            'wechat', 'wechatid', 'wechatno', 'wechataccount', 'wechataccounts',
            'weixin', 'weixinid', 'weixinno', 'weixinaccount', 'wx', 'wxid',
            'qq', 'qqid', 'qqno', 'qqnumber', 'qqaccount', 'qqaccounts', 'im', 'imid',
            'imaccount', 'imaccounts', 'social', 'socialid', 'socialaccount',
            'socialaccounts', 'socialmediaaccount', 'lineid', 'lineaccount',
            'whatsapp', 'whatsappid', 'telegram', 'telegramid',
            '微信', '微信号', '微信账号', 'qq号', 'qq账号', '即时通讯账号', '社交账号',
        ], true)) {
            return true;
        }
        if (preg_match('/(certificate|credential|idcard|identity|passport|license)(id|no|num|number)?$/', $compact) === 1
            || in_array($compact, [
                'idno', 'idnum', 'idnumber', 'cardno', 'cardnumber',
                '证件', '证件号', '证件号码', '身份证',
                '身份证号', '身份证号码', '护照', '护照号', '护照号码',
            ], true)) {
            return true;
        }
        if (preg_match('/address$/', $compact) === 1
            || in_array($compact, ['地址', '详细地址', '联系地址', '收件地址', '入住地址'], true)) {
            return true;
        }
        if (preg_match('/(remark|remarks|memo|note|notes|message|comment|comments|specialrequest|specialrequests)$/', $compact) === 1
            || in_array($compact, [
                '备注', '留言', '买家留言', '特殊要求', '特殊需求', '客户备注', '客人备注',
            ], true)) {
            return true;
        }
        return in_array($compact, [
            'birthday', 'birthdate', 'dateofbirth', 'dob', 'bankcard', 'bankaccount',
            'alipayaccount', '生日', '出生日期', '银行卡', '银行卡号', '银行账号', '支付宝账号',
        ], true);
    }

    private function redactedOrderFieldName(string $key, string $suffix): string
    {
        if ($this->isOrderIdKey($key)) {
            return 'order_id_hash';
        }
        $name = preg_replace('/[^a-z0-9]+/', '_', $this->normalizeOrderFieldKey($key)) ?? '';
        $name = trim($name, '_');
        return ($name !== '' ? $name : 'field') . '_' . $suffix;
    }

    private function compactOrderFieldKey(string $key): string
    {
        return str_replace('_', '', $this->normalizeOrderFieldKey($key));
    }

    private function normalizeOrderFieldKey(string $key): string
    {
        $key = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', trim($key)) ?? $key;
        $key = mb_strtolower($key, 'UTF-8');
        $key = preg_replace('/[^\p{L}\p{N}]+/u', '_', $key) ?? $key;
        return trim($key, '_');
    }

    private function maskPhone(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if ($digits === '') {
            return '';
        }
        if (strlen($digits) <= 4) {
            return str_repeat('*', strlen($digits));
        }
        return str_repeat('*', strlen($digits) - 4) . substr($digits, -4);
    }

    private function maskName(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        return mb_substr($value, 0, 1) . '***';
    }

    private function tableExists(string $table): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return false;
        }
        if (strtolower((string)Db::connect()->getConfig('type')) === 'sqlite') {
            return Db::query(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
                [$table]
            ) !== [];
        }
        return !empty(Db::query("SHOW TABLES LIKE '" . addslashes($table) . "'"));
    }

    /**
     * @return array<string, bool>
     */
    private function tableColumns(string $table): array
    {
        $columns = [];
        if (strtolower((string)Db::connect()->getConfig('type')) === 'sqlite') {
            foreach (Db::query('PRAGMA table_info(`' . $table . '`)') as $row) {
                if (!empty($row['name'])) {
                    $columns[(string)$row['name']] = true;
                }
            }
            return $columns;
        }
        foreach (Db::query('SHOW COLUMNS FROM `' . $table . '`') as $row) {
            if (!empty($row['Field'])) {
                $columns[(string)$row['Field']] = true;
            }
        }
        return $columns;
    }
}
