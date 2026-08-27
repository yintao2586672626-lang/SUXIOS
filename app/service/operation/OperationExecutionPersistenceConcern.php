<?php
declare(strict_types=1);

namespace app\service\operation;

use app\service\KnowledgeSopExecutionProvenanceService;
use app\service\OperatingOpportunityLabService;
use app\service\OperatingQuestionExecutionBridgeService;
use app\service\OperationActionLifecycleService;
use app\service\RevenueCockpitActionContract;
use app\service\SourceBackedExecutionIntentIdentityService;
use DateTimeImmutable;
use DateTimeZone;
use think\facade\Db;
use Throwable;

/**
 * Execution intent persistence, idempotency, evidence normalization and
 * credential-boundary helpers shared by OperationManagementService.
 */
trait OperationExecutionPersistenceConcern
{
    private function executionIntentBlockedReasons(string $objectType, array $input, array $targetValue, array $evidence): array
    {
        $reasons = [];
        foreach (['platform', 'object_type', 'action_type'] as $field) {
            if (trim((string)($input[$field] ?? '')) === '') {
                $reasons[] = $field . ' missing';
            }
        }
        if (empty($targetValue)) {
            $reasons[] = 'target_value missing';
        }
        if (!$this->hasNonEmptyValue($evidence)) {
            $reasons[] = 'evidence missing';
        }

        if ($objectType === 'price') {
            foreach (['room_type_key', 'rate_plan_key', 'target_price'] as $field) {
                if (!array_key_exists($field, $targetValue) || trim((string)$targetValue[$field]) === '') {
                    $reasons[] = $field . ' missing';
                }
            }
            if (array_key_exists('target_price', $targetValue)
                && (!is_numeric($targetValue['target_price']) || (float)$targetValue['target_price'] <= 0)
            ) {
                $reasons[] = 'target_price must be positive';
            }
        } elseif ($objectType === 'inventory') {
            if (trim((string)($targetValue['room_type_key'] ?? '')) === '') {
                $reasons[] = 'room_type_key missing';
            }
            if (!array_key_exists('target_inventory', $targetValue) && trim((string)($targetValue['sell_status'] ?? '')) === '') {
                $reasons[] = 'target_inventory or sell_status missing';
            }
        } elseif ($objectType === 'campaign') {
            foreach (['campaign_type', 'target_metric'] as $field) {
                if (trim((string)($targetValue[$field] ?? '')) === '') {
                    $reasons[] = $field . ' missing';
                }
            }
        } elseif ($objectType === 'room_product') {
            foreach (['room_type_key', 'target_metric'] as $field) {
                if (trim((string)($targetValue[$field] ?? '')) === '') {
                    $reasons[] = $field . ' missing';
                }
            }
        } elseif ($objectType === 'data_collection') {
            if (trim((string)($targetValue['collection_scope'] ?? '')) === '' && trim((string)($targetValue['target_date'] ?? '')) === '') {
                $reasons[] = 'collection_scope or target_date missing';
            }
            if (empty($evidence['evidence_refs']) && empty($evidence['data_gaps'])) {
                $reasons[] = 'ota evidence refs or data_gaps missing';
            }
        } elseif ($objectType === 'operation_checklist') {
            foreach (['title', 'action_text'] as $field) {
                if (trim((string)($targetValue[$field] ?? '')) === '') {
                    $reasons[] = $field . ' missing';
                }
            }
            if (!is_array($targetValue['steps'] ?? null) || $targetValue['steps'] === []) {
                $reasons[] = 'steps missing';
            }
            if (!is_array($targetValue['acceptance_criteria'] ?? null) || $targetValue['acceptance_criteria'] === []) {
                $reasons[] = 'acceptance_criteria missing';
            }
            if (empty($evidence['evidence_refs'])) {
                $reasons[] = 'knowledge evidence refs missing';
            }
        } elseif ($objectType === 'investment') {
            foreach (['project_name', 'tracking_status', 'target_metric'] as $field) {
                if (trim((string)($targetValue[$field] ?? '')) === '') {
                    $reasons[] = $field . ' missing';
                }
            }
            $sourceModule = trim((string)($input['source_module'] ?? ''));
            if (in_array($sourceModule, ['strategy_simulation', 'quant_simulation'], true)) {
                $readinessStage = trim((string)($evidence['readiness_stage'] ?? ''));
                if ($readinessStage === '') {
                    $reasons[] = 'simulation_readiness_stage missing';
                } elseif (!in_array($readinessStage, ['review_ready', 'approved_pending_execution', 'execution_ready'], true)) {
                    $reasons[] = $readinessStage;
                }
                if (!empty($evidence['data_gaps'])) {
                    $reasons[] = 'simulation_readiness_gaps_pending';
                }
            }
        } elseif ($objectType === 'opening') {
            foreach (['project_name', 'tracking_status', 'target_metric'] as $field) {
                if (trim((string)($targetValue[$field] ?? '')) === '') {
                    $reasons[] = $field . ' missing';
                }
            }
        } elseif ($objectType === 'expansion') {
            foreach (['project_name', 'tracking_status', 'target_metric'] as $field) {
                if (trim((string)($targetValue[$field] ?? '')) === '') {
                    $reasons[] = $field . ' missing';
                }
            }
            $readinessStage = trim((string)($evidence['readiness_stage'] ?? ''));
            if ($readinessStage === '') {
                $reasons[] = 'expansion_readiness_stage missing';
            } elseif (!in_array($readinessStage, ['review_ready', 'approved_pending_tracking'], true)) {
                $reasons[] = 'expansion_readiness_stage ' . $readinessStage;
            }
        } elseif ($objectType === 'revenue_research') {
            foreach (['research_product', 'action_text', 'target_metric'] as $field) {
                if (trim((string)($targetValue[$field] ?? '')) === '') {
                    $reasons[] = $field . ' missing';
                }
            }
            $readinessStage = trim((string)($evidence['research_readiness_stage'] ?? ''));
            if ($readinessStage === '') {
                $reasons[] = 'research_readiness_stage missing';
            } elseif ($readinessStage !== 'research_ready_for_execution') {
                $reasons[] = $readinessStage;
            }
            if (!empty($evidence['data_gaps'])) {
                $reasons[] = 'research_data_gaps_pending';
            }
        } elseif ($objectType !== '') {
            $reasons[] = 'object_type not supported';
        }

        return array_values(array_unique($reasons));
    }

    private function assertExecutionPayloadHasNoCredentialMaterial(mixed $value, int $depth = 0): void
    {
        if ($depth > 32) {
            throw new \InvalidArgumentException('Operation execution payload nesting is too deep.');
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if ($this->isExecutionCredentialKey((string)$key)
                    && !$this->isEmptyOrRedactedCredentialValue($item)) {
                    throw new \InvalidArgumentException('Operation execution payload contains reusable credential material.');
                }
                $this->assertExecutionPayloadHasNoCredentialMaterial($item, $depth + 1);
            }
            return;
        }

        if (is_object($value)) {
            $this->assertExecutionPayloadHasNoCredentialMaterial(get_object_vars($value), $depth + 1);
            return;
        }

        if (is_string($value) && $this->containsExecutionCredentialText($value)) {
            throw new \InvalidArgumentException('Operation execution payload contains reusable credential material.');
        }
    }

    private function isExecutionCredentialKey(string $key): bool
    {
        $normalized = strtolower((string)(preg_replace('/[^a-z0-9]/i', '', $key) ?? ''));
        return isset(self::EXECUTION_CREDENTIAL_KEYS[$normalized]);
    }

    private function isEmptyOrRedactedCredentialValue(mixed $value): bool
    {
        if ($value === null || $value === false || $value === 0 || $value === [] || $value === '') {
            return true;
        }
        if (!is_string($value)) {
            return false;
        }

        return in_array(strtolower(trim($value)), [
            '***',
            '[redacted]',
            'redacted',
            '[masked]',
            'masked',
            'missing',
            'unavailable',
            'expired',
            'invalid',
            'revoked',
            'unknown',
            'none',
            'null',
            'empty',
            'omitted',
            'not_configured',
        ], true);
    }

    private function containsExecutionCredentialText(string $value): bool
    {
        if (trim($value) === '') {
            return false;
        }

        $safeStatus = '(?:\*{3,}|\[?redacted\]?|\[?masked\]?|missing|unavailable|expired|invalid|revoked|unknown|none|null|empty|omitted|not_configured)';
        if (preg_match('/\b(?:authorization|cookie|set-cookie)\s*:\s*(?!' . $safeStatus . '\b)[^\r\n]+/iu', $value) === 1) {
            return true;
        }

        return preg_match(
            '/["\']?(?:authorization|auth_data|auth_token|access_token|refresh_token|token|cookies?|password|client_secret|api_secret|spidertoken|mtgsig)["\']?\s*[:=]\s*["\']?(?!' . $safeStatus . '(?:["\']|\b))[^\s,;}"\']+/iu',
            $value
        ) === 1;
    }

    private function sanitizeLegacyExecutionValue(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 32) {
            return '[redacted]';
        }

        if (is_array($value)) {
            $safe = [];
            foreach ($value as $key => $item) {
                if ($this->isExecutionCredentialKey((string)$key)) {
                    $safe[$key] = '[redacted]';
                    continue;
                }
                $safe[$key] = $this->sanitizeLegacyExecutionValue($item, $depth + 1);
            }
            return $safe;
        }

        if (is_object($value)) {
            return '[redacted]';
        }

        if (!is_string($value) || trim($value) === '') {
            return $value;
        }

        $trimmed = trim($value);
        if (($trimmed[0] ?? '') === '{' || ($trimmed[0] ?? '') === '[') {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                return json_encode(
                    $this->sanitizeLegacyExecutionValue($decoded, $depth + 1),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ) ?: '{}';
            }
        }

        return $this->sanitizeLegacyExecutionText($value);
    }

    private function sanitizeLegacyExecutionText(string $value): string
    {
        $value = preg_replace(
            '/(\b(?:authorization|cookie|set-cookie)\s*:\s*)[^\r\n]+/iu',
            '$1[redacted]',
            $value
        ) ?? $value;

        return preg_replace(
            '/(["\']?(?:authorization|auth_data|auth_token|access_token|refresh_token|token|cookies?|password|client_secret|api_secret|spidertoken|mtgsig)["\']?\s*[:=]\s*)["\']?[^\s,;}"\']+["\']?/iu',
            '$1[redacted]',
            $value
        ) ?? $value;
    }

    private function arrayValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $factors
     * @return array<string, mixed>
     */
    private function latestManualReviewFromFactors(array $factors): array
    {
        if (is_array($factors['manual_review'] ?? null)) {
            return $factors['manual_review'];
        }

        $versions = is_array($factors['manual_review_versions'] ?? null)
            ? array_values($factors['manual_review_versions'])
            : [];
        $last = end($versions);

        return is_array($last) ? $last : [];
    }

    /**
     * @param array<string, mixed> $review
     */
    private function manualApprovedPriceFromReview(array $review): ?float
    {
        if (($review['action'] ?? '') !== 'approve_with_changes') {
            return null;
        }

        $price = $review['approved_price'] ?? null;
        if (is_string($price)) {
            $price = preg_replace('/[^\d.\-]/', '', $price) ?? '';
        }
        if ($price === null || $price === '' || !is_numeric($price)) {
            return null;
        }

        $number = round((float)$price, 2);

        return $number > 0 ? $number : null;
    }

    private function normalizeExecutionDate(string $date): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Shanghai'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false
            || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
            || $parsed->format('Y-m-d') !== $date
        ) {
            throw new \InvalidArgumentException('execution date must be a valid YYYY-MM-DD calendar date');
        }

        return $date;
    }

    private function ensureExecutionTables(): void
    {
        foreach (['operation_execution_intents', 'operation_execution_tasks', 'operation_execution_evidence'] as $table) {
            if (!$this->tableExists($table)) {
                throw new \RuntimeException($table . ' table does not exist, run database migration first');
            }
        }
    }

    /** @param array<string, mixed> $payload */
    private function expansionExecutionIntentIdempotencyKey(array $payload): string
    {
        return 'expansion:v1:' . (int)$payload['source_record_id'];
    }

    /** @param array<string, mixed> $payload */
    private function priceSuggestionExecutionIntentIdempotencyKey(array $payload): string
    {
        return 'price_suggestion:v1:' . (int)$payload['source_record_id'];
    }

    /** @param array<string, mixed> $payload */
    private function knowledgeSopExecutionIntentIdempotencyKey(array $payload): string
    {
        $evidence = is_array($payload['evidence'] ?? null) ? $payload['evidence'] : [];
        $provenance = is_array($evidence['knowledge_provenance'] ?? null)
            ? $evidence['knowledge_provenance']
            : [];
        $targetValue = is_array($payload['target_value'] ?? null) ? $payload['target_value'] : [];
        $unitId = (int)($provenance['knowledge_unit_id'] ?? 0);
        $chunkId = (int)($provenance['knowledge_chunk_id'] ?? 0);
        $hotelId = (int)($payload['hotel_id'] ?? 0);
        $platform = strtolower(trim((string)($payload['platform'] ?? '')));
        $contentDigest = strtolower(trim((string)($provenance['content_digest'] ?? '')));
        $unitAuthorityDigest = strtolower(trim((string)($provenance['unit_authority_digest'] ?? '')));

        if (($provenance['contract_version'] ?? '') !== KnowledgeSopExecutionProvenanceService::CONTRACT_VERSION
            || $unitId <= 0
            || $chunkId <= 0
            || $chunkId !== (int)($payload['source_record_id'] ?? 0)
            || $hotelId <= 0
            || $hotelId !== (int)($provenance['target_hotel_id'] ?? 0)
            || $platform === ''
            || $platform !== strtolower(trim((string)($provenance['resolved_platform'] ?? '')))
            || preg_match('/^[a-f0-9]{64}$/D', $contentDigest) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $unitAuthorityDigest) !== 1
        ) {
            throw new \InvalidArgumentException('knowledge SOP idempotency provenance is invalid');
        }

        $identity = [
            'contract_version' => KnowledgeSopExecutionProvenanceService::CONTRACT_VERSION,
            'knowledge_unit_id' => $unitId,
            'knowledge_chunk_id' => $chunkId,
            'content_digest' => $contentDigest,
            'unit_authority_digest' => $unitAuthorityDigest,
            'target_hotel_id' => $hotelId,
            'resolved_platform' => $platform,
            'action_type' => trim((string)($payload['action_type'] ?? '')),
            'date_start' => trim((string)($payload['date_start'] ?? '')),
            'date_end' => trim((string)($payload['date_end'] ?? '')),
            'assignee_id' => (int)($targetValue['assignee_id'] ?? 0),
        ];
        $encoded = json_encode(
            $identity,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        return 'knowledge_sop_' . md5($encoded);
    }

    private function normalizeTrustedExecutionIntentIdempotencyKey(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^(?:expansion:v1:[1-9][0-9]*|ota_diagnosis_action_[a-f0-9]{32}:attempt:[1-9][0-9]*|operation_alert_[a-f0-9]{32}|operating_target_[a-f0-9]{32}|operation_optimizer_[a-f0-9]{32}|operating_network_replication_[a-f0-9]{32}|operating_question_action_[a-f0-9]{32}|operation_action_[a-f0-9]{32}|daily_one_thing_action_[a-f0-9]{32}|source_intent_[a-f0-9]{32})$/D', $value) !== 1) {
            throw new \InvalidArgumentException('trusted execution-intent idempotency key is invalid');
        }
        return $value;
    }

    private function normalizeOtaDiagnosisExecutionIntentBaseKey(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^ota_diagnosis_action_[a-f0-9]{32}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('OTA diagnosis execution-intent base key is invalid');
        }
        return $value;
    }

    /** @param array<string, mixed> $schedule @return array<string, mixed> */
    private function normalizePendingExecutionIntentSchedule(array $schedule): array
    {
        $assigneeId = (int)($schedule['assignee_id'] ?? 0);
        if ($assigneeId <= 0) {
            throw new \InvalidArgumentException('assignee_id is required');
        }
        $timezone = new \DateTimeZone('Asia/Shanghai');
        $parse = static function (mixed $value, string $field) use ($timezone): \DateTimeImmutable {
            $value = trim(str_replace('T', ' ', (string)$value));
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(?::\d{2})?$/D', $value) !== 1) {
                throw new \InvalidArgumentException($field . ' must use YYYY-MM-DD HH:MM[:SS]');
            }
            $value = strlen($value) === 16 ? $value . ':00' : $value;
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $timezone);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date === false
                || ($errors !== false && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0))
                || $date->format('Y-m-d H:i:s') !== $value
            ) {
                throw new \InvalidArgumentException($field . ' must be a valid date-time');
            }
            return $date;
        };
        $dueAt = $parse($schedule['due_at'] ?? '', 'due_at');
        $reviewAt = $parse($schedule['review_at'] ?? '', 'review_at');
        if ($dueAt <= new \DateTimeImmutable('now', $timezone)) {
            throw new \InvalidArgumentException('due_at must be later than the current time');
        }
        if ($reviewAt <= $dueAt) {
            throw new \InvalidArgumentException('review_at must be later than due_at');
        }

        return [
            'assignee_id' => $assigneeId,
            'due_at' => $dueAt->format('Y-m-d H:i:s'),
            'review_at' => $reviewAt->format('Y-m-d H:i:s'),
            'source_policy' => 'human_assigned_schedule_requires_manual_approval_and_readback_review',
        ];
    }

    /** @param array<string, mixed> $payload */
    private function replayTrustedExecutionIntent(string $idempotencyKey, array $payload, array $hotelIds): ?array
    {
        try {
            $row = Db::name('operation_execution_intents')
                ->where('idempotency_key', $idempotencyKey)
                ->whereNull('deleted_at')
                ->field('id,tenant_id,source_module,source_record_id,hotel_id,platform,object_type,action_type')
                ->find();
        } catch (Throwable $e) {
            $message = strtolower($e->getMessage());
            if (str_contains($message, 'unknown column')
                || str_contains($message, 'no such column')
                || str_contains($message, 'undefined column')
            ) {
                throw new \RuntimeException(
                    'operation_execution_intents.idempotency_key is unavailable; run the 20260716 execution-intent idempotency migration first',
                    500,
                    $e
                );
            }
            throw $e;
        }

        if (!$row) {
            return null;
        }
        $row['source_module'] = $this->canonicalExecutionSourceModule($row['source_module'] ?? '');
        $payload['source_module'] = $this->canonicalExecutionSourceModule($payload['source_module'] ?? '');
        if (SourceBackedExecutionIntentIdentityService::supports($payload)
            && !$this->sourceBackedReplayTenantIsCurrent($row, $payload)
        ) {
            throw new \RuntimeException(
                'execution-intent idempotency key is outside the current tenant scope',
                409
            );
        }
        foreach (['source_module', 'source_record_id', 'hotel_id', 'platform', 'object_type', 'action_type'] as $field) {
            if ((string)($row[$field] ?? '') !== (string)($payload[$field] ?? '')) {
                throw new \RuntimeException('execution-intent idempotency key is already linked to a different request', 409);
            }
        }

        $intent = $this->executionIntentDetail((int)$row['id'], $hotelIds);
        if (SourceBackedExecutionIntentIdentityService::supports($payload)) {
            $storedEvidence = is_array($intent['evidence'] ?? null) ? $intent['evidence'] : [];
            $currentEvidence = is_array($payload['evidence'] ?? null) ? $payload['evidence'] : [];
            $storedDigest = strtolower(trim((string)($storedEvidence['source_snapshot_digest'] ?? '')));
            $currentDigest = strtolower(trim((string)($currentEvidence['source_snapshot_digest'] ?? '')));
            $storedHasDigest = preg_match('/^[a-f0-9]{64}$/D', $storedDigest) === 1;
            $currentHasDigest = preg_match('/^[a-f0-9]{64}$/D', $currentDigest) === 1;
            if ($storedHasDigest !== $currentHasDigest
                || ($storedHasDigest && !hash_equals($storedDigest, $currentDigest))) {
                throw new \InvalidArgumentException('source-backed execution snapshot changed; create a new execution intent');
            }
        }
        $intent['idempotent_replay'] = true;
        return $intent;
    }

    /** @param array<string,mixed> $payload @param array<int,int|string> $hotelIds */
    private function replayLegacyExpansionExecutionIntent(array $payload, array $hotelIds): ?array
    {
        $rows = Db::name('operation_execution_intents')
            ->whereRaw('LOWER(TRIM(`source_module`)) = ?', ['expansion'])
            ->where('source_record_id', (int)($payload['source_record_id'] ?? 0))
            ->whereNull('deleted_at')
            ->order('id', 'asc')
            ->select()->toArray();
        $legacyKey = $this->expansionExecutionIntentIdempotencyKey($payload);
        foreach ($rows as $row) {
            if (!$this->sourceBackedReplayTenantIsCurrent($row, $payload) || !$this->sourceBackedIntentTenantIsCurrent($row)) {
                continue;
            }
            if ((int)($row['hotel_id'] ?? 0) !== (int)($payload['hotel_id'] ?? 0)) {
                throw new \RuntimeException('expansion record is already linked to an execution intent for a different hotel', 409);
            }
            if ((string)($row['idempotency_key'] ?? '') !== $legacyKey) {
                continue;
            }
            foreach (['platform', 'object_type', 'action_type'] as $field) {
                if ((string)($row[$field] ?? '') !== (string)($payload[$field] ?? '')) {
                    throw new \RuntimeException('expansion execution source is already linked to a different request', 409);
                }
            }
            $stored = $this->normalizeExecutionIntentRow($row);
            $storedEvidence = is_array($stored['evidence'] ?? null) ? $stored['evidence'] : [];
            $currentEvidence = is_array($payload['evidence'] ?? null) ? $payload['evidence'] : [];
            $storedDigest = strtolower(trim((string)($storedEvidence['source_snapshot_digest'] ?? '')));
            $currentDigest = strtolower(trim((string)($currentEvidence['source_snapshot_digest'] ?? '')));
            if (preg_match('/^[a-f0-9]{64}$/D', $storedDigest) !== 1
                || preg_match('/^[a-f0-9]{64}$/D', $currentDigest) !== 1 || !hash_equals($storedDigest, $currentDigest)) {
                continue;
            }

            $intent = $this->executionIntentDetail((int)$row['id'], $hotelIds);
            $intent['idempotent_replay'] = true;
            return $intent;
        }
        return null;
    }

    private function executionIntentRow(int $id, array $hotelIds, bool $lock = false): ?array
    {
        if ($id <= 0 || empty($hotelIds)) {
            return null;
        }

        $query = Db::name('operation_execution_intents')
            ->where('id', $id)
            ->whereIn('hotel_id', $hotelIds)
            ->whereNull('deleted_at');
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();

        return is_array($row) ? $row : null;
    }

    private function executionTaskRow(int $id, array $hotelIds, bool $lock = false): ?array
    {
        if ($id <= 0 || empty($hotelIds)) {
            return null;
        }

        $query = Db::name('operation_execution_tasks')
            ->where('id', $id)
            ->whereIn('hotel_id', $hotelIds)
            ->whereNull('deleted_at');
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();

        return is_array($row) ? $row : null;
    }

    private function executionIntentDetail(int $id, array $hotelIds): array
    {
        $this->assertExecutionTenantReadSchema();
        $row = $this->executionIntentRow($id, $hotelIds);
        if (!$row) {
            throw new \RuntimeException('execution intent not found');
        }
        if (!$this->sourceBackedIntentTenantIsCurrent($row)) {
            throw new \RuntimeException('execution intent not found in the current tenant scope');
        }

        $intent = $this->normalizeExecutionIntentRow($row);
        $taskQuery = Db::name('operation_execution_tasks')
            ->where('intent_id', $id)
            ->where('hotel_id', (int)$row['hotel_id'])
            ->whereNull('deleted_at');
        if (array_key_exists('tenant_id', $row)) {
            $taskQuery->where('tenant_id', (int)$row['tenant_id']);
        }
        $tasks = $taskQuery
            ->order('id', 'asc')
            ->select()
            ->toArray();
        $intent['tasks'] = array_map([$this, 'normalizeExecutionTaskRow'], $tasks);
        return (new OperationActionLifecycleService())->decorateCurrentDatabaseAggregate($intent);
    }

    private function executionTaskDetail(int $id, array $hotelIds): array
    {
        $this->assertExecutionTenantReadSchema();
        $context = $this->executionTaskAuthorizationContext($id, $hotelIds);
        $row = $context['task'];
        $task = $this->normalizeExecutionTaskRow($row);
        $intentRow = $context['intent'];

        $evidenceQuery = Db::name('operation_execution_evidence')
            ->where('task_id', $id)
            ->whereNull('deleted_at');
        if (array_key_exists('tenant_id', $row)) {
            $evidenceQuery->where('tenant_id', (int)$row['tenant_id']);
        }
        $evidenceRows = $evidenceQuery
            ->order('id', 'desc')
            ->select()
            ->toArray();
        $intent = $this->normalizeExecutionIntentRow($intentRow);
        $task['evidence'] = array_map([$this, 'normalizeExecutionEvidenceRow'], $evidenceRows);
        $effectEvidenceTypes = [
            'source_verified_metric_readback',
            'ota_source_readback',
            'business_metric_readback',
            'operator_attested_platform_readback',
            'manual_finance',
            'manual_roi_evidence',
        ];
        $task['execution_evidence'] = array_values(array_filter(
            $task['evidence'],
            static fn(array $evidence): bool => !in_array(
                strtolower(trim((string)($evidence['evidence_type'] ?? ''))),
                $effectEvidenceTypes,
                true
            )
        ));
        $task['effect_source_evidence'] = array_values(array_filter(
            $task['evidence'],
            static fn(array $evidence): bool => in_array(
                strtolower(trim((string)($evidence['evidence_type'] ?? ''))),
                $effectEvidenceTypes,
                true
            )
        ));
        $effectReviews = [];
        if ($this->tableExists(OperationEffectReviewService::TABLE)) {
            $effectReviewResult = (new OperationEffectReviewService($this->executionOutcomeService))->listForTask(
                (int)($task['tenant_id'] ?? $intent['tenant_id'] ?? 0),
                (int)($task['hotel_id'] ?? 0),
                (int)($task['intent_id'] ?? 0),
                (int)($task['id'] ?? 0)
            );
            $effectReviews = is_array($effectReviewResult['list'] ?? null)
                ? $effectReviewResult['list']
                : [];
        }
        $task['effect_reviews'] = $effectReviews;
        $verifiedEffectReviews = array_values(array_filter(
            $effectReviews,
            static fn(array $review): bool => ($review['readback_verified'] ?? false) === true
                && ($review['approval_contract_verified'] ?? false) === true
                && ($review['active_eligible'] ?? false) === true
                && ($review['outcome']['source_verified'] ?? false) === true
                && ($review['outcome']['outcome_verified'] ?? false) === true
                && ($review['causality_claimed'] ?? true) === false
        ));
        $approvalContractDrifted = count(array_filter(
            $effectReviews,
            static fn(array $review): bool => ($review['approval_contract_verified'] ?? false) !== true
        )) > 0;
        $activeEffectReview = null;
        foreach ($verifiedEffectReviews as $review) {
            if ((string)($review['result_status'] ?? '') === (string)($task['result_status'] ?? '')
                && (string)($review['result_summary'] ?? '') === (string)($task['result_summary'] ?? '')
            ) {
                $activeEffectReview = $review;
                break;
            }
        }
        $task['active_effect_review'] = $activeEffectReview;
        $task['effect_review_summary'] = [
            'count' => count($effectReviews),
            'verified_count' => count($verifiedEffectReviews),
            'approval_contract_verified_count' => count(array_filter(
                $effectReviews,
                static fn(array $review): bool => ($review['approval_contract_verified'] ?? false) === true
            )),
            'latest_id' => (int)($effectReviews[0]['id'] ?? 0),
            'active_id' => (int)($activeEffectReview['id'] ?? 0),
            'active_content_digest' => (string)($activeEffectReview['content_digest'] ?? ''),
            'current_result_bound' => $activeEffectReview !== null,
            'persistence_status' => $activeEffectReview !== null
                ? 'readback_verified'
                : ($approvalContractDrifted
                    ? 'approval_target_drifted'
                    : ($effectReviews === [] ? 'missing' : 'current_result_mismatch')),
            'execution_effect_storage_separated' => true,
            'causality_claimed' => false,
        ];
        $task['evidence_summary'] = $this->buildSafeExecutionEvidenceSummary($task['evidence'], $task, $intent);
        $task['evidence_truth'] = $this->buildExecutionEvidenceTruth($intent, $task, $task['evidence']);
        $legacyOutcomeTruth = $this->buildExecutionOutcomeTruth($intent, $task, $task['evidence']);
        $usesStrictEffectReview = in_array(strtolower(trim((string)($intent['source_module'] ?? ''))), [
            'ota_diagnosis_saved',
            OperatingQuestionExecutionBridgeService::SOURCE_MODULE,
            RevenueCockpitActionContract::SOURCE_MODULE,
            OperatingOpportunityLabService::DAILY_SOURCE_MODULE,
        ], true);
        $isTerminalReview = in_array((string)($task['result_status'] ?? ''), ['success', 'near_success', 'failed'], true);
        $task['outcome_truth'] = $usesStrictEffectReview && $isTerminalReview
            ? (is_array($activeEffectReview['outcome'] ?? null)
                ? $activeEffectReview['outcome']
                : [
                    'status' => 'unverified',
                    'source_verified' => false,
                    'outcome_verified' => false,
                    'positive_outcome_verified' => false,
                    'metric_key' => (string)($intent['expected_metric'] ?? ''),
                    'failure_reason' => $approvalContractDrifted
                        ? 'approval_target_contract_drifted'
                        : 'current_separate_effect_review_missing',
                    'causality_claimed' => false,
                ])
            : $legacyOutcomeTruth;
        $task['truth_context'] = $this->buildExecutionTruthContext(
            $intent,
            $task,
            $task['evidence_truth'],
            (string)($task['result_status'] ?? 'observing'),
            $task['outcome_truth']
        );
        $task['review_available_at'] = $this->executionReviewAvailableAt($intent, $task['evidence']);
        $task['review_available_on'] = $task['review_available_at'] !== ''
            ? substr($task['review_available_at'], 0, 10)
            : $this->executionReviewAvailableOn($task['evidence']);
        $reviewAvailableTimestamp = $task['review_available_at'] !== ''
            ? $this->operationShanghaiTimestampOrNull($task['review_available_at'])
            : null;
        $task['review_is_available'] = $task['review_available_on'] === ''
            || ($reviewAvailableTimestamp !== null
                ? $this->operationShanghaiDateTime() >= $reviewAvailableTimestamp
                : $task['review_available_on'] <= $this->operationShanghaiToday());
        $task['sop_candidate'] = $this->executionFlowReadService->buildSopCandidate(
            $intent,
            $task,
            $task['evidence_truth'],
            $task['outcome_truth'],
            (string)($task['result_status'] ?? 'observing')
        );
        if ($usesStrictEffectReview
            && $activeEffectReview === null
        ) {
            $reasonCodes = is_array($task['sop_candidate']['reason_codes'] ?? null)
                ? $task['sop_candidate']['reason_codes']
                : [];
            $reasonCodes[] = $approvalContractDrifted
                ? 'approval_target_contract_drifted'
                : 'separate_effect_review_readback_missing';
            $task['sop_candidate']['candidate_id'] = null;
            $task['sop_candidate']['status'] = 'not_ready';
            $task['sop_candidate']['approval_status'] = 'not_available';
            $task['sop_candidate']['reason_codes'] = array_values(array_unique($reasonCodes));
            $task['sop_candidate']['boundaries']['next_stage'] = $approvalContractDrifted
                ? 'reapprove_target_and_repeat_effect_review'
                : 'complete_separate_effect_review';
        }

        return (new OperationActionLifecycleService())->decorateTask($task, $intent);
    }

    private function executionReviewAvailableOn(array $evidenceRows): string
    {
        return $this->executionFlowReadService->reviewAvailableOn($evidenceRows);
    }

    /**
     * @param array<string, mixed> $intent
     * @param array<int, array<string, mixed>> $evidenceRows
     */
    private function executionReviewAvailableAt(array $intent, array $evidenceRows): string
    {
        if (in_array(strtolower(trim((string)($intent['source_module'] ?? ''))), [
            'ota_diagnosis_saved',
            OperatingQuestionExecutionBridgeService::SOURCE_MODULE,
            RevenueCockpitActionContract::SOURCE_MODULE,
            OperatingOpportunityLabService::DAILY_SOURCE_MODULE,
        ], true)) {
            $scheduledTimestamp = $this->savedOtaDiagnosisReviewTimestamp($intent);
            if ($scheduledTimestamp !== null) {
                return $this->operationShanghaiDateTime(new DateTimeImmutable('@' . $scheduledTimestamp))->format('Y-m-d H:i:s');
            }
        }

        $availableOn = $this->executionReviewAvailableOn($evidenceRows);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/D', $availableOn) === 1
            ? $availableOn . ' 00:00:00'
            : '';
    }

    /**
     * Keep a non-sensitive receipt visible after protected-response redaction removes
     * the raw evidence payload for non-super-admin operators.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array{count: int, types: array<int, string>, latest_type: string, latest_at: string}
     */
    private function buildSafeExecutionEvidenceSummary(array $rows, array $task = [], array $intent = []): array
    {
        return $this->executionFlowReadService->buildSafeEvidenceSummary($rows, $task, $intent);
    }

    private function normalizeExecutionIntentRow(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['source_module'] = $this->canonicalExecutionSourceModule($row['source_module'] ?? '');
        $row['hotel_id'] = (int)$row['hotel_id'];
        $row['source_record_id'] = (int)($row['source_record_id'] ?? 0);
        $row['expected_delta'] = ($row['expected_delta'] ?? null) === null
            ? null
            : (float)$row['expected_delta'];
        $row['current_value'] = $this->decodeJson((string)($row['current_value_json'] ?? ''));
        $row['target_value'] = $this->decodeJson((string)($row['target_value_json'] ?? ''));
        $row['evidence'] = $this->decodeJson((string)($row['evidence_json'] ?? ''));
        unset($row['idempotency_key'], $row['current_value_json'], $row['target_value_json'], $row['evidence_json']);

        $sanitized = $this->sanitizeLegacyExecutionValue($row);
        return is_array($sanitized) ? $sanitized : [];
    }

    private function normalizeExecutionTaskRow(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['intent_id'] = (int)$row['intent_id'];
        $row['hotel_id'] = (int)$row['hotel_id'];
        $row['operator_id'] = (int)($row['operator_id'] ?? 0);
        $row['action_track_id'] = (int)($row['action_track_id'] ?? 0);
        $row['current_value'] = $this->decodeJson((string)($row['current_value_json'] ?? ''));
        $row['target_value'] = $this->decodeJson((string)($row['target_value_json'] ?? ''));
        unset($row['current_value_json'], $row['target_value_json']);

        $sanitized = $this->sanitizeLegacyExecutionValue($row);
        return is_array($sanitized) ? $sanitized : [];
    }

    private function normalizeExecutionEvidenceRow(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['task_id'] = (int)$row['task_id'];
        $row['created_by'] = (int)($row['created_by'] ?? 0);
        $row['before'] = $this->decodeJson((string)($row['before_json'] ?? ''));
        $row['after'] = $this->decodeJson((string)($row['after_json'] ?? ''));
        $row['platform_response'] = $this->decodeJson((string)($row['platform_response_json'] ?? ''));
        unset($row['before_json'], $row['after_json'], $row['platform_response_json']);

        $sanitized = $this->sanitizeLegacyExecutionValue($row);
        return is_array($sanitized) ? $sanitized : [];
    }

    private function insertExecutionEvidence(array $payload, ?int $authorizedTenantId = null): int
    {
        $this->assertExecutionPayloadHasNoCredentialMaterial($payload);
        $taskId = (int)$payload['task_id'];
        $id = (int)Db::name('operation_execution_evidence')->insertGetId($this->withExecutionTaskTenantId([
            'task_id' => $taskId,
            'evidence_type' => (string)$payload['evidence_type'],
            'before_json' => json_encode($payload['before'] ?? [], JSON_UNESCAPED_UNICODE),
            'after_json' => json_encode($payload['after'] ?? [], JSON_UNESCAPED_UNICODE),
            'attachment_path' => (string)($payload['attachment_path'] ?? ''),
            'platform_response_json' => json_encode($payload['platform_response'] ?? [], JSON_UNESCAPED_UNICODE),
            'remark' => (string)($payload['remark'] ?? ''),
            'created_by' => (int)($payload['created_by'] ?? 0),
            'created_at' => (string)($payload['created_at'] ?? date('Y-m-d H:i:s')),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'operation_execution_evidence', $taskId, $authorizedTenantId));
        if ($id <= 0) {
            throw new \RuntimeException('execution evidence save failed: missing evidence id');
        }
        return $id;
    }

    private function buildExecutionEvidencePlatformResponse(
        array $evidence,
        array $task = [],
        array $intent = []
    ): array
    {
        $platformResponse = $this->arrayValue($evidence['platform_response'] ?? []);
        if (array_key_exists('node_record', $platformResponse)) {
            $platformResponse['node_record'] = $this->normalizeExecutionNodeRecord(
                $this->arrayValue($platformResponse['node_record']),
                $task,
                $intent
            );
        }
        foreach (['operator_execution_evidence', 'operator_roi_evidence'] as $key) {
            $operatorEvidence = $this->arrayValue($evidence[$key] ?? []);
            if ($operatorEvidence !== []) {
                $platformResponse[$key] = $operatorEvidence;
            }
        }

        return $platformResponse;
    }

    /** @param array<string, mixed> $record @return array<string, string> */
    private function normalizeExecutionNodeRecord(array $record, array $task = [], array $intent = []): array
    {
        if ($record === []) {
            throw new \InvalidArgumentException('revenue node record is empty');
        }

        $required = [
            'recorded_at',
            'operating_period',
            'source_scope',
            'room_status_alignment',
            'data_quality_status',
            'metric_definition',
            'comparison_basis',
            'progress_status',
            'judgment_basis',
            'success_criteria',
            'stop_condition',
        ];
        foreach ($required as $field) {
            if (trim((string)($record[$field] ?? '')) === '') {
                throw new \InvalidArgumentException('revenue node record missing required field: ' . $field);
            }
        }
        $contractVersion = trim((string)($record['contract_version'] ?? ''));
        if (!in_array($contractVersion, ['operation_revenue_node.v1', 'operation_revenue_node.v2'], true)) {
            throw new \InvalidArgumentException('revenue node record contract version is invalid');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', (string)$record['recorded_at']) !== 1) {
            throw new \InvalidArgumentException('revenue node recorded_at is invalid');
        }

        $enums = [
            'operating_period' => ['weekday', 'weekend', 'holiday', 'special_event'],
            'source_scope' => ['pms_ota_cross_check', 'pms', 'ctrip', 'meituan', 'manual_other'],
            'room_status_alignment' => ['operator_confirmed', 'mismatch', 'unverified'],
            'data_quality_status' => ['manual_confirmed', 'unverified', 'mismatch'],
            'progress_status' => ['normal', 'too_fast', 'too_slow', 'insufficient_evidence'],
        ];
        foreach ($enums as $field => $allowed) {
            if (!in_array((string)$record[$field], $allowed, true)) {
                throw new \InvalidArgumentException('revenue node record field is invalid: ' . $field);
            }
        }

        $normalized = ['contract_version' => $contractVersion];
        if ($contractVersion === 'operation_revenue_node.v2') {
            $systemHotelId = (int)($record['system_hotel_id'] ?? 0);
            $businessDate = trim((string)($record['business_date'] ?? ''));
            $taskHotelId = (int)($task['hotel_id'] ?? 0);
            $intentHotelId = (int)($intent['hotel_id'] ?? 0);
            $intentBusinessDate = substr(trim((string)($intent['date_start'] ?? '')), 0, 10);
            if ($systemHotelId <= 0) {
                throw new \InvalidArgumentException('revenue node record system_hotel_id is required');
            }
            if ($businessDate === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $businessDate) !== 1) {
                throw new \InvalidArgumentException('revenue node record business_date is required');
            }
            if ($taskHotelId <= 0
                || $intentHotelId <= 0
                || $systemHotelId !== $taskHotelId
                || $systemHotelId !== $intentHotelId
            ) {
                throw new \InvalidArgumentException('revenue node record system_hotel_id does not match execution task');
            }
            if ($intentBusinessDate === '' || $businessDate !== $intentBusinessDate) {
                throw new \InvalidArgumentException('revenue node record business_date does not match execution intent');
            }
            $normalized['system_hotel_id'] = (string)$systemHotelId;
            $normalized['business_date'] = $businessDate;
        }
        foreach (array_merge($required, ['special_event', 'metric_snapshot', 'primary_risk']) as $field) {
            $normalized[$field] = trim((string)($record[$field] ?? ''));
        }

        return $normalized;
    }

    private function createActionTrackForExecution(array $intent, int $taskId, ?int $authorizedTenantId = null): int
    {
        $target = $this->decodeJson((string)($intent['target_value_json'] ?? ''));
        $dateStart = (string)($intent['date_start'] ?? date('Y-m-d'));
        $hotelId = (int)$intent['hotel_id'];
        $before = $this->baseline([$hotelId], 7, $dateStart);

        return (int)Db::name('operation_action_tracks')->insertGetId($this->withHotelTenantId([
            'hotel_id' => $hotelId,
            'action_type' => (string)($intent['action_type'] ?? ''),
            'action_title' => 'execution_task_' . $taskId . '_' . (string)($intent['object_type'] ?? 'operation'),
            'start_date' => $dateStart,
            'end_date' => !empty($intent['date_end']) ? (string)$intent['date_end'] : null,
            'target_metric' => (string)($intent['expected_metric'] ?? $target['target_metric'] ?? ''),
            'target_change_rate' => ($intent['expected_delta'] ?? null) === null
                ? null
                : (float)$intent['expected_delta'],
            'before_data_json' => json_encode($before, JSON_UNESCAPED_UNICODE),
            'after_data_json' => json_encode([], JSON_UNESCAPED_UNICODE),
            'result_status' => 'observing',
            'result_summary' => '',
            'remark' => 'created from operation execution task',
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'operation_action_tracks', $hotelId, $authorizedTenantId));
    }
}
