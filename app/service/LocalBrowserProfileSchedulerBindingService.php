<?php
declare(strict_types=1);

namespace app\service;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use app\model\Role;
use think\facade\Db;

/**
 * Controlled writer for the server-local browser Profile scheduler binding.
 *
 * It never opens a Profile, probes an OTA, or returns raw device, Profile, or
 * platform-hotel identifiers. Preflight is read-only; execute revalidates the
 * complete scope under row locks and updates only the two exact source rows.
 */
final class LocalBrowserProfileSchedulerBindingService
{
    public const CONTRACT_VERSION = 'local_browser_profile_scheduler_binding.v1';

    private const TIMEZONE = 'Asia/Shanghai';
    private const BINDING_MODE = 'single_user_local';
    private const PLATFORMS = ['ctrip', 'meituan'];
    private const BINDING_KEYS = [
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

    private Closure $profileStateLoader;
    private Closure $clock;

    public function __construct(?callable $profileStateLoader = null, ?callable $clock = null)
    {
        $this->profileStateLoader = $profileStateLoader !== null
            ? Closure::fromCallable($profileStateLoader)
            : static fn(array $source): array => (new OtaProfileSessionProofService())->profileReuseState($source);
        $this->clock = $clock !== null
            ? Closure::fromCallable($clock)
            : static fn(): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE));
    }

    public static function executionConfirmation(
        int $tenantId,
        int $hotelId,
        int $userId,
        int $ctripSourceId,
        int $meituanSourceId
    ): string {
        return sprintf(
            'BIND LOCAL PROFILE SCHEDULER TENANT %d HOTEL %d USER %d CTRIP %d MEITUAN %d',
            $tenantId,
            $hotelId,
            $userId,
            $ctripSourceId,
            $meituanSourceId
        );
    }

    /** @return array<string,mixed> */
    public function preflight(
        int $tenantId,
        int $hotelId,
        int $userId,
        int $ctripSourceId,
        int $meituanSourceId,
        string $deviceId
    ): array {
        try {
            return $this->inspect(
                $tenantId,
                $hotelId,
                $userId,
                $ctripSourceId,
                $meituanSourceId,
                $deviceId,
                false,
                'preflight'
            )['receipt'];
        } catch (Throwable) {
            return $this->blocked(
                $this->baseReceipt($tenantId, $hotelId, $userId, $ctripSourceId, $meituanSourceId, 'preflight'),
                'local_profile_scheduler_binding_read_failed'
            );
        }
    }

    /** @return array<string,mixed> */
    public function execute(
        int $tenantId,
        int $hotelId,
        int $userId,
        int $ctripSourceId,
        int $meituanSourceId,
        string $deviceId
    ): array {
        try {
            return Db::transaction(function () use (
                $tenantId,
                $hotelId,
                $userId,
                $ctripSourceId,
                $meituanSourceId,
                $deviceId
            ): array {
                $inspection = $this->inspect(
                    $tenantId,
                    $hotelId,
                    $userId,
                    $ctripSourceId,
                    $meituanSourceId,
                    $deviceId,
                    true,
                    'execute'
                );
                $receipt = $inspection['receipt'];
                if (($receipt['binding_ready'] ?? false) !== true) {
                    return $receipt;
                }

                /** @var array<int,array<string,mixed>> $contexts */
                $contexts = $inspection['contexts'];
                $now = $this->now()->format('Y-m-d H:i:s');
                $affectedRows = 0;
                $readbackVerified = true;
                $sourceReceipts = [];

                foreach ($contexts as $context) {
                    $source = $context['source'];
                    $sourceId = (int)$source['id'];
                    $platform = (string)$source['platform'];
                    $beforeConfig = $context['config'];
                    $beforeRaw = (string)$source['config_json'];
                    $beforeSession = $this->currentSessionFields($beforeConfig);
                    $beforeSessionDigest = $this->digest($beforeSession);
                    $nextConfig = $beforeConfig;
                    $nextConfig['source_method'] = self::BINDING_MODE;
                    $nextConfig['collector_binding_mode'] = self::BINDING_MODE;
                    $nextConfig['collector_device_id'] = $deviceId;
                    $nextConfig['collector_device_id_hash'] = hash('sha256', $deviceId);
                    $nextConfig['collector_user_id'] = $userId;
                    $nextConfig['collector_tenant_id'] = $tenantId;
                    $nextConfig['collector_hotel_id'] = $hotelId;
                    $nextConfig['collector_platform'] = $platform;
                    $nextConfig['collector_bound_at'] = ($context['binding_status'] ?? '') === 'bound'
                        ? (string)$beforeConfig['collector_bound_at']
                        : $now;

                    $writeNeeded = ($context['write_needed'] ?? false) === true;
                    $affected = 0;
                    if ($writeNeeded) {
                        $affected = Db::name('platform_data_sources')
                            ->where('id', $sourceId)
                            ->where('tenant_id', $tenantId)
                            ->where('user_id', $userId)
                            ->where('system_hotel_id', $hotelId)
                            ->where('platform', $platform)
                            ->where('config_json', $beforeRaw)
                            ->update([
                                'config_json' => json_encode(
                                    $nextConfig,
                                    JSON_UNESCAPED_UNICODE
                                    | JSON_UNESCAPED_SLASHES
                                    | JSON_PRESERVE_ZERO_FRACTION
                                    | JSON_THROW_ON_ERROR
                                ),
                                'update_time' => $now,
                            ]);
                        if ($affected !== 1) {
                            throw new \RuntimeException('local_profile_scheduler_binding_exact_write_failed');
                        }
                        $affectedRows += $affected;
                    }

                    $readbackQuery = Db::name('platform_data_sources')
                        ->field('id,tenant_id,user_id,system_hotel_id,platform,ingestion_method,enabled,status,config_json')
                        ->where('id', $sourceId)
                        ->where('tenant_id', $tenantId)
                        ->where('user_id', $userId)
                        ->where('system_hotel_id', $hotelId)
                        ->where('platform', $platform);
                    if ($writeNeeded) {
                        $readbackQuery->lock(true);
                    }
                    $readback = $readbackQuery->find();
                    if (!is_array($readback)) {
                        throw new \RuntimeException('local_profile_scheduler_binding_readback_missing');
                    }
                    $readbackConfig = $this->decodeConfig($readback['config_json'] ?? null);
                    $sessionPreserved = $this->currentSessionFields($readbackConfig) === $beforeSession
                        && hash_equals($beforeSessionDigest, $this->digest($this->currentSessionFields($readbackConfig)));
                    $bindingVerified = $this->bindingMatches(
                        $readbackConfig,
                        $tenantId,
                        $hotelId,
                        $userId,
                        $platform,
                        $deviceId
                    );
                    $sourceReadbackVerified = $readbackConfig === $nextConfig
                        && $sessionPreserved
                        && $bindingVerified;
                    if (!$sourceReadbackVerified) {
                        throw new \RuntimeException('local_profile_scheduler_binding_readback_drift');
                    }
                    $readbackVerified = $readbackVerified && $sourceReadbackVerified;
                    $sourceReceipts[] = [
                        'platform' => $platform,
                        'data_source_id' => $sourceId,
                        'status' => 'bound',
                        'write_performed' => $affected === 1,
                        'current_session_preserved' => $sessionPreserved,
                        'current_session_digest' => $beforeSessionDigest,
                        'readback_verified' => $sourceReadbackVerified,
                    ];
                }

                $receipt['status'] = 'ready';
                $receipt['bound'] = true;
                $receipt['already_bound'] = $affectedRows === 0;
                $receipt['sources'] = $sourceReceipts;
                $receipt['write'] = [
                    'attempted' => true,
                    'performed' => $affectedRows > 0,
                    'affected_rows' => $affectedRows,
                    'readback_verified' => $readbackVerified,
                    'current_session_preserved' => $readbackVerified,
                    'idempotent' => $affectedRows === 0,
                ];
                $receipt['database_write_performed'] = $affectedRows > 0;
                $receipt['receipt_digest'] = $this->receiptDigest($receipt);
                return $receipt;
            });
        } catch (Throwable) {
            return $this->blocked(
                $this->baseReceipt($tenantId, $hotelId, $userId, $ctripSourceId, $meituanSourceId, 'execute'),
                'local_profile_scheduler_binding_write_failed'
            );
        }
    }

    /**
     * @return array{receipt:array<string,mixed>,contexts:array<int,array<string,mixed>>}
     */
    private function inspect(
        int $tenantId,
        int $hotelId,
        int $userId,
        int $ctripSourceId,
        int $meituanSourceId,
        string $deviceId,
        bool $lock,
        string $mode
    ): array {
        $receipt = $this->baseReceipt(
            $tenantId,
            $hotelId,
            $userId,
            $ctripSourceId,
            $meituanSourceId,
            $mode
        );
        $blockers = [];
        if ($tenantId <= 0
            || $hotelId <= 0
            || $userId <= 0
            || $ctripSourceId <= 0
            || $meituanSourceId <= 0
            || $ctripSourceId === $meituanSourceId
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/D', trim($deviceId)) !== 1
        ) {
            return [
                'receipt' => $this->blocked($receipt, 'local_profile_scheduler_binding_scope_invalid'),
                'contexts' => [],
            ];
        }
        $deviceId = trim($deviceId);

        $hotelQuery = Db::name('hotels')->field('id,tenant_id,status')->where('id', $hotelId);
        if ($lock) {
            $hotelQuery->lock(true);
        }
        $hotel = $hotelQuery->find();
        if (!is_array($hotel)
            || (int)($hotel['tenant_id'] ?? 0) !== $tenantId
            || (int)($hotel['status'] ?? 0) !== 1
        ) {
            $blockers[] = $this->issue('local_profile_scheduler_hotel_scope_invalid');
        }

        $userQuery = Db::name('users')->field('id,tenant_id,role_id,status')->where('id', $userId);
        if ($lock) {
            $userQuery->lock(true);
        }
        $user = $userQuery->find();
        $userTenantId = is_array($user) ? (int)($user['tenant_id'] ?? 0) : 0;
        $superAdmin = is_array($user) && (int)($user['role_id'] ?? 0) === Role::SUPER_ADMIN;
        if (!is_array($user)
            || (int)($user['status'] ?? 0) !== 1
            || ($userTenantId !== $tenantId && !$superAdmin)
        ) {
            $blockers[] = $this->issue('local_profile_scheduler_user_scope_invalid');
        }

        if (!$this->hasActiveFetchGrant($tenantId, $hotelId, $userId, $lock)) {
            $blockers[] = $this->issue('local_profile_scheduler_fetch_permission_missing');
        }

        $sourceIds = [$ctripSourceId, $meituanSourceId];
        sort($sourceIds, SORT_NUMERIC);
        $sourceQuery = Db::name('platform_data_sources')
            ->field('id,tenant_id,user_id,system_hotel_id,platform,ingestion_method,enabled,status,config_json')
            ->whereIn('id', $sourceIds);
        if ($lock) {
            $sourceQuery->lock(true);
        }
        $rows = $sourceQuery->select()->toArray();
        $rowsById = [];
        foreach ($rows as $row) {
            $rowsById[(int)($row['id'] ?? 0)] = $row;
        }
        $expected = [
            'ctrip' => $ctripSourceId,
            'meituan' => $meituanSourceId,
        ];
        $contexts = [];
        $sourceReceipts = [];

        foreach ($expected as $platform => $sourceId) {
            $sourceBlockers = [];
            $source = $rowsById[$sourceId] ?? null;
            if (!is_array($source)
                || (int)($source['tenant_id'] ?? 0) !== $tenantId
                || (int)($source['user_id'] ?? 0) !== $userId
                || (int)($source['system_hotel_id'] ?? 0) !== $hotelId
                || strtolower(trim((string)($source['platform'] ?? ''))) !== $platform
                || strtolower(trim((string)($source['ingestion_method'] ?? ''))) !== 'browser_profile'
                || (int)($source['enabled'] ?? 0) !== 1
                || strtolower(trim((string)($source['status'] ?? ''))) === 'disabled'
            ) {
                $sourceBlockers[] = $this->issue('local_profile_scheduler_source_scope_invalid', $platform);
            }

            $config = [];
            if (is_array($source)) {
                try {
                    $config = $this->decodeConfig($source['config_json'] ?? null);
                } catch (Throwable) {
                    $sourceBlockers[] = $this->issue('local_profile_scheduler_source_config_invalid', $platform);
                }
            }

            $platformHotelId = trim((string)($config['platform_hotel_id'] ?? ''));
            $identitySource = trim((string)($config['platform_hotel_identity_source'] ?? ''));
            $identityCheckedAt = trim((string)($config['platform_hotel_identity_checked_at'] ?? ''));
            if ($platformHotelId === '' || $identitySource === '' || !$this->timestamp($identityCheckedAt)) {
                $sourceBlockers[] = $this->issue('local_profile_scheduler_canonical_identity_unverified', $platform);
            }

            $profileHash = $this->sourceProfileHash($platform, $config);
            if ($profileHash === '') {
                $sourceBlockers[] = $this->issue('local_profile_scheduler_source_profile_missing', $platform);
            }

            $profileQuery = Db::name('ota_profile_bindings')
                ->field('id,tenant_id,system_hotel_id,platform,profile_key_hash,binding_status')
                ->where('tenant_id', $tenantId)
                ->where('system_hotel_id', $hotelId)
                ->where('platform', $platform)
                ->where('binding_status', 'active');
            if ($lock) {
                $profileQuery->lock(true);
            }
            $profiles = $profileQuery->select()->toArray();
            if (count($profiles) !== 1) {
                $sourceBlockers[] = $this->issue(
                    count($profiles) === 0
                        ? 'local_profile_scheduler_profile_binding_missing'
                        : 'local_profile_scheduler_profile_binding_conflict',
                    $platform
                );
            } elseif ($profileHash === ''
                || !hash_equals(
                    $profileHash,
                    strtolower(trim((string)($profiles[0]['profile_key_hash'] ?? '')))
                )
            ) {
                $sourceBlockers[] = $this->issue('local_profile_scheduler_profile_binding_mismatch', $platform);
            }

            if ($platformHotelId !== ''
                && $this->hasCrossHotelCanonicalOwner(
                    $tenantId,
                    $hotelId,
                    $sourceId,
                    $platform,
                    $platformHotelId,
                    $lock
                )
            ) {
                $sourceBlockers[] = $this->issue('local_profile_scheduler_cross_hotel_identity_conflict', $platform);
            }

            if ($sourceBlockers === []
                && !$this->currentSessionScopeMatches(
                    $config,
                    $tenantId,
                    $hotelId,
                    $sourceId,
                    $platform,
                    $profileHash,
                    $platformHotelId
                )
            ) {
                $sourceBlockers[] = $this->issue('local_profile_scheduler_current_session_scope_drift', $platform);
            }

            $profileState = [];
            if ($sourceBlockers === []) {
                try {
                    $profileState = ($this->profileStateLoader)($source);
                } catch (Throwable) {
                    $profileState = [];
                }
                if (!is_array($profileState)
                    || ($profileState['is_reusable'] ?? null) !== true
                    || !in_array(
                        strtolower(trim((string)($profileState['status'] ?? ''))),
                        ['reusable', 'renewal_warning'],
                        true
                    )
                ) {
                    $sourceBlockers[] = $this->issue('local_profile_scheduler_profile_session_not_reusable', $platform);
                }
            }

            $bindingStatus = 'missing';
            $writeNeeded = true;
            if ($sourceBlockers === []) {
                $bindingInspection = $this->existingBinding(
                    $config,
                    $tenantId,
                    $hotelId,
                    $userId,
                    $platform,
                    $deviceId
                );
                $bindingStatus = $bindingInspection['status'];
                $writeNeeded = $bindingInspection['write_needed'];
                if ($bindingInspection['blocked']) {
                    $sourceBlockers[] = $this->issue(
                        'local_profile_scheduler_existing_binding_conflict',
                        $platform
                    );
                }
            }

            foreach ($sourceBlockers as $issue) {
                $blockers[] = $issue;
            }
            $sourceReceipts[] = [
                'platform' => $platform,
                'data_source_id' => $sourceId,
                'status' => $sourceBlockers === [] ? 'ready' : 'blocked',
                'canonical_identity' => $platformHotelId !== '' && $identitySource !== '' && $this->timestamp($identityCheckedAt)
                    ? 'verified'
                    : 'unverified',
                'profile_binding' => count($profiles) === 1 && $profileHash !== ''
                    && hash_equals($profileHash, strtolower(trim((string)($profiles[0]['profile_key_hash'] ?? ''))))
                    ? 'verified'
                    : 'unverified',
                'profile_reuse' => ($profileState['is_reusable'] ?? false) === true ? 'verified' : 'unverified',
                'current_session' => $sourceBlockers === [] ? 'verified' : 'unverified',
                'binding_status' => $bindingStatus,
                'write_needed' => $sourceBlockers === [] && $writeNeeded,
                'blockers' => $sourceBlockers,
            ];
            if ($sourceBlockers === [] && is_array($source)) {
                $source['platform'] = $platform;
                $contexts[] = [
                    'source' => $source,
                    'config' => $config,
                    'binding_status' => $bindingStatus,
                    'write_needed' => $writeNeeded,
                ];
            }
        }

        $blockers = $this->uniqueIssues($blockers);
        $ready = $blockers === [] && count($contexts) === 2;
        $receipt['status'] = $ready ? 'ready' : 'blocked';
        $receipt['binding_ready'] = $ready;
        $receipt['bound'] = $ready && !array_filter(
            $contexts,
            static fn(array $context): bool => ($context['write_needed'] ?? false) === true
        );
        $receipt['already_bound'] = $receipt['bound'];
        $receipt['authorization_mode'] = $superAdmin && $userTenantId !== $tenantId
            ? 'cross_tenant_super_admin_explicit_hotel_grant'
            : 'same_tenant_explicit_hotel_grant';
        $receipt['sources'] = $sourceReceipts;
        $receipt['blockers'] = $blockers;
        $receipt['write'] = [
            'attempted' => false,
            'performed' => false,
            'needed_sources' => count(array_filter(
                $contexts,
                static fn(array $context): bool => ($context['write_needed'] ?? false) === true
            )),
            'readback_verified' => false,
            'current_session_preserved' => false,
            'idempotent' => $ready && $receipt['already_bound'],
        ];
        $receipt['receipt_digest'] = $this->receiptDigest($receipt);

        return ['receipt' => $receipt, 'contexts' => $contexts];
    }

    private function hasActiveFetchGrant(int $tenantId, int $hotelId, int $userId, bool $lock): bool
    {
        $query = Db::name('user_hotel_permissions')
            ->field('id,tenant_id,user_id,hotel_id,can_fetch_online_data,status,expires_at')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('hotel_id', $hotelId)
            ->where('can_fetch_online_data', 1);
        if ($lock) {
            $query->lock(true);
        }
        $now = $this->now()->getTimestamp();
        foreach ($query->select()->toArray() as $row) {
            $status = strtolower(trim((string)($row['status'] ?? '')));
            $expiresAt = trim((string)($row['expires_at'] ?? ''));
            $expiry = $expiresAt === '' ? false : strtotime($expiresAt);
            if (in_array($status, ['active', '1'], true)
                && ($expiresAt === '' || ($expiry !== false && $expiry > $now))
            ) {
                return true;
            }
        }
        return false;
    }

    private function hasCrossHotelCanonicalOwner(
        int $tenantId,
        int $hotelId,
        int $sourceId,
        string $platform,
        string $platformHotelId,
        bool $lock
    ): bool {
        $query = Db::name('platform_data_sources')
            ->field('id,tenant_id,system_hotel_id,platform,ingestion_method,enabled,status,config_json')
            ->where('platform', $platform)
            ->whereIn('ingestion_method', ['browser_profile', 'profile_browser'])
            ->where('enabled', 1)
            ->where('status', '<>', 'disabled')
            ->where('id', '<>', $sourceId);
        if ($lock) {
            $query->lock(true);
        }
        foreach ($query->select()->toArray() as $row) {
            try {
                $config = $this->decodeConfig($row['config_json'] ?? null);
            } catch (Throwable) {
                continue;
            }
            $candidate = trim((string)($config['platform_hotel_id'] ?? ''));
            if ($candidate === '' || strtolower($candidate) !== strtolower($platformHotelId)) {
                continue;
            }
            if ((int)($row['tenant_id'] ?? 0) !== $tenantId
                || (int)($row['system_hotel_id'] ?? 0) !== $hotelId
            ) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $config */
    private function currentSessionScopeMatches(
        array $config,
        int $tenantId,
        int $hotelId,
        int $sourceId,
        string $platform,
        string $profileHash,
        string $platformHotelId
    ): bool {
        $probeAt = trim((string)($config['current_session_probe_at'] ?? ''));
        $probeDate = trim((string)($config['current_session_probe_date'] ?? ''));
        $probeTimestamp = $probeAt === '' ? false : strtotime($probeAt);
        return ($config['current_session_probe_performed'] ?? null) === true
            && ($config['current_session_verified'] ?? null) === true
            && strtolower(trim((string)($config['current_session_status'] ?? ''))) === 'verified'
            && (int)($config['current_session_probe_data_source_id'] ?? 0) === $sourceId
            && (int)($config['current_session_probe_tenant_id'] ?? 0) === $tenantId
            && (int)($config['current_session_probe_system_hotel_id'] ?? 0) === $hotelId
            && strtolower(trim((string)($config['current_session_probe_platform'] ?? ''))) === $platform
            && trim((string)($config['current_session_probe_timezone'] ?? '')) === self::TIMEZONE
            && trim((string)($config['current_session_probe_scope'] ?? '')) === 'same_data_source_profile_session'
            && preg_match('/^[a-f0-9]{64}$/D', $profileHash) === 1
            && hash_equals($profileHash, strtolower(trim((string)($config['current_session_probe_profile_key_hash'] ?? ''))))
            && $platformHotelId !== ''
            && hash_equals($platformHotelId, trim((string)($config['current_session_probe_platform_hotel_id'] ?? '')))
            && $probeTimestamp !== false
            && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $probeDate) === 1
            && date('Y-m-d', $probeTimestamp) === $probeDate
            && $probeDate === $this->now()->format('Y-m-d');
    }

    /**
     * @param array<string,mixed> $config
     * @return array{status:string,write_needed:bool,blocked:bool}
     */
    private function existingBinding(
        array $config,
        int $tenantId,
        int $hotelId,
        int $userId,
        string $platform,
        string $deviceId
    ): array {
        $present = array_filter(
            self::BINDING_KEYS,
            static fn(string $key): bool => array_key_exists($key, $config)
        );
        if ($present === []) {
            return ['status' => 'missing', 'write_needed' => true, 'blocked' => false];
        }
        if (!$this->bindingMatches($config, $tenantId, $hotelId, $userId, $platform, $deviceId)) {
            return ['status' => 'conflict', 'write_needed' => false, 'blocked' => true];
        }
        return ['status' => 'bound', 'write_needed' => false, 'blocked' => false];
    }

    /** @param array<string,mixed> $config */
    private function bindingMatches(
        array $config,
        int $tenantId,
        int $hotelId,
        int $userId,
        string $platform,
        string $deviceId
    ): bool {
        $deviceHash = strtolower(trim((string)($config['collector_device_id_hash'] ?? '')));
        $boundAt = trim((string)($config['collector_bound_at'] ?? ''));
        return strtolower(trim((string)($config['source_method'] ?? ''))) === self::BINDING_MODE
            && strtolower(trim((string)($config['collector_binding_mode'] ?? ''))) === self::BINDING_MODE
            && hash_equals($deviceId, trim((string)($config['collector_device_id'] ?? '')))
            && preg_match('/^[a-f0-9]{64}$/D', $deviceHash) === 1
            && hash_equals(hash('sha256', $deviceId), $deviceHash)
            && (int)($config['collector_user_id'] ?? 0) === $userId
            && (int)($config['collector_tenant_id'] ?? 0) === $tenantId
            && (int)($config['collector_hotel_id'] ?? 0) === $hotelId
            && strtolower(trim((string)($config['collector_platform'] ?? ''))) === $platform
            && $this->timestamp($boundAt);
    }

    /** @param array<string,mixed> $config */
    private function sourceProfileHash(string $platform, array $config): string
    {
        $keys = $platform === 'meituan'
            ? ['profile_binding_key', 'stable_profile_id', 'store_id', 'storeId', 'poi_id', 'poiId', 'profile_id', 'profileId']
            : ['profile_binding_key', 'stable_profile_id', 'profile_id', 'profileId', 'browser_profile_id', 'browserProfileId'];
        foreach ($keys as $key) {
            if (!is_scalar($config[$key] ?? null)) {
                continue;
            }
            $value = trim((string)$config[$key]);
            if ($value === '') {
                continue;
            }
            $canonical = BrowserProfileCaptureRequestService::safeFilePart($value);
            return $canonical !== '' && $canonical !== 'default' ? hash('sha256', $canonical) : '';
        }
        return '';
    }

    /** @return array<string,mixed> */
    private function decodeConfig(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || trim($raw) === '') {
            throw new \RuntimeException('local_profile_scheduler_source_config_invalid');
        }
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('local_profile_scheduler_source_config_invalid');
        }
        return $decoded;
    }

    /** @param array<string,mixed> $config @return array<string,mixed> */
    private function currentSessionFields(array $config): array
    {
        $fields = [];
        foreach ($config as $key => $value) {
            if (str_starts_with((string)$key, 'current_session_')) {
                $fields[(string)$key] = $value;
            }
        }
        ksort($fields, SORT_STRING);
        return $fields;
    }

    private function timestamp(string $value): bool
    {
        return trim($value) !== '' && strtotime($value) !== false;
    }

    private function now(): DateTimeImmutable
    {
        $now = ($this->clock)();
        if (!$now instanceof DateTimeImmutable) {
            throw new \RuntimeException('local_profile_scheduler_clock_invalid');
        }
        return $now->setTimezone(new DateTimeZone(self::TIMEZONE));
    }

    /** @param array<string,mixed> $value */
    private function digest(array $value): string
    {
        return hash('sha256', (string)json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        ));
    }

    /** @param array<string,mixed> $receipt */
    private function receiptDigest(array $receipt): string
    {
        unset($receipt['receipt_digest']);
        return $this->digest($receipt);
    }

    /** @return array<string,mixed> */
    private function baseReceipt(
        int $tenantId,
        int $hotelId,
        int $userId,
        int $ctripSourceId,
        int $meituanSourceId,
        string $mode
    ): array {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'mode' => $mode,
            'status' => 'blocked',
            'binding_ready' => false,
            'bound' => false,
            'already_bound' => false,
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'user_id' => $userId,
            'source_ids' => [
                'ctrip' => $ctripSourceId,
                'meituan' => $meituanSourceId,
            ],
            'platforms' => self::PLATFORMS,
            'authorization_mode' => null,
            'sources' => [],
            'blockers' => [],
            'write' => [
                'attempted' => false,
                'performed' => false,
                'readback_verified' => false,
                'current_session_preserved' => false,
            ],
            'database_write_performed' => false,
            'ota_collection_performed' => false,
            'profile_opened' => false,
            'sensitive_values_exposed' => false,
        ];
    }

    /** @param array<string,mixed> $receipt @return array<string,mixed> */
    private function blocked(array $receipt, string $code): array
    {
        $receipt['status'] = 'blocked';
        $receipt['binding_ready'] = false;
        $receipt['bound'] = false;
        $receipt['blockers'] = [$this->issue($code)];
        $receipt['database_write_performed'] = false;
        $receipt['receipt_digest'] = $this->receiptDigest($receipt);
        return $receipt;
    }

    /** @return array{code:string,platform?:string} */
    private function issue(string $code, string $platform = ''): array
    {
        $issue = ['code' => $code];
        if (in_array($platform, self::PLATFORMS, true)) {
            $issue['platform'] = $platform;
        }
        return $issue;
    }

    /** @param array<int,array<string,mixed>> $issues @return array<int,array<string,mixed>> */
    private function uniqueIssues(array $issues): array
    {
        $unique = [];
        foreach ($issues as $issue) {
            $key = (string)($issue['platform'] ?? '') . ':' . (string)($issue['code'] ?? '');
            $unique[$key] = $issue;
        }
        return array_values($unique);
    }
}
