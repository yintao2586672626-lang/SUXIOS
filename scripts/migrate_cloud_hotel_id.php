#!/usr/bin/env php
<?php
declare(strict_types=1);

use think\App;
use think\facade\Db;

require_once __DIR__ . DIRECTORY_SEPARATOR . 'cloud_hotel_id_column_registry.php';

if (!defined('CLOUD_HOTEL_ID_MIGRATION_DATABASE')) {
    define('CLOUD_HOTEL_ID_MIGRATION_DATABASE', 'hotelx_cloud');
}
const CLOUD_HOTEL_ID_MIGRATION_CONFIRMATION = 'RENAME_CLOUD_HOTEL_ID';
const CLOUD_HOTEL_ID_MIGRATION_MAINTENANCE_CONFIRMATION = 'ALL_WRITERS_PAUSED';
const CLOUD_HOTEL_ID_MIGRATION_LOCK_NAME = 'suxios:cloud_hotel_id_migration:v2';
const CLOUD_HOTEL_ID_MIGRATION_LOCK_TIMEOUT_SECONDS = 30;
const CLOUD_HOTEL_ID_MIGRATION_RUNTIME_ENV = '/etc/suxios/dingdandao-collector.env';
const CLOUD_HOTEL_ID_MIGRATION_SYSTEMCTL = '/usr/bin/systemctl';
const CLOUD_HOTEL_ID_MIGRATION_STOPPED_UNITS = [
    'suxios-dingdandao-collection.timer',
    'suxios-dingdandao-collection.service',
];

/** @return string */
function cloudHotelIdMigrationIdentifier(string $value): string
{
    if (preg_match('/^[A-Za-z0-9_]+$/D', $value) !== 1) {
        throw new RuntimeException('unsafe_database_identifier');
    }
    return '`' . $value . '`';
}

/** @return array{from_count:int,to_count:int,total_rows:int} */
function cloudHotelIdMigrationCounts(string $table, string $column, int $fromHotelId, int $toHotelId): array
{
    $rows = Db::query(
        'SELECT COUNT(*) AS total_rows,'
        . 'SUM(' . cloudHotelIdMigrationIdentifier($column) . '=' . $fromHotelId . ') AS from_count,'
        . 'SUM(' . cloudHotelIdMigrationIdentifier($column) . '=' . $toHotelId . ') AS to_count '
        . 'FROM ' . cloudHotelIdMigrationIdentifier($table)
    );
    $row = $rows[0] ?? [];
    return [
        'total_rows' => (int)($row['total_rows'] ?? $row['TOTAL_ROWS'] ?? 0),
        'from_count' => (int)($row['from_count'] ?? $row['FROM_COUNT'] ?? 0),
        'to_count' => (int)($row['to_count'] ?? $row['TO_COUNT'] ?? 0),
    ];
}

/** @return array{from_count:int,to_count:int,total_rows:int} */
function cloudHotelIdMigrationTextCounts(string $table, string $column, int $fromHotelId, int $toHotelId): array
{
    $rows = Db::query(
        'SELECT COUNT(*) AS total_rows,'
        . 'SUM(CAST(' . cloudHotelIdMigrationIdentifier($column) . ' AS CHAR)=?) AS from_count,'
        . 'SUM(CAST(' . cloudHotelIdMigrationIdentifier($column) . ' AS CHAR)=?) AS to_count '
        . 'FROM ' . cloudHotelIdMigrationIdentifier($table),
        [(string)$fromHotelId, (string)$toHotelId]
    );
    $row = $rows[0] ?? [];
    return [
        'total_rows' => (int)($row['total_rows'] ?? $row['TOTAL_ROWS'] ?? 0),
        'from_count' => (int)($row['from_count'] ?? $row['FROM_COUNT'] ?? 0),
        'to_count' => (int)($row['to_count'] ?? $row['TO_COUNT'] ?? 0),
    ];
}

function cloudHotelIdMigrationDerivedMismatchCount(string $table, string $column, string $sourceColumn): int
{
    $rows = Db::query(
        'SELECT COUNT(*) AS mismatch_count FROM ' . cloudHotelIdMigrationIdentifier($table)
        . ' WHERE ' . cloudHotelIdMigrationIdentifier($column) . ' IS NOT NULL'
        . ' AND ' . cloudHotelIdMigrationIdentifier($column)
        . '<>' . cloudHotelIdMigrationIdentifier($sourceColumn)
    );
    return (int)($rows[0]['mismatch_count'] ?? $rows[0]['MISMATCH_COUNT'] ?? 0);
}

function cloudHotelIdMigrationTableColumnExists(string $table, string $column): bool
{
    $rows = Db::query(
        'SELECT COUNT(*) AS column_count FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?',
        [CLOUD_HOTEL_ID_MIGRATION_DATABASE, $table, $column]
    );
    return (int)($rows[0]['column_count'] ?? $rows[0]['COLUMN_COUNT'] ?? 0) === 1;
}

/** @param array<int,string> $identityKeys */
function cloudHotelIdMigrationJsonReferencePattern(array $identityKeys, int $hotelId): string
{
    $safeKeys = array_values(array_filter(
        array_unique($identityKeys),
        static fn(string $key): bool => preg_match('/^[a-z_]+$/D', $key) === 1
    ));
    if ($safeKeys === []) {
        throw new RuntimeException('json_identity_keys_required');
    }
    return '\\"(' . implode('|', array_map('preg_quote', $safeKeys))
        . ')\\"[[:space:]]*:[[:space:]]*\\"?' . $hotelId . '\\"?([,}[:space:]]|$)';
}

/** @return array<int,string> */
function cloudHotelIdMigrationAllJsonIdentityKeys(): array
{
    return [
        'hotel_id',
        'system_hotel_id',
        'default_hotel_id',
        'collector_hotel_id',
        'source_system_hotel_id',
        'destination_system_hotel_id',
    ];
}

/** @param array<int,string> $identityKeys @return array{reference_count:int,multiset_sha256:string} */
function cloudHotelIdMigrationJsonReferenceDigest(
    string $table,
    string $column,
    int $hotelId,
    array $identityKeys
): array {
    $rows = Db::query(
        'SELECT CAST(' . cloudHotelIdMigrationIdentifier($column) . ' AS CHAR) AS json_value '
        . 'FROM ' . cloudHotelIdMigrationIdentifier($table)
        . ' WHERE ' . cloudHotelIdMigrationIdentifier($column) . ' IS NOT NULL'
        . ' AND CAST(' . cloudHotelIdMigrationIdentifier($column) . ' AS CHAR) REGEXP ?',
        [cloudHotelIdMigrationJsonReferencePattern($identityKeys, $hotelId)]
    );
    $hashes = [];
    foreach ($rows as $row) {
        $raw = (string)($row['json_value'] ?? $row['JSON_VALUE'] ?? '');
        $hashes[] = hash('sha256', $raw);
    }
    sort($hashes, SORT_STRING);
    return [
        'reference_count' => count($hashes),
        'multiset_sha256' => hash('sha256', implode("\n", $hashes)),
    ];
}

/** @return array<string,array{table:string,column:string,policy:string}> */
function cloudHotelIdMigrationJsonCandidateColumns(): array
{
    $rows = Db::query(
        'SELECT c.TABLE_NAME AS table_name,c.COLUMN_NAME AS column_name '
        . 'FROM information_schema.COLUMNS c '
        . 'INNER JOIN information_schema.TABLES t '
        . 'ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME AND t.TABLE_TYPE=\'BASE TABLE\' '
        . 'WHERE c.TABLE_SCHEMA=? AND (c.DATA_TYPE=\'json\' OR c.COLUMN_NAME LIKE \'%\\_json\')',
        [CLOUD_HOTEL_ID_MIGRATION_DATABASE]
    );
    $policyIndex = cloudHotelIdJsonPolicyRegistryIndex();
    $candidates = [];
    foreach ($rows as $row) {
        $table = trim((string)($row['table_name'] ?? $row['TABLE_NAME'] ?? ''));
        $column = trim((string)($row['column_name'] ?? $row['COLUMN_NAME'] ?? ''));
        if ($table === '' || $column === '') {
            continue;
        }
        $key = cloudHotelIdColumnKey($table, $column);
        $candidates[$key] = [
            'table' => $table,
            'column' => $column,
            'policy' => (string)($policyIndex[$key]['policy'] ?? 'unknown'),
        ];
    }
    foreach ($policyIndex as $key => $policy) {
        if (!cloudHotelIdMigrationTableColumnExists($policy['table'], $policy['column'])) {
            continue;
        }
        $candidates[$key] = [
            'table' => $policy['table'],
            'column' => $policy['column'],
            'policy' => $policy['policy'],
        ];
    }
    ksort($candidates, SORT_STRING);
    return $candidates;
}

/** @return array<string,mixed> */
function cloudHotelIdMigrationPrepareMutableJsonEntry(
    array $policy,
    string $identityColumn,
    int|string $identityValue,
    array $receiptLocator,
    string $raw,
    int $fromHotelId,
    int $toHotelId
): array {
    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        throw new RuntimeException(
            'mutable_active_config_json_decode_failed:' . $policy['table'] . '.' . $policy['column'],
            0,
            $exception
        );
    }
    if (!is_array($decoded)) {
        throw new RuntimeException('mutable_active_config_json_not_array:' . $policy['table'] . '.' . $policy['column']);
    }
    $unclassifiedKeys = array_values(array_diff(
        cloudHotelIdMigrationAllJsonIdentityKeys(),
        $policy['identity_keys'],
        $policy['non_system_keys']
    ));
    if ($unclassifiedKeys !== []) {
        $unclassified = cloudHotelIdTransformMutableJsonValue(
            $decoded,
            $fromHotelId,
            $toHotelId,
            $unclassifiedKeys
        );
        if ($unclassified['from_count'] > 0) {
            throw new RuntimeException(
                'mutable_active_config_unclassified_identity_key:' . $policy['table'] . '.' . $policy['column']
            );
        }
    }
    $transformation = cloudHotelIdTransformMutableJsonValue(
        $decoded,
        $fromHotelId,
        $toHotelId,
        $policy['identity_keys']
    );
    if ($transformation['from_count'] === 0) {
        return [];
    }
    if ($transformation['to_count'] > 0) {
        throw new RuntimeException(
            'mutable_active_config_target_id_already_present:' . $policy['table'] . '.' . $policy['column']
        );
    }
    try {
        $afterRaw = json_encode(
            $transformation['transformed'],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    } catch (Throwable $exception) {
        throw new RuntimeException(
            'mutable_active_config_json_encode_failed:' . $policy['table'] . '.' . $policy['column'],
            0,
            $exception
        );
    }
    return [
        'table' => $policy['table'],
        'column' => $policy['column'],
        'identity_column' => $identityColumn,
        'identity_value' => $identityValue,
        'identity_keys' => $policy['identity_keys'],
        'receipt_locator' => $receiptLocator,
        'reference_count' => (int)$transformation['from_count'],
        'target_count_before' => (int)$transformation['to_count'],
        'before_sha256' => hash('sha256', $raw),
        'after_sha256' => hash('sha256', $afterRaw),
        'before_raw' => $raw,
        'after_raw' => $afterRaw,
    ];
}

function cloudHotelIdMigrationAssertMutableJsonOutsideScopeClear(int $fromHotelId): void
{
    $policyIndex = cloudHotelIdJsonPolicyRegistryIndex();
    $systemPolicy = $policyIndex['system_configs.config_value'] ?? null;
    if (is_array($systemPolicy)
        && cloudHotelIdMigrationTableColumnExists('system_configs', 'config_key')
        && cloudHotelIdMigrationTableColumnExists('system_configs', 'config_value')) {
        $rowKeys = array_values($systemPolicy['row_keys']);
        $rows = Db::query(
            'SELECT COUNT(*) AS reference_count FROM `system_configs` '
            . 'WHERE `config_key` NOT IN (' . implode(',', array_fill(0, count($rowKeys), '?')) . ') '
            . 'AND CAST(`config_value` AS CHAR) REGEXP ?',
            [...$rowKeys, cloudHotelIdMigrationJsonReferencePattern($systemPolicy['identity_keys'], $fromHotelId)]
        );
        $count = (int)($rows[0]['reference_count'] ?? $rows[0]['REFERENCE_COUNT'] ?? 0);
        if ($count > 0) {
            throw new RuntimeException('mutable_active_config_reference_outside_allowlist:system_configs.config_value:' . $count);
        }
    }
    $platformPolicy = $policyIndex['platform_data_sources.config_json'] ?? null;
    if (is_array($platformPolicy)
        && cloudHotelIdMigrationTableColumnExists('platform_data_sources', 'system_hotel_id')
        && cloudHotelIdMigrationTableColumnExists('platform_data_sources', 'config_json')) {
        $rows = Db::query(
            'SELECT COUNT(*) AS reference_count FROM `platform_data_sources` '
            . 'WHERE (`system_hotel_id` IS NULL OR `system_hotel_id`<>?) '
            . 'AND CAST(`config_json` AS CHAR) REGEXP ?',
            [$fromHotelId, cloudHotelIdMigrationJsonReferencePattern($platformPolicy['identity_keys'], $fromHotelId)]
        );
        $count = (int)($rows[0]['reference_count'] ?? $rows[0]['REFERENCE_COUNT'] ?? 0);
        if ($count > 0) {
            throw new RuntimeException('mutable_active_config_reference_outside_scope:platform_data_sources.config_json:' . $count);
        }
    }
}

/** @return array{mutable:array<string,array<string,mixed>>,historical:array<string,array<string,mixed>>} */
function cloudHotelIdMigrationJsonPreflight(int $fromHotelId, int $toHotelId, bool $lockRows): array
{
    $mutable = [];
    $policyIndex = cloudHotelIdJsonPolicyRegistryIndex();
    $systemPolicy = $policyIndex['system_configs.config_value'] ?? null;
    if (is_array($systemPolicy)
        && cloudHotelIdMigrationTableColumnExists('system_configs', 'config_key')
        && cloudHotelIdMigrationTableColumnExists('system_configs', 'config_value')) {
        $rowKeys = array_values($systemPolicy['row_keys']);
        $rows = Db::query(
            'SELECT config_key,config_value FROM `system_configs` WHERE config_key IN ('
            . implode(',', array_fill(0, count($rowKeys), '?')) . ') ORDER BY config_key'
            . ($lockRows ? ' FOR UPDATE' : ''),
            $rowKeys
        );
        $seenKeys = [];
        foreach ($rows as $row) {
            $configKey = trim((string)($row['config_key'] ?? $row['CONFIG_KEY'] ?? ''));
            if ($configKey === '' || isset($seenKeys[$configKey])) {
                throw new RuntimeException('mutable_active_config_key_not_unique:' . $configKey);
            }
            $seenKeys[$configKey] = true;
            $entry = cloudHotelIdMigrationPrepareMutableJsonEntry(
                $systemPolicy,
                'config_key',
                $configKey,
                ['config_key' => $configKey],
                (string)($row['config_value'] ?? $row['CONFIG_VALUE'] ?? ''),
                $fromHotelId,
                $toHotelId
            );
            if ($entry !== []) {
                $mutable['system_configs.config_value:' . $configKey] = $entry;
            }
        }
    }
    $platformPolicy = $policyIndex['platform_data_sources.config_json'] ?? null;
    if (is_array($platformPolicy)
        && cloudHotelIdMigrationTableColumnExists('platform_data_sources', 'id')
        && cloudHotelIdMigrationTableColumnExists('platform_data_sources', 'system_hotel_id')
        && cloudHotelIdMigrationTableColumnExists('platform_data_sources', 'config_json')) {
        $rows = Db::query(
            'SELECT id,config_json FROM `platform_data_sources` WHERE system_hotel_id=? ORDER BY id'
            . ($lockRows ? ' FOR UPDATE' : ''),
            [$fromHotelId]
        );
        foreach ($rows as $row) {
            $rowId = (int)($row['id'] ?? $row['ID'] ?? 0);
            if ($rowId <= 0) {
                throw new RuntimeException('mutable_active_config_row_identity_invalid:platform_data_sources.config_json');
            }
            $entry = cloudHotelIdMigrationPrepareMutableJsonEntry(
                $platformPolicy,
                'id',
                $rowId,
                ['row_id' => $rowId],
                (string)($row['config_json'] ?? $row['CONFIG_JSON'] ?? ''),
                $fromHotelId,
                $toHotelId
            );
            if ($entry !== []) {
                $mutable['platform_data_sources.config_json:' . $rowId] = $entry;
            }
        }
    }
    cloudHotelIdMigrationAssertMutableJsonOutsideScopeClear($fromHotelId);

    $historical = [];
    foreach (cloudHotelIdMigrationJsonCandidateColumns() as $key => $candidate) {
        if ($candidate['policy'] === CLOUD_HOTEL_ID_JSON_MUTABLE_ACTIVE) {
            continue;
        }
        $digest = cloudHotelIdMigrationJsonReferenceDigest(
            $candidate['table'],
            $candidate['column'],
            $fromHotelId,
            cloudHotelIdMigrationAllJsonIdentityKeys()
        );
        if ($digest['reference_count'] === 0) {
            continue;
        }
        if ($candidate['policy'] !== CLOUD_HOTEL_ID_JSON_IMMUTABLE_EVIDENCE) {
            throw new RuntimeException(
                'unknown_non_historical_json_reference_requires_review:' . $key . ':' . $digest['reference_count']
            );
        }
        $historical[$key] = [
            'table' => $candidate['table'],
            'column' => $candidate['column'],
            'reference_count' => $digest['reference_count'],
            'multiset_sha256' => $digest['multiset_sha256'],
            'policy' => CLOUD_HOTEL_ID_JSON_IMMUTABLE_EVIDENCE,
        ];
    }
    return ['mutable' => $mutable, 'historical' => $historical];
}

/** @return array<string,mixed> */
function cloudHotelIdMigrationJsonReceipt(array $jsonPreflight): array
{
    $mutable = [];
    foreach (($jsonPreflight['mutable'] ?? []) as $entry) {
        $mutable[] = [
            'table' => $entry['table'],
            ...$entry['receipt_locator'],
            'reference_count' => $entry['reference_count'],
            'before_sha256' => $entry['before_sha256'],
            'after_sha256' => $entry['after_sha256'],
        ];
    }
    return [
        'mutable_active_config_migrations' => $mutable,
        'preserved_immutable_evidence_references' => array_values($jsonPreflight['historical'] ?? []),
        'unknown_non_historical_json_references' => [],
        'raw_json_values_disclosed' => false,
    ];
}

/** @return array<string,int> */
function cloudHotelIdMigrationApplyMutableJson(array $jsonPreflight): array
{
    $updated = [];
    foreach (($jsonPreflight['mutable'] ?? []) as $entryKey => $entry) {
        $affected = Db::execute(
            'UPDATE ' . cloudHotelIdMigrationIdentifier($entry['table'])
            . ' SET ' . cloudHotelIdMigrationIdentifier($entry['column']) . '=? '
            . 'WHERE ' . cloudHotelIdMigrationIdentifier($entry['identity_column']) . '=? '
            . 'AND BINARY ' . cloudHotelIdMigrationIdentifier($entry['column']) . '=BINARY ?',
            [$entry['after_raw'], $entry['identity_value'], $entry['before_raw']]
        );
        if ($affected !== 1) {
            throw new RuntimeException('mutable_active_config_cas_mismatch:' . $entryKey . ':' . $affected);
        }
        $updated[$entryKey] = $affected;
    }
    return $updated;
}

/** @return array<string,mixed> */
function cloudHotelIdMigrationAssertJsonReadback(array $jsonPreflight, int $fromHotelId, int $toHotelId): array
{
    $mutable = [];
    foreach (($jsonPreflight['mutable'] ?? []) as $entryKey => $entry) {
        $rows = Db::query(
            'SELECT ' . cloudHotelIdMigrationIdentifier($entry['column']) . ' AS json_value FROM '
            . cloudHotelIdMigrationIdentifier($entry['table']) . ' WHERE '
            . cloudHotelIdMigrationIdentifier($entry['identity_column']) . '=?',
            [$entry['identity_value']]
        );
        if (count($rows) !== 1) {
            throw new RuntimeException('mutable_active_config_readback_missing:' . $entryKey);
        }
        $raw = (string)($rows[0]['json_value'] ?? $rows[0]['JSON_VALUE'] ?? '');
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RuntimeException('mutable_active_config_readback_decode_failed:' . $entryKey, 0, $exception);
        }
        $counts = cloudHotelIdTransformMutableJsonValue(
            $decoded,
            $fromHotelId,
            $toHotelId,
            $entry['identity_keys']
        );
        $expectedTargetCount = (int)$entry['reference_count'] + (int)$entry['target_count_before'];
        if (hash('sha256', $raw) !== $entry['after_sha256']
            || $counts['from_count'] !== 0
            || $counts['to_count'] !== $expectedTargetCount) {
            throw new RuntimeException('mutable_active_config_readback_mismatch:' . $entryKey);
        }
        $mutable[$entryKey] = [
            'table' => $entry['table'],
            ...$entry['receipt_locator'],
            'reference_count' => (int)$entry['reference_count'],
            'after_sha256' => $entry['after_sha256'],
            'source_id_remaining' => 0,
            'target_id_count' => $counts['to_count'],
        ];
    }
    foreach (($jsonPreflight['historical'] ?? []) as $key => $entry) {
        $actual = cloudHotelIdMigrationJsonReferenceDigest(
            $entry['table'],
            $entry['column'],
            $fromHotelId,
            cloudHotelIdMigrationAllJsonIdentityKeys()
        );
        if ($actual['reference_count'] !== (int)$entry['reference_count']
            || $actual['multiset_sha256'] !== $entry['multiset_sha256']) {
            throw new RuntimeException('immutable_evidence_multiset_digest_changed:' . $key);
        }
    }
    // Re-discover every current JSON location on the active connection. This
    // catches newly inserted mutable rows, newly deployed immutable locations,
    // and unknown locations that were absent from the original preflight.
    $currentJsonPreflight = cloudHotelIdMigrationJsonPreflight($fromHotelId, $toHotelId, false);
    if (($currentJsonPreflight['mutable'] ?? []) !== []) {
        throw new RuntimeException('postcommit_mutable_json_source_reference_reappeared');
    }
    $expectedHistorical = $jsonPreflight['historical'] ?? [];
    $currentHistorical = $currentJsonPreflight['historical'] ?? [];
    if (array_keys($currentHistorical) !== array_keys($expectedHistorical)) {
        throw new RuntimeException('postcommit_immutable_json_reference_set_changed');
    }
    foreach ($expectedHistorical as $key => $entry) {
        $current = $currentHistorical[$key] ?? null;
        if (!is_array($current)
            || (int)$current['reference_count'] !== (int)$entry['reference_count']
            || $current['multiset_sha256'] !== $entry['multiset_sha256']) {
            throw new RuntimeException('postcommit_immutable_json_reference_set_changed:' . $key);
        }
    }
    return [
        'status' => 'mutable_config_readback_and_immutable_evidence_preservation_verified',
        'mutable_active_configs' => array_values($mutable),
        'preserved_immutable_evidence_references' => array_values($jsonPreflight['historical'] ?? []),
        'raw_json_values_disclosed' => false,
    ];
}

/** @return array<int,array<string,mixed>> */
function cloudHotelIdMigrationDiscoveredColumns(): array
{
    $databaseRows = Db::query('SELECT DATABASE() AS database_name');
    $database = trim((string)($databaseRows[0]['database_name'] ?? $databaseRows[0]['DATABASE_NAME'] ?? ''));
    if ($database !== CLOUD_HOTEL_ID_MIGRATION_DATABASE) {
        throw new RuntimeException('unexpected_database:' . $database);
    }
    return Db::query(
        'SELECT c.TABLE_NAME AS table_name,c.COLUMN_NAME AS column_name,c.DATA_TYPE AS data_type,'
        . 'c.EXTRA AS extra,t.ENGINE AS engine '
        . 'FROM information_schema.COLUMNS c '
        . 'INNER JOIN information_schema.TABLES t '
        . 'ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME AND t.TABLE_TYPE=\'BASE TABLE\' '
        . 'WHERE c.TABLE_SCHEMA=? '
        . 'AND ((c.COLUMN_NAME=\'id\' AND c.TABLE_NAME=\'hotels\') '
        . 'OR c.COLUMN_NAME REGEXP \'(^|_)hotel_id$\' '
        . 'OR c.COLUMN_NAME=\'store_id\') '
        . 'ORDER BY c.TABLE_NAME,c.ORDINAL_POSITION',
        [$database]
    );
}

/** @return array<string,mixed>|null */
function cloudHotelIdMigrationHotel(int $hotelId): ?array
{
    $row = Db::name('hotels')
        ->field('id,tenant_id,name,status,owner_user_id,ota_channel_strategy')
        ->where('id', $hotelId)
        ->find();
    return is_array($row) ? $row : null;
}

function cloudHotelIdMigrationAssertIdentity(
    int $fromHotelId,
    int $toHotelId,
    int $expectedTenantId,
    string $expectedHotelName
): void {
    $sourceHotel = cloudHotelIdMigrationHotel($fromHotelId);
    $targetHotel = cloudHotelIdMigrationHotel($toHotelId);
    if (!is_array($sourceHotel)
        || (int)($sourceHotel['tenant_id'] ?? 0) !== $expectedTenantId
        || trim((string)($sourceHotel['name'] ?? '')) !== $expectedHotelName) {
        throw new RuntimeException('source_hotel_identity_mismatch');
    }
    if (is_array($targetHotel)) {
        throw new RuntimeException('target_hotel_id_already_exists');
    }
}

function cloudHotelIdMigrationAcquireLock(object $connection): bool
{
    $rows = $connection->query(
        'SELECT GET_LOCK(?, ?) AS lock_acquired',
        [CLOUD_HOTEL_ID_MIGRATION_LOCK_NAME, CLOUD_HOTEL_ID_MIGRATION_LOCK_TIMEOUT_SECONDS]
    );
    return (int)($rows[0]['lock_acquired'] ?? $rows[0]['LOCK_ACQUIRED'] ?? 0) === 1;
}

function cloudHotelIdMigrationReleaseLock(object $connection): bool
{
    $rows = $connection->query(
        'SELECT RELEASE_LOCK(?) AS lock_released',
        [CLOUD_HOTEL_ID_MIGRATION_LOCK_NAME]
    );
    return (int)($rows[0]['lock_released'] ?? $rows[0]['LOCK_RELEASED'] ?? 0) === 1;
}

function cloudHotelIdMigrationRuntimeEnvHotelId(string $path): int
{
    if (!is_readable($path)) {
        throw new RuntimeException('external_runtime_config_blocked:dingdandao_env_unreadable');
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        throw new RuntimeException('external_runtime_config_blocked:dingdandao_env_read_failed');
    }
    $values = [];
    foreach ($lines as $line) {
        if (preg_match('/^SUXIOS_DINGDANDAO_HOTEL_ID=(.*)$/D', trim((string)$line), $matches) === 1) {
            $values[] = trim((string)$matches[1]);
        }
    }
    if (count($values) !== 1 || preg_match('/^[1-9][0-9]*$/D', $values[0]) !== 1) {
        throw new RuntimeException('external_runtime_config_blocked:dingdandao_hotel_id_invalid');
    }
    return (int)$values[0];
}

function cloudHotelIdMigrationRuntimeUnitState(string $unit): string
{
    if (!function_exists('exec') || !is_executable(CLOUD_HOTEL_ID_MIGRATION_SYSTEMCTL)) {
        throw new RuntimeException('external_runtime_config_blocked:systemctl_unavailable');
    }
    $output = [];
    $exitCode = 0;
    exec(
        CLOUD_HOTEL_ID_MIGRATION_SYSTEMCTL
        . ' show --property=ActiveState --value ' . escapeshellarg($unit) . ' 2>/dev/null',
        $output,
        $exitCode
    );
    $state = count($output) === 1 ? trim((string)$output[0]) : '';
    if ($exitCode !== 0 || !in_array($state, ['inactive', 'failed'], true)) {
        throw new RuntimeException('external_runtime_config_blocked:unit_not_stopped:' . $unit . ':' . $state);
    }
    return $state;
}

/** @return array{env_path:string,expected_hotel_id:int,readback_hotel_id:int,stopped_units:array<string,string>} */
function cloudHotelIdMigrationAssertExternalRuntimePrepared(int $expectedHotelId): array
{
    $readbackHotelId = cloudHotelIdMigrationRuntimeEnvHotelId(CLOUD_HOTEL_ID_MIGRATION_RUNTIME_ENV);
    if ($readbackHotelId !== $expectedHotelId) {
        throw new RuntimeException(
            'external_runtime_config_blocked:dingdandao_hotel_id_mismatch:'
            . $readbackHotelId . ':' . $expectedHotelId
        );
    }
    $stoppedUnits = [];
    foreach (CLOUD_HOTEL_ID_MIGRATION_STOPPED_UNITS as $unit) {
        $stoppedUnits[$unit] = cloudHotelIdMigrationRuntimeUnitState($unit);
    }
    return [
        'env_path' => CLOUD_HOTEL_ID_MIGRATION_RUNTIME_ENV,
        'expected_hotel_id' => $expectedHotelId,
        'readback_hotel_id' => $readbackHotelId,
        'stopped_units' => $stoppedUnits,
    ];
}

/** @return array<int,array<string,mixed>> */
function cloudHotelIdMigrationForeignKeysToHotels(): array
{
    return Db::query(
        'SELECT k.TABLE_NAME AS table_name,k.COLUMN_NAME AS column_name,k.CONSTRAINT_NAME AS constraint_name '
        . 'FROM information_schema.KEY_COLUMN_USAGE k '
        . 'WHERE k.CONSTRAINT_SCHEMA=? AND k.REFERENCED_TABLE_SCHEMA=? '
        . 'AND k.REFERENCED_TABLE_NAME=\'hotels\' AND k.REFERENCED_COLUMN_NAME=\'id\'',
        [CLOUD_HOTEL_ID_MIGRATION_DATABASE, CLOUD_HOTEL_ID_MIGRATION_DATABASE]
    );
}

/** @return array<string,array{table:string,column:string,engine:string,from_count:int,to_count:int,total_rows:int}> */
function cloudHotelIdMigrationPreflight(int $fromHotelId, int $toHotelId): array
{
    $preflight = [];
    $discoveredKeys = [];
    $numericTypes = ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'decimal', 'numeric'];
    foreach (cloudHotelIdMigrationDiscoveredColumns() as $columnRow) {
        $table = trim((string)($columnRow['table_name'] ?? $columnRow['TABLE_NAME'] ?? ''));
        $column = trim((string)($columnRow['column_name'] ?? $columnRow['COLUMN_NAME'] ?? ''));
        $dataType = strtolower(trim((string)($columnRow['data_type'] ?? $columnRow['DATA_TYPE'] ?? '')));
        $extra = strtoupper(trim((string)($columnRow['extra'] ?? $columnRow['EXTRA'] ?? '')));
        if ($table === '' || $column === '') {
            continue;
        }
        $key = $table . '.' . $column;
        $discoveredKeys[$key] = true;
        $classification = cloudHotelIdClassifyDiscoveredColumn($table, $column);
        $numericCompatible = in_array($dataType, $numericTypes, true);
        $counts = $numericCompatible
            ? cloudHotelIdMigrationCounts($table, $column, $fromHotelId, $toHotelId)
            : cloudHotelIdMigrationTextCounts($table, $column, $fromHotelId, $toHotelId);

        if ($classification['classification'] === CLOUD_HOTEL_ID_COLUMN_NEGATIVE) {
            continue;
        }
        if ($classification['classification'] === CLOUD_HOTEL_ID_COLUMN_DERIVED) {
            if (!$numericCompatible || !str_contains($extra, 'GENERATED')) {
                throw new RuntimeException('derived_system_hotel_column_not_readonly_generated:' . $key);
            }
            continue;
        }
        if ($classification['classification'] === 'unknown') {
            if ($column === 'store_id') {
                throw new RuntimeException(
                    'unregistered_store_id_column_requires_review:' . $key . ':' . $counts['from_count']
                );
            }
            if ($counts['from_count'] > 0) {
                throw new RuntimeException('unknown_hotel_id_reference_requires_review:' . $key . ':' . $counts['from_count']);
            }
            continue;
        }

        if (!$numericCompatible) {
            throw new RuntimeException('registered_positive_column_non_numeric:' . $key . ':' . $dataType);
        }
        $engine = strtoupper(trim((string)($columnRow['engine'] ?? $columnRow['ENGINE'] ?? '')));
        if ($engine !== 'INNODB') {
            throw new RuntimeException('non_transactional_table:' . $key . ':' . $engine);
        }
        if ($counts['to_count'] !== 0) {
            throw new RuntimeException('target_id_already_referenced:' . $key);
        }
        $preflight[$key] = [
            'table' => $table,
            'column' => $column,
            'engine' => $engine,
            ...$counts,
        ];
    }

    $missingRequired = [];
    foreach (cloudHotelIdPositiveColumnRegistry() as $entry) {
        $key = cloudHotelIdColumnKey($entry['table'], $entry['column']);
        if ($entry['presence'] === CLOUD_HOTEL_ID_COLUMN_REQUIRED && !isset($discoveredKeys[$key])) {
            $missingRequired[] = $key;
        }
    }
    if ($missingRequired !== []) {
        throw new RuntimeException('required_positive_column_missing:' . implode(',', $missingRequired));
    }

    // The primary key must move after every discovered positive alias.
    if (isset($preflight['hotels.id'])) {
        $hotelPrimaryKey = $preflight['hotels.id'];
        unset($preflight['hotels.id']);
        $preflight['hotels.id'] = $hotelPrimaryKey;
    }

    if (cloudHotelIdMigrationForeignKeysToHotels() !== []) {
        throw new RuntimeException('foreign_keys_to_hotels_require_explicit_review');
    }

    return $preflight;
}

function cloudHotelIdMigrationHotelsNextAutoIncrement(): int
{
    $rows = Db::query(
        'SELECT AUTO_INCREMENT AS next_auto_increment FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA=? AND TABLE_NAME=\'hotels\' AND TABLE_TYPE=\'BASE TABLE\'',
        [CLOUD_HOTEL_ID_MIGRATION_DATABASE]
    );
    return (int)($rows[0]['next_auto_increment'] ?? $rows[0]['NEXT_AUTO_INCREMENT'] ?? 0);
}

function cloudHotelIdMigrationAssertHotelsAutoIncrementAbove(int $hotelId): int
{
    $nextAutoIncrement = cloudHotelIdMigrationHotelsNextAutoIncrement();
    if ($nextAutoIncrement <= $hotelId) {
        throw new RuntimeException(
            'hotels_auto_increment_not_above_target:' . $nextAutoIncrement . ':' . $hotelId
        );
    }
    return $nextAutoIncrement;
}

/** @return array<string,int> */
function cloudHotelIdMigrationApplyRelational(array $preflight, int $fromHotelId, int $toHotelId): array
{
    $updated = [];
    Db::execute('SET @suxi_cloud_hotel_id_migration = 1');
    try {
        foreach ($preflight as $key => $entry) {
            $table = $entry['table'];
            $column = $entry['column'];
            $expectedCount = (int)$entry['from_count'];
            $affected = Db::execute(
                'UPDATE ' . cloudHotelIdMigrationIdentifier($table)
                . ' SET ' . cloudHotelIdMigrationIdentifier($column) . '=?'
                . ' WHERE ' . cloudHotelIdMigrationIdentifier($column) . '=?',
                [$toHotelId, $fromHotelId]
            );
            if ($affected !== $expectedCount) {
                throw new RuntimeException('updated_row_count_mismatch:' . $key . ':' . $affected . ':' . $expectedCount);
            }
            $updated[$key] = $affected;
        }
    } finally {
        Db::execute('SET @suxi_cloud_hotel_id_migration = 0');
    }
    return $updated;
}

/** @return array<string,mixed> */
function cloudHotelIdMigrationAssertRelationalPostflight(
    array $preflight,
    int $fromHotelId,
    int $toHotelId,
    int $expectedTenantId,
    string $expectedHotelName
): array {
    $countsByColumn = [];
    foreach ($preflight as $key => $entry) {
        $counts = cloudHotelIdMigrationCounts($entry['table'], $entry['column'], $fromHotelId, $toHotelId);
        if ($counts['from_count'] !== 0 || $counts['to_count'] !== (int)$entry['from_count']) {
            throw new RuntimeException('postflight_count_mismatch:' . $key);
        }
        $countsByColumn[$key] = $counts;
    }
    $migratedHotel = cloudHotelIdMigrationHotel($toHotelId);
    if (!is_array($migratedHotel)
        || (int)($migratedHotel['tenant_id'] ?? 0) !== $expectedTenantId
        || trim((string)($migratedHotel['name'] ?? '')) !== $expectedHotelName
        || cloudHotelIdMigrationHotel($fromHotelId) !== null) {
        throw new RuntimeException('postflight_hotel_identity_mismatch');
    }
    return [
        'columns' => $countsByColumn,
        'hotels_next_auto_increment' => cloudHotelIdMigrationAssertHotelsAutoIncrementAbove($toHotelId),
    ];
}

function cloudHotelIdMigrationConnectionId(): int
{
    $rows = Db::query('SELECT CONNECTION_ID() AS connection_id');
    return (int)($rows[0]['connection_id'] ?? $rows[0]['CONNECTION_ID'] ?? 0);
}

/**
 * Reconnect after commit and audit every discovered positive, negative, and
 * unknown candidate. This is intentionally separate from transaction-local
 * postflight evidence.
 *
 * @param array<string,array{table:string,column:string,engine:string,from_count:int,to_count:int,total_rows:int}> $preflight
 * @return array<string,mixed>
 */
function cloudHotelIdMigrationPostCommitAudit(
    int $fromHotelId,
    int $toHotelId,
    int $expectedTenantId,
    string $expectedHotelName,
    int $transactionConnectionId,
    array $preflight,
    array $jsonPreflight
): array {
    Db::connect(null, true);
    $auditConnectionId = cloudHotelIdMigrationConnectionId();
    if ($transactionConnectionId <= 0
        || $auditConnectionId <= 0
        || $auditConnectionId === $transactionConnectionId) {
        throw new RuntimeException('postcommit_new_connection_not_proven');
    }
    if (cloudHotelIdMigrationForeignKeysToHotels() !== []) {
        throw new RuntimeException('postcommit_foreign_keys_to_hotels_require_explicit_review');
    }

    $numericTypes = ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'decimal', 'numeric'];
    $positiveAudit = [];
    $negativeAudit = [];
    $derivedAudit = [];
    $unknownAudit = [];
    $seenPositive = [];
    foreach (cloudHotelIdMigrationDiscoveredColumns() as $columnRow) {
        $table = trim((string)($columnRow['table_name'] ?? $columnRow['TABLE_NAME'] ?? ''));
        $column = trim((string)($columnRow['column_name'] ?? $columnRow['COLUMN_NAME'] ?? ''));
        $dataType = strtolower(trim((string)($columnRow['data_type'] ?? $columnRow['DATA_TYPE'] ?? '')));
        $extra = strtoupper(trim((string)($columnRow['extra'] ?? $columnRow['EXTRA'] ?? '')));
        if ($table === '' || $column === '') {
            continue;
        }
        $key = cloudHotelIdColumnKey($table, $column);
        $classification = cloudHotelIdClassifyDiscoveredColumn($table, $column);
        $numericCompatible = in_array($dataType, $numericTypes, true);
        $counts = $numericCompatible
            ? cloudHotelIdMigrationCounts($table, $column, $fromHotelId, $toHotelId)
            : cloudHotelIdMigrationTextCounts($table, $column, $fromHotelId, $toHotelId);
        $auditRow = [
            'table' => $table,
            'column' => $column,
            'classification' => $classification['classification'],
            ...$counts,
        ];

        if ($classification['classification'] === CLOUD_HOTEL_ID_COLUMN_POSITIVE) {
            if (!$numericCompatible) {
                throw new RuntimeException('postcommit_positive_column_non_numeric:' . $key);
            }
            $expectedTargetCount = (int)($preflight[$key]['from_count'] ?? 0);
            if ($counts['from_count'] !== 0 || $counts['to_count'] !== $expectedTargetCount) {
                throw new RuntimeException(
                    'postcommit_positive_count_mismatch:' . $key . ':'
                    . $counts['from_count'] . ':' . $counts['to_count'] . ':' . $expectedTargetCount
                );
            }
            $seenPositive[$key] = true;
            $positiveAudit[$key] = $auditRow;
            continue;
        }
        if ($classification['classification'] === CLOUD_HOTEL_ID_COLUMN_NEGATIVE) {
            $negativeAudit[$key] = $auditRow;
            continue;
        }
        if ($classification['classification'] === CLOUD_HOTEL_ID_COLUMN_DERIVED) {
            $sourceColumn = trim((string)($classification['source_column'] ?? ''));
            if (!$numericCompatible || !str_contains($extra, 'GENERATED') || $sourceColumn === '') {
                throw new RuntimeException('postcommit_derived_column_not_readonly_generated:' . $key);
            }
            $sourceCounts = cloudHotelIdMigrationCounts($table, $sourceColumn, $fromHotelId, $toHotelId);
            $mismatchCount = cloudHotelIdMigrationDerivedMismatchCount($table, $column, $sourceColumn);
            if ($counts['from_count'] !== 0
                || $sourceCounts['from_count'] !== 0
                || $counts['to_count'] > $sourceCounts['to_count']
                || $mismatchCount !== 0) {
                throw new RuntimeException('postcommit_derived_column_mismatch:' . $key);
            }
            $auditRow['source_column'] = $sourceColumn;
            $auditRow['source_counts'] = $sourceCounts;
            $auditRow['source_identity_mismatch_count'] = $mismatchCount;
            $auditRow['derived_consistency'] = 'readonly_generated_source_identity_subset_verified';
            $derivedAudit[$key] = $auditRow;
            continue;
        }
        if ($column === 'store_id' || $counts['from_count'] > 0) {
            throw new RuntimeException(
                'postcommit_unknown_column_requires_review:' . $key . ':' . $counts['from_count']
            );
        }
        $unknownAudit[$key] = $auditRow;
    }

    $missingPreflightKeys = array_values(array_diff(array_keys($preflight), array_keys($seenPositive)));
    if ($missingPreflightKeys !== []) {
        throw new RuntimeException('postcommit_preflight_column_missing:' . implode(',', $missingPreflightKeys));
    }
    $migratedHotel = cloudHotelIdMigrationHotel($toHotelId);
    if (!is_array($migratedHotel)
        || (int)($migratedHotel['tenant_id'] ?? 0) !== $expectedTenantId
        || trim((string)($migratedHotel['name'] ?? '')) !== $expectedHotelName
        || cloudHotelIdMigrationHotel($fromHotelId) !== null) {
        throw new RuntimeException('postcommit_hotel_identity_mismatch');
    }
    $nextAutoIncrement = cloudHotelIdMigrationAssertHotelsAutoIncrementAbove($toHotelId);
    $jsonAudit = cloudHotelIdMigrationAssertJsonReadback($jsonPreflight, $fromHotelId, $toHotelId);

    return [
        'status' => 'postcommit_new_connection_full_registry_audit_passed',
        'transaction_connection_id' => $transactionConnectionId,
        'audit_connection_id' => $auditConnectionId,
        'new_connection_verified' => true,
        'hotels_next_auto_increment' => $nextAutoIncrement,
        'hotels_auto_increment_above_target_verified' => true,
        'foreign_keys_to_hotels_verified_absent' => true,
        'positive_columns' => array_values($positiveAudit),
        'negative_columns_excluded_from_migration' => array_values($negativeAudit),
        'derived_readonly_system_hotel_columns' => array_values($derivedAudit),
        'unknown_zero_reference_columns' => array_values($unknownAudit),
        'json_identity_audit' => $jsonAudit,
    ];
}

/**
 * @param array<string,array{table:string,column:string,engine:string,from_count:int,to_count:int,total_rows:int}> $preflight
 * @return array<string,mixed>
 */
function cloudHotelIdMigrationReceiptBase(
    int $fromHotelId,
    int $toHotelId,
    int $expectedTenantId,
    string $expectedHotelName,
    array $preflight,
    array $jsonPreflight
): array {
    return [
        'contract_version' => 'suxios.cloud_hotel_id_migration.v2',
        'registry_contract_version' => CLOUD_HOTEL_ID_COLUMN_REGISTRY_CONTRACT,
        'database' => CLOUD_HOTEL_ID_MIGRATION_DATABASE,
        'from_hotel_id' => $fromHotelId,
        'to_hotel_id' => $toHotelId,
        'tenant_id' => $expectedTenantId,
        'hotel_name' => $expectedHotelName,
        'column_classification_registry' => cloudHotelIdColumnRegistry(),
        'registered_positive_system_hotel_columns' => cloudHotelIdPositiveColumnRegistry(),
        'preflight' => array_values($preflight),
        'json_identity_preflight' => cloudHotelIdMigrationJsonReceipt($jsonPreflight),
        'migration_boundary' => [
            'automatic_update_scope' => 'explicit_registry_only',
            'discovery_scope' => 'hotels.id_plus_hotel_id_pattern_plus_all_store_id',
            'unregistered_store_id_policy' => 'fail_closed_review_required_never_auto_migrate',
            'mutable_active_json_policy' => 'whitelisted_config_keys_recursive_exact_scalar_id_cas_migration',
            'immutable_json_policy' => 'explicit_allowlist_preserved_without_rewrite',
            'unknown_json_policy' => 'source_id_reference_blocks_migration',
            'maintenance_write_pause_required' => true,
            'all_writer_quiescence_confirmation' => 'operator_attested_via_ALL_WRITERS_PAUSED',
            'all_writer_quiescence_programmatic_evidence' => 'not_independently_verified',
            'named_migration_lock' => CLOUD_HOTEL_ID_MIGRATION_LOCK_NAME,
            'named_migration_lock_scope' => 'cooperative_invocations_of_this_migration_only',
            'named_migration_lock_held_through_postcommit_audit' => true,
            'transaction_isolation' => 'SERIALIZABLE',
            'identity_and_preflight_rechecked_inside_apply_transaction' => true,
            'postflight_verified_before_commit' => true,
            'hotels_auto_increment_must_be_strictly_above_target' => true,
            'postcommit_new_connection_full_registry_audit_required' => true,
            'external_runtime_sequence' => [
                'stop_suxios_dingdandao_collection_timer_and_service_before_database_write',
                'update_/etc/suxios/dingdandao-collector.env_to_target_system_hotel_id',
                'exact_env_hotel_id_readback_before_database_write',
                'restart_and_verify_timer_only_after_database_commit',
            ],
            'external_runtime_incomplete_status' => 'external_runtime_config_blocked',
        ],
    ];
}

if (defined('CLOUD_HOTEL_ID_MIGRATION_LIBRARY_ONLY')
    && constant('CLOUD_HOTEL_ID_MIGRATION_LIBRARY_ONLY') === true) {
    return;
}

$configuredAppDir = trim((string)getenv('SUXIOS_APP_DIR'));
$appDir = $configuredAppDir !== '' ? rtrim($configuredAppDir, '/\\') : dirname(__DIR__);
$autoload = $appDir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "app_autoload_missing\n");
    exit(1);
}

$fromHotelId = 0;
$toHotelId = 0;
$expectedTenantId = 0;
$expectedHotelName = '';
$mode = 'plan';
$confirmation = '';
$maintenanceWritePauseConfirmation = '';
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--from=([1-9][0-9]*)$/D', (string)$argument, $matches) === 1) {
        $fromHotelId = (int)$matches[1];
        continue;
    }
    if (preg_match('/^--to=([1-9][0-9]*)$/D', (string)$argument, $matches) === 1) {
        $toHotelId = (int)$matches[1];
        continue;
    }
    if (preg_match('/^--expected-tenant-id=([1-9][0-9]*)$/D', (string)$argument, $matches) === 1) {
        $expectedTenantId = (int)$matches[1];
        continue;
    }
    if (str_starts_with((string)$argument, '--expected-hotel-name=')) {
        $expectedHotelName = trim(substr((string)$argument, strlen('--expected-hotel-name=')));
        continue;
    }
    if (preg_match('/^--mode=(plan|apply)$/D', (string)$argument, $matches) === 1) {
        $mode = $matches[1];
        continue;
    }
    if (str_starts_with((string)$argument, '--confirm=')) {
        $confirmation = trim(substr((string)$argument, strlen('--confirm=')));
        continue;
    }
    if (str_starts_with((string)$argument, '--maintenance-write-pause-confirmed=')) {
        $maintenanceWritePauseConfirmation = trim(substr(
            (string)$argument,
            strlen('--maintenance-write-pause-confirmed=')
        ));
        continue;
    }
    fwrite(STDERR, "usage: php scripts/migrate_cloud_hotel_id.php --from=<id> --to=<id> --expected-tenant-id=<id> --expected-hotel-name=<name> [--mode=plan|apply] [--confirm=RENAME_CLOUD_HOTEL_ID] [--maintenance-write-pause-confirmed=ALL_WRITERS_PAUSED]\n");
    exit(2);
}
if ($fromHotelId <= 0 || $toHotelId <= 0 || $fromHotelId === $toHotelId
    || $expectedTenantId <= 0 || $expectedHotelName === '') {
    fwrite(STDERR, "complete_distinct_hotel_scope_required\n");
    exit(2);
}
if ($mode === 'apply' && $confirmation !== CLOUD_HOTEL_ID_MIGRATION_CONFIRMATION) {
    fwrite(STDERR, "explicit_apply_confirmation_required\n");
    exit(2);
}
if ($mode === 'apply'
    && $maintenanceWritePauseConfirmation !== CLOUD_HOTEL_ID_MIGRATION_MAINTENANCE_CONFIRMATION) {
    fwrite(STDERR, "maintenance_write_pause_confirmation_required\n");
    exit(2);
}

require $autoload;
(new App($appDir))->initialize();

if ($mode === 'plan') {
    cloudHotelIdMigrationAssertIdentity(
        $fromHotelId,
        $toHotelId,
        $expectedTenantId,
        $expectedHotelName
    );
    $preflight = cloudHotelIdMigrationPreflight($fromHotelId, $toHotelId);
    $jsonPreflight = cloudHotelIdMigrationJsonPreflight($fromHotelId, $toHotelId, false);
    $receiptBase = cloudHotelIdMigrationReceiptBase(
        $fromHotelId,
        $toHotelId,
        $expectedTenantId,
        $expectedHotelName,
        $preflight,
        $jsonPreflight
    );
    echo json_encode([
        'status' => 'preflight_passed_apply_prerequisites_pending',
        'mode' => 'plan',
        'inspected_at' => date(DATE_ATOM),
        ...$receiptBase,
        'execution_evidence' => [
            'maintenance_write_pause_confirmed' => false,
            'named_migration_lock_acquired' => false,
            'serializable_transaction_started' => false,
            'identity_rechecked_inside_transaction' => false,
            'preflight_inside_transaction' => false,
            'postflight_inside_transaction' => false,
            'external_runtime_config_readback_verified' => false,
            'external_runtime_units_stopped_verified' => false,
            'external_runtime_completion_gate' => 'external_runtime_config_blocked',
        ],
        'plan_sha256' => hash('sha256', (string)json_encode($receiptBase, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        'database_write_performed' => false,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$updated = [];
$postflight = [];
$preflight = [];
$jsonPreflight = ['mutable' => [], 'historical' => []];
$mutableJsonUpdated = [];
$transactionJsonReadback = [];
$lockAcquired = false;
$lockReleased = false;
$transactionOpen = false;
$applyException = null;
$externalRuntimePreflight = [];
$transactionConnectionId = 0;
$postCommitAudit = [];
$lockConnection = null;
try {
    // Keep the advisory lock on a dedicated connection. Transaction and
    // post-commit audit connections may be replaced independently without
    // releasing the cooperative migration lock.
    $lockConnection = Db::connect(null, true);
    $lockAcquired = cloudHotelIdMigrationAcquireLock($lockConnection);
    if (!$lockAcquired) {
        throw new RuntimeException('named_migration_lock_unavailable');
    }

    Db::connect(null, true);
    $transactionConnectionId = cloudHotelIdMigrationConnectionId();
    if ($transactionConnectionId <= 0) {
        throw new RuntimeException('transaction_connection_identity_unavailable');
    }
    $externalRuntimePreflight = cloudHotelIdMigrationAssertExternalRuntimePrepared($toHotelId);
    Db::execute('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
    Db::startTrans();
    $transactionOpen = true;

    // Both identity and all preflight counts are deliberately inside the same
    // SERIALIZABLE transaction as the updates. The named lock and the operator
    // maintenance assertion close the remaining cooperative-writer boundary.
    cloudHotelIdMigrationAssertIdentity(
        $fromHotelId,
        $toHotelId,
        $expectedTenantId,
        $expectedHotelName
    );
    $preflight = cloudHotelIdMigrationPreflight($fromHotelId, $toHotelId);
    $jsonPreflight = cloudHotelIdMigrationJsonPreflight($fromHotelId, $toHotelId, true);

    $updated = cloudHotelIdMigrationApplyRelational($preflight, $fromHotelId, $toHotelId);
    $mutableJsonUpdated = cloudHotelIdMigrationApplyMutableJson($jsonPreflight);

    $postflight = cloudHotelIdMigrationAssertRelationalPostflight(
        $preflight,
        $fromHotelId,
        $toHotelId,
        $expectedTenantId,
        $expectedHotelName
    );
    $transactionJsonReadback = cloudHotelIdMigrationAssertJsonReadback(
        $jsonPreflight,
        $fromHotelId,
        $toHotelId
    );
    Db::commit();
    $transactionOpen = false;

    // The dedicated advisory lock remains held while a forced new connection
    // rediscovers and audits the full positive/negative/unknown registry.
    $postCommitAudit = cloudHotelIdMigrationPostCommitAudit(
        $fromHotelId,
        $toHotelId,
        $expectedTenantId,
        $expectedHotelName,
        $transactionConnectionId,
        $preflight,
        $jsonPreflight
    );
} catch (Throwable $exception) {
    if ($transactionOpen) {
        try {
            Db::rollback();
        } catch (Throwable $rollbackException) {
            $exception = new RuntimeException(
                'migration_rollback_failed_after:' . $exception->getMessage(),
                0,
                $rollbackException
            );
        }
        $transactionOpen = false;
    }
    $applyException = $exception;
} finally {
    if ($lockAcquired && is_object($lockConnection)) {
        try {
            $lockReleased = cloudHotelIdMigrationReleaseLock($lockConnection);
        } catch (Throwable $releaseException) {
            if (!($applyException instanceof Throwable)) {
                $applyException = new RuntimeException('named_migration_lock_release_failed', 0, $releaseException);
            }
        }
    }
}
if ($applyException instanceof Throwable) {
    throw $applyException;
}
if (!$lockReleased) {
    throw new RuntimeException('named_migration_lock_release_failed');
}

$receiptBase = cloudHotelIdMigrationReceiptBase(
    $fromHotelId,
    $toHotelId,
    $expectedTenantId,
    $expectedHotelName,
    $preflight,
    $jsonPreflight
);
$receipt = [
    'status' => 'database_migrated_external_runtime_restart_required',
    'database_migration_status' => 'postcommit_new_connection_full_registry_audit_passed',
    'completion_gate' => 'external_runtime_config_blocked',
    'mode' => 'apply',
    'migrated_at' => date(DATE_ATOM),
    ...$receiptBase,
    'updated_rows' => $updated,
    'updated_mutable_json_rows' => $mutableJsonUpdated,
    'postflight' => $postflight,
    'execution_evidence' => [
        'maintenance_write_pause_confirmed' => true,
        'all_writer_quiescence_operator_attested' => true,
        'all_writer_quiescence_programmatically_verified' => false,
        'all_writer_quiescence_evidence_boundary' => 'operator_attestation_plus_dingdandao_unit_checks_only',
        'named_migration_lock_acquired' => $lockAcquired,
        'named_migration_lock_released' => $lockReleased,
        'named_migration_lock_held_through_postcommit_audit' => true,
        'serializable_transaction_started' => true,
        'identity_rechecked_inside_transaction' => true,
        'preflight_inside_transaction' => true,
        'postflight_inside_transaction' => true,
        'json_readback_inside_transaction' => $transactionJsonReadback,
        'transaction_committed' => true,
        'postcommit_audit' => $postCommitAudit,
        'external_runtime_preflight' => $externalRuntimePreflight,
        'external_runtime_restart_status' => 'pending_operator_restart_and_installer_verification',
    ],
    'database_write_performed' => true,
];
$receipt['receipt_sha256'] = hash(
    'sha256',
    (string)json_encode($receipt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);
echo json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
