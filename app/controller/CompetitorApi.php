<?php
declare(strict_types=1);

namespace app\controller;

use app\model\CompetitorDevice;
use app\model\CompetitorHotel;
use app\model\CompetitorPriceLog;
use think\Response;
use think\facade\Db;

class CompetitorApi extends Base
{
    private const TASK_TOKEN = 'shop2025';

    public function task(): Response
    {
        $deviceId = (string)$this->request->post('device_id', '');
        $platform = (string)$this->request->post('platform', '');
        $token = (string)$this->request->post('token', '');

        if ($token !== self::TASK_TOKEN) {
            return json(['code' => 403, 'message' => 'token无效', 'data' => null]);
        }
        if ($deviceId === '' || $platform === '') {
            return json(['code' => 400, 'message' => '参数不完整', 'data' => null]);
        }

        $now = date('Y-m-d H:i:s');
        $oneHourAgo = date('Y-m-d H:i:s', strtotime('-1 hour'));

        // 更新设备在线时间
        $device = CompetitorDevice::where('device_id', $deviceId)->find();
        if (!$device) {
            $device = new CompetitorDevice();
            $device->device_id = $deviceId;
            $device->name = $deviceId;
            $device->status = 1;
        }
        $device->last_time = $now;
        $device->save();

        $query = CompetitorHotel::where('status', 1)->where('platform', $platform);

        // 同一酒店每小时只抓1次
        $query->whereNotExists(function ($sub) use ($oneHourAgo, $platform) {
            $sub->table('competitor_price_log')
                ->whereColumn('competitor_price_log.hotel_id', 'competitor_hotel.id')
                ->whereColumn('competitor_price_log.store_id', 'competitor_hotel.store_id')
                ->where('competitor_price_log.platform', $platform)
                ->where('competitor_price_log.fetch_time', '>=', $oneHourAgo);
        });

        $list = $query->limit(5)->select()->toArray();

        $data = array_map(function ($item) {
            return [
                'store_id' => (int)$item['store_id'],
                'hotel_id' => (int)$item['id'],
                'city' => $item['city'],
                'hotel_name' => $item['hotel_name'],
                'platform' => $item['platform'],
            ];
        }, $list);

        return json(['code' => 200, 'message' => 'ok', 'data' => $data]);
    }

    public function report(): Response
    {
        $storeId = (int)$this->request->post('store_id', 0);
        $hotelId = (int)$this->request->post('hotel_id', 0);
        $platform = (string)$this->request->post('platform', '');
        $city = (string)$this->request->post('city', '');
        $priceText = (string)$this->request->post('price_text', '');
        $base64 = (string)$this->request->post('base64', '');
        $deviceId = (string)$this->request->post('device_id', '');

        if ($storeId <= 0 || $hotelId <= 0 || $platform === '' || $city === '' || $deviceId === '') {
            return json(['code' => 400, 'message' => '参数不完整', 'data' => null]);
        }

        $price = $this->extractPrice($priceText);

        $screenshotPath = '';
        if ($base64 !== '') {
            $screenshotPath = $this->saveBase64Image($base64);
        }

        $log = new CompetitorPriceLog();
        $log->store_id = $storeId;
        $log->hotel_id = $hotelId;
        $log->platform = $platform;
        $log->city = $city;
        $log->price = $price;
        $log->screenshot = $screenshotPath;
        $log->device_id = $deviceId;
        $log->fetch_time = date('Y-m-d H:i:s');
        $log->save();

        // 更新设备在线时间
        $device = CompetitorDevice::where('device_id', $deviceId)->find();
        if ($device) {
            $device->last_time = date('Y-m-d H:i:s');
            $device->save();
        }

        return json(['code' => 200, 'message' => 'ok', 'data' => ['id' => $log->id]]);
    }

    private function extractPrice(string $text): float
    {
        if (preg_match('/(\d+(?:\.\d+)?)/', $text, $matches)) {
            return (float)$matches[1];
        }
        return 0.0;
    }

    private function saveBase64Image(string $base64): string
    {
        $data = $base64;
        if (strpos($data, ',') !== false) {
            $data = explode(',', $data, 2)[1];
        }
        $binary = base64_decode($data, true);
        if ($binary === false) {
            return '';
        }
        $datePath = date('Ymd');
        $dir = runtime_path() . 'upload/price/' . $datePath . '/';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $filename = uniqid('price_', true) . '.jpg';
        $path = $dir . $filename;
        file_put_contents($path, $binary);

        return 'runtime/upload/price/' . $datePath . '/' . $filename;
    }
}
