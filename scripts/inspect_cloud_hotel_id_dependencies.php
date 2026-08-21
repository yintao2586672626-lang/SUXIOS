#!/usr/bin/env php
<?php
declare(strict_types=1);

use think\App;
use think\facade\Db;

require_once __DIR__ . DIRECTORY_SEPARATOR . 'cloud_hotel_id_column_registry.php';

$configuredAppDir = trim((string)getenv('SUXIOS_APP_DIR'));
$appDir = $configuredAppDir !== '' ? rtrim($configuredAppDir, '/\\') : dirname(__DIR__);
$autoload = $appDir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "app_autoload_missing\n");
    exit(1);
}

$fromHotelId = 0;
$toHotelId = 0;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--from=([1-9][0-9]*)$/D', (string)$argument, $matches) === 1) {
        $fromHotelId = (int)$matches[1];
        continue;
    }
    if (preg_match('/^--to=([1-9][0-9]*)$/D', (string)$argument, $matches) === 1) {
        $toHotelId = (int)$matches[1];
        continue;
    }
    fwrite(STDERR, "usage: php scripts/inspect_cloud_hotel_id_dependencies.php --from=<id> --to=<id>\n");
    exit(2);
}
if ($fromHotelId <= 0 || $toHotelId <= 0 || $fromHotelId === $toHotelId) {
    fwrite(STDERR, "distinct_positive_hotel_ids_required\n");
    exit(2);
}

require $autoload;
(new App($appDir))->initialize();

/** @return string */
function dependencyIdentifier(string $value): string
{
    if (preg_match('/^[A-Za-z0-9_]+$/D', $value) !== 1) {
        throw new RuntimeException('unsafe_database_identifier');
    }
    return '`' . $value . '`';
}

/** @param array<string,mixed>|null $row */
function dependencyProjectRow(?array $row): ?array
{
    if (!is_array($row)) {
        return null;
    }
    $result = [];
    foreach ($row as $key => $value) {
        if ($value instanceof DateTimeInterface) {
            $value = $value->format('Y-m-d H:i:s');
        }
        $result[(string)$key] = $value;
    }
    return $result;
}

function dependencyTableColumnExists(string $database, string $table, string $column): bool
{
    $rows = Db::query(
        'SELECT COUNT(*) AS column_count FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?',
        [$database, $table, $column]
    );
    return (int)($rows[0]['column_count'] ?? $rows[0]['COLUMN_COUNT'] ?? 0) === 1;
}

/** @param array<int,string> $identityKeys */
function dependencyJsonReferencePattern(array $identityKeys, int $hotelId): string
{
    return '\\"(' . implode('|', array_map('preg_quote', $identityKeys))
        . ')\\"[[:space:]]*:[[:space:]]*\\"?' . $hotelId . '\\"?([,}[:space:]]|$)';
}

/** @param array<int,string> $identityKeys @return array{reference_count:int,multiset_sha256:string} */
function dependencyJsonReferenceDigest(
    string $table,
    string $column,
    int $hotelId,
    array $identityKeys
): array {
    $rows = Db::query(
        'SELECT CAST(' . dependencyIdentifier($column) . ' AS CHAR) AS json_value FROM '
        . dependencyIdentifier($table) . ' WHERE ' . dependencyIdentifier($column) . ' IS NOT NULL'
        . ' AND CAST(' . dependencyIdentifier($column) . ' AS CHAR) REGEXP ?',
        [dependencyJsonReferencePattern($identityKeys, $hotelId)]
    );
    $hashes = [];
    foreach ($rows as $row) {
        $hashes[] = hash('sha256', (string)($row['json_value'] ?? $row['JSON_VALUE'] ?? ''));
    }
    sort($hashes, SORT_STRING);
    return [
        'reference_count' => count($hashes),
        'multiset_sha256' => hash('sha256', implode("\n", $hashes)),
    ];
}

/** @return array<int,string> */
function dependencyAllJsonIdentityKeys(): array
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

/** @return array<string,mixed>|null */
function dependencyInspectMutableJsonEntry(
    array $policy,
    array $receiptLocator,
    string $raw,
    int $fromHotelId,
    int $toHotelId
): ?array {
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
        dependencyAllJsonIdentityKeys(),
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

    $projection = cloudHotelIdTransformMutableJsonValue(
        $decoded,
        $fromHotelId,
        $toHotelId,
        $policy['identity_keys']
    );
    if ($projection['from_count'] === 0) {
        return null;
    }
    if ($projection['to_count'] > 0) {
        throw new RuntimeException(
            'mutable_active_config_target_id_already_present:' . $policy['table'] . '.' . $policy['column']
        );
    }
    try {
        $afterRaw = json_encode(
            $projection['transformed'],
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
        ...$receiptLocator,
        'reference_count' => (int)$projection['from_count'],
        'target_count_before' => (int)$projection['to_count'],
        'before_sha256' => hash('sha256', $raw),
        'after_sha256' => hash('sha256', $afterRaw),
        'policy' => CLOUD_HOTEL_ID_JSON_MUTABLE_ACTIVE,
    ];
}

$databaseRow = Db::query('SELECT DATABASE() AS database_name');
$database = trim((string)($databaseRow[0]['database_name'] ?? $databaseRow[0]['DATABASE_NAME'] ?? ''));
if ($database === '') {
    throw new RuntimeException('database_name_unavailable');
}

$sourceHotel = Db::name('hotels')
    ->field('id,tenant_id,name,status,owner_user_id,ota_channel_strategy')
    ->where('id', $fromHotelId)
    ->find();
$targetHotel = Db::name('hotels')
    ->field('id,tenant_id,name,status,owner_user_id,ota_channel_strategy')
    ->where('id', $toHotelId)
    ->find();

$columnRows = Db::query(
    'SELECT c.TABLE_NAME AS table_name,c.COLUMN_NAME AS column_name,c.DATA_TYPE AS data_type,'
    . 'c.COLUMN_TYPE AS column_type,c.IS_NULLABLE AS is_nullable,c.COLUMN_KEY AS column_key,c.EXTRA AS extra '
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

$numericTypes = [
    'tinyint' => true,
    'smallint' => true,
    'mediumint' => true,
    'int' => true,
    'integer' => true,
    'bigint' => true,
    'decimal' => true,
    'numeric' => true,
];
$discoveredColumns = [];
$numericHotelIdentityColumns = [];
$reviewRequiredColumns = [];
$unregisteredStoreIdColumns = [];
$blockingUnknownReferences = [];
$invalidRegisteredPositiveColumns = [];
$invalidDerivedColumns = [];
foreach ($columnRows as $columnRow) {
    $table = trim((string)($columnRow['table_name'] ?? $columnRow['TABLE_NAME'] ?? ''));
    $column = trim((string)($columnRow['column_name'] ?? $columnRow['COLUMN_NAME'] ?? ''));
    $dataType = strtolower(trim((string)($columnRow['data_type'] ?? $columnRow['DATA_TYPE'] ?? '')));
    $extra = strtoupper(trim((string)($columnRow['extra'] ?? $columnRow['EXTRA'] ?? '')));
    if ($table === '' || $column === '') {
        continue;
    }
    $numericCompatible = isset($numericTypes[$dataType]);
    $count = [];
    if ($numericCompatible) {
        $sql = 'SELECT COUNT(*) AS total_rows,'
            . 'SUM(' . dependencyIdentifier($column) . '=' . $fromHotelId . ') AS from_count,'
            . 'SUM(' . dependencyIdentifier($column) . '=' . $toHotelId . ') AS to_count '
            . 'FROM ' . dependencyIdentifier($table);
        $countRows = Db::query($sql);
        $count = $countRows[0] ?? [];
    } else {
        $sql = 'SELECT COUNT(*) AS total_rows,'
            . 'SUM(CAST(' . dependencyIdentifier($column) . ' AS CHAR)=?) AS from_count,'
            . 'SUM(CAST(' . dependencyIdentifier($column) . ' AS CHAR)=?) AS to_count '
            . 'FROM ' . dependencyIdentifier($table);
        $countRows = Db::query($sql, [(string)$fromHotelId, (string)$toHotelId]);
        $count = $countRows[0] ?? [];
    }
    $classification = cloudHotelIdClassifyDiscoveredColumn($table, $column);
    if (!$numericCompatible
        && $classification['classification'] === CLOUD_HOTEL_ID_COLUMN_POSITIVE) {
        $classification['automatic_migration_eligible'] = false;
        $classification['review_required'] = true;
        $classification['reason'] = 'registered_positive_system_hotel_column_non_numeric';
    }
    if ($classification['classification'] === CLOUD_HOTEL_ID_COLUMN_DERIVED
        && !str_contains($extra, 'GENERATED')) {
        $classification['review_required'] = true;
        $classification['reason'] = 'registered_derived_column_not_generated_readonly';
    }
    $projected = [
        'table' => $table,
        'column' => $column,
        'data_type' => $dataType,
        'column_type' => (string)($columnRow['column_type'] ?? $columnRow['COLUMN_TYPE'] ?? ''),
        'nullable' => (string)($columnRow['is_nullable'] ?? $columnRow['IS_NULLABLE'] ?? ''),
        'key' => (string)($columnRow['column_key'] ?? $columnRow['COLUMN_KEY'] ?? ''),
        'extra' => $extra,
        'numeric_compatible' => $numericCompatible,
        'total_rows' => (int)($count['total_rows'] ?? $count['TOTAL_ROWS'] ?? 0),
        'from_count' => (int)($count['from_count'] ?? $count['FROM_COUNT'] ?? 0),
        'to_count' => (int)($count['to_count'] ?? $count['TO_COUNT'] ?? 0),
        'registered_column_classification' => $classification['classification'],
        'registered_column_presence' => $classification['presence'],
        'registered_system_hotel_column' => $classification['classification'] === CLOUD_HOTEL_ID_COLUMN_POSITIVE,
        'registered_negative_non_system_hotel_column' => $classification['classification'] === CLOUD_HOTEL_ID_COLUMN_NEGATIVE,
        'registered_derived_readonly_system_hotel_column' => $classification['classification'] === CLOUD_HOTEL_ID_COLUMN_DERIVED,
        'automatic_migration_eligible' => $classification['automatic_migration_eligible'],
        'review_required' => $classification['review_required'],
        'classification_reason' => $classification['reason'],
        'registered_alias' => $classification['alias'],
        'derived_source_column' => $classification['source_column'],
    ];
    $discoveredColumns[] = $projected;
    if ($numericCompatible) {
        $numericHotelIdentityColumns[] = $projected;
    }
    if ($classification['review_required']) {
        $reviewRequiredColumns[] = $projected;
    }
    if ($column === 'store_id' && !$classification['registered']) {
        $unregisteredStoreIdColumns[] = $projected;
    }
    if (!$classification['registered'] && (int)$projected['from_count'] > 0) {
        $blockingUnknownReferences[] = $projected;
    }
    if ($classification['classification'] === CLOUD_HOTEL_ID_COLUMN_POSITIVE && !$numericCompatible) {
        $invalidRegisteredPositiveColumns[] = $projected;
    }
    if ($classification['classification'] === CLOUD_HOTEL_ID_COLUMN_DERIVED
        && !str_contains($extra, 'GENERATED')) {
        $invalidDerivedColumns[] = $projected;
    }
}

$foreignKeys = Db::query(
    'SELECT k.TABLE_NAME AS table_name,k.COLUMN_NAME AS column_name,'
    . 'k.CONSTRAINT_NAME AS constraint_name,k.REFERENCED_TABLE_NAME AS referenced_table,'
    . 'k.REFERENCED_COLUMN_NAME AS referenced_column,r.UPDATE_RULE AS update_rule,r.DELETE_RULE AS delete_rule '
    . 'FROM information_schema.KEY_COLUMN_USAGE k '
    . 'LEFT JOIN information_schema.REFERENTIAL_CONSTRAINTS r '
    . 'ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME '
    . 'WHERE k.CONSTRAINT_SCHEMA=? AND k.REFERENCED_TABLE_SCHEMA=? '
    . 'AND k.REFERENCED_TABLE_NAME=\'hotels\' AND k.REFERENCED_COLUMN_NAME=\'id\' '
    . 'ORDER BY k.TABLE_NAME,k.COLUMN_NAME',
    [$database, $database]
);

$jsonRows = Db::query(
    'SELECT c.TABLE_NAME AS table_name,c.COLUMN_NAME AS column_name,c.DATA_TYPE AS data_type '
    . 'FROM information_schema.COLUMNS c '
    . 'INNER JOIN information_schema.TABLES t '
    . 'ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME AND t.TABLE_TYPE=\'BASE TABLE\' '
    . 'WHERE c.TABLE_SCHEMA=? '
    . 'AND (c.DATA_TYPE=\'json\' OR c.COLUMN_NAME LIKE \'%\\_json\') '
    . 'ORDER BY c.TABLE_NAME,c.ORDINAL_POSITION',
    [$database]
);
$jsonPolicyIndex = cloudHotelIdJsonPolicyRegistryIndex();
$jsonCandidates = [];
foreach ($jsonRows as $jsonRow) {
    $table = trim((string)($jsonRow['table_name'] ?? $jsonRow['TABLE_NAME'] ?? ''));
    $column = trim((string)($jsonRow['column_name'] ?? $jsonRow['COLUMN_NAME'] ?? ''));
    if ($table === '' || $column === '') {
        continue;
    }
    $key = cloudHotelIdColumnKey($table, $column);
    $jsonCandidates[$key] = [
        'table' => $table,
        'column' => $column,
        'data_type' => strtolower(trim((string)($jsonRow['data_type'] ?? $jsonRow['DATA_TYPE'] ?? ''))),
        'policy' => (string)($jsonPolicyIndex[$key]['policy'] ?? 'unknown'),
    ];
}
// Policy locations are explicit candidates even when their SQL type is
// LONGTEXT (notably system_configs.config_value).
foreach ($jsonPolicyIndex as $key => $policy) {
    if (!dependencyTableColumnExists($database, $policy['table'], $policy['column'])) {
        continue;
    }
    $jsonCandidates[$key] = [
        'table' => $policy['table'],
        'column' => $policy['column'],
        'data_type' => 'explicit_policy_location',
        'policy' => $policy['policy'],
    ];
}
ksort($jsonCandidates, SORT_STRING);

$mutableActiveConfigReferences = [];
$mutableActiveConfigErrors = [];
$systemPolicy = $jsonPolicyIndex['system_configs.config_value'] ?? null;
if (is_array($systemPolicy)
    && dependencyTableColumnExists($database, 'system_configs', 'config_key')
    && dependencyTableColumnExists($database, 'system_configs', 'config_value')) {
    $rowKeys = array_values($systemPolicy['row_keys']);
    $rows = Db::query(
        'SELECT config_key,config_value FROM `system_configs` WHERE config_key IN ('
        . implode(',', array_fill(0, count($rowKeys), '?')) . ') ORDER BY config_key',
        $rowKeys
    );
    $seenKeys = [];
    foreach ($rows as $row) {
        $configKey = trim((string)($row['config_key'] ?? $row['CONFIG_KEY'] ?? ''));
        $locator = ['config_key' => $configKey];
        if ($configKey === '' || isset($seenKeys[$configKey])) {
            $mutableActiveConfigErrors[] = [
                'table' => 'system_configs',
                'column' => 'config_value',
                ...$locator,
                'error_code' => 'mutable_active_config_key_not_unique',
            ];
            continue;
        }
        $seenKeys[$configKey] = true;
        try {
            $reference = dependencyInspectMutableJsonEntry(
                $systemPolicy,
                $locator,
                (string)($row['config_value'] ?? $row['CONFIG_VALUE'] ?? ''),
                $fromHotelId,
                $toHotelId
            );
            if (is_array($reference)) {
                $mutableActiveConfigReferences[] = $reference;
            }
        } catch (Throwable $exception) {
            $mutableActiveConfigErrors[] = [
                'table' => 'system_configs',
                'column' => 'config_value',
                ...$locator,
                'error_code' => $exception->getMessage(),
            ];
        }
    }
    $outsideRows = Db::query(
        'SELECT COUNT(*) AS reference_count FROM `system_configs` WHERE config_key NOT IN ('
        . implode(',', array_fill(0, count($rowKeys), '?')) . ') '
        . 'AND CAST(`config_value` AS CHAR) REGEXP ?',
        [...$rowKeys, dependencyJsonReferencePattern($systemPolicy['identity_keys'], $fromHotelId)]
    );
    $outsideCount = (int)($outsideRows[0]['reference_count'] ?? $outsideRows[0]['REFERENCE_COUNT'] ?? 0);
    if ($outsideCount > 0) {
        $mutableActiveConfigErrors[] = [
            'table' => 'system_configs',
            'column' => 'config_value',
            'error_code' => 'mutable_active_config_reference_outside_allowlist',
            'reference_count' => $outsideCount,
        ];
    }
}

$platformPolicy = $jsonPolicyIndex['platform_data_sources.config_json'] ?? null;
if (is_array($platformPolicy)
    && dependencyTableColumnExists($database, 'platform_data_sources', 'id')
    && dependencyTableColumnExists($database, 'platform_data_sources', 'system_hotel_id')
    && dependencyTableColumnExists($database, 'platform_data_sources', 'config_json')) {
    $rows = Db::query(
        'SELECT id,config_json FROM `platform_data_sources` WHERE system_hotel_id=? ORDER BY id',
        [$fromHotelId]
    );
    foreach ($rows as $row) {
        $rowId = (int)($row['id'] ?? $row['ID'] ?? 0);
        $locator = ['row_id' => $rowId];
        if ($rowId <= 0) {
            $mutableActiveConfigErrors[] = [
                'table' => 'platform_data_sources',
                'column' => 'config_json',
                ...$locator,
                'error_code' => 'mutable_active_config_row_identity_invalid',
            ];
            continue;
        }
        try {
            $reference = dependencyInspectMutableJsonEntry(
                $platformPolicy,
                $locator,
                (string)($row['config_json'] ?? $row['CONFIG_JSON'] ?? ''),
                $fromHotelId,
                $toHotelId
            );
            if (is_array($reference)) {
                $mutableActiveConfigReferences[] = $reference;
            }
        } catch (Throwable $exception) {
            $mutableActiveConfigErrors[] = [
                'table' => 'platform_data_sources',
                'column' => 'config_json',
                ...$locator,
                'error_code' => $exception->getMessage(),
            ];
        }
    }
    $outsideRows = Db::query(
        'SELECT COUNT(*) AS reference_count FROM `platform_data_sources` '
        . 'WHERE (`system_hotel_id` IS NULL OR `system_hotel_id`<>?) '
        . 'AND CAST(`config_json` AS CHAR) REGEXP ?',
        [$fromHotelId, dependencyJsonReferencePattern($platformPolicy['identity_keys'], $fromHotelId)]
    );
    $outsideCount = (int)($outsideRows[0]['reference_count'] ?? $outsideRows[0]['REFERENCE_COUNT'] ?? 0);
    if ($outsideCount > 0) {
        $mutableActiveConfigErrors[] = [
            'table' => 'platform_data_sources',
            'column' => 'config_json',
            'error_code' => 'mutable_active_config_reference_outside_scope',
            'reference_count' => $outsideCount,
        ];
    }
}

$preservedImmutableEvidenceReferences = [];
$blockingUnknownJsonReferences = [];
foreach ($jsonCandidates as $key => $candidate) {
    if ($candidate['policy'] === CLOUD_HOTEL_ID_JSON_MUTABLE_ACTIVE) {
        continue;
    }
    $digest = dependencyJsonReferenceDigest(
        $candidate['table'],
        $candidate['column'],
        $fromHotelId,
        dependencyAllJsonIdentityKeys()
    );
    if ($digest['reference_count'] === 0) {
        continue;
    }
    $reference = [
        'table' => $candidate['table'],
        'column' => $candidate['column'],
        'reference_count' => $digest['reference_count'],
        'multiset_sha256' => $digest['multiset_sha256'],
        'policy' => $candidate['policy'],
    ];
    if ($candidate['policy'] === CLOUD_HOTEL_ID_JSON_IMMUTABLE_EVIDENCE) {
        $preservedImmutableEvidenceReferences[] = $reference;
        continue;
    }
    $blockingUnknownJsonReferences[] = $reference;
}

$jsonReferenceTableColumns = [];
$allJsonReferences = [
    ...$mutableActiveConfigReferences,
    ...$preservedImmutableEvidenceReferences,
    ...$blockingUnknownJsonReferences,
];
$jsonReferenceTables = array_values(array_unique(array_map(
    static fn(array $reference): string => (string)$reference['table'],
    $allJsonReferences
)));
foreach ($jsonReferenceTables as $table) {
    $metadataRows = Db::query(
        'SELECT COLUMN_NAME AS column_name,DATA_TYPE AS data_type,COLUMN_TYPE AS column_type,'
        . 'IS_NULLABLE AS is_nullable,COLUMN_KEY AS column_key '
        . 'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION',
        [$database, $table]
    );
    $jsonReferenceTableColumns[$table] = array_map('dependencyProjectRow', $metadataRows);
}

$registeredPositiveMigrationReferences = array_values(array_filter(
    $discoveredColumns,
    static fn(array $entry): bool => $entry['registered_column_classification'] === CLOUD_HOTEL_ID_COLUMN_POSITIVE
        && (int)$entry['from_count'] > 0
));
$blockingTargetReferences = array_values(array_filter(
    $discoveredColumns,
    static fn(array $entry): bool => $entry['registered_column_classification'] === CLOUD_HOTEL_ID_COLUMN_POSITIVE
        && (int)$entry['to_count'] > 0
));
$hasBlockingCondition = $unregisteredStoreIdColumns !== []
    || $blockingUnknownReferences !== []
    || $invalidRegisteredPositiveColumns !== []
    || $invalidDerivedColumns !== []
    || $foreignKeys !== []
    || $blockingTargetReferences !== []
    || $mutableActiveConfigErrors !== []
    || $blockingUnknownJsonReferences !== [];
$hasMigrationWork = $registeredPositiveMigrationReferences !== []
    || $mutableActiveConfigReferences !== [];
$auditStatus = $hasBlockingCondition
    ? 'review_required'
    : ($hasMigrationWork ? 'migration_required' : 'ok');
$receipt = [
    'contract_version' => 'suxios.cloud_hotel_id_dependency_audit.v2',
    'registry_contract_version' => CLOUD_HOTEL_ID_COLUMN_REGISTRY_CONTRACT,
    'status' => $auditStatus,
    'mode' => 'read_only_dependency_audit',
    'inspected_at' => date(DATE_ATOM),
    'database' => $database,
    'from_hotel_id' => $fromHotelId,
    'to_hotel_id' => $toHotelId,
    'source_hotel' => dependencyProjectRow(is_array($sourceHotel) ? $sourceHotel : null),
    'target_hotel' => dependencyProjectRow(is_array($targetHotel) ? $targetHotel : null),
    'discovery_boundary' => [
        'column_patterns' => ['hotels.id', '(^|_)hotel_id$', 'store_id'],
        'automatic_migration_scope' => 'explicit_registry_only',
        'unregistered_store_id_policy' => 'fail_closed_review_required_never_auto_migrate',
        'mutable_active_json_policy' => 'explicit_location_and_policy_specific_identity_keys_require_migration',
        'immutable_json_policy' => 'explicit_allowlist_raw_multiset_digest_preserved_without_rewrite',
        'unknown_json_policy' => 'source_identity_reference_is_review_required',
        'raw_json_values_disclosed' => false,
    ],
    'column_classification_registry' => cloudHotelIdColumnRegistry(),
    'registered_positive_system_hotel_columns' => cloudHotelIdPositiveColumnRegistry(),
    'discovered_hotel_identity_columns' => $discoveredColumns,
    'numeric_hotel_id_columns' => $numericHotelIdentityColumns,
    'review_required_columns' => $reviewRequiredColumns,
    'unregistered_store_id_columns' => $unregisteredStoreIdColumns,
    'blocking_unknown_references' => $blockingUnknownReferences,
    'blocking_target_references' => $blockingTargetReferences,
    'invalid_registered_positive_columns' => $invalidRegisteredPositiveColumns,
    'invalid_derived_readonly_columns' => $invalidDerivedColumns,
    'foreign_keys_to_hotels' => array_map('dependencyProjectRow', $foreignKeys),
    'mutable_active_config_references' => $mutableActiveConfigReferences,
    'mutable_active_config_errors' => $mutableActiveConfigErrors,
    'preserved_immutable_evidence_references' => $preservedImmutableEvidenceReferences,
    'blocking_unknown_json_references' => $blockingUnknownJsonReferences,
    'json_reference_table_columns' => $jsonReferenceTableColumns,
    'raw_json_values_disclosed' => false,
    'database_write_performed' => false,
];
echo json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($auditStatus === 'ok' ? 0 : 3);
