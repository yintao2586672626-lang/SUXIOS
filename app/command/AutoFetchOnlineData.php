<?php
declare(strict_types=1);

namespace app\command;

use app\service\PlatformDataSyncService;
use app\service\OtaFailureNotificationService;
use app\service\ScheduledAutoFetchPolicy;
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

        $now = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai'));
        $hotels = Db::name('hotels')->where('status', 1)->select()->toArray();
        $hasIncompleteDueRun = false;

        foreach ($hotels as $hotel) {
            $hotelId = (int)$hotel['id'];
            $hotelName = (string)($hotel['name'] ?? $hotelId);
            $status = Cache::get("online_data_auto_fetch_status_{$hotelId}", []);
            $status = is_array($status) ? $status : [];
            if (empty($status['enabled'])) {
                continue;
            }

            $realtimeIntervalHours = $this->normalizeRealtimeScheduleIntervalHours($status['realtime_schedule_interval_hours'] ?? $status['realtime_interval_hours'] ?? $status['schedule_interval_hours'] ?? 2);
            $retryMaxAttempts = $this->normalizeScheduleRetryMaxAttempts($status['retry_max_attempts'] ?? 3);
            $retryDelayMinutes = $this->normalizeScheduleRetryDelayMinutes($status['retry_delay_minutes'] ?? 5);
            $dueRuns = $this->buildDueRuns($hotelId, $status, $now);
            if (empty($dueRuns)) {
                continue;
            }

            $browserHeadless = array_key_exists('browser_headless', $status) ? $this->truthy($status['browser_headless']) : true;
            $ctripSectionConcurrency = $this->normalizeCtripSectionConcurrency($status['ctrip_section_concurrency'] ?? $status['ctripSectionConcurrency'] ?? 3);
            $lockKey = "online_data_profile_lock_{$hotelId}";
            foreach ($dueRuns as $run) {
                if (Cache::get($run['executed_key'])) {
                    $output->writeln("Hotel {$hotelName} {$run['label']} already executed, skipped.");
                    continue;
                }
                $retryState = Cache::get($run['retry_key'], []);
                $retryState = is_array($retryState) ? $retryState : [];
                if (!$this->isScheduleRetryDue($retryState, $retryMaxAttempts, $now)) {
                    $hasIncompleteDueRun = true;
                    $reason = ((int)($retryState['attempts'] ?? 0) >= $retryMaxAttempts)
                        ? 'retry exhausted'
                        : 'retry cooldown';
                    $output->writeln("Hotel {$hotelName} {$run['label']} {$reason}, skipped.");
                    continue;
                }
                if (Cache::get($lockKey)) {
                    $message = 'skipped_locked: same Profile is already running another capture task';
                    $output->writeln("Hotel {$hotelName} {$run['label']} {$message}.");
                    $this->updateStatus($hotelId, false, $message, $run['data_date'], [
                        'status' => 'skipped_locked',
                        'data_period' => $run['period'],
                        'slot_id' => $run['slot_id'],
                    ]);
                    $hasIncompleteDueRun = true;
                    continue;
                }

                $snapshotTime = date('Y-m-d H:i:s');
                $output->writeln("Hotel {$hotelName} start {$run['label']} capture for {$run['data_date']}.");
                Cache::set($lockKey, [
                    'data_period' => $run['period'],
                    'data_date' => $run['data_date'],
                    'started_at' => $snapshotTime,
                ], 7200);
                try {
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
                    } catch (\Throwable $e) {
                        Log::error('Scheduled OTA collection execution failed', [
                            'hotel_id' => $hotelId,
                            'data_period' => $run['period'],
                            'exception_type' => get_debug_type($e),
                        ]);
                        $result = [
                            'success' => false,
                            'message' => 'scheduled_fetch_exception:' . get_debug_type($e),
                            'saved_count' => 0,
                            'failed_platforms' => ['ctrip', 'meituan'],
                            'successful_platforms' => [],
                        ];
                    }

                    $outcome = $this->classifyScheduledRunOutcome($result);
                    $retryDetails = $outcome['complete']
                        ? [
                            'attempts' => (int)($retryState['attempts'] ?? 0) + 1,
                            'max_attempts' => $retryMaxAttempts,
                            'next_retry_at' => null,
                            'retry_exhausted' => false,
                        ]
                        : $this->buildScheduleRetryState(
                            $retryState,
                            $retryMaxAttempts,
                            $retryDelayMinutes,
                            $now,
                            $outcome['status'],
                            (string)($result['message'] ?? '')
                        );

                    $this->updateStatus($hotelId, $outcome['complete'], (string)($result['message'] ?? ''), $run['data_date'], [
                        'status' => $outcome['status'],
                        'saved_count' => $outcome['saved_count'],
                        'data_period' => $run['period'],
                        'slot_id' => $run['slot_id'],
                        'timing' => is_array($result['timing'] ?? null) ? $result['timing'] : [],
                        'ctrip_section_concurrency' => $result['ctrip_section_concurrency'] ?? $ctripSectionConcurrency,
                        'realtime_schedule_interval_hours' => $realtimeIntervalHours,
                        'failed_platforms' => $outcome['failed_platforms'],
                        'successful_platforms' => $outcome['successful_platforms'],
                        ...$retryDetails,
                    ]);
                    $output->writeln("Hotel {$hotelName} {$run['label']} {$outcome['status']}: " . (string)($result['message'] ?? '-'));

                    if ($outcome['complete']) {
                        Cache::set($run['executed_key'], true, 86400);
                        Cache::delete($run['retry_key']);
                    } else {
                        Cache::set($run['retry_key'], $retryDetails, 86400 * 2);
                        $hasIncompleteDueRun = true;
                    }
                } finally {
                    Cache::delete($lockKey);
                }
            }
        }

        $output->writeln('[' . date('Y-m-d H:i:s') . '] Online data auto-fetch schedule check finished.');
        return $hasIncompleteDueRun ? 1 : 0;
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

        // Scheduled collection is Profile-only. Reusable Cookie/API credentials
        // remain an explicit manual recovery path and are never a cron fallback.
        return [
            'success' => false,
            'message' => 'scheduled_browser_profile_source_required',
            'saved_count' => 0,
            'data_period' => $dataPeriod,
            'timing' => $this->ensureTotalTiming([], $startedAt),
            'failed_platforms' => ['ctrip'],
        ];
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

    /**
     * Build the current dispatch window without requiring an exact minute hit.
     * Historical collection remains due for the rest of the day; realtime
     * collection remains due until the end of its scheduled hour.
     *
     * @return array<int, array{slot_id: string, period: string, data_date: string, executed_key: string, retry_key: string, label: string, executed_message: string}>
     */
    private function buildDueRuns(int $hotelId, array $status, \DateTimeImmutable $now): array
    {
        return (new ScheduledAutoFetchPolicy())->dueRuns($hotelId, $status, $now);
    }

    /**
     * A scheduled run is complete only when at least one row was read back and
     * no platform remains failed. Partial writes remain retryable and visible.
     *
     * @return array{complete: bool, status: string, saved_count: int, failed_platforms: array<int, string>, successful_platforms: array<int, string>}
     */
    private function classifyScheduledRunOutcome(array $result): array
    {
        return (new ScheduledAutoFetchPolicy())->classifyOutcome($result);
    }

    private function normalizeScheduleRetryMaxAttempts(mixed $value): int
    {
        return (new ScheduledAutoFetchPolicy())->normalizeMaxAttempts($value);
    }

    private function normalizeScheduleRetryDelayMinutes(mixed $value): int
    {
        return (new ScheduledAutoFetchPolicy())->normalizeDelayMinutes($value);
    }

    private function isScheduleRetryDue(array $retryState, int $maxAttempts, \DateTimeImmutable $now): bool
    {
        return (new ScheduledAutoFetchPolicy())->retryDue($retryState, $maxAttempts, $now);
    }

    /** @return array{attempts: int, max_attempts: int, next_retry_at: ?string, retry_exhausted: bool, last_status: string, last_message: string} */
    private function buildScheduleRetryState(
        array $currentState,
        int $maxAttempts,
        int $baseDelayMinutes,
        \DateTimeImmutable $now,
        string $status,
        string $message
    ): array {
        return (new ScheduledAutoFetchPolicy())->nextRetryState(
            $currentState,
            $maxAttempts,
            $baseDelayMinutes,
            $now,
            $status,
            $message
        );
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
        $slotId = trim((string)($details['slot_id'] ?? ''));
        $failedPlatforms = $this->normalizeFailedPlatforms($details['failed_platforms'] ?? []);
        $successfulPlatforms = $this->normalizeFailedPlatforms($details['successful_platforms'] ?? []);
        $timing = is_array($details['timing'] ?? null) ? $this->normalizeTiming($details['timing']) : [];
        if ($statusCode !== '') {
            $runRecord['status'] = $statusCode;
        }
        if ($dataPeriod !== '') {
            $runRecord['data_period'] = $dataPeriod;
        }
        if ($slotId !== '') {
            $runRecord['slot_id'] = $slotId;
        }
        $runRecord['failed_platforms'] = $failedPlatforms;
        $runRecord['successful_platforms'] = $successfulPlatforms;
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
        foreach (['attempts', 'max_attempts', 'next_retry_at', 'retry_exhausted'] as $retryField) {
            if (array_key_exists($retryField, $details)) {
                $runRecord[$retryField] = $details[$retryField];
            }
        }

        $status['last_run_time'] = $runAt;
        $status['last_data_date'] = $dataDate;
        $status['last_result'] = ['success' => $success, 'message' => $message, 'status' => $statusCode];
        if ($dataPeriod !== '') {
            $status['last_result']['data_period'] = $dataPeriod;
        }
        if ($slotId !== '') {
            $status['last_result']['slot_id'] = $slotId;
        }
        $status['last_result']['failed_platforms'] = $failedPlatforms;
        $status['last_result']['successful_platforms'] = $successfulPlatforms;
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
        foreach (['attempts', 'max_attempts', 'next_retry_at', 'retry_exhausted'] as $retryField) {
            if (array_key_exists($retryField, $details)) {
                $status['last_result'][$retryField] = $details[$retryField];
            }
        }

        $recentRuns = $status['recent_runs'] ?? [];
        $recentRuns = is_array($recentRuns) ? $recentRuns : [];
        array_unshift($recentRuns, $runRecord);
        $status['recent_runs'] = array_slice($recentRuns, 0, 10);

        $failedRecords = $status['failed_records'] ?? [];
        $failedRecords = is_array($failedRecords) ? $failedRecords : [];
        if ($statusCode !== 'skipped_locked') {
            $failedRecords = array_values(array_filter($failedRecords, function ($item) use ($dataDate, $dataPeriod, $slotId) {
                if ($slotId !== '' && trim((string)($item['slot_id'] ?? '')) !== '') {
                    return trim((string)$item['slot_id']) !== $slotId;
                }
                if ((string)($item['data_date'] ?? '') !== $dataDate) {
                    return true;
                }
                $itemPeriod = $this->normalizeOnlineDailyDataPeriod($item['data_period'] ?? '');
                return $dataPeriod !== '' && $itemPeriod !== '' && $itemPeriod !== $dataPeriod;
            }));
            if (!$success) {
                $failedRecord = [
                    'data_date' => $dataDate,
                    'last_failed_at' => $runAt,
                    'message' => $message,
                ];
                if ($dataPeriod !== '') {
                    $failedRecord['data_period'] = $dataPeriod;
                }
                if ($slotId !== '') {
                    $failedRecord['slot_id'] = $slotId;
                }
                $failedRecord['failed_platforms'] = $failedPlatforms;
                $failedRecord['successful_platforms'] = $successfulPlatforms;
                foreach (['attempts', 'max_attempts', 'next_retry_at', 'retry_exhausted'] as $retryField) {
                    if (array_key_exists($retryField, $details)) {
                        $failedRecord[$retryField] = $details[$retryField];
                    }
                }
                array_unshift($failedRecords, $failedRecord);
            }
            $status['failed_records'] = array_slice($failedRecords, 0, 30);
        }

        Cache::set($statusKey, $status, 86400 * 30);

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
