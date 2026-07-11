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
use InvalidArgumentException;
use RuntimeException;
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

        $query = HotelModel::order($sortBy, $sortOrder);
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
        $list = $query->page($pagination['page'], $pagination['page_size'])->select();

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

        $hotels = HotelModel::whereIn('id', $hotelIds)->select();
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

        HotelModel::whereIn('id', $foundIds)->update(['status' => $status]);
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

        $query = HotelModel::where('status', HotelModel::STATUS_ENABLED)
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

        $list = $query->select();

        return $this->success($list);
    }

    /**
     * 酒店详情
     */
    public function read(int $id): Response
    {
        $this->checkPermission();

        $hotel = HotelModel::find($id);
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
        $duplicateHotel = $this->duplicateHotelByName($data['name']);
        if ($duplicateHotel) {
            return $this->duplicateHotelNameResponse($duplicateHotel);
        }

        // 检查编码唯一性
        if ($code !== null) {
            $exists = HotelModel::where('code', $code)->find();
            if ($exists) {
                return $this->error('酒店编码已存在');
            }
        }

        $hotel = new HotelModel();
        $hotel->name = $data['name'];
        $hotel->code = $code;
        $hotel->address = $data['address'] ?? '';
        $hotel->contact_person = $data['contact_person'] ?? '';
        $hotel->contact_phone = $data['contact_phone'] ?? '';
        $hotel->description = $data['description'] ?? '';
        $hotel->status = $data['status'] ?? HotelModel::STATUS_ENABLED;
        if ($this->tableColumnExists('hotels', 'ota_channel_strategy')) {
            $hotel->ota_channel_strategy = $otaChannelStrategy;
        }
        if ($hasOwnerColumn) {
            $hotel->owner_user_id = $this->resolveOwnerUserId($data);
        }
        if ($hasCreatorColumn) {
            $hotel->created_by = (int)$this->currentUser->id;
        }
        Db::transaction(function () use ($hotel): void {
            $hotel->save();
            if ($this->tableColumnExists('hotels', 'tenant_id')) {
                $tenantId = (int)Db::name('hotels')->where('id', (int)$hotel->id)->value('tenant_id');
                if ($tenantId <= 0) {
                    $tenantId = (int)$hotel->id;
                    $hotel->tenant_id = $tenantId;
                    $hotel->save();
                } else {
                    $hotel->tenant_id = $tenantId;
                }
            }
            if (!$this->currentUser->isSuperAdmin()) {
                $this->grantCurrentUserHotelPermission($hotel);
            }

            OperationLog::record('hotel', 'create', '创建酒店: ' . $hotel->name, $this->currentUser->id ?? null);
        });

        return $this->success($hotel, '创建成功');
    }

    /**
     * 更新酒店
     */
    public function update(int $id): Response
    {
        $this->checkPermission();

        $hotel = HotelModel::find($id);
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
            $exists = HotelModel::where('code', $code)->where('id', '<>', $id)->find();
            if ($exists) {
                return $this->error('酒店编码已存在');
            }
        }

        // 记录状态变更
        $oldStatus = $hotel->status;
        $newStatus = $data['status'] ?? $oldStatus;
        $statusChanged = false;
        $affectedUsers = 0;
        
        if ($oldStatus != $newStatus) {
            $statusChanged = true;
            // 统计受影响的用户数
            $affectedUsers = \app\model\User::where('hotel_id', $id)->count();
        }

        $hotel->name = $data['name'];
        $hotel->code = $code;
        $hotel->address = $data['address'] ?? '';
        $hotel->contact_person = $data['contact_person'] ?? '';
        $hotel->contact_phone = $data['contact_phone'] ?? '';
        $hotel->description = $data['description'] ?? '';
        if ($this->tableColumnExists('hotels', 'ota_channel_strategy')) {
            $hotel->ota_channel_strategy = $otaChannelStrategy;
        }
        if (isset($data['status'])) {
            $hotel->status = $data['status'];
        }
        $hotel->save();

        // 记录操作日志
        $logDesc = '更新酒店: ' . $hotel->name;
        if ($statusChanged) {
            $statusText = $newStatus == HotelModel::STATUS_ENABLED ? '启用' : '禁用';
            $logDesc .= " (状态变更: {$statusText}, 影响{$affectedUsers}个用户)";
        }
        OperationLog::record('hotel', 'update', $logDesc, $this->currentUser->id ?? null, $id);

        // 返回结果，包含状态变更信息
        $result = $hotel->toArray();
        if ($statusChanged) {
            $result['status_changed'] = true;
            $result['affected_users'] = $affectedUsers;
            $result['status_text'] = $newStatus == HotelModel::STATUS_ENABLED ? '已启用' : '已禁用';
        }

        return $this->success($result, $statusChanged ? "酒店已{$result['status_text']}，涉及{$affectedUsers}个主门店归属账号" : '更新成功');
    }

    private function normalizeHotelCode($value): ?string
    {
        $code = trim((string)($value ?? ''));
        return $code === '' ? null : $code;
    }

    private function duplicateHotelByName(string $name, ?int $excludeId = null): ?HotelModel
    {
        $normalizedName = trim($name);
        if ($normalizedName === '') {
            return null;
        }
        $query = HotelModel::where('name', $normalizedName);
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
        if ($forceDelete && !$canForceDelete) {
            return $this->error('仅超级管理员可以归档酒店', 403);
        }

        $hotel = HotelModel::find($id);
        if (!$hotel) {
            return $this->error('酒店不存在');
        }
        $hotelName = (string)$hotel->name;
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
        if ((int)($preview['users_preserved'] ?? 0) > 0) {
            $references[] = ['table' => 'users', 'label' => '保留员工门店归属', 'count' => (int)$preview['users_preserved']];
        }

        if (!$forceDelete) {
            return $this->error('归档会停用该酒店并停止 OTA 采集；历史数据、配置和员工归属将完整保留', 409, [
                'references' => $references,
                'can_force_delete' => $canForceDelete,
                'requires_name_confirmation' => true,
            ]);
        }
        $confirmationName = (string)($data['confirmation_name'] ?? '');
        if (!$this->hotelDeleteConfirmationMatches($hotelName, $confirmationName)) {
            return $this->error('请输入完整门店名称后再归档', 422, [
                'references' => $references,
                'can_force_delete' => $canForceDelete,
                'requires_name_confirmation' => true,
            ]);
        }

        try {
            $result = $service->delete($id, (int)($this->currentUser->id ?? 0));
        } catch (\Throwable $e) {
            return $this->error('酒店归档失败，事务已回滚: ' . $e->getMessage(), 500);
        }

        OperationLog::record(
            'hotel',
            'archive',
            '归档酒店并保留关联数据: ' . $hotelName,
            $this->currentUser->id ?? null,
            null,
            null,
            [
                'archived_hotel_id' => $id,
                'archived_hotel_name' => $hotelName,
                'preserved_rows' => (int)($result['preserved_rows'] ?? 0),
            ]
        );

        return $this->success($result, '酒店已归档；历史数据、配置和员工归属均已保留');
    }

    public function restore(int $id): Response
    {
        $this->checkPermission(true);
        if (!(bool)($this->currentUser?->isSuperAdmin() ?? false)) {
            return $this->error('仅超级管理员可以恢复归档酒店', 403);
        }

        try {
            $result = (new HotelCascadeDeletionService())->restore($id);
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 409);
        } catch (\Throwable $e) {
            return $this->error('酒店恢复失败，事务已回滚: ' . $e->getMessage(), 500);
        }

        OperationLog::record(
            'hotel',
            'restore_archive',
            '恢复归档酒店: ' . (string)($result['hotel_name'] ?? $id),
            $this->currentUser->id ?? null,
            $id,
            null,
            ['restored_hotel_id' => $id, 'status' => 0]
        );

        return $this->success($result, '酒店已恢复为停用状态，请确认配置后再手动启用');
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

    private function grantCurrentUserHotelPermission(HotelModel $hotel): void
    {
        if (!$this->currentUser || !$hotel->id) {
            return;
        }

        $hotelId = (int)$hotel->id;
        $payload = [
            'user_id' => (int)$this->currentUser->id,
            'hotel_id' => $hotelId,
            'scope_type' => 'owner',
            'can_view' => 1,
            'can_report' => 1,
            'can_fill' => 1,
            'can_edit' => 1,
            'can_fetch_ota' => 1,
            'can_delete_ota' => 0,
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
            'can_delete_online_data' => 0,
            'is_primary' => empty($this->currentUser->hotel_id) ? 1 : 0,
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
            $payload['tenant_id'] = (int)($hotel->tenant_id ?? $hotelId);
        }

        $existing = UserHotelPermission::where('user_id', (int)$this->currentUser->id)
            ->where('hotel_id', $hotelId)
            ->find();

        if ($existing) {
            $existing->save($payload);
            return;
        }

        $payload['create_time'] = date('Y-m-d H:i:s');
        UserHotelPermission::create($payload);
    }

    private function ensureHotelCanBeDeleted(int $hotelId, bool $ignoreCurrentUserHotelPermission = false): array
    {
        $checks = [
            ['users', 'hotel_id', '用户'],
            ['user_hotel_permissions', 'hotel_id', '用户酒店权限'],
            ['daily_reports', 'hotel_id', '日报'],
            ['monthly_tasks', 'hotel_id', '月任务'],
            ['online_daily_data', 'system_hotel_id', '线上数据'],
            ['operation_logs', 'hotel_id', '操作日志'],
            ['field_mappings', 'hotel_id', '字段映射'],
            ['hotel_field_templates', 'hotel_id', '字段模板'],
            ['room_types', 'hotel_id', '房型'],
            ['devices', 'hotel_id', '设备'],
            ['device_maintenance', 'hotel_id', '设备维护'],
            ['energy_consumption', 'hotel_id', '能耗记录'],
            ['energy_benchmarks', 'hotel_id', '能耗基准'],
            ['energy_saving_suggestions', 'hotel_id', '节能建议'],
            ['maintenance_plans', 'hotel_id', '维护计划'],
            ['price_suggestions', 'hotel_id', '价格建议'],
            ['demand_forecasts', 'hotel_id', '需求预测'],
            ['knowledge_categories', 'hotel_id', '知识分类'],
            ['knowledge_base', 'hotel_id', '知识库'],
            ['transfer_records', 'hotel_id', '转让记录'],
            ['operation_strategy_actions', 'hotel_id', '运营策略动作'],
        ];

        $references = [];
        foreach ($checks as [$table, $column, $label]) {
            $count = $ignoreCurrentUserHotelPermission && $table === 'user_hotel_permissions'
                ? $this->countHotelPermissionRowsExcludingCurrentUser($hotelId)
                : $this->countReferenceRows($table, $column, $hotelId);
            if ($count > 0) {
                $references[] = ['table' => $table, 'label' => $label, 'count' => $count];
            }
        }

        return $references;
    }

    private function countHotelPermissionRowsExcludingCurrentUser(int $hotelId): int
    {
        if (!$this->tableColumnExists('user_hotel_permissions', 'hotel_id')) {
            return 0;
        }

        $query = Db::name('user_hotel_permissions')->where('hotel_id', $hotelId);
        if ($this->currentUser) {
            $query->where('user_id', '<>', (int)$this->currentUser->id);
        }

        return (int)$query->count();
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
