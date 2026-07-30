#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\service\CloudBrowserProfileService;
use think\App;
use think\facade\Db;

$releaseRoot = realpath(dirname(__DIR__, 2));
if (!is_string($releaseRoot) || $releaseRoot === '') {
    fwrite(STDERR, "release_root_unresolved\n");
    exit(2);
}

require $releaseRoot . '/vendor/autoload.php';
(new App($releaseRoot))->initialize();

$options = getopt('', [
    'hotel-id:',
    'owner-user-id:',
    'profile-id:',
    'require-runtime',
]);
$hotelId = max(0, (int)($options['hotel-id'] ?? 0));
$ownerUserId = max(0, (int)($options['owner-user-id'] ?? 0));
$profileId = trim((string)($options['profile-id'] ?? ''));
$requireRuntime = array_key_exists('require-runtime', $options);
if ($hotelId <= 0
    || $ownerUserId <= 0
    || preg_match('/^cbp_[A-Za-z0-9_-]{16,64}$/D', $profileId) !== 1
) {
    collectionVerifyFail('dingdandao_collection_scope_arguments_invalid');
}

try {
    foreach ([
        'hotels',
        'cloud_browser_profiles',
        'platform_data_sources',
        'online_daily_data',
        'manual_notification_schedule_runs',
        'dingdandao_operating_target_captures',
        'dingdandao_room_fee_capture_details',
        'system_configs',
    ] as $table) {
        Db::query('SELECT 1 FROM `' . $table . '` WHERE 1 = 0');
    }
    Db::query(
        'SELECT `scope_robot_id`, `result_summary_json`'
        . ' FROM `manual_notification_schedule_runs` WHERE 1 = 0'
    );

    $scope = config('single_hotel_operating_digest');
    $scope = is_array($scope) ? $scope : [];
    $hotel = Db::name('hotels')
        ->where('id', $hotelId)
        ->field('id,tenant_id,name,status')
        ->find();
    if (!is_array($hotel)
        || (int)($hotel['tenant_id'] ?? 0) <= 0
        || (int)($hotel['status'] ?? 0) !== 1
        || (int)($scope['hotel_id'] ?? 0) !== $hotelId
        || (int)($scope['tenant_id'] ?? 0) !== (int)$hotel['tenant_id']
        || !hash_equals(
            trim((string)($scope['hotel_name'] ?? '')),
            trim((string)($hotel['name'] ?? ''))
        )
    ) {
        throw new RuntimeException('dingdandao_collection_hotel_scope_invalid');
    }
    $tenantId = (int)$hotel['tenant_id'];
    $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))
        ->format('Y-m-d');
    $profile = (new CloudBrowserProfileService())
        ->validateDingdandaoCollectionProfile(
            $profileId,
            $tenantId,
            $hotelId,
            $ownerUserId,
            $today
        );
    if (($profile['validated'] ?? false) !== true
        || ($profile['access_mode'] ?? '') !== 'read_only'
        || ($profile['source_scope'] ?? '') !== 'today_only'
    ) {
        throw new RuntimeException('dingdandao_collection_profile_not_ready');
    }

    collectionVerifyProviderBinding(
        $tenantId,
        $hotelId,
        trim((string)($scope['pms']['provider_hotel_name'] ?? ''))
    );
    collectionVerifyOtaBindings($tenantId, $hotelId, $scope);
    if ($requireRuntime) {
        collectionVerifyGatewayRuntime();
    }

    echo json_encode([
        'status' => $requireRuntime ? 'runtime_ready' : 'scope_ready',
        'release_root' => $releaseRoot,
        'business_date' => $today,
        'tenant_id' => $tenantId,
        'hotel_id' => $hotelId,
        'hotel_name' => (string)$hotel['name'],
        'profile_reference' => substr($profileId, 0, 12) . '...',
        'profile_ready' => true,
        'access_mode' => 'read_only',
        'source_scope' => 'today_only',
        'dingdandao_binding_ready' => true,
        'ctrip_binding_ready' => true,
        'meituan_binding_ready' => true,
        'gateway_runtime_ready' => $requireRuntime,
        'preview_only' => true,
        'database_write' => false,
        'webhook_read' => false,
        'message_sent' => false,
        'sensitive_values_exposed' => false,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    collectionVerifyFail($exception->getMessage());
}

/** @param array<string,mixed> $scope */
function collectionVerifyOtaBindings(int $tenantId, int $hotelId, array $scope): void
{
    $rows = Db::name('platform_data_sources')
        ->where('tenant_id', $tenantId)
        ->where('system_hotel_id', $hotelId)
        ->whereIn('platform', ['ctrip', 'meituan'])
        ->whereIn('ingestion_method', ['browser_profile', 'profile_browser'])
        ->where('enabled', 1)
        ->field('id,platform,config_json')
        ->select()
        ->toArray();
    foreach ([
        'ctrip' => [
            'expected' => trim((string)(
                $scope['platforms']['ctrip']['platform_hotel_id'] ?? ''
            )),
            'keys' => [
                'platform_hotel_id',
                'platformHotelId',
                'hotel_id',
                'hotelId',
                'ota_hotel_id',
                'otaHotelId',
                'ctrip_hotel_id',
                'ctripHotelId',
                'master_hotel_id',
                'masterHotelId',
                'external_hotel_id',
            ],
        ],
        'meituan' => [
            'expected' => trim((string)(
                $scope['platforms']['meituan']['platform_hotel_id'] ?? ''
            )),
            'keys' => [
                'platform_hotel_id',
                'platformHotelId',
                'store_id',
                'storeId',
                'poi_id',
                'poiId',
                'hotel_id',
                'hotelId',
                'external_hotel_id',
            ],
        ],
    ] as $platform => $definition) {
        if ($definition['expected'] === '') {
            throw new RuntimeException($platform . '_platform_hotel_id_missing');
        }
        $matched = [];
        foreach ($rows as $row) {
            if (!is_array($row)
                || strtolower(trim((string)($row['platform'] ?? ''))) !== $platform
            ) {
                continue;
            }
            $config = json_decode((string)($row['config_json'] ?? ''), true);
            $config = is_array($config) ? $config : [];
            $observed = '';
            foreach ($definition['keys'] as $key) {
                if (!is_scalar($config[$key] ?? null)) {
                    continue;
                }
                $observed = trim((string)$config[$key]);
                if ($observed !== '') {
                    break;
                }
            }
            if ($observed !== '' && hash_equals($definition['expected'], $observed)) {
                $matched[] = (int)($row['id'] ?? 0);
            }
        }
        if (count(array_filter($matched, static fn(int $id): bool => $id > 0)) !== 1) {
            throw new RuntimeException($platform . '_exact_source_binding_invalid');
        }
    }
}

function collectionVerifyProviderBinding(
    int $tenantId,
    int $hotelId,
    string $expectedProviderHotelName
): void {
    if ($expectedProviderHotelName === '') {
        throw new RuntimeException('dingdandao_provider_alias_missing');
    }
    $raw = Db::name('system_configs')
        ->where('config_key', 'dingdandao_hotel_bindings')
        ->value('config_value');
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        throw new RuntimeException('dingdandao_server_binding_missing');
    }
    $rows = is_array($decoded['bindings'] ?? null)
        ? $decoded['bindings']
        : (array_is_list($decoded) ? $decoded : []);
    $targetBindings = [];
    $providerOwners = [];
    foreach ($rows as $row) {
        if (!is_array($row)
            || (int)($row['tenant_id'] ?? 0) !== $tenantId
            || strtolower(trim((string)($row['status'] ?? ''))) !== 'verified'
        ) {
            continue;
        }
        $rowHotelId = (int)($row['hotel_id'] ?? 0);
        $providerHotelId = trim((string)($row['provider_hotel_id'] ?? ''));
        $providerHotelName = trim((string)($row['provider_hotel_name'] ?? ''));
        if ($rowHotelId <= 0 || $providerHotelId === '' || $providerHotelName === '') {
            continue;
        }
        $providerOwners[$providerHotelId][$rowHotelId] = true;
        if ($rowHotelId === $hotelId) {
            $targetBindings[] = [
                'provider_hotel_id' => $providerHotelId,
                'provider_hotel_name' => $providerHotelName,
            ];
        }
    }
    if (count($targetBindings) !== 1
        || !hash_equals(
            $expectedProviderHotelName,
            (string)$targetBindings[0]['provider_hotel_name']
        )
        || count($providerOwners[$targetBindings[0]['provider_hotel_id']] ?? []) !== 1
    ) {
        throw new RuntimeException('dingdandao_server_binding_invalid');
    }
}

function collectionVerifyGatewayRuntime(): void
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 3,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents('http://127.0.0.1:8787/health', false, $context);
    $health = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($health)
        || (string)($health['status'] ?? '') !== 'ok'
        || (string)($health['profile_lease_contract'] ?? '')
            !== 'dingdandao_profile_lease.v1'
        || ($health['read_only_policy_runtime'] ?? null) !== true
    ) {
        throw new RuntimeException('dingdandao_gateway_runtime_unavailable');
    }
}

function collectionVerifyFail(string $reason): never
{
    $reason = preg_replace(
        '/(key|token|secret|cookie|password|authorization|webhook)\s*[=:]\s*[^\s,;]+/iu',
        '$1=<redacted>',
        trim($reason)
    ) ?? 'dingdandao_collection_preflight_failed';
    fwrite(STDERR, mb_strcut($reason, 0, 240, 'UTF-8') . PHP_EOL);
    exit(2);
}
