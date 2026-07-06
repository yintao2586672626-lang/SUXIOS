<?php
declare(strict_types=1);

namespace Tests;

use app\service\CtripReviewOrderMatchService;
use app\service\OtaReviewRiskPolicyService;
use PHPUnit\Framework\TestCase;

final class CtripReviewOrderMatchServiceTest extends TestCase
{
    public function testIdentityMatchingIsBlockedByReviewRiskPolicy(): void
    {
        $service = new CtripReviewOrderMatchService();

        $result = $service->matchReviewIdentity([
            'commentId' => 'comment-1',
            'userName' => 'M519352****',
        ], [
            [
                'groupId' => 'group-1',
                'members' => [
                    md5('m5193520007') => [
                        'uid' => md5('m5193520007'),
                        'nickName' => 'Guest A',
                        'roleType' => 'guest',
                    ],
                ],
            ],
        ]);

        self::assertSame(OtaReviewRiskPolicyService::STATUS_BLOCKED, $result['status']);
        self::assertSame('risk_recognition_only', $result['allowed_learning_mode']);
        self::assertContains('identity_reverse_lookup', $result['risk_categories']);
        self::assertContains('anonymous_user_matching', $result['risk_categories']);
        self::assertContains('masked_data_reconstruction_risk', $result['risk_categories']);
        self::assertContains('identity_resolution', $result['blocked_outputs']);
        self::assertFalse($result['storage_write']);
        self::assertFalse($result['business_metric']);
        self::assertSame('docs/ctrip_review_governance_rules_20260705.md', $result['governance_policy_doc']);
        self::assertContains('privacy_not_queryable', $result['governance_status_codes']);
        self::assertArrayNotHasKey('identity', $result);
    }

    public function testReviewOrderMatchingIsBlockedByReviewRiskPolicy(): void
    {
        $service = new CtripReviewOrderMatchService();

        $result = $service->matchReviewToOrder([
            'commentId' => 'comment-1',
            'userName' => 'M519352****',
            'checkinTimeStr' => '2026-06-28',
            'hotelRoomInfo' => 'Room A',
        ], [], []);

        self::assertSame(OtaReviewRiskPolicyService::STATUS_BLOCKED, $result['status']);
        self::assertSame('ctrip_review_order_match_service', $result['operation']);
        self::assertContains('platform_appeal', $result['safe_redirect']);
        self::assertContains('service_recovery', $result['safe_redirect']);
        self::assertSame('docs/ota_all_channel_review_method_20260701.md', $result['policy_doc']);
    }

    public function testCtripReviewGovernanceRulesReturnVisibleValidationResults(): void
    {
        $policy = new OtaReviewRiskPolicyService();

        $result = $policy->blockedOperation('ctrip_review_reply_check', [
            'identity_reverse_lookup',
        ], [
            'platform' => 'ctrip',
            'review' => [
                'review_origin' => 'third_party',
                'source_platform' => 'qunar',
                'review_date' => '2026-07-05',
            ],
            'order' => [
                'departure_date' => '2026-03-01',
            ],
            'reference_date' => '2026-07-05',
            'reply_text' => 'Please contact us at service@example.com.',
        ]);

        self::assertSame('blocked', $result['governance_summary_status']);
        self::assertContains('expired_90d', $result['governance_status_codes']);
        self::assertContains('third_party_display_only', $result['governance_status_codes']);
        self::assertContains('privacy_not_queryable', $result['governance_status_codes']);
        self::assertContains('reply_contains_contact', $result['governance_status_codes']);

        $checksByCode = [];
        foreach ($result['governance_rule_checks'] as $check) {
            $checksByCode[$check['code']] = $check;
        }
        self::assertSame('active', $checksByCode['expired_90d']['status']);
        self::assertSame('2026-05-30', $checksByCode['expired_90d']['evidence']['window_deadline']);
        self::assertSame('active', $checksByCode['third_party_display_only']['status']);
        self::assertSame('active', $checksByCode['privacy_not_queryable']['status']);
        self::assertSame('active', $checksByCode['reply_contains_contact']['status']);
    }
}
