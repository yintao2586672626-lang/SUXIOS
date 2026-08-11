<?php
declare(strict_types=1);

namespace app\service;

use app\service\operation\ExecutionOutcomeService;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

/**
 * Resolves the one durable platform owner for a hotel's canonical daily checks.
 *
 * The four persisted operation triplets are the authority. Cache state is never
 * consulted, and an incomplete or drifting set is blocked rather than repaired
 * or replaced by another platform.
 */
final class CanonicalOtaDailyPlatformSelectionService
{
    public const SCHEMA_VERSION = 'canonical_ota_daily_platform_selection.v1';
    public const POLICY = 'ctrip_preferred_else_meituan_sticky';
    public const POLICY_VERSION = 'v1';

    /** @return array<string,mixed> */
    public function resolve(
        int $tenantId,
        int $hotelId,
        string $targetDate,
        string $period = 'historical_daily'
    ): array {
        $ownerScope = $this->normalizeOwnerScope($tenantId, $hotelId, $targetDate, $period);
        return $this->resolveOwner($ownerScope, false);
    }

    /**
     * Locks the exact hotel owner row and re-reads the durable owner inside the
     * same transaction. A caller may persist only when this returns claimable,
     * or replay only when its complete source scope exactly equals the owner.
     *
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    public function assertScopeMayPersist(array $scope): array
    {
        $scope = $this->normalizeExactScope($scope);
        $ownerScope = $this->ownerScopeFromExactScope($scope);

        return Db::transaction(function () use ($scope, $ownerScope): array {
            $hotel = Db::name('hotels')
                ->where('tenant_id', $ownerScope['tenant_id'])
                ->where('id', $ownerScope['hotel_id'])
                ->lock(true)
                ->find();
            if (!is_array($hotel)
                || (int)($hotel['id'] ?? 0) !== $ownerScope['hotel_id']
                || (int)($hotel['tenant_id'] ?? 0) !== $ownerScope['tenant_id']
            ) {
                throw new RuntimeException('canonical_daily_platform_selection_hotel_scope_invalid');
            }

            $resolved = $this->resolveOwner($ownerScope, true);
            if (($resolved['status'] ?? '') === 'none') {
                return [
                    'status' => 'claimable',
                    'claimable' => true,
                    'replay' => false,
                    'scope' => $scope,
                    'owner_scope' => $ownerScope,
                    'selection_receipt' => null,
                ];
            }
            if (($resolved['status'] ?? '') !== 'selected'
                || !is_array($resolved['scope'] ?? null)
            ) {
                throw new RuntimeException('canonical_daily_platform_selection_resolution_invalid');
            }
            if ($resolved['scope'] !== $scope) {
                throw new RuntimeException('canonical_daily_platform_selection_owner_scope_conflict');
            }

            return [
                'status' => 'replay',
                'claimable' => false,
                'replay' => true,
                'scope' => $resolved['scope'],
                'owner_scope' => $ownerScope,
                'selection_receipt' => $resolved['selection_receipt'],
            ];
        });
    }

    /**
     * @param array{tenant_id:int,hotel_id:int,target_date:string,data_period:string} $ownerScope
     * @return array<string,mixed>
     */
    private function resolveOwner(array $ownerScope, bool $lock): array
    {
        $query = Db::name('operation_execution_intents')
            ->where('tenant_id', $ownerScope['tenant_id'])
            ->where('hotel_id', $ownerScope['hotel_id'])
            ->where('source_module', CanonicalOtaInvestigationActionService::SOURCE_MODULE)
            ->where('object_type', 'operation_checklist')
            ->where('date_start', $ownerScope['target_date'])
            ->where('date_end', $ownerScope['target_date'])
            ->order('id', 'asc');
        if ($lock) {
            $query->lock(true);
        }
        $candidateRows = $query->select()->toArray();

        $rows = [];
        foreach ($candidateRows as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('canonical_daily_platform_selection_intent_invalid');
            }
            $intentEvidence = $this->decodeJson(
                $row['evidence_json'] ?? null,
                'canonical_daily_platform_selection_intent_evidence_invalid'
            );
            $period = strtolower(trim((string)($intentEvidence['data_period'] ?? '')));
            if (preg_match('/^[a-z0-9_]{1,40}$/D', $period) !== 1) {
                throw new RuntimeException('canonical_daily_platform_selection_intent_period_invalid');
            }
            if ($period === $ownerScope['data_period']) {
                $row['_selection_intent_evidence'] = $intentEvidence;
                $rows[] = $row;
            }
        }

        if ($rows === []) {
            return [
                'status' => 'none',
                'selected' => false,
                'scope' => null,
                'owner_scope' => $ownerScope,
                'selection_receipt' => null,
            ];
        }
        if (count($rows) !== 4) {
            throw new RuntimeException('canonical_daily_platform_selection_intent_membership_invalid');
        }

        $scope = null;
        $platform = '';
        $actionSetDigest = '';
        $actionTypes = [];
        $intentIds = [];
        $triplets = [];
        $ownerMetadataStates = [];
        foreach ($rows as $row) {
            if (trim((string)($row['deleted_at'] ?? '')) !== '') {
                throw new RuntimeException('canonical_daily_platform_selection_intent_deleted');
            }
            $intentEvidence = $row['_selection_intent_evidence'];
            unset($row['_selection_intent_evidence']);
            $rowScope = $this->exactScopeFromIntent($row, $intentEvidence, $ownerScope);
            if ($scope === null) {
                $scope = $rowScope;
                $platform = $rowScope['platform'];
                $actionSetDigest = strtolower(trim((string)($intentEvidence['action_set_digest'] ?? '')));
            } elseif ($rowScope !== $scope) {
                throw new RuntimeException('canonical_daily_platform_selection_exact_scope_mismatch');
            }

            $rowActionSetDigest = strtolower(trim((string)($intentEvidence['action_set_digest'] ?? '')));
            if (!$this->isDigest($rowActionSetDigest)
                || !hash_equals($actionSetDigest, $rowActionSetDigest)
            ) {
                throw new RuntimeException('canonical_daily_platform_selection_action_set_mismatch');
            }

            $intentId = (int)($row['id'] ?? 0);
            $actionType = trim((string)($row['action_type'] ?? ''));
            if ($intentId <= 0 || $actionType === '') {
                throw new RuntimeException('canonical_daily_platform_selection_intent_identity_invalid');
            }

            [$task, $evidence] = $this->exactTripletMembers($intentId, $lock);
            $this->assertEvidenceTruth($row, $intentEvidence, $task, $evidence);

            $intentIds[] = $intentId;
            $actionTypes[] = $actionType;
            $triplets[] = [
                'intent_id' => $intentId,
                'task_id' => (int)($task['id'] ?? 0),
                'evidence_id' => (int)($evidence['id'] ?? 0),
                'action_type' => $actionType,
            ];
            $ownerMetadataStates[] = $this->ownerMetadataState(
                $intentEvidence,
                $ownerScope,
                $platform
            );
        }

        if (!is_array($scope) || $platform === '') {
            throw new RuntimeException('canonical_daily_platform_selection_scope_missing');
        }
        $expectedActionTypes = $this->actionTypesForPlatform($platform);
        sort($actionTypes, SORT_STRING);
        if ($actionTypes !== $expectedActionTypes || count(array_unique($actionTypes)) !== 4) {
            throw new RuntimeException('canonical_daily_platform_selection_action_types_invalid');
        }
        sort($intentIds, SORT_NUMERIC);
        usort($triplets, static fn(array $left, array $right): int =>
            $left['intent_id'] <=> $right['intent_id']);
        $metadataStates = array_values(array_unique($ownerMetadataStates));
        if (count($metadataStates) !== 1) {
            throw new RuntimeException('canonical_daily_platform_selection_owner_metadata_mixed');
        }

        $receipt = $this->selectionReceipt(
            $ownerScope,
            $scope,
            $platform,
            $actionSetDigest,
            $intentIds,
            $triplets,
            $metadataStates[0]
        );
        return [
            'status' => 'selected',
            'selected' => true,
            'platform' => $platform,
            'scope' => $scope,
            'owner_scope' => $ownerScope,
            'selection_receipt' => $receipt,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $intentEvidence
     * @param array{tenant_id:int,hotel_id:int,target_date:string,data_period:string} $ownerScope
     * @return array<string,mixed>
     */
    private function exactScopeFromIntent(array $row, array $intentEvidence, array $ownerScope): array
    {
        $scope = [
            'tenant_id' => (int)($intentEvidence['tenant_id'] ?? 0),
            'hotel_id' => (int)($intentEvidence['hotel_id'] ?? 0),
            'data_source_id' => (int)($intentEvidence['data_source_id'] ?? 0),
            'task_id' => (int)($intentEvidence['sync_task_id'] ?? 0),
            'row_id' => (int)($intentEvidence['row_id'] ?? 0),
            'platform' => strtolower(trim((string)($intentEvidence['platform'] ?? ''))),
            'target_date' => substr(trim((string)($intentEvidence['target_date'] ?? '')), 0, 10),
            'data_period' => strtolower(trim((string)($intentEvidence['data_period'] ?? ''))),
        ];
        $scope = $this->normalizeExactScope($scope);
        if ($this->ownerScopeFromExactScope($scope) !== $ownerScope
            || (int)($row['tenant_id'] ?? 0) !== $scope['tenant_id']
            || (int)($row['hotel_id'] ?? 0) !== $scope['hotel_id']
            || (int)($row['source_record_id'] ?? 0) !== $scope['row_id']
            || strtolower(trim((string)($row['platform'] ?? ''))) !== $scope['platform']
            || substr(trim((string)($row['date_start'] ?? '')), 0, 10) !== $scope['target_date']
            || substr(trim((string)($row['date_end'] ?? '')), 0, 10) !== $scope['target_date']
        ) {
            throw new RuntimeException('canonical_daily_platform_selection_intent_scope_invalid');
        }
        return $scope;
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function exactTripletMembers(int $intentId, bool $lock): array
    {
        $taskQuery = Db::name('operation_execution_tasks')
            ->where('intent_id', $intentId)
            ->order('id', 'asc');
        if ($lock) {
            $taskQuery->lock(true);
        }
        $tasks = $taskQuery->select()->toArray();
        if (count($tasks) !== 1
            || !is_array($tasks[0])
            || trim((string)($tasks[0]['deleted_at'] ?? '')) !== ''
        ) {
            throw new RuntimeException('canonical_daily_platform_selection_task_membership_invalid');
        }
        $task = $tasks[0];
        $taskId = (int)($task['id'] ?? 0);
        if ($taskId <= 0) {
            throw new RuntimeException('canonical_daily_platform_selection_task_identity_invalid');
        }

        $evidenceQuery = Db::name('operation_execution_evidence')
            ->where('task_id', $taskId)
            ->order('id', 'asc');
        if ($lock) {
            $evidenceQuery->lock(true);
        }
        $evidenceRows = $evidenceQuery->select()->toArray();
        if (count($evidenceRows) !== 1
            || !is_array($evidenceRows[0])
            || trim((string)($evidenceRows[0]['deleted_at'] ?? '')) !== ''
        ) {
            throw new RuntimeException('canonical_daily_platform_selection_evidence_membership_invalid');
        }
        return [$task, $evidenceRows[0]];
    }

    /**
     * @param array<string,mixed> $intent
     * @param array<string,mixed> $intentEvidence
     * @param array<string,mixed> $task
     * @param array<string,mixed> $evidence
     */
    private function assertEvidenceTruth(
        array $intent,
        array $intentEvidence,
        array $task,
        array $evidence
    ): void {
        $normalizedIntent = $intent;
        $normalizedIntent['current_value'] = $this->decodeJson(
            $intent['current_value_json'] ?? null,
            'canonical_daily_platform_selection_current_value_invalid'
        );
        $normalizedIntent['target_value'] = $this->decodeJson(
            $intent['target_value_json'] ?? null,
            'canonical_daily_platform_selection_target_value_invalid'
        );
        $normalizedIntent['evidence'] = $intentEvidence;

        $normalizedTask = $task;
        $normalizedTask['current_value'] = $this->decodeJson(
            $task['current_value_json'] ?? null,
            'canonical_daily_platform_selection_task_current_value_invalid'
        );
        $normalizedTask['target_value'] = $this->decodeJson(
            $task['target_value_json'] ?? null,
            'canonical_daily_platform_selection_task_target_value_invalid'
        );

        $normalizedEvidence = $evidence;
        $normalizedEvidence['before'] = $this->decodeJson(
            $evidence['before_json'] ?? null,
            'canonical_daily_platform_selection_before_evidence_invalid'
        );
        $normalizedEvidence['after'] = $this->decodeJson(
            $evidence['after_json'] ?? null,
            'canonical_daily_platform_selection_after_evidence_invalid'
        );
        $normalizedEvidence['platform_response'] = $this->decodeJson(
            $evidence['platform_response_json'] ?? null,
            'canonical_daily_platform_selection_platform_response_invalid'
        );

        $truth = (new ExecutionOutcomeService())->assessExecutionEvidenceTruth(
            $normalizedIntent,
            $normalizedTask,
            $normalizedEvidence
        );
        if (($truth['source_verified'] ?? false) !== true) {
            throw new RuntimeException('canonical_daily_platform_selection_evidence_truth_unverified');
        }
    }

    /**
     * @param array<string,mixed> $intentEvidence
     * @param array{tenant_id:int,hotel_id:int,target_date:string,data_period:string} $ownerScope
     */
    private function ownerMetadataState(array $intentEvidence, array $ownerScope, string $platform): string
    {
        $fields = ['owner_scope_digest', 'owner_platform', 'selection_policy', 'selection_policy_version'];
        $present = array_values(array_filter(
            $fields,
            static fn(string $field): bool => array_key_exists($field, $intentEvidence)
        ));
        if ($present === []) {
            return 'legacy_four_intent_inference';
        }
        if (count($present) !== count($fields)
            || !hash_equals(
                $this->digest($ownerScope),
                strtolower(trim((string)($intentEvidence['owner_scope_digest'] ?? '')))
            )
            || strtolower(trim((string)($intentEvidence['owner_platform'] ?? ''))) !== $platform
            || trim((string)($intentEvidence['selection_policy'] ?? '')) !== self::POLICY
            || trim((string)($intentEvidence['selection_policy_version'] ?? '')) !== self::POLICY_VERSION
        ) {
            throw new RuntimeException('canonical_daily_platform_selection_owner_metadata_invalid');
        }
        return 'intent_evidence';
    }

    /**
     * @param array{tenant_id:int,hotel_id:int,target_date:string,data_period:string} $ownerScope
     * @param array<string,mixed> $scope
     * @param array<int,int> $intentIds
     * @param array<int,array{intent_id:int,task_id:int,evidence_id:int,action_type:string}> $triplets
     * @return array<string,mixed>
     */
    private function selectionReceipt(
        array $ownerScope,
        array $scope,
        string $platform,
        string $actionSetDigest,
        array $intentIds,
        array $triplets,
        string $ownerSource
    ): array {
        $policy = [
            'name' => self::POLICY,
            'version' => self::POLICY_VERSION,
            'preference' => ['ctrip', 'meituan'],
            'sticky_after_claim' => true,
        ];
        $receipt = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'selected',
            'selection_policy' => self::POLICY,
            'selection_policy_version' => self::POLICY_VERSION,
            'selection_policy_digest' => $this->digest($policy),
            'owner_scope' => $ownerScope,
            'owner_scope_digest' => $this->digest($ownerScope),
            'selected_platform' => $platform,
            'scope' => $scope,
            'intent_ids' => $intentIds,
            'triplets' => $triplets,
            'action_set_digest' => $actionSetDigest,
            'owner_source' => $ownerSource,
            'legacy_owner_inferred' => $ownerSource === 'legacy_four_intent_inference',
            'readback_verified' => true,
        ];
        $receipt['content_digest'] = $this->digest($receipt);
        return $receipt;
    }

    /** @return array<int,string> */
    private function actionTypesForPlatform(string $platform): array
    {
        if (!method_exists(CanonicalOtaInvestigationActionService::class, 'actionTypesForPlatform')) {
            throw new RuntimeException('canonical_daily_platform_selection_action_manifest_unavailable');
        }
        $declared = CanonicalOtaInvestigationActionService::actionTypesForPlatform($platform);
        if (!is_array($declared)) {
            throw new RuntimeException('canonical_daily_platform_selection_action_manifest_invalid');
        }
        $types = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            array_is_list($declared) ? $declared : array_values($declared)
        ), static fn(string $value): bool => $value !== '')));
        sort($types, SORT_STRING);
        if (count($types) !== 4) {
            throw new RuntimeException('canonical_daily_platform_selection_action_manifest_invalid');
        }
        return $types;
    }

    /** @param array<string,mixed> $scope @return array<string,mixed> */
    private function normalizeExactScope(array $scope): array
    {
        foreach (['tenant_id', 'hotel_id', 'data_source_id', 'task_id', 'row_id', 'platform', 'target_date', 'data_period'] as $field) {
            if (!array_key_exists($field, $scope)) {
                throw new InvalidArgumentException('canonical_daily_platform_selection_scope_field_missing:' . $field);
            }
        }
        $normalized = [
            'tenant_id' => $this->positiveInteger($scope['tenant_id'], 'tenant_id'),
            'hotel_id' => $this->positiveInteger($scope['hotel_id'], 'hotel_id'),
            'data_source_id' => $this->positiveInteger($scope['data_source_id'], 'data_source_id'),
            'task_id' => $this->positiveInteger($scope['task_id'], 'task_id'),
            'row_id' => $this->positiveInteger($scope['row_id'], 'row_id'),
            'platform' => strtolower(trim((string)$scope['platform'])),
            'target_date' => substr(trim((string)$scope['target_date']), 0, 10),
            'data_period' => strtolower(trim((string)$scope['data_period'])),
        ];
        if (!in_array($normalized['platform'], ['ctrip', 'meituan'], true)) {
            throw new InvalidArgumentException('canonical_daily_platform_selection_scope_platform_invalid');
        }
        $this->normalizeOwnerScope(
            $normalized['tenant_id'],
            $normalized['hotel_id'],
            $normalized['target_date'],
            $normalized['data_period']
        );
        return $normalized;
    }

    /**
     * @return array{tenant_id:int,hotel_id:int,target_date:string,data_period:string}
     */
    private function normalizeOwnerScope(
        int $tenantId,
        int $hotelId,
        string $targetDate,
        string $period
    ): array {
        $tenantId = $this->positiveInteger($tenantId, 'tenant_id');
        $hotelId = $this->positiveInteger($hotelId, 'hotel_id');
        $targetDate = substr(trim($targetDate), 0, 10);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $targetDate);
        $period = strtolower(trim($period));
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== $targetDate) {
            throw new InvalidArgumentException('canonical_daily_platform_selection_target_date_invalid');
        }
        if ($period !== 'historical_daily') {
            throw new InvalidArgumentException('canonical_daily_platform_selection_period_invalid');
        }
        return [
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'target_date' => $targetDate,
            'data_period' => $period,
        ];
    }

    /**
     * @param array<string,mixed> $scope
     * @return array{tenant_id:int,hotel_id:int,target_date:string,data_period:string}
     */
    private function ownerScopeFromExactScope(array $scope): array
    {
        return [
            'tenant_id' => (int)$scope['tenant_id'],
            'hotel_id' => (int)$scope['hotel_id'],
            'target_date' => (string)$scope['target_date'],
            'data_period' => (string)$scope['data_period'],
        ];
    }

    private function positiveInteger(mixed $value, string $field): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($validated === false) {
            throw new InvalidArgumentException('canonical_daily_platform_selection_positive_integer_required:' . $field);
        }
        return (int)$validated;
    }

    /** @return array<string,mixed> */
    private function decodeJson(mixed $value, string $error): array
    {
        if (is_array($value)) {
            return $value;
        }
        try {
            $decoded = json_decode((string)$value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException($error, 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException($error);
        }
        return $decoded;
    }

    private function isDigest(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', strtolower(trim($value))) === 1;
    }

    /** @param array<string,mixed> $value */
    private function digest(array $value): string
    {
        unset($value['content_digest']);
        return hash('sha256', json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }
}
