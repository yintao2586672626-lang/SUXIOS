<?php
declare(strict_types=1);

namespace Tests;

use app\service\KnowledgeDecisionGateService;
use app\service\KnowledgeSopExecutionProvenanceService;
use app\service\OperatingQuestionKnowledgeRetrievalService;
use PHPUnit\Framework\TestCase;

final class KnowledgeReferenceUsagePolicyTest extends TestCase
{
    public function testReferencePolicyCannotBeOverriddenByGradeOrPositiveFlags(): void
    {
        foreach (['A', 'B', 'C'] as $grade) {
            foreach ([['usage_policy' => ' Reference_Only '], ['scope' => 'reference_only'],
                ['decision_policy' => 'reference_only_until_separate_review'],
                ['decision_policy' => 'reference_only_until_separate_hotel_and_platform_verification']] as $policy) {
                $content = [...$this->content($grade), ...$policy,
                    'decision_safe' => true, 'task_draft_safe' => true];
                $before = $content;
                $gate = $this->assess($content);
                self::assertSame('reference_only', $gate['status']);
                self::assertTrue($gate['retrieval_safe']);
                self::assertFalse($gate['decision_safe']);
                self::assertFalse($gate['task_draft_safe']);
                self::assertContains('knowledge_usage_reference_only', $gate['reason_codes']);
                self::assertSame($before, $content);
            }
        }
    }

    public function testExplicitDenialsAndInvalidFlagsDoNotAuthorizeActions(): void
    {
        foreach ([false, 0, 'false', '0', null, [], 'unknown'] as $value) {
            $gate = $this->assess([...$this->content('B'), 'decision_safe' => $value, 'task_draft_safe' => $value]);
            self::assertSame('reference_only', $gate['status']);
            self::assertTrue($gate['retrieval_safe']);
            self::assertFalse($gate['decision_safe']);
            self::assertFalse($gate['task_draft_safe']);
            self::assertContains('knowledge_decision_explicitly_disallowed', $gate['reason_codes']);
            self::assertContains('knowledge_task_draft_explicitly_disallowed', $gate['reason_codes']);
        }
    }

    public function testDecisionAndTaskDenialsRemainIndependent(): void
    {
        $decisionDenied = $this->assess([...$this->content('B'), 'decision_safe' => false]);
        self::assertSame('reference_only', $decisionDenied['status']);
        self::assertFalse($decisionDenied['decision_safe']);
        self::assertTrue($decisionDenied['task_draft_safe']);
        $taskDenied = $this->assess([...$this->content('B'), 'task_draft_safe' => false]);
        self::assertSame('approved', $taskDenied['status']);
        self::assertTrue($taskDenied['decision_safe']);
        self::assertFalse($taskDenied['task_draft_safe']);
    }

    public function testMissingPolicyKeepsExistingReviewedMethodCompatibility(): void
    {
        $official = $this->assess($this->content('A'));
        self::assertSame('approved', $official['status']);
        self::assertTrue($official['decision_safe']);
        self::assertTrue($official['task_draft_safe']);
        $method = $this->assess($this->content('C'));
        self::assertSame('reference_only', $method['status']);
        self::assertFalse($method['decision_safe']);
        self::assertTrue($method['task_draft_safe']);
    }

    public function testPositiveFlagsCannotBypassExistingEvidenceAndSourceGuards(): void
    {
        $positive = ['decision_safe' => true, 'task_draft_safe' => true];
        foreach ([['evidence_grade' => 'D'], ['source_refs' => []], ['lifecycle_status' => 'archived'],
            ['reviewed_at' => '2026-10-07 00:00:00'], ['blocked_uses' => ['operation_task_creation']]] as $change) {
            $gate = $this->assess([...$this->content('A'), ...$positive, ...$change]);
            self::assertFalse($gate['task_draft_safe']);
            if (!isset($change['blocked_uses'])) {
                self::assertFalse($gate['decision_safe']);
            }
        }
    }

    public function testReferencePolicyPreservesHardBlockedAndUnknownStates(): void
    {
        $base = [...$this->content('B'), 'usage_policy' => 'reference_only'];
        $blocked = $this->assess([...$base, 'source_refs' => []]);
        self::assertSame('blocked', $blocked['status']);
        self::assertFalse($blocked['retrieval_safe']);
        self::assertFalse($blocked['task_draft_safe']);
        $unknown = $this->assess([...$base, 'scope' => 'known_unknown', 'unknowns' => ['current_source_missing']]);
        self::assertSame('known_unknown', $unknown['status']);
        self::assertTrue($unknown['retrieval_safe']);
        self::assertFalse($unknown['decision_safe']);
        self::assertFalse($unknown['task_draft_safe']);
    }

    private function content(string $grade): array
    {
        return ['lifecycle_status' => 'active', 'scope' => 'generic_methodology',
            'evidence_level' => 'source_code_reviewed', 'evidence_grade' => $grade,
            'source_refs' => ['synthetic-versioned-method'], 'reviewed_at' => '2026-10-06 00:00:00'];
    }

    public function testOperatingQuestionKeepsExplicitReferencesReadableWithoutDecisionSupport(): void
    {
        $service = new OperatingQuestionKnowledgeRetrievalService();
        foreach ([['usage_policy' => 'reference_only'], ['scope' => 'reference_only'],
            ['decision_policy' => 'reference_only_until_separate_review'], ['decision_safe' => false]] as $policy) {
            [$unit, $chunk] = $this->snapshot($policy);
            $result = $service->buildFromRows([$unit], [$chunk], ['hotel_id' => 7, 'user_id' => 9,
                'platform' => 'ctrip', 'question' => '广告质量来源核验']);
            self::assertSame('matched', $result['status']);
            self::assertCount(1, $result['items']);
            self::assertSame(291, $result['items'][0]['chunk_id']);
            self::assertSame('reference_only', $result['items'][0]['usage_policy']);
            self::assertSame('reference_only', $result['items'][0]['gate_status']);
        }
    }

    public function testTaskEntryRejectsReferencesButKeepsReviewedSopUsable(): void
    {
        $service = new KnowledgeSopExecutionProvenanceService();
        [$unit, $chunk] = $this->snapshot(['evidence_grade' => 'C']);
        $accepted = $service->validateSnapshot($unit, $chunk, 7, 'ctrip', '2026-10-06 12:00:00');
        self::assertTrue($accepted['knowledge_gate']['task_draft_safe']);
        self::assertSame(7, $accepted['target_hotel_id']);
        foreach ([['usage_policy' => 'reference_only'], ['scope' => 'reference_only'],
            ['decision_policy' => 'reference_only_until_separate_hotel_and_platform_verification'], ['task_draft_safe' => false],
            ['usage_policy' => 'reference_only', 'decision_safe' => true, 'task_draft_safe' => true]] as $policy) {
            [$unit, $chunk] = $this->snapshot($policy);
            try {
                $service->validateSnapshot($unit, $chunk, 7, 'ctrip', '2026-10-06 12:00:00');
                self::fail('Explicit reference restriction reached task provenance.');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('knowledge SOP is stale, conflicting, unverified, or otherwise unsafe for a task draft', $error->getMessage());
            }
        }
    }

    private function snapshot(array $policy): array
    {
        $unit = ['unit_id' => 40, 'current_chunk_id' => 291, 'hotel_id' => 0, 'created_by' => 0,
            'status' => 'done', 'source' => 'system_reference', 'stable_key' => '',
            'name' => '广告质量来源核验', 'description' => '广告质量来源核验步骤',
            'lifecycle_status' => 'active', 'reviewed_at' => '2020-01-01 00:00:00', 'review_due_at' => '2099-01-01 00:00:00'];
        $content = [...$this->content('B'), 'reviewed_at' => '2020-01-01 00:00:00', 'review_due_at' => '2099-01-01 00:00:00',
            'content_type' => 'sop_card', 'title' => '广告质量来源核验', 'summary' => '广告质量来源核验步骤', 'platforms' => ['ctrip'],
            'steps' => ['广告质量来源核验'], 'task_template' => ['title' => '广告质量来源核验',
                'steps' => ['先核对广告质量来源'], 'acceptance_criteria' => ['来源范围一致']], ...$policy];
        return [$unit, ['chunk_id' => 291, 'unit_id' => 40, 'type' => '运营SOP', 'content' => $content,
            'lifecycle_status' => 'active', 'superseded_by_chunk_id' => 0]];
    }

    private function assess(array $content): array
    {
        return (new KnowledgeDecisionGateService())->assess(['lifecycle_status' => 'active'], $content, '2026-10-06 12:00:00');
    }
}
