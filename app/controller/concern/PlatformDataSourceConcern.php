<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\model\OperationLog;
use app\service\OtaBrowserAssistImportService;
use app\service\PlatformDataSyncService;
use think\Response;
use think\facade\Db;

trait PlatformDataSourceConcern
{
    public function dataSourceList(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_view_online_data');

        try {
            $service = new PlatformDataSyncService();
            return $this->success($service->listDataSources($this->currentUser, $this->request->get()));
        } catch (\Throwable $e) {
            return $this->error('获取数据源失败: ' . $e->getMessage(), 500);
        }
    }

    public function collectionResourceCatalog(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_view_online_data');

        try {
            $service = new PlatformDataSyncService();
            return $this->success($service->collectionResourceCatalog($this->currentUser, $this->request->get()));
        } catch (\Throwable $e) {
            return $this->error('Failed to load collection resource catalog: ' . $e->getMessage(), 500);
        }
    }

    public function collectionStatus(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_view_online_data');

        try {
            $requestData = array_merge($this->request->get(), $this->requestData());
            $platform = $this->normalizeCollectionStatusPlatform($requestData['platform'] ?? 'all');
            $systemHotelId = $this->resolveOnlineDataSystemHotelId(
                $requestData['system_hotel_id']
                ?? $requestData['systemHotelId']
                ?? $requestData['hotel_id']
                ?? $requestData['hotelId']
                ?? null
            );
            if (!$systemHotelId) {
                return $this->success($this->emptyCollectionStatusPayload($platform), '请选择酒店后查看采集状态');
            }

            $permissionStatus = $this->currentUser->isSuperAdmin() || $this->currentUser->hasHotelPermission((int)$systemHotelId, 'can_view_online_data')
                ? 'allowed'
                : 'denied';
            if ($permissionStatus !== 'allowed') {
                throw new \RuntimeException('无权查看该酒店采集状态', 403);
            }
            $fetchPermissionStatus = $this->currentUser->isSuperAdmin() || $this->currentUser->hasHotelPermission((int)$systemHotelId, 'can_fetch_online_data')
                ? 'allowed'
                : 'denied';

            $service = new PlatformDataSyncService();
            $filters = ['system_hotel_id' => (int)$systemHotelId];
            if (in_array($platform, ['ctrip', 'meituan'], true)) {
                $filters['platform'] = $platform;
            }

            $catalog = $service->collectionResourceCatalog($this->currentUser, $filters);
            $sources = $service->listDataSources($this->currentUser, $filters);
            $tasks = $service->listSyncTasks($this->currentUser, array_merge($filters, ['limit' => 30]));
            $dailySummary = $this->collectionStatusDailySummary((int)$systemHotelId, $platform);
            $profileStatus = $this->buildPlatformProfileStatus((int)$systemHotelId);
            $hotel = Db::name('hotels')->where('id', (int)$systemHotelId)->find() ?: [];
            $platforms = in_array($platform, ['ctrip', 'meituan'], true) ? [$platform] : ['ctrip', 'meituan'];
            $platformRows = [];
            foreach ($platforms as $itemPlatform) {
                $platformRows[$itemPlatform] = $this->buildCollectionStatusPlatformRow(
                    $itemPlatform,
                    $catalog,
                    $sources,
                    $tasks,
                    $dailySummary[$itemPlatform] ?? [],
                    $profileStatus
                );
            }

            return $this->success([
                'generated_at' => date('Y-m-d H:i:s'),
                'tokenStatus' => 'valid',
                'hotelId' => (int)$systemHotelId,
                'tenantId' => $this->positiveCollectionStatusInt($hotel['tenant_id'] ?? $this->currentUser->tenant_id ?? null),
                'platform' => $platform,
                'currentHotelName' => (string)($hotel['name'] ?? ''),
                'permissionStatus' => $permissionStatus,
                'fetchPermissionStatus' => $fetchPermissionStatus,
                'dataScope' => 'ota_channel',
                'reviewCollectionStatus' => 'policy_disabled',
                'requiresExplicitReviewAuthorization' => true,
                'context' => [
                    'tokenStatus' => 'valid',
                    'hotelId' => (int)$systemHotelId,
                    'tenantId' => $this->positiveCollectionStatusInt($hotel['tenant_id'] ?? $this->currentUser->tenant_id ?? null),
                    'platform' => $platform,
                    'currentHotelName' => (string)($hotel['name'] ?? ''),
                    'permissionStatus' => $permissionStatus,
                    'fetchPermissionStatus' => $fetchPermissionStatus,
                ],
                'platforms' => $platformRows,
                'summary' => $this->summarizeCollectionStatusPlatforms($platformRows),
                'source_contract' => [
                    'scope' => 'OTA 渠道口径',
                    'storage_table' => 'online_daily_data',
                    'task_table' => 'platform_data_sync_tasks',
                    'profile_login_scope' => ['ctrip', 'meituan'],
                    'sensitive_fields' => 'redacted',
                ],
            ], '采集状态已读取');
        } catch (\think\exception\HttpException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getStatusCode()));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getCode()));
        } catch (\Throwable $e) {
            return $this->error('获取采集状态失败: ' . $e->getMessage(), 500);
        }
    }

    public function saveDataSource(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        try {
            $service = new PlatformDataSyncService();
            $data = $service->saveDataSource($this->currentUser, $this->requestData());
            $this->clearAutoFetchLightProfileSourcesCache((int)($data['system_hotel_id'] ?? 0), (string)($data['platform'] ?? ''));
            OperationLog::record('online_data', 'save_data_source', '保存平台数据源: ' . ($data['name'] ?? ''), $this->currentUser->id, (int)($data['system_hotel_id'] ?? 0) ?: null);
            return $this->success($data, '数据源保存成功');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getCode()));
        } catch (\Throwable $e) {
            return $this->error('保存数据源失败: ' . $e->getMessage(), 500);
        }
    }

    public function deleteDataSource(int $id): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_delete_online_data');

        try {
            $source = Db::name('platform_data_sources')->where('id', $id)->find();
            $service = new PlatformDataSyncService();
            $service->deleteDataSource($this->currentUser, (int)$id);
            $this->clearBrowserProfileStatusCacheForSource(is_array($source) ? $source : []);
            $this->clearAutoFetchLightProfileSourcesCache((int)($source['system_hotel_id'] ?? 0), (string)($source['platform'] ?? ''));
            OperationLog::record('online_data', 'delete_data_source', '停用平台数据源ID: ' . $id, $this->currentUser->id);
            return $this->success(['id' => (int)$id], '数据源已停用');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getCode()));
        } catch (\Throwable $e) {
            return $this->error('删除数据源失败: ' . $e->getMessage(), 500);
        }
    }

    public function syncDataSource(int $id): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        try {
            $service = new PlatformDataSyncService();
            $result = $service->syncDataSource($this->currentUser, (int)$id, $this->requestData());
            OperationLog::record('online_data', 'sync_data_source', '同步平台数据源ID: ' . $id . '，状态: ' . $result['status'], $this->currentUser->id, null);
            return $this->success($result, '同步任务已完成');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getCode()));
        } catch (\Throwable $e) {
            return $this->error('同步数据源失败: ' . $e->getMessage(), 500);
        }
    }

    public function importDataSourceRows(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        try {
            $service = new PlatformDataSyncService();
            $payload = $this->requestData();
            $file = $this->request->file('file') ?: $this->request->file('import_file');
            if ($file) {
                $payload['rows'] = $service->parseImportFile($file->getPathname(), $file->getOriginalName());
            }
            $result = $service->importRows($this->currentUser, $payload);
            OperationLog::record('online_data', 'import_data_source_rows', '导入平台数据，状态: ' . $result['status'], $this->currentUser->id, null);
            return $this->success($result, '导入任务已完成');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getCode()));
        } catch (\Throwable $e) {
            return $this->error('导入数据失败: ' . $e->getMessage(), 500);
        }
    }

    public function importBrowserAssistCapture(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        try {
            $payload = $this->browserAssistCaptureRequestData();
            $service = new OtaBrowserAssistImportService();
            $result = $service->importCapture($this->currentUser, $payload);
            OperationLog::record('online_data', 'import_browser_assist_capture', '导入浏览器辅助采集数据，分包: ' . $result['package_count'] . '，入库: ' . $result['saved_count'], $this->currentUser->id, null);
            return $this->success($result, '浏览器辅助采集导入完成');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getCode()));
        } catch (\Throwable $e) {
            return $this->error('浏览器辅助采集导入失败: ' . $e->getMessage(), 500);
        }
    }

    public function syncTaskList(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_view_online_data');

        try {
            $service = new PlatformDataSyncService();
            return $this->success($service->listSyncTasks($this->currentUser, $this->request->get()));
        } catch (\Throwable $e) {
            return $this->error('获取同步任务失败: ' . $e->getMessage(), 500);
        }
    }

    public function syncLogList(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_view_online_data');

        try {
            $service = new PlatformDataSyncService();
            return $this->success($service->listSyncLogs($this->currentUser, $this->request->get()));
        } catch (\Throwable $e) {
            return $this->error('获取同步日志失败: ' . $e->getMessage(), 500);
        }
    }

    private function normalizeCollectionStatusPlatform($value): string
    {
        $platform = strtolower(trim((string)$value));
        if (in_array($platform, ['ctrip', 'meituan', 'all'], true)) {
            return $platform;
        }
        return 'unknown';
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyCollectionStatusPayload(string $platform): array
    {
        return [
            'generated_at' => date('Y-m-d H:i:s'),
            'tokenStatus' => 'valid',
            'hotelId' => null,
            'tenantId' => null,
            'platform' => $platform,
            'currentHotelName' => '',
            'permissionStatus' => 'unknown',
            'fetchPermissionStatus' => 'unknown',
            'dataScope' => 'ota_channel',
            'reviewCollectionStatus' => 'policy_disabled',
            'requiresExplicitReviewAuthorization' => true,
            'context' => [
                'tokenStatus' => 'valid',
                'hotelId' => null,
                'tenantId' => null,
                'platform' => $platform,
                'currentHotelName' => '',
                'permissionStatus' => 'unknown',
                'fetchPermissionStatus' => 'unknown',
            ],
            'platforms' => [],
            'summary' => [
                'collectionStatus' => 'not_loaded',
                'dataCollected' => false,
                'latestCollectedAt' => '',
                'latestDataDate' => '',
                'dataRange' => '',
                'failureReason' => 'system_hotel_id_missing',
            ],
            'source_contract' => [
                'scope' => 'OTA 渠道口径',
                'storage_table' => 'online_daily_data',
                'task_table' => 'platform_data_sync_tasks',
                'profile_login_scope' => ['ctrip', 'meituan'],
                'sensitive_fields' => 'redacted',
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function collectionStatusDailySummary(int $systemHotelId, string $platform): array
    {
        $columns = $this->collectionStatusTableColumns('online_daily_data');
        if (!isset($columns['data_date'], $columns['system_hotel_id'])) {
            return [];
        }

        $sourceColumn = isset($columns['source']) ? 'source' : (isset($columns['platform']) ? 'platform' : '');
        if ($sourceColumn === '') {
            return [];
        }

        $platforms = in_array($platform, ['ctrip', 'meituan'], true) ? [$platform] : ['ctrip', 'meituan'];
        $fields = [
            $sourceColumn . ' AS platform',
            'COUNT(*) AS row_count',
            'MIN(data_date) AS start_date',
            'MAX(data_date) AS end_date',
        ];
        if (isset($columns['update_time'])) {
            $fields[] = 'MAX(update_time) AS latest_collected_at';
        } elseif (isset($columns['create_time'])) {
            $fields[] = 'MAX(create_time) AS latest_collected_at';
        }

        try {
            $query = Db::name('online_daily_data')
                ->field(implode(',', $fields))
                ->whereIn($sourceColumn, $platforms);
            $query->where('system_hotel_id', $systemHotelId);
            $rows = $query->group($sourceColumn)->select()->toArray();
        } catch (\Throwable $e) {
            return [];
        }

        $summary = [];
        foreach ($rows as $row) {
            $itemPlatform = strtolower((string)($row['platform'] ?? ''));
            if (!in_array($itemPlatform, ['ctrip', 'meituan'], true)) {
                continue;
            }
            $start = (string)($row['start_date'] ?? '');
            $end = (string)($row['end_date'] ?? '');
            $summary[$itemPlatform] = [
                'row_count' => (int)($row['row_count'] ?? 0),
                'start_date' => $start,
                'end_date' => $end,
                'latest_collected_at' => (string)($row['latest_collected_at'] ?? ''),
                'data_range' => $start !== '' && $end !== '' ? ($start === $end ? $start : $start . ' 至 ' . $end) : '',
            ];
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $catalog
     * @param array<int, array<string, mixed>> $sources
     * @param array<int, array<string, mixed>> $tasks
     * @param array<string, mixed> $dailySummary
     * @param array<string, mixed> $profileStatus
     * @return array<string, mixed>
     */
    private function buildCollectionStatusPlatformRow(string $platform, array $catalog, array $sources, array $tasks, array $dailySummary, array $profileStatus): array
    {
        $platformSources = array_values(array_filter($sources, static fn(array $source): bool => strtolower((string)($source['platform'] ?? '')) === $platform));
        $platformTasks = array_values(array_filter($tasks, static fn(array $task): bool => strtolower((string)($task['platform'] ?? '')) === $platform));
        $latestTask = $this->latestCollectionStatusTask($platformTasks);
        $latestSource = $this->latestCollectionStatusSource($platformSources);
        $resourceStatuses = $this->collectionStatusResourceRows($catalog, $platform);
        $profileRow = $this->collectionStatusProfileRow($profileStatus, $platform);
        $dataCollected = (int)($dailySummary['row_count'] ?? 0) > 0;
        $collectionStatus = $this->resolveCollectionStatus($dataCollected, $latestTask, $resourceStatuses);
        $failureReason = $this->collectionStatusFailureReason($collectionStatus, $latestTask, $latestSource, $profileRow, $resourceStatuses, $dataCollected);

        return [
            'platform' => $platform,
            'platformName' => $platform === 'meituan' ? '美团' : '携程',
            'platformLoginStatus' => (string)($profileRow['status_code'] ?? 'unconfigured'),
            'platformLoginText' => (string)($profileRow['current_status'] ?? '未配置'),
            'permissionStatus' => 'allowed',
            'dataCollected' => $dataCollected,
            'collectionStatus' => $collectionStatus,
            'latestCollectedAt' => (string)($dailySummary['latest_collected_at'] ?? ''),
            'latestDataDate' => (string)($dailySummary['end_date'] ?? ''),
            'dataRange' => (string)($dailySummary['data_range'] ?? ''),
            'storedRowCount' => (int)($dailySummary['row_count'] ?? 0),
            'failureReason' => $failureReason,
            'dataScope' => 'ota_channel',
            'reviewCollection' => [
                'status' => 'policy_disabled',
                'requiresExplicitAuthorization' => true,
                'defaultEnabled' => false,
                'scope' => 'ota_channel_review_summary',
            ],
            'profile' => [
                'statusCode' => (string)($profileRow['status_code'] ?? 'unconfigured'),
                'currentStatus' => (string)($profileRow['current_status'] ?? '未配置'),
                'nextAction' => (string)($profileRow['next_action'] ?? ''),
                'dataSourceId' => isset($profileRow['data_source_id']) ? (int)$profileRow['data_source_id'] : null,
                'profileExists' => (bool)($profileRow['profile_exists'] ?? false),
                'bindingCheckStatus' => (string)($profileRow['binding_check_status'] ?? 'unknown'),
            ],
            'sourceSummary' => [
                'configuredCount' => count($platformSources),
                'readyCount' => count(array_filter($platformSources, static fn(array $source): bool => in_array((string)($source['status'] ?? ''), ['ready', 'success'], true))),
                'latestSourceId' => isset($latestSource['id']) ? (int)$latestSource['id'] : null,
                'latestSourceStatus' => (string)($latestSource['status'] ?? $latestSource['last_sync_status'] ?? ''),
            ],
            'latestTask' => $latestTask ? [
                'id' => (int)($latestTask['id'] ?? 0),
                'status' => (string)($latestTask['status'] ?? ''),
                'message' => $this->redactCollectionStatusText((string)($latestTask['message'] ?? '')),
                'startedAt' => (string)($latestTask['started_at'] ?? ''),
                'finishedAt' => (string)($latestTask['finished_at'] ?? ''),
                'nextRetryAt' => (string)($latestTask['next_retry_at'] ?? ''),
            ] : null,
            'resourceStatuses' => $resourceStatuses,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $platformRows
     * @return array<string, mixed>
     */
    private function summarizeCollectionStatusPlatforms(array $platformRows): array
    {
        $rows = array_values($platformRows);
        $latestCollectedAt = '';
        $latestDataDate = '';
        $failureReasons = [];
        $hasData = false;
        $statuses = [];
        foreach ($rows as $row) {
            $hasData = $hasData || !empty($row['dataCollected']);
            $statuses[] = (string)($row['collectionStatus'] ?? 'not_loaded');
            foreach (['latestCollectedAt', 'latestDataDate'] as $key) {
                $value = (string)($row[$key] ?? '');
                if ($value !== '') {
                    if ($key === 'latestCollectedAt' && strcmp($value, $latestCollectedAt) > 0) {
                        $latestCollectedAt = $value;
                    }
                    if ($key === 'latestDataDate' && strcmp($value, $latestDataDate) > 0) {
                        $latestDataDate = $value;
                    }
                }
            }
            $reason = trim((string)($row['failureReason'] ?? ''));
            if ($reason !== '') {
                $failureReasons[] = $reason;
            }
        }

        $status = 'not_collected';
        if (in_array('failed', $statuses, true)) {
            $status = 'failed';
        } elseif (in_array('partial', $statuses, true)) {
            $status = 'partial';
        } elseif (in_array('stale', $statuses, true)) {
            $status = 'stale';
        } elseif ($hasData) {
            $status = 'collected';
        } elseif ($rows === []) {
            $status = 'not_loaded';
        }

        return [
            'collectionStatus' => $status,
            'dataCollected' => $hasData,
            'latestCollectedAt' => $latestCollectedAt,
            'latestDataDate' => $latestDataDate,
            'dataRange' => $this->mergeCollectionStatusDateRange($rows),
            'failureReason' => implode('；', array_values(array_unique($failureReasons))),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function mergeCollectionStatusDateRange(array $rows): string
    {
        $starts = [];
        $ends = [];
        foreach ($rows as $row) {
            $range = (string)($row['dataRange'] ?? '');
            if ($range === '') {
                continue;
            }
            $parts = preg_split('/\s+至\s+/', $range) ?: [];
            $starts[] = $parts[0] ?? $range;
            $ends[] = $parts[1] ?? ($parts[0] ?? $range);
        }
        $starts = array_values(array_filter($starts));
        $ends = array_values(array_filter($ends));
        if ($starts === [] || $ends === []) {
            return '';
        }
        sort($starts);
        sort($ends);
        $start = $starts[0];
        $end = $ends[count($ends) - 1];
        return $start === $end ? $start : $start . ' 至 ' . $end;
    }

    /**
     * @param array<string, mixed> $catalog
     * @return array<int, array<string, mixed>>
     */
    private function collectionStatusResourceRows(array $catalog, string $platform): array
    {
        $rows = [];
        foreach ((array)($catalog['resources'] ?? []) as $resource) {
            foreach ((array)($resource['platform_statuses'] ?? []) as $status) {
                if (strtolower((string)($status['platform'] ?? '')) !== $platform) {
                    continue;
                }
                $rows[] = [
                    'resource' => (string)($resource['resource'] ?? ''),
                    'dataType' => (string)($status['data_type'] ?? $resource['data_type'] ?? ''),
                    'collectionStatus' => (string)($status['collection_status'] ?? ''),
                    'etlStatus' => (string)($status['etl_status'] ?? ''),
                    'freshness' => (string)($status['freshness'] ?? ''),
                    'missingReason' => $this->redactCollectionStatusText((string)($status['missing_reason'] ?? '')),
                    'lastSyncTime' => (string)($status['last_sync_time'] ?? ''),
                    'latestDataDate' => (string)($status['latest_data_date'] ?? ''),
                    'storedRowCount' => (int)($status['stored_row_count'] ?? 0),
                ];
            }
        }
        return $rows;
    }

    /**
     * @param array<string, mixed> $profileStatus
     * @return array<string, mixed>
     */
    private function collectionStatusProfileRow(array $profileStatus, string $platform): array
    {
        foreach ((array)($profileStatus['items'] ?? []) as $item) {
            if (strtolower((string)($item['platform'] ?? '')) === $platform) {
                return is_array($item) ? $item : [];
            }
        }
        return [];
    }

    /**
     * @param array<int, array<string, mixed>> $tasks
     * @return array<string, mixed>|null
     */
    private function latestCollectionStatusTask(array $tasks): ?array
    {
        $latest = null;
        $latestTime = '';
        foreach ($tasks as $task) {
            $time = (string)($task['finished_at'] ?? $task['started_at'] ?? $task['update_time'] ?? $task['create_time'] ?? '');
            if ($latest === null || strcmp($time, $latestTime) > 0) {
                $latest = $task;
                $latestTime = $time;
            }
        }
        return $latest;
    }

    /**
     * @param array<int, array<string, mixed>> $sources
     * @return array<string, mixed>|null
     */
    private function latestCollectionStatusSource(array $sources): ?array
    {
        $latest = null;
        $latestTime = '';
        foreach ($sources as $source) {
            $time = (string)($source['last_sync_time'] ?? $source['update_time'] ?? $source['create_time'] ?? '');
            if ($latest === null || strcmp($time, $latestTime) > 0) {
                $latest = $source;
                $latestTime = $time;
            }
        }
        return $latest;
    }

    /**
     * @param array<string, mixed>|null $latestTask
     * @param array<int, array<string, mixed>> $resourceStatuses
     */
    private function resolveCollectionStatus(bool $dataCollected, ?array $latestTask, array $resourceStatuses): string
    {
        $taskStatus = (string)($latestTask['status'] ?? '');
        if ($taskStatus === 'running') {
            return 'collecting';
        }
        if ($taskStatus === 'failed') {
            return 'failed';
        }
        if ($taskStatus === 'partial_success') {
            return 'partial';
        }
        $resourceCollections = array_map(static fn(array $row): string => (string)($row['collectionStatus'] ?? ''), $resourceStatuses);
        if (in_array('failed', $resourceCollections, true)) {
            return 'failed';
        }
        if (in_array('partial_success', $resourceCollections, true)) {
            return 'partial';
        }
        if (in_array('stale', $resourceCollections, true)) {
            return 'stale';
        }
        return $dataCollected ? 'collected' : 'not_collected';
    }

    /**
     * @param array<string, mixed>|null $latestTask
     * @param array<string, mixed>|null $latestSource
     * @param array<string, mixed> $profileRow
     * @param array<int, array<string, mixed>> $resourceStatuses
     */
    private function collectionStatusFailureReason(string $collectionStatus, ?array $latestTask, ?array $latestSource, array $profileRow, array $resourceStatuses, bool $dataCollected): string
    {
        $taskMessage = $this->redactCollectionStatusText((string)($latestTask['message'] ?? ''));
        if (in_array($collectionStatus, ['failed', 'partial'], true) && $taskMessage !== '') {
            return $taskMessage;
        }

        $profileStatus = (string)($profileRow['status_code'] ?? '');
        if (in_array($profileStatus, ['waiting_login', 'login_expired'], true)) {
            return $this->redactCollectionStatusText((string)($profileRow['next_action'] ?? $profileRow['current_status'] ?? 'platform_login_required'));
        }

        $sourceError = $this->redactCollectionStatusText((string)($latestSource['last_error'] ?? ''));
        if ($sourceError !== '') {
            return $sourceError;
        }

        foreach ($resourceStatuses as $resourceStatus) {
            $reason = $this->redactCollectionStatusText((string)($resourceStatus['missingReason'] ?? ''));
            if ($reason !== '') {
                return $reason;
            }
        }

        return $dataCollected ? '' : 'no_collected_ota_rows';
    }

    private function redactCollectionStatusText(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        $text = preg_replace('/(cookie|token|authorization|spidertoken|mtgsig|password|secret)\s*[:=]\s*[^\\s,;]+/i', '$1=[redacted]', $text) ?? $text;
        $text = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted]', $text) ?? $text;
        $text = preg_replace('/\b1[3-9]\d{9}\b/', '[redacted]', $text) ?? $text;
        $text = preg_replace('/\b\d{10,}\b/', '[redacted]', $text) ?? $text;
        return mb_substr($text, 0, 240);
    }

    /**
     * @return array<string, bool>
     */
    private function collectionStatusTableColumns(string $table): array
    {
        try {
            $rows = Db::query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
            return array_fill_keys(array_column($rows, 'Field'), true);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function positiveCollectionStatusInt($value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value) || (int)$value <= 0) {
            return null;
        }
        return (int)$value;
    }

    /**
     * @return array<string, mixed>
     */
    private function browserAssistCaptureRequestData(): array
    {
        $payload = $this->requestData();
        $file = $this->request->file('file')
            ?: $this->request->file('capture_file')
            ?: $this->request->file('import_file');
        if (!$file) {
            return $payload;
        }

        $path = $file->getPathname();
        if (!is_file($path)) {
            throw new \RuntimeException('上传的采集文件不存在。', 422);
        }
        if ((int)filesize($path) > 5 * 1024 * 1024) {
            throw new \RuntimeException('采集文件超过5MB。', 422);
        }

        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            throw new \RuntimeException('采集文件为空。', 422);
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('采集文件必须是JSON对象。', 422);
        }

        $payload['capture'] = $decoded;
        return $payload;
    }

}
