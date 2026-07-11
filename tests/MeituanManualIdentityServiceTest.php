<?php
declare(strict_types=1);

namespace Tests;

use app\service\MeituanManualIdentityService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MeituanManualIdentityServiceTest extends TestCase
{
    public function testRejectsRequestPoiOutsideStoredHotelConfig(): void
    {
        self::assertTrue(class_exists(MeituanManualIdentityService::class));

        $service = new MeituanManualIdentityService();

        try {
            $service->resolve([
                'partner_id' => 'partner-80',
                'poi_id' => 'WRONG-POI',
            ], [
                'partner_id' => 'partner-80',
                'poi_id' => '1029642156589279',
            ], 'traffic');
            self::fail('Expected the mismatched Meituan POI to be rejected.');
        } catch (InvalidArgumentException $e) {
            self::assertSame(409, $e->getCode());
            self::assertSame('meituan_platform_identity_mismatch', $e->getMessage());
        }
    }

    public function testUsesStoredIdentityWhenRequestOmitsPlatformIds(): void
    {
        self::assertTrue(class_exists(MeituanManualIdentityService::class));

        $identity = (new MeituanManualIdentityService())->resolve([], [
            'partnerId' => 'partner-80',
            'poiId' => '1029642156589279',
            'shopId' => 'shop-80',
        ], 'ads');

        self::assertSame([
            'partner_id' => 'partner-80',
            'poi_id' => '1029642156589279',
            'shop_id' => 'shop-80',
        ], $identity);
    }

    public function testManualFetchControllerUsesStoredIdentityBeforeTrafficOrdersAndAdsRequests(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__) . '/app/controller/concern/OnlineDataManualFetchConcern.php'
        );

        self::assertStringContainsString('use app\\service\\MeituanManualIdentityService;', $source);
        self::assertStringContainsString("->resolve(\$requestData, \$storedConfig, 'traffic')", $source);
        self::assertStringContainsString('->resolve($requestData, $storedConfig, $section)', $source);
    }
}
