<?php
declare(strict_types=1);

namespace app\contract;

interface ManualOnlineFetchTaskStatusStore
{
    /** @return array<string, mixed> */
    public function read(string $taskId): array;

    /** @param array<string, mixed> $status */
    public function write(string $taskId, array $status): bool;

    /**
     * Atomically read, transform, and persist one task status.
     *
     * @param callable(array<string, mixed>):array<string, mixed> $mutator
     * @return array<string, mixed>
     */
    public function update(string $taskId, callable $mutator): array;

    public function delete(string $taskId): void;

    public function locator(string $taskId): string;

    /**
     * @return array{scanned:int,timed_out:int,orphaned:int,expired:int,removed:int,kept:int,errors:int}
     */
    public function cleanupExpired(
        int $retentionSeconds,
        int $staleSeconds,
        int $orphanSeconds,
        int $now,
        bool $dryRun = false
    ): array;
}
