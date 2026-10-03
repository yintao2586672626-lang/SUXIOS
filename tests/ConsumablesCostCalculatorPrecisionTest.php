<?php
declare(strict_types=1);

use app\service\ConsumablesCostCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConsumablesCostCalculatorPrecisionTest extends TestCase
{
    private function input(array $changes = []): array
    {
        return ['schema_version'=>'consumables-v1','mode'=>'derived','other_variable_cost_per_night'=>0,'items'=>[
            array_replace(['id'=>'synthetic','name'=>'合成成本假设','enabled'=>true,'unit'=>'ml','package_price'=>100,
                'package_quantity'=>500,'usage_quantity'=>20,'usage_basis'=>'cleaning','occurrences_per_occupied_night'=>0.5,
                'source_label'=>'synthetic-assumption','as_of'=>'2026-10-02'],$changes)]];
    }

    public function testOrdinaryUnitConversionKeepsItsExistingMeaning(): void
    {
        $result=(new ConsumablesCostCalculator())->evaluate($this->input());
        self::assertSame(0.2,$result['items'][0]['unit_cost']);
        self::assertSame(2.0,$result['effective_operating_cost_per_night']);
        self::assertSame('scenario_assumption',$result['source_quality']);
    }

    public static function nonzeroUnderflows(): array
    {
        return ['unit price before compensating multiplications'=>[['package_price'=>1e-200,'package_quantity'=>1e200,'usage_quantity'=>1e200,'occurrences_per_occupied_night'=>1e200]],
            'positive product below float range'=>[['package_price'=>1e-200,'package_quantity'=>1,'usage_quantity'=>1e-200,'occurrences_per_occupied_night'=>1e200]],
            'raw price is positive but not representable'=>[['package_price'=>'1e-400']],
            'raw usage is positive but not representable'=>[['usage_quantity'=>'1e-400']],
            'raw occurrence is positive but not representable'=>[['occurrences_per_occupied_night'=>'1e-400']]];
    }

    #[DataProvider('nonzeroUnderflows')]
    public function testUnrepresentablePositiveOperandsCannotBecomeReadyZero(array $changes): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ConsumablesCostCalculator())->evaluate($this->input($changes));
    }

    public function testExplicitZeroIsHandledBeforeAnOverflowingProduct(): void
    {
        $result=(new ConsumablesCostCalculator())->evaluate($this->input(['package_price'=>1e308,'package_quantity'=>1,'usage_quantity'=>1e308,'occurrences_per_occupied_night'=>0]));
        self::assertSame(0.0,$result['effective_operating_cost_per_night']);
        self::assertSame('ready',$result['status']);
    }

    public function testScientificZeroAndUnknownPriceRemainDifferent(): void
    {
        $service=new ConsumablesCostCalculator();
        $zero=$service->evaluate($this->input(['package_price'=>'0e-400']));
        self::assertSame('ready',$zero['status']);self::assertSame(0.0,$zero['effective_operating_cost_per_night']);
        $unknown=$service->evaluate($this->input(['package_price'=>null]));
        self::assertSame('partial',$unknown['status']);self::assertNull($unknown['effective_operating_cost_per_night']);
        self::assertNull($unknown['known_subtotal_per_night']);
    }
}
