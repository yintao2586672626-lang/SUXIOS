<?php
declare(strict_types=1);

namespace Tests;

use app\service\CtripPublicHotelProfileService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class CtripPublicHotelProfileBindingTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $databasePath = '';

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ctrip_public_profile_binding_' . getmypid() . '.sqlite';
        $database = self::$originalDatabaseConfig;
        $database['default'] = 'sqlite';
        $database['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$databasePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($database, 'database');
    }

    public static function tearDownAfterClass(): void
    {
        try {
            Db::connect('sqlite')->close();
        } catch (\Throwable) {
        }
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$databasePath);
    }

    protected function setUp(): void
    {
        try {
            Db::connect('sqlite')->close();
        } catch (\Throwable) {
        }
        @unlink(self::$databasePath);
        Db::connect(null, true);
        $this->createSchema();
        Db::name('hotels')->insertAll([
            ['id' => 10, 'tenant_id' => 1, 'name' => '系统本店', 'address' => '', 'update_time' => null],
            ['id' => 20, 'tenant_id' => 2, 'name' => '另一门店', 'address' => '', 'update_time' => null],
        ]);
    }

    public function testIdOnlySelfBindingCollectsPersistsAndRequiresExplicitReplacement(): void
    {
        $service = $this->service();
        $result = $service->addByHotelId(10, '3456814', 'self', 91);

        self::assertSame('available', $result['status']);
        self::assertSame('self', $result['role']);
        self::assertSame('3456814', $result['binding']['ota_hotel_id']);
        self::assertSame('ctrip_public_binding', $result['binding']['source']);
        self::assertTrue($result['profile']['persistence']['readback_verified']);
        self::assertSame(88, $result['profile']['fields']['room_count']);
        self::assertSame('上海市测试路1号', Db::name('hotels')->where('id', 10)->value('address'));
        self::assertCount(1, $service->listProfiles(10));

        $stored = json_decode((string)Db::name('system_configs')
            ->where('config_key', 'ctrip_public_hotel_bindings')
            ->value('config_value'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('3456814', $stored['10']['ota_hotel_id']);
        self::assertSame(1, $stored['10']['tenant_id']);
        self::assertArrayNotHasKey('cookies', $stored['10']);

        try {
            $service->addByHotelId(10, '3456815', 'self', 91);
            self::fail('Replacing a self binding must require explicit confirmation.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('确认替换', $exception->getMessage());
        }

        $replaced = $service->addByHotelId(10, '3456815', 'self', 91, true);
        self::assertSame('3456815', $replaced['binding']['ota_hotel_id']);
        self::assertCount(1, $replaced['profiles']);
        self::assertSame('3456815', $replaced['profiles'][0]['ota_hotel_id']);
        self::assertSame('self', $replaced['profiles'][0]['role']);
        $archived = json_decode((string)Db::name('ota_ctrip_entity_snapshots')
            ->where('system_hotel_id', 10)
            ->where('entity_key', '3456814')
            ->value('attributes_json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('archived_self', $archived['role']);
        self::assertSame('3456815', $archived['replacement_ota_hotel_id']);
    }

    public function testIdOnlyCompetitorRemainsInBulkRefreshTargets(): void
    {
        $service = $this->service();
        $service->addByHotelId(10, '3456814', 'self', 91);
        $added = $service->addByHotelId(10, '4567890', 'competitor', 91);

        self::assertSame('available', $added['status']);
        self::assertSame('competitor', $added['role']);
        $index = Db::name('competitor_hotel')
            ->where('store_id', 10)
            ->where('platform', 'xc')
            ->where('hotel_code', '4567890')
            ->find();
        self::assertIsArray($index);
        self::assertSame(1, (int)$index['status']);
        self::assertSame('公开测试酒店', $index['hotel_name']);

        $bulk = $service->syncForHotel(10, 'competitors', 10, false);
        self::assertSame(1, $bulk['requested_count']);
        self::assertSame(1, $bulk['cached_count']);
        self::assertSame('4567890', $bulk['profiles'][0]['ota_hotel_id']);
    }

    public function testOfficialCompetitorIdUpgradesOneExactPublicNameTargetWithoutDuplicate(): void
    {
        $targetId = (int)Db::name('competitor_hotel')->insertGetId([
            'tenant_id' => 1,
            'store_id' => 10,
            'platform' => 'xc',
            'city' => '',
            'hotel_name' => ' 公开测试酒店 ',
            'hotel_code' => 'public-name:unique-test',
            'status' => 1,
        ]);

        $result = $this->service()->addByHotelId(10, '4567890', 'competitor', 91);

        self::assertSame('available', $result['status']);
        self::assertSame(1, (int)Db::name('competitor_hotel')->where('store_id', 10)->count());
        $target = Db::name('competitor_hotel')->where('id', $targetId)->find();
        self::assertIsArray($target);
        self::assertSame('4567890', $target['hotel_code']);
        self::assertSame('公开测试酒店', $target['hotel_name']);
    }

    public function testOfficialCompetitorIdDoesNotGuessBetweenDuplicatePublicNameTargets(): void
    {
        Db::name('competitor_hotel')->insertAll([
            [
                'tenant_id' => 1,
                'store_id' => 10,
                'platform' => 'xc',
                'city' => '',
                'hotel_name' => '公开测试酒店',
                'hotel_code' => 'public-name:ambiguous-a',
                'status' => 1,
            ],
            [
                'tenant_id' => 1,
                'store_id' => 10,
                'platform' => 'xc',
                'city' => '',
                'hotel_name' => ' 公开测试酒店 ',
                'hotel_code' => 'public-name:ambiguous-b',
                'status' => 1,
            ],
        ]);

        $this->service()->addByHotelId(10, '4567890', 'competitor', 91);

        self::assertSame(3, (int)Db::name('competitor_hotel')->where('store_id', 10)->count());
        self::assertSame(1, (int)Db::name('competitor_hotel')
            ->where('store_id', 10)
            ->where('hotel_code', '4567890')
            ->count());
        self::assertSame(2, (int)Db::name('competitor_hotel')
            ->where('store_id', 10)
            ->where('hotel_code', 'like', 'public-name:%')
            ->count());
    }

    public function testCollectionFailureStillKeepsCompetitorIdForRetry(): void
    {
        $service = $this->service(true);
        $result = $service->addByHotelId(10, '9999999', 'competitor', 91);

        self::assertSame('binding_saved_collection_failed', $result['status']);
        self::assertSame('collection_failed', $result['profile']['capture_status']);
        self::assertTrue($result['profile']['persistence']['readback_verified']);
        self::assertSame(1, (int)Db::name('competitor_hotel')
            ->where('store_id', 10)
            ->where('hotel_code', '9999999')
            ->value('status'));

        $retry = $service->syncForHotel(10, 'all', 30, true);
        self::assertSame(1, $retry['requested_count']);
        self::assertSame('9999999', $retry['profiles'][0]['ota_hotel_id']);
        self::assertSame('collection_failed', $retry['profiles'][0]['capture_status']);
    }

    public function testSameDayFailureCannotOverwriteTheLastSuccessfulProfile(): void
    {
        $success = $this->service()->addByHotelId(10, '3456814', 'competitor', 91);
        self::assertTrue($success['profile']['persistence']['readback_verified']);

        $failed = $this->service(true)->addByHotelId(10, '3456814', 'competitor', 91);
        self::assertSame('binding_saved_collection_failed', $failed['status']);
        self::assertFalse($failed['profile']['persistence']['readback_verified']);
        self::assertTrue($failed['profile']['persistence']['latest_success_preserved']);
        self::assertSame('latest_success_preserved', $failed['profile']['persistence']['persistence_status']);

        $stored = Db::name('ota_ctrip_entity_snapshots')
            ->where('system_hotel_id', 10)
            ->where('entity_key', '3456814')
            ->where('data_date', '2026-07-16')
            ->find();
        self::assertIsArray($stored);
        self::assertSame('available', $stored['capture_status']);
        self::assertSame(1, (int)$stored['tenant_id']);
        self::assertSame('available', $this->service()->listProfiles(10)[0]['capture_status']);
    }

    public function testProfileReadbackAndSourceValidationRemainSeparateAndHistoryCanBeListed(): void
    {
        $service = $this->service();
        $service->addByHotelId(10, '3456814', 'competitor', 91);
        $latest = Db::name('ota_ctrip_entity_snapshots')->where('system_hotel_id', 10)->find();
        self::assertIsArray($latest);
        $older = $latest;
        unset($older['id']);
        $older['data_date'] = '2026-07-15';
        $older['first_seen_at'] = '2026-07-15 20:00:00';
        $older['last_seen_at'] = '2026-07-15 20:00:00';
        $older['create_time'] = '2026-07-15 20:00:00';
        $older['update_time'] = '2026-07-15 20:00:00';
        Db::name('ota_ctrip_entity_snapshots')->insert($older);

        $current = $service->listProfiles(10);
        self::assertCount(1, $current);
        self::assertTrue($current[0]['persistence_readback_verified']);
        self::assertSame('source_observed', $current[0]['source_validation_status']);
        self::assertArrayNotHasKey('readback_verified', $current[0]);

        $history = $service->listProfiles(10, true);
        self::assertCount(2, $history);
        self::assertSame(['2026-07-16', '2026-07-15'], array_column($history, 'data_date'));
    }

    public function testStaleCaptureStatusSurvivesDatabaseReadback(): void
    {
        $service = $this->service();
        $service->addByHotelId(10, '3456814', 'competitor', 91);
        $row = Db::name('ota_ctrip_entity_snapshots')->where('system_hotel_id', 10)->find();
        self::assertIsArray($row);
        $attributes = json_decode((string)$row['attributes_json'], true);
        self::assertIsArray($attributes);
        $attributes['source_validation_status'] = 'stale';
        Db::name('ota_ctrip_entity_snapshots')->where('id', (int)$row['id'])->update([
            'capture_status' => 'stale',
            'attributes_json' => json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $profile = $service->listProfiles(10)[0];
        self::assertTrue($profile['persistence_readback_verified']);
        self::assertSame('stale', $profile['capture_status']);
        self::assertSame('stale', $profile['source_validation_status']);
    }

    private function service(bool $fail = false): CtripPublicHotelProfileService
    {
        $html = <<<'HTML'
<!doctype html><html><body>
<h1 aria-label="公开测试酒店">公开测试酒店</h1>
<div class="headInit_headInit-address_position"><span aria-label="上海市测试路1号">地址</span></div>
<span role="img" aria-label="4 out of 5 diamonds"></span>
<div class="reviewTop_reviewTop-score-container-ctrip" aria-label="4.8 out of 5"><em>4.8</em></div>
<ul data-test-id="hotelOverview-label"><li>开业：2018</li><li>装修：2023</li><li>客房数：88</li></ul>
<div id="fac_0" aria-label="免费停车场"></div>
</body></html>
HTML;
        $fetcher = static fn(string $url): array => $fail
            ? ['http_status' => 429, 'body' => '', 'final_url' => $url]
            : ['http_status' => 200, 'body' => $html, 'final_url' => $url];

        return new CtripPublicHotelProfileService($fetcher, static fn(): string => '2026-07-16 20:00:00');
    }

    private function createSchema(): void
    {
        foreach ([
            'CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT NOT NULL, address TEXT NULL, update_time TEXT NULL)',
            'CREATE TABLE system_configs (id INTEGER PRIMARY KEY AUTOINCREMENT, config_key TEXT NOT NULL UNIQUE, config_value TEXT NULL, description TEXT NULL, create_time TEXT NULL, update_time TEXT NULL)',
            'CREATE TABLE online_daily_data (id INTEGER PRIMARY KEY AUTOINCREMENT, system_hotel_id INTEGER NOT NULL, source TEXT NOT NULL, data_type TEXT NOT NULL, hotel_id TEXT NULL, hotel_name TEXT NULL, compare_type TEXT NULL, raw_data TEXT NULL, data_date TEXT NULL, update_time TEXT NULL)',
            'CREATE TABLE competitor_hotel (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NULL, store_id INTEGER NOT NULL, platform TEXT NOT NULL, city TEXT NOT NULL DEFAULT "", hotel_name TEXT NOT NULL DEFAULT "", hotel_code TEXT NOT NULL, status INTEGER NOT NULL DEFAULT 1, create_time TEXT NULL, update_time TEXT NULL, created_at TEXT NULL, updated_at TEXT NULL)',
            'CREATE TABLE ota_ctrip_entity_snapshots (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NULL, system_hotel_id INTEGER NOT NULL, ota_hotel_id TEXT NOT NULL, data_date TEXT NOT NULL, source TEXT NOT NULL, capture_section TEXT NULL, endpoint_id TEXT NULL, entity_type TEXT NOT NULL, entity_key TEXT NOT NULL, entity_name TEXT NULL, attributes_json TEXT NULL, capture_status TEXT NULL, first_seen_at TEXT NULL, last_seen_at TEXT NULL, create_time TEXT NULL, update_time TEXT NULL)',
        ] as $sql) {
            Db::execute($sql);
        }
    }
}
