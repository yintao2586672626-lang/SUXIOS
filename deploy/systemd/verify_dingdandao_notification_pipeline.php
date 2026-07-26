<?php
declare(strict_types=1);

use app\service\CloudBrowserProfileService;
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
(new App($releaseRoot))->initialize();
$options = getopt('', [
    'hotel-id:',
    'robot-id:',
    'owner-user-id:',
    'profile-id:',
    'require-enabled-schedule',
]);
$hotelId = max(0, (int)($options['hotel-id'] ?? 0));
$robotId = max(0, (int)($options['robot-id'] ?? 0));
$ownerUserId = max(0, (int)($options['owner-user-id'] ?? 0));
$profileId = trim((string)($options['profile-id'] ?? ''));
$requireEnabledSchedule = array_key_exists('require-enabled-schedule', $options);
if ($hotelId <= 0
    || $robotId <= 0
    || $ownerUserId <= 0
    || preg_match('/^cbp_[A-Za-z0-9_-]{16,64}$/D', $profileId) !== 1
) {
    fwrite(STDERR, "pipeline_scope_arguments_invalid\n");
    exit(2);
}

try {
    foreach ([
        'hotels',
        'competitor_wechat_robot',
        'cloud_browser_profiles',
        'manual_notifications',
        'manual_notification_schedule_dispatches',
        'manual_notification_dispatch_attempts',
        'manual_notification_schedule_runs',
        'dingdandao_operating_target_captures',
        'dingdandao_room_fee_capture_details',
        'operating_target_daily_records',
        'operating_target_daily_snapshots',
    ] as $table) {
        Db::query('SELECT 1 FROM `' . $table . '` WHERE 1 = 0');
    }
    Db::query(
        'SELECT `scope_hotel_id`, `scope_robot_id`, `result_summary_json`'
        . ' FROM `manual_notification_schedule_runs` WHERE 1 = 0'
    );

    $hotel = Db::name('hotels')
        ->where('id', $hotelId)
        ->field('id,tenant_id,name,status')
        ->find();
    if (!is_array($hotel)
        || (int)($hotel['tenant_id'] ?? 0) <= 0
        || (int)($hotel['status'] ?? 0) !== 1
        || trim((string)($hotel['name'] ?? '')) === ''
    ) {
        throw new RuntimeException('pipeline_hotel_scope_invalid');
    }
    $tenantId = (int)$hotel['tenant_id'];
    $robot = (new ManualNotificationTestTargetService())->resolve($hotelId, $robotId);
    if ($robot === null) {
        throw new RuntimeException('pipeline_verified_test_robot_missing');
    }

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
        throw new RuntimeException('pipeline_cloud_profile_not_ready');
    }

    $schedules = Db::name('manual_notifications')
        ->where('tenant_id', $tenantId)
        ->where('hotel_id', $hotelId)
        ->where('enabled', 1)
        ->where('schedule_status', 'schedule_enabled')
        ->whereIn('trigger_type', ['daily_fixed_time', 'hourly_on_the_hour'])
        ->where('send_method', 'wecom_test')
        ->field('id,template_type,test_robot_id,test_robot_name')
        ->select()
        ->toArray();
    $robotName = (string)$robot['robot_name'];
    foreach ($schedules as $schedule) {
        if ((string)($schedule['template_type'] ?? '')
                !== ManualNotificationService::DYNAMIC_REPORT_TYPE
            || (int)($schedule['test_robot_id'] ?? 0) !== $robotId
            || trim((string)($schedule['test_robot_name'] ?? '')) !== $robotName
        ) {
            throw new RuntimeException(
                'pipeline_enabled_schedule_scope_mismatch notification_id='
                . (int)($schedule['id'] ?? 0)
            );
        }
    }
    if ($requireEnabledSchedule && $schedules === []) {
        throw new RuntimeException('pipeline_enabled_schedule_missing');
    }

    echo json_encode([
        'status' => 'ready',
        'release_root' => $releaseRoot,
        'business_date' => $today,
        'hotel_id' => $hotelId,
        'hotel_name' => (string)$hotel['name'],
        'robot_id' => $robotId,
        'robot_name' => $robotName,
        'notification_scope' => ManualNotificationTestTargetService::TEST_SCOPE,
        'profile_reference' => substr($profileId, 0, 12) . '...',
        'profile_ready' => true,
        'access_mode' => 'read_only',
        'source_scope' => 'today_only',
        'eligible_saved_schedule_count' => count($schedules),
        'webhook_read' => false,
        'message_sent' => false,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    $message = preg_replace(
        '/(key|token|secret|cookie|password|authorization|webhook)\s*[=:]\s*[^\s,;]+/iu',
        '$1=<redacted>',
        $exception->getMessage()
    ) ?? '';
    fwrite(STDERR, mb_substr(trim($message), 0, 240, 'UTF-8') . PHP_EOL);
    exit(2);
}
