<?php
declare(strict_types=1);

namespace Tests;

use app\service\OutboundUrlGuard;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OutboundUrlGuardTest extends TestCase
{
    public function testAcceptsPublicHttpsAndBuildsPinnedCurlResolution(): void
    {
        $guard = $this->guard([
            'public.example' => ['93.184.216.34', '2606:4700:4700::1111'],
        ]);

        $target = $guard->validate('https://public.example/v1/chat/completions?mode=fast');

        self::assertSame('public.example', $target['host']);
        self::assertSame(443, $target['port']);
        self::assertSame(['93.184.216.34', '2606:4700:4700::1111'], $target['addresses']);
        self::assertSame(['public.example:443:93.184.216.34'], $target['curl_resolve']);
    }

    public function testRejectsUnsafeUrlFormsAndNonPublicLiteralAddresses(): void
    {
        $guard = $this->guard(['public.example' => ['93.184.216.34']]);
        $urls = [
            'http://public.example/v1',
            'https://user:pass@public.example/v1',
            'https://public.example\\@127.0.0.1/v1',
            'https://public.example:8443/v1',
            'https://localhost/v1',
            'https://127.0.0.1/v1',
            'https://100.64.0.1/v1',
            'https://169.254.169.254/latest/meta-data',
            'https://192.0.2.1/v1',
            'https://[::1]/v1',
            'https://[fc00::1]/v1',
            'https://[fe80::1]/v1',
            'https://[2001:db8::1]/v1',
        ];

        foreach ($urls as $url) {
            try {
                $guard->validate($url);
                self::fail('Expected unsafe URL to be rejected.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringNotContainsString('user', $exception->getMessage());
                self::assertStringNotContainsString('pass', $exception->getMessage());
                self::assertStringNotContainsString($url, $exception->getMessage());
            }
        }
    }

    public function testAcceptsPublicIpv6OnlyAndPinsTheValidatedAddress(): void
    {
        $guard = $this->guard([
            'ipv6.example' => ['2606:4700:4700::1111'],
        ]);

        $target = $guard->validate('https://ipv6.example/v1');

        self::assertSame(['2606:4700:4700::1111'], $target['addresses']);
        self::assertSame(['ipv6.example:443:[2606:4700:4700::1111]'], $target['curl_resolve']);
    }

    public function testRejectsHostWhenAnyResolvedAddressIsNotPublic(): void
    {
        $guard = $this->guard([
            'mixed.example' => ['93.184.216.34', 'fd00::10'],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $guard->validate('https://mixed.example/v1');
    }

    public function testRejectsUnresolvedHostInsteadOfAllowingASecondResolverAttempt(): void
    {
        $guard = $this->guard(['unresolved.example' => []]);

        $this->expectException(InvalidArgumentException::class);
        $guard->validate('https://unresolved.example/v1');
    }

    public function testLocalLlmGuardAllowsOnlyPinnedOllamaLoopbackEndpoint(): void
    {
        $guard = new OutboundUrlGuard();

        $target = $guard->validateLocalLlm('http://localhost:11434/v1/chat/completions');

        self::assertSame('localhost', $target['host']);
        self::assertSame(11434, $target['port']);
        self::assertSame(['127.0.0.1'], $target['addresses']);
        self::assertSame(['localhost:11434:127.0.0.1'], $target['curl_resolve']);

        foreach ([
            'http://127.0.0.1:8080/v1',
            'http://192.168.1.10:11434/v1',
            'https://127.0.0.1:11434/v1',
            'http://user:pass@127.0.0.1:11434/v1',
            'http://127.0.0.1:11434/api/push',
            'http://127.0.0.1:11434/v1?target=metadata',
        ] as $url) {
            try {
                $guard->validateLocalLlm($url);
                self::fail('Expected non-Ollama local URL to be rejected.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame(OutboundUrlGuard::ERROR_LOCAL_LLM_NOT_ALLOWED, $exception->getMessage());
            }
        }
    }

    /**
     * @param array<string,array<int,string>> $answers
     */
    private function guard(array $answers): OutboundUrlGuard
    {
        return new OutboundUrlGuard(
            static fn(string $host): array => $answers[$host] ?? []
        );
    }
}
