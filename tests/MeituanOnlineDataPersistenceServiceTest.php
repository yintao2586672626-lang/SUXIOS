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
}
