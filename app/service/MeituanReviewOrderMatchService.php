<?php
declare(strict_types=1);

namespace app\service;

/**
 * Matches Meituan reviews to the selected hotel's authorized Meituan order
 * evidence. Guest identity, phone values and anonymous-user reconstruction are
 * deliberately outside this contract.
 */
final class MeituanReviewOrderMatchService
{
    private const MIN_CANDIDATE_SCORE_GAP = 20;
    private const VALID_STATUSES = ['已退房', '已离店', '已完成', '完成', 'checkedout', 'checked_out', 'completed'];
    private const WEAK_STATUSES = ['已入住', '进行中', '已预订', '待确认', '预订', 'booked', 'reserved', 'pending', 'checkedin', 'checked_in'];
    private const INVALID_STATUSES = ['已取消', '已关闭', '取消', '关闭', 'noshow', 'no show', 'cancelled', 'canceled', 'closed', 'refunded'];

    /**
     * @param array<string, mixed> $review
     * @param array<int, array<string, mixed>> $orders
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function matchReviewToOrder(array $review, array $orders, array $options = []): array
    {
        return $this->buildReviewOrderMatches([$review], $orders, $options)[0];
    }

    /**
     * @param array<int, array<string, mixed>> $reviews
     * @param array<int, array<string, mixed>> $orders
     * @param array<string, mixed> $options
     * @return array<int, array<string, mixed>>
     */
    public function buildReviewOrderMatches(array $reviews, array $orders, array $options = []): array
    {
        $meituanOrders = array_values(array_filter(
            $orders,
            fn(array $order): bool => $this->isMeituanOrder($order)
        ));

        $results = [];
        foreach ($reviews as $review) {
            $results[] = $this->scoreReview($review, $meituanOrders, $options);
        }

        $topOrderHits = [];
        foreach ($results as $result) {
            if (($result['status'] ?? '') === 'confirmed') {
                continue;
            }
            $orderId = trim((string)($result['candidates'][0]['order_id'] ?? ''));
            if ($orderId !== '') {
                $topOrderHits[$orderId] = ($topOrderHits[$orderId] ?? 0) + 1;
            }
        }

        foreach ($results as $index => $result) {
            $orderId = trim((string)($result['candidates'][0]['order_id'] ?? ''));
            if ($orderId === '' || (int)($topOrderHits[$orderId] ?? 0) <= 1) {
                continue;
            }
            $result['status'] = 'ambiguous';
            $result['review_status'] = 'ambiguous';
            $result['confidence'] = 'ambiguous';
            $result['reason'] = 'same_order_is_top_candidate_for_multiple_reviews';
            $result['order'] = null;
            $result['review_flags'] = array_values(array_unique(array_merge(
                is_array($result['review_flags'] ?? null) ? $result['review_flags'] : [],
                ['同一订单同时成为多条点评的首选候选']
            )));
            $results[$index] = $result;
        }

        return $results;
    }

    /**
     * Phone retrieval and phone-derived matching remain prohibited. The method
     * stays available so callers receive an explicit, stable failure contract.
     *
     * @param array<string, mixed> $order
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function buildPhoneHandlingState(array $order, array $context = []): array
    {
        return (new OtaReviewRiskPolicyService())->blockedOperation('meituan_order_phone_state_service', [
            'phone_acquisition',
            'identity_reverse_lookup',
        ]);
    }

    /**
     * Returns the minimum safe order evidence allowed in raw storage. It never
     * retains a guest name, user identifier, phone value, free-text remark or
     * the plain order identifier.
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    public function sanitizeOrderForStorage(array $order): array
    {
        $orderId = $this->extractOrderId($order);
        $amount = $this->extractAmount($order);
        $safe = [
            'order_id_hash' => $orderId === '' ? '' : hash('sha256', 'meituan_order|' . $orderId),
            'arrival_date' => $this->extractDate($order, ['checkInDate', 'check_in_date', 'checkinDate', 'arrivalDate', 'arrival_date']),
            'departure_date' => $this->extractDate($order, ['checkOutDate', 'check_out_date', 'departureDate', 'departure_date']),
            'room_name' => $this->extractRoomName($order),
            'order_status' => $this->firstString($order, ['orderStatus', 'order_status', 'status']),
            'detail_verified' => $this->isTrue($this->firstValue($order, ['detailVerified', 'detail_verified', 'orderDetailVerified', 'order_detail_verified'])),
            'source_platform' => 'meituan',
        ];
        if ($amount !== null) {
            $safe['amount'] = $amount;
        }
        return $safe;
    }

    /**
     * @param array<string, mixed> $review
     * @return array<string, mixed>
     */
    public function normalizeReviewForStorage(array $review): array
    {
        $reviewId = $this->extractReviewId($review);
        $content = trim((string)($review['content'] ?? $review['comment'] ?? $review['reviewContent'] ?? ''));
        $reviewDate = $this->extractDate($review, ['reviewDate', 'review_date', 'commentTime', 'comment_time', 'addTime', 'add_time', 'publishTime', 'publish_time']);
        $checkinDate = $this->extractDate($review, ['checkInDate', 'check_in_date', 'checkinDate', 'arrivalDate', 'arrival_date', 'consumeDate', 'consume_date']);
        $roomName = $this->extractRoomName($review);
        $score = $this->extractScore($review);
        $visibleOrderId = $this->reviewOrderIdentifier($review);
        $safeRaw = [
            'review_id_hash' => $reviewId === '' ? '' : hash('sha256', 'meituan_review|' . $reviewId),
            'review_date' => $reviewDate,
            'checkin_date' => $checkinDate,
            'room_name' => $roomName,
            'score' => $score,
            'source_platform' => 'meituan',
        ];
        if ($visibleOrderId !== '') {
            $safeRaw['visible_order_id'] = $visibleOrderId;
        }
        if ($content !== '') {
            $safeRaw['content_hash'] = hash('sha256', $content);
        }

        return [
            'review_id' => $reviewId,
            'meituan_user_id' => '',
            'source_username' => '',
            'review_date' => $reviewDate,
            'checkin_date' => $checkinDate,
            'room_name' => $roomName,
            'score' => $score,
            'content' => '',
            'raw_review_json' => $this->encodeJson($safeRaw),
        ];
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    public function normalizeOrderForStorage(array $order): array
    {
        return [
            'order_id' => $this->extractOrderId($order),
            'meituan_user_id' => '',
            'guest_name_masked' => '',
            'arrival_date' => $this->extractDate($order, ['checkInDate', 'check_in_date', 'checkinDate', 'arrivalDate', 'arrival_date']),
            'departure_date' => $this->extractDate($order, ['checkOutDate', 'check_out_date', 'departureDate', 'departure_date']),
            'room_name' => $this->extractRoomName($order),
            'order_status' => $this->firstString($order, ['orderStatus', 'order_status', 'status']),
            'phone_masked' => '',
            'phone_last4' => '',
            'phone_status' => OtaReviewRiskPolicyService::STATUS_BLOCKED,
            'phone_source' => 'not_collected_by_policy',
            'raw_order_json' => $this->encodeJson($this->sanitizeOrderForStorage($order)),
        ];
    }

    /** @param array<string, mixed> $review */
    public function extractReviewId(array $review): string
    {
        return $this->firstString($review, ['reviewId', 'review_id', 'commentId', 'comment_id', 'id']);
    }

    /** @param array<string, mixed> $order */
    public function extractOrderId(array $order): string
    {
        return $this->firstString($order, [
            'meituanOrderId', 'meituan_order_id', 'mtOrderId', 'mt_order_id',
            'channelOrderNo', 'channel_order_no', 'orderId', 'order_id',
            'orderNo', 'order_no', 'platform_order_id', 'id',
        ]);
    }

    /**
     * @param array<string, mixed> $review
     * @param array<int, array<string, mixed>> $orders
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function scoreReview(array $review, array $orders, array $options): array
    {
        $base = [
            'scope' => 'meituan_ota_channel',
            'match_subject' => 'authorized_order_evidence',
            'identity_resolution' => 'blocked_not_attempted',
            'phone_evidence_used' => false,
            'storage_contains_guest_identity' => false,
            'requires_manual_confirmation' => true,
        ];
        if ($orders === []) {
            return $base + [
                'status' => 'not_found',
                'review_status' => 'not_found',
                'reason' => 'meituan_order_pool_empty',
                'confidence' => 'not_found',
                'missing_evidence' => ['authorized_meituan_orders'],
                'candidates' => [],
                'search_windows' => [],
            ];
        }

        $strongOrderId = $this->reviewOrderIdentifier($review);
        if ($strongOrderId !== '') {
            $strongMatches = array_values(array_filter(
                $orders,
                fn(array $order): bool => in_array($strongOrderId, $this->orderIdentifiers($order), true)
            ));
            if (count($strongMatches) === 1) {
                $candidate = $this->publicCandidate($strongMatches[0], 100, [
                    'strong_identifier_score' => 100,
                    'room_score' => 0,
                    'date_score' => 0,
                    'status_score' => 0,
                    'detail_review_score' => 0,
                    'uniqueness_score' => 0,
                ], ['点评可见订单标识与当前酒店美团订单标识完全一致'], []);
                return $base + [
                    'status' => 'confirmed',
                    'review_status' => 'confirmed',
                    'reason' => 'strong_order_identifier_match',
                    'confidence' => 'confirmed',
                    'match_method' => 'platform_review_order_link',
                    'order' => $this->normalizeOrderForResponse($strongMatches[0]),
                    'candidates' => [$candidate],
                    'score' => 100,
                    'score_breakdown' => $candidate['score_breakdown'],
                    'missing_evidence' => [],
                    'evidence' => [
                        'groups' => ['review_order_identifier', 'authorized_meituan_order_pool'],
                        'matched_identifier' => $strongOrderId,
                        'candidate_count' => 1,
                        'store_scope' => 'selected_system_hotel',
                    ],
                    'review_flags' => [],
                    'window_used' => 'strong_identifier',
                    'search_windows' => [],
                ];
            }

            return $base + [
                'status' => $strongMatches === [] ? 'not_found' : 'ambiguous',
                'review_status' => $strongMatches === [] ? 'not_found' : 'ambiguous',
                'reason' => $strongMatches === [] ? 'strong_order_link_not_in_order_pool' : 'duplicate_strong_order_identifier',
                'confidence' => $strongMatches === [] ? 'not_found' : 'ambiguous',
                'missing_evidence' => $strongMatches === [] ? ['linked_meituan_order_detail'] : ['unique_order_identifier'],
                'candidates' => array_map(
                    fn(array $order): array => $this->publicCandidate($order, 100, ['strong_identifier_score' => 100], ['订单标识命中'], []),
                    array_slice($strongMatches, 0, 5)
                ),
                'review_flags' => $strongMatches === [] ? [] : ['强订单标识在当前酒店订单池中命中多条记录'],
                'window_used' => 'strong_identifier',
                'search_windows' => [],
            ];
        }

        $coverageStart = $this->normalizeDate((string)($options['coverage_start_date'] ?? ''));
        $reviewStayDate = $this->reviewStayDate($review);
        if ($coverageStart !== '' && $reviewStayDate !== '' && $reviewStayDate < $coverageStart) {
            return $base + [
                'status' => 'not_found',
                'review_status' => 'not_found',
                'reason' => 'review_before_order_coverage',
                'confidence' => 'not_found',
                'review_date' => $reviewStayDate,
                'coverage_start_date' => $coverageStart,
                'missing_evidence' => ['historical_authorized_meituan_orders'],
                'candidates' => [],
                'search_windows' => [],
            ];
        }

        $window = $this->selectCandidateWindow($review, $orders);
        if ($window['orders'] === []) {
            return $base + [
                'status' => 'not_found',
                'review_status' => 'not_found',
                'reason' => 'no_candidate_after_safe_date_windows',
                'confidence' => 'not_found',
                'missing_evidence' => $window['missing_evidence'],
                'candidates' => [],
                'window_used' => 'none',
                'search_windows' => $window['search_windows'],
            ];
        }

        $scored = [];
        foreach ($window['orders'] as $order) {
            $scored[] = $this->scoreCandidate($review, $order, $window['window_used']);
        }
        usort($scored, static function (array $left, array $right): int {
            $scoreCompare = (int)$right['score'] <=> (int)$left['score'];
            return $scoreCompare !== 0 ? $scoreCompare : strcmp((string)$left['order_id'], (string)$right['order_id']);
        });

        $top = $scored[0];
        $second = $scored[1] ?? null;
        $gap = is_array($second) ? (int)$top['score'] - (int)$second['score'] : null;
        $uniqueTop = $gap === null || $gap >= self::MIN_CANDIDATE_SCORE_GAP;
        if ($uniqueTop) {
            $top['score_breakdown']['uniqueness_score'] = 10;
            $top['score'] = min(100, (int)$top['score'] + 10);
            $top['evidence'][] = '当前安全窗口内首选候选唯一且分差达到20';
            $scored[0] = $top;
        }

        $roomScore = (int)($top['score_breakdown']['room_score'] ?? 0);
        $dateScore = (int)($top['score_breakdown']['date_score'] ?? 0);
        $statusScore = (int)($top['score_breakdown']['status_score'] ?? 0);
        $hardConflict = (bool)($top['score_breakdown']['hard_conflict'] ?? false);
        $ambiguous = !$uniqueTop || $hardConflict;
        $highConfidence = !$ambiguous
            && (int)$top['score'] >= 80
            && $roomScore >= 28
            && $dateScore >= 30
            && $statusScore >= 10;
        $status = $ambiguous ? 'ambiguous' : ($highConfidence ? 'high_confidence' : 'candidate');
        $flags = [];
        if (!$uniqueTop) {
            $flags[] = '前两名候选分差小于' . self::MIN_CANDIDATE_SCORE_GAP;
        }
        if ($hardConflict) {
            $flags[] = '候选存在日期或订单状态硬冲突';
        }
        if ($roomScore === 0) {
            $flags[] = '房型证据缺失或不一致';
        }

        $publicCandidates = [];
        foreach (array_slice($scored, 0, 5) as $candidate) {
            unset($candidate['_order']);
            $publicCandidates[] = $candidate;
        }

        return $base + [
            'status' => $status,
            'review_status' => $status,
            'reason' => $status === 'high_confidence'
                ? 'high_confidence_candidate_requires_manual_confirmation'
                : ($status === 'ambiguous' ? 'ambiguous_candidates' : 'candidate_evidence_insufficient'),
            'confidence' => $status,
            'match_method' => 'safe_multidimensional_candidate_scoring',
            'order' => $highConfidence ? $this->normalizeOrderForResponse($top['_order']) : null,
            'candidates' => $publicCandidates,
            'score' => (int)$top['score'],
            'score_gap' => $gap,
            'score_breakdown' => $top['score_breakdown'],
            'missing_evidence' => $top['missing_evidence'],
            'evidence' => [
                'groups' => $top['evidence'],
                'candidate_count' => count($window['orders']),
                'store_scope' => 'selected_system_hotel',
                'identity_resolution' => 'blocked_not_attempted',
                'phone_evidence_used' => false,
            ],
            'review_flags' => $flags,
            'window_used' => $window['window_used'],
            'search_windows' => $window['search_windows'],
        ];
    }

    /**
     * @param array<string, mixed> $review
     * @param array<int, array<string, mixed>> $orders
     * @return array{orders:array<int,array<string,mixed>>,window_used:string,search_windows:array<int,string>,missing_evidence:array<int,string>}
     */
    private function selectCandidateWindow(array $review, array $orders): array
    {
        $searched = [];
        $stayDate = $this->reviewStayDate($review);
        if ($stayDate !== '') {
            $searched[] = 'explicit_stay_date';
            $matched = array_values(array_filter(
                $orders,
                fn(array $order): bool => $this->orderArrivalDate($order) === $stayDate
            ));
            return [
                'orders' => $matched,
                'window_used' => $matched === [] ? 'none' : 'explicit_stay_date',
                'search_windows' => $searched,
                'missing_evidence' => $matched === [] ? ['authorized_order_with_same_arrival_date'] : [],
            ];
        }

        $publishDate = $this->reviewPublishDate($review);
        if ($publishDate !== '') {
            foreach ([[0, 14, 'checkout_to_publish_0_14d'], [15, 30, 'checkout_to_publish_15_30d']] as $definition) {
                [$minimum, $maximum, $name] = $definition;
                $searched[] = $name;
                $matched = array_values(array_filter($orders, function (array $order) use ($publishDate, $minimum, $maximum): bool {
                    $departure = $this->orderDepartureDate($order);
                    if ($departure === '') {
                        return false;
                    }
                    $delta = $this->daysBetween($departure, $publishDate);
                    return $delta !== null && $delta >= $minimum && $delta <= $maximum;
                }));
                if ($matched !== []) {
                    return [
                        'orders' => $matched,
                        'window_used' => $name,
                        'search_windows' => $searched,
                        'missing_evidence' => [],
                    ];
                }
            }
        }

        $stayMonth = $this->firstString($review, ['stayMonth', 'stay_month', 'consumeMonth', 'consume_month']);
        if (preg_match('/^(20\d{2})\D?(0?[1-9]|1[0-2])$/', trim($stayMonth), $matches)) {
            $month = sprintf('%04d-%02d', (int)$matches[1], (int)$matches[2]);
            $searched[] = 'explicit_stay_month';
            $matched = array_values(array_filter(
                $orders,
                fn(array $order): bool => str_starts_with($this->orderArrivalDate($order), $month)
            ));
            return [
                'orders' => $matched,
                'window_used' => $matched === [] ? 'none' : 'explicit_stay_month',
                'search_windows' => $searched,
                'missing_evidence' => $matched === [] ? ['authorized_order_in_review_stay_month'] : [],
            ];
        }

        return [
            'orders' => [],
            'window_used' => 'none',
            'search_windows' => $searched,
            'missing_evidence' => ['review_stay_date_or_publish_date_or_stay_month'],
        ];
    }

    /**
     * @param array<string, mixed> $review
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function scoreCandidate(array $review, array $order, string $window): array
    {
        $breakdown = [
            'strong_identifier_score' => 0,
            'room_score' => 0,
            'date_score' => 0,
            'status_score' => 0,
            'detail_review_score' => 0,
            'uniqueness_score' => 0,
            'hard_conflict' => false,
        ];
        $evidence = [];
        $missing = [];

        [$breakdown['room_score'], $roomEvidence, $roomMissing] = $this->roomScore(
            $this->extractRoomName($review),
            $this->extractRoomName($order)
        );
        $evidence = array_merge($evidence, $roomEvidence);
        $missing = array_merge($missing, $roomMissing);

        [$breakdown['date_score'], $dateConflict, $dateEvidence, $dateMissing] = $this->dateScore($review, $order, $window);
        $breakdown['hard_conflict'] = $dateConflict;
        $evidence = array_merge($evidence, $dateEvidence);
        $missing = array_merge($missing, $dateMissing);

        [$breakdown['status_score'], $statusConflict, $statusEvidence, $statusMissing] = $this->statusScore(
            $this->firstString($order, ['orderStatus', 'order_status', 'status'])
        );
        $breakdown['hard_conflict'] = $breakdown['hard_conflict'] || $statusConflict;
        $evidence = array_merge($evidence, $statusEvidence);
        $missing = array_merge($missing, $statusMissing);

        $detailVerified = $this->isTrue($this->firstValue($order, ['detailVerified', 'detail_verified', 'orderDetailVerified', 'order_detail_verified']));
        if ($detailVerified) {
            $breakdown['detail_review_score'] = 5;
            $evidence[] = '订单详情来源已复核';
        } else {
            $missing[] = '订单详情未复核，候选仍需人工确认';
        }

        $score = 0;
        foreach (['room_score', 'date_score', 'status_score', 'detail_review_score'] as $key) {
            $score += (int)$breakdown[$key];
        }

        $candidate = $this->publicCandidate($order, max(0, $score), $breakdown, $evidence, array_values(array_unique($missing)));
        $candidate['_order'] = $order;
        return $candidate;
    }

    /** @return array{0:int,1:array<int,string>,2:array<int,string>} */
    private function roomScore(string $reviewRoom, string $orderRoom): array
    {
        $review = $this->normalizeRoomName($reviewRoom);
        $order = $this->normalizeRoomName($orderRoom);
        if ($review === '' || $order === '') {
            return [0, [], ['点评或订单房型缺失']];
        }
        if ($review === $order) {
            return [35, ['点评房型与订单房型完全一致'], []];
        }
        if (str_contains($review, $order) || str_contains($order, $review)) {
            return [28, ['点评房型与订单房型前缀/包含关系一致'], []];
        }
        return [0, ['点评房型与订单房型不一致'], ['房型证据不一致，不能高置信']];
    }

    /** @param array<string,mixed> $review @param array<string,mixed> $order @return array{0:int,1:bool,2:array<int,string>,3:array<int,string>} */
    private function dateScore(array $review, array $order, string $window): array
    {
        if ($window === 'explicit_stay_date') {
            $stay = $this->reviewStayDate($review);
            $arrival = $this->orderArrivalDate($order);
            return $stay !== '' && $stay === $arrival
                ? [35, false, ['点评入住日期与订单入住日期完全一致'], []]
                : [0, true, ['点评入住日期与订单不一致'], ['入住日期硬冲突']];
        }
        if (str_starts_with($window, 'checkout_to_publish_')) {
            $publish = $this->reviewPublishDate($review);
            $departure = $this->orderDepartureDate($order);
            $delta = $departure !== '' && $publish !== '' ? $this->daysBetween($departure, $publish) : null;
            if ($delta === null) {
                return [0, false, [], ['点评发布日期或订单离店日期缺失']];
            }
            if ($delta < 0) {
                return [0, true, ['点评早于订单离店日期'], ['点评时间与订单离店时间硬冲突']];
            }
            if ($delta <= 14) {
                return [30, false, ['离店后' . $delta . '天发布点评'], []];
            }
            if ($delta <= 30) {
                return [15, false, ['离店后' . $delta . '天发布点评'], ['点评发布时间距离店超过14天']];
            }
            return [0, false, ['点评发布时间距离店较远'], ['点评发布时间距离店超过30天']];
        }
        if ($window === 'explicit_stay_month') {
            return [15, false, ['点评月份与订单入住月份一致'], ['只有月份证据，缺少精确日期']];
        }
        return [0, false, [], ['缺少可验证日期窗口']];
    }

    /** @return array{0:int,1:bool,2:array<int,string>,3:array<int,string>} */
    private function statusScore(string $status): array
    {
        $normalized = $this->lower($status);
        if ($normalized === '') {
            return [0, false, [], ['订单状态缺失']];
        }
        if ($this->containsAny($normalized, self::INVALID_STATUSES)) {
            return [-25, true, ['订单状态为取消/关闭/退款状态：' . $status], ['无效订单状态，不能匹配']];
        }
        if ($this->containsAny($normalized, self::VALID_STATUSES)) {
            return [15, false, ['订单状态有效：' . $status], []];
        }
        if ($this->containsAny($normalized, self::WEAK_STATUSES)) {
            return [5, false, ['订单状态尚未完成：' . $status], ['订单未达到已退房/已完成']];
        }
        return [0, false, ['订单状态未识别：' . $status], ['订单状态需要人工复核']];
    }

    /** @param array<string,mixed> $order @param array<string,mixed> $breakdown @param array<int,string> $evidence @param array<int,string> $missing */
    private function publicCandidate(array $order, int $score, array $breakdown, array $evidence, array $missing): array
    {
        return $this->normalizeOrderForResponse($order) + [
            'score' => $score,
            'score_breakdown' => $breakdown,
            'evidence' => $evidence,
            'missing_evidence' => $missing,
        ];
    }

    /** @param array<string,mixed> $order @return array<string,mixed> */
    private function normalizeOrderForResponse(array $order): array
    {
        return [
            'order_id' => $this->extractOrderId($order),
            'arrival_date' => $this->orderArrivalDate($order),
            'departure_date' => $this->orderDepartureDate($order),
            'room_name' => $this->extractRoomName($order),
            'order_status' => $this->firstString($order, ['orderStatus', 'order_status', 'status']),
            'detail_verified' => $this->isTrue($this->firstValue($order, ['detailVerified', 'detail_verified', 'orderDetailVerified', 'order_detail_verified'])),
            'match_source' => 'authorized_meituan_order_pool',
        ];
    }

    /** @param array<string,mixed> $review */
    private function reviewOrderIdentifier(array $review): string
    {
        return $this->firstString($review, [
            'meituanOrderId', 'meituan_order_id', 'mtOrderId', 'mt_order_id',
            'channelOrderNo', 'channel_order_no', 'orderId', 'order_id',
            'orderNo', 'order_no', 'platform_order_id',
        ]);
    }

    /** @param array<string,mixed> $order @return array<int,string> */
    private function orderIdentifiers(array $order): array
    {
        $values = [];
        foreach ([
            'meituanOrderId', 'meituan_order_id', 'mtOrderId', 'mt_order_id',
            'channelOrderNo', 'channel_order_no', 'orderId', 'order_id',
            'orderNo', 'order_no', 'platform_order_id', 'id',
        ] as $field) {
            $value = trim((string)($order[$field] ?? ''));
            if ($value !== '') {
                $values[] = $value;
            }
        }
        return array_values(array_unique($values));
    }

    /** @param array<string,mixed> $order */
    private function isMeituanOrder(array $order): bool
    {
        $platform = $this->lower($this->firstString($order, ['platform', 'sourcePlatform', 'source_platform', 'channel']));
        return $platform === '' || $platform === 'meituan' || $platform === 'mt' || str_contains($platform, '美团');
    }

    /** @param array<string,mixed> $review */
    private function reviewStayDate(array $review): string
    {
        return $this->extractDate($review, ['checkInDate', 'check_in_date', 'checkinDate', 'arrivalDate', 'arrival_date', 'consumeDate', 'consume_date']);
    }

    /** @param array<string,mixed> $review */
    private function reviewPublishDate(array $review): string
    {
        return $this->extractDate($review, ['reviewDate', 'review_date', 'commentTime', 'comment_time', 'addTime', 'add_time', 'publishTime', 'publish_time']);
    }

    /** @param array<string,mixed> $order */
    private function orderArrivalDate(array $order): string
    {
        return $this->extractDate($order, ['checkInDate', 'check_in_date', 'checkinDate', 'arrivalDate', 'arrival_date']);
    }

    /** @param array<string,mixed> $order */
    private function orderDepartureDate(array $order): string
    {
        return $this->extractDate($order, ['checkOutDate', 'check_out_date', 'departureDate', 'departure_date']);
    }

    /** @param array<string,mixed> $data @param array<int,string> $fields */
    private function firstString(array $data, array $fields): string
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $data) && trim((string)$data[$field]) !== '') {
                return trim((string)$data[$field]);
            }
        }
        return '';
    }

    /** @param array<string,mixed> $data @param array<int,string> $fields */
    private function firstValue(array $data, array $fields)
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                return $data[$field];
            }
        }
        return null;
    }

    /** @param array<string,mixed> $data @param array<int,string> $fields */
    private function extractDate(array $data, array $fields): string
    {
        foreach ($fields as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $date = $this->normalizeDate((string)$data[$field]);
            if ($date !== '') {
                return $date;
            }
        }
        return '';
    }

    private function normalizeDate(string $value): string
    {
        $text = trim($value);
        if ($text === '') {
            return '';
        }
        if (preg_match('/(20\d{2})\D+(\d{1,2})\D+(\d{1,2})/u', $text, $matches)) {
            return checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1])
                ? sprintf('%04d-%02d-%02d', (int)$matches[1], (int)$matches[2], (int)$matches[3])
                : '';
        }
        if (preg_match('/^(20\d{2})(\d{2})(\d{2})$/', $text, $matches)) {
            return checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1])
                ? sprintf('%04d-%02d-%02d', (int)$matches[1], (int)$matches[2], (int)$matches[3])
                : '';
        }
        $timestamp = strtotime($text);
        return $timestamp === false ? '' : date('Y-m-d', $timestamp);
    }

    /** @param array<string,mixed> $data */
    private function extractRoomName(array $data): string
    {
        return $this->firstString($data, ['roomName', 'room_name', 'roomType', 'room_type', 'hotelRoomInfo', 'hotel_room_info', 'productName', 'product_name']);
    }

    private function normalizeRoomName(string $value): string
    {
        $normalized = $this->lower(trim($value));
        $normalized = (string)preg_replace('/\s+/', '', $normalized);
        return (string)preg_replace('/[^\p{L}\p{N}]+/u', '', $normalized);
    }

    /** @param array<string,mixed> $review */
    private function extractScore(array $review): ?float
    {
        foreach (['score', 'avgScore', 'avg_score', 'rating'] as $field) {
            if (isset($review[$field]) && is_numeric($review[$field])) {
                return (float)$review[$field];
            }
        }
        return null;
    }

    /** @param array<string,mixed> $order */
    private function extractAmount(array $order): ?float
    {
        foreach (['amount', 'totalAmount', 'total_amount', 'paidAmount', 'paid_amount', 'orderAmount', 'order_amount'] as $field) {
            if (isset($order[$field]) && is_numeric($order[$field])) {
                return (float)$order[$field];
            }
        }
        return null;
    }

    private function daysBetween(string $start, string $end): ?int
    {
        $startTimestamp = strtotime($start . ' 00:00:00');
        $endTimestamp = strtotime($end . ' 00:00:00');
        if ($startTimestamp === false || $endTimestamp === false) {
            return null;
        }
        return (int)floor(($endTimestamp - $startTimestamp) / 86400);
    }

    /** @param array<int,string> $needles */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $this->lower($needle))) {
                return true;
            }
        }
        return false;
    }

    private function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private function isTrue($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array($this->lower(trim((string)$value)), ['1', 'true', 'yes', 'on', 'verified'], true);
    }

    /** @param mixed $value */
    private function encodeJson($value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }
}
