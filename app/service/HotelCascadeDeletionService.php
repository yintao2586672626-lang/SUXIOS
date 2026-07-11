<?php
declare(strict_types=1);

namespace app\service;

use app\model\SystemConfig;
use RuntimeException;
use think\facade\Db;

final class HotelCascadeDeletionService
{
    /** @var array<int, array{0: string, 1: string}> */
    private const HOTEL_RELATIONS = [
        ['agent_configs', 'hotel_id'],
        ['agent_conversations', 'hotel_id'],
        ['agent_logs', 'hotel_id'],
        ['agent_tasks', 'hotel_id'],
        ['agent_work_orders', 'hotel_id'],
        ['ai_daily_reports', 'hotel_id'],
        ['ai_model_call_logs', 'hotel_id'],
        ['competitor_analysis', 'hotel_id'],
        ['competitor_price_log', 'hotel_id'],
        ['complaint_feedbacks', 'hotel_id'],
        ['complaint_rooms', 'hotel_id'],
        ['daily_reports', 'hotel_id'],
        ['demand_forecasts', 'hotel_id'],
        ['devices', 'hotel_id'],
        ['energy_benchmarks', 'hotel_id'],
        ['energy_consumption', 'hotel_id'],
        ['energy_saving_suggestions', 'hotel_id'],
        ['field_mappings', 'hotel_id'],
        ['hotel_field_templates', 'hotel_id'],
        ['knowledge_base', 'hotel_id'],
        ['knowledge_categories', 'hotel_id'],
        ['knowledge_units', 'hotel_id'],
        ['maintenance_plans', 'hotel_id'],
        ['monthly_tasks', 'hotel_id'],
        ['online_daily_data', 'system_hotel_id'],
        ['opening_projects', 'hotel_id'],
        ['operation_action_tracks', 'hotel_id'],
        ['operation_alerts', 'hotel_id'],
        ['operation_execution_intents', 'hotel_id'],
        ['operation_execution_tasks', 'hotel_id'],
        ['operation_logs', 'hotel_id'],
        ['ota_credentials', 'system_hotel_id'],
        ['ota_ctrip_capture_gaps', 'system_hotel_id'],
        ['ota_ctrip_capture_runs', 'system_hotel_id'],
        ['ota_ctrip_entity_snapshots', 'system_hotel_id'],
        ['ota_ctrip_im_sessions', 'system_hotel_id'],
        ['ota_ctrip_metric_facts', 'system_hotel_id'],
        ['ota_ctrip_orders', 'system_hotel_id'],
        ['ota_ctrip_reviews', 'system_hotel_id'],
        ['ota_ctrip_review_order_matches', 'system_hotel_id'],
        ['ota_meituan_orders', 'system_hotel_id'],
        ['ota_meituan_reviews', 'system_hotel_id'],
        ['ota_meituan_review_order_matches', 'system_hotel_id'],
        ['ota_profile_bindings', 'system_hotel_id'],
        ['platform_data_raw_records', 'system_hotel_id'],
        ['platform_data_sources', 'system_hotel_id'],
        ['platform_data_sync_logs', 'system_hotel_id'],
        ['platform_data_sync_tasks', 'system_hotel_id'],
        ['price_suggestions', 'hotel_id'],
        ['room_types', 'hotel_id'],
        ['system_notifications', 'hotel_id'],
        ['transfer_records', 'hotel_id'],
    ];

    private const OTA_CONFIG_KEYS = ['ctrip_config_list', 'meituan_config_list'];

    /** @return array<string, mixed> */
    public function preview(int $hotelId): array
    {
        $hotel = Db::name('hotels')->field('id,name')->where('id', $hotelId)->find();
        if (!is_array($hotel)) {
            throw new RuntimeException('酒店不存在');
        }

        $tables = [];
        foreach (self::HOTEL_RELATIONS as [$table, $column]) {
            if (!$this->tableColumnExists($table, $column)) {
                continue;
            }
            $count = (int)Db::name($table)->where($column, $hotelId)->count();
            if ($count > 0) {
                $tables[$table] = $count;
            }
        }
        foreach ($this->dependentChildCounts($hotelId) as $table => $count) {
            if ($count > 0) {
                $tables[$table] = $count;
            }
        }
        if ($this->tableColumnExists('user_hotel_permissions', 'hotel_id')) {
            $count = (int)Db::name('user_hotel_permissions')->where('hotel_id', $hotelId)->count();
            if ($count > 0) {
                $tables['user_hotel_permissions'] = $count;
            }
        }

        $usersDetached = $this->tableColumnExists('users', 'hotel_id')
            ? (int)Db::name('users')->where('hotel_id', $hotelId)->count()
            : 0;
        $configEntries = $this->countOtaConfigEntries($hotelId);

        return [
            'hotel' => [
                'id' => (int)$hotel['id'],
                'name' => (string)$hotel['name'],
            ],
            'tables' => $tables,
            'users_preserved' => $usersDetached,
            'config_entries' => $configEntries,
            'total_rows' => array_sum($tables) + $usersDetached + $configEntries,
        ];
    }

    /** @return array<string, mixed> */
    public function delete(int $hotelId, int $archivedBy = 0): array
    {
        $result = Db::transaction(function () use ($hotelId, $archivedBy): array {
            $hotel = Db::name('hotels')->where('id', $hotelId)->lock(true)->find();
            if (!is_array($hotel)) {
                throw new RuntimeException('酒店不存在');
            }

            if (!$this->tableColumnExists('hotels', 'archived_at')) {
                throw new RuntimeException('酒店归档字段缺失，请先执行 20260712_add_hotel_archiving.sql');
            }
            if (trim((string)($hotel['archived_at'] ?? '')) !== '') {
                throw new RuntimeException('酒店已归档，无需重复操作');
            }

            $payload = [
                'status' => 0,
                'archived_at' => date('Y-m-d H:i:s'),
            ];
            if ($this->tableColumnExists('hotels', 'archived_by')) {
                $payload['archived_by'] = max(0, $archivedBy);
            }
            $updated = (int)Db::name('hotels')->where('id', $hotelId)->update($payload);
            if ($updated !== 1 && trim((string)($hotel['archived_at'] ?? '')) === '') {
                throw new RuntimeException('酒店归档失败，事务已回滚');
            }

            $preview = $this->preview($hotelId);

            return [
                'hotel_id' => $hotelId,
                'hotel_name' => (string)$hotel['name'],
                'archived' => true,
                'archived_at' => (string)$payload['archived_at'],
                'preserved_tables' => (array)($preview['tables'] ?? []),
                'preserved_rows' => (int)($preview['total_rows'] ?? 0),
                'users_preserved' => (int)($preview['users_preserved'] ?? 0),
                'config_entries_preserved' => (int)($preview['config_entries'] ?? 0),
            ];
        });

        SystemConfig::clearProtectedOtaCaches();
        return $result;
    }

    /** @return array<string, mixed> */
    public function restore(int $hotelId): array
    {
        $result = Db::transaction(function () use ($hotelId): array {
            $hotel = Db::name('hotels')->where('id', $hotelId)->lock(true)->find();
            if (!is_array($hotel)) {
                throw new RuntimeException('酒店不存在');
            }
            if (!$this->tableColumnExists('hotels', 'archived_at')) {
                throw new RuntimeException('酒店归档字段缺失，请先执行 20260712_add_hotel_archiving.sql');
            }
            if (trim((string)($hotel['archived_at'] ?? '')) === '') {
                throw new RuntimeException('酒店未归档，无需恢复');
            }

            $payload = ['status' => 0, 'archived_at' => null];
            if ($this->tableColumnExists('hotels', 'archived_by')) {
                $payload['archived_by'] = null;
            }
            Db::name('hotels')->where('id', $hotelId)->update($payload);

            return [
                'hotel_id' => $hotelId,
                'hotel_name' => (string)$hotel['name'],
                'restored' => true,
                'status' => 0,
            ];
        });

        SystemConfig::clearProtectedOtaCaches();
        return $result;
    }

    /** @return array<string, int> */
    private function dependentChildCounts(int $hotelId): array
    {
        $counts = [];
        $counts['opening_tasks'] = $this->countChildrenByParent(
            'opening_tasks',
            'project_id',
            'opening_projects',
            'id',
            'hotel_id',
            $hotelId
        );
        $counts['operation_execution_evidence'] = $this->countChildrenByParent(
            'operation_execution_evidence',
            'task_id',
            'operation_execution_tasks',
            'id',
            'hotel_id',
            $hotelId
        );
        $counts['knowledge_chunks'] = $this->countChildrenByParent(
            'knowledge_chunks',
            'unit_id',
            'knowledge_units',
            'id',
            'hotel_id',
            $hotelId
        );
        $counts['hotel_field_template_items'] = $this->countChildrenByParent(
            'hotel_field_template_items',
            'template_id',
            'hotel_field_templates',
            'id',
            'hotel_id',
            $hotelId
        );

        return array_filter($counts, static fn(int $count): bool => $count > 0);
    }

    private function countChildrenByParent(
        string $childTable,
        string $childColumn,
        string $parentTable,
        string $parentIdColumn,
        string $parentHotelColumn,
        int $hotelId
    ): int {
        foreach ([$childTable, $childColumn, $parentTable, $parentIdColumn, $parentHotelColumn] as $identifier) {
            if (!preg_match('/^[A-Za-z0-9_]+$/D', $identifier)) {
                return 0;
            }
        }
        if (!$this->tableColumnExists($childTable, $childColumn)
            || !$this->tableColumnExists($parentTable, $parentIdColumn)
            || !$this->tableColumnExists($parentTable, $parentHotelColumn)
        ) {
            return 0;
        }

        $sql = "SELECT COUNT(*) AS aggregate FROM `{$childTable}` child_row "
            . "INNER JOIN `{$parentTable}` parent_row "
            . "ON parent_row.`{$parentIdColumn}` = child_row.`{$childColumn}` "
            . "WHERE parent_row.`{$parentHotelColumn}` = ?";
        $rows = Db::query($sql, [$hotelId]);
        return (int)($rows[0]['aggregate'] ?? 0);
    }

    private function countOtaConfigEntries(int $hotelId): int
    {
        $total = 0;
        foreach (self::OTA_CONFIG_KEYS as $key) {
            foreach ($this->readConfigList($key) as $item) {
                if (is_array($item) && $this->configBelongsToHotel($item, $hotelId)) {
                    $total++;
                }
            }
        }
        return $total;
    }

    /** @return array<mixed> */
    private function readConfigList(string $key): array
    {
        $row = $this->systemConfigRow($key, false);
        return is_array($row)
            ? $this->decodeConfigList((string)($row['config_value'] ?? ''), $key)
            : [];
    }

    /** @return array<string, mixed>|null */
    private function systemConfigRow(string $key, bool $lock): ?array
    {
        if (!$this->tableColumnExists('system_configs', 'config_key')) {
            return null;
        }
        $query = Db::name('system_configs')->where('config_key', $key);
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        return is_array($row) ? $row : null;
    }

    /** @return array<mixed> */
    private function decodeConfigList(string $raw, string $key): array
    {
        if (trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException("{$key} 配置格式无效");
        }
        return $decoded;
    }

    /** @param array<string, mixed> $config */
    private function configBelongsToHotel(array $config, int $hotelId): bool
    {
        $systemHotelId = (int)($config['system_hotel_id'] ?? 0);
        $legacyHotelId = (int)($config['hotel_id'] ?? 0);
        return $systemHotelId === $hotelId || ($systemHotelId <= 0 && $legacyHotelId === $hotelId);
    }

    private function tableColumnExists(string $table, string $column): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $table) || !preg_match('/^[A-Za-z0-9_]+$/D', $column)) {
            return false;
        }
        try {
            Db::query("SELECT `{$column}` FROM `{$table}` LIMIT 1");
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
