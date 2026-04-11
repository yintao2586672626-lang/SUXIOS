<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;
use think\facade\Cache;
use think\facade\Log;

class AutoFetchOnlineData extends Command
{
    protected function configure()
    {
        $this->setName('online-data:auto-fetch')
            ->setDescription('自动获取线上数据（定时任务调用）');
    }

    protected function execute(Input $input, Output $output)
    {
        $output->writeln('[' . date('Y-m-d H:i:s') . '] 开始检查自动获取任务...');
        
        $currentTime = date('H:i');
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        
        // 获取所有酒店
        $hotels = Db::name('hotels')->where('status', 1)->select()->toArray();
        
        foreach ($hotels as $hotel) {
            $hotelId = $hotel['id'];
            $statusKey = "online_data_auto_fetch_status_{$hotelId}";
            $status = Cache::get($statusKey, []);
            
            // 检查是否开启
            if (empty($status['enabled'])) {
                continue;
            }
            
            // 检查运行时间
            $scheduleTime = $status['schedule_time'] ?? '10:00';
            if ($currentTime !== $scheduleTime) {
                continue;
            }
            
            // 检查今天是否已执行
            $executedKey = "online_data_executed_{$hotelId}_{$today}";
            if (Cache::get($executedKey)) {
                $output->writeln("酒店 {$hotel['name']} 今天已执行，跳过");
                continue;
            }
            
            $output->writeln("开始为酒店 {$hotel['name']} 获取数据...");
            
            // 执行获取
            $result = $this->fetchDataForHotel($hotelId, $yesterday);
            
            if ($result['success']) {
                $output->writeln("酒店 {$hotel['name']} 获取成功: {$result['message']}");
                $this->updateStatus($hotelId, true, $result['message']);
            } else {
                $output->writeln("酒店 {$hotel['name']} 获取失败: {$result['message']}");
                $this->updateStatus($hotelId, false, $result['message']);
            }
            
            // 标记今天已执行
            Cache::set($executedKey, true, 86400);
        }
        
        $output->writeln('[' . date('Y-m-d H:i:s') . '] 自动获取任务检查完成');
        return 0;
    }
    
    private function fetchDataForHotel(int $hotelId, string $dataDate): array
    {
        // 获取Cookies
        $cookiesKey = "online_data_cookies_{$hotelId}";
        $cookiesList = Cache::get($cookiesKey, []);
        
        if (empty($cookiesList)) {
            $cookiesList = Cache::get('online_data_cookies_list', []);
        }
        
        $cookies = '';
        foreach ($cookiesList as $item) {
            if (strpos($item['name'], 'ctrip') !== false) {
                $cookies = $item['cookies'];
                break;
            }
        }
        
        if (empty($cookies)) {
            return ['success' => false, 'message' => '未配置Cookies'];
        }
        
        try {
            $result = $this->sendHttpRequest(
                'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportCompeteHotelReport',
                ['nodeId' => '24588', 'startDate' => $dataDate, 'endDate' => $dataDate],
                $cookies
            );
            
            if (!$result['success']) {
                return ['success' => false, 'message' => '请求失败: ' . $result['error']];
            }
            
            $savedCount = $this->parseAndSaveData($result['data'], $dataDate, $dataDate, $hotelId);
            
            if ($savedCount === 0) {
                return ['success' => false, 'message' => '未获取到有效数据'];
            }
            
            Log::info("自动获取线上数据成功", ['hotel_id' => $hotelId, 'count' => $savedCount]);
            
            return ['success' => true, 'message' => "成功获取 {$savedCount} 条数据"];
            
        } catch (\Exception $e) {
            Log::error("自动获取线上数据异常", ['hotel_id' => $hotelId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => '异常: ' . $e->getMessage()];
        }
    }
    
    private function sendHttpRequest(string $url, array $postData, string $cookies): array
    {
        $headers = [
            'Accept: application/json, text/javascript, */*; q=0.01',
            'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With: XMLHttpRequest',
            'Origin: https://ebooking.ctrip.com',
            'Referer: https://ebooking.ctrip.com/',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Cookie: ' . $cookies,
        ];
        
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => http_build_query($postData),
                'timeout' => 30,
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        
        $response = @file_get_contents($url, false, $context);
        
        if ($response === false) {
            return ['success' => false, 'error' => error_get_last()['message'] ?? 'Unknown error'];
        }
        
        return ['success' => true, 'data' => json_decode($response, true), 'raw' => $response];
    }
    
    private function parseAndSaveData($responseData, $startDate, $endDate, int $hotelId): int
    {
        $dataList = $responseData['data']['hotelList'] ?? $responseData['data'] ?? $responseData['hotelList'] ?? [];
        
        if (empty($dataList)) {
            foreach ($responseData as $value) {
                if (is_array($value) && isset($value[0]) && isset($value[0]['hotelId'])) {
                    $dataList = array_merge($dataList, $value);
                }
            }
        }
        
        if (empty($dataList)) return 0;
        
        $savedCount = 0;
        foreach ($dataList as $item) {
            if (!is_array($item)) continue;
            
            $hotelIdFromData = $item['hotelId'] ?? $item['hotel_id'] ?? null;
            if (empty($hotelIdFromData)) continue;
            
            $dataDate = $item['dataDate'] ?? $item['date'] ?? $startDate;
            
            $exists = Db::name('online_daily_data')
                ->where('hotel_id', (string)$hotelIdFromData)
                ->where('data_date', $dataDate)
                ->where('system_hotel_id', $hotelId)
                ->find();
            
            $data = [
                'hotel_id' => (string)$hotelIdFromData,
                'hotel_name' => $item['hotelName'] ?? $item['hotel_name'] ?? '',
                'system_hotel_id' => $hotelId,
                'data_date' => $dataDate,
                'amount' => floatval($item['amount'] ?? $item['totalAmount'] ?? 0),
                'quantity' => intval($item['quantity'] ?? $item['roomNights'] ?? 0),
                'book_order_num' => intval($item['bookOrderNum'] ?? 0),
                'comment_score' => floatval($item['commentScore'] ?? 0),
                'qunar_comment_score' => floatval($item['qunarCommentScore'] ?? 0),
                'raw_data' => json_encode($item, JSON_UNESCAPED_UNICODE),
            ];
            
            if ($exists) {
                Db::name('online_daily_data')->where('id', $exists['id'])->update($data);
            } else {
                Db::name('online_daily_data')->insert($data);
            }
            $savedCount++;
        }
        
        return $savedCount;
    }
    
    private function updateStatus(int $hotelId, bool $success, string $message): void
    {
        $statusKey = "online_data_auto_fetch_status_{$hotelId}";
        $status = Cache::get($statusKey, []);
        $status['last_run_time'] = date('Y-m-d H:i:s');
        $status['last_result'] = ['success' => $success, 'message' => $message];
        Cache::set($statusKey, $status, 86400 * 30);
    }
}
