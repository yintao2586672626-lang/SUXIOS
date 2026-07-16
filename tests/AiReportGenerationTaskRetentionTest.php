<?php
declare(strict_types=1);

namespace Tests;

use app\service\AiReportGenerationTaskService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class AiReportGenerationTaskRetentionTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $databasePath = '';
    private string $terminalTaskId = 'airpt_20260701000000_aaaaaaaaaaaaaaaaaaaaaaaa';
    private string $runningTaskId = 'airpt_20260701000000_bbbbbbbbbbbbbbbbbbbbbbbb';
    private string $orphanTaskId = 'airpt_20260701000000_cccccccccccccccccccccccc';
    private string $queuedTaskId = 'airpt_20260701000000_dddddddddddddddddddddddd';
    /** @var array<int, string> */
    private array $logPaths = [];

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'suxi_ai_report_retention_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.sqlite';
        $database = self::$originalDatabaseConfig;
        $database['default'] = 'sqlite';
        $database['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$databasePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($database, 'database');
        Db::connect(null, true);
        Db::execute('CREATE TABLE ai_report_generation_tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id VARCHAR(96) NOT NULL UNIQUE,
            status VARCHAR(40) NOT NULL,
            stage VARCHAR(40) NOT NULL,
            progress_percent INTEGER NOT NULL DEFAULT 0,
            model_status VARCHAR(40) NOT NULL DEFAULT \'\',
            error_code VARCHAR(80) NOT NULL DEFAULT \'\',
            error_message VARCHAR(500) NOT NULL DEFAULT \'\',
            active_dedupe_key VARCHAR(64) NULL,
            lease_expires_at DATETIME NULL,
            finished_at DATETIME NULL,
            updated_at DATETIME NOT NULL
        )');
        Db::execute('CREATE TABLE ai_report_input_cache (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            input_fingerprint VARCHAR(64) NOT NULL UNIQUE,
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
        Db::name('ai_report_generation_tasks')->delete(true);
        Db::name('ai_report_input_cache')->delete(true);
        $old = date('Y-m-d H:i:s', time() - (40 * 86400));
        Db::name('ai_report_generation_tasks')->insert([
            'task_id' => $this->terminalTaskId,
            'status' => 'succeeded',
            'stage' => 'completed',
            'progress_percent' => 100,
            'updated_at' => $old,
        ]);
        Db::name('ai_report_generation_tasks')->insert([
            'task_id' => $this->runningTaskId,
            'status' => 'running',
            'stage' => 'generating',
            'progress_percent' => 30,
            'active_dedupe_key' => str_repeat('b', 64),
            'lease_expires_at' => date('Y-m-d H:i:s', time() - 3600),
            'updated_at' => $old,
        ]);
        Db::name('ai_report_generation_tasks')->insert([
            'task_id' => $this->queuedTaskId,
            'status' => 'queued',
            'stage' => 'launching',
            'progress_percent' => 10,
            'active_dedupe_key' => str_repeat('d', 64),
            'lease_expires_at' => date('Y-m-d H:i:s', time() - 3600),
            'updated_at' => $old,
        ]);
        Db::name('ai_report_input_cache')->insert([
            'input_fingerprint' => str_repeat('c', 64),
            'updated_at' => $old,
        ]);

        $runtime = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'ai_report_tasks';
        if (!is_dir($runtime)) {
            self::assertTrue(mkdir($runtime, 0775, true));
        }
        $this->logPaths = [
            $runtime . DIRECTORY_SEPARATOR . $this->terminalTaskId . '.stdout.log',
            $runtime . DIRECTORY_SEPARATOR . $this->orphanTaskId . '.log',
        ];
        foreach ($this->logPaths as $path) {
            self::assertNotFalse(file_put_contents($path, 'test-only retention artifact'));
            self::assertTrue(touch($path, time() - (40 * 86400)));
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->logPaths as $path) {
            @unlink($path);
        }
    }

    public function testDryRunIsReadOnlyAndActualCleanupIsBoundedToExpiredMetadata(): void
    {
        $service = new AiReportGenerationTaskService();

        $dryRun = $service->cleanupExpiredRecords(30, 30, true, 100);

        self::assertTrue($dryRun['dry_run']);
        self::assertSame(2, $dryRun['eligible_expired_active_tasks']);
        self::assertSame(1, $dryRun['eligible_tasks']);
        self::assertSame(1, $dryRun['eligible_cache_rows']);
        self::assertSame(2, $dryRun['eligible_log_files']);
        self::assertSame(0, $dryRun['removed_tasks']);
        self::assertSame(0, $dryRun['removed_cache_rows']);
        self::assertSame(0, $dryRun['removed_log_files']);
        self::assertSame('running', Db::name('ai_report_generation_tasks')
            ->where('task_id', $this->runningTaskId)->value('status'));
        self::assertSame('queued', Db::name('ai_report_generation_tasks')
            ->where('task_id', $this->queuedTaskId)->value('status'));
        self::assertSame(3, (int)Db::name('ai_report_generation_tasks')->count());
        self::assertSame(1, (int)Db::name('ai_report_input_cache')->count());
        foreach ($this->logPaths as $path) {
            self::assertFileExists($path);
        }

        $cleanup = $service->cleanupExpiredRecords(30, 30, false, 100);

        self::assertFalse($cleanup['dry_run']);
        self::assertSame(2, $cleanup['eligible_expired_active_tasks']);
        self::assertSame(1, $cleanup['removed_tasks']);
        self::assertSame(1, $cleanup['removed_cache_rows']);
        self::assertSame(2, $cleanup['removed_log_files']);
        self::assertSame('failed', Db::name('ai_report_generation_tasks')
            ->where('task_id', $this->runningTaskId)->value('status'));
        self::assertSame('worker_lease_expired', Db::name('ai_report_generation_tasks')
            ->where('task_id', $this->runningTaskId)->value('error_code'));
        self::assertSame('failed', Db::name('ai_report_generation_tasks')
            ->where('task_id', $this->queuedTaskId)->value('status'));
        self::assertSame('worker_lease_expired', Db::name('ai_report_generation_tasks')
            ->where('task_id', $this->queuedTaskId)->value('error_code'));
        self::assertSame(0, (int)Db::name('ai_report_input_cache')->count());
        foreach ($this->logPaths as $path) {
            self::assertFileDoesNotExist($path);
        }
    }
}
