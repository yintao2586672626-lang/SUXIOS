<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\model\OperationLog;
use app\service\MeituanReviewOrderMatchService;
use think\Response;
use think\facade\Db;

trait MeituanReviewOrderMatchConcern
{
    public function saveMeituanReviewForMatch(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        return $this->reviewRiskPolicyBlockedResponse('meituan_review_storage_for_order_match', [
            'identity_reverse_lookup',
            'anonymous_user_matching',
        ]);

        try {
            $data = $this->requestData();
            $systemHotelId = $this->resolveMeituanReviewMatchHotelId($data);
            if (!$systemHotelId) {
                return $this->error('Please select a hotel', 400);
            }

            $review = is_array($data['review'] ?? null) ? $data['review'] : $data;
            $service = new MeituanReviewOrderMatchService();
            $normalized = $service->normalizeReviewForStorage($review);
            if ($normalized['review_id'] === '') {
                return $this->error('Missing Meituan review id', 422);
            }

            $row = [
                'system_hotel_id' => $systemHotelId,
                'review_id' => $normalized['review_id'],
                'meituan_user_id' => $normalized['meituan_user_id'],
                'source_username' => $normalized['source_username'],
                'review_date' => $this->nullableMeituanReviewMatchDate($normalized['review_date']),
                'checkin_date' => $this->nullableMeituanReviewMatchDate($normalized['checkin_date']),
                'room_name' => $normalized['room_name'],
                'room_name_prefix' => $this->meituanReviewMatchRoomPrefix((string)$normalized['room_name']),
                'score' => $normalized['score'],
                'content' => $normalized['content'],
                'raw_review_json' => $normalized['raw_review_json'],
                'update_time' => date('Y-m-d H:i:s'),
            ];

            $existing = Db::name('ota_meituan_reviews')
                ->where('system_hotel_id', $systemHotelId)
                ->where('review_id', (string)$normalized['review_id'])
                ->find();
            if ($existing) {
                Db::name('ota_meituan_reviews')->where('id', (int)$existing['id'])->update($row);
                $id = (int)$existing['id'];
            } else {
                $row['create_time'] = date('Y-m-d H:i:s');
                $id = (int)Db::name('ota_meituan_reviews')->insertGetId($row);
            }

            return $this->success(['id' => $id, 'review_id' => $normalized['review_id']], 'Meituan review saved');
        } catch (\think\exception\HttpException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getStatusCode()));
        } catch (\Throwable $e) {
            return $this->error('Failed to save Meituan review: ' . $e->getMessage(), 500);
        }
    }

    public function saveMeituanOrderForMatch(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        return $this->reviewRiskPolicyBlockedResponse('meituan_order_storage_for_review_match', [
            'identity_reverse_lookup',
            'phone_acquisition',
            'anonymous_user_matching',
        ]);

        try {
            $data = $this->requestData();
            $systemHotelId = $this->resolveMeituanReviewMatchHotelId($data);
            if (!$systemHotelId) {
                return $this->error('Please select a hotel', 400);
            }

            $order = is_array($data['order'] ?? null) ? $data['order'] : $data;
            $service = new MeituanReviewOrderMatchService();
            $normalized = $service->normalizeOrderForStorage($order);
            if ($normalized['order_id'] === '') {
                return $this->error('Missing Meituan order id', 422);
            }

            $row = [
                'system_hotel_id' => $systemHotelId,
                'order_id' => $normalized['order_id'],
                'meituan_user_id' => $normalized['meituan_user_id'],
                'guest_name_masked' => $normalized['guest_name_masked'],
                'arrival_date' => $this->nullableMeituanReviewMatchDate($normalized['arrival_date']),
                'departure_date' => $this->nullableMeituanReviewMatchDate($normalized['departure_date']),
                'room_name' => $normalized['room_name'],
                'room_name_prefix' => $this->meituanReviewMatchRoomPrefix((string)$normalized['room_name']),
                'order_status' => $normalized['order_status'],
                'phone_masked' => $normalized['phone_masked'],
                'phone_last4' => $normalized['phone_last4'],
                'phone_status' => $normalized['phone_status'],
                'phone_source' => $normalized['phone_source'],
                'raw_order_json' => $normalized['raw_order_json'],
                'update_time' => date('Y-m-d H:i:s'),
            ];

            $existing = Db::name('ota_meituan_orders')
                ->where('system_hotel_id', $systemHotelId)
                ->where('order_id', (string)$normalized['order_id'])
                ->find();
            if ($existing) {
                Db::name('ota_meituan_orders')->where('id', (int)$existing['id'])->update($row);
                $id = (int)$existing['id'];
            } else {
                $row['create_time'] = date('Y-m-d H:i:s');
                $id = (int)Db::name('ota_meituan_orders')->insertGetId($row);
            }

            return $this->success([
                'id' => $id,
                'order_id' => $normalized['order_id'],
                'phone_status' => $normalized['phone_status'],
                'phone_masked' => $normalized['phone_masked'],
            ], 'Meituan order saved');
        } catch (\think\exception\HttpException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getStatusCode()));
        } catch (\Throwable $e) {
            return $this->error('Failed to save Meituan order: ' . $e->getMessage(), 500);
        }
    }

    public function lookupMeituanReviewOrderMatch(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_view_online_data');

        return $this->reviewRiskPolicyBlockedResponse('meituan_review_order_lookup', [
            'identity_reverse_lookup',
            'phone_acquisition',
            'anonymous_user_matching',
        ]);

        try {
            $data = $this->requestData();
            $systemHotelId = $this->resolveMeituanReviewMatchHotelId($data);
            if (!$systemHotelId) {
                return $this->error('Please select a hotel', 400);
            }

            $review = $this->resolveMeituanReviewForLookup($systemHotelId, $data);
            if ($review === []) {
                return $this->error('No Meituan review found for matching', 404);
            }

            $service = new MeituanReviewOrderMatchService();
            $result = $service->matchReviewToOrder(
                $review,
                $this->loadMeituanOrderPool($systemHotelId),
                ['coverage_start_date' => $this->firstMeituanOrderCoverageDate($systemHotelId)]
            );

            $this->saveMeituanReviewMatchAttempt($systemHotelId, $review, $result, 'auto');

            return $this->success($result, 'Meituan review order lookup completed');
        } catch (\think\exception\HttpException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getStatusCode()));
        } catch (\Throwable $e) {
            return $this->error('Failed to lookup Meituan review order: ' . $e->getMessage(), 500);
        }
    }

    public function bindMeituanReviewOrderMatch(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        return $this->reviewRiskPolicyBlockedResponse('meituan_review_order_manual_bind', [
            'identity_reverse_lookup',
            'phone_acquisition',
            'anonymous_user_matching',
        ]);

        try {
            $data = $this->requestData();
            $systemHotelId = $this->resolveMeituanReviewMatchHotelId($data);
            if (!$systemHotelId) {
                return $this->error('Please select a hotel', 400);
            }

            $reviewId = trim((string)($data['reviewId'] ?? $data['review_id'] ?? $data['commentId'] ?? $data['comment_id'] ?? ''));
            $orderId = trim((string)($data['orderId'] ?? $data['order_id'] ?? ''));
            if ($reviewId === '' || $orderId === '') {
                return $this->error('Missing reviewId or orderId', 422);
            }

            $order = Db::name('ota_meituan_orders')
                ->where('system_hotel_id', $systemHotelId)
                ->where('order_id', $orderId)
                ->find() ?: [];

            $row = [
                'system_hotel_id' => $systemHotelId,
                'review_id' => $reviewId,
                'order_id' => $orderId,
                'meituan_user_id' => trim((string)($data['mtUserId'] ?? $data['meituan_user_id'] ?? $order['meituan_user_id'] ?? '')),
                'guest_name_masked' => trim((string)($data['guestNameMasked'] ?? $data['guest_name_masked'] ?? $order['guest_name_masked'] ?? '')),
                'match_status' => 'matched',
                'match_method' => 'manual',
                'confidence' => 'high',
                'candidate_orders_json' => $this->encodeMeituanReviewMatchJson([]),
                'evidence_json' => $this->encodeMeituanReviewMatchJson([
                    'source' => 'manual_bind',
                    'scope' => 'meituan_ota_channel',
                    'business_metric' => false,
                    'operator_user_id' => $this->currentUser->id ?? null,
                ]),
                'bound_by' => $this->currentUser->id ?? null,
                'bound_at' => date('Y-m-d H:i:s'),
                'update_time' => date('Y-m-d H:i:s'),
            ];

            $existing = Db::name('ota_meituan_review_order_matches')
                ->where('system_hotel_id', $systemHotelId)
                ->where('review_id', $reviewId)
                ->find();
            if ($existing) {
                Db::name('ota_meituan_review_order_matches')->where('id', (int)$existing['id'])->update($row);
                $id = (int)$existing['id'];
            } else {
                $row['create_time'] = date('Y-m-d H:i:s');
                $id = (int)Db::name('ota_meituan_review_order_matches')->insertGetId($row);
            }

            OperationLog::record('online_data', 'bind_meituan_review_order_match', 'Bind Meituan review order: ' . $reviewId . ' -> ' . $orderId, $this->currentUser->id ?? null, $systemHotelId);
            return $this->success(['id' => $id, 'review_id' => $reviewId, 'order_id' => $orderId], 'Meituan review order bound');
        } catch (\think\exception\HttpException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getStatusCode()));
        } catch (\Throwable $e) {
            return $this->error('Failed to bind Meituan review order: ' . $e->getMessage(), 500);
        }
    }

    public function unbindMeituanReviewOrderMatch(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        return $this->reviewRiskPolicyBlockedResponse('meituan_review_order_manual_unbind', [
            'identity_reverse_lookup',
            'phone_acquisition',
            'anonymous_user_matching',
        ]);

        try {
            $data = $this->requestData();
            $systemHotelId = $this->resolveMeituanReviewMatchHotelId($data);
            if (!$systemHotelId) {
                return $this->error('Please select a hotel', 400);
            }

            $reviewId = trim((string)($data['reviewId'] ?? $data['review_id'] ?? $data['commentId'] ?? $data['comment_id'] ?? ''));
            if ($reviewId === '') {
                return $this->error('Missing reviewId', 422);
            }

            $existing = Db::name('ota_meituan_review_order_matches')
                ->where('system_hotel_id', $systemHotelId)
                ->where('review_id', $reviewId)
                ->find();
            if (!$existing) {
                return $this->error('Meituan review order match not found', 404);
            }

            $row = [
                'order_id' => '',
                'match_status' => 'unbound',
                'match_method' => 'manual_unbind',
                'confidence' => 'none',
                'candidate_orders_json' => $this->encodeMeituanReviewMatchJson([]),
                'evidence_json' => $this->encodeMeituanReviewMatchJson([
                    'source' => 'manual_unbind',
                    'scope' => 'meituan_ota_channel',
                    'business_metric' => false,
                    'previous_order_id' => (string)($existing['order_id'] ?? ''),
                    'operator_user_id' => $this->currentUser->id ?? null,
                ]),
                'bound_by' => null,
                'bound_at' => null,
                'update_time' => date('Y-m-d H:i:s'),
            ];

            Db::name('ota_meituan_review_order_matches')->where('id', (int)$existing['id'])->update($row);
            OperationLog::record('online_data', 'unbind_meituan_review_order_match', 'Unbind Meituan review order: ' . $reviewId, $this->currentUser->id ?? null, $systemHotelId);

            return $this->success(['id' => (int)$existing['id'], 'review_id' => $reviewId, 'match_status' => 'unbound'], 'Meituan review order unbound');
        } catch (\think\exception\HttpException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getStatusCode()));
        } catch (\Throwable $e) {
            return $this->error('Failed to unbind Meituan review order: ' . $e->getMessage(), 500);
        }
    }

    public function meituanOrderPhoneState(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_view_online_data');

        return $this->reviewRiskPolicyBlockedResponse('meituan_order_phone_state', [
            'phone_acquisition',
            'identity_reverse_lookup',
        ]);

        try {
            $data = $this->requestData();
            $order = is_array($data['order'] ?? null) ? $data['order'] : $data;
            $service = new MeituanReviewOrderMatchService();
            $state = $service->buildPhoneHandlingState($order, [
                'app_session_status' => $data['app_session_status'] ?? $data['appSessionStatus'] ?? $order['app_session_status'] ?? 'unknown',
            ]);

            return $this->success($state, 'Meituan order phone state resolved');
        } catch (\think\exception\HttpException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getStatusCode()));
        } catch (\Throwable $e) {
            return $this->error('Failed to resolve Meituan order phone state: ' . $e->getMessage(), 500);
        }
    }

    /**
     * @param array<string, mixed> $requestData
     */
    private function resolveMeituanReviewMatchHotelId(array $requestData): ?int
    {
        return $this->resolveOnlineDataSystemHotelId(
            $requestData['system_hotel_id']
            ?? $requestData['systemHotelId']
            ?? $requestData['hotel_id']
            ?? $requestData['hotelId']
            ?? null
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function resolveMeituanReviewForLookup(int $systemHotelId, array $data): array
    {
        if (isset($data['review']) && is_array($data['review'])) {
            return $data['review'];
        }

        $reviewId = trim((string)($data['reviewId'] ?? $data['review_id'] ?? $data['commentId'] ?? $data['comment_id'] ?? ''));
        if ($reviewId === '') {
            return [];
        }

        $row = Db::name('ota_meituan_reviews')
            ->where('system_hotel_id', $systemHotelId)
            ->where('review_id', $reviewId)
            ->find();
        if (!$row) {
            return [];
        }

        return [
            'reviewId' => (string)$row['review_id'],
            'mtUserId' => (string)$row['meituan_user_id'],
            'userName' => (string)$row['source_username'],
            'checkInDate' => (string)($row['checkin_date'] ?? ''),
            'roomName' => (string)$row['room_name'],
            'score' => $row['score'] ?? null,
            'content' => (string)$row['content'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadMeituanOrderPool(int $systemHotelId): array
    {
        $rows = Db::name('ota_meituan_orders')
            ->where('system_hotel_id', $systemHotelId)
            ->select()
            ->toArray();

        return array_map(static function (array $row): array {
            return [
                'orderId' => (string)$row['order_id'],
                'mtUserId' => (string)$row['meituan_user_id'],
                'guestNameMasked' => (string)$row['guest_name_masked'],
                'checkInDate' => (string)($row['arrival_date'] ?? ''),
                'checkOutDate' => (string)($row['departure_date'] ?? ''),
                'roomName' => (string)$row['room_name'],
                'orderStatus' => (string)$row['order_status'],
                'phone' => (string)$row['phone_masked'],
                'phoneLast4' => (string)$row['phone_last4'],
                'phone_source' => (string)$row['phone_source'],
                'phone_status' => (string)$row['phone_status'],
                'platform' => 'meituan',
            ];
        }, $rows);
    }

    private function firstMeituanOrderCoverageDate(int $systemHotelId): string
    {
        $date = Db::name('ota_meituan_orders')
            ->where('system_hotel_id', $systemHotelId)
            ->whereNotNull('arrival_date')
            ->min('arrival_date');

        return $date ? (string)$date : '';
    }

    /**
     * @param array<string, mixed> $review
     * @param array<string, mixed> $result
     */
    private function saveMeituanReviewMatchAttempt(int $systemHotelId, array $review, array $result, string $source): void
    {
        $service = new MeituanReviewOrderMatchService();
        $reviewId = $service->extractReviewId($review);
        if ($reviewId === '') {
            return;
        }

        $order = is_array($result['order'] ?? null) ? $result['order'] : [];
        $row = [
            'system_hotel_id' => $systemHotelId,
            'review_id' => $reviewId,
            'order_id' => (string)($order['order_id'] ?? ''),
            'meituan_user_id' => (string)($order['meituan_user_id'] ?? ''),
            'guest_name_masked' => (string)($order['guest_name_masked'] ?? ''),
            'match_status' => (string)($result['status'] ?? 'unmatched'),
            'match_method' => (string)($result['match_method'] ?? $source),
            'confidence' => (string)($result['confidence'] ?? 'none'),
            'candidate_orders_json' => $this->encodeMeituanReviewMatchJson($result['candidates'] ?? []),
            'evidence_json' => $this->encodeMeituanReviewMatchJson([
                'source' => $source,
                'scope' => 'meituan_ota_channel',
                'business_metric' => false,
                'result' => $result,
            ]),
            'update_time' => date('Y-m-d H:i:s'),
        ];

        $existing = Db::name('ota_meituan_review_order_matches')
            ->where('system_hotel_id', $systemHotelId)
            ->where('review_id', $reviewId)
            ->find();
        if ($existing) {
            Db::name('ota_meituan_review_order_matches')->where('id', (int)$existing['id'])->update($row);
            return;
        }

        $row['create_time'] = date('Y-m-d H:i:s');
        Db::name('ota_meituan_review_order_matches')->insert($row);
    }

    private function nullableMeituanReviewMatchDate($value): ?string
    {
        $text = trim((string)$value);
        if ($text === '') {
            return null;
        }
        if (preg_match('/(20\d{2})\D+(\d{1,2})\D+(\d{1,2})/u', $text, $matches)) {
            return sprintf('%04d-%02d-%02d', (int)$matches[1], (int)$matches[2], (int)$matches[3]);
        }
        $timestamp = strtotime($text);
        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }

    private function meituanReviewMatchRoomPrefix(string $roomName): string
    {
        $roomName = trim($roomName);
        if ($roomName === '') {
            return '';
        }
        return trim((string)preg_split('/[-|_]/', $roomName)[0]);
    }

    private function encodeMeituanReviewMatchJson($value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }
}
