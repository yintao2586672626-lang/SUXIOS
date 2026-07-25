<?php
declare(strict_types=1);

use app\service\WechatMonitorVisualCardService;
use app\service\WechatRobotDeliveryService;
use app\service\CloudAutomationStateStore;
use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @return string */
function visual_option(string $name): string
{
    foreach ($GLOBALS['argv'] as $index => $argument) {
        if ($argument === '--' . $name) return trim((string)($GLOBALS['argv'][$index + 1] ?? ''));
    }
    return '';
}

$hotelId = (int)visual_option('hotel-id');
$testRobotId = (int)visual_option('test-robot-id');
$formalRobotId = (int)visual_option('robot-id');
if ($hotelId <= 0 || (($testRobotId > 0) === ($formalRobotId > 0))) {
    fwrite(STDERR, "Usage: php scripts/send_test_wechat_visual_card.php --hotel-id 80 (--test-robot-id 1 | --robot-id 2)\n");
    exit(1);
}
$robotId = $testRobotId > 0 ? $testRobotId : $formalRobotId;
$testOnly = $testRobotId > 0;

$root = dirname(__DIR__);
try {
    (new App($root))->initialize();
    $robot = Db::name('competitor_wechat_robot')->where('id', $robotId)->where('store_id', $hotelId)->where('status', 1)->field('id,name')->find();
    if (!is_array($robot)) {
        throw new RuntimeException('未找到启用中的门店企业微信机器人绑定。');
    }
    if ($testOnly && !str_contains((string)($robot['name'] ?? ''), '测试')) {
        throw new RuntimeException('测试图卡只能发送到名称含“测试”的已绑定机器人。');
    }
    $hourKey = date('Y-m-d-H');
    $idempotencyKey = "hourly-monitor-image:{$hotelId}:{$robotId}:{$hourKey}";
    $scope = $testOnly ? 'test_only' : 'operating_group';
    $identity = ['robot_id' => $robotId, 'hour' => $hourKey, 'scope' => $scope];
    $context = ['test_only' => $testOnly, 'robot_name' => (string)$robot['name'], 'hour' => $hourKey];
    $state = new CloudAutomationStateStore();
    // Reserve the external-side-effect boundary before spending CPU on a PNG.
    $guard = $state->queueDelivery(
        'hourly_monitor_visual_card',
        $hotelId,
        $identity,
        ['msgtype' => 'image', 'test_only' => $testOnly],
        $context,
        $idempotencyKey
    );
    $guardStatus = (string)($guard['status'] ?? 'queued');
    if (in_array($guardStatus, ['sent', 'sending', 'delivery_outcome_unknown'], true)) {
        $deliveryStatus = $guardStatus === 'sent' ? 'sent' : $guardStatus;
        fwrite(STDOUT, json_encode([
            'build' => null,
            'delivery' => ['delivery_status' => $deliveryStatus, 'reused' => true],
            'delivery_record' => $guard,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit($guardStatus === 'sent' ? 0 : 2);
    }
    $stamp = date('Ymd-His');
    $output = $root . '/runtime/wechat_visual_cards/hourly-' . $hotelId . '-' . $stamp . '.png';
    $command = [PHP_BINARY, $root . '/scripts/build_wechat_monitor_visual_card.php', '--hotel-id=' . $hotelId, '--output=' . $output];
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes, $root, null, ['bypass_shell' => true]);
    if (!is_resource($process)) throw new RuntimeException('图卡构建器未能启动。');
    fclose($pipes[0]); $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0) throw new RuntimeException('图卡构建失败：' . trim((string)$stderr));
    $build = json_decode((string)$stdout, true, 512, JSON_THROW_ON_ERROR);
    $payload = (new WechatMonitorVisualCardService())->imagePayloadFromFile((string)($build['output_path'] ?? ''));
    $record = $state->queueDelivery(
        'hourly_monitor_visual_card',
        $hotelId,
        $identity,
        $payload,
        $context,
        $idempotencyKey
    );
    $existingStatus = (string)($record['status'] ?? 'queued');
    if ($existingStatus === 'sent') {
        $delivery = ['delivery_status' => 'sent', 'sent_count' => 0, 'failed_count' => 0, 'reused' => true];
    } elseif ($existingStatus === 'sending') {
        $delivery = ['delivery_status' => 'in_progress', 'sent_count' => 0, 'failed_count' => 0];
    } else {
        $attempt = $state->beginDeliveryAttempt($record);
        $delivery = (new WechatRobotDeliveryService())->deliverToHotel($hotelId, $payload, [$robotId]);
        $record = $state->recordDeliveryAttempt($attempt, $delivery);
    }
    fwrite(STDOUT, json_encode(['build' => $build, 'delivery' => $delivery, 'delivery_record' => $record], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit((string)($delivery['delivery_status'] ?? '') === 'sent' ? 0 : 2);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
