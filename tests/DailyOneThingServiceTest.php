<?php
declare(strict_types=1);

namespace Tests;

use app\service\DailyOneThingService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DailyOneThingServiceTest extends TestCase
{
    public function testSelectsVerifiedBookabilityGapAheadOfLowerQualityAiGap(): void
    {
        $result = (new DailyOneThingService())->select([
            [
                'id' => 11,
                'feature_key' => 'ai_guest_acquisition',
                'source_quality_status' => 'manual_unverified',
                'created_at' => '2026-08-22 09:00:00',
                'result' => ['status' => 'measured', 'failed_intent_count' => 5],
            ],
            [
                'id' => 12,
                'feature_key' => 'bookability_gap',
                'source_quality_status' => 'verified',
                'created_at' => '2026-08-22 09:05:00',
                'result' => [
                    'status' => 'gap_detected',
                    'gap_count' => 1,
                    'earliest_failure_stage' => 'pre_checkout',
                    'potential_lost_revenue' => 688,
                ],
            ],
        ], '2026-08-22');

        self::assertSame('action_required', $result['status']);
        self::assertSame('bookability_gap', $result['selected']['feature_key']);
        self::assertSame(12, $result['selected']['run_id']);
        self::assertFalse($result['can_execute']);
        self::assertTrue($result['requires_human_approval']);
        self::assertStringContainsString('只用于分配注意力', $result['selection_boundary']);
    }

    public function testBlockedEvidenceDoesNotBecomeAction(): void
    {
        $result = (new DailyOneThingService())->select([
            [
                'feature_key' => 'service_promise_risk',
                'source_quality_status' => 'unverified',
                'result' => ['status' => 'blocked_by_missing_facts'],
            ],
            [
                'feature_key' => 'promotion_incrementality',
                'source_quality_status' => 'unverified',
                'result' => ['effect_status' => 'indeterminate'],
            ],
        ], '2026-08-22');

        self::assertSame('blocked_by_missing_facts', $result['status']);
        self::assertNull($result['selected']);
        self::assertSame(2, $result['blocked_count']);
    }

    public function testNoActionIsAValidOutput(): void
    {
        $result = (new DailyOneThingService())->select([
            [
                'feature_key' => 'service_promise_risk',
                'source_quality_status' => 'verified',
                'result' => ['status' => 'capacity_available', 'risk_count' => 0],
            ],
            [
                'feature_key' => 'bookability_gap',
                'source_quality_status' => 'verified',
                'result' => ['status' => 'aligned', 'gap_count' => 0],
            ],
        ], '2026-08-22');

        self::assertSame('no_action', $result['status']);
        self::assertStringContainsString('没有需要打断老板', $result['headline']);
    }

    public function testRejectsInvalidBusinessDate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new DailyOneThingService())->select([], '2026-02-30');
    }
}
