<?php
declare(strict_types=1);

namespace app\service;

use Closure;
use JsonException;
use RuntimeException;
use Throwable;
use app\model\SystemConfig;
use think\facade\Db;

final class OtaCredentialMigrationService
{
    private const PLATFORMS = ['ctrip', 'meituan'];
    private const CLASSIFICATIONS = [
        'bound_verified',
        'unbound',
        'field_conflict',
        'duplicate_config_id',
        'tenant_mismatch',
        'already_migrated',
        'profile_secret_cleanup_required',
    ];
    private const CONFIG_LIST_KEYS = [
        'ctrip_config_list' => 'ctrip',
        'meituan_config_list' => 'meituan',
    ];
    private const METADATA_RELOCATIONS = [
        'meituan_config_list' => [
            'source_table' => 'system_config',
            'target_table' => 'system_configs',
        ],
    ];

    private ?OtaCredentialVault $vault;
    private ?Closure $credentialStore;

    public function __construct(
        ?OtaCredentialVault $vault = null,
        ?callable $credentialStore = null,
        private readonly int $actorId = 0
    ) {
        $this->vault = $vault;
        $this->credentialStore = $credentialStore === null
            ? null
            : Closure::fromCallable($credentialStore);
    }

    /**
     * Inventory legacy OTA credential stores and optionally migrate verified bindings.
     *
     * The returned structure is deliberately metadata-only. Raw config IDs and all
     * credential material remain process-local and are never returned to the caller.
     */
    public function run(bool $execute): array
    {
        $inventory = $this->inventoryLegacyConfigs();
        if (!$execute) {
            return $this->safeSummary('dry-run', $inventory, [], []);
        }
        if ($inventory['blockers'] !== []) {
            return $this->safeSummary('execute', $inventory, [], []);
        }

        try {
            $result = Db::transaction(function () use ($inventory): array {
                $migrated = [];
                $sanitized = [];
                foreach ($inventory['items'] as $item) {
                    if (($item['classification'] ?? '') === 'bound_verified') {
                        $migrated[] = $this->migrateItem($item);
                    } elseif (($item['classification'] ?? '') === 'profile_secret_cleanup_required') {
                        $sanitized[] = $this->sanitizeProfileCredentialMaterial($item, $inventory['items']);
                    } elseif ($this->isMissingHotelCredential($item)) {
                        $sanitized[] = $this->sanitizeMissingHotelCredential($item);
                    }
                }
                $relocationBlockers = [];
                $currentRelocations = $this->inventoryMetadataRelocations($relocationBlockers);
                if ($relocationBlockers !== []) {
                    throw new RuntimeException('OTA metadata relocation state changed during migration.');
                }
                $relocated = [];
                foreach ($currentRelocations as $relocation) {
                    $relocated[] = $this->relocateMetadataConfig($relocation);
                }
                SystemConfig::clearProtectedOtaCaches();
                return [
                    'migrated' => $migrated,
                    'sanitized' => $sanitized,
                    'relocated' => $relocated,
                    'post_inventory' => $this->inventoryLegacyConfigs(),
                ];
            });
        } catch (Throwable) {
            // Never include the originating exception: it may contain legacy secret text.
            throw new RuntimeException('OTA credential migration failed.');
        }

        return $this->safeSummary(
            'execute',
            $inventory,
            $result['migrated'],
            $result['sanitized'],
            $result['post_inventory'],
            $result['relocated']
        );
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, blockers: array<int, string>, sources: array<string, array<string, mixed>>}
     */
    private function inventoryLegacyConfigs(): array
    {
        $blockers = [];
        $sources = [
            'system_config' => ['status' => 'not_present', 'row_count' => 0, 'item_count' => 0],
            'system_configs' => ['status' => 'not_present', 'row_count' => 0, 'item_count' => 0],
            'platform_data_sources' => ['status' => 'not_present', 'row_count' => 0, 'item_count' => 0],
        ];

        if (!$this->tableExists('hotels')) {
            $blockers[] = 'schema_missing:hotels';
            $hotelTenants = [];
        } elseif (!$this->tableHasColumn('hotels', 'tenant_id')) {
            $blockers[] = 'schema_missing:hotels.tenant_id';
            $hotelTenants = [];
        } else {
            $hotelTenants = $this->hotelTenantMap();
        }
        if (!$this->tableExists('ota_credentials')) {
            $blockers[] = 'schema_missing:ota_credentials';
        }

        $items = [];
        foreach (['system_config', 'system_configs'] as $table) {
            if (!$this->tableExists($table)) {
                continue;
            }
            $sources[$table]['status'] = 'scanned';
            $this->scanConfigurationTable(
                $table,
                $hotelTenants,
                $items,
                $blockers,
                $sources[$table]
            );
        }

        if ($this->tableExists('platform_data_sources')) {
            $sources['platform_data_sources']['status'] = 'scanned';
            $this->scanPlatformDataSources(
                $hotelTenants,
                $items,
                $blockers,
                $sources['platform_data_sources']
            );
        }

        $this->classifyDuplicateLocators($items);
        $metadataRelocations = $this->inventoryMetadataRelocations($blockers);
        usort($items, static fn(array $left, array $right): int => strcmp(
            (string)$left['item_id'],
            (string)$right['item_id']
        ));

        $blockers = array_values(array_unique($blockers));
        sort($blockers);
        return [
            'items' => $items,
            'blockers' => $blockers,
            'sources' => $sources,
            'metadata_relocations' => $metadataRelocations,
        ];
    }

    /**
     * Build a metadata-only plan for moving legacy config lists into the table
     * used by normal runtime reads. Fingerprints stay internal and are never
     * included in the public migration summary.
     *
     * @param array<int, string> $blockers
     * @return array<int, array<string, mixed>>
     */
    private function inventoryMetadataRelocations(array &$blockers): array
    {
        $relocations = [];
        foreach (self::METADATA_RELOCATIONS as $configKey => $tables) {
            $sourceTable = (string)$tables['source_table'];
            $targetTable = (string)$tables['target_table'];
            if (!$this->tableExists($sourceTable)) {
                continue;
            }

            $sourceRow = Db::name($sourceTable)->where('config_key', $configKey)->find();
            if (!$sourceRow) {
                continue;
            }
            try {
                $sourceValue = $this->decodeJsonObject((string)($sourceRow['config_value'] ?? ''));
            } catch (JsonException) {
                $blockers[] = 'invalid_json:' . $sourceTable;
                continue;
            }
            if ($sourceValue === []) {
                continue;
            }

            $base = [
                'config_key' => $configKey,
                'source_table' => $sourceTable,
                'target_table' => $targetTable,
                'source_row_id' => (int)($sourceRow['id'] ?? 0),
                'source_fingerprint' => $this->comparableFingerprint($sourceValue),
            ];
            if (!$this->tableExists($targetTable)) {
                $blockers[] = 'schema_missing:' . $targetTable;
                $relocations[] = array_merge($base, [
                    'target_row_id' => null,
                    'target_fingerprint' => null,
                    'classification' => 'conflict',
                    'reason_code' => 'canonical_table_missing',
                ]);
                continue;
            }

            $targetRow = Db::name($targetTable)->where('config_key', $configKey)->find();
            if (!$targetRow) {
                $relocations[] = array_merge($base, [
                    'target_row_id' => null,
                    'target_fingerprint' => null,
                    'classification' => 'relocation_required',
                    'reason_code' => 'canonical_missing',
                ]);
                continue;
            }

            try {
                $targetValue = $this->decodeJsonObject((string)($targetRow['config_value'] ?? ''));
                $sourceSafe = $this->sanitizedRelocatableMetadataList($sourceValue);
                $targetSafe = $this->sanitizedRelocatableMetadataList($targetValue);
                $matches = hash_equals(
                    $this->comparableFingerprint($sourceSafe),
                    $this->comparableFingerprint($targetSafe)
                );
            } catch (JsonException) {
                $blockers[] = 'invalid_json:' . $targetTable;
                continue;
            } catch (RuntimeException) {
                $matches = false;
            }

            if (!$matches) {
                $blockers[] = 'metadata_relocation_conflict:' . $configKey;
            }
            $relocations[] = array_merge($base, [
                'target_row_id' => (int)($targetRow['id'] ?? 0),
                'target_fingerprint' => $this->comparableFingerprint($targetValue ?? []),
                'classification' => $matches ? 'legacy_retirement_required' : 'conflict',
                'reason_code' => $matches ? 'canonical_match' : 'canonical_content_conflict',
            ]);
        }

        return $relocations;
    }

    /**
     * Copy a sanitized config list into the canonical table and retire the
     * legacy row. This method is called only from the surrounding transaction.
     *
     * @param array<string, mixed> $relocation
     * @return array<string, mixed>
     */
    private function relocateMetadataConfig(array $relocation): array
    {
        $configKey = (string)($relocation['config_key'] ?? '');
        $definition = self::METADATA_RELOCATIONS[$configKey] ?? null;
        if (!is_array($definition)
            || !in_array((string)($relocation['classification'] ?? ''), [
                'relocation_required',
                'legacy_retirement_required',
            ], true)) {
            throw new RuntimeException('OTA metadata relocation plan is invalid.');
        }

        $sourceTable = (string)$definition['source_table'];
        $targetTable = (string)$definition['target_table'];
        $sourceRowId = (int)($relocation['source_row_id'] ?? 0);
        $sourceRow = Db::name($sourceTable)->where('id', $sourceRowId)->lock(true)->find();
        if (!$sourceRow || !hash_equals($configKey, (string)($sourceRow['config_key'] ?? ''))) {
            throw new RuntimeException('Legacy OTA metadata source changed during relocation.');
        }
        $sourceValue = $this->decodeJsonObject((string)($sourceRow['config_value'] ?? ''));
        if ($sourceValue === []
            || !hash_equals(
                (string)($relocation['source_fingerprint'] ?? ''),
                $this->comparableFingerprint($sourceValue)
            )) {
            throw new RuntimeException('Legacy OTA metadata source changed during relocation.');
        }
        $safeSourceValue = $this->sanitizedRelocatableMetadataList($sourceValue);

        $targetRow = Db::name($targetTable)->where('config_key', $configKey)->lock(true)->find();
        $action = 'legacy_retired';
        if (($relocation['classification'] ?? '') === 'relocation_required') {
            if ($targetRow) {
                throw new RuntimeException('Canonical OTA metadata appeared during relocation.');
            }
            $targetRowId = (int)Db::name($targetTable)->insertGetId([
                'config_key' => $configKey,
                'config_value' => $this->encodeJson($safeSourceValue),
            ]);
            if ($targetRowId <= 0) {
                throw new RuntimeException('Canonical OTA metadata insert failed.');
            }
            $action = 'copied_and_legacy_retired';
        } else {
            $expectedTargetRowId = (int)($relocation['target_row_id'] ?? 0);
            if (!$targetRow || (int)($targetRow['id'] ?? 0) !== $expectedTargetRowId) {
                throw new RuntimeException('Canonical OTA metadata changed during relocation.');
            }
            $targetValue = $this->decodeJsonObject((string)($targetRow['config_value'] ?? ''));
            if (!hash_equals(
                (string)($relocation['target_fingerprint'] ?? ''),
                $this->comparableFingerprint($targetValue)
            )) {
                throw new RuntimeException('Canonical OTA metadata changed during relocation.');
            }
            $safeTargetValue = $this->sanitizedRelocatableMetadataList($targetValue);
            if (!hash_equals(
                $this->comparableFingerprint($safeSourceValue),
                $this->comparableFingerprint($safeTargetValue)
            )) {
                throw new RuntimeException('Canonical OTA metadata conflicts with the legacy source.');
            }
            $targetRowId = $expectedTargetRowId;
        }

        $sourceUpdate = ['config_value' => '{}'];
        if ($this->tableHasColumn($sourceTable, 'update_time')) {
            $sourceUpdate['update_time'] = date('Y-m-d H:i:s');
        }
        if (Db::name($sourceTable)->where('id', $sourceRowId)->update($sourceUpdate) !== 1) {
            throw new RuntimeException('Legacy OTA metadata retirement failed.');
        }

        return [
            'config_key' => $configKey,
            'source_table' => $sourceTable,
            'target_table' => $targetTable,
            'source_row_id' => $sourceRowId,
            'target_row_id' => $targetRowId,
            'action' => $action,
        ];
    }

    /**
     * @param array<string|int, mixed> $list
     * @return array<string|int, mixed>
     */
    private function sanitizedRelocatableMetadataList(array $list): array
    {
        $safeList = [];
        foreach ($list as $entryKey => $entry) {
            if (!is_array($entry)) {
                throw new RuntimeException('OTA metadata list contains an invalid entry.');
            }
            [$metadata, $secrets] = $this->splitSecrets($entry);
            if ($this->hasNonEmptyScalar($secrets)) {
                throw new RuntimeException('OTA metadata list still contains reusable credentials.');
            }
            $safeList[$entryKey] = $metadata;
        }
        return $safeList;
    }

    private function comparableFingerprint(mixed $value): string
    {
        return $this->fingerprint($this->normalizeComparableValue($value));
    }

    private function normalizeComparableValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = $this->normalizeComparableValue($item);
        }
        if (!array_is_list($normalized)) {
            uksort($normalized, static fn(int|string $left, int|string $right): int => strcmp(
                (string)$left,
                (string)$right
            ));
        }
        return $normalized;
    }

    /**
     * @param array<int, int> $hotelTenants
     * @param array<int, array<string, mixed>> $items
     * @param array<int, string> $blockers
     * @param array<string, mixed> $sourceSummary
     */
    private function scanConfigurationTable(
        string $table,
        array $hotelTenants,
        array &$items,
        array &$blockers,
        array &$sourceSummary
    ): void {
        $exactKeys = array_keys(self::CONFIG_LIST_KEYS);
        foreach (array_keys($hotelTenants) as $hotelId) {
            $exactKeys[] = 'online_data_cookies_hotel_' . $hotelId;
            $exactKeys[] = 'online_data_cookies_' . $hotelId;
        }

        $rowsById = [];
        foreach (Db::name($table)->whereIn('config_key', $exactKeys)->select()->toArray() as $row) {
            $rowsById[(int)$row['id']] = $row;
        }
        foreach (Db::name($table)
            ->whereRaw("SUBSTR(config_key, 1, 12) = 'data_config_'")
            ->select()
            ->toArray() as $row) {
            $rowsById[(int)$row['id']] = $row;
        }
        ksort($rowsById);
        $sourceSummary['row_count'] = count($rowsById);

        foreach ($rowsById as $row) {
            $rowId = (int)($row['id'] ?? 0);
            $configKey = trim((string)($row['config_key'] ?? ''));
            try {
                $decoded = $this->decodeJsonObject((string)($row['config_value'] ?? ''));
            } catch (JsonException) {
                $blockers[] = 'invalid_json:' . $table;
                continue;
            }

            if (isset(self::CONFIG_LIST_KEYS[$configKey])) {
                foreach ($decoded as $entryKey => $entry) {
                    if (!is_array($entry)) {
                        $blockers[] = 'invalid_shape:' . $table;
                        continue;
                    }
                    $item = $this->buildInventoryItem(
                        sourceTable: $table,
                        sourceRowId: $rowId,
                        sourceKind: 'config_list',
                        sourceKey: $configKey,
                        entryKey: (string)$entryKey,
                        payload: $entry,
                        explicitSecrets: null,
                        impliedPlatform: self::CONFIG_LIST_KEYS[$configKey],
                        impliedHotelId: null,
                        hotelTenants: $hotelTenants
                    );
                    if ($this->shouldInventoryItem($item)) {
                        $items[] = $item;
                    }
                }
                continue;
            }

            if (str_starts_with($configKey, 'data_config_')) {
                if (array_is_list($decoded) && $decoded !== []) {
                    $blockers[] = 'invalid_shape:' . $table;
                    continue;
                }
                $suffix = strtolower(substr($configKey, strlen('data_config_')));
                $platform = str_starts_with($suffix, 'ctrip')
                    ? 'ctrip'
                    : (str_starts_with($suffix, 'meituan') ? 'meituan' : '');
                $item = $this->buildInventoryItem(
                    sourceTable: $table,
                    sourceRowId: $rowId,
                    sourceKind: 'data_config',
                    sourceKey: $configKey,
                    entryKey: '',
                    payload: $decoded,
                    explicitSecrets: null,
                    impliedPlatform: $platform,
                    impliedHotelId: null,
                    hotelTenants: $hotelTenants
                );
                if ($this->shouldInventoryItem($item)) {
                    $items[] = $item;
                }
                continue;
            }

            $cacheHotelId = $this->hotelIdFromLegacyCookieKey($configKey);
            if ($cacheHotelId === null) {
                continue;
            }
            foreach ($decoded as $entryKey => $entry) {
                if (!is_array($entry)) {
                    $blockers[] = 'invalid_shape:' . $table;
                    continue;
                }
                $item = $this->buildInventoryItem(
                    sourceTable: $table,
                    sourceRowId: $rowId,
                    sourceKind: 'cookie_cache',
                    sourceKey: $configKey,
                    entryKey: (string)$entryKey,
                    payload: $entry,
                    explicitSecrets: null,
                    impliedPlatform: $this->normalizePlatform($entry['platform'] ?? 'ctrip'),
                    impliedHotelId: $cacheHotelId,
                    hotelTenants: $hotelTenants
                );
                if ($this->shouldInventoryItem($item)) {
                    $items[] = $item;
                }
            }
        }

        $sourceSummary['item_count'] = count(array_filter(
            $items,
            static fn(array $item): bool => ($item['source_table'] ?? '') === $table
        ));
    }

    /**
     * @param array<int, int> $hotelTenants
     * @param array<int, array<string, mixed>> $items
     * @param array<int, string> $blockers
     * @param array<string, mixed> $sourceSummary
     */
    private function scanPlatformDataSources(
        array $hotelTenants,
        array &$items,
        array &$blockers,
        array &$sourceSummary
    ): void {
        $requiredColumns = ['id', 'system_hotel_id', 'platform', 'config_json', 'secret_json'];
        $columns = $this->tableColumns('platform_data_sources');
        foreach ($requiredColumns as $column) {
            if (!isset($columns[$column])) {
                $blockers[] = 'schema_missing:platform_data_sources.' . $column;
                return;
            }
        }

        $rows = Db::name('platform_data_sources')
            ->whereIn('platform', self::PLATFORMS)
            ->order('id')
            ->select()
            ->toArray();
        $sourceSummary['row_count'] = count($rows);

        foreach ($rows as $row) {
            try {
                $config = $this->decodeOptionalJsonObject((string)($row['config_json'] ?? ''));
                $secret = $this->decodeOptionalJsonObject((string)($row['secret_json'] ?? ''));
            } catch (JsonException) {
                $blockers[] = 'invalid_json:platform_data_sources';
                continue;
            }
            if (isset($row['config_id']) && !isset($config['config_id'])) {
                $config['config_id'] = $row['config_id'];
            }
            $config['system_hotel_id'] = $row['system_hotel_id'] ?? null;
            if (array_key_exists('tenant_id', $row)) {
                $config['tenant_id'] = $row['tenant_id'];
            }
            $config['platform'] = $row['platform'] ?? '';

            $item = $this->buildInventoryItem(
                sourceTable: 'platform_data_sources',
                sourceRowId: (int)($row['id'] ?? 0),
                sourceKind: 'platform_source',
                sourceKey: '',
                entryKey: '',
                payload: $config,
                explicitSecrets: $secret,
                impliedPlatform: $this->normalizePlatform($row['platform'] ?? ''),
                impliedHotelId: $this->strictPositiveInt($row['system_hotel_id'] ?? null),
                hotelTenants: $hotelTenants,
                fingerprintPayload: [$config, $secret, (string)($row['ingestion_method'] ?? '')],
                ingestionMethod: (string)($row['ingestion_method'] ?? ''),
                sourceEnabled: !array_key_exists('enabled', $row)
                    || in_array($row['enabled'], [true, 1, '1', 'true', 'yes', 'on'], true)
            );

            if (!$this->shouldInventoryItem($item)) {
                continue;
            }
            $items[] = $item;
        }

        $sourceSummary['item_count'] = count(array_filter(
            $items,
            static fn(array $item): bool => ($item['source_table'] ?? '') === 'platform_data_sources'
        ));
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $explicitSecrets
     * @param array<int, int> $hotelTenants
     * @param mixed $fingerprintPayload
     * @return array<string, mixed>
     */
    private function buildInventoryItem(
        string $sourceTable,
        int $sourceRowId,
        string $sourceKind,
        string $sourceKey,
        string $entryKey,
        array $payload,
        ?array $explicitSecrets,
        string $impliedPlatform,
        ?int $impliedHotelId,
        array $hotelTenants,
        mixed $fingerprintPayload = null,
        string $ingestionMethod = '',
        bool $sourceEnabled = true
    ): array {
        [$metadata, $secrets] = $this->splitSecrets($payload);
        if ($explicitSecrets !== null) {
            [, $secretsFromExplicitStore] = $this->splitSecrets($explicitSecrets);
            $secrets = array_replace_recursive($secrets, $secretsFromExplicitStore);
        }

        $platform = $this->normalizePlatform($metadata['platform'] ?? $impliedPlatform);
        $platformConflict = $impliedPlatform !== ''
            && $platform !== ''
            && !hash_equals($impliedPlatform, $platform);

        $configIdValue = trim((string)($metadata['config_id'] ?? ''));
        $idValue = trim((string)($metadata['id'] ?? ''));
        $entryConfigId = trim($entryKey);
        $configId = $configIdValue !== ''
            ? $configIdValue
            : ($idValue !== '' ? $idValue : $entryConfigId);
        if ($sourceKind === 'platform_source'
            && $configIdValue === ''
            && $idValue === ''
            && $configId === ''
            && $sourceRowId > 0
            && $platform !== '') {
            $configId = $platform . '-source-' . $sourceRowId;
        }
        $locatorConflict = ($configIdValue !== '' && $idValue !== '' && !hash_equals($configIdValue, $idValue))
            || ($sourceKind !== 'data_config'
                && $sourceKind !== 'platform_source'
                && $entryConfigId !== ''
                && $configId !== ''
                && !hash_equals($entryConfigId, $configId));

        $systemHotelPresent = array_key_exists('system_hotel_id', $metadata)
            && $metadata['system_hotel_id'] !== null
            && $metadata['system_hotel_id'] !== '';
        // Generic data_config records use hotel_id as the OTA-side hotel code.
        // Their internal ownership is system_hotel_id only.
        $hotelIdIsSystemBinding = in_array($sourceKind, ['config_list', 'cookie_cache'], true);
        $hotelPresent = $hotelIdIsSystemBinding
            && array_key_exists('hotel_id', $metadata)
            && $metadata['hotel_id'] !== null
            && $metadata['hotel_id'] !== '';
        $systemHotelId = $systemHotelPresent ? $this->strictPositiveInt($metadata['system_hotel_id']) : null;
        $hotelId = $hotelPresent ? $this->strictPositiveInt($metadata['hotel_id']) : null;
        $bindingInvalid = ($systemHotelPresent && $systemHotelId === null)
            || ($hotelPresent && $hotelId === null);
        $bindingConflict = $systemHotelId !== null && $hotelId !== null && $systemHotelId !== $hotelId;
        if ($impliedHotelId !== null) {
            $bindingConflict = $bindingConflict
                || ($systemHotelId !== null && $systemHotelId !== $impliedHotelId)
                || ($hotelId !== null && $hotelId !== $impliedHotelId);
        }
        $boundHotelId = $systemHotelId ?? $hotelId ?? $impliedHotelId;

        $providedTenantPresent = array_key_exists('tenant_id', $metadata)
            && $metadata['tenant_id'] !== null
            && $metadata['tenant_id'] !== '';
        $providedTenantId = $providedTenantPresent
            ? $this->strictPositiveInt($metadata['tenant_id'])
            : null;
        $tenantId = $boundHotelId === null ? null : ($hotelTenants[$boundHotelId] ?? null);
        $tenantMismatch = $boundHotelId !== null
            && ($tenantId === null
                || $tenantId <= 0
                || ($providedTenantPresent && ($providedTenantId === null || $providedTenantId !== $tenantId)));

        $credentialStatus = strtolower(trim((string)($metadata['credential_status'] ?? '')));
        $hasExistingMetadata = $this->strictPositiveInt($metadata['credential_ref'] ?? null) !== null
            || in_array($credentialStatus, ['ready', 'revoked', 'superseded'], true);
        $vaultRecordExists = $tenantId !== null
            && $tenantId > 0
            && $boundHotelId !== null
            && $platform !== ''
            && $this->validConfigId($configId)
            && $this->vaultLocatorExists($tenantId, $boundHotelId, $platform, $configId);
        $verifiedVaultMetadata = $vaultRecordExists
            ? $this->vaultLocatorVerifiedMetadata($tenantId, $boundHotelId, $platform, $configId)
            : null;
        $existingVault = is_array($verifiedVaultMetadata);
        $credentialRef = $this->strictPositiveInt($metadata['credential_ref'] ?? null);
        $legacySuperseded = $credentialStatus === 'superseded';
        $vaultCredentialRef = $this->strictPositiveInt($verifiedVaultMetadata['credential_ref'] ?? null);
        $credentialReferenceMatches = $existingVault
            && $credentialRef !== null
            && $vaultCredentialRef !== null
            && $credentialRef === $vaultCredentialRef;
        $credentialMetadataMatchesVault = $credentialReferenceMatches
            && $credentialStatus === 'ready';
        $isBrowserProfileSource = $sourceKind === 'platform_source'
            && in_array(strtolower(trim($ingestionMethod)), ['browser_profile', 'profile_browser'], true);
        $profileCredentialMetadataDetached = $isBrowserProfileSource
            && strtolower(trim((string)($metadata['credential_usage'] ?? ''))) === 'not_required_for_browser_profile'
            && strtolower(trim((string)($metadata['credential_status'] ?? ''))) === 'not_required'
            && !$this->truthy($metadata['has_secret'] ?? false)
            && !$this->truthy($metadata['has_cookies'] ?? false)
            && (!$vaultRecordExists || in_array(
                strtolower(trim((string)($metadata['profile_vault_detachment_status'] ?? ''))),
                ['not_present', 'revoked', 'preserved_enabled_non_profile_reference'],
                true
            ));
        $profileCredentialMaterialPresent = $isBrowserProfileSource
            && ($this->hasNonEmptyScalar($secrets)
                || $hasExistingMetadata
                || ($vaultRecordExists && !$profileCredentialMetadataDetached));

        $classification = 'bound_verified';
        $reasonCode = 'verified_binding';
        if ($profileCredentialMaterialPresent) {
            $classification = 'profile_secret_cleanup_required';
            $reasonCode = 'browser_profile_credential_material_forbidden';
        } elseif ($platformConflict || $locatorConflict || $bindingConflict) {
            $classification = 'field_conflict';
            $reasonCode = $platformConflict
                ? 'platform_conflict'
                : ($locatorConflict ? 'config_id_conflict' : 'hotel_binding_conflict');
        } elseif ($platform === '' || !$this->validConfigId($configId) || $bindingInvalid || $boundHotelId === null) {
            $classification = 'unbound';
            $reasonCode = $platform === ''
                ? 'platform_unknown'
                : (!$this->validConfigId($configId) ? 'config_id_missing_or_invalid' : 'hotel_binding_missing_or_invalid');
        } elseif ($tenantMismatch) {
            $classification = 'tenant_mismatch';
            $reasonCode = $tenantId === null ? 'hotel_not_found' : 'tenant_binding_mismatch';
        } elseif ($legacySuperseded && !$existingVault && !$this->hasNonEmptyScalar($secrets)) {
            $classification = 'unbound';
            $reasonCode = 'superseded_credential_requires_reentry';
        } elseif ($vaultRecordExists && !$existingVault && !$this->hasNonEmptyScalar($secrets)) {
            $classification = 'unbound';
            $reasonCode = 'credential_vault_not_ready';
        } elseif ($existingVault && !$credentialMetadataMatchesVault && !$this->hasNonEmptyScalar($secrets)) {
            $classification = 'unbound';
            $reasonCode = $credentialRef === null
                ? 'credential_reference_missing'
                : (!$credentialReferenceMatches ? 'credential_reference_mismatch' : 'credential_metadata_unverified');
        } elseif ($existingVault && !$this->hasNonEmptyScalar($secrets)) {
            $classification = 'already_migrated';
            $reasonCode = 'credential_metadata_present';
        } elseif (!$this->hasNonEmptyScalar($secrets)) {
            $classification = 'unbound';
            $reasonCode = $hasExistingMetadata
                ? 'credential_metadata_unverified'
                : 'no_reusable_secret';
        }

        $itemIdentity = implode('|', [$sourceTable, (string)$sourceRowId, $sourceKind, $sourceKey, $entryKey]);
        return [
            'item_id' => substr(hash('sha256', $itemIdentity), 0, 20),
            'source_table' => $sourceTable,
            'source_row_id' => $sourceRowId,
            'source_kind' => $sourceKind,
            'source_key' => $sourceKey,
            'entry_key' => $entryKey,
            'platform' => $platform,
            'config_id' => $configId,
            'system_hotel_id' => $boundHotelId,
            'tenant_id' => $tenantId,
            'classification' => $classification,
            'reason_code' => $reasonCode,
            'has_credential_metadata' => $hasExistingMetadata
                || ($existingVault && !$profileCredentialMetadataDetached),
            'ingestion_method' => strtolower(trim($ingestionMethod)),
            'source_enabled' => $sourceEnabled,
            'secret_payload' => $secrets,
            'fingerprint' => $this->fingerprint($fingerprintPayload ?? $payload),
        ];
    }

    /** @param array<string, mixed> $item */
    private function shouldInventoryItem(array $item): bool
    {
        return $this->hasNonEmptyScalar($item['secret_payload'] ?? [])
            || !empty($item['has_credential_metadata']);
    }

    /** @param array<int, array<string, mixed>> $items */
    private function classifyDuplicateLocators(array &$items): void
    {
        $counts = [];
        foreach ($items as $item) {
            $platform = (string)($item['platform'] ?? '');
            $configId = (string)($item['config_id'] ?? '');
            if ($platform === '' || !$this->validConfigId($configId)) {
                continue;
            }
            $locator = implode('|', [
                (string)($item['tenant_id'] ?? ''),
                (string)($item['system_hotel_id'] ?? ''),
                $platform,
                $configId,
            ]);
            $counts[$locator] = ($counts[$locator] ?? 0) + 1;
        }

        foreach ($items as &$item) {
            if (($item['classification'] ?? '') !== 'bound_verified') {
                continue;
            }
            $locator = implode('|', [
                (string)($item['tenant_id'] ?? ''),
                (string)($item['system_hotel_id'] ?? ''),
                (string)$item['platform'],
                (string)$item['config_id'],
            ]);
            if (($counts[$locator] ?? 0) > 1) {
                $item['classification'] = 'duplicate_config_id';
                $item['reason_code'] = 'duplicate_config_id';
            }
        }
        unset($item);
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function migrateItem(array $item): array
    {
        $current = $this->currentSourceMaterial($item);
        if (!hash_equals((string)$item['fingerprint'], $this->fingerprint($current['fingerprint_payload']))) {
            throw new RuntimeException('Legacy OTA source changed during migration.');
        }

        $secrets = $current['secrets'];
        if (!$this->hasNonEmptyScalar($secrets)) {
            throw new RuntimeException('Legacy OTA source no longer contains migratable credentials.');
        }

        $tenantId = (int)($item['tenant_id'] ?? 0);
        $hotelId = (int)($item['system_hotel_id'] ?? 0);
        $platform = (string)($item['platform'] ?? '');
        $configId = (string)($item['config_id'] ?? '');
        if ($tenantId <= 0 || $hotelId <= 0 || !$this->validConfigId($configId) || !in_array($platform, self::PLATFORMS, true)) {
            throw new RuntimeException('Legacy OTA source scope is invalid.');
        }

        $vaultMetadata = $this->storeCredential(
            $tenantId,
            $hotelId,
            $platform,
            $configId,
            $secrets
        );
        $credentialRef = $this->strictPositiveInt($vaultMetadata['credential_ref'] ?? null);
        if ($credentialRef === null
            || strtolower(trim((string)($vaultMetadata['credential_status'] ?? ''))) !== 'ready') {
            throw new RuntimeException('OTA credential vault returned invalid migration metadata.');
        }

        $safeCredentialMetadata = [
            'credential_ref' => $credentialRef,
            'credential_status' => 'ready',
            'has_cookies' => $this->containsNonEmptyCookie($secrets),
            'secret_mask' => trim((string)($vaultMetadata['secret_mask'] ?? '')),
        ];
        $safeMetadata = $current['metadata'];
        foreach (['key_id', 'payload_version', 'encrypted_payload', 'ciphertext'] as $internalKey) {
            unset($safeMetadata[$internalKey]);
        }
        $safeMetadata['id'] = $configId;
        $safeMetadata['config_id'] = $configId;
        if (in_array((string)($item['source_kind'] ?? ''), ['config_list', 'cookie_cache'], true)) {
            $safeMetadata['hotel_id'] = (string)$hotelId;
        }
        $safeMetadata['system_hotel_id'] = $hotelId;
        $safeMetadata = array_merge($safeMetadata, $safeCredentialMetadata);

        $this->writeSanitizedSource($item, $current, $safeMetadata);

        return [
            'item_id' => (string)$item['item_id'],
            'source_table' => (string)$item['source_table'],
            'source_row_id' => (int)$item['source_row_id'],
            'credential_ref' => $credentialRef,
        ];
    }

    /** @param array<string, mixed> $item */
    private function isMissingHotelCredential(array $item): bool
    {
        return ($item['classification'] ?? '') === 'tenant_mismatch'
            && ($item['reason_code'] ?? '') === 'hotel_not_found';
    }

    /**
     * Remove credentials whose canonical hotel no longer exists without creating
     * a new ownership assertion or copying them into the vault.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function sanitizeMissingHotelCredential(array $item): array
    {
        if (!$this->isMissingHotelCredential($item)) {
            throw new RuntimeException('Legacy OTA source is not an orphan credential.');
        }
        $hotelId = $this->strictPositiveInt($item['system_hotel_id'] ?? null);
        if ($hotelId === null) {
            throw new RuntimeException('Legacy OTA orphan hotel scope is invalid.');
        }

        $current = $this->currentSourceMaterial($item);
        if (!hash_equals((string)$item['fingerprint'], $this->fingerprint($current['fingerprint_payload']))) {
            throw new RuntimeException('Legacy OTA source changed during migration.');
        }
        if (Db::name('hotels')->where('id', $hotelId)->lock(true)->find()) {
            throw new RuntimeException('Legacy OTA hotel binding changed; run a new dry-run.');
        }

        $safeMetadata = $current['metadata'];
        foreach ([
            'credential_ref',
            'credential_status',
            'has_cookies',
            'secret_mask',
            'migration_required',
            'migration_reason',
            'key_id',
            'payload_version',
            'encrypted_payload',
            'ciphertext',
        ] as $legacyCredentialKey) {
            unset($safeMetadata[$legacyCredentialKey]);
        }
        $safeMetadata['credential_status'] = 'migration_required';
        $safeMetadata['migration_required'] = true;
        $safeMetadata['migration_reason'] = 'hotel_not_found';
        $safeMetadata['has_cookies'] = false;
        $safeMetadata['secret_mask'] = '';

        $this->writeSanitizedSource($item, $current, $safeMetadata);

        return [
            'item_id' => (string)$item['item_id'],
            'source_table' => (string)$item['source_table'],
            'source_row_id' => (int)$item['source_row_id'],
            'reason_code' => 'hotel_not_found',
        ];
    }

    /**
     * Browser Profile authorization is owned by a local profile binding and a
     * current browser session. Reusable secrets and Vault locators must never
     * remain attached to that source model.
     *
     * @param array<string, mixed> $item
     * @param array<int, array<string, mixed>> $inventoryItems
     * @return array<string, mixed>
     */
    private function sanitizeProfileCredentialMaterial(array $item, array $inventoryItems): array
    {
        if (($item['classification'] ?? '') !== 'profile_secret_cleanup_required'
            || ($item['source_kind'] ?? '') !== 'platform_source'
            || !in_array((string)($item['ingestion_method'] ?? ''), ['browser_profile', 'profile_browser'], true)) {
            throw new RuntimeException('Legacy OTA source is not a Browser Profile credential cleanup target.');
        }

        $current = $this->currentSourceMaterial($item);
        if (!hash_equals((string)$item['fingerprint'], $this->fingerprint($current['fingerprint_payload']))) {
            throw new RuntimeException('Legacy OTA source changed during migration.');
        }

        $safeMetadata = $current['metadata'];
        foreach ([
            'credential_ref',
            'credential_status',
            'credential_status_label',
            'credential_metadata_status',
            'credential_usage',
            'status',
            'has_secret',
            'has_cookies',
            'cookie_configured',
            'secret_mask',
            'migration_required',
            'migration_reason',
            'key_id',
            'payload_version',
            'encrypted_payload',
            'ciphertext',
            'rotated_at',
        ] as $legacyCredentialKey) {
            unset($safeMetadata[$legacyCredentialKey]);
        }
        $safeMetadata = array_merge($safeMetadata, [
            'credential_usage' => 'not_required_for_browser_profile',
            'credential_status' => 'not_required',
            'status' => 'not_required',
            'has_secret' => false,
            'has_cookies' => false,
            'profile_execution_policy' => 'profile_session_metadata_only_no_vault_decrypt',
        ]);

        $vaultAction = 'not_present';
        $tenantId = $this->strictPositiveInt($item['tenant_id'] ?? null);
        $hotelId = $this->strictPositiveInt($item['system_hotel_id'] ?? null);
        $platform = (string)($item['platform'] ?? '');
        $configId = (string)($item['config_id'] ?? '');
        if ($tenantId !== null
            && $hotelId !== null
            && in_array($platform, self::PLATFORMS, true)
            && $this->validConfigId($configId)
            && $this->vaultLocatorExists($tenantId, $hotelId, $platform, $configId)) {
            if ($this->hasEnabledNonProfileCredentialReference($item, $inventoryItems)) {
                $vaultAction = 'preserved_enabled_non_profile_reference';
            } else {
                $this->credentialVault()->revoke($tenantId, $hotelId, $platform, $configId);
                $vaultAction = 'revoked';
            }
        }
        $safeMetadata['profile_vault_detachment_status'] = $vaultAction;
        $this->writeSanitizedSource($item, $current, $safeMetadata);

        return [
            'item_id' => (string)$item['item_id'],
            'source_table' => (string)$item['source_table'],
            'source_row_id' => (int)$item['source_row_id'],
            'reason_code' => 'browser_profile_credential_material_forbidden',
            'vault_action' => $vaultAction,
        ];
    }

    /**
     * @param array<string, mixed> $profileItem
     * @param array<int, array<string, mixed>> $inventoryItems
     */
    private function hasEnabledNonProfileCredentialReference(array $profileItem, array $inventoryItems): bool
    {
        foreach ($inventoryItems as $candidate) {
            if (hash_equals((string)($profileItem['item_id'] ?? ''), (string)($candidate['item_id'] ?? ''))) {
                continue;
            }
            if ((int)($candidate['tenant_id'] ?? 0) !== (int)($profileItem['tenant_id'] ?? 0)
                || (int)($candidate['system_hotel_id'] ?? 0) !== (int)($profileItem['system_hotel_id'] ?? 0)
                || !hash_equals((string)($candidate['platform'] ?? ''), (string)($profileItem['platform'] ?? ''))
                || !hash_equals((string)($candidate['config_id'] ?? ''), (string)($profileItem['config_id'] ?? ''))) {
                continue;
            }
            if (in_array((string)($candidate['ingestion_method'] ?? ''), ['browser_profile', 'profile_browser'], true)) {
                continue;
            }
            if (($candidate['source_kind'] ?? '') === 'platform_source'
                && empty($candidate['source_enabled'])) {
                continue;
            }
            return true;
        }
        return false;
    }

    /**
     * @param array<string, mixed> $item
     * @return array{metadata: array<string, mixed>, secrets: array<string, mixed>, fingerprint_payload: mixed, container: array<string, mixed>}
     */
    private function currentSourceMaterial(array $item): array
    {
        $table = (string)$item['source_table'];
        $rowId = (int)$item['source_row_id'];
        $row = Db::name($table)->where('id', $rowId)->lock(true)->find();
        if (!$row) {
            throw new RuntimeException('Legacy OTA source row no longer exists.');
        }

        if (($item['source_kind'] ?? '') === 'platform_source') {
            $config = $this->decodeOptionalJsonObject((string)($row['config_json'] ?? ''));
            $secret = $this->decodeOptionalJsonObject((string)($row['secret_json'] ?? ''));
            if (isset($row['config_id']) && !isset($config['config_id'])) {
                $config['config_id'] = $row['config_id'];
            }
            $config['system_hotel_id'] = $row['system_hotel_id'] ?? null;
            if (array_key_exists('tenant_id', $row)) {
                $config['tenant_id'] = $row['tenant_id'];
            }
            $config['platform'] = $row['platform'] ?? '';
            [$metadata, $configSecrets] = $this->splitSecrets($config);
            [, $storedSecrets] = $this->splitSecrets($secret);
            return [
                'metadata' => $metadata,
                'secrets' => array_replace_recursive($configSecrets, $storedSecrets),
                'fingerprint_payload' => [$config, $secret, (string)($row['ingestion_method'] ?? '')],
                'container' => ['row' => $row, 'config' => $config],
            ];
        }

        $decoded = $this->decodeJsonObject((string)($row['config_value'] ?? ''));
        if (($item['source_kind'] ?? '') === 'data_config') {
            [$metadata, $secrets] = $this->splitSecrets($decoded);
            return [
                'metadata' => $metadata,
                'secrets' => $secrets,
                'fingerprint_payload' => $decoded,
                'container' => ['row' => $row, 'decoded' => $decoded],
            ];
        }

        $entryKey = (string)($item['entry_key'] ?? '');
        $entry = $this->arrayEntryByStringKey($decoded, $entryKey);
        if (!is_array($entry)) {
            throw new RuntimeException('Legacy OTA source item no longer exists.');
        }
        [$metadata, $secrets] = $this->splitSecrets($entry);
        return [
            'metadata' => $metadata,
            'secrets' => $secrets,
            'fingerprint_payload' => $entry,
            'container' => ['row' => $row, 'decoded' => $decoded],
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $current
     * @param array<string, mixed> $safeMetadata
     */
    private function writeSanitizedSource(array $item, array $current, array $safeMetadata): void
    {
        $table = (string)$item['source_table'];
        $rowId = (int)$item['source_row_id'];
        $now = date('Y-m-d H:i:s');

        if (($item['source_kind'] ?? '') === 'platform_source') {
            $update = [
                'config_json' => $this->encodeJson($safeMetadata),
                'secret_json' => '{}',
            ];
            if ($this->tableHasColumn($table, 'update_time')) {
                $update['update_time'] = $now;
            }
            if (Db::name($table)->where('id', $rowId)->update($update) < 0) {
                throw new RuntimeException('Legacy OTA platform source update failed.');
            }
            return;
        }

        $decoded = $current['container']['decoded'] ?? [];
        if (($item['source_kind'] ?? '') === 'data_config') {
            $decoded = $safeMetadata;
        } else {
            $entryKey = (string)($item['entry_key'] ?? '');
            $found = false;
            foreach ($decoded as $key => $_value) {
                if ((string)$key === $entryKey) {
                    $decoded[$key] = $safeMetadata;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                throw new RuntimeException('Legacy OTA source item changed during migration.');
            }
        }

        $update = ['config_value' => $this->encodeJson($decoded)];
        if ($this->tableHasColumn($table, 'update_time')) {
            $update['update_time'] = $now;
        }
        if (Db::name($table)->where('id', $rowId)->update($update) < 0) {
            throw new RuntimeException('Legacy OTA configuration update failed.');
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function storeCredential(
        int $tenantId,
        int $hotelId,
        string $platform,
        string $configId,
        array $payload
    ): array {
        if ($this->credentialStore !== null) {
            $result = ($this->credentialStore)(
                $tenantId,
                $hotelId,
                $platform,
                $configId,
                $payload,
                $this->actorId
            );
        } else {
            $result = $this->credentialVault()->store(
                $tenantId,
                $hotelId,
                $platform,
                $configId,
                $payload,
                $this->actorId
            );
        }
        if (!is_array($result)) {
            throw new RuntimeException('OTA credential vault returned invalid migration metadata.');
        }
        return $result;
    }

    private function credentialVault(): OtaCredentialVault
    {
        return $this->vault ??= new OtaCredentialVault();
    }

    /**
     * @param array<string, mixed> $inventory
     * @param array<int, array<string, mixed>> $migrated
     * @param array<int, array<string, mixed>> $sanitized
     * @param array<string, mixed>|null $postInventory
     * @return array<string, mixed>
     */
    private function safeSummary(
        string $mode,
        array $inventory,
        array $migrated,
        array $sanitized,
        ?array $postInventory = null,
        array $relocated = []
    ): array
    {
        $blockers = array_values(array_unique(array_map('strval', array_merge(
            $inventory['blockers'] ?? [],
            $postInventory['blockers'] ?? []
        ))));
        sort($blockers);
        $schemaMissing = array_values(array_filter(
            $blockers,
            static fn(string $blocker): bool => str_starts_with($blocker, 'schema_missing:')
        ));
        $safeItems = [];
        $counts = array_fill_keys(self::CLASSIFICATIONS, 0);
        foreach ($inventory['items'] ?? [] as $item) {
            $classification = (string)($item['classification'] ?? 'unbound');
            if (isset($counts[$classification])) {
                $counts[$classification]++;
            }
            $safeItems[] = [
                'item_id' => (string)($item['item_id'] ?? ''),
                'source_table' => (string)($item['source_table'] ?? ''),
                'source_row_id' => (int)($item['source_row_id'] ?? 0),
                'source_kind' => (string)($item['source_kind'] ?? ''),
                'platform' => (string)($item['platform'] ?? ''),
                'system_hotel_id' => $this->strictPositiveInt($item['system_hotel_id'] ?? null),
                'classification' => $classification,
                'reason_code' => (string)($item['reason_code'] ?? ''),
            ];
        }
        $safeRelocations = [];
        $metadataRelocationEligibleCount = 0;
        foreach ($inventory['metadata_relocations'] ?? [] as $relocation) {
            $classification = (string)($relocation['classification'] ?? 'conflict');
            if (in_array($classification, ['relocation_required', 'legacy_retirement_required'], true)) {
                $metadataRelocationEligibleCount++;
            }
            $safeRelocations[] = [
                'config_key' => (string)($relocation['config_key'] ?? ''),
                'source_table' => (string)($relocation['source_table'] ?? ''),
                'target_table' => (string)($relocation['target_table'] ?? ''),
                'source_row_id' => (int)($relocation['source_row_id'] ?? 0),
                'target_row_id' => $this->strictPositiveInt($relocation['target_row_id'] ?? null),
                'classification' => $classification,
                'reason_code' => (string)($relocation['reason_code'] ?? ''),
            ];
        }
        $postMetadataRelocations = $postInventory === null
            ? ($inventory['metadata_relocations'] ?? [])
            : ($postInventory['metadata_relocations'] ?? []);
        $safeRelocated = [];
        foreach ($relocated as $item) {
            $safeRelocated[] = [
                'config_key' => (string)($item['config_key'] ?? ''),
                'source_table' => (string)($item['source_table'] ?? ''),
                'target_table' => (string)($item['target_table'] ?? ''),
                'source_row_id' => (int)($item['source_row_id'] ?? 0),
                'target_row_id' => (int)($item['target_row_id'] ?? 0),
                'action' => (string)($item['action'] ?? ''),
            ];
        }
        $initialRemainingIssueCount = $counts['unbound']
            + $counts['field_conflict']
            + $counts['duplicate_config_id']
            + $counts['tenant_mismatch']
            + $counts['profile_secret_cleanup_required'];
        $postExecutionCounts = $counts;
        if ($postInventory !== null) {
            $postExecutionCounts = array_fill_keys(self::CLASSIFICATIONS, 0);
            foreach ($postInventory['items'] ?? [] as $item) {
                $classification = (string)($item['classification'] ?? 'unbound');
                if (isset($postExecutionCounts[$classification])) {
                    $postExecutionCounts[$classification]++;
                }
            }
        }
        $remainingIssueCount = $postExecutionCounts['unbound']
            + $postExecutionCounts['field_conflict']
            + $postExecutionCounts['duplicate_config_id']
            + $postExecutionCounts['tenant_mismatch']
            + $postExecutionCounts['profile_secret_cleanup_required'];
        $nonSchemaBlockers = array_values(array_filter(
            $blockers,
            static fn(string $blocker): bool => !str_starts_with($blocker, 'schema_missing:')
        ));
        if ($mode === 'execute') {
            $status = $blockers !== []
                ? 'blocked'
                : ($remainingIssueCount > 0 || $postMetadataRelocations !== []
                    ? 'migration_required'
                    : 'completed');
        } elseif ($nonSchemaBlockers !== []) {
            $status = 'blocked';
        } elseif ($schemaMissing !== [] || $remainingIssueCount > 0 || $postMetadataRelocations !== []) {
            $status = 'migration_required';
        } else {
            $status = 'ready';
        }

        return [
            'mode' => $mode,
            'status' => $status,
            'blockers' => $blockers,
            'inventory_count' => count($safeItems),
            'eligible_count' => $counts['bound_verified'],
            'migrated_count' => count($migrated),
            'sanitized_count' => count($sanitized),
            'metadata_relocation_count' => count($safeRelocations),
            'metadata_relocation_eligible_count' => $metadataRelocationEligibleCount,
            'metadata_relocated_count' => count($safeRelocated),
            'remaining_metadata_relocation_count' => count($postMetadataRelocations),
            'initial_remaining_issue_count' => $initialRemainingIssueCount,
            'remaining_issue_count' => $remainingIssueCount,
            'classification_counts' => $counts,
            'post_execution_classification_counts' => $postExecutionCounts,
            'sources' => $inventory['sources'] ?? [],
            'items' => $safeItems,
            'migrated' => array_values($migrated),
            'sanitized' => array_values($sanitized),
            'metadata_relocations' => $safeRelocations,
            'metadata_relocated' => $safeRelocated,
        ];
    }

    /**
     * @param array<string|int, mixed> $value
     * @return array{0: array<string|int, mixed>, 1: array<string|int, mixed>}
     */
    private function splitSecrets(array $value): array
    {
        $metadata = [];
        $secrets = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array(strtolower(trim($key)), ['key_id', 'payload_version'], true)) {
                continue;
            }
            if (is_string($key) && $this->isSecretKey($key)) {
                $secrets[$key] = $item;
                continue;
            }
            if (is_array($item)) {
                if ($item === []) {
                    $metadata[$key] = [];
                    continue;
                }
                [$nestedMetadata, $nestedSecrets] = $this->splitSecrets($item);
                if ($nestedMetadata !== []) {
                    $metadata[$key] = $nestedMetadata;
                }
                if ($nestedSecrets !== []) {
                    $secrets[$key] = $nestedSecrets;
                }
                continue;
            }
            if (is_string($item) && $this->stringContainsCredentialMaterial($item)) {
                $secrets[$key] = $item;
                continue;
            }
            $metadata[$key] = $item;
        }
        return [$metadata, $secrets];
    }

    private function isSecretKey(string $key): bool
    {
        $camelSeparated = (string)preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', trim($key));
        $normalized = strtolower((string)preg_replace('/[^a-z0-9]+/i', '_', $camelSeparated));
        $normalized = trim($normalized, '_');
        $compact = str_replace('_', '', $normalized);
        if (in_array($normalized, [
            'cookies', 'cookie', 'auth_data', 'authorization', 'authorization_header',
            'token', 'spiderkey', 'spider_key', 'spidertoken', 'mtgsig', 'mtsi_eb_u',
            'usertoken', 'usersign', 'password', 'secret', 'api_key', 'secret_json',
            'auth_token', 'headers', 'headers_json', 'set_cookie', 'access_token',
            'refresh_token', 'encrypted_payload', 'ciphertext',
        ], true) || in_array($compact, [
            'authdata', 'apikey', 'spiderkey', 'spidertoken', 'mtgsig', 'mtsiebu',
            'secretjson', 'authtoken', 'authorizationheader', 'headersjson',
            'setcookie', 'accesstoken', 'refreshtoken', 'encryptedpayload',
        ], true)) {
            return true;
        }
        return preg_match('/^(?:x_)?(?:access|refresh|auth|client)_(?:secret|token)$/D', $normalized) === 1;
    }

    private function stringContainsCredentialMaterial(string $value): bool
    {
        return preg_match('/["\']?(?:cookie|set-cookie|authorization|proxy-authorization|x-api-key|api-key|auth_data|token|access_token|refresh_token|spidertoken|spiderkey|mtgsig|usertoken|usersign|password)["\']?\s*[:=]/i', $value) === 1
            || preg_match('/\bbearer\s+[A-Za-z0-9._~+\/=:-]{8,}/i', $value) === 1;
    }

    private function containsNonEmptyCookie(array $value): bool
    {
        foreach ($value as $key => $item) {
            $normalized = is_string($key)
                ? strtolower((string)preg_replace('/[^a-z0-9]+/i', '', $key))
                : '';
            if (in_array($normalized, ['cookie', 'cookies', 'setcookie'], true)
                && $this->hasNonEmptyScalar($item)) {
                return true;
            }
            if (is_array($item) && $this->containsNonEmptyCookie($item)) {
                return true;
            }
        }
        return false;
    }

    private function hasNonEmptyScalar(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->hasNonEmptyScalar($item)) {
                    return true;
                }
            }
            return false;
        }
        return is_scalar($value) && trim((string)$value) !== '';
    }

    private function truthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'yes', 'on'], true);
    }

    private function hotelIdFromLegacyCookieKey(string $key): ?int
    {
        if (preg_match('/^online_data_cookies_(?:hotel_)?([1-9][0-9]*)$/D', $key, $matches) !== 1) {
            return null;
        }
        return $this->strictPositiveInt($matches[1]);
    }

    private function normalizePlatform(mixed $value): string
    {
        $platform = strtolower(trim((string)$value));
        return in_array($platform, self::PLATFORMS, true) ? $platform : '';
    }

    private function validConfigId(string $configId): bool
    {
        return preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $configId) === 1;
    }

    private function strictPositiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', trim($value)) !== 1) {
            return null;
        }
        $integer = (int)trim($value);
        return $integer > 0 ? $integer : null;
    }

    /** @return array<int, int> */
    private function hotelTenantMap(): array
    {
        $result = [];
        foreach (Db::name('hotels')->field(['id', 'tenant_id'])->select()->toArray() as $row) {
            $hotelId = $this->strictPositiveInt($row['id'] ?? null);
            if ($hotelId === null) {
                continue;
            }
            $result[$hotelId] = (int)($row['tenant_id'] ?? 0);
        }
        ksort($result);
        return $result;
    }

    private function vaultLocatorExists(int $tenantId, int $hotelId, string $platform, string $configId): bool
    {
        if (!$this->tableExists('ota_credentials')) {
            return false;
        }
        return Db::name('ota_credentials')
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('platform', $platform)
            ->where('config_id', $configId)
            ->count() > 0;
    }

    /** @return array<string, mixed>|null */
    private function vaultLocatorVerifiedMetadata(int $tenantId, int $hotelId, string $platform, string $configId): ?array
    {
        try {
            return $this->credentialVault()->verifiedMetadataForExecution(
                $tenantId,
                $hotelId,
                $platform,
                $configId
            );
        } catch (Throwable) {
            return null;
        }
    }

    private function tableExists(string $table): bool
    {
        if (!in_array($table, ['hotels', 'ota_credentials', 'system_config', 'system_configs', 'platform_data_sources'], true)) {
            return false;
        }
        try {
            Db::query('SELECT 1 FROM `' . $table . '` LIMIT 1');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, true> */
    private function tableColumns(string $table): array
    {
        if (!in_array($table, ['hotels', 'system_config', 'system_configs', 'platform_data_sources'], true)) {
            return [];
        }
        try {
            $columns = [];
            foreach (Db::query('SHOW COLUMNS FROM `' . $table . '`') as $row) {
                $name = trim((string)($row['Field'] ?? ''));
                if ($name !== '') {
                    $columns[$name] = true;
                }
            }
            return $columns;
        } catch (Throwable) {
            $columns = [];
            foreach (Db::query('PRAGMA table_info(`' . $table . '`)') as $row) {
                $name = trim((string)($row['name'] ?? ''));
                if ($name !== '') {
                    $columns[$name] = true;
                }
            }
            return $columns;
        }
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        return isset($this->tableColumns($table)[$column]);
    }

    /** @return array<string, mixed> */
    private function decodeJsonObject(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new JsonException('Legacy OTA JSON must be an object or array.');
        }
        return $decoded;
    }

    /** @return array<string, mixed> */
    private function decodeOptionalJsonObject(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }
        return $this->decodeJsonObject($json);
    }

    private function encodeJson(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function fingerprint(mixed $value): string
    {
        return hash('sha256', json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    }

    private function arrayEntryByStringKey(array $values, string $wanted): mixed
    {
        foreach ($values as $key => $value) {
            if ((string)$key === $wanted) {
                return $value;
            }
        }
        return null;
    }
}
