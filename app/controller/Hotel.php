<?php
declare(strict_types=1);

namespace app\controller;

use app\model\Hotel as HotelModel;
use app\model\OperationLog;
use app\model\UserHotelPermission;
use app\service\HotelDataMergeService;
use app\service\PermissionService;
use InvalidArgumentException;
use RuntimeException;
use think\Response;
use think\facade\Db;

class Hotel extends Base
{
    private const OTA_CHANNEL_STRATEGIES = ['ctrip_only', 'dual', 'meituan_only'];

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

        $query = HotelModel::order('id', 'desc');

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
            $otaChannelStrategy = $this->normalizeOtaChannelStrategy($data, (string)($hotel->ota_channel_strategy ?? 'dual'));
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

        return $this->success($result, $statusChanged ? "酒店已{$result['status_text']}，影响{$affectedUsers}个用户的权限" : '更新成功');
    }

    private function normalizeHotelCode($value): ?string
    {
        $code = trim((string)($value ?? ''));
        return $code === '' ? null : $code;
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
        $this->checkPermission();
        $data = $this->requestData();
        $forceDelete = $this->currentUser->isSuperAdmin() && $this->isForceDeleteRequested($data);

        $hotel = HotelModel::find($id);
        $deleteAuthorization = (new PermissionService())->authorize($this->currentUser, 'hotel.delete', $id);
        if (empty($deleteAuthorization['allowed'])) {
            return $this->error('权限不足', 403, $deleteAuthorization);
        }
        if (!$hotel) {
            return $this->error('酒店不存在');
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

        $references = $this->ensureHotelCanBeDeleted($id, !$this->currentUser->isSuperAdmin());
        if ($this->shouldBlockHotelDelete($references, $forceDelete)) {
            $canForceDelete = $this->currentUser->isSuperAdmin();
            return $this->error($canForceDelete ? '该酒店存在关联数据，超级管理员可以确认后强制删除；如需保留历史经营入口，请改为禁用酒店' : '该酒店存在关联数据，当前角色不能强制删除，请改为禁用酒店或联系管理员处理', 409, [
                'references' => $references,
                'can_force_delete' => $canForceDelete,
            ]);
        }

        $hotelName = $hotel->name;
        $forcedDelete = !empty($references) && $forceDelete;
        if (!$this->currentUser->isSuperAdmin()) {
            UserHotelPermission::where('user_id', (int)$this->currentUser->id)
                ->where('hotel_id', $id)
                ->delete();
        }
        $hotel->delete();

        OperationLog::record(
            'hotel',
            'delete',
            ($forcedDelete ? '强制删除酒店: ' : '删除酒店: ') . $hotelName,
            $this->currentUser->id ?? null,
            $id,
            null,
            $forcedDelete ? ['references' => $references] : []
        );

        return $this->success([
            'forced' => $forcedDelete,
            'references' => $forcedDelete ? $references : [],
        ], $forcedDelete ? '删除成功，关联历史数据已保留' : '删除成功');
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

    private function isTruthy($value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function normalizeOtaChannelStrategy(array $data, string $default = 'dual'): string
    {
        $value = trim((string)($data['ota_channel_strategy'] ?? $data['otaChannelStrategy'] ?? $default));
        if ($value === '') {
            return in_array($default, self::OTA_CHANNEL_STRATEGIES, true) ? $default : 'dual';
        }
        if (!in_array($value, self::OTA_CHANNEL_STRATEGIES, true)) {
            throw new InvalidArgumentException('OTA渠道策略无效，仅支持 ctrip_only、dual、meituan_only');
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
