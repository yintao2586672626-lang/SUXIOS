<?php
declare(strict_types=1);

namespace app\command;

use app\service\PlatformDataSyncService;
use app\service\OtaFailureNotificationService;
use app\service\ScheduledAutoFetchPolicy;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;
use think\facade\Db;
use think\facade\Cache;
use think\facade\Log;

class AutoFetchOnlineData extends Command
{
    protected function configure()
    {
        $this->setName('online-data:auto-fetch')
            ->addOption('hotel-id', null, Option::VALUE_REQUIRED, 'Optional positive hotel id scope')
            ->addOption('target-date', null, Option::VALUE_REQUIRED, 'Explicit historical date within the previous 7 days')
            ->addOption('source-ids', null, Option::VALUE_REQUIRED, 'Optional comma-separated Profile source ids within the hotel scope')
            ->addOption('force-rerun', null, Option::VALUE_NONE, 'Rerun one completed explicit hotel/date/source scope')
            ->setDescription('自动获取线上数据（定时任务调用）');
    }

    protected function execute(Input $input, Output $output)
    {
        $hotelIdOption = $input->getOption('hotel-id');
        $hotelId = null;
        if ($hotelIdOption !== null) {
            $rawHotelId = trim((string)$hotelIdOption);
            if ($rawHotelId === '' || !ctype_digit($rawHotelId) || (int)$rawHotelId <= 0) {
                $output->writeln('hotel-id must be a positive integer.');
                return 1;
            }
            $hotelId = (int)$rawHotelId;
        }

        $targetDateOption = $input->getOption('target-date');
        $targetDate = null;
        if ($targetDateOption !== null) {
            if ($hotelId === null) {
                $output->writeln('target-date requires an explicit hotel-id scope.');
                return 1;
            }
            $targetDate = $this->normalizeExplicitTargetDate((string)$targetDateOption);
            if ($targetDate === null) {
                $output->writeln('target-date must be a valid date within the previous 7 days.');
                return 1;
            }
        }

        $sourceIdsOption = $input->getOption('source-ids');
        $sourceIds = [];
        if ($sourceIdsOption !== null) {
            if ($hotelId === null) {
                $output->writeln('source-ids requires an explicit hotel-id scope.');
                return 1;
            }
            $sourceIds = $this->normalizeExplicitSourceIds((string)$sourceIdsOption);
            if ($sourceIds === []) {
                $output->writeln('source-ids must contain positive integer ids.');
                return 1;
            }
        }

        $forceRerun = (bool)$input->getOption('force-rerun');
        if ($forceRerun && ($hotelId === null || $targetDate === null || $sourceIds === [])) {
            $output->writeln('force-rerun requires explicit hotel-id, target-date, and source-ids.');
            return 1;
        }

        return $this->executeSegmentedSchedules($output, $hotelId, $targetDate, $sourceIds, $forceRerun);
    }

    private function executeSegmentedSchedules(Output $output, ?int $hotelIdFilter = null, ?string $targetDateOverride = null, array $sourceIds = [], bool $forceRerun = false): int
    {
        $output->writeln('[' . date('Y-m-d H:i:s') . '] Start online data auto-fetch schedule check.');

        $now = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai'));
        $hotelsQuery = Db::name('hotels')->where('status', 1);
        if ($hotelIdFilter !== null) {
            $hotelsQuery->where('id', $hotelIdFilter);
        }
        $hotels = $hotelsQuery->select()->toArray();
        if ($hotelIdFilter !== null && $hotels === []) {
            $output->writeln('hotel-id was not found or is disabled.');
            return 1;
        }
        $hasIncompleteDueRun = false;

        foreach ($hotels as $hotel) {
            $hotelId = (int)$hotel['id'];
            $hotelName = (string)($hotel['name'] ?? $hotelId);
            $status = Cache::get("online_data_auto_fetch_status_{$hotelId}", []);
            $status = is_array($status) ? $status : [];
            if (empty($status['enabled'])) {
                if ($targetDateOverride !== null) {
                    $output->writeln("Hotel {$hotelName} auto-fetch is disabled.");
                    $hasIncompleteDueRun = true;
                }
                continue;
            }

            $realtimeIntervalHours = $this->normalizeRealtimeScheduleIntervalHours($status['realtime_schedule_interval_hours'] ?? $status['realtime_interval_hours'] ?? $status['schedule_interval_hours'] ?? 2);
            $retryMaxAttempts = $this->normalizeScheduleRetryMaxAttempts($status['retry_max_attempts'] ?? 3);
            $retryDelayMinutes = $this->normalizeScheduleRetryDelayMinutes($status['retry_delay_minutes'] ?? 5);
            $dueRuns = $targetDateOverride !== null
                ? [$this->explicitHistoricalRun($hotelId, $targetDateOverride, $sourceIds)]
                : $this->buildDueRuns($hotelId, $status, $now);
            if (empty($dueRuns)) {
                continue;
            }

            $browserHeadless = array_key_exists('browser_headless', $status) ? $this->truthy($status['browser_headless']) : true;
            $ctripSectionConcurrency = $this->normalizeCtripSectionConcurrency($status['ctrip_section_concurrency'] ?? $status['ctripSectionConcurrency'] ?? 3);
            $lockKey = "online_data_profile_lock_{$hotelId}";
            foreach ($dueRuns as $run) {
                $executedReceipt = Cache::get($run['executed_key']);
                if ($executedReceipt && !$forceRerun) {
                    $output->writeln("Hotel {$hotelName} {$run['label']} already executed, skipped.");
                    if (is_array($executedReceipt)) {
                        $this->writeMachineReceipt($output, $executedReceipt);
                    }
                    continue;
                }
                $retryState = $forceRerun ? [] : Cache::get($run['retry_key'], []);
                $retryState = is_array($retryState) ? $retryState : [];
                if (!$forceRerun && !$this->isScheduleRetryDue($retryState, $retryMaxAttempts, $now)) {
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
                            (string)($status['ctrip_node_id'] ?? ''),
                            (new ScheduledAutoFetchPolicy())->normalizePlatforms($run['target_platforms'] ?? []),
                            $sourceIds
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
                            'failed_platforms' => (new ScheduledAutoFetchPolicy())->normalizePlatforms($run['target_platforms'] ?? []) ?: ['ctrip', 'meituan'],
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
                        'platform_results' => is_array($result['platform_results'] ?? null) ? $result['platform_results'] : [],
                        'ctrip_section_concurrency' => $result['ctrip_section_concurrency'] ?? $ctripSectionConcurrency,
                        'realtime_schedule_interval_hours' => $realtimeIntervalHours,
                        'failed_platforms' => $outcome['failed_platforms'],
                        'successful_platforms' => $outcome['successful_platforms'],
                        ...$retryDetails,
                    ]);
                    $output->writeln("Hotel {$hotelName} {$run['label']} {$outcome['status']}: " . (string)($result['message'] ?? '-'));

                    $receipt = $this->buildMachineReceipt($hotelId, $run['data_date'], $sourceIds, $outcome, $result);
                    $this->writeMachineReceipt($output, $receipt);
                    if ($outcome['complete'] && !empty($receipt['collection_complete'])) {
                        Cache::set($run['executed_key'], $receipt, 86400);
                        Cache::delete($run['retry_key']);
                    } else {
                        $retryReceiptDetails = $outcome['complete']
                            ? [
                                ...$retryDetails,
                                'last_status' => 'receipt_invalid',
                                'last_message' => 'collection completed without a verifiable source-task receipt',
                            ]
                            : $retryDetails;
                        Cache::set($run['retry_key'], $retryReceiptDetails, 86400 * 2);
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

    private function normalizeExplicitTargetDate(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return null;
        }
        $timezone = new \DateTimeZone('Asia/Shanghai');
        $target = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$target instanceof \DateTimeImmutable
            || (is_array($errors) && ((int)($errors['warning_count'] ?? 0) > 0 || (int)($errors['error_count'] ?? 0) > 0))
            || $target->format('Y-m-d') !== $value
        ) {
            return null;
        }
        $today = new \DateTimeImmutable('today', $timezone);
        $ageDays = (int)(($today->getTimestamp() - $target->getTimestamp()) / 86400);
        return $ageDays >= 1 && $ageDays <= 7 ? $value : null;
    }

    /** @return array<int, int> */
    private function normalizeExplicitSourceIds(string $value): array
    {
        $ids = [];
        foreach (explode(',', trim($value)) as $part) {
            $part = trim($part);
            if ($part === '' || !ctype_digit($part) || (int)$part <= 0) {
                return [];
            }
            $ids[(int)$part] = (int)$part;
        }
        $ids = array_values($ids);
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /** @return array{slot_id: string, period: string, data_date: string, executed_key: string, retry_key: string, label: string, executed_message: string} */
    private function explicitHistoricalRun(int $hotelId, string $targetDate, array $sourceIds = []): array
    {
        $sourceIds = array_values(array_unique(array_filter(
            array_map('intval', $sourceIds),
            static fn(int $id): bool => $id > 0
        )));
        sort($sourceIds, SORT_NUMERIC);
        $scopeSuffix = $sourceIds === [] ? '' : '_sources_' . implode('-', $sourceIds);
        return [
            'slot_id' => "historical:{$targetDate}",
            'period' => 'historical_daily',
            'data_date' => $targetDate,
            'executed_key' => "online_data_historical_executed_{$hotelId}_{$targetDate}{$scopeSuffix}",
            'retry_key' => "online_data_historical_retry_{$hotelId}_{$targetDate}{$scopeSuffix}",
            'label' => 'historical-explicit',
            'executed_message' => 'Explicit historical data already executed.',
        ];
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
        string $ctripNodeId = '',
        array $targetPlatforms = [],
        array $sourceIds = []
    ): array
    {
        $startedAt = microtime(true);
        $dataPeriod = $this->normalizeOnlineDailyDataPeriod($dataPeriod) ?: 'historical_daily';
        $snapshotTime = $this->normalizeDateTime($snapshotTime) ?? date('Y-m-d H:i:s');
        $ctripSectionConcurrency = $this->normalizeCtripSectionConcurrency($ctripSectionConcurrency);
        $targetPlatforms = (new ScheduledAutoFetchPolicy())->normalizePlatforms($targetPlatforms);
        $profileResult = $this->syncBrowserProfileSources($hotelId, $dataDate, $browserHeadless, $dataPeriod, $snapshotTime, $ctripSectionConcurrency, $targetPlatforms, $sourceIds);
        if ($profileResult['attempted']) {
            return [
                'success' => (bool)$profileResult['success'],
                'message' => (string)$profileResult['message'],
                'saved_count' => (int)($profileResult['saved_count'] ?? 0),
                'data_period' => $dataPeriod,
                'timing' => $this->ensureTotalTiming(is_array($profileResult['timing'] ?? null) ? $profileResult['timing'] : [], $startedAt),
                'ctrip_section_concurrency' => $ctripSectionConcurrency,
                'platform_results' => is_array($profileResult['platform_results'] ?? null) ? $profileResult['platform_results'] : [],
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
            'platform_results' => [],
            'failed_platforms' => $targetPlatforms ?: ['ctrip', 'meituan'],
        ];
    }

    private function syncBrowserProfileSources(int $hotelId, string $dataDate, bool $browserHeadless = true, string $dataPeriod = 'historical_daily', ?string $snapshotTime = null, int $ctripSectionConcurrency = 3, array $targetPlatforms = [], array $sourceIds = []): array
    {
        $dataPeriod = $this->normalizeOnlineDailyDataPeriod($dataPeriod) ?: 'historical_daily';
        $snapshotTime = $this->normalizeDateTime($snapshotTime) ?? date('Y-m-d H:i:s');
        $ctripSectionConcurrency = $this->normalizeCtripSectionConcurrency($ctripSectionConcurrency);
        try {
            $sourceIds = array_values(array_unique(array_filter(array_map('intval', $sourceIds), static fn(int $id): bool => $id > 0)));
            $sourceQuery = Db::name('platform_data_sources')
                ->where('enabled', 1)
                ->whereIn('status', ['ready', 'success', 'partial_success', 'failed', 'waiting_config'])
                ->where('system_hotel_id', $hotelId)
                ->whereIn('platform', ['ctrip', 'meituan'])
                ->where('ingestion_method', 'browser_profile');
            if ($sourceIds !== []) {
                $sourceQuery->whereIn('id', $sourceIds);
            }
            $sources = $sourceQuery
                ->field('id,platform,status,last_sync_time,system_hotel_id')
                ->select()
                ->toArray();
            if ($sourceIds !== []) {
                $foundSourceIds = array_values(array_unique(array_map(
                    static fn(array $source): int => (int)($source['id'] ?? 0),
                    $sources
                )));
                $missingSourceIds = array_values(array_diff($sourceIds, $foundSourceIds));
                if ($missingSourceIds !== []) {
                    return [
                        'attempted' => true,
                        'success' => false,
                        'message' => 'scheduled_profile_source_scope_missing:' . implode(',', $missingSourceIds),
                        'saved_count' => 0,
                        'data_period' => $dataPeriod,
                        'timing' => [],
                        'platform_results' => [],
                        'failed_platforms' => $targetPlatforms ?: ['ctrip', 'meituan'],
                        'successful_platforms' => [],
                    ];
                }
            }
            $policy = new ScheduledAutoFetchPolicy();
            $sources = $policy->profileSourcesForRun($sources, $sourceIds);
            $targetPlatforms = $policy->normalizePlatforms($targetPlatforms);
            if ($targetPlatforms !== []) {
                $sources = array_values(array_filter(
                    $sources,
                    static fn(array $source): bool => in_array(strtolower(trim((string)($source['platform'] ?? ''))), $targetPlatforms, true)
                ));
            }
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
        $platformResults = [];
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
            $runReadback = is_array($result['run_readback'] ?? null) ? $result['run_readback'] : [];
            $coreReadbackVerified = $this->runReadbackCoreVerified($runReadback);
            $platformResults[] = [
                'platform' => $platform,
                'data_source_id' => (int)$source['id'],
                'success' => $coreReadbackVerified,
                'saved_count' => $sourceSavedCount,
                'run_readback' => $runReadback,
                'message' => (string)($result['message'] ?? $result['status'] ?? '-'),
            ];
            if (!$coreReadbackVerified) {
                $failedCount++;
                $failedPlatforms[$platform] = true;
            }
            $messages[] = strtoupper($platform) . ' 数据源#' . (int)$source['id'] . ': ' . (string)($result['message'] ?? $result['status'] ?? '-');
        }

        if ($savedCount > 0) {
            if (($savedByPlatform['ctrip'] ?? 0) > 0) {
                $this->updateCtripLatestFetchStatus($hotelId, date('Y-m-d H:i:s'), $dataDate, (int)$savedByPlatform['ctrip']);
            }
            $messagePrefix = $failedCount > 0 ? '浏览器 Profile 已写入但本次核心指标回执不完整' : '浏览器 Profile 数据源同步并验证本次核心指标回执';
            return [
                'attempted' => true,
                'success' => $failedCount === 0,
                'message' => "{$messagePrefix} {$savedCount} 条",
                'saved_count' => $savedCount,
                'data_period' => $dataPeriod,
                'timing' => $timing,
                'platform_results' => $platformResults,
                'failed_platforms' => array_keys($failedPlatforms),
                'successful_platforms' => array_keys(array_filter(
                    $savedByPlatform,
                    static fn(int $count, string $platform): bool => $count > 0 && !isset($failedPlatforms[$platform]),
                    ARRAY_FILTER_USE_BOTH
                )),
            ];
        }

        return [
            'attempted' => true,
            'success' => false,
            'message' => '浏览器 Profile 数据源同步失败：' . implode('；', array_slice($messages, 0, 3)),
            'saved_count' => 0,
            'data_period' => $dataPeriod,
            'timing' => $timing,
            'platform_results' => $platformResults,
            'failed_platforms' => array_keys($failedPlatforms),
            'successful_platforms' => [],
        ];
    }

    /**
     * @param array<int, int> $sourceIds
     * @param array<string, mixed> $outcome
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function buildMachineReceipt(int $hotelId, string $targetDate, array $sourceIds, array $outcome, array $result): array
    {
        $sourceIds = array_values(array_unique(array_filter(
            array_map('intval', $sourceIds),
            static fn(int $id): bool => $id > 0
        )));
        sort($sourceIds, SORT_NUMERIC);
        $sourceTasks = [];
        foreach ((array)($result['platform_results'] ?? []) as $platformResult) {
            if (!is_array($platformResult)) {
                continue;
            }
            $readback = is_array($platformResult['run_readback'] ?? null) ? $platformResult['run_readback'] : [];
            $dataSourceId = (int)($readback['data_source_id'] ?? $platformResult['data_source_id'] ?? 0);
            $syncTaskId = (int)($readback['sync_task_id'] ?? 0);
            $receiptHotelId = (int)($readback['system_hotel_id'] ?? 0);
            $receiptDate = substr(trim((string)($readback['target_date'] ?? '')), 0, 10);
            $platform = strtolower(trim((string)($readback['platform'] ?? $platformResult['platform'] ?? '')));
            $rowIds = array_values(array_unique(array_filter(array_map(
                'intval',
                is_array($readback['row_ids'] ?? null) ? $readback['row_ids'] : []
            ), static fn(int $id): bool => $id > 0)));
            if (($readback['readback_verified'] ?? false) !== true
                || $dataSourceId <= 0
                || $syncTaskId <= 0
                || $receiptHotelId !== $hotelId
                || $receiptDate !== $targetDate
                || !in_array($platform, ['ctrip', 'meituan'], true)
                || $rowIds === []
            ) {
                continue;
            }
            $sourceTasks[$dataSourceId] = [
                'data_source_id' => $dataSourceId,
                'sync_task_id' => $syncTaskId,
                'platform' => $platform,
                'collection_status' => !empty($platformResult['success']) ? 'success' : 'partial',
                'row_ids' => $rowIds,
            ];
        }
        ksort($sourceTasks, SORT_NUMERIC);
        $receiptSourceIds = array_map('intval', array_keys($sourceTasks));
        $expectedSourceIds = $sourceIds === [] ? $receiptSourceIds : $sourceIds;
        $exportableSnapshotComplete = $expectedSourceIds !== []
            && $receiptSourceIds === $expectedSourceIds;
        $collectionComplete = !empty($outcome['complete']) && $exportableSnapshotComplete;

        return [
            'schema_version' => 1,
            'hotel_id' => $hotelId,
            'target_date' => $targetDate,
            'source_ids' => $expectedSourceIds,
            'status' => (string)($outcome['status'] ?? ''),
            'collection_complete' => $collectionComplete,
            'exportable_snapshot_complete' => $exportableSnapshotComplete,
            'source_tasks' => array_values($sourceTasks),
        ];
    }

    /** @param array<string, mixed> $receipt */
    private function writeMachineReceipt(Output $output, array $receipt): void
    {
        $json = json_encode($receipt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($json)) {
            $output->writeln('SUXIOS_AUTO_FETCH_RECEIPT=' . $json);
        }
    }

    private function runReadbackCoreVerified(array $receipt): bool
    {
        $metricKeys = array_values(array_unique(array_map(
            static fn($value): string => strtolower(trim((string)$value)),
            is_array($receipt['verified_metric_keys'] ?? null) ? $receipt['verified_metric_keys'] : []
        )));
        return ($receipt['readback_verified'] ?? false) === true
            && (int)($receipt['sync_task_id'] ?? 0) > 0
            && (int)($receipt['data_source_id'] ?? 0) > 0
            && trim((string)($receipt['started_at'] ?? '')) !== ''
            && array_values(array_filter(
                is_array($receipt['row_ids'] ?? null) ? $receipt['row_ids'] : [],
                static fn($value): bool => (int)$value > 0
            )) !== []
            && array_values(array_filter(
                is_array($receipt['source_trace_ids'] ?? null) ? $receipt['source_trace_ids'] : [],
                static fn($value): bool => trim((string)$value) !== ''
            )) !== []
            && count(array_intersect(['revenue', 'room_nights', 'adr'], $metricKeys)) === 3;
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
        if (is_array($details['platform_results'] ?? null)) {
            $runRecord['platform_results'] = $details['platform_results'];
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
        if (is_array($details['platform_results'] ?? null)) {
            $status['last_result']['platform_results'] = $details['platform_results'];
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
