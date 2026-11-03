<?php
declare(strict_types=1);

namespace tests;

use app\service\HotelPmsBindingService;
use app\service\DingdandaoPmsIntegrationService;
use app\service\MeituanCloudPmsIntegrationService;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testLegacyMeituanEnableCannotReplaceSelectedDingdandao(): void
    {
        $binding = new HotelPmsBindingService();
        $binding->save(8, 80, 7, [
            'provider' => HotelPmsBindingService::PROVIDER_DINGDANDAO,
            'provider_hotel_id' => 'dd-80',
            'provider_hotel_name' => 'Selected hotel',
        ]);
        $before = $this->integrationRows();

        try {
            (new MeituanCloudPmsIntegrationService())->save(8, 80, 7, [
                'status' => true,
                'provider_hotel_id' => 'mt-80',
                'provider_hotel_name' => 'Another binding',
            ]);
            self::fail('An integration edit must not enable a second PMS.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('hotel_pms_other_provider_enabled', $error->getMessage());
        }

        self::assertSame($before, $this->integrationRows());
        self::assertSame(HotelPmsBindingService::PROVIDER_DINGDANDAO, $binding->status(8, 80, 7)['selected_provider']);
    }

    public function testLegacyDingdandaoEnableCannotReplaceSelectedMeituan(): void
    {
        $binding = new HotelPmsBindingService();
        $binding->save(8, 80, 7, [
            'provider' => HotelPmsBindingService::PROVIDER_MEITUAN_CLOUD,
            'provider_hotel_id' => 'mt-80',
            'provider_hotel_name' => 'Selected hotel',
        ]);
        $before = $this->integrationRows();

        try {
            (new DingdandaoPmsIntegrationService())->save(8, 80, 7, [
                'status' => true,
                'provider_hotel_id' => 'dd-80',
                'provider_hotel_name' => 'Another binding',
            ]);
            self::fail('An integration edit must not enable a second PMS.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('hotel_pms_other_provider_enabled', $error->getMessage());
        }

        self::assertSame($before, $this->integrationRows());
        self::assertSame(HotelPmsBindingService::PROVIDER_MEITUAN_CLOUD, $binding->status(8, 80, 7)['selected_provider']);
    }

    public function testInactiveLegacyConfigurationEditPreservesSelectedProviderAndItsPolicy(): void
    {
        $binding = new HotelPmsBindingService();
        $binding->save(8, 80, 7, [
            'provider' => HotelPmsBindingService::PROVIDER_DINGDANDAO,
            'provider_hotel_name' => 'Selected hotel',
        ]);
        Db::name('dingdandao_pms_integrations')->where('tenant_id', 8)->where('hotel_id', 80)->update(['robot_id' => 91, 'auto_push_enabled' => 1]);
        $before = Db::name('dingdandao_pms_integrations')->find();

        (new MeituanCloudPmsIntegrationService())->save(8, 80, 7, [
            'status' => false,
            'provider_hotel_id' => 'mt-draft',
            'provider_hotel_name' => 'Inactive draft',
        ]);

        self::assertSame($before, Db::name('dingdandao_pms_integrations')->find());
        self::assertSame('mt-draft', Db::name('meituan_cloud_pms_integrations')->value('provider_hotel_id'));
        self::assertSame(HotelPmsBindingService::PROVIDER_DINGDANDAO, $binding->status(8, 80, 7)['selected_provider']);
    }

    public function testLegacyConfigurationCanEnableAnUnselectedProviderAndEditTheActiveOne(): void
    {
        $binding = new HotelPmsBindingService();
        $dingdandao = new DingdandaoPmsIntegrationService();
        $dingdandao->save(8, 80, 7, ['status' => true, 'provider_hotel_name' => 'Original DD']);
        self::assertSame(HotelPmsBindingService::PROVIDER_DINGDANDAO, $binding->status(8, 80, 7)['selected_provider']);

        $dingdandao->save(8, 80, 7, ['status' => true, 'provider_hotel_name' => 'Edited DD']);
        self::assertSame('Edited DD', Db::name('dingdandao_pms_integrations')->value('provider_hotel_name'));
        self::assertSame(0, (int)Db::name('meituan_cloud_pms_integrations')->value('status'));

        $binding->save(8, 80, 7, ['provider' => HotelPmsBindingService::PROVIDER_NONE]);
        (new MeituanCloudPmsIntegrationService())->save(8, 80, 7, ['status' => true, 'provider_hotel_name' => 'Selected MT']);
        self::assertSame(HotelPmsBindingService::PROVIDER_MEITUAN_CLOUD, $binding->status(8, 80, 7)['selected_provider']);
    }

    public function testInactiveDingdandaoEditPreservesSelectedMeituan(): void
    {
        $binding = new HotelPmsBindingService();
        $binding->save(8, 80, 7, ['provider' => HotelPmsBindingService::PROVIDER_MEITUAN_CLOUD, 'provider_hotel_name' => 'Selected MT']);
        $before = Db::name('meituan_cloud_pms_integrations')->find();

        (new DingdandaoPmsIntegrationService())->save(8, 80, 7, ['status' => false, 'provider_hotel_id' => 'dd-draft', 'provider_hotel_name' => 'Inactive DD']);

        self::assertSame($before, Db::name('meituan_cloud_pms_integrations')->find());
        self::assertSame('dd-draft', Db::name('dingdandao_pms_integrations')->value('provider_hotel_id'));
        self::assertSame(HotelPmsBindingService::PROVIDER_MEITUAN_CLOUD, $binding->status(8, 80, 7)['selected_provider']);
    }

    public function testIntegrationRowCreationFailureLeavesNoPartialProviderConfiguration(): void
    {
        Db::execute("CREATE TRIGGER reject_meituan_creation BEFORE INSERT ON meituan_cloud_pms_integrations BEGIN SELECT RAISE(ABORT, 'fixture_creation_rejected'); END");
        try {
            try {
                (new HotelPmsBindingService())->save(8, 80, 7, ['provider' => HotelPmsBindingService::PROVIDER_DINGDANDAO, 'provider_hotel_name' => 'Rejected creation']);
                self::fail('A failed companion-row creation must roll back the entire configuration.');
            } catch (\think\db\exception\PDOException $error) {
                self::assertStringContainsString('fixture_creation_rejected', $error->getMessage());
            }
            self::assertSame(['dingdandao' => [], 'meituan' => []], $this->integrationRows());
        } finally {
            Db::execute('DROP TRIGGER reject_meituan_creation');
        }
    }

    public function testLegacyEnableIgnoresAndPreservesOtherHotelAndTenantConfigurations(): void
    {
        $this->insertDingdandao(['tenant_id' => 8, 'hotel_id' => 81, 'status' => 1, 'provider_hotel_name' => 'Other hotel']);
        $this->insertDingdandao(['tenant_id' => 9, 'hotel_id' => 90, 'status' => 1, 'provider_hotel_name' => 'Other tenant']);
        $otherRows = Db::name('dingdandao_pms_integrations')->order('id')->select()->toArray();

        (new MeituanCloudPmsIntegrationService())->save(8, 80, 7, ['status' => true, 'provider_hotel_name' => 'Scoped hotel']);

        self::assertSame($otherRows, Db::name('dingdandao_pms_integrations')->whereIn('hotel_id', [81, 90])->order('id')->select()->toArray());
        self::assertSame(0, (int)Db::name('dingdandao_pms_integrations')->where('tenant_id', 8)->where('hotel_id', 80)->value('status'));
        self::assertSame(1, (int)Db::name('meituan_cloud_pms_integrations')->where('tenant_id', 8)->where('hotel_id', 80)->value('status'));
    }

    /** @return iterable<string,array{string,string,array<string,mixed>}> */
    public static function invalidDateRequests(): iterable
    {
        foreach (['binding', 'dingdandao', 'meituan'] as $entry) {
            yield $entry . '-business-date' => [$entry, 'not-a-date', []];
            yield $entry . '-target-date' => [$entry, '', ['target_date' => 'not-a-date']];
            yield $entry . '-impossible-date' => [$entry, '2026-02-30', []];
            yield $entry . '-target-type' => [$entry, '', ['target_date' => ['not-a-date']]];
            yield $entry . '-date-mismatch' => [$entry, '2026-07-28', ['target_date' => '2026-07-29']];
        }
    }

    #[DataProvider('invalidDateRequests')]
    public function testInvalidDateFailsBeforeChangingAnyProviderConfiguration(string $entry, string $businessDate, array $input): void
    {
        $binding = new HotelPmsBindingService();
        $binding->save(8, 80, 7, ['provider' => HotelPmsBindingService::PROVIDER_DINGDANDAO, 'provider_hotel_name' => 'Initial hotel']);
        $before = $this->integrationRows();
        $service = match ($entry) {
            'dingdandao' => new DingdandaoPmsIntegrationService(),
            'meituan' => new MeituanCloudPmsIntegrationService(),
            default => $binding,
        };
        try {
            $service->save(8, 80, 7, $input + [
                'provider' => HotelPmsBindingService::PROVIDER_MEITUAN_CLOUD,
                'provider_hotel_name' => 'Rejected change',
                'provider_hotel_id' => 'rejected-change',
                'status' => false,
            ], $businessDate);
            self::fail('Invalid dates must be rejected before any configuration write.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('date_invalid', $error->getMessage());
        }
        self::assertSame($before, $this->integrationRows());
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function integrationRows(): array
    {
        return [
            'dingdandao' => Db::name('dingdandao_pms_integrations')->order('id')->select()->toArray(),
            'meituan' => Db::name('meituan_cloud_pms_integrations')->order('id')->select()->toArray(),
        ];
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
        Db::execute('CREATE TABLE dingdandao_operating_target_captures (id INTEGER PRIMARY KEY, tenant_id INTEGER, hotel_id INTEGER, business_date TEXT)');
        Db::execute('CREATE TABLE meituan_cloud_pms_captures (id INTEGER PRIMARY KEY, tenant_id INTEGER, hotel_id INTEGER, business_date TEXT)');
        Db::execute('CREATE TABLE dingdandao_pms_push_dispatches (id INTEGER PRIMARY KEY, tenant_id INTEGER, hotel_id INTEGER)');
    }
}
