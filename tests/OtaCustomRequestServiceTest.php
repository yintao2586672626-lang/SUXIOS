<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaCustomRequestService;
use app\service\OutboundUrlGuard;
use PHPUnit\Framework\TestCase;

final class OtaCustomRequestServiceTest extends TestCase
{
    public function testAllowedRedirectToLoopbackIsRejectedWithoutFollowingSecondHop(): void
    {
        $calls = 0;
        $service = $this->serviceWithTransport(
            static function () use (&$calls): array {
                $calls++;
                return [
                    'success' => true,
                    'status' => 302,
                    'response_headers' => [
                        'HTTP/1.1 302 Found',
                        'Location: http://127.0.0.1/internal',
                    ],
                    'body' => '',
                ];
            }
        );

        $result = $service->request('https://ebooking.ctrip.com/redirect', 'GET', '', '');

        self::assertFalse($result['success']);
        self::assertSame('redirect_not_allowed', $result['error_code']);
        self::assertSame(1, $calls, 'The service must not issue a second request for Location.');
    }

    public function testResolvedLoopbackPrivateAndMetadataAddressesFailBeforeTransport(): void
    {
        foreach (['127.0.0.1', '10.10.0.8', '169.254.169.254'] as $address) {
            $calls = 0;
            $guard = new OutboundUrlGuard(static fn(string $host): array => [$address]);
            $service = new OtaCustomRequestService(
                $guard,
                static function () use (&$calls): array {
                    $calls++;
                    return ['success' => true, 'status' => 200, 'response_headers' => [], 'body' => '{}'];
                }
            );

            $result = $service->request('https://ebooking.ctrip.com/api', 'GET', '', '');

            self::assertFalse($result['success'], $address);
            self::assertSame('url_not_allowed', $result['error_code'], $address);
            self::assertSame(0, $calls, $address);
        }
    }

    public function testUserinfoNonHttpsAndNon443UrlsFailBeforeTransport(): void
    {
        $calls = 0;
        $service = $this->serviceWithTransport(
            static function () use (&$calls): array {
                $calls++;
                return ['success' => true, 'status' => 200, 'response_headers' => [], 'body' => '{}'];
            }
        );

        foreach ([
            'https://user:secret@ebooking.ctrip.com/api',
            'http://ebooking.ctrip.com/api',
            'https://ebooking.ctrip.com:444/api',
        ] as $url) {
            $result = $service->request($url, 'GET', '', '');
            self::assertFalse($result['success'], $url);
            self::assertSame('url_not_allowed', $result['error_code'], $url);
        }
        self::assertSame(0, $calls);
    }

    public function testMethodAndResponseSizeAreBounded(): void
    {
        $calls = 0;
        $service = $this->serviceWithTransport(
            static function () use (&$calls): array {
                $calls++;
                return [
                    'success' => true,
                    'status' => 200,
                    'response_headers' => ['HTTP/1.1 200 OK'],
                    'body' => str_repeat('x', OtaCustomRequestService::MAX_RESPONSE_BYTES + 1),
                ];
            }
        );

        $methodResult = $service->request('https://ebooking.ctrip.com/api', 'DELETE', '', '');
        self::assertFalse($methodResult['success']);
        self::assertSame('method_not_allowed', $methodResult['error_code']);
        self::assertSame(0, $calls);

        $sizeResult = $service->request('https://ebooking.ctrip.com/api', 'GET', '', '');
        self::assertFalse($sizeResult['success']);
        self::assertSame('response_too_large', $sizeResult['error_code']);
        self::assertSame(1, $calls);
    }

    public function testAllowedJsonResponsePreservesExistingControllerContract(): void
    {
        $service = $this->serviceWithTransport(static fn(): array => [
            'success' => true,
            'status' => 200,
            'response_headers' => ['HTTP/1.1 200 OK', 'Content-Type: application/json'],
            'body' => '{"ok":true}',
        ]);

        $result = $service->request(
            'https://ebooking.ctrip.com/api',
            'POST',
            "Content-Type: application/json\nAuthorization: Bearer test-only",
            '{"query":"today"}'
        );

        self::assertTrue($result['success']);
        self::assertSame(200, $result['status']);
        self::assertSame(['ok' => true], $result['data']);
        self::assertStringContainsString('Content-Type: application/json', $result['response_headers']);
    }

    public function testOnlyUpstreamTwoHundredStatusesAreSuccessful(): void
    {
        foreach ([0, 401, 429, 500] as $status) {
            $service = $this->serviceWithTransport(static fn(): array => [
                'success' => true,
                'status' => $status,
                'response_headers' => ["HTTP/1.1 {$status} Test"],
                'body' => '{"error":"upstream down"}',
            ]);

            $result = $service->request('https://ebooking.ctrip.com/api', 'GET', '', '');

            self::assertFalse($result['success'], (string)$status);
            self::assertSame('upstream_http_error', $result['error_code'], (string)$status);
            self::assertSame($status, $result['status'], (string)$status);
        }

        foreach ([200, 204] as $status) {
            $service = $this->serviceWithTransport(static fn(): array => [
                'success' => true,
                'status' => $status,
                'response_headers' => ["HTTP/1.1 {$status} Test"],
                'body' => $status === 204 ? '' : '{"ok":true}',
            ]);

            $result = $service->request('https://ebooking.ctrip.com/api', 'GET', '', '');

            self::assertTrue($result['success'], (string)$status);
            self::assertSame($status, $result['status'], (string)$status);
        }
    }

    public function testDefaultTransportStaticallyPinsDnsDisablesRedirectsAndCapsBody(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/service/OtaCustomRequestService.php');

        self::assertStringContainsString('CURLOPT_FOLLOWLOCATION => false', $source);
        self::assertStringContainsString('CURLOPT_MAXREDIRS => 0', $source);
        self::assertStringContainsString("CURLOPT_RESOLVE => \$target['curl_resolve']", $source);
        self::assertStringContainsString('CURLOPT_WRITEFUNCTION', $source);
        self::assertStringNotContainsString('file_get_contents($url', $source);

        $concern = (string)file_get_contents(
            dirname(__DIR__) . '/app/controller/concern/OnlineDataRequestConcern.php'
        );
        $start = strpos($concern, 'private function sendCustomRequest(');
        $end = strpos($concern, 'private function auditUrlWithoutQuery(', (int)$start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $method = substr($concern, (int)$start, (int)$end - (int)$start);
        self::assertStringContainsString('new OtaCustomRequestService()', $method);
        self::assertStringNotContainsString('file_get_contents', $method);
        self::assertStringNotContainsString('stream_context_create', $method);
    }

    private function serviceWithTransport(callable $transport): OtaCustomRequestService
    {
        return new OtaCustomRequestService(
            new OutboundUrlGuard(static fn(string $host): array => ['93.184.216.34']),
            $transport
        );
    }
}
