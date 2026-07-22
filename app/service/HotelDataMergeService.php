<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

class HotelDataMergeService
{
    /**
     * Keep the migration surface explicit. `online_daily_data.hotel_id` is an
     * OTA platform hotel id, so only `online_daily_data.system_hotel_id` moves.
     *
     * @return array<int, array{table:string,column:string,label:string,scope:string}>
     */
    public function migrationPlans(): array
    {
        return [
            ['table' => 'users', 'column' => 'hotel_id', 'label' => '用户默认门店', 'scope' => 'system'],
            ['table' => 'user_hotel_permissions', 'column' => 'hotel_id', 'label' => '用户门店权限', 'scope' => 'system'],
            ['table' => 'daily_reports', 'column' => 'hotel_id', 'label' => '经营日报', 'scope' => 'operation'],
            ['table' => 'monthly_tasks', 'column' => 'hotel_id', 'label' => '月度任务', 'scope' => 'operation'],
            ['table' => 'ai_daily_reports', 'column' => 'hotel_id', 'label' => 'AI经营日报', 'scope' => 'ai'],
            ['table' => 'online_daily_data', 'column' => 'system_hotel_id', 'label' => 'OTA线上数据', 'scope' => 'ota'],
            ['table' => 'ota_ctrip_metric_facts', 'column' => 'system_hotel_id', 'label' => '携程指标事实', 'scope' => 'ota'],
            ['table' => 'ota_ctrip_entity_snapshots', 'column' => 'system_hotel_id', 'label' => '携程实体快照', 'scope' => 'ota'],
            ['table' => 'ota_ctrip_capture_runs', 'column' => 'system_hotel_id', 'label' => '携程采集批次', 'scope' => 'ota'],
            ['table' => 'ota_ctrip_capture_gaps', 'column' => 'system_hotel_id', 'label' => '携程采集缺口', 'scope' => 'ota'],
            ['table' => 'ota_ctrip_reviews', 'column' => 'system_hotel_id', 'label' => '携程点评', 'scope' => 'ota'],
            ['table' => 'ota_ctrip_orders', 'column' => 'system_hotel_id', 'label' => '携程订单', 'scope' => 'ota'],
            ['table' => 'ota_ctrip_im_sessions', 'column' => 'system_hotel_id', 'label' => '携程IM会话', 'scope' => 'ota'],
            ['table' => 'ota_ctrip_review_order_matches', 'column' => 'system_hotel_id', 'label' => '携程评价订单匹配', 'scope' => 'ota'],
            ['table' => 'platform_data_sources', 'column' => 'system_hotel_id', 'label' => '平台数据源', 'scope' => 'ota'],
            ['table' => 'platform_data_sync_tasks', 'column' => 'system_hotel_id', 'label' => '平台同步任务', 'scope' => 'ota'],
            ['table' => 'platform_data_sync_logs', 'column' => 'system_hotel_id', 'label' => '平台同步日志', 'scope' => 'ota'],
            ['table' => 'platform_data_raw_records', 'column' => 'system_hotel_id', 'label' => '平台原始记录', 'scope' => 'ota'],
            ['table' => 'agent_configs', 'column' => 'hotel_id', 'label' => 'AI配置', 'scope' => 'ai'],
            ['table' => 'agent_conversations', 'column' => 'hotel_id', 'label' => 'AI会话', 'scope' => 'ai'],
            ['table' => 'agent_logs', 'column' => 'hotel_id', 'label' => 'AI日志', 'scope' => 'ai'],
            ['table' => 'agent_tasks', 'column' => 'hotel_id', 'label' => 'AI任务', 'scope' => 'ai'],
            ['table' => 'agent_work_orders', 'column' => 'hotel_id', 'label' => 'AI工单', 'scope' => 'ai'],
            ['table' => 'operation_action_tracks', 'column' => 'hotel_id', 'label' => '运营动作跟踪', 'scope' => 'operation'],
            ['table' => 'operation_alerts', 'column' => 'hotel_id', 'label' => '运营预警', 'scope' => 'operation'],
            ['table' => 'operation_execution_intents', 'column' => 'hotel_id', 'label' => '执行意图', 'scope' => 'operation'],
            ['table' => 'operation_execution_tasks', 'column' => 'hotel_id', 'label' => '执行任务', 'scope' => 'operation'],
            ['table' => 'operation_logs', 'column' => 'hotel_id', 'label' => '操作日志', 'scope' => 'audit'],
            ['table' => 'field_mappings', 'column' => 'hotel_id', 'label' => '字段映射', 'scope' => 'config'],
            ['table' => 'hotel_field_templates', 'column' => 'hotel_id', 'label' => '字段模板', 'scope' => 'config'],
            ['table' => 'room_types', 'column' => 'hotel_id', 'label' => '房型', 'scope' => 'asset'],
            ['table' => 'devices', 'column' => 'hotel_id', 'label' => '设备', 'scope' => 'asset'],
            ['table' => 'energy_consumption', 'column' => 'hotel_id', 'label' => '能耗记录', 'scope' => 'asset'],
            ['table' => 'energy_benchmarks', 'column' => 'hotel_id', 'label' => '能耗基准', 'scope' => 'asset'],
            ['table' => 'energy_saving_suggestions', 'column' => 'hotel_id', 'label' => '节能建议', 'scope' => 'asset'],
            ['table' => 'maintenance_plans', 'column' => 'hotel_id', 'label' => '维护计划', 'scope' => 'asset'],
            ['table' => 'price_suggestions', 'column' => 'hotel_id', 'label' => '价格建议', 'scope' => 'revenue'],
            ['table' => 'demand_forecasts', 'column' => 'hotel_id', 'label' => '需求预测', 'scope' => 'revenue'],
            ['table' => 'competitor_analysis', 'column' => 'hotel_id', 'label' => '竞对分析', 'scope' => 'revenue'],
            ['table' => 'competitor_price_log', 'column' => 'store_id', 'label' => '竞对价格日志', 'scope' => 'revenue'],
            ['table' => 'knowledge_categories', 'column' => 'hotel_id', 'label' => '知识分类', 'scope' => 'knowledge'],
            ['table' => 'knowledge_base', 'column' => 'hotel_id', 'label' => '知识库', 'scope' => 'knowledge'],
            ['table' => 'knowledge_units', 'column' => 'hotel_id', 'label' => '知识单元', 'scope' => 'knowledge'],
            ['table' => 'complaint_feedbacks', 'column' => 'hotel_id', 'label' => '投诉反馈', 'scope' => 'operation'],
            ['table' => 'complaint_rooms', 'column' => 'hotel_id', 'label' => '投诉房间', 'scope' => 'operation'],
            ['table' => 'opening_projects', 'column' => 'hotel_id', 'label' => '开业项目', 'scope' => 'investment'],
            ['table' => 'transfer_records', 'column' => 'hotel_id', 'label' => '转让记录', 'scope' => 'investment'],
            ['table' => 'system_notifications', 'column' => 'hotel_id', 'label' => '系统通知', 'scope' => 'system'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(int $sourceHotelId, int $targetHotelId): array
    {
        $this->assertValidMergeTarget($sourceHotelId, $targetHotelId);

        $items = [];
        $totalSourceRows = 0;
        $expectedUpdateRows = 0;
        $skippableConflictTotal = 0;
        $blockingConflictTotal = 0;
        $blockingConflicts = [];
        foreach ($this->availableMigrationPlans() as $plan) {
            $sourceCount = $this->countRows($plan['table'], $plan['column'], $sourceHotelId);
            $targetCount = $this->countRows($plan['table'], $plan['column'], $targetHotelId);
            $conflicts = $this->detectUniqueConflicts($plan['table'], $plan['column'], $sourceHotelId, $targetHotelId);
            $conflictCount = array_sum(array_column($conflicts, 'count'));
            $skippableConflictCount = $this->isSkippableConflictPlan($plan)
                ? $conflictCount
                : 0;
            $blockingConflictCount = max(0, $conflictCount - $skippableConflictCount);
            $expectedUpdateCount = max(0, $sourceCount - $skippableConflictCount);
            $totalSourceRows += $sourceCount;
            $expectedUpdateRows += $expectedUpdateCount;
            $skippableConflictTotal += $skippableConflictCount;
            $blockingConflictTotal += $blockingConflictCount;

            $item = array_merge($plan, [
                'source_count' => $sourceCount,
                'target_count' => $targetCount,
                'conflict_count' => $conflictCount,
                'skippable_conflict_count' => $skippableConflictCount,
                'blocking_conflict_count' => $blockingConflictCount,
                'expected_update_count' => $expectedUpdateCount,
                'conflicts' => $conflicts,
                'conflict_resolution' => $skippableConflictCount > 0 ? 'merge_then_remove_source_duplicate_permission' : null,
                'will_update' => $sourceCount > 0 && $blockingConflictCount === 0,
            ]);
            if ($blockingConflictCount > 0) {
                $blockingConflicts[] = $item;
            }
            $items[] = $item;
        }

        return [
            'source_hotel' => $this->hotelSummary($sourceHotelId),
            'target_hotel' => $this->hotelSummary($targetHotelId),
            'items' => $items,
            'total_source_rows' => $totalSourceRows,
            'expected_update_rows' => $expectedUpdateRows,
            'skippable_conflict_total' => $skippableConflictTotal,
            'blocking_conflict_total' => $blockingConflictTotal,
            'blocking_conflicts' => $blockingConflicts,
            'can_execute' => $totalSourceRows > 0 && empty($blockingConflicts),
            'confirmation_text' => $this->confirmationText($sourceHotelId, $targetHotelId),
            'rules' => [
                'source_hotel_kept' => true,
                'online_daily_data_hotel_id_kept' => true,
                'blocks_unique_conflicts' => true,
                'tenant_id_retargeted' => true,
                'merges_duplicate_user_permissions' => true,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(int $sourceHotelId, int $targetHotelId, string $confirmationText, bool $deactivateSource = false): array
    {
        $preview = $this->preview($sourceHotelId, $targetHotelId);
        if (trim($confirmationText) !== $preview['confirmation_text']) {
            throw new InvalidArgumentException('确认文本不匹配，已取消迁移');
        }

        if (empty($preview['can_execute'])) {
            throw new RuntimeException('门店数据存在冲突或没有可迁移数据，请先查看预览结果');
        }

        $updatedItems = [];
        $skippedConflictTotal = 0;
        $mergedConflictTotal = 0;
        $targetTenantId = (int)($preview['target_hotel']['tenant_id'] ?? 0);
        if ($targetTenantId <= 0) {
            throw new RuntimeException('目标门店缺少有效租户映射，已拒绝迁移');
        }
        Db::transaction(function () use ($sourceHotelId, $targetHotelId, $targetTenantId, $deactivateSource, $preview, &$updatedItems, &$skippedConflictTotal, &$mergedConflictTotal): void {
            foreach ($preview['items'] as $item) {
                $sourceCount = (int)($item['source_count'] ?? 0);
                if ($sourceCount <= 0) {
                    continue;
                }

                $skippedConflicts = 0;
                if ((int)($item['skippable_conflict_count'] ?? 0) > 0) {
                    $skippedConflicts = $this->resolveSkippableConflicts(
                        (string)$item['table'],
                        (string)$item['column'],
                        $sourceHotelId,
                        $targetHotelId,
                        $targetTenantId
                    );
                }
                $skippedConflictTotal += $skippedConflicts;
                $mergedConflictTotal += $skippedConflicts;

                $updated = Db::name((string)$item['table'])
                    ->where((string)$item['column'], $sourceHotelId)
                    ->update($this->buildUpdatePayload((string)$item['table'], (string)$item['column'], $targetHotelId, $targetTenantId));

                $updatedItems[] = array_merge($item, [
                    'updated_count' => (int)$updated,
                    'skipped_conflict_count' => $skippedConflicts,
                ]);
            }

            if ($deactivateSource) {
                Db::name('hotels')->where('id', $sourceHotelId)->update([
                    'status' => 0,
                    'update_time' => date('Y-m-d H:i:s'),
                ]);
            }
        });

        return [
            'source_hotel' => $preview['source_hotel'],
            'target_hotel' => $preview['target_hotel'],
            'items' => $updatedItems,
            'updated_total' => array_sum(array_column($updatedItems, 'updated_count')),
            'skipped_conflict_total' => $skippedConflictTotal,
            'merged_conflict_total' => $mergedConflictTotal,
            'source_deactivated' => $deactivateSource,
        ];
    }

    public function confirmationText(int $sourceHotelId, int $targetHotelId): string
    {
        return 'MERGE ' . $sourceHotelId . ' -> ' . $targetHotelId;
    }

    /**
     * @return array<int, array{table:string,column:string,label:string,scope:string}>
     */
    private function availableMigrationPlans(): array
    {
        return array_values(array_filter($this->migrationPlans(), function (array $plan): bool {
            return $this->tableColumnExists($plan['table'], $plan['column']);
        }));
    }

    private function assertValidMergeTarget(int $sourceHotelId, int $targetHotelId): void
    {
        if ($sourceHotelId <= 0 || $targetHotelId <= 0) {
            throw new InvalidArgumentException('请选择源门店和目标门店');
        }

        if ($sourceHotelId === $targetHotelId) {
            throw new InvalidArgumentException('源门店和目标门店不能相同');
        }

        $this->hotelSummary($sourceHotelId);
        $this->hotelSummary($targetHotelId);
    }

    /**
     * @return array{id:int,name:string,code:string,status:int,tenant_id:int}
     */
    private function hotelSummary(int $hotelId): array
    {
        if (!$this->tableColumnExists('hotels', 'tenant_id')) {
            throw new RuntimeException('hotels.tenant_id 为门店合并必需列，已拒绝迁移');
        }

        try {
            $hotel = Db::name('hotels')
                ->field('id,name,code,status,tenant_id')
                ->where('id', $hotelId)
                ->find();
        } catch (\Throwable $e) {
            throw new RuntimeException('门店租户映射查询失败，已拒绝迁移', 0, $e);
        }
        if (!$hotel) {
            throw new InvalidArgumentException('门店不存在: ' . $hotelId);
        }

        $tenantId = (int)($hotel['tenant_id'] ?? 0);
        if ($tenantId <= 0) {
            throw new RuntimeException('门店缺少有效租户映射: ' . $hotelId);
        }

        return [
            'id' => (int)$hotel['id'],
            'name' => (string)$hotel['name'],
            'code' => (string)($hotel['code'] ?? ''),
            'status' => (int)($hotel['status'] ?? 0),
            'tenant_id' => $tenantId,
        ];
    }

    private function countRows(string $table, string $column, int $hotelId): int
    {
        return (int)Db::name($table)->where($column, $hotelId)->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildUpdatePayload(string $table, string $column, int $targetHotelId, int $targetTenantId): array
    {
        $payload = [$column => $targetHotelId];
        if ($targetTenantId > 0 && $this->tableColumnExists($table, 'tenant_id')) {
            $payload['tenant_id'] = $targetTenantId;
        }
        if ($this->tableColumnExists($table, 'update_time')) {
            $payload['update_time'] = date('Y-m-d H:i:s');
        }

        return $payload;
    }

    /**
     * Only duplicate user-store grants are auto-resolvable: the target grant
     * already exists, so source flags are merged into the target grant before
     * removing the duplicate source grant.
     *
     * @param array<string, string> $plan
     */
    private function isSkippableConflictPlan(array $plan): bool
    {
        return ($plan['table'] ?? '') === 'user_hotel_permissions'
            && ($plan['column'] ?? '') === 'hotel_id'
            && $this->tableColumnExists('user_hotel_permissions', 'user_id');
    }

    private function resolveSkippableConflicts(string $table, string $column, int $sourceHotelId, int $targetHotelId, int $targetTenantId): int
    {
        if (!$this->isSkippableConflictPlan(['table' => $table, 'column' => $column])) {
            return 0;
        }

        $quotedTable = $this->quoteIdentifier($table);
        $quotedColumn = $this->quoteIdentifier($column);
        $mergeAssignments = $this->duplicatePermissionMergeAssignments($targetTenantId);
        if (!empty($mergeAssignments)) {
            $mergeSql = "UPDATE {$quotedTable} t "
                . "INNER JOIN {$quotedTable} s ON s.`user_id` <=> t.`user_id` "
                . 'SET ' . implode(', ', $mergeAssignments) . ' '
                . "WHERE s.{$quotedColumn} = ? AND t.{$quotedColumn} = ?";
            Db::execute($mergeSql, [$sourceHotelId, $targetHotelId]);
        }

        $sql = "DELETE s FROM {$quotedTable} s "
            . "INNER JOIN {$quotedTable} t ON s.`user_id` <=> t.`user_id` "
            . "WHERE s.{$quotedColumn} = ? AND t.{$quotedColumn} = ?";

        return (int)Db::execute($sql, [$sourceHotelId, $targetHotelId]);
    }

    /**
     * @return array<int, string>
     */
    private function duplicatePermissionMergeAssignments(int $targetTenantId): array
    {
        $assignments = [];
        foreach ($this->mergeablePermissionFlagColumns() as $column) {
            if (!$this->tableColumnExists('user_hotel_permissions', $column)) {
                continue;
            }
            $quoted = $this->quoteIdentifier($column);
            $assignments[] = "t.{$quoted} = GREATEST(COALESCE(t.{$quoted}, 0), COALESCE(s.{$quoted}, 0))";
        }

        if ($targetTenantId > 0 && $this->tableColumnExists('user_hotel_permissions', 'tenant_id')) {
            $assignments[] = 't.`tenant_id` = ' . $targetTenantId;
        }
        if ($this->tableColumnExists('user_hotel_permissions', 'status')) {
            $assignments[] = "t.`status` = CASE WHEN t.`status` = 'active' OR s.`status` = 'active' THEN 'active' ELSE t.`status` END";
        }
        if ($this->tableColumnExists('user_hotel_permissions', 'expires_at')) {
            $assignments[] = "t.`expires_at` = CASE WHEN t.`expires_at` IS NULL OR s.`expires_at` IS NULL THEN NULL WHEN s.`expires_at` > t.`expires_at` THEN s.`expires_at` ELSE t.`expires_at` END";
        }
        if ($this->tableColumnExists('user_hotel_permissions', 'update_time')) {
            $assignments[] = "t.`update_time` = '" . date('Y-m-d H:i:s') . "'";
        }

        return $assignments;
    }

    /**
     * @return array<int, string>
     */
    private function mergeablePermissionFlagColumns(): array
    {
        return [
            'can_view_report',
            'can_fill_daily_report',
            'can_fill_monthly_task',
            'can_edit_report',
            'can_delete_report',
            'is_primary',
            'can_view_online_data',
            'can_fetch_online_data',
            'can_delete_online_data',
            'can_view',
            'can_fill',
            'can_edit',
            'can_delete',
            'can_export',
            'can_ai',
            'can_operation',
            'can_investment',
        ];
    }

    /**
     * @return array<int, array{index:string,count:int,columns:array<int, string>}>
     */
    private function detectUniqueConflicts(string $table, string $column, int $sourceHotelId, int $targetHotelId): array
    {
        $conflicts = [];
        $migratingColumns = [$column];
        if ($this->tableColumnExists($table, 'tenant_id')) {
            $migratingColumns[] = 'tenant_id';
        }

        foreach ($this->uniqueIndexesForColumn($table, $column) as $indexName => $columns) {
            $sourceAlias = 's';
            $targetAlias = 't';
            $join = [];
            foreach ($columns as $indexColumn) {
                if (in_array($indexColumn, $migratingColumns, true)) {
                    continue;
                }
                $quoted = $this->quoteIdentifier($indexColumn);
                $join[] = "{$sourceAlias}.{$quoted} <=> {$targetAlias}.{$quoted}";
            }
            $quotedTable = $this->quoteIdentifier($table);
            $quotedColumn = $this->quoteIdentifier($column);
            $joinSql = empty($join) ? '1=1' : implode(' AND ', $join);
            $sql = "SELECT COUNT(*) AS count_value FROM {$quotedTable} {$sourceAlias} "
                . "INNER JOIN {$quotedTable} {$targetAlias} ON {$joinSql} "
                . "WHERE {$sourceAlias}.{$quotedColumn} = ? "
                . "AND {$targetAlias}.{$quotedColumn} = ?";
            $rows = Db::query($sql, [$sourceHotelId, $targetHotelId]);
            $count = (int)($rows[0]['count_value'] ?? 0);
            if ($count > 0) {
                $conflicts[] = [
                    'index' => (string)$indexName,
                    'count' => $count,
                    'columns' => array_values($columns),
                ];
            }
        }

        return $conflicts;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function uniqueIndexesForColumn(string $table, string $column): array
    {
        try {
            $rows = Db::query('SHOW INDEX FROM ' . $this->quoteIdentifier($table));
        } catch (\Throwable $showError) {
            return $this->sqliteUniqueIndexesForColumn($table, $column);
        }
        $indexes = [];
        foreach ($rows as $row) {
            if ((int)($row['Non_unique'] ?? 1) !== 0) {
                continue;
            }
            $indexName = (string)($row['Key_name'] ?? '');
            $indexColumn = (string)($row['Column_name'] ?? '');
            $seq = (int)($row['Seq_in_index'] ?? 0);
            if ($indexName === '' || $indexColumn === '' || $seq <= 0) {
                continue;
            }
            $indexes[$indexName][$seq] = $indexColumn;
        }

        $result = [];
        foreach ($indexes as $indexName => $columnsBySeq) {
            ksort($columnsBySeq);
            $columns = array_values($columnsBySeq);
            if (in_array($column, $columns, true)) {
                $result[$indexName] = $columns;
            }
        }

        return $result;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function sqliteUniqueIndexesForColumn(string $table, string $column): array
    {
        try {
            $indexRows = Db::query('PRAGMA index_list(' . $this->quoteIdentifier($table) . ')');
        } catch (\Throwable $e) {
            throw new RuntimeException("无法探测 {$table} 的唯一索引", 0, $e);
        }

        $result = [];
        foreach ($indexRows as $indexRow) {
            if ((int)($indexRow['unique'] ?? 0) !== 1) {
                continue;
            }
            $indexName = (string)($indexRow['name'] ?? '');
            if ($indexName === '') {
                continue;
            }
            $columns = array_values(array_filter(array_map(
                static fn(array $row): string => (string)($row['name'] ?? ''),
                Db::query('PRAGMA index_info(' . $this->quoteIdentifier($indexName) . ')')
            )));
            if (in_array($column, $columns, true)) {
                $result[$indexName] = $columns;
            }
        }

        return $result;
    }

    private function tableColumnExists(string $table, string $column): bool
    {
        $table = str_replace('`', '', $table);
        $column = str_replace(['`', "'"], '', $column);

        try {
            $exists = $this->probeTableColumn($table, $column);
        } catch (\Throwable $e) {
            if ($table === 'hotels' && $column === 'tenant_id') {
                throw new RuntimeException('hotels.tenant_id 元数据探测失败，已拒绝迁移', 0, $e);
            }
            return false;
        }

        if (!$exists && $table === 'hotels' && $column === 'tenant_id') {
            throw new RuntimeException('hotels.tenant_id 为门店合并必需列，已拒绝迁移');
        }

        return $exists;
    }

    protected function probeTableColumn(string $table, string $column): bool
    {
        try {
            return !empty(Db::query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'"));
        } catch (\Throwable $showError) {
            try {
                $rows = Db::query("PRAGMA table_info(`{$table}`)");
            } catch (\Throwable $pragmaError) {
                throw new RuntimeException("无法探测 {$table}.{$column}", 0, $pragmaError);
            }

            foreach ($rows as $row) {
                if (($row['name'] ?? '') === $column) {
                    return true;
                }
            }

            return false;
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new InvalidArgumentException('Invalid identifier: ' . $identifier);
        }

        return '`' . $identifier . '`';
    }
}
