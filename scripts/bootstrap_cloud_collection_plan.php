#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\service\HotelCollectionBindingReceiptService;
use app\service\HotelCollectionPlanService;
use think\App;
use think\facade\Db;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
(new App($root))->initialize();

set_exception_handler(static function (Throwable $error): never {
    bootstrapFail(bootstrapSafeCode($error->getMessage()) ?: 'collection_plan_bootstrap_failed');
});

$options = getopt('', [
    'tenant-id:',
    'hotel-id:',
    'actor-user-id:',
    'ctrip-source-id:',
    'meituan-source-id:',
    'pms-provider:',
    'execute',
]);
$tenantId = bootstrapPositiveInt($options['tenant-id'] ?? null, 'tenant_id_invalid');
$hotelId = bootstrapPositiveInt($options['hotel-id'] ?? null, 'hotel_id_invalid');
$actorUserId = bootstrapPositiveInt($options['actor-user-id'] ?? null, 'actor_user_id_invalid');
$ctripSourceId = bootstrapPositiveInt($options['ctrip-source-id'] ?? null, 'ctrip_source_id_invalid');
$meituanSourceId = bootstrapPositiveInt($options['meituan-source-id'] ?? null, 'meituan_source_id_invalid');
$pmsProvider = bootstrapSafeCode((string)($options['pms-provider'] ?? ''));
if (!in_array($pmsProvider, ['dingdandao_pms', 'meituan_cloud_pms'], true)) {
    bootstrapFail('pms_provider_invalid');
}
$execute = array_key_exists('execute', $options);
$businessDate = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d');

$hotel = Db::name('hotels')
    ->where('id', $hotelId)
    ->where('tenant_id', $tenantId)
    ->field('id,tenant_id,name,status')
    ->find();
if (!is_array($hotel) || (int)($hotel['status'] ?? 0) !== 1) {
    bootstrapFail('hotel_scope_invalid');
}

$binding = (new HotelCollectionBindingReceiptService())->receipt(
    $hotel,
    $actorUserId,
    $businessDate,
    ['ctrip' => $ctripSourceId, 'meituan' => $meituanSourceId]
);
$pms = is_array($binding['bindings']['pms'] ?? null) ? $binding['bindings']['pms'] : [];
$ready = strtolower(trim((string)($binding['status'] ?? 'blocked'))) === 'ready'
    && strtolower(trim((string)($pms['provider'] ?? ''))) === $pmsProvider
    && ($binding['binding_ready'] ?? false) === true
    && preg_match('/^[a-f0-9]{64}$/D', (string)($binding['binding_digest'] ?? '')) === 1;
if (!$ready) {
    bootstrapFail('collection_plan_binding_not_ready');
}

if (!$execute) {
    bootstrapReply([
        'status' => 'ready_to_activate',
        'mode' => 'dry_run',
        'tenant_id' => $tenantId,
        'system_hotel_id' => $hotelId,
        'business_date' => $businessDate,
        'binding_receipt_verified' => true,
        'collection_started' => false,
        'message_sent' => false,
        'sensitive_values_exposed' => false,
    ]);
}

$plan = (new HotelCollectionPlanService())->save($hotel, $actorUserId, [
    'business_date' => $businessDate,
    'business_date_policy' => 'same_day_realtime',
    'timezone' => 'Asia/Shanghai',
    'schedule_time' => '00:30',
    'retry_interval_minutes' => 30,
    'max_attempts' => 1,
    'activate' => true,
    'sources' => [
        'ctrip' => ['data_source_id' => $ctripSourceId],
        'meituan' => ['data_source_id' => $meituanSourceId],
        'pms' => ['provider' => $pmsProvider],
    ],
]);
if (($plan['save_verified'] ?? false) !== true
    || ($plan['readback_verified'] ?? false) !== true
    || ($plan['execution_authorized'] ?? false) !== true
    || strtolower(trim((string)($plan['plan_status'] ?? ''))) !== 'active'
) {
    bootstrapFail('collection_plan_activation_readback_failed');
}

bootstrapReply([
    'status' => 'activated_and_readback_verified',
    'mode' => 'execute',
    'tenant_id' => $tenantId,
    'system_hotel_id' => $hotelId,
    'business_date' => $businessDate,
    'plan_id' => (int)($plan['id'] ?? 0) ?: null,
    'plan_version' => (int)($plan['plan_version'] ?? 0),
    'readback_verified' => true,
    'execution_authorized' => true,
    'collection_started' => false,
    'message_sent' => false,
    'sensitive_values_exposed' => false,
]);

/** @param array<string,mixed> $payload */
function bootstrapReply(array $payload): never
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        . PHP_EOL;
    exit(0);
}

function bootstrapFail(string $reason): never
{
    echo json_encode([
        'status' => 'blocked',
        'reason' => bootstrapSafeCode($reason) ?: 'collection_plan_bootstrap_failed',
        'collection_started' => false,
        'message_sent' => false,
        'sensitive_values_exposed' => false,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}

function bootstrapPositiveInt(mixed $value, string $reason): int
{
    $parsed = filter_var($value, FILTER_VALIDATE_INT);
    if (!is_int($parsed) || $parsed <= 0) {
        bootstrapFail($reason);
    }
    return $parsed;
}

function bootstrapSafeCode(string $value): string
{
    $value = trim((string)preg_replace('/[^a-zA-Z0-9_-]+/', '_', strtolower($value)), '_');
    return substr($value, 0, 120);
}
