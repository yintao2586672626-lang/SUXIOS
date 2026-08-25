<?php
declare(strict_types=1);

use app\model\User;
use app\service\TemporalInsightService;
use think\App;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @param array<string, mixed> $receipt */
function writeDailyRoomNightAccuracyReceipt(string $root, int $hotelId, array $receipt): string
{
    $directory = $root . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'daily-room-night-accuracy';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('daily room-night accuracy receipt directory could not be created');
    }
    $path = $directory . DIRECTORY_SEPARATOR . 'hotel-' . $hotelId . '-latest.json';
    $temporary = $path . '.tmp-' . getmypid();
    $json = json_encode(
        $receipt,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
    if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)) {
        @unlink($temporary);
        throw new RuntimeException('daily room-night accuracy receipt could not be written');
    }
    if (!@rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('daily room-night accuracy receipt could not be promoted atomically');
    }
    return $path;
}

/** @param array<string, mixed> $receipt */
function emitDailyRoomNightAccuracyReceipt(array $receipt): void
{
    echo 'SUXIOS_DAILY_ROOM_NIGHT_ACCURACY=', json_encode(
        $receipt,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ), PHP_EOL;
}

$options = getopt('', ['hotel-id:', 'user-id:', 'as-of-date::']);
$hotelId = (int)($options['hotel-id'] ?? 0);
$userId = (int)($options['user-id'] ?? 0);
$timezone = new DateTimeZone('Asia/Shanghai');
$asOfDate = trim((string)($options['as-of-date'] ?? ''));
if ($asOfDate === '') {
    $asOfDate = (new DateTimeImmutable('now', $timezone))->format('Y-m-d');
}
$parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $asOfDate, $timezone);
if ($hotelId <= 0 || $userId <= 0 || !$parsedDate || $parsedDate->format('Y-m-d') !== $asOfDate) {
    fwrite(STDERR, "Required: --hotel-id=<id> --user-id=<id> [--as-of-date=YYYY-MM-DD]\n");
    exit(2);
}

$root = dirname(__DIR__);
$baseReceipt = [
    'schema_version' => 'daily_room_night_accuracy.v1',
    'system_hotel_id' => $hotelId,
    'actor_user_id' => $userId,
    'as_of_date' => $asOfDate,
    'metric_scope' => 'ota_channel',
    'metric_key' => 'ota_room_nights',
    'horizon_days' => 1,
    'algorithm' => [
        'model_version' => 'coarse_trend_v1',
        'method' => 'weekday_recent_trend_interval',
        'confidence_type' => 'uncalibrated_rule_index',
    ],
    'external_action_executed' => false,
    'automatic_price_write' => false,
    'ota_write_executed' => false,
    'receipt_written_at' => (new DateTimeImmutable('now', $timezone))->format('Y-m-d H:i:s'),
];

try {
    $app = new App($root);
    $app->initialize();

    $user = User::where('id', $userId)->where('status', 1)->find();
    $authorized = $user && (
        $user->isSuperAdmin()
        || $user->hasHotelPermission($hotelId, 'can_view_online_data')
    );
    if (!$authorized) {
        $receipt = $baseReceipt + [
            'status' => 'blocked',
            'reason_code' => 'scheduled_actor_hotel_permission_missing',
            'message' => '定时执行人无权读取该酒店 OTA 数据，未生成预测。',
            'forecast' => null,
            'actual_receipt' => null,
        ];
        $receipt['receipt_path'] = writeDailyRoomNightAccuracyReceipt($root, $hotelId, $receipt);
        emitDailyRoomNightAccuracyReceipt($receipt);
        exit(2);
    }

    $service = new TemporalInsightService();
    $generated = $service->generateForecast(
        $hotelId,
        $userId,
        $asOfDate,
        1,
        'ota_room_nights'
    );
    $generationStatus = (string)($generated['status'] ?? 'blocked');
    $readbackCount = is_numeric($generated['readback_count'] ?? null)
        ? (int)$generated['readback_count']
        : null;
    $persistenceStatus = (string)($generated['persistence_status'] ?? '');
    $persistenceVerified = $generationStatus === 'generated'
        && $readbackCount === 1
        && in_array($persistenceStatus, ['saved_and_readback_verified', 'idempotent_readback_verified'], true);
    $point = is_array($generated['points'][0]['metrics']['ota_room_nights'] ?? null)
        ? $generated['points'][0]['metrics']['ota_room_nights']
        : null;
    $targetDate = trim((string)($generated['points'][0]['date'] ?? ''));
    $actualReceipt = $service->dailyRoomNightAccuracyReceipt($hotelId, $asOfDate);

    $status = $persistenceVerified
        ? 'completed'
        : ($generationStatus === 'insufficient_data' ? 'observing' : 'blocked');
    $reasonCode = $persistenceVerified
        ? ((bool)($generated['idempotent_replay'] ?? false) ? 'same_day_forecast_replayed' : 't1_forecast_saved_and_readback_verified')
        : ($generationStatus === 'insufficient_data'
            ? 'minimum_history_not_yet_available'
            : (string)($generated['reason_code'] ?? 't1_forecast_not_persisted'));
    $receipt = $baseReceipt + [
        'status' => $status,
        'reason_code' => $reasonCode,
        'message' => (string)($generated['message'] ?? 'T+1 OTA 间夜运行未返回说明。'),
        'forecast' => $point === null ? null : [
            'forecast_run_id' => (string)($generated['forecast_run_id'] ?? ''),
            'forecast_point_id' => (int)($point['forecast_point_id'] ?? 0),
            'target_date' => $targetDate,
            'predicted_value' => $point['predicted_value'] ?? null,
            'lower_bound' => $point['lower_bound'] ?? null,
            'upper_bound' => $point['upper_bound'] ?? null,
            'sample_days' => (int)($point['sample_days'] ?? 0),
            'data_quality_status' => (string)($point['data_quality_status'] ?? 'unknown'),
            'operational_status' => (string)($generated['operational_status'] ?? 'disabled'),
            'persistence_status' => $persistenceStatus,
            'saved_count' => is_numeric($generated['saved_count'] ?? null) ? (int)$generated['saved_count'] : null,
            'readback_count' => $readbackCount,
            'idempotent_replay' => (bool)($generated['idempotent_replay'] ?? false),
        ],
        'actual_receipt' => $actualReceipt,
    ];
    $receipt['receipt_path'] = writeDailyRoomNightAccuracyReceipt($root, $hotelId, $receipt);
    emitDailyRoomNightAccuracyReceipt($receipt);
    exit($status === 'blocked' ? 2 : 0);
} catch (Throwable $e) {
    $receipt = $baseReceipt + [
        'status' => 'blocked',
        'reason_code' => 'daily_room_night_accuracy_runtime_failed',
        'message' => $e->getMessage(),
        'forecast' => null,
        'actual_receipt' => null,
    ];
    try {
        $receipt['receipt_path'] = writeDailyRoomNightAccuracyReceipt($root, $hotelId, $receipt);
    } catch (Throwable) {
        $receipt['receipt_path'] = null;
    }
    emitDailyRoomNightAccuracyReceipt($receipt);
    exit(2);
}
