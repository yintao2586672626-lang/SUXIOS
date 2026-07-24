<?php
declare(strict_types=1);

use think\App;
use think\facade\Db;

require __DIR__ . '/../vendor/autoload.php';

$app = new App();
$app->initialize();

set_exception_handler(static function (Throwable $exception): never {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(2);
});

const P0_TRAFFIC_SOURCE_MARKER = 'p0_ota_field_loop';

/**
 * @param array<int, string> $argv
 * @return array<string, mixed>
 */
function parse_options(array $argv): array
{
    $options = [
        'date' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d'),
        'platform' => '',
        'system-hotel-id' => null,
        'execute' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--execute') {
            $options['execute'] = true;
            continue;
        }
        if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
            continue;
        }
        [$key, $value] = explode('=', substr($arg, 2), 2);
        if (array_key_exists($key, $options)) {
            $options[$key] = trim($value);
        }
    }

    $dateText = (string)$options['date'];
    $timezone = new DateTimeZone('Asia/Shanghai');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateText, $timezone);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if ($date === false
        || ($dateErrors !== false && ((int)$dateErrors['warning_count'] > 0 || (int)$dateErrors['error_count'] > 0))
        || $date->format('Y-m-d') !== $dateText
    ) {
        throw new InvalidArgumentException('Invalid --date, expected a real calendar date in YYYY-MM-DD.');
    }
    $currentDate = new DateTimeImmutable('today', $timezone);
    if ($date > $currentDate) {
        throw new InvalidArgumentException('--date must not be later than the current Asia/Shanghai date.');
    }

    $systemHotelId = $options['system-hotel-id'];
    if ($systemHotelId === null || trim((string)$systemHotelId) === '') {
        $options['system-hotel-id'] = null;
    } elseif (!preg_match('/^[1-9][0-9]*$/', trim((string)$systemHotelId))) {
        throw new InvalidArgumentException('Invalid --system-hotel-id, expected a positive integer.');
    } else {
        $options['system-hotel-id'] = (int)$systemHotelId;
    }

    return $options;
}

/**
 * @return array<int, string>
 */
function selected_platforms(string $value): array
{
    if (trim($value) === '') {
        return ['ctrip', 'meituan'];
    }

    $platforms = array_values(array_unique(array_filter(array_map(
        static fn(string $platform): string => strtolower(trim($platform)),
        explode(',', $value)
    ))));

    if ($platforms === []) {
        throw new InvalidArgumentException('Invalid --platform, expected at least one of ctrip or meituan.');
    }

    foreach ($platforms as $platform) {
        if (!in_array($platform, ['ctrip', 'meituan'], true)) {
            throw new InvalidArgumentException('Unsupported --platform, expected ctrip and/or meituan.');
        }
    }

    return $platforms;
}

/**
 * Resolve the registration denominator from enabled hotels and active Profile
 * bindings. Matching Profile sources are validated per hotel later and must
 * never shrink the denominator. Target-date rows are evidence, not scope.
 *
 * @param array<int, string> $platforms
 * @param array<int, array<string, mixed>> $hotelRows
 * @param array<int, array<string, mixed>> $bindingRows
 * @param array<int, array<string, mixed>> $sourceRows
 * @return array<string, array<int, int>>
 */
function resolve_registration_hotel_scope(
    array $platforms,
    ?int $explicitHotelId,
    array $hotelRows,
    array $bindingRows,
    array $sourceRows
): array {
    $result = [];
    foreach ($platforms as $platform) {
        $result[$platform] = [];
    }

    $validHotels = [];
    foreach ($hotelRows as $hotel) {
        $hotelId = (int)($hotel['id'] ?? 0);
        $tenantId = (int)($hotel['tenant_id'] ?? 0);
        if ($hotelId <= 0 || $tenantId <= 0 || (int)($hotel['status'] ?? 0) !== 1) {
            continue;
        }
        $validHotels[$hotelId] = $tenantId;
    }

    if ($explicitHotelId !== null) {
        if (isset($validHotels[$explicitHotelId])) {
            foreach ($result as $platform => $_hotelIds) {
                $result[$platform] = [$explicitHotelId];
            }
        }
        return $result;
    }

    $bindingScopes = [];
    foreach ($bindingRows as $binding) {
        $platform = strtolower(trim((string)($binding['platform'] ?? '')));
        $profileKeyHash = strtolower(trim((string)($binding['profile_key_hash'] ?? '')));
        $hotelId = (int)($binding['system_hotel_id'] ?? 0);
        $tenantId = (int)($binding['tenant_id'] ?? 0);
        if (!isset($result[$platform])
            || !preg_match('/^[a-f0-9]{64}$/', $profileKeyHash)
            || strtolower(trim((string)($binding['binding_status'] ?? ''))) !== 'active'
            || ($validHotels[$hotelId] ?? 0) !== $tenantId
        ) {
            continue;
        }
        $bindingScopes[$platform][$profileKeyHash][$tenantId . ':' . $hotelId] = true;
        $result[$platform][] = $hotelId;
    }

    foreach ($sourceRows as $source) {
        $platform = strtolower(trim((string)($source['platform'] ?? '')));
        $profileKeyHash = strtolower(trim((string)($source['profile_key_hash'] ?? '')));
        $hotelId = (int)($source['system_hotel_id'] ?? 0);
        $tenantId = (int)($source['tenant_id'] ?? 0);
        $scopes = $bindingScopes[$platform][$profileKeyHash] ?? [];
        if (!isset($result[$platform])
            || ($validHotels[$hotelId] ?? 0) !== $tenantId
            || !in_array(strtolower(trim((string)($source['ingestion_method'] ?? ''))), ['browser_profile', 'profile_browser'], true)
            || (int)($source['enabled'] ?? 0) !== 1
            || strtolower(trim((string)($source['status'] ?? ''))) === 'disabled'
            || !preg_match('/^[a-f0-9]{64}$/', $profileKeyHash)
            || count($scopes) !== 1
            || !isset($scopes[$tenantId . ':' . $hotelId])
        ) {
            continue;
        }
        $result[$platform][] = $hotelId;
    }

    foreach ($result as $platform => $hotelIds) {
        $hotelIds = array_values(array_unique(array_map('intval', $hotelIds)));
        sort($hotelIds);
        $result[$platform] = $hotelIds;
    }
    return $result;
}

/** @param array<int, int> $hotelIds @return array{status:string,reason:string} */
function registration_target_scope_state(array $hotelIds): array
{
    return $hotelIds === []
        ? ['status' => 'incomplete', 'reason' => 'no_target_hotel_scope']
        : ['status' => 'ready', 'reason' => ''];
}

/**
 * @param array<int, string> $platforms
 * @return array<string, array<int, int>>
 */
function target_hotel_ids_by_platform(string $date, array $platforms): array
{
    $rows = Db::name('online_daily_data')
        ->field('source,system_hotel_id,COUNT(*) AS row_count')
        ->where('data_date', $date)
        ->whereIn('source', $platforms)
        ->whereNotIn('data_type', ['traffic_forecast'])
        ->where('system_hotel_id', '>', 0)
        ->group('source,system_hotel_id')
        ->select()
        ->toArray();

    $result = [];
    foreach ($platforms as $platform) {
        $result[$platform] = [];
    }
    foreach ($rows as $row) {
        $platform = strtolower((string)($row['source'] ?? ''));
        $hotelId = (int)($row['system_hotel_id'] ?? 0);
        if (!isset($result[$platform]) || $hotelId <= 0) {
            continue;
        }
        $result[$platform][] = $hotelId;
    }

    foreach ($result as $platform => $ids) {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        $result[$platform] = $ids;
    }

    return $result;
}

function registration_table_exists(string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    if (!preg_match('/^[a-z0-9_]+$/', $table)) {
        return $cache[$table] = false;
    }

    try {
        Db::query('SHOW COLUMNS FROM `' . $table . '`');
        return $cache[$table] = true;
    } catch (Throwable) {
        return $cache[$table] = false;
    }
}

/**
 * Project only non-secret scalar metadata from platform_data_sources.config_json.
 *
 * @param array<string, mixed> $config
 * @return array<string, mixed>
 */
function safe_platform_config_projection(array $config): array
{
    $allowed = [
        'credential_ref', 'credential_status', 'config_id', 'source_config_id', 'source_config_key',
        'profile_id', 'profileId',
        'hotel_id', 'hotelId', 'ctrip_hotel_id', 'ctripHotelId', 'node_id', 'nodeId',
        'store_id', 'storeId', 'poi_id', 'poiId', 'partner_id', 'partnerId',
        'hotel_name', 'name', 'poi_name', 'poiName',
        'manual_login_state_verified', 'login_state_verified', 'profile_login_verified',
        'profile_status', 'login_status', 'profile_login_status',
        'last_login_verified_at', 'profile_login_verified_at', 'last_profile_login_at',
    ];
    $projection = [];
    foreach ($allowed as $key) {
        if (!array_key_exists($key, $config) || (!is_scalar($config[$key]) && $config[$key] !== null)) {
            continue;
        }
        $projection[$key] = $config[$key];
    }
    return $projection;
}

/**
 * @param array<string, mixed> $projection
 */
function platform_projection_has_identity_conflict(string $platform, array $projection): bool
{
    $groups = [
        ['config_id', 'source_config_id', 'source_config_key'],
        ['profile_id', 'profileId'],
    ];
    if ($platform === 'ctrip') {
        $groups[] = ['hotel_id', 'hotelId', 'ctrip_hotel_id', 'ctripHotelId'];
        $groups[] = ['node_id', 'nodeId'];
    } else {
        $groups[] = ['store_id', 'storeId'];
        $groups[] = ['poi_id', 'poiId'];
        $groups[] = ['partner_id', 'partnerId'];
    }

    foreach ($groups as $keys) {
        $values = [];
        foreach ($keys as $key) {
            $value = first_string($projection, [$key]);
            if ($value !== '') {
                $values[$value] = true;
            }
        }
        if (count($values) > 1) {
            return true;
        }
    }
    return false;
}

/**
 * @param array<int, int> $hotelIds
 * @return array<int, array<string, mixed>>
 */
function hotel_metadata_for_ids(array $hotelIds): array
{
    if ($hotelIds === [] || !registration_table_exists('hotels')) {
        return [];
    }

    $rows = Db::name('hotels')
        ->field('id,tenant_id,name')
        ->whereIn('id', $hotelIds)
        ->select()
        ->toArray();
    $result = [];
    foreach ($rows as $row) {
        $hotelId = (int)($row['id'] ?? 0);
        if ($hotelId > 0) {
            $result[$hotelId] = $row;
        }
    }
    return $result;
}

/**
 * @param array<int, int> $hotelIds
 * @return array<int, array<int, array<string, mixed>>>
 */
function safe_source_projections_for_hotels(string $platform, array $hotelIds): array
{
    if ($hotelIds === [] || !registration_table_exists('platform_data_sources')) {
        return [];
    }

    $fields = ['id', 'platform', 'data_type', 'ingestion_method', 'system_hotel_id', 'status', 'enabled', 'config_json', 'update_time'];
    if (isset(platform_data_source_columns()['tenant_id'])) {
        $fields[] = 'tenant_id';
    }
    $rows = Db::name('platform_data_sources')
        ->field(implode(',', $fields))
        ->where('platform', $platform)
        ->whereIn('system_hotel_id', $hotelIds)
        ->order('id', 'asc')
        ->select()
        ->toArray();

    $result = [];
    foreach ($rows as $row) {
        $hotelId = (int)($row['system_hotel_id'] ?? 0);
        $decoded = json_decode((string)($row['config_json'] ?? ''), true);
        $row['config'] = safe_platform_config_projection(is_array($decoded) ? $decoded : []);
        unset($row['config_json']);
        if ($hotelId > 0) {
            $result[$hotelId][] = $row;
        }
    }
    return $result;
}

/**
 * @param array<string, mixed> $credential
 * @param array<int, array<string, mixed>> $sourceRows
 * @return array{projection:array<string,mixed>,source_ids:array<int,int>,conflict:bool}
 */
function merge_matching_source_projection(array $credential, array $sourceRows): array
{
    $credentialRef = (int)($credential['id'] ?? 0);
    $configId = trim((string)($credential['config_id'] ?? ''));
    $projection = [];
    $sourceIds = [];
    $conflict = false;
    $mergeKeys = [
        'config_id', 'source_config_id', 'source_config_key',
        'profile_id', 'profileId',
        'hotel_id', 'hotelId', 'ctrip_hotel_id', 'ctripHotelId', 'node_id', 'nodeId',
        'store_id', 'storeId', 'poi_id', 'poiId', 'partner_id', 'partnerId',
        'hotel_name', 'name', 'poi_name', 'poiName',
    ];

    foreach ($sourceRows as $sourceRow) {
        $config = is_array($sourceRow['config'] ?? null) ? $sourceRow['config'] : [];
        $sourceCredentialRef = (int)($config['credential_ref'] ?? 0);
        $sourceConfigId = first_string($config, ['config_id', 'source_config_id', 'source_config_key']);
        $hasCredentialRef = $sourceCredentialRef > 0;
        $hasConfigId = $sourceConfigId !== '';
        $credentialRefMatches = $hasCredentialRef && $sourceCredentialRef === $credentialRef;
        $configIdMatches = $hasConfigId && $sourceConfigId === $configId;
        if ($hasCredentialRef && $hasConfigId && $credentialRefMatches !== $configIdMatches) {
            $conflict = true;
            continue;
        }
        if (!$credentialRefMatches && !$configIdMatches) {
            continue;
        }

        $sourceIds[] = (int)($sourceRow['id'] ?? 0);
        foreach ($config as $key => $value) {
            if (!in_array($key, $mergeKeys, true)) {
                continue;
            }
            if (!array_key_exists($key, $projection) || trim((string)$projection[$key]) === '') {
                $projection[$key] = $value;
                continue;
            }
            if (trim((string)$value) !== '' && (string)$projection[$key] !== (string)$value) {
                $conflict = true;
            }
        }
    }
    if (platform_projection_has_identity_conflict((string)($credential['platform'] ?? ''), $projection)) {
        $conflict = true;
    }

    return [
        'projection' => $projection,
        'source_ids' => array_values(array_filter(array_unique($sourceIds), static fn(int $id): bool => $id > 0)),
        'conflict' => $conflict,
    ];
}

/**
 * @param array<int, int> $hotelIds
 * @return array{contexts:array<int,array<string,mixed>>,blockers:array<int,array<string,mixed>>}
 */
function credential_contexts_for_hotels(string $platform, array $hotelIds): array
{
    $contexts = [];
    $blockers = [];
    $hotels = hotel_metadata_for_ids($hotelIds);
    $sourceRowsByHotel = safe_source_projections_for_hotels($platform, $hotelIds);

    if (!registration_table_exists('ota_credentials')) {
        foreach ($hotelIds as $hotelId) {
            $blockers[$hotelId] = [
                'status' => 'migration_required',
                'reason' => 'ota_credentials_table_missing',
            ];
        }
        return ['contexts' => [], 'blockers' => $blockers];
    }

    $credentialRows = $hotelIds === [] ? [] : Db::name('ota_credentials')
        ->field('id,tenant_id,system_hotel_id,platform,config_id,credential_status,rotated_at,create_time,update_time')
        ->where('platform', $platform)
        ->whereIn('system_hotel_id', $hotelIds)
        ->order('id', 'asc')
        ->select()
        ->toArray();
    $credentialsByHotel = [];
    foreach ($credentialRows as $row) {
        $credentialsByHotel[(int)($row['system_hotel_id'] ?? 0)][] = $row;
    }

    foreach ($hotelIds as $hotelId) {
        $hotel = $hotels[$hotelId] ?? null;
        if (!is_array($hotel)) {
            $blockers[$hotelId] = ['status' => 'blocked', 'reason' => 'hotel_metadata_missing'];
            continue;
        }
        $tenantId = (int)($hotel['tenant_id'] ?? 0);
        if ($tenantId <= 0) {
            $blockers[$hotelId] = ['status' => 'migration_required', 'reason' => 'hotel_tenant_id_missing'];
            continue;
        }
        $hotelSourceRows = $sourceRowsByHotel[$hotelId] ?? [];
        $sourceTenantMismatch = count(array_filter(
            $hotelSourceRows,
            static fn(array $row): bool => (int)($row['tenant_id'] ?? 0) > 0
                && (int)($row['tenant_id'] ?? 0) !== $tenantId
        )) > 0;
        if ($sourceTenantMismatch) {
            $blockers[$hotelId] = ['status' => 'blocked', 'reason' => 'data_source_tenant_scope_mismatch'];
            continue;
        }
        $hotelSourceRows = array_values(array_filter(
            $hotelSourceRows,
            static fn(array $row): bool => (int)($row['tenant_id'] ?? 0) <= 0
                || (int)($row['tenant_id'] ?? 0) === $tenantId
        ));

        $scoped = array_values(array_filter(
            $credentialsByHotel[$hotelId] ?? [],
            static fn(array $row): bool => (int)($row['tenant_id'] ?? 0) === $tenantId
        ));
        if (count($scoped) !== count($credentialsByHotel[$hotelId] ?? [])) {
            $blockers[$hotelId] = ['status' => 'blocked', 'reason' => 'credential_tenant_scope_mismatch'];
            continue;
        }
        $ready = array_values(array_filter(
            $scoped,
            static fn(array $row): bool => strtolower(trim((string)($row['credential_status'] ?? ''))) === 'ready'
        ));
        if ($ready === []) {
            $blockers[$hotelId] = [
                'status' => $scoped === [] ? 'migration_required' : 'blocked',
                'reason' => $scoped === [] ? 'credential_metadata_missing' : 'credential_not_ready',
            ];
            continue;
        }

        if (count($ready) > 1) {
            $referenced = [];
            foreach ($ready as $candidate) {
                $merged = merge_matching_source_projection($candidate, $hotelSourceRows);
                if ($merged['source_ids'] !== [] && !$merged['conflict']) {
                    $referenced[] = $candidate;
                }
            }
            if (count($referenced) !== 1) {
                $blockers[$hotelId] = ['status' => 'blocked', 'reason' => 'credential_metadata_ambiguous'];
                continue;
            }
            $ready = $referenced;
        }

        $credential = $ready[0];
        $merged = merge_matching_source_projection($credential, $hotelSourceRows);
        if ($merged['conflict']) {
            $blockers[$hotelId] = ['status' => 'blocked', 'reason' => 'source_config_projection_conflict'];
            continue;
        }
        if ($merged['source_ids'] === []) {
            $blockers[$hotelId] = ['status' => 'migration_required', 'reason' => 'credential_source_projection_missing'];
            continue;
        }

        $projection = $merged['projection'];
        $profileId = first_string($projection, ['profile_id', 'profileId']);
        $platformId = $platform === 'ctrip'
            ? first_string($projection, ['ctrip_hotel_id', 'ctripHotelId', 'hotel_id', 'hotelId', 'node_id', 'nodeId'])
            : first_string($projection, ['store_id', 'storeId', 'poi_id', 'poiId', 'partner_id', 'partnerId']);
        if ($platformId === '' || ($platform === 'ctrip' && $profileId === '')) {
            $blockers[$hotelId] = ['status' => 'migration_required', 'reason' => 'safe_source_identity_projection_incomplete'];
            continue;
        }

        $contexts[$hotelId] = [
            'credential_ref' => (int)($credential['id'] ?? 0),
            'credential_status' => 'ready',
            'config_id' => trim((string)($credential['config_id'] ?? '')),
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'hotel_name' => trim((string)($hotel['name'] ?? '')),
            'source_projection_ids' => $merged['source_ids'],
            'config' => $projection,
            'sort_time' => first_string($credential, ['update_time', 'rotated_at', 'create_time']),
        ];
    }

    ksort($contexts);
    ksort($blockers);
    return ['contexts' => $contexts, 'blockers' => $blockers];
}

/**
 * @param array<string, mixed> $config
 */
function browser_profile_identity_signature(string $platform, array $config): string
{
    if (platform_projection_has_identity_conflict($platform, $config)) {
        return '';
    }

    if ($platform === 'ctrip') {
        $profileId = first_string($config, ['profile_id', 'profileId']);
        $platformHotelId = first_string($config, [
            'ctrip_hotel_id', 'ctripHotelId', 'hotel_id', 'hotelId', 'node_id', 'nodeId',
        ]);
    } else {
        $profileId = first_string($config, ['store_id', 'storeId', 'profile_id', 'profileId', 'poi_id', 'poiId']);
        $platformHotelId = first_string($config, ['poi_id', 'poiId', 'store_id', 'storeId', 'partner_id', 'partnerId']);
    }

    if ($profileId === '' || $platformHotelId === '') {
        return '';
    }
    return hash('sha256', $platform . "\0" . $profileId . "\0" . $platformHotelId);
}

/**
 * @param array<string, mixed> $config
 */
function browser_profile_key(string $platform, array $config): string
{
    return $platform === 'meituan'
        ? first_string($config, ['store_id', 'storeId', 'profile_id', 'profileId'])
        : first_string($config, ['profile_id', 'profileId']);
}

function browser_profile_key_hash(string $profileKey): string
{
    $profileKey = trim($profileKey);
    if ($profileKey === '') {
        return '';
    }
    $safeFilePart = \app\service\BrowserProfileCaptureRequestService::safeFilePart($profileKey);
    if ($safeFilePart === '' || $safeFilePart === 'default') {
        return '';
    }
    return hash('sha256', $safeFilePart);
}

/**
 * @param array<int, string> $platforms
 * @return array<string, array<int, int>>
 */
function registration_hotel_scope_rows(array $platforms, ?int $explicitHotelId): array
{
    foreach (['hotels', 'ota_profile_bindings', 'platform_data_sources'] as $table) {
        if (!registration_table_exists($table)) {
            throw new RuntimeException('P0 registration scope table is missing: ' . $table);
        }
    }

    $hotelColumns = [];
    foreach (Db::query('SHOW COLUMNS FROM `hotels`') as $column) {
        $hotelColumns[(string)($column['Field'] ?? '')] = true;
    }
    if (array_diff(['id', 'tenant_id', 'status'], array_keys($hotelColumns)) !== []) {
        throw new RuntimeException('P0 registration hotel scope schema is incomplete.');
    }

    $bindingColumns = [];
    foreach (Db::query('SHOW COLUMNS FROM `ota_profile_bindings`') as $column) {
        $bindingColumns[(string)($column['Field'] ?? '')] = true;
    }
    $requiredBindingColumns = [
        'tenant_id', 'system_hotel_id', 'platform', 'profile_key_hash', 'binding_status',
    ];
    if (array_diff($requiredBindingColumns, array_keys($bindingColumns)) !== []) {
        throw new RuntimeException('P0 registration Profile binding scope schema is incomplete.');
    }

    $requiredSourceColumns = [
        'tenant_id', 'system_hotel_id', 'platform', 'data_type', 'ingestion_method',
        'enabled', 'status', 'config_json',
    ];
    if (array_diff($requiredSourceColumns, array_keys(platform_data_source_columns())) !== []) {
        throw new RuntimeException('P0 registration Profile source scope schema is incomplete.');
    }

    $hotelQuery = Db::name('hotels')
        ->field('id,tenant_id,status')
        ->where('tenant_id', '>', 0)
        ->where('status', 1);
    if ($explicitHotelId !== null) {
        $hotelQuery->where('id', $explicitHotelId);
    }
    $hotelRows = $hotelQuery->select()->toArray();

    if ($explicitHotelId !== null || $hotelRows === []) {
        return resolve_registration_hotel_scope($platforms, $explicitHotelId, $hotelRows, [], []);
    }

    $hotelIds = array_values(array_map(
        static fn(array $hotel): int => (int)($hotel['id'] ?? 0),
        $hotelRows
    ));
    $bindingRows = Db::name('ota_profile_bindings')
        ->field(implode(',', $requiredBindingColumns))
        ->whereIn('platform', $platforms)
        ->whereIn('system_hotel_id', $hotelIds)
        ->where('binding_status', 'active')
        ->select()
        ->toArray();
    $sourceRows = Db::name('platform_data_sources')
        ->field(implode(',', $requiredSourceColumns))
        ->whereIn('platform', $platforms)
        ->whereIn('system_hotel_id', $hotelIds)
        ->whereIn('ingestion_method', ['browser_profile', 'profile_browser'])
        ->where('enabled', 1)
        ->where('status', '<>', 'disabled')
        ->select()
        ->toArray();

    foreach ($sourceRows as &$source) {
        $config = json_decode((string)($source['config_json'] ?? ''), true);
        $config = safe_platform_config_projection(is_array($config) ? $config : []);
        $source['profile_key_hash'] = browser_profile_key_hash(
            browser_profile_key(strtolower(trim((string)($source['platform'] ?? ''))), $config)
        );
        unset($source['config_json']);
    }
    unset($source);

    return resolve_registration_hotel_scope(
        $platforms,
        null,
        $hotelRows,
        $bindingRows,
        $sourceRows
    );
}

/**
 * @param array<string, mixed> $config
 */
function browser_profile_dir_present(string $platform, array $config): bool
{
    $profileKey = browser_profile_key($platform, $config);
    if ($profileKey === '') {
        return false;
    }
    $safeFilePart = \app\service\BrowserProfileCaptureRequestService::safeFilePart($profileKey);
    if ($safeFilePart === '' || $safeFilePart === 'default') {
        return false;
    }
    return is_dir(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . $platform . '_profile_' . $safeFilePart);
}

/**
 * @return array<int, bool>
 */
function profile_scope_conflicted_source_ids(string $platform): array
{
    static $cache = [];
    if (isset($cache[$platform])) {
        return $cache[$platform];
    }
    if (!registration_table_exists('platform_data_sources')) {
        return $cache[$platform] = [];
    }
    $fields = ['id', 'platform', 'ingestion_method', 'system_hotel_id', 'status', 'enabled', 'config_json'];
    if (isset(platform_data_source_columns()['tenant_id'])) {
        $fields[] = 'tenant_id';
    }
    $rows = Db::name('platform_data_sources')
        ->field(implode(',', $fields))
        ->where('platform', $platform)
        ->whereIn('ingestion_method', ['browser_profile', 'profile_browser'])
        ->where('enabled', 1)
        ->where('status', '<>', 'disabled')
        ->select()
        ->toArray();
    $hotelIds = array_values(array_unique(array_filter(array_map(
        static fn(array $row): int => (int)($row['system_hotel_id'] ?? 0),
        $rows
    ), static fn(int $hotelId): bool => $hotelId > 0)));
    $hotels = hotel_metadata_for_ids($hotelIds);
    $groups = [];
    foreach ($rows as $row) {
        $decoded = json_decode((string)($row['config_json'] ?? ''), true);
        $config = safe_platform_config_projection(is_array($decoded) ? $decoded : []);
        $profileKeyHash = browser_profile_key_hash(browser_profile_key($platform, $config));
        $sourceId = (int)($row['id'] ?? 0);
        $hotelId = (int)($row['system_hotel_id'] ?? 0);
        if ($profileKeyHash === '' || $sourceId <= 0 || $hotelId <= 0) {
            continue;
        }
        $tenantId = (int)($hotels[$hotelId]['tenant_id'] ?? $row['tenant_id'] ?? 0);
        $groups[$profileKeyHash]['scopes'][$tenantId . ':' . $hotelId] = true;
        $groups[$profileKeyHash]['source_ids'][$sourceId] = true;
    }
    $conflicted = [];
    foreach ($groups as $group) {
        if (count((array)($group['scopes'] ?? [])) <= 1) {
            continue;
        }
        foreach (array_keys((array)($group['source_ids'] ?? [])) as $sourceId) {
            $conflicted[(int)$sourceId] = true;
        }
    }
    return $cache[$platform] = $conflicted;
}

/**
 * @param array<string, mixed> $row
 * @param array<string, mixed> $config
 * @param array<int, bool> $conflictedSourceIds
 * @return array{status:string,reason:string}
 */
function profile_binding_scope_status(string $platform, int $tenantId, int $hotelId, array $row, array $config, array $conflictedSourceIds): array
{
    $profileKeyHash = browser_profile_key_hash(browser_profile_key($platform, $config));
    if ($profileKeyHash === '') {
        return ['status' => 'migration_required', 'reason' => 'profile_key_missing'];
    }
    if (!registration_table_exists('ota_profile_bindings')) {
        return ['status' => 'migration_required', 'reason' => 'profile_binding_table_missing'];
    }
    $columns = [];
    foreach (Db::query('SHOW COLUMNS FROM `ota_profile_bindings`') as $column) {
        $name = (string)($column['Field'] ?? '');
        if ($name !== '') {
            $columns[$name] = true;
        }
    }
    $required = ['id', 'tenant_id', 'system_hotel_id', 'platform', 'profile_key_hash', 'binding_status'];
    if (array_diff($required, array_keys($columns)) !== []) {
        return ['status' => 'migration_required', 'reason' => 'profile_binding_schema_incomplete'];
    }

    $activeBindings = Db::name('ota_profile_bindings')
        ->field(implode(',', $required))
        ->where('platform', $platform)
        ->where('profile_key_hash', $profileKeyHash)
        ->where('binding_status', 'active')
        ->select()
        ->toArray();
    if ($activeBindings === []) {
        $bindingExists = (int)Db::name('ota_profile_bindings')
            ->where('platform', $platform)
            ->where('profile_key_hash', $profileKeyHash)
            ->count() > 0;
        return [
            'status' => $bindingExists ? 'blocked' : 'migration_required',
            'reason' => $bindingExists ? 'profile_binding_not_active' : 'profile_binding_missing',
        ];
    }
    if (count($activeBindings) !== 1) {
        return ['status' => 'blocked', 'reason' => 'profile_binding_ambiguous'];
    }
    $binding = $activeBindings[0];
    if ((int)($binding['tenant_id'] ?? 0) !== $tenantId
        || (int)($binding['system_hotel_id'] ?? 0) !== $hotelId
    ) {
        return ['status' => 'blocked', 'reason' => 'profile_binding_scope_mismatch'];
    }
    $sourceId = (int)($row['id'] ?? 0);
    if ($sourceId <= 0 || isset($conflictedSourceIds[$sourceId])) {
        return ['status' => 'blocked', 'reason' => 'profile_scope_conflict_across_hotel_or_tenant'];
    }
    return ['status' => 'ready', 'reason' => ''];
}

/**
 * Resolve only verified, same-tenant browser Profile metadata. This path
 * deliberately does not read ota_credentials or platform_data_sources.secret_json.
 *
 * @param array<int, int> $hotelIds
 * @return array{contexts:array<int,array<string,mixed>>,blockers:array<int,array<string,mixed>>}
 */
function profile_contexts_for_hotels(string $platform, array $hotelIds): array
{
    $contexts = [];
    $blockers = [];
    $hotels = hotel_metadata_for_ids($hotelIds);
    $sourceRowsByHotel = safe_source_projections_for_hotels($platform, $hotelIds);
    $conflictedSourceIds = profile_scope_conflicted_source_ids($platform);

    foreach ($hotelIds as $hotelId) {
        $hotel = $hotels[$hotelId] ?? null;
        if (!is_array($hotel)) {
            $blockers[$hotelId] = ['status' => 'blocked', 'reason' => 'hotel_metadata_missing'];
            continue;
        }
        $tenantId = (int)($hotel['tenant_id'] ?? 0);
        if ($tenantId <= 0) {
            $blockers[$hotelId] = ['status' => 'migration_required', 'reason' => 'hotel_tenant_id_missing'];
            continue;
        }

        $allRows = $sourceRowsByHotel[$hotelId] ?? [];
        $profileRows = array_values(array_filter(
            $allRows,
            static fn(array $row): bool => in_array(
                strtolower(trim((string)($row['ingestion_method'] ?? ''))),
                ['browser_profile', 'profile_browser'],
                true
            )
        ));
        if (count(array_filter(
            $profileRows,
            static fn(array $row): bool => (int)($row['tenant_id'] ?? 0) > 0
                && (int)($row['tenant_id'] ?? 0) !== $tenantId
        )) > 0) {
            $blockers[$hotelId] = ['status' => 'blocked', 'reason' => 'data_source_tenant_scope_mismatch'];
            continue;
        }
        $profileRows = array_values(array_filter(
            $profileRows,
            static fn(array $row): bool => ((int)($row['tenant_id'] ?? 0) <= 0 || (int)($row['tenant_id'] ?? 0) === $tenantId)
                && (int)($row['enabled'] ?? 0) === 1
                && strtolower(trim((string)($row['status'] ?? ''))) !== 'disabled'
        ));
        if ($profileRows === []) {
            $blockers[$hotelId] = ['status' => 'migration_required', 'reason' => 'browser_profile_source_missing'];
            continue;
        }

        $preparedRows = [];
        $preparationFailures = [];
        foreach ($profileRows as $row) {
            $config = is_array($row['config'] ?? null) ? $row['config'] : [];
            $binding = profile_binding_scope_status(
                $platform,
                $tenantId,
                $hotelId,
                $row,
                $config,
                $conflictedSourceIds
            );
            if ((string)($binding['status'] ?? '') !== 'ready') {
                $preparationFailures[] = $binding;
                continue;
            }
            if (!browser_profile_dir_present($platform, $config)) {
                $preparationFailures[] = ['status' => 'migration_required', 'reason' => 'profile_not_prepared'];
                continue;
            }
            $row['historical_login_metadata_present'] = profile_login_metadata_verified_config($config);
            $preparedRows[] = $row;
        }
        if ($preparedRows === []) {
            $blockedFailure = null;
            foreach ($preparationFailures as $failure) {
                if ((string)($failure['status'] ?? '') === 'blocked') {
                    $blockedFailure = $failure;
                    break;
                }
            }
            $blockers[$hotelId] = is_array($blockedFailure)
                ? $blockedFailure
                : ($preparationFailures[0] ?? ['status' => 'migration_required', 'reason' => 'profile_binding_missing']);
            continue;
        }

        $identityGroups = [];
        $identityIncomplete = false;
        foreach ($preparedRows as $row) {
            $config = is_array($row['config'] ?? null) ? $row['config'] : [];
            $signature = browser_profile_identity_signature($platform, $config);
            if ($signature === '') {
                $identityIncomplete = true;
                continue;
            }
            $identityGroups[$signature][] = $row;
        }
        if (count($identityGroups) > 1) {
            $blockers[$hotelId] = ['status' => 'blocked', 'reason' => 'browser_profile_identity_ambiguous'];
            continue;
        }
        if ($identityGroups === []) {
            $blockers[$hotelId] = [
                'status' => $identityIncomplete ? 'migration_required' : 'blocked',
                'reason' => $identityIncomplete ? 'safe_source_identity_projection_incomplete' : 'browser_profile_identity_missing',
            ];
            continue;
        }

        $matchingRows = array_values(reset($identityGroups));
        usort($matchingRows, static function (array $left, array $right): int {
            $score = static function (array $row): int {
                $score = strtolower(trim((string)($row['status'] ?? ''))) === 'success' ? 100 : 0;
                $score += strtolower(trim((string)($row['last_sync_status'] ?? ''))) === 'success' ? 20 : 0;
                return $score + min(19, max(0, (int)($row['id'] ?? 0)));
            };
            return $score($right) <=> $score($left);
        });
        $selected = $matchingRows[0];
        $projection = is_array($selected['config'] ?? null) ? $selected['config'] : [];
        $sourceProjectionIds = array_values(array_filter(array_unique(array_map(
            static fn(array $row): int => (int)($row['id'] ?? 0),
            $matchingRows
        )), static fn(int $id): bool => $id > 0));
        $sourceConfigKey = first_string($projection, ['config_id', 'source_config_id', 'source_config_key']);

        $contexts[$hotelId] = [
            'credential_usage' => 'not_required_for_browser_profile',
            'credential_status' => 'not_required',
            'config_id' => $sourceConfigKey,
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'hotel_name' => trim((string)($hotel['name'] ?? '')),
            'source_projection_ids' => $sourceProjectionIds,
            'config' => $projection,
            'historical_login_metadata_present' => (bool)($selected['historical_login_metadata_present'] ?? false),
            'sort_time' => trim((string)($selected['update_time'] ?? '')),
        ];
    }

    ksort($contexts);
    ksort($blockers);
    return ['contexts' => $contexts, 'blockers' => $blockers];
}

/**
 * @param array<string, mixed> $row
 * @param array<int, string> $keys
 */
function first_string(array $row, array $keys): string
{
    foreach ($keys as $key) {
        $raw = $row[$key] ?? '';
        if (!is_scalar($raw) && $raw !== null) {
            continue;
        }
        $value = trim((string)$raw);
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

/**
 * @return array<string, bool>
 */
function platform_data_source_columns(): array
{
    static $columns = null;
    if ($columns !== null) {
        return $columns;
    }

    $columns = [];
    foreach (Db::query('SHOW COLUMNS FROM `platform_data_sources`') as $row) {
        $field = (string)($row['Field'] ?? '');
        if ($field !== '') {
            $columns[$field] = true;
        }
    }

    return $columns;
}

/**
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function filter_platform_data_source_columns(array $data): array
{
    return array_intersect_key($data, platform_data_source_columns());
}

function truthy_config_value(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value)) {
        return $value === 1;
    }
    if (is_string($value)) {
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'verified'], true);
    }
    return false;
}

/**
 * @param array<string, mixed> $config
 */
function profile_login_state_verified_config(array $config): bool
{
    foreach (['manual_login_state_verified', 'login_state_verified', 'profile_login_verified'] as $key) {
        if (truthy_config_value($config[$key] ?? false)) {
            return true;
        }
    }
    return false;
}

/**
 * @param array<string, mixed> $config
 */
function profile_login_metadata_verified_config(array $config): bool
{
    if (!profile_login_state_verified_config($config)) {
        return false;
    }

    $status = strtolower(first_string($config, ['profile_status', 'login_status', 'profile_login_status']));
    if (!in_array($status, ['logged_in', 'authorized'], true)) {
        return false;
    }

    return first_string($config, [
        'last_login_verified_at',
        'profile_login_verified_at',
        'last_profile_login_at',
    ]) !== '';
}

/**
 * @param array<string, mixed> $selected
 * @return array<string, mixed>
 */
function build_source_spec(string $platform, array $selected, string $targetDate): array
{
    $row = is_array($selected['config'] ?? null) ? $selected['config'] : [];
    $systemHotelId = (int)($selected['system_hotel_id'] ?? 0);
    $tenantId = (int)($selected['tenant_id'] ?? 0);
    $hotelName = trim((string)($selected['hotel_name'] ?? ''));
    $configKey = trim((string)($selected['config_id'] ?? ''));
    $sortTime = (string)($selected['sort_time'] ?? '');
    $baseConfig = [
        'capture_sections' => 'traffic',
        'source_resource' => 'flowData',
        'registered_by' => P0_TRAFFIC_SOURCE_MARKER,
        'registered_from' => 'bound_browser_profile_identity_metadata_only',
        'credential_usage' => 'not_required_for_browser_profile',
        'credential_status' => 'not_required',
        'profile_binding_status' => 'ready',
        'profile_binding_policy' => 'active_ota_profile_binding_same_platform_tenant_hotel',
        'profile_execution_policy' => 'profile_session_metadata_only_no_vault_decrypt',
        'source_config_updated_at' => $sortTime,
        'source_projection_ids' => array_values(array_map('intval', (array)($selected['source_projection_ids'] ?? []))),
        'target_date' => $targetDate,
        'source_scope' => 'ota_channel_only',
        'manual_login_state_verified' => false,
        'historical_login_metadata_present' => (bool)($selected['historical_login_metadata_present'] ?? false),
        'login_evidence_scope' => 'historical_metadata_only',
        'current_session_probe_performed' => false,
        'current_session_verified' => false,
        'current_session_status' => 'unverified',
        'session_probe_status' => 'ready_for_session_probe',
        'login_verification_status' => 'ready_for_session_probe',
        'profile_auth_evidence_policy' => 'Historical Profile metadata and directory presence are preparation evidence only; current-session proof must be produced on this same data source before sync.',
    ];
    if ($configKey !== '') {
        $baseConfig['source_config_key'] = $configKey;
    }
    if ($platform === 'ctrip') {
        $profileId = first_string($row, ['profile_id', 'profileId']);
        $config = array_merge($baseConfig, [
            'profile_id' => $profileId,
            'hotel_id' => first_string($row, ['hotel_id', 'hotelId', 'ctrip_hotel_id', 'ctripHotelId']),
            'hotel_name' => $hotelName,
        ]);
        $nodeId = first_string($row, ['node_id', 'nodeId']);
        if ($nodeId !== '') {
            $config['node_id'] = $nodeId;
        }
        $name = 'Ctrip traffic browser Profile source';
    } else {
        $storeId = first_string($row, ['store_id', 'storeId', 'poi_id', 'poiId']);
        $config = array_merge($baseConfig, [
            'store_id' => $storeId,
            'poi_id' => first_string($row, ['poi_id', 'poiId']),
            'poi_name' => $hotelName,
            'partner_id' => first_string($row, ['partner_id', 'partnerId']),
        ]);
        $name = 'Meituan traffic browser Profile source';
    }

    return [
        'tenant_id' => $tenantId,
        'system_hotel_id' => $systemHotelId,
        'name' => $hotelName !== '' ? $name . ' - hotel ' . $systemHotelId : $name,
        'platform' => $platform,
        'data_type' => 'traffic',
        'ingestion_method' => 'browser_profile',
        'status' => 'waiting_config',
        'enabled' => 1,
        'config' => $config,
        'secret' => [],
    ];
}

/**
 * @param array<string, mixed> $spec
 * @return array<string, mixed>|null
 */
function find_existing_source(array $spec): ?array
{
    $query = Db::name('platform_data_sources')
        ->where('platform', (string)$spec['platform'])
        ->where('data_type', 'traffic')
        ->where('ingestion_method', 'browser_profile')
        ->where('system_hotel_id', (int)$spec['system_hotel_id']);
    if (isset(platform_data_source_columns()['tenant_id'])) {
        $query->where('tenant_id', (int)$spec['tenant_id']);
    }
    $rows = $query->order('id', 'asc')->select()->toArray();

    $firstActive = null;
    foreach ($rows as $row) {
        $config = json_decode((string)($row['config_json'] ?? ''), true);
        $config = is_array($config) ? $config : [];
        if (($config['registered_by'] ?? '') === P0_TRAFFIC_SOURCE_MARKER) {
            return $row;
        }
        if ($firstActive === null && (string)($row['status'] ?? '') !== 'disabled') {
            $firstActive = $row;
        }
    }

    return $firstActive;
}

/** @param array<string, mixed>|null $existing */
function existing_source_registration_policy(?array $existing): string
{
    if ($existing === null) {
        return 'insert_new_source';
    }
    $config = json_decode((string)($existing['config_json'] ?? ''), true);
    $config = is_array($config) ? $config : [];
    $managed = ($config['registered_by'] ?? '') === 'p0_ota_field_loop';
    $disabled = strtolower(trim((string)($existing['status'] ?? ''))) === 'disabled'
        || (int)($existing['enabled'] ?? 1) !== 1;
    if ($managed && $disabled) {
        return 'blocked_disabled_managed_source';
    }
    return $managed ? 'keep_managed_source' : 'keep_user_source';
}

/**
 * @param array<string, mixed> $spec
 * @param array<string, mixed>|null $existing
 * @return array<string, mixed>
 */
function persist_source(array $spec, ?array $existing, bool $execute): array
{
    $now = date('Y-m-d H:i:s');
    $config = is_array($spec['config'] ?? null) ? $spec['config'] : [];
    $lastError = 'Ready for a current-session probe on this bound hotel-scoped Profile; historical login metadata is reference only and no sync is authorized yet.';
    $actionMetadata = [
        'manual_login_state_verified' => false,
        'historical_login_metadata_present' => (bool)($config['historical_login_metadata_present'] ?? false),
        'login_evidence_scope' => 'historical_metadata_only',
        'current_session_probe_performed' => false,
        'current_session_verified' => false,
        'session_probe_status' => 'ready_for_session_probe',
        'login_verification_status' => (string)($config['login_verification_status'] ?? 'ready_for_session_probe'),
        'profile_binding_status' => (string)($config['profile_binding_status'] ?? 'ready'),
    ];
    $status = 'waiting_config';
    $lastSyncStatus = 'waiting_config';
    $data = [
        'tenant_id' => (int)$spec['tenant_id'],
        'system_hotel_id' => (int)$spec['system_hotel_id'],
        'user_id' => 1,
        'name' => (string)$spec['name'],
        'platform' => (string)$spec['platform'],
        'data_type' => 'traffic',
        'ingestion_method' => 'browser_profile',
        'status' => $status,
        'enabled' => 1,
        'config_json' => json_encode($spec['config'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'secret_json' => '{}',
        'last_sync_status' => $lastSyncStatus,
        'last_error' => $lastError,
        'updated_by' => 1,
        'update_time' => $now,
    ];
    $data = filter_platform_data_source_columns($data);

    $existingPolicy = existing_source_registration_policy($existing);
    if ($existingPolicy === 'blocked_disabled_managed_source') {
        return [
            'action' => 'blocked_disabled_managed_source',
            'data_source_id' => (int)($existing['id'] ?? 0),
            'platform' => (string)$spec['platform'],
            'system_hotel_id' => (int)$spec['system_hotel_id'],
            'status' => 'disabled',
            'enabled' => (int)($existing['enabled'] ?? 0),
            'reason' => 'managed_source_disabled_requires_explicit_operator_action',
        ] + $actionMetadata;
    }

    if ($existingPolicy === 'keep_managed_source' && $existing !== null) {
        $existingConfig = json_decode((string)($existing['config_json'] ?? ''), true);
        $existingConfig = is_array($existingConfig) ? $existingConfig : [];
        $currentSessionVerified = (new \app\service\OtaProfileSessionProofService())
            ->isCurrentVerified($existing);
        $historicalLoginMetadataPresent = (bool)($existingConfig['historical_login_metadata_present'] ?? false)
            || profile_login_metadata_verified_config($existingConfig);

        return [
            'action' => 'kept_existing_managed_source',
            'data_source_id' => (int)($existing['id'] ?? 0),
            'platform' => (string)$spec['platform'],
            'system_hotel_id' => (int)$spec['system_hotel_id'],
            'status' => (string)($existing['status'] ?? ''),
            'last_sync_status' => (string)($existing['last_sync_status'] ?? ''),
            'enabled' => (int)($existing['enabled'] ?? 0),
            'manual_login_state_verified' => $currentSessionVerified,
            'historical_login_metadata_present' => $historicalLoginMetadataPresent,
            'login_evidence_scope' => $currentSessionVerified
                ? 'current_session_same_source'
                : 'historical_metadata_only',
            'current_session_probe_performed' => truthy_config_value(
                $existingConfig['current_session_probe_performed'] ?? false
            ),
            'current_session_verified' => $currentSessionVerified,
            'session_probe_status' => $currentSessionVerified ? 'verified' : 'ready_for_session_probe',
            'login_verification_status' => $currentSessionVerified ? 'verified' : 'ready_for_session_probe',
            'profile_binding_status' => (string)($existingConfig['profile_binding_status'] ?? 'ready'),
        ];
    }

    if ($existing !== null && (string)($existing['status'] ?? '') !== 'disabled') {
        return [
            'action' => 'kept_existing_user_source',
            'data_source_id' => (int)($existing['id'] ?? 0),
            'platform' => (string)$spec['platform'],
            'system_hotel_id' => (int)$spec['system_hotel_id'],
            'status' => (string)($existing['status'] ?? ''),
            'enabled' => (int)($existing['enabled'] ?? 0),
        ] + $actionMetadata;
    }

    if (!$execute) {
        return [
            'action' => 'would_insert',
            'data_source_id' => null,
            'platform' => (string)$spec['platform'],
            'system_hotel_id' => (int)$spec['system_hotel_id'],
            'status' => 'waiting_config',
            'last_sync_status' => 'waiting_config',
            'enabled' => 1,
        ] + $actionMetadata;
    }

    $data['created_by'] = 1;
    $data['create_time'] = $now;
    $data = filter_platform_data_source_columns($data);
    $id = (int)Db::name('platform_data_sources')->insertGetId($data);
    return [
        'action' => 'inserted',
        'data_source_id' => $id,
        'platform' => (string)$spec['platform'],
        'system_hotel_id' => (int)$spec['system_hotel_id'],
        'status' => 'waiting_config',
        'last_sync_status' => 'waiting_config',
        'enabled' => 1,
    ] + $actionMetadata;
}

$options = parse_options($argv);
$platforms = selected_platforms((string)$options['platform']);
$targetDateDataIds = target_hotel_ids_by_platform((string)$options['date'], $platforms);
$targetIds = registration_hotel_scope_rows(
    $platforms,
    is_int($options['system-hotel-id']) ? $options['system-hotel-id'] : null
);
$execute = (bool)$options['execute'];

$summary = [
    'date' => (string)$options['date'],
    'execute' => $execute,
    'system_hotel_id' => $options['system-hotel-id'],
    'scope' => 'ota_channel_only',
    'status_policy' => 'registered sources stay waiting_config and ready_for_session_probe; historical login metadata never authorizes sync; active Profile binding, same-source current-session proof, platform identity, target-date rows, and verifier readiness are required',
    'platforms' => [],
];

foreach ($platforms as $platform) {
    $hotelIds = $targetIds[$platform] ?? [];
    $targetScopeState = registration_target_scope_state($hotelIds);
    $profileResolution = profile_contexts_for_hotels($platform, $hotelIds);
    $configs = $profileResolution['contexts'];
    $blockers = $profileResolution['blockers'];
    $platformSummary = [
        'target_hotel_ids' => $hotelIds,
        'target_date_data_hotel_ids' => $targetDateDataIds[$platform] ?? [],
        'target_scope_status' => $targetScopeState['status'],
        'target_scope_reason' => $targetScopeState['reason'],
        'matched_config_count' => count($configs),
        'matched_profile_metadata_count' => count($configs),
        'actions' => [],
        'missing_config_hotel_ids' => [],
        'migration_required_hotel_ids' => [],
        'blocked_hotel_ids' => [],
        'blockers' => [],
        'profile_source_policy' => 'active_ota_profile_binding_plus_same_hotel_profile_dir_and_identity; historical_login_metadata_reference_only; no_legacy_config_secret_or_vault_payload_read',
    ];

    if ($targetScopeState['status'] === 'incomplete') {
        $platformSummary['blockers'][] = [
            'system_hotel_id' => null,
            'status' => 'incomplete',
            'reason' => 'no_target_hotel_scope',
        ];
    }

    foreach ($hotelIds as $hotelId) {
        if (!isset($configs[$hotelId])) {
            $platformSummary['missing_config_hotel_ids'][] = $hotelId;
            $blocker = is_array($blockers[$hotelId] ?? null)
                ? $blockers[$hotelId]
                : ['status' => 'migration_required', 'reason' => 'browser_profile_metadata_missing'];
            $status = (string)($blocker['status'] ?? 'blocked');
            if ($status === 'migration_required') {
                $platformSummary['migration_required_hotel_ids'][] = $hotelId;
            } else {
                $platformSummary['blocked_hotel_ids'][] = $hotelId;
            }
            $platformSummary['blockers'][] = [
                'system_hotel_id' => $hotelId,
                'status' => $status,
                'reason' => (string)($blocker['reason'] ?? 'unknown'),
            ];
            continue;
        }
        $spec = build_source_spec($platform, $configs[$hotelId], (string)$options['date']);
        $existing = find_existing_source($spec);
        $action = persist_source($spec, $existing, $execute);
        $platformSummary['actions'][] = $action;
        if (($action['action'] ?? '') === 'blocked_disabled_managed_source') {
            $platformSummary['blocked_hotel_ids'][] = $hotelId;
            $platformSummary['blockers'][] = [
                'system_hotel_id' => $hotelId,
                'status' => 'blocked',
                'reason' => (string)($action['reason'] ?? 'managed_source_disabled_requires_explicit_operator_action'),
            ];
        }
    }

    $platformSummary['status'] = $targetScopeState['status'] === 'incomplete'
        ? 'incomplete'
        : ($platformSummary['blocked_hotel_ids'] !== []
            ? 'blocked'
            : ($platformSummary['migration_required_hotel_ids'] !== [] ? 'migration_required' : 'ready'));

    $summary['platforms'][$platform] = $platformSummary;
}

$platformStatuses = array_values(array_map(
    static fn(array $platformSummary): string => (string)($platformSummary['status'] ?? 'blocked'),
    $summary['platforms']
));
$summary['status'] = in_array('blocked', $platformStatuses, true)
    ? 'blocked'
    : (in_array('migration_required', $platformStatuses, true)
        ? 'migration_required'
        : (in_array('incomplete', $platformStatuses, true) ? 'incomplete' : 'ready'));

echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
