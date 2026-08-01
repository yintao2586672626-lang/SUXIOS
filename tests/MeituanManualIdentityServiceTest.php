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

    public function testOrderFlowUsesStoredPartnerAndPoiIdentity(): void
    {
        $identity = (new MeituanManualIdentityService())->resolve([], [
            'partner_id' => '5006692',
            'poi_id' => '1010341153889311',
        ], 'order_flow');

        self::assertSame('5006692', $identity['partner_id']);
        self::assertSame('1010341153889311', $identity['poi_id']);
    }

    public function testManualFetchControllerUsesStoredIdentityBeforeTrafficOrdersAndAdsRequests(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__) . '/app/controller/concern/OnlineDataManualFetchConcern.php'
        );

        self::assertStringContainsString('use app\\service\\MeituanManualIdentityService;', $source);
        self::assertStringContainsString("->resolve(\$requestData, \$storedConfig, 'traffic')", $source);
        self::assertStringContainsString("->resolve(\$requestData, \$storedConfig, 'order_flow')", $source);
        self::assertStringContainsString('->resolve($requestData, $storedConfig, $section)', $source);
    }

    public function testCapturedPayloadRejectsPoiOutsideStoredProfileConfig(): void
    {
        $service = new MeituanManualIdentityService();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('meituan_platform_identity_mismatch');

        $service->resolveCapturedPayloadIdentity([
            'store_id' => 'store-80',
            'poi_id' => 'WRONG-POI',
        ], [
            'store_id' => 'store-80',
            'poi_id' => 'poi-80',
        ]);
    }

    public function testCapturedPayloadUsesStoredProfileIdentity(): void
    {
        $identity = (new MeituanManualIdentityService())->resolveCapturedPayloadIdentity([
            'store_id' => 'store-80',
        ], [
            'store_id' => 'store-80',
            'poi_id' => 'poi-80',
            'shop_id' => 'shop-80',
        ]);

        self::assertSame([
            'store_id' => 'store-80',
            'poi_id' => 'poi-80',
            'shop_id' => 'shop-80',
        ], $identity);
    }

    public function testCapturedPayloadRequiresItsOwnPlatformIdentity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('meituan_captured_payload_identity_missing');

        (new MeituanManualIdentityService())->resolveCapturedPayloadIdentity([], [
            'store_id' => 'store-80',
            'poi_id' => 'poi-80',
        ]);
    }

    public function testPoiOnlyLegacyProfileConfigProvidesCanonicalStoreIdentity(): void
    {
        $identity = (new MeituanManualIdentityService())->resolveCapturedPayloadIdentity([
            'poi_id' => 'poi-80',
        ], [
            'poiId' => 'poi-80',
        ]);

        self::assertSame([
            'store_id' => 'poi-80',
            'poi_id' => 'poi-80',
            'shop_id' => 'poi-80',
        ], $identity);
    }

    public function testPayloadPoiCanValidateProfileWhoseBindingKeyDiffersFromPoi(): void
    {
        $identity = (new MeituanManualIdentityService())->resolveCapturedPayloadIdentity([
            'poi_id' => 'poi-80',
        ], [
            'profile_binding_key' => 'profile-80',
            'poi_id' => 'poi-80',
        ]);

        self::assertSame('profile-80', $identity['store_id']);
        self::assertSame('poi-80', $identity['poi_id']);
    }

    public function testCapturedSaveControllerUsesAuthoritativeProfileIdentityBeforeBuildingRows(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__) . '/app/controller/concern/OnlineDataRequestConcern.php'
        );

        $resolvePosition = strpos($source, 'resolveMeituanCapturedProfileIdentity(');
        $buildPosition = strpos($source, 'buildMeituanCapturedDailyRows($payload, $systemHotelId)');

        self::assertNotFalse($resolvePosition);
        self::assertNotFalse($buildPosition);
        self::assertLessThan($buildPosition, $resolvePosition);
        self::assertStringContainsString('assertOtaProfileBindingForHotel', $source);
        self::assertStringContainsString('loadProfileSessionSource', $source);
    }

    public function testCapturedSaveSeparatesManualConfigBindingFromProfileBinding(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__) . '/app/controller/concern/OnlineDataRequestConcern.php'
        );

        self::assertStringContainsString('isMeituanManualCapturedPayload(', $source);
        self::assertStringContainsString('resolveMeituanCapturedManualIdentity(', $source);
        self::assertStringContainsString('resolveMeituanManualFetchConfigMetadata(', $source);
    }
}
