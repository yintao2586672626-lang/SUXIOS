<?php
declare(strict_types=1);

namespace Tests;

use app\middleware\Cors;
use PHPUnit\Framework\TestCase;
use think\App;
use think\Config;
use think\Container;
use think\Request;
use think\Response;

final class CorsCacheVariationTest extends TestCase
{
    public function testAllowedOriginPreservesCompressionVariation(): void
    {
        $response = $this->dispatch('https://console.suxios.test', ['Vary' => 'Accept-Encoding']);
        self::assertSame('Accept-Encoding, Origin', $response->getHeader('Vary'));
        self::assertSame('https://console.suxios.test', $response->getHeader('Access-Control-Allow-Origin'));
    }

    public function testUnallowedAndAbsentOriginsStillSeparateCacheRepresentations(): void
    {
        foreach (['https://untrusted.invalid', ''] as $origin) {
            $response = $this->dispatch($origin, ['Vary' => 'Accept-Encoding']);
            self::assertSame('Accept-Encoding, Origin', $response->getHeader('Vary'));
            self::assertNull($response->getHeader('Access-Control-Allow-Origin'));
        }
    }

    public function testExistingOriginVariationIsNotDuplicatedAndWildcardIsPreserved(): void
    {
        $response = $this->dispatch('https://console.suxios.test', ['vary' => 'Accept-Encoding, origin']);
        self::assertSame('Accept-Encoding, origin', $response->getHeader('vary'));
        self::assertNull($response->getHeader('Vary'));
        $wildcard = $this->dispatch('https://console.suxios.test', ['Vary' => '*']);
        self::assertSame('*', $wildcard->getHeader('Vary'));
    }

    private function dispatch(string $origin, array $headers): Response
    {
        $previous = Container::getInstance();
        $app = new App(dirname(__DIR__));
        $config = new Config();
        $config->set(['allowed_origins' => ['https://console.suxios.test']], 'cors');
        $app->instance('config', $config);
        Container::setInstance($app);
        try {
            $request = (new Request())->setMethod('GET')->withHeader(['origin' => $origin]);
            return (new Cors())->handle($request, static fn(): Response => Response::create('fixture', 'html', 200)->header($headers));
        } finally {
            Container::setInstance($previous);
        }
    }
}
