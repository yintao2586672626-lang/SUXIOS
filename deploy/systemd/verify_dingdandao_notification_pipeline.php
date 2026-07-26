<?php
declare(strict_types=1);

use app\service\CloudBrowserProfileService;
use app\service\DingdandaoCloudCollectionService;
use app\service\ManualNotificationService;
use app\service\ManualNotificationTestTargetService;
use app\service\OperatingTargetNotificationPayloadService;
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
    'require-enable-readiness',
]);
$hotelId = max(0, (int)($options['hotel-id'] ?? 0));
$robotId = max(0, (int)($options['robot-id'] ?? 0));
$ownerUserId = max(0, (int)($options['owner-user-id'] ?? 0));
$profileId = trim((string)($options['profile-id'] ?? ''));
$requireEnabledSchedule = array_key_exists('require-enabled-schedule', $options);
$requireEnableReadiness = array_key_exists('require-enable-readiness', $options);
if ($hotelId <= 0
    || $robotId <= 0
    || $ownerUserId <= 0
    || preg_match('/^cbp_[A-Za-z0-9_-]{16,64}$/D', $profileId) !== 1
) {
    fwrite(STDERR, "pipeline_scope_arguments_invalid\n");
    exit(2);
}
if ($requireEnableReadiness && !$requireEnabledSchedule) {
    fwrite(STDERR, "pipeline_enable_readiness_requires_enabled_schedule\n");
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
        'system_configs',
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

    $serverBindingReady = false;
    $todayReportGateReady = false;
    $priorSentAttemptReady = false;
    $gatewayProfileLeaseReady = false;
    if ($requireEnableReadiness) {
        pipelineVerifyGatewayProfileLeaseContract();
        $gatewayProfileLeaseReady = true;

        $bootstrapScope = (new DingdandaoCloudCollectionService())
            ->bindingBootstrapScope(
                $profileId,
                $tenantId,
                $hotelId,
                $ownerUserId
            );
        pipelineVerifyDingdandaoServerBinding(
            $tenantId,
            $hotelId,
            (string)($bootstrapScope['expected_provider_hotel_name'] ?? '')
        );
        $serverBindingReady = true;

        $candidate = (new OperatingTargetNotificationPayloadService())->build(
            $tenantId,
            $hotelId,
            (string)$hotel['name'],
            $today,
            'scheduled_test'
        );
        $facts = is_array($candidate['report_preview']['facts'] ?? null)
            ? $candidate['report_preview']['facts']
            : [];
        $targetRevenue = $facts['target_revenue'] ?? null;
        if (($candidate['status'] ?? '') !== 'ready'
            || ($candidate['formal_send_gate']['allowed'] ?? false) !== true
            || (string)($candidate['business_date'] ?? '') !== $today
            || (int)($candidate['operating_target_record_id'] ?? 0) <= 0
            || !is_numeric($targetRevenue)
            || !is_finite((float)$targetRevenue)
            || (float)$targetRevenue <= 0
        ) {
            throw new RuntimeException('pipeline_today_report_gate_not_ready');
        }
        $todayReportGateReady = true;

        $priorSentAttemptReady = pipelineHasPriorSentTestAttempt(
            $tenantId,
            $hotelId,
            $robotId
        );
        if (!$priorSentAttemptReady) {
            throw new RuntimeException(
                'pipeline_external_delivery_preflight_missing_prior_sent_attempt'
            );
        }
    }

    echo json_encode([
        'status' => $requireEnableReadiness
            ? 'enable_preflight_ready_runtime_session_unverified'
            : 'scope_ready',
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
        'server_binding_ready' => $serverBindingReady,
        'today_report_gate_ready' => $todayReportGateReady,
        'external_delivery_preflight' => $priorSentAttemptReady
            ? 'prior_sent_attempt_verified'
            : 'not_checked',
        'gateway_profile_lease_ready' => $gatewayProfileLeaseReady,
        'live_session_verified' => false,
        'runtime_collection_fail_closed' => true,
        'webhook_read' => false,
        'secret_material_read' => false,
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

function pipelineVerifyGatewayProfileLeaseContract(): void
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 3,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents(
        'http://127.0.0.1:8787/health',
        false,
        $context
    );
    $health = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($health)
        || (string)($health['status'] ?? '') !== 'ok'
        || (string)($health['profile_lease_contract'] ?? '')
            !== 'dingdandao_profile_lease.v1'
        || ($health['read_only_policy_runtime'] ?? null) !== true
    ) {
        throw new RuntimeException(
            'pipeline_gateway_profile_lease_contract_unavailable'
        );
    }
}

function pipelineVerifyDingdandaoServerBinding(
    int $tenantId,
    int $hotelId,
    string $expectedProviderHotelName
): void {
    if ($expectedProviderHotelName === '') {
        throw new RuntimeException('pipeline_dingdandao_server_binding_invalid');
    }
    $raw = Db::name('system_configs')
        ->where('config_key', 'dingdandao_hotel_bindings')
        ->value('config_value');
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        throw new RuntimeException('pipeline_dingdandao_server_binding_missing');
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
        if ($rowHotelId <= 0
            || $providerHotelId === ''
            || strlen($providerHotelId) > 120
            || $providerHotelName === ''
            || mb_strlen($providerHotelName) > 160
        ) {
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
    if (count($targetBindings) !== 1) {
        throw new RuntimeException('pipeline_dingdandao_server_binding_invalid');
    }
    $binding = $targetBindings[0];
    if (!hash_equals(
        $expectedProviderHotelName,
        (string)$binding['provider_hotel_name']
    )
        || count($providerOwners[$binding['provider_hotel_id']] ?? []) !== 1
    ) {
        throw new RuntimeException('pipeline_dingdandao_server_binding_invalid');
    }
}

function pipelineHasPriorSentTestAttempt(
    int $tenantId,
    int $hotelId,
    int $robotId
): bool {
    $row = Db::name('manual_notification_schedule_dispatches')
        ->alias('dispatch')
        ->join(
            'manual_notification_dispatch_attempts attempt',
            'attempt.dispatch_id = dispatch.id'
        )
        ->join(
            'manual_notifications notification',
            'notification.id = dispatch.notification_id'
        )
        ->where('dispatch.tenant_id', $tenantId)
        ->where('dispatch.hotel_id', $hotelId)
        ->where('dispatch.robot_id', $robotId)
        ->where('dispatch.delivery_mode', 'test')
        ->where('dispatch.status', 'sent')
        ->where('dispatch.result_code', 'wecom_business_success')
        ->where('attempt.status', 'sent')
        ->where('attempt.result_code', 'wecom_business_success')
        ->where('notification.tenant_id', $tenantId)
        ->where('notification.hotel_id', $hotelId)
        ->where('notification.send_method', 'wecom_test')
        ->where(
            'notification.template_type',
            ManualNotificationService::DYNAMIC_REPORT_TYPE
        )
        ->field('dispatch.id AS dispatch_id,attempt.id AS attempt_id')
        ->order('attempt.id', 'desc')
        ->find();
    return is_array($row)
        && (int)($row['dispatch_id'] ?? 0) > 0
        && (int)($row['attempt_id'] ?? 0) > 0;
}
