<?php
declare(strict_types=1);

namespace app\service;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use FilesystemIterator;
use RuntimeException;
use think\facade\Db;

final class OtaProfileRetentionService
{
    public const DEFAULT_RETENTION_DAYS = 30;

    private const TIMEZONE = 'Asia/Shanghai';
    private const PROFILE_METHODS = ['browser_profile', 'profile_browser'];
    private const ACTIVE_TASK_STATUSES = [
        'queued', 'pending', 'running', 'retrying', 'browser_opened', 'syncing', 'syncing_after_login',
    ];
    private const SUCCESS_ACTIVITY_STATUSES = [
        'success', 'partial_success', 'no_data', 'authorized', 'logged_in',
    ];
    private const SUCCESS_TASK_ACTIVITY_STATUSES = ['success', 'partial_success', 'no_data'];
    private const TERMINAL_MANUAL_TASK_STATUSES = [
        'success', 'partial_success', 'failed', 'no_data', 'unverified',
    ];
    private const PROFILE_CACHE_PATHS = [
        'Default/Cache',
        'Default/Code Cache',
        'Default/Service Worker/CacheStorage',
        'Default/Service Worker/ScriptCache',
        'Default/GPUCache',
        'Default/DawnGraphiteCache',
        'Default/DawnWebGPUCache',
        'GrShaderCache',
        'ShaderCache',
        'GraphiteDawnCache',
    ];
    private const PROFILE_ACTIVITY_FILES = [
        'Local State',
        'Default/Network/Cookies',
        'Default/Cookies',
        'Default/Preferences',
        'Default/Secure Preferences',
    ];
    private const PROFILE_ACTIVITY_DIRECTORIES = [
        'Default/Local Storage',
        'Default/IndexedDB',
        'Default/Session Storage',
        'Default/Sessions',
    ];
    private const AGED_ARTIFACT_DIRECTORIES = [
        'runtime/ctrip_capture',
        'runtime/meituan_capture',
        'runtime/platform_data_sources',
        'runtime/platform_profile_login',
        'runtime/ota_cookie_injection',
        'runtime/log',
        'runtime/auto_fetch_tasks',
        'runtime/static-gzip',
        'runtime/static-html',
        'app/runtime/platform_profile_login',
        'storage/logs',
        'test-results',
        'reports/ctrip_capture_assets',
        'reports/meituan_capture_assets',
    ];
    private const AGED_REPORT_FILE_PATTERN = '/^(?:ctrip_browser_capture_|meituan_browser_capture_|ctrip_capture_target_|tmp_).+\.json$/i';

    private string $projectRoot;
    private string $storageRoot;
    private Closure $clock;
    private ?Closure $profileInUse;

    public function __construct(
        ?string $projectRoot = null,
        ?Closure $clock = null,
        ?Closure $profileInUse = null
    ) {
        $root = $projectRoot ?: dirname(__DIR__, 2);
        $resolved = realpath($root);
        $this->projectRoot = rtrim($resolved !== false ? $resolved : $root, "\\/");
        $this->storageRoot = $this->projectRoot . DIRECTORY_SEPARATOR . 'storage';
        $this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable(
            'now',
            new DateTimeZone(self::TIMEZONE)
        );
        $this->profileInUse = $profileInUse;
    }

    /** @return array<string, mixed> */
    public function cleanup(int $retentionDays = self::DEFAULT_RETENTION_DAYS, bool $dryRun = false): array
    {
        if ($retentionDays < 1 || $retentionDays > 3650) {
            throw new RuntimeException('OTA Profile retention days must be between 1 and 3650.');
        }

        $now = ($this->clock)();
        $cutoff = $now->modify('-' . $retentionDays . ' days');
        $result = [
            'retention_days' => $retentionDays,
            'cutoff_at' => $cutoff->format('Y-m-d H:i:s'),
            'dry_run' => $dryRun,
            'profiles_scanned' => 0,
            'profiles_expired' => 0,
            'profiles_removed' => 0,
            'profiles_kept' => 0,
            'profiles_skipped_in_use' => 0,
            'profiles_skipped_active_task' => 0,
            'sources_disabled' => 0,
            'bindings_revoked' => 0,
            'source_secrets_cleared' => 0,
            'orphan_metadata_groups_scanned' => 0,
            'orphan_metadata_groups_expired' => 0,
            'orphan_metadata_groups_cleaned' => 0,
            'orphan_metadata_groups_kept' => 0,
            'orphan_metadata_groups_skipped_active_task' => 0,
            'credentials_scanned' => 0,
            'credentials_expired' => 0,
            'credentials_revoked' => 0,
            'credentials_kept' => 0,
            'credentials_activity_unknown' => 0,
            'credentials_skipped_active_task' => 0,
            'credential_ciphertexts_cleared' => 0,
            'credential_sources_disabled' => 0,
            'credential_source_secrets_cleared' => 0,
            'profile_cache_targets_expired' => 0,
            'profile_cache_targets_removed' => 0,
            'artifact_files_expired' => 0,
            'artifact_files_removed' => 0,
            'orphan_locks_expired' => 0,
            'orphan_locks_removed' => 0,
            'bytes_reclaimable' => 0,
            'bytes_removed' => 0,
            'errors' => 0,
            'error_codes' => [],
            'items' => [],
        ];

        $metadata = null;
        try {
            $metadata = $this->loadProfileMetadata();
        } catch (\Throwable) {
            $result['errors']++;
            $result['error_codes'][] = 'profile_metadata_unavailable';
        }

        if (is_array($metadata)) {
            $seenIdentities = [];
            $this->cleanupProfiles($metadata, $now, $cutoff, $dryRun, $seenIdentities, $result);
            $this->cleanupOrphanedProfileMetadata(
                $metadata,
                $seenIdentities,
                $now,
                $cutoff,
                $dryRun,
                $result
            );
        }
        $this->cleanupDormantCredentials($now, $cutoff, $dryRun, $result);
        $this->cleanupAgedArtifacts($cutoff, $dryRun, $result);
        $this->cleanupOrphanedLocks($dryRun, $result);
        $result['error_codes'] = array_values(array_unique($result['error_codes']));

        return $result;
    }

    /** @return array{sources:array<string,list<array<string,mixed>>>,bindings:array<string,list<array<string,mixed>>>,active_source_ids:array<int,true>} */
    private function loadProfileMetadata(): array
    {
        $sourceRows = Db::name('platform_data_sources')
            ->field('id,tenant_id,system_hotel_id,platform,ingestion_method,enabled,status,last_sync_time,last_sync_status,config_json,secret_json,create_time')
            ->whereIn('platform', ['ctrip', 'meituan'])
            ->whereIn('ingestion_method', self::PROFILE_METHODS)
            ->select()
            ->toArray();
        $bindingRows = Db::name('ota_profile_bindings')
            ->field('id,tenant_id,system_hotel_id,platform,profile_key_hash,binding_status,create_time')
            ->whereIn('platform', ['ctrip', 'meituan'])
            ->select()
            ->toArray();

        $lastSuccessfulTaskBySource = [];
        $successfulTaskRows = Db::name('platform_data_sync_tasks')
            ->field('data_source_id,MAX(COALESCE(finished_at,update_time,started_at,create_time)) AS last_success_at')
            ->whereIn('status', self::SUCCESS_TASK_ACTIVITY_STATUSES)
            ->group('data_source_id')
            ->select()
            ->toArray();
        foreach ($successfulTaskRows as $row) {
            $sourceId = (int)($row['data_source_id'] ?? 0);
            if ($sourceId > 0) {
                $lastSuccessfulTaskBySource[$sourceId] = (string)($row['last_success_at'] ?? '');
            }
        }

        $sources = [];
        foreach ($sourceRows as $row) {
            $platform = $this->normalizePlatform((string)($row['platform'] ?? ''));
            $config = $this->decodeConfig((string)($row['config_json'] ?? ''));
            $profileKey = $this->sourceProfileKey($platform, $config);
            if ($platform === '' || $profileKey === '') {
                continue;
            }
            $row['_safe_config'] = $config;
            $row['_last_success_task_at'] = $lastSuccessfulTaskBySource[(int)($row['id'] ?? 0)] ?? '';
            $sources[$this->identity($platform, $profileKey)][] = $row;
        }

        $bindings = [];
        foreach ($bindingRows as $row) {
            $platform = $this->normalizePlatform((string)($row['platform'] ?? ''));
            $hash = strtolower(trim((string)($row['profile_key_hash'] ?? '')));
            if ($platform === '' || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                continue;
            }
            $bindings[$platform . ':' . $hash][] = $row;
        }

        $activeSourceIds = [];
        $activeTaskRows = Db::name('platform_data_sync_tasks')
            ->field('data_source_id')
            ->whereIn('status', self::ACTIVE_TASK_STATUSES)
            ->select()
            ->toArray();
        foreach ($activeTaskRows as $row) {
            $sourceId = (int)($row['data_source_id'] ?? 0);
            if ($sourceId > 0) {
                $activeSourceIds[$sourceId] = true;
            }
        }

        return ['sources' => $sources, 'bindings' => $bindings, 'active_source_ids' => $activeSourceIds];
    }

    /** @param array<string,mixed> $result */
    private function cleanupDormantCredentials(
        DateTimeImmutable $now,
        DateTimeImmutable $cutoff,
        bool $dryRun,
        array &$result
    ): void {
        try {
            $credentials = Db::name('ota_credentials')
                ->field('id,tenant_id,system_hotel_id,platform,config_id,encrypted_payload,secret_mask,credential_status,last_used_at,rotated_at,revoked_at,create_time')
                ->whereIn('platform', ['ctrip', 'meituan'])
                ->select()
                ->toArray();
        } catch (\Throwable) {
            $result['errors']++;
            $result['error_codes'][] = 'credential_schema_unavailable';
            return;
        }

        $result['credentials_scanned'] += count($credentials);
        $readyCredentials = array_values(array_filter(
            $credentials,
            static fn(array $row): bool => strtolower(trim((string)($row['credential_status'] ?? ''))) === 'ready'
        ));
        if ($readyCredentials === []) {
            return;
        }

        try {
            $activityGuard = $this->loadCredentialActivityGuard();
        } catch (\Throwable) {
            $result['errors']++;
            $result['error_codes'][] = 'credential_activity_guard_unavailable';
            $result['credentials_kept'] += count($readyCredentials);
            return;
        }

        foreach ($readyCredentials as $credential) {
            $activityAt = $this->credentialActivityAt($credential);
            $item = [
                'item_type' => 'credential',
                'credential_ref' => (int)($credential['id'] ?? 0),
                'platform' => $this->normalizePlatform((string)($credential['platform'] ?? '')),
                'system_hotel_id' => (int)($credential['system_hotel_id'] ?? 0),
                'last_used_at' => $activityAt?->format('Y-m-d H:i:s'),
                'age_days' => $activityAt instanceof DateTimeImmutable
                    ? max(0, (int)floor(($now->getTimestamp() - $activityAt->getTimestamp()) / 86400))
                    : null,
                'status' => 'kept',
                'reason' => 'within_retention_window',
            ];

            if (!$activityAt instanceof DateTimeImmutable) {
                $result['credentials_activity_unknown']++;
                $result['credentials_kept']++;
                $item['reason'] = 'credential_activity_unknown';
                $result['items'][] = $item;
                continue;
            }
            if ($activityAt >= $cutoff) {
                $result['credentials_kept']++;
                $result['items'][] = $item;
                continue;
            }

            $result['credentials_expired']++;
            $item['status'] = $dryRun ? 'would_revoke' : 'expired';
            $item['reason'] = 'credential_inactive_beyond_retention';
            if ($this->credentialHasActiveTask($credential, $activityGuard)) {
                $result['credentials_skipped_active_task']++;
                $result['credentials_kept']++;
                $item['status'] = 'skipped';
                $item['reason'] = 'credential_active_task';
                $result['items'][] = $item;
                continue;
            }
            if ($dryRun) {
                $result['credentials_kept']++;
                $result['items'][] = $item;
                continue;
            }

            try {
                $outcome = $this->revokeDormantCredential((int)$credential['id'], $cutoff);
            } catch (\Throwable) {
                $result['errors']++;
                $result['error_codes'][] = 'credential_cleanup_failed';
                $result['credentials_kept']++;
                $item['status'] = 'skipped';
                $item['reason'] = 'credential_cleanup_failed';
                $result['items'][] = $item;
                continue;
            }

            if (($outcome['status'] ?? '') === 'revoked') {
                $result['credentials_revoked']++;
                $result['credential_ciphertexts_cleared'] += (int)($outcome['ciphertexts_cleared'] ?? 0);
                $result['credential_sources_disabled'] += (int)($outcome['sources_disabled'] ?? 0);
                $result['credential_source_secrets_cleared'] += (int)($outcome['source_secrets_cleared'] ?? 0);
                $item['status'] = 'revoked';
            } else {
                $result['credentials_kept']++;
                $item['status'] = 'skipped';
                $item['reason'] = match ((string)($outcome['status'] ?? '')) {
                    'active_task' => 'credential_active_task',
                    'activity_unknown' => 'credential_activity_unknown',
                    'within_retention' => 'within_retention_window',
                    default => 'credential_state_changed',
                };
                if (($outcome['status'] ?? '') === 'active_task') {
                    $result['credentials_skipped_active_task']++;
                }
                if (($outcome['status'] ?? '') === 'activity_unknown') {
                    $result['credentials_activity_unknown']++;
                }
            }
            $result['items'][] = $item;
        }
    }

    /**
     * @return array{credential_refs:array<int,true>,locators:array<string,true>,scopes:array<string,true>}
     */
    private function loadCredentialActivityGuard(): array
    {
        $sourceRows = Db::name('platform_data_sources')
            ->field('id,system_hotel_id,platform,config_json')
            ->whereIn('platform', ['ctrip', 'meituan'])
            ->select()
            ->toArray();
        $sourcesById = [];
        foreach ($sourceRows as $source) {
            $sourceId = (int)($source['id'] ?? 0);
            if ($sourceId > 0) {
                $sourcesById[$sourceId] = $source;
            }
        }

        $guard = ['credential_refs' => [], 'locators' => [], 'scopes' => []];
        $activeTasks = Db::name('platform_data_sync_tasks')
            ->field('data_source_id,system_hotel_id,platform,status')
            ->whereIn('status', self::ACTIVE_TASK_STATUSES)
            ->select()
            ->toArray();
        foreach ($activeTasks as $task) {
            $sourceId = (int)($task['data_source_id'] ?? 0);
            $source = $sourceId > 0 ? ($sourcesById[$sourceId] ?? null) : null;
            $scopeRow = is_array($source) ? $source : $task;
            $hotelId = (int)($scopeRow['system_hotel_id'] ?? 0);
            $platform = $this->normalizePlatform((string)($scopeRow['platform'] ?? ''));
            if ($hotelId <= 0 || $platform === '') {
                throw new RuntimeException('Active OTA task scope is unavailable.');
            }

            if (!is_array($source)) {
                $guard['scopes'][$this->credentialScopeKey($hotelId, $platform)] = true;
                continue;
            }
            $config = $this->decodeConfig((string)($source['config_json'] ?? ''));
            $credentialRef = (int)($config['credential_ref'] ?? 0);
            $configId = trim((string)($config['config_id'] ?? $config['credential_config_id'] ?? ''));
            if ($credentialRef > 0) {
                $guard['credential_refs'][$credentialRef] = true;
            } elseif ($configId !== '') {
                $guard['locators'][$this->credentialLocatorKey($hotelId, $platform, $configId)] = true;
            } else {
                $guard['scopes'][$this->credentialScopeKey($hotelId, $platform)] = true;
            }
        }

        foreach ($this->loadActiveManualTaskScopes() as $scope => $_active) {
            $guard['scopes'][$scope] = true;
        }
        return $guard;
    }

    /** @return array<string,true> */
    private function loadActiveManualTaskScopes(): array
    {
        $driver = strtolower(trim((string)(getenv('SUXI_MANUAL_FETCH_TASK_STATUS_DRIVER') ?: 'database')));
        return match ($driver) {
            'database', 'mysql' => $this->loadActiveDatabaseManualTaskScopes(),
            'file' => $this->loadActiveFileManualTaskScopes(),
            default => throw new RuntimeException('Manual OTA task status driver is unsupported.'),
        };
    }

    /** @return array<string,true> */
    private function loadActiveDatabaseManualTaskScopes(): array
    {
        $rows = Db::name('manual_online_fetch_task_statuses')
            ->field('hotel_id,platform,status')
            ->whereNotIn('status', self::TERMINAL_MANUAL_TASK_STATUSES)
            ->select()
            ->toArray();
        $scopes = [];
        foreach ($rows as $row) {
            $hotelId = (int)($row['hotel_id'] ?? 0);
            $platform = $this->normalizePlatform((string)($row['platform'] ?? ''));
            if ($hotelId <= 0 || $platform === '') {
                throw new RuntimeException('Manual OTA task scope is unavailable.');
            }
            $scopes[$this->credentialScopeKey($hotelId, $platform)] = true;
        }
        return $scopes;
    }

    /** @return array<string,true> */
    private function loadActiveFileManualTaskScopes(): array
    {
        $root = $this->projectRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'manual_fetch_tasks';
        if (!file_exists($root)) {
            return [];
        }
        if (!is_dir($root) || is_link($root)) {
            throw new RuntimeException('Manual OTA task status root is unavailable.');
        }

        $scopes = [];
        foreach (new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS) as $entry) {
            if (!$entry->isDir()) {
                continue;
            }
            if ($entry->isLink()) {
                throw new RuntimeException('Manual OTA task status contains a link.');
            }
            $statusPath = $entry->getPathname() . DIRECTORY_SEPARATOR . 'status.json';
            if (!is_file($statusPath) || is_link($statusPath)) {
                throw new RuntimeException('Manual OTA task status is incomplete.');
            }
            $decoded = json_decode((string)file_get_contents($statusPath), true);
            if (!is_array($decoded)) {
                throw new RuntimeException('Manual OTA task status is invalid.');
            }
            $status = strtolower(trim((string)($decoded['status'] ?? 'queued')));
            if (in_array($status, self::TERMINAL_MANUAL_TASK_STATUSES, true)) {
                continue;
            }
            $hotelId = (int)($decoded['hotel_id'] ?? $decoded['system_hotel_id'] ?? 0);
            $platform = $this->normalizePlatform((string)($decoded['platform'] ?? ''));
            if ($hotelId <= 0 || $platform === '') {
                throw new RuntimeException('Manual OTA task scope is unavailable.');
            }
            $scopes[$this->credentialScopeKey($hotelId, $platform)] = true;
        }
        return $scopes;
    }

    /** @param array<string,mixed> $credential */
    private function credentialActivityAt(array $credential): ?DateTimeImmutable
    {
        foreach (['last_used_at', 'rotated_at', 'create_time'] as $field) {
            $value = $credential[$field] ?? null;
            if (!is_scalar($value) || trim((string)$value) === '') {
                continue;
            }
            try {
                return new DateTimeImmutable((string)$value, new DateTimeZone(self::TIMEZONE));
            } catch (\Throwable) {
                return null;
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $credential
     * @param array{credential_refs:array<int,true>,locators:array<string,true>,scopes:array<string,true>} $guard
     */
    private function credentialHasActiveTask(array $credential, array $guard): bool
    {
        $credentialRef = (int)($credential['id'] ?? 0);
        $hotelId = (int)($credential['system_hotel_id'] ?? 0);
        $platform = $this->normalizePlatform((string)($credential['platform'] ?? ''));
        $configId = trim((string)($credential['config_id'] ?? ''));
        return isset($guard['credential_refs'][$credentialRef])
            || isset($guard['locators'][$this->credentialLocatorKey($hotelId, $platform, $configId)])
            || isset($guard['scopes'][$this->credentialScopeKey($hotelId, $platform)]);
    }

    /** @return array{status:string,ciphertexts_cleared:int,sources_disabled:int,source_secrets_cleared:int} */
    private function revokeDormantCredential(int $credentialId, DateTimeImmutable $cutoff): array
    {
        return Db::transaction(function () use ($credentialId, $cutoff): array {
            $credential = Db::name('ota_credentials')->where('id', $credentialId)->lock(true)->find();
            if (!is_array($credential) || strtolower(trim((string)($credential['credential_status'] ?? ''))) !== 'ready') {
                return ['status' => 'state_changed', 'ciphertexts_cleared' => 0, 'sources_disabled' => 0, 'source_secrets_cleared' => 0];
            }
            $activityAt = $this->credentialActivityAt($credential);
            if (!$activityAt instanceof DateTimeImmutable) {
                return ['status' => 'activity_unknown', 'ciphertexts_cleared' => 0, 'sources_disabled' => 0, 'source_secrets_cleared' => 0];
            }
            if ($activityAt >= $cutoff) {
                return ['status' => 'within_retention', 'ciphertexts_cleared' => 0, 'sources_disabled' => 0, 'source_secrets_cleared' => 0];
            }
            $activityGuard = $this->loadCredentialActivityGuard();
            if ($this->credentialHasActiveTask($credential, $activityGuard)) {
                return ['status' => 'active_task', 'ciphertexts_cleared' => 0, 'sources_disabled' => 0, 'source_secrets_cleared' => 0];
            }

            $ciphertextCleared = (string)($credential['encrypted_payload'] ?? '') !== '' ? 1 : 0;
            $updated = (int)Db::name('ota_credentials')
                ->where('id', $credentialId)
                ->where('credential_status', 'ready')
                ->update([
                    'credential_status' => 'revoked',
                    'encrypted_payload' => '',
                    'secret_mask' => '',
                    'revoked_at' => date('Y-m-d H:i:s'),
                    'update_time' => date('Y-m-d H:i:s'),
                ]);
            if ($updated <= 0) {
                return ['status' => 'state_changed', 'ciphertexts_cleared' => 0, 'sources_disabled' => 0, 'source_secrets_cleared' => 0];
            }
            [$sourcesDisabled, $sourceSecretsCleared] = $this->disableCredentialDataSources($credential);
            return [
                'status' => 'revoked',
                'ciphertexts_cleared' => $ciphertextCleared,
                'sources_disabled' => $sourcesDisabled,
                'source_secrets_cleared' => $sourceSecretsCleared,
            ];
        });
    }

    /** @param array<string,mixed> $credential @return array{0:int,1:int} */
    private function disableCredentialDataSources(array $credential): array
    {
        $credentialId = (int)($credential['id'] ?? 0);
        $tenantId = (int)($credential['tenant_id'] ?? 0);
        $hotelId = (int)($credential['system_hotel_id'] ?? 0);
        $platform = $this->normalizePlatform((string)($credential['platform'] ?? ''));
        $configId = trim((string)($credential['config_id'] ?? ''));
        $sources = Db::name('platform_data_sources')
            ->field('id,enabled,status,last_sync_status,config_json,secret_json')
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('platform', $platform)
            ->select()
            ->toArray();

        $disabled = 0;
        $secretsCleared = 0;
        foreach ($sources as $source) {
            $config = $this->decodeConfig((string)($source['config_json'] ?? ''));
            $sourceCredentialRef = (int)($config['credential_ref'] ?? 0);
            $sourceConfigId = trim((string)($config['config_id'] ?? $config['credential_config_id'] ?? ''));
            if ($sourceCredentialRef !== $credentialId
                && !($sourceCredentialRef <= 0 && $sourceConfigId !== '' && hash_equals($configId, $sourceConfigId))
            ) {
                continue;
            }

            $secret = trim((string)($source['secret_json'] ?? ''));
            if ($secret !== '' && !in_array(strtolower($secret), ['null', '{}', '[]'], true)) {
                $secretsCleared++;
            }
            $config['credential_status'] = 'revoked';
            $config['status'] = 'revoked';
            $config['has_secret'] = false;
            $config['has_cookies'] = false;
            $config['cookie_configured'] = false;
            $disabled += (int)Db::name('platform_data_sources')->where('id', (int)$source['id'])->update([
                'enabled' => 0,
                'status' => 'disabled',
                'last_sync_status' => 'disabled',
                'last_error' => 'credential_retention_expired',
                'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'secret_json' => null,
                'update_time' => date('Y-m-d H:i:s'),
            ]);
        }
        return [$disabled, $secretsCleared];
    }

    private function credentialScopeKey(int $hotelId, string $platform): string
    {
        return $hotelId . ':' . $platform;
    }

    private function credentialLocatorKey(int $hotelId, string $platform, string $configId): string
    {
        return $this->credentialScopeKey($hotelId, $platform) . ':' . $configId;
    }

    /**
     * @param array{sources:array<string,list<array<string,mixed>>>,bindings:array<string,list<array<string,mixed>>>,active_source_ids:array<int,true>} $metadata
     * @param array<string,true> $seenIdentities
     * @param array<string, mixed> $result
     */
    private function cleanupProfiles(
        array $metadata,
        DateTimeImmutable $now,
        DateTimeImmutable $cutoff,
        bool $dryRun,
        array &$seenIdentities,
        array &$result
    ): void {
        if (!is_dir($this->storageRoot)) {
            return;
        }

        foreach (new FilesystemIterator($this->storageRoot, FilesystemIterator::SKIP_DOTS) as $entry) {
            if (!$entry->isDir() || $entry->isLink()) {
                continue;
            }
            if (preg_match('/^(ctrip|meituan)_profile_(.+)$/D', $entry->getFilename(), $matches) !== 1) {
                continue;
            }

            $platform = $matches[1];
            $profileKey = $matches[2];
            $identity = $this->identity($platform, $profileKey);
            $seenIdentities[$identity] = true;
            $sources = $metadata['sources'][$identity] ?? [];
            $bindings = $metadata['bindings'][$identity] ?? [];
            $profilePath = $entry->getPathname();
            $result['profiles_scanned']++;

            try {
                $lastUsed = $this->lastUsedAt($profilePath, $sources, $bindings);
                $hotelIds = $this->profileHotelIds($sources, $bindings);
                $item = [
                    'profile_ref' => substr(hash('sha256', $identity), 0, 12),
                    'platform' => $platform,
                    'system_hotel_ids' => $hotelIds,
                    'last_used_at' => $lastUsed?->format('Y-m-d H:i:s'),
                    'age_days' => $lastUsed instanceof DateTimeImmutable
                        ? max(0, (int)floor(($now->getTimestamp() - $lastUsed->getTimestamp()) / 86400))
                        : null,
                    'status' => 'kept',
                    'reason' => 'within_retention_window',
                ];

                if (!$lastUsed instanceof DateTimeImmutable) {
                    $result['profiles_kept']++;
                    $item['reason'] = 'last_used_unknown';
                    $result['items'][] = $item;
                    continue;
                }
                if ($lastUsed >= $cutoff) {
                    $result['profiles_kept']++;
                    $this->cleanupProfileCaches($platform, $profileKey, $profilePath, $cutoff, $dryRun, $result);
                    $result['items'][] = $item;
                    continue;
                }

                $result['profiles_expired']++;
                $item['status'] = $dryRun ? 'would_remove' : 'expired';
                $item['reason'] = 'inactive_beyond_retention';
                $size = $this->treeSize($profilePath, $profilePath);
                $result['bytes_reclaimable'] += $size;

                if ($this->hasActiveTask($sources, $metadata['active_source_ids'])) {
                    $result['profiles_skipped_active_task']++;
                    $result['profiles_kept']++;
                    $item['status'] = 'skipped';
                    $item['reason'] = 'active_sync_task';
                    $result['items'][] = $item;
                    continue;
                }

                $guard = $this->acquireProfileGuard($platform, $profileKey);
                if ($guard === null || $this->isProfileProcessInUse($platform, $profileKey, $profilePath)) {
                    $this->releaseProfileGuard($guard, false);
                    $result['profiles_skipped_in_use']++;
                    $result['profiles_kept']++;
                    $item['status'] = 'skipped';
                    $item['reason'] = 'profile_in_use';
                    $result['items'][] = $item;
                    continue;
                }

                try {
                    if ($dryRun) {
                        $result['profiles_kept']++;
                    } else {
                        [$disabled, $revoked, $secretsCleared] = $this->disableProfileMetadata($sources, $bindings);
                        $result['sources_disabled'] += $disabled;
                        $result['bindings_revoked'] += $revoked;
                        $result['source_secrets_cleared'] += $secretsCleared;
                        $this->removeTree($profilePath, $profilePath);
                        $result['profiles_removed']++;
                        $result['bytes_removed'] += $size;
                        $item['status'] = 'removed';
                    }
                } finally {
                    $this->releaseProfileGuard($guard, !$dryRun && !is_dir($profilePath));
                }
                $result['items'][] = $item;
            } catch (\Throwable) {
                $result['errors']++;
                $result['error_codes'][] = 'profile_cleanup_failed';
            }
        }
    }

    /**
     * Sanitize database credentials whose local Profile directory has already gone.
     * This closes the gap where a Profile was removed manually but secret_json or an
     * active binding remained behind. Recent metadata is retained until the same
     * inactivity cutoff, and active sync tasks always fail closed.
     *
     * @param array{sources:array<string,list<array<string,mixed>>>,bindings:array<string,list<array<string,mixed>>>,active_source_ids:array<int,true>} $metadata
     * @param array<string,true> $seenIdentities
     * @param array<string,mixed> $result
     */
    private function cleanupOrphanedProfileMetadata(
        array $metadata,
        array $seenIdentities,
        DateTimeImmutable $now,
        DateTimeImmutable $cutoff,
        bool $dryRun,
        array &$result
    ): void {
        $identities = array_values(array_unique(array_merge(
            array_keys($metadata['sources']),
            array_keys($metadata['bindings'])
        )));

        foreach ($identities as $identity) {
            if (isset($seenIdentities[$identity])) {
                continue;
            }
            $sources = $metadata['sources'][$identity] ?? [];
            $bindings = $metadata['bindings'][$identity] ?? [];
            $actionableSources = array_values(array_filter(
                $sources,
                fn(array $source): bool => $this->sourceMetadataNeedsCleanup($source)
            ));
            $actionableBindings = array_values(array_filter(
                $bindings,
                static fn(array $binding): bool => strtolower(trim((string)($binding['binding_status'] ?? ''))) !== 'revoked'
            ));
            if ($actionableSources === [] && $actionableBindings === []) {
                continue;
            }

            $result['orphan_metadata_groups_scanned']++;
            $lastUsed = $this->lastUsedAt(null, $sources, $bindings);
            if (!$lastUsed instanceof DateTimeImmutable || $lastUsed >= $cutoff) {
                $result['orphan_metadata_groups_kept']++;
                continue;
            }

            $result['orphan_metadata_groups_expired']++;
            $platform = explode(':', $identity, 2)[0] ?? '';
            $item = [
                'profile_ref' => substr(hash('sha256', $identity), 0, 12),
                'platform' => $platform,
                'system_hotel_ids' => $this->profileHotelIds($sources, $bindings),
                'last_used_at' => $lastUsed->format('Y-m-d H:i:s'),
                'age_days' => max(0, (int)floor(($now->getTimestamp() - $lastUsed->getTimestamp()) / 86400)),
                'status' => $dryRun ? 'would_sanitize' : 'expired_metadata',
                'reason' => 'orphaned_credentials_beyond_retention',
            ];

            if ($this->hasActiveTask($sources, $metadata['active_source_ids'])) {
                $result['orphan_metadata_groups_skipped_active_task']++;
                $result['orphan_metadata_groups_kept']++;
                $item['status'] = 'skipped';
                $item['reason'] = 'active_sync_task';
                $result['items'][] = $item;
                continue;
            }

            if (!$dryRun) {
                [$disabled, $revoked, $secretsCleared] = $this->disableProfileMetadata(
                    $actionableSources,
                    $actionableBindings
                );
                $result['sources_disabled'] += $disabled;
                $result['bindings_revoked'] += $revoked;
                $result['source_secrets_cleared'] += $secretsCleared;
                $result['orphan_metadata_groups_cleaned']++;
                $item['status'] = 'sanitized';
            }
            $result['items'][] = $item;
        }
    }

    /** @param list<array<string,mixed>> $sources @param list<array<string,mixed>> $bindings */
    private function lastUsedAt(?string $profilePath, array $sources, array $bindings): ?DateTimeImmutable
    {
        $timestamps = [];
        // Bound Profiles use authoritative login/sync evidence. Browser launches can
        // rewrite Preferences or Sessions even after authentication has expired and
        // must not keep a dead Profile alive forever. File activity is only a fallback
        // for a still-unbound local Profile that has no database identity yet.
        if ($profilePath !== null && is_dir($profilePath) && $sources === [] && $bindings === []) {
            $created = @filectime($profilePath);
            if (is_int($created) && $created > 0) {
                $timestamps[] = $created;
            }
            foreach (self::PROFILE_ACTIVITY_FILES as $relative) {
                $path = $profilePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                $mtime = is_file($path) ? @filemtime($path) : false;
                if (is_int($mtime) && $mtime > 0) {
                    $timestamps[] = $mtime;
                }
            }
            foreach (self::PROFILE_ACTIVITY_DIRECTORIES as $relative) {
                $path = $profilePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                if (is_dir($path)) {
                    $timestamps[] = $this->latestTreeTimestamp($path, $profilePath);
                }
            }
        }

        foreach ($sources as $source) {
            $this->appendTimestamp($timestamps, $source['create_time'] ?? null);
            $this->appendTimestamp($timestamps, $source['_last_success_task_at'] ?? null);
            $status = strtolower(trim((string)($source['last_sync_status'] ?? '')));
            if ($status === '') {
                $status = strtolower(trim((string)($source['status'] ?? '')));
            }
            if (in_array($status, [...self::SUCCESS_ACTIVITY_STATUSES, 'disabled'], true)) {
                $this->appendTimestamp($timestamps, $source['last_sync_time'] ?? null);
            }
            $config = is_array($source['_safe_config'] ?? null) ? $source['_safe_config'] : [];
            if (($config['current_session_verified'] ?? null) === true) {
                $this->appendTimestamp($timestamps, $config['current_session_probe_at'] ?? null);
            }
            foreach (['last_login_verified_at', 'profile_login_verified_at', 'last_profile_login_at', 'last_verified_at'] as $key) {
                $this->appendTimestamp($timestamps, $config[$key] ?? null);
            }
        }
        foreach ($bindings as $binding) {
            $this->appendTimestamp($timestamps, $binding['create_time'] ?? null);
        }

        $timestamp = $timestamps === [] ? 0 : max($timestamps);
        return $timestamp > 0
            ? (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone(self::TIMEZONE))
            : null;
    }

    /** @param list<int> $timestamps */
    private function appendTimestamp(array &$timestamps, mixed $value): void
    {
        if (!is_scalar($value) || trim((string)$value) === '') {
            return;
        }
        try {
            $time = new DateTimeImmutable((string)$value, new DateTimeZone(self::TIMEZONE));
            $timestamps[] = $time->getTimestamp();
        } catch (\Throwable) {
        }
    }

    /** @param list<array<string,mixed>> $sources @param array<int,true> $activeSourceIds */
    private function hasActiveTask(array $sources, array $activeSourceIds): bool
    {
        foreach ($sources as $source) {
            if (isset($activeSourceIds[(int)($source['id'] ?? 0)])) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $source */
    private function sourceMetadataNeedsCleanup(array $source): bool
    {
        $secret = trim((string)($source['secret_json'] ?? ''));
        $secretPresent = $secret !== '' && !in_array(strtolower($secret), ['null', '{}', '[]'], true);
        return (int)($source['enabled'] ?? 0) !== 0
            || strtolower(trim((string)($source['status'] ?? ''))) !== 'disabled'
            || strtolower(trim((string)($source['last_sync_status'] ?? ''))) !== 'disabled'
            || $secretPresent;
    }

    /** @param list<array<string,mixed>> $sources @param list<array<string,mixed>> $bindings @return array{0:int,1:int,2:int} */
    private function disableProfileMetadata(array $sources, array $bindings): array
    {
        $sourceIds = array_values(array_unique(array_filter(array_map(
            static fn(array $row): int => (int)($row['id'] ?? 0),
            $sources
        ))));
        $bindingIds = array_values(array_unique(array_filter(array_map(
            static fn(array $row): int => (int)($row['id'] ?? 0),
            $bindings
        ))));

        $secretsCleared = count(array_filter(
            $sources,
            static function (array $source): bool {
                $secret = trim((string)($source['secret_json'] ?? ''));
                return $secret !== '' && !in_array(strtolower($secret), ['null', '{}', '[]'], true);
            }
        ));

        return Db::transaction(function () use ($sourceIds, $bindingIds, $secretsCleared): array {
            $disabled = 0;
            $revoked = 0;
            $now = date('Y-m-d H:i:s');
            if ($sourceIds !== []) {
                $disabled = (int)Db::name('platform_data_sources')->whereIn('id', $sourceIds)->update([
                    'enabled' => 0,
                    'status' => 'disabled',
                    'last_sync_status' => 'disabled',
                    'last_error' => 'profile_retention_expired',
                    'secret_json' => null,
                    'update_time' => $now,
                ]);
            }
            if ($bindingIds !== []) {
                $revoked = (int)Db::name('ota_profile_bindings')->whereIn('id', $bindingIds)->update([
                    'binding_status' => 'revoked',
                    'revoked_by' => null,
                    'update_time' => $now,
                ]);
            }
            return [$disabled, $revoked, $secretsCleared];
        });
    }

    /** @param array<string,mixed> $result */
    private function cleanupProfileCaches(
        string $platform,
        string $profileKey,
        string $profilePath,
        DateTimeImmutable $cutoff,
        bool $dryRun,
        array &$result
    ): void {
        $expired = [];
        foreach (self::PROFILE_CACHE_PATHS as $relative) {
            $path = $profilePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!is_dir($path)) {
                continue;
            }
            $latest = $this->latestTreeTimestamp($path, $profilePath);
            if ($latest > 0 && $latest < $cutoff->getTimestamp()) {
                $expired[$path] = $this->treeSize($path, $profilePath);
            }
        }
        if ($expired === []) {
            return;
        }

        $result['profile_cache_targets_expired'] += count($expired);
        $result['bytes_reclaimable'] += array_sum($expired);
        $guard = $this->acquireProfileGuard($platform, $profileKey);
        if ($guard === null || $this->isProfileProcessInUse($platform, $profileKey, $profilePath)) {
            $this->releaseProfileGuard($guard, false);
            $result['profiles_skipped_in_use']++;
            return;
        }
        try {
            if ($dryRun) {
                return;
            }
            foreach ($expired as $path => $bytes) {
                $this->removeTree($path, $profilePath);
                $result['profile_cache_targets_removed']++;
                $result['bytes_removed'] += $bytes;
            }
        } finally {
            $this->releaseProfileGuard($guard, false);
        }
    }

    /** @param array<string,mixed> $result */
    private function cleanupAgedArtifacts(DateTimeImmutable $cutoff, bool $dryRun, array &$result): void
    {
        foreach (self::AGED_ARTIFACT_DIRECTORIES as $relative) {
            $root = $this->projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!is_dir($root)) {
                continue;
            }
            try {
                foreach ($this->collectFiles($root, $root) as $path) {
                    $this->removeAgedArtifactFile($path, $cutoff, $dryRun, $result);
                }
                if (!$dryRun) {
                    $this->removeEmptyDirectories($root, $root);
                }
            } catch (\Throwable) {
                $result['errors']++;
                $result['error_codes'][] = 'artifact_cleanup_failed';
            }
        }

        $reportsRoot = $this->projectRoot . DIRECTORY_SEPARATOR . 'reports';
        if (!is_dir($reportsRoot)) {
            return;
        }
        foreach (new FilesystemIterator($reportsRoot, FilesystemIterator::SKIP_DOTS) as $entry) {
            if (!$entry->isFile() || $entry->isLink()
                || preg_match(self::AGED_REPORT_FILE_PATTERN, $entry->getFilename()) !== 1
            ) {
                continue;
            }
            $this->removeAgedArtifactFile($entry->getPathname(), $cutoff, $dryRun, $result);
        }
    }

    /** @param array<string,mixed> $result */
    private function removeAgedArtifactFile(
        string $path,
        DateTimeImmutable $cutoff,
        bool $dryRun,
        array &$result
    ): void {
        $mtime = @filemtime($path);
        if (!is_int($mtime) || $mtime >= $cutoff->getTimestamp()) {
            return;
        }
        $bytes = (int)(@filesize($path) ?: 0);
        $result['artifact_files_expired']++;
        $result['bytes_reclaimable'] += $bytes;
        if ($dryRun) {
            return;
        }
        if (!@unlink($path)) {
            $result['errors']++;
            $result['error_codes'][] = 'artifact_file_delete_failed';
            return;
        }
        $result['artifact_files_removed']++;
        $result['bytes_removed'] += $bytes;
    }

    /** @param array<string,mixed> $result */
    private function cleanupOrphanedLocks(bool $dryRun, array &$result): void
    {
        $lockRoot = $this->projectRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'locks';
        if (!is_dir($lockRoot)) {
            return;
        }
        foreach (new FilesystemIterator($lockRoot, FilesystemIterator::SKIP_DOTS) as $entry) {
            if (!$entry->isFile() || $entry->isLink()
                || preg_match('/^profile_capture_(ctrip|meituan)_(.+)\.lock$/D', $entry->getFilename(), $matches) !== 1
            ) {
                continue;
            }
            $profilePath = $this->storageRoot . DIRECTORY_SEPARATOR . $matches[1] . '_profile_' . $matches[2];
            if (is_dir($profilePath)) {
                continue;
            }
            $result['orphan_locks_expired']++;
            if ($dryRun) {
                continue;
            }
            $handle = @fopen($entry->getPathname(), 'c+');
            if (!is_resource($handle) || !@flock($handle, LOCK_EX | LOCK_NB)) {
                if (is_resource($handle)) {
                    @fclose($handle);
                }
                continue;
            }
            @flock($handle, LOCK_UN);
            @fclose($handle);
            if (@unlink($entry->getPathname())) {
                $result['orphan_locks_removed']++;
            }
        }
    }

    /** @return array{handle:resource,path:string,created:bool}|null */
    private function acquireProfileGuard(string $platform, string $profileKey): ?array
    {
        $lockRoot = $this->projectRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'locks';
        if (!is_dir($lockRoot) && !@mkdir($lockRoot, 0775, true) && !is_dir($lockRoot)) {
            return null;
        }
        $path = $lockRoot . DIRECTORY_SEPARATOR . 'profile_capture_' . $platform . '_'
            . BrowserProfileCaptureRequestService::safeFilePart($profileKey) . '.lock';
        $created = !is_file($path);
        $handle = @fopen($path, 'c+');
        if (!is_resource($handle)) {
            return null;
        }
        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            @fclose($handle);
            return null;
        }
        return ['handle' => $handle, 'path' => $path, 'created' => $created];
    }

    /** @param array{handle:resource,path:string,created:bool}|null $guard */
    private function releaseProfileGuard(?array $guard, bool $removeLock): void
    {
        if (!is_array($guard) || !is_resource($guard['handle'] ?? null)) {
            return;
        }
        @flock($guard['handle'], LOCK_UN);
        @fclose($guard['handle']);
        if ($removeLock || ($guard['created'] ?? false)) {
            @unlink((string)$guard['path']);
        }
    }

    private function isProfileProcessInUse(string $platform, string $profileKey, string $profilePath): bool
    {
        if ($this->profileInUse instanceof Closure) {
            return (bool)($this->profileInUse)($platform, $profileKey, $profilePath);
        }
        if (PHP_OS_FAMILY === 'Windows') {
            return $this->windowsProfileProcessInUse($profilePath);
        }
        return $this->posixProfileProcessInUse($profilePath);
    }

    private function windowsProfileProcessInUse(string $profilePath): bool
    {
        if (!function_exists('proc_open')) {
            return true;
        }
        $needle = base64_encode($profilePath);
        $script = '$needle=[Text.Encoding]::UTF8.GetString([Convert]::FromBase64String(\'' . $needle . '\'));'
            . 'try{$p=Get-CimInstance Win32_Process -Filter "Name=\'chrome.exe\' OR Name=\'msedge.exe\' OR Name=\'chromium.exe\'" -ErrorAction Stop|'
            . 'Where-Object{$_.CommandLine -and $_.CommandLine.IndexOf($needle,[StringComparison]::OrdinalIgnoreCase)-ge 0}|Select-Object -First 1}'
            . 'catch{exit 2};if($null-ne $p){exit 10};exit 0';
        $utf16 = function_exists('mb_convert_encoding')
            ? mb_convert_encoding($script, 'UTF-16LE', 'UTF-8')
            : (function_exists('iconv') ? iconv('UTF-8', 'UTF-16LE', $script) : false);
        if (!is_string($utf16)) {
            return true;
        }
        return $this->processExitCode([
            'powershell.exe', '-NoProfile', '-NonInteractive', '-EncodedCommand', base64_encode($utf16),
        ]) !== 0;
    }

    private function posixProfileProcessInUse(string $profilePath): bool
    {
        if (!function_exists('proc_open')) {
            return true;
        }
        $pipes = [];
        $process = @proc_open(['ps', '-eo', 'args='], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return true;
        }
        $stdout = (string)stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        return $exit !== 0 || str_contains($stdout, $profilePath);
    }

    /** @param list<string> $command */
    private function processExitCode(array $command): int
    {
        $pipes = [];
        $process = @proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            return 2;
        }
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return (int)proc_close($process);
    }

    private function identity(string $platform, string $profileKey): string
    {
        return $platform . ':' . hash('sha256', BrowserProfileCaptureRequestService::safeFilePart($profileKey));
    }

    private function normalizePlatform(string $platform): string
    {
        $platform = strtolower(trim($platform));
        return in_array($platform, ['ctrip', 'meituan'], true) ? $platform : '';
    }

    /** @return array<string,mixed> */
    private function decodeConfig(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $config */
    private function sourceProfileKey(string $platform, array $config): string
    {
        $keys = $platform === 'meituan'
            ? ['profile_binding_key', 'stable_profile_id', 'store_id', 'storeId', 'poi_id', 'poiId', 'profile_id', 'profileId']
            : ['profile_binding_key', 'stable_profile_id', 'profile_id', 'profileId', 'browser_profile_id', 'browserProfileId'];
        foreach ($keys as $key) {
            if (isset($config[$key]) && is_scalar($config[$key]) && trim((string)$config[$key]) !== '') {
                return trim((string)$config[$key]);
            }
        }
        return '';
    }

    /** @param list<array<string,mixed>> $sources @param list<array<string,mixed>> $bindings @return list<int> */
    private function profileHotelIds(array $sources, array $bindings): array
    {
        $ids = [];
        foreach (array_merge($sources, $bindings) as $row) {
            $id = (int)($row['system_hotel_id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        $result = array_map('intval', array_keys($ids));
        sort($result);
        return $result;
    }

    private function latestTreeTimestamp(string $path, string $allowedRoot): int
    {
        $latest = (int)(@filemtime($path) ?: 0);
        foreach ($this->collectFiles($path, $allowedRoot) as $file) {
            $latest = max($latest, (int)(@filemtime($file) ?: 0));
        }
        return $latest;
    }

    private function treeSize(string $path, string $allowedRoot): int
    {
        $bytes = 0;
        foreach ($this->collectFiles($path, $allowedRoot) as $file) {
            $bytes += (int)(@filesize($file) ?: 0);
        }
        return $bytes;
    }

    /** @return list<string> */
    private function collectFiles(string $path, string $allowedRoot): array
    {
        $files = [];
        $rootReal = realpath($allowedRoot);
        $pathReal = realpath($path);
        if ($rootReal === false || $pathReal === false || !$this->isWithin($pathReal, $rootReal)) {
            throw new RuntimeException('Retention path escaped its allowed root.');
        }
        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $item) {
            if ($item->isLink()) {
                throw new RuntimeException('Retention path contains a symbolic link or junction.');
            }
            if ($item->isDir()) {
                $files = array_merge($files, $this->collectFiles($item->getPathname(), $allowedRoot));
            } elseif ($item->isFile()) {
                $files[] = $item->getPathname();
            }
        }
        return $files;
    }

    private function removeTree(string $path, string $allowedRoot): void
    {
        $rootReal = realpath($allowedRoot);
        $pathReal = realpath($path);
        if ($rootReal === false || $pathReal === false || !$this->isWithin($pathReal, $rootReal)) {
            throw new RuntimeException('Retention delete path escaped its allowed root.');
        }
        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $item) {
            if ($item->isLink()) {
                throw new RuntimeException('Retention delete path contains a symbolic link or junction.');
            }
            if ($item->isDir()) {
                $this->removeTree($item->getPathname(), $allowedRoot);
            } elseif (!@unlink($item->getPathname())) {
                throw new RuntimeException('Retention file deletion failed.');
            }
        }
        if (!@rmdir($path)) {
            throw new RuntimeException('Retention directory deletion failed.');
        }
    }

    private function removeEmptyDirectories(string $path, string $allowedRoot): void
    {
        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $item) {
            if ($item->isDir() && !$item->isLink()) {
                $this->removeEmptyDirectories($item->getPathname(), $allowedRoot);
            }
        }
        if ($path !== $allowedRoot) {
            $iterator = new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS);
            if (!$iterator->valid()) {
                @rmdir($path);
            }
        }
    }

    private function isWithin(string $path, string $root): bool
    {
        $root = rtrim($root, "\\/");
        return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }
}
