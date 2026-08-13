<?php
declare(strict_types=1);

namespace app\service\operation;

trait OperationExecutionReceiptConcern
{
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
}
