<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\model\OperationLog;
use app\service\BrowserProfileCaptureRequestService;
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
                $row = $this->mergeCtripReviewMatchImSessionRow($row, $existing);
                Db::name('ota_ctrip_im_sessions')->where('id', (int)$existing['id'])->update($row);
                $id = (int)$existing['id'];
            } else {
                $row['create_time'] = date('Y-m-d H:i:s');
                $id = (int)Db::name('ota_ctrip_im_sessions')->insertGetId($row);
            }

            $orderCacheId = $this->upsertCtripReviewMatchOrderFromImSession($systemHotelId, $session);
            OperationLog::record('online_data', 'save_ctrip_review_im_session', '保存携程评价匹配 IM 会话: ' . $groupId, $this->currentUser->id ?? null, $systemHotelId);
            return $this->success([
                'id' => $id,
                'match_status' => $row['match_status'],
                'order_cache_id' => $orderCacheId,
                'order_cache_status' => $orderCacheId > 0 ? 'synced' : 'not_available',
            ], '携程 IM 会话缓存已保存');
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
                'guest_name' => trim((string)($order['guestName'] ?? $order['guest_name'] ?? $order['customerName'] ?? $order['customer_name'] ?? $order['contactName'] ?? $order['contact_name'] ?? $order['clientName'] ?? $order['client_name'] ?? '')),
                'arrival_date' => $this->nullableCtripReviewMatchDate($order['arrivalDate'] ?? $order['arrival_date'] ?? $order['arrival'] ?? $order['checkInDate'] ?? $order['check_in_date'] ?? $order['checkIn'] ?? $order['check_in'] ?? $order['checkinTime'] ?? $order['checkin_time'] ?? $order['checkInTime'] ?? $order['check_in_time'] ?? $order['stayDate'] ?? $order['stay_date'] ?? null),
                'departure_date' => $this->nullableCtripReviewMatchDate($order['departureDate'] ?? $order['departure_date'] ?? $order['departure'] ?? $order['checkOutDate'] ?? $order['check_out_date'] ?? $order['checkOut'] ?? $order['check_out'] ?? $order['checkoutTime'] ?? $order['checkout_time'] ?? $order['checkOutTime'] ?? $order['check_out_time'] ?? null),
                'room_name' => trim((string)($order['roomName'] ?? $order['room_name'] ?? $order['roomType'] ?? $order['room_type'] ?? $order['room_type_name'] ?? $order['productName'] ?? $order['product_name'] ?? $order['ratePlanName'] ?? $order['rate_plan_name'] ?? '')),
                'room_name_prefix' => $this->roomPrefix((string)($order['roomName'] ?? $order['room_name'] ?? $order['roomType'] ?? $order['room_type'] ?? $order['room_type_name'] ?? $order['productName'] ?? $order['product_name'] ?? $order['ratePlanName'] ?? $order['rate_plan_name'] ?? '')),
                'order_status' => trim((string)($order['orderStatus'] ?? $order['order_status'] ?? $order['orderStatusDesc'] ?? $order['order_status_desc'] ?? $order['orderState'] ?? $order['order_state'] ?? $order['status'] ?? '')),
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

            return $this->success(
                $this->buildCtripReviewOrderMatchPublicResult($review, $result),
                '携程评价订单反查完成'
            );
        } catch (\think\exception\HttpException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getStatusCode()));
        } catch (\Throwable $e) {
            return $this->error('携程评价订单反查失败: ' . $e->getMessage(), 500);
        }
    }

    public function previewCtripReviewOrdererIdentity(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_view_online_data');

        try {
            $data = $this->requestData();
            $systemHotelId = $this->resolveCtripReviewMatchHotelId($data);
            if (!$systemHotelId) {
                return $this->error('请选择酒店', 400);
            }

            $review = $this->buildCtripReviewOrdererPreviewReview($data);
            $imSessions = $this->loadCtripReviewImSessions($systemHotelId);
            $orders = $this->loadCtripOrderPool($systemHotelId);
            $service = new CtripReviewOrderMatchService();
            $identityResult = $service->matchReviewIdentity($review, $imSessions);
            $result = $service->matchReviewToOrder(
                $review,
                $imSessions,
                $orders,
                ['coverage_start_date' => $this->firstCtripOrderCoverageDate($systemHotelId)]
            );
            if (($result['status'] ?? '') === 'out_of_coverage' && ($identityResult['status'] ?? '') === 'person_locked') {
                $result['identity'] = $identityResult['identity'] ?? null;
                $result['confidence'] = $identityResult['confidence'] ?? $result['confidence'] ?? 'none';
                $result['identity_preview_status'] = 'person_locked';
            }

            $identity = is_array($result['identity'] ?? null) ? $result['identity'] : null;
            $order = is_array($result['order'] ?? null) ? $result['order'] : null;
            $guestName = trim((string)($identity['guest_name'] ?? $result['person_name'] ?? ''));
            $status = (string)($result['status'] ?? 'unknown');
            $orderLabel = $order
                ? '已查到订单'
                : ($guestName !== '' ? '已锁定客人，待订单池复核' : '未查到订单');

            return $this->success([
                'mode' => 'identity_preview',
                'scope' => 'ctrip_ota_channel',
                'status' => $status,
                'reason' => (string)($result['reason'] ?? ''),
                'confidence' => (string)($result['confidence'] ?? 'none'),
                'source_username' => $this->firstCtripReviewMatchText($review, ['userName', 'user_name', 'sourceUsername', 'source_username', 'username', 'nickName', 'nick_name', 'user_name_masked']),
                'display_label' => '疑似下单人',
                'display_text' => $guestName !== '' ? ('疑似下单人：' . $guestName) : '未匹配到下单人',
                'order_label' => $orderLabel,
                'identity' => $identity,
                'order' => $order,
                'result' => $result,
                'storage_write' => false,
                'source_status' => [
                    'scope' => 'ctrip_ota_channel',
                    'policy' => 'authorized_page_identity_preview_only',
                    'review_collection_policy' => 'policy_disabled',
                    'storage_write' => false,
                    'im_session_count' => count($imSessions),
                    'order_count' => count($orders),
                ],
            ], '携程评价疑似下单人预览完成');
        } catch (\think\exception\HttpException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getStatusCode()));
        } catch (\Throwable $e) {
            return $this->error('携程评价疑似下单人预览失败: ' . $e->getMessage(), 500);
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
            $autoCaptureSummary = $this->emptyCtripReviewMatchAutoCaptureSummary();

            $reviews = $this->loadCtripReviewsForMatch($systemHotelId, $reviewLimit);
            $imSessions = $this->loadCtripReviewImSessions($systemHotelId);
            $orders = $this->loadCtripOrderPool($systemHotelId);
            if (
                !$dryRun
                && $this->shouldAutoCaptureCtripReviewMatchReviews($data)
            ) {
                $autoCaptureSummary = $this->captureCtripReviewMatchReviewsFromAuthorizedProfile($systemHotelId, $data);
                $importSummary = $this->mergeCtripReviewMatchImportSummary($importSummary, [
                    'raw_records_scanned' => 0,
                    'request_payloads_scanned' => (int)($autoCaptureSummary['payloads_scanned'] ?? 0),
                    'reviews_upserted' => (int)($autoCaptureSummary['reviews_upserted'] ?? 0),
                    'im_sessions_upserted' => (int)($autoCaptureSummary['im_sessions_upserted'] ?? 0),
                    'orders_upserted' => (int)($autoCaptureSummary['orders_upserted'] ?? 0),
                ]);
                $reviews = $this->loadCtripReviewsForMatch($systemHotelId, $reviewLimit);
                $imSessions = $this->loadCtripReviewImSessions($systemHotelId);
                $orders = $this->loadCtripOrderPool($systemHotelId);
            }
            $imOrderCacheSynced = $this->syncCtripReviewOrdersFromImSessionCache($systemHotelId);
            if ($imOrderCacheSynced > 0) {
                $importSummary['orders_upserted'] = (int)($importSummary['orders_upserted'] ?? 0) + $imOrderCacheSynced;
                $orders = $this->loadCtripOrderPool($systemHotelId);
            }
            $coverageStartDate = $this->firstCtripOrderCoverageDate($systemHotelId);
            $service = new CtripReviewOrderMatchService();
            $statusCounts = [];
            $samples = [];
            $multiReviewResolution = ['resolved_count' => 0, 'assignments' => []];

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
                    $candidateOrder = $this->firstPublicCtripReviewCandidateOrder(
                        is_array($result['candidates'] ?? null) ? $result['candidates'] : []
                    );
                    $candidateCount = is_array($result['candidates'] ?? null) ? count($result['candidates']) : 0;
                    $samples[] = [
                        'comment_id' => $this->extractCtripReviewCommentId($review),
                        'source_username' => $this->firstCtripReviewMatchText($review, ['userName', 'user_name', 'sourceUsername', 'source_username', 'username', 'nickName', 'nick_name', 'user_name_masked']),
                        'status' => $status,
                        'status_text' => $this->publicCtripReviewMatchStatusText(
                            $status,
                            (string)($result['order']['order_id'] ?? ''),
                            (string)($result['identity']['guest_name'] ?? $result['person_name'] ?? ''),
                            $candidateCount
                        ),
                        'order_id' => (string)($result['order']['order_id'] ?? ''),
                        'guest_name' => (string)($result['identity']['guest_name'] ?? $result['person_name'] ?? ''),
                        'candidate_count' => $candidateCount,
                        'candidate_order_id' => (string)($candidateOrder['order_id'] ?? ''),
                        'candidate_guest_name' => (string)($candidateOrder['guest_name'] ?? ''),
                        'candidate_arrival_date' => (string)($candidateOrder['arrival_date'] ?? ''),
                        'reason' => (string)($result['reason'] ?? ''),
                    ];
                }
            }
            $multiReviewResolution = $this->resolveCtripReviewMultiReviewOrderAssignments($systemHotelId, $reviews, 'automation_multi_review');
            $statusCounts = $this->applyCtripReviewMultiReviewResolutionToStatusCounts($statusCounts, $multiReviewResolution);
            $samples = $this->applyCtripReviewMultiReviewResolutionToSamples($samples, $multiReviewResolution);

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
                    'multi_review_resolved_count' => (int)($multiReviewResolution['resolved_count'] ?? 0),
                ],
                'status_counts' => $statusCounts,
                'import' => $importSummary,
                'auto_capture' => $autoCaptureSummary,
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
                    'auto_capture_status' => (string)($autoCaptureSummary['status'] ?? 'not_attempted'),
                    'request_payload_preflight_status' => (string)($payloadPreflight['status'] ?? 'unknown'),
                    'im_order_cache_synced' => $imOrderCacheSynced,
                    'policy' => 'explicit_review_match_authorized_profile_or_existing_cache',
                    'storage_write' => !$dryRun,
                    'transaction' => $dryRun ? 'rolled_back' : 'not_wrapped',
                ],
                'samples' => $samples,
                'review_cards' => $dryRun ? $samples : $this->loadCtripReviewMatchReviewCards($systemHotelId, 30),
                'next_action' => $ready
                    ? '查看 matched/person_locked 样本，person_locked 需要运营确认订单'
                    : '请确认携程授权 Profile 登录有效，并补齐评价、IM 会话和订单明细后重新运行自动匹配',
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
            'ctrip_im_sessions' => count($this->loadCtripReviewImSessions($systemHotelId)),
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
            'samples' => $this->loadCtripReviewMatchRecentSamples($systemHotelId, 20),
            'review_cards' => $this->loadCtripReviewMatchReviewCards($systemHotelId, 30),
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
            'next_commands' => $ready ? [] : $this->buildCtripReviewMatchClosureNextCommands($systemHotelId),
            'next_action' => $ready
                ? '携程评价匹配闭环已由真实入库数据证明'
                : '导入真实授权的携程评价明细、IM members 和订单池，并执行入库匹配后重跑闭环检查',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadCtripReviewMatchRecentSamples(int $systemHotelId, int $limit = 20): array
    {
        $rows = Db::name('ota_ctrip_review_order_matches')
            ->where('system_hotel_id', $systemHotelId)
            ->field('comment_id, order_id, guest_name, match_status, confidence, candidate_orders_json, update_time')
            ->order('update_time', 'desc')
            ->order('id', 'desc')
            ->limit(max(1, min(50, $limit)))
            ->select()
            ->toArray();
        if ($rows === []) {
            return [];
        }

        $commentIds = array_values(array_unique(array_filter(array_map(
            static fn(array $row): string => trim((string)($row['comment_id'] ?? '')),
            $rows
        ))));
        $reviewsByCommentId = [];
        if ($commentIds !== []) {
            $reviewRows = Db::name('ota_ctrip_reviews')
                ->where('system_hotel_id', $systemHotelId)
                ->whereIn('comment_id', $commentIds)
                ->field('comment_id, source_username, checkin_date, room_name, score')
                ->select()
                ->toArray();
            foreach ($reviewRows as $reviewRow) {
                $commentId = trim((string)($reviewRow['comment_id'] ?? ''));
                if ($commentId !== '') {
                    $reviewsByCommentId[$commentId] = $reviewRow;
                }
            }
        }

        return array_map(function (array $row) use ($reviewsByCommentId): array {
            $commentId = trim((string)($row['comment_id'] ?? ''));
            $review = $reviewsByCommentId[$commentId] ?? [];
            $candidateOrder = $this->firstPublicCtripReviewCandidateOrder(
                $this->decodeCtripReviewMatchJson((string)($row['candidate_orders_json'] ?? '[]'))
            );
            $candidateCount = $this->publicCtripReviewCandidateCount((string)($row['candidate_orders_json'] ?? '[]'));

            return [
                'comment_id' => $commentId,
                'source_username' => (string)($review['source_username'] ?? ''),
                'checkin_date' => (string)($review['checkin_date'] ?? ''),
                'room_name' => (string)($review['room_name'] ?? ''),
                'score' => $review['score'] ?? null,
                'status' => (string)($row['match_status'] ?? 'unknown'),
                'confidence' => (string)($row['confidence'] ?? 'none'),
                'order_id' => (string)($row['order_id'] ?? ''),
                'guest_name' => (string)($row['guest_name'] ?? ''),
                'status_text' => $this->publicCtripReviewMatchStatusText(
                    (string)($row['match_status'] ?? 'unknown'),
                    (string)($row['order_id'] ?? ''),
                    (string)($row['guest_name'] ?? ''),
                    $candidateCount
                ),
                'candidate_count' => $candidateCount,
                'candidate_order_id' => (string)($candidateOrder['order_id'] ?? ''),
                'candidate_guest_name' => (string)($candidateOrder['guest_name'] ?? ''),
                'candidate_arrival_date' => (string)($candidateOrder['arrival_date'] ?? ''),
                'updated_at' => (string)($row['update_time'] ?? ''),
            ];
        }, $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadCtripReviewMatchReviewCards(int $systemHotelId, int $limit = 30): array
    {
        $reviewRows = Db::name('ota_ctrip_reviews')
            ->where('system_hotel_id', $systemHotelId)
            ->field('comment_id, source_username, user_avatar_url, review_date, checkin_date, room_name, score, content, update_time')
            ->order('review_date', 'desc')
            ->order('id', 'desc')
            ->limit(max(1, min(50, $limit)))
            ->select()
            ->toArray();
        if ($reviewRows === []) {
            return [];
        }

        $commentIds = array_values(array_unique(array_filter(array_map(
            static fn(array $row): string => trim((string)($row['comment_id'] ?? '')),
            $reviewRows
        ))));
        $matchesByCommentId = [];
        if ($commentIds !== []) {
            $matchRows = Db::name('ota_ctrip_review_order_matches')
                ->where('system_hotel_id', $systemHotelId)
                ->whereIn('comment_id', $commentIds)
                ->field('comment_id, order_id, guest_name, match_status, confidence, candidate_orders_json, update_time')
                ->order('update_time', 'desc')
                ->order('id', 'desc')
                ->select()
                ->toArray();
            foreach ($matchRows as $matchRow) {
                $commentId = trim((string)($matchRow['comment_id'] ?? ''));
                if ($commentId !== '' && !isset($matchesByCommentId[$commentId])) {
                    $matchesByCommentId[$commentId] = $matchRow;
                }
            }
        }

        return array_map(function (array $reviewRow) use ($matchesByCommentId): array {
            $commentId = trim((string)($reviewRow['comment_id'] ?? ''));
            $match = $matchesByCommentId[$commentId] ?? [];
            $status = (string)($match['match_status'] ?? 'unmatched');
            $orderId = (string)($match['order_id'] ?? '');
            $guestName = (string)($match['guest_name'] ?? '');
            $candidateOrder = $this->firstPublicCtripReviewCandidateOrder(
                $this->decodeCtripReviewMatchJson((string)($match['candidate_orders_json'] ?? '[]'))
            );
            $candidateCount = $this->publicCtripReviewCandidateCount((string)($match['candidate_orders_json'] ?? '[]'));

            return [
                'comment_id' => $commentId,
                'source_username' => (string)($reviewRow['source_username'] ?? ''),
                'avatar_url' => (string)($reviewRow['user_avatar_url'] ?? ''),
                'review_date' => (string)($reviewRow['review_date'] ?? ''),
                'checkin_date' => (string)($reviewRow['checkin_date'] ?? ''),
                'room_name' => (string)($reviewRow['room_name'] ?? ''),
                'score' => $reviewRow['score'] ?? null,
                'content' => $this->shortCtripReviewMatchText((string)($reviewRow['content'] ?? ''), 180),
                'status' => $status,
                'status_text' => $this->publicCtripReviewMatchStatusText($status, $orderId, $guestName, $candidateCount),
                'order_id' => $orderId,
                'guest_name' => $guestName,
                'candidate_count' => $candidateCount,
                'candidate_order_id' => (string)($candidateOrder['order_id'] ?? ''),
                'candidate_guest_name' => (string)($candidateOrder['guest_name'] ?? ''),
                'candidate_arrival_date' => (string)($candidateOrder['arrival_date'] ?? ''),
                'confidence' => (string)($match['confidence'] ?? 'none'),
                'updated_at' => (string)($match['update_time'] ?? $reviewRow['update_time'] ?? ''),
            ];
        }, $reviewRows);
    }

    /**
     * @param array<string, mixed> $review
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function buildCtripReviewOrderMatchPublicResult(array $review, array $result): array
    {
        $order = is_array($result['order'] ?? null) ? $result['order'] : [];
        $identity = is_array($result['identity'] ?? null) ? $result['identity'] : [];
        $status = (string)($result['status'] ?? 'unknown');
        $orderId = (string)($order['order_id'] ?? '');
        $guestName = (string)($identity['guest_name'] ?? $result['person_name'] ?? $order['guest_name'] ?? '');
        $candidateOrder = $this->firstPublicCtripReviewCandidateOrder(
            is_array($result['candidates'] ?? null) ? $result['candidates'] : []
        );
        $candidateCount = is_array($result['candidates'] ?? null) ? count($result['candidates']) : 0;

        return [
            'status' => $status,
            'status_text' => $this->publicCtripReviewMatchStatusText($status, $orderId, $guestName, $candidateCount),
            'found' => in_array($status, ['found', 'matched'], true) && $orderId !== '',
            'scope' => 'ctrip_ota_channel',
            'comment_id' => $this->extractCtripReviewCommentId($review),
            'order_id' => $orderId,
            'guest_name' => $guestName,
            'confidence' => (string)($result['confidence'] ?? 'none'),
            'candidate_count' => $candidateCount,
            'candidate_order_id' => (string)($candidateOrder['order_id'] ?? ''),
            'candidate_guest_name' => (string)($candidateOrder['guest_name'] ?? ''),
            'candidate_arrival_date' => (string)($candidateOrder['arrival_date'] ?? ''),
            'candidate_order' => $candidateOrder,
            'review' => $this->buildCtripReviewMatchPublicReview($review),
            'order' => $orderId !== '' ? [
                'order_id' => $orderId,
                'guest_name' => $guestName,
                'arrival_date' => (string)($order['arrival_date'] ?? ''),
                'departure_date' => (string)($order['departure_date'] ?? ''),
                'room_name' => (string)($order['room_name'] ?? ''),
            ] : null,
            'identity' => $guestName !== '' ? ['guest_name' => $guestName] : null,
        ];
    }

    /**
     * @param array<string, mixed> $review
     * @return array<string, mixed>
     */
    private function buildCtripReviewMatchPublicReview(array $review): array
    {
        return [
            'comment_id' => $this->extractCtripReviewCommentId($review),
            'source_username' => $this->firstCtripReviewMatchText($review, ['userName', 'user_name', 'sourceUsername', 'source_username', 'username', 'nickName', 'nick_name', 'user_name_masked']),
            'avatar_url' => $this->firstCtripReviewMatchText($review, ['userIcon', 'user_icon', 'userAvatarUrl', 'user_avatar_url', 'avatarUrl', 'avatar_url']),
            'review_date' => $this->firstCtripReviewMatchText($review, ['reviewDate', 'review_date', 'addtime', 'addTime', 'publishTime', 'publish_time', 'commentTime', 'comment_time']),
            'checkin_date' => $this->firstCtripReviewMatchText($review, ['checkinTimeStr', 'checkInDate', 'check_in_date', 'checkinDate', 'checkin_date', 'arrivalDate', 'arrival_date', 'stayDate', 'stay_date']),
            'room_name' => $this->firstCtripReviewMatchText($review, ['hotelRoomInfo', 'hotel_room_info', 'roomName', 'room_name', 'roomType', 'room_type', 'room_type_name', 'productName', 'product_name']),
            'score' => $review['avgScore'] ?? $review['score'] ?? null,
            'content' => $this->shortCtripReviewMatchText($this->firstCtripReviewMatchText($review, ['content', 'commentContent', 'comment_content', 'reviewContent', 'review_content']), 180),
        ];
    }

    private function publicCtripReviewMatchStatusText(string $status, string $orderId = '', string $guestName = '', int $candidateCount = 0): string
    {
        if (in_array($status, ['found', 'matched'], true) && $orderId !== '') {
            return '已查到订单';
        }
        if ($status === 'person_locked' && $candidateCount > 0) {
            return '候选订单待复核';
        }
        if ($status === 'person_locked' && $guestName !== '') {
            return '已识别客人，待确认订单';
        }
        if ($status === 'out_of_coverage') {
            return '超出当前订单缓存范围';
        }
        if ($status === 'needs_ops') {
            return '缺少可匹配数据';
        }
        if ($status === 'unmatched') {
            return '未查到订单';
        }
        return '待查询';
    }

    /**
     * @param array<int, mixed> $candidates
     * @return array<string, string>|null
     */
    private function firstPublicCtripReviewCandidateOrder(array $candidates): ?array
    {
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $orderId = trim((string)($candidate['order_id'] ?? $candidate['orderId'] ?? $candidate['orderNo'] ?? $candidate['order_no'] ?? ''));
            if ($orderId === '') {
                continue;
            }
            return [
                'order_id' => $orderId,
                'guest_name' => (string)($candidate['guest_name'] ?? $candidate['guestName'] ?? ''),
                'arrival_date' => $this->normalizeCtripReviewMatchDate($candidate['arrival_date'] ?? $candidate['arrivalDate'] ?? ''),
                'departure_date' => $this->normalizeCtripReviewMatchDate($candidate['departure_date'] ?? $candidate['departureDate'] ?? ''),
                'room_name' => (string)($candidate['room_name'] ?? $candidate['roomName'] ?? ''),
            ];
        }

        return null;
    }

    private function publicCtripReviewCandidateCount(string $candidateJson): int
    {
        $candidates = $this->decodeCtripReviewMatchJson($candidateJson);
        return is_array($candidates) ? count(array_filter($candidates, 'is_array')) : 0;
    }

    private function shortCtripReviewMatchText(string $text, int $limit): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '' || $limit <= 0) {
            return '';
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($text, 'UTF-8') > $limit
                ? mb_substr($text, 0, $limit, 'UTF-8') . '...'
                : $text;
        }
        return strlen($text) > $limit ? substr($text, 0, $limit) . '...' : $text;
    }

    /**
     * @return array<string, string>
     */
    private function buildCtripReviewMatchClosureNextCommands(int $systemHotelId): array
    {
        $payloadArg = ' -- --file=<authorized-payload.json> --system-hotel-id=' . $systemHotelId;

        return [
            'preflight' => 'npm.cmd run import:ctrip-review-match-payload:preflight' . $payloadArg,
            'dry_run' => 'npm.cmd run import:ctrip-review-match-payload' . $payloadArg,
            'execute' => 'npm.cmd run import:ctrip-review-match-payload:execute' . $payloadArg,
            'verify' => 'npm.cmd run verify:ctrip-review-match -- --system-hotel-id=' . $systemHotelId,
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
     * @return array<string, mixed>
     */
    private function emptyCtripReviewMatchAutoCaptureSummary(): array
    {
        return [
            'status' => 'not_attempted',
            'message' => '',
            'output' => '',
            'profile_id' => '',
            'payloads_scanned' => 0,
            'reviews_detected' => 0,
            'im_sessions_detected' => 0,
            'orders_detected' => 0,
            'reviews_upserted' => 0,
            'im_sessions_upserted' => 0,
            'orders_upserted' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function shouldAutoCaptureCtripReviewMatchReviews(array $data): bool
    {
        $value = $data['auto_capture'] ?? $data['autoCapture'] ?? true;
        if (is_bool($value)) {
            return $value;
        }
        return !in_array(strtolower(trim((string)$value)), ['0', 'false', 'no', 'off', 'disabled'], true);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function captureCtripReviewMatchReviewsFromAuthorizedProfile(int $systemHotelId, array $data): array
    {
        $summary = $this->emptyCtripReviewMatchAutoCaptureSummary();
        $summary['status'] = 'attempted';

        $projectRoot = dirname(__DIR__, 3);
        $scriptPath = $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'ctrip_comment_browser_capture.mjs';
        if (!is_file($scriptPath)) {
            return array_merge($summary, [
                'status' => 'failed',
                'message' => '未找到携程评价授权 Profile 采集脚本',
            ]);
        }

        $nodeBinary = BrowserProfileCaptureRequestService::resolveNodeBinary();
        if ($nodeBinary === '') {
            return array_merge($summary, [
                'status' => 'failed',
                'message' => '未找到 Node.js，无法启动携程评价授权 Profile 采集',
            ]);
        }

        $hotelId = BrowserProfileCaptureRequestService::resolveCtripHotelId($data);
        $profileId = BrowserProfileCaptureRequestService::resolveCtripProfileId($data, $systemHotelId, $hotelId);
        $outputDir = $projectRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'ctrip_review_match';
        if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
            return array_merge($summary, [
                'status' => 'failed',
                'message' => '无法创建携程评价匹配采集输出目录',
                'profile_id' => $profileId,
            ]);
        }

        $outputPath = $outputDir . DIRECTORY_SEPARATOR . 'ctrip_review_match_comments_'
            . BrowserProfileCaptureRequestService::safeFilePart($profileId)
            . '_' . date('YmdHis') . '.json';
        $timeoutSeconds = max(45, min(180, (int)($data['auto_capture_timeout_seconds'] ?? $data['autoCaptureTimeoutSeconds'] ?? 180)));
        $loginTimeoutMs = max(30000, min(120000, (int)($data['login_timeout_ms'] ?? $data['loginTimeoutMs'] ?? 45000)));
        $args = [
            $nodeBinary,
            $scriptPath,
            '--profile-id=' . $profileId,
            '--system-hotel-id=' . (string)$systemHotelId,
            '--output=' . $outputPath,
            '--report-dir=' . $outputDir,
            '--login-timeout-ms=' . (string)$loginTimeoutMs,
            '--headless=true',
        ];
        if ($hotelId !== '') {
            $args[] = '--hotel-id=' . $hotelId;
        }

        $runResult = $this->runMeituanCaptureProcess($args, $projectRoot, $timeoutSeconds);
        if (!$runResult['success']) {
            return array_merge($summary, [
                'status' => 'failed',
                'message' => str_replace('美团', '携程', (string)($runResult['message'] ?? '携程评价授权 Profile 采集失败')),
                'output' => $outputPath,
                'profile_id' => $profileId,
            ]);
        }
        if (!is_file($outputPath)) {
            return array_merge($summary, [
                'status' => 'failed',
                'message' => '携程评价授权 Profile 采集未生成结果文件',
                'output' => $outputPath,
                'profile_id' => $profileId,
            ]);
        }

        $payload = json_decode((string)file_get_contents($outputPath), true);
        if (!is_array($payload)) {
            return array_merge($summary, [
                'status' => 'failed',
                'message' => '携程评价授权 Profile 采集结果不是有效 JSON',
                'output' => $outputPath,
                'profile_id' => $profileId,
            ]);
        }

        $payload['system_hotel_id'] = $payload['system_hotel_id'] ?? $systemHotelId;
        $imported = $this->importCtripReviewMatchPayload($systemHotelId, $payload);
        $reviewsDetected = count($this->extractCtripCapturedComments($payload));
        $imSessionsDetected = count($this->extractCtripReviewMatchImSessionsFromPayload($payload));
        $ordersDetected = count($this->extractCtripReviewMatchOrdersFromPayload($payload));
        $detectedTotal = $reviewsDetected + $imSessionsDetected + $ordersDetected;

        return array_merge($summary, [
            'status' => $detectedTotal > 0 ? 'success' : 'empty',
            'message' => $reviewsDetected > 0 ? '已读取授权评价数据' : '授权 Profile 已运行，但未读取到评价明细',
            'output' => $outputPath,
            'profile_id' => $profileId,
            'payloads_scanned' => 1,
            'reviews_detected' => $reviewsDetected,
            'im_sessions_detected' => $imSessionsDetected,
            'orders_detected' => $ordersDetected,
            'reviews_upserted' => (int)($imported['reviews_upserted'] ?? 0),
            'im_sessions_upserted' => (int)($imported['im_sessions_upserted'] ?? 0),
            'orders_upserted' => (int)($imported['orders_upserted'] ?? 0),
        ]);
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
                    || $this->firstCtripReviewMatchText($order, ['guestName', 'guest_name', 'customerName', 'customer_name', 'contactName', 'contact_name', 'clientName', 'client_name']) !== ''
                ) {
                    $summary['orders_with_guest_identity']++;
                }
                $arrivalDate = $this->normalizeCtripReviewMatchDate($this->firstCtripReviewMatchText($order, ['arrivalDate', 'arrival_date', 'arrival', 'checkInDate', 'check_in_date', 'checkIn', 'check_in', 'checkinTime', 'checkin_time', 'checkInTime', 'check_in_time', 'stayDate', 'stay_date']));
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
            if ($this->upsertCtripReviewMatchOrderFromImSession($systemHotelId, $session) > 0) {
                $summary['orders_upserted']++;
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
            'screenshots',
            'pages',
            'xhr_urls',
            'unmatched_xhr_urls',
            'endpoint_candidates',
            'capture_audit',
            'capture_gate',
            'capture_gap_report',
            'catalog',
            'catalog_facts',
            'standard_rows',
            'raw_payload',
            'payload_counts',
            'payload_keys',
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
        $hasUid = $this->firstCtripReviewMatchText($member, ['uid', 'guestUid', 'guest_uid', 'userId', 'user_id', 'memberUid', 'member_uid']) !== '';
        $hasName = $this->firstCtripReviewMatchText($member, ['nickName', 'nickname', 'nick_name', 'name', 'guestName', 'guest_name']) !== '';
        $hasRole = $this->firstCtripReviewMatchText($member, ['roleType', 'role_type', 'role', 'userType', 'user_type']) !== '';
        $hasAvatar = $this->firstCtripReviewMatchText($member, ['pic', 'avatar', 'avatarUrl', 'avatar_url']) !== '';

        return $hasUid || ($hasName && ($hasRole || $hasAvatar));
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
            || $this->firstCtripReviewMatchText($order, ['guestName', 'guest_name', 'customerName', 'customer_name', 'contactName', 'contact_name', 'clientName', 'client_name']) !== ''
            || $this->firstCtripReviewMatchText($order, ['arrivalDate', 'arrival_date', 'arrival', 'checkInDate', 'check_in_date', 'checkIn', 'check_in', 'checkinTime', 'checkin_time', 'checkInTime', 'check_in_time', 'stayDate', 'stay_date']) !== ''
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
            $row = $this->mergeCtripReviewMatchImSessionRow($row, $existing);
            Db::name('ota_ctrip_im_sessions')->where('id', (int)$existing['id'])->update($row);
            return (int)$existing['id'];
        }

        $row['create_time'] = date('Y-m-d H:i:s');
        return (int)Db::name('ota_ctrip_im_sessions')->insertGetId($row);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    private function mergeCtripReviewMatchImSessionRow(array $row, array $existing): array
    {
        foreach (['session_id', 'guest_uid', 'guest_name', 'guest_avatar_url', 'room_name', 'room_name_prefix', 'order_id'] as $field) {
            if (($row[$field] ?? '') === '' && trim((string)($existing[$field] ?? '')) !== '') {
                $row[$field] = $existing[$field];
            }
        }
        foreach (['arrival_date', 'departure_date', 'fetched_at'] as $field) {
            if (($row[$field] ?? null) === null && !empty($existing[$field])) {
                $row[$field] = $existing[$field];
            }
        }
        $row['match_status'] = trim((string)($row['guest_name'] ?? '')) !== ''
            ? (!empty($row['arrival_date']) ? 'usable' : 'degraded_missing_arrival_date')
            : 'unusable_no_guest_member';

        return $row;
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
            'guest_name' => $this->firstCtripReviewMatchText($order, ['guestName', 'guest_name', 'customerName', 'customer_name', 'contactName', 'contact_name', 'clientName', 'client_name']),
            'arrival_date' => $this->nullableCtripReviewMatchDate($this->firstCtripReviewMatchText($order, ['arrivalDate', 'arrival_date', 'arrival', 'checkInDate', 'check_in_date', 'checkIn', 'check_in', 'checkinTime', 'checkin_time', 'checkInTime', 'check_in_time', 'stayDate', 'stay_date'])),
            'departure_date' => $this->nullableCtripReviewMatchDate($this->firstCtripReviewMatchText($order, ['departureDate', 'departure_date', 'departure', 'checkOutDate', 'check_out_date', 'checkOut', 'check_out', 'checkoutTime', 'checkout_time', 'checkOutTime', 'check_out_time'])),
            'room_name' => $roomName,
            'room_name_prefix' => $this->roomPrefix($roomName),
            'order_status' => $this->firstCtripReviewMatchText($order, ['orderStatus', 'order_status', 'orderStatusDesc', 'order_status_desc', 'orderState', 'order_state', 'status']),
            'source_platform' => 'ctrip',
            'raw_order_json' => $this->encodeCtripReviewMatchJson($order),
            'update_time' => date('Y-m-d H:i:s'),
        ];

        $existing = Db::name('ota_ctrip_orders')
            ->where('system_hotel_id', $systemHotelId)
            ->where('order_id', $orderId)
            ->find();
        if ($existing) {
            $row = $this->mergeCtripReviewMatchOrderRow($row, $existing);
            Db::name('ota_ctrip_orders')->where('id', (int)$existing['id'])->update($row);
            return (int)$existing['id'];
        }

        $row['create_time'] = date('Y-m-d H:i:s');
        return (int)Db::name('ota_ctrip_orders')->insertGetId($row);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    private function mergeCtripReviewMatchOrderRow(array $row, array $existing): array
    {
        foreach (['guest_uid', 'guest_name', 'room_name', 'room_name_prefix', 'order_status', 'source_platform'] as $field) {
            if (($row[$field] ?? '') === '' && trim((string)($existing[$field] ?? '')) !== '') {
                $row[$field] = $existing[$field];
            }
        }
        foreach (['arrival_date', 'departure_date'] as $field) {
            if (($row[$field] ?? null) === null && !empty($existing[$field])) {
                $row[$field] = $existing[$field];
            }
        }
        if (($row['raw_order_json'] ?? '') === '' && trim((string)($existing['raw_order_json'] ?? '')) !== '') {
            $row['raw_order_json'] = $existing['raw_order_json'];
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $session
     */
    private function upsertCtripReviewMatchOrderFromImSession(int $systemHotelId, array $session): int
    {
        $orderId = $this->firstCtripReviewMatchText($session, ['orderId', 'order_id', 'orderNo', 'order_no', 'orderSn', 'order_sn', 'platform_order_id', 'bookingOrderId', 'booking_order_id', 'reservationOrderId', 'reservation_order_id']);
        if ($orderId === '') {
            return 0;
        }

        $members = $session['members'] ?? [];
        $service = new CtripReviewOrderMatchService();
        $memberIndex = is_array($members) ? $service->buildMemberIndex([['members' => $members]]) : [];
        $guest = $memberIndex === [] ? [] : (array)reset($memberIndex);
        $roomName = $this->firstCtripReviewMatchText($session, ['roomName', 'room_name', 'roomNamePrefix', 'room_name_prefix', 'roomType', 'room_type', 'room_type_name', 'productName', 'product_name', 'ratePlanName', 'rate_plan_name']);

        return $this->upsertCtripReviewMatchOrder($systemHotelId, [
            'orderId' => $orderId,
            'guestUid' => $this->firstCtripReviewMatchText($session, ['guestUid', 'guest_uid', 'ctrip_guest_uid', 'memberUid', 'member_uid', 'uid']) ?: (string)($guest['uid'] ?? ''),
            'guestName' => $this->firstCtripReviewMatchText($session, ['guestName', 'guest_name', 'customerName', 'customer_name', 'contactName', 'contact_name', 'clientName', 'client_name']) ?: (string)($guest['nick_name'] ?? ''),
            'arrivalDate' => $this->firstCtripReviewMatchText($session, ['arrivalDate', 'arrival_date', 'arrival', 'checkInDate', 'check_in_date', 'checkIn', 'check_in', 'checkinTime', 'checkin_time', 'checkInTime', 'check_in_time', 'checkDate', 'check_date', 'stayDate', 'stay_date']),
            'departureDate' => $this->firstCtripReviewMatchText($session, ['departureDate', 'departure_date', 'departure', 'checkOutDate', 'check_out_date', 'checkOut', 'check_out', 'checkoutTime', 'checkout_time', 'checkOutTime', 'check_out_time']),
            'roomName' => $roomName,
            'orderStatus' => $this->firstCtripReviewMatchText($session, ['orderStatus', 'order_status', 'orderStatusDesc', 'order_status_desc', 'orderState', 'order_state', 'status']),
            '_source' => 'ctrip_im_session_cache',
            '_session' => $session,
        ]);
    }

    private function syncCtripReviewOrdersFromImSessionCache(int $systemHotelId): int
    {
        $rows = Db::name('ota_ctrip_im_sessions')
            ->where('system_hotel_id', $systemHotelId)
            ->where('order_id', '<>', '')
            ->select()
            ->toArray();

        $count = 0;
        foreach ($rows as $row) {
            $members = $this->decodeCtripReviewMatchJson((string)($row['members_json'] ?? '[]'));
            $session = [
                'groupId' => (string)($row['group_id'] ?? ''),
                'sessionId' => (string)($row['session_id'] ?? ''),
                'orderId' => (string)($row['order_id'] ?? ''),
                'guestUid' => (string)($row['guest_uid'] ?? ''),
                'guestName' => (string)($row['guest_name'] ?? ''),
                'arrivalDate' => (string)($row['arrival_date'] ?? ''),
                'departureDate' => (string)($row['departure_date'] ?? ''),
                'roomName' => (string)($row['room_name'] ?? ''),
                'members' => $members,
            ];
            if ($this->upsertCtripReviewMatchOrderFromImSession($systemHotelId, $session) > 0) {
                $count++;
            }
        }

        return $count;
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
    private function buildCtripReviewOrdererPreviewReview(array $data): array
    {
        $review = isset($data['review']) && is_array($data['review']) ? $data['review'] : [];
        $fieldMap = [
            'commentId' => ['commentId', 'comment_id', 'reviewId', 'review_id', 'id'],
            'userName' => ['userName', 'user_name', 'sourceUsername', 'source_username', 'username', 'nickName', 'nick_name', 'user_name_masked'],
            'checkinTimeStr' => ['checkinTimeStr', 'checkInDate', 'check_in_date', 'checkinDate', 'arrivalDate', 'arrival_date', 'stayDate', 'stay_date'],
            'hotelRoomInfo' => ['hotelRoomInfo', 'hotel_room_info', 'roomName', 'room_name', 'roomType', 'room_type', 'room_type_name', 'productName', 'product_name', 'ratePlanName', 'rate_plan_name'],
            'content' => ['content', 'comment', 'commentContent', 'comment_content', 'reviewContent', 'review_content', 'text', '_dom_text'],
        ];

        foreach ($fieldMap as $target => $fields) {
            foreach ($fields as $field) {
                if (!isset($data[$field]) || trim((string)$data[$field]) === '') {
                    continue;
                }
                $review[$target] = trim((string)$data[$field]);
                break;
            }
        }

        $review['_preview_source'] = 'authorized_page_payload';
        return $review;
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
            'reviewDate' => (string)($row['review_date'] ?? ''),
            'addtime' => (string)($row['review_date'] ?? ''),
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

        return array_map(function (array $row): array {
            $rawReview = $this->decodeCtripReviewMatchJson((string)($row['raw_review_json'] ?? '{}'));
            $rawCheckinTime = $this->firstCtripReviewMatchText($rawReview, ['checkinTimeStr', 'checkInDate', 'check_in_date', 'checkinDate', 'arrivalDate', 'arrival_date', 'stayDate', 'stay_date']);
            $rawPublishTime = $this->firstCtripReviewMatchText($rawReview, ['publishTime', 'publish_time', 'publishedAt', 'published_at', 'addtime', 'addTime', 'commentTime', 'comment_time', 'reviewTime', 'review_time', 'createTime', 'create_time', 'submitTime', 'submit_time', 'date']);

            return [
                'commentId' => (string)$row['comment_id'],
                'userName' => (string)$row['source_username'],
                'userIcon' => (string)$row['user_avatar_url'],
                'checkinTimeStr' => $rawCheckinTime !== '' ? $rawCheckinTime : (string)($row['checkin_date'] ?? ''),
                'addtime' => $rawPublishTime !== '' ? $rawPublishTime : (string)($row['review_date'] ?? ''),
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

        $sessions = [];
        foreach ($rows as $row) {
            $groupId = (string)$row['group_id'];
            $members = $this->decodeCtripReviewMatchJson((string)$row['members_json']);
            if (!$this->looksLikeCtripReviewMatchGroupKey($groupId) || !is_array($members) || !$this->looksLikeCtripReviewMatchMemberList($members)) {
                continue;
            }
            $sessions[] = [
                'groupId' => (string)$row['group_id'],
                'sessionId' => (string)$row['session_id'],
                'arrivalDate' => (string)($row['arrival_date'] ?? ''),
                'roomName' => (string)$row['room_name'],
                'members' => $members,
            ];
        }

        return $sessions;
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
     * @param array<int, array<string, mixed>> $reviews
     * @return array{resolved_count:int, assignments:array<int, array<string, string>>}
     */
    private function resolveCtripReviewMultiReviewOrderAssignments(int $systemHotelId, array $reviews, string $source): array
    {
        $commentIds = [];
        foreach ($reviews as $review) {
            $commentId = $this->extractCtripReviewCommentId($review);
            if ($commentId !== '') {
                $commentIds[] = $commentId;
            }
        }
        $commentIds = array_values(array_unique($commentIds));
        if ($commentIds === []) {
            return ['resolved_count' => 0, 'assignments' => []];
        }

        $rows = Db::name('ota_ctrip_review_order_matches')->alias('m')
            ->leftJoin('ota_ctrip_reviews r', 'r.system_hotel_id = m.system_hotel_id AND r.comment_id = m.comment_id')
            ->where('m.system_hotel_id', $systemHotelId)
            ->whereIn('m.comment_id', $commentIds)
            ->where('m.match_status', 'person_locked')
            ->field('m.id,m.comment_id,m.guest_uid,m.guest_name,m.candidate_orders_json,m.evidence_json,r.source_username,r.review_date,r.checkin_date,r.room_name')
            ->select()
            ->toArray();

        $groups = [];
        foreach ($rows as $row) {
            $candidateOrders = $this->normalizeCtripReviewCandidateOrders(
                $this->decodeCtripReviewMatchJson((string)($row['candidate_orders_json'] ?? '[]'))
            );
            if (count($candidateOrders) < 2) {
                continue;
            }
            $candidateKey = implode('|', array_keys($candidateOrders));
            $groupKey = md5(implode('|', [
                trim((string)($row['source_username'] ?? '')),
                trim((string)($row['guest_name'] ?? '')),
                $candidateKey,
                $this->roomPrefix((string)($row['room_name'] ?? '')),
            ]));
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'candidate_orders' => $candidateOrders,
                    'rows' => [],
                ];
            }
            $row['_match_date'] = $this->normalizeCtripReviewMatchDate($row['checkin_date'] ?? '') ?: $this->normalizeCtripReviewMatchDate($row['review_date'] ?? '');
            $groups[$groupKey]['rows'][] = $row;
        }

        $assignments = [];
        foreach ($groups as $group) {
            $groupRows = $group['rows'];
            $candidateOrders = $group['candidate_orders'];
            if (count($groupRows) < 2 || count($groupRows) !== count($candidateOrders)) {
                continue;
            }
            usort($groupRows, static function (array $left, array $right): int {
                return strcmp((string)($left['_match_date'] ?? ''), (string)($right['_match_date'] ?? ''))
                    ?: strcmp((string)($left['comment_id'] ?? ''), (string)($right['comment_id'] ?? ''));
            });
            $candidateList = array_values($candidateOrders);
            usort($candidateList, static function (array $left, array $right): int {
                return strcmp((string)($left['arrival_date'] ?? ''), (string)($right['arrival_date'] ?? ''))
                    ?: strcmp((string)($left['order_id'] ?? ''), (string)($right['order_id'] ?? ''));
            });

            $pairs = [];
            foreach ($groupRows as $index => $row) {
                $candidate = $candidateList[$index] ?? null;
                if (!is_array($candidate)) {
                    $pairs = [];
                    break;
                }
                $reviewDate = (string)($row['_match_date'] ?? '');
                $arrivalDate = (string)($candidate['arrival_date'] ?? '');
                if (!$this->isCtripReviewOrderSequenceDateCompatible($reviewDate, $arrivalDate)) {
                    $pairs = [];
                    break;
                }
                $pairs[] = [$row, $candidate, $index + 1];
            }
            if ($pairs === []) {
                continue;
            }

            foreach ($pairs as [$row, $candidate, $sequenceIndex]) {
                $evidence = $this->decodeCtripReviewMatchJson((string)($row['evidence_json'] ?? '{}'));
                $assignment = [
                    'comment_id' => (string)$row['comment_id'],
                    'order_id' => (string)$candidate['order_id'],
                    'guest_name' => (string)($candidate['guest_name'] ?: $row['guest_name']),
                    'match_status' => 'found',
                    'match_method' => 'im_uid_multi_review_order_sequence',
                ];
                Db::name('ota_ctrip_review_order_matches')
                    ->where('id', (int)$row['id'])
                    ->update([
                        'order_id' => $assignment['order_id'],
                        'guest_uid' => (string)($row['guest_uid'] ?? $candidate['guest_uid'] ?? ''),
                        'guest_name' => $assignment['guest_name'],
                        'match_status' => 'found',
                        'match_method' => $assignment['match_method'],
                        'confidence' => 'medium',
                        'candidate_orders_json' => $this->encodeCtripReviewMatchJson([]),
                        'evidence_json' => $this->encodeCtripReviewMatchJson([
                            'source' => $source,
                            'scope' => 'ctrip_ota_channel',
                            'assignment' => [
                                'sequence_index' => $sequenceIndex,
                                'review_date' => (string)($row['_match_date'] ?? ''),
                                'arrival_date' => (string)($candidate['arrival_date'] ?? ''),
                                'candidate_order_count' => count($candidateOrders),
                                'rule' => 'same_person_same_candidate_orders_date_sequence',
                            ],
                            'previous_evidence' => $evidence,
                        ]),
                        'update_time' => date('Y-m-d H:i:s'),
                    ]);
                $assignments[] = $assignment;
            }
        }

        return [
            'resolved_count' => count($assignments),
            'assignments' => $assignments,
        ];
    }

    /**
     * @param array<int, mixed> $candidates
     * @return array<string, array<string, string>>
     */
    private function normalizeCtripReviewCandidateOrders(array $candidates): array
    {
        $orders = [];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $orderId = trim((string)($candidate['order_id'] ?? $candidate['orderId'] ?? $candidate['orderNo'] ?? $candidate['order_no'] ?? ''));
            if ($orderId === '') {
                continue;
            }
            $orders[$orderId] = [
                'order_id' => $orderId,
                'guest_uid' => (string)($candidate['guest_uid'] ?? $candidate['guestUid'] ?? ''),
                'guest_name' => (string)($candidate['guest_name'] ?? $candidate['guestName'] ?? ''),
                'arrival_date' => $this->normalizeCtripReviewMatchDate($candidate['arrival_date'] ?? $candidate['arrivalDate'] ?? ''),
                'room_name' => (string)($candidate['room_name'] ?? $candidate['roomName'] ?? ''),
            ];
        }
        ksort($orders);
        return $orders;
    }

    private function isCtripReviewOrderSequenceDateCompatible(string $reviewDate, string $arrivalDate): bool
    {
        $reviewDate = $this->normalizeCtripReviewMatchDate($reviewDate);
        $arrivalDate = $this->normalizeCtripReviewMatchDate($arrivalDate);
        if ($reviewDate === '' || $arrivalDate === '') {
            return false;
        }
        if (substr($reviewDate, 0, 7) === substr($arrivalDate, 0, 7)) {
            return true;
        }
        $reviewTime = strtotime($reviewDate);
        $arrivalTime = strtotime($arrivalDate);
        if ($reviewTime === false || $arrivalTime === false) {
            return false;
        }
        return abs($reviewTime - $arrivalTime) <= 31 * 86400;
    }

    /**
     * @param array<string, int> $statusCounts
     * @param array<string, mixed> $resolution
     * @return array<string, int>
     */
    private function applyCtripReviewMultiReviewResolutionToStatusCounts(array $statusCounts, array $resolution): array
    {
        $resolvedCount = max(0, (int)($resolution['resolved_count'] ?? 0));
        if ($resolvedCount === 0) {
            return $statusCounts;
        }
        $statusCounts['person_locked'] = max(0, (int)($statusCounts['person_locked'] ?? 0) - $resolvedCount);
        $statusCounts['found'] = (int)($statusCounts['found'] ?? 0) + $resolvedCount;
        return array_filter($statusCounts, static fn(int $count): bool => $count > 0);
    }

    /**
     * @param array<int, array<string, mixed>> $samples
     * @param array<string, mixed> $resolution
     * @return array<int, array<string, mixed>>
     */
    private function applyCtripReviewMultiReviewResolutionToSamples(array $samples, array $resolution): array
    {
        $assignments = [];
        foreach (($resolution['assignments'] ?? []) as $assignment) {
            if (!is_array($assignment) || (string)($assignment['comment_id'] ?? '') === '') {
                continue;
            }
            $assignments[(string)$assignment['comment_id']] = $assignment;
        }
        if ($assignments === []) {
            return $samples;
        }
        foreach ($samples as &$sample) {
            $commentId = (string)($sample['comment_id'] ?? '');
            if (!isset($assignments[$commentId])) {
                continue;
            }
            $sample['status'] = 'found';
            $sample['status_text'] = $this->publicCtripReviewMatchStatusText('found', (string)($assignments[$commentId]['order_id'] ?? ''), (string)($assignments[$commentId]['guest_name'] ?? ''));
            $sample['order_id'] = (string)($assignments[$commentId]['order_id'] ?? '');
            $sample['guest_name'] = (string)($assignments[$commentId]['guest_name'] ?? $sample['guest_name'] ?? '');
            $sample['candidate_count'] = 0;
            $sample['candidate_order_id'] = '';
            $sample['candidate_guest_name'] = '';
            $sample['candidate_arrival_date'] = '';
            $sample['reason'] = '';
        }
        unset($sample);
        return $samples;
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
        if ($this->normalizeCtripReviewMatchYearMonth($text) !== '') {
            return '';
        }
        if (preg_match('/\/Date\((\d{10,13})(?:[+-]\d{4})?\)\//', $text, $matches)) {
            $timestamp = (int)$matches[1];
            if ($timestamp > 9999999999) {
                $timestamp = (int)floor($timestamp / 1000);
            }
            return date('Y-m-d', $timestamp);
        }
        if (preg_match('/(20\d{2})[-\/.年](\d{1,2})[-\/.月](\d{1,2})/u', $text, $matches)) {
            return sprintf('%04d-%02d-%02d', (int)$matches[1], (int)$matches[2], (int)$matches[3]);
        }
        $timestamp = strtotime($text);
        return $timestamp === false ? '' : date('Y-m-d', $timestamp);
    }

    private function normalizeCtripReviewMatchYearMonth($value): string
    {
        $text = trim((string)$value);
        if ($text === '') {
            return '';
        }
        if (preg_match('/^(20\d{2})[-\/.](\d{1,2})$/', $text, $matches)) {
            return sprintf('%04d-%02d', (int)$matches[1], (int)$matches[2]);
        }
        if (preg_match('/^(20\d{2})\s*年\s*(\d{1,2})\s*月\s*$/u', $text, $matches)) {
            return sprintf('%04d-%02d', (int)$matches[1], (int)$matches[2]);
        }
        return '';
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
