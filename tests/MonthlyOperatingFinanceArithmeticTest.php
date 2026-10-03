<?php
declare(strict_types=1);

use app\service\MonthlyOperatingFinanceService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MonthlyOperatingFinanceArithmeticTest extends TestCase
{
    #[DataProvider('overflowProvider')]
    public function testNonFiniteArithmeticIsAnExplicitInputFailure(array $overrides, string $field): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($field . '_not_calculable');
        (new MonthlyOperatingFinanceService())->calculate('whole_hotel', [...$this->inputs(), ...$overrides]);
    }

    public static function overflowProvider(): iterable
    {
        yield 'revenue addition' => [['room_operating_revenue' => 1e308, 'non_room_operating_revenue' => 1e308], 'total_operating_revenue'];
        yield 'gop subtraction' => [['departmental_expense' => 1e308, 'undistributed_operating_expense' => 1e308], 'gop'];
        yield 'gop margin' => [['room_operating_revenue' => 0.01, 'non_room_operating_revenue' => 0, 'departmental_expense' => 1e308], 'gop_margin_percent'];
        yield 'owner cash subtraction' => [['rent_expense' => 1e308, 'other_fixed_cash_cost' => 1e308], 'owner_cash_proxy_before_tax_capex_and_financing'];
        yield 'budget gop variance' => [['departmental_expense' => 1e308, 'budget_gop' => 1e308], 'budget_gop_variance'];
    }

    public function testExplicitZerosAndOrdinaryLossesRemainJsonSerializable(): void
    {
        $service = new MonthlyOperatingFinanceService();
        $zero = $service->calculate('whole_hotel', array_fill_keys(array_keys($this->inputs()), 0));
        self::assertSame('ready', $zero['status']);
        self::assertSame(0.0, $zero['gop']);
        self::assertNull($zero['gop_margin_percent']);
        self::assertNotFalse(json_encode($zero, JSON_THROW_ON_ERROR));
        $loss = $service->calculate('whole_hotel', [...$this->inputs(), 'departmental_expense' => 15000, 'budget_gop' => -500]);
        self::assertSame(-5000.0, $loss['gop']);
        self::assertSame(-4500.0, $loss['budget_gop_variance']);
        self::assertNotFalse(json_encode($loss, JSON_THROW_ON_ERROR));
    }

    public function testMissingRevenueRemainsMissing(): void
    {
        $result = (new MonthlyOperatingFinanceService())->calculate('whole_hotel', ['non_room_operating_revenue' => 0]);
        self::assertSame('blocked', $result['status']);
        self::assertNull($result['total_operating_revenue']);
        self::assertContains('room_operating_revenue_missing', $result['missing_items']);
    }

    private function inputs(): array
    {
        return ['room_operating_revenue' => 10000, 'non_room_operating_revenue' => 2000,
            'departmental_expense' => 3000, 'undistributed_operating_expense' => 2000,
            'rent_expense' => 1000, 'other_fixed_cash_cost' => 500,
            'budget_total_operating_revenue' => 11000, 'budget_gop' => 6500];
    }
}
