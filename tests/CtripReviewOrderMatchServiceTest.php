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
}
