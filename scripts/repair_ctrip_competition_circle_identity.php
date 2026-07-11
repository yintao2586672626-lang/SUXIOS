<?php
declare(strict_types=1);

use app\service\CtripCompetitionCirclePersistenceService;
use think\App;
use think\facade\Db;

require __DIR__ . '/../vendor/autoload.php';

$app = new App();
$app->initialize();

// Read-only by default. Pass --execute to apply; --dry-run is accepted for
// explicit operator intent and remains the default when --execute is absent.
$execute = in_array('--execute', $argv, true);
$dryRun = in_array('--dry-run', $argv, true) || !$execute;

/** @return array<string,mixed> */
function identity_repair_raw(array $row): array
{
    $raw = json_decode((string)($row['raw_data'] ?? ''), true);
    return is_array($raw) ? $raw : [];
}

function identity_repair_platform_hotel_id(array $row): string
{
    $raw = identity_repair_raw($row);
    foreach (['masterHotelId', 'masterhotelid', 'master_hotel_id', 'hotelId', 'hotel_id', 'HotelId', 'hotelID'] as $field) {
        if (!array_key_exists($field, $raw) || !is_scalar($raw[$field])) {
            continue;
        }
        $value = trim((string)$raw[$field]);
        if ($value !== '' && $value !== '-1') {
            return $value;
        }
    }
    $fallback = trim((string)($row['hotel_id'] ?? ''));
    return $fallback !== '-1' ? $fallback : '';
}

function identity_repair_has_explicit_self(array $row): bool
{
    $raw = identity_repair_raw($row);
    if ($raw === []) {
        return false;
    }
    $semantics = CtripCompetitionCirclePersistenceService::normalizeRowSemantics($raw);
    return ($semantics['compare_type'] ?? '') === 'self';
}

function identity_repair_snapshot_key(array $row): string
{
    $snapshot = trim((string)($row['snapshot_time'] ?? ''));
    if ($snapshot === '') {
        $snapshot = trim((string)($row['update_time'] ?? $row['create_time'] ?? ''));
    }
    return (string)($row['data_date'] ?? '')
        . '|' . $snapshot
        . '|' . trim((string)($row['create_time'] ?? ''));
}

/** @return array<int,string> */
function identity_repair_config_platform_ids(array $config, int $systemHotelId): array
{
    $ids = [];
    foreach (['platform_hotel_id', 'platformHotelId', 'ota_hotel_id', 'otaHotelId', 'ctrip_hotel_id', 'ctripHotelId', 'masterHotelId', 'master_hotel_id'] as $field) {
        if (!is_scalar($config[$field] ?? null)) {
            continue;
        }
        $value = trim((string)$config[$field]);
        if ($value !== '' && $value !== '-1' && $value !== (string)$systemHotelId) {
            $ids[$value] = true;
        }
    }
    return array_values(array_map('strval', array_keys($ids)));
}

function identity_repair_config_timestamp(array $config): int
{
    foreach (['updated_at', 'update_time', 'created_at', 'create_time'] as $field) {
        $timestamp = strtotime(trim((string)($config[$field] ?? '')));
        if ($timestamp !== false) {
            return (int)$timestamp;
        }
    }
    return 0;
}

/**
 * Same-store config history is retained, but ownership inference uses only the
 * newest ready version for that store.
 *
 * @param array<int,array<string,mixed>> $activeHotels
 * @return array<string,array<int,int>> platform hotel ID => system hotel IDs
 */
function identity_repair_current_ready_config_map(array $activeHotels): array
{
    $activeSet = array_fill_keys(array_map(static fn(array $hotel): int => (int)$hotel['id'], $activeHotels), true);
    $raw = Db::name('system_configs')->where('config_key', 'ctrip_config_list')->value('config_value');
    $configs = json_decode((string)($raw ?? ''), true);
    $latestByHotel = [];

    foreach (is_array($configs) ? $configs : [] as $storedKey => $config) {
        if (!is_array($config) || strtolower(trim((string)($config['credential_status'] ?? ''))) !== 'ready') {
            continue;
        }
        $systemHotelId = (int)($config['system_hotel_id'] ?? $config['hotel_id'] ?? 0);
        if ($systemHotelId <= 0 || !isset($activeSet[$systemHotelId])) {
            continue;
        }
        $ids = identity_repair_config_platform_ids($config, $systemHotelId);
        if (count($ids) !== 1) {
            continue;
        }
        $candidate = [
            'system_hotel_id' => $systemHotelId,
            'platform_hotel_id' => $ids[0],
            'timestamp' => identity_repair_config_timestamp($config),
            'config_id' => trim((string)($config['id'] ?? $config['config_id'] ?? $storedKey)),
        ];
        $current = $latestByHotel[$systemHotelId] ?? null;
        if (
            $current === null
            || $candidate['timestamp'] > $current['timestamp']
            || ($candidate['timestamp'] === $current['timestamp'] && strcmp($candidate['config_id'], $current['config_id']) > 0)
        ) {
            $latestByHotel[$systemHotelId] = $candidate;
        }
    }

    $map = [];
    foreach ($latestByHotel as $candidate) {
        $platformHotelId = (string)$candidate['platform_hotel_id'];
        $map[$platformHotelId][(int)$candidate['system_hotel_id']] = (int)$candidate['system_hotel_id'];
    }
    foreach ($map as $platformHotelId => $owners) {
        $map[$platformHotelId] = array_values($owners);
    }
    return $map;
}

/**
 * @param array<int,array<string,mixed>> $rows
 * @param array<int,array<string,mixed>> $activeHotels
 * @return array<string,array<int,int>> platform hotel ID => system hotel IDs
 */
function identity_repair_unique_active_self_history_map(array $rows, array $activeHotels): array
{
    $activeSet = array_fill_keys(array_map(static fn(array $hotel): int => (int)$hotel['id'], $activeHotels), true);
    $map = [];
    foreach ($rows as $row) {
        $systemHotelId = (int)($row['system_hotel_id'] ?? 0);
        if ($systemHotelId <= 0 || !isset($activeSet[$systemHotelId]) || !identity_repair_has_explicit_self($row)) {
            continue;
        }
        $platformHotelId = identity_repair_platform_hotel_id($row);
        if ($platformHotelId !== '') {
            $map[$platformHotelId][$systemHotelId] = $systemHotelId;
        }
    }
    foreach ($map as $platformHotelId => $owners) {
        $map[$platformHotelId] = array_values($owners);
    }
    return $map;
}

/**
 * @param array<int,string> $removeCodes
 * @param array<int,string> $addCodes
 */
function identity_repair_validation_flags(array $row, array $removeCodes, array $addCodes): string
{
    $removeSet = array_fill_keys($removeCodes, true);
    $flags = json_decode((string)($row['validation_flags'] ?? ''), true);
    $flags = is_array($flags) ? $flags : [];
    $result = [];
    $existing = [];
    foreach ($flags as $flag) {
        $code = is_array($flag) && is_scalar($flag['code'] ?? null) ? trim((string)$flag['code']) : '';
        if ($code !== '' && isset($removeSet[$code])) {
            continue;
        }
        if ($code !== '') {
            $existing[$code] = true;
        }
        $result[] = $flag;
    }
    foreach (array_values(array_unique($addCodes)) as $code) {
        if ($code === '' || isset($existing[$code])) {
            continue;
        }
        $result[] = [
            'level' => 'warning',
            'field' => 'system_hotel_id',
            'code' => $code,
            'message' => $code,
        ];
        $existing[$code] = true;
    }
    return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
}

/** @return array<string,bool> */
function identity_repair_flag_code_set(array $row): array
{
    $flags = json_decode((string)($row['validation_flags'] ?? ''), true);
    $codes = [];
    foreach (is_array($flags) ? $flags : [] as $flag) {
        $code = is_array($flag) && is_scalar($flag['code'] ?? null) ? trim((string)$flag['code']) : '';
        if ($code !== '') {
            $codes[$code] = true;
        }
    }
    return $codes;
}

$rows = Db::name('online_daily_data')
    ->where('source', 'ctrip')
    ->where('data_type', CtripCompetitionCirclePersistenceService::DATA_TYPE)
    ->where('dimension', CtripCompetitionCirclePersistenceService::DIMENSION)
    ->field('id,tenant_id,system_hotel_id,hotel_id,data_date,snapshot_time,compare_type,comment_score,qunar_comment_score,validation_status,validation_flags,data_source_id,sync_task_id,source_trace_id,raw_data,create_time,update_time')
    ->order('id', 'asc')
    ->select()
    ->toArray();

$activeHotels = Db::name('hotels')->where('status', 1)->field('id,tenant_id,name,status')->select()->toArray();
$activeHotelMap = [];
foreach ($activeHotels as $hotel) {
    $activeHotelMap[(int)$hotel['id']] = $hotel;
}
$currentReadyConfigMap = identity_repair_current_ready_config_map($activeHotels);
$activeSelfHistoryMap = identity_repair_unique_active_self_history_map($rows, $activeHotels);

$roleCandidates = [];
$qualityCandidates = [];
$evidenceCandidateCount = 0;
$unboundGroups = [];
$allOwnerGroups = [];
foreach ($rows as $row) {
    $semantics = CtripCompetitionCirclePersistenceService::normalizeRowSemantics(identity_repair_raw($row));
    $flagCodeSet = identity_repair_flag_code_set($row);
    $qualityCodes = array_fill_keys($semantics['validation_flag_codes'], true);
    $commentMismatch = $semantics['comment_score'] === null
        ? $row['comment_score'] !== null
        : abs((float)$row['comment_score'] - (float)$semantics['comment_score']) > 0.0001;
    $qunarMismatch = $semantics['qunar_comment_score'] === null
        ? $row['qunar_comment_score'] !== null
        : abs((float)$row['qunar_comment_score'] - (float)$semantics['qunar_comment_score']) > 0.0001;
    $qualityFlagMismatch = isset($qualityCodes['field_missing:comment_score']) !== isset($flagCodeSet['field_missing:comment_score'])
        || isset($qualityCodes['field_missing:qunar_comment_score']) !== isset($flagCodeSet['field_missing:qunar_comment_score']);
    $evidenceFlagCodes = [];
    if ((int)($row['data_source_id'] ?? 0) <= 0) {
        $evidenceFlagCodes[] = 'evidence_missing:data_source_id';
    }
    if ((int)($row['sync_task_id'] ?? 0) <= 0) {
        $evidenceFlagCodes[] = 'evidence_missing:sync_task_id';
    }
    $evidenceCodeSet = array_fill_keys($evidenceFlagCodes, true);
    $evidenceFlagMismatch = isset($evidenceCodeSet['evidence_missing:data_source_id']) !== isset($flagCodeSet['evidence_missing:data_source_id'])
        || isset($evidenceCodeSet['evidence_missing:sync_task_id']) !== isset($flagCodeSet['evidence_missing:sync_task_id']);
    $currentValidationStatus = strtolower(trim((string)($row['validation_status'] ?? '')));
    $evidenceStatusMismatch = $evidenceFlagCodes !== []
        && !in_array($currentValidationStatus, ['unverified', 'abnormal'], true);
    $isEvidenceCandidate = $evidenceFlagMismatch || $evidenceStatusMismatch;
    if ($commentMismatch || $qunarMismatch || $qualityFlagMismatch || $isEvidenceCandidate) {
        $qualityCandidates[(int)$row['id']] = [
            'row' => $row,
            'semantics' => $semantics,
            'evidence_flag_codes' => $evidenceFlagCodes,
        ];
        if ($isEvidenceCandidate) {
            $evidenceCandidateCount++;
        }
    }
    if (identity_repair_has_explicit_self($row) && ($row['compare_type'] ?? '') !== 'self') {
        $roleCandidates[(int)$row['id']] = $row;
    }
    $currentSystemHotelId = (int)($row['system_hotel_id'] ?? 0);
    $snapshotKey = identity_repair_snapshot_key($row);
    $allGroupKey = $currentSystemHotelId . '|' . $snapshotKey;
    $allOwnerGroups[$allGroupKey]['key'] = $allGroupKey;
    $allOwnerGroups[$allGroupKey]['current_system_hotel_id'] = $currentSystemHotelId;
    $allOwnerGroups[$allGroupKey]['rows'][] = $row;
    if (identity_repair_has_explicit_self($row)) {
        $platformHotelId = identity_repair_platform_hotel_id($row);
        if ($platformHotelId !== '') {
            $allOwnerGroups[$allGroupKey]['self_hotel_ids'][$platformHotelId] = $platformHotelId;
        }
    }
    if ($currentSystemHotelId > 0) {
        continue;
    }
    $groupKey = $snapshotKey;
    $unboundGroups[$groupKey]['key'] = $groupKey;
    $unboundGroups[$groupKey]['rows'][] = $row;
    if (identity_repair_has_explicit_self($row)) {
        $platformHotelId = identity_repair_platform_hotel_id($row);
        if ($platformHotelId !== '') {
            $unboundGroups[$groupKey]['self_hotel_ids'][$platformHotelId] = $platformHotelId;
        }
    }
}

$resolvedGroups = [];
$unresolvedGroups = [];
foreach ($unboundGroups as $groupKey => $group) {
    $group['self_hotel_ids'] = array_values($group['self_hotel_ids'] ?? []);
    if (count($group['self_hotel_ids']) !== 1) {
        $group['reason'] = $group['self_hotel_ids'] === [] ? 'explicit_self_id_missing' : 'multiple_explicit_self_ids';
        $unresolvedGroups[$groupKey] = $group;
        continue;
    }

    $platformHotelId = (string)$group['self_hotel_ids'][0];
    $configOwners = array_values(array_unique(array_map('intval', $currentReadyConfigMap[$platformHotelId] ?? [])));
    $historyOwners = array_values(array_unique(array_map('intval', $activeSelfHistoryMap[$platformHotelId] ?? [])));
    $ownerId = 0;
    $ownerSource = '';
    if (count($configOwners) === 1) {
        $ownerId = $configOwners[0];
        $ownerSource = 'current_ready_config';
    } elseif (count($historyOwners) === 1) {
        $ownerId = $historyOwners[0];
        $ownerSource = 'unique_active_self_history';
    }

    if ($ownerId <= 0 || !isset($activeHotelMap[$ownerId])) {
        $group['reason'] = $configOwners !== [] || $historyOwners !== [] ? 'owner_ambiguous' : 'active_owner_not_found';
        $group['config_owner_ids'] = $configOwners;
        $group['history_owner_ids'] = $historyOwners;
        $unresolvedGroups[$groupKey] = $group;
        continue;
    }

    $group['owner_system_hotel_id'] = $ownerId;
    $group['owner_tenant_id'] = isset($activeHotelMap[$ownerId]['tenant_id']) ? (int)$activeHotelMap[$ownerId]['tenant_id'] : null;
    $group['owner_hotel_name'] = (string)($activeHotelMap[$ownerId]['name'] ?? '');
    $group['owner_source'] = $ownerSource;
    $resolvedGroups[$groupKey] = $group;
}

$boundMismatchGroups = [];
foreach ($allOwnerGroups as $groupKey => $group) {
    $currentSystemHotelId = (int)($group['current_system_hotel_id'] ?? 0);
    if ($currentSystemHotelId <= 0) {
        continue;
    }
    $selfHotelIds = array_values($group['self_hotel_ids'] ?? []);
    if (count($selfHotelIds) !== 1) {
        continue;
    }
    $platformHotelId = (string)$selfHotelIds[0];
    $configOwners = array_values(array_unique(array_map('intval', $currentReadyConfigMap[$platformHotelId] ?? [])));
    if (count($configOwners) !== 1 || $configOwners[0] === $currentSystemHotelId) {
        continue;
    }
    $ownerId = $configOwners[0];
    if ($ownerId <= 0 || !isset($activeHotelMap[$ownerId])) {
        continue;
    }
    $group['self_hotel_ids'] = $selfHotelIds;
    $group['owner_system_hotel_id'] = $ownerId;
    $group['owner_tenant_id'] = isset($activeHotelMap[$ownerId]['tenant_id']) ? (int)$activeHotelMap[$ownerId]['tenant_id'] : null;
    $group['owner_hotel_name'] = (string)($activeHotelMap[$ownerId]['name'] ?? '');
    $group['owner_source'] = 'current_ready_config';
    $group['reason'] = 'bound_owner_mismatch';
    $boundMismatchGroups['bound|' . $groupKey] = $group;
}
$resolvedGroups = array_merge($resolvedGroups, $boundMismatchGroups);

$preview = [
    'mode' => $dryRun ? 'dry_run' : 'execute',
    'scanned_rows' => count($rows),
    'role_repair_candidate_rows' => count($roleCandidates),
    'quality_repair_candidate_rows' => count($qualityCandidates),
    'evidence_repair_candidate_rows' => $evidenceCandidateCount,
    'unbound_batch_count' => count($unboundGroups),
    'unbound_row_count' => array_sum(array_map(static fn(array $group): int => count($group['rows'] ?? []), $unboundGroups)),
    'resolved_owner_batch_count' => count($resolvedGroups),
    'resolved_owner_row_count' => array_sum(array_map(static fn(array $group): int => count($group['rows'] ?? []), $resolvedGroups)),
    'bound_owner_mismatch_batch_count' => count($boundMismatchGroups),
    'bound_owner_mismatch_row_count' => array_sum(array_map(static fn(array $group): int => count($group['rows'] ?? []), $boundMismatchGroups)),
    'bound_owner_mismatches' => [],
    'resolved_by_source' => [],
    'unresolved_batch_count' => count($unresolvedGroups),
    'unresolved_row_count' => array_sum(array_map(static fn(array $group): int => count($group['rows'] ?? []), $unresolvedGroups)),
    'unresolved_batches' => [],
    'updated_role_rows' => 0,
    'updated_quality_rows' => 0,
    'updated_owner_rows' => 0,
];
foreach ($resolvedGroups as $group) {
    $source = (string)$group['owner_source'];
    $preview['resolved_by_source'][$source]['batches'] = (int)($preview['resolved_by_source'][$source]['batches'] ?? 0) + 1;
    $preview['resolved_by_source'][$source]['rows'] = (int)($preview['resolved_by_source'][$source]['rows'] ?? 0) + count($group['rows']);
}
foreach ($boundMismatchGroups as $group) {
    $preview['bound_owner_mismatches'][] = [
        'key' => (string)$group['key'],
        'row_count' => count($group['rows'] ?? []),
        'self_hotel_ids' => array_values(array_map('strval', $group['self_hotel_ids'] ?? [])),
        'current_system_hotel_id' => (int)($group['current_system_hotel_id'] ?? 0),
        'owner_system_hotel_id' => (int)($group['owner_system_hotel_id'] ?? 0),
        'owner_hotel_name' => (string)($group['owner_hotel_name'] ?? ''),
    ];
}
foreach ($unresolvedGroups as $group) {
    $preview['unresolved_batches'][] = [
        'key' => (string)$group['key'],
        'row_count' => count($group['rows'] ?? []),
        'self_hotel_ids' => array_values(array_map('strval', $group['self_hotel_ids'] ?? [])),
        'reason' => (string)($group['reason'] ?? 'unknown'),
        'config_owner_ids' => $group['config_owner_ids'] ?? [],
        'history_owner_ids' => $group['history_owner_ids'] ?? [],
    ];
}

if ($dryRun) {
    echo json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
}

Db::transaction(function () use (&$preview, $roleCandidates, $qualityCandidates, $resolvedGroups): void {
    foreach ($roleCandidates as $row) {
        $removeCodes = ['self_identity_conflict_with_current_binding'];
        if ((int)($row['system_hotel_id'] ?? 0) > 0) {
            $removeCodes[] = 'self_identity_unresolved';
        }
        Db::name('online_daily_data')->where('id', (int)$row['id'])->update([
            'compare_type' => 'self',
            'validation_flags' => identity_repair_validation_flags($row, $removeCodes, []),
            'update_time' => $row['update_time'],
        ]);
        $preview['updated_role_rows']++;
    }

    foreach ($resolvedGroups as $group) {
        $ownerSource = (string)$group['owner_source'];
        $ownerFlagCodes = ['historical_owner_inferred_from_' . $ownerSource];
        if (($group['reason'] ?? '') === 'bound_owner_mismatch') {
            $ownerFlagCodes[] = 'historical_owner_reassigned_from_bound_owner_mismatch';
        }
        foreach ($group['rows'] as $row) {
            $update = [
                'tenant_id' => $group['owner_tenant_id'],
                'system_hotel_id' => (int)$group['owner_system_hotel_id'],
                'validation_flags' => identity_repair_validation_flags(
                    $row,
                    ['binding_missing', 'self_identity_unresolved', 'self_identity_conflict_with_current_binding'],
                    $ownerFlagCodes
                ),
                // Preserve the original acquisition timestamp; ownership repair
                // must not look like a new Ctrip collection in history views.
                'update_time' => $row['update_time'],
            ];
            if (identity_repair_has_explicit_self($row)) {
                $update['compare_type'] = 'self';
            }
            Db::name('online_daily_data')->where('id', (int)$row['id'])->update($update);
            $preview['updated_owner_rows']++;
        }
    }

    foreach ($qualityCandidates as $candidate) {
        $row = $candidate['row'];
        $semantics = $candidate['semantics'];
        $evidenceFlagCodes = is_array($candidate['evidence_flag_codes'] ?? null)
            ? $candidate['evidence_flag_codes']
            : [];
        $current = Db::name('online_daily_data')->where('id', (int)$row['id'])->find();
        $current = is_array($current) ? $current : $row;
        $status = strtolower(trim((string)($current['validation_status'] ?? '')));
        if ($status === 'abnormal') {
            $status = 'abnormal';
        } elseif ($evidenceFlagCodes !== []) {
            $status = 'unverified';
        } elseif ($status !== 'unverified') {
            $status = (string)$semantics['validation_status'];
        }
        Db::name('online_daily_data')->where('id', (int)$row['id'])->update([
            'comment_score' => $semantics['comment_score'],
            'qunar_comment_score' => $semantics['qunar_comment_score'],
            'validation_status' => $status,
            'validation_flags' => identity_repair_validation_flags(
                $current,
                [
                    'field_missing:comment_score',
                    'field_missing:qunar_comment_score',
                    'evidence_missing:data_source_id',
                    'evidence_missing:sync_task_id',
                ],
                array_values(array_unique(array_merge(
                    $semantics['validation_flag_codes'],
                    $evidenceFlagCodes
                )))
            ),
            'update_time' => $row['update_time'],
        ]);
        $preview['updated_quality_rows']++;
    }
});

echo json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
