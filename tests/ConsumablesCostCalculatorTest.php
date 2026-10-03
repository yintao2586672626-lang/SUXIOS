<?php
declare(strict_types=1);

namespace Tests;

use app\service\ConsumablesCostCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\Support\InvestmentScenarioFixture as Fixture;

require_once __DIR__ . '/Support/InvestmentScenarioFixture.php';

final class ConsumablesCostCalculatorTest extends TestCase
{
    public function testAbsentEmptyAndAllDisabledRemainUnknownRatherThanZero(): void
    {
        $calculator = new ConsumablesCostCalculator();
        self::assertNull($calculator->normalize(null));
        foreach ([null, $this->input([]), $this->input([$this->item(['enabled' => false])])] as $input) {
            $result = $calculator->evaluate($calculator->normalize($input));
            self::assertSame('empty', $result['status']);
            self::assertNull($result['known_subtotal_per_night']);
            self::assertNull($result['consumables_per_night']);
            self::assertNull($result['effective_operating_cost_per_night']);
            self::assertContains('consumables_cost.items', $result['missing_fields']);
        }
    }

    public function testBottleGuestNightsAndOtherCostUseUnitsAndPreservePrecision(): void
    {
        $calculator = new ConsumablesCostCalculator();
        $input = $this->input([$this->item([
            'package_price' => 30, 'package_quantity' => 500, 'unit' => 'ml', 'usage_quantity' => 10,
            'usage_basis' => 'guest_night', 'occurrences_per_occupied_night' => 1.5,
        ])], 20);
        $result = $calculator->evaluate($calculator->normalize($input));
        self::assertSame('ready', $result['status']);
        self::assertEqualsWithDelta(0.06, $result['items'][0]['unit_cost'], 0.0000000001);
        self::assertEqualsWithDelta(0.9, $result['consumables_per_night'], 0.0000000001);
        self::assertEqualsWithDelta(20.9, $result['effective_operating_cost_per_night'], 0.0000000001);
        self::assertSame([], $result['missing_fields']);
        self::assertSame('scenario_assumption', $result['source_quality']);

        $thirds = $calculator->evaluate($calculator->normalize($this->input([
            $this->item(['id' => 'one', 'package_price' => 1, 'package_quantity' => 3]),
            $this->item(['id' => 'two', 'package_price' => 1, 'package_quantity' => 3]),
            $this->item(['id' => 'three', 'package_price' => 1, 'package_quantity' => 3]),
        ])));
        self::assertEqualsWithDelta(1.0, $thirds['consumables_per_night'], 0.0000000001);
        self::assertNotSame(0.33, $thirds['items'][0]['cost_per_occupied_night']);
    }

    public function testCleaningAndSoldNightConversionsHaveDistinctMeaning(): void
    {
        $calculator = new ConsumablesCostCalculator();
        $sold = $calculator->normalize($this->input([$this->item(['occurrences_per_occupied_night' => 12])]));
        self::assertSame(1.0, $sold['items'][0]['occurrences_per_occupied_night']);
        self::assertSame(2.0, $calculator->evaluate($sold)['consumables_per_night']);

        $cleaning = $calculator->evaluate($calculator->normalize($this->input([$this->item([
            'unit' => 'g', 'usage_basis' => 'cleaning', 'occurrences_per_occupied_night' => 0.5,
        ])])));
        self::assertSame(1.0, $cleaning['consumables_per_night']);
    }

    public function testExplicitZeroPriceUsageAndConversionRemainKnownValues(): void
    {
        $calculator = new ConsumablesCostCalculator();
        foreach ([['package_price' => 0], ['usage_quantity' => 0], ['usage_basis' => 'guest_night', 'occurrences_per_occupied_night' => 0]] as $changes) {
            $result = $calculator->evaluate($calculator->normalize($this->input([$this->item($changes)])));
            self::assertSame('ready', $result['status']);
            self::assertSame(0.0, $result['known_subtotal_per_night']);
            self::assertSame(0.0, $result['consumables_per_night']);
            self::assertSame(0.0, $result['effective_operating_cost_per_night']);
        }
    }

    public function testPartialItemsKeepKnownSubtotalButDoNotInventCompleteTotals(): void
    {
        $calculator = new ConsumablesCostCalculator();
        $result = $calculator->evaluate($calculator->normalize($this->input([
            $this->item(['id' => 'known']), $this->item(['id' => 'unknown', 'package_price' => '', 'usage_quantity' => '  ']),
        ])));
        self::assertSame('partial', $result['status']);
        self::assertSame(2.0, $result['known_subtotal_per_night']);
        self::assertNull($result['consumables_per_night']);
        self::assertNull($result['effective_operating_cost_per_night']);
        self::assertSame(['package_price', 'usage_quantity'], $result['items'][1]['missing_fields']);
        self::assertContains('consumables_cost.items.1.package_price', $result['missing_fields']);
        self::assertContains('consumables_cost.items.1.usage_quantity', $result['missing_fields']);

        $allUnknown = $calculator->evaluate($calculator->normalize($this->input([$this->item(['package_price' => null])])));
        self::assertSame('partial', $allUnknown['status']);
        self::assertNull($allUnknown['known_subtotal_per_night']);
        self::assertNull($allUnknown['consumables_per_night']);
        self::assertNull($allUnknown['effective_operating_cost_per_night']);

        $zeroAndUnknown = $calculator->evaluate($calculator->normalize($this->input([
            $this->item(['id' => 'known-zero', 'usage_quantity' => 0]), $this->item(['id' => 'unknown', 'package_price' => null]),
        ])));
        self::assertSame(0.0, $zeroAndUnknown['known_subtotal_per_night']);
        self::assertNull($zeroAndUnknown['consumables_per_night']);

        foreach (['guest_night', 'cleaning'] as $basis) {
            $missingConversion = $calculator->evaluate($calculator->normalize($this->input([$this->item([
                'usage_basis' => $basis, 'occurrences_per_occupied_night' => null,
            ])])));
            self::assertContains('occurrences_per_occupied_night', $missingConversion['items'][0]['missing_fields']);
            self::assertNull($missingConversion['consumables_per_night']);
        }
    }

    public function testMissingOtherCostsNeverBecomeZeroAndZeroPriceDoesNotHideMissingQuantity(): void
    {
        $calculator = new ConsumablesCostCalculator();
        $result = $calculator->evaluate($calculator->normalize($this->input([$this->item()], null)));
        self::assertSame('partial', $result['status']);
        self::assertSame(2.0, $result['consumables_per_night']);
        self::assertNull($result['other_variable_cost_per_night']);
        self::assertNull($result['effective_operating_cost_per_night']);
        self::assertContains('consumables_cost.other_variable_cost_per_night', $result['missing_fields']);

        $missing = $calculator->evaluate($calculator->normalize($this->input([$this->item(['package_price' => 0, 'package_quantity' => null])])));
        self::assertNull($missing['items'][0]['unit_cost']);
        self::assertNull($missing['consumables_per_night']);
        self::assertContains('package_quantity', $missing['items'][0]['missing_fields']);
    }

    public function testDisabledRowsArePreservedAndExcludedIncludingTheirMissingFields(): void
    {
        $calculator = new ConsumablesCostCalculator();
        $result = $calculator->evaluate($calculator->normalize($this->input([
            $this->item(['id' => 'active']), $this->item(['id' => 'disabled', 'enabled' => false, 'package_price' => 999]),
            $this->item(['id' => 'draft', 'enabled' => false, 'package_price' => null]),
        ])));
        self::assertSame('ready', $result['status']);
        self::assertCount(3, $result['items']);
        self::assertSame(false, $result['items'][1]['enabled']);
        self::assertSame(999.0, $result['items'][1]['package_price']);
        self::assertSame(2.0, $result['consumables_per_night']);
        self::assertSame([], $result['missing_fields']);
        self::assertSame(['package_price'], $result['items'][2]['missing_fields']);

        $disabledOverflow = $calculator->evaluate($calculator->normalize($this->input([
            $this->item(['id' => 'active']),
            $this->item(['id' => 'disabled', 'enabled' => false, 'package_price' => 1.0e308, 'package_quantity' => 1.0e-308]),
        ])));
        self::assertSame('ready', $disabledOverflow['status']);
        self::assertSame(2.0, $disabledOverflow['effective_operating_cost_per_night']);
        self::assertNull($disabledOverflow['items'][1]['cost_per_occupied_night']);
    }

    public function testNormalizationIsCanonicalTrimsTextsAndNeverPromotesSourceClaims(): void
    {
        $calculator = new ConsumablesCostCalculator();
        $raw = $this->input([$this->item([
            'id' => ' item ', 'name' => ' 合成物料 ', 'package_price' => '20', 'package_quantity' => '10',
            'source_label' => ' 手工假设 ', 'as_of' => ' 2026-10-01 ',
        ])]);
        $raw['source_quality'] = 'hotel_verified';
        $raw['tenant_id'] = 7;
        $normalized = $calculator->normalize($raw);
        self::assertSame($normalized, $calculator->normalize($normalized));
        self::assertSame('item', $normalized['items'][0]['id']);
        self::assertSame('合成物料', $normalized['items'][0]['name']);
        self::assertSame('手工假设', $normalized['items'][0]['source_label']);
        self::assertSame('2026-10-01', $normalized['items'][0]['as_of']);
        self::assertArrayNotHasKey('tenant_id', $normalized);
        self::assertArrayNotHasKey('source_quality', $normalized);
        self::assertSame('scenario_assumption', $calculator->evaluate($normalized)['source_quality']);
        $withoutSchema = $this->input([]);
        unset($withoutSchema['schema_version']);
        self::assertSame('consumables-v1', $calculator->normalize($withoutSchema)['schema_version']);
    }

    public function testManualAndDerivedEvaluateSameAmountsWithoutApplyingBusinessChanges(): void
    {
        $calculator = new ConsumablesCostCalculator();
        $manual = $this->input([$this->item()]);
        $derived = array_replace($manual, ['mode' => 'derived']);
        self::assertSame($calculator->evaluate($calculator->normalize($manual)), $calculator->evaluate($calculator->normalize($derived)));
        self::assertSame($manual, $this->input([$this->item()]));
    }

    public function testInvalidTypesSchemasDuplicateIdsBooleansAndDatesAreRejected(): void
    {
        $calculator = new ConsumablesCostCalculator();
        $base = $this->input([$this->item()]);
        $cases = [
            array_replace($base, ['schema_version' => 'consumables-v2']), array_replace($base, ['schema_version' => null]),
            array_replace($base, ['mode' => 'automatic']), array_replace($base, ['mode' => null]),
            array_replace($base, ['items' => null]), array_replace($base, ['items' => ['not_a_list' => $this->item()]]),
            array_replace($base, ['items' => ['string']]),
            array_replace($base, ['items' => [$this->item(), $this->item(['id' => ' item '])]]),
            array_replace($base, ['other_variable_cost_per_night' => -1]),
        ];
        foreach ([['id' => ''], ['id' => str_repeat('x', 65)], ['name' => str_repeat('名', 161)], ['source_label' => str_repeat('x', 301)],
            ['enabled' => 1], ['enabled' => 'true'], ['enabled' => null], ['unit' => 'bottle'], ['usage_basis' => 'month'],
            ['package_price' => -1], ['package_price' => true], ['package_price' => INF], ['package_price' => NAN], ['package_price' => 'abc'],
            ['package_quantity' => 0], ['package_quantity' => -1], ['usage_quantity' => -1], ['occurrences_per_occupied_night' => -1],
            ['as_of' => '2026-02-30'], ['as_of' => '2026-2-01'], ['as_of' => 'not a date']] as $changes) {
            $cases[] = $this->input([$this->item($changes)]);
        }
        $withoutEnabled = $this->item();
        unset($withoutEnabled['enabled']);
        $cases[] = $this->input([$withoutEnabled]);
        $cases[] = $this->input(array_map(fn(int $id): array => $this->item(['id' => (string)$id]), range(1, 101)));
        foreach ($cases as $case) {
            try {
                $calculator->normalize($case);
                self::fail('Expected invalid consumables input rejection');
            } catch (InvalidArgumentException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    public function testExactlyOneHundredItemsAndRealLeapDateAreAccepted(): void
    {
        $calculator = new ConsumablesCostCalculator();
        $input = $this->input(array_map(fn(int $id): array => $this->item(['id' => (string)$id, 'as_of' => '2024-02-29']), range(1, 100)));
        $result = $calculator->evaluate($calculator->normalize($input));
        self::assertCount(100, $result['items']);
        self::assertSame('ready', $result['status']);
        self::assertSame(200.0, $result['effective_operating_cost_per_night']);
    }

    public function testDerivedOverflowCannotBecomeReadyOrInfiniteCost(): void
    {
        $calculator = new ConsumablesCostCalculator();
        foreach ([['package_price' => 1.0e308, 'package_quantity' => 1.0e-308],
            ['package_price' => 1.0e308, 'package_quantity' => 1, 'usage_quantity' => 10]] as $changes) {
            try {
                $calculator->evaluate($calculator->normalize($this->input([$this->item($changes)])));
                self::fail('Expected nonfinite derived arithmetic rejection');
            } catch (InvalidArgumentException $error) {
                self::assertStringContainsString('finite', $error->getMessage());
            }
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUnavailableProcurementReferenceRejectsInsteadOfBecomingAManualCost(): void
    {
        Fixture::withoutOptionalModules();
        self::assertFalse(class_exists(\app\service\ConsumablesProcurementReferenceService::class));
        $reference = ['catalog_id' => 'synthetic-unavailable-catalog', 'source_sha256' => str_repeat('a', 64),
            'item_id' => 14, 'tier_id' => 'c', 'confirmed_for_scenario' => false];
        $calculator = new ConsumablesCostCalculator();
        foreach ([true, false] as $enabled) {
            foreach ([true, false] as $confirmed) {
                $row = $this->item(['enabled' => $enabled,
                    'procurement_reference' => array_replace($reference, ['confirmed_for_scenario' => $confirmed])]);
                $input = $this->input([$row]);
                $before = $input;
                try {
                    $calculator->normalize($input);
                    self::fail('An unavailable reference cannot be normalized as a usable or disabled manual row');
                } catch (InvalidArgumentException $error) {
                    self::assertStringContainsString('采购参考目录尚未接入', $error->getMessage());
                }
                self::assertSame($before, $input);
            }
        }
        $manual = $calculator->normalize($this->input([$this->item(['procurement_reference' => null])]));
        self::assertSame(20.0, $manual['items'][0]['package_price']);
        self::assertArrayNotHasKey('procurement_reference', $manual['items'][0]);
        self::assertSame(2.0, $calculator->evaluate($manual)['consumables_per_night']);
    }

    private function input(array $items, mixed $other = 0): array
    {
        return ['schema_version' => 'consumables-v1', 'mode' => 'manual', 'other_variable_cost_per_night' => $other, 'items' => $items];
    }

    private function item(array $changes = []): array
    {
        return array_replace([
            'id' => 'item', 'name' => '合成物料', 'enabled' => true, 'package_price' => 20, 'package_quantity' => 10,
            'unit' => 'piece', 'usage_quantity' => 1, 'usage_basis' => 'occupied_room_night', 'occurrences_per_occupied_night' => null,
            'source_label' => null, 'as_of' => null,
        ], $changes);
    }
}
