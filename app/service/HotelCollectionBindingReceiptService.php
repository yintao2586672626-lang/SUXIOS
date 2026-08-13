<?php
declare(strict_types=1);

namespace app\service;

use app\model\User;
use DateTimeImmutable;
use RuntimeException;
use think\facade\Db;

/**
 * Read-only, secret-free hotel collection identity receipt.
 *
 * This service reconciles the system hotel, both OTA source records, the
 * exact browser Profile ownership or operator-owned local-collector device
 * mapping, and the selected PMS identity. It does not read secret_json,
 * browser storage, cookies, tokens or Profile directories, and it never
 * creates or substitutes an execution device.
 */
final class HotelCollectionBindingReceiptService
{
    private const PLATFORMS = ['ctrip', 'meituan'];
    private const LOCAL_DEVICE_ONLINE_SECONDS = 120;

    /** @var callable|null */
    private $sourceLoader;

    /** @var callable|null */
    private $profileBindingLoader;

    /** @var callable|null */
    private $localExecutionBindingLoader;

    /** @var callable|null */
    private $pmsBindingLoader;

    /** @var callable|null */
    private $identityOwnerLoader;

    /** @var callable|null */
    private $clock;

    /** @var callable|null */
    private $executionOwnerPermissionLoader;

    /** @var callable|null */
    private $profileSessionStateLoader;

    public function __construct(
        ?callable $sourceLoader = null,
        ?callable $profileBindingLoader = null,
        ?callable $localExecutionBindingLoader = null,
        ?callable $pmsBindingLoader = null,
        ?callable $identityOwnerLoader = null,
        ?callable $clock = null,
        ?callable $executionOwnerPermissionLoader = null,
        ?callable $profileSessionStateLoader = null
    ) {
        $this->sourceLoader = $sourceLoader;
        $this->profileBindingLoader = $profileBindingLoader;
        $this->localExecutionBindingLoader = $localExecutionBindingLoader;
        $this->pmsBindingLoader = $pmsBindingLoader;
        $this->identityOwnerLoader = $identityOwnerLoader;
        $this->clock = $clock;
        $this->executionOwnerPermissionLoader = $executionOwnerPermissionLoader;
        $this->profileSessionStateLoader = $profileSessionStateLoader;
    }

    /**
     * @param array<string,mixed> $hotel
     * @return array<string,mixed>
     */
    public function receipt(
        array $hotel,
        int $actorUserId,
        string $businessDate = '',
        array $designatedSourceIds = []
    ): array
    {
        $hotelId = (int)($hotel['id'] ?? 0);
        $tenantId = (int)($hotel['tenant_id'] ?? 0);
        if ($hotelId <= 0 || $tenantId <= 0 || $actorUserId <= 0) {
            throw new \InvalidArgumentException('hotel_collection_binding_scope_invalid');
        }
        $businessDate = $this->dateOrEmpty($businessDate);
        $designatedSourceIds = $this->designatedSourceIds($designatedSourceIds);

        [$sourceRows, $sourceReadError] = $this->safeRows(
            fn(): array => $this->loadSources($tenantId, $hotelId)
        );
        [$profileRows, $profileReadError] = $this->safeRows(
            fn(): array => $this->loadProfileBindings($tenantId, $hotelId)
        );
        [$localRows, $localReadError] = $this->safeRows(
            fn(): array => $this->loadLocalExecutionBindings($tenantId, $hotelId)
        );

        $bindings = [];
        foreach (self::PLATFORMS as $platform) {
            $bindings[$platform] = $this->otaBinding(
                $tenantId,
                $hotelId,
                $platform,
                $sourceRows,
                $profileRows,
                $localRows,
                $sourceReadError,
                $profileReadError,
                $localReadError,
                (int)($designatedSourceIds[$platform] ?? 0)
            );
        }
        $bindings['pms'] = $this->pmsBinding(
            $tenantId,
            $hotelId,
            $actorUserId,
            $businessDate
        );

        $blockers = [];
        $recoveryReasons = [];
        foreach ($bindings as $binding) {
            $blockers = array_merge($blockers, (array)($binding['blockers'] ?? []));
            $recoveryReasons = array_merge(
                $recoveryReasons,
                (array)($binding['recovery_reasons'] ?? [])
            );
        }
        $blockers = $this->uniqueIssues($blockers);
        $recoveryReasons = $this->uniqueIssues($recoveryReasons);

        $otaOwnerIds = array_values(array_unique(array_filter(array_map(
            static fn(string $platform): int => (int)($bindings[$platform]['execution_owner_user_id'] ?? 0),
            self::PLATFORMS
        ))));
        if (count($otaOwnerIds) > 1) {
            $blockers[] = $this->issue(
                'ota_execution_owner_conflict',
                'Ctrip and Meituan are assigned to different execution owners.'
            );
            $blockers = $this->uniqueIssues($blockers);
        }

        $status = $blockers !== []
            ? 'blocked'
            : ($recoveryReasons !== [] ? 'recoverable' : 'ready');
        $digestPayload = [
            'schema_version' => 1,
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'ctrip' => $this->bindingDigestFacts($bindings['ctrip']),
            'meituan' => $this->bindingDigestFacts($bindings['meituan']),
            'pms' => $this->bindingDigestFacts($bindings['pms']),
        ];

        return [
            'schema_version' => 1,
            'status' => $status,
            'binding_ready' => $status === 'ready',
            'business_date_context' => $businessDate !== '' ? $businessDate : null,
            'system_hotel' => [
                'tenant_id' => $tenantId,
                'system_hotel_id' => $hotelId,
                'hotel_name' => $this->safeText((string)($hotel['name'] ?? ''), 160),
                'enabled' => (int)($hotel['status'] ?? 0) === 1,
            ],
            'execution_owner_user_id' => count($otaOwnerIds) === 1 ? $otaOwnerIds[0] : null,
            'bindings' => $bindings,
            'binding_digest' => hash(
                'sha256',
                (string)json_encode(
                    $digestPayload,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                )
            ),
            'blockers' => $blockers,
            'recovery_reasons' => $recoveryReasons,
            'execution_policy' => [
                'login_state_location' => 'exact_profile_or_operator_owned_local_collector',
                'central_cookie_profile_pool' => false,
                'automatic_device_substitution' => false,
                'cross_hotel_collection' => false,
                'resume_scope' => 'same_tenant_user_hotel_platform_execution_binding',
            ],
            'replication_gate' => [
                'ready' => false,
                'status' => $status === 'ready'
                    ? 'binding_ready_runtime_acceptance_still_required'
                    : 'binding_not_ready',
                'reason' => $status === 'ready'
                    ? 'Binding alone does not prove save, exact readback, page acceptance or stable field runs.'
                    : 'All hotel and execution bindings must be exact before field replication can start.',
            ],
            'sensitive_values_exposed' => false,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $sourceRows
     * @param array<int,array<string,mixed>> $profileRows
     * @param array<int,array<string,mixed>> $localRows
     * @return array<string,mixed>
     */
    private function otaBinding(
        int $tenantId,
        int $hotelId,
        string $platform,
        array $sourceRows,
        array $profileRows,
        array $localRows,
        string $sourceReadError,
        string $profileReadError,
        string $localReadError,
        int $designatedSourceId
    ): array {
        $blockers = [];
        $recoveryReasons = [];
        if ($sourceReadError !== '') {
            $blockers[] = $this->issue('ota_source_binding_read_failed', $sourceReadError, $platform);
        }
        $scopeMismatches = array_values(array_filter(
            $sourceRows,
            static fn(array $row): bool => strtolower(trim((string)($row['platform'] ?? ''))) === $platform
                && ((int)($row['tenant_id'] ?? 0) !== $tenantId
                    || (int)($row['system_hotel_id'] ?? 0) !== $hotelId)
        ));
        if ($scopeMismatches !== []) {
            $blockers[] = $this->issue('ota_source_scope_mismatch', 'OTA source rows crossed the requested hotel scope.', $platform);
        }
        $candidates = array_values(array_filter(
            $sourceRows,
            static fn(array $row): bool => (int)($row['tenant_id'] ?? 0) === $tenantId
                && (int)($row['system_hotel_id'] ?? 0) === $hotelId
                && strtolower(trim((string)($row['platform'] ?? ''))) === $platform
                && in_array(
                    strtolower(trim((string)($row['ingestion_method'] ?? ''))),
                    ['browser_profile', 'profile_browser', 'local_collector'],
                    true
                )
                && (int)($row['enabled'] ?? 0) === 1
                && strtolower(trim((string)($row['status'] ?? ''))) !== 'disabled'
        ));
        $selectedCandidates = $designatedSourceId > 0
            ? array_values(array_filter(
                $candidates,
                static fn(array $row): bool => (int)($row['id'] ?? 0) === $designatedSourceId
            ))
            : $candidates;
        if (count($selectedCandidates) !== 1) {
            $missingCode = $designatedSourceId > 0
                ? 'ota_designated_source_binding_missing'
                : 'ota_source_binding_missing';
            $conflictCode = $designatedSourceId > 0
                ? 'ota_designated_source_binding_conflict'
                : 'ota_source_binding_conflict';
            $blockers[] = $this->issue(
                count($selectedCandidates) === 0 ? $missingCode : $conflictCode,
                count($selectedCandidates) === 0
                    ? ($designatedSourceId > 0
                        ? 'The designated OTA source is not active in this hotel and platform scope.'
                        : 'No active OTA source is bound to this hotel and platform.')
                    : 'More than one active OTA source matched the designated hotel and platform scope.',
                $platform
            );
        }
        $source = count($selectedCandidates) === 1 ? $selectedCandidates[0] : [];
        $config = $this->decodeConfig($source['config_json'] ?? null);
        $sourceId = (int)($source['id'] ?? 0);
        $sourceOwnerUserId = (int)($source['user_id'] ?? 0);
        if ($source !== [] && $sourceOwnerUserId <= 0) {
            $blockers[] = $this->issue('ota_execution_owner_missing', 'OTA source has no execution owner.', $platform);
        }
        if ($sourceOwnerUserId > 0) {
            try {
                if (!$this->executionOwnerPermitted($tenantId, $hotelId, $sourceOwnerUserId)) {
                    $recoveryReasons[] = $this->issue(
                        'permission_denied',
                        'Restore this execution owner\'s access to the same hotel before resuming.',
                        $platform
                    );
                }
            } catch (\Throwable $error) {
                $blockers[] = $this->issue(
                    'ota_execution_owner_permission_unverified',
                    $this->safeCode($error->getMessage()) ?: 'permission_read_failed',
                    $platform
                );
            }
        }
        $ingestionMethod = strtolower(trim((string)($source['ingestion_method'] ?? '')));
        $isLocalCollector = $ingestionMethod === 'local_collector';
        if ($localReadError !== '' && $isLocalCollector) {
            $blockers[] = $this->issue(
                'ota_execution_device_binding_read_failed',
                $localReadError,
                $platform
            );
        }
        $declaredLocalAccountId = (int)($config['local_collector_account_id'] ?? 0);
        $declaredLocalDeviceHash = strtolower(trim((string)($config['collector_device_id_hash'] ?? '')));
        $localSourceProofValid = !$isLocalCollector || (
            $declaredLocalAccountId > 0
            && preg_match('/^[a-f0-9]{64}$/D', $declaredLocalDeviceHash) === 1
        );
        if ($profileReadError !== '' && !$isLocalCollector) {
            $blockers[] = $this->issue('ota_profile_binding_read_failed', $profileReadError, $platform);
        }
        if ($source !== [] && !in_array(
            $ingestionMethod,
            ['browser_profile', 'profile_browser', 'local_collector'],
            true
        )) {
            $blockers[] = $this->issue(
                'ota_source_not_operator_device_profile',
                'OTA login state is not declared as an operator-device Profile or local collector source.',
                $platform
            );
        }
        if ($source !== [] && $isLocalCollector && !$localSourceProofValid) {
            $blockers[] = $this->issue(
                'ota_local_collector_source_binding_proof_missing',
                'Local collector source has no exact account and device binding proof.',
                $platform
            );
        }

        $platformHotelId = trim((string)($config['platform_hotel_id'] ?? ''));
        $legacyCandidates = $source !== []
            ? $this->legacyIdentityCandidates($platform, $config)
            : $this->candidateLegacyIdentityCandidates($platform, $candidates);
        if ($platformHotelId === '') {
            $blockers[] = $this->issue(
                'ota_platform_hotel_id_canonical_missing',
                'Canonical platform_hotel_id is missing; legacy aliases cannot silently become the binding.',
                $platform
            );
        }
        if (count($legacyCandidates) > 1) {
            $blockers[] = $this->issue(
                'ota_platform_hotel_identity_alias_conflict',
                'Legacy platform hotel identity aliases disagree.',
                $platform
            );
        }
        $identitySource = trim((string)($config['platform_hotel_identity_source'] ?? ''));
        $identityCheckedAt = trim((string)($config['platform_hotel_identity_checked_at'] ?? ''));
        if ($platformHotelId !== '' && ($identitySource === '' || !$this->timestamp($identityCheckedAt))) {
            $blockers[] = $this->issue(
                'ota_platform_hotel_identity_unverified',
                'Platform hotel identity has no dated verification evidence.',
                $platform
            );
        }
        if ($platformHotelId !== '') {
            [$owners, $ownerReadError] = $this->safeRows(
                fn(): array => $this->loadIdentityOwners('ota', $platform, $platformHotelId)
            );
            if ($ownerReadError !== '') {
                $blockers[] = $this->issue('ota_platform_hotel_uniqueness_unverified', $ownerReadError, $platform);
            } else {
                $foreignOwners = array_values(array_filter(
                    $owners,
                    static fn(array $owner): bool => (int)($owner['tenant_id'] ?? 0) !== $tenantId
                        || (int)($owner['system_hotel_id'] ?? 0) !== $hotelId
                ));
                if ($foreignOwners !== []) {
                    $blockers[] = $this->issue(
                        'ota_platform_hotel_identity_cross_hotel_conflict',
                        'The platform hotel identity is active in another hotel scope.',
                        $platform
                    );
                }
            }
        }

        $expectedProfileHash = $this->sourceProfileHash($platform, $config, $ingestionMethod);
        $activeProfiles = array_values(array_filter(
            $profileRows,
            static fn(array $row): bool => (int)($row['tenant_id'] ?? 0) === $tenantId
                && (int)($row['system_hotel_id'] ?? 0) === $hotelId
                && strtolower(trim((string)($row['platform'] ?? ''))) === $platform
                && strtolower(trim((string)($row['binding_status'] ?? ''))) === 'active'
        ));
        $profileBindingStatus = 'missing';
        $profileHash = '';
        if ($isLocalCollector) {
            $profileHash = $expectedProfileHash;
            $profileBindingStatus = $profileHash !== '' ? 'local_account_profile_declared' : 'missing';
        } else {
            if (count($activeProfiles) !== 1) {
                $blockers[] = $this->issue(
                    count($activeProfiles) === 0 ? 'ota_profile_binding_missing' : 'ota_profile_binding_conflict',
                    count($activeProfiles) === 0
                        ? 'No active Profile ownership binding exists for this hotel and platform.'
                        : 'More than one active Profile ownership binding exists for this hotel and platform.',
                    $platform
                );
            }
            $profileBinding = count($activeProfiles) === 1 ? $activeProfiles[0] : [];
            $profileHash = strtolower(trim((string)($profileBinding['profile_key_hash'] ?? '')));
            $profileBindingStatus = count($activeProfiles) === 1
                ? 'active'
                : (count($activeProfiles) === 0 ? 'missing' : 'conflict');
        }
        if ($source !== [] && $expectedProfileHash === '') {
            $blockers[] = $this->issue('ota_source_profile_identity_missing', 'OTA source has no Profile identity key.', $platform);
        } elseif ($profileHash !== '' && $expectedProfileHash !== '' && !hash_equals($profileHash, $expectedProfileHash)) {
            $blockers[] = $this->issue(
                'ota_source_profile_binding_mismatch',
                'OTA source Profile does not match the hotel Profile ownership binding.',
                $platform
            );
        }

        $localExecutionCandidates = array_values(array_filter(
            $localRows,
            static fn(array $row): bool => (int)($row['tenant_id'] ?? 0) === $tenantId
                && (int)($row['system_hotel_id'] ?? 0) === $hotelId
                && strtolower(trim((string)($row['platform'] ?? ''))) === $platform
                && strtolower(trim((string)($row['mapping_status'] ?? ''))) === 'active'
        ));
        $singleUserLocalBinding = !$isLocalCollector
            ? $this->singleUserLocalProfileBinding(
                $source,
                $config,
                $tenantId,
                $hotelId,
                $platform,
                $sourceOwnerUserId
            )
            : ['declared' => false, 'complete' => false, 'device_binding_digest' => null, 'bound_at' => null];
        $executionCandidates = ($singleUserLocalBinding['declared'] ?? false) === true
            ? []
            : $localExecutionCandidates;
        $profileSchedulerBound = ($singleUserLocalBinding['complete'] ?? false) === true
            && $source !== []
            && count($activeProfiles) === 1
            && $profileHash !== ''
            && $expectedProfileHash !== ''
            && hash_equals($profileHash, $expectedProfileHash)
            && $sourceOwnerUserId > 0
            && $platformHotelId !== ''
            && $identitySource !== ''
            && $this->timestamp($identityCheckedAt);
        $profileSessionStatus = null;
        if ($profileSchedulerBound) {
            $profileSession = $this->profileSessionState($source);
            if (($profileSession['is_reusable'] ?? false) === true) {
                $profileSessionStatus = 'profile_reuse_verified';
            } else {
                $profileSessionReason = $this->safeCode((string)($profileSession['reason'] ?? ''));
                $profileSessionStatus = in_array($profileSessionReason, [
                    'profile_session_explicitly_expired',
                    'profile_reauthentication_required',
                ], true) ? 'session_expired' : 'login_required';
                $recoveryReasons[] = $this->issue(
                    $profileSessionStatus,
                    $profileSessionStatus === 'session_expired'
                        ? 'The bound browser Profile must be reauthenticated on this same local device.'
                        : 'The bound browser Profile has no reusable verified login proof on this same local device.',
                    $platform
                );
            }
        }
        if (count($executionCandidates) !== 1
            && !($profileSchedulerBound && count($executionCandidates) === 0)
        ) {
            $blockers[] = $this->issue(
                count($executionCandidates) === 0
                    ? 'ota_execution_device_binding_missing'
                    : 'ota_execution_device_binding_conflict',
                count($executionCandidates) === 0
                    ? ($isLocalCollector
                        ? 'No operator-owned execution device mapping exists for this hotel and platform.'
                        : 'No complete single_user_local Profile execution binding exists for this hotel and platform.')
                    : 'More than one execution device mapping exists for this hotel and platform.',
                $platform
            );
        }
        $execution = count($executionCandidates) === 1 ? $executionCandidates[0] : [];
        $effectiveDeviceStatus = null;
        if ($execution !== []) {
            $executionMappingMatches = (int)($execution['data_source_id'] ?? 0) === $sourceId
                && trim((string)($execution['platform_hotel_id'] ?? '')) !== ''
                && ($platformHotelId === ''
                    || hash_equals(
                        strtolower($platformHotelId),
                        strtolower(trim((string)$execution['platform_hotel_id']))
                    ));
            if (!$executionMappingMatches) {
                $blockers[] = $this->issue(
                    'ota_execution_mapping_identity_mismatch',
                    'Execution mapping does not match the exact source and platform hotel identity.',
                    $platform
                );
            }
            $executionDeviceHash = strtolower(trim((string)($execution['device_binding_digest'] ?? '')));
            $localSourceExecutionMatches = !$isLocalCollector || (
                $localSourceProofValid
                && (int)($execution['account_id'] ?? 0) === $declaredLocalAccountId
                && preg_match('/^[a-f0-9]{64}$/D', $executionDeviceHash) === 1
                && hash_equals($declaredLocalDeviceHash, $executionDeviceHash)
            );
            if (!$localSourceExecutionMatches) {
                $blockers[] = $this->issue(
                    'ota_local_collector_source_execution_mismatch',
                    'Local collector source does not match the exact execution account and device.',
                    $platform
                );
            }
            $executionProfileHash = strtolower(trim((string)($execution['profile_key_hash'] ?? '')));
            if ($profileHash !== '' && ($executionProfileHash === '' || !hash_equals($profileHash, $executionProfileHash))) {
                $blockers[] = $this->issue(
                    'ota_execution_profile_binding_mismatch',
                    'Execution account Profile does not match the hotel Profile binding.',
                    $platform
                );
            } elseif ($isLocalCollector
                && $profileHash !== ''
                && $executionMappingMatches
                && $localSourceExecutionMatches
            ) {
                $profileBindingStatus = 'local_account_bound';
            }
            if ((int)($execution['account_tenant_id'] ?? 0) !== $tenantId
                || (int)($execution['device_tenant_id'] ?? 0) !== $tenantId
                || (int)($execution['account_user_id'] ?? 0) !== $sourceOwnerUserId
                || (int)($execution['device_user_id'] ?? 0) !== $sourceOwnerUserId
            ) {
                $blockers[] = $this->issue(
                    'ota_execution_owner_scope_mismatch',
                    'Execution account or device belongs to another tenant or user.',
                    $platform
                );
            }
            $deviceStatus = $this->effectiveDeviceStatus($execution);
            $effectiveDeviceStatus = $deviceStatus;
            $accountStatus = strtolower(trim((string)($execution['account_status'] ?? 'login_required')));
            $sessionStatus = strtolower(trim((string)($execution['session_status'] ?? 'login_required')));
            $lastErrorCode = $this->safeCode((string)($execution['last_error_code'] ?? ''));
            if (in_array($deviceStatus, ['revoked', 'disabled'], true)
                || in_array($accountStatus, ['revoked', 'disabled'], true)
            ) {
                $blockers[] = $this->issue(
                    'ota_execution_binding_revoked',
                    'The bound execution device or account is revoked.',
                    $platform
                );
            } elseif ($deviceStatus !== 'online') {
                $recoveryReasons[] = $this->issue(
                    'device_offline',
                    'Resume only after the already-bound operator device is online.',
                    $platform
                );
            } elseif ($sessionStatus !== 'current_session_verified') {
                $reasonCode = in_array($lastErrorCode, ['permission_denied', 'identity_mismatch'], true)
                    ? $lastErrorCode
                    : 'login_required';
                $recoveryReasons[] = $this->issue(
                    $reasonCode,
                    $reasonCode === 'login_required'
                        ? 'Resume only after login is restored on the already-bound operator device.'
                        : 'Review permission or hotel identity on the already-bound operator device.',
                    $platform
                );
            }
        }

        $blockers = $this->uniqueIssues($blockers);
        $recoveryReasons = $this->uniqueIssues($recoveryReasons);
        $status = $blockers !== [] ? 'blocked' : ($recoveryReasons !== [] ? 'recoverable' : 'ready');
        $executionBindingKind = $execution !== []
            ? 'local_collector_device'
            : ($profileSchedulerBound ? 'browser_profile_single_user_local' : null);
        $executionBindingDigest = $execution !== []
            ? hash('sha256', (string)json_encode([
                'tenant_id' => $tenantId,
                'system_hotel_id' => $hotelId,
                'platform' => $platform,
                'source_id' => $sourceId,
                'platform_hotel_id' => $platformHotelId,
                'account_id' => (int)($execution['account_id'] ?? 0),
                'device_id' => (int)($execution['device_id'] ?? 0),
                'device_binding_digest' => (string)($execution['device_binding_digest'] ?? ''),
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
            : ($profileSchedulerBound
                ? hash('sha256', (string)json_encode([
                    'binding_kind' => 'browser_profile_single_user_local',
                    'tenant_id' => $tenantId,
                    'system_hotel_id' => $hotelId,
                    'platform' => $platform,
                    'source_id' => $sourceId,
                    'execution_owner_user_id' => $sourceOwnerUserId,
                    'platform_hotel_id' => $platformHotelId,
                    'profile_binding_digest' => $profileHash,
                    'device_binding_digest' => (string)($singleUserLocalBinding['device_binding_digest'] ?? ''),
                    'collector_bound_at' => (string)($singleUserLocalBinding['bound_at'] ?? ''),
                    'ingestion_method' => $ingestionMethod,
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
                : null);
        $executionBindingStatus = $execution !== [] || $profileSchedulerBound
            ? 'bound'
            : (count($executionCandidates) === 0 ? 'missing' : 'conflict');
        $resumeScope = $profileSchedulerBound && $execution === []
            ? 'same_bound_local_profile_owner_hotel_platform'
            : 'same_account_same_device_same_hotel_same_platform';

        return [
            'platform' => $platform,
            'status' => $status,
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'source_id' => $sourceId > 0 ? $sourceId : null,
            'designated_source_id' => $designatedSourceId > 0 ? $designatedSourceId : null,
            'candidate_source_ids' => array_values(array_map(
                static fn(array $row): int => (int)($row['id'] ?? 0),
                $candidates
            )),
            'unselected_active_source_ids' => $designatedSourceId > 0
                ? array_values(array_map(
                    static fn(array $row): int => (int)($row['id'] ?? 0),
                    array_filter(
                        $candidates,
                        static fn(array $row): bool => (int)($row['id'] ?? 0) !== $designatedSourceId
                    )
                ))
                : [],
            'execution_owner_user_id' => $sourceOwnerUserId > 0 ? $sourceOwnerUserId : null,
            'ingestion_method' => $ingestionMethod !== '' ? $ingestionMethod : null,
            'platform_hotel_id' => $platformHotelId !== '' ? $platformHotelId : null,
            'legacy_platform_hotel_id_candidate' => count($legacyCandidates) === 1
                ? $legacyCandidates[0]
                : null,
            'identity_evidence' => [
                'status' => $platformHotelId !== '' && $identitySource !== '' && $this->timestamp($identityCheckedAt)
                    ? 'verified'
                    : 'unverified',
                'source' => $identitySource !== '' ? $this->safeCode($identitySource) : null,
                'checked_at' => $this->timestamp($identityCheckedAt) ? $identityCheckedAt : null,
            ],
            'profile_binding' => [
                'status' => $profileBindingStatus,
                'profile_binding_digest' => $profileHash !== '' ? $profileHash : null,
            ],
            'execution_device_binding' => [
                'status' => $executionBindingStatus,
                'binding_kind' => $executionBindingKind,
                'execution_binding_digest' => $executionBindingDigest,
                'device_binding_digest' => $execution !== []
                    ? (trim((string)($execution['device_binding_digest'] ?? '')) ?: null)
                    : ($profileSchedulerBound
                        ? (string)($singleUserLocalBinding['device_binding_digest'] ?? '')
                        : null),
                'device_status' => $execution !== [] ? $effectiveDeviceStatus : null,
                'account_status' => $execution !== []
                    ? (trim((string)($execution['account_status'] ?? '')) ?: null)
                    : null,
                'session_status' => $execution !== []
                    ? (trim((string)($execution['session_status'] ?? '')) ?: null)
                    : $profileSessionStatus,
                'last_seen_at' => $this->timestamp((string)($execution['last_seen_at'] ?? ''))
                    ? (string)$execution['last_seen_at']
                    : null,
                'automatic_device_substitution' => false,
                'resume_scope' => $resumeScope,
            ],
            'last_sync_status' => $this->safeCode((string)($source['last_sync_status'] ?? '')) ?: null,
            'last_sync_time' => $this->timestamp((string)($source['last_sync_time'] ?? ''))
                ? (string)$source['last_sync_time']
                : null,
            'blockers' => $blockers,
            'recovery_reasons' => $recoveryReasons,
        ];
    }

    /** @return array<string,mixed> */
    private function pmsBinding(
        int $tenantId,
        int $hotelId,
        int $actorUserId,
        string $businessDate
    ): array {
        try {
            $raw = $this->pmsBindingLoader === null
                ? (new HotelPmsBindingService())->status($tenantId, $hotelId, $actorUserId, $businessDate)
                : call_user_func($this->pmsBindingLoader, $tenantId, $hotelId, $actorUserId, $businessDate);
            $raw = is_array($raw) ? $raw : [];
        } catch (\Throwable $error) {
            return [
                'platform' => 'pms',
                'status' => 'blocked',
                'tenant_id' => $tenantId,
                'system_hotel_id' => $hotelId,
                'provider' => null,
                'provider_hotel_id' => null,
                'provider_hotel_name' => null,
                'blockers' => [$this->issue(
                    'pms_binding_read_failed',
                    'PMS binding could not be read for this hotel scope.',
                    'pms'
                )],
                'recovery_reasons' => [],
            ];
        }

        $blockers = [];
        $bindingStatus = strtolower(trim((string)($raw['binding_status'] ?? 'unconfigured')));
        $provider = strtolower(trim((string)($raw['selected_provider'] ?? '')));
        $selectedSource = is_array($raw['selected_source'] ?? null) ? $raw['selected_source'] : [];
        $config = is_array($selectedSource['config'] ?? null) ? $selectedSource['config'] : [];
        $providerHotelId = trim((string)($config['provider_hotel_id'] ?? ''));
        $providerHotelName = $this->safeText((string)($config['provider_hotel_name'] ?? ''), 160);
        if ($bindingStatus !== 'configured' || $provider === '') {
            $blockers[] = $this->issue(
                $bindingStatus === 'conflict' ? 'pms_binding_conflict' : 'pms_binding_missing',
                $bindingStatus === 'conflict'
                    ? 'More than one PMS provider is enabled for this hotel.'
                    : 'No single PMS provider is selected for this hotel.',
                'pms'
            );
        }
        if ($provider !== '' && ($providerHotelId === '' || $providerHotelName === '')) {
            $blockers[] = $this->issue(
                'pms_platform_hotel_identity_missing',
                'Selected PMS has no complete provider hotel identity.',
                'pms'
            );
        }
        if ($provider !== '' && $providerHotelId !== '') {
            [$owners, $ownerReadError] = $this->safeRows(
                fn(): array => $this->loadIdentityOwners('pms', $provider, $providerHotelId)
            );
            if ($ownerReadError !== '') {
                $blockers[] = $this->issue('pms_platform_hotel_uniqueness_unverified', $ownerReadError, 'pms');
            } else {
                $foreignOwners = array_values(array_filter(
                    $owners,
                    static fn(array $owner): bool => (int)($owner['tenant_id'] ?? 0) !== $tenantId
                        || (int)($owner['system_hotel_id'] ?? 0) !== $hotelId
                ));
                if ($foreignOwners !== []) {
                    $blockers[] = $this->issue(
                        'pms_platform_hotel_identity_cross_hotel_conflict',
                        'The PMS provider hotel identity is active in another hotel scope.',
                        'pms'
                    );
                }
            }
        }
        $blockers = $this->uniqueIssues($blockers);

        return [
            'platform' => 'pms',
            'status' => $blockers === [] ? 'ready' : 'blocked',
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'provider' => $provider !== '' ? $provider : null,
            'provider_hotel_id' => $providerHotelId !== '' ? $providerHotelId : null,
            'provider_hotel_name' => $providerHotelName !== '' ? $providerHotelName : null,
            'last_capture_business_date' => $this->dateOrNull((string)($config['last_capture_business_date'] ?? '')),
            'last_capture_status' => $this->safeCode((string)($config['last_capture_status'] ?? '')) ?: null,
            'last_readback_status' => $this->safeCode((string)($config['last_readback_status'] ?? '')) ?: null,
            'blockers' => $blockers,
            'recovery_reasons' => [],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function loadSources(int $tenantId, int $hotelId): array
    {
        if ($this->sourceLoader !== null) {
            $rows = call_user_func($this->sourceLoader, $tenantId, $hotelId);
            return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
        }
        return Db::name('platform_data_sources')
            ->field('id,tenant_id,user_id,system_hotel_id,platform,ingestion_method,enabled,status,last_sync_status,last_sync_time,config_json')
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->whereIn('platform', self::PLATFORMS)
            ->order('platform,id')
            ->select()
            ->toArray();
    }

    /** @return array<int,array<string,mixed>> */
    private function loadProfileBindings(int $tenantId, int $hotelId): array
    {
        if ($this->profileBindingLoader !== null) {
            $rows = call_user_func($this->profileBindingLoader, $tenantId, $hotelId);
            return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
        }
        return Db::name('ota_profile_bindings')
            ->field('id,tenant_id,system_hotel_id,platform,profile_key_hash,binding_status')
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->whereIn('platform', self::PLATFORMS)
            ->order('platform,id')
            ->select()
            ->toArray();
    }

    /** @return array<int,array<string,mixed>> */
    private function loadLocalExecutionBindings(int $tenantId, int $hotelId): array
    {
        if ($this->localExecutionBindingLoader !== null) {
            $rows = call_user_func($this->localExecutionBindingLoader, $tenantId, $hotelId);
            return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
        }
        $mappings = Db::name('ota_local_collector_account_hotels')
            ->field('id,tenant_id,account_id,system_hotel_id,platform,platform_hotel_id,data_source_id,status')
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->whereIn('platform', self::PLATFORMS)
            ->order('platform,id')
            ->select()
            ->toArray();
        $rows = [];
        foreach ($mappings as $mapping) {
            $account = Db::name('ota_local_collector_accounts')
                ->field('id,tenant_id,user_id,device_id,platform,profile_key_hash,status,session_status,last_error_code,last_session_verified_at')
                ->where('id', (int)($mapping['account_id'] ?? 0))
                ->find();
            $account = is_array($account) ? $account : [];
            $device = Db::name('ota_local_collector_devices')
                ->field('id,tenant_id,user_id,device_public_id,status,last_seen_at,last_error_code')
                ->where('id', (int)($account['device_id'] ?? 0))
                ->find();
            $device = is_array($device) ? $device : [];
            $devicePublicId = trim((string)($device['device_public_id'] ?? ''));
            $rows[] = [
                'mapping_id' => (int)($mapping['id'] ?? 0),
                'tenant_id' => (int)($mapping['tenant_id'] ?? 0),
                'system_hotel_id' => (int)($mapping['system_hotel_id'] ?? 0),
                'platform' => strtolower(trim((string)($mapping['platform'] ?? ''))),
                'platform_hotel_id' => trim((string)($mapping['platform_hotel_id'] ?? '')),
                'data_source_id' => (int)($mapping['data_source_id'] ?? 0),
                'mapping_status' => strtolower(trim((string)($mapping['status'] ?? ''))),
                'account_id' => (int)($account['id'] ?? 0),
                'account_tenant_id' => (int)($account['tenant_id'] ?? 0),
                'account_user_id' => (int)($account['user_id'] ?? 0),
                'account_status' => strtolower(trim((string)($account['status'] ?? ''))),
                'session_status' => strtolower(trim((string)($account['session_status'] ?? ''))),
                'profile_key_hash' => strtolower(trim((string)($account['profile_key_hash'] ?? ''))),
                'last_error_code' => $this->safeCode((string)($account['last_error_code'] ?? '')),
                'device_id' => (int)($device['id'] ?? 0),
                'device_tenant_id' => (int)($device['tenant_id'] ?? 0),
                'device_user_id' => (int)($device['user_id'] ?? 0),
                'device_status' => strtolower(trim((string)($device['status'] ?? 'offline'))),
                'last_seen_at' => trim((string)($device['last_seen_at'] ?? '')),
                'device_binding_digest' => $devicePublicId !== '' ? hash('sha256', $devicePublicId) : '',
            ];
        }
        return $rows;
    }

    /** @return array<int,array<string,mixed>> */
    private function loadIdentityOwners(string $kind, string $platform, string $externalId): array
    {
        if ($this->identityOwnerLoader !== null) {
            $rows = call_user_func($this->identityOwnerLoader, $kind, $platform, $externalId);
            return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
        }
        if ($kind === 'pms') {
            $table = match ($platform) {
                HotelPmsBindingService::PROVIDER_DINGDANDAO => 'dingdandao_pms_integrations',
                HotelPmsBindingService::PROVIDER_MEITUAN_CLOUD => 'meituan_cloud_pms_integrations',
                default => throw new RuntimeException('pms_provider_unsupported'),
            };
            return array_map(
                static fn(array $row): array => [
                    'tenant_id' => (int)($row['tenant_id'] ?? 0),
                    'system_hotel_id' => (int)($row['hotel_id'] ?? 0),
                ],
                Db::name($table)
                    ->field('tenant_id,hotel_id')
                    ->where('provider_hotel_id', $externalId)
                    ->where('status', 1)
                    ->select()
                    ->toArray()
            );
        }

        $rows = Db::name('platform_data_sources')
            ->field('id,tenant_id,system_hotel_id,platform,enabled,status,config_json')
            ->where('platform', $platform)
            ->where('enabled', 1)
            ->select()
            ->toArray();
        $owners = [];
        foreach ($rows as $row) {
            if (strtolower(trim((string)($row['status'] ?? ''))) === 'disabled') {
                continue;
            }
            $config = $this->decodeConfig($row['config_json'] ?? null);
            $candidate = trim((string)($config['platform_hotel_id'] ?? ''));
            if ($candidate !== '' && hash_equals(strtolower($externalId), strtolower($candidate))) {
                $owners[] = [
                    'tenant_id' => (int)($row['tenant_id'] ?? 0),
                    'system_hotel_id' => (int)($row['system_hotel_id'] ?? 0),
                    'source_id' => (int)($row['id'] ?? 0),
                ];
            }
        }
        return $owners;
    }

    /** @return array{0:array<int,array<string,mixed>>,1:string} */
    private function safeRows(callable $loader): array
    {
        try {
            $rows = $loader();
            return [is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [], ''];
        } catch (\Throwable $error) {
            return [[], $this->safeCode($error->getMessage()) ?: 'read_failed'];
        }
    }

    /** @return array<string,mixed> */
    private function decodeConfig(mixed $value): array
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

    /** @param array<string,mixed> $config @return array<int,string> */
    private function legacyIdentityCandidates(string $platform, array $config): array
    {
        $keys = $platform === 'meituan'
            ? ['store_id', 'storeId', 'poi_id', 'poiId']
            : ['hotel_id', 'hotelId', 'ctrip_hotel_id', 'ctripHotelId', 'node_id', 'nodeId'];
        $values = [];
        foreach ($keys as $key) {
            $value = trim((string)($config[$key] ?? ''));
            if ($value !== '') {
                $values[strtolower($value)] = $value;
            }
        }
        return array_values($values);
    }

    /**
     * @param array<int,array<string,mixed>> $sources
     * @return array<int,string>
     */
    private function candidateLegacyIdentityCandidates(string $platform, array $sources): array
    {
        $values = [];
        foreach ($sources as $source) {
            foreach ($this->legacyIdentityCandidates(
                $platform,
                $this->decodeConfig($source['config_json'] ?? null)
            ) as $candidate) {
                $values[strtolower($candidate)] = $candidate;
            }
        }
        return array_values($values);
    }

    /** @param array<string,mixed> $config */
    private function sourceProfileHash(string $platform, array $config, string $ingestionMethod = ''): string
    {
        if ($ingestionMethod === 'local_collector') {
            $profileKeyHash = strtolower(trim((string)($config['profile_key_hash'] ?? '')));
            return preg_match('/^[a-f0-9]{64}$/D', $profileKeyHash) === 1 ? $profileKeyHash : '';
        }
        $keys = $platform === 'meituan'
            ? ['store_id', 'storeId', 'poi_id', 'poiId', 'profile_id', 'profileId']
            : ['profile_id', 'profileId', 'browser_profile_id', 'browserProfileId'];
        foreach ($keys as $key) {
            $value = trim((string)($config[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            $canonical = BrowserProfileCaptureRequestService::safeFilePart($value);
            return $canonical !== '' ? hash('sha256', $canonical) : '';
        }
        return '';
    }

    /**
     * Server-local browser Profiles are an explicit compatibility mode for the
     * server owner's own device. Merely having an active Profile row is not an
     * execution binding: every persisted device/scope field must be complete
     * and self-consistent. The raw device id is validated here but never
     * returned by this receipt.
     *
     * @param array<string,mixed> $source
     * @param array<string,mixed> $config
     * @return array{declared:bool,complete:bool,device_binding_digest:?string,bound_at:?string}
     */
    private function singleUserLocalProfileBinding(
        array $source,
        array $config,
        int $tenantId,
        int $hotelId,
        string $platform,
        int $sourceOwnerUserId
    ): array {
        $sourceMethod = strtolower(trim((string)($config['source_method'] ?? '')));
        $bindingMode = strtolower(trim((string)($config['collector_binding_mode'] ?? '')));
        $declared = $sourceMethod === 'single_user_local'
            || $bindingMode === 'single_user_local';
        if (!$declared) {
            return [
                'declared' => false,
                'complete' => false,
                'device_binding_digest' => null,
                'bound_at' => null,
            ];
        }

        $deviceId = trim((string)($config['collector_device_id'] ?? ''));
        $deviceHash = strtolower(trim((string)($config['collector_device_id_hash'] ?? '')));
        $boundAt = trim((string)($config['collector_bound_at'] ?? ''));
        $complete = $sourceMethod === 'single_user_local'
            && $bindingMode === 'single_user_local'
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/D', $deviceId) === 1
            && preg_match('/^[a-f0-9]{64}$/D', $deviceHash) === 1
            && hash_equals(hash('sha256', $deviceId), $deviceHash)
            && $sourceOwnerUserId > 0
            && (int)($config['collector_user_id'] ?? 0) === $sourceOwnerUserId
            && (int)($config['collector_tenant_id'] ?? 0) === $tenantId
            && (int)($config['collector_hotel_id'] ?? 0) === $hotelId
            && strtolower(trim((string)($config['collector_platform'] ?? ''))) === $platform
            && (int)($source['tenant_id'] ?? 0) === $tenantId
            && (int)($source['system_hotel_id'] ?? 0) === $hotelId
            && strtolower(trim((string)($source['platform'] ?? ''))) === $platform
            && $this->timestamp($boundAt);

        return [
            'declared' => true,
            'complete' => $complete,
            'device_binding_digest' => $complete ? $deviceHash : null,
            'bound_at' => $complete ? $boundAt : null,
        ];
    }

    /** @param array<string,mixed> $source @return array<string,mixed> */
    private function profileSessionState(array $source): array
    {
        try {
            $state = $this->profileSessionStateLoader !== null
                ? call_user_func($this->profileSessionStateLoader, $source)
                : (new OtaProfileSessionProofService())->profileReuseState($source);
            return is_array($state) ? $state : [];
        } catch (\Throwable) {
            return [
                'status' => 'unverified',
                'is_reusable' => false,
                'reason' => 'profile_proof_unverified',
            ];
        }
    }

    private function executionOwnerPermitted(int $tenantId, int $hotelId, int $userId): bool
    {
        if ($this->executionOwnerPermissionLoader !== null) {
            return call_user_func(
                $this->executionOwnerPermissionLoader,
                $tenantId,
                $hotelId,
                $userId
            ) === true;
        }
        $user = User::find($userId);
        if (!$user instanceof User
            || (int)($user->status ?? 0) !== 1
            || !$this->executionOwnerTenantCompatible(
                (int)($user->tenant_id ?? 0),
                $tenantId,
                $user->isSuperAdmin()
            )
        ) {
            return false;
        }
        if ((int)($user->tenant_id ?? 0) === $tenantId) {
            return (new HotelScopeService())->canAccessHotel($user, $hotelId);
        }

        // A cross-tenant super admin is the sole compatibility exception and
        // still needs the exact active, unexpired hotel fetch grant used by
        // the explicit single_user_local binding flow.
        $hotel = Db::name('hotels')
            ->field('id')
            ->where('id', $hotelId)
            ->where('tenant_id', $tenantId)
            ->where('status', 1)
            ->find();
        if (!is_array($hotel) || (int)($hotel['id'] ?? 0) !== $hotelId) {
            return false;
        }

        $now = $this->now()->format('Y-m-d H:i:s');
        $grant = Db::name('user_hotel_permissions')
            ->field('id')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('hotel_id', $hotelId)
            ->where('can_fetch_online_data', 1)
            ->whereIn('status', ['active', '1', 1])
            ->where(static function ($query) use ($now): void {
                $query->whereNull('expires_at')->whereOr('expires_at', '>', $now);
            })
            ->find();
        return is_array($grant) && (int)($grant['id'] ?? 0) > 0;
    }

    private function executionOwnerTenantCompatible(
        int $userTenantId,
        int $hotelTenantId,
        bool $isSuperAdmin
    ): bool
    {
        return $userTenantId === $hotelTenantId || $isSuperAdmin;
    }

    /** @param array<string,mixed> $execution */
    private function effectiveDeviceStatus(array $execution): string
    {
        $storedStatus = strtolower(trim((string)($execution['device_status'] ?? '')));
        if (in_array($storedStatus, ['revoked', 'disabled'], true)) {
            return $storedStatus;
        }
        $lastSeenAt = strtotime((string)($execution['last_seen_at'] ?? ''));
        return $lastSeenAt !== false
            && $lastSeenAt >= $this->now()->getTimestamp() - self::LOCAL_DEVICE_ONLINE_SECONDS
                ? 'online'
                : 'device_offline';
    }

    private function now(): DateTimeImmutable
    {
        if ($this->clock === null) {
            return new DateTimeImmutable('now');
        }
        $value = call_user_func($this->clock);
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (is_int($value)) {
            return (new DateTimeImmutable())->setTimestamp($value);
        }
        if (is_string($value) && trim($value) !== '') {
            return new DateTimeImmutable($value);
        }
        throw new RuntimeException('hotel_collection_binding_clock_invalid');
    }

    /** @param array<string,mixed> $binding @return array<string,mixed> */
    private function bindingDigestFacts(array $binding): array
    {
        return [
            'platform' => (string)($binding['platform'] ?? ''),
            'tenant_id' => (int)($binding['tenant_id'] ?? 0),
            'system_hotel_id' => (int)($binding['system_hotel_id'] ?? 0),
            'source_id' => (int)($binding['source_id'] ?? 0),
            'platform_hotel_id' => (string)($binding['platform_hotel_id'] ?? ''),
            'provider' => (string)($binding['provider'] ?? ''),
            'provider_hotel_id' => (string)($binding['provider_hotel_id'] ?? ''),
            'profile_binding_digest' => (string)($binding['profile_binding']['profile_binding_digest'] ?? ''),
            'execution_owner_user_id' => (int)($binding['execution_owner_user_id'] ?? 0),
            'execution_binding_digest' => (string)($binding['execution_device_binding']['execution_binding_digest'] ?? ''),
            'device_binding_digest' => (string)($binding['execution_device_binding']['device_binding_digest'] ?? ''),
        ];
    }

    /** @return array{code:string,platform:string,message:string} */
    private function issue(string $code, string $message, string $platform = ''): array
    {
        return [
            'code' => $this->safeCode($code) ?: 'binding_error',
            'platform' => $this->safeCode($platform),
            'message' => $this->safeText($message, 220),
        ];
    }

    /** @param array<int,mixed> $issues @return array<int,array<string,string>> */
    private function uniqueIssues(array $issues): array
    {
        $result = [];
        foreach ($issues as $issue) {
            if (!is_array($issue)) {
                continue;
            }
            $normalized = $this->issue(
                (string)($issue['code'] ?? ''),
                (string)($issue['message'] ?? ''),
                (string)($issue['platform'] ?? '')
            );
            $key = $normalized['platform'] . ':' . $normalized['code'];
            $result[$key] = $normalized;
        }
        return array_values($result);
    }

    private function dateOrEmpty(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('hotel_collection_binding_date_invalid');
        }
        return $value;
    }

    /** @param array<int|string,mixed> $values @return array<string,int> */
    private function designatedSourceIds(array $values): array
    {
        $result = [];
        foreach (self::PLATFORMS as $platform) {
            $sourceId = (int)($values[$platform] ?? 0);
            if ($sourceId > 0) {
                $result[$platform] = $sourceId;
            }
        }
        return $result;
    }

    private function dateOrNull(string $value): ?string
    {
        try {
            return $this->dateOrEmpty($value) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function timestamp(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }
        try {
            new DateTimeImmutable($value);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function safeCode(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_.:-]+/', '_', $value) ?? '';
        return trim(substr($value, 0, 120), '_');
    }

    private function safeText(string $value, int $limit): string
    {
        $value = trim((string)(preg_replace('/\s+/u', ' ', $value) ?? $value));
        return function_exists('mb_substr')
            ? mb_substr($value, 0, $limit, 'UTF-8')
            : substr($value, 0, $limit);
    }
}
