<?php
declare(strict_types=1);

namespace app\service\operation;

use app\service\OnlineDataFieldFactService;
use app\service\OnlineDataTrustStatusService;
use app\service\OtaStandardEtlService;
use DateTimeImmutable;
use DateTimeZone;
use think\facade\Db;
use Throwable;

trait OperationSnapshotConcern
{
    private function buildSummary(array $hotelIds, ?int $hotelId, string $date): array
    {
        return $this->buildSummaryFromRows(
            $this->dailyReportRows($hotelIds, $date, $date),
            $this->onlineRows($hotelIds, $date, $date),
            $hotelIds,
            $hotelId,
            $date
        );
    }

    private function buildSummaryFromRows(array $daily, array $online, array $hotelIds, ?int $hotelId, string $date): array
    {
        $base = [
            'hotel_id' => $hotelId ?: ($hotelIds[0] ?? null),
            'date' => $date,
            'revenue' => null,
            'orders' => null,
            'room_nights' => null,
            'adr' => null,
            'occ' => null,
            'revpar' => null,
            'data_status' => 'missing',
            'source_status' => 'missing',
            'source_scope' => 'unknown',
            'metric_scopes' => [
                'revenue' => [],
                'orders' => [],
                'room_nights' => [],
            ],
            'data_gaps' => [
                ['code' => 'operation_revenue_missing', 'message' => '经营收入字段未返回'],
                ['code' => 'operation_orders_missing', 'message' => '订单字段未返回'],
                ['code' => 'operation_room_nights_missing', 'message' => '间夜字段未返回'],
            ],
            'optional_data_gaps' => [],
            'evidence_refs' => [],
        ];

        $canonicalOnlineFacts = $this->canonicalOnlineOperatingFacts($online);
        if (empty($daily) && empty($canonicalOnlineFacts)) {
            return $base;
        }

        $totals = ['revenue' => 0.0, 'orders' => 0.0, 'room_nights' => 0.0];
        $metricPresent = ['revenue' => false, 'orders' => false, 'room_nights' => false];
        $metricScopes = ['revenue' => [], 'orders' => [], 'room_nights' => []];
        $dailyRevenueCoverage = [];
        $dailyRoomNightCoverage = [];
        $roomCount = 0.0;
        $roomCountPresent = false;
        $sourceKinds = [];
        $sourceMissing = false;

        foreach ($daily as $row) {
            $reportData = $this->decodeJson((string)($row['report_data'] ?? ''));
            $dailyMetricKeys = [];
            if ($this->dailyRevenueIsPresent($row, $reportData)) {
                $totals['revenue'] += $this->extractRevenue($row, $reportData);
                $metricPresent['revenue'] = true;
                $metricScopes['revenue']['whole_hotel_daily_report'] = true;
                $this->markDailyMetricCoverage($dailyRevenueCoverage, $row);
                $dailyMetricKeys[] = 'revenue';
            }
            if ($this->dailyRoomNightsArePresent($reportData)) {
                $totals['room_nights'] += $this->extractRoomNights($row, $reportData);
                $metricPresent['room_nights'] = true;
                $metricScopes['room_nights']['whole_hotel_daily_report'] = true;
                $this->markDailyMetricCoverage($dailyRoomNightCoverage, $row);
                $dailyMetricKeys[] = 'room_nights';
            }
            $dailyOrders = $this->extractDailyOrders($row, $reportData);
            if ($dailyOrders !== null) {
                $totals['orders'] += $dailyOrders;
                $metricPresent['orders'] = true;
                $metricScopes['orders']['whole_hotel_daily_report'] = true;
                $dailyMetricKeys[] = 'orders';
            }
            $rowRoomCount = $this->extractSalableRoomCount($row, $reportData);
            if ($rowRoomCount > 0) {
                $roomCount += $rowRoomCount;
                $roomCountPresent = true;
                $dailyMetricKeys[] = 'available_rooms';
            }
            if ($this->numericMetricValue($row['occupancy_rate'] ?? null) !== null) {
                $base['occ'] = max((float)($base['occ'] ?? 0), (float)$row['occupancy_rate']);
                $dailyMetricKeys[] = 'occupancy_rate';
            }
            $sourceKinds['daily_reports'] = true;
            $base['evidence_refs'][] = [
                'source_ref' => 'daily_reports#' . (int)($row['id'] ?? 0),
                'source_record_id' => (int)($row['id'] ?? 0),
                'source' => 'daily_reports',
                'platform' => '',
                'data_date' => (string)($row['report_date'] ?? $date),
                'data_type' => 'whole_hotel_daily_report',
                'validation_status' => (string)($row['validation_status'] ?? 'recorded'),
                'ingestion_method' => (string)($row['ingestion_method'] ?? 'daily_report'),
                'updated_at' => (string)($row['update_time'] ?? $row['create_time'] ?? ''),
                'metric_keys' => array_values(array_unique($dailyMetricKeys)),
            ];
        }

        foreach ($canonicalOnlineFacts as $item) {
            $row = is_array($item['source_row'] ?? null) ? $item['source_row'] : [];
            $fact = is_array($item['fact'] ?? null) ? $item['fact'] : [];
            $onlineMetricKeys = [];
            $fieldFactMetricKeys = [];
            $onlineOrders = $this->numericMetricValue($fact['order_count'] ?? null);
            if ((string)($fact['metric_semantic_scope'] ?? '') === 'ota_daily_generic') {
                $raw = $this->decodeJson((string)($row['raw_data'] ?? ''));
                $rawOrders = $this->firstNumericMetric($raw, ['bookOrderNum', 'book_order_num', 'orders']);
                if ($rawOrders !== null) {
                    $onlineOrders = $onlineOrders === null ? $rawOrders : max($onlineOrders, $rawOrders);
                }
            }
            if ($onlineOrders !== null) {
                $totals['orders'] += $onlineOrders;
                $metricPresent['orders'] = true;
                $metricScopes['orders']['ota_channel'] = true;
                $onlineMetricKeys[] = 'book_order_num';
                $fieldFactMetricKeys[] = 'order_count';
            }
            if (!$this->hasDailyMetricForOnlineRow($dailyRevenueCoverage, $row)
                && ($onlineRevenue = $this->numericMetricValue($fact['revenue'] ?? null)) !== null) {
                $totals['revenue'] += $onlineRevenue;
                $metricPresent['revenue'] = true;
                $metricScopes['revenue']['ota_channel'] = true;
                $onlineMetricKeys[] = 'amount';
                $fieldFactMetricKeys[] = 'order_amount';
            }
            if (!$this->hasDailyMetricForOnlineRow($dailyRoomNightCoverage, $row)
                && ($onlineRoomNights = $this->numericMetricValue($fact['room_nights'] ?? null)) !== null) {
                $totals['room_nights'] += $onlineRoomNights;
                $metricPresent['room_nights'] = true;
                $metricScopes['room_nights']['ota_channel'] = true;
                $onlineMetricKeys[] = 'quantity';
                $fieldFactMetricKeys[] = 'room_nights';
            }
            if ($onlineMetricKeys === []) {
                continue;
            }
            $source = $this->normalizeOtaChannel((string)($row['source'] ?? ''));
            $platform = $this->normalizeOtaChannel((string)($row['platform'] ?? ''));
            if ($source === '' && $platform === '') {
                $sourceMissing = true;
            } else {
                $sourceKinds['ota_channel'] = true;
            }
            $base['evidence_refs'][] = [
                'source_ref' => 'online_daily_data#' . (int)($row['id'] ?? 0),
                'source_record_id' => (int)($row['id'] ?? 0),
                'source' => $source,
                'platform' => $platform,
                'data_date' => (string)($row['data_date'] ?? ''),
                'data_type' => (string)($row['data_type'] ?? ''),
                'validation_status' => (string)($row['validation_status'] ?? ''),
                'ingestion_method' => (string)($row['ingestion_method'] ?? ''),
                'data_period' => (string)($row['data_period'] ?? ''),
                'is_final' => array_key_exists('is_final', $row) ? (int)$row['is_final'] : null,
                'snapshot_time' => (string)($row['snapshot_time'] ?? ''),
                'updated_at' => (string)($row['update_time'] ?? ''),
                'metric_keys' => array_values(array_unique($onlineMetricKeys)),
                'field_fact_metric_keys' => array_values(array_unique($fieldFactMetricKeys)),
                'calculation_basis' => (string)($fact['calculation_basis'] ?? 'ota_daily_standard_fact'),
                'metric_semantic_scope' => (string)($fact['metric_semantic_scope'] ?? ''),
            ];
        }

        $base['revenue'] = $metricPresent['revenue'] ? round($totals['revenue'], 2) : null;
        $base['orders'] = $metricPresent['orders'] ? (int)round($totals['orders']) : null;
        $base['room_nights'] = $metricPresent['room_nights'] ? round($totals['room_nights'], 2) : null;
        $base['adr'] = $metricPresent['revenue'] && $metricPresent['room_nights'] && $base['room_nights'] > 0
            ? round((float)$base['revenue'] / (float)$base['room_nights'], 2)
            : null;
        if ($base['occ'] === null && $roomCountPresent && $metricPresent['room_nights']) {
            $base['occ'] = round(((float)$base['room_nights'] / $roomCount) * 100, 2);
        }
        $base['revpar'] = $roomCountPresent && $metricPresent['revenue']
            ? round((float)$base['revenue'] / $roomCount, 2)
            : null;

        $dataGaps = [];
        foreach ([
            'revenue' => ['operation_revenue_missing', '经营收入字段未返回'],
            'orders' => ['operation_orders_missing', '订单字段未返回'],
            'room_nights' => ['operation_room_nights_missing', '间夜字段未返回'],
        ] as $metric => [$code, $message]) {
            if (!$metricPresent[$metric]) {
                $dataGaps[] = ['code' => $code, 'message' => $message];
            }
        }
        if ($sourceMissing) {
            $dataGaps[] = ['code' => 'operation_source_missing', 'message' => '存在未标明 OTA 渠道来源的经营记录'];
        }
        if ($base['adr'] === null) {
            $base['optional_data_gaps'][] = ['code' => 'operation_adr_not_calculable', 'message' => '收入或间夜缺失，或间夜为0，ADR不可计算'];
        }
        if ($base['occ'] === null) {
            $base['optional_data_gaps'][] = ['code' => 'operation_occ_not_calculable', 'message' => '入住率或可售房量未返回，OCC不可计算'];
        }
        if ($base['revpar'] === null) {
            $base['optional_data_gaps'][] = ['code' => 'operation_revpar_not_calculable', 'message' => '收入或可售房量未返回，RevPAR不可计算'];
        }

        $base['metric_scopes'] = array_map(static fn(array $scopes): array => array_keys($scopes), $metricScopes);
        $base['source_scope'] = isset($sourceKinds['daily_reports'], $sourceKinds['ota_channel'])
            ? 'mixed_whole_hotel_and_ota_channel'
            : (isset($sourceKinds['daily_reports'])
                ? 'whole_hotel_daily_report'
                : (isset($sourceKinds['ota_channel']) ? 'ota_channel' : 'unknown'));
        $base['source_status'] = $sourceMissing ? 'partial' : 'clear';
        $base['data_gaps'] = $dataGaps;
        $base['data_status'] = $dataGaps === [] ? self::DATA_OK : 'partial';

        return $base;
    }

    private function buildOta(array $hotelIds, string $date): array
    {
        return $this->buildOtaFromRows($this->onlineRows($hotelIds, $date, $date));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function buildOtaFromRows(array $rows): array
    {
        $base = [
            'exposure' => null,
            'visitors' => null,
            'views' => null,
            'orders' => null,
            'view_rate' => null,
            'order_rate' => null,
            'order_filling' => null,
            'order_submit' => null,
            'flow_rate' => null,
            'fill_submit_rate' => null,
            'data_status' => self::DATA_PENDING,
            'funnel_status' => 'missing',
            'missing_metrics' => ['exposure', 'visitors'],
            'source_scope' => 'ota_channel',
            'evidence_refs' => [],
        ];

        $rows = $this->latestOnlineFlowRows($rows);
        if (empty($rows)) {
            return $base;
        }

        foreach ($rows as $row) {
            $metrics = $this->onlineFlowMetrics($row);
            if ($this->onlineRowHasNumericMetric($row, ['list_exposure', 'exposure', 'show_num', 'showNum', 'impression'])) {
                $base['exposure'] = (int)($base['exposure'] ?? 0) + (int)$metrics['exposure'];
            }
            if ($this->onlineRowHasNumericMetric($row, ['visitors', 'visitor_num', 'visitorNum', 'qunarDetailVisitors', 'detail_exposure'])) {
                $base['visitors'] = (int)($base['visitors'] ?? 0) + (int)$metrics['visitors'];
            }
            if ($this->onlineRowHasNumericMetric($row, ['detail_exposure', 'views', 'total_detail_num', 'totalDetailNum', 'detailVisitors'])) {
                $base['views'] = (int)($base['views'] ?? 0) + (int)$metrics['views'];
            }
            if ($this->onlineRowHasNumericMetric($row, ['order_submit_num', 'book_order_num', 'bookOrderNum', 'orders'])) {
                $base['orders'] = (int)($base['orders'] ?? 0) + (int)$metrics['orders'];
                $base['order_submit'] = (int)($base['order_submit'] ?? 0) + (int)$metrics['orders'];
            }
            if ($this->onlineRowHasNumericMetric($row, ['order_filling_num', 'orderFillingNum', 'order_page_visitor'])) {
                $base['order_filling'] = (int)($base['order_filling'] ?? 0) + (int)$metrics['order_filling'];
            }
            $base['evidence_refs'][] = [
                'source_ref' => 'online_daily_data#' . (int)($row['id'] ?? 0),
                'source_record_id' => (int)($row['id'] ?? 0),
                'source' => strtolower(trim((string)($row['source'] ?? ''))),
                'platform' => strtolower(trim((string)($row['platform'] ?? ''))),
                'endpoint_id' => $this->onlineEndpointIdFromRow($row),
                'data_date' => (string)($row['data_date'] ?? ''),
                'validation_status' => (string)($row['validation_status'] ?? ''),
                'ingestion_method' => (string)($row['ingestion_method'] ?? ''),
                'data_period' => (string)($row['data_period'] ?? ''),
                'is_final' => array_key_exists('is_final', $row) ? (int)$row['is_final'] : null,
                'snapshot_time' => (string)($row['snapshot_time'] ?? ''),
                'updated_at' => (string)($row['update_time'] ?? ''),
                'metric_keys' => ['list_exposure', 'detail_exposure', 'flow_rate', 'order_filling_num', 'order_submit_num'],
                'reported_flow_rate' => $metrics['reported_flow_rate'],
            ];
        }

        $base['view_rate'] = $base['exposure'] !== null && $base['exposure'] > 0 && $base['views'] !== null
            ? round($base['views'] / $base['exposure'] * 100, 2)
            : null;
        $base['order_rate'] = $base['visitors'] !== null && $base['visitors'] > 0 && $base['orders'] !== null
            ? round($base['orders'] / $base['visitors'] * 100, 2)
            : null;
        $base['flow_rate'] = $base['view_rate'];
        $base['fill_submit_rate'] = (int)($base['order_filling'] ?? 0) > 0
            ? round((int)($base['order_submit'] ?? 0) / (int)$base['order_filling'] * 100, 2)
            : null;
        $base['missing_metrics'] = array_values(array_filter([
            $base['exposure'] === null ? 'exposure' : null,
            $base['visitors'] === null ? 'visitors' : null,
        ]));
        $base['data_status'] = $base['exposure'] !== null && ($base['visitors'] !== null || $base['views'] !== null)
            ? self::DATA_OK
            : 'partial';
        $base['funnel_status'] = $base['data_status'] === self::DATA_OK ? 'available' : 'missing';

        return $base;
    }

    /**
     * Resolve operating values through the shared OTA standard ETL. This keeps
     * Ctrip checkout versus booking fields and Meituan business-card versus
     * order-list aggregates as alternative evidence families, never additive
     * or "latest non-empty row wins" values.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array{fact:array<string,mixed>,source_row:array<string,mixed>}>
     */
    private function canonicalOnlineOperatingFacts(array $rows): array
    {
        $candidates = array_values(array_filter($rows, function (mixed $row): bool {
            if (!is_array($row)) {
                return false;
            }
            $dataType = strtolower(trim((string)($row['data_type'] ?? '')));
            if ($dataType !== '' && !in_array($dataType, [
                'business',
                'business_overview',
                'overview',
                'operation',
                'order',
                'orders',
            ], true)) {
                return false;
            }
            return $this->isTrustedSelfOtaFactRow($row)
                || $this->isMetricScopedPartialOtaCandidate($row);
        }));
        if ($candidates === []) {
            return [];
        }

        $rowsById = [];
        foreach ($candidates as $row) {
            $rowId = (int)($row['id'] ?? 0);
            if ($rowId > 0) {
                $rowsById[$rowId] = $row;
            }
        }
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows($candidates);
        $result = [];
        foreach ((array)($dataset['fact_ota_daily'] ?? []) as $fact) {
            if (!is_array($fact)) {
                continue;
            }
            $trace = is_array($fact['source_trace'] ?? null) ? $fact['source_trace'] : [];
            $rowId = (int)($trace['row_id'] ?? 0);
            $sourceRow = $rowsById[$rowId] ?? null;
            if (!is_array($sourceRow)) {
                continue;
            }
            $fieldFactMetricKeys = $this->operatingFactFieldMetricKeys($fact);
            if ($fieldFactMetricKeys === []) {
                continue;
            }
            if (!$this->isTrustedSelfOtaFactRow($sourceRow)
                && !$this->metricScopedFieldFactsReady($sourceRow, $fieldFactMetricKeys)
            ) {
                continue;
            }
            $result[] = ['fact' => $fact, 'source_row' => $sourceRow];
        }

        usort($result, static function (array $left, array $right): int {
            $leftFact = is_array($left['fact'] ?? null) ? $left['fact'] : [];
            $rightFact = is_array($right['fact'] ?? null) ? $right['fact'] : [];
            return [
                (string)($leftFact['date_key'] ?? ''),
                (string)($leftFact['platform_key'] ?? ''),
                (int)($leftFact['source_trace']['row_id'] ?? 0),
            ] <=> [
                (string)($rightFact['date_key'] ?? ''),
                (string)($rightFact['platform_key'] ?? ''),
                (int)($rightFact['source_trace']['row_id'] ?? 0),
            ];
        });
        return $result;
    }

    /** @param array<string, mixed> $row */
    private function isMetricScopedPartialOtaCandidate(array $row): bool
    {
        if (OnlineDataTrustStatusService::classifyValidationStatus($row['validation_status'] ?? '') !== 'partial'
            || trim((string)($row['source_trace_id'] ?? '')) === ''
        ) {
            return false;
        }
        $trustedProbe = $row;
        $trustedProbe['validation_status'] = 'normal';
        return $this->isTrustedSelfOtaFactRow($trustedProbe);
    }

    /**
     * @param array<string, mixed> $fact
     * @return array<int, string>
     */
    private function operatingFactFieldMetricKeys(array $fact): array
    {
        $keys = [];
        if ($this->numericMetricValue($fact['revenue'] ?? null) !== null) {
            $keys[] = 'order_amount';
        }
        if ($this->numericMetricValue($fact['room_nights'] ?? null) !== null) {
            $keys[] = 'room_nights';
        }
        if ($this->numericMetricValue($fact['order_count'] ?? null) !== null) {
            $keys[] = 'order_count';
        }
        return array_values(array_unique($keys));
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $metricKeys
     */
    private function metricScopedFieldFactsReady(array $row, array $metricKeys): bool
    {
        $raw = $this->decodeJson((string)($row['raw_data'] ?? ''));
        $status = OnlineDataFieldFactService::buildMetricStatus($row, $raw, $metricKeys);
        return (string)($status['status'] ?? '') === 'ready'
            && (array)($status['missing_requested_metric_keys'] ?? []) === [];
    }

    /** @return array<string, mixed> */
    private function onlineMetricFieldFact(array $row, string $metricKey): array
    {
        $raw = $this->decodeJson((string)($row['raw_data'] ?? ''));
        $metricKey = strtolower(trim($metricKey));
        foreach (is_array($raw['field_facts'] ?? null) ? $raw['field_facts'] : [] as $fact) {
            if (is_array($fact)
                && strtolower(trim((string)($fact['metric_key'] ?? ''))) === $metricKey
            ) {
                return $fact;
            }
        }
        return [];
    }

    /**
     * A traffic collection can persist several field rows for the same snapshot.
     * Keep only the latest verified snapshot for each hotel/channel/date so one
     * platform response is never counted multiple times.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function latestOnlineFlowRows(array $rows): array
    {
        $selected = [];
        foreach ($rows as $row) {
            if (!$this->isTrustedSelfOtaFactRow($row)) {
                continue;
            }
            $dataType = strtolower(trim((string)($row['data_type'] ?? '')));
            if ($dataType !== '' && !in_array($dataType, ['traffic', 'flow', 'traffic_flow', 'traffic_overview'], true)) {
                continue;
            }
            $endpointId = $this->onlineEndpointIdFromRow($row);
            if ($endpointId !== '' && !in_array($endpointId, ['business_flow_transform', 'traffic_flow_transform'], true)) {
                continue;
            }
            if (!$this->hasOnlineFlowEvidence($row)) {
                continue;
            }
            if ($endpointId === '') {
                $metrics = $this->onlineFlowMetrics($row);
                if ((float)$metrics['exposure'] <= 0
                    && (float)$metrics['visitors'] <= 0
                    && (float)$metrics['views'] <= 0
                    && (float)$metrics['order_filling'] <= 0
                    && (float)$metrics['orders'] <= 0
                ) {
                    continue;
                }
            }
            $date = substr(trim((string)($row['data_date'] ?? '')), 0, 10);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }
            $hotelId = (string)($row['system_hotel_id'] ?? $row['hotel_id'] ?? '');
            $source = strtolower(trim((string)($row['source'] ?? ''))) ?: 'unknown';
            $platform = $this->normalizeOtaChannel((string)($row['platform'] ?? ''));
            $key = $hotelId . '|' . $source . '|' . $platform . '|' . $date;
            $current = $selected[$key] ?? null;
            $rowRank = $this->onlineFlowRowRank($row);
            $currentRank = is_array($current) ? $this->onlineFlowRowRank($current) : -1;
            if ($current === null
                || $rowRank > $currentRank
                || ($rowRank === $currentRank && $this->onlineRowTimestamp($row) > $this->onlineRowTimestamp($current))
                || ($rowRank === $currentRank
                    && $this->onlineRowTimestamp($row) === $this->onlineRowTimestamp($current)
                    && (int)($row['id'] ?? 0) > (int)($current['id'] ?? 0))) {
                $selected[$key] = $row;
            }
        }
        return array_values($selected);
    }

    /** @param array<string, mixed> $row */
    private function hasTrustedOnlineValidationStatus(array $row): bool
    {
        $status = strtolower(trim((string)($row['validation_status'] ?? '')));
        return in_array($status, [
            'normal',
            'available',
            'verified',
            'valid',
            'confirmed',
            'approved',
            'passed',
            'ok',
            'success',
            'complete',
            'completed',
        ], true);
    }

    /** @param array<string, mixed> $row */
    private function hasTrustedOnlineIngestionMethod(array $row): bool
    {
        $rawValue = $row['raw_data'] ?? [];
        $raw = is_array($rawValue) ? $rawValue : $this->decodeJson((string)$rawValue);
        foreach ([
            $row['ingestion_method'] ?? null,
            $row['source_method'] ?? null,
            $raw['ingestion_method'] ?? null,
            $raw['_ingestion_method'] ?? null,
            $raw['source_method'] ?? null,
        ] as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $method = strtolower(trim((string)$value));
            if ($method === '') {
                continue;
            }
            return !in_array($method, [
                'legacy',
                'manual',
                'manual_import',
                'manual_override',
                'user_provided',
                'user_provided_unverified',
                'import_csv',
                'import_json',
            ], true);
        }

        return false;
    }

    /** @param array<string, mixed> $row */
    private function hasTrustedOnlineCollectionTimestamp(array $row): bool
    {
        return $this->trustedOnlineCollectionTimestamp($row) > 0;
    }

    /** @param array<string, mixed> $row */
    private function trustedOnlineCollectionTimestamp(array $row): int
    {
        $rawValue = $row['raw_data'] ?? [];
        $raw = is_array($rawValue) ? $rawValue : $this->decodeJson((string)$rawValue);
        $meta = is_array($raw['meta'] ?? null) ? $raw['meta'] : [];
        $capture = is_array($raw['capture_evidence'] ?? null) ? $raw['capture_evidence'] : [];
        $timestamps = [];
        foreach ([
            $row['collected_at'] ?? null,
            $row['snapshot_time'] ?? null,
            $row['received_at'] ?? null,
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
            if (!is_scalar($value)) {
                continue;
            }
            $text = trim((string)$value);
            if (preg_match(
                '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?$/D',
                $text
            ) !== 1) {
                continue;
            }
            $timestamp = strtotime($text);
            if ($timestamp !== false && $timestamp > 0) {
                $timestamps[] = $timestamp;
            }
        }

        return $timestamps === [] ? 0 : min($timestamps);
    }

    /** @param array<string, mixed> $row */
    private function hasNoBlockingOnlineRowState(array $row): bool
    {
        foreach (['status', 'save_status'] as $field) {
            $status = strtolower(trim((string)($row[$field] ?? '')));
            if (in_array($status, [
                'failed',
                'fail',
                'error',
                'collection_failed',
                'capture_failed',
                'permission_denied',
                'binding_missing',
                'blocked',
                'rejected',
                'login_required',
                'unverified',
                'stale',
                'warning',
                'partial',
                'partial_success',
            ], true)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $row */
    private function isTrustedSelfOtaFactRow(array $row): bool
    {
        if (!$this->hasTrustedOtaEvidenceEnvelope($row)) {
            return false;
        }

        $compareType = strtolower(trim((string)($row['compare_type'] ?? '')));
        if ($compareType !== '' && $compareType !== 'self') {
            return false;
        }

        if (array_key_exists('hotel_id', $row)) {
            $otaHotelId = trim((string)$row['hotel_id']);
            if ($otaHotelId !== '' && is_numeric($otaHotelId) && (float)$otaHotelId <= 0) {
                return false;
            }
        }

        $source = $this->normalizeOtaChannel((string)($row['source'] ?? ''));
        $platform = $this->normalizeOtaChannel((string)($row['platform'] ?? ''));
        $knownChannels = ['ctrip', 'meituan', 'qunar'];
        if (($source !== '' && !in_array($source, $knownChannels, true))
            || ($platform !== '' && !in_array($platform, $knownChannels, true))
            || ($source === '' && $platform === '')
            || ($source !== '' && $platform !== '' && $source !== $platform)
        ) {
            return false;
        }

        $dataDate = trim((string)($row['data_date'] ?? ''));
        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $dataDate);
        if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $dataDate) {
            return false;
        }

        $today = date('Y-m-d');
        if ($dataDate > $today) {
            return false;
        }
        $period = strtolower(trim((string)($row['data_period'] ?? '')));
        if ($period === 'next_30_days') {
            return false;
        }
        if ($dataDate === $today && $period !== '' && $period !== 'realtime_snapshot') {
            return false;
        }
        if ($dataDate < $today && $period === 'realtime_snapshot') {
            return false;
        }
        if ($dataDate === $today && array_key_exists('is_final', $row) && (int)$row['is_final'] === 1) {
            return false;
        }
        if ($dataDate < $today
            && $period === 'historical_daily'
            && array_key_exists('is_final', $row)
            && (int)$row['is_final'] !== 1
        ) {
            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $row */
    private function hasTrustedOtaEvidenceEnvelope(array $row): bool
    {
        return $this->hasTrustedOnlineValidationStatus($row)
            && $this->hasNoBlockingOnlineRowState($row)
            && (int)($row['system_hotel_id'] ?? 0) > 0
            && (int)($row['data_source_id'] ?? 0) > 0
            && (int)($row['readback_verified'] ?? 0) === 1
            && $this->hasTrustedOnlineIngestionMethod($row)
            && $this->hasTrustedOnlineCollectionTimestamp($row);
    }

    private function normalizeOtaChannel(string $value): string
    {
        $value = strtolower(trim($value));
        return match ($value) {
            '携程', 'trip', 'trip.com', 'ebooking' => 'ctrip',
            '美团', 'meituan hotel' => 'meituan',
            '去哪儿', 'qunar.com' => 'qunar',
            default => $value,
        };
    }

    /** @param array<string, mixed> $summary */
    private function operatingSnapshotChannel(array $summary): string
    {
        $channels = [];
        foreach ((array)($summary['evidence_refs'] ?? []) as $evidenceRef) {
            if (!is_array($evidenceRef)) {
                continue;
            }
            $source = trim((string)($evidenceRef['source'] ?? ''));
            $platform = trim((string)($evidenceRef['platform'] ?? ''));
            $channel = $this->normalizeOtaChannel($source !== '' ? $source : $platform);
            if (in_array($channel, ['ctrip', 'meituan', 'qunar'], true)) {
                $channels[] = $channel;
            }
        }
        $channels = array_values(array_unique($channels));

        return count($channels) === 1 ? $channels[0] : '';
    }

    private function otaChannelLabel(string $channel): string
    {
        return match ($channel) {
            'ctrip' => '携程',
            'meituan' => '美团',
            'qunar' => '去哪儿',
            default => 'OTA',
        };
    }

    /** @param array<string, mixed> $row */
    private function hasOnlineFlowEvidence(array $row): bool
    {
        $keys = [
            'list_exposure', 'exposure', 'show_num', 'showNum', 'impression',
            'detail_exposure', 'visitors', 'visitor_num', 'visitorNum', 'qunarDetailVisitors',
            'views', 'total_detail_num', 'totalDetailNum', 'detailVisitors',
            'order_filling_num', 'orderFillingNum', 'order_page_visitor',
            'order_submit_num', 'book_order_num', 'bookOrderNum', 'orders',
        ];
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '' && is_numeric($row[$key])) {
                return true;
            }
        }
        $raw = $this->decodeJson((string)($row['raw_data'] ?? ''));
        foreach ($keys as $key) {
            if (array_key_exists($key, $raw) && $raw[$key] !== null && $raw[$key] !== '' && is_numeric($raw[$key])) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $row @param array<int, string> $keys */
    private function onlineRowHasNumericMetric(array $row, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '' && is_numeric($row[$key])) {
                return true;
            }
        }
        $raw = $this->decodeJson((string)($row['raw_data'] ?? ''));
        foreach ($keys as $key) {
            if (array_key_exists($key, $raw) && $raw[$key] !== null && $raw[$key] !== '' && is_numeric($raw[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{exposure: float, visitors: float, views: float, order_filling: float, orders: float, reported_flow_rate: ?float}
     */
    private function onlineFlowMetrics(array $row): array
    {
        $raw = $this->decodeJson((string)($row['raw_data'] ?? ''));
        $metric = function (array $keys) use ($row, $raw): float {
            $value = $this->firstNumericValue($row, $keys);
            if ($value === null) {
                $value = $this->firstNumericValue($raw, $keys, 0);
            }
            return max(0.0, (float)($value ?? 0));
        };
        $exposure = $metric(['list_exposure', 'exposure', 'show_num', 'showNum', 'impression']);
        $views = $metric(['detail_exposure', 'views', 'total_detail_num', 'totalDetailNum', 'detailVisitors']);
        $visitors = $metric(['visitors', 'visitor_num', 'visitorNum', 'qunarDetailVisitors', 'detail_exposure']);
        if ($views <= 0 && $visitors > 0) {
            $views = $visitors;
        }
        if ($visitors <= 0 && $views > 0) {
            $visitors = $views;
        }
        return [
            'exposure' => $exposure,
            'visitors' => $visitors,
            'views' => $views,
            'order_filling' => $metric(['order_filling_num', 'orderFillingNum', 'order_page_visitor']),
            'orders' => $metric(['order_submit_num', 'book_order_num', 'bookOrderNum', 'orders']),
            'reported_flow_rate' => $this->firstNumericValue(
                $row,
                ['flow_rate', 'flowRate'],
                $this->firstNumericValue($raw, ['flow_rate', 'flowRate'])
            ),
        ];
    }

    private function onlineEndpointIdFromDimension(string $dimension): string
    {
        if (preg_match('/^catalog:[^:]+:([^:]+)/', trim($dimension), $matches)) {
            return (string)($matches[1] ?? '');
        }
        return '';
    }

    /** @param array<string,mixed> $row */
    private function onlineEndpointIdFromRow(array $row): string
    {
        $raw = $this->decodeJson((string)($row['raw_data'] ?? ''));
        $rawRow = is_array($raw['row'] ?? null) ? $raw['row'] : [];
        $captureEvidence = is_array($raw['capture_evidence'] ?? null) ? $raw['capture_evidence'] : [];
        $ids = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => strtolower(trim((string)$value)),
            [
                $this->onlineEndpointIdFromDimension((string)($row['dimension'] ?? '')),
                $raw['endpoint_id'] ?? $raw['endpointId'] ?? '',
                $rawRow['endpoint_id'] ?? $rawRow['endpointId'] ?? '',
                $captureEvidence['endpoint_id'] ?? $captureEvidence['endpointId'] ?? '',
            ]
        ), static fn(string $value): bool => $value !== '')));
        if (count($ids) > 1) {
            return 'conflicting_endpoint_identity';
        }
        return (string)($ids[0] ?? '');
    }

    /** @param array<string, mixed> $row */
    private function onlineFlowRowRank(array $row): int
    {
        $metrics = $this->onlineFlowMetrics($row);
        $rank = 0;
        if ($metrics['exposure'] > 0) {
            $rank += 40;
        }
        if ($metrics['views'] > 0 || $metrics['visitors'] > 0) {
            $rank += 30;
        }
        if ($metrics['orders'] > 0) {
            $rank += 20;
        }
        if ($metrics['exposure'] > 0 && $metrics['views'] > 0 && $metrics['orders'] > 0) {
            $rank += 100;
        }
        if (str_contains(strtolower((string)($row['dimension'] ?? '')), 'flow_transform')) {
            $rank += 10;
        }
        return $rank;
    }

    /** @param array<string, mixed> $row */
    private function onlineRowTimestamp(array $row): int
    {
        foreach (['update_time', 'create_time'] as $field) {
            $value = trim((string)($row[$field] ?? ''));
            if ($value === '') {
                continue;
            }
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return $timestamp;
            }
        }
        return 0;
    }

    private function buildCompetitors(array $hotelIds, string $date, array $summary): array
    {
        $base = [
            'avg_price' => null,
            'avg_our_public_price' => null,
            'avg_score' => null,
            'price_gap' => null,
            'price_gap_percent' => null,
            'score_gap' => null,
            'rank_position' => null,
            'data_status' => self::DATA_PENDING,
            'comparability_status' => 'insufficient_evidence',
            'comparison_scope' => 'ota_public_rate_to_ota_public_rate',
            'comparison_key' => '',
            'visible_row_count' => 0,
            'decision_eligible_row_count' => 0,
            'excluded_from_decision_count' => 0,
            'quality_gaps' => [],
            'meituan_rank_summary' => $this->buildMeituanRankSummary($hotelIds, $date),
        ];

        if ($this->tableExists('competitor_analysis')) {
            try {
                $rows = Db::name('competitor_analysis')
                    ->whereIn('hotel_id', $hotelIds)
                    ->where('analysis_date', $date)
                    ->field('id,hotel_id,competitor_hotel_id,room_type_id,competitor_room_type_id,analysis_date,our_price,competitor_price,price_difference,price_index,ota_platform,competitor_data,create_time,update_time')
                    ->select()
                    ->toArray();
                if (!empty($rows)) {
                    $base['visible_row_count'] = count($rows);
                    $groups = [];
                    $gapCounts = [];
                    foreach ($rows as $row) {
                        $assessment = $this->competitorAnalysisComparability($row);
                        if (($assessment['eligible'] ?? false) !== true) {
                            foreach (($assessment['reasons'] ?? []) as $reason) {
                                $gapCounts[$reason] = ($gapCounts[$reason] ?? 0) + 1;
                            }
                            continue;
                        }
                        $key = (string)$assessment['comparison_key'];
                        $groups[$key]['our_prices'][] = (float)$row['our_price'];
                        $groups[$key]['competitor_prices'][] = (float)$row['competitor_price'];
                        $groups[$key]['latest'] = max(
                            (string)($groups[$key]['latest'] ?? ''),
                            (string)($assessment['captured_at'] ?? '')
                        );
                    }

                    $eligibleCount = array_sum(array_map(
                        static fn(array $group): int => count($group['competitor_prices'] ?? []),
                        $groups
                    ));
                    $base['decision_eligible_row_count'] = $eligibleCount;
                    $base['excluded_from_decision_count'] = max(0, count($rows) - $eligibleCount);
                    $base['quality_gaps'] = array_map(
                        static fn(string $code, int $count): array => ['code' => $code, 'row_count' => $count],
                        array_keys($gapCounts),
                        array_values($gapCounts)
                    );

                    if ($groups === []) {
                        $base['data_status'] = 'data_gap';
                        return $base;
                    }

                    uasort($groups, static function (array $left, array $right): int {
                        $countCompare = count($right['competitor_prices'] ?? []) <=> count($left['competitor_prices'] ?? []);
                        return $countCompare !== 0
                            ? $countCompare
                            : strcmp((string)($right['latest'] ?? ''), (string)($left['latest'] ?? ''));
                    });
                    $comparisonKey = (string)array_key_first($groups);
                    $group = $groups[$comparisonKey];
                    $base['avg_our_public_price'] = $this->avg($group['our_prices'] ?? []);
                    $base['avg_price'] = $this->avg($group['competitor_prices'] ?? []);
                    $base['price_gap'] = round($base['avg_our_public_price'] - $base['avg_price'], 2);
                    $base['price_gap_percent'] = $base['avg_price'] > 0
                        ? round($base['price_gap'] / $base['avg_price'] * 100, 2)
                        : null;
                    $base['comparison_key'] = $comparisonKey;
                    $base['comparability_status'] = 'eligible';
                    $base['data_status'] = self::DATA_OK;
                    return $base;
                }
            } catch (Throwable $e) {
                return $base;
            }
        }

        return $base;
    }

    /** @return array{eligible:bool,reasons:array<int,string>,comparison_key:string,captured_at:string} */
    private function competitorAnalysisComparability(array $row): array
    {
        $context = $this->arrayValue($row['competitor_data'] ?? []);
        foreach (['comparison_context', 'rate_context', 'source'] as $nestedKey) {
            $nested = $this->arrayValue($context[$nestedKey] ?? []);
            if ($nested !== []) {
                $context = array_merge($context, $nested);
            }
        }

        $context += [
            'platform' => $row['ota_platform'] ?? null,
            'captured_at' => $row['update_time'] ?? $row['create_time'] ?? '',
        ];
        $reasons = [];
        if (!is_numeric($row['our_price'] ?? null) || (float)$row['our_price'] <= 0
            || !is_numeric($row['competitor_price'] ?? null) || (float)$row['competitor_price'] <= 0
        ) {
            $reasons[] = 'public_price_missing';
        }

        $requiredStrings = [
            'platform', 'check_in_date', 'check_out_date', 'room_type_key', 'rate_plan_key',
            'breakfast', 'cancellation_policy', 'payment_mode', 'price_basis', 'currency',
            'availability', 'source_method', 'source_ref', 'captured_at', 'validation_status',
        ];
        foreach ($requiredStrings as $field) {
            if (!$this->competitorContextHasValue($context, $field)) {
                $reasons[] = $field . '_missing';
            }
        }
        if (!array_key_exists('tax_fee_included', $context)) {
            $reasons[] = 'tax_fee_included_missing';
        }
        if (!is_numeric($context['adults'] ?? null) || (int)$context['adults'] <= 0) {
            $reasons[] = 'adults_missing';
        }
        if (!is_numeric($context['children'] ?? null) || (int)$context['children'] < 0) {
            $reasons[] = 'children_missing';
        }
        if (!$this->competitorContextReadbackVerified($context['readback_verified'] ?? null)) {
            $reasons[] = 'readback_unverified';
        }
        if (!in_array(strtolower(trim((string)($context['validation_status'] ?? ''))), ['normal', 'available', 'ok', 'valid', 'verified'], true)) {
            $reasons[] = 'validation_failed';
        }
        if (!in_array(strtolower(trim((string)($context['availability'] ?? ''))), ['available', 'bookable'], true)) {
            $reasons[] = 'not_publicly_bookable';
        }

        $checkIn = trim((string)($context['check_in_date'] ?? ''));
        $checkOut = trim((string)($context['check_out_date'] ?? ''));
        if ($checkIn !== '' && $checkOut !== ''
            && (strtotime($checkIn) === false || strtotime($checkOut) === false || strtotime($checkOut) <= strtotime($checkIn))
        ) {
            $reasons[] = 'stay_date_invalid';
        }

        $keyFields = [
            'platform', 'check_in_date', 'check_out_date', 'room_type_key', 'rate_plan_key',
            'breakfast', 'cancellation_policy', 'payment_mode', 'tax_fee_included', 'price_basis',
            'currency', 'adults', 'children',
        ];
        $keyValues = [];
        foreach ($keyFields as $field) {
            $keyValues[] = strtolower(trim((string)($context[$field] ?? '')));
        }

        return [
            'eligible' => $reasons === [],
            'reasons' => array_values(array_unique($reasons)),
            'comparison_key' => hash('sha256', implode('|', $keyValues)),
            'captured_at' => trim((string)($context['captured_at'] ?? '')),
        ];
    }

    private function competitorContextHasValue(array $context, string $field): bool
    {
        return array_key_exists($field, $context)
            && $context[$field] !== null
            && trim((string)$context[$field]) !== '';
    }

    private function competitorContextReadbackVerified(mixed $value): bool
    {
        return $value === true
            || $value === 1
            || in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'verified'], true);
    }

    private function buildMeituanRankSummary(array $hotelIds, string $date): array
    {
        $base = $this->emptyMeituanRankSummary();
        if (empty($hotelIds)) {
            $base['rank_missing_reason'] = 'hotel scope is empty';
            return $base;
        }

        $start = date('Y-m-d', strtotime($date . ' -120 days'));
        $rows = array_values(array_filter(
            $this->onlineRows($hotelIds, $start, $date),
            fn(array $row): bool => $this->isMeituanBusinessRankRow($row)
        ));
        if (empty($rows)) {
            return $base;
        }

        $latestDataDate = '';
        foreach ($rows as $row) {
            $rowDate = (string)($row['data_date'] ?? '');
            if ($rowDate !== '' && ($latestDataDate === '' || strcmp($rowDate, $latestDataDate) > 0)) {
                $latestDataDate = $rowDate;
            }
        }

        $latestDateRows = array_values(array_filter($rows, static fn(array $row): bool => (string)($row['data_date'] ?? '') === $latestDataDate));
        $latestFetchedAt = $this->maxOnlineRowFetchedAt($latestDateRows);
        $batchRows = $latestFetchedAt !== ''
            ? array_values(array_filter($latestDateRows, fn(array $row): bool => $this->onlineRowFetchedAt($row) === $latestFetchedAt))
            : $latestDateRows;
        if (empty($batchRows)) {
            $batchRows = $latestDateRows;
        }

        $base['source_evidence'] = $this->summarizeMeituanRankSourceEvidence($batchRows);
        $targetPoiId = $this->resolveMeituanTargetPoiId($hotelIds);
        $hotels = $this->buildMeituanRankHotels($batchRows, $targetPoiId);

        if (empty($hotels)) {
            $base['record_count'] = count($batchRows);
            $base['latest_data_date'] = $latestDataDate;
            $base['latest_fetched_at'] = $latestFetchedAt;
            $base['rank_missing_reason'] = 'Meituan rows exist, but no restorable hotel ranking row was found.';
            return $base;
        }

        uasort($hotels, static function (array $a, array $b): int {
            $rankA = !empty($a['rank_values']) ? min($a['rank_values']) : PHP_INT_MAX;
            $rankB = !empty($b['rank_values']) ? min($b['rank_values']) : PHP_INT_MAX;
            if ($rankA !== $rankB) {
                return $rankA <=> $rankB;
            }
            return strcmp((string)$a['hotel_name'], (string)$b['hotel_name']);
        });

        $rankedHotels = array_values(array_filter($hotels, static fn(array $hotel): bool => !empty($hotel['rank_values'])));
        $selfHotel = null;
        foreach ($hotels as $hotel) {
            if (!empty($hotel['is_self'])) {
                $selfHotel = $hotel;
                break;
            }
        }

        $topHotel = $rankedHotels[0] ?? null;
        $selfRank = is_array($selfHotel) && !empty($selfHotel['rank_values']) ? min($selfHotel['rank_values']) : null;
        $topRank = is_array($topHotel) && !empty($topHotel['rank_values']) ? min($topHotel['rank_values']) : null;
        $previousRank = null;
        if ($selfRank !== null) {
            foreach (array_reverse($rankedHotels) as $hotel) {
                $candidateRank = min($hotel['rank_values']);
                if ($candidateRank < $selfRank) {
                    $previousRank = $candidateRank;
                    break;
                }
            }
        }

        $tagSummary = $this->summarizeMeituanPlatformTags($hotels);
        $rankStatus = !empty($rankedHotels) ? 'ok' : 'missing';
        $rankMissingReason = '';
        if ($rankStatus === 'missing') {
            $rankMissingReason = 'Meituan ranking rows exist, but rank/ranking fields were not returned.';
        } elseif ($targetPoiId === '') {
            $rankStatus = 'self_unbound';
            $rankMissingReason = 'Meituan POI/Store ID is not bound, so self position cannot be confirmed.';
        } elseif (!is_array($selfHotel)) {
            $rankStatus = 'self_missing';
            $rankMissingReason = 'Target POI was not found in the latest Meituan ranking batch.';
        } elseif ($selfRank === null) {
            $rankStatus = 'self_rank_missing';
            $rankMissingReason = 'Self row exists, but rank/ranking field was not returned.';
        }

        $trend = $this->summarizeMeituanRankTrend(is_array($selfHotel) ? $selfHotel['rank_history'] : []);
        $base['data_status'] = self::DATA_OK;
        $base['latest_data_date'] = $latestDataDate;
        $base['latest_fetched_at'] = $latestFetchedAt;
        $base['record_count'] = count($batchRows);
        $base['sample_count'] = count($batchRows);
        $base['hotel_count'] = count($hotels);
        $base['rank_status'] = $rankStatus;
        $base['rank_missing_reason'] = $rankMissingReason;
        $base['self_position_text'] = $selfRank !== null ? ('第' . $selfRank) : '未返回';
        $base['top_hotel_name'] = is_array($topHotel) ? (string)$topHotel['hotel_name'] : '未返回';
        $base['top_rank'] = $topRank;
        $base['gap_to_previous_text'] = $selfRank !== null && $previousRank !== null
            ? ('排名差 ' . ($selfRank - $previousRank) . ' 名；平台未返回指标差额')
            : '未返回';
        $base['top1_gap_text'] = $selfRank !== null && $topRank !== null
            ? ($selfRank === $topRank ? '本店为TOP1' : ('落后TOP1 ' . ($selfRank - $topRank) . ' 名；平台未返回指标差额'))
            : '未返回';
        $base['rank_gap_metric_status'] = 'missing';
        $base['rank_trend_status'] = $trend['status'];
        $base['rank_trend_text'] = $trend['text'];
        $base['platform_tag_status'] = $tagSummary['status'];
        $base['platform_tag_text'] = $tagSummary['text'];
        $base['vip_count'] = $tagSummary['vip_count'];
        $base['tag_returned_count'] = $tagSummary['returned_count'];
        $base['returned_empty_count'] = $tagSummary['returned_empty_count'];
        $base['not_returned_count'] = $tagSummary['not_returned_count'];
        $base['target_poi_bound'] = $targetPoiId !== '';
        $previousBatchRows = $this->previousMeituanRankBatchRows($rows, $latestDataDate, $latestFetchedAt);
        $currentChangeSnapshot = $this->summarizeMeituanRankBatchSnapshot($hotels, $latestDataDate, $latestFetchedAt, count($batchRows));
        $previousChangeSnapshot = !empty($previousBatchRows)
            ? $this->summarizeMeituanRankBatchSnapshot(
                $this->buildMeituanRankHotels($previousBatchRows, $targetPoiId),
                (string)($previousBatchRows[0]['data_date'] ?? ''),
                $this->maxOnlineRowFetchedAt($previousBatchRows),
                count($previousBatchRows)
            )
            : [];
        $changeMonitor = $this->summarizeMeituanRankBatchChanges($currentChangeSnapshot, $previousChangeSnapshot);

        $base['previous_data_date'] = (string)($previousChangeSnapshot['data_date'] ?? '');
        $base['previous_fetched_at'] = (string)($previousChangeSnapshot['fetched_at'] ?? '');
        $base['change_monitor_status'] = $changeMonitor['status'];
        $base['change_missing_reason'] = $changeMonitor['missing_reason'];
        $base['change_alerts'] = $changeMonitor['alerts'];
        $base['source_ref'] = 'online_daily_data.raw_data.platformTags/platformTagStatus/rank';

        return $base;
    }

    private function emptyMeituanRankSummary(): array
    {
        return [
            'data_status' => self::DATA_PENDING,
            'source_ref' => 'online_daily_data.raw_data',
            'privacy_scope' => 'Platform hotel tags and ranking aggregates only; excludes guest privacy, order phone, room status and room-source mapping.',
            'latest_data_date' => '',
            'latest_fetched_at' => '',
            'previous_data_date' => '',
            'previous_fetched_at' => '',
            'record_count' => 0,
            'sample_count' => 0,
            'hotel_count' => 0,
            'rank_status' => 'missing',
            'rank_missing_reason' => 'No Meituan competitor ranking rows found for permitted hotels up to report date.',
            'self_position_text' => '未返回',
            'top_hotel_name' => '未返回',
            'top_rank' => null,
            'gap_to_previous_text' => '未返回',
            'top1_gap_text' => '未返回',
            'rank_gap_metric_status' => 'missing',
            'rank_trend_status' => 'missing',
            'rank_trend_text' => '平台未返回可比榜单历史',
            'platform_tag_status' => 'not_returned',
            'platform_tag_text' => '平台标签未返回，不推断VIP',
            'vip_count' => 0,
            'tag_returned_count' => 0,
            'returned_empty_count' => 0,
            'not_returned_count' => 0,
            'target_poi_bound' => false,
            'source_evidence' => [
                'status' => 'missing',
                'row_count' => 0,
                'complete_row_count' => 0,
                'source_row_ids' => [],
                'source_trace_ids' => [],
                'source_methods' => [],
                'missing_fields' => ['source_rows'],
            ],
            'change_monitor_status' => 'missing',
            'change_missing_reason' => 'No comparable previous Meituan ranking batch found.',
            'change_alerts' => [],
        ];
    }

    private function buildMeituanRankHotels(array $batchRows, string $targetPoiId): array
    {
        $hotels = [];
        foreach ($batchRows as $row) {
            $raw = $this->decodeJson((string)($row['raw_data'] ?? ''));
            $poiId = $this->firstStringValue($raw, ['poiId', 'poi_id', 'hotelId', 'hotel_id'], (string)($row['hotel_id'] ?? ''));
            $hotelName = $this->firstStringValue($raw, ['poiName', 'poi_name', 'hotelName', 'hotel_name', 'shopName', 'name'], (string)($row['hotel_name'] ?? ''));
            if ($poiId === '' && $hotelName === '') {
                continue;
            }

            $key = $poiId !== '' ? $poiId : $hotelName;
            if (!isset($hotels[$key])) {
                $hotels[$key] = [
                    'poi_id' => $poiId,
                    'hotel_name' => $hotelName,
                    'is_self' => $targetPoiId !== '' && $poiId !== '' && $poiId === $targetPoiId,
                    'rank_values' => [],
                    'rank_history' => [],
                    'platform_tags' => [],
                    'platform_tag_status' => 'not_returned',
                    'has_vip_tag' => false,
                    'metrics' => [],
                ];
            }

            $rank = (int)($this->firstNumericValue($raw, ['rank', 'ranking', 'rankNo', 'rankIndex']) ?? 0);
            $rankType = $this->firstStringValue($raw, ['rankType', 'rank_type'], '');
            $dateRange = $this->firstStringValue($raw, ['dateRange', 'date_range'], '');
            $metricField = $this->classifyMeituanRankMetric(
                (string)($row['dimension'] ?? $raw['dimension'] ?? $raw['_dimName'] ?? ''),
                (string)($raw['aiMetricName'] ?? $raw['ai_metric_name'] ?? $raw['_aiMetricName'] ?? ''),
                $rankType
            );
            $metricValue = $this->firstNumericValue($raw, ['dataValue', 'data_value', 'value', 'metricValue'], $row['data_value'] ?? null);

            if ($rank > 0) {
                $hotels[$key]['rank_values'][] = $rank;
                $hotels[$key]['rank_history'][] = [
                    'rank' => $rank,
                    'rank_type' => $rankType,
                    'date_range' => $dateRange,
                    'metric' => $metricField,
                    'value' => $metricValue,
                ];
            }
            if ($metricField !== '' && $metricValue !== null) {
                $hotels[$key]['metrics'][$metricField] = (float)$metricValue;
            }

            $tagInfo = $this->meituanPlatformTagInfo($raw);
            $hotels[$key]['platform_tags'] = $this->mergeStringValues($hotels[$key]['platform_tags'], $tagInfo['tags']);
            if ($tagInfo['status'] !== 'not_returned') {
                $hotels[$key]['platform_tag_status'] = $tagInfo['status'];
            }
            if (!empty($tagInfo['has_vip'])) {
                $hotels[$key]['has_vip_tag'] = true;
            }
        }

        uasort($hotels, static function (array $a, array $b): int {
            $rankA = !empty($a['rank_values']) ? min($a['rank_values']) : PHP_INT_MAX;
            $rankB = !empty($b['rank_values']) ? min($b['rank_values']) : PHP_INT_MAX;
            if ($rankA !== $rankB) {
                return $rankA <=> $rankB;
            }
            return strcmp((string)$a['hotel_name'], (string)$b['hotel_name']);
        });

        return $hotels;
    }

    private function previousMeituanRankBatchRows(array $rows, string $latestDataDate, string $latestFetchedAt): array
    {
        $batches = [];
        foreach ($rows as $row) {
            $dataDate = (string)($row['data_date'] ?? '');
            if ($dataDate === '' || ($latestDataDate !== '' && strcmp($dataDate, $latestDataDate) > 0)) {
                continue;
            }

            $fetchedAt = $this->onlineRowFetchedAt($row);
            if ($dataDate === $latestDataDate) {
                if ($latestFetchedAt === '' || $fetchedAt === '' || strcmp($fetchedAt, $latestFetchedAt) >= 0) {
                    continue;
                }
            }

            $key = $dataDate . '|' . $fetchedAt;
            if (!isset($batches[$key])) {
                $batches[$key] = [
                    'data_date' => $dataDate,
                    'fetched_at' => $fetchedAt,
                    'rows' => [],
                ];
            }
            $batches[$key]['rows'][] = $row;
        }

        if (empty($batches)) {
            return [];
        }

        usort($batches, static function (array $a, array $b): int {
            $dateCompare = strcmp((string)$b['data_date'], (string)$a['data_date']);
            if ($dateCompare !== 0) {
                return $dateCompare;
            }
            return strcmp((string)$b['fetched_at'], (string)$a['fetched_at']);
        });

        return $batches[0]['rows'] ?? [];
    }

    private function summarizeMeituanRankBatchSnapshot(array $hotels, string $dataDate, string $fetchedAt, int $recordCount): array
    {
        $rankedHotels = array_values(array_filter($hotels, static fn(array $hotel): bool => !empty($hotel['rank_values'])));
        $selfHotel = null;
        foreach ($hotels as $hotel) {
            if (!empty($hotel['is_self'])) {
                $selfHotel = $hotel;
                break;
            }
        }

        $topHotel = $rankedHotels[0] ?? null;
        $selfRank = is_array($selfHotel) && !empty($selfHotel['rank_values']) ? min($selfHotel['rank_values']) : null;
        $topRank = is_array($topHotel) && !empty($topHotel['rank_values']) ? min($topHotel['rank_values']) : null;
        $tagSummary = $this->summarizeMeituanPlatformTags($hotels);

        return [
            'data_date' => $dataDate,
            'fetched_at' => $fetchedAt,
            'record_count' => $recordCount,
            'hotel_count' => count($hotels),
            'has_rank_evidence' => !empty($rankedHotels),
            'has_top1_evidence' => is_array($topHotel) && $topRank !== null,
            'has_self_rank_evidence' => $selfRank !== null,
            'top_hotel_name' => is_array($topHotel) ? (string)($topHotel['hotel_name'] ?? '') : '',
            'top_poi_id' => is_array($topHotel) ? (string)($topHotel['poi_id'] ?? '') : '',
            'top_rank' => $topRank,
            'self_rank' => $selfRank,
            'platform_tag_status' => $tagSummary['status'],
            'has_platform_tag_evidence' => $tagSummary['status'] !== 'not_returned',
            'vip_count' => $tagSummary['vip_count'],
            'tag_returned_count' => $tagSummary['returned_count'],
            'returned_empty_count' => $tagSummary['returned_empty_count'],
        ];
    }

    private function summarizeMeituanRankBatchChanges(array $current, array $previous): array
    {
        if (empty($previous)) {
            return [
                'status' => 'missing',
                'missing_reason' => 'No comparable previous Meituan ranking batch found.',
                'alerts' => [],
            ];
        }

        $alerts = [];
        $missingReasons = [];
        $hasComparableEvidence = false;

        $currentTopKey = (string)(($current['top_poi_id'] ?? '') ?: ($current['top_hotel_name'] ?? ''));
        $previousTopKey = (string)(($previous['top_poi_id'] ?? '') ?: ($previous['top_hotel_name'] ?? ''));
        if (($current['has_top1_evidence'] ?? false) && ($previous['has_top1_evidence'] ?? false) && $currentTopKey !== '' && $previousTopKey !== '') {
            $hasComparableEvidence = true;
            if ($currentTopKey !== $previousTopKey) {
                $alerts[] = [
                    'type' => 'top1_changed',
                    'level' => 'medium',
                    'title' => 'Meituan TOP1 changed',
                    'message' => 'Meituan competitor TOP1 changed from ' . (string)($previous['top_hotel_name'] ?? '') . ' to ' . (string)($current['top_hotel_name'] ?? '') . '.',
                    'current' => ['top_hotel_name' => $current['top_hotel_name'] ?? '', 'top_rank' => $current['top_rank'] ?? null],
                    'previous' => ['top_hotel_name' => $previous['top_hotel_name'] ?? '', 'top_rank' => $previous['top_rank'] ?? null],
                ];
            }
        } else {
            $missingReasons[] = 'TOP1 rank fields are not comparable.';
        }

        if (($current['has_self_rank_evidence'] ?? false) && ($previous['has_self_rank_evidence'] ?? false)) {
            $hasComparableEvidence = true;
            $currentRank = (int)($current['self_rank'] ?? 0);
            $previousRank = (int)($previous['self_rank'] ?? 0);
            if ($currentRank > 0 && $previousRank > 0 && $currentRank !== $previousRank) {
                $direction = $currentRank < $previousRank ? 'up' : 'down';
                $delta = abs($currentRank - $previousRank);
                $alerts[] = [
                    'type' => 'self_rank_changed',
                    'level' => $direction === 'down' ? 'medium' : 'low',
                    'title' => 'Meituan self rank changed',
                    'message' => 'Meituan self rank changed from ' . $previousRank . ' to ' . $currentRank . ' (' . $direction . ' ' . $delta . ').',
                    'direction' => $direction,
                    'delta' => $delta,
                    'current' => ['self_rank' => $currentRank],
                    'previous' => ['self_rank' => $previousRank],
                ];
            }
        } else {
            $missingReasons[] = 'Self rank fields are not comparable.';
        }

        $currentTagStatus = (string)($current['platform_tag_status'] ?? '');
        $previousTagStatus = (string)($previous['platform_tag_status'] ?? '');
        if ($currentTagStatus !== '' && $previousTagStatus !== '') {
            if ($currentTagStatus !== 'not_returned' || $previousTagStatus !== 'not_returned') {
                $hasComparableEvidence = true;
            }
            if ($currentTagStatus !== $previousTagStatus) {
                $hasComparableEvidence = true;
                $alerts[] = [
                    'type' => 'platform_tag_status_changed',
                    'level' => 'low',
                    'title' => 'Meituan platform tag status changed',
                    'message' => 'Meituan platform tag return status changed from ' . $previousTagStatus . ' to ' . $currentTagStatus . '; missing tags do not imply non-VIP.',
                    'current' => ['platform_tag_status' => $currentTagStatus],
                    'previous' => ['platform_tag_status' => $previousTagStatus],
                ];
            }
        }

        if (($current['has_platform_tag_evidence'] ?? false) && ($previous['has_platform_tag_evidence'] ?? false)) {
            $hasComparableEvidence = true;
            $currentVipCount = (int)($current['vip_count'] ?? 0);
            $previousVipCount = (int)($previous['vip_count'] ?? 0);
            if ($currentVipCount !== $previousVipCount) {
                $alerts[] = [
                    'type' => 'vip_count_changed',
                    'level' => 'low',
                    'title' => 'Meituan VIP tag count changed',
                    'message' => 'Meituan VIP-tagged competitor count changed from ' . $previousVipCount . ' to ' . $currentVipCount . '.',
                    'delta' => $currentVipCount - $previousVipCount,
                    'current' => ['vip_count' => $currentVipCount],
                    'previous' => ['vip_count' => $previousVipCount],
                ];
            }
        } else {
            $missingReasons[] = 'VIP/platform tag fields are not comparable; no VIP inference is made.';
        }

        if (!$hasComparableEvidence) {
            return [
                'status' => 'missing',
                'missing_reason' => implode(' ', array_values(array_unique($missingReasons))),
                'alerts' => [],
            ];
        }

        return [
            'status' => !empty($alerts) ? 'changed' : 'ok',
            'missing_reason' => implode(' ', array_values(array_unique($missingReasons))),
            'alerts' => $alerts,
        ];
    }

    private function isMeituanBusinessRankRow(array $row): bool
    {
        if (!$this->hasTrustedOtaEvidenceEnvelope($row)) {
            return false;
        }
        $source = strtolower((string)($row['source'] ?? ''));
        $platform = strtolower((string)($row['platform'] ?? ''));
        $dataType = strtolower((string)($row['data_type'] ?? ''));
        return ($source === 'meituan' || $platform === 'meituan') && ($dataType === '' || $dataType === 'business');
    }

    /** @return array<string, mixed> */
    private function summarizeMeituanRankSourceEvidence(array $rows): array
    {
        $completeRowCount = 0;
        $rowIds = [];
        $traceIds = [];
        $sourceMethods = [];
        $missingFields = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rawValue = $row['raw_data'] ?? [];
            $raw = is_array($rawValue) ? $rawValue : $this->decodeJson((string)$rawValue);
            $capture = is_array($raw['capture_evidence'] ?? null) ? $raw['capture_evidence'] : [];
            $meta = is_array($raw['meta'] ?? null) ? $raw['meta'] : [];
            $traceId = '';
            foreach ([
                $row['source_trace_id'] ?? null,
                $raw['source_trace_id'] ?? null,
                $capture['source_trace_id'] ?? null,
                $meta['source_trace_id'] ?? null,
            ] as $value) {
                if (is_scalar($value) && trim((string)$value) !== '') {
                    $traceId = trim((string)$value);
                    break;
                }
            }
            $sourceMethod = '';
            foreach ([
                $row['ingestion_method'] ?? null,
                $row['source_method'] ?? null,
                $raw['ingestion_method'] ?? null,
                $raw['_ingestion_method'] ?? null,
                $raw['source_method'] ?? null,
            ] as $value) {
                if (is_scalar($value) && trim((string)$value) !== '') {
                    $sourceMethod = strtolower(trim((string)$value));
                    break;
                }
            }

            $rowMissing = [];
            $rowId = (int)($row['id'] ?? 0);
            if ($rowId <= 0) {
                $rowMissing[] = 'source_row_id';
            }
            if ($traceId === '') {
                $rowMissing[] = 'source_trace_id';
            }
            if ($sourceMethod === '') {
                $rowMissing[] = 'source_method';
            }
            if ($this->trustedOnlineCollectionTimestamp($row) <= 0) {
                $rowMissing[] = 'collected_at';
            }
            if ($rowMissing === []) {
                $completeRowCount++;
            } else {
                $missingFields = array_merge($missingFields, $rowMissing);
            }
            if ($rowId > 0) {
                $rowIds[] = $rowId;
            }
            if ($traceId !== '') {
                $traceIds[] = $traceId;
            }
            if ($sourceMethod !== '') {
                $sourceMethods[] = $sourceMethod;
            }
        }

        $rowCount = count($rows);
        return [
            'status' => $rowCount > 0 && $completeRowCount === $rowCount ? 'verified' : 'unverified',
            'row_count' => $rowCount,
            'complete_row_count' => $completeRowCount,
            'source_row_ids' => array_values(array_unique($rowIds)),
            'source_trace_ids' => array_values(array_unique($traceIds)),
            'source_methods' => array_values(array_unique($sourceMethods)),
            'missing_fields' => array_values(array_unique($missingFields)),
        ];
    }

    private function resolveMeituanTargetPoiId(array $hotelIds): string
    {
        $hotelIds = array_values(array_unique(array_filter(array_map('intval', $hotelIds), static fn(int $id): bool => $id > 0)));
        if ($hotelIds === [] || !$this->tableExists('platform_data_sources')) {
            return '';
        }

        $identityColumn = '';
        foreach (['platform_hotel_id', 'poi_id', 'store_id'] as $candidate) {
            if ($this->tableHasColumn('platform_data_sources', $candidate)) {
                $identityColumn = $candidate;
                break;
            }
        }
        if ($identityColumn === '') {
            return '';
        }

        try {
            $rows = Db::name('platform_data_sources')
                ->where('platform', 'meituan')
                ->whereIn('system_hotel_id', $hotelIds)
                ->where('enabled', 1)
                ->whereIn('status', ['ready', 'success', 'partial_success'])
                ->field(['system_hotel_id', $identityColumn])
                ->order('update_time', 'desc')
                ->select()
                ->toArray();
        } catch (Throwable $e) {
            return '';
        }

        $identities = [];
        foreach ($rows as $row) {
            $value = trim((string)($row[$identityColumn] ?? ''));
            if ($value !== '' && preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $value) === 1) {
                $identities[$value] = true;
            }
        }

        return count($identities) === 1 ? (string)array_key_first($identities) : '';
    }

    private function meituanPlatformTagInfo(array $raw): array
    {
        $tags = [];
        foreach (['platformTags', 'tags', 'tagList', 'badgeList', 'benefitTags', 'titleTags', 'identityTags'] as $key) {
            $tags = $this->mergeStringValues($tags, $this->stringListValue($raw[$key] ?? []));
        }
        foreach (['platformTagText', 'vipTag', 'memberTag', 'rightsTag', 'platformTag', 'crownLevel', 'crownTag'] as $key) {
            $tags = $this->mergeStringValues($tags, $this->stringListValue($raw[$key] ?? []));
        }

        $hasVip = !empty($raw['hasVipTag']) || !empty($raw['isVip']) || !empty($raw['vipFlag']) || !empty($raw['memberFlag']) || $this->hasMeituanVipTag($tags);
        $status = (string)($raw['platformTagStatus'] ?? '');
        if ($status === '') {
            if (!empty($tags)) {
                $status = 'returned';
            } elseif (array_key_exists('platformTags', $raw) || array_key_exists('tags', $raw) || array_key_exists('tagList', $raw)) {
                $status = 'returned_empty';
            } else {
                $status = 'not_returned';
            }
        }

        return [
            'tags' => $tags,
            'status' => $status,
            'has_vip' => $hasVip,
        ];
    }

    private function summarizeMeituanPlatformTags(array $hotels): array
    {
        $returned = 0;
        $returnedEmpty = 0;
        $notReturned = 0;
        $vip = 0;
        foreach ($hotels as $hotel) {
            $tags = is_array($hotel['platform_tags'] ?? null) ? $hotel['platform_tags'] : [];
            if (!empty($tags)) {
                $returned++;
            } elseif (($hotel['platform_tag_status'] ?? '') === 'returned_empty') {
                $returnedEmpty++;
            } else {
                $notReturned++;
            }
            if (!empty($hotel['has_vip_tag']) || $this->hasMeituanVipTag($tags)) {
                $vip++;
            }
        }

        $status = $returned > 0 ? 'returned' : ($returnedEmpty > 0 ? 'returned_empty' : 'not_returned');
        $text = match ($status) {
            'returned' => 'VIP ' . $vip . '家 / 平台标签返回 ' . $returned . '家',
            'returned_empty' => '平台返回空标签 ' . $returnedEmpty . '家，不推断VIP',
            default => '平台标签未返回，不推断VIP',
        };

        return [
            'status' => $status,
            'text' => $text,
            'returned_count' => $returned,
            'returned_empty_count' => $returnedEmpty,
            'not_returned_count' => $notReturned,
            'vip_count' => $vip,
        ];
    }

    private function summarizeMeituanRankTrend(array $history): array
    {
        $ranks = array_values(array_filter($history, static fn(array $item): bool => (int)($item['rank'] ?? 0) > 0));
        if (count($ranks) < 2) {
            return ['status' => 'missing', 'text' => '平台未返回可比榜单历史'];
        }

        usort($ranks, static function (array $a, array $b): int {
            $order = ['0' => 0, '1' => 1, '7' => 2, '30' => 3, '' => 9];
            $rangeA = (string)($a['date_range'] ?? '');
            $rangeB = (string)($b['date_range'] ?? '');
            return ($order[$rangeA] ?? 8) <=> ($order[$rangeB] ?? 8);
        });

        $current = (int)($ranks[0]['rank'] ?? 0);
        $previous = (int)($ranks[1]['rank'] ?? 0);
        if ($current <= 0 || $previous <= 0) {
            return ['status' => 'missing', 'text' => '平台未返回可比榜单历史'];
        }
        if ($current === $previous) {
            return ['status' => 'flat', 'text' => '排名持平'];
        }
        if ($current < $previous) {
            return ['status' => 'up', 'text' => '上升' . ($previous - $current) . '名'];
        }
        return ['status' => 'down', 'text' => '下降' . ($current - $previous) . '名'];
    }

    private function classifyMeituanRankMetric(string $dimension, string $metricName, string $rankType): string
    {
        $combined = mb_strtolower($dimension . '|' . $metricName . '|' . $rankType, 'UTF-8');
        if ($rankType === 'P_XS' || str_contains($combined, '销售') || str_contains($combined, 'sales')) {
            return str_contains($combined, '间夜') || str_contains($combined, 'roomnight') ? 'salesRoomNights' : 'sales';
        }
        if ($rankType === 'P_LL' || str_contains($combined, '流量') || str_contains($combined, '曝光') || str_contains($combined, '浏览')) {
            return str_contains($combined, '浏览') || str_contains($combined, 'view') ? 'views' : 'exposure';
        }
        if ($rankType === 'P_ZH' || str_contains($combined, '转化') || str_contains($combined, 'conversion')) {
            return str_contains($combined, '支付') || str_contains($combined, 'pay') ? 'payConversion' : 'viewConversion';
        }
        if ($rankType === 'P_RZ' || str_contains($combined, '入住')) {
            return str_contains($combined, '房费') || str_contains($combined, '收入') || str_contains($combined, 'revenue') ? 'roomRevenue' : 'roomNights';
        }
        return '';
    }

    private function firstStringValue(array $data, array $keys, string $default = ''): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $value = trim((string)$data[$key]);
            if ($value !== '') {
                return $value;
            }
        }
        return trim($default);
    }

    private function firstNumericValue(array $data, array $keys, mixed $default = null): ?float
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && is_numeric($data[$key])) {
                return (float)$data[$key];
            }
        }
        return is_numeric($default) ? (float)$default : null;
    }

    private function stringListValue(mixed $value): array
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $item) {
                if (is_array($item)) {
                    foreach (['name', 'text', 'label', 'title', 'tagName', 'tag'] as $key) {
                        if (trim((string)($item[$key] ?? '')) !== '') {
                            $result[] = trim((string)$item[$key]);
                            break;
                        }
                    }
                    continue;
                }
                $text = trim((string)$item);
                if ($text !== '' && $text !== '未返回') {
                    $result[] = $text;
                }
            }
            return array_values(array_unique($result));
        }

        $text = trim((string)$value);
        if ($text === '' || $text === '未返回') {
            return [];
        }
        return array_values(array_filter(array_map('trim', preg_split('/[\/,，;；|]+/u', $text) ?: [])));
    }

    private function mergeStringValues(array $left, array $right): array
    {
        return array_values(array_unique(array_filter(array_merge($left, $right), static fn(string $value): bool => trim($value) !== '')));
    }

    private function hasMeituanVipTag(array $tags): bool
    {
        foreach ($tags as $tag) {
            if (preg_match('/vip|会员|皇冠|权益|甄选|优选/iu', (string)$tag) === 1) {
                return true;
            }
        }
        return false;
    }

    private function onlineRowFetchedAt(array $row): string
    {
        return (string)($row['update_time'] ?? $row['create_time'] ?? '');
    }

    private function maxOnlineRowFetchedAt(array $rows): string
    {
        $max = '';
        foreach ($rows as $row) {
            $time = $this->onlineRowFetchedAt($row);
            if ($time !== '' && ($max === '' || strcmp($time, $max) > 0)) {
                $max = $time;
            }
        }
        return $max;
    }

    private function buildServiceQuality(array $hotelIds, string $date): array
    {
        return $this->buildServiceQualityFromRows($this->onlineRows($hotelIds, $date, $date));
    }

    private function buildServiceQualityFromRows(array $rows): array
    {
        $base = [
            'avg_psi_score' => null,
            'avg_service_score' => null,
            'sample_count' => 0,
            'psi_sample_count' => 0,
            'service_score_sample_count' => 0,
            'data_status' => self::DATA_PENDING,
            'score_scale' => 'unknown',
            'threshold_80_eligible' => false,
            'data_gaps' => [],
        ];

        $psiScores = [];
        $serviceScores = [];
        foreach ($rows as $row) {
            $dataType = strtolower((string)($row['data_type'] ?? ''));
            if (!in_array($dataType, ['quality', 'service', 'service_quality', 'psi'], true)) {
                continue;
            }
            if (!$this->isTrustedSelfOtaFactRow($row)) {
                continue;
            }

            $raw = $this->decodeJson((string)($row['raw_data'] ?? ''));
            $psi = $this->nestedOnlineMetric($raw, ['psiScore', 'psi_score', 'psi', 'serviceQualityScore', 'qualityScore']);
            if ($psi === null && str_contains(strtolower((string)($row['dimension'] ?? '')), ':psi_score')) {
                $psi = $this->firstNumericMetric($row, ['data_value']);
            }
            $serviceScore = $this->nestedOnlineMetric($raw, ['serviceScore', 'service_score', 'dayReportServiceScore', 'service_score_value']);

            if ($psi !== null && $psi > 0) {
                $psiScores[] = $psi;
                $base['psi_sample_count']++;
            }
            if ($serviceScore !== null && $serviceScore > 0) {
                $serviceScores[] = $serviceScore;
                $base['service_score_sample_count']++;
            }
            if (($psi !== null && $psi > 0) || ($serviceScore !== null && $serviceScore > 0)) {
                $base['sample_count']++;
            }
        }

        if ($base['sample_count'] <= 0) {
            return $base;
        }

        $base['avg_psi_score'] = $psiScores !== [] ? $this->avg($psiScores) : null;
        $base['avg_service_score'] = $serviceScores !== [] ? $this->avg($serviceScores) : null;
        $scores = array_merge($psiScores, $serviceScores);
        $base['threshold_80_eligible'] = $this->scoresUseHundredPointScale($scores);
        $base['score_scale'] = $base['threshold_80_eligible'] ? '0_100' : 'unknown';
        $base['data_status'] = $base['threshold_80_eligible'] ? self::DATA_OK : 'partial';
        $base['data_gaps'] = $base['threshold_80_eligible'] ? [] : ['service_quality_scale_unknown'];

        return $base;
    }

    /** @param array<string, mixed> $raw @param array<int, string> $keys */
    private function nestedOnlineMetric(array $raw, array $keys): ?float
    {
        $payloads = [$raw];
        foreach ([
            $raw['row'] ?? null,
            $raw['raw_data'] ?? null,
            $raw['row']['raw_data'] ?? null,
        ] as $payload) {
            if (is_array($payload)) {
                $payloads[] = $payload;
            }
        }

        foreach ($payloads as $payload) {
            $metrics = is_array($payload['metrics'] ?? null) ? $payload['metrics'] : [];
            $value = $this->firstNumericMetric($metrics, $keys);
            if ($value === null) {
                $value = $this->firstNumericMetric($payload, $keys);
            }
            if ($value !== null) {
                return $value;
            }

            foreach ((array)($payload['facts'] ?? []) as $fact) {
                if (!is_array($fact)) {
                    continue;
                }
                $metricKey = strtolower(trim((string)($fact['metric_key'] ?? '')));
                if (!in_array($metricKey, array_map('strtolower', $keys), true)) {
                    continue;
                }
                $factValue = $fact['value'] ?? null;
                if (is_numeric($factValue)) {
                    return (float)$factValue;
                }
            }
        }

        return null;
    }

    /** @param array<int, mixed> $scores */
    private function scoresUseHundredPointScale(array $scores): bool
    {
        $scores = array_values(array_filter($scores, static fn($value): bool => is_numeric($value) && (float)$value > 0));
        if ($scores === []) {
            return false;
        }
        foreach ($scores as $score) {
            $score = (float)$score;
            if ($score <= 10 || $score > 100) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, mixed> $serviceQuality */
    private function serviceQualityThresholdEligible(array $serviceQuality): bool
    {
        if (array_key_exists('threshold_80_eligible', $serviceQuality)) {
            return $serviceQuality['threshold_80_eligible'] === true;
        }
        return $this->scoresUseHundredPointScale([
            $serviceQuality['avg_psi_score'] ?? null,
            $serviceQuality['avg_service_score'] ?? null,
        ]);
    }

    private function buildHoliday(string $date): array
    {
        $timezone = new DateTimeZone('Asia/Shanghai');
        $today = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone) ?: new DateTimeImmutable('today', $timezone);
        $holidays = [
            ['name' => '元旦', 'start_date' => '2026-01-01', 'end_date' => '2026-01-03'],
            ['name' => '春节', 'start_date' => '2026-02-15', 'end_date' => '2026-02-23'],
            ['name' => '清明节', 'start_date' => '2026-04-04', 'end_date' => '2026-04-06'],
            ['name' => '劳动节', 'start_date' => '2026-05-01', 'end_date' => '2026-05-05'],
            ['name' => '端午节', 'start_date' => '2026-06-19', 'end_date' => '2026-06-21'],
            ['name' => '中秋节', 'start_date' => '2026-09-25', 'end_date' => '2026-09-27'],
            ['name' => '国庆节', 'start_date' => '2026-10-01', 'end_date' => '2026-10-07'],
        ];

        foreach ($holidays as $holiday) {
            $end = DateTimeImmutable::createFromFormat('!Y-m-d', $holiday['end_date'], $timezone);
            if ($end >= $today) {
                $start = DateTimeImmutable::createFromFormat('!Y-m-d', $holiday['start_date'], $timezone);
                $daysLeft = $today < $start ? (int)$today->diff($start)->format('%a') : 0;
                return [
                    'next_holiday' => $holiday['name'],
                    'days_left' => $daysLeft,
                    'suggestion' => $daysLeft < 15 ? '节假日临近，建议检查库存、价格和活动节奏' : '保持常规监控',
                    'data_status' => self::DATA_OK,
                ];
            }
        }

        return [
            'next_holiday' => null,
            'days_left' => null,
            'suggestion' => self::DATA_PENDING,
            'data_status' => self::DATA_PENDING,
        ];
    }

    private function averageOnlineMetrics(array $hotelIds, string $date, int $days): array
    {
        $start = date('Y-m-d', strtotime($date . ' -' . $days . ' days'));
        $end = date('Y-m-d', strtotime($date . ' -1 day'));
        $rows = $this->latestOnlineFlowRows($this->onlineRows($hotelIds, $start, $end));
        if (empty($rows)) {
            return [];
        }

        $byDate = [];
        foreach ($rows as $row) {
            $day = (string)$row['data_date'];
            $metrics = $this->onlineFlowMetrics($row);
            $byDate[$day]['exposure'] = ($byDate[$day]['exposure'] ?? 0) + $metrics['exposure'];
            $byDate[$day]['visitors'] = ($byDate[$day]['visitors'] ?? 0) + $metrics['visitors'];
            $byDate[$day]['views'] = ($byDate[$day]['views'] ?? 0) + $metrics['views'];
            $byDate[$day]['orders'] = ($byDate[$day]['orders'] ?? 0) + $metrics['orders'];
        }

        $count = max(1, count($byDate));
        $sum = ['exposure' => 0, 'visitors' => 0, 'views' => 0, 'orders' => 0];
        foreach ($byDate as $metric) {
            foreach ($sum as $key => $value) {
                $sum[$key] += (float)($metric[$key] ?? 0);
            }
        }

        $exposure = $sum['exposure'] / $count;
        $visitors = $sum['visitors'] / $count;
        $views = $sum['views'] / $count;
        $orders = $sum['orders'] / $count;

        return [
            'exposure' => $exposure,
            'visitors' => $visitors,
            'views' => $views,
            'orders' => $orders,
            'view_rate' => $exposure > 0 ? $views / $exposure * 100 : 0,
            'order_rate' => $visitors > 0 ? $orders / $visitors * 100 : 0,
            'data_status' => $exposure > 0 && ($visitors > 0 || $views > 0) ? self::DATA_OK : 'partial',
        ];
    }

    private function baseline(array $hotelIds, int $days, ?string $endDate = null): array
    {
        $end = $endDate ? date('Y-m-d', strtotime($endDate . ' -1 day')) : date('Y-m-d');
        $start = date('Y-m-d', strtotime($end . ' -' . ($days - 1) . ' days'));
        $daily = $this->dailyReportRows($hotelIds, $start, $end);
        $onlineRows = $this->onlineRows($hotelIds, $start, $end);
        $dailyByDate = [];
        $onlineByDate = [];
        $dates = [];
        foreach ($daily as $row) {
            $date = substr(trim((string)($row['report_date'] ?? '')), 0, 10);
            if ($date !== '') {
                $dailyByDate[$date][] = $row;
                $dates[$date] = true;
            }
        }
        foreach ($onlineRows as $row) {
            $date = substr(trim((string)($row['data_date'] ?? '')), 0, 10);
            if ($date !== '') {
                $onlineByDate[$date][] = $row;
                $dates[$date] = true;
            }
        }

        $metricValues = ['orders' => [], 'revenue' => [], 'room_nights' => []];
        $sourceScopes = [];
        $incompleteDates = [];
        $actualDates = [];
        foreach (array_keys($dates) as $date) {
            $summary = $this->buildSummaryFromRows(
                $dailyByDate[$date] ?? [],
                $onlineByDate[$date] ?? [],
                $hotelIds,
                count($hotelIds) === 1 ? (int)$hotelIds[0] : null,
                $date
            );
            if (($summary['evidence_refs'] ?? []) === []) {
                continue;
            }
            $actualDates[$date] = true;
            $sourceScopes[(string)($summary['source_scope'] ?? 'unknown')] = true;
            if (($summary['data_status'] ?? '') !== self::DATA_OK) {
                $incompleteDates[] = $date;
            }
            foreach (array_keys($metricValues) as $metric) {
                if ($summary[$metric] !== null && is_numeric($summary[$metric])) {
                    $metricValues[$metric][] = (float)$summary[$metric];
                }
            }
        }

        $conversionValues = [];
        $flowByDate = [];
        foreach ($this->latestOnlineFlowRows($onlineRows) as $row) {
            $day = (string)($row['data_date'] ?? '');
            if ($day === '') {
                continue;
            }
            $metrics = $this->onlineFlowMetrics($row);
            $flowByDate[$day]['visitors'] = ($flowByDate[$day]['visitors'] ?? 0) + $metrics['visitors'];
            $flowByDate[$day]['orders'] = ($flowByDate[$day]['orders'] ?? 0) + $metrics['orders'];
        }
        foreach ($flowByDate as $metric) {
            $visitors = (float)($metric['visitors'] ?? 0);
            if ($visitors > 0) {
                $conversionValues[] = (float)($metric['orders'] ?? 0) / $visitors * 100;
            }
        }

        $count = count($actualDates);
        $dataGaps = [];
        foreach ([
            'orders' => ['baseline_orders_incomplete', '订单'],
            'revenue' => ['baseline_revenue_incomplete', '收入'],
            'room_nights' => ['baseline_room_nights_incomplete', '间夜'],
        ] as $metric => [$code, $label]) {
            if (count($metricValues[$metric]) < $count) {
                $dataGaps[] = [
                    'code' => $code,
                    'message' => $label . '仅覆盖 ' . count($metricValues[$metric]) . '/' . $count . ' 个有效日期',
                ];
            }
        }
        if ($incompleteDates !== []) {
            $dataGaps[] = [
                'code' => 'baseline_daily_summary_partial',
                'message' => count($incompleteDates) . ' 个日期存在必需字段或来源缺口',
            ];
        }

        return [
            'days' => $days,
            'actual_days' => $count,
            'avg_orders' => $metricValues['orders'] !== [] ? round(array_sum($metricValues['orders']) / count($metricValues['orders']), 2) : null,
            'avg_revenue' => $metricValues['revenue'] !== [] ? round(array_sum($metricValues['revenue']) / count($metricValues['revenue']), 2) : null,
            'avg_room_nights' => $metricValues['room_nights'] !== [] ? round(array_sum($metricValues['room_nights']) / count($metricValues['room_nights']), 2) : null,
            'avg_conversion' => $conversionValues !== [] ? round(array_sum($conversionValues) / count($conversionValues), 2) : null,
            'metric_sample_days' => [
                'orders' => count($metricValues['orders']),
                'revenue' => count($metricValues['revenue']),
                'room_nights' => count($metricValues['room_nights']),
                'conversion' => count($conversionValues),
            ],
            'source_scopes' => array_keys($sourceScopes),
            'data_gaps' => $dataGaps,
            'data_status' => $count === 0 ? 'missing' : ($dataGaps === [] ? self::DATA_OK : 'partial'),
        ];
    }

    private function dailyReportRows(array $hotelIds, string $startDate, string $endDate): array
    {
        if (!$this->tableExists('daily_reports') || empty($hotelIds)) {
            return [];
        }

        try {
            return Db::name('daily_reports')
                ->whereIn('hotel_id', $hotelIds)
                ->whereBetween('report_date', [$startDate, $endDate])
                ->select()
                ->toArray();
        } catch (Throwable $e) {
            return [];
        }
    }

    private function onlineRows(array $hotelIds, string $startDate, string $endDate): array
    {
        if (!$this->tableExists('online_daily_data')) {
            return [];
        }

        try {
            $query = Db::name('online_daily_data')->whereBetween('data_date', [$startDate, $endDate]);
            if (!empty($hotelIds)) {
                $query->whereIn('system_hotel_id', array_map('intval', $hotelIds));
            }
            return $query->select()->toArray();
        } catch (Throwable $e) {
            return [];
        }
    }

}
