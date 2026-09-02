<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use RuntimeException;
use think\facade\Db;

/**
 * Executes active hotel collection plans through one process-wide serial queue.
 *
 * The queue never sends messages. It re-authorizes every persisted plan before
 * starting children, resolves one exact ready cloud Profile per source, and
 * returns only bounded, secret-free receipts. The CLI entrypoint owns the
 * process-lifetime global flock.
 */
final class CloudThreeSourceCollectionQueueService
{
    private const TIMEZONE = 'Asia/Shanghai';
    private const SOURCE_ORDER = ['pms', 'ctrip', 'meituan'];
    private const OTA_PROFILE_PLATFORM = [
        'ctrip' => 'ctrip',
        'meituan' => 'meituan',
    ];
    private const PMS_PROFILE_PLATFORM = [
        HotelPmsBindingService::PROVIDER_DINGDANDAO => 'dingdandao',
        HotelPmsBindingService::PROVIDER_MEITUAN_CLOUD => 'meituan_cloud_pms',
    ];
    private const CONTROL_TOKEN_FILES = [
        '/run/credentials/suxios-cloud-three-source-queue.service/control-token',
        '/etc/suxios-cloud-browser/control-token',
    ];
    private const GATEWAY_URL = 'http://127.0.0.1:8787';
    private const SETSID_BINARY = '/usr/bin/setsid';
    private const MAX_CAPTURED_PROCESS_OUTPUT_BYTES = 262_144;
    private const MAX_TRANSIENT_RETRIES = 2;
    private const RETRY_BACKOFF_SECONDS = [30, 90];
    private const CLEANUP_RESERVE_SECONDS = 15;
    private const FINALIZATION_RECEIPT_RESERVE_SECONDS = 15;
    private const MIN_CHILD_WINDOW_SECONDS = 60;
    private const TRANSIENT_FAILURE_CODES = [
        'gateway_collection_capacity_busy',
        'gateway_temporarily_unavailable',
        'gateway_connection_timeout',
        'gateway_connection_refused',
        'cloud_ota_gateway_temporarily_unavailable',
        'cloud_ota_gateway_connection_timeout',
        'cloud_ota_gateway_connection_refused',
    ];

    /** @var callable|null */
    private $planLoader;

    /** @var callable|null */
    private $hotelLoader;

    /** @var callable|null */
    private $authorizationLoader;

    /** @var callable|null */
    private $profileLoader;

    /** @var callable|null */
    private $childRunner;

    /** @var callable|null */
    private $clock;

    /** @var callable|null */
    private $monotonicClock;

    /** @var callable|null */
    private $collectionAborter;

    /** @var callable|null */
    private $sleeper;

    /** @var callable|null */
    private $runReceiptWriter;

    /** @var callable|null */
    private $canonicalHistoryFinalizer;

    /** @var callable|null */
    private $attemptStateLoader;

    private string $applicationRoot;

    public function __construct(
        ?callable $planLoader = null,
        ?callable $hotelLoader = null,
        ?callable $authorizationLoader = null,
        ?callable $profileLoader = null,
        ?callable $childRunner = null,
        ?callable $clock = null,
        ?callable $monotonicClock = null,
        ?string $applicationRoot = null,
        ?callable $collectionAborter = null,
        ?callable $sleeper = null,
        ?callable $runReceiptWriter = null,
        ?callable $canonicalHistoryFinalizer = null,
        ?callable $attemptStateLoader = null
    ) {
        $this->planLoader = $planLoader;
        $this->hotelLoader = $hotelLoader;
        $this->authorizationLoader = $authorizationLoader;
        $this->profileLoader = $profileLoader;
        $this->childRunner = $childRunner;
        $this->clock = $clock;
        $this->monotonicClock = $monotonicClock;
        $this->applicationRoot = rtrim($applicationRoot ?? dirname(__DIR__, 2), '/\\');
        $this->collectionAborter = $collectionAborter;
        $this->sleeper = $sleeper;
        $this->runReceiptWriter = $runReceiptWriter;
        $this->canonicalHistoryFinalizer = $canonicalHistoryFinalizer;
        $this->attemptStateLoader = $attemptStateLoader;
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function run(array $options = []): array
    {
        $now = $this->now();
        $requestedTargetDate = trim((string)($options['target_date'] ?? ''));
        $childTimeoutSeconds = max(60, min(900, (int)($options['child_timeout_seconds'] ?? 540)));
        $deadlineSeconds = max(60, min(3300, (int)($options['deadline_seconds'] ?? 1500)));
        $controlTokenFile = trim((string)($options['control_token_file']
            ?? self::CONTROL_TOKEN_FILES[0]));
        if (($requestedTargetDate !== '' && !$this->validDate($requestedTargetDate))
            || !in_array($controlTokenFile, self::CONTROL_TOKEN_FILES, true)
        ) {
            return $this->queueFailure('cloud_three_source_queue_arguments_invalid');
        }

        try {
            $plans = array_values(array_filter($this->loadPlans(), fn(array $plan): bool =>
                $this->eligiblePlan($plan)
            ));
        } catch (\Throwable $error) {
            return $this->queueFailure($this->safeCode($error->getMessage()) ?: 'collection_plan_read_failed');
        }
        usort($plans, static fn(array $left, array $right): int => [
            (int)($left['tenant_id'] ?? 0),
            (int)($left['system_hotel_id'] ?? 0),
            (int)($left['id'] ?? 0),
        ] <=> [
            (int)($right['tenant_id'] ?? 0),
            (int)($right['system_hotel_id'] ?? 0),
            (int)($right['id'] ?? 0),
        ]);

        if ($plans === []) {
            return [
                'status' => 'no_eligible_plans',
                'execution_mode' => 'global_serial',
                'source_order' => self::SOURCE_ORDER,
                'retry_policy' => $this->retryPolicy(),
                'target_date' => $requestedTargetDate !== '' ? $requestedTargetDate : null,
                'target_dates' => [],
                'eligible_plan_count' => 0,
                'verified_hotel_count' => 0,
                'blocked_hotel_count' => 0,
                'deadline_reached' => false,
                'hotels' => [],
                'message_sent' => false,
                'sensitive_values_exposed' => false,
            ];
        }

        $scopeCounts = [];
        foreach ($plans as $plan) {
            $scope = (int)($plan['tenant_id'] ?? 0) . ':' . (int)($plan['system_hotel_id'] ?? 0);
            $scopeCounts[$scope] = ($scopeCounts[$scope] ?? 0) + 1;
        }

        $deadlineAt = $this->monotonicNow() + $deadlineSeconds;
        $hotelReceipts = [];
        $verifiedHotelCount = 0;
        $duePlanCount = 0;
        $skippedPlanCount = 0;
        $blockedPlanCount = 0;
        $deadlineReached = false;
        $gatewayCleanupVerified = true;
        $resolvedTargetDates = [];
        foreach ($plans as $plan) {
            $executionWindow = $this->resolveExecutionWindow(
                $plan,
                $now,
                $requestedTargetDate
            );
            $targetDate = (string)($executionWindow['target_date'] ?? '');
            $runMode = (string)($executionWindow['run_mode'] ?? '');
            if (($executionWindow['status'] ?? '') !== 'ready') {
                $hotelReceipts[] = $this->blockedHotelReceipt(
                    $plan,
                    $targetDate,
                    (string)($executionWindow['reason'] ?? 'collection_plan_execution_window_invalid')
                );
                $blockedPlanCount++;
                continue;
            }
            $resolvedTargetDates[$targetDate] = true;
            $due = $this->executionDue($plan, $targetDate, $runMode, $now);
            if (($due['status'] ?? '') !== 'ready') {
                if (($due['status'] ?? '') === 'not_due') {
                    $hotelReceipts[] = $this->skippedHotelReceipt(
                        $plan,
                        $targetDate,
                        (string)($due['reason'] ?? 'collection_plan_not_due')
                    );
                    $skippedPlanCount++;
                } else {
                    $hotelReceipts[] = $this->blockedHotelReceipt(
                        $plan,
                        $targetDate,
                        (string)($due['reason'] ?? 'collection_plan_due_state_blocked')
                    );
                    $blockedPlanCount++;
                }
                continue;
            }
            $duePlanCount++;
            if (!$gatewayCleanupVerified) {
                $hotelReceipts[] = $this->blockedHotelReceipt(
                    $plan,
                    $targetDate,
                    'previous_timeout_cleanup_unverified'
                );
                continue;
            }
            if ($this->monotonicNow() >= $deadlineAt) {
                $deadlineReached = true;
                $hotelReceipts[] = $this->blockedHotelReceipt($plan, $targetDate, 'queue_deadline_reached');
                continue;
            }
            $scope = (int)($plan['tenant_id'] ?? 0) . ':' . (int)($plan['system_hotel_id'] ?? 0);
            if (($scopeCounts[$scope] ?? 0) !== 1) {
                $hotelReceipts[] = $this->blockedHotelReceipt($plan, $targetDate, 'collection_plan_scope_conflict');
                continue;
            }

            $receipt = $this->runPlan(
                $plan,
                $targetDate,
                $runMode,
                $controlTokenFile,
                $childTimeoutSeconds,
                $deadlineAt
            );
            if (($receipt['status'] ?? '') === 'all_sources_saved_and_readback_verified') {
                $verifiedHotelCount++;
            }
            if (($receipt['deadline_reached'] ?? false) === true) {
                $deadlineReached = true;
            }
            if (($receipt['gateway_cleanup_verified'] ?? true) !== true) {
                $gatewayCleanupVerified = false;
            }
            $hotelReceipts[] = $receipt;
        }

        $allVerified = $duePlanCount > 0
            && $verifiedHotelCount === $duePlanCount
            && $blockedPlanCount === 0
            && !$deadlineReached;
        return [
            'status' => $duePlanCount === 0 && $blockedPlanCount === 0
                ? 'no_due_plans'
                : ($allVerified ? 'all_hotels_saved_and_readback_verified' : 'partial_or_blocked'),
            'execution_mode' => 'global_serial',
            'source_order' => self::SOURCE_ORDER,
            'retry_policy' => $this->retryPolicy(),
            'target_date' => count($resolvedTargetDates) === 1
                ? (string)array_key_first($resolvedTargetDates)
                : null,
            'target_dates' => array_values(array_keys($resolvedTargetDates)),
            'eligible_plan_count' => count($plans),
            'due_plan_count' => $duePlanCount,
            'skipped_plan_count' => $skippedPlanCount,
            'blocked_plan_count' => $blockedPlanCount,
            'verified_hotel_count' => $verifiedHotelCount,
            'blocked_hotel_count' => max(0, $duePlanCount - $verifiedHotelCount) + $blockedPlanCount,
            'deadline_reached' => $deadlineReached,
            'gateway_cleanup_verified' => $gatewayCleanupVerified,
            'hotels' => $hotelReceipts,
            'message_sent' => false,
            'sensitive_values_exposed' => false,
        ];
    }

    /**
     * Resolve the business date from the persisted plan instead of forcing
     * every queue run into the same-day realtime contract. An explicit date is
     * accepted only when it matches the plan's current policy window.
     *
     * @param array<string,mixed> $plan
     * @return array{status:string,target_date:string,run_mode:string,reason:?string}
     */
    private function resolveExecutionWindow(
        array $plan,
        DateTimeImmutable $now,
        string $requestedTargetDate
    ): array {
        $policy = strtolower(trim((string)($plan['business_date_policy'] ?? '')));
        $runMode = match ($policy) {
            'same_day_realtime' => 'realtime',
            'previous_business_day' => 'daily',
            default => '',
        };
        if ($runMode === '') {
            return [
                'status' => 'blocked',
                'target_date' => $requestedTargetDate,
                'run_mode' => '',
                'reason' => 'collection_plan_business_date_policy_unsupported',
            ];
        }

        $expectedTargetDate = $policy === 'previous_business_day'
            ? $now->modify('-1 day')->format('Y-m-d')
            : $now->format('Y-m-d');
        if ($requestedTargetDate !== '' && $requestedTargetDate !== $expectedTargetDate) {
            return [
                'status' => 'blocked',
                'target_date' => $requestedTargetDate,
                'run_mode' => $runMode,
                'reason' => 'collection_plan_target_date_policy_mismatch',
            ];
        }

        return [
            'status' => 'ready',
            'target_date' => $requestedTargetDate !== ''
                ? $requestedTargetDate
                : $expectedTargetDate,
            'run_mode' => $runMode,
            'reason' => null,
        ];
    }

    /**
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private function runPlan(
        array $plan,
        string $targetDate,
        string $runMode,
        string $controlTokenFile,
        int $childTimeoutSeconds,
        float $deadlineAt
    ): array {
        $otaCaptureTimeoutSeconds = max(60, $childTimeoutSeconds - 180);
        $planId = (int)($plan['id'] ?? 0);
        $tenantId = (int)($plan['tenant_id'] ?? 0);
        $hotelId = (int)($plan['system_hotel_id'] ?? 0);
        $ownerUserId = (int)($plan['execution_owner_user_id'] ?? 0);
        $planHash = strtolower(trim((string)($plan['plan_hash'] ?? '')));
        if ($planId <= 0
            || $tenantId <= 0
            || $hotelId <= 0
            || $ownerUserId <= 0
            || preg_match('/^[a-f0-9]{64}$/D', $planHash) !== 1
        ) {
            return $this->blockedHotelReceipt($plan, $targetDate, 'collection_plan_scope_invalid');
        }
        $businessDatePolicy = strtolower(trim((string)($plan['business_date_policy'] ?? '')));
        $expectedRunMode = match ($businessDatePolicy) {
            'same_day_realtime' => 'realtime',
            'previous_business_day' => 'daily',
            default => '',
        };
        if ($expectedRunMode === '') {
            return $this->blockedHotelReceipt($plan, $targetDate, 'collection_plan_business_date_policy_unsupported');
        }
        if ($runMode !== $expectedRunMode) {
            return $this->blockedHotelReceipt($plan, $targetDate, 'collection_plan_execution_mode_mismatch');
        }
        $dataPeriod = $runMode === 'daily' ? 'historical_daily' : 'realtime_snapshot';

        $sourcePlan = $this->decodeArray($plan['source_plan_json'] ?? null);
        $ctripSourceId = (int)($sourcePlan['ctrip']['data_source_id'] ?? 0);
        $meituanSourceId = (int)($sourcePlan['meituan']['data_source_id'] ?? 0);
        $pmsProvider = strtolower(trim((string)($sourcePlan['pms']['provider'] ?? '')));
        if ($ctripSourceId <= 0
            || $meituanSourceId <= 0
            || !array_key_exists($pmsProvider, self::PMS_PROFILE_PLATFORM)
        ) {
            return $this->blockedHotelReceipt($plan, $targetDate, 'collection_plan_source_scope_invalid');
        }
        if ($runMode === 'daily'
            && $pmsProvider === HotelPmsBindingService::PROVIDER_MEITUAN_CLOUD
        ) {
            return $this->blockedHotelReceipt(
                $plan,
                $targetDate,
                'collection_plan_pms_historical_unsupported'
            );
        }

        try {
            $hotel = $this->loadHotel($tenantId, $hotelId);
        } catch (\Throwable $error) {
            return $this->blockedHotelReceipt(
                $plan,
                $targetDate,
                $this->safeCode($error->getMessage()) ?: 'hotel_scope_read_failed'
            );
        }
        if ((int)($hotel['id'] ?? 0) !== $hotelId
            || (int)($hotel['tenant_id'] ?? 0) !== $tenantId
            || (int)($hotel['status'] ?? 0) !== 1
        ) {
            return $this->blockedHotelReceipt($plan, $targetDate, 'hotel_scope_invalid');
        }

        try {
            $authorization = $this->authorize(
                $hotel,
                $targetDate,
                [$ctripSourceId, $meituanSourceId],
                ['ctrip', 'meituan'],
                $runMode
            );
        } catch (\Throwable $error) {
            return $this->blockedHotelReceipt(
                $plan,
                $targetDate,
                $this->safeCode($error->getMessage()) ?: 'collection_plan_authorization_failed'
            );
        }
        $expectedSourceIds = [$ctripSourceId, $meituanSourceId];
        sort($expectedSourceIds, SORT_NUMERIC);
        $authorizedSourceIds = array_values(array_unique(array_filter(array_map(
            'intval',
            (array)($authorization['actual_source_ids'] ?? [])
        ))));
        sort($authorizedSourceIds, SORT_NUMERIC);
        $authorizationVerified = ($authorization['collection_allowed'] ?? false) === true
            && (int)($authorization['tenant_id'] ?? 0) === $tenantId
            && (int)($authorization['system_hotel_id'] ?? 0) === $hotelId
            && (int)($authorization['execution_owner_user_id'] ?? 0) === $ownerUserId
            && (int)($authorization['plan_id'] ?? 0) === $planId
            && (string)($authorization['business_date'] ?? '') === $targetDate
            && (string)($authorization['run_mode'] ?? '') === $runMode
            && hash_equals(
                $planHash,
                strtolower(trim((string)($authorization['plan_hash'] ?? '')))
            )
            && $authorizedSourceIds === $expectedSourceIds
            && $this->platformList($authorization['actual_platforms'] ?? []) === ['ctrip', 'meituan']
            && ($authorization['plan_readback_verified'] ?? false) === true
            && ($authorization['binding_digest_matches'] ?? false) === true;

        $dispatcherRunId = $this->newDispatcherRunId();
        $runGate = $authorization;
        $runGate['dispatcher_run_id'] = $dispatcherRunId;
        $runGate['tenant_id'] = $tenantId;
        $runGate['system_hotel_id'] = $hotelId;
        $runGate['business_date'] = $targetDate;
        $runGate['run_mode'] = $runMode;
        $runGate['data_period'] = $dataPeriod;
        $runGate['plan_id'] = $planId;
        $runGate['plan_version'] = max(0, (int)($plan['plan_version'] ?? 0));
        $runGate['plan_hash'] = $planHash;
        $runGate['collection_allowed'] = $authorizationVerified;
        $runGate['execution_owner_user_id'] = $ownerUserId;
        $runGate['expected_source_ids'] = $expectedSourceIds;
        $runGate['sources'] = [
            'ctrip' => [
                'platform' => 'ctrip',
                'data_source_id' => $ctripSourceId,
                'ingestion_method' => 'browser_profile',
            ],
            'meituan' => [
                'platform' => 'meituan',
                'data_source_id' => $meituanSourceId,
                'ingestion_method' => 'browser_profile',
            ],
            'pms' => ['provider' => $pmsProvider],
        ];
        if (!$authorizationVerified) {
            $failureReasons = is_array($runGate['failure_reasons'] ?? null)
                ? $runGate['failure_reasons']
                : [];
            $failureReasons[] = [
                'code' => 'collection_plan_authorization_blocked',
                'platform' => '',
            ];
            $runGate['failure_reasons'] = $failureReasons;
        }
        try {
            $beginReceipt = $this->writeRunReceipt('begin', ['gate' => $runGate]);
            if (!$this->beginRunReceiptVerified(
                $beginReceipt,
                $dispatcherRunId,
                $tenantId,
                $hotelId,
                $targetDate,
                $runMode,
                $expectedSourceIds,
                $authorizationVerified ? 'started' : 'blocked'
            )) {
                throw new RuntimeException('collection_run_receipt_begin_readback_unverified');
            }
        } catch (\Throwable) {
            $blocked = $this->blockedHotelReceipt(
                $plan,
                $targetDate,
                'collection_run_receipt_begin_failed'
            );
            $blocked['dispatcher_run_id'] = $dispatcherRunId;
            $blocked['run_receipt_structure_verified'] = false;
            $blocked['run_receipt_status'] = 'unverified';
            return $blocked;
        }
        if (!$authorizationVerified) {
            $blocked = $this->blockedHotelReceipt(
                $plan,
                $targetDate,
                'collection_plan_authorization_blocked'
            );
            $blocked['dispatcher_run_id'] = $dispatcherRunId;
            $blocked['run_receipt_structure_verified'] = true;
            $blocked['run_receipt_status'] = 'blocked';
            $blocked['run_receipt_readback_verified'] = ($beginReceipt['readback_verified'] ?? false) === true;
            return $blocked;
        }

        try {
            $profiles = $this->resolveProfiles(
                $this->loadProfiles($tenantId, $hotelId, $ownerUserId),
                $tenantId,
                $hotelId,
                $ownerUserId,
                self::PMS_PROFILE_PLATFORM[$pmsProvider],
                $this->now()
            );
        } catch (\Throwable $error) {
            $reason = $this->safeCode($error->getMessage()) ?: 'cloud_profile_scope_invalid';
            $ledger = $this->recordPreflightFailure(
                $dispatcherRunId,
                $tenantId,
                $hotelId,
                $targetDate,
                $pmsProvider,
                $ctripSourceId,
                $meituanSourceId,
                $runMode,
                $reason
            );
            $blocked = $this->blockedHotelReceipt(
                $plan,
                $targetDate,
                $reason
            );
            $blocked['dispatcher_run_id'] = $dispatcherRunId;
            $blocked['run_receipt_structure_verified'] = $ledger;
            $blocked['run_receipt_status'] = $ledger ? 'failed' : 'unverified';
            return $blocked;
        }

        $pmsScript = $pmsProvider === HotelPmsBindingService::PROVIDER_DINGDANDAO
            ? 'run_dingdandao_cloud_collection.php'
            : 'run_meituan_cloud_pms_collection.php';
        $pmsCommand = [
            PHP_BINARY,
            '-d',
            'memory_limit=384M',
            $this->applicationRoot . '/scripts/' . $pmsScript,
            '--hotel-id=' . $hotelId,
            '--owner-user-id=' . $ownerUserId,
            '--profile-id=' . $profiles['pms']['profile_public_id'],
            '--target-date=' . $targetDate,
            '--run-mode=' . $runMode,
            '--control-token-file=' . $controlTokenFile,
            '--no-push',
        ];
        if ($pmsProvider === HotelPmsBindingService::PROVIDER_DINGDANDAO) {
            $pmsCommand[] = '--fresh-observation';
        }
        $commands = [
            'pms' => $pmsCommand,
            'ctrip' => [
                PHP_BINARY,
                '-d',
                'memory_limit=384M',
                $this->applicationRoot . '/scripts/run_cloud_ota_profile_collection.php',
                '--data-source-id=' . $ctripSourceId,
                '--owner-user-id=' . $ownerUserId,
                '--profile-id=' . $profiles['ctrip']['profile_public_id'],
                '--target-date=' . $targetDate,
                '--run-mode=' . $runMode,
                '--control-token-file=' . $controlTokenFile,
                '--timeout-seconds=' . $otaCaptureTimeoutSeconds,
                '--dispatcher-run-id=' . $dispatcherRunId,
            ],
            'meituan' => [
                PHP_BINARY,
                '-d',
                'memory_limit=384M',
                $this->applicationRoot . '/scripts/run_cloud_ota_profile_collection.php',
                '--data-source-id=' . $meituanSourceId,
                '--owner-user-id=' . $ownerUserId,
                '--profile-id=' . $profiles['meituan']['profile_public_id'],
                '--target-date=' . $targetDate,
                '--run-mode=' . $runMode,
                '--control-token-file=' . $controlTokenFile,
                '--timeout-seconds=' . $otaCaptureTimeoutSeconds,
                '--dispatcher-run-id=' . $dispatcherRunId,
            ],
        ];
        $sourceIds = ['pms' => 0, 'ctrip' => $ctripSourceId, 'meituan' => $meituanSourceId];
        $sourceReceipts = [];
        $allVerified = true;
        $deadlineReached = false;
        $gatewayCleanupVerified = true;
        $runReceiptWritesVerified = true;
        $finalRunReceipt = [];
        $skipRemainingReason = null;
        foreach (self::SOURCE_ORDER as $sourceKey) {
            if (is_string($skipRemainingReason)) {
                $allVerified = false;
                $skippedReceipt = $this->blockedSourceReceipt(
                    $sourceKey,
                    $sourceIds[$sourceKey],
                    $skipRemainingReason
                );
                if ($runReceiptWritesVerified) {
                    $runReceiptWritesVerified = $this->recordFinalSourceReceipt(
                        $dispatcherRunId,
                        $tenantId,
                        $hotelId,
                        $targetDate,
                        $pmsProvider,
                        $sourceKey,
                        $skippedReceipt
                    );
                    $skippedReceipt['run_receipt_recorded'] = $runReceiptWritesVerified;
                } else {
                    $skippedReceipt['run_receipt_recorded'] = false;
                }
                $sourceReceipts[] = $skippedReceipt;
                continue;
            }
            $attemptCount = 0;
            $retryDelays = [];
            $lastTransientReason = null;
            $retryStopReason = 'terminal_failure';
            $transientRetryExhausted = false;
            $receipt = $this->blockedSourceReceipt(
                $sourceKey,
                $sourceIds[$sourceKey],
                'collection_child_not_started'
            );
            while (true) {
                $remainingSeconds = (int)floor($deadlineAt - $this->monotonicNow());
                $minimumAttemptWindow = self::MIN_CHILD_WINDOW_SECONDS
                    + self::CLEANUP_RESERVE_SECONDS;
                if ($remainingSeconds < $minimumAttemptWindow) {
                    if ($attemptCount === 0) {
                        $deadlineReached = true;
                        $receipt = $this->blockedSourceReceipt(
                            $sourceKey,
                            $sourceIds[$sourceKey],
                            'queue_deadline_reached'
                        );
                    }
                    $retryStopReason = $attemptCount === 0
                        ? 'queue_deadline_reached'
                        : 'queue_deadline_insufficient_for_retry';
                    break;
                }
                $effectiveTimeout = min(
                    $childTimeoutSeconds,
                    $remainingSeconds - self::CLEANUP_RESERVE_SECONDS
                );
                $attemptCount++;
                $child = [];
                try {
                    $child = $this->runChild(
                        $sourceKey,
                        $commands[$sourceKey],
                        $effectiveTimeout,
                        [
                            'tenant_id' => $tenantId,
                            'system_hotel_id' => $hotelId,
                            'owner_user_id' => $ownerUserId,
                            'data_source_id' => $sourceIds[$sourceKey],
                            'target_date' => $targetDate,
                            'run_mode' => $runMode,
                            'dispatcher_run_id' => $dispatcherRunId,
                            'profile_public_id' => $profiles[$sourceKey]['profile_public_id'],
                            'attempt_count' => $attemptCount,
                        ]
                    );
                    $receipt = $this->sanitizeChildReceipt(
                        $sourceKey,
                        $sourceIds[$sourceKey],
                        $child,
                        $dispatcherRunId,
                        $hotelId,
                        $targetDate,
                        $dataPeriod
                    );
                    if (($receipt['timed_out'] ?? false) === true) {
                        $processCleanupVerified = ($child['process_group_cleanup_verified'] ?? false) === true;
                        try {
                            $gatewayAbortVerified = $this->abortCollection(
                                $profiles[$sourceKey]['profile_public_id'],
                                $controlTokenFile
                            );
                        } catch (\Throwable) {
                            $gatewayAbortVerified = false;
                        }
                        $cleanupVerified = $processCleanupVerified && $gatewayAbortVerified;
                        $receipt['process_group_cleanup_verified'] = $processCleanupVerified;
                        $receipt['gateway_abort_verified'] = $gatewayAbortVerified;
                        $receipt['timeout_cleanup_verified'] = $cleanupVerified;
                        if (!$cleanupVerified) {
                            $gatewayCleanupVerified = false;
                            $skipRemainingReason = 'previous_timeout_cleanup_unverified';
                        }
                    }
                } catch (\Throwable $error) {
                    $receipt = $this->blockedSourceReceipt(
                        $sourceKey,
                        $sourceIds[$sourceKey],
                        $this->safeCode($error->getMessage()) ?: 'collection_child_failed'
                    );
                }

                if (($receipt['verified'] ?? false) === true) {
                    $retryStopReason = 'verified';
                    break;
                }
                $retryDecision = $this->retryDecision(
                    $receipt,
                    $attemptCount,
                    $deadlineAt
                );
                $retryStopReason = (string)$retryDecision['stop_reason'];
                if (($retryDecision['transient'] ?? false) === true) {
                    $lastTransientReason = (string)($receipt['reason'] ?? '');
                }
                if (($retryDecision['retry'] ?? false) !== true) {
                    $transientRetryExhausted = ($retryDecision['exhausted'] ?? false) === true;
                    break;
                }
                $delay = (int)$retryDecision['delay_seconds'];
                $retryDelays[] = $delay;
                $this->sleepFor($delay);
            }
            $receipt['attempt_count'] = $attemptCount;
            $receipt['retry_count'] = max(0, $attemptCount - 1);
            $receipt['retry_delays_seconds'] = $retryDelays;
            $receipt['recovered_after_retry'] = ($receipt['verified'] ?? false) === true
                && $attemptCount > 1;
            $receipt['transient_retry_exhausted'] = $transientRetryExhausted;
            $receipt['last_transient_reason'] = $lastTransientReason;
            $receipt['retry_stop_reason'] = $retryStopReason;
            if (($receipt['verified'] ?? false) !== true) {
                $allVerified = false;
            }
            if (($receipt['timed_out'] ?? false) === true) {
                $deadlineReached = $deadlineReached || $this->monotonicNow() >= $deadlineAt;
            }
            if ($runReceiptWritesVerified) {
                $runReceiptWritesVerified = $this->recordFinalSourceReceipt(
                    $dispatcherRunId,
                    $tenantId,
                    $hotelId,
                    $targetDate,
                    $pmsProvider,
                    $sourceKey,
                    $receipt
                );
            }
            $receipt['run_receipt_recorded'] = $runReceiptWritesVerified;
            if (!$runReceiptWritesVerified) {
                $allVerified = false;
                $receipt['source_result_verified_before_run_receipt'] = ($receipt['verified'] ?? false) === true;
                $receipt['verified'] = false;
                $receipt['status'] = 'blocked';
                $receipt['reason'] = 'collection_run_receipt_write_failed';
                $receipt['readback_verified'] = false;
                $skipRemainingReason = 'collection_run_receipt_write_failed';
            }
            $sourceReceipts[] = $receipt;
        }

        $finalizationReceipt = [];
        $trustedReady = false;
        if ($runReceiptWritesVerified) {
            try {
                $finalizationReceipt = $this->buildFinalizationReceipt(
                    $dispatcherRunId,
                    $hotelId,
                    $targetDate,
                    [$ctripSourceId, $meituanSourceId],
                    $sourceReceipts,
                    $dataPeriod
                );
                $finalizationBudgetSeconds = (int)floor(
                    $deadlineAt - $this->monotonicNow()
                ) - self::FINALIZATION_RECEIPT_RESERVE_SECONDS;
                if ($finalizationBudgetSeconds <= 0) {
                    $deadlineReached = true;
                    $canonicalFinalization = $this->blockedCanonicalHistoryFinalization(
                        $finalizationReceipt,
                        $tenantId,
                        $hotelId,
                        'queue_deadline_insufficient_for_canonical_finalization'
                    );
                } else {
                    $canonicalFinalization = $this->finalizeCanonicalHistory(
                        $finalizationReceipt,
                        $tenantId,
                        $hotelId,
                        $finalizationBudgetSeconds
                    );
                    if ($this->monotonicNow() >= $deadlineAt) {
                        $deadlineReached = true;
                        $canonicalFinalization['status'] =
                            (array)($canonicalFinalization['promoted_platforms'] ?? []) !== []
                                ? 'partial'
                                : 'blocked';
                        $canonicalFinalization['reason'] =
                            'queue_deadline_reached_during_canonical_finalization';
                        $canonicalFinalization['canonical_history_complete'] = false;
                    }
                }
                $policy = new ScheduledAutoFetchPolicy();
                if ($dataPeriod === 'historical_daily') {
                    $finalizationReceipt = $policy->attachAuthorityVerifier(
                        $finalizationReceipt,
                        is_array($canonicalFinalization['overall_verifier'] ?? null)
                            ? $canonicalFinalization['overall_verifier']
                            : []
                    );
                }
                $finalizationReceipt['canonical_history_finalization'] = $canonicalFinalization;
                $finalizationReceipt['canonical_history_complete'] =
                    ($canonicalFinalization['canonical_history_complete'] ?? false) === true;
                $trustedReady = $allVerified
                    && $policy->dailyTrustReceiptReady(
                        $finalizationReceipt,
                        $targetDate,
                        $hotelId,
                        [$ctripSourceId, $meituanSourceId],
                        ['ctrip', 'meituan']
                    )
                    && $this->canonicalHistoryFinalizationVerified(
                        $canonicalFinalization,
                        $tenantId,
                        $hotelId,
                        $targetDate,
                        $finalizationReceipt
                    );
                $finalReceiptBudgetSeconds = (int)floor(
                    $deadlineAt - $this->monotonicNow()
                );
                if ($finalReceiptBudgetSeconds <= 0) {
                    $deadlineReached = true;
                    $trustedReady = false;
                    $finalReceiptBudgetSeconds = 1;
                }
                $finalRunReceipt = $this->writeRunReceipt('finalize', [
                    'dispatcher_run_id' => $dispatcherRunId,
                    'tenant_id' => $tenantId,
                    'system_hotel_id' => $hotelId,
                    'business_date' => $targetDate,
                    'receipt' => $finalizationReceipt,
                    'trusted_ready' => $trustedReady,
                    'timeout_seconds' => $finalReceiptBudgetSeconds,
                ]);
                if ($this->monotonicNow() >= $deadlineAt) {
                    $deadlineReached = true;
                    $trustedReady = false;
                }
                $runReceiptWritesVerified = $this->finalizedRunReceiptVerified(
                    $finalRunReceipt,
                    $dispatcherRunId,
                    $tenantId,
                    $hotelId,
                    $targetDate,
                    $pmsProvider,
                    $sourceReceipts,
                    $finalizationReceipt,
                    $runMode,
                    $trustedReady
                );
            } catch (\Throwable) {
                $runReceiptWritesVerified = false;
            }
        }
        if (!$runReceiptWritesVerified || !$trustedReady) {
            $allVerified = false;
        }

        return [
            'status' => $allVerified
                ? 'all_sources_saved_and_readback_verified'
                : 'partial_or_blocked',
            'plan_id' => $planId,
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'target_date' => $targetDate,
            'dispatcher_run_id' => $dispatcherRunId,
            'execution_mode' => 'serial',
            'source_order' => self::SOURCE_ORDER,
            'retry_policy' => $this->retryPolicy(),
            'sources' => $sourceReceipts,
            'deadline_reached' => $deadlineReached,
            'gateway_cleanup_verified' => $gatewayCleanupVerified,
            'run_receipt_structure_verified' => $runReceiptWritesVerified,
            'run_receipt_status' => $runReceiptWritesVerified
                ? (string)($finalRunReceipt['status'] ?? 'started')
                : 'unverified',
            'run_receipt_readback_verified' => $runReceiptWritesVerified
                && ($finalRunReceipt['readback_verified'] ?? false) === true,
            'run_receipt_finalized' => $runReceiptWritesVerified
                && !in_array((string)($finalRunReceipt['status'] ?? ''), ['started', 'in_progress', 'collected'], true),
            'collection_anchor_hash' => $trustedReady
                ? (string)($finalRunReceipt['collection_anchor_hash'] ?? '')
                : null,
            'trust_receipt_digest' => $trustedReady
                ? (string)($finalRunReceipt['trust_receipt_digest'] ?? '')
                : null,
            'canonical_history_complete' => $trustedReady
                && ($finalizationReceipt['canonical_history_complete'] ?? false) === true,
            'canonical_history_finalization_reason' => $this->safeCode(
                (string)($canonicalFinalization['reason'] ?? '')
            ),
            'message_sent' => false,
            'sensitive_values_exposed' => false,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function loadPlans(): array
    {
        if ($this->planLoader !== null) {
            $rows = call_user_func($this->planLoader);
            return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
        }
        return Db::name('hotel_collection_plans')
            ->field('id,tenant_id,system_hotel_id,plan_version,plan_status,enabled,active_slot,business_date_policy,timezone,schedule_time,retry_interval_minutes,max_attempts,execution_owner_user_id,binding_digest,plan_hash,source_plan_json,validation_status')
            ->where('enabled', 1)
            ->where('active_slot', 1)
            ->where('plan_status', 'active')
            ->where('validation_status', 'ready')
            ->order('tenant_id,system_hotel_id,id')
            ->select()
            ->toArray();
    }

    /** @param array<string,mixed> $plan */
    private function eligiblePlan(array $plan): bool
    {
        return (int)($plan['enabled'] ?? 0) === 1
            && (int)($plan['active_slot'] ?? 0) === 1
            && strtolower(trim((string)($plan['plan_status'] ?? ''))) === 'active'
            && strtolower(trim((string)($plan['validation_status'] ?? ''))) === 'ready';
    }

    /** @param array<string,mixed> $attempt */
    private function planAttemptRetryAllowed(array $attempt): bool
    {
        $pmsStatus = strtolower(trim((string)($attempt['pms_status'] ?? '')));
        $parentReceipt = $this->decodeArray($attempt['receipt_json'] ?? null);
        $pmsAttempt = is_array($parentReceipt['pms_attempt'] ?? null)
            ? $parentReceipt['pms_attempt']
            : [];
        if ($pmsStatus !== 'failed'
            || ($pmsAttempt['business_data_persisted'] ?? null) !== false
            || !in_array(
                $this->safeCode((string)($pmsAttempt['reason_code'] ?? '')),
                self::TRANSIENT_FAILURE_CODES,
                true
            )
        ) {
            return false;
        }
        $sources = array_values(array_filter(
            is_array($attempt['source_receipts'] ?? null) ? $attempt['source_receipts'] : [],
            'is_array'
        ));
        if (count($sources) !== 2) {
            return false;
        }
        $platforms = [];
        foreach ($sources as $source) {
            $platform = strtolower(trim((string)($source['platform'] ?? '')));
            $receipt = $this->decodeArray($source['receipt_json'] ?? null);
            if (!in_array($platform, ['ctrip', 'meituan'], true)
                || strtolower(trim((string)($source['status'] ?? ''))) !== 'failed'
                || (int)($source['saved_row_count'] ?? 0) !== 0
                || (int)($source['readback_row_count'] ?? 0) !== 0
                || (int)($source['readback_verified'] ?? 0) !== 0
                || ($receipt['business_data_persisted'] ?? null) !== false
                || !in_array(
                    $this->safeCode((string)($source['failure_code'] ?? $receipt['failure_code'] ?? '')),
                    self::TRANSIENT_FAILURE_CODES,
                    true
                )
            ) {
                return false;
            }
            $platforms[$platform] = true;
        }
        $keys = array_keys($platforms);
        sort($keys, SORT_STRING);
        return $keys === ['ctrip', 'meituan'];
    }

    /** @param array<string,mixed> $plan @return array{status:string,reason:?string,attempt_number:int} */
    private function executionDue(
        array $plan,
        string $targetDate,
        string $runMode,
        DateTimeImmutable $now
    ): array {
        $planId = (int)($plan['id'] ?? 0);
        $planVersion = max(0, (int)($plan['plan_version'] ?? 0));
        $tenantId = (int)($plan['tenant_id'] ?? 0);
        $hotelId = (int)($plan['system_hotel_id'] ?? 0);
        $timezone = trim((string)($plan['timezone'] ?? self::TIMEZONE));
        $scheduleTime = trim((string)($plan['schedule_time'] ?? ''));
        $retryMinutes = max(1, min(1440, (int)($plan['retry_interval_minutes'] ?? 14)));
        $maxAttempts = max(1, min(50, (int)($plan['max_attempts'] ?? 7)));
        if ($planId <= 0 || $tenantId <= 0 || $hotelId <= 0
            || $timezone !== self::TIMEZONE
            || preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $scheduleTime) !== 1
            || !in_array($runMode, ['daily', 'realtime'], true)
        ) {
            return ['status' => 'blocked', 'reason' => 'collection_plan_schedule_invalid', 'attempt_number' => 0];
        }
        $scheduleAt = new DateTimeImmutable(
            $now->setTimezone(new DateTimeZone(self::TIMEZONE))->format('Y-m-d') . ' ' . $scheduleTime . ':00',
            new DateTimeZone(self::TIMEZONE)
        );
        if ($now < $scheduleAt) {
            return ['status' => 'not_due', 'reason' => 'collection_plan_schedule_not_due', 'attempt_number' => 0];
        }
        try {
            if ($this->attemptStateLoader !== null) {
                $loaded = call_user_func(
                    $this->attemptStateLoader,
                    $plan,
                    $targetDate,
                    $runMode,
                    $now
                );
                if (!is_array($loaded)) {
                    throw new RuntimeException('collection_plan_attempt_state_invalid');
                }
                $attempts = array_values(array_filter($loaded, 'is_array'));
            } else {
                $attemptQuery = Db::name('hotel_collection_plan_runs')
                ->field('id,status,failure_code,pms_status,pms_readback_verified,receipt_json,started_at,finished_at,update_time')
                ->where('tenant_id', $tenantId)
                ->where('system_hotel_id', $hotelId)
                ->where('business_date', $targetDate)
                ->where('run_mode', $runMode)
                ->where('plan_id', $planId)
                ->where('plan_version', $planVersion);
                if ($runMode === 'realtime') {
                    $attemptQuery->where(
                        'started_at',
                        '>=',
                        $now->setTime((int)$now->format('H'), 0)->format('Y-m-d H:i:s')
                    );
                }
                $attempts = $attemptQuery
                    ->order('id', 'desc')
                    ->limit($maxAttempts + 1)
                    ->select()
                    ->toArray();
                foreach ($attempts as &$attempt) {
                    if (!is_array($attempt) || (int)($attempt['id'] ?? 0) <= 0) continue;
                    $attempt['source_receipts'] = Db::name('hotel_collection_plan_run_sources')
                        ->field('platform,status,saved_row_count,readback_row_count,readback_verified,failure_code,receipt_json')
                        ->where('run_id', (int)$attempt['id'])
                        ->order('platform', 'asc')
                        ->select()
                        ->toArray();
                }
                unset($attempt);
            }
        } catch (\Throwable) {
            return ['status' => 'blocked', 'reason' => 'collection_plan_attempt_state_unavailable', 'attempt_number' => 0];
        }
        foreach ($attempts as $attempt) {
            if (is_array($attempt) && (string)($attempt['status'] ?? '') === 'succeeded') {
                return ['status' => 'not_due', 'reason' => 'collection_plan_target_already_succeeded', 'attempt_number' => count($attempts)];
            }
        }
        $attemptCount = count(array_filter($attempts, 'is_array'));
        $latest = is_array($attempts[0] ?? null) ? $attempts[0] : [];
        $latestAt = $this->timestamp((string)(
            $latest['finished_at'] ?? $latest['update_time'] ?? $latest['started_at'] ?? ''
        ));
        if (in_array((string)($latest['status'] ?? ''), ['started', 'in_progress', 'collected'], true)
            && $latestAt instanceof DateTimeImmutable
            && $latestAt > $now->modify('-35 minutes')
        ) {
            return ['status' => 'not_due', 'reason' => 'collection_plan_attempt_in_progress', 'attempt_number' => $attemptCount];
        }
        if ($attemptCount > 0) {
            foreach ($attempts as $attempt) {
                if (!is_array($attempt) || !$this->planAttemptRetryAllowed($attempt)) {
                    return [
                        'status' => 'not_due',
                        'reason' => 'collection_plan_manual_recovery_required',
                        'attempt_number' => $attemptCount,
                    ];
                }
            }
        }
        if ($attemptCount >= $maxAttempts) {
            return ['status' => 'not_due', 'reason' => 'collection_plan_attempts_exhausted', 'attempt_number' => $attemptCount];
        }
        if ($attemptCount > 0
            && $latestAt instanceof DateTimeImmutable
            && $latestAt->modify('+' . $retryMinutes . ' minutes') > $now
        ) {
            return ['status' => 'not_due', 'reason' => 'collection_plan_retry_not_due', 'attempt_number' => $attemptCount + 1];
        }
        return ['status' => 'ready', 'reason' => null, 'attempt_number' => $attemptCount + 1];
    }

    /** @return array<string,mixed> */
    private function loadHotel(int $tenantId, int $hotelId): array
    {
        if ($this->hotelLoader !== null) {
            $row = call_user_func($this->hotelLoader, $tenantId, $hotelId);
            return is_array($row) ? $row : [];
        }
        $row = Db::name('hotels')
            ->field('id,tenant_id,name,status')
            ->where('id', $hotelId)
            ->where('tenant_id', $tenantId)
            ->find();
        return is_array($row) ? $row : [];
    }

    /** @return array<string,mixed> */
    private function authorize(
        array $hotel,
        string $targetDate,
        array $sourceIds,
        array $platforms,
        string $runMode
    ): array {
        if ($this->authorizationLoader !== null) {
            $receipt = call_user_func(
                $this->authorizationLoader,
                $hotel,
                $targetDate,
                $sourceIds,
                $platforms,
                $runMode
            );
            return is_array($receipt) ? $receipt : [];
        }
        return (new HotelCollectionPlanService())->authorizeExecutionScope(
            $hotel,
            $targetDate,
            $sourceIds,
            $platforms,
            $runMode
        );
    }

    /** @return array<int,array<string,mixed>> */
    private function loadProfiles(int $tenantId, int $hotelId, int $ownerUserId): array
    {
        if ($this->profileLoader !== null) {
            $rows = call_user_func($this->profileLoader, $tenantId, $hotelId, $ownerUserId);
            return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
        }
        return Db::name('cloud_browser_profiles')
            ->field('id,tenant_id,system_hotel_id,owner_user_id,platform,profile_public_id,authorization_status,ready_at,session_expires_at')
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('owner_user_id', $ownerUserId)
            ->whereIn('platform', array_values(array_unique(array_merge(
                array_values(self::OTA_PROFILE_PLATFORM),
                array_values(self::PMS_PROFILE_PLATFORM)
            ))))
            ->order('platform,id')
            ->select()
            ->toArray();
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,array<string,mixed>>
     */
    private function resolveProfiles(
        array $rows,
        int $tenantId,
        int $hotelId,
        int $ownerUserId,
        string $pmsProfilePlatform,
        DateTimeImmutable $now
    ): array {
        $resolved = [];
        foreach (['pms' => $pmsProfilePlatform] + self::OTA_PROFILE_PLATFORM as $sourceKey => $platform) {
            $candidates = array_values(array_filter(
                $rows,
                static fn(array $row): bool => (int)($row['tenant_id'] ?? 0) === $tenantId
                    && (int)($row['system_hotel_id'] ?? 0) === $hotelId
                    && (int)($row['owner_user_id'] ?? 0) === $ownerUserId
                    && strtolower(trim((string)($row['platform'] ?? ''))) === $platform
            ));
            if (count($candidates) !== 1) {
                throw new RuntimeException(count($candidates) === 0
                    ? 'cloud_profile_binding_missing_' . $platform
                    : 'cloud_profile_binding_conflict_' . $platform);
            }
            $profile = $candidates[0];
            $publicId = trim((string)($profile['profile_public_id'] ?? ''));
            if (preg_match('/^cbp_[A-Za-z0-9_-]{16,64}$/D', $publicId) !== 1) {
                throw new RuntimeException('cloud_profile_identity_invalid_' . $platform);
            }
            if (strtolower(trim((string)($profile['authorization_status'] ?? '')))
                !== CloudBrowserProfileService::READY_TO_COLLECT
            ) {
                throw new RuntimeException('cloud_profile_not_ready_' . $platform);
            }
            $readyAt = $this->timestamp((string)($profile['ready_at'] ?? ''));
            $sessionExpiresAt = $this->timestamp((string)($profile['session_expires_at'] ?? ''));
            if (!$readyAt instanceof DateTimeImmutable || $readyAt > $now) {
                throw new RuntimeException('cloud_profile_ready_evidence_invalid_' . $platform);
            }
            if (!$sessionExpiresAt instanceof DateTimeImmutable || $sessionExpiresAt <= $now) {
                throw new RuntimeException('cloud_profile_session_expired_' . $platform);
            }
            $resolved[$sourceKey] = [
                'profile_public_id' => $publicId,
                'profile_public_id_digest' => hash('sha256', $publicId),
            ];
        }
        return $resolved;
    }

    /** @param array<int,string> $command @param array<string,mixed> $context @return array<string,mixed> */
    private function runChild(
        string $sourceKey,
        array $command,
        int $timeoutSeconds,
        array $context
    ): array {
        if ($this->childRunner !== null) {
            $result = call_user_func(
                $this->childRunner,
                $sourceKey,
                $command,
                $timeoutSeconds,
                $context
            );
            return is_array($result) ? $result : [];
        }
        return $this->runChildProcess($command, $timeoutSeconds);
    }

    /** @param array<int,string> $command @return array<string,mixed> */
    private function runChildProcess(array $command, int $timeoutSeconds): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        if (PHP_OS_FAMILY !== 'Linux'
            || !is_executable(self::SETSID_BINARY)
            || !function_exists('posix_kill')
        ) {
            return [
                'exit_code' => 1,
                'timed_out' => false,
                'process_group_cleanup_verified' => false,
                'receipt' => ['reason' => 'collection_process_group_runtime_unavailable'],
            ];
        }
        $isolatedCommand = [self::SETSID_BINARY, '--wait', ...$command];
        $process = proc_open(
            $isolatedCommand,
            $descriptors,
            $pipes,
            $this->applicationRoot,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            return ['exit_code' => 1, 'timed_out' => false, 'receipt' => []];
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $outputTail = '';
        $startedAt = $this->monotonicNow();
        $timedOut = false;
        $processGroupCleanupVerified = true;
        $observedExitCode = null;
        $initialStatus = proc_get_status($process);
        $processGroupId = max(0, (int)($initialStatus['pid'] ?? 0));
        while (true) {
            foreach ([1, 2] as $index) {
                $chunk = stream_get_contents($pipes[$index]);
                if (is_string($chunk) && $chunk !== '') {
                    $outputTail = $this->appendTail($outputTail, $chunk);
                }
            }
            $status = proc_get_status($process);
            if (($status['running'] ?? false) !== true) {
                $observedExitCode = (int)($status['exitcode'] ?? 1);
                break;
            }
            if ($this->monotonicNow() - $startedAt >= $timeoutSeconds) {
                $timedOut = true;
                $processGroupCleanupVerified = $processGroupId > 0
                    && @posix_kill(-$processGroupId, 15);
                $graceDeadline = $this->monotonicNow() + 2.0;
                do {
                    usleep(100_000);
                    $status = proc_get_status($process);
                } while ($this->processGroupExists($processGroupId)
                    && $this->monotonicNow() < $graceDeadline);
                if ($this->processGroupExists($processGroupId)) {
                    @posix_kill(-$processGroupId, 9);
                    $killDeadline = $this->monotonicNow() + 2.0;
                    while ($this->processGroupExists($processGroupId)
                        && $this->monotonicNow() < $killDeadline
                    ) {
                        usleep(100_000);
                    }
                }
                break;
            }
            usleep(100_000);
        }
        foreach ([1, 2] as $index) {
            $chunk = stream_get_contents($pipes[$index]);
            if (is_string($chunk) && $chunk !== '') {
                $outputTail = $this->appendTail($outputTail, $chunk);
            }
            fclose($pipes[$index]);
        }
        $closedExitCode = proc_close($process);
        if ($timedOut) {
            // Reap the setsid group leader before the final group liveness check;
            // an unreaped zombie can otherwise make kill(-pgid, 0) look active.
            $processGroupCleanupVerified = !$this->processGroupExists($processGroupId);
        }
        $exitCode = $timedOut
            ? 124
            : ($observedExitCode !== null && $observedExitCode >= 0
                ? $observedExitCode
                : max(0, (int)$closedExitCode));
        return [
            'exit_code' => $exitCode,
            'timed_out' => $timedOut,
            'process_group_cleanup_verified' => $processGroupCleanupVerified,
            'receipt' => $this->lastJsonObject($outputTail),
        ];
    }

    private function processGroupExists(int $processGroupId): bool
    {
        return $processGroupId > 0
            && function_exists('posix_kill')
            && @posix_kill(-$processGroupId, 0);
    }

    private function abortCollection(string $profilePublicId, string $controlTokenFile): bool
    {
        if ($this->collectionAborter !== null) {
            return call_user_func($this->collectionAborter, $profilePublicId, $controlTokenFile) === true;
        }
        if (!in_array($controlTokenFile, self::CONTROL_TOKEN_FILES, true)
            || preg_match('/^cbp_[A-Za-z0-9_-]{16,64}$/D', $profilePublicId) !== 1
            || !is_readable($controlTokenFile)
        ) {
            return false;
        }
        $token = trim((string)file_get_contents($controlTokenFile));
        if ($token === '' || strlen($token) > 4096) {
            return false;
        }
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$token}\r\n",
            'content' => json_encode([
                'profile_public_id' => $profilePublicId,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'timeout' => 10,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents(self::GATEWAY_URL . '/v1/collection/abort', false, $context);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($decoded)
            && ($decoded['cleanup_verified'] ?? false) === true
            && in_array((string)($decoded['status'] ?? ''), ['aborted', 'no_active_collection'], true);
    }

    /** @param array<string,mixed> $child @return array<string,mixed> */
    private function sanitizeChildReceipt(
        string $sourceKey,
        int $sourceId,
        array $child,
        string $dispatcherRunId,
        int $hotelId,
        string $targetDate,
        string $expectedDataPeriod
    ): array
    {
        $receipt = is_array($child['receipt'] ?? null) ? $child['receipt'] : [];
        $exitCode = (int)($child['exit_code'] ?? 1);
        $timedOut = ($child['timed_out'] ?? false) === true;
        $status = $this->safeCode((string)($receipt['status'] ?? 'blocked')) ?: 'blocked';
        $reason = $timedOut
            ? 'collection_child_timeout'
            : ($this->safeCode((string)($receipt['reason'] ?? '')) ?: null);
        $messagePolicyViolated = ($receipt['message_sent'] ?? false) === true;
        $sensitivePolicyViolated = ($receipt['sensitive_values_exposed'] ?? false) === true;
        $businessDataPersisted = array_key_exists('business_data_persisted', $receipt)
            ? (($receipt['business_data_persisted'] ?? false) === true)
            : null;
        $savedCount = max(0, (int)($receipt['saved_count'] ?? 0));
        $readbackCount = max(0, (int)($receipt['readback_count'] ?? 0));
        $captureId = null;
        $taskId = null;
        $runReadback = [];
        $receiptDataPeriod = strtolower(trim((string)($receipt['data_period'] ?? '')));
        $historicalCoreStatus = strtolower(trim((string)(
            $receipt['historical_core_contract_status']
                ?? ($expectedDataPeriod === 'historical_daily' ? 'blocked' : 'not_required')
        )));
        if ($sourceKey === 'pms') {
            $rawCaptureId = max(0, (int)($receipt['capture_id'] ?? 0));
            $captureId = $rawCaptureId > 0 ? $rawCaptureId : null;
            $savedCount = $captureId !== null ? 1 : 0;
            $readbackCount = ($receipt['readback_status'] ?? '') === 'readback_verified' ? $savedCount : 0;
            $verified = !$timedOut
                && !$messagePolicyViolated
                && $exitCode === 0
                && $status === 'saved_and_readback_verified'
                && (string)($receipt['identity_status'] ?? '') === 'matched'
                && (string)($receipt['readback_status'] ?? '') === 'readback_verified'
                && $receiptDataPeriod === $expectedDataPeriod
                && ($receipt['push_orchestration']['disabled_by_invocation'] ?? false) === true
                && $savedCount === 1
                && $readbackCount === 1;
        } else {
            $rawTaskId = max(0, (int)($receipt['task_id'] ?? 0));
            $taskId = $rawTaskId > 0 ? $rawTaskId : null;
            $runReadback = $this->sanitizeRunReadback(
                is_array($receipt['run_readback'] ?? null) ? $receipt['run_readback'] : [],
                $expectedDataPeriod
            );
            $runReadbackMatches = $runReadback !== []
                && (string)($runReadback['dispatcher_run_id'] ?? '') === $dispatcherRunId
                && (int)($runReadback['sync_task_id'] ?? 0) === (int)$taskId
                && (int)($runReadback['data_source_id'] ?? 0) === $sourceId
                && (int)($runReadback['system_hotel_id'] ?? 0) === $hotelId
                && (string)($runReadback['platform'] ?? '') === $sourceKey
                && (string)($runReadback['target_date'] ?? '') === $targetDate
                && (string)($runReadback['data_period'] ?? '') === $expectedDataPeriod
                && (array)($runReadback['source_trace_ids'] ?? []) !== []
                && (int)($runReadback['readback_count'] ?? 0) === $readbackCount
                && count((array)($runReadback['row_ids'] ?? [])) === $readbackCount
                && ($runReadback['readback_verified'] ?? false) === true
                && (string)($runReadback['p0_status'] ?? '') === 'ready'
                && (string)($runReadback['field_fact_status'] ?? '') === 'ready'
                && (string)($runReadback['page_field_fact_status'] ?? '') === 'ready'
                && (string)($runReadback['platform_hotel_identifier_status'] ?? '') === 'ready'
                && (array)($runReadback['missing_traffic_metric_keys'] ?? []) === [];
            $coreContractMatches = $expectedDataPeriod === 'historical_daily'
                ? $historicalCoreStatus === 'ready'
                : $historicalCoreStatus === 'not_required';
            $verified = !$timedOut
                && !$messagePolicyViolated
                && $exitCode === 0
                && $status === 'saved_and_readback_verified'
                && $taskId !== null
                && $runReadbackMatches
                && $receiptDataPeriod === $expectedDataPeriod
                && $coreContractMatches
                && ($receipt['business_data_persisted'] ?? false) === true
                && ($receipt['readback_verified'] ?? false) === true
                && ($receipt['gateway_receipt_readback_verified'] ?? false) === true
                && $savedCount > 0
                && $savedCount === $readbackCount;
        }
        if ($messagePolicyViolated) {
            $reason = 'message_send_policy_violated';
        }
        if ($sensitivePolicyViolated) {
            $reason = 'sensitive_value_policy_violated';
            $verified = false;
        }
        return [
            'source' => $sourceKey,
            'data_source_id' => $sourceId > 0 ? $sourceId : null,
            'status' => $verified ? 'saved_and_readback_verified' : 'blocked',
            'reason' => $verified ? null : ($reason ?: 'collection_child_unverified'),
            'exit_code' => $exitCode,
            'timed_out' => $timedOut,
            'saved_count' => $savedCount,
            'readback_count' => $readbackCount,
            'capture_id' => $captureId,
            'task_id' => $taskId,
            'run_readback' => $runReadback,
            'data_period' => $receiptDataPeriod !== '' ? $receiptDataPeriod : null,
            'historical_core_contract_status' => $historicalCoreStatus,
            'readback_verified' => $verified,
            'gateway_receipt_readback_verified' => $sourceKey === 'pms'
                ? null
                : (($receipt['gateway_receipt_readback_verified'] ?? false) === true),
            'verified' => $verified,
            'business_data_persisted' => $verified ? true : $businessDataPersisted,
            'message_sent' => false,
            'sensitive_values_exposed' => false,
        ];
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    private function writeRunReceipt(string $action, array $context): array
    {
        if ($this->runReceiptWriter !== null) {
            $result = call_user_func($this->runReceiptWriter, $action, $context);
            if (!is_array($result)) {
                throw new RuntimeException('collection_run_receipt_writer_invalid');
            }
            return $result;
        }

        $service = new HotelCollectionRunReceiptService();
        return match ($action) {
            'begin' => $service->begin((array)($context['gate'] ?? [])),
            'pms_success' => $service->recordPmsCapture(
                (string)($context['dispatcher_run_id'] ?? ''),
                (int)($context['system_hotel_id'] ?? 0),
                (string)($context['business_date'] ?? ''),
                (string)($context['provider'] ?? ''),
                (int)($context['capture_id'] ?? 0)
            ),
            'pms_failure' => $service->recordPmsFailure(
                (string)($context['dispatcher_run_id'] ?? ''),
                (int)($context['system_hotel_id'] ?? 0),
                (string)($context['business_date'] ?? ''),
                (string)($context['provider'] ?? ''),
                (array)($context['outcome'] ?? [])
            ),
            'platform_results' => $service->recordPlatformResults(
                (string)($context['dispatcher_run_id'] ?? ''),
                (int)($context['system_hotel_id'] ?? 0),
                (string)($context['business_date'] ?? ''),
                (array)($context['results'] ?? [])
            ),
            'finalize' => $service->finalizeCollection(
                (string)($context['dispatcher_run_id'] ?? ''),
                (int)($context['system_hotel_id'] ?? 0),
                (string)($context['business_date'] ?? ''),
                (array)($context['receipt'] ?? []),
                ($context['trusted_ready'] ?? false) === true,
                max(0, (int)($context['timeout_seconds'] ?? 0))
            ),
            'read' => $service->readGroup(
                (string)($context['dispatcher_run_id'] ?? ''),
                (int)($context['tenant_id'] ?? 0),
                (int)($context['system_hotel_id'] ?? 0),
                (string)($context['business_date'] ?? '')
            ),
            default => throw new RuntimeException('collection_run_receipt_action_invalid'),
        };
    }

    /** @param array<int,int> $expectedSourceIds */
    private function beginRunReceiptVerified(
        array $receipt,
        string $dispatcherRunId,
        int $tenantId,
        int $hotelId,
        string $businessDate,
        string $expectedRunMode,
        array $expectedSourceIds,
        string $expectedStatus = 'started'
    ): bool {
        $sourceIds = array_values(array_unique(array_filter(array_map(
            static fn(array $row): int => max(0, (int)($row['data_source_id'] ?? 0)),
            is_array($receipt['source_receipts'] ?? null) ? $receipt['source_receipts'] : []
        ))));
        sort($sourceIds, SORT_NUMERIC);
        sort($expectedSourceIds, SORT_NUMERIC);
        return (string)($receipt['dispatcher_run_id'] ?? '') === $dispatcherRunId
            && (int)($receipt['tenant_id'] ?? 0) === $tenantId
            && (int)($receipt['system_hotel_id'] ?? 0) === $hotelId
            && (string)($receipt['business_date'] ?? '') === $businessDate
            && (string)($receipt['run_mode'] ?? '') === $expectedRunMode
            && (string)($receipt['status'] ?? '') === $expectedStatus
            && ($receipt['ledger_structure_verified'] ?? false) === true
            && $sourceIds === $expectedSourceIds;
    }

    private function recordPreflightFailure(
        string $dispatcherRunId,
        int $tenantId,
        int $hotelId,
        string $businessDate,
        string $pmsProvider,
        int $ctripSourceId,
        int $meituanSourceId,
        string $runMode,
        string $reason
    ): bool {
        $pmsReceipt = $this->blockedSourceReceipt('pms', 0, $reason);
        $pmsReceipt['business_data_persisted'] = false;
        $ctripReceipt = $this->blockedSourceReceipt('ctrip', $ctripSourceId, $reason);
        $ctripReceipt['business_data_persisted'] = false;
        $meituanReceipt = $this->blockedSourceReceipt('meituan', $meituanSourceId, $reason);
        $meituanReceipt['business_data_persisted'] = false;
        try {
            if (!$this->recordFinalSourceReceipt(
                $dispatcherRunId,
                $tenantId,
                $hotelId,
                $businessDate,
                $pmsProvider,
                'pms',
                $pmsReceipt
            )) {
                return false;
            }
            $result = $this->writeRunReceipt('platform_results', [
                'dispatcher_run_id' => $dispatcherRunId,
                'system_hotel_id' => $hotelId,
                'business_date' => $businessDate,
                'results' => [
                    $this->platformRunResult('ctrip', $ctripSourceId, $dispatcherRunId, $hotelId, $businessDate, $ctripReceipt),
                    $this->platformRunResult('meituan', $meituanSourceId, $dispatcherRunId, $hotelId, $businessDate, $meituanReceipt),
                ],
            ]);
            if (!$this->platformReceiptRecorded($result, 'ctrip', $ctripSourceId, false)
                || !$this->platformReceiptRecorded($result, 'meituan', $meituanSourceId, false)
            ) {
                return false;
            }
            $final = $this->writeRunReceipt('read', [
                'dispatcher_run_id' => $dispatcherRunId,
                'tenant_id' => $tenantId,
                'system_hotel_id' => $hotelId,
                'business_date' => $businessDate,
            ]);
            return $this->finalRunReceiptVerified(
                $final,
                $dispatcherRunId,
                $tenantId,
                $hotelId,
                $businessDate,
                $pmsProvider,
                [$pmsReceipt, $ctripReceipt, $meituanReceipt],
                $runMode
            );
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<string,mixed> $receipt */
    private function recordFinalSourceReceipt(
        string $dispatcherRunId,
        int $tenantId,
        int $hotelId,
        string $businessDate,
        string $pmsProvider,
        string $sourceKey,
        array $receipt
    ): bool {
        try {
            if ($sourceKey === 'pms') {
                $verified = ($receipt['verified'] ?? false) === true;
                $result = $this->writeRunReceipt($verified ? 'pms_success' : 'pms_failure', [
                    'dispatcher_run_id' => $dispatcherRunId,
                    'tenant_id' => $tenantId,
                    'system_hotel_id' => $hotelId,
                    'business_date' => $businessDate,
                    'provider' => $pmsProvider,
                    'capture_id' => (int)($receipt['capture_id'] ?? 0),
                    'outcome' => $receipt,
                ]);
                $pms = is_array($result['pms_receipt'] ?? null)
                    ? $result['pms_receipt']
                    : [];
                if ((string)($pms['provider'] ?? '') !== $pmsProvider) {
                    return false;
                }
                return $verified
                    ? (string)($pms['status'] ?? '') === 'verified'
                        && (int)($pms['capture_id'] ?? 0) === (int)($receipt['capture_id'] ?? 0)
                        && ($pms['readback_verified'] ?? false) === true
                    : (string)($pms['status'] ?? '') === 'failed'
                        && (string)($pms['reason_code'] ?? '') === (string)($receipt['reason'] ?? '')
                        && ($pms['readback_verified'] ?? true) === false;
            }

            $sourceId = (int)($receipt['data_source_id'] ?? 0);
            $verified = ($receipt['verified'] ?? false) === true;
            $result = $this->writeRunReceipt('platform_results', [
                'dispatcher_run_id' => $dispatcherRunId,
                'system_hotel_id' => $hotelId,
                'business_date' => $businessDate,
                'results' => [
                    $this->platformRunResult(
                        $sourceKey,
                        $sourceId,
                        $dispatcherRunId,
                        $hotelId,
                        $businessDate,
                        $receipt
                    ),
                ],
            ]);
            return $this->platformReceiptRecorded($result, $sourceKey, $sourceId, $verified);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string,mixed> */
    private function platformRunResult(
        string $platform,
        int $sourceId,
        string $dispatcherRunId,
        int $hotelId,
        string $businessDate,
        array $receipt
    ): array {
        $verified = ($receipt['verified'] ?? false) === true;
        return [
            'platform' => $platform,
            'success' => $verified,
            'status' => $verified ? 'success' : 'failed',
            'source_task_status' => $verified ? 'success' : 'failed',
            'ingestion_method' => 'browser_profile',
            'historical_core_contract_status' => strtolower(trim((string)(
                $receipt['historical_core_contract_status'] ?? ($verified ? 'not_required' : 'blocked')
            ))),
            'dispatcher_run_id' => $dispatcherRunId,
            'system_hotel_id' => $hotelId,
            'target_date' => $businessDate,
            'data_source_id' => $sourceId,
            'task_id' => max(0, (int)($receipt['task_id'] ?? 0)),
            'saved_count' => max(0, (int)($receipt['saved_count'] ?? 0)),
            'readback_count' => max(0, (int)($receipt['readback_count'] ?? 0)),
            'readback_verified' => $verified && ($receipt['readback_verified'] ?? false) === true,
            'business_data_persisted' => array_key_exists('business_data_persisted', $receipt)
                ? (($receipt['business_data_persisted'] ?? null) === true
                    ? true
                    : (($receipt['business_data_persisted'] ?? null) === false ? false : null))
                : null,
            'run_readback' => is_array($receipt['run_readback'] ?? null)
                ? $receipt['run_readback']
                : [],
            'failure_reason' => $verified ? '' : (string)($receipt['reason'] ?? 'collection_child_unverified'),
        ];
    }

    private function platformReceiptRecorded(
        array $receipt,
        string $platform,
        int $sourceId,
        bool $verified
    ): bool {
        $matched = null;
        foreach (is_array($receipt['source_receipts'] ?? null) ? $receipt['source_receipts'] : [] as $row) {
            if (is_array($row) && (string)($row['platform'] ?? '') === $platform) {
                $matched = $row;
                break;
            }
        }
        if (!is_array($matched) || (int)($matched['data_source_id'] ?? 0) !== $sourceId) {
            return false;
        }
        return $verified
            ? (string)($matched['status'] ?? '') === 'success'
                && ($matched['readback_verified'] ?? false) === true
                && (int)($matched['platform_sync_task_id'] ?? 0) > 0
            : in_array((string)($matched['status'] ?? ''), ['failed', 'partial'], true)
                && ($matched['readback_verified'] ?? true) === false;
    }

    /** @param array<int,array<string,mixed>> $sourceReceipts */
    private function finalRunReceiptVerified(
        array $receipt,
        string $dispatcherRunId,
        int $tenantId,
        int $hotelId,
        string $businessDate,
        string $pmsProvider,
        array $sourceReceipts,
        string $expectedRunMode
    ): bool {
        if ((string)($receipt['dispatcher_run_id'] ?? '') !== $dispatcherRunId
            || (int)($receipt['tenant_id'] ?? 0) !== $tenantId
            || (int)($receipt['system_hotel_id'] ?? 0) !== $hotelId
            || (string)($receipt['business_date'] ?? '') !== $businessDate
            || (string)($receipt['run_mode'] ?? '') !== $expectedRunMode
            || ($receipt['ledger_structure_verified'] ?? false) !== true
        ) {
            return false;
        }
        $expectedPms = null;
        $expectedPlatforms = [];
        foreach ($sourceReceipts as $sourceReceipt) {
            $sourceKey = (string)($sourceReceipt['source'] ?? '');
            if ($sourceKey === 'pms') {
                $expectedPms = $sourceReceipt;
            } elseif (in_array($sourceKey, ['ctrip', 'meituan'], true)) {
                $expectedPlatforms[$sourceKey] = $sourceReceipt;
            }
        }
        if (!is_array($expectedPms) || count($expectedPlatforms) !== 2) {
            return false;
        }
        $pms = is_array($receipt['pms_receipt'] ?? null) ? $receipt['pms_receipt'] : [];
        if ((string)($pms['provider'] ?? '') !== $pmsProvider) {
            return false;
        }
        if (($expectedPms['verified'] ?? false) === true) {
            if ((string)($pms['status'] ?? '') !== 'verified'
                || ($pms['readback_verified'] ?? false) !== true
            ) {
                return false;
            }
        } elseif ((string)($pms['status'] ?? '') !== 'failed'
            || ($pms['readback_verified'] ?? true) !== false
        ) {
            return false;
        }
        foreach ($expectedPlatforms as $platform => $sourceReceipt) {
            if (!$this->platformReceiptRecorded(
                $receipt,
                $platform,
                (int)($sourceReceipt['data_source_id'] ?? 0),
                ($sourceReceipt['verified'] ?? false) === true
            )) {
                return false;
            }
        }
        return in_array(
            (string)($receipt['status'] ?? ''),
            ['succeeded', 'collected', 'partial', 'failed'],
            true
        );
    }

    /**
     * @param array<int,array<string,mixed>> $sourceReceipts
     * @return array<string,mixed>
     */
    private function buildFinalizationReceipt(
        string $dispatcherRunId,
        int $hotelId,
        string $targetDate,
        array $sourceIds,
        array $sourceReceipts,
        string $dataPeriod
    ): array {
        $platformResults = [];
        $savedCount = 0;
        $otaVerified = [];
        foreach ($sourceReceipts as $sourceReceipt) {
            $platform = (string)($sourceReceipt['source'] ?? '');
            if (!in_array($platform, ['ctrip', 'meituan'], true)) {
                continue;
            }
            $platformResult = $this->platformRunResult(
                $platform,
                (int)($sourceReceipt['data_source_id'] ?? 0),
                $dispatcherRunId,
                $hotelId,
                $targetDate,
                $sourceReceipt
            );
            $platformResults[] = $platformResult;
            $savedCount += max(0, (int)($platformResult['saved_count'] ?? 0));
            if (($platformResult['success'] ?? false) === true) {
                $otaVerified[$platform] = true;
            }
        }
        $result = [
            'success' => array_keys($otaVerified) === ['ctrip', 'meituan']
                || array_keys($otaVerified) === ['meituan', 'ctrip'],
            'saved_count' => $savedCount,
            'required_platforms' => ['ctrip', 'meituan'],
            'platform_results' => $platformResults,
        ];
        $policy = new ScheduledAutoFetchPolicy();
        $outcome = $policy->classifyOutcome($result);
        $receipt = $policy->buildDailyTrustReceipt(
            $hotelId,
            $targetDate,
            $sourceIds,
            $outcome,
            $result,
            $dataPeriod
        );
        $receipt['dispatcher_run_id'] = $dispatcherRunId;
        return $receipt;
    }

    /** @param array<string,mixed> $receipt @return array<string,mixed> */
    private function finalizeCanonicalHistory(
        array $receipt,
        int $tenantId,
        int $hotelId,
        int $timeoutSeconds
    ): array
    {
        if ($this->canonicalHistoryFinalizer !== null) {
            $result = call_user_func(
                $this->canonicalHistoryFinalizer,
                $receipt,
                $tenantId,
                $hotelId,
                max(1, $timeoutSeconds)
            );
            if (!is_array($result)) {
                throw new RuntimeException('canonical_history_finalizer_invalid');
            }
            return $result;
        }
        return (new OtaCanonicalHistoryPromotionCoordinator())->finalize(
            $receipt,
            $tenantId,
            $hotelId,
            max(1, $timeoutSeconds)
        );
    }

    /** @param array<string,mixed> $receipt @return array<string,mixed> */
    private function blockedCanonicalHistoryFinalization(
        array $receipt,
        int $tenantId,
        int $hotelId,
        string $reason
    ): array {
        return [
            'schema_version' => 1,
            'status' => 'blocked',
            'reason' => $this->safeCode($reason),
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'target_date' => (string)($receipt['target_date'] ?? ''),
            'required_platforms' => ['ctrip', 'meituan'],
            'promoted_platforms' => [],
            'blocked_platforms' => ['ctrip', 'meituan'],
            'collection_anchor_contract_version' =>
                (string)($receipt['collection_anchor_contract_version'] ?? ''),
            'collection_anchor_hash' => (string)($receipt['collection_anchor_hash'] ?? ''),
            'overall_verifier' => [],
            'platform_results' => [],
            'canonical_history_complete' => false,
            'sensitive_values_exposed' => false,
        ];
    }

    /** @param array<string,mixed> $finalization @param array<string,mixed> $receipt */
    private function canonicalHistoryFinalizationVerified(
        array $finalization,
        int $tenantId,
        int $hotelId,
        string $targetDate,
        array $receipt
    ): bool {
        $requiredPlatforms = array_values(array_unique(array_map(
            'strval',
            is_array($finalization['required_platforms'] ?? null)
                ? $finalization['required_platforms']
                : []
        )));
        $promotedPlatforms = array_values(array_unique(array_map(
            'strval',
            is_array($finalization['promoted_platforms'] ?? null)
                ? $finalization['promoted_platforms']
                : []
        )));
        sort($requiredPlatforms, SORT_STRING);
        sort($promotedPlatforms, SORT_STRING);
        $anchorHash = strtolower(trim((string)($receipt['collection_anchor_hash'] ?? '')));
        return (string)($finalization['status'] ?? '') === 'verified'
            && ($finalization['canonical_history_complete'] ?? false) === true
            && (int)($finalization['tenant_id'] ?? 0) === $tenantId
            && (int)($finalization['hotel_id'] ?? 0) === $hotelId
            && (string)($finalization['target_date'] ?? '') === $targetDate
            && $requiredPlatforms === ['ctrip', 'meituan']
            && $promotedPlatforms === ['ctrip', 'meituan']
            && preg_match('/^[a-f0-9]{64}$/D', $anchorHash) === 1
            && hash_equals(
                $anchorHash,
                strtolower(trim((string)($finalization['collection_anchor_hash'] ?? '')))
            )
            && ($finalization['sensitive_values_exposed'] ?? true) === false;
    }

    /** @param array<int,array<string,mixed>> $sourceReceipts @param array<string,mixed> $receipt */
    private function finalizedRunReceiptVerified(
        array $runReceipt,
        string $dispatcherRunId,
        int $tenantId,
        int $hotelId,
        string $targetDate,
        string $pmsProvider,
        array $sourceReceipts,
        array $receipt,
        string $expectedRunMode,
        bool $trustedReady
    ): bool {
        if (!$this->finalRunReceiptVerified(
            $runReceipt,
            $dispatcherRunId,
            $tenantId,
            $hotelId,
            $targetDate,
            $pmsProvider,
            $sourceReceipts,
            $expectedRunMode
        ) || ($runReceipt['readback_verified'] ?? false) !== true) {
            return false;
        }
        $status = (string)($runReceipt['status'] ?? '');
        $anchorHash = strtolower(trim((string)($runReceipt['collection_anchor_hash'] ?? '')));
        $trustDigest = strtolower(trim((string)($runReceipt['trust_receipt_digest'] ?? '')));
        if (!$trustedReady) {
            return in_array($status, ['partial', 'failed'], true)
                && $anchorHash === ''
                && $trustDigest === '';
        }
        $expectedAnchorHash = strtolower(trim((string)($receipt['collection_anchor_hash'] ?? '')));
        return $status === 'succeeded'
            && preg_match('/^[a-f0-9]{64}$/D', $anchorHash) === 1
            && hash_equals($expectedAnchorHash, $anchorHash)
            && preg_match('/^[a-f0-9]{64}$/D', $trustDigest) === 1;
    }

    /** @return array<string,mixed> */
    private function sanitizeRunReadback(array $receipt, string $expectedDataPeriod): array
    {
        $dispatcherRunId = strtolower(trim((string)($receipt['dispatcher_run_id'] ?? '')));
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $dispatcherRunId) !== 1) {
            $dispatcherRunId = '';
        }
        $targetDate = trim((string)($receipt['target_date'] ?? ''));
        $platform = strtolower(trim((string)($receipt['platform'] ?? '')));
        $dataPeriod = strtolower(trim((string)($receipt['data_period'] ?? '')));
        $triggerType = strtolower(trim((string)($receipt['trigger_type'] ?? '')));
        $syncTaskId = max(0, (int)($receipt['sync_task_id'] ?? 0));
        $sourceId = max(0, (int)($receipt['data_source_id'] ?? 0));
        $hotelId = max(0, (int)($receipt['system_hotel_id'] ?? 0));
        $readbackCount = max(0, (int)($receipt['readback_count'] ?? 0));
        $p0Status = strtolower(trim((string)($receipt['p0_status'] ?? '')));
        $fieldFactStatus = strtolower(trim((string)($receipt['field_fact_status'] ?? '')));
        $pageFieldFactStatus = strtolower(trim((string)($receipt['page_field_fact_status'] ?? '')));
        $platformHotelIdentifierStatus = strtolower(trim((string)(
            $receipt['platform_hotel_identifier_status'] ?? ''
        )));
        $missingTrafficMetricKeys = is_array($receipt['missing_traffic_metric_keys'] ?? null)
            ? array_values(array_filter(array_map(
                fn(mixed $value): string => $this->safeCode((string)$value),
                $receipt['missing_traffic_metric_keys']
            )))
            : null;
        $rowIds = array_values(array_unique(array_filter(array_map(
            static fn($value): int => max(0, (int)$value),
            is_array($receipt['row_ids'] ?? null) ? $receipt['row_ids'] : []
        ))));
        sort($rowIds, SORT_NUMERIC);
        $sourceTraceIds = $this->safeStringList($receipt['source_trace_ids'] ?? [], 50, 160);
        if ($dispatcherRunId === ''
            || !$this->validDate($targetDate)
            || !in_array($platform, ['ctrip', 'meituan'], true)
            || !in_array($expectedDataPeriod, ['historical_daily', 'realtime_snapshot'], true)
            || $dataPeriod !== $expectedDataPeriod
            || $triggerType !== 'daily_profile_reuse'
            || $syncTaskId <= 0
            || $sourceId <= 0
            || $hotelId <= 0
            || $readbackCount <= 0
            || count($rowIds) !== $readbackCount
            || $sourceTraceIds === []
            || ($receipt['readback_verified'] ?? false) !== true
            || $p0Status !== 'ready'
            || $fieldFactStatus !== 'ready'
            || $pageFieldFactStatus !== 'ready'
            || $platformHotelIdentifierStatus !== 'ready'
            || $missingTrafficMetricKeys !== []
            || count($rowIds) > 500
        ) {
            return [];
        }
        return [
            'dispatcher_run_id' => $dispatcherRunId,
            'trigger_type' => $triggerType,
            'sync_task_id' => $syncTaskId,
            'data_source_id' => $sourceId,
            'system_hotel_id' => $hotelId,
            'platform' => $platform,
            'target_date' => $targetDate,
            'data_period' => $dataPeriod,
            'started_at' => trim((string)($receipt['started_at'] ?? '')),
            'p0_status' => $p0Status,
            'field_fact_status' => $fieldFactStatus,
            'page_field_fact_status' => $pageFieldFactStatus,
            'platform_hotel_identifier_status' => $platformHotelIdentifierStatus,
            'missing_traffic_metric_keys' => [],
            'readback_count' => $readbackCount,
            'row_ids' => $rowIds,
            'source_trace_ids' => $sourceTraceIds,
            'readback_verified' => true,
        ];
    }

    private function newDispatcherRunId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);
    }

    /**
     * @param array<string,mixed> $receipt
     * @return array{retry:bool,transient:bool,exhausted:bool,delay_seconds:int,stop_reason:string}
     */
    private function retryDecision(array $receipt, int $attemptCount, float $deadlineAt): array
    {
        $reason = $this->safeCode((string)($receipt['reason'] ?? ''));
        $transient = in_array($reason, self::TRANSIENT_FAILURE_CODES, true)
            || ($reason === 'collection_child_timeout'
                && ($receipt['timeout_cleanup_verified'] ?? false) === true);
        if (!$transient) {
            return [
                'retry' => false,
                'transient' => false,
                'exhausted' => false,
                'delay_seconds' => 0,
                'stop_reason' => 'terminal_failure',
            ];
        }
        if (($receipt['business_data_persisted'] ?? null) !== false) {
            return [
                'retry' => false,
                'transient' => true,
                'exhausted' => false,
                'delay_seconds' => 0,
                'stop_reason' => ($receipt['business_data_persisted'] ?? null) === true
                    ? 'business_data_persisted'
                    : 'persistence_outcome_unknown',
            ];
        }
        if (($receipt['message_sent'] ?? false) === true
            || ($receipt['sensitive_values_exposed'] ?? false) === true
        ) {
            return [
                'retry' => false,
                'transient' => true,
                'exhausted' => false,
                'delay_seconds' => 0,
                'stop_reason' => 'policy_violation',
            ];
        }
        if ($attemptCount >= self::MAX_TRANSIENT_RETRIES + 1) {
            return [
                'retry' => false,
                'transient' => true,
                'exhausted' => true,
                'delay_seconds' => 0,
                'stop_reason' => 'transient_retry_exhausted',
            ];
        }

        $delay = self::RETRY_BACKOFF_SECONDS[$attemptCount - 1] ?? 0;
        $requiredSeconds = $delay
            + self::MIN_CHILD_WINDOW_SECONDS
            + self::CLEANUP_RESERVE_SECONDS;
        if ((int)floor($deadlineAt - $this->monotonicNow()) < $requiredSeconds) {
            return [
                'retry' => false,
                'transient' => true,
                'exhausted' => false,
                'delay_seconds' => 0,
                'stop_reason' => 'queue_deadline_insufficient_for_retry',
            ];
        }
        return [
            'retry' => true,
            'transient' => true,
            'exhausted' => false,
            'delay_seconds' => $delay,
            'stop_reason' => 'transient_retry_scheduled',
        ];
    }

    private function sleepFor(int $seconds): void
    {
        $seconds = max(0, $seconds);
        if ($this->sleeper !== null) {
            call_user_func($this->sleeper, $seconds);
            return;
        }
        if ($seconds > 0) {
            sleep($seconds);
        }
    }

    /** @return array<string,mixed> */
    private function retryPolicy(): array
    {
        return [
            'max_transient_retries' => self::MAX_TRANSIENT_RETRIES,
            'backoff_seconds' => self::RETRY_BACKOFF_SECONDS,
            'explicit_transient_only' => true,
            'requires_explicit_no_persistence' => true,
            'timeout_requires_verified_cleanup' => true,
        ];
    }

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    private function blockedHotelReceipt(array $plan, string $targetDate, string $reason): array
    {
        return [
            'status' => 'blocked',
            'reason' => $this->safeCode($reason) ?: 'queue_scope_blocked',
            'plan_id' => (int)($plan['id'] ?? 0) ?: null,
            'tenant_id' => (int)($plan['tenant_id'] ?? 0) ?: null,
            'system_hotel_id' => (int)($plan['system_hotel_id'] ?? 0) ?: null,
            'target_date' => $targetDate,
            'execution_mode' => 'serial',
            'source_order' => self::SOURCE_ORDER,
            'retry_policy' => $this->retryPolicy(),
            'sources' => [],
            'deadline_reached' => $reason === 'queue_deadline_reached',
            'message_sent' => false,
            'sensitive_values_exposed' => false,
        ];
    }

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    private function skippedHotelReceipt(array $plan, string $targetDate, string $reason): array
    {
        return [
            'status' => 'not_due',
            'reason' => $this->safeCode($reason) ?: 'collection_plan_not_due',
            'plan_id' => (int)($plan['id'] ?? 0) ?: null,
            'tenant_id' => (int)($plan['tenant_id'] ?? 0) ?: null,
            'system_hotel_id' => (int)($plan['system_hotel_id'] ?? 0) ?: null,
            'target_date' => $targetDate,
            'sources' => [],
            'deadline_reached' => false,
            'message_sent' => false,
            'sensitive_values_exposed' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function blockedSourceReceipt(string $sourceKey, int $sourceId, string $reason): array
    {
        return [
            'source' => $sourceKey,
            'data_source_id' => $sourceId > 0 ? $sourceId : null,
            'status' => 'blocked',
            'reason' => $this->safeCode($reason) ?: 'collection_child_blocked',
            'exit_code' => 1,
            'timed_out' => $reason === 'collection_child_timeout',
            'saved_count' => 0,
            'readback_count' => 0,
            'readback_verified' => false,
            'verified' => false,
            'business_data_persisted' => null,
            'attempt_count' => 0,
            'retry_count' => 0,
            'retry_delays_seconds' => [],
            'recovered_after_retry' => false,
            'transient_retry_exhausted' => false,
            'last_transient_reason' => null,
            'retry_stop_reason' => 'not_started',
            'message_sent' => false,
            'sensitive_values_exposed' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function queueFailure(string $reason): array
    {
        return [
            'status' => 'blocked',
            'reason' => $this->safeCode($reason) ?: 'cloud_three_source_queue_failed',
            'execution_mode' => 'global_serial',
            'source_order' => self::SOURCE_ORDER,
            'retry_policy' => $this->retryPolicy(),
            'eligible_plan_count' => 0,
            'verified_hotel_count' => 0,
            'blocked_hotel_count' => 0,
            'deadline_reached' => false,
            'hotels' => [],
            'message_sent' => false,
            'sensitive_values_exposed' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function decodeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function appendTail(string $current, string $chunk): string
    {
        $combined = $current . $chunk;
        return strlen($combined) <= self::MAX_CAPTURED_PROCESS_OUTPUT_BYTES
            ? $combined
            : substr($combined, -self::MAX_CAPTURED_PROCESS_OUTPUT_BYTES);
    }

    /** @return array<string,mixed> */
    private function lastJsonObject(string $output): array
    {
        $lines = array_reverse(preg_split('/\R+/', trim($output)) ?: []);
        foreach ($lines as $line) {
            $decoded = json_decode(trim((string)$line), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    private function now(): DateTimeImmutable
    {
        if ($this->clock === null) {
            return new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE));
        }
        $value = call_user_func($this->clock);
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (is_int($value)) {
            return (new DateTimeImmutable('@' . $value))->setTimezone(new DateTimeZone(self::TIMEZONE));
        }
        if (is_string($value) && trim($value) !== '') {
            return new DateTimeImmutable($value, new DateTimeZone(self::TIMEZONE));
        }
        throw new RuntimeException('cloud_three_source_queue_clock_invalid');
    }

    private function monotonicNow(): float
    {
        $value = $this->monotonicClock !== null
            ? call_user_func($this->monotonicClock)
            : hrtime(true) / 1_000_000_000;
        if (!is_int($value) && !is_float($value)) {
            throw new RuntimeException('cloud_three_source_queue_monotonic_clock_invalid');
        }
        return (float)$value;
    }

    private function timestamp(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value, new DateTimeZone(self::TIMEZONE));
        } catch (\Throwable) {
            return null;
        }
    }

    private function validDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone(self::TIMEZONE));
        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
    }

    private function safeCode(string $value): string
    {
        $value = trim((string)preg_replace('/[^a-zA-Z0-9_-]+/', '_', strtolower($value)), '_');
        return substr($value, 0, 120);
    }

    /** @return list<string> */
    private function platformList(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }
        $platforms = [];
        foreach ($values as $value) {
            $platform = strtolower(trim((string)$value));
            if (in_array($platform, ['ctrip', 'meituan'], true)) {
                $platforms[$platform] = true;
            }
        }
        $result = array_keys($platforms);
        sort($result, SORT_STRING);
        return $result;
    }

    /** @return list<string> */
    private function safeStringList(mixed $values, int $limit, int $maxLength): array
    {
        if (!is_array($values)) {
            return [];
        }
        $result = [];
        foreach ($values as $value) {
            $text = trim((string)$value);
            if ($text === '' || strlen($text) > $maxLength
                || preg_match('/^[A-Za-z0-9._:-]+$/D', $text) !== 1
            ) {
                continue;
            }
            $result[$text] = true;
            if (count($result) >= $limit) {
                break;
            }
        }
        return array_keys($result);
    }
}
