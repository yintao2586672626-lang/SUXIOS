<?php
declare(strict_types=1);

namespace app\service;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use think\facade\Db;

/**
 * Persists a privacy-minimized employee receipt from one verified WeCom event.
 *
 * The injected resolver is the trust boundary for current binding, sender to
 * user mapping, task assignment, and tenant/hotel scope. The receipt is only a
 * sender-reported claim; it never changes approval or execution state.
 */
final class WecomTaskReceiptService
{
    public const TABLE = 'wecom_task_receipts';
    public const CONTRACT_VERSION = 'wecom_task_receipt.v1';

    private const REPORTED_STATUSES = [
        'acknowledged',
        'in_progress',
        'completed',
        'blocked',
        'failed',
    ];

    private const TASK_STATUSES = [
        'pending_execute',
        'executing',
        'blocked',
        'executed',
        'failed',
    ];

    /** @var Closure(array<string,int|string>):array<string,mixed> */
    private Closure $scopeResolver;

    /** @var Closure():DateTimeImmutable */
    private Closure $clock;

    /** @var Closure(array<string,int>):array<string,mixed> */
    private Closure $eventReader;

    /**
     * The resolver receives only tenant/hotel/task/event/binding identities and
     * the sender hash. It must return:
     *
     * - binding: id, tenant_id, hotel_id, status=verified
     * - sender: binding_id, tenant_id, hotel_id, sender_id_hash, actor_id,
     *   status=verified
     * - task: id, tenant_id, hotel_id, assignee_id, status, deleted_at
     */
    public function __construct(
        ?callable $scopeResolver = null,
        ?callable $clock = null,
        ?callable $eventReader = null
    )
    {
        $this->scopeResolver = $scopeResolver !== null
            ? Closure::fromCallable($scopeResolver)
            : static function (array $context): array {
                throw new RuntimeException('wecom_task_receipt_scope_resolver_required', 503);
            };
        $this->clock = $clock !== null
            ? Closure::fromCallable($clock)
            : static fn(): DateTimeImmutable => new DateTimeImmutable(
                'now',
                new DateTimeZone(date_default_timezone_get() ?: 'Asia/Shanghai')
            );
        $this->eventReader = $eventReader !== null
            ? Closure::fromCallable($eventReader)
            : static fn(array $context): array => (new WecomInboundService())->readEvent(
                $context['source_event_id'],
                $context['tenant_id'],
                [$context['hotel_id']]
            );
    }

    /** @return array<string,mixed> */
    public function record(
        int $tenantId,
        int $hotelId,
        int $taskId,
        int $sourceEventId
    ): array {
        $this->assertSchemaReady();
        if ($tenantId <= 0 || $hotelId <= 0 || $taskId <= 0 || $sourceEventId <= 0) {
            throw new InvalidArgumentException('wecom_task_receipt_identity_invalid');
        }

        $archivedEvent = ($this->eventReader)([
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'source_event_id' => $sourceEventId,
        ]);
        if (!is_array($archivedEvent)) {
            throw new RuntimeException('wecom_task_receipt_event_reader_invalid', 503);
        }
        $event = $this->normalizeArchivedEvent($archivedEvent, $tenantId, $hotelId);
        if ((int)$event['id'] !== $sourceEventId) {
            throw new RuntimeException('wecom_task_receipt_event_reader_scope_mismatch', 403);
        }
        $reported = $this->parseStructuredPayload((string)$event['content_text']);
        if ((int)$reported['task_id'] !== $taskId) {
            throw new InvalidArgumentException('wecom_task_receipt_payload_task_mismatch');
        }

        $resolverContext = [
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'task_id' => $taskId,
            'source_event_id' => (int)$event['id'],
            'binding_id' => (int)$event['binding_id'],
            'sender_id_hash' => (string)$event['sender_id_hash'],
        ];
        $scopeFacts = ($this->scopeResolver)($resolverContext);
        if (!is_array($scopeFacts)) {
            throw new RuntimeException('wecom_task_receipt_scope_resolver_invalid', 503);
        }
        $scope = $this->verifyScopeFacts(
            $scopeFacts,
            $tenantId,
            $hotelId,
            $taskId,
            (int)$event['binding_id'],
            (string)$event['sender_id_hash']
        );
        $existing = $this->findExisting($tenantId, $hotelId, (int)$event['id'], $taskId);
        $sourceHotelId = is_array($existing)
            ? (int)($existing['source_hotel_id'] ?? 0)
            : $hotelId;
        if ($sourceHotelId <= 0) {
            throw new RuntimeException('wecom_task_receipt_source_scope_invalid', 409);
        }

        $resultDigest = $this->digest((string)$reported['result']);
        $evidenceNoteDigest = $this->digest((string)$reported['evidence_note']);
        $privacySafePayload = [
            'task_id' => $taskId,
            'reported_status' => (string)$reported['status'],
            'result_digest' => $resultDigest,
            'evidence_note_digest' => $evidenceNoteDigest,
            'reported_amount' => $reported['amount'],
        ];
        $structuredPayloadDigest = $this->digest($privacySafePayload);
        $inputDigest = $this->digest([
            'contract_version' => self::CONTRACT_VERSION,
            'tenant_id' => $tenantId,
            'source_hotel_id' => $sourceHotelId,
            'task_id' => $taskId,
            'source_event_id' => (int)$event['id'],
            'source_binding_id' => (int)$event['binding_id'],
            'sender_id_hash' => (string)$event['sender_id_hash'],
            'source_event_payload_digest' => (string)$event['payload_digest'],
            'source_event_content_digest' => (string)$event['content_digest'],
            'binding_scope_digest' => (string)$scope['binding_scope_digest'],
            'sender_scope_digest' => (string)$scope['sender_scope_digest'],
            'task_scope_digest' => (string)$scope['task_scope_digest'],
            'structured_payload_digest' => $structuredPayloadDigest,
        ]);

        if (is_array($existing)) {
            return $this->replayExisting($existing, $inputDigest);
        }

        $sourceEventRef = 'wecom_inbound_events#' . (int)$event['id'];
        $sourceBindingRef = 'wecom_inbound_bindings#' . (int)$event['binding_id'];
        $taskRef = 'operation_execution_tasks#' . $taskId;
        $amountStatus = $reported['amount'] === null
            ? 'not_reported'
            : 'unverified_sender_reported';
        $payload = [
            'contract_version' => self::CONTRACT_VERSION,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'source_hotel_id' => $sourceHotelId,
            'task_id' => $taskId,
            'source_event_id' => (int)$event['id'],
            'source_binding_id' => (int)$event['binding_id'],
            'source_event_ref' => $sourceEventRef,
            'source_binding_ref' => $sourceBindingRef,
            'task_ref' => $taskRef,
            'sender_id_hash' => (string)$event['sender_id_hash'],
            'reported_status' => (string)$reported['status'],
            'reported_amount' => $reported['amount'],
            'reported_amount_status' => $amountStatus,
            'result_digest' => $resultDigest,
            'evidence_note_digest' => $evidenceNoteDigest,
            'structured_payload_digest' => $structuredPayloadDigest,
            'source_event_payload_digest' => (string)$event['payload_digest'],
            'source_event_content_digest' => (string)$event['content_digest'],
            'binding_scope_digest' => (string)$scope['binding_scope_digest'],
            'sender_scope_digest' => (string)$scope['sender_scope_digest'],
            'task_scope_digest' => (string)$scope['task_scope_digest'],
            'task_status_at_receipt' => (string)$scope['task_status'],
            'input_digest' => $inputDigest,
        ];
        $contentDigest = $this->receiptContentDigest($payload);
        $createdAt = ($this->clock)();
        if (!$createdAt instanceof DateTimeImmutable) {
            throw new RuntimeException('wecom_task_receipt_clock_invalid', 500);
        }

        try {
            $id = (int)Db::name(self::TABLE)->insertGetId(array_merge($payload, [
                'content_digest' => $contentDigest,
                'created_at' => $createdAt->format('Y-m-d H:i:s.u'),
            ]));
            if ($id <= 0) {
                throw new RuntimeException('wecom_task_receipt_write_failed');
            }
        } catch (Throwable $error) {
            $winner = $this->findExisting($tenantId, $hotelId, (int)$event['id'], $taskId);
            if (!is_array($winner)) {
                throw $error;
            }
            return $this->replayExisting($winner, $inputDigest);
        }

        $readback = $this->read($id, $tenantId, $hotelId, $taskId, (int)$event['id']);
        if (!hash_equals($inputDigest, (string)$readback['input_digest'])
            || !hash_equals($contentDigest, (string)$readback['content_digest'])
        ) {
            throw new RuntimeException('wecom_task_receipt_exact_readback_failed', 409);
        }
        $readback['created'] = true;
        $readback['replayed'] = false;
        return $readback;
    }

    /** @return array<string,mixed> */
    public function read(
        int $receiptId,
        int $tenantId,
        int $hotelId,
        int $taskId,
        int $sourceEventId
    ): array {
        $this->assertSchemaReady();
        if ($receiptId <= 0 || $tenantId <= 0 || $hotelId <= 0 || $taskId <= 0 || $sourceEventId <= 0) {
            throw new RuntimeException('wecom_task_receipt_not_found', 404);
        }
        $row = Db::name(self::TABLE)
            ->where('id', $receiptId)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('task_id', $taskId)
            ->where('source_event_id', $sourceEventId)
            ->find();
        if (!is_array($row)) {
            throw new RuntimeException('wecom_task_receipt_not_found', 404);
        }
        return $this->normalizeAndVerify($row);
    }

    /** @return array<string,mixed> */
    private function normalizeArchivedEvent(array $event, int $tenantId, int $hotelId): array
    {
        $normalized = [
            'contract_version' => (string)($event['contract_version'] ?? ''),
            'id' => (int)($event['id'] ?? 0),
            'binding_id' => (int)($event['binding_id'] ?? 0),
            'tenant_id' => (int)($event['tenant_id'] ?? 0),
            'hotel_id' => (int)($event['hotel_id'] ?? 0),
            'external_event_id' => (string)($event['external_event_id'] ?? ''),
            'payload_digest' => strtolower(trim((string)($event['payload_digest'] ?? ''))),
            'occurred_at' => isset($event['occurred_at']) ? (string)$event['occurred_at'] : null,
            'message_type' => strtolower(trim((string)($event['message_type'] ?? ''))),
            'transport' => strtolower(trim((string)($event['transport'] ?? ''))),
            'sender_id_hash' => strtolower(trim((string)($event['sender_id_hash'] ?? ''))),
            'content_text' => isset($event['content_text']) ? (string)$event['content_text'] : null,
            'archive_status' => strtolower(trim((string)($event['archive_status'] ?? ''))),
            'processing_status' => strtolower(trim((string)($event['processing_status'] ?? ''))),
            'block_code' => isset($event['block_code']) && trim((string)$event['block_code']) !== ''
                ? (string)$event['block_code']
                : null,
            'answer' => is_array($event['answer'] ?? null) ? $event['answer'] : [],
            'evidence_refs' => is_array($event['evidence_refs'] ?? null) ? $event['evidence_refs'] : [],
            'delivery_status' => strtolower(trim((string)($event['delivery_status'] ?? 'not_sent'))),
            'delivery_reference' => isset($event['delivery_reference'])
                && trim((string)$event['delivery_reference']) !== ''
                    ? (string)$event['delivery_reference']
                    : null,
            'content_digest' => strtolower(trim((string)($event['content_digest'] ?? ''))),
        ];
        if ($normalized['contract_version'] !== WecomInboundService::CONTRACT_VERSION
            || $normalized['id'] <= 0
            || $normalized['binding_id'] <= 0
            || $normalized['tenant_id'] !== $tenantId
            || $normalized['hotel_id'] !== $hotelId
            || $normalized['external_event_id'] === ''
            || $normalized['message_type'] !== 'text'
            || $normalized['transport'] !== 'wecom_app_callback'
            || $normalized['archive_status'] !== 'readback_verified'
            || !in_array($normalized['processing_status'], ['reply_ready', 'blocked', 'failed'], true)
            || !is_string($normalized['content_text'])
            || trim($normalized['content_text']) === ''
            || strlen($normalized['content_text']) > 4096
            || !$this->isDigest($normalized['payload_digest'])
            || !$this->isDigest($normalized['sender_id_hash'])
            || !$this->isDigest($normalized['content_digest'])
        ) {
            throw new RuntimeException('wecom_task_receipt_archived_event_invalid', 422);
        }
        $eventDigestPayload = array_intersect_key($normalized, array_flip([
            'contract_version', 'binding_id', 'tenant_id', 'hotel_id', 'external_event_id',
            'payload_digest', 'occurred_at', 'message_type', 'transport', 'sender_id_hash', 'content_text',
            'archive_status', 'processing_status', 'block_code', 'answer', 'evidence_refs', 'delivery_status',
            'delivery_reference',
        ]));
        if (!hash_equals($normalized['content_digest'], $this->digest($eventDigestPayload))) {
            throw new RuntimeException('wecom_task_receipt_archived_event_digest_drift', 409);
        }
        return $normalized;
    }

    /** @return array{task_id:int,status:string,result:string,evidence_note:string,amount:?string} */
    private function parseStructuredPayload(string $content): array
    {
        try {
            $payload = json_decode($content, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (Throwable $error) {
            throw new InvalidArgumentException('wecom_task_receipt_structured_json_required', 0, $error);
        }
        if (!is_array($payload) || array_is_list($payload)) {
            throw new InvalidArgumentException('wecom_task_receipt_structured_object_required');
        }
        $required = ['task_id', 'status', 'result', 'evidence_note'];
        $allowed = [...$required, 'amount'];
        if (array_diff($required, array_keys($payload)) !== []
            || array_diff(array_keys($payload), $allowed) !== []
        ) {
            throw new InvalidArgumentException('wecom_task_receipt_fields_invalid');
        }
        if (!is_int($payload['task_id']) || $payload['task_id'] <= 0) {
            throw new InvalidArgumentException('wecom_task_receipt_task_id_invalid');
        }
        $status = is_string($payload['status']) ? strtolower(trim($payload['status'])) : '';
        if (!in_array($status, self::REPORTED_STATUSES, true)) {
            throw new InvalidArgumentException('wecom_task_receipt_status_invalid');
        }
        $result = $this->strictText($payload['result'], 'result');
        $evidenceNote = $this->strictText($payload['evidence_note'], 'evidence_note');
        $amount = null;
        if (array_key_exists('amount', $payload)) {
            if (!is_string($payload['amount'])) {
                throw new InvalidArgumentException('wecom_task_receipt_amount_decimal_string_required');
            }
            $amount = $this->normalizeDecimal($payload['amount']);
        }
        return [
            'task_id' => $payload['task_id'],
            'status' => $status,
            'result' => $result,
            'evidence_note' => $evidenceNote,
            'amount' => $amount,
        ];
    }

    private function strictText(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('wecom_task_receipt_' . $field . '_invalid');
        }
        $value = trim($value);
        if (mb_strlen($value) < 1
            || mb_strlen($value) > 500
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1
        ) {
            throw new InvalidArgumentException('wecom_task_receipt_' . $field . '_invalid');
        }
        return $value;
    }

    private function normalizeDecimal(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^-?(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,2})?$/D', $value) !== 1) {
            throw new InvalidArgumentException('wecom_task_receipt_amount_invalid');
        }
        $negative = str_starts_with($value, '-');
        $unsigned = $negative ? substr($value, 1) : $value;
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $fraction = str_pad($fraction, 2, '0');
        $isZero = trim($whole, '0') === '' && trim($fraction, '0') === '';
        return ($negative && !$isZero ? '-' : '') . $whole . '.' . $fraction;
    }

    /** @return array{binding_scope_digest:string,sender_scope_digest:string,task_scope_digest:string,task_status:string} */
    private function verifyScopeFacts(
        array $facts,
        int $tenantId,
        int $hotelId,
        int $taskId,
        int $bindingId,
        string $senderIdHash
    ): array {
        $binding = is_array($facts['binding'] ?? null) ? $facts['binding'] : [];
        $sender = is_array($facts['sender'] ?? null) ? $facts['sender'] : [];
        $task = is_array($facts['task'] ?? null) ? $facts['task'] : [];

        $bindingScope = [
            'id' => (int)($binding['id'] ?? 0),
            'tenant_id' => (int)($binding['tenant_id'] ?? 0),
            'hotel_id' => (int)($binding['hotel_id'] ?? 0),
            'status' => strtolower(trim((string)($binding['status'] ?? ''))),
        ];
        if ($bindingScope !== [
            'id' => $bindingId,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'status' => 'verified',
        ]) {
            throw new RuntimeException('wecom_task_receipt_binding_scope_invalid', 403);
        }

        $senderScope = [
            'binding_id' => (int)($sender['binding_id'] ?? 0),
            'tenant_id' => (int)($sender['tenant_id'] ?? 0),
            'hotel_id' => (int)($sender['hotel_id'] ?? 0),
            'sender_id_hash' => strtolower(trim((string)($sender['sender_id_hash'] ?? ''))),
            'actor_id' => (int)($sender['actor_id'] ?? 0),
            'status' => strtolower(trim((string)($sender['status'] ?? ''))),
        ];
        if ($senderScope['binding_id'] !== $bindingId
            || $senderScope['tenant_id'] !== $tenantId
            || $senderScope['hotel_id'] !== $hotelId
            || $senderScope['actor_id'] <= 0
            || $senderScope['status'] !== 'verified'
            || !$this->isDigest($senderScope['sender_id_hash'])
            || !hash_equals($senderIdHash, $senderScope['sender_id_hash'])
        ) {
            throw new RuntimeException('wecom_task_receipt_sender_scope_invalid', 403);
        }

        $taskStatus = strtolower(trim((string)($task['status'] ?? '')));
        $taskScope = [
            'id' => (int)($task['id'] ?? 0),
            'tenant_id' => (int)($task['tenant_id'] ?? 0),
            'hotel_id' => (int)($task['hotel_id'] ?? 0),
            'assignee_id' => (int)($task['assignee_id'] ?? 0),
        ];
        if ($taskScope['id'] !== $taskId
            || $taskScope['tenant_id'] !== $tenantId
            || $taskScope['hotel_id'] !== $hotelId
            || $taskScope['assignee_id'] <= 0
            || !in_array($taskStatus, self::TASK_STATUSES, true)
            || (isset($task['deleted_at']) && trim((string)$task['deleted_at']) !== '')
        ) {
            throw new RuntimeException('wecom_task_receipt_task_scope_invalid', 403);
        }
        if ($senderScope['actor_id'] !== $taskScope['assignee_id']) {
            throw new RuntimeException('wecom_task_receipt_sender_not_assignee', 403);
        }

        return [
            'binding_scope_digest' => $this->digest($bindingScope),
            'sender_scope_digest' => $this->digest($senderScope),
            'task_scope_digest' => $this->digest($taskScope),
            'task_status' => $taskStatus,
        ];
    }

    /** @return array<string,mixed> */
    private function normalizeAndVerify(array $row): array
    {
        foreach (['id', 'tenant_id', 'hotel_id', 'source_hotel_id', 'task_id', 'source_event_id', 'source_binding_id'] as $field) {
            $row[$field] = (int)($row[$field] ?? 0);
        }
        $row['reported_amount'] = $row['reported_amount'] === null || $row['reported_amount'] === ''
            ? null
            : $this->normalizeDecimal((string)$row['reported_amount']);
        $expectedEventRef = 'wecom_inbound_events#' . $row['source_event_id'];
        $expectedBindingRef = 'wecom_inbound_bindings#' . $row['source_binding_id'];
        $expectedTaskRef = 'operation_execution_tasks#' . $row['task_id'];
        $amountStatus = (string)($row['reported_amount_status'] ?? '');
        if ((string)($row['contract_version'] ?? '') !== self::CONTRACT_VERSION
            || $row['id'] <= 0
            || $row['tenant_id'] <= 0
            || $row['hotel_id'] <= 0
            || $row['source_hotel_id'] <= 0
            || $row['task_id'] <= 0
            || $row['source_event_id'] <= 0
            || $row['source_binding_id'] <= 0
            || (string)($row['source_event_ref'] ?? '') !== $expectedEventRef
            || (string)($row['source_binding_ref'] ?? '') !== $expectedBindingRef
            || (string)($row['task_ref'] ?? '') !== $expectedTaskRef
            || !in_array((string)($row['reported_status'] ?? ''), self::REPORTED_STATUSES, true)
            || !in_array((string)($row['task_status_at_receipt'] ?? ''), self::TASK_STATUSES, true)
            || ($row['reported_amount'] === null && $amountStatus !== 'not_reported')
            || ($row['reported_amount'] !== null && $amountStatus !== 'unverified_sender_reported')
        ) {
            throw new RuntimeException('wecom_task_receipt_readback_contract_invalid', 409);
        }
        foreach ([
            'sender_id_hash', 'result_digest', 'evidence_note_digest', 'structured_payload_digest',
            'source_event_payload_digest', 'source_event_content_digest', 'binding_scope_digest',
            'sender_scope_digest', 'task_scope_digest', 'input_digest', 'content_digest',
        ] as $field) {
            if (!$this->isDigest((string)($row[$field] ?? ''))) {
                throw new RuntimeException('wecom_task_receipt_readback_contract_invalid', 409);
            }
        }
        $privacySafePayload = [
            'task_id' => $row['task_id'],
            'reported_status' => (string)$row['reported_status'],
            'result_digest' => (string)$row['result_digest'],
            'evidence_note_digest' => (string)$row['evidence_note_digest'],
            'reported_amount' => $row['reported_amount'],
        ];
        if (!hash_equals((string)$row['structured_payload_digest'], $this->digest($privacySafePayload))) {
            throw new RuntimeException('wecom_task_receipt_payload_digest_drift', 409);
        }
        $contentPayload = array_intersect_key($row, array_flip([
            'contract_version', 'tenant_id', 'source_hotel_id', 'task_id', 'source_event_id', 'source_binding_id',
            'source_event_ref', 'source_binding_ref', 'task_ref', 'sender_id_hash', 'reported_status',
            'reported_amount', 'reported_amount_status', 'result_digest', 'evidence_note_digest',
            'structured_payload_digest', 'source_event_payload_digest', 'source_event_content_digest',
            'binding_scope_digest', 'sender_scope_digest', 'task_scope_digest', 'task_status_at_receipt',
            'input_digest',
        ]));
        if (!hash_equals((string)$row['content_digest'], $this->receiptContentDigest($contentPayload))) {
            throw new RuntimeException('wecom_task_receipt_content_digest_drift', 409);
        }
        $row['result_ref'] = $expectedEventRef . '/result';
        $row['evidence_note_ref'] = $expectedEventRef . '/evidence_note';
        $row['readback_verified'] = true;
        $row['persistence_status'] = 'readback_verified';
        $row['boundaries'] = $this->boundaries();
        return $row;
    }

    /** @return array<string,mixed> */
    private function replayExisting(array $row, string $inputDigest): array
    {
        $readback = $this->normalizeAndVerify($row);
        if (!hash_equals((string)$readback['input_digest'], $inputDigest)) {
            throw new RuntimeException('wecom_task_receipt_idempotency_conflict', 409);
        }
        $readback['created'] = false;
        $readback['replayed'] = true;
        return $readback;
    }

    /** @return array<string,mixed>|null */
    private function findExisting(int $tenantId, int $hotelId, int $sourceEventId, int $taskId): ?array
    {
        $row = Db::name(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('source_event_id', $sourceEventId)
            ->where('task_id', $taskId)
            ->find();
        return is_array($row) ? $row : null;
    }

    /** @return array<string,bool|string> */
    private function boundaries(): array
    {
        return [
            'receipt_semantics' => 'unverified_employee_report',
            'raw_message_persisted' => false,
            'raw_pii_persisted' => false,
            'raw_sender_identifier_persisted' => false,
            'pseudonymous_sender_hash_persisted' => true,
            'human_approval_recorded' => false,
            'task_state_mutated' => false,
            'execution_success_verified' => false,
            'external_send_performed' => false,
            'ota_write' => false,
            'pms_write' => false,
            'financial_fact_created' => false,
        ];
    }

    private function assertSchemaReady(): void
    {
        try {
            Db::name(self::TABLE)->limit(1)->select();
        } catch (Throwable $error) {
            throw new RuntimeException('wecom_task_receipt_migration_required', 503, $error);
        }
    }

    private function isDigest(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    private function digest(mixed $value): string
    {
        return hash('sha256', $this->encode($this->canonicalize($value)));
    }

    /** @param array<string,mixed> $payload */
    private function receiptContentDigest(array $payload): string
    {
        unset($payload['hotel_id']);
        return $this->digest($payload);
    }

    private function encode(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([$this, 'canonicalize'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }
}
