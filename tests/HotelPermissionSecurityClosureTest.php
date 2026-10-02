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
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL)');
        Db::execute(<<<'SQL'
CREATE TABLE online_data_correction_ledger (
    id INTEGER PRIMARY KEY,
    online_data_id INTEGER NOT NULL,
    tenant_id INTEGER NULL,
    system_hotel_id INTEGER NOT NULL,
    operator_id INTEGER NOT NULL,
    operation TEXT NOT NULL,
    changed_fields_json TEXT NULL,
    before_json TEXT NULL,
    reason TEXT NULL,
    restorable INTEGER NOT NULL DEFAULT 0,
    restored_at TEXT NULL,
    restored_by INTEGER NULL,
    created_at TEXT NOT NULL
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
        Db::name('online_data_correction_ledger')->delete(true);
        Db::name('hotels')->delete(true);
        Db::name('hotels')->insertAll([
            ['id' => 80, 'tenant_id' => 101],
            ['id' => 81, 'tenant_id' => 101],
        ]);
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

    public function testWrongTenantRecordIsNotExposedByNoChangeUpdateOrDeletePreRead(): void
    {
        Db::name('online_daily_data')->insert([
            'id' => 201,
            'tenant_id' => 202,
            'system_hotel_id' => 80,
            'amount' => 30,
            'validation_status' => 'verified',
            'validation_flags' => '[]',
        ]);
        $user = $this->hotelAOnlyUser();

        $unchanged = $this->controller($user, ['id' => 201, 'amount' => 30])->updateData();
        self::assertSame(400, $unchanged->getCode());
        self::assertSame('数据不存在', json_decode((string)$unchanged->getContent(), true)['message']);

        $delete = $this->controller($user, ['id' => 201])->deleteData();
        self::assertSame(400, $delete->getCode());
        self::assertSame('数据不存在', json_decode((string)$delete->getContent(), true)['message']);
        self::assertSame(30.0, (float)Db::name('online_daily_data')->where('id', 201)->value('amount'));
    }

    public function testCorrectionLedgerCountPagesAndRestoreHintRespectTenantAndHotelScope(): void
    {
        foreach ([
            [501, 101, 80, 'delete', 1],
            [502, 202, 80, 'delete', 1],
            [503, null, 80, 'delete', 1],
            [504, 101, 81, 'delete', 1],
            [506, 101, 80, 'update', 0],
        ] as [$id, $tenantId, $hotelId, $operation, $restorable]) {
            Db::name('online_data_correction_ledger')->insert([
                'id' => $id,
                'online_data_id' => $id + 100,
                'tenant_id' => $tenantId,
                'system_hotel_id' => $hotelId,
                'operator_id' => 7,
                'operation' => $operation,
                'changed_fields_json' => '["amount"]',
                'before_json' => json_encode([
                    'id' => $id + 100,
                    'tenant_id' => $tenantId,
                    'system_hotel_id' => $hotelId,
                ], JSON_THROW_ON_ERROR),
                'reason' => 'synthetic correction',
                'restorable' => $restorable,
                'created_at' => '2026-07-15 12:00:00',
            ]);
        }

        $user = $this->hotelAOnlyUser();
        $first = json_decode((string)$this->controller($user, ['page' => 1, 'page_size' => 1])->correctionLedger()->getContent(), true)['data'];
        self::assertSame(2, $first['total']);
        self::assertSame([506], array_map('intval', array_column($first['list'], 'id')));
        self::assertFalse($first['list'][0]['can_restore']);

        $second = json_decode((string)$this->controller($user, ['page' => 2, 'page_size' => 1])->correctionLedger()->getContent(), true)['data'];
        self::assertSame(2, $second['total']);
        self::assertSame([501], array_map('intval', array_column($second['list'], 'id')));
        self::assertTrue($second['list'][0]['can_restore']);

        $superUser = new class {
            public int $id = 1;
            public ?int $hotel_id = null;

            public function isSuperAdmin(): bool
            {
                return true;
            }
        };
        $superList = json_decode((string)$this->controller($superUser, ['page_size' => 10])->correctionLedger()->getContent(), true)['data'];
        self::assertSame(5, $superList['total']);
    }

    public function testCorrectionLedgerKeepsHotelPermissionWhenLegacyHotelTableHasNoTenantColumn(): void
    {
        Db::name('online_data_correction_ledger')->insert([
            'id' => 507,
            'online_data_id' => 607,
            'tenant_id' => 101,
            'system_hotel_id' => 80,
            'operator_id' => 7,
            'operation' => 'update',
            'changed_fields_json' => '["amount"]',
            'before_json' => '{}',
            'reason' => 'legacy hotel schema',
            'restorable' => 0,
            'created_at' => '2026-07-15 12:00:00',
        ]);
        Db::execute('ALTER TABLE hotels DROP COLUMN tenant_id');
        Db::connect(null, true);
        try {
            $response = $this->controller($this->hotelAOnlyUser(), [])->correctionLedger();
            self::assertSame(200, $response->getCode());
            $data = json_decode((string)$response->getContent(), true)['data'];
            self::assertSame(1, $data['total']);
            self::assertSame(507, (int)$data['list'][0]['id']);
        } finally {
            Db::execute('ALTER TABLE hotels ADD COLUMN tenant_id INTEGER');
            Db::name('hotels')->whereIn('id', [80, 81])->update(['tenant_id' => 101]);
            Db::connect(null, true);
        }
    }

    public function testCorrectionLedgerRestoreHintMatchesSnapshotAndTargetAvailability(): void
    {
        foreach ([
            [511, 101, 80, 611],
            [512, 101, 81, 612],
            [513, 202, 80, 613],
            [514, 101, 80, 614],
        ] as [$ledgerId, $snapshotTenantId, $snapshotHotelId, $dataId]) {
            Db::name('online_data_correction_ledger')->insert([
                'id' => $ledgerId,
                'online_data_id' => $dataId,
                'tenant_id' => 101,
                'system_hotel_id' => 80,
                'operator_id' => 7,
                'operation' => 'delete',
                'changed_fields_json' => '["amount"]',
                'before_json' => json_encode([
                    'id' => $dataId,
                    'tenant_id' => $snapshotTenantId,
                    'system_hotel_id' => $snapshotHotelId,
                ], JSON_THROW_ON_ERROR),
                'reason' => 'synthetic restore state',
                'restorable' => 1,
                'created_at' => '2026-07-15 12:00:00',
            ]);
        }
        foreach ([
            [515, 615, '{'],
            [516, 616, json_encode(['id' => 617, 'tenant_id' => 101, 'system_hotel_id' => 80], JSON_THROW_ON_ERROR)],
        ] as [$ledgerId, $dataId, $snapshot]) {
            Db::name('online_data_correction_ledger')->insert([
                'id' => $ledgerId,
                'online_data_id' => $dataId,
                'tenant_id' => 101,
                'system_hotel_id' => 80,
                'operator_id' => 7,
                'operation' => 'delete',
                'changed_fields_json' => '["amount"]',
                'before_json' => $snapshot,
                'reason' => 'synthetic invalid snapshot',
                'restorable' => 1,
                'created_at' => '2026-07-15 12:00:00',
            ]);
        }
        Db::name('online_daily_data')->insert([
            'id' => 614,
            'tenant_id' => 101,
            'system_hotel_id' => 80,
            'amount' => 40,
        ]);

        $response = $this->controller($this->hotelAOnlyUser(), ['page_size' => 10])->correctionLedger();
        self::assertSame(200, $response->getCode());
        $list = json_decode((string)$response->getContent(), true)['data']['list'];
        $byId = array_column($list, null, 'id');
        self::assertTrue($byId[511]['can_restore']);
        self::assertFalse($byId[512]['can_restore']);
        self::assertFalse($byId[513]['can_restore']);
        self::assertFalse($byId[514]['can_restore']);
        self::assertFalse($byId[515]['can_restore']);
        self::assertFalse($byId[516]['can_restore']);
        foreach ($list as $row) {
            self::assertArrayNotHasKey('before_json', $row);
        }

        $superUser = new class {
            public int $id = 1;
            public ?int $hotel_id = null;

            public function isSuperAdmin(): bool
            {
                return true;
            }
        };
        $superResponse = $this->controller($superUser, ['page_size' => 10])->correctionLedger();
        $superRows = array_column(json_decode((string)$superResponse->getContent(), true)['data']['list'], null, 'id');
        self::assertTrue($superRows[511]['can_restore']);
        self::assertFalse($superRows[512]['can_restore']);
        self::assertFalse($superRows[513]['can_restore']);
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
