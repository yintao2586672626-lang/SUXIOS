<?php
declare(strict_types=1);

namespace Tests;

use app\service\DatabaseManualOnlineFetchTaskStatusStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class DatabaseManualOnlineFetchTaskStatusStoreTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $databasePath = '';
    private string $taskRoot;
    private string $taskId;
    private ?DatabaseManualOnlineFetchTaskStatusStore $store = null;

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'suxi_manual_fetch_db_store_' . getmypid() . '.sqlite';
        $database = self::$originalDatabaseConfig;
        $database['default'] = 'sqlite';
        $database['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$databasePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($database, 'database');
        Db::execute('CREATE TABLE manual_online_fetch_task_statuses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id VARCHAR(96) NOT NULL UNIQUE,
            hotel_id INTEGER NOT NULL,
            user_id INTEGER NULL,
            platform VARCHAR(20) NOT NULL,
            task_kind VARCHAR(60) NOT NULL,
            status VARCHAR(40) NOT NULL,
            stage VARCHAR(60) NOT NULL,
            status_json TEXT NOT NULL,
            started_at DATETIME NULL,
            finished_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        )');
    }

    public static function tearDownAfterClass(): void
    {
        try {
            Db::connect('sqlite')->close();
        } catch (\Throwable) {
        }
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$databasePath);
    }

    protected function setUp(): void
    {
        $this->taskRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'suxi_manual_fetch_db_store_' . getmypid() . '_' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($this->taskRoot, 0775, true));
        $this->taskId = 'manual_ctrip_fetch_7_20260717000000_' . bin2hex(random_bytes(4));
        $this->store = new DatabaseManualOnlineFetchTaskStatusStore($this->taskRoot);
    }

    protected function tearDown(): void
    {
        $this->store?->delete($this->taskId);
        if (is_dir($this->taskRoot)) {
            @rmdir($this->taskRoot);
        }
    }

    public function testDatabaseStorePersistsAndAtomicallyUpdatesSafeTaskStatus(): void
    {
        $createdAt = '2026-07-17 00:00:00';
        $status = [
            'task_id' => $this->taskId,
            'hotel_id' => 7,
            'user_id' => 5,
            'platform' => 'ctrip',
            'task_kind' => 'ctrip_traffic',
            'status' => 'queued',
            'stage' => 'created',
            'message' => '后台任务已创建，等待启动',
            'progress_percent' => 5,
            'done' => false,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];

        self::assertTrue($this->store->write($this->taskId, $status));
        self::assertSame('database://manual-fetch/' . $this->taskId, $this->store->locator($this->taskId));
        self::assertSame($status, $this->store->read($this->taskId));

        $updated = $this->store->update($this->taskId, static function (array $current): array {
            $current['status'] = 'running';
            $current['stage'] = 'requesting';
            $current['progress_percent'] = 30;
            $current['updated_at'] = '2026-07-17 00:01:00';
            return $current;
        });

        self::assertSame('running', $updated['status']);
        self::assertSame('requesting', $this->store->read($this->taskId)['stage']);
    }

    public function testCleanupTransitionsAnActuallyStaleActiveTaskUnderTheRowLock(): void
    {
        $now = strtotime('2026-07-17 03:00:00');
        self::assertIsInt($now);
        self::assertTrue($this->store->write($this->taskId, [
            'task_id' => $this->taskId,
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'task_kind' => 'ctrip_traffic',
            'status' => 'running',
            'stage' => 'requesting',
            'progress_percent' => 30,
            'done' => false,
            'created_at' => '2026-07-17 00:00:00',
            'updated_at' => '2026-07-17 00:00:00',
        ]));

        $result = $this->store->cleanupExpired(86400, 3600, 86400, $now, false);
        $status = $this->store->read($this->taskId);

        self::assertSame(1, $result['timed_out']);
        self::assertSame(0, $result['errors']);
        self::assertSame('failed', $status['status']);
        self::assertSame('timeout', $status['stage']);
        self::assertTrue($status['done']);
    }

    public function testLockedTimeoutRecheckCannotOverwriteAWorkerTerminalResult(): void
    {
        $now = strtotime('2026-07-17 03:00:00');
        self::assertIsInt($now);
        self::assertTrue($this->store->write($this->taskId, [
            'task_id' => $this->taskId,
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'task_kind' => 'ctrip_traffic',
            'status' => 'running',
            'stage' => 'requesting',
            'done' => false,
            'created_at' => '2026-07-17 00:00:00',
            'updated_at' => '2026-07-17 00:00:00',
        ]));

        // Simulate the worker winning after cleanup took its initial stale snapshot.
        self::assertTrue($this->store->write($this->taskId, [
            'task_id' => $this->taskId,
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'task_kind' => 'ctrip_traffic',
            'status' => 'success',
            'stage' => 'completed',
            'done' => true,
            'created_at' => '2026-07-17 00:00:00',
            'finished_at' => '2026-07-17 02:59:59',
            'updated_at' => '2026-07-17 02:59:59',
        ]));

        $method = new \ReflectionMethod($this->store, 'transitionStaleTaskToTimeout');
        $method->setAccessible(true);
        self::assertFalse($method->invoke($this->store, $this->taskId, 3600, $now));

        $status = $this->store->read($this->taskId);
        self::assertSame('success', $status['status']);
        self::assertSame('completed', $status['stage']);
        self::assertSame('2026-07-17 02:59:59', $status['finished_at']);
    }

    public function testDatabaseStoreRejectsSensitiveLaunchEnvelopeFields(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot persist sensitive launch field');
        $this->store->write($this->taskId, [
            'task_id' => $this->taskId,
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'task_kind' => 'ctrip',
            'status' => 'queued',
            'stage' => 'created',
            'created_at' => '2026-07-17 00:00:00',
            'updated_at' => '2026-07-17 00:00:00',
            'authorization' => 'Bearer must-not-persist',
        ]);
    }
}
