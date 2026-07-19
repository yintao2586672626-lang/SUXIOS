<?php
declare(strict_types=1);

namespace app\controller;

use app\model\CompetitorDevice;
use app\model\CompetitorHotel;
use app\model\CompetitorPriceLog;
use app\model\OperationLog;
use app\service\CompetitorDeviceAuthService;
use app\service\CompetitorEventFeedService;
use app\service\CompetitorManualObservationService;
use app\service\HotelScopeService;
use think\facade\Db;
use think\Response;

class CompetitorApi extends Base
{
    private const TASK_ASSIGNMENT_TTL_SECONDS = 7200;
    private const COMPLETED_REPORT_TTL_SECONDS = 7200;
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

    /** @var array<string, int> */
    private array $taskLockDepth = [];

    public function events(): Response
    {
        $this->requireHotel();

        $rawSystemHotelId = $this->request->get(
            'system_hotel_id',
            $this->request->get('store_id', '')
        );
        if (!is_scalar($rawSystemHotelId)
            || preg_match('/^[1-9][0-9]*$/D', trim((string)$rawSystemHotelId)) !== 1
        ) {
            return $this->error('system_hotel_id/store_id must be a positive integer', 422);
        }
        $systemHotelId = (int)$rawSystemHotelId;
        $scope = new HotelScopeService();
        if (!$this->currentUser
            || !$scope->canAccessHotel(
                $this->currentUser,
                $systemHotelId,
                'can_view_online_data'
            )
        ) {
            return $this->error('无权查看此门店的竞争事件', 403);
        }

        try {
            $result = (new CompetitorEventFeedService())->build(
                $systemHotelId,
                $this->request->get('platform', 'all'),
                trim((string)$this->request->get('stay_date', $this->request->get('check_in_date', ''))),
                trim((string)$this->request->get('collected_at_start', '')),
                trim((string)$this->request->get('collected_at_end', '')),
                (int)$this->request->get('limit', 200)
            );
            $result['can_collect_manual_observation'] = $scope->hotelPermissionAllows(
                $this->currentUser,
                $systemHotelId,
                'ota.collect'
            );

            $message = match ((string)($result['status'] ?? '')) {
                'empty' => '暂无匹配的携程/美团竞争事件',
                'insufficient_evidence' => '竞争事件已读取，但证据不足',
                'partial' => '竞争事件已读取，部分样本证据不足',
                default => '携程/美团统一竞争事件已读取',
            };
            return $this->success($result, $message);
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            \think\facade\Log::error('Competitor event feed read failed.', [
                'exception_type' => get_debug_type($exception),
                'system_hotel_id' => $systemHotelId,
            ]);
            return $this->error('竞争事件读取失败', 500, [
                'reason' => 'competitor_event_feed_read_failed',
            ]);
        }
    }

    public function targets(): Response
    {
        $this->requireHotel();

        $rawSystemHotelId = $this->request->get(
            'system_hotel_id',
            $this->request->get('store_id', '')
        );
        if (!is_scalar($rawSystemHotelId)
            || preg_match('/^[1-9][0-9]*$/D', trim((string)$rawSystemHotelId)) !== 1
        ) {
            return $this->error('system_hotel_id/store_id must be a positive integer', 422);
        }
        $systemHotelId = (int)$rawSystemHotelId;
        if (!$this->currentUser
            || !(new HotelScopeService())->canAccessHotel(
                $this->currentUser,
                $systemHotelId,
                'can_view_online_data'
            )
        ) {
            return $this->error('无权查看此门店的竞品目标', 403);
        }

        $platform = strtolower(trim((string)$this->request->get('platform', 'all')));
        $platformAliases = match ($platform) {
            'all', '' => ['xc', 'ctrip', 'mt', 'meituan'],
            'xc', 'ctrip' => ['xc', 'ctrip'],
            'mt', 'meituan' => ['mt', 'meituan'],
            default => null,
        };
        if ($platformAliases === null) {
            return $this->error('platform only supports ctrip/xc and meituan/mt', 422);
        }

        $targets = CompetitorHotel::where('store_id', $systemHotelId)
            ->where('status', 1)
            ->whereIn('platform', $platformAliases)
            ->field('id,store_id,platform,city,hotel_name,hotel_code,status')
            ->order('platform', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        $targets = array_map(static function (array $target): array {
            $platform = strtolower(trim((string)($target['platform'] ?? '')));
            $canonicalPlatform = in_array($platform, ['xc', 'ctrip'], true) ? 'ctrip' : 'meituan';
            $hotelCode = trim((string)($target['hotel_code'] ?? ''));
            $otaHotelId = preg_match('/^[1-9][0-9]{0,19}$/D', $hotelCode) === 1 ? $hotelCode : null;
            return [
                'id' => (int)($target['id'] ?? 0),
                'system_hotel_id' => (int)($target['store_id'] ?? 0),
                'platform' => $canonicalPlatform,
                'city' => trim((string)($target['city'] ?? '')),
                'hotel_name' => trim((string)($target['hotel_name'] ?? '')),
                'ota_hotel_id' => $otaHotelId,
                'identity_status' => $otaHotelId !== null ? 'ota_hotel_id_configured' : 'public_name_only',
            ];
        }, $targets);

        return $this->success([
            'system_hotel_id' => $systemHotelId,
            'platform' => $platform === '' ? 'all' : $platform,
            'targets' => $targets,
            'target_count' => count($targets),
            'scope_notice' => '竞品目标仅属于当前宿析门店和携程/美团 OTA 渠道，不代表全酒店市场事实。',
        ], '竞品目标已读取');
    }

    public function manualObservation(): Response
    {
        $this->requireHotel();
        $data = $this->requestData();
        $rawSystemHotelId = $data['system_hotel_id'] ?? $data['store_id'] ?? '';
        $rawCompetitorHotelId = $data['competitor_hotel_id'] ?? $data['hotel_id'] ?? '';
        if (!is_scalar($rawSystemHotelId)
            || preg_match('/^[1-9][0-9]*$/D', trim((string)$rawSystemHotelId)) !== 1
            || !is_scalar($rawCompetitorHotelId)
            || preg_match('/^[1-9][0-9]*$/D', trim((string)$rawCompetitorHotelId)) !== 1
        ) {
            return $this->error('system_hotel_id and competitor_hotel_id must be positive integers', 422);
        }
        $systemHotelId = (int)$rawSystemHotelId;
        $competitorHotelId = (int)$rawCompetitorHotelId;
        $scope = new HotelScopeService();
        if (!$this->currentUser
            || !$scope->hotelPermissionAllows($this->currentUser, $systemHotelId, 'ota.collect')
        ) {
            return $this->error('无权为此门店保存 OTA 竞品观测', 403);
        }

        try {
            $saved = (new CompetitorManualObservationService())->persist(
                $systemHotelId,
                $competitorHotelId,
                (int)$this->currentUser->id,
                $data
            );
            $record = is_array($saved['record'] ?? null) ? $saved['record'] : [];
            $stayDate = trim((string)($record['check_in_date'] ?? ''));
            $collectedAt = trim((string)($record['collected_at'] ?? ''));
            $feed = (new CompetitorEventFeedService())->build(
                $systemHotelId,
                (string)($saved['canonical_platform'] ?? ''),
                $stayDate,
                $collectedAt,
                $collectedAt,
                500
            );
            $event = null;
            foreach ((array)($feed['events'] ?? []) as $candidate) {
                if ((int)($candidate['id'] ?? 0) === (int)$saved['id']) {
                    $event = $candidate;
                    break;
                }
            }
            if (!is_array($event) || ($event['readback_verified'] ?? false) !== true) {
                throw new \RuntimeException('已保存观测未能进入可回读事件流');
            }

            OperationLog::record(
                'competitor',
                'manual_observation',
                '人工核验并保存 OTA 公开竞品观测: ' . $competitorHotelId,
                (int)$this->currentUser->id,
                $systemHotelId,
                null,
                [
                    'audit_type' => 'acquisition',
                    'outcome' => 'success',
                    'system_hotel_id' => $systemHotelId,
                    'competitor_hotel_id' => $competitorHotelId,
                    'platform' => (string)($saved['canonical_platform'] ?? ''),
                    'price_log_id' => (int)$saved['id'],
                    'idempotent_replay' => (bool)$saved['idempotent_replay'],
                    'readback_verified' => true,
                    'availability_evidence_eligible' => (bool)($event['availability_evidence_eligible'] ?? false),
                    'price_evidence_eligible' => (bool)($event['price_evidence_eligible'] ?? false),
                ]
            );

            return $this->success([
                'id' => (int)$saved['id'],
                'idempotent_replay' => (bool)$saved['idempotent_replay'],
                'readback_verified' => true,
                'availability_evidence_eligible' => (bool)($event['availability_evidence_eligible'] ?? false),
                'price_evidence_eligible' => (bool)($event['price_evidence_eligible'] ?? false),
                'decision_eligible' => (bool)($event['price_evidence_eligible'] ?? false),
                'event' => $event,
                'scope_notice' => '人工核验的 OTA 公开起价只形成渠道可售事件；同口径信息不完整时不进入收益定价。',
            ], (bool)$saved['idempotent_replay'] ? '相同观测已存在，已完成回读' : '真实公开观测已保存并回读');
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422, [
                'reason' => 'competitor_manual_observation_invalid',
            ]);
        } catch (\Throwable $exception) {
            \think\facade\Log::error('Competitor manual observation persistence failed.', [
                'exception_type' => get_debug_type($exception),
                'system_hotel_id' => $systemHotelId,
                'competitor_hotel_id' => $competitorHotelId,
            ]);
            return $this->error('真实竞品观测保存或回读失败', 500, [
                'reason' => 'competitor_manual_observation_persistence_failed',
            ]);
        }
    }

    public function task(): Response
    {
        $deviceId = trim((string)$this->request->post('device_id', ''));
        $platform = trim((string)$this->request->post('platform', ''));
        $storeId = (int)$this->request->post('store_id', 0);
        $token = $this->extractTaskToken();

        $rateLimitResponse = $this->enforceExternalRateLimit('task', $this->externalRateLimitIdentity($deviceId, $platform), 30, 60);
        if ($rateLimitResponse !== null) {
            return $rateLimitResponse;
        }

        if ($deviceId === '' || $platform === '' || $storeId <= 0) {
            OperationLog::record('competitor', 'task_denied', '竞对任务领取失败: 参数不完整', null, null, 'invalid_task_payload', [
                'audit_type' => 'operation',
                'outcome' => 'denied',
                'device_id' => $this->sanitizeExternalAuditText($deviceId),
                'platform' => $this->sanitizeExternalAuditText($platform),
                'requested_store_id' => $storeId,
            ]);
            return $this->apiError('参数不完整', 400);
        }
        if (!in_array($platform, CompetitorHotel::platformCodes(), true)) {
            OperationLog::record('competitor', 'task_denied', '竞对任务领取失败: 平台不受支持', null, null, 'unsupported_platform', [
                'audit_type' => 'operation',
                'outcome' => 'denied',
                'device_id' => $this->sanitizeExternalAuditText($deviceId),
                'platform' => $this->sanitizeExternalAuditText($platform),
                'requested_store_id' => $storeId,
            ]);
            return $this->apiError('平台不受支持', 400);
        }

        $device = (new CompetitorDeviceAuthService())->findAuthorizedBinding(
            $deviceId,
            $platform,
            $storeId,
            $token
        );
        if (!$device) {
            OperationLog::record('competitor', 'task_denied', '竞对任务领取失败: 设备绑定或Token无效', null, null, 'device_binding_invalid', [
                'audit_type' => 'operation',
                'outcome' => 'denied',
                'device_id' => $this->sanitizeExternalAuditText($deviceId),
                'platform' => $this->sanitizeExternalAuditText($platform),
                'requested_store_id' => $storeId,
            ]);
            return $this->apiError('设备未绑定该门店、已停用或Token无效', 403);
        }

        $now = date('Y-m-d H:i:s');
        $oneHourAgo = date('Y-m-d H:i:s', strtotime('-1 hour'));
        $device->last_time = $now;
        $device->save();

        $tenantId = (int)$device->tenant_id;
        $bindingId = (int)$device->id;
        $tokenVersion = max(1, (int)$device->token_version);
        $query = CompetitorHotel::where('status', 1)
            ->where('platform', $platform)
            ->where('store_id', $storeId)
            ->where('tenant_id', $tenantId);

        // 同一酒店每小时只抓1次
        $query->whereNotExists(function ($sub) use ($oneHourAgo, $platform) {
            $sub->table('competitor_price_log')
                ->whereColumn('competitor_price_log.hotel_id', 'competitor_hotel.id')
                ->whereColumn('competitor_price_log.store_id', 'competitor_hotel.store_id')
                ->where('competitor_price_log.platform', $platform)
                ->where('competitor_price_log.fetch_time', '>=', $oneHourAgo);
        });

        $list = $query->limit(5)->select()->toArray();

        $defaultCheckInDate = date('Y-m-d', strtotime('+1 day'));
        $defaultCheckOutDate = date('Y-m-d', strtotime('+2 days'));
        $data = array_map(function ($item) use ($defaultCheckInDate, $defaultCheckOutDate) {
            return [
                'store_id' => (int)$item['store_id'],
                'hotel_id' => (int)$item['id'],
                'city' => $item['city'],
                'hotel_name' => $item['hotel_name'],
                'platform' => $item['platform'],
                'ota_hotel_id' => trim((string)($item['hotel_code'] ?? '')),
                'capture_scope' => [
                    'check_in_date' => $defaultCheckInDate,
                    'check_out_date' => $defaultCheckOutDate,
                    'adults' => 2,
                    'children' => 0,
                    'currency' => 'CNY',
                    'price_basis' => 'per_room_per_night',
                    'availability_values' => ['available', 'bookable', 'unavailable', 'sold_out'],
                ],
            ];
        }, $list);

        // Claim each task before returning it. A task owned by another active
        // device is skipped, so concurrent pollers never receive the same job.
        $data = array_values(array_filter($data, function (array $item) use ($deviceId, $bindingId, $tokenVersion): bool {
            return $this->rememberTaskAssignment(
                $deviceId,
                (string)($item['platform'] ?? ''),
                (int)($item['store_id'] ?? 0),
                (int)($item['hotel_id'] ?? 0),
                $bindingId,
                $tokenVersion
            );
        }));

        $rememberedAssignments = [];
        foreach ($data as $item) {
            $storeId = (int)($item['store_id'] ?? 0);
            $hotelId = (int)($item['hotel_id'] ?? 0);
            $itemPlatform = (string)($item['platform'] ?? '');
            if (!$this->rememberTaskAssignment($deviceId, $itemPlatform, $storeId, $hotelId, $bindingId, $tokenVersion)) {
                foreach ($rememberedAssignments as $assignment) {
                    cache($this->taskAssignmentCacheKey(
                        $deviceId,
                        (string)$assignment['platform'],
                        (int)$assignment['store_id'],
                        (int)$assignment['hotel_id'],
                        $bindingId,
                        $tokenVersion
                    ), null);
                }
                OperationLog::record('competitor', 'task_denied', '竞对任务领取失败: 无法保存设备任务归属', null, $storeId, 'task_assignment_unavailable', [
                    'audit_type' => 'operation',
                    'outcome' => 'failed',
                    'tenant_id' => $tenantId,
                    'device_id' => $this->sanitizeExternalAuditText($deviceId),
                    'platform' => $this->sanitizeExternalAuditText($platform),
                    'store_id' => $storeId,
                    'binding_id' => $bindingId,
                    'token_version' => $tokenVersion,
                ]);
                return $this->apiError('任务归属暂不可用，请稍后重试', 503);
            }
            $rememberedAssignments[] = $item;
        }

        OperationLog::record('competitor', 'task', '领取竞对采集任务: ' . count($data) . '条', null, $storeId, null, [
            'audit_type' => 'acquisition',
            'outcome' => 'success',
            'tenant_id' => $tenantId,
            'device_id' => $this->sanitizeExternalAuditText($deviceId),
            'platform' => $this->sanitizeExternalAuditText($platform),
            'store_id' => $storeId,
            'binding_id' => $bindingId,
            'token_version' => $tokenVersion,
            'task_count' => count($data),
            'target_competitor_hotel_ids' => array_values(array_map(
                static fn(array $item): int => (int)($item['hotel_id'] ?? 0),
                $data
            )),
        ]);

        return json(['code' => 200, 'message' => 'ok', 'data' => $data]);
    }

    public function report(): Response
    {
        $storeId = (int)$this->request->post('store_id', 0);
        $hotelId = (int)$this->request->post('hotel_id', 0);
        $platform = (string)$this->request->post('platform', '');
        if ($storeId <= 0 || $hotelId <= 0 || trim($platform) === '') {
            return $this->reportLegacy();
        }

        return $this->withTaskAssignmentLock(
            $platform,
            $storeId,
            $hotelId,
            fn(): Response => $this->reportLegacy()
        );
    }

    private function reportLegacy(): Response
    {
        $storeId = (int)$this->request->post('store_id', 0);
        $hotelId = (int)$this->request->post('hotel_id', 0);
        $platform = trim((string)$this->request->post('platform', ''));
        $city = trim((string)$this->request->post('city', ''));
        $priceText = (string)$this->request->post('price_text', '');
        $base64 = (string)$this->request->post('base64', '');
        $deviceId = trim((string)$this->request->post('device_id', ''));

        $rateLimitResponse = $this->enforceExternalRateLimit('report', $this->externalRateLimitIdentity($deviceId, $platform), 60, 60);
        if ($rateLimitResponse !== null) {
            return $rateLimitResponse;
        }

        if ($storeId <= 0 || $hotelId <= 0 || $platform === '' || $city === '' || $deviceId === '') {
            OperationLog::record('competitor', 'report_denied', '竞对价格上报失败: 参数不完整', null, null, 'invalid_report_payload', [
                'audit_type' => 'operation',
                'outcome' => 'denied',
                'device_id' => $this->sanitizeExternalAuditText($deviceId),
                'platform' => $this->sanitizeExternalAuditText($platform),
                'requested_store_id' => $storeId,
                'competitor_hotel_id' => $hotelId,
            ]);
            return $this->apiError('参数不完整', 400);
        }
        if (!in_array($platform, CompetitorHotel::platformCodes(), true)) {
            OperationLog::record('competitor', 'report_denied', '竞对价格上报失败: 平台不受支持', null, null, 'unsupported_platform', [
                'audit_type' => 'operation',
                'outcome' => 'denied',
                'device_id' => $this->sanitizeExternalAuditText($deviceId),
                'platform' => $this->sanitizeExternalAuditText($platform),
                'requested_store_id' => $storeId,
                'competitor_hotel_id' => $hotelId,
            ]);
            return $this->apiError('平台不受支持', 400);
        }

        $device = CompetitorDevice::where('device_id', $deviceId)
            ->where('platform', $platform)
            ->where('store_id', $storeId)
            ->where('status', 1)
            ->whereNull('revoked_at')
            ->find();
        if (!$device) {
            OperationLog::record('competitor', 'report_denied', '竞对价格上报失败: 设备未登记或已停用', null, null, 'device_not_active', [
                'audit_type' => 'operation',
                'outcome' => 'denied',
                'device_id' => $this->sanitizeExternalAuditText($deviceId),
                'platform' => $this->sanitizeExternalAuditText($platform),
                'requested_store_id' => $storeId,
                'competitor_hotel_id' => $hotelId,
            ]);
            return $this->apiError('设备未绑定该门店或已停用', 403);
        }

        $expectedToken = (string)($device->token_hash ?? '');
        if (!$this->isValidReportToken($expectedToken)) {
            OperationLog::record('competitor', 'report_denied', '竞对价格上报失败: 设备Token无效', null, $storeId, 'invalid_report_token', [
                'audit_type' => 'operation',
                'outcome' => 'denied',
                'tenant_id' => (int)$device->tenant_id,
                'device_id' => $this->sanitizeExternalAuditText($deviceId),
                'platform' => $this->sanitizeExternalAuditText($platform),
                'store_id' => $storeId,
                'competitor_hotel_id' => $hotelId,
            ]);
            return $this->apiError('设备Token无效', 403);
        }
        if (!(new CompetitorDeviceAuthService())->bindingScopeIsActive($device)) {
            OperationLog::record('competitor', 'report_denied', '竞对价格上报失败: 设备绑定权限已失效', null, $storeId, 'device_scope_inactive', [
                'audit_type' => 'operation',
                'outcome' => 'denied',
                'tenant_id' => (int)$device->tenant_id,
                'device_id' => $this->sanitizeExternalAuditText($deviceId),
                'platform' => $this->sanitizeExternalAuditText($platform),
                'store_id' => $storeId,
                'competitor_hotel_id' => $hotelId,
            ]);
            return $this->apiError('设备绑定用户已无该门店采集权限', 403);
        }

        $tenantId = (int)$device->tenant_id;
        $bindingId = (int)$device->id;
        $tokenVersion = max(1, (int)$device->token_version);
        $target = CompetitorHotel::where('id', $hotelId)
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('platform', $platform)
            ->where('status', 1)
            ->find();
        if (!$target) {
            OperationLog::record('competitor', 'report_denied', '竞对价格上报失败: 竞对酒店不存在或未启用', null, $storeId, 'competitor_hotel_not_found', [
                'audit_type' => 'operation',
                'outcome' => 'denied',
                'tenant_id' => $tenantId,
                'device_id' => $this->sanitizeExternalAuditText($deviceId),
                'platform' => $this->sanitizeExternalAuditText($platform),
                'store_id' => $storeId,
                'competitor_hotel_id' => $hotelId,
            ]);
            return $this->apiError('竞对酒店不存在或未启用', 403);
        }

        $city = (string)($target->city ?? $city);
        $availability = strtolower(trim((string)$this->request->post('availability', '')));
        $extractedPrice = $this->extractPrice($priceText);
        $price = $this->isValidReportPrice($extractedPrice) ? $extractedPrice : null;
        if ($price === null && !$this->allowsMissingPriceForAvailability($availability)) {
            OperationLog::record('competitor', 'report_denied', '竞对事件上报失败: 可订状态未识别到有效价格', null, $storeId, 'invalid_report_price', [
                'audit_type' => 'operation',
                'outcome' => 'denied',
                'tenant_id' => $tenantId,
                'device_id' => $this->sanitizeExternalAuditText($deviceId),
                'platform' => $this->sanitizeExternalAuditText($platform),
                'store_id' => $storeId,
                'competitor_hotel_id' => $hotelId,
                'price_text' => $this->sanitizeExternalAuditText($priceText),
                'availability' => $this->sanitizeExternalAuditText($availability),
            ]);
            return $this->apiError('可订事件必须包含有效竞对价格；仅售罄或不可订事件可不含价格', 400);
        }
        $rateContext = $this->normalizeCompetitorRateContext([
            'ota_hotel_id' => $this->request->post('ota_hotel_id', ''),
            'collected_at' => $this->request->post('collected_at', ''),
            'source_method' => $this->request->post('source_method', ''),
            'source_ref' => $this->request->post('source_ref', ''),
            'check_in_date' => $this->request->post('check_in_date', ''),
            'check_out_date' => $this->request->post('check_out_date', ''),
            'adults' => $this->request->post('adults', null),
            'children' => $this->request->post('children', null),
            'room_type_key' => $this->request->post('room_type_key', ''),
            'ota_product_id' => $this->request->post('ota_product_id', ''),
            'rate_plan_key' => $this->request->post('rate_plan_key', ''),
            'package_name' => $this->request->post('package_name', ''),
            'breakfast' => $this->request->post('breakfast', ''),
            'cancellation_policy' => $this->request->post('cancellation_policy', ''),
            'payment_mode' => $this->request->post('payment_mode', ''),
            'tax_fee_included' => $this->request->post('tax_fee_included', null),
            'price_basis' => $this->request->post('price_basis', ''),
            'currency' => $this->request->post('currency', ''),
            'availability' => $availability,
        ], $platform, $price);

        $reportFingerprint = $this->reportFingerprint($deviceId, $platform, $storeId, $hotelId, $price, $base64, $availability);
        if (!$this->hasTaskAssignment($deviceId, $platform, $storeId, $hotelId, $bindingId, $tokenVersion)) {
            $completedReport = $this->completedReport($deviceId, $platform, $storeId, $hotelId, $reportFingerprint, $bindingId, $tokenVersion);
            if ($completedReport !== null) {
                OperationLog::record('competitor', 'report_replayed', '竞对价格上报命中幂等结果', null, $storeId, null, [
                    'audit_type' => 'operation',
                    'outcome' => 'success',
                    'tenant_id' => $tenantId,
                    'device_id' => $this->sanitizeExternalAuditText($deviceId),
                    'platform' => $this->sanitizeExternalAuditText($platform),
                    'store_id' => $storeId,
                    'competitor_hotel_id' => $hotelId,
                    'binding_id' => $bindingId,
                    'token_version' => $tokenVersion,
                    'price_log_id' => (int)$completedReport['id'],
                    'idempotent_replay' => true,
                ]);
                return json([
                    'code' => 200,
                    'message' => 'ok',
                    'data' => [
                        'id' => (int)$completedReport['id'],
                        'idempotent_replay' => true,
                    ],
                ]);
            }

            OperationLog::record('competitor', 'report_denied', '竞对价格上报失败: 设备未领取该任务或任务已过期', null, $storeId, 'task_assignment_missing', [
                'audit_type' => 'operation',
                'outcome' => 'denied',
                'tenant_id' => $tenantId,
                'device_id' => $this->sanitizeExternalAuditText($deviceId),
                'platform' => $this->sanitizeExternalAuditText($platform),
                'store_id' => $storeId,
                'competitor_hotel_id' => $hotelId,
                'binding_id' => $bindingId,
                'token_version' => $tokenVersion,
            ]);
            return $this->apiError('该任务未由当前设备领取或已过期，请重新领取任务', 403);
        }

        $screenshotPath = '';
        if ($base64 !== '') {
            try {
                $screenshotPath = $this->saveBase64Image($base64);
            } catch (\InvalidArgumentException $e) {
                OperationLog::record('competitor', 'report_denied', '竞对价格上报失败: 截图格式不合规', null, $storeId, 'invalid_report_screenshot', [
                    'audit_type' => 'operation',
                    'outcome' => 'denied',
                    'tenant_id' => $tenantId,
                    'device_id' => $this->sanitizeExternalAuditText($deviceId),
                    'platform' => $this->sanitizeExternalAuditText($platform),
                    'store_id' => $storeId,
                    'competitor_hotel_id' => $hotelId,
                    'binding_id' => $bindingId,
                    'token_version' => $tokenVersion,
                ]);
                $status = $e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 400;
                return $this->apiError($e->getMessage(), $status);
            }
        }

        if (!$this->consumeTaskAssignment($deviceId, $platform, $storeId, $hotelId, $bindingId, $tokenVersion)) {
            $this->removeSavedScreenshot($screenshotPath);
            $completedReport = $this->completedReport($deviceId, $platform, $storeId, $hotelId, $reportFingerprint, $bindingId, $tokenVersion);
            if ($completedReport !== null) {
                OperationLog::record('competitor', 'report_replayed', '竞对价格上报命中幂等结果', null, $storeId, null, [
                    'audit_type' => 'operation',
                    'outcome' => 'success',
                    'tenant_id' => $tenantId,
                    'device_id' => $this->sanitizeExternalAuditText($deviceId),
                    'platform' => $this->sanitizeExternalAuditText($platform),
                    'store_id' => $storeId,
                    'competitor_hotel_id' => $hotelId,
                    'binding_id' => $bindingId,
                    'token_version' => $tokenVersion,
                    'price_log_id' => (int)$completedReport['id'],
                    'idempotent_replay' => true,
                ]);
                return json([
                    'code' => 200,
                    'message' => 'ok',
                    'data' => [
                        'id' => (int)$completedReport['id'],
                        'idempotent_replay' => true,
                    ],
                ]);
            }
            OperationLog::record('competitor', 'report_denied', '竞对价格上报失败: 任务正在处理或已完成', null, $storeId, 'task_assignment_conflict', [
                'audit_type' => 'operation',
                'outcome' => 'denied',
                'tenant_id' => $tenantId,
                'device_id' => $this->sanitizeExternalAuditText($deviceId),
                'platform' => $this->sanitizeExternalAuditText($platform),
                'store_id' => $storeId,
                'competitor_hotel_id' => $hotelId,
                'binding_id' => $bindingId,
                'token_version' => $tokenVersion,
            ]);
            return $this->apiError('该任务正在处理或已完成，请勿重复上报', 409);
        }

        try {
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
            if ($this->competitorRateComparabilitySchemaReady()) {
                foreach ($rateContext as $field => $value) {
                    $log->{$field} = $value;
                }
                $log->readback_verified = 0;
            }
            $log->save();

            if ($this->competitorRateComparabilitySchemaReady()) {
                $readback = CompetitorPriceLog::where('id', (int)$log->id)
                    ->where('store_id', $storeId)
                    ->where('hotel_id', $hotelId)
                    ->find();
                $readbackPrice = $readback ? $readback->getData('price') : null;
                $priceReadbackMatches = $price === null
                    ? $readbackPrice === null
                    : is_numeric($readbackPrice) && abs((float)$readbackPrice - $price) < 0.001;
                $readbackVerified = $readback
                    && $priceReadbackMatches
                    && (string)($readback->content_hash ?? '') === (string)$rateContext['content_hash'];
                $log->readback_verified = $readbackVerified ? 1 : 0;
                if (!$readbackVerified) {
                    $log->validation_status = 'failed';
                    $log->failure_reason = 'saved_row_readback_mismatch';
                }
                $log->save();
            }
        } catch (\Throwable $e) {
            $this->rememberTaskAssignment($deviceId, $platform, $storeId, $hotelId, $bindingId, $tokenVersion);
            $this->removeSavedScreenshot($screenshotPath);
            try {
                OperationLog::record('competitor', 'report_failed', '竞对价格上报持久化失败', null, $storeId, 'report_persist_failed:' . get_debug_type($e), [
                    'audit_type' => 'operation',
                    'outcome' => 'failed',
                    'tenant_id' => $tenantId,
                    'device_id' => $this->sanitizeExternalAuditText($deviceId),
                    'platform' => $this->sanitizeExternalAuditText($platform),
                    'store_id' => $storeId,
                    'competitor_hotel_id' => $hotelId,
                    'binding_id' => $bindingId,
                    'token_version' => $tokenVersion,
                    'failure_stage' => 'persistence',
                ]);
            } catch (\Throwable $auditError) {
                \think\facade\Log::error('Competitor report audit persistence failed.', [
                    'exception_type' => get_debug_type($auditError),
                    'store_id' => $storeId,
                    'competitor_hotel_id' => $hotelId,
                ]);
            }
            throw $e;
        }

        $this->rememberCompletedReport($deviceId, $platform, $storeId, $hotelId, $reportFingerprint, (int)$log->id, $bindingId, $tokenVersion);

        // 更新设备在线时间
        $device->last_time = date('Y-m-d H:i:s');
        $device->save();

        $comparabilityReady = $this->competitorRateComparabilitySchemaReady();
        $readbackVerified = !$comparabilityReady || (int)($log->readback_verified ?? 0) === 1;
        $auditError = $readbackVerified ? null : 'saved_row_readback_mismatch';
        OperationLog::record('competitor', $auditError === null ? 'report' : 'report_failed', '上报竞对价格/可售事件: ' . $hotelId, null, $storeId, $auditError, [
            'audit_type' => 'operation',
            'outcome' => $auditError === null ? 'success' : 'failed',
            'tenant_id' => $tenantId,
            'device_id' => $this->sanitizeExternalAuditText($deviceId),
            'platform' => $this->sanitizeExternalAuditText($platform),
            'store_id' => $storeId,
            'competitor_hotel_id' => $hotelId,
            'binding_id' => $bindingId,
            'token_version' => $tokenVersion,
            'price' => $price,
            'price_log_id' => (int)$log->id,
            'validation_status' => $comparabilityReady
                ? (string)($log->validation_status ?? 'unverified')
                : 'schema_pending',
            'readback_verified' => $readbackVerified,
        ]);

        $availabilityEvidenceEligible = $comparabilityReady
            && (string)($log->validation_status ?? '') === 'valid'
            && (int)($log->readback_verified ?? 0) === 1
            && trim((string)($log->availability_scope_key ?? '')) !== ''
            && $this->isValidAvailabilityStatus((string)($log->availability ?? ''));
        $priceEvidenceEligible = $availabilityEvidenceEligible
            && $this->isBookableAvailability((string)($log->availability ?? ''))
            && trim((string)($log->comparison_key ?? '')) !== ''
            && $price !== null;

        return json(['code' => 200, 'message' => 'ok', 'data' => [
            'id' => $log->id,
            'validation_status' => $this->competitorRateComparabilitySchemaReady()
                ? (string)($log->validation_status ?? 'unverified')
                : 'schema_pending',
            'readback_verified' => $this->competitorRateComparabilitySchemaReady()
                ? (int)($log->readback_verified ?? 0) === 1
                : false,
            'availability_evidence_eligible' => $availabilityEvidenceEligible,
            'price_evidence_eligible' => $priceEvidenceEligible,
            'decision_eligible' => $priceEvidenceEligible,
        ]]);
    }

    private function isValidReportToken(string $expectedToken): bool
    {
        $token = $this->extractReportToken();

        return (new CompetitorDeviceAuthService())->verifyTokenHash($token, $expectedToken);
    }

    private function extractTaskToken(): string
    {
        return trim((string)$this->request->header('X-Task-Token', ''));
    }

    private function extractReportToken(): string
    {
        return trim((string)$this->request->header('X-Report-Token', ''));
    }

    private function taskAssignmentCacheKey(
        string $deviceId,
        string $platform,
        int $storeId,
        int $hotelId,
        int $bindingId = 0,
        int $tokenVersion = 0
    ): string
    {
        return 'competitor_task_assignment_v2_' . $this->hashScopeValues([
            trim($deviceId),
            trim($platform),
            (string)$storeId,
            (string)$hotelId,
            (string)$bindingId,
            (string)$tokenVersion,
        ]);
    }

    private function taskOwnershipCacheKey(string $platform, int $storeId, int $hotelId): string
    {
        return 'competitor_task_owner_v2_' . $this->hashScopeValues([
            trim($platform),
            (string)$storeId,
            (string)$hotelId,
        ]);
    }

    private function completedReportCacheKey(
        string $deviceId,
        string $platform,
        int $storeId,
        int $hotelId,
        int $bindingId = 0,
        int $tokenVersion = 0
    ): string
    {
        return $this->taskAssignmentCacheKey(
            $deviceId,
            $platform,
            $storeId,
            $hotelId,
            $bindingId,
            $tokenVersion
        ) . '_completed';
    }

    private function rememberTaskAssignment(
        string $deviceId,
        string $platform,
        int $storeId,
        int $hotelId,
        int $bindingId = 0,
        int $tokenVersion = 0
    ): bool
    {
        if ($deviceId === '' || $platform === '' || $storeId <= 0 || $hotelId <= 0) {
            return false;
        }

        return $this->withTaskAssignmentLock($platform, $storeId, $hotelId, function () use ($deviceId, $platform, $storeId, $hotelId, $bindingId, $tokenVersion): bool {
            $ownerKey = $this->taskOwnershipCacheKey($platform, $storeId, $hotelId);
            $owner = cache($ownerKey);
            if (is_array($owner)
                && !hash_equals((string)($owner['device_id'] ?? ''), $deviceId)
            ) {
                return false;
            }

            $assignment = [
                'device_id' => $deviceId,
                'platform' => $platform,
                'store_id' => $storeId,
                'hotel_id' => $hotelId,
                'binding_id' => $bindingId,
                'token_version' => $tokenVersion,
                'issued_at' => time(),
            ];
            if (!cache($ownerKey, $assignment, self::TASK_ASSIGNMENT_TTL_SECONDS)) {
                return false;
            }
            $stored = cache(
                $this->taskAssignmentCacheKey($deviceId, $platform, $storeId, $hotelId, $bindingId, $tokenVersion),
                $assignment,
                self::TASK_ASSIGNMENT_TTL_SECONDS
            );
            if (!$stored) {
                cache($ownerKey, null);
                return false;
            }
            cache($this->completedReportCacheKey($deviceId, $platform, $storeId, $hotelId, $bindingId, $tokenVersion), null);
            return true;
        });
    }

    private function hasTaskAssignment(
        string $deviceId,
        string $platform,
        int $storeId,
        int $hotelId,
        int $bindingId = 0,
        int $tokenVersion = 0
    ): bool
    {
        $assignment = cache($this->taskAssignmentCacheKey($deviceId, $platform, $storeId, $hotelId, $bindingId, $tokenVersion));
        $owner = cache($this->taskOwnershipCacheKey($platform, $storeId, $hotelId));

        return is_array($assignment)
            && is_array($owner)
            && hash_equals((string)($assignment['device_id'] ?? ''), $deviceId)
            && hash_equals((string)($owner['device_id'] ?? ''), $deviceId)
            && hash_equals((string)($assignment['platform'] ?? ''), $platform)
            && (int)($assignment['store_id'] ?? 0) === $storeId
            && (int)($assignment['hotel_id'] ?? 0) === $hotelId
            && (int)($assignment['binding_id'] ?? -1) === $bindingId
            && (int)($assignment['token_version'] ?? -1) === $tokenVersion
            && (int)($owner['binding_id'] ?? -1) === $bindingId
            && (int)($owner['token_version'] ?? -1) === $tokenVersion;
    }

    private function consumeTaskAssignment(
        string $deviceId,
        string $platform,
        int $storeId,
        int $hotelId,
        int $bindingId = 0,
        int $tokenVersion = 0
    ): bool
    {
        return $this->withTaskAssignmentLock($platform, $storeId, $hotelId, function () use ($deviceId, $platform, $storeId, $hotelId, $bindingId, $tokenVersion): bool {
            if (!$this->hasTaskAssignment($deviceId, $platform, $storeId, $hotelId, $bindingId, $tokenVersion)) {
                return false;
            }
            $assignmentDeleted = (bool)cache(
                $this->taskAssignmentCacheKey($deviceId, $platform, $storeId, $hotelId, $bindingId, $tokenVersion),
                null
            );
            $ownerDeleted = (bool)cache($this->taskOwnershipCacheKey($platform, $storeId, $hotelId), null);
            return $assignmentDeleted && $ownerDeleted;
        });
    }

    private function withTaskAssignmentLock(string $platform, int $storeId, int $hotelId, callable $callback): mixed
    {
        $lockKey = $this->hashScopeValues([trim($platform), (string)$storeId, (string)$hotelId]);
        if (($this->taskLockDepth[$lockKey] ?? 0) > 0) {
            return $callback();
        }

        $dir = rtrim(runtime_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'competitor-task-locks';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('competitor task lock directory is unavailable');
        }
        $handle = fopen($dir . DIRECTORY_SEPARATOR . $lockKey . '.lock', 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new \RuntimeException('competitor task lock is unavailable');
        }

        $this->taskLockDepth[$lockKey] = 1;
        try {
            return $callback();
        } finally {
            unset($this->taskLockDepth[$lockKey]);
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function reportFingerprint(
        string $deviceId,
        string $platform,
        int $storeId,
        int $hotelId,
        ?float $price,
        string $base64,
        string $availability = ''
    ): string {
        return $this->hashScopeValues([
            $deviceId,
            $platform,
            (string)$storeId,
            (string)$hotelId,
            $price === null ? 'price:null' : number_format($price, 2, '.', ''),
            strtolower(trim($availability)),
            $base64 === '' ? '' : hash('sha256', $base64),
        ]);
    }

    /** @param array<int, string> $values */
    private function hashScopeValues(array $values): string
    {
        $payload = '';
        foreach ($values as $value) {
            $payload .= strlen($value) . ':' . $value . ';';
        }

        return hash('sha256', $payload);
    }

    private function rememberCompletedReport(
        string $deviceId,
        string $platform,
        int $storeId,
        int $hotelId,
        string $fingerprint,
        int $logId,
        int $bindingId = 0,
        int $tokenVersion = 0
    ): void {
        cache($this->completedReportCacheKey($deviceId, $platform, $storeId, $hotelId, $bindingId, $tokenVersion), [
            'fingerprint' => $fingerprint,
            'id' => $logId,
            'completed_at' => time(),
        ], self::COMPLETED_REPORT_TTL_SECONDS);
    }

    /** @return array{fingerprint: string, id: int, completed_at: int}|null */
    private function completedReport(
        string $deviceId,
        string $platform,
        int $storeId,
        int $hotelId,
        string $fingerprint,
        int $bindingId = 0,
        int $tokenVersion = 0
    ): ?array {
        $completed = cache($this->completedReportCacheKey($deviceId, $platform, $storeId, $hotelId, $bindingId, $tokenVersion));
        if (!is_array($completed)
            || (int)($completed['id'] ?? 0) <= 0
            || !hash_equals((string)($completed['fingerprint'] ?? ''), $fingerprint)) {
            return null;
        }

        return [
            'fingerprint' => (string)$completed['fingerprint'],
            'id' => (int)$completed['id'],
            'completed_at' => (int)($completed['completed_at'] ?? 0),
        ];
    }

    private function removeSavedScreenshot(string $relativePath): void
    {
        if ($relativePath === '' || !str_starts_with($relativePath, 'runtime/upload/price/')) {
            return;
        }

        $absolutePath = root_path() . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
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

    /** @return array<string,mixed> */
    private function normalizeCompetitorRateContext(array $input, string $platform, ?float $price): array
    {
        $string = fn(string $key, int $limit): string => $this->limitExternalText((string)($input[$key] ?? ''), $limit);
        $checkIn = $this->normalizeExternalDate((string)($input['check_in_date'] ?? ''));
        $checkOut = $this->normalizeExternalDate((string)($input['check_out_date'] ?? ''));
        $collectedAt = $this->normalizeExternalDateTime((string)($input['collected_at'] ?? ''));
        $adults = is_numeric($input['adults'] ?? null) ? (int)$input['adults'] : null;
        $children = is_numeric($input['children'] ?? null) ? (int)$input['children'] : null;
        $taxIncluded = $this->normalizeExternalBoolean($input['tax_fee_included'] ?? null);
        $availability = strtolower($string('availability', 32));
        $currency = strtoupper($string('currency', 3));
        $sourceRef = $this->sanitizeExternalSourceRef((string)($input['source_ref'] ?? ''));
        $nights = $checkIn !== null && $checkOut !== null && strtotime($checkOut) > strtotime($checkIn)
            ? (int)((strtotime($checkOut) - strtotime($checkIn)) / 86400)
            : null;

        $context = [
            'ota_hotel_id' => $string('ota_hotel_id', 80),
            'collected_at' => $collectedAt,
            'source_method' => $string('source_method', 40),
            'source_ref' => $sourceRef,
            'check_in_date' => $checkIn,
            'check_out_date' => $checkOut,
            'nights' => $nights,
            'adults' => $adults,
            'children' => $children,
            'room_type_key' => $string('room_type_key', 160),
            'ota_product_id' => $string('ota_product_id', 120),
            'rate_plan_key' => $string('rate_plan_key', 160),
            'package_name' => $string('package_name', 160),
            'breakfast' => $string('breakfast', 80),
            'cancellation_policy' => $string('cancellation_policy', 500),
            'payment_mode' => $string('payment_mode', 80),
            'tax_fee_included' => $taxIncluded,
            'price_basis' => $string('price_basis', 80),
            'currency' => $currency,
            'availability' => $availability,
        ];

        $availabilityMissing = [];
        foreach (['collected_at', 'source_method', 'source_ref', 'check_in_date', 'check_out_date', 'currency', 'availability'] as $field) {
            if ($context[$field] === null || trim((string)$context[$field]) === '') {
                $availabilityMissing[] = $field;
            }
        }
        if ($nights === null || $nights <= 0) {
            $availabilityMissing[] = 'valid_stay_window';
        }
        if ($adults === null || $adults <= 0) {
            $availabilityMissing[] = 'adults';
        }
        if ($children === null || $children < 0) {
            $availabilityMissing[] = 'children';
        }
        if (!$this->isValidAvailabilityStatus($availability)) {
            $availabilityMissing[] = 'ota_channel_availability_status';
        }

        $rateDimensionMissing = $availabilityMissing;
        foreach (['room_type_key', 'rate_plan_key', 'breakfast', 'cancellation_policy', 'payment_mode', 'price_basis'] as $field) {
            if ($context[$field] === null || trim((string)$context[$field]) === '') {
                $rateDimensionMissing[] = $field;
            }
        }
        if ($taxIncluded === null) {
            $rateDimensionMissing[] = 'tax_fee_included';
        }

        $availabilityFields = [
            strtolower(trim($platform)), $context['ota_hotel_id'], $context['source_method'],
            $context['source_ref'], $checkIn, $checkOut, $context['room_type_key'],
            $context['ota_product_id'], $context['rate_plan_key'], $context['price_basis'],
            $currency, $adults, $children,
        ];
        $comparisonFields = [
            strtolower(trim($platform)), $context['ota_hotel_id'], $context['source_method'],
            $context['source_ref'], $checkIn, $checkOut, $context['room_type_key'],
            $context['ota_product_id'],
            $context['rate_plan_key'], $context['breakfast'], $context['cancellation_policy'],
            $context['payment_mode'], $context['package_name'], $taxIncluded, $context['price_basis'], $currency,
            $adults, $children,
        ];
        $context['availability_scope_key'] = $availabilityMissing === []
            ? hash('sha256', implode('|', array_map(static fn(mixed $value): string => strtolower(trim((string)$value)), $availabilityFields)))
            : '';
        $context['comparison_key'] = $rateDimensionMissing === []
            ? hash('sha256', implode('|', array_map(static fn(mixed $value): string => strtolower(trim((string)$value)), $comparisonFields)))
            : '';
        $requiredMissing = $this->isBookableAvailability($availability)
            ? $rateDimensionMissing
            : $availabilityMissing;
        if ($this->isBookableAvailability($availability) && !$this->isValidReportPrice((float)$price)) {
            $requiredMissing[] = 'price';
        }
        $requiredMissing = array_values(array_unique($requiredMissing));
        $context['validation_status'] = $requiredMissing === [] ? 'valid' : 'incomplete';
        $context['failure_reason'] = $requiredMissing === []
            ? ''
            : 'missing_event_evidence_fields:' . implode(',', $requiredMissing);
        $context['content_hash'] = hash('sha256', json_encode(
            ['platform' => strtolower(trim($platform)), 'price' => $price, 'context' => $context],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));

        return $context;
    }

    private function competitorRateComparabilitySchemaReady(): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        try {
            $ready = !empty(Db::query("SHOW COLUMNS FROM `competitor_price_log` LIKE 'comparison_key'"))
                && !empty(Db::query("SHOW COLUMNS FROM `competitor_price_log` LIKE 'availability_scope_key'"))
                && !empty(Db::query("SHOW COLUMNS FROM `competitor_price_log` LIKE 'readback_verified'"));
        } catch (\Throwable) {
            $ready = false;
        }
        return $ready;
    }

    private function normalizeExternalDate(string $value): ?string
    {
        $value = trim($value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))) {
            return null;
        }
        return $date->format('Y-m-d') === $value ? $value : null;
    }

    private function normalizeExternalDateTime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || strtotime($value) === false) {
            return null;
        }
        return date('Y-m-d H:i:s', strtotime($value));
    }

    private function normalizeExternalBoolean(mixed $value): ?int
    {
        if ($value === true || $value === 1 || in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes'], true)) {
            return 1;
        }
        if ($value === false || $value === 0 || in_array(strtolower(trim((string)$value)), ['0', 'false', 'no'], true)) {
            return 0;
        }
        return null;
    }

    private function sanitizeExternalSourceRef(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $parts = parse_url($value);
        if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
            $safe = strtolower((string)$parts['scheme']) . '://' . (string)$parts['host'];
            if (isset($parts['port'])) {
                $safe .= ':' . (int)$parts['port'];
            }
            $safe .= (string)($parts['path'] ?? '');
            return $this->limitExternalText($safe, 500);
        }
        return $this->limitExternalText($this->sanitizeExternalAuditText($value), 500);
    }

    private function limitExternalText(string $value, int $length): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        return mb_strlen($value, 'UTF-8') > $length
            ? mb_substr($value, 0, $length, 'UTF-8')
            : $value;
    }

    private function extractPrice(string $text): float
    {
        $normalizedText = $this->normalizePriceText($text);
        $amountPattern = '(\d{1,3}(?:[,，]\d{3})+(?:\.\d+)?|\d+(?:\.\d+)?)';
        if (preg_match('/[¥￥]\s*' . $amountPattern . '/u', $normalizedText, $matches)) {
            return $this->normalizeExtractedPrice($matches[1]);
        }
        if (preg_match('/(?:价格|房价|售价|到手价|现价|低价|最低价|含税价|优惠价)[^\d]{0,12}' . $amountPattern . '/u', $normalizedText, $matches)) {
            return $this->normalizeExtractedPrice($matches[1]);
        }
        if (preg_match('/' . $amountPattern . '\s*元/u', $normalizedText, $matches)) {
            return $this->normalizeExtractedPrice($matches[1]);
        }
        if (preg_match('/' . $amountPattern . '\s*(?:\/\s*)?(?:晚|夜|间夜|起)/u', $normalizedText, $matches)) {
            return $this->normalizeExtractedPrice($matches[1]);
        }
        if (preg_match('/^\s*' . $amountPattern . '\s*$/u', $normalizedText, $matches)) {
            return $this->normalizeExtractedPrice($matches[1]);
        }
        return 0.0;
    }

    private function isValidReportPrice(float $price): bool
    {
        return $price > 0.0;
    }

    private function isValidAvailabilityStatus(string $availability): bool
    {
        return in_array(strtolower(trim($availability)), ['available', 'bookable', 'unavailable', 'sold_out'], true);
    }

    private function isBookableAvailability(string $availability): bool
    {
        return in_array(strtolower(trim($availability)), ['available', 'bookable'], true);
    }

    private function allowsMissingPriceForAvailability(string $availability): bool
    {
        return in_array(strtolower(trim($availability)), ['unavailable', 'sold_out'], true);
    }

    private function normalizePriceText(string $text): string
    {
        return strtr($text, [
            '０' => '0',
            '１' => '1',
            '２' => '2',
            '３' => '3',
            '４' => '4',
            '５' => '5',
            '６' => '6',
            '７' => '7',
            '８' => '8',
            '９' => '9',
            '，' => ',',
            '．' => '.',
        ]);
    }

    private function normalizeExtractedPrice(string $value): float
    {
        return (float)str_replace([',', '，'], '', $value);
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
