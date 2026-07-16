<?php
declare(strict_types=1);

namespace Tests;

use app\service\MeituanOnlineDataPersistenceService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class MeituanOnlineDataPersistenceServiceTest extends TestCase
{
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
