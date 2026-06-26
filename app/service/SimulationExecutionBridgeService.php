<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;
use Throwable;

class SimulationExecutionBridgeService
{
    public function attachToRecord(array $record, string $sourceModule, array $hotelIds = []): array
    {
        $records = $this->attachToRecords([$record], $sourceModule, $hotelIds);

        return $records[0] ?? $record;
    }

    public function attachToRecords(array $records, string $sourceModule, array $hotelIds = []): array
    {
        $recordIds = $this->recordIds($records);
        if ($recordIds === []) {
            return $records;
        }

        try {
            $query = Db::name('operation_execution_intents')
                ->where('source_module', $sourceModule)
                ->whereIn('source_record_id', $recordIds)
                ->whereNull('deleted_at')
                ->order('id', 'desc');

            $hotelIds = $this->positiveInts($hotelIds);
            if ($hotelIds !== []) {
                $query->whereIn('hotel_id', $hotelIds);
            }

            $intentRows = $query->select()->toArray();
        } catch (Throwable $e) {
            return $this->markRowsWithoutBridgeStatus($records, 'not_loaded');
        }

        return $this->attachRowsWithIntents($records, $intentRows, $sourceModule);
    }

    public function attachRowsWithIntents(array $records, array $intentRows, string $sourceModule): array
    {
        $latestByRecordId = $this->latestIntentByRecordId($intentRows, $sourceModule);

        foreach ($records as $index => $record) {
            if (!is_array($record)) {
                continue;
            }

            $recordId = $this->recordId($record);
            $intent = $latestByRecordId[$recordId] ?? null;
            if (is_array($intent)) {
                $records[$index] = $this->withBridge($record, $intent);
                continue;
            }

            if ($this->hasExistingBridge($record)) {
                continue;
            }

            $record['execution_bridge_status'] = 'not_linked';
            $records[$index] = $record;
        }

        return $records;
    }

    private function latestIntentByRecordId(array $intentRows, string $sourceModule): array
    {
        $latest = [];
        foreach ($intentRows as $intent) {
            if (!is_array($intent)) {
                continue;
            }

            if ((string)($intent['source_module'] ?? '') !== $sourceModule) {
                continue;
            }

            $recordId = (int)($intent['source_record_id'] ?? 0);
            $intentId = (int)($intent['id'] ?? 0);
            if ($recordId <= 0 || $intentId <= 0) {
                continue;
            }

            if (!isset($latest[$recordId]) || $intentId > (int)($latest[$recordId]['id'] ?? 0)) {
                $latest[$recordId] = $intent;
            }
        }

        return $latest;
    }

    private function withBridge(array $record, array $intent): array
    {
        $intentId = (int)$intent['id'];
        $record['execution_intent_id'] = $intentId;
        $record['operation_execution_intent_id'] = $intentId;
        $record['execution_bridge_status'] = 'linked';
        $record['execution_tracking'] = [
            'intent_id' => $intentId,
            'source_module' => (string)($intent['source_module'] ?? ''),
            'source_record_id' => (int)($intent['source_record_id'] ?? 0),
            'status' => (string)($intent['status'] ?? ''),
            'blocked_reason' => (string)($intent['blocked_reason'] ?? ''),
            'created_at' => (string)($intent['created_at'] ?? ''),
            'updated_at' => (string)($intent['updated_at'] ?? ''),
        ];

        return $record;
    }

    private function markRowsWithoutBridgeStatus(array $records, string $status): array
    {
        foreach ($records as $index => $record) {
            if (!is_array($record) || $this->hasExistingBridge($record)) {
                continue;
            }

            $record['execution_bridge_status'] = $status;
            $records[$index] = $record;
        }

        return $records;
    }

    private function hasExistingBridge(array $record): bool
    {
        return (int)($record['execution_intent_id'] ?? 0) > 0
            || (int)($record['operation_execution_intent_id'] ?? 0) > 0
            || (string)($record['execution_bridge_status'] ?? '') === 'linked';
    }

    private function recordIds(array $records): array
    {
        $ids = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            $recordId = $this->recordId($record);
            if ($recordId > 0) {
                $ids[] = $recordId;
            }
        }

        return array_values(array_unique($ids));
    }

    private function recordId(array $record): int
    {
        return (int)($record['id'] ?? $record['record_id'] ?? 0);
    }

    private function positiveInts(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            $number = (int)$value;
            if ($number > 0) {
                $result[] = $number;
            }
        }

        return array_values(array_unique($result));
    }
}
