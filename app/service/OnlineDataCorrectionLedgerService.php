<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;
use think\facade\Db;

final class OnlineDataCorrectionLedgerService
{
    private const DATA_TABLE = 'online_daily_data';
    private const LEDGER_TABLE = 'online_data_correction_ledger';

    /**
     * @param array<string, mixed> $updates
     * @param array<int, int>|null $permittedHotelIds null means super-admin scope
     * @return array<string, mixed>
     */
    public function update(
        int $onlineDataId,
        array $updates,
        int $operatorId,
        ?array $permittedHotelIds,
        string $reason = 'manual_correction'
    ): array {
        if ($onlineDataId <= 0 || $updates === []) {
            throw new RuntimeException('online_data_correction_input_invalid');
        }

        return Db::transaction(function () use ($onlineDataId, $updates, $operatorId, $permittedHotelIds, $reason): array {
            $before = $this->findAuthorizedRow($onlineDataId, $permittedHotelIds, true);
            $affected = Db::name(self::DATA_TABLE)->where('id', $onlineDataId)->update($updates);
            if ($affected !== 1) {
                throw new RuntimeException('online_data_update_not_applied');
            }
            $after = $this->findAuthorizedRow($onlineDataId, $permittedHotelIds, false);
            foreach ($updates as $field => $expected) {
                if (!$this->valuesMatch($expected, $after[$field] ?? null)) {
                    throw new RuntimeException('online_data_update_readback_mismatch:' . $field);
                }
            }

            $ledgerId = $this->insertLedger(
                $before,
                $after,
                'update',
                array_keys($updates),
                $operatorId,
                $reason,
                false
            );
            return ['row' => $after, 'ledger_id' => $ledgerId];
        });
    }

    /**
     * @param array<int, int>|null $permittedHotelIds
     * @return array<string, mixed>
     */
    public function delete(
        int $onlineDataId,
        int $operatorId,
        ?array $permittedHotelIds,
        string $reason = 'manual_delete'
    ): array {
        if ($onlineDataId <= 0) {
            throw new RuntimeException('online_data_id_invalid');
        }

        return Db::transaction(function () use ($onlineDataId, $operatorId, $permittedHotelIds, $reason): array {
            $before = $this->findAuthorizedRow($onlineDataId, $permittedHotelIds, true);
            $ledgerId = $this->insertLedger(
                $before,
                null,
                'delete',
                array_keys($before),
                $operatorId,
                $reason,
                true
            );
            $deleted = Db::name(self::DATA_TABLE)->where('id', $onlineDataId)->delete();
            if ($deleted !== 1) {
                throw new RuntimeException('online_data_delete_not_applied');
            }
            if (Db::name(self::DATA_TABLE)->where('id', $onlineDataId)->find()) {
                throw new RuntimeException('online_data_delete_readback_mismatch');
            }
            return ['id' => $onlineDataId, 'ledger_id' => $ledgerId];
        });
    }

    /**
     * @param array<int, int> $onlineDataIds
     * @param array<int, int>|null $permittedHotelIds
     * @return array<string, mixed>
     */
    public function batchDelete(
        array $onlineDataIds,
        int $operatorId,
        ?array $permittedHotelIds,
        string $reason = 'manual_batch_delete'
    ): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $onlineDataIds), static fn(int $id): bool => $id > 0)));
        sort($ids);
        if ($ids === []) {
            throw new RuntimeException('online_data_ids_invalid');
        }

        return Db::transaction(function () use ($ids, $operatorId, $permittedHotelIds, $reason): array {
            $query = Db::name(self::DATA_TABLE)->whereIn('id', $ids)->lock(true);
            if ($permittedHotelIds !== null) {
                $query->whereIn('system_hotel_id', $this->normalizeHotelIds($permittedHotelIds));
            }
            $rows = $query->select()->toArray();
            $foundIds = array_map('intval', array_column($rows, 'id'));
            sort($foundIds);
            if ($foundIds !== $ids) {
                throw new RuntimeException('online_data_batch_contains_missing_or_forbidden_rows');
            }

            $ledgerIds = [];
            foreach ($rows as $row) {
                $ledgerIds[] = $this->insertLedger(
                    $row,
                    null,
                    'delete',
                    array_keys($row),
                    $operatorId,
                    $reason,
                    true
                );
            }
            $deleted = Db::name(self::DATA_TABLE)->whereIn('id', $ids)->delete();
            if ($deleted !== count($ids)) {
                throw new RuntimeException('online_data_batch_delete_count_mismatch');
            }
            if ((int)Db::name(self::DATA_TABLE)->whereIn('id', $ids)->count() !== 0) {
                throw new RuntimeException('online_data_batch_delete_readback_mismatch');
            }
            return ['deleted_count' => $deleted, 'ids' => $ids, 'ledger_ids' => $ledgerIds];
        });
    }

    /**
     * @param array<int, int>|null $permittedHotelIds
     * @return array<string, mixed>
     */
    public function restore(
        int $ledgerId,
        int $operatorId,
        ?array $permittedHotelIds
    ): array {
        if ($ledgerId <= 0) {
            throw new RuntimeException('online_data_ledger_id_invalid');
        }

        return Db::transaction(function () use ($ledgerId, $operatorId, $permittedHotelIds): array {
            $ledger = Db::name(self::LEDGER_TABLE)->where('id', $ledgerId)->lock(true)->find();
            if (!$ledger || (string)($ledger['operation'] ?? '') !== 'delete' || (int)($ledger['restorable'] ?? 0) !== 1) {
                throw new RuntimeException('online_data_delete_ledger_not_restorable');
            }
            if (trim((string)($ledger['restored_at'] ?? '')) !== '') {
                throw new RuntimeException('online_data_delete_ledger_already_restored');
            }
            $hotelId = (int)($ledger['system_hotel_id'] ?? 0);
            if ($permittedHotelIds !== null && !in_array($hotelId, $this->normalizeHotelIds($permittedHotelIds), true)) {
                throw new RuntimeException('online_data_restore_forbidden');
            }

            $before = json_decode((string)($ledger['before_json'] ?? ''), true);
            if (!is_array($before) || (int)($before['id'] ?? 0) <= 0) {
                throw new RuntimeException('online_data_restore_snapshot_invalid');
            }
            $onlineDataId = (int)$before['id'];
            if (Db::name(self::DATA_TABLE)->where('id', $onlineDataId)->find()) {
                throw new RuntimeException('online_data_restore_id_conflict');
            }
            Db::name(self::DATA_TABLE)->insert($before);
            $restored = Db::name(self::DATA_TABLE)->where('id', $onlineDataId)->find();
            if (!$restored || !$this->snapshotMatches($before, $restored)) {
                throw new RuntimeException('online_data_restore_readback_mismatch');
            }

            $now = date('Y-m-d H:i:s');
            $updated = Db::name(self::LEDGER_TABLE)
                ->where('id', $ledgerId)
                ->whereNull('restored_at')
                ->update(['restored_at' => $now, 'restored_by' => $operatorId]);
            if ($updated !== 1) {
                throw new RuntimeException('online_data_restore_ledger_update_failed');
            }
            return ['id' => $onlineDataId, 'ledger_id' => $ledgerId, 'restored_at' => $now];
        });
    }

    /**
     * @param array<int, int>|null $permittedHotelIds
     * @return array<string, mixed>
     */
    private function findAuthorizedRow(int $onlineDataId, ?array $permittedHotelIds, bool $lock): array
    {
        $query = Db::name(self::DATA_TABLE)->where('id', $onlineDataId);
        if ($permittedHotelIds !== null) {
            $query->whereIn('system_hotel_id', $this->normalizeHotelIds($permittedHotelIds));
        }
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        if (!$row) {
            throw new RuntimeException('online_data_missing_or_forbidden');
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed>|null $after
     * @param array<int, string> $changedFields
     */
    private function insertLedger(
        array $before,
        ?array $after,
        string $operation,
        array $changedFields,
        int $operatorId,
        string $reason,
        bool $restorable
    ): int {
        $ledgerId = (int)Db::name(self::LEDGER_TABLE)->insertGetId([
            'online_data_id' => (int)($before['id'] ?? 0),
            'tenant_id' => isset($before['tenant_id']) ? (int)$before['tenant_id'] : null,
            'system_hotel_id' => (int)($before['system_hotel_id'] ?? 0),
            'operator_id' => $operatorId,
            'operation' => $operation,
            'changed_fields_json' => $this->encodeJson(array_values($changedFields)),
            'before_json' => $this->encodeJson($before),
            'after_json' => $after !== null ? $this->encodeJson($after) : null,
            'reason' => mb_substr(trim($reason), 0, 255),
            'restorable' => $restorable ? 1 : 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        if ($ledgerId <= 0) {
            throw new RuntimeException('online_data_correction_ledger_write_failed');
        }
        return $ledgerId;
    }

    /** @param array<int, int> $hotelIds @return array<int, int> */
    private function normalizeHotelIds(array $hotelIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $hotelIds), static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            throw new RuntimeException('online_data_hotel_scope_empty');
        }
        return $ids;
    }

    private function encodeJson(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
    }

    private function valuesMatch(mixed $expected, mixed $actual): bool
    {
        if (is_numeric($expected) && is_numeric($actual)) {
            return abs((float)$expected - (float)$actual) < 0.000001;
        }
        return $expected === $actual || (string)$expected === (string)$actual;
    }

    /** @param array<string, mixed> $expected @param array<string, mixed> $actual */
    private function snapshotMatches(array $expected, array $actual): bool
    {
        foreach ($expected as $field => $value) {
            if (!$this->valuesMatch($value, $actual[$field] ?? null)) {
                return false;
            }
        }
        return true;
    }
}
