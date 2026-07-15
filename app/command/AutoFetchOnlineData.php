<?php
declare(strict_types=1);

namespace app\command;

use app\service\PlatformDataSyncService;
use app\service\OtaCredentialVault;
use app\service\OtaFailureNotificationService;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;
use think\facade\Cache;
use think\facade\Log;

class AutoFetchOnlineData extends Command
{
    protected function configure()
    {
        $this->setName('online-data:auto-fetch')
            ->setDescription('自动获取线上数据（定时任务调用）');
    }

    protected function execute(Input $input, Output $output)
    {
        return $this->executeSegmentedSchedules($output);
    }

    private function executeSegmentedSchedules(Output $output): int
    {
        $output->writeln('[' . date('Y-m-d H:i:s') . '] Start online data auto-fetch schedule check.');

        $currentTime = date('H:i');
        $currentMinute = (int)date('i');
        $currentHour = date('H');
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $hotels = Db::name('hotels')->where('status', 1)->select()->toArray();

        foreach ($hotels as $hotel) {
            $hotelId = (int)$hotel['id'];
            $hotelName = (string)($hotel['name'] ?? $hotelId);
            $status = Cache::get("online_data_auto_fetch_status_{$hotelId}", []);
            $status = is_array($status) ? $status : [];
            if (empty($status['enabled'])) {
                continue;
            }

            $historicalTime = $this->normalizeFetchScheduleTime((string)($status['historical_schedule_time'] ?? $status['schedule_time'] ?? '10:00')) ?? '10:00';
            $realtimeMinute = $this->normalizeAutoFetchScheduleMinute($status['realtime_schedule_minute'] ?? $status['schedule_minute'] ?? 5);
            $realtimeMinute = $realtimeMinute === null ? 5 : $realtimeMinute;
            $realtimeIntervalHours = $this->normalizeRealtimeScheduleIntervalHours($status['realtime_schedule_interval_hours'] ?? $status['realtime_interval_hours'] ?? $status['schedule_interval_hours'] ?? 2);
            $historicalEnabled = array_key_exists('historical_enabled', $status) ? $this->truthy($status['historical_enabled']) : true;
            $realtimeEnabled = array_key_exists('realtime_enabled', $status) ? $this->truthy($status['realtime_enabled']) : true;

            $dueRuns = [];
            if ($historicalEnabled && $currentTime === $historicalTime) {
                $dueRuns[] = [
                    'period' => 'historical_daily',
                    'data_date' => $yesterday,
                    'executed_key' => "online_data_historical_executed_{$hotelId}_{$yesterday}",
                    'label' => 'historical',
                ];
            }
            if ($realtimeEnabled && $currentMinute === $realtimeMinute && $this->isRealtimeScheduleHourDue((int)$currentHour, $realtimeIntervalHours)) {
                $dueRuns[] = [
                    'period' => 'realtime_snapshot',
                    'data_date' => $today,
                    'executed_key' => "online_data_realtime_executed_{$hotelId}_{$today}_{$currentHour}",
                    'label' => 'realtime',
                ];
            }
            if (empty($dueRuns)) {
                continue;
            }

            $browserHeadless = array_key_exists('browser_headless', $status) ? $this->truthy($status['browser_headless']) : true;
            $ctripSectionConcurrency = $this->normalizeCtripSectionConcurrency($status['ctrip_section_concurrency'] ?? $status['ctripSectionConcurrency'] ?? 3);
            $lockKey = "online_data_profile_lock_{$hotelId}";
            $ranLockedTask = false;
            foreach ($dueRuns as $run) {
                if (Cache::get($run['executed_key'])) {
                    $output->writeln("Hotel {$hotelName} {$run['label']} already executed, skipped.");
                    continue;
                }
                if ($ranLockedTask || Cache::get($lockKey)) {
                    $message = 'skipped_locked: same Profile is already running another capture task';
                    $output->writeln("Hotel {$hotelName} {$run['label']} {$message}.");
                    $this->updateStatus($hotelId, false, $message, $run['data_date'], [
                        'status' => 'skipped_locked',
                        'data_period' => $run['period'],
                    ]);
                    continue;
                }

                $snapshotTime = date('Y-m-d H:i:s');
                $output->writeln("Hotel {$hotelName} start {$run['label']} capture for {$run['data_date']}.");
                Cache::set($lockKey, [
                    'data_period' => $run['period'],
                    'data_date' => $run['data_date'],
                    'started_at' => $snapshotTime,
                ], 7200);
                $ranLockedTask = true;
                try {
                    $result = $this->fetchDataForHotel(
                        $hotelId,
                        $run['data_date'],
                        $browserHeadless,
                        $run['period'],
                        $snapshotTime,
                        $ctripSectionConcurrency,
                        (string)($status['ctrip_config_id'] ?? ''),
                        (string)($status['ctrip_request_url'] ?? ''),
                        (string)($status['ctrip_node_id'] ?? '')
                    );
                    $this->updateStatus($hotelId, !empty($result['success']), (string)($result['message'] ?? ''), $run['data_date'], [
                        'status' => !empty($result['success']) ? 'success' : 'failed',
                        'saved_count' => (int)($result['saved_count'] ?? 0),
                        'data_period' => $run['period'],
                        'timing' => is_array($result['timing'] ?? null) ? $result['timing'] : [],
                        'ctrip_section_concurrency' => $result['ctrip_section_concurrency'] ?? $ctripSectionConcurrency,
                        'realtime_schedule_interval_hours' => $realtimeIntervalHours,
                        'failed_platforms' => $result['failed_platforms'] ?? [],
                        'successful_platforms' => $result['successful_platforms'] ?? [],
                    ]);
                    $output->writeln("Hotel {$hotelName} {$run['label']} " . (!empty($result['success']) ? 'success' : 'failed') . ': ' . (string)($result['message'] ?? '-'));
                    Cache::set($run['executed_key'], true, 86400);
                } finally {
                    Cache::delete($lockKey);
                }
            }
        }

        $output->writeln('[' . date('Y-m-d H:i:s') . '] Online data auto-fetch schedule check finished.');
        return 0;
    }

    private function fetchDataForHotel(
        int $hotelId,
        string $dataDate,
        bool $browserHeadless = true,
        string $dataPeriod = 'historical_daily',
        ?string $snapshotTime = null,
        int $ctripSectionConcurrency = 3,
        string $ctripConfigId = '',
        string $ctripRequestUrl = '',
        string $ctripNodeId = ''
    ): array
    {
        $startedAt = microtime(true);
        $dataPeriod = $this->normalizeOnlineDailyDataPeriod($dataPeriod) ?: 'historical_daily';
        $snapshotTime = $this->normalizeDateTime($snapshotTime) ?? date('Y-m-d H:i:s');
        $ctripSectionConcurrency = $this->normalizeCtripSectionConcurrency($ctripSectionConcurrency);
        $profileResult = $this->syncBrowserProfileSources($hotelId, $dataDate, $browserHeadless, $dataPeriod, $snapshotTime, $ctripSectionConcurrency);
        if ($profileResult['attempted']) {
            return [
                'success' => (bool)$profileResult['success'],
                'message' => (string)$profileResult['message'],
                'saved_count' => (int)($profileResult['saved_count'] ?? 0),
                'data_period' => $dataPeriod,
                'timing' => $this->ensureTotalTiming(is_array($profileResult['timing'] ?? null) ? $profileResult['timing'] : [], $startedAt),
                'ctrip_section_concurrency' => $ctripSectionConcurrency,
                'failed_platforms' => $profileResult['failed_platforms'] ?? [],
                'successful_platforms' => $profileResult['successful_platforms'] ?? [],
            ];
        }

        $ctripRequestUrl = $this->normalizeScheduledCtripRequestUrl($ctripRequestUrl);
        $ctripNodeId = $this->normalizeScheduledCtripNodeId($ctripNodeId);
        if ($ctripRequestUrl === '' || $ctripNodeId === '') {
            return ['success' => false, 'message' => 'ctrip_execution_metadata_invalid', 'saved_count' => 0, 'data_period' => $dataPeriod, 'timing' => $this->ensureTotalTiming([], $startedAt), 'failed_platforms' => ['ctrip']];
        }

        $locator = $this->resolveCtripCredentialLocatorForHotel($hotelId, $ctripConfigId);
        if (($locator['status'] ?? '') !== 'ready') {
            return [
                'success' => false,
                'message' => (string)($locator['message'] ?? 'credential_unavailable'),
                'saved_count' => 0,
                'data_period' => $dataPeriod,
                'timing' => $this->ensureTotalTiming([], $startedAt),
                'failed_platforms' => ['ctrip'],
            ];
        }

        try {
            return (new OtaCredentialVault())->withPayloadForExecution(
                (int)$locator['tenant_id'],
                $hotelId,
                'ctrip',
                (string)$locator['config_id'],
                function (array $credentialPayload) use ($hotelId, $dataDate, $dataPeriod, $snapshotTime, $startedAt, $ctripRequestUrl, $ctripNodeId): array {
                    $cookieValue = $credentialPayload['cookies'] ?? $credentialPayload['cookie'] ?? null;
                    $cookies = is_scalar($cookieValue) ? trim((string)$cookieValue) : '';
                    if ($cookies === '') {
                        return ['success' => false, 'message' => 'credential_payload_missing_cookie', 'saved_count' => 0, 'data_period' => $dataPeriod, 'timing' => $this->ensureTotalTiming([], $startedAt), 'failed_platforms' => ['ctrip']];
                    }

                    $result = $this->sendHttpRequest(
                        $ctripRequestUrl,
                        ['nodeId' => $ctripNodeId, 'startDate' => $dataDate, 'endDate' => $dataDate],
                        $cookies
                    );

                    if (!$result['success']) {
                        return ['success' => false, 'message' => 'ctrip_request_failed', 'saved_count' => 0, 'data_period' => $dataPeriod, 'timing' => $this->ensureTotalTiming([], $startedAt), 'failed_platforms' => ['ctrip']];
                    }

                    $savedCount = $this->parseAndSaveData($result['data'], $dataDate, $dataDate, $hotelId, $dataPeriod, $snapshotTime);

                    if ($savedCount === 0) {
                        return ['success' => false, 'message' => 'no_valid_data', 'saved_count' => 0, 'data_period' => $dataPeriod, 'timing' => $this->ensureTotalTiming([], $startedAt), 'failed_platforms' => ['ctrip']];
                    }

                    Log::info('Auto fetch online data succeeded', ['hotel_id' => $hotelId, 'count' => $savedCount]);
                    $this->updateCtripLatestFetchStatus($hotelId, date('Y-m-d H:i:s'), $dataDate, $savedCount);

                    return ['success' => true, 'message' => "saved_{$savedCount}_rows", 'saved_count' => $savedCount, 'data_period' => $dataPeriod, 'timing' => $this->ensureTotalTiming([], $startedAt), 'successful_platforms' => ['ctrip']];
                }
            );
        } catch (\Throwable $e) {
            Log::error('Auto fetch online data credential execution failed', [
                'hotel_id' => $hotelId,
                'exception_type' => get_debug_type($e),
            ]);
            return ['success' => false, 'message' => 'credential_execution_failed', 'saved_count' => 0, 'data_period' => $dataPeriod, 'timing' => $this->ensureTotalTiming([], $startedAt), 'failed_platforms' => ['ctrip']];
        }
    }

    private function syncBrowserProfileSources(int $hotelId, string $dataDate, bool $browserHeadless = true, string $dataPeriod = 'historical_daily', ?string $snapshotTime = null, int $ctripSectionConcurrency = 3): array
    {
        $dataPeriod = $this->normalizeOnlineDailyDataPeriod($dataPeriod) ?: 'historical_daily';
        $snapshotTime = $this->normalizeDateTime($snapshotTime) ?? date('Y-m-d H:i:s');
        $ctripSectionConcurrency = $this->normalizeCtripSectionConcurrency($ctripSectionConcurrency);
        try {
            $sources = Db::name('platform_data_sources')
                ->where('enabled', 1)
                ->whereIn('status', ['ready', 'success', 'partial_success'])
                ->where('system_hotel_id', $hotelId)
                ->whereIn('platform', ['ctrip', 'meituan'])
                ->where('ingestion_method', 'browser_profile')
                ->field('id,platform,system_hotel_id')
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            Log::warning('Read browser Profile data-source metadata failed', [
                'hotel_id' => $hotelId,
                'exception_type' => get_debug_type($e),
            ]);
            return ['attempted' => false, 'success' => false, 'message' => '', 'saved_count' => 0, 'data_period' => $dataPeriod, 'timing' => []];
        }

        if (empty($sources)) {
            return ['attempted' => false, 'success' => false, 'message' => '', 'saved_count' => 0, 'data_period' => $dataPeriod, 'timing' => []];
        }

        $systemUser = new class {
            public int $id = 1;

            public function isSuperAdmin(): bool
            {
                return true;
            }
        };
        $service = new PlatformDataSyncService();
        $messages = [];
        $savedCount = 0;
        $savedByPlatform = [];
        $failedCount = 0;
        $failedPlatforms = [];
        $timing = [];
        foreach ($sources as $source) {
            $platform = strtolower((string)($source['platform'] ?? 'source'));
            try {
                $result = $service->syncDataSource($systemUser, (int)$source['id'], [
                    'trigger_type' => 'cron',
                    'data_date' => $dataDate,
                    'data_period' => $dataPeriod,
                    'snapshot_time' => $snapshotTime,
                    'interactive_browser' => !$browserHeadless,
                    'browser_headless' => $browserHeadless,
                    'ctrip_section_concurrency' => $ctripSectionConcurrency,
                ]);
            } catch (\Throwable $e) {
                $failedCount++;
                $failedPlatforms[$platform] = true;
                $messages[] = strtoupper($platform) . ' 数据源#' . (int)$source['id'] . ': ' . $e->getMessage();
                continue;
            }

            $sourceSavedCount = (int)($result['saved_count'] ?? 0);
            $savedCount += $sourceSavedCount;
            if (is_array($result['timing'] ?? null)) {
                $timing = $this->sumTiming($timing, $result['timing']);
            }
            $savedByPlatform[$platform] = ($savedByPlatform[$platform] ?? 0) + $sourceSavedCount;
            if (!in_array((string)($result['status'] ?? ''), ['success', 'partial_success'], true) || $sourceSavedCount <= 0) {
                $failedCount++;
                $failedPlatforms[$platform] = true;
            }
            $messages[] = strtoupper($platform) . ' 数据源#' . (int)$source['id'] . ': ' . (string)($result['message'] ?? $result['status'] ?? '-');
        }

        if ($savedCount > 0) {
            if (($savedByPlatform['ctrip'] ?? 0) > 0) {
                $this->updateCtripLatestFetchStatus($hotelId, date('Y-m-d H:i:s'), $dataDate, (int)$savedByPlatform['ctrip']);
            }
            $messagePrefix = $failedCount > 0 ? '浏览器 Profile 数据源部分同步成功' : '浏览器 Profile 数据源同步成功';
            return [
                'attempted' => true,
                'success' => true,
                'message' => "{$messagePrefix} {$savedCount} 条",
                'saved_count' => $savedCount,
                'data_period' => $dataPeriod,
                'timing' => $timing,
                'failed_platforms' => array_keys($failedPlatforms),
                'successful_platforms' => array_keys(array_filter($savedByPlatform, static fn(int $count): bool => $count > 0)),
            ];
        }

        return [
            'attempted' => true,
            'success' => false,
            'message' => '浏览器 Profile 数据源同步失败：' . implode('；', array_slice($messages, 0, 3)),
            'saved_count' => 0,
            'data_period' => $dataPeriod,
            'timing' => $timing,
            'failed_platforms' => array_keys($failedPlatforms),
            'successful_platforms' => [],
        ];
    }

    private function resolveCtripCredentialLocatorForHotel(int $hotelId, string $preferredConfigId = ''): array
    {
        $tenantId = (int)Db::name('hotels')->where('id', $hotelId)->value('tenant_id');
        if ($tenantId <= 0) {
            return ['status' => 'missing_tenant', 'message' => 'credential_tenant_unavailable'];
        }

        $preferredConfigId = trim($preferredConfigId);
        if ($preferredConfigId !== '' && !preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $preferredConfigId)) {
            return ['status' => 'invalid_credential', 'message' => 'credential_config_id_invalid'];
        }

        try {
            $query = Db::name('ota_credentials')
                ->where('tenant_id', $tenantId)
                ->where('system_hotel_id', $hotelId)
                ->where('platform', 'ctrip')
                ->where('credential_status', 'ready')
                ->field('tenant_id,system_hotel_id,platform,config_id,credential_status');
            if ($preferredConfigId !== '') {
                $query->where('config_id', $preferredConfigId);
            }
            $rows = $query
                ->limit(2)
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            Log::warning('Read Ctrip credential locator failed', [
                'hotel_id' => $hotelId,
                'exception_type' => get_debug_type($e),
            ]);
            return ['status' => 'metadata_unavailable', 'message' => 'credential_metadata_unavailable'];
        }

        if (count($rows) === 0) {
            return ['status' => 'missing_credential', 'message' => 'credential_not_ready'];
        }

        if (count($rows) !== 1) {
            return ['status' => 'ambiguous_credential', 'message' => 'credential_selection_ambiguous'];
        }

        $row = $rows[0];
        $configId = trim((string)($row['config_id'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9._-]{1,100}$/', $configId)) {
            return ['status' => 'invalid_credential', 'message' => 'credential_config_id_invalid'];
        }

        return [
            'status' => 'ready',
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'platform' => 'ctrip',
            'config_id' => $configId,
        ];
    }

    private function normalizeFetchScheduleTime(string $scheduleTime): ?string
    {
        $scheduleTime = trim($scheduleTime);
        if (!preg_match('/^([01]?[0-9]|2[0-3]):([0-5][0-9])$/', $scheduleTime, $matches)) {
            return null;
        }
        return sprintf('%02d:%02d', (int)$matches[1], (int)$matches[2]);
    }

    private function normalizeScheduledCtripRequestUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportCompeteHotelReport';
        }
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string)($parts['host'] ?? '')) !== 'ebooking.ctrip.com'
        ) {
            return '';
        }
        return $url;
    }

    private function normalizeScheduledCtripNodeId(string $nodeId): string
    {
        $nodeId = trim($nodeId);
        if ($nodeId === '') {
            return '24588';
        }
        return preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $nodeId) === 1 ? $nodeId : '';
    }

    private function normalizeAutoFetchScheduleMinute($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        $minute = (int)$value;
        return $minute >= 0 && $minute <= 59 ? $minute : null;
    }

    private function normalizeRealtimeScheduleIntervalHours($value): int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return 2;
        }
        return max(1, min(24, (int)$value));
    }

    private function isRealtimeScheduleHourDue(int $hour, int $intervalHours): bool
    {
        $intervalHours = $this->normalizeRealtimeScheduleIntervalHours($intervalHours);
        return $hour % $intervalHours === 0;
    }

    private function normalizeCtripSectionConcurrency($value): int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return 3;
        }
        return max(1, min(4, (int)$value));
    }

    private function truthy($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int)$value !== 0;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }
        return !empty($value);
    }

    private function normalizeOnlineDailyDataPeriod($value): string
    {
        $period = strtolower(trim((string)$value));
        return in_array($period, ['historical_daily', 'realtime_snapshot'], true) ? $period : '';
    }

    private function normalizeDateTime($value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }

    private function onlineDailyDataColumns(): array
    {
        static $columns = null;
        if (is_array($columns)) {
            return $columns;
        }

        $columns = [];
        try {
            foreach (Db::query('SHOW COLUMNS FROM `online_daily_data`') as $row) {
                $field = (string)($row['Field'] ?? $row['field'] ?? '');
                if ($field !== '') {
                    $columns[$field] = true;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('读取 online_daily_data 字段失败', ['error' => $e->getMessage()]);
        }

        return $columns;
    }

    private function applyOnlineDailyDataPeriodFields(array $data, array $columns, array $periodOptions = []): array
    {
        $period = $this->normalizeOnlineDailyDataPeriod($periodOptions['data_period'] ?? $data['data_period'] ?? '') ?: 'historical_daily';
        $snapshotTime = $this->normalizeDateTime($periodOptions['snapshot_time'] ?? $data['snapshot_time'] ?? null);
        if ($period === 'realtime_snapshot' && $snapshotTime === null) {
            $snapshotTime = date('Y-m-d H:i:s');
        }

        if (isset($columns['data_period'])) {
            $data['data_period'] = $period;
        }
        if (isset($columns['snapshot_time'])) {
            $data['snapshot_time'] = $period === 'realtime_snapshot' ? $snapshotTime : null;
        }
        if (isset($columns['snapshot_bucket'])) {
            $data['snapshot_bucket'] = $period === 'realtime_snapshot' && $snapshotTime !== null
                ? date('YmdH', strtotime($snapshotTime))
                : '';
        }
        if (isset($columns['is_final'])) {
            $data['is_final'] = $period === 'historical_daily' ? 1 : 0;
        }

        return $data;
    }

    private function applyOnlineDailyDataPeriodQuery($query, array $data, array $columns): void
    {
        $period = $this->normalizeOnlineDailyDataPeriod($data['data_period'] ?? '') ?: 'historical_daily';
        if (isset($columns['data_period'])) {
            $query->where('data_period', $period);
        }
        if ($period === 'realtime_snapshot' && isset($columns['snapshot_bucket'])) {
            $query->where('snapshot_bucket', (string)($data['snapshot_bucket'] ?? ''));
        }
    }

    private function sumTiming(array $base, array $timing): array
    {
        foreach ($this->normalizeTiming($timing) as $key => $value) {
            $base[$key] = (int)($base[$key] ?? 0) + $value;
        }
        return $base;
    }

    private function normalizeTiming(array $timing): array
    {
        $normalized = [];
        foreach ([
            'capture_elapsed_ms',
            'raw_store_elapsed_ms',
            'normalize_elapsed_ms',
            'daily_rows_save_elapsed_ms',
            'finish_task_elapsed_ms',
            'total_elapsed_ms',
        ] as $key) {
            if (array_key_exists($key, $timing) && is_numeric($timing[$key])) {
                $normalized[$key] = max(0, (int)$timing[$key]);
            }
        }
        return $normalized;
    }

    private function ensureTotalTiming(array $timing, float $startedAt): array
    {
        $timing = $this->normalizeTiming($timing);
        if (empty($timing['total_elapsed_ms'])) {
            $timing['total_elapsed_ms'] = max(0, (int)round((microtime(true) - $startedAt) * 1000));
        }
        return $timing;
    }

    private function ctripLatestFetchStatusKey(?int $hotelId): string
    {
        return $hotelId ? "online_data_ctrip_latest_fetch_{$hotelId}" : 'online_data_ctrip_latest_fetch';
    }

    private function updateCtripLatestFetchStatus(?int $hotelId, string $fetchedAt, string $dataDate, int $savedCount): void
    {
        Cache::set($this->ctripLatestFetchStatusKey($hotelId), [
            'fetched_at' => $fetchedAt,
            'data_date' => $dataDate,
            'saved_count' => $savedCount,
        ], 86400 * 30);
    }

    private function sendHttpRequest(string $url, array $postData, string $cookies): array
    {
        if (!$this->isAllowedCtripRequestUrl($url)) {
            return ['success' => false, 'error' => '仅允许请求携程官方域名'];
        }

        $headers = [
            'Accept: application/json, text/javascript, */*; q=0.01',
            'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With: XMLHttpRequest',
            'Origin: https://ebooking.ctrip.com',
            'Referer: https://ebooking.ctrip.com/',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Cookie: ' . $cookies,
        ];

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => http_build_query($postData),
                'timeout' => 30,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            return ['success' => false, 'error' => error_get_last()['message'] ?? 'Unknown error'];
        }

        return ['success' => true, 'data' => json_decode($response, true), 'raw' => $response];
    }

    private function isAllowedCtripRequestUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        return $scheme === 'https' && ($host === 'ctrip.com' || str_ends_with($host, '.ctrip.com'));
    }

    private function parseAndSaveData($responseData, $startDate, $endDate, int $hotelId, string $dataPeriod = 'historical_daily', ?string $snapshotTime = null): int
    {
        $dataList = $responseData['data']['hotelList'] ?? $responseData['data'] ?? $responseData['hotelList'] ?? [];

        if (empty($dataList)) {
            foreach ($responseData as $value) {
                if (is_array($value) && isset($value[0]) && isset($value[0]['hotelId'])) {
                    $dataList = array_merge($dataList, $value);
                }
            }
        }

        if (empty($dataList)) return 0;

        $columns = $this->onlineDailyDataColumns();
        $dataPeriod = $this->normalizeOnlineDailyDataPeriod($dataPeriod) ?: 'historical_daily';
        $snapshotTime = $this->normalizeDateTime($snapshotTime) ?? date('Y-m-d H:i:s');
        $savedCount = 0;
        foreach ($dataList as $item) {
            if (!is_array($item)) continue;

            $hotelIdFromData = $item['hotelId'] ?? $item['hotel_id'] ?? null;
            if (empty($hotelIdFromData)) continue;

            $dataDate = $item['dataDate'] ?? $item['date'] ?? $startDate;

            $data = [
                'hotel_id' => (string)$hotelIdFromData,
                'hotel_name' => $item['hotelName'] ?? $item['hotel_name'] ?? '',
                'system_hotel_id' => $hotelId,
                'data_date' => $dataDate,
                'amount' => floatval($item['amount'] ?? $item['totalAmount'] ?? 0),
                'quantity' => intval($item['quantity'] ?? $item['roomNights'] ?? 0),
                'book_order_num' => intval($item['bookOrderNum'] ?? 0),
                'comment_score' => floatval($item['commentScore'] ?? 0),
                'qunar_comment_score' => floatval($item['qunarCommentScore'] ?? 0),
                'raw_data' => json_encode($item, JSON_UNESCAPED_UNICODE),
            ];
            $data = $this->applyOnlineDailyDataPeriodFields($data, $columns, [
                'data_period' => $dataPeriod,
                'snapshot_time' => $snapshotTime,
            ]);

            $query = Db::name('online_daily_data')
                ->where('hotel_id', (string)$hotelIdFromData)
                ->where('data_date', $dataDate)
                ->where('system_hotel_id', $hotelId);
            $this->applyOnlineDailyDataPeriodQuery($query, $data, $columns);
            $exists = $query->find();

            if ($exists) {
                Db::name('online_daily_data')->where('id', $exists['id'])->update($data);
            } else {
                Db::name('online_daily_data')->insert($data);
            }
            $savedCount++;
        }

        return $savedCount;
    }

    private function updateStatus(int $hotelId, bool $success, string $message, ?string $dataDate = null, array $details = []): void
    {
        $statusKey = "online_data_auto_fetch_status_{$hotelId}";
        $status = Cache::get($statusKey, []);
        if (!is_array($status)) {
            $status = [];
        }

        $runAt = date('Y-m-d H:i:s');
        $dataDate = $dataDate ?: date('Y-m-d', strtotime('-1 day'));
        $runRecord = [
            'run_at' => $runAt,
            'data_date' => $dataDate,
            'success' => $success,
            'message' => $message,
        ];
        $statusCode = (string)($details['status'] ?? ($success ? 'success' : 'failed'));
        $dataPeriod = $this->normalizeOnlineDailyDataPeriod($details['data_period'] ?? $details['dataPeriod'] ?? '');
        $timing = is_array($details['timing'] ?? null) ? $this->normalizeTiming($details['timing']) : [];
        if ($statusCode !== '') {
            $runRecord['status'] = $statusCode;
        }
        if ($dataPeriod !== '') {
            $runRecord['data_period'] = $dataPeriod;
        }
        if (array_key_exists('saved_count', $details)) {
            $runRecord['saved_count'] = (int)$details['saved_count'];
        }
        if (!empty($timing)) {
            $runRecord['timing'] = $timing;
        }
        if (array_key_exists('ctrip_section_concurrency', $details)) {
            $runRecord['ctrip_section_concurrency'] = $this->normalizeCtripSectionConcurrency($details['ctrip_section_concurrency']);
            $status['ctrip_section_concurrency'] = $runRecord['ctrip_section_concurrency'];
        }
        if (array_key_exists('realtime_schedule_interval_hours', $details)) {
            $runRecord['realtime_schedule_interval_hours'] = $this->normalizeRealtimeScheduleIntervalHours($details['realtime_schedule_interval_hours']);
            $status['realtime_schedule_interval_hours'] = $runRecord['realtime_schedule_interval_hours'];
            $status['schedule_interval_hours'] = $runRecord['realtime_schedule_interval_hours'];
        }

        $status['last_run_time'] = $runAt;
        $status['last_data_date'] = $dataDate;
        $status['last_result'] = ['success' => $success, 'message' => $message, 'status' => $statusCode];
        if ($dataPeriod !== '') {
            $status['last_result']['data_period'] = $dataPeriod;
        }
        if (array_key_exists('saved_count', $details)) {
            $status['last_result']['saved_count'] = (int)$details['saved_count'];
        }
        if (!empty($timing)) {
            $status['last_result']['timing'] = $timing;
        }
        if (array_key_exists('ctrip_section_concurrency', $details)) {
            $status['last_result']['ctrip_section_concurrency'] = $this->normalizeCtripSectionConcurrency($details['ctrip_section_concurrency']);
        }
        if (array_key_exists('realtime_schedule_interval_hours', $details)) {
            $status['last_result']['realtime_schedule_interval_hours'] = $this->normalizeRealtimeScheduleIntervalHours($details['realtime_schedule_interval_hours']);
        }

        $recentRuns = $status['recent_runs'] ?? [];
        $recentRuns = is_array($recentRuns) ? $recentRuns : [];
        array_unshift($recentRuns, $runRecord);
        $status['recent_runs'] = array_slice($recentRuns, 0, 10);

        $failedRecords = $status['failed_records'] ?? [];
        $failedRecords = is_array($failedRecords) ? $failedRecords : [];
        $failedRecords = array_values(array_filter($failedRecords, function ($item) use ($dataDate) {
            return (string)($item['data_date'] ?? '') !== $dataDate;
        }));
        if (!$success && $statusCode !== 'skipped_locked') {
            array_unshift($failedRecords, [
                'data_date' => $dataDate,
                'last_failed_at' => $runAt,
                'message' => $message,
            ]);
        }
        $status['failed_records'] = array_slice($failedRecords, 0, 30);

        Cache::set($statusKey, $status, 86400 * 30);

        $failedPlatforms = $this->normalizeFailedPlatforms($details['failed_platforms'] ?? []);
        $successfulPlatforms = $this->normalizeFailedPlatforms($details['successful_platforms'] ?? []);
        if ((!$success || $failedPlatforms !== [] || $successfulPlatforms !== []) && $statusCode !== 'skipped_locked') {
            try {
                (new OtaFailureNotificationService())->recordCollectionOutcome([
                    'hotel_id' => $hotelId,
                    'platform' => 'ota',
                    'failed_platforms' => $failedPlatforms,
                    'successful_platforms' => $successfulPlatforms,
                    'message' => $message,
                    'data_date' => $dataDate,
                    'success' => $success,
                    'saved_count' => (int)($details['saved_count'] ?? 0),
                    'actor_user_id' => 0,
                ]);
            } catch (\Throwable $e) {
                Log::warning('Scheduled OTA failure notifier execution failed', [
                    'hotel_id' => $hotelId,
                    'exception_type' => get_debug_type($e),
                ]);
            }
        }
    }

    /** @return array<int, string> */
    private function normalizeFailedPlatforms(mixed $platforms): array
    {
        if (!is_array($platforms)) {
            return [];
        }
        $normalized = [];
        foreach ($platforms as $platform) {
            $platform = strtolower(trim((string)$platform));
            if (in_array($platform, ['ctrip', 'meituan'], true)) {
                $normalized[$platform] = true;
            }
        }
        return array_keys($normalized);
    }
}
