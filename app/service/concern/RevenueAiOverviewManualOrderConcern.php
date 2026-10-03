<?php
declare(strict_types=1);

namespace app\service\concern;

use think\facade\Db;

/** Manual import readback stays separate from confirmed OTA revenue facts. */
trait RevenueAiOverviewManualOrderConcern
{
    /**
     * Read back only value-verified manual order aggregates. These rows remain
     * user-provided/unverified and are deliberately kept outside confirmed
     * room-revenue metrics and automated pricing readiness.
     *
     * @return array<string, mixed>
     */
    private function manualOrderImportSummary(string $businessDate, ?int $hotelId): array
    {
        $base = [
            'status' => 'no_data',
            'quality_status' => 'user_provided_unverified',
            'business_date' => $businessDate,
            'hotel_id' => $hotelId,
            'rows' => [],
            'summary' => [],
            'note' => '仅展示已保存并精确回读的人工携程订单聚合；参考底价不是确认收入。',
            'real_file_acceptance' => 'unverified',
        ];
        if ($hotelId === null || !$this->tableExists('online_daily_data')) {
            $base['status'] = 'not_loaded';
            return $base;
        }
        $columns = $this->tableColumns('online_daily_data');
        foreach (['system_hotel_id', 'source', 'data_date', 'data_type', 'ingestion_method', 'raw_data', 'readback_verified'] as $required) {
            if (!isset($columns[$required])) {
                $base['status'] = 'readback_contract_unavailable';
                $base['note'] = '人工订单导入缺少精确回读字段，当前不展示聚合结果。';
                return $base;
            }
        }
        $fields = array_values(array_filter([
            'id', 'system_hotel_id', 'source', 'data_date', 'data_type', 'ingestion_method',
            'validation_status', 'readback_verified', 'source_trace_id', 'raw_data',
        ], static fn(string $field): bool => isset($columns[$field])));
        try {
            $rows = Db::name('online_daily_data')
                ->field(implode(',', $fields))
                ->where('system_hotel_id', $hotelId)
                // online_daily_data.source is the authoritative OTA platform
                // identity; per-order sales channels live inside raw_data.
                ->where('source', 'ctrip')
                ->where('data_date', $businessDate)
                ->where('data_type', 'order')
                ->whereIn('ingestion_method', ['manual', 'import_excel', 'import_csv', 'import_json'])
                ->where('readback_verified', 1)
                ->order('id', 'asc')
                ->select()
                ->toArray();
        } catch (\Throwable) {
            $base['status'] = 'readback_query_failed';
            $base['note'] = '人工订单导入回读查询失败，当前不展示聚合结果。';
            return $base;
        }

        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $item = $this->manualOrderImportItem($row, $businessDate);
            if ($item !== null) {
                $items[] = $item;
            }
        }
        if ($items === []) {
            return $base;
        }

        $base['status'] = 'available_unverified';
        $base['rows'] = $items;
        $acceptanceStatuses = array_values(array_unique(array_map(
            static fn(array $item): string => (string)($item['real_file_acceptance'] ?? 'unverified'),
            $items
        )));
        $base['real_file_acceptance'] = count($acceptanceStatuses) === 1
            ? $acceptanceStatuses[0]
            : 'mixed_local_acceptance';
        $base['summary'] = [
            'row_count' => count($items),
            'active_orders' => $this->manualOrderMetricTotal($items, 'active_orders'),
            'cancelled_orders' => $this->manualOrderMetricTotal($items, 'cancelled_orders'),
            'room_nights' => $this->manualOrderMetricTotal($items, 'room_nights'),
            'readback_verified' => true,
        ];
        return $base;
    }
    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function manualOrderImportItem(array $row, string $businessDate): ?array
    {
        $stored = $this->jsonLikeArray($row['raw_data'] ?? []);
        $canonical = is_array($stored['row'] ?? null) ? $stored['row'] : [];
        $detail = is_array($canonical['raw_data'] ?? null) ? $canonical['raw_data'] : [];
        $importContract = (string)($detail['import_contract'] ?? '');
        if (strtolower(trim((string)($row['source'] ?? ''))) !== 'ctrip'
            || strtolower(trim((string)($canonical['platform'] ?? ''))) !== 'ctrip'
            || (string)($detail['amount_semantics'] ?? '') !== 'reference_bottom_price_not_confirmed_revenue'
            || !in_array($importContract, ['ctrip_order_aggregate_v1', 'ctrip_order_aggregate_v2'], true)
            || ($importContract === 'ctrip_order_aggregate_v2' && (string)($detail['record_kind'] ?? '') !== 'channel_daily_aggregate')
            || (string)($detail['pii_policy'] ?? '') !== 'aggregate_only_no_guest_staff_reservation_notes'
        ) {
            return null;
        }
        $channelKey = strtolower(trim((string)($detail['channel_key'] ?? $canonical['source'] ?? '')));
        if ($channelKey === '') {
            return null;
        }
        $sourceFormatValue = $detail['source_format'] ?? null;
        $sourceFormat = is_scalar($sourceFormatValue) ? trim((string)$sourceFormatValue) : null;
        if ($sourceFormat === '') {
            $sourceFormat = null;
        }

        return [
            'row_id' => max(0, (int)($row['id'] ?? 0)),
            'source' => 'ctrip_manual_order_import',
            'source_label' => '携程订单文件人工导入',
            'channel_key' => $channelKey,
            'channel_label' => trim((string)($detail['channel_label'] ?? $channelKey)),
            'business_date' => (string)($row['data_date'] ?? $businessDate),
            'business_date_basis' => (string)($detail['business_date_basis'] ?? 'stay_date'),
            'active_orders' => $this->numeric($canonical['book_order_num'] ?? $detail['active_order_num'] ?? null),
            'gross_orders' => $this->numeric($canonical['gross_order_num'] ?? $detail['gross_order_num'] ?? null),
            'cancelled_orders' => $this->numeric($canonical['cancel_order_num'] ?? $detail['cancel_order_num'] ?? null),
            'unknown_status_orders' => $this->numeric($canonical['unknown_status_order_num'] ?? $detail['unknown_status_order_num'] ?? null),
            'cancel_rate' => $this->numeric($canonical['cancel_rate'] ?? $detail['cancel_rate'] ?? null),
            'room_nights' => $this->numeric($canonical['quantity'] ?? $detail['room_nights'] ?? null),
            'average_booking_lead_days' => $this->numeric($canonical['avg_lead_days'] ?? $detail['average_booking_lead_days'] ?? null),
            'reference_bottom_price_total' => $this->numeric($canonical['amount'] ?? $detail['bottom_price_sum'] ?? null),
            'reference_bottom_price_adr' => $this->numeric($canonical['bottom_price_adr'] ?? $detail['bottom_price_adr'] ?? null),
            'reference_bottom_price_coverage_rate' => $this->numeric($detail['bottom_price_coverage_rate'] ?? null),
            'reference_bottom_price_completeness' => (string)($detail['bottom_price_completeness'] ?? 'unknown'),
            'amount_semantics' => 'reference_bottom_price_not_confirmed_revenue',
            'source_format' => $sourceFormat,
            'source_layout' => is_scalar($detail['source_layout'] ?? null) ? trim((string)$detail['source_layout']) ?: null : null,
            'source_file_count' => max(0, (int)($detail['source_file_count'] ?? 0)),
            'import_contract' => $importContract,
            'quality_status' => 'user_provided_unverified',
            'readback_verified' => true,
            'real_file_acceptance' => (string)($detail['fixture_status'] ?? '') === 'explicit_test_fixture'
                ? 'test_fixture_only'
                : ((string)($detail['file_layout_acceptance'] ?? '') === 'verified_25_column_layout'
                    ? 'local_25_column_layout_and_readback_verified'
                    : 'compatible_layout_readback_verified'),
        ];
    }

    /** Every displayed channel must supply the metric before its total is known. */
    private function manualOrderMetricTotal(array $items, string $metric): ?float
    {
        $total = 0.0;
        foreach ($items as $item) {
            $value = $this->numeric($item[$metric] ?? null);
            if ($value === null) {
                return null;
            }
            $total += $value;
        }
        return is_finite($total) ? $total : null;
    }
}
