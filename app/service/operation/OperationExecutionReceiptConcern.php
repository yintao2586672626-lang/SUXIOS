<?php
declare(strict_types=1);
namespace app\service\operation;
trait OperationExecutionReceiptConcern
{
    /** Keep legacy omitted-status behavior identical at every execution gate. */
    private function normalizeExecutionTaskStatus(array $input): string
    {
        return trim((string)($input['status'] ?? 'executed'));
    }
    private static function assertExecutionTaskStatusAndReceipt(string $status, array $evidence): void
    {
        if (!in_array($status, ['executing', 'blocked', 'executed', 'failed'], true)) {
            throw new \InvalidArgumentException('execution status is not supported');
        }
        $response = self::executionReceiptArray($evidence['platform_response'] ?? []);
        if ($status === 'executed' && self::executionPlatformResponseReportsFailure($response)) {
            throw new \InvalidArgumentException('执行回执明确失败或已停止，不能标记为已执行');
        }
    }
    /** A meaningful failure receipt is evidence, but cannot prove completion. */
    private static function executionPlatformResponseReportsFailure(array $response): bool
    {
        foreach (['execution_failed', 'stop_condition_triggered'] as $field) {
            if (filter_var($response[$field] ?? false, FILTER_VALIDATE_BOOLEAN)) return true;
        }
        foreach (['status', 'execution_status'] as $field) {
            if (in_array(strtolower(trim((string)($response[$field] ?? ''))),
                ['failed', 'error', 'rejected', 'stopped', 'rolled_back'], true)) return true;
        }
        foreach (['operator_execution_evidence', 'execution_receipt', 'platform_receipt', 'action_receipt', 'receipt'] as $field) {
            $nested = self::executionReceiptArray($response[$field] ?? []);
            if ($nested !== [] && self::executionPlatformResponseReportsFailure($nested)) return true;
        }
        return false;
    }
    /**
     * One authoritative minimum contract for a real execution receipt.
     *
     * Free-form remarks and arbitrary response JSON are context only. A receipt
     * must contain paired before/after state, an attachment, a structured action
     * or platform receipt, a validated operating-node record, or an explicit
     * stop/failure signal. Outcome readbacks are deliberately excluded because
     * they prove observation, not execution.
     *
     * @param array<string,mixed> $evidence
     */
    public static function isMeaningfulExecutionReceipt(array $evidence, ?int $expectedOperatorId = null): bool
    {
        $evidenceType = strtolower(trim((string)($evidence['evidence_type'] ?? '')));
        if (in_array($evidenceType, [
            'manual_finance',
            'manual_roi_evidence',
            'operator_attested_platform_readback',
            'source_verified_metric_readback',
        ], true)) {
            return false;
        }
        $createdBy = array_key_exists('created_by', $evidence)
            ? (int)$evidence['created_by']
            : null;
        if ($expectedOperatorId !== null
            && ($expectedOperatorId <= 0 || $createdBy !== $expectedOperatorId)
        ) {
            return false;
        }
        if ($createdBy !== null && $createdBy <= 0) {
            return false;
        }
        $before = self::executionReceiptArray($evidence['before'] ?? $evidence['before_json'] ?? []);
        $after = self::executionReceiptArray($evidence['after'] ?? $evidence['after_json'] ?? []);
        if (self::executionReceiptContainsAuditableStateChange($before, $after)) {
            return true;
        }

        $attachment = trim((string)($evidence['attachment_path'] ?? ''));
        if ($attachment !== '') {
            return true;
        }

        $platformResponse = self::executionReceiptArray(
            $evidence['platform_response'] ?? $evidence['platform_response_json'] ?? []
        );
        return self::executionPlatformResponseContainsReceipt($platformResponse);
    }

    /** @return array<string,mixed> */
    private static function executionReceiptArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function executionReceiptValueIsMeaningful(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::executionReceiptValueIsMeaningful($item)) {
                    return true;
                }
            }
            return false;
        }
        if (is_string($value)) {
            return trim($value) !== '';
        }
        return $value !== null && $value !== false;
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $after */
    private static function executionReceiptContainsAuditableStateChange(array $before, array $after): bool
    {
        if ($before === [] || $after === []) {
            return false;
        }
        foreach (array_intersect(array_keys($before), array_keys($after)) as $key) {
            $beforeValue = $before[$key];
            $afterValue = $after[$key];
            $path = [(string)$key];
            if (is_array($beforeValue) && is_array($afterValue)) {
                if (self::executionReceiptStateFieldIsMeaningful($path)
                    && self::executionReceiptCanonicalValue($beforeValue)
                        !== self::executionReceiptCanonicalValue($afterValue)
                    && (self::executionReceiptValueIsMeaningful($beforeValue)
                        || self::executionReceiptValueIsMeaningful($afterValue))
                ) {
                    return true;
                }
                if (self::executionReceiptNestedStateChanged($beforeValue, $afterValue, $path)) {
                    return true;
                }
                continue;
            }
            if (self::executionReceiptStateFieldIsMeaningful($path)
                && self::executionReceiptCanonicalValue($beforeValue)
                    !== self::executionReceiptCanonicalValue($afterValue)
                && (self::executionReceiptValueIsMeaningful($beforeValue)
                    || self::executionReceiptValueIsMeaningful($afterValue))
            ) {
                return true;
            }
        }
        return false;
    }

    /** @param array<mixed> $before @param array<mixed> $after @param list<string> $path */
    private static function executionReceiptNestedStateChanged(array $before, array $after, array $path): bool
    {
        foreach (array_intersect(array_keys($before), array_keys($after)) as $key) {
            $beforeValue = $before[$key];
            $afterValue = $after[$key];
            $nextPath = [...$path, (string)$key];
            if (is_array($beforeValue) && is_array($afterValue)) {
                if (self::executionReceiptStateFieldIsMeaningful($nextPath)
                    && self::executionReceiptCanonicalValue($beforeValue)
                        !== self::executionReceiptCanonicalValue($afterValue)
                    && (self::executionReceiptValueIsMeaningful($beforeValue)
                        || self::executionReceiptValueIsMeaningful($afterValue))
                ) {
                    return true;
                }
                if (self::executionReceiptNestedStateChanged($beforeValue, $afterValue, $nextPath)) {
                    return true;
                }
                continue;
            }
            if (self::executionReceiptStateFieldIsMeaningful($nextPath)
                && self::executionReceiptCanonicalValue($beforeValue)
                    !== self::executionReceiptCanonicalValue($afterValue)
                && (self::executionReceiptValueIsMeaningful($beforeValue)
                    || self::executionReceiptValueIsMeaningful($afterValue))
            ) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $path */
    private static function executionReceiptStateFieldIsMeaningful(array $path): bool
    {
        $field = implode('_', array_filter(
            array_map(static function (string $segment): string {
                if (ctype_digit($segment)) {
                    return '';
                }
                $segment = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $segment) ?? $segment;
                return strtolower(preg_replace('/[^a-zA-Z0-9_\x{4e00}-\x{9fff}]+/u', '_', $segment) ?? '');
            }, $path),
            static fn(string $segment): bool => $segment !== ''
        ));
        if ($field === '') {
            return false;
        }
        if (preg_match(
            '/(?:^|_)(?:action|active|enabled|status|state|availability|inventory|stock|quota|room|nights|price|rate|amount|value|image|title|content|description|campaign|promotion|discount|coupon|policy|setting|config|switch|exposure|traffic|view|impression|click|order|booking|conversion|occupancy|adr|revpar|revenue|cost|profit|rank|score|service|breakfast|cancellation|refund|commission|bid|budget|target|threshold|schedule|date|time|limit|allocation|channel|product|package|tag|benefit|amenity)(?:_|$)/',
            $field
        ) === 1) {
            return true;
        }
        foreach ([
            '动作', '启用', '状态', '库存', '房量', '房型', '价格', '房价', '金额', '数值',
            '图片', '标题', '内容', '活动', '促销', '折扣', '政策', '配置', '开关', '曝光',
            '流量', '点击', '订单', '预订', '转化', '入住率', '营收', '收入', '成本', '利润',
            '排名', '评分', '服务', '早餐', '取消', '退款', '佣金', '预算', '目标', '日期',
            '时间', '渠道', '产品', '套餐', '标签', '权益', '设施',
        ] as $token) {
            if (str_contains($field, $token)) {
                return true;
            }
        }
        return false;
    }

    private static function executionReceiptCanonicalValue(mixed $value): string
    {
        $encoded = json_encode(
            self::executionReceiptCanonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
        return is_string($encoded) ? $encoded : '';
    }

    private static function executionReceiptCanonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::executionReceiptCanonicalize($item);
        }
        return $value;
    }

    /** @param array<string,mixed> $response */
    private static function executionPlatformResponseContainsReceipt(array $response): bool
    {
        foreach ([
            'completed_action',
            'executed_action',
            'applied_action',
            'action_result',
        ] as $field) {
            if (self::executionReceiptValueIsMeaningful($response[$field] ?? null)) {
                return true;
            }
        }

        foreach ([
            'receipt_id',
            'execution_receipt_id',
            'platform_receipt_id',
            'operation_id',
            'request_id',
            'transaction_id',
            'change_id',
            'source_ref',
        ] as $field) {
            $value = trim((string)($response[$field] ?? ''));
            if ($value !== '' && $value !== '0') {
                return true;
            }
        }

        foreach (['action_applied', 'execution_completed', 'execution_failed', 'stop_condition_triggered'] as $field) {
            if (filter_var($response[$field] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                return true;
            }
        }

        $beforeState = self::executionReceiptArray($response['before_state'] ?? []);
        $afterState = self::executionReceiptArray($response['after_state'] ?? []);
        if (self::executionReceiptContainsAuditableStateChange($beforeState, $afterState)) {
            return true;
        }

        $nodeRecord = self::executionReceiptArray($response['node_record'] ?? []);
        if (trim((string)($nodeRecord['contract_version'] ?? '')) !== ''
            && trim((string)($nodeRecord['recorded_at'] ?? '')) !== ''
            && trim((string)($nodeRecord['judgment_basis'] ?? '')) !== ''
            && trim((string)($nodeRecord['progress_status'] ?? '')) !== ''
        ) {
            return true;
        }

        $status = strtolower(trim((string)($response['status'] ?? $response['execution_status'] ?? '')));
        if (in_array($status, ['failed', 'error', 'rejected', 'stopped', 'rolled_back'], true)) {
            foreach (['error_code', 'error_message', 'failure_reason', 'blocked_reason'] as $field) {
                if (trim((string)($response[$field] ?? '')) !== '') {
                    return true;
                }
            }
        }

        foreach ([
            'operator_execution_evidence',
            'execution_receipt',
            'platform_receipt',
            'action_receipt',
            'receipt',
        ] as $field) {
            $nested = self::executionReceiptArray($response[$field] ?? []);
            if ($nested !== [] && self::executionPlatformResponseContainsReceipt($nested)) {
                return true;
            }
        }

        return false;
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
        $rawSummary = is_string($row['result_summary'] ?? null) ? $row['result_summary'] : null;
        unset($row['result_summary_sha256']);
        $row['id'] = (int)$row['id'];
        $row['intent_id'] = (int)$row['intent_id'];
        $row['hotel_id'] = (int)$row['hotel_id'];
        $row['operator_id'] = (int)($row['operator_id'] ?? 0);
        $row['action_track_id'] = (int)($row['action_track_id'] ?? 0);
        $row['current_value'] = $this->decodeJson((string)($row['current_value_json'] ?? ''));
        $row['target_value'] = $this->decodeJson((string)($row['target_value_json'] ?? ''));
        unset($row['current_value_json'], $row['target_value_json']);

        $sanitized = $this->sanitizeLegacyExecutionValue($row);
        if (is_array($sanitized) && $rawSummary !== null) {
            try {
                $this->assertExecutionPayloadHasNoCredentialMaterial($rawSummary);
                $decodedSummary = json_decode($rawSummary, true);
                if (is_array($decodedSummary)) {
                    $this->assertExecutionPayloadHasNoCredentialMaterial($decodedSummary);
                }
                $displaySummary = $sanitized['result_summary'] ?? null;
                $unchangedValues = is_array($decodedSummary)
                    ? $this->sanitizeLegacyExecutionValue($decodedSummary, 2) === $decodedSummary
                    : $displaySummary === $rawSummary;
                // Attest stored text only when existing safety filtering changed no field values.
                if ($unchangedValues) {
                    $sanitized['result_summary_sha256'] = hash('sha256', $rawSummary);
                }
            } catch (\InvalidArgumentException) {
                // Historical credential material remains redacted and must expose no raw-text digest.
            }
        }
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

}
