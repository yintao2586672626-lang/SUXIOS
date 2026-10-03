<?php
declare(strict_types=1);

namespace Tests;

use app\controller\StrategySimulation;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/** Historical record truth remains supported after strategy generation retirement. */
final class StrategySimulationTruthTest extends TestCase
{
    public function testHistoricalMissingScoresStayMissingInsteadOfZero(): void
    {
        $record = $this->format(['id' => 37, 'tenant_id' => 9, 'input_json' => ['hotel_id' => 7], 'score_json' => []], true);
        self::assertNull($record['total_score']);
        self::assertFalse($record['decision_ready']);
        self::assertSame('legacy_rule_score', $record['score_type']);
        self::assertSame(9, $record['_execution_source_tenant_id']);
        self::assertSame(7, $record['input']['hotel_id']);
    }

    public function testHistoricalZeroScoreAndDataGapsRemainExactInListAndDetail(): void
    {
        $row = [
            'id' => 38, 'tenant_id' => 9, 'created_at' => '2026-08-13 10:00:00',
            'input_json' => json_encode(['hotel_id' => 7, 'ota_target_date' => '2026-08-13', 'competitor_count' => null], JSON_THROW_ON_ERROR),
            'score_json' => json_encode(['total_score' => 0, 'decision_ready' => false, 'data_gaps' => ['competitor_evidence_missing']], JSON_THROW_ON_ERROR),
            'data_snapshot_json' => json_encode(['hotel_id' => 7, 'data_date' => '2026-08-13', 'source' => 'ctrip', 'status' => 'unverified'], JSON_THROW_ON_ERROR),
        ];
        $detail = $this->format($row, true);
        $list = $this->format($row, false);
        self::assertSame(0, $detail['total_score']);
        self::assertSame($detail['total_score'], $list['total_score']);
        self::assertSame(['competitor_evidence_missing'], $detail['data_gaps']);
        self::assertNull($detail['input']['competitor_count']);
        self::assertSame('ctrip', $detail['data_snapshot']['source']);
        self::assertSame('unverified', $detail['data_snapshot']['status']);
        self::assertSame('2026-08-13', $detail['data_snapshot']['data_date']);
        self::assertArrayNotHasKey('input', $list);
    }

    private function format(array $row, bool $detail): array
    {
        $controller = (new ReflectionClass(StrategySimulation::class))->newInstanceWithoutConstructor();
        return (new ReflectionMethod(StrategySimulation::class, 'formatRecord'))->invoke($controller, $row, $detail);
    }
}
