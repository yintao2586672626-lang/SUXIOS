<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\model\OperationLog;
use app\service\CtripReviewOrderMatchService;
use think\Response;
use think\facade\Db;

trait CtripReviewOrderMatchConcern
{
    public function saveCtripReviewImSession(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        try {
            $data = $this->requestData();
            $systemHotelId = $this->resolveCtripReviewMatchHotelId($data);
            if (!$systemHotelId) {
                return $this->error('请选择酒店', 400);
            }

            $session = is_array($data['session'] ?? null) ? $data['session'] : $data;
            $groupId = trim((string)($session['groupId'] ?? $session['group_id'] ?? ''));
            if ($groupId === '') {
                return $this->error('缺少携程 IM groupId', 422);
            }

            $members = $session['members'] ?? null;
            if (!is_array($members)) {
                return $this->error('缺少携程 IM members', 422);
            }

            $service = new CtripReviewOrderMatchService();
            $memberIndex = $service->buildMemberIndex([['members' => $members]]);
            $guest = $memberIndex === [] ? null : reset($memberIndex);
            $arrivalDate = $this->normalizeCtripReviewMatchDate($session['arrivalDate'] ?? $session['arrival_date'] ?? $session['checkDate'] ?? $session['check_date'] ?? '');
            $roomName = trim((string)($session['roomName'] ?? $session['room_name'] ?? $session['roomNamePrefix'] ?? $session['room_name_prefix'] ?? ''));

            $row = [
                'system_hotel_id' => $systemHotelId,
                'group_id' => $groupId,
                'session_id' => trim((string)($session['sessionId'] ?? $session['session_id'] ?? '')),
                'guest_uid' => $guest['uid'] ?? '',
                'guest_name' => $guest['nick_name'] ?? '',
                'guest_avatar_url' => $guest['avatar_url'] ?? trim((string)($session['avatar'] ?? '')),
                'arrival_date' => $arrivalDate !== '' ? $arrivalDate : null,
                'departure_date' => $this->nullableCtripReviewMatchDate($session['departureDate'] ?? $session['departure_date'] ?? null),
                'room_name' => $roomName,
                'room_name_prefix' => $this->roomPrefix($roomName),
                'order_id' => trim((string)($session['orderId'] ?? $session['order_id'] ?? '')),
                'match_status' => $guest ? ($arrivalDate !== '' ? 'usable' : 'degraded_missing_arrival_date') : 'unusable_no_guest_member',
                'members_json' => $this->encodeCtripReviewMatchJson($members),
                'evidence_json' => $this->encodeCtripReviewMatchJson([
                    'source' => 'ctrip_query_message_list',
                    'scope' => 'ctrip_ota_channel',
                    'member_count' => count($members),
                    'eligible_guest_member_count' => count($memberIndex),
                ]),
                'fetched_at' => date('Y-m-d H:i:s'),
                'update_time' => date('Y-m-d H:i:s'),
            ];

            $existing = Db::name('ota_ctrip_im_sessions')
                ->where('system_hotel_id', $systemHotelId)
                ->where('group_id', $groupId)
                ->find();
            if ($existing) {
                Db::name('ota_ctrip_im_sessions')->where('id', (int)$existing['id'])->update($row);
                $id = (int)$existing['id'];
            } else {
                $row['create_time'] = date('Y-m-d H:i:s');
                $id = (int)Db::name('ota_ctrip_im_sessions')->insertGetId($row);
            }

            OperationLog::record('online_data', 'save_ctrip_review_im_session', '保存携程评价匹配 IM 会话: ' . $groupId, $this->currentUser->id ?? null, $systemHotelId);
            return $this->success(['id' => $id, 'match_status' => $row['match_status']], '携程 IM 会话缓存已保存');
        } catch (\think\exception\HttpException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getStatusCode()));
        } catch (\Throwable $e) {
            return $this->error('保存携程 IM 会话缓存失败: ' . $e->getMessage(), 500);
        }
    }

    public function saveCtripReviewForMatch(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        try {
            $data = $this->requestData();
            $systemHotelId = $this->resolveCtripReviewMatchHotelId($data);
            if (!$systemHotelId) {
                return $this->error('请选择酒店', 400);
            }

            $review = is_array($data['review'] ?? null) ? $data['review'] : $data;
            $commentId = $this->extractCtripReviewCommentId($review);
            if ($commentId === '') {
                return $this->error('缺少携程评价 commentId', 422);
            }

            $row = [
                'system_hotel_id' => $systemHotelId,
                'comment_id' => $commentId,
                'source_username' => $this->firstCtripReviewMatchText($review, ['userName', 'user_name', 'sourceUsername', 'source_username', 'username', 'nickName', 'nick_name']),
                'user_avatar_url' => $this->firstCtripReviewMatchText($review, ['userIcon', 'user_icon', 'avatar', 'avatarUrl', 'avatar_url']),
                'review_date' => $this->nullableCtripReviewMatchDate($this->firstCtripReviewMatchText($review, ['addtime', 'commentTime', 'comment_time', 'reviewTime', 'review_time', 'createTime', 'create_time', 'submitTime', 'submit_time', 'date'])),
                'checkin_date' => $this->nullableCtripReviewMatchDate($this->firstCtripReviewMatchText($review, ['checkinTimeStr', 'checkInDate', 'check_in_date', 'checkinDate', 'arrivalDate', 'arrival_date', 'stayDate', 'stay_date'])),
                'room_name' => $this->firstCtripReviewMatchText($review, ['hotelRoomInfo', 'hotel_room_info', 'roomName', 'room_name', 'roomType', 'room_type', 'productName', 'ratePlanName']),
                'score' => $this->firstCtripReviewMatchNumber($review, ['avgScore', 'score', 'rating', 'rate', 'totalScore', 'overallScore', 'commentScore', 'star']),
                'content' => $this->firstCtripReviewMatchText($review, ['content', 'comment', 'commentContent', 'reviewContent', 'contentText', 'text']),
                'raw_review_json' => $this->encodeCtripReviewMatchJson($review),
                'update_time' => date('Y-m-d H:i:s'),
            ];

            $existing = Db::name('ota_ctrip_reviews')
                ->where('system_hotel_id', $systemHotelId)
                ->where('comment_id', $commentId)
                ->find();
            if ($existing) {
                Db::name('ota_ctrip_reviews')->where('id', (int)$existing['id'])->update($row);
                $id = (int)$existing['id'];
            } else {
                $row['create_time'] = date('Y-m-d H:i:s');
                $id = (int)Db::name('ota_ctrip_reviews')->insertGetId($row);
            }

            return $this->success(['id' => $id, 'comment_id' => $commentId], '携程评价已保存');
        } catch (\think\exception\HttpException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getStatusCode()));
        } catch (\Throwable $e) {
            return $this->error('保存携程评价失败: ' . $e->getMessage(), 500);
        }
    }

    public function saveCtripOrderForMatch(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        try {
            $data = $this->requestData();
            $systemHotelId = $this->resolveCtripReviewMatchHotelId($data);
            if (!$systemHotelId) {
                return $this->error('请选择酒店', 400);
            }

            $order = is_array($data['order'] ?? null) ? $data['order'] : $data;
            $orderId = trim((string)($order['orderId'] ?? $order['order_id'] ?? $order['platform_order_id'] ?? ''));
            if ($orderId === '') {
                return $this->error('缺少携程订单号', 422);
            }

            $row = [
                'system_hotel_id' => $systemHotelId,
                'order_id' => $orderId,
                'guest_uid' => trim((string)($order['guestUid'] ?? $order['guest_uid'] ?? $order['ctrip_guest_uid'] ?? '')),
                'guest_name' => trim((string)($order['guestName'] ?? $order['guest_name'] ?? $order['customerName'] ?? $order['customer_name'] ?? '')),
                'arrival_date' => $this->nullableCtripReviewMatchDate($order['arrivalDate'] ?? $order['arrival_date'] ?? $order['checkInDate'] ?? $order['check_in_date'] ?? null),
                'departure_date' => $this->nullableCtripReviewMatchDate($order['departureDate'] ?? $order['departure_date'] ?? $order['checkOutDate'] ?? $order['check_out_date'] ?? null),
                'room_name' => trim((string)($order['roomName'] ?? $order['room_name'] ?? $order['roomType'] ?? $order['room_type'] ?? '')),
                'room_name_prefix' => $this->roomPrefix((string)($order['roomName'] ?? $order['room_name'] ?? $order['roomType'] ?? $order['room_type'] ?? '')),
                'order_status' => trim((string)($order['orderStatus'] ?? $order['order_status'] ?? $order['status'] ?? '')),
                'source_platform' => 'ctrip',
                'raw_order_json' => $this->encodeCtripReviewMatchJson($order),
                'update_time' => date('Y-m-d H:i:s'),
            ];

            $existing = Db::name('ota_ctrip_orders')
                ->where('system_hotel_id', $systemHotelId)
                ->where('order_id', $orderId)
                ->find();
            if ($existing) {
                Db::name('ota_ctrip_orders')->where('id', (int)$existing['id'])->update($row);
                $id = (int)$existing['id'];
            } else {
                $row['create_time'] = date('Y-m-d H:i:s');
                $id = (int)Db::name('ota_ctrip_orders')->insertGetId($row);
            }

            return $this->success(['id' => $id, 'order_id' => $orderId], '携程订单已保存');
        } catch (\think\exception\HttpException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getStatusCode()));
        } catch (\Throwable $e) {
            return $this->error('保存携程订单失败: ' . $e->getMessage(), 500);
        }
    }

    public function lookupCtripReviewOrderMatch(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_view_online_data');

        try {
            $data = $this->requestData();
            $systemHotelId = $this->resolveCtripReviewMatchHotelId($data);
            if (!$systemHotelId) {
                return $this->error('请选择酒店', 400);
            }

            $review = $this->resolveCtripReviewForLookup($systemHotelId, $data);
            if ($review === []) {
                return $this->error('未找到可匹配的携程评价', 404);
            }

            $service = new CtripReviewOrderMatchService();
            $result = $service->matchReviewToOrder(
                $review,
                $this->loadCtripReviewImSessions($systemHotelId),
                $this->loadCtripOrderPool($systemHotelId),
                ['coverage_start_date' => $this->firstCtripOrderCoverageDate($systemHotelId)]
            );

            $this->saveCtripReviewMatchAttempt($systemHotelId, $review, $result, 'auto');

            return $this->success($result, '携程评价订单反查完成');
        } catch (\think\exception\HttpException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getStatusCode()));
        } catch (\Throwable $e) {
            return $this->error('携程评价订单反查失败: ' . $e->getMessage(), 500);
        }
    }

    public function runCtripReviewOrderMatchAutomation(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        try {
            $data = $this->requestData();
            $systemHotelId = $this->resolveCtripReviewMatchHotelId($data);
            if (!$systemHotelId) {
                return $this->error('请选择酒店', 400);
            }

            $rawLimit = max(0, min(100, (int)($data['raw_limit'] ?? $data['rawLimit'] ?? 30)));
            $reviewLimit = max(1, min(500, (int)($data['review_limit'] ?? $data['reviewLimit'] ?? 200)));
            $importSummary = $this->importCtripReviewMatchRawRecords($systemHotelId, $rawLimit);

            $reviews = $this->loadCtripReviewsForMatch($systemHotelId, $reviewLimit);
            $imSessions = $this->loadCtripReviewImSessions($systemHotelId);
            $orders = $this->loadCtripOrderPool($systemHotelId);
            $coverageStartDate = $this->firstCtripOrderCoverageDate($systemHotelId);
            $service = new CtripReviewOrderMatchService();
            $statusCounts = [];
            $samples = [];

            foreach ($reviews as $review) {
                $result = $service->matchReviewToOrder(
                    $review,
                    $imSessions,
                    $orders,
                    ['coverage_start_date' => $coverageStartDate]
                );
                $status = (string)($result['status'] ?? 'unknown');
                $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
                $this->saveCtripReviewMatchAttempt($systemHotelId, $review, $result, 'automation');
                if (count($samples) < 20) {
                    $samples[] = [
                        'comment_id' => $this->extractCtripReviewCommentId($review),
                        'source_username' => $this->firstCtripReviewMatchText($review, ['userName', 'user_name', 'sourceUsername', 'source_username']),
                        'status' => $status,
                        'order_id' => (string)($result['order']['order_id'] ?? ''),
                        'guest_name' => (string)($result['identity']['guest_name'] ?? $result['person_name'] ?? ''),
                        'reason' => (string)($result['reason'] ?? ''),
                    ];
                }
            }

            $missingSources = [];
            if (count($reviews) === 0) {
                $missingSources[] = 'ctrip_reviews';
            }
            if (count($imSessions) === 0) {
                $missingSources[] = 'ctrip_im_sessions';
            }
            if (count($orders) === 0) {
                $missingSources[] = 'ctrip_orders';
            }

            $ready = $missingSources === [];
            $payload = [
                'status' => $ready ? 'completed' : 'not_ready',
                'scope' => 'ctrip_ota_channel',
                'summary' => [
                    'review_count' => count($reviews),
                    'im_session_count' => count($imSessions),
                    'order_count' => count($orders),
                    'matched_count' => (int)($statusCounts['found'] ?? 0),
                    'person_locked_count' => (int)($statusCounts['person_locked'] ?? 0),
                    'needs_ops_count' => (int)($statusCounts['needs_ops'] ?? 0),
                    'out_of_coverage_count' => (int)($statusCounts['out_of_coverage'] ?? 0),
                ],
                'status_counts' => $statusCounts,
                'import' => $importSummary,
                'missing_sources' => $missingSources,
                'samples' => $samples,
                'next_action' => $ready
                    ? '查看 matched/person_locked 样本，person_locked 需要运营确认订单'
                    : '先通过携程浏览器 Profile 自动采集评价、IM 会话和订单池，再重新运行自动匹配',
            ];

            OperationLog::record(
                'online_data',
                'run_ctrip_review_order_match_automation',
                'Run Ctrip review order matching automation',
                $this->currentUser->id ?? null,
                $systemHotelId
            );

            return $this->success(
                $payload,
                $ready ? '携程评价订单自动匹配完成' : '携程评价订单自动匹配未完成：缺少必要数据源'
            );
        } catch (\think\exception\HttpException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getStatusCode()));
        } catch (\Throwable $e) {
            return $this->error('携程评价订单自动匹配失败: ' . $e->getMessage(), 500);
        }
    }

    public function bindCtripReviewOrderMatch(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        try {
            $data = $this->requestData();
            $systemHotelId = $this->resolveCtripReviewMatchHotelId($data);
            if (!$systemHotelId) {
                return $this->error('请选择酒店', 400);
            }

            $commentId = trim((string)($data['commentId'] ?? $data['comment_id'] ?? ''));
            $orderId = trim((string)($data['orderId'] ?? $data['order_id'] ?? ''));
            if ($commentId === '' || $orderId === '') {
                return $this->error('缺少 commentId 或 orderId', 422);
            }

            $order = Db::name('ota_ctrip_orders')
                ->where('system_hotel_id', $systemHotelId)
                ->where('order_id', $orderId)
                ->find() ?: [];

            $row = [
                'system_hotel_id' => $systemHotelId,
                'comment_id' => $commentId,
                'order_id' => $orderId,
                'guest_uid' => trim((string)($data['guestUid'] ?? $data['guest_uid'] ?? $order['guest_uid'] ?? '')),
                'guest_name' => trim((string)($data['guestName'] ?? $data['guest_name'] ?? $order['guest_name'] ?? '')),
                'match_status' => 'matched',
                'match_method' => 'manual',
                'confidence' => 'high',
                'candidate_orders_json' => $this->encodeCtripReviewMatchJson([]),
                'evidence_json' => $this->encodeCtripReviewMatchJson([
                    'source' => 'manual_bind',
                    'scope' => 'ctrip_ota_channel',
                    'operator_user_id' => $this->currentUser->id ?? null,
                ]),
                'bound_by' => $this->currentUser->id ?? null,
                'bound_at' => date('Y-m-d H:i:s'),
                'update_time' => date('Y-m-d H:i:s'),
            ];

            $existing = Db::name('ota_ctrip_review_order_matches')
                ->where('system_hotel_id', $systemHotelId)
                ->where('comment_id', $commentId)
                ->find();
            if ($existing) {
                Db::name('ota_ctrip_review_order_matches')->where('id', (int)$existing['id'])->update($row);
                $id = (int)$existing['id'];
            } else {
                $row['create_time'] = date('Y-m-d H:i:s');
                $id = (int)Db::name('ota_ctrip_review_order_matches')->insertGetId($row);
            }

            OperationLog::record('online_data', 'bind_ctrip_review_order_match', '人工绑定携程评价订单: ' . $commentId . ' -> ' . $orderId, $this->currentUser->id ?? null, $systemHotelId);
            return $this->success(['id' => $id, 'comment_id' => $commentId, 'order_id' => $orderId], '携程评价订单已绑定');
        } catch (\think\exception\HttpException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getStatusCode()));
        } catch (\Throwable $e) {
            return $this->error('绑定携程评价订单失败: ' . $e->getMessage(), 500);
        }
    }

    /**
     * @return array<string, int>
     */
    private function importCtripReviewMatchRawRecords(int $systemHotelId, int $limit): array
    {
        $summary = [
            'raw_records_scanned' => 0,
            'reviews_upserted' => 0,
            'im_sessions_upserted' => 0,
            'orders_upserted' => 0,
        ];
        if ($limit <= 0) {
            return $summary;
        }

        $rows = Db::name('platform_data_raw_records')
            ->where('platform', 'ctrip')
            ->where('system_hotel_id', $systemHotelId)
            ->order('id', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();

        foreach ($rows as $row) {
            $summary['raw_records_scanned']++;
            $payload = json_decode((string)($row['raw_payload'] ?? ''), true);
            if (!is_array($payload)) {
                continue;
            }
            $imported = $this->importCtripReviewMatchPayload($systemHotelId, $payload);
            $summary['reviews_upserted'] += (int)$imported['reviews_upserted'];
            $summary['im_sessions_upserted'] += (int)$imported['im_sessions_upserted'];
            $summary['orders_upserted'] += (int)$imported['orders_upserted'];
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, int>
     */
    private function importCtripReviewMatchPayload(int $systemHotelId, array $payload): array
    {
        $summary = [
            'reviews_upserted' => 0,
            'im_sessions_upserted' => 0,
            'orders_upserted' => 0,
        ];

        foreach ($this->extractCtripCapturedComments($payload) as $review) {
            if ($this->upsertCtripReviewMatchReview($systemHotelId, $review) > 0) {
                $summary['reviews_upserted']++;
            }
        }

        foreach ($this->extractCtripReviewMatchImSessionsFromPayload($payload) as $session) {
            if ($this->upsertCtripReviewMatchImSession($systemHotelId, $session) > 0) {
                $summary['im_sessions_upserted']++;
            }
        }

        foreach ($this->extractCtripReviewMatchOrdersFromPayload($payload) as $order) {
            if ($this->upsertCtripReviewMatchOrder($systemHotelId, $order) > 0) {
                $summary['orders_upserted']++;
            }
        }

        return $summary;
    }

    /**
     * @param mixed $value
     * @return array<int, array<string, mixed>>
     */
    private function extractCtripReviewMatchImSessionsFromPayload($value, int $depth = 0): array
    {
        if ($depth > 7 || !is_array($value)) {
            return [];
        }

        $sessions = [];
        if ($this->isSequentialArray($value)) {
            foreach ($value as $item) {
                $sessions = array_merge($sessions, $this->extractCtripReviewMatchImSessionsFromPayload($item, $depth + 1));
            }
            return $this->dedupeCtripReviewMatchRows($sessions, ['groupId', 'group_id', 'sessionId', 'session_id']);
        }

        $members = $this->firstCtripReviewMatchArray($value, ['members', 'memberList', 'member_list', 'imMembers', 'userList', 'users']);
        $groupId = $this->firstCtripReviewMatchText($value, ['groupId', 'group_id', 'groupID', 'sessionGroupId', 'conversationId']);
        if ($groupId !== '' && is_array($members) && $members !== []) {
            $session = $value;
            $session['groupId'] = $groupId;
            $session['members'] = $members;
            $sessions[] = $session;
        }

        foreach ($value as $item) {
            if (is_array($item)) {
                $sessions = array_merge($sessions, $this->extractCtripReviewMatchImSessionsFromPayload($item, $depth + 1));
            }
        }

        return $this->dedupeCtripReviewMatchRows($sessions, ['groupId', 'group_id', 'sessionId', 'session_id']);
    }

    /**
     * @param mixed $value
     * @return array<int, array<string, mixed>>
     */
    private function extractCtripReviewMatchOrdersFromPayload($value, int $depth = 0): array
    {
        if ($depth > 7 || !is_array($value)) {
            return [];
        }

        $orders = [];
        if ($this->isSequentialArray($value)) {
            foreach ($value as $item) {
                $orders = array_merge($orders, $this->extractCtripReviewMatchOrdersFromPayload($item, $depth + 1));
            }
            return $this->dedupeCtripReviewMatchRows($orders, ['orderId', 'order_id', 'platform_order_id']);
        }

        $orderId = $this->firstCtripReviewMatchText($value, ['orderId', 'order_id', 'platform_order_id', 'bookingOrderId', 'reservationOrderId']);
        if ($orderId !== '' && $this->looksLikeCtripReviewMatchOrder($value)) {
            $order = $value;
            $order['orderId'] = $orderId;
            $orders[] = $order;
        }

        foreach ($value as $item) {
            if (is_array($item)) {
                $orders = array_merge($orders, $this->extractCtripReviewMatchOrdersFromPayload($item, $depth + 1));
            }
        }

        return $this->dedupeCtripReviewMatchRows($orders, ['orderId', 'order_id', 'platform_order_id']);
    }

    /**
     * @param array<string, mixed> $order
     */
    private function looksLikeCtripReviewMatchOrder(array $order): bool
    {
        return $this->firstCtripReviewMatchText($order, ['guestUid', 'guest_uid', 'ctrip_guest_uid', 'memberUid', 'uid']) !== ''
            || $this->firstCtripReviewMatchText($order, ['guestName', 'guest_name', 'customerName', 'customer_name', 'contactName']) !== ''
            || $this->firstCtripReviewMatchText($order, ['arrivalDate', 'arrival_date', 'checkInDate', 'check_in_date', 'stayDate']) !== ''
            || $this->firstCtripReviewMatchText($order, ['roomName', 'room_name', 'roomType', 'room_type', 'productName']) !== '';
    }

    /**
     * @param array<string, mixed> $review
     */
    private function upsertCtripReviewMatchReview(int $systemHotelId, array $review): int
    {
        $commentId = $this->extractCtripReviewCommentId($review);
        if ($commentId === '') {
            return 0;
        }

        $row = [
            'system_hotel_id' => $systemHotelId,
            'comment_id' => $commentId,
            'source_username' => $this->firstCtripReviewMatchText($review, ['userName', 'user_name', 'sourceUsername', 'source_username', 'username', 'nickName', 'nick_name']),
            'user_avatar_url' => $this->firstCtripReviewMatchText($review, ['userIcon', 'user_icon', 'avatar', 'avatarUrl', 'avatar_url']),
            'review_date' => $this->nullableCtripReviewMatchDate($this->firstCtripReviewMatchText($review, ['addtime', 'commentTime', 'comment_time', 'reviewTime', 'review_time', 'createTime', 'create_time', 'submitTime', 'submit_time', 'date'])),
            'checkin_date' => $this->nullableCtripReviewMatchDate($this->firstCtripReviewMatchText($review, ['checkinTimeStr', 'checkInDate', 'check_in_date', 'checkinDate', 'arrivalDate', 'arrival_date', 'stayDate', 'stay_date'])),
            'room_name' => $this->firstCtripReviewMatchText($review, ['hotelRoomInfo', 'hotel_room_info', 'roomName', 'room_name', 'roomType', 'room_type', 'productName', 'ratePlanName']),
            'score' => $this->firstCtripReviewMatchNumber($review, ['avgScore', 'score', 'rating', 'rate', 'totalScore', 'overallScore', 'commentScore', 'star']),
            'content' => $this->firstCtripReviewMatchText($review, ['content', 'comment', 'commentContent', 'reviewContent', 'contentText', 'text']),
            'raw_review_json' => $this->encodeCtripReviewMatchJson($review),
            'update_time' => date('Y-m-d H:i:s'),
        ];

        $existing = Db::name('ota_ctrip_reviews')
            ->where('system_hotel_id', $systemHotelId)
            ->where('comment_id', $commentId)
            ->find();
        if ($existing) {
            Db::name('ota_ctrip_reviews')->where('id', (int)$existing['id'])->update($row);
            return (int)$existing['id'];
        }

        $row['create_time'] = date('Y-m-d H:i:s');
        return (int)Db::name('ota_ctrip_reviews')->insertGetId($row);
    }

    /**
     * @param array<string, mixed> $session
     */
    private function upsertCtripReviewMatchImSession(int $systemHotelId, array $session): int
    {
        $groupId = trim((string)($session['groupId'] ?? $session['group_id'] ?? ''));
        $members = $session['members'] ?? null;
        if ($groupId === '' || !is_array($members)) {
            return 0;
        }

        $service = new CtripReviewOrderMatchService();
        $memberIndex = $service->buildMemberIndex([['members' => $members]]);
        $guest = $memberIndex === [] ? null : reset($memberIndex);
        $arrivalDate = $this->normalizeCtripReviewMatchDate($session['arrivalDate'] ?? $session['arrival_date'] ?? $session['checkDate'] ?? $session['check_date'] ?? '');
        $roomName = $this->firstCtripReviewMatchText($session, ['roomName', 'room_name', 'roomNamePrefix', 'room_name_prefix', 'roomType']);
        $row = [
            'system_hotel_id' => $systemHotelId,
            'group_id' => $groupId,
            'session_id' => $this->firstCtripReviewMatchText($session, ['sessionId', 'session_id', 'conversationId']),
            'guest_uid' => $guest['uid'] ?? '',
            'guest_name' => $guest['nick_name'] ?? '',
            'guest_avatar_url' => $guest['avatar_url'] ?? $this->firstCtripReviewMatchText($session, ['avatar', 'avatarUrl', 'avatar_url']),
            'arrival_date' => $arrivalDate !== '' ? $arrivalDate : null,
            'departure_date' => $this->nullableCtripReviewMatchDate($this->firstCtripReviewMatchText($session, ['departureDate', 'departure_date', 'checkOutDate', 'check_out_date'])),
            'room_name' => $roomName,
            'room_name_prefix' => $this->roomPrefix($roomName),
            'order_id' => $this->firstCtripReviewMatchText($session, ['orderId', 'order_id', 'platform_order_id']),
            'match_status' => $guest ? ($arrivalDate !== '' ? 'usable' : 'degraded_missing_arrival_date') : 'unusable_no_guest_member',
            'members_json' => $this->encodeCtripReviewMatchJson($members),
            'evidence_json' => $this->encodeCtripReviewMatchJson([
                'source' => 'ctrip_query_message_list',
                'scope' => 'ctrip_ota_channel',
                'member_count' => count($members),
                'eligible_guest_member_count' => count($memberIndex),
            ]),
            'fetched_at' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
        ];

        $existing = Db::name('ota_ctrip_im_sessions')
            ->where('system_hotel_id', $systemHotelId)
            ->where('group_id', $groupId)
            ->find();
        if ($existing) {
            Db::name('ota_ctrip_im_sessions')->where('id', (int)$existing['id'])->update($row);
            return (int)$existing['id'];
        }

        $row['create_time'] = date('Y-m-d H:i:s');
        return (int)Db::name('ota_ctrip_im_sessions')->insertGetId($row);
    }

    /**
     * @param array<string, mixed> $order
     */
    private function upsertCtripReviewMatchOrder(int $systemHotelId, array $order): int
    {
        $orderId = $this->firstCtripReviewMatchText($order, ['orderId', 'order_id', 'platform_order_id', 'bookingOrderId', 'reservationOrderId']);
        if ($orderId === '') {
            return 0;
        }

        $roomName = $this->firstCtripReviewMatchText($order, ['roomName', 'room_name', 'roomType', 'room_type', 'productName']);
        $row = [
            'system_hotel_id' => $systemHotelId,
            'order_id' => $orderId,
            'guest_uid' => $this->firstCtripReviewMatchText($order, ['guestUid', 'guest_uid', 'ctrip_guest_uid', 'memberUid', 'member_uid', 'uid']),
            'guest_name' => $this->firstCtripReviewMatchText($order, ['guestName', 'guest_name', 'customerName', 'customer_name', 'contactName']),
            'arrival_date' => $this->nullableCtripReviewMatchDate($this->firstCtripReviewMatchText($order, ['arrivalDate', 'arrival_date', 'checkInDate', 'check_in_date', 'stayDate'])),
            'departure_date' => $this->nullableCtripReviewMatchDate($this->firstCtripReviewMatchText($order, ['departureDate', 'departure_date', 'checkOutDate', 'check_out_date'])),
            'room_name' => $roomName,
            'room_name_prefix' => $this->roomPrefix($roomName),
            'order_status' => $this->firstCtripReviewMatchText($order, ['orderStatus', 'order_status', 'status']),
            'source_platform' => 'ctrip',
            'raw_order_json' => $this->encodeCtripReviewMatchJson($order),
            'update_time' => date('Y-m-d H:i:s'),
        ];

        $existing = Db::name('ota_ctrip_orders')
            ->where('system_hotel_id', $systemHotelId)
            ->where('order_id', $orderId)
            ->find();
        if ($existing) {
            Db::name('ota_ctrip_orders')->where('id', (int)$existing['id'])->update($row);
            return (int)$existing['id'];
        }

        $row['create_time'] = date('Y-m-d H:i:s');
        return (int)Db::name('ota_ctrip_orders')->insertGetId($row);
    }

    /**
     * @param array<string, mixed> $requestData
     */
    private function resolveCtripReviewMatchHotelId(array $requestData): ?int
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
    private function resolveCtripReviewForLookup(int $systemHotelId, array $data): array
    {
        if (isset($data['review']) && is_array($data['review'])) {
            return $data['review'];
        }

        $commentId = trim((string)($data['commentId'] ?? $data['comment_id'] ?? ''));
        if ($commentId === '') {
            return [];
        }

        $row = Db::name('ota_ctrip_reviews')
            ->where('system_hotel_id', $systemHotelId)
            ->where('comment_id', $commentId)
            ->find();
        if (!$row) {
            return [];
        }

        return [
            'commentId' => (string)$row['comment_id'],
            'userName' => (string)$row['source_username'],
            'userIcon' => (string)$row['user_avatar_url'],
            'checkinTimeStr' => (string)($row['checkin_date'] ?? ''),
            'hotelRoomInfo' => (string)$row['room_name'],
            'avgScore' => $row['score'] ?? null,
            'content' => (string)$row['content'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadCtripReviewsForMatch(int $systemHotelId, int $limit): array
    {
        $rows = Db::name('ota_ctrip_reviews')
            ->where('system_hotel_id', $systemHotelId)
            ->order('review_date', 'desc')
            ->order('id', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();

        return array_map(static function (array $row): array {
            return [
                'commentId' => (string)$row['comment_id'],
                'userName' => (string)$row['source_username'],
                'userIcon' => (string)$row['user_avatar_url'],
                'checkinTimeStr' => (string)($row['checkin_date'] ?? ''),
                'hotelRoomInfo' => (string)$row['room_name'],
                'avgScore' => $row['score'] ?? null,
                'content' => (string)$row['content'],
            ];
        }, $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadCtripReviewImSessions(int $systemHotelId): array
    {
        $rows = Db::name('ota_ctrip_im_sessions')
            ->where('system_hotel_id', $systemHotelId)
            ->select()
            ->toArray();

        return array_map(function (array $row): array {
            return [
                'groupId' => (string)$row['group_id'],
                'sessionId' => (string)$row['session_id'],
                'arrivalDate' => (string)($row['arrival_date'] ?? ''),
                'roomName' => (string)$row['room_name'],
                'members' => $this->decodeCtripReviewMatchJson((string)$row['members_json']),
            ];
        }, $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadCtripOrderPool(int $systemHotelId): array
    {
        $rows = Db::name('ota_ctrip_orders')
            ->where('system_hotel_id', $systemHotelId)
            ->select()
            ->toArray();

        return array_map(static function (array $row): array {
            return [
                'orderId' => (string)$row['order_id'],
                'guestUid' => (string)$row['guest_uid'],
                'guestName' => (string)$row['guest_name'],
                'arrivalDate' => (string)($row['arrival_date'] ?? ''),
                'departureDate' => (string)($row['departure_date'] ?? ''),
                'roomName' => (string)$row['room_name'],
                'orderStatus' => (string)$row['order_status'],
                'platform' => 'ctrip',
            ];
        }, $rows);
    }

    private function firstCtripOrderCoverageDate(int $systemHotelId): string
    {
        $date = Db::name('ota_ctrip_orders')
            ->where('system_hotel_id', $systemHotelId)
            ->whereNotNull('arrival_date')
            ->min('arrival_date');

        return $date ? (string)$date : '';
    }

    /**
     * @param array<string, mixed> $review
     * @param array<string, mixed> $result
     */
    private function saveCtripReviewMatchAttempt(int $systemHotelId, array $review, array $result, string $source): void
    {
        $commentId = $this->extractCtripReviewCommentId($review);
        if ($commentId === '') {
            return;
        }

        $order = is_array($result['order'] ?? null) ? $result['order'] : [];
        $identity = is_array($result['identity'] ?? null) ? $result['identity'] : [];
        $row = [
            'system_hotel_id' => $systemHotelId,
            'comment_id' => $commentId,
            'order_id' => (string)($order['order_id'] ?? ''),
            'guest_uid' => (string)($identity['guest_uid'] ?? $order['guest_uid'] ?? ''),
            'guest_name' => (string)($identity['guest_name'] ?? $order['guest_name'] ?? ''),
            'match_status' => (string)($result['status'] ?? 'unmatched'),
            'match_method' => (string)($result['match_method'] ?? $source),
            'confidence' => (string)($result['confidence'] ?? 'none'),
            'candidate_orders_json' => $this->encodeCtripReviewMatchJson($result['candidates'] ?? []),
            'evidence_json' => $this->encodeCtripReviewMatchJson([
                'source' => $source,
                'scope' => 'ctrip_ota_channel',
                'result' => $result,
            ]),
            'update_time' => date('Y-m-d H:i:s'),
        ];

        $existing = Db::name('ota_ctrip_review_order_matches')
            ->where('system_hotel_id', $systemHotelId)
            ->where('comment_id', $commentId)
            ->find();
        if ($existing) {
            Db::name('ota_ctrip_review_order_matches')->where('id', (int)$existing['id'])->update($row);
            return;
        }

        $row['create_time'] = date('Y-m-d H:i:s');
        Db::name('ota_ctrip_review_order_matches')->insert($row);
    }

    /**
     * @param array<string, mixed> $review
     */
    private function extractCtripReviewCommentId(array $review): string
    {
        foreach (['commentId', 'comment_id', 'id', 'reviewId', 'review_id'] as $field) {
            if (isset($review[$field]) && trim((string)$review[$field]) !== '') {
                return trim((string)$review[$field]);
            }
        }
        return '';
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $fields
     */
    private function firstCtripReviewMatchText(array $data, array $fields): string
    {
        foreach ($fields as $field) {
            if (isset($data[$field]) && trim((string)$data[$field]) !== '') {
                return trim((string)$data[$field]);
            }
        }
        return '';
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $fields
     */
    private function firstCtripReviewMatchNumber(array $data, array $fields): ?float
    {
        foreach ($fields as $field) {
            if (isset($data[$field]) && is_numeric($data[$field])) {
                return (float)$data[$field];
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $fields
     * @return array<int|string, mixed>|null
     */
    private function firstCtripReviewMatchArray(array $data, array $fields): ?array
    {
        foreach ($fields as $field) {
            if (isset($data[$field]) && is_array($data[$field])) {
                return $data[$field];
            }
        }
        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string> $identityFields
     * @return array<int, array<string, mixed>>
     */
    private function dedupeCtripReviewMatchRows(array $rows, array $identityFields): array
    {
        $seen = [];
        $deduped = [];
        foreach ($rows as $row) {
            $identity = $this->firstCtripReviewMatchText($row, $identityFields);
            if ($identity === '') {
                $identity = md5($this->encodeCtripReviewMatchJson($row));
            }
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $deduped[] = $row;
        }
        return $deduped;
    }

    private function nullableCtripReviewMatchDate($value): ?string
    {
        $date = $this->normalizeCtripReviewMatchDate($value);
        return $date === '' ? null : $date;
    }

    private function normalizeCtripReviewMatchDate($value): string
    {
        $text = trim((string)$value);
        if ($text === '') {
            return '';
        }
        if (preg_match('/(20\d{2})[-\/.年](\d{1,2})[-\/.月](\d{1,2})/u', $text, $matches)) {
            return sprintf('%04d-%02d-%02d', (int)$matches[1], (int)$matches[2], (int)$matches[3]);
        }
        $timestamp = strtotime($text);
        return $timestamp === false ? '' : date('Y-m-d', $timestamp);
    }

    private function roomPrefix(string $roomName): string
    {
        $roomName = trim($roomName);
        if ($roomName === '') {
            return '';
        }

        return trim((string)preg_split('/[-（(]/u', $roomName)[0]);
    }

    private function encodeCtripReviewMatchJson($value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }

    /**
     * @return array<string, mixed>|array<int, mixed>
     */
    private function decodeCtripReviewMatchJson(string $value): array
    {
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
