<?php
declare(strict_types=1);

use app\service\ConsumablesActualCostService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Synthetic inventory quantities only; no database or external service. */
final class ConsumablesActualCostPrecisionTest extends TestCase
{
    private function input(array $changes = []): array
    {
        return ['occupied_room_nights' => 100, 'occupied_room_nights_source_ref' => 'synthetic-pms-room-nights',
            'denominator_scope' => 'whole_hotel', 'operator_attested' => true, 'items' => [array_replace([
                'id' => 'precision', 'name' => '合成精度耗材', 'enabled' => true, 'unit' => 'piece',
                'source_ref' => 'synthetic-inventory-count', 'source_date' => '2026-10-02',
                'opening_quantity' => 0, 'purchased_quantity' => 0, 'transfer_in_quantity' => 0,
                'closing_quantity' => 0, 'transfer_out_quantity' => 0, 'returned_quantity' => 0,
                'written_off_quantity' => 0, 'unit_price' => 1e6,
            ], $changes)]];
    }

    public static function smallMovements(): array
    {
        return [
            'purchased' => [['opening_quantity' => 1e12, 'closing_quantity' => 1e12, 'purchased_quantity' => 0.00001]],
            'transfer in' => [['opening_quantity' => 1e12, 'closing_quantity' => 1e12, 'transfer_in_quantity' => 0.00001]],
            'several small movements' => [['opening_quantity' => 1e12, 'closing_quantity' => 1e12,
                'purchased_quantity' => 0.00002, 'transfer_in_quantity' => 0.00003,
                'transfer_out_quantity' => 0.00001, 'returned_quantity' => 0.00001, 'written_off_quantity' => 0.00002]],
            'legacy numeric strings' => [['opening_quantity' => '1000000000000', 'closing_quantity' => '1e12',
                'purchased_quantity' => '0.00001', 'unit_price' => '1000000']],
        ];
    }

    #[DataProvider('smallMovements')]
    public function testSmallConsumptionSurvivesLargeInventoryCancellation(array $changes): void
    {
        $service = new ConsumablesActualCostService();
        $result = $service->calculate($this->input($changes));
        self::assertSame('calculated', $result['status']);
        self::assertSame(0.00001, $result['items'][0]['consumed_quantity']);
        self::assertSame(10.0, $result['items'][0]['consumed_cost']);
        self::assertSame(10.0, $result['actual_consumed_cost']);
        self::assertSame(0.1, $result['actual_consumables_cost_per_room_night']);
        self::assertSame([], $result['missing_items']);
        $readback = json_decode(json_encode($result['inputs'], JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($result, $service->calculate($readback));
    }

    public static function negativeBalances(): array
    {
        return [
            'below quantity rounding' => [['closing_quantity' => 0.0000001]],
            'smallest supported float' => [['closing_quantity' => 1e-320]],
            'distinct nearby decimal quantities' => [['opening_quantity' => 0.3, 'closing_quantity' => 0.30000000000000004]],
            'negative after large cancellation' => [['opening_quantity' => 1e12, 'closing_quantity' => 1e12,
                'transfer_out_quantity' => 0.0000001]],
        ];
    }

    #[DataProvider('negativeBalances')]
    public function testNegativeBalanceIsMissingBeforeOutputRounding(array $changes): void
    {
        $result = (new ConsumablesActualCostService())->calculate($this->input($changes));
        self::assertSame('partial', $result['status']);
        self::assertSame('partial', $result['items'][0]['status']);
        self::assertNull($result['items'][0]['consumed_quantity']);
        self::assertNull($result['items'][0]['consumed_cost']);
        self::assertNull($result['actual_consumed_cost']);
        self::assertNull($result['actual_consumables_cost_per_room_night']);
        self::assertContains('precision:inventory_balance_negative', $result['missing_items']);
    }

    public function testOrdinaryDecimalZeroAndEstablishedPrecisionStayCompatible(): void
    {
        $service = new ConsumablesActualCostService();
        $zero = $service->calculate($this->input(['opening_quantity' => 0.3, 'closing_quantity' => 0.1,
            'written_off_quantity' => 0.2]));
        self::assertSame('calculated', $zero['status']);
        self::assertSame(0.0, $zero['items'][0]['consumed_quantity']);
        self::assertSame(0.0, $zero['actual_consumed_cost']);
        $rounded = $service->calculate($this->input(['opening_quantity' => 1.2345678, 'unit_price' => 0.3333]));
        self::assertSame(1.234568, $rounded['items'][0]['consumed_quantity']);
        self::assertSame(0.41, $rounded['actual_consumed_cost']);
        self::assertSame(0.0041, $rounded['actual_consumables_cost_per_room_night']);
    }

    public function testMissingSourceStillPreventsConsumptionCalculation(): void
    {
        $result = (new ConsumablesActualCostService())->calculate($this->input([
            'opening_quantity' => 1e12, 'closing_quantity' => 1e12, 'purchased_quantity' => 0.00001, 'source_ref' => '',
        ]));
        self::assertSame('partial', $result['status']);
        self::assertNull($result['actual_consumed_cost']);
        self::assertNull($result['items'][0]['consumed_quantity']);
        self::assertContains('precision:source_evidence', $result['missing_items']);
    }

    public function testQuantityDisplayRoundingCannotErasePositiveCostOrVariance(): void
    {
        $service = new ConsumablesActualCostService();
        $result = $service->calculate($this->input([
            'opening_quantity' => 1e12, 'closing_quantity' => 1e12, 'purchased_quantity' => 1e-7,
            'unit_price' => 1e6, 'budget_unit_price' => 500000, 'budget_usage_per_room_night' => 0,
        ]));
        self::assertSame('calculated', $result['status']);
        self::assertSame(1e-7, $result['items'][0]['consumed_quantity']);
        self::assertSame(0.1, $result['items'][0]['consumed_cost']);
        self::assertSame(0.05, $result['items'][0]['price_variance']);
        self::assertSame(0.05, $result['items'][0]['usage_variance']);
        self::assertSame(0.1, $result['items'][0]['total_variance']);
        self::assertSame(0.1, $result['actual_consumed_cost']);
        self::assertSame(0.001, $result['actual_consumables_cost_per_room_night']);
        self::assertSame(1e-7, $result['inputs']['items'][0]['purchased_quantity']);
        $readback = json_decode(json_encode($result['inputs'], JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($result, $service->calculate($readback));
    }
}
