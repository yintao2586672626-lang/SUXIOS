<?php
declare(strict_types=1);

namespace app\service\operation;

use app\service\KnowledgeSopExecutionProvenanceService;
use think\facade\Db;
use Throwable;

trait OperationExecutionIntentConcern
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
        if (!$this->hasMeaningfulExecutionEvidence($evidence)) {
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
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            throw new \InvalidArgumentException('计划执行日期格式无效');
        }

        return date('Y-m-d', $timestamp);
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
        if (preg_match('/^(?:ota_diagnosis_action_[a-f0-9]{32}:attempt:[1-9][0-9]*|operation_alert_[a-f0-9]{32}|operating_target_[a-f0-9]{32}|operation_optimizer_[a-f0-9]{32})$/D', $value) !== 1) {
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
                ->field('id,source_module,source_record_id,hotel_id,platform,object_type,action_type')
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
        foreach (['source_module', 'source_record_id', 'hotel_id', 'platform', 'object_type', 'action_type'] as $field) {
            if ((string)($row[$field] ?? '') !== (string)($payload[$field] ?? '')) {
                throw new \RuntimeException('execution-intent idempotency key is already linked to a different request', 409);
            }
        }

        $intent = $this->executionIntentDetail((int)$row['id'], $hotelIds);
        $intent['idempotent_replay'] = true;
        return $intent;
    }

    /** @param array<string, mixed> $payload */
    private function replayExpansionExecutionIntent(string $idempotencyKey, array $payload, array $hotelIds): ?array
    {
        try {
            $row = Db::name('operation_execution_intents')
                ->where('idempotency_key', $idempotencyKey)
                ->where('source_module', 'expansion')
                ->where('object_type', 'expansion')
                ->where('source_record_id', (int)$payload['source_record_id'])
                ->whereNull('deleted_at')
                ->field('id,hotel_id')
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
        if ((int)$row['hotel_id'] !== (int)$payload['hotel_id']) {
            throw new \RuntimeException('expansion record is already linked to an execution intent for a different hotel', 409);
        }

        return $this->executionIntentDetail((int)$row['id'], $hotelIds);
    }

}
