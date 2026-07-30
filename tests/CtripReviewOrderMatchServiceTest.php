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

    public function testReviewOrderMatchingUsesDirectAuthorizedOrderLinkWithoutIdentityResolution(): void
    {
        $service = new CtripReviewOrderMatchService();

        $result = $service->matchReviewToOrder([
            'commentId' => 'comment-1',
            'userName' => 'M519352****',
            'orderId' => 'order-1',
            'checkinTimeStr' => '2026-06-28',
            'hotelRoomInfo' => 'Room A',
        ], [], [[
            'orderId' => 'order-1',
            'guestName' => 'Must Not Leak',
            'arrivalDate' => '2026-06-28',
            'departureDate' => '2026-06-29',
            'roomName' => 'Room A',
            'orderStatus' => '已离店',
            'platform' => 'ctrip',
        ]]);

        self::assertSame('confirmed', $result['status']);
        self::assertSame('confirmed', $result['confidence']);
        self::assertSame('platform_review_order_link', $result['match_method']);
        self::assertSame('order-1', $result['order']['order_id']);
        self::assertSame('blocked_not_attempted', $result['identity_resolution']);
        self::assertArrayNotHasKey('guest_name', $result['order']);
        self::assertArrayNotHasKey('identity', $result);
    }

    public function testReviewOrderMatchingUsesUniqueStayDateAndRoomEvidence(): void
    {
        $service = new CtripReviewOrderMatchService();

        $result = $service->matchReviewToOrder([
            'commentId' => 'comment-2',
            'checkinTimeStr' => '2026-06-28',
            'hotelRoomInfo' => '豪华大床房',
            'publishTime' => '2026-06-30 10:00:00',
        ], [], [[
            'orderId' => 'order-2',
            'arrivalDate' => '2026-06-28',
            'departureDate' => '2026-06-29',
            'roomName' => '豪华大床房（含早）',
            'orderStatus' => '已离店',
            'platform' => 'ctrip',
        ], [
            'orderId' => 'order-other-date',
            'arrivalDate' => '2026-06-27',
            'departureDate' => '2026-06-28',
            'roomName' => '豪华大床房（含早）',
            'orderStatus' => '已离店',
            'platform' => 'ctrip',
        ]]);

        self::assertSame('candidate', $result['status']);
        self::assertSame('candidate', $result['confidence']);
        self::assertSame('candidate_evidence_insufficient', $result['reason']);
        self::assertNull($result['order']);
        self::assertSame('order-2', $result['candidates'][0]['order_id']);
        self::assertSame('explicit_stay_dates', $result['window_used']);
        self::assertContains('未完成订单详情复核，不能高置信', $result['missing_evidence']);
    }

    public function testReviewOrderMatchingReturnsExplicitMissingEvidence(): void
    {
        $service = new CtripReviewOrderMatchService();

        $result = $service->matchReviewToOrder([
            'commentId' => 'comment-3',
            'hotelRoomInfo' => 'Room A',
        ], [], []);

        self::assertSame('not_found', $result['status']);
        self::assertSame('ctrip_order_pool_empty', $result['reason']);
        self::assertSame(['authorized_ctrip_orders'], $result['missing_evidence']);
        self::assertSame('blocked_not_attempted', $result['identity_resolution']);
    }

    public function testReviewOrderMatchingUsesExplicitImGroupOrderLink(): void
    {
        $service = new CtripReviewOrderMatchService();

        $result = $service->matchReviewToOrder([
            'commentId' => 'comment-4',
            'groupId' => 'group-4',
        ], [[
            'groupId' => 'group-4',
            'orderId' => 'order-4',
            'members' => [['uid' => 'ignored', 'nickName' => 'Ignored']],
        ]], [[
            'orderId' => 'order-4',
            'arrivalDate' => '2026-07-01',
            'departureDate' => '2026-07-02',
            'roomName' => 'Room B',
            'orderStatus' => '已离店',
            'platform' => 'ctrip',
        ]]);

        self::assertSame('confirmed', $result['status']);
        self::assertSame('platform_im_group_order_link', $result['match_method']);
        self::assertSame('order-4', $result['order']['order_id']);
        self::assertArrayNotHasKey('identity', $result);
    }

    public function testHighConfidenceWorksWithAuthorizedCtripOrderWithoutPmsDependency(): void
    {
        $service = new CtripReviewOrderMatchService();

        $result = $service->matchReviewToOrder([
            'commentId' => 'comment-high',
            'publishTime' => '2026-07-10 16:00:00',
            'hotelRoomInfo' => '景观大床房（270度景观）',
            'content' => '一个人散心，大床很舒服',
        ], [], [[
            'orderId' => 'ctrip-high',
            'departureDate' => '2026-07-08',
            'arrivalDate' => '2026-07-06',
            'roomName' => '景观大床房',
            'orderStatus' => '已退房',
            'amount' => 688,
            'detailVerified' => true,
            'platform' => 'ctrip',
        ]], [
            'store_mapping_verified' => true,
        ]);

        self::assertSame('high_confidence', $result['status']);
        self::assertSame('ctrip-high', $result['order']['order_id']);
        self::assertGreaterThanOrEqual(75, $result['score']);
        self::assertSame(30, $result['score_breakdown']['room_score']);
        self::assertSame('checkout_0_14_days_before_review', $result['window_used']);
        self::assertSame('blocked_not_attempted', $result['identity_resolution']);
        self::assertArrayNotHasKey('guest_name', $result['order']);
    }

    public function testCancelledZeroAmountOrderIsRetainedAsLowConfidenceCandidate(): void
    {
        $service = new CtripReviewOrderMatchService();

        $result = $service->matchReviewToOrder([
            'commentId' => 'comment-cancelled',
            'publishTime' => '2026-07-10 16:00:00',
            'hotelRoomInfo' => '庭院亲子房',
            'content' => '带孩子住的亲子房',
        ], [], [[
            'orderId' => 'ctrip-cancelled',
            'departureDate' => '2026-07-09',
            'arrivalDate' => '2026-07-08',
            'roomName' => '庭院亲子间',
            'orderStatus' => '已取消',
            'amount' => 0,
            'detailVerified' => true,
            'platform' => 'ctrip',
        ]], [
            'store_mapping_verified' => true,
            'room_mapping' => ['庭院亲子房' => ['庭院亲子间']],
        ]);

        self::assertSame('candidate', $result['status']);
        self::assertCount(1, $result['candidates']);
        self::assertSame('ctrip-cancelled', $result['candidates'][0]['order_id']);
        self::assertSame(0, $result['candidates'][0]['score_breakdown']['status_score']);
        self::assertSame(0, $result['candidates'][0]['score_breakdown']['amount_score']);
        self::assertContains('0元订单保留为低分候选，不能高置信', $result['missing_evidence']);
    }

    public function testPublishBeforeCheckoutFourteenHundredIsAmbiguousHardConflict(): void
    {
        $service = new CtripReviewOrderMatchService();

        $result = $service->matchReviewToOrder([
            'commentId' => 'comment-time-conflict',
            'publishTime' => '2026-07-10 10:00:00',
            'hotelRoomInfo' => '景观大床房',
        ], [], [[
            'orderId' => 'ctrip-time-conflict',
            'departureDate' => '2026-07-10',
            'arrivalDate' => '2026-07-09',
            'roomName' => '景观大床房',
            'orderStatus' => '已退房',
            'amount' => 500,
            'detailVerified' => true,
            'platform' => 'ctrip',
        ]], ['store_mapping_verified' => true]);

        self::assertSame('ambiguous', $result['status']);
        self::assertSame(-30, $result['score_breakdown']['date_score']);
        self::assertTrue($result['score_breakdown']['time_logic_conflict']);
        self::assertContains('点评时间与离店时间硬冲突', $result['review_flags']);
    }

    public function testNotFoundRequiresAllConfiguredWindowsToBeExhausted(): void
    {
        $service = new CtripReviewOrderMatchService();

        $result = $service->matchReviewToOrder([
            'commentId' => 'comment-old-order',
            'publishTime' => '2026-07-18 16:00:00',
            'checkinTimeStr' => '2026-07',
            'hotelRoomInfo' => '景观大床房',
        ], [], [[
            'orderId' => 'ctrip-too-old',
            'departureDate' => '2025-01-03',
            'arrivalDate' => '2025-01-01',
            'roomName' => '景观大床房',
            'orderStatus' => '已退房',
            'platform' => 'ctrip',
        ]]);

        self::assertSame('not_found', $result['status']);
        self::assertSame('no_candidate_after_all_windows', $result['reason']);
        self::assertSame('none', $result['window_used']);
        self::assertSame([
            'checkout_0_14_days_before_review',
            'checkout_15_30_days_before_review',
            'stay_month',
        ], array_column($result['search_windows'], 'window'));
    }

    public function testBatchMatchingDowngradesDuplicateTopOrderAcrossReviews(): void
    {
        $service = new CtripReviewOrderMatchService();
        $reviews = [[
            'commentId' => 'duplicate-1',
            'publishTime' => '2026-07-10 16:00:00',
            'hotelRoomInfo' => '景观大床房',
        ], [
            'commentId' => 'duplicate-2',
            'publishTime' => '2026-07-11 16:00:00',
            'hotelRoomInfo' => '景观大床房',
        ]];
        $orders = [[
            'orderId' => 'ctrip-duplicate',
            'departureDate' => '2026-07-09',
            'arrivalDate' => '2026-07-08',
            'roomName' => '景观大床房',
            'orderStatus' => '已退房',
            'amount' => 688,
            'detailVerified' => true,
            'platform' => 'ctrip',
        ]];

        $results = $service->buildReviewOrderMatches($reviews, [], $orders, ['store_mapping_verified' => true]);

        self::assertCount(2, $results);
        self::assertSame('ambiguous', $results[0]['status']);
        self::assertSame('ambiguous', $results[1]['status']);
        self::assertSame(-15, $results[0]['score_breakdown']['duplicate_penalty']);
        self::assertContains('同一订单命中多条点评', $results[0]['review_flags']);
    }

    public function testNonCtripOrdersNeverEnterCandidatePool(): void
    {
        $service = new CtripReviewOrderMatchService();
        $result = $service->matchReviewToOrder([
            'commentId' => 'comment-meituan',
            'publishTime' => '2026-07-10 16:00:00',
            'hotelRoomInfo' => '景观大床房',
        ], [], [[
            'orderId' => 'meituan-order',
            'departureDate' => '2026-07-09',
            'roomName' => '景观大床房',
            'orderStatus' => '已退房',
            'platform' => 'meituan',
        ]]);

        self::assertSame('not_found', $result['status']);
        self::assertSame('ctrip_order_pool_empty', $result['reason']);
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
