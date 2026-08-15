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
        ?callable $collectionAborter = null
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
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function run(array $options = []): array
    {
        $now = $this->now();
        $targetDate = trim((string)($options['target_date'] ?? $now->format('Y-m-d')));
        $childTimeoutSeconds = max(60, min(900, (int)($options['child_timeout_seconds'] ?? 540)));
        $deadlineSeconds = max(60, min(3300, (int)($options['deadline_seconds'] ?? 1500)));
        $controlTokenFile = trim((string)($options['control_token_file']
            ?? self::CONTROL_TOKEN_FILES[0]));
        if (!$this->validDate($targetDate)
            || $targetDate !== $now->format('Y-m-d')
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
                'target_date' => $targetDate,
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
        $deadlineReached = false;
        $gatewayCleanupVerified = true;
        foreach ($plans as $plan) {
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

        $allVerified = $verifiedHotelCount === count($plans) && !$deadlineReached;
        return [
            'status' => $allVerified
                ? 'all_hotels_saved_and_readback_verified'
                : 'partial_or_blocked',
            'execution_mode' => 'global_serial',
            'source_order' => self::SOURCE_ORDER,
            'target_date' => $targetDate,
            'eligible_plan_count' => count($plans),
            'verified_hotel_count' => $verifiedHotelCount,
            'blocked_hotel_count' => count($plans) - $verifiedHotelCount,
            'deadline_reached' => $deadlineReached,
            'gateway_cleanup_verified' => $gatewayCleanupVerified,
            'hotels' => $hotelReceipts,
            'message_sent' => false,
            'sensitive_values_exposed' => false,
        ];
    }

    /**
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private function runPlan(
        array $plan,
        string $targetDate,
        string $controlTokenFile,
        int $childTimeoutSeconds,
        float $deadlineAt
    ): array {
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
        if (strtolower(trim((string)($plan['business_date_policy'] ?? ''))) !== 'same_day_realtime') {
            return $this->blockedHotelReceipt($plan, $targetDate, 'collection_plan_business_date_policy_unsupported');
        }

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
                'realtime'
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
        if (($authorization['collection_allowed'] ?? false) !== true
            || (int)($authorization['tenant_id'] ?? 0) !== $tenantId
            || (int)($authorization['system_hotel_id'] ?? 0) !== $hotelId
            || (int)($authorization['execution_owner_user_id'] ?? 0) !== $ownerUserId
            || (int)($authorization['plan_id'] ?? 0) !== $planId
            || !hash_equals(
                $planHash,
                strtolower(trim((string)($authorization['plan_hash'] ?? '')))
            )
            || $authorizedSourceIds !== $expectedSourceIds
            || ($authorization['plan_readback_verified'] ?? false) !== true
            || ($authorization['binding_digest_matches'] ?? false) !== true
        ) {
            return $this->blockedHotelReceipt($plan, $targetDate, 'collection_plan_authorization_blocked');
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
            return $this->blockedHotelReceipt(
                $plan,
                $targetDate,
                $this->safeCode($error->getMessage()) ?: 'cloud_profile_scope_invalid'
            );
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
                '--control-token-file=' . $controlTokenFile,
                '--timeout-seconds=' . $childTimeoutSeconds,
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
                '--control-token-file=' . $controlTokenFile,
                '--timeout-seconds=' . $childTimeoutSeconds,
            ],
        ];
        $sourceIds = ['pms' => 0, 'ctrip' => $ctripSourceId, 'meituan' => $meituanSourceId];
        $sourceReceipts = [];
        $allVerified = true;
        $deadlineReached = false;
        $gatewayCleanupVerified = true;
        $skipRemainingReason = null;
        foreach (self::SOURCE_ORDER as $sourceKey) {
            if (is_string($skipRemainingReason)) {
                $allVerified = false;
                $sourceReceipts[] = $this->blockedSourceReceipt(
                    $sourceKey,
                    $sourceIds[$sourceKey],
                    $skipRemainingReason
                );
                continue;
            }
            $remainingSeconds = (int)floor($deadlineAt - $this->monotonicNow());
            if ($remainingSeconds < 60) {
                $deadlineReached = true;
                $allVerified = false;
                $sourceReceipts[] = $this->blockedSourceReceipt(
                    $sourceKey,
                    $sourceIds[$sourceKey],
                    'queue_deadline_reached'
                );
                continue;
            }
            $effectiveTimeout = min($childTimeoutSeconds, $remainingSeconds);
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
                        'profile_public_id' => $profiles[$sourceKey]['profile_public_id'],
                    ]
                );
                $receipt = $this->sanitizeChildReceipt(
                    $sourceKey,
                    $sourceIds[$sourceKey],
                    $child
                );
                if (($receipt['timed_out'] ?? false) === true) {
                    $processCleanupVerified = ($child['process_group_cleanup_verified'] ?? false) === true;
                    $gatewayAbortVerified = $this->abortCollection(
                        $profiles[$sourceKey]['profile_public_id'],
                        $controlTokenFile
                    );
                    $cleanupVerified = $processCleanupVerified && $gatewayAbortVerified;
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
            if (($receipt['verified'] ?? false) !== true) {
                $allVerified = false;
            }
            if (($receipt['timed_out'] ?? false) === true) {
                $deadlineReached = $deadlineReached || $this->monotonicNow() >= $deadlineAt;
            }
            $sourceReceipts[] = $receipt;
        }

        return [
            'status' => $allVerified
                ? 'all_sources_saved_and_readback_verified'
                : 'partial_or_blocked',
            'plan_id' => $planId,
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'target_date' => $targetDate,
            'execution_mode' => 'serial',
            'source_order' => self::SOURCE_ORDER,
            'sources' => $sourceReceipts,
            'deadline_reached' => $deadlineReached,
            'gateway_cleanup_verified' => $gatewayCleanupVerified,
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
            ->field('id,tenant_id,system_hotel_id,plan_version,plan_status,enabled,active_slot,business_date_policy,execution_owner_user_id,binding_digest,plan_hash,source_plan_json,validation_status')
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
    private function sanitizeChildReceipt(string $sourceKey, int $sourceId, array $child): array
    {
        $receipt = is_array($child['receipt'] ?? null) ? $child['receipt'] : [];
        $exitCode = (int)($child['exit_code'] ?? 1);
        $timedOut = ($child['timed_out'] ?? false) === true;
        $status = $this->safeCode((string)($receipt['status'] ?? 'blocked')) ?: 'blocked';
        $reason = $timedOut
            ? 'collection_child_timeout'
            : ($this->safeCode((string)($receipt['reason'] ?? '')) ?: null);
        $messagePolicyViolated = ($receipt['message_sent'] ?? false) === true;
        $savedCount = max(0, (int)($receipt['saved_count'] ?? 0));
        $readbackCount = max(0, (int)($receipt['readback_count'] ?? 0));
        if ($sourceKey === 'pms') {
            $savedCount = (int)($receipt['capture_id'] ?? 0) > 0 ? 1 : 0;
            $readbackCount = ($receipt['readback_status'] ?? '') === 'readback_verified' ? $savedCount : 0;
            $verified = !$timedOut
                && !$messagePolicyViolated
                && $exitCode === 0
                && $status === 'saved_and_readback_verified'
                && (string)($receipt['identity_status'] ?? '') === 'matched'
                && (string)($receipt['readback_status'] ?? '') === 'readback_verified'
                && ($receipt['push_orchestration']['disabled_by_invocation'] ?? false) === true
                && $savedCount === 1
                && $readbackCount === 1;
        } else {
            $verified = !$timedOut
                && !$messagePolicyViolated
                && $exitCode === 0
                && $status === 'saved_and_readback_verified'
                && ($receipt['business_data_persisted'] ?? false) === true
                && ($receipt['readback_verified'] ?? false) === true
                && $savedCount > 0
                && $savedCount === $readbackCount;
        }
        if ($messagePolicyViolated) {
            $reason = 'message_send_policy_violated';
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
            'readback_verified' => $verified,
            'verified' => $verified,
            'message_sent' => false,
            'sensitive_values_exposed' => false,
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
            'sources' => [],
            'deadline_reached' => $reason === 'queue_deadline_reached',
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
}
