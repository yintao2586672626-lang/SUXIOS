<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenueAiOverviewService;
use app\service\operation\ExecutionFlowReadService;
use app\service\operation\ExecutionOutcomeService;
use PHPUnit\Framework\TestCase;

final class RevenueAiEffectReviewSuggestionIdentityTest extends TestCase
{
    private const DATE = '2026-09-28';

    public function testReviewedInputsRetainDifferentSuggestionsForSameHotelAndDate(): void
    {
        $first = $this->flowItem(101, 501);
        $second = $this->flowItem(102, 502);
        self::assertSame('ready', $first['roi']['status']);
        self::assertSame('reviewed', $second['stage']);
        self::assertSame(501, $first['recommendation']['source_record_id']);
        $summary = $this->summary([$first, $second]);
        self::assertSame(2, $summary['effect_review']['input_ready_count']);
        self::assertTrue($summary['effect_review']['next_day_input_ready']);
        $inputs = array_column($summary['effect_review']['inputs'], null, 'intent_id');
        self::assertSame('price_suggestion', $inputs[101]['source_module'] ?? null);
        self::assertSame(501, $inputs[101]['source_record_id'] ?? null);
        self::assertSame(502, $inputs[102]['source_record_id'] ?? null);
        self::assertSame(201, $inputs[101]['task_id']);
        self::assertSame(7, $inputs[101]['hotel_id']);
        self::assertSame(self::DATE, $inputs[101]['date_start']);
        self::assertSame('ctrip', $inputs[101]['platform']);
    }

    public function testRecentItemRetainsSuggestionInsteadOfSubstitutingIntentOrTaskId(): void
    {
        $summary = $this->summary([$this->flowItem(101, 501)]);
        $row = $summary['recent_items'][0];
        self::assertSame(101, $row['intent_id']);
        self::assertSame(201, $row['task_id']);
        self::assertSame('price_suggestion', $row['source_module'] ?? null);
        self::assertSame(501, $row['source_record_id'] ?? null);
    }

    public function testPendingSuggestionKeepsIdentityWithoutClaimingExecutionOrNextDayReadiness(): void
    {
        $item = $this->flowItem(103, 503, pending: true);
        $summary = $this->summary([$item]);
        self::assertSame(0, $summary['executed_count']);
        self::assertFalse($summary['effect_review']['next_day_input_ready']);
        self::assertSame(0, $summary['effect_review']['input_ready_count']);
        self::assertSame(503, $summary['effect_review']['inputs'][0]['source_record_id'] ?? null);
        self::assertSame('price_suggestion', $summary['recent_items'][0]['source_module'] ?? null);
    }

    public function testSharedRecordNumberRetainsItsActualSourceModule(): void
    {
        $price = $this->flowItem(104, 504);
        $forecast = $this->flowItem(105, 504, module: 'demand_forecast');
        $summary = $this->summary([$price, $forecast]);
        $inputs = array_column($summary['effect_review']['inputs'], null, 'intent_id');
        self::assertSame('price_suggestion', $inputs[104]['source_module'] ?? null);
        self::assertSame('demand_forecast', $inputs[105]['source_module'] ?? null);
        self::assertSame(504, $inputs[104]['source_record_id'] ?? null);
        self::assertSame(504, $inputs[105]['source_record_id'] ?? null);
    }

    public function testBusinessDateFilterKeepsOnlySelectedDateSuggestionIdentity(): void
    {
        $old = $this->flowItem(106, 506, date: '2026-09-27');
        $current = $this->flowItem(107, 507);
        $summary = $this->summary([$old, $current]);
        self::assertSame(1, $summary['total_count']);
        self::assertSame(107, $summary['effect_review']['inputs'][0]['intent_id']);
        self::assertSame(507, $summary['effect_review']['inputs'][0]['source_record_id'] ?? null);
        self::assertSame(self::DATE, $summary['recent_items'][0]['date_start']);
    }

    public function testMissingLegacySourceIdentityRemainsExplicitlyUnknown(): void
    {
        $item = $this->flowItem(108, 508, pending: true);
        unset($item['recommendation']['source_module'], $item['recommendation']['source_record_id']);
        $summary = $this->summary([$item]);
        foreach ([$summary['effect_review']['inputs'][0], $summary['recent_items'][0]] as $row) {
            self::assertArrayHasKey('source_module', $row);
            self::assertArrayHasKey('source_record_id', $row);
            self::assertNull($row['source_module']);
            self::assertNull($row['source_record_id']);
        }
        self::assertFalse($summary['effect_review']['next_day_input_ready']);
    }

    private function summary(array $items): array
    {
        $summary = (new RevenueAiOverviewService())->buildExecutionSummaryFromFlow(
            ['data_status' => 'ok', 'data_gaps' => [], 'list' => $items],
            self::DATE,
            7
        );
        $output = getenv('SUXI_BINDING_OBSERVATIONS');
        if (is_string($output) && $output !== '') {
            file_put_contents($output, json_encode([
                'synthetic_test_only' => true,
                'input_items' => $items,
                'summary' => $summary,
                'loaded_source' => (new \ReflectionClass(RevenueAiOverviewService::class))->getFileName(),
                'loaded_flow' => (new \ReflectionClass(ExecutionFlowReadService::class))->getFileName(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
        }
        return $summary;
    }

    private function flowItem(
        int $intentId,
        int $suggestionId,
        bool $pending = false,
        string $module = 'price_suggestion',
        string $date = self::DATE
    ): array {
        $intent = [
            'id' => $intentId, 'hotel_id' => 7, 'tenant_id' => 42,
            'source_module' => $module, 'source_record_id' => $suggestionId,
            'platform' => 'ctrip', 'object_type' => 'price', 'action_type' => 'price_adjust',
            'date_start' => $date, 'date_end' => $date, 'expected_metric' => 'revenue',
            'expected_delta' => 10, 'status' => $pending ? 'pending_approval' : 'approved',
            'target_value' => ['expected_delta_status' => 'quantified'],
        ];
        $task = [
            'id' => $intentId + 100, 'intent_id' => $intentId,
            'hotel_id' => 7, 'tenant_id' => 42, 'status' => 'executed',
            'result_status' => 'success', 'executed_at' => $date . ' 12:00:00',
        ];
        $evidence = [
            'id' => $intentId + 200, 'task_id' => $task['id'], 'tenant_id' => 42,
            'evidence_type' => 'source_verified_metric_readback',
            'before' => ['revenue' => 100], 'after' => ['revenue' => 120, 'cost' => 0],
            'created_by' => 0, 'created_at' => $date . ' 13:00:00',
            'platform_response' => [
                'verification_authority' => 'system_readback', 'source' => 'synthetic_fixture',
                'source_ref' => 'synthetic_fixture#' . $intentId,
                'system_hotel_id' => 7, 'platform' => 'ctrip', 'object_type' => 'price',
                'date_start' => $date, 'date_end' => $date, 'metric_key' => 'revenue',
                'database_written' => true, 'readback_verified' => true, 'readback_count' => 1,
                'readback_at' => $date . ' 13:00:00', 'validation_status' => 'verified',
                'synthetic_test_only' => true,
            ],
        ];
        return (new ExecutionFlowReadService(new ExecutionOutcomeService()))->buildItem(
            $intent,
            $pending ? [] : [$task],
            $pending ? [] : [$evidence]
        );
    }
}
