<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Shanghai');

$root = dirname(__DIR__);
require $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'ota_data_inventory_common.php';

/**
 * @return array<string, bool>
 */
function hotel_ota_columns(PDO $pdo, string $table): array
{
    if (!ota_inventory_table_exists($pdo, $table)) {
        return [];
    }

    $rows = ota_inventory_query_all($pdo, 'SHOW COLUMNS FROM `' . $table . '`');
    $columns = [];
    foreach ($rows as $row) {
        $field = (string)($row['Field'] ?? '');
        if ($field !== '') {
            $columns[$field] = true;
        }
    }

    return $columns;
}

/**
 * @return array<int, string>
 */
function hotel_ota_applicable_platforms(string $strategy): array
{
    return match ($strategy) {
        'none' => [],
        'ctrip_only' => ['ctrip'],
        'meituan_only' => ['meituan'],
        default => ['ctrip', 'meituan'],
    };
}

function hotel_ota_truthy(mixed $value): bool
{
    if ($value === true || $value === 1) {
        return true;
    }

    $text = strtolower(trim((string)$value));
    return in_array($text, ['1', 'true', 'yes', 'y', 'ok'], true);
}

/**
 * @return array<string, mixed>
 */
function hotel_ota_decode_json(?string $json): array
{
    $decoded = json_decode((string)$json, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * @param array<string, mixed> $config
 */
function hotel_ota_profile_verified(array $config): bool
{
    $manualVerified = false;
    foreach (['manual_login_state_verified', 'login_state_verified', 'profile_login_verified'] as $key) {
        if (array_key_exists($key, $config) && hotel_ota_truthy($config[$key])) {
            $manualVerified = true;
            break;
        }
    }

    $status = strtolower(trim((string)($config['profile_status'] ?? $config['login_status'] ?? '')));
    $statusVerified = in_array($status, ['logged_in', 'authorized'], true);

    $lastVerifiedAt = trim((string)(
        $config['last_login_verified_at']
        ?? $config['profile_login_verified_at']
        ?? $config['last_profile_login_at']
        ?? ''
    ));

    return $manualVerified && $statusVerified && $lastVerifiedAt !== '';
}

/**
 * @param array<string, mixed> $source
 */
function hotel_ota_source_ready(array $source): bool
{
    $status = strtolower(trim((string)($source['status'] ?? '')));
    return (int)($source['enabled'] ?? 0) === 1 && in_array($status, ['ready', 'success', 'partial_success'], true);
}

/**
 * @param array<string, mixed> $task
 */
function hotel_ota_task_activity_reference_at(array $task): string
{
    $times = [];
    foreach (['update_time', 'started_at'] as $key) {
        $value = trim((string)($task[$key] ?? ''));
        if ($value === '') {
            continue;
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            continue;
        }
        $times[] = ['value' => $value, 'timestamp' => $timestamp];
    }

    if ($times === []) {
        return '';
    }

    usort($times, static fn(array $left, array $right): int => $right['timestamp'] <=> $left['timestamp']);

    return (string)$times[0]['value'];
}

/**
 * @param array<string, mixed> $task
 */
function hotel_ota_task_stale_running(array $task): bool
{
    $status = strtolower(trim((string)($task['status'] ?? '')));
    if (!in_array($status, ['pending', 'queued', 'running', 'browser_opened', 'syncing', 'syncing_after_login'], true)) {
        return false;
    }

    $reference = hotel_ota_task_activity_reference_at($task);
    if ($reference === '') {
        return false;
    }

    $timestamp = strtotime($reference);
    return $timestamp !== false && (time() - $timestamp) > 30 * 60;
}

/**
 * @param array<string, mixed> $task
 */
function hotel_ota_task_reference_at(array $task): string
{
    return hotel_ota_task_activity_reference_at($task);
}

/**
 * @param array<int, array<string, mixed>> $tasks
 * @return array<string, mixed>
 */
function hotel_ota_task_evidence(array $tasks): array
{
    $runningIds = [];
    $staleRunningIds = [];
    $runningTaskCount = 0;
    $staleRunningTaskCount = 0;
    $missingTaskIdCount = 0;
    $staleReferenceTimes = [];
    $latestUpdateTime = '';
    foreach ($tasks as $task) {
        $id = (int)($task['id'] ?? 0);
        $updateTime = trim((string)($task['update_time'] ?? ''));
        if ($updateTime !== '' && strcmp($updateTime, $latestUpdateTime) > 0) {
            $latestUpdateTime = $updateTime;
        }

        $status = strtolower(trim((string)($task['status'] ?? '')));
        if (!in_array($status, ['pending', 'queued', 'running', 'browser_opened', 'syncing', 'syncing_after_login'], true)) {
            continue;
        }

        if (hotel_ota_task_stale_running($task)) {
            $staleRunningTaskCount++;
            if ($id > 0) {
                $staleRunningIds[] = (string)$id;
            } else {
                $missingTaskIdCount++;
            }
            $referenceAt = hotel_ota_task_reference_at($task);
            if ($referenceAt !== '') {
                $staleReferenceTimes[] = $referenceAt;
            }
            continue;
        }

        $runningTaskCount++;
        if ($id > 0) {
            $runningIds[] = (string)$id;
        } else {
            $missingTaskIdCount++;
        }
    }

    sort($staleReferenceTimes);
    $blockingIds = $runningIds !== [] ? $runningIds : $staleRunningIds;
    $activeTaskCount = $runningTaskCount + $staleRunningTaskCount;

    return [
        'running_task_count' => $runningTaskCount,
        'stale_running_task_count' => $staleRunningTaskCount,
        'missing_task_id_count' => $missingTaskIdCount,
        'task_id_evidence_complete' => $activeTaskCount === count($runningIds) + count($staleRunningIds),
        'blocking_task_ids' => implode(',', $blockingIds),
        'running_task_ids' => implode(',', $runningIds),
        'stale_running_task_ids' => implode(',', $staleRunningIds),
        'oldest_stale_task_at' => $staleReferenceTimes[0] ?? '',
        'latest_task_update_time' => $latestUpdateTime,
    ];
}

/**
 * @param array<int, array<string, mixed>> $tasks
 */
function hotel_ota_task_state(array $tasks): string
{
    if ($tasks === []) {
        return 'none';
    }

    $hasRunning = false;
    $hasStale = false;
    foreach ($tasks as $task) {
        if (hotel_ota_task_stale_running($task)) {
            $hasStale = true;
            continue;
        }

        $status = strtolower(trim((string)($task['status'] ?? '')));
        if (in_array($status, ['pending', 'queued', 'running', 'browser_opened', 'syncing', 'syncing_after_login'], true)) {
            $hasRunning = true;
        }
    }

    if ($hasRunning) {
        return 'running';
    }
    if ($hasStale) {
        return 'stale_running';
    }

    return 'none';
}

/**
 * @param array<int, string> $blockers
 */
function hotel_ota_status_from_blockers(array $blockers, bool $profileVerified): string
{
    if (in_array('inactive_hotel', $blockers, true)) {
        return 'blocked_inactive_hotel';
    }
    if (in_array('sync_task_running', $blockers, true)) {
        return 'blocked_running_task';
    }
    if (in_array('sync_task_stale_running', $blockers, true)) {
        return 'blocked_stale_running_task';
    }
    if (in_array('missing_fetch_permission', $blockers, true)) {
        return 'blocked_permission';
    }
    if (in_array('missing_platform_source', $blockers, true)) {
        return 'blocked_missing_source';
    }
    if (in_array('source_not_ready', $blockers, true)) {
        return 'blocked_source_not_ready';
    }
    if (!$profileVerified) {
        return 'ready_for_manual_login';
    }

    return 'ready_for_session_probe';
}

function hotel_ota_platform_login_entry(string $platform): string
{
    return match ($platform) {
        'ctrip' => 'https://ebooking.ctrip.com/home/mainland',
        'meituan' => 'https://me.meituan.com/ebooking/',
        default => '',
    };
}

/**
 * @param array<int, string> $blockers
 */
function hotel_ota_primary_blocker(string $status, array $blockers): string
{
    $statusBlockers = [
        'blocked_inactive_hotel' => 'inactive_hotel',
        'blocked_running_task' => 'sync_task_running',
        'blocked_stale_running_task' => 'sync_task_stale_running',
        'blocked_permission' => 'missing_fetch_permission',
        'blocked_missing_source' => 'missing_platform_source',
        'blocked_source_not_ready' => 'source_not_ready',
    ];

    $candidate = $statusBlockers[$status] ?? '';
    if ($candidate !== '' && in_array($candidate, $blockers, true)) {
        return $candidate;
    }

    return $blockers[0] ?? '';
}

/**
 * @param array<int, string> $blockers
 */
function hotel_ota_next_action(string $status, array $blockers, int $hotelId, string $platform): string
{
    if (in_array('inactive_hotel', $blockers, true)) {
        return '先确认该门店是否应启用；停用门店不进入 OTA 登录和采集流程。';
    }
    if (in_array('sync_task_running', $blockers, true)) {
        return '等待同门店同平台同步任务结束，再重跑单店资格检查。';
    }
    if (in_array('sync_task_stale_running', $blockers, true)) {
        return '先在平台同步任务中复核 stale_running 任务；确认无真实采集进程后再做受控清理。';
    }
    if (in_array('missing_fetch_permission', $blockers, true)) {
        return '先给负责账号授予该门店 can_fetch_online_data 权限，再重跑资格检查。';
    }
    if (in_array('missing_platform_source', $blockers, true)) {
        return '先为该门店绑定 ' . $platform . ' platform_data_sources；如果该平台不适用，改门店 OTA 策略而不是用缺配置兜底。';
    }
    if (in_array('source_not_ready', $blockers, true)) {
        return '先修复该平台数据源状态到 ready/success，并确认 enabled=1。';
    }
    if ($status === 'ready_for_manual_login') {
        return '可做单店人工登录；完成后只重跑该门店该平台资格检查。';
    }
    if ($status === 'ready_for_session_probe') {
        return '历史登录元数据和 Profile 已就绪；先由账号持有人执行当前会话探测，探测通过后再进入单店采集或 P0 字段闭环验证。';
    }

    return '保留当前阻断状态，先按 blockers 字段逐项处理。';
}

/**
 * @param array<string, mixed> $row
 */
function hotel_ota_platform_actionable(array $row): bool
{
    return in_array((string)($row['status'] ?? ''), ['ready_for_session_probe', 'ready_for_manual_login'], true);
}

/**
 * @param array<string, mixed> $row
 */
function hotel_ota_platform_missing_source(array $row): bool
{
    $blockers = array_filter(explode(',', (string)($row['blockers'] ?? '')));
    return (string)($row['status'] ?? '') === 'blocked_missing_source'
        && in_array('missing_platform_source', $blockers, true);
}

/**
 * @param array<string, mixed> $evidence
 */
function hotel_ota_permission_blocker_reason(array $evidence): string
{
    if ((int)($evidence['permission_user_count'] ?? 0) <= 0) {
        return 'no_permission_rows';
    }
    if ((int)($evidence['fetch_permission_user_count'] ?? 0) <= 0) {
        return 'no_fetch_permission_grant';
    }
    if ((int)($evidence['active_fetch_permission_user_count'] ?? 0) > 0) {
        return '';
    }
    if ((int)($evidence['expired_fetch_permission_user_count'] ?? 0) > 0) {
        return 'fetch_permission_expired';
    }
    if ((int)($evidence['inactive_fetch_permission_user_count'] ?? 0) > 0) {
        return 'fetch_permission_inactive';
    }

    return 'fetch_permission_not_currently_effective';
}

/**
 * @param array<int, array<string, mixed>> $platformRows
 * @return array<string, mixed>
 */
function hotel_ota_strategy_candidate(string $strategy, array $platformRows): array
{
    if ($strategy !== 'dual') {
        return [];
    }

    $byPlatform = [];
    foreach ($platformRows as $row) {
        $platform = (string)($row['platform'] ?? '');
        if (in_array($platform, ['ctrip', 'meituan'], true)) {
            $byPlatform[$platform] = $row;
        }
    }

    $ctrip = $byPlatform['ctrip'] ?? null;
    $meituan = $byPlatform['meituan'] ?? null;
    if (!is_array($ctrip) || !is_array($meituan)) {
        return [];
    }

    $base = [
        'hotel_id' => (int)($ctrip['hotel_id'] ?? $meituan['hotel_id'] ?? 0),
        'hotel_name' => (string)($ctrip['hotel_name'] ?? $meituan['hotel_name'] ?? ''),
        'current_strategy' => 'dual',
        'confirmation_required' => 'candidate only; confirm business OTA channel before updating strategy.',
    ];

    if (hotel_ota_platform_actionable($ctrip) && hotel_ota_platform_missing_source($meituan)) {
        return $base + [
            'candidate_strategy' => 'ctrip_only',
            'available_platform' => 'ctrip',
            'blocked_platform' => 'meituan',
            'reason' => 'ctrip_session_probe_or_manual_login_meituan_source_missing',
            'next_action' => 'Confirm whether this store should stay dual. If not, update ota_channel_strategy to ctrip_only instead of adding a placeholder Meituan source.',
        ];
    }

    if (hotel_ota_platform_actionable($meituan) && hotel_ota_platform_missing_source($ctrip)) {
        return $base + [
            'candidate_strategy' => 'meituan_only',
            'available_platform' => 'meituan',
            'blocked_platform' => 'ctrip',
            'reason' => 'meituan_session_probe_or_manual_login_ctrip_source_missing',
            'next_action' => 'Confirm whether this store should stay dual. If not, update ota_channel_strategy to meituan_only instead of adding a placeholder Ctrip source.',
        ];
    }

    return [];
}

function hotel_ota_recheck_command(int $hotelId, string $platform): string
{
    return 'npm.cmd run verify:hotel-ota-login-eligibility -- --hotel-id=' . $hotelId . ' --platform=' . $platform . ' --format=json --strict';
}

try {
    $options = ota_inventory_parse_options($argv, [
        'format' => 'markdown',
        'strict' => false,
        'hotel-id' => '',
        'platform' => 'all',
        'limit' => '200',
    ]);

    if (!in_array((string)$options['format'], ['json', 'markdown'], true)) {
        throw new InvalidArgumentException('Invalid --format, expected json or markdown.');
    }

    $platformFilter = strtolower(trim((string)$options['platform']));
    if (!in_array($platformFilter, ['all', 'ctrip', 'meituan'], true)) {
        throw new InvalidArgumentException('Invalid --platform, expected all, ctrip, or meituan.');
    }

    $hotelIdFilter = trim((string)$options['hotel-id']);
    if ($hotelIdFilter !== '' && !preg_match('/^\d+$/', $hotelIdFilter)) {
        throw new InvalidArgumentException('Invalid --hotel-id, expected an integer.');
    }

    $limit = max(1, min(500, (int)$options['limit']));
    $pdo = ota_inventory_connect($root);

    $requiredTables = ['hotels', 'user_hotel_permissions', 'platform_data_sources'];
    $missingTables = array_values(array_filter(
        $requiredTables,
        static fn(string $table): bool => !ota_inventory_table_exists($pdo, $table)
    ));

    $issues = [];
    foreach ($missingTables as $table) {
        $issues[] = ['severity' => 'error', 'code' => $table . '_missing', 'message' => $table . ' table is required for login eligibility checks.'];
    }

    $hotelColumns = hotel_ota_columns($pdo, 'hotels');
    $permissionColumns = hotel_ota_columns($pdo, 'user_hotel_permissions');
    $sourceColumns = hotel_ota_columns($pdo, 'platform_data_sources');
    $taskColumns = hotel_ota_columns($pdo, 'platform_data_sync_tasks');

    $hasOtaStrategy = isset($hotelColumns['ota_channel_strategy']);
    $hasPermissionStatus = isset($permissionColumns['status']);
    $hasPermissionExpires = isset($permissionColumns['expires_at']);
    $hasFetchPermission = isset($permissionColumns['can_fetch_online_data']);
    $hasSourceConfig = isset($sourceColumns['config_json']);
    $hasTasks = ota_inventory_table_exists($pdo, 'platform_data_sync_tasks');

    if (!$hasFetchPermission) {
        $issues[] = ['severity' => 'error', 'code' => 'can_fetch_online_data_missing', 'message' => 'user_hotel_permissions.can_fetch_online_data is required.'];
    }
    if (!$hasOtaStrategy) {
        $issues[] = ['severity' => 'warning', 'code' => 'ota_channel_strategy_missing', 'message' => 'hotels.ota_channel_strategy is missing; no OTA channel is assumed until the schema is migrated and the hotel strategy is confirmed.'];
    }
    if (!$hasSourceConfig) {
        $issues[] = ['severity' => 'warning', 'code' => 'source_config_json_missing', 'message' => 'platform_data_sources.config_json is missing; Profile login verification cannot be proven.'];
    }
    if (!$hasTasks) {
        $issues[] = ['severity' => 'warning', 'code' => 'sync_task_table_missing', 'message' => 'platform_data_sync_tasks is missing; conflicting running tasks are not verified.'];
    }

    $hotels = [];
    if ($missingTables === []) {
        $strategyExpr = $hasOtaStrategy
            ? "COALESCE(NULLIF(h.`ota_channel_strategy`, ''), 'none') AS ota_channel_strategy"
            : "'none' AS ota_channel_strategy";
        $hotelWhere = $hotelIdFilter !== '' ? 'WHERE h.`id` = ?' : '';
        $hotelParams = $hotelIdFilter !== '' ? [(int)$hotelIdFilter] : [];
        $hotels = ota_inventory_query_all($pdo, "
            SELECT h.`id`, h.`name`, h.`status`, {$strategyExpr}
            FROM `hotels` h
            {$hotelWhere}
            ORDER BY h.`id`
        ", $hotelParams);

        if ($hotelIdFilter !== '' && $hotels === []) {
            $issues[] = [
                'severity' => 'error',
                'code' => 'hotel_not_found',
                'message' => 'Requested hotel_id ' . $hotelIdFilter . ' was not found; do not treat an empty eligibility report as login-ready.',
            ];
        }
    }

    $permissionEvidenceByHotel = [];
    if ($missingTables === [] && $hasFetchPermission) {
        $permissionWhere = ['1 = 1'];
        $statusCurrentExpr = $hasPermissionStatus
            ? "uhp.`status` IN ('active', '1', 1)"
            : '1 = 1';
        $statusInactiveExpr = $hasPermissionStatus
            ? "uhp.`can_fetch_online_data` = 1 AND NOT ({$statusCurrentExpr})"
            : '0 = 1';
        $expiresCurrentExpr = $hasPermissionExpires
            ? "(uhp.`expires_at` IS NULL OR uhp.`expires_at` >= NOW())"
            : '1 = 1';
        $expiresExpiredExpr = $hasPermissionExpires
            ? "uhp.`can_fetch_online_data` = 1 AND uhp.`expires_at` IS NOT NULL AND uhp.`expires_at` < NOW()"
            : '0 = 1';
        $activeFetchExpr = "uhp.`can_fetch_online_data` = 1 AND {$statusCurrentExpr} AND {$expiresCurrentExpr}";
        if ($hasPermissionStatus) {
            $permissionWhere[] = "(uhp.`status` IS NULL OR uhp.`status` <> 'deleted')";
        }
        if ($hotelIdFilter !== '') {
            $permissionWhere[] = 'uhp.`hotel_id` = ' . (int)$hotelIdFilter;
        }
        $permissionRows = ota_inventory_query_all($pdo, "
            SELECT uhp.`hotel_id`,
                   COUNT(DISTINCT uhp.`user_id`) AS permission_user_count,
                   COUNT(DISTINCT CASE WHEN uhp.`can_fetch_online_data` = 1 THEN uhp.`user_id` END) AS fetch_permission_user_count,
                   COUNT(DISTINCT CASE WHEN {$activeFetchExpr} THEN uhp.`user_id` END) AS active_fetch_permission_user_count,
                   COUNT(DISTINCT CASE WHEN {$statusInactiveExpr} THEN uhp.`user_id` END) AS inactive_fetch_permission_user_count,
                   COUNT(DISTINCT CASE WHEN {$expiresExpiredExpr} THEN uhp.`user_id` END) AS expired_fetch_permission_user_count
            FROM `user_hotel_permissions` uhp
            WHERE " . implode(' AND ', $permissionWhere) . "
            GROUP BY uhp.`hotel_id`
        ");
        foreach ($permissionRows as $row) {
            $hotelId = (int)($row['hotel_id'] ?? 0);
            $evidence = [
                'permission_user_count' => (int)($row['permission_user_count'] ?? 0),
                'fetch_permission_user_count' => (int)($row['fetch_permission_user_count'] ?? 0),
                'active_fetch_permission_user_count' => (int)($row['active_fetch_permission_user_count'] ?? 0),
                'inactive_fetch_permission_user_count' => (int)($row['inactive_fetch_permission_user_count'] ?? 0),
                'expired_fetch_permission_user_count' => (int)($row['expired_fetch_permission_user_count'] ?? 0),
            ];
            $evidence['permission_blocker_reason'] = hotel_ota_permission_blocker_reason($evidence);
            $permissionEvidenceByHotel[$hotelId] = $evidence;
        }
    }

    $sourcesByHotelPlatform = [];
    if ($missingTables === []) {
        $sourceWhere = [];
        if ($hotelIdFilter !== '') {
            $sourceWhere[] = 'p.`system_hotel_id` = ' . (int)$hotelIdFilter;
        }
        // Strategy candidate checks need same-hotel sibling platform metadata even for --platform output filters.
        $sourceWhereSql = $sourceWhere === [] ? '' : 'WHERE ' . implode(' AND ', $sourceWhere);
        $configSelect = $hasSourceConfig ? 'p.`config_json`' : "'' AS config_json";
        $sourceRows = ota_inventory_query_all($pdo, "
            SELECT p.`id`, p.`system_hotel_id`, LOWER(p.`platform`) AS platform,
                   p.`enabled`, p.`status`, p.`last_sync_status`, p.`last_sync_time`, p.`update_time`,
                   {$configSelect}
            FROM `platform_data_sources` p
            {$sourceWhereSql}
            ORDER BY p.`system_hotel_id`, platform, p.`id`
        ");
        foreach ($sourceRows as $row) {
            $hotelId = (int)($row['system_hotel_id'] ?? 0);
            $platform = strtolower(trim((string)($row['platform'] ?? '')));
            if ($hotelId <= 0 || !in_array($platform, ['ctrip', 'meituan'], true)) {
                continue;
            }
            $sourcesByHotelPlatform[$hotelId][$platform][] = $row;
        }
    }

    $tasksByHotelPlatform = [];
    if ($hasTasks && $missingTables === []) {
        $taskWhere = ["t.`status` IN ('pending', 'queued', 'running', 'browser_opened', 'syncing', 'syncing_after_login')"];
        if ($hotelIdFilter !== '') {
            $taskWhere[] = 't.`system_hotel_id` = ' . (int)$hotelIdFilter;
        }
        // Keep sibling platform task state available so single-platform checks do not hide strategy candidates.
        $taskRows = ota_inventory_query_all($pdo, "
            SELECT t.`id`, t.`system_hotel_id`, LOWER(t.`platform`) AS platform,
                   t.`status`, t.`started_at`, t.`update_time`
            FROM `platform_data_sync_tasks` t
            WHERE " . implode(' AND ', $taskWhere) . "
            ORDER BY t.`system_hotel_id`, platform, t.`id` DESC
        ");
        foreach ($taskRows as $row) {
            $hotelId = (int)($row['system_hotel_id'] ?? 0);
            $platform = strtolower(trim((string)($row['platform'] ?? '')));
            if ($hotelId <= 0 || !in_array($platform, ['ctrip', 'meituan'], true)) {
                continue;
            }
            $tasksByHotelPlatform[$hotelId][$platform][] = $row;
        }
    }

    $platformRows = [];
    $statusCounts = [];
    $hotelRollup = [];
    $strategyCandidates = [];
    $hasNotApplicableSpecificRequest = false;
    $rollupScope = $platformFilter === 'all' ? 'all_applicable_platforms' : 'requested_platform_only';
    foreach ($hotels as $hotel) {
        $hotelId = (int)($hotel['id'] ?? 0);
        if ($hotelId <= 0) {
            continue;
        }
        $hotelName = (string)($hotel['name'] ?? '');
        $hotelStatus = (int)($hotel['status'] ?? 0);
        $hotelLifecycleState = $hotelStatus === 1 ? 'active' : 'inactive';
        $inactiveHotelBlocksOtaFlow = $hotelStatus !== 1;
        $strategy = strtolower(trim((string)($hotel['ota_channel_strategy'] ?? 'none')));
        if (!in_array($strategy, ['none', 'ctrip_only', 'dual', 'meituan_only'], true)) {
            $strategy = 'dual';
        }
        $strategyPlatforms = hotel_ota_applicable_platforms($strategy);
        $outputPlatforms = $strategyPlatforms;
        if ($platformFilter !== 'all') {
            $outputPlatforms = array_values(array_filter($strategyPlatforms, static fn(string $platform): bool => $platform === $platformFilter));
            if ($hotelIdFilter !== '' && $outputPlatforms === []) {
                $hasNotApplicableSpecificRequest = true;
                $issues[] = [
                    'severity' => 'warning',
                    'code' => 'platform_not_applicable_to_strategy',
                    'message' => 'Requested platform ' . $platformFilter . ' is not applicable to hotel_id ' . (string)$hotelId . ' under ota_channel_strategy=' . $strategy . '; do not treat an empty platform report as login-ready.',
                ];
            }
        }
        $permissionEvidence = $permissionEvidenceByHotel[$hotelId] ?? [
            'permission_user_count' => 0,
            'fetch_permission_user_count' => 0,
            'active_fetch_permission_user_count' => 0,
            'inactive_fetch_permission_user_count' => 0,
            'expired_fetch_permission_user_count' => 0,
            'permission_blocker_reason' => 'no_permission_rows',
        ];
        $fetchUserCount = (int)($permissionEvidence['active_fetch_permission_user_count'] ?? 0);

        $readyForSessionProbeCount = 0;
        $readyForManualLoginCount = 0;
        $platformCount = 0;
        $hotelPlatformRows = [];
        foreach ($strategyPlatforms as $platform) {
            $sources = $sourcesByHotelPlatform[$hotelId][$platform] ?? [];
            $enabledSources = array_values(array_filter($sources, static fn(array $row): bool => (int)($row['enabled'] ?? 0) === 1));
            $readySources = array_values(array_filter($enabledSources, 'hotel_ota_source_ready'));
            $profileVerifiedSources = [];
            $lastLoginVerifiedAt = '';
            foreach ($enabledSources as $source) {
                $config = hotel_ota_decode_json(isset($source['config_json']) ? (string)$source['config_json'] : '');
                $sourceProfileVerified = hotel_ota_profile_verified($config);
                if ($sourceProfileVerified) {
                    $profileVerifiedSources[] = $source;
                    foreach (['last_login_verified_at', 'profile_login_verified_at', 'last_profile_login_at'] as $key) {
                        $candidate = trim((string)($config[$key] ?? ''));
                        if ($candidate !== '' && strcmp($candidate, $lastLoginVerifiedAt) > 0) {
                            $lastLoginVerifiedAt = $candidate;
                        }
                    }
                }
            }
            $taskRows = $tasksByHotelPlatform[$hotelId][$platform] ?? [];
            $taskState = hotel_ota_task_state($taskRows);
            $taskEvidence = hotel_ota_task_evidence($taskRows);
            $blockers = [];
            if ($hotelStatus !== 1) {
                $blockers[] = 'inactive_hotel';
            }
            // Task blockers stay ahead of permission/source blockers so operators do not bypass collector locks.
            if ($taskState === 'running') {
                $blockers[] = 'sync_task_running';
            } elseif ($taskState === 'stale_running') {
                $blockers[] = 'sync_task_stale_running';
            }
            if ($fetchUserCount <= 0) {
                $blockers[] = 'missing_fetch_permission';
            }
            if ($enabledSources === []) {
                $blockers[] = 'missing_platform_source';
            } elseif ($readySources === []) {
                $blockers[] = 'source_not_ready';
            }

            $profileVerified = $profileVerifiedSources !== [];
            $status = hotel_ota_status_from_blockers($blockers, $profileVerified);
            $nextAction = hotel_ota_next_action($status, $blockers, $hotelId, $platform);
            $recheckCommand = hotel_ota_recheck_command($hotelId, $platform);
            if (in_array('missing_fetch_permission', $blockers, true) && !in_array('inactive_hotel', $blockers, true)) {
                $nextAction .= ' permission_blocker_reason=' . (string)($permissionEvidence['permission_blocker_reason'] ?? 'unknown') . '。';
            }
            if ($taskState === 'stale_running' && (string)$taskEvidence['blocking_task_ids'] !== '') {
                $nextAction .= ' blocking_task_ids=' . (string)$taskEvidence['blocking_task_ids'] . '；只在确认无真实采集进程后处理这些任务。';
            } elseif ($taskState === 'stale_running' && !$taskEvidence['task_id_evidence_complete']) {
                $nextAction .= ' 任务 ID 证据不完整，先按门店/平台复查 platform_data_sync_tasks，不能凭状态批量清理。';
            }

            $hotelPlatformRows[] = [
                'hotel_id' => $hotelId,
                'hotel_name' => $hotelName,
                'hotel_status' => $hotelStatus,
                'hotel_lifecycle_state' => $hotelLifecycleState,
                'inactive_hotel_blocks_ota_flow' => $inactiveHotelBlocksOtaFlow,
                'downstream_setup_suppressed' => $inactiveHotelBlocksOtaFlow,
                'ota_channel_strategy' => $strategy,
                'platform' => $platform,
                'fetch_user_count' => $fetchUserCount,
                'permission_user_count' => $permissionEvidence['permission_user_count'],
                'fetch_permission_user_count' => $permissionEvidence['fetch_permission_user_count'],
                'active_fetch_permission_user_count' => $permissionEvidence['active_fetch_permission_user_count'],
                'inactive_fetch_permission_user_count' => $permissionEvidence['inactive_fetch_permission_user_count'],
                'expired_fetch_permission_user_count' => $permissionEvidence['expired_fetch_permission_user_count'],
                'permission_blocker_reason' => $permissionEvidence['permission_blocker_reason'],
                'enabled_source_count' => count($enabledSources),
                'ready_source_count' => count($readySources),
                'profile_verified_count' => count($profileVerifiedSources),
                'historical_profile_verified_count' => count($profileVerifiedSources),
                'login_evidence_scope' => 'historical_metadata_only',
                'current_session_probe_performed' => false,
                'current_session_verified' => false,
                'current_session_status' => 'unverified',
                'task_state' => $taskState,
                'running_task_count' => $taskEvidence['running_task_count'],
                'stale_running_task_count' => $taskEvidence['stale_running_task_count'],
                'missing_task_id_count' => $taskEvidence['missing_task_id_count'],
                'task_id_evidence_complete' => $taskEvidence['task_id_evidence_complete'],
                'blocking_task_ids' => $taskEvidence['blocking_task_ids'],
                'running_task_ids' => $taskEvidence['running_task_ids'],
                'stale_running_task_ids' => $taskEvidence['stale_running_task_ids'],
                'oldest_stale_task_at' => $taskEvidence['oldest_stale_task_at'],
                'latest_task_update_time' => $taskEvidence['latest_task_update_time'],
                'status' => $status,
                'primary_blocker' => hotel_ota_primary_blocker($status, $blockers),
                'blockers' => implode(',', $blockers),
                'next_action' => $nextAction,
                'recheck_command' => $recheckCommand,
                'manual_login_entry' => $status === 'ready_for_manual_login' ? hotel_ota_platform_login_entry($platform) : '',
                'strategy_adjustment_candidate' => false,
                'strategy_adjustment_candidate_value' => '',
                'strategy_adjustment_note' => '',
                'last_login_verified_at' => $lastLoginVerifiedAt,
                'source_policy' => 'read_platform_data_sources_metadata_only',
                'sensitive_values_exposed' => false,
            ];
        }

        $strategyCandidate = hotel_ota_strategy_candidate($strategy, $hotelPlatformRows);
        if ($strategyCandidate !== []) {
            foreach ($hotelPlatformRows as &$row) {
                if ((string)($row['platform'] ?? '') !== (string)($strategyCandidate['blocked_platform'] ?? '')) {
                    continue;
                }
                if (!hotel_ota_platform_missing_source($row)) {
                    continue;
                }
                $row['strategy_adjustment_candidate'] = true;
                $row['strategy_adjustment_candidate_value'] = (string)($strategyCandidate['candidate_strategy'] ?? '');
                $row['strategy_adjustment_note'] = (string)($strategyCandidate['confirmation_required'] ?? '');
                $row['next_action'] = (string)($strategyCandidate['next_action'] ?? $row['next_action']);
            }
            unset($row);
        }
        $visibleStrategyCandidate = $strategyCandidate;
        if ($visibleStrategyCandidate !== [] && $platformFilter !== 'all' && (string)($visibleStrategyCandidate['blocked_platform'] ?? '') !== $platformFilter) {
            $visibleStrategyCandidate = [];
        }
        if ($visibleStrategyCandidate !== []) {
            $strategyCandidates[] = $visibleStrategyCandidate;
        }

        $outputPlatformMap = array_fill_keys($outputPlatforms, true);
        $outputHotelPlatformRows = array_values(array_filter(
            $hotelPlatformRows,
            static fn(array $row): bool => isset($outputPlatformMap[(string)($row['platform'] ?? '')])
        ));
        foreach ($outputHotelPlatformRows as $row) {
            $status = (string)($row['status'] ?? '');
            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
            if ($status === 'ready_for_session_probe') {
                $readyForSessionProbeCount++;
            }
            if ($status === 'ready_for_manual_login') {
                $readyForManualLoginCount++;
            }
        }
        $platformCount = count($outputHotelPlatformRows);
        $platformRows = array_merge($platformRows, $outputHotelPlatformRows);

        $hotelRollup[] = [
            'hotel_id' => $hotelId,
            'hotel_name' => $hotelName,
            'hotel_status' => $hotelStatus,
            'hotel_lifecycle_state' => $hotelLifecycleState,
            'inactive_hotel_blocks_ota_flow' => $inactiveHotelBlocksOtaFlow,
            'downstream_setup_suppressed' => $inactiveHotelBlocksOtaFlow,
            'ota_channel_strategy' => $strategy,
            'rollup_scope' => $rollupScope,
            'applicable_platform_count' => $platformCount,
            'ready_to_collect_platform_count' => 0,
            'ready_for_session_probe_platform_count' => $readyForSessionProbeCount,
            'ready_for_manual_login_platform_count' => $readyForManualLoginCount,
            'hotel_ready_to_collect' => 0,
            'hotel_ready_for_session_probe' => $platformCount > 0 && $readyForSessionProbeCount === $platformCount ? 1 : 0,
            'strategy_candidate' => (string)($visibleStrategyCandidate['candidate_strategy'] ?? ''),
        ];
    }

    $knownHotelIds = array_fill_keys(array_map(static fn(array $hotel): int => (int)($hotel['id'] ?? 0), $hotels), true);
    $orphanSources = [];
    if ($missingTables === [] && $hotelIdFilter === '') {
        $orphanPlatformWhere = $platformFilter === 'all'
            ? "LOWER(p.`platform`) IN ('ctrip', 'meituan')"
            : "LOWER(p.`platform`) = " . $pdo->quote($platformFilter);
        $orphanSources = ota_inventory_query_all($pdo, "
            SELECT p.`system_hotel_id` AS missing_hotel_id, LOWER(p.`platform`) AS platform,
                   p.`enabled`, p.`status`, p.`last_sync_status`, COUNT(*) AS rows_count
            FROM `platform_data_sources` p
            LEFT JOIN `hotels` h ON h.`id` = p.`system_hotel_id`
            WHERE h.`id` IS NULL AND {$orphanPlatformWhere}
            GROUP BY p.`system_hotel_id`, platform, p.`enabled`, p.`status`, p.`last_sync_status`
            ORDER BY p.`system_hotel_id`, platform
        ");
        $orphanSources = array_map(static function (array $row): array {
            $row['flow_included'] = false;
            $row['next_action'] = '先核对缺失 hotel_id 是否应恢复门店或迁移绑定；未确认前不得进入 OTA 登录/采集流程。';
            return $row;
        }, $orphanSources);
        $orphanIssueKeys = [];
        foreach ($orphanSources as $row) {
            $key = (string)($row['missing_hotel_id'] ?? '') . ':' . (string)($row['platform'] ?? '');
            if (isset($orphanIssueKeys[$key])) {
                continue;
            }
            $orphanIssueKeys[$key] = true;
            $issues[] = [
                'severity' => 'warning',
                'code' => 'orphan_platform_data_source',
                'message' => 'platform_data_sources ' . (string)($row['platform'] ?? '') . ' references missing hotel_id ' . (string)($row['missing_hotel_id'] ?? ''),
            ];
        }
    }

    $activeHotelIds = [];
    foreach ($hotels as $hotel) {
        if ((int)($hotel['status'] ?? 0) === 1) {
            $activeHotelIds[(int)($hotel['id'] ?? 0)] = true;
        }
    }

    $summary = [
        'checked_at' => date('Y-m-d H:i:s'),
        'scope' => 'ota_channel_only',
        'platform_filter' => $platformFilter,
        'rollup_scope' => $rollupScope,
        'strategy_context_scope' => 'same_hotel_all_applicable_platforms',
        'orphan_source_scope' => $platformFilter === 'all' ? 'ctrip_meituan_only' : $platformFilter,
        'source_policy' => 'read_platform_data_sources_metadata_only',
        'sensitive_values_exposed' => false,
        'current_session_probe_performed' => false,
        'current_session_policy' => 'historical_login_metadata_is_not_current_session_proof',
        'hotel_count' => count($hotels),
        'active_hotels' => count($activeHotelIds),
        'inactive_hotels' => count($hotels) - count($activeHotelIds),
        'platform_rows' => count($platformRows),
        'ready_to_collect_platforms' => 0,
        'ready_for_session_probe_platforms' => count(array_filter($platformRows, static fn(array $row): bool => ($row['status'] ?? '') === 'ready_for_session_probe')),
        'ready_for_manual_login_platforms' => count(array_filter($platformRows, static fn(array $row): bool => ($row['status'] ?? '') === 'ready_for_manual_login')),
        'blocked_platforms' => count(array_filter($platformRows, static fn(array $row): bool => !in_array(($row['status'] ?? ''), ['ready_for_session_probe', 'ready_for_manual_login'], true))),
        'hotels_ready_to_collect' => 0,
        'hotels_ready_for_session_probe' => count(array_filter($hotelRollup, static fn(array $row): bool => (int)($row['hotel_ready_for_session_probe'] ?? 0) === 1)),
        'strategy_candidate_hotels' => count($strategyCandidates),
        'orphan_source_groups' => count($orphanSources),
    ];

    $result = [
        'summary' => $summary,
        'status_counts' => array_map(
            static fn(string $status, int $count): array => ['status' => $status, 'count' => $count],
            array_keys($statusCounts),
            array_values($statusCounts)
        ),
        'hotel_rollup' => $hotelRollup,
        'platform_eligibility' => $platformRows,
        'strategy_candidates' => $strategyCandidates,
        'orphan_sources' => $orphanSources,
        'issues' => $issues,
        'manual_login_policy' => [
            'profile_directories_touched' => false,
            'cookies_or_local_storage_cleared' => false,
            'current_session_probe_performed' => false,
            'historical_login_metadata_is_current_session_proof' => false,
            'manual_login_before_collection' => 'Confirm no running task for the same hotel/platform, then use account-owner local browser authorization.',
        ],
    ];

    if ((string)$options['format'] === 'json') {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        echo '# Hotel OTA Login Eligibility' . PHP_EOL . PHP_EOL;
        echo '- checked_at: `' . $summary['checked_at'] . '`' . PHP_EOL;
        echo '- scope: `ota_channel_only`; do not promote OTA readiness to whole-hotel readiness.' . PHP_EOL;
        echo '- source_policy: `read_platform_data_sources_metadata_only`' . PHP_EOL;
        echo '- sensitive_values_exposed: `false`' . PHP_EOL . PHP_EOL;

        echo '## Summary' . PHP_EOL . PHP_EOL;
        echo ota_inventory_markdown_table(['metric', 'value'], array_map(
            static fn(string $key, mixed $value): array => ['metric' => $key, 'value' => is_bool($value) ? ($value ? 'true' : 'false') : $value],
            array_keys($summary),
            array_values($summary)
        )) . PHP_EOL . PHP_EOL;

        echo '## Status Counts' . PHP_EOL . PHP_EOL;
        echo ota_inventory_markdown_table(
            ['status', 'count'],
            array_map(
                static fn(string $status, int $count): array => ['status' => $status, 'count' => $count],
                array_keys($statusCounts),
                array_values($statusCounts)
            )
        ) . PHP_EOL . PHP_EOL;

        echo '## Strategy Candidates' . PHP_EOL . PHP_EOL;
        echo $strategyCandidates === []
            ? 'No OTA strategy adjustment candidates found. Missing sources should be treated as account-binding blockers unless business scope says otherwise.' . PHP_EOL . PHP_EOL
            : ota_inventory_markdown_table(
                ['hotel_id', 'hotel_name', 'current_strategy', 'candidate_strategy', 'available_platform', 'blocked_platform', 'reason', 'confirmation_required'],
                array_slice($strategyCandidates, 0, $limit)
            ) . PHP_EOL . PHP_EOL;

        echo '## Hotel Rollup' . PHP_EOL . PHP_EOL;
        echo ota_inventory_markdown_table(
            ['hotel_id', 'hotel_name', 'hotel_status', 'hotel_lifecycle_state', 'inactive_hotel_blocks_ota_flow', 'downstream_setup_suppressed', 'ota_channel_strategy', 'rollup_scope', 'applicable_platform_count', 'ready_to_collect_platform_count', 'ready_for_session_probe_platform_count', 'ready_for_manual_login_platform_count', 'hotel_ready_to_collect', 'hotel_ready_for_session_probe', 'strategy_candidate'],
            array_slice($hotelRollup, 0, $limit)
        ) . PHP_EOL . PHP_EOL;

        echo '## Platform Eligibility' . PHP_EOL . PHP_EOL;
        echo ota_inventory_markdown_table(
            ['hotel_id', 'hotel_name', 'platform', 'hotel_lifecycle_state', 'inactive_hotel_blocks_ota_flow', 'downstream_setup_suppressed', 'fetch_user_count', 'permission_user_count', 'fetch_permission_user_count', 'active_fetch_permission_user_count', 'permission_blocker_reason', 'enabled_source_count', 'ready_source_count', 'profile_verified_count', 'historical_profile_verified_count', 'login_evidence_scope', 'current_session_probe_performed', 'current_session_verified', 'current_session_status', 'task_state', 'running_task_count', 'stale_running_task_count', 'missing_task_id_count', 'task_id_evidence_complete', 'blocking_task_ids', 'oldest_stale_task_at', 'status', 'primary_blocker', 'strategy_adjustment_candidate', 'strategy_adjustment_candidate_value', 'blockers', 'next_action', 'last_login_verified_at'],
            array_slice($platformRows, 0, $limit)
        ) . PHP_EOL . PHP_EOL;

        echo '## Orphan Sources' . PHP_EOL . PHP_EOL;
        echo $orphanSources === []
            ? 'No orphan platform_data_sources rows found.' . PHP_EOL . PHP_EOL
            : ota_inventory_markdown_table(['missing_hotel_id', 'platform', 'enabled', 'status', 'last_sync_status', 'rows_count', 'flow_included', 'next_action'], $orphanSources) . PHP_EOL . PHP_EOL;

        echo '## Issues' . PHP_EOL . PHP_EOL;
        echo $issues === []
            ? 'No schema or reference issues found.' . PHP_EOL
            : ota_inventory_markdown_table(['severity', 'code', 'message'], $issues) . PHP_EOL;
    }

    $hasError = array_reduce($issues, static fn(bool $carry, array $issue): bool => $carry || ($issue['severity'] ?? '') === 'error', false);
    $hasBlockedActivePlatform = array_reduce(
        $platformRows,
        static fn(bool $carry, array $row): bool => $carry
            || ((int)($row['hotel_status'] ?? 0) === 1
                && !in_array((string)($row['status'] ?? ''), ['ready_for_session_probe', 'ready_for_manual_login'], true)),
        false
    );
    $hasInvalidSpecificRequest = ($hotelIdFilter !== '' && $hotels === []) || $hasNotApplicableSpecificRequest;
    exit(($hasInvalidSpecificRequest || ((bool)$options['strict'] && ($hasError || $hasBlockedActivePlatform))) ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'hotel OTA login eligibility check failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
