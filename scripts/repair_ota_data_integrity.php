<?php
declare(strict_types=1);

use app\model\SystemConfig;
use think\App;
use think\facade\Db;

require __DIR__ . '/../vendor/autoload.php';

$app = new App();
$app->initialize();

$execute = in_array('--execute', $argv, true);
$mode = $execute ? 'execute' : 'dry_run';
$now = date('Y-m-d H:i:s');

/** @return array<string,bool> */
function integrity_table_columns(string $table): array
{
    if (preg_match('/^[A-Za-z0-9_]+$/D', $table) !== 1) {
        throw new InvalidArgumentException('Invalid table identifier.');
    }
    try {
        $rows = Db::query('SHOW COLUMNS FROM `' . $table . '`');
    } catch (Throwable) {
        return [];
    }
    return array_fill_keys(array_map(static fn(array $row): string => (string)$row['Field'], $rows), true);
}

/** @return array<string,mixed> */
function integrity_decode_json(mixed $value): array
{
    if (is_array($value)) {
        return $value;
    }
    $decoded = json_decode((string)($value ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

/** @return array<int,string> */
function integrity_platform_id_fields(string $platform): array
{
    return $platform === 'meituan'
        ? ['platform_hotel_id', 'meituan_hotel_id', 'poi_id', 'poiId', 'store_id', 'storeId', 'hotel_id']
        : ['platform_hotel_id', 'ctrip_hotel_id', 'ota_hotel_id', 'master_hotel_id', 'masterHotelId', 'hotel_id'];
}

function integrity_platform_id_from_config(array $config, string $platform, int $systemHotelId): string
{
    foreach (integrity_platform_id_fields($platform) as $field) {
        if (!is_scalar($config[$field] ?? null)) {
            continue;
        }
        $value = trim((string)$config[$field]);
        if ($value !== '' && $value !== '-1' && $value !== (string)$systemHotelId) {
            return $value;
        }
    }
    return '';
}

function integrity_config_timestamp(array $config): int
{
    foreach (['rotated_at', 'updated_at', 'update_time', 'created_at', 'create_time'] as $field) {
        $timestamp = strtotime(trim((string)($config[$field] ?? '')));
        if ($timestamp !== false) {
            return $timestamp;
        }
    }
    return 0;
}

function integrity_config_is_current(array $config): bool
{
    if (trim((string)($config['deleted_at'] ?? '')) !== '') {
        return false;
    }
    return !in_array(
        strtolower(trim((string)($config['config_status'] ?? 'active'))),
        ['deleted', 'history', 'superseded', 'archived'],
        true
    );
}

function integrity_row_timestamp(array $row): int
{
    foreach (['snapshot_time', 'create_time', 'update_time'] as $field) {
        $timestamp = strtotime(trim((string)($row[$field] ?? '')));
        if ($timestamp !== false) {
            return $timestamp;
        }
    }
    return 0;
}

function integrity_evidence_score(array $row): int
{
    $score = 0;
    foreach (['data_source_id', 'sync_task_id', 'source_trace_id', 'snapshot_time'] as $field) {
        if (trim((string)($row[$field] ?? '')) !== '' && (string)($row[$field] ?? '') !== '0') {
            $score++;
        }
    }
    return $score;
}

/** @param array<int,string> $codes */
function integrity_merge_flags(mixed $rawFlags, array $codes): string
{
    $flags = integrity_decode_json($rawFlags);
    $seen = [];
    foreach ($flags as $flag) {
        if (is_array($flag) && is_scalar($flag['code'] ?? null)) {
            $seen[trim((string)$flag['code'])] = true;
        } elseif (is_string($flag)) {
            $seen[trim($flag)] = true;
        }
    }
    foreach (array_values(array_unique(array_filter(array_map('strval', $codes)))) as $code) {
        if (isset($seen[$code])) {
            continue;
        }
        $flags[] = [
            'level' => 'warning',
            'field' => 'ota_identity_or_evidence',
            'code' => $code,
            'message' => $code,
        ];
        $seen[$code] = true;
    }
    return json_encode($flags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function integrity_competition_batch_key(array $row): string
{
    $anchor = '';
    if ((int)($row['sync_task_id'] ?? 0) > 0) {
        $anchor = 'task:' . (int)$row['sync_task_id'];
    } elseif (trim((string)($row['source_trace_id'] ?? '')) !== '') {
        $anchor = 'trace:' . trim((string)$row['source_trace_id']);
    } elseif (trim((string)($row['snapshot_time'] ?? '')) !== '') {
        $anchor = 'snapshot:' . trim((string)$row['snapshot_time']);
    } else {
        $anchor = 'created:' . trim((string)($row['create_time'] ?? ''));
    }
    return (int)($row['system_hotel_id'] ?? 0) . '|' . (string)($row['data_date'] ?? '') . '|' . $anchor;
}

function integrity_unbound_batch_key(array $row): string
{
    $anchor = '';
    if ((int)($row['sync_task_id'] ?? 0) > 0) {
        $anchor = 'task:' . (int)$row['sync_task_id'];
    } elseif (trim((string)($row['source_trace_id'] ?? '')) !== '') {
        $anchor = 'trace:' . trim((string)$row['source_trace_id']);
    } elseif (trim((string)($row['snapshot_time'] ?? '')) !== '') {
        $anchor = 'snapshot:' . trim((string)$row['snapshot_time']);
    } elseif (trim((string)($row['create_time'] ?? '')) !== '') {
        $anchor = 'created:' . trim((string)$row['create_time']);
    } else {
        $anchor = 'row:' . (int)($row['id'] ?? 0);
    }
    return strtolower(trim((string)($row['source'] ?? $row['platform'] ?? '')))
        . '|' . trim((string)($row['data_type'] ?? ''))
        . '|' . trim((string)($row['data_date'] ?? ''))
        . '|' . $anchor;
}

$allHotels = Db::name('hotels')->field('id,tenant_id,name,status')->select()->toArray();
$hotelMap = [];
$activeHotelMap = [];
foreach ($allHotels as $hotel) {
    $hotelId = (int)$hotel['id'];
    $hotelMap[$hotelId] = $hotel;
    if ((int)($hotel['status'] ?? 1) === 1) {
        $activeHotelMap[$hotelId] = $hotel;
    }
}
if ($hotelMap === []) {
    throw new RuntimeException('No hotel rows found; repair aborted.');
}
$allHotelIds = array_keys($hotelMap);
$activeHotelIds = array_keys($activeHotelMap);
$allHotelIdSql = implode(',', array_map('intval', $allHotelIds));

$otaRelationTables = [
    'ota_ctrip_review_order_matches',
    'ota_meituan_review_order_matches',
    'platform_data_raw_records',
    'platform_data_sync_logs',
    'online_daily_data',
    'ota_ctrip_capture_gaps',
    'ota_ctrip_capture_runs',
    'ota_ctrip_entity_snapshots',
    'ota_ctrip_im_sessions',
    'ota_ctrip_metric_facts',
    'ota_ctrip_orders',
    'ota_ctrip_reviews',
    'ota_meituan_orders',
    'ota_meituan_reviews',
    'ota_profile_bindings',
    'ota_credentials',
    'platform_data_sync_tasks',
    'platform_data_sources',
];
$orphanPlans = [];
foreach ($otaRelationTables as $table) {
    $columns = integrity_table_columns($table);
    if (!isset($columns['system_hotel_id'])) {
        continue;
    }
    $filter = '`system_hotel_id` IS NULL OR `system_hotel_id` NOT IN (' . $allHotelIdSql . ')';
    if ($table === 'online_daily_data') {
        $filter = '(' . $filter . ") AND (LOWER(COALESCE(`source`,'')) IN ('ctrip','meituan','qunar') OR LOWER(COALESCE(`platform`,'')) IN ('ctrip','meituan','qunar'))";
    }
    $count = (int)(Db::query('SELECT COUNT(*) AS c FROM `' . $table . '` WHERE ' . $filter)[0]['c'] ?? 0);
    if ($count > 0) {
        $orphanPlans[$table] = ['filter' => $filter, 'count' => $count];
    }
}

$sourceRows = Db::name('platform_data_sources')
    ->whereIn('system_hotel_id', $activeHotelIds)
    ->whereIn('platform', ['ctrip', 'meituan'])
    ->order('id', 'desc')
    ->select()
    ->toArray();

// Canonical IDs are accepted only when one current hotel owns the ID. Manual
// competition-circle bindings take precedence over generic browser sources.
$canonicalCandidates = [];
$sourceConfigById = [];
foreach ($sourceRows as $source) {
    $sourceId = (int)$source['id'];
    $hotelId = (int)$source['system_hotel_id'];
    $platform = strtolower(trim((string)$source['platform']));
    $config = integrity_decode_json($source['config_json'] ?? '');
    $sourceConfigById[$sourceId] = $config;
    $platformHotelId = integrity_platform_id_from_config($config, $platform, $hotelId);
    if ($platformHotelId === '') {
        continue;
    }
    $priority = 0;
    if ((string)($source['data_type'] ?? '') === 'competitor') {
        $priority += 100;
    }
    if ((string)($source['ingestion_method'] ?? '') === 'manual_cookie_api') {
        $priority += 50;
    }
    if ((int)($source['enabled'] ?? 0) === 1) {
        $priority += 10;
    }
    $candidate = [
        'hotel_id' => $hotelId,
        'platform_hotel_id' => $platformHotelId,
        'priority' => $priority,
        'source_id' => $sourceId,
    ];
    $current = $canonicalCandidates[$platform][$hotelId] ?? null;
    if ($current === null || $candidate['priority'] > $current['priority']
        || ($candidate['priority'] === $current['priority'] && $candidate['source_id'] > $current['source_id'])) {
        $canonicalCandidates[$platform][$hotelId] = $candidate;
    }
}

$latestCompetitionSelf = Db::name('online_daily_data')
    ->where('source', 'ctrip')
    ->where('data_type', 'competitor')
    ->where('dimension', 'competition_circle_hotel')
    ->where('compare_type', 'self')
    ->whereIn('system_hotel_id', $activeHotelIds)
    ->where('validation_status', '<>', 'abnormal')
    ->field('id,system_hotel_id,hotel_id,data_date,snapshot_time,create_time')
    ->order('data_date', 'desc')
    ->order('snapshot_time', 'desc')
    ->order('create_time', 'desc')
    ->order('id', 'desc')
    ->select()
    ->toArray();
$seenLatestCompetitionHotel = [];
foreach ($latestCompetitionSelf as $row) {
    $hotelId = (int)($row['system_hotel_id'] ?? 0);
    $platformHotelId = trim((string)($row['hotel_id'] ?? ''));
    if ($hotelId <= 0 || isset($seenLatestCompetitionHotel[$hotelId])
        || $platformHotelId === '' || $platformHotelId === '-1' || $platformHotelId === (string)$hotelId) {
        continue;
    }
    $seenLatestCompetitionHotel[$hotelId] = true;
    $candidate = [
        'hotel_id' => $hotelId,
        'platform_hotel_id' => $platformHotelId,
        'priority' => 80,
        'source_id' => 0,
    ];
    $current = $canonicalCandidates['ctrip'][$hotelId] ?? null;
    if ($current === null || $candidate['priority'] > $current['priority']) {
        $canonicalCandidates['ctrip'][$hotelId] = $candidate;
    }
}

$canonicalByHotel = [];
$canonicalOwner = [];
foreach ($canonicalCandidates as $platform => $byHotel) {
    $owners = [];
    foreach ($byHotel as $candidate) {
        $owners[(string)$candidate['platform_hotel_id']][] = (int)$candidate['hotel_id'];
    }
    foreach ($byHotel as $hotelId => $candidate) {
        $platformHotelId = (string)$candidate['platform_hotel_id'];
        $uniqueOwners = array_values(array_unique($owners[$platformHotelId] ?? []));
        if (count($uniqueOwners) !== 1) {
            continue;
        }
        $canonicalByHotel[$platform][(int)$hotelId] = $platformHotelId;
        $canonicalOwner[$platform][$platformHotelId] = (int)$hotelId;
    }
}

// The operator-confirmed binding must remain explicit and unique.
if (isset($activeHotelMap[121]) && trim((string)$activeHotelMap[121]['name']) === '西安天诚') {
    $canonicalByHotel['ctrip'][121] = '6866634';
    $canonicalOwner['ctrip']['6866634'] = 121;
}

$currentSourceOwner = [];
foreach ($sourceRows as $source) {
    $currentSourceOwner[(int)$source['id']] = (int)$source['system_hotel_id'];
}
$currentTaskOwner = [];
$currentTaskRows = Db::name('platform_data_sync_tasks')
    ->whereIn('system_hotel_id', $activeHotelIds)
    ->field('id,system_hotel_id')
    ->select()
    ->toArray();
foreach ($currentTaskRows as $task) {
    $currentTaskOwner[(int)$task['id']] = (int)$task['system_hotel_id'];
}

// Unbound historical rows are not deleted blindly. Rebind only when the
// existing source/task scope or an explicit self platform ID provides one
// unique current owner; everything else remains orphan cleanup material.
$unboundRows = Db::name('online_daily_data')
    ->whereNull('system_hotel_id')
    ->whereRaw("(LOWER(COALESCE(`source`,'')) IN ('ctrip','meituan','qunar') OR LOWER(COALESCE(`platform`,'')) IN ('ctrip','meituan','qunar'))")
    ->field('id,tenant_id,system_hotel_id,hotel_id,source,platform,data_type,data_date,compare_type,validation_status,validation_flags,data_source_id,sync_task_id,source_trace_id,snapshot_time,create_time,update_time')
    ->select()
    ->toArray();
$unboundGroups = [];
foreach ($unboundRows as $row) {
    $key = integrity_unbound_batch_key($row);
    $unboundGroups[$key]['rows'][] = $row;
    $sourceOwner = (int)($currentSourceOwner[(int)($row['data_source_id'] ?? 0)] ?? 0);
    $taskOwner = (int)($currentTaskOwner[(int)($row['sync_task_id'] ?? 0)] ?? 0);
    if ($sourceOwner > 0) {
        $unboundGroups[$key]['owners'][$sourceOwner] = true;
        $unboundGroups[$key]['strong_owner'] = true;
    }
    if ($taskOwner > 0) {
        $unboundGroups[$key]['owners'][$taskOwner] = true;
        $unboundGroups[$key]['strong_owner'] = true;
    }
    if (strtolower(trim((string)($row['compare_type'] ?? ''))) === 'self') {
        $platform = strtolower(trim((string)($row['source'] ?? $row['platform'] ?? '')));
        if ($platform === 'ctrip' || $platform === 'meituan') {
            $owner = (int)($canonicalOwner[$platform][trim((string)($row['hotel_id'] ?? ''))] ?? 0);
            if ($owner > 0) {
                $unboundGroups[$key]['owners'][$owner] = true;
            }
        }
    }
}
$orphanRebindPlans = [];
$orphanRebindMethodCounts = ['source_or_task' => 0, 'platform_id' => 0];
foreach ($unboundGroups as $group) {
    $owners = array_map('intval', array_keys($group['owners'] ?? []));
    if (count($owners) !== 1 || !isset($activeHotelMap[$owners[0]])) {
        continue;
    }
    $ownerId = $owners[0];
    $strong = (bool)($group['strong_owner'] ?? false);
    $code = $strong
        ? 'historical_owner_inferred_from_source_or_task'
        : 'historical_owner_inferred_from_platform_id';
    foreach ($group['rows'] as $row) {
        $payload = [
            'tenant_id' => isset($activeHotelMap[$ownerId]['tenant_id']) ? (int)$activeHotelMap[$ownerId]['tenant_id'] : null,
            'system_hotel_id' => $ownerId,
            'validation_flags' => integrity_merge_flags($row['validation_flags'] ?? '', [$code]),
            'update_time' => $row['update_time'] ?? null,
        ];
        if (!$strong && strtolower(trim((string)($row['validation_status'] ?? ''))) !== 'abnormal') {
            $payload['validation_status'] = 'unverified';
        }
        $orphanRebindPlans[(int)$row['id']] = $payload;
        $orphanRebindMethodCounts[$strong ? 'source_or_task' : 'platform_id']++;
    }
}
if (isset($orphanPlans['online_daily_data'])) {
    $orphanPlans['online_daily_data']['count'] = max(
        0,
        (int)$orphanPlans['online_daily_data']['count'] - count($orphanRebindPlans)
    );
    if ($orphanPlans['online_daily_data']['count'] === 0) {
        unset($orphanPlans['online_daily_data']);
    }
}

$sourceRepairs = [];
foreach ($sourceRows as $source) {
    $sourceId = (int)$source['id'];
    $hotelId = (int)$source['system_hotel_id'];
    $platform = strtolower((string)$source['platform']);
    $expectedId = (string)($canonicalByHotel[$platform][$hotelId] ?? '');
    if ($expectedId === '' || (string)($source['ingestion_method'] ?? '') === 'historical_backfill') {
        continue;
    }
    $config = $sourceConfigById[$sourceId] ?? [];
    $observedIds = [];
    foreach (integrity_platform_id_fields($platform) as $field) {
        if (is_scalar($config[$field] ?? null) && trim((string)$config[$field]) !== '') {
            $observedIds[] = trim((string)$config[$field]);
        }
    }
    $observedIds = array_values(array_unique($observedIds));
    $hasObservedMismatch = false;
    $needsRepair = !isset($config['platform_hotel_id']) || trim((string)$config['platform_hotel_id']) !== $expectedId;
    foreach ($observedIds as $observedId) {
        if ($observedId !== $expectedId) {
            $needsRepair = true;
            $hasObservedMismatch = true;
        }
    }
    if (!$needsRepair) {
        continue;
    }
    foreach (integrity_platform_id_fields($platform) as $field) {
        if (array_key_exists($field, $config)) {
            $config[$field] = $expectedId;
        }
    }
    $config['platform_hotel_id'] = $expectedId;
    $config['identity_repaired_at'] = $now;
    $config['recollection_required'] = $hasObservedMismatch;
    $sourceRepairs[$sourceId] = [
        'source_id' => $sourceId,
        'system_hotel_id' => $hotelId,
        'hotel_name' => (string)($activeHotelMap[$hotelId]['name'] ?? ''),
        'platform' => $platform,
        'old_platform_ids' => $observedIds,
        'platform_hotel_id' => $expectedId,
        'requires_recollection' => $hasObservedMismatch,
        'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ];
}

$competitionRows = Db::name('online_daily_data')
    ->where('source', 'ctrip')
    ->where('data_type', 'competitor')
    ->where('dimension', 'competition_circle_hotel')
    ->whereIn('system_hotel_id', $activeHotelIds)
    ->field('id,system_hotel_id,hotel_id,hotel_name,data_date,compare_type,validation_status,validation_flags,data_source_id,sync_task_id,source_trace_id,snapshot_time,create_time,update_time')
    ->order('id', 'asc')
    ->select()
    ->toArray();

$competitionRowsByBatch = [];
$invalidCompetitionBatchKeys = [];
foreach ($competitionRows as $row) {
    $batchKey = integrity_competition_batch_key($row);
    $competitionRowsByBatch[$batchKey][] = $row;
    if (strtolower(trim((string)($row['compare_type'] ?? ''))) !== 'self') {
        continue;
    }
    $systemHotelId = (int)$row['system_hotel_id'];
    $platformHotelId = trim((string)($row['hotel_id'] ?? ''));
    $ownerId = (int)($canonicalOwner['ctrip'][$platformHotelId] ?? 0);
    if ($ownerId > 0 && $ownerId !== $systemHotelId) {
        $invalidCompetitionBatchKeys[$batchKey] = [
            'system_hotel_id' => $systemHotelId,
            'returned_self_hotel_id' => $platformHotelId,
            'actual_owner_system_hotel_id' => $ownerId,
            'data_date' => (string)$row['data_date'],
        ];
    }
}
$invalidCompetitionRowIds = [];
foreach (array_keys($invalidCompetitionBatchKeys) as $batchKey) {
    foreach ($competitionRowsByBatch[$batchKey] ?? [] as $row) {
        $invalidCompetitionRowIds[(int)$row['id']] = true;
    }
}

$sourceIdSet = [];
foreach (Db::name('platform_data_sources')->column('id') as $id) {
    $sourceIdSet[(int)$id] = true;
}
$taskIdSet = [];
foreach (Db::name('platform_data_sync_tasks')->column('id') as $id) {
    $taskIdSet[(int)$id] = true;
}

$rowById = [];
$rowReasonCodes = [];
$rowRoleUpdates = [];
$rowNameUpdates = [];
foreach ($competitionRows as $row) {
    $rowId = (int)$row['id'];
    if (isset($invalidCompetitionRowIds[$rowId])) {
        continue;
    }
    $rowById[$rowId] = $row;
    $systemHotelId = (int)$row['system_hotel_id'];
    $expectedId = (string)($canonicalByHotel['ctrip'][$systemHotelId] ?? '');
    if ($expectedId !== '') {
        $platformHotelId = trim((string)($row['hotel_id'] ?? ''));
        $expectedRole = $platformHotelId === $expectedId
            ? 'self'
            : ($platformHotelId === '-1' ? 'competitor_avg' : 'competitor');
        if ($expectedRole !== (string)($row['compare_type'] ?? '')) {
            $rowRoleUpdates[$rowId] = $expectedRole;
            $rowReasonCodes[$rowId][] = 'competition_role_corrected_by_platform_id';
        }
        if ($expectedRole === 'self') {
            $name = trim((string)($row['hotel_name'] ?? ''));
            if ($name === '' || in_array($name, ['我的酒店', '本店', 'My Hotel'], true)) {
                $rowNameUpdates[$rowId] = (string)($activeHotelMap[$systemHotelId]['name'] ?? $name);
            }
        }
    }

    $dataSourceId = (int)($row['data_source_id'] ?? 0);
    $syncTaskId = (int)($row['sync_task_id'] ?? 0);
    if ($dataSourceId <= 0) {
        $rowReasonCodes[$rowId][] = 'evidence_missing:data_source_id';
    } elseif (!isset($sourceIdSet[$dataSourceId])) {
        $rowReasonCodes[$rowId][] = 'evidence_dangling:data_source_id';
    }
    if ($syncTaskId <= 0) {
        $rowReasonCodes[$rowId][] = 'evidence_missing:sync_task_id';
    } elseif (!isset($taskIdSet[$syncTaskId])) {
        $rowReasonCodes[$rowId][] = 'evidence_dangling:sync_task_id';
    }
    if (trim((string)($row['source_trace_id'] ?? '')) === '') {
        $rowReasonCodes[$rowId][] = 'evidence_missing:source_trace_id';
    }
    if (trim((string)($row['snapshot_time'] ?? '')) === '') {
        $rowReasonCodes[$rowId][] = 'evidence_missing:snapshot_time';
    }
}

$scopedIdentityRows = Db::name('online_daily_data')
    ->whereIn('system_hotel_id', $activeHotelIds)
    ->whereRaw("COALESCE(`compare_type`,'') IN ('','self','competitor','competitor_avg')")
    ->whereRaw("(LOWER(COALESCE(`source`,'')) IN ('ctrip','meituan') OR LOWER(COALESCE(`platform`,'')) IN ('ctrip','meituan'))")
    ->field('id,system_hotel_id,hotel_id,hotel_name,data_date,source,platform,data_type,compare_type,validation_status,validation_flags,data_source_id,sync_task_id,source_trace_id,snapshot_time,create_time,update_time')
    ->select()
    ->toArray();
$identityRoleTransitionCounts = [];
foreach ($scopedIdentityRows as $row) {
    $rowId = (int)$row['id'];
    if (isset($invalidCompetitionRowIds[$rowId])) {
        continue;
    }
    $platform = strtolower(trim((string)($row['source'] ?? '')));
    if (!in_array($platform, ['ctrip', 'meituan'], true)) {
        $platform = strtolower(trim((string)($row['platform'] ?? '')));
    }
    $systemHotelId = (int)$row['system_hotel_id'];
    $expectedId = (string)($canonicalByHotel[$platform][$systemHotelId] ?? '');
    if ($expectedId === '') {
        continue;
    }
    $platformHotelId = trim((string)($row['hotel_id'] ?? ''));
    if ($platformHotelId === '') {
        continue;
    }
    if ($platformHotelId === $expectedId) {
        $expectedRole = 'self';
    } elseif ($platformHotelId === '-1') {
        $expectedRole = 'competitor_avg';
    } elseif ($platformHotelId === (string)$systemHotelId) {
        $expectedRole = 'unverified';
    } else {
        $expectedRole = 'competitor';
    }
    $currentRole = (string)($row['compare_type'] ?? '');
    if ($expectedRole === $currentRole) {
        continue;
    }
    $rowById[$rowId] = array_merge($rowById[$rowId] ?? [], $row);
    $rowRoleUpdates[$rowId] = $expectedRole;
    $rowReasonCodes[$rowId][] = $expectedRole === 'unverified'
        ? 'platform_id_equals_system_hotel_id_requires_recollection'
        : 'ota_role_corrected_by_platform_id';
    $transition = ($currentRole !== '' ? $currentRole : '(empty)') . '->' . $expectedRole;
    $identityRoleTransitionCounts[$transition] = (int)($identityRoleTransitionCounts[$transition] ?? 0) + 1;
}

// A repaired source remains queryable, but its already-saved rows are not
// promoted as verified truth until a fresh collection uses the corrected ID.
if ($sourceRepairs !== []) {
    $recollectionSourceIds = array_keys(array_filter(
        $sourceRepairs,
        static fn(array $plan): bool => (bool)($plan['requires_recollection'] ?? false)
    ));
}
if (!empty($recollectionSourceIds)) {
    $affectedRows = Db::name('online_daily_data')
        ->whereIn('data_source_id', $recollectionSourceIds)
        ->field('id,validation_status,validation_flags,update_time')
        ->select()
        ->toArray();
    foreach ($affectedRows as $row) {
        $rowId = (int)$row['id'];
        if (isset($invalidCompetitionRowIds[$rowId])) {
            continue;
        }
        $rowById[$rowId] = array_merge($rowById[$rowId] ?? [], $row);
        $rowReasonCodes[$rowId][] = 'source_platform_identity_repaired_requires_recollection';
    }
}

// Competition rows are current values by hotel/date/platform hotel ID. Keep
// the newest evidence-rich row; raw task/evidence tables retain the history.
$naturalGroups = [];
foreach ($competitionRows as $row) {
    $rowId = (int)$row['id'];
    if (isset($invalidCompetitionRowIds[$rowId])) {
        continue;
    }
    $role = (string)($rowRoleUpdates[$rowId] ?? $row['compare_type'] ?? '');
    $hotelIdentity = trim((string)($row['hotel_id'] ?? ''));
    if ($hotelIdentity === '') {
        $hotelIdentity = 'name:' . mb_strtolower(trim((string)($row['hotel_name'] ?? '')));
    }
    $key = (int)$row['system_hotel_id'] . '|' . (string)$row['data_date'] . '|' . $hotelIdentity . '|' . $role;
    $naturalGroups[$key][] = $row;
}
$duplicateCompetitionRowIds = [];
foreach ($naturalGroups as $rows) {
    if (count($rows) < 2) {
        continue;
    }
    usort($rows, static function (array $left, array $right): int {
        $timeCompare = integrity_row_timestamp($right) <=> integrity_row_timestamp($left);
        if ($timeCompare !== 0) {
            return $timeCompare;
        }
        $evidenceCompare = integrity_evidence_score($right) <=> integrity_evidence_score($left);
        return $evidenceCompare !== 0 ? $evidenceCompare : ((int)$right['id'] <=> (int)$left['id']);
    });
    foreach (array_slice($rows, 1) as $duplicate) {
        $duplicateCompetitionRowIds[(int)$duplicate['id']] = true;
    }
}

$credentialRows = Db::name('ota_credentials')
    ->whereIn('system_hotel_id', $activeHotelIds)
    ->order('id', 'desc')
    ->select()
    ->toArray();
$referencedConfigIds = [];
foreach ($sourceRows as $source) {
    if ((int)($source['enabled'] ?? 0) !== 1) {
        continue;
    }
    $config = $sourceConfigById[(int)$source['id']] ?? [];
    $configId = trim((string)($config['config_id'] ?? ''));
    if ($configId !== '') {
        $key = (int)$source['system_hotel_id'] . '|' . strtolower((string)$source['platform']);
        $referencedConfigIds[$key][$configId] = true;
    }
}
$credentialGroups = [];
$projectedCredentialStatus = [];
$credentialRowByLocator = [];
foreach ($credentialRows as $row) {
    $key = (int)$row['tenant_id'] . '|' . (int)$row['system_hotel_id'] . '|' . strtolower((string)$row['platform']);
    $credentialGroups[$key][] = $row;
    $locator = (int)$row['system_hotel_id'] . '|' . strtolower((string)$row['platform']) . '|' . (string)$row['config_id'];
    $projectedCredentialStatus[$locator] = (string)$row['credential_status'];
    $credentialRowByLocator[$locator] = $row;
}
$credentialRevocationIds = [];
$selectedCredentialByHotelPlatform = [];
foreach ($credentialGroups as $rows) {
    $ready = array_values(array_filter($rows, static fn(array $row): bool => (string)$row['credential_status'] === 'ready'));
    if ($ready === []) {
        continue;
    }
    usort($ready, static function (array $left, array $right): int {
        $timeCompare = integrity_config_timestamp($right) <=> integrity_config_timestamp($left);
        return $timeCompare !== 0 ? $timeCompare : ((int)$right['id'] <=> (int)$left['id']);
    });
    $hotelId = (int)$ready[0]['system_hotel_id'];
    $platform = strtolower((string)$ready[0]['platform']);
    $referenceKey = $hotelId . '|' . $platform;
    $keep = $ready[0];
    foreach ($ready as $candidate) {
        if (isset($referencedConfigIds[$referenceKey][(string)$candidate['config_id']])) {
            $keep = $candidate;
            break;
        }
    }
    $selectedCredentialByHotelPlatform[$referenceKey] = (string)$keep['config_id'];
    foreach ($ready as $candidate) {
        if ((int)$candidate['id'] === (int)$keep['id']) {
            continue;
        }
        $credentialRevocationIds[(int)$candidate['id']] = true;
        $projectedCredentialStatus[$hotelId . '|' . $platform . '|' . (string)$candidate['config_id']] = 'revoked';
    }
}

$configListPlans = [];
$platformBindingRepairs = [];
$activeBindingByPlatformHotelId = [];
$activeBindingBySystemHotel = [];
foreach (['ctrip' => 'ctrip_config_list', 'meituan' => 'meituan_config_list'] as $platform => $configKey) {
    $storedRow = Db::name('system_configs')->where('config_key', $configKey)->find();
    if (!is_array($storedRow)) {
        continue;
    }
    $list = integrity_decode_json($storedRow['config_value'] ?? '');
    $knownConfigIds = array_fill_keys(array_map('strval', array_keys($list)), true);
    $groups = [];
    $originalCount = 0;
    foreach ($list as $storedKey => $config) {
        if (!is_array($config)) {
            continue;
        }
        $originalCount++;
        $hotelId = (int)($config['system_hotel_id'] ?? 0);
        if ($hotelId <= 0 && is_numeric($config['hotel_id'] ?? null) && isset($activeHotelMap[(int)$config['hotel_id']])) {
            $hotelId = (int)$config['hotel_id'];
        }
        if ($hotelId <= 0 || !isset($activeHotelMap[$hotelId])) {
            continue;
        }
        $configId = trim((string)($config['config_id'] ?? $config['id'] ?? $storedKey));
        if ($configId === '') {
            continue;
        }
        $config['id'] = $configId;
        $config['config_id'] = $configId;
        $config['hotel_id'] = (string)$hotelId;
        $config['system_hotel_id'] = $hotelId;
        if (integrity_config_is_current($config)) {
            $currentPlatformHotelId = integrity_platform_id_from_config($config, $platform, $hotelId);
            $canonicalPlatformHotelId = (string)($canonicalByHotel[$platform][$hotelId] ?? '');
            $canonicalIdOwner = $currentPlatformHotelId === ''
                ? null
                : ($canonicalOwner[$platform][$currentPlatformHotelId] ?? null);
            if ($canonicalPlatformHotelId !== '' && $currentPlatformHotelId !== $canonicalPlatformHotelId) {
                if ($currentPlatformHotelId !== '') {
                    $historyIdBase = substr($configId, 0, 55)
                        . '__history_repair_' . date('YmdHis') . '_'
                        . substr(hash('sha256', $configId . '|' . $currentPlatformHotelId), 0, 8);
                    $historyId = substr($historyIdBase, 0, 100);
                    for ($suffix = 2; isset($knownConfigIds[$historyId]); $suffix++) {
                        $historyId = substr($historyIdBase, 0, 96) . '_' . $suffix;
                    }
                    $knownConfigIds[$historyId] = true;
                    $history = $config;
                    $history['id'] = $historyId;
                    $history['config_id'] = $historyId;
                    $history['config_status'] = 'history';
                    $history['credential_status'] = 'revoked';
                    $history['has_cookies'] = false;
                    $history['history_of_config_id'] = $configId;
                    $history['superseded_at'] = $now;
                    unset($history['credential_ref'], $history['secret_mask'], $history['history_count']);
                    $groups[$hotelId][] = $history;
                }
                if ($platform === 'meituan') {
                    $config['poi_id'] = $canonicalPlatformHotelId;
                    $config['store_id'] = $canonicalPlatformHotelId;
                    $config['platform_hotel_id'] = $canonicalPlatformHotelId;
                } else {
                    $config['ctrip_hotel_id'] = $canonicalPlatformHotelId;
                    $config['ctripHotelId'] = $canonicalPlatformHotelId;
                    $config['ota_hotel_id'] = $canonicalPlatformHotelId;
                    $config['platform_hotel_id'] = $canonicalPlatformHotelId;
                }
                $platformBindingRepairs[] = [
                    'platform' => $platform,
                    'system_hotel_id' => $hotelId,
                    'hotel_name' => (string)($activeHotelMap[$hotelId]['name'] ?? ''),
                    'config_id' => $configId,
                    'old_platform_hotel_id' => $currentPlatformHotelId,
                    'platform_hotel_id' => $canonicalPlatformHotelId,
                    'action' => 'canonical_id_corrected',
                ];
            } elseif ($currentPlatformHotelId !== '' && $canonicalIdOwner !== null && (int)$canonicalIdOwner !== $hotelId) {
                $config['config_status'] = 'history';
                $config['credential_status'] = 'revoked';
                $config['has_cookies'] = false;
                $config['superseded_at'] = $now;
                $locator = $hotelId . '|' . $platform . '|' . $configId;
                $credentialRow = $credentialRowByLocator[$locator] ?? null;
                if (is_array($credentialRow) && (string)($credentialRow['credential_status'] ?? '') === 'ready') {
                    $credentialRevocationIds[(int)$credentialRow['id']] = true;
                    $projectedCredentialStatus[$locator] = 'revoked';
                }
                $platformBindingRepairs[] = [
                    'platform' => $platform,
                    'system_hotel_id' => $hotelId,
                    'hotel_name' => (string)($activeHotelMap[$hotelId]['name'] ?? ''),
                    'config_id' => $configId,
                    'old_platform_hotel_id' => $currentPlatformHotelId,
                    'platform_hotel_id' => '',
                    'canonical_owner_system_hotel_id' => (int)$canonicalIdOwner,
                    'action' => 'wrong_owner_marked_history',
                ];
            }
        }
        if (integrity_config_is_current($config)) {
            $platformHotelId = integrity_platform_id_from_config($config, $platform, $hotelId);
            if ($platformHotelId !== '') {
                $binding = [
                    'platform' => $platform,
                    'platform_hotel_id' => $platformHotelId,
                    'system_hotel_id' => $hotelId,
                    'hotel_name' => (string)($activeHotelMap[$hotelId]['name'] ?? ''),
                    'config_id' => $configId,
                ];
                $activeBindingByPlatformHotelId[$platform][$platformHotelId][] = $binding;
                $activeBindingBySystemHotel[$platform][$hotelId][] = $binding;
            }
        }
        $groups[$hotelId][] = $config;
    }
    $preserved = [];
    $currentCount = 0;
    foreach ($groups as $hotelId => $configs) {
        $preferredId = (string)($selectedCredentialByHotelPlatform[$hotelId . '|' . $platform] ?? '');
        $currentConfigs = array_values(array_filter($configs, 'integrity_config_is_current'));
        usort($currentConfigs, static function (array $left, array $right) use ($preferredId): int {
            $leftPreferred = $preferredId !== '' && (string)$left['config_id'] === $preferredId;
            $rightPreferred = $preferredId !== '' && (string)$right['config_id'] === $preferredId;
            if ($leftPreferred !== $rightPreferred) {
                return $rightPreferred <=> $leftPreferred;
            }
            $timeCompare = integrity_config_timestamp($right) <=> integrity_config_timestamp($left);
            return $timeCompare !== 0 ? $timeCompare : strcmp((string)$right['config_id'], (string)$left['config_id']);
        });
        $primaryId = $currentConfigs === [] ? '' : (string)$currentConfigs[0]['config_id'];
        if ($primaryId !== '') {
            $currentCount++;
        }
        $materialHistoryCount = max(0, count($configs) - 1);
        $hasExplicitLegacyHistoryCount = false;
        $legacyHiddenHistoryCount = 0;
        $reportedHistoryCount = 0;
        foreach ($configs as $item) {
            if (array_key_exists('legacy_history_count', $item)) {
                $hasExplicitLegacyHistoryCount = true;
                $legacyHiddenHistoryCount = max($legacyHiddenHistoryCount, max(0, (int)$item['legacy_history_count']));
            }
            $reportedHistoryCount = max($reportedHistoryCount, max(0, (int)($item['history_count'] ?? 0)));
        }
        if (!$hasExplicitLegacyHistoryCount) {
            $legacyHiddenHistoryCount = max(0, $reportedHistoryCount - $materialHistoryCount);
        }
        foreach ($configs as $config) {
            $configId = (string)$config['config_id'];
            $statusKey = $hotelId . '|' . $platform . '|' . $configId;
            if ($primaryId !== '' && hash_equals($primaryId, $configId)) {
                $config['config_status'] = 'active';
                $config['deleted_at'] = '';
                $config['credential_status'] = (string)($projectedCredentialStatus[$statusKey] ?? 'missing');
                $config['legacy_history_count'] = $legacyHiddenHistoryCount;
                $config['history_count'] = $legacyHiddenHistoryCount + $materialHistoryCount;
            } elseif (integrity_config_is_current($config)) {
                $config['config_status'] = 'history';
                $config['credential_status'] = 'revoked';
                $config['has_cookies'] = false;
                $config['superseded_at'] = $config['superseded_at'] ?? $now;
                unset($config['history_count'], $config['legacy_history_count']);
            } else {
                unset($config['history_count'], $config['legacy_history_count']);
            }
            $preserved[$configId] = $config;
        }
    }
    $json = json_encode($preserved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $configListPlans[$configKey] = [
        'row_id' => (int)$storedRow['id'],
        'config_value' => $json,
        'original_count' => $originalCount,
        'current_count' => $currentCount,
        'stored_count' => count($preserved),
        'removed_count' => max(0, $originalCount - count($preserved)),
        'changed' => $json !== (string)($storedRow['config_value'] ?? ''),
    ];
}

$activePlatformHotelBindingConflicts = [];
foreach ($activeBindingByPlatformHotelId as $platform => $byPlatformHotelId) {
    foreach ($byPlatformHotelId as $platformHotelId => $bindings) {
        $hotelIds = array_values(array_unique(array_map(
            static fn(array $binding): int => (int)$binding['system_hotel_id'],
            $bindings
        )));
        if (count($hotelIds) > 1) {
            $activePlatformHotelBindingConflicts[] = [
                'platform' => $platform,
                'platform_hotel_id' => $platformHotelId,
                'bindings' => $bindings,
            ];
        }
    }
}

$activeSystemHotelBindingConflicts = [];
foreach ($activeBindingBySystemHotel as $platform => $bySystemHotel) {
    foreach ($bySystemHotel as $hotelId => $bindings) {
        $platformHotelIds = array_values(array_unique(array_map(
            static fn(array $binding): string => (string)$binding['platform_hotel_id'],
            $bindings
        )));
        if (count($platformHotelIds) > 1) {
            $activeSystemHotelBindingConflicts[] = [
                'platform' => $platform,
                'system_hotel_id' => (int)$hotelId,
                'hotel_name' => (string)($activeHotelMap[(int)$hotelId]['name'] ?? ''),
                'bindings' => $bindings,
            ];
        }
    }
}

$deleteCompetitionIds = $invalidCompetitionRowIds + $duplicateCompetitionRowIds;
foreach (array_keys($deleteCompetitionIds) as $rowId) {
    unset($rowById[$rowId], $rowReasonCodes[$rowId], $rowRoleUpdates[$rowId], $rowNameUpdates[$rowId]);
}

$rowUpdatePlans = [];
foreach ($rowById as $rowId => $row) {
    $codes = array_values(array_unique($rowReasonCodes[$rowId] ?? []));
    $payload = [];
    if (isset($rowRoleUpdates[$rowId])) {
        $payload['compare_type'] = $rowRoleUpdates[$rowId];
    }
    if (isset($rowNameUpdates[$rowId])) {
        $payload['hotel_name'] = $rowNameUpdates[$rowId];
    }
    if ($codes !== []) {
        $mergedFlags = integrity_merge_flags($row['validation_flags'] ?? '', $codes);
        if ($mergedFlags !== (string)($row['validation_flags'] ?? '')) {
            $payload['validation_flags'] = $mergedFlags;
        }
        $currentStatus = strtolower(trim((string)($row['validation_status'] ?? '')));
        if ($currentStatus !== 'abnormal'
            && $currentStatus !== 'unverified'
            && array_filter($codes, static fn(string $code): bool => str_starts_with($code, 'evidence_') || str_contains($code, 'requires_recollection'))
        ) {
            $payload['validation_status'] = 'unverified';
        }
    }
    if ($payload === []) {
        continue;
    }
    if (array_key_exists('update_time', $row)) {
        $payload['update_time'] = $row['update_time'];
    }
    $rowUpdatePlans[(int)$rowId] = $payload;
}

$coverageDate = date('Y-m-d', strtotime('-1 day'));
$coverageRows = Db::query(
    "SELECT LOWER(COALESCE(NULLIF(source,''),platform)) platform_code, data_type, COUNT(*) row_count, COUNT(DISTINCT system_hotel_id) hotel_count "
    . "FROM online_daily_data WHERE system_hotel_id IN (" . implode(',', array_map('intval', $activeHotelIds)) . ") "
    . "AND data_date = ? AND (LOWER(COALESCE(source,'')) IN ('ctrip','meituan') OR LOWER(COALESCE(platform,'')) IN ('ctrip','meituan')) "
    . "GROUP BY platform_code,data_type ORDER BY platform_code,data_type",
    [$coverageDate]
);
$ctripCompetitionCovered = array_map('intval', Db::name('online_daily_data')
    ->where('source', 'ctrip')
    ->where('data_type', 'competitor')
    ->where('dimension', 'competition_circle_hotel')
    ->where('data_date', $coverageDate)
    ->whereIn('system_hotel_id', $activeHotelIds)
    ->whereNotIn('validation_status', ['abnormal'])
    ->distinct(true)
    ->column('system_hotel_id'));
$missingCompetitionHotels = [];
foreach ($activeHotelMap as $hotelId => $hotel) {
    if (!in_array($hotelId, $ctripCompetitionCovered, true)) {
        $missingCompetitionHotels[] = ['system_hotel_id' => $hotelId, 'hotel_name' => (string)$hotel['name']];
    }
}

$preview = [
    'mode' => $mode,
    'confirmed_identity' => [
        'system_hotel_id' => 121,
        'hotel_name' => (string)($activeHotelMap[121]['name'] ?? ''),
        'ctrip_hotel_id' => '6866634',
    ],
    'orphan_rows_by_table' => array_map(static fn(array $plan): int => (int)$plan['count'], $orphanPlans),
    'orphan_row_total' => array_sum(array_map(static fn(array $plan): int => (int)$plan['count'], $orphanPlans)),
    'unbound_rows_rebound' => count($orphanRebindPlans),
    'unbound_rebind_method_counts' => $orphanRebindMethodCounts,
    'source_platform_id_repairs' => array_values(array_map(static fn(array $plan): array => array_diff_key($plan, ['config_json' => true]), $sourceRepairs)),
    'source_platform_id_repair_count' => count($sourceRepairs),
    'invalid_cross_hotel_competition_batches' => array_values($invalidCompetitionBatchKeys),
    'invalid_cross_hotel_competition_batch_count' => count($invalidCompetitionBatchKeys),
    'invalid_cross_hotel_competition_row_count' => count($invalidCompetitionRowIds),
    'duplicate_competition_row_count' => count($duplicateCompetitionRowIds),
    'competition_row_update_count' => count($rowUpdatePlans),
    'identity_role_transition_counts' => $identityRoleTransitionCounts,
    'competition_update_reason_counts' => (function () use ($rowReasonCodes): array {
        $counts = [];
        foreach ($rowReasonCodes as $codes) {
            foreach (array_unique($codes) as $code) {
                $counts[$code] = (int)($counts[$code] ?? 0) + 1;
            }
        }
        ksort($counts);
        return $counts;
    })(),
    'credential_ready_versions_revoked' => count($credentialRevocationIds),
    'config_list_plans' => array_map(static fn(array $plan): array => array_diff_key($plan, ['config_value' => true]), $configListPlans),
    'platform_binding_repairs' => $platformBindingRepairs,
    'active_platform_hotel_binding_conflicts' => $activePlatformHotelBindingConflicts,
    'active_system_hotel_binding_conflicts' => $activeSystemHotelBindingConflicts,
    'coverage_date' => $coverageDate,
    'coverage_by_platform_and_type' => $coverageRows,
    'ctrip_competition_covered_hotel_count' => count($ctripCompetitionCovered),
    'ctrip_competition_missing_hotels' => $missingCompetitionHotels,
    'independent_evidence_tables' => [
        'kept' => true,
        'reason' => 'retain source/task/trace evidence for future verified captures; only orphan rows are removed',
    ],
];

if (!$execute) {
    echo json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
}

Db::transaction(function () use (
    $orphanPlans,
    $orphanRebindPlans,
    $sourceRepairs,
    $deleteCompetitionIds,
    $rowUpdatePlans,
    $credentialRevocationIds,
    $configListPlans,
    $now
): void {
    foreach ($orphanRebindPlans as $rowId => $payload) {
        Db::name('online_daily_data')->where('id', $rowId)->update($payload);
    }
    foreach ($orphanPlans as $table => $plan) {
        Db::execute('DELETE FROM `' . $table . '` WHERE ' . $plan['filter']);
    }

    foreach ($sourceRepairs as $sourceId => $plan) {
        Db::name('platform_data_sources')->where('id', $sourceId)->update([
            'config_json' => $plan['config_json'],
            'last_sync_status' => 'identity_repaired',
            'update_time' => $now,
        ]);
    }

    foreach (array_chunk(array_map('intval', array_keys($deleteCompetitionIds)), 500) as $ids) {
        if ($ids !== []) {
            Db::name('online_daily_data')->whereIn('id', $ids)->delete();
        }
    }
    foreach ($rowUpdatePlans as $rowId => $payload) {
        Db::name('online_daily_data')->where('id', $rowId)->update($payload);
    }

    foreach (array_chunk(array_map('intval', array_keys($credentialRevocationIds)), 500) as $ids) {
        if ($ids !== []) {
            Db::name('ota_credentials')->whereIn('id', $ids)->update([
                'credential_status' => 'revoked',
                'update_time' => $now,
            ]);
        }
    }

    foreach ($configListPlans as $plan) {
        if (!$plan['changed']) {
            continue;
        }
        Db::name('system_configs')->where('id', $plan['row_id'])->update([
            'config_value' => $plan['config_value'],
            'update_time' => $now,
        ]);
    }
});

SystemConfig::clearProtectedOtaCaches();
$preview['mode'] = 'executed';
echo json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
