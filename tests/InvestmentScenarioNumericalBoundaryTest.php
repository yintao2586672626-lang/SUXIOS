<?php
declare(strict_types=1);

use app\service\InvestmentScenarioCalculator;
use app\service\InvestmentScenarioCashPlanner;
use PHPUnit\Framework\TestCase;

final class InvestmentScenarioNumericalBoundaryTest extends TestCase
{
    public function testNonzeroUnderflowCannotBecomeZeroOccupancyOrManagementRate(): void
    {
        foreach (['occupancy_first_year', 'management_fee_rate'] as $field) {
            foreach (['1e-999', '-1e-999'] as $value) {
                try {
                    (new InvestmentScenarioCalculator())->normalize([$field => $value]);
                    self::fail($field . ' silently accepted a nonzero value as zero');
                } catch (InvalidArgumentException $exception) {
                    self::assertStringContainsString($field, $exception->getMessage());
                }
            }
        }
    }

    public function testNonzeroUnderflowCannotBecomeAnInterestFreeLoan(): void
    {
        foreach (['1e-999', '-1e-999'] as $value) {
            try {
                (new InvestmentScenarioCashPlanner())->normalize(['loans' => [
                    ['id' => 'test-only-loan', 'method' => 'annuity', 'annual_rate' => $value],
                ]]);
                self::fail('Nonzero interest silently became an interest-free loan');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('利率', $exception->getMessage());
            }
        }
    }

    public function testExactZeroAndRepresentableSmallValuesRemainValid(): void
    {
        foreach (['0', '0e-999', '-0e-999', '0.000', '1e-6'] as $value) {
            $scenario = (new InvestmentScenarioCalculator())->normalize(['occupancy_first_year' => $value, 'management_fee_rate' => $value]);
            self::assertSame((float)$value, $scenario['occupancy_first_year']);
            self::assertSame((float)$value, $scenario['management_fee_rate']);
            $plan = (new InvestmentScenarioCashPlanner())->normalize(['loans' => [
                ['id' => 'test-only-loan', 'method' => 'annuity', 'annual_rate' => $value],
            ]]);
            self::assertSame((float)$value, $plan['loans'][0]['annual_rate']);
        }
    }

    public function testMalformedEvidenceDateIsAnInputError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('as_of');
        (new InvestmentScenarioCalculator())->normalize(['as_of' => '2026-10-' . chr(0) . '3']);
    }
}
