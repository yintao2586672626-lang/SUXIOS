<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaP0ScopeProjectionService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OtaP0ScopeProjectionServiceTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App(dirname(__DIR__));
        $app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'ota_p0_scope_projection_' . getmypid() . '.sqlite';

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

        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, status INTEGER NOT NULL)');
        Db::execute('CREATE TABLE ota_profile_bindings (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL, platform VARCHAR(30), binding_status VARCHAR(30))');
        Db::execute('CREATE TABLE online_daily_data (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL, source VARCHAR(30), platform VARCHAR(30), compare_type VARCHAR(30), dimension VARCHAR(255), data_date DATE NOT NULL, data_period VARCHAR(30), data_type VARCHAR(30), raw_data TEXT)');

        Db::name('hotels')->insertAll([
            ['id' => 20, 'tenant_id' => 10, 'status' => 1],
            ['id' => 21, 'tenant_id' => 10, 'status' => 1],
            ['id' => 22, 'tenant_id' => 99, 'status' => 1],
        ]);
        Db::name('ota_profile_bindings')->insertAll([
            ['id' => 1, 'tenant_id' => 10, 'system_hotel_id' => 20, 'platform' => 'ctrip', 'binding_status' => 'active'],
            ['id' => 2, 'tenant_id' => 10, 'system_hotel_id' => 21, 'platform' => 'ctrip', 'binding_status' => 'active'],
            ['id' => 3, 'tenant_id' => 99, 'system_hotel_id' => 20, 'platform' => 'ctrip', 'binding_status' => 'active'],
        ]);
        Db::name('online_daily_data')->insertAll([
            $this->trafficRow(1, 10, 20, '2026-07-31', 'self', 'catalog:business_overview:business_flow_transform:list_exposure', 'business_flow_transform'),
            $this->trafficRow(2, 10, 20, '2026-07-31', '', '', ''),
            $this->trafficRow(3, 10, 20, '2026-07-31', 'competitor_avg', '', ''),
            $this->trafficRow(4, 10, 20, '2026-07-31', 'self', 'catalog:business_overview:traffic_rank_snapshot:rank', 'traffic_rank_snapshot'),
            $this->trafficRow(5, 10, 21, '2026-07-31', 'self', 'catalog:business_overview:business_flow_transform:list_exposure', 'business_flow_transform'),
            $this->trafficRow(6, 99, 20, '2026-07-31', 'self', 'catalog:business_overview:business_flow_transform:list_exposure', 'business_flow_transform'),
            $this->trafficRow(7, 10, 20, '2026-07-31', 'self', 'catalog:business_overview:business_flow_transform:list_exposure', 'business_flow_transform', 'forecast'),
            $this->trafficRow(8, 10, 20, '2026-07-30', 'self', 'catalog:business_overview:business_flow_transform:list_exposure', 'business_flow_transform'),
        ]);
    }

    public function testProjectionCountsOnlyTargetTenantHotelOwnAuthoritativeRows(): void
    {
        $projection = (new OtaP0ScopeProjectionService())->project('ctrip', '2026-07-31', 20, 10);

        self::assertSame('ready', $projection['status']);
        self::assertSame(2, $projection['own_traffic_row_count']);
        self::assertSame([20], $projection['stored_traffic_hotel_ids']);
        self::assertSame([20], $projection['profile_binding_hotel_ids']);
        self::assertFalse($projection['sensitive_values_exposed']);
    }

    public function testHotelScopedProjectionFailsClosedWithoutTenant(): void
    {
        $projection = (new OtaP0ScopeProjectionService())->project('ctrip', '2026-07-31', 20, null);

        self::assertSame('scope_unavailable', $projection['status']);
        self::assertSame(0, $projection['own_traffic_row_count']);
        self::assertSame([], $projection['stored_traffic_hotel_ids']);
        self::assertSame([], $projection['profile_binding_hotel_ids']);
    }

    /** @return array<string, mixed> */
    private function trafficRow(
        int $id,
        int $tenantId,
        int $systemHotelId,
        string $date,
        string $compareType,
        string $dimension,
        string $endpointId,
        ?string $dataPeriod = null
    ): array {
        return [
            'id' => $id,
            'tenant_id' => $tenantId,
            'system_hotel_id' => $systemHotelId,
            'source' => 'ctrip',
            'platform' => 'ctrip',
            'compare_type' => $compareType,
            'dimension' => $dimension,
            'data_date' => $date,
            'data_period' => $dataPeriod,
            'data_type' => 'traffic',
            'raw_data' => $endpointId === '' ? '{}' : json_encode(['endpoint_id' => $endpointId]),
        ];
    }
}
