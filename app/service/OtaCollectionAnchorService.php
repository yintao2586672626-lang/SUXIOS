<?php
declare(strict_types=1);

namespace app\service;

/**
 * Canonical source-task projection used by collection, promotion and natural
 * acceptance. Keep this contract centralized: a field that can authorize a
 * downstream state transition must be covered by the same immutable hash.
 */
final class OtaCollectionAnchorService
{
    public const CONTRACT_VERSION = 'ota_collection_anchor.v2';

    private const ALLOWED_PLATFORMS = ['ctrip', 'meituan'];
    private const ALLOWED_CORE_STATUSES = ['ready', 'blocked', 'not_ready', 'not_required'];

    /** @return array<int,array<string,mixed>> */
    public static function normalize(mixed $value): array
    {
        $tasks = [];
        foreach (is_array($value) ? $value : [] as $task) {
            if (!is_array($task)) {
                return [];
            }
            $sourceId = (int)($task['data_source_id'] ?? 0);
            $taskId = (int)($task['sync_task_id'] ?? 0);
            $platform = strtolower(trim((string)($task['platform'] ?? '')));
            $coreStatus = strtolower(trim((string)(
                $task['historical_core_contract_status'] ?? ''
            )));
            $rowIds = array_values(array_unique(array_filter(array_map(
                'intval',
                is_array($task['row_ids'] ?? null) ? $task['row_ids'] : []
            ), static fn(int $id): bool => $id > 0)));
            sort($rowIds, SORT_NUMERIC);
            if ($sourceId <= 0
                || $taskId <= 0
                || !in_array($platform, self::ALLOWED_PLATFORMS, true)
                || !in_array($coreStatus, self::ALLOWED_CORE_STATUSES, true)
                || $rowIds === []
                || isset($tasks[$sourceId])
            ) {
                return [];
            }
            $tasks[$sourceId] = [
                'data_source_id' => $sourceId,
                'sync_task_id' => $taskId,
                'platform' => $platform,
                'collection_status' => strtolower(trim((string)($task['collection_status'] ?? ''))),
                'p0_status' => strtolower(trim((string)($task['p0_status'] ?? ''))),
                'historical_core_contract_status' => $coreStatus,
                'row_ids' => $rowIds,
            ];
        }
        ksort($tasks, SORT_NUMERIC);
        return array_values($tasks);
    }

    public static function hash(mixed $value): string
    {
        return hash('sha256', json_encode([
            'contract_version' => self::CONTRACT_VERSION,
            'source_tasks' => self::normalize($value),
        ], JSON_UNESCAPED_SLASHES) ?: '{}');
    }

    public static function matches(mixed $value, mixed $expectedHash): bool
    {
        $expectedHash = strtolower(trim((string)$expectedHash));
        return preg_match('/^[a-f0-9]{64}$/D', $expectedHash) === 1
            && hash_equals(self::hash($value), $expectedHash);
    }
}
