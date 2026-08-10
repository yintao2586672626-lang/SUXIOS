<?php
declare(strict_types=1);

namespace Tests;

use app\service\OnlineDataAutoFetchStatusStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OnlineDataAutoFetchStatusStoreTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirectories = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->tempDirectories) as $directory) {
            $this->removeDirectory($directory);
        }
        $this->tempDirectories = [];
    }

    public function testMutationPreservesUnrelatedFieldsAndSkipsUnchangedWrite(): void
    {
        $status = ['enabled' => true, 'marker' => 'preserved'];
        $writes = 0;
        $store = $this->store($status, $writes, $this->tempDirectory() . '/locks');

        $updated = $store->mutate(80, static function (array $current): array {
            $current['canonical_daily_analysis_authorizations']['ctrip'] = ['plan_id' => 'ctrip-plan'];
            return $current;
        });
        $same = $store->mutate(80, static fn(array $current): array => $current);

        self::assertSame('preserved', $updated['marker']);
        self::assertSame('ctrip-plan', $updated['canonical_daily_analysis_authorizations']['ctrip']['plan_id']);
        self::assertSame($updated, $same);
        self::assertSame(1, $writes);
    }

    public function testWriteFailureFailsClosed(): void
    {
        $store = new OnlineDataAutoFetchStatusStore(
            static fn(int $_hotelId): array => ['enabled' => true],
            static fn(int $_hotelId, array $_status, int $_ttl): bool => false,
            $this->tempDirectory() . '/locks'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('online_data_auto_fetch_status_write_failed');
        $store->mutate(80, static function (array $status): array {
            $status['enabled'] = false;
            return $status;
        });
    }

    public function testUnavailableLockDirectoryFailsBeforeCacheRead(): void
    {
        $directory = $this->tempDirectory();
        $blockedPath = $directory . DIRECTORY_SEPARATOR . 'blocked';
        file_put_contents($blockedPath, 'not-a-directory');
        $read = false;
        $store = new OnlineDataAutoFetchStatusStore(
            static function (int $_hotelId) use (&$read): array {
                $read = true;
                return [];
            },
            static fn(int $_hotelId, array $_status, int $_ttl): bool => true,
            $blockedPath
        );

        try {
            $store->mutate(80, static fn(array $status): array => $status);
            self::fail('An unavailable lock directory must fail closed.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'online_data_auto_fetch_status_lock_directory_unavailable',
                $exception->getMessage()
            );
        }
        self::assertFalse($read);
    }

    public function testConcurrentHotelMutationsDoNotLoseEitherWorkerUpdates(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is unavailable.');
        }

        $directory = $this->tempDirectory();
        $statusPath = $directory . DIRECTORY_SEPARATOR . 'status.json';
        $lockDirectory = $directory . DIRECTORY_SEPARATOR . 'locks';
        $workerPath = __DIR__ . '/Support/online_data_auto_fetch_status_store_worker.php';
        file_put_contents(
            $statusPath,
            json_encode(['enabled' => true, 'marker' => 'preserved'], JSON_THROW_ON_ERROR)
        );

        $attempts = 30;
        $processes = [];
        foreach (['ctrip', 'meituan'] as $platform) {
            $pipes = [];
            $process = proc_open(
                [
                    PHP_BINARY,
                    $workerPath,
                    $statusPath,
                    $lockDirectory,
                    '80',
                    $platform,
                    (string)$attempts,
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
            self::assertSame(0, proc_close($process), trim($stdout . "\n" . $stderr));
        }

        $status = json_decode((string)file_get_contents($statusPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('preserved', $status['marker']);
        self::assertSame($attempts, $status['worker_updates']['ctrip']);
        self::assertSame($attempts, $status['worker_updates']['meituan']);
    }

    public function testConcurrentPlatformProvisioningPreservesBothAuthorizationGrants(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is unavailable.');
        }

        $directory = $this->tempDirectory();
        $statusPath = $directory . DIRECTORY_SEPARATOR . 'authorization-status.json';
        $lockDirectory = $directory . DIRECTORY_SEPARATOR . 'locks';
        $workerPath = __DIR__ . '/Support/canonical_ota_scheduled_analysis_authorization_worker.php';
        file_put_contents($statusPath, json_encode(['enabled' => true], JSON_THROW_ON_ERROR));

        $processes = [];
        foreach (['ctrip', 'meituan'] as $platform) {
            $pipes = [];
            $process = proc_open(
                [
                    PHP_BINARY,
                    $workerPath,
                    $statusPath,
                    $lockDirectory,
                    $platform,
                    'hotel80_' . $platform . '_daily_goal_concurrency_v1',
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
            self::assertSame(0, proc_close($process), trim($stdout . "\n" . $stderr));
        }

        $status = json_decode((string)file_get_contents($statusPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(
            'ctrip',
            $status['canonical_daily_analysis_authorizations']['ctrip']['platform']
        );
        self::assertSame(
            'meituan',
            $status['canonical_daily_analysis_authorizations']['meituan']['platform']
        );
        self::assertSame(
            $status['canonical_daily_analysis_authorizations']['ctrip'],
            $status['canonical_daily_analysis_authorization']
        );
    }

    public function testPerHotelStatusWritersUseTheAtomicStoreBoundary(): void
    {
        $root = dirname(__DIR__);
        $controller = (string)file_get_contents($root . '/app/controller/concern/AutoFetchConcern.php');
        $scheduler = (string)file_get_contents($root . '/app/command/AutoFetchOnlineData.php');
        $background = (string)file_get_contents($root . '/app/command/AutoFetchOnlineDataOnce.php');
        $provisioning = (string)file_get_contents(
            $root . '/app/service/CanonicalOtaScheduledAnalysisAuthorizationProvisioningService.php'
        );

        self::assertGreaterThanOrEqual(7, substr_count($controller, 'new OnlineDataAutoFetchStatusStore()'));
        self::assertGreaterThanOrEqual(2, substr_count($scheduler, 'new OnlineDataAutoFetchStatusStore()'));
        self::assertStringContainsString('new OnlineDataAutoFetchStatusStore()', $background);
        self::assertStringContainsString('$store->mutate($hotelId, function', $provisioning);
        self::assertStringNotContainsString('cache($statusKey, $status, 86400 * 30)', $controller);
        self::assertStringNotContainsString('Cache::set($statusKey, $status, 86400 * 30)', $scheduler);
        self::assertStringNotContainsString('Cache::set($statusKey, $status, 86400 * 30)', $background);
    }

    private function store(array &$status, int &$writes, string $lockDirectory): OnlineDataAutoFetchStatusStore
    {
        return new OnlineDataAutoFetchStatusStore(
            static function (int $_hotelId) use (&$status): array {
                return $status;
            },
            static function (int $_hotelId, array $updated, int $_ttl) use (&$status, &$writes): bool {
                $status = $updated;
                $writes++;
                return true;
            },
            $lockDirectory
        );
    }

    private function tempDirectory(): string
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'suxi_auto_fetch_status_test_' . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create auto-fetch status test directory.');
        }
        $this->tempDirectories[] = $directory;
        return $directory;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }
}
