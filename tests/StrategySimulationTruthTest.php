<?php
declare(strict_types=1);

namespace Tests;

use app\controller\StrategySimulation;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ReflectionHelper;

final class StrategySimulationTruthTest extends TestCase
{
    use ReflectionHelper;

    public function testMissingOptionalEvidenceStaysMissingAndBlocksDecision(): void
    {
        $controller = (new ReflectionClass(StrategySimulation::class))->newInstanceWithoutConstructor();
        $input = $this->invokeNonPublic($controller, 'normalizeInput', [[
            'project_name' => '测试项目',
            'city' => '杭州',
            'property_area' => 2400,
            'room_count' => 80,
            'monthly_rent' => 100000,
            'decoration_budget' => 3200000,
        ]]);

        self::assertNull($input['competitor_count']);
        self::assertNull($input['lease_years']);
        self::assertNull($input['rent_free_months']);
        self::assertSame('', $input['business_type']);
        self::assertSame('', $input['target_customer']);
        self::assertSame('', $input['target_hotel_level']);

        $scores = $this->invokeNonPublic($controller, 'calculateScores', [
            $input,
            [
                'daily_reports' => ['count' => 0],
                'online_daily_data' => ['count' => 0, 'total_quantity' => 0, 'avg_score' => null, 'competitor_hotels' => 0],
                'competitor_analysis' => ['count' => 0, 'competitor_hotels' => 0],
                'data_sources' => [],
                'missing_data' => [],
            ],
            ['used' => false, 'available' => false, 'poi_counts' => []],
        ]);

        self::assertSame('rule_simulation_index', $scores['score_type']);
        self::assertFalse($scores['decision_ready']);
        self::assertContains('竞品数量', $scores['data_gaps']);
        self::assertContains('目标客群', $scores['data_gaps']);
        self::assertContains('目标酒店档次', $scores['data_gaps']);

        $recommendation = $this->invokeNonPublic($controller, 'buildRecommendation', [$input, $scores]);
        $risk = $this->invokeNonPublic($controller, 'buildRisk', [$scores, $recommendation]);
        self::assertStringContainsString('不形成选址或投资结论', $recommendation['decision']);
        self::assertSame('待评估', $recommendation['competition_pressure']);
        self::assertSame('待评估', $risk['risk_level']);
    }

    public function testStrategyUiDoesNotAutoInventCompetitorCount(): void
    {
        $root = dirname(__DIR__);
        $appMain = (string)file_get_contents($root . '/public/app-main.js');
        $template = (string)file_get_contents($root . '/resources/frontend/templates/fragments/01-page-ai-strategy.html');
        $static = (string)file_get_contents($root . '/public/expansion-static-options.js');

        self::assertStringContainsString('未核验时请留空', $template);
        self::assertStringNotContainsString('readonly="" class="w-full', $template);
        self::assertStringContainsString('Never replace a', $appMain);
        self::assertStringContainsString('competitor_count: optionalNumber(project.competitor_count)', $static);
    }
}
