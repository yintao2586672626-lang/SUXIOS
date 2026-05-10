<?php
/**
 * 自动获取线上数据脚本
 * 用法：php scripts/auto_fetch_online_data.php
 * 
 * 建议在 crontab 中配置每天早上10点执行：
 * 0 10 * * * cd /path/to/hotel-admin && php scripts/auto_fetch_online_data.php >> /var/log/hotel_admin_cron.log 2>&1
 */

// 设置错误报告
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 设置时区
date_default_timezone_set('Asia/Shanghai');

// 引入框架
require_once __DIR__ . '/../vendor/autoload.php';

// 初始化应用
$app = new \think\App();
$app->initialize();

use think\facade\Db;
use think\facade\Cache;

echo "[" . date('Y-m-d H:i:s') . "] 开始自动获取线上数据...\n";

try {
    // 获取携程 cookies 配置
    $cookiesList = Cache::get('online_data_cookies_list', []);
    $cookies = '';
    $cookiesName = '';
    
    foreach ($cookiesList as $item) {
        if (strpos($item['name'], 'ctrip') !== false) {
            $cookies = $item['cookies'];
            $cookiesName = $item['name'];
            break;
        }
    }
    
    if (empty($cookies)) {
        echo "[" . date('Y-m-d H:i:s') . "] 错误: 未配置携程Cookies\n";
        exit(1);
    }
    
    echo "[" . date('Y-m-d H:i:s') . "] 使用Cookies配置: {$cookiesName}\n";
    
    // 获取昨天的数据
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    echo "[" . date('Y-m-d H:i:s') . "] 获取日期: {$yesterday}\n";
    
    // 发送请求
    $url = 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportCompeteHotelReport';
    $postData = [
        'nodeId' => '24588',
        'startDate' => $yesterday,
        'endDate' => $yesterday,
    ];
    
    $headers = [
        'Accept: application/json, text/javascript, */*; q=0.01',
        'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
        'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With: XMLHttpRequest',
        'Origin: https://ebooking.ctrip.com',
        'Referer: https://ebooking.ctrip.com/',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Cookie: ' . $cookies,
    ];
    
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => http_build_query($postData),
            'timeout' => 30,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);
    
    $response = @file_get_contents($url, false, $context);
    
    if ($response === false) {
        $error = error_get_last();
        echo "[" . date('Y-m-d H:i:s') . "] 错误: 请求失败 - " . ($error['message'] ?? 'Unknown error') . "\n";
        exit(1);
    }
    
    $responseData = json_decode($response, true);
    echo "[" . date('Y-m-d H:i:s') . "] 原始响应: " . substr($response, 0, 500) . "...\n";
    
    // 解析数据
    $dataList = [];
    if (isset($responseData['data']) && is_array($responseData['data'])) {
        $dataList = $responseData['data'];
    } elseif (isset($responseData['hotelList']) && is_array($responseData['hotelList'])) {
        $dataList = $responseData['hotelList'];
    } elseif (is_array($responseData)) {
        $dataList = $responseData;
    }
    
    if (empty($dataList)) {
        echo "[" . date('Y-m-d H:i:s') . "] 警告: 未获取到数据\n";
        exit(0);
    }
    
    echo "[" . date('Y-m-d H:i:s') . "] 获取到 " . count($dataList) . " 条数据\n";
    
    // 保存数据
    $savedCount = 0;
    foreach ($dataList as $item) {
        if (empty($item['hotelId'])) continue;

        $itemDate = $item['dataDate']
            ?? $item['date']
            ?? $item['data_date']
            ?? $item['statDate']
            ?? $item['stat_date']
            ?? $item['bizDate']
            ?? $item['businessDate']
            ?? $item['reportDate']
            ?? $yesterday;
        if (is_string($itemDate) && preg_match('/^\d{4}-\d{2}-\d{2}/', $itemDate, $matches)) {
            $itemDate = $matches[0];
        }
        
        $exists = Db::name('online_daily_data')
            ->where('source', 'ctrip')
            ->whereNull('system_hotel_id')
            ->where('hotel_id', (string)$item['hotelId'])
            ->where('data_date', $itemDate)
            ->find();
        
        $data = [
            'hotel_id' => (string)$item['hotelId'],
            'hotel_name' => $item['hotelName'] ?? '',
            'system_hotel_id' => null,
            'data_date' => $itemDate,
            'amount' => floatval($item['amount'] ?? 0),
            'quantity' => intval($item['quantity'] ?? 0),
            'book_order_num' => intval($item['bookOrderNum'] ?? 0),
            'comment_score' => floatval($item['commentScore'] ?? 0),
            'qunar_comment_score' => floatval($item['qunarCommentScore'] ?? 0),
            'source' => 'ctrip',
            'data_type' => 'business',
            'dimension' => '',
            'raw_data' => json_encode($item, JSON_UNESCAPED_UNICODE),
        ];
        
        if ($exists) {
            Db::name('online_daily_data')
                ->where('id', $exists['id'])
                ->update($data);
        } else {
            Db::name('online_daily_data')->insert($data);
        }
        $savedCount++;
    }
    
    // 记录日志
    Db::name('operation_logs')->insert([
        'user_id' => 0,
        'module' => 'online_data',
        'action' => 'cron_fetch',
        'description' => '定时任务自动获取线上数据: ' . $savedCount . '条',
        'ip' => '127.0.0.1',
        'user_agent' => 'cron_script',
        'create_time' => date('Y-m-d H:i:s'),
    ]);
    
    echo "[" . date('Y-m-d H:i:s') . "] 成功: 保存 {$savedCount} 条数据\n";
    echo "[" . date('Y-m-d H:i:s') . "] 完成\n";
    
} catch (\Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] 异常: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
