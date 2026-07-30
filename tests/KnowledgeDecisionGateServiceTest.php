<?php
declare(strict_types=1);

namespace Tests;

use app\service\KnowledgeDecisionGateService;
use PHPUnit\Framework\TestCase;

final class KnowledgeDecisionGateServiceTest extends TestCase
{
    public function testCurrentOfficialKnowledgeIsApprovedForDecisionSupport(): void
    {
        $gate = (new KnowledgeDecisionGateService())->assess([
            'lifecycle_status' => 'active',
            'reviewed_at' => '2026-07-30 00:00:00',
            'review_due_at' => '2026-10-28 00:00:00',
        ], [
            'lifecycle_status' => 'active',
            'scope' => 'platform_rule',
            'evidence_level' => 'official_current_rule',
            'source_refs' => ['official-rule-v1'],
        ], '2026-07-30 12:00:00');

        self::assertSame('approved', $gate['status']);
        self::assertSame('A', $gate['evidence_grade']);
        self::assertSame('current', $gate['freshness_status']);
        self::assertTrue($gate['retrieval_safe']);
        self::assertTrue($gate['decision_safe']);
        self::assertTrue($gate['task_draft_safe']);
    }

    public function testReviewDueKnowledgeRemainsVisibleButCannotDriveDecisionOrTask(): void
    {
        $gate = (new KnowledgeDecisionGateService())->assess([
            'lifecycle_status' => 'active',
            'reviewed_at' => '2025-01-01 00:00:00',
            'review_due_at' => '2025-04-01 00:00:00',
        ], [
            'lifecycle_status' => 'active',
            'scope' => 'generic_methodology',
            'evidence_level' => 'reviewed_method',
            'source_refs' => ['method-review'],
        ], '2026-07-30 12:00:00');

        self::assertSame('reference_only', $gate['status']);
        self::assertSame('review_due', $gate['freshness_status']);
        self::assertTrue($gate['retrieval_safe']);
        self::assertFalse($gate['decision_safe']);
        self::assertFalse($gate['task_draft_safe']);
        self::assertContains('knowledge_review_due', $gate['reason_codes']);
    }

    public function testExpiredOrUntraceableKnowledgeIsBlocked(): void
    {
        $service = new KnowledgeDecisionGateService();
        $expired = $service->assess([
            'lifecycle_status' => 'active',
        ], [
            'scope' => 'platform_rule',
            'evidence_level' => 'official_current_rule',
            'source_refs' => ['official-rule'],
            'valid_until' => '2026-07-01 00:00:00',
        ], '2026-07-30 12:00:00');
        self::assertSame('blocked', $expired['status']);
        self::assertFalse($expired['reference_safe']);
        self::assertContains('knowledge_expired', $expired['reason_codes']);

        $untraceable = $service->assess([], [
            'scope' => 'generic_methodology',
            'evidence_level' => 'reviewed_method',
            'source_refs' => [],
        ], '2026-07-30 12:00:00');
        self::assertSame('blocked', $untraceable['status']);
        self::assertContains('knowledge_traceability_missing', $untraceable['reason_codes']);
    }

    public function testVersionConflictIsReturnedOnlyAsKnownUnknown(): void
    {
        $gate = (new KnowledgeDecisionGateService())->assess([
            'lifecycle_status' => 'active',
            'reviewed_at' => '2026-07-30 00:00:00',
        ], [
            'lifecycle_status' => 'active',
            'scope' => 'version_conflict',
            'evidence_level' => 'two_official_surface_versions_conflict_live_recheck_required',
            'source_refs' => ['official-old', 'official-new'],
            'conflict_key' => 'ctrip_feedback_window_days',
            'decision_status' => 'unresolved_until_live_help_verified',
        ], '2026-07-30 12:00:00');

        self::assertSame('known_unknown', $gate['status']);
        self::assertTrue($gate['retrieval_safe']);
        self::assertFalse($gate['decision_safe']);
        self::assertFalse($gate['task_draft_safe']);
        self::assertContains('knowledge_conflict_unresolved', $gate['reason_codes']);
    }

    public function testUnverifiedMaterialCannotEnterDefaultDecisionPrompt(): void
    {
        $gate = (new KnowledgeDecisionGateService())->assess([
            'lifecycle_status' => 'active',
            'reviewed_at' => '2026-07-30 00:00:00',
        ], [
            'lifecycle_status' => 'active',
            'scope' => 'hotel_reference',
            'evidence_level' => 'user_provided_unverified_case',
            'source_refs' => ['uploaded-material'],
        ], '2026-07-30 12:00:00');

        self::assertSame('reference_only', $gate['status']);
        self::assertSame('D', $gate['evidence_grade']);
        self::assertTrue($gate['reference_safe']);
        self::assertFalse($gate['retrieval_safe']);
        self::assertFalse($gate['decision_safe']);
    }

    public function testConflictingClaimsNeedExplicitResolution(): void
    {
        $service = new KnowledgeDecisionGateService();
        $unresolved = $service->resolveConflictingClaims([
            $this->claimEntry(1, 'feedback_window_days', 30),
            $this->claimEntry(2, 'feedback_window_days', 90),
            $this->claimEntry(3, '', 'unrelated'),
        ]);

        self::assertSame(1, $unresolved['unresolved_conflict_count']);
        self::assertSame(2, $unresolved['excluded_entry_count']);
        self::assertSame([3], array_column($unresolved['entries'], 'chunk_id'));
        self::assertSame('unresolved', $unresolved['conflicts'][0]['status']);

        $resolved = $service->resolveConflictingClaims([
            $this->claimEntry(1, 'feedback_window_days', 30),
            $this->claimEntry(2, 'feedback_window_days', 90, 'resolved'),
        ]);
        self::assertSame(1, $resolved['resolved_conflict_count']);
        self::assertSame([2], array_column($resolved['entries'], 'chunk_id'));
        self::assertSame('resolved', $resolved['conflicts'][0]['status']);
    }

    /**
     * @return array<string, mixed>
     */
    private function claimEntry(
        int $chunkId,
        string $conflictKey,
        mixed $claimValue,
        string $resolutionStatus = ''
    ): array {
        $content = ['claim_value' => $claimValue];
        if ($conflictKey !== '') {
            $content['conflict_key'] = $conflictKey;
        }
        if ($resolutionStatus !== '') {
            $content['resolution_status'] = $resolutionStatus;
        }

        return [
            'chunk_id' => $chunkId,
            'unit_id' => 1,
            'content' => $content,
        ];
    }
}
