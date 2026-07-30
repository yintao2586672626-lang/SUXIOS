<?php
declare(strict_types=1);

namespace Tests;

use app\service\FixedWindowRateLimiter;
use PHPUnit\Framework\TestCase;

final class FixedWindowRateLimiterTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirectories = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->tempDirectories) as $directory) {
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
                if (is_dir($path)) {
                    foreach (glob($path . DIRECTORY_SEPARATOR . '*') ?: [] as $nestedPath) {
                        @unlink($nestedPath);
                    }
                    @rmdir($path);
                    continue;
                }
                @unlink($path);
            }
            @rmdir($directory);
        }
        $this->tempDirectories = [];
    }

    public function testConsumesExactlyTheConfiguredQuotaWithinOneWindow(): void
    {
        $store = [];
        $ttls = [];
        $now = 125;
        $service = $this->service($store, $ttls, $now, $this->tempDirectory() . '/locks');

        self::assertSame(
            ['allowed' => true, 'count' => 1, 'limit' => 2, 'window' => 60, 'retry_after' => 55],
            $service->consume('authenticated_window', 2, 60)
        );
        self::assertTrue($service->consume('authenticated_window', 2, 60)['allowed']);
        $denied = $service->consume('authenticated_window', 2, 60);

        self::assertFalse($denied['allowed']);
        self::assertSame(2, $denied['count']);
        self::assertSame(['authenticated_window_2' => 2], $store);
        self::assertSame(['authenticated_window_2' => 60], $ttls);
    }

    public function testNewWindowStartsWithAFreshCounter(): void
    {
        $store = [];
        $ttls = [];
        $now = 125;
        $service = $this->service($store, $ttls, $now, $this->tempDirectory() . '/locks');

        self::assertTrue($service->consume('public_endpoint', 1, 60)['allowed']);
        self::assertFalse($service->consume('public_endpoint', 1, 60)['allowed']);

        $now = 180;
        $fresh = $service->consume('public_endpoint', 1, 60);

        self::assertTrue($fresh['allowed']);
        self::assertSame(1, $fresh['count']);
        self::assertSame(1, $store['public_endpoint_3']);
    }

    public function testUnavailableLockDirectoryFailsClosedBeforeStoreAccess(): void
    {
        $directory = $this->tempDirectory();
        $blockedPath = $directory . DIRECTORY_SEPARATOR . 'not-a-directory';
        file_put_contents($blockedPath, 'blocked');
        $storeRead = false;
        $service = new FixedWindowRateLimiter(
            static function (string $_key) use (&$storeRead): ?int {
                $storeRead = true;
                return null;
            },
            static fn(string $_key, int $_value, int $_ttl): bool => true,
            static fn(): int => 125,
            $blockedPath
        );

        try {
            $service->consume('public_endpoint', 1, 60);
            self::fail('Expected an unavailable lock directory to fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('lock directory is unavailable', $exception->getMessage());
        }
        self::assertFalse($storeRead);
    }

    public function testCounterWriteFailureFailsClosed(): void
    {
        $service = new FixedWindowRateLimiter(
            static fn(string $_key): ?int => null,
            static fn(string $_key, int $_value, int $_ttl): bool => false,
            static fn(): int => 125,
            $this->tempDirectory() . '/locks'
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('counter write failed');
        $service->consume('public_endpoint', 1, 60);
    }

    public function testConcurrentWorkersDoNotLoseCounterIncrements(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is unavailable.');
        }

        $directory = $this->tempDirectory();
        $counterPath = $directory . DIRECTORY_SEPARATOR . 'counter.txt';
        $lockDirectory = $directory . DIRECTORY_SEPARATOR . 'locks';
        $workerPath = __DIR__ . '/Support/fixed_window_rate_limiter_worker.php';
        $workerCount = 6;
        $attemptsPerWorker = 20;
        $processes = [];

        for ($worker = 0; $worker < $workerCount; $worker++) {
            $pipes = [];
            $process = proc_open(
                [
                    PHP_BINARY,
                    $workerPath,
                    $counterPath,
                    $lockDirectory,
                    'concurrent_public_endpoint',
                    '125',
                    (string)$attemptsPerWorker,
                ],
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                null,
                null,
                ['bypass_shell' => true]
            );
            self::assertIsResource($process);
            fclose($pipes[0]);
            $processes[] = [$process, $pipes];
        }

        foreach ($processes as [$process, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
            self::assertSame(0, $exitCode, trim($stdout . "\n" . $stderr));
        }

        self::assertFileExists($counterPath);
        self::assertSame(
            $workerCount * $attemptsPerWorker,
            (int)trim((string)file_get_contents($counterPath))
        );
    }

    /**
     * @param array<string, int> $store
     * @param array<string, int> $ttls
     */
    private function service(array &$store, array &$ttls, int &$now, string $lockDirectory): FixedWindowRateLimiter
    {
        return new FixedWindowRateLimiter(
            static function (string $key) use (&$store): ?int {
                return $store[$key] ?? null;
            },
            static function (string $key, int $value, int $ttl) use (&$store, &$ttls): bool {
                $store[$key] = $value;
                $ttls[$key] = $ttl;
                return true;
            },
            static function () use (&$now): int {
                return $now;
            },
            $lockDirectory
        );
    }

    private function tempDirectory(): string
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'suxi_fixed_window_test_' . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create fixed-window rate-limit test directory.');
        }
        $this->tempDirectories[] = $directory;

        return $directory;
    }
}
