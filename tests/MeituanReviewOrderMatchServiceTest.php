<?php
declare(strict_types=1);

namespace Tests;

use app\service\MeituanReviewOrderMatchService;
use PHPUnit\Framework\TestCase;

final class MeituanReviewOrderMatchServiceTest extends TestCase
{
    public function testStrongMeituanEvidenceFindsOrderWithoutExposingRawPhone(): void
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
            'orderStatus' => 'checked_out',
            'platform' => 'meituan',
        ]]);

        self::assertSame('found', $result['status']);
        self::assertSame('high', $result['confidence']);
        self::assertSame('meituan_user_date_room_phone', $result['match_method']);
        self::assertSame('order-1', $result['order']['order_id']);
        self::assertSame('meituan_ota_channel', $result['scope']);

        self::assertSame('*******8000', $result['order']['phone']['phone_masked']);
        self::assertSame('8000', $result['order']['phone']['phone_last4']);
        self::assertIsArray($result['order']['phone']);
        self::assertArrayNotHasKey('raw_phone', $result['order']['phone']);
        self::assertArrayNotHasKey('full_phone', $result['order']['phone']);
        self::assertFalse($result['order']['phone']['business_metric']);
    }

    public function testDateRoomOnlyReturnsManualCandidateInsteadOfConfirmedMatch(): void
    {
        $service = new MeituanReviewOrderMatchService();

        $result = $service->matchReviewToOrder([
            'reviewId' => 'review-2',
            'hotelId' => 'poi-100',
            'checkInDate' => '2026-06-28',
            'roomName' => 'Deluxe Queen',
        ], [
            [
                'orderId' => 'order-1',
                'hotelId' => 'poi-100',
                'checkInDate' => '2026-06-28',
                'roomName' => 'Deluxe Queen with Breakfast',
                'platform' => 'meituan',
            ],
            [
                'orderId' => 'order-2',
                'hotelId' => 'poi-100',
                'checkInDate' => '2026-06-28',
                'roomName' => 'Deluxe Queen no Breakfast',
                'platform' => 'meituan',
            ],
        ]);

        self::assertSame('candidate_review_order', $result['status']);
        self::assertSame('lacks_strong_identity', $result['reason']);
        self::assertTrue($result['requires_manual_bind']);
        self::assertCount(2, $result['candidates']);
    }

    public function testPhoneStateKeepsAppSessionAndMetricBoundaryVisible(): void
    {
        $service = new MeituanReviewOrderMatchService();

        $ready = $service->buildPhoneHandlingState([
            'orderId' => 'order-1',
            'phone' => '*******8000',
        ], [
            'app_session_status' => 'ready',
        ]);

        self::assertSame('masked_only', $ready['phone_status']);
        self::assertSame('*******8000', $ready['phone_masked']);
        self::assertSame('8000', $ready['phone_last4']);
        self::assertSame('requires_separate_permission', $ready['reveal_policy']);
        self::assertTrue($ready['can_request_reveal']);
        self::assertFalse($ready['business_metric']);
        self::assertArrayNotHasKey('raw_phone', $ready);
        self::assertArrayNotHasKey('full_phone', $ready);

        $missing = $service->buildPhoneHandlingState([
            'orderId' => 'order-2',
        ], [
            'app_session_status' => 'not_configured',
        ]);

        self::assertSame('app_session_not_ready', $missing['phone_status']);
        self::assertSame('requires_authorized_app_session', $missing['next_action']);
        self::assertFalse($missing['can_request_reveal']);
        self::assertSame('meituan_ota_channel', $missing['scope']);
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
