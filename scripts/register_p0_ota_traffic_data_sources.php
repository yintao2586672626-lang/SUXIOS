<?php
declare(strict_types=1);

use think\App;
use think\facade\Db;

require __DIR__ . '/../vendor/autoload.php';

$app = new App();
$app->initialize();

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

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$options['date'])) {
        throw new InvalidArgumentException('Invalid --date, expected YYYY-MM-DD.');
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

    foreach ($platforms as $platform) {
        if (!in_array($platform, ['ctrip', 'meituan'], true)) {
            throw new InvalidArgumentException('Unsupported --platform, expected ctrip and/or meituan.');
        }
    }

    return $platforms;
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

/**
 * @return array<string, array<string, mixed>>
 */
function load_config_list(string $platform): array
{
    $table = $platform === 'ctrip' ? 'system_configs' : 'system_config';
    $key = $platform === 'ctrip' ? 'ctrip_config_list' : 'meituan_config_list';
    $value = Db::name($table)->where('config_key', $key)->value('config_value');
    $decoded = json_decode((string)$value, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * @param array<string, mixed> $row
 */
function config_system_hotel_id(array $row): int
{
    $systemHotelId = (int)($row['system_hotel_id'] ?? 0);
    if ($systemHotelId > 0) {
        return $systemHotelId;
    }
    return (int)($row['hotel_id'] ?? 0);
}

/**
 * @param array<string, mixed> $row
 */
function config_sort_time(array $row): string
{
    foreach (['update_time', 'updated_at', 'created_at', 'create_time'] as $key) {
        $value = trim((string)($row[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

/**
 * @param array<int, int> $hotelIds
 * @return array<int, array<string, mixed>>
 */
function latest_configs_for_hotels(string $platform, array $hotelIds): array
{
    $targetIds = array_flip($hotelIds);
    $selected = [];

    foreach (load_config_list($platform) as $configKey => $row) {
        if (!is_array($row)) {
            continue;
        }
        $systemHotelId = config_system_hotel_id($row);
        if ($systemHotelId <= 0 || !isset($targetIds[$systemHotelId])) {
            continue;
        }

        $candidate = [
            'config_key' => (string)$configKey,
            'system_hotel_id' => $systemHotelId,
            'config' => $row,
            'sort_time' => config_sort_time($row),
        ];
        $current = $selected[$systemHotelId] ?? null;
        if ($current === null || strcmp((string)$candidate['sort_time'], (string)$current['sort_time']) >= 0) {
            $selected[$systemHotelId] = $candidate;
        }
    }

    ksort($selected);
    return $selected;
}

/**
 * @param array<string, mixed> $row
 * @param array<int, string> $keys
 */
function first_string(array $row, array $keys): string
{
    foreach ($keys as $key) {
        $value = trim((string)($row[$key] ?? ''));
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
 * @return array<string, mixed>|null
 */
function find_verified_profile_login_source(string $platform, int $systemHotelId): ?array
{
    if ($systemHotelId <= 0) {
        return null;
    }

    $rows = Db::name('platform_data_sources')
        ->field('id,platform,data_type,ingestion_method,system_hotel_id,status,enabled,last_sync_status,config_json,update_time')
        ->where('platform', $platform)
        ->where('ingestion_method', 'browser_profile')
        ->where('system_hotel_id', $systemHotelId)
        ->where('enabled', 1)
        ->order('id', 'desc')
        ->select()
        ->toArray();

    $best = null;
    $bestScore = -1;
    foreach ($rows as $row) {
        $status = strtolower(trim((string)($row['status'] ?? '')));
        if ($status === 'disabled') {
            continue;
        }
        $config = json_decode((string)($row['config_json'] ?? ''), true);
        $config = is_array($config) ? $config : [];
        if (!profile_login_state_verified_config($config)) {
            continue;
        }

        $score = 0;
        if ((string)($row['data_type'] ?? '') !== 'traffic') {
            $score += 100;
        }
        if ($status === 'success') {
            $score += 20;
        }
        if (strtolower(trim((string)($row['last_sync_status'] ?? ''))) === 'success') {
            $score += 10;
        }
        $score += min(9, (int)($row['id'] ?? 0));

        if ($score > $bestScore) {
            $best = $row;
            $best['config'] = $config;
            $bestScore = $score;
        }
    }

    return $best;
}

/**
 * @param array<string, mixed> $config
 * @param array<string, mixed>|null $verifiedSource
 * @return array<string, mixed>
 */
function apply_profile_login_inheritance(array $config, ?array $verifiedSource): array
{
    if ($verifiedSource === null) {
        return $config;
    }

    $config['manual_login_state_verified'] = true;
    $config['login_state_verified'] = true;
    $config['profile_login_verified'] = true;
    $config['login_verification_status'] = 'verified_from_existing_browser_profile_source';
    $config['profile_login_inherited_from_data_source_id'] = (int)($verifiedSource['id'] ?? 0);
    $config['profile_login_inherited_from_data_type'] = (string)($verifiedSource['data_type'] ?? '');
    $config['profile_login_inheritance_policy'] = 'same_platform_same_system_hotel_browser_profile_verified_metadata_only_no_secret_reuse';
    $config['profile_auth_evidence_policy'] = 'Profile directory presence is not login-state evidence; verified Profile metadata may be inherited only from same platform/system_hotel_id browser_profile source.';

    return $config;
}

/**
 * @param array<string, mixed> $selected
 * @return array<string, mixed>
 */
function build_source_spec(string $platform, array $selected, string $targetDate): array
{
    $row = is_array($selected['config'] ?? null) ? $selected['config'] : [];
    $systemHotelId = (int)($selected['system_hotel_id'] ?? 0);
    $hotelName = first_string($row, ['hotel_name', 'name', 'poi_name', 'poiName']);
    $configKey = (string)($selected['config_key'] ?? '');
    $sortTime = (string)($selected['sort_time'] ?? '');
    $baseConfig = [
        'capture_sections' => 'traffic',
        'source_resource' => 'flowData',
        'registered_by' => P0_TRAFFIC_SOURCE_MARKER,
        'registered_from' => $platform === 'ctrip' ? 'ctrip_config_list' : 'meituan_config_list',
        'source_config_key' => $configKey,
        'source_config_updated_at' => $sortTime,
        'target_date' => $targetDate,
        'source_scope' => 'ota_channel_only',
        'manual_login_state_verified' => false,
        'login_verification_status' => 'not_verified',
        'profile_auth_evidence_policy' => 'Profile directory presence is not login-state evidence.',
    ];
    $baseConfig = apply_profile_login_inheritance(
        $baseConfig,
        find_verified_profile_login_source($platform, $systemHotelId)
    );

    if ($platform === 'ctrip') {
        $profileId = first_string($row, ['profile_id', 'profileId']);
        if ($profileId === '') {
            $profileId = 'system_' . $systemHotelId;
        }
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
    $rows = Db::name('platform_data_sources')
        ->where('platform', (string)$spec['platform'])
        ->where('data_type', 'traffic')
        ->where('ingestion_method', 'browser_profile')
        ->where('system_hotel_id', (int)$spec['system_hotel_id'])
        ->order('id', 'asc')
        ->select()
        ->toArray();

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

/**
 * @param array<string, mixed> $spec
 * @param array<string, mixed>|null $existing
 * @return array<string, mixed>
 */
function persist_source(array $spec, ?array $existing, bool $execute): array
{
    $now = date('Y-m-d H:i:s');
    $loginVerified = profile_login_state_verified_config(is_array($spec['config'] ?? null) ? $spec['config'] : []);
    $lastError = $loginVerified
        ? 'Waiting for target-date traffic source sync/field-fact verifier; manual_login_state_verified inherited from same-hotel browser Profile metadata.'
        : 'Waiting for manual_login_state_verified and target-date traffic rows; Profile directory presence is not treated as login evidence.';
    $actionMetadata = [
        'manual_login_state_verified' => $loginVerified,
        'login_verification_status' => (string)($spec['config']['login_verification_status'] ?? ''),
        'profile_login_inherited_from_data_source_id' => isset($spec['config']['profile_login_inherited_from_data_source_id'])
            ? (int)$spec['config']['profile_login_inherited_from_data_source_id']
            : null,
        'profile_login_inheritance_policy' => (string)($spec['config']['profile_login_inheritance_policy'] ?? ''),
    ];
    $status = 'waiting_config';
    $lastSyncStatus = 'waiting_config';
    if ($existing !== null && (string)($existing['status'] ?? '') !== 'disabled') {
        $existingStatus = trim((string)($existing['status'] ?? ''));
        $existingLastSyncStatus = trim((string)($existing['last_sync_status'] ?? ''));
        if ($existingStatus !== '') {
            $status = $existingStatus;
        }
        if ($existingLastSyncStatus !== '') {
            $lastSyncStatus = $existingLastSyncStatus;
        }
    }
    $data = [
        'tenant_id' => (int)$spec['system_hotel_id'],
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

    if ($existing !== null && (string)($existing['status'] ?? '') !== 'disabled') {
        $existingConfig = json_decode((string)($existing['config_json'] ?? ''), true);
        $existingConfig = is_array($existingConfig) ? $existingConfig : [];
        $isManaged = ($existingConfig['registered_by'] ?? '') === P0_TRAFFIC_SOURCE_MARKER;
        if (!$isManaged) {
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
                'action' => 'would_update',
                'data_source_id' => (int)($existing['id'] ?? 0),
                'platform' => (string)$spec['platform'],
                'system_hotel_id' => (int)$spec['system_hotel_id'],
                'status' => $status,
                'last_sync_status' => $lastSyncStatus,
                'enabled' => 1,
            ] + $actionMetadata;
        }

        Db::name('platform_data_sources')->where('id', (int)$existing['id'])->update($data);
        return [
            'action' => 'updated',
            'data_source_id' => (int)$existing['id'],
            'platform' => (string)$spec['platform'],
            'system_hotel_id' => (int)$spec['system_hotel_id'],
            'status' => $status,
            'last_sync_status' => $lastSyncStatus,
            'enabled' => 1,
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
$targetIds = target_hotel_ids_by_platform((string)$options['date'], $platforms);
$execute = (bool)$options['execute'];

$summary = [
    'date' => (string)$options['date'],
    'execute' => $execute,
    'scope' => 'ota_channel_only',
    'status_policy' => 'registered sources stay waiting_config until target-date traffic rows and verifier readiness exist; manual login may be inherited only from same-hotel verified browser Profile metadata',
    'platforms' => [],
];

foreach ($platforms as $platform) {
    $hotelIds = $targetIds[$platform] ?? [];
    $configs = latest_configs_for_hotels($platform, $hotelIds);
    $platformSummary = [
        'target_hotel_ids' => $hotelIds,
        'matched_config_count' => count($configs),
        'actions' => [],
        'missing_config_hotel_ids' => [],
    ];

    foreach ($hotelIds as $hotelId) {
        if (!isset($configs[$hotelId])) {
            $platformSummary['missing_config_hotel_ids'][] = $hotelId;
            continue;
        }
        $spec = build_source_spec($platform, $configs[$hotelId], (string)$options['date']);
        $existing = find_existing_source($spec);
        $platformSummary['actions'][] = persist_source($spec, $existing, $execute);
    }

    $summary['platforms'][$platform] = $platformSummary;
}

echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
