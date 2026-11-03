<?php
declare(strict_types=1);

namespace Tests;

use app\service\ExpansionService;
use app\service\LlmClient;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ExpansionServiceTest extends TestCase
{

    public function testProjectReadinessKeepsMarketRecordAsScreeningOnly(): void
    {
        $service = $this->fallbackService();
        $marketInput = $this->marketInput();
        $market = RetiredHistoryFixture::result('expansion', 'market');

        $readiness = $service->buildProjectReadiness('market', $marketInput, $market);
        $missingCodes = array_column($readiness['missing_evidence'], 'code');

        self::assertSame('screening_record_only', $readiness['stage']);
        self::assertFalse($readiness['project_ready']);
        self::assertContains('benchmark_model', $missingCodes);
        self::assertContains('collaboration_plan', $missingCodes);
        self::assertContains('source_evidence', $missingCodes);
    }

    public function testProjectReadinessRequiresEvidenceReviewAndTracking(): void
    {
        $service = $this->fallbackService();
        $marketInput = $this->marketInput();
        $benchmarkInput = $this->benchmarkInput();
        $market = RetiredHistoryFixture::result('expansion', 'market');
        $benchmark = RetiredHistoryFixture::result('expansion', 'benchmark');
        $collaborationInput = array_merge($this->collaborationInput(), [
            'market_input' => $marketInput,
            'market_result' => $market,
            'benchmark_input' => $benchmarkInput,
            'benchmark_result' => $benchmark,
        ]);
        $collaboration = RetiredHistoryFixture::result('expansion', 'collaboration');

        $readiness = $service->buildProjectReadiness('collaboration', $collaborationInput, $collaboration);
        $missingCodes = array_column($readiness['missing_evidence'], 'code');

        self::assertSame('diligence_required', $readiness['stage']);
        self::assertFalse($readiness['project_ready']);
        self::assertContains('source_evidence', $missingCodes);
        self::assertContains('manual_review', $missingCodes);

        $approved = $service->buildProjectReadiness('collaboration', array_merge($collaborationInput, [
            'source_evidence' => ['competitor_samples' => 'checked'],
            'review_status' => 'approved',
            'opening_project_id' => 88,
        ]), $collaboration);

        self::assertSame('project_ready', $approved['stage']);
        self::assertTrue($approved['project_ready']);
    }

    public function testBuildExecutionIntentInputRequiresExplicitHotel(): void
    {
        $service = $this->fallbackService();

        $this->expectException(InvalidArgumentException::class);
        $service->buildExecutionIntentInput(['id' => 9], 0);
    }

    public function testBuildExecutionIntentInputRejectsScreeningOnlyRecord(): void
    {
        $service = $this->fallbackService();
        $marketInput = $this->marketInput();
        $market = RetiredHistoryFixture::result('expansion', 'market');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('screening_record_only');
        $service->buildExecutionIntentInput([
            'id' => 9,
            'record_type' => 'market',
            'input' => $marketInput,
            'result' => $market,
        ], 7);
    }

    public function testBuildExecutionIntentInputUsesExpansionDecisionScope(): void
    {
        $service = $this->fallbackService();
        $marketInput = $this->marketInput();
        $benchmarkInput = $this->benchmarkInput();
        $market = RetiredHistoryFixture::result('expansion', 'market');
        $benchmark = RetiredHistoryFixture::result('expansion', 'benchmark');
        $collaborationInput = array_merge($this->collaborationInput(), [
            'market_input' => $marketInput,
            'market_result' => $market,
            'benchmark_input' => $benchmarkInput,
            'benchmark_result' => $benchmark,
            'source_evidence' => ['competitor_samples' => 'checked'],
            'review_status' => 'approved',
        ]);
        $collaboration = RetiredHistoryFixture::result('expansion', 'collaboration');
        $readiness = $service->buildProjectReadiness('collaboration', $collaborationInput, $collaboration);

        $intentInput = $service->buildExecutionIntentInput([
            'id' => 9,
            'record_type' => 'collaboration',
            'project_name' => '上海虹桥新店',
            'city_area' => '上海虹桥',
            'decision' => '可推进',
            'risk_level' => '中风险',
            'input' => $collaborationInput,
            'result' => $collaboration,
            'project_readiness' => $readiness,
        ], 7, ['date_start' => '2026-06-14']);

        self::assertSame('expansion', $intentInput['source_module']);
        self::assertSame(9, $intentInput['source_record_id']);
        self::assertSame(7, $intentInput['hotel_id']);
        self::assertSame('investment', $intentInput['platform']);
        self::assertSame('expansion', $intentInput['object_type']);
        self::assertSame('expansion_project_closure', $intentInput['target_value']['target_metric']);
        self::assertSame('pending_expansion_post_decision_tracking', $intentInput['target_value']['tracking_status']);
        self::assertSame($readiness['stage'], $intentInput['evidence']['readiness_stage']);
        self::assertSame('expansion_screening_and_project_decision', $intentInput['evidence']['source_scope']);
        self::assertSame('medium', $intentInput['risk_level']);
    }

    private function marketInput(): array
    {
        return [
            'city' => '上海',
            'business_area' => '虹桥',
            'property_area' => 3200,
            'estimated_rent' => 120000,
            'target_room_count' => 80,
            'city_tier' => '一线',
            'decoration_level' => '中端精选',
            'primary_customer' => '商务差旅',
            'secondary_customer' => '会议会展',
            'lease_years' => 10,
            'rent_free_months' => 4,
            'fitout_budget' => 420,
            'expected_adr' => 320,
            'expected_occupancy_rate' => 82,
            'ota_market_penetration_rate' => 65,
            'parking_spaces' => 30,
        ];
    }

    private function benchmarkInput(): array
    {
        return [
            'city' => '上海',
            'business_area' => '虹桥',
            'target_price_band' => '260-360',
            'hotel_type' => '中端商务',
            'target_room_count' => 80,
            'competitor_count' => 12,
            'avg_competitor_price' => 315,
            'avg_competitor_score' => 4.7,
            'avg_review_count' => 520,
            'ota_heat_index' => 82,
            'traffic_radius_km' => 3,
        ];
    }

    private function collaborationInput(): array
    {
        $dueDate = date('Y-m-d', strtotime('+30 days'));
        $tasks = array_map(static fn(string $name): array => [
            'name' => $name,
            'status' => '已完成',
            'owner' => '拓展负责人',
            'due_date' => $dueDate,
        ], ['市场调研', '物业评估', '合同谈判', '装修筹建', '证照办理', 'OTA上线', '运营交接']);

        return [
            'project_name' => '上海虹桥新店',
            'city_area' => '上海虹桥',
            'current_stage' => '上线',
            'owner' => '拓展负责人',
            'expected_online_date' => date('Y-m-d', strtotime('+60 days')),
            'tasks' => $tasks,
        ];
    }

    private function fallbackService(): ExpansionService
    {
        return new ExpansionService(new class extends LlmClient {
            public function createJsonResponse(array $messages, array $schema, string $modelKey = 'deepseek_v4_default'): array
            {
                throw new RuntimeException('missing model config');
            }
        });
    }
}
