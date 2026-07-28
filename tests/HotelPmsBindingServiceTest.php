<?php
declare(strict_types=1);

namespace tests;

use app\service\HotelPmsBindingService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class HotelPmsBindingServiceTest extends TestCase
{
    private static array $databaseConfig;
    private static string $databasePath;

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$databaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir()
            . '/hotel_pms_binding_' . getmypid() . '.sqlite';
        @unlink(self::$databasePath);
        $config = self::$databaseConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$databasePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($config, 'database');
        Db::connect(null, true);
        self::createSchema();
    }

    public static function tearDownAfterClass(): void
    {
        Config::set(self::$databaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$databasePath);
    }

    protected function setUp(): void
    {
        Db::name('dingdandao_pms_integrations')->delete(true);
        Db::name('meituan_cloud_pms_integrations')->delete(true);
    }

    public function testSelectingDingdandaoLeavesExactlyOneActivePms(): void
    {
        $result = (new HotelPmsBindingService())->save(8, 80, 7, [
            'provider' => HotelPmsBindingService::PROVIDER_DINGDANDAO,
            'provider_hotel_id' => 'dd-80',
            'provider_hotel_name' => '敦煌漠蓝新',
        ]);

        self::assertSame('configured', $result['binding_status']);
        self::assertSame(
            HotelPmsBindingService::PROVIDER_DINGDANDAO,
            $result['selected_provider']
        );
        self::assertSame(
            1,
            (int)Db::name('dingdandao_pms_integrations')->value('status')
        );
        self::assertSame(
            0,
            (int)Db::name('meituan_cloud_pms_integrations')->value('status')
        );
    }

    public function testSwitchingToMeituanDisablesDingdandaoAutoPushButKeepsItsIdentity(): void
    {
        $service = new HotelPmsBindingService();
        $service->save(8, 80, 7, [
            'provider' => HotelPmsBindingService::PROVIDER_DINGDANDAO,
            'provider_hotel_id' => 'dd-80',
            'provider_hotel_name' => '敦煌漠蓝新',
        ]);
        Db::name('dingdandao_pms_integrations')
            ->where('tenant_id', 8)
            ->where('hotel_id', 80)
            ->update([
                'robot_id' => 91,
                'auto_push_enabled' => 1,
            ]);

        $result = $service->save(8, 80, 7, [
            'provider' => HotelPmsBindingService::PROVIDER_MEITUAN_CLOUD,
            'provider_hotel_id' => 'mt-80',
            'provider_hotel_name' => '敦煌漠蓝新',
        ]);
        $dingdandao = Db::name('dingdandao_pms_integrations')
            ->where('tenant_id', 8)
            ->where('hotel_id', 80)
            ->find();

        self::assertSame(
            HotelPmsBindingService::PROVIDER_MEITUAN_CLOUD,
            $result['selected_provider']
        );
        self::assertSame(0, (int)$dingdandao['status']);
        self::assertSame(0, (int)$dingdandao['auto_push_enabled']);
        self::assertSame(91, (int)$dingdandao['robot_id']);
        self::assertSame('dd-80', $dingdandao['provider_hotel_id']);
    }

    public function testLegacyDoubleEnabledRowsAreReportedAsConflictWithoutPreferredSource(): void
    {
        $this->insertDingdandao(['status' => 1]);
        $this->insertMeituan(['status' => 1]);

        $result = (new HotelPmsBindingService())->status(8, 80, 7);

        self::assertSame('conflict', $result['binding_status']);
        self::assertNull($result['selected_provider']);
        self::assertNull($result['selected_source']);
        self::assertSame(
            'hotel_pms_multiple_sources_enabled',
            $result['blockers'][0]['code']
        );
    }

    public function testNoneDisablesBothProvidersWithoutDeletingHistory(): void
    {
        $this->insertDingdandao([
            'status' => 1,
            'provider_hotel_id' => 'dd-80',
            'provider_hotel_name' => '敦煌漠蓝新',
        ]);
        $this->insertMeituan([
            'status' => 0,
            'provider_hotel_id' => 'mt-80',
            'provider_hotel_name' => '敦煌漠蓝新',
        ]);

        $result = (new HotelPmsBindingService())->save(8, 80, 7, [
            'provider' => HotelPmsBindingService::PROVIDER_NONE,
        ]);

        self::assertSame('unconfigured', $result['binding_status']);
        self::assertSame(
            'dd-80',
            Db::name('dingdandao_pms_integrations')->value('provider_hotel_id')
        );
        self::assertSame(
            'mt-80',
            Db::name('meituan_cloud_pms_integrations')->value('provider_hotel_id')
        );
        self::assertSame(
            0,
            (int)Db::name('dingdandao_pms_integrations')->value('status')
        );
        self::assertSame(
            0,
            (int)Db::name('meituan_cloud_pms_integrations')->value('status')
        );
    }

    public function testSelectionSummariesReturnOneCompactLabelPerHotel(): void
    {
        $this->insertDingdandao(['hotel_id' => 80, 'status' => 1]);
        $this->insertMeituan(['hotel_id' => 81, 'status' => 1]);
        $this->insertDingdandao(['hotel_id' => 82, 'status' => 1]);
        $this->insertMeituan(['hotel_id' => 82, 'status' => 1]);

        $summaries = (new HotelPmsBindingService())->selectionSummaries([
            80,
            81,
            82,
            83,
        ]);

        self::assertSame('订单来了 PMS', $summaries[80]['selected_provider_label']);
        self::assertSame('美团云 PMS', $summaries[81]['selected_provider_label']);
        self::assertSame('PMS 配置冲突', $summaries[82]['selected_provider_label']);
        self::assertSame('未配置 PMS', $summaries[83]['selected_provider_label']);
    }

    /** @param array<string,mixed> $override */
    private function insertDingdandao(array $override = []): void
    {
        Db::name('dingdandao_pms_integrations')->insert($override + [
            'tenant_id' => 8,
            'hotel_id' => 80,
            'provider' => HotelPmsBindingService::PROVIDER_DINGDANDAO,
            'source_url' => 'https://www.dingdandao.com/',
            'status' => 0,
            'auto_push_enabled' => 0,
            'created_by' => 7,
            'updated_by' => 7,
            'create_time' => '2026-07-28 12:00:00',
            'update_time' => '2026-07-28 12:00:00',
        ]);
    }

    /** @param array<string,mixed> $override */
    private function insertMeituan(array $override = []): void
    {
        Db::name('meituan_cloud_pms_integrations')->insert($override + [
            'tenant_id' => 8,
            'hotel_id' => 80,
            'provider' => HotelPmsBindingService::PROVIDER_MEITUAN_CLOUD,
            'source_url' => 'https://pms.meituan.com/#qk-workbench',
            'status' => 0,
            'created_by' => 7,
            'updated_by' => 7,
            'create_time' => '2026-07-28 12:00:00',
            'update_time' => '2026-07-28 12:00:00',
        ]);
    }

    private static function createSchema(): void
    {
        Db::execute(
            'CREATE TABLE dingdandao_pms_integrations ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, provider TEXT, '
            . 'provider_hotel_id TEXT NULL, provider_hotel_name TEXT NULL, source_url TEXT, '
            . 'robot_id INTEGER NULL, status INTEGER, auto_push_enabled INTEGER, '
            . 'last_capture_id INTEGER NULL, last_capture_business_date TEXT NULL, '
            . 'last_capture_status TEXT NULL, last_readback_status TEXT NULL, '
            . 'last_push_business_date TEXT NULL, last_push_status TEXT NULL, last_push_at TEXT NULL, '
            . 'last_push_error TEXT NULL, created_by INTEGER NULL, updated_by INTEGER NULL, '
            . 'create_time TEXT, update_time TEXT, UNIQUE (tenant_id, hotel_id, provider))'
        );
        Db::execute(
            'CREATE TABLE meituan_cloud_pms_integrations ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, provider TEXT, '
            . 'provider_hotel_id TEXT NULL, provider_hotel_name TEXT NULL, source_url TEXT, status INTEGER, '
            . 'last_capture_id INTEGER NULL, last_capture_business_date TEXT NULL, '
            . 'last_capture_status TEXT NULL, last_readback_status TEXT NULL, '
            . 'created_by INTEGER NULL, updated_by INTEGER NULL, create_time TEXT, update_time TEXT, '
            . 'UNIQUE (tenant_id, hotel_id, provider))'
        );
    }
}
