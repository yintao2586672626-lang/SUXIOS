<?php
declare(strict_types=1);

use app\model\User;
use app\service\MeituanTemporalService;
use think\App;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt('', ['hotel-id:', 'user-id:', 'as-of-date::']);
$hotelId = (int)($options['hotel-id'] ?? 0);
$userId = (int)($options['user-id'] ?? 0);
$timezone = new \DateTimeZone('Asia/Shanghai');
$asOfDate = trim((string)($options['as-of-date'] ?? ''));
if ($asOfDate === '') {
    $asOfDate = (new \DateTimeImmutable('now', $timezone))->format('Y-m-d');
}
$parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $asOfDate, $timezone);

if ($hotelId <= 0 || $userId <= 0 || !$parsedDate || $parsedDate->format('Y-m-d') !== $asOfDate) {
    fwrite(STDERR, "Required: --hotel-id=<id> --user-id=<id> [--as-of-date=YYYY-MM-DD]\n");
    exit(2);
}

$root = dirname(__DIR__);
$app = new App($root);
$app->initialize();

$user = User::where('id', $userId)->where('status', 1)->find();
if (!$user
    || !$user->hasHotelPermission($hotelId, 'can_view_online_data')
    || !$user->hasHotelPermission($hotelId, 'can_fetch_online_data')
) {
    echo json_encode([
        'status' => 'blocked',
        'reason_code' => 'scheduled_actor_hotel_permission_missing',
        'system_hotel_id' => $hotelId,
        'actor_user_id' => $userId,
        'as_of_date' => $asOfDate,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
    exit(2);
}

$service = new MeituanTemporalService();
$refresh = $service->refresh($user, $hotelId, $asOfDate);
$summary = $service->summary($user, $hotelId, $asOfDate);
$segmentStatuses = [
    'today' => (string)($summary['today']['status'] ?? 'missing'),
    'yesterday' => (string)($summary['yesterday']['status'] ?? 'missing'),
    'future' => (string)($summary['future']['status'] ?? 'missing'),
];
$deliveryGateReady = ($summary['source_state']['status'] ?? '') === 'ready'
    && $segmentStatuses['today'] === 'ready'
    && $segmentStatuses['yesterday'] === 'ready'
    && $segmentStatuses['future'] === 'ready';

$result = [
    'status' => (string)($refresh['status'] ?? 'blocked'),
    'reason_code' => (string)($refresh['reason_code'] ?? 'meituan_temporal_refresh_failed'),
    'system_hotel_id' => $hotelId,
    'actor_user_id' => $userId,
    'as_of_date' => $asOfDate,
    'data_scope' => 'ota_channel',
    'source_state' => $summary['source_state'] ?? [],
    'segments' => $segmentStatuses,
    'tasks' => $refresh['tasks'] ?? [],
    'latest_today_capture' => $summary['today']['captured_at'] ?? null,
    'delivery_gate' => [
        'ready' => $deliveryGateReady,
        'reason_code' => $deliveryGateReady
            ? 'all_meituan_temporal_segments_ready'
            : 'meituan_temporal_segments_not_ready',
        'external_delivery_executed' => false,
        'timer_modified' => false,
    ],
];

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
exit(($refresh['status'] ?? '') === 'blocked' ? 2 : 0);
