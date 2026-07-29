<?php
declare(strict_types=1);

namespace app\controller;

use app\model\Hotel;
use app\model\OperationLog;
use app\service\DingdandaoOperatingTargetCaptureService;
use app\service\DingdandaoPmsIntegrationService;
use app\service\MeituanCloudPmsCaptureService;
use app\service\MeituanCloudPmsIntegrationService;
use app\service\OperatingTargetAutomationService;
use app\service\OperatingTargetReportGateService;
use app\service\OperatingTargetService;
use app\service\PmsFactReconciliationService;
use app\service\PmsRealtimeSyncService;
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
            $dingdandaoCaptures = new DingdandaoOperatingTargetCaptureService();
            $meituanCloudCaptures = new MeituanCloudPmsCaptureService();
            $current['pms_source_status'] = $dingdandaoCaptures
                ->latest($tenantId, $hotelId, $targetDate);
            $current['pms_source_statuses'] = [
                DingdandaoOperatingTargetCaptureService::PROVIDER => $current['pms_source_status'],
                MeituanCloudPmsCaptureService::PROVIDER => $meituanCloudCaptures
                    ->latest($tenantId, $hotelId, $targetDate),
            ];
            $current['pms_reconciliation'] = (new PmsFactReconciliationService())->summarize(
                $hotelId,
                $targetDate,
                $current['pms_source_statuses'],
                [
                    DingdandaoOperatingTargetCaptureService::PROVIDER => $dingdandaoCaptures
                        ->history($tenantId, $hotelId, $targetDate, 20),
                    MeituanCloudPmsCaptureService::PROVIDER => $meituanCloudCaptures
                        ->history($tenantId, $hotelId, $targetDate, 20),
                ]
            );
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

    public function syncSelectedPmsRealtime(): Response
    {
        $input = $this->requestData();
        [$hotelId, $tenantId] = $this->authorizedScope('can_fill_daily_report', $input);
        $targetDate = trim((string)($input['target_date'] ?? ''));
        try {
            $result = (new PmsRealtimeSyncService())->sync(
                $tenantId,
                $hotelId,
                (int)$this->currentUser->id,
                $targetDate
            );
            OperationLog::record(
                'operating_target',
                'pms_realtime_sync',
                '手动同步当前门店 PMS 实时数据 ' . $targetDate,
                (int)$this->currentUser->id,
                $hotelId,
                null,
                [
                    'outcome' => ($result['status'] ?? '') === 'synced' ? 'success' : 'blocked',
                    'provider' => (string)($result['provider'] ?? ''),
                    'target_date' => (string)($result['target_date'] ?? ''),
                    'capture_id' => (int)($result['capture_id'] ?? 0),
                    'readback_verified' => ($result['readback_verified'] ?? false) === true,
                    'blocker_code' => (string)($result['blocker_code'] ?? ''),
                ]
            );
            return $this->success($result, (string)($result['message'] ?? 'PMS 实时同步已处理'));
        } catch (\Throwable) {
            return $this->error('PMS 实时同步执行失败，本次未把旧快照标记为新数据', 500);
        }
    }

    public function prefillDingdandao(): Response
    {
        [$hotelId, $tenantId] = $this->authorizedScope('can_view_report');
        $targetDate = (string)$this->request->get('target_date', '');
        try {
            return $this->success(
                (new DingdandaoPmsIntegrationService())
                    ->prefill(
                        $tenantId,
                        $hotelId,
                        (int)$this->currentUser->id,
                        $targetDate
                    )
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
            $integration = new DingdandaoPmsIntegrationService();
            $expectation = $integration->captureExpectation(
                $tenantId,
                $hotelId,
                (string)($hotel->name ?? '未命名酒店')
            );
            $capture = (new DingdandaoOperatingTargetCaptureService())->save(
                $tenantId,
                $hotelId,
                (int)$this->currentUser->id,
                (string)$expectation['expected_provider_hotel_name'],
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

    public function dingdandaoIntegrationStatus(): Response
    {
        [$hotelId, $tenantId] = $this->authorizedScope('can_view_report');
        $targetDate = (string)$this->request->get('target_date', '');
        try {
            return $this->success(
                (new DingdandaoPmsIntegrationService())->status(
                    $tenantId,
                    $hotelId,
                    (int)$this->currentUser->id,
                    $targetDate
                )
            );
        } catch (\InvalidArgumentException) {
            return $this->error('请选择有效的门店和经营日期', 422);
        }
    }

    public function saveDingdandaoIntegration(): Response
    {
        $input = $this->requestData();
        [$hotelId, $tenantId] = $this->authorizedScope('can_fill_daily_report', $input);
        try {
            $result = (new DingdandaoPmsIntegrationService())->save(
                $tenantId,
                $hotelId,
                (int)$this->currentUser->id,
                $input,
                (string)($input['target_date'] ?? '')
            );
            OperationLog::record(
                'operating_target',
                'dingdandao_pms_config',
                '维护订单来了门店绑定与企业微信推送策略',
                (int)$this->currentUser->id,
                $hotelId,
                null,
                [
                    'outcome' => 'success',
                    'provider' => DingdandaoOperatingTargetCaptureService::PROVIDER,
                    'status' => ($result['config']['status'] ?? false) === true,
                    'auto_push_enabled' => ($result['config']['auto_push_enabled'] ?? false) === true,
                    'robot_id' => $result['config']['robot_id'] ?? null,
                ]
            );
            return $this->success($result, '订单来了接口绑定与推送策略已保存并回读');
        } catch (\InvalidArgumentException $error) {
            return $this->error($this->dingdandaoIntegrationInputError($error->getMessage()), 422);
        } catch (\Throwable $error) {
            $message = $error->getMessage() === 'dingdandao_pms_tables_missing'
                ? '订单来了接口维护表尚未安装，请先执行数据库迁移'
                : '订单来了接口绑定保存失败，未生成成功回执';
            return $this->error($message, 500);
        }
    }

    public function meituanCloudStatus(): Response
    {
        [$hotelId, $tenantId] = $this->authorizedScope('can_view_report');
        $targetDate = (string)$this->request->get('target_date', '');
        try {
            return $this->success(
                (new MeituanCloudPmsCaptureService())
                    ->latest($tenantId, $hotelId, $targetDate)
            );
        } catch (\InvalidArgumentException) {
            return $this->error('请选择有效的目标日期', 422);
        }
    }

    public function prefillMeituanCloud(): Response
    {
        [$hotelId, $tenantId] = $this->authorizedScope('can_view_report');
        $targetDate = (string)$this->request->get('target_date', '');
        try {
            return $this->success(
                (new MeituanCloudPmsIntegrationService())
                    ->prefill(
                        $tenantId,
                        $hotelId,
                        (int)$this->currentUser->id,
                        $targetDate
                    )
            );
        } catch (\InvalidArgumentException) {
            return $this->error('请选择有效的目标日期', 422);
        }
    }

    /**
     * Manual/API submissions are stored as unverified evidence. Only the
     * protected cloud collection runner may call the service in verified mode.
     */
    public function saveMeituanCloudCapture(): Response
    {
        $input = $this->requestData();
        [$hotelId, $tenantId] = $this->authorizedScope('can_fill_daily_report', $input);
        $hotel = Hotel::find($hotelId);
        try {
            $integration = new MeituanCloudPmsIntegrationService();
            $expectation = $integration->captureExpectation(
                $tenantId,
                $hotelId,
                (string)($hotel->name ?? '未命名酒店')
            );
            $capture = (new MeituanCloudPmsCaptureService())->save(
                $tenantId,
                $hotelId,
                (int)$this->currentUser->id,
                (string)$expectation['expected_provider_hotel_name'],
                $input
            );
            $integration->recordCapture(
                $tenantId,
                $hotelId,
                (int)$this->currentUser->id,
                $capture
            );
            OperationLog::record(
                'operating_target',
                'meituan_cloud_pms_capture',
                '保存美团云 PMS 当日实时经营事实 ' . (string)($capture['business_date'] ?? ''),
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
                    'date_status' => $capture['date_status'] ?? 'unverified',
                    'readback_status' => $capture['readback_status'] ?? 'unverified',
                ]
            );
            return $this->success(
                $capture,
                ($capture['quality_status'] ?? '') === 'verified'
                    ? '美团云 PMS 数据已保存、对账并完成回读'
                    : '美团云 PMS 数据已保存为未验证状态，未进入经营目标'
            );
        } catch (\InvalidArgumentException $error) {
            return $this->error($this->meituanCloudInputError($error->getMessage()), 422);
        } catch (\Throwable $error) {
            $message = $error->getMessage() === 'meituan_cloud_capture_tables_missing'
                ? '美团云 PMS 事实表尚未安装，请先执行数据库迁移'
                : '美团云 PMS 数据保存或回读失败，未标记为成功';
            return $this->error($message, 500);
        }
    }

    public function meituanCloudIntegrationStatus(): Response
    {
        [$hotelId, $tenantId] = $this->authorizedScope('can_view_report');
        $targetDate = (string)$this->request->get('target_date', '');
        try {
            return $this->success(
                (new MeituanCloudPmsIntegrationService())->status(
                    $tenantId,
                    $hotelId,
                    (int)$this->currentUser->id,
                    $targetDate
                )
            );
        } catch (\InvalidArgumentException) {
            return $this->error('请选择有效的门店和经营日期', 422);
        }
    }

    public function saveMeituanCloudIntegration(): Response
    {
        $input = $this->requestData();
        [$hotelId, $tenantId] = $this->authorizedScope('can_fill_daily_report', $input);
        try {
            $result = (new MeituanCloudPmsIntegrationService())->save(
                $tenantId,
                $hotelId,
                (int)$this->currentUser->id,
                $input,
                (string)($input['target_date'] ?? '')
            );
            OperationLog::record(
                'operating_target',
                'meituan_cloud_pms_config',
                '维护美团云 PMS 独立门店数据源绑定',
                (int)$this->currentUser->id,
                $hotelId,
                null,
                [
                    'outcome' => 'success',
                    'provider' => MeituanCloudPmsCaptureService::PROVIDER,
                    'status' => ($result['config']['status'] ?? false) === true,
                    'provider_hotel_id' => $result['config']['provider_hotel_id'] ?? null,
                ]
            );
            return $this->success($result, '美团云 PMS 独立数据源绑定已保存并回读');
        } catch (\InvalidArgumentException $error) {
            return $this->error($this->meituanCloudIntegrationInputError($error->getMessage()), 422);
        } catch (\Throwable $error) {
            $message = $error->getMessage() === 'meituan_cloud_pms_table_missing'
                ? '美团云 PMS 接口维护表尚未安装，请先执行数据库迁移'
                : '美团云 PMS 数据源绑定保存失败，未生成成功回执';
            return $this->error($message, 500);
        }
    }

    public function pushDingdandaoVerifiedCapture(): Response
    {
        $input = $this->requestData();
        [$hotelId, $tenantId] = $this->authorizedScope('can_fill_daily_report', $input);
        if (($input['confirmed'] ?? false) !== true) {
            return $this->error('请确认当前操作会向所选企业微信群发送真实消息', 422);
        }
        $targetDate = (string)($input['target_date'] ?? '');
        $hotel = Hotel::find($hotelId);
        try {
            $integration = new DingdandaoPmsIntegrationService();
            $prefill = $integration->prefill(
                $tenantId,
                $hotelId,
                (int)$this->currentUser->id,
                $targetDate
            );
            if (($prefill['status'] ?? '') === 'blocked') {
                $blocked = [
                    'delivery_status' => 'blocked',
                    'delivery_attempted' => false,
                    'blockers' => (array)($prefill['gaps'] ?? []),
                    'fact_gate' => $prefill['fact_gate'] ?? null,
                ];
                $message = (string)($blocked['blockers'][0]['message']
                    ?? '订单来了数据尚未通过当前门店身份门禁');
                return $this->error($message, 422, $blocked);
            }
            $capture = is_array($prefill['capture'] ?? null)
                ? $prefill['capture']
                : [];
            $targetSync = $integration->syncVerifiedCapture(
                $tenantId,
                $hotelId,
                (int)$this->currentUser->id,
                (int)($capture['id'] ?? 0)
            );
            if (($targetSync['sync_status'] ?? '') === 'blocked') {
                $blocked = [
                    'delivery_status' => 'blocked',
                    'delivery_attempted' => false,
                    'blockers' => (array)($targetSync['gaps'] ?? []),
                    'operating_target_sync' => $targetSync,
                ];
                $message = (string)($blocked['blockers'][0]['message']
                    ?? '订单来了事实未能按当前门店绑定同步到经营目标');
                return $this->error($message, 422, $blocked);
            }
            $result = $integration->dispatchVerifiedCapture(
                $tenantId,
                $hotelId,
                (int)$this->currentUser->id,
                (string)($hotel->name ?? '未命名酒店'),
                $capture,
                'manual',
                ($input['retry_failed'] ?? false) === true
            );
            $result['operating_target_sync'] = $targetSync;
            if (($result['delivery_status'] ?? '') === 'blocked') {
                $message = (string)($result['blockers'][0]['message']
                    ?? '订单来了数据尚未通过发送门禁');
                return $this->error($message, 422, $result);
            }
            OperationLog::record(
                'operating_target',
                'dingdandao_pms_wecom_push',
                '发送订单来了已验证经营事实 ' . $targetDate,
                (int)$this->currentUser->id,
                $hotelId,
                null,
                [
                    'outcome' => in_array(
                        (string)($result['delivery_status'] ?? ''),
                        ['sent', 'already_sent'],
                        true
                    ) ? 'success' : 'partial',
                    'capture_id' => (int)($capture['id'] ?? 0),
                    'delivery_status' => $result['delivery_status'] ?? 'blocked',
                    'delivery_attempted' => ($result['delivery_attempted'] ?? false) === true,
                    'target_sync_status' => $targetSync['sync_status'] ?? 'unknown',
                ]
            );
            return $this->success($result, $this->dingdandaoDeliveryMessage($result));
        } catch (\InvalidArgumentException $error) {
            return $this->error($this->dingdandaoIntegrationInputError($error->getMessage()), 422);
        } catch (\RuntimeException $error) {
            $message = match ($error->getMessage()) {
                'dingdandao_target_scope_mismatch' =>
                    '当前经营目标是全酒店口径，不能用订单来了住宿房费事实覆盖，请先统一目标口径',
                'dingdandao_target_sync_capture_not_verified' =>
                    '订单来了事实尚未通过门店身份、日期、对账和数据库回读校验',
                'dingdandao_pms_tables_missing' =>
                    '订单来了推送回执表尚未安装，请先执行数据库迁移',
                default => '订单来了事实未能同步到经营目标，已停止企业微信发送',
            };
            return $this->error($message, 409);
        } catch (\Throwable $error) {
            $message = $error->getMessage() === 'dingdandao_pms_tables_missing'
                ? '订单来了推送回执表尚未安装，请先执行数据库迁移'
                : '企业微信发送失败，已验证的PMS数据仍保留在数据库中';
            return $this->error($message, 500);
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

    public function createTaskDraft(): Response
    {
        $input = $this->requestData();
        [$hotelId, $tenantId] = $this->authorizedScope('can_fill_daily_report', $input);
        $targetDate = (string)($input['target_date'] ?? '');
        try {
            $result = (new OperatingTargetAutomationService())->createTaskDraft(
                $tenantId,
                $hotelId,
                (int)$this->currentUser->id,
                $targetDate,
                $input
            );
            OperationLog::record(
                'operating_target',
                'create_task_draft',
                '经营目标偏差生成待审批任务 ' . $targetDate,
                (int)$this->currentUser->id,
                $hotelId,
                null,
                [
                    'outcome' => 'success',
                    'record_id' => (int)($result['target']['record_id'] ?? 0),
                    'intent_id' => (int)($result['execution_intent']['id'] ?? 0),
                    'reused_existing_intent' => ($result['reused_existing_intent'] ?? false) === true,
                ]
            );
            return $this->success($result, '已生成待审批任务，可在任务执行与复盘中继续处理');
        } catch (\InvalidArgumentException) {
            return $this->error('经营目标任务参数或门店范围无效', 422);
        } catch (\RuntimeException $error) {
            $message = match ($error->getMessage()) {
                'operating_target_record_missing' => '尚未保存该日期的经营目标',
                'operating_target_facts_not_actionable' => '经营事实尚未验证或存在阻断，不能创建执行任务',
                'operating_target_no_actionable_deviation' => '当前已设置目标没有需要执行的负向偏差',
                default => '经营目标任务创建失败，未生成重复任务',
            };
            return $this->error($message, 409);
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
            'operating_target_target_occupancy_rate_percent_invalid' => '目标入住率必须在 0 到 100 之间',
            'operating_target_target_revpar_invalid' => '目标 RevPAR 必须是有效的非负金额',
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

    private function dingdandaoIntegrationInputError(string $code): string
    {
        return match ($code) {
            'dingdandao_pms_binding_required' => '启用接口前必须维护订单来了门店名称；门店ID可在首次可信采集回读后自动固化',
            'dingdandao_pms_push_binding_required' => '启用自动推送前必须先启用接口并选择共享机器人',
            'dingdandao_pms_robot_invalid' => '请选择当前门店已启用的企业微信共享机器人',
            'dingdandao_pms_robot_test_required' => '启用正式自动推送前，必须先在企业微信推送页完成该机器人的真实送达测试',
            'dingdandao_pms_date_invalid' => '经营日期格式无效',
            default => '订单来了接口维护或推送参数无效',
        };
    }

    private function meituanCloudInputError(string $code): string
    {
        return match ($code) {
            'meituan_cloud_capture_date_invalid' => '美团云 PMS 经营日期格式无效',
            'meituan_cloud_capture_time_invalid' => '美团云 PMS 采集时间无效',
            'meituan_cloud_capture_source_url_invalid' => '只允许已授权的美团云 PMS 工作台来源',
            'meituan_cloud_capture_method_invalid' => '美团云 PMS 只接受受保护会话内的同源接口采集',
            'meituan_cloud_room_type_limit_exceeded' => '美团云 PMS 房型行数超过当前单次采集上限',
            'meituan_cloud_room_type_invalid' => '美团云 PMS 房型库存格式无效',
            'meituan_cloud_capture_not_verified' => '美团云 PMS 数据未通过身份、日期或差值验真',
            default => '美团云 PMS 经营指标输入无效；未知值请保持为空，不能用0补齐',
        };
    }

    private function meituanCloudIntegrationInputError(string $code): string
    {
        return match ($code) {
            'meituan_cloud_pms_binding_required' => '启用美团云 PMS 前必须维护对应门店名称',
            'meituan_cloud_pms_date_invalid' => '经营日期格式无效',
            default => '美团云 PMS 独立数据源维护参数无效',
        };
    }

    /** @param array<string,mixed> $result */
    private function dingdandaoDeliveryMessage(array $result): string
    {
        return match ((string)($result['delivery_status'] ?? '')) {
            'sent' => '订单来了已验证经营事实已送达企业微信',
            'already_sent' => '该采集记录此前已送达，本次未重复发送',
            'partial' => '企业微信仅部分送达，请查看推送回执',
            'failed', 'binding_missing' => '企业微信未送达，PMS数据已保留，请检查机器人配置后重试',
            default => '订单来了推送编排已完成，请查看回执状态',
        };
    }
}
