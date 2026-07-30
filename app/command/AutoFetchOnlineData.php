<?php
declare(strict_types=1);

namespace app\command;

use app\model\User;
use app\service\PlatformDataSyncService;
use app\service\CloudOtaCollectionScopeService;
use app\service\CtripCollectorWorkflowService;
use app\service\OtaFailureNotificationService;
use app\service\OtaOrderedCollectionPlanner;
use app\service\P0OtaFieldLoopVerifierRunner;
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
    private const PROFILE_LOCK_STALE_SECONDS = 300;
    private const CLOUD_SINGLE_USER_LOCAL_HOTEL_IDS = [80];

    /** @var array<string, mixed> */
    private array $cloudCollectorScope = [];

    private ?User $cloudCollectorUser = null;

    /** @var array<string, mixed> */
    private array $cloudCollectorPreflight = [];

    protected function configure()
    {
        $this->setName('online-data:auto-fetch')
            ->addOption('hotel-id', null, Option::VALUE_REQUIRED, 'Optional positive hotel id scope')
            ->addOption('target-date', null, Option::VALUE_REQUIRED, 'Explicit historical date within the previous 7 days')
            ->addOption('source-ids', null, Option::VALUE_REQUIRED, 'Optional comma-separated Profile source ids within the hotel scope')
            ->addOption('platforms', null, Option::VALUE_REQUIRED, 'Optional comma-separated OTA platform scope: ctrip,meituan')
            ->addOption('collector-mode', null, Option::VALUE_REQUIRED, 'Cloud collector mode; only single_user_local is supported')
            ->addOption('collector-user-id', null, Option::VALUE_REQUIRED, 'Explicit cloud collector owner user id')
            ->addOption('collector-device-id', null, Option::VALUE_REQUIRED, 'Explicit non-secret cloud collector device id')
            ->addOption('platform-hotel-anchors', null, Option::VALUE_REQUIRED, 'Explicit source_id=platform_hotel_id pairs for scope binding')
            ->addOption('bind-cloud-scope', null, Option::VALUE_NONE, 'Preview or bind the explicit cloud collector scope without OTA requests')
            ->addOption('confirm-cloud-scope-binding', null, Option::VALUE_NONE, 'Confirm writing the explicit cloud collector binding')
            ->addOption('rotate-cloud-device-binding', null, Option::VALUE_NONE, 'Allow replacing a different existing collector device binding')
            ->addOption('unbind-cloud-scope', null, Option::VALUE_NONE, 'Preview or remove the explicit cloud collector binding')
            ->addOption('confirm-cloud-scope-unbind', null, Option::VALUE_NONE, 'Confirm removing the explicit cloud collector binding')
            ->addOption('validate-cloud-scope', null, Option::VALUE_NONE, 'Validate cloud collector scope without OTA requests or persistence')
            ->addOption('force-rerun', null, Option::VALUE_NONE, 'Rerun one completed explicit hotel/date/source scope')
            ->addOption('daily-only', null, Option::VALUE_NONE, 'Run only the yesterday historical schedule; skip realtime snapshots')
            ->addOption('realtime-only', null, Option::VALUE_NONE, 'Run only today realtime snapshots; skip yesterday historical collection')
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

        $platformsOption = $input->getOption('platforms');
        $platforms = [];
        if ($platformsOption !== null) {
            if ($hotelId === null) {
                $output->writeln('platforms requires an explicit hotel-id scope.');
                return 1;
            }
            $platforms = $this->normalizeExplicitPlatforms((string)$platformsOption);
            if ($platforms === []) {
                $output->writeln('platforms must contain ctrip and/or meituan.');
                return 1;
            }
        }

        $platformHotelAnchorsOption = $input->getOption('platform-hotel-anchors');
        $platformHotelAnchors = $platformHotelAnchorsOption === null
            ? []
            : $this->normalizePlatformHotelAnchors((string)$platformHotelAnchorsOption);
        if ($platformHotelAnchorsOption !== null && $platformHotelAnchors === []) {
            $output->writeln('platform-hotel-anchors must contain source_id=platform_hotel_id pairs.');
            return 1;
        }

        $forceRerun = (bool)$input->getOption('force-rerun');
        if ($forceRerun && ($hotelId === null || $targetDate === null || $sourceIds === [])) {
            $output->writeln('force-rerun requires explicit hotel-id, target-date, and source-ids.');
            return 1;
        }

        $dailyOnly = (bool)$input->getOption('daily-only');
        $realtimeOnly = (bool)$input->getOption('realtime-only');
        if ($dailyOnly && $realtimeOnly) {
            $output->writeln('daily-only and realtime-only cannot be used together.');
            return 1;
        }
        if ($realtimeOnly && $targetDate !== null) {
            $output->writeln('realtime-only cannot be combined with target-date.');
            return 1;
        }

        $bindCloudScope = (bool)$input->getOption('bind-cloud-scope');
        $confirmCloudScopeBinding = (bool)$input->getOption('confirm-cloud-scope-binding');
        $rotateCloudDeviceBinding = (bool)$input->getOption('rotate-cloud-device-binding');
        $unbindCloudScope = (bool)$input->getOption('unbind-cloud-scope');
        $confirmCloudScopeUnbind = (bool)$input->getOption('confirm-cloud-scope-unbind');
        $validateCloudScope = (bool)$input->getOption('validate-cloud-scope');
        if ($platformHotelAnchors !== [] && !$bindCloudScope) {
            $output->writeln('platform-hotel-anchors requires bind-cloud-scope.');
            return 1;
        }
        if ($bindCloudScope
            && array_keys($platformHotelAnchors) !== $sourceIds
        ) {
            $output->writeln(
                'bind-cloud-scope requires one explicit platform-hotel anchor for every source-id.'
            );
            return 1;
        }
        $cloudCollectorEnabled = $this->truthy(getenv('SUXIOS_OTA_CLOUD_COLLECTOR'));
        if (($validateCloudScope || $bindCloudScope || $unbindCloudScope) && !$cloudCollectorEnabled) {
            $output->writeln(
                'Cloud scope validation, binding or unbinding requires SUXIOS_OTA_CLOUD_COLLECTOR=1.'
            );
            return 1;
        }
        if (count(array_filter([$validateCloudScope, $bindCloudScope, $unbindCloudScope])) > 1) {
            $output->writeln(
                'validate-cloud-scope, bind-cloud-scope and unbind-cloud-scope are mutually exclusive.'
            );
            return 1;
        }
        if ($confirmCloudScopeBinding && !$bindCloudScope) {
            $output->writeln('confirm-cloud-scope-binding requires bind-cloud-scope.');
            return 1;
        }
        if ($rotateCloudDeviceBinding && (!$bindCloudScope || !$confirmCloudScopeBinding)) {
            $output->writeln(
                'rotate-cloud-device-binding requires bind-cloud-scope and confirm-cloud-scope-binding.'
            );
            return 1;
        }
        if ($confirmCloudScopeUnbind && !$unbindCloudScope) {
            $output->writeln('confirm-cloud-scope-unbind requires unbind-cloud-scope.');
            return 1;
        }
        if (($bindCloudScope || $unbindCloudScope)
            && ($forceRerun || $targetDate !== null || $dailyOnly || $realtimeOnly)
        ) {
            $output->writeln(
                'Cloud scope binding or unbinding cannot be combined with collection schedule or rerun options.'
            );
            return 1;
        }
        if ($cloudCollectorEnabled) {
            $collectorMode = strtolower(trim((string)$input->getOption('collector-mode')));
            $collectorUserId = $this->normalizePositiveIntegerOption($input->getOption('collector-user-id'));
            $collectorDeviceId = trim((string)$input->getOption('collector-device-id'));
            if ($collectorMode !== 'single_user_local'
                || $collectorUserId === null
                || !$this->validCollectorDeviceId($collectorDeviceId)
                || $hotelId === null
                || $sourceIds === []
                || $platforms === []
            ) {
                $output->writeln(
                    'Cloud OTA collector blocked: explicit single_user_local mode, collector-user-id, '
                    . 'collector-device-id, hotel-id, source-ids, and platforms are required.'
                );
                return 78;
            }
            try {
                $sources = $this->initializeCloudCollectorScope(
                    $collectorUserId,
                    $collectorDeviceId,
                    $hotelId,
                    $sourceIds,
                    $platforms,
                    !$bindCloudScope
                );
            } catch (\Throwable $e) {
                $output->writeln('Cloud OTA collector blocked: ' . $e->getMessage());
                return 78;
            }
            $this->cloudCollectorPreflight = (new CloudOtaCollectionScopeService())
                ->evaluate($sources, $this->cloudCollectorScope);
            if ($bindCloudScope) {
                if (!$confirmCloudScopeBinding) {
                    $output->writeln(
                        'SUXIOS_OTA_CLOUD_BINDING='
                        . json_encode(
                            $this->cloudCollectorBindingReceipt(
                                'confirmation_required',
                                false
                            ),
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                        )
                    );
                    return 0;
                }
                try {
                    $this->bindCloudCollectorSources(
                        $sources,
                        $this->cloudCollectorScope,
                        $rotateCloudDeviceBinding,
                        $platformHotelAnchors
                    );
                    $sources = $this->initializeCloudCollectorScope(
                        $collectorUserId,
                        $collectorDeviceId,
                        $hotelId,
                        $sourceIds,
                        $platforms
                    );
                    $this->cloudCollectorPreflight = (new CloudOtaCollectionScopeService())
                        ->evaluate($sources, $this->cloudCollectorScope);
                } catch (\Throwable $e) {
                    $output->writeln('Cloud OTA collector binding blocked: ' . $e->getMessage());
                    return 78;
                }
                $output->writeln(
                    'SUXIOS_OTA_CLOUD_BINDING='
                    . json_encode(
                        $this->cloudCollectorBindingReceipt(
                            'scope_bound_for_current_device',
                            true
                        ),
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    )
                );
                return 0;
            }
            if ($unbindCloudScope) {
                if (!$confirmCloudScopeUnbind) {
                    $output->writeln(
                        'SUXIOS_OTA_CLOUD_BINDING='
                        . json_encode(
                            $this->cloudCollectorBindingReceipt(
                                'unbind_confirmation_required',
                                false
                            ),
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                        )
                    );
                    return 0;
                }
                try {
                    $this->unbindCloudCollectorSources($sources);
                } catch (\Throwable $e) {
                    $output->writeln('Cloud OTA collector unbind blocked: ' . $e->getMessage());
                    return 78;
                }
                $output->writeln(
                    'SUXIOS_OTA_CLOUD_BINDING='
                    . json_encode(
                        $this->cloudCollectorBindingReceipt('scope_unbound', true),
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    )
                );
                return 0;
            }
            if ($validateCloudScope) {
                $output->writeln(
                    'SUXIOS_OTA_CLOUD_SCOPE='
                    . json_encode(
                        $this->cloudCollectorScopeValidationReceipt(),
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    )
                );
                return ($this->cloudCollectorPreflight['collection_allowed'] ?? false) ? 0 : 78;
            }
            if (!($this->cloudCollectorPreflight['collection_allowed'] ?? false)) {
                $output->writeln(
                    'SUXIOS_OTA_CLOUD_SCOPE='
                    . json_encode(
                        $this->cloudCollectorScopeValidationReceipt(),
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    )
                );
                return 78;
            }
        }
        $runMode = $dailyOnly ? 'daily' : ($realtimeOnly ? 'realtime' : '');
        return $this->executeSegmentedSchedules(
            $output,
            $hotelId,
            $targetDate,
            $sourceIds,
            $forceRerun,
            $runMode,
            $platforms
        );
    }

    private function executeSegmentedSchedules(
        Output $output,
        ?int $hotelIdFilter = null,
        ?string $targetDateOverride = null,
        array $sourceIds = [],
        bool $forceRerun = false,
        string $runMode = '',
        array $platforms = []
    ): int
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
                : ($runMode === 'realtime'
                    ? [$this->explicitRealtimeRun($hotelId, $now)]
                    : $this->buildDueRuns($hotelId, $status, $now));
            if ($platforms !== []) {
                foreach ($dueRuns as &$dueRun) {
                    $dueRun['target_platforms'] = $platforms;
                }
                unset($dueRun);
            }
            if ($runMode === 'daily') {
                $dueRuns = array_values(array_filter(
                    $dueRuns,
                    static fn(array $run): bool => (string)($run['period'] ?? '') === 'historical_daily'
                ));
            }
            if ($runMode === 'realtime') {
                $dueRuns = array_values(array_filter(
                    $dueRuns,
                    static fn(array $run): bool => (string)($run['period'] ?? '') === 'realtime_snapshot'
                ));
            }
            if (empty($dueRuns)) {
                continue;
            }

            $browserHeadless = array_key_exists('browser_headless', $status) ? $this->truthy($status['browser_headless']) : true;
            $ctripSectionConcurrency = $this->normalizeCtripSectionConcurrency($status['ctrip_section_concurrency'] ?? $status['ctripSectionConcurrency'] ?? 3);
            $lockKey = "online_data_profile_lock_{$hotelId}";
            foreach ($dueRuns as $run) {
                $executedReceipt = Cache::get($run['executed_key']);
                if ($executedReceipt && !$forceRerun) {
                    if (is_array($executedReceipt) && $this->machineReceiptDailyTrustReady(
                        $executedReceipt,
                        (string)$run['data_date'],
                        $hotelId
                    )) {
                        $output->writeln("Hotel {$hotelName} {$run['label']} already executed with dual-OTA P0 proof, skipped.");
                        $this->writeMachineReceipt($output, $executedReceipt);
                        continue;
                    }
                    Cache::delete($run['executed_key']);
                    $output->writeln("Hotel {$hotelName} {$run['label']} cached receipt is incomplete, recollection remains due.");
                }
                $retryState = $forceRerun ? [] : Cache::get($run['retry_key'], []);
                $retryState = is_array($retryState) ? $retryState : [];
                if (!$forceRerun && !$this->isScheduleRetryDue($retryState, $retryMaxAttempts, $now)) {
                    $hasIncompleteDueRun = true;
                    $reason = ((int)($retryState['attempts'] ?? 0) >= $retryMaxAttempts)
                        ? 'retry exhausted'
                        : 'retry cooldown';
                    $output->writeln("Hotel {$hotelName} {$run['label']} {$reason}, skipped.");
                    if ((string)($run['period'] ?? '') === 'historical_daily') {
                        $lastReceipt = is_array($retryState['last_receipt'] ?? null)
                            ? $retryState['last_receipt']
                            : $this->buildMachineReceipt(
                                $hotelId,
                                (string)$run['data_date'],
                                $sourceIds,
                                [
                                    'complete' => false,
                                    'status' => 'failed',
                                    'required_platforms' => ['ctrip', 'meituan'],
                                ],
                                ['platform_results' => []],
                                'historical_daily'
                            );
                        $gapReport = (new ScheduledAutoFetchPolicy())->buildYesterdayGapReport(
                            $lastReceipt,
                            $retryState,
                            $now
                        );
                        if (($gapReport['status'] ?? '') === 'gap'
                            && empty($retryState['gap_report_emitted'])
                        ) {
                            $retryState['gap_report'] = $gapReport;
                            $retryState['gap_report_emitted'] = true;
                            Cache::set($run['retry_key'], $retryState, 86400 * 2);
                            $this->updateStatus(
                                $hotelId,
                                false,
                                'yesterday_dual_ota_gap_at_cutoff',
                                (string)$run['data_date'],
                                [
                                    'status' => 'gap',
                                    'data_period' => 'historical_daily',
                                    'slot_id' => (string)$run['slot_id'],
                                    'failed_platforms' => $gapReport['recollection_platforms'] ?? [],
                                    'gap_report' => $gapReport,
                                    'attempts' => (int)($retryState['attempts'] ?? 0),
                                    'max_attempts' => $retryMaxAttempts,
                                    'next_retry_at' => $retryState['next_retry_at'] ?? null,
                                    'retry_exhausted' => !empty($retryState['retry_exhausted']),
                                ]
                            );
                            $this->writeGapReport($output, $gapReport);
                        }
                    }
                    continue;
                }
                $existingLock = Cache::get($lockKey);
                if (is_array($existingLock) && $this->profileLockIsStale($existingLock)) {
                    // The worker can disappear before its finally block runs.
                    // Do not let its cache TTL block the bounded retry window.
                    Cache::delete($lockKey);
                    $existingLock = null;
                    $output->writeln("Hotel {$hotelName} {$run['label']} recovered a stale Profile lock.");
                }
                if ($existingLock) {
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
                    if (($outcome['status'] ?? '') === 'in_progress') {
                        $this->updateStatus(
                            $hotelId,
                            false,
                            (string)($result['message'] ?? 'existing_browser_profile_task_in_progress'),
                            (string)$run['data_date'],
                            [
                                'status' => 'in_progress',
                                'saved_count' => $outcome['saved_count'],
                                'data_period' => $run['period'],
                                'slot_id' => $run['slot_id'],
                                'platform_results' => is_array($result['platform_results'] ?? null)
                                    ? $result['platform_results']
                                    : [],
                                'failed_platforms' => $outcome['failed_platforms'],
                                'in_progress_platforms' => $outcome['in_progress_platforms'] ?? [],
                                'successful_platforms' => $outcome['successful_platforms'],
                            ]
                        );
                        $output->writeln(
                            "Hotel {$hotelName} {$run['label']} in_progress: "
                            . (string)($result['message'] ?? '-')
                        );
                        $hasIncompleteDueRun = true;
                        continue;
                    }
                    $receipt = $this->buildMachineReceipt(
                        $hotelId,
                        (string)$run['data_date'],
                        $sourceIds,
                        $outcome,
                        $result,
                        (string)$run['period']
                    );
                    if ((string)$run['period'] === 'historical_daily') {
                        $verifier = (new P0OtaFieldLoopVerifierRunner())->verify(
                            $hotelId,
                            (string)$run['data_date'],
                            ['ctrip', 'meituan'],
                            (string)($receipt['collection_anchor_hash'] ?? '')
                        );
                        Cache::set(
                            "online_data_p0_authority_receipt_{$hotelId}_{$run['data_date']}",
                            $verifier,
                            86400 * 2
                        );
                        $receipt = (new ScheduledAutoFetchPolicy())->attachAuthorityVerifier(
                            $receipt,
                            $verifier
                        );
                    }
                    $trustedReady = $this->machineReceiptDailyTrustReady(
                        $receipt,
                        (string)$run['data_date'],
                        $hotelId
                    );
                    if (!$trustedReady && $outcome['complete']) {
                        $outcome['complete'] = false;
                        $outcome['status'] = 'partial_success';
                        $outcome['failed_platforms'] = $this->receiptRecollectionPlatforms($receipt);
                        $outcome['successful_platforms'] = array_values(array_diff(
                            $outcome['required_platforms'],
                            $outcome['failed_platforms']
                        ));
                        $result['message'] = trim(
                            (string)($result['message'] ?? '') . '; dual_ota_authority_verifier_incomplete',
                            '; '
                        );
                    }
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
                        'authority_verifier' => $receipt['authority_verifier'] ?? [],
                        'trust_receipt' => $receipt,
                        ...$retryDetails,
                    ]);
                    $output->writeln("Hotel {$hotelName} {$run['label']} {$outcome['status']}: " . (string)($result['message'] ?? '-'));

                    $this->writeMachineReceipt($output, $receipt);
                    if ($trustedReady) {
                        Cache::set($run['executed_key'], $receipt, 86400);
                        // Explicit source-scoped runs are still the same
                        // hotel/date daily receipt. Downstream report gates
                        // intentionally consume the canonical key, so publish
                        // the already-verified receipt there as well.
                        if ((string)$run['period'] === 'historical_daily') {
                            Cache::set(
                                $this->canonicalHistoricalExecutedKey($hotelId, (string)$run['data_date']),
                                $receipt,
                                86400
                            );
                        }
                        Cache::delete($run['retry_key']);
                    } else {
                        $retryReceiptDetails = $outcome['complete']
                            ? [
                                ...$retryDetails,
                                'last_status' => 'receipt_invalid',
                                'last_message' => 'collection completed without a verifiable source-task receipt',
                            ]
                            : $retryDetails;
                        $retryReceiptDetails['last_receipt'] = $receipt;
                        if ((string)$run['period'] === 'historical_daily') {
                            $gapReport = (new ScheduledAutoFetchPolicy())->buildYesterdayGapReport(
                                $receipt,
                                $retryReceiptDetails,
                                $now
                            );
                            $retryReceiptDetails['gap_report'] = $gapReport;
                            if (($gapReport['status'] ?? '') === 'gap') {
                                $retryReceiptDetails['gap_report_emitted'] = true;
                                $this->writeGapReport($output, $gapReport);
                            }
                        }
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

    private function canonicalHistoricalExecutedKey(int $hotelId, string $targetDate): string
    {
        return "online_data_historical_executed_{$hotelId}_{$targetDate}";
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

    /** @return array<int, string> */
    private function normalizeExplicitPlatforms(string $value): array
    {
        $platforms = [];
        foreach (explode(',', strtolower(trim($value))) as $platform) {
            $platform = trim($platform);
            if (!in_array($platform, ['ctrip', 'meituan'], true)) {
                return [];
            }
            $platforms[$platform] = $platform;
        }
        return array_values($platforms);
    }

    /** @return array<int, string> */
    private function normalizePlatformHotelAnchors(string $value): array
    {
        $anchors = [];
        foreach (explode(',', trim($value)) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2
                || !ctype_digit(trim($pair[0]))
                || (int)trim($pair[0]) <= 0
            ) {
                return [];
            }
            $sourceId = (int)trim($pair[0]);
            $platformHotelId = trim($pair[1]);
            if ($platformHotelId === ''
                || strlen($platformHotelId) > 120
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $platformHotelId) !== 1
                || isset($anchors[$sourceId])
            ) {
                return [];
            }
            $anchors[$sourceId] = $platformHotelId;
        }
        ksort($anchors, SORT_NUMERIC);
        return $anchors;
    }

    private function normalizePositiveIntegerOption(mixed $value): ?int
    {
        $value = trim((string)$value);
        return $value !== '' && ctype_digit($value) && (int)$value > 0
            ? (int)$value
            : null;
    }

    private function validCollectorDeviceId(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/D', $value) === 1;
    }

    /** @param array<string, mixed> $source */
    private function cloudCollectorPlatformHotelId(array $source): string
    {
        $config = json_decode((string)($source['config_json'] ?? ''), true);
        return is_array($config) ? trim((string)($config['platform_hotel_id'] ?? '')) : '';
    }

    /**
     * @param array<int, int> $sourceIds
     * @param array<int, string> $platforms
     */
    private function initializeCloudCollectorScope(
        int $userId,
        string $deviceId,
        int $hotelId,
        array $sourceIds,
        array $platforms,
        bool $requireExistingBinding = true
    ): array {
        if (!in_array($hotelId, self::CLOUD_SINGLE_USER_LOCAL_HOTEL_IDS, true)) {
            throw new \RuntimeException(
                'single_user_local cloud compatibility is allowlisted only for system hotel 80.'
            );
        }
        $user = User::where('id', $userId)->where('status', User::STATUS_ENABLED)->find();
        if (!$user instanceof User) {
            throw new \RuntimeException('collector user is missing or disabled.');
        }

        $hotel = Db::name('hotels')
            ->field('id,tenant_id,status')
            ->where('id', $hotelId)
            ->where('status', 1)
            ->find();
        $tenantId = is_array($hotel) ? (int)($hotel['tenant_id'] ?? 0) : 0;
        if (!is_array($hotel) || $tenantId <= 0) {
            throw new \RuntimeException('collector hotel is missing, disabled, or has no tenant scope.');
        }
        $hasExplicitHotelGrant = $this->hasExplicitCloudCollectorHotelGrant($userId, $hotelId, $tenantId);
        $authorizationMode = $this->collectorTenantAuthorizationMode(
            (int)($user->tenant_id ?? 0),
            $user->isSuperAdmin(),
            $tenantId,
            $hasExplicitHotelGrant
        );

        $scope = [
            'mode' => 'single_user_local',
            'authorization_mode' => $authorizationMode,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'device_id' => $deviceId,
            'device_id_hash' => hash('sha256', $deviceId),
            'hotel_id' => $hotelId,
            'source_ids' => $sourceIds,
            'platforms' => $platforms,
        ];
        $sources = Db::name('platform_data_sources')
            ->field('id,tenant_id,user_id,system_hotel_id,platform,ingestion_method,enabled,status,config_json')
            ->whereIn('id', $sourceIds)
            ->select()
            ->toArray();
        $foundIds = array_values(array_unique(array_map(
            static fn(array $source): int => (int)($source['id'] ?? 0),
            $sources
        )));
        sort($foundIds, SORT_NUMERIC);
        if ($foundIds !== $sourceIds) {
            throw new \RuntimeException('collector source whitelist contains missing data-source ids.');
        }

        $presentPlatforms = [];
        foreach ($sources as $source) {
            $this->assertCloudCollectorSourceRow($source, $scope, $requireExistingBinding);
            $platform = strtolower(trim((string)($source['platform'] ?? '')));
            $presentPlatforms[$platform] = true;
        }
        foreach ($platforms as $platform) {
            if (!isset($presentPlatforms[$platform])) {
                throw new \RuntimeException("collector source whitelist has no {$platform} data source.");
            }
        }
        if (count(OtaOrderedCollectionPlanner::oneSourcePerBrowserProfileAccount($sources)) !== count($sources)) {
            throw new \RuntimeException(
                'collector source whitelist contains duplicate browser Profile account rows; '
                . 'select one source id per platform account.'
            );
        }

        $this->cloudCollectorScope = $scope;
        $this->cloudCollectorUser = $user;
        return $sources;
    }

    private function hasExplicitCloudCollectorHotelGrant(int $userId, int $hotelId, int $tenantId): bool
    {
        $now = date('Y-m-d H:i:s');
        $grant = Db::name('user_hotel_permissions')
            ->field('id')
            ->where('user_id', $userId)
            ->where('hotel_id', $hotelId)
            ->where('tenant_id', $tenantId)
            ->where('can_fetch_online_data', 1)
            ->whereIn('status', ['active', '1', 1])
            ->where(static function ($query) use ($now): void {
                $query->whereNull('expires_at')->whereOr('expires_at', '>', $now);
            })
            ->find();
        return is_array($grant) && (int)($grant['id'] ?? 0) > 0;
    }

    private function collectorTenantAuthorizationMode(
        int $userTenantId,
        bool $isSuperAdmin,
        int $hotelTenantId,
        bool $hasExplicitHotelGrant
    ): string {
        if (!$hasExplicitHotelGrant) {
            throw new \RuntimeException(
                'collector user has no active, unexpired, tenant-bound hotel fetch grant.'
            );
        }
        if ($userTenantId === $hotelTenantId) {
            return 'same_tenant_explicit_hotel_grant';
        }
        if ($isSuperAdmin) {
            return 'cross_tenant_super_admin_explicit_hotel_grant';
        }
        throw new \RuntimeException(
            'collector user and hotel tenant scopes do not match, and the user is not a controlled super admin.'
        );
    }

    /**
     * @param array<int, array<string, mixed>> $sources
     * @param array<string, mixed> $scope
     */
    private function bindCloudCollectorSources(
        array $sources,
        array $scope,
        bool $allowDeviceRotation,
        array $platformHotelAnchors = []
    ): void {
        $this->assertNoConflictingCloudPlatformHotelIdentity(
            $sources,
            $scope,
            $platformHotelAnchors
        );
        $boundAt = date('Y-m-d H:i:s');
        Db::transaction(function () use (
            $sources,
            $scope,
            $allowDeviceRotation,
            $platformHotelAnchors,
            $boundAt
        ): void {
            foreach ($sources as $source) {
                $sourceId = (int)($source['id'] ?? 0);
                $config = json_decode((string)($source['config_json'] ?? ''), true);
                $config = is_array($config) ? $config : [];
                $existingDeviceId = trim((string)($config['collector_device_id'] ?? ''));
                $existingDeviceHash = strtolower(trim((string)($config['collector_device_id_hash'] ?? '')));
                $existingMethod = strtolower(trim((string)($config['source_method'] ?? '')));
                $hasExistingBinding = $existingDeviceId !== ''
                    || $existingDeviceHash !== ''
                    || $existingMethod !== '';
                $sameDevice = $existingDeviceId !== ''
                    && hash_equals((string)$scope['device_id'], $existingDeviceId)
                    && preg_match('/^[a-f0-9]{64}$/D', $existingDeviceHash) === 1
                    && hash_equals((string)$scope['device_id_hash'], $existingDeviceHash);
                if ($hasExistingBinding && !$sameDevice && !$allowDeviceRotation) {
                    throw new \RuntimeException(
                        "data source {$sourceId} already has another or incomplete device binding; "
                        . 'use rotate-cloud-device-binding only after the owner logs in on the replacement device.'
                    );
                }

                $boundConfig = $this->cloudCollectorBoundSourceConfig(
                    $source,
                    $config,
                    $scope,
                    $boundAt,
                    (string)($platformHotelAnchors[$sourceId] ?? '')
                );
                Db::name('platform_data_sources')
                    ->where('id', $sourceId)
                    ->update([
                        'config_json' => json_encode(
                            $boundConfig,
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                        ),
                        'update_time' => $boundAt,
                    ]);
            }
        });
    }

    /**
     * The canonical platform hotel identity may not be shared with another
     * system hotel. Legacy aliases are deliberately ignored here.
     *
     * @param array<int, array<string, mixed>> $sources
     * @param array<string, mixed> $scope
     * @param array<int, string> $platformHotelAnchors
     */
    private function assertNoConflictingCloudPlatformHotelIdentity(
        array $sources,
        array $scope,
        array $platformHotelAnchors
    ): void {
        $proposed = [];
        $sourceIds = [];
        $platforms = [];
        foreach ($sources as $source) {
            $sourceId = (int)($source['id'] ?? 0);
            $platform = strtolower(trim((string)($source['platform'] ?? '')));
            $config = json_decode((string)($source['config_json'] ?? ''), true);
            $config = is_array($config) ? $config : [];
            $anchor = trim((string)($platformHotelAnchors[$sourceId] ?? $config['platform_hotel_id'] ?? ''));
            if ($sourceId <= 0 || $anchor === '') {
                throw new \RuntimeException(
                    'platform_hotel_id is required for every cloud collector source.'
                );
            }
            $proposed[$platform . "\0" . $anchor] = [
                'tenant_id' => (int)($scope['tenant_id'] ?? 0),
                'hotel_id' => (int)($scope['hotel_id'] ?? 0),
            ];
            $sourceIds[] = $sourceId;
            $platforms[$platform] = $platform;
        }

        $query = Db::name('platform_data_sources')
            ->field('id,tenant_id,system_hotel_id,platform,config_json')
            ->whereIn('platform', array_values($platforms))
            ->whereIn('ingestion_method', ['browser_profile', 'profile_browser'])
            ->where('enabled', 1)
            ->where('status', '<>', 'disabled');
        if ($sourceIds !== []) {
            $query->whereNotIn('id', $sourceIds);
        }
        foreach ($query->select()->toArray() as $row) {
            $config = json_decode((string)($row['config_json'] ?? ''), true);
            $anchor = is_array($config)
                ? trim((string)($config['platform_hotel_id'] ?? ''))
                : '';
            if ($anchor === '') {
                continue;
            }
            $key = strtolower(trim((string)($row['platform'] ?? ''))) . "\0" . $anchor;
            if (!isset($proposed[$key])) {
                continue;
            }
            if ((int)($row['tenant_id'] ?? 0) !== (int)$proposed[$key]['tenant_id']
                || (int)($row['system_hotel_id'] ?? 0) !== (int)$proposed[$key]['hotel_id']
            ) {
                throw new \RuntimeException(
                    'platform_hotel_id is already bound to another tenant or system hotel.'
                );
            }
        }
    }

    /** @param array<int, array<string, mixed>> $sources */
    private function unbindCloudCollectorSources(array $sources): void
    {
        $bindingKeys = [
            'source_method',
            'collector_binding_mode',
            'collector_device_id',
            'collector_device_id_hash',
            'collector_user_id',
            'collector_tenant_id',
            'collector_hotel_id',
            'collector_platform',
            'collector_bound_at',
        ];
        $updatedAt = date('Y-m-d H:i:s');
        Db::transaction(function () use ($sources, $bindingKeys, $updatedAt): void {
            foreach ($sources as $source) {
                $sourceId = (int)($source['id'] ?? 0);
                $currentConfigJson = (string)Db::name('platform_data_sources')
                    ->where('id', $sourceId)
                    ->lock(true)
                    ->value('config_json');
                $currentSource = $source;
                $currentSource['config_json'] = $currentConfigJson;
                $this->assertCloudCollectorSourceRow(
                    $currentSource,
                    $this->cloudCollectorScope
                );
                $config = json_decode($currentConfigJson, true);
                $config = is_array($config) ? $config : [];
                foreach ($bindingKeys as $key) {
                    unset($config[$key]);
                }
                Db::name('platform_data_sources')
                    ->where('id', $sourceId)
                    ->update([
                        'config_json' => json_encode(
                            $config,
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                        ),
                        'update_time' => $updatedAt,
                    ]);
            }
        });
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $config
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function cloudCollectorBoundSourceConfig(
        array $source,
        array $config,
        array $scope,
        string $boundAt,
        string $platformHotelId = ''
    ): array {
        $platformHotelId = trim($platformHotelId) !== ''
            ? trim($platformHotelId)
            : trim((string)($config['platform_hotel_id'] ?? ''));
        if ($platformHotelId === '') {
            throw new \RuntimeException(
                'platform_hotel_id is required for every cloud collector source.'
            );
        }
        $config['source_method'] = 'single_user_local';
        $config['collector_binding_mode'] = 'single_user_local';
        $config['collector_device_id'] = (string)$scope['device_id'];
        $config['collector_device_id_hash'] = (string)$scope['device_id_hash'];
        $config['collector_user_id'] = (int)$scope['user_id'];
        $config['collector_tenant_id'] = (int)$scope['tenant_id'];
        $config['collector_hotel_id'] = (int)$scope['hotel_id'];
        $config['collector_platform'] = strtolower(trim((string)($source['platform'] ?? '')));
        $config['collector_bound_at'] = $boundAt;
        $config['platform_hotel_id'] = $platformHotelId;
        foreach (array_keys($config) as $key) {
            if (str_starts_with((string)$key, 'current_session_')) {
                unset($config[$key]);
            }
        }
        return $config;
    }

    /** @return array<string, mixed> */
    private function cloudCollectorBindingReceipt(string $status, bool $databaseWritePerformed): array
    {
        return [
            'status' => $status,
            'mode' => (string)($this->cloudCollectorScope['mode'] ?? ''),
            'authorization_mode' => (string)($this->cloudCollectorScope['authorization_mode'] ?? ''),
            'tenant_id' => (int)($this->cloudCollectorScope['tenant_id'] ?? 0),
            'user_id' => (int)($this->cloudCollectorScope['user_id'] ?? 0),
            'collector_device_id' => (string)($this->cloudCollectorScope['device_id'] ?? ''),
            'hotel_id' => (int)($this->cloudCollectorScope['hotel_id'] ?? 0),
            'source_ids' => array_values((array)($this->cloudCollectorScope['source_ids'] ?? [])),
            'platforms' => array_values((array)($this->cloudCollectorScope['platforms'] ?? [])),
            'scope_status' => (string)($this->cloudCollectorPreflight['status'] ?? 'blocked'),
            'collection_allowed' => (bool)($this->cloudCollectorPreflight['collection_allowed'] ?? false),
            'database_write_performed' => $databaseWritePerformed,
            'current_session_probe_performed' => false,
            'collection_performed' => false,
            'persistence_performed' => false,
            'sensitive_values_exposed' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function cloudCollectorScopeValidationReceipt(): array
    {
        $receipt = $this->cloudCollectorPreflight;
        if ($receipt === []) {
            $receipt = [
                'status' => 'blocked',
                'collection_allowed' => false,
                'message' => '采集范围尚未完成预检，已阻止采集。',
                'mode' => (string)($this->cloudCollectorScope['mode'] ?? ''),
                'authorization_mode' => (string)($this->cloudCollectorScope['authorization_mode'] ?? ''),
                'tenant_id' => (int)($this->cloudCollectorScope['tenant_id'] ?? 0),
                'user_id' => (int)($this->cloudCollectorScope['user_id'] ?? 0),
                'hotel_id' => (int)($this->cloudCollectorScope['hotel_id'] ?? 0),
                'source_ids' => array_values((array)($this->cloudCollectorScope['source_ids'] ?? [])),
                'platforms' => array_values((array)($this->cloudCollectorScope['platforms'] ?? [])),
                'sources' => [],
            ];
        }
        $receipt['collector_device_id'] = (string)($this->cloudCollectorScope['device_id'] ?? '');
        $receipt['current_session_probe_performed'] = false;
        $receipt['collection_performed'] = false;
        $receipt['persistence_performed'] = false;
        $receipt['sensitive_values_exposed'] = false;
        return $receipt;
    }

    /** @param array<string, mixed> $source @param array<string, mixed> $scope */
    private function assertCloudCollectorSourceRow(
        array $source,
        array $scope,
        bool $requireExistingBinding = true
    ): void
    {
        $sourceId = (int)($source['id'] ?? 0);
        $platform = strtolower(trim((string)($source['platform'] ?? '')));
        if ($sourceId <= 0
            || !in_array($sourceId, (array)$scope['source_ids'], true)
            || (int)($source['tenant_id'] ?? 0) !== (int)$scope['tenant_id']
            || (int)($source['user_id'] ?? 0) !== (int)$scope['user_id']
            || (int)($source['system_hotel_id'] ?? 0) !== (int)$scope['hotel_id']
            || !in_array($platform, (array)$scope['platforms'], true)
            || strtolower(trim((string)($source['ingestion_method'] ?? ''))) !== 'browser_profile'
            || (int)($source['enabled'] ?? 0) !== 1
            || strtolower(trim((string)($source['status'] ?? ''))) === 'disabled'
        ) {
            throw new \RuntimeException("data source {$sourceId} is outside the collector user/tenant/hotel/platform whitelist.");
        }

        if (!$requireExistingBinding) {
            return;
        }

        $config = json_decode((string)($source['config_json'] ?? ''), true);
        $config = is_array($config) ? $config : [];
        if (strtolower(trim((string)($config['source_method'] ?? ''))) !== 'single_user_local') {
            throw new \RuntimeException("data source {$sourceId} is not marked single_user_local.");
        }
        if (strtolower(trim((string)($config['collector_binding_mode'] ?? ''))) !== 'single_user_local'
            || trim((string)($config['collector_bound_at'] ?? '')) === ''
        ) {
            throw new \RuntimeException("data source {$sourceId} has no complete collector binding receipt.");
        }
        $configuredDeviceId = trim((string)($config['collector_device_id'] ?? ''));
        if ($configuredDeviceId === ''
            || !hash_equals((string)$scope['device_id'], $configuredDeviceId)
        ) {
            throw new \RuntimeException("data source {$sourceId} is not assigned to this collector device id.");
        }
        $configuredDeviceHash = strtolower(trim((string)($config['collector_device_id_hash'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/D', $configuredDeviceHash) !== 1
            || !hash_equals((string)$scope['device_id_hash'], $configuredDeviceHash)
        ) {
            throw new \RuntimeException("data source {$sourceId} is not bound to this collector device.");
        }
        if ((int)($config['collector_user_id'] ?? 0) !== (int)$scope['user_id']
            || (int)($config['collector_tenant_id'] ?? 0) !== (int)$scope['tenant_id']
            || (int)($config['collector_hotel_id'] ?? 0) !== (int)$scope['hotel_id']
            || strtolower(trim((string)($config['collector_platform'] ?? '')))
                !== strtolower(trim((string)($source['platform'] ?? '')))
        ) {
            throw new \RuntimeException(
                "data source {$sourceId} collector binding metadata does not match its current scope."
            );
        }
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

    /**
     * The hourly local dispatcher is an explicit current-day request. It must
     * not be silently skipped because the per-hotel default cadence is two
     * hours: that cadence remains for the combined legacy scheduler, while
     * the dedicated realtime task owns one idempotent slot per hour.
     *
     * @return array{slot_id: string, period: string, data_date: string, executed_key: string, retry_key: string, label: string, executed_message: string}
     */
    private function explicitRealtimeRun(int $hotelId, \DateTimeImmutable $now): array
    {
        $dataDate = $now->format('Y-m-d');
        $hour = $now->format('H');
        return [
            'slot_id' => "realtime:{$dataDate}:{$hour}",
            'period' => 'realtime_snapshot',
            'data_date' => $dataDate,
            'executed_key' => "online_data_realtime_executed_{$hotelId}_{$dataDate}_{$hour}",
            'retry_key' => "online_data_realtime_retry_{$hotelId}_{$dataDate}_{$hour}",
            'label' => 'realtime-explicit',
            'executed_message' => 'Explicit realtime snapshot already executed.',
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
        if ($targetPlatforms === []) {
            $targetPlatforms = ['ctrip', 'meituan'];
        }
        $profileResult = $this->syncBrowserProfileSources($hotelId, $dataDate, $browserHeadless, $dataPeriod, $snapshotTime, $ctripSectionConcurrency, $targetPlatforms, $sourceIds);
        if ($profileResult['attempted']) {
            return [
                'success' => (bool)$profileResult['success'],
                'status' => (string)($profileResult['status'] ?? ((bool)$profileResult['success'] ? 'success' : 'failed')),
                'message' => (string)$profileResult['message'],
                'saved_count' => (int)($profileResult['saved_count'] ?? 0),
                'data_period' => $dataPeriod,
                'timing' => $this->ensureTotalTiming(is_array($profileResult['timing'] ?? null) ? $profileResult['timing'] : [], $startedAt),
                'ctrip_section_concurrency' => $ctripSectionConcurrency,
                'platform_results' => is_array($profileResult['platform_results'] ?? null) ? $profileResult['platform_results'] : [],
                'failed_platforms' => $profileResult['failed_platforms'] ?? [],
                'in_progress_platforms' => $profileResult['in_progress_platforms'] ?? [],
                'successful_platforms' => $profileResult['successful_platforms'] ?? [],
                'reused_verified_count' => (int)($profileResult['reused_verified_count'] ?? 0),
                'required_platforms' => $targetPlatforms,
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
            'failed_platforms' => $targetPlatforms,
            'required_platforms' => $targetPlatforms,
        ];
    }

    private function syncBrowserProfileSources(int $hotelId, string $dataDate, bool $browserHeadless = true, string $dataPeriod = 'historical_daily', ?string $snapshotTime = null, int $ctripSectionConcurrency = 3, array $targetPlatforms = [], array $sourceIds = []): array
    {
        $dataPeriod = $this->normalizeOnlineDailyDataPeriod($dataPeriod) ?: 'historical_daily';
        $snapshotTime = $this->normalizeDateTime($snapshotTime) ?? date('Y-m-d H:i:s');
        $ctripSectionConcurrency = $this->normalizeCtripSectionConcurrency($ctripSectionConcurrency);
        $policy = new ScheduledAutoFetchPolicy();
        $targetPlatforms = $policy->normalizePlatforms($targetPlatforms);
        if ($targetPlatforms === []) {
            $targetPlatforms = ['ctrip', 'meituan'];
        }
        try {
            $sourceIds = array_values(array_unique(array_filter(array_map('intval', $sourceIds), static fn(int $id): bool => $id > 0)));
            $sourceQuery = Db::name('platform_data_sources')
                ->where('enabled', 1)
                ->whereIn('status', ['ready', 'success', 'partial_success', 'failed', 'waiting_config'])
                ->where('system_hotel_id', $hotelId)
                ->whereIn('platform', ['ctrip', 'meituan'])
                ->where('ingestion_method', 'browser_profile');
            if ($this->cloudCollectorScope !== []) {
                $sourceQuery
                    ->where('tenant_id', (int)$this->cloudCollectorScope['tenant_id'])
                    ->where('user_id', (int)$this->cloudCollectorScope['user_id']);
            }
            if ($sourceIds !== []) {
                $sourceQuery->whereIn('id', $sourceIds);
            }
            $sources = $sourceQuery
                ->field('id,tenant_id,user_id,platform,data_type,status,last_sync_time,system_hotel_id,ingestion_method,enabled,config_json')
                ->select()
                ->toArray();
            if ($this->cloudCollectorScope !== []) {
                foreach ($sources as $source) {
                    $this->assertCloudCollectorSourceRow($source, $this->cloudCollectorScope);
                }
            }
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
            $sources = $policy->profileSourcesForRun($sources, $sourceIds);
            if ($sourceIds === []) {
                $sources = $this->oneSourcePerBrowserProfileAccount($sources);
            }
            $sources = array_values(array_filter(
                $sources,
                static fn(array $source): bool => in_array(strtolower(trim((string)($source['platform'] ?? ''))), $targetPlatforms, true)
            ));
            usort($sources, static function (array $left, array $right): int {
                $platformOrder = ['ctrip' => 0, 'meituan' => 1];
                $leftPlatform = strtolower(trim((string)($left['platform'] ?? '')));
                $rightPlatform = strtolower(trim((string)($right['platform'] ?? '')));
                return [
                    $platformOrder[$leftPlatform] ?? 99,
                    (int)($left['id'] ?? 0),
                ] <=> [
                    $platformOrder[$rightPlatform] ?? 99,
                    (int)($right['id'] ?? 0),
                ];
            });
        } catch (\Throwable $e) {
            Log::warning('Read browser Profile data-source metadata failed', [
                'hotel_id' => $hotelId,
                'exception_type' => get_debug_type($e),
            ]);
            if ($this->cloudCollectorScope !== []) {
                return [
                    'attempted' => true,
                    'success' => false,
                    'message' => 'cloud_collector_source_scope_invalid',
                    'saved_count' => 0,
                    'data_period' => $dataPeriod,
                    'timing' => [],
                    'platform_results' => [],
                    'failed_platforms' => $targetPlatforms ?: ['ctrip', 'meituan'],
                    'successful_platforms' => [],
                ];
            }
            return ['attempted' => false, 'success' => false, 'message' => '', 'saved_count' => 0, 'data_period' => $dataPeriod, 'timing' => []];
        }

        $presentPlatforms = array_values(array_unique(array_filter(array_map(
            static fn(array $source): string => strtolower(trim((string)($source['platform'] ?? ''))),
            $sources
        ), static fn(string $platform): bool => in_array($platform, ['ctrip', 'meituan'], true))));
        $missingPlatforms = array_values(array_diff($targetPlatforms, $presentPlatforms));
        if (empty($sources)) {
            return [
                'attempted' => true,
                'success' => false,
                'message' => 'scheduled_dual_ota_profile_sources_missing:' . implode(',', $missingPlatforms ?: $targetPlatforms),
                'saved_count' => 0,
                'data_period' => $dataPeriod,
                'timing' => [],
                'platform_results' => array_map(static fn(string $platform): array => [
                    'platform' => $platform,
                    'success' => false,
                    'saved_count' => 0,
                    'message' => 'scheduled_profile_source_missing',
                ], $missingPlatforms ?: $targetPlatforms),
                'failed_platforms' => $missingPlatforms ?: $targetPlatforms,
                'successful_platforms' => [],
                'required_platforms' => $targetPlatforms,
            ];
        }

        $systemUser = $this->cloudCollectorUser ?? new class {
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
        $evidenceByPlatform = [];
        $reusedVerifiedCount = 0;
        $failedCount = count($missingPlatforms);
        $failedPlatforms = array_fill_keys($missingPlatforms, true);
        $inProgressPlatforms = [];
        $platformResults = array_map(static fn(string $platform): array => [
            'platform' => $platform,
            'success' => false,
            'saved_count' => 0,
            'message' => 'scheduled_profile_source_missing',
        ], $missingPlatforms);
        $timing = [];
        foreach ($sources as $source) {
            $platform = strtolower((string)($source['platform'] ?? 'source'));
            $orderedExecution = $this->orderedBrowserProfileExecution(
                $source,
                $dataDate,
                $dataPeriod
            );
            $orderedPlan = $orderedExecution['plan'];
            $reusedRunReadback = $orderedExecution['reused_run_readback'];
            if ($dataPeriod === 'historical_daily'
                && ($orderedPlan['sections'] ?? []) === []
                && $reusedRunReadback !== []
            ) {
                $reusedCount = count(is_array($reusedRunReadback['row_ids'] ?? null)
                    ? $reusedRunReadback['row_ids']
                    : []);
                $reusedVerifiedCount += $reusedCount;
                $evidenceByPlatform[$platform] = ($evidenceByPlatform[$platform] ?? 0) + $reusedCount;
                $platformResults[] = [
                    'platform' => $platform,
                    'data_source_id' => (int)$source['id'],
                    'success' => true,
                    'saved_count' => 0,
                    'reused_verified_count' => $reusedCount,
                    'run_readback' => $reusedRunReadback,
                    'ordered_collection' => $orderedPlan,
                    'message' => 'target_date_core_already_verified_no_capture',
                ];
                continue;
            }
            try {
                $syncOptions = [
                    'trigger_type' => 'daily_profile_reuse',
                    'data_date' => $dataDate,
                    'data_period' => $dataPeriod,
                    'snapshot_time' => $snapshotTime,
                    'interactive_browser' => !$browserHeadless,
                    'browser_headless' => $browserHeadless,
                    'ctrip_section_concurrency' => $dataPeriod === 'historical_daily' && $platform === 'ctrip'
                        ? 1
                        : $ctripSectionConcurrency,
                    'capture_sections' => implode(',', (array)($orderedPlan['sections'] ?? [])),
                    // The adapter normally expands a profile field configuration
                    // into its default section set.  A daily ordered plan must
                    // remain bounded to its first missing section instead.
                    'bounded_capture_sections' => implode(',', (array)($orderedPlan['sections'] ?? [])),
                    'ordered_collection' => $orderedPlan,
                    'require_current_session_probe' => $this->cloudCollectorScope !== [],
                    'required_collector_binding' => $this->cloudCollectorScope === []
                        ? []
                        : [
                            'mode' => (string)$this->cloudCollectorScope['mode'],
                            'tenant_id' => (int)$this->cloudCollectorScope['tenant_id'],
                            'user_id' => (int)$this->cloudCollectorScope['user_id'],
                            'device_id' => (string)$this->cloudCollectorScope['device_id'],
                            'device_id_hash' => (string)$this->cloudCollectorScope['device_id_hash'],
                            'hotel_id' => (int)$this->cloudCollectorScope['hotel_id'],
                            'platform' => $platform,
                            'platform_hotel_id' => $this->cloudCollectorPlatformHotelId($source),
                        ],
                ];
                if ($platform === 'ctrip' && $dataPeriod === 'realtime_snapshot') {
                    $syncOptions = (new CtripCollectorWorkflowService())->applyFlowOptions([
                        ...$syncOptions,
                        'collector_flow' => 'realtime',
                        'bounded_capture_sections' => implode(
                            ',',
                            (array)($orderedPlan['sections'] ?? [])
                        ),
                    ]);
                }
                $result = $service->syncDataSource(
                    $systemUser,
                    (int)$source['id'],
                    $syncOptions
                );
            } catch (\Throwable $e) {
                $failedCount++;
                $failedPlatforms[$platform] = true;
                $platformResults[] = [
                    'platform' => $platform,
                    'data_source_id' => (int)$source['id'],
                    'success' => false,
                    'saved_count' => 0,
                    'reused_verified_count' => 0,
                    'run_readback' => [],
                    'ordered_collection' => $orderedPlan,
                    'message' => 'ordered_profile_capture_failed',
                ];
                $messages[] = strtoupper($platform) . ' 数据源#' . (int)$source['id'] . ': ' . $e->getMessage();
                continue;
            }

            if (($result['reused_active_task'] ?? false) === true
                && strtolower(trim((string)($result['status'] ?? ''))) === 'in_progress'
            ) {
                $inProgressPlatforms[$platform] = true;
                $platformResults[] = [
                    'platform' => $platform,
                    'data_source_id' => (int)$source['id'],
                    'task_id' => max(0, (int)($result['task_id'] ?? 0)),
                    'success' => false,
                    'status' => 'in_progress',
                    'reused_active_task' => true,
                    'saved_count' => 0,
                    'reused_verified_count' => 0,
                    'run_readback' => [],
                    'ordered_collection' => $orderedPlan,
                    'message' => 'data_source_sync_task_reused_in_progress',
                ];
                $messages[] = strtoupper($platform) . ' Profile task in progress';
                continue;
            }

            $sourceSavedCount = (int)($result['saved_count'] ?? 0);
            $savedCount += $sourceSavedCount;
            if (is_array($result['timing'] ?? null)) {
                $timing = $this->sumTiming($timing, $result['timing']);
            }
            $savedByPlatform[$platform] = ($savedByPlatform[$platform] ?? 0) + $sourceSavedCount;
            $runReadback = is_array($result['run_readback'] ?? null) ? $result['run_readback'] : [];
            $compositeReadbackVerified = $this->orderedCompositeReadbackVerified(
                $source,
                $dataDate,
                $dataPeriod,
                $runReadback
            );
            $coreReadbackVerified = $this->runReadbackCoreVerified($runReadback)
                || $compositeReadbackVerified;
            if ($coreReadbackVerified) {
                $evidenceByPlatform[$platform] = ($evidenceByPlatform[$platform] ?? 0) + $sourceSavedCount;
            }
            $platformResults[] = [
                'platform' => $platform,
                'data_source_id' => (int)$source['id'],
                'success' => $coreReadbackVerified,
                'saved_count' => $sourceSavedCount,
                'reused_verified_count' => 0,
                'run_readback' => $runReadback,
                'composite_readback_verified' => $compositeReadbackVerified,
                'ordered_collection' => $orderedPlan,
                'message' => (string)($result['message'] ?? $result['status'] ?? '-'),
            ];
            if (!$coreReadbackVerified) {
                $failedCount++;
                $failedPlatforms[$platform] = true;
            }
            $messages[] = strtoupper($platform) . ' 数据源#' . (int)$source['id'] . ': ' . (string)($result['message'] ?? $result['status'] ?? '-');
        }

        $verifiedEvidenceCount = $savedCount + $reusedVerifiedCount;
        if ($verifiedEvidenceCount > 0) {
            if (($savedByPlatform['ctrip'] ?? 0) > 0) {
                $this->updateCtripLatestFetchStatus($hotelId, date('Y-m-d H:i:s'), $dataDate, (int)$savedByPlatform['ctrip']);
            }
            $messagePrefix = $failedCount > 0 ? '浏览器 Profile 已写入但本次核心指标回执不完整' : '浏览器 Profile 数据源同步并验证本次核心指标回执';
            return [
                'attempted' => true,
                'success' => $failedCount === 0 && $inProgressPlatforms === [],
                'status' => $inProgressPlatforms !== [] && $failedCount === 0
                    ? 'in_progress'
                    : ($failedCount === 0 ? 'success' : 'partial_success'),
                'message' => "{$messagePrefix} {$savedCount} 条",
                'saved_count' => $savedCount,
                'reused_verified_count' => $reusedVerifiedCount,
                'data_period' => $dataPeriod,
                'timing' => $timing,
                'platform_results' => $platformResults,
                'failed_platforms' => array_keys($failedPlatforms),
                'in_progress_platforms' => array_keys($inProgressPlatforms),
                'successful_platforms' => array_keys(array_filter(
                    $evidenceByPlatform,
                    static fn(int $count, string $platform): bool => $count > 0 && !isset($failedPlatforms[$platform]),
                    ARRAY_FILTER_USE_BOTH
                )),
                'required_platforms' => $targetPlatforms,
            ];
        }

        if ($inProgressPlatforms !== [] && $failedCount === 0) {
            return [
                'attempted' => true,
                'success' => false,
                'status' => 'in_progress',
                'message' => 'existing_browser_profile_task_in_progress',
                'saved_count' => 0,
                'reused_verified_count' => 0,
                'data_period' => $dataPeriod,
                'timing' => $timing,
                'platform_results' => $platformResults,
                'failed_platforms' => [],
                'in_progress_platforms' => array_keys($inProgressPlatforms),
                'successful_platforms' => [],
                'required_platforms' => $targetPlatforms,
            ];
        }

        return [
            'attempted' => true,
            'success' => false,
            'message' => '浏览器 Profile 数据源同步失败：' . implode('；', array_slice($messages, 0, 3)),
            'saved_count' => 0,
            'reused_verified_count' => 0,
            'data_period' => $dataPeriod,
            'timing' => $timing,
            'platform_results' => $platformResults,
            'failed_platforms' => array_keys($failedPlatforms),
            'in_progress_platforms' => array_keys($inProgressPlatforms),
            'successful_platforms' => [],
            'required_platforms' => $targetPlatforms,
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @return array{plan: array<string, mixed>, reused_run_readback: array<string, mixed>}
     */
    private function orderedBrowserProfileExecution(
        array $source,
        string $dataDate,
        string $dataPeriod
    ): array {
        $platform = strtolower(trim((string)($source['platform'] ?? '')));
        if ($dataPeriod !== 'historical_daily') {
            return [
                'plan' => OtaOrderedCollectionPlanner::requestPlan(
                    $platform,
                    $dataDate,
                    [],
                    'realtime_collection_outside_yesterday_contract'
                ),
                'reused_run_readback' => [],
            ];
        }

        $sourceStatus = strtolower(trim((string)($source['status'] ?? '')));
        $sourceRecoveryRequired = in_array(
            $sourceStatus,
            ['failed', 'waiting_config'],
            true
        );
        $rows = $this->storedProfileRowsForPlan(
            (int)($source['system_hotel_id'] ?? 0),
            (int)($source['id'] ?? 0),
            $platform,
            $dataDate
        );
        $plan = OtaOrderedCollectionPlanner::requestPlanFromStoredRows(
            $platform,
            $dataDate,
            $rows,
            $sourceRecoveryRequired
        );
        if (($plan['sections'] ?? []) !== []) {
            $plannedSections = array_values(array_filter(
                array_merge(...array_map(
                    static fn($section): array => preg_split('/\s*,\s*/', trim((string)$section)) ?: [],
                    (array)$plan['sections']
                )),
                static fn(string $section): bool => trim($section) !== ''
            ));
            // Browser Profile collection shares one local login session.  Run one
            // missing section per bounded task: persistence/readback of the first
            // section becomes the evidence that selects the next missing section.
            // This prevents a slow traffic page from repeating an already-complete
            // business capture in the same task.
            if (count($plannedSections) > 1) {
                $plan['planned_sections'] = $plannedSections;
                $plan['pending_sections'] = array_slice($plannedSections, 1);
                $plan['sections'] = [$plannedSections[0]];
                $plan['execution_mode'] = 'single_section_bounded';
            }
            return ['plan' => $plan, 'reused_run_readback' => []];
        }

        $readback = $this->existingVerifiedProfileRunReadback(
            (int)($source['system_hotel_id'] ?? 0),
            (int)($source['id'] ?? 0),
            $platform,
            $dataDate
        );
        if ($readback !== []) {
            return ['plan' => $plan, 'reused_run_readback' => $readback];
        }

        $plan = OtaOrderedCollectionPlanner::requestPlan(
            $platform,
            $dataDate,
            OtaOrderedCollectionPlanner::requiredFieldKeys($platform),
            'verified_rows_without_bound_run_readback'
        );
        $plannedSections = array_values(array_filter(
            array_merge(...array_map(
                static fn($section): array => preg_split('/\s*,\s*/', trim((string)$section)) ?: [],
                (array)($plan['sections'] ?? [])
            )),
            static fn(string $section): bool => trim($section) !== ''
        ));
        if (count($plannedSections) > 1) {
            $plan['planned_sections'] = $plannedSections;
            $plan['pending_sections'] = array_slice($plannedSections, 1);
            $plan['sections'] = [$plannedSections[0]];
            $plan['execution_mode'] = 'single_section_bounded';
        }
        $plan['stage'] = 'conflict_recovery';
        $plan['source_recovery_required'] = true;
        $plan['eligible_row_count'] = count(OtaOrderedCollectionPlanner::storedCoreRows($platform, $rows));
        return ['plan' => $plan, 'reused_run_readback' => []];
    }

    /** @return array<int, array<string, mixed>> */
    private function storedProfileRowsForPlan(
        int $hotelId,
        int $sourceId,
        string $platform,
        string $dataDate
    ): array {
        return (new PlatformDataSyncService())->readStoredRowsForCollectionPlan(
            $hotelId,
            $sourceId,
            $platform,
            $dataDate
        );
    }

    /** @return array<string, mixed> */
    private function existingVerifiedProfileRunReadback(
        int $hotelId,
        int $sourceId,
        string $platform,
        string $dataDate
    ): array {
        if ($hotelId <= 0 || $sourceId <= 0) {
            return [];
        }
        try {
            $tasks = Db::name('platform_data_sync_tasks')
                ->where('system_hotel_id', $hotelId)
                ->where('data_source_id', $sourceId)
                ->where('platform', $platform)
                ->whereIn('status', ['success', 'partial_success'])
                ->order('id', 'desc')
                ->limit(30)
                ->select()
                ->toArray();
        } catch (\Throwable) {
            return [];
        }
        foreach ($tasks as $task) {
            $stats = json_decode((string)($task['stats_json'] ?? ''), true);
            $readback = is_array($stats)
                && is_array($stats['run_readback'] ?? null)
                ? $stats['run_readback']
                : [];
            if ($this->runReadbackCoreVerified($readback)
                && (int)($readback['system_hotel_id'] ?? 0) === $hotelId
                && (int)($readback['data_source_id'] ?? 0) === $sourceId
                && strtolower(trim((string)($readback['platform'] ?? ''))) === $platform
                && substr(trim((string)($readback['target_date'] ?? '')), 0, 10) === $dataDate
                && strtolower(trim((string)($readback['data_period'] ?? ''))) === 'historical_daily'
            ) {
                return $readback;
            }
        }
        return [];
    }

    /**
     * A gap-only run may write traffic while the same source/date already has
     * read-back revenue facts, or vice versa. The current task must still own
     * real rows and source traces; the final external verifier remains the
     * authority for the combined target-date facts.
     *
     * @param array<string, mixed> $source
     * @param array<string, mixed> $readback
     */
    private function orderedCompositeReadbackVerified(
        array $source,
        string $dataDate,
        string $dataPeriod,
        array $readback
    ): bool {
        $platform = strtolower(trim((string)($source['platform'] ?? '')));
        $sourceId = (int)($source['id'] ?? 0);
        $hotelId = (int)($source['system_hotel_id'] ?? 0);
        $p0Status = strtolower(trim((string)($readback['p0_status'] ?? '')));
        if ($dataPeriod !== 'historical_daily'
            || ($readback['readback_verified'] ?? false) !== true
            || !in_array($p0Status, ['ready', 'not_required'], true)
            || (int)($readback['sync_task_id'] ?? 0) <= 0
            || (int)($readback['data_source_id'] ?? 0) !== $sourceId
            || (int)($readback['system_hotel_id'] ?? 0) !== $hotelId
            || strtolower(trim((string)($readback['platform'] ?? ''))) !== $platform
            || substr(trim((string)($readback['target_date'] ?? '')), 0, 10) !== $dataDate
            || strtolower(trim((string)($readback['data_period'] ?? ''))) !== 'historical_daily'
            || array_values(array_filter(
                is_array($readback['row_ids'] ?? null) ? $readback['row_ids'] : [],
                static fn($value): bool => (int)$value > 0
            )) === []
            || array_values(array_filter(
                is_array($readback['source_trace_ids'] ?? null) ? $readback['source_trace_ids'] : [],
                static fn($value): bool => trim((string)$value) !== ''
            )) === []
        ) {
            return false;
        }
        $rows = $this->storedProfileRowsForPlan($hotelId, $sourceId, $platform, $dataDate);
        return OtaOrderedCollectionPlanner::missingFieldKeys(
            $platform,
            OtaOrderedCollectionPlanner::storedCoreRows($platform, $rows)
        ) === [];
    }

    /**
     * @param array<int, int> $sourceIds
     * @param array<string, mixed> $outcome
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function buildMachineReceipt(
        int $hotelId,
        string $targetDate,
        array $sourceIds,
        array $outcome,
        array $result,
        string $dataPeriod = 'historical_daily'
    ): array
    {
        return (new ScheduledAutoFetchPolicy())->buildDailyTrustReceipt(
            $hotelId,
            $targetDate,
            $sourceIds,
            $outcome,
            $result,
            $dataPeriod
        );
    }

    /** @param array<string, mixed> $receipt */
    private function machineReceiptDailyTrustReady(
        array $receipt,
        ?string $expectedDate = null,
        ?int $expectedHotelId = null
    ): bool
    {
        return (new ScheduledAutoFetchPolicy())->dailyTrustReceiptReady(
            $receipt,
            $expectedDate,
            $expectedHotelId
        );
    }

    /** @param array<string, mixed> $receipt */
    private function writeMachineReceipt(Output $output, array $receipt): void
    {
        $json = json_encode($receipt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($json)) {
            $output->writeln('SUXIOS_AUTO_FETCH_RECEIPT=' . $json);
        }
    }

    /** @param array<string, mixed> $gapReport */
    private function writeGapReport(Output $output, array $gapReport): void
    {
        $json = json_encode($gapReport, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($json)) {
            $output->writeln('SUXIOS_OTA_YESTERDAY_GAP_REPORT=' . $json);
        }
    }

    /** @param array<string, mixed> $receipt @return array<int, string> */
    private function receiptRecollectionPlatforms(array $receipt): array
    {
        $required = (new ScheduledAutoFetchPolicy())->normalizePlatforms(
            $receipt['required_platforms'] ?? ['ctrip', 'meituan']
        );
        if ($required === []) {
            $required = ['ctrip', 'meituan'];
        }
        $verifier = is_array($receipt['authority_verifier'] ?? null)
            ? $receipt['authority_verifier']
            : [];
        $verified = (new ScheduledAutoFetchPolicy())->normalizePlatforms(
            $verifier['verified_platforms'] ?? []
        );
        $missing = array_values(array_diff($required, $verified));
        if ($missing !== []) {
            return $missing;
        }
        foreach ((array)($verifier['issue_codes'] ?? []) as $code) {
            $code = strtolower(trim((string)$code));
            foreach ($required as $platform) {
                if (str_starts_with($code, $platform . '_')) {
                    $missing[$platform] = $platform;
                }
            }
        }
        return $missing === [] ? $required : array_values($missing);
    }

    private function runReadbackCoreVerified(array $receipt): bool
    {
        $metricKeys = array_values(array_unique(array_map(
            static fn($value): string => strtolower(trim((string)$value)),
            is_array($receipt['verified_metric_keys'] ?? null) ? $receipt['verified_metric_keys'] : []
        )));
        return ($receipt['readback_verified'] ?? false) === true
            && strtolower(trim((string)($receipt['p0_status'] ?? ''))) === 'ready'
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
        foreach (['authority_verifier', 'trust_receipt', 'gap_report'] as $evidenceField) {
            if (is_array($details[$evidenceField] ?? null)) {
                $runRecord[$evidenceField] = $details[$evidenceField];
            }
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
        foreach (['authority_verifier', 'trust_receipt', 'gap_report'] as $evidenceField) {
            if (is_array($details[$evidenceField] ?? null)) {
                $status['last_result'][$evidenceField] = $details[$evidenceField];
            }
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

    /**
     * Kept as a command-level compatibility seam for scheduled-run tests.
     * The actual grouping policy remains centralized in the planner.
     *
     * @param array<int, array<string, mixed>> $sources
     * @return array<int, array<string, mixed>>
     */
    private function oneSourcePerBrowserProfileAccount(array $sources): array
    {
        return OtaOrderedCollectionPlanner::oneSourcePerBrowserProfileAccount($sources);
    }

    /** @param array<string,mixed> $lock */
    private function profileLockIsStale(array $lock): bool
    {
        $startedAt = trim((string)($lock['started_at'] ?? ''));
        if ($startedAt === '') {
            return true;
        }
        $timestamp = strtotime($startedAt);
        return $timestamp === false || (time() - $timestamp) > self::PROFILE_LOCK_STALE_SECONDS;
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
