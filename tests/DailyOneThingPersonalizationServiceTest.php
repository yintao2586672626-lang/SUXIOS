<?php
declare(strict_types=1);

namespace Tests;

use app\service\DailyOneThingPersonalizationService;
use app\service\DailyOneThingService;
use PHPUnit\Framework\TestCase;

final class DailyOneThingPersonalizationServiceTest extends TestCase
{
    public function testPersonalizationCannotOverrideAnyBaseBusinessDimension(): void
    {
        $service = $this->service('meituan');
        $result = $service->select([
            $this->candidate('signal:a:ctrip', 'ctrip', [90, 80, 80, 20]),
            $this->candidate('signal:z:meituan', 'meituan', [89, 100, 100, 1]),
        ], '2026-08-29', 7, 11, 80);

        self::assertSame('signal:a:ctrip', $result['selected']['candidate_key']);
        self::assertSame('not_applied', $result['personalization_receipt']['status']);
        self::assertContains('no_base_rank_tie', $result['personalization_receipt']['not_applied_reasons']);
        self::assertFalse($result['personalization_receipt']['facts_changed']);
        self::assertFalse($result['personalization_receipt']['eligibility_changed']);
    }

    public function testTwoUsersCanReceiveDifferentPreviewWithinTheSameExactBaseTie(): void
    {
        $scores = [80, 80, 90, 20];
        $candidates = [
            $this->candidate('signal:a:ctrip', 'ctrip', $scores),
            $this->candidate('signal:z:meituan', 'meituan', $scores),
        ];
        $ctrip = $this->service('ctrip')->select($candidates, '2026-08-29', 7, 11, 80);
        $meituan = $this->service('meituan')->select($candidates, '2026-08-29', 7, 12, 80);

        self::assertSame('signal:a:ctrip', $ctrip['selected']['candidate_key']);
        self::assertSame('signal:z:meituan', $meituan['selected']['candidate_key']);
        self::assertSame('applied', $ctrip['personalization_receipt']['status']);
        self::assertSame('applied', $meituan['personalization_receipt']['status']);
        self::assertSame(11, $ctrip['personalization_receipt']['scope']['user_id']);
        self::assertSame(12, $meituan['personalization_receipt']['scope']['user_id']);
        self::assertSame(2, $ctrip['personalization_receipt']['base_tie_group_size']);
        self::assertStringContainsString(
            '已确认的平台偏好',
            $ctrip['personalization_receipt']['why_you']['summary']
        );
        self::assertStringNotContainsString(
            '历史反馈',
            $ctrip['personalization_receipt']['why_you']['summary']
        );
        self::assertSame(
            $ctrip['selection_policy']['order'],
            $ctrip['selection_policy']['base_order']
        );
        self::assertStringContainsString(
            'explicit_confirmed_preference_desc_then_feedback_adjustment_desc',
            $ctrip['selection_policy']['effective_order']
        );
        self::assertTrue($meituan['personalization_receipt']['selection_changed']);
        self::assertFalse($ctrip['personalization_receipt']['permissions_changed']);
        self::assertFalse($ctrip['personalization_receipt']['approval_changed']);
        self::assertFalse($ctrip['personalization_receipt']['external_write_authorized']);
        self::assertTrue(
            $ctrip['selected']['recommendation_explanation']['personalization_receipt_authoritative']
        );
        self::assertArrayNotHasKey(
            'personalization',
            $ctrip['selected']['recommendation_explanation']
        );
        self::assertSame(
            'highest_base_rank_personalized_receipt_tie_break',
            $ctrip['selected']['recommendation_explanation']['why_recommended']['reason_code']
        );
        self::assertSame(
            $ctrip['selected']['content_digest'],
            DailyOneThingService::digest($ctrip['selected'])
        );
        self::assertArrayNotHasKey('candidates', $ctrip);
    }

    public function testEligibleFeedbackCanBreakOnlyTheExactBaseTie(): void
    {
        $scores = [80, 80, 90, 20];
        $ctripCandidate = $this->candidate('signal:z:ctrip', 'ctrip', $scores);
        $meituanCandidate = $this->candidate('signal:a:meituan', 'meituan', $scores);
        $feedback = $this->feedbackContext([
            DailyOneThingPersonalizationService::featureIdentity($ctripCandidate) => 1,
            DailyOneThingPersonalizationService::featureIdentity($meituanCandidate) => -1,
        ]);
        $service = $this->service('', $feedback);
        $result = $service->select(
            [$meituanCandidate, $ctripCandidate],
            '2026-08-29',
            7,
            11,
            80
        );

        self::assertSame('signal:z:ctrip', $result['selected']['candidate_key']);
        self::assertTrue($result['personalization_receipt']['selection_changed']);
        self::assertSame('feedback', $result['personalization_receipt']['applied_adjustments'][0]['kind']);
        self::assertSame(20, $result['personalization_receipt']['applied_adjustments'][0]['sample_count']);
        self::assertStringContainsString(
            '历史反馈',
            $result['personalization_receipt']['why_you']['summary']
        );
        self::assertStringNotContainsString(
            '已确认的平台偏好',
            $result['personalization_receipt']['why_you']['summary']
        );
    }

    public function testExplicitPreferencePrecedesContradictoryFeedback(): void
    {
        $scores = [80, 80, 90, 20];
        $ctripCandidate = $this->candidate('signal:a:ctrip', 'ctrip', $scores);
        $meituanCandidate = $this->candidate('signal:z:meituan', 'meituan', $scores);
        $feedback = $this->feedbackContext([
            DailyOneThingPersonalizationService::featureIdentity($ctripCandidate) => 1,
            DailyOneThingPersonalizationService::featureIdentity($meituanCandidate) => -1,
        ]);
        $result = $this->service('meituan', $feedback)->select(
            [$ctripCandidate, $meituanCandidate],
            '2026-08-29',
            7,
            11,
            80
        );

        self::assertSame('signal:z:meituan', $result['selected']['candidate_key']);
        self::assertSame(
            'explicit_confirmed_preference',
            $result['personalization_receipt']['applied_adjustments'][0]['kind']
        );
        self::assertCount(1, $result['personalization_receipt']['applied_adjustments']);
        self::assertContains(
            'feedback_conflicts_with_explicit_preference',
            $result['personalization_receipt']['not_applied_reasons']
        );
    }

    public function testNegativeFeedbackDemotionIsReportedEvenWhenWinnerHasZeroAdjustment(): void
    {
        $scores = [80, 80, 90, 20];
        $ctrip = $this->candidate('signal:a:ctrip', 'ctrip', $scores);
        $meituan = $this->candidate('signal:z:meituan', 'meituan', $scores);
        $feedback = $this->feedbackContext([
            DailyOneThingPersonalizationService::featureIdentity($ctrip) => -1,
        ]);
        $result = $this->service('', $feedback)->select(
            [$ctrip, $meituan],
            '2026-08-29',
            7,
            11,
            80
        );

        self::assertSame('signal:z:meituan', $result['selected']['candidate_key']);
        self::assertTrue($result['personalization_receipt']['selection_changed']);
        self::assertSame('feedback', $result['personalization_receipt']['applied_adjustments'][0]['kind']);
        self::assertSame(0, $result['personalization_receipt']['applied_adjustments'][0]['adjustment']);
        self::assertSame(20, $result['personalization_receipt']['applied_adjustments'][0]['sample_count']);
        self::assertNotEmpty($result['personalization_receipt']['applied_adjustments'][0]['source_refs']);
    }

    public function testUnconfirmedPreferenceAndInsufficientFeedbackPreserveDefault(): void
    {
        $scores = [80, 80, 90, 20];
        $preferenceLoader = static fn(): array => [
            'status' => 'ready',
            'tenant_id' => 7,
            'user_id' => 11,
            'hotel_id' => 80,
            'items' => [[
                'id' => 91,
                'preference_key' => 'preferred_platform',
                'value' => 'meituan',
                'learning_status' => 'inferred',
                'lifecycle_status' => 'active',
                'consumable' => false,
            ]],
        ];
        $feedbackLoader = static fn(): array => [
            'contract_version' => 'daily_one_thing_feedback_adjustments.v1',
            'status' => 'insufficient_samples',
            'scope' => ['tenant_id' => 7, 'user_id' => 11, 'hotel_id' => 80],
            'items' => [],
        ];
        $service = new DailyOneThingPersonalizationService(
            preferenceLoader: $preferenceLoader,
            feedbackLoader: $feedbackLoader
        );
        $result = $service->select([
            $this->candidate('signal:a:ctrip', 'ctrip', $scores),
            $this->candidate('signal:z:meituan', 'meituan', $scores),
        ], '2026-08-29', 7, 11, 80);

        self::assertSame('signal:a:ctrip', $result['selected']['candidate_key']);
        self::assertSame('not_applied', $result['personalization_receipt']['status']);
        self::assertTrue($result['personalization_receipt']['candidate_preferences_consumed'] === false);
        self::assertContains(
            'no_explicit_confirmed_platform_preference',
            $result['personalization_receipt']['not_applied_reasons']
        );
        self::assertContains(
            'feedback_insufficient_samples',
            $result['personalization_receipt']['not_applied_reasons']
        );
    }

    public function testAllOtaPreferenceIsRecognizedAsNeutralInsteadOfMissing(): void
    {
        $scores = [80, 80, 90, 20];
        $result = $this->service('all_ota')->select([
            $this->candidate('signal:a:ctrip', 'ctrip', $scores),
            $this->candidate('signal:z:meituan', 'meituan', $scores),
        ], '2026-08-29', 7, 11, 80);

        self::assertSame('signal:a:ctrip', $result['selected']['candidate_key']);
        self::assertSame('not_applied', $result['personalization_receipt']['status']);
        self::assertNotEmpty($result['personalization_receipt']['preference_refs']);
        self::assertContains(
            'preferred_platform_all_ota_is_neutral',
            $result['personalization_receipt']['not_applied_reasons']
        );
        self::assertNotContains(
            'no_explicit_confirmed_platform_preference',
            $result['personalization_receipt']['not_applied_reasons']
        );
    }

    public function testInsufficientFeedbackExplainsExactUniqueBusinessDateProgress(): void
    {
        $scores = [80, 80, 90, 20];
        $ctrip = $this->candidate('signal:a:ctrip', 'ctrip', $scores);
        $meituan = $this->candidate('signal:z:meituan', 'meituan', $scores);
        $ctripIdentity = DailyOneThingPersonalizationService::featureIdentity($ctrip);
        $feedback = [
            'contract_version' => 'daily_one_thing_feedback_adjustments.v1',
            'status' => 'insufficient_samples',
            'scope' => ['tenant_id' => 7, 'user_id' => 11, 'hotel_id' => 80],
            'items' => [[
                'feature_identity' => $ctripIdentity,
                'status' => 'insufficient_samples',
                'eligible' => false,
                'adjustment' => 0,
                'sample_count' => 7,
                'minimum_samples' => 20,
                'unique_business_date_count' => 7,
                'duplicate_sample_count' => 3,
                'sample_digest' => hash('sha256', 'seven-unique-days'),
                'source_refs' => [],
            ]],
        ];

        $result = $this->service('', $feedback)->select(
            [$ctrip, $meituan],
            '2026-08-29',
            7,
            11,
            80
        );

        self::assertSame('not_applied', $result['personalization_receipt']['status']);
        self::assertStringContainsString('7/20', $result['personalization_receipt']['why_you']['summary']);
        self::assertSame(7, $result['personalization_receipt']['feedback_progress'][0]['unique_business_date_count']);
        self::assertSame(3, $result['personalization_receipt']['feedback_progress'][0]['duplicate_sample_count']);
    }

    public function testExplanationChangesContentButNotMaterialIdentity(): void
    {
        $scores = [80, 80, 90, 20];
        $candidate = $this->candidate('signal:a:ctrip', 'ctrip', $scores);
        $base = (new DailyOneThingService())->select([$candidate], '2026-08-29');
        $personalized = $this->service('ctrip')->select(
            [$candidate],
            '2026-08-29',
            7,
            11,
            80
        );

        self::assertSame(
            $base['selected']['material_identity_digest'],
            $personalized['selected']['material_identity_digest']
        );
        self::assertSame(
            $base['selected']['content_digest'],
            $personalized['selected']['content_digest']
        );
        self::assertSame('no_base_rank_tie', $personalized['personalization_receipt']['not_applied_reasons'][0]);
    }

    private function service(string $preferredPlatform = '', ?array $feedback = null): DailyOneThingPersonalizationService
    {
        $preferenceLoader = static fn(int $tenantId, int $userId, int $hotelId): array => [
            'status' => 'ready',
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'hotel_id' => $hotelId,
            'items' => $preferredPlatform === '' ? [] : [[
                'id' => $userId + 100,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'hotel_id' => $hotelId,
                'scope' => 'hotel',
                'preference_key' => 'preferred_platform',
                'value' => $preferredPlatform,
                'value_hash' => hash('sha256', json_encode($preferredPlatform)),
                'learning_status' => 'explicit_confirmed',
                'lifecycle_status' => 'active',
                'consumable' => true,
            ]],
        ];
        $feedbackLoader = static fn(): array => $feedback ?? [
            'contract_version' => 'daily_one_thing_feedback_adjustments.v1',
            'status' => 'empty',
            'scope' => ['tenant_id' => 7, 'user_id' => 11, 'hotel_id' => 80],
            'items' => [],
        ];
        return new DailyOneThingPersonalizationService(
            preferenceLoader: $preferenceLoader,
            feedbackLoader: $feedbackLoader
        );
    }

    /** @param array<string,int> $adjustments @return array<string,mixed> */
    private function feedbackContext(array $adjustments): array
    {
        return [
            'contract_version' => 'daily_one_thing_feedback_adjustments.v1',
            'status' => 'ready',
            'scope' => ['tenant_id' => 7, 'user_id' => 11, 'hotel_id' => 80],
            'items' => array_map(
                static fn(string $identity, int $adjustment): array => [
                    'feature_identity' => $identity,
                    'status' => 'ready',
                    'eligible' => true,
                    'adjustment' => $adjustment,
                    'sample_count' => 20,
                    'minimum_samples' => 20,
                    'sample_digest' => hash('sha256', $identity . ':' . $adjustment),
                    'source_refs' => ['ai_suggestion_calibration_feedback_events#' . substr($identity, 0, 6)],
                ],
                array_keys($adjustments),
                array_values($adjustments)
            ),
        ];
    }

    /** @param array{0:int,1:int,2:int,3:int} $scores @return array<string,mixed> */
    private function candidate(string $key, string $platform, array $scores): array
    {
        return [
            'candidate_key' => $key,
            'source_type' => 'strict_fact_signal',
            'problem' => $platform . ' 当前经营事实需要优先核对',
            'fact_basis' => [[
                'statement' => '同酒店同平台同日期事实已精确回读。',
                'evidence_ref' => 'online_daily_data#101',
                'quality_status' => 'strict_readback',
            ]],
            'recommended_action' => [
                'type' => 'human_reviewed_operating_check',
                'object' => $platform . '_fact_scope',
                'title' => '只读核对一项事实',
                'description' => '先核对事实，再由用户决定是否执行后续动作。',
                'steps' => ['打开同范围页面只读核对。', '把真实证据绑定原任务。'],
            ],
            'expected_observation_metric' => [
                'key' => 'detail_exposure',
                'label' => '详情曝光',
                'unit' => 'exposure_count',
                'baseline_value' => 10,
                'aggregation' => 'latest',
            ],
            'scope' => [
                'tenant_id' => 7,
                'hotel_id' => 80,
                'platform' => $platform,
                'business_date' => '2026-08-29',
                'metric_scope' => 'ota_channel',
                'scope_note' => '只属于当前平台当前酒店当前日期，不扩大为全酒店结论。',
            ],
            'risk' => [
                'level' => 'low',
                'summary' => '风险是误用不同范围事实。',
                'controls' => ['只读核对，不自动写平台。'],
                'stop_conditions' => ['身份或日期不一致时停止。'],
            ],
            'responsibility' => [
                'owner_id' => 1,
                'owner_label' => '当前确认人',
                'due_at' => '2026-08-29 23:00:00',
                'review_at' => '2026-08-30 10:00:00',
            ],
            'ranking' => [
                'impact' => $scores[0],
                'urgency' => $scores[1],
                'evidence_strength' => $scores[2],
                'execution_cost' => $scores[3],
                'reasons' => [],
            ],
            'source' => [
                'record_id' => 101,
                'record_ref' => 'online_daily_data#101',
                'snapshot_digest' => str_repeat('a', 64),
                'fact_refs' => ['online_daily_data#101'],
                'gap_codes' => [],
            ],
            'external_write_boundary' => [
                'automatic_ctrip_write' => false,
                'automatic_meituan_write' => false,
                'automatic_pms_write' => false,
                'automatic_wecom_message' => false,
                'automatic_execution' => false,
                'human_confirmation_required' => true,
                'causality_claimed' => false,
            ],
        ];
    }
}
