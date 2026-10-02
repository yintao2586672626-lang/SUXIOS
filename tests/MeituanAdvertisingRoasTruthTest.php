<?php
declare(strict_types=1);

namespace Tests;

use app\controller\OnlineData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ReflectionHelper;

final class MeituanAdvertisingRoasTruthTest extends TestCase
{
    use ReflectionHelper;

    private function normalize(array $metrics): array
    {
        $controller = (new ReflectionClass(OnlineData::class))->newInstanceWithoutConstructor();
        $rows = $this->invokeNonPublic($controller, 'buildMeituanCapturedDailyRows', [[
            'storeId' => 'synthetic-81', 'poiId' => 'synthetic-81',
            'poiName' => 'Synthetic Hotel', 'defaultDataDate' => '2026-09-26',
            'ads' => [array_merge(['adId' => 'synthetic-ad', 'date' => '2026-09-26', 'cost' => 30], $metrics)],
        ], 81]);
        self::assertCount(1, $rows);
        self::assertSame('meituan', $rows[0]['source']);
        self::assertSame(81, $rows[0]['system_hotel_id']);
        self::assertSame('2026-09-26', $rows[0]['data_date']);
        self::assertSame('advertising', $rows[0]['data_type']);
        return $rows[0];
    }

    public static function invalidRoas(): array
    {
        return array_map(static fn($value): array => [$value], [
            '--', '   ', '%', 'not-a-ratio', false, true, [], ['value' => 2],
            -1, '-0.5', '1e9999', '-1e9999',
        ]);
    }

    #[DataProvider('invalidRoas')]
    public function testInvalidSourceDoesNotBecomeARealZero($value): void
    {
        foreach (['roas', 'roi'] as $key) {
            $row = $this->normalize([$key => $value]);
            self::assertNull($row['data_value']);
            $decoded = json_decode(json_encode($row, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            self::assertNull($decoded['data_value']);
            $raw = json_decode($decoded['raw_data'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($value, $raw[$key], 'Keep the original source for diagnosis');
        }
    }

    public function testExplicitZeroAndLegacyFormattedRatiosKeepTheirValues(): void
    {
        foreach ([[0, 0.0], ['0', 0.0], ['0.00', 0.0], [0.5, 0.5], ['1,200.5', 1200.5], ['￥3', 3.0]] as [$value, $expected]) {
            foreach (['roas', 'roi'] as $key) {
                self::assertSame($expected, $this->normalize([$key => $value, 'orderAmount' => 90])['data_value']);
            }
        }
        self::assertSame(0.0, $this->normalize(['roas' => 0, 'roi' => 3])['data_value']);
        self::assertSame(2.0, $this->normalize(['roas' => null, 'roi' => 2])['data_value']);
    }

    public function testKnownZeroAttributionWithPositiveSpendIsAZeroRatio(): void
    {
        foreach ([0, '0', 0.0] as $amount) {
            $row = $this->normalize(['orderAmount' => $amount]);
            self::assertSame(0.0, $row['data_value']);
            $raw = json_decode($row['raw_data'], true, 512, JSON_THROW_ON_ERROR);
            self::assertEquals(0, $raw['roas']);
            self::assertEquals(0, $raw['order_amount']);
        }
        self::assertSame(3.0, $this->normalize(['orderAmount' => 90])['data_value']);
    }

    public function testMissingOrNonPositiveDenominatorAndNegativeAttributionStayUnknown(): void
    {
        foreach ([[], ['orderAmount' => null], ['orderAmount' => -1],
            ['cost' => 0, 'orderAmount' => 0], ['cost' => 0, 'orderAmount' => 90],
            ['cost' => null, 'orderAmount' => 0], ['cost' => -1, 'orderAmount' => 0]] as $metrics) {
            self::assertNull($this->normalize($metrics)['data_value']);
        }
    }

    public function testExplicitInvalidRatioIsNotSilentlyReplacedByCalculatedOperands(): void
    {
        foreach ([90, 0, null] as $amount) {
            $row = $this->normalize(['roas' => '--', 'orderAmount' => $amount]);
            self::assertNull($row['data_value']);
            self::assertSame('--', json_decode($row['raw_data'], true, 512, JSON_THROW_ON_ERROR)['roas']);
        }
        self::assertNull($this->normalize(['roas' => '--', 'roi' => 3])['data_value']);
    }

    public function testDerivedOverflowIsNotSerializableAsARealRatio(): void
    {
        $row = $this->normalize(['cost' => '1e-308', 'orderAmount' => '1e308']);
        self::assertNull($row['data_value']);
        json_encode($row, JSON_THROW_ON_ERROR);
        self::assertIsArray(json_decode($row['raw_data'], true, 512, JSON_THROW_ON_ERROR));
    }

    public function testNonFiniteOperandsCannotManufactureZeroOrInfiniteRatios(): void
    {
        foreach ([['cost' => '1e9999', 'orderAmount' => 40], ['cost' => 30, 'orderAmount' => '1e9999']] as $metrics) {
            self::assertNull($this->normalize($metrics)['data_value']);
        }
    }
}
