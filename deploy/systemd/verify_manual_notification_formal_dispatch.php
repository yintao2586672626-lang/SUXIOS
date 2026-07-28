<?php
declare(strict_types=1);

use app\service\WechatRobotDeliveryService;
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
        'hotels',
        'manual_notifications',
        'manual_notification_schedule_dispatches',
        'manual_notification_dispatch_attempts',
        'manual_notification_schedule_runs',
        'manual_notification_schedule_run_scopes',
        'competitor_wechat_robot',
    ] as $table) {
        Db::query('SELECT 1 FROM `' . $table . '` WHERE 1 = 0');
    }

    // Selecting these columns also proves the forward migration needed by
    // formal tenant/robot scoping has been applied. Webhook is never selected.
    Db::query(
        'SELECT `tenant_id`,`store_id`,`owner_user_id`,`notification_scope`,`status`'
        . ' FROM `competitor_wechat_robot` WHERE 1 = 0'
    );
    Db::query(
        'SELECT `schedule_run_id`'
        . ' FROM `manual_notification_schedule_dispatches` WHERE 1 = 0'
    );
    Db::query(
        'SELECT `source_scope`,`content_sections`,`business_date_rule`,`active_weekdays`,'
        . '`effective_from`,`effective_to`,`hourly_start_time`,`hourly_end_time`,'
        . '`interval_minutes`'
        . ' FROM `manual_notifications` WHERE 1 = 0'
    );

    $records = Db::name('manual_notifications')
        ->alias('notification')
        ->leftJoin('hotels hotel', 'hotel.id = notification.hotel_id')
        ->where('notification.enabled', 1)
        ->where('notification.schedule_status', 'schedule_enabled')
        ->whereIn(
            'notification.trigger_type',
            ['daily_fixed_time', 'hourly_on_the_hour', 'interval_minutes']
        )
        ->where('notification.send_method', 'wecom_formal')
        ->field(
            'notification.id,notification.tenant_id,notification.hotel_id,'
            . 'notification.created_by,notification.test_robot_id,'
            . 'notification.test_robot_name,hotel.tenant_id AS hotel_tenant_id'
        )
        ->select()
        ->toArray();

    $delivery = new WechatRobotDeliveryService();
    $blocked = [];
    foreach ($records as $record) {
        $tenantId = (int)($record['tenant_id'] ?? 0);
        $hotelId = (int)($record['hotel_id'] ?? 0);
        $hotelTenantId = (int)($record['hotel_tenant_id'] ?? 0);
        if ($tenantId <= 0 || $hotelTenantId !== $tenantId) {
            $blocked[] = [
                'id' => (int)($record['id'] ?? 0),
                'reason_code' => 'hotel_tenant_scope_mismatch',
            ];
            continue;
        }

        $binding = $delivery->resolvePlanRobot(
            $tenantId,
            $hotelId,
            (int)($record['test_robot_id'] ?? 0),
            trim((string)($record['test_robot_name'] ?? '')),
            (int)($record['created_by'] ?? 0),
            'formal'
        );
        if (($binding['eligible'] ?? false) !== true) {
            $blocked[] = [
                'id' => (int)($record['id'] ?? 0),
                'reason_code' => (string)($binding['reason_code'] ?? 'target_robot_ineligible'),
            ];
        }
    }

    if ($blocked !== []) {
        throw new RuntimeException(
            'formal_schedule_binding_invalid ' . json_encode(
                $blocked,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )
        );
    }
    if (in_array('--require-enabled', $argv, true) && $records === []) {
        throw new RuntimeException('enabled_formal_schedule_missing');
    }

    echo json_encode([
        'status' => 'ready',
        'release_root' => $releaseRoot,
        'delivery_mode' => 'formal',
        'eligible_saved_schedule_count' => count($records),
        'webhook_read' => false,
        'message_sent' => false,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, mb_substr($exception->getMessage(), 0, 500, 'UTF-8') . PHP_EOL);
    exit(2);
}
