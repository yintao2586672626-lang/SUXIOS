<?php
declare(strict_types=1);

namespace app\service;

use app\model\User;
use app\model\SystemConfig;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

/** Tenant-scoped manual records; no automatic table creation or business-data import. */
class InvestmentPaybackService
{
    private const MAX_LAYOUT_PROJECTS = 1000;
    private int $tenantId;
    private int $actorId;
    private PermissionService $permissions;
    private InvestmentPaybackCalculator $calculator;
    private HotelScopeService $hotelScope;

    public function __construct(private User $user, ?PermissionService $permissions = null, ?InvestmentPaybackCalculator $calculator = null, ?HotelScopeService $hotelScope = null, private bool $readOnly = false)
    {
        $this->tenantId = (int)($user->tenant_id ?? 0);
        $this->actorId = (int)($user->id ?? 0);
        if ($this->actorId <= 0) {
            throw new RuntimeException('未登录', 401);
        }
        if ($this->tenantId <= 0) {
            throw new RuntimeException('当前账号缺少租户归属', 403);
        }
        $this->permissions = $permissions ?? new PermissionService();
        $this->calculator = $calculator ?? new InvestmentPaybackCalculator();
        $this->hotelScope = $hotelScope ?? new HotelScopeService();
        $this->authorize(null);
    }

    public function projects(array $filters = []): array
    {
        if (array_key_exists('tenant_id', $filters) && (int)$filters['tenant_id'] !== $this->tenantId) {
            throw new RuntimeException('无权访问此投资项目租户', 403);
        }
        $hotelId = null;
        if (array_key_exists('hotel_id', $filters)) {
            $hotelId = self::nullableId($filters['hotel_id'], '酒店编号');
            if ($hotelId === null) {
                throw new InvalidArgumentException('酒店编号必须大于0');
            }
            $this->assertHotelTenant($hotelId);
            $this->authorize($hotelId);
        }
        $page = max(1, (int)($filters['page'] ?? 1));
        $pageSize = min(100, max(1, (int)($filters['page_size'] ?? 20)));
        $query = $this->accessibleProjectsQuery($hotelId);
        // Read the full accessible preference before applying page/search/archive filters.
        $accessibleIds = (clone $query)->column('id');
        try {
            $order = $this->accessibleLayoutOrder($this->readLayoutOrder(), $accessibleIds);
            $layout = ['order' => $order, 'status' => 'ready'];
        } catch (\Throwable $exception) {
            // A failed personal preference must not conceal readable ledger facts.
            // The UI can display the default order while disabling layout edits.
            $order = [];
            $layout = ['order' => null, 'status' => 'error', 'message' => '卡片顺序读取失败，请重试'];
        }
        if (!self::boolean($filters['include_archived'] ?? false)) {
            $query->whereNull('archived_at');
        }
        $search = trim((string)($filters['search'] ?? ''));
        if ($search !== '') {
            $query->whereLike('project_name', '%' . addcslashes($search, '%_\\') . '%');
        }
        $status = trim((string)($filters['status'] ?? ''));
        if ($status !== '') {
            if (!in_array($status, ['draft', 'preparing', 'operating', 'exited'], true)) {
                throw new InvalidArgumentException('项目状态无效');
            }
            $query->where('status', $status);
        }
        $total = (clone $query)->count();
        if ($order !== []) {
            $case = 'CASE id';
            $bindings = [];
            foreach ($order as $position => $id) {
                $parameter = 'payback_layout_' . $position;
                $case .= ' WHEN :' . $parameter . ' THEN ' . $position;
                $bindings[$parameter] = $id;
            }
            $query->orderRaw($case . ' ELSE ' . count($order) . ' END', $bindings);
        }
        $rows = $query->order('id', 'desc')->page($page, $pageSize)->select()->toArray();
        $list = [];
        foreach ($rows as $row) {
            $project = $this->formatProject($row);
            $list[] = array_merge($project, ['summary' => $this->calculator->summarize($project, $this->entries((int)$row['id']), $filters['as_of'] ?? null)]);
        }
        return ['list' => $list, 'pagination' => ['page' => $page, 'page_size' => $pageSize, 'total' => $total, 'total_page' => (int)ceil($total / $pageSize)], 'layout' => $layout];
    }

    /** Personal display preference; never updates projects, ledger values, versions, or audit history. */
    public function saveLayout(array $input): array
    {
        $this->assertWriteMode();
        $submitted = self::normalizeLayoutOrder($input['order'] ?? null);
        foreach ($submitted as $id) {
            $this->findProject($id);
        }
        $order = Db::transaction(function () use ($submitted): array {
            $saved = $this->readLayoutOrder(true);
            $accessibleIds = array_map('intval', $this->accessibleProjectsQuery()->order('id', 'desc')->column('id'));
            foreach ($submitted as $id) {
                if (!in_array($id, $accessibleIds, true)) {
                    throw new RuntimeException('无权访问此投资项目或酒店', 403);
                }
            }
            $current = $this->accessibleLayoutOrder($saved, $accessibleIds);
            // Unsaved/new projects follow the saved order by default, but get slots
            // in this baseline so their first drag can move them anywhere.
            $current = array_merge($current, array_values(array_diff($accessibleIds, $current)));
            $submittedSet = array_fill_keys($submitted, true);
            $next = 0;
            foreach ($current as &$id) {
                if (isset($submittedSet[$id])) {
                    $id = $submitted[$next++];
                }
            }
            unset($id);
            $current = self::normalizeLayoutOrder($current);
            $stored = json_encode($current, JSON_THROW_ON_ERROR);
            if (!SystemConfig::setValue($this->layoutKey(), $stored, '投资回本卡片个人显示顺序')) {
                throw new RuntimeException('卡片顺序保存失败');
            }
            // SystemConfig maintains a value cache. Confirm a fresh database read
            // instead of treating its write cache as durable readback evidence.
            if ($this->readLayoutOrder() !== $current) {
                throw new RuntimeException('卡片顺序保存回读不一致');
            }
            return $current;
        });
        return ['order' => $order];
    }

    private function accessibleProjectsQuery(?int $hotelId = null): \think\db\Query
    {
        if ($hotelId !== null) {
            return Db::name('investment_payback_projects')->where('tenant_id', $this->tenantId)->where('hotel_id', $hotelId);
        }
        $hotelIds = $this->hotelScope->accessibleHotelIds($this->user, 'investment.simulate');
        if ($this->readOnly) {
            $hotelIds = array_values(array_unique(array_merge($hotelIds, $this->hotelScope->accessibleHotelIds($this->user, 'investment.view'))));
        }
        $hotelIds = array_values(array_filter($hotelIds, fn(int $id): bool => $this->hotelAllowed($id)));
        return Db::name('investment_payback_projects')->where('tenant_id', $this->tenantId)->where(function ($query) use ($hotelIds): void {
            $query->whereNull('hotel_id');
            if ($hotelIds !== []) {
                $query->whereOr('hotel_id', 'in', $hotelIds);
            }
        });
    }

    private function layoutKey(): string
    {
        return 'investment_payback_order_t' . $this->tenantId . '_u' . $this->actorId;
    }

    private function readLayoutOrder(bool $lock = false): array
    {
        $query = Db::name('system_config')->where('config_key', $this->layoutKey());
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        if (!$row) {
            return [];
        }
        try {
            return self::normalizeLayoutOrder(json_decode((string)$row['config_value'], true, 512, JSON_THROW_ON_ERROR));
        } catch (\JsonException|InvalidArgumentException $exception) {
            throw new RuntimeException('卡片顺序读取失败，请重试', 0, $exception);
        }
    }

    private function accessibleLayoutOrder(array $order, array $accessibleIds): array
    {
        $accessibleSet = array_fill_keys(array_map('intval', $accessibleIds), true);
        return array_values(array_filter($order, static fn(int $id): bool => isset($accessibleSet[$id])));
    }

    private static function normalizeLayoutOrder($order): array
    {
        if (!is_array($order) || !array_is_list($order)) {
            throw new InvalidArgumentException('卡片顺序必须是项目编号数组');
        }
        if (count($order) > self::MAX_LAYOUT_PROJECTS) {
            throw new InvalidArgumentException('卡片顺序最多支持1000个项目');
        }
        $seen = [];
        foreach ($order as $id) {
            if (!is_int($id) || $id <= 0) {
                throw new InvalidArgumentException('卡片顺序只能包含正整数项目编号');
            }
            if (isset($seen[$id])) {
                throw new InvalidArgumentException('卡片顺序不能包含重复项目编号');
            }
            $seen[$id] = true;
        }
        return $order;
    }

    public function detail(int $id, ?string $asOf = null): array
    {
        $project = $this->formatProject($this->findProject($id));
        $entries = $this->entries($id);
        $events = Db::name('investment_payback_events')->where('tenant_id', $this->tenantId)->where('project_id', $id)->order('id', 'desc')->limit(100)->select()->toArray();
        foreach ($events as &$event) {
            $event['id'] = (int)$event['id'];
            $event['actor_id'] = (int)$event['actor_id'];
            $event['project_version'] = (int)$event['project_version'];
            $event['payload'] = json_decode((string)$event['payload_json'], true, 512, JSON_THROW_ON_ERROR);
            // Scenario snapshots have their own exact-readback endpoint; the ledger audit shows their compact summaries.
            unset($event['payload']['scenario_snapshot']);
            unset($event['payload_json']);
        }
        unset($event);
        return [
            'project' => $project,
            'entries' => $entries,
            'summary' => $this->calculator->summarize($project, $entries, $asOf),
            'audit_history' => $events,
            'audit_history_limit' => 100,
            'source_label' => InvestmentPaybackCalculator::SOURCE_LABEL,
            'can_delete_entries' => !$this->readOnly && $project['archived_at'] === null && $this->user->isSuperAdmin(),
        ];
    }

    public function saveProject(array $input): array
    {
        $this->assertWriteMode();
        $asOf = self::requestedAsOf($input);
        $id = (int)($input['id'] ?? 0);
        if ($id < 0) {
            throw new InvalidArgumentException('项目编号无效');
        }
        $requestId = $id === 0 ? self::requestId($input['client_request_id'] ?? '') : '';
        try {
            $resultId = Db::transaction(function () use ($id, $requestId, $input): int {
            $old = $id > 0 ? $this->findProject($id, true) : null;
            if ($old) {
                $this->assertWritable($old);
                if ($this->assertVersion($old, $input, true)) return $id;
            }
            $data = self::normalizeProject($input, $old);
            $this->authorize($data['hotel_id']);
            if ($data['hotel_id'] !== null) {
                $this->assertHotelTenant($data['hotel_id']);
            }
            if ($old) {
                $entries = $this->entries($id);
                $actualExists = $old['opening_as_of'] !== null || array_filter($entries, fn(array $row): bool => !$row['is_planned']) !== [];
                if ($actualExists) {
                    foreach (['investor_name', 'hotel_id', 'basis', 'currency'] as $field) {
                        if ((string)($old[$field] ?? '') !== (string)($data[$field] ?? '')) {
                            throw new RuntimeException('已有实际资金历史后不能变更主体、酒店、币种或统计口径；请另建项目', 409);
                        }
                    }
                }
                foreach ($entries as $entry) {
                    if ($entry['voided_at'] !== null || $entry['is_planned']) {
                        continue;
                    }
                    [$start, $end] = InvestmentPaybackCalculator::period($entry['date'], $entry['precision']);
                    if ($data['opening_as_of'] !== null && $start <= $data['opening_as_of']) {
                        throw new RuntimeException('期初截至日与现有实际明细重叠；先核对并作废重叠明细，不能重复累计', 409);
                    }
                    if ($entry['kind'] === 'investment' && $data['first_invested_on'] !== null && $data['first_invested_on'] > $end) {
                        throw new InvalidArgumentException('首次实际投入日期不能晚于现有实际投入');
                    }
                }
                // Confirmation belongs to the exact opening balance and cash
                // basis that was reviewed. Save a changed basis first, then
                // explicitly confirm it in a separate versioned update.
                $data = $this->invalidateChangedProjectHistory($old, $data);
            }
            $digest = self::digest($data);
            if (!$old) {
                $existing = Db::name('investment_payback_projects')->where('tenant_id', $this->tenantId)->where('created_by', $this->actorId)->where('client_request_id', $requestId)->find();
                if ($existing) {
                    $this->assertRetry($existing, $digest);
                    $this->authorize($existing['hotel_id'] === null ? null : (int)$existing['hotel_id']);
                    return (int)$existing['id'];
                }
                $now = date('Y-m-d H:i:s');
                $new = array_merge($data, ['tenant_id' => $this->tenantId, 'client_request_id' => $requestId, 'input_digest' => $digest, 'version' => 1, 'created_by' => $this->actorId, 'updated_by' => $this->actorId, 'created_at' => $now, 'updated_at' => $now]);
                $newId = (int)Db::name('investment_payback_projects')->insertGetId($new);
                $this->event($newId, null, 'project_created', null, $this->formatProject($this->findProject($newId)), null, $digest);
                return $newId;
            }
            if (hash_equals((string)$old['input_digest'], $digest)) {
                return $id;
            }
            $before = $this->calculator->summarize($this->formatProject($old), $this->entries($id));
            Db::name('investment_payback_projects')->where('tenant_id', $this->tenantId)->where('id', $id)->update(array_merge($data, ['input_digest' => $digest, 'version' => (int)$old['version'] + 1, 'updated_by' => $this->actorId, 'updated_at' => date('Y-m-d H:i:s')]));
            $this->event($id, null, 'project_updated', $this->formatProject($old), $this->formatProject($this->findProject($id)), $before);
            return $id;
            });
        } catch (\Throwable $exception) {
            if ($id > 0) {
                throw $exception;
            }
            // A concurrent creation may win the unique request key after our
            // initial lookup. Re-read the committed winner, never create twice.
            $existing = Db::name('investment_payback_projects')->where('tenant_id', $this->tenantId)->where('created_by', $this->actorId)->where('client_request_id', $requestId)->find();
            if (!$existing) {
                throw $exception;
            }
            $this->assertRetry($existing, self::digest(self::normalizeProject($input)));
            $this->authorize($existing['hotel_id'] === null ? null : (int)$existing['hotel_id']);
            $resultId = (int)$existing['id'];
        }
        return $this->detail($resultId, $asOf);
    }

    public function archive(int $id, array $input = []): array
    {
        $this->assertWriteMode();
        $asOf = self::requestedAsOf($input);
        Db::transaction(function () use ($id, $input): void {
            $old = $this->findProject($id, true);
            if ($old['archived_at'] !== null) {
                return;
            }
            $this->assertVersion($old, $input);
            Db::name('investment_payback_projects')->where('tenant_id', $this->tenantId)->where('id', $id)->update([
                'archived_at' => date('Y-m-d H:i:s'), 'archive_reason' => self::text($input['reason'] ?? '人工归档', '归档原因', 1000),
                'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => $this->actorId, 'version' => (int)$old['version'] + 1,
            ]);
            $this->event($id, null, 'project_archived', $this->formatProject($old), $this->formatProject($this->findProject($id)));
        });
        return $this->detail($id, $asOf);
    }

    public function saveEntry(int $projectId, array $input): array
    {
        $this->assertWriteMode();
        $asOf = self::requestedAsOf($input);
        Db::transaction(function () use ($projectId, $input): void {
            $project = $this->findProject($projectId, true);
            $this->assertWritable($project);
            $id = (int)($input['id'] ?? 0);
            if ($id < 0) {
                throw new InvalidArgumentException('资金记录编号无效');
            }
            $old = $id > 0 ? $this->findEntry($projectId, $id) : null;
            if ($old) {
                if ($old['voided_at'] !== null) {
                    throw new RuntimeException('已作废记录不能重新编辑；请新增修正记录', 409);
                }
                if ($this->assertVersion($old, $input, true)) return;
            }
            $data = self::normalizeEntry($input, $old);
            $digest = self::digest($data);
            $requestId = $id === 0 ? self::requestId($input['client_request_id'] ?? '') : '';
            if ($id === 0) {
                $existing = Db::name('investment_payback_entries')->where('tenant_id', $this->tenantId)->where('project_id', $projectId)->where('created_by', $this->actorId)->where('client_request_id', $requestId)->find();
                if ($existing) {
                    $this->assertRetry($existing, $digest);
                    return;
                }
            } elseif (hash_equals((string)$old['input_digest'], $digest)) {
                return;
            }
            $this->validateEntryProject($project, $data, $id);
            $before = $this->calculator->summarize($this->formatProject($project), $this->entries($projectId));
            $now = date('Y-m-d H:i:s');
            if ($old) {
                Db::name('investment_payback_entries')->where('tenant_id', $this->tenantId)->where('project_id', $projectId)->where('id', $id)->update(array_merge($data, ['input_digest' => $digest, 'version' => (int)$old['version'] + 1, 'updated_by' => $this->actorId, 'updated_at' => $now]));
            } else {
                $id = (int)Db::name('investment_payback_entries')->insertGetId(array_merge($data, ['tenant_id' => $this->tenantId, 'project_id' => $projectId, 'client_request_id' => $requestId, 'input_digest' => $digest, 'version' => 1, 'created_by' => $this->actorId, 'updated_by' => $this->actorId, 'created_at' => $now, 'updated_at' => $now]));
            }
            $this->touchProject($project, $old, $data);
            $this->event($projectId, $id, $old ? 'entry_updated' : 'entry_created', $old ? $this->formatEntry($old) : null, $this->formatEntry($this->findEntry($projectId, $id)), $before, $old ? null : $digest);
        });
        return $this->detail($projectId, $asOf);
    }

    public function voidEntry(int $projectId, int $entryId, array $input): array
    {
        $this->assertWriteMode();
        $asOf = self::requestedAsOf($input);
        $reason = self::text($input['reason'] ?? '', '作废原因', 1000, true);
        Db::transaction(function () use ($projectId, $entryId, $input, $reason): void {
            $project = $this->findProject($projectId, true);
            $this->assertWritable($project);
            $old = $this->findEntry($projectId, $entryId);
            if ($old['voided_at'] !== null) {
                return;
            }
            if ($old['kind'] === 'recovery' && $this->linkedRefunds($projectId, $entryId) !== []) {
                throw new RuntimeException('原收款已有有效关联退款，请先核对并作废关联退款后再作废原款', 409);
            }
            $this->assertVersion($old, $input);
            $before = $this->calculator->summarize($this->formatProject($project), $this->entries($projectId));
            Db::name('investment_payback_entries')->where('tenant_id', $this->tenantId)->where('project_id', $projectId)->where('id', $entryId)->update([
                'voided_at' => date('Y-m-d H:i:s'), 'voided_by' => $this->actorId, 'void_reason' => $reason,
                'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => $this->actorId, 'version' => (int)$old['version'] + 1,
            ]);
            $this->touchProject($project, $old, null);
            $this->event($projectId, $entryId, 'entry_voided', $this->formatEntry($old), $this->formatEntry($this->findEntry($projectId, $entryId)), $before);
        });
        return $this->detail($projectId, $asOf);
    }

    public function deleteEntry(int $projectId, int $entryId, array $input): array
    {
        $this->assertWriteMode();
        if (!$this->user->isSuperAdmin()) {
            throw new RuntimeException('仅管理员可删除资金记录', 403);
        }
        $asOf = self::requestedAsOf($input);
        if (!isset($input['expected_version']) || !is_int($input['expected_version']) || $input['expected_version'] <= 0) {
            throw new InvalidArgumentException('删除资金记录须提供正整数记录版本');
        }
        $reason = self::text($input['reason'] ?? '管理员主动删除', '删除原因', 1000);
        $reason = $reason === '' ? '管理员主动删除' : $reason;
        return Db::transaction(function () use ($projectId, $entryId, $input, $reason, $asOf): array {
            // The same project lock used by entry edits/refunds prevents a new
            // dependent refund from appearing between this check and deletion.
            $project = $this->findProject($projectId, true);
            $this->assertWritable($project);
            $old = $this->findEntry($projectId, $entryId);
            $this->assertVersion($old, $input);
            if (Db::name('investment_payback_entries')->where('tenant_id', $this->tenantId)->where('project_id', $projectId)
                ->where('original_entry_id', $entryId)->where('id', '<>', $entryId)->find()) {
                // Retained voided/planned rows still require their original
                // record. Reject every dependency rather than create an orphan.
                throw new RuntimeException('此记录仍有关联退款或资金记录，请先核对并删除关联记录后再删除原款', 409);
            }
            $before = $this->calculator->summarize($this->formatProject($project), $this->entries($projectId));
            $deleted = Db::name('investment_payback_entries')->where('tenant_id', $this->tenantId)->where('project_id', $projectId)
                ->where('id', $entryId)->where('version', (int)$old['version'])->delete();
            if ($deleted !== 1) {
                throw new RuntimeException('记录已被修改，请重新读取后再删除', 409);
            }
            $this->touchProject($project, $old, null);
            // The ledger migration has no FK/cascade from audit events to entry
            // rows. Keep the historical ID and the complete before snapshot.
            $this->event($projectId, $entryId, 'entry_deleted', $this->formatEntry($old), [
                'id' => $entryId, 'deleted' => true, 'delete_reason' => $reason,
            ], $before);
            $readback = $this->detail($projectId, $asOf);
            foreach ($readback['entries'] as $entry) {
                if ($entry['id'] === $entryId) {
                    throw new RuntimeException('资金记录删除回读不一致');
                }
            }
            return $readback;
        });
    }

    public static function normalizeProject(array $input, ?array $old = null): array
    {
        $merged = array_merge($old ?? [], $input);
        $data = [
            'project_name' => self::text($merged['project_name'] ?? '', '项目名称', 160, true),
            'investor_name' => self::text($merged['investor_name'] ?? '', '投资主体', 160, true),
            'hotel_id' => self::nullableId($merged['hotel_id'] ?? null, '酒店编号'),
            'basis' => (string)($merged['basis'] ?? 'investor_cash'),
            'currency' => (string)($merged['currency'] ?? 'CNY'),
            'status' => (string)($merged['status'] ?? 'draft'),
            'first_invested_on' => self::nullableDate($merged['first_invested_on'] ?? null, '首次实际投入日期'),
            'forecast_as_of' => self::nullableDate($merged['forecast_as_of'] ?? InvestmentPaybackCalculator::today(), '测算基准日'),
            'history_complete_through' => self::nullableDate($merged['history_complete_through'] ?? null, '历史核对截至日'),
            'expected_monthly_amount' => self::nullableMoney($merged['expected_monthly_amount'] ?? null, true, '预计每月净收回'),
            'expected_source' => self::text($merged['expected_source'] ?? '', '预测假设来源', 1000),
            'opening_as_of' => self::nullableDate($merged['opening_as_of'] ?? null, '期初截至日'),
            'opening_invested' => self::nullableMoney($merged['opening_invested'] ?? null, false, '期初累计投入'),
            'opening_recovered' => self::nullableMoney($merged['opening_recovered'] ?? null, true, '期初累计净收回'),
            'opening_source' => self::text($merged['opening_source'] ?? '', '期初汇总来源', 1000),
            'notes' => self::text($merged['notes'] ?? '', '项目备注', 2000),
        ];
        if ($data['basis'] !== 'investor_cash' || $data['currency'] !== 'CNY') {
            throw new InvalidArgumentException('首版只支持投资人实际投入/实际实收人民币口径');
        }
        if (!in_array($data['status'], ['draft', 'preparing', 'operating', 'exited'], true)) {
            throw new InvalidArgumentException('项目状态无效');
        }
        if ($data['forecast_as_of'] === null) {
            throw new InvalidArgumentException('测算基准日不能为空');
        }
        foreach (['first_invested_on', 'forecast_as_of', 'history_complete_through', 'opening_as_of'] as $field) {
            if ($data[$field] !== null && $data[$field] > InvestmentPaybackCalculator::today()) {
                throw new InvalidArgumentException('实际日期、核对截至日和测算基准日不能晚于今天');
            }
        }
        $openingCount = (int)($data['opening_as_of'] !== null) + (int)($data['opening_invested'] !== null) + (int)($data['opening_recovered'] !== null);
        if ($openingCount > 0 && ($openingCount !== 3 || $data['opening_source'] === '')) {
            throw new InvalidArgumentException('期初汇总须同时填写截至日、累计投入、累计净收回和来源说明');
        }
        if ($openingCount > 0 && InvestmentPaybackCalculator::fen($data['opening_invested']) <= 0) {
            throw new InvalidArgumentException('期初实际累计投入须大于0；尚未投入请保存草稿');
        }
        if ($data['first_invested_on'] !== null && $data['opening_as_of'] !== null && $data['first_invested_on'] > $data['opening_as_of']) {
            throw new InvalidArgumentException('首次投入日期不能晚于期初汇总截至日');
        }
        if ($data['expected_monthly_amount'] !== null && $data['expected_source'] === '') {
            throw new InvalidArgumentException('填写预计每月净收回时须说明假设来源');
        }
        return $data;
    }

    public static function normalizeEntry(array $input, ?array $old = null): array
    {
        if ($old !== null) {
            $old['date'] = $old['business_date'];
        }
        $merged = array_merge($old ?? [], $input);
        $kind = (string)($merged['kind'] ?? '');
        if (!in_array($kind, ['investment', 'recovery', 'refund'], true)) {
            throw new InvalidArgumentException('资金类型须为 investment/recovery/refund');
        }
        $amount = InvestmentPaybackCalculator::fen($merged['amount'] ?? null);
        $confirmedZero = self::boolean($merged['confirmed_zero'] ?? false);
        if ($amount <= 0 && !($kind === 'recovery' && $amount === 0 && $confirmedZero)) {
            throw new InvalidArgumentException('实际投入和退款金额须大于0；0元收回须明确勾选已核对');
        }
        $date = trim((string)($merged['date'] ?? ''));
        $precision = (string)($merged['precision'] ?? 'day');
        [$start] = InvestmentPaybackCalculator::period($date, $precision);
        $planned = self::boolean($merged['is_planned'] ?? false);
        if ($start > InvestmentPaybackCalculator::today() && !$planned) {
            throw new InvalidArgumentException('未来资金日期必须明确登记为计划，不能计入实际');
        }
        $source = self::text($merged['source'] ?? '', '资金来源说明', 1000);
        $notes = self::text($merged['notes'] ?? '', '资金备注/退款原因', 2000);
        $originalId = self::nullableId($merged['original_entry_id'] ?? null, '原收回记录编号');
        if ($kind === 'refund' && $notes === '') {
            throw new InvalidArgumentException('退款/冲回须填写原因与原收款来源说明');
        }
        if ($kind !== 'refund' && $originalId !== null) {
            throw new InvalidArgumentException('仅退款/冲回可关联原收回记录');
        }
        return [
            'kind' => $kind, 'amount' => InvestmentPaybackCalculator::yuan($amount), 'business_date' => $date, 'precision' => $precision,
            'is_planned' => $planned ? 1 : 0, 'confirmed_zero' => $confirmedZero ? 1 : 0,
            'category' => self::text($merged['category'] ?? '', '资金类别', 120), 'source' => $source, 'notes' => $notes, 'original_entry_id' => $originalId,
        ];
    }

    private function validateEntryProject(array $project, array $data, int $entryId = 0): void
    {
        [$start, $end] = InvestmentPaybackCalculator::period($data['business_date'], $data['precision']);
        if (!$data['is_planned'] && $project['opening_as_of'] !== null && $start <= $project['opening_as_of']) {
            throw new RuntimeException('此资金日期已包含在期初汇总内；请核对后修正期初，不能重复记账', 409);
        }
        if (!$data['is_planned'] && $data['kind'] === 'investment' && $project['first_invested_on'] !== null && $end < $project['first_invested_on']) {
            throw new InvalidArgumentException('此投入早于项目首次投入日期，请先修正项目日期');
        }
        if ($data['original_entry_id'] !== null) {
            if ($entryId > 0 && $data['original_entry_id'] === $entryId) {
                throw new InvalidArgumentException('退款不能把自身作为原实际收回记录');
            }
            $original = $this->findEntry((int)$project['id'], $data['original_entry_id']);
            if ($original['kind'] !== 'recovery' || $original['voided_at'] !== null || (int)$original['is_planned'] === 1) {
                throw new InvalidArgumentException('退款只能关联本项目有效的实际收回记录');
            }
            $available = InvestmentPaybackCalculator::fen($original['amount']);
            if (!$data['is_planned']) {
                foreach ($this->linkedRefunds((int)$project['id'], $data['original_entry_id'], $entryId) as $refund) {
                    $available -= InvestmentPaybackCalculator::fen($refund['amount']);
                }
            }
            if (InvestmentPaybackCalculator::fen($data['amount']) > $available) {
                throw new InvalidArgumentException('本次及已有有效关联退款不能超过原实际收回金额；请核对退款来源');
            }
            [$originalStart] = InvestmentPaybackCalculator::period($original['business_date'], $original['precision']);
            if ($end < $originalStart) {
                throw new InvalidArgumentException('关联退款日期不能早于原实际收款日期/月份');
            }
        }
        if ($entryId > 0) {
            $refunds = $this->linkedRefunds((int)$project['id'], $entryId);
            if ($refunds !== []) {
                if ($data['kind'] !== 'recovery' || $data['is_planned']) {
                    throw new RuntimeException('此原收款已有有效关联退款，不能改成投入、退款或计划', 409);
                }
                $available = InvestmentPaybackCalculator::fen($data['amount']);
                foreach ($refunds as $refund) {
                    $available -= InvestmentPaybackCalculator::fen($refund['amount']);
                    [, $refundEnd] = InvestmentPaybackCalculator::period($refund['business_date'], $refund['precision']);
                    if ($refundEnd < $start) {
                        throw new InvalidArgumentException('原收款更正日期不能晚于已有有效关联退款日期/月份');
                    }
                }
                if ($available < 0) {
                    throw new InvalidArgumentException('更正后的原收款金额不能小于已有有效关联退款合计');
                }
            }
        }
    }

    /** The project lock serializes refunds and their original receipt edits. */
    private function linkedRefunds(int $projectId, int $originalId, int $excludedId = 0): array
    {
        $query = Db::name('investment_payback_entries')->where('tenant_id', $this->tenantId)->where('project_id', $projectId)
            ->where('kind', 'refund')->where('original_entry_id', $originalId)->where('is_planned', 0)->whereNull('voided_at');
        if ($excludedId > 0) {
            $query->where('id', '<>', $excludedId);
        }
        return $query->select()->toArray();
    }

    private function findProject(int $id, bool $lock = false): array
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('项目编号必须大于0');
        }
        $query = Db::name('investment_payback_projects')->where('tenant_id', $this->tenantId)->where('id', $id);
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        if (!$row) {
            throw new RuntimeException('项目不存在或不在当前租户范围', 404);
        }
        $this->authorize($row['hotel_id'] === null ? null : (int)$row['hotel_id']);
        return $row;
    }

    private function findEntry(int $projectId, int $id): array
    {
        $row = Db::name('investment_payback_entries')->where('tenant_id', $this->tenantId)->where('project_id', $projectId)->where('id', $id)->find();
        if (!$row) {
            throw new RuntimeException('资金记录不存在或不属于此项目', 404);
        }
        return $row;
    }

    private function entries(int $projectId): array
    {
        $rows = Db::name('investment_payback_entries')->where('tenant_id', $this->tenantId)->where('project_id', $projectId)->order('business_date', 'asc')->order('id', 'asc')->select()->toArray();
        return array_map(fn(array $row): array => $this->formatEntry($row), $rows);
    }

    private function formatProject(array $row): array
    {
        unset($row['input_digest']);
        foreach (['id', 'tenant_id', 'version', 'created_by', 'updated_by'] as $field) {
            $row[$field] = (int)$row[$field];
        }
        $row['hotel_id'] = $row['hotel_id'] === null ? null : (int)$row['hotel_id'];
        foreach (['opening_invested', 'opening_recovered', 'expected_monthly_amount'] as $field) {
            $row[$field] = $row[$field] === null ? null : InvestmentPaybackCalculator::yuan(InvestmentPaybackCalculator::fen($row[$field], $field !== 'opening_invested'));
        }
        $row['source_label'] = InvestmentPaybackCalculator::SOURCE_LABEL;
        return $row;
    }

    private function formatEntry(array $row): array
    {
        unset($row['input_digest']);
        foreach (['id', 'tenant_id', 'project_id', 'version', 'created_by', 'updated_by'] as $field) {
            $row[$field] = (int)$row[$field];
        }
        $row['date'] = $row['business_date'];
        unset($row['business_date']);
        $row['amount'] = InvestmentPaybackCalculator::yuan(InvestmentPaybackCalculator::fen($row['amount']));
        $row['is_planned'] = (bool)$row['is_planned'];
        $row['confirmed_zero'] = (bool)$row['confirmed_zero'];
        $row['original_entry_id'] = $row['original_entry_id'] === null ? null : (int)$row['original_entry_id'];
        $row['voided_by'] = $row['voided_by'] === null ? null : (int)$row['voided_by'];
        $row['source_label'] = InvestmentPaybackCalculator::SOURCE_LABEL;
        return $row;
    }

    private function authorize(?int $hotelId): void
    {
        if (!$this->hotelAllowed($hotelId)) {
            throw new RuntimeException('无权访问此投资项目或酒店', 403);
        }
    }

    private function hotelAllowed(?int $hotelId): bool
    {
        if ($this->readOnly && ($this->permissions->authorize($this->user, 'investment.view', $hotelId)['allowed'] ?? false) === true) {
            return true;
        }
        return ($this->permissions->authorize($this->user, 'investment.simulate', $hotelId)['allowed'] ?? false) === true;
    }

    private function assertWriteMode(): void
    {
        if ($this->readOnly) {
            throw new RuntimeException('投资资金连接只允许读取', 403);
        }
    }

    private function assertHotelTenant(int $hotelId): void
    {
        if (!Db::name('hotels')->where('id', $hotelId)->where('tenant_id', $this->tenantId)->find()) {
            throw new RuntimeException('关联酒店不存在或不属于当前租户', 403);
        }
    }

    private function assertWritable(array $project): void
    {
        if ($project['archived_at'] !== null) {
            throw new RuntimeException('项目已归档，只能查阅历史', 409);
        }
    }

    private function assertVersion(array $row, array $input, bool $allowExactUpdateReplay = false): bool
    {
        if (!array_key_exists('expected_version', $input)) {
            return false;
        }
        $version = $input['expected_version'];
        // Omission remains compatible with older clients; an explicit value
        // must never turn null, decimals, booleans or malformed text into a
        // bypass or a different integer version through PHP coercion.
        if (!(is_int($version) && $version > 0)
            && !(is_string($version) && preg_match('/^[1-9]\d*$/D', $version) && (string)(int)$version === $version)) {
            throw new InvalidArgumentException('记录版本须为正整数或规范数字字符串');
        }
        if ((int)$version !== (int)$row['version']) {
            if ($allowExactUpdateReplay && $this->isExactUpdateReplay($row, $input)) return true;
            throw new RuntimeException('记录已被修改，请重新读取后再保存', 409);
        }
        return false;
    }

    /** Lost update replies may replay only the exact committed actor-scoped transition. */
    private function isExactUpdateReplay(array $row, array $input): bool
    {
        $expected = (int)$input['expected_version'];
        if ($expected === PHP_INT_MAX || (int)$row['version'] !== $expected + 1
            || (int)$row['tenant_id'] !== $this->tenantId || (int)$row['updated_by'] !== $this->actorId) return false;
        $isEntry = array_key_exists('project_id', $row);
        $projectId = (int)($isEntry ? $row['project_id'] : $row['id']);
        $query = Db::name('investment_payback_events')->where('tenant_id', $this->tenantId)->where('actor_id', $this->actorId)
            ->where('project_id', $projectId)->where('event_type', $isEntry ? 'entry_updated' : 'project_updated');
        $isEntry ? $query->where('entry_id', (int)$row['id']) : $query->whereNull('entry_id');
        $event = $query->order('id', 'desc')->find();
        try {
            $payload = $event ? json_decode((string)$event['payload_json'], true, 512, JSON_THROW_ON_ERROR) : null;
            $before = is_array($payload) ? ($payload['before'] ?? null) : null;
            $after = is_array($payload) ? ($payload['after'] ?? null) : null;
            if (!is_array($before) || !is_array($after) || ($before['version'] ?? null) !== $expected
                || ($before['id'] ?? null) !== (int)$row['id'] || ($before['tenant_id'] ?? null) !== $this->tenantId
                || ($before['client_request_id'] ?? null) !== $row['client_request_id']
                || ($isEntry && ($before['project_id'] ?? null) !== $projectId)
                || $after !== ($isEntry ? $this->formatEntry($row) : $this->formatProject($row))) return false;
            // Rebuild against the immutable old state, including automatic loss
            // of opening-history confirmation, never against the edited row.
            if ($isEntry) {
                $before['business_date'] = $before['date'];
                $data = self::normalizeEntry($input, $before);
            } else {
                $data = $this->invalidateChangedProjectHistory($before, self::normalizeProject($input, $before));
            }
            return hash_equals((string)$row['input_digest'], self::digest($data));
        } catch (\Throwable) {
            return false;
        }
    }

    private function invalidateChangedProjectHistory(array $before, array $data): array
    {
        foreach (['investor_name', 'hotel_id', 'basis', 'currency', 'first_invested_on', 'opening_as_of', 'opening_invested', 'opening_recovered', 'opening_source'] as $field) {
            if ((string)($before[$field] ?? '') !== (string)($data[$field] ?? '')) {
                $data['history_complete_through'] = null;
                break;
            }
        }
        return $data;
    }

    private function assertRetry(array $row, string $digest): void
    {
        $isEntry = array_key_exists('project_id', $row);
        $projectId = (int)($isEntry ? $row['project_id'] : $row['id']);
        $query = Db::name('investment_payback_events')->where('tenant_id', $this->tenantId)->where('actor_id', $this->actorId)
            ->where('project_id', $projectId)->where('event_type', $isEntry ? 'entry_created' : 'project_created');
        $isEntry ? $query->where('entry_id', (int)$row['id']) : $query->whereNull('entry_id');
        $event = $query->order('id')->find();
        try {
            $payload = $event ? json_decode((string)$event['payload_json'], true, 512, JSON_THROW_ON_ERROR) : null;
        } catch (\JsonException $exception) {
            throw new RuntimeException('首次保存证据无效，请重新读取后确认记录，不能自动确认重试', 409, $exception);
        }
        $after = is_array($payload) ? ($payload['after'] ?? null) : null;
        if (!is_array($after) || ($after['version'] ?? null) !== 1
            || ($after['id'] ?? null) !== (int)$row['id']
            || ($after['tenant_id'] ?? null) !== $this->tenantId
            || ($after['created_by'] ?? null) !== $this->actorId
            || ($after['client_request_id'] ?? null) !== $row['client_request_id']
            || ($isEntry && ($after['project_id'] ?? null) !== $projectId)) {
            throw new RuntimeException('首次保存证据缺失或作用域不一致，请重新读取后确认记录，不能自动确认重试', 409);
        }
        if (array_key_exists('create_input_digest', $payload)) {
            $createdDigest = $payload['create_input_digest'];
        } else {
            // Older audit snapshots did not preserve the digest. Version 1 and
            // the scoped creation evidence prove the current digest is still
            // the original one; edited legacy rows require an explicit reread.
            if ((int)$row['version'] !== 1) {
                throw new RuntimeException('旧记录已变化且缺首次保存摘要，请重新读取后确认记录，不能自动确认重试', 409);
            }
            $createdDigest = $row['input_digest'];
        }
        if (!is_string($createdDigest) || !preg_match('/^[0-9a-f]{64}$/D', $createdDigest)
            || !hash_equals($createdDigest, $digest) || !hash_equals((string)$row['input_digest'], $digest)) {
            throw new RuntimeException('重复保存标识已用于不同内容，请重新读取或使用新的保存标识', 409);
        }
    }

    /** Shared with atomic imports; planned/voided/opening-excluded rows are not actual cash. */
    public static function entryChangeInvalidatesHistory(array $project, ?array $before, ?array $after): bool
    {
        $checkedThrough = $project['history_complete_through'] ?? null;
        if ($checkedThrough === null) {
            return false;
        }
        foreach ([$before, $after] as $entry) {
            if ($entry === null || !empty($entry['voided_at']) || !empty($entry['is_planned'])) {
                continue;
            }
            [$start] = InvestmentPaybackCalculator::period((string)($entry['business_date'] ?? $entry['date']), (string)$entry['precision']);
            if ($start <= $checkedThrough && (($project['opening_as_of'] ?? null) === null || $start > $project['opening_as_of'])) {
                // A monthly record overlapping the checked interval also
                // invalidates it; do not invent a partially checked month.
                return true;
            }
        }
        return false;
    }

    private function touchProject(array $project, ?array $before, ?array $after): void
    {
        $update = [
            'version' => (int)$project['version'] + 1, 'updated_by' => $this->actorId, 'updated_at' => date('Y-m-d H:i:s'),
        ];
        if (self::entryChangeInvalidatesHistory($project, $before, $after)) {
            $update['history_complete_through'] = null;
            // Keep retry detection aligned with the persisted project. An old
            // digest would otherwise discard an explicit reconfirmation.
            $update['input_digest'] = self::digest(self::normalizeProject(['history_complete_through' => null], $project));
        }
        Db::name('investment_payback_projects')->where('tenant_id', $this->tenantId)->where('id', (int)$project['id'])->update($update);
    }

    private function event(int $projectId, ?int $entryId, string $type, ?array $beforeRecord, array $afterRecord, ?array $beforeSummary = null, ?string $createInputDigest = null): void
    {
        $project = $this->formatProject($this->findProject($projectId));
        $afterSummary = $this->calculator->summarize($project, $this->entries($projectId));
        $payload = ['before' => $beforeRecord, 'after' => $afterRecord, 'summary_before' => $beforeSummary, 'summary_after' => $afterSummary];
        if ($createInputDigest !== null) {
            // Preserve the exact initial normalized request; later edits update
            // the record digest but never this immutable creation evidence.
            $payload['create_input_digest'] = $createInputDigest;
        }
        Db::name('investment_payback_events')->insert([
            'tenant_id' => $this->tenantId, 'project_id' => $projectId, 'entry_id' => $entryId, 'actor_id' => $this->actorId,
            'event_type' => $type, 'project_version' => $project['version'], 'created_at' => date('Y-m-d H:i:s'),
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
    }

    private static function nullableId($value, string $label): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!preg_match('/^[1-9]\d{0,9}$/D', (string)$value)) {
            throw new InvalidArgumentException($label . '无效');
        }
        return (int)$value;
    }

    private static function nullableDate($value, string $label): ?string
    {
        return $value === null || $value === '' ? null : InvestmentPaybackCalculator::date(trim((string)$value), $label);
    }

    private static function requestedAsOf(array $input): ?string
    {
        $value = $input['as_of'] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('统计截至日须为 YYYY-MM-DD 日期');
        }
        $asOf = InvestmentPaybackCalculator::date(trim($value), '统计截至日');
        if ($asOf > InvestmentPaybackCalculator::today()) {
            throw new InvalidArgumentException('实际资金截至日不能晚于今天');
        }
        return $asOf;
    }

    private static function nullableMoney($value, bool $signed, string $label): ?string
    {
        return $value === null || $value === '' ? null : InvestmentPaybackCalculator::yuan(InvestmentPaybackCalculator::fen($value, $signed, $label));
    }

    private static function text($value, string $label, int $limit, bool $required = false): string
    {
        if (!is_string($value) && !is_numeric($value)) {
            throw new InvalidArgumentException($label . '格式无效');
        }
        $value = trim((string)$value);
        if (($required && $value === '') || mb_strlen($value) > $limit) {
            throw new InvalidArgumentException($label . ($value === '' ? '不能为空' : '超出长度限制'));
        }
        return $value;
    }

    private static function boolean($value): bool
    {
        if (in_array($value, [true, 1, '1'], true)) {
            return true;
        }
        if (in_array($value, [false, 0, '0', '', null], true)) {
            return false;
        }
        throw new InvalidArgumentException('布尔字段必须明确为 true/false');
    }

    private static function requestId($value): string
    {
        $value = self::text($value, '保存标识', 100, true);
        if (!preg_match('/^[A-Za-z0-9._:-]{8,100}$/D', $value)) {
            throw new InvalidArgumentException('保存标识须为8至100位字母、数字或 ._:-');
        }
        return $value;
    }

    private static function digest(array $data): string
    {
        ksort($data);
        return hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
