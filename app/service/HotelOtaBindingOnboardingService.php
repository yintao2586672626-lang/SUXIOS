<?php
declare(strict_types=1);

namespace app\service;

use Closure;
use Throwable;
use think\facade\Db;

/**
 * Hotel-80 page orchestration for the two zero-collection OTA binding actions.
 *
 * This service does not accept a platform hotel id, device id, Profile, Cookie,
 * token, or arbitrary source id from the browser. It composes the existing
 * proof-gated identity and scheduler-binding writers and returns only a safe
 * action receipt. No OTA request or collector task is created here.
 */
final class HotelOtaBindingOnboardingService
{
    public const CONTRACT_VERSION = 'hotel_ota_binding_onboarding.v1';
    public const HOTEL_ID = 80;
    public const CTRIP_SOURCE_ID = 25;
    public const MEITUAN_SOURCE_ID = 68;
    public const ACTION_CLAIM_IDENTITY = 'claim_meituan_identity';
    public const ACTION_BIND_SCHEDULER = 'bind_local_profile_scheduler';

    private Closure $sourceScopeLoader;
    private Closure $identityPreflight;
    private Closure $identityExecutor;
    private Closure $bindingPreflight;
    private Closure $bindingExecutor;
    private Closure $deviceIdProvider;

    public function __construct(
        ?callable $sourceScopeLoader = null,
        ?callable $identityPreflight = null,
        ?callable $identityExecutor = null,
        ?callable $bindingPreflight = null,
        ?callable $bindingExecutor = null,
        ?callable $deviceIdProvider = null
    ) {
        $this->sourceScopeLoader = $sourceScopeLoader !== null
            ? Closure::fromCallable($sourceScopeLoader)
            : static fn(int $tenantId, int $hotelId): array => Db::name('platform_data_sources')
                ->field('id,tenant_id,user_id,system_hotel_id,platform,config_json')
                ->whereIn('id', [self::CTRIP_SOURCE_ID, self::MEITUAN_SOURCE_ID])
                ->where('tenant_id', $tenantId)
                ->where('system_hotel_id', $hotelId)
                ->select()
                ->toArray();
        $this->identityPreflight = $identityPreflight !== null
            ? Closure::fromCallable($identityPreflight)
            : static fn(int $tenantId, int $hotelId, int $sourceId): array =>
                (new OtaPlatformHotelIdentityClaimService())->preflight($tenantId, $hotelId, $sourceId);
        $this->identityExecutor = $identityExecutor !== null
            ? Closure::fromCallable($identityExecutor)
            : static fn(int $tenantId, int $hotelId, int $sourceId): array =>
                (new OtaPlatformHotelIdentityClaimService())->execute($tenantId, $hotelId, $sourceId);
        $this->bindingPreflight = $bindingPreflight !== null
            ? Closure::fromCallable($bindingPreflight)
            : static fn(
                int $tenantId,
                int $hotelId,
                int $userId,
                int $ctripSourceId,
                int $meituanSourceId,
                string $deviceId
            ): array => (new LocalBrowserProfileSchedulerBindingService())->preflight(
                $tenantId,
                $hotelId,
                $userId,
                $ctripSourceId,
                $meituanSourceId,
                $deviceId
            );
        $this->bindingExecutor = $bindingExecutor !== null
            ? Closure::fromCallable($bindingExecutor)
            : static fn(
                int $tenantId,
                int $hotelId,
                int $userId,
                int $ctripSourceId,
                int $meituanSourceId,
                string $deviceId
            ): array => (new LocalBrowserProfileSchedulerBindingService())->execute(
                $tenantId,
                $hotelId,
                $userId,
                $ctripSourceId,
                $meituanSourceId,
                $deviceId
            );
        $this->deviceIdProvider = $deviceIdProvider !== null
            ? Closure::fromCallable($deviceIdProvider)
            : static fn(array $rows, int $tenantId, int $hotelId, int $userId): string =>
                self::serverDeviceId($rows, $tenantId, $hotelId, $userId);
    }

    /** @return array<string,mixed> */
    public function preview(int $tenantId, int $hotelId): array
    {
        $base = $this->baseReceipt($tenantId, $hotelId);
        if ($tenantId <= 0 || $hotelId !== self::HOTEL_ID) {
            return $this->blocked($base, 'hotel_ota_binding_onboarding_scope_invalid');
        }

        try {
            $rows = ($this->sourceScopeLoader)($tenantId, $hotelId);
            $rows = is_array($rows) ? $rows : [];
        } catch (Throwable) {
            return $this->blocked($base, 'hotel_ota_binding_onboarding_source_read_failed');
        }

        $scope = $this->sourceScope($rows, $tenantId, $hotelId);
        if (($scope['verified'] ?? false) !== true) {
            return $this->blocked(
                $base,
                (string)($scope['failure_code'] ?? 'hotel_ota_binding_onboarding_source_scope_mismatch')
            );
        }

        $ownerUserId = (int)$scope['user_id'];
        try {
            $deviceId = trim((string)($this->deviceIdProvider)($rows, $tenantId, $hotelId, $ownerUserId));
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/D', $deviceId) !== 1) {
                return $this->blocked($base, 'hotel_ota_binding_onboarding_device_identity_invalid');
            }
            $identity = ($this->identityPreflight)(
                $tenantId,
                $hotelId,
                self::MEITUAN_SOURCE_ID
            );
            $binding = ($this->bindingPreflight)(
                $tenantId,
                $hotelId,
                $ownerUserId,
                self::CTRIP_SOURCE_ID,
                self::MEITUAN_SOURCE_ID,
                $deviceId
            );
        } catch (Throwable) {
            return $this->blocked($base, 'hotel_ota_binding_onboarding_preflight_failed');
        }

        $identity = is_array($identity) ? $identity : [];
        $binding = is_array($binding) ? $binding : [];
        $identityReady = ($identity['claim_ready'] ?? false) === true;
        $identityCanonical = ($identity['already_canonical'] ?? false) === true;
        $bindingReady = ($binding['binding_ready'] ?? false) === true;
        $bound = ($binding['bound'] ?? false) === true;
        $claimAllowed = $identityReady && !$identityCanonical;
        $bindAllowed = $bindingReady && !$bound;
        $identityIntent = $this->intentDigest(
            self::ACTION_CLAIM_IDENTITY,
            $tenantId,
            $hotelId,
            (string)($identity['receipt_digest'] ?? '')
        );
        $bindingIntent = $this->intentDigest(
            self::ACTION_BIND_SCHEDULER,
            $tenantId,
            $hotelId,
            (string)($binding['receipt_digest'] ?? '')
        );

        $status = $bound
            ? 'verified'
            : ($bindAllowed ? 'partial' : ($claimAllowed ? 'unverified' : 'blocked'));
        $reasons = $this->uniqueIssues([
            ...(is_array($identity['blockers'] ?? null) ? $identity['blockers'] : []),
            ...(is_array($binding['blockers'] ?? null) ? $binding['blockers'] : []),
        ]);

        $receipt = $base;
        $receipt['status'] = $status;
        $receipt['scope_verified'] = true;
        $receipt['execution_owner_verified'] = true;
        $receipt['execution_device'] = [
            'status' => $bound ? 'verified' : ($bindingReady ? 'ready' : 'unverified'),
            'fingerprint' => substr(hash('sha256', $deviceId), 0, 12),
            'raw_value_exposed' => false,
        ];
        $receipt['identity'] = $this->safeIdentityReceipt($identity);
        $receipt['scheduler_binding'] = $this->safeBindingReceipt($binding);
        $receipt['actions'] = [
            self::ACTION_CLAIM_IDENTITY => [
                'allowed' => $claimAllowed,
                'status' => $identityCanonical ? 'complete' : ($claimAllowed ? 'confirmation_required' : 'blocked'),
                'intent_digest' => $identityIntent,
                'requires_explicit_confirmation' => true,
            ],
            self::ACTION_BIND_SCHEDULER => [
                'allowed' => $bindAllowed,
                'status' => $bound ? 'complete' : ($bindAllowed ? 'confirmation_required' : 'blocked'),
                'intent_digest' => $bindingIntent,
                'requires_explicit_confirmation' => true,
            ],
        ];
        $receipt['action_required'] = $claimAllowed
            ? self::ACTION_CLAIM_IDENTITY
            : ($bindAllowed ? self::ACTION_BIND_SCHEDULER : null);
        $receipt['reason_codes'] = $reasons;
        $receipt['exact_readback_verified'] = $bound;
        $receipt['contract_digest'] = $this->digest([
            'scope' => [$tenantId, $hotelId, self::CTRIP_SOURCE_ID, self::MEITUAN_SOURCE_ID],
            'status' => $status,
            'identity_intent' => $identityIntent,
            'binding_intent' => $bindingIntent,
        ]);
        return $receipt;
    }

    /** @return array<string,mixed> */
    public function execute(
        int $tenantId,
        int $hotelId,
        string $action,
        string $expectedIntentDigest
    ): array {
        $before = $this->preview($tenantId, $hotelId);
        if (($before['scope_verified'] ?? false) !== true) {
            return $this->operationBlocked($before, $action, 'hotel_ota_binding_onboarding_scope_unverified');
        }
        if (!in_array($action, [self::ACTION_CLAIM_IDENTITY, self::ACTION_BIND_SCHEDULER], true)) {
            return $this->operationBlocked($before, $action, 'hotel_ota_binding_onboarding_action_invalid');
        }
        $actionPreview = is_array($before['actions'][$action] ?? null)
            ? $before['actions'][$action]
            : [];
        if (($actionPreview['allowed'] ?? false) !== true) {
            return $this->operationBlocked($before, $action, 'hotel_ota_binding_onboarding_action_not_ready');
        }
        $currentIntentDigest = strtolower(trim((string)($actionPreview['intent_digest'] ?? '')));
        $expectedIntentDigest = strtolower(trim($expectedIntentDigest));
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedIntentDigest) !== 1
            || $currentIntentDigest === ''
            || !hash_equals($currentIntentDigest, $expectedIntentDigest)
        ) {
            return $this->operationBlocked($before, $action, 'hotel_ota_binding_onboarding_preview_stale');
        }

        try {
            if ($action === self::ACTION_CLAIM_IDENTITY) {
                $actionReceipt = ($this->identityExecutor)(
                    $tenantId,
                    $hotelId,
                    self::MEITUAN_SOURCE_ID
                );
                $succeeded = is_array($actionReceipt)
                    && ($actionReceipt['status'] ?? '') === 'ready'
                    && ($actionReceipt['claim_ready'] ?? false) === true
                    && (($actionReceipt['claimed'] ?? false) === true
                        || ($actionReceipt['already_canonical'] ?? false) === true)
                    && ($actionReceipt['write']['readback_verified'] ?? false) === true;
            } else {
                $scope = $this->sourceScope(
                    ($this->sourceScopeLoader)($tenantId, $hotelId),
                    $tenantId,
                    $hotelId
                );
                if (($scope['verified'] ?? false) !== true) {
                    return $this->operationBlocked(
                        $before,
                        $action,
                        'hotel_ota_binding_onboarding_source_scope_mismatch'
                    );
                }
                $rows = ($this->sourceScopeLoader)($tenantId, $hotelId);
                $deviceId = trim((string)($this->deviceIdProvider)(
                    is_array($rows) ? $rows : [],
                    $tenantId,
                    $hotelId,
                    (int)$scope['user_id']
                ));
                $actionReceipt = ($this->bindingExecutor)(
                    $tenantId,
                    $hotelId,
                    (int)$scope['user_id'],
                    self::CTRIP_SOURCE_ID,
                    self::MEITUAN_SOURCE_ID,
                    $deviceId
                );
                $succeeded = is_array($actionReceipt)
                    && ($actionReceipt['status'] ?? '') === 'ready'
                    && ($actionReceipt['bound'] ?? false) === true
                    && ($actionReceipt['write']['readback_verified'] ?? false) === true;
            }
        } catch (Throwable) {
            return $this->operationBlocked($before, $action, 'hotel_ota_binding_onboarding_action_failed');
        }

        $actionReceipt = is_array($actionReceipt) ? $actionReceipt : [];
        if (!$succeeded) {
            $failureCode = (string)($actionReceipt['blockers'][0]['code'] ?? 'hotel_ota_binding_onboarding_action_failed');
            $blocked = $this->operationBlocked($before, $action, $failureCode);
            $blocked['operation']['action_receipt'] = $action === self::ACTION_CLAIM_IDENTITY
                ? $this->safeIdentityReceipt($actionReceipt)
                : $this->safeBindingReceipt($actionReceipt);
            return $blocked;
        }

        $after = $this->preview($tenantId, $hotelId);
        $after['operation'] = [
            'action' => $action,
            'outcome' => 'success',
            'failure_code' => null,
            'database_write_performed' => ($actionReceipt['database_write_performed'] ?? false) === true
                || ($actionReceipt['write']['performed'] ?? false) === true
                || (int)($actionReceipt['write']['affected_rows'] ?? 0) > 0,
            'exact_readback_verified' => ($actionReceipt['write']['readback_verified'] ?? false) === true,
            'ota_collection_performed' => false,
            'action_receipt' => $action === self::ACTION_CLAIM_IDENTITY
                ? $this->safeIdentityReceipt($actionReceipt)
                : $this->safeBindingReceipt($actionReceipt),
        ];
        return $after;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private static function serverDeviceId(array $rows, int $tenantId, int $hotelId, int $userId): string
    {
        $devices = [];
        foreach (['ctrip' => self::CTRIP_SOURCE_ID, 'meituan' => self::MEITUAN_SOURCE_ID] as $platform => $sourceId) {
            $matches = array_values(array_filter(
                $rows,
                static fn(array $row): bool => (int)($row['id'] ?? 0) === $sourceId
                    && (int)($row['tenant_id'] ?? 0) === $tenantId
                    && (int)($row['user_id'] ?? 0) === $userId
                    && (int)($row['system_hotel_id'] ?? 0) === $hotelId
                    && strtolower(trim((string)($row['platform'] ?? ''))) === $platform
            ));
            if (count($matches) !== 1) {
                $devices = [];
                break;
            }
            $rawConfig = $matches[0]['config_json'] ?? null;
            if (is_array($rawConfig)) {
                $config = $rawConfig;
            } elseif (is_string($rawConfig) && trim($rawConfig) !== '') {
                try {
                    $decoded = json_decode($rawConfig, true, 512, JSON_THROW_ON_ERROR);
                    $config = is_array($decoded) ? $decoded : [];
                } catch (Throwable) {
                    $config = [];
                }
            } else {
                $config = [];
            }
            $deviceId = trim((string)($config['collector_device_id'] ?? ''));
            $deviceHash = strtolower(trim((string)($config['collector_device_id_hash'] ?? '')));
            $boundAt = trim((string)($config['collector_bound_at'] ?? ''));
            if (strtolower(trim((string)($config['source_method'] ?? ''))) !== 'single_user_local'
                || strtolower(trim((string)($config['collector_binding_mode'] ?? ''))) !== 'single_user_local'
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/D', $deviceId) !== 1
                || preg_match('/^[a-f0-9]{64}$/D', $deviceHash) !== 1
                || !hash_equals(hash('sha256', $deviceId), $deviceHash)
                || (int)($config['collector_user_id'] ?? 0) !== $userId
                || (int)($config['collector_tenant_id'] ?? 0) !== $tenantId
                || (int)($config['collector_hotel_id'] ?? 0) !== $hotelId
                || strtolower(trim((string)($config['collector_platform'] ?? ''))) !== $platform
                || $boundAt === ''
                || strtotime($boundAt) === false
            ) {
                $devices = [];
                break;
            }
            $devices[] = $deviceId;
        }
        $devices = array_values(array_unique($devices));
        if (count($devices) === 1) {
            return $devices[0];
        }

        $host = strtolower(trim((string)gethostname()));
        $host = preg_replace('/[^a-z0-9._:-]+/', '-', $host) ?? '';
        $host = trim($host, '-');
        return 'suxios-local-' . ($host !== '' ? $host : 'hotel80');
    }

    /** @param array<int,array<string,mixed>> $rows @return array<string,mixed> */
    private function sourceScope(array $rows, int $tenantId, int $hotelId): array
    {
        $expected = ['ctrip' => self::CTRIP_SOURCE_ID, 'meituan' => self::MEITUAN_SOURCE_ID];
        $owners = [];
        foreach ($expected as $platform => $sourceId) {
            $matches = array_values(array_filter(
                $rows,
                static fn(array $row): bool => (int)($row['id'] ?? 0) === $sourceId
                    && (int)($row['tenant_id'] ?? 0) === $tenantId
                    && (int)($row['system_hotel_id'] ?? 0) === $hotelId
                    && strtolower(trim((string)($row['platform'] ?? ''))) === $platform
            ));
            if (count($matches) !== 1 || (int)($matches[0]['user_id'] ?? 0) <= 0) {
                return ['verified' => false, 'failure_code' => 'hotel_ota_binding_onboarding_source_scope_mismatch'];
            }
            $owners[] = (int)$matches[0]['user_id'];
        }
        $owners = array_values(array_unique($owners));
        if (count($owners) !== 1) {
            return ['verified' => false, 'failure_code' => 'hotel_ota_binding_onboarding_execution_owner_conflict'];
        }
        return ['verified' => true, 'user_id' => $owners[0]];
    }

    /** @return array<string,mixed> */
    private function baseReceipt(int $tenantId, int $hotelId): array
    {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'status' => 'blocked',
            'tenant_id' => max(0, $tenantId),
            'system_hotel_id' => max(0, $hotelId),
            'source_ids' => ['ctrip' => self::CTRIP_SOURCE_ID, 'meituan' => self::MEITUAN_SOURCE_ID],
            'scope_verified' => false,
            'execution_owner_verified' => false,
            'execution_device' => [
                'status' => 'unverified',
                'fingerprint' => null,
                'raw_value_exposed' => false,
            ],
            'identity' => [],
            'scheduler_binding' => [],
            'actions' => [],
            'action_required' => null,
            'reason_codes' => [],
            'exact_readback_verified' => false,
            'database_write_performed' => false,
            'ota_collection_performed' => false,
            'collector_task_created' => false,
            'profile_opened' => false,
            'sensitive_values_exposed' => false,
            'contract_digest' => null,
        ];
    }

    /** @param array<string,mixed> $receipt @return array<string,mixed> */
    private function blocked(array $receipt, string $code): array
    {
        $receipt['status'] = 'blocked';
        $receipt['reason_codes'] = [['code' => $this->safeCode($code)]];
        $receipt['contract_digest'] = $this->digest([
            'scope' => [$receipt['tenant_id'], $receipt['system_hotel_id']],
            'reason_codes' => $receipt['reason_codes'],
        ]);
        return $receipt;
    }

    /** @param array<string,mixed> $receipt @return array<string,mixed> */
    private function operationBlocked(array $receipt, string $action, string $code): array
    {
        $receipt['operation'] = [
            'action' => $action,
            'outcome' => 'blocked',
            'failure_code' => $this->safeCode($code),
            'database_write_performed' => false,
            'exact_readback_verified' => false,
            'ota_collection_performed' => false,
        ];
        return $receipt;
    }

    /** @param array<string,mixed> $receipt @return array<string,mixed> */
    private function safeIdentityReceipt(array $receipt): array
    {
        return [
            'contract_version' => (string)($receipt['contract_version'] ?? ''),
            'status' => (string)($receipt['status'] ?? 'blocked'),
            'claim_ready' => ($receipt['claim_ready'] ?? false) === true,
            'claimed' => ($receipt['claimed'] ?? false) === true,
            'already_canonical' => ($receipt['already_canonical'] ?? false) === true,
            'data_source_id' => self::MEITUAN_SOURCE_ID,
            'platform' => 'meituan',
            'identity_candidate_count' => (int)($receipt['identity_candidate_count'] ?? 0),
            'profile_binding' => is_array($receipt['profile_binding'] ?? null) ? $receipt['profile_binding'] : [],
            'current_session_proof' => is_array($receipt['current_session_proof'] ?? null) ? $receipt['current_session_proof'] : [],
            'ownership' => is_array($receipt['ownership'] ?? null) ? $receipt['ownership'] : [],
            'write' => is_array($receipt['write'] ?? null) ? $receipt['write'] : [],
            'blockers' => is_array($receipt['blockers'] ?? null) ? $receipt['blockers'] : [],
            'receipt_digest' => (string)($receipt['receipt_digest'] ?? ''),
            'sensitive_values_exposed' => false,
        ];
    }

    /** @param array<string,mixed> $receipt @return array<string,mixed> */
    private function safeBindingReceipt(array $receipt): array
    {
        return [
            'contract_version' => (string)($receipt['contract_version'] ?? ''),
            'status' => (string)($receipt['status'] ?? 'blocked'),
            'binding_ready' => ($receipt['binding_ready'] ?? false) === true,
            'bound' => ($receipt['bound'] ?? false) === true,
            'already_bound' => ($receipt['already_bound'] ?? false) === true,
            'source_ids' => ['ctrip' => self::CTRIP_SOURCE_ID, 'meituan' => self::MEITUAN_SOURCE_ID],
            'sources' => is_array($receipt['sources'] ?? null) ? $receipt['sources'] : [],
            'authorization_mode' => (string)($receipt['authorization_mode'] ?? ''),
            'write' => is_array($receipt['write'] ?? null) ? $receipt['write'] : [],
            'blockers' => is_array($receipt['blockers'] ?? null) ? $receipt['blockers'] : [],
            'database_write_performed' => ($receipt['database_write_performed'] ?? false) === true,
            'receipt_digest' => (string)($receipt['receipt_digest'] ?? ''),
            'sensitive_values_exposed' => false,
        ];
    }

    /** @param array<int,mixed> $issues @return array<int,array<string,mixed>> */
    private function uniqueIssues(array $issues): array
    {
        $result = [];
        $seen = [];
        foreach ($issues as $issue) {
            if (!is_array($issue)) {
                continue;
            }
            $code = $this->safeCode((string)($issue['code'] ?? ''));
            if ($code === 'hotel_ota_binding_onboarding_blocked' && trim((string)($issue['code'] ?? '')) === '') {
                continue;
            }
            $platform = strtolower(trim((string)($issue['platform'] ?? '')));
            $key = $platform . ':' . $code;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $row = ['code' => $code];
            if (in_array($platform, ['ctrip', 'meituan'], true)) {
                $row['platform'] = $platform;
            }
            $result[] = $row;
        }
        return $result;
    }

    private function safeCode(string $code): string
    {
        $code = strtolower(trim($code));
        return preg_match('/^[a-z0-9_]{1,120}$/D', $code) === 1
            ? $code
            : 'hotel_ota_binding_onboarding_blocked';
    }

    private function intentDigest(
        string $action,
        int $tenantId,
        int $hotelId,
        string $upstreamReceiptDigest
    ): string {
        return $this->digest([
            'contract_version' => self::CONTRACT_VERSION,
            'action' => $action,
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'source_ids' => [self::CTRIP_SOURCE_ID, self::MEITUAN_SOURCE_ID],
            'upstream_receipt_digest' => $upstreamReceiptDigest,
        ]);
    }

    private function digest(mixed $value): string
    {
        return hash('sha256', (string)json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }
}
