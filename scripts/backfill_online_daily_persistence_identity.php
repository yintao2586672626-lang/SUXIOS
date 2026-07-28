<?php
declare(strict_types=1);

use app\service\PlatformNormalizedRowPersistenceService;
use app\service\SchemaVersionService;

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

$root = dirname(__DIR__);
$execute = in_array('--execute', $argv, true);
$migration = '20260723_add_online_daily_data_persistence_identity.sql';

try {
    $config = SchemaVersionService::databaseConfigFromEnvironment($root);
    $pdo = SchemaVersionService::createPdo($config);
    $migrationQuery = $pdo->prepare(
        'SELECT executed_at FROM schema_versions WHERE migration = ? LIMIT 1'
    );
    $migrationQuery->execute([$migration]);
    $scopeStart = trim((string)$migrationQuery->fetchColumn());
    if ($scopeStart === '') {
        throw new RuntimeException('persistence identity migration is not registered');
    }

    $knownHashes = [];
    $knownQuery = $pdo->query(
        "SELECT persistence_identity_hash FROM online_daily_data "
        . "WHERE persistence_identity_hash IS NOT NULL AND persistence_identity_hash <> ''"
    );
    while (($hash = $knownQuery->fetchColumn()) !== false) {
        $knownHashes[(string)$hash] = true;
    }

    $rowsQuery = $pdo->prepare(
        'SELECT id, tenant_id, system_hotel_id, data_source_id, source, platform, '
        . 'hotel_id, data_type, data_date, data_period, snapshot_bucket, dimension, '
        . 'compare_type, raw_data, readback_verified, create_time, update_time '
        . 'FROM online_daily_data '
        . "WHERE (persistence_identity_hash IS NULL OR persistence_identity_hash = '') "
        . 'AND create_time >= ? ORDER BY id ASC'
    );
    $rowsQuery->execute([$scopeStart]);

    $identity = new PlatformNormalizedRowPersistenceService();
    $candidates = [];
    $groupSizes = [];
    $checked = 0;
    $alreadyIndexedDuplicates = 0;
    while (($row = $rowsQuery->fetch(PDO::FETCH_ASSOC)) !== false) {
        $checked++;
        $hash = $identity->identityHash($row);
        if (isset($knownHashes[$hash])) {
            $alreadyIndexedDuplicates++;
            continue;
        }
        $groupSizes[$hash] = (int)($groupSizes[$hash] ?? 0) + 1;
        if (!isset($candidates[$hash]) || isPreferredCanonicalRow($row, $candidates[$hash])) {
            $candidates[$hash] = $row;
        }
    }

    $collisionGroups = 0;
    $unindexedDuplicateRows = 0;
    foreach ($groupSizes as $size) {
        if ($size > 1) {
            $collisionGroups++;
            $unindexedDuplicateRows += $size - 1;
        }
    }

    $written = 0;
    if ($execute && $candidates !== []) {
        $pdo->beginTransaction();
        try {
            $update = $pdo->prepare(
                'UPDATE online_daily_data SET persistence_identity_hash = ? '
                . "WHERE id = ? AND (persistence_identity_hash IS NULL OR persistence_identity_hash = '')"
            );
            foreach ($candidates as $hash => $row) {
                $update->execute([$hash, (int)$row['id']]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException(
                        'persistence identity backfill row changed concurrently: ' . (int)$row['id']
                    );
                }
                $written++;
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    $remainingQuery = $pdo->prepare(
        'SELECT COUNT(*) FROM online_daily_data '
        . "WHERE (persistence_identity_hash IS NULL OR persistence_identity_hash = '') "
        . 'AND create_time >= ?'
    );
    $remainingQuery->execute([$scopeStart]);

    echo json_encode([
        'mode' => $execute ? 'execute' : 'dry-run',
        'scope' => 'rows created after persistence identity migration registration',
        'scope_start' => $scopeStart,
        'checked_missing_rows' => $checked,
        'canonical_updates_planned' => count($candidates),
        'written' => $written,
        'collision_groups_preserved' => $collisionGroups,
        'unindexed_duplicate_rows_preserved' => $unindexedDuplicateRows + $alreadyIndexedDuplicates,
        'remaining_missing_rows_in_scope' => (int)$remainingQuery->fetchColumn(),
        'historical_rows_before_scope_modified' => 0,
        'facts_deleted' => 0,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, '[backfill:online-daily-persistence-identity] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

/** @param array<string, mixed> $candidate @param array<string, mixed> $current */
function isPreferredCanonicalRow(array $candidate, array $current): bool
{
    $candidateVerified = (int)($candidate['readback_verified'] ?? 0);
    $currentVerified = (int)($current['readback_verified'] ?? 0);
    if ($candidateVerified !== $currentVerified) {
        return $candidateVerified > $currentVerified;
    }

    $candidateTime = max(
        trim((string)($candidate['update_time'] ?? '')),
        trim((string)($candidate['create_time'] ?? ''))
    );
    $currentTime = max(
        trim((string)($current['update_time'] ?? '')),
        trim((string)($current['create_time'] ?? ''))
    );
    if ($candidateTime !== $currentTime) {
        return strcmp($candidateTime, $currentTime) > 0;
    }
    return (int)($candidate['id'] ?? 0) > (int)($current['id'] ?? 0);
}
