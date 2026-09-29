<?php
declare(strict_types=1);

namespace Tests;

use app\service\MeituanOnlineDataPersistenceService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use think\facade\Config;
use think\facade\Db;

final class MeituanOnlineDataPersistenceServiceTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testRankRetryDoesNotReassignForeignTenantRow(): void
    {
        Config::set(['default' => 'file', 'stores' => ['file' => [
            'type' => 'File', 'path' => (string)getenv('SUXIOS_CACHE_PATH'),
        ]]], 'cache');
        Config::set(['default' => 'file', 'channels' => ['file' => [
            'type' => 'File', 'path' => (string)getenv('SUXIOS_CACHE_PATH') . '/logs/',
        ]]], 'log');
        Config::set(['default' => 'rank_tenant', 'connections' => ['rank_tenant' => [
            'type' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'fields_strict' => false,
        ]]], 'database');
        Db::connect(null, true);
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL)');
        Db::execute('INSERT INTO hotels VALUES (80,8)');
        Db::execute('CREATE TABLE online_daily_data (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, system_hotel_id INTEGER,
            hotel_id TEXT, hotel_name TEXT, data_date TEXT, source TEXT,
            dimension TEXT, data_type TEXT, data_value REAL, amount REAL, quantity REAL,
            book_order_num REAL, comment_score REAL, qunar_comment_score REAL,
            raw_data TEXT, validation_status TEXT, validation_flags TEXT,
            readback_verified INTEGER DEFAULT 0, readback_verified_at TEXT
        )');
        $response = ['data' => ['peerRankData' => [[
            'dimName' => '入住间夜', 'aiMetricName' => 'P_RZ_NIGHT_COUNT',
            'roundRanks' => [[
                'poiId' => '8', 'poiName' => 'Synthetic Meituan Hotel',
                'date' => '2026-07-11 08:30:00', 'dataValue' => 9,
            ]],
        ]]]];
        $service = new MeituanOnlineDataPersistenceService();
        $context = ['rank_type' => 'P_RZ', 'date_range' => '1'];
        self::assertSame(1, $service->parseAndSaveMeituanData(
            $response, '2026-07-11', '2026-07-11', 80, $context
        ));
        $first = Db::name('online_daily_data')->where('source', 'meituan')->find();
        self::assertIsArray($first);
        $foreignId = (int)$first['id'];
        Db::name('online_daily_data')->where('id', $foreignId)->update([
            'tenant_id' => 9, 'data_value' => 999, 'readback_verified' => 0,
        ]);

        self::assertSame(1, $service->parseAndSaveMeituanData(
            $response, '2026-07-11', '2026-07-11', 80, $context
        ));
        $foreign = Db::name('online_daily_data')->where('id', $foreignId)->find();
        self::assertSame(9, (int)$foreign['tenant_id']);
        self::assertSame(999.0, (float)$foreign['data_value']);
        $trusted = Db::name('online_daily_data')->where('tenant_id', 8)->find();
        self::assertIsArray($trusted);
        self::assertNotSame($foreignId, (int)$trusted['id']);
        self::assertSame(1, (int)$trusted['readback_verified']);

        $corrected = $response;
        $corrected['data']['peerRankData'][0]['roundRanks'][0]['dataValue'] = 11;
        self::assertSame(1, $service->parseAndSaveMeituanData(
            $corrected, '2026-07-11', '2026-07-11', 80, $context
        ));
        $sameTenant = Db::name('online_daily_data')->where('tenant_id', 8)->find();
        self::assertSame((int)$trusted['id'], (int)$sameTenant['id']);
        self::assertSame(11.0, (float)$sameTenant['data_value']);
        self::assertSame(1, (int)$sameTenant['readback_verified']);
        self::assertSame(2, Db::name('online_daily_data')->count());
    }

    public function testBooleanVipTagIsPersistedAsVipPlatformTag(): void
    {
        $service = new MeituanOnlineDataPersistenceService();
        $method = new ReflectionMethod($service, 'extractMeituanPlatformTagInfo');
        $method->setAccessible(true);

        self::assertSame([
            'tags' => ['VIP'],
            'status' => 'returned',
        ], $method->invoke($service, ['vipTag' => true]));
        self::assertSame([
            'tags' => [],
            'status' => 'returned_empty',
        ], $method->invoke($service, ['vipTag' => false]));
    }

    public function testRankPersistenceIdentitySeparatesRankTypeAndDateRange(): void
    {
        $service = new MeituanOnlineDataPersistenceService();
        $method = new ReflectionMethod($service, 'buildRankStorageDimension');
        $method->setAccessible(true);

        $stayYesterday = $method->invoke($service, '入住间夜', 'P_RZ', '1', '2026-07-11', '2026-07-11');
        $salesYesterday = $method->invoke($service, '入住间夜', 'P_XS', '1', '2026-07-11', '2026-07-11');
        $staySevenDays = $method->invoke($service, '入住间夜', 'P_RZ', '7', '2026-07-05', '2026-07-11');

        self::assertNotSame($stayYesterday, $salesYesterday);
        self::assertNotSame($stayYesterday, $staySevenDays);
        self::assertStringContainsString('P_RZ', $stayYesterday);
        self::assertStringContainsString('range=1', $stayYesterday);

        $longDimension = $method->invoke(
            $service,
            str_repeat('超长榜单维度', 30),
            'P_RZ',
            'custom',
            '2026-06-01',
            '2026-06-30'
        );
        self::assertLessThanOrEqual(100, mb_strlen($longDimension));
    }

    public function testPercentOnlyRankKeepsDataValueNull(): void
    {
        $service = new MeituanOnlineDataPersistenceService();
        $method = new ReflectionMethod($service, 'buildRankMetricStorageValues');
        $method->setAccessible(true);

        $values = $method->invoke($service, null, true, false, false, false);

        self::assertNull($values['data_value']);
        self::assertNull($values['amount']);
        self::assertNull($values['quantity']);
    }

    public function testPersistenceFailureIsNotReportedAsEmptyResult(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__) . '/app/service/MeituanOnlineDataPersistenceService.php'
        );

        self::assertStringContainsString("throw new \\RuntimeException('meituan_rank_persistence_failed'", $source);
    }

    public function testRankCandidateReadbackRequiresEveryExpectedDatabaseRow(): void
    {
        $prototype = new MeituanOnlineDataPersistenceService();
        self::assertTrue(method_exists($prototype, 'verifyPersistedRankCandidate'));
        $dimensionMethod = new ReflectionMethod($prototype, 'buildRankStorageDimension');
        $dimensionMethod->setAccessible(true);
        $dimension = $dimensionMethod->invoke(
            $prototype,
            '入住间夜',
            'P_RZ',
            '1',
            '2026-07-11',
            '2026-07-11'
        );
        $responseData = [
            'data' => [
                'peerRankData' => [[
                    'dimName' => '入住间夜',
                    'aiMetricName' => 'P_RZ_NIGHT_COUNT',
                    'roundRanks' => [[
                        'poiId' => '8',
                        'poiName' => 'Meituan A',
                        'date' => '2026-07-11 08:30:00',
                        'dataValue' => 9,
                    ]],
                ]],
            ],
        ];
        $matchingRow = [
            'id' => 123,
            'tenant_id' => 44,
            'system_hotel_id' => 80,
            'hotel_id' => '8',
            'data_date' => '2026-07-11',
            'source' => 'meituan',
            'data_type' => 'peer_rank',
            'dimension' => $dimension,
            'readback_verified' => 1,
        ];

        $verified = (new MeituanOnlineDataPersistenceService(
            static fn(array $_scope): array => [$matchingRow],
            static fn(int $_systemHotelId): int => 44
        ))->verifyPersistedRankCandidate(
            $responseData,
            80,
            '2026-07-11',
            '2026-07-11',
            ['rank_type' => 'P_RZ', 'date_range' => '1']
        );
        self::assertTrue($verified['verified']);
        self::assertSame(1, $verified['expected_count']);
        self::assertSame(1, $verified['matched_count']);
        self::assertSame([123], $verified['row_ids']);

        $mismatch = (new MeituanOnlineDataPersistenceService(
            static fn(array $_scope): array => [[...$matchingRow, 'hotel_id' => 'wrong-poi']],
            static fn(int $_systemHotelId): int => 44
        ))->verifyPersistedRankCandidate(
            $responseData,
            80,
            '2026-07-11',
            '2026-07-11',
            ['rank_type' => 'P_RZ', 'date_range' => '1']
        );
        self::assertFalse($mismatch['verified']);
        self::assertSame('database_readback_mismatch', $mismatch['reason']);

        $tenantMismatch = (new MeituanOnlineDataPersistenceService(
            static fn(array $_scope): array => [[...$matchingRow, 'tenant_id' => 45]],
            static fn(int $_systemHotelId): int => 44
        ))->verifyPersistedRankCandidate(
            $responseData,
            80,
            '2026-07-11',
            '2026-07-11',
            ['rank_type' => 'P_RZ', 'date_range' => '1']
        );
        self::assertFalse($tenantMismatch['verified']);
        self::assertSame(0, $tenantMismatch['matched_count']);

        $unverified = (new MeituanOnlineDataPersistenceService(
            static fn(array $_scope): array => [[...$matchingRow, 'readback_verified' => 0]],
            static fn(int $_systemHotelId): int => 44
        ))->verifyPersistedRankCandidate(
            $responseData,
            80,
            '2026-07-11',
            '2026-07-11',
            ['rank_type' => 'P_RZ', 'date_range' => '1']
        );
        self::assertFalse($unverified['verified']);
        self::assertSame(0, $unverified['matched_count']);
    }
}
