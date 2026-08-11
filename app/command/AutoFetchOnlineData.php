<?php
declare(strict_types=1);

namespace app\command;

use app\model\User;
use app\service\PlatformDataSyncService;
use app\service\CloudOtaCollectionScopeService;
use app\service\HotelCollectionPlanService;
use app\service\HotelCollectionRunReceiptService;
use app\service\DingdandaoOperatingTargetCaptureService;
use app\service\CtripCollectorWorkflowService;
use app\service\CanonicalOtaDailyOperationFinalizer;
use app\service\OtaFailureNotificationService;
use app\service\OtaLocalCollectorService;
use app\service\OtaCanonicalHistoryPromotionCoordinator;
use app\service\OtaOrderedCollectionPlanner;
use app\service\OtaTrafficAttributionService;
use app\service\OnlineDataAutoFetchStatusStore;
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
    private const NATURAL_HISTORICAL_CAPTURE_TIMEOUT_SECONDS = 600;
    private const LOCAL_PLAN_COMPLETION_TIMEOUT_SECONDS = 300;
    private const LOCAL_PLAN_POLL_INTERVAL_MICROSECONDS = 1_000_000;
    private const CLOUD_SINGLE_USER_LOCAL_HOTEL_IDS = [80];

    /** @var array<string, mixed> */
    private array $cloudCollectorScope = [];

    private ?User $cloudCollectorUser = null;

    /** @var array<string, mixed> */
    private array $cloudCollectorPreflight = [];

    /** Natural dispatcher attempt that invoked this command, when present. */
    private string $dispatcherRunId = '';

    /** @var array<string,mixed> Exact active plan gate for this dispatcher run. */
    private array $scheduledPlanGate = [];

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
            ->addOption('dispatcher-run-id', null, Option::VALUE_REQUIRED, 'Natural dispatcher UUID for task provenance binding')
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

        $dispatcherRunIdOption = $input->getOption('dispatcher-run-id');
        if ($dispatcherRunIdOption !== null) {
            $this->dispatcherRunId = $this->normalizeDispatcherRunId(
                (string)$dispatcherRunIdOption
            );
            if ($this->dispatcherRunId === '') {
                $output->writeln('dispatcher-run-id must be a canonical UUID.');
                return 1;
            }
            if (!$dailyOnly
                || $hotelId === null
                || $targetDate === null
                || $sourceIds === []
                || $platforms === []
            ) {
                $output->writeln(
                    'dispatcher-run-id requires daily-only with explicit hotel, target-date, source-ids, and platforms.'
                );
                return 1;
            }
        }
        if ($this->dispatcherRunId !== '') {
            $planGate = $this->scheduledCollectionPlanGate(
                (int)$hotelId,
                (string)$targetDate,
                $sourceIds,
                $platforms,
                $dailyOnly ? 'daily' : 'realtime'
            );
            $this->scheduledPlanGate = $planGate;
            $runReceipt = null;
            $runReceiptService = new HotelCollectionRunReceiptService();
            try {
                $runReceipt = $runReceiptService->begin($planGate);
                $output->writeln(
                    'SUXIOS_COLLECTION_RUN_RECEIPT='
                    . json_encode(
                        $runReceipt,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                    )
                );
            } catch (\Throwable $error) {
                try {
                    $committedRunReceipt = $runReceiptService->readExact(
                        $this->dispatcherRunId,
                        (int)$hotelId,
                        (string)$targetDate
                    );
                } catch (\Throwable) {
                    $committedRunReceipt = null;
                }
                if (is_array($committedRunReceipt)
                    && $this->durableSucceededRunReceiptReady(
                        $committedRunReceipt,
                        (int)$hotelId,
                        (string)$targetDate,
                        $sourceIds,
                        $platforms
                    )
                ) {
                    $runReceipt = $committedRunReceipt;
                    $output->writeln(
                        'SUXIOS_COLLECTION_RUN_RECEIPT='
                        . json_encode(
                            $runReceipt,
                            JSON_UNESCAPED_UNICODE
                                | JSON_UNESCAPED_SLASHES
                                | JSON_THROW_ON_ERROR
                        )
                    );
                } else {
                    $scopeChangedBlocked = in_array(trim($error->getMessage()), [
                        'hotel_collection_run_receipt_scope_mismatch',
                        'hotel_collection_run_receipt_source_scope_mismatch',
                    ], true) && $this->blockChangedScheduledCollectionScope(
                        $output,
                        (int)$hotelId,
                        (string)$targetDate
                    );
                    $planGate['status'] = 'blocked';
                    $planGate['collection_allowed'] = false;
                    $planGate['failure_reasons'] = [
                        ...(array)($planGate['failure_reasons'] ?? []),
                        [
                            'code' => $scopeChangedBlocked
                                ? 'plan_scope_changed_during_active_run'
                                : 'hotel_collection_run_receipt_write_failed',
                            'platform' => '',
                            'message' => $scopeChangedBlocked
                                ? 'The active run was sealed because its signed plan scope changed.'
                                : 'The durable per-hotel run receipt could not be written and read back.',
                        ],
                    ];
                    $this->scheduledPlanGate = $planGate;
                    try {
                        Log::error('Hotel collection run receipt begin failed', [
                            'hotel_id' => (int)$hotelId,
                            'business_date' => (string)$targetDate,
                            'dispatcher_run_id' => $this->dispatcherRunId,
                            'exception_type' => get_debug_type($error),
                        ]);
                    } catch (\Throwable) {
                        // A logging backend failure must not bypass the blocked gate.
                    }
                }
            }
            $output->writeln(
                'SUXIOS_COLLECTION_PLAN_GATE='
                . json_encode(
                    $planGate,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                )
            );
            if (is_array($runReceipt)
                && $this->durableSucceededRunReceiptReady(
                    $runReceipt,
                    (int)$hotelId,
                    (string)$targetDate,
                    $sourceIds,
                    $platforms
                )
            ) {
                $output->writeln(
                    'Hotel collection already has an exact durable succeeded run receipt; '
                    . 'no producer task was restarted.'
                );
                return 0;
            }
            if (($planGate['collection_allowed'] ?? false) !== true) {
                return 78;
            }
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

    /**
     * @param array<int,int> $sourceIds
     * @param array<int,string> $platforms
     * @return array<string,mixed>
     */
    protected function scheduledCollectionPlanGate(
        int $hotelId,
        string $businessDate,
        array $sourceIds,
        array $platforms,
        string $runMode
    ): array {
        try {
            $hotel = Db::name('hotels')
                ->where('id', $hotelId)
                ->where('status', 1)
                ->find();
            if (!is_array($hotel)) {
                throw new \RuntimeException('hotel_collection_plan_hotel_missing_or_disabled');
            }
            $gate = (new HotelCollectionPlanService())->authorizeExecutionScope(
                $hotel,
                $businessDate,
                $sourceIds,
                $platforms,
                $runMode
            );
            $gate['schema_version'] = 1;
            $gate['dispatcher_run_id'] = $this->dispatcherRunId;
            return $gate;
        } catch (\Throwable $error) {
            $failureCode = strtolower(trim($error->getMessage()));
            $failureCode = preg_replace('/[^a-z0-9_]+/', '_', $failureCode) ?? '';
            return [
                'schema_version' => 1,
                'status' => 'blocked',
                'collection_allowed' => false,
                'system_hotel_id' => $hotelId,
                'business_date' => $businessDate,
                'run_mode' => $runMode,
                'dispatcher_run_id' => $this->dispatcherRunId,
                'failure_reasons' => [[
                    'code' => trim(substr($failureCode, 0, 120), '_')
                        ?: 'hotel_collection_plan_gate_failed',
                    'platform' => '',
                    'message' => 'The hotel collection plan gate could not be verified.',
                ]],
                'automatic_device_substitution' => false,
                'sensitive_values_exposed' => false,
            ];
        }
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
            $tenantId = (int)($hotel['tenant_id'] ?? 0);
            $hotelName = (string)($hotel['name'] ?? $hotelId);
            $status = Cache::get("online_data_auto_fetch_status_{$hotelId}", []);
            $status = is_array($status) ? $status : [];
            if ($this->dispatcherRunId !== '') {
                // The signed active hotel plan is authoritative for an exact
                // dispatcher run. A legacy cache toggle must not silently
                // disable a plan that already passed the durable scope gate.
                $status['enabled'] = true;
            }
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
                ? [$this->explicitHistoricalRun($hotelId, $targetDateOverride)]
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
            $dueRuns = array_map(
                fn(array $run): array => $this->bindRunCacheScope($run, $sourceIds),
                $dueRuns
            );
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
                        $hotelId,
                        ($run['cache_scope_sources_fixed'] ?? false) === true
                            ? (array)($run['cache_scope_source_ids'] ?? [])
                            : null,
                        ($run['cache_scope_platforms_fixed'] ?? false) === true
                            ? (array)($run['cache_scope_platforms'] ?? [])
                            : null
                    ) && ((string)($run['period'] ?? '') !== 'historical_daily'
                        || (($executedReceipt['canonical_history_complete'] ?? false) === true
                            && ($this->dispatcherRunId === ''
                                || $this->cachedReceiptProducerLedgerStillTrusted(
                                    $executedReceipt,
                                    $hotelId,
                                    (string)$run['data_date'],
                                    ($run['cache_scope_sources_fixed'] ?? false) === true
                                        ? (array)($run['cache_scope_source_ids'] ?? [])
                                        : $sourceIds,
                                    ($run['cache_scope_platforms_fixed'] ?? false) === true
                                        ? (array)($run['cache_scope_platforms'] ?? [])
                                        : (array)($run['target_platforms'] ?? ['ctrip', 'meituan'])
                                ))
                            && $this->cachedHistoricalDailyReceiptRowsStillCurrent(
                                $executedReceipt,
                                $tenantId
                            )))
                    ) {
                        if ((string)($run['period'] ?? '') === 'historical_daily'
                            && is_array($executedReceipt['canonical_history_finalization'] ?? null)
                        ) {
                            $executedReceipt = $this->attachCanonicalDailyOperationFinalization(
                                $executedReceipt,
                                is_array($executedReceipt['canonical_history_finalization'] ?? null)
                                    ? $executedReceipt['canonical_history_finalization']
                                    : [],
                                $tenantId,
                                $hotelId,
                                $status
                            );
                            Cache::set($run['executed_key'], $executedReceipt, 86400);
                            $this->persistCachedCanonicalDailyOperationStatus(
                                $executedReceipt,
                                $hotelId,
                                (string)$run['data_date'],
                                (string)$run['slot_id']
                            );
                        }
                        $output->writeln("Hotel {$hotelName} {$run['label']} already executed with requested-scope P0 proof, skipped.");
                        if (!$this->markScheduledNoCollectionOutcome(
                            $output,
                            $hotelId,
                            (string)$run['data_date'],
                            'verified_cache_reused'
                        )) {
                            $hasIncompleteDueRun = true;
                        }
                        $this->writeReusedCacheReceipt(
                            $output,
                            $executedReceipt,
                            $hotelId,
                            (string)$run['data_date']
                        );
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
                    $this->markScheduledNoCollectionOutcome(
                        $output,
                        $hotelId,
                        (string)$run['data_date'],
                        $reason === 'retry exhausted' ? 'retry_exhausted' : 'retry_cooldown'
                    );
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
                    $this->markScheduledNoCollectionOutcome(
                        $output,
                        $hotelId,
                        (string)$run['data_date'],
                        'profile_locked'
                    );
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
                            $sourceIds,
                            $forceRerun
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

                    if ($this->dispatcherRunId !== '') {
                        $result['platform_results'] = $this->scopedScheduledPlatformResults(
                            is_array($result['platform_results'] ?? null)
                                ? $result['platform_results']
                                : [],
                            $hotelId,
                            (string)$run['data_date'],
                            (new ScheduledAutoFetchPolicy())->normalizePlatforms(
                                $run['target_platforms'] ?? []
                            ) ?: ['ctrip', 'meituan']
                        );
                        if (!$this->recordScheduledPlatformResults(
                            $hotelId,
                            (string)$run['data_date'],
                            $result['platform_results']
                        )) {
                            $result['message'] = trim(
                                (string)($result['message'] ?? '')
                                . '; hotel_collection_run_receipt_write_failed',
                                '; '
                            );
                            $this->updateStatus(
                                $hotelId,
                                false,
                                (string)$result['message'],
                                (string)$run['data_date'],
                                [
                                    'status' => 'in_progress',
                                    'saved_count' => 0,
                                    'data_period' => $run['period'],
                                    'slot_id' => $run['slot_id'],
                                    'platform_results' => $result['platform_results'],
                                    'failed_platforms' => [],
                                    'in_progress_platforms' => (new ScheduledAutoFetchPolicy())
                                        ->normalizePlatforms($run['target_platforms'] ?? [])
                                        ?: ['ctrip', 'meituan'],
                                    'successful_platforms' => [],
                                    'failure_reason' => 'hotel_collection_run_receipt_write_failed',
                                    'dispatcher_run_id' => $this->dispatcherRunId,
                                ]
                            );
                            $output->writeln(
                                "Hotel {$hotelName} {$run['label']} in_progress: "
                                . 'hotel_collection_run_receipt_write_failed'
                            );
                            // The producer task may already be queued, running, or terminal.
                            // Keep the same dispatcher active so the next scheduler trigger
                            // can re-read that exact task and retry the durable receipt. A
                            // terminal machine receipt here would rotate the dispatcher and
                            // orphan the original account/device-bound task.
                            $hasIncompleteDueRun = true;
                            continue;
                        }
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
                    $canonicalFinalization = (new OtaCanonicalHistoryPromotionCoordinator())
                        ->finalize($receipt, $tenantId, $hotelId);
                    $receipt['canonical_history_finalization'] = $canonicalFinalization;
                    $receipt['canonical_history_complete'] =
                        ($canonicalFinalization['canonical_history_complete'] ?? false) === true;
                    $receipt = $this->attachCanonicalDailyOperationFinalization(
                        $receipt,
                        $canonicalFinalization,
                        $tenantId,
                        $hotelId,
                        $status
                    );
                    $verifier = is_array($canonicalFinalization['overall_verifier'] ?? null)
                        ? $canonicalFinalization['overall_verifier']
                        : [];
                    if ($verifier !== []) {
                        Cache::set(
                            "online_data_p0_authority_receipt_{$hotelId}_{$run['data_date']}",
                            $verifier,
                            86400 * 2
                        );
                    }
                    if ((string)$run['period'] === 'historical_daily') {
                        $receipt = (new ScheduledAutoFetchPolicy())->attachAuthorityVerifier(
                            $receipt,
                            $verifier
                        );
                    }
                    $trustedReady = $this->machineReceiptDailyTrustReady(
                        $receipt,
                        (string)$run['data_date'],
                        $hotelId,
                        ($run['cache_scope_sources_fixed'] ?? false) === true
                            ? (array)($run['cache_scope_source_ids'] ?? [])
                            : null,
                        ($run['cache_scope_platforms_fixed'] ?? false) === true
                            ? (array)($run['cache_scope_platforms'] ?? [])
                            : null
                    ) && ($receipt['canonical_history_complete'] ?? false) === true;
                    if ($this->dispatcherRunId !== '') {
                        $finalizedRunReceipt = $this->finalizeScheduledCollectionReceipt(
                            $hotelId,
                            (string)$run['data_date'],
                            $receipt,
                            $trustedReady
                        );
                        if (!is_array($finalizedRunReceipt)) {
                            $result['message'] = trim(
                                (string)($result['message'] ?? '')
                                . '; hotel_collection_run_final_receipt_write_failed',
                                '; '
                            );
                            $this->updateStatus(
                                $hotelId,
                                false,
                                (string)$result['message'],
                                (string)$run['data_date'],
                                [
                                    'status' => 'in_progress',
                                    'saved_count' => (int)($outcome['saved_count'] ?? 0),
                                    'data_period' => $run['period'],
                                    'slot_id' => $run['slot_id'],
                                    'platform_results' => is_array($result['platform_results'] ?? null)
                                        ? $result['platform_results']
                                        : [],
                                    'failed_platforms' => [],
                                    'in_progress_platforms' => (array)($outcome['required_platforms'] ?? []),
                                    'successful_platforms' => [],
                                    'failure_reason' => 'hotel_collection_run_final_receipt_write_failed',
                                    'dispatcher_run_id' => $this->dispatcherRunId,
                                ]
                            );
                            $output->writeln(
                                "Hotel {$hotelName} {$run['label']} in_progress: "
                                . 'hotel_collection_run_final_receipt_write_failed'
                            );
                            // Do not publish a terminal AUTO receipt. The exact producer
                            // tasks and rows are already bound to this dispatcher; keeping
                            // it active lets the next trigger re-read them and retry only
                            // the durable finalization step.
                            $hasIncompleteDueRun = true;
                            continue;
                        }
                        $receipt['collection_run_status'] = (string)(
                            $finalizedRunReceipt['status'] ?? ''
                        );
                        $receipt['collection_run_readback_verified'] =
                            ($finalizedRunReceipt['readback_verified'] ?? false) === true;
                        $receipt['collection_run_failure_code'] = trim((string)(
                            $finalizedRunReceipt['failure_code'] ?? ''
                        )) ?: null;
                        $receipt['trust_receipt_digest'] = trim((string)(
                            $finalizedRunReceipt['trust_receipt_digest'] ?? ''
                        )) ?: null;
                    }
                    if ($this->dispatcherRunId !== '') {
                        $receipt['pms_run_attachment'] = $this->attachExactScheduledPmsCapture(
                            $tenantId,
                            $hotelId,
                            (string)$run['data_date']
                        );
                    }
                    if (!$trustedReady && $outcome['complete']) {
                        $outcome['complete'] = false;
                        $outcome['status'] = 'partial_success';
                        $outcome['failed_platforms'] = $this->receiptRecollectionPlatforms($receipt);
                        $outcome['successful_platforms'] = array_values(array_diff(
                            $outcome['required_platforms'],
                            $outcome['failed_platforms']
                        ));
                        $result['message'] = trim(
                            (string)($result['message'] ?? '') . '; requested_scope_authority_or_history_incomplete',
                            '; '
                        );
                        $receipt = $this->downgradeUntrustedMachineReceipt($receipt);
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
                        // The unscoped canonical key belongs to the legacy
                        // dynamic full-range scheduler. A fixed single-source
                        // or single-platform receipt must never make that
                        // scheduler skip another platform's real collection.
                        if ((string)$run['period'] === 'historical_daily'
                            && ($run['cache_scope_sources_fixed'] ?? true) === false
                            && ($run['cache_scope_platforms_fixed'] ?? true) === false
                        ) {
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

    private function normalizeDispatcherRunId(string $value): string
    {
        $value = strtolower(trim($value));
        return preg_match(
            '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',
            $value
        ) === 1 ? $value : '';
    }

    /** @param array<string,mixed> $readback @param array<string,mixed> $stats */
    private function reusableNaturalDispatcherReadback(array $readback, array $stats = []): bool
    {
        if ($this->dispatcherRunId === '') {
            return true;
        }
        $readbackRunId = $this->normalizeDispatcherRunId(
            (string)($readback['dispatcher_run_id'] ?? '')
        );
        if ($readbackRunId === ''
            || strtolower(trim((string)($readback['trigger_type'] ?? ''))) !== 'daily_profile_reuse'
        ) {
            return false;
        }
        return $stats === [] || $this->normalizeDispatcherRunId(
            (string)($stats['dispatcher_run_id'] ?? '')
        ) === $readbackRunId;
    }

    /** @param array<string,mixed> $readback */
    private function currentDispatcherOwnsRunReadback(array $readback): bool
    {
        return $this->dispatcherRunId === ''
            || $this->normalizeDispatcherRunId(
                (string)($readback['dispatcher_run_id'] ?? '')
            ) === $this->dispatcherRunId;
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
     * Return only explicit identity aliases from this exact source config.
     * Meituan may expose either its store id or POI id in the current response;
     * both are authoritative only when configured on this same source row.
     *
     * @param array<string, mixed> $source
     * @return array<int, string>
     */
    private function profileSourcePlatformHotelIds(array $source): array
    {
        $config = json_decode((string)($source['config_json'] ?? ''), true);
        if (!is_array($config)) {
            return [];
        }
        $platform = strtolower(trim((string)($source['platform'] ?? '')));
        $keys = $platform === 'meituan'
            ? ['platform_hotel_id', 'store_id', 'storeId', 'poi_id', 'poiId']
            : ['platform_hotel_id', 'hotel_id', 'hotelId', 'ctrip_hotel_id', 'ctripHotelId', 'node_id', 'nodeId'];
        $identifiers = [];
        foreach ($keys as $key) {
            $identifier = trim((string)($config[$key] ?? ''));
            if ($identifier !== '') {
                $identifiers[$identifier] = true;
            }
        }
        return array_keys($identifiers);
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
    private function explicitHistoricalRun(int $hotelId, string $targetDate): array
    {
        return [
            'slot_id' => "historical:{$targetDate}",
            'period' => 'historical_daily',
            'data_date' => $targetDate,
            'executed_key' => "online_data_historical_executed_{$hotelId}_{$targetDate}",
            'retry_key' => "online_data_historical_retry_{$hotelId}_{$targetDate}",
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

    /**
     * @param array<string, mixed> $run
     * @param array<int, int> $sourceIds
     * @return array<string, mixed>
     */
    private function bindRunCacheScope(array $run, array $sourceIds): array
    {
        $sourceIds = array_values(array_unique(array_filter(
            array_map('intval', $sourceIds),
            static fn(int $id): bool => $id > 0
        )));
        sort($sourceIds, SORT_NUMERIC);
        $policy = new ScheduledAutoFetchPolicy();
        $platforms = $policy->normalizePlatforms($run['target_platforms'] ?? []);
        $suffix = $policy->cacheScopeSuffix($sourceIds, $platforms);
        $run['executed_key'] = (string)($run['executed_key'] ?? '') . $suffix;
        $run['retry_key'] = (string)($run['retry_key'] ?? '') . $suffix;
        $run['cache_scope_source_ids'] = $sourceIds;
        $run['cache_scope_platforms'] = $platforms;
        $run['cache_scope_sources_fixed'] = $sourceIds !== [];
        $run['cache_scope_platforms_fixed'] = $platforms !== [];
        return $run;
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
        array $sourceIds = [],
        bool $forceRerun = false
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
        $profileResult = $this->syncBrowserProfileSources(
            $hotelId,
            $dataDate,
            $browserHeadless,
            $dataPeriod,
            $snapshotTime,
            $ctripSectionConcurrency,
            $targetPlatforms,
            $sourceIds,
            $forceRerun
        );
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
                'missing_source_ids' => $profileResult['missing_source_ids'] ?? [],
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
            'missing_source_ids' => [],
            'required_platforms' => $targetPlatforms,
        ];
    }

    private function syncBrowserProfileSources(int $hotelId, string $dataDate, bool $browserHeadless = true, string $dataPeriod = 'historical_daily', ?string $snapshotTime = null, int $ctripSectionConcurrency = 3, array $targetPlatforms = [], array $sourceIds = [], bool $forceRerun = false): array
    {
        $dataPeriod = $this->normalizeOnlineDailyDataPeriod($dataPeriod) ?: 'historical_daily';
        $snapshotTime = $this->normalizeDateTime($snapshotTime) ?? date('Y-m-d H:i:s');
        $ctripSectionConcurrency = $this->normalizeCtripSectionConcurrency($ctripSectionConcurrency);
        $policy = new ScheduledAutoFetchPolicy();
        $targetPlatforms = $policy->normalizePlatforms($targetPlatforms);
        if ($targetPlatforms === []) {
            $targetPlatforms = ['ctrip', 'meituan'];
        }
        $missingSourceIds = [];
        try {
            $sourceIds = array_values(array_unique(array_filter(array_map('intval', $sourceIds), static fn(int $id): bool => $id > 0)));
            $scheduledIngestionMethods = $this->dispatcherRunId !== '' && $sourceIds !== []
                ? ['browser_profile', 'local_collector']
                : ['browser_profile'];
            $sourceQuery = Db::name('platform_data_sources')
                ->where('enabled', 1)
                ->whereIn('status', ['ready', 'success', 'partial_success', 'failed', 'waiting_config'])
                ->where('system_hotel_id', $hotelId)
                ->whereIn('platform', ['ctrip', 'meituan'])
                ->whereIn('ingestion_method', $scheduledIngestionMethods);
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
                'missing_source_ids' => $missingSourceIds,
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
        $messages = [];
        $savedCount = 0;
        $savedByPlatform = [];
        $evidenceByPlatform = [];
        $reusedVerifiedCount = 0;
        $failedCount = count($missingPlatforms) + ($missingSourceIds === [] ? 0 : 1);
        $failedPlatforms = array_fill_keys($missingPlatforms, true);
        $inProgressPlatforms = [];
        $platformResults = array_map(static fn(string $platform): array => [
            'platform' => $platform,
            'success' => false,
            'saved_count' => 0,
            'message' => 'scheduled_profile_source_missing',
        ], $missingPlatforms);
        foreach ($missingSourceIds as $missingSourceId) {
            $platformResults[] = [
                'platform' => 'source_scope',
                'data_source_id' => $missingSourceId,
                'success' => false,
                'saved_count' => 0,
                'message' => 'scheduled_profile_source_scope_missing',
            ];
            $messages[] = 'SOURCE#' . $missingSourceId . ': scheduled_profile_source_scope_missing';
        }
        $timing = [];
        foreach ($sources as $source) {
            $platform = strtolower((string)($source['platform'] ?? 'source'));
            $ingestionMethod = strtolower(trim((string)($source['ingestion_method'] ?? '')));
            if ($ingestionMethod === 'local_collector') {
                try {
                    $localCollector = new OtaLocalCollectorService();
                    $localScope = [
                        'tenant_id' => (int)($this->scheduledPlanGate['tenant_id'] ?? 0),
                        'system_hotel_id' => $hotelId,
                        'platform' => $platform,
                        'data_source_id' => (int)($source['id'] ?? 0),
                        'business_date' => $dataDate,
                        'dispatcher_run_id' => $this->dispatcherRunId,
                        'execution_owner_user_id' => (int)(
                            $this->scheduledPlanGate['execution_owner_user_id'] ?? 0
                        ),
                    ];
                    $localResult = $localCollector->schedulePlanCollection($localScope);
                    $localResult = $this->awaitScheduledLocalCollection(
                        $localCollector,
                        $localScope,
                        $localResult
                    );
                    $localStatus = strtolower(trim((string)($localResult['status'] ?? 'failed')));
                    $activeLocalStatus = in_array($localStatus, [
                        'queued',
                        'in_progress',
                        'device_offline',
                        'waiting_user_login',
                        'verification_required',
                    ], true);
                    if ($activeLocalStatus) {
                        $inProgressPlatforms[$platform] = true;
                        $localResult['source_task_status'] = $localStatus;
                        $localResult['status'] = 'in_progress';
                        $localResult['reused_active_task'] = true;
                    }
                    $localResult['platform'] = $platform;
                    $localResult['system_hotel_id'] = $hotelId;
                    $localResult['target_date'] = $dataDate;
                    $localResult['dispatcher_run_id'] = $this->dispatcherRunId;
                    $localResult['data_source_id'] = (int)($source['id'] ?? 0);
                    $localResult['ingestion_method'] = 'local_collector';
                    $localResult['collection_quality'] = [
                        'status' => ($localResult['success'] ?? false) === true
                            ? 'verified'
                            : ($activeLocalStatus ? 'in_progress' : 'not_verified'),
                    ];
                    $localSavedCount = max(0, (int)($localResult['saved_count'] ?? 0));
                    $savedCount += $localSavedCount;
                    $savedByPlatform[$platform] = ($savedByPlatform[$platform] ?? 0)
                        + $localSavedCount;
                    if (($localResult['success'] ?? false) === true) {
                        $evidenceByPlatform[$platform] = ($evidenceByPlatform[$platform] ?? 0)
                            + max(1, (int)($localResult['readback_count'] ?? 0));
                    } elseif (!$activeLocalStatus) {
                        $failedCount++;
                        $failedPlatforms[$platform] = true;
                    }
                    $platformResults[] = $localResult;
                    $messages[] = strtoupper($platform) . ' LOCAL#'
                        . (int)($source['id'] ?? 0) . ': '
                        . (string)($localResult['message'] ?? $localResult['status'] ?? 'failed');
                } catch (\Throwable $error) {
                    $failureCode = strtolower(trim($error->getMessage()));
                    $failureCode = preg_replace('/[^a-z0-9._:-]+/', '_', $failureCode) ?? '';
                    $failureCode = trim(substr($failureCode, 0, 120), '_')
                        ?: 'local_collector_plan_schedule_failed';
                    $failedCount++;
                    $failedPlatforms[$platform] = true;
                    $platformResults[] = [
                        'platform' => $platform,
                        'system_hotel_id' => $hotelId,
                        'data_source_id' => (int)($source['id'] ?? 0),
                        'ingestion_method' => 'local_collector',
                        'target_date' => $dataDate,
                        'dispatcher_run_id' => $this->dispatcherRunId,
                        'success' => false,
                        'status' => 'failed',
                        'failure_reason' => $failureCode,
                        'saved_count' => 0,
                        'readback_count' => 0,
                        'readback_verified' => false,
                        'run_readback' => [],
                        'historical_core_contract_status' => 'blocked',
                        'message' => $failureCode,
                    ];
                    Log::warning('Scheduled local collector source failed', [
                        'hotel_id' => $hotelId,
                        'platform' => $platform,
                        'data_source_id' => (int)($source['id'] ?? 0),
                        'exception_type' => get_debug_type($error),
                    ]);
                    $messages[] = strtoupper($platform) . ' LOCAL#'
                        . (int)($source['id'] ?? 0) . ': ' . $failureCode;
                }
                continue;
            }
            $orderedPlan = [];
            try {
                $orderedExecution = $this->orderedBrowserProfileExecution(
                    $source,
                    $dataDate,
                    $dataPeriod,
                    $forceRerun
                );
                $orderedPlan = $orderedExecution['plan'];
                $reusedRunReadback = $orderedExecution['reused_run_readback'];
                if (!$forceRerun
                    && $dataPeriod === 'historical_daily'
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
                        'system_hotel_id' => $hotelId,
                        'data_source_id' => (int)$source['id'],
                        'ingestion_method' => 'browser_profile',
                        'target_date' => $dataDate,
                        'dispatcher_run_id' => $this->dispatcherRunId,
                        'success' => true,
                        'status' => 'success',
                        'source_task_status' => 'success',
                        'task_id' => max(0, (int)($reusedRunReadback['sync_task_id'] ?? 0)),
                        'saved_count' => $reusedCount,
                        'readback_count' => $reusedCount,
                        'readback_verified' => true,
                        'reused_verified_count' => $reusedCount,
                        'run_readback' => $reusedRunReadback,
                        'historical_core_contract_status' => 'ready',
                        'ordered_collection' => $orderedPlan,
                        'message' => 'target_date_core_already_verified_no_capture',
                    ];
                    continue;
                }
                $cloudPlatformHotelId = $this->cloudCollectorPlatformHotelId($source);
                $requiredPlatformHotelIds = $this->cloudCollectorScope === []
                    ? $this->profileSourcePlatformHotelIds($source)
                    : array_values(array_filter([$cloudPlatformHotelId], static fn(string $id): bool => $id !== ''));
                $syncOptions = [
                    'trigger_type' => 'daily_profile_reuse',
                    'force_rerun' => $forceRerun,
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
                    // into its default section set. A daily ordered plan remains
                    // bounded to the planner-declared sections, but all required
                    // sections share one sync task and one exact run readback.
                    'bounded_capture_sections' => implode(',', (array)($orderedPlan['sections'] ?? [])),
                    'ordered_collection' => $orderedPlan,
                    // Cloud device/user binding and this capture's live session
                    // proof are separate contracts. Every historical Profile
                    // capture must prove the current execution's login and
                    // platform-hotel identity before any raw row is persisted.
                    'require_collector_binding' => $this->cloudCollectorScope !== [],
                    'require_current_run_session_probe' => $dataPeriod === 'historical_daily'
                        || $this->cloudCollectorScope !== [],
                    'required_platform_hotel_id' => $requiredPlatformHotelIds[0] ?? '',
                    'required_platform_hotel_ids' => $requiredPlatformHotelIds,
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
                            'platform_hotel_id' => $cloudPlatformHotelId,
                        ],
                ];
                if ($dataPeriod === 'historical_daily' && $this->dispatcherRunId !== '') {
                    // Recent Ctrip target-date traffic captures have taken close
                    // to five minutes. A natural two-section task must not inherit
                    // the 120-second headless default, while 10 minutes per
                    // platform still keeps two serial sources inside the task's
                    // 40-minute execution limit.
                    $syncOptions['timeout_seconds'] = self::NATURAL_HISTORICAL_CAPTURE_TIMEOUT_SECONDS;
                }
                if ($this->dispatcherRunId !== '') {
                    $syncOptions['dispatcher_run_id'] = $this->dispatcherRunId;
                }
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
                $result = $this->syncBrowserProfileSource(
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
                Log::warning('Ordered browser Profile source failed', [
                    'hotel_id' => $hotelId,
                    'platform' => $platform,
                    'data_source_id' => (int)$source['id'],
                    'stage' => 'plan_or_capture',
                    'exception_type' => get_debug_type($e),
                ]);
                $messages[] = strtoupper($platform) . ' 数据源#' . (int)$source['id'] . ': ordered_profile_capture_failed';
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

            $sourceSavedCount = 0;
            $sourceTiming = [];
            $sourceCountsApplied = false;
            try {
                $sourceSavedCount = (int)($result['saved_count'] ?? 0);
                $sourceTiming = is_array($result['timing'] ?? null) ? $result['timing'] : [];
                $runReadback = is_array($result['run_readback'] ?? null) ? $result['run_readback'] : [];
                $historicalCoreContractVerified = $dataPeriod === 'historical_daily'
                    ? $this->orderedHistoricalCoreReadbackVerified(
                        $source,
                        $dataDate,
                        $dataPeriod,
                        $runReadback
                    )
                    : true;
                $compositeReadbackVerified = $this->orderedCompositeReadbackVerified(
                    $source,
                    $dataDate,
                    $dataPeriod,
                    $runReadback
                );
                $coreReadbackVerified = $dataPeriod === 'historical_daily'
                    ? $compositeReadbackVerified
                    : $this->runReadbackCoreVerified($runReadback);
                $platformReadbackVerified = $coreReadbackVerified
                    && $historicalCoreContractVerified;
                $operationalReceipt = $this->profileSourceOperationalReceipt(
                    $result,
                    $platformReadbackVerified,
                    $historicalCoreContractVerified
                );
                $platformMessage = $operationalReceipt['message'];
                $savedCount += $sourceSavedCount;
                if ($sourceTiming !== []) {
                    $timing = $this->sumTiming($timing, $sourceTiming);
                }
                $savedByPlatform[$platform] = ($savedByPlatform[$platform] ?? 0) + $sourceSavedCount;
                $sourceCountsApplied = true;
                if ($platformReadbackVerified) {
                    $evidenceByPlatform[$platform] = ($evidenceByPlatform[$platform] ?? 0) + $sourceSavedCount;
                }
                $platformResults[] = [
                    'platform' => $platform,
                    'system_hotel_id' => $hotelId,
                    'data_source_id' => (int)$source['id'],
                    'ingestion_method' => $ingestionMethod,
                    'target_date' => $dataDate,
                    'success' => $platformReadbackVerified,
                    'task_id' => $operationalReceipt['task_id'],
                    'status' => $operationalReceipt['status'],
                    'source_task_status' => $operationalReceipt['source_task_status'],
                    'failure_reason' => $operationalReceipt['failure_reason'],
                    'readback_count' => $operationalReceipt['readback_count'],
                    'readback_verified' => $operationalReceipt['readback_verified'],
                    'dispatcher_run_id' => $this->dispatcherRunId,
                    'saved_count' => $sourceSavedCount,
                    'reused_verified_count' => 0,
                    'run_readback' => $runReadback,
                    'collection_quality' => is_array($result['collection_quality'] ?? null)
                        ? $result['collection_quality']
                        : [],
                    'composite_readback_verified' => $compositeReadbackVerified,
                    'historical_core_contract_status' => $dataPeriod === 'historical_daily'
                        ? ($historicalCoreContractVerified ? 'ready' : 'blocked')
                        : 'not_required',
                    'ordered_collection' => $orderedPlan,
                    'message' => $platformMessage,
                ];
                if (!$platformReadbackVerified) {
                    $failedCount++;
                    $failedPlatforms[$platform] = true;
                }
                $messages[] = strtoupper($platform) . ' 数据源#' . (int)$source['id'] . ': ' . $platformMessage;
            } catch (\Throwable $e) {
                if (!$sourceCountsApplied) {
                    $savedCount += $sourceSavedCount;
                    if ($sourceTiming !== []) {
                        $timing = $this->sumTiming($timing, $sourceTiming);
                    }
                    $savedByPlatform[$platform] = ($savedByPlatform[$platform] ?? 0) + $sourceSavedCount;
                }
                $failedCount++;
                $failedPlatforms[$platform] = true;
                $platformResults[] = [
                    'platform' => $platform,
                    'data_source_id' => (int)$source['id'],
                    'success' => false,
                    'saved_count' => $sourceSavedCount,
                    'reused_verified_count' => 0,
                    'run_readback' => [],
                    'composite_readback_verified' => false,
                    'ordered_collection' => $orderedPlan,
                    'message' => 'ordered_profile_readback_failed',
                ];
                Log::warning('Ordered browser Profile readback failed', [
                    'hotel_id' => $hotelId,
                    'platform' => $platform,
                    'data_source_id' => (int)$source['id'],
                    'stage' => 'exact_readback',
                    'exception_type' => get_debug_type($e),
                ]);
                $messages[] = strtoupper($platform) . ' 数据源#' . (int)$source['id'] . ': ordered_profile_readback_failed';
                continue;
            }
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
                'missing_source_ids' => $missingSourceIds,
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
                'missing_source_ids' => $missingSourceIds,
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
            'missing_source_ids' => $missingSourceIds,
            'successful_platforms' => [],
            'required_platforms' => $targetPlatforms,
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @return array{plan: array<string, mixed>, reused_run_readback: array<string, mixed>}
     */
    protected function orderedBrowserProfileExecution(
        array $source,
        string $dataDate,
        string $dataPeriod,
        bool $forceRerun = false
    ): array {
        $platform = strtolower(trim((string)($source['platform'] ?? '')));
        if ($dataPeriod !== 'historical_daily') {
            $plan = OtaOrderedCollectionPlanner::requestPlan(
                $platform,
                $dataDate,
                [],
                'realtime_collection_outside_yesterday_contract'
            );
            $plan['force_rerun'] = $forceRerun;
            $plan['reuse_existing_run_readback'] = false;
            return [
                'plan' => $plan,
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
        $eligibleRows = OtaOrderedCollectionPlanner::storedCoreRows($platform, $rows);
        if ($forceRerun
            && $platform === 'ctrip'
            && $eligibleRows !== []
            && OtaOrderedCollectionPlanner::missingFieldKeys($platform, $eligibleRows) === []
        ) {
            return [
                'plan' => $this->historicalCtripAuthorityRecollectionPlan(
                    $dataDate,
                    $rows,
                    true,
                    'explicit_force_rerun_authority_recollection'
                ),
                'reused_run_readback' => [],
            ];
        }
        $plan = OtaOrderedCollectionPlanner::requestPlanFromStoredRows(
            $platform,
            $dataDate,
            $rows,
            $sourceRecoveryRequired
        );
        $plan['force_rerun'] = $forceRerun;
        $plan['reuse_existing_run_readback'] = !$forceRerun;
        if (($plan['sections'] ?? []) !== []) {
            $plannedSections = array_values(array_filter(
                array_merge(...array_map(
                    static fn($section): array => preg_split('/\s*,\s*/', trim((string)$section)) ?: [],
                    (array)$plan['sections']
                )),
                static fn(string $section): bool => trim($section) !== ''
            ));
            if ($this->dispatcherRunId !== '') {
                $plannedSections = OtaOrderedCollectionPlanner::defaultSections($platform);
                $plan['reason'] = 'natural_exact_task_core_recollection';
                $plan['recollection_field_keys'] =
                    OtaOrderedCollectionPlanner::requiredFieldKeys($platform);
            }
            // Browser Profile collection shares one local login session. Keep all
            // planner-required sections in one task so the final receipt owns both
            // revenue/order and traffic facts. Splitting them across tasks lets the
            // later traffic task borrow unanchored revenue facts from an older run.
            if (count($plannedSections) > 1) {
                $plan['planned_sections'] = $plannedSections;
                $plan['pending_sections'] = [];
                $plan['sections'] = $plannedSections;
                $plan['execution_mode'] = 'multi_section_single_task';
            }
            return ['plan' => $plan, 'reused_run_readback' => []];
        }

        $readback = $forceRerun
            ? []
            : $this->existingVerifiedProfileRunReadback(
                (int)($source['system_hotel_id'] ?? 0),
                (int)($source['id'] ?? 0),
                $platform,
                $dataDate,
                $rows
            );
        return $this->resolveVerifiedCompleteHistoricalExecution(
            $platform,
            $dataDate,
            $rows,
            $plan,
            $readback,
            $forceRerun
        );
    }

    /**
     * Keep the live service construction behind one narrow seam so the
     * per-platform continuation contract can be exercised without an OTA
     * request. Production always reaches the same PlatformDataSyncService.
     *
     * @param mixed $user
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected function syncBrowserProfileSource($user, int $sourceId, array $options): array
    {
        return (new PlatformDataSyncService())->syncDataSource($user, $sourceId, $options);
    }

    /**
     * Keep the operator-facing failure code from the exact source task while
     * leaving the stricter historical/readback gate unchanged.
     *
     * @param array<string, mixed> $result
     * @return array{task_id:int,status:string,source_task_status:string,message:string,failure_reason:string,readback_count:int,readback_verified:bool}
     */
    private function profileSourceOperationalReceipt(
        array $result,
        bool $platformReadbackVerified,
        bool $historicalCoreContractVerified
    ): array {
        $safeCode = static function (mixed $value): string {
            $value = strtolower(trim((string)$value));
            return preg_match('/^[a-z0-9][a-z0-9._:-]{0,119}$/D', $value) === 1
                ? $value
                : '';
        };
        $sourceStatus = $safeCode($result['status'] ?? '') ?: 'unknown';
        $sourceMessage = $safeCode($result['message'] ?? '');
        $sourceFailureReason = $safeCode($result['failure_reason'] ?? '');
        $terminalFailure = in_array($sourceStatus, [
            'failed',
            'partial_success',
            'capture_failed',
            'permission_denied',
            'cancelled',
        ], true);

        if ($platformReadbackVerified) {
            $status = 'success';
            $message = $sourceMessage !== '' ? $sourceMessage : 'platform_data_synchronized';
            $failureReason = '';
        } elseif ($terminalFailure && $sourceMessage !== '') {
            $status = $sourceStatus;
            $message = $sourceMessage;
            $failureReason = $sourceFailureReason !== '' ? $sourceFailureReason : $sourceMessage;
        } elseif (!$historicalCoreContractVerified) {
            $status = 'failed';
            $message = 'historical_core_contract_incomplete';
            $failureReason = $sourceFailureReason !== ''
                ? $sourceFailureReason
                : 'historical_core_contract_incomplete';
        } else {
            $status = 'failed';
            $message = $sourceMessage !== '' ? $sourceMessage : 'ordered_profile_readback_not_verified';
            $failureReason = $sourceFailureReason !== '' ? $sourceFailureReason : $message;
        }

        return [
            'task_id' => max(0, (int)($result['task_id'] ?? 0)),
            'status' => $status,
            'source_task_status' => $sourceStatus,
            'message' => $message,
            'failure_reason' => $failureReason,
            'readback_count' => max(0, (int)($result['readback_count'] ?? 0)),
            'readback_verified' => ($result['readback_verified'] ?? false) === true,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $verifiedCompletePlan
     * @param array<string, mixed> $existingReadback
     * @return array{plan: array<string, mixed>, reused_run_readback: array<string, mixed>}
     */
    private function resolveVerifiedCompleteHistoricalExecution(
        string $platform,
        string $dataDate,
        array $rows,
        array $verifiedCompletePlan,
        array $existingReadback,
        bool $forceRerun
    ): array {
        if ($platform === 'ctrip' && $forceRerun) {
            return [
                'plan' => $this->historicalCtripAuthorityRecollectionPlan(
                    $dataDate,
                    $rows,
                    true,
                    'explicit_force_rerun_authority_recollection'
                ),
                'reused_run_readback' => [],
            ];
        }
        if (!$forceRerun && $existingReadback !== []) {
            return ['plan' => $verifiedCompletePlan, 'reused_run_readback' => $existingReadback];
        }
        if ($platform === 'ctrip') {
            return [
                'plan' => $this->historicalCtripAuthorityRecollectionPlan(
                    $dataDate,
                    $rows,
                    false,
                    'verified_rows_without_current_bound_run_readback'
                ),
                'reused_run_readback' => [],
            ];
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
            $plan['pending_sections'] = [];
            $plan['sections'] = $plannedSections;
            $plan['execution_mode'] = 'multi_section_single_task';
        }
        $plan['stage'] = 'conflict_recovery';
        $plan['source_recovery_required'] = true;
        $plan['eligible_row_count'] = count(OtaOrderedCollectionPlanner::storedCoreRows($platform, $rows));
        $plan['force_rerun'] = $forceRerun;
        $plan['reuse_existing_run_readback'] = false;
        return ['plan' => $plan, 'reused_run_readback' => []];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function historicalCtripAuthorityRecollectionPlan(
        string $dataDate,
        array $rows,
        bool $forceRerun,
        string $reason
    ): array {
        $trafficFieldKeys = [
            'list_exposure',
            'detail_exposure',
            'flow_rate',
            'order_filling_num',
            'order_submit_num',
        ];
        $eligibleRows = OtaOrderedCollectionPlanner::storedCoreRows('ctrip', $rows);
        $plan = OtaOrderedCollectionPlanner::requestPlan(
            'ctrip',
            $dataDate,
            $trafficFieldKeys,
            $reason
        );
        $naturalCompositeRun = $this->dispatcherRunId !== '';
        $sections = $naturalCompositeRun
            ? ['business_overview', 'traffic_report']
            : ['traffic_report'];
        $plan['mode'] = $naturalCompositeRun
            ? 'bounded_historical_core_recollection'
            : 'bounded_authority_recollection';
        $plan['scope'] = $naturalCompositeRun
            ? 'ctrip_target_date_historical_core'
            : 'ctrip_target_date_traffic_authority';
        $plan['stage'] = 'authority_recollection';
        $plan['execution_mode'] = $naturalCompositeRun
            ? 'multi_section_single_task'
            : 'single_section_bounded';
        $plan['planned_sections'] = $sections;
        $plan['pending_sections'] = [];
        $plan['sections'] = $sections;
        $plan['captured_field_keys'] = OtaOrderedCollectionPlanner::capturedFieldKeys(
            'ctrip',
            $eligibleRows
        );
        $plan['missing_field_keys'] = [];
        $plan['recollection_field_keys'] = $naturalCompositeRun
            ? OtaOrderedCollectionPlanner::requiredFieldKeys('ctrip')
            : $trafficFieldKeys;
        $plan['source_recovery_required'] = false;
        $plan['eligible_row_count'] = count($eligibleRows);
        $plan['force_rerun'] = $forceRerun;
        $plan['reuse_existing_run_readback'] = false;
        $plan['date_policy'] = 'exact_target_date_no_replay_or_rewrite';
        return $plan;
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

    /**
     * A historical cache may suppress a new collection only while its exact
     * task receipts and every referenced row still exist in the current
     * tenant/hotel/source/date scope. The cached external verifier is evidence
     * about the receipt at verification time; it is not a substitute for this
     * current database membership check.
     *
     * @param array<string, mixed> $receipt
     */
    private function cachedHistoricalDailyReceiptRowsStillCurrent(
        array $receipt,
        int $tenantId
    ): bool {
        if ($tenantId <= 0) {
            return false;
        }

        $taskReadbacks = [];
        $rowsBySource = [];
        foreach (is_array($receipt['source_tasks'] ?? null) ? $receipt['source_tasks'] : [] as $task) {
            if (!is_array($task)) {
                return false;
            }
            $sourceId = (int)($task['data_source_id'] ?? 0);
            $taskId = (int)($task['sync_task_id'] ?? 0);
            $platform = strtolower(trim((string)($task['platform'] ?? '')));
            $ingestionMethod = strtolower(trim((string)($task['ingestion_method'] ?? '')));
            if ($sourceId <= 0 || $taskId <= 0
                || !in_array($platform, ['ctrip', 'meituan'], true)
                || !in_array($ingestionMethod, ['browser_profile', 'local_collector'], true)
                || array_key_exists($taskId, $taskReadbacks)
                || array_key_exists($sourceId, $rowsBySource)
            ) {
                return false;
            }
            $taskReadback = $this->storedRunReadbackForCachedHistoricalTask(
                $tenantId,
                (int)($receipt['hotel_id'] ?? 0),
                $sourceId,
                $taskId,
                $platform,
                $ingestionMethod
            );
            if ($taskReadback === []) {
                return false;
            }
            $taskReadbacks[$taskId] = $taskReadback;
            $rowsBySource[$sourceId] = $this->storedProfileRowsForPlan(
                (int)($receipt['hotel_id'] ?? 0),
                $sourceId,
                $platform,
                substr(trim((string)($receipt['target_date'] ?? '')), 0, 10)
            );
        }

        return $this->cachedHistoricalDailyReceiptRowsMatch(
            $receipt,
            $tenantId,
            $taskReadbacks,
            $rowsBySource
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function storedRunReadbackForCachedHistoricalTask(
        int $tenantId,
        int $hotelId,
        int $sourceId,
        int $taskId,
        string $platform,
        string $ingestionMethod
    ): array {
        $expectedTrigger = $ingestionMethod === 'local_collector'
            ? 'local_collector_upload'
            : 'daily_profile_reuse';
        try {
            $task = Db::name('platform_data_sync_tasks')
                ->where('id', $taskId)
                ->where('tenant_id', $tenantId)
                ->where('system_hotel_id', $hotelId)
                ->where('data_source_id', $sourceId)
                ->where('ingestion_method', $ingestionMethod)
                ->where('trigger_type', $expectedTrigger)
                ->whereIn('status', ['success', 'partial_success'])
                ->find();
        } catch (\Throwable) {
            return [];
        }
        if (!is_array($task)
            || strtolower(trim((string)($task['platform'] ?? ''))) !== $platform
        ) {
            return [];
        }
        $stats = json_decode((string)($task['stats_json'] ?? ''), true);
        $stats = is_array($stats) ? $stats : [];
        $readback = is_array($stats['run_readback'] ?? null) ? $stats['run_readback'] : [];
        if ($this->dispatcherRunId !== '') {
            $readbackRunId = $this->normalizeDispatcherRunId(
                (string)($readback['dispatcher_run_id'] ?? '')
            );
            if ($readbackRunId === ''
                || strtolower(trim((string)($readback['trigger_type'] ?? '')))
                    !== $expectedTrigger
                || $this->normalizeDispatcherRunId(
                    (string)($stats['dispatcher_run_id'] ?? '')
                ) !== $readbackRunId
            ) {
                return [];
            }
        }
        return $readback;
    }

    /**
     * Pure membership gate kept separate from the database adapter so stale,
     * deleted and cross-scope cache counterexamples remain deterministic.
     *
     * @param array<string, mixed> $receipt
     * @param array<int, array<string, mixed>> $taskReadbacks
     * @param array<int, array<int, array<string, mixed>>> $rowsBySource
     */
    private function cachedHistoricalDailyReceiptRowsMatch(
        array $receipt,
        int $tenantId,
        array $taskReadbacks,
        array $rowsBySource
    ): bool {
        $hotelId = (int)($receipt['hotel_id'] ?? 0);
        $dataDate = substr(trim((string)($receipt['target_date'] ?? '')), 0, 10);
        $expectedSourceIds = array_values(array_unique(array_filter(array_map(
            'intval',
            is_array($receipt['source_ids'] ?? null) ? $receipt['source_ids'] : []
        ), static fn(int $sourceId): bool => $sourceId > 0)));
        sort($expectedSourceIds, SORT_NUMERIC);
        $requiredPlatforms = (new ScheduledAutoFetchPolicy())->normalizePlatforms(
            $receipt['required_platforms'] ?? []
        );
        sort($requiredPlatforms, SORT_STRING);
        $sourceTasks = is_array($receipt['source_tasks'] ?? null)
            ? array_values($receipt['source_tasks'])
            : [];
        if ($tenantId <= 0 || $hotelId <= 0 || !$this->validDataDate($dataDate)
            || strtolower(trim((string)($receipt['data_period'] ?? ''))) !== 'historical_daily'
            || $expectedSourceIds === [] || $requiredPlatforms === []
            || count($sourceTasks) !== count($expectedSourceIds)
        ) {
            return false;
        }

        $seenSourceIds = [];
        $seenTaskIds = [];
        $seenPlatforms = [];
        foreach ($sourceTasks as $task) {
            if (!is_array($task)) {
                return false;
            }
            $sourceId = (int)($task['data_source_id'] ?? 0);
            $taskId = (int)($task['sync_task_id'] ?? 0);
            $platform = strtolower(trim((string)($task['platform'] ?? '')));
            $receiptRowIds = array_values(array_unique(array_filter(array_map(
                'intval',
                is_array($task['row_ids'] ?? null) ? $task['row_ids'] : []
            ), static fn(int $rowId): bool => $rowId > 0)));
            sort($receiptRowIds, SORT_NUMERIC);
            if ($sourceId <= 0 || $taskId <= 0 || $receiptRowIds === []
                || !in_array($sourceId, $expectedSourceIds, true)
                || !in_array($platform, $requiredPlatforms, true)
                || isset($seenSourceIds[$sourceId]) || isset($seenTaskIds[$taskId])
            ) {
                return false;
            }

            $readback = $taskReadbacks[$taskId] ?? null;
            $currentRows = $rowsBySource[$sourceId] ?? null;
            if (!is_array($readback) || !is_array($currentRows)) {
                return false;
            }
            if ($this->dispatcherRunId !== '') {
                $taskDispatcherRunId = $this->normalizeDispatcherRunId(
                    (string)($task['dispatcher_run_id'] ?? '')
                );
                $ingestionMethod = strtolower(trim((string)(
                    $task['ingestion_method'] ?? ''
                )));
                $localCollectorTaskId = (int)($task['local_collector_task_id'] ?? 0);
                $expectedTrigger = $ingestionMethod === 'local_collector'
                    ? 'local_collector_upload'
                    : 'daily_profile_reuse';
                $methodScopeReady = $ingestionMethod === 'local_collector'
                    ? $localCollectorTaskId > 0
                    : ($ingestionMethod === 'browser_profile' && $localCollectorTaskId === 0);
                if ($taskDispatcherRunId === ''
                    || $taskDispatcherRunId !== $this->normalizeDispatcherRunId(
                        (string)($readback['dispatcher_run_id'] ?? '')
                    )
                    || !$methodScopeReady
                    || strtolower(trim((string)($task['trigger_type'] ?? ''))) !== $expectedTrigger
                    || strtolower(trim((string)($readback['trigger_type'] ?? ''))) !== $expectedTrigger
                    || ($task['readback_verified'] ?? false) !== true
                ) {
                    return false;
                }
            }
            $currentRowIds = array_values(array_unique(array_filter(array_map(
                'intval',
                is_array($readback['row_ids'] ?? null) ? $readback['row_ids'] : []
            ), static fn(int $rowId): bool => $rowId > 0)));
            sort($currentRowIds, SORT_NUMERIC);
            $sourceTraceIds = array_values(array_filter(
                is_array($readback['source_trace_ids'] ?? null)
                    ? $readback['source_trace_ids']
                    : [],
                static fn($value): bool => trim((string)$value) !== ''
            ));
            if (($readback['readback_verified'] ?? false) !== true
                || (int)($readback['sync_task_id'] ?? 0) !== $taskId
                || (int)($readback['data_source_id'] ?? 0) !== $sourceId
                || (int)($readback['system_hotel_id'] ?? 0) !== $hotelId
                || strtolower(trim((string)($readback['platform'] ?? ''))) !== $platform
                || substr(trim((string)($readback['target_date'] ?? '')), 0, 10) !== $dataDate
                || strtolower(trim((string)($readback['data_period'] ?? ''))) !== 'historical_daily'
                || trim((string)($readback['started_at'] ?? '')) === ''
                || $sourceTraceIds === []
                || $currentRowIds !== $receiptRowIds
            ) {
                return false;
            }

            $rowsById = [];
            foreach ($currentRows as $row) {
                if (is_array($row) && (int)($row['id'] ?? 0) > 0) {
                    $rowsById[(int)$row['id']] = $row;
                }
            }
            foreach ($receiptRowIds as $rowId) {
                if (!isset($rowsById[$rowId])
                    || (int)($rowsById[$rowId]['tenant_id'] ?? 0) !== $tenantId
                ) {
                    return false;
                }
            }
            if (!$this->profileRunReadbackRowsStillCurrent(
                $readback,
                $currentRows,
                $hotelId,
                $sourceId,
                $platform,
                $dataDate
            ) || !$this->exactTaskP0RowsComplete(
                $readback,
                $currentRows,
                $hotelId,
                $sourceId,
                $platform,
                $dataDate
            ) || !$this->exactTaskOrderedCoreRowsComplete(
                $readback,
                $currentRows,
                $hotelId,
                $sourceId,
                $platform,
                $dataDate
            )) {
                return false;
            }

            $seenSourceIds[$sourceId] = true;
            $seenTaskIds[$taskId] = true;
            $seenPlatforms[$platform] = true;
        }

        $actualSourceIds = array_map('intval', array_keys($seenSourceIds));
        sort($actualSourceIds, SORT_NUMERIC);
        $actualPlatforms = array_keys($seenPlatforms);
        sort($actualPlatforms, SORT_STRING);
        return $actualSourceIds === $expectedSourceIds
            && $actualPlatforms === $requiredPlatforms;
    }

    /** @return array<string, mixed> */
    private function existingVerifiedProfileRunReadback(
        int $hotelId,
        int $sourceId,
        string $platform,
        string $dataDate,
        array $currentRows = []
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
                && $this->reusableNaturalDispatcherReadback($readback, is_array($stats) ? $stats : [])
                && $this->currentDispatcherOwnsRunReadback($readback)
                && ($this->dispatcherRunId === ''
                    || strtolower(trim((string)($task['trigger_type'] ?? ''))) === 'daily_profile_reuse')
                && (int)($readback['sync_task_id'] ?? 0) === (int)($task['id'] ?? 0)
                && (int)($readback['system_hotel_id'] ?? 0) === $hotelId
                && (int)($readback['data_source_id'] ?? 0) === $sourceId
                && strtolower(trim((string)($readback['platform'] ?? ''))) === $platform
                && substr(trim((string)($readback['target_date'] ?? '')), 0, 10) === $dataDate
                && strtolower(trim((string)($readback['data_period'] ?? ''))) === 'historical_daily'
                && $this->profileRunReadbackRowsStillCurrent(
                    $readback,
                    $currentRows,
                    $hotelId,
                    $sourceId,
                    $platform,
                    $dataDate
                )
                && $this->exactTaskP0RowsComplete(
                    $readback,
                    $currentRows,
                    $hotelId,
                    $sourceId,
                    $platform,
                    $dataDate
                )
                && $this->exactTaskOrderedCoreRowsComplete(
                    $readback,
                    $currentRows,
                    $hotelId,
                    $sourceId,
                    $platform,
                    $dataDate
                )
            ) {
                return $readback;
            }
        }
        return [];
    }

    /**
     * A prior task receipt is reusable only while every referenced row still
     * belongs to that exact task/scope. Later upserts can retain the row id but
     * replace sync_task_id; such a receipt is stale and must trigger a bounded
     * authority recollection instead of silently inheriting the newer facts.
     *
     * @param array<string, mixed> $readback
     * @param array<int, array<string, mixed>> $currentRows
     */
    private function profileRunReadbackRowsStillCurrent(
        array $readback,
        array $currentRows,
        int $hotelId,
        int $sourceId,
        string $platform,
        string $dataDate
    ): bool {
        $taskId = (int)($readback['sync_task_id'] ?? 0);
        $rowIds = array_values(array_unique(array_filter(array_map(
            'intval',
            is_array($readback['row_ids'] ?? null) ? $readback['row_ids'] : []
        ), static fn(int $rowId): bool => $rowId > 0)));
        if ($taskId <= 0 || $rowIds === []) {
            return false;
        }

        $rowsById = [];
        foreach ($currentRows as $row) {
            if (is_array($row) && (int)($row['id'] ?? 0) > 0) {
                $rowsById[(int)$row['id']] = $row;
            }
        }

        $authoritativeMarkerTrafficPresent = false;
        foreach ($rowIds as $rowId) {
            $row = $rowsById[$rowId] ?? null;
            if (!is_array($row)) {
                return false;
            }
            $rowPlatform = strtolower(trim((string)($row['platform'] ?? '')));
            if ($rowPlatform === '') {
                $rowPlatform = strtolower(trim((string)($row['source'] ?? '')));
            }
            if ((int)($row['sync_task_id'] ?? 0) !== $taskId
                || (int)($row['data_source_id'] ?? 0) !== $sourceId
                || (int)($row['system_hotel_id'] ?? 0) !== $hotelId
                || substr(trim((string)($row['data_date'] ?? '')), 0, 10) !== $dataDate
                || strtolower(trim((string)($row['data_period'] ?? ''))) !== 'historical_daily'
                || $rowPlatform !== $platform
                || (int)($row['readback_verified'] ?? 0) !== 1
            ) {
                return false;
            }
            if ($platform === 'ctrip' && $this->rowHasAuthoritativeObservedTrafficMarker($row)) {
                $authoritativeMarkerTrafficPresent = true;
            }
        }

        return $platform !== 'ctrip' || $authoritativeMarkerTrafficPresent;
    }

    /** @param array<string, mixed> $row */
    private function rowHasAuthoritativeObservedTrafficMarker(array $row): bool
    {
        $dataType = strtolower(trim((string)($row['data_type'] ?? '')));
        if (!in_array($dataType, ['traffic', 'flow', 'conversion'], true)
            || !OtaTrafficAttributionService::rowBelongsToAuthoritativeP0Traffic($row, 'ctrip')
        ) {
            return false;
        }

        $rawValue = $row['raw_data'] ?? null;
        if (is_string($rawValue) && trim($rawValue) !== '') {
            $decoded = json_decode($rawValue, true);
            $raw = is_array($decoded) ? $decoded : [];
        } else {
            $raw = is_array($rawValue) ? $rawValue : [];
        }
        $sourceRow = is_array($raw['row'] ?? null) ? $raw['row'] : [];
        $marker = $sourceRow['_observed_traffic_metric_keys'] ?? null;
        if (!is_array($marker) || !array_is_list($marker)) {
            return false;
        }
        $observed = [];
        foreach ($marker as $value) {
            if (!is_string($value)
                || trim($value) !== $value
                || preg_match('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/D', $value) !== 1
            ) {
                return false;
            }
            $observed[$value] = $value;
        }
        return array_diff([
            'list_exposure',
            'detail_exposure',
            'flow_rate',
            'order_filling_num',
            'order_submit_num',
        ], $observed) === [];
    }

    /**
     * Historical success and receipt reuse must be supported by P0 facts that
     * still belong to this exact task. Same-date rows from an older task (or a
     * realtime task) cannot complete the current task's field set.
     *
     * @param array<string, mixed> $readback
     * @param array<int, array<string, mixed>> $currentRows
     */
    private function exactTaskP0RowsComplete(
        array $readback,
        array $currentRows,
        int $hotelId,
        int $sourceId,
        string $platform,
        string $dataDate
    ): bool {
        $taskId = (int)($readback['sync_task_id'] ?? 0);
        $readbackRowIds = [];
        foreach (is_array($readback['row_ids'] ?? null) ? $readback['row_ids'] : [] as $rowId) {
            $rowId = (int)$rowId;
            if ($rowId > 0) {
                $readbackRowIds[$rowId] = true;
            }
        }
        $requiredMetricKeys = match ($platform) {
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
            default => [],
        };
        if ($taskId <= 0 || $readbackRowIds === [] || $requiredMetricKeys === []) {
            return false;
        }

        $exactRows = array_values(array_filter(
            $currentRows,
            static function ($row) use (
                $taskId,
                $readbackRowIds,
                $hotelId,
                $sourceId,
                $platform,
                $dataDate
            ): bool {
                if (!is_array($row)) {
                    return false;
                }
                $rowPlatform = strtolower(trim((string)($row['platform'] ?? '')));
                if ($rowPlatform === '') {
                    $rowPlatform = strtolower(trim((string)($row['source'] ?? '')));
                }
                $dataType = strtolower(trim((string)($row['data_type'] ?? '')));
                return isset($readbackRowIds[(int)($row['id'] ?? 0)])
                    && (int)($row['sync_task_id'] ?? 0) === $taskId
                    && (int)($row['data_source_id'] ?? 0) === $sourceId
                    && (int)($row['system_hotel_id'] ?? 0) === $hotelId
                    && substr(trim((string)($row['data_date'] ?? '')), 0, 10) === $dataDate
                    && strtolower(trim((string)($row['data_period'] ?? ''))) === 'historical_daily'
                    && $rowPlatform === $platform
                    && (int)($row['readback_verified'] ?? 0) === 1
                    && in_array($dataType, ['traffic', 'flow', 'conversion'], true)
                    && OtaTrafficAttributionService::rowBelongsToAuthoritativeP0Traffic(
                        $row,
                        $platform
                    );
            }
        ));
        $capturedMetricKeys = OtaOrderedCollectionPlanner::capturedFieldKeys(
            $platform,
            OtaOrderedCollectionPlanner::storedCoreRows($platform, $exactRows)
        );
        return array_diff($requiredMetricKeys, $capturedMetricKeys) === [];
    }

    /**
     * The daily ordered contract contains both revenue/order and traffic facts.
     * Every required field must belong to the same exact task and receipt rows;
     * rows from an older/manual task may guide planning but never complete this
     * task's trust receipt.
     *
     * @param array<string, mixed> $readback
     * @param array<int, array<string, mixed>> $currentRows
     */
    private function exactTaskOrderedCoreRowsComplete(
        array $readback,
        array $currentRows,
        int $hotelId,
        int $sourceId,
        string $platform,
        string $dataDate
    ): bool {
        $taskId = (int)($readback['sync_task_id'] ?? 0);
        $readbackRowIds = [];
        foreach (is_array($readback['row_ids'] ?? null) ? $readback['row_ids'] : [] as $rowId) {
            $rowId = (int)$rowId;
            if ($rowId > 0) {
                $readbackRowIds[$rowId] = true;
            }
        }
        if ($taskId <= 0 || $readbackRowIds === []
            || !in_array($platform, ['ctrip', 'meituan'], true)
        ) {
            return false;
        }

        $exactRows = array_values(array_filter(
            $currentRows,
            static function ($row) use (
                $taskId,
                $readbackRowIds,
                $hotelId,
                $sourceId,
                $platform,
                $dataDate
            ): bool {
                if (!is_array($row)) {
                    return false;
                }
                $rowPlatform = strtolower(trim((string)($row['platform'] ?? '')));
                if ($rowPlatform === '') {
                    $rowPlatform = strtolower(trim((string)($row['source'] ?? '')));
                }
                return isset($readbackRowIds[(int)($row['id'] ?? 0)])
                    && (int)($row['sync_task_id'] ?? 0) === $taskId
                    && (int)($row['data_source_id'] ?? 0) === $sourceId
                    && (int)($row['system_hotel_id'] ?? 0) === $hotelId
                    && substr(trim((string)($row['data_date'] ?? '')), 0, 10) === $dataDate
                    && strtolower(trim((string)($row['data_period'] ?? ''))) === 'historical_daily'
                    && $rowPlatform === $platform
                    && (int)($row['readback_verified'] ?? 0) === 1;
            }
        ));
        if (count($exactRows) !== count($readbackRowIds)) {
            return false;
        }
        $eligibleRows = OtaOrderedCollectionPlanner::storedCoreRows($platform, $exactRows);
        return $eligibleRows !== []
            && OtaOrderedCollectionPlanner::missingFieldKeys($platform, $eligibleRows) === [];
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $readback
     */
    protected function orderedHistoricalCoreReadbackVerified(
        array $source,
        string $dataDate,
        string $dataPeriod,
        array $readback
    ): bool {
        $platform = strtolower(trim((string)($source['platform'] ?? '')));
        $sourceId = (int)($source['id'] ?? 0);
        $hotelId = (int)($source['system_hotel_id'] ?? 0);
        if ($dataPeriod !== 'historical_daily'
            || ($readback['readback_verified'] ?? false) !== true
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
        return $this->exactTaskOrderedCoreRowsComplete(
            $readback,
            $rows,
            $hotelId,
            $sourceId,
            $platform,
            $dataDate
        );
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
    protected function orderedCompositeReadbackVerified(
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
        return $this->profileRunReadbackRowsStillCurrent(
            $readback,
            $rows,
            $hotelId,
            $sourceId,
            $platform,
            $dataDate
        ) && $this->exactTaskP0RowsComplete(
            $readback,
            $rows,
            $hotelId,
            $sourceId,
            $platform,
            $dataDate
        ) && $this->exactTaskOrderedCoreRowsComplete(
            $readback,
            $rows,
            $hotelId,
            $sourceId,
            $platform,
            $dataDate
        );
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
        $receipt = (new ScheduledAutoFetchPolicy())->buildDailyTrustReceipt(
            $hotelId,
            $targetDate,
            $sourceIds,
            $outcome,
            $result,
            $dataPeriod
        );
        if ($this->dispatcherRunId !== '') {
            $receipt['dispatcher_run_id'] = $this->dispatcherRunId;
        }
        return $receipt;
    }

    /** @param array<string,mixed> $receipt @return array<string,mixed> */
    private function downgradeUntrustedMachineReceipt(array $receipt): array
    {
        $receipt['status'] = 'partial_success';
        $receipt['collection_complete'] = false;
        $receipt['authority_scope_complete'] = false;
        $receipt['dual_ota_p0_complete'] = false;
        $receipt['collection_run_readback_verified'] = false;
        $receipt['collection_run_failure_code'] =
            'requested_scope_authority_or_history_incomplete';
        return $receipt;
    }

    /**
     * Runs only local draft/action persistence against an already-promoted
     * OTA row. Its receipt is intentionally independent of collection trust
     * so a local analysis failure never causes another OTA request.
     *
     * @param array<string,mixed> $receipt
     * @param array<string,mixed> $canonicalFinalization
     * @param array<string,mixed> $status
     * @return array<string,mixed>
     */
    private function attachCanonicalDailyOperationFinalization(
        array $receipt,
        array $canonicalFinalization,
        int $tenantId,
        int $hotelId,
        array $status
    ): array {
        $authorization = is_array($status['canonical_daily_analysis_authorizations'] ?? null)
            ? $status['canonical_daily_analysis_authorizations']
            : [];
        if (is_array($status['canonical_daily_analysis_authorization'] ?? null)) {
            // The original single grant remains a Ctrip-only compatibility path.
            $authorization['ctrip'] = $authorization['ctrip']
                ?? $status['canonical_daily_analysis_authorization'];
        }
        $analysis = (new CanonicalOtaDailyOperationFinalizer())->finalize(
            $receipt,
            $canonicalFinalization,
            $tenantId,
            $hotelId,
            $authorization
        );
        $receipt['canonical_operation_finalization'] = $analysis;
        $receipt['canonical_operation_complete'] =
            strtolower(trim((string)($analysis['status'] ?? ''))) === 'verified';
        $receipt['canonical_operation_contract_version'] =
            CanonicalOtaDailyOperationFinalizer::SCHEMA_VERSION;
        return $receipt;
    }

    /** @param array<string,mixed> $receipt */
    private function persistCachedCanonicalDailyOperationStatus(
        array $receipt,
        int $hotelId,
        string $targetDate,
        string $slotId
    ): void {
        (new OnlineDataAutoFetchStatusStore())->mutate(
            $hotelId,
            fn(array $status): array => $this->mergeCachedCanonicalDailyOperationStatus(
                $status,
                $receipt,
                $hotelId,
                $targetDate,
                $slotId
            )
        );
    }

    /**
     * Replaces only the operation sidecar inside an existing exact collection
     * status record. Collection outcome fields and run cardinality stay intact.
     *
     * @param array<string,mixed> $status
     * @param array<string,mixed> $receipt
     * @return array<string,mixed>
     */
    private function mergeCachedCanonicalDailyOperationStatus(
        array $status,
        array $receipt,
        int $hotelId,
        string $targetDate,
        string $slotId
    ): array {
        $targetDate = substr(trim($targetDate), 0, 10);
        $slotId = trim($slotId);
        $anchorHash = strtolower(trim((string)($receipt['collection_anchor_hash'] ?? '')));
        $analysis = is_array($receipt['canonical_operation_finalization'] ?? null)
            ? $receipt['canonical_operation_finalization']
            : [];
        if ($hotelId <= 0
            || !$this->validDataDate($targetDate)
            || $slotId === ''
            || (int)($receipt['hotel_id'] ?? 0) !== $hotelId
            || substr(trim((string)($receipt['target_date'] ?? '')), 0, 10) !== $targetDate
            || strtolower(trim((string)($receipt['data_period'] ?? ''))) !== 'historical_daily'
            || preg_match('/^[a-f0-9]{64}$/D', $anchorHash) !== 1
            || ($receipt['canonical_history_complete'] ?? false) !== true
            || ($receipt['canonical_operation_complete'] ?? false) !== true
            || (string)($receipt['canonical_operation_contract_version'] ?? '')
                !== CanonicalOtaDailyOperationFinalizer::SCHEMA_VERSION
            || strtolower(trim((string)($analysis['status'] ?? ''))) !== 'verified'
            || (isset($status['hotel_id']) && (int)$status['hotel_id'] > 0 && (int)$status['hotel_id'] !== $hotelId)
        ) {
            return $status;
        }

        $lastResult = is_array($status['last_result'] ?? null) ? $status['last_result'] : [];
        if ((string)($status['last_data_date'] ?? '') === $targetDate
            && $this->cachedCanonicalOperationRecordMatches(
                $lastResult,
                $receipt,
                $hotelId,
                $targetDate,
                $slotId
            )
        ) {
            $lastResult['trust_receipt'] = $receipt;
            $status['last_result'] = $lastResult;
        }

        $recentRuns = is_array($status['recent_runs'] ?? null) ? $status['recent_runs'] : [];
        $recentRunsUpdated = false;
        foreach ($recentRuns as $index => $run) {
            if (!is_array($run)
                || (string)($run['data_date'] ?? '') !== $targetDate
                || !$this->cachedCanonicalOperationRecordMatches(
                    $run,
                    $receipt,
                    $hotelId,
                    $targetDate,
                    $slotId
                )
            ) {
                continue;
            }
            $run['trust_receipt'] = $receipt;
            $recentRuns[$index] = $run;
            $recentRunsUpdated = true;
        }
        if ($recentRunsUpdated) {
            $status['recent_runs'] = $recentRuns;
        }
        return $status;
    }

    /**
     * @param array<string,mixed> $record
     * @param array<string,mixed> $receipt
     */
    private function cachedCanonicalOperationRecordMatches(
        array $record,
        array $receipt,
        int $hotelId,
        string $targetDate,
        string $slotId
    ): bool {
        $recordReceipt = is_array($record['trust_receipt'] ?? null)
            ? $record['trust_receipt']
            : [];
        $expectedAnchor = strtolower(trim((string)($receipt['collection_anchor_hash'] ?? '')));
        $recordAnchor = strtolower(trim((string)($recordReceipt['collection_anchor_hash'] ?? '')));
        return trim((string)($record['slot_id'] ?? '')) === $slotId
            && strtolower(trim((string)($record['data_period'] ?? ''))) === 'historical_daily'
            && (int)($recordReceipt['hotel_id'] ?? 0) === $hotelId
            && substr(trim((string)($recordReceipt['target_date'] ?? '')), 0, 10) === $targetDate
            && preg_match('/^[a-f0-9]{64}$/D', $recordAnchor) === 1
            && hash_equals($expectedAnchor, $recordAnchor);
    }

    private function validDataDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed instanceof \DateTimeImmutable && $parsed->format('Y-m-d') === $date;
    }

    /**
     * Accept a durable succeeded ledger only when its public readback is the
     * exact two-source collection scope expected by this invocation. This is
     * deliberately independent from the AUTO receipt so a committed terminal
     * run can close a runner whose previous process lost its final output.
     *
     * @param array<string,mixed> $receipt
     * @param array<int,int> $expectedSourceIds
     * @param array<int,string> $expectedPlatforms
     */
    private function durableSucceededRunReceiptReady(
        array $receipt,
        int $expectedHotelId,
        string $expectedDate,
        array $expectedSourceIds,
        array $expectedPlatforms
    ): bool {
        return $this->exactDurableSucceededRunReceiptReady(
            $receipt,
            $this->dispatcherRunId,
            $expectedHotelId,
            $expectedDate,
            $expectedSourceIds,
            $expectedPlatforms
        );
    }

    /**
     * @param array<string,mixed> $receipt
     * @param array<int,int> $expectedSourceIds
     * @param array<int,string> $expectedPlatforms
     */
    private function exactDurableSucceededRunReceiptReady(
        array $receipt,
        string $expectedDispatcherRunId,
        int $expectedHotelId,
        string $expectedDate,
        array $expectedSourceIds,
        array $expectedPlatforms
    ): bool {
        $expectedDate = substr(trim($expectedDate), 0, 10);
        $expectedDispatcherRunId = $this->normalizeDispatcherRunId(
            $expectedDispatcherRunId
        );
        $expectedSourceIds = array_values(array_unique(array_filter(
            array_map('intval', $expectedSourceIds),
            static fn(int $sourceId): bool => $sourceId > 0
        )));
        sort($expectedSourceIds, SORT_NUMERIC);
        $expectedPlatforms = array_values(array_unique(array_map(
            static fn(mixed $platform): string => strtolower(trim((string)$platform)),
            $expectedPlatforms
        )));
        sort($expectedPlatforms, SORT_STRING);
        $dispatcherRunId = $this->normalizeDispatcherRunId(
            (string)($receipt['dispatcher_run_id'] ?? '')
        );
        if ($expectedHotelId <= 0
            || !$this->validDataDate($expectedDate)
            || count($expectedSourceIds) !== 2
            || $expectedPlatforms !== ['ctrip', 'meituan']
            || $dispatcherRunId === ''
            || $expectedDispatcherRunId === ''
            || $dispatcherRunId !== $expectedDispatcherRunId
            || (int)($receipt['system_hotel_id'] ?? 0) !== $expectedHotelId
            || substr(trim((string)($receipt['business_date'] ?? '')), 0, 10) !== $expectedDate
            || strtolower(trim((string)($receipt['status'] ?? ''))) !== 'succeeded'
            || ($receipt['ledger_structure_verified'] ?? false) !== true
            || ($receipt['readback_verified'] ?? false) !== true
            || preg_match('/^[a-f0-9]{64}$/D', strtolower(trim((string)(
                $receipt['collection_anchor_hash'] ?? ''
            )))) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', strtolower(trim((string)(
                $receipt['trust_receipt_digest'] ?? ''
            )))) !== 1
            || trim((string)($receipt['finished_at'] ?? '')) === ''
        ) {
            return false;
        }
        $sources = is_array($receipt['source_receipts'] ?? null)
            ? $receipt['source_receipts']
            : [];
        if (count($sources) !== 2) {
            return false;
        }
        $actualSourceIds = [];
        $actualPlatforms = [];
        $seenSyncTaskIds = [];
        $seenLocalTaskIds = [];
        foreach ($sources as $source) {
            if (!is_array($source)) {
                return false;
            }
            $sourceId = (int)($source['data_source_id'] ?? 0);
            $platform = strtolower(trim((string)($source['platform'] ?? '')));
            $method = strtolower(trim((string)($source['ingestion_method'] ?? '')));
            $syncTaskId = (int)($source['platform_sync_task_id'] ?? 0);
            $localTaskId = (int)($source['local_collector_task_id'] ?? 0);
            $savedCount = (int)($source['saved_row_count'] ?? 0);
            $readbackCount = (int)($source['readback_row_count'] ?? 0);
            if ($sourceId <= 0
                || !in_array($sourceId, $expectedSourceIds, true)
                || !in_array($platform, $expectedPlatforms, true)
                || isset($actualSourceIds[$sourceId])
                || isset($actualPlatforms[$platform])
                || strtolower(trim((string)($source['status'] ?? ''))) !== 'success'
                || ($source['readback_verified'] ?? false) !== true
                || $savedCount <= 0
                || $readbackCount !== $savedCount
                || $syncTaskId <= 0
                || isset($seenSyncTaskIds[$syncTaskId])
                || trim((string)($source['finished_at'] ?? '')) === ''
                || ($method === 'local_collector'
                    ? ($localTaskId <= 0 || isset($seenLocalTaskIds[$localTaskId]))
                    : ($method !== 'browser_profile' || $localTaskId > 0))
            ) {
                return false;
            }
            $actualSourceIds[$sourceId] = true;
            $actualPlatforms[$platform] = true;
            $seenSyncTaskIds[$syncTaskId] = true;
            if ($localTaskId > 0) {
                $seenLocalTaskIds[$localTaskId] = true;
            }
        }
        $actualSourceIds = array_map('intval', array_keys($actualSourceIds));
        sort($actualSourceIds, SORT_NUMERIC);
        $actualPlatforms = array_keys($actualPlatforms);
        sort($actualPlatforms, SORT_STRING);
        return $actualSourceIds === $expectedSourceIds
            && $actualPlatforms === $expectedPlatforms;
    }

    /**
     * A cached success may suppress this run only when its producer still has
     * a fully verified durable ledger. The current run remains an anchorless
     * `verified_cache_reused` outcome; producer evidence is never relabeled as
     * evidence created by the current dispatcher.
     *
     * @param array<string,mixed> $cachedReceipt
     * @param array<int,int> $expectedSourceIds
     * @param array<int,string> $expectedPlatforms
     */
    private function cachedReceiptProducerLedgerStillTrusted(
        array $cachedReceipt,
        int $expectedHotelId,
        string $expectedDate,
        array $expectedSourceIds,
        array $expectedPlatforms
    ): bool {
        $producerDispatcherRunId = $this->normalizeDispatcherRunId(
            (string)($cachedReceipt['dispatcher_run_id'] ?? '')
        );
        if ($producerDispatcherRunId === '') {
            return false;
        }
        try {
            $producerLedger = (new HotelCollectionRunReceiptService())->readExact(
                $producerDispatcherRunId,
                $expectedHotelId,
                $expectedDate
            );
        } catch (\Throwable) {
            return false;
        }
        return $this->cachedReceiptMatchesTrustedProducerLedger(
            $cachedReceipt,
            $producerLedger,
            $expectedHotelId,
            $expectedDate,
            $expectedSourceIds,
            $expectedPlatforms
        );
    }

    /**
     * @param array<string,mixed> $cachedReceipt
     * @param array<string,mixed> $producerLedger
     * @param array<int,int> $expectedSourceIds
     * @param array<int,string> $expectedPlatforms
     */
    private function cachedReceiptMatchesTrustedProducerLedger(
        array $cachedReceipt,
        array $producerLedger,
        int $expectedHotelId,
        string $expectedDate,
        array $expectedSourceIds,
        array $expectedPlatforms
    ): bool {
        $producerDispatcherRunId = $this->normalizeDispatcherRunId(
            (string)($cachedReceipt['dispatcher_run_id'] ?? '')
        );
        if (!$this->exactDurableSucceededRunReceiptReady(
            $producerLedger,
            $producerDispatcherRunId,
            $expectedHotelId,
            $expectedDate,
            $expectedSourceIds,
            $expectedPlatforms
        )
            || ($cachedReceipt['collection_run_readback_verified'] ?? false) !== true
            || strtolower(trim((string)($cachedReceipt['collection_run_status'] ?? '')))
                !== 'succeeded'
        ) {
            return false;
        }
        $cachedAnchor = strtolower(trim((string)(
            $cachedReceipt['collection_anchor_hash'] ?? ''
        )));
        $cachedTrustDigest = strtolower(trim((string)(
            $cachedReceipt['trust_receipt_digest'] ?? ''
        )));
        if (preg_match('/^[a-f0-9]{64}$/D', $cachedAnchor) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $cachedTrustDigest) !== 1
            || !hash_equals(
                strtolower(trim((string)($producerLedger['collection_anchor_hash'] ?? ''))),
                $cachedAnchor
            )
            || !hash_equals(
                strtolower(trim((string)($producerLedger['trust_receipt_digest'] ?? ''))),
                $cachedTrustDigest
            )
        ) {
            return false;
        }

        $gate = $this->scheduledPlanGate;
        $gateScopeHash = strtolower(trim((string)($gate['scope_hash'] ?? '')));
        $ledgerScopeHash = strtolower(trim((string)($producerLedger['scope_hash'] ?? '')));
        if (($gate['collection_allowed'] ?? false) !== true
            || (int)($gate['system_hotel_id'] ?? 0) !== $expectedHotelId
            || substr(trim((string)($gate['business_date'] ?? '')), 0, 10)
                !== substr(trim($expectedDate), 0, 10)
            || strtolower(trim((string)($gate['run_mode'] ?? ''))) !== 'daily'
            || (int)($gate['plan_id'] ?? 0) !== (int)($producerLedger['plan_id'] ?? 0)
            || (int)($gate['plan_version'] ?? 0)
                !== (int)($producerLedger['plan_version'] ?? 0)
            || preg_match('/^[a-f0-9]{64}$/D', $gateScopeHash) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $ledgerScopeHash) !== 1
            || !hash_equals($gateScopeHash, $ledgerScopeHash)
        ) {
            return false;
        }

        $gateSources = is_array($gate['sources'] ?? null) ? $gate['sources'] : [];
        $ledgerSources = is_array($producerLedger['source_receipts'] ?? null)
            ? $producerLedger['source_receipts']
            : [];
        $cachedTasks = is_array($cachedReceipt['source_tasks'] ?? null)
            ? $cachedReceipt['source_tasks']
            : [];
        if (count($ledgerSources) !== 2 || count($cachedTasks) !== 2) {
            return false;
        }
        $ledgerByPlatform = [];
        foreach ($ledgerSources as $source) {
            if (!is_array($source)) {
                return false;
            }
            $platform = strtolower(trim((string)($source['platform'] ?? '')));
            if (!in_array($platform, ['ctrip', 'meituan'], true)
                || isset($ledgerByPlatform[$platform])
            ) {
                return false;
            }
            $ledgerByPlatform[$platform] = $source;
        }
        $taskByPlatform = [];
        foreach ($cachedTasks as $task) {
            if (!is_array($task)) {
                return false;
            }
            $platform = strtolower(trim((string)($task['platform'] ?? '')));
            if (!in_array($platform, ['ctrip', 'meituan'], true)
                || isset($taskByPlatform[$platform])
            ) {
                return false;
            }
            $taskByPlatform[$platform] = $task;
        }
        foreach (['ctrip', 'meituan'] as $platform) {
            $gateSource = is_array($gateSources[$platform] ?? null)
                ? $gateSources[$platform]
                : [];
            $ledgerSource = $ledgerByPlatform[$platform] ?? [];
            $task = $taskByPlatform[$platform] ?? [];
            $sourceId = (int)($ledgerSource['data_source_id'] ?? 0);
            $method = strtolower(trim((string)($ledgerSource['ingestion_method'] ?? '')));
            $syncTaskId = (int)($ledgerSource['platform_sync_task_id'] ?? 0);
            $localTaskId = (int)($ledgerSource['local_collector_task_id'] ?? 0);
            $rowIds = array_values(array_unique(array_filter(array_map(
                'intval',
                is_array($task['row_ids'] ?? null) ? $task['row_ids'] : []
            ), static fn(int $rowId): bool => $rowId > 0)));
            $expectedTrigger = $method === 'local_collector'
                ? 'local_collector_upload'
                : 'daily_profile_reuse';
            if ($sourceId <= 0
                || !in_array($method, ['browser_profile', 'local_collector'], true)
                || (int)($gateSource['data_source_id'] ?? 0) !== $sourceId
                || strtolower(trim((string)($gateSource['ingestion_method'] ?? ''))) !== $method
                || (int)($task['data_source_id'] ?? 0) !== $sourceId
                || (int)($task['sync_task_id'] ?? 0) !== $syncTaskId
                || (int)($task['local_collector_task_id'] ?? 0) !== $localTaskId
                || strtolower(trim((string)($task['ingestion_method'] ?? ''))) !== $method
                || strtolower(trim((string)($task['trigger_type'] ?? ''))) !== $expectedTrigger
                || $this->normalizeDispatcherRunId(
                    (string)($task['dispatcher_run_id'] ?? '')
                ) !== $producerDispatcherRunId
                || strtolower(trim((string)($task['collection_status'] ?? ''))) !== 'success'
                || strtolower(trim((string)($task['p0_status'] ?? ''))) !== 'ready'
                || strtolower(trim((string)(
                    $task['historical_core_contract_status'] ?? ''
                ))) !== 'ready'
                || ($task['readback_verified'] ?? false) !== true
                || $rowIds === []
                || count($rowIds) !== (int)($ledgerSource['saved_row_count'] ?? 0)
                || count($rowIds) !== (int)($ledgerSource['readback_row_count'] ?? 0)
            ) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, mixed> $receipt */
    private function machineReceiptDailyTrustReady(
        array $receipt,
        ?string $expectedDate = null,
        ?int $expectedHotelId = null,
        ?array $expectedSourceIds = null,
        ?array $expectedPlatforms = null
    ): bool
    {
        return (new ScheduledAutoFetchPolicy())->dailyTrustReceiptReady(
            $receipt,
            $expectedDate,
            $expectedHotelId,
            $expectedSourceIds,
            $expectedPlatforms
        );
    }

    /** @param array<string, mixed> $receipt */
    private function writeMachineReceipt(Output $output, array $receipt): void
    {
        if ($this->dispatcherRunId !== '') {
            $receipt['dispatcher_run_id'] = $this->dispatcherRunId;
        }
        $json = json_encode($receipt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($json)) {
            $output->writeln('SUXIOS_AUTO_FETCH_RECEIPT=' . $json);
        }
    }

    /** @param array<string,mixed> $producerReceipt */
    private function writeReusedCacheReceipt(
        Output $output,
        array $producerReceipt,
        int $hotelId,
        string $targetDate
    ): void {
        $producerRunId = strtolower(trim((string)(
            $producerReceipt['dispatcher_run_id'] ?? ''
        )));
        if (preg_match(
            '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',
            $producerRunId
        ) !== 1) {
            $producerRunId = '';
        }
        $producerAnchorHash = strtolower(trim((string)(
            $producerReceipt['collection_anchor_hash'] ?? ''
        )));
        if (preg_match('/^[a-f0-9]{64}$/D', $producerAnchorHash) !== 1) {
            $producerAnchorHash = '';
        }
        try {
            $output->writeln('SUXIOS_REUSED_CACHE_RECEIPT=' . json_encode([
                'schema_version' => 1,
                'receipt_type' => 'suxios_reused_verified_cache',
                'current_dispatcher_run_id' => $this->dispatcherRunId,
                'producer_dispatcher_run_id' => $producerRunId !== '' ? $producerRunId : null,
                'hotel_id' => $hotelId,
                'target_date' => substr(trim($targetDate), 0, 10),
                'status' => 'skipped',
                'reason' => 'verified_cache_reused',
                'producer_collection_anchor_hash' => $producerAnchorHash !== ''
                    ? $producerAnchorHash
                    : null,
                'current_collection_anchor_hash' => null,
                'current_source_tasks' => [],
                'sensitive_values_exposed' => false,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } catch (\Throwable $error) {
            Log::warning('Reused cache receipt serialization failed', [
                'hotel_id' => $hotelId,
                'target_date' => $targetDate,
                'dispatcher_run_id' => $this->dispatcherRunId,
                'exception_type' => get_debug_type($error),
            ]);
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
        $normalizeMetricKeys = static function (mixed $values): array {
            if (!is_array($values)) {
                return [];
            }
            $normalized = [];
            foreach ($values as $value) {
                $key = strtolower(trim((string)$value));
                if ($key !== '') {
                    $normalized[$key] = $key;
                }
            }
            return array_values($normalized);
        };
        $metricKeys = array_values(array_unique(array_map(
            static fn($value): string => strtolower(trim((string)$value)),
            is_array($receipt['verified_metric_keys'] ?? null) ? $receipt['verified_metric_keys'] : []
        )));
        $anchorVerified = ($receipt['readback_verified'] ?? false) === true
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
            )) !== [];
        if (!$anchorVerified) {
            return false;
        }

        if (strtolower(trim((string)($receipt['data_period'] ?? ''))) === 'realtime_snapshot') {
            $requiredTrafficMetricKeys = $normalizeMetricKeys(
                $receipt['required_traffic_metric_keys'] ?? []
            );
            $completeTrafficMetricKeys = $normalizeMetricKeys(
                $receipt['complete_traffic_metric_keys'] ?? []
            );
            $missingTrafficMetricKeys = $normalizeMetricKeys(
                $receipt['missing_traffic_metric_keys'] ?? []
            );
            return strtolower(trim((string)($receipt['field_fact_status'] ?? ''))) === 'ready'
                && $requiredTrafficMetricKeys !== []
                && $missingTrafficMetricKeys === []
                && array_diff($requiredTrafficMetricKeys, $completeTrafficMetricKeys) === [];
        }

        return count(array_intersect(['revenue', 'room_nights', 'adr'], $metricKeys)) === 3;
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

    protected function updateCtripLatestFetchStatus(?int $hotelId, string $fetchedAt, string $dataDate, int $savedCount): void
    {
        try {
            Cache::set($this->ctripLatestFetchStatusKey($hotelId), [
                'fetched_at' => $fetchedAt,
                'data_date' => $dataDate,
                'saved_count' => $savedCount,
            ], 86400 * 30);
        } catch (\Throwable $e) {
            // This projection is informational. Exact task/row readback has
            // already succeeded, so a cache outage must not rewrite both OTA
            // platform outcomes as a collection failure.
            Log::warning('Ctrip latest-fetch projection cache update failed', [
                'hotel_id' => $hotelId,
                'data_date' => $dataDate,
                'stage' => 'latest_fetch_projection',
                'exception_type' => get_debug_type($e),
            ]);
        }
    }

    /**
     * Every dispatcher result must carry the same exact hotel/date/source
     * identity as the plan gate, including failures that never created a task.
     *
     * @param array<int,mixed> $platformResults
     * @param array<int,string> $requiredPlatforms
     * @return array<int,array<string,mixed>>
     */
    private function scopedScheduledPlatformResults(
        array $platformResults,
        int $hotelId,
        string $businessDate,
        array $requiredPlatforms
    ): array {
        $requiredPlatforms = (new ScheduledAutoFetchPolicy())->normalizePlatforms($requiredPlatforms);
        if ($requiredPlatforms === []) {
            $requiredPlatforms = ['ctrip', 'meituan'];
        }
        $sources = is_array($this->scheduledPlanGate['sources'] ?? null)
            ? $this->scheduledPlanGate['sources']
            : [];
        $executionOwnerUserId = max(0, (int)(
            $this->scheduledPlanGate['execution_owner_user_id'] ?? 0
        ));
        $indexed = [];
        $other = [];
        foreach ($platformResults as $platformResult) {
            if (!is_array($platformResult)) {
                continue;
            }
            $platform = strtolower(trim((string)($platformResult['platform'] ?? '')));
            if (in_array($platform, ['ctrip', 'meituan'], true) && !isset($indexed[$platform])) {
                $indexed[$platform] = $platformResult;
            } else {
                $other[] = $platformResult;
            }
        }
        foreach ($requiredPlatforms as $platform) {
            $sourcePlan = is_array($sources[$platform] ?? null) ? $sources[$platform] : [];
            $sourceId = max(0, (int)($sourcePlan['data_source_id'] ?? 0));
            $result = is_array($indexed[$platform] ?? null)
                ? $indexed[$platform]
                : [
                    'platform' => $platform,
                    'success' => false,
                    'status' => 'failed',
                    'failure_reason' => 'scheduled_platform_result_missing',
                    'saved_count' => 0,
                    'readback_count' => 0,
                    'readback_verified' => false,
                    'run_readback' => [],
                    'message' => 'scheduled_platform_result_missing',
                ];
            $result['platform'] = $platform;
            $result['system_hotel_id'] = $hotelId;
            $result['target_date'] = $businessDate;
            $result['dispatcher_run_id'] = $this->dispatcherRunId;
            $result['execution_owner_user_id'] = $executionOwnerUserId;
            $sourceIngestionMethod = strtolower(trim((string)(
                $sourcePlan['ingestion_method'] ?? ''
            )));
            if (in_array($sourceIngestionMethod, ['browser_profile', 'local_collector'], true)) {
                $result['ingestion_method'] = $sourceIngestionMethod;
            }
            if ((int)($result['data_source_id'] ?? 0) <= 0 && $sourceId > 0) {
                $result['data_source_id'] = $sourceId;
            }
            $indexed[$platform] = $result;
        }
        return [
            ...array_values(array_intersect_key($indexed, array_flip($requiredPlatforms))),
            ...$other,
        ];
    }

    /**
     * A local collector task is asynchronous, while one dispatcher UUID is one
     * immutable producer attempt. Poll only that exact task until it reaches a
     * terminal result; a later dispatcher must never adopt its task or rows.
     *
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $initialResult
     * @return array<string,mixed>
     */
    private function awaitScheduledLocalCollection(
        OtaLocalCollectorService $collector,
        array $scope,
        array $initialResult,
        int $timeoutSeconds = self::LOCAL_PLAN_COMPLETION_TIMEOUT_SECONDS
    ): array {
        $result = $initialResult;
        $timeoutSeconds = max(0, min(self::LOCAL_PLAN_COMPLETION_TIMEOUT_SECONDS, $timeoutSeconds));
        $deadline = hrtime(true) + ($timeoutSeconds * 1_000_000_000);
        while (in_array(
            strtolower(trim((string)($result['status'] ?? ''))),
            ['queued', 'in_progress'],
            true
        )) {
            if ($timeoutSeconds === 0 || hrtime(true) >= $deadline) {
                $sourceTaskStatus = strtolower(trim((string)($result['status'] ?? 'in_progress')));
                return [
                    ...$result,
                    'success' => false,
                    'status' => 'in_progress',
                    'source_task_status' => $sourceTaskStatus,
                    'readback_verified' => false,
                    'failure_reason' => 'local_collector_plan_completion_timeout',
                    'message' => 'local_collector_plan_completion_timeout',
                    'automatic_device_substitution' => false,
                    'sensitive_values_exposed' => false,
                ];
            }
            $remainingMicroseconds = (int)max(
                1,
                min(
                    self::LOCAL_PLAN_POLL_INTERVAL_MICROSECONDS,
                    intdiv(max(0, $deadline - hrtime(true)), 1_000)
                )
            );
            usleep($remainingMicroseconds);
            $result = $collector->schedulePlanCollection($scope);
        }
        return $result;
    }

    /** @param array<int,array<string,mixed>> $platformResults */
    private function recordScheduledPlatformResults(
        int $hotelId,
        string $businessDate,
        array $platformResults
    ): bool {
        try {
            $receipt = (new HotelCollectionRunReceiptService())->recordPlatformResults(
                $this->dispatcherRunId,
                $hotelId,
                $businessDate,
                $platformResults
            );
            // recordPlatformResults may legitimately leave the aggregate run
            // active while an operator-owned local task is still queued or
            // waiting for login. At this stage we need the exact two-slot DB
            // readback, not a terminal-completion claim.
            return ($receipt['ledger_structure_verified']
                ?? $receipt['readback_verified']
                ?? false) === true;
        } catch (\Throwable $error) {
            Log::error('Hotel collection run result receipt write failed', [
                'hotel_id' => $hotelId,
                'business_date' => $businessDate,
                'dispatcher_run_id' => $this->dispatcherRunId,
                'exception_type' => get_debug_type($error),
            ]);
            return false;
        }
    }

    /**
     * @param array<string,mixed> $receipt
     * @return array<string,mixed>|false
     */
    private function finalizeScheduledCollectionReceipt(
        int $hotelId,
        string $businessDate,
        array $receipt,
        bool $trustedReady
    ): array|false {
        try {
            $runReceipt = (new HotelCollectionRunReceiptService())->finalizeCollection(
                $this->dispatcherRunId,
                $hotelId,
                $businessDate,
                $receipt,
                $trustedReady
            );
            if (($runReceipt['readback_verified'] ?? false) !== true) {
                return false;
            }
            $status = strtolower(trim((string)($runReceipt['status'] ?? '')));
            $anchorHash = strtolower(trim((string)(
                $runReceipt['collection_anchor_hash'] ?? ''
            )));
            if (!$trustedReady) {
                return $status !== 'succeeded' && $anchorHash === ''
                    ? $runReceipt
                    : false;
            }
            return $status === 'succeeded'
                && preg_match('/^[a-f0-9]{64}$/D', $anchorHash) === 1
                && hash_equals(
                    strtolower(trim((string)($receipt['collection_anchor_hash'] ?? ''))),
                    $anchorHash
                )
                && preg_match(
                    '/^[a-f0-9]{64}$/D',
                    strtolower(trim((string)($runReceipt['trust_receipt_digest'] ?? '')))
                ) === 1
                    ? $runReceipt
                    : false;
        } catch (\Throwable $error) {
            Log::error('Hotel collection run final receipt write failed', [
                'hotel_id' => $hotelId,
                'business_date' => $businessDate,
                'dispatcher_run_id' => $this->dispatcherRunId,
                'exception_type' => get_debug_type($error),
            ]);
            return false;
        }
    }

    /** @return array<string,mixed> */
    private function attachExactScheduledPmsCapture(
        int $tenantId,
        int $hotelId,
        string $businessDate
    ): array {
        $missing = [
            'status' => 'not_attached',
            'provider' => DingdandaoOperatingTargetCaptureService::PROVIDER,
            'capture_id' => null,
            'readback_verified' => false,
            'failure_code' => 'dingdandao_capture_missing',
            'sensitive_values_exposed' => false,
        ];
        try {
            $capture = (new DingdandaoOperatingTargetCaptureService())->latest(
                $tenantId,
                $hotelId,
                $businessDate
            );
            $captureId = max(0, (int)($capture['id'] ?? 0));
            if ($captureId <= 0) {
                $captureGaps = is_array($capture['gaps'] ?? null) ? $capture['gaps'] : [];
                $firstGap = is_array($captureGaps[0] ?? null) ? $captureGaps[0] : [];
                $failureCode = trim((string)(
                    $capture['failure_code']
                    ?? $capture['reason']
                    ?? $firstGap['code']
                    ?? 'dingdandao_capture_missing'
                ));
                if (preg_match('/^[a-z0-9_]{1,120}$/D', $failureCode) === 1) {
                    $missing['failure_code'] = $failureCode;
                }
                return $missing;
            }
            $runReceipt = (new HotelCollectionRunReceiptService())->recordPmsCapture(
                $this->dispatcherRunId,
                $hotelId,
                $businessDate,
                DingdandaoOperatingTargetCaptureService::PROVIDER,
                $captureId
            );
            return [
                'status' => 'attached',
                'provider' => DingdandaoOperatingTargetCaptureService::PROVIDER,
                'capture_id' => $captureId,
                'readback_verified' => (
                    $runReceipt['pms_receipt']['status'] ?? ''
                ) === 'verified'
                    && ($runReceipt['pms_receipt']['readback_verified'] ?? false) === true,
                'failure_code' => '',
                'sensitive_values_exposed' => false,
            ];
        } catch (\Throwable $error) {
            Log::warning('Hotel collection run PMS receipt was not attached', [
                'hotel_id' => $hotelId,
                'business_date' => $businessDate,
                'dispatcher_run_id' => $this->dispatcherRunId,
                'exception_type' => get_debug_type($error),
            ]);
            $failureCode = trim($error->getMessage());
            $missing['failure_code'] = preg_match(
                '/^(?:hotel_collection_run_pms|dingdandao_)[a-z0-9_]+$/D',
                $failureCode
            ) === 1
                ? $failureCode
                : 'hotel_collection_run_pms_attachment_failed';
            return $missing;
        }
    }

    private function markScheduledNoCollectionOutcome(
        Output $output,
        int $hotelId,
        string $businessDate,
        string $outcomeCode
    ): bool {
        if ($this->dispatcherRunId === '') {
            return true;
        }
        try {
            $runReceipt = (new HotelCollectionRunReceiptService())->markNoCollectionOutcome(
                $this->dispatcherRunId,
                $hotelId,
                $businessDate,
                $outcomeCode
            );
            $output->writeln(
                'SUXIOS_COLLECTION_RUN_RECEIPT='
                . json_encode(
                    $runReceipt,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                )
            );
            $sourceReceipts = is_array($runReceipt['source_receipts'] ?? null)
                ? $runReceipt['source_receipts']
                : [];
            $exactAnchorlessReadback = ($runReceipt['readback_verified'] ?? false) === true
                && count($sourceReceipts) === 2
                && trim((string)($runReceipt['collection_anchor_hash'] ?? '')) === ''
                && trim((string)($runReceipt['trust_receipt_digest'] ?? '')) === ''
                && trim((string)($runReceipt['finished_at'] ?? '')) !== '';
            foreach ($sourceReceipts as $sourceReceipt) {
                if (!is_array($sourceReceipt)
                    || (string)($sourceReceipt['failure_code'] ?? '') !== $outcomeCode
                    || (int)($sourceReceipt['platform_sync_task_id'] ?? 0) > 0
                    || (int)($sourceReceipt['local_collector_task_id'] ?? 0) > 0
                    || ($sourceReceipt['readback_verified'] ?? false) === true
                ) {
                    $exactAnchorlessReadback = false;
                    break;
                }
            }
            return $exactAnchorlessReadback;
        } catch (\Throwable $error) {
            Log::error('Hotel collection no-collection outcome receipt write failed', [
                'hotel_id' => $hotelId,
                'business_date' => $businessDate,
                'dispatcher_run_id' => $this->dispatcherRunId,
                'outcome_code' => $outcomeCode,
                'exception_type' => get_debug_type($error),
            ]);
            return false;
        }
    }

    private function blockChangedScheduledCollectionScope(
        Output $output,
        int $hotelId,
        string $businessDate
    ): bool {
        if ($this->dispatcherRunId === '') {
            return false;
        }
        try {
            $runReceipt = (new HotelCollectionRunReceiptService())
                ->blockScopeChangedDuringActiveRun(
                    $this->dispatcherRunId,
                    $hotelId,
                    $businessDate
                );
            $output->writeln(
                'SUXIOS_COLLECTION_RUN_RECEIPT='
                . json_encode(
                    $runReceipt,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                )
            );
            $sourceReceipts = is_array($runReceipt['source_receipts'] ?? null)
                ? $runReceipt['source_receipts']
                : [];
            $exactBlockedReadback = (string)($runReceipt['status'] ?? '') === 'blocked'
                && (string)($runReceipt['failure_stage'] ?? '') === 'plan_gate'
                && (string)($runReceipt['failure_code'] ?? '')
                    === 'plan_scope_changed_during_active_run'
                && trim((string)($runReceipt['collection_anchor_hash'] ?? '')) === ''
                && trim((string)($runReceipt['trust_receipt_digest'] ?? '')) === ''
                && trim((string)($runReceipt['finished_at'] ?? '')) !== ''
                && count($sourceReceipts) === 2;
            foreach ($sourceReceipts as $sourceReceipt) {
                if (!is_array($sourceReceipt)
                    || (string)($sourceReceipt['status'] ?? '') !== 'blocked'
                    || (string)($sourceReceipt['failure_stage'] ?? '') !== 'plan_gate'
                    || (string)($sourceReceipt['failure_code'] ?? '')
                        !== 'plan_scope_changed_during_active_run'
                ) {
                    $exactBlockedReadback = false;
                    break;
                }
            }
            return $exactBlockedReadback;
        } catch (\Throwable $error) {
            try {
                Log::error('Hotel collection changed-scope run could not be sealed', [
                    'hotel_id' => $hotelId,
                    'business_date' => $businessDate,
                    'dispatcher_run_id' => $this->dispatcherRunId,
                    'exception_type' => get_debug_type($error),
                ]);
            } catch (\Throwable) {
                // Logging must not turn an unsealed scope into a terminal receipt.
            }
            return false;
        }
    }

    private function updateStatus(int $hotelId, bool $success, string $message, ?string $dataDate = null, array $details = []): void
    {
        $dataDate = $dataDate ?: date('Y-m-d', strtotime('-1 day'));
        $statusCode = (string)($details['status'] ?? ($success ? 'success' : 'failed'));
        $failedPlatforms = $this->normalizeFailedPlatforms($details['failed_platforms'] ?? []);
        $successfulPlatforms = $this->normalizeFailedPlatforms($details['successful_platforms'] ?? []);
        (new OnlineDataAutoFetchStatusStore())->mutate(
            $hotelId,
            fn(array $status): array => $this->buildUpdatedStatus(
                $status,
                $success,
                $message,
                $dataDate,
                $details,
                $statusCode,
                $failedPlatforms,
                $successfulPlatforms
            )
        );

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
     * @param array<string,mixed> $status
     * @param array<string,mixed> $details
     * @param array<int,string> $failedPlatforms
     * @param array<int,string> $successfulPlatforms
     * @return array<string,mixed>
     */
    private function buildUpdatedStatus(
        array $status,
        bool $success,
        string $message,
        string $dataDate,
        array $details,
        string $statusCode,
        array $failedPlatforms,
        array $successfulPlatforms
    ): array {
        $runAt = date('Y-m-d H:i:s');
        $runRecord = [
            'run_at' => $runAt,
            'data_date' => $dataDate,
            'success' => $success,
            'message' => $message,
        ];
        $dataPeriod = $this->normalizeOnlineDailyDataPeriod($details['data_period'] ?? $details['dataPeriod'] ?? '');
        $slotId = trim((string)($details['slot_id'] ?? ''));
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

        return $status;
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
