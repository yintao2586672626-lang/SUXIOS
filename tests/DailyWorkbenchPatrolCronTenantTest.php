<?php
declare(strict_types=1);

namespace Tests;

use app\controller\OnlineData;
use app\controller\ota\SyncController;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;
use think\facade\Env;
use think\Request;
use think\Response;

final class DailyWorkbenchPatrolCronTenantTest extends TestCase
{
    private const CRON_TOKEN = 'tenant-cron-test-token';
    private const TARGET_DATE = '2099-12-29';

    private static App $app;
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';
    private static mixed $originalCronToken = null;

    private string $baseDir = '';
    private string $latestPath = '';
    private bool $baseDirExisted = false;
    private bool $latestExisted = false;
    private string $latestContents = '';

    /** @var array<int, string> */
    private array $createdRunIds = [];

    public static function setUpBeforeClass(): void
    {
        self::$app = new App(dirname(__DIR__));
        self::$app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$originalCronToken = Env::get('CRON_TOKEN');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'daily_workbench_patrol_tenant_' . getmypid() . '.sqlite';

        $config = self::$originalDatabaseConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$sqlitePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($config, 'database');
        Env::set('CRON_TOKEN', self::CRON_TOKEN);
    }

    public static function tearDownAfterClass(): void
    {
        try {
            Db::connect('sqlite')->close();
        } catch (\Throwable) {
        }
        Config::set(self::$originalDatabaseConfig, 'database');
        Env::set('CRON_TOKEN', self::$originalCronToken);
        Db::connect(null, true);
        @unlink(self::$sqlitePath);
    }

    protected function setUp(): void
    {
        try {
            Db::connect('sqlite')->close();
        } catch (\Throwable) {
        }
        @unlink(self::$sqlitePath);
        Db::connect(null, true);

        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name VARCHAR(100), status INTEGER NOT NULL, create_time DATETIME, update_time DATETIME)');
        Db::execute('CREATE TABLE operation_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, user_id INTEGER, hotel_id INTEGER, module VARCHAR(50), action VARCHAR(80), description VARCHAR(500), error_info TEXT, extra_data TEXT, ip VARCHAR(64), user_agent VARCHAR(255), create_time DATETIME)');
        Db::name('hotels')->insertAll([
            ['id' => 101, 'tenant_id' => 10, 'name' => 'Tenant A Hotel', 'status' => 1],
            ['id' => 202, 'tenant_id' => 20, 'name' => 'Tenant B Hotel', 'status' => 1],
        ]);
        cache('online_data_auto_fetch_status_101', null);
        cache('online_data_auto_fetch_status_202', null);

        $this->clearRateLimitCache();
        $this->baseDir = rtrim(runtime_path(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'phase2_daily_workbench_patrol';
        $this->latestPath = $this->baseDir . DIRECTORY_SEPARATOR . 'latest.json';
        $this->baseDirExisted = is_dir($this->baseDir);
        $this->latestExisted = is_file($this->latestPath);
        $this->latestContents = $this->latestExisted
            ? (string)file_get_contents($this->latestPath)
            : '';
        $this->createdRunIds = [];
    }

    protected function tearDown(): void
    {
        $targetDir = $this->baseDir . DIRECTORY_SEPARATOR . self::TARGET_DATE;
        foreach ($this->createdRunIds as $runId) {
            $path = $targetDir . DIRECTORY_SEPARATOR . $runId . '.json';
            if (is_file($path)) {
                unlink($path);
            }
        }
        if (is_dir($targetDir) && (glob($targetDir . DIRECTORY_SEPARATOR . '*') ?: []) === []) {
            rmdir($targetDir);
        }
        if ($this->latestExisted) {
            if (!is_dir($this->baseDir)) {
                mkdir($this->baseDir, 0775, true);
            }
            file_put_contents($this->latestPath, $this->latestContents, LOCK_EX);
        } elseif (is_file($this->latestPath)) {
            unlink($this->latestPath);
        }
        if (!$this->baseDirExisted
            && is_dir($this->baseDir)
            && (glob($this->baseDir . DIRECTORY_SEPARATOR . '*') ?: []) === []
        ) {
            rmdir($this->baseDir);
        }
        $this->clearRateLimitCache();
    }

    public function testValidCronTokenCreatesTenantConsistentSnapshotsAndAuditsForTwoHotels(): void
    {
        $response = $this->invokeCron(self::CRON_TOKEN);
        self::assertSame(200, $response->getCode());
        $payload = $this->payload($response);
        self::assertSame(200, $payload['code']);
        self::assertSame(2, $payload['data']['snapshot_count']);

        $snapshots = $payload['data']['snapshots'];
        $this->createdRunIds = array_values(array_map(
            static fn(array $snapshot): string => (string)$snapshot['run_id'],
            $snapshots
        ));
        $pairs = array_map(
            static fn(array $snapshot): array => [(int)$snapshot['tenant_id'], (int)$snapshot['hotel_id']],
            $snapshots
        );
        self::assertSame([[10, 101], [20, 202]], $pairs);
        self::assertNotEmpty($this->createdRunIds[0]);
        self::assertNotEmpty($this->createdRunIds[1]);

        $audits = Db::name('operation_logs')
            ->where('action', 'daily_workbench_patrol_cron')
            ->order('id', 'asc')
            ->field('tenant_id,hotel_id')
            ->select()
            ->toArray();
        self::assertSame([[10, 101], [20, 202]], array_map(
            static fn(array $row): array => [(int)$row['tenant_id'], (int)$row['hotel_id']],
            $audits
        ));
    }

    public function testInvalidCronTokenKeepsUnauthorizedResponseAndWritesGlobalAudit(): void
    {
        $response = $this->invokeCron('wrong-token');
        self::assertSame(401, $response->getCode());
        self::assertSame(401, $this->payload($response)['code']);

        $audit = Db::name('operation_logs')
            ->where('action', 'daily_workbench_patrol_cron_public_failure')
            ->find();
        self::assertNotNull($audit);
        self::assertNull($audit['tenant_id']);
        self::assertNull($audit['hotel_id']);
    }

    public function testAutoFetchCronValidTokenCanEnumerateTwoTenantHotelsWithoutAuthenticatedUser(): void
    {
        $response = $this->invokeAutoFetchCron(self::CRON_TOKEN);
        self::assertSame(200, $response->getCode());
        $payload = $this->payload($response);
        self::assertSame(200, $payload['code']);
        self::assertSame(0, $payload['executed']);
        self::assertSame([], $payload['results']);
    }

    public function testAutoFetchCronInvalidTokenKeepsUnauthorizedResponseAndGlobalAudit(): void
    {
        $response = $this->invokeAutoFetchCron('wrong-token');
        self::assertSame(401, $response->getCode());
        $audit = Db::name('operation_logs')->where('action', 'cron_trigger_public_failure')->find();
        self::assertNotNull($audit);
        self::assertNull($audit['tenant_id']);
    }

    private function invokeCron(string $token): Response
    {
        $request = new class extends Request {
            public function isCli(): bool
            {
                return false;
            }
        };
        $request->withServer(['REMOTE_ADDR' => '127.0.0.1'])
            ->withGet(['target_date' => self::TARGET_DATE, 'limit' => 30])
            ->withHeader(['Accept' => 'application/json', 'X-Cron-Token' => $token]);
        self::$app->instance('request', $request);

        return (new OnlineData(self::$app))->dailyWorkbenchPatrolCron();
    }

    private function invokeAutoFetchCron(string $token): Response
    {
        $request = new class extends Request {
            public function isCli(): bool
            {
                return false;
            }
        };
        $request->withServer(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader(['Accept' => 'application/json', 'X-Cron-Token' => $token]);
        self::$app->instance('request', $request);

        return (new SyncController(self::$app))->cronTrigger();
    }

    /** @return array<string, mixed> */
    private function payload(Response $response): array
    {
        return json_decode((string)$response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function clearRateLimitCache(): void
    {
        $bucket = (int)floor(time() / 60);
        $ipHash = substr(sha1('127.0.0.1'), 0, 16);
        cache('public_endpoint_rate_daily_workbench_patrol_cron_' . $ipHash . '_' . $bucket, null);
        cache('public_endpoint_rate_cron_trigger_' . $ipHash . '_' . $bucket, null);
    }
}
