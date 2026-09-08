<?php
declare(strict_types=1);
namespace Tests;

use app\service\KnowledgeApplicabilityService;
use app\service\KnowledgeDecisionGateService;
use app\service\OperatingQuestionKnowledgeRetrievalService;
use PHPUnit\Framework\TestCase;
use Tests\Support\KnowledgeApplicabilityFixture;

final class KnowledgeApplicabilityEvaluationTest extends TestCase
{
    public function testFixedQuestionCorpusControlsActualRetrievedCitationsAndDecisionUse(): void
    {
        $cases = KnowledgeApplicabilityFixture::cases();
        self::assertGreaterThanOrEqual(30, count($cases));
        foreach ($cases as $case) {
            $result = (new OperatingQuestionKnowledgeRetrievalService())->buildFromRows($case['units'], $case['chunks'], $case['scope']);
            self::assertSame($case['expected_ids'], array_column($result['items'], 'chunk_id'), $case['id']);
            self::assertSame($case['decision_ids'], array_column(array_filter($result['items'], static fn($item) => $item['decision_safe']), 'chunk_id'), $case['id']);
            if ($case['reason']) {
                self::assertContains($case['reason'], array_merge([], ...array_column($result['exclusions'] ?? [], 'reason_codes')), $case['id']);
            }
            foreach ($result['items'] as $item) {
                self::assertFalse($item['fact_safe']); self::assertFalse($item['external_write_authorized']);
                self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $item['content_digest']);
            }
        }
    }

    public function testDateBoundariesAndMalformedDatesFailClosed(): void
    {
        $case = KnowledgeApplicabilityFixture::cases()[0];
        $service = new KnowledgeApplicabilityService();
        $content = $case['chunks'][0]['content'];
        $scope = array_replace($case['scope'], ['as_of' => '2026-09-30 23:59:59']);
        self::assertTrue($service->assess($case['units'][0], $content, $scope)['decision_safe']);
        self::assertFalse($service->assess($case['units'][0], $content, array_replace($scope, ['as_of' => '2026-10-01']))['retrieval_safe']);
        foreach (['2026-02-30', 'tomorrow', 'invalid', '2026-09-31'] as $date) {
            $result = $service->assess($case['units'][0], array_replace($content, ['valid_until' => $date]), $case['scope']);
            self::assertFalse($result['retrieval_safe']); self::assertContains('knowledge_date_invalid', $result['reason_codes']);
        }
        self::assertFalse($service->assess($case['units'][0], $content, array_replace($scope, ['as_of' => 'tomorrow']))['retrieval_safe']);
    }

    public function testUnknownPlatformMissingTenantConditionsAndDigestCannotWidenScope(): void
    {
        $case = KnowledgeApplicabilityFixture::cases()[0];
        $service = new KnowledgeApplicabilityService(); $content = $case['chunks'][0]['content'];
        self::assertFalse($service->assess($case['units'][0], array_replace($content, ['platforms' => ['unknown_ota']]), $case['scope'])['retrieval_safe']);
        self::assertFalse($service->assess($case['units'][0] + ['tenant_id' => 1], $content, array_replace($case['scope'], ['tenant_id' => 0]))['retrieval_safe']);
        self::assertFalse($service->assess($case['units'][0], $content, $case['scope'], ['content_digest' => str_repeat('0', 64)])['retrieval_safe']);
    }

    public function testNestedDeclarationsCannotOverrideTopLevelScope(): void
    {
        $case = KnowledgeApplicabilityFixture::cases()[0];
        $service = new KnowledgeApplicabilityService();
        foreach (['platforms' => [['meituan'], ['ctrip']], 'hotel_ids' => [[81], [80]], 'tenant_ids' => [[2], [1]]] as $field => [$outer, $inner]) {
            $chunks = $case['chunks'];
            $chunks[0]['content'] = array_replace($chunks[0]['content'], [$field => $outer, 'applicability' => [$field => $inner]]);
            $assessment = $service->assess($case['units'][0], $chunks[0]['content'], $case['scope']);
            self::assertFalse($assessment['retrieval_safe'], $field);
            self::assertFalse($assessment['decision_safe'], $field);
            self::assertSame([], (new OperatingQuestionKnowledgeRetrievalService())->buildFromRows($case['units'], $chunks, $case['scope'])['items'], $field);
            $chunks[0]['content']['applicability'][$field] = [];
            self::assertFalse($service->assess($case['units'][0], $chunks[0]['content'], $case['scope'])['retrieval_safe'], $field . ' empty nested restriction');
        }
        $content = array_replace($case['chunks'][0]['content'], ['platforms' => ['all_ota'], 'hotel_ids' => [80, 81], 'tenant_ids' => [1, 2], 'applicability' => ['platforms' => ['ctrip'], 'hotel_ids' => [80], 'tenant_ids' => [1]]]);
        $valid = $service->assess($case['units'][0], $content, $case['scope']);
        self::assertTrue($valid['decision_safe']);
        self::assertSame(['ctrip'], $valid['scope']['platforms']);
        self::assertFalse($service->assess($case['units'][0], $content, array_replace($case['scope'], ['platform' => 'meituan']))['retrieval_safe']);
        $content['platforms'] = ['ctrip']; $content['applicability']['platforms'] = ['meituan'];
        self::assertFalse($service->assess($case['units'][0], $content, array_replace($case['scope'], ['platform' => 'all_ota']))['retrieval_safe']);
    }

    public function testAllOtaRequiresAnExplicitSupportedPlatform(): void
    {
        $case = KnowledgeApplicabilityFixture::cases()[0]; $service = new KnowledgeApplicabilityService();
        $content = array_replace($case['chunks'][0]['content'], ['platforms' => ['all_ota']]);
        foreach (['', 'unknown_ota', 'qunar'] as $platform) {
            $result = $service->assess($case['units'][0], $content, array_replace($case['scope'], ['platform' => $platform]));
            self::assertFalse($result['retrieval_safe'], $platform);
            self::assertContains($platform === '' ? 'knowledge_platform_missing' : 'knowledge_platform_mismatch', $result['reason_codes']);
        }
        foreach (['ctrip', 'meituan', 'all_ota'] as $platform) {
            self::assertTrue($service->assess($case['units'][0], $content, array_replace($case['scope'], ['platform' => $platform]))['decision_safe'], $platform);
        }
        // Existing explicit non-all_ota declarations remain compatible.
        foreach (['qunar', 'dianping', 'pms', 'dingdandao'] as $platform) {
            $content['platforms'] = [$platform];
            self::assertTrue($service->assess($case['units'][0], $content, array_replace($case['scope'], ['platform' => $platform]))['decision_safe']);
            self::assertFalse($service->assess($case['units'][0], $content, array_replace($case['scope'], ['platform' => 'all_ota']))['retrieval_safe']);
        }
    }

    public function testLegacySourceMetadataStaysExplicitAndReferenceDeniesAllActions(): void
    {
        $case = KnowledgeApplicabilityFixture::cases()[0]; $content = $case['chunks'][0]['content'];
        unset($content['source_verification_status'], $content['valid_until']);
        $result = (new KnowledgeApplicabilityService())->assess($case['units'][0], $content, $case['scope']);
        self::assertContains('knowledge_source_verification_not_recorded', $result['warnings']);
        $result = (new KnowledgeDecisionGateService())->assess([], $content + ['reference_only' => true, 'decision_safe' => true, 'task_draft_safe' => true], '2026-09-08');
        self::assertTrue($result['retrieval_safe']); self::assertFalse($result['decision_safe']); self::assertFalse($result['task_draft_safe']);
    }

    public function testSamePolicyKeyOnDifferentChannelsIsNotAContradictionAndDetailsExposeRealConflicts(): void
    {
        $case = KnowledgeApplicabilityFixture::cases()[0];
        $first = $case['chunks'][0]; $first['content'] += ['conflict_key' => 'policy', 'claim_value' => 'A'];
        $second = $first; $second['chunk_id'] = 102; $second['content']['claim_value'] = 'B'; $second['content']['platforms'] = ['meituan'];
        $service = new KnowledgeApplicabilityService();
        $both = $service->assessRows($case['units'][0], [$first, $second], array_replace($case['scope'], ['platform' => 'all_ota']));
        self::assertSame([], $both['conflicts']);
        $second['content']['platforms'] = ['ctrip'];
        $conflict = $service->assessRows($case['units'][0], [$first, $second], $case['scope']);
        self::assertCount(1, $conflict['conflicts']);
        self::assertFalse($conflict['assessments'][101]['decision_safe']); self::assertFalse($conflict['assessments'][102]['retrieval_safe']);
        $second['content']['platforms'] = ['ctrip', 'meituan'];
        $overlapping = $service->assessRows($case['units'][0], [$first, $second], $case['scope']);
        self::assertCount(1, $overlapping['conflicts']);
        self::assertFalse($overlapping['assessments'][101]['decision_safe']);
    }

    public function testEffectivePlatformIntersectionDrivesCitationsAndConflictResolution(): void
    {
        $case = KnowledgeApplicabilityFixture::cases()[0]; $case['scope']['platform'] = 'all_ota';
        $first = $case['chunks'][0];
        $first['content'] = array_replace($first['content'], ['platforms' => ['all_ota'], 'applicability' => ['platforms' => ['ctrip']], 'conflict_key' => 'policy', 'claim_value' => 'A']);
        $second = $first; $second['chunk_id'] = 102;
        $second['content'] = array_replace($second['content'], ['platforms' => ['meituan'], 'applicability' => [], 'claim_value' => 'B']);
        $retrieval = (new OperatingQuestionKnowledgeRetrievalService())->buildFromRows($case['units'], [$first, $second], $case['scope']);
        self::assertSame([], $retrieval['conflicts']);
        self::assertSame([101, 102], array_column($retrieval['items'], 'chunk_id'));
        self::assertSame([['ctrip'], ['meituan']], array_column($retrieval['items'], 'platforms'));
        $detail = (new KnowledgeApplicabilityService())->assessRows($case['units'][0], [$first, $second], $case['scope']);
        self::assertSame([], $detail['conflicts']); self::assertTrue($detail['assessments'][101]['decision_safe']);
        $case['units'][0]['source'] = \app\service\RevenueOperationsKnowledgeService::SOURCE;
        $revenue = (new \app\service\RevenueOperationsKnowledgeService())->buildContextFromRows($case['units'], [$first, $second], $case['scope']);
        self::assertCount(2, $revenue['entries']);
        self::assertSame(['ctrip'], array_column($revenue['entries'], null, 'chunk_id')[101]['platforms']);
        self::assertSame(['meituan'], array_column($revenue['entries'], null, 'chunk_id')[102]['platforms']);
        // Narrowing does not hide a real same-platform contradiction.
        $second['content']['platforms'] = ['ctrip'];
        $conflict = (new OperatingQuestionKnowledgeRetrievalService())->buildFromRows($case['units'], [$first, $second], $case['scope']);
        self::assertSame([], $conflict['items']); self::assertCount(1, $conflict['conflicts']);
    }

    public function testRevenueConsumerRetainsCompatibleGateAndMinimalApplicabilityContract(): void
    {
        $case = KnowledgeApplicabilityFixture::cases()[0];
        $case['units'][0]['source'] = \app\service\RevenueOperationsKnowledgeService::SOURCE;
        $case['chunks'][0]['content']['reference_only'] = true;
        $result = (new \app\service\RevenueOperationsKnowledgeService())->buildContextFromRows($case['units'], $case['chunks'], $case['scope']);
        self::assertCount(1, $result['entries']);
        self::assertSame('knowledge_applicability.v1', $result['applicability_contract']);
        self::assertSame($result['entries'][0]['knowledge_gate'], $result['entries'][0]['applicability']);
        self::assertFalse($result['entries'][0]['applicability']['decision_safe']);
        self::assertFalse($result['fact_safe']); self::assertFalse($result['external_write_authorized']);
        unset($case['chunks'][0]['content']['reference_only']);
        $truncated = (new \app\service\RevenueOperationsKnowledgeService())->buildContextFromRows($case['units'], $case['chunks'], $case['scope'] + ['_chunk_fetch_truncated' => true]);
        self::assertFalse($truncated['entries'][0]['applicability']['decision_safe']);
        self::assertContains('knowledge_candidate_window_truncated', $truncated['entries'][0]['applicability']['reason_codes']);
        $case['chunks'][0]['content']['applicability'] = ['hotel_conditions' => ['store_stage' => ['mature']]];
        $blocked = (new \app\service\RevenueOperationsKnowledgeService())->buildContextFromRows($case['units'], $case['chunks'], $case['scope']);
        self::assertSame([], $blocked['entries']);
        self::assertContains('knowledge_condition_mismatch:store_stage', $blocked['applicability_exclusions'][0]['applicability']['reason_codes']);
    }

    public function testReferenceInjectionCannotBecomeAnAnswerFactOrTriggerAModelCall(): void
    {
        $case = KnowledgeApplicabilityFixture::cases()[28];
        $retrieval = (new OperatingQuestionKnowledgeRetrievalService())->buildFromRows($case['units'], $case['chunks'], $case['scope']);
        $scope = $case['scope'] + ['date_start' => '2026-09-08', 'date_end' => '2026-09-08'];
        $source = (new \app\service\OperatingQuestionUnifiedEvidenceService(static fn() => $retrieval))->collectSource('knowledge', $scope, $case['question']);
        self::assertCount(1, $source['items']); self::assertFalse($source['items'][0]['decision_safe']);
        $answer = (new \app\service\OperatingQuestionAiAnswerService())->generate([
            'question' => $case['question'], 'scope' => $scope,
            'answer' => ['status' => 'blocked_by_missing_facts', 'evidence_counts' => ['facts' => 0]],
            'evidence' => ['knowledge' => $retrieval['items']],
        ]);
        self::assertSame('missing_verified_facts', $answer['reason']);
        self::assertFalse($answer['llm_client_invoked']);
        self::assertFalse($answer['external_llm_called']);
    }
}
