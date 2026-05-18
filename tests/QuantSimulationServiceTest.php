<?php
declare(strict_types=1);

namespace Tests;

use app\service\LlmClient;
use app\service\QuantSimulationService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\ReflectionHelper;

final class QuantSimulationServiceTest extends TestCase
{
    use ReflectionHelper;

    public function testNormalizeInputAcceptsSnakeCaseAndCalculateSimulationCoreMetrics(): void
    {
        $service = new QuantSimulationService();
        $input = $this->invokeNonPublic($service, 'normalizeInput', [[
            'room_count' => 10,
            'decoration_investment' => 0,
            'equipment_investment' => 0,
            'pre_opening_cost' => 0,
            'other_investment' => 0,
            'adr' => 100,
            'occupancy_rate' => 50,
            'other_income' => 0,
            'monthly_rent' => 10000,
            'labor_cost' => 0,
            'utility_cost' => 0,
            'ota_commission_rate' => 10,
            'consumable_cost' => 0,
            'maintenance_cost' => 0,
            'other_fixed_cost' => 0,
        ]]);

        self::assertSame(10.0, $input['roomCount']);
        self::assertSame(50.0, $input['occupancyRate']);

        $result = $this->invokeNonPublic($service, 'calculateSimulation', [$input]);

        self::assertSame(300.0, $result['availableRoomNights']);
        self::assertSame(15000.0, $result['roomRevenue']);
        self::assertSame(1500.0, $result['otaCommission']);
        self::assertSame(3500.0, $result['monthlyNetCashflow']);
        self::assertEqualsWithDelta(0.3704, $result['breakEvenOccupancy'], 0.0001);
    }

    public function testDetailedInvestmentFieldsOverrideLegacyTotals(): void
    {
        $service = new QuantSimulationService();
        $input = $this->invokeNonPublic($service, 'normalizeInput', [[
            'roomCount' => 10,
            'decorationInvestment' => 999999,
            'decorationHardCost' => 100000,
            'decorationSoftCost' => 20000,
            'fireSafetyCost' => 10000,
            'signageDesignCost' => 5000,
            'furnitureInvestment' => 999999,
            'roomFurnitureCost' => 30000,
            'applianceEquipmentCost' => 20000,
            'linenSuppliesCost' => 5000,
            'techSystemCost' => 3000,
            'openingCost' => 999999,
            'licensePermitCost' => 6000,
            'openingMarketingCost' => 7000,
            'recruitmentTrainingCost' => 8000,
            'openingMaterialCost' => 9000,
            'otherInvestment' => 999999,
            'contingencyCost' => 10000,
            'rentDepositCost' => 15000,
            'otherProjectCost' => 5000,
            'adr' => 100,
            'occupancyRate' => 80,
            'otherIncome' => 0,
            'monthlyRent' => 0,
            'laborCost' => 0,
            'utilityCost' => 0,
            'otaCommissionRate' => 0,
            'consumableCost' => 0,
            'maintenanceCost' => 0,
            'otherFixedCost' => 0,
        ]]);

        self::assertSame(135000.0, $input['decorationInvestment']);
        self::assertSame(58000.0, $input['furnitureInvestment']);
        self::assertSame(30000.0, $input['openingCost']);
        self::assertSame(30000.0, $input['otherInvestment']);

        $result = $this->invokeNonPublic($service, 'calculateSimulation', [$input]);

        self::assertSame(253000.0, $result['totalInvestment']);
    }

    public function testLegacyInvestmentTotalsBackfillDetailFields(): void
    {
        $input = $this->invokeNonPublic(new QuantSimulationService(), 'normalizeInput', [[
            'roomCount' => 10,
            'decorationInvestment' => 120000,
            'furnitureInvestment' => 50000,
            'openingCost' => 20000,
            'otherInvestment' => 10000,
            'adr' => 100,
            'occupancyRate' => 50,
            'otherIncome' => 0,
            'monthlyRent' => 0,
            'laborCost' => 0,
            'utilityCost' => 0,
            'otaCommissionRate' => 0,
            'consumableCost' => 0,
            'maintenanceCost' => 0,
            'otherFixedCost' => 0,
        ]]);

        self::assertSame(120000.0, $input['decorationHardCost']);
        self::assertSame(0.0, $input['decorationSoftCost']);
        self::assertSame(50000.0, $input['roomFurnitureCost']);
        self::assertSame(20000.0, $input['licensePermitCost']);
        self::assertSame(10000.0, $input['contingencyCost']);
    }

    public function testDetailedRevenueFieldsOverrideLegacySummary(): void
    {
        $service = new QuantSimulationService();
        $input = $this->invokeNonPublic($service, 'normalizeInput', [[
            'roomCount' => 10,
            'decorationInvestment' => 0,
            'furnitureInvestment' => 0,
            'openingCost' => 0,
            'otherInvestment' => 0,
            'adr' => 999,
            'occupancyRate' => 1,
            'weekdayDays' => 20,
            'weekdayAdr' => 100,
            'weekdayOccupancyRate' => 50,
            'weekendDays' => 8,
            'weekendAdr' => 200,
            'weekendOccupancyRate' => 100,
            'holidayDays' => 2,
            'holidayAdr' => 300,
            'holidayOccupancyRate' => 100,
            'otherIncome' => 999,
            'breakfastIncome' => 1000,
            'meetingIncome' => 2000,
            'retailIncome' => 3000,
            'parkingLaundryIncome' => 4000,
            'otherMiscIncome' => 5000,
            'monthlyRent' => 0,
            'laborCost' => 0,
            'utilityCost' => 0,
            'otaCommissionRate' => 0,
            'consumableCost' => 0,
            'maintenanceCost' => 0,
            'otherFixedCost' => 0,
        ]]);

        self::assertSame(160.0, $input['adr']);
        self::assertEqualsWithDelta(66.67, $input['occupancyRate'], 0.01);
        self::assertSame(15000.0, $input['otherIncome']);

        $result = $this->invokeNonPublic($service, 'calculateSimulation', [$input]);

        self::assertSame(300.0, $result['availableRoomNights']);
        self::assertSame(32000.0, $result['roomRevenue']);
        self::assertSame(47000.0, $result['monthlyRevenue']);
        self::assertSame(47000.0, $result['monthlyNetCashflow']);
    }

    public function testLegacyRevenueFieldsBackfillDetailFields(): void
    {
        $input = $this->invokeNonPublic(new QuantSimulationService(), 'normalizeInput', [[
            'roomCount' => 10,
            'decorationInvestment' => 0,
            'furnitureInvestment' => 0,
            'openingCost' => 0,
            'otherInvestment' => 0,
            'adr' => 100,
            'occupancyRate' => 50,
            'otherIncome' => 123,
            'monthlyRent' => 0,
            'laborCost' => 0,
            'utilityCost' => 0,
            'otaCommissionRate' => 0,
            'consumableCost' => 0,
            'maintenanceCost' => 0,
            'otherFixedCost' => 0,
        ]]);

        self::assertSame(30.0, $input['weekdayDays']);
        self::assertSame(100.0, $input['weekdayAdr']);
        self::assertSame(50.0, $input['weekdayOccupancyRate']);
        self::assertSame(0.0, $input['weekendDays']);
        self::assertSame(123.0, $input['otherMiscIncome']);
        self::assertSame(123.0, $input['otherIncome']);
    }

    #[DataProvider('invalidInputProvider')]
    public function testNormalizeInputRejectsInvalidValues(array $override): void
    {
        $this->expectException(InvalidArgumentException::class);

        $base = [
            'roomCount' => 10,
            'decorationInvestment' => 0,
            'furnitureInvestment' => 0,
            'openingCost' => 0,
            'otherInvestment' => 0,
            'adr' => 100,
            'occupancyRate' => 50,
            'otherIncome' => 0,
            'monthlyRent' => 10000,
            'laborCost' => 0,
            'utilityCost' => 0,
            'otaCommissionRate' => 10,
            'consumableCost' => 0,
            'maintenanceCost' => 0,
            'otherFixedCost' => 0,
        ];

        $this->invokeNonPublic(new QuantSimulationService(), 'normalizeInput', [array_merge($base, $override)]);
    }

    #[DataProvider('riskLevelProvider')]
    public function testCalculateRiskLevelBranches(float $cashflow, ?float $payback, float $rentRatio, float $breakEven, string $expected): void
    {
        $result = $this->invokeNonPublic(new QuantSimulationService(), 'calculateRiskLevel', [
            $cashflow,
            $payback,
            $rentRatio,
            $breakEven,
        ]);

        self::assertSame($expected, $result);
    }

    public function testBuildModelAnalysisUsesConfiguredLlmClient(): void
    {
        $client = new class extends LlmClient {
            public array $messages = [];

            public function createJsonResponse(array $messages, array $schema, string $modelKey = 'deepseek_v4_default'): array
            {
                $this->messages = $messages;
                return [
                    'summary' => '基准现金流健康，但需复核淡季入住。',
                    'decision' => '可进入下一轮经营数据复核。',
                    'recommendations' => [
                        ['priority' => 'P0', 'title' => '复核ADR', 'detail' => '用近30天OTA成交价校验ADR。'],
                    ],
                    'watch_points' => [
                        ['metric' => '保本入住率', 'threshold' => '55%', 'action' => '高于阈值时重谈租金。'],
                    ],
                    'assumptions' => ['未接入真实经营复核数据。'],
                ];
            }
        };

        $analysis = $this->invokeNonPublic(new QuantSimulationService($client), 'buildModelAnalysis', $this->modelAnalysisArguments('deepseek_chat'));

        self::assertSame('llm', $analysis['source']);
        self::assertSame('deepseek_chat', $analysis['model_key']);
        self::assertSame('基准现金流健康，但需复核淡季入住。', $analysis['summary']);
        self::assertSame('P0', $analysis['recommendations'][0]['priority']);
        self::assertStringContainsString('deterministic_result', (string)($client->messages[1]['content'] ?? ''));
    }

    public function testBuildModelAnalysisFallsBackWhenLlmFails(): void
    {
        $client = new class extends LlmClient {
            public function createJsonResponse(array $messages, array $schema, string $modelKey = 'deepseek_v4_default'): array
            {
                throw new RuntimeException('missing model config');
            }
        };

        $analysis = $this->invokeNonPublic(new QuantSimulationService($client), 'buildModelAnalysis', $this->modelAnalysisArguments('deepseek_chat'));

        self::assertSame('fallback', $analysis['source']);
        self::assertSame('deepseek_chat', $analysis['model_key']);
        self::assertStringContainsString('本地量化结果显示', $analysis['summary']);
        self::assertStringContainsString('missing model config', $analysis['error']);
        self::assertNotEmpty($analysis['recommendations']);
    }

    public static function invalidInputProvider(): array
    {
        return [
            'room count' => [['roomCount' => 0]],
            'adr' => [['adr' => 0]],
            'occupancy high' => [['occupancyRate' => 101]],
            'ota negative' => [['otaCommissionRate' => -1]],
            'negative cost' => [['laborCost' => -1]],
        ];
    }

    public static function riskLevelProvider(): array
    {
        return [
            'negative cashflow' => [-1, null, 0.2, 0.4, '高风险'],
            'high rent ratio' => [10, 20, 0.42, 0.4, '高风险'],
            'medium high rent ratio' => [10, 20, 0.32, 0.4, '中高风险'],
            'medium payback' => [10, 24, 0.2, 0.4, '中风险'],
            'low risk' => [10, 12, 0.2, 0.4, '低风险'],
        ];
    }

    private function modelAnalysisArguments(string $modelKey): array
    {
        $input = [
            'roomCount' => 10,
            'decorationInvestment' => 100000,
            'furnitureInvestment' => 50000,
            'openingCost' => 20000,
            'otherInvestment' => 0,
            'adr' => 100,
            'occupancyRate' => 70,
            'otherIncome' => 0,
            'monthlyRent' => 10000,
            'laborCost' => 1000,
            'utilityCost' => 1000,
            'otaCommissionRate' => 10,
            'consumableCost' => 1000,
            'maintenanceCost' => 500,
            'otherFixedCost' => 500,
        ];
        $result = [
            'monthlyNetCashflow' => 5000,
            'paybackMonths' => 34,
            'rentRatio' => 0.24,
            'breakEvenOccupancy' => 0.48,
            'riskLevel' => '中风险',
        ];
        $scenarios = [
            ['scenarioType' => '保守情景', 'monthlyNetCashflow' => -1000],
            ['scenarioType' => '基准情景', 'monthlyNetCashflow' => 5000],
            ['scenarioType' => '乐观情景', 'monthlyNetCashflow' => 9000],
        ];
        $riskHints = [
            ['title' => '回本周期', 'content' => '回本周期偏长，需关注现金流稳定性。'],
        ];

        return [$input, $result, $scenarios, $riskHints, $modelKey];
    }
}
