<?php
declare(strict_types=1);

namespace Tests;

use app\controller\ota\CtripController;
use app\controller\ota\MeituanController;
use app\model\User;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Db;
use think\Request;
use think\Response;

final class ReviewOrderEvidencePersistenceIntegrationTest extends TestCase
{
    private static App $app;
    private ?Request $originalRequest = null;
    private ?User $actor = null;
    private int $hotelId = 0;
    private string $token = '';

    public static function setUpBeforeClass(): void
    {
        self::$app = new App(dirname(__DIR__));
        self::$app->initialize();
    }

    protected function setUp(): void
    {
        $this->originalRequest = self::$app->request;
        $this->actor = $this->superAdmin();
        $hotel = Db::name('hotels')
            ->where('status', 1)
            ->where('tenant_id', '>', 0)
            ->order('id', 'asc')
            ->field('id')
            ->find();
        if (!$this->actor instanceof User || !is_array($hotel)) {
            self::markTestSkipped('An enabled super-admin and hotel are required for review-order persistence integration.');
        }
        foreach ([
            'ota_ctrip_reviews',
            'ota_ctrip_orders',
            'ota_ctrip_review_order_matches',
            'ota_meituan_reviews',
            'ota_meituan_orders',
            'ota_meituan_review_order_matches',
        ] as $table) {
            try {
                Db::name($table)->limit(1)->count();
            } catch (\Throwable) {
                self::markTestSkipped("{$table} is required for review-order persistence integration.");
            }
        }
        $this->hotelId = (int)$hotel['id'];
        $this->token = 'codex-e2e-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if ($this->originalRequest instanceof Request) {
            self::$app->instance('request', $this->originalRequest);
        }
    }

    public function testDualOtaEvidenceDecisionsAreSavedReadBackAndRolledBack(): void
    {
        $mtReviewA = $this->token . '-mt-review-a';
        $mtReviewB = $this->token . '-mt-review-b';
        $mtOrder = $this->token . '-mt-order';
        $ctReviewA = $this->token . '-ct-review-a';
        $ctReviewB = $this->token . '-ct-review-b';
        $ctOrder = $this->token . '-ct-order';
        $sensitiveMarker = 'sensitive-' . $this->token;

        Db::startTrans();
        try {
            $mtReviewSave = $this->call(MeituanController::class, 'saveMeituanReviewForMatch', [
                'system_hotel_id' => $this->hotelId,
                'review' => [
                    'reviewId' => $mtReviewA,
                    'meituanOrderId' => $mtOrder,
                    'reviewDate' => '2099-01-03',
                    'checkInDate' => '2099-01-01',
                    'roomName' => '证据回读大床房',
                    'score' => 4.8,
                    'userName' => $sensitiveMarker,
                    'content' => $sensitiveMarker,
                ],
            ]);
            self::assertSame('saved_and_readback_verified', $mtReviewSave['save_status']);
            self::assertFalse((bool)($mtReviewSave['readback']['identity_fields_stored'] ?? true));

            $mtOrderSave = $this->call(MeituanController::class, 'saveMeituanOrderForMatch', [
                'system_hotel_id' => $this->hotelId,
                'order' => [
                    'orderId' => $mtOrder,
                    'checkInDate' => '2099-01-01',
                    'checkOutDate' => '2099-01-02',
                    'roomName' => '证据回读大床房',
                    'orderStatus' => '已完成',
                    'detailVerified' => true,
                    'guestName' => $sensitiveMarker,
                    'phone' => '13800008000',
                ],
            ]);
            self::assertSame('saved_and_readback_verified', $mtOrderSave['save_status']);

            $mtStoredReview = Db::name('ota_meituan_reviews')
                ->where('system_hotel_id', $this->hotelId)
                ->where('review_id', $mtReviewA)
                ->find();
            $mtStoredOrder = Db::name('ota_meituan_orders')
                ->where('system_hotel_id', $this->hotelId)
                ->where('order_id', $mtOrder)
                ->find();
            self::assertIsArray($mtStoredReview);
            self::assertIsArray($mtStoredOrder);
            self::assertSame('', (string)$mtStoredReview['source_username']);
            self::assertSame('', (string)$mtStoredReview['content']);
            self::assertSame('', (string)$mtStoredOrder['guest_name_masked']);
            self::assertSame('', (string)$mtStoredOrder['phone_last4']);
            self::assertStringNotContainsString($sensitiveMarker, (string)$mtStoredReview['raw_review_json']);
            self::assertStringNotContainsString($sensitiveMarker, (string)$mtStoredOrder['raw_order_json']);
            self::assertStringNotContainsString('13800008000', (string)$mtStoredOrder['raw_order_json']);

            $mtLookup = $this->call(MeituanController::class, 'lookupMeituanReviewOrderMatch', [
                'system_hotel_id' => $this->hotelId,
                'reviewId' => $mtReviewA,
            ]);
            self::assertSame('confirmed', $mtLookup['status']);
            self::assertSame('saved_and_readback_verified', $mtLookup['save_status']);
            self::assertSame($mtReviewA, $mtLookup['attempt_readback']['review_id']);

            $mtBind = $this->call(MeituanController::class, 'bindMeituanReviewOrderMatch', [
                'system_hotel_id' => $this->hotelId,
                'reviewId' => $mtReviewA,
                'orderId' => $mtOrder,
            ]);
            self::assertSame('matched', $mtBind['match_status']);
            self::assertSame('saved_and_readback_verified', $mtBind['save_status']);

            $this->call(MeituanController::class, 'saveMeituanReviewForMatch', [
                'system_hotel_id' => $this->hotelId,
                'review' => [
                    'reviewId' => $mtReviewB,
                    'reviewDate' => '2099-01-04',
                    'checkInDate' => '2099-01-01',
                    'roomName' => '证据回读大床房',
                ],
            ]);
            $mtReject = $this->call(MeituanController::class, 'rejectMeituanReviewOrderMatch', [
                'system_hotel_id' => $this->hotelId,
                'reviewId' => $mtReviewB,
                'orderId' => $mtOrder,
                'reason' => '人工核验后日期不一致',
            ]);
            self::assertSame('rejected', $mtReject['match_status']);
            self::assertSame('saved_and_readback_verified', $mtReject['save_status']);

            $mtRun = $this->call(MeituanController::class, 'runMeituanReviewOrderMatchAutomation', [
                'system_hotel_id' => $this->hotelId,
                'review_limit' => 500,
            ]);
            self::assertSame('saved_and_readback_verified', $mtRun['save_status']);
            self::assertGreaterThanOrEqual(1, (int)$mtRun['summary']['manual_matched_count']);
            self::assertGreaterThanOrEqual(1, (int)$mtRun['summary']['rejected_count']);
            $this->assertStoredStatus('ota_meituan_review_order_matches', 'review_id', $mtReviewA, 'matched');
            $this->assertStoredStatus('ota_meituan_review_order_matches', 'review_id', $mtReviewB, 'rejected');

            $mtClosure = $this->call(MeituanController::class, 'checkMeituanReviewOrderMatchClosure', [
                'system_hotel_id' => $this->hotelId,
                'min_matched' => 1,
            ]);
            self::assertSame('completed', $mtClosure['status']);
            self::assertGreaterThanOrEqual(1, (int)$mtClosure['summary']['manual_matched_count']);

            $mtUnbind = $this->call(MeituanController::class, 'unbindMeituanReviewOrderMatch', [
                'system_hotel_id' => $this->hotelId,
                'reviewId' => $mtReviewA,
            ]);
            self::assertSame('unbound', $mtUnbind['match_status']);
            $this->assertStoredStatus('ota_meituan_review_order_matches', 'review_id', $mtReviewA, 'unbound');

            $ctReviewSave = $this->call(CtripController::class, 'saveCtripReviewForMatch', [
                'system_hotel_id' => $this->hotelId,
                'review' => [
                    'commentId' => $ctReviewA,
                    'orderId' => $ctOrder,
                    'addtime' => '2099-01-03 12:00:00',
                    'checkinTimeStr' => '2099-01-01',
                    'hotelRoomInfo' => '证据回读双床房',
                    'avgScore' => 4.9,
                    'userName' => $sensitiveMarker,
                    'content' => $sensitiveMarker,
                ],
            ]);
            self::assertSame('saved_and_readback_verified', $ctReviewSave['save_status']);
            self::assertFalse((bool)($ctReviewSave['readback']['identity_fields_stored'] ?? true));
            self::assertFalse((bool)($ctReviewSave['readback']['review_content_stored'] ?? true));

            $ctOrderSave = $this->call(CtripController::class, 'saveCtripOrderForMatch', [
                'system_hotel_id' => $this->hotelId,
                'order' => [
                    'orderId' => $ctOrder,
                    'arrivalDate' => '2099-01-01',
                    'departureDate' => '2099-01-02',
                    'roomName' => '证据回读双床房',
                    'orderStatus' => '已离店',
                    'detailVerified' => true,
                    'guestName' => $sensitiveMarker,
                ],
            ]);
            self::assertSame('saved_and_readback_verified', $ctOrderSave['save_status']);

            $ctStoredReview = Db::name('ota_ctrip_reviews')
                ->where('system_hotel_id', $this->hotelId)
                ->where('comment_id', $ctReviewA)
                ->find();
            $ctStoredOrder = Db::name('ota_ctrip_orders')
                ->where('system_hotel_id', $this->hotelId)
                ->where('order_id', $ctOrder)
                ->find();
            self::assertIsArray($ctStoredReview);
            self::assertIsArray($ctStoredOrder);
            self::assertSame('', (string)$ctStoredReview['source_username']);
            self::assertSame('', (string)$ctStoredReview['content']);
            self::assertSame('', (string)$ctStoredOrder['guest_uid']);
            self::assertSame('', (string)$ctStoredOrder['guest_name']);
            self::assertStringNotContainsString($sensitiveMarker, (string)$ctStoredReview['raw_review_json']);
            self::assertStringNotContainsString($sensitiveMarker, (string)$ctStoredOrder['raw_order_json']);

            $ctLookup = $this->call(CtripController::class, 'lookupCtripReviewOrderMatch', [
                'system_hotel_id' => $this->hotelId,
                'commentId' => $ctReviewA,
                'store_mapping_verified' => true,
            ]);
            self::assertSame('confirmed', $ctLookup['status']);
            self::assertSame('saved_and_readback_verified', $ctLookup['save_status']);
            self::assertTrue((bool)$ctLookup['storage_write']);
            self::assertSame($ctReviewA, $ctLookup['attempt_readback']['comment_id']);

            $ctBind = $this->call(CtripController::class, 'bindCtripReviewOrderMatch', [
                'system_hotel_id' => $this->hotelId,
                'commentId' => $ctReviewA,
                'orderId' => $ctOrder,
            ]);
            self::assertSame('matched', $ctBind['match_status']);
            self::assertSame('saved_and_readback_verified', $ctBind['save_status']);

            $this->call(CtripController::class, 'saveCtripReviewForMatch', [
                'system_hotel_id' => $this->hotelId,
                'review' => [
                    'commentId' => $ctReviewB,
                    'addtime' => '2099-01-04 12:00:00',
                    'checkinTimeStr' => '2099-01-01',
                    'hotelRoomInfo' => '证据回读双床房',
                ],
            ]);
            $ctReject = $this->call(CtripController::class, 'rejectCtripReviewOrderMatch', [
                'system_hotel_id' => $this->hotelId,
                'commentId' => $ctReviewB,
                'orderId' => $ctOrder,
                'reason' => '人工核验后订单主体不一致',
            ]);
            self::assertSame('rejected', $ctReject['match_status']);

            $ctRun = $this->call(CtripController::class, 'runCtripReviewOrderMatchAutomation', [
                'system_hotel_id' => $this->hotelId,
                'review_limit' => 500,
                'raw_limit' => 0,
                'auto_capture' => false,
            ]);
            self::assertGreaterThanOrEqual(1, (int)$ctRun['summary']['manual_matched_count']);
            self::assertGreaterThanOrEqual(1, (int)$ctRun['summary']['rejected_count']);
            $this->assertStoredStatus('ota_ctrip_review_order_matches', 'comment_id', $ctReviewA, 'matched');
            $this->assertStoredStatus('ota_ctrip_review_order_matches', 'comment_id', $ctReviewB, 'rejected');

            $ctClosure = $this->call(CtripController::class, 'checkCtripReviewOrderMatchClosure', [
                'system_hotel_id' => $this->hotelId,
                'min_matched' => 1,
            ]);
            self::assertSame('completed', $ctClosure['status']);
            self::assertGreaterThanOrEqual(1, (int)$ctClosure['summary']['manual_matched_count']);

            $ctUnbind = $this->call(CtripController::class, 'unbindCtripReviewOrderMatch', [
                'system_hotel_id' => $this->hotelId,
                'commentId' => $ctReviewA,
            ]);
            self::assertSame('unbound', $ctUnbind['match_status']);
            $this->assertStoredStatus('ota_ctrip_review_order_matches', 'comment_id', $ctReviewA, 'unbound');
        } finally {
            Db::rollback();
            $this->removeAnyResidualRows([$mtReviewA, $mtReviewB], [$mtOrder], [$ctReviewA, $ctReviewB], [$ctOrder]);
        }

        self::assertSame(0, (int)Db::name('ota_meituan_reviews')->where('review_id', $mtReviewA)->count());
        self::assertSame(0, (int)Db::name('ota_ctrip_reviews')->where('comment_id', $ctReviewA)->count());
    }

    /** @param class-string $controllerClass @param array<string,mixed> $payload @return array<string,mixed> */
    private function call(string $controllerClass, string $action, array $payload): array
    {
        $request = (new Request())
            ->setMethod('POST')
            ->setUrl('/api/online-data/integration-test')
            ->setBaseUrl('/api/online-data/integration-test')
            ->setPathinfo('api/online-data/integration-test')
            ->withPost($payload)
            ->withServer(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader(['Accept' => 'application/json']);
        $request->user = $this->actor;
        self::$app->instance('request', $request);

        $response = (new $controllerClass(self::$app))->{$action}();
        self::assertInstanceOf(Response::class, $response);
        $decoded = json_decode((string)$response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(200, $response->getCode(), (string)$response->getContent());
        self::assertSame(200, $decoded['code'] ?? null, (string)$response->getContent());
        self::assertIsArray($decoded['data'] ?? null);
        return $decoded['data'];
    }

    private function assertStoredStatus(string $table, string $keyField, string $keyValue, string $expected): void
    {
        $status = Db::name($table)
            ->where('system_hotel_id', $this->hotelId)
            ->where($keyField, $keyValue)
            ->value('match_status');
        self::assertSame($expected, (string)$status);
    }

    private function superAdmin(): ?User
    {
        foreach (User::where('status', User::STATUS_ENABLED)->order('id', 'asc')->select() as $user) {
            if ($user instanceof User && $user->isSuperAdmin()) {
                return $user;
            }
        }
        return null;
    }

    /** @param list<string> $mtReviews @param list<string> $mtOrders @param list<string> $ctReviews @param list<string> $ctOrders */
    private function removeAnyResidualRows(array $mtReviews, array $mtOrders, array $ctReviews, array $ctOrders): void
    {
        Db::name('ota_meituan_review_order_matches')->where('system_hotel_id', $this->hotelId)->whereIn('review_id', $mtReviews)->delete();
        Db::name('ota_meituan_reviews')->where('system_hotel_id', $this->hotelId)->whereIn('review_id', $mtReviews)->delete();
        Db::name('ota_meituan_orders')->where('system_hotel_id', $this->hotelId)->whereIn('order_id', $mtOrders)->delete();
        Db::name('ota_ctrip_review_order_matches')->where('system_hotel_id', $this->hotelId)->whereIn('comment_id', $ctReviews)->delete();
        Db::name('ota_ctrip_reviews')->where('system_hotel_id', $this->hotelId)->whereIn('comment_id', $ctReviews)->delete();
        Db::name('ota_ctrip_orders')->where('system_hotel_id', $this->hotelId)->whereIn('order_id', $ctOrders)->delete();
        Db::name('operation_logs')
            ->where('hotel_id', $this->hotelId)
            ->whereLike('description', '%' . $this->token . '%')
            ->delete();
    }
}
