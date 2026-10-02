<?php
declare(strict_types=1);

namespace Tests;

use app\controller\OnlineData;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ReflectionHelper;

final class MeituanCapturedPercentUnitTest extends TestCase
{
    use ReflectionHelper;

    private function controller(): OnlineData
    {
        return (new ReflectionClass(OnlineData::class))->newInstanceWithoutConstructor();
    }

    public function testExplicitPercentSuffixPreservesSmallPercentagesAndTrueZero(): void
    {
        $controller = $this->controller();
        foreach (['0.5%' => 0.5, '1%' => 1.0, '0%' => 0.0, '12.5%' => 12.5, ' 0.5 % ' => 0.5] as $raw => $expected) {
            self::assertSame($expected, $this->invokeNonPublic($controller, 'normalizeMeituanPercentValue', [$raw]), $raw);
        }
    }

    public function testLegacyUnlabelledRatiosKeepTheirExistingConvention(): void
    {
        $controller = $this->controller();
        foreach ([[0.005, 0.5], [0.5, 50.0], ['0.5', 50.0], [1, 100.0], [0, 0.0], [12.5, 12.5]] as [$raw, $expected]) {
            self::assertSame($expected, $this->invokeNonPublic($controller, 'normalizeMeituanPercentValue', [$raw]));
        }
    }

    public function testMissingOrInvalidValuesDoNotBecomeZeroPercent(): void
    {
        $controller = $this->controller();
        foreach ([null, '', ' ', '%', 'not-a-rate', '0%5', '0.5%%', INF, NAN, '1e9999%'] as $raw) {
            self::assertNull($this->invokeNonPublic($controller, 'normalizeMeituanPercentValue', [$raw]));
        }
    }

    public function testCapturedAdPayloadPreservesPercentageAndOriginalUnitThroughJsonReadback(): void
    {
        $controller = $this->controller();
        foreach (['0.5%' => 0.5, '1%' => 1.0, '0%' => 0.0] as $raw => $expected) {
            $rows = $this->invokeNonPublic($controller, 'buildMeituanCapturedDailyRows', [[
                'storeId' => 'synthetic-store-80', 'poiId' => 'synthetic-poi-80',
                'poiName' => 'Synthetic Hotel', 'defaultDataDate' => '2026-09-25',
                'ads' => [['adId' => 'synthetic-ad-a', 'date' => '2026-09-25',
                    'exposure_count' => 1000, 'click_count' => 200, 'orderNum' => 1, 'conversionRate' => $raw]],
            ], 80]);
            self::assertCount(1, $rows);
            self::assertSame($expected, $rows[0]['flow_rate']);
            $decoded = json_decode(json_encode($rows[0], JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            self::assertEquals($expected, $decoded['flow_rate']);
            self::assertSame('meituan', $decoded['source']);
            self::assertSame(80, $decoded['system_hotel_id']);
            self::assertSame('advertising', $decoded['data_type']);
            self::assertSame('2026-09-25', $decoded['data_date']);
            $source = json_decode($decoded['raw_data'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($raw, $source['conversionRate']);
        }
    }
}
