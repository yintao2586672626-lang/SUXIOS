<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Base;
use app\controller\SystemNotificationController;
use app\model\SystemNotification;
use app\model\SystemNotificationUserState;
use app\service\OtaFailureNotificationService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OtaFailureNotificationServiceTest extends TestCase
{
    /** @var array<string, mixed> */
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';
    private static ?App $app = null;

    public static function setUpBeforeClass(): void
    {
        self::$app = new App();
        self::$app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'suxios_ota_failure_notification_test_' . getmypid() . '.sqlite';
        if (is_file(self::$sqlitePath)) {
            @unlink(self::$sqlitePath);
        }

        $config = self::$originalDatabaseConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$sqlitePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($config, 'database');
        Db::connect()->close();
        Db::connect(null, true);
        self::createTables();
    }

    public static function tearDownAfterClass(): void
    {
        try {
            Db::connect('sqlite')->close();
        } catch (\Throwable) {
            // Cleanup only.
        }
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect()->close();
        Db::connect(null, true);
        if (self::$sqlitePath !== '' && is_file(self::$sqlitePath)) {
            @unlink(self::$sqlitePath);
        }
    }

    protected function setUp(): void
    {
        foreach ([
            'system_notification_user_states',
            'system_notifications',
            'operation_logs',
            'platform_data_sources',
            'system_configs',
            'user_hotel_permissions',
            'hotels',
            'users',
            'roles',
        ] as $table) {
            Db::name($table)->delete(true);
        }
        Db::name('roles')->insert([
            'id' => 2,
            'name' => 'hotel_manager',
            'display_name' => '门店用户',
            'level' => 2,
            'permissions' => json_encode(['can_view_online_data'], JSON_UNESCAPED_UNICODE),
            'status' => 1,
        ]);
    }

    public function testConfiguredSubmitterReceivesSanitizedIdempotentNotification(): void
    {
        $this->seedHotelAndUsers();
        $this->grantHotel(101, 7);
        $this->seedConfig(7, 'ctrip', 101);
        $service = new OtaFailureNotificationService();

        $first = $service->recordCollectionOutcome([
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'reason_code' => 'browser_profile_source_missing',
            'authorization_source_type' => 'cookie_api',
            'authorization_source_label' => 'Cookie=session-secret; token=live-token',
            'message' => 'Cookie=session-secret token=live-token Profile C:\\secret\\profile raw response',
            'data_date' => '2026-07-13',
            'success' => false,
            'saved_count' => 0,
        ]);

        self::assertSame('notified', $first['status']);
        self::assertSame(101, $first['deliveries'][0]['recipient_user_id']);
        self::assertSame('source_missing', $first['deliveries'][0]['reason_code']);

        $notification = SystemNotification::find((int)$first['deliveries'][0]['notification_id']);
        self::assertSame(101, (int)$notification->recipient_user_id);
        self::assertStringContainsString('2026-07-13', (string)$notification->message);
        self::assertStringNotContainsString('session-secret', (string)$notification->message);
        self::assertStringNotContainsString('live-token', (string)$notification->message);
        self::assertStringNotContainsString('secret\\profile', (string)$notification->message);
        self::assertStringNotContainsString('raw response', (string)$notification->message);
        $actionPayload = json_decode((string)$notification->action_payload, true);
        self::assertSame('授权来源（敏感值已隐藏）', $actionPayload['authorization_source_label']);
        self::assertStringNotContainsString('session-secret', (string)$notification->action_payload);
        self::assertStringNotContainsString('live-token', (string)$notification->action_payload);

        SystemNotificationUserState::markClearedForUser([(int)$notification->id], 101);
        $second = $service->recordCollectionOutcome([
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'reason_code' => 'migration_required',
            'data_date' => '2026-07-14',
            'success' => false,
            'saved_count' => 0,
        ]);

        self::assertSame((int)$notification->id, $second['deliveries'][0]['notification_id']);
        self::assertSame(1, SystemNotification::where('hotel_id', 7)->where('platform', 'ctrip')->count());
        self::assertSame([], SystemNotificationUserState::statesByNotificationId([(int)$notification->id], 101));
        self::assertStringContainsString('2026-07-14', (string)SystemNotification::find((int)$notification->id)->message);
    }

    public function testInvisibleConfigSubmitterDoesNotFallBackToUnrelatedHotelOwner(): void
    {
        $this->seedHotelAndUsers();
        $this->seedConfig(7, 'meituan', 101);

        $result = (new OtaFailureNotificationService())->recordCollectionOutcome([
            'hotel_id' => 7,
            'platform' => 'meituan',
            'reason_code' => 'current_session_probe_missing',
            'data_date' => '2026-07-13',
            'success' => false,
            'saved_count' => 0,
        ]);

        self::assertSame('recipient_missing', $result['status']);
        self::assertSame(0, SystemNotification::where('hotel_id', 7)->count());
        self::assertSame('session_unverified', $result['deliveries'][0]['reason_code']);
    }

    public function testAuthFailureCreatesStrongReminderAndVerifiedCaptureResolvesIt(): void
    {
        $this->seedHotelAndUsers();
        $this->grantHotel(101, 7);
        $this->seedConfig(7, 'ctrip', 101);
        $service = new OtaFailureNotificationService();

        $failure = $service->recordCollectionOutcome([
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'reason_code' => 'login_expired',
            'authorization_source_label' => '携程运营 Profile',
            'authorization_source_type' => 'profile',
            'data_source_id' => 117,
            'data_date' => '2026-07-14',
            'success' => false,
            'saved_count' => 0,
        ]);

        self::assertSame('notified', $failure['status']);
        $notificationId = (int)$failure['deliveries'][0]['notification_id'];
        $notification = SystemNotification::find($notificationId);
        self::assertSame('ota_auth_required', (string)$notification->category);
        self::assertSame(0, (int)$notification->is_cleared);
        $payload = json_decode((string)$notification->action_payload, true);
        self::assertSame('1', $payload['requires_resolution']);
        self::assertSame('strong', $payload['reminder_level']);
        self::assertSame('verified_same_platform_session_or_capture', $payload['resolution_rule']);
        self::assertSame('携程运营 Profile', $payload['authorization_source_label']);
        self::assertSame('profile', $payload['authorization_source_type']);
        self::assertSame('exact', $payload['authorization_source_state']);
        self::assertSame('117', $payload['data_source_id']);

        $success = $service->recordCollectionOutcome([
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'data_date' => '2026-07-14',
            'success' => true,
            'saved_count' => 0,
            'auth_verified' => true,
        ]);

        self::assertSame('no_failure', $success['status']);
        self::assertSame('resolved', $success['resolutions'][0]['status']);
        self::assertSame(1, $success['resolutions'][0]['resolved_count']);
        self::assertSame(1, (int)SystemNotification::find($notificationId)->is_cleared);

        $recurrence = $service->recordCollectionOutcome([
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'reason_code' => 'login_expired',
            'data_date' => '2026-07-15',
            'success' => false,
            'saved_count' => 0,
        ]);
        self::assertSame($notificationId, (int)$recurrence['deliveries'][0]['notification_id']);
        self::assertSame(0, (int)SystemNotification::find($notificationId)->is_cleared);
    }

    public function testSuccessfulPlatformResolvesOnlyItsOwnStrongReminderDuringPartialRun(): void
    {
        $this->seedHotelAndUsers();
        $this->grantHotel(101, 7);
        $this->seedConfig(7, 'ctrip', 101);
        $this->seedConfig(7, 'meituan', 101);
        $service = new OtaFailureNotificationService();
        $service->recordCollectionOutcome([
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'reason_code' => 'login_expired',
            'success' => false,
            'saved_count' => 0,
        ]);

        $result = $service->recordCollectionOutcome([
            'hotel_id' => 7,
            'success' => true,
            'saved_count' => 8,
            'successful_platforms' => ['ctrip'],
            'failed_platforms' => ['meituan'],
            'reason_code' => 'current_session_probe_missing',
        ]);

        self::assertSame('notified', $result['status']);
        self::assertSame('resolved', $result['resolutions'][0]['status']);
        self::assertSame('meituan', $result['deliveries'][0]['platform']);
        self::assertSame('ota_auth_required', (string)SystemNotification::where('platform', 'meituan')->value('category'));
        self::assertSame(1, (int)SystemNotification::where('platform', 'ctrip')->value('is_cleared'));
    }

    public function testNoRecipientCreatesExplicitAuditInsteadOfBroadcastNotification(): void
    {
        Db::name('hotels')->insert([
            'id' => 9,
            'tenant_id' => 1,
            'name' => '无提交人门店',
            'status' => 1,
            'created_by' => 998,
            'owner_user_id' => 999,
        ]);

        $result = (new OtaFailureNotificationService())->recordCollectionOutcome([
            'hotel_id' => 9,
            'platform' => 'ctrip',
            'reason_code' => 'target_date_rows_missing',
            'data_date' => '2026-07-13',
            'success' => false,
            'saved_count' => 0,
        ]);

        self::assertSame('recipient_missing', $result['status']);
        self::assertSame(0, SystemNotification::where('hotel_id', 9)->count());
        $audit = Db::name('operation_logs')->where('hotel_id', 9)->find();
        self::assertIsArray($audit);
        self::assertSame('ota_failure_notification_recipient_missing', $audit['action']);
        self::assertStringContainsString('recipient_missing', (string)$audit['extra_data']);
    }

    public function testSuccessfulRowsAndFieldEvidencePartialDoNotProduceFailureNotification(): void
    {
        $this->seedHotelAndUsers();
        $this->grantHotel(101, 7);
        $this->seedConfig(7, 'ctrip', 101);

        $result = (new OtaFailureNotificationService())->recordCollectionOutcome([
            'hotel_id' => 7,
            'success' => true,
            'saved_count' => 125,
            'message' => 'field_fact_status=partial',
            'platform_results' => [[
                'platform' => 'ctrip',
                'success' => true,
                'saved_count' => 125,
                'message' => 'field_fact_status=partial',
            ]],
        ]);

        self::assertSame('no_failure', $result['status']);
        self::assertSame(0, SystemNotification::count());
    }

    public function testPartialPlatformFailureNotifiesOnlyFailedPlatform(): void
    {
        $this->seedHotelAndUsers();
        $this->grantHotel(101, 7);
        $this->seedConfig(7, 'ctrip', 101);
        $this->seedConfig(7, 'meituan', 101);

        $result = (new OtaFailureNotificationService())->recordCollectionOutcome([
            'hotel_id' => 7,
            'success' => true,
            'saved_count' => 20,
            'data_date' => '2026-07-13',
            'platform_results' => [
                ['platform' => 'ctrip', 'success' => true, 'saved_count' => 20],
                ['platform' => 'meituan', 'success' => false, 'saved_count' => 0, 'reason_code' => 'current_session_probe_missing'],
            ],
        ]);

        self::assertSame('notified', $result['status']);
        self::assertCount(1, $result['deliveries']);
        self::assertSame('meituan', $result['deliveries'][0]['platform']);
        self::assertSame(0, SystemNotification::where('platform', 'ctrip')->count());
        self::assertSame(1, SystemNotification::where('platform', 'meituan')->count());
    }

    public function testTargetedNotificationIsNotVisibleToAnotherHotelUser(): void
    {
        $this->seedHotelAndUsers();
        $this->grantHotel(101, 7);
        $this->grantHotel(303, 7);
        $this->seedConfig(7, 'ctrip', 101);
        (new OtaFailureNotificationService())->recordCollectionOutcome([
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'reason_code' => 'zero_rows',
            'data_date' => '2026-07-13',
            'success' => false,
            'saved_count' => 0,
        ]);
        SystemNotification::recordEvent([
            'hotel_id' => 7,
            'platform' => 'ota',
            'category' => 'general',
            'title' => 'Legacy broadcast',
            'source_key' => 'unit_test_legacy_broadcast',
        ]);

        $recipientIds = $this->visibleNotificationIdsFor(101, [7]);
        $otherUserIds = $this->visibleNotificationIdsFor(303, [7]);

        self::assertCount(2, $recipientIds);
        self::assertCount(1, $otherUserIds);
        $otherNotification = SystemNotification::find($otherUserIds[0]);
        self::assertNull($otherNotification->recipient_user_id);
    }

    public function testSerializedStrongReminderIsFlaggedOnlyForDirectRecipient(): void
    {
        $this->seedHotelAndUsers();
        $this->grantHotel(101, 7);
        $this->seedConfig(7, 'meituan', 101);
        Db::name('platform_data_sources')->insert([
            'id' => 208,
            'tenant_id' => 1,
            'system_hotel_id' => 7,
            'user_id' => 101,
            'name' => '美团前台 Profile',
            'platform' => 'meituan',
            'data_type' => 'operations',
            'ingestion_method' => 'browser_profile',
            'status' => 'login_expired',
            'enabled' => 1,
            'config_json' => json_encode(['profile_status' => 'login_expired'], JSON_UNESCAPED_UNICODE),
            'last_sync_status' => 'failed',
            'last_error' => 'login expired',
            'created_by' => 101,
            'update_time' => '2026-07-14 18:00:00',
        ]);
        $result = (new OtaFailureNotificationService())->recordCollectionOutcome([
            'hotel_id' => 7,
            'platform' => 'meituan',
            'reason_code' => 'login_expired',
            'success' => false,
            'saved_count' => 0,
        ]);
        $row = SystemNotification::with(['hotel', 'actor'])
            ->find((int)$result['deliveries'][0]['notification_id'])
            ->toArray();

        $controller = new SystemNotificationController(self::$app ?? new App());
        $currentUser = new class {
            public int $id = 101;

            public function isSuperAdmin(): bool
            {
                return false;
            }

            public function getPermittedHotelIds(): array
            {
                return [7];
            }
        };
        $property = new ReflectionProperty(Base::class, 'currentUser');
        $property->setAccessible(true);
        $property->setValue($controller, $currentUser);
        $method = (new ReflectionClass(SystemNotificationController::class))->getMethod('serializeNotification');
        $method->setAccessible(true);
        $serialized = $method->invoke($controller, $row, []);

        self::assertTrue($serialized['requires_resolution']);
        self::assertTrue($serialized['is_direct_recipient']);
        self::assertSame('strong', $serialized['reminder_level']);
        self::assertSame('login_expired', $serialized['reason_code']);
        self::assertSame('登录失效强提醒', $serialized['category_label']);
        self::assertSame('美团前台 Profile · 数据源 #208', $serialized['authorization_source_label']);
        self::assertSame('profile', $serialized['authorization_source_type']);
        self::assertSame('exact', $serialized['authorization_source_state']);
        self::assertSame(208, $serialized['data_source_id']);
    }

    public function testDirectStrongReminderRowsAreNotLimitedToFirstNotificationPage(): void
    {
        $this->seedHotelAndUsers();
        $this->grantHotel(101, 7);
        for ($index = 1; $index <= 25; $index++) {
            SystemNotification::recordEvent([
                'hotel_id' => 7,
                'recipient_user_id' => 101,
                'platform' => $index % 2 === 0 ? 'ctrip' : 'meituan',
                'category' => 'ota_auth_required',
                'severity' => 'error',
                'title' => '登录失效强提醒 ' . $index,
                'source_module' => 'ota_failure_notifier',
                'source_key' => 'unit_test_strong_reminder_' . $index,
            ]);
        }
        SystemNotification::recordEvent([
            'hotel_id' => 7,
            'recipient_user_id' => 101,
            'category' => 'general',
            'title' => '普通通知',
            'source_module' => 'system',
            'source_key' => 'unit_test_normal_notification',
        ]);

        $controller = new SystemNotificationController(self::$app ?? new App());
        $currentUser = new class {
            public int $id = 101;

            public function isSuperAdmin(): bool
            {
                return false;
            }

            public function getPermittedHotelIds(): array
            {
                return [7];
            }
        };
        $property = new ReflectionProperty(Base::class, 'currentUser');
        $property->setAccessible(true);
        $property->setValue($controller, $currentUser);
        $method = (new ReflectionClass(SystemNotificationController::class))->getMethod('directStrongReminderRows');
        $method->setAccessible(true);
        $rows = $method->invoke($controller, 101);

        self::assertCount(25, $rows);
        self::assertSame(
            array_fill(0, 25, 101),
            array_map(static fn(array $row): int => (int)$row['recipient_user_id'], $rows)
        );
    }

    /** @return array<int, int> */
    private function visibleNotificationIdsFor(int $userId, array $hotelIds): array
    {
        $controller = new SystemNotificationController(self::$app ?? new App());
        $currentUser = new class($userId, $hotelIds) {
            public function __construct(public int $id, private array $hotelIds)
            {
            }

            public function isSuperAdmin(): bool
            {
                return false;
            }

            public function getPermittedHotelIds(): array
            {
                return $this->hotelIds;
            }
        };
        $property = new ReflectionProperty(Base::class, 'currentUser');
        $property->setAccessible(true);
        $property->setValue($controller, $currentUser);
        $query = SystemNotification::where('is_cleared', 0);
        $method = (new ReflectionClass(SystemNotificationController::class))->getMethod('applyVisibleScope');
        $method->setAccessible(true);
        $method->invoke($controller, $query, '');
        return array_map('intval', $query->order('id', 'asc')->column('id'));
    }

    private function seedHotelAndUsers(): void
    {
        Db::name('hotels')->insert([
            'id' => 7,
            'tenant_id' => 1,
            'name' => '测试门店',
            'status' => 1,
            'created_by' => 201,
            'owner_user_id' => 202,
        ]);
        foreach ([101, 201, 202, 303] as $userId) {
            Db::name('users')->insert([
                'id' => $userId,
                'tenant_id' => 1,
                'username' => 'user_' . $userId,
                'status' => 1,
                'hotel_id' => $userId === 202 ? 7 : null,
                'role_id' => 2,
            ]);
        }
    }

    private function grantHotel(int $userId, int $hotelId): void
    {
        Db::name('user_hotel_permissions')->insert([
            'user_id' => $userId,
            'hotel_id' => $hotelId,
            'can_view_online_data' => 1,
        ]);
    }

    private function seedConfig(int $hotelId, string $platform, int $userId): void
    {
        $key = $platform . '_config_list';
        $row = Db::name('system_configs')->where('config_key', $key)->find();
        $list = $row ? json_decode((string)$row['config_value'], true) : [];
        $list = is_array($list) ? $list : [];
        $id = $platform . '_' . $hotelId;
        $list[$id] = [
            'id' => $id,
            'config_id' => $id,
            'system_hotel_id' => $hotelId,
            'hotel_id' => (string)$hotelId,
            'user_id' => $userId,
            'config_status' => 'active',
            'update_time' => '2026-07-14 10:00:00',
        ];
        $payload = json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($row) {
            Db::name('system_configs')->where('config_key', $key)->update(['config_value' => $payload]);
        } else {
            Db::name('system_configs')->insert(['config_key' => $key, 'config_value' => $payload]);
        }
    }

    private static function createTables(): void
    {
        Db::execute('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT, display_name TEXT, level INTEGER, permissions TEXT, status INTEGER)');
        Db::execute('CREATE TABLE users (id INTEGER PRIMARY KEY, tenant_id INTEGER, username TEXT, status INTEGER, hotel_id INTEGER, role_id INTEGER)');
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER, name TEXT, status INTEGER, created_by INTEGER, owner_user_id INTEGER)');
        Db::execute('CREATE TABLE user_hotel_permissions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, hotel_id INTEGER, can_view_online_data INTEGER DEFAULT 0)');
        Db::execute('CREATE TABLE system_configs (id INTEGER PRIMARY KEY AUTOINCREMENT, config_key TEXT UNIQUE, config_value TEXT)');
        Db::execute("CREATE TABLE platform_data_sources (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER DEFAULT NULL,
            system_hotel_id INTEGER NOT NULL,
            user_id INTEGER DEFAULT NULL,
            name TEXT DEFAULT NULL,
            platform TEXT NOT NULL,
            data_type TEXT DEFAULT NULL,
            ingestion_method TEXT DEFAULT NULL,
            status TEXT DEFAULT 'active',
            enabled INTEGER DEFAULT 1,
            config_json TEXT DEFAULT NULL,
            secret_json TEXT DEFAULT NULL,
            last_sync_time TEXT DEFAULT NULL,
            last_sync_status TEXT DEFAULT NULL,
            last_error TEXT DEFAULT NULL,
            created_by INTEGER DEFAULT NULL,
            create_time TEXT DEFAULT NULL,
            update_time TEXT DEFAULT NULL
        )");
        Db::execute('CREATE TABLE operation_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, hotel_id INTEGER, module TEXT, action TEXT, description TEXT, extra_data TEXT, create_time TEXT)');
        Db::execute("CREATE TABLE system_notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            hotel_id INTEGER DEFAULT NULL,
            user_id INTEGER DEFAULT NULL,
            recipient_user_id INTEGER DEFAULT NULL,
            platform TEXT NOT NULL DEFAULT 'ota',
            category TEXT NOT NULL DEFAULT 'general',
            severity TEXT NOT NULL DEFAULT 'info',
            title TEXT NOT NULL,
            message TEXT DEFAULT NULL,
            action_type TEXT DEFAULT NULL,
            action_payload TEXT DEFAULT NULL,
            source_module TEXT NOT NULL DEFAULT 'system',
            source_key TEXT NOT NULL UNIQUE,
            is_read INTEGER NOT NULL DEFAULT 0,
            is_cleared INTEGER NOT NULL DEFAULT 0,
            read_time TEXT DEFAULT NULL,
            clear_time TEXT DEFAULT NULL,
            create_time TEXT DEFAULT NULL,
            update_time TEXT DEFAULT NULL
        )");
        Db::execute("CREATE TABLE system_notification_user_states (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            notification_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            is_read INTEGER NOT NULL DEFAULT 0,
            is_cleared INTEGER NOT NULL DEFAULT 0,
            read_time TEXT DEFAULT NULL,
            clear_time TEXT DEFAULT NULL,
            create_time TEXT DEFAULT NULL,
            update_time TEXT DEFAULT NULL,
            UNIQUE(notification_id, user_id)
        )");
    }
}
