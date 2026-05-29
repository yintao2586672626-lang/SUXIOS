<?php
declare(strict_types=1);

namespace app\service;

use app\contract\DataSourceAdapter;
use app\service\platform\ApiDataSourceAdapter;
use app\service\platform\ManualImportDataSourceAdapter;
use RuntimeException;
use think\facade\Db;

final class PlatformDataSyncService
{
    /** @var array<int, DataSourceAdapter> */
    private array $adapters;

    /** @var array<string, array<string, bool>> */
    private array $columns = [];

    /**
     * @param array<int, DataSourceAdapter>|null $adapters
     */
    public function __construct(?array $adapters = null)
    {
        $this->adapters = $adapters ?? [
            new ManualImportDataSourceAdapter(),
            new ApiDataSourceAdapter(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function normalizeRowsFromPayload(array $payload, array $source, ?int $syncTaskId = null): array
    {
        $rows = $this->extractBusinessRows($payload);
        if (empty($rows)) {
            return [];
        }

        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $date = $this->normalizeDate(
                $row['data_date']
                    ?? $row['dataDate']
                    ?? $row['date']
                    ?? $row['stat_date']
                    ?? $row['statDate']
                    ?? $row['biz_date']
                    ?? $row['bizDate']
                    ?? $row['orderDate']
                    ?? $row['createTime']
                    ?? $payload['data_date']
                    ?? $payload['dataDate']
                    ?? null
            );
            if ($date === null) {
                continue;
            }

            $platform = strtolower((string)($source['platform'] ?? $row['source'] ?? 'custom'));
            $dataType = $this->normalizeDataType((string)($source['data_type'] ?? $row['data_type'] ?? 'business'));
            if ($this->isCommentDataType($dataType)) {
                continue;
            }
            $traceId = $this->buildTraceId($source, $row, $date, $syncTaskId);
            $sanitizedRow = $this->sanitizePayloadForStorage($row, $dataType);
            $raw = [
                'row' => $sanitizedRow,
                'data_source_id' => $source['id'] ?? null,
                'data_source_name' => $source['name'] ?? '',
                'sync_task_id' => $syncTaskId,
                'source_trace_id' => $traceId,
                'ingested_at' => date('Y-m-d H:i:s'),
            ];

            $normalized[] = [
                'hotel_id' => $this->stringValue($row, ['hotel_id', 'hotelId', 'poi_id', 'poiId', 'external_hotel_id']) ?: (string)($source['external_hotel_id'] ?? ''),
                'hotel_name' => $this->stringValue($row, ['hotel_name', 'hotelName', 'poi_name', 'poiName', 'name']) ?: (string)($source['hotel_name'] ?? $source['name'] ?? ''),
                'data_date' => $date,
                'amount' => $this->amountValue($row, $dataType),
                'quantity' => $this->quantityValue($row, $dataType),
                'book_order_num' => $this->orderCountValue($row, $dataType),
                'comment_score' => $this->numericValue($row, ['comment_score', 'rating', 'score']),
                'qunar_comment_score' => $this->numericValue($row, ['qunar_comment_score', 'qunar_score']),
                'raw_data' => json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'system_hotel_id' => (int)($source['system_hotel_id'] ?? $row['system_hotel_id'] ?? 0) ?: null,
                'data_value' => $this->dataValue($row, $dataType),
                'source' => $platform,
                'dimension' => $this->stringValue($row, ['dimension', 'dim_name', '_dimName']),
                'data_type' => $dataType,
                'platform' => $this->stringValue($row, ['platform']) ?: $platform,
                'compare_type' => $this->stringValue($row, ['compare_type', 'compareType']),
                'list_exposure' => (int)$this->numericValue($row, ['list_exposure', 'listExposure', 'impressions', 'exposure_count', 'exposureCount']),
                'detail_exposure' => (int)$this->numericValue($row, ['detail_exposure', 'detailExposure', 'clicks', 'click_count', 'clickCount', 'visitors', 'visitorTotal', 'pv', 'uv']),
                'flow_rate' => $this->numericValue($row, ['flow_rate', 'flowRate', 'cvr', 'ctr', 'conversion_rate', 'conversionRate', 'convertionRate', 'avgConversionsRate', 'orderConversionRate', 'dealRate']),
                'order_filling_num' => (int)$this->numericValue($row, ['order_filling_num', 'orderFillingNum', 'orderVisitors', 'clickCount', 'clicks']),
                'order_submit_num' => (int)$this->numericValue($row, ['order_submit_num', 'orderSubmitNum', 'bookings', 'bookingCount', 'orderCount', 'orderQuantity']),
                'validation_status' => 'normal',
                'validation_flags' => json_encode([], JSON_UNESCAPED_UNICODE),
                'data_source_id' => isset($source['id']) ? (int)$source['id'] : null,
                'sync_task_id' => $syncTaskId,
                'ingestion_method' => (string)($source['ingestion_method'] ?? 'manual'),
                'source_trace_id' => $traceId,
            ];
        }

        return $normalized;
    }

    public function listDataSources($user, array $filters = []): array
    {
        $query = Db::name('platform_data_sources')->order('id', 'desc');
        $this->applySourceScope($query, $user);
        if (!empty($filters['platform'])) {
            $query->where('platform', (string)$filters['platform']);
        }
        if (!empty($filters['data_type'])) {
            $query->where('data_type', (string)$filters['data_type']);
        }
        if (!empty($filters['system_hotel_id'])) {
            $query->where('system_hotel_id', (int)$filters['system_hotel_id']);
        }
        $rows = $query->select()->toArray();
        return array_map([$this, 'sanitizeSourceRow'], $rows);
    }

    public function saveDataSource($user, array $payload): array
    {
        $source = $this->normalizeSourcePayload($payload);
        $this->assertCanUseHotel($user, (int)$source['system_hotel_id'], 'can_fetch_online_data');
        $hasSecretInput = false;
        foreach (['secret', 'secret_json', 'cookies', 'cookie', 'token', 'api_key', 'authorization', 'password', 'spidertoken', 'mtgsig'] as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }
            $value = $payload[$key];
            if (is_array($value) ? !empty($value) : trim((string)$value) !== '') {
                $hasSecretInput = true;
                break;
            }
        }

        $now = date('Y-m-d H:i:s');
        $data = [
            'system_hotel_id' => $source['system_hotel_id'],
            'user_id' => (int)($user->id ?? 0) ?: null,
            'name' => $source['name'],
            'platform' => $source['platform'],
            'data_type' => $source['data_type'],
            'ingestion_method' => $source['ingestion_method'],
            'status' => $source['status'],
            'enabled' => $source['enabled'],
            'config_json' => json_encode($source['config'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'secret_json' => json_encode($source['secret'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_by' => (int)($user->id ?? 0) ?: null,
            'update_time' => $now,
        ];

        $id = (int)($payload['id'] ?? 0);
        if ($id > 0) {
            $existing = Db::name('platform_data_sources')->where('id', $id)->find();
            if (!$existing) {
                throw new RuntimeException('Data source not found.', 404);
            }
            $this->assertCanUseHotel($user, (int)($existing['system_hotel_id'] ?? 0), 'can_fetch_online_data');
            if (!$hasSecretInput) {
                unset($data['secret_json']);
            }
            Db::name('platform_data_sources')->where('id', $id)->update($data);
        } else {
            $data['created_by'] = (int)($user->id ?? 0) ?: null;
            $data['create_time'] = $now;
            $id = (int)Db::name('platform_data_sources')->insertGetId($data);
        }

        $row = Db::name('platform_data_sources')->where('id', $id)->find();
        return $this->sanitizeSourceRow($row ?: []);
    }

    public function deleteDataSource($user, int $id): bool
    {
        $row = Db::name('platform_data_sources')->where('id', $id)->find();
        if (!$row) {
            throw new RuntimeException('Data source not found.', 404);
        }
        $this->assertCanUseHotel($user, (int)($row['system_hotel_id'] ?? 0), 'can_delete_online_data');
        Db::name('platform_data_sources')->where('id', $id)->update([
            'enabled' => 0,
            'status' => 'disabled',
            'updated_by' => (int)($user->id ?? 0) ?: null,
            'update_time' => date('Y-m-d H:i:s'),
        ]);
        return true;
    }

    public function syncDataSource($user, int $id, array $options = []): array
    {
        $source = $this->loadSource($id);
        $this->assertCanUseHotel($user, (int)($source['system_hotel_id'] ?? 0), 'can_fetch_online_data');

        if ((int)($source['enabled'] ?? 0) !== 1) {
            throw new RuntimeException('Data source is disabled.', 422);
        }

        $taskId = $this->createTask($source, $user, (string)($options['trigger_type'] ?? 'manual'));
        try {
            $adapter = $this->resolveAdapter($source);
            $result = $adapter->fetch($source, $options);
            if (($result['status'] ?? '') !== 'success') {
                return $this->finishTask($taskId, $source, (string)$result['status'], (string)$result['message'], 0, 0, $result['payload'] ?? []);
            }

            $payload = $result['payload'] ?? [];
            $this->storeRawRecord($source, $taskId, $payload, $result['http_status'] ?? null);
            $rows = $this->normalizeRowsFromPayload(is_array($payload) ? $payload : [], $source, $taskId);
            $saved = $this->saveNormalizedRows($rows);

            $status = $saved > 0 ? 'success' : 'partial_success';
            $message = $saved > 0 ? 'Platform data synchronized.' : 'No business rows were found in payload.';
            return $this->finishTask($taskId, $source, $status, $message, count($rows), $saved, $payload);
        } catch (\Throwable $e) {
            return $this->finishTask($taskId, $source, 'failed', $e->getMessage(), 0, 0, []);
        }
    }

    public function importRows($user, array $payload): array
    {
        $sourceId = (int)($payload['data_source_id'] ?? $payload['source_id'] ?? 0);
        if ($sourceId <= 0) {
            $source = $this->saveDataSource($user, [
                'name' => $payload['name'] ?? 'Manual import',
                'platform' => $payload['platform'] ?? 'custom',
                'data_type' => $payload['data_type'] ?? 'business',
                'system_hotel_id' => $payload['system_hotel_id'] ?? 0,
                'ingestion_method' => 'manual',
            ]);
            $sourceId = (int)$source['id'];
        }

        return $this->syncDataSource($user, $sourceId, [
            'trigger_type' => 'manual_import',
            'payload' => ['rows' => $payload['rows'] ?? $payload['data'] ?? []],
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parseImportFile(string $path, string $originalName): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('Import file not found.', 422);
        }
        if ((int)filesize($path) > 5 * 1024 * 1024) {
            throw new RuntimeException('Import file exceeds 5MB.', 422);
        }

        $extension = strtolower(pathinfo($originalName ?: $path, PATHINFO_EXTENSION));
        $rows = match ($extension) {
            'json' => $this->parseJsonImportFile($path),
            'csv' => $this->parseCsvImportFile($path),
            'xlsx' => $this->parseXlsxImportFile($path),
            default => throw new RuntimeException('Only JSON, CSV and XLSX imports are supported.', 422),
        };

        if (empty($rows)) {
            throw new RuntimeException('Import file has no business rows.', 422);
        }

        return $rows;
    }

    public function listSyncTasks($user, array $filters = []): array
    {
        $query = Db::name('platform_data_sync_tasks')->order('id', 'desc');
        $this->applyTaskScope($query, $user);
        if (!empty($filters['data_source_id'])) {
            $query->where('data_source_id', (int)$filters['data_source_id']);
        }
        if (!empty($filters['status'])) {
            $query->where('status', (string)$filters['status']);
        }
        return $query->limit(max(1, min(200, (int)($filters['limit'] ?? 50))))->select()->toArray();
    }

    public function listSyncLogs($user, array $filters = []): array
    {
        $query = Db::name('platform_data_sync_logs')->order('id', 'desc');
        $this->applyTaskScope($query, $user);
        if (!empty($filters['sync_task_id'])) {
            $query->where('sync_task_id', (int)$filters['sync_task_id']);
        }
        if (!empty($filters['data_source_id'])) {
            $query->where('data_source_id', (int)$filters['data_source_id']);
        }
        return $query->limit(max(1, min(200, (int)($filters['limit'] ?? 50))))->select()->toArray();
    }

    private function normalizeSourcePayload(array $payload): array
    {
        $config = $this->decodeConfig($payload['config_json'] ?? $payload['config'] ?? []);
        $secret = $this->decodeConfig($payload['secret_json'] ?? $payload['secret'] ?? []);
        foreach (['cookies', 'cookie', 'token', 'api_key', 'authorization', 'password', 'spidertoken', 'mtgsig'] as $key) {
            if (array_key_exists($key, $payload) && $payload[$key] !== '') {
                $secret[$key === 'cookie' ? 'cookies' : $key] = (string)$payload[$key];
            }
        }
        foreach (['url', 'request_url', 'method', 'allowed_hosts', 'payload', 'payload_json', 'headers', 'external_hotel_id', 'hotel_name'] as $key) {
            if (array_key_exists($key, $payload) && $payload[$key] !== '') {
                $config[$key] = $payload[$key];
            }
        }

        $method = (string)($payload['ingestion_method'] ?? 'manual');
        $status = in_array($method, ['manual', 'import_json', 'import_csv', 'import_excel'], true) || !empty($config) || !empty($secret)
            ? 'ready'
            : 'waiting_config';
        $dataType = $this->normalizeDataType(trim((string)($payload['data_type'] ?? 'business')) ?: 'business');
        if ($this->isCommentDataType($dataType)) {
            throw new RuntimeException('Comment/review data collection is disabled by policy.', 422);
        }

        return [
            'name' => trim((string)($payload['name'] ?? '')) ?: 'Platform data source',
            'system_hotel_id' => is_numeric($payload['system_hotel_id'] ?? $payload['hotel_id'] ?? null) ? (int)($payload['system_hotel_id'] ?? $payload['hotel_id']) : 0,
            'platform' => strtolower(trim((string)($payload['platform'] ?? 'custom'))) ?: 'custom',
            'data_type' => $dataType,
            'ingestion_method' => $method,
            'status' => $status,
            'enabled' => (int)($payload['enabled'] ?? 1),
            'config' => $config,
            'secret' => $secret,
        ];
    }

    private function loadSource(int $id): array
    {
        $row = Db::name('platform_data_sources')->where('id', $id)->find();
        if (!$row) {
            throw new RuntimeException('Data source not found.', 404);
        }
        $row['config'] = $this->decodeConfig($row['config_json'] ?? []);
        $row['secret'] = $this->decodeConfig($row['secret_json'] ?? []);
        return $row;
    }

    private function resolveAdapter(array $source): DataSourceAdapter
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->supports($source)) {
                return $adapter;
            }
        }
        throw new RuntimeException('No adapter is available for this data source.', 422);
    }

    private function createTask(array $source, $user, string $triggerType): int
    {
        $now = date('Y-m-d H:i:s');
        return (int)Db::name('platform_data_sync_tasks')->insertGetId([
            'data_source_id' => (int)$source['id'],
            'system_hotel_id' => (int)($source['system_hotel_id'] ?? 0) ?: null,
            'platform' => (string)$source['platform'],
            'data_type' => (string)$source['data_type'],
            'ingestion_method' => (string)$source['ingestion_method'],
            'trigger_type' => $triggerType,
            'status' => 'running',
            'attempt_count' => 1,
            'max_attempts' => 3,
            'started_at' => $now,
            'requested_by' => (int)($user->id ?? 0) ?: null,
            'create_time' => $now,
            'update_time' => $now,
        ]);
    }

    private function finishTask(int $taskId, array $source, string $status, string $message, int $normalizedCount, int $savedCount, array $payload): array
    {
        $now = date('Y-m-d H:i:s');
        $stats = [
            'normalized_count' => $normalizedCount,
            'saved_count' => $savedCount,
            'payload_keys' => array_slice(array_keys($payload), 0, 30),
        ];
        $nextRetryAt = in_array($status, ['failed', 'partial_success'], true) ? date('Y-m-d H:i:s', time() + 900) : null;

        Db::name('platform_data_sync_tasks')->where('id', $taskId)->update([
            'status' => $status,
            'finished_at' => $now,
            'next_retry_at' => $nextRetryAt,
            'message' => $message,
            'stats_json' => json_encode($stats, JSON_UNESCAPED_UNICODE),
            'update_time' => $now,
        ]);
        Db::name('platform_data_sources')->where('id', (int)$source['id'])->update([
            'last_sync_time' => $now,
            'last_sync_status' => $status,
            'last_error' => in_array($status, ['success'], true) ? null : $message,
            'status' => $status === 'success' ? 'success' : $status,
            'update_time' => $now,
        ]);
        $this->logSync($taskId, $source, $status === 'success' ? 'info' : 'warning', 'sync_finished', $message, $stats);

        return [
            'task_id' => $taskId,
            'data_source_id' => (int)$source['id'],
            'status' => $status,
            'message' => $message,
            'normalized_count' => $normalizedCount,
            'saved_count' => $savedCount,
            'next_retry_at' => $nextRetryAt,
        ];
    }

    private function storeRawRecord(array $source, int $taskId, array $payload, ?int $httpStatus): void
    {
        $payload = $this->sanitizePayloadForStorage($payload, (string)($source['data_type'] ?? ''));
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        Db::name('platform_data_raw_records')->insert([
            'data_source_id' => (int)$source['id'],
            'sync_task_id' => $taskId,
            'system_hotel_id' => (int)($source['system_hotel_id'] ?? 0) ?: null,
            'platform' => (string)$source['platform'],
            'data_type' => (string)$source['data_type'],
            'ingestion_method' => (string)$source['ingestion_method'],
            'payload_hash' => hash('sha256', (string)$raw),
            'raw_payload' => (string)$raw,
            'http_status' => $httpStatus,
            'received_at' => date('Y-m-d H:i:s'),
            'create_time' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function saveNormalizedRows(array $rows): int
    {
        if (empty($rows)) {
            return 0;
        }

        $columns = $this->tableColumns('online_daily_data');
        $saved = 0;
        foreach ($rows as $row) {
            $data = array_intersect_key($row, $columns);
            if (empty($data)) {
                continue;
            }
            $existing = null;
            if (($row['source_trace_id'] ?? '') !== '' && isset($columns['source_trace_id'])) {
                $existing = Db::name('online_daily_data')->where('source_trace_id', (string)$row['source_trace_id'])->find();
            }
            if (!$existing) {
                $query = Db::name('online_daily_data')
                    ->where('data_date', $row['data_date'])
                    ->where('source', $row['source'])
                    ->where('data_type', $row['data_type']);
                if (!empty($row['system_hotel_id']) && isset($columns['system_hotel_id'])) {
                    $query->where('system_hotel_id', (int)$row['system_hotel_id']);
                }
                if (($row['hotel_id'] ?? '') !== '' && isset($columns['hotel_id'])) {
                    $query->where('hotel_id', (string)$row['hotel_id']);
                }
                if (($row['dimension'] ?? '') !== '' && isset($columns['dimension'])) {
                    $query->where('dimension', (string)$row['dimension']);
                }
                $existing = $query->find();
            }
            if ($existing) {
                if (isset($columns['update_time'])) {
                    $data['update_time'] = date('Y-m-d H:i:s');
                }
                Db::name('online_daily_data')->where('id', (int)$existing['id'])->update($data);
            } else {
                if (isset($columns['create_time'])) {
                    $data['create_time'] = date('Y-m-d H:i:s');
                }
                if (isset($columns['update_time'])) {
                    $data['update_time'] = date('Y-m-d H:i:s');
                }
                Db::name('online_daily_data')->insert($data);
            }
            $saved++;
        }
        return $saved;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractBusinessRows(array $payload): array
    {
        $rows = $payload['rows']
            ?? $payload['list']
            ?? $payload['items']
            ?? $payload['records']
            ?? $payload['orderList']
            ?? $payload['campaignList']
            ?? null;
        if ($rows === null && isset($payload['data']) && is_array($payload['data'])) {
            $rows = $payload['data']['rows']
                ?? $payload['data']['list']
                ?? $payload['data']['items']
                ?? $payload['data']['records']
                ?? $payload['data']['orderList']
                ?? $payload['data']['campaignList']
                ?? $payload['data'];
        }
        if (!is_array($rows)) {
            return [];
        }
        if ($rows !== [] && array_keys($rows) !== range(0, count($rows) - 1)) {
            $rows = [$rows];
        }
        return $rows;
    }

    private function normalizeDataType(string $value): string
    {
        $value = strtolower(trim($value));
        if (in_array($value, ['review', 'reviews', 'comment', 'comments'], true)) {
            return 'review';
        }
        if (in_array($value, ['order', 'orders', 'order_list', 'order-list'], true)) {
            return 'order';
        }
        if (in_array($value, ['ad', 'ads', 'advertising', 'advertisement', 'campaign', 'campaigns'], true)) {
            return 'advertising';
        }
        if (in_array($value, ['quality', 'service', 'service_quality', 'psi'], true)) {
            return 'quality';
        }
        if (in_array($value, ['flow', 'traffic'], true)) {
            return 'traffic';
        }
        return $value !== '' ? $value : 'business';
    }

    private function isCommentDataType(string $dataType): bool
    {
        return $this->normalizeDataType($dataType) === 'review';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function amountValue(array $row, string $dataType): float
    {
        $dataType = $this->normalizeDataType($dataType);
        if ($dataType === 'advertising') {
            return $this->numericValue($row, ['todayCost', 'cost', 'cashCost', 'bonusCost', 'ad_cost', 'adCost', 'spend', 'amount']);
        }
        if ($dataType === 'order') {
            return $this->numericValue($row, ['totalAmount', 'orderAmount', 'payAmount', 'roomAmount', 'amount', 'order_amount', 'room_revenue', 'revenue']);
        }
        return $this->numericValue($row, ['amount', 'checkoutRevenue', 'checkout_revenue', 'revenue', 'order_amount', 'orderAmount', 'room_revenue', 'bookAmount', 'saleAmount', 'totalAmount']);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function quantityValue(array $row, string $dataType): int
    {
        $dataType = $this->normalizeDataType($dataType);
        if ($dataType === 'order') {
            $roomCount = $this->numericValue($row, ['roomCount', 'room_count']);
            $nights = $this->numericValue($row, ['nights', 'night_count', 'nightCount']);
            if ($roomCount > 0 && $nights > 0) {
                return (int)round($roomCount * $nights);
            }
        }

        return (int)$this->numericValue($row, [
            'quantity',
            'room_nights',
            'roomNights',
            'nights',
            'night_count',
            'checkoutRoomNights',
            'checkout_room_nights',
            'checkOutQuantity',
            'bookQuantity',
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function dataValue(array $row, string $dataType): float
    {
        $explicit = $this->numericValue($row, ['data_value', 'dataValue', 'value', 'metric_value', 'averagePrice', 'avgPrice', 'avg_price']);
        if ($explicit > 0) {
            return $explicit;
        }

        $dataType = $this->normalizeDataType($dataType);
        if ($dataType === 'quality') {
            return $this->numericValue($row, ['serviceScore', 'psiScore', 'imScore', 'score']);
        }
        if ($dataType === 'advertising') {
            return $this->numericValue($row, ['roas', 'roi', 'ecpc', 'ctr', 'cvr']);
        }
        if ($dataType === 'order') {
            $quantity = $this->quantityValue($row, $dataType);
            $amount = $this->amountValue($row, $dataType);
            return $quantity > 0 ? round($amount / $quantity, 2) : 0.0;
        }

        return 0.0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function orderCountValue(array $row, string $dataType): int
    {
        $count = (int)$this->numericValue($row, ['book_order_num', 'orders', 'order_count', 'orderCount', 'bookOrderNum', 'orderNum', 'orderQuantity', 'bookings', 'bookingCount']);
        if ($count > 0) {
            return $count;
        }
        if ($this->normalizeDataType($dataType) === 'order' && $this->firstOrderIdentifier($row) !== '') {
            return 1;
        }
        return 0;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sanitizePayloadForStorage(array $payload, string $dataType = ''): array
    {
        return $this->sanitizePayloadNode($payload, $this->normalizeDataType($dataType) === 'order');
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function sanitizePayloadNode(array $node, bool $orderContext): array
    {
        $sanitized = [];
        foreach ($node as $key => $value) {
            $keyText = (string)$key;
            if ($this->isSensitiveConfigKey($keyText)) {
                continue;
            }

            $childOrderContext = $orderContext || $this->isOrderContainerKey($keyText);
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizePayloadArray($value, $childOrderContext);
                continue;
            }

            if ($childOrderContext || $this->isOrderPiiKey($keyText)) {
                $this->appendRedactedOrderField($sanitized, $keyText, $value);
                continue;
            }

            $sanitized[$key] = $value;
        }
        return $sanitized;
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private function sanitizePayloadArray(array $value, bool $orderContext): array
    {
        if ($value === []) {
            return [];
        }
        $sanitized = [];
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $sanitized[$key] = $this->sanitizePayloadNode($item, $orderContext);
            } else {
                $keyText = (string)$key;
                if ($this->isSensitiveConfigKey($keyText)) {
                    continue;
                }
                if ($orderContext || $this->isOrderPiiKey($keyText)) {
                    $this->appendRedactedOrderField($sanitized, $keyText, $item);
                } else {
                    $sanitized[$key] = $item;
                }
            }
        }
        return $sanitized;
    }

    /**
     * @param array<mixed> $target
     */
    private function appendRedactedOrderField(array &$target, string $key, mixed $value): void
    {
        if ($this->isOrderIdKey($key)) {
            $text = trim((string)$value);
            if ($text !== '') {
                $target[$this->redactedFieldName($key, 'hash')] = hash('sha256', 'ota_order|' . $text);
            }
            return;
        }
        if ($this->isPhoneKey($key)) {
            $masked = $this->maskPhone((string)$value);
            if ($masked !== '') {
                $target[$this->redactedFieldName($key, 'masked')] = $masked;
            }
            return;
        }
        if ($this->isGuestNameKey($key)) {
            $masked = $this->maskName((string)$value);
            if ($masked !== '') {
                $target[$this->redactedFieldName($key, 'masked')] = $masked;
            }
            return;
        }
        if ($this->isSensitiveOrderTextKey($key)) {
            return;
        }

        $target[$key] = $value;
    }

    private function isOrderContainerKey(string $key): bool
    {
        return preg_match('/order[_-]?(list|rows|items|data|detail|details|info)|orders/i', $key) === 1;
    }

    private function isOrderPiiKey(string $key): bool
    {
        return $this->isOrderIdKey($key)
            || $this->isPhoneKey($key)
            || $this->isGuestNameKey($key)
            || $this->isSensitiveOrderTextKey($key);
    }

    private function isOrderIdKey(string $key): bool
    {
        return preg_match('/^(order[_-]?(id|no|num|number|sn)|booking[_-]?(id|no|number))$/i', $key) === 1;
    }

    private function isPhoneKey(string $key): bool
    {
        return preg_match('/(phone|mobile|tel)$/i', $key) === 1;
    }

    private function isGuestNameKey(string $key): bool
    {
        return preg_match('/(guest|customer|contact|user|traveller|passenger)[_-]?name$/i', $key) === 1;
    }

    private function isSensitiveOrderTextKey(string $key): bool
    {
        return preg_match('/(certificate|credential|id[_-]?card|card[_-]?no|passport|remark|memo|note|address)/i', $key) === 1;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function firstOrderIdentifier(array $row): string
    {
        foreach (['orderId', 'order_id', 'orderNo', 'order_no', 'orderNumber', 'bookingId', 'booking_id'] as $key) {
            if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
                return trim((string)$row[$key]);
            }
        }
        return '';
    }

    private function redactedFieldName(string $key, string $suffix): string
    {
        if ($this->isOrderIdKey($key)) {
            return 'order_id_hash';
        }
        $name = preg_replace('/(?<!^)[A-Z]/', '_$0', $key) ?? $key;
        $name = strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', '_', $name));
        $name = trim($name, '_');
        return ($name !== '' ? $name : 'field') . '_' . $suffix;
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

    private function sanitizeSourceRow(array $row): array
    {
        $config = $this->decodeConfig($row['config_json'] ?? []);
        $secret = $this->decodeConfig($row['secret_json'] ?? []);
        unset($row['config_json']);
        unset($row['secret_json']);
        $row['config'] = $this->sanitizeConfigForResponse($config);
        $row['has_secret'] = !empty($secret);
        $row['has_cookies'] = isset($secret['cookies']) && trim((string)$secret['cookies']) !== '';
        $row['cookies_preview'] = $row['has_cookies'] ? $this->maskSecret((string)$secret['cookies']) : '';
        return $row;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseJsonImportFile(string $path): array
    {
        $content = file_get_contents($path);
        $decoded = json_decode((string)$content, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('JSON import file is invalid.', 422);
        }
        if ($decoded !== [] && array_keys($decoded) === range(0, count($decoded) - 1)) {
            return array_values(array_filter($decoded, 'is_array'));
        }
        return $this->extractBusinessRows($decoded);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseCsvImportFile(string $path): array
    {
        $handle = fopen($path, 'rb');
        if (!$handle) {
            throw new RuntimeException('CSV import file cannot be read.', 422);
        }

        $headers = [];
        $rows = [];
        while (($cells = fgetcsv($handle)) !== false) {
            $cells = array_map(static fn($value): string => trim((string)$value), $cells);
            if ($this->isBlankRow($cells)) {
                continue;
            }
            if ($headers === []) {
                $headers = $this->normalizeHeaderRow($cells);
                continue;
            }
            $row = [];
            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }
                $row[$header] = $cells[$index] ?? '';
            }
            if (!$this->isBlankRow(array_values($row))) {
                $rows[] = $row;
            }
        }
        fclose($handle);

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseXlsxImportFile(string $path): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new RuntimeException('XLSX import requires PHP ZipArchive extension.', 422);
        }

        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('XLSX import file cannot be opened.', 422);
        }

        try {
            $sharedStrings = $this->readXlsxSharedStrings($zip);
            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            if ($sheetXml === false) {
                throw new RuntimeException('XLSX sheet1.xml was not found.', 422);
            }
            $sheet = simplexml_load_string((string)$sheetXml, 'SimpleXMLElement', LIBXML_NONET);
            if (!$sheet) {
                throw new RuntimeException('XLSX sheet1.xml is invalid.', 422);
            }

            $matrix = [];
            foreach ($sheet->sheetData->row as $rowNode) {
                $row = [];
                foreach ($rowNode->c as $cellNode) {
                    $ref = (string)($cellNode['r'] ?? '');
                    $columnIndex = $this->xlsxColumnIndex($ref);
                    if ($columnIndex < 0) {
                        continue;
                    }
                    $type = (string)($cellNode['t'] ?? '');
                    $value = (string)($cellNode->v ?? '');
                    if ($type === 's') {
                        $value = $sharedStrings[(int)$value] ?? '';
                    } elseif ($type === 'inlineStr') {
                        $value = (string)($cellNode->is->t ?? '');
                    }
                    $row[$columnIndex] = trim($value);
                }
                if (!$this->isBlankRow($row)) {
                    ksort($row);
                    $matrix[] = $row;
                }
            }
        } finally {
            $zip->close();
        }

        if (empty($matrix)) {
            return [];
        }

        $headers = $this->normalizeHeaderRow(array_values(array_shift($matrix)));
        $rows = [];
        foreach ($matrix as $cells) {
            $cells = array_values($cells);
            $row = [];
            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }
                $row[$header] = $cells[$index] ?? '';
            }
            if (!$this->isBlankRow(array_values($row))) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    private function readXlsxSharedStrings(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }
        $shared = simplexml_load_string((string)$xml, 'SimpleXMLElement', LIBXML_NONET);
        if (!$shared) {
            return [];
        }

        $strings = [];
        foreach ($shared->si as $item) {
            if (isset($item->t)) {
                $strings[] = (string)$item->t;
                continue;
            }
            $text = '';
            foreach ($item->r as $run) {
                $text .= (string)($run->t ?? '');
            }
            $strings[] = $text;
        }
        return $strings;
    }

    private function xlsxColumnIndex(string $reference): int
    {
        if (!preg_match('/^([A-Z]+)/i', $reference, $matches)) {
            return -1;
        }
        $letters = strtoupper($matches[1]);
        $index = 0;
        for ($i = 0, $length = strlen($letters); $i < $length; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }
        return $index - 1;
    }

    /**
     * @param array<int, mixed> $cells
     * @return array<int, string>
     */
    private function normalizeHeaderRow(array $cells): array
    {
        return array_map(static function ($value): string {
            $header = trim((string)$value);
            return preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
        }, $cells);
    }

    /**
     * @param array<int, mixed> $values
     */
    private function isBlankRow(array $values): bool
    {
        foreach ($values as $value) {
            if (trim((string)$value) !== '') {
                return false;
            }
        }
        return true;
    }

    private function sanitizeConfigForResponse(array $config): array
    {
        foreach ($config as $key => $value) {
            if ($this->isSensitiveConfigKey((string)$key)) {
                $config[$key] = is_string($value) ? $this->maskSecret($value) : '[configured]';
                continue;
            }
            if (is_array($value)) {
                $config[$key] = $this->sanitizeConfigForResponse($value);
            } elseif (strtolower((string)$key) === 'headers' && is_string($value)) {
                $config[$key] = $this->sanitizeHeaderString($value);
            }
        }
        return $config;
    }

    private function sanitizeHeaderString(string $headers): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $headers) ?: [];
        $sanitized = [];
        foreach ($lines as $line) {
            [$name] = array_pad(explode(':', (string)$line, 2), 2, '');
            $sanitized[] = $this->isSensitiveConfigKey($name) ? trim($name) . ': ' . '[configured]' : $line;
        }
        return implode("\n", $sanitized);
    }

    private function isSensitiveConfigKey(string $key): bool
    {
        return preg_match('/cookie|authorization|token|api[-_]?key|secret|password|spidertoken|mtgsig/i', $key) === 1;
    }

    private function decodeConfig($value): array
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

    private function normalizeDate($value): ?string
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') {
            return null;
        }
        $value = str_replace('/', '-', $value);
        $time = strtotime($value);
        return $time === false ? null : date('Y-m-d', $time);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $keys
     */
    private function numericValue(array $row, array $keys): float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }
            $value = str_replace([',', '%', '￥', '¥', ' '], '', (string)$row[$key]);
            if ($value === '') {
                continue;
            }
            return is_numeric($value) ? (float)$value : 0.0;
        }
        return 0.0;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $keys
     */
    private function stringValue(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
                return trim((string)$row[$key]);
            }
        }
        return '';
    }

    private function buildTraceId(array $source, array $row, string $date, ?int $syncTaskId): string
    {
        $parts = [
            $source['id'] ?? '',
            $source['platform'] ?? '',
            $source['data_type'] ?? '',
            $date,
            $row['hotel_id'] ?? $row['hotelId'] ?? $row['poi_id'] ?? $row['poiId'] ?? '',
            $row['dimension'] ?? $row['_dimName'] ?? '',
            $syncTaskId ?? '',
        ];
        return substr(hash('sha256', implode('|', array_map('strval', $parts))), 0, 64);
    }

    private function maskSecret(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        return mb_substr($value, 0, 4) . '...' . mb_substr($value, -4);
    }

    private function tableColumns(string $table): array
    {
        if (isset($this->columns[$table])) {
            return $this->columns[$table];
        }
        $rows = Db::query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
        $this->columns[$table] = array_fill_keys(array_column($rows, 'Field'), true);
        return $this->columns[$table];
    }

    private function assertCanUseHotel($user, int $hotelId, string $permission): void
    {
        if (!$user) {
            throw new RuntimeException('Unauthenticated.', 401);
        }
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return;
        }
        if ($hotelId <= 0 || !method_exists($user, 'hasHotelPermission') || !$user->hasHotelPermission($hotelId, $permission)) {
            throw new RuntimeException('Forbidden.', 403);
        }
    }

    private function applySourceScope($query, $user): void
    {
        if (!$user || (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())) {
            return;
        }
        $hotelIds = method_exists($user, 'getPermittedHotelIds') ? array_values(array_map('intval', $user->getPermittedHotelIds())) : [];
        if (empty($hotelIds)) {
            $query->whereRaw('1=0');
            return;
        }
        $query->whereIn('system_hotel_id', $hotelIds);
    }

    private function applyTaskScope($query, $user): void
    {
        if (!$user || (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())) {
            return;
        }
        $hotelIds = method_exists($user, 'getPermittedHotelIds') ? array_values(array_map('intval', $user->getPermittedHotelIds())) : [];
        if (empty($hotelIds)) {
            $query->whereRaw('1=0');
            return;
        }
        $query->whereIn('system_hotel_id', $hotelIds);
    }

    private function logSync(int $taskId, array $source, string $level, string $event, string $message, array $context = []): void
    {
        Db::name('platform_data_sync_logs')->insert([
            'sync_task_id' => $taskId,
            'data_source_id' => (int)($source['id'] ?? 0) ?: null,
            'system_hotel_id' => (int)($source['system_hotel_id'] ?? 0) ?: null,
            'level' => $level,
            'event' => $event,
            'message' => $message,
            'context_json' => json_encode($context, JSON_UNESCAPED_UNICODE),
            'create_time' => date('Y-m-d H:i:s'),
        ]);
    }
}
