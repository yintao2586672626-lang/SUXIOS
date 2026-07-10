<?php
declare(strict_types=1);

namespace tests;

use app\service\OtaFactSemantics;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OtaFactSemanticsTest extends TestCase
{
    public function testChannelNormalizationKeepsOtaScopeExplicit(): void
    {
        self::assertSame('ctrip', OtaFactSemantics::channel(' CTrip '));
        self::assertSame('meituan', OtaFactSemantics::channel('MEITUAN'));
        foreach (['zh-cn-alias', 'garbled-channel', 'whole_hotel'] as $channel) {
            try {
                OtaFactSemantics::channel($channel);
                self::fail('non-canonical channel must be rejected');
            } catch (InvalidArgumentException) {
            }
        }
    }

    public function testMissingNumberIsNullButExplicitZeroRemainsZero(): void
    {
        self::assertNull(OtaFactSemantics::nullableNumber([], ['orders']));
        self::assertSame(0.0, OtaFactSemantics::nullableNumber(['orders' => 0], ['orders']));
        self::assertSame(12.5, OtaFactSemantics::nullableNumber(['orders' => '12.5%'], ['orders']));
    }

    public function testFactKeyIsStableAndSeparatesChannelScope(): void
    {
        $base = ['system_hotel_id' => 7, 'channel' => 'ctrip', 'data_date' => '2026-07-10', 'metric_key' => 'orders', 'entity_id' => 'hotel-1'];
        self::assertSame(OtaFactSemantics::factKey($base), OtaFactSemantics::factKey($base));
        self::assertNotSame(OtaFactSemantics::factKey($base), OtaFactSemantics::factKey(array_merge($base, ['channel' => 'meituan'])));
        self::assertNotSame(
            OtaFactSemantics::factKey(array_merge($base, ['metric_key' => 'a|b'])),
            OtaFactSemantics::factKey(array_merge($base, ['metric_key' => 'a', 'entity_id' => 'b|hotel-1']))
        );
    }
}
