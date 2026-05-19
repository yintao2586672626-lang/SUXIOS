<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaTrafficUrlNormalizer;
use PHPUnit\Framework\TestCase;

final class OtaTrafficUrlNormalizerTest extends TestCase
{
    public function testUsesDefaultCtripTrafficUrlWhenInputIsEmpty(): void
    {
        $url = OtaTrafficUrlNormalizer::normalizeCtripTrafficUrl('');

        self::assertStringContainsString('queryFlowTransforNewV1', $url);
        self::assertStringContainsString('hostType=Ebooking', $url);
        self::assertMatchesRegularExpression('/[?&]v=[0-9.]+/', $url);
    }

    public function testNormalizesWhitespaceHostTypeAndVersionParameter(): void
    {
        $url = OtaTrafficUrlNormalizer::normalizeCtripTrafficUrl(
            " https://ebooking.ctrip.com/datacenter/api/inland/marketanalysis/flowanalysis/queryFlowTransforNewV1?foo=1&v=123 "
        );

        self::assertStringNotContainsString(' ', $url);
        self::assertStringContainsString('foo=1', $url);
        self::assertStringContainsString('hostType=Ebooking', $url);
        self::assertStringNotContainsString('v=123', $url);
        self::assertMatchesRegularExpression('/[?&]v=[0-9.]+/', $url);
    }

    public function testPreservesExistingHostTypeAndAddsVersionWithQuestionMark(): void
    {
        $url = OtaTrafficUrlNormalizer::normalizeCtripTrafficUrl(
            'https://ebooking.ctrip.com/datacenter/api/inland/marketanalysis/flowanalysis/queryFlowTransforNewV1?hostType=Ebooking'
        );

        self::assertSame(1, substr_count($url, 'hostType=Ebooking'));
        self::assertMatchesRegularExpression('/[?&]v=[0-9.]+/', $url);
    }

    public function testAllowsKnownCtripFlowPageTrafficEndpoints(): void
    {
        foreach ([
            'queryScanFlowDetailsV2',
            'queryFlowTransforNew',
            'queryHomePageRealTimeData',
            'getFlowData',
            'getTrafficData',
            'getStatData',
        ] as $endpoint) {
            $url = OtaTrafficUrlNormalizer::normalizeCtripTrafficUrl(
                'https://ebooking.ctrip.com/datacenter/api/inland/businessreport/flowdata/' . $endpoint
            );

            self::assertStringContainsString($endpoint, $url);
            self::assertStringContainsString('hostType=Ebooking', $url);
            self::assertMatchesRegularExpression('/[?&]v=[0-9.]+/', $url);
        }
    }

    public function testRejectsNonCtripTrafficEndpoint(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        OtaTrafficUrlNormalizer::normalizeCtripTrafficUrl('https://ebooking.ctrip.com/invalid');
    }
}
