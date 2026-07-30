<?php
declare(strict_types=1);

namespace Tests;

use app\service\HotelDataMergeService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class HotelDataMergeTenantTest extends TestCase
{
    /** @var array<string, mixed> */
    private static array $originalDatabaseConfig = [];

    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hotel_data_merge_tenant_' . getmypid() . '.sqlite';

        $config = self::$originalDatabaseConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$sqlitePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($config, 'database');
    }

    public static function tearDownAfterClass(): void
    {
        try {
            Db::connect('sqlite')->close();
        } catch (\Throwable) {
        }
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        if (self::$sqlitePath !== '' && is_file(self::$sqlitePath)) {
            @unlink(self::$sqlitePath);
        }
    }

    protected function setUp(): void
    {
        try {
            Db::connect('sqlite')->close();
        } catch (\Throwable) {
        }
        if (is_file(self::$sqlitePath)) {
            @unlink(self::$sqlitePath);
        }
        Db::connect(null, true);
    }

    public function testSameTenantMultipleHotelMergeRetargetsBusinessTenantFromHotelMapping(): void
    {
        $this->createSchema(true);
        $this->seedSameTenantHotels();
        Db::name('daily_reports')->insert([
            'id' => 1001,
            'tenant_id' => 11,
            'hotel_id' => 11,
            'label' => 'legacy source row',
        ]);
        $service = new SingleTableHotelDataMergeService();

        $result = $service->execute(11, 22, $service->confirmationText(11, 22));

        $row = Db::name('daily_reports')->where('id', 1001)->find();
        self::assertIsArray($row);
        self::assertSame(22, (int)$row['hotel_id']);
        self::assertSame(501, (int)$row['tenant_id']);
        self::assertSame('legacy source row', $row['label']);
        self::assertSame(1, Db::name('daily_reports')->count());
        self::assertSame(1, (int)$result['updated_total']);
    }

    public function testMissingHotelsTenantColumnRejectsBeforeAnyBusinessWrite(): void
    {
        $this->createSchema(false);
        $this->seedHotelsWithoutTenant();
        Db::name('daily_reports')->insert([
            'id' => 1002,
            'tenant_id' => 11,
            'hotel_id' => 11,
            'label' => 'must remain unchanged',
        ]);
        $before = Db::name('daily_reports')->where('id', 1002)->find();
        $service = new SingleTableHotelDataMergeService();

        try {
            $service->execute(11, 22, $service->confirmationText(11, 22));
            self::fail('Missing hotels.tenant_id must reject merge.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('hotels.tenant_id', $exception->getMessage());
        }

        self::assertSame($before, Db::name('daily_reports')->where('id', 1002)->find());
    }

    public function testHotelsTenantMetadataProbeFailureRejectsBeforeAnyBusinessWrite(): void
    {
        $this->createSchema(true);
        $this->seedSameTenantHotels();
        Db::name('daily_reports')->insert([
            'id' => 1003,
            'tenant_id' => 11,
            'hotel_id' => 11,
            'label' => 'metadata failure row',
        ]);
        $before = Db::name('daily_reports')->where('id', 1003)->find();
        $service = new FailingTenantProbeHotelDataMergeService();

        try {
            $service->execute(11, 22, $service->confirmationText(11, 22));
            self::fail('Tenant metadata failure must reject merge.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('元数据探测失败', $exception->getMessage());
        }

        self::assertSame($before, Db::name('daily_reports')->where('id', 1003)->find());
    }

    private function createSchema(bool $withTenantId): void
    {
        $tenantColumn = $withTenantId ? 'tenant_id INTEGER NOT NULL,' : '';
        Db::execute("CREATE TABLE hotels (
            id INTEGER PRIMARY KEY,
            {$tenantColumn}
            name VARCHAR(100) NOT NULL,
            code VARCHAR(50),
            status INTEGER NOT NULL DEFAULT 1,
            update_time DATETIME
        )");
        Db::execute('CREATE TABLE daily_reports (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER,
            hotel_id INTEGER,
            label VARCHAR(100),
            update_time DATETIME
        )');
    }

    private function seedSameTenantHotels(): void
    {
        Db::name('hotels')->insertAll([
            ['id' => 11, 'tenant_id' => 501, 'name' => 'Tenant A hotel 1', 'code' => 'A-1', 'status' => 1],
            ['id' => 22, 'tenant_id' => 501, 'name' => 'Tenant A hotel 2', 'code' => 'A-2', 'status' => 1],
        ]);
    }

    private function seedHotelsWithoutTenant(): void
    {
        Db::name('hotels')->insertAll([
            ['id' => 11, 'name' => 'Legacy hotel 1', 'code' => 'L-1', 'status' => 1],
            ['id' => 22, 'name' => 'Legacy hotel 2', 'code' => 'L-2', 'status' => 1],
        ]);
    }
}

class SingleTableHotelDataMergeService extends HotelDataMergeService
{
    public function migrationPlans(): array
    {
        return [[
            'table' => 'daily_reports',
            'column' => 'hotel_id',
            'label' => '经营日报',
            'scope' => 'operation',
        ]];
    }
}

final class FailingTenantProbeHotelDataMergeService extends SingleTableHotelDataMergeService
{
    protected function probeTableColumn(string $table, string $column): bool
    {
        if ($table === 'hotels' && $column === 'tenant_id') {
            throw new RuntimeException('simulated metadata failure');
        }

        return parent::probeTableColumn($table, $column);
    }
}
