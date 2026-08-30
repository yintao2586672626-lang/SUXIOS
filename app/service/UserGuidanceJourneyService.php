<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

/**
 * Server-side continuity for the existing system-usage assistant.
 *
 * The records describe how to resume a user-owned journey. They never become
 * hotel facts, permissions, approvals, OTA writes, or external messages.
 */
final class UserGuidanceJourneyService
{
    public const TABLE = 'user_guidance_journeys';
    public const CONTRACT_VERSION = 'user_guidance_journey.v1';

    /** @var list<string> */
    private const STEP_STATUSES = ['pending', 'in_progress', 'checking', 'blocked', 'completed'];

    /** @var list<string> */
    private const LIFECYCLE_STATUSES = ['active', 'completed', 'archived'];

    /** @return array<string,mixed> */
    public function save(
        int $tenantId,
        int $userId,
        ?int $hotelId,
        array $input,
        int $recordedBy
    ): array {
        $this->assertScope($tenantId, $userId, $hotelId, $recordedBy);
        $this->assertTableReady();

        $normalized = $this->normalizeInput($tenantId, $userId, $hotelId, $input);
        $hotelScopeId = $hotelId ?? 0;
        $now = date('Y-m-d H:i:s');

        return Db::transaction(function () use (
            $tenantId,
            $userId,
            $hotelId,
            $hotelScopeId,
            $recordedBy,
            $normalized,
            $now
        ): array {
            $previous = Db::name(self::TABLE)
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->where('hotel_id', $hotelScopeId)
                ->where('journey_key', $normalized['journey_key'])
                ->order('version_no', 'desc')
                ->order('id', 'desc')
                ->lock(true)
                ->find();

            if (is_array($previous)
                && hash_equals((string)$previous['content_digest'], $normalized['content_digest'])
                && (string)$previous['lifecycle_status'] === $normalized['lifecycle_status']
            ) {
                return $this->persistenceResult(false, $this->readExact(
                    (int)$previous['id'],
                    $tenantId,
                    $userId,
                    $hotelScopeId
                ));
            }

            if ($normalized['lifecycle_status'] === 'active') {
                $activeQuery = Db::name(self::TABLE)
                    ->where('tenant_id', $tenantId)
                    ->where('user_id', $userId)
                    ->where('lifecycle_status', 'active');
                $this->applyHotelScope($activeQuery, $hotelId);
                $activeQuery->update(['lifecycle_status' => 'superseded']);
            } elseif (is_array($previous) && (string)$previous['lifecycle_status'] === 'active') {
                Db::name(self::TABLE)->where('id', (int)$previous['id'])
                    ->update(['lifecycle_status' => 'superseded']);
            }

            $row = [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'hotel_id' => $hotelScopeId,
                'journey_key' => $normalized['journey_key'],
                'version_no' => max(1, (int)($previous['version_no'] ?? 0) + 1),
                'goal' => $normalized['goal'],
                'original_query_digest' => $normalized['original_query_digest'],
                'active_key' => $normalized['active_key'],
                'journey_keys_json' => $this->encodeJson($normalized['journey_keys']),
                'current_step_status' => $normalized['current_step_status'],
                'blocker_code' => $normalized['blocker_code'],
                'blocker_summary' => $normalized['blocker_summary'],
                'lifecycle_status' => $normalized['lifecycle_status'],
                'content_digest' => $normalized['content_digest'],
                'previous_journey_id' => is_array($previous) ? (int)$previous['id'] : null,
                'recorded_by' => $recordedBy,
                'created_at' => $now,
            ];

            try {
                $id = (int)Db::name(self::TABLE)->insertGetId($row);
            } catch (\Throwable $e) {
                $winner = Db::name(self::TABLE)
                    ->where('tenant_id', $tenantId)
                    ->where('user_id', $userId)
                    ->where('hotel_id', $hotelScopeId)
                    ->where('journey_key', $normalized['journey_key'])
                    ->where('content_digest', $normalized['content_digest'])
                    ->where('lifecycle_status', $normalized['lifecycle_status'])
                    ->find();
                if (!is_array($winner)) {
                    throw $e;
                }
                return $this->persistenceResult(false, $this->readExact(
                    (int)$winner['id'],
                    $tenantId,
                    $userId,
                    $hotelScopeId
                ));
            }

            $readback = $this->readExact($id, $tenantId, $userId, $hotelScopeId);
            if (!hash_equals($normalized['content_digest'], (string)$readback['content_digest'])) {
                throw new RuntimeException('user guidance journey exact readback failed');
            }
            return $this->persistenceResult(true, $readback);
        });
    }

    /** @return array<string,mixed> */
    public function readActive(int $tenantId, int $userId, ?int $hotelId): array
    {
        $this->assertScope($tenantId, $userId, $hotelId, $userId);
        if (!$this->tableExists()) {
            return $this->missingMigrationResult();
        }

        $query = Db::name(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->whereIn('hotel_id', $hotelId !== null && $hotelId > 0 ? [$hotelId, 0] : [0])
            ->where('lifecycle_status', 'active');
        $rows = $query->order('id', 'desc')->limit(20)->select()->toArray();
        if ($hotelId !== null && $hotelId > 0) {
            usort($rows, static function (array $left, array $right) use ($hotelId): int {
                $leftExact = (int)($left['hotel_id'] ?? 0) === $hotelId ? 1 : 0;
                $rightExact = (int)($right['hotel_id'] ?? 0) === $hotelId ? 1 : 0;
                return $rightExact <=> $leftExact ?: (int)$right['id'] <=> (int)$left['id'];
            });
        }
        if ($rows === []) {
            return [
                'contract_version' => self::CONTRACT_VERSION,
                'data_status' => 'empty',
                'journey' => null,
                'write_boundaries' => $this->writeBoundaries(),
            ];
        }
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'data_status' => 'ready',
            'journey' => $this->readExact(
                (int)$rows[0]['id'],
                $tenantId,
                $userId,
                (int)($rows[0]['hotel_id'] ?? 0)
            ),
            'write_boundaries' => $this->writeBoundaries(),
        ];
    }

    /** @return array<string,mixed> */
    public function readResumeCard(int $tenantId, int $userId, ?int $hotelId): array
    {
        $active = $this->readActive($tenantId, $userId, $hotelId);
        if (($active['data_status'] ?? '') !== 'ready'
            || !is_array($active['journey'] ?? null)
        ) {
            return [
                'contract_version' => 'user_guidance_resume_card.v1',
                'data_status' => (string)($active['data_status'] ?? 'unavailable'),
                'card' => null,
                'actions' => [
                    'can_continue' => false,
                    'can_complete' => false,
                    'can_ignore' => false,
                ],
                'boundaries' => $this->resumeBoundaries(),
                'migration_required' => (bool)($active['migration_required'] ?? false),
            ];
        }
        $journey = $active['journey'];
        $scopeHotelId = max(0, (int)($journey['hotel_id'] ?? 0));
        return [
            'contract_version' => 'user_guidance_resume_card.v1',
            'data_status' => 'ready',
            'scope' => [
                'type' => $scopeHotelId > 0 ? 'hotel' : 'global',
                'hotel_id' => $scopeHotelId > 0 ? $scopeHotelId : null,
            ],
            'card' => [
                'journey_id' => (int)$journey['id'],
                'journey_key' => (string)$journey['journey_key'],
                'version_no' => (int)$journey['version_no'],
                'content_digest' => (string)$journey['content_digest'],
                'goal_summary' => (string)$journey['goal'],
                'next_step' => [
                    'topic_key' => (string)$journey['active_key'],
                    'status' => (string)$journey['current_step_status'],
                    'blocker_code' => (string)$journey['blocker_code'],
                    'blocker_summary' => (string)$journey['blocker_summary'],
                ],
                'journey_keys' => $journey['journey_keys'],
                'saved_at' => (string)$journey['created_at'],
                'readback_verified' => true,
            ],
            'actions' => [
                'can_continue' => true,
                'can_complete' => true,
                'can_ignore' => true,
            ],
            'boundaries' => $this->resumeBoundaries(),
        ];
    }

    /** @return array<string,mixed> */
    public function transitionExact(
        int $tenantId,
        int $userId,
        ?int $contextHotelId,
        int $journeyId,
        string $expectedContentDigest,
        string $action,
        int $recordedBy
    ): array {
        $this->assertScope($tenantId, $userId, $contextHotelId, $recordedBy);
        $this->assertTableReady();
        if ($journeyId <= 0) {
            throw new InvalidArgumentException('journey_id is invalid');
        }
        $expectedContentDigest = strtolower(trim($expectedContentDigest));
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedContentDigest) !== 1) {
            throw new InvalidArgumentException('expected_content_digest is invalid');
        }
        $action = strtolower(trim($action));
        if (!in_array($action, ['complete', 'ignore'], true)) {
            throw new InvalidArgumentException('journey transition action is invalid');
        }
        $targetLifecycle = $action === 'complete' ? 'completed' : 'archived';

        return Db::transaction(function () use (
            $tenantId,
            $userId,
            $contextHotelId,
            $journeyId,
            $expectedContentDigest,
            $action,
            $targetLifecycle,
            $recordedBy
        ): array {
            $allowedHotelIds = $contextHotelId !== null && $contextHotelId > 0
                ? [$contextHotelId, 0]
                : [0];
            $row = Db::name(self::TABLE)
                ->where('id', $journeyId)
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->whereIn('hotel_id', $allowedHotelIds)
                ->lock(true)
                ->find();
            if (!is_array($row)) {
                throw new RuntimeException('resume journey is missing or inaccessible', 404);
            }
            $journey = $this->normalizeRow($row);
            if (($journey['readback_verified'] ?? false) !== true) {
                throw new RuntimeException('resume journey exact readback integrity failed');
            }
            if (!hash_equals($expectedContentDigest, (string)$journey['content_digest'])) {
                throw new RuntimeException('stale_resume_card', 409);
            }

            $successor = Db::name(self::TABLE)
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->where('hotel_id', (int)($row['hotel_id'] ?? 0))
                ->where('previous_journey_id', $journeyId)
                ->order('id', 'desc')
                ->lock(true)
                ->find();
            if (is_array($successor)) {
                $successorJourney = $this->normalizeRow($successor);
                if ((string)$successorJourney['lifecycle_status'] !== $targetLifecycle) {
                    throw new RuntimeException('journey_transition_conflict', 409);
                }
                return $this->transitionResult(
                    $action,
                    true,
                    $journey,
                    $successorJourney
                );
            }
            if ((string)$journey['lifecycle_status'] !== 'active') {
                throw new RuntimeException('stale_resume_card', 409);
            }

            $latest = Db::name(self::TABLE)
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->where('hotel_id', (int)($row['hotel_id'] ?? 0))
                ->where('journey_key', (string)$journey['journey_key'])
                ->order('version_no', 'desc')
                ->order('id', 'desc')
                ->lock(true)
                ->find();
            if (!is_array($latest) || (int)$latest['id'] !== $journeyId) {
                throw new RuntimeException('stale_resume_card', 409);
            }

            $actualHotelId = max(0, (int)($row['hotel_id'] ?? 0));
            $normalized = $this->normalizeInput(
                $tenantId,
                $userId,
                $actualHotelId > 0 ? $actualHotelId : null,
                [
                    'journey_key' => $journey['journey_key'],
                    'goal' => $journey['goal'],
                    'original_query_digest' => $journey['original_query_digest'],
                    'active_key' => $journey['active_key'],
                    'journey_keys' => $journey['journey_keys'],
                    'current_step_status' => $action === 'complete'
                        ? 'completed'
                        : $journey['current_step_status'],
                    'blocker_code' => $action === 'complete' ? '' : $journey['blocker_code'],
                    'blocker_summary' => $action === 'complete' ? '' : $journey['blocker_summary'],
                    'lifecycle_status' => $targetLifecycle,
                ]
            );
            Db::name(self::TABLE)->where('id', $journeyId)->update([
                'lifecycle_status' => 'superseded',
            ]);
            $id = (int)Db::name(self::TABLE)->insertGetId([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'hotel_id' => $actualHotelId,
                'journey_key' => $normalized['journey_key'],
                'version_no' => (int)$journey['version_no'] + 1,
                'goal' => $normalized['goal'],
                'original_query_digest' => $normalized['original_query_digest'],
                'active_key' => $normalized['active_key'],
                'journey_keys_json' => $this->encodeJson($normalized['journey_keys']),
                'current_step_status' => $normalized['current_step_status'],
                'blocker_code' => $normalized['blocker_code'],
                'blocker_summary' => $normalized['blocker_summary'],
                'lifecycle_status' => $targetLifecycle,
                'content_digest' => $normalized['content_digest'],
                'previous_journey_id' => $journeyId,
                'recorded_by' => $recordedBy,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $readback = $this->readExact($id, $tenantId, $userId, $actualHotelId);
            return $this->transitionResult($action, false, $journey, $readback);
        });
    }

    /** @return array<string,mixed> */
    public function archiveActive(
        int $tenantId,
        int $userId,
        ?int $hotelId,
        int $recordedBy
    ): array {
        $active = $this->readActive($tenantId, $userId, $hotelId);
        if (($active['data_status'] ?? '') !== 'ready' || !is_array($active['journey'] ?? null)) {
            return $active;
        }
        $journey = $active['journey'];
        return $this->transitionExact(
            $tenantId,
            $userId,
            $hotelId,
            (int)$journey['id'],
            (string)$journey['content_digest'],
            'ignore',
            $recordedBy
        );
    }

    public function tableExists(): bool
    {
        try {
            Db::query('SELECT 1 FROM `' . self::TABLE . '` WHERE 1 = 0');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string,mixed> */
    private function readExact(int $id, int $tenantId, int $userId, int $hotelScopeId): array
    {
        $row = Db::name(self::TABLE)
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('hotel_id', $hotelScopeId)
            ->find();
        if (!is_array($row)) {
            throw new RuntimeException('user guidance journey exact readback missing');
        }
        $normalized = $this->normalizeRow($row);
        if (($normalized['readback_verified'] ?? false) !== true) {
            throw new RuntimeException('user guidance journey exact readback integrity failed');
        }
        return $normalized;
    }

    /** @return array<string,mixed> */
    private function normalizeInput(int $tenantId, int $userId, ?int $hotelId, array $input): array
    {
        $goal = $this->safeText($input['goal'] ?? '', 'goal', 240, true);
        $originalQuery = $this->safeText($input['original_query'] ?? '', 'original_query', 500, false);
        $originalQueryDigest = strtolower(trim((string)($input['original_query_digest'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/D', $originalQueryDigest) !== 1) {
            $originalQueryDigest = $originalQuery !== ''
                ? hash('sha256', $originalQuery)
                : str_repeat('0', 64);
        }
        $blockerSummary = $this->safeText($input['blocker_summary'] ?? '', 'blocker_summary', 500, false);
        $keys = [];
        foreach (array_slice(is_array($input['journey_keys'] ?? null) ? $input['journey_keys'] : [], 0, 4) as $rawKey) {
            $key = mb_substr(strtolower(trim((string)$rawKey)), 0, 80);
            if (preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/D', $key) !== 1) {
                throw new InvalidArgumentException('journey key is invalid');
            }
            if (!in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }
        if ($keys === []) {
            throw new InvalidArgumentException('journey keys are required');
        }
        $activeKey = mb_substr(strtolower(trim((string)($input['active_key'] ?? ''))), 0, 80);
        if (!in_array($activeKey, $keys, true)) {
            $activeKey = $keys[0];
        }
        $stepStatus = strtolower(trim((string)($input['current_step_status'] ?? 'pending')));
        if (!in_array($stepStatus, self::STEP_STATUSES, true)) {
            throw new InvalidArgumentException('journey step status is invalid');
        }
        $lifecycle = strtolower(trim((string)($input['lifecycle_status'] ?? 'active')));
        if (!in_array($lifecycle, self::LIFECYCLE_STATUSES, true)) {
            throw new InvalidArgumentException('journey lifecycle status is invalid');
        }
        $blockerCode = mb_substr(strtolower(trim((string)($input['blocker_code'] ?? ''))), 0, 120);
        if ($blockerCode !== '' && preg_match('/^[a-z0-9][a-z0-9_.:-]{0,119}$/D', $blockerCode) !== 1) {
            throw new InvalidArgumentException('journey blocker code is invalid');
        }
        $journeyKey = strtolower(trim((string)($input['journey_key'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/D', $journeyKey) !== 1) {
            $journeyKey = hash('sha256', implode('|', [
                (string)$tenantId,
                (string)$userId,
                (string)($hotelId ?? 0),
                $goal,
            ]));
        }
        $stable = [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'hotel_id' => $hotelId ?? 0,
            'journey_key' => $journeyKey,
            'goal' => $goal,
            'original_query_digest' => $originalQueryDigest,
            'active_key' => $activeKey,
            'journey_keys' => $keys,
            'current_step_status' => $stepStatus,
            'blocker_code' => $blockerCode,
            'blocker_summary' => $blockerSummary,
        ];
        $stable['content_digest'] = hash('sha256', $this->encodeJson($stable));
        $stable['lifecycle_status'] = $lifecycle;
        return $stable;
    }

    private function safeText(mixed $value, string $field, int $maxLength, bool $required): string
    {
        $text = trim((string)$value);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;
        if ($required && $text === '') {
            throw new InvalidArgumentException($field . ' is required');
        }
        if (mb_strlen($text) > $maxLength) {
            throw new InvalidArgumentException($field . ' is too long');
        }
        if ($this->containsSensitiveValue($text)) {
            throw new InvalidArgumentException($field . ' contains sensitive credential material');
        }
        return $text;
    }

    private function containsSensitiveValue(string $text): bool
    {
        return preg_match('/(?:password|passwd|cookie|authorization|access[_ -]?token|refresh[_ -]?token|secret|验证码|短信码|verification[_ -]?code)\s*[:=：]\s*\S+/iu', $text) === 1
            || preg_match('/\bsk-[A-Za-z0-9_-]{16,}\b/', $text) === 1
            || preg_match('/\beyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{10,}/', $text) === 1;
    }

    private function assertScope(int $tenantId, int $userId, ?int $hotelId, int $recordedBy): void
    {
        if ($tenantId < 0 || $userId <= 0 || $recordedBy <= 0 || ($hotelId !== null && $hotelId <= 0)) {
            throw new InvalidArgumentException('user guidance journey scope is invalid');
        }
        if ($recordedBy !== $userId) {
            throw new RuntimeException('user guidance journey can only be changed by its owner');
        }
    }

    private function assertTableReady(): void
    {
        if (!$this->tableExists()) {
            throw new RuntimeException('user guidance journey migration required', 503);
        }
    }

    private function applyHotelScope(mixed $query, ?int $hotelId): void
    {
        $query->where('hotel_id', $hotelId ?? 0);
    }

    /** @return array<string,mixed> */
    private function normalizeRow(array $row): array
    {
        $normalized = [
            'id' => (int)$row['id'],
            'tenant_id' => (int)$row['tenant_id'],
            'user_id' => (int)$row['user_id'],
            'hotel_id' => (int)($row['hotel_id'] ?? 0) ?: null,
            'journey_key' => (string)$row['journey_key'],
            'version_no' => (int)$row['version_no'],
            'goal' => (string)$row['goal'],
            'original_query_digest' => (string)$row['original_query_digest'],
            'active_key' => (string)$row['active_key'],
            'journey_keys' => array_values(array_filter(
                $this->decodeJson((string)$row['journey_keys_json']),
                'is_string'
            )),
            'current_step_status' => (string)$row['current_step_status'],
            'blocker_code' => (string)$row['blocker_code'],
            'blocker_summary' => (string)$row['blocker_summary'],
            'lifecycle_status' => (string)$row['lifecycle_status'],
            'content_digest' => (string)$row['content_digest'],
            'previous_journey_id' => (int)($row['previous_journey_id'] ?? 0) ?: null,
            'recorded_by' => (int)$row['recorded_by'],
            'created_at' => (string)$row['created_at'],
        ];
        $digestPayload = [
            'tenant_id' => $normalized['tenant_id'],
            'user_id' => $normalized['user_id'],
            'hotel_id' => $normalized['hotel_id'] ?? 0,
            'journey_key' => $normalized['journey_key'],
            'goal' => $normalized['goal'],
            'original_query_digest' => $normalized['original_query_digest'],
            'active_key' => $normalized['active_key'],
            'journey_keys' => $normalized['journey_keys'],
            'current_step_status' => $normalized['current_step_status'],
            'blocker_code' => $normalized['blocker_code'],
            'blocker_summary' => $normalized['blocker_summary'],
        ];
        $normalized['readback_verified'] = hash_equals(
            $normalized['content_digest'],
            hash('sha256', $this->encodeJson($digestPayload))
        );
        return $normalized;
    }

    /** @return array<string,mixed> */
    private function persistenceResult(bool $created, array $journey): array
    {
        if (($journey['readback_verified'] ?? false) !== true) {
            throw new RuntimeException('user guidance journey exact readback integrity failed');
        }
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'created' => $created,
            'persistence_status' => 'readback_verified',
            'journey' => $journey,
            'write_boundaries' => $this->writeBoundaries(),
        ];
    }

    /** @return array<string,bool> */
    private function writeBoundaries(): array
    {
        return [
            'hotel_fact_write' => false,
            'permission_change' => false,
            'business_approval' => false,
            'ota_write' => false,
            'pms_write' => false,
            'external_message' => false,
        ];
    }

    /** @return array<string,bool> */
    private function resumeBoundaries(): array
    {
        return [
            'hotel_fact_changed' => false,
            'permission_changed' => false,
            'approval_changed' => false,
            'ota_write_authorized' => false,
            'pms_write_authorized' => false,
            'external_message_authorized' => false,
            'business_completion_claimed' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function transitionResult(
        string $action,
        bool $idempotentReplay,
        array $previous,
        array $journey
    ): array {
        if (($journey['readback_verified'] ?? false) !== true) {
            throw new RuntimeException('journey transition exact readback failed');
        }
        return [
            'contract_version' => 'user_guidance_journey_transition.v1',
            'status' => 'exact_readback_verified',
            'action' => $action,
            'idempotent_replay' => $idempotentReplay,
            'previous' => [
                'journey_id' => (int)$previous['id'],
                'version_no' => (int)$previous['version_no'],
                'content_digest' => (string)$previous['content_digest'],
            ],
            'journey' => $journey,
            'resume_card' => null,
            'boundaries' => $this->resumeBoundaries(),
        ];
    }

    /** @return array<string,mixed> */
    private function missingMigrationResult(): array
    {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'data_status' => 'migration_required',
            'journey' => null,
            'reason_code' => 'user_guidance_journey_table_missing',
            'write_boundaries' => $this->writeBoundaries(),
        ];
    }

    private function encodeJson(mixed $value): string
    {
        return (string)json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
    }

    /** @return array<int|string,mixed> */
    private function decodeJson(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
