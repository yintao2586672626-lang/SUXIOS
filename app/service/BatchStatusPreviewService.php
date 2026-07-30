<?php
declare(strict_types=1);

namespace app\service;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Cache;

final class BatchStatusPreviewService
{
    private const TTL_SECONDS = 300;
    private const PREVIEW_ID_PATTERN = '/^[a-f0-9]{32}$/D';

    private Closure $reader;
    private Closure $writer;
    private Closure $deleter;
    private Closure $clock;
    private Closure $idGenerator;

    public function __construct(
        ?callable $reader = null,
        ?callable $writer = null,
        ?callable $deleter = null,
        ?callable $clock = null,
        ?callable $idGenerator = null
    ) {
        $this->reader = Closure::fromCallable($reader ?? static fn(string $key): mixed => Cache::get($key));
        $this->writer = Closure::fromCallable($writer ?? static fn(string $key, array $value, int $ttl): bool => Cache::set($key, $value, $ttl));
        $this->deleter = Closure::fromCallable($deleter ?? static fn(string $key): bool => Cache::delete($key));
        $this->clock = Closure::fromCallable($clock ?? static fn(): int => time());
        $this->idGenerator = Closure::fromCallable($idGenerator ?? static fn(): string => bin2hex(random_bytes(16)));
    }

    /**
     * @param array<int, int> $ids
     * @return array{preview_id:string,expires_in:int}
     */
    public function issue(string $scope, int $actorId, array $ids, int $status): array
    {
        $normalizedIds = $this->normalizeIds($ids);
        $this->assertRequestShape($scope, $actorId, $normalizedIds, $status);

        $previewId = strtolower(trim((string)($this->idGenerator)()));
        if (preg_match(self::PREVIEW_ID_PATTERN, $previewId) !== 1) {
            throw new RuntimeException('Unable to generate a valid batch preview identifier.');
        }

        $payload = [
            'scope' => $scope,
            'actor_id' => $actorId,
            'ids' => $normalizedIds,
            'status' => $status,
            'expires_at' => ($this->clock)() + self::TTL_SECONDS,
        ];
        if (!(bool)($this->writer)($this->cacheKey($previewId), $payload, self::TTL_SECONDS)) {
            throw new RuntimeException('Unable to persist batch preview.');
        }

        return ['preview_id' => $previewId, 'expires_in' => self::TTL_SECONDS];
    }

    /** @param array<int, int> $ids */
    public function consume(string $previewId, string $scope, int $actorId, array $ids, int $status): bool
    {
        $previewId = strtolower(trim($previewId));
        if (preg_match(self::PREVIEW_ID_PATTERN, $previewId) !== 1) {
            return false;
        }

        $key = $this->cacheKey($previewId);
        $payload = ($this->reader)($key);
        if (!is_array($payload)) {
            return false;
        }
        if ((int)($payload['expires_at'] ?? 0) < ($this->clock)()) {
            ($this->deleter)($key);
            return false;
        }

        $normalizedIds = $this->normalizeIds($ids);
        $storedIds = $this->normalizeIds(is_array($payload['ids'] ?? null) ? $payload['ids'] : []);
        $matches = hash_equals((string)($payload['scope'] ?? ''), $scope)
            && (int)($payload['actor_id'] ?? 0) === $actorId
            && (int)($payload['status'] ?? -1) === $status
            && $storedIds === $normalizedIds;
        if (!$matches) {
            return false;
        }

        return (bool)($this->deleter)($key);
    }

    /** @param array<int, mixed> $ids */
    private function normalizeIds(array $ids): array
    {
        $normalized = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn(int $id): bool => $id > 0
        )));
        sort($normalized, SORT_NUMERIC);
        return $normalized;
    }

    /** @param array<int, int> $ids */
    private function assertRequestShape(string $scope, int $actorId, array $ids, int $status): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{2,50}$/D', $scope) !== 1
            || $actorId <= 0
            || $ids === []
            || !in_array($status, [0, 1], true)
        ) {
            throw new InvalidArgumentException('Invalid batch preview request.');
        }
    }

    private function cacheKey(string $previewId): string
    {
        return 'batch_status_preview_' . $previewId;
    }
}
