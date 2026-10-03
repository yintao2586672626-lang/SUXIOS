<?php
declare(strict_types=1);

use app\service\ConsumablesActualCostService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConsumablesActualCostPrecisionTest extends TestCase
{
    private function input(array $changes = []): array
    {
        $row = ['id'=>'synthetic','name'=>'合成数值验收','enabled'=>true,'unit'=>'g','source_ref'=>'synthetic-count',
            'source_date'=>'2026-10-02','opening_quantity'=>0,'purchased_quantity'=>100,'transfer_in_quantity'=>0,
            'closing_quantity'=>0,'transfer_out_quantity'=>0,'returned_quantity'=>0,'written_off_quantity'=>0,
            'unit_price'=>2,'budget_unit_price'=>null,'budget_usage_per_room_night'=>null];
        return ['occupied_room_nights'=>100,'occupied_room_nights_source_ref'=>'synthetic-pms',
            'denominator_scope'=>'whole_hotel','operator_attested'=>true,'items'=>[array_replace($row,$changes)]];
    }

    public function testPositiveSubmicroQuantityIsValuedBeforeAnyDisplayRounding(): void
    {
        $result=(new ConsumablesActualCostService())->calculate($this->input(['purchased_quantity'=>1e-7,'unit_price'=>1e12]));
        self::assertSame(1e-7,$result['items'][0]['consumed_quantity']);
        self::assertSame(100000.0,$result['actual_consumed_cost']);
        self::assertSame('calculated',$result['status']);
    }

    public function testFutureInventoryDateCannotBecomeAttestedActualCost(): void
    {
        $tomorrow = (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->modify('+1 day')->format('Y-m-d');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('consumables_source_date_in_future');
        (new ConsumablesActualCostService())->calculate($this->input(['source_date'=>$tomorrow]));
    }

    public function testTodaysInventoryEvidenceRemainsUsable(): void
    {
        $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
        $result = (new ConsumablesActualCostService())->calculate($this->input(['source_date'=>$today]));
        self::assertSame('calculated', $result['status']);
        self::assertSame('operator_attested', $result['source_quality']);
    }

    public function testLargeEqualStocksDoNotEraseASeparatePurchase(): void
    {
        $result=(new ConsumablesActualCostService())->calculate($this->input(['opening_quantity'=>1e12,'purchased_quantity'=>1e-5,'closing_quantity'=>1e12,'unit_price'=>1e12]));
        self::assertSame(1e-5,$result['items'][0]['consumed_quantity']);
        self::assertSame(10000000.0,$result['actual_consumed_cost']);
    }

    public static function negativeBalances(): array
    {
        return ['small count shortage'=>[['purchased_quantity'=>0,'closing_quantity'=>1e-7,'unit_price'=>1e12]],
            'small transfer after equal large stocks'=>[['opening_quantity'=>1e12,'purchased_quantity'=>0,'closing_quantity'=>1e12,'transfer_out_quantity'=>1e-7,'unit_price'=>1e12]]];
    }

    #[DataProvider('negativeBalances')]
    public function testNegativeInventoryNeverBecomesCalculatedZero(array $changes): void
    {
        $result=(new ConsumablesActualCostService())->calculate($this->input($changes));
        self::assertSame('partial',$result['status']);
        self::assertNull($result['items'][0]['consumed_quantity']);
        self::assertNull($result['actual_consumed_cost']);
        self::assertContains('inventory_balance_negative',$result['items'][0]['missing_items']);
    }

    public function testBinaryNoiseAroundAnExactDecimalBalanceRemainsRealZero(): void
    {
        $result=(new ConsumablesActualCostService())->calculate($this->input(['opening_quantity'=>0.3,'purchased_quantity'=>0,'closing_quantity'=>0.1,'written_off_quantity'=>0.2]));
        self::assertSame('calculated',$result['status']);
        self::assertSame(0.0,$result['items'][0]['consumed_quantity']);
        self::assertSame(0.0,$result['actual_consumed_cost']);
        self::assertSame(0.4,$result['separate_loss_cost']);
    }

    public function testUnknownPriceRetainsCertainQuantityAndNoKnownMoneySubtotal(): void
    {
        $result=(new ConsumablesActualCostService())->calculate($this->input(['unit_price'=>'']));
        self::assertSame(100.0,$result['items'][0]['consumed_quantity']);
        self::assertNull($result['items'][0]['consumed_cost']);
        self::assertNull($result['actual_consumed_cost']);
        self::assertNull($result['known_consumed_cost']);
        self::assertSame('partial',$result['status']);
        self::assertContains('unit_price',$result['items'][0]['missing_items']);
    }

    public function testExcludedDerivedOverflowDoesNotBlockAnEnabledRow(): void
    {
        $input=$this->input();
        $input['items'][]=array_replace($input['items'][0],['id'=>'excluded','enabled'=>false,'opening_quantity'=>1e12,'purchased_quantity'=>0,'unit_price'=>2]);
        $result=(new ConsumablesActualCostService())->calculate($input);
        self::assertSame(200.0,$result['actual_consumed_cost']);
        self::assertSame('excluded',$result['items'][1]['status']);
        self::assertSame(1e12,$result['inputs']['items'][1]['opening_quantity']);
        self::assertNull($result['items'][1]['consumed_quantity']);
        self::assertNull($result['items'][1]['consumed_cost']);
    }

    public function testKnownRealZeroRemainsZeroBesideAnUnpricedRow(): void
    {
        $input=$this->input(['purchased_quantity'=>0]);
        $input['items'][]=array_replace($input['items'][0],['id'=>'unpriced','purchased_quantity'=>100,'unit_price'=>null]);
        $result=(new ConsumablesActualCostService())->calculate($input);
        self::assertNull($result['actual_consumed_cost']);
        self::assertSame(0.0,$result['known_consumed_cost']);
        self::assertSame(100.0,$result['items'][1]['consumed_quantity']);
    }

    public function testFractionalRoomNightCountIsRejected(): void
    {
        $input=$this->input();$input['occupied_room_nights']=0.5;
        $this->expectException(InvalidArgumentException::class);
        (new ConsumablesActualCostService())->calculate($input);
    }

    public function testMissingAndZeroRoomNightsRemainDistinctAndCannotBeDivisors(): void
    {
        $service=new ConsumablesActualCostService();
        foreach ([null,0] as $nights) {
            $input=$this->input();$input['occupied_room_nights']=$nights;
            $result=$service->calculate($input);
            self::assertSame($nights === null ? null : 0.0,$result['inputs']['occupied_room_nights']);
            self::assertSame(200.0,$result['actual_consumed_cost']);
            self::assertNull($result['actual_consumables_cost_per_room_night']);
            self::assertSame('partial',$result['status']);
        }
    }

    public function testUnitCostDoesNotRoundAnExistingCentIntoZero(): void
    {
        $input=$this->input(['purchased_quantity'=>1,'unit_price'=>0.01]);$input['occupied_room_nights']=100000;
        $result=(new ConsumablesActualCostService())->calculate($input);
        self::assertSame(0.01,$result['actual_consumed_cost']);
        self::assertSame(1e-7,$result['actual_consumables_cost_per_room_night']);
    }

    public static function unrepresentableAmounts(): array
    {
        return ['consumption multiplication'=>[['purchased_quantity'=>1e-200,'unit_price'=>1e-200]],
            'separate loss multiplication'=>[['purchased_quantity'=>1e-200,'written_off_quantity'=>1e-200,'unit_price'=>1e-200]],
            'budget multiplication'=>[['purchased_quantity'=>0,'budget_unit_price'=>1e-200,'budget_usage_per_room_night'=>1e-200]],
            'raw quantity underflow'=>[['purchased_quantity'=>'1e-400']]];
    }

    #[DataProvider('unrepresentableAmounts')]
    public function testPositiveUnderflowIsRejectedBeforeBecomingKnownZero(array $changes): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ConsumablesActualCostService())->calculate($this->input($changes));
    }

    public function testRepresentableAmountStillUsesCnyCentRounding(): void
    {
        $result=(new ConsumablesActualCostService())->calculate($this->input(['purchased_quantity'=>0.001,'unit_price'=>1]));
        self::assertSame(0.001,$result['items'][0]['consumed_quantity']);
        self::assertSame(0.0,$result['actual_consumed_cost']);
        self::assertSame('calculated',$result['status']);
    }
}
