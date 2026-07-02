<?php
declare(strict_types=1);

namespace Tests;

use app\service\MeituanReviewOrderMatchService;
use app\service\OtaReviewRiskPolicyService;
use PHPUnit\Framework\TestCase;

final class MeituanReviewOrderMatchServiceTest extends TestCase
{
    public function testReviewOrderMatchingIsBlockedByReviewRiskPolicy(): void
    {
        $service = new MeituanReviewOrderMatchService();

        $result = $service->matchReviewToOrder([
            'reviewId' => 'review-1',
            'hotelId' => 'poi-100',
            'mtUserId' => 'user-100',
            'checkInDate' => '2026-06-28',
            'roomName' => 'Deluxe Queen',
            'phoneLast4' => '8000',
        ], [[
            'orderId' => 'order-1',
            'hotelId' => 'poi-100',
            'mtUserId' => 'user-100',
            'checkInDate' => '2026-06-28',
            'roomName' => 'Deluxe Queen with Breakfast',
            'phone' => '*******8000',
            'platform' => 'meituan',
        ]]);

        self::assertSame(OtaReviewRiskPolicyService::STATUS_BLOCKED, $result['status']);
        self::assertSame('meituan_review_order_match_service', $result['operation']);
        self::assertContains('identity_reverse_lookup', $result['risk_categories']);
        self::assertContains('phone_acquisition', $result['risk_categories']);
        self::assertContains('anonymous_user_matching', $result['risk_categories']);
        self::assertContains('phone_reveal', $result['blocked_outputs']);
        self::assertFalse($result['storage_write']);
        self::assertArrayNotHasKey('order', $result);
        self::assertArrayNotHasKey('candidates', $result);
    }

    public function testPhoneStateIsBlockedByReviewRiskPolicy(): void
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

    public function testOrderStoragePayloadRedactsGuestPhoneAndOrderText(): void
    {
        $service = new MeituanReviewOrderMatchService();

        $sanitized = $service->sanitizeOrderForStorage([
            'orderId' => 'order-1',
            'guestName' => 'Alice Guest',
            'phone' => '*******8000',
            'customerRemark' => 'late arrival',
            'idCardNo' => 'sample-id-card-token',
            'roomName' => 'Deluxe Queen',
        ]);

        $encoded = json_encode($sanitized, JSON_UNESCAPED_SLASHES);
        self::assertIsString($encoded);
        self::assertStringNotContainsString('order-1', $encoded);
        self::assertStringNotContainsString('Alice Guest', $encoded);
        self::assertStringNotContainsString('90000008000', $encoded);
        self::assertStringNotContainsString('late arrival', $encoded);
        self::assertStringNotContainsString('sample-id-card-token', $encoded);
        self::assertSame('*******8000', $sanitized['phone_masked']);
        self::assertArrayHasKey('order_id_hash', $sanitized);
    }
}
