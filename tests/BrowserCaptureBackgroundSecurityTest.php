<?php
declare(strict_types=1);

namespace Tests;

use app\service\BrowserCaptureTaskExecutionService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BrowserCaptureBackgroundSecurityTest extends TestCase
{
    public function testBackgroundWhitelistNormalizesOnlyCredentialFreeUrls(): void
    {
        $safe = $this->sanitize([
            'system_hotel_id' => 7,
            'profile_id' => 'profile-7',
            'cdp_url' => 'http://127.0.0.1:9223/',
            'adsUrl' => 'https://ebmidas.dianping.com/business/home',
            'authorization' => 'Bearer must-not-persist',
            'cookies' => 'must-not-persist',
        ]);

        self::assertSame('http://127.0.0.1:9223', $safe['cdp_url']);
        self::assertSame('https://ebmidas.dianping.com/business/home', $safe['ads_url']);
        self::assertArrayNotHasKey('cdpUrl', $safe);
        self::assertArrayNotHasKey('adsUrl', $safe);
        self::assertArrayNotHasKey('authorization', $safe);
        self::assertArrayNotHasKey('cookies', $safe);
    }

    #[DataProvider('sensitiveUrlProvider')]
    public function testBackgroundWhitelistFailsClosedForSensitiveOrNonLoopbackUrls(array $request): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->sanitize($request);
    }

    /** @return iterable<string,array{0:array<string,mixed>}> */
    public static function sensitiveUrlProvider(): iterable
    {
        yield 'cdp query capability' => [['cdp_url' => 'http://127.0.0.1:9223/?token=secret']];
        yield 'cdp userinfo' => [['cdpUrl' => 'http://user:secret@127.0.0.1:9223']];
        yield 'cdp non-loopback' => [['cdp_url' => 'http://192.168.1.10:9223']];
        yield 'cdp fragment' => [['cdp_url' => 'http://127.0.0.1:9223/#secret']];
        yield 'ads query token' => [['ads_url' => 'https://ebmidas.dianping.com/business/home?token=secret']];
        yield 'ads userinfo' => [['adsUrl' => 'https://user:secret@ebmidas.dianping.com/business/home']];
        yield 'ads fragment' => [['ads_url' => 'https://ebmidas.dianping.com/business/home#secret']];
        yield 'ads token path' => [['ads_url' => 'https://ebmidas.dianping.com/access-token/secret']];
        yield 'conflicting aliases' => [[
            'cdp_url' => 'http://127.0.0.1:9223',
            'cdpUrl' => 'http://127.0.0.1:9333',
        ]];
    }

    /** @return array<string,mixed> */
    private function sanitize(array $request): array
    {
        return BrowserCaptureTaskExecutionService::sanitizeBackgroundRequest($request);
    }
}
