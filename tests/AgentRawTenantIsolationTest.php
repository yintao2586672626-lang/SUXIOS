<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Agent;
use app\service\RevenuePricingRecommendationService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class AgentRawTenantIsolationTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App(dirname(__DIR__));
        $app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'agent_raw_tenant_' . getmypid() . '.sqlite';

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

        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name VARCHAR(100), status INTEGER)');
        Db::execute('CREATE TABLE online_daily_data (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL, data_source_id INTEGER, data_date DATE NOT NULL, source VARCHAR(50), data_type VARCHAR(50), amount DECIMAL(12,2), quantity INTEGER, book_order_num INTEGER, list_exposure INTEGER, detail_exposure INTEGER, order_filling_num INTEGER, order_submit_num INTEGER, readback_verified INTEGER, validation_status VARCHAR(30), raw_data TEXT, create_time DATETIME, update_time DATETIME)');
        Db::execute('CREATE TABLE platform_data_sources (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL, platform VARCHAR(30), config_json TEXT)');

        Db::name('hotels')->insert(['id' => 20, 'tenant_id' => 10, 'name' => 'Tenant 10 Hotel', 'status' => 1]);
        Db::name('online_daily_data')->insertAll([
            [
                'id' => 1,
                'tenant_id' => 10,
                'system_hotel_id' => 20,
                'data_source_id' => 1001,
                'data_date' => '2026-07-21',
                'source' => 'ctrip',
                'data_type' => 'traffic',
                'amount' => 100,
                'quantity' => 10,
                'book_order_num' => 5,
                'list_exposure' => 1000,
                'detail_exposure' => 100,
                'order_filling_num' => 20,
                'order_submit_num' => 10,
                'readback_verified' => 1,
                'validation_status' => 'verified',
                'raw_data' => '{}',
            ],
            [
                'id' => 2,
                'tenant_id' => 99,
                'system_hotel_id' => 20,
                'data_source_id' => 2002,
                'data_date' => '2026-07-21',
                'source' => 'ctrip',
                'data_type' => 'traffic',
                'amount' => 9999,
                'quantity' => 999,
                'book_order_num' => 999,
                'list_exposure' => 99999,
                'detail_exposure' => 9999,
                'order_filling_num' => 999,
                'order_submit_num' => 999,
                'readback_verified' => 1,
                'validation_status' => 'verified',
                'raw_data' => '{}',
            ],
        ]);
        Db::name('platform_data_sources')->insertAll([
            [
                'id' => 1001,
                'tenant_id' => 10,
                'system_hotel_id' => 20,
                'platform' => 'ctrip',
                'config_json' => json_encode(['ota_hotel_id' => 'valid-ota'], JSON_THROW_ON_ERROR),
            ],
            [
                'id' => 2002,
                'tenant_id' => 99,
                'system_hotel_id' => 20,
                'platform' => 'ctrip',
                'config_json' => json_encode(['ota_hotel_id' => 'polluted-ota'], JSON_THROW_ON_ERROR),
            ],
        ]);
    }

    public function testAgentDiagnosisAndPricingExcludeWrongTenantRowsSharingTheHotelId(): void
    {
        $agent = (new ReflectionClass(Agent::class))->newInstanceWithoutConstructor();

        $diagnosis = $this->method(Agent::class, 'queryOtaDiagnosisData')->invoke(
            $agent,
            20,
            '',
            '',
            'ctrip',
            '2026-07-21',
            '2026-07-21',
            'traffic'
        );
        self::assertSame([1], array_map('intval', array_column($diagnosis['online_rows'], 'id')));
        self::assertSame(100.0, (float)$diagnosis['online_rows'][0]['amount']);

        $ownOtaIds = $this->method(Agent::class, 'otaDiagnosisOwnPlatformHotelIds')->invoke(
            $agent,
            [['data_source_id' => 1001], ['data_source_id' => 2002]],
            20,
            'ctrip'
        );
        self::assertSame(['valid-ota'], $ownOtaIds);

        $pricing = new RevenuePricingRecommendationService();
        $trafficRows = $this->method(RevenuePricingRecommendationService::class, 'ctripTrafficRows')->invoke(
            $pricing,
            20,
            '2026-07-21',
            '2026-07-21'
        );
        self::assertSame([1], array_map('intval', array_column($trafficRows, 'id')));
        self::assertSame(1000, (int)$trafficRows[0]['list_exposure']);
    }

    private function method(string $class, string $name): ReflectionMethod
    {
        $method = new ReflectionMethod($class, $name);
        $method->setAccessible(true);
        return $method;
    }
}
