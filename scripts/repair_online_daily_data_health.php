<?php
declare(strict_types=1);

use app\service\PlatformNormalizedRowPersistenceService;
use app\service\SchemaVersionService;

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

date_default_timezone_set('Asia/Shanghai');

const ODH_REPAIR_VERSION = 'online_daily_data_health_repair.v1';
const ODH_DUPLICATE_FLAG = 'duplicate_business_key_superseded';

/** @param array<int, string> $argv @return array{execute:bool} */
function odh_options(array $argv): array
{
    $execute = false;
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--execute') {
            $execute = true;
            continue;
        }
        throw new InvalidArgumentException('unsupported argument: ' . $argument);
    }
    return ['execute' => $execute];
}

/** @return array<string, mixed> */
function odh_decode_json(mixed $value): array
{
    if (is_array($value)) {
        return $value;
    }
    if (!is_string($value) || trim($value) === '') {
        return [];
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

/** @return array<int, string> */
function odh_flags(mixed $value): array
{
    $decoded = odh_decode_json($value);
    if ($decoded === []) {
        return [];
    }
    $flags = [];
    foreach ($decoded as $flag) {
        if (is_scalar($flag) && trim((string)$flag) !== '') {
            $flags[] = trim((string)$flag);
        }
    }
    return array_values(array_unique($flags));
}

/** @param array<string, mixed> $row */
function odh_is_future_stay_order(array $row, string $today): bool
{
    $raw = odh_decode_json($row['raw_data'] ?? []);
    $wrappedRow = is_array($raw['row'] ?? null) ? $raw['row'] : [];
    $detail = is_array($wrappedRow['raw_data'] ?? null) ? $wrappedRow['raw_data'] : [];
    $platform = strtolower(trim((string)($row['platform'] ?? $row['source'] ?? '')));
    $source = strtolower(trim((string)($row['source'] ?? '')));
    return ($platform === 'ctrip' || $source === 'ctrip')
        && strtolower(trim((string)($row['data_type'] ?? ''))) === 'order'
        && strtolower(trim((string)($row['data_period'] ?? ''))) === 'historical_daily'
        && trim((string)($row['data_date'] ?? '')) > $today
        && strtolower(trim((string)($detail['business_date_basis'] ?? ''))) === 'stay_date'
        && strtolower(trim((string)($detail['source_method'] ?? ''))) === 'user_provided_unverified'
        && str_starts_with(
            strtolower(trim((string)($detail['import_contract'] ?? ''))),
            'ctrip_order_aggregate_'
        );
}

/** @param array<string, mixed> $row */
function odh_observation_time(array $row): string
{
    $raw = odh_decode_json($row['raw_data'] ?? []);
    $wrappedRow = is_array($raw['row'] ?? null) ? $raw['row'] : [];
    $detail = is_array($wrappedRow['raw_data'] ?? null) ? $wrappedRow['raw_data'] : [];
    foreach ([
        $raw['ingested_at'] ?? null,
        $raw['captured_at'] ?? null,
        $detail['observed_at'] ?? null,
        $row['snapshot_time'] ?? null,
        $row['create_time'] ?? null,
        $row['update_time'] ?? null,
    ] as $value) {
        $text = trim((string)($value ?? ''));
        if ($text === '') {
            continue;
        }
        $timestamp = strtotime($text);
        if ($timestamp !== false) {
            return date('Y-m-d H:i:s', $timestamp);
        }
    }
    throw new RuntimeException('online daily row has no usable observation time: ' . (int)($row['id'] ?? 0));
}

/** @param array<string, mixed> $row */
function odh_forecast_snapshot_bucket(array $row): string
{
    $taskId = max(0, (int)($row['sync_task_id'] ?? 0));
    if ($taskId > 0) {
        return substr('task:' . $taskId, 0, 20);
    }
    $time = odh_observation_time($row);
    return 'time:' . date('YmdHi', strtotime($time) ?: time());
}

/** @param array<string, mixed> $raw @param array<int, string> $reasons */
function odh_mark_raw_repair(array $raw, array $reasons, ?int $canonicalRowId = null): array
{
    $existing = is_array($raw['data_health_repair'] ?? null) ? $raw['data_health_repair'] : [];
    $existingReasons = is_array($existing['reasons'] ?? null) ? $existing['reasons'] : [];
    $mergedReasons = array_values(array_unique(array_filter(array_map(
        static fn(mixed $reason): string => trim((string)$reason),
        [...$existingReasons, ...$reasons]
    ))));
    $raw['data_health_repair'] = [
        'version' => ODH_REPAIR_VERSION,
        'reasons' => $mergedReasons,
        'canonical_row_id' => $canonicalRowId,
    ];
    return $raw;
}

/** @param array<string, mixed> $row @return array<string, mixed> */
function odh_future_stay_update(array $row): array
{
    $time = odh_observation_time($row);
    $bucket = 'ob:' . date('YmdHi', strtotime($time) ?: time());
    $raw = odh_decode_json($row['raw_data'] ?? []);
    $wrappedRow = is_array($raw['row'] ?? null) ? $raw['row'] : [];
    $detail = is_array($wrappedRow['raw_data'] ?? null) ? $wrappedRow['raw_data'] : [];
    $detail['date_role'] = 'future_stay_date';
    $detail['observed_at'] = $detail['observed_at'] ?? $time;
    $wrappedRow['raw_data'] = $detail;
    $wrappedRow['data_period'] = 'future_on_books';
    $wrappedRow['snapshot_time'] = $time;
    $wrappedRow['snapshot_bucket'] = $bucket;
    $raw['row'] = $wrappedRow;
    $raw['data_period'] = 'future_on_books';
    $raw['snapshot_time'] = $time;
    $raw['snapshot_bucket'] = $bucket;
    $raw = odh_mark_raw_repair($raw, ['future_stay_order_period_reclassified']);

    $updated = $row;
    $updated['data_period'] = 'future_on_books';
    $updated['snapshot_time'] = $time;
    $updated['snapshot_bucket'] = $bucket;
    $updated['is_final'] = 0;
    $updated['validation_flags'] = json_encode(
        array_values(array_unique([
            ...odh_flags($row['validation_flags'] ?? []),
            'future_stay_order_period_reclassified',
        ])),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
    $updated['raw_data'] = json_encode(
        $raw,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
    $updated['persistence_identity_hash'] = (new PlatformNormalizedRowPersistenceService())
        ->identityHash($updated);
    return $updated;
}

/** @param array<string, mixed> $row @return array<string, mixed> */
function odh_forecast_update(array $row): array
{
    $time = odh_observation_time($row);
    $bucket = odh_forecast_snapshot_bucket($row);
    $raw = odh_decode_json($row['raw_data'] ?? []);
    $wrappedRow = is_array($raw['row'] ?? null) ? $raw['row'] : [];
    $wrappedRow['snapshot_time'] = $time;
    $wrappedRow['snapshot_bucket'] = $bucket;
    $raw['row'] = $wrappedRow;
    $raw['snapshot_time'] = $time;
    $raw['snapshot_bucket'] = $bucket;
    $raw = odh_mark_raw_repair($raw, ['forecast_snapshot_identity_backfilled']);

    $updated = $row;
    $updated['snapshot_time'] = $time;
    $updated['snapshot_bucket'] = $bucket;
    $updated['is_final'] = 0;
    $updated['validation_flags'] = json_encode(
        array_values(array_unique([
            ...odh_flags($row['validation_flags'] ?? []),
            'forecast_snapshot_identity_backfilled',
        ])),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
    $updated['raw_data'] = json_encode(
        $raw,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
    $updated['persistence_identity_hash'] = (new PlatformNormalizedRowPersistenceService())
        ->identityHash($updated);
    return $updated;
}

/** @param array<string, mixed> $row */
function odh_is_explicit_duplicate_quarantine(array $row): bool
{
    return strtolower(trim((string)($row['validation_status'] ?? ''))) === 'quarantined'
        && in_array(ODH_DUPLICATE_FLAG, odh_flags($row['validation_flags'] ?? []), true);
}

/** @param array<string, mixed> $row */
function odh_business_key(array $row): string
{
    $normalize = static fn(mixed $value): string => mb_strtolower(trim((string)($value ?? '')), 'UTF-8');
    $dataType = $normalize($row['data_type'] ?? '');
    $ingestion = $normalize($row['ingestion_method'] ?? '');
    $taskId = max(0, (int)($row['sync_task_id'] ?? 0));
    $taskScope = in_array($dataType, ['traffic', 'flow', 'conversion'], true)
        && $taskId > 0
        && !in_array($ingestion, ['manual', 'import_json', 'import_csv', 'import_excel'], true)
        ? $taskId
        : 0;
    $hotelId = trim((string)($row['hotel_id'] ?? ''));
    $hotelKey = $hotelId !== ''
        ? $normalize($hotelId)
        : 'name:' . $normalize($row['hotel_name'] ?? '');
    return json_encode([
        $normalize($row['source'] ?? ''),
        $normalize($row['platform'] ?? ''),
        $dataType,
        $normalize($row['dimension'] ?? ''),
        $normalize($row['compare_type'] ?? ''),
        trim((string)($row['data_date'] ?? '')),
        $normalize($row['data_period'] ?? ''),
        $normalize($row['snapshot_bucket'] ?? ''),
        $taskScope,
        max(0, (int)($row['system_hotel_id'] ?? 0)),
        $hotelKey,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

/** @param array<string, mixed> $row @return array<int, int|string> */
function odh_preference_rank(array $row): array
{
    $validation = strtolower(trim((string)($row['validation_status'] ?? '')));
    $history = strtolower(trim((string)($row['history_status'] ?? '')));
    $validationRank = [
        'verified' => 6,
        'normal' => 5,
        'partial' => 4,
        'warning' => 3,
        'unverified' => 2,
        'abnormal' => 1,
        'quarantined' => 0,
    ][$validation] ?? 1;
    $strict = $validation === 'verified'
        && $history === 'success'
        && (int)($row['readback_verified'] ?? 0) === 1;
    return [
        $strict ? 1 : 0,
        $validationRank,
        $history === 'success' ? 2 : ($history === 'partial' ? 1 : 0),
        (int)($row['readback_verified'] ?? 0) === 1 ? 1 : 0,
        max(
            strtotime((string)($row['update_time'] ?? '')) ?: 0,
            strtotime((string)($row['create_time'] ?? '')) ?: 0
        ),
        (int)($row['id'] ?? 0),
    ];
}

/** @param array<string, mixed> $left @param array<string, mixed> $right */
function odh_preferred_row(array $left, array $right): array
{
    return odh_preference_rank($left) >= odh_preference_rank($right) ? $left : $right;
}

/** @param array<string, mixed> $row @return array<string, mixed> */
function odh_quarantine_duplicate(array $row, int $canonicalRowId): array
{
    $raw = odh_mark_raw_repair(
        odh_decode_json($row['raw_data'] ?? []),
        [ODH_DUPLICATE_FLAG],
        $canonicalRowId
    );
    $updated = $row;
    $updated['validation_status'] = 'quarantined';
    $updated['validation_flags'] = json_encode(
        array_values(array_unique([...odh_flags($row['validation_flags'] ?? []), ODH_DUPLICATE_FLAG])),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
    if (array_key_exists('history_status', $row)) {
        $updated['history_status'] = 'unverified';
    }
    $updated['readback_verified'] = 0;
    if (array_key_exists('readback_verified_at', $row)) {
        $updated['readback_verified_at'] = null;
    }
    $updated['raw_data'] = json_encode(
        $raw,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
    $updated['persistence_identity_hash'] = hash(
        'sha256',
        ODH_REPAIR_VERSION . '|quarantine|' . (int)$row['id'] . '|' . (string)($row['persistence_identity_hash'] ?? '')
    );
    return $updated;
}

/** @param array<string, mixed> $row @return array<string, mixed> */
function odh_reclassify_numeric_anomaly(array $row): array
{
    $raw = odh_mark_raw_repair(
        odh_decode_json($row['raw_data'] ?? []),
        ['out_of_domain_value_reclassified']
    );
    $updated = $row;
    $updated['validation_status'] = 'abnormal';
    $updated['validation_flags'] = json_encode(
        array_values(array_unique([
            ...odh_flags($row['validation_flags'] ?? []),
            'out_of_domain_value',
        ])),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
    $updated['raw_data'] = json_encode(
        $raw,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
    return $updated;
}

/** @return array<int, array<string, mixed>> */
function odh_query_all(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return array<string, bool> */
function odh_columns(PDO $pdo): array
{
    $rows = odh_query_all($pdo, 'SHOW COLUMNS FROM online_daily_data');
    return array_fill_keys(array_map(
        static fn(array $row): string => (string)($row['Field'] ?? ''),
        $rows
    ), true);
}

/** @return array<int, array<string, mixed>> */
function odh_rows_by_ids(PDO $pdo, array $ids, bool $forUpdate = false): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
    if ($ids === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    return odh_query_all(
        $pdo,
        'SELECT * FROM online_daily_data WHERE id IN (' . $placeholders . ') ORDER BY id ASC'
            . ($forUpdate ? ' FOR UPDATE' : ''),
        $ids
    );
}

/** @param array<int, array<string, mixed>> $rows @return array<int, array<string, mixed>> */
function odh_index_rows(array $rows): array
{
    $indexed = [];
    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id > 0) {
            $indexed[$id] = $row;
        }
    }
    return $indexed;
}

/**
 * @return array{
 *   updates:array<int,array{original:array<string,mixed>,updated:array<string,mixed>,reasons:array<int,string>}>,
 *   counts:array<string,int>,collisions:array<int,array<string,int|string>>
 * }
 */
function odh_build_plan(PDO $pdo, string $today): array
{
    $updates = [];
    $putUpdate = static function (
        array &$target,
        array $original,
        array $updated,
        string $reason
    ): void {
        $id = (int)($original['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('repair candidate id missing');
        }
        if (isset($target[$id])) {
            $updated = array_replace($target[$id]['updated'], $updated);
            $reasons = array_values(array_unique([...$target[$id]['reasons'], $reason]));
            $target[$id] = [
                'original' => $target[$id]['original'],
                'updated' => $updated,
                'reasons' => $reasons,
            ];
            return;
        }
        $target[$id] = ['original' => $original, 'updated' => $updated, 'reasons' => [$reason]];
    };

    $futureRows = odh_query_all(
        $pdo,
        "SELECT * FROM online_daily_data WHERE data_date > ? "
        . "AND LOWER(TRIM(COALESCE(platform, source, ''))) = 'ctrip' "
        . "AND LOWER(TRIM(COALESCE(data_type, ''))) = 'order' "
        . "AND LOWER(TRIM(COALESCE(data_period, ''))) = 'historical_daily' "
        . "ORDER BY id ASC",
        [$today]
    );
    $futureCount = 0;
    foreach ($futureRows as $row) {
        if (!odh_is_future_stay_order($row, $today)) {
            continue;
        }
        $futureCount++;
        $putUpdate($updates, $row, odh_future_stay_update($row), 'future_stay_order_period');
    }

    $forecastRows = odh_query_all(
        $pdo,
        "SELECT * FROM online_daily_data WHERE LOWER(TRIM(COALESCE(data_period, ''))) = 'next_30_days' "
        . "AND COALESCE(snapshot_bucket, '') = '' ORDER BY id ASC"
    );
    foreach ($forecastRows as $row) {
        $putUpdate($updates, $row, odh_forecast_update($row), 'forecast_snapshot_identity');
    }

    $numericRows = odh_query_all(
        $pdo,
        "SELECT * FROM online_daily_data WHERE ("
        . "amount < 0 OR quantity < 0 OR book_order_num < 0 OR data_value < 0 "
        . "OR list_exposure < 0 OR detail_exposure < 0 OR flow_rate < 0 "
        . "OR order_filling_num < 0 OR order_submit_num < 0 OR flow_rate > 100 "
        . "OR comment_score < 0 OR comment_score > 5 "
        . "OR qunar_comment_score < 0 OR qunar_comment_score > 5"
        . ") AND LOWER(TRIM(COALESCE(validation_status, ''))) NOT IN "
        . "('abnormal', 'quarantined', 'unverified') ORDER BY id ASC"
    );
    foreach ($numericRows as $row) {
        $putUpdate($updates, $row, odh_reclassify_numeric_anomaly($row), 'numeric_quality_status');
    }

    $keyRows = odh_query_all(
        $pdo,
        'SELECT id, source, platform, data_type, dimension, compare_type, data_date, data_period, '
        . 'snapshot_bucket, sync_task_id, ingestion_method, system_hotel_id, hotel_id, hotel_name, '
        . 'validation_status, validation_flags, history_status, readback_verified, create_time, update_time '
        . 'FROM online_daily_data ORDER BY id ASC'
    );
    $groups = [];
    $keyRowIndex = [];
    foreach ($keyRows as $row) {
        $id = (int)$row['id'];
        if (isset($updates[$id])) {
            $row = array_replace($row, $updates[$id]['updated']);
        }
        if (odh_is_explicit_duplicate_quarantine($row)) {
            continue;
        }
        $keyRowIndex[$id] = $row;
        $groups[odh_business_key($row)][] = $row;
    }

    $losers = [];
    foreach ($groups as $group) {
        if (count($group) <= 1) {
            continue;
        }
        $members = array_values($group);
        $winner = $members[0];
        foreach (array_slice($members, 1) as $candidate) {
            $winner = odh_preferred_row($winner, $candidate);
        }
        $winnerId = (int)$winner['id'];
        foreach ($members as $candidate) {
            $candidateId = (int)$candidate['id'];
            if ($candidateId !== $winnerId) {
                $losers[$candidateId] = $winnerId;
            }
        }
    }
    $loserRows = odh_index_rows(odh_rows_by_ids($pdo, array_keys($losers)));
    foreach ($losers as $loserId => $winnerId) {
        $original = $updates[$loserId]['original'] ?? $loserRows[$loserId] ?? null;
        if (!is_array($original)) {
            throw new RuntimeException('duplicate loser row missing: ' . $loserId);
        }
        $base = $updates[$loserId]['updated'] ?? $original;
        $putUpdate(
            $updates,
            $original,
            odh_quarantine_duplicate($base, $winnerId),
            'duplicate_business_key'
        );
    }

    $collisions = [];
    $plannedHashes = [];
    $hashOwner = $pdo->prepare(
        "SELECT id FROM online_daily_data WHERE persistence_identity_hash = ? AND id <> ? LIMIT 1"
    );
    foreach ($updates as $id => $candidate) {
        $hash = trim((string)($candidate['updated']['persistence_identity_hash'] ?? ''));
        if ($hash === '') {
            continue;
        }
        if (isset($plannedHashes[$hash]) && $plannedHashes[$hash] !== $id) {
            $collisions[] = [
                'row_id' => $id,
                'conflicting_row_id' => $plannedHashes[$hash],
                'type' => 'planned_hash_collision',
            ];
            continue;
        }
        $plannedHashes[$hash] = $id;
        $hashOwner->execute([$hash, $id]);
        $ownerId = (int)($hashOwner->fetchColumn() ?: 0);
        if ($ownerId > 0 && !isset($updates[$ownerId])) {
            $collisions[] = [
                'row_id' => $id,
                'conflicting_row_id' => $ownerId,
                'type' => 'existing_hash_collision',
            ];
        }
    }

    ksort($updates, SORT_NUMERIC);
    return [
        'updates' => $updates,
        'counts' => [
            'future_stay_order_rows' => $futureCount,
            'forecast_snapshot_rows' => count($forecastRows),
            'duplicate_rows_quarantined' => count($losers),
            'numeric_rows_reclassified' => count($numericRows),
            'total_rows_to_update' => count($updates),
        ],
        'collisions' => $collisions,
    ];
}

/** @return array<string, int> */
function odh_remaining_checks(PDO $pdo): array
{
    $futureMisclassified = (int)$pdo->query(
        "SELECT COUNT(*) FROM online_daily_data WHERE data_date > CURDATE() "
        . "AND LOWER(TRIM(COALESCE(platform, source, ''))) = 'ctrip' "
        . "AND LOWER(TRIM(COALESCE(data_type, ''))) = 'order' "
        . "AND LOWER(TRIM(COALESCE(data_period, ''))) = 'historical_daily' "
        . "AND JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.row.raw_data.business_date_basis')) = 'stay_date'"
    )->fetchColumn();
    $unversionedForecast = (int)$pdo->query(
        "SELECT COUNT(*) FROM online_daily_data WHERE LOWER(TRIM(COALESCE(data_period, ''))) = 'next_30_days' "
        . "AND COALESCE(snapshot_bucket, '') = ''"
    )->fetchColumn();
    $unsafeNumeric = (int)$pdo->query(
        "SELECT COUNT(*) FROM online_daily_data WHERE ("
        . "amount < 0 OR quantity < 0 OR book_order_num < 0 OR data_value < 0 "
        . "OR list_exposure < 0 OR detail_exposure < 0 OR flow_rate < 0 "
        . "OR order_filling_num < 0 OR order_submit_num < 0 OR flow_rate > 100 "
        . "OR comment_score < 0 OR comment_score > 5 "
        . "OR qunar_comment_score < 0 OR qunar_comment_score > 5"
        . ") AND LOWER(TRIM(COALESCE(validation_status, ''))) NOT IN "
        . "('abnormal', 'quarantined', 'unverified')"
    )->fetchColumn();

    $rows = odh_query_all(
        $pdo,
        'SELECT id, source, platform, data_type, dimension, compare_type, data_date, data_period, '
        . 'snapshot_bucket, sync_task_id, ingestion_method, system_hotel_id, hotel_id, hotel_name, '
        . 'validation_status, validation_flags FROM online_daily_data ORDER BY id ASC'
    );
    $groups = [];
    foreach ($rows as $row) {
        if (odh_is_explicit_duplicate_quarantine($row)) {
            continue;
        }
        $groups[odh_business_key($row)] = (int)($groups[odh_business_key($row)] ?? 0) + 1;
    }
    $duplicateExtra = 0;
    foreach ($groups as $count) {
        if ($count > 1) {
            $duplicateExtra += $count - 1;
        }
    }
    return [
        'misclassified_future_stay_orders' => $futureMisclassified,
        'unversioned_forecast_rows' => $unversionedForecast,
        'active_duplicate_extra_rows' => $duplicateExtra,
        'unsafe_numeric_status_rows' => $unsafeNumeric,
    ];
}

/** @param array<int, array<string, mixed>> $rows @return array{path:string,sha256:string,row_count:int,bytes:int} */
function odh_write_backup(string $root, array $rows): array
{
    $backupDir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backups'
        . DIRECTORY_SEPARATOR . 'online_daily_data_health';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0770, true) && !is_dir($backupDir)) {
        throw new RuntimeException('unable to create online daily health backup directory');
    }
    $path = $backupDir . DIRECTORY_SEPARATOR . date('Ymd_His') . '_affected_rows.jsonl';
    $handle = fopen($path, 'xb');
    if ($handle === false) {
        throw new RuntimeException('unable to create online daily health backup');
    }
    try {
        fwrite($handle, json_encode([
            '_meta' => [
                'repair_version' => ODH_REPAIR_VERSION,
                'created_at' => date('Y-m-d H:i:s'),
                'table' => 'online_daily_data',
                'row_count' => count($rows),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
        foreach ($rows as $row) {
            fwrite(
                $handle,
                json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                    . PHP_EOL
            );
        }
    } finally {
        fclose($handle);
    }
    return [
        'path' => $path,
        'sha256' => hash_file('sha256', $path) ?: '',
        'row_count' => count($rows),
        'bytes' => (int)filesize($path),
    ];
}

/** @param array<string, mixed> $expected */
function odh_mismatch_field(array $stored, array $expected, array $fields): string
{
    foreach ($fields as $field) {
        if (!array_key_exists($field, $expected)) {
            continue;
        }
        $expectedValue = $expected[$field];
        $storedValue = $stored[$field] ?? null;
        if ($expectedValue === null) {
            if ($storedValue !== null) {
                return $field;
            }
            continue;
        }
        if ((string)$storedValue !== (string)$expectedValue) {
            return $field;
        }
    }
    return '';
}

/**
 * @param array<int,array{original:array<string,mixed>,updated:array<string,mixed>,reasons:array<int,string>}> $updates
 * @return array{written:int,verified:int,backup:array<string,mixed>,remaining:array<string,int>}
 */
function odh_execute(PDO $pdo, string $root, array $updates, array $columns): array
{
    $runningTasks = (int)$pdo->query(
        "SELECT COUNT(*) FROM platform_data_sync_tasks WHERE status = 'running'"
    )->fetchColumn();
    if ($runningTasks > 0) {
        throw new RuntimeException('active platform sync task exists; retry after collection finishes');
    }
    if ($updates === []) {
        return [
            'written' => 0,
            'verified' => 0,
            'backup' => [],
            'remaining' => odh_remaining_checks($pdo),
        ];
    }

    $ids = array_keys($updates);
    $fields = array_values(array_filter([
        'data_period', 'snapshot_time', 'snapshot_bucket', 'is_final',
        'validation_status', 'validation_flags',
        'readback_verified', 'readback_verified_at', 'persistence_identity_hash',
        'raw_data', 'update_time',
    ], static fn(string $field): bool => isset($columns[$field])));
    $repairTime = date('Y-m-d H:i:s');
    $written = 0;
    $verified = 0;
    $backup = [];

    $pdo->beginTransaction();
    try {
        $lockedRows = odh_rows_by_ids($pdo, $ids, true);
        $lockedIndex = odh_index_rows($lockedRows);
        if (count($lockedIndex) !== count($updates)) {
            throw new RuntimeException('repair candidate row count changed before execution');
        }
        foreach ($updates as $id => $candidate) {
            $locked = $lockedIndex[$id];
            $original = $candidate['original'];
            foreach (['update_time', 'raw_data', 'persistence_identity_hash'] as $guardField) {
                if ((string)($locked[$guardField] ?? '') !== (string)($original[$guardField] ?? '')) {
                    throw new RuntimeException('repair candidate changed concurrently: ' . $id);
                }
            }
        }
        $backup = odh_write_backup($root, $lockedRows);

        foreach ($updates as $id => $candidate) {
            $updated = $candidate['updated'];
            if (isset($columns['update_time'])) {
                $updated['update_time'] = $repairTime;
            }
            $assignments = [];
            $params = [];
            foreach ($fields as $field) {
                if (!array_key_exists($field, $updated)) {
                    continue;
                }
                $assignments[] = '`' . $field . '` = ?';
                $params[] = $updated[$field];
            }
            if ($assignments === []) {
                throw new RuntimeException('repair update has no fields: ' . $id);
            }
            $params[] = $id;
            $params[] = $candidate['original']['update_time'] ?? null;
            $params[] = $candidate['original']['raw_data'] ?? null;
            $params[] = $candidate['original']['persistence_identity_hash'] ?? null;
            $sql = 'UPDATE online_daily_data SET ' . implode(', ', $assignments)
                . ' WHERE id = ? AND (update_time <=> ?) AND (raw_data <=> ?)'
                . ' AND (persistence_identity_hash <=> ?)';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('repair CAS update failed: ' . $id);
            }
            $written++;

            $readback = $pdo->prepare('SELECT * FROM online_daily_data WHERE id = ? LIMIT 1');
            $readback->execute([$id]);
            $stored = $readback->fetch(PDO::FETCH_ASSOC);
            if (!is_array($stored)) {
                throw new RuntimeException('repair readback row missing: ' . $id);
            }
            $expected = $updated;
            $mismatch = odh_mismatch_field($stored, $expected, $fields);
            if ($mismatch !== '') {
                throw new RuntimeException('repair readback mismatch: ' . $id . ':' . $mismatch);
            }
            $verified++;
        }

        $remaining = odh_remaining_checks($pdo);
        if (array_sum($remaining) !== 0) {
            throw new RuntimeException('repair post-check failed: ' . json_encode($remaining));
        }
        $pdo->commit();
        return [
            'written' => $written,
            'verified' => $verified,
            'backup' => $backup,
            'remaining' => $remaining,
        ];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function odh_main(array $argv): int
{
    try {
        $options = odh_options($argv);
        $root = dirname(__DIR__);
        $config = SchemaVersionService::databaseConfigFromEnvironment($root);
        $pdo = SchemaVersionService::createPdo($config);
        $columns = odh_columns($pdo);
        foreach ([
            'id', 'data_date', 'data_period', 'snapshot_time', 'snapshot_bucket', 'is_final',
            'validation_status', 'validation_flags', 'readback_verified', 'raw_data',
            'persistence_identity_hash',
        ] as $requiredColumn) {
            if (!isset($columns[$requiredColumn])) {
                throw new RuntimeException('online_daily_data column missing: ' . $requiredColumn);
            }
        }

        $plan = odh_build_plan($pdo, date('Y-m-d'));
        if ($plan['collisions'] !== []) {
            throw new RuntimeException(
                'repair identity collision detected: ' . json_encode($plan['collisions'])
            );
        }
        $execution = $options['execute']
            ? odh_execute($pdo, $root, $plan['updates'], $columns)
            : [
                'written' => 0,
                'verified' => 0,
                'backup' => [],
                'remaining' => odh_remaining_checks($pdo),
            ];
        echo json_encode([
            'mode' => $options['execute'] ? 'execute' : 'dry-run',
            'repair_version' => ODH_REPAIR_VERSION,
            'database' => (string)($config['database'] ?? ''),
            'checked_at' => date('Y-m-d H:i:s'),
            'plan' => $plan['counts'],
            'collisions' => $plan['collisions'],
            'execution' => $execution,
            'facts_deleted' => 0,
            'business_metric_values_rewritten' => 0,
            'strict_fact_rows_promoted' => 0,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
        return 0;
    } catch (Throwable $exception) {
        fwrite(STDERR, '[repair:online-daily-data-health] ' . $exception->getMessage() . PHP_EOL);
        return 1;
    }
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(odh_main($argv));
}
