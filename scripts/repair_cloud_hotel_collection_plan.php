#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\service\HotelCollectionPlanService;
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
$tenantId = 0;
$actorUserId = 0;
$businessDate = '';
$mode = 'inspect';
$confirmation = '';
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--hotel-id=([1-9][0-9]*)$/D', (string)$argument, $matches) === 1) {
        $hotelId = (int)$matches[1];
        continue;
    }
    if (preg_match('/^--tenant-id=([1-9][0-9]*)$/D', (string)$argument, $matches) === 1) {
        $tenantId = (int)$matches[1];
        continue;
    }
    if (preg_match('/^--actor-user-id=([1-9][0-9]*)$/D', (string)$argument, $matches) === 1) {
        $actorUserId = (int)$matches[1];
        continue;
    }
    if (preg_match('/^--business-date=(\d{4}-\d{2}-\d{2})$/D', (string)$argument, $matches) === 1) {
        $businessDate = $matches[1];
        continue;
    }
    if (preg_match('/^--mode=(inspect|reissue)$/D', (string)$argument, $matches) === 1) {
        $mode = $matches[1];
        continue;
    }
    if (str_starts_with((string)$argument, '--confirm=')) {
        $confirmation = trim(substr((string)$argument, strlen('--confirm=')));
        continue;
    }
    fwrite(STDERR, "usage: php scripts/repair_cloud_hotel_collection_plan.php --hotel-id=<id> --tenant-id=<id> --actor-user-id=<id> --business-date=YYYY-MM-DD [--mode=inspect|reissue] [--confirm=REISSUE_COLLECTION_PLAN]\n");
    exit(2);
}
if ($hotelId <= 0 || $tenantId <= 0 || $actorUserId <= 0 || $businessDate === '') {
    fwrite(STDERR, "complete_collection_plan_scope_required\n");
    exit(2);
}
if ($mode === 'reissue' && $confirmation !== 'REISSUE_COLLECTION_PLAN') {
    fwrite(STDERR, "explicit_reissue_confirmation_required\n");
    exit(2);
}

require $autoload;
(new App($appDir))->initialize();

$hotel = Db::name('hotels')
    ->field('id,tenant_id,name,status')
    ->where('id', $hotelId)
    ->where('tenant_id', $tenantId)
    ->find();
if (!is_array($hotel) || (int)($hotel['status'] ?? 0) !== 1) {
    throw new RuntimeException('active_hotel_scope_not_found');
}
$row = Db::name('hotel_collection_plans')
    ->where('tenant_id', $tenantId)
    ->where('system_hotel_id', $hotelId)
    ->where('active_slot', 1)
    ->find();
if (!is_array($row)) {
    throw new RuntimeException('active_collection_plan_not_found');
}
$sourcePlan = json_decode((string)($row['source_plan_json'] ?? ''), true);
if (!is_array($sourcePlan)) {
    throw new RuntimeException('collection_plan_source_json_invalid');
}
$scope = [
    'ctrip_source_id' => (int)($sourcePlan['ctrip']['data_source_id'] ?? 0),
    'meituan_source_id' => (int)($sourcePlan['meituan']['data_source_id'] ?? 0),
    'pms_provider' => strtolower(trim((string)($sourcePlan['pms']['provider'] ?? ''))),
];
if ($scope['ctrip_source_id'] <= 0 || $scope['meituan_source_id'] <= 0 || $scope['pms_provider'] === '') {
    throw new RuntimeException('collection_plan_source_scope_invalid');
}

$service = new HotelCollectionPlanService();
$before = $service->read($hotel, $actorUserId, $businessDate);
$safe = static function (array $plan) use ($scope): array {
    return [
        'status' => (string)($plan['status'] ?? ''),
        'id' => (int)($plan['id'] ?? 0),
        'tenant_id' => (int)($plan['tenant_id'] ?? 0),
        'system_hotel_id' => (int)($plan['system_hotel_id'] ?? 0),
        'plan_version' => (int)($plan['plan_version'] ?? 0),
        'plan_status' => (string)($plan['plan_status'] ?? ''),
        'stored_validation_status' => (string)($plan['stored_validation_status'] ?? ''),
        'current_binding_status' => (string)($plan['current_binding_status'] ?? ''),
        'readback_verified' => ($plan['readback_verified'] ?? false) === true,
        'binding_digest_matches' => ($plan['binding_digest_matches'] ?? false) === true,
        'execution_authorized' => ($plan['execution_authorized'] ?? false) === true,
        'source_scope' => $scope,
        'failure_reasons' => array_map(
            static fn(array $reason): array => [
                'code' => (string)($reason['code'] ?? ''),
                'platform' => (string)($reason['platform'] ?? ''),
                'message' => (string)($reason['message'] ?? ''),
            ],
            array_values(array_filter((array)($plan['failure_reasons'] ?? []), 'is_array'))
        ),
        'sensitive_values_exposed' => false,
    ];
};

if ($mode === 'inspect') {
    echo json_encode([
        'status' => 'ok',
        'mode' => 'inspect',
        'inspected_at' => date(DATE_ATOM),
        'plan' => $safe($before),
        'database_write_performed' => false,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$saved = $service->save($hotel, $actorUserId, [
    'business_date' => $businessDate,
    'sources' => [
        'ctrip' => ['data_source_id' => $scope['ctrip_source_id']],
        'meituan' => ['data_source_id' => $scope['meituan_source_id']],
        'pms' => ['provider' => $scope['pms_provider']],
    ],
    'activate' => true,
    'business_date_policy' => (string)($row['business_date_policy'] ?? ''),
    'timezone' => (string)($row['timezone'] ?? ''),
    'schedule_time' => (string)($row['schedule_time'] ?? ''),
    'retry_interval_minutes' => (int)($row['retry_interval_minutes'] ?? 0),
    'max_attempts' => (int)($row['max_attempts'] ?? 0),
]);
$after = $service->read($hotel, $actorUserId, $businessDate);
$safeAfter = $safe($after);
if (($saved['save_verified'] ?? false) !== true
    || ($safeAfter['readback_verified'] ?? false) !== true
    || ($safeAfter['binding_digest_matches'] ?? false) !== true
    || ($safeAfter['execution_authorized'] ?? false) !== true
) {
    throw new RuntimeException('reissued_collection_plan_readback_failed');
}

echo json_encode([
    'status' => 'reissued_and_readback_verified',
    'mode' => 'reissue',
    'reissued_at' => date(DATE_ATOM),
    'before' => $safe($before),
    'after' => $safeAfter,
    'database_write_performed' => true,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
