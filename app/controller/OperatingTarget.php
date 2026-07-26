<?php
declare(strict_types=1);

namespace app\controller;

use app\model\Hotel;
use app\model\OperationLog;
use app\service\DingdandaoOperatingTargetCaptureService;
use app\service\OperatingTargetReportGateService;
use app\service\OperatingTargetService;
use think\Response;

/** Whole-hotel daily operating target entry, history, and preview. */
final class OperatingTarget extends Base
{
    public function current(): Response
    {
        [$hotelId, $tenantId] = $this->authorizedScope('can_view_report');
        $targetDate = (string)$this->request->get('target_date', '');
        try {
            $current = (new OperatingTargetService())->current($tenantId, $hotelId, $targetDate);
            $current['pms_source_status'] = (new DingdandaoOperatingTargetCaptureService())
                ->latest($tenantId, $hotelId, $targetDate);
            return $this->success($current);
        } catch (\InvalidArgumentException) {
            return $this->error('请选择有效的目标日期', 422);
        }
    }

    public function history(): Response
    {
        [$hotelId, $tenantId] = $this->authorizedScope('can_view_report');
        $limit = (int)$this->request->get('limit', 60);
        return $this->success((new OperatingTargetService())->history($tenantId, $hotelId, $limit));
    }

    public function snapshots(): Response
    {
        [$hotelId, $tenantId] = $this->authorizedScope('can_view_report');
        $targetDate = (string)$this->request->get('target_date', '');
        $limit = (int)$this->request->get('limit', 20);
        try {
            return $this->success(
                (new OperatingTargetService())->snapshotHistory($tenantId, $hotelId, $targetDate, $limit)
            );
        } catch (\InvalidArgumentException) {
            return $this->error('请选择有效的目标日期', 422);
        }
    }

    public function prefillDailyReport(): Response
    {
        [$hotelId, $tenantId] = $this->authorizedScope('can_view_report');
        $targetDate = (string)$this->request->get('target_date', '');
        try {
            return $this->success((new OperatingTargetService())->prefillFromDailyReport($tenantId, $hotelId, $targetDate));
        } catch (\InvalidArgumentException) {
            return $this->error('请选择有效的目标日期', 422);
        }
    }

    public function dingdandaoStatus(): Response
    {
        [$hotelId, $tenantId] = $this->authorizedScope('can_view_report');
        $targetDate = (string)$this->request->get('target_date', '');
        try {
            return $this->success(
                (new DingdandaoOperatingTargetCaptureService())
                    ->latest($tenantId, $hotelId, $targetDate)
            );
        } catch (\InvalidArgumentException) {
            return $this->error('请选择有效的目标日期', 422);
        }
    }

    public function prefillDingdandao(): Response
    {
        [$hotelId, $tenantId] = $this->authorizedScope('can_view_report');
        $targetDate = (string)$this->request->get('target_date', '');
        try {
            return $this->success(
                (new DingdandaoOperatingTargetCaptureService())
                    ->prefill($tenantId, $hotelId, $targetDate)
            );
        } catch (\InvalidArgumentException) {
            return $this->error('请选择有效的目标日期', 422);
        }
    }

    /**
     * Accept a sanitized browser-assist snapshot. The caller may provide only
     * operating metrics, source trace and room-fee rows; session material and
     * raw account responses are not accepted by this boundary.
     */
    public function saveDingdandaoCapture(): Response
    {
        $input = $this->requestData();
        [$hotelId, $tenantId] = $this->authorizedScope('can_fill_daily_report', $input);
        $hotel = Hotel::find($hotelId);
        try {
            $capture = (new DingdandaoOperatingTargetCaptureService())->save(
                $tenantId,
                $hotelId,
                (int)$this->currentUser->id,
                (string)($hotel->name ?? '未命名酒店'),
                $input
            );
            OperationLog::record(
                'operating_target',
                'dingdandao_capture',
                '保存订单来了今日住宿经营事实 ' . (string)($capture['business_date'] ?? ''),
                (int)$this->currentUser->id,
                $hotelId,
                null,
                [
                    'outcome' => ($capture['quality_status'] ?? '') === 'verified'
                        ? 'success'
                        : 'partial',
                    'capture_id' => (int)($capture['id'] ?? 0),
                    'capture_status' => $capture['capture_status'] ?? 'unverified',
                    'quality_status' => $capture['quality_status'] ?? 'unverified',
                    'identity_status' => $capture['identity_status'] ?? 'unverified',
                    'readback_status' => $capture['readback_status'] ?? 'unverified',
                ]
            );
            return $this->success(
                $capture,
                ($capture['quality_status'] ?? '') === 'verified'
                    ? '订单来了今日住宿数据已保存、对账并完成回读'
                    : '订单来了数据已保存为阻断状态，未进入经营目标与推送'
            );
        } catch (\InvalidArgumentException $error) {
            return $this->error($this->dingdandaoInputError($error->getMessage()), 422);
        } catch (\Throwable) {
            return $this->error('订单来了数据保存或回读失败，未标记为成功', 500);
        }
    }

    public function save(): Response
    {
        $input = $this->requestData();
        [$hotelId, $tenantId] = $this->authorizedScope('can_fill_daily_report', $input);
        $service = new OperatingTargetService();
        try {
            $targetDate = (string)($input['target_date'] ?? '');
            if ($service->exists($tenantId, $hotelId, $targetDate)) {
                $this->currentUser->hasHotelPermissionOrFail($hotelId, 'can_edit_report', '您没有该酒店的经营目标编辑权限');
            }
            $result = $service->save($tenantId, $hotelId, (int)$this->currentUser->id, $input);
            OperationLog::record(
                'operating_target',
                'save',
                '保存每日经营目标 ' . $targetDate,
                (int)$this->currentUser->id,
                $hotelId,
                null,
                [
                    'outcome' => $result['status'] === 'blocked' ? 'partial' : 'success',
                    'target_date' => $targetDate,
                    'calculation_status' => $result['status'],
                    'source_type' => $result['record']['facts']['source_type'] ?? null,
                ]
            );
            return $this->success($result, '经营目标已保存并完成回读');
        } catch (\InvalidArgumentException $error) {
            return $this->error($this->inputErrorMessage($error->getMessage()), 422);
        } catch (\Throwable) {
            return $this->error('经营目标保存失败，未生成成功回执', 500);
        }
    }

    public function preview(): Response
    {
        [$hotelId, $tenantId] = $this->authorizedScope('can_view_report');
        $targetDate = (string)$this->request->get('target_date', '');
        try {
            $current = (new OperatingTargetService())->current($tenantId, $hotelId, $targetDate);
            return $this->success($current['report_preview']);
        } catch (\InvalidArgumentException) {
            return $this->error('请选择有效的目标日期', 422);
        }
    }

    /**
     * Render the report and its formal-send decision without calling any
     * external delivery channel.
     */
    public function reportPreview(): Response
    {
        [$hotelId, $tenantId] = $this->authorizedScope('can_view_report');
        $targetDate = (string)$this->request->get('target_date', '');
        try {
            $current = (new OperatingTargetService())->current($tenantId, $hotelId, $targetDate);
            $hotel = Hotel::find($hotelId);
            $gate = (new OperatingTargetReportGateService())->pagePreview(
                (array)$current['report_preview'],
                (string)($hotel->name ?? '未命名酒店')
            );
            return $this->success([
                'operating_target_preview' => $current['report_preview'],
                'gate' => $gate,
            ]);
        } catch (\InvalidArgumentException) {
            return $this->error('请选择有效的目标日期', 422);
        }
    }

    /**
     * Accept an explicitly double-confirmed test-only request. The injected
     * transport intentionally records no Webhook and never sends externally.
     */
    public function requestTestPush(): Response
    {
        $input = $this->requestData();
        [$hotelId, $tenantId] = $this->authorizedScope('can_fill_daily_report', $input);
        $targetDate = (string)($input['target_date'] ?? '');
        try {
            $service = new OperatingTargetService();
            $current = $service->current($tenantId, $hotelId, $targetDate);
            $hotel = Hotel::find($hotelId);
            $preview = (array)$current['report_preview'];
            $gate = new OperatingTargetReportGateService(
                null,
                static function (array $payload, array $context): array {
                    $receiptSeed = json_encode([
                        'purpose' => $context['purpose'] ?? '',
                        'hotel_id' => $context['hotel_id'] ?? 0,
                        'target_date' => $context['target_date'] ?? '',
                        'preview_fingerprint' => $context['preview_fingerprint'] ?? '',
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                    return [
                        'request_accepted' => true,
                        'delivery_attempted' => false,
                        'delivery_status' => OperatingTargetReportGateService::TEST_ONLY_REQUEST_STATUS,
                        'receipt_id' => 'operating-target-test-only-' . substr(hash('sha256', (string)$receiptSeed), 0, 20),
                        'message' => '测试请求已受理：仅生成并核对酒店80的漠蓝测试负载；本轮未读取Webhook、未向企业微信外发。',
                    ];
                }
            );
            $fingerprint = trim((string)($input['preview_fingerprint'] ?? ''));
            $result = $gate->authorizedTestPush($preview, (string)($hotel->name ?? '未命名酒店'), [
                'approved' => (($input['first_confirmation'] ?? false) === true)
                    && (($input['second_confirmation'] ?? false) === true),
                'purpose' => OperatingTargetReportGateService::TEST_PUSH_PURPOSE,
                'actor_id' => (int)$this->currentUser->id,
                'approval_reference' => 'operating-target-ui-double-confirm:'
                    . $hotelId . ':' . $targetDate . ':' . substr($fingerprint, 0, 16),
                'test_destination' => '酒店80 / 1号漠蓝测试机器人 / test_only',
                'test_only' => ($input['test_only'] ?? false) === true,
                'target_robot_id' => (int)($input['target_robot_id'] ?? 0),
                'target_robot_name' => (string)($input['target_robot_name'] ?? ''),
                'first_confirmation' => ($input['first_confirmation'] ?? false) === true,
                'second_confirmation' => ($input['second_confirmation'] ?? false) === true,
                'preview_fingerprint' => $fingerprint,
            ]);

            if ((string)($result['delivery_status'] ?? '') !== OperatingTargetReportGateService::TEST_ONLY_REQUEST_STATUS) {
                $blockers = (array)($result['authorization_blockers'] ?? []);
                $message = (string)($blockers[0]['message'] ?? '测试推送请求未通过门禁。');
                return $this->error($message, 422, $result);
            }

            return $this->success($result, '测试推送请求已受理；本轮仅 test_only 演练，未向企业微信外发。');
        } catch (\InvalidArgumentException) {
            return $this->error('请选择有效的目标日期', 422);
        }
    }

    /** @return array{0:int,1:int} */
    private function authorizedScope(string $capability, ?array $input = null): array
    {
        if (!$this->currentUser) {
            abort(401, '请先登录');
        }
        $input ??= $this->requestData();
        $hotelId = (int)($this->request->get('hotel_id', 0) ?: ($input['hotel_id'] ?? 0));
        if ($hotelId <= 0) {
            abort(422, '请选择门店');
        }
        $hotel = Hotel::find($hotelId);
        if (!$hotel) {
            abort(404, '门店不存在或当前账号无权访问');
        }
        $tenantId = (int)$hotel->tenant_id;
        if ($tenantId <= 0) {
            abort(422, '门店缺少有效租户归属，不能保存经营目标');
        }
        if (!$this->currentUser->isSuperAdmin()) {
            $this->currentUser->hasHotelPermissionOrFail($hotelId, $capability, '当前账号没有该门店的经营目标权限');
        }
        return [$hotelId, $tenantId];
    }

    private function inputErrorMessage(string $code): string
    {
        return match ($code) {
            'operating_target_date_invalid' => '目标日期格式无效',
            'operating_target_scope_invalid' => '经营目标只接受全酒店或住宿房费口径，不能使用单一OTA渠道替代。',
            'operating_target_source_invalid' => '经营事实来源类型无效',
            'operating_target_quality_invalid' => '数据质量状态无效',
            default => '经营目标输入不合法，请检查金额、间夜和日期。',
        };
    }

    private function dingdandaoInputError(string $code): string
    {
        return match ($code) {
            'dingdandao_capture_date_invalid' => '订单来了统计日期格式无效',
            'dingdandao_capture_time_invalid' => '订单来了采集时间无效',
            'dingdandao_capture_source_url_invalid' => '只允许订单来了住宿数据中心的已授权只读来源',
            'dingdandao_capture_source_api_invalid' => '接口来源必须是已验证的订单来了同源只读路径',
            'dingdandao_capture_method_invalid' => '采集方式无效',
            'dingdandao_capture_detail_limit_exceeded' => '房费明细行数超过当前单次采集上限',
            'dingdandao_capture_detail_invalid' => '房费明细格式无效',
            default => '订单来了经营指标输入无效；未知值请保持为空，不能用0补齐',
        };
    }
}
