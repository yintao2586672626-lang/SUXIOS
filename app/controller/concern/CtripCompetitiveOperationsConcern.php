<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\model\User as UserModel;
use app\service\CtripCompetitiveOperationsService;
use app\service\CtripPublicHotelProfileService;
use app\service\MeituanPublicPageEvidenceService;
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
            $profiles = $this->otaPublicPageProfiles($systemHotelId, $platform, $businessDate);
            $result = (new OtaPublicPageDiagnosisService())->build(
                $systemHotelId,
                $platform,
                $businessDate,
                $profiles
            );

            $operationAuthorization = $this->otaPublicPageOperationAuthorization($systemHotelId);
            $canCreateTask = $this->currentUserCanExecuteOtaPublicPageTask($systemHotelId);
            $taskBridge = [
                'state' => 'no_intent',
                'create_status' => $canCreateTask
                    ? (($operationAuthorization['allowed'] ?? false) === true
                        ? 'available'
                        : (string)($operationAuthorization['reason'] ?? 'operation_capability_unavailable'))
                    : 'permission_denied',
                'identity_version' => null,
                'readback_status' => 'not_applicable',
                'execution_intent' => null,
                'operation_surface' => [
                    'accessible' => ($operationAuthorization['allowed'] ?? false) === true,
                    'status' => ($operationAuthorization['allowed'] ?? false) === true
                        ? 'available'
                        : (string)($operationAuthorization['reason'] ?? 'operation_capability_unavailable'),
                ],
            ];
            if (!$canCreateTask || ($operationAuthorization['allowed'] ?? false) !== true) {
                $taskBridge['state'] = 'create_blocked';
            }

            if ($canCreateTask) {
                try {
                    $diagnosisService = new OtaPublicPageDiagnosisService();
                    $operationService = new OperationManagementService();
                    $draft = $diagnosisService->buildExecutionIntentDraft($result, $this->otaPublicPageIdentitySchedule());
                    $found = $this->findOtaPublicPageExecutionIntent($operationService, $draft, $systemHotelId);
                    $intent = is_array($found['intent'] ?? null) ? $found['intent'] : null;
                    $identityVersion = (string)($found['identity_version'] ?? '');
                    if ($intent !== null) {
                        $mismatches = $this->otaPublicPageExecutionIntentMismatchFields(
                            $intent,
                            $draft,
                            $identityVersion,
                            $systemHotelId
                        );
                        if ($mismatches !== []) {
                            $taskBridge['state'] = 'readback_mismatch';
                            $taskBridge['readback_status'] = 'readback_mismatch';
                            $taskBridge['identity_version'] = $identityVersion;
                            $taskBridge['mismatch_fields'] = $mismatches;
                        } else {
                            $taskBridge['state'] = 'existing_intent';
                            $taskBridge['readback_status'] = 'readback_verified';
                            $taskBridge['identity_version'] = $identityVersion;
                            $taskBridge['execution_intent'] = $this->otaPublicPageExecutionIntentSummary(
                                $operationService,
                                $intent,
                                $identityVersion,
                                $systemHotelId
                            );
                        }
                    }
                } catch (\Throwable $bridgeException) {
                    \think\facade\Log::error('OTA public-page diagnosis task bridge read failed.', [
                        'exception_type' => get_debug_type($bridgeException),
                    ]);
                    $taskBridge['state'] = 'readback_mismatch';
                    $taskBridge['create_status'] = 'task_bridge_read_failed';
                    $taskBridge['readback_status'] = 'readback_mismatch';
                    $taskBridge['operation_surface'] = [
                        'accessible' => false,
                        'status' => 'task_bridge_read_failed',
                    ];
                }
            }
            $result['task_bridge'] = $taskBridge;

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
            $profiles = $this->otaPublicPageProfiles($systemHotelId, $platform, $businessDate);
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
            $operationSurfaceAuthorization = $this->otaPublicPageOperationAuthorization($systemHotelId);
            if (($operationSurfaceAuthorization['allowed'] ?? false) !== true) {
                $statusCode = (int)($operationSurfaceAuthorization['status'] ?? 403);
                if (!in_array($statusCode, [403, 503], true)) {
                    $statusCode = 403;
                }
                return $this->error('当前运营模块不可用，未创建任务', $statusCode, [
                    'reason' => (string)($operationSurfaceAuthorization['reason'] ?? 'operation_capability_unavailable'),
                    'operation_surface_status' => (string)($operationSurfaceAuthorization['reason'] ?? 'operation_capability_unavailable'),
                    'create_performed' => false,
                ]);
            }

            $operationService = new OperationManagementService();
            $found = $this->findOtaPublicPageExecutionIntent($operationService, $draft, $systemHotelId);
            $intent = is_array($found['intent'] ?? null) ? $found['intent'] : null;
            $identityVersion = (string)($found['identity_version'] ?? OtaPublicPageDiagnosisService::EXECUTION_IDENTITY_VERSION);
            $reusedExistingIntent = $intent !== null;
            $retryPerformed = false;
            $scheduleUpdated = false;
            $attempt = max(1, (int)($found['attempt'] ?? 1));
            $terminalStatuses = ['rejected', 'failed', 'failure', 'cancelled', 'canceled'];
            $existingStatus = strtolower(trim((string)($intent['status'] ?? '')));
            $existingLifecycleStatus = $intent !== null
                ? (string)($this->otaPublicPageExecutionLifecycle($operationService, $intent, $systemHotelId)['status'] ?? '')
                : '';
            $retryableLifecycleStatuses = ['execution_failed', 'reviewed_failed'];
            if ($intent !== null && (
                in_array($existingStatus, $terminalStatuses, true)
                || in_array($existingLifecycleStatus, $retryableLifecycleStatuses, true)
            )) {
                $retryPerformed = true;
                $attempt++;
                $draft['input']['evidence']['intent_attempt'] = $attempt;
                $draft['input']['evidence']['retry_of_intent_id'] = (int)($intent['id'] ?? 0);
                $draft['input']['evidence']['retry_of_status'] = in_array($existingStatus, $terminalStatuses, true)
                    ? $existingStatus
                    : $existingLifecycleStatus;
                $intent = null;
                $reusedExistingIntent = false;
                $identityVersion = OtaPublicPageDiagnosisService::EXECUTION_IDENTITY_VERSION;
            } else {
                $draft['input']['evidence']['intent_attempt'] = $attempt;
            }
            if ($intent === null) {
                $idempotencyKey = (string)($draft['idempotency_base_key'] ?? '') . ':attempt:' . $attempt;
                $intent = $operationService->createExecutionIntent(
                    [$systemHotelId],
                    $systemHotelId,
                    $draft['input'],
                    (int)($this->currentUser->id ?? 0),
                    false,
                    $idempotencyKey,
                    true
                );
                $reusedExistingIntent = ($intent['idempotent_replay'] ?? false) === true;
                $identityVersion = OtaPublicPageDiagnosisService::EXECUTION_IDENTITY_VERSION;
            } else {
                $intent['idempotent_replay'] = true;
            }
            $intentId = (int)($intent['id'] ?? 0);
            $targetValue = is_array($intent['target_value'] ?? null) ? $intent['target_value'] : [];
            $persistedSchedule = is_array($targetValue['workflow_schedule'] ?? null)
                ? $targetValue['workflow_schedule']
                : [];
            $expectedSchedule = $draft['input']['target_value']['workflow_schedule'];
            $intentStatus = strtolower(trim((string)($intent['status'] ?? '')));
            if ($reusedExistingIntent
                && in_array($intentStatus, ['draft', 'pending_approval'], true)
                && $persistedSchedule !== $expectedSchedule
            ) {
                $intent = $operationService->reschedulePendingExecutionIntent(
                    $intentId,
                    [$systemHotelId],
                    $expectedSchedule,
                    (int)($this->currentUser->id ?? 0)
                );
                $targetValue = is_array($intent['target_value'] ?? null) ? $intent['target_value'] : [];
                $persistedSchedule = is_array($targetValue['workflow_schedule'] ?? null)
                    ? $targetValue['workflow_schedule']
                    : [];
                $intentStatus = strtolower(trim((string)($intent['status'] ?? '')));
                $scheduleUpdated = true;
            }
            $mismatchFields = $this->otaPublicPageExecutionIntentMismatchFields(
                $intent,
                $draft,
                $identityVersion,
                $systemHotelId
            );
            if ($intentId <= 0 || $mismatchFields !== []) {
                throw new \RuntimeException(
                    'operation execution intent readback mismatch: ' . implode(',', $mismatchFields),
                    500
                );
            }
            if (!$reusedExistingIntent && ($persistedSchedule !== $expectedSchedule || $intentStatus !== 'pending_approval')) {
                throw new \RuntimeException('new operation execution intent readback mismatch', 500);
            }
            if ($reusedExistingIntent && $intentStatus === '') {
                throw new \RuntimeException('existing operation execution intent status is missing', 500);
            }
            $assignmentReadbackStatus = $persistedSchedule === $expectedSchedule
                ? ($scheduleUpdated ? 'rescheduled_readback_verified' : 'readback_verified')
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
                ? ($retryPerformed
                    ? '公开页诊断终态任务已重试为新的待审批运营任务草稿'
                    : '公开页诊断已转为待审批运营任务草稿')
                : ($intentStatus === 'pending_approval'
                    ? ($scheduleUpdated
                        ? '现有待审批运营任务排期已更新并回读确认'
                        : ($assignmentReadbackStatus === 'existing_schedule_preserved'
                        ? '已打开现有运营任务草稿，保留原排期'
                        : '已打开现有运营任务草稿'))
                    : '已打开现有运营记录（' . $intentStatusLabel . '）');
            $executionIntentSummary = $this->otaPublicPageExecutionIntentSummary(
                $operationService,
                $intent,
                $identityVersion,
                $systemHotelId
            );
            $taskBridge = [
                'state' => 'existing_intent',
                'create_status' => 'available',
                'identity_version' => $identityVersion,
                'readback_status' => 'readback_verified',
                'execution_intent' => $executionIntentSummary,
                'operation_surface' => [
                    'accessible' => true,
                    'status' => 'available',
                ],
            ];

            return $this->success([
                'diagnosis' => $diagnosis,
                'execution_intent' => $intent,
                'task_bridge' => $taskBridge,
                'execution_intent_readback_status' => 'readback_verified',
                'assignment_readback_status' => $assignmentReadbackStatus,
                'execution_intent_status' => $intentStatus,
                'execution_task_status' => (string)($executionIntentSummary['task_status'] ?? ''),
                'operation_lifecycle_status' => (string)($executionIntentSummary['lifecycle_status'] ?? ''),
                'execution_intent_identity_version' => $identityVersion,
                'execution_intent_is_pending_approval' => $intentStatus === 'pending_approval',
                'operation_surface_accessible' => true,
                'operation_surface_status' => 'available',
                'reused_existing_intent' => $reusedExistingIntent,
                'create_performed' => !$reusedExistingIntent,
                'retry_performed' => $retryPerformed && !$reusedExistingIntent,
                'schedule_updated' => $scheduleUpdated,
                'intent_attempt' => $attempt,
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

    public function saveOtaPublicPageEvidence(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        try {
            $data = $this->requestData();
            $systemHotelId = $this->ctripCompetitiveSystemHotelId($data['system_hotel_id'] ?? $data['hotel_id'] ?? null);
            if (!$this->currentUserCanMaintainOtaConfig($systemHotelId)) {
                return $this->error('无权维护此门店的 OTA 公开页证据', 403);
            }
            $platform = strtolower(trim((string)($data['platform'] ?? '')));
            if ($platform !== 'meituan') {
                throw new \InvalidArgumentException('当前人工公开页证据入口仅支持美团');
            }
            $result = (new MeituanPublicPageEvidenceService())->saveObservation(
                $systemHotelId,
                $data,
                (int)($this->currentUser->id ?? 0)
            );

            return $this->success($result, '美团公开页观测已保存并完成数据库回读；来源仍标记为人工观察');
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            \think\facade\Log::error('Meituan public-page evidence save failed.', [
                'exception_type' => get_debug_type($exception),
            ]);
            return $this->error('美团公开页证据保存或回读失败', 500, [
                'reason' => 'meituan_public_page_evidence_save_failed',
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

    /** @return array<int, array<string, mixed>> */
    private function otaPublicPageProfiles(int $systemHotelId, string $platform, string $businessDate): array
    {
        return match ($platform) {
            'ctrip' => (new CtripPublicHotelProfileService())->listDiagnosisProfiles($systemHotelId, $businessDate),
            'meituan' => (new MeituanPublicPageEvidenceService())->listDiagnosisProfiles($systemHotelId, $businessDate),
            default => [],
        };
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

    /** @return array{allowed:bool,reason:string,status:int} */
    private function otaPublicPageOperationAuthorization(int $systemHotelId): array
    {
        try {
            $service = new ProtectedCapabilityService();
            $capability = $service->classifyPath('POST', 'api/operation/execution-intents');
            if ($capability === null || $this->currentUser === null) {
                return ['allowed' => false, 'reason' => 'operation_capability_unavailable', 'status' => 503];
            }
            $authorization = $service->authorizeContext($this->currentUser, $capability, [
                'hotel_id' => $systemHotelId,
                'system_hotel_id' => $systemHotelId,
            ]);
            return [
                'allowed' => ($authorization['allowed'] ?? false) === true,
                'reason' => (string)($authorization['reason'] ?? 'operation_capability_unavailable'),
                'status' => (int)($authorization['status'] ?? 403),
            ];
        } catch (\Throwable) {
            return ['allowed' => false, 'reason' => 'operation_access_check_failed', 'status' => 503];
        }
    }

    /** @return array{assignee_id:int,due_at:string,review_at:string} */
    private function otaPublicPageIdentitySchedule(): array
    {
        $timezone = new \DateTimeZone('Asia/Shanghai');
        $today = new \DateTimeImmutable('today', $timezone);
        return [
            'assignee_id' => (int)($this->currentUser->id ?? 0),
            'due_at' => $today->modify('+1 day')->setTime(18, 0)->format('Y-m-d H:i:s'),
            'review_at' => $today->modify('+2 days')->setTime(10, 0)->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param array<string, mixed> $draft
     * @return array{intent:array<string,mixed>|null,identity_version:string,attempt:int}
     */
    private function findOtaPublicPageExecutionIntent(
        OperationManagementService $service,
        array $draft,
        int $systemHotelId
    ): array {
        $identities = [
            [
                'base_key' => (string)($draft['idempotency_base_key'] ?? ''),
                'version' => OtaPublicPageDiagnosisService::EXECUTION_IDENTITY_VERSION,
            ],
            [
                'base_key' => (string)($draft['version_two_idempotency_base_key'] ?? ''),
                'version' => OtaPublicPageDiagnosisService::VERSION_TWO_EXECUTION_IDENTITY_VERSION,
            ],
            [
                'base_key' => (string)($draft['legacy_idempotency_base_key'] ?? ''),
                'version' => OtaPublicPageDiagnosisService::LEGACY_EXECUTION_IDENTITY_VERSION,
            ],
        ];
        foreach ($identities as $identity) {
            $found = $service->readLatestOtaDiagnosisExecutionIntentAttempt(
                (string)$identity['base_key'],
                [$systemHotelId]
            );
            if (is_array($found) && is_array($found['intent'] ?? null)) {
                return [
                    'intent' => $found['intent'],
                    'identity_version' => $identity['version'],
                    'attempt' => max(1, (int)($found['attempt'] ?? 1)),
                ];
            }
        }
        return [
            'intent' => null,
            'identity_version' => OtaPublicPageDiagnosisService::EXECUTION_IDENTITY_VERSION,
            'attempt' => 1,
        ];
    }

    /**
     * @param array<string, mixed> $intent
     * @param array<string, mixed> $draft
     * @return array<int, string>
     */
    private function otaPublicPageExecutionIntentMismatchFields(
        array $intent,
        array $draft,
        string $identityVersion,
        int $systemHotelId
    ): array {
        $input = is_array($draft['input'] ?? null) ? $draft['input'] : [];
        $currentValue = is_array($intent['current_value'] ?? null) ? $intent['current_value'] : [];
        $targetValue = is_array($intent['target_value'] ?? null) ? $intent['target_value'] : [];
        $evidence = is_array($intent['evidence'] ?? null) ? $intent['evidence'] : [];
        $expectedCurrent = is_array($input['current_value'] ?? null) ? $input['current_value'] : [];
        $expectedTarget = is_array($input['target_value'] ?? null) ? $input['target_value'] : [];
        $expectedEvidence = is_array($input['evidence'] ?? null) ? $input['evidence'] : [];
        $expectedSourceRecordId = match ($identityVersion) {
            OtaPublicPageDiagnosisService::LEGACY_EXECUTION_IDENTITY_VERSION => (int)($draft['legacy_source_record_id'] ?? 0),
            OtaPublicPageDiagnosisService::VERSION_TWO_EXECUTION_IDENTITY_VERSION => (int)($draft['version_two_source_record_id'] ?? 0),
            default => (int)($draft['source_record_id'] ?? 0),
        };
        $mismatches = [];
        $check = static function (bool $matches, string $field) use (&$mismatches): void {
            if (!$matches) {
                $mismatches[] = $field;
            }
        };

        $check((int)($intent['hotel_id'] ?? 0) === $systemHotelId, 'hotel_id');
        $check((int)($intent['tenant_id'] ?? 0) > 0, 'tenant_id');
        $check((string)($intent['source_module'] ?? '') === OtaPublicPageDiagnosisService::EXECUTION_SOURCE_MODULE, 'source_module');
        $check((int)($intent['source_record_id'] ?? 0) === $expectedSourceRecordId, 'source_record_id');
        foreach (['platform', 'object_type', 'action_type', 'date_start', 'date_end', 'expected_metric'] as $field) {
            $check((string)($intent[$field] ?? '') === (string)($input[$field] ?? ''), $field);
        }
        $check((string)($currentValue['diagnosis_type'] ?? '') === (string)($expectedCurrent['diagnosis_type'] ?? ''), 'current_value.diagnosis_type');
        foreach (['collection_scope', 'target_date'] as $field) {
            $check((string)($targetValue[$field] ?? '') === (string)($expectedTarget[$field] ?? ''), 'target_value.' . $field);
        }
        $check((string)($evidence['metric_scope'] ?? '') === (string)($expectedEvidence['metric_scope'] ?? ''), 'evidence.metric_scope');
        if ($identityVersion === OtaPublicPageDiagnosisService::EXECUTION_IDENTITY_VERSION) {
            $check(
                (string)($evidence['task_identity_fingerprint'] ?? '') === (string)($expectedEvidence['task_identity_fingerprint'] ?? ''),
                'evidence.task_identity_fingerprint'
            );
            $check((string)($evidence['identity_version'] ?? '') === $identityVersion, 'evidence.identity_version');
        } else {
            $check(
                (string)($evidence['diagnosis_fingerprint'] ?? '') === (string)($expectedEvidence['diagnosis_fingerprint'] ?? ''),
                'evidence.diagnosis_fingerprint'
            );
            if ($identityVersion === OtaPublicPageDiagnosisService::VERSION_TWO_EXECUTION_IDENTITY_VERSION) {
                $check((string)($evidence['identity_version'] ?? '') === $identityVersion, 'evidence.identity_version');
            }
        }
        $check(in_array((string)($intent['status'] ?? ''), [
            'draft', 'pending_approval', 'blocked', 'approved', 'rejected',
            'failed', 'failure', 'cancelled', 'canceled',
        ], true), 'status');
        $schedule = is_array($targetValue['workflow_schedule'] ?? null) ? $targetValue['workflow_schedule'] : [];
        $check((int)($schedule['assignee_id'] ?? 0) > 0, 'target_value.workflow_schedule.assignee_id');
        $check(trim((string)($schedule['due_at'] ?? '')) !== '', 'target_value.workflow_schedule.due_at');
        $check(trim((string)($schedule['review_at'] ?? '')) !== '', 'target_value.workflow_schedule.review_at');

        return array_values(array_unique($mismatches));
    }

    /**
     * @param array<string, mixed> $intent
     * @return array<string, mixed>
     */
    private function otaPublicPageExecutionLifecycle(
        OperationManagementService $service,
        array $intent,
        int $systemHotelId
    ): array {
        $intentId = (int)($intent['id'] ?? 0);
        $flow = $service->executionFlow([$systemHotelId], $systemHotelId, ['intent_id' => $intentId, 'limit' => 1]);
        $item = is_array($flow['list'][0] ?? null) ? $flow['list'][0] : [];
        $approval = is_array($item['approval'] ?? null) ? $item['approval'] : [];
        $execution = is_array($item['execution'] ?? null) ? $item['execution'] : [];
        $evidence = is_array($item['evidence'] ?? null) ? $item['evidence'] : [];
        $evidenceTruth = is_array($item['evidence_truth'] ?? null) ? $item['evidence_truth'] : [];
        $review = is_array($item['review'] ?? null) ? $item['review'] : [];
        $intentStatus = (string)($approval['status'] ?? $intent['status'] ?? '');
        $taskStatus = (string)($execution['status'] ?? 'pending_create');
        $reviewStatus = (string)($review['status'] ?? 'observing');
        $sourceVerified = ($evidenceTruth['source_verified'] ?? false) === true;

        if (in_array($intentStatus, ['draft', 'pending_approval', 'blocked', 'rejected', 'failed', 'failure', 'cancelled', 'canceled'], true)) {
            $status = match ($intentStatus) {
                'failure' => 'failed',
                'canceled' => 'cancelled',
                default => $intentStatus,
            };
        } elseif ($intentStatus !== 'approved') {
            $status = 'invalid_state';
        } elseif ((int)($execution['task_id'] ?? 0) <= 0 || $taskStatus === 'pending_create') {
            $status = 'approved_pending_task';
        } elseif ($taskStatus === 'pending_execute' || $taskStatus === 'executing') {
            $status = $taskStatus;
        } elseif ($taskStatus === 'blocked') {
            $status = 'execution_blocked';
        } elseif ($taskStatus === 'failed') {
            $status = 'execution_failed';
        } elseif ($taskStatus !== 'executed') {
            $status = 'invalid_state';
        } elseif (!$sourceVerified) {
            $status = (int)($evidence['count'] ?? 0) <= 0
                ? 'execution_evidence_missing'
                : ((int)($evidence['operator_attested_count'] ?? 0) > 0
                    ? 'execution_evidence_partial'
                    : 'execution_evidence_unverified');
        } elseif ($reviewStatus === 'success') {
            $status = 'reviewed_success';
        } elseif ($reviewStatus === 'near_success') {
            $status = 'reviewed_near_success';
        } elseif ($reviewStatus === 'failed') {
            $status = 'reviewed_failed';
        } else {
            $status = ($review['is_available'] ?? false) === true ? 'pending_review' : 'review_scheduled';
        }

        return [
            'status' => $status,
            'stage' => (string)($item['stage'] ?? ''),
            'intent_id' => $intentId,
            'intent_status' => $intentStatus,
            'task_id' => (int)($execution['task_id'] ?? 0),
            'task_status' => $taskStatus,
            'result_status' => $reviewStatus,
            'truth_status' => (string)($review['truth_status'] ?? 'unverified'),
            'source_verified' => $sourceVerified,
            'review_available_on' => (string)($review['available_on'] ?? ''),
            'review_is_available' => ($review['is_available'] ?? false) === true,
            'next_action' => is_array($item['next_action'] ?? null) ? $item['next_action'] : [],
        ];
    }

    /**
     * @param array<string, mixed> $intent
     * @return array<string, mixed>
     */
    private function otaPublicPageExecutionIntentSummary(
        OperationManagementService $service,
        array $intent,
        string $identityVersion,
        int $systemHotelId
    ): array {
        $targetValue = is_array($intent['target_value'] ?? null) ? $intent['target_value'] : [];
        $schedule = is_array($targetValue['workflow_schedule'] ?? null) ? $targetValue['workflow_schedule'] : [];
        $assigneeId = (int)($schedule['assignee_id'] ?? 0);
        $assigneeName = '';
        if ($assigneeId > 0) {
            $assignee = UserModel::where('id', $assigneeId)->find();
            if ($assignee) {
                $assigneeName = trim((string)($assignee->realname ?? $assignee->username ?? ''));
            }
        }
        $lifecycle = $this->otaPublicPageExecutionLifecycle($service, $intent, $systemHotelId);

        return [
            'id' => (int)($intent['id'] ?? 0),
            'hotel_id' => (int)($intent['hotel_id'] ?? 0),
            'platform' => (string)($intent['platform'] ?? ''),
            'approval_status' => (string)($intent['status'] ?? ''),
            'task_status' => (string)($lifecycle['task_status'] ?? ''),
            'lifecycle_status' => (string)($lifecycle['status'] ?? ''),
            'identity_version' => $identityVersion,
            'intent_attempt' => max(1, (int)($intent['evidence']['intent_attempt'] ?? $intent['evidence']['attempt'] ?? 1)),
            'retry_of_intent_id' => (int)($intent['evidence']['retry_of_intent_id'] ?? 0),
            'workflow_schedule' => $schedule,
            'assignee_name' => $assigneeName,
            'execution_lifecycle' => $lifecycle,
        ];
    }
}
