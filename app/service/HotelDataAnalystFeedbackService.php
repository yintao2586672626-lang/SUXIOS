<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

/** Append-only, hotel-scoped human feedback for one exact analyst answer. */
final class HotelDataAnalystFeedbackService
{
    public const TABLE = 'hotel_data_analyst_feedbacks';
    public const CONTRACT_VERSION = 'hotel_data_analyst_feedback.v1';
    public const USAGE_POLICY = 'eval_candidate_only_no_training';

    private OperatingQuestionService $questionService;
    private HotelDataAnalystFeedbackProjectionService $projectionService;

    public function __construct(
        ?OperatingQuestionService $questionService = null,
        ?HotelDataAnalystFeedbackProjectionService $projectionService = null
    ) {
        $this->questionService = $questionService ?? new OperatingQuestionService();
        $this->projectionService = $projectionService ?? new HotelDataAnalystFeedbackProjectionService();
    }

    /** @param list<int> $accessibleHotelIds @return array<string,mixed> */
    public function save(
        int $tenantId,
        array $accessibleHotelIds,
        int $questionId,
        int $createdBy,
        array $input
    ): array {
        $this->assertSchemaReady();
        if ($tenantId <= 0 || $questionId <= 0 || $createdBy <= 0) {
            throw new InvalidArgumentException('feedback_scope_invalid');
        }
        $hotelIds = $this->positiveIds($accessibleHotelIds);
        if ($hotelIds === []) throw new RuntimeException('feedback_question_not_found', 404);
        $question = $this->questionService->read($questionId, $tenantId, $hotelIds);
        $hotelId = (int)($question['hotel_id'] ?? 0);
        if ($hotelId <= 0 || !in_array($hotelId, $hotelIds, true)) {
            throw new RuntimeException('feedback_question_not_found', 404);
        }

        $feedbackKind = strtolower(trim((string)($input['feedback_kind'] ?? $input['feedback_type'] ?? '')));
        if (!in_array($feedbackKind, ['useful', 'needs_correction'], true)) {
            throw new InvalidArgumentException('feedback_kind_invalid');
        }
        $correction = $this->normalizeCorrection($feedbackKind, $input);
        $idempotencyKey = trim((string)($input['idempotency_key'] ?? $input['client_feedback_key'] ?? ''));
        if (preg_match('/^[A-Za-z0-9:_-]{8,120}$/D', $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('feedback_idempotency_key_invalid');
        }
        $sourceContentDigest = strtolower(trim((string)($input['source_content_digest'] ?? $input['question_content_digest'] ?? '')));
        $qualityReceiptDigest = strtolower(trim((string)($input['quality_receipt_digest'] ?? '')));
        $inputDigest = $this->digest([
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'question_id' => $questionId,
            'source_content_digest' => $sourceContentDigest,
            'quality_receipt_digest' => $qualityReceiptDigest,
            'feedback_kind' => $feedbackKind,
            'correction' => $correction,
            'idempotency_key' => $idempotencyKey,
            'created_by' => $createdBy,
        ]);
        $existing = $this->findByIdempotency($tenantId, $hotelId, $createdBy, $idempotencyKey);
        if (is_array($existing)) {
            return $this->replayExisting($existing, $inputDigest, $questionId);
        }
        $currentReceipt = is_array($question['analysis_quality_receipt'] ?? null)
            ? $question['analysis_quality_receipt']
            : [];
        if (!hash_equals((string)($question['content_digest'] ?? ''), $sourceContentDigest)
            || !hash_equals((string)($currentReceipt['receipt_digest'] ?? ''), $qualityReceiptDigest)
        ) {
            throw new RuntimeException('analysis_snapshot_drift', 409);
        }
        $scope = $this->normalizeScope($question);
        $scopeDigest = $this->digest($scope);
        $receiptScopeDigest = $this->digest([
            'tenant_id' => $scope['tenant_id'],
            'hotel_id' => $scope['hotel_id'],
            'platform' => $scope['platform'],
            'date_start' => $scope['date_start'],
            'date_end' => $scope['date_end'],
            'answer_scope' => $scope,
        ]);
        if (!hash_equals((string)($currentReceipt['scope_digest'] ?? ''), $receiptScopeDigest)) {
            throw new RuntimeException('analysis_scope_receipt_drift', 409);
        }
        $projection = $this->projectionService->project($question, $feedbackKind, $correction);
        $correctionDigest = $this->digest($correction);
        $payload = [
            'contract_version' => self::CONTRACT_VERSION,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'question_id' => $questionId,
            'source_scope' => $scope,
            'source_scope_digest' => $scopeDigest,
            'source_content_digest' => $sourceContentDigest,
            'quality_receipt_contract_version' => (string)($currentReceipt['contract_version'] ?? ''),
            'quality_receipt_digest' => $qualityReceiptDigest,
            'feedback_kind' => $feedbackKind,
            'correction' => $correction,
            'correction_digest' => $correctionDigest,
            'usage_policy' => self::USAGE_POLICY,
            'evaluation_projection' => $projection,
            'idempotency_key' => $idempotencyKey,
            'input_digest' => $inputDigest,
            'created_by' => $createdBy,
        ];
        $contentDigest = $this->digest($payload);

        $createdAt = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))
            ->format('Y-m-d H:i:s.u');
        try {
            $id = (int)Db::name(self::TABLE)->insertGetId([
                'contract_version' => self::CONTRACT_VERSION,
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'question_id' => $questionId,
                'source_scope_json' => $this->encode($scope),
                'source_scope_digest' => $scopeDigest,
                'source_content_digest' => $sourceContentDigest,
                'quality_receipt_contract_version' => (string)($currentReceipt['contract_version'] ?? ''),
                'quality_receipt_digest' => $qualityReceiptDigest,
                'feedback_kind' => $feedbackKind,
                'correction_json' => $this->encode($correction),
                'correction_digest' => $correctionDigest,
                'usage_policy' => self::USAGE_POLICY,
                'evaluation_projection_json' => $this->encode($projection),
                'idempotency_key' => $idempotencyKey,
                'input_digest' => $inputDigest,
                'content_digest' => $contentDigest,
                'created_by' => $createdBy,
                'created_at' => $createdAt,
            ]);
            if ($id <= 0) throw new RuntimeException('feedback_write_failed');
        } catch (\Throwable $error) {
            $winner = $this->findByIdempotency($tenantId, $hotelId, $createdBy, $idempotencyKey);
            if (!is_array($winner)) throw $error;
            return $this->replayExisting($winner, $inputDigest, $questionId);
        }
        $readback = $this->read($id, $tenantId, $hotelIds, $questionId, $createdBy);
        if (!hash_equals($contentDigest, (string)$readback['content_digest'])
            || !hash_equals($inputDigest, (string)$readback['input_digest'])
        ) {
            throw new RuntimeException('feedback_readback_digest_drift', 409);
        }
        $readback['created'] = true;
        $readback['replayed'] = false;
        $readback['persistence_status'] = 'readback_verified';
        return $readback;
    }

    /** @param list<int> $accessibleHotelIds @return array<string,mixed> */
    public function read(
        int $feedbackId,
        int $tenantId,
        array $accessibleHotelIds,
        int $questionId,
        int $createdBy
    ): array {
        $this->assertSchemaReady();
        $hotelIds = $this->positiveIds($accessibleHotelIds);
        if ($feedbackId <= 0 || $questionId <= 0 || $createdBy <= 0 || $hotelIds === []) {
            throw new RuntimeException('feedback_not_found', 404);
        }
        $row = Db::name(self::TABLE)
            ->where('id', $feedbackId)
            ->where('tenant_id', $tenantId)
            ->where('question_id', $questionId)
            ->where('created_by', $createdBy)
            ->whereIn('hotel_id', $hotelIds)
            ->find();
        if (!is_array($row)) throw new RuntimeException('feedback_not_found', 404);
        $readback = $this->normalizeAndVerify($row);
        $readback['persistence_status'] = 'readback_verified';
        return $readback;
    }

    /** @param list<int> $accessibleHotelIds @return array<string,mixed> */
    public function listMine(
        int $tenantId,
        array $accessibleHotelIds,
        int $questionId,
        int $createdBy,
        int $limit = 20
    ): array {
        if (!$this->tableExists()) {
            return [
                'contract_version' => self::CONTRACT_VERSION,
                'data_status' => 'migration_required',
                'question_id' => $questionId,
                'list' => [],
                'latest' => null,
                'summary' => ['total' => 0, 'useful' => 0, 'needs_correction' => 0],
                'boundaries' => $this->boundaries(),
            ];
        }
        $hotelIds = $this->positiveIds($accessibleHotelIds);
        $question = $this->questionService->read($questionId, $tenantId, $hotelIds);
        $hotelId = (int)($question['hotel_id'] ?? 0);
        $rows = Db::name(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('question_id', $questionId)
            ->where('created_by', $createdBy)
            ->order('id', 'desc')
            ->limit(max(1, min(50, $limit)))
            ->select()
            ->toArray();
        $list = array_map([$this, 'normalizeAndVerify'], $rows);
        $summary = ['total' => count($list), 'useful' => 0, 'needs_correction' => 0];
        foreach ($list as $item) {
            $kind = (string)$item['feedback_kind'];
            if (isset($summary[$kind])) $summary[$kind]++;
        }
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'data_status' => 'ready',
            'question_id' => $questionId,
            'list' => $list,
            'latest' => $list[0] ?? null,
            'summary' => $summary,
            'boundaries' => $this->boundaries(),
        ];
    }

    /** @return array<string,mixed> */
    private function normalizeAndVerify(array $row): array
    {
        foreach (['id', 'tenant_id', 'hotel_id', 'question_id', 'created_by'] as $field) {
            $row[$field] = (int)($row[$field] ?? 0);
        }
        $row['source_scope'] = $this->decode($row['source_scope_json'] ?? null);
        $row['correction'] = $this->decode($row['correction_json'] ?? null);
        $row['evaluation_projection'] = $this->decode($row['evaluation_projection_json'] ?? null);
        unset($row['source_scope_json'], $row['correction_json'], $row['evaluation_projection_json']);
        if ((string)($row['contract_version'] ?? '') !== self::CONTRACT_VERSION
            || (string)($row['usage_policy'] ?? '') !== self::USAGE_POLICY
            || preg_match('/^[a-f0-9]{64}$/D', (string)($row['source_scope_digest'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', (string)($row['source_content_digest'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', (string)($row['quality_receipt_digest'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', (string)($row['content_digest'] ?? '')) !== 1
            || !hash_equals((string)$row['source_scope_digest'], $this->digest($row['source_scope']))
            || !hash_equals((string)$row['correction_digest'], $this->digest($row['correction']))
        ) {
            throw new RuntimeException('feedback_readback_contract_invalid', 409);
        }
        $payload = [
            'contract_version' => self::CONTRACT_VERSION,
            'tenant_id' => $row['tenant_id'],
            'hotel_id' => $row['hotel_id'],
            'question_id' => $row['question_id'],
            'source_scope' => $row['source_scope'],
            'source_scope_digest' => (string)$row['source_scope_digest'],
            'source_content_digest' => (string)$row['source_content_digest'],
            'quality_receipt_contract_version' => (string)($row['quality_receipt_contract_version'] ?? ''),
            'quality_receipt_digest' => (string)$row['quality_receipt_digest'],
            'feedback_kind' => (string)($row['feedback_kind'] ?? ''),
            'correction' => $row['correction'],
            'correction_digest' => (string)$row['correction_digest'],
            'usage_policy' => (string)$row['usage_policy'],
            'evaluation_projection' => $row['evaluation_projection'],
            'idempotency_key' => (string)($row['idempotency_key'] ?? ''),
            'input_digest' => (string)($row['input_digest'] ?? ''),
            'created_by' => $row['created_by'],
        ];
        if (!hash_equals((string)$row['content_digest'], $this->digest($payload))) {
            throw new RuntimeException('feedback_readback_digest_drift', 409);
        }
        $row['readback_verified'] = true;
        $row['formal_evaluation_case_created'] = false;
        $row['model_training_triggered'] = false;
        $row['external_action_authorized'] = false;
        $row['boundaries'] = $this->boundaries();
        return $row;
    }

    /** @return array<string,mixed> */
    private function replayExisting(array $row, string $inputDigest, int $questionId): array
    {
        $readback = $this->normalizeAndVerify($row);
        if ((int)$readback['question_id'] !== $questionId
            || !hash_equals((string)$readback['input_digest'], $inputDigest)
        ) {
            throw new RuntimeException('feedback_idempotency_key_conflict', 409);
        }
        $readback['created'] = false;
        $readback['replayed'] = true;
        $readback['persistence_status'] = 'readback_verified';
        return $readback;
    }

    /** @return array<string,mixed>|null */
    private function findByIdempotency(int $tenantId, int $hotelId, int $createdBy, string $key): ?array
    {
        $row = Db::name(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('created_by', $createdBy)
            ->where('idempotency_key', $key)
            ->find();
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed> */
    private function normalizeCorrection(string $kind, array $input): array
    {
        $summary = trim((string)($input['correction_text'] ?? $input['correction_summary'] ?? ''));
        $issueCodes = $this->stringList($input['issue_codes'] ?? [], 10, 80);
        if ($kind === 'useful') {
            if ($summary !== '' || $issueCodes !== []) {
                throw new InvalidArgumentException('useful_feedback_cannot_include_correction');
            }
            return [];
        }
        if (mb_strlen($summary) < 4 || mb_strlen($summary) > 2000) {
            throw new InvalidArgumentException('correction_summary_length_invalid');
        }
        foreach ($issueCodes as $code) {
            if (preg_match('/^[a-z0-9_:-]{2,80}$/D', $code) !== 1) {
                throw new InvalidArgumentException('correction_issue_code_invalid');
            }
        }
        return ['summary' => $summary, 'issue_codes' => $issueCodes];
    }

    /** @return array<string,mixed> */
    private function normalizeScope(array $question): array
    {
        $scope = is_array($question['answer']['scope'] ?? null) ? $question['answer']['scope'] : [];
        return [
            'tenant_id' => (int)($scope['tenant_id'] ?? 0),
            'hotel_id' => (int)($scope['hotel_id'] ?? 0),
            'platform' => strtolower(trim((string)($scope['platform'] ?? ''))),
            'date_start' => (string)($scope['date_start'] ?? ''),
            'date_end' => (string)($scope['date_end'] ?? ''),
            'source_scope' => (string)($scope['source_scope'] ?? ''),
        ];
    }

    /** @return array<string,bool|string> */
    private function boundaries(): array
    {
        return [
            'usage_policy' => self::USAGE_POLICY,
            'original_analysis_mutated' => false,
            'formal_evaluation_case_created' => false,
            'model_training_triggered' => false,
            'external_model_called' => false,
            'ota_write' => false,
            'pms_write' => false,
            'external_message' => false,
            'automatic_execution' => false,
            'external_action_authorized' => false,
        ];
    }

    private function assertSchemaReady(): void
    {
        if (!$this->tableExists()) throw new RuntimeException('hotel_data_analyst_feedback_migration_required', 503);
    }

    private function tableExists(): bool
    {
        try {
            Db::name(self::TABLE)->limit(1)->select();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param list<int> $ids @return list<int> */
    private function positiveIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
    }

    /** @return list<string> */
    private function stringList(mixed $value, int $limit, int $length): array
    {
        if (!is_array($value)) $value = $value === null || $value === '' ? [] : [$value];
        return array_values(array_slice(array_unique(array_filter(array_map(
            static fn(mixed $item): string => is_scalar($item) ? mb_substr(strtolower(trim((string)$item)), 0, $length) : '',
            $value
        ))), 0, $limit));
    }

    private function digest(mixed $value): string
    {
        return hash('sha256', $this->encode($this->canonicalize($value)));
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> */
    private function decode(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (!is_string($value) || trim($value) === '') return [];
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) return array_map([$this, 'canonicalize'], $value);
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);
        return $value;
    }
}
