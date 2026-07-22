<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;
use think\facade\Db;

final class CloudOtaBundleExportService
{
    private const TRUSTED_VALIDATION_STATUSES = [
        'normal', 'available', 'verified', 'ok', 'success', 'complete', 'completed', 'readback_verified',
    ];

    /**
     * @param array<string, mixed> $binding
     * @param array<int, string> $requiredPlatforms
     * @param array<int, int> $syncTaskIdsBySource
     * @return array<string, mixed>
     */
    public function export(
        array $binding,
        string $targetDate,
        array $requiredPlatforms,
        string $outputPath,
        array $syncTaskIdsBySource
    ): array
    {
        $binding = CloudOtaBundleCodec::verifyBinding($binding);
        $sourceHotelId = (int)$binding['source_system_hotel_id'];
        $destinationHotelId = (int)$binding['destination_system_hotel_id'];
        $requiredPlatforms = $this->normalizePlatforms($requiredPlatforms);
        $sourceHotel = $this->assertSourceHotel($sourceHotelId);
        $this->assertReadbackSchema();

        $boundPlatforms = array_values(array_unique(array_map(
            static fn(array $item): string => (string)$item['platform'],
            $binding['bindings']
        )));
        foreach ($requiredPlatforms as $platform) {
            if (!in_array($platform, $boundPlatforms, true)) {
                throw new RuntimeException('cloud_binding_required_platform_missing:' . $platform);
            }
        }
        $selectedBindings = array_values(array_filter(
            $binding['bindings'],
            static fn(array $item): bool => in_array((string)$item['platform'], $requiredPlatforms, true)
        ));

        $packages = [];
        $rowCount = 0;
        $missingPlatforms = [];
        foreach ($selectedBindings as $item) {
            $source = $this->loadSource(
                (int)$item['source_data_source_id'],
                $sourceHotelId,
                (int)$sourceHotel['tenant_id'],
                (string)$item['platform']
            );
            $sourceId = (int)$item['source_data_source_id'];
            $syncTaskId = (int)($syncTaskIdsBySource[$sourceId] ?? 0);
            if ($syncTaskId <= 0) {
                throw new RuntimeException('cloud_bundle_sync_task_binding_missing:' . (string)$item['platform']);
            }
            $syncTask = $this->loadVerifiedSyncTask(
                $syncTaskId,
                $source,
                $sourceHotelId,
                (int)$sourceHotel['tenant_id'],
                (string)$item['platform'],
                $targetDate
            );
            [$rows, $targetRowCount] = $this->trustedTargetRows(
                $source,
                $syncTask,
                $sourceHotelId,
                (int)$sourceHotel['tenant_id'],
                $targetDate
            );
            $collection = $this->collectionState($syncTask, $rows, $targetRowCount);
            if ($rows === []) {
                $missingPlatforms[] = (string)$item['platform'];
            }
            $rowCount += count($rows);
            if ($rowCount > CloudOtaBundleCodec::MAX_ROWS) {
                throw new RuntimeException('cloud_bundle_row_limit_exceeded');
            }
            $packages[] = [
                'platform' => (string)$item['platform'],
                'source_data_source_id' => (int)$item['source_data_source_id'],
                'source_sync_task_id' => $syncTaskId,
                'destination_data_source_id' => (int)$item['destination_data_source_id'],
                'collection' => $collection,
                'snapshot_complete' => count($rows) === $targetRowCount,
                'source_row_count' => $targetRowCount,
                'rows' => $rows,
            ];
        }

        $bundle = CloudOtaBundleCodec::build([
            'source_system_hotel_id' => $sourceHotelId,
            'destination_system_hotel_id' => $destinationHotelId,
            'target_date' => $targetDate,
            'required_platforms' => $requiredPlatforms,
        ], $packages);
        $this->writeAtomic($outputPath, $bundle);

        return [
            'status' => $missingPlatforms === [] ? 'ready' : 'partial',
            'bundle_id' => (string)$bundle['bundle_id'],
            'payload_sha256' => (string)$bundle['payload_sha256'],
            'target_date' => (string)$bundle['target_date'],
            'source_system_hotel_id' => $sourceHotelId,
            'destination_system_hotel_id' => $destinationHotelId,
            'package_count' => count($packages),
            'row_count' => $rowCount,
            'missing_platforms' => array_values(array_unique($missingPlatforms)),
            'output_file' => realpath($outputPath) ?: $outputPath,
            'upload_allowed' => $rowCount > 0 || $missingPlatforms !== [],
            'boundary' => 'Only locally read-back-verified rows are exported; missing packages remain explicit health evidence.',
        ];
    }

    /** @return array<string, mixed> */
    public function readBindingFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('cloud_binding_file_not_readable');
        }
        if ((int)filesize($path) > 256 * 1024) {
            throw new RuntimeException('cloud_binding_file_too_large');
        }
        $decoded = json_decode((string)file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('cloud_binding_file_invalid');
        }
        return CloudOtaBundleCodec::verifyBinding($decoded);
    }

    /** @return array<string, mixed> */
    private function assertSourceHotel(int $hotelId): array
    {
        $hotel = Db::name('hotels')->where('id', $hotelId)->field('id,tenant_id,name,status')->find();
        if (!is_array($hotel) || (int)($hotel['status'] ?? 0) !== 1 || (int)($hotel['tenant_id'] ?? 0) <= 0) {
            throw new RuntimeException('cloud_bundle_source_hotel_missing_or_disabled');
        }
        return $hotel;
    }

    private function assertReadbackSchema(): void
    {
        $columns = $this->tableColumns('online_daily_data');
        foreach ([
            'tenant_id', 'system_hotel_id', 'data_source_id', 'data_date', 'source_trace_id',
            'sync_task_id', 'validation_status', 'readback_verified', 'readback_verified_at',
        ] as $column) {
            if (!isset($columns[$column])) {
                throw new RuntimeException('cloud_bundle_export_schema_missing:' . $column);
            }
        }
    }

    /** @return array<string, mixed> */
    private function loadSource(int $sourceId, int $hotelId, int $tenantId, string $platform): array
    {
        $source = Db::name('platform_data_sources')
            ->withoutField('secret_json')
            ->where('id', $sourceId)
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('platform', $platform)
            ->find();
        if (!is_array($source)) {
            throw new RuntimeException('cloud_bundle_source_binding_missing:' . $platform);
        }
        if ((int)($source['tenant_id'] ?? 0) <= 0 || (int)($source['enabled'] ?? 0) !== 1) {
            throw new RuntimeException('cloud_bundle_source_binding_disabled:' . $platform);
        }
        return $source;
    }

    /** @param array<string, mixed> $source @return array<string, mixed> */
    private function loadVerifiedSyncTask(
        int $taskId,
        array $source,
        int $hotelId,
        int $tenantId,
        string $platform,
        string $targetDate
    ): array {
        $taskColumns = $this->tableColumns('platform_data_sync_tasks');
        $query = Db::name('platform_data_sync_tasks')
            ->where('id', $taskId)
            ->where('data_source_id', (int)$source['id'])
            ->where('system_hotel_id', $hotelId)
            ->where('platform', $platform);
        if (isset($taskColumns['tenant_id'])) {
            $query->where('tenant_id', $tenantId);
        }
        $task = $query->find();
        if (!is_array($task)
            || !in_array(strtolower(trim((string)($task['status'] ?? ''))), ['success', 'partial_success'], true)
        ) {
            throw new RuntimeException('cloud_bundle_sync_task_missing_or_incomplete:' . $platform);
        }
        try {
            $stats = json_decode((string)($task['stats_json'] ?? ''), true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            throw new RuntimeException('cloud_bundle_sync_task_receipt_invalid:' . $platform, 0, $exception);
        }
        $receipt = is_array($stats['run_readback'] ?? null) ? $stats['run_readback'] : [];
        $rowIds = array_values(array_unique(array_filter(array_map(
            'intval',
            is_array($receipt['row_ids'] ?? null) ? $receipt['row_ids'] : []
        ), static fn(int $id): bool => $id > 0)));
        sort($rowIds, SORT_NUMERIC);
        if (($receipt['readback_verified'] ?? false) !== true
            || (int)($receipt['sync_task_id'] ?? 0) !== $taskId
            || (int)($receipt['data_source_id'] ?? 0) !== (int)$source['id']
            || (int)($receipt['system_hotel_id'] ?? 0) !== $hotelId
            || strtolower(trim((string)($receipt['platform'] ?? ''))) !== $platform
            || substr(trim((string)($receipt['target_date'] ?? '')), 0, 10) !== $targetDate
            || (int)($receipt['readback_count'] ?? 0) !== count($rowIds)
            || $rowIds === []
        ) {
            throw new RuntimeException('cloud_bundle_sync_task_receipt_invalid:' . $platform);
        }
        $receipt['row_ids'] = $rowIds;
        $task['run_readback'] = $receipt;
        return $task;
    }

    /**
     * @param array<string, mixed> $source
     * @return array{0:array<int, array<string, mixed>>,1:int}
     */
    private function trustedTargetRows(array $source, array $syncTask, int $hotelId, int $tenantId, string $targetDate): array
    {
        $columns = $this->tableColumns('online_daily_data');
        $fields = array_values(array_intersect(CloudOtaBundleCodec::rowFields(), array_keys($columns)));
        $base = Db::name('online_daily_data')
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('data_source_id', (int)$source['id'])
            ->where('sync_task_id', (int)$syncTask['id'])
            ->where('data_date', $targetDate);
        $targetRowCount = (int)(clone $base)->count();
        $rows = $base
            ->where('readback_verified', 1)
            ->whereIn('validation_status', self::TRUSTED_VALIDATION_STATUSES)
            ->order('id', 'asc')
            ->limit(CloudOtaBundleCodec::MAX_ROWS + 1)
            ->field('id,' . implode(',', $fields))
            ->select()
            ->toArray();
        if (count($rows) > CloudOtaBundleCodec::MAX_ROWS) {
            throw new RuntimeException(
                'cloud_bundle_source_row_limit_exceeded:' . strtolower((string)($source['platform'] ?? 'unknown'))
            );
        }

        $normalized = [];
        $trustedRowIds = [];
        foreach ($rows as $row) {
            $trustedRowIds[] = (int)($row['id'] ?? 0);
            $normalized[] = CloudOtaBundleCodec::allowlistedRow($row);
        }
        $expectedRowIds = array_values(array_map(
            'intval',
            (array)($syncTask['run_readback']['row_ids'] ?? [])
        ));
        sort($trustedRowIds, SORT_NUMERIC);
        sort($expectedRowIds, SORT_NUMERIC);
        if ($targetRowCount !== count($expectedRowIds) || $trustedRowIds !== $expectedRowIds) {
            throw new RuntimeException(
                'cloud_bundle_sync_task_row_identity_mismatch:' . strtolower((string)($source['platform'] ?? 'unknown'))
            );
        }
        return [$normalized, $targetRowCount];
    }

    /** @param array<string, mixed> $syncTask @param array<int, array<string, mixed>> $rows @return array<string, string> */
    private function collectionState(array $syncTask, array $rows, int $targetRowCount): array
    {
        $taskStatus = strtolower(trim((string)($syncTask['status'] ?? '')));

        if ($rows !== []) {
            $status = $taskStatus === 'success' ? 'success' : 'partial';
            $message = $status === 'success' ? 'target_date_rows_readback_verified' : 'target_date_rows_verified_with_source_warning';
        } elseif ($targetRowCount > 0) {
            $status = 'failed';
            $message = 'sync_task_rows_exist_but_are_untrusted';
        } else {
            $status = 'target_date_missing';
            $message = 'sync_task_target_date_rows_missing';
        }

        return [
            'status' => $status,
            'message' => $message,
            'last_sync_time' => trim((string)($syncTask['finished_at'] ?? '')),
        ];
    }

    /** @param array<string, mixed> $bundle */
    private function writeAtomic(string $outputPath, array $bundle): void
    {
        $outputPath = trim($outputPath);
        if ($outputPath === '' || strtolower(pathinfo($outputPath, PATHINFO_EXTENSION)) !== 'json') {
            throw new RuntimeException('cloud_bundle_output_file_must_be_json');
        }
        $directory = dirname($outputPath);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('cloud_bundle_output_directory_create_failed');
        }
        $json = json_encode(
            $bundle,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
        if (strlen($json) > CloudOtaBundleCodec::MAX_FILE_BYTES) {
            throw new RuntimeException('cloud_bundle_file_limit_exceeded');
        }
        $tempPath = $outputPath . '.part-' . bin2hex(random_bytes(4));
        if (file_put_contents($tempPath, $json, LOCK_EX) !== strlen($json)) {
            @unlink($tempPath);
            throw new RuntimeException('cloud_bundle_write_failed');
        }
        @chmod($tempPath, 0640);
        if (!rename($tempPath, $outputPath)) {
            @unlink($tempPath);
            throw new RuntimeException('cloud_bundle_atomic_rename_failed');
        }
    }

    /** @return array<string, bool> */
    private function tableColumns(string $table): array
    {
        try {
            $rows = Db::query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
            return array_fill_keys(array_column($rows, 'Field'), true);
        } catch (\Throwable $exception) {
            throw new RuntimeException('cloud_bundle_table_unavailable:' . $table, 0, $exception);
        }
    }

    /** @param array<int, string> $platforms @return array<int, string> */
    private function normalizePlatforms(array $platforms): array
    {
        $normalized = [];
        foreach ($platforms as $platform) {
            $platform = strtolower(trim((string)$platform));
            if (!in_array($platform, ['ctrip', 'meituan'], true)) {
                throw new RuntimeException('cloud_bundle_platform_invalid:' . $platform);
            }
            $normalized[] = $platform;
        }
        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_STRING);
        if ($normalized === []) {
            throw new RuntimeException('cloud_bundle_required_platforms_missing');
        }
        return $normalized;
    }
}
