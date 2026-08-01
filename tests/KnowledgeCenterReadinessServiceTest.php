<?php
declare(strict_types=1);

namespace Tests;

use app\service\KnowledgeCenterReadinessService;
use PHPUnit\Framework\TestCase;

final class KnowledgeCenterReadinessServiceTest extends TestCase
{
    public function testQuarantinedUnitIsVisibleButNotRetrievable(): void
    {
        $readiness = (new KnowledgeCenterReadinessService())->buildUnitReadiness([
            'status' => 'done',
            'lifecycle_status' => 'quarantined',
            'lifecycle_reason' => '旧研究合同已失效',
            'hotel_id' => 8,
        ], 2);

        self::assertSame('unit_quarantined', $readiness['stage']);
        self::assertSame('已隔离', $readiness['status_label']);
        self::assertSame('quarantined', $readiness['lifecycle_status']);
        self::assertSame('旧研究合同已失效', $readiness['lifecycle_reason']);
        self::assertFalse($readiness['closed_loop']);
        self::assertSame(['lifecycle_quarantined'], array_column($readiness['missing_evidence'], 'code'));
    }

    public function testStaleUnitMustBeReviewedBeforeRetrieval(): void
    {
        $readiness = (new KnowledgeCenterReadinessService())->buildUnitReadiness([
            'status' => 'done',
            'lifecycle_status' => 'stale',
            'hotel_id' => 8,
        ], 2);

        self::assertSame('unit_stale', $readiness['stage']);
        self::assertSame('待复核', $readiness['status_label']);
        self::assertFalse($readiness['closed_loop']);
        self::assertSame(['lifecycle_stale'], array_column($readiness['missing_evidence'], 'code'));
    }

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
            'known_knowns' => ['通用方法已复核'],
            'known_unknowns' => ['当前门店事实待验证'],
            'truth_profile_version' => '2026-07-29.1',
        ], 2);

        self::assertSame('unit_global_scope', $readiness['stage']);
        self::assertFalse($readiness['closed_loop']);
        self::assertSame(['hotel_scope'], array_column($readiness['missing_evidence'], 'code'));
    }

    public function testReviewedSystemOwnedGlobalReferenceIsReadyWithoutPretendingToBeHotelData(): void
    {
        $readiness = (new KnowledgeCenterReadinessService())->buildUnitReadiness([
            'status' => 'done',
            'hotel_id' => 0,
            'created_by' => 0,
            'known_knowns' => ['官方公开规则已复核'],
            'known_unknowns' => ['当前门店事实待验证'],
            'truth_profile_version' => '2026-07-30.3',
        ], 3);

        self::assertSame('unit_global_reference', $readiness['stage']);
        self::assertSame('通用知识可检索', $readiness['status_label']);
        self::assertTrue($readiness['closed_loop']);
        self::assertSame(100, $readiness['score']);
        self::assertSame([], $readiness['missing_evidence']);
        self::assertStringContainsString('不绑定为任何单店事实', $readiness['next_action']);
    }

    public function testReviewDueUnitStopsDecisionReadinessButKeepsKnowledgeVisible(): void
    {
        $readiness = (new KnowledgeCenterReadinessService())->buildUnitReadiness([
            'status' => 'done',
            'hotel_id' => 0,
            'created_by' => 0,
            'known_knowns' => ['来源规则曾经复核'],
            'known_unknowns' => ['当前版本是否变化待核验'],
            'truth_profile_version' => '2026-01-01.1',
            'reviewed_at' => '2026-01-01 00:00:00',
            'review_due_at' => '2026-04-01 00:00:00',
            '_as_of' => '2026-07-30 00:00:00',
        ], 3);

        self::assertSame('unit_review_due', $readiness['stage']);
        self::assertSame('review_due', $readiness['freshness_status']);
        self::assertFalse($readiness['closed_loop']);
        self::assertSame(75, $readiness['score']);
        self::assertSame(
            ['knowledge_review_due'],
            array_column($readiness['missing_evidence'], 'code')
        );
        self::assertTrue($readiness['can_open_chunks']);
    }

    public function testDoneHotelUnitWithChunksIsReady(): void
    {
        $readiness = (new KnowledgeCenterReadinessService())->buildUnitReadiness([
            'status' => 'done',
            'hotel_id' => 8,
            'known_knowns' => ['方法已复核'],
            'known_unknowns' => ['效果待复盘'],
            'truth_profile_version' => '2026-07-29.1',
        ], 2);

        self::assertSame('unit_ready', $readiness['stage']);
        self::assertTrue($readiness['closed_loop']);
        self::assertSame(100, $readiness['score']);
        self::assertSame('active', $readiness['lifecycle_status']);
        self::assertSame('mapped', $readiness['truth_profile_status']);
        self::assertSame(1, $readiness['known_known_count']);
        self::assertSame(1, $readiness['known_unknown_count']);
        self::assertSame([], $readiness['missing_evidence']);
    }

    public function testDoneUnitWithChunksRequiresTruthProfile(): void
    {
        $readiness = (new KnowledgeCenterReadinessService())->buildUnitReadiness([
            'status' => 'done',
            'hotel_id' => 8,
        ], 2);

        self::assertSame('unit_truth_map_missing', $readiness['stage']);
        self::assertSame('missing', $readiness['truth_profile_status']);
        self::assertSame(
            ['known_knowns', 'known_unknowns', 'truth_profile_version'],
            array_column($readiness['missing_evidence'], 'code')
        );
    }

    public function testTruthMappedUnitCannotBeReadyWhenNoChunkPassesRetrievalGate(): void
    {
        $readiness = (new KnowledgeCenterReadinessService())->buildUnitReadiness([
            'status' => 'done',
            'hotel_id' => 8,
            'known_knowns' => ['已确认事实'],
            'known_unknowns' => ['待验证事实'],
            'truth_profile_version' => '2026-07-30.4',
            '_chunk_gate_summary' => [
                'total_count' => 2,
                'retrieval_safe_count' => 0,
                'decision_safe_count' => 0,
                'blocked_count' => 2,
            ],
        ], 2);

        self::assertSame('unit_chunks_unverified', $readiness['stage']);
        self::assertFalse($readiness['closed_loop']);
        self::assertSame(
            ['retrieval_safe_chunk_missing'],
            array_column($readiness['missing_evidence'], 'code')
        );
        self::assertSame(2, $readiness['chunk_gate_summary']['blocked_count']);
    }

    public function testRetrievalSafeReferenceCannotBeReportedAsDecisionReady(): void
    {
        $readiness = (new KnowledgeCenterReadinessService())->buildUnitReadiness([
            'status' => 'done',
            'hotel_id' => 8,
            'known_knowns' => ['已确认事实'],
            'known_unknowns' => ['待验证事实'],
            'truth_profile_version' => '2026-07-30.4',
            '_chunk_gate_summary' => [
                'total_count' => 1,
                'retrieval_safe_count' => 1,
                'decision_safe_count' => 0,
                'reference_only_count' => 1,
            ],
        ], 1);

        self::assertSame('unit_reference_only', $readiness['stage']);
        self::assertFalse($readiness['closed_loop']);
        self::assertSame(
            ['decision_safe_chunk_missing'],
            array_column($readiness['missing_evidence'], 'code')
        );
    }

    public function testUnresolvedChunkConflictBlocksUnitEvenWhenAnotherChunkIsDecisionSafe(): void
    {
        $readiness = (new KnowledgeCenterReadinessService())->buildUnitReadiness([
            'status' => 'done',
            'hotel_id' => 8,
            'known_knowns' => ['已确认事实'],
            'known_unknowns' => ['待验证事实'],
            'truth_profile_version' => '2026-07-30.4',
            '_chunk_gate_summary' => [
                'total_count' => 3,
                'retrieval_safe_count' => 1,
                'decision_safe_count' => 1,
                'unresolved_conflict_count' => 1,
            ],
        ], 3);

        self::assertSame('unit_conflict_unresolved', $readiness['stage']);
        self::assertFalse($readiness['closed_loop']);
        self::assertSame(
            ['knowledge_conflict_unresolved'],
            array_column($readiness['missing_evidence'], 'code')
        );
    }

    public function testMixedSafeAndKnownUnknownChunksRemainPartiallyReady(): void
    {
        $readiness = (new KnowledgeCenterReadinessService())->buildUnitReadiness([
            'status' => 'done',
            'hotel_id' => 8,
            'known_knowns' => ['已确认事实'],
            'known_unknowns' => ['待验证事实'],
            'truth_profile_version' => '2026-07-30.4',
            '_chunk_gate_summary' => [
                'total_count' => 2,
                'retrieval_safe_count' => 2,
                'decision_safe_count' => 1,
                'known_unknown_count' => 1,
            ],
        ], 2);

        self::assertSame('unit_partially_ready', $readiness['stage']);
        self::assertFalse($readiness['closed_loop']);
        self::assertSame(
            ['knowledge_chunks_partially_ready'],
            array_column($readiness['missing_evidence'], 'code')
        );
    }

    public function testAllDecisionSafeChunksKeepUnitReady(): void
    {
        $readiness = (new KnowledgeCenterReadinessService())->buildUnitReadiness([
            'status' => 'done',
            'hotel_id' => 8,
            'known_knowns' => ['已确认事实'],
            'known_unknowns' => ['待验证事实'],
            'truth_profile_version' => '2026-07-30.4',
            '_chunk_gate_summary' => [
                'total_count' => 2,
                'retrieval_safe_count' => 2,
                'decision_safe_count' => 2,
                'task_draft_safe_count' => 2,
            ],
        ], 2);

        self::assertSame('unit_ready', $readiness['stage']);
        self::assertTrue($readiness['closed_loop']);
        self::assertSame(2, $readiness['chunk_gate_summary']['decision_safe_count']);
    }
}
