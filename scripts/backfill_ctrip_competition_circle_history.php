<?php
declare(strict_types=1);

use app\service\CtripCompetitionCirclePersistenceService;
use think\App;
use think\facade\Db;

require __DIR__ . '/../vendor/autoload.php';

$app = new App();
$app->initialize();

const BACKFILL_TRACE_PREFIX = 'legacy_backfill:';
const COMPETITION_CIRCLE_DIMENSION = 'competition_circle_hotel';

/**
 * This migration never changes credential/Profile/fetch readiness state. It
 * only classifies historical online_daily_data rows and adds migration audit
 * anchors. Use --dry-run for an explicit read-only preview; --execute applies.
 */
$execute = in_array('--execute', $argv, true);
$dryRun = in_array('--dry-run', $argv, true) || !$execute;
$repairClassified = in_array('--repair-classified', $argv, true);
$systemHotelFilter = null;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--system-hotel-id=')) {
        $value = trim(substr($argument, strlen('--system-hotel-id=')));
        if (!preg_match('/^[1-9][0-9]*$/', $value)) {
            throw new InvalidArgumentException('--system-hotel-id must be a positive integer.');
        }
        $systemHotelFilter = (int)$value;
    }
}

/** @return array<string,mixed> */
function decode_backfill_raw(array $row): array
{
    $decoded = json_decode((string)($row['raw_data'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

function backfill_platform_hotel_id(array $row): string
{
    foreach (['masterHotelId', 'masterhotelid', 'master_hotel_id', 'hotelId', 'hotel_id', 'HotelId', 'hotelID'] as $key) {
        if (!array_key_exists($key, $row) || is_array($row[$key]) || is_object($row[$key])) {
            continue;
        }
        $value = trim((string)$row[$key]);
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

/** @return array<int,string> */
function config_hotel_ids(array $config): array
{
    $ids = [];
    foreach (['platform_hotel_id', 'platformHotelId', 'ota_hotel_id', 'otaHotelId', 'ctrip_hotel_id', 'ctripHotelId', 'masterHotelId', 'master_hotel_id'] as $key) {
        if (!is_scalar($config[$key] ?? null)) {
            continue;
        }
        $value = trim((string)$config[$key]);
        if ($value !== '') {
            $ids[$value] = true;
        }
    }
    return array_keys($ids);
}

/** @return array<int,array<int,string>> */
function configured_ctrip_hotel_ids(): array
{
    $map = [];
    $sources = Db::name('platform_data_sources')
        ->field('system_hotel_id,config_json')
        ->where('platform', 'ctrip')
        ->select()
        ->toArray();
    foreach ($sources as $source) {
        $systemHotelId = (int)($source['system_hotel_id'] ?? 0);
        $config = json_decode((string)($source['config_json'] ?? ''), true);
        if ($systemHotelId <= 0 || !is_array($config)) {
            continue;
        }
        foreach (config_hotel_ids($config) as $id) {
            if ($id !== (string)$systemHotelId) {
                $map[$systemHotelId][$id] = $id;
            }
        }
    }

    $stored = Db::name('system_configs')->where('config_key', 'ctrip_config_list')->value('config_value');
    $configs = json_decode((string)($stored ?? ''), true);
    if (is_array($configs)) {
        foreach ($configs as $config) {
            if (!is_array($config)) {
                continue;
            }
            $systemHotelId = (int)($config['system_hotel_id'] ?? $config['hotel_id'] ?? 0);
            if ($systemHotelId <= 0) {
                continue;
            }
            foreach (config_hotel_ids($config) as $id) {
                if ($id !== (string)$systemHotelId) {
                    $map[$systemHotelId][$id] = $id;
                }
            }
        }
    }

    foreach ($map as $systemHotelId => $ids) {
        $map[$systemHotelId] = array_values($ids);
    }
    return $map;
}

/** @return array<string,mixed> */
function ensure_backfill_source(int $systemHotelId): array
{
    $existing = Db::name('platform_data_sources')
        ->where('system_hotel_id', $systemHotelId)
        ->where('platform', 'ctrip')
        ->where('data_type', CtripCompetitionCirclePersistenceService::DATA_TYPE)
        ->where('ingestion_method', CtripCompetitionCirclePersistenceService::BACKFILL_INGESTION_METHOD)
        ->order('id', 'asc')
        ->find();
    if (is_array($existing)) {
        return $existing;
    }

    $now = date('Y-m-d H:i:s');
    $id = (int)Db::name('platform_data_sources')->insertGetId([
        'tenant_id' => $systemHotelId,
        'system_hotel_id' => $systemHotelId,
        'name' => '携程竞争圈历史回填',
        'platform' => 'ctrip',
        'data_type' => CtripCompetitionCirclePersistenceService::DATA_TYPE,
        'ingestion_method' => CtripCompetitionCirclePersistenceService::BACKFILL_INGESTION_METHOD,
        'status' => 'ready',
        'enabled' => 1,
        'config_json' => json_encode([
            'scope' => 'competition_circle_history',
            'evidence_boundary' => 'migration_trace_only_not_platform_response',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'secret_json' => null,
        'create_time' => $now,
        'update_time' => $now,
    ]);
    return Db::name('platform_data_sources')->where('id', $id)->find() ?: ['id' => $id];
}

function start_backfill_task(int $sourceId, int $systemHotelId): int
{
    $now = date('Y-m-d H:i:s');
    return (int)Db::name('platform_data_sync_tasks')->insertGetId([
        'tenant_id' => $systemHotelId,
        'data_source_id' => $sourceId,
        'system_hotel_id' => $systemHotelId,
        'platform' => 'ctrip',
        'data_type' => CtripCompetitionCirclePersistenceService::DATA_TYPE,
        'ingestion_method' => CtripCompetitionCirclePersistenceService::BACKFILL_INGESTION_METHOD,
        'trigger_type' => 'migration',
        'status' => 'running',
        'attempt_count' => 1,
        'max_attempts' => 1,
        'started_at' => $now,
        'message' => '携程竞争圈历史回填开始',
        'create_time' => $now,
        'update_time' => $now,
    ]);
}

/** @param array<int,string> $codes */
function merge_backfill_flags(array $row, array $codes): string
{
    $flags = json_decode((string)($row['validation_flags'] ?? ''), true);
    if (!is_array($flags)) {
        $flags = [];
    }
    $managedCodes = array_fill_keys([
        'self_identity_conflict_with_current_binding',
        'self_identity_unresolved',
        'binding_missing',
        'field_missing:comment_score',
        'field_missing:qunar_comment_score',
        'historical_source_trace_unavailable',
        'snapshot_time_inferred_from_data_date',
    ], true);
    $flags = array_values(array_filter($flags, static function ($flag) use ($managedCodes): bool {
        $code = is_array($flag) && is_scalar($flag['code'] ?? null) ? (string)$flag['code'] : '';
        return $code === '' || !isset($managedCodes[$code]);
    }));
    $existingCodes = [];
    foreach ($flags as $flag) {
        if (is_array($flag) && is_scalar($flag['code'] ?? null)) {
            $existingCodes[(string)$flag['code']] = true;
        }
    }
    foreach (array_values(array_unique($codes)) as $code) {
        if (isset($existingCodes[$code])) {
            continue;
        }
        $flags[] = [
            'level' => 'warning',
            'field' => str_starts_with($code, 'field_missing:') ? substr($code, 14) : 'source_trace_id',
            'code' => $code,
            'message' => $code,
        ];
    }
    return json_encode($flags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
}

$query = Db::name('online_daily_data')->where('source', 'ctrip');
if ($repairClassified) {
    $query->where('data_type', CtripCompetitionCirclePersistenceService::DATA_TYPE)
        ->where('dimension', COMPETITION_CIRCLE_DIMENSION)
        ->where('source_trace_id', 'like', BACKFILL_TRACE_PREFIX . '%');
} else {
    $query->where(function ($q) {
        $q->where('data_type', 'business')->whereOr('data_type', '');
    })->whereRaw("(`dimension` = '' OR `dimension` IS NULL)");
}
if ($systemHotelFilter !== null) {
    $query->where('system_hotel_id', $systemHotelFilter);
}
$legacyRows = $query->order('system_hotel_id', 'asc')->order('data_date', 'asc')->order('id', 'asc')->select()->toArray();
$candidates = [];
foreach ($legacyRows as $row) {
    $raw = decode_backfill_raw($row);
    if (CtripCompetitionCirclePersistenceService::hasCompetitionCircleSignature($raw)) {
        $candidates[] = $row;
    }
}

$alreadyClassifiedQuery = Db::name('online_daily_data')
    ->where('source', 'ctrip')
    ->where('data_type', CtripCompetitionCirclePersistenceService::DATA_TYPE)
    ->where('dimension', COMPETITION_CIRCLE_DIMENSION);
if ($systemHotelFilter !== null) {
    $alreadyClassifiedQuery->where('system_hotel_id', $systemHotelFilter);
}
$alreadyClassified = (int)$alreadyClassifiedQuery->count();

$configuredIds = configured_ctrip_hotel_ids();
$groups = [];
foreach ($candidates as $row) {
    $systemHotelId = (int)($row['system_hotel_id'] ?? 0);
    $key = implode('|', [
        (string)$systemHotelId,
        (string)($row['data_date'] ?? ''),
        (string)($row['snapshot_time'] ?? $row['update_time'] ?? ''),
        (string)($row['create_time'] ?? ''),
    ]);
    $groups[$key]['system_hotel_id'] = $systemHotelId;
    $groups[$key]['data_date'] = (string)($row['data_date'] ?? '');
    $groups[$key]['rows'][] = $row;
}

$preview = [
    'mode' => $dryRun
        ? ($repairClassified ? 'dry_run_repair' : 'dry_run')
        : ($repairClassified ? 'execute_repair' : 'execute'),
    'candidate_rows' => count($candidates),
    'already_classified' => $alreadyClassified,
    'store_count' => count(array_unique(array_filter(array_map(static fn(array $row): int => (int)($row['system_hotel_id'] ?? 0), $candidates)))),
    'circle_day_count' => count($groups),
    'unbound_rows' => 0,
    'missing_qunar_score_rows' => 0,
    'self_resolved_groups' => 0,
    'self_unresolved_groups' => 0,
    'min_data_date' => null,
    'max_data_date' => null,
    'updated_rows' => 0,
    'store_results' => [],
];

$preparedGroups = [];
foreach ($groups as $groupKey => $group) {
    $systemHotelId = (int)$group['system_hotel_id'];
    foreach ($group['rows'] as $row) {
        $raw = decode_backfill_raw($row);
        $semantics = CtripCompetitionCirclePersistenceService::normalizeRowSemantics($raw);
        if (($semantics['qunar_comment_score'] ?? null) === null) {
            $preview['missing_qunar_score_rows']++;
        }
        if ($systemHotelId <= 0) {
            $preview['unbound_rows']++;
        }
    }
    $configuredSelfIds = $systemHotelId > 0 ? ($configuredIds[$systemHotelId] ?? []) : [];
    $identitySource = 'current_platform_binding';
    $selfIds = $configuredSelfIds;
    $groupHotelIds = [];
    foreach ($group['rows'] as $row) {
        $id = backfill_platform_hotel_id(decode_backfill_raw($row));
        if ($id !== '') {
            $groupHotelIds[$id] = true;
        }
    }
    $selfResolved = array_intersect($selfIds, array_keys($groupHotelIds)) !== [];
    if (!$selfResolved) {
        $preview['self_unresolved_groups']++;
    } else {
        $preview['self_resolved_groups']++;
    }
    $preparedGroups[$groupKey] = array_merge($group, [
        'self_hotel_ids' => $selfIds,
        'identity_source' => $identitySource,
        'self_resolved' => $selfResolved,
    ]);
    $date = (string)$group['data_date'];
    $preview['min_data_date'] = $preview['min_data_date'] === null || strcmp($date, $preview['min_data_date']) < 0 ? $date : $preview['min_data_date'];
    $preview['max_data_date'] = $preview['max_data_date'] === null || strcmp($date, $preview['max_data_date']) > 0 ? $date : $preview['max_data_date'];
}

if ($dryRun) {
    echo json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
}

$groupsByStore = [];
foreach ($preparedGroups as $group) {
    $groupsByStore[(int)$group['system_hotel_id']][] = $group;
}

foreach ($groupsByStore as $systemHotelId => $storeGroups) {
    $sourceId = 0;
    $taskId = 0;
    if ($systemHotelId > 0) {
        $source = ensure_backfill_source($systemHotelId);
        $sourceId = (int)($source['id'] ?? 0);
        $taskId = start_backfill_task($sourceId, $systemHotelId);
    }
    $storeUpdated = 0;
    try {
        Db::transaction(function () use ($storeGroups, $sourceId, $taskId, &$storeUpdated): void {
            foreach ($storeGroups as $group) {
                $selfIds = $group['self_hotel_ids'];
                foreach ($group['rows'] as $row) {
                    $fields = CtripCompetitionCirclePersistenceService::buildLegacyBackfillFields($row, $selfIds);
                    $codes = $fields['validation_flag_codes'];
                    if (empty($group['self_resolved'])) {
                        $codes[] = 'self_identity_unresolved';
                    }
                    if ((int)($row['system_hotel_id'] ?? 0) <= 0) {
                        $codes[] = 'binding_missing';
                    }
                    $update = [
                        'data_type' => CtripCompetitionCirclePersistenceService::DATA_TYPE,
                        'dimension' => COMPETITION_CIRCLE_DIMENSION,
                        'compare_type' => $fields['compare_type'],
                        'platform' => 'Ctrip',
                        'ingestion_method' => CtripCompetitionCirclePersistenceService::BACKFILL_INGESTION_METHOD,
                        'source_trace_id' => $fields['source_trace_id'],
                        'snapshot_time' => $fields['snapshot_time'],
                        'data_source_id' => $sourceId > 0 ? $sourceId : null,
                        'sync_task_id' => $taskId > 0 ? $taskId : null,
                        'comment_score' => $fields['comment_score'],
                        'qunar_comment_score' => $fields['qunar_comment_score'],
                        'validation_status' => 'unverified',
                        'validation_flags' => merge_backfill_flags($row, $codes),
                        // Preserve the historical acquisition timestamp; this migration
                        // must not appear as a new OTA fetch in the UI.
                        'update_time' => $row['update_time'] ?? $row['create_time'] ?? null,
                    ];
                    Db::name('online_daily_data')->where('id', (int)$row['id'])->update($update);
                    $storeUpdated++;
                }
            }
        });

        $now = date('Y-m-d H:i:s');
        if ($taskId > 0) {
            Db::name('platform_data_sync_tasks')->where('id', $taskId)->update([
                'status' => 'success',
                'finished_at' => $now,
                'message' => '携程竞争圈历史回填完成',
                'stats_json' => json_encode(['updated_rows' => $storeUpdated], JSON_UNESCAPED_UNICODE),
                'update_time' => $now,
            ]);
            Db::name('platform_data_sources')->where('id', $sourceId)->update([
                'last_sync_time' => $now,
                'last_sync_status' => 'success',
                'last_error' => null,
                'update_time' => $now,
            ]);
        }
        $preview['updated_rows'] += $storeUpdated;
        $preview['store_results'][] = [
            'system_hotel_id' => $systemHotelId > 0 ? $systemHotelId : null,
            'updated_rows' => $storeUpdated,
            'data_source_id' => $sourceId ?: null,
            'sync_task_id' => $taskId ?: null,
            'status' => 'success',
        ];
    } catch (Throwable $exception) {
        if ($taskId > 0) {
            Db::name('platform_data_sync_tasks')->where('id', $taskId)->update([
                'status' => 'failed',
                'finished_at' => date('Y-m-d H:i:s'),
                'message' => 'historical_backfill_failed',
                'update_time' => date('Y-m-d H:i:s'),
            ]);
        }
        throw $exception;
    }
}

$preview['mode'] = $repairClassified ? 'execute_repair' : 'execute';
echo json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
