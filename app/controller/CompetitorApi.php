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
    private const SCREENSHOT_MAX_BYTES = 2 * 1024 * 1024;
    private const SCREENSHOT_MAX_BASE64_CHARS = 2796404;
    private const SCREENSHOT_MAX_WIDTH = 8000;
    private const SCREENSHOT_MAX_HEIGHT = 8000;
    private const SCREENSHOT_MAX_PIXELS = 20000000;
    private const SCREENSHOT_ALLOWED_MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function task(): Response
    {
        $deviceId = (string)$this->request->post('device_id', '');
        $platform = (string)$this->request->post('platform', '');
        $token = $this->extractTaskToken();

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
            try {
                $screenshotPath = $this->saveBase64Image($base64);
            } catch (\InvalidArgumentException $e) {
                OperationLog::record('competitor', 'report_denied', '竞对价格上报失败: 截图格式不合规', null, $hotelId, 'invalid_report_screenshot', [
                    'audit_type' => 'operation',
                    'device_id' => $this->sanitizeExternalAuditText($deviceId),
                    'platform' => $this->sanitizeExternalAuditText($platform),
                    'store_id' => $storeId,
                ]);
                $status = $e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 400;
                return $this->apiError($e->getMessage(), $status);
            }
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
        $token = $this->extractReportToken();

        return $token !== '' && hash_equals($expectedToken, $token);
    }

    private function extractTaskToken(): string
    {
        return trim((string)$this->request->header('X-Task-Token', ''));
    }

    private function extractReportToken(): string
    {
        return trim((string)$this->request->header('X-Report-Token', ''));
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
        $ipHash = substr(sha1((string)$this->request->ip()), 0, 16);
        $key = sprintf('competitor_api_rate_%s_%s', $scope, $ipHash);
        $count = (int)cache($key);

        if ($count >= $limit) {
            OperationLog::record('competitor', 'external_rate_limited', '竞对公开接口触发限流: ' . $scope, null, null, 'HTTP 429', [
                'audit_type' => 'operation',
                'scope' => $scope,
                'limit' => $limit,
                'window' => $window,
                'identity' => $this->sanitizeExternalAuditText($identity),
                'ip_hash' => $ipHash,
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
        if ($value === '') {
            return '';
        }

        $patterns = [
            '/\bAuthorization\s*:\s*Bearer\s+[^\s,;]+/iu' => 'Authorization=****',
            '/\bBearer\s+[A-Za-z0-9._\-]{8,}/u' => 'Bearer ****',
            '/\b(cookie|token|authorization|password|secret|spidertoken|mtgsig|usersign|usertoken|api[_-]?key|access[_-]?key|key)\s*[:=]\s*["\']?[^"\'\s,;]+/iu' => '$1=****',
            '/([?&](?:token|key|api[_-]?key|authorization|spidertoken|mtgsig|usersign|usertoken)=)[^&#\s]+/iu' => '$1****',
            '/sk-[A-Za-z0-9_-]{8,}/u' => 'sk-****',
            '/(1[3-9]\d)\d{4}(\d{4})/u' => '$1****$2',
            '/\b\d{12,}\b/u' => '[编号已隐藏]',
            '/\s+/u' => ' ',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $value = preg_replace($pattern, $replacement, $value) ?? $value;
        }

        if (mb_strlen($value, 'UTF-8') > 80) {
            return mb_substr($value, 0, 80, 'UTF-8') . '...';
        }

        return $value;
    }

    private function extractPrice(string $text): float
    {
        if (preg_match('/[¥￥]\s*(\d+(?:\.\d+)?)/u', $text, $matches)) {
            return (float)$matches[1];
        }
        if (preg_match('/(?:价格|房价|售价|到手价|现价|低价|最低价|含税价|优惠价)[^\d]{0,12}(\d+(?:\.\d+)?)/u', $text, $matches)) {
            return (float)$matches[1];
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*元/u', $text, $matches)) {
            return (float)$matches[1];
        }
        if (preg_match('/(\d+(?:\.\d+)?)/u', $text, $matches)) {
            return (float)$matches[1];
        }
        return 0.0;
    }

    private function saveBase64Image(string $base64): string
    {
        [$data, $declaredMime] = $this->normalizeBase64ImagePayload($base64);
        $binary = base64_decode($data, true);
        if ($binary === false) {
            throw new \InvalidArgumentException('截图base64格式错误', 400);
        }

        if (strlen($binary) > self::SCREENSHOT_MAX_BYTES) {
            throw new \InvalidArgumentException('截图文件超过2MB', 413);
        }

        $imageInfo = @getimagesizefromstring($binary);
        if ($imageInfo === false || empty($imageInfo['mime'])) {
            throw new \InvalidArgumentException('截图必须是有效图片', 400);
        }

        $detectedMime = strtolower((string)$imageInfo['mime']);
        if (!isset(self::SCREENSHOT_ALLOWED_MIME_EXTENSIONS[$detectedMime])) {
            throw new \InvalidArgumentException('截图仅支持JPEG、PNG或WEBP格式', 415);
        }

        if ($declaredMime !== '' && $declaredMime !== $detectedMime) {
            throw new \InvalidArgumentException('截图声明格式与实际图片格式不一致', 400);
        }

        $width = (int)($imageInfo[0] ?? 0);
        $height = (int)($imageInfo[1] ?? 0);
        if ($width <= 0 || $height <= 0 || $width > self::SCREENSHOT_MAX_WIDTH || $height > self::SCREENSHOT_MAX_HEIGHT || ($width * $height) > self::SCREENSHOT_MAX_PIXELS) {
            throw new \InvalidArgumentException('截图尺寸超出限制', 413);
        }

        $datePath = date('Ymd');
        $dir = runtime_path() . 'upload/price/' . $datePath . '/';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $filename = uniqid('price_', true) . '.' . self::SCREENSHOT_ALLOWED_MIME_EXTENSIONS[$detectedMime];
        $path = $dir . $filename;
        if (file_put_contents($path, $binary) === false) {
            throw new \InvalidArgumentException('截图保存失败', 500);
        }

        return 'runtime/upload/price/' . $datePath . '/' . $filename;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function normalizeBase64ImagePayload(string $base64): array
    {
        $input = trim($base64);
        if ($input === '') {
            throw new \InvalidArgumentException('截图不能为空', 400);
        }

        $declaredMime = '';
        if (preg_match('/^data:([^;,]+);base64,(.*)$/s', $input, $matches)) {
            $declaredMime = strtolower(trim((string)$matches[1]));
            $input = (string)$matches[2];
            if (!isset(self::SCREENSHOT_ALLOWED_MIME_EXTENSIONS[$declaredMime])) {
                throw new \InvalidArgumentException('截图仅支持JPEG、PNG或WEBP格式', 415);
            }
        } elseif (strpos($input, ',') !== false) {
            throw new \InvalidArgumentException('截图Data URI格式错误', 400);
        }

        if (strlen($input) > self::SCREENSHOT_MAX_BASE64_CHARS) {
            throw new \InvalidArgumentException('截图base64内容超过限制', 413);
        }

        $input = str_replace(["\r", "\n", "\t", ' '], '', $input);
        if ($input === '' || strlen($input) > self::SCREENSHOT_MAX_BASE64_CHARS || !preg_match('/^[A-Za-z0-9+\/=]+$/', $input)) {
            throw new \InvalidArgumentException('截图base64格式错误', 400);
        }

        return [$input, $declaredMime];
    }
}
