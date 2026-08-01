<?php
declare(strict_types=1);

namespace Tests;

use app\command\CleanupManualFetchTasks;
use app\contract\ManualOnlineFetchTaskStatusStore;
use app\service\ManualOnlineFetchTaskService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ManualOnlineFetchTaskRetentionTest extends TestCase
{
    private string $taskRoot;
    private string|false $previousPhpCliBinary;
    private string|false $previousDriver;
    private string|false $previousRetention;
    private string|false $previousStaleSeconds;
    /** @var list<string> */
    private array $junctionPaths = [];
    /** @var list<string> */
    private array $externalRoots = [];

    protected function setUp(): void
    {
        $this->taskRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'suxi_manual_fetch_retention_' . getmypid() . '_' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($this->taskRoot, 0775, true));

        $this->previousPhpCliBinary = getenv('PHP_CLI_BINARY');
        $this->previousDriver = getenv('SUXI_MANUAL_FETCH_TASK_STATUS_DRIVER');
        $this->previousRetention = getenv('SUXI_MANUAL_FETCH_TASK_RETENTION_SECONDS');
        $this->previousStaleSeconds = getenv('SUXI_MANUAL_FETCH_TASK_STALE_SECONDS');
        putenv('PHP_CLI_BINARY=' . PHP_BINARY);
        putenv('SUXI_MANUAL_FETCH_TASK_STATUS_DRIVER=file');
        putenv('SUXI_MANUAL_FETCH_TASK_RETENTION_SECONDS=3600');
    }

    protected function tearDown(): void
    {
        foreach ($this->junctionPaths as $junctionPath) {
            @rmdir($junctionPath);
        }
        $this->removeTree($this->taskRoot);
        foreach ($this->externalRoots as $externalRoot) {
            $this->removeTree($externalRoot);
        }
        $this->restoreEnv('PHP_CLI_BINARY', $this->previousPhpCliBinary);
        $this->restoreEnv('SUXI_MANUAL_FETCH_TASK_STATUS_DRIVER', $this->previousDriver);
        $this->restoreEnv('SUXI_MANUAL_FETCH_TASK_RETENTION_SECONDS', $this->previousRetention);
        $this->restoreEnv('SUXI_MANUAL_FETCH_TASK_STALE_SECONDS', $this->previousStaleSeconds);
    }

    public function testExplicitCleanupSupportsDryRunAndPreservesFreshOrUnknownDirectories(): void
    {
        $service = new ManualOnlineFetchTaskService(null, $this->taskRoot);
        $old = $this->createTask($service, 71);
        $fresh = $this->createTask($service, 72);
        $service->markTaskFailed($old['task_id'], 'test failure', 'request_failed');

        $now = time();
        $this->ageTask($old, $now - 7200);
        $unknown = $this->taskRoot . DIRECTORY_SEPARATOR . 'operator-owned-directory';
        self::assertTrue(mkdir($unknown));
        touch($unknown, $now - 7200);

        $dryRun = $service->cleanupExpiredTasks(3600, $now, true);
        self::assertSame(2, $dryRun['scanned']);
        self::assertSame(1, $dryRun['expired']);
        self::assertSame(0, $dryRun['removed']);
        self::assertDirectoryExists(dirname($old['input']));

        $cleanup = $service->cleanupExpiredTasks(3600, $now);
        self::assertSame(1, $cleanup['expired']);
        self::assertSame(1, $cleanup['removed']);
        self::assertSame(0, $cleanup['errors']);
        self::assertDirectoryDoesNotExist(dirname($old['input']));
        self::assertDirectoryExists(dirname($fresh['input']));
        self::assertDirectoryExists($unknown);
    }

    public function testCreatingANewTaskOpportunisticallyPrunesExpiredTaskArtifacts(): void
    {
        $service = new ManualOnlineFetchTaskService(null, $this->taskRoot);
        $old = $this->createTask($service, 73);
        $service->markTaskFailed($old['task_id'], 'test failure', 'request_failed');
        $this->ageTask($old, time() - 7200);

        $fresh = $this->createTask($service, 74);

        self::assertDirectoryDoesNotExist(dirname($old['input']));
        self::assertDirectoryExists(dirname($fresh['input']));
    }

    public function testCleanupTimesOutStaleActiveTaskBeforeRetentionCountdownStarts(): void
    {
        $service = new ManualOnlineFetchTaskService(null, $this->taskRoot);
        $task = $this->createTask($service, 76);
        $now = time();
        $this->ageTask($task, $now - 90000, false);

        $cleanup = $service->cleanupExpiredTasks(86400, $now);

        self::assertSame(1, $cleanup['scanned']);
        self::assertSame(1, $cleanup['timed_out']);
        self::assertSame(0, $cleanup['removed']);
        self::assertDirectoryExists(dirname($task['input']));
        $status = $service->readTaskStatus($task['task_id']);
        self::assertSame('failed', $status['status']);
        self::assertSame('timeout', $status['stage']);
        self::assertSame(date('Y-m-d H:i:s', $now), $status['finished_at']);
    }

    public function testConfiguredStaleThresholdIsSharedByPollingAndCleanup(): void
    {
        putenv('SUXI_MANUAL_FETCH_TASK_STALE_SECONDS=90000');
        $service = new ManualOnlineFetchTaskService(null, $this->taskRoot);
        $task = $this->createTask($service, 77);
        $now = time();
        $this->ageTask($task, $now - 8000, false);

        self::assertSame('queued', $service->readTaskStatus($task['task_id'])['status']);
        $cleanup = $service->cleanupExpiredTasks(3600, $now);
        self::assertSame(0, $cleanup['timed_out']);
        self::assertDirectoryExists(dirname($task['input']));
    }

    public function testAgedRecognizedOrphanDirectoryIsRemoved(): void
    {
        $taskId = 'manual_meituan_fetch_79_20260716000000_cafebabe';
        $taskDirectory = $this->taskRoot . DIRECTORY_SEPARATOR . $taskId;
        self::assertTrue(mkdir($taskDirectory, 0775, true));
        self::assertNotFalse(file_put_contents($taskDirectory . DIRECTORY_SEPARATOR . 'launcher.log', 'orphan'));
        $now = time();
        touch($taskDirectory . DIRECTORY_SEPARATOR . 'launcher.log', $now - 90000);
        touch($taskDirectory, $now - 90000);

        $result = (new ManualOnlineFetchTaskService(null, $this->taskRoot))
            ->cleanupExpiredTasks(3600, $now);

        self::assertSame(1, $result['scanned']);
        self::assertSame(1, $result['orphaned']);
        self::assertSame(1, $result['removed']);
        self::assertDirectoryDoesNotExist($taskDirectory);
    }

    public function testCleanupRejectsWindowsJunctionThatResolvesOutsideTaskRoot(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            self::markTestSkipped('NTFS Junction behavior is Windows-specific.');
        }

        $externalRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'suxi_manual_fetch_external_' . getmypid() . '_' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($externalRoot, 0775, true));
        $this->externalRoots[] = $externalRoot;
        $marker = $externalRoot . DIRECTORY_SEPARATOR . 'must-survive.txt';
        self::assertNotFalse(file_put_contents($marker, 'outside-task-root'));
        touch($externalRoot, time() - 90000);

        $taskId = 'manual_ctrip_fetch_78_20260716000000_deadbeef';
        $junctionPath = $this->taskRoot . DIRECTORY_SEPARATOR . $taskId;
        $this->createWindowsJunction($junctionPath, $externalRoot);
        $this->junctionPaths[] = $junctionPath;

        $result = (new ManualOnlineFetchTaskService(null, $this->taskRoot))
            ->cleanupExpiredTasks(3600, time());

        self::assertSame(1, $result['scanned']);
        self::assertSame(1, $result['errors']);
        self::assertSame(0, $result['removed']);
        self::assertFileExists($marker);
        self::assertSame('outside-task-root', file_get_contents($marker));
    }

    public function testStatusStoreCanBeReplacedWithoutChangingTaskLifecycle(): void
    {
        $store = new InMemoryManualOnlineFetchTaskStatusStore();
        $service = new ManualOnlineFetchTaskService($store, $this->taskRoot);
        $task = $this->createTask($service, 75);

        self::assertStringStartsWith('memory://manual-fetch/', $task['status_file']);
        self::assertSame('queued', $service->readTaskStatus($task['task_id'])['status']);

        $completed = $service->completeTask($task['task_id'], [
            'data' => [
                'saved_count' => 1,
                'readback_count' => 1,
                'readback_verified' => true,
                'status' => 'success',
            ],
        ]);
        self::assertSame('success', $completed['status']);
        self::assertSame('success', $store->read($task['task_id'])['status']);
    }

    public function testUnsupportedStatusDriverFailsInsteadOfSilentlyUsingLocalFiles(): void
    {
        putenv('SUXI_MANUAL_FETCH_TASK_STATUS_DRIVER=redis');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsupported');
        new ManualOnlineFetchTaskService(null, $this->taskRoot);
    }

    public function testCleanupCommandIsRegisteredForScheduledOperations(): void
    {
        $console = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'console.php';
        self::assertSame(
            CleanupManualFetchTasks::class,
            $console['commands']['online-data:cleanup-manual-fetch-tasks'] ?? null
        );
    }

    /** @return array<string, mixed> */
    private function createTask(ManualOnlineFetchTaskService $service, int $hotelId): array
    {
        $task = $service->createTask('ctrip', $hotelId, '2026-07-16', '2026-07-16', [], [
            'user_id' => 5,
            'api_url' => 'http://127.0.0.1:8080/api/online-data/fetch-ctrip',
            'authorization' => 'Bearer retention-test-token',
        ]);
        self::assertNotSame([], $task);
        return $task;
    }

    /** @param array<string, mixed> $task */
    private function ageTask(array $task, int $timestamp, bool $terminal = true): void
    {
        $statusPath = (string)$task['status_file'];
        $status = json_decode((string)file_get_contents($statusPath), true, 512, JSON_THROW_ON_ERROR);
        $oldTime = date('Y-m-d H:i:s', $timestamp);
        $status['created_at'] = $oldTime;
        $status['updated_at'] = $oldTime;
        if ($terminal) {
            $status['finished_at'] = $oldTime;
        } else {
            unset($status['finished_at']);
        }
        file_put_contents(
            $statusPath,
            json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
        );
        touch($statusPath, $timestamp);
        touch(dirname($statusPath), $timestamp);
    }

    private function restoreEnv(string $name, string|false $value): void
    {
        putenv($value === false ? $name : $name . '=' . $value);
    }

    private function createWindowsJunction(string $junctionPath, string $targetPath): void
    {
        $pipes = [];
        $command = 'cmd.exe /d /c mklink /J '
            . escapeshellarg($junctionPath) . ' '
            . escapeshellarg($targetPath);
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), trim((string)$stdout . PHP_EOL . (string)$stderr));
        self::assertDirectoryExists($junctionPath);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $item) {
            if ($item->isDir() && !$item->isLink()) {
                $this->removeTree($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }
}

final class InMemoryManualOnlineFetchTaskStatusStore implements ManualOnlineFetchTaskStatusStore
{
    /** @var array<string, array<string, mixed>> */
    private array $rows = [];

    public function read(string $taskId): array
    {
        return $this->rows[$taskId] ?? [];
    }

    public function write(string $taskId, array $status): bool
    {
        $this->rows[$taskId] = $status;
        return true;
    }

    public function update(string $taskId, callable $mutator): array
    {
        $current = $this->read($taskId);
        if ($current === []) {
            return [];
        }
        $next = $mutator($current);
        if (!is_array($next) || (string)($next['task_id'] ?? '') !== $taskId) {
            return [];
        }
        $this->rows[$taskId] = $next;
        return $next;
    }

    public function delete(string $taskId): void
    {
        unset($this->rows[$taskId]);
    }

    public function locator(string $taskId): string
    {
        return 'memory://manual-fetch/' . $taskId;
    }

    public function cleanupExpired(
        int $retentionSeconds,
        int $staleSeconds,
        int $orphanSeconds,
        int $now,
        bool $dryRun = false
    ): array
    {
        return [
            'scanned' => count($this->rows),
            'timed_out' => 0,
            'orphaned' => 0,
            'expired' => 0,
            'removed' => 0,
            'kept' => count($this->rows),
            'errors' => 0,
        ];
    }
}
