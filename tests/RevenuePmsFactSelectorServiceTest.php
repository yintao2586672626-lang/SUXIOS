<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenuePmsFactSelectorService;
use PHPUnit\Framework\TestCase;

final class RevenuePmsFactSelectorServiceTest extends TestCase
{
    public function testEffectiveMeituanProviderSelectsItsExactEnvelopeMetadata(): void
    {
        $selection = (new RevenuePmsFactSelectorService())->select([
            'pms_binding' => [
                'effective_provider' => 'meituan_cloud_pms',
            ],
            'sources' => [
                'meituan_cloud_pms' => [
                    'data_status' => 'readback_verified',
                    'facts' => ['room_revenue' => 7200.0],
                ],
            ],
            'source_completeness' => [
                'meituan_cloud_pms' => 'readback_verified',
            ],
            'date_alignment' => [
                'sources' => [
                    'meituan_cloud_pms' => ['observed_date' => '2026-08-23'],
                ],
            ],
        ]);

        self::assertSame('meituan_cloud_pms', $selection['source_key']);
        self::assertSame('meituan_cloud_pms', $selection['provider']);
        self::assertSame('meituan_cloud_pms_captures', $selection['expected_table']);
        self::assertSame('readback_verified', $selection['data_status']);
        self::assertSame(7200.0, $selection['facts']['room_revenue']);
        self::assertSame('2026-08-23', $selection['date_alignment']['observed_date']);
        self::assertFalse($selection['legacy_fixture']);
    }

    public function testEffectiveMeituanProviderNeverFallsBackToDingdandao(): void
    {
        $selection = (new RevenuePmsFactSelectorService())->select([
            'pms_binding' => [
                'effective_provider' => 'meituan_cloud_pms',
            ],
            'sources' => [
                'dingdandao_pms' => [
                    'data_status' => 'readback_verified',
                    'facts' => ['room_revenue' => 9999.0],
                ],
            ],
        ]);

        self::assertSame('meituan_cloud_pms', $selection['source_key']);
        self::assertSame('missing', $selection['data_status']);
        self::assertSame([], $selection['source']);
        self::assertSame([], $selection['facts']);
    }

    public function testLegacyDingdandaoOnlyFixtureRemainsCompatible(): void
    {
        $selection = (new RevenuePmsFactSelectorService())->select([
            'sources' => [
                'dingdandao_pms' => [
                    'data_status' => 'readback_verified',
                    'facts' => ['sold_room_nights' => 12],
                ],
            ],
        ]);

        self::assertSame('dingdandao_pms', $selection['source_key']);
        self::assertSame('readback_verified', $selection['data_status']);
        self::assertSame(12, $selection['facts']['sold_room_nights']);
        self::assertTrue($selection['legacy_fixture']);
    }

    public function testUnknownOrAmbiguousProviderUsesNeutralPmsWithoutAliasing(): void
    {
        $unknown = (new RevenuePmsFactSelectorService())->select([
            'pms_binding' => ['effective_provider' => 'none'],
            'source_completeness' => ['pms' => 'blocked'],
        ]);
        $ambiguous = (new RevenuePmsFactSelectorService())->select([
            'sources' => [
                'dingdandao_pms' => ['data_status' => 'readback_verified'],
                'meituan_cloud_pms' => ['data_status' => 'readback_verified'],
            ],
        ]);

        self::assertSame('pms', $unknown['source_key']);
        self::assertSame('blocked', $unknown['data_status']);
        self::assertNull($unknown['provider']);
        self::assertSame('pms', $ambiguous['source_key']);
        self::assertSame('missing', $ambiguous['data_status']);
    }
}
