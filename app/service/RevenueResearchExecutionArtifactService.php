<?php
declare(strict_types=1);

namespace app\service;

use Closure;
use RuntimeException;
use think\facade\Cache;

final class RevenueResearchExecutionArtifactService
{
    private const TTL_SECONDS = 1800;
    private const ID_PATTERN = '/^[a-f0-9]{32}$/D';

    private Closure $reader;
    private Closure $writer;
    private Closure $deleter;
    private Closure $clock;
    private Closure $idGenerator;
    private Closure $consumeLock;

    public function __construct(
        ?callable $reader = null,
        ?callable $writer = null,
        ?callable $deleter = null,
        ?callable $clock = null,
        ?callable $idGenerator = null,
        ?callable $consumeLock = null
    ) {
        $this->reader = Closure::fromCallable($reader ?? static fn(string $key): mixed => Cache::get($key));
        $this->writer = Closure::fromCallable($writer ?? static fn(string $key, array $value, int $ttl): bool => Cache::set($key, $value, $ttl));
        $this->deleter = Closure::fromCallable($deleter ?? static fn(string $key): bool => Cache::delete($key));
        $this->clock = Closure::fromCallable($clock ?? static fn(): int => time());
        $this->idGenerator = Closure::fromCallable($idGenerator ?? static fn(): string => bin2hex(random_bytes(16)));
        $this->consumeLock = Closure::fromCallable($consumeLock ?? [$this, 'withConsumeFileLock']);
    }

    /**
     * @param array<string, mixed> $research
     * @return array{id:string,status:string,expires_at:string,expires_in:int}
     */
    public function issue(array $research, int $actorId, int $hotelId): array
    {
        $this->assertIssuable($research, $actorId, $hotelId);
        $artifactId = strtolower(trim((string)($this->idGenerator)()));
        if (preg_match(self::ID_PATTERN, $artifactId) !== 1) {
            throw new RuntimeException('unable to generate revenue research execution artifact', 503);
        }

        $expiresAt = ($this->clock)() + self::TTL_SECONDS;
        $payload = [
            'actor_id' => $actorId,
            'hotel_id' => $hotelId,
            'product_key' => trim((string)($research['product_key'] ?? '')),
            'research' => $research,
            'issued_at' => ($this->clock)(),
            'expires_at' => $expiresAt,
        ];
        $payload['payload_hash'] = $this->payloadHash($payload);
        $cacheKey = $this->cacheKey($artifactId);
        if (!(bool)($this->writer)($cacheKey, $payload, self::TTL_SECONDS)) {
            throw new RuntimeException('unable to persist revenue research execution artifact', 503);
        }

        $stored = ($this->reader)($cacheKey);
        if (!$this->payloadIsIntact($stored, $actorId, $hotelId)) {
            ($this->deleter)($cacheKey);
            throw new RuntimeException('revenue research execution artifact readback verification failed', 503);
        }

        return [
            'id' => $artifactId,
            'status' => 'available',
            'expires_at' => date(DATE_ATOM, $expiresAt),
            'expires_in' => self::TTL_SECONDS,
        ];
    }

    /** @return array<string, mixed> */
    public function load(string $artifactId, int $actorId, int $hotelId): array
    {
        $artifactId = strtolower(trim($artifactId));
        if (preg_match(self::ID_PATTERN, $artifactId) !== 1 || $actorId <= 0 || $hotelId <= 0) {
            throw new RuntimeException('valid revenue research execution artifact is required', 422);
        }

        $payload = ($this->reader)($this->cacheKey($artifactId));
        if (!is_array($payload)) {
            throw new RuntimeException('revenue research execution artifact is missing or expired; rerun research', 410);
        }
        if ((int)($payload['expires_at'] ?? 0) < ($this->clock)()) {
            ($this->deleter)($this->cacheKey($artifactId));
            throw new RuntimeException('revenue research execution artifact is missing or expired; rerun research', 410);
        }
        if (!$this->payloadIsIntact($payload, $actorId, $hotelId)) {
            throw new RuntimeException('revenue research execution artifact does not match current user or hotel', 403);
        }

        $research = $payload['research'] ?? null;
        if (!is_array($research)) {
            throw new RuntimeException('revenue research execution artifact payload is invalid', 422);
        }
        return $research;
    }

    /** @return array<string, mixed> */
    public function consume(string $artifactId, int $actorId, int $hotelId): array
    {
        return ($this->consumeLock)(function () use ($artifactId, $actorId, $hotelId): array {
            $research = $this->load($artifactId, $actorId, $hotelId);
            if (!$this->delete($artifactId)) {
                throw new RuntimeException('revenue research execution artifact was already consumed', 409);
            }
            return $research;
        });
    }

    public function delete(string $artifactId): bool
    {
        $artifactId = strtolower(trim($artifactId));
        return preg_match(self::ID_PATTERN, $artifactId) === 1
            && (bool)($this->deleter)($this->cacheKey($artifactId));
    }

    /** @param array<string, mixed> $research */
    private function assertIssuable(array $research, int $actorId, int $hotelId): void
    {
        $readiness = is_array($research['readiness'] ?? null) ? $research['readiness'] : [];
        $result = is_array($research['result'] ?? null) ? $research['result'] : [];
        $gaps = array_values(array_filter((array)($research['gaps'] ?? []), 'is_array'));
        $resultGaps = array_values(array_filter(array_map('strval', (array)($result['data_gaps'] ?? []))));
        $executable = array_filter(
            (array)($result['decision_recommendations'] ?? []),
            static fn(mixed $item): bool => is_array($item)
                && ($item['can_create_execution_intent'] ?? false) === true
                && (($item['decision_quality']['contract_version'] ?? '') === AiDecisionQualityService::CONTRACT_VERSION)
                && (($item['decision_quality']['execution_ready'] ?? false) === true)
        );

        if ($actorId <= 0
            || $hotelId <= 0
            || trim((string)($research['product_key'] ?? '')) === ''
            || trim((string)($research['status'] ?? '')) !== 'done'
            || ($readiness['stage'] ?? '') !== 'research_ready_for_execution'
            || ($readiness['execution_ready'] ?? false) !== true
            || $gaps !== []
            || $resultGaps !== []
            || $executable === []) {
            throw new RuntimeException('revenue research is not eligible for an execution artifact', 422);
        }
    }

    private function payloadIsIntact(mixed $payload, int $actorId, int $hotelId): bool
    {
        if (!is_array($payload)
            || (int)($payload['actor_id'] ?? 0) !== $actorId
            || (int)($payload['hotel_id'] ?? 0) !== $hotelId) {
            return false;
        }
        $storedHash = (string)($payload['payload_hash'] ?? '');
        if ($storedHash === '') {
            return false;
        }
        return hash_equals($storedHash, $this->payloadHash($payload));
    }

    /** @param array<string, mixed> $payload */
    private function payloadHash(array $payload): string
    {
        unset($payload['payload_hash']);
        return hash('sha256', serialize($payload));
    }

    private function cacheKey(string $artifactId): string
    {
        return 'revenue_research_execution_artifact:' . $artifactId;
    }

    private function withConsumeFileLock(callable $callback): mixed
    {
        $dir = rtrim(runtime_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'revenue-research-locks';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('revenue research execution artifact lock directory is unavailable', 503);
        }
        $handle = fopen($dir . DIRECTORY_SEPARATOR . 'consume.lock', 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('revenue research execution artifact lock is unavailable', 503);
        }
        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
