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
                'source_username' => $this->firstCtripReviewMatchText($review, ['userName', 'user_name', 'sourceUsername', 'source_username', 'username', 'nickName', 'nick_name', 'user_name_masked']),
                'user_avatar_url' => $this->firstCtripReviewMatchText($review, ['userIcon', 'user_icon', 'avatar', 'avatarUrl', 'avatar_url']),
                'review_date' => $this->nullableCtripReviewMatchDate($this->firstCtripReviewMatchText($review, ['addtime', 'commentTime', 'comment_time', 'reviewTime', 'review_time', 'reviewDate', 'review_date', 'createTime', 'create_time', 'submitTime', 'submit_time', 'date'])),
                'checkin_date' => $this->nullableCtripReviewMatchDate($this->firstCtripReviewMatchText($review, ['checkinTimeStr', 'checkInDate', 'check_in_date', 'checkinDate', 'arrivalDate', 'arrival_date', 'stayDate', 'stay_date'])),
                'room_name' => $this->firstCtripReviewMatchText($review, ['hotelRoomInfo', 'hotel_room_info', 'roomName', 'room_name', 'roomType', 'room_type', 'room_type_name', 'productName', 'product_name', 'ratePlanName', 'rate_plan_name']),
                'score' => $this->firstCtripReviewMatchNumber($review, ['avgScore', 'avg_score', 'score', 'rating', 'rate', 'totalScore', 'total_score', 'overallScore', 'overall_score', 'commentScore', 'comment_score', 'star']),
                'content' => $this->firstCtripReviewMatchText($review, ['content', 'comment', 'commentContent', 'comment_content', 'reviewContent', 'review_content', 'contentText', 'content_text', 'commentText', 'comment_text', 'reviewText', 'review_text', 'text', '_dom_text']),
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
            $orderId = trim((string)($order['orderId'] ?? $order['order_id'] ?? $order['orderNo'] ?? $order['order_no'] ?? $order['orderSn'] ?? $order['order_sn'] ?? $order['platform_order_id'] ?? $order['bookingOrderId'] ?? $order['booking_order_id'] ?? $order['reservationOrderId'] ?? $order['reservation_order_id'] ?? ''));
            if ($orderId === '') {
                return $this->error('缺少携程订单号', 422);
            }

            $row = [
                'system_hotel_id' => $systemHotelId,
                'order_id' => $orderId,
                'guest_uid' => trim((string)($order['guestUid'] ?? $order['guest_uid'] ?? $order['ctrip_guest_uid'] ?? $order['memberUid'] ?? $order['member_uid'] ?? $order['uid'] ?? '')),
                'guest_name' => trim((string)($order['guestName'] ?? $order['guest_name'] ?? $order['customerName'] ?? $order['customer_name'] ?? $order['contactName'] ?? $order['contact_name'] ?? '')),
                'arrival_date' => $this->nullableCtripReviewMatchDate($order['arrivalDate'] ?? $order['arrival_date'] ?? $order['checkInDate'] ?? $order['check_in_date'] ?? $order['checkIn'] ?? $order['check_in'] ?? $order['checkinTime'] ?? $order['checkin_time'] ?? $order['checkInTime'] ?? $order['check_in_time'] ?? $order['stayDate'] ?? $order['stay_date'] ?? null),
                'departure_date' => $this->nullableCtripReviewMatchDate($order['departureDate'] ?? $order['departure_date'] ?? $order['checkOutDate'] ?? $order['check_out_date'] ?? $order['checkOut'] ?? $order['check_out'] ?? $order['checkoutTime'] ?? $order['checkout_time'] ?? $order['checkOutTime'] ?? $order['check_out_time'] ?? null),
                'room_name' => trim((string)($order['roomName'] ?? $order['room_name'] ?? $order['roomType'] ?? $order['room_type'] ?? $order['room_type_name'] ?? $order['productName'] ?? $order['product_name'] ?? $order['ratePlanName'] ?? $order['rate_plan_name'] ?? '')),
                'room_name_prefix' => $this->roomPrefix((string)($order['roomName'] ?? $order['room_name'] ?? $order['roomType'] ?? $order['room_type'] ?? $order['room_type_name'] ?? $order['productName'] ?? $order['product_name'] ?? $order['ratePlanName'] ?? $order['rate_plan_name'] ?? '')),
                'order_status' => trim((string)($order['orderStatus'] ?? $order['order_status'] ?? $order['orderState'] ?? $order['order_state'] ?? $order['status'] ?? '')),
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

        $dryRunTransactionStarted = false;
        try {
            $data = $this->requestData();
            $systemHotelId = $this->resolveCtripReviewMatchHotelId($data);
            if (!$systemHotelId) {
                return $this->error('请选择酒店', 400);
            }

            $rawLimit = max(0, min(100, (int)($data['raw_limit'] ?? $data['rawLimit'] ?? 30)));
            $reviewLimit = max(1, min(500, (int)($data['review_limit'] ?? $data['reviewLimit'] ?? 200)));
            $preflightOnly = in_array(strtolower(trim((string)($data['preflight_only'] ?? $data['preflightOnly'] ?? ''))), ['1', 'true', 'yes', 'on'], true);
            $dryRun = in_array(strtolower(trim((string)($data['dry_run'] ?? $data['dryRun'] ?? ''))), ['1', 'true', 'yes', 'on'], true);
            $requestPayloads = $this->extractCtripReviewMatchRequestPayloads($data);
            foreach ($requestPayloads as $payloadForGuard) {
                $this->assertCtripReviewMatchPayloadHasNoPlaceholders($payloadForGuard);
            }
            $payloadPreflight = $this->buildCtripReviewMatchPayloadPreflight($requestPayloads);
            if ($preflightOnly) {
                $preflightReady = (bool)($payloadPreflight['ready_for_match_attempt'] ?? false);
                return $this->success([
                    'status' => $preflightReady ? 'preflight_ready' : 'preflight_blocked',
                    'scope' => 'ctrip_ota_channel',
                    'payload_preflight' => $payloadPreflight,
                    'source_status' => [
                        'scope' => 'ctrip_ota_channel',
                        'detail_sources_ready' => false,
                        'request_payloads_scanned' => count($requestPayloads),
                        'request_payload_preflight_status' => (string)($payloadPreflight['status'] ?? 'unknown'),
                        'policy' => 'authorized_payload_preflight_only',
                        'storage_write' => false,
                        'transaction' => 'not_started',
                    ],
                    'next_action' => $preflightReady
                        ? '预检通过；可先执行干跑，确认匹配结果后再入库'
                        : '补齐 payload_preflight.blocking_gaps 后再导入匹配',
                ], $preflightReady ? '携程评价匹配 payload 预检通过' : '携程评价匹配 payload 预检未通过');
            }
            if ($dryRun) {
                Db::startTrans();
                $dryRunTransactionStarted = true;
            }
            $importSummary = $this->mergeCtripReviewMatchImportSummary(
                $this->importCtripReviewMatchRequestPayload($systemHotelId, $data),
                $this->importCtripReviewMatchRawRecords($systemHotelId, $rawLimit)
            );

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
                        'source_username' => $this->firstCtripReviewMatchText($review, ['userName', 'user_name', 'sourceUsername', 'source_username', 'username', 'nickName', 'nick_name', 'user_name_masked']),
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
                'mode' => $dryRun ? 'dry_run' : 'execute',
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
                'payload_preflight' => $payloadPreflight,
                'missing_sources' => $missingSources,
                'source_status' => [
                    'scope' => 'ctrip_ota_channel',
                    'detail_sources_ready' => $ready,
                    'source_tables' => [
                        'ctrip_reviews' => count($reviews),
                        'ctrip_im_sessions' => count($imSessions),
                        'ctrip_orders' => count($orders),
                    ],
                    'raw_records_scanned' => (int)($importSummary['raw_records_scanned'] ?? 0),
                    'request_payloads_scanned' => (int)($importSummary['request_payloads_scanned'] ?? 0),
                    'request_payload_preflight_status' => (string)($payloadPreflight['status'] ?? 'unknown'),
                    'policy' => 'authorized_import_or_existing_cache_only',
                    'storage_write' => !$dryRun,
                    'transaction' => $dryRun ? 'rolled_back' : 'not_wrapped',
                ],
                'samples' => $samples,
                'next_action' => $ready
                    ? '查看 matched/person_locked 样本，person_locked 需要运营确认订单'
                    : '导入已授权的携程评价明细、IM members 和订单池缓存后，再重新运行自动匹配',
            ];

            if ($dryRun) {
                Db::rollback();
                $dryRunTransactionStarted = false;
                return $this->success(
                    $payload,
                    $ready ? '携程评价订单干跑匹配完成：未入库' : '携程评价订单干跑匹配未完成：缺少必要数据源'
                );
            }

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
            if ($dryRunTransactionStarted) {
                Db::rollback();
            }
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getStatusCode()));
        } catch (\InvalidArgumentException $e) {
            if ($dryRunTransactionStarted) {
                Db::rollback();
            }
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            if ($dryRunTransactionStarted) {
                Db::rollback();
            }
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

    public function checkCtripReviewOrderMatchClosure(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        try {
            $data = $this->requestData();
            $systemHotelId = $this->resolveCtripReviewMatchHotelId($data);
            if (!$systemHotelId) {
                return $this->error('请选择酒店', 400);
            }

            $minMatched = max(0, min(1000, (int)($data['min_matched'] ?? $data['minMatched'] ?? 1)));
            $payload = $this->buildCtripReviewMatchClosureStatus($systemHotelId, $minMatched);
            $ready = ($payload['status'] ?? '') === 'completed';

            return $this->success(
                $payload,
                $ready ? '携程评价订单匹配闭环已完成' : '携程评价订单匹配闭环未完成'
            );
        } catch (\think\exception\HttpException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getStatusCode()));
        } catch (\Throwable $e) {
            return $this->error('携程评价订单匹配闭环检查失败: ' . $e->getMessage(), 500);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCtripReviewMatchClosureStatus(int $systemHotelId, int $minMatched = 1): array
    {
        $sourceTables = [
            'ctrip_reviews' => (int)Db::name('ota_ctrip_reviews')->where('system_hotel_id', $systemHotelId)->count(),
            'ctrip_im_sessions' => (int)Db::name('ota_ctrip_im_sessions')->where('system_hotel_id', $systemHotelId)->count(),
            'ctrip_orders' => (int)Db::name('ota_ctrip_orders')->where('system_hotel_id', $systemHotelId)->count(),
            'ctrip_review_order_matches' => (int)Db::name('ota_ctrip_review_order_matches')->where('system_hotel_id', $systemHotelId)->count(),
        ];

        $statusRows = Db::name('ota_ctrip_review_order_matches')
            ->where('system_hotel_id', $systemHotelId)
            ->field('match_status, COUNT(*) AS total')
            ->group('match_status')
            ->select()
            ->toArray();

        $statusCounts = [];
        foreach ($statusRows as $row) {
            $status = trim((string)($row['match_status'] ?? ''));
            $statusCounts[$status !== '' ? $status : 'unknown'] = (int)($row['total'] ?? 0);
        }

        $matchedCount = (int)($statusCounts['found'] ?? 0) + (int)($statusCounts['matched'] ?? 0);
        $missingSources = [];
        foreach (['ctrip_reviews', 'ctrip_im_sessions', 'ctrip_orders'] as $key) {
            if (($sourceTables[$key] ?? 0) <= 0) {
                $missingSources[] = $key;
            }
        }
        if ($matchedCount < $minMatched) {
            $missingSources[] = 'matched_results';
        }

        $ready = $missingSources === [];
        return [
            'mode' => 'closure_check',
            'status' => $ready ? 'completed' : 'not_ready',
            'scope' => 'ctrip_ota_channel',
            'summary' => [
                'review_count' => $sourceTables['ctrip_reviews'],
                'im_session_count' => $sourceTables['ctrip_im_sessions'],
                'order_count' => $sourceTables['ctrip_orders'],
                'matched_count' => $matchedCount,
                'person_locked_count' => (int)($statusCounts['person_locked'] ?? 0),
                'needs_ops_count' => (int)($statusCounts['needs_ops'] ?? 0),
                'out_of_coverage_count' => (int)($statusCounts['out_of_coverage'] ?? 0),
            ],
            'status_counts' => $statusCounts,
            'missing_sources' => array_values(array_unique($missingSources)),
            'source_status' => [
                'scope' => 'ctrip_ota_channel',
                'detail_sources_ready' => $ready,
                'source_tables' => $sourceTables,
                'policy' => 'real_data_closure_check_only',
                'storage_write' => false,
                'transaction' => 'not_started',
            ],
            'required' => [
                'min_matched' => $minMatched,
                'required_sources' => ['ctrip_reviews', 'ctrip_im_sessions', 'ctrip_orders'],
                'accepted_match_statuses' => ['found', 'matched'],
            ],
            'next_action' => $ready
                ? '携程评价匹配闭环已由真实入库数据证明'
                : '导入真实授权的携程评价明细、IM members 和订单池，并执行入库匹配后重跑闭环检查',
        ];
    }

    /**
     * @return array<string, int>
     */
    private function importCtripReviewMatchRawRecords(int $systemHotelId, int $limit): array
    {
        $summary = [
            'raw_records_scanned' => 0,
            'request_payloads_scanned' => 0,
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
     * @param array<string, mixed> $data
     * @return array<int, array<string, mixed>>
     */
    private function extractCtripReviewMatchRequestPayloads(array $data): array
    {
        $candidates = [];
        foreach (['payload', 'capture_payload', 'raw_payload', 'rawPayload'] as $field) {
            if (array_key_exists($field, $data)) {
                $candidates[] = $data[$field];
            }
        }
        if (isset($data['payloads']) && is_array($data['payloads'])) {
            foreach ($data['payloads'] as $payload) {
                $candidates[] = $payload;
            }
        }

        $payloads = [];
        foreach ($candidates as $payload) {
            if (is_string($payload)) {
                $payload = json_decode($payload, true);
            }
            if (is_array($payload)) {
                $payloads[] = $payload;
            }
        }

        return $payloads;
    }

    /**
     * @param array<int, array<string, mixed>> $payloads
     * @return array<string, mixed>
     */
    private function buildCtripReviewMatchPayloadPreflight(array $payloads): array
    {
        $summary = [
            'status' => 'not_provided',
            'payloads_scanned' => count($payloads),
            'reviews_detected' => 0,
            'reviews_with_comment_id' => 0,
            'reviews_with_username' => 0,
            'reviews_with_stay_date' => 0,
            'im_sessions_detected' => 0,
            'im_sessions_with_guest_member' => 0,
            'im_sessions_with_arrival_date' => 0,
            'orders_detected' => 0,
            'orders_with_order_id' => 0,
            'orders_with_guest_identity' => 0,
            'orders_with_arrival_date' => 0,
            'missing_payload_sections' => [],
            'blocking_gaps' => [],
            'warning_gaps' => [],
            'ready_for_match_attempt' => false,
            'policy' => 'authorized_payload_preflight_no_storage_write',
        ];

        if ($payloads === []) {
            return $summary;
        }

        $service = new CtripReviewOrderMatchService();
        foreach ($payloads as $payload) {
            $reviews = $this->extractCtripCapturedComments($payload);
            $summary['reviews_detected'] += count($reviews);
            foreach ($reviews as $review) {
                if ($this->extractCtripReviewCommentId($review) !== '') {
                    $summary['reviews_with_comment_id']++;
                }
                if ($this->firstCtripReviewMatchText($review, ['userName', 'user_name', 'sourceUsername', 'source_username', 'username', 'nickName', 'nick_name', 'user_name_masked']) !== '') {
                    $summary['reviews_with_username']++;
                }
                $stayDate = $this->normalizeCtripReviewMatchDate($this->firstCtripReviewMatchText($review, ['checkinTimeStr', 'checkInDate', 'check_in_date', 'checkinDate', 'arrivalDate', 'arrival_date', 'stayDate', 'stay_date']));
                if ($stayDate !== '') {
                    $summary['reviews_with_stay_date']++;
                }
            }

            $imSessions = $this->extractCtripReviewMatchImSessionsFromPayload($payload);
            $summary['im_sessions_detected'] += count($imSessions);
            foreach ($imSessions as $session) {
                if ($service->buildMemberIndex([$session]) !== []) {
                    $summary['im_sessions_with_guest_member']++;
                }
                $arrivalDate = $this->normalizeCtripReviewMatchDate($session['arrivalDate'] ?? $session['arrival_date'] ?? $session['checkInDate'] ?? $session['check_in_date'] ?? $session['checkIn'] ?? $session['check_in'] ?? $session['checkinTime'] ?? $session['checkin_time'] ?? $session['checkInTime'] ?? $session['check_in_time'] ?? $session['checkDate'] ?? $session['check_date'] ?? $session['stayDate'] ?? $session['stay_date'] ?? '');
                if ($arrivalDate !== '') {
                    $summary['im_sessions_with_arrival_date']++;
                }
            }

            $orders = $this->extractCtripReviewMatchOrdersFromPayload($payload);
            $summary['orders_detected'] += count($orders);
            foreach ($orders as $order) {
                if ($this->firstCtripReviewMatchText($order, ['orderId', 'order_id', 'orderNo', 'order_no', 'orderSn', 'order_sn', 'platform_order_id', 'bookingOrderId', 'booking_order_id', 'reservationOrderId', 'reservation_order_id']) !== '') {
                    $summary['orders_with_order_id']++;
                }
                if (
                    $this->firstCtripReviewMatchText($order, ['guestUid', 'guest_uid', 'ctrip_guest_uid', 'memberUid', 'member_uid', 'uid']) !== ''
                    || $this->firstCtripReviewMatchText($order, ['guestName', 'guest_name', 'customerName', 'customer_name', 'contactName', 'contact_name']) !== ''
                ) {
                    $summary['orders_with_guest_identity']++;
                }
                $arrivalDate = $this->normalizeCtripReviewMatchDate($this->firstCtripReviewMatchText($order, ['arrivalDate', 'arrival_date', 'checkInDate', 'check_in_date', 'checkIn', 'check_in', 'checkinTime', 'checkin_time', 'checkInTime', 'check_in_time', 'stayDate', 'stay_date']));
                if ($arrivalDate !== '') {
                    $summary['orders_with_arrival_date']++;
                }
            }
        }

        $missingSections = [];
        if ((int)$summary['reviews_detected'] === 0) {
            $missingSections[] = 'reviews';
        }
        if ((int)$summary['im_sessions_detected'] === 0) {
            $missingSections[] = 'im_sessions';
        }
        if ((int)$summary['orders_detected'] === 0) {
            $missingSections[] = 'orders';
        }

        $blockingGaps = array_map(static fn(string $section): string => $section . '_missing', $missingSections);
        if ((int)$summary['reviews_detected'] > 0 && (int)$summary['reviews_with_comment_id'] === 0) {
            $blockingGaps[] = 'review_comment_id_missing';
        }
        if ((int)$summary['reviews_detected'] > 0 && (int)$summary['reviews_with_username'] === 0) {
            $blockingGaps[] = 'review_username_missing';
        }
        if ((int)$summary['im_sessions_detected'] > 0 && (int)$summary['im_sessions_with_guest_member'] === 0) {
            $blockingGaps[] = 'im_guest_member_missing';
        }
        if ((int)$summary['orders_detected'] > 0 && (int)$summary['orders_with_order_id'] === 0) {
            $blockingGaps[] = 'order_id_missing';
        }
        if ((int)$summary['orders_detected'] > 0 && (int)$summary['orders_with_guest_identity'] === 0) {
            $blockingGaps[] = 'order_guest_identity_missing';
        }

        $warningGaps = [];
        if ((int)$summary['reviews_detected'] > 0 && (int)$summary['reviews_with_stay_date'] === 0) {
            $warningGaps[] = 'review_stay_date_missing';
        }
        if ((int)$summary['im_sessions_detected'] > 0 && (int)$summary['im_sessions_with_arrival_date'] === 0) {
            $warningGaps[] = 'im_arrival_date_missing';
        }
        if ((int)$summary['orders_detected'] > 0 && (int)$summary['orders_with_arrival_date'] === 0) {
            $warningGaps[] = 'order_arrival_date_missing';
        }

        $summary['missing_payload_sections'] = $missingSections;
        $summary['blocking_gaps'] = array_values(array_unique($blockingGaps));
        $summary['warning_gaps'] = array_values(array_unique($warningGaps));
        $summary['ready_for_match_attempt'] = $missingSections === [] && $summary['blocking_gaps'] === [];
        $summary['status'] = $summary['ready_for_match_attempt']
            ? ($summary['warning_gaps'] === [] ? 'ready' : 'warning')
            : 'blocked';

        return $summary;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, int>
     */
    private function importCtripReviewMatchRequestPayload(int $systemHotelId, array $data): array
    {
        $summary = [
            'raw_records_scanned' => 0,
            'request_payloads_scanned' => 0,
            'reviews_upserted' => 0,
            'im_sessions_upserted' => 0,
            'orders_upserted' => 0,
        ];

        foreach ($this->extractCtripReviewMatchRequestPayloads($data) as $payload) {
            $this->assertCtripReviewMatchPayloadHasNoPlaceholders($payload);
            $summary['request_payloads_scanned']++;
            $imported = $this->importCtripReviewMatchPayload($systemHotelId, $payload);
            $summary['reviews_upserted'] += (int)$imported['reviews_upserted'];
            $summary['im_sessions_upserted'] += (int)$imported['im_sessions_upserted'];
            $summary['orders_upserted'] += (int)$imported['orders_upserted'];
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertCtripReviewMatchPayloadHasNoPlaceholders(array $payload): void
    {
        $paths = [];
        $this->collectCtripReviewMatchPayloadPlaceholders($payload, '', $paths);
        $paths = array_values(array_unique($paths));
        if ($paths === []) {
            return;
        }

        throw new \InvalidArgumentException(
            '授权 payload 仍包含模板占位符，请替换所有 replace-with-* 字段后再导入: '
            . implode(', ', array_slice($paths, 0, 12))
        );
    }

    /**
     * @param mixed $value
     * @param array<int, string> $paths
     */
    private function collectCtripReviewMatchPayloadPlaceholders($value, string $path, array &$paths): void
    {
        if (is_string($value)) {
            if (str_starts_with(trim($value), 'replace-with-')) {
                $paths[] = $path;
            }
            return;
        }

        if (!is_array($value)) {
            return;
        }

        foreach ($value as $key => $child) {
            $childPath = $path === '' ? (string)$key : $path . '.' . (string)$key;
            if (is_string($key) && str_starts_with(trim($key), 'replace-with-')) {
                $paths[] = $childPath . '.__key';
            }
            $this->collectCtripReviewMatchPayloadPlaceholders($child, $childPath, $paths);
        }
    }

    /**
     * @return array<string, int>
     */
    private function mergeCtripReviewMatchImportSummary(array ...$summaries): array
    {
        $merged = [
            'raw_records_scanned' => 0,
            'request_payloads_scanned' => 0,
            'reviews_upserted' => 0,
            'im_sessions_upserted' => 0,
            'orders_upserted' => 0,
        ];

        foreach ($summaries as $summary) {
            foreach ($merged as $key => $value) {
                $merged[$key] += (int)($summary[$key] ?? 0);
            }
        }

        return $merged;
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

        foreach (['INTERCEPTED_MEMBERS', 'intercepted_members', 'membersByGroupId', 'members_by_group_id'] as $field) {
            if (isset($value[$field]) && is_array($value[$field])) {
                $sessions = array_merge($sessions, $this->extractCtripReviewMatchImSessionsFromMemberMap($value[$field]));
            }
        }

        foreach ($value as $key => $item) {
            if ((is_string($key) || is_int($key)) && is_array($item) && $this->looksLikeCtripReviewMatchGroupKey((string)$key) && $this->looksLikeCtripReviewMatchMemberList($item)) {
                $sessions[] = [
                    'groupId' => (string)$key,
                    'members' => $item,
                    'source' => 'ctrip_intercepted_member_map',
                ];
            }
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
     * @param array<string|int, mixed> $memberMap
     * @return array<int, array<string, mixed>>
     */
    private function extractCtripReviewMatchImSessionsFromMemberMap(array $memberMap): array
    {
        $sessions = [];
        foreach ($memberMap as $groupId => $members) {
            if (!is_array($members) || !$this->looksLikeCtripReviewMatchGroupKey((string)$groupId) || !$this->looksLikeCtripReviewMatchMemberList($members)) {
                continue;
            }

            $sessions[] = [
                'groupId' => (string)$groupId,
                'members' => $members,
                'source' => 'ctrip_intercepted_member_map',
            ];
        }

        return $this->dedupeCtripReviewMatchRows($sessions, ['groupId']);
    }

    private function looksLikeCtripReviewMatchGroupKey(string $key): bool
    {
        $key = trim($key);
        if ($key === '' || strlen($key) < 6) {
            return false;
        }

        $blockedKeys = [
            'members',
            'memberlist',
            'member_list',
            'immembers',
            'users',
            'userlist',
            'list',
            'rows',
            'data',
            'result',
            'reviews',
            'orders',
            'orderlist',
            'order_list',
            'commentlist',
            'comment_list',
            'responses',
            'items',
        ];

        return !in_array(strtolower($key), $blockedKeys, true);
    }

    /**
     * @param array<string|int, mixed> $members
     */
    private function looksLikeCtripReviewMatchMemberList(array $members): bool
    {
        if ($members === []) {
            return false;
        }

        foreach ($members as $member) {
            if (is_array($member) && $this->looksLikeCtripReviewMatchMember($member)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $member
     */
    private function looksLikeCtripReviewMatchMember(array $member): bool
    {
        foreach (['uid', 'nickName', 'nickname', 'nick_name', 'name', 'roleType', 'role_type', 'pic', 'avatar', 'avatarUrl', 'avatar_url'] as $field) {
            if (isset($member[$field]) && trim((string)$member[$field]) !== '') {
                return true;
            }
        }

        return false;
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
            return $this->dedupeCtripReviewMatchRows($orders, ['orderId', 'order_id', 'orderNo', 'order_no', 'orderSn', 'order_sn', 'platform_order_id', 'bookingOrderId', 'booking_order_id', 'reservationOrderId', 'reservation_order_id']);
        }

        $orderId = $this->firstCtripReviewMatchText($value, ['orderId', 'order_id', 'orderNo', 'order_no', 'orderSn', 'order_sn', 'platform_order_id', 'bookingOrderId', 'booking_order_id', 'reservationOrderId', 'reservation_order_id']);
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

        return $this->dedupeCtripReviewMatchRows($orders, ['orderId', 'order_id', 'orderNo', 'order_no', 'orderSn', 'order_sn', 'platform_order_id', 'bookingOrderId', 'booking_order_id', 'reservationOrderId', 'reservation_order_id']);
    }

    /**
     * @param array<string, mixed> $order
     */
    private function looksLikeCtripReviewMatchOrder(array $order): bool
    {
        return $this->firstCtripReviewMatchText($order, ['guestUid', 'guest_uid', 'ctrip_guest_uid', 'memberUid', 'member_uid', 'uid']) !== ''
            || $this->firstCtripReviewMatchText($order, ['guestName', 'guest_name', 'customerName', 'customer_name', 'contactName', 'contact_name']) !== ''
            || $this->firstCtripReviewMatchText($order, ['arrivalDate', 'arrival_date', 'checkInDate', 'check_in_date', 'checkIn', 'check_in', 'checkinTime', 'checkin_time', 'checkInTime', 'check_in_time', 'stayDate', 'stay_date']) !== ''
            || $this->firstCtripReviewMatchText($order, ['roomName', 'room_name', 'roomType', 'room_type', 'room_type_name', 'productName', 'product_name', 'ratePlanName', 'rate_plan_name']) !== '';
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
            'source_username' => $this->firstCtripReviewMatchText($review, ['userName', 'user_name', 'sourceUsername', 'source_username', 'username', 'nickName', 'nick_name', 'user_name_masked']),
            'user_avatar_url' => $this->firstCtripReviewMatchText($review, ['userIcon', 'user_icon', 'avatar', 'avatarUrl', 'avatar_url']),
            'review_date' => $this->nullableCtripReviewMatchDate($this->firstCtripReviewMatchText($review, ['addtime', 'commentTime', 'comment_time', 'reviewTime', 'review_time', 'reviewDate', 'review_date', 'createTime', 'create_time', 'submitTime', 'submit_time', 'date'])),
            'checkin_date' => $this->nullableCtripReviewMatchDate($this->firstCtripReviewMatchText($review, ['checkinTimeStr', 'checkInDate', 'check_in_date', 'checkinDate', 'arrivalDate', 'arrival_date', 'stayDate', 'stay_date'])),
            'room_name' => $this->firstCtripReviewMatchText($review, ['hotelRoomInfo', 'hotel_room_info', 'roomName', 'room_name', 'roomType', 'room_type', 'room_type_name', 'productName', 'product_name', 'ratePlanName', 'rate_plan_name']),
            'score' => $this->firstCtripReviewMatchNumber($review, ['avgScore', 'avg_score', 'score', 'rating', 'rate', 'totalScore', 'total_score', 'overallScore', 'overall_score', 'commentScore', 'comment_score', 'star']),
            'content' => $this->firstCtripReviewMatchText($review, ['content', 'comment', 'commentContent', 'comment_content', 'reviewContent', 'review_content', 'contentText', 'content_text', 'commentText', 'comment_text', 'reviewText', 'review_text', 'text', '_dom_text']),
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
        $arrivalDate = $this->normalizeCtripReviewMatchDate($session['arrivalDate'] ?? $session['arrival_date'] ?? $session['checkInDate'] ?? $session['check_in_date'] ?? $session['checkIn'] ?? $session['check_in'] ?? $session['checkinTime'] ?? $session['checkin_time'] ?? $session['checkInTime'] ?? $session['check_in_time'] ?? $session['checkDate'] ?? $session['check_date'] ?? $session['stayDate'] ?? $session['stay_date'] ?? '');
        $roomName = $this->firstCtripReviewMatchText($session, ['roomName', 'room_name', 'roomNamePrefix', 'room_name_prefix', 'roomType', 'room_type', 'room_type_name', 'productName', 'product_name', 'ratePlanName', 'rate_plan_name']);
        $row = [
            'system_hotel_id' => $systemHotelId,
            'group_id' => $groupId,
            'session_id' => $this->firstCtripReviewMatchText($session, ['sessionId', 'session_id', 'conversationId']),
            'guest_uid' => $guest['uid'] ?? '',
            'guest_name' => $guest['nick_name'] ?? '',
            'guest_avatar_url' => $guest['avatar_url'] ?? $this->firstCtripReviewMatchText($session, ['avatar', 'avatarUrl', 'avatar_url']),
            'arrival_date' => $arrivalDate !== '' ? $arrivalDate : null,
            'departure_date' => $this->nullableCtripReviewMatchDate($this->firstCtripReviewMatchText($session, ['departureDate', 'departure_date', 'checkOutDate', 'check_out_date', 'checkOut', 'check_out', 'checkoutTime', 'checkout_time', 'checkOutTime', 'check_out_time'])),
            'room_name' => $roomName,
            'room_name_prefix' => $this->roomPrefix($roomName),
            'order_id' => $this->firstCtripReviewMatchText($session, ['orderId', 'order_id', 'orderNo', 'order_no', 'orderSn', 'order_sn', 'platform_order_id', 'bookingOrderId', 'booking_order_id', 'reservationOrderId', 'reservation_order_id']),
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
        $orderId = $this->firstCtripReviewMatchText($order, ['orderId', 'order_id', 'orderNo', 'order_no', 'orderSn', 'order_sn', 'platform_order_id', 'bookingOrderId', 'booking_order_id', 'reservationOrderId', 'reservation_order_id']);
        if ($orderId === '') {
            return 0;
        }

        $roomName = $this->firstCtripReviewMatchText($order, ['roomName', 'room_name', 'roomType', 'room_type', 'room_type_name', 'productName', 'product_name', 'ratePlanName', 'rate_plan_name']);
        $row = [
            'system_hotel_id' => $systemHotelId,
            'order_id' => $orderId,
            'guest_uid' => $this->firstCtripReviewMatchText($order, ['guestUid', 'guest_uid', 'ctrip_guest_uid', 'memberUid', 'member_uid', 'uid']),
            'guest_name' => $this->firstCtripReviewMatchText($order, ['guestName', 'guest_name', 'customerName', 'customer_name', 'contactName', 'contact_name']),
            'arrival_date' => $this->nullableCtripReviewMatchDate($this->firstCtripReviewMatchText($order, ['arrivalDate', 'arrival_date', 'checkInDate', 'check_in_date', 'checkIn', 'check_in', 'checkinTime', 'checkin_time', 'checkInTime', 'check_in_time', 'stayDate', 'stay_date'])),
            'departure_date' => $this->nullableCtripReviewMatchDate($this->firstCtripReviewMatchText($order, ['departureDate', 'departure_date', 'checkOutDate', 'check_out_date', 'checkOut', 'check_out', 'checkoutTime', 'checkout_time', 'checkOutTime', 'check_out_time'])),
            'room_name' => $roomName,
            'room_name_prefix' => $this->roomPrefix($roomName),
            'order_status' => $this->firstCtripReviewMatchText($order, ['orderStatus', 'order_status', 'orderState', 'order_state', 'status']),
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
            ->where('arrival_date', '<>', '0000-00-00')
            ->order('arrival_date', 'asc')
            ->value('arrival_date');

        return $date ? $this->normalizeCtripReviewMatchDate($date) : '';
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
        if (preg_match('/^20\d{2}$/', $text)) {
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
