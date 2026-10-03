<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Agent;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ReflectionHelper;

final class AgentOtaDiagnosisRevenueCoverageTest extends TestCase
{
    use ReflectionHelper;

    private function controller(): Agent
    {
        return (new ReflectionClass(Agent::class))->newInstanceWithoutConstructor();
    }

    private function row(int $id, string $date, ?float $amount, ?float $nights, ?float $orders = 1): array
    {
        $metrics = ['amount' => $amount, 'quantity' => $nights, 'book_order_num' => $orders];
        $facts = [];
        foreach (['amount' => 'order_amount', 'quantity' => 'room_nights', 'book_order_num' => 'order_count'] as $field => $metric) {
            $facts[] = ['metric_key' => $metric, 'normalized_field' => $field,
                'status' => $metrics[$field] === null ? 'missing' : 'captured',
                'stored_value_present' => $metrics[$field] !== null];
        }
        return array_merge(['id' => $id, 'tenant_id' => 31, 'system_hotel_id' => 901,
            'hotel_id' => 'synthetic-poi-901', 'hotel_name' => 'Synthetic hotel',
            'source' => 'ctrip', 'platform' => 'ctrip', 'data_type' => 'business',
            'data_date' => $date, 'dimension' => 'business:temporal_summary',
            'data_period' => 'historical_daily', 'is_final' => 1, 'readback_verified' => 1,
            'validation_status' => 'normal', 'source_trace_id' => 'synthetic-trace-' . $id,
            'raw_data' => json_encode(['row' => $metrics, 'field_facts' => $facts], JSON_THROW_ON_ERROR)], $metrics);
    }

    private function buildDiagnosticResult(array $rows, string $end = '2026-09-30'): array
    {
        $controller = $this->controller();
        foreach ($rows as $row) {
            self::assertTrue($this->invokeNonPublic($controller, 'isOtaDiagnosisDecisionEligibleRow', [$row]),
                'Synthetic source row must pass the unchanged production eligibility gate');
        }
        return $this->invokeNonPublic($controller, 'buildOtaDiagnosisResult', [[
            'hotel' => ['id' => 901, 'tenant_id' => 31, 'name' => 'Synthetic hotel'],
            'online_rows' => $rows, 'decision_eligible_online_rows' => $rows,
            'daily_reports' => [], 'competitor_prices' => [], 'competitor_analyses' => [],
            'price_suggestions' => [], 'sync_logs' => [],
        ], 901, '901', 'Synthetic hotel', 'ctrip', '2026-09-29', $end, 'all']);
    }

    public function testMissingRevenueDayCannotBecomeZeroInPeriodAdrOrPrompt(): void
    {
        $result = $this->buildDiagnosticResult([$this->row(8101, '2026-09-29', 100, 1), $this->row(8102, '2026-09-30', null, 9)]);
        self::assertNull($result['metrics']['amount'], 'A partial observed subtotal is not complete period revenue');
        self::assertSame(10, $result['metrics']['quantity'], 'Known room nights remain visible');
        self::assertNull($result['metrics']['adr'], '100 from one day divided by 10 nights across two days falsely treats missing revenue as zero');
        self::assertContains('metric_missing:amount', $result['data_gaps']);
        self::assertFalse($result['data_summary']['core_metrics_complete']);
        self::assertSame(100.0, $result['source_summary']['daily'][0]['amount']);
        self::assertNull($result['source_summary']['daily'][1]['amount']);
        $prompt = $this->invokeNonPublic($this->controller(), 'buildOtaDiagnosisPrompt', [$result]);
        self::assertMatchesRegularExpression('/"adr"\s*:\s*null/', $prompt);
        self::assertMatchesRegularExpression('/"amount"\s*:\s*null/', $prompt);
        $final = $this->invokeNonPublic($this->controller(), 'finalizeOtaDiagnosisDecision', [$result]);
        self::assertSame('blocked_by_missing_facts', $final['decision_status']);
        self::assertSame('do_not_create_execution_intent', $final['execution_policy']);
    }

    public function testMissingRoomNightDayCannotSupplyAMisalignedDenominator(): void
    {
        $result = $this->buildDiagnosticResult([$this->row(8101, '2026-09-29', 100, 1), $this->row(8102, '2026-09-30', 2700, null)]);
        self::assertSame(2800.0, $result['metrics']['amount']);
        self::assertNull($result['metrics']['quantity']);
        self::assertNull($result['metrics']['adr']);
        self::assertContains('metric_missing:quantity', $result['data_gaps']);
    }

    public function testAbsentRequestedDayCannotBeSilentlyDroppedFromPeriodTotals(): void
    {
        $result = $this->buildDiagnosticResult([$this->row(8101, '2026-09-29', 100, 1)]);
        self::assertNull($result['metrics']['amount']);
        self::assertNull($result['metrics']['quantity']);
        self::assertNull($result['metrics']['book_order_num']);
        self::assertNull($result['metrics']['adr']);
        self::assertSame(100.0, $result['source_summary']['daily'][0]['amount']);
    }

    public function testMissingOrdersAreNotPresentedAsCompletePeriodOrders(): void
    {
        $result = $this->buildDiagnosticResult([$this->row(8101, '2026-09-29', 100, 1), $this->row(8102, '2026-09-30', 2700, 9, null)]);
        self::assertNull($result['metrics']['book_order_num']);
        self::assertSame(280.0, $result['metrics']['adr']);
        self::assertContains('metric_missing:book_order_num', $result['data_gaps']);
    }

    public function testCompletePeriodUsesWeightedAdrFromAlignedInputs(): void
    {
        $result = $this->buildDiagnosticResult([$this->row(8101, '2026-09-29', 100, 1), $this->row(8102, '2026-09-30', 2700, 9)]);
        self::assertSame(2800.0, $result['metrics']['amount']);
        self::assertSame(10, $result['metrics']['quantity']);
        self::assertSame(280.0, $result['metrics']['adr']);
        self::assertTrue($result['data_summary']['core_metrics_complete']);
    }

    public function testObservedZeroDayRemainsPresentAndNeverCountsAsMissing(): void
    {
        $result = $this->buildDiagnosticResult([$this->row(8101, '2026-09-29', 100, 1), $this->row(8102, '2026-09-30', 0, 0, 0)]);
        self::assertSame(100.0, $result['metrics']['amount']);
        self::assertSame(1, $result['metrics']['quantity']);
        self::assertSame(100.0, $result['metrics']['adr']);
        self::assertTrue($result['data_summary']['core_metrics_complete']);
        self::assertSame(0.0, $result['source_summary']['daily'][1]['amount']);
    }

    public function testEntireObservedZeroPeriodKeepsZeroAndAdrUndefined(): void
    {
        $result = $this->buildDiagnosticResult([$this->row(8101, '2026-09-29', 0, 0, 0), $this->row(8102, '2026-09-30', 0, 0, 0)]);
        self::assertSame(0.0, $result['metrics']['amount']);
        self::assertSame(0, $result['metrics']['quantity']);
        self::assertNull($result['metrics']['adr']);
        self::assertTrue($result['data_summary']['core_metrics_complete']);
    }

    public function testSameDaySupplementCanCompleteInputsWithoutDemandingEachRowHaveEveryMetric(): void
    {
        $result = $this->buildDiagnosticResult([$this->row(8101, '2026-09-29', 100, null),
            $this->row(8102, '2026-09-29', null, 1)], '2026-09-29');
        self::assertSame(100.0, $result['metrics']['amount']);
        self::assertSame(1, $result['metrics']['quantity']);
        self::assertSame(100.0, $result['metrics']['adr']);
    }
}
