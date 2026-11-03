<?php
declare(strict_types=1);
namespace Tests;

use app\service\QuantSimulationService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class QuantSimulationCalendarAndBreakEvenTest extends TestCase
{
    public function testMonthlyDatesStayInTheirCalendarMonthsWithMonthEndClamping(): void
    {
        $service = new QuantSimulationService();
        $method = new ReflectionMethod($service,'buildCashflowSeriesResult');
        foreach ([
            '2026-01-31'=>['2026-01-31','2026-02-28','2026-03-31','2026-04-30'],
            '2024-01-31'=>['2024-01-31','2024-02-29','2024-03-31','2024-04-30'],
            '2026-01-30'=>['2026-01-30','2026-02-28','2026-03-30','2026-04-30'],
            '2026-01-15'=>['2026-01-15','2026-02-15','2026-03-15','2026-04-15'],
        ] as $date=>$expected) {
            $result = $method->invoke($service,['valuation_date'=>$date,'currency'=>'CNY',
                'construction_cashflows'=>[-100000],'operation_cashflows'=>[10000,10000,10000],'terminal_value'=>5000]);
            self::assertSame($expected,array_column($result['cashflow_series'],'date'));
            self::assertSame([-100000.0,10000.0,10000.0,15000.0],array_column($result['cashflow_series'],'value'));
        }
    }

    public function testZeroRoomContributionCannotClaimFullOccupancyBreakEven(): void
    {
        $service = new QuantSimulationService();
        $normalize = new ReflectionMethod($service,'normalizeInput');
        $input = $normalize->invoke($service,[
            'roomCount'=>10,'decorationInvestment'=>100000,'furnitureInvestment'=>0,'openingCost'=>0,
            'otherInvestment'=>0,'adr'=>100,'occupancyRate'=>100,'otherIncome'=>0,'monthlyRent'=>10000,
            'laborCost'=>0,'utilityCost'=>0,'otaCommissionRate'=>100,'consumableCost'=>0,
            'maintenanceCost'=>0,'otherFixedCost'=>0,
        ]);
        $result = (new ReflectionMethod($service,'calculateSimulation'))->invoke($service,$input);
        self::assertSame(-10000.0,$result['monthlyNetCashflow']);
        self::assertNull($result['breakEvenOccupancy']);
        self::assertSame('unreachable',$result['breakEvenOccupancyStatus']);
        self::assertSame('高风险',$result['riskLevel']);
        $hints = (new ReflectionMethod($service,'buildRiskHints'))->invoke($service,$result);
        self::assertStringContainsString('无法保本',implode(' ',array_column($hints,'content')));
    }

    public function testOtherIncomeCanCoverFixedCostWithoutAnyRoomContribution(): void
    {
        $service = new QuantSimulationService();
        $method = new ReflectionMethod($service,'calculateBreakEvenOccupancy');
        self::assertSame(0.0,$method->invoke($service,10000.0,10000.0,300.0,100.0,1.0));
        self::assertSame(0.5,$method->invoke($service,15000.0,0.0,300.0,100.0,0.0));
    }

    public function testStoredResultAndScenarioPreserveUnreachableMetricTruth(): void
    {
        $service = new QuantSimulationService();
        foreach ([null, 1.5] as $occupancy) {
            $result = ['breakEvenOccupancy' => $occupancy, 'breakEvenOccupancyStatus' => 'unreachable'];
            $record = (new ReflectionMethod($service, 'formatRecord'))->invoke($service, [
                'id' => 17, 'project_name' => 'Fixture', 'input_json' => '{}',
                'result_json' => json_encode($result, JSON_THROW_ON_ERROR),
                'scenarios_json' => json_encode([$result], JSON_THROW_ON_ERROR),
                'risk_hints_json' => '[]', 'payback_months' => null,
                'risk_level' => '高风险', 'created_by' => 9, 'created_at' => '2026-09-07 09:00:00',
            ], true);
            self::assertSame($occupancy, $record['result']['breakEvenOccupancy']);
            self::assertSame($occupancy, $record['scenarios'][0]['breakEvenOccupancy']);
            foreach ([$record['metric_truth'], $record['scenarios'][0]['metric_truth']] as $truth) {
                self::assertSame('unreachable', $truth['breakEvenOccupancy']['calculation_status']);
                self::assertSame($occupancy !== null, $truth['breakEvenOccupancy']['value_observed']);
                self::assertSame('unverified', $truth['breakEvenOccupancy']['status']);
                self::assertSame('missing', $truth['monthlyRevenue']['calculation_status']);
            }
        }
    }
}
