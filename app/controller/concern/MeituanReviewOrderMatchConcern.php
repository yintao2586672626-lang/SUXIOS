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

        try {
            $data = $this->requestData();
            $systemHotelId = $this->resolveMeituanReviewMatchHotelId($data);
            if (!$systemHotelId) {
                return $this->error('请选择酒店', 400);
            }

            $review = is_array($data['review'] ?? null) ? $data['review'] : $data;
            $service = new MeituanReviewOrderMatchService();
            $normalized = $service->normalizeReviewForStorage($review);
            if ($normalized['review_id'] === '') {
                return $this->error('缺少美团点评 ID', 422);
            }

            $now = date('Y-m-d H:i:s');
            $row = [
                'system_hotel_id' => $systemHotelId,
                'review_id' => $normalized['review_id'],
                'meituan_user_id' => '',
                'source_username' => '',
                'review_date' => $this->nullableMeituanReviewMatchDate($normalized['review_date']),
                'checkin_date' => $this->nullableMeituanReviewMatchDate($normalized['checkin_date']),
                'room_name' => $normalized['room_name'],
                'room_name_prefix' => $this->meituanReviewMatchRoomPrefix((string)$normalized['room_name']),
                'score' => $normalized['score'],
                'content' => '',
                'raw_review_json' => $normalized['raw_review_json'],
                'update_time' => $now,
            ];
            $readback = $this->upsertMeituanReviewScopedRow(
                'ota_meituan_reviews',
                $systemHotelId,
                'review_id',
                (string)$normalized['review_id'],
                $row,
                ['review_id', 'meituan_user_id', 'source_username', 'review_date', 'checkin_date', 'room_name', 'content', 'raw_review_json']
            );

            OperationLog::record(
                'online_data',
                'save_meituan_review_for_match',
                'Save Meituan review evidence: ' . (string)$normalized['review_id'],
                $this->currentUser->id ?? null,
                $systemHotelId
            );

            return $this->success([
                'id' => (int)$readback['id'],
                'review_id' => (string)$readback['review_id'],
                'save_status' => 'saved_and_readback_verified',
                'data_status' => 'ready',
                'readback' => $this->publicMeituanReviewStorageReadback($readback),
                'source_status' => $this->meituanReviewMatchWriteSourceStatus('authorized_review_evidence'),
            ], '美团点评证据已保存并完成回读');
        } catch (\think\exception\HttpException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getStatusCode()));
        } catch (\Throwable $e) {
            return $this->error('保存美团点评证据失败: ' . $e->getMessage(), 500);
        }
    }

    public function saveMeituanOrderForMatch(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        try {
            $data = $this->requestData();
            $systemHotelId = $this->resolveMeituanReviewMatchHotelId($data);
            if (!$systemHotelId) {
                return $this->error('请选择酒店', 400);
            }

            $order = is_array($data['order'] ?? null) ? $data['order'] : $data;
            $service = new MeituanReviewOrderMatchService();
            $normalized = $service->normalizeOrderForStorage($order);
            if ($normalized['order_id'] === '') {
                return $this->error('缺少美团订单 ID', 422);
            }

            $now = date('Y-m-d H:i:s');
            $row = [
                'system_hotel_id' => $systemHotelId,
                'order_id' => $normalized['order_id'],
                'meituan_user_id' => '',
                'guest_name_masked' => '',
                'arrival_date' => $this->nullableMeituanReviewMatchDate($normalized['arrival_date']),
                'departure_date' => $this->nullableMeituanReviewMatchDate($normalized['departure_date']),
                'room_name' => $normalized['room_name'],
                'room_name_prefix' => $this->meituanReviewMatchRoomPrefix((string)$normalized['room_name']),
                'order_status' => $normalized['order_status'],
                'phone_masked' => '',
                'phone_last4' => '',
                'phone_status' => $normalized['phone_status'],
                'phone_source' => $normalized['phone_source'],
                'raw_order_json' => $normalized['raw_order_json'],
                'update_time' => $now,
            ];
            $readback = $this->upsertMeituanReviewScopedRow(
                'ota_meituan_orders',
                $systemHotelId,
                'order_id',
                (string)$normalized['order_id'],
                $row,
                ['order_id', 'meituan_user_id', 'guest_name_masked', 'arrival_date', 'departure_date', 'room_name', 'order_status', 'phone_masked', 'phone_last4', 'phone_status', 'phone_source', 'raw_order_json']
            );

            OperationLog::record(
                'online_data',
                'save_meituan_order_for_review_match',
                'Save Meituan order evidence: ' . (string)$normalized['order_id'],
                $this->currentUser->id ?? null,
                $systemHotelId
            );

            return $this->success([
                'id' => (int)$readback['id'],
                'order_id' => (string)$readback['order_id'],
                'save_status' => 'saved_and_readback_verified',
                'data_status' => 'ready',
                'readback' => $this->publicMeituanOrderStorageReadback($readback),
                'source_status' => $this->meituanReviewMatchWriteSourceStatus('authorized_order_evidence'),
            ], '美团订单证据已保存并完成回读');
        } catch (\think\exception\HttpException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getStatusCode()));
        } catch (\Throwable $e) {
            return $this->error('保存美团订单证据失败: ' . $e->getMessage(), 500);
        }
    }

    public function lookupMeituanReviewOrderMatch(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        try {
            $data = $this->requestData();
            $systemHotelId = $this->resolveMeituanReviewMatchHotelId($data);
            if (!$systemHotelId) {
                return $this->error('请选择酒店', 400);
            }

            $review = $this->resolveMeituanReviewForLookup($systemHotelId, $data);
            if ($review === []) {
                return $this->error('当前酒店不存在该美团点评，请先保存点评证据', 404);
            }

            $service = new MeituanReviewOrderMatchService();
            $result = $service->matchReviewToOrder(
                $review,
                $this->loadMeituanOrderPool($systemHotelId),
                ['coverage_start_date' => $this->firstMeituanOrderCoverageDate($systemHotelId)]
            );
            $attemptReadback = $this->saveMeituanReviewMatchAttempt($systemHotelId, $review, $result, 'manual_lookup');
            $result['save_status'] = 'saved_and_readback_verified';
            $result['attempt_readback'] = $this->publicMeituanMatchReadback($attemptReadback);
            $result['review_cards'] = $this->loadMeituanReviewMatchReviewCards($systemHotelId, 30);

            return $this->success($result, '美团点评订单候选已计算并完成保存回读');
        } catch (\think\exception\HttpException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getStatusCode()));
        } catch (\Throwable $e) {
            return $this->error('计算美团点评订单候选失败: ' . $e->getMessage(), 500);
        }
    }

    public function runMeituanReviewOrderMatchAutomation(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        try {
            $data = $this->requestData();
            $systemHotelId = $this->resolveMeituanReviewMatchHotelId($data);
            if (!$systemHotelId) {
                return $this->error('请选择酒店', 400);
            }

            $reviewLimit = max(1, min(500, (int)($data['review_limit'] ?? $data['reviewLimit'] ?? 200)));
            $reviews = $this->loadMeituanReviewsForMatch($systemHotelId, $reviewLimit);
            $orders = $this->loadMeituanOrderPool($systemHotelId);
            $service = new MeituanReviewOrderMatchService();
            $results = $service->buildReviewOrderMatches(
                $reviews,
                $orders,
                ['coverage_start_date' => $this->firstMeituanOrderCoverageDate($systemHotelId)]
            );

            $statusCounts = [];
            $samples = [];
            foreach ($reviews as $index => $review) {
                $result = is_array($results[$index] ?? null) ? $results[$index] : [
                    'status' => 'not_found',
                    'confidence' => 'not_found',
                    'reason' => 'batch_match_result_missing',
                    'missing_evidence' => ['batch_match_result'],
                    'candidates' => [],
                ];
                $readback = $this->saveMeituanReviewMatchAttempt($systemHotelId, $review, $result, 'automation');
                $storedStatus = (string)($readback['match_status'] ?? $result['status'] ?? 'unknown');
                $statusCounts[$storedStatus] = ($statusCounts[$storedStatus] ?? 0) + 1;
                if (count($samples) < 20) {
                    $samples[] = $this->buildMeituanReviewMatchSample($review, $result, $readback);
                }
            }

            $missingSources = [];
            if ($reviews === []) {
                $missingSources[] = 'meituan_reviews';
            }
            if ($orders === []) {
                $missingSources[] = 'meituan_orders';
            }
            $ready = $missingSources === [];
            $payload = [
                'mode' => 'execute',
                'status' => $ready ? 'completed' : 'not_ready',
                'scope' => 'meituan_ota_channel',
                'summary' => [
                    'review_count' => count($reviews),
                    'order_count' => count($orders),
                    'manual_matched_count' => (int)($statusCounts['matched'] ?? 0),
                    'evidence_ready_count' => (int)($statusCounts['confirmed'] ?? 0) + (int)($statusCounts['high_confidence'] ?? 0),
                    'confirmed_count' => (int)($statusCounts['confirmed'] ?? 0),
                    'high_confidence_count' => (int)($statusCounts['high_confidence'] ?? 0),
                    'candidate_count' => (int)($statusCounts['candidate'] ?? 0),
                    'ambiguous_count' => (int)($statusCounts['ambiguous'] ?? 0),
                    'not_found_count' => (int)($statusCounts['not_found'] ?? 0),
                    'rejected_count' => (int)($statusCounts['rejected'] ?? 0),
                    'unbound_count' => (int)($statusCounts['unbound'] ?? 0),
                ],
                'status_counts' => $statusCounts,
                'missing_sources' => $missingSources,
                'save_status' => $reviews === [] ? 'not_applicable' : 'saved_and_readback_verified',
                'source_status' => [
                    'scope' => 'meituan_ota_channel',
                    'detail_sources_ready' => $ready,
                    'source_tables' => [
                        'meituan_reviews' => count($reviews),
                        'meituan_orders' => count($orders),
                    ],
                    'policy' => 'authorized_saved_evidence_only',
                    'identity_resolution' => 'blocked_not_attempted',
                    'phone_acquisition' => 'blocked_not_attempted',
                    'storage_write' => $reviews !== [],
                ],
                'samples' => $samples,
                'review_cards' => $this->loadMeituanReviewMatchReviewCards($systemHotelId, 30),
                'next_action' => $ready
                    ? '人工确认高置信候选；歧义候选应补充日期/房型/订单状态证据或明确否决'
                    : '先导入当前酒店真实授权的美团点评与美团订单证据；缺失不会按0处理',
            ];

            OperationLog::record(
                'online_data',
                'run_meituan_review_order_match_automation',
                'Run safe Meituan review order matching',
                $this->currentUser->id ?? null,
                $systemHotelId
            );

            return $this->success(
                $payload,
                $ready ? '美团点评订单候选计算完成' : '美团点评订单候选计算未完成：缺少必要数据源'
            );
        } catch (\think\exception\HttpException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getStatusCode()));
        } catch (\Throwable $e) {
            return $this->error('运行美团点评订单候选计算失败: ' . $e->getMessage(), 500);
        }
    }

    public function checkMeituanReviewOrderMatchClosure(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_view_online_data');

        try {
            $data = $this->requestData();
            $systemHotelId = $this->resolveMeituanReviewMatchHotelId($data);
            if (!$systemHotelId) {
                return $this->error('请选择酒店', 400);
            }
            $minMatched = max(0, min(1000, (int)($data['min_matched'] ?? $data['minMatched'] ?? 1)));
            $payload = $this->buildMeituanReviewMatchClosureStatus($systemHotelId, $minMatched);
            $ready = ($payload['status'] ?? '') === 'completed';

            return $this->success($payload, $ready ? '美团点评订单人工确认闭环已完成' : '美团点评订单人工确认闭环未完成');
        } catch (\think\exception\HttpException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getStatusCode()));
        } catch (\Throwable $e) {
            return $this->error('检查美团点评订单闭环失败: ' . $e->getMessage(), 500);
        }
    }

    public function bindMeituanReviewOrderMatch(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        try {
            $data = $this->requestData();
            $systemHotelId = $this->resolveMeituanReviewMatchHotelId($data);
            if (!$systemHotelId) {
                return $this->error('请选择酒店', 400);
            }
            $reviewId = $this->meituanReviewMatchReviewId($data);
            $orderId = trim((string)($data['orderId'] ?? $data['order_id'] ?? ''));
            if ($reviewId === '' || $orderId === '') {
                return $this->error('缺少 reviewId 或 orderId', 422);
            }

            $now = date('Y-m-d H:i:s');
            Db::startTrans();
            try {
                $review = Db::name('ota_meituan_reviews')
                    ->where('system_hotel_id', $systemHotelId)
                    ->where('review_id', $reviewId)
                    ->lock(true)
                    ->find();
                if (!$review) {
                    throw new \RuntimeException('当前酒店不存在该美团点评，不能人工确认');
                }
                $order = Db::name('ota_meituan_orders')
                    ->where('system_hotel_id', $systemHotelId)
                    ->where('order_id', $orderId)
                    ->lock(true)
                    ->find();
                if (!$order) {
                    throw new \RuntimeException('当前酒店不存在该美团订单，不能人工确认');
                }

                $existing = Db::name('ota_meituan_review_order_matches')
                    ->where('system_hotel_id', $systemHotelId)
                    ->where('review_id', $reviewId)
                    ->lock(true)
                    ->find();
                $row = [
                    'system_hotel_id' => $systemHotelId,
                    'review_id' => $reviewId,
                    'order_id' => $orderId,
                    'meituan_user_id' => '',
                    'guest_name_masked' => '',
                    'match_status' => 'matched',
                    'match_method' => 'manual_confirm',
                    'confidence' => 'high',
                    'candidate_orders_json' => $this->encodeMeituanReviewMatchJson([]),
                    'evidence_json' => $this->encodeMeituanReviewMatchJson([
                        'source' => 'manual_confirm',
                        'scope' => 'meituan_ota_channel',
                        'operator_user_id' => $this->currentUser->id ?? null,
                        'previous_order_id' => (string)($existing['order_id'] ?? ''),
                        'identity_resolution' => 'blocked_not_attempted',
                        'phone_evidence_used' => false,
                    ]),
                    'bound_by' => $this->currentUser->id ?? null,
                    'bound_at' => $now,
                    'update_time' => $now,
                ];
                $id = $this->upsertMeituanReviewMatchRowWithinTransaction($existing, $row);
                $readback = $this->readMeituanReviewMatchRow($id, $systemHotelId, $reviewId);
                $this->assertMeituanReviewReadback($readback, $row, ['order_id', 'match_status', 'match_method', 'confidence', 'bound_by', 'bound_at']);
                Db::commit();
            } catch (\Throwable $e) {
                Db::rollback();
                throw $e;
            }

            OperationLog::record('online_data', 'bind_meituan_review_order_match', 'Confirm Meituan review order: ' . $reviewId . ' -> ' . $orderId, $this->currentUser->id ?? null, $systemHotelId);
            return $this->success([
                'id' => (int)$readback['id'],
                'review_id' => $reviewId,
                'order_id' => $orderId,
                'match_status' => 'matched',
                'save_status' => 'saved_and_readback_verified',
                'data_status' => 'ready',
                'readback' => $this->publicMeituanMatchReadback($readback),
                'source_status' => $this->meituanReviewMatchWriteSourceStatus('manual_confirm'),
                'review_cards' => $this->loadMeituanReviewMatchReviewCards($systemHotelId, 30),
            ], '美团点评订单已人工确认并完成保存回读');
        } catch (\think\exception\HttpException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getStatusCode()));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 404);
        } catch (\Throwable $e) {
            return $this->error('人工确认美团点评订单失败: ' . $e->getMessage(), 500);
        }
    }

    public function rejectMeituanReviewOrderMatch(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        try {
            $data = $this->requestData();
            $systemHotelId = $this->resolveMeituanReviewMatchHotelId($data);
            if (!$systemHotelId) {
                return $this->error('请选择酒店', 400);
            }
            $reviewId = $this->meituanReviewMatchReviewId($data);
            $orderId = trim((string)($data['orderId'] ?? $data['order_id'] ?? ''));
            $reason = trim((string)($data['reason'] ?? $data['decision_reason'] ?? $data['decisionReason'] ?? ''));
            if ($reviewId === '') {
                return $this->error('缺少 reviewId', 422);
            }
            if ($reason === '') {
                return $this->error('人工否决必须填写原因', 422);
            }

            $now = date('Y-m-d H:i:s');
            Db::startTrans();
            try {
                $review = Db::name('ota_meituan_reviews')
                    ->where('system_hotel_id', $systemHotelId)
                    ->where('review_id', $reviewId)
                    ->lock(true)
                    ->find();
                if (!$review) {
                    throw new \RuntimeException('当前酒店不存在该美团点评，不能人工否决');
                }
                if ($orderId !== '') {
                    $orderExists = Db::name('ota_meituan_orders')
                        ->where('system_hotel_id', $systemHotelId)
                        ->where('order_id', $orderId)
                        ->lock(true)
                        ->find();
                    if (!$orderExists) {
                        throw new \RuntimeException('当前酒店不存在该美团订单，不能作为否决对象');
                    }
                }
                $existing = Db::name('ota_meituan_review_order_matches')
                    ->where('system_hotel_id', $systemHotelId)
                    ->where('review_id', $reviewId)
                    ->lock(true)
                    ->find();
                $row = [
                    'system_hotel_id' => $systemHotelId,
                    'review_id' => $reviewId,
                    'order_id' => '',
                    'meituan_user_id' => '',
                    'guest_name_masked' => '',
                    'match_status' => 'rejected',
                    'match_method' => 'manual_reject',
                    'confidence' => 'none',
                    'candidate_orders_json' => $this->encodeMeituanReviewMatchJson([]),
                    'evidence_json' => $this->encodeMeituanReviewMatchJson([
                        'source' => 'manual_reject',
                        'scope' => 'meituan_ota_channel',
                        'operator_user_id' => $this->currentUser->id ?? null,
                        'rejected_order_id' => $orderId,
                        'previous_order_id' => (string)($existing['order_id'] ?? ''),
                        'reason' => $this->shortMeituanReviewMatchText($reason, 300),
                        'identity_resolution' => 'blocked_not_attempted',
                        'phone_evidence_used' => false,
                    ]),
                    'bound_by' => $this->currentUser->id ?? null,
                    'bound_at' => $now,
                    'update_time' => $now,
                ];
                $id = $this->upsertMeituanReviewMatchRowWithinTransaction($existing, $row);
                $readback = $this->readMeituanReviewMatchRow($id, $systemHotelId, $reviewId);
                $this->assertMeituanReviewReadback($readback, $row, ['order_id', 'match_status', 'match_method', 'confidence', 'bound_by', 'bound_at']);
                Db::commit();
            } catch (\Throwable $e) {
                Db::rollback();
                throw $e;
            }

            OperationLog::record('online_data', 'reject_meituan_review_order_match', 'Reject Meituan review order candidate: ' . $reviewId, $this->currentUser->id ?? null, $systemHotelId);
            return $this->success([
                'id' => (int)$readback['id'],
                'review_id' => $reviewId,
                'match_status' => 'rejected',
                'save_status' => 'saved_and_readback_verified',
                'data_status' => 'ready',
                'readback' => $this->publicMeituanMatchReadback($readback),
                'source_status' => $this->meituanReviewMatchWriteSourceStatus('manual_reject'),
                'review_cards' => $this->loadMeituanReviewMatchReviewCards($systemHotelId, 30),
            ], '美团点评订单候选已否决并完成保存回读');
        } catch (\think\exception\HttpException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getStatusCode()));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 404);
        } catch (\Throwable $e) {
            return $this->error('否决美团点评订单候选失败: ' . $e->getMessage(), 500);
        }
    }

    public function unbindMeituanReviewOrderMatch(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        try {
            $data = $this->requestData();
            $systemHotelId = $this->resolveMeituanReviewMatchHotelId($data);
            if (!$systemHotelId) {
                return $this->error('请选择酒店', 400);
            }
            $reviewId = $this->meituanReviewMatchReviewId($data);
            if ($reviewId === '') {
                return $this->error('缺少 reviewId', 422);
            }

            $now = date('Y-m-d H:i:s');
            Db::startTrans();
            try {
                $existing = Db::name('ota_meituan_review_order_matches')
                    ->where('system_hotel_id', $systemHotelId)
                    ->where('review_id', $reviewId)
                    ->lock(true)
                    ->find();
                if (!$existing) {
                    throw new \RuntimeException('当前酒店不存在该美团点评匹配记录');
                }
                $row = [
                    'order_id' => '',
                    'meituan_user_id' => '',
                    'guest_name_masked' => '',
                    'match_status' => 'unbound',
                    'match_method' => 'manual_unbind',
                    'confidence' => 'none',
                    'candidate_orders_json' => $this->encodeMeituanReviewMatchJson([]),
                    'evidence_json' => $this->encodeMeituanReviewMatchJson([
                        'source' => 'manual_unbind',
                        'scope' => 'meituan_ota_channel',
                        'previous_order_id' => (string)($existing['order_id'] ?? ''),
                        'previous_status' => (string)($existing['match_status'] ?? ''),
                        'operator_user_id' => $this->currentUser->id ?? null,
                    ]),
                    'bound_by' => null,
                    'bound_at' => null,
                    'update_time' => $now,
                ];
                Db::name('ota_meituan_review_order_matches')->where('id', (int)$existing['id'])->update($row);
                $readback = $this->readMeituanReviewMatchRow((int)$existing['id'], $systemHotelId, $reviewId);
                $this->assertMeituanReviewReadback($readback, $row, ['order_id', 'match_status', 'match_method', 'confidence', 'bound_by', 'bound_at']);
                Db::commit();
            } catch (\Throwable $e) {
                Db::rollback();
                throw $e;
            }

            OperationLog::record('online_data', 'unbind_meituan_review_order_match', 'Unbind Meituan review order: ' . $reviewId, $this->currentUser->id ?? null, $systemHotelId);
            return $this->success([
                'id' => (int)$readback['id'],
                'review_id' => $reviewId,
                'match_status' => 'unbound',
                'save_status' => 'saved_and_readback_verified',
                'data_status' => 'ready',
                'readback' => $this->publicMeituanMatchReadback($readback),
                'source_status' => $this->meituanReviewMatchWriteSourceStatus('manual_unbind'),
                'review_cards' => $this->loadMeituanReviewMatchReviewCards($systemHotelId, 30),
            ], '美团点评订单绑定已撤销并完成保存回读');
        } catch (\think\exception\HttpException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getStatusCode()));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 404);
        } catch (\Throwable $e) {
            return $this->error('撤销美团点评订单绑定失败: ' . $e->getMessage(), 500);
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
    }

    /** @param array<string,mixed> $requestData */
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

    /** @param array<string,mixed> $data */
    private function meituanReviewMatchReviewId(array $data): string
    {
        return trim((string)($data['reviewId'] ?? $data['review_id'] ?? $data['commentId'] ?? $data['comment_id'] ?? ''));
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function resolveMeituanReviewForLookup(int $systemHotelId, array $data): array
    {
        $reviewId = $this->meituanReviewMatchReviewId($data);
        if ($reviewId === '' && is_array($data['review'] ?? null)) {
            $reviewId = (new MeituanReviewOrderMatchService())->extractReviewId($data['review']);
        }
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
        $raw = $this->decodeMeituanReviewMatchJson((string)($row['raw_review_json'] ?? '{}'));

        return [
            'reviewId' => (string)$row['review_id'],
            'reviewDate' => (string)($row['review_date'] ?? ''),
            'checkInDate' => (string)($row['checkin_date'] ?? ''),
            'roomName' => (string)$row['room_name'],
            'score' => $row['score'] ?? null,
            'meituanOrderId' => (string)($raw['visible_order_id'] ?? ''),
            'platform' => 'meituan',
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function loadMeituanReviewsForMatch(int $systemHotelId, int $limit = 200): array
    {
        $rows = Db::name('ota_meituan_reviews')
            ->where('system_hotel_id', $systemHotelId)
            ->order('review_date', 'desc')
            ->order('id', 'desc')
            ->limit(max(1, min(500, $limit)))
            ->select()
            ->toArray();

        return array_map(function (array $row): array {
            $raw = $this->decodeMeituanReviewMatchJson((string)($row['raw_review_json'] ?? '{}'));
            return [
                'reviewId' => (string)$row['review_id'],
                'reviewDate' => (string)($row['review_date'] ?? ''),
                'checkInDate' => (string)($row['checkin_date'] ?? ''),
                'roomName' => (string)$row['room_name'],
                'score' => $row['score'] ?? null,
                'meituanOrderId' => (string)($raw['visible_order_id'] ?? ''),
                'platform' => 'meituan',
            ];
        }, $rows);
    }

    /** @return array<int,array<string,mixed>> */
    private function loadMeituanOrderPool(int $systemHotelId): array
    {
        $rows = Db::name('ota_meituan_orders')
            ->where('system_hotel_id', $systemHotelId)
            ->order('arrival_date', 'desc')
            ->order('id', 'desc')
            ->select()
            ->toArray();

        return array_map(function (array $row): array {
            $raw = $this->decodeMeituanReviewMatchJson((string)($row['raw_order_json'] ?? '{}'));
            return [
                'orderId' => (string)$row['order_id'],
                'checkInDate' => (string)($row['arrival_date'] ?? ''),
                'checkOutDate' => (string)($row['departure_date'] ?? ''),
                'roomName' => (string)$row['room_name'],
                'orderStatus' => (string)$row['order_status'],
                'detailVerified' => (bool)($raw['detail_verified'] ?? false),
                'amount' => isset($raw['amount']) && is_numeric($raw['amount']) ? (float)$raw['amount'] : null,
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

    /** @param array<string,mixed> $review @param array<string,mixed> $result @return array<string,mixed> */
    private function saveMeituanReviewMatchAttempt(int $systemHotelId, array $review, array $result, string $source): array
    {
        $service = new MeituanReviewOrderMatchService();
        $reviewId = $service->extractReviewId($review);
        if ($reviewId === '') {
            throw new \RuntimeException('美团点评匹配结果缺少 reviewId，未写入');
        }

        $now = date('Y-m-d H:i:s');
        Db::startTrans();
        try {
            $existing = Db::name('ota_meituan_review_order_matches')
                ->where('system_hotel_id', $systemHotelId)
                ->where('review_id', $reviewId)
                ->lock(true)
                ->find();
            if ($existing && in_array((string)($existing['match_status'] ?? ''), ['matched', 'rejected', 'unbound'], true)) {
                Db::commit();
                return $existing + ['manual_decision_preserved' => true];
            }

            $order = is_array($result['order'] ?? null) ? $result['order'] : [];
            $row = [
                'system_hotel_id' => $systemHotelId,
                'review_id' => $reviewId,
                'order_id' => (string)($order['order_id'] ?? ''),
                'meituan_user_id' => '',
                'guest_name_masked' => '',
                'match_status' => (string)($result['status'] ?? 'not_found'),
                'match_method' => (string)($result['match_method'] ?? $source),
                'confidence' => (string)($result['confidence'] ?? 'none'),
                'candidate_orders_json' => $this->encodeMeituanReviewMatchJson($result['candidates'] ?? []),
                'evidence_json' => $this->encodeMeituanReviewMatchJson([
                    'source' => $source,
                    'scope' => 'meituan_ota_channel',
                    'identity_resolution' => 'blocked_not_attempted',
                    'phone_evidence_used' => false,
                    'result' => $result,
                ]),
                'bound_by' => null,
                'bound_at' => null,
                'update_time' => $now,
            ];
            $id = $this->upsertMeituanReviewMatchRowWithinTransaction($existing, $row);
            $readback = $this->readMeituanReviewMatchRow($id, $systemHotelId, $reviewId);
            $this->assertMeituanReviewReadback($readback, $row, ['order_id', 'match_status', 'match_method', 'confidence', 'candidate_orders_json', 'evidence_json']);
            Db::commit();
            return $readback;
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /** @param array<string,mixed>|null $existing @param array<string,mixed> $row */
    private function upsertMeituanReviewMatchRowWithinTransaction(?array $existing, array $row): int
    {
        if ($existing) {
            Db::name('ota_meituan_review_order_matches')->where('id', (int)$existing['id'])->update($row);
            return (int)$existing['id'];
        }
        $row['create_time'] = $row['update_time'] ?? date('Y-m-d H:i:s');
        return (int)Db::name('ota_meituan_review_order_matches')->insertGetId($row);
    }

    /** @return array<string,mixed> */
    private function readMeituanReviewMatchRow(int $id, int $systemHotelId, string $reviewId): array
    {
        $readback = Db::name('ota_meituan_review_order_matches')
            ->where('id', $id)
            ->where('system_hotel_id', $systemHotelId)
            ->where('review_id', $reviewId)
            ->find();
        if (!$readback) {
            throw new \LogicException('美团点评订单匹配保存后精确回读失败');
        }
        return $readback;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,string> $verifyFields
     * @return array<string,mixed>
     */
    private function upsertMeituanReviewScopedRow(
        string $table,
        int $systemHotelId,
        string $keyField,
        string $keyValue,
        array $row,
        array $verifyFields
    ): array {
        Db::startTrans();
        try {
            $existing = Db::name($table)
                ->where('system_hotel_id', $systemHotelId)
                ->where($keyField, $keyValue)
                ->lock(true)
                ->find();
            if ($existing) {
                Db::name($table)->where('id', (int)$existing['id'])->update($row);
                $id = (int)$existing['id'];
            } else {
                $row['create_time'] = $row['update_time'] ?? date('Y-m-d H:i:s');
                $id = (int)Db::name($table)->insertGetId($row);
            }
            $readback = Db::name($table)
                ->where('id', $id)
                ->where('system_hotel_id', $systemHotelId)
                ->where($keyField, $keyValue)
                ->find();
            if (!$readback) {
                throw new \LogicException('美团点评证据保存后精确回读失败');
            }
            $this->assertMeituanReviewReadback($readback, $row, $verifyFields);
            Db::commit();
            return $readback;
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /** @param array<string,mixed> $readback @param array<string,mixed> $expected @param array<int,string> $fields */
    private function assertMeituanReviewReadback(array $readback, array $expected, array $fields): void
    {
        foreach ($fields as $field) {
            $actual = $readback[$field] ?? null;
            $wanted = $expected[$field] ?? null;
            if (is_numeric($actual) && is_numeric($wanted)) {
                if ((float)$actual === (float)$wanted) {
                    continue;
                }
            } elseif ((string)($actual ?? '') === (string)($wanted ?? '')) {
                continue;
            }
            throw new \LogicException('美团点评证据保存后字段回读不一致: ' . $field);
        }
    }

    /** @return array<string,mixed> */
    private function buildMeituanReviewMatchClosureStatus(int $systemHotelId, int $minMatched): array
    {
        $sourceTables = [
            'meituan_reviews' => (int)Db::name('ota_meituan_reviews')->where('system_hotel_id', $systemHotelId)->count(),
            'meituan_orders' => (int)Db::name('ota_meituan_orders')->where('system_hotel_id', $systemHotelId)->count(),
            'meituan_review_order_matches' => (int)Db::name('ota_meituan_review_order_matches')->where('system_hotel_id', $systemHotelId)->count(),
        ];
        $statusRows = Db::name('ota_meituan_review_order_matches')
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

        $manualMatched = (int)($statusCounts['matched'] ?? 0);
        $evidenceReady = (int)($statusCounts['confirmed'] ?? 0) + (int)($statusCounts['high_confidence'] ?? 0);
        $missingSources = [];
        foreach (['meituan_reviews', 'meituan_orders'] as $key) {
            if (($sourceTables[$key] ?? 0) <= 0) {
                $missingSources[] = $key;
            }
        }
        if ($manualMatched < $minMatched) {
            $missingSources[] = 'manually_confirmed_matches';
        }
        $ready = $missingSources === [];

        return [
            'mode' => 'closure_check',
            'status' => $ready ? 'completed' : 'not_ready',
            'scope' => 'meituan_ota_channel',
            'summary' => [
                'review_count' => $sourceTables['meituan_reviews'],
                'order_count' => $sourceTables['meituan_orders'],
                'manual_matched_count' => $manualMatched,
                'evidence_ready_count' => $evidenceReady,
                'confirmed_count' => (int)($statusCounts['confirmed'] ?? 0),
                'high_confidence_count' => (int)($statusCounts['high_confidence'] ?? 0),
                'candidate_count' => (int)($statusCounts['candidate'] ?? 0),
                'ambiguous_count' => (int)($statusCounts['ambiguous'] ?? 0),
                'not_found_count' => (int)($statusCounts['not_found'] ?? 0),
                'rejected_count' => (int)($statusCounts['rejected'] ?? 0),
                'unbound_count' => (int)($statusCounts['unbound'] ?? 0),
            ],
            'status_counts' => $statusCounts,
            'review_cards' => $this->loadMeituanReviewMatchReviewCards($systemHotelId, 30),
            'missing_sources' => array_values(array_unique($missingSources)),
            'source_status' => [
                'scope' => 'meituan_ota_channel',
                'detail_sources_ready' => $ready,
                'source_tables' => $sourceTables,
                'policy' => 'authorized_saved_evidence_and_manual_confirmation',
                'storage_write' => false,
                'identity_resolution' => 'blocked_not_attempted',
                'phone_acquisition' => 'blocked_not_attempted',
            ],
            'required' => [
                'min_matched' => $minMatched,
                'required_sources' => ['meituan_reviews', 'meituan_orders'],
                'accepted_formal_match_statuses' => ['matched'],
                'evidence_ready_statuses' => ['confirmed', 'high_confidence'],
            ],
            'next_action' => $ready
                ? '美团点评与订单证据已由人工确认并保存回读'
                : '补齐真实授权的美团点评/订单，运行候选计算，再人工确认至少一条；未知数据不会按0处理',
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function loadMeituanReviewMatchReviewCards(int $systemHotelId, int $limit = 30): array
    {
        $reviewRows = Db::name('ota_meituan_reviews')
            ->where('system_hotel_id', $systemHotelId)
            ->field('review_id, review_date, checkin_date, room_name, score, update_time')
            ->order('review_date', 'desc')
            ->order('id', 'desc')
            ->limit(max(1, min(50, $limit)))
            ->select()
            ->toArray();
        if ($reviewRows === []) {
            return [];
        }

        $reviewIds = array_values(array_unique(array_filter(array_map(
            static fn(array $row): string => trim((string)($row['review_id'] ?? '')),
            $reviewRows
        ))));
        $matchesByReviewId = [];
        if ($reviewIds !== []) {
            $matchRows = Db::name('ota_meituan_review_order_matches')
                ->where('system_hotel_id', $systemHotelId)
                ->whereIn('review_id', $reviewIds)
                ->order('update_time', 'desc')
                ->order('id', 'desc')
                ->select()
                ->toArray();
            foreach ($matchRows as $matchRow) {
                $reviewId = trim((string)($matchRow['review_id'] ?? ''));
                if ($reviewId !== '' && !isset($matchesByReviewId[$reviewId])) {
                    $matchesByReviewId[$reviewId] = $matchRow;
                }
            }
        }

        return array_map(function (array $reviewRow) use ($matchesByReviewId): array {
            $reviewId = trim((string)($reviewRow['review_id'] ?? ''));
            $match = $matchesByReviewId[$reviewId] ?? [];
            $status = (string)($match['match_status'] ?? 'unmatched');
            $orderId = (string)($match['order_id'] ?? '');
            $candidates = $this->decodeMeituanReviewMatchJson((string)($match['candidate_orders_json'] ?? '[]'));
            $candidate = $this->firstPublicMeituanReviewCandidate($candidates);
            $evidence = $this->decodeMeituanReviewMatchJson((string)($match['evidence_json'] ?? '{}'));
            $result = is_array($evidence['result'] ?? null) ? $evidence['result'] : [];
            return [
                'review_id' => $reviewId,
                'review_date' => (string)($reviewRow['review_date'] ?? ''),
                'checkin_date' => (string)($reviewRow['checkin_date'] ?? ''),
                'room_name' => (string)($reviewRow['room_name'] ?? ''),
                'score' => $reviewRow['score'] ?? null,
                'status' => $status,
                'status_text' => $this->publicMeituanReviewMatchStatusText($status, $orderId, is_array($candidates) ? count($candidates) : 0),
                'order_id' => $orderId,
                'candidate_count' => is_array($candidates) ? count($candidates) : 0,
                'candidate_order_id' => (string)($candidate['order_id'] ?? ''),
                'candidate_arrival_date' => (string)($candidate['arrival_date'] ?? ''),
                'candidate_room_name' => (string)($candidate['room_name'] ?? ''),
                'confidence' => (string)($match['confidence'] ?? 'none'),
                'match_score' => isset($result['score']) && is_numeric($result['score']) ? (int)$result['score'] : null,
                'score_gap' => isset($result['score_gap']) && is_numeric($result['score_gap']) ? (int)$result['score_gap'] : null,
                'score_breakdown' => is_array($result['score_breakdown'] ?? null) ? $result['score_breakdown'] : [],
                'review_flags' => is_array($result['review_flags'] ?? null) ? $result['review_flags'] : [],
                'missing_evidence' => is_array($result['missing_evidence'] ?? null) ? $result['missing_evidence'] : [],
                'window_used' => (string)($result['window_used'] ?? ''),
                'reason' => (string)($result['reason'] ?? ''),
                'updated_at' => (string)($match['update_time'] ?? $reviewRow['update_time'] ?? ''),
            ];
        }, $reviewRows);
    }

    /** @param array<string,mixed> $review @param array<string,mixed> $result @param array<string,mixed> $readback @return array<string,mixed> */
    private function buildMeituanReviewMatchSample(array $review, array $result, array $readback): array
    {
        $candidates = is_array($result['candidates'] ?? null) ? $result['candidates'] : [];
        $candidate = $this->firstPublicMeituanReviewCandidate($candidates);
        $storedStatus = (string)($readback['match_status'] ?? $result['status'] ?? 'unknown');
        return [
            'review_id' => (new MeituanReviewOrderMatchService())->extractReviewId($review),
            'status' => $storedStatus,
            'status_text' => $this->publicMeituanReviewMatchStatusText($storedStatus, (string)($readback['order_id'] ?? ''), count($candidates)),
            'order_id' => (string)($readback['order_id'] ?? ''),
            'candidate_count' => count($candidates),
            'candidate_order_id' => (string)($candidate['order_id'] ?? ''),
            'candidate_arrival_date' => (string)($candidate['arrival_date'] ?? ''),
            'match_score' => isset($result['score']) && is_numeric($result['score']) ? (int)$result['score'] : null,
            'score_breakdown' => is_array($result['score_breakdown'] ?? null) ? $result['score_breakdown'] : [],
            'review_flags' => is_array($result['review_flags'] ?? null) ? $result['review_flags'] : [],
            'missing_evidence' => is_array($result['missing_evidence'] ?? null) ? $result['missing_evidence'] : [],
            'window_used' => (string)($result['window_used'] ?? ''),
            'reason' => (string)($result['reason'] ?? ''),
            'manual_decision_preserved' => (bool)($readback['manual_decision_preserved'] ?? false),
        ];
    }

    /** @param mixed $candidates @return array<string,mixed>|null */
    private function firstPublicMeituanReviewCandidate($candidates): ?array
    {
        if (!is_array($candidates)) {
            return null;
        }
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            return [
                'order_id' => (string)($candidate['order_id'] ?? ''),
                'arrival_date' => (string)($candidate['arrival_date'] ?? ''),
                'departure_date' => (string)($candidate['departure_date'] ?? ''),
                'room_name' => (string)($candidate['room_name'] ?? ''),
                'order_status' => (string)($candidate['order_status'] ?? ''),
                'score' => isset($candidate['score']) && is_numeric($candidate['score']) ? (int)$candidate['score'] : null,
                'score_breakdown' => is_array($candidate['score_breakdown'] ?? null) ? $candidate['score_breakdown'] : [],
                'evidence' => is_array($candidate['evidence'] ?? null) ? $candidate['evidence'] : [],
                'missing_evidence' => is_array($candidate['missing_evidence'] ?? null) ? $candidate['missing_evidence'] : [],
            ];
        }
        return null;
    }

    private function publicMeituanReviewMatchStatusText(string $status, string $orderId, int $candidateCount): string
    {
        return match ($status) {
            'matched' => $orderId !== '' ? '已人工确认订单 ' . $orderId : '已人工确认',
            'confirmed' => $orderId !== '' ? '订单标识强证据命中 ' . $orderId . '，待人工确认' : '订单标识强证据命中，待人工确认',
            'high_confidence' => '高置信候选，待人工确认',
            'candidate' => $candidateCount > 0 ? '有 ' . $candidateCount . ' 个候选，需人工复核' : '证据不足，需人工复核',
            'ambiguous' => $candidateCount > 0 ? '候选歧义（' . $candidateCount . ' 条），不能自动确认' : '候选歧义，不能自动确认',
            'rejected' => '已人工否决',
            'unbound' => '已撤销绑定',
            'not_found' => '未找到候选',
            default => '尚未运行候选计算',
        };
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function publicMeituanReviewStorageReadback(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'system_hotel_id' => (int)$row['system_hotel_id'],
            'review_id' => (string)$row['review_id'],
            'review_date' => (string)($row['review_date'] ?? ''),
            'checkin_date' => (string)($row['checkin_date'] ?? ''),
            'room_name' => (string)($row['room_name'] ?? ''),
            'score' => $row['score'] ?? null,
            'identity_fields_stored' => false,
            'content_stored' => false,
            'update_time' => (string)($row['update_time'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function publicMeituanOrderStorageReadback(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'system_hotel_id' => (int)$row['system_hotel_id'],
            'order_id' => (string)$row['order_id'],
            'arrival_date' => (string)($row['arrival_date'] ?? ''),
            'departure_date' => (string)($row['departure_date'] ?? ''),
            'room_name' => (string)($row['room_name'] ?? ''),
            'order_status' => (string)($row['order_status'] ?? ''),
            'identity_fields_stored' => false,
            'phone_fields_stored' => false,
            'phone_status' => (string)($row['phone_status'] ?? ''),
            'update_time' => (string)($row['update_time'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function publicMeituanMatchReadback(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'system_hotel_id' => (int)$row['system_hotel_id'],
            'review_id' => (string)$row['review_id'],
            'order_id' => (string)($row['order_id'] ?? ''),
            'match_status' => (string)($row['match_status'] ?? ''),
            'match_method' => (string)($row['match_method'] ?? ''),
            'confidence' => (string)($row['confidence'] ?? 'none'),
            'bound_by' => isset($row['bound_by']) ? (int)$row['bound_by'] : null,
            'bound_at' => (string)($row['bound_at'] ?? ''),
            'identity_fields_stored' => false,
            'phone_evidence_used' => false,
            'update_time' => (string)($row['update_time'] ?? ''),
        ];
    }

    /** @return array<string,mixed> */
    private function meituanReviewMatchWriteSourceStatus(string $policy): array
    {
        return [
            'scope' => 'meituan_ota_channel',
            'policy' => $policy,
            'storage_write' => true,
            'same_hotel_scope_verified' => true,
            'identity_resolution' => 'blocked_not_attempted',
            'phone_acquisition' => 'blocked_not_attempted',
        ];
    }

    private function nullableMeituanReviewMatchDate($value): ?string
    {
        $text = trim((string)$value);
        return $text === '' ? null : $text;
    }

    private function meituanReviewMatchRoomPrefix(string $roomName): string
    {
        $roomName = trim($roomName);
        if ($roomName === '') {
            return '';
        }
        $parts = preg_split('/[-|_]/', $roomName);
        return trim((string)($parts[0] ?? $roomName));
    }

    private function shortMeituanReviewMatchText(string $value, int $limit): string
    {
        $value = trim($value);
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $limit, 'UTF-8');
        }
        return substr($value, 0, $limit);
    }

    /** @param mixed $value */
    private function encodeMeituanReviewMatchJson($value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }

    /** @return array<string,mixed>|array<int,mixed> */
    private function decodeMeituanReviewMatchJson(string $value): array
    {
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
