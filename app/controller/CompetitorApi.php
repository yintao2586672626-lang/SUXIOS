<?php
declare(strict_types=1);

namespace app\controller;

use app\model\CompetitorDevice;
use app\model\CompetitorHotel;
use app\model\CompetitorPriceLog;
use app\model\OperationLog;
use think\Response;

class CompetitorApi extends Base
{
    private const TASK_TOKEN_ENV = 'COMPETITOR_TASK_TOKEN';
    private const REPORT_TOKEN_ENV = 'COMPETITOR_REPORT_TOKEN';

    public function task(): Response
    {
        $deviceId = (string)$this->request->post('device_id', '');
        $platform = (string)$this->request->post('platform', '');
        $token = (string)$this->request->post('token', '');

        $rateLimitResponse = $this->enforceExternalRateLimit('task', $this->externalRateLimitIdentity($deviceId, $platform), 30, 60);
        if ($rateLimitResponse !== null) {
            return $rateLimitResponse;
        }

        $expectedToken = $this->getTaskToken();
        if ($expectedToken === '') {
            OperationLog::record('competitor', 'task_denied', '竞对任务领取失败: 未配置Token', null, null, 'missing_task_token', [
                'audit_type' => 'operation',
                'device_id' => $this->sanitizeExternalAuditText($deviceId),
                'platform' => $this->sanitizeExternalAuditText($platform),
            ]);
            return $this->apiError('未配置COMPETITOR_TASK_TOKEN', 403);
        }

        if ($token === '' || !hash_equals($expectedToken, $token)) {
            OperationLog::record('competitor', 'task_denied', '竞对任务领取失败: token无效', null, null, 'invalid_task_token', [
                'audit_type' => 'operation',
                'device_id' => $this->sanitizeExternalAuditText($deviceId),
                'platform' => $this->sanitizeExternalAuditText($platform),
            ]);
            return $this->apiError('token无效', 403);
        }
        if ($deviceId === '' || $platform === '') {
            OperationLog::record('competitor', 'task_denied', '竞对任务领取失败: 参数不完整', null, null, 'invalid_task_payload', [
                'audit_type' => 'operation',
                'device_id' => $this->sanitizeExternalAuditText($deviceId),
                'platform' => $this->sanitizeExternalAuditText($platform),
            ]);
            return $this->apiError('参数不完整', 400);
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

        OperationLog::record('competitor', 'task', '领取竞对采集任务: ' . count($data) . '条', null, null, null, [
            'audit_type' => 'acquisition',
            'device_id' => $this->sanitizeExternalAuditText($deviceId),
            'platform' => $this->sanitizeExternalAuditText($platform),
            'task_count' => count($data),
        ]);

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

        $rateLimitResponse = $this->enforceExternalRateLimit('report', $this->externalRateLimitIdentity($deviceId, $platform), 60, 60);
        if ($rateLimitResponse !== null) {
            return $rateLimitResponse;
        }

        $expectedToken = $this->getReportToken();
        if ($expectedToken === '') {
            OperationLog::record('competitor', 'report_denied', '竞对价格上报失败: 未配置Token', null, $hotelId > 0 ? $hotelId : null, 'missing_report_token', [
                'audit_type' => 'operation',
                'device_id' => $this->sanitizeExternalAuditText($deviceId),
                'platform' => $this->sanitizeExternalAuditText($platform),
            ]);
            return $this->apiError('未配置COMPETITOR_REPORT_TOKEN', 403);
        }

        if (!$this->isValidReportToken($expectedToken)) {
            OperationLog::record('competitor', 'report_denied', '竞对价格上报失败: report_token无效', null, $hotelId > 0 ? $hotelId : null, 'invalid_report_token', [
                'audit_type' => 'operation',
                'device_id' => $this->sanitizeExternalAuditText($deviceId),
                'platform' => $this->sanitizeExternalAuditText($platform),
            ]);
            return $this->apiError('report_token无效', 403);
        }

        if ($storeId <= 0 || $hotelId <= 0 || $platform === '' || $city === '' || $deviceId === '') {
            OperationLog::record('competitor', 'report_denied', '竞对价格上报失败: 参数不完整', null, $hotelId > 0 ? $hotelId : null, 'invalid_report_payload', [
                'audit_type' => 'operation',
                'device_id' => $this->sanitizeExternalAuditText($deviceId),
                'platform' => $this->sanitizeExternalAuditText($platform),
                'store_id' => $storeId,
            ]);
            return $this->apiError('参数不完整', 400);
        }

        $target = CompetitorHotel::where('id', $hotelId)
            ->where('store_id', $storeId)
            ->where('platform', $platform)
            ->where('status', 1)
            ->find();
        if (!$target) {
            OperationLog::record('competitor', 'report_denied', '竞对价格上报失败: 竞对酒店不存在或未启用', null, $hotelId, 'competitor_hotel_not_found', [
                'audit_type' => 'operation',
                'device_id' => $this->sanitizeExternalAuditText($deviceId),
                'platform' => $this->sanitizeExternalAuditText($platform),
                'store_id' => $storeId,
            ]);
            return $this->apiError('竞对酒店不存在或未启用', 403);
        }

        $city = (string)($target->city ?? $city);
        $price = $this->extractPrice($priceText);

        $screenshotPath = '';
        if ($base64 !== '') {
            $screenshotPath = $this->saveBase64Image($base64);
        }

        $log = new CompetitorPriceLog();
        $log->tenant_id = (int)($target->tenant_id ?? $target->store_id ?? 0);
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

        OperationLog::record('competitor', 'report', '上报竞对价格: ' . $hotelId, null, $hotelId, null, [
            'audit_type' => 'operation',
            'device_id' => $this->sanitizeExternalAuditText($deviceId),
            'platform' => $this->sanitizeExternalAuditText($platform),
            'store_id' => $storeId,
            'price' => $price,
            'price_log_id' => (int)$log->id,
        ]);

        return json(['code' => 200, 'message' => 'ok', 'data' => ['id' => $log->id]]);
    }

    private function isValidReportToken(string $expectedToken): bool
    {
        $token = trim((string)$this->request->post('report_token', ''));
        if ($token === '') {
            $token = trim((string)$this->request->header('X-Report-Token', ''));
        }
        if ($token === '') {
            $token = trim((string)$this->request->post('token', ''));
        }

        return $token !== '' && hash_equals($expectedToken, $token);
    }

    private function getReportToken(): string
    {
        return trim((string)env(self::REPORT_TOKEN_ENV, ''));
    }

    private function getTaskToken(): string
    {
        return trim((string)env(self::TASK_TOKEN_ENV, ''));
    }

    private function apiError(string $message, int $status): Response
    {
        return json(['code' => $status, 'message' => $message, 'data' => null], $status);
    }

    private function enforceExternalRateLimit(string $scope, string $identity, int $limit, int $window): ?Response
    {
        $identity = $identity !== '' ? $identity : (string)$this->request->ip();
        $key = sprintf('competitor_api_rate_%s_%s', $scope, sha1($identity . '|' . (string)$this->request->ip()));
        $count = (int)cache($key);

        if ($count >= $limit) {
            OperationLog::record('competitor', 'external_rate_limited', '竞对公开接口触发限流: ' . $scope, null, null, 'HTTP 429', [
                'audit_type' => 'operation',
                'scope' => $scope,
                'limit' => $limit,
                'window' => $window,
                'identity' => $this->sanitizeExternalAuditText($identity),
            ]);

            return json([
                'code' => 429,
                'message' => '请求过于频繁，请稍后再试',
                'data' => [
                    'retry_after' => $window,
                    'limit' => $limit,
                    'window' => $window,
                ],
            ], 429, ['Retry-After' => (string)$window]);
        }

        cache($key, $count + 1, $window + 5);
        return null;
    }

    private function externalRateLimitIdentity(string $deviceId, string $platform): string
    {
        $deviceId = $this->sanitizeExternalAuditText($deviceId);
        $platform = $this->sanitizeExternalAuditText($platform);

        return trim($deviceId . '|' . $platform, '|');
    }

    private function sanitizeExternalAuditText(string $value): string
    {
        $value = trim($value);
        if (mb_strlen($value, 'UTF-8') > 80) {
            return mb_substr($value, 0, 80, 'UTF-8') . '...';
        }

        return $value;
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
