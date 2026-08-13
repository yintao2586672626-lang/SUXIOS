<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;
use Throwable;

class SimulationExecutionBridgeService
{
    private const SOURCE_TABLES = [
        'strategy_simulation' => 'strategy_simulation_records',
        'quant_simulation' => 'quant_simulation_records',
    ];

    private const ACTIVE_INTENT_STATUSES = ['draft', 'pending_approval', 'approved'];

    public function attachToRecord(array $record, string $sourceModule, array $hotelIds = []): array
    {
        $records = $this->attachToRecords([$record], $sourceModule, $hotelIds);

        return $records[0] ?? $record;
    }

    public function attachToRecords(array $records, string $sourceModule, array $hotelIds = []): array
    {
        $sourceModule = SourceBackedExecutionIntentIdentityService::canonicalSourceModule($sourceModule);
        $recordIds = $this->recordIds($records);
        if ($recordIds === [] || !isset(self::SOURCE_TABLES[$sourceModule])) {
            return $this->markRowsWithoutBridgeStatus($records, 'not_linked');
        }

        try {
            $sourceScopes = $this->persistedSourceScopes($sourceModule, $recordIds, $hotelIds);
            foreach ($records as $index => $record) {
                if (!is_array($record)) {
                    continue;
                }
                $scope = $sourceScopes[$this->recordId($record)] ?? null;
                if (!is_array($scope)) {
                    continue;
                }
                $records[$index]['_execution_source_tenant_id'] = (int)$scope['tenant_id'];
                $records[$index]['_execution_source_hotel_id'] = (int)$scope['hotel_id'];
            }

            $query = Db::name('operation_execution_intents')
                ->whereRaw('LOWER(TRIM(source_module)) = ?', [$sourceModule])
                ->whereIn('source_record_id', $recordIds)
                ->whereNull('deleted_at')
                ->order('id', 'desc');

            $hotelIds = $this->positiveInts($hotelIds);
            if ($hotelIds !== []) {
                $query->whereIn('hotel_id', $hotelIds);
            }

            $intentRows = $query->select()->toArray();
        } catch (Throwable) {
            return $this->markRowsWithoutBridgeStatus($records, 'not_loaded');
        }

        return $this->attachRowsWithIntents($records, $intentRows, $sourceModule);
    }

    public function attachRowsWithIntents(array $records, array $intentRows, string $sourceModule): array
    {
        $sourceModule = SourceBackedExecutionIntentIdentityService::canonicalSourceModule($sourceModule);
        foreach ($records as $index => $record) {
            if (!is_array($record)) {
                continue;
            }

            $recordId = $this->recordId($record);
            $sourceTenantId = (int)($record['_execution_source_tenant_id'] ?? 0);
            $sourceHotelId = (int)($record['_execution_source_hotel_id'] ?? 0);
            $record = $this->withoutBridge($record, 'not_linked');
            $intent = $this->latestIntentForRecord(
                $intentRows,
                $sourceModule,
                $recordId,
                $sourceTenantId,
                $sourceHotelId
            );
            if (is_array($intent)) {
                $records[$index] = $this->withBridge($record, $intent);
                continue;
            }

            $records[$index] = $record;
        }

        return $records;
    }

    private function latestIntentForRecord(
        array $intentRows,
        string $sourceModule,
        int $recordId,
        int $sourceTenantId,
        int $sourceHotelId
    ): ?array
    {
        $latest = null;
        foreach ($intentRows as $intent) {
            if (!is_array($intent)) {
                continue;
            }

            if (SourceBackedExecutionIntentIdentityService::canonicalSourceModule($intent['source_module'] ?? null) !== $sourceModule) {
                continue;
            }

            $intentId = (int)($intent['id'] ?? 0);
            if ($recordId <= 0
                || $sourceTenantId <= 0
                || $sourceHotelId <= 0
                || (int)($intent['source_record_id'] ?? 0) !== $recordId
                || $intentId <= 0
                || (int)($intent['tenant_id'] ?? 0) !== $sourceTenantId
                || (int)($intent['hotel_id'] ?? 0) !== $sourceHotelId
                || !in_array(strtolower(trim((string)($intent['status'] ?? ''))), self::ACTIVE_INTENT_STATUSES, true)
                || (isset($intent['deleted_at']) && trim((string)$intent['deleted_at']) !== '')
            ) {
                continue;
            }

            if ($latest === null || $intentId > (int)($latest['id'] ?? 0)) {
                $latest = $intent;
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
            '_source_bridge_verified' => true,
            'intent_id' => $intentId,
            'source_module' => SourceBackedExecutionIntentIdentityService::canonicalSourceModule($intent['source_module'] ?? null),
            'source_record_id' => (int)($intent['source_record_id'] ?? 0),
            'hotel_id' => (int)($intent['hotel_id'] ?? 0),
            'tenant_id' => (int)($intent['tenant_id'] ?? 0),
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
            if (!is_array($record)) {
                continue;
            }

            $records[$index] = $this->withoutBridge($record, $status);
        }

        return $records;
    }

    private function withoutBridge(array $record, string $status): array
    {
        unset(
            $record['_execution_source_tenant_id'],
            $record['_execution_source_hotel_id'],
            $record['execution_intent_id'],
            $record['operation_execution_intent_id'],
            $record['execution_task_id'],
            $record['opening_project_id'],
            $record['tracking_record_id'],
            $record['post_decision_tracking_id'],
            $record['investment_tracking_id'],
            $record['post_decision_tracking'],
            $record['execution_tracking']
        );
        $record['execution_bridge_status'] = $status;

        if (is_array($record['execution_readiness'] ?? null)) {
            $readiness = $record['execution_readiness'];
            $readiness['execution_ready'] = false;
            if ((string)($readiness['stage'] ?? '') === 'execution_ready') {
                $readiness['stage'] = 'approved_pending_execution';
            }
            foreach ((array)($readiness['checks'] ?? []) as $index => $check) {
                if (!is_array($check) || (string)($check['key'] ?? '') !== 'execution_bridge') {
                    continue;
                }
                $readiness['checks'][$index]['passed'] = false;
                $readiness['checks'][$index]['status'] = 'missing';
            }
            $record['execution_readiness'] = $readiness;
        }

        return $record;
    }

    /** @param array<int,int> $recordIds @param array<int,int|string> $permittedHotelIds @return array<int,array{tenant_id:int,hotel_id:int}> */
    private function persistedSourceScopes(string $sourceModule, array $recordIds, array $permittedHotelIds): array
    {
        $table = self::SOURCE_TABLES[$sourceModule] ?? '';
        if ($table === '') {
            return [];
        }
        $fields = $sourceModule === 'strategy_simulation'
            ? 'id,tenant_id,input_json,data_snapshot_json'
            : 'id,tenant_id,input_json,result_json';
        $rows = Db::name($table)
            ->whereIn('id', $recordIds)
            ->whereNull('deleted_at')
            ->field($fields)
            ->select()
            ->toArray();

        $permitted = array_fill_keys($this->positiveInts($permittedHotelIds), true);
        $candidates = [];
        $hotelIds = [];
        foreach ($rows as $row) {
            $sourceId = (int)($row['id'] ?? 0);
            $tenantId = (int)($row['tenant_id'] ?? 0);
            $hotelCandidates = [];
            foreach (['input_json', 'data_snapshot_json', 'result_json'] as $field) {
                $payload = $this->decodeJson($row[$field] ?? null);
                foreach (['hotel_id', 'system_hotel_id', 'target_hotel_id'] as $hotelField) {
                    $hotelId = (int)($payload[$hotelField] ?? 0);
                    if ($hotelId > 0) {
                        $hotelCandidates[$hotelId] = true;
                    }
                }
            }
            if ($sourceId <= 0 || $tenantId <= 0 || count($hotelCandidates) !== 1) {
                continue;
            }
            $hotelId = (int)array_key_first($hotelCandidates);
            if ($permitted !== [] && !isset($permitted[$hotelId])) {
                continue;
            }
            $candidates[$sourceId] = ['tenant_id' => $tenantId, 'hotel_id' => $hotelId];
            $hotelIds[$hotelId] = true;
        }
        if ($candidates === []) {
            return [];
        }

        $hotelTenants = [];
        foreach (Db::name('hotels')->whereIn('id', array_keys($hotelIds))->field('id,tenant_id')->select()->toArray() as $hotel) {
            $hotelId = (int)($hotel['id'] ?? 0);
            $tenantId = (int)($hotel['tenant_id'] ?? 0);
            if ($hotelId > 0 && $tenantId > 0) {
                $hotelTenants[$hotelId] = $tenantId;
            }
        }
        foreach ($candidates as $sourceId => $scope) {
            if ((int)($hotelTenants[$scope['hotel_id']] ?? 0) !== $scope['tenant_id']) {
                unset($candidates[$sourceId]);
            }
        }

        return $candidates;
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
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
