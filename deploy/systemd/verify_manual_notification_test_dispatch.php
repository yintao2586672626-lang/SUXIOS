<?php
declare(strict_types=1);

use app\service\ManualNotificationService;
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

    $robot = Db::name('competitor_wechat_robot')
        ->where('id', 1)
        ->where('store_id', 80)
        ->where('name', ManualNotificationService::TEST_ROBOT_NAME)
        ->where('status', 1)
        ->field('id,store_id,name,status')
        ->find();
    if (!is_array($robot)) {
        throw new RuntimeException('hotel80_test_robot1_identity_missing');
    }

    $records = Db::name('manual_notifications')
        ->where('enabled', 1)
        ->where('schedule_status', 'schedule_enabled')
        ->whereIn('trigger_type', ['daily_fixed_time', 'hourly_on_the_hour'])
        ->where('send_method', 'wecom_test')
        ->field('id,hotel_id,template_type,test_robot_id,test_robot_name')
        ->select()
        ->toArray();
    $outsideScope = [];
    foreach ($records as $record) {
        if ((int)($record['hotel_id'] ?? 0) !== 80
            || (int)($record['test_robot_id'] ?? 0) !== 1
            || trim((string)($record['test_robot_name'] ?? '')) !== ManualNotificationService::TEST_ROBOT_NAME
            || trim((string)($record['template_type'] ?? '')) !== ManualNotificationService::DYNAMIC_REPORT_TYPE
        ) {
            $outsideScope[] = (int)($record['id'] ?? 0);
        }
    }
    if ($outsideScope !== []) {
        throw new RuntimeException(
            'enabled_test_schedule_outside_hotel80_robot1 ids=' . implode(',', $outsideScope)
        );
    }
    if (in_array('--require-enabled', $argv, true) && $records === []) {
        throw new RuntimeException('enabled_operating_target_test_schedule_missing');
    }

    echo json_encode([
        'status' => 'ready',
        'release_root' => $releaseRoot,
        'delivery_mode' => 'test',
        'hotel_id' => 80,
        'robot_id' => 1,
        'eligible_saved_schedule_count' => count($records),
        'webhook_read' => false,
        'message_sent' => false,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, mb_substr($exception->getMessage(), 0, 240, 'UTF-8') . PHP_EOL);
    exit(2);
}
