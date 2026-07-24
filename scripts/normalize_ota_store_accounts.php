<?php
declare(strict_types=1);

use app\service\BrowserProfileCaptureRequestService;
use app\service\HotelDataMergeService;
use think\App;
use think\facade\Db;

require __DIR__ . '/../vendor/autoload.php';

$app = new App();
$app->initialize();

const STORE_ACCOUNT_MERGE_CONFIRMATION = 'MERGE 113 -> 125';

$execute = in_array('--execute', $argv, true);
$now = date('Y-m-d H:i:s');
$plan = [
    'mode' => $execute ? 'execute' : 'dry_run',
    'hotel_merge' => ['source' => 113, 'target' => 125, 'confirmation' => STORE_ACCOUNT_MERGE_CONFIRMATION],
    'disable_sources' => [6, 7, 14],
    'profile_consolidation' => ['hotel' => 107, 'platform' => 'ctrip', 'primary_source' => 23, 'secondary_source' => 24],
];

/** @return array<string,mixed> */
function source_row(int $id): array
{
    $row = Db::name('platform_data_sources')->where('id', $id)->find();
    if (!is_array($row)) {
        throw new RuntimeException('Expected platform data source is missing: ' . $id);
    }
    return $row;
}

/** @param array<string,mixed> $source */
function profile_key(array $source): string
{
    $config = json_decode((string)($source['config_json'] ?? ''), true);
    if (!is_array($config)) {
        return '';
    }
    foreach (['profile_id', 'profileId', 'browser_profile_id', 'browserProfileId'] as $key) {
        if (is_scalar($config[$key] ?? null) && trim((string)$config[$key]) !== '') {
            return trim((string)$config[$key]);
        }
    }
    return '';
}

/** @param array<string,mixed> $source */
function replace_profile_key(array $source, string $primaryProfileKey): string
{
    $config = json_decode((string)($source['config_json'] ?? ''), true);
    if (!is_array($config)) {
        throw new RuntimeException('Secondary Profile source config is invalid.');
    }
    $config['profile_id'] = $primaryProfileKey;
    foreach (['profileId', 'browser_profile_id', 'browserProfileId'] as $key) {
        if (array_key_exists($key, $config)) {
            $config[$key] = $primaryProfileKey;
        }
    }
    return json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

/** @param array<string,mixed> $row */
function assert_source_scope(array $row, int $hotelId, string $platform = ''): void
{
    if ((int)($row['system_hotel_id'] ?? 0) !== $hotelId) {
        throw new RuntimeException('Platform source scope changed; normalization stopped: ' . (int)($row['id'] ?? 0));
    }
    if ($platform !== '' && strtolower((string)($row['platform'] ?? '')) !== $platform) {
        throw new RuntimeException('Platform source channel changed; normalization stopped: ' . (int)($row['id'] ?? 0));
    }
}

$sourceHotel = Db::name('hotels')->field('id,name,status,tenant_id')->where('id', 113)->find();
$targetHotel = Db::name('hotels')->field('id,name,status,tenant_id')->where('id', 125)->find();
if (!is_array($sourceHotel) || !is_array($targetHotel) || trim((string)$sourceHotel['name']) === '' || (string)$sourceHotel['name'] !== (string)$targetHotel['name']) {
    throw new RuntimeException('Duplicate hotel identity changed; normalization stopped.');
}

$primarySource = source_row(23);
$secondarySource = source_row(24);
assert_source_scope($primarySource, 107, 'ctrip');
assert_source_scope($secondarySource, 107, 'ctrip');
$staleMergedSource = source_row(14);
assert_source_scope($staleMergedSource, 80, 'ctrip');
foreach ([6, 7] as $orphanSourceId) {
    $orphanSource = source_row($orphanSourceId);
    if ((int)Db::name('hotels')->where('id', (int)$orphanSource['system_hotel_id'])->count() > 0) {
        throw new RuntimeException('Expected orphan source now belongs to a real hotel; normalization stopped: ' . $orphanSourceId);
    }
}
$primaryProfileKey = profile_key($primarySource);
$secondaryProfileKey = profile_key($secondarySource);
if ($primaryProfileKey === '' || $secondaryProfileKey === '') {
    throw new RuntimeException('Profile identity is missing; normalization stopped.');
}

$primaryProfileHash = hash('sha256', BrowserProfileCaptureRequestService::safeFilePart($primaryProfileKey));
$oldSecondaryHash = hash('sha256', BrowserProfileCaptureRequestService::safeFilePart($secondaryProfileKey));
$alreadyConsolidated = hash_equals($primaryProfileHash, $oldSecondaryHash);
$hotel107TenantId = (int)Db::name('hotels')->where('id', 107)->value('tenant_id');
$secondaryBinding = null;
if (!$alreadyConsolidated) {
    $secondaryBinding = Db::name('ota_profile_bindings')
        ->where('tenant_id', $hotel107TenantId)
        ->where('system_hotel_id', 107)
        ->where('platform', 'ctrip')
        ->where('profile_key_hash', $oldSecondaryHash)
        ->find();
}

$mergeService = new HotelDataMergeService();
$preview = $mergeService->preview(113, 125);
$plan['hotel_merge']['source_name'] = (string)$sourceHotel['name'];
$plan['hotel_merge']['source_rows'] = (int)($preview['total_source_rows'] ?? 0);
$plan['hotel_merge']['can_execute'] = (bool)($preview['can_execute'] ?? false);
$plan['profile_consolidation']['secondary_binding_id'] = is_array($secondaryBinding) ? (int)$secondaryBinding['id'] : null;
$plan['profile_consolidation']['already_consolidated'] = $alreadyConsolidated;

if (!$execute) {
    echo json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
}

$result = Db::transaction(function () use ($mergeService, $preview, $sourceHotel, $primarySource, $secondarySource, $primaryProfileKey, $secondaryBinding, $now): array {
    $changes = [];

    if ((int)$sourceHotel['status'] === 1) {
        if (!empty($preview['can_execute'])) {
            $mergeResult = $mergeService->execute(113, 125, STORE_ACCOUNT_MERGE_CONFIRMATION, true);
            $changes['hotel_merge'] = [
                'updated_rows' => (int)($mergeResult['updated_total'] ?? 0),
                'source_deactivated' => (bool)($mergeResult['source_deactivated'] ?? false),
            ];
        } elseif ((int)($preview['total_source_rows'] ?? 0) === 0 && (int)($preview['blocking_conflict_total'] ?? 0) === 0) {
            Db::name('hotels')->where('id', 113)->update(['status' => 0, 'update_time' => $now]);
            $changes['hotel_merge'] = ['updated_rows' => 0, 'source_deactivated' => true];
        } else {
            throw new RuntimeException('Hotel merge has blocking conflicts; no changes were applied.');
        }
    } else {
        $changes['hotel_merge'] = ['updated_rows' => 0, 'source_deactivated' => true, 'already_applied' => true];
    }

    $disabled = [];
    foreach ([6, 7, 14] as $sourceId) {
        $row = source_row($sourceId);
        if ((int)($row['enabled'] ?? 0) === 1 || strtolower((string)($row['status'] ?? '')) !== 'disabled') {
            Db::name('platform_data_sources')->where('id', $sourceId)->update([
                'enabled' => 0,
                'status' => 'disabled',
                'last_error' => $sourceId === 14 ? 'disabled_by_store_profile_scope_normalization' : 'disabled_orphan_store_source',
                'update_time' => $now,
            ]);
            $disabled[] = $sourceId;
        }
    }
    $changes['disabled_sources'] = $disabled;

    $primaryHash = hash('sha256', BrowserProfileCaptureRequestService::safeFilePart($primaryProfileKey));
    $secondaryHash = hash('sha256', BrowserProfileCaptureRequestService::safeFilePart(profile_key($secondarySource)));
    if (!hash_equals($primaryHash, $secondaryHash)) {
        Db::name('platform_data_sources')->where('id', 24)->update([
            'config_json' => replace_profile_key($secondarySource, $primaryProfileKey),
            'last_error' => null,
            'update_time' => $now,
        ]);
        $changes['profile_source_updated'] = 24;
    }
    if (is_array($secondaryBinding) && strtolower((string)($secondaryBinding['binding_status'] ?? '')) === 'active') {
        Db::name('ota_profile_bindings')->where('id', (int)$secondaryBinding['id'])->update([
            'binding_status' => 'revoked',
            'revoked_by' => null,
            'update_time' => $now,
        ]);
        $changes['revoked_binding_id'] = (int)$secondaryBinding['id'];
    }

    $source14Profile = profile_key(source_row(14));
    if ($source14Profile !== '') {
        $source14Hash = hash('sha256', BrowserProfileCaptureRequestService::safeFilePart($source14Profile));
        $staleBindings = Db::name('ota_profile_bindings')
            ->where('platform', 'ctrip')
            ->where('profile_key_hash', $source14Hash)
            ->where('binding_status', 'active')
            ->column('id');
        if ($staleBindings !== []) {
            Db::name('ota_profile_bindings')->whereIn('id', $staleBindings)->update([
                'binding_status' => 'revoked',
                'revoked_by' => null,
                'update_time' => $now,
            ]);
        }
        $changes['revoked_stale_binding_ids'] = array_map('intval', $staleBindings);
    }

    return $changes;
});

$remainingDuplicateBindings = Db::query(
    "SELECT tenant_id, system_hotel_id, platform, COUNT(*) AS duplicate_count
     FROM ota_profile_bindings
     WHERE binding_status = 'active'
     GROUP BY tenant_id, system_hotel_id, platform
     HAVING COUNT(*) > 1"
);
$remainingDuplicateSources = Db::query(
    "SELECT tenant_id, system_hotel_id, platform, data_type, ingestion_method, COUNT(*) AS duplicate_count
     FROM platform_data_sources
     WHERE enabled = 1 AND status <> 'disabled'
     GROUP BY tenant_id, system_hotel_id, platform, data_type, ingestion_method
     HAVING COUNT(*) > 1"
);

echo json_encode([
    'mode' => 'execute',
    'changes' => $result,
    'verification' => [
        'duplicate_active_profile_bindings' => count($remainingDuplicateBindings),
        'duplicate_active_source_keys' => count($remainingDuplicateSources),
        'source_hotel_active' => (int)Db::name('hotels')->where('id', 113)->value('status'),
        'target_hotel_active' => (int)Db::name('hotels')->where('id', 125)->value('status'),
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
