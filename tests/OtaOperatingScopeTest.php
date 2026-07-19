<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaOperatingScope;
use PHPUnit\Framework\TestCase;

final class OtaOperatingScopeTest extends TestCase
{
    public function testOwnOperatingScopeKeepsSelfRowsAndExcludesCompetitorRows(): void
    {
        self::assertTrue(OtaOperatingScope::isOwnOperatingRow([
            'hotel_name' => '我的酒店',
            'dimension' => '',
        ]));

        self::assertTrue(OtaOperatingScope::isOwnOperatingRow([
            'hotel_name' => '巢湖测试',
            'dimension' => '',
        ], null, ['巢湖测试']));

        self::assertFalse(OtaOperatingScope::isOwnOperatingRow([
            'hotel_name' => '竞对酒店A',
            'dimension' => '',
        ], null, ['巢湖测试']));

        self::assertFalse(OtaOperatingScope::isOwnOperatingRow([
            'hotel_name' => '',
            'compare_type' => 'competitor',
            'dimension' => '',
        ]));

        self::assertFalse(OtaOperatingScope::isOwnOperatingRow([
            'hotel_name' => '我的酒店',
            'dimension' => '房费收入榜',
        ]));
    }

    public function testPersistedNormalizedRowIdentitySurvivesReadbackWrapper(): void
    {
        self::assertTrue(OtaOperatingScope::isOwnOperatingRow([
            'hotel_name' => 'Platform display name',
            'dimension' => '',
            'raw_data' => json_encode([
                'row' => [
                    'compare_type' => 'self',
                    'is_self' => true,
                ],
                'field_fact_summary' => ['captured_count' => 5],
            ], JSON_UNESCAPED_UNICODE),
        ], null, ['System hotel name']));

        self::assertFalse(OtaOperatingScope::isOwnOperatingRow([
            'hotel_name' => 'Platform display name',
            'dimension' => '',
            'raw_data' => json_encode([
                'row' => [
                    'compare_type' => 'competitor',
                    'is_self' => false,
                ],
            ], JSON_UNESCAPED_UNICODE),
        ], null, ['System hotel name']));
    }

    public function testExactBoundPlatformIdentityKeepsOwnRowWithoutWeakeningCompetitorExclusion(): void
    {
        $ownRow = [
            'hotel_name' => 'Platform display name',
            'hotel_id' => 'poi-own-1',
            'data_type' => 'traffic',
            'dimension' => '',
            'raw_data' => json_encode(['row' => ['poi_id' => 'poi-own-1']], JSON_UNESCAPED_UNICODE),
        ];

        self::assertTrue(OtaOperatingScope::isOwnOperatingRow(
            $ownRow,
            null,
            ['System hotel name'],
            ['poi-own-1']
        ));
        self::assertFalse(OtaOperatingScope::isOwnOperatingRow(
            [...$ownRow, 'compare_type' => 'competitor'],
            null,
            ['System hotel name'],
            ['poi-own-1']
        ));
        self::assertFalse(OtaOperatingScope::isOwnOperatingRow(
            [...$ownRow, 'data_type' => 'peer_rank'],
            null,
            ['System hotel name'],
            ['poi-own-1']
        ));
    }
}
