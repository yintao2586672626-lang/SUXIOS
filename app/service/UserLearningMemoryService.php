<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;
use Throwable;

/**
 * Append-only, user-owned preference memory.
 *
 * Only explicit_confirmed projections are consumable by downstream
 * personalization. Inferred and insufficient signals stay visible as
 * candidates and never become durable authority by themselves.
 */
final class UserLearningMemoryService
{
    public const CONTRACT_VERSION = 'user_learning_memory.v1';
    public const EVENT_TABLE = 'user_learning_memory_events';
    public const PREFERENCE_TABLE = 'user_learning_memory_preferences';

    public const STATUS_EXPLICIT_CONFIRMED = 'explicit_confirmed';
    public const STATUS_INFERRED = 'inferred';
    public const STATUS_INSUFFICIENT = 'insufficient';

    private const SCOPES = ['global', 'hotel', 'session'];
    private const LEARNING_STATUSES = [
        self::STATUS_EXPLICIT_CONFIRMED,
        self::STATUS_INFERRED,
        self::STATUS_INSUFFICIENT,
    ];
    private const SOURCE_TYPES = [
        'explicit_user',
        'user_correction',
        'behavioral_signal',
        'system_observation',
    ];
    private const CONTEXT_FIELDS = [
        'content_classification',
        'source_ref',
        'surface',
        'reason_code',
        'signal_count',
        'sample_count',
        'correlation_id',
    ];

    /**
     * Store an observed preference signal and append one projection version.
     *
     * @param array<string,mixed> $sourceContext
     * @return array<string,mixed>
     */
    public function recordFeedback(
        int $tenantId,
        int $userId,
        string $scope,
        string $preferenceKey,
        mixed $value,
        string $learningStatus,
        string $idempotencyKey,
        ?int $hotelId = null,
        ?string $sessionRef = null,
        string $sourceType = 'behavioral_signal',
        array $sourceContext = []
    ): array {
        $schemaGap = $this->schemaGap('record_feedback');
        if ($schemaGap !== null) {
            return $schemaGap;
        }

        $scopeData = $this->normalizeScope(
            $tenantId,
            $userId,
            $scope,
            $hotelId,
            $sessionRef
        );
        $preferenceKey = $this->normalizePreferenceKey($preferenceKey);
        $learningStatus = strtolower(trim($learningStatus));
        if (!in_array($learningStatus, self::LEARNING_STATUSES, true)) {
            throw new InvalidArgumentException('user_learning_status_invalid');
        }
        $sourceType = strtolower(trim($sourceType));
        if (!in_array($sourceType, self::SOURCE_TYPES, true)) {
            throw new InvalidArgumentException('user_learning_source_type_invalid');
        }
        if ($learningStatus === self::STATUS_EXPLICIT_CONFIRMED
            && !in_array($sourceType, ['explicit_user', 'user_correction'], true)
        ) {
            throw new InvalidArgumentException(
                'explicit_confirmation_requires_explicit_user_source'
            );
        }

        $valueJson = $this->normalizeValue($value);
        $context = $this->normalizeContext($sourceContext, $learningStatus);
        $contextJson = $this->encodeCanonical($context);
        $this->assertPreferenceAllowed(
            $preferenceKey,
            $valueJson,
            (string)$context['content_classification']
        );

        $preferenceIdentity = $this->preferenceIdentity(
            $scopeData,
            $preferenceKey
        );
        $idempotency = $this->idempotency(
            $tenantId,
            $userId,
            $idempotencyKey
        );
        $eventType = $learningStatus === self::STATUS_EXPLICIT_CONFIRMED
            ? 'confirmed'
            : 'observed';
        $requestDigest = $this->requestDigest([
            'operation' => 'record_feedback',
            'preference_identity' => $preferenceIdentity,
            'event_type' => $eventType,
            'learning_status' => $learningStatus,
            'value_hash' => hash('sha256', $valueJson),
            'source_type' => $sourceType,
            'source_context' => $context,
        ]);

        return $this->executeIdempotent(
            $idempotency['event_identity'],
            $requestDigest,
            'record_feedback',
            function () use (
                $scopeData,
                $preferenceKey,
                $preferenceIdentity,
                $eventType,
                $learningStatus,
                $valueJson,
                $sourceType,
                $contextJson,
                $idempotency,
                $requestDigest
            ): int {
                $now = $this->now();
                $eventId = $this->insertEvent([
                    ...$scopeData,
                    'preference_key' => $preferenceKey,
                    'preference_identity' => $preferenceIdentity,
                    'event_type' => $eventType,
                    'learning_status' => $learningStatus,
                    'value_json' => $valueJson,
                    'value_hash' => hash('sha256', $valueJson),
                    'source_type' => $sourceType,
                    'source_context_json' => $contextJson,
                    'idempotency_hash' => $idempotency['idempotency_hash'],
                    'event_identity' => $idempotency['event_identity'],
                    'request_digest' => $requestDigest,
                    'created_at' => $now,
                ]);
                if ($learningStatus === self::STATUS_EXPLICIT_CONFIRMED
                    || $this->latestActiveConfirmedProjection($preferenceIdentity) === null
                ) {
                    $this->insertProjection([
                        ...$scopeData,
                        'preference_key' => $preferenceKey,
                        'preference_identity' => $preferenceIdentity,
                        'version' => $this->nextVersion($preferenceIdentity),
                        'event_id' => $eventId,
                        'learning_status' => $learningStatus,
                        'lifecycle_status' => 'active',
                        'value_json' => $valueJson,
                        'value_hash' => hash('sha256', $valueJson),
                        'created_at' => $now,
                    ]);
                }
                return $eventId;
            }
        );
    }

    /**
     * Accumulate one bounded behavioral signal and expose a confirmation
     * candidate only after the same scoped value reaches a stable threshold.
     * Existing explicit preferences always remain authoritative.
     *
     * @param array<string,mixed> $sourceContext
     * @return array<string,mixed>
     */
    public function recordRepeatedSignal(
        int $tenantId,
        int $userId,
        string $scope,
        string $preferenceKey,
        mixed $value,
        string $idempotencyKey,
        int $minimumSignals = 3,
        ?int $hotelId = null,
        ?string $sessionRef = null,
        array $sourceContext = []
    ): array {
        $schemaGap = $this->schemaGap('record_repeated_signal');
        if ($schemaGap !== null) {
            return $schemaGap;
        }
        if ($minimumSignals < 2 || $minimumSignals > 20) {
            throw new InvalidArgumentException('user_learning_signal_threshold_invalid');
        }

        $scopeData = $this->normalizeScope(
            $tenantId,
            $userId,
            $scope,
            $hotelId,
            $sessionRef
        );
        $preferenceKey = $this->normalizePreferenceKey($preferenceKey);
        $valueJson = $this->normalizeValue($value);
        $valueHash = hash('sha256', $valueJson);
        $context = $this->normalizeContext(
            $sourceContext,
            self::STATUS_INSUFFICIENT
        );
        $this->assertPreferenceAllowed(
            $preferenceKey,
            $valueJson,
            (string)$context['content_classification']
        );
        $identity = $this->preferenceIdentity($scopeData, $preferenceKey);
        $idempotency = $this->idempotency($tenantId, $userId, $idempotencyKey);
        $existing = $this->eventByIdentity($idempotency['event_identity']);
        if ($existing !== null) {
            $existingContext = $existing['source_context_json'] === null
                ? []
                : $this->decodeJson((string)$existing['source_context_json']);
            $context['signal_count'] = max(1, (int)($existingContext['signal_count'] ?? 1));
            $result = $this->recordFeedback(
                tenantId: $tenantId,
                userId: $userId,
                scope: $scope,
                preferenceKey: $preferenceKey,
                value: $value,
                learningStatus: (string)$existing['learning_status'],
                idempotencyKey: $idempotencyKey,
                hotelId: $hotelId,
                sessionRef: $sessionRef,
                sourceType: 'behavioral_signal',
                sourceContext: $context
            );
            return $this->withSignalCandidateStatus($result, $minimumSignals);
        }

        $confirmed = $this->latestActiveConfirmedProjection($identity);
        if ($confirmed !== null) {
            $this->assertProjectionReadback($confirmed);
            return [
                'contract_version' => self::CONTRACT_VERSION,
                'status' => 'already_confirmed',
                'operation' => 'record_repeated_signal',
                'migration_required' => false,
                'signal_count' => 0,
                'minimum_signals' => $minimumSignals,
                'candidate_ready' => false,
                'requires_confirmation' => false,
                'preference' => $this->publicProjection($confirmed),
                'readback' => [
                    'status' => 'exact_readback_verified',
                    'exact_readback_verified' => true,
                    'projection_id' => (int)$confirmed['id'],
                ],
            ];
        }

        $latest = $this->latestProjection($identity);
        if (is_array($latest)
            && (string)$latest['learning_status'] === self::STATUS_INFERRED
            && (string)$latest['lifecycle_status'] === 'active'
            && hash_equals((string)$latest['value_hash'], $valueHash)
        ) {
            $this->assertProjectionReadback($latest);
            return [
                'contract_version' => self::CONTRACT_VERSION,
                'status' => 'candidate_ready',
                'operation' => 'record_repeated_signal',
                'migration_required' => false,
                'signal_count' => $this->signalCountFromProjection($latest),
                'minimum_signals' => $minimumSignals,
                'candidate_ready' => true,
                'requires_confirmation' => true,
                'preference' => $this->publicProjection($latest),
                'readback' => [
                    'status' => 'exact_readback_verified',
                    'exact_readback_verified' => true,
                    'projection_id' => (int)$latest['id'],
                ],
            ];
        }

        $boundaryEventId = (int)(Db::name(self::EVENT_TABLE)
            ->where('preference_identity', $identity)
            ->whereIn('event_type', ['confirmed', 'revoked', 'reset'])
            ->order('id', 'desc')
            ->value('id') ?? 0);
        $signalQuery = Db::name(self::EVENT_TABLE)
            ->where('preference_identity', $identity)
            ->where('event_type', 'observed')
            ->where('source_type', 'behavioral_signal')
            ->where('value_hash', $valueHash);
        if ($boundaryEventId > 0) {
            $signalQuery->where('id', '>', $boundaryEventId);
        }
        $signalCount = (int)$signalQuery->count() + 1;
        $learningStatus = $signalCount >= $minimumSignals
            ? self::STATUS_INFERRED
            : self::STATUS_INSUFFICIENT;
        $context['signal_count'] = $signalCount;

        return $this->withSignalCandidateStatus($this->recordFeedback(
            tenantId: $tenantId,
            userId: $userId,
            scope: $scope,
            preferenceKey: $preferenceKey,
            value: $value,
            learningStatus: $learningStatus,
            idempotencyKey: $idempotencyKey,
            hotelId: $hotelId,
            sessionRef: $sessionRef,
            sourceType: 'behavioral_signal',
            sourceContext: $context
        ), $minimumSignals);
    }

    /**
     * Explicitly confirm a preference. This is the only convenience method
     * that produces a projection consumable by downstream personalization.
     *
     * @param array<string,mixed> $sourceContext
     * @return array<string,mixed>
     */
    public function confirmPreference(
        int $tenantId,
        int $userId,
        string $scope,
        string $preferenceKey,
        mixed $value,
        string $idempotencyKey,
        ?int $hotelId = null,
        ?string $sessionRef = null,
        array $sourceContext = []
    ): array {
        return $this->recordFeedback(
            tenantId: $tenantId,
            userId: $userId,
            scope: $scope,
            preferenceKey: $preferenceKey,
            value: $value,
            learningStatus: self::STATUS_EXPLICIT_CONFIRMED,
            idempotencyKey: $idempotencyKey,
            hotelId: $hotelId,
            sessionRef: $sessionRef,
            sourceType: 'explicit_user',
            sourceContext: $sourceContext
        );
    }

    /** @return array<string,mixed> */
    public function revokePreference(
        int $tenantId,
        int $userId,
        string $scope,
        string $preferenceKey,
        string $idempotencyKey,
        ?int $hotelId = null,
        ?string $sessionRef = null
    ): array {
        $schemaGap = $this->schemaGap('revoke_preference');
        if ($schemaGap !== null) {
            return $schemaGap;
        }

        $scopeData = $this->normalizeScope(
            $tenantId,
            $userId,
            $scope,
            $hotelId,
            $sessionRef
        );
        $preferenceKey = $this->normalizePreferenceKey($preferenceKey);
        $preferenceIdentity = $this->preferenceIdentity(
            $scopeData,
            $preferenceKey
        );
        $idempotency = $this->idempotency(
            $tenantId,
            $userId,
            $idempotencyKey
        );
        $requestDigest = $this->requestDigest([
            'operation' => 'revoke_preference',
            'preference_identity' => $preferenceIdentity,
        ]);

        return $this->executeIdempotent(
            $idempotency['event_identity'],
            $requestDigest,
            'revoke_preference',
            function () use (
                $scopeData,
                $preferenceKey,
                $preferenceIdentity,
                $idempotency,
                $requestDigest
            ): int {
                $current = $this->latestProjection($preferenceIdentity);
                if ($current === null
                    || (string)$current['lifecycle_status'] !== 'active'
                ) {
                    throw new RuntimeException('user_learning_preference_not_active');
                }
                $now = $this->now();
                $eventId = $this->insertEvent([
                    ...$scopeData,
                    'preference_key' => $preferenceKey,
                    'preference_identity' => $preferenceIdentity,
                    'event_type' => 'revoked',
                    'learning_status' => (string)$current['learning_status'],
                    'value_json' => (string)$current['value_json'],
                    'value_hash' => (string)$current['value_hash'],
                    'source_type' => 'explicit_user',
                    'source_context_json' => $this->encodeCanonical([
                        'content_classification' => 'user_preference',
                        'reason_code' => 'user_revoked',
                    ]),
                    'idempotency_hash' => $idempotency['idempotency_hash'],
                    'event_identity' => $idempotency['event_identity'],
                    'request_digest' => $requestDigest,
                    'created_at' => $now,
                ]);
                $this->insertProjection([
                    ...$scopeData,
                    'preference_key' => $preferenceKey,
                    'preference_identity' => $preferenceIdentity,
                    'version' => $this->nextVersion($preferenceIdentity),
                    'event_id' => $eventId,
                    'learning_status' => (string)$current['learning_status'],
                    'lifecycle_status' => 'revoked',
                    'value_json' => (string)$current['value_json'],
                    'value_hash' => (string)$current['value_hash'],
                    'created_at' => $now,
                ]);
                return $eventId;
            }
        );
    }

    /**
     * Reset only one exact global/hotel/session scope. Every active preference
     * receives a new reset projection; other scopes remain untouched.
     *
     * @return array<string,mixed>
     */
    public function resetScope(
        int $tenantId,
        int $userId,
        string $scope,
        string $idempotencyKey,
        ?int $hotelId = null,
        ?string $sessionRef = null
    ): array {
        $schemaGap = $this->schemaGap('reset_scope');
        if ($schemaGap !== null) {
            return $schemaGap;
        }

        $scopeData = $this->normalizeScope(
            $tenantId,
            $userId,
            $scope,
            $hotelId,
            $sessionRef
        );
        $idempotency = $this->idempotency(
            $tenantId,
            $userId,
            $idempotencyKey
        );
        $requestDigest = $this->requestDigest([
            'operation' => 'reset_scope',
            'scope' => $this->scopeDigestInput($scopeData),
        ]);

        return $this->executeIdempotent(
            $idempotency['event_identity'],
            $requestDigest,
            'reset_scope',
            function () use (
                $scopeData,
                $idempotency,
                $requestDigest
            ): int {
                $active = array_values(array_filter(
                    $this->latestScopeProjections($scopeData),
                    static fn(array $row): bool =>
                        (string)$row['lifecycle_status'] === 'active'
                ));
                $now = $this->now();
                $eventId = $this->insertEvent([
                    ...$scopeData,
                    'preference_key' => '*',
                    'preference_identity' => null,
                    'event_type' => 'reset',
                    'learning_status' => null,
                    'value_json' => null,
                    'value_hash' => null,
                    'source_type' => 'explicit_user',
                    'source_context_json' => $this->encodeCanonical([
                        'content_classification' => 'user_preference',
                        'reason_code' => 'user_scope_reset',
                    ]),
                    'idempotency_hash' => $idempotency['idempotency_hash'],
                    'event_identity' => $idempotency['event_identity'],
                    'request_digest' => $requestDigest,
                    'created_at' => $now,
                ]);
                foreach ($active as $current) {
                    $identity = (string)$current['preference_identity'];
                    $this->insertProjection([
                        ...$scopeData,
                        'preference_key' => (string)$current['preference_key'],
                        'preference_identity' => $identity,
                        'version' => $this->nextVersion($identity),
                        'event_id' => $eventId,
                        'learning_status' => (string)$current['learning_status'],
                        'lifecycle_status' => 'reset',
                        'value_json' => (string)$current['value_json'],
                        'value_hash' => (string)$current['value_hash'],
                        'created_at' => $now,
                    ]);
                }
                return $eventId;
            }
        );
    }

    /**
     * List the latest projection for one exact scope.
     *
     * @return array<string,mixed>
     */
    public function listPreferences(
        int $tenantId,
        int $userId,
        string $scope,
        ?int $hotelId = null,
        ?string $sessionRef = null,
        bool $includeInactive = false,
        bool $includeCandidates = true
    ): array {
        $schemaGap = $this->schemaGap('list_preferences');
        if ($schemaGap !== null) {
            return array_merge($schemaGap, ['count' => 0, 'items' => []]);
        }
        $scopeData = $this->normalizeScope(
            $tenantId,
            $userId,
            $scope,
            $hotelId,
            $sessionRef
        );
        $items = [];
        foreach ($this->latestScopeProjections($scopeData) as $row) {
            if (!$includeInactive
                && (string)$row['lifecycle_status'] !== 'active'
            ) {
                continue;
            }
            if (!$includeCandidates
                && (string)$row['learning_status']
                    !== self::STATUS_EXPLICIT_CONFIRMED
            ) {
                continue;
            }
            $this->assertProjectionReadback($row);
            $items[] = $this->publicProjection($row);
        }

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'status' => 'ready',
            'migration_required' => false,
            'scope' => $this->publicScope($scopeData),
            'count' => count($items),
            'items' => $items,
        ];
    }

    /**
     * Read one exact preference identity and, optionally, one exact version.
     *
     * @return array<string,mixed>
     */
    public function readExact(
        int $tenantId,
        int $userId,
        string $scope,
        string $preferenceKey,
        ?int $version = null,
        ?int $hotelId = null,
        ?string $sessionRef = null
    ): array {
        $schemaGap = $this->schemaGap('read_exact');
        if ($schemaGap !== null) {
            return $schemaGap;
        }
        $scopeData = $this->normalizeScope(
            $tenantId,
            $userId,
            $scope,
            $hotelId,
            $sessionRef
        );
        $preferenceKey = $this->normalizePreferenceKey($preferenceKey);
        $identity = $this->preferenceIdentity($scopeData, $preferenceKey);
        $query = Db::name(self::PREFERENCE_TABLE)
            ->where('preference_identity', $identity);
        if ($version !== null) {
            if ($version <= 0) {
                throw new InvalidArgumentException(
                    'user_learning_preference_version_invalid'
                );
            }
            $query->where('version', $version);
        }
        $row = $query->order('version', 'desc')->find();
        if (!is_array($row)) {
            return [
                'contract_version' => self::CONTRACT_VERSION,
                'status' => 'not_found',
                'migration_required' => false,
                'scope' => $this->publicScope($scopeData),
                'preference_key' => $preferenceKey,
                'preference' => null,
                'readback' => [
                    'status' => 'not_found',
                    'exact_readback_verified' => false,
                ],
            ];
        }
        $this->assertProjectionReadback($row);
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'status' => 'exact_readback_verified',
            'migration_required' => false,
            'scope' => $this->publicScope($scopeData),
            'preference_key' => $preferenceKey,
            'preference' => $this->publicProjection($row),
            'readback' => [
                'status' => 'exact_readback_verified',
                'exact_readback_verified' => true,
                'projection_id' => (int)$row['id'],
                'event_id' => (int)$row['event_id'],
                'version' => (int)$row['version'],
                'value_hash' => (string)$row['value_hash'],
            ],
        ];
    }

    /**
     * @param callable():int $writer
     * @return array<string,mixed>
     */
    private function executeIdempotent(
        string $eventIdentity,
        string $requestDigest,
        string $operation,
        callable $writer
    ): array {
        $existing = $this->eventByIdentity($eventIdentity);
        if ($existing !== null) {
            $this->assertIdempotencyDigest($existing, $requestDigest);
            return $this->eventResult($existing, true, $operation);
        }

        $eventId = 0;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $eventId = Db::transaction(function () use (
                    $eventIdentity,
                    $requestDigest,
                    $writer
                ): int {
                    $existing = $this->eventByIdentity($eventIdentity);
                    if ($existing !== null) {
                        $this->assertIdempotencyDigest($existing, $requestDigest);
                        return (int)$existing['id'];
                    }
                    return $writer();
                });
                break;
            } catch (Throwable $exception) {
                $existing = $this->eventByIdentity($eventIdentity);
                if ($existing !== null) {
                    $this->assertIdempotencyDigest($existing, $requestDigest);
                    return $this->eventResult($existing, true, $operation);
                }
                if (!$this->isDuplicateKeyConflict($exception) || $attempt === 3) {
                    throw $exception;
                }
            }
        }

        $row = Db::name(self::EVENT_TABLE)
            ->where('id', $eventId)
            ->where('event_identity', $eventIdentity)
            ->find();
        if (!is_array($row)) {
            throw new RuntimeException('user_learning_event_exact_readback_failed');
        }
        $this->assertIdempotencyDigest($row, $requestDigest);
        return $this->eventResult($row, false, $operation);
    }

    /** @param array<string,mixed> $event @return array<string,mixed> */
    private function eventResult(
        array $event,
        bool $idempotentReplay,
        string $operation
    ): array {
        $this->assertEventReadback($event);
        $projections = Db::name(self::PREFERENCE_TABLE)
            ->where('event_id', (int)$event['id'])
            ->order('id', 'asc')
            ->select()
            ->toArray();
        foreach ($projections as $projection) {
            $this->assertProjectionReadback($projection);
            if ((int)$projection['tenant_id'] !== (int)$event['tenant_id']
                || (int)$projection['user_id'] !== (int)$event['user_id']
                || (int)$projection['event_id'] !== (int)$event['id']
            ) {
                throw new RuntimeException(
                    'user_learning_projection_scope_readback_failed'
                );
            }
        }

        $eventType = (string)$event['event_type'];
        $status = $idempotentReplay
            ? 'idempotent_replay'
            : match ($eventType) {
                'revoked' => 'revoked',
                'reset' => 'reset',
                default => 'stored',
            };
        $publicProjections = array_map(
            fn(array $row): array => $this->publicProjection($row),
            $projections
        );
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'status' => $status,
            'operation' => $operation,
            'migration_required' => false,
            'idempotent_replay' => $idempotentReplay,
            'event' => $this->publicEvent($event),
            'preference' => count($publicProjections) === 1
                ? $publicProjections[0]
                : null,
            'preferences' => $publicProjections,
            'readback' => [
                'status' => 'exact_readback_verified',
                'exact_readback_verified' => true,
                'event_id' => (int)$event['id'],
                'event_identity' => (string)$event['event_identity'],
                'request_digest' => (string)$event['request_digest'],
                'projection_count' => count($publicProjections),
            ],
        ];
    }

    /** @param array<string,mixed> $row */
    private function assertEventReadback(array $row): void
    {
        if ((int)($row['id'] ?? 0) <= 0
            || (int)($row['tenant_id'] ?? 0) <= 0
            || (int)($row['user_id'] ?? 0) <= 0
            || preg_match('/^[a-f0-9]{64}$/D', (string)($row['event_identity'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', (string)($row['request_digest'] ?? '')) !== 1
        ) {
            throw new RuntimeException('user_learning_event_exact_readback_failed');
        }
        if ($row['value_json'] !== null) {
            $json = $this->encodeCanonical($this->decodeJson((string)$row['value_json']));
            if (!hash_equals((string)$row['value_hash'], hash('sha256', $json))) {
                throw new RuntimeException('user_learning_event_value_readback_failed');
            }
        }
    }

    /** @param array<string,mixed> $row */
    private function assertProjectionReadback(array $row): void
    {
        $scopeData = [
            'tenant_id' => (int)($row['tenant_id'] ?? 0),
            'user_id' => (int)($row['user_id'] ?? 0),
            'hotel_id' => $row['hotel_id'] === null
                ? null
                : (int)$row['hotel_id'],
            'memory_scope' => (string)($row['memory_scope'] ?? ''),
            'session_ref_hash' => $row['session_ref_hash'] === null
                ? null
                : (string)$row['session_ref_hash'],
        ];
        $expectedIdentity = $this->preferenceIdentity(
            $scopeData,
            (string)($row['preference_key'] ?? '')
        );
        $valueJson = $this->encodeCanonical(
            $this->decodeJson((string)($row['value_json'] ?? ''))
        );
        if ((int)($row['id'] ?? 0) <= 0
            || (int)($row['version'] ?? 0) <= 0
            || (int)($row['event_id'] ?? 0) <= 0
            || !hash_equals(
                $expectedIdentity,
                (string)($row['preference_identity'] ?? '')
            )
            || !hash_equals(
                hash('sha256', $valueJson),
                (string)($row['value_hash'] ?? '')
            )
        ) {
            throw new RuntimeException(
                'user_learning_projection_exact_readback_failed'
            );
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function publicEvent(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'tenant_id' => (int)$row['tenant_id'],
            'user_id' => (int)$row['user_id'],
            'hotel_id' => $row['hotel_id'] === null
                ? null
                : (int)$row['hotel_id'],
            'scope' => (string)$row['memory_scope'],
            'session_scoped' => $row['session_ref_hash'] !== null,
            'preference_key' => (string)$row['preference_key'],
            'event_type' => (string)$row['event_type'],
            'learning_status' => $row['learning_status'] === null
                ? null
                : (string)$row['learning_status'],
            'value' => $row['value_json'] === null
                ? null
                : $this->decodeJson((string)$row['value_json']),
            'source_type' => (string)$row['source_type'],
            'source_context' => $row['source_context_json'] === null
                ? []
                : $this->decodeJson((string)$row['source_context_json']),
            'created_at' => (string)$row['created_at'],
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function publicProjection(array $row): array
    {
        $learningStatus = (string)$row['learning_status'];
        $lifecycleStatus = (string)$row['lifecycle_status'];
        $event = Db::name(self::EVENT_TABLE)
            ->where('id', (int)$row['event_id'])
            ->find();
        $sourceContext = is_array($event) && $event['source_context_json'] !== null
            ? $this->decodeJson((string)$event['source_context_json'])
            : [];
        return [
            'id' => (int)$row['id'],
            'tenant_id' => (int)$row['tenant_id'],
            'user_id' => (int)$row['user_id'],
            'hotel_id' => $row['hotel_id'] === null
                ? null
                : (int)$row['hotel_id'],
            'scope' => (string)$row['memory_scope'],
            'session_scoped' => $row['session_ref_hash'] !== null,
            'preference_key' => (string)$row['preference_key'],
            'version' => (int)$row['version'],
            'event_id' => (int)$row['event_id'],
            'learning_status' => $learningStatus,
            'lifecycle_status' => $lifecycleStatus,
            'consumable' => $learningStatus === self::STATUS_EXPLICIT_CONFIRMED
                && $lifecycleStatus === 'active',
            'value' => $this->decodeJson((string)$row['value_json']),
            'value_hash' => (string)$row['value_hash'],
            'source_type' => is_array($event) ? (string)$event['source_type'] : '',
            'source_context' => $sourceContext,
            'candidate' => in_array(
                $learningStatus,
                [self::STATUS_INFERRED, self::STATUS_INSUFFICIENT],
                true
            ) && $lifecycleStatus === 'active',
            'created_at' => (string)$row['created_at'],
        ];
    }

    /** @return array<string,mixed> */
    private function withSignalCandidateStatus(array $result, int $minimumSignals): array
    {
        $preference = is_array($result['preference'] ?? null)
            ? $result['preference']
            : [];
        $signalCount = max(0, (int)(
            $preference['source_context']['signal_count']
                ?? $result['event']['source_context']['signal_count']
                ?? 0
        ));
        $candidateReady = ($preference['learning_status'] ?? '') === self::STATUS_INFERRED
            && ($preference['lifecycle_status'] ?? '') === 'active';
        $result['operation'] = 'record_repeated_signal';
        $result['signal_count'] = $signalCount;
        $result['minimum_signals'] = $minimumSignals;
        $result['candidate_ready'] = $candidateReady;
        $result['requires_confirmation'] = $candidateReady;
        return $result;
    }

    /** @return array<string,mixed>|null */
    private function latestActiveConfirmedProjection(string $preferenceIdentity): ?array
    {
        $boundaryVersion = (int)(Db::name(self::PREFERENCE_TABLE)
            ->where('preference_identity', $preferenceIdentity)
            ->whereIn('lifecycle_status', ['revoked', 'reset'])
            ->order('version', 'desc')
            ->value('version') ?? 0);
        $query = Db::name(self::PREFERENCE_TABLE)
            ->where('preference_identity', $preferenceIdentity)
            ->where('learning_status', self::STATUS_EXPLICIT_CONFIRMED)
            ->where('lifecycle_status', 'active');
        if ($boundaryVersion > 0) {
            $query->where('version', '>', $boundaryVersion);
        }
        $row = $query->order('version', 'desc')->find();
        return is_array($row) ? $row : null;
    }

    private function signalCountFromProjection(array $projection): int
    {
        $event = Db::name(self::EVENT_TABLE)
            ->where('id', (int)$projection['event_id'])
            ->find();
        if (!is_array($event) || $event['source_context_json'] === null) {
            return 0;
        }
        $context = $this->decodeJson((string)$event['source_context_json']);
        return max(0, (int)($context['signal_count'] ?? 0));
    }

    /** @param array<string,mixed> $scopeData @return list<array<string,mixed>> */
    private function latestScopeProjections(array $scopeData): array
    {
        $query = Db::name(self::PREFERENCE_TABLE)
            ->where('tenant_id', (int)$scopeData['tenant_id'])
            ->where('user_id', (int)$scopeData['user_id'])
            ->where('memory_scope', (string)$scopeData['memory_scope']);
        $this->applyScopeQuery($query, $scopeData);
        $rows = $query
            ->order('preference_identity', 'asc')
            ->order('version', 'desc')
            ->select()
            ->toArray();
        $latest = [];
        foreach ($rows as $row) {
            $identity = (string)$row['preference_identity'];
            if (!isset($latest[$identity])) {
                $latest[$identity] = $row;
            }
        }
        return array_values($latest);
    }

    /** @param array<string,mixed> $scopeData */
    private function applyScopeQuery(mixed $query, array $scopeData): void
    {
        if ($scopeData['hotel_id'] === null) {
            $query->whereNull('hotel_id');
        } else {
            $query->where('hotel_id', (int)$scopeData['hotel_id']);
        }
        if ($scopeData['session_ref_hash'] === null) {
            $query->whereNull('session_ref_hash');
        } else {
            $query->where(
                'session_ref_hash',
                (string)$scopeData['session_ref_hash']
            );
        }
    }

    /** @return array<string,mixed>|null */
    private function latestProjection(string $preferenceIdentity): ?array
    {
        $row = Db::name(self::PREFERENCE_TABLE)
            ->where('preference_identity', $preferenceIdentity)
            ->order('version', 'desc')
            ->find();
        return is_array($row) ? $row : null;
    }

    private function nextVersion(string $preferenceIdentity): int
    {
        return (int)Db::name(self::PREFERENCE_TABLE)
            ->where('preference_identity', $preferenceIdentity)
            ->max('version') + 1;
    }

    /** @param array<string,mixed> $row */
    private function insertEvent(array $row): int
    {
        return (int)Db::name(self::EVENT_TABLE)->insertGetId($row);
    }

    /** @param array<string,mixed> $row */
    private function insertProjection(array $row): void
    {
        Db::name(self::PREFERENCE_TABLE)->insert($row);
    }

    /** @return array<string,mixed>|null */
    private function eventByIdentity(string $eventIdentity): ?array
    {
        $row = Db::name(self::EVENT_TABLE)
            ->where('event_identity', $eventIdentity)
            ->find();
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $row */
    private function assertIdempotencyDigest(array $row, string $digest): void
    {
        if (!hash_equals((string)($row['request_digest'] ?? ''), $digest)) {
            throw new InvalidArgumentException('user_learning_idempotency_conflict');
        }
    }

    /**
     * @return array{tenant_id:int,user_id:int,hotel_id:?int,memory_scope:string,session_ref_hash:?string}
     */
    private function normalizeScope(
        int $tenantId,
        int $userId,
        string $scope,
        ?int $hotelId,
        ?string $sessionRef
    ): array {
        if ($tenantId <= 0 || $userId <= 0) {
            throw new InvalidArgumentException('user_learning_owner_scope_invalid');
        }
        $scope = strtolower(trim($scope));
        if (!in_array($scope, self::SCOPES, true)) {
            throw new InvalidArgumentException('user_learning_memory_scope_invalid');
        }
        if ($hotelId !== null && $hotelId <= 0) {
            throw new InvalidArgumentException('user_learning_hotel_scope_invalid');
        }
        $sessionRef = trim((string)$sessionRef);
        if (strlen($sessionRef) > 191) {
            throw new InvalidArgumentException('user_learning_session_scope_invalid');
        }
        if ($scope === 'global' && ($hotelId !== null || $sessionRef !== '')) {
            throw new InvalidArgumentException('global_scope_must_not_bind_hotel_or_session');
        }
        if ($scope === 'hotel' && ($hotelId === null || $sessionRef !== '')) {
            throw new InvalidArgumentException('hotel_scope_requires_only_hotel');
        }
        if ($scope === 'session' && $sessionRef === '') {
            throw new InvalidArgumentException('session_scope_requires_session_ref');
        }
        return [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'hotel_id' => $hotelId,
            'memory_scope' => $scope,
            'session_ref_hash' => $scope === 'session'
                ? hash('sha256', $sessionRef)
                : null,
        ];
    }

    private function normalizePreferenceKey(string $key): string
    {
        $key = strtolower(trim($key));
        if (preg_match('/^[a-z][a-z0-9_.-]{1,127}$/D', $key) !== 1) {
            throw new InvalidArgumentException('user_learning_preference_key_invalid');
        }
        return $key;
    }

    private function normalizeValue(mixed $value): string
    {
        $normalized = $this->normalizeJsonValue($value, 0);
        if ($normalized === null || $normalized === '' || $normalized === []) {
            throw new InvalidArgumentException('user_learning_preference_value_empty');
        }
        $json = $this->encodeCanonical($normalized);
        if (strlen($json) > 4096) {
            throw new InvalidArgumentException('user_learning_preference_value_too_large');
        }
        return $json;
    }

    private function normalizeJsonValue(mixed $value, int $depth): mixed
    {
        if ($depth > 4) {
            throw new InvalidArgumentException('user_learning_preference_value_too_deep');
        }
        if (is_string($value)) {
            $value = trim($value);
            if (mb_strlen($value) > 1000) {
                throw new InvalidArgumentException(
                    'user_learning_preference_string_too_large'
                );
            }
            return $value;
        }
        if (is_bool($value) || is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new InvalidArgumentException(
                    'user_learning_preference_number_invalid'
                );
            }
            return $value;
        }
        if (!is_array($value) || count($value) > 50) {
            throw new InvalidArgumentException('user_learning_preference_value_invalid');
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_int($key)
                && preg_match('/^[A-Za-z0-9_.-]{1,80}$/D', (string)$key) !== 1
            ) {
                throw new InvalidArgumentException(
                    'user_learning_preference_value_key_invalid'
                );
            }
            $result[$key] = $this->normalizeJsonValue($item, $depth + 1);
        }
        if (!array_is_list($result)) {
            ksort($result, SORT_STRING);
        }
        return $result;
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function normalizeContext(array $context, string $learningStatus): array
    {
        foreach (array_keys($context) as $field) {
            if (!in_array((string)$field, self::CONTEXT_FIELDS, true)) {
                throw new InvalidArgumentException(
                    'user_learning_source_context_field_not_allowed:' . $field
                );
            }
        }
        $classification = strtolower(trim((string)(
            $context['content_classification']
                ?? ($learningStatus === self::STATUS_EXPLICIT_CONFIRMED
                    ? 'user_preference'
                    : 'interaction_pattern')
        )));
        if (!in_array($classification, ['user_preference', 'interaction_pattern'], true)) {
            throw new InvalidArgumentException(
                'user_learning_content_classification_rejected:' . $classification
            );
        }
        $normalized = ['content_classification' => $classification];
        foreach (['source_ref', 'surface', 'reason_code', 'correlation_id'] as $field) {
            if (!array_key_exists($field, $context)) {
                continue;
            }
            $value = trim((string)$context[$field]);
            if ($value === ''
                || strlen($value) > 191
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:#-]*$/D', $value) !== 1
            ) {
                throw new InvalidArgumentException(
                    'user_learning_source_context_value_invalid:' . $field
                );
            }
            $normalized[$field] = $value;
        }
        foreach (['signal_count', 'sample_count'] as $field) {
            if (!array_key_exists($field, $context)) {
                continue;
            }
            $value = filter_var($context[$field], FILTER_VALIDATE_INT);
            if ($value === false || $value < 0 || $value > 1000000) {
                throw new InvalidArgumentException(
                    'user_learning_source_context_value_invalid:' . $field
                );
            }
            $normalized[$field] = $value;
        }
        return $normalized;
    }

    private function assertPreferenceAllowed(
        string $preferenceKey,
        string $valueJson,
        string $classification
    ): void {
        $forbiddenKey = '/(?:^|[._-])(?:password|passwd|pwd|credential|secret|api[_-]?key|token|access[_-]?token|refresh[_-]?token|authorization|auth|cookie|session[_-]?(?:id|token)|otp|captcha|verification[_-]?code|sms[_-]?code|mfa|2fa|passkey|recovery[_-]?code|role|permission|privilege|auto[_-]?approve|approval|bypass[_-]?auth|external[_-]?write)(?:$|[._-])/i';
        $forbiddenFactKey = '/^(?:(?:business[_-]?fact|fact)(?:$|[._-])|(?:hotel[_-]?id|platform[_-]?hotel[_-]?id|business[_-]?date|data[_-]?date)$|(?:current|actual)[._-]?(?:revenue|occupancy|adr|revpar|price|inventory)$|(?:revenue|occupancy|adr|revpar|price|inventory|order|room)[._-]?(?:value|amount|count)$)/i';
        if (preg_match($forbiddenKey, $preferenceKey) === 1) {
            throw new InvalidArgumentException(
                'user_learning_sensitive_preference_rejected'
            );
        }
        if (preg_match($forbiddenFactKey, $preferenceKey) === 1
            || $classification === 'business_fact'
        ) {
            throw new InvalidArgumentException(
                'user_learning_business_fact_rejected'
            );
        }

        $sensitiveValuePatterns = [
            '/\b(?:password|passwd|pwd|api[_ -]?key|access[_ -]?token|refresh[_ -]?token|authorization|cookie)\s*[:=]\s*\S+/iu',
            '/(?:密码|凭证|密钥|令牌|验证码|短信码|恢复码)\s*[:：=]\s*\S+/u',
            '/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{5,}\b/',
            '/\b(?:bearer\s+|sk-(?:proj-)?)[A-Za-z0-9._-]{12,}\b/i',
            '/(?:以后|今后).{0,12}(?:直接|自动).{0,12}(?:改价|审批|外发|发送|执行|写入|删除)/u',
            '/(?:无需|不用|绕过|跳过).{0,8}(?:确认|审批|鉴权|权限)/u',
            '/\b(?:bypass|skip)\s+(?:approval|authorization|auth)\b/i',
            '/\bauto(?:matically)?[-_\s]?(?:approve|execute|publish|send|write|delete)\b/i',
        ];
        foreach ($sensitiveValuePatterns as $pattern) {
            if (preg_match($pattern, $valueJson) === 1) {
                throw new InvalidArgumentException(
                    'user_learning_sensitive_preference_rejected'
                );
            }
        }
    }

    /**
     * @param array<string,mixed> $scopeData
     */
    private function preferenceIdentity(
        array $scopeData,
        string $preferenceKey
    ): string {
        return hash('sha256', implode('|', [
            self::CONTRACT_VERSION,
            (string)$scopeData['tenant_id'],
            (string)$scopeData['user_id'],
            (string)$scopeData['memory_scope'],
            (string)($scopeData['hotel_id'] ?? 0),
            (string)($scopeData['session_ref_hash'] ?? ''),
            $preferenceKey,
        ]));
    }

    /** @return array{idempotency_hash:string,event_identity:string} */
    private function idempotency(
        int $tenantId,
        int $userId,
        string $idempotencyKey
    ): array {
        $idempotencyKey = trim($idempotencyKey);
        if (strlen($idempotencyKey) < 8
            || strlen($idempotencyKey) > 191
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $idempotencyKey) !== 1
        ) {
            throw new InvalidArgumentException(
                'user_learning_idempotency_key_invalid'
            );
        }
        $hash = hash('sha256', $idempotencyKey);
        return [
            'idempotency_hash' => $hash,
            'event_identity' => hash('sha256', implode('|', [
                self::CONTRACT_VERSION,
                (string)$tenantId,
                (string)$userId,
                $hash,
            ])),
        ];
    }

    /** @param array<string,mixed> $input */
    private function requestDigest(array $input): string
    {
        return hash('sha256', $this->encodeCanonical($input));
    }

    /** @param array<string,mixed> $scopeData @return array<string,mixed> */
    private function scopeDigestInput(array $scopeData): array
    {
        return [
            'tenant_id' => (int)$scopeData['tenant_id'],
            'user_id' => (int)$scopeData['user_id'],
            'hotel_id' => $scopeData['hotel_id'],
            'memory_scope' => (string)$scopeData['memory_scope'],
            'session_ref_hash' => $scopeData['session_ref_hash'],
        ];
    }

    /** @param array<string,mixed> $scopeData @return array<string,mixed> */
    private function publicScope(array $scopeData): array
    {
        return [
            'tenant_id' => (int)$scopeData['tenant_id'],
            'user_id' => (int)$scopeData['user_id'],
            'hotel_id' => $scopeData['hotel_id'] === null
                ? null
                : (int)$scopeData['hotel_id'],
            'scope' => (string)$scopeData['memory_scope'],
            'session_scoped' => $scopeData['session_ref_hash'] !== null,
        ];
    }

    private function encodeCanonical(mixed $value): string
    {
        $value = $this->canonicalize($value);
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $result = [];
        foreach ($value as $key => $item) {
            $result[$key] = $this->canonicalize($item);
        }
        if (!array_is_list($result)) {
            ksort($result, SORT_STRING);
        }
        return $result;
    }

    private function decodeJson(string $json): mixed
    {
        try {
            return json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'user_learning_json_readback_failed',
                0,
                $exception
            );
        }
    }

    private function now(): string
    {
        return (new DateTimeImmutable(
            'now',
            new DateTimeZone('Asia/Shanghai')
        ))->format('Y-m-d H:i:s.u');
    }

    /** @return array<string,mixed>|null */
    private function schemaGap(string $operation): ?array
    {
        $required = [
            self::EVENT_TABLE => [
                'id', 'tenant_id', 'user_id', 'hotel_id', 'memory_scope',
                'session_ref_hash', 'preference_key', 'preference_identity',
                'event_type', 'learning_status', 'value_json', 'value_hash',
                'source_type', 'source_context_json', 'idempotency_hash',
                'event_identity', 'request_digest', 'created_at',
            ],
            self::PREFERENCE_TABLE => [
                'id', 'tenant_id', 'user_id', 'hotel_id', 'memory_scope',
                'session_ref_hash', 'preference_key', 'preference_identity',
                'version', 'event_id', 'learning_status', 'lifecycle_status',
                'value_json', 'value_hash', 'created_at',
            ],
        ];
        $missingTables = [];
        $missingColumns = [];
        foreach ($required as $table => $columns) {
            $inspection = DatabaseSchemaRequirement::inspectTableColumns(
                $table
            );
            if ($inspection['status'] === DatabaseSchemaRequirement::STATUS_MISSING) {
                $missingTables[] = $table;
                continue;
            }
            if ($inspection['status'] !== DatabaseSchemaRequirement::STATUS_PRESENT) {
                throw new RuntimeException(
                    'user_learning_memory_schema_unreadable:' . $table,
                    503
                );
            }
            $missing = array_values(array_diff(
                $columns,
                $inspection['columns']
            ));
            if ($missing !== []) {
                $missingColumns[$table] = $missing;
            }
        }
        if ($missingTables === [] && $missingColumns === []) {
            return null;
        }
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'status' => 'migration_required',
            'operation' => $operation,
            'migration_required' => true,
            'migration' => 'database/migrations/20260829_create_user_learning_memory.sql',
            'missing_tables' => $missingTables,
            'missing_columns' => $missingColumns,
            'readback' => [
                'status' => 'migration_required',
                'exact_readback_verified' => false,
            ],
        ];
    }

    private function isDuplicateKeyConflict(Throwable $error): bool
    {
        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            $message = strtolower($current->getMessage());
            if (str_contains($message, 'duplicate entry')
                || str_contains($message, 'integrity constraint violation: 1062')
                || str_contains($message, 'unique constraint failed')
            ) {
                return true;
            }
        }
        return false;
    }
}
