<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/** Exact-task verifier for the two-platform historical daily core contract. */
final class OtaHistoricalCoreReadbackVerifier
{
    /** @var callable|null */
    private $rowLoader;

    public function __construct(?callable $rowLoader = null)
    {
        $this->rowLoader = $rowLoader;
    }

    /** @param array<string,mixed> $readback */
    public function verify(
        string $platform,
        int $tenantId,
        int $sourceId,
        int $hotelId,
        string $targetDate,
        string $dataPeriod,
        array $readback
    ): bool {
        $rowIds = $this->positiveIds($readback['row_ids'] ?? []);
        if (!$this->identityReady(
            $platform,
            $tenantId,
            $sourceId,
            $hotelId,
            $targetDate,
            $dataPeriod,
            $readback,
            $rowIds
        )) {
            return false;
        }
        try {
            $rows = $this->rowLoader !== null
                ? call_user_func($this->rowLoader, $tenantId, $rowIds)
                : Db::name('online_daily_data')
                    ->where('tenant_id', $tenantId)
                    ->whereIn('id', $rowIds)
                    ->select()
                    ->toArray();
        } catch (\Throwable) {
            return false;
        }
        return is_array($rows) && $this->verifyRows(
            $platform,
            $tenantId,
            $sourceId,
            $hotelId,
            $targetDate,
            $dataPeriod,
            $readback,
            $rows
        );
    }

    /** @param array<string,mixed> $readback @param array<int,mixed> $rows */
    public function verifyRows(
        string $platform,
        int $tenantId,
        int $sourceId,
        int $hotelId,
        string $targetDate,
        string $dataPeriod,
        array $readback,
        array $rows
    ): bool {
        $platform = strtolower(trim($platform));
        $rowIds = $this->positiveIds($readback['row_ids'] ?? []);
        if (!$this->identityReady(
            $platform,
            $tenantId,
            $sourceId,
            $hotelId,
            $targetDate,
            $dataPeriod,
            $readback,
            $rowIds
        )) {
            return false;
        }
        $syncTaskId = (int)$readback['sync_task_id'];
        $rowIdSet = array_fill_keys($rowIds, true);
        $exactRows = array_values(array_filter($rows, static function (mixed $row) use (
            $rowIdSet,
            $syncTaskId,
            $sourceId,
            $tenantId,
            $hotelId,
            $platform,
            $targetDate,
            $dataPeriod
        ): bool {
            if (!is_array($row) || !isset($rowIdSet[(int)($row['id'] ?? 0)])) {
                return false;
            }
            $rowPlatform = strtolower(trim((string)($row['platform'] ?? $row['source'] ?? '')));
            return (int)($row['sync_task_id'] ?? 0) === $syncTaskId
                && (int)($row['data_source_id'] ?? 0) === $sourceId
                && (int)($row['tenant_id'] ?? 0) === $tenantId
                && (int)($row['system_hotel_id'] ?? 0) === $hotelId
                && substr(trim((string)($row['data_date'] ?? '')), 0, 10) === $targetDate
                && strtolower(trim((string)($row['data_period'] ?? ''))) === $dataPeriod
                && $rowPlatform === $platform
                && (int)($row['readback_verified'] ?? 0) === 1;
        }));
        if (count($exactRows) !== count($rowIds)) {
            return false;
        }
        $eligibleRows = OtaOrderedCollectionPlanner::storedCoreRows($platform, $exactRows);
        return $eligibleRows !== []
            && OtaOrderedCollectionPlanner::missingFieldKeys($platform, $eligibleRows) === [];
    }

    /** @param array<string,mixed> $readback @param list<int> $rowIds */
    private function identityReady(
        string $platform,
        int $tenantId,
        int $sourceId,
        int $hotelId,
        string $targetDate,
        string $dataPeriod,
        array $readback,
        array $rowIds
    ): bool {
        return in_array($platform, ['ctrip', 'meituan'], true)
            && $tenantId > 0
            && $sourceId > 0
            && $hotelId > 0
            && $dataPeriod === 'historical_daily'
            && ($readback['readback_verified'] ?? false) === true
            && (int)($readback['sync_task_id'] ?? 0) > 0
            && (int)($readback['data_source_id'] ?? 0) === $sourceId
            && (int)($readback['system_hotel_id'] ?? 0) === $hotelId
            && strtolower(trim((string)($readback['platform'] ?? ''))) === $platform
            && substr(trim((string)($readback['target_date'] ?? '')), 0, 10) === $targetDate
            && strtolower(trim((string)($readback['data_period'] ?? ''))) === $dataPeriod
            && $rowIds !== []
            && $this->safeTraceIds($readback['source_trace_ids'] ?? []) !== [];
    }

    /** @return list<int> */
    private function positiveIds(mixed $values): array
    {
        if (!is_array($values)) return [];
        $ids = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): int => max(0, (int)$value),
            $values
        ))));
        sort($ids, SORT_NUMERIC);
        return count($ids) <= 500 ? $ids : [];
    }

    /** @return list<string> */
    private function safeTraceIds(mixed $values): array
    {
        if (!is_array($values)) return [];
        return array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            $values
        ), static fn(string $value): bool =>
            preg_match('/^[A-Za-z0-9._:-]{1,160}$/D', $value) === 1
        )));
    }
}
