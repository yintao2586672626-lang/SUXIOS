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
}
