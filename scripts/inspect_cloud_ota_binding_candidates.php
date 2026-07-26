#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\model\User;
use think\App;
use think\facade\Db;

$configuredAppDir = trim((string)getenv('SUXIOS_APP_DIR'));
$appDir = $configuredAppDir !== '' ? rtrim($configuredAppDir, '/\\') : dirname(__DIR__);
$autoload = $appDir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "app_autoload_missing\n");
    exit(1);
}

$hotelId = 0;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--hotel-id=([1-9][0-9]*)$/D', (string)$argument, $matches) === 1) {
        $hotelId = (int)$matches[1];
        continue;
    }
    fwrite(STDERR, "usage: php scripts/inspect_cloud_ota_binding_candidates.php --hotel-id=<id>\n");
    exit(2);
}
if ($hotelId <= 0) {
    fwrite(STDERR, "hotel_id_required\n");
    exit(2);
}

require $autoload;
(new App($appDir))->initialize();

/**
 * This inspector deliberately projects only authorization and binding metadata.
 * It never selects password, mobile, email, secret_json, config_json, Cookie,
 * webhook, token, or raw OTA response fields.
 *
 * @return array<int,string>
 */
function safeColumns(string $table, array $allowlist): array
{
    $present = [];
    foreach (Db::query('SHOW COLUMNS FROM `' . $table . '`') as $row) {
        $field = trim((string)($row['Field'] ?? $row['field'] ?? ''));
        if ($field !== '') {
            $present[$field] = true;
        }
    }
    return array_values(array_filter(
        $allowlist,
        static fn(string $column): bool => isset($present[$column])
    ));
}

/** @param array<string,mixed> $row */
function projectRow(array $row): array
{
    $projected = [];
    foreach ($row as $key => $value) {
        if ($value instanceof DateTimeInterface) {
            $value = $value->format('Y-m-d H:i:s');
        }
        $projected[(string)$key] = $value;
    }
    return $projected;
}

$hotelColumns = safeColumns('hotels', [
    'id', 'tenant_id', 'name', 'status', 'user_id', 'owner_user_id', 'ota_channel_strategy',
]);
$hotel = Db::name('hotels')
    ->field(implode(',', $hotelColumns))
    ->where('id', $hotelId)
    ->find();
if (!is_array($hotel)) {
    fwrite(STDERR, "hotel_not_found\n");
    exit(3);
}

$permissionColumns = safeColumns('user_hotel_permissions', [
    'id', 'tenant_id', 'user_id', 'hotel_id', 'status', 'can_fetch_online_data', 'expires_at',
]);
$permissions = Db::name('user_hotel_permissions')
    ->field(implode(',', $permissionColumns))
    ->where('hotel_id', $hotelId)
    ->order('id')
    ->select()
    ->toArray();

$sourceColumns = safeColumns('platform_data_sources', [
    'id', 'tenant_id', 'user_id', 'system_hotel_id', 'platform', 'ingestion_method',
    'enabled', 'status', 'last_sync_status', 'last_sync_time', 'update_time',
]);
$sources = Db::name('platform_data_sources')
    ->field(implode(',', $sourceColumns))
    ->where('system_hotel_id', $hotelId)
    ->whereIn('platform', ['ctrip', 'meituan'])
    ->order('id')
    ->select()
    ->toArray();

$tenantId = (int)($hotel['tenant_id'] ?? 0);
$userColumns = safeColumns('users', [
    'id', 'tenant_id', 'username', 'real_name', 'nickname', 'name', 'hotel_id', 'role_id', 'status',
]);
$userRows = Db::name('users')
    ->field(implode(',', $userColumns))
    ->where('status', User::STATUS_ENABLED)
    ->where(static function ($query) use ($tenantId): void {
        $query->where('tenant_id', $tenantId)->whereOr('role_id', 1);
    })
    ->order('id')
    ->select()
    ->toArray();

$users = [];
foreach ($userRows as $row) {
    $user = User::find((int)($row['id'] ?? 0));
    $safe = projectRow($row);
    $safe['is_super_admin'] = $user instanceof User && $user->isSuperAdmin();
    $users[] = $safe;
}

echo json_encode([
    'status' => 'ok',
    'inspected_at' => date(DATE_ATOM),
    'scope' => 'ota_channel_binding_metadata_only',
    'hotel' => projectRow($hotel),
    'users' => $users,
    'permissions' => array_map('projectRow', $permissions),
    'sources' => array_map('projectRow', $sources),
    'sensitive_values_exposed' => false,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
