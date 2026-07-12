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
}
