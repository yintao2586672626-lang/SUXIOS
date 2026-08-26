<?php
declare(strict_types=1);

namespace Tests;

use app\service\MeituanReviewOrderMatchService;
use app\service\OtaReviewRiskPolicyService;
use PHPUnit\Framework\TestCase;

final class MeituanReviewOrderMatchServiceTest extends TestCase
{
    public function testVisibleOrderIdentifierCreatesConfirmedEvidenceWithoutIdentityResolution(): void
    {
        $service = new MeituanReviewOrderMatchService();

        $result = $service->matchReviewToOrder([
            'reviewId' => 'review-1',
            'meituanOrderId' => 'order-1',
            'mtUserId' => 'must-not-be-used',
            'phoneLast4' => '8000',
        ], [[
            'orderId' => 'order-1',
            'mtUserId' => 'must-not-be-used',
            'phone' => '*******8000',
            'checkInDate' => '2026-06-28',
            'roomName' => '豪华大床房',
            'platform' => 'meituan',
        ]]);

        self::assertSame('confirmed', $result['status']);
        self::assertSame('platform_review_order_link', $result['match_method']);
        self::assertSame('order-1', $result['order']['order_id']);
        self::assertSame('blocked_not_attempted', $result['identity_resolution']);
        self::assertFalse($result['phone_evidence_used']);
        self::assertFalse($result['storage_contains_guest_identity']);
        self::assertTrue($result['requires_manual_confirmation']);
        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::assertIsString($encoded);
        self::assertStringNotContainsString('must-not-be-used', $encoded);
        self::assertStringNotContainsString('8000', $encoded);
    }

    public function testExactStayDateRoomAndCompletedStatusCreateHighConfidenceCandidate(): void
    {
        $service = new MeituanReviewOrderMatchService();

        $result = $service->matchReviewToOrder([
            'reviewId' => 'review-2',
            'checkInDate' => '2026-06-28',
            'roomName' => '豪华大床房',
        ], [[
            'orderId' => 'order-2',
            'checkInDate' => '2026-06-28',
            'checkOutDate' => '2026-06-29',
            'roomName' => '豪华大床房',
            'orderStatus' => '已完成',
            'detailVerified' => true,
            'platform' => 'meituan',
        ]]);

        self::assertSame('high_confidence', $result['status']);
        self::assertSame('high_confidence_candidate_requires_manual_confirmation', $result['reason']);
        self::assertSame('order-2', $result['order']['order_id']);
        self::assertSame(100, $result['score']);
        self::assertSame(35, $result['score_breakdown']['room_score']);
        self::assertSame(35, $result['score_breakdown']['date_score']);
        self::assertSame(15, $result['score_breakdown']['status_score']);
        self::assertSame(10, $result['score_breakdown']['uniqueness_score']);
        self::assertTrue($result['requires_manual_confirmation']);
    }

    public function testCloseCandidatesRemainAmbiguousAndCannotAutoConfirm(): void
    {
        $service = new MeituanReviewOrderMatchService();

        $result = $service->matchReviewToOrder([
            'reviewId' => 'review-3',
            'checkInDate' => '2026-06-28',
            'roomName' => '豪华大床房',
        ], [
            [
                'orderId' => 'order-a',
                'checkInDate' => '2026-06-28',
                'roomName' => '豪华大床房',
                'orderStatus' => '已完成',
                'platform' => 'meituan',
            ],
            [
                'orderId' => 'order-b',
                'checkInDate' => '2026-06-28',
                'roomName' => '豪华大床房含早',
                'orderStatus' => '已完成',
                'platform' => 'meituan',
            ],
        ]);

        self::assertSame('ambiguous', $result['status']);
        self::assertSame(7, $result['score_gap']);
        self::assertNull($result['order']);
        self::assertContains('前两名候选分差小于20', $result['review_flags']);
        self::assertCount(2, $result['candidates']);
    }

    public function testCanceledOrderCreatesExplicitHardConflict(): void
    {
        $service = new MeituanReviewOrderMatchService();

        $result = $service->matchReviewToOrder([
            'reviewId' => 'review-4',
            'checkInDate' => '2026-06-28',
            'roomName' => '豪华大床房',
        ], [[
            'orderId' => 'order-4',
            'checkInDate' => '2026-06-28',
            'roomName' => '豪华大床房',
            'orderStatus' => '已取消',
            'platform' => 'meituan',
        ]]);

        self::assertSame('ambiguous', $result['status']);
        self::assertTrue($result['score_breakdown']['hard_conflict']);
        self::assertNull($result['order']);
        self::assertContains('候选存在日期或订单状态硬冲突', $result['review_flags']);
    }

    public function testMissingAuthorizedOrderPoolReturnsExplicitNotFound(): void
    {
        $service = new MeituanReviewOrderMatchService();
        $result = $service->matchReviewToOrder(['reviewId' => 'review-5'], []);

        self::assertSame('not_found', $result['status']);
        self::assertSame('meituan_order_pool_empty', $result['reason']);
        self::assertSame(['authorized_meituan_orders'], $result['missing_evidence']);
        self::assertSame([], $result['candidates']);
    }

    public function testSameOrderCannotBeHighConfidenceForMultipleReviews(): void
    {
        $service = new MeituanReviewOrderMatchService();
        $results = $service->buildReviewOrderMatches([
            ['reviewId' => 'review-a', 'checkInDate' => '2026-06-28', 'roomName' => '豪华大床房'],
            ['reviewId' => 'review-b', 'checkInDate' => '2026-06-28', 'roomName' => '豪华大床房'],
        ], [[
            'orderId' => 'order-shared',
            'checkInDate' => '2026-06-28',
            'roomName' => '豪华大床房',
            'orderStatus' => '已完成',
            'platform' => 'meituan',
        ]]);

        self::assertCount(2, $results);
        self::assertSame('ambiguous', $results[0]['status']);
        self::assertSame('ambiguous', $results[1]['status']);
        self::assertSame('same_order_is_top_candidate_for_multiple_reviews', $results[0]['reason']);
    }

    public function testPhoneStateRemainsBlockedByReviewRiskPolicy(): void
    {
        $service = new MeituanReviewOrderMatchService();

        $result = $service->buildPhoneHandlingState([
            'orderId' => 'order-1',
            'phone' => '*******8000',
        ], [
            'app_session_status' => 'ready',
        ]);

        self::assertSame(OtaReviewRiskPolicyService::STATUS_BLOCKED, $result['status']);
        self::assertSame('meituan_order_phone_state_service', $result['operation']);
        self::assertContains('phone_acquisition', $result['risk_categories']);
        self::assertContains('phone_reveal', $result['blocked_outputs']);
        self::assertArrayNotHasKey('phone_last4', $result);
        self::assertArrayNotHasKey('phone_masked', $result);
    }

    public function testOrderStoragePayloadDropsGuestPhoneFreeTextAndPlainOrderId(): void
    {
        $service = new MeituanReviewOrderMatchService();

        $sanitized = $service->sanitizeOrderForStorage([
            'orderId' => 'order-sensitive-1',
            'mtUserId' => 'user-sensitive-1',
            'guestName' => 'Alice Guest',
            'phone' => '*******8000',
            'customerRemark' => 'late arrival',
            'idCardNo' => 'sample-id-card-token',
            'roomName' => '豪华大床房',
            'checkInDate' => '2026-06-28',
            'amount' => 388.50,
        ]);

        $encoded = json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::assertIsString($encoded);
        self::assertStringNotContainsString('order-sensitive-1', $encoded);
        self::assertStringNotContainsString('user-sensitive-1', $encoded);
        self::assertStringNotContainsString('Alice Guest', $encoded);
        self::assertStringNotContainsString('8000', $encoded);
        self::assertStringNotContainsString('late arrival', $encoded);
        self::assertStringNotContainsString('sample-id-card-token', $encoded);
        self::assertArrayHasKey('order_id_hash', $sanitized);
        self::assertSame('豪华大床房', $sanitized['room_name']);
        self::assertSame(388.5, $sanitized['amount']);
    }

    public function testNormalizedReviewAndOrderStorageContainNoGuestIdentityOrPhoneEvidence(): void
    {
        $service = new MeituanReviewOrderMatchService();

        $review = $service->normalizeReviewForStorage([
            'reviewId' => 'review-safe-1',
            'mtUserId' => 'user-sensitive',
            'userName' => 'Sensitive Name',
            'content' => '正文中可能包含个人信息 13800008000',
            'checkInDate' => '2026-06-28',
            'roomName' => '豪华大床房',
            'score' => 4.8,
        ]);
        $order = $service->normalizeOrderForStorage([
            'orderId' => 'order-safe-1',
            'mtUserId' => 'user-sensitive',
            'guestName' => 'Sensitive Name',
            'phone' => '13800008000',
            'checkInDate' => '2026-06-28',
            'roomName' => '豪华大床房',
        ]);

        self::assertSame('', $review['meituan_user_id']);
        self::assertSame('', $review['source_username']);
        self::assertSame('', $review['content']);
        self::assertStringNotContainsString('Sensitive Name', $review['raw_review_json']);
        self::assertStringNotContainsString('13800008000', $review['raw_review_json']);
        self::assertSame('', $order['meituan_user_id']);
        self::assertSame('', $order['guest_name_masked']);
        self::assertSame('', $order['phone_masked']);
        self::assertSame('', $order['phone_last4']);
        self::assertSame(OtaReviewRiskPolicyService::STATUS_BLOCKED, $order['phone_status']);
        self::assertStringNotContainsString('Sensitive Name', $order['raw_order_json']);
        self::assertStringNotContainsString('13800008000', $order['raw_order_json']);
    }
}
