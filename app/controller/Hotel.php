<?php
declare(strict_types=1);

namespace app\controller;

use app\model\Hotel as HotelModel;
use app\model\OperationLog;
use app\model\UserHotelPermission;
use app\service\HotelDataMergeService;
use app\service\HotelCascadeDeletionService;
use app\service\PermissionService;
use app\service\BatchStatusPreviewService;
use app\service\HotelCollectionBindingReceiptService;
use app\service\HotelCollectionPlanService;
use app\service\HotelAutopilotLifecycleService;
use app\service\HotelOtaBindingOnboardingService;
use app\service\HotelPmsBindingService;
use DomainException;
use InvalidArgumentException;
use RuntimeException;
use think\db\BaseQuery;
use think\Response;
use think\facade\Db;

class Hotel extends Base
{
    private const OTA_CHANNEL_STRATEGIES = ['none', 'ctrip_only', 'dual', 'meituan_only'];

    /**
     * 酒店列表
     */
    public function index(): Response
    {
        $this->checkPermission();
        $creatorColumnError = $this->ensureCreatorColumnIfRequired();
        if ($creatorColumnError) {
            return $creatorColumnError;
        }

        $pagination = $this->getPagination();
        $name = $this->request->param('name', '');
        $status = $this->request->param('status', '');
        $sortBy = (string)$this->request->param('sort_by', 'id');
        $sortOrder = strtolower((string)$this->request->param('sort_order', 'desc'));
        $allowedSorts = ['id', 'name', 'code', 'status', 'create_time', 'update_time'];
        if (!in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'id';
        }
        if (!in_array($sortOrder, ['asc', 'desc'], true)) {
            $sortOrder = 'desc';
        }

        $query = $this->hotelQuery()->order($sortBy, $sortOrder);
        if ($sortBy !== 'id') {
            $query->order('id', 'desc');
        }

        if ($name) {
            $query->whereLike('name', '%' . $name . '%');
        }
        if ($status !== '') {
            $query->where('status', $status);
        }

        // 非超级管理员只能看到有权限的酒店
        if (!$this->currentUser->isSuperAdmin()) {
            $permittedHotelIds = array_values(array_map('intval', $this->currentUser->getPermittedHotelIds()));
            if (empty($permittedHotelIds)) {
                return $this->paginate([], 0, $pagination['page'], $pagination['page_size']);
            }
            $query->whereIn('id', $permittedHotelIds);
            if ($this->requiresOwnHotelScope()) {
                $query->where('created_by', (int)$this->currentUser->id);
            }
        }

        $total = $query->count();
        $list = $this->appendPmsSelectionSummaries(
            $query->page($pagination['page'], $pagination['page_size'])
                ->select()
                ->toArray()
        );

        return $this->paginate($list, $total, $pagination['page'], $pagination['page_size']);
    }

    /**
     * 批量启用或停用门店。必须先 preview，再携带 confirm=true 执行。
     */
    public function batchStatus(): Response
    {
        $this->checkPermission();
        $data = $this->requestData();
        $hotelIds = array_values(array_unique(array_filter(array_map('intval', (array)($data['hotel_ids'] ?? [])), static fn (int $id): bool => $id > 0)));
        $status = (int)($data['status'] ?? -1);
        $confirmed = filter_var($data['confirm'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (empty($hotelIds) || count($hotelIds) > 100) {
            return $this->error('请选择 1-100 个门店', 422);
        }
        if (!in_array($status, [HotelModel::STATUS_DISABLED, HotelModel::STATUS_ENABLED], true)) {
            return $this->error('门店状态无效', 422);
        }

        $hotels = $this->hotelQuery()->whereIn('id', $hotelIds)->select();
        $affectedUserIdsByHotel = array_fill_keys($hotelIds, []);
        $affectedUserIdSet = [];
        $primaryUsers = \app\model\User::whereIn('hotel_id', $hotelIds)
            ->field('id,hotel_id')
            ->select()
            ->toArray();
        foreach ($primaryUsers as $userRow) {
            $rowHotelId = (int)($userRow['hotel_id'] ?? 0);
            $rowUserId = (int)($userRow['id'] ?? 0);
            if ($rowHotelId > 0 && $rowUserId > 0 && array_key_exists($rowHotelId, $affectedUserIdsByHotel)) {
                $affectedUserIdsByHotel[$rowHotelId][$rowUserId] = true;
                $affectedUserIdSet[$rowUserId] = true;
            }
        }
        if ($this->tableColumnExists('user_hotel_permissions', 'hotel_id')
            && $this->tableColumnExists('user_hotel_permissions', 'user_id')) {
            $permissionQuery = Db::name('user_hotel_permissions')->whereIn('hotel_id', $hotelIds);
            if ($this->tableColumnExists('user_hotel_permissions', 'status')) {
                $permissionQuery->where('status', 1);
            }
            $permissionRows = $permissionQuery->field('hotel_id,user_id')->select()->toArray();
            foreach ($permissionRows as $permissionRow) {
                $rowHotelId = (int)($permissionRow['hotel_id'] ?? 0);
                $rowUserId = (int)($permissionRow['user_id'] ?? 0);
                if ($rowHotelId > 0 && $rowUserId > 0 && array_key_exists($rowHotelId, $affectedUserIdsByHotel)) {
                    $affectedUserIdsByHotel[$rowHotelId][$rowUserId] = true;
                    $affectedUserIdSet[$rowUserId] = true;
                }
            }
        }
        $rows = [];
        foreach ($hotels as $hotel) {
            if (!$this->currentUserCanManageHotelRecord($hotel)) {
                return $this->error('包含无权管理的门店', 403, ['hotel_id' => (int)$hotel->id]);
            }
            $affectedUsers = count($affectedUserIdsByHotel[(int)$hotel->id] ?? []);
            $rows[] = [
                'id' => (int)$hotel->id,
                'name' => (string)$hotel->name,
                'current_status' => (int)$hotel->status,
                'next_status' => $status,
                'affected_users' => $affectedUsers,
            ];
        }
        $foundIds = array_column($rows, 'id');
        $missingIds = array_values(array_diff($hotelIds, $foundIds));
        if ($missingIds !== []) {
            return $this->error('包含不存在的门店，请刷新列表后重试', 422, ['missing_ids' => $missingIds]);
        }

        $previewService = new BatchStatusPreviewService();

        if (!$confirmed) {
            $preview = $previewService->issue('hotel_batch_status', (int)$this->currentUser->id, $hotelIds, $status);
            return $this->success([
                'preview' => true,
                'preview_id' => $preview['preview_id'],
                'preview_expires_in' => $preview['expires_in'],
                'affected_count' => count($rows),
                'affected_users' => count($affectedUserIdSet),
                'rows' => $rows,
                'missing_ids' => $missingIds,
            ], '批量门店状态变更预览已生成');
        }

        if (empty($rows)) {
            return $this->error('没有可变更的门店', 422);
        }
        $previewId = trim((string)($data['preview_id'] ?? ''));
        if (!$previewService->consume($previewId, 'hotel_batch_status', (int)$this->currentUser->id, $hotelIds, $status)) {
            return $this->error('批量门店预览已失效，请重新预览后确认', 409);
        }

        $this->hotelQuery()->whereIn('id', $foundIds)->update(['status' => $status]);
        $statusText = $status === HotelModel::STATUS_ENABLED ? '启用' : '停用';
        OperationLog::record('hotel', 'batch_status', "批量{$statusText}门店: " . implode(',', array_column($rows, 'name')), $this->currentUser->id ?? null);

        return $this->success([
            'preview' => false,
            'affected_count' => count($rows),
            'missing_ids' => $missingIds,
        ], "已批量{$statusText}" . count($rows) . '个门店');
    }

    /**
     * 所有酒店（下拉选择用）
     */
    public function all(): Response
    {
        if (!$this->currentUser) {
            return $this->error('未登录', 401);
        }

        $creatorColumnError = $this->ensureCreatorColumnIfRequired();
        if ($creatorColumnError) {
            return $creatorColumnError;
        }

        $fields = 'id, name, code, status';
        if ($this->tableColumnExists('hotels', 'ota_channel_strategy')) {
            $fields .= ', ota_channel_strategy';
        }

        $query = $this->hotelQuery()->where('status', HotelModel::STATUS_ENABLED)
            ->field($fields)
            ->order('id', 'asc');

        // 非超级管理员只能看到有权限的酒店
        if ($this->currentUser && !$this->currentUser->isSuperAdmin()) {
            $permittedHotelIds = array_values(array_map('intval', $this->currentUser->getPermittedHotelIds()));
            if (empty($permittedHotelIds)) {
                return $this->success([]);
            }
            $query->whereIn('id', $permittedHotelIds);
            if ($this->requiresOwnHotelScope()) {
                $query->where('created_by', (int)$this->currentUser->id);
            }
        }

        $list = $this->appendPmsSelectionSummaries($query->select()->toArray());

        return $this->success($list);
    }

    /**
     * Batch-read the safe lifecycle projection for every visible hotel.
     */
    public function automationLifecycle(): Response
    {
        $this->checkPermission();
        $creatorColumnError = $this->ensureCreatorColumnIfRequired();
        if ($creatorColumnError) {
            return $creatorColumnError;
        }

        $query = $this->hotelQuery()
            ->field('id,tenant_id')
            ->order('id', 'asc')
            ->limit(500);
        if (!$this->currentUser->isSuperAdmin()) {
            $permittedHotelIds = array_values(array_map('intval', $this->currentUser->getPermittedHotelIds()));
            if ($permittedHotelIds === []) {
                return $this->success([
                    'items' => [],
                    'count' => 0,
                    'sensitive_values_exposed' => false,
                ]);
            }
            $query->whereIn('id', $permittedHotelIds);
            if ($this->requiresOwnHotelScope()) {
                $query->where('created_by', (int)$this->currentUser->id);
            }
        }

        $byTenant = [];
        foreach ($query->select()->toArray() as $hotel) {
            $tenantId = (int)($hotel['tenant_id'] ?? 0);
            $hotelId = (int)($hotel['id'] ?? 0);
            if ($tenantId > 0 && $hotelId > 0) {
                $byTenant[$tenantId][] = $hotelId;
            }
        }
        try {
            $service = new HotelAutopilotLifecycleService();
            $items = [];
            foreach ($byTenant as $tenantId => $hotelIds) {
                $items = array_merge($items, $service->readForHotels((int)$tenantId, $hotelIds));
            }
        } catch (\Throwable $error) {
            $failureCode = preg_replace('/[^a-z0-9_.:-]+/', '_', strtolower($error->getMessage()))
                ?: 'hotel_automation_lifecycle_read_failed';
            return $this->error('自动运行状态暂不可用，请先完成数据库迁移', 503, [
                'failure_code' => substr($failureCode, 0, 120),
                'sensitive_values_exposed' => false,
            ]);
        }
        usort($items, static fn(array $left, array $right): int =>
            (int)($left['hotel_id'] ?? 0) <=> (int)($right['hotel_id'] ?? 0));
        return $this->success([
            'items' => $items,
            'count' => count($items),
            'sensitive_values_exposed' => false,
        ]);
    }

    /**
     * 酒店详情
     */
    public function read(int $id): Response
    {
        $this->checkPermission();

        $hotel = $this->hotelQuery()->where('id', $id)->find();
        if (!$hotel) {
            return $this->error('酒店不存在');
        }

        // 权限检查
        if (!$this->currentUser->isSuperAdmin()) {
            $permittedHotelIds = array_values(array_map('intval', $this->currentUser->getPermittedHotelIds()));
            if (!in_array($id, $permittedHotelIds, true)) {
                return $this->error('无权查看此酒店', 403);
            }
            if ($this->requiresOwnHotelScope()) {
                $creatorColumnError = $this->ensureCreatorColumnIfRequired();
                if ($creatorColumnError) {
                    return $creatorColumnError;
                }
                if (!$this->currentUserOwnsHotel($hotel)) {
                    return $this->error('只能查看自己添加的酒店', 403);
                }
            }
        }

        return $this->success($hotel);
    }

    /**
     * Read the single PMS selection and the selected provider's fact status.
     */
    public function pmsBinding(int $id): Response
    {
        $this->checkPermission();
        $hotel = $this->hotelQuery()->where('id', $id)->find();
        if (!$hotel instanceof HotelModel) {
            return $this->error('酒店不存在', 404);
        }
        if (!$this->currentUser->isSuperAdmin()) {
            $permittedHotelIds = array_values(array_map(
                'intval',
                $this->currentUser->getPermittedHotelIds()
            ));
            if (!in_array($id, $permittedHotelIds, true)) {
                return $this->error('无权查看此酒店的 PMS 配置', 403);
            }
        }

        try {
            $result = (new HotelPmsBindingService())->status(
                (int)$hotel->tenant_id,
                $id,
                (int)$this->currentUser->id,
                (string)$this->request->get('target_date', '')
            );
            return $this->success($result);
        } catch (InvalidArgumentException) {
            return $this->error('PMS 门店范围或经营日期无效', 422);
        }
    }

    /**
     * Read the exact, secret-free collection identity binding for one hotel.
     */
    public function collectionBindingReceipt(int $id): Response
    {
        $this->checkPermission();
        $hotel = $this->hotelQuery()->where('id', $id)->find();
        if (!$hotel instanceof HotelModel) {
            return $this->error('酒店不存在', 404);
        }
        if (!$this->currentUser->isSuperAdmin()) {
            $permittedHotelIds = array_values(array_map(
                'intval',
                $this->currentUser->getPermittedHotelIds()
            ));
            if (!in_array($id, $permittedHotelIds, true)) {
                return $this->error('无权查看该酒店的采集绑定凭据', 403);
            }
            if ($this->requiresOwnHotelScope()) {
                $creatorColumnError = $this->ensureCreatorColumnIfRequired();
                if ($creatorColumnError) {
                    return $creatorColumnError;
                }
                if (!$this->currentUserOwnsHotel($hotel)) {
                    return $this->error('只能查看自己添加酒店的采集绑定凭据', 403);
                }
            }
        }

        try {
            $designatedSourceIds = [];
            foreach (['ctrip', 'meituan'] as $platform) {
                $rawSourceId = trim((string)$this->request->get($platform . '_source_id', ''));
                if ($rawSourceId === '') {
                    continue;
                }
                if (preg_match('/^[1-9]\d{0,9}$/D', $rawSourceId) !== 1) {
                    return $this->error('指定的数据源无效', 422);
                }
                $designatedSourceIds[$platform] = (int)$rawSourceId;
            }
            $receipt = (new HotelCollectionBindingReceiptService())->receipt(
                $hotel->toArray(),
                (int)$this->currentUser->id,
                (string)$this->request->get(
                    'business_date',
                    $this->request->get('target_date', '')
                ),
                $designatedSourceIds
            );
            unset($receipt['execution_owner_user_id']);
            foreach (['ctrip', 'meituan'] as $platform) {
                if (is_array($receipt['bindings'][$platform] ?? null)) {
                    unset($receipt['bindings'][$platform]['execution_owner_user_id']);
                }
            }
            return $this->success($receipt);
        } catch (InvalidArgumentException) {
            return $this->error('酒店范围或经营日期无效', 422);
        }
    }

    /**
     * Read the zero-collection Hotel-80 OTA binding recovery contract.
     */
    public function otaBindingOnboarding(int $id): Response
    {
        $this->checkPermission();
        $hotel = $this->hotelQuery()->where('id', $id)->find();
        if (!$hotel instanceof HotelModel) {
            return $this->error('酒店不存在', 404);
        }
        $authorization = (new PermissionService())->authorize(
            $this->currentUser,
            'ota.collect',
            $id
        );
        if (empty($authorization['allowed']) || !$this->currentUserCanManageHotelRecord($hotel)) {
            return $this->error('权限不足', 403, $authorization);
        }

        $result = (new HotelOtaBindingOnboardingService())->preview(
            (int)$hotel->tenant_id,
            $id
        );
        $bindingReceipt = $this->hotelOtaBindingReceipt($hotel);
        if ($bindingReceipt === null) {
            $result['binding_readback_status'] = 'unverified';
            $result['reason_codes'][] = [
                'code' => 'hotel_ota_binding_onboarding_binding_readback_failed',
            ];
        } else {
            $result['binding_readback_status'] = 'readback_verified';
            $result['binding_receipt'] = $bindingReceipt;
        }
        return $this->success($result);
    }

    /**
     * Confirm one proof-gated Hotel-80 OTA binding action without collecting OTA data.
     */
    public function confirmOtaBindingOnboarding(int $id): Response
    {
        $this->checkPermission();
        $hotel = $this->hotelQuery()->where('id', $id)->find();
        if (!$hotel instanceof HotelModel) {
            return $this->error('酒店不存在', 404);
        }
        $permissionService = new PermissionService();
        $updateAuthorization = $permissionService->authorize($this->currentUser, 'hotel.update', $id);
        $collectAuthorization = $permissionService->authorize($this->currentUser, 'ota.collect', $id);
        if (empty($updateAuthorization['allowed'])
            || empty($collectAuthorization['allowed'])
            || !$this->currentUserCanManageHotelRecord($hotel)
        ) {
            return $this->error('权限不足', 403, [
                'hotel_update' => $updateAuthorization,
                'ota_collect' => $collectAuthorization,
            ]);
        }

        $input = $this->requestData();
        $allowedKeys = ['contract_version', 'action', 'expected_intent_digest', 'confirmed'];
        $unknownKeys = array_values(array_diff(array_keys($input), $allowedKeys));
        $action = trim((string)($input['action'] ?? ''));
        $intentDigest = strtolower(trim((string)($input['expected_intent_digest'] ?? '')));
        if ($unknownKeys !== []
            || (string)($input['contract_version'] ?? '') !== HotelOtaBindingOnboardingService::CONTRACT_VERSION
            || !in_array($action, [
                HotelOtaBindingOnboardingService::ACTION_CLAIM_IDENTITY,
                HotelOtaBindingOnboardingService::ACTION_BIND_SCHEDULER,
            ], true)
            || preg_match('/^[a-f0-9]{64}$/D', $intentDigest) !== 1
            || ($input['confirmed'] ?? null) !== true
        ) {
            return $this->error('绑定确认参数无效', 422, [
                'failure_code' => 'hotel_ota_binding_onboarding_input_invalid',
                'unknown_fields' => $unknownKeys,
                'ota_collection_performed' => false,
            ]);
        }

        $result = (new HotelOtaBindingOnboardingService())->execute(
            (int)$hotel->tenant_id,
            $id,
            $action,
            $intentDigest
        );
        if (($result['operation']['outcome'] ?? '') !== 'success') {
            return $this->error('当前绑定确认未执行', 409, $result);
        }

        $bindingReceipt = $this->hotelOtaBindingReceipt($hotel);
        $bindingReadbackVerified = $bindingReceipt !== null
            && $this->hotelOtaBindingActionReadbackVerified($bindingReceipt, $action);
        $result['binding_readback_status'] = $bindingReadbackVerified
            ? 'readback_verified'
            : 'unverified';
        if ($bindingReceipt !== null) {
            $result['binding_receipt'] = $bindingReceipt;
        }
        if (!$bindingReadbackVerified) {
            $result['status'] = 'partial';
            $result['operation']['outcome'] = 'partial';
            $result['operation']['failure_code'] = 'hotel_ota_binding_onboarding_exact_readback_unverified';
            $result['operation']['exact_readback_verified'] = false;
            return $this->success($result, '绑定已写入，但页面精确回读尚未闭合');
        }

        return $this->success($result, '绑定已保存并精确回读；未触发 OTA 采集');
    }

    /**
     * Read the durable, hotel-scoped collection plan and its current binding gate.
     */
    public function collectionPlan(int $id): Response
    {
        $this->checkPermission();
        $hotel = $this->hotelQuery()->where('id', $id)->find();
        if (!$hotel instanceof HotelModel) {
            return $this->error('酒店不存在', 404);
        }
        if (!$this->currentUser->isSuperAdmin()) {
            $permittedHotelIds = array_values(array_map(
                'intval',
                $this->currentUser->getPermittedHotelIds()
            ));
            if (!in_array($id, $permittedHotelIds, true)) {
                return $this->error('无权查看该酒店的采集计划', 403);
            }
            if ($this->requiresOwnHotelScope()) {
                $creatorColumnError = $this->ensureCreatorColumnIfRequired();
                if ($creatorColumnError) {
                    return $creatorColumnError;
                }
                if (!$this->currentUserOwnsHotel($hotel)) {
                    return $this->error('只能查看自己添加酒店的采集计划', 403);
                }
            }
        }

        try {
            $result = (new HotelCollectionPlanService())->read(
                $hotel->toArray(),
                (int)$this->currentUser->id,
                (string)$this->request->get(
                    'business_date',
                    $this->request->get('target_date', '')
                )
            );
            return $this->success($result);
        } catch (InvalidArgumentException) {
            return $this->error('酒店范围或经营日期无效', 422);
        } catch (RuntimeException $error) {
            if ($error->getMessage() === 'hotel_collection_plan_table_missing') {
                return $this->error('采集计划表尚未安装', 503);
            }
            if ($error->getMessage() === 'hotel_collection_plan_signing_key_missing') {
                return $this->error('采集计划签名配置尚未就绪', 503);
            }
            return $this->error('采集计划回读失败', 500);
        }
    }

    /**
     * Save and exactly read back one hotel plan without persisting login state.
     */
    public function updateCollectionPlan(int $id): Response
    {
        $this->checkPermission();
        $hotel = $this->hotelQuery()->where('id', $id)->find();
        if (!$hotel instanceof HotelModel) {
            return $this->error('酒店不存在', 404);
        }
        $authorization = (new PermissionService())->authorize(
            $this->currentUser,
            'hotel.update',
            $id
        );
        if (empty($authorization['allowed'])
            || !$this->currentUserCanManageHotelRecord($hotel)
        ) {
            return $this->error('权限不足', 403, $authorization);
        }

        $input = $this->requestData();
        try {
            $result = (new HotelCollectionPlanService())->save(
                $hotel->toArray(),
                (int)$this->currentUser->id,
                $input
            );
            OperationLog::record(
                'hotel',
                'collection_plan',
                '维护酒店采集计划: ' . (string)$hotel->name,
                (int)$this->currentUser->id,
                $id,
                null,
                [
                    'outcome' => 'success',
                    'plan_version' => (int)($result['plan_version'] ?? 0),
                    'plan_status' => (string)($result['plan_status'] ?? 'draft'),
                    'readback_verified' => ($result['readback_verified'] ?? false) === true,
                    'execution_authorized' => ($result['execution_authorized'] ?? false) === true,
                ]
            );
            return $this->success($result, '酒店采集计划已保存并精确回读');
        } catch (InvalidArgumentException $error) {
            $failureCode = preg_match('/^[a-z0-9_]{1,120}$/D', $error->getMessage()) === 1
                ? $error->getMessage()
                : 'hotel_collection_plan_input_invalid';
            return $this->error('酒店采集计划参数无效', 422, [
                'failure_code' => $failureCode,
            ]);
        } catch (RuntimeException $error) {
            $failureCode = preg_match('/^[a-z0-9_]{1,120}$/D', $error->getMessage()) === 1
                ? $error->getMessage()
                : 'hotel_collection_plan_save_failed';
            if ($failureCode === 'hotel_collection_plan_table_missing') {
                return $this->error('采集计划表尚未安装', 503, [
                    'failure_code' => $failureCode,
                ]);
            }
            if ($failureCode === 'hotel_collection_plan_binding_not_ready') {
                return $this->error('当前酒店绑定未就绪，计划只能保存为草稿', 409, [
                    'failure_code' => $failureCode,
                ]);
            }
            if (in_array($failureCode, [
                'hotel_collection_binding_receipt_scope_mismatch',
                'hotel_collection_plan_hotel_disabled',
                'hotel_collection_plan_final_binding_not_ready',
                'hotel_collection_plan_active_switch_failed',
            ], true)) {
                return $this->error('酒店采集计划范围或最终绑定校验未通过', 409, [
                    'failure_code' => $failureCode,
                ]);
            }
            if ($failureCode === 'hotel_collection_plan_signing_key_missing') {
                return $this->error('采集计划签名配置尚未就绪', 503, [
                    'failure_code' => $failureCode,
                ]);
            }
            return $this->error('酒店采集计划保存或回读失败', 500, [
                'failure_code' => $failureCode,
            ]);
        }
    }

    /**
     * Save exactly one active PMS for this hotel. Provider history is retained.
     */
    public function updatePmsBinding(int $id): Response
    {
        $this->checkPermission();
        $hotel = $this->hotelQuery()->where('id', $id)->find();
        if (!$hotel instanceof HotelModel) {
            return $this->error('酒店不存在', 404);
        }
        $authorization = (new PermissionService())->authorize(
            $this->currentUser,
            'hotel.update',
            $id
        );
        if (empty($authorization['allowed'])
            || !$this->currentUserCanManageHotelRecord($hotel)
        ) {
            return $this->error('权限不足', 403, $authorization);
        }

        $input = $this->requestData();
        try {
            $result = (new HotelPmsBindingService())->save(
                (int)$hotel->tenant_id,
                $id,
                (int)$this->currentUser->id,
                $input,
                (string)($input['target_date'] ?? '')
            );
            OperationLog::record(
                'hotel',
                'pms_binding',
                '维护门店唯一 PMS: ' . (string)$hotel->name,
                (int)$this->currentUser->id,
                $id,
                null,
                [
                    'outcome' => 'success',
                    'selected_provider' => $result['selected_provider'] ?? null,
                    'binding_status' => $result['binding_status'] ?? 'unconfigured',
                ]
            );
            return $this->success($result, '门店 PMS 配置已保存并回读');
        } catch (InvalidArgumentException $error) {
            $message = match ($error->getMessage()) {
                'hotel_pms_provider_invalid' => '请选择有效的 PMS，或选择暂不配置',
                'hotel_pms_binding_required' => '启用 PMS 时必须填写该系统中的门店名称',
                default => 'PMS 门店配置无效',
            };
            return $this->error($message, 422);
        } catch (\Throwable $error) {
            $message = $error->getMessage() === 'hotel_pms_tables_missing'
                ? 'PMS 配置表尚未安装，请先执行对应数据库迁移'
                : 'PMS 配置保存失败，未生成成功回执';
            return $this->error($message, 500);
        }
    }

    /**
     * 创建酒店
     */
    public function create(): Response
    {
        $this->checkPermission();
        if (!$this->currentUser->canManageOwnHotels()) {
            return $this->error('权限不足', 403);
        }

        $hasCreatorColumn = $this->tableColumnExists('hotels', 'created_by');
        $hasOwnerColumn = $this->tableColumnExists('hotels', 'owner_user_id');
        if (!$this->currentUser->isSuperAdmin() && !$hasCreatorColumn && !$hasOwnerColumn) {
            return $this->missingCreatorColumnResponse();
        }

        $data = $this->requestData();
        try {
            $otaChannelStrategy = $this->normalizeOtaChannelStrategy($data);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
        $this->validate($data, [
            'name' => 'require|max:100',
        ], [
            'name.require' => '酒店名称不能为空',
            'name.max' => '酒店名称最多100个字符',
        ]);

        $data['name'] = trim((string)$data['name']);
        try {
            $tenantId = $this->resolveCreateTenantId($data);
        } catch (DomainException $e) {
            return $this->error($e->getMessage(), 403);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 409);
        }
        $duplicateHotel = $this->duplicateHotelByName($data['name']);
        if ($duplicateHotel) {
            return $this->duplicateHotelNameResponse($duplicateHotel);
        }

        $hotel = new HotelModel();
        $hotel->name = $data['name'];
        $hotel->code = null;
        $hotel->address = $data['address'] ?? '';
        $hotel->contact_person = $data['contact_person'] ?? '';
        $hotel->contact_phone = $data['contact_phone'] ?? '';
        $hotel->description = $data['description'] ?? '';
        $hotel->status = HotelModel::STATUS_ENABLED;
        if ($this->tableColumnExists('hotels', 'ota_channel_strategy')) {
            $hotel->ota_channel_strategy = $otaChannelStrategy;
        }
        if ($hasOwnerColumn) {
            $hotel->owner_user_id = $this->resolveOwnerUserId($data);
        }
        if ($hasCreatorColumn) {
            $hotel->created_by = (int)$this->currentUser->id;
        }
        if ($tenantId !== null) {
            $hotel->tenant_id = $tenantId;
        }
        $hadAccessibleHotels = $this->currentUser->getPermittedHotelIds() !== [];
        $automationLifecycle = null;
        Db::transaction(function () use ($hotel, $tenantId, $hadAccessibleHotels, &$automationLifecycle): void {
            $hotel->save();
            $this->assignGeneratedHotelCode($hotel);
            if (!$this->currentUser->isSuperAdmin()) {
                $this->grantCurrentUserHotelPermission($hotel, $tenantId);
            }
            if (!$hadAccessibleHotels) {
                $this->assignFirstCreatedHotelAsDefault((int)$hotel->id);
            }

            $auditData = $tenantId !== null ? ['tenant_id' => $tenantId] : [];
            OperationLog::record(
                'hotel',
                'create',
                '创建酒店: ' . $hotel->name,
                $this->currentUser->id ?? null,
                (int)$hotel->id,
                null,
                $auditData
            );
            $automationLifecycle = (new HotelAutopilotLifecycleService())->initializeHotel(
                $hotel,
                (int)($this->currentUser->id ?? 0)
            );
        });

        $result = $hotel->toArray();
        $result['automation_lifecycle'] = $automationLifecycle;
        return $this->success($result, '创建成功，自动运行生命周期已建立');
    }

    /**
     * 新增门店编号只由系统生成。先使用全局自增主键对应的四位编号；
     * 若历史人工编号占用了该值，则在数据库唯一约束保护下生成新的数字编号。
     */
    private function assignGeneratedHotelCode(HotelModel $hotel): void
    {
        $hotelId = (int)$hotel->id;
        if ($hotelId <= 0) {
            throw new RuntimeException('门店编号生成失败，请重试');
        }

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $candidate = $attempt === 0
                ? str_pad((string)$hotelId, 4, '0', STR_PAD_LEFT)
                : (string)$hotelId . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            try {
                $updated = Db::name('hotels')->where('id', $hotelId)->update(['code' => $candidate]);
                if ($updated !== 1) {
                    throw new RuntimeException('门店编号生成失败，请重试');
                }
                $hotel->code = $candidate;
                return;
            } catch (\Throwable $e) {
                if (!$this->isHotelCodeCollision($e)) {
                    throw $e;
                }
            }
        }

        throw new RuntimeException('门店编号生成失败，请重试');
    }

    private function isHotelCodeCollision(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        return str_contains($message, 'uk_code')
            || str_contains($message, 'hotels.code')
            || str_contains($message, 'duplicate entry')
            || str_contains($message, 'unique constraint');
    }

    /**
     * 更新酒店
     */
    public function update(int $id): Response
    {
        $this->checkPermission();

        $hotel = $this->hotelQuery()->where('id', $id)->find();
        if (!$hotel) {
            return $this->error('酒店不存在');
        }
        $updateAuthorization = (new PermissionService())->authorize($this->currentUser, 'hotel.update', $id);
        if (empty($updateAuthorization['allowed'])) {
            return $this->error('权限不足', 403, $updateAuthorization);
        }
        if (!$this->currentUser->isSuperAdmin() && $this->currentUser->canManageOwnHotels()) {
            $creatorColumnError = $this->ensureCreatorColumnIfRequired();
            if ($creatorColumnError) {
                return $creatorColumnError;
            }
        }
        if (!$this->currentUserCanManageHotelRecord($hotel)) {
            return $this->error('权限不足', 403);
        }

        $data = $this->requestData();
        try {
            $otaChannelStrategy = $this->normalizeOtaChannelStrategy($data, (string)($hotel->ota_channel_strategy ?? 'none'));
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
        $code = $this->normalizeHotelCode($data['code'] ?? null);
        $data['code'] = $code ?? '';

        $this->validate($data, [
            'name' => 'require|max:100',
            'code' => 'max:50',
        ], [
            'name.require' => '酒店名称不能为空',
            'name.max' => '酒店名称最多100个字符',
            'code.max' => '酒店编码最多50个字符',
        ]);

        $data['name'] = trim((string)$data['name']);
        $duplicateHotel = $this->duplicateHotelByName($data['name'], $id);
        if ($duplicateHotel) {
            return $this->duplicateHotelNameResponse($duplicateHotel);
        }

        // 检查编码唯一性
        if ($code !== null) {
            $exists = $this->hotelQuery()->where('code', $code)->where('id', '<>', $id)->find();
            if ($exists) {
                return $this->error('酒店编码已存在');
            }
        }

        // 记录状态变更
        $oldStatus = $hotel->status;
        $newStatus = $data['status'] ?? $oldStatus;
        $statusChanged = false;
        $affectedUsers = 0;
        $isDisabling = (int)$oldStatus === HotelModel::STATUS_ENABLED
            && (int)$newStatus !== HotelModel::STATUS_ENABLED;
        
        if ($oldStatus != $newStatus) {
            $statusChanged = true;
            $preferenceColumn = $this->tableColumnExists('users', 'default_hotel_id')
                ? 'default_hotel_id'
                : 'hotel_id';
            $affectedUsers = $isDisabling
                ? (int)Db::name('users')->where($preferenceColumn, $id)->count()
                : 0;
        }

        $updatePayload = [
            'name' => $data['name'],
            'code' => $code,
            'address' => $data['address'] ?? '',
            'contact_person' => $data['contact_person'] ?? '',
            'contact_phone' => $data['contact_phone'] ?? '',
            'description' => $data['description'] ?? '',
        ];
        if ($this->tableColumnExists('hotels', 'ota_channel_strategy')) {
            $updatePayload['ota_channel_strategy'] = $otaChannelStrategy;
        }
        if (isset($data['status'])) {
            $updatePayload['status'] = $data['status'];
        }
        if ($this->tableColumnExists('hotels', 'update_time')) {
            $updatePayload['update_time'] = date('Y-m-d H:i:s');
        }
        [$hotel, $clearedDefaultPreferences] = Db::transaction(function () use (
            $id,
            $updatePayload,
            $statusChanged,
            $newStatus,
            $affectedUsers,
            $isDisabling
        ): array {
            $this->hotelQuery()->where('id', $id)->update($updatePayload);
            $cleared = 0;
            if ($isDisabling && $this->tableColumnExists('users', 'default_hotel_id')) {
                $cleared = (int)Db::name('users')
                    ->where('default_hotel_id', $id)
                    ->update(['default_hotel_id' => null]);
            }

            $updatedHotel = $this->hotelQuery()->where('id', $id)->find();
            if (!$updatedHotel instanceof HotelModel) {
                throw new RuntimeException('酒店更新失败，请刷新后重试');
            }

            $logDesc = '更新酒店: ' . $updatedHotel->name;
            if ($statusChanged) {
                $statusText = (int)$newStatus === HotelModel::STATUS_ENABLED ? '启用' : '禁用';
                $logDesc .= " (状态变更: {$statusText}, 影响{$affectedUsers}个默认主门店账号)";
            }
            OperationLog::record('hotel', 'update', $logDesc, $this->currentUser->id ?? null, $id);
            return [$updatedHotel, $cleared];
        });

        if ($clearedDefaultPreferences > 0 && $this->currentUser->defaultHotelPreferenceId() === $id) {
            $this->currentUser->default_hotel_id = null;
        }

        // 返回结果，包含状态变更信息
        $result = $hotel->toArray();
        if ($statusChanged) {
            $result['status_changed'] = true;
            $result['affected_users'] = $affectedUsers;
            $result['default_preferences_cleared'] = $clearedDefaultPreferences;
            $result['status_text'] = (int)$newStatus === HotelModel::STATUS_ENABLED ? '已启用' : '已禁用';
        }

        return $this->success($result, $statusChanged ? "酒店已{$result['status_text']}，涉及{$affectedUsers}个主门店归属账号" : '更新成功');
    }

    private function normalizeHotelCode($value): ?string
    {
        $code = trim((string)($value ?? ''));
        return $code === '' ? null : $code;
    }

    private function hotelQuery(): BaseQuery
    {
        if ($this->currentUser instanceof \app\model\User && $this->currentUser->isSuperAdmin()) {
            return HotelModel::withoutTenantScope();
        }

        return HotelModel::where([]);
    }

    /**
     * @param array<int,array<string,mixed>> $hotels
     * @return array<int,array<string,mixed>>
     */
    private function appendPmsSelectionSummaries(array $hotels): array
    {
        $hotelIds = array_values(array_filter(array_map(
            static fn(array $hotel): int => (int)($hotel['id'] ?? 0),
            $hotels
        )));
        try {
            $summaries = (new HotelPmsBindingService())->selectionSummaries($hotelIds);
        } catch (\Throwable) {
            $summaries = [];
        }

        foreach ($hotels as &$hotel) {
            $hotelId = (int)($hotel['id'] ?? 0);
            $summary = $summaries[$hotelId] ?? [
                'binding_status' => 'unavailable',
                'selected_provider' => null,
                'selected_provider_label' => 'PMS 状态未取得',
            ];
            $hotel['pms_binding_status'] = $summary['binding_status'];
            $hotel['pms_provider'] = $summary['selected_provider'];
            $hotel['pms_provider_label'] = $summary['selected_provider_label'];
        }
        unset($hotel);

        return $hotels;
    }

    private function duplicateHotelByName(string $name, ?int $excludeId = null): ?HotelModel
    {
        $normalizedName = trim($name);
        if ($normalizedName === '') {
            return null;
        }
        $query = $this->hotelQuery()->where('name', $normalizedName);
        if ($excludeId !== null && $excludeId > 0) {
            $query->where('id', '<>', $excludeId);
        }
        $hotel = $query->order('id', 'asc')->find();
        return $hotel instanceof HotelModel ? $hotel : null;
    }

    private function duplicateHotelNameResponse(HotelModel $hotel): Response
    {
        return $this->error('酒店名称已存在，请先核对并合并', 409, [
            'duplicate_hotels' => [[
                'id' => (int)$hotel->id,
                'name' => (string)$hotel->name,
                'code' => (string)($hotel->code ?? ''),
                'status' => (int)$hotel->status,
            ]],
        ]);
    }

    private function resolveOwnerUserId(array $data): int
    {
        if (!$this->currentUser) {
            return 0;
        }

        if (!$this->currentUser->isSuperAdmin()) {
            return (int)$this->currentUser->id;
        }

        $ownerUserId = (int)($data['owner_user_id'] ?? 0);
        return $ownerUserId > 0 ? $ownerUserId : (int)$this->currentUser->id;
    }

    public function mergePreview(): Response
    {
        $this->checkPermission(true);

        try {
            $sourceHotelId = (int)$this->request->param('source_hotel_id', 0);
            $targetHotelId = (int)$this->request->param('target_hotel_id', 0);
            $preview = (new HotelDataMergeService())->preview($sourceHotelId, $targetHotelId);

            return $this->success($preview, '门店数据迁移预览已生成');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('门店数据迁移预览失败: ' . $e->getMessage(), 500);
        }
    }

    public function mergeExecute(): Response
    {
        $this->checkPermission(true);

        $data = $this->requestData();
        $sourceHotelId = (int)($data['source_hotel_id'] ?? 0);
        $targetHotelId = (int)($data['target_hotel_id'] ?? 0);
        $deactivateSource = $this->isTruthy($data['deactivate_source'] ?? false);
        $service = new HotelDataMergeService();
        $expectedConfirmation = $service->confirmationText($sourceHotelId, $targetHotelId);
        $actualConfirmation = trim((string)($data['confirmation_text'] ?? ''));

        if ($actualConfirmation !== $expectedConfirmation) {
            return $this->error('确认文本不匹配，已取消迁移', 422, [
                'expected_confirmation_text' => $expectedConfirmation,
            ]);
        }

        try {
            $result = $service->execute($sourceHotelId, $targetHotelId, $actualConfirmation, $deactivateSource);
            OperationLog::record(
                'hotel',
                'merge_data',
                sprintf('门店数据迁移: %d -> %d', $sourceHotelId, $targetHotelId),
                $this->currentUser->id ?? null,
                $targetHotelId,
                null,
                [
                    'source_hotel_id' => $sourceHotelId,
                    'target_hotel_id' => $targetHotelId,
                    'updated_total' => $result['updated_total'] ?? 0,
                    'source_deactivated' => $deactivateSource,
                ]
            );

            return $this->success($result, '门店数据迁移完成');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 409);
        } catch (\Throwable $e) {
            return $this->error('门店数据迁移失败: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 删除酒店
     */
    public function delete(int $id): Response
    {
        $this->checkPermission(true);
        $data = $this->requestData();
        $forceDelete = $this->isForceDeleteRequested($data);
        $canForceDelete = (bool)($this->currentUser?->isSuperAdmin() ?? false);

        $hotel = $this->hotelQuery()->where('id', $id)->find();
        if (!$hotel) {
            return $this->error('酒店不存在');
        }
        $hotelName = (string)$hotel->name;
        $hotelTenantId = null;
        if ($this->tableColumnExists('hotels', 'tenant_id')) {
            $hotelTenantId = (int)($hotel->tenant_id ?? 0);
            try {
                $this->assertTenantExists($hotelTenantId);
            } catch (InvalidArgumentException|RuntimeException $e) {
                return $this->error('酒店租户数据无效，已拒绝删除: ' . $e->getMessage(), 409);
            }
        }
        $service = new HotelCascadeDeletionService();
        try {
            $preview = $service->preview($id);
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 409);
        }

        $references = [];
        foreach ((array)($preview['tables'] ?? []) as $table => $count) {
            $references[] = ['table' => (string)$table, 'label' => (string)$table, 'count' => (int)$count];
        }
        if ((int)($preview['config_entries'] ?? 0) > 0) {
            $references[] = ['table' => 'ota_config_lists', 'label' => '携程/美团配置', 'count' => (int)$preview['config_entries']];
        }
        if ((int)($preview['users_detached'] ?? 0) > 0) {
            $references[] = ['table' => 'users', 'label' => '解除员工门店归属', 'count' => (int)$preview['users_detached']];
        }

        if (!$forceDelete) {
            return $this->error('删除会永久清除该酒店及全部关联数据，请核对清单后再次确认', 409, [
                'references' => $references,
                'can_force_delete' => $canForceDelete,
                'requires_name_confirmation' => true,
            ]);
        }
        $confirmationName = (string)($data['confirmation_name'] ?? '');
        if (!$this->hotelDeleteConfirmationMatches($hotelName, $confirmationName)) {
            return $this->error('请输入完整门店名称后再删除', 422, [
                'references' => $references,
                'can_force_delete' => $canForceDelete,
                'requires_name_confirmation' => true,
            ]);
        }

        try {
            $result = $service->delete($id);
        } catch (\Throwable $e) {
            return $this->error('酒店及关联数据删除失败，事务已回滚: ' . $e->getMessage(), 500);
        }

        $auditData = [
            'deleted_hotel_id' => $id,
            'deleted_hotel_name' => $hotelName,
            'deleted_rows' => (int)($result['deleted_rows'] ?? 0),
            'users_detached' => (int)($result['users_detached'] ?? 0),
            'config_entries_deleted' => (int)($result['config_entries_deleted'] ?? 0),
            'preserved_audit_rows' => (int)($result['preserved_audit_rows'] ?? 0),
        ];
        if ($hotelTenantId !== null) {
            $auditData['tenant_id'] = $hotelTenantId;
        }
        OperationLog::record(
            'hotel',
            'delete',
            '删除酒店及关联数据: ' . $hotelName,
            $this->currentUser->id ?? null,
            $id,
            null,
            $auditData
        );

        return $this->success($result, '酒店及关联数据已删除');
    }

    /**
     * 检查权限
     */
    private function checkPermission(bool $requireAdmin = false): void
    {
        // 未登录检查
        if (!$this->currentUser) {
            abort(401, '未登录');
        }

        // 管理员权限检查
        if ($requireAdmin && !$this->currentUser->isSuperAdmin()) {
            abort(403, '权限不足');
        }
    }

    private function requiresOwnHotelScope(): bool
    {
        return false;
    }

    private function ensureCreatorColumnIfRequired(): ?Response
    {
        if ($this->requiresOwnHotelScope() && !$this->tableColumnExists('hotels', 'created_by')) {
            return $this->missingCreatorColumnResponse();
        }

        return null;
    }

    private function missingCreatorColumnResponse(): Response
    {
        return $this->error('酒店创建人字段未迁移，无法按创建人隔离酒店数据', 500, [
            'missing_column' => 'hotels.created_by',
        ]);
    }

    private function currentUserOwnsHotel(HotelModel $hotel): bool
    {
        if (!$this->currentUser) {
            return false;
        }

        if ($this->tableColumnExists('hotels', 'owner_user_id')) {
            return (int)($hotel->owner_user_id ?? 0) === (int)$this->currentUser->id;
        }

        return (int)($hotel->created_by ?? 0) === (int)$this->currentUser->id;
    }

    /** @return array<string,mixed>|null */
    private function hotelOtaBindingReceipt(HotelModel $hotel): ?array
    {
        try {
            $receipt = (new HotelCollectionBindingReceiptService())->receipt(
                $hotel->toArray(),
                (int)$this->currentUser->id,
                (string)$this->request->get(
                    'business_date',
                    $this->request->get('target_date', '')
                ),
                [
                    'ctrip' => HotelOtaBindingOnboardingService::CTRIP_SOURCE_ID,
                    'meituan' => HotelOtaBindingOnboardingService::MEITUAN_SOURCE_ID,
                ]
            );
            unset($receipt['execution_owner_user_id']);
            foreach (['ctrip', 'meituan'] as $platform) {
                if (is_array($receipt['bindings'][$platform] ?? null)) {
                    unset($receipt['bindings'][$platform]['execution_owner_user_id']);
                }
            }
            return $receipt;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $receipt */
    private function hotelOtaBindingActionReadbackVerified(array $receipt, string $action): bool
    {
        $expected = [
            'ctrip' => HotelOtaBindingOnboardingService::CTRIP_SOURCE_ID,
            'meituan' => HotelOtaBindingOnboardingService::MEITUAN_SOURCE_ID,
        ];
        foreach ($expected as $platform => $sourceId) {
            $binding = is_array($receipt['bindings'][$platform] ?? null)
                ? $receipt['bindings'][$platform]
                : [];
            if ((int)($binding['system_hotel_id'] ?? 0) !== HotelOtaBindingOnboardingService::HOTEL_ID
                || (int)($binding['source_id'] ?? 0) !== $sourceId
            ) {
                return false;
            }
            if ($action === HotelOtaBindingOnboardingService::ACTION_BIND_SCHEDULER
                && (string)($binding['execution_device_binding']['status'] ?? '') !== 'bound'
            ) {
                return false;
            }
        }
        $meituan = is_array($receipt['bindings']['meituan'] ?? null)
            ? $receipt['bindings']['meituan']
            : [];
        return trim((string)($meituan['platform_hotel_id'] ?? '')) !== ''
            && (string)($meituan['identity_evidence']['status'] ?? '') === 'verified'
            && trim((string)($meituan['identity_evidence']['source'] ?? '')) !== ''
            && trim((string)($meituan['identity_evidence']['checked_at'] ?? '')) !== '';
    }

    private function currentUserCanManageHotelRecord(HotelModel $hotel): bool
    {
        if (!$this->currentUser) {
            return false;
        }

        if ($this->currentUser->isSuperAdmin()) {
            return true;
        }

        if (!$this->currentUser->canManageOwnHotels()) {
            return false;
        }

        $creatorColumnError = $this->ensureCreatorColumnIfRequired();
        if ($creatorColumnError) {
            return false;
        }

        $permittedHotelIds = array_values(array_map('intval', $this->currentUser->getPermittedHotelIds()));
        return in_array((int)$hotel->id, $permittedHotelIds, true);
    }

    private function grantCurrentUserHotelPermission(HotelModel $hotel, ?int $validatedTenantId): void
    {
        if (!$this->currentUser || !$hotel->id) {
            return;
        }

        $hotelId = (int)$hotel->id;
        $canDeleteOta = (new PermissionService())->roleAllows($this->currentUser, 'ota.delete') ? 1 : 0;
        $payload = [
            'user_id' => (int)$this->currentUser->id,
            'hotel_id' => $hotelId,
            'scope_type' => 'owner',
            'can_view' => 1,
            'can_report' => 1,
            'can_fill' => 1,
            'can_edit' => 1,
            'can_fetch_ota' => 1,
            'can_delete_ota' => $canDeleteOta,
            'can_export' => 1,
            'can_ai' => 1,
            'can_operation' => 1,
            'can_investment' => 1,
            'status' => 'active',
            'created_by' => (int)$this->currentUser->id,
            'can_view_report' => 1,
            'can_fill_daily_report' => 1,
            'can_fill_monthly_task' => 1,
            'can_edit_report' => 1,
            'can_delete_report' => 0,
            'can_view_online_data' => 1,
            'can_fetch_online_data' => 1,
            'can_delete_online_data' => $canDeleteOta,
            'is_primary' => $this->currentUser->defaultHotelPreferenceId() <= 0 ? 1 : 0,
            'update_time' => date('Y-m-d H:i:s'),
        ];

        foreach ([
            'scope_type',
            'can_view',
            'can_report',
            'can_fill',
            'can_edit',
            'can_fetch_ota',
            'can_delete_ota',
            'can_export',
            'can_ai',
            'can_operation',
            'can_investment',
            'status',
            'created_by',
        ] as $column) {
            if (!$this->tableColumnExists('user_hotel_permissions', $column)) {
                unset($payload[$column]);
            }
        }

        if ($this->tableColumnExists('user_hotel_permissions', 'tenant_id')) {
            if ($validatedTenantId === null || $validatedTenantId <= 0) {
                throw new RuntimeException('无法在缺少有效租户的情况下授予酒店权限');
            }
            $payload['tenant_id'] = $validatedTenantId;
        }

        $existing = UserHotelPermission::where('user_id', (int)$this->currentUser->id)
            ->where('hotel_id', $hotelId)
            ->find();

        if ($existing) {
            $existing->save($payload);
            $this->currentUser->resetAuthorizationContext();
            return;
        }

        $payload['create_time'] = date('Y-m-d H:i:s');
        UserHotelPermission::create($payload);
        $this->currentUser->resetAuthorizationContext();
    }

    private function assignFirstCreatedHotelAsDefault(int $hotelId): void
    {
        $userId = (int)($this->currentUser->id ?? 0);
        if ($userId <= 0 || $hotelId <= 0 || !$this->tableColumnExists('users', 'default_hotel_id')) {
            return;
        }

        $updated = (int)Db::name('users')
            ->where('id', $userId)
            ->whereNull('default_hotel_id')
            ->update(['default_hotel_id' => $hotelId]);
        if ($updated === 1) {
            $this->currentUser->default_hotel_id = $hotelId;
        }
    }

    protected function shouldBlockHotelDelete(array $references, bool $forceDelete): bool
    {
        return !empty($references) && !$forceDelete;
    }

    protected function isForceDeleteRequested(array $data): bool
    {
        $force = $data['force'] ?? $this->request->param('force', false);
        return $force === true || $force === 1 || $force === '1' || $force === 'true';
    }

    protected function hotelDeleteConfirmationMatches(string $hotelName, string $confirmation): bool
    {
        return trim($hotelName) !== '' && hash_equals(trim($hotelName), trim($confirmation));
    }

    private function isTruthy($value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function normalizeOtaChannelStrategy(array $data, string $default = 'none'): string
    {
        $value = trim((string)($data['ota_channel_strategy'] ?? $data['otaChannelStrategy'] ?? $default));
        if ($value === '') {
            return in_array($default, self::OTA_CHANNEL_STRATEGIES, true) ? $default : 'none';
        }
        if (!in_array($value, self::OTA_CHANNEL_STRATEGIES, true)) {
            throw new InvalidArgumentException('OTA渠道策略无效，仅支持 none、ctrip_only、dual、meituan_only');
        }

        return $value;
    }

    /**
     * Resolve the SaaS tenant independently from any hotel identifier.
     *
     * @param array<string, mixed> $data
     */
    private function resolveCreateTenantId(array $data): ?int
    {
        if (!$this->tableColumnExists('hotels', 'tenant_id')) {
            return null;
        }

        if ($this->currentUser->isSuperAdmin()) {
            $tenantId = $this->positiveTenantId($data['tenant_id'] ?? null);
            if ($tenantId <= 0) {
                throw new InvalidArgumentException('超级管理员创建酒店时必须提供有效 tenant_id');
            }
        } else {
            $tenantId = $this->positiveTenantId($this->currentUser->tenant_id ?? null);
            if ($tenantId <= 0) {
                throw new InvalidArgumentException('当前用户未绑定有效租户，无法创建酒店');
            }

            if (array_key_exists('tenant_id', $data)) {
                $requestedTenantId = $this->positiveTenantId($data['tenant_id']);
                if ($requestedTenantId <= 0) {
                    throw new InvalidArgumentException('请求 tenant_id 无效');
                }
                if ($requestedTenantId !== $tenantId) {
                    throw new DomainException('无权为其他租户创建酒店');
                }
            }
        }

        $this->assertTenantExists($tenantId);
        return $tenantId;
    }

    private function assertTenantExists(int $tenantId): void
    {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('tenant_id 必须为正整数');
        }
        if (!$this->tableColumnExists('tenants', 'id')) {
            throw new RuntimeException('租户基础表不可用，请先完成租户迁移');
        }

        try {
            $storedTenantId = (int)Db::name('tenants')->where('id', $tenantId)->value('id');
        } catch (\Throwable $e) {
            throw new RuntimeException('租户数据校验失败', 0, $e);
        }
        if ($storedTenantId !== $tenantId) {
            throw new InvalidArgumentException('tenant_id 对应租户不存在');
        }
    }

    private function positiveTenantId($value): int
    {
        return is_numeric($value) && (int)$value > 0 ? (int)$value : 0;
    }

    private function countReferenceRows(string $table, string $column, int $value): int
    {
        if (!$this->tableColumnExists($table, $column)) {
            return 0;
        }

        return (int)Db::name($table)->where($column, $value)->count();
    }

    private function tableColumnExists(string $table, string $column): bool
    {
        $table = str_replace('`', '', $table);
        $column = str_replace(['`', "'"], '', $column);

        try {
            return in_array($column, Db::name($table)->getTableFields(), true);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
