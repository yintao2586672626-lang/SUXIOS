<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaCustomRequestService;
use app\service\OutboundUrlGuard;
use PHPUnit\Framework\TestCase;

final class OtaCustomRequestServiceTest extends TestCase
{
    public function testEveryLegacyRequestFailsClosedWithoutCallingTransport(): void
    {
        $calls = 0;
        $service = new OtaCustomRequestService(
            new OutboundUrlGuard(static fn(string $host): array => ['93.184.216.34']),
            static function () use (&$calls): array {
                $calls++;
                return ['success' => true, 'status' => 200, 'response_headers' => [], 'body' => '{}'];
            }
        );

        foreach ([
            ['https://ebooking.ctrip.com/report', 'GET', 'Cookie: must-not-forward', ''],
            ['https://ebooking.ctrip.com/write', 'POST', 'Authorization: Bearer must-not-forward', '{"write":true}'],
            ['https://eb.meituan.com/logout', 'GET', '', ''],
            ['https://127.0.0.1/internal', 'GET', '', ''],
        ] as [$url, $method, $headers, $body]) {
            $result = $service->request($url, $method, $headers, $body);

            self::assertFalse($result['success'], $url);
            self::assertSame(OtaCustomRequestService::DISABLED_ERROR_CODE, $result['error_code'], $url);
            self::assertSame(0, $result['status'], $url);
            self::assertSame('', $result['response_headers'], $url);
        }

        self::assertSame(0, $calls, 'The compatibility service must never perform an outbound request.');
    }

    public function testDisabledContractUsesStableGoneStatus(): void
    {
        self::assertSame(
            410,
            OtaCustomRequestService::httpStatusForErrorCode(OtaCustomRequestService::DISABLED_ERROR_CODE)
        );
        self::assertSame(500, OtaCustomRequestService::httpStatusForErrorCode('unexpected_error'));
    }

    public function testServiceContainsNoOutboundTransportImplementation(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/service/OtaCustomRequestService.php');

        self::assertStringContainsString("DISABLED_ERROR_CODE = 'custom_request_disabled'", $source);
        self::assertStringNotContainsString('curl_init', $source);
        self::assertStringNotContainsString('curl_exec', $source);
        self::assertStringNotContainsString('file_get_contents($url', $source);
        self::assertStringNotContainsString('$this->transport', $source);
    }

    public function testControllerDelegatesToTheInertCompatibilityBoundary(): void
    {
        $concern = (string)file_get_contents(
            dirname(__DIR__) . '/app/controller/concern/OnlineDataRequestConcern.php'
        );
        $start = strpos($concern, 'private function sendCustomRequest(');
        $end = strpos($concern, 'private function auditUrlWithoutQuery(', (int)$start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $method = substr($concern, (int)$start, (int)$end - (int)$start);

        self::assertStringContainsString('new OtaCustomRequestService()', $method);
        self::assertStringContainsString('custom_request_disabled', $concern);
        self::assertStringContainsString("410 => '通用 OTA 请求已停用", $concern);
        self::assertStringNotContainsString('file_get_contents', $method);
        self::assertStringNotContainsString('stream_context_create', $method);
    }
}
