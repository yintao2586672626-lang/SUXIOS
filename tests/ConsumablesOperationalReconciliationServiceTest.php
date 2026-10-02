<?php
declare(strict_types=1);

use app\service\ConsumablesActualCostService;
use app\service\ConsumablesOperationalReconciliationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConsumablesOperationalReconciliationServiceTest extends TestCase
{
    private function input(): array
    {
        return ['occupied_room_nights' => 100, 'occupied_room_nights_source_ref' => 'synthetic-whole-hotel-pms',
            'denominator_scope' => 'whole_hotel', 'operator_attested' => true, 'cleaning_count' => 80,
            'cleaning_count_source_ref' => 'synthetic-cleaning-register', 'items' => [[
                'id' => 'towel', 'name' => '合成耗材', 'enabled' => true, 'unit' => 'piece',
                'source_ref' => 'synthetic-inventory-and-valuation', 'source_date' => '2026-10-02',
                'opening_quantity' => 30, 'purchased_quantity' => 100, 'transfer_in_quantity' => 0,
                'closing_quantity' => 20, 'transfer_out_quantity' => 0, 'returned_quantity' => 0,
                'written_off_quantity' => 10, 'unit_price' => 2, 'budget_unit_price' => 1.5,
                'budget_usage_per_room_night' => 0.8, 'issued_quantity' => 90,
                'issued_quantity_source_ref' => 'synthetic-issue-register', 'book_closing_quantity' => 18,
                'book_closing_quantity_source_ref' => 'synthetic-stock-ledger',
            ]]];
    }

    public function testIssuesCountDifferenceAndCleaningCostRetainSeparateMeanings(): void
    {
        $result = (new ConsumablesOperationalReconciliationService())->calculate($this->input());
        $row = $result['items'][0];
        self::assertSame(200.0, $result['actual_consumed_cost']);
        self::assertSame(20.0, $result['separate_loss_cost']);
        self::assertSame(2.0, $result['actual_consumables_cost_per_room_night']);
        self::assertSame(180.0, $row['issued_cost']);
        self::assertSame(10.0, $row['inventory_balance_minus_issued_quantity']);
        self::assertSame(20.0, $row['inventory_balance_minus_issued_cost']);
        self::assertSame(2.0, $row['counted_minus_book_closing_quantity']);
        self::assertSame(4.0, $row['counted_minus_book_closing_cost']);
        self::assertSame(2.5, $result['reconciliation']['inventory_balance_cost_per_cleaning']);
        self::assertSame(1.8, $result['reconciliation']['issued_cost_per_room_night']);
        self::assertSame('calculated', $result['reconciliation']['status']);
        self::assertSame('operator_attested', $result['reconciliation']['source_quality']);
        self::assertTrue($result['reconciliation']['boundaries']['inventory_count_difference_is_not_automatic_loss']);
        self::assertFalse($result['reconciliation']['boundaries']['source_independently_verified']);
        self::assertSame($result['items'], $result['inputs']['items']);
    }

    public function testLegacyInputsRemainCalculatedWhileAdditionalEvidenceStaysMissing(): void
    {
        $input = $this->input();
        unset($input['cleaning_count'], $input['cleaning_count_source_ref']);
        foreach (['issued_quantity', 'issued_quantity_source_ref', 'book_closing_quantity', 'book_closing_quantity_source_ref'] as $key) unset($input['items'][0][$key]);
        $base = (new ConsumablesActualCostService())->calculate($input);
        $result = (new ConsumablesOperationalReconciliationService())->calculate($input);
        foreach (['status', 'source_quality', 'actual_consumed_cost', 'separate_loss_cost', 'actual_consumables_cost_per_room_night', 'missing_items'] as $key) self::assertSame($base[$key], $result[$key]);
        self::assertSame('missing', $result['reconciliation']['status']);
        self::assertNull($result['reconciliation']['issued_cost']);
        self::assertNull($result['reconciliation']['known_issued_cost']);
        self::assertNull($result['reconciliation']['counted_minus_book_closing_cost']);
        self::assertNull($result['reconciliation']['inventory_balance_cost_per_cleaning']);
        self::assertNull($result['inputs']['items'][0]['issued_quantity']);
        self::assertSame('', $result['inputs']['items'][0]['issued_quantity_source_ref']);
    }

    public function testMissingProvenanceNeverPromotesRecordedNumbersIntoComparableCost(): void
    {
        $input = $this->input();
        $input['items'][0]['issued_quantity_source_ref'] = '';
        $input['items'][0]['book_closing_quantity_source_ref'] = '';
        $input['cleaning_count_source_ref'] = '';
        $result = (new ConsumablesOperationalReconciliationService())->calculate($input);
        self::assertSame(90.0, $result['inputs']['items'][0]['issued_quantity']);
        self::assertSame(18.0, $result['inputs']['items'][0]['book_closing_quantity']);
        self::assertNull($result['items'][0]['issued_cost']);
        self::assertNull($result['reconciliation']['known_issued_cost']);
        self::assertNull($result['items'][0]['inventory_balance_minus_issued_quantity']);
        self::assertNull($result['items'][0]['counted_minus_book_closing_quantity']);
        self::assertNull($result['reconciliation']['inventory_balance_cost_per_cleaning']);
        self::assertSame('partial', $result['reconciliation']['status']);
        self::assertContains('towel:issued_quantity_source_ref', $result['reconciliation']['missing_items']);
        self::assertContains('cleaning_count_source_missing', $result['reconciliation']['missing_items']);
    }

    public function testRealZerosAreRetainedAndCountShortageDoesNotChangeLoss(): void
    {
        $input = $this->input();
        $input['items'][0]['issued_quantity'] = 0;
        $input['items'][0]['book_closing_quantity'] = 25;
        $input['cleaning_count'] = 0;
        $result = (new ConsumablesOperationalReconciliationService())->calculate($input);
        self::assertSame(0.0, $result['reconciliation']['issued_cost']);
        self::assertSame(0.0, $result['reconciliation']['issued_cost_per_room_night']);
        self::assertSame(-5.0, $result['items'][0]['counted_minus_book_closing_quantity']);
        self::assertSame(-10.0, $result['reconciliation']['counted_minus_book_closing_cost']);
        self::assertSame(20.0, $result['separate_loss_cost']);
        self::assertSame(0.0, $result['inputs']['cleaning_count']);
        self::assertNull($result['reconciliation']['inventory_balance_cost_per_cleaning']);
        self::assertContains('cleaning_count_missing_or_zero', $result['reconciliation']['missing_items']);
    }

    public function testPartialCoverageCannotBecomeACompleteTotalAndDisabledRowsArePreserved(): void
    {
        $input = $this->input();
        $input['items'][] = array_replace($input['items'][0], ['id' => 'liquid', 'unit' => 'ml', 'issued_quantity' => '', 'book_closing_quantity' => '']);
        $result = (new ConsumablesOperationalReconciliationService())->calculate($input);
        self::assertSame(400.0, $result['actual_consumed_cost']);
        self::assertNull($result['reconciliation']['issued_cost']);
        self::assertSame(180.0, $result['reconciliation']['known_issued_cost']);
        self::assertNull($result['reconciliation']['counted_minus_book_closing_cost']);
        self::assertSame(2, $result['reconciliation']['coverage']['enabled_item_count']);
        self::assertSame(1, $result['reconciliation']['coverage']['issued_cost_ready_count']);
        self::assertArrayNotHasKey('issued_quantity', $result['reconciliation']);
        $input['items'][1]['enabled'] = false;
        $excluded = (new ConsumablesOperationalReconciliationService())->calculate($input);
        self::assertSame(180.0, $excluded['reconciliation']['issued_cost']);
        self::assertSame('excluded', $excluded['items'][1]['reconciliation_status']);
        self::assertNull($excluded['items'][1]['issued_cost']);
        self::assertCount(2, $excluded['inputs']['items']);
    }

    public function testIncompleteInventoryStillAllowsSourcedIssueAndCountComparisonButNotConsumptionDifference(): void
    {
        $input = $this->input();
        $input['items'][0]['opening_quantity'] = '';
        $input['operator_attested'] = false;
        $result = (new ConsumablesOperationalReconciliationService())->calculate($input);
        self::assertNull($result['actual_consumed_cost']);
        self::assertSame(180.0, $result['reconciliation']['issued_cost']);
        self::assertSame(4.0, $result['reconciliation']['counted_minus_book_closing_cost']);
        self::assertNull($result['reconciliation']['inventory_balance_minus_issued_cost']);
        self::assertNull($result['reconciliation']['inventory_balance_cost_per_cleaning']);
        self::assertSame('unverified', $result['reconciliation']['source_quality']);
        self::assertSame('partial', $result['reconciliation']['status']);
    }

    public function testNormalizedEvidenceCanBeRecalculatedWithoutChangingInputsOrMetrics(): void
    {
        $service = new ConsumablesOperationalReconciliationService();
        $saved = $service->calculate($this->input());
        $readback = json_decode(json_encode($saved['inputs'], JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($saved, $service->calculate($readback));
    }

    public function testMissingHotelRoomNightEvidenceKeepsCleaningCalculationButNotPerNightCost(): void
    {
        $input = $this->input();
        $input['occupied_room_nights_source_ref'] = '';
        $result = (new ConsumablesOperationalReconciliationService())->calculate($input);
        self::assertSame(2.5, $result['reconciliation']['inventory_balance_cost_per_cleaning']);
        self::assertNull($result['reconciliation']['issued_cost_per_room_night']);
        self::assertSame('partial', $result['reconciliation']['status']);
        self::assertFalse($result['reconciliation']['coverage']['whole_hotel_room_night_denominator_ready']);
        self::assertContains('whole_hotel_room_nights_source_missing', $result['reconciliation']['inventory_balance_missing_items']);
    }

    public static function invalidInputs(): array
    {
        return ['negative issue' => ['issued_quantity', -1, true], 'boolean issue' => ['issued_quantity', false, true],
            'not a number' => ['book_closing_quantity', 'unknown', true], 'infinite book count' => ['book_closing_quantity', INF, true],
            'source object' => ['issued_quantity_source_ref', ['secret' => 'not-used'], true],
            'negative cleaning' => ['cleaning_count', -1, false], 'boolean cleaning' => ['cleaning_count', true, false],
            'source too long' => ['cleaning_count_source_ref', str_repeat('x', 501), false]];
    }

    #[DataProvider('invalidInputs')]
    public function testInvalidEvidenceIsRejected(string $field, mixed $value, bool $row): void
    {
        $input = $this->input();
        if ($row) $input['items'][0][$field] = $value;
        else $input[$field] = $value;
        $this->expectException(InvalidArgumentException::class);
        (new ConsumablesOperationalReconciliationService())->calculate($input);
    }

    public function testAdditionalAmountOverflowIsRejectedBeforePersistence(): void
    {
        $input = $this->input();
        $input['items'][0]['issued_quantity'] = 1e12;
        $this->expectException(InvalidArgumentException::class);
        (new ConsumablesOperationalReconciliationService())->calculate($input);
    }

    public function testTinyCleaningDenominatorCannotBecomeInfiniteUnitCost(): void
    {
        $input = $this->input();
        $input['cleaning_count'] = 1e-320;
        $this->expectException(InvalidArgumentException::class);
        (new ConsumablesOperationalReconciliationService())->calculate($input);
    }

    public function testExcludedEvidenceDoesNotCalculateUnusedOverflowingAmount(): void
    {
        $input = $this->input();
        $input['items'][] = array_replace($input['items'][0], ['id'=>'excluded','enabled'=>false,'issued_quantity'=>1e12]);
        $result = (new ConsumablesOperationalReconciliationService())->calculate($input);
        self::assertSame('excluded', $result['items'][1]['reconciliation_status']);
        self::assertSame(1e12, $result['inputs']['items'][1]['issued_quantity']);
        self::assertNull($result['items'][1]['issued_cost']);
        self::assertNull($result['items'][1]['inventory_balance_minus_issued_cost']);
        self::assertSame(180.0, $result['reconciliation']['issued_cost']);
    }

    public function testSignedCountTotalsDoNotDependOnRowOrder(): void
    {
        $input = $this->input(); $row = $input['items'][0];
        $row = array_replace($row, ['unit_price'=>1,'purchased_quantity'=>0,'transfer_in_quantity'=>0,'transfer_out_quantity'=>0,'returned_quantity'=>0,'written_off_quantity'=>0,'issued_quantity'=>0,'budget_unit_price'=>null,'budget_usage_per_room_night'=>null]);
        $input['items'] = [
            array_replace($row,['id'=>'positive1','opening_quantity'=>8e11,'closing_quantity'=>8e11,'book_closing_quantity'=>0]),
            array_replace($row,['id'=>'positive2','opening_quantity'=>8e11,'closing_quantity'=>8e11,'book_closing_quantity'=>0]),
            array_replace($row,['id'=>'negative','opening_quantity'=>0,'closing_quantity'=>0,'book_closing_quantity'=>8e11]),
        ];
        $service = new ConsumablesOperationalReconciliationService();
        $first = $service->calculate($input);
        $input['items'] = [$input['items'][0],$input['items'][2],$input['items'][1]];
        $second = $service->calculate($input);
        self::assertSame(8e11, $first['reconciliation']['counted_minus_book_closing_cost']);
        self::assertSame($first['reconciliation'], $second['reconciliation']);
    }

    public function testSignedMoneyTotalsRetainCentsAcrossLargeCancellation(): void
    {
        $input = $this->input(); $row = $input['items'][0];
        $row = array_replace($row, ['unit_price'=>1,'purchased_quantity'=>0,'transfer_in_quantity'=>0,'transfer_out_quantity'=>0,'returned_quantity'=>0,'written_off_quantity'=>0,'issued_quantity'=>0,'budget_unit_price'=>null,'budget_usage_per_room_night'=>null]);
        $positive = []; $negative = []; $interleaved = [];
        for ($index = 0; $index < 30; $index++) {
            $positive[] = array_replace($row,['id'=>'positive'.$index,'opening_quantity'=>999999999999.99,'closing_quantity'=>999999999999.99,'book_closing_quantity'=>0]);
            $negative[] = array_replace($row,['id'=>'negative'.$index,'opening_quantity'=>0,'closing_quantity'=>0,'book_closing_quantity'=>1e12]);
            $interleaved[] = $positive[$index]; $interleaved[] = $negative[$index];
        }
        $input['items'] = array_merge($positive, $negative);
        $service = new ConsumablesOperationalReconciliationService();
        $first = $service->calculate($input);
        $input['items'] = $interleaved;
        $second = $service->calculate($input);
        self::assertSame(-0.30, $first['reconciliation']['counted_minus_book_closing_cost']);
        self::assertSame($first['reconciliation'], $second['reconciliation']);
    }

    public function testUnreliableInventoryCancellationCannotProduceFakeZero(): void
    {
        $input = $this->input();
        $input['items'][0] = array_replace($input['items'][0], ['opening_quantity'=>1e12,'closing_quantity'=>1e12,'book_closing_quantity'=>1e12,'purchased_quantity'=>0.00001,'unit_price'=>1e12,'written_off_quantity'=>0,'issued_quantity'=>0,'budget_unit_price'=>null,'budget_usage_per_room_night'=>null]);
        $this->expectException(InvalidArgumentException::class);
        (new ConsumablesOperationalReconciliationService())->calculate($input);
    }

    public function testRoundedNegativeBalanceCannotProduceFakeZero(): void
    {
        $input = $this->input();
        $input['items'][0] = array_replace($input['items'][0], ['opening_quantity'=>0,'purchased_quantity'=>0,'closing_quantity'=>1e-7,'book_closing_quantity'=>1e-7,'unit_price'=>1e12,'written_off_quantity'=>0,'issued_quantity'=>0,'budget_unit_price'=>null,'budget_usage_per_room_night'=>null]);
        $this->expectException(InvalidArgumentException::class);
        (new ConsumablesOperationalReconciliationService())->calculate($input);
    }

    public function testOrdinaryDecimalNoiseIsAcceptedAsZeroBalance(): void
    {
        $input = $this->input();
        $input['items'][0] = array_replace($input['items'][0], ['opening_quantity'=>0.3,'purchased_quantity'=>0,'closing_quantity'=>0.1,'book_closing_quantity'=>0.1,'written_off_quantity'=>0.2,'issued_quantity'=>0,'budget_unit_price'=>null,'budget_usage_per_room_night'=>null]);
        $result = (new ConsumablesOperationalReconciliationService())->calculate($input);
        self::assertSame(0.0, $result['actual_consumed_cost']);
        self::assertSame('calculated', $result['status']);
    }

    public function testPositiveSubMicroConsumptionRetainsItsValuedIssueDifference(): void
    {
        $input = $this->input();
        $input['items'][0] = array_replace($input['items'][0], ['opening_quantity'=>0,'purchased_quantity'=>1e-7,'closing_quantity'=>0,'written_off_quantity'=>0,'issued_quantity'=>0,'book_closing_quantity'=>0,'unit_price'=>1e12,'budget_unit_price'=>null,'budget_usage_per_room_night'=>null]);
        $result = (new ConsumablesOperationalReconciliationService())->calculate($input);
        self::assertSame(1e-7, $result['items'][0]['consumed_quantity']);
        self::assertSame(100000.0, $result['actual_consumed_cost']);
        self::assertSame(1e-7, $result['items'][0]['inventory_balance_minus_issued_quantity']);
        self::assertSame(100000.0, $result['reconciliation']['inventory_balance_minus_issued_cost']);
        self::assertSame('calculated', $result['reconciliation']['status']);
    }

    public function testSubMicroCountDifferenceIsValuedWithoutCreatingWriteoff(): void
    {
        $input = $this->input();
        $input['items'][0] = array_replace($input['items'][0], ['opening_quantity'=>1e-7,'purchased_quantity'=>0,'closing_quantity'=>1e-7,'written_off_quantity'=>0,'issued_quantity'=>0,'book_closing_quantity'=>0,'unit_price'=>1e12,'budget_unit_price'=>null,'budget_usage_per_room_night'=>null]);
        $result = (new ConsumablesOperationalReconciliationService())->calculate($input);
        self::assertSame(1e-7, $result['items'][0]['counted_minus_book_closing_quantity']);
        self::assertSame(100000.0, $result['reconciliation']['counted_minus_book_closing_cost']);
        self::assertSame(0.0, $result['actual_consumed_cost']);
        self::assertSame(0.0, $result['separate_loss_cost']);
        self::assertTrue($result['reconciliation']['boundaries']['inventory_count_difference_is_not_automatic_loss']);
    }

    public function testSubMicroIssueDifferenceKeepsItsSignedAmount(): void
    {
        $input = $this->input();
        $input['items'][0] = array_replace($input['items'][0], ['opening_quantity'=>0,'purchased_quantity'=>0,'closing_quantity'=>0,'written_off_quantity'=>0,'issued_quantity'=>1e-7,'book_closing_quantity'=>0,'unit_price'=>1e12,'budget_unit_price'=>null,'budget_usage_per_room_night'=>null]);
        $result = (new ConsumablesOperationalReconciliationService())->calculate($input);
        self::assertSame(100000.0, $result['reconciliation']['issued_cost']);
        self::assertSame(-1e-7, $result['items'][0]['inventory_balance_minus_issued_quantity']);
        self::assertSame(-100000.0, $result['reconciliation']['inventory_balance_minus_issued_cost']);
        self::assertSame(0.0, $result['actual_consumed_cost']);
    }

    public function testNegativeTinyFlowBesideLargeEqualStocksIsRejected(): void
    {
        $input = $this->input();
        $input['items'][0] = array_replace($input['items'][0], ['opening_quantity'=>1e12,'purchased_quantity'=>0,'closing_quantity'=>1e12,'transfer_out_quantity'=>1e-7,'written_off_quantity'=>0,'issued_quantity'=>0,'book_closing_quantity'=>1e12,'unit_price'=>1e12,'budget_unit_price'=>null,'budget_usage_per_room_night'=>null]);
        $this->expectException(InvalidArgumentException::class);
        (new ConsumablesOperationalReconciliationService())->calculate($input);
    }

    public function testDisabledInventoryAndIssueOverflowCannotBlockActiveRows(): void
    {
        $input = $this->input();
        $input['items'][] = array_replace($input['items'][0], ['id'=>'excluded','enabled'=>false,'opening_quantity'=>1e12,'purchased_quantity'=>0,'closing_quantity'=>0,'written_off_quantity'=>0,'issued_quantity'=>1e12]);
        $result = (new ConsumablesOperationalReconciliationService())->calculate($input);
        self::assertSame(200.0, $result['actual_consumed_cost']);
        self::assertSame(180.0, $result['reconciliation']['issued_cost']);
        self::assertNull($result['items'][1]['consumed_quantity']);
        self::assertNull($result['items'][1]['consumed_cost']);
        self::assertNull($result['items'][1]['issued_cost']);
        self::assertSame(1e12, $result['inputs']['items'][1]['opening_quantity']);
        self::assertSame('excluded', $result['items'][1]['reconciliation_status']);
    }

    public function testMissingUnitPriceKeepsSourcedQuantitiesButNoKnownMoney(): void
    {
        $input = $this->input();
        $input['items'][0]['unit_price'] = null;
        $result = (new ConsumablesOperationalReconciliationService())->calculate($input);
        self::assertSame(100.0, $result['items'][0]['consumed_quantity']);
        self::assertSame(10.0, $result['items'][0]['inventory_balance_minus_issued_quantity']);
        self::assertSame(2.0, $result['items'][0]['counted_minus_book_closing_quantity']);
        foreach (['actual_consumed_cost','known_consumed_cost'] as $field) self::assertNull($result[$field]);
        foreach (['issued_cost','known_issued_cost','inventory_balance_minus_issued_cost','counted_minus_book_closing_cost'] as $field) self::assertNull($result['reconciliation'][$field]);
        self::assertSame('partial', $result['reconciliation']['status']);
    }

    public function testFractionalCleaningCountCannotBecomeAUnitCost(): void
    {
        $input = $this->input();
        $input['cleaning_count'] = 0.5;
        $this->expectException(InvalidArgumentException::class);
        (new ConsumablesOperationalReconciliationService())->calculate($input);
    }

    public function testMissingCleaningCountIsNullAndDoesNotConsumeAFalseZero(): void
    {
        $input = $this->input();
        $input['cleaning_count'] = null;
        $result = (new ConsumablesOperationalReconciliationService())->calculate($input);
        self::assertNull($result['inputs']['cleaning_count']);
        self::assertNull($result['reconciliation']['inventory_balance_cost_per_cleaning']);
        self::assertFalse($result['reconciliation']['coverage']['cleaning_denominator_ready']);
        self::assertSame(200.0, $result['actual_consumed_cost']);
        self::assertSame('partial', $result['reconciliation']['status']);
    }

    public function testSmallPositiveUnitCostsAndEvidenceSurviveJsonReadback(): void
    {
        $input = $this->input();
        $input['occupied_room_nights'] = 100000;
        $input['cleaning_count'] = 100000;
        $input['items'][0] = array_replace($input['items'][0], ['opening_quantity'=>0,'purchased_quantity'=>0.01,'closing_quantity'=>0,'written_off_quantity'=>0,'issued_quantity'=>0.01,'book_closing_quantity'=>0,'unit_price'=>1,'budget_unit_price'=>null,'budget_usage_per_room_night'=>null]);
        $service = new ConsumablesOperationalReconciliationService();
        $result = $service->calculate($input);
        self::assertSame(1e-7, $result['actual_consumables_cost_per_room_night']);
        self::assertSame(1e-7, $result['reconciliation']['issued_cost_per_room_night']);
        self::assertSame(1e-7, $result['reconciliation']['inventory_balance_cost_per_cleaning']);
        $readback = json_decode(json_encode($result['inputs'], JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($result, $service->calculate($readback));
    }

    public static function unrepresentableEvidence(): array
    {
        return ['issue product'=>[['opening_quantity'=>0,'closing_quantity'=>0,'issued_quantity'=>1e-200,'book_closing_quantity'=>0]],
            'count product'=>[['opening_quantity'=>1e-200,'closing_quantity'=>1e-200,'issued_quantity'=>0,'book_closing_quantity'=>0]],
            'raw issue quantity'=>[['opening_quantity'=>0,'closing_quantity'=>0,'issued_quantity'=>'1e-400','book_closing_quantity'=>0]]];
    }

    #[DataProvider('unrepresentableEvidence')]
    public function testNonzeroEvidenceUnderflowCannotBecomeCalculatedZero(array $changes): void
    {
        $input = $this->input();
        $input['items'][0] = array_replace($input['items'][0], ['purchased_quantity'=>0,'written_off_quantity'=>0,'unit_price'=>1e-200,'budget_unit_price'=>null,'budget_usage_per_room_night'=>null], $changes);
        $this->expectException(InvalidArgumentException::class);
        (new ConsumablesOperationalReconciliationService())->calculate($input);
    }
}
