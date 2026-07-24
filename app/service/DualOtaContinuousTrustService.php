<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * Evaluates the daily Ctrip + Meituan trust loop from persisted facts only.
 *
 * This service never substitutes another date, another hotel, an unverified
 * write, or a numeric zero for missing evidence.
 */
final class DualOtaContinuousTrustService
{
    private const PLATFORMS = ['ctrip', 'meituan'];
    private const TRUSTED_VALIDATION_STATUSES = [
        'normal', 'available', 'verified', 'valid', 'confirmed', 'approved',
        'passed', 'ok', 'success', 'complete', 'completed', 'readback_verified',
    ];
    private const FAILED_TASK_STATUSES = [
        'failed', 'capture_failed', 'permission_denied', 'collection_failed',
        'login_expired', 'waiting_config', 'profile_session_not_ready',
    ];
    private const REQUIRED_TRAFFIC_METRICS = [
        'ctrip' => [
            'list_exposure',
            'detail_exposure',
            'flow_rate',
            'order_filling_num',
            'order_submit_num',
        ],
        'meituan' => [
            'list_exposure',
            'detail_exposure',
            'flow_rate',
        ],
    ];

    /** @var array<string, array<string, bool>> */
    private array $columns = [];

    /** @return array<string, mixed> */
    public function inspectHotel(int $hotelId, string $startDate, string $endDate): array
    {
        if ($hotelId <= 0) {
            throw new \InvalidArgumentException('hotel_id must be a positive integer.');
        }
        self::assertDateRange($startDate, $endDate);
        if (!$this->tableExists('hotels')) {
            return self::unavailable($hotelId, $startDate, $endDate, 'hotels_table_missing');
        }

        $hotelColumns = $this->tableColumns('hotels');
        $hotelFields = array_values(array_intersect(['id', 'tenant_id', 'name', 'status'], array_keys($hotelColumns)));
        $hotel = Db::name('hotels')
            ->where('id', $hotelId)
            ->field(implode(',', $hotelFields))
            ->find();
        if (!is_array($hotel)) {
            return self::unavailable($hotelId, $startDate, $endDate, 'hotel_not_found');
        }
        if (!$this->tableExists('platform_data_sources')
            || !$this->tableExists('platform_data_sync_tasks')
            || !$this->tableExists('online_daily_data')
        ) {
            return self::unavailable($hotelId, $startDate, $endDate, 'continuous_trust_source_table_missing');
        }

        $sources = $this->loadSources($hotelId);
        $tasks = $this->loadTasks($hotelId);
        $rows = $this->loadRows($hotelId, $startDate, $endDate, $sources);
        $dailyColumns = $this->tableColumns('online_daily_data');

        return self::evaluate(
            $hotel,
            $startDate,
            $endDate,
            $rows,
            $sources,
            $tasks,
            isset($dailyColumns['readback_verified']),
            isset($dailyColumns['validation_status'])
        );
    }

    /**
     * Pure evaluator used by the live database adapter and focused tests.
     *
     * @param array<string, mixed> $hotel
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array<string, mixed>> $sources
     * @param array<int, array<string, mixed>> $tasks
     * @return array<string, mixed>
     */
    public static function evaluate(
        array $hotel,
        string $startDate,
        string $endDate,
        array $rows,
        array $sources,
        array $tasks,
        bool $hasReadbackColumn = true,
        bool $hasValidationColumn = true
    ): array {
        self::assertDateRange($startDate, $endDate);
        $hotelId = (int)($hotel['id'] ?? 0);
        $tenantId = (int)($hotel['tenant_id'] ?? 0);
        $days = [];
        $verifiedDays = 0;

        foreach (array_reverse(self::dateRange($startDate, $endDate)) as $date) {
            $platformRows = [];
            foreach (self::PLATFORMS as $platform) {
                $platformRows[] = self::evaluatePlatformDay(
                    $platform,
                    $date,
                    $hotelId,
                    $tenantId,
                    $rows,
                    $sources,
                    $tasks,
                    $hasReadbackColumn,
                    $hasValidationColumn
                );
            }

            $platformStatuses = array_column($platformRows, 'status');
            if ($platformStatuses === ['verified', 'verified']) {
                $dayStatus = 'verified';
                $verifiedDays++;
            } elseif ($platformStatuses === ['collection_failed', 'collection_failed']) {
                $dayStatus = 'collection_failed';
            } else {
                $dayStatus = 'partial';
            }
            $days[] = [
                'date' => $date,
                'status' => $dayStatus,
                'platforms' => $platformRows,
            ];
        }

        $consecutiveVerifiedDays = 0;
        foreach ($days as $day) {
            if (($day['status'] ?? '') !== 'verified') {
                break;
            }
            $consecutiveVerifiedDays++;
        }
        $latestStatus = (string)($days[0]['status'] ?? 'partial');
        $status = $verifiedDays === count($days) && $days !== []
            ? 'verified'
            : ($latestStatus === 'collection_failed' ? 'collection_failed' : 'partial');

        return [
            'schema_version' => 1,
            'metric_scope' => 'ota_channel',
            'hotel_id' => $hotelId > 0 ? $hotelId : null,
            'hotel_name' => trim((string)($hotel['name'] ?? '')),
            'tenant_scope_status' => $tenantId > 0 ? 'verified' : 'partial',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'status' => $tenantId > 0 ? $status : 'partial',
            'evaluated_days' => count($days),
            'verified_days' => $verifiedDays,
            'consecutive_verified_days' => $consecutiveVerifiedDays,
            'required_platforms' => self::PLATFORMS,
            'required_steps' => [
                'source',
                'hotel',
                'date',
                'field_facts',
                'save',
                'readback',
                'page_status',
                'p0',
            ],
            'days' => $days,
            'boundary' => 'Only exact-date, tenant/hotel-bound, Profile-sourced, saved and database-read-back Ctrip and Meituan facts can become verified. Old rows and numeric zero never replace missing evidence.',
        ];
    }

    /** @return array<string, mixed> */
    public static function unscoped(string $startDate, string $endDate): array
    {
        self::assertDateRange($startDate, $endDate);
        return self::unavailable(null, $startDate, $endDate, 'hotel_scope_required');
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array<string, mixed>> $sources
     * @param array<int, array<string, mixed>> $tasks
     * @return array<string, mixed>
     */
    private static function evaluatePlatformDay(
        string $platform,
        string $date,
        int $hotelId,
        int $tenantId,
        array $rows,
        array $sources,
        array $tasks,
        bool $hasReadbackColumn,
        bool $hasValidationColumn
    ): array {
        $platformSources = array_values(array_filter($sources, static function (array $source) use ($platform, $hotelId, $tenantId): bool {
            $sourceTenantId = (int)($source['tenant_id'] ?? 0);
            $sourceHotelId = (int)($source['system_hotel_id'] ?? 0);
            return self::rowPlatform($source) === $platform
                && (int)($source['enabled'] ?? 1) === 1
                && $sourceHotelId === $hotelId
                && $tenantId > 0
                && $sourceTenantId === $tenantId;
        }));
        $sourceIds = array_values(array_filter(array_map(
            static fn(array $source): int => (int)($source['id'] ?? 0),
            $platformSources
        ), static fn(int $id): bool => $id > 0));

        $targetRows = array_values(array_filter($rows, static function (array $row) use ($platform, $date, $sourceIds): bool {
            $sourceId = (int)($row['data_source_id'] ?? 0);
            return substr(trim((string)($row['data_date'] ?? '')), 0, 10) === $date
                && (self::rowPlatform($row) === $platform || ($sourceId > 0 && in_array($sourceId, $sourceIds, true)))
                && !self::retiredSnapshot($row);
        }));
        $scopedRows = array_values(array_filter($targetRows, static function (array $row) use ($hotelId, $tenantId, $sourceIds, $hasValidationColumn): bool {
            return $hasValidationColumn
                && (int)($row['system_hotel_id'] ?? 0) === $hotelId
                && $tenantId > 0
                && (int)($row['tenant_id'] ?? 0) === $tenantId
                && (int)($row['data_source_id'] ?? 0) > 0
                && in_array((int)$row['data_source_id'], $sourceIds, true)
                && self::trustedValidationStatus((string)($row['validation_status'] ?? ''));
        }));
        $trafficRows = array_values(array_filter($scopedRows, static function (array $row) use ($platform): bool {
            $dataType = strtolower(trim((string)($row['data_type'] ?? '')));
            return in_array($dataType, ['traffic', 'flow', 'conversion'], true)
                && OtaTrafficAttributionService::rowBelongsToOwnPlatformTraffic(
                    self::attributionRow($row),
                    $platform
                );
        }));

        $task = self::latestExactDateTask($tasks, $platform, $date, $hotelId, $tenantId, $sourceIds);
        $taskStatus = strtolower(trim((string)($task['status'] ?? '')));
        $taskIngestionMethod = strtolower(trim((string)($task['ingestion_method'] ?? '')));
        $taskStats = self::decodeArray($task['stats_json'] ?? []);
        $taskDiagnostics = is_array($taskStats['sync_diagnostics'] ?? null) ? $taskStats['sync_diagnostics'] : [];
        $localP0TaskReady = $taskStatus === 'success'
            && in_array($taskIngestionMethod, ['browser_profile', 'profile_browser'], true)
            && strtolower(trim((string)($taskDiagnostics['p0_status'] ?? ''))) === 'ready';
        $cloudP0TaskReady = $taskStatus === 'success'
            && $taskIngestionMethod === 'cloud_bundle'
            && strtolower(trim((string)($taskStats['collection_status'] ?? ''))) === 'success'
            && ($taskStats['readback_verified'] ?? false) === true;
        $p0TaskReady = $localP0TaskReady || $cloudP0TaskReady;

        $facts = self::trafficFactClosure($platform, $trafficRows);
        $sourceReady = $platformSources !== [] && (
            count(array_filter($platformSources, static fn(array $source): bool => in_array(
                strtolower(trim((string)($source['ingestion_method'] ?? ''))),
                ['browser_profile', 'profile_browser'],
                true
            ))) > 0
            || ($cloudP0TaskReady && count(array_filter(
                $trafficRows,
                static fn(array $row): bool => self::rowCarriesProfileOrigin($row)
            )) === count($trafficRows))
        );
        $hotelReady = $sourceReady
            && $trafficRows !== []
            && (bool)($facts['platform_hotel_identifier_ready'] ?? false);
        $dateReady = $trafficRows !== [];
        $fieldFactsReady = (bool)($facts['ready'] ?? false);
        $saveReady = $trafficRows !== [] && count(array_filter($trafficRows, static fn(array $row): bool =>
            (int)($row['id'] ?? 0) > 0
            && (int)($row['data_source_id'] ?? 0) > 0
            && (int)($row['sync_task_id'] ?? 0) > 0
        )) === count($trafficRows);
        $readbackReady = $hasReadbackColumn && $trafficRows !== [] && count(array_filter(
            $trafficRows,
            static fn(array $row): bool => (int)($row['readback_verified'] ?? 0) === 1
        )) === count($trafficRows);
        $pageStatusReady = $fieldFactsReady && (bool)($facts['ui_status_ready'] ?? false) && $readbackReady;
        $p0Ready = $p0TaskReady
            && $fieldFactsReady
            && $readbackReady
            && (bool)($facts['nonzero_required_metric_ready'] ?? false)
            && (bool)($facts['platform_hotel_identifier_ready'] ?? false);

        $steps = [
            'source' => $sourceReady,
            'hotel' => $hotelReady,
            'date' => $dateReady,
            'field_facts' => $fieldFactsReady,
            'save' => $saveReady,
            'readback' => $readbackReady,
            'page_status' => $pageStatusReady,
            'p0' => $p0Ready,
        ];
        $missingSteps = array_keys(array_filter($steps, static fn(bool $ready): bool => !$ready));
        $collectionFailed = in_array($taskStatus, self::FAILED_TASK_STATUSES, true)
            || ($trafficRows === [] && self::sourceReportsCollectionFailure($platformSources, $date));
        $status = $missingSteps === []
            ? 'verified'
            : ($collectionFailed ? 'collection_failed' : 'partial');

        return [
            'platform' => $platform,
            'status' => $status,
            'target_date' => $date,
            'source_method' => $sourceReady
                ? ($cloudP0TaskReady ? 'cloud_profile_bridge' : 'browser_profile')
                : null,
            'data_source_ids' => $sourceIds,
            'sync_task_id' => (int)($task['id'] ?? 0) ?: null,
            'sync_task_status' => $taskStatus !== '' ? $taskStatus : null,
            'steps' => $steps,
            'missing_steps' => $missingSteps,
            'required_metric_keys' => self::REQUIRED_TRAFFIC_METRICS[$platform],
            'complete_metric_keys' => $facts['complete_metric_keys'],
            'missing_metric_keys' => $facts['missing_metric_keys'],
            'p0_status' => $p0Ready ? 'ready' : 'blocked',
            'failure_reason' => $collectionFailed
                ? (trim((string)($task['message'] ?? '')) ?: 'target_date_collection_failed')
                : null,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private static function trafficFactClosure(string $platform, array $rows): array
    {
        $required = self::REQUIRED_TRAFFIC_METRICS[$platform];
        $expectedStorage = [];
        foreach ($required as $metricKey) {
            $expectedStorage[$metricKey] = 'online_daily_data.' . $metricKey;
        }

        $complete = [];
        $allUiReady = $rows !== [];
        $allIdentifiersReady = $rows !== [];
        $nonzeroRows = 0;
        foreach ($rows as $row) {
            $evidenceRow = self::evidenceRow($row);
            $raw = self::decodeArray($evidenceRow['raw_data'] ?? []);
            $rowTraceId = trim((string)($evidenceRow['source_trace_id'] ?? $raw['source_trace_id'] ?? ''));
            $rowEvidence = is_array($raw['capture_evidence'] ?? null) ? $raw['capture_evidence'] : [];
            $rowUrlHash = trim((string)($rowEvidence['source_url_hash'] ?? $raw['source_url_hash'] ?? ''));
            $rowComplete = [];

            foreach (is_array($raw['field_facts'] ?? null) ? $raw['field_facts'] : [] as $fact) {
                if (!is_array($fact)) {
                    continue;
                }
                $metricKey = strtolower(trim((string)($fact['metric_key'] ?? '')));
                if (!isset($expectedStorage[$metricKey])) {
                    continue;
                }
                $sourcePath = trim((string)($fact['source_path'] ?? ''));
                $storageField = trim((string)($fact['storage_field'] ?? ''));
                $factEvidence = is_array($fact['capture_evidence'] ?? null) ? $fact['capture_evidence'] : [];
                $factTraceId = trim((string)($factEvidence['source_trace_id'] ?? ''));
                $factUrlHash = trim((string)($factEvidence['source_url_hash'] ?? ''));
                $factReady = self::structuredSourcePath($sourcePath)
                    && $storageField === $expectedStorage[$metricKey]
                    && ($fact['stored_value_present'] ?? null) === true
                    && $rowTraceId !== ''
                    && $rowUrlHash !== ''
                    && hash_equals($rowTraceId, $factTraceId)
                    && hash_equals($rowUrlHash, $factUrlHash);
                if ($factReady) {
                    $complete[$metricKey] = true;
                    $rowComplete[$metricKey] = true;
                }
            }

            $rowUiReady = count(array_diff($required, array_keys($rowComplete))) === 0;
            if (!$rowUiReady) {
                $allUiReady = false;
            }

            $identifierReady = ($raw['platform_hotel_identifier_present'] ?? null) === true
                && trim((string)($raw['platform_hotel_identifier_source'] ?? '')) !== ''
                && !in_array(
                    strtolower(trim((string)($raw['platform_hotel_identifier_proof'] ?? ''))),
                    ['', 'missing', 'unverified'],
                    true
                );
            $bindingStatus = strtolower(trim((string)($raw['platform_hotel_binding_status'] ?? '')));
            if ($bindingStatus !== '') {
                $identifierReady = $identifierReady
                    && $bindingStatus === 'matched'
                    && !in_array(
                        strtolower(trim((string)($raw['platform_hotel_binding_proof'] ?? ''))),
                        ['', 'missing', 'unverified'],
                        true
                    );
            }
            if (!$identifierReady) {
                $allIdentifiersReady = false;
            }

            foreach ($required as $metricKey) {
                if (!array_key_exists($metricKey, $row) || $row[$metricKey] === null || $row[$metricKey] === '') {
                    continue;
                }
                if (is_numeric($row[$metricKey]) && abs((float)$row[$metricKey]) > 0.000001) {
                    $nonzeroRows++;
                    break;
                }
            }
        }

        $completeKeys = array_values(array_intersect($required, array_keys($complete)));
        $missingKeys = array_values(array_diff($required, $completeKeys));
        return [
            'ready' => $rows !== [] && $missingKeys === [] && $allUiReady,
            'ui_status_ready' => $allUiReady,
            'platform_hotel_identifier_ready' => $allIdentifiersReady,
            'nonzero_required_metric_ready' => $nonzeroRows > 0,
            'complete_metric_keys' => $completeKeys,
            'missing_metric_keys' => $missingKeys,
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function attributionRow(array $row): array
    {
        $evidenceRow = self::evidenceRow($row);
        foreach (['platform', 'source', 'data_type', 'dimension', 'compare_type'] as $field) {
            if (!array_key_exists($field, $evidenceRow) && array_key_exists($field, $row)) {
                $evidenceRow[$field] = $row[$field];
            }
        }
        return $evidenceRow;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function evidenceRow(array $row): array
    {
        if (strtolower(trim((string)($row['ingestion_method'] ?? ''))) !== 'cloud_bundle') {
            return $row;
        }
        $wrapper = self::decodeArray($row['raw_data'] ?? []);
        $sourceRow = is_array($wrapper['row'] ?? null) ? $wrapper['row'] : [];
        return $sourceRow !== [] ? $sourceRow : $row;
    }

    /** @param array<string, mixed> $row */
    private static function rowCarriesProfileOrigin(array $row): bool
    {
        $sourceRow = self::evidenceRow($row);
        return in_array(
            strtolower(trim((string)($sourceRow['ingestion_method'] ?? ''))),
            ['browser_profile', 'profile_browser'],
            true
        );
    }

    /**
     * @param array<int, array<string, mixed>> $tasks
     * @param array<int, int> $sourceIds
     * @return array<string, mixed>
     */
    private static function latestExactDateTask(
        array $tasks,
        string $platform,
        string $date,
        int $hotelId,
        int $tenantId,
        array $sourceIds
    ): array {
        $matches = array_values(array_filter($tasks, static function (array $task) use ($platform, $date, $hotelId, $tenantId, $sourceIds): bool {
            if (self::rowPlatform($task) !== $platform
                || (int)($task['system_hotel_id'] ?? 0) !== $hotelId
                || $tenantId <= 0
                || (int)($task['tenant_id'] ?? 0) !== $tenantId
                || !in_array((int)($task['data_source_id'] ?? 0), $sourceIds, true)
            ) {
                return false;
            }
            $stats = self::decodeArray($task['stats_json'] ?? []);
            $diagnostics = is_array($stats['sync_diagnostics'] ?? null) ? $stats['sync_diagnostics'] : [];
            $readback = is_array($stats['run_readback'] ?? null) ? $stats['run_readback'] : [];
            $taskDate = substr(trim((string)(
                $diagnostics['target_date']
                ?? $readback['target_date']
                ?? $stats['target_date']
                ?? ($stats['collection_quality']['target_date'] ?? '')
            )), 0, 10);
            return $taskDate === $date;
        }));
        usort($matches, static function (array $left, array $right): int {
            $leftTime = (string)($left['finished_at'] ?? $left['update_time'] ?? $left['create_time'] ?? '');
            $rightTime = (string)($right['finished_at'] ?? $right['update_time'] ?? $right['create_time'] ?? '');
            return strcmp($rightTime, $leftTime);
        });
        return $matches[0] ?? [];
    }

    /** @param array<int, array<string, mixed>> $sources */
    private static function sourceReportsCollectionFailure(array $sources, string $date): bool
    {
        foreach ($sources as $source) {
            $status = strtolower(trim((string)($source['last_sync_status'] ?? $source['status'] ?? '')));
            $lastSyncDate = substr(trim((string)($source['last_sync_time'] ?? '')), 0, 10);
            if ($lastSyncDate === $date && in_array($status, self::FAILED_TASK_STATUSES, true)) {
                return true;
            }
        }
        return false;
    }

    private static function trustedValidationStatus(string $status): bool
    {
        return in_array(strtolower(trim($status)), self::TRUSTED_VALIDATION_STATUSES, true);
    }

    private static function structuredSourcePath(string $sourcePath): bool
    {
        $sourcePath = trim($sourcePath);
        return $sourcePath !== ''
            && (str_contains($sourcePath, '.') || str_contains($sourcePath, '[') || str_contains($sourcePath, '/'));
    }

    /** @return array<string, mixed> */
    private static function decodeArray(mixed $value): array
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

    /** @param array<string, mixed> $row */
    private static function rowPlatform(array $row): string
    {
        $platform = strtolower(trim((string)($row['platform'] ?? $row['source'] ?? '')));
        return match (true) {
            str_contains($platform, 'ctrip'), str_contains($platform, 'xiecheng') => 'ctrip',
            str_contains($platform, 'meituan') => 'meituan',
            default => $platform,
        };
    }

    /** @param array<string, mixed> $row */
    private static function retiredSnapshot(array $row): bool
    {
        if (strtolower(trim((string)($row['validation_status'] ?? ''))) !== 'unverified'
            || (int)($row['readback_verified'] ?? 1) !== 0
        ) {
            return false;
        }
        $flags = self::decodeArray($row['validation_flags'] ?? []);
        foreach ($flags as $flag) {
            $code = is_array($flag) ? (string)($flag['code'] ?? '') : (string)$flag;
            if ($code === 'cloud_bundle_row_absent_from_newer_verified_snapshot') {
                return true;
            }
        }
        return false;
    }

    /** @return array<int, string> */
    private static function dateRange(string $startDate, string $endDate): array
    {
        $start = new \DateTimeImmutable($startDate . ' 00:00:00', new \DateTimeZone('Asia/Shanghai'));
        $end = new \DateTimeImmutable($endDate . ' 00:00:00', new \DateTimeZone('Asia/Shanghai'));
        $dates = [];
        for ($cursor = $start; $cursor <= $end; $cursor = $cursor->modify('+1 day')) {
            $dates[] = $cursor->format('Y-m-d');
        }
        return $dates;
    }

    private static function assertDateRange(string $startDate, string $endDate): void
    {
        foreach ([$startDate, $endDate] as $date) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('Asia/Shanghai'));
            $errors = \DateTimeImmutable::getLastErrors();
            if (!$parsed instanceof \DateTimeImmutable
                || (is_array($errors) && ((int)($errors['warning_count'] ?? 0) > 0 || (int)($errors['error_count'] ?? 0) > 0))
                || $parsed->format('Y-m-d') !== $date
            ) {
                throw new \InvalidArgumentException('Continuous trust date range must use valid YYYY-MM-DD dates.');
            }
        }
        $days = (int)floor((strtotime($endDate) - strtotime($startDate)) / 86400) + 1;
        if ($startDate > $endDate || $days < 1 || $days > 30) {
            throw new \InvalidArgumentException('Continuous trust date range must contain 1 to 30 days.');
        }
    }

    /** @return array<string, mixed> */
    private static function unavailable(?int $hotelId, string $startDate, string $endDate, string $reason): array
    {
        return [
            'schema_version' => 1,
            'metric_scope' => 'ota_channel',
            'hotel_id' => $hotelId,
            'hotel_name' => '',
            'tenant_scope_status' => 'partial',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'status' => 'partial',
            'evaluated_days' => 0,
            'verified_days' => 0,
            'consecutive_verified_days' => 0,
            'required_platforms' => self::PLATFORMS,
            'required_steps' => [
                'source', 'hotel', 'date', 'field_facts', 'save', 'readback', 'page_status', 'p0',
            ],
            'days' => [],
            'reason' => $reason,
            'boundary' => 'No hotel-scoped evidence was evaluated, so the result remains partial.',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function loadSources(int $hotelId): array
    {
        $columns = $this->tableColumns('platform_data_sources');
        $fields = array_values(array_intersect([
            'id', 'tenant_id', 'system_hotel_id', 'platform', 'ingestion_method',
            'status', 'enabled', 'last_sync_status', 'last_error', 'last_sync_time',
        ], array_keys($columns)));
        return Db::name('platform_data_sources')
            ->where('system_hotel_id', $hotelId)
            ->whereIn('platform', self::PLATFORMS)
            ->field(implode(',', $fields))
            ->order('id', 'asc')
            ->select()
            ->toArray();
    }

    /** @return array<int, array<string, mixed>> */
    private function loadTasks(int $hotelId): array
    {
        $columns = $this->tableColumns('platform_data_sync_tasks');
        $fields = array_values(array_intersect([
            'id', 'tenant_id', 'data_source_id', 'system_hotel_id', 'platform',
            'data_type', 'ingestion_method', 'status', 'message', 'stats_json', 'started_at', 'finished_at',
            'create_time', 'update_time',
        ], array_keys($columns)));
        return Db::name('platform_data_sync_tasks')
            ->where('system_hotel_id', $hotelId)
            ->order('id', 'desc')
            ->limit(600)
            ->field(implode(',', $fields))
            ->select()
            ->toArray();
    }

    /**
     * @param array<int, array<string, mixed>> $sources
     * @return array<int, array<string, mixed>>
     */
    private function loadRows(int $hotelId, string $startDate, string $endDate, array $sources): array
    {
        $columns = $this->tableColumns('online_daily_data');
        $fields = array_values(array_intersect([
            'id', 'tenant_id', 'system_hotel_id', 'hotel_id', 'hotel_name', 'data_date',
            'source', 'platform', 'data_type', 'dimension', 'validation_status',
            'validation_flags', 'data_source_id', 'sync_task_id', 'ingestion_method',
            'source_trace_id', 'raw_data', 'readback_verified', 'readback_verified_at',
            'list_exposure', 'detail_exposure', 'flow_rate', 'order_filling_num',
            'order_submit_num',
        ], array_keys($columns)));
        $load = static function ($query) use ($fields, $startDate, $endDate): array {
            return $query
                ->whereBetween('data_date', [$startDate, $endDate])
                ->field(implode(',', $fields))
                ->order('data_date', 'desc')
                ->order('id', 'desc')
                ->limit(10000)
                ->select()
                ->toArray();
        };

        $rows = $load(Db::name('online_daily_data')->where('system_hotel_id', $hotelId));
        if (!isset($columns['data_source_id'])) {
            return $rows;
        }
        $sourceIds = array_values(array_filter(array_map(
            static fn(array $source): int => (int)($source['id'] ?? 0),
            $sources
        ), static fn(int $id): bool => $id > 0));
        if ($sourceIds === []) {
            return $rows;
        }
        $boundRows = $load(Db::name('online_daily_data')->whereIn('data_source_id', $sourceIds));
        $byId = [];
        foreach (array_merge($rows, $boundRows) as $row) {
            $key = (string)($row['id'] ?? md5(json_encode($row) ?: ''));
            $byId[$key] = $row;
        }
        return array_values($byId);
    }

    private function tableExists(string $table): bool
    {
        try {
            return !empty(Db::query("SHOW TABLES LIKE '" . addslashes($table) . "'"));
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string, bool> */
    private function tableColumns(string $table): array
    {
        if (isset($this->columns[$table])) {
            return $this->columns[$table];
        }
        try {
            $rows = Db::query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
            $this->columns[$table] = array_fill_keys(array_map(
                static fn(array $row): string => (string)($row['Field'] ?? ''),
                $rows
            ), true);
        } catch (\Throwable) {
            $this->columns[$table] = [];
        }
        return $this->columns[$table];
    }
}
