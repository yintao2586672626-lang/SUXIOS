<?php
declare(strict_types=1);

namespace app\service;

use app\model\User;
use RuntimeException;
use think\facade\Db;

final class CloudOtaBundleImportService
{
    private const TRANSIENT_ERROR_PREFIXES = [
        'cloud_bundle_actor_', 'cloud_bundle_destination_', 'cloud_bundle_cloud_source_',
        'cloud_bundle_import_schema_missing:', 'cloud_bundle_table_unavailable:',
    ];

    /** @return array<string, mixed> */
    public function readBundleFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('cloud_bundle_file_not_readable');
        }
        $bytes = (int)filesize($path);
        if ($bytes <= 0 || $bytes > CloudOtaBundleCodec::MAX_FILE_BYTES) {
            throw new RuntimeException('cloud_bundle_file_size_invalid');
        }
        $decoded = json_decode((string)file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('cloud_bundle_file_invalid');
        }
        return CloudOtaBundleCodec::verify($decoded);
    }

    /** @return array<string, mixed> */
    public function importFile(string $path, int $actorUserId, bool $dryRun = false): array
    {
        return $this->importBundle($this->readBundleFile($path), $actorUserId, $dryRun);
    }

    /** @param array<string, mixed> $bundle @return array<string, mixed> */
    public function importBundle(array $bundle, int $actorUserId, bool $dryRun = false): array
    {
        $bundle = CloudOtaBundleCodec::verify($bundle);
        $actor = $this->loadActor($actorUserId);
        [$hotel, $sources] = $this->assertDestinationBindings($bundle, $actor);
        $this->assertImportSchema();

        if ($dryRun) {
            return [
                'status' => 'validated',
                'dry_run' => true,
                'bundle_id' => (string)$bundle['bundle_id'],
                'target_date' => (string)$bundle['target_date'],
                'destination_system_hotel_id' => (int)$bundle['destination_system_hotel_id'],
                'package_count' => count($bundle['packages']),
                'row_count' => array_sum(array_map(static fn(array $package): int => count($package['rows']), $bundle['packages'])),
                'readback_verified' => false,
                'write_count' => 0,
                'boundary' => 'Dry run validates hash, scope, actor, hotel, source bindings and schema without database writes.',
            ];
        }

        return Db::transaction(function () use ($bundle, $actor, $hotel, $sources): array {
            $packageResults = [];
            $rowCount = 0;
            $inserted = 0;
            $updated = 0;
            $retired = 0;
            $readback = 0;
            foreach ($bundle['packages'] as $package) {
                $sourceId = (int)$package['destination_data_source_id'];
                $source = $this->lockSourceForImport($sources[$sourceId]);
                $this->assertPackageVersionIsCurrent($bundle, $package, $source);
                $taskId = $this->createSyncTask($bundle, $package, $source, $actor);
                $packageResult = $this->importPackage($bundle, $package, $source, $hotel, $taskId);
                $this->finishSyncTask($taskId, $packageResult);
                $this->mirrorSourceCollectionState($bundle, $source, $package, $packageResult);
                $packageResults[] = $packageResult;
                $rowCount += (int)$packageResult['row_count'];
                $inserted += (int)$packageResult['inserted_count'];
                $updated += (int)$packageResult['updated_count'];
                $retired += (int)$packageResult['retired_count'];
                $readback += (int)$packageResult['readback_count'];
            }

            $hasFailedCollection = count(array_filter($packageResults, static fn(array $item): bool =>
                !in_array((string)$item['collection_status'], ['success', 'partial'], true)
            )) > 0;
            return [
                'status' => $hasFailedCollection ? 'partial' : 'succeeded',
                'dry_run' => false,
                'bundle_id' => (string)$bundle['bundle_id'],
                'payload_sha256' => (string)$bundle['payload_sha256'],
                'target_date' => (string)$bundle['target_date'],
                'destination_system_hotel_id' => (int)$bundle['destination_system_hotel_id'],
                'package_count' => count($packageResults),
                'row_count' => $rowCount,
                'inserted_count' => $inserted,
                'updated_count' => $updated,
                'retired_count' => $retired,
                'readback_count' => $readback,
                'readback_verified' => $rowCount > 0 && $rowCount === $readback,
                'packages' => $packageResults,
                'boundary' => 'A partial collection is imported only as explicit data-health evidence; report generation remains gated elsewhere.',
            ];
        });
    }

    /** @return array<string, mixed> */
    public function processInbox(
        string $stateDirectory,
        int $actorUserId,
        int $limit = 10,
        int $maxAttempts = 10
    ): array {
        $paths = $this->ensureInboxDirectories($stateDirectory);
        $limit = max(1, min(50, $limit));
        $maxAttempts = max(1, min(50, $maxAttempts));
        $lock = fopen($paths['lock'], 'c');
        if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            return ['status' => 'skipped', 'reason' => 'cloud_bundle_import_already_running'];
        }

        try {
            $files = glob($paths['inbox'] . DIRECTORY_SEPARATOR . '*.json') ?: [];
            sort($files, SORT_STRING);
            $files = array_slice($files, 0, $limit);
            $results = [];
            foreach ($files as $path) {
                $results[] = $this->processInboxFile($path, $paths, $actorUserId, $maxAttempts);
            }
            $failed = count(array_filter($results, static fn(array $result): bool =>
                in_array((string)($result['status'] ?? ''), ['retry_scheduled', 'rejected'], true)
            ));
            return [
                'status' => $failed > 0 ? 'partial' : 'succeeded',
                'processed_count' => count($results),
                'failed_count' => $failed,
                'inbox_count' => count(glob($paths['inbox'] . DIRECTORY_SEPARATOR . '*.json') ?: []),
                'results' => $results,
                'collection_triggered' => false,
                'report_generation_triggered' => false,
            ];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<string, mixed> */
    public function status(string $stateDirectory): array
    {
        $paths = $this->ensureInboxDirectories($stateDirectory);
        $lastReceipts = glob($paths['receipts'] . DIRECTORY_SEPARATOR . '*.json') ?: [];
        usort($lastReceipts, static fn(string $left, string $right): int => filemtime($right) <=> filemtime($left));
        return [
            'status' => 'available',
            'state_directory' => $paths['root'],
            'inbox_count' => count(glob($paths['inbox'] . DIRECTORY_SEPARATOR . '*.json') ?: []),
            'processed_count' => count(glob($paths['processed'] . DIRECTORY_SEPARATOR . '*.json') ?: []),
            'rejected_count' => count(glob($paths['rejected'] . DIRECTORY_SEPARATOR . '*.json') ?: []),
            'receipt_count' => count($lastReceipts),
            'latest_receipts' => array_values(array_map('basename', array_slice($lastReceipts, 0, 10))),
            'collection_triggered' => false,
            'report_generation_triggered' => false,
        ];
    }

    private function loadActor(int $actorUserId): User
    {
        if ($actorUserId <= 0) {
            throw new RuntimeException('cloud_bundle_actor_user_id_missing');
        }
        $actor = User::find($actorUserId);
        if (!$actor instanceof User || (int)$actor->status !== User::STATUS_ENABLED) {
            throw new RuntimeException('cloud_bundle_actor_missing_or_disabled');
        }
        return $actor;
    }

    /**
     * @param array<string, mixed> $bundle
     * @return array{0:array<string, mixed>,1:array<int, array<string, mixed>>}
     */
    private function assertDestinationBindings(array $bundle, User $actor): array
    {
        $hotelId = (int)$bundle['destination_system_hotel_id'];
        $hotel = Db::name('hotels')->where('id', $hotelId)->field('id,tenant_id,name,status')->find();
        if (!is_array($hotel) || (int)($hotel['status'] ?? 0) !== 1 || (int)($hotel['tenant_id'] ?? 0) <= 0) {
            throw new RuntimeException('cloud_bundle_destination_hotel_missing_or_disabled');
        }
        if (!$actor->isSuperAdmin()) {
            if ((int)($actor->tenant_id ?? 0) !== (int)$hotel['tenant_id']
                || !$actor->hasHotelPermission($hotelId, 'can_fetch_online_data')) {
                throw new RuntimeException('cloud_bundle_actor_forbidden_for_destination_hotel');
            }
        }

        $sources = [];
        foreach ($bundle['packages'] as $package) {
            $sourceId = (int)$package['destination_data_source_id'];
            if (isset($sources[$sourceId])) {
                continue;
            }
            $source = Db::name('platform_data_sources')
                ->withoutField('secret_json')
                ->where('id', $sourceId)
                ->where('tenant_id', (int)$hotel['tenant_id'])
                ->where('system_hotel_id', $hotelId)
                ->where('platform', (string)$package['platform'])
                ->find();
            if (!is_array($source)) {
                throw new RuntimeException('cloud_bundle_cloud_source_binding_missing:' . (string)$package['platform']);
            }
            if ((int)($source['enabled'] ?? 0) !== 1) {
                throw new RuntimeException('cloud_bundle_cloud_source_binding_disabled:' . (string)$package['platform']);
            }
            $sources[$sourceId] = $source;
        }
        return [$hotel, $sources];
    }

    private function assertImportSchema(): void
    {
        $requirements = [
            'online_daily_data' => [
                'id', 'tenant_id', 'system_hotel_id', 'data_source_id', 'data_date', 'source', 'platform',
                'data_type', 'ingestion_method', 'source_trace_id', 'validation_status', 'validation_flags',
                'readback_verified', 'readback_verified_at', 'raw_data',
            ],
            'platform_data_sync_tasks' => [
                'id', 'tenant_id', 'data_source_id', 'system_hotel_id', 'platform', 'data_type',
                'ingestion_method', 'trigger_type', 'status', 'message', 'stats_json',
            ],
        ];
        foreach ($requirements as $table => $columns) {
            $available = $this->tableColumns($table);
            foreach ($columns as $column) {
                if (!isset($available[$column])) {
                    throw new RuntimeException('cloud_bundle_import_schema_missing:' . $table . '.' . $column);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $bundle
     * @param array<string, mixed> $package
     * @param array<string, mixed> $source
     * @param array<string, mixed> $hotel
     * @return array<string, mixed>
     */
    private function importPackage(array $bundle, array $package, array $source, array $hotel, int $taskId): array
    {
        $collectionStatus = (string)$package['collection']['status'];
        $rows = $package['rows'];
        if ($rows === []) {
            return [
                'task_id' => $taskId,
                'platform' => (string)$package['platform'],
                'collection_status' => $collectionStatus,
                'status' => 'failed',
                'message' => (string)$package['collection']['message'],
                'row_count' => 0,
                'inserted_count' => 0,
                'updated_count' => 0,
                'retired_count' => 0,
                'readback_count' => 0,
                'readback_verified' => false,
            ];
        }

        $columns = $this->tableColumns('online_daily_data');
        $inserted = 0;
        $updated = 0;
        $rowIds = [];
        $snapshots = [];
        $rowIdentityOccurrences = [];
        foreach ($rows as $index => $row) {
            $rowIdentityHash = $this->destinationRowIdentityHash($row);
            $rowIdentityOccurrence = (int)($rowIdentityOccurrences[$rowIdentityHash] ?? 0);
            $rowIdentityOccurrences[$rowIdentityHash] = $rowIdentityOccurrence + 1;
            $data = $this->prepareDestinationRow(
                $bundle,
                $package,
                $source,
                $hotel,
                $taskId,
                $row,
                (int)$index,
                $rowIdentityHash,
                $rowIdentityOccurrence,
                $columns
            );
            $existing = Db::name('online_daily_data')
                ->where('tenant_id', (int)$hotel['tenant_id'])
                ->where('system_hotel_id', (int)$hotel['id'])
                ->where('data_source_id', (int)$source['id'])
                ->where('source_trace_id', (string)$data['source_trace_id'])
                ->find();
            if (is_array($existing)) {
                $rowId = (int)$existing['id'];
                if (isset($columns['update_time'])) {
                    $data['update_time'] = date('Y-m-d H:i:s');
                }
                Db::name('online_daily_data')
                    ->where('id', $rowId)
                    ->where('tenant_id', (int)$hotel['tenant_id'])
                    ->where('system_hotel_id', (int)$hotel['id'])
                    ->where('data_source_id', (int)$source['id'])
                    ->update($data);
                $updated++;
            } else {
                if (isset($columns['create_time'])) {
                    $data['create_time'] = date('Y-m-d H:i:s');
                }
                if (isset($columns['update_time'])) {
                    $data['update_time'] = date('Y-m-d H:i:s');
                }
                $rowId = (int)Db::name('online_daily_data')->insertGetId($data);
                if ($rowId <= 0) {
                    throw new RuntimeException('cloud_bundle_row_insert_failed');
                }
                $inserted++;
            }

            $stored = Db::name('online_daily_data')->where('id', $rowId)->lock(true)->find();
            if (!is_array($stored) || !$this->destinationRowMatches($stored, $data)) {
                throw new RuntimeException('cloud_bundle_mysql_readback_mismatch');
            }
            $rowIds[] = $rowId;
            $snapshots[] = $stored;
        }
        if (!$this->markReadbackVerified($snapshots, $hotel, $source, $columns)) {
            throw new RuntimeException('cloud_bundle_mysql_readback_proof_failed');
        }
        $snapshotComplete = ($package['snapshot_complete'] ?? false) === true
            && (int)($package['source_row_count'] ?? -1) === count($rows);
        $retired = $collectionStatus === 'success' && $snapshotComplete
            ? $this->retireRowsMissingFromSnapshot($bundle, $package, $source, $hotel, $rowIds, $columns)
            : 0;
        $readback = (int)Db::name('online_daily_data')
            ->whereIn('id', $rowIds)
            ->where('tenant_id', (int)$hotel['tenant_id'])
            ->where('system_hotel_id', (int)$hotel['id'])
            ->where('data_source_id', (int)$source['id'])
            ->where('data_date', (string)$bundle['target_date'])
            ->where('readback_verified', 1)
            ->count();
        if ($readback !== count($rows)) {
            throw new RuntimeException('cloud_bundle_mysql_readback_count_mismatch');
        }

        return [
            'task_id' => $taskId,
            'platform' => (string)$package['platform'],
            'collection_status' => $collectionStatus,
            'status' => $collectionStatus === 'success' ? 'success' : 'partial_success',
            'message' => 'cloud_bundle_rows_imported_and_read_back',
            'row_count' => count($rows),
            'inserted_count' => $inserted,
            'updated_count' => $updated,
            'retired_count' => $retired,
            'snapshot_complete' => $snapshotComplete,
            'readback_count' => $readback,
            'readback_verified' => true,
        ];
    }

    /**
     * @param array<string, mixed> $bundle
     * @param array<string, mixed> $package
     * @param array<string, mixed> $source
     * @param array<string, mixed> $hotel
     * @param array<string, mixed> $row
     * @param array<string, bool> $columns
     * @return array<string, mixed>
     */
    private function prepareDestinationRow(
        array $bundle,
        array $package,
        array $source,
        array $hotel,
        int $taskId,
        array $row,
        int $index,
        string $rowIdentityHash,
        int $rowIdentityOccurrence,
        array $columns
    ): array {
        $sourceTraceId = trim((string)$row['source_trace_id']);
        $traceId = 'bridge:' . hash('sha256', implode('|', [
            (string)$bundle['source_system_hotel_id'],
            (string)$package['source_data_source_id'],
            (string)$source['id'],
            $sourceTraceId,
            $rowIdentityHash,
            (string)$rowIdentityOccurrence,
        ]));
        $data = CloudOtaBundleCodec::allowlistedRow($row);
        unset(
            $data['tenant_id'],
            $data['system_hotel_id'],
            $data['data_source_id'],
            $data['readback_verified'],
            $data['readback_verified_at'],
            $data['create_time'],
            $data['update_time']
        );
        $data['tenant_id'] = (int)$hotel['tenant_id'];
        $data['system_hotel_id'] = (int)$hotel['id'];
        $data['data_source_id'] = (int)$source['id'];
        $data['sync_task_id'] = $taskId;
        $data['source'] = (string)$package['platform'];
        $data['platform'] = (string)$package['platform'];
        $data['data_date'] = (string)$bundle['target_date'];
        $data['ingestion_method'] = 'cloud_bundle';
        $data['source_trace_id'] = mb_substr($traceId, 0, 80, 'UTF-8');
        $data['raw_data'] = json_encode([
            'contract_version' => CloudOtaBundleCodec::CONTRACT_VERSION,
            'bundle_id' => (string)$bundle['bundle_id'],
            'bundle_created_at' => (string)$bundle['created_at'],
            'payload_sha256' => (string)$bundle['payload_sha256'],
            'source_system_hotel_id' => (int)$bundle['source_system_hotel_id'],
            'source_data_source_id' => (int)$package['source_data_source_id'],
            'source_trace_id' => $sourceTraceId,
            'source_row_identity_sha256' => $rowIdentityHash,
            'source_row_identity_occurrence' => $rowIdentityOccurrence,
            'row_index' => $index,
            'row' => CloudOtaBundleCodec::allowlistedRow($row),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $data = $this->applyDestinationValidation($data, $columns);
        if ((string)($data['validation_status'] ?? '') === 'abnormal') {
            throw new RuntimeException('cloud_bundle_destination_row_validation_failed');
        }
        if (isset($columns['readback_verified'])) {
            $data['readback_verified'] = 0;
        }
        if (isset($columns['readback_verified_at'])) {
            $data['readback_verified_at'] = null;
        }
        return array_intersect_key($data, $columns);
    }

    /** @param array<string, mixed> $row */
    private function destinationRowIdentityHash(array $row): string
    {
        $identity = [];
        foreach ([
            'hotel_id', 'data_date', 'data_type', 'dimension', 'compare_type',
            'data_period', 'snapshot_time', 'snapshot_bucket', 'is_final',
        ] as $field) {
            $identity[$field] = $row[$field] ?? null;
        }
        return hash('sha256', CloudOtaBundleCodec::canonicalJson($identity));
    }

    /** @param array<string, mixed> $data @param array<string, bool> $columns @return array<string, mixed> */
    private function applyDestinationValidation(array $data, array $columns): array
    {
        $flags = [];
        foreach (['source', 'hotel_id', 'data_date', 'data_type'] as $field) {
            if (!array_key_exists($field, $data) || trim((string)$data[$field]) === '') {
                $flags[] = ['level' => 'error', 'field' => $field, 'message' => $field . '_missing'];
            }
        }
        foreach ([
            'amount', 'quantity', 'book_order_num', 'data_value', 'list_exposure',
            'detail_exposure', 'flow_rate', 'order_filling_num', 'order_submit_num',
        ] as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
                continue;
            }
            if (!is_numeric($data[$field]) || (float)$data[$field] < 0) {
                $flags[] = ['level' => 'error', 'field' => $field, 'message' => $field . '_invalid'];
            }
        }
        if (isset($data['flow_rate']) && $data['flow_rate'] !== null
            && is_numeric($data['flow_rate']) && (float)$data['flow_rate'] > 100.0) {
            $flags[] = ['level' => 'error', 'field' => 'flow_rate', 'message' => 'flow_rate_out_of_range'];
        }
        foreach (['comment_score', 'qunar_comment_score'] as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
                continue;
            }
            if (!is_numeric($data[$field]) || (float)$data[$field] < 0 || (float)$data[$field] > 5) {
                $flags[] = ['level' => 'error', 'field' => $field, 'message' => $field . '_out_of_range'];
            }
        }
        $hasError = count(array_filter($flags, static fn(array $flag): bool => ($flag['level'] ?? '') === 'error')) > 0;
        if (isset($columns['validation_status'])) {
            $data['validation_status'] = $hasError ? 'abnormal' : ($flags === [] ? 'normal' : 'warning');
        }
        if (isset($columns['validation_flags'])) {
            $data['validation_flags'] = json_encode($flags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return $data;
    }

    /** @param array<string, mixed> $stored @param array<string, mixed> $expected */
    private function destinationRowMatches(array $stored, array $expected): bool
    {
        foreach ($expected as $field => $expectedValue) {
            if (in_array($field, ['create_time', 'update_time'], true)) {
                continue;
            }
            $storedValue = $stored[$field] ?? null;
            if ($expectedValue === null) {
                if ($storedValue !== null) {
                    return false;
                }
                continue;
            }
            if ($field === 'raw_data') {
                $expectedJson = is_string($expectedValue) ? json_decode($expectedValue, true) : null;
                $storedJson = is_string($storedValue) ? json_decode($storedValue, true) : null;
                if (!is_array($expectedJson) || !is_array($storedJson) || $expectedJson != $storedJson) {
                    return false;
                }
                continue;
            }
            if (is_numeric($expectedValue) && is_numeric($storedValue)) {
                if (abs((float)$expectedValue - (float)$storedValue) > 0.0001) {
                    return false;
                }
                continue;
            }
            if ((string)$storedValue !== (string)$expectedValue) {
                return false;
            }
        }
        return true;
    }

    /**
     * Rows are selected with FOR UPDATE before this compare-and-set, so another
     * writer cannot change business values between readback and proof marking.
     *
     * @param array<int, array<string, mixed>> $snapshots
     * @param array<string, mixed> $hotel
     * @param array<string, mixed> $source
     * @param array<string, bool> $columns
     */
    private function markReadbackVerified(array $snapshots, array $hotel, array $source, array $columns): bool
    {
        if ($snapshots === [] || !isset($columns['readback_verified'], $columns['readback_verified_at'])) {
            return false;
        }
        $verifiedAt = date('Y-m-d H:i:s');
        $ids = [];
        foreach ($snapshots as $snapshot) {
            $rowId = (int)($snapshot['id'] ?? 0);
            if ($rowId <= 0 || isset($ids[$rowId])
                || (int)($snapshot['tenant_id'] ?? 0) !== (int)$hotel['tenant_id']
                || (int)($snapshot['system_hotel_id'] ?? 0) !== (int)$hotel['id']
                || (int)($snapshot['data_source_id'] ?? 0) !== (int)$source['id']
                || (int)($snapshot['readback_verified'] ?? -1) !== 0) {
                return false;
            }
            $ids[$rowId] = true;
            $affected = (int)Db::name('online_daily_data')
                ->where('id', $rowId)
                ->where('tenant_id', (int)$hotel['tenant_id'])
                ->where('system_hotel_id', (int)$hotel['id'])
                ->where('data_source_id', (int)$source['id'])
                ->where('readback_verified', 0)
                ->update([
                    'readback_verified' => 1,
                    'readback_verified_at' => $verifiedAt,
                ]);
            if ($affected !== 1) {
                return false;
            }
        }
        return count($ids) === count($snapshots);
    }

    /** @param array<string, mixed> $source @return array<string, mixed> */
    private function lockSourceForImport(array $source): array
    {
        $locked = Db::name('platform_data_sources')
            ->withoutField('secret_json')
            ->where('id', (int)$source['id'])
            ->where('tenant_id', (int)$source['tenant_id'])
            ->where('system_hotel_id', (int)$source['system_hotel_id'])
            ->lock(true)
            ->find();
        if (!is_array($locked) || (int)($locked['enabled'] ?? 0) !== 1) {
            throw new RuntimeException('cloud_bundle_cloud_source_binding_disabled_during_import');
        }
        return $locked;
    }

    /** @param array<string, mixed> $bundle @param array<string, mixed> $package @param array<string, mixed> $source */
    private function assertPackageVersionIsCurrent(array $bundle, array $package, array $source): void
    {
        $incomingVersion = $this->packageSourceVersion($bundle, $package);
        $incomingCreatedAt = (string)$bundle['created_at'];
        $bundleId = (string)$bundle['bundle_id'];
        $targetDate = (string)$bundle['target_date'];
        $latest = null;
        $legacyTargetTaskFound = false;
        $legacySameBundleFound = false;
        $tasks = Db::name('platform_data_sync_tasks')
            ->where('tenant_id', (int)$source['tenant_id'])
            ->where('data_source_id', (int)$source['id'])
            ->where('system_hotel_id', (int)$source['system_hotel_id'])
            ->where('data_type', 'cloud_bundle')
            ->order('id', 'desc')
            ->field('id,stats_json')
            ->select()
            ->toArray();
        foreach ($tasks as $task) {
            $stats = is_string($task['stats_json'] ?? null)
                ? json_decode((string)$task['stats_json'], true)
                : null;
            if (!is_array($stats) || (string)($stats['target_date'] ?? '') !== $targetDate) {
                continue;
            }
            $taskBundleId = (string)($stats['bundle_id'] ?? '');
            $version = trim((string)($stats['source_version'] ?? ''));
            if ($version === '') {
                $legacyTargetTaskFound = true;
                $legacySameBundleFound = $legacySameBundleFound
                    || ($taskBundleId !== '' && hash_equals($taskBundleId, $bundleId));
                continue;
            }
            $createdAt = trim((string)($stats['bundle_created_at'] ?? ''));
            $candidate = [
                'source_version' => $version,
                'bundle_created_at' => $createdAt !== '' ? $createdAt : $version,
                'bundle_id' => $taskBundleId,
            ];
            if ($latest === null
                || strcmp($candidate['source_version'], $latest['source_version']) > 0
                || ($candidate['source_version'] === $latest['source_version']
                    && strcmp($candidate['bundle_created_at'], $latest['bundle_created_at']) > 0)) {
                $latest = $candidate;
            }
        }

        if (is_array($latest)) {
            $versionComparison = strcmp($incomingVersion, (string)$latest['source_version']);
            $createdAtComparison = strcmp($incomingCreatedAt, (string)$latest['bundle_created_at']);
            $isLatestReplay = $versionComparison === 0
                && $createdAtComparison === 0
                && (string)$latest['bundle_id'] !== ''
                && hash_equals((string)$latest['bundle_id'], $bundleId);
            if ($versionComparison < 0
                || ($versionComparison === 0 && $createdAtComparison < 0)
                || ($versionComparison === 0 && $createdAtComparison === 0 && !$isLatestReplay)) {
                throw new RuntimeException('cloud_bundle_stale_package:' . (string)$package['platform']);
            }
            return;
        }

        $currentSourceVersion = trim((string)($source['last_sync_time'] ?? ''));
        if ($legacySameBundleFound
            && ($currentSourceVersion === '' || strcmp($incomingVersion, $currentSourceVersion) >= 0)) {
            return;
        }
        if ($legacyTargetTaskFound && $currentSourceVersion !== ''
            && strcmp($incomingVersion, $currentSourceVersion) <= 0) {
            throw new RuntimeException('cloud_bundle_stale_package_legacy_version_unverifiable:' . (string)$package['platform']);
        }
    }

    /** @param array<string, mixed> $bundle @param array<string, mixed> $package */
    private function packageSourceVersion(array $bundle, array $package): string
    {
        $lastSyncTime = trim((string)$package['collection']['last_sync_time']);
        return $lastSyncTime !== '' ? $lastSyncTime : (string)$bundle['created_at'];
    }

    /**
     * A successful package is a complete trusted snapshot for its exact
     * source/hotel/date binding. Facts absent from a newer snapshot remain as
     * history but can no longer be consumed as verified current facts.
     *
     * @param array<string, mixed> $bundle
     * @param array<string, mixed> $package
     * @param array<string, mixed> $source
     * @param array<string, mixed> $hotel
     * @param array<int, int> $currentRowIds
     * @param array<string, bool> $columns
     */
    private function retireRowsMissingFromSnapshot(
        array $bundle,
        array $package,
        array $source,
        array $hotel,
        array $currentRowIds,
        array $columns
    ): int {
        $currentLookup = array_fill_keys(array_map('intval', $currentRowIds), true);
        $candidates = Db::name('online_daily_data')
            ->where('tenant_id', (int)$hotel['tenant_id'])
            ->where('system_hotel_id', (int)$hotel['id'])
            ->where('data_source_id', (int)$source['id'])
            ->where('data_date', (string)$bundle['target_date'])
            ->where('ingestion_method', 'cloud_bundle')
            ->where('readback_verified', 1)
            ->field('id,raw_data')
            ->lock(true)
            ->select()
            ->toArray();
        $retireIds = [];
        foreach ($candidates as $candidate) {
            $rowId = (int)($candidate['id'] ?? 0);
            if ($rowId <= 0 || isset($currentLookup[$rowId])) {
                continue;
            }
            $raw = is_string($candidate['raw_data'] ?? null)
                ? json_decode((string)$candidate['raw_data'], true)
                : null;
            if (!is_array($raw)
                || (int)($raw['source_system_hotel_id'] ?? 0) !== (int)$bundle['source_system_hotel_id']
                || (int)($raw['source_data_source_id'] ?? 0) !== (int)$package['source_data_source_id']) {
                continue;
            }
            $retireIds[] = $rowId;
        }
        if ($retireIds === []) {
            return 0;
        }

        $update = [
            'readback_verified' => 0,
            'readback_verified_at' => null,
            'validation_status' => 'unverified',
            'validation_flags' => json_encode([[
                'level' => 'warning',
                'code' => 'cloud_bundle_row_absent_from_newer_verified_snapshot',
                'superseding_bundle_id' => (string)$bundle['bundle_id'],
            ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ];
        if (isset($columns['update_time'])) {
            $update['update_time'] = date('Y-m-d H:i:s');
        }
        return (int)Db::name('online_daily_data')
            ->whereIn('id', $retireIds)
            ->where('tenant_id', (int)$hotel['tenant_id'])
            ->where('system_hotel_id', (int)$hotel['id'])
            ->where('data_source_id', (int)$source['id'])
            ->where('readback_verified', 1)
            ->update(array_intersect_key($update, $columns));
    }

    /** @param array<string, mixed> $bundle @param array<string, mixed> $package @param array<string, mixed> $source */
    private function createSyncTask(array $bundle, array $package, array $source, User $actor): int
    {
        $columns = $this->tableColumns('platform_data_sync_tasks');
        $now = date('Y-m-d H:i:s');
        $data = [
            'tenant_id' => (int)$source['tenant_id'],
            'data_source_id' => (int)$source['id'],
            'system_hotel_id' => (int)$source['system_hotel_id'],
            'platform' => (string)$package['platform'],
            'data_type' => 'cloud_bundle',
            'ingestion_method' => 'cloud_bundle',
            'trigger_type' => 'cloud_bundle_import',
            'status' => 'running',
            'attempt_count' => 1,
            'max_attempts' => 1,
            'started_at' => $now,
            'requested_by' => (int)$actor->id,
            'message' => 'cloud_bundle_import_started',
            'stats_json' => json_encode([
                'bundle_id' => (string)$bundle['bundle_id'],
                'bundle_created_at' => (string)$bundle['created_at'],
                'payload_sha256' => (string)$bundle['payload_sha256'],
                'target_date' => (string)$bundle['target_date'],
                'source_version' => $this->packageSourceVersion($bundle, $package),
                'source_system_hotel_id' => (int)$bundle['source_system_hotel_id'],
                'source_data_source_id' => (int)$package['source_data_source_id'],
                'collection_status' => (string)$package['collection']['status'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'create_time' => $now,
            'update_time' => $now,
        ];
        return (int)Db::name('platform_data_sync_tasks')->insertGetId(array_intersect_key($data, $columns));
    }

    /** @param array<string, mixed> $result */
    private function finishSyncTask(int $taskId, array $result): void
    {
        $stats = [
            'collection_status' => (string)$result['collection_status'],
            'row_count' => (int)$result['row_count'],
            'inserted_count' => (int)$result['inserted_count'],
            'updated_count' => (int)$result['updated_count'],
            'retired_count' => (int)$result['retired_count'],
            'readback_count' => (int)$result['readback_count'],
            'readback_verified' => ($result['readback_verified'] ?? false) === true,
            'collection_triggered' => false,
        ];
        $existing = Db::name('platform_data_sync_tasks')->where('id', $taskId)->value('stats_json');
        $existing = is_string($existing) ? json_decode($existing, true) : [];
        if (!is_array($existing)) {
            $existing = [];
        }
        Db::name('platform_data_sync_tasks')->where('id', $taskId)->update([
            'status' => (string)$result['status'],
            'finished_at' => date('Y-m-d H:i:s'),
            'message' => mb_substr((string)$result['message'], 0, 500, 'UTF-8'),
            'stats_json' => json_encode(array_merge($existing, $stats), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'update_time' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @param array<string, mixed> $bundle @param array<string, mixed> $source @param array<string, mixed> $package @param array<string, mixed> $result */
    private function mirrorSourceCollectionState(array $bundle, array $source, array $package, array $result): void
    {
        $collectionStatus = (string)$package['collection']['status'];
        $lastSyncStatus = match ($collectionStatus) {
            'success' => 'success',
            'partial' => 'partial_success',
            'login_expired' => 'login_expired',
            default => 'failed',
        };
        $lastSyncTime = $this->packageSourceVersion($bundle, $package);
        $currentLastSyncTime = trim((string)($source['last_sync_time'] ?? ''));
        if ($currentLastSyncTime !== '' && strcmp($lastSyncTime, $currentLastSyncTime) < 0) {
            return;
        }
        Db::name('platform_data_sources')
            ->where('id', (int)$source['id'])
            ->where('tenant_id', (int)$source['tenant_id'])
            ->where('system_hotel_id', (int)$source['system_hotel_id'])
            ->update([
            'last_sync_time' => $lastSyncTime,
            'last_sync_status' => $lastSyncStatus,
            'last_error' => $lastSyncStatus === 'success' ? null : mb_substr((string)$package['collection']['message'], 0, 500, 'UTF-8'),
            'update_time' => date('Y-m-d H:i:s'),
            ]);
    }

    /** @param array<string, string> $paths @return array<string, mixed> */
    private function processInboxFile(string $path, array $paths, int $actorUserId, int $maxAttempts): array
    {
        $fileHash = hash_file('sha256', $path) ?: hash('sha256', $path);
        $retryPath = $paths['receipts'] . DIRECTORY_SEPARATOR . $fileHash . '.retry.json';
        $retry = $this->readJson($retryPath);
        $nextRetryAt = strtotime((string)($retry['next_retry_at'] ?? '')) ?: 0;
        if ($nextRetryAt > time()) {
            return ['status' => 'deferred', 'file' => basename($path), 'next_retry_at' => date('Y-m-d H:i:s', $nextRetryAt)];
        }

        try {
            $bundle = $this->readBundleFile($path);
            $bundleId = (string)$bundle['bundle_id'];
            $successReceipt = $paths['receipts'] . DIRECTORY_SEPARATOR . $bundleId . '.success.json';
            if (is_file($successReceipt)) {
                $this->moveFile($path, $paths['processed'] . DIRECTORY_SEPARATOR . $bundleId . '.json');
                return ['status' => 'duplicate', 'bundle_id' => $bundleId, 'file' => basename($path)];
            }
            $result = $this->importBundle($bundle, $actorUserId, false);
            $receipt = [
                'status' => 'success',
                'processed_at' => date('Y-m-d H:i:s'),
                'bundle_id' => $bundleId,
                'file_sha256' => $fileHash,
                'result' => $result,
            ];
            $this->writeJsonAtomic($successReceipt, $receipt);
            if (is_file($retryPath)) {
                @unlink($retryPath);
            }
            $this->moveFile($path, $paths['processed'] . DIRECTORY_SEPARATOR . $bundleId . '.json');
            return [
                'status' => (string)$result['status'],
                'bundle_id' => $bundleId,
                'row_count' => (int)$result['row_count'],
                'readback_verified' => ($result['readback_verified'] ?? false) === true,
            ];
        } catch (\Throwable $exception) {
            $message = $this->safeError($exception->getMessage());
            $attempts = max(0, (int)($retry['attempts'] ?? 0)) + 1;
            $transient = $this->isTransientError($message);
            if (!$transient || $attempts >= $maxAttempts) {
                $destination = $paths['rejected'] . DIRECTORY_SEPARATOR . $fileHash . '.json';
                $this->moveFile($path, $destination);
                $this->writeJsonAtomic($paths['receipts'] . DIRECTORY_SEPARATOR . $fileHash . '.rejected.json', [
                    'status' => 'rejected',
                    'rejected_at' => date('Y-m-d H:i:s'),
                    'attempts' => $attempts,
                    'file_sha256' => $fileHash,
                    'reason' => $message,
                ]);
                if (is_file($retryPath)) {
                    @unlink($retryPath);
                }
                return ['status' => 'rejected', 'file' => basename($path), 'reason' => $message, 'attempts' => $attempts];
            }

            $nextRetry = time() + min(3600, 300 * (2 ** min(4, $attempts - 1)));
            $this->writeJsonAtomic($retryPath, [
                'status' => 'retry_scheduled',
                'attempts' => $attempts,
                'file_sha256' => $fileHash,
                'reason' => $message,
                'next_retry_at' => date('Y-m-d H:i:s', $nextRetry),
            ]);
            return [
                'status' => 'retry_scheduled',
                'file' => basename($path),
                'reason' => $message,
                'attempts' => $attempts,
                'next_retry_at' => date('Y-m-d H:i:s', $nextRetry),
            ];
        }
    }

    /** @return array<string, string> */
    private function ensureInboxDirectories(string $stateDirectory): array
    {
        $root = rtrim(trim($stateDirectory), "\\/");
        if ($root === '') {
            throw new RuntimeException('cloud_bundle_state_directory_missing');
        }
        $paths = [
            'root' => $root,
            'inbox' => $root . DIRECTORY_SEPARATOR . 'inbox',
            'processed' => $root . DIRECTORY_SEPARATOR . 'processed',
            'rejected' => $root . DIRECTORY_SEPARATOR . 'rejected',
            'receipts' => $root . DIRECTORY_SEPARATOR . 'receipts',
            'lock' => $root . DIRECTORY_SEPARATOR . '.import.lock',
        ];
        foreach (['root', 'inbox', 'processed', 'rejected', 'receipts'] as $key) {
            if (!is_dir($paths[$key]) && !mkdir($paths[$key], 0750, true) && !is_dir($paths[$key])) {
                throw new RuntimeException('cloud_bundle_state_directory_create_failed:' . $key);
            }
            @chmod($paths[$key], 0750);
        }
        return $paths;
    }

    private function moveFile(string $source, string $destination): void
    {
        if (is_file($destination)) {
            $destination .= '.' . date('YmdHis');
        }
        if (!rename($source, $destination)) {
            throw new RuntimeException('cloud_bundle_archive_move_failed');
        }
        @chmod($destination, 0640);
    }

    /** @param array<string, mixed> $data */
    private function writeJsonAtomic(string $path, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
        $temp = $path . '.part-' . bin2hex(random_bytes(4));
        if (file_put_contents($temp, $json, LOCK_EX) !== strlen($json)) {
            @unlink($temp);
            throw new RuntimeException('cloud_bundle_receipt_write_failed');
        }
        @chmod($temp, 0640);
        if (!rename($temp, $path)) {
            @unlink($temp);
            throw new RuntimeException('cloud_bundle_receipt_rename_failed');
        }
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        try {
            $decoded = json_decode((string)file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function isTransientError(string $message): bool
    {
        foreach (self::TRANSIENT_ERROR_PREFIXES as $prefix) {
            if (str_starts_with($message, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private function safeError(string $message): string
    {
        $message = preg_replace('/(key|token|secret|cookie|password|authorization)\s*[=:]\s*[^\s,;]+/i', '$1=<redacted>', $message) ?? '';
        return mb_substr(trim($message), 0, 240, 'UTF-8');
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
}
