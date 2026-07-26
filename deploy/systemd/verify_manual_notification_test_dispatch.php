<?php
declare(strict_types=1);

use app\service\ManualNotificationService;
use app\service\ManualNotificationTestTargetService;
use think\App;
use think\facade\Db;

$releaseRoot = realpath(dirname(__DIR__, 2));
if (!is_string($releaseRoot) || $releaseRoot === '') {
    fwrite(STDERR, "release_root_unresolved\n");
    exit(2);
}

require $releaseRoot . '/vendor/autoload.php';
$app = new App($releaseRoot);
$app->initialize();
$options = getopt('', ['hotel-id:', 'robot-id:', 'require-enabled']);
$hotelId = max(0, (int)($options['hotel-id'] ?? 0));
$robotId = max(0, (int)($options['robot-id'] ?? 0));
$requireEnabled = array_key_exists('require-enabled', $options);
if ($hotelId <= 0 || $robotId <= 0) {
    fwrite(STDERR, "--hotel-id and --robot-id are required\n");
    exit(2);
}

try {
    foreach ([
        'manual_notifications',
        'manual_notification_schedule_dispatches',
        'manual_notification_dispatch_attempts',
        'manual_notification_schedule_runs',
        'operating_target_daily_records',
        'operating_target_daily_snapshots',
        'competitor_wechat_robot',
    ] as $table) {
        Db::query('SELECT 1 FROM `' . $table . '` WHERE 1 = 0');
    }
    Db::query(
        'SELECT `request_kind`, `payload_fingerprint`, `payload_snapshot_json`,'
        . ' `attempt_count`, `last_attempt_at`, `robot_id`'
        . ' FROM `manual_notification_schedule_dispatches` WHERE 1 = 0'
    );
    Db::query(
        'SELECT `dispatch_id`, `attempt_no`, `status`, `response_reference`'
        . ' FROM `manual_notification_dispatch_attempts` WHERE 1 = 0'
    );
    Db::query(
        'SELECT `scope_hotel_id`, `scope_robot_id`, `status`'
        . ' FROM `manual_notification_schedule_runs` WHERE 1 = 0'
    );

    $robot = (new ManualNotificationTestTargetService())->resolve($hotelId, $robotId);
    if ($robot === null) {
        throw new RuntimeException('verified_test_robot_identity_missing');
    }
    $robotName = (string)$robot['robot_name'];

    $records = Db::name('manual_notifications')
        ->where('enabled', 1)
        ->where('schedule_status', 'schedule_enabled')
        ->whereIn('trigger_type', ['daily_fixed_time', 'hourly_on_the_hour'])
        ->where('send_method', 'wecom_test')
        ->where('hotel_id', $hotelId)
        ->field('id,hotel_id,template_type,test_robot_id,test_robot_name')
        ->select()
        ->toArray();
    $outsideScope = [];
    foreach ($records as $record) {
        if ((int)($record['hotel_id'] ?? 0) !== $hotelId
            || (int)($record['test_robot_id'] ?? 0) !== $robotId
            || trim((string)($record['test_robot_name'] ?? '')) !== $robotName
            || trim((string)($record['template_type'] ?? '')) !== ManualNotificationService::DYNAMIC_REPORT_TYPE
        ) {
            $outsideScope[] = (int)($record['id'] ?? 0);
        }
    }
    if ($outsideScope !== []) {
        throw new RuntimeException(
            'enabled_test_schedule_outside_verified_scope ids=' . implode(',', $outsideScope)
        );
    }
    if ($requireEnabled && $records === []) {
        throw new RuntimeException('enabled_operating_target_test_schedule_missing');
    }

    echo json_encode([
        'status' => 'ready',
        'release_root' => $releaseRoot,
        'delivery_mode' => 'test',
        'hotel_id' => $hotelId,
        'robot_id' => $robotId,
        'eligible_saved_schedule_count' => count($records),
        'webhook_read' => false,
        'message_sent' => false,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, mb_substr($exception->getMessage(), 0, 240, 'UTF-8') . PHP_EOL);
    exit(2);
}
