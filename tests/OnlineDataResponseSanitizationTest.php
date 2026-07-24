<?php

declare(strict_types=1);

namespace tests;

use app\controller\concern\OnlineDataRequestConcern;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class OnlineDataResponseSanitizationTest extends TestCase
{
    public function testCtripCookieApiResponseProjectionDropsRawRequestAndResponseMaterial(): void
    {
        $subject = new class {
            use OnlineDataRequestConcern;
        };
        $method = new ReflectionMethod($subject, 'summarizeCtripCookieApiResponses');
        $method->setAccessible(true);

        $result = $method->invoke($subject, [[
            'url' => 'https://ebooking.ctrip.com/api/data?token=query-secret',
            'source_url_hash' => str_repeat('a', 64),
            'section' => 'traffic_report',
            'endpoint_id' => 'traffic_search_flow',
            'data_type' => 'traffic',
            'status' => 200,
            'request_type' => 'get',
            'catalog_fact_count' => 2,
            'standard_row_count' => 1,
            'request_headers' => ['Authorization' => 'header-secret'],
            'request_payload' => ['token' => 'payload-secret'],
            'data' => ['access_token' => 'response-secret'],
        ]]);

        self::assertCount(1, $result);
        self::assertSame('https://ebooking.ctrip.com/api/data', $result[0]['url']);
        self::assertSame(str_repeat('a', 64), $result[0]['source_url_hash']);
        self::assertArrayNotHasKey('request_headers', $result[0]);
        self::assertArrayNotHasKey('request_payload', $result[0]);
        self::assertArrayNotHasKey('data', $result[0]);
        self::assertStringNotContainsString('secret', json_encode($result, JSON_THROW_ON_ERROR));
    }
}
