<?php
declare(strict_types=1);

namespace app\service;

final class SourceBackedExecutionIntentIdentityService
{
    private const BRIDGE_CONTAINER_FIELDS = [
        'execution_tracking',
        'tracking_records',
        'post_decision_tracking',
    ];

    private const BRIDGE_INTENT_REFERENCE_FIELDS = [
        'execution_intent_id',
        'operation_execution_intent_id',
        'latest_execution_intent_id',
    ];

    private const BRIDGE_AUXILIARY_FIELDS = [
        'tracking_record_id',
        'post_decision_tracking_id',
        'opening_project_id',
        'investment_tracking_id',
        'execution_status',
        'execution_idempotency_key',
        '_source_bridge_verified',
    ];

    private const MODULES = [
        'expansion',
        'feasibility_report',
        'opening',
        'transfer_decision',
        'strategy_simulation',
        'quant_simulation',
        'price_suggestion',
        'operation_alert',
    ];

    /** @param array<string, mixed> $payload */
    public static function supports(array $payload): bool
    {
        return in_array(self::canonicalSourceModule($payload['source_module'] ?? null), self::MODULES, true);
    }

    public static function canonicalSourceModule(mixed $sourceModule): string
    {
        return is_scalar($sourceModule) ? strtolower(trim((string)$sourceModule)) : '';
    }

    public static function canonicalPayloadKey(mixed $key): string
    {
        if (!is_string($key)) {
            return '';
        }
        $key = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', trim($key)) ?? $key;
        $key = strtolower($key);
        $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? $key;
        return trim($key, '_');
    }

    /** @param array<string, mixed> $payload */
    public static function key(array $payload, ?string $override): string
    {
        if ($override !== null) {
            throw new \InvalidArgumentException('source-backed execution intent cannot override its idempotency key');
        }
        if ((int)($payload['source_record_id'] ?? 0) <= 0) {
            throw new \InvalidArgumentException('source_record_id is required for source-backed execution intent');
        }
        $identity = [];
        foreach ([
            'tenant_id', 'source_module', 'source_record_id', 'hotel_id', 'platform', 'object_type', 'action_type',
            'date_start', 'date_end',
        ] as $field) {
            if (array_key_exists($field, $payload)) {
                $identity[$field] = $payload[$field];
            }
        }
        $identity['source_module'] = self::canonicalSourceModule($identity['source_module'] ?? null);
        if ((int)($identity['tenant_id'] ?? 0) <= 0) {
            throw new \InvalidArgumentException('tenant_id is required for source-backed execution intent identity');
        }
        $evidence = is_array($payload['evidence'] ?? null) ? $payload['evidence'] : [];
        $businessDigests = [];
        foreach (['source_snapshot_digest', 'source_record_digest', 'simulation_payload_digest'] as $field) {
            $digest = strtolower(trim((string)($evidence[$field] ?? '')));
            if (preg_match('/^[a-f0-9]{64}$/D', $digest) === 1) {
                $businessDigests[$field] = $digest;
            }
        }
        if ($businessDigests !== []) {
            // Readiness and execution-link projections can change after the
            // first link is persisted. The source digest is the stable
            // business snapshot identity; those projections must not create a
            // second intent for the same source fact.
            $identity['business_snapshot_digests'] = $businessDigests;
            if ($identity['source_module'] === 'price_suggestion') {
                $target = is_array($payload['target_value'] ?? null) ? $payload['target_value'] : [];
                $identity['price_execution_target'] = [
                    'room_type_id' => (int)($target['room_type_id'] ?? 0),
                    'room_type_key' => trim((string)($target['room_type_key'] ?? '')),
                    'rate_plan_key' => trim((string)($target['rate_plan_key'] ?? '')),
                    'expected_metric' => strtolower(trim((string)($payload['expected_metric'] ?? ''))),
                    'expected_delta' => (float)($payload['expected_delta'] ?? 0),
                    'risk_level' => strtolower(trim((string)($payload['risk_level'] ?? ''))),
                    'mapping_record_id' => trim((string)($evidence['ota_target_mapping']['mapping_record_id'] ?? '')),
                    'mapping_version' => trim((string)($evidence['ota_target_mapping']['mapping_version'] ?? '')),
                    'mapping_digest' => trim((string)($evidence['ota_target_mapping']['mapping_digest'] ?? '')),
                ];
            }
        } else {
            // Compatibility for older callers that do not yet publish a
            // formal source digest. Tracking-only fields are still excluded.
            foreach ([
                'current_value', 'target_value', 'evidence', 'expected_metric', 'expected_delta',
                'risk_level', 'blocked_reason', 'assignee_id', 'due_at', 'review_at',
            ] as $field) {
                if (array_key_exists($field, $payload)) {
                    $identity[$field] = self::withoutExecutionTracking($payload[$field]);
                }
            }
        }
        $encoded = json_encode(
            self::canonicalize($identity),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
        if (!is_string($encoded)) {
            throw new \RuntimeException('source-backed execution intent identity encoding failed');
        }
        return 'source_intent_' . md5($encoded);
    }

    /** @param array<string, mixed> $snapshot */
    public static function snapshotDigest(string $sourceModule, array $snapshot): string
    {
        $encoded = json_encode(
            self::canonicalize(self::withoutExecutionTracking([
                'source_module' => self::canonicalSourceModule($sourceModule),
                'snapshot' => $snapshot,
            ])),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
        if (!is_string($encoded)) {
            throw new \RuntimeException('source-backed execution snapshot encoding failed');
        }

        return hash('sha256', $encoded);
    }

    /** @param array<string,mixed> $source */
    public static function priceSuggestionSnapshotDigest(array $source): string
    {
        $jsonArray = static function (mixed $value): array {
            if (is_array($value)) {
                return $value;
            }
            if (!is_string($value) || trim($value) === '') {
                return [];
            }
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        };
        $snapshot = [
            'id' => (int)($source['id'] ?? 0),
            'tenant_id' => (int)($source['tenant_id'] ?? 0),
            'hotel_id' => (int)($source['hotel_id'] ?? 0),
            'room_type_id' => (int)($source['room_type_id'] ?? 0),
            'demand_forecast_id' => (int)($source['demand_forecast_id'] ?? 0),
            'suggestion_date' => trim((string)($source['suggestion_date'] ?? '')),
            'suggestion_type' => (int)($source['suggestion_type'] ?? 0),
            'current_price' => (float)($source['current_price'] ?? 0),
            'suggested_price' => (float)($source['suggested_price'] ?? 0),
            'min_price' => (float)($source['min_price'] ?? 0),
            'max_price' => (float)($source['max_price'] ?? 0),
            'confidence_score' => (float)($source['confidence_score'] ?? 0),
            'competitor_data' => $jsonArray($source['competitor_data'] ?? []),
            'factors' => $jsonArray($source['factors'] ?? []),
            'reason' => trim((string)($source['reason'] ?? '')),
            'status' => (int)($source['status'] ?? 0),
            'applied_by' => (int)($source['applied_by'] ?? 0),
            'applied_time' => trim((string)($source['applied_time'] ?? '')),
            'remark' => trim((string)($source['remark'] ?? '')),
        ];

        return self::snapshotDigest('price_suggestion', $snapshot);
    }

    /** @param array<string,mixed> $source */
    public static function operationAlertSnapshotDigest(array $source): string
    {
        $rawData = $source['raw_data'] ?? [];
        if (is_string($rawData)) {
            $decoded = json_decode($rawData, true);
            $rawData = is_array($decoded) ? $decoded : [];
        }
        $snapshot = [
            'tenant_id' => (int)($source['tenant_id'] ?? 0),
            'hotel_id' => (int)($source['hotel_id'] ?? 0),
            'alert_type' => strtolower(trim((string)($source['alert_type'] ?? ''))),
            'level' => strtolower(trim((string)($source['level'] ?? ''))),
            'title' => trim((string)($source['title'] ?? '')),
            'message' => trim((string)($source['message'] ?? '')),
            'source' => strtolower(trim((string)($source['source'] ?? ''))),
            'related_date' => trim((string)($source['related_date'] ?? '')),
            'action_suggestion' => trim((string)($source['action_suggestion'] ?? ($rawData['action_suggestion'] ?? ''))),
            'raw_data' => is_array($rawData) ? $rawData : [],
        ];

        return self::snapshotDigest('operation_alert', $snapshot);
    }

    private static function withoutExecutionTracking(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'withoutExecutionTracking'], $value);
        }
        $bridgeObject = self::hasDirectBridgeIntentReference($value);
        foreach ($value as $key => $item) {
            $canonicalKey = self::canonicalPayloadKey($key);
            if ($canonicalKey === '_source_bridge_verified'
                || in_array($canonicalKey, self::BRIDGE_INTENT_REFERENCE_FIELDS, true)
                || ($canonicalKey === 'post_decision_tracking' && is_bool($item))
                || ($bridgeObject && in_array($canonicalKey, self::BRIDGE_AUXILIARY_FIELDS, true))
                || (in_array($canonicalKey, self::BRIDGE_CONTAINER_FIELDS, true)
                    && self::looksLikeBridgeProjection($canonicalKey, $item))
            ) {
                unset($value[$key]);
                continue;
            }
            $value[$key] = self::withoutExecutionTracking($item);
        }
        return $value;
    }

    private static function looksLikeBridgeProjection(string $containerField, mixed $value): bool
    {
        if (!is_array($value)) {
            return $containerField === 'post_decision_tracking' && is_bool($value);
        }
        return self::containsBridgeReference($value);
    }

    /** @param array<mixed> $value */
    private static function hasDirectBridgeIntentReference(array $value): bool
    {
        foreach ($value as $key => $item) {
            $canonicalKey = self::canonicalPayloadKey($key);
            if (in_array($canonicalKey, self::BRIDGE_INTENT_REFERENCE_FIELDS, true)
                && is_scalar($item)
                && (int)$item > 0
            ) {
                return true;
            }
        }
        return false;
    }

    /** @param array<mixed> $value */
    private static function containsBridgeReference(array $value): bool
    {
        if (self::hasDirectBridgeIntentReference($value)) {
            return true;
        }
        foreach ($value as $key => $item) {
            if (self::canonicalPayloadKey($key) === 'post_decision_tracking' && is_bool($item)) {
                return true;
            }
            if (is_array($item) && self::containsBridgeReference($item)) {
                return true;
            }
        }
        return false;
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
