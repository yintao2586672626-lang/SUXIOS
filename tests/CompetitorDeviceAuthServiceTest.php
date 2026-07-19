<?php
declare(strict_types=1);

namespace Tests;

use app\model\CompetitorDevice;
use app\service\CompetitorDeviceAuthService;
use PHPUnit\Framework\TestCase;
use think\App;

final class CompetitorDeviceAuthServiceTest extends TestCase
{
    private static ?App $app = null;

    public static function setUpBeforeClass(): void
    {
        self::$app = new App(dirname(__DIR__));
        self::$app->initialize();
    }

    public function testIssuedCredentialIsOneWayAndRejectsAnotherToken(): void
    {
        $service = new CompetitorDeviceAuthService();
        $credential = $service->issueCredential();

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $credential['token']);
        self::assertNotSame($credential['token'], $credential['hash']);
        self::assertStringEndsWith(substr($credential['token'], -8), $credential['hint']);
        self::assertTrue($service->verifyTokenHash($credential['token'], $credential['hash']));
        self::assertFalse($service->verifyTokenHash(str_repeat('0', 64), $credential['hash']));
        self::assertFalse($service->verifyTokenHash($credential['token'], ''));
    }

    public function testBindingCannotAuthorizeAnotherTenantStoreOrPlatform(): void
    {
        $binding = new CompetitorDevice();
        $binding->tenant_id = 12;
        $binding->store_id = 80;
        $binding->platform = 'ctrip';
        $service = new CompetitorDeviceAuthService();

        self::assertTrue($service->bindingMatchesTarget($binding, 12, 80, 'ctrip'));
        self::assertFalse($service->bindingMatchesTarget($binding, 13, 80, 'ctrip'));
        self::assertFalse($service->bindingMatchesTarget($binding, 12, 81, 'ctrip'));
        self::assertFalse($service->bindingMatchesTarget($binding, 12, 80, 'meituan'));
    }

    public function testSensitiveHashIsHiddenFromModelSerialization(): void
    {
        $binding = new CompetitorDevice();
        $binding->device_id = 'fixture-device';
        $binding->token_hash = 'must-not-leak';
        $binding->token_hint = '…12345678';

        $serialized = $binding->toArray();
        self::assertArrayNotHasKey('token_hash', $serialized);
        self::assertSame('…12345678', $serialized['token_hint']);
    }
}
