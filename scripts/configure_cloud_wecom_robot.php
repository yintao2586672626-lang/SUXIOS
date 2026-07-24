#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\service\WechatRobotWebhookSecret;
use app\service\WechatRobotDeliveryService;
use think\App;
use think\facade\Db;

$options = getopt('', ['hotel-id:', 'app-dir::', 'name::', 'send-test']);
$hotelId = max(0, (int)($options['hotel-id'] ?? 0));
$appDir = rtrim((string)($options['app-dir'] ?? dirname(__DIR__)), "\\/");
$robotName = trim((string)($options['name'] ?? '宿析OS云端日报'));
$webhook = trim((string)stream_get_contents(STDIN));
$webhook = preg_replace('/^\xEF\xBB\xBF/', '', $webhook) ?? '';

if ($hotelId <= 0) {
    fail('hotel_id_invalid');
}
if ($robotName === '' || mb_strlen($robotName, 'UTF-8') > 120) {
    fail('robot_name_invalid');
}
if (!isValidWechatWebhook($webhook)) {
    fail('webhook_invalid');
}

$autoload = $appDir . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fail('app_autoload_missing');
}

require $autoload;
(new App($appDir))->initialize();

$hotel = Db::name('hotels')
    ->field('id,name,status')
    ->where('id', $hotelId)
    ->find();
if (!is_array($hotel) || (int)($hotel['status'] ?? 0) !== 1) {
    fail('hotel_missing_or_disabled');
}

$result = Db::transaction(static function () use ($hotelId, $robotName, $webhook): array {
    $rows = Db::name('competitor_wechat_robot')
        ->where('store_id', $hotelId)
        ->order('id', 'asc')
        ->lock(true)
        ->select()
        ->toArray();

    if (count($rows) > 1) {
        throw new RuntimeException('multiple_robot_bindings_require_manual_choice');
    }

    $robotId = (int)($rows[0]['id'] ?? 0);
    $created = false;
    if ($robotId <= 0) {
        $robotId = (int)Db::name('competitor_wechat_robot')->insertGetId([
            'store_id' => $hotelId,
            'name' => $robotName,
            'webhook' => '',
            'status' => 1,
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
        ]);
        if ($robotId <= 0) {
            throw new RuntimeException('robot_insert_failed');
        }
        $created = true;
    }

    $secret = new WechatRobotWebhookSecret();
    $protected = $secret->protect($webhook, $robotId);
    $updated = Db::name('competitor_wechat_robot')
        ->where('id', $robotId)
        ->where('store_id', $hotelId)
        ->update([
            'name' => $robotName,
            'webhook' => $protected,
            'status' => 1,
            'update_time' => date('Y-m-d H:i:s'),
        ]);
    if ($updated !== 1) {
        throw new RuntimeException('robot_update_failed');
    }

    $stored = (string)Db::name('competitor_wechat_robot')
        ->where('id', $robotId)
        ->value('webhook');
    if (!$secret->isProtected($stored) || !hash_equals($webhook, $secret->reveal($stored, $robotId))) {
        throw new RuntimeException('encrypted_readback_failed');
    }

    return [
        'status' => 'configured',
        'hotel_id' => $hotelId,
        'robot_id' => $robotId,
        'created' => $created,
        'enabled' => true,
        'protected' => true,
        'stored_length' => strlen($stored),
        'plaintext_readback_verified' => true,
    ];
});

if (array_key_exists('send-test', $options)) {
    $hotelName = trim((string)($hotel['name'] ?? '')) ?: ('酒店 #' . $hotelId);
    $delivery = (new WechatRobotDeliveryService())->deliverToHotel(
        $hotelId,
        [
            'msgtype' => 'markdown',
            'markdown' => [
                'content' => '# 宿析OS 企业微信联通测试' . "\n"
                    . '> 门店：' . $hotelName . "\n"
                    . '> 云端推送链路已连接；本次未触发 OTA 采集或报告生成。',
            ],
        ],
        [(int)$result['robot_id']]
    );
    $result['test_delivery'] = [
        'status' => (string)($delivery['delivery_status'] ?? 'failed'),
        'robot_count' => (int)($delivery['robot_count'] ?? 0),
        'sent_count' => (int)($delivery['sent_count'] ?? 0),
        'failed_count' => (int)($delivery['failed_count'] ?? 0),
    ];
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
if (isset($result['test_delivery']) && ($result['test_delivery']['status'] ?? '') !== 'sent') {
    exit(2);
}

function isValidWechatWebhook(string $webhook): bool
{
    if ($webhook === '' || strlen($webhook) > 256 || filter_var($webhook, FILTER_VALIDATE_URL) === false) {
        return false;
    }
    $parts = parse_url($webhook);
    if (!is_array($parts)
        || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
        || strtolower((string)($parts['host'] ?? '')) !== 'qyapi.weixin.qq.com'
        || (string)($parts['path'] ?? '') !== '/cgi-bin/webhook/send'
        || isset($parts['user'], $parts['pass'])
        || isset($parts['fragment'])
        || (isset($parts['port']) && (int)$parts['port'] !== 443)
    ) {
        return false;
    }
    parse_str((string)($parts['query'] ?? ''), $query);
    $key = $query['key'] ?? null;
    return is_string($key) && preg_match('/^[A-Za-z0-9-]{16,128}$/D', trim($key)) === 1;
}

function fail(string $reason): never
{
    fwrite(STDERR, json_encode(['status' => 'failed', 'reason' => $reason]) . PHP_EOL);
    exit(1);
}
