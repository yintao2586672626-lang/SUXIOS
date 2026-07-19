<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\model\OperationLog;
use app\service\OtaBrowserAssistImportService;
use app\service\OtaCapabilityStateService;
use app\service\OtaCollectionQualityStateService;
use app\service\OtaProfileSessionProofService;
use app\service\PlatformDataSyncService;
use DateTimeImmutable;
use DateTimeZone;
use think\Response;
use think\facade\Db;

trait PlatformDataSourceConcern
{
    private const COLLECTION_STATUS_TIMEZONE = 'Asia/Shanghai';

    private const COLLECTION_STATUS_REQUIRED_TRAFFIC_METRICS = [
        'list_exposure',
        'detail_exposure',
        'flow_rate',
        'order_filling_num',
        'order_submit_num',
    ];

    /** @return array<int, string> */
    private function collectionStatusRequiredTrafficMetrics(string $platform = ''): array
    {
        return strtolower(trim($platform)) === 'meituan'
            ? array_slice(self::COLLECTION_STATUS_REQUIRED_TRAFFIC_METRICS, 0, 3)
            : self::COLLECTION_STATUS_REQUIRED_TRAFFIC_METRICS;
    }

    private const COLLECTION_STATUS_FORECAST_PERIODS = [
        'next_7_days',
        'next_30_days',
        'forecast',
        'future_forecast',
    ];

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
            $targetDate = $this->collectionStatusTargetDate($requestData);
            $filters = ['system_hotel_id' => (int)$systemHotelId];
            if (in_array($platform, ['ctrip', 'meituan'], true)) {
                $filters['platform'] = $platform;
            }

            $catalog = $service->collectionResourceCatalog($this->currentUser, $filters);
            $sources = $service->listDataSources($this->currentUser, $filters);
            $tasks = $service->listSyncTasks($this->currentUser, array_merge($filters, ['limit' => 30]));
            $dailySummary = $this->collectionStatusDailySummary((int)$systemHotelId, $platform, $targetDate);
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
                'targetDate' => $targetDate,
                'currentHotelName' => (string)($hotel['name'] ?? ''),
                'permissionStatus' => $permissionStatus,
                'fetchPermissionStatus' => $fetchPermissionStatus,
                'dataScope' => 'ota_channel',
                'reviewCollectionStatus' => 'aggregate_enabled',
                'requiresExplicitReviewAuthorization' => false,
                'context' => [
                    'tokenStatus' => 'valid',
                    'hotelId' => (int)$systemHotelId,
                    'tenantId' => $this->positiveCollectionStatusInt($hotel['tenant_id'] ?? $this->currentUser->tenant_id ?? null),
                    'platform' => $platform,
                    'targetDate' => $targetDate,
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
            $source = Db::name('platform_data_sources')
                ->field('id,system_hotel_id,platform,ingestion_method,config_json')
                ->where('id', $id)
                ->find();
            if (is_array($source)) {
                $safeSources = $this->sanitizeBrowserProfileSourcesForSharedCache([$source]);
                $source = $safeSources[0] ?? [];
            }
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
            $result = $service->syncDataSource(
                $this->currentUser,
                (int)$id,
                $this->sanitizePublicDataSourceSyncOptions($this->requestData())
            );
            OperationLog::record('online_data', 'sync_data_source', '同步平台数据源ID: ' . $id . '，状态: ' . $result['status'], $this->currentUser->id, null);
            return $this->platformDataTaskResponse($result, '同步');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getCode()));
        } catch (\Throwable $e) {
            return $this->error('同步数据源失败: ' . $e->getMessage(), 500);
        }
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    private function sanitizePublicDataSourceSyncOptions(array $options): array
    {
        unset(
            $options['trigger_type'],
            $options['triggerType'],
            $options['interactive_browser'],
            $options['interactiveBrowser']
        );
        $options['trigger_type'] = 'manual';
        $options['interactive_browser'] = false;
        return $options;
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
            return $this->platformDataTaskResponse($result, '导入');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getCode()));
        } catch (\Throwable $e) {
            return $this->error('导入数据失败: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Only a service result explicitly marked success may be exposed as an API
     * success. Partial or failed tasks keep their diagnostic payload, while the
     * non-200 response prevents the UI from announcing a completed write.
     */
    private function platformDataTaskResponse(array $result, string $action): Response
    {
        $status = strtolower(trim((string)($result['status'] ?? 'unknown')));
        if ($status === 'success') {
            return $this->success($result, $action . '已完成并通过业务校验');
        }

        if ($status === 'partial_success') {
            return json([
                'code' => 422,
                'message' => $action . '仅部分完成，未确认完整入库；请查看任务状态、数据缺口和回读结果。',
                'data' => $result,
            ], 422);
        }

        return json([
            'code' => 500,
            'message' => $action . '失败；请查看任务状态和失败原因。',
            'data' => $result,
        ], 500);
    }

    public function importBrowserAssistCapture(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        try {
            $payload = $this->browserAssistCaptureRequestData();
            $service = new OtaBrowserAssistImportService();
            $result = $service->importCapture($this->currentUser, $payload);
            OperationLog::record('online_data', 'import_browser_assist_capture', '导入浏览器辅助采集数据，状态: ' . ($result['status'] ?? 'unknown') . '，分包: ' . $result['package_count'] . '，已确认入库: ' . $result['saved_count'], $this->currentUser->id, null);
            return $this->platformDataTaskResponse($result, '浏览器辅助采集导入');
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
            'reviewCollectionStatus' => 'aggregate_enabled',
            'requiresExplicitReviewAuthorization' => false,
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
    private function collectionStatusDailySummary(int $systemHotelId, string $platform, string $targetDate): array
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
            $query->where('data_date', '<=', $this->collectionStatusShanghaiToday());
            $query->where('system_hotel_id', $systemHotelId);
            $this->collectionStatusApplyObservedPeriodScope($query, $columns);
            $rows = $query->group($sourceColumn)->select()->toArray();
        } catch (\Throwable $e) {
            return [];
        }

        $targetStats = $this->collectionStatusTargetDateStats($systemHotelId, $platforms, $sourceColumn, $columns, $targetDate);
        $summary = [];
        foreach ($platforms as $itemPlatform) {
            $summary[$itemPlatform] = array_merge([
                'row_count' => 0,
                'start_date' => '',
                'end_date' => '',
                'latest_collected_at' => '',
                'data_range' => '',
            ], $targetStats[$itemPlatform] ?? [
                'target_date' => $targetDate,
                'target_date_rows' => 0,
                'target_date_traffic_rows' => 0,
                'target_date_data_types' => [],
                'target_date_traffic_field_fact_ready_count' => 0,
                'target_date_traffic_field_fact_missing_count' => 0,
                'target_date_traffic_field_fact_status' => 'not_loaded',
                'target_date_traffic_verified_metric_keys' => [],
                'target_date_traffic_missing_metric_keys' => $this->collectionStatusRequiredTrafficMetrics($itemPlatform),
                'target_date_traffic_ready_data_source_ids' => [],
            ]);
        }
        foreach ($rows as $row) {
            $itemPlatform = strtolower((string)($row['platform'] ?? ''));
            if (!in_array($itemPlatform, ['ctrip', 'meituan'], true)) {
                continue;
            }
            $start = (string)($row['start_date'] ?? '');
            $end = (string)($row['end_date'] ?? '');
            $summary[$itemPlatform] = array_merge($summary[$itemPlatform] ?? [], [
                'row_count' => (int)($row['row_count'] ?? 0),
                'start_date' => $start,
                'end_date' => $end,
                'latest_collected_at' => (string)($row['latest_collected_at'] ?? ''),
                'data_range' => $start !== '' && $end !== '' ? ($start === $end ? $start : $start . ' 至 ' . $end) : '',
            ]);
        }

        return $summary;
    }

    /**
     * @param array<int, string> $platforms
     * @param array<string, bool> $columns
     * @return array<string, array<string, mixed>>
     */
    private function collectionStatusTargetDateStats(int $systemHotelId, array $platforms, string $sourceColumn, array $columns, string $targetDate): array
    {
        $stats = [];
        foreach ($platforms as $platform) {
            $stats[$platform] = [
                'target_date' => $targetDate,
                'target_date_rows' => 0,
                'target_date_traffic_rows' => 0,
                'target_date_data_types' => [],
                'target_date_traffic_field_fact_ready_count' => 0,
                'target_date_traffic_field_fact_missing_count' => 0,
                'target_date_traffic_field_fact_status' => 'not_loaded',
                'target_date_traffic_verified_metric_keys' => [],
                'target_date_traffic_missing_metric_keys' => $this->collectionStatusRequiredTrafficMetrics((string)$platform),
                'target_date_traffic_ready_data_source_ids' => [],
            ];
        }
        if ($targetDate === '' || !isset($columns['data_date'], $columns['system_hotel_id'])) {
            return $stats;
        }

        $fields = [$sourceColumn . ' AS platform'];
        $fields[] = isset($columns['id']) ? 'id' : '0 AS id';
        $fields[] = isset($columns['data_type']) ? 'data_type' : "'' AS data_type";
        $fields[] = isset($columns['data_period']) ? 'data_period' : "'' AS data_period";
        $fields[] = isset($columns['data_source_id']) ? 'data_source_id' : '0 AS data_source_id';
        $fields[] = isset($columns['raw_data']) ? 'raw_data' : "'' AS raw_data";
        try {
            $query = Db::name('online_daily_data')
                ->field(implode(',', $fields))
                ->whereIn($sourceColumn, $platforms)
                ->where('system_hotel_id', $systemHotelId)
                ->where('data_date', $targetDate);
            $this->collectionStatusApplyObservedPeriodScope($query, $columns);
            if (isset($columns['id'])) {
                $query->order('id', 'asc');
            }
            $rows = $query->select()->toArray();
        } catch (\Throwable $e) {
            return $stats;
        }

        $sourceMetricKeys = [];
        foreach ($rows as $row) {
            $platform = strtolower((string)($row['platform'] ?? ''));
            if (!isset($stats[$platform]) || $this->collectionStatusRowIsForecast($row)) {
                continue;
            }
            $dataType = strtolower(trim((string)($row['data_type'] ?? '')));
            $stats[$platform]['target_date_rows']++;
            if ($dataType !== '') {
                $stats[$platform]['target_date_data_types'][$dataType] = true;
            }
            if ($dataType === 'traffic') {
                $stats[$platform]['target_date_traffic_rows']++;
                $closure = $this->collectionStatusTrafficFieldFactClosure($row['raw_data'] ?? '', $platform);
                $dataSourceId = (int)($row['data_source_id'] ?? 0);
                if ($dataSourceId <= 0) {
                    $dataSourceId = (int)($closure['data_source_id'] ?? 0);
                }
                if ($dataSourceId > 0) {
                    foreach ((array)($closure['verified_metric_keys'] ?? []) as $metricKey) {
                        $sourceMetricKeys[$platform][$dataSourceId][(string)$metricKey] = true;
                    }
                }
                if (($closure['complete'] ?? false) === true && $dataSourceId > 0) {
                    $stats[$platform]['target_date_traffic_field_fact_ready_count']++;
                } else {
                    $stats[$platform]['target_date_traffic_field_fact_missing_count']++;
                }
            }
        }

        foreach ($stats as $platform => $row) {
            $requiredTrafficMetrics = $this->collectionStatusRequiredTrafficMetrics((string)$platform);
            $trafficRows = (int)($row['target_date_traffic_rows'] ?? 0);
            $bestVerifiedMetricKeys = [];
            $readyDataSourceIds = [];
            foreach ((array)($sourceMetricKeys[$platform] ?? []) as $dataSourceId => $metricMap) {
                $verifiedMetricKeys = array_values(array_intersect(
                    $requiredTrafficMetrics,
                    array_keys((array)$metricMap)
                ));
                if (count($verifiedMetricKeys) > count($bestVerifiedMetricKeys)) {
                    $bestVerifiedMetricKeys = $verifiedMetricKeys;
                }
                if (count($verifiedMetricKeys) === count($requiredTrafficMetrics)) {
                    $readyDataSourceIds[] = (int)$dataSourceId;
                }
            }
            $missingMetricKeys = array_values(array_diff($requiredTrafficMetrics, $bestVerifiedMetricKeys));
            $stats[$platform]['target_date_data_types'] = array_keys($row['target_date_data_types'] ?? []);
            $stats[$platform]['target_date_traffic_verified_metric_keys'] = $bestVerifiedMetricKeys;
            $stats[$platform]['target_date_traffic_missing_metric_keys'] = $missingMetricKeys;
            $stats[$platform]['target_date_traffic_ready_data_source_ids'] = array_values(array_unique(array_filter($readyDataSourceIds)));
            $stats[$platform]['target_date_traffic_field_fact_ready_count'] = count($readyDataSourceIds);
            $stats[$platform]['target_date_traffic_field_fact_missing_count'] = count($missingMetricKeys);
            $stats[$platform]['target_date_traffic_field_fact_status'] = $trafficRows <= 0
                ? 'not_loaded'
                : ($readyDataSourceIds !== [] ? 'ready' : ($bestVerifiedMetricKeys !== [] ? 'partial' : 'missing'));
        }

        return $stats;
    }

    private function collectionStatusRawHasFieldFacts($rawData): bool
    {
        return ($this->collectionStatusTrafficFieldFactClosure($rawData)['complete'] ?? false) === true;
    }

    /**
     * @return array{complete: bool, verified_metric_keys: array<int, string>, missing_metric_keys: array<int, string>, data_source_id: int}
     */
    private function collectionStatusTrafficFieldFactClosure($rawData, string $platform = ''): array
    {
        $requiredTrafficMetrics = $this->collectionStatusRequiredTrafficMetrics($platform);
        if (is_string($rawData)) {
            $decoded = json_decode($rawData, true);
            $rawData = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($rawData) || $this->collectionStatusRowIsForecast($rawData)) {
            return [
                'complete' => false,
                'verified_metric_keys' => [],
                'missing_metric_keys' => $requiredTrafficMetrics,
                'data_source_id' => 0,
            ];
        }

        $verified = [];
        $facts = is_array($rawData['field_facts'] ?? null) ? $rawData['field_facts'] : [];
        foreach ($facts as $fact) {
            if (!is_array($fact)) {
                continue;
            }
            $metricKey = strtolower(trim((string)($fact['metric_key'] ?? '')));
            if (!in_array($metricKey, $requiredTrafficMetrics, true)) {
                continue;
            }
            $sourcePath = trim((string)($fact['source_path'] ?? ''));
            $storageField = trim((string)($fact['storage_field'] ?? ''));
            if (strtolower(trim((string)($fact['status'] ?? ''))) !== 'captured'
                || !$this->collectionStatusSourcePathIsStructured($sourcePath)
                || $storageField !== 'online_daily_data.' . $metricKey
                || ($fact['stored_value_present'] ?? null) !== true
                || !$this->collectionStatusCaptureEvidenceIsDesensitized($fact['capture_evidence'] ?? null)
            ) {
                continue;
            }
            $verified[$metricKey] = true;
        }

        $verifiedMetricKeys = array_values(array_intersect(
            $requiredTrafficMetrics,
            array_keys($verified)
        ));
        $missingMetricKeys = array_values(array_diff($requiredTrafficMetrics, $verifiedMetricKeys));

        return [
            'complete' => $missingMetricKeys === [],
            'verified_metric_keys' => $verifiedMetricKeys,
            'missing_metric_keys' => $missingMetricKeys,
            'data_source_id' => max(0, (int)($rawData['data_source_id'] ?? 0)),
        ];
    }

    private function collectionStatusSourcePathIsStructured(string $sourcePath): bool
    {
        $sourcePath = trim($sourcePath);
        return $sourcePath !== ''
            && (str_starts_with($sourcePath, '$.')
                || str_starts_with($sourcePath, '/')
                || str_contains($sourcePath, '.')
                || str_contains($sourcePath, '['));
    }

    private function collectionStatusCaptureEvidenceIsDesensitized($evidence): bool
    {
        if (!is_array($evidence) || $evidence === []) {
            return false;
        }
        $traceId = trim((string)($evidence['source_trace_id'] ?? $evidence['_source_trace_id'] ?? ''));
        $sourceUrlHash = trim((string)($evidence['source_url_hash'] ?? $evidence['_source_url_hash'] ?? $evidence['url_hash'] ?? $evidence['_url_hash'] ?? ''));
        if ($traceId === '' || preg_match('/^[a-f0-9]{64}$/iD', $sourceUrlHash) !== 1) {
            return false;
        }

        foreach ($evidence as $key => $value) {
            $normalizedKey = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '_', (string)$key), '_'));
            if (preg_match('/(?:^|_)(?:cookie|authorization|token|secret|password|headers?)(?:_|$)/', $normalizedKey) === 1
                || (str_ends_with($normalizedKey, '_url') && !str_ends_with($normalizedKey, '_url_hash'))
                || is_array($value)
                || is_object($value)
            ) {
                return false;
            }
            $text = is_scalar($value) ? trim((string)$value) : '';
            if ($text !== '' && (preg_match('/https?:\/\//i', $text) === 1
                || preg_match('/(?:cookie|authorization|bearer|token|password|secret)\s*[:=]/i', $text) === 1)
            ) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $row */
    private function collectionStatusRowIsForecast(array $row): bool
    {
        $dataType = strtolower(trim((string)($row['data_type'] ?? '')));
        $dataPeriod = strtolower(trim((string)($row['data_period'] ?? '')));
        return in_array($dataType, ['traffic_forecast', 'forecast'], true)
            || in_array($dataPeriod, self::COLLECTION_STATUS_FORECAST_PERIODS, true);
    }

    /**
     * @param mixed $query
     * @param array<string, bool> $columns
     */
    private function collectionStatusApplyObservedPeriodScope($query, array $columns): void
    {
        if (isset($columns['data_type'])) {
            $query->whereNotIn('data_type', ['traffic_forecast', 'forecast']);
        }
        if (isset($columns['data_period'])) {
            $query->where(function ($query): void {
                $query->whereNull('data_period')
                    ->whereOr('data_period', 'not in', ['next_7_days', 'next_30_days', 'forecast', 'future_forecast']);
            });
        }
    }

    /**
     * @param array<int, array<string, mixed>> $platformSources
     * @param array<int, int> $readyTrafficDataSourceIds
     * @return array{required: bool, verified: bool, same_source_verified: bool}
     */
    private function collectionStatusProfileSessionState(array $platformSources, array $readyTrafficDataSourceIds): array
    {
        $sourcesById = [];
        $profileSourceIds = [];
        foreach ($platformSources as $source) {
            $sourceId = (int)($source['id'] ?? 0);
            if ($sourceId <= 0) {
                continue;
            }
            $sourcesById[$sourceId] = $source;
            $method = strtolower(trim((string)($source['ingestion_method'] ?? '')));
            if (in_array($method, ['browser_profile', 'profile_browser'], true)) {
                $profileSourceIds[$sourceId] = true;
            }
        }

        if ($profileSourceIds === []) {
            return ['required' => false, 'verified' => false, 'same_source_verified' => false];
        }

        $relevantProfileSourceIds = [];
        $hasUnknownReadySource = false;
        foreach ($readyTrafficDataSourceIds as $sourceId) {
            if (!isset($sourcesById[$sourceId])) {
                $hasUnknownReadySource = true;
                continue;
            }
            if (isset($profileSourceIds[$sourceId])) {
                $relevantProfileSourceIds[] = $sourceId;
            }
        }

        if ($relevantProfileSourceIds === []) {
            if ($readyTrafficDataSourceIds !== [] && !$hasUnknownReadySource) {
                return ['required' => false, 'verified' => false, 'same_source_verified' => false];
            }
            return ['required' => true, 'verified' => false, 'same_source_verified' => false];
        }

        $proofService = new OtaProfileSessionProofService();
        foreach (array_values(array_unique($relevantProfileSourceIds)) as $sourceId) {
            $source = $sourcesById[$sourceId];
            if (!array_key_exists('config_json', $source) && is_array($source['config'] ?? null)) {
                $encoded = json_encode($source['config'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $source['config_json'] = is_string($encoded) ? $encoded : '';
            }
            if ($proofService->isCurrentVerified($source)) {
                return ['required' => true, 'verified' => true, 'same_source_verified' => true];
            }
        }

        return ['required' => true, 'verified' => false, 'same_source_verified' => false];
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
        $requiredTrafficMetrics = $this->collectionStatusRequiredTrafficMetrics($platform);
        $platformSources = array_values(array_filter($sources, static fn(array $source): bool => strtolower((string)($source['platform'] ?? '')) === $platform));
        $platformTasks = array_values(array_filter($tasks, static fn(array $task): bool => strtolower((string)($task['platform'] ?? '')) === $platform));
        $latestTask = $this->latestCollectionStatusTask($platformTasks);
        $latestTaskStatus = PlatformDataSyncService::effectiveSyncTaskStatus($latestTask);
        $latestTaskRawStatus = (string)($latestTask['status'] ?? '');
        $latestTaskStale = PlatformDataSyncService::isStaleRunningSyncTask($latestTask);
        $latestSource = $this->latestCollectionStatusSource($platformSources);
        $resourceStatuses = $this->collectionStatusResourceRows($catalog, $platform);
        $profileRow = $this->collectionStatusProfileRow($profileStatus, $platform);
        $capabilityReport = (new OtaCapabilityStateService())->evaluate(
            (string)($profileRow['status_code'] ?? ''),
            $resourceStatuses
        );
        $latestTaskStats = $latestTask ? $this->collectionStatusDecodeJson($latestTask['stats_json'] ?? '') : [];
        $syncDiagnostics = is_array($latestTaskStats['sync_diagnostics'] ?? null) ? $latestTaskStats['sync_diagnostics'] : [];
        $publicSyncDiagnostics = $this->collectionStatusPublicSyncDiagnostics($syncDiagnostics, $latestTaskRawStatus);
        $taskCollectionQuality = $this->collectionStatusTaskCollectionQuality($latestTaskStats['collection_quality'] ?? null);
        $targetDate = (string)($dailySummary['target_date'] ?? $syncDiagnostics['target_date'] ?? '');
        $targetDateRows = (int)($dailySummary['target_date_rows'] ?? 0);
        $targetDateTrafficRows = (int)($dailySummary['target_date_traffic_rows'] ?? 0);
        $fieldFactStatus = (string)($dailySummary['target_date_traffic_field_fact_status'] ?? 'not_loaded');
        $verifiedTrafficMetricKeys = array_values(array_intersect(
            $requiredTrafficMetrics,
            array_map(
                static fn($value): string => strtolower(trim((string)$value)),
                (array)($dailySummary['target_date_traffic_verified_metric_keys'] ?? [])
            )
        ));
        $trafficMetricClosureReady = count($verifiedTrafficMetricKeys) === count($requiredTrafficMetrics);
        if ($targetDateTrafficRows > 0 && $fieldFactStatus === 'ready' && !$trafficMetricClosureReady) {
            $fieldFactStatus = $verifiedTrafficMetricKeys === [] ? 'missing' : 'partial';
        }
        $readyTrafficDataSourceIds = array_values(array_unique(array_filter(array_map(
            static fn($value): int => max(0, (int)$value),
            (array)($dailySummary['target_date_traffic_ready_data_source_ids'] ?? [])
        ))));
        $profileSession = $this->collectionStatusProfileSessionState($platformSources, $readyTrafficDataSourceIds);
        $hasStoredData = (int)($dailySummary['row_count'] ?? 0) > 0;
        $dataCollected = $targetDateTrafficRows > 0
            && $fieldFactStatus === 'ready'
            && $trafficMetricClosureReady
            && (($profileSession['required'] ?? false) !== true || ($profileSession['same_source_verified'] ?? false) === true);
        $effectiveDailySummary = $dailySummary;
        $effectiveDailySummary['target_date_traffic_field_fact_status'] = $fieldFactStatus;
        $collectionStatus = $this->resolveCollectionStatus($dataCollected, $hasStoredData, $latestTask, $resourceStatuses, $effectiveDailySummary, $profileRow);
        $failureReason = $this->collectionStatusFailureReason($collectionStatus, $latestTask, $latestSource, $profileRow, $resourceStatuses, $dataCollected, $effectiveDailySummary, $publicSyncDiagnostics);
        $bindingContract = is_array($profileRow['binding_contract'] ?? null) ? $profileRow['binding_contract'] : [];
        $quality = (new OtaCollectionQualityStateService())->evaluate([
            'platform' => $platform,
            'binding_contract_status' => $bindingContract['status'] ?? '',
            'binding_check_status' => $profileRow['binding_check_status'] ?? '',
            'binding_missing_requirements' => $bindingContract['missing_requirements'] ?? [],
            'profile_status' => $profileRow['status_code'] ?? '',
            'collection_status' => $collectionStatus,
            'target_date' => $targetDate,
            'latest_data_date' => $dailySummary['end_date'] ?? '',
            'latest_collected_at' => $dailySummary['latest_collected_at'] ?? '',
            'target_date_rows' => $targetDateRows,
            'target_date_traffic_rows' => $targetDateTrafficRows,
            'field_fact_status' => $fieldFactStatus,
            'verified_traffic_metric_keys' => $verifiedTrafficMetricKeys,
            'profile_session_proof_required' => $profileSession['required'] ?? false,
            'profile_session_verified' => $profileSession['verified'] ?? false,
            'profile_session_same_source' => $profileSession['same_source_verified'] ?? false,
            'has_stored_data' => $hasStoredData,
            'source_count' => count($platformSources),
            'failure_reason' => $failureReason,
        ]);

        return [
            'platform' => $platform,
            'platformName' => $platform === 'meituan' ? '美团' : '携程',
            'platformLoginStatus' => (string)($profileRow['status_code'] ?? 'unconfigured'),
            'platformLoginText' => (string)($profileRow['current_status'] ?? '未配置'),
            'permissionStatus' => 'allowed',
            'dataCollected' => $dataCollected,
            'hasStoredData' => $hasStoredData,
            'collectionStatus' => $collectionStatus,
            'latestCollectedAt' => (string)($dailySummary['latest_collected_at'] ?? ''),
            'latestDataDate' => (string)($dailySummary['end_date'] ?? ''),
            'dataRange' => (string)($dailySummary['data_range'] ?? ''),
            'storedRowCount' => (int)($dailySummary['row_count'] ?? 0),
            'targetDate' => $targetDate,
            'targetDateRows' => $targetDateRows,
            'targetDateTrafficRows' => $targetDateTrafficRows,
            'targetDateDataTypes' => array_values((array)($dailySummary['target_date_data_types'] ?? [])),
            'fieldFactsReady' => (int)($dailySummary['target_date_traffic_field_fact_ready_count'] ?? 0),
            'fieldFactsMissing' => (int)($dailySummary['target_date_traffic_field_fact_missing_count'] ?? 0),
            'fieldFactStatus' => $fieldFactStatus,
            'verifiedTrafficMetricKeys' => $verifiedTrafficMetricKeys,
            'missingTrafficMetricKeys' => array_values(array_diff($requiredTrafficMetrics, $verifiedTrafficMetricKeys)),
            'failureReason' => $failureReason,
            'quality' => $quality,
            'capabilityReport' => $capabilityReport,
            'dataScope' => 'ota_channel',
            'reviewCollection' => [
                'status' => 'aggregate_enabled',
                'requiresExplicitAuthorization' => false,
                'defaultEnabled' => true,
                'scope' => 'ota_channel_review_summary',
                'privacyBoundary' => 'aggregate_metrics_only_no_review_text',
            ],
            'profile' => [
                'statusCode' => (string)($profileRow['status_code'] ?? 'unconfigured'),
                'currentStatus' => (string)($profileRow['current_status'] ?? '未配置'),
                'nextAction' => (string)($profileRow['next_action'] ?? ''),
                'dataSourceId' => isset($profileRow['data_source_id']) ? (int)$profileRow['data_source_id'] : null,
                'profileExists' => (bool)($profileRow['profile_exists'] ?? false),
                'bindingCheckStatus' => (string)($profileRow['binding_check_status'] ?? 'unknown'),
                'currentSessionProofRequired' => (bool)($profileSession['required'] ?? false),
                'currentSessionVerified' => (bool)($profileSession['verified'] ?? false),
                'currentSessionSameSource' => (bool)($profileSession['same_source_verified'] ?? false),
            ],
            'sourceSummary' => [
                'configuredCount' => count($platformSources),
                'readyCount' => count(array_filter($platformSources, static fn(array $source): bool => in_array((string)($source['status'] ?? ''), ['ready', 'success'], true))),
                'latestSourceId' => isset($latestSource['id']) ? (int)$latestSource['id'] : null,
                'latestSourceStatus' => (string)($latestSource['status'] ?? $latestSource['last_sync_status'] ?? ''),
                'lastError' => $this->collectionStatusSafeErrorMessage((string)($latestSource['last_sync_status'] ?? $latestSource['status'] ?? ''), (string)($latestSource['last_error'] ?? '')),
            ],
            'latestTask' => $latestTask ? [
                'id' => (int)($latestTask['id'] ?? 0),
                'status' => $latestTaskStatus,
                'rawStatus' => $latestTaskRawStatus,
                'isStaleRunning' => $latestTaskStale,
                'staleAgeSeconds' => PlatformDataSyncService::syncTaskAgeSeconds($latestTask),
                'message' => $this->collectionStatusSafeTaskMessage($latestTaskRawStatus, (string)($latestTask['message'] ?? '')),
                'syncDiagnostics' => $publicSyncDiagnostics,
                'collectionQuality' => $taskCollectionQuality,
                'targetDate' => (string)($syncDiagnostics['target_date'] ?? ''),
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
        if (in_array('permission_denied', $statuses, true)) {
            $status = 'permission_denied';
        } elseif (in_array('hotel_mismatch', $statuses, true)) {
            $status = 'hotel_mismatch';
        } elseif (in_array('login_expired', $statuses, true)) {
            $status = 'login_expired';
        } elseif (in_array('stale_running', $statuses, true)) {
            $status = 'stale_running';
        } elseif (in_array('failed', $statuses, true)) {
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
                    'missingReason' => $this->collectionStatusSafeErrorMessage((string)($status['collection_status'] ?? ''), (string)($status['missing_reason'] ?? '')),
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
    private function resolveCollectionStatus(bool $dataCollected, bool $hasStoredData, ?array $latestTask, array $resourceStatuses, array $dailySummary, array $profileRow): string
    {
        $taskStatus = PlatformDataSyncService::effectiveSyncTaskStatus($latestTask);
        if ($taskStatus === 'stale_running') {
            return 'stale_running';
        }
        if ($taskStatus === 'running') {
            return 'collecting';
        }
        $profileStatus = (string)($profileRow['status_code'] ?? '');
        if (!$dataCollected && in_array($profileStatus, ['permission_denied', 'no_permission', 'unauthorized'], true)) {
            return 'permission_denied';
        }
        if (!$dataCollected && $profileStatus === 'hotel_mismatch') {
            return 'hotel_mismatch';
        }
        if (!$dataCollected && in_array($profileStatus, ['waiting_login', 'login_expired'], true)) {
            return 'login_expired';
        }
        if ($taskStatus === 'failed') {
            return 'failed';
        }
        $targetDateRows = (int)($dailySummary['target_date_rows'] ?? 0);
        $targetTrafficRows = (int)($dailySummary['target_date_traffic_rows'] ?? 0);
        $fieldFactStatus = (string)($dailySummary['target_date_traffic_field_fact_status'] ?? 'not_loaded');
        if ($targetTrafficRows > 0 && $fieldFactStatus !== 'ready') {
            return 'partial';
        }
        if ($targetDateRows > 0 && $targetTrafficRows <= 0) {
            return 'partial';
        }
        if ($taskStatus === 'partial_success') {
            return 'partial';
        }
        $resourceCollections = array_map(static fn(array $row): string => (string)($row['collectionStatus'] ?? ''), $resourceStatuses);
        if (in_array('stale_running', $resourceCollections, true)) {
            return 'stale_running';
        }
        if (in_array('failed', $resourceCollections, true)) {
            return 'failed';
        }
        if (in_array('partial_success', $resourceCollections, true)) {
            return 'partial';
        }
        if (in_array('stale', $resourceCollections, true)) {
            return 'stale';
        }
        if ($dataCollected) {
            return 'collected';
        }
        return $hasStoredData ? 'stale' : 'not_collected';
    }

    /**
     * @param array<string, mixed>|null $latestTask
     * @param array<string, mixed>|null $latestSource
     * @param array<string, mixed> $profileRow
     * @param array<int, array<string, mixed>> $resourceStatuses
     */
    private function collectionStatusFailureReason(string $collectionStatus, ?array $latestTask, ?array $latestSource, array $profileRow, array $resourceStatuses, bool $dataCollected, array $dailySummary = [], array $syncDiagnostics = []): string
    {
        $taskMessage = $this->collectionStatusSafeErrorMessage((string)($latestTask['status'] ?? ''), (string)($latestTask['message'] ?? ''));
        $profileStatus = (string)($profileRow['status_code'] ?? '');
        if ($collectionStatus === 'stale_running') {
            return $taskMessage !== '' ? $taskMessage : 'stale_running_task';
        }
        if (in_array($profileStatus, ['permission_denied', 'no_permission', 'unauthorized'], true)) {
            return 'permission_denied';
        }
        if ($profileStatus === 'hotel_mismatch') {
            return 'hotel_mismatch';
        }
        if (in_array($profileStatus, ['waiting_login', 'login_expired'], true)) {
            return $this->redactCollectionStatusText((string)($profileRow['next_action'] ?? $profileRow['current_status'] ?? 'platform_login_required'));
        }

        $operatorMessage = $this->collectionStatusSafeErrorMessage((string)($syncDiagnostics['adapter_status'] ?? ''), (string)($syncDiagnostics['operator_message'] ?? ''));
        $missingInputs = is_array($syncDiagnostics['missing_inputs'] ?? null) ? $syncDiagnostics['missing_inputs'] : [];
        if ($operatorMessage !== '' && in_array('target_date_traffic_rows', $missingInputs, true)) {
            return $operatorMessage;
        }
        if ($operatorMessage !== '' && in_array('traffic_field_facts', $missingInputs, true)) {
            return $operatorMessage;
        }

        if ((int)($dailySummary['target_date_rows'] ?? 0) <= 0) {
            if ((int)($dailySummary['row_count'] ?? 0) > 0) {
                return 'target_date_no_rows';
            }
            if (in_array($collectionStatus, ['not_collected', 'stale'], true)) {
                return 'no_collected_ota_rows';
            }
        }
        if ((int)($dailySummary['target_date_rows'] ?? 0) > 0 && (int)($dailySummary['target_date_traffic_rows'] ?? 0) <= 0) {
            return $operatorMessage !== '' ? $operatorMessage : 'target_date_traffic_rows_missing';
        }
        if ((int)($dailySummary['target_date_traffic_rows'] ?? 0) > 0 && (string)($dailySummary['target_date_traffic_field_fact_status'] ?? '') !== 'ready') {
            return 'traffic_field_facts_missing';
        }

        if (in_array($collectionStatus, ['failed', 'partial'], true) && $taskMessage !== '') {
            return $taskMessage;
        }

        $sourceError = $this->collectionStatusSafeErrorMessage((string)($latestSource['last_sync_status'] ?? $latestSource['status'] ?? ''), (string)($latestSource['last_error'] ?? ''));
        if ($sourceError !== '') {
            return $sourceError;
        }

        foreach ($resourceStatuses as $resourceStatus) {
            $reason = $this->collectionStatusSafeErrorMessage((string)($resourceStatus['collectionStatus'] ?? ''), (string)($resourceStatus['missingReason'] ?? ''));
            if ($reason !== '') {
                return $reason;
            }
        }

        return $dataCollected ? '' : 'no_collected_ota_rows';
    }

    private function collectionStatusDecodeJson($value): array
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

    /**
     * @param array<string, mixed> $diagnostics
     * @return array<string, mixed>
     */
    private function collectionStatusPublicSyncDiagnostics(array $diagnostics, string $fallbackStatus): array
    {
        if ($diagnostics === []) {
            return [];
        }

        $fieldFactStatus = strtolower(trim((string)($diagnostics['field_fact_status'] ?? '')));
        if (!in_array($fieldFactStatus, ['ready', 'partial', 'missing', 'not_loaded'], true)) {
            $fieldFactStatus = 'unknown';
        }
        $p0Status = strtolower(trim((string)($diagnostics['p0_status'] ?? '')));
        if (!in_array($p0Status, ['ready', 'blocked', 'not_required', 'not_loaded'], true)) {
            $p0Status = 'unknown';
        }
        $adapterStatus = strtolower(trim((string)($diagnostics['adapter_status'] ?? $fallbackStatus)));
        if (!in_array($adapterStatus, ['success', 'partial_success', 'failed', 'capture_failed', 'permission_denied'], true)) {
            $adapterStatus = 'unknown';
        }
        $missingInputs = [];
        foreach ((array)($diagnostics['missing_inputs'] ?? []) as $value) {
            $value = strtolower(trim((string)$value));
            if (in_array($value, ['manual_login_state_verified', 'profile_status_logged_in', 'last_login_verified_at', 'target_date_traffic_rows', 'traffic_field_facts'], true)) {
                $missingInputs[] = $value;
            }
        }

        return [
            'target_date' => $this->collectionStatusTaskQualityDate($diagnostics['target_date'] ?? ''),
            'requires_target_date_traffic' => in_array(strtolower(trim((string)($diagnostics['requires_target_date_traffic'] ?? ''))), ['1', 'true', 'yes', 'on'], true) || ($diagnostics['requires_target_date_traffic'] ?? false) === true,
            'target_date_rows' => max(0, (int)($diagnostics['target_date_rows'] ?? 0)),
            'target_date_traffic_rows' => max(0, (int)($diagnostics['target_date_traffic_rows'] ?? 0)),
            'target_date_traffic_field_fact_ready_count' => max(0, (int)($diagnostics['target_date_traffic_field_fact_ready_count'] ?? 0)),
            'target_date_traffic_field_fact_missing_count' => max(0, (int)($diagnostics['target_date_traffic_field_fact_missing_count'] ?? 0)),
            'field_fact_status' => $fieldFactStatus,
            'p0_status' => $p0Status,
            'capability_states' => $this->collectionStatusCapabilityStates($diagnostics['capability_states'] ?? null),
            'missing_inputs' => array_values(array_unique($missingInputs)),
            'operator_message' => $this->collectionStatusSafeTaskMessage($adapterStatus ?: $fallbackStatus, (string)($diagnostics['operator_message'] ?? '')),
            'adapter_status' => $adapterStatus,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function collectionStatusCapabilityStates(mixed $value): array
    {
        $states = [
            'business' => 'unverified',
            'orders' => 'unverified',
            'reviews' => 'unverified',
        ];
        if (!is_array($value)) {
            return $states;
        }

        $allowed = ['verified', 'permission_denied', 'capability_unavailable', 'unverified', 'collection_failed'];
        foreach (array_keys($states) as $capability) {
            $candidate = strtolower(trim((string)($value[$capability] ?? '')));
            if (in_array($candidate, $allowed, true)) {
                $states[$capability] = $candidate;
            }
        }

        return $states;
    }

    private function collectionStatusSafeTaskMessage(string $status, string $message): string
    {
        $message = strtolower(trim($message));
        $knownMessages = [
            'platform data synchronized.' => 'platform_data_synchronized',
            'platform_data_synchronized' => 'platform_data_synchronized',
            'no business rows were found in payload.' => 'sync_completed_without_saved_rows',
            'sync_completed_without_saved_rows' => 'sync_completed_without_saved_rows',
            'target_date_traffic_ready' => 'target_date_traffic_ready',
            'manual_login_state_not_verified' => 'manual_login_state_not_verified',
            'profile_reused_no_target_date_traffic_rows' => 'profile_reused_no_target_date_traffic_rows',
            'traffic_field_facts_missing' => 'traffic_field_facts_missing',
            'permission_denied' => 'permission_denied',
            'collection_failed' => 'collection_failed',
            'collection_partial' => 'collection_partial',
            'stale_running_task' => 'stale_running_task',
        ];
        if (isset($knownMessages[$message])) {
            return $knownMessages[$message];
        }

        return match (strtolower(trim($status))) {
            'success' => 'platform_data_synchronized',
            'partial_success' => 'collection_partial',
            'permission_denied', 'unauthorized', 'forbidden' => 'permission_denied',
            'login_expired', 'waiting_login', 'session_expired' => 'login_state_unverified',
            'stale_running' => 'stale_running_task',
            default => 'collection_failed',
        };
    }

    private function collectionStatusSafeErrorMessage(string $status, string $message): string
    {
        if (trim($message) === '') {
            return '';
        }

        return $this->collectionStatusSafeTaskMessage($status, $message);
    }

    /**
     * Limits persisted task quality data to the contract used by the status UI.
     * Do not pass raw task stats, platform responses, or source configuration
     * through this projection.
     *
     * @return array<string, mixed>|null
     */
    private function collectionStatusTaskCollectionQuality(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $states = [
            'available',
            'partial',
            'stale',
            'unverified',
            'binding_missing',
            'permission_denied',
            'collection_failed',
        ];
        $state = strtolower(trim((string)($value['primary_quality_state'] ?? '')));
        $isValid = in_array($state, $states, true);
        if (!$isValid) {
            $state = 'unverified';
        }

        $allowedFlags = [
            'manual_login_state_verified',
            'profile_status_logged_in',
            'last_login_verified_at',
            'target_date_traffic_rows',
            'traffic_field_facts',
            'system_hotel_id_missing',
            'data_source_id_missing',
            'ota_store_id_missing',
            'profile_id_missing',
            'non_ota_platform_source',
            'platform_permission_denied',
            'task_status_failed',
            'manual_import_provenance_unverified',
            'source_ingestion_method_unverified',
            'platform_session_not_verified',
            'target_date_missing',
            'p0_target_date_evidence_not_ready',
            'saved_rows_missing',
            'target_date_rows_missing',
            'target_date_traffic_rows_missing',
            'target_date_field_facts_partial',
            'task_partial_success',
            'task_quality_not_verified',
        ];
        $qualityFlags = [];
        foreach ((array)($value['quality_flags'] ?? []) as $flag) {
            $flag = strtolower(trim((string)$flag));
            if (in_array($flag, $allowedFlags, true)) {
                $qualityFlags[] = $flag;
            }
        }
        if (!$isValid) {
            $qualityFlags[] = 'task_quality_not_verified';
        }

        $metricScope = strtolower(trim((string)($value['metric_scope'] ?? '')));
        if (!in_array($metricScope, ['ota_channel', 'unknown'], true)) {
            $metricScope = 'unknown';
        }
        $evidence = is_array($value['evidence'] ?? null) ? $value['evidence'] : [];
        $taskStatus = strtolower(trim((string)($evidence['task_status'] ?? '')));
        if (!in_array($taskStatus, ['success', 'partial_success', 'failed', 'capture_failed', 'permission_denied', 'unknown'], true)) {
            $taskStatus = 'unknown';
        }
        $ingestionMethod = strtolower(trim((string)($evidence['ingestion_method'] ?? '')));
        if (!in_array($ingestionMethod, ['browser_profile', 'profile_browser', 'manual', 'api', 'unknown'], true)) {
            $ingestionMethod = 'unknown';
        }
        $p0Status = strtolower(trim((string)($evidence['p0_status'] ?? '')));
        if (!in_array($p0Status, ['ready', 'blocked', 'not_required', 'not_loaded', 'unknown'], true)) {
            $p0Status = 'unknown';
        }
        $fieldFactStatus = strtolower(trim((string)($evidence['field_fact_status'] ?? '')));
        if (!in_array($fieldFactStatus, ['ready', 'partial', 'missing', 'not_loaded', 'unknown'], true)) {
            $fieldFactStatus = 'unknown';
        }

        $nextAction = strtolower(trim((string)($value['next_action'] ?? '')));
        $allowedActions = [
            '',
            'complete_hotel_poi_binding',
            'restore_platform_permission',
            'inspect_collection_failure',
            'verify_task_source_scope',
            'verify_manual_import_provenance',
            'verify_collection_method',
            'verify_platform_login_state',
            'select_target_date',
            'verify_target_date_evidence',
            'collect_target_date_data',
            'complete_missing_target_date_evidence',
        ];
        if (!in_array($nextAction, $allowedActions, true)) {
            $nextAction = 'verify_target_date_evidence';
        }

        return [
            'primary_quality_state' => $state,
            'quality_flags' => array_values(array_unique($qualityFlags)),
            'metric_scope' => $metricScope,
            'evidence_scope' => 'sync_task',
            'target_date' => $this->collectionStatusTaskQualityDate($value['target_date'] ?? ''),
            'data_as_of' => $this->collectionStatusTaskQualityDate($value['data_as_of'] ?? ''),
            'collected_at' => $this->collectionStatusTaskQualityCollectedAt($value['collected_at'] ?? ''),
            'evidence' => [
                'task_status' => $taskStatus,
                'ingestion_method' => $ingestionMethod,
                'p0_status' => $p0Status,
                'target_date_rows' => max(0, (int)($evidence['target_date_rows'] ?? 0)),
                'target_date_traffic_rows' => max(0, (int)($evidence['target_date_traffic_rows'] ?? 0)),
                'field_fact_status' => $fieldFactStatus,
                'normalized_count' => max(0, (int)($evidence['normalized_count'] ?? 0)),
                'saved_count' => max(0, (int)($evidence['saved_count'] ?? 0)),
            ],
            'next_action' => $nextAction,
        ];
    }

    private function collectionStatusTaskQualityDate(mixed $value): string
    {
        return $this->collectionStatusStrictObservedDate($value);
    }

    private function collectionStatusTaskQualityCollectedAt(mixed $value): string
    {
        $value = trim((string)$value);
        return preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:[+-]\d{2}:?\d{2})?$/', $value) === 1 ? $value : '';
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

    private function collectionStatusTargetDate(array $requestData): string
    {
        $value = trim((string)(
            $requestData['target_date']
            ?? $requestData['targetDate']
            ?? $requestData['data_date']
            ?? $requestData['dataDate']
            ?? $requestData['date']
            ?? ''
        ));
        if ($value === '') {
            return $this->collectionStatusShanghaiToday();
        }

        $date = $this->collectionStatusStrictObservedDate($value);
        if ($date === '') {
            throw new \RuntimeException('target_date 必须是 Asia/Shanghai 时区内不晚于今天的有效 YYYY-MM-DD 日期。', 422);
        }

        return $date;
    }

    private function collectionStatusStrictObservedDate($value): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }

        $timezone = new DateTimeZone(self::COLLECTION_STATUS_TIMEZONE);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date instanceof DateTimeImmutable
            || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
            || $date->format('Y-m-d') !== $value
            || $date > new DateTimeImmutable('today', $timezone)
        ) {
            return '';
        }

        return $date->format('Y-m-d');
    }

    private function collectionStatusShanghaiToday(): string
    {
        return (new DateTimeImmutable('today', new DateTimeZone(self::COLLECTION_STATUS_TIMEZONE)))->format('Y-m-d');
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
