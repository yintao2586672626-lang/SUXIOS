<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;
use Throwable;

/**
 * Persists one evidence-bound operating review as a real pending approval.
 *
 * This service deliberately stops before approval and execution: it never
 * creates an execution task, performs an OTA write, starts collection, or
 * sends an external message.
 */
final class OperatingApprovalIntentService
{
    public const CONTRACT_VERSION = 'operating_approval_intent.v1';
    public const SOURCE_MODULE = 'operating_loop_approval';

    private const PLATFORM = 'hotel_operation';
    private const OBJECT_TYPE = 'operation_checklist';
    private const ACTION_TYPE = 'human_review_operating_cycle';
    private const EXPECTED_METRIC = 'operating_review_decision';
    private const IDEMPOTENCY_PREFIX = 'operating_approval_';

    private ?OperationManagementService $operationService;

    public function __construct(?OperationManagementService $operationService = null)
    {
        $this->operationService = $operationService;
    }

    /**
     * @param list<array<string,mixed>|string> $evidenceRefs
     * @return array<string,mixed>
     */
    public function createPendingApproval(
        int $tenantId,
        int $hotelId,
        string $businessDate,
        int $actorId,
        array $evidenceRefs
    ): array {
        if ($tenantId <= 0 || $hotelId <= 0 || $actorId <= 0) {
            throw new InvalidArgumentException('operating_approval_scope_invalid');
        }
        $businessDate = $this->date($businessDate);
        $this->assertSchemaReady();
        $this->assertActorScope($tenantId, $hotelId, $actorId);

        $evidenceRefs = $this->normalizeEvidenceRefs($evidenceRefs, $businessDate);
        $evidenceDigest = self::digest($evidenceRefs);
        $sourceRecordId = $this->sourceRecordId($evidenceRefs);
        $idempotencyKey = self::IDEMPOTENCY_PREFIX . md5(self::encode([
            'contract_version' => self::CONTRACT_VERSION,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'business_date' => $businessDate,
            'evidence_digest' => $evidenceDigest,
        ]));

        $metricDefinition = [
            'version' => 'operating_review_decision_metric.v1',
            'metric_key' => self::EXPECTED_METRIC,
            'value_type' => 'categorical',
            'allowed_values' => ['approved', 'rejected', 'needs_more_evidence'],
            'scope' => 'tenant_id + hotel_id + business_date',
            'missing_value_policy' => 'indeterminate',
            'causality_claimed' => false,
        ];
        $metricDefinitionDigest = self::digest([
            'metric_key' => self::EXPECTED_METRIC,
            'definition' => $metricDefinition,
        ]);
        $approvalTarget = [
            'version' => 'operating_review_approval_target.v1',
            'source_module' => self::SOURCE_MODULE,
            'source_record_id' => $sourceRecordId,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'platform' => self::PLATFORM,
            'object_type' => self::OBJECT_TYPE,
            'action_type' => self::ACTION_TYPE,
            'baseline_business_date' => $businessDate,
            'expected_metric' => self::EXPECTED_METRIC,
            'metric_definition' => $metricDefinition,
            'metric_definition_digest' => $metricDefinitionDigest,
            'evidence_digest' => $evidenceDigest,
            'decision_scope' => 'review_operating_analysis_before_any_execution',
            'human_confirmation_required' => true,
            'external_action_allowed' => false,
        ];
        $approvalTargetDigest = self::digest($approvalTarget);
        $approvalTarget['content_digest'] = $approvalTargetDigest;

        $input = [
            'source_module' => self::SOURCE_MODULE,
            'source_record_id' => $sourceRecordId,
            'hotel_id' => $hotelId,
            'platform' => self::PLATFORM,
            'object_type' => self::OBJECT_TYPE,
            'action_type' => self::ACTION_TYPE,
            'date_start' => $businessDate,
            'date_end' => $businessDate,
            'current_value' => [
                'business_date' => $businessDate,
                'evidence_digest' => $evidenceDigest,
                'evidence_ref_count' => count($evidenceRefs),
            ],
            'target_value' => [
                'title' => '经营分析待人工审批',
                'action_text' => '核对本业务日经营证据，并决定是否进入人工执行。',
                'steps' => [
                    '核对租户、酒店、业务日和证据引用',
                    '由人工选择批准、拒绝或要求补充证据',
                ],
                'acceptance_criteria' => [
                    '批准前不创建执行任务',
                    '批准前不触发 OTA 写入或外部消息',
                    '缺少证据时保持待补充或拒绝',
                ],
                'baseline_business_date' => $businessDate,
                'execution_mode' => 'manual',
                'metric_definition' => $metricDefinition,
                'metric_definition_digest' => $metricDefinitionDigest,
                'approval_target_digest' => $approvalTargetDigest,
                'auto_write_ota' => false,
            ],
            'evidence' => [
                'contract_version' => self::CONTRACT_VERSION,
                'source_policy' => 'formal_evidence_refs_then_human_approval',
                'business_date' => $businessDate,
                'evidence_refs' => $evidenceRefs,
                'evidence_digest' => $evidenceDigest,
                'metric_definition' => $metricDefinition,
                'metric_definition_digest' => $metricDefinitionDigest,
                'approval_target' => $approvalTarget,
                'approval_target_digest' => $approvalTargetDigest,
                'boundaries' => [
                    'human_approval_required' => true,
                    'automatic_collection' => false,
                    'automatic_approval' => false,
                    'automatic_execution' => false,
                    'ota_write' => false,
                    'external_message' => false,
                    'causality_claimed' => false,
                ],
            ],
            'expected_metric' => self::EXPECTED_METRIC,
            'expected_delta' => 0,
            'risk_level' => 'medium',
            'status' => 'pending_approval',
        ];
        $payload = $this->operations()->buildExecutionIntentPayload(
            [$hotelId],
            $hotelId,
            $input,
            $actorId
        );
        if ((string)($payload['status'] ?? '') !== 'pending_approval'
            || trim((string)($payload['blocked_reason'] ?? '')) !== ''
        ) {
            throw new RuntimeException('operating_approval_payload_not_pending');
        }

        $expected = [
            'idempotency_key' => $idempotencyKey,
            'tenant_id' => $tenantId,
            'source_module' => (string)$payload['source_module'],
            'source_record_id' => (int)$payload['source_record_id'],
            'hotel_id' => (int)$payload['hotel_id'],
            'platform' => (string)$payload['platform'],
            'object_type' => (string)$payload['object_type'],
            'action_type' => (string)$payload['action_type'],
            'date_start' => (string)$payload['date_start'],
            'date_end' => (string)$payload['date_end'],
            'current_value' => (array)$payload['current_value'],
            'target_value' => (array)$payload['target_value'],
            'evidence' => (array)$payload['evidence'],
            'expected_metric' => (string)$payload['expected_metric'],
            'expected_delta' => (float)$payload['expected_delta'],
            'risk_level' => (string)$payload['risk_level'],
            'status' => 'pending_approval',
            'blocked_reason' => '',
        ];
        $now = date('Y-m-d H:i:s');
        $insert = [
            'idempotency_key' => $idempotencyKey,
            'tenant_id' => $tenantId,
            'source_module' => $expected['source_module'],
            'source_record_id' => $expected['source_record_id'],
            'hotel_id' => $hotelId,
            'platform' => $expected['platform'],
            'object_type' => $expected['object_type'],
            'action_type' => $expected['action_type'],
            'date_start' => $businessDate,
            'date_end' => $businessDate,
            'current_value_json' => self::encode($expected['current_value']),
            'target_value_json' => self::encode($expected['target_value']),
            'evidence_json' => self::encode($expected['evidence']),
            'expected_metric' => $expected['expected_metric'],
            'expected_delta' => $expected['expected_delta'],
            'risk_level' => $expected['risk_level'],
            'status' => 'pending_approval',
            'blocked_reason' => '',
            'review_remark' => '',
            'created_by' => $actorId,
            'approved_by' => 0,
            'approved_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ];

        try {
            $stored = Db::transaction(function () use ($tenantId, $hotelId, $idempotencyKey, $insert): array {
                $existing = $this->rowByKey($tenantId, $hotelId, $idempotencyKey);
                if (is_array($existing)) {
                    return ['row' => $existing, 'created' => false];
                }
                $id = (int)Db::name('operation_execution_intents')->insertGetId($insert);
                $row = $this->rowById($id, $tenantId, $hotelId);
                if (!is_array($row)) {
                    throw new RuntimeException('operating_approval_insert_readback_missing');
                }
                return ['row' => $row, 'created' => true];
            });
        } catch (Throwable $exception) {
            // A concurrent insert may win the unique idempotency key. Only an
            // exact scoped readback can turn that race into a successful replay.
            $row = $this->rowByKey($tenantId, $hotelId, $idempotencyKey);
            if (!is_array($row)) {
                throw $exception;
            }
            $stored = ['row' => $row, 'created' => false];
        }

        $intent = $this->assertExactReadback(
            (array)$stored['row'],
            $expected,
            ($stored['created'] ?? false) === true ? $actorId : null
        );

        return [
            'status' => 'pending_approval',
            'execution_intent' => $intent,
            'idempotency_key' => $idempotencyKey,
            'reused_existing_intent' => ($stored['created'] ?? false) !== true,
            'persistence_status' => 'readback_verified',
            'execution_task_created' => false,
            'external_action_triggered' => false,
            'source_policy' => 'pending_human_approval_no_automatic_execution_or_external_action',
        ];
    }

    private function operations(): OperationManagementService
    {
        return $this->operationService ??= new OperationManagementService();
    }

    private function assertSchemaReady(): void
    {
        foreach (['hotels', 'users', 'user_hotel_permissions', 'operation_execution_intents', 'operation_execution_tasks'] as $table) {
            try {
                Db::query('SELECT 1 FROM `' . $table . '` LIMIT 1');
            } catch (Throwable $exception) {
                throw new RuntimeException('operating_approval_schema_missing:' . $table, 0, $exception);
            }
        }
    }

    private function assertActorScope(int $tenantId, int $hotelId, int $actorId): void
    {
        $hotel = Db::name('hotels')
            ->where('id', $hotelId)
            ->where('tenant_id', $tenantId)
            ->where('status', 1)
            ->field('id,tenant_id,owner_user_id,created_by')
            ->find();
        if (!is_array($hotel)) {
            throw new InvalidArgumentException('operating_approval_hotel_scope_mismatch');
        }
        $actor = Db::name('users')
            ->where('id', $actorId)
            ->where('tenant_id', $tenantId)
            ->where('status', 1)
            ->field('id')
            ->find();
        if (!is_array($actor)) {
            throw new InvalidArgumentException('operating_approval_actor_scope_mismatch');
        }
        if (in_array($actorId, [(int)$hotel['owner_user_id'], (int)$hotel['created_by']], true)) {
            return;
        }

        $permission = Db::name('user_hotel_permissions')
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('user_id', $actorId)
            ->where('status', 'active')
            ->where('can_view', 1)
            ->where('can_operation', 1)
            ->where(static function ($query): void {
                $query->whereNull('expires_at')->whereOr('expires_at', '>', date('Y-m-d H:i:s'));
            })
            ->field('id')
            ->find();
        if (!is_array($permission)) {
            throw new InvalidArgumentException('operating_approval_actor_not_permitted');
        }
    }

    /**
     * @param list<array<string,mixed>|string> $refs
     * @return list<array<string,mixed>>
     */
    private function normalizeEvidenceRefs(array $refs, string $businessDate): array
    {
        if ($refs === [] || !array_is_list($refs) || count($refs) > 100) {
            throw new InvalidArgumentException('operating_approval_evidence_refs_invalid');
        }

        $normalized = [];
        foreach ($refs as $ref) {
            if (is_string($ref)) {
                if (preg_match('/^([a-z][a-z0-9_]{0,79})#([1-9][0-9]*)$/D', trim($ref), $matches) !== 1) {
                    throw new InvalidArgumentException('operating_approval_evidence_ref_invalid');
                }
                $item = [
                    'role' => 'supporting_fact',
                    'source_kind' => 'formal_record',
                    'table' => $matches[1],
                    'row_ids' => [(int)$matches[2]],
                    'platform' => '',
                ];
            } elseif (is_array($ref)) {
                $item = $this->normalizeEvidenceRef($ref, $businessDate);
            } else {
                throw new InvalidArgumentException('operating_approval_evidence_ref_invalid');
            }
            $normalized[self::encode($item)] = $item;
        }
        if ($normalized === []) {
            throw new InvalidArgumentException('operating_approval_evidence_refs_invalid');
        }
        ksort($normalized, SORT_STRING);
        return array_values($normalized);
    }

    /** @param array<string,mixed> $ref @return array<string,mixed> */
    private function normalizeEvidenceRef(array $ref, string $businessDate): array
    {
        $allowed = [
            'role', 'source_kind', 'table', 'source_table', 'row_id', 'row_ids',
            'platform', 'business_date', 'fact_scope', 'metric_definition_digest',
            'readback_verified', 'verification_status',
        ];
        if (array_diff(array_keys($ref), $allowed) !== []) {
            throw new InvalidArgumentException('operating_approval_evidence_ref_unknown_field');
        }
        $table = trim((string)($ref['table'] ?? $ref['source_table'] ?? ''));
        if (isset($ref['table'], $ref['source_table'])
            && trim((string)$ref['table']) !== trim((string)$ref['source_table'])
        ) {
            throw new InvalidArgumentException('operating_approval_evidence_ref_table_conflict');
        }
        $role = trim((string)($ref['role'] ?? 'supporting_fact'));
        $sourceKind = trim((string)($ref['source_kind'] ?? 'formal_record'));
        foreach (['table' => $table, 'role' => $role, 'source_kind' => $sourceKind] as $field => $value) {
            if (preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $value) !== 1) {
                throw new InvalidArgumentException('operating_approval_evidence_ref_' . $field . '_invalid');
            }
        }

        $rowIds = $ref['row_ids'] ?? ($ref['row_id'] ?? []);
        $rowIds = is_array($rowIds) ? $rowIds : [$rowIds];
        if ($rowIds === [] || count($rowIds) > 1000) {
            throw new InvalidArgumentException('operating_approval_evidence_ref_row_ids_invalid');
        }
        $rowIds = array_values(array_unique(array_map('intval', $rowIds)));
        if (count(array_filter($rowIds, static fn(int $id): bool => $id > 0)) !== count($rowIds)) {
            throw new InvalidArgumentException('operating_approval_evidence_ref_row_ids_invalid');
        }
        sort($rowIds, SORT_NUMERIC);

        $platform = strtolower(trim((string)($ref['platform'] ?? '')));
        if ($platform !== '' && preg_match('/^[a-z0-9][a-z0-9_.:-]{0,39}$/D', $platform) !== 1) {
            throw new InvalidArgumentException('operating_approval_evidence_ref_platform_invalid');
        }
        $refDate = trim((string)($ref['business_date'] ?? ''));
        if ($refDate !== '' && $this->date($refDate) !== $businessDate) {
            throw new InvalidArgumentException('operating_approval_evidence_ref_date_mismatch');
        }

        $normalized = [
            'role' => $role,
            'source_kind' => $sourceKind,
            'table' => $table,
            'row_ids' => $rowIds,
            'platform' => $platform,
        ];
        if ($refDate !== '') {
            $normalized['business_date'] = $businessDate;
        }
        foreach (['fact_scope'] as $field) {
            $value = trim((string)($ref[$field] ?? ''));
            if ($value !== '') {
                if (preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $value) !== 1) {
                    throw new InvalidArgumentException('operating_approval_evidence_ref_' . $field . '_invalid');
                }
                $normalized[$field] = $value;
            }
        }
        $definitionDigest = strtolower(trim((string)($ref['metric_definition_digest'] ?? '')));
        if ($definitionDigest !== '') {
            if (preg_match('/^[a-f0-9]{64}$/D', $definitionDigest) !== 1) {
                throw new InvalidArgumentException('operating_approval_evidence_ref_metric_digest_invalid');
            }
            $normalized['metric_definition_digest'] = $definitionDigest;
        }
        if (array_key_exists('readback_verified', $ref)) {
            if (!in_array($ref['readback_verified'], [true, 1, '1', 'true'], true)) {
                throw new InvalidArgumentException('operating_approval_evidence_ref_not_verified');
            }
            $normalized['readback_verified'] = true;
        }
        $verificationStatus = strtolower(trim((string)($ref['verification_status'] ?? '')));
        if ($verificationStatus !== '') {
            if (!in_array($verificationStatus, ['verified', 'readback_verified', 'success', 'manual_confirmed'], true)) {
                throw new InvalidArgumentException('operating_approval_evidence_ref_not_verified');
            }
            $normalized['verification_status'] = $verificationStatus;
        }
        return $normalized;
    }

    /** @param list<array<string,mixed>> $refs */
    private function sourceRecordId(array $refs): int
    {
        foreach (['hotel_operating_cycles', 'hotel_operating_cycle_events'] as $preferredTable) {
            foreach ($refs as $ref) {
                if ((string)$ref['table'] === $preferredTable) {
                    return (int)$ref['row_ids'][0];
                }
            }
        }
        return (int)$refs[0]['row_ids'][0];
    }

    /** @return array<string,mixed>|null */
    private function rowByKey(int $tenantId, int $hotelId, string $idempotencyKey): ?array
    {
        $row = Db::name('operation_execution_intents')
            ->where('idempotency_key', $idempotencyKey)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->whereNull('deleted_at')
            ->find();
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function rowById(int $id, int $tenantId, int $hotelId): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $row = Db::name('operation_execution_intents')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->whereNull('deleted_at')
            ->find();
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $expected
     * @return array<string,mixed>
     */
    private function assertExactReadback(array $row, array $expected, ?int $createdBy): array
    {
        foreach ([
            'idempotency_key', 'tenant_id', 'source_module', 'source_record_id', 'hotel_id',
            'platform', 'object_type', 'action_type', 'date_start', 'date_end',
            'expected_metric', 'risk_level', 'status', 'blocked_reason',
        ] as $field) {
            if ((string)($row[$field] ?? '') !== (string)($expected[$field] ?? '')) {
                throw new RuntimeException('operating_approval_exact_readback_drift:' . $field, 409);
            }
        }
        if (abs((float)($row['expected_delta'] ?? 0) - (float)$expected['expected_delta']) > 0.00001) {
            throw new RuntimeException('operating_approval_exact_readback_drift:expected_delta', 409);
        }
        if ($createdBy !== null && (int)($row['created_by'] ?? 0) !== $createdBy) {
            throw new RuntimeException('operating_approval_exact_readback_drift:created_by', 409);
        }
        if ((int)($row['created_by'] ?? 0) <= 0
            || (int)($row['approved_by'] ?? 0) !== 0
            || ($row['approved_at'] ?? null) !== null
            || trim((string)($row['review_remark'] ?? '')) !== ''
        ) {
            throw new RuntimeException('operating_approval_exact_readback_drift:approval_state', 409);
        }

        $decoded = [
            'current_value' => $this->decode((string)($row['current_value_json'] ?? '')),
            'target_value' => $this->decode((string)($row['target_value_json'] ?? '')),
            'evidence' => $this->decode((string)($row['evidence_json'] ?? '')),
        ];
        foreach ($decoded as $field => $value) {
            if (self::canonicalize($value) !== self::canonicalize((array)$expected[$field])) {
                throw new RuntimeException('operating_approval_exact_readback_drift:' . $field, 409);
            }
        }

        $taskCount = (int)Db::name('operation_execution_tasks')
            ->where('tenant_id', (int)$expected['tenant_id'])
            ->where('hotel_id', (int)$expected['hotel_id'])
            ->where('intent_id', (int)($row['id'] ?? 0))
            ->whereNull('deleted_at')
            ->count();
        if ($taskCount !== 0) {
            throw new RuntimeException('operating_approval_exact_readback_drift:execution_task_exists', 409);
        }

        return [
            'id' => (int)$row['id'],
            'tenant_id' => (int)$row['tenant_id'],
            'source_module' => (string)$row['source_module'],
            'source_record_id' => (int)$row['source_record_id'],
            'hotel_id' => (int)$row['hotel_id'],
            'platform' => (string)$row['platform'],
            'object_type' => (string)$row['object_type'],
            'action_type' => (string)$row['action_type'],
            'date_start' => (string)$row['date_start'],
            'date_end' => (string)$row['date_end'],
            'current_value' => $decoded['current_value'],
            'target_value' => $decoded['target_value'],
            'evidence' => $decoded['evidence'],
            'expected_metric' => (string)$row['expected_metric'],
            'expected_delta' => (float)$row['expected_delta'],
            'risk_level' => (string)$row['risk_level'],
            'status' => (string)$row['status'],
            'blocked_reason' => (string)$row['blocked_reason'],
            'review_remark' => (string)$row['review_remark'],
            'created_by' => (int)$row['created_by'],
            'approved_by' => (int)$row['approved_by'],
            'approved_at' => null,
            'tasks' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function decode(string $json): array
    {
        try {
            $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RuntimeException('operating_approval_exact_readback_json_invalid', 409, $exception);
        }
        if (!is_array($value)) {
            throw new RuntimeException('operating_approval_exact_readback_json_invalid', 409);
        }
        return $value;
    }

    private function date(string $value): string
    {
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false
            || $date->format('Y-m-d') !== $value
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        ) {
            throw new InvalidArgumentException('operating_approval_business_date_invalid');
        }
        return $value;
    }

    private static function encode(array $value): string
    {
        return json_encode(
            self::canonicalize($value),
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR
        );
    }

    private static function digest(array $value): string
    {
        return hash('sha256', self::encode($value));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'canonicalize'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }
        return $value;
    }
}
