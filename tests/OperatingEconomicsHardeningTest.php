<?php
declare(strict_types=1);

use app\service\ChannelEconomicsService;
use app\service\ConsumablesActualCostService;
use PHPUnit\Framework\TestCase;

/** Synthetic numeric boundaries only; no stored business data. */
final class OperatingEconomicsHardeningTest extends TestCase
{
    private function channel(): array
    {
        return ['net_revenue' => 1000, 'advertising_spend' => 100, 'attributed_order_amount' => 400,
            'effective_order_amount' => 1200, 'refund_amount' => 50, 'attribution_basis' => 'synthetic-same-window',
            'advertising_included_in_net_revenue' => false, 'advertising_in_direct_costs' => false,
            'cost_coverage_complete' => true, 'operator_attested' => true, 'source_refs' => ['synthetic-monthly-source'], 'costs' => []];
    }

    public function testOrderSettlementDifferenceCannotExceedSupportedCalculatedAmount(): void
    {
        $input = $this->channel();
        $input['net_revenue'] = -1e12;
        $input['effective_order_amount'] = 1e12;
        $input['advertising_spend'] = 0;
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('channel_calculated_amount_out_of_range');
        (new ChannelEconomicsService())->calculate($input);
    }

    public function testSupportedDifferenceAndKnownZeroRemainAvailable(): void
    {
        $input = $this->channel();
        $input['net_revenue'] = 0;
        $input['effective_order_amount'] = 1e12;
        $input['advertising_spend'] = 0;
        $result = (new ChannelEconomicsService())->calculate($input);
        self::assertSame(1e12, $result['order_to_settlement_difference']);
        self::assertSame(0.0, $result['channel_net_contribution_amount']);
    }

    public function testPositiveChannelNumberCannotSilentlyUnderflowToZero(): void
    {
        $input = $this->channel();
        $input['advertising_spend'] = '1e-9999';
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('channel_number_invalid');
        (new ChannelEconomicsService())->calculate($input);
    }

    public function testPositiveActualRoomNightsCannotSilentlyUnderflowToZero(): void
    {
        $input = ['occupied_room_nights' => '1e-9999', 'occupied_room_nights_source_ref' => 'synthetic-room-ledger',
            'denominator_scope' => 'whole_hotel', 'items' => []];
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('consumables_number_invalid');
        (new ConsumablesActualCostService())->calculate($input);
    }

    public function testScientificKnownZeroRemainsZeroRatherThanUnderflowError(): void
    {
        $input = $this->channel(); $input['advertising_spend'] = '0e-9999';
        self::assertSame(0.0, (new ChannelEconomicsService())->calculate($input)['inputs']['advertising_spend']);
        $actual = ['occupied_room_nights' => '0e-9999', 'occupied_room_nights_source_ref' => 'synthetic-room-ledger',
            'denominator_scope' => 'whole_hotel', 'items' => []];
        self::assertSame(0.0, (new ConsumablesActualCostService())->calculate($actual)['inputs']['occupied_room_nights']);
    }

    public function testMalformedInventorySourceDateRemainsExplicitEvidenceGap(): void
    {
        $row = ['id' => 'synthetic-item', 'name' => '合成耗材', 'enabled' => true, 'unit' => 'piece',
            'source_ref' => 'synthetic-inventory-source', 'source_date' => '2026-10-' . chr(0) . '3',
            'opening_quantity' => 1, 'purchased_quantity' => 0, 'transfer_in_quantity' => 0, 'closing_quantity' => 0,
            'transfer_out_quantity' => 0, 'returned_quantity' => 0, 'written_off_quantity' => 0, 'unit_price' => 2];
        $result = (new ConsumablesActualCostService())->calculate(['occupied_room_nights' => 100,
            'occupied_room_nights_source_ref' => 'synthetic-room-ledger', 'denominator_scope' => 'whole_hotel', 'items' => [$row]]);
        self::assertSame('partial', $result['status']);
        self::assertNull($result['actual_consumed_cost']);
        self::assertContains('synthetic-item:source_evidence', $result['missing_items']);
    }
}
