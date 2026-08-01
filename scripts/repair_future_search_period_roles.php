<?php
declare(strict_types=1);

use app\service\OnlineDailyDataPersistenceService;
use app\service\PlatformNormalizedRowPersistenceService;
use app\service\SchemaVersionService;

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

/**
 * Correct Ctrip traffic_search_details target-date rows that were mistakenly
 * assigned either a finalized historical role or a realtime snapshot role.
 *
 * Dry-run is the default. Business metric values and raw evidence are never
 * rewritten, no row is deleted, and readback trust is not minted by this
 * classification repair.
 *
 * @param array<int, string> $argv
 * @return array{execute:bool,hotel_id:int,start_date:string,end_date:string}
 */
function temporal_period_repair_options(array $argv): array
{
    $options = [
        'execute' => false,
        'hotel_id' => 0,
        'start_date' => '',
        'end_date' => '',
    ];
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--execute') {
            $options['execute'] = true;
            continue;
        }
        foreach ([
            '--hotel-id=' => 'hotel_id',
            '--start-date=' => 'start_date',
            '--end-date=' => 'end_date',
        ] as $prefix => $key) {
            if (str_starts_with($argument, $prefix)) {
                $options[$key] = substr($argument, strlen($prefix));
                continue 2;
            }
        }
        throw new InvalidArgumentException('unsupported argument: ' . $argument);
    }
    $options['hotel_id'] = max(0, (int)$options['hotel_id']);
    if ($options['hotel_id'] <= 0) {
        throw new InvalidArgumentException('--hotel-id=<positive system hotel id> is required');
    }
    foreach (['start_date', 'end_date'] as $key) {
        $value = trim((string)$options[$key]);
        if ($value !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new InvalidArgumentException($key . ' must be YYYY-MM-DD');
        }
        $options[$key] = $value;
    }
    if ($options['start_date'] !== ''
        && $options['end_date'] !== ''
        && $options['start_date'] > $options['end_date']
    ) {
        throw new InvalidArgumentException('start_date cannot be after end_date');
    }
    return $options;
}

/** @param array<string, mixed> $row */
function temporal_period_repair_hash(array $row): string
{
    return (new PlatformNormalizedRowPersistenceService())->identityHash($row);
}

/**
 * @param array<string, mixed> $stored
 * @param array<string, mixed> $expected
 */
function temporal_period_repair_mismatch_field(array $stored, array $expected): string
{
    foreach ($expected as $field => $value) {
        if (!array_key_exists($field, $stored)) {
            return (string)$field;
        }
        if ($value === null) {
            if ($stored[$field] !== null) {
                return (string)$field;
            }
            continue;
        }
        if ((string)$stored[$field] !== (string)$value) {
            return (string)$field;
        }
    }
    return '';
}

/**
 * @param array<string, mixed> $stored
 * @param array<string, mixed> $expected
 */
function temporal_period_repair_row_matches(array $stored, array $expected): bool
{
    return temporal_period_repair_mismatch_field($stored, $expected) === '';
}

try {
    $options = temporal_period_repair_options($argv);
    $root = dirname(__DIR__);
    $pdo = SchemaVersionService::createPdo(
        SchemaVersionService::databaseConfigFromEnvironment($root)
    );
    $columnRows = $pdo->query('SHOW COLUMNS FROM online_daily_data')->fetchAll(PDO::FETCH_ASSOC);
    $columns = array_fill_keys(array_map(
        static fn(array $column): string => (string)($column['Field'] ?? ''),
        $columnRows
    ), true);
    $generatedColumns = array_values(array_filter(array_map(
        static fn(array $column): string => str_contains(
            strtoupper((string)($column['Extra'] ?? '')),
            'GENERATED'
        )
            ? (string)($column['Field'] ?? '')
            : '',
        $columnRows
    )));
    foreach ([
        'id',
        'tenant_id',
        'system_hotel_id',
        'source',
        'platform',
        'hotel_id',
        'data_type',
        'data_date',
        'data_period',
        'dimension',
        'compare_type',
        'raw_data',
        'is_final',
        'persistence_identity_hash',
        'readback_verified',
    ] as $requiredColumn) {
        if (!isset($columns[$requiredColumn])) {
            throw new RuntimeException('online_daily_data column missing: ' . $requiredColumn);
        }
    }
    $activeSyncTasks = [];
    $syncTaskTable = $pdo->query(
        "SHOW TABLES LIKE 'platform_data_sync_tasks'"
    )->fetchColumn();
    if ($syncTaskTable !== false) {
        $activeSyncQuery = $pdo->prepare(
            "SELECT id, status, started_at FROM platform_data_sync_tasks "
            . "WHERE system_hotel_id = ? AND status = 'running' ORDER BY id ASC"
        );
        $activeSyncQuery->execute([$options['hotel_id']]);
        $activeSyncTasks = $activeSyncQuery->fetchAll(PDO::FETCH_ASSOC);
    }
    if ($options['execute'] && $activeSyncTasks !== []) {
        throw new RuntimeException(
            'active platform sync task exists for hotel; retry after collection finishes'
        );
    }

    $roleMismatch = [
        "COALESCE(data_period, '') <> 'next_30_days'",
        'COALESCE(is_final, -1) <> 0',
    ];
    if (isset($columns['snapshot_time'])) {
        $roleMismatch[] = 'snapshot_time IS NOT NULL';
    }
    if (isset($columns['snapshot_bucket'])) {
        $roleMismatch[] = "COALESCE(snapshot_bucket, '') <> ''";
    }
    $where = [
        'system_hotel_id = ?',
        "LOWER(TRIM(source)) = 'ctrip'",
        "LOWER(TRIM(data_type)) = 'traffic'",
        '(' . implode(' OR ', $roleMismatch) . ')',
        "(dimension LIKE 'catalog:traffic_report:traffic_search_details:%'"
            . (isset($columns['endpoint_id']) ? " OR endpoint_id = 'traffic_search_details'" : '')
            . ')',
    ];
    $params = [$options['hotel_id']];
    if ($options['start_date'] !== '') {
        $where[] = 'data_date >= ?';
        $params[] = $options['start_date'];
    }
    if ($options['end_date'] !== '') {
        $where[] = 'data_date <= ?';
        $params[] = $options['end_date'];
    }
    $select = $pdo->prepare(
        'SELECT * FROM online_daily_data WHERE ' . implode(' AND ', $where)
        . ' ORDER BY id ASC'
    );
    $select->execute($params);

    $candidates = [];
    $rejectedByClassifier = 0;
    $captureDateCounts = [];
    $compareTypeCounts = [];
    $sourcePeriodRoleCounts = [];
    $readbackStatusCounts = ['verified' => 0, 'unverified' => 0];
    $targetDates = [];
    while (($row = $select->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (!OnlineDailyDataPersistenceService::isFutureTargetRow($row)) {
            $rejectedByClassifier++;
            continue;
        }
        $captureDate = trim((string)($row['data_date'] ?? ''));
        if ($captureDate !== '') {
            $captureDateCounts[$captureDate] = (int)($captureDateCounts[$captureDate] ?? 0) + 1;
        }
        $compareType = strtolower(trim((string)($row['compare_type'] ?? '')));
        $compareType = $compareType !== '' ? $compareType : 'missing';
        $compareTypeCounts[$compareType] = (int)($compareTypeCounts[$compareType] ?? 0) + 1;
        $readbackKey = (int)($row['readback_verified'] ?? 0) === 1 ? 'verified' : 'unverified';
        $readbackStatusCounts[$readbackKey]++;
        $sourceRole = (string)($row['data_period'] ?? 'missing')
            . '|'
            . (string)($row['is_final'] ?? 'missing');
        $sourcePeriodRoleCounts[$sourceRole] =
            (int)($sourcePeriodRoleCounts[$sourceRole] ?? 0) + 1;
        $raw = json_decode((string)($row['raw_data'] ?? ''), true);
        $dimensionValues = is_array($raw['dimension_values'] ?? null)
            ? $raw['dimension_values']
            : [];
        $targetDate = trim((string)($dimensionValues['target_date'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate) === 1) {
            $targetDates[$targetDate] = true;
        }
        foreach ($generatedColumns as $generatedColumn) {
            unset($row[$generatedColumn]);
        }
        $updated = $row;
        $updated['data_period'] = 'next_30_days';
        $updated['is_final'] = 0;
        if (isset($columns['snapshot_time'])) {
            $updated['snapshot_time'] = null;
        }
        if (isset($columns['snapshot_bucket'])) {
            $updated['snapshot_bucket'] = '';
        }
        $updated['readback_verified'] = 0;
        if (isset($columns['readback_verified_at'])) {
            $updated['readback_verified_at'] = null;
        }
        $updated['persistence_identity_hash'] = temporal_period_repair_hash($updated);
        $candidates[] = ['original' => $row, 'updated' => $updated];
    }

    $collisionQuery = $pdo->prepare(
        'SELECT id FROM online_daily_data WHERE persistence_identity_hash = ? ORDER BY id ASC'
    );
    $candidateGroups = [];
    foreach ($candidates as $candidate) {
        $candidateGroups[(string)$candidate['updated']['persistence_identity_hash']][] = $candidate;
    }
    $safeCandidates = [];
    $collisions = [];
    foreach ($candidateGroups as $hash => $group) {
        $groupIds = array_fill_keys(array_map(
            static fn(array $candidate): int => (int)$candidate['original']['id'],
            $group
        ), true);
        $collisionQuery->execute([$hash]);
        $existingIds = array_values(array_filter(array_map(
            'intval',
            $collisionQuery->fetchAll(PDO::FETCH_COLUMN)
        ), static fn(int $id): bool => $id > 0 && !isset($groupIds[$id])));
        if ($existingIds !== []) {
            foreach ($group as $candidate) {
                $collisions[] = [
                    'row_id' => (int)$candidate['original']['id'],
                    'conflicting_row_id' => $existingIds[0],
                    'collision_type' => 'existing_canonical_row',
                ];
            }
            continue;
        }

        usort($group, static function (array $left, array $right): int {
            $leftRow = $left['original'];
            $rightRow = $right['original'];
            return [
                (int)($leftRow['readback_verified'] ?? 0) === 1 ? 1 : 0,
                max(0, (int)($leftRow['sync_task_id'] ?? 0)),
                (string)($leftRow['snapshot_time'] ?? $leftRow['update_time'] ?? ''),
                (int)($leftRow['id'] ?? 0),
            ] <=> [
                (int)($rightRow['readback_verified'] ?? 0) === 1 ? 1 : 0,
                max(0, (int)($rightRow['sync_task_id'] ?? 0)),
                (string)($rightRow['snapshot_time'] ?? $rightRow['update_time'] ?? ''),
                (int)($rightRow['id'] ?? 0),
            ];
        });
        $winner = array_pop($group);
        if (is_array($winner)) {
            $safeCandidates[] = $winner;
        }
        foreach ($group as $candidate) {
            $collisions[] = [
                'row_id' => (int)$candidate['original']['id'],
                'conflicting_row_id' => is_array($winner)
                    ? (int)$winner['original']['id']
                    : 0,
                'collision_type' => 'older_version_same_canonical_identity',
            ];
        }
    }
    $safeReadbackStatusCounts = ['verified' => 0, 'unverified' => 0];
    foreach ($safeCandidates as $candidate) {
        $key = (int)($candidate['original']['readback_verified'] ?? 0) === 1
            ? 'verified'
            : 'unverified';
        $safeReadbackStatusCounts[$key]++;
    }

    $written = 0;
    $verified = 0;
    $readbackTrustPreserved = 0;
    if ($options['execute'] && $safeCandidates !== []) {
        $set = [
            "data_period = 'next_30_days'",
            'is_final = 0',
            'persistence_identity_hash = ?',
            'readback_verified = 0',
        ];
        if (isset($columns['snapshot_time'])) {
            $set[] = 'snapshot_time = NULL';
        }
        if (isset($columns['snapshot_bucket'])) {
            $set[] = "snapshot_bucket = ''";
        }
        if (isset($columns['readback_verified_at'])) {
            $set[] = 'readback_verified_at = NULL';
        }
        if (isset($columns['update_time'])) {
            $set[] = 'update_time = ?';
        }
        $cas = $pdo->prepare(
            'UPDATE online_daily_data SET ' . implode(', ', $set)
            . ' WHERE id = ? AND system_hotel_id = ? AND (data_period <=> ?)'
            . ' AND (is_final <=> ?) AND raw_data = ?'
            . " AND COALESCE(persistence_identity_hash, '') = ?"
        );
        $readback = $pdo->prepare('SELECT * FROM online_daily_data WHERE id = ? LIMIT 1');
        $proofSet = ['readback_verified = 1'];
        if (isset($columns['readback_verified_at'])) {
            $proofSet[] = 'readback_verified_at = ?';
        }
        $preserveProof = $pdo->prepare(
            'UPDATE online_daily_data SET ' . implode(', ', $proofSet)
            . " WHERE id = ? AND data_period = 'next_30_days' AND is_final = 0"
            . ' AND readback_verified = 0 AND persistence_identity_hash = ? AND raw_data = ?'
        );
        $pdo->beginTransaction();
        try {
            foreach ($safeCandidates as $candidate) {
                $original = $candidate['original'];
                $updated = $candidate['updated'];
                $originalWasVerified = (int)($original['readback_verified'] ?? 0) === 1;
                $repairTime = date('Y-m-d H:i:s');
                $updateParams = [(string)$updated['persistence_identity_hash']];
                if (isset($columns['update_time'])) {
                    $updateParams[] = $repairTime;
                    $updated['update_time'] = $repairTime;
                }
                array_push(
                    $updateParams,
                    (int)$original['id'],
                    (int)$original['system_hotel_id'],
                    $original['data_period'] ?? null,
                    $original['is_final'] ?? null,
                    (string)$original['raw_data'],
                    (string)$original['persistence_identity_hash']
                );
                $cas->execute($updateParams);
                if ($cas->rowCount() !== 1) {
                    throw new RuntimeException(
                        'future-search period CAS failed for row ' . (int)$original['id']
                    );
                }
                $written++;
                $readback->execute([(int)$original['id']]);
                $stored = $readback->fetch(PDO::FETCH_ASSOC);
                $mismatchField = is_array($stored)
                    ? temporal_period_repair_mismatch_field($stored, $updated)
                    : 'row_missing';
                if ($mismatchField !== '') {
                    throw new RuntimeException(
                        'future-search period exact readback failed for row '
                        . (int)$original['id']
                        . ' field '
                        . $mismatchField
                    );
                }
                if ($originalWasVerified) {
                    $proofParams = [];
                    if (isset($columns['readback_verified_at'])) {
                        $proofParams[] = $repairTime;
                        $updated['readback_verified_at'] = $repairTime;
                    }
                    array_push(
                        $proofParams,
                        (int)$original['id'],
                        (string)$updated['persistence_identity_hash'],
                        (string)$updated['raw_data']
                    );
                    $preserveProof->execute($proofParams);
                    if ($preserveProof->rowCount() !== 1) {
                        throw new RuntimeException(
                            'future-search readback proof preservation failed for row '
                            . (int)$original['id']
                        );
                    }
                    $updated['readback_verified'] = 1;
                    $readback->execute([(int)$original['id']]);
                    $proofReadback = $readback->fetch(PDO::FETCH_ASSOC);
                    $proofExpected = $updated;
                    // MySQL may advance an ON UPDATE timestamp when the proof
                    // flag is persisted. Business values, identity and the
                    // proof fields themselves still require exact equality.
                    unset($proofExpected['update_time']);
                    $proofMismatchField = is_array($proofReadback)
                        ? temporal_period_repair_mismatch_field($proofReadback, $proofExpected)
                        : 'row_missing';
                    if ($proofMismatchField !== '') {
                        throw new RuntimeException(
                            'future-search preserved proof readback failed for row '
                            . (int)$original['id']
                            . ' field '
                            . $proofMismatchField
                        );
                    }
                    $readbackTrustPreserved++;
                }
                $verified++;
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    $remainingHistorical = $pdo->prepare(
        'SELECT COUNT(*) FROM online_daily_data WHERE system_hotel_id = ? '
        . "AND LOWER(TRIM(source)) = 'ctrip' AND LOWER(TRIM(data_type)) = 'traffic' "
        . "AND data_period = 'historical_daily' AND is_final = 1 "
        . "AND dimension LIKE 'catalog:traffic_report:traffic_search_details:%'"
    );
    $remainingHistorical->execute([$options['hotel_id']]);
    $remainingMisclassifiedSelect = $pdo->prepare(
        'SELECT * FROM online_daily_data WHERE system_hotel_id = ? '
        . "AND LOWER(TRIM(source)) = 'ctrip' AND LOWER(TRIM(data_type)) = 'traffic' "
        . 'AND (' . implode(' OR ', $roleMismatch) . ') '
        . "AND dimension LIKE 'catalog:traffic_report:traffic_search_details:%' "
        . 'ORDER BY id ASC'
    );
    $remainingMisclassifiedSelect->execute([$options['hotel_id']]);
    $remainingMisclassified = 0;
    while (($remainingRow = $remainingMisclassifiedSelect->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (OnlineDailyDataPersistenceService::isFutureTargetRow($remainingRow)) {
            $remainingMisclassified++;
        }
    }
    ksort($captureDateCounts, SORT_STRING);
    ksort($compareTypeCounts, SORT_STRING);
    ksort($sourcePeriodRoleCounts, SORT_STRING);
    $targetDateList = array_values(array_keys($targetDates));
    sort($targetDateList, SORT_STRING);
    $collisionTypeCounts = [];
    foreach ($collisions as $collision) {
        $type = (string)($collision['collision_type'] ?? 'unknown');
        $collisionTypeCounts[$type] = (int)($collisionTypeCounts[$type] ?? 0) + 1;
    }
    ksort($collisionTypeCounts, SORT_STRING);

    echo json_encode([
        'mode' => $options['execute'] ? 'execute' : 'dry-run',
        'scope' => [
            'system_hotel_id' => $options['hotel_id'],
            'source' => 'ctrip',
            'endpoint_id' => 'traffic_search_details',
            'from_period' => 'any_misclassified_period_role',
            'to_period' => 'next_30_days',
            'start_date' => $options['start_date'] !== '' ? $options['start_date'] : null,
            'end_date' => $options['end_date'] !== '' ? $options['end_date'] : null,
        ],
        'classifier_candidates' => count($candidates),
        'active_sync_task_count' => count($activeSyncTasks),
        'active_sync_task_ids' => array_slice(array_map(
            static fn(array $task): int => (int)($task['id'] ?? 0),
            $activeSyncTasks
        ), 0, 20),
        'candidate_row_id_sample' => array_slice(array_map(
            static fn(array $candidate): int => (int)$candidate['original']['id'],
            $candidates
        ), 0, 20),
        'capture_date_counts' => $captureDateCounts,
        'compare_type_counts' => $compareTypeCounts,
        'source_period_role_counts' => $sourcePeriodRoleCounts,
        'readback_status_counts' => $readbackStatusCounts,
        'target_date_range' => [
            'start_date' => $targetDateList !== [] ? $targetDateList[0] : null,
            'end_date' => $targetDateList !== []
                ? $targetDateList[count($targetDateList) - 1]
                : null,
            'distinct_dates' => count($targetDateList),
        ],
        'safe_updates_planned' => count($safeCandidates),
        'safe_update_readback_status_counts' => $safeReadbackStatusCounts,
        'classifier_rejected_rows' => $rejectedByClassifier,
        'identity_collision_count' => count($collisions),
        'identity_collision_type_counts' => $collisionTypeCounts,
        'identity_collisions' => array_slice($collisions, 0, 20),
        'written' => $written,
        'exact_readback_verified' => $verified,
        'readback_trust_preserved' => $readbackTrustPreserved,
        'readback_trust_preservation_planned' => $safeReadbackStatusCounts['verified'],
        'readback_trust_left_unverified' => $options['execute']
            ? $written - $readbackTrustPreserved
            : $readbackStatusCounts['unverified'],
        'remaining_historical_endpoint_rows' => (int)$remainingHistorical->fetchColumn(),
        'remaining_misclassified_future_target_rows' => $remainingMisclassified,
        'business_metric_fields_modified' => 0,
        'raw_evidence_fields_modified' => 0,
        'generated_projection_columns_excluded_from_exact_write_comparison' => $generatedColumns,
        'rows_deleted' => 0,
        'readback_trust_minted' => 0,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, '[repair:future-search-period-roles] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
