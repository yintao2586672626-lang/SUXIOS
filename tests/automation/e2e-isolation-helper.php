<?php
declare(strict_types=1);

use app\model\Role;
use app\model\SystemConfig;
use app\service\HotelCascadeDeletionService;
use app\service\SchemaVersionService;
use think\App;
use think\facade\Config;
use think\facade\Db;
use Tests\Automation\E2eDatabaseSafetyGuard;

$root = dirname(__DIR__, 2);
require $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
require __DIR__ . DIRECTORY_SEPARATOR . 'E2eDatabaseSafetyGuard.php';

(new App($root))->initialize();

/**
 * @return array{
 *     database_name: string,
 *     mode: string,
 *     dedicated_database: bool,
 *     database_host_scope: string
 * }
 */
function e2eDatabaseSafetyGuard(): array
{
    $rows = Db::query('SELECT DATABASE() AS database_name');
    $databaseName = (string)($rows[0]['database_name'] ?? '');
    $defaultConnection = (string)Config::get('database.default', 'mysql');
    $connectionConfig = Config::get('database.connections.' . $defaultConnection, []);
    $databaseHost = is_array($connectionConfig) ? (string)($connectionConfig['hostname'] ?? '') : '';

    return E2eDatabaseSafetyGuard::inspect(
        $databaseName,
        $databaseHost,
        (string)(getenv('SUXI_E2E_ALLOW_SHARED_DB') ?: ''),
        (string)(getenv('SUXI_E2E_ALLOW_REMOTE_TEST_DB') ?: '')
    );
}

/** @return array<string, bool> */
function e2eTableColumns(string $table): array
{
    if (!preg_match('/^[A-Za-z0-9_]+$/D', $table)) {
        return [];
    }
    try {
        $rows = Db::query("SHOW COLUMNS FROM `{$table}`");
    } catch (Throwable) {
        return [];
    }

    $columns = [];
    foreach ($rows as $row) {
        $name = (string)($row['Field'] ?? $row['field'] ?? '');
        if ($name !== '') {
            $columns[$name] = true;
        }
    }
    return $columns;
}

/** @param array<string, mixed> $payload @return array<string, mixed> */
function e2eFilterPayload(string $table, array $payload): array
{
    return array_intersect_key($payload, e2eTableColumns($table));
}

function e2eHasColumn(string $table, string $column): bool
{
    return isset(e2eTableColumns($table)[$column]);
}

/** @return array{schema_ready: true, schema_contract: string} */
function e2eAssertSchemaReady(): array
{
    $requiredColumns = [
        'tenants' => ['id', 'name', 'status'],
        'roles' => ['id', 'name'],
        'users' => ['id', 'username', 'role_id', 'hotel_id', 'default_hotel_id', 'tenant_id'],
        'hotels' => ['id', 'name', 'tenant_id'],
        'login_rate_limit_counters' => ['scope_type', 'subject_hash', 'bucket_start', 'attempt_count', 'expires_at'],
        'user_hotel_permissions' => ['user_id', 'hotel_id'],
        'online_daily_data' => [
            'id', 'tenant_id', 'system_hotel_id', 'hotel_id', 'data_date',
            'source', 'data_type', 'dimension',
            'data_source_id', 'ingestion_method', 'snapshot_time',
            'readback_verified', 'readback_verified_at',
        ],
        'platform_data_sources' => [
            'id', 'tenant_id', 'system_hotel_id', 'name', 'platform',
            'data_type', 'ingestion_method', 'status', 'enabled',
        ],
        'ai_daily_reports' => [
            'id', 'tenant_id', 'hotel_id', 'report_date', 'status',
            'generation_mode', 'model_status', 'recommended_actions_json',
            'source_refs_json', 'snapshot_json', 'input_fingerprint', 'prompt_version',
        ],
        'ai_report_human_reviews' => [
            'id', 'tenant_id', 'hotel_id', 'report_id', 'subject_type',
            'decision', 'result_version', 'created_by', 'created_at',
        ],
        'competitor_price_log' => ['report_fingerprint'],
    ];
    $missing = [];
    foreach ($requiredColumns as $table => $columns) {
        $available = e2eTableColumns($table);
        foreach ($columns as $column) {
            if (!isset($available[$column])) {
                $missing[] = $table . '.' . $column;
            }
        }
    }
    if ($missing !== []) {
        throw new RuntimeException(
            'Isolated E2E database schema is missing or stale: ' . implode(', ', $missing)
            . '; initialize or migrate this dedicated test database with database/init_full.sql before running E2E'
        );
    }

    $defaultConnection = (string)Config::get('database.default', 'mysql');
    $connectionConfig = (array)Config::get('database.connections.' . $defaultConnection, []);
    $schemaStatus = SchemaVersionService::fromDatabaseConfig(
        $connectionConfig,
        dirname(__DIR__, 2)
    )->status();
    if (($schemaStatus['ready'] ?? false) !== true) {
        throw new RuntimeException(sprintf(
            'Isolated E2E database migration catalog is stale: current=%s, required=%s, pending=%d; run php think db:migrate against this dedicated test database',
            (string)($schemaStatus['current_version'] ?? 'none'),
            (string)($schemaStatus['required_version'] ?? 'unknown'),
            count((array)($schemaStatus['pending'] ?? []))
        ));
    }

    $idempotencyIndexes = Db::query(
        "SHOW INDEX FROM `competitor_price_log` WHERE `Key_name` = 'uniq_competitor_report_fingerprint'"
    );
    if (count($idempotencyIndexes) !== 1
        || (int)($idempotencyIndexes[0]['Non_unique'] ?? $idempotencyIndexes[0]['non_unique'] ?? 1) !== 0
        || (string)($idempotencyIndexes[0]['Column_name'] ?? $idempotencyIndexes[0]['column_name'] ?? '') !== 'report_fingerprint') {
        throw new RuntimeException(
            'Isolated E2E database schema is missing the competitor report idempotency index; run php think db:migrate against this dedicated test database'
        );
    }

    return [
        'schema_ready' => true,
        'schema_contract' => 'e2e-runtime-readiness-v2',
    ];
}

function e2ePrefixQuery(string $table, string $column, string $prefix): \think\db\Query
{
    if (!preg_match('/^[A-Za-z0-9_]+$/D', $column)) {
        throw new RuntimeException('Invalid E2E cleanup column');
    }
    return Db::name($table)->whereRaw(
        "LEFT(`{$column}`, " . strlen($prefix) . ') = ?',
        [$prefix]
    );
}

function e2ePrefix(): string
{
    $prefix = trim((string)getenv('SUXI_E2E_PREFIX'));
    if (!preg_match('/^codex_e2e_[a-z0-9_]{8,48}$/D', $prefix)) {
        throw new RuntimeException('Invalid isolated E2E prefix');
    }
    return $prefix;
}

function e2eSetProtectedModules(int $tenantId, bool $enabled): void
{
    if ($tenantId <= 0) {
        return;
    }

    $raw = SystemConfig::getValue(SystemConfig::KEY_PROTECTED_CAPABILITY_POLICY, '');
    $policy = is_string($raw) ? json_decode($raw, true) : [];
    if (!is_array($policy)) {
        $policy = [];
    }
    $tenantModules = is_array($policy['tenant_modules'] ?? null) ? $policy['tenant_modules'] : [];
    $tenantKey = (string)$tenantId;
    if ($enabled) {
        $tenantModules[$tenantKey] = ['ai_decision', 'operation_decision', 'investment'];
    } else {
        unset($tenantModules[$tenantKey]);
    }
    $policy['tenant_modules'] = $tenantModules;

    if (!SystemConfig::setValue(
        SystemConfig::KEY_PROTECTED_CAPABILITY_POLICY,
        json_encode($policy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'High-value capability backend protection policy JSON'
    )) {
        throw new RuntimeException('Failed to update isolated E2E module entitlement');
    }
}

/** @return array{username: string, role_name: string, tenant_name: string, hotel_name: string, hotel_code: string} */
function e2eNames(string $prefix): array
{
    return [
        'username' => $prefix . '_user',
        'role_name' => $prefix . '_role',
        'tenant_name' => $prefix . '_tenant',
        'hotel_name' => $prefix . '_hotel',
        'hotel_code' => $prefix . '_hotel',
    ];
}

/** @return array{counts: array<string, int>, total: int} */
function e2eCount(string $prefix): array
{
    $names = e2eNames($prefix);
    $targets = [
        'tenants' => ['tenants', 'name'],
        'roles' => ['roles', 'name'],
        'users' => ['users', 'username'],
        'hotels' => ['hotels', 'name'],
        'expansion_records' => ['expansion_records', 'project_name'],
        'transfer_records' => ['transfer_records', 'hotel_name'],
        'strategy_simulation_records' => ['strategy_simulation_records', 'project_name'],
        'quant_simulation_records' => ['quant_simulation_records', 'project_name'],
        'feasibility_reports' => ['feasibility_reports', 'project_name'],
    ];

    $counts = [];
    foreach ($targets as $label => [$table, $column]) {
        $counts[$label] = e2eHasColumn($table, $column)
            ? (int)e2ePrefixQuery($table, $column, $prefix)->count()
            : 0;
    }

    $hotelId = (int)getenv('SUXI_E2E_HOTEL_ID');
    if ($hotelId <= 0) {
        $hotelId = (int)(Db::name('hotels')->where('name', $names['hotel_name'])->value('id') ?? 0);
    }
    $userId = (int)getenv('SUXI_E2E_USER_ID');
    if ($userId <= 0) {
        $userId = (int)(Db::name('users')->where('username', $names['username'])->value('id') ?? 0);
    }
    $tenantId = (int)getenv('SUXI_E2E_TENANT_ID');
    if ($tenantId <= 0 && $hotelId > 0) {
        $tenantId = (int)(Db::name('hotels')->where('id', $hotelId)->value('tenant_id') ?? 0);
    }
    if ($tenantId <= 0) {
        $tenantId = (int)(Db::name('tenants')->where('name', $names['tenant_name'])->value('id') ?? 0);
    }
    foreach ([
        'online_daily_data' => ['online_daily_data', 'system_hotel_id'],
        'platform_data_sources' => ['platform_data_sources', 'system_hotel_id'],
        'temporal_forecast_snapshots' => ['temporal_forecast_snapshots', 'system_hotel_id'],
        'analysis_reference_set_versions' => ['analysis_reference_set_versions', 'system_hotel_id'],
        'ai_daily_reports' => 'ai_daily_reports',
        'ai_report_generation_tasks' => 'ai_report_generation_tasks',
        'ai_report_input_cache' => 'ai_report_input_cache',
        'ai_report_human_reviews' => 'ai_report_human_reviews',
        'operation_action_tracks' => 'operation_action_tracks',
        'operation_execution_intents' => 'operation_execution_intents',
        'operation_execution_tasks' => 'operation_execution_tasks',
    ] as $label => $relation) {
        [$table, $column] = is_array($relation) ? $relation : [$relation, 'hotel_id'];
        $counts[$label] = $hotelId > 0 && e2eHasColumn($table, $column)
            ? (int)Db::name($table)->where($column, $hotelId)->count()
            : 0;
    }
    $counts['operation_execution_evidence'] = e2eHasColumn('operation_execution_evidence', 'remark')
        ? (int)e2ePrefixQuery('operation_execution_evidence', 'remark', $prefix)->count()
        : 0;
    $counts['operation_logs'] = $userId > 0 && e2eHasColumn('operation_logs', 'user_id')
        ? (int)Db::name('operation_logs')->where('user_id', $userId)->count()
        : 0;
    $counts['login_logs_by_user'] = $userId > 0 && e2eHasColumn('login_logs', 'user_id')
        ? (int)Db::name('login_logs')->where('user_id', $userId)->count()
        : 0;
    $counts['login_logs_by_name'] = e2eHasColumn('login_logs', 'username')
        ? (int)Db::name('login_logs')->where('username', $names['username'])->count()
        : 0;
    $counts['user_hotel_permissions'] = $userId > 0 && e2eHasColumn('user_hotel_permissions', 'user_id')
        ? (int)Db::name('user_hotel_permissions')->where('user_id', $userId)->count()
        : 0;

    $rawPolicy = SystemConfig::getValue(SystemConfig::KEY_PROTECTED_CAPABILITY_POLICY, '');
    $policy = is_string($rawPolicy) ? json_decode($rawPolicy, true) : [];
    $tenantModules = is_array($policy['tenant_modules'] ?? null) ? $policy['tenant_modules'] : [];
    $counts['module_entitlement'] = $tenantId > 0 && array_key_exists((string)$tenantId, $tenantModules) ? 1 : 0;

    return ['counts' => $counts, 'total' => array_sum($counts)];
}

/** @return array<string, mixed> */
function e2eSeed(string $prefix): array
{
    $password = (string)getenv('SUXI_E2E_PASSWORD');
    if (strlen($password) < 24) {
        throw new RuntimeException('Temporary E2E password is missing or too short');
    }
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $password = '';
    if (!is_string($passwordHash) || $passwordHash === '') {
        throw new RuntimeException('Failed to hash temporary E2E password');
    }

    $names = e2eNames($prefix);
    if (e2eCount($prefix)['total'] !== 0) {
        throw new RuntimeException('Isolated E2E prefix already exists');
    }

    $seed = Db::transaction(function () use ($names, $passwordHash): array {
        $roleId = (int)Db::name('roles')->insertGetId(e2eFilterPayload('roles', [
            'name' => $names['role_name'],
            'display_name' => 'Isolated E2E manager',
            'description' => 'temporary isolated E2E role',
            'permissions' => json_encode(['all'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'level' => Role::HOTEL_MANAGER,
            'status' => Role::STATUS_ENABLED,
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
        ]));
        if ($roleId <= 0) {
            throw new RuntimeException('Failed to create isolated E2E role');
        }

        $tenantId = (int)Db::name('tenants')->insertGetId(e2eFilterPayload('tenants', [
            'name' => $names['tenant_name'],
            'status' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]));
        if ($tenantId <= 0) {
            throw new RuntimeException('Failed to create isolated E2E tenant');
        }

        $userId = (int)Db::name('users')->insertGetId(e2eFilterPayload('users', [
            'tenant_id' => $tenantId,
            'username' => $names['username'],
            'password' => $passwordHash,
            'realname' => $names['username'],
            'role_id' => $roleId,
            'status' => 1,
            'hotel_id' => null,
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
        ]));
        if ($userId <= 0) {
            throw new RuntimeException('Failed to create isolated E2E user');
        }
        $storedRoleId = (int)Db::name('users')->where('id', $userId)->value('role_id');
        if ($storedRoleId !== $roleId || $storedRoleId === Role::ADMIN) {
            throw new RuntimeException('Isolated E2E user was not stored with the selected non-admin role');
        }

        $hotelId = (int)Db::name('hotels')->insertGetId(e2eFilterPayload('hotels', [
            'tenant_id' => $tenantId,
            'name' => $names['hotel_name'],
            'code' => $names['hotel_code'],
            'address' => 'isolated E2E only',
            'contact_person' => 'Codex E2E',
            'contact_phone' => '',
            'status' => 1,
            'description' => 'temporary isolated E2E hotel',
            'ota_channel_strategy' => 'none',
            'owner_user_id' => $userId,
            'created_by' => $userId,
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
        ]));
        if ($hotelId <= 0) {
            throw new RuntimeException('Failed to create isolated E2E hotel');
        }

        Db::name('users')->where('id', $userId)->update(e2eFilterPayload('users', [
            'hotel_id' => $hotelId,
            'default_hotel_id' => $hotelId,
            'update_time' => date('Y-m-d H:i:s'),
        ]));

        $permission = e2eFilterPayload('user_hotel_permissions', [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'hotel_id' => $hotelId,
            'scope_type' => 'owner',
            'can_view' => 1,
            'can_report' => 1,
            'can_fill' => 1,
            'can_edit' => 1,
            'can_fetch_ota' => 1,
            'can_delete_ota' => 1,
            'can_export' => 1,
            'can_ai' => 1,
            'can_operation' => 1,
            'can_investment' => 1,
            'expires_at' => null,
            'status' => 'active',
            'created_by' => $userId,
            'can_view_report' => 1,
            'can_fill_daily_report' => 1,
            'can_fill_monthly_task' => 1,
            'can_edit_report' => 1,
            'can_delete_report' => 1,
            'is_primary' => 1,
            'can_view_online_data' => 1,
            'can_fetch_online_data' => 1,
            'can_delete_online_data' => 1,
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
        ]);
        if ($permission !== []) {
            Db::name('user_hotel_permissions')->insert($permission);
        }

        return [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'username' => $names['username'],
            'role_id' => $roleId,
            'hotel_id' => $hotelId,
            'hotel_name' => $names['hotel_name'],
        ];
    });
    e2eSetProtectedModules((int)$seed['tenant_id'], true);
    return $seed;
}

/** @return array<string, mixed> */
function e2eSeedAiReportInputs(string $prefix): array
{
    $names = e2eNames($prefix);
    $hotelId = (int)getenv('SUXI_E2E_HOTEL_ID');
    $hotel = $hotelId > 0
        ? Db::name('hotels')->where('id', $hotelId)->field('id,name,tenant_id')->find()
        : Db::name('hotels')->where('name', $names['hotel_name'])->field('id,name,tenant_id')->find();
    if (!is_array($hotel)
        || (int)($hotel['id'] ?? 0) <= 0
        || (string)($hotel['name'] ?? '') !== $names['hotel_name']) {
        throw new RuntimeException('Isolated E2E hotel is missing for AI report traffic fixture');
    }

    $hotelId = (int)$hotel['id'];
    $trafficOtaHotelId = $prefix . '_traffic_ota';
    $businessOtaHotelId = $prefix . '_ota';
    $now = date('Y-m-d H:i:s');
    $fixtures = [
        ['date' => '2026-05-16', 'data_type' => 'traffic', 'hotel_id' => $trafficOtaHotelId, 'list_exposure' => 40000, 'detail_exposure' => 10000, 'flow_rate' => 25.0, 'order_filling_num' => 300, 'order_submit_num' => 150],
        ['date' => '2026-05-17', 'data_type' => 'traffic', 'hotel_id' => $trafficOtaHotelId, 'list_exposure' => 10000, 'detail_exposure' => 2500, 'flow_rate' => 25.0, 'order_filling_num' => 250, 'order_submit_num' => 120],
        ['date' => '2026-05-17', 'data_type' => 'business', 'hotel_id' => $businessOtaHotelId, 'amount' => 120000, 'quantity' => 300, 'book_order_num' => 120],
    ];

    $fixtureResult = Db::transaction(function () use ($fixtures, $hotel, $hotelId, $names, $prefix, $now): array {
        $dataSourceIds = [];
        foreach (['business', 'traffic'] as $dataType) {
            $sourceId = (int)Db::name('platform_data_sources')->insertGetId(e2eFilterPayload('platform_data_sources', [
                'tenant_id' => (int)($hotel['tenant_id'] ?? 0) ?: $hotelId,
                'system_hotel_id' => $hotelId,
                'name' => $prefix . '_ctrip_' . $dataType . '_source',
                'platform' => 'ctrip',
                'data_type' => $dataType,
                'ingestion_method' => 'isolated_e2e_fixture',
                'status' => 'success',
                'enabled' => 1,
                'config_json' => json_encode([
                    'synthetic' => true,
                    'fixture_scope' => 'isolated_e2e_ai_report_inputs',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'last_sync_time' => $now,
                'last_sync_status' => 'success',
                'create_time' => $now,
                'update_time' => $now,
            ]));
            $storedSource = Db::name('platform_data_sources')->where('id', $sourceId)->find();
            if ($sourceId <= 0
                || !is_array($storedSource)
                || (int)($storedSource['system_hotel_id'] ?? 0) !== $hotelId
                || (string)($storedSource['platform'] ?? '') !== 'ctrip'
                || (string)($storedSource['data_type'] ?? '') !== $dataType
                || (string)($storedSource['ingestion_method'] ?? '') !== 'isolated_e2e_fixture'
                || (string)($storedSource['status'] ?? '') !== 'success') {
                throw new RuntimeException('Isolated AI report data-source fixture readback failed');
            }
            $dataSourceIds[$dataType] = $sourceId;
        }

        $ids = [];
        foreach ($fixtures as $fixture) {
            $date = (string)$fixture['date'];
            $dataType = (string)$fixture['data_type'];
            $otaHotelId = (string)$fixture['hotel_id'];
            $traceId = $prefix . '_' . $dataType . '_' . str_replace('-', '', $date);
            $payload = e2eFilterPayload('online_daily_data', [
                'tenant_id' => (int)($hotel['tenant_id'] ?? 0) ?: $hotelId,
                'system_hotel_id' => $hotelId,
                'hotel_id' => $otaHotelId,
                'hotel_name' => $names['hotel_name'],
                'data_date' => $date,
                'source' => 'ctrip',
                'platform' => 'ctrip',
                'data_type' => $dataType,
                'dimension' => '',
                'compare_type' => 'self',
                'amount' => $fixture['amount'] ?? null,
                'quantity' => $fixture['quantity'] ?? null,
                'book_order_num' => $fixture['book_order_num'] ?? null,
                'list_exposure' => $fixture['list_exposure'] ?? null,
                'detail_exposure' => $fixture['detail_exposure'] ?? null,
                'flow_rate' => $fixture['flow_rate'] ?? null,
                'order_filling_num' => $fixture['order_filling_num'] ?? null,
                'order_submit_num' => $fixture['order_submit_num'] ?? null,
                'data_value' => $fixture['list_exposure'] ?? $fixture['amount'] ?? 0,
                'raw_data' => json_encode([
                    'synthetic' => true,
                    'fixture_scope' => 'isolated_e2e_ai_report_inputs',
                    'ingestion_method' => 'isolated_e2e_fixture',
                    'collected_at' => $date . ' 23:59:59',
                    'source_trace_id' => $traceId,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'validation_status' => 'normal',
                'validation_flags' => '[]',
                'data_source_id' => $dataSourceIds[$dataType],
                'ingestion_method' => 'isolated_e2e_fixture',
                'source_trace_id' => $traceId,
                'data_period' => 'historical_daily',
                'snapshot_time' => $date . ' 23:59:59',
                'snapshot_bucket' => str_replace('-', '', $date),
                'is_final' => 1,
                'readback_verified' => 0,
                'readback_verified_at' => null,
                'create_time' => $now,
                'update_time' => $now,
            ]);
            $rowId = (int)Db::name('online_daily_data')->insertGetId($payload);
            $stored = Db::name('online_daily_data')->where('id', $rowId)->find();
            if (!is_array($stored)
                || (int)($stored['system_hotel_id'] ?? 0) !== $hotelId
                || (string)($stored['hotel_id'] ?? '') !== $otaHotelId
                || (string)($stored['data_date'] ?? '') !== $date
                || (string)($stored['source'] ?? '') !== 'ctrip'
                || (string)($stored['data_type'] ?? '') !== $dataType
                || (int)($stored['data_source_id'] ?? 0) !== (int)$dataSourceIds[$dataType]
                || ($dataType === 'traffic' && (
                    (int)($stored['list_exposure'] ?? -1) !== (int)$fixture['list_exposure']
                    || (int)($stored['detail_exposure'] ?? -1) !== (int)$fixture['detail_exposure']
                ))
                || ($dataType === 'business' && (
                    (float)($stored['amount'] ?? -1) !== (float)$fixture['amount']
                    || (int)($stored['book_order_num'] ?? -1) !== (int)$fixture['book_order_num']
                ))) {
                throw new RuntimeException('Isolated AI report input fixture readback failed');
            }
            Db::name('online_daily_data')->where('id', $rowId)->update([
                'readback_verified' => 1,
                'readback_verified_at' => $now,
            ]);
            $verified = Db::name('online_daily_data')->where('id', $rowId)->find();
            if (!is_array($verified) || (int)($verified['readback_verified'] ?? 0) !== 1) {
                throw new RuntimeException('Isolated AI report traffic fixture verification writeback failed');
            }
            $ids[] = $rowId;
        }
        return ['row_ids' => $ids, 'data_source_ids' => $dataSourceIds];
    });

    $rowIds = $fixtureResult['row_ids'];
    $dataSourceIds = $fixtureResult['data_source_ids'];

    return [
        'hotel_id' => $hotelId,
        'ota_hotel_id' => $trafficOtaHotelId,
        'business_ota_hotel_id' => $businessOtaHotelId,
        'row_ids' => $rowIds,
        'data_source_ids' => $dataSourceIds,
        'data_dates' => array_values(array_unique(array_column($fixtures, 'date'))),
        'readback_verified' => count($rowIds) === count($fixtures),
        'source_scope' => 'synthetic_isolated_e2e_ctrip_channel_fixture',
    ];
}

/**
 * Persist an explicitly synthetic, non-formal report so browser E2E can test
 * judgment/readback and the untrusted-action refusal without forging a real
 * dual-OTA P0 verifier receipt.
 *
 * @return array<string, mixed>
 */
function e2eSeedSyntheticAiReport(string $prefix): array
{
    $names = e2eNames($prefix);
    $hotelId = (int)getenv('SUXI_E2E_HOTEL_ID');
    $hotel = $hotelId > 0
        ? Db::name('hotels')->where('id', $hotelId)->field('id,name,tenant_id')->find()
        : null;
    if (!is_array($hotel)
        || (int)($hotel['id'] ?? 0) <= 0
        || (string)($hotel['name'] ?? '') !== $names['hotel_name']) {
        throw new RuntimeException('Isolated E2E hotel is missing for synthetic AI report fixture');
    }

    $reportDate = '2026-05-17';
    $tenantId = (int)($hotel['tenant_id'] ?? 0);
    $userId = (int)getenv('SUXI_E2E_USER_ID');
    $sourceRows = Db::name('online_daily_data')
        ->where('system_hotel_id', $hotelId)
        ->where('data_date', $reportDate)
        ->where('source', 'ctrip')
        ->where('ingestion_method', 'isolated_e2e_fixture')
        ->order('id', 'asc')
        ->column('id');
    $sourceRowIds = array_values(array_filter(array_map('intval', $sourceRows), static fn(int $id): bool => $id > 0));
    if (count($sourceRowIds) < 2) {
        throw new RuntimeException('Synthetic AI report fixture requires the isolated Ctrip input rows first');
    }

    $resultVersion = hash('sha256', implode('|', [$prefix, (string)$hotelId, $reportDate, 'non-formal-report-v1']));
    $snapshot = [
        'synthetic' => true,
        'fixture_scope' => 'isolated_e2e_non_formal_ai_report',
        'formal_report_generated' => false,
        'input_trust' => [
            'readback_verified' => false,
            'source_rows_readback_verified' => true,
            'formal_p0_gate_ready' => false,
            'quality_status' => 'synthetic_unverified',
            'reason_code' => 'isolated_fixture_has_no_external_p0_verifier_receipt',
        ],
        'report_scope' => [
            'hotel_id' => $hotelId,
            'report_date' => $reportDate,
            'source_scope' => 'ctrip_channel_synthetic_fixture',
            'whole_hotel_conclusions_allowed' => false,
        ],
        'result_contract' => [
            'result_version' => $resultVersion,
            'evidence_boundary' => 'synthetic_isolated_e2e_only',
        ],
        'trial_validation' => [
            'user_confirmed_useful' => null,
        ],
    ];
    $actions = [[
        'title' => '核查合成流量下降信号',
        'action' => '仅验证人工复核界面；不得转成真实执行单。',
        'reason' => '输入是隔离 E2E 合成数据，且不存在外部 P0 verifier 回执。',
        'platform' => 'ctrip',
        'object_type' => 'campaign',
        'action_type' => 'promotion',
        'expected_metric' => 'conversion',
        'expected_delta' => 0.0,
        'risk_level' => 'medium',
        'target_value' => [
            'campaign_type' => 'synthetic_fixture_review',
            'target_metric' => 'conversion',
        ],
        'can_create_execution_intent' => false,
        'blocked_reason' => 'Synthetic E2E input is not a trusted OTA readback and cannot create an execution intent.',
    ]];
    $sourceRefs = [[
        'ref' => 'online_daily_data.synthetic_e2e',
        'source' => 'ctrip',
        'platform' => 'ctrip',
        'system_hotel_id' => $hotelId,
        'data_date' => $reportDate,
        'metric_scope' => 'ctrip_channel_synthetic_fixture',
        'quality_status' => 'synthetic_unverified',
        'ingestion_method' => 'isolated_e2e_fixture',
        'readback_verified' => true,
        'row_ids' => $sourceRowIds,
        'summary' => 'Synthetic isolated E2E input; not eligible for a formal operating report.',
    ]];
    $now = date('Y-m-d H:i:s');

    $reportId = (int)Db::name('ai_daily_reports')->insertGetId(e2eFilterPayload('ai_daily_reports', [
        'tenant_id' => $tenantId,
        'hotel_id' => $hotelId,
        'report_date' => $reportDate,
        'status' => 'generated',
        'generation_mode' => 'synthetic_e2e',
        'model_key' => '',
        'model_status' => 'not_requested',
        'model_message' => 'Synthetic isolated E2E fixture; no formal P0 verifier receipt.',
        'summary' => '[Synthetic E2E / non-formal] Ctrip channel fixture for judgment and refusal-path verification.',
        'yesterday_result_json' => json_encode([
            'report_date' => $reportDate,
            'source_scope' => 'ctrip_channel_synthetic_fixture',
            'revenue' => 120000,
            'orders' => 120,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'abnormal_metrics_json' => json_encode([[
            'metric' => 'list_exposure',
            'status' => 'synthetic_signal_only',
        ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'competitor_changes_json' => '[]',
        'data_gaps_json' => json_encode([[
            'code' => 'formal_dual_ota_p0_verifier_missing',
            'message' => 'Synthetic fixture has no Meituan and external P0 verifier receipt.',
        ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'recommended_actions_json' => json_encode($actions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'source_refs_json' => json_encode($sourceRefs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'input_fingerprint' => $resultVersion,
        'prompt_version' => 'isolated-e2e-non-formal-v1',
        'created_by' => $userId,
        'created_at' => $now,
        'updated_at' => $now,
    ]));

    $stored = Db::name('ai_daily_reports')->where('id', $reportId)->find();
    $storedSnapshot = is_array($stored)
        ? json_decode((string)($stored['snapshot_json'] ?? ''), true)
        : null;
    if ($reportId <= 0
        || !is_array($stored)
        || (int)($stored['hotel_id'] ?? 0) !== $hotelId
        || (string)($stored['report_date'] ?? '') !== $reportDate
        || (string)($stored['generation_mode'] ?? '') !== 'synthetic_e2e'
        || !is_array($storedSnapshot)
        || ($storedSnapshot['synthetic'] ?? false) !== true
        || ($storedSnapshot['formal_report_generated'] ?? true) !== false
        || (($storedSnapshot['input_trust']['readback_verified'] ?? true) !== false)) {
        throw new RuntimeException('Synthetic AI report fixture exact readback failed');
    }

    return [
        'report_id' => $reportId,
        'hotel_id' => $hotelId,
        'report_date' => $reportDate,
        'generation_mode' => 'synthetic_e2e',
        'formal_report_generated' => false,
        'input_trust_readback_verified' => false,
        'persistence_readback_verified' => true,
        'source_row_ids' => $sourceRowIds,
        'source_scope' => 'synthetic_isolated_e2e_non_formal_report',
    ];
}

/**
 * Promote only the freshly saved temporal-axis synthetic rows into trusted
 * E2E fixtures. The public save endpoint deliberately cannot invent capture
 * provenance or claim that a generic business widget is the verified Ctrip
 * daily business overview. This isolated helper therefore attaches explicit
 * synthetic-only provenance, the captured endpoint/section contract and a
 * zero-missing field-fact summary before re-establishing readback proof.
 *
 * @return array<string, mixed>
 */
function e2eVerifyTemporalInputs(string $prefix): array
{
    $names = e2eNames($prefix);
    $hotelId = (int)getenv('SUXI_E2E_HOTEL_ID');
    $hotel = $hotelId > 0
        ? Db::name('hotels')->where('id', $hotelId)->field('id,name')->find()
        : null;
    if (!is_array($hotel)
        || (int)($hotel['id'] ?? 0) <= 0
        || (string)($hotel['name'] ?? '') !== $names['hotel_name']) {
        throw new RuntimeException('Isolated E2E hotel is missing for temporal input verification');
    }

    $otaHotelId = $prefix . '_ota';
    $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Shanghai'));
    $expectedDates = [];
    for ($offset = -14; $offset <= 0; $offset++) {
        $date = $today->modify(sprintf('%+d days', $offset))->format('Y-m-d');
        $expectedDates[$date] = $offset === 0 ? 'realtime_snapshot' : 'historical_daily';
    }

    $rows = Db::name('online_daily_data')
        ->where('system_hotel_id', $hotelId)
        ->where('hotel_id', $otaHotelId)
        ->where('source', 'ctrip')
        ->where('data_type', 'business')
        ->whereBetween('data_date', [array_key_first($expectedDates), array_key_last($expectedDates)])
        ->order('data_date', 'asc')
        ->select()
        ->toArray();
    if (count($rows) !== count($expectedDates)) {
        throw new RuntimeException('Temporal input fixture must contain exactly 15 isolated rows');
    }

    $rowsByDate = [];
    foreach ($rows as $row) {
        $date = (string)($row['data_date'] ?? '');
        if (isset($rowsByDate[$date])) {
            throw new RuntimeException('Temporal input fixture contains a duplicate date');
        }
        $rowsByDate[$date] = $row;
    }

    $verifiedAt = date('Y-m-d H:i:s');
    $rowIds = [];
    foreach ($expectedDates as $date => $expectedPeriod) {
        $row = $rowsByDate[$date] ?? null;
        $expectedFinal = $expectedPeriod === 'historical_daily' ? 1 : 0;
        if (!is_array($row)
            || (string)($row['data_period'] ?? '') !== $expectedPeriod
            || (int)($row['is_final'] ?? -1) !== $expectedFinal
            || !is_numeric($row['amount'] ?? null)
            || !is_numeric($row['quantity'] ?? null)
            || !is_numeric($row['book_order_num'] ?? null)) {
            throw new RuntimeException('Temporal input fixture date or metric contract failed for ' . $date);
        }

        $rowId = (int)($row['id'] ?? 0);
        $traceId = $prefix . '_temporal_' . str_replace('-', '', $date);
        $pending = e2eFilterPayload('online_daily_data', [
            'source_trace_id' => $traceId,
            'ingestion_method' => 'isolated_e2e_fixture',
            'compare_type' => 'self',
            'validation_status' => 'normal',
            'validation_flags' => '[]',
            'raw_data' => json_encode([
                'synthetic' => true,
                'fixture_scope' => 'isolated_e2e_temporal_axis',
                'source_trace_id' => $traceId,
                'row' => [
                    'endpoint_id' => 'business_market_overview',
                    'section' => 'business_overview',
                    'source' => 'ctrip',
                    'platform' => 'ctrip',
                    'compare_type' => 'self',
                    'hotelId' => $otaHotelId,
                    'hotelName' => (string)$hotel['name'],
                    'dataDate' => $date,
                    'amount' => (float)$row['amount'],
                    'quantity' => (float)$row['quantity'],
                    'bookOrderNum' => (int)$row['book_order_num'],
                ],
                'field_facts' => [
                    [
                        'metric_key' => 'order_amount',
                        'status' => 'captured',
                        'stored_value_present' => true,
                        'storage_field' => 'online_daily_data.amount',
                    ],
                    [
                        'metric_key' => 'room_nights',
                        'status' => 'captured',
                        'stored_value_present' => true,
                        'storage_field' => 'online_daily_data.quantity',
                    ],
                    [
                        'metric_key' => 'order_count',
                        'status' => 'captured',
                        'stored_value_present' => true,
                        'storage_field' => 'online_daily_data.book_order_num',
                    ],
                ],
                'field_fact_summary' => [
                    'captured_count' => 3,
                    'missing_count' => 0,
                    'missing_metric_keys' => [],
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'readback_verified' => 0,
            'readback_verified_at' => null,
            'update_time' => $verifiedAt,
        ]);
        if ($rowId <= 0
            || (int)Db::name('online_daily_data')
                ->where('id', $rowId)
                ->where('system_hotel_id', $hotelId)
                ->where('hotel_id', $otaHotelId)
                ->update($pending) !== 1) {
            throw new RuntimeException('Temporal input provenance write failed for ' . $date);
        }

        $stored = Db::name('online_daily_data')->where('id', $rowId)->find();
        $raw = is_array($stored) && is_string($stored['raw_data'] ?? null)
            ? json_decode((string)$stored['raw_data'], true)
            : null;
        if (!is_array($stored)
            || (string)($stored['source_trace_id'] ?? '') !== $traceId
            || (string)($stored['data_date'] ?? '') !== $date
            || (string)($stored['data_period'] ?? '') !== $expectedPeriod
            || (int)($stored['is_final'] ?? -1) !== $expectedFinal
            || (string)($stored['compare_type'] ?? '') !== 'self'
            || !is_array($raw)
            || ($raw['synthetic'] ?? false) !== true
            || (string)($raw['fixture_scope'] ?? '') !== 'isolated_e2e_temporal_axis'
            || strtolower(trim((string)($raw['row']['endpoint_id'] ?? ''))) !== 'business_market_overview'
            || strtolower(trim((string)($raw['row']['section'] ?? ''))) !== 'business_overview'
            || (int)($raw['field_fact_summary']['missing_count'] ?? 1) !== 0
            || (int)($stored['readback_verified'] ?? -1) !== 0) {
            throw new RuntimeException('Temporal input provenance readback failed for ' . $date);
        }

        if ((int)Db::name('online_daily_data')
            ->where('id', $rowId)
            ->where('source_trace_id', $traceId)
            ->where('readback_verified', 0)
            ->update([
                'readback_verified' => 1,
                'readback_verified_at' => $verifiedAt,
            ]) !== 1) {
            throw new RuntimeException('Temporal input verification writeback failed for ' . $date);
        }
        $rowIds[] = $rowId;
    }

    return [
        'hotel_id' => $hotelId,
        'ota_hotel_id' => $otaHotelId,
        'row_ids' => $rowIds,
        'historical_days' => count($expectedDates) - 1,
        'realtime_days' => 1,
        'readback_verified' => count($rowIds) === count($expectedDates),
        'source_scope' => 'synthetic_isolated_e2e_ctrip_channel_fixture',
    ];
}

/** @return array<string, int> */
function e2eDeletePrefixedRows(string $prefix): array
{
    $targets = [
        ['online_daily_data', 'hotel_id'],
        ['operation_action_tracks', 'action_title'],
        ['expansion_records', 'project_name'],
        ['transfer_records', 'hotel_name'],
        ['strategy_simulation_records', 'project_name'],
        ['quant_simulation_records', 'project_name'],
        ['feasibility_reports', 'project_name'],
    ];
    $deleted = [];
    foreach ($targets as [$table, $column]) {
        if (!e2eHasColumn($table, $column)) {
            continue;
        }
        $count = (int)e2ePrefixQuery($table, $column, $prefix)->delete();
        if ($count > 0) {
            $deleted[$table] = $count;
        }
    }
    return $deleted;
}

/** @return array<string, int> */
function e2eDeleteAiReportArtifacts(int $hotelId): array
{
    if ($hotelId <= 0) {
        return [];
    }

    $taskIds = [];
    if (e2eHasColumn('ai_report_generation_tasks', 'hotel_id')
        && e2eHasColumn('ai_report_generation_tasks', 'task_id')) {
        $taskIds = array_values(array_filter(array_map(
            'strval',
            Db::name('ai_report_generation_tasks')->where('hotel_id', $hotelId)->column('task_id')
        ), static fn(string $taskId): bool => preg_match('/^airpt_[A-Za-z0-9_\-]{16,90}$/D', $taskId) === 1));
    }

    $deleted = [];
    foreach ([
        ['ai_report_human_reviews', 'hotel_id'],
        ['analysis_reference_set_versions', 'system_hotel_id'],
        ['ai_report_input_cache', 'hotel_id'],
        ['ai_report_generation_tasks', 'hotel_id'],
    ] as [$table, $column]) {
        if (!e2eHasColumn($table, $column)) {
            continue;
        }
        $count = (int)Db::name($table)->where($column, $hotelId)->delete();
        if ($count > 0) {
            $deleted[$table] = $count;
        }
    }

    $runtime = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'ai_report_tasks';
    $removedLogs = 0;
    foreach ($taskIds as $taskId) {
        foreach (['.stdout.log', '.stderr.log', '.log'] as $suffix) {
            $path = $runtime . DIRECTORY_SEPARATOR . $taskId . $suffix;
            if (!is_file($path) || is_link($path)) {
                continue;
            }
            if (!unlink($path)) {
                throw new RuntimeException('Failed to remove isolated AI report task log');
            }
            $removedLogs++;
        }
    }
    if ($removedLogs > 0) {
        $deleted['ai_report_task_logs'] = $removedLogs;
    }

    return $deleted;
}

/** @return array<string, mixed> */
function e2eCleanup(string $prefix): array
{
    $names = e2eNames($prefix);
    $deleted = e2eDeletePrefixedRows($prefix);
    $hotelRows = Db::name('hotels')->where('name', $names['hotel_name'])->field('id,name,tenant_id')->select()->toArray();
    if (count($hotelRows) > 1) {
        throw new RuntimeException('Refusing cleanup: isolated E2E hotel name is not unique');
    }
    if ($hotelRows !== []) {
        $hotelId = (int)$hotelRows[0]['id'];
        $deleted = array_merge($deleted, e2eDeleteAiReportArtifacts($hotelId));
        e2eSetProtectedModules((int)($hotelRows[0]['tenant_id'] ?? 0), false);
        $result = (new HotelCascadeDeletionService())->delete($hotelId);
        $deleted['hotel_cascade_rows'] = (int)($result['deleted_rows'] ?? 0) + 1;
    }

    $userRows = Db::name('users')->where('username', $names['username'])->field('id,username')->select()->toArray();
    if (count($userRows) > 1) {
        throw new RuntimeException('Refusing cleanup: isolated E2E username is not unique');
    }
    if ($userRows !== []) {
        $userId = (int)$userRows[0]['id'];
        $authCacheEntries = 0;
        if (cache('user_token_' . $userId) !== null) {
            $authCacheEntries++;
        }
        // The per-user index intentionally stores only a one-way token digest.
        // Individual token entries expire by TTL and cannot be reconstructed here.
        cache('user_token_' . $userId, null);
        $deleted['auth_cache_entries'] = $authCacheEntries;
        if (e2eHasColumn('operation_logs', 'user_id')) {
            $deleted['operation_logs'] = (int)Db::name('operation_logs')->where('user_id', $userId)->delete();
        }
        if (e2eHasColumn('login_logs', 'user_id')) {
            $deleted['login_logs_by_user'] = (int)Db::name('login_logs')->where('user_id', $userId)->delete();
        }
        if (e2eHasColumn('login_logs', 'username')) {
            $deleted['login_logs_by_name'] = (int)Db::name('login_logs')->where('username', $names['username'])->delete();
        }
        if (e2eHasColumn('user_hotel_permissions', 'user_id')) {
            $deleted['user_hotel_permissions'] = (int)Db::name('user_hotel_permissions')->where('user_id', $userId)->delete();
        }
        $deleted['users'] = (int)Db::name('users')
            ->where('id', $userId)
            ->where('username', $names['username'])
            ->delete();
    }

    $roleRows = Db::name('roles')->where('name', $names['role_name'])->field('id,name')->select()->toArray();
    if (count($roleRows) > 1) {
        throw new RuntimeException('Refusing cleanup: isolated E2E role name is not unique');
    }
    if ($roleRows !== []) {
        $deleted['roles'] = (int)Db::name('roles')
            ->where('id', (int)$roleRows[0]['id'])
            ->where('name', $names['role_name'])
            ->delete();
    }

    $tenantRows = Db::name('tenants')->where('name', $names['tenant_name'])->field('id,name')->select()->toArray();
    if (count($tenantRows) > 1) {
        throw new RuntimeException('Refusing cleanup: isolated E2E tenant name is not unique');
    }
    if ($tenantRows !== []) {
        $deleted['tenants'] = (int)Db::name('tenants')
            ->where('id', (int)$tenantRows[0]['id'])
            ->where('name', $names['tenant_name'])
            ->delete();
    }

    return ['deleted' => array_filter($deleted, static fn(int $count): bool => $count > 0)];
}

try {
    $action = (string)($argv[1] ?? '');
    $databaseSafety = e2eDatabaseSafetyGuard();
    if ($action === 'guard') {
        $result = array_merge($databaseSafety, e2eAssertSchemaReady());
    } else {
        $prefix = e2ePrefix();
        $result = match ($action) {
            'count' => e2eCount($prefix),
            'seed' => e2eSeed($prefix),
            'seed-ai-report-inputs' => e2eSeedAiReportInputs($prefix),
            'seed-synthetic-ai-report' => e2eSeedSyntheticAiReport($prefix),
            'verify-temporal-inputs' => e2eVerifyTemporalInputs($prefix),
            'cleanup' => e2eCleanup($prefix),
            default => throw new RuntimeException('Unknown E2E isolation action'),
        };
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
