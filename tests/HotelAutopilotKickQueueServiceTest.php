<?php
declare(strict_types=1);

namespace Tests;

use app\service\HotelAutopilotKickQueueService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class HotelAutopilotKickQueueServiceTest extends TestCase
{
    private static App $app;
    private static array $originalDatabaseConfig = [];
    private static string $connection = '';
    private static string $databasePath = '';

    public static function setUpBeforeClass(): void
    {
        self::$app = new App(dirname(__DIR__));
        self::$app->initialize();
        self::$connection = 'hotel_autopilot_kick_' . getmypid() . '_' . bin2hex(random_bytes(4));
        self::$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . self::$connection . '.sqlite';
        self::$originalDatabaseConfig = Config::get('database');
        $database = self::$originalDatabaseConfig;
        $database['default'] = self::$connection;
        $database['connections'][self::$connection] = [
            'type' => 'sqlite',
            'database' => self::$databasePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($database, 'database');
        Db::connect(null, true);
        self::createSchema();
    }

    public static function tearDownAfterClass(): void
    {
        try {
            Db::connect()->close();
        } catch (\Throwable) {
        }
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$databasePath);
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            HotelAutopilotKickQueueService::TABLE,
            'ota_local_collector_tasks',
            'ota_local_collector_account_hotels',
            'ota_local_collector_accounts',
            'hotels',
            'users',
        ] as $table) {
            Db::name($table)->delete(true);
        }
        $this->seedVerifiedLogin();
    }

    public function testMigrationDefinesSecretFreeIdempotentDueQueue(): void
    {
        $migration = (string)file_get_contents(
            dirname(__DIR__) . '/database/migrations/20260812_zzzzz_create_hotel_autopilot_kick_queue.sql'
        );
        self::assertStringContainsString('hotel_autopilot_kick_queue', $migration);
        self::assertStringContainsString('uq_hotel_autopilot_kick_source', $migration);
        self::assertStringContainsString('idx_hotel_autopilot_kick_due', $migration);
        self::assertStringNotContainsString('cookie', strtolower($migration));
        self::assertStringNotContainsString('raw_data', strtolower($migration));
        self::assertStringNotContainsString('config_json', strtolower($migration));
        self::assertStringNotContainsString('profile_path', strtolower($migration));
    }

    public function testBackgroundCommandDrainsQueueBeforeTheGeneralHotelScan(): void
    {
        $command = (string)file_get_contents(
            dirname(__DIR__) . '/app/command/ReconcileHotelAutopilotLifecycle.php'
        );
        $drainAt = strpos($command, 'HotelAutopilotKickQueueService())->consumeDue(3)');
        $scanAt = strpos($command, '$service->reconcileDue(');
        self::assertIsInt($drainAt);
        self::assertIsInt($scanAt);
        self::assertLessThan($scanAt, $drainAt);
        self::assertStringContainsString("'kick_queue' => \$kickQueue", $command);
    }

    public function testVerifiedLoginEnqueueIsExactReadbackVerifiedAndIdempotent(): void
    {
        $service = $this->service();
        $first = $service->enqueueVerifiedLogin(12, 101, 7, 9001);
        $second = $service->enqueueVerifiedLogin(12, 101, 7, 9001);

        self::assertSame('queued', $first['status']);
        self::assertSame('pending', $first['queue_status']);
        self::assertTrue($first['readback_verified']);
        self::assertSame($first['queue_id'], $second['queue_id']);
        self::assertSame($first['request_digest'], $second['request_digest']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $first['request_digest']);
        self::assertSame(1, Db::name(HotelAutopilotKickQueueService::TABLE)->count());
        $serialized = json_encode($first, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('result_summary_json', $serialized);
        self::assertStringNotContainsString('account_id', $serialized);
        self::assertStringNotContainsString('device_id', $serialized);
    }

    public function testCrossScopeOrUnverifiedLoginCannotEnterQueue(): void
    {
        $service = $this->service();
        try {
            $service->enqueueVerifiedLogin(12, 102, 7, 9001);
            self::fail('Cross-hotel login evidence must fail closed.');
        } catch (RuntimeException $error) {
            self::assertSame('hotel_autopilot_kick_login_receipt_unverified', $error->getMessage());
        }
        Db::name('ota_local_collector_accounts')->where('id', 501)->update([
            'status' => 'login_required',
            'session_status' => 'login_required',
        ]);
        try {
            $service->enqueueVerifiedLogin(12, 101, 7, 9001);
            self::fail('Expired account evidence must fail closed.');
        } catch (RuntimeException $error) {
            self::assertSame('hotel_autopilot_kick_login_scope_readback_failed', $error->getMessage());
        }
        self::assertSame(0, Db::name(HotelAutopilotKickQueueService::TABLE)->count());
    }

    public function testBackgroundConsumerReconcilesExactHotelAndCompletesWithReadback(): void
    {
        $calls = [];
        $service = $this->service(static function (array $hotel, int $actorId) use (&$calls): array {
            $calls[] = ['tenant_id' => (int)$hotel['tenant_id'], 'hotel_id' => (int)$hotel['id'], 'actor_id' => $actorId];
            return [
                'tenant_id' => (int)$hotel['tenant_id'],
                'hotel_id' => (int)$hotel['id'],
                'status' => 'scheduled_waiting_first_collection',
                'current_stage' => 'first_trusted_collection',
                'failure_code' => null,
                'readback_verified' => true,
                'external_action_triggered' => false,
                'auto_write_ota' => false,
            ];
        });
        $service->enqueueVerifiedLogin(12, 101, 7, 9001);

        $result = $service->consumeDue(3);

        self::assertSame('completed', $result['status']);
        self::assertSame(1, $result['processed_count']);
        self::assertSame(0, $result['failure_count']);
        self::assertSame([['tenant_id' => 12, 'hotel_id' => 101, 'actor_id' => 7]], $calls);
        self::assertSame('completed', $result['results'][0]['status']);
        self::assertSame('scheduled_waiting_first_collection', $result['results'][0]['lifecycle_status']);
        self::assertTrue($result['results'][0]['readback_verified']);
        self::assertSame('completed', Db::name(HotelAutopilotKickQueueService::TABLE)
            ->where('source_task_id', 9001)
            ->value('status'));
        self::assertSame(0, $service->consumeDue(3)['processed_count']);
    }

    public function testBackgroundFailureDefersWithoutChangingVerifiedLoginFacts(): void
    {
        $service = $this->service(static function (): array {
            throw new RuntimeException('hotel_lifecycle_dispatcher_unavailable');
        });
        $service->enqueueVerifiedLogin(12, 101, 7, 9001);

        $result = $service->consumeDue(3);

        self::assertSame('partial', $result['status']);
        self::assertSame('retry_wait', $result['results'][0]['status']);
        self::assertSame('hotel_lifecycle_dispatcher_unavailable', $result['results'][0]['failure_code']);
        self::assertSame(1, $result['results'][0]['attempt_count']);
        self::assertSame('success', Db::name('ota_local_collector_tasks')->where('id', 9001)->value('status'));
        self::assertSame('current_session_verified', Db::name('ota_local_collector_accounts')
            ->where('id', 501)
            ->value('session_status'));
        self::assertSame(0, $service->consumeDue(3)['processed_count']);
    }

    private function service(?callable $reconciler = null): HotelAutopilotKickQueueService
    {
        return new HotelAutopilotKickQueueService(
            $reconciler,
            static fn(): DateTimeImmutable => new DateTimeImmutable(
                '2026-08-12 23:00:00',
                new DateTimeZone('Asia/Shanghai')
            )
        );
    }

    private function seedVerifiedLogin(): void
    {
        Db::name('users')->insert([
            'id' => 7,
            'tenant_id' => 12,
            'status' => 1,
        ]);
        Db::name('hotels')->insert([
            'id' => 101,
            'tenant_id' => 12,
            'name' => 'Hotel A',
            'status' => 1,
            'ota_channel_strategy' => 'dual',
            'owner_user_id' => 7,
            'created_by' => 7,
        ]);
        Db::name('ota_local_collector_accounts')->insert([
            'id' => 501,
            'tenant_id' => 12,
            'user_id' => 7,
            'device_id' => 301,
            'platform' => 'ctrip',
            'status' => 'active',
            'session_status' => 'current_session_verified',
        ]);
        Db::name('ota_local_collector_account_hotels')->insert([
            'id' => 701,
            'tenant_id' => 12,
            'account_id' => 501,
            'system_hotel_id' => 101,
            'platform' => 'ctrip',
            'status' => 'active',
        ]);
        Db::name('ota_local_collector_tasks')->insert([
            'id' => 9001,
            'tenant_id' => 12,
            'user_id' => 7,
            'device_id' => 301,
            'account_id' => 501,
            'system_hotel_id' => 101,
            'platform' => 'ctrip',
            'task_type' => 'login',
            'status' => 'success',
            'result_summary_json' => json_encode([
                'source_method' => 'local_account_profile',
                'session_status' => 'current_session_verified',
                'sensitive_values_received' => false,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'finished_at' => '2026-08-12 22:59:00',
        ]);
    }

    private static function createSchema(): void
    {
        Db::execute('CREATE TABLE users (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, status INTEGER NOT NULL)');
        Db::execute('CREATE TABLE hotels (
            id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT, status INTEGER NOT NULL,
            ota_channel_strategy TEXT, owner_user_id INTEGER, created_by INTEGER
        )');
        Db::execute('CREATE TABLE ota_local_collector_accounts (
            id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
            device_id INTEGER NOT NULL, platform TEXT NOT NULL, status TEXT NOT NULL,
            session_status TEXT NOT NULL
        )');
        Db::execute('CREATE TABLE ota_local_collector_account_hotels (
            id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, account_id INTEGER NOT NULL,
            system_hotel_id INTEGER NOT NULL, platform TEXT NOT NULL, status TEXT NOT NULL
        )');
        Db::execute('CREATE TABLE ota_local_collector_tasks (
            id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
            device_id INTEGER NOT NULL, account_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL,
            platform TEXT NOT NULL, task_type TEXT NOT NULL, status TEXT NOT NULL,
            result_summary_json TEXT, finished_at TEXT
        )');
        Db::execute('CREATE TABLE hotel_autopilot_kick_queue (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            system_hotel_id INTEGER NOT NULL,
            source_task_id INTEGER NOT NULL,
            requested_by INTEGER NOT NULL,
            trigger_type TEXT NOT NULL,
            platform TEXT NOT NULL,
            status TEXT NOT NULL,
            request_digest TEXT NOT NULL,
            attempt_count INTEGER NOT NULL DEFAULT 0,
            next_attempt_at TEXT,
            claimed_at TEXT,
            completed_at TEXT,
            lifecycle_status TEXT,
            lifecycle_failure_code TEXT,
            failure_code TEXT,
            create_time TEXT NOT NULL,
            update_time TEXT NOT NULL,
            UNIQUE (tenant_id, source_task_id)
        )');
    }
}
