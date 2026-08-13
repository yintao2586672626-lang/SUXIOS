<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use RuntimeException;
use think\facade\Db;

/**
 * Read-only analysis for value-readback-verified Ctrip manual order batches.
 *
 * A batch is never stitched together with another import task. V1 rows expose
 * only metrics that can be reproduced from the stored daily aggregates; V2
 * rows additionally expose distributions and classification receipts.
 */
final class CtripOrderAnalysisService
{
    private const CONTRACT_V1 = 'ctrip_order_aggregate_v1';
    private const CONTRACT_V2 = 'ctrip_order_aggregate_v2';
    private const MAX_RANGE_DAYS = 1096;
    private const MANUAL_METHODS = ['manual', 'import_excel', 'import_csv', 'import_json'];

    /** @return array<string, mixed> */
    public function analyzeStoredRange(
        int $systemHotelId,
        int $tenantId,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): array {
        if ($systemHotelId <= 0 || $tenantId <= 0) {
            throw new RuntimeException('订单分析缺少有效的酒店或租户范围。', 422);
        }
        [$dateFrom, $dateTo] = $this->validatedRange($dateFrom, $dateTo);

        $query = Db::name('online_daily_data')
            ->field(implode(',', [
                'id', 'tenant_id', 'system_hotel_id', 'data_source_id', 'sync_task_id',
                'source', 'platform', 'data_type', 'data_date', 'ingestion_method',
                'readback_verified', 'raw_data', 'create_time', 'update_time',
            ]))
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $systemHotelId)
            ->where('source', 'ctrip')
            ->where('data_type', 'order')
            ->whereIn('ingestion_method', self::MANUAL_METHODS);
        try {
            $storedRows = $query->order('id', 'asc')->select()->toArray();
        } catch (\Throwable $error) {
            throw new RuntimeException('携程订单分析回读失败。', 500, $error);
        }

        $batches = [];
        foreach ($storedRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $candidate = $this->candidate($row);
            if ($candidate === null) {
                continue;
            }
            $taskId = max(0, (int)($row['sync_task_id'] ?? 0));
            $sourceId = max(0, (int)($row['data_source_id'] ?? 0));
            $batchKey = $taskId > 0
                ? 'task:' . $taskId
                : 'legacy-source:' . $sourceId;
            if (!isset($batches[$batchKey])) {
                $batches[$batchKey] = [
                    'task_id' => $taskId,
                    'source_id' => $sourceId,
                    'max_row_id' => 0,
                    'all_readback_verified' => true,
                    'intersects_requested_range' => false,
                    'rows' => [],
                ];
            }
            $batches[$batchKey]['max_row_id'] = max(
                (int)$batches[$batchKey]['max_row_id'],
                max(0, (int)($row['id'] ?? 0))
            );
            $batches[$batchKey]['all_readback_verified'] = $batches[$batchKey]['all_readback_verified']
                && (int)($row['readback_verified'] ?? 0) === 1;
            $candidateDate = (string)$candidate['date'];
            $batches[$batchKey]['intersects_requested_range'] = $batches[$batchKey]['intersects_requested_range']
                || $dateFrom === null
                || ($candidateDate >= $dateFrom && $candidateDate <= (string)$dateTo);
            $batches[$batchKey]['rows'][] = $row;
        }

        if ($batches === []) {
            return $this->noData($systemHotelId, $dateFrom, $dateTo);
        }
        usort($batches, static fn(array $left, array $right): int => (
            ((int)$right['max_row_id'] <=> (int)$left['max_row_id'])
            ?: ((int)$right['task_id'] <=> (int)$left['task_id'])
        ));
        $selected = null;
        foreach ($batches as $batch) {
            if (($batch['intersects_requested_range'] ?? false) !== true) {
                continue;
            }
            if (($batch['all_readback_verified'] ?? false) === true && ($batch['rows'] ?? []) !== []) {
                $selected = $batch;
                break;
            }
        }
        if (!is_array($selected)) {
            $hasRangeIntersection = count(array_filter(
                $batches,
                static fn(array $batch): bool => ($batch['intersects_requested_range'] ?? false) === true
            )) > 0;
            if (!$hasRangeIntersection) {
                return $this->noData($systemHotelId, $dateFrom, $dateTo);
            }
            return $this->indeterminate(
                $systemHotelId,
                $dateFrom,
                $dateTo,
                '订单批次尚未完成值级精确回读，不能生成分析。'
            );
        }

        $analysis = $this->analyzeRows(
            (array)$selected['rows'],
            $systemHotelId,
            $dateFrom,
            $dateTo
        );
        $analysis['batch']['sync_task_id'] = (int)$selected['task_id'];
        $analysis['batch']['data_source_id'] = (int)$selected['source_id'];
        $analysis['batch']['selection_policy'] = 'latest_single_readback_verified_import_batch_no_cross_batch_stitching';
        return $analysis;
    }

    /**
     * Analyze exact-readback canonical rows or stored online_daily_data rows.
     *
     * @param array<int, mixed> $rows
     * @return array<string, mixed>
     */
    public function analyzeRows(
        array $rows,
        ?int $systemHotelId = null,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): array {
        [$dateFrom, $dateTo] = $this->validatedRange($dateFrom, $dateTo);
        $candidates = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $candidate = $this->candidate($row);
            if ($candidate === null) {
                continue;
            }
            $rowHotelId = max(0, (int)($candidate['canonical']['system_hotel_id'] ?? $row['system_hotel_id'] ?? 0));
            if ($systemHotelId !== null && $systemHotelId > 0 && $rowHotelId !== $systemHotelId) {
                continue;
            }
            $date = (string)$candidate['date'];
            if ($dateFrom !== null && $dateTo !== null && ($date < $dateFrom || $date > $dateTo)) {
                continue;
            }
            if (!$candidate['readback_verified']) {
                return $this->indeterminate(
                    $systemHotelId ?? $rowHotelId,
                    $dateFrom,
                    $dateTo,
                    '订单分析包含未完成值级回读的记录。'
                );
            }
            $candidates[] = $candidate;
            $systemHotelId ??= $rowHotelId > 0 ? $rowHotelId : null;
        }
        if ($candidates === []) {
            return $this->noData($systemHotelId ?? 0, $dateFrom, $dateTo);
        }

        $contracts = array_values(array_unique(array_column($candidates, 'contract')));
        if (count($contracts) !== 1) {
            return $this->indeterminate(
                $systemHotelId ?? 0,
                $dateFrom,
                $dateTo,
                '同一订单批次混入多个导入契约，已停止合并。'
            );
        }
        $contract = (string)$contracts[0];
        $datasetHashes = [];
        if ($contract === self::CONTRACT_V2) {
            foreach ($candidates as $candidate) {
                $hash = trim((string)($candidate['detail']['dataset_receipt']['dataset_hash'] ?? ''));
                if ($hash !== '') {
                    $datasetHashes[$hash] = true;
                }
            }
            if (count($datasetHashes) !== 1) {
                return $this->indeterminate(
                    $systemHotelId ?? 0,
                    $dateFrom,
                    $dateTo,
                    'V2 订单批次的数据集哈希缺失或不一致，已停止分析。'
                );
            }
        }

        $channels = [];
        $dates = [];
        $hotelName = '';
        $classificationAvailable = $contract === self::CONTRACT_V2;
        $distributionsAvailable = $contract === self::CONTRACT_V2;
        $roomTypesAvailable = $contract === self::CONTRACT_V2;
        $losBuckets = [];
        $leadBuckets = [];
        $roomTypes = [];
        $classification = [
            'status' => $classificationAvailable ? 'available' : 'evidence_missing',
            'stayed_orders' => 0,
            'active_not_stayed_orders' => 0,
            'cancelled_orders' => 0,
            'unknown_status_orders' => 0,
            'status_family_counts' => [],
            'status_label_counts' => [],
            'order_type_counts' => [],
        ];

        foreach ($candidates as $candidate) {
            $canonical = $candidate['canonical'];
            $detail = $candidate['detail'];
            $date = (string)$candidate['date'];
            $dates[$date] = true;
            if ($hotelName === '') {
                $hotelName = trim((string)($canonical['hotel_name'] ?? ''));
            }
            $channelKey = strtolower(trim((string)($detail['channel_key'] ?? $canonical['source'] ?? '')));
            if ($channelKey === '') {
                $channelKey = 'ctrip_family_unspecified';
            }
            if (!isset($channels[$channelKey])) {
                $channels[$channelKey] = $this->emptyAccumulator(
                    $channelKey,
                    trim((string)($detail['channel_label'] ?? $channelKey))
                );
            }
            $this->addCandidate($channels[$channelKey], $canonical, $detail, $contract);

            if ($contract !== self::CONTRACT_V2) {
                continue;
            }
            $receipt = is_array($detail['classification_receipt'] ?? null)
                ? $detail['classification_receipt']
                : null;
            $los = is_array($detail['los_distribution'] ?? null) ? $detail['los_distribution'] : null;
            $lead = is_array($detail['lead_time_distribution'] ?? null) ? $detail['lead_time_distribution'] : null;
            $roomRows = is_array($detail['room_type_metrics'] ?? null) ? $detail['room_type_metrics'] : null;
            if ($receipt === null) {
                $classificationAvailable = false;
            } else {
                $classification['stayed_orders'] += max(0, (int)($receipt['stayed_order_num'] ?? 0));
                $classification['active_not_stayed_orders'] += max(0, (int)($receipt['active_not_stayed_order_num'] ?? 0));
                $this->mergeCounts($classification['status_family_counts'], $receipt['status_family_counts'] ?? []);
                $this->mergeCounts($classification['status_label_counts'], $receipt['status_label_counts'] ?? []);
                $this->mergeCounts($classification['order_type_counts'], $receipt['order_type_counts'] ?? []);
            }
            if ($los === null || !is_array($los['buckets'] ?? null)) {
                $distributionsAvailable = false;
            } else {
                $this->mergeDistribution($losBuckets, $los['buckets']);
            }
            if ($lead === null || !is_array($lead['buckets'] ?? null)) {
                $distributionsAvailable = false;
            } else {
                $this->mergeDistribution($leadBuckets, $lead['buckets']);
            }
            if ($roomRows === null || ($detail['room_type_metrics_truncated'] ?? false) === true) {
                $roomTypesAvailable = false;
            } else {
                $this->mergeRoomTypes($roomTypes, $roomRows);
            }
        }

        $channelRows = [];
        $summaryAccumulator = $this->emptyAccumulator('all', '全部携程系渠道');
        foreach ($channels as $channel) {
            $channelRows[] = $this->finalizeAccumulator($channel);
            $this->mergeAccumulators($summaryAccumulator, $channel);
        }
        usort($channelRows, static fn(array $left, array $right): int => (
            ((float)($right['active_orders'] ?? -1) <=> (float)($left['active_orders'] ?? -1))
            ?: strcmp((string)$left['label'], (string)$right['label'])
        ));
        $summary = $this->finalizeAccumulator($summaryAccumulator);
        unset($summary['key'], $summary['label']);

        if ($classificationAvailable) {
            $classification['status'] = 'available';
            $classification['cancelled_orders'] = $summary['cancelled_orders'];
            $classification['unknown_status_orders'] = $summary['unknown_status_orders'];
            ksort($classification['status_family_counts']);
            ksort($classification['status_label_counts']);
            ksort($classification['order_type_counts']);
            $summary['stayed_orders'] = $classification['stayed_orders'];
        } else {
            $classification = [
                'status' => 'evidence_missing',
                'stayed_orders' => null,
                'active_not_stayed_orders' => null,
                'cancelled_orders' => $summary['cancelled_orders'],
                'unknown_status_orders' => $summary['unknown_status_orders'],
                'reason' => 'V1 聚合未保存逐状态分类回执。',
            ];
            $summary['stayed_orders'] = null;
        }

        $missingDimensions = [];
        if (!$classificationAvailable) {
            $missingDimensions[] = $this->missingDimension('status_classification', '已入住与状态分类', '旧聚合未保存逐状态计数');
        }
        if (!$distributionsAvailable) {
            $missingDimensions[] = $this->missingDimension('los_distribution', '连住分布', '旧聚合仅保存平均连住与单晚占比');
            $missingDimensions[] = $this->missingDimension('lead_time_distribution', '提前预订分布', '旧聚合仅保存平均提前天数');
        }
        if (!$roomTypesAvailable) {
            $missingDimensions[] = $this->missingDimension('room_type_metrics', '完整房型表现', '旧聚合仅保存每日 Top5，无法恢复完整排名');
        }

        $exclusions = $this->exclusionResult($contract, $candidates[0]['detail']);
        if ($exclusions['status'] !== 'available') {
            $missingDimensions[] = $this->missingDimension(
                'exclusion_receipt',
                '扫码单与关房记录排除',
                '缺少经核验的精确源字段和值，未应用猜测规则',
                '重新上传原始携程 XLS，并确认精确源字段和值后再应用排除规则'
            );
        }
        $dateList = array_keys($dates);
        sort($dateList);
        $datasetReceipt = $contract === self::CONTRACT_V2
            && is_array($candidates[0]['detail']['dataset_receipt'] ?? null)
                ? $candidates[0]['detail']['dataset_receipt']
                : null;

        return [
            'status' => $missingDimensions === [] ? 'available_unverified' : 'available_partial',
            'quality_status' => 'user_provided_unverified',
            'quality_label' => '人工文件导入 / 来源待核验',
            'persistence_readback_status' => 'verified',
            'metric_scope' => 'ota_channel',
            'hotel' => ['id' => $systemHotelId ?? 0, 'name' => $hotelName],
            'source' => ['platform' => 'ctrip', 'method' => 'user_provided_unverified'],
            'date_range' => [
                'from' => $dateList[0] ?? null,
                'to' => $dateList !== [] ? $dateList[count($dateList) - 1] : null,
                'requested_from' => $dateFrom,
                'requested_to' => $dateTo,
                'basis' => 'stay_date_with_booking_date_fallback',
            ],
            'batch' => [
                'sync_task_id' => 0,
                'data_source_id' => 0,
                'import_contract' => $contract,
                'dataset_hash' => $contract === self::CONTRACT_V2 ? array_key_first($datasetHashes) : null,
                'row_count' => count($candidates),
                'dataset_receipt' => $datasetReceipt,
            ],
            'summary' => $summary,
            'channels' => $channelRows,
            'classification' => $classification,
            'exclusions' => $exclusions,
            'distributions' => [
                'los' => $distributionsAvailable
                    ? ['status' => 'available', 'buckets' => array_values($losBuckets)]
                    : ['status' => 'evidence_missing', 'buckets' => [], 'reason' => '原始分布未保存'],
                'lead_time' => $distributionsAvailable
                    ? ['status' => 'available', 'buckets' => array_values($leadBuckets)]
                    : ['status' => 'evidence_missing', 'buckets' => [], 'reason' => '原始分布未保存'],
            ],
            'room_types' => $roomTypesAvailable
                ? ['status' => 'available', 'rows' => $this->finalizeRoomTypes($roomTypes)]
                : ['status' => 'evidence_missing', 'rows' => [], 'reason' => '完整房型聚合不可恢复'],
            'missing_dimensions' => $missingDimensions,
            'amount_semantics' => 'reference_bottom_price_not_confirmed_revenue',
            'note' => '只分析已保存并完成值级回读的单一人工导入批次；不会拼接其他批次。',
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }

    /** @return array<string, mixed>|null */
    private function candidate(array $row): ?array
    {
        $stored = $this->decodeArray($row['raw_data'] ?? []);
        $canonical = is_array($stored['row'] ?? null) ? $stored['row'] : $row;
        $detail = $this->decodeArray($canonical['raw_data'] ?? []);
        $contract = trim((string)($detail['import_contract'] ?? ''));
        if (!in_array($contract, [self::CONTRACT_V1, self::CONTRACT_V2], true)
            || strtolower(trim((string)($canonical['platform'] ?? ''))) !== 'ctrip'
            || strtolower(trim((string)($canonical['data_type'] ?? ''))) !== 'order'
            || (string)($detail['amount_semantics'] ?? '') !== 'reference_bottom_price_not_confirmed_revenue'
            || (string)($detail['pii_policy'] ?? '') !== 'aggregate_only_no_guest_staff_reservation_notes'
            || ($contract === self::CONTRACT_V2 && (string)($detail['record_kind'] ?? '') !== 'channel_daily_aggregate')
        ) {
            return null;
        }
        $outerSource = strtolower(trim((string)($row['source'] ?? '')));
        if ($stored !== [] && isset($stored['row']) && $outerSource !== '' && $outerSource !== 'ctrip') {
            return null;
        }
        $date = trim((string)($canonical['data_date'] ?? $row['data_date'] ?? ''));
        if (!$this->validDate($date)) {
            return null;
        }
        $readbackVerified = array_key_exists('readback_verified', $row)
            ? (int)$row['readback_verified'] === 1
            : (($canonical['_readback_verified'] ?? false) === true);
        return [
            'canonical' => $canonical,
            'detail' => $detail,
            'contract' => $contract,
            'date' => $date,
            'readback_verified' => $readbackVerified,
        ];
    }

    /** @return array<string, mixed> */
    private function emptyAccumulator(string $key, string $label): array
    {
        return [
            'key' => $key,
            'label' => $label !== '' ? $label : $key,
            'gross_orders' => 0.0,
            'active_orders' => 0.0,
            'cancelled_orders' => 0.0,
            'unknown_status_orders' => 0.0,
            'room_nights' => 0.0,
            'reference_bottom_price_total' => 0.0,
            'bottom_price_value_seen' => false,
            'bottom_price_room_nights' => 0.0,
            'bottom_price_valid_orders' => 0.0,
            'los_weighted_sum' => 0.0,
            'los_weight' => 0.0,
            'single_night_weighted_sum' => 0.0,
            'single_night_weight' => 0.0,
            'lead_weighted_sum' => 0.0,
            'lead_weight' => 0.0,
            'core_complete' => true,
        ];
    }

    /** @param array<string, mixed> $accumulator @param array<string, mixed> $canonical @param array<string, mixed> $detail */
    private function addCandidate(array &$accumulator, array $canonical, array $detail, string $contract): void
    {
        foreach ([
            'gross_orders' => $canonical['gross_order_num'] ?? $detail['gross_order_num'] ?? null,
            'active_orders' => $canonical['book_order_num'] ?? $detail['active_order_num'] ?? null,
            'cancelled_orders' => $canonical['cancel_order_num'] ?? $detail['cancel_order_num'] ?? null,
            'unknown_status_orders' => $canonical['unknown_status_order_num'] ?? $detail['unknown_status_order_num'] ?? null,
            'room_nights' => $canonical['quantity'] ?? $detail['room_nights'] ?? null,
        ] as $field => $value) {
            $number = $this->number($value);
            if ($number === null) {
                $accumulator['core_complete'] = false;
            } else {
                $accumulator[$field] += $number;
            }
        }
        $bottomPrice = $this->number($detail['bottom_price_sum'] ?? null);
        if ($bottomPrice !== null) {
            $accumulator['reference_bottom_price_total'] += $bottomPrice;
            $accumulator['bottom_price_value_seen'] = true;
        }
        $bottomPriceNights = $this->number($detail['bottom_price_room_nights'] ?? null);
        if ($bottomPriceNights === null && $bottomPrice !== null) {
            $rowBottomPriceAdr = $this->number($canonical['bottom_price_adr'] ?? $detail['bottom_price_adr'] ?? null);
            if ($rowBottomPriceAdr !== null && $rowBottomPriceAdr > 0) {
                $bottomPriceNights = $bottomPrice / $rowBottomPriceAdr;
            }
        }
        if ($bottomPriceNights !== null) {
            $accumulator['bottom_price_room_nights'] += $bottomPriceNights;
        }
        $bottomValidOrders = $this->number($detail['bottom_price_valid_order_count'] ?? null);
        if ($bottomValidOrders !== null) {
            $accumulator['bottom_price_valid_orders'] += $bottomValidOrders;
        }
        $activeOrders = $this->number($canonical['book_order_num'] ?? $detail['active_order_num'] ?? null);
        $losWeight = $contract === self::CONTRACT_V2
            ? $this->number($detail['los_distribution']['valid_order_count'] ?? null)
            : $activeOrders;
        $leadWeight = $contract === self::CONTRACT_V2
            ? $this->number($detail['lead_time_distribution']['valid_order_count'] ?? null)
            : $activeOrders;
        $averageLos = $this->number($canonical['avg_los'] ?? $detail['average_los'] ?? null);
        if ($averageLos !== null && $losWeight !== null && $losWeight > 0) {
            $accumulator['los_weighted_sum'] += $averageLos * $losWeight;
            $accumulator['los_weight'] += $losWeight;
        }
        $singleNightRate = $this->number($detail['single_night_rate'] ?? null);
        if ($singleNightRate !== null && $losWeight !== null && $losWeight > 0) {
            $accumulator['single_night_weighted_sum'] += $singleNightRate * $losWeight;
            $accumulator['single_night_weight'] += $losWeight;
        }
        $averageLead = $this->number($canonical['avg_lead_days'] ?? $detail['average_booking_lead_days'] ?? null);
        if ($averageLead !== null && $leadWeight !== null && $leadWeight > 0) {
            $accumulator['lead_weighted_sum'] += $averageLead * $leadWeight;
            $accumulator['lead_weight'] += $leadWeight;
        }
    }

    /** @param array<string, mixed> $target @param array<string, mixed> $source */
    private function mergeAccumulators(array &$target, array $source): void
    {
        foreach ([
            'gross_orders', 'active_orders', 'cancelled_orders', 'unknown_status_orders', 'room_nights',
            'reference_bottom_price_total', 'bottom_price_room_nights', 'bottom_price_valid_orders',
            'los_weighted_sum', 'los_weight', 'single_night_weighted_sum', 'single_night_weight',
            'lead_weighted_sum', 'lead_weight',
        ] as $field) {
            $target[$field] += (float)($source[$field] ?? 0);
        }
        $target['bottom_price_value_seen'] = $target['bottom_price_value_seen'] || ($source['bottom_price_value_seen'] ?? false);
        $target['core_complete'] = $target['core_complete'] && ($source['core_complete'] ?? false);
    }

    /** @param array<string, mixed> $accumulator @return array<string, mixed> */
    private function finalizeAccumulator(array $accumulator): array
    {
        $gross = $accumulator['core_complete'] ? $accumulator['gross_orders'] : null;
        $active = $accumulator['core_complete'] ? $accumulator['active_orders'] : null;
        $cancelled = $accumulator['core_complete'] ? $accumulator['cancelled_orders'] : null;
        $unknown = $accumulator['core_complete'] ? $accumulator['unknown_status_orders'] : null;
        return [
            'key' => $accumulator['key'],
            'label' => $accumulator['label'],
            'gross_orders' => $this->intIfWhole($gross),
            'active_orders' => $this->intIfWhole($active),
            'cancelled_orders' => $this->intIfWhole($cancelled),
            'unknown_status_orders' => $this->intIfWhole($unknown),
            'cancel_rate' => $gross !== null && $gross > 0 && $cancelled !== null && $unknown === 0.0
                ? $cancelled / $gross
                : null,
            'room_nights' => $accumulator['core_complete'] ? $accumulator['room_nights'] : null,
            'reference_bottom_price_total' => $accumulator['bottom_price_value_seen']
                ? $accumulator['reference_bottom_price_total']
                : null,
            'reference_bottom_price_adr' => $accumulator['bottom_price_value_seen']
                && $accumulator['bottom_price_room_nights'] > 0
                    ? $accumulator['reference_bottom_price_total'] / $accumulator['bottom_price_room_nights']
                    : null,
            'reference_bottom_price_coverage_rate' => $active !== null && $active > 0
                ? $accumulator['bottom_price_valid_orders'] / $active
                : null,
            'average_los' => $accumulator['los_weight'] > 0
                ? $accumulator['los_weighted_sum'] / $accumulator['los_weight']
                : null,
            'single_night_rate' => $accumulator['single_night_weight'] > 0
                ? $accumulator['single_night_weighted_sum'] / $accumulator['single_night_weight']
                : null,
            'average_booking_lead_days' => $accumulator['lead_weight'] > 0
                ? $accumulator['lead_weighted_sum'] / $accumulator['lead_weight']
                : null,
            'amount' => null,
            'amount_semantics' => 'reference_bottom_price_not_confirmed_revenue',
        ];
    }

    /** @param array<string, int> $target */
    private function mergeCounts(array &$target, mixed $source): void
    {
        if (!is_array($source)) {
            return;
        }
        foreach ($source as $key => $count) {
            if (!is_scalar($key) || !is_numeric($count)) {
                continue;
            }
            $label = mb_substr(trim((string)$key), 0, 120, 'UTF-8');
            if ($label !== '') {
                $target[$label] = ($target[$label] ?? 0) + max(0, (int)$count);
            }
        }
    }

    /** @param array<string, array<string, mixed>> $target @param array<int, mixed> $rows */
    private function mergeDistribution(array &$target, array $rows): void
    {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = trim((string)($row['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            if (!isset($target[$key])) {
                $target[$key] = [
                    'key' => $key,
                    'label' => trim((string)($row['label'] ?? $key)),
                    'orders' => 0,
                ];
            }
            $target[$key]['orders'] += max(0, (int)($row['orders'] ?? 0));
        }
    }

    /** @param array<string, array<string, mixed>> $target @param array<int, mixed> $rows */
    private function mergeRoomTypes(array &$target, array $rows): void
    {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = mb_substr(trim((string)($row['name'] ?? '')), 0, 120, 'UTF-8');
            if ($name === '') {
                continue;
            }
            if (!isset($target[$name])) {
                $target[$name] = [
                    'name' => $name,
                    'active_orders' => 0.0,
                    'room_nights' => 0.0,
                    'reference_bottom_price_total' => 0.0,
                    'bottom_price_value_seen' => false,
                    'bottom_price_room_nights' => 0.0,
                ];
            }
            $target[$name]['active_orders'] += max(0.0, (float)($row['active_orders'] ?? 0));
            $target[$name]['room_nights'] += max(0.0, (float)($row['room_nights'] ?? 0));
            $bottom = $this->number($row['reference_bottom_price_total'] ?? null);
            if ($bottom !== null) {
                $target[$name]['reference_bottom_price_total'] += $bottom;
                $target[$name]['bottom_price_value_seen'] = true;
            }
            $bottomPriceRoomNights = $this->number($row['bottom_price_room_nights'] ?? null);
            if ($bottomPriceRoomNights !== null && $bottomPriceRoomNights > 0) {
                $target[$name]['bottom_price_room_nights'] += $bottomPriceRoomNights;
            } else {
                $adr = $this->number($row['reference_bottom_price_adr'] ?? null);
                if ($bottom !== null && $adr !== null && $adr > 0) {
                    $target[$name]['bottom_price_room_nights'] += $bottom / $adr;
                }
            }
        }
    }

    /** @param array<string, array<string, mixed>> $rows @return array<int, array<string, mixed>> */
    private function finalizeRoomTypes(array $rows): array
    {
        $result = array_values(array_map(static function (array $row): array {
            $row['active_orders'] = (int)round((float)$row['active_orders']);
            $row['reference_bottom_price_total'] = $row['bottom_price_value_seen']
                ? (float)$row['reference_bottom_price_total']
                : null;
            $row['reference_bottom_price_adr'] = $row['bottom_price_value_seen']
                && $row['bottom_price_room_nights'] > 0
                    ? $row['reference_bottom_price_total'] / $row['bottom_price_room_nights']
                    : null;
            unset($row['bottom_price_value_seen'], $row['bottom_price_room_nights']);
            return $row;
        }, $rows));
        usort($result, static fn(array $left, array $right): int => (
            ((int)$right['active_orders'] <=> (int)$left['active_orders'])
            ?: strcmp((string)$left['name'], (string)$right['name'])
        ));
        return array_slice($result, 0, 50);
    }

    /** @param array<string, mixed> $detail @return array<string, mixed> */
    private function exclusionResult(string $contract, array $detail): array
    {
        if ($contract !== self::CONTRACT_V2 || !is_array($detail['exclusion_receipt'] ?? null)) {
            return [
                'status' => 'evidence_missing',
                'excluded_order_count' => null,
                'reason_counts' => [],
                'reason' => 'V1 聚合没有排除规则回执。',
            ];
        }
        $receipt = $detail['exclusion_receipt'];
        $receiptStatus = (string)($receipt['status'] ?? 'evidence_missing');
        return [
            'status' => $receiptStatus === 'applied_verified' ? 'available' : 'evidence_missing',
            'policy_status' => $receiptStatus,
            'policy_version' => (string)($receipt['policy_version'] ?? ''),
            'excluded_order_count' => max(0, (int)($receipt['excluded_order_count'] ?? 0)),
            'reason_counts' => is_array($receipt['reason_counts'] ?? null) ? $receipt['reason_counts'] : [],
            'reason' => $receiptStatus === 'applied_verified'
                ? ''
                : '缺少可验证的扫码单/关房记录精确字段规则，未应用猜测排除。',
        ];
    }

    /** @return array<string, string> */
    private function missingDimension(
        string $key,
        string $label,
        string $reason,
        string $nextAction = '重新上传同一门店的原始携程 XLS 后自动补算'
    ): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'reason' => $reason,
            'next_action' => $nextAction,
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
            throw new RuntimeException('订单分析开始日期和结束日期需要同时填写。', 422);
        }
        if ($dateFrom === null) {
            return [null, null];
        }
        if (!$this->validDate($dateFrom) || !$this->validDate((string)$dateTo) || $dateFrom > $dateTo) {
            throw new RuntimeException('订单分析日期范围无效。', 422);
        }
        $days = (new DateTimeImmutable($dateFrom))->diff(new DateTimeImmutable((string)$dateTo))->days;
        if (!is_int($days) || $days + 1 > self::MAX_RANGE_DAYS) {
            throw new RuntimeException('订单分析日期范围最多为 1096 天。', 422);
        }
        return [$dateFrom, $dateTo];
    }

    private function validDate(string $date): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1) {
            return false;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $date;
    }

    /** @return array<string, mixed> */
    private function noData(int $hotelId, ?string $dateFrom, ?string $dateTo): array
    {
        return [
            'status' => 'no_data',
            'quality_status' => 'data_missing',
            'persistence_readback_status' => 'not_available',
            'metric_scope' => 'ota_channel',
            'hotel' => ['id' => $hotelId, 'name' => ''],
            'date_range' => ['from' => null, 'to' => null, 'requested_from' => $dateFrom, 'requested_to' => $dateTo],
            'batch' => ['sync_task_id' => 0, 'data_source_id' => 0, 'import_contract' => null, 'dataset_hash' => null, 'row_count' => 0],
            'summary' => [],
            'channels' => [],
            'classification' => ['status' => 'evidence_missing'],
            'exclusions' => ['status' => 'evidence_missing'],
            'distributions' => [
                'los' => ['status' => 'evidence_missing', 'buckets' => []],
                'lead_time' => ['status' => 'evidence_missing', 'buckets' => []],
            ],
            'room_types' => ['status' => 'evidence_missing', 'rows' => []],
            'missing_dimensions' => [],
            'amount_semantics' => 'reference_bottom_price_not_confirmed_revenue',
            'note' => '当前酒店和日期范围没有可用的携程人工订单回读。',
        ];
    }

    /** @return array<string, mixed> */
    private function indeterminate(
        int $hotelId,
        ?string $dateFrom,
        ?string $dateTo,
        string $reason
    ): array {
        $result = $this->noData($hotelId, $dateFrom, $dateTo);
        $result['status'] = 'indeterminate';
        $result['quality_status'] = 'indeterminate';
        $result['note'] = $reason;
        return $result;
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

    private function number(mixed $value): ?float
    {
        return $value !== null && $value !== '' && is_numeric($value) ? (float)$value : null;
    }

    private function intIfWhole(?float $value): int|float|null
    {
        if ($value === null) {
            return null;
        }
        return abs($value - round($value)) <= 0.000001 ? (int)round($value) : $value;
    }
}
