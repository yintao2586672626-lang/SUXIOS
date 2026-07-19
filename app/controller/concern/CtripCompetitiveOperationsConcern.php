<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\model\User as UserModel;
use app\service\CtripCompetitiveOperationsService;
use app\service\CtripPublicHotelProfileService;
use app\service\OperationManagementService;
use app\service\OtaPublicPageDiagnosisService;
use app\service\ProtectedCapabilityService;
use think\Response;

trait CtripCompetitiveOperationsConcern
{
    public function ctripCompetitiveOperations(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_view_online_data');

        try {
            $systemHotelId = $this->ctripCompetitiveSystemHotelId($this->request->get('system_hotel_id', $this->request->get('hotel_id', null)));
            if (!$this->currentUserCanViewCtripCompetitiveHotel($systemHotelId)) {
                return $this->error('无权查看此门店的携程竞争圈数据', 403);
            }
            $startDate = trim((string)$this->request->get('start_date', ''));
            $endDate = trim((string)$this->request->get('end_date', ''));
            $result = (new CtripCompetitiveOperationsService())->build($systemHotelId, $startDate, $endDate);

            return $this->success($result, '携程竞争圈经营视图已生成');
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            \think\facade\Log::error('Ctrip competitive operations read failed.', [
                'exception_type' => get_debug_type($exception),
            ]);
            return $this->error('携程竞争圈经营视图读取失败', 500, [
                'reason' => 'ctrip_competitive_operations_read_failed',
            ]);
        }
    }

    public function ctripPublicProfiles(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_view_online_data');

        try {
            $systemHotelId = $this->ctripCompetitiveSystemHotelId($this->request->get('system_hotel_id', $this->request->get('hotel_id', null)));
            if (!$this->currentUserCanViewCtripCompetitiveHotel($systemHotelId)) {
                return $this->error('无权查看此门店的携程酒店档案', 403);
            }
            $service = new CtripPublicHotelProfileService();

            return $this->success([
                'system_hotel_id' => $systemHotelId,
                'binding' => $service->resolveOwnHotelBinding($systemHotelId),
                'profiles' => $service->listProfiles($systemHotelId),
                'profile_schema_version' => CtripPublicHotelProfileService::PROFILE_SCHEMA_VERSION,
                'room_count_semantics' => CtripPublicHotelProfileService::ROOM_COUNT_SEMANTICS,
                'scope_notice' => '档案来自无需登录的携程公开酒店页；包含可稳定识别的静态资料，不包含动态价格、指定日期库存、订单或流量。',
            ], '携程酒店档案已读取');
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            \think\facade\Log::error('Ctrip public profiles read failed.', [
                'exception_type' => get_debug_type($exception),
            ]);
            return $this->error('携程酒店档案读取失败', 500, [
                'reason' => 'ctrip_public_profiles_read_failed',
            ]);
        }
    }

    public function otaPublicPageDiagnosis(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_view_online_data');

        try {
            $systemHotelId = $this->ctripCompetitiveSystemHotelId($this->request->get('system_hotel_id', $this->request->get('hotel_id', null)));
            if (!$this->currentUserCanViewCtripCompetitiveHotel($systemHotelId)) {
                return $this->error('无权查看此门店的 OTA 公开页诊断', 403);
            }
            $platform = strtolower(trim((string)$this->request->get('platform', 'ctrip')));
            $businessDate = trim((string)$this->request->get('business_date', date('Y-m-d')));
            $profiles = $platform === 'ctrip'
                ? (new CtripPublicHotelProfileService())->listProfiles($systemHotelId, true)
                : [];
            $result = (new OtaPublicPageDiagnosisService())->build(
                $systemHotelId,
                $platform,
                $businessDate,
                $profiles
            );

            return $this->success($result, 'OTA 公开页证据诊断已生成');
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            \think\facade\Log::error('OTA public-page diagnosis read failed.', [
                'exception_type' => get_debug_type($exception),
            ]);
            return $this->error('OTA 公开页证据诊断读取失败', 500, [
                'reason' => 'ota_public_page_diagnosis_read_failed',
            ]);
        }
    }

    public function createOtaPublicPageDiagnosisExecutionIntent(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_view_online_data');

        try {
            $data = $this->requestData();
            $systemHotelId = $this->ctripCompetitiveSystemHotelId($data['system_hotel_id'] ?? $data['hotel_id'] ?? null);
            if (!$this->currentUserCanViewCtripCompetitiveHotel($systemHotelId)) {
                return $this->error('无权查看此门店的 OTA 公开页诊断', 403);
            }
            if (!$this->currentUserCanExecuteOtaPublicPageTask($systemHotelId)) {
                return $this->error('无权限将此门店的公开页诊断转为运营任务', 403);
            }

            $platform = strtolower(trim((string)($data['platform'] ?? 'ctrip')));
            $businessDate = trim((string)($data['business_date'] ?? date('Y-m-d')));
            $profiles = $platform === 'ctrip'
                ? (new CtripPublicHotelProfileService())->listProfiles($systemHotelId, true)
                : [];
            $diagnosisService = new OtaPublicPageDiagnosisService();
            $diagnosis = $diagnosisService->build($systemHotelId, $platform, $businessDate, $profiles);
            $schedule = [
                'assignee_id' => (int)($data['assignee_id'] ?? 0),
                'due_at' => trim((string)($data['due_at'] ?? '')),
                'review_at' => trim((string)($data['review_at'] ?? '')),
            ];
            $assignee = UserModel::where('id', $schedule['assignee_id'])
                ->where('status', UserModel::STATUS_ENABLED)
                ->find();
            if (!$assignee) {
                throw new \InvalidArgumentException('负责人必须是已启用用户');
            }
            if (!$assignee->hasHotelPermission($systemHotelId, 'operation.execute')) {
                throw new \InvalidArgumentException('负责人无权执行此门店的运营任务');
            }
            $draft = $diagnosisService->buildExecutionIntentDraft($diagnosis, $schedule);
            $intent = (new OperationManagementService())->createExecutionIntent(
                [$systemHotelId],
                $systemHotelId,
                $draft['input'],
                (int)($this->currentUser->id ?? 0),
                false,
                $draft['idempotency_key']
            );
            $intentId = (int)($intent['id'] ?? 0);
            $targetValue = is_array($intent['target_value'] ?? null) ? $intent['target_value'] : [];
            $persistedSchedule = is_array($targetValue['workflow_schedule'] ?? null)
                ? $targetValue['workflow_schedule']
                : [];
            $expectedSchedule = $draft['input']['target_value']['workflow_schedule'];
            $reusedExistingIntent = ($intent['idempotent_replay'] ?? false) === true;
            $intentStatus = strtolower(trim((string)($intent['status'] ?? '')));
            if ($intentId <= 0
                || (int)($intent['hotel_id'] ?? 0) !== $systemHotelId
                || (string)($intent['source_module'] ?? '') !== 'ota_diagnosis'
                || (int)($intent['source_record_id'] ?? 0) !== (int)$draft['source_record_id']
            ) {
                throw new \RuntimeException('operation execution intent readback mismatch', 500);
            }
            if (!$reusedExistingIntent && ($persistedSchedule !== $expectedSchedule || $intentStatus !== 'pending_approval')) {
                throw new \RuntimeException('new operation execution intent readback mismatch', 500);
            }
            if ($reusedExistingIntent && $intentStatus === '') {
                throw new \RuntimeException('existing operation execution intent status is missing', 500);
            }
            $assignmentReadbackStatus = $persistedSchedule === $expectedSchedule
                ? 'readback_verified'
                : 'existing_schedule_preserved';
            $intentStatusLabel = match ($intentStatus) {
                'pending_approval' => '待审批',
                'approved' => '已审批',
                'rejected' => '已驳回',
                'executing' => '执行中',
                'completed' => '已完成',
                'failed' => '执行失败',
                'cancelled' => '已取消',
                default => $intentStatus,
            };
            $message = !$reusedExistingIntent
                ? '公开页诊断已转为待审批运营任务草稿'
                : ($intentStatus === 'pending_approval'
                    ? ($assignmentReadbackStatus === 'existing_schedule_preserved'
                        ? '已打开现有运营任务草稿，保留原排期'
                        : '已打开现有运营任务草稿')
                    : '已打开现有运营记录（' . $intentStatusLabel . '）');
            $operationSurfaceAuthorization = ['allowed' => false, 'reason' => 'operation_capability_unavailable'];
            try {
                $protectedCapabilityService = new ProtectedCapabilityService();
                $operationCapability = $protectedCapabilityService->classifyPath(
                    'GET',
                    'api/operation/execution-intents/' . $intentId
                );
                if ($operationCapability !== null && $this->currentUser !== null) {
                    $operationSurfaceAuthorization = $protectedCapabilityService->authorizeContext(
                        $this->currentUser,
                        $operationCapability,
                        [
                            'hotel_id' => $systemHotelId,
                            'system_hotel_id' => $systemHotelId,
                        ]
                    );
                }
            } catch (\Throwable) {
                $operationSurfaceAuthorization = ['allowed' => false, 'reason' => 'operation_access_check_failed'];
            }

            return $this->success([
                'diagnosis' => $diagnosis,
                'execution_intent' => $intent,
                'execution_intent_readback_status' => 'readback_verified',
                'assignment_readback_status' => $assignmentReadbackStatus,
                'execution_intent_status' => $intentStatus,
                'execution_intent_is_pending_approval' => $intentStatus === 'pending_approval',
                'operation_surface_accessible' => ($operationSurfaceAuthorization['allowed'] ?? false) === true,
                'operation_surface_status' => ($operationSurfaceAuthorization['allowed'] ?? false) === true
                    ? 'available'
                    : (string)($operationSurfaceAuthorization['reason'] ?? 'unavailable'),
                'reused_existing_intent' => $reusedExistingIntent,
            ], $message);
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            \think\facade\Log::error('OTA public-page diagnosis execution-intent create failed.', [
                'exception_type' => get_debug_type($exception),
                'exception_code' => (int)$exception->getCode(),
            ]);
            $statusCode = (int)$exception->getCode();
            if (!in_array($statusCode, [409, 500, 503], true)) {
                $statusCode = 500;
            }
            return $this->error('OTA 公开页诊断转任务失败', $statusCode, [
                'reason' => 'ota_public_page_diagnosis_execution_intent_create_failed',
            ]);
        }
    }

    public function syncCtripPublicProfiles(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        try {
            $data = $this->requestData();
            $systemHotelId = $this->ctripCompetitiveSystemHotelId($data['system_hotel_id'] ?? $data['hotel_id'] ?? null);
            if (!$this->currentUserCanMaintainOtaConfig($systemHotelId)) {
                return $this->error('无权更新此门店的携程酒店档案', 403);
            }
            $scope = strtolower(trim((string)($data['scope'] ?? 'own')));
            $limit = is_numeric($data['limit'] ?? null) ? (int)$data['limit'] : 10;
            $force = filter_var($data['force'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $result = (new CtripPublicHotelProfileService())->syncForHotel(
                $systemHotelId,
                $scope,
                $limit,
                $force
            );

            if (($result['status'] ?? '') === 'binding_missing') {
                return $this->error('未找到本店携程酒店ID绑定，未发起公开页采集', 409, $result);
            }
            if (($result['status'] ?? '') === 'collection_failed') {
                return $this->error('携程公开资料本次采集失败，失败状态已保留', 502, $result);
            }

            return $this->success(
                $result,
                ($result['status'] ?? '') === 'partial'
                    ? '携程公开资料部分补全，缺失或失败字段已明确标记'
                    : '携程公开资料已补全并回读确认'
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), 409);
        } catch (\Throwable $exception) {
            \think\facade\Log::error('Ctrip public profiles sync failed.', [
                'exception_type' => get_debug_type($exception),
            ]);
            return $this->error('携程公开资料补全失败', 500, [
                'reason' => 'ctrip_public_profiles_sync_failed',
            ]);
        }
    }

    public function addCtripPublicProfile(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        try {
            $data = $this->requestData();
            $systemHotelId = $this->ctripCompetitiveSystemHotelId($data['system_hotel_id'] ?? $data['hotel_id'] ?? null);
            if (!$this->currentUserCanMaintainOtaConfig($systemHotelId)) {
                return $this->error('无权维护此门店的携程公开酒店ID', 403);
            }
            $otaHotelId = trim((string)($data['ota_hotel_id'] ?? $data['ctrip_hotel_id'] ?? ''));
            $role = strtolower(trim((string)($data['role'] ?? 'competitor')));
            $replace = filter_var($data['replace'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $actorId = (int)($this->currentUser->id ?? 0);
            $result = (new CtripPublicHotelProfileService())->addByHotelId(
                $systemHotelId,
                $otaHotelId,
                $role,
                $actorId,
                $replace
            );

            return $this->success(
                $result,
                ($result['status'] ?? '') === 'binding_saved_collection_failed'
                    ? '携程酒店ID已保存，但公开页本次采集失败；失败状态已保留，可稍后重试'
                    : '携程酒店ID已添加，公开静态资料已补全并回读确认'
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), 409);
        } catch (\Throwable $exception) {
            \think\facade\Log::error('Ctrip public profile add failed.', [
                'exception_type' => get_debug_type($exception),
            ]);
            return $this->error('携程公开酒店ID添加失败', 500, [
                'reason' => 'ctrip_public_profile_add_failed',
            ]);
        }
    }

    private function ctripCompetitiveSystemHotelId(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        $value = trim((string)$value);
        if (preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            return (int)$value;
        }
        throw new \InvalidArgumentException('system_hotel_id 必须是正整数');
    }

    private function currentUserCanViewCtripCompetitiveHotel(int $systemHotelId): bool
    {
        $user = $this->currentUser ?? null;
        if (!$user) {
            return false;
        }
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }
        $permittedIds = method_exists($user, 'getPermittedHotelIds')
            ? array_map('intval', (array)$user->getPermittedHotelIds())
            : [];
        return in_array($systemHotelId, $permittedIds, true);
    }

    private function currentUserCanExecuteOtaPublicPageTask(int $systemHotelId): bool
    {
        $user = $this->currentUser ?? null;
        return $user !== null
            && method_exists($user, 'hasHotelPermission')
            && $user->hasHotelPermission($systemHotelId, 'operation.execute');
    }
}
