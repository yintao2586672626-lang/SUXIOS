<?php
declare(strict_types=1);

namespace Tests;

use app\controller\concern\OnlineDataHistoryConcern;
use app\controller\concern\OnlineDataQualityConcern;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\Response;
use think\facade\Config;
use think\facade\Db;

final class OnlineDataHistoryAuthorizationContractTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'online_history_authorization_' . getmypid() . '.sqlite';
        @unlink(self::$sqlitePath);

        $config = self::$originalDatabaseConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$sqlitePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($config, 'database');
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
        if (is_file(self::$sqlitePath) && !unlink(self::$sqlitePath)) {
            throw new RuntimeException('Unable to remove online history authorization SQLite fixture.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Db::name('online_daily_data')->delete(true);
        Db::name('hotels')->delete(true);
        Db::name('hotels')->insertAll([
            ['id' => 7, 'name' => 'Allowed hotel', 'status' => 1],
            ['id' => 8, 'name' => 'Denied hotel', 'status' => 1],
        ]);
        Db::name('online_daily_data')->insertAll([
            $this->row(1, 7, 'Allowed hotel'),
            $this->row(2, 8, 'Denied hotel'),
        ]);
    }

    public function testHistoryListsOnlyHotelsWithOnlineDataViewPermission(): void
    {
        $user = $this->user([7, 8], [7]);

        $history = $this->payload($this->controller($user)->history());
        self::assertSame(200, $history['code']);
        self::assertSame([7], array_map(
            static fn(array $row): int => (int)$row['hotel_id'],
            $history['data']['list']
        ));

        $ctripHistory = $this->payload($this->controller($user)->ctripHistory());
        self::assertSame(200, $ctripHistory['code']);
        self::assertSame([7], array_map(
            static fn(array $row): int => (int)$row['hotel_id'],
            $ctripHistory['data']['list']
        ));
    }

    public function testHistoryDetailRequiresOnlineDataPermissionForTheTargetRecordHotel(): void
    {
        $response = $this->payload($this->controller($this->user([7, 8], [7]))->historyDetail(2));

        self::assertSame(403, $response['code']);
        self::assertSame('无权查看该历史记录', $response['message']);
    }

    public function testCtripHistoryAppliesBusinessDateBoundsToListAndCount(): void
    {
        Db::name('online_daily_data')->insert(array_replace($this->row(3, 7, 'Allowed hotel'), [
            'data_date' => '2026-07-31', 'create_time' => '2026-08-03 12:00:00',
        ]));
        $user = $this->user([7, 8], [7]);
        $response = $this->payload($this->controller($user, [
            'start_date' => '2026-07-31', 'end_date' => '2026-07-31',
        ])->ctripHistory());
        self::assertSame(200, $response['code']);
        self::assertSame(1, $response['data']['total']);
        self::assertSame([3], array_column($response['data']['list'], 'id'));
        self::assertSame('2026-07-31', $response['data']['list'][0]['data_date']);
    }

    public function testStoredHistoryNeverReturnsLegacyCredentialFields(): void
    {
        Db::name('online_daily_data')->where('id', 1)->update(['raw_data' => json_encode([
            'cookies' => 'test-only-cookie-marker',
            'nested' => ['authorization' => 'test-only-auth-marker'],
            'amount' => 42,
        ])]);
        $response = $this->payload($this->controller($this->user([7], [7]))->historyDetail(1));
        self::assertSame(200, $response['code']);
        self::assertStringNotContainsString('test-only-cookie-marker', json_encode($response));
        self::assertStringNotContainsString('test-only-auth-marker', json_encode($response));
        self::assertSame(42, $response['data']['raw_data_json']['amount']);
    }

    public function testDailyListAppliesEitherBusinessDateBoundAndSurvivesHotelRename(): void
    {
        Db::name('online_daily_data')->insert(array_replace($this->row(3, 7, 'Old name'), ['data_date' => '2026-07-31']));
        Db::name('hotels')->where('id', 7)->update(['name' => 'Renamed hotel']);
        $user = $this->user([7, 8], [7]);
        $from = $this->payload($this->controller($user, ['start_date' => '2026-08-01'])->dailyDataList());
        $until = $this->payload($this->controller($user, ['end_date' => '2026-07-31'])->dailyDataList());
        self::assertSame([1], array_column($from['data']['list'], 'id'));
        self::assertSame([3], array_column($until['data']['list'], 'id'));
        self::assertSame('Renamed hotel', $until['data']['list'][0]['hotel_name']);
        self::assertSame(1, $until['data']['pagination']['total']);
    }

    public function testDailyListAlsoRedactsLegacyCredentialsWithoutMutatingStoredMetrics(): void
    {
        Db::name('online_daily_data')->where('id', 1)->update(['raw_data' => '{"cookies":"test-only-cookie-marker","amount":42}']);
        $response = $this->payload($this->controller($this->user([7], [7]))->dailyDataList());
        self::assertSame(200, $response['code']);
        self::assertStringNotContainsString('test-only-cookie-marker', json_encode($response));
        self::assertStringContainsString('test-only-cookie-marker', Db::name('online_daily_data')->where('id', 1)->value('raw_data'));
    }

    private static function createSchema(): void
    {
        Db::execute(<<<'SQL'
CREATE TABLE hotels (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    status INTEGER NOT NULL
)
SQL);
        Db::execute(<<<'SQL'
CREATE TABLE online_daily_data (
    id INTEGER PRIMARY KEY,
    data_date TEXT NOT NULL,
    source TEXT NOT NULL DEFAULT '',
    platform TEXT DEFAULT NULL,
    data_type TEXT NOT NULL DEFAULT '',
    system_hotel_id INTEGER DEFAULT NULL,
    hotel_id TEXT DEFAULT NULL,
    hotel_name TEXT DEFAULT NULL,
    dimension TEXT NOT NULL DEFAULT '',
    compare_type TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT '',
    validation_status TEXT NOT NULL DEFAULT 'normal',
    readback_verified INTEGER NOT NULL DEFAULT 0,
    raw_data TEXT DEFAULT NULL,
    amount REAL DEFAULT 0,
    quantity REAL DEFAULT 0,
    book_order_num INTEGER DEFAULT 0,
    data_value REAL DEFAULT 0,
    list_exposure INTEGER DEFAULT 0,
    detail_exposure INTEGER DEFAULT 0,
    order_submit_num INTEGER DEFAULT 0,
    create_time TEXT DEFAULT NULL,
    update_time TEXT DEFAULT NULL
)
SQL);
    }

    private function controller(object $user, array $params = []): object
    {
        return new class($user, $params) {
            use OnlineDataHistoryConcern;
            use OnlineDataQualityConcern;

            public object $currentUser;
            public object $request;

            public function __construct(object $user, array $params)
            {
                $this->currentUser = $user;
                $this->request = new class($user, $params) {
                    public object $user;

                    public function __construct(object $user, private array $params)
                    {
                        $this->user = $user;
                    }

                    public function get(string $key, mixed $default = null): mixed
                    {
                        return $this->params[$key] ?? $default;
                    }
                };
            }

            private function getOnlineDailyDataColumns(): array
            {
                $columns = [];
                foreach (Db::query('PRAGMA table_info(online_daily_data)') as $column) {
                    $columns[(string)$column['name']] = true;
                }
                return $columns;
            }

            private function normalizeOnlineDataTypeFilters(mixed $single, mixed $multiple): array
            {
                return [];
            }

            protected function success(mixed $data = null, string $message = '操作成功'): Response
            {
                return json(['code' => 200, 'message' => $message, 'data' => $data], 200);
            }

            protected function error(string $message = '操作失败', int $code = 400, mixed $data = null): Response
            {
                return json(['code' => $code, 'message' => $message, 'data' => $data], $code);
            }
        };
    }

    private function user(array $permittedHotelIds, array $onlineDataHotelIds): object
    {
        return new class($permittedHotelIds, $onlineDataHotelIds) {
            public int $hotel_id = 7;

            public function __construct(private array $permittedHotelIds, private array $onlineDataHotelIds)
            {
            }

            public function isSuperAdmin(): bool
            {
                return false;
            }

            public function getPermittedHotelIds(): array
            {
                return $this->permittedHotelIds;
            }

            public function hasHotelPermission(int $hotelId, string $permission): bool
            {
                return $permission === 'can_view_online_data'
                    && in_array($hotelId, $this->onlineDataHotelIds, true);
            }
        };
    }

    private function row(int $id, int $systemHotelId, string $hotelName): array
    {
        $fetchTime = '2026-08-01 09:00:00';
        return [
            'id' => $id,
            'data_date' => '2026-08-01',
            'source' => 'ctrip',
            'platform' => 'Ctrip',
            'data_type' => 'traffic',
            'system_hotel_id' => $systemHotelId,
            'hotel_id' => 'ctrip-' . $systemHotelId,
            'hotel_name' => $hotelName,
            'dimension' => 'traffic',
            'compare_type' => 'self',
            'status' => '',
            'validation_status' => 'normal',
            'readback_verified' => 1,
            'raw_data' => '{}',
            'amount' => 1,
            'quantity' => 1,
            'book_order_num' => 1,
            'data_value' => 1,
            'list_exposure' => 1,
            'detail_exposure' => 1,
            'order_submit_num' => 1,
            'create_time' => $fetchTime,
            'update_time' => $fetchTime,
        ];
    }

    private function payload(Response $response): array
    {
        $payload = json_decode($response->getContent(), true);
        self::assertIsArray($payload);
        return $payload;
    }
}
