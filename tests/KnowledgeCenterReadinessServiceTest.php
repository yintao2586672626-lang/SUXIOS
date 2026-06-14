<?php
declare(strict_types=1);

namespace Tests;

use app\service\KnowledgeCenterReadinessService;
use PHPUnit\Framework\TestCase;

final class KnowledgeCenterReadinessServiceTest extends TestCase
{
    public function testPendingUnitIsNotReady(): void
    {
        $readiness = (new KnowledgeCenterReadinessService())->buildUnitReadiness([
            'status' => 'pending',
            'hotel_id' => 3,
        ], 0);

        self::assertSame('unit_pending', $readiness['stage']);
        self::assertFalse($readiness['closed_loop']);
        self::assertSame(['processed_status'], array_column($readiness['missing_evidence'], 'code'));
    }

    public function testDoneUnitRequiresChunks(): void
    {
        $readiness = (new KnowledgeCenterReadinessService())->buildUnitReadiness([
            'status' => 'done',
            'hotel_id' => 3,
        ], 0);

        self::assertSame('unit_done_no_chunks', $readiness['stage']);
        self::assertFalse($readiness['closed_loop']);
        self::assertSame(['knowledge_chunks'], array_column($readiness['missing_evidence'], 'code'));
    }

    public function testDoneUnitWithoutHotelKeepsScopeBoundaryVisible(): void
    {
        $readiness = (new KnowledgeCenterReadinessService())->buildUnitReadiness([
            'status' => 'done',
            'hotel_id' => 0,
        ], 2);

        self::assertSame('unit_global_scope', $readiness['stage']);
        self::assertFalse($readiness['closed_loop']);
        self::assertSame(['hotel_scope'], array_column($readiness['missing_evidence'], 'code'));
    }

    public function testDoneHotelUnitWithChunksIsReady(): void
    {
        $readiness = (new KnowledgeCenterReadinessService())->buildUnitReadiness([
            'status' => 'done',
            'hotel_id' => 8,
        ], 2);

        self::assertSame('unit_ready', $readiness['stage']);
        self::assertTrue($readiness['closed_loop']);
        self::assertSame(100, $readiness['score']);
        self::assertSame([], $readiness['missing_evidence']);
    }
}
