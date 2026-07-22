<?php
declare(strict_types=1);

namespace Tests;

use app\controller\concern\OnlineDataQualityConcern;
use app\controller\concern\OnlineDataRecordConcern;
use app\controller\concern\OnlineDataSupportConcern;
use app\controller\concern\OnlineDailyDataPersistenceConcern;
use PHPUnit\Framework\TestCase;
use think\App;
use think\exception\HttpException;
use think\facade\Config;
use think\facade\Db;
use think\Response;

final class HotelPermissionSecurityClosureTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . '/hotel_permission_closure_' . getmypid() . '.sqlite';
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
        Db::execute(<<<'SQL'
CREATE TABLE online_daily_data (
    id INTEGER PRIMARY KEY,
    tenant_id INTEGER NOT NULL,
    system_hotel_id INTEGER NOT NULL,
    amount NUMERIC DEFAULT 0,
    quantity NUMERIC DEFAULT 0,
    book_order_num INTEGER DEFAULT 0,
    comment_score NUMERIC DEFAULT 0,
    qunar_comment_score NUMERIC DEFAULT 0,
    validation_status VARCHAR(32) DEFAULT 'verified',
    validation_flags TEXT DEFAULT '[]',
    create_time DATETIME,
    update_time DATETIME
)
SQL);
    }

    public static function tearDownAfterClass(): void
    {
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$sqlitePath);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Db::name('online_daily_data')->delete(true);
        Db::name('online_daily_data')->insert([
            'id' => 200,
            'tenant_id' => 101,
            'system_hotel_id' => 81,
            'amount' => 10,
            'validation_status' => 'verified',
            'validation_flags' => '[]',
        ]);
    }

    public function testUserAHotelBReadCreateUpdateDeleteAllFailWithoutMutation(): void
    {
        $user = $this->hotelAOnlyUser();

        $read = $this->controller($user, ['system_hotel_id' => 81])->dailyDataList();
        self::assertSame(403, $read->getCode(), '读取酒店B必须失败: ' . (string)$read->getContent());

        $this->assertForbidden(
            fn() => $this->controller($user, [
                'system_hotel_id' => 81,
                'data_date' => '2026-07-21',
                'data' => [['amount' => 99]],
            ])->saveDailyData(),
            '新增酒店B必须失败'
        );

        $this->assertForbidden(
            fn() => $this->controller($user, ['id' => 200, 'amount' => 999])->updateData(),
            '修改酒店B必须失败'
        );

        $this->assertForbidden(
            fn() => $this->controller($user, ['id' => 200])->deleteData(),
            '删除酒店B必须失败'
        );

        $row = Db::name('online_daily_data')->where('id', 200)->find();
        self::assertIsArray($row);
        self::assertSame(10.0, (float)$row['amount']);
        self::assertSame(1, Db::name('online_daily_data')->count());
    }

    public function testZeroAndMissingHotelCannotEnterOnlineDataWriteGate(): void
    {
        $user = $this->hotelAOnlyUser();
        $this->assertForbidden(
            fn() => $this->controller($user, ['system_hotel_id' => 0])->saveDailyData(),
            'hotel_id=0 必须失败'
        );

        $userWithoutCurrentHotel = $this->hotelAOnlyUser(null);
        $this->assertForbidden(
            fn() => $this->controller($userWithoutCurrentHotel, [])->saveDailyData(),
            '缺失当前酒店必须失败'
        );
    }

    private function controller(object $user, array $requestData): object
    {
        return new class($user, $requestData) {
            use OnlineDataSupportConcern;
            use OnlineDailyDataPersistenceConcern;
            use OnlineDataQualityConcern;
            use OnlineDataRecordConcern;

            public object $currentUser;
            public object $request;

            public function __construct(object $user, array $requestData)
            {
                $this->currentUser = $user;
                $this->request = new class($user, $requestData) {
                    public object $user;

                    public function __construct(object $user, private array $data)
                    {
                        $this->user = $user;
                    }

                    public function get(string $key, mixed $default = null): mixed
                    {
                        return $this->data[$key] ?? $default;
                    }

                    public function post(string $key, mixed $default = null): mixed
                    {
                        return $this->data[$key] ?? $default;
                    }

                    public function param(string $key, mixed $default = null): mixed
                    {
                        return $this->data[$key] ?? $default;
                    }

                    public function has(string $key): bool
                    {
                        return array_key_exists($key, $this->data);
                    }
                };
            }

            private function requireHotel(): int
            {
                return (int)($this->currentUser->hotel_id ?? 0);
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

    private function hotelAOnlyUser(?int $primaryHotelId = 80): object
    {
        return new class($primaryHotelId) {
            public int $id = 7;
            public ?int $hotel_id;

            public function __construct(?int $primaryHotelId)
            {
                $this->hotel_id = $primaryHotelId;
            }

            public function isSuperAdmin(): bool
            {
                return false;
            }

            /** @return array<int, int> */
            public function getPermittedHotelIds(): array
            {
                return [80, 81];
            }

            public function hasHotelPermission(int $hotelId, string $permission): bool
            {
                return $hotelId === 80 && in_array($permission, [
                    'can_view_online_data',
                    'can_fetch_online_data',
                    'can_delete_online_data',
                ], true);
            }

            public function hasHotelPermissionOrFail(
                int $hotelId,
                string $permission,
                string $message = '无权限操作该门店'
            ): void {
                if (!$this->hasHotelPermission($hotelId, $permission)) {
                    throw new HttpException(403, $message);
                }
            }
        };
    }

    private function assertForbidden(callable $operation, string $message): void
    {
        try {
            $operation();
            self::fail($message);
        } catch (HttpException $e) {
            self::assertSame(403, $e->getStatusCode(), $message);
        }
    }
}
