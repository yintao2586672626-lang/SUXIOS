<?php
declare(strict_types=1);

namespace app\service;

use Throwable;
use think\facade\Db;

/** Read-only, tenant-safe projection of persisted source-to-execution bridges. */
final class SourceBackedExecutionBridgeProjectionService
{
    private const VERIFIED_MARKER = '_source_bridge_verified';

    private static ?object $projectionToken = null;

    private const INTENT_ID_FIELDS = [
        'operation_execution_intent_id',
        'execution_intent_id',
        'latest_execution_intent_id',
    ];

    private const TRACKING_COLLECTION_FIELDS = [
        'execution_tracking',
        'tracking_records',
    ];

    private const TRACKING_OBJECT_FIELDS = [
        'post_decision_tracking',
    ];

    /** These identifiers have no tenant/source/hotel proof available in this bridge. */
    private const UNVERIFIABLE_REFERENCE_FIELDS = [
        'tracking_record_id',
        'post_decision_tracking_id',
        'opening_project_id',
        'investment_tracking_id',
    ];

    /**
     * Build the reusable two-query projection context for one response or a list.
     *
     * @param array<int,array<string,mixed>> $sources
     * @return array<string,mixed>
     */
    public function projectionContext(string $sourceModule, array $sources): array
    {
        $sourceModule = SourceBackedExecutionIntentIdentityService::canonicalSourceModule($sourceModule);
        [$sourceById, $hotelTenants] = $this->currentSourceScopes($sources);
        $validIntents = [];
        if ($sourceModule !== '' && $sourceById !== [] && $hotelTenants !== []) {
            foreach ($this->intentRows($sourceModule, array_keys($sourceById)) as $intent) {
                $sourceId = (int)($intent['source_record_id'] ?? 0);
                if (!isset($sourceById[$sourceId])
                    || !$this->matchesCurrentScope($sourceModule, $sourceById[$sourceId], $intent, $hotelTenants)
                ) {
                    continue;
                }
                $validIntents[$sourceId][(int)$intent['id']] = $intent;
            }
        }

        return [
            'source_module' => $sourceModule,
            'sources' => $sourceById,
            'hotel_tenants' => $hotelTenants,
            'valid_intents' => $validIntents,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $sources
     * @return array<int,int> source_record_id => latest current intent id
     */
    public function latestIntentIds(string $sourceModule, array $sources): array
    {
        $context = $this->projectionContext($sourceModule, $sources);
        $result = [];
        foreach ((array)($context['valid_intents'] ?? []) as $sourceId => $intents) {
            if (is_array($intents) && $intents !== []) {
                $result[(int)$sourceId] = (int)array_key_first($intents);
            }
        }
        return $result;
    }

    /**
     * Sanitize several payloads against one shared projection context.
     *
     * @param array<int,array{source:array<string,mixed>,payload:array<string,mixed>}> $items
     * @param array<string,mixed>|null $context
     * @return array<int,array<string,mixed>>
     */
    public function trackingForResponses(string $sourceModule, array $items, ?array $context = null): array
    {
        $sourceModule = SourceBackedExecutionIntentIdentityService::canonicalSourceModule($sourceModule);
        if ($context === null) {
            $context = $this->projectionContext(
                $sourceModule,
                array_values(array_filter(array_map(
                    static fn(array $item): mixed => $item['source'] ?? null,
                    $items
                ), 'is_array'))
            );
        }
        if (($context['source_module'] ?? null) !== $sourceModule) {
            $context = ['source_module' => $sourceModule, 'sources' => [], 'valid_intents' => []];
        }

        $projected = [];
        foreach ($items as $item) {
            $source = is_array($item['source'] ?? null) ? $item['source'] : [];
            $payload = is_array($item['payload'] ?? null) ? $item['payload'] : [];
            $sourceId = (int)($source['id'] ?? 0);
            $currentSource = is_array($context['sources'][$sourceId] ?? null)
                ? $context['sources'][$sourceId]
                : [];
            $scope = [
                'source_module' => $sourceModule,
                'source_record_id' => $sourceId,
                'hotel_id' => (int)($currentSource['hotel_id'] ?? 0),
                'tenant_id' => (int)($currentSource['tenant_id'] ?? 0),
            ];
            $validIntentIds = [];
            foreach ((array)($context['valid_intents'][$sourceId] ?? []) as $intentId => $intent) {
                if (is_array($intent)) {
                    $validIntentIds[(int)$intentId] = true;
                }
            }
            $projected[] = $this->sanitizeNode($payload, $scope, $validIntentIds);
        }

        return $projected;
    }

    /** @param array<string,mixed> $source @param array<string,mixed> $payload @return array<string,mixed> */
    public function trackingForResponse(string $sourceModule, array $source, array $payload): array
    {
        return $this->trackingForResponses($sourceModule, [[
            'source' => $source,
            'payload' => $payload,
        ]])[0] ?? [];
    }

    public static function hasProjectedTracking(array $payload): bool
    {
        return self::containsVerifiedProjection($payload);
    }

    /**
     * The only recursive tracking sanitizer. Non-tracking business fields pass through.
     *
     * @param array<string,int|string> $scope
     * @param array<int,bool> $validIntentIds
     * @return array<mixed>
     */
    private function sanitizeNode(array $node, array $scope, array $validIntentIds, bool $ancestorScopeConflict = false): array
    {
        $isList = array_is_list($node);
        $bridgeObject = $this->hasDirectBridgeIntentReference($node);
        $scopeConflict = $ancestorScopeConflict || !$this->declaredScopeMatches($node, $scope);
        if ($scopeConflict) {
            $validIntentIds = [];
        }

        $sanitized = [];
        foreach ($node as $key => $value) {
            $canonicalKey = $this->canonicalKey($key);
            if ($canonicalKey === self::VERIFIED_MARKER) {
                continue;
            }
            if (in_array($canonicalKey, self::UNVERIFIABLE_REFERENCE_FIELDS, true)) {
                if ($bridgeObject) {
                    continue;
                }
                $sanitized[$key] = is_array($value)
                    ? $this->sanitizeNode($value, $scope, $validIntentIds, $scopeConflict)
                    : $value;
                continue;
            }
            if (in_array($canonicalKey, self::INTENT_ID_FIELDS, true)) {
                $intentId = is_scalar($value) ? (int)$value : 0;
                if (isset($validIntentIds[$intentId])) {
                    $sanitized[$canonicalKey] = $intentId;
                }
                continue;
            }
            if (in_array($canonicalKey, self::TRACKING_COLLECTION_FIELDS, true)) {
                if (!$this->containsDeclaredBridgeIdentifier($value)) {
                    $sanitized[$key] = is_array($value)
                        ? $this->sanitizeNode($value, $scope, $validIntentIds, $scopeConflict)
                        : $value;
                    continue;
                }
                $collection = $this->sanitizeTrackingContainer($value, $scope, $validIntentIds);
                if ($collection !== null) {
                    $sanitized[$canonicalKey] = $collection;
                } elseif (!array_key_exists($canonicalKey, $sanitized)) {
                    $sanitized[$canonicalKey] = [];
                }
                continue;
            }
            if (in_array($canonicalKey, self::TRACKING_OBJECT_FIELDS, true)) {
                if (is_bool($value)) {
                    continue;
                }
                if (is_array($value) && !$this->containsDeclaredBridgeIdentifier($value)) {
                    $sanitized[$key] = $this->sanitizeNode($value, $scope, $validIntentIds, $scopeConflict);
                    continue;
                }
                $tracking = $this->sanitizeTrackingContainer($value, $scope, $validIntentIds);
                if ($tracking !== null) {
                    $sanitized[$canonicalKey] = $tracking;
                }
                continue;
            }
            $sanitized[$key] = is_array($value)
                ? $this->sanitizeNode($value, $scope, $validIntentIds, $scopeConflict)
                : $value;
        }

        if (!$isList && !$scopeConflict && $this->containsDirectVerifiedIntentReference($sanitized, $validIntentIds)) {
            $sanitized[self::VERIFIED_MARKER] = self::projectionToken();
        }

        return $isList ? array_values($sanitized) : $sanitized;
    }

    private function containsDeclaredBridgeIdentifier(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }
        if ($this->hasDirectBridgeIntentReference($value)) {
            return true;
        }
        foreach ($value as $key => $item) {
            $canonicalKey = $this->canonicalKey($key);
            if (($canonicalKey === 'post_decision_tracking' && is_bool($item))
                || (is_array($item) && $this->containsDeclaredBridgeIdentifier($item))
            ) {
                return true;
            }
        }
        return false;
    }

    private static function containsVerifiedProjection(array $value): bool
    {
        $marked = ($value[self::VERIFIED_MARKER] ?? null) === self::projectionToken();
        if ($marked) {
            foreach ($value as $key => $item) {
                $canonicalKey = SourceBackedExecutionIntentIdentityService::canonicalPayloadKey($key);
                if (in_array($canonicalKey, self::INTENT_ID_FIELDS, true)
                    && is_scalar($item) && (int)$item > 0
                ) {
                    return true;
                }
            }
        }
        foreach ($value as $item) {
            if (is_array($item) && self::containsVerifiedProjection($item)) {
                return true;
            }
        }
        return false;
    }

    private static function projectionToken(): object
    {
        if (self::$projectionToken === null) {
            self::$projectionToken = new class implements \JsonSerializable {
                public function jsonSerialize(): bool
                {
                    return true;
                }
            };
        }
        return self::$projectionToken;
    }

    /** @param array<mixed> $node */
    private function hasDirectBridgeIntentReference(array $node): bool
    {
        foreach ($node as $key => $value) {
            if (in_array($this->canonicalKey($key), self::INTENT_ID_FIELDS, true)
                && is_scalar($value)
                && (int)$value > 0
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,int|string> $scope
     * @param array<int,bool> $validIntentIds
     */
    private function sanitizeTrackingContainer(mixed $value, array $scope, array $validIntentIds): ?array
    {
        if (!is_array($value) || $value === []) {
            return null;
        }
        if (!array_is_list($value)) {
            $item = $this->sanitizeNode($value, $scope, $validIntentIds);
            return $this->containsVerifiedIntentReference($item, $validIntentIds) ? $item : null;
        }

        $items = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }
            $item = $this->sanitizeNode($item, $scope, $validIntentIds);
            if ($this->containsVerifiedIntentReference($item, $validIntentIds)) {
                $items[] = $item;
            }
        }
        return $items === [] ? null : $items;
    }

    /** @param array<int,bool> $validIntentIds */
    private function containsVerifiedIntentReference(array $node, array $validIntentIds): bool
    {
        foreach ($node as $key => $value) {
            if (in_array($this->canonicalKey($key), self::INTENT_ID_FIELDS, true)
                && is_scalar($value) && isset($validIntentIds[(int)$value])
            ) {
                return true;
            }
            if (is_array($value) && $this->containsVerifiedIntentReference($value, $validIntentIds)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<int,bool> $validIntentIds */
    private function containsDirectVerifiedIntentReference(array $node, array $validIntentIds): bool
    {
        foreach ($node as $key => $value) {
            if (in_array($this->canonicalKey($key), self::INTENT_ID_FIELDS, true)
                && is_scalar($value)
                && isset($validIntentIds[(int)$value])
            ) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,int|string> $scope */
    private function declaredScopeMatches(array $node, array $scope): bool
    {
        foreach ($node as $key => $value) {
            $canonicalKey = $this->canonicalKey($key);
            if ($canonicalKey === 'source_module') {
                if (!is_scalar($value)) {
                    return false;
                }
                $module = strtolower(trim((string)$value));
                if ($module !== '' && $module !== $scope['source_module']) {
                    return false;
                }
                continue;
            }
            if (in_array($canonicalKey, ['source_record_id', 'hotel_id', 'tenant_id'], true)
                && (!is_scalar($value) || ((int)$value > 0 && (int)$value !== (int)$scope[$canonicalKey]))
            ) {
                return false;
            }
        }
        return true;
    }

    private function canonicalKey(mixed $key): string
    {
        return SourceBackedExecutionIntentIdentityService::canonicalPayloadKey($key);
    }

    /**
     * @param array<int,array<string,mixed>> $sources
     * @return array{0:array<int,array<string,mixed>>,1:array<int,int>}
     */
    private function currentSourceScopes(array $sources): array
    {
        $sourceById = [];
        $hotelIds = [];
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }
            $sourceId = (int)($source['id'] ?? 0);
            $tenantId = (int)($source['tenant_id'] ?? 0);
            $hotelId = (int)($source['hotel_id'] ?? 0);
            if ($sourceId <= 0 || $tenantId <= 0 || $hotelId <= 0) {
                continue;
            }
            $sourceById[$sourceId] = $source;
            $hotelIds[$hotelId] = true;
        }
        if ($sourceById === []) {
            return [[], []];
        }

        try {
            $rows = Db::name('hotels')
                ->whereIn('id', array_keys($hotelIds))
                ->field('id,tenant_id')
                ->select()
                ->toArray();
        } catch (Throwable) {
            return [[], []];
        }
        $hotelTenants = [];
        foreach ($rows as $row) {
            $hotelId = (int)($row['id'] ?? 0);
            $tenantId = (int)($row['tenant_id'] ?? 0);
            if ($hotelId > 0 && $tenantId > 0) {
                $hotelTenants[$hotelId] = $tenantId;
            }
        }
        return [$sourceById, $hotelTenants];
    }

    /** @param array<int,int> $sourceIds @return array<int,array<string,mixed>> */
    private function intentRows(string $sourceModule, array $sourceIds): array
    {
        $sourceIds = array_values(array_unique(array_filter(
            array_map('intval', $sourceIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($sourceModule === '' || $sourceIds === []) {
            return [];
        }
        try {
            return Db::name('operation_execution_intents')
                ->whereRaw('LOWER(TRIM(source_module)) = ?', [$sourceModule])
                ->whereIn('source_record_id', $sourceIds)
                ->whereNull('deleted_at')
                ->order('id', 'desc')
                ->field('id,tenant_id,source_module,source_record_id,hotel_id,status')
                ->select()
                ->toArray();
        } catch (Throwable) {
            return [];
        }
    }

    /** @param array<int,int> $hotelTenants */
    private function matchesCurrentScope(string $sourceModule, array $source, array $intent, array $hotelTenants): bool
    {
        $sourceId = (int)($source['id'] ?? 0);
        $sourceTenantId = (int)($source['tenant_id'] ?? 0);
        $sourceHotelId = (int)($source['hotel_id'] ?? 0);
        return $sourceId > 0
            && $sourceTenantId > 0
            && $sourceHotelId > 0
            && (int)($hotelTenants[$sourceHotelId] ?? 0) === $sourceTenantId
            && (int)($intent['id'] ?? 0) > 0
            && (int)($intent['source_record_id'] ?? 0) === $sourceId
            && strtolower(trim((string)($intent['source_module'] ?? ''))) === $sourceModule
            && (int)($intent['hotel_id'] ?? 0) === $sourceHotelId
            && (int)($intent['tenant_id'] ?? 0) === $sourceTenantId
            && in_array(strtolower(trim((string)($intent['status'] ?? ''))), [
                'draft',
                'pending_approval',
                'approved',
            ], true);
    }
}
