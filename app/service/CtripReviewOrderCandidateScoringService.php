<?php
declare(strict_types=1);

namespace app\service;

/**
 * Scores Ctrip review -> authorized Ctrip/PMS order candidates without trying
 * to reverse-resolve an anonymous reviewer identity.
 */
final class CtripReviewOrderCandidateScoringService
{
    private const VALID_STATUSES = ['已退房', '已离店', '已完成', '完成', '已入住', 'checkedout', 'completed'];
    private const WEAK_STATUSES = ['进行中', '已预订', '待确认', '预订', 'booked', 'reserved', 'pending'];
    private const INVALID_STATUSES = ['已取消', '已关闭', '取消', '关闭', 'noshow', 'no show', 'cancelled', 'canceled', 'closed'];
    private const ROOM_KEYWORDS = ['亲子', '家庭', '儿童', '深睡', '大床', '双床', '洞穴', '智能', '私汤', '浴缸', '泡池', '雅居', '漫波', '景观', '庭院'];
    private const CONTENT_HINTS = [
        '亲子' => [
            'words' => ['亲子', '家庭', '小孩', '孩子', '儿童', '宝宝'],
            'room_words' => ['亲子', '家庭', '儿童'],
            'hard_conflict' => true,
        ],
        '大床' => [
            'words' => ['一个人', '散心', '出差', '大床'],
            'room_words' => ['大床', '深睡', '智能'],
            'hard_conflict' => false,
        ],
        '双床' => [
            'words' => ['朋友', '同事', '双床', '两张床'],
            'room_words' => ['双床'],
            'hard_conflict' => true,
        ],
        '私汤' => [
            'words' => ['私汤', '浴缸', '泡池'],
            'room_words' => ['私汤', '浴缸', '泡池'],
            'hard_conflict' => true,
        ],
        '设施' => [
            'words' => ['智能马桶', '智能床垫', '负离子', '烘衣机', '投影', '隔音'],
            'room_words' => ['智能', '深睡', '洞穴', '大床'],
            'hard_conflict' => false,
        ],
    ];

    /**
     * @param array<int, array<string, mixed>> $reviews
     * @param array<int, array<string, mixed>> $orders
     * @param array<string, mixed> $roomMapping
     * @param array<int, array<string, mixed>> $imSessions
     * @param array<string, mixed> $options
     * @return array<int, array<string, mixed>>
     */
    public function buildMatches(
        array $reviews,
        array $orders,
        array $roomMapping = [],
        array $imSessions = [],
        array $options = []
    ): array {
        $ctripOrders = array_values(array_filter(
            $orders,
            fn(array $order): bool => $this->isCtripOrder($order)
        ));

        $initial = [];
        foreach ($reviews as $review) {
            $initial[] = $this->scoreReview($review, $ctripOrders, $roomMapping, $imSessions, $options, []);
        }

        $topOrderHits = [];
        foreach ($initial as $match) {
            if (($match['status'] ?? '') === 'confirmed') {
                continue;
            }
            $candidate = $match['candidates'][0] ?? null;
            if (!is_array($candidate) || (int)($candidate['score'] ?? 0) < 45) {
                continue;
            }
            $orderId = trim((string)($candidate['order_id'] ?? ''));
            if ($orderId !== '') {
                $topOrderHits[$orderId] = ($topOrderHits[$orderId] ?? 0) + 1;
            }
        }
        $duplicateOrderIds = array_keys(array_filter(
            $topOrderHits,
            static fn(int $count): bool => $count > 1
        ));
        if ($duplicateOrderIds === []) {
            return $initial;
        }

        $final = [];
        foreach ($reviews as $review) {
            $final[] = $this->scoreReview($review, $ctripOrders, $roomMapping, $imSessions, $options, $duplicateOrderIds);
        }
        return $final;
    }

    /**
     * @param array<string, mixed> $review
     * @param array<int, array<string, mixed>> $orders
     * @param array<string, mixed> $roomMapping
     * @param array<int, array<string, mixed>> $imSessions
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function matchReview(
        array $review,
        array $orders,
        array $roomMapping = [],
        array $imSessions = [],
        array $options = []
    ): array {
        return $this->buildMatches([$review], $orders, $roomMapping, $imSessions, $options)[0];
    }

    /**
     * @param array<string, mixed> $review
     * @param array<int, array<string, mixed>> $orders
     * @param array<string, mixed> $roomMapping
     * @param array<int, array<string, mixed>> $imSessions
     * @param array<string, mixed> $options
     * @param array<int, string> $duplicateOrderIds
     * @return array<string, mixed>
     */
    private function scoreReview(
        array $review,
        array $orders,
        array $roomMapping,
        array $imSessions,
        array $options,
        array $duplicateOrderIds
    ): array {
        $base = [
            'scope' => 'ctrip_ota_channel',
            'match_subject' => 'authorized_order_evidence',
            'identity_resolution' => 'blocked_not_attempted',
            'storage_contains_guest_identity' => false,
        ];
        if ($orders === []) {
            return $base + [
                'status' => 'not_found',
                'review_status' => 'not_found',
                'reason' => 'ctrip_order_pool_empty',
                'confidence' => 'not_found',
                'missing_evidence' => ['authorized_ctrip_orders'],
                'candidates' => [],
                'search_windows' => [],
            ];
        }

        $strongLink = $this->strongOrderLink($review, $imSessions);
        if ($strongLink['order_id'] !== '') {
            $strongMatches = array_values(array_filter(
                $orders,
                fn(array $order): bool => in_array($strongLink['order_id'], $this->orderIdentifiers($order), true)
            ));
            if (count($strongMatches) === 1) {
                $candidate = $this->scoreCandidate(
                    $review,
                    $strongMatches[0],
                    $roomMapping,
                    true,
                    false,
                    false,
                    $options,
                    $strongLink
                );
                return $base + [
                    'status' => 'confirmed',
                    'review_status' => 'confirmed',
                    'reason' => 'strong_order_identifier_match',
                    'confidence' => 'confirmed',
                    'match_method' => $strongLink['method'],
                    'order' => $this->normalizeOrder($strongMatches[0]),
                    'candidates' => [$this->withoutInternalOrder($candidate)],
                    'score' => 100,
                    'score_breakdown' => $candidate['score_breakdown'],
                    'missing_evidence' => [],
                    'evidence' => [
                        'groups' => $strongLink['evidence'],
                        'matched_identifier' => $strongLink['order_id'],
                        'candidate_count' => 1,
                        'store_scope' => 'selected_system_hotel',
                    ],
                    'window_used' => 'strong_identifier',
                    'search_windows' => [],
                ];
            }

            $candidates = array_map(
                fn(array $order): array => $this->scoreCandidate(
                    $review,
                    $order,
                    $roomMapping,
                    true,
                    true,
                    false,
                    $options,
                    $strongLink
                ),
                $strongMatches
            );
            return $base + [
                'status' => $strongMatches === [] ? 'not_found' : 'ambiguous',
                'review_status' => $strongMatches === [] ? 'not_found' : 'ambiguous',
                'reason' => $strongMatches === [] ? 'strong_order_link_not_in_order_pool' : 'duplicate_strong_order_identifier',
                'confidence' => $strongMatches === [] ? 'not_found' : 'ambiguous',
                'missing_evidence' => $strongMatches === [] ? ['linked_ctrip_order_detail'] : ['unique_order_identifier'],
                'candidates' => array_map([$this, 'withoutInternalOrder'], array_slice($candidates, 0, 5)),
                'review_flags' => $strongMatches === [] ? [] : ['强订单标识在订单池中命中多条记录'],
                'window_used' => 'strong_identifier',
                'search_windows' => [],
            ];
        }

        $coverageStart = $this->dateOnly((string)($options['coverage_start_date'] ?? ''));
        $reviewMonth = $this->reviewStayMonth($review);
        if ($coverageStart !== '' && $reviewMonth !== '' && $reviewMonth < substr($coverageStart, 0, 7)) {
            return $base + [
                'status' => 'not_found',
                'review_status' => 'not_found',
                'reason' => 'review_before_order_coverage',
                'confidence' => 'not_found',
                'missing_evidence' => ['historical_authorized_ctrip_orders'],
                'candidates' => [],
                'coverage_start_date' => $coverageStart,
                'search_windows' => [],
            ];
        }

        $window = $this->selectCandidateWindow($review, $orders);
        if ($window['orders'] === []) {
            $missing = ['candidate_after_explicit_14d_30d_month_windows'];
            if ($this->publishValue($review) === '') {
                $missing[] = 'review_publish_time';
            }
            if ($reviewMonth === '') {
                $missing[] = 'review_stay_month';
            }
            return $base + [
                'status' => 'not_found',
                'review_status' => 'not_found',
                'reason' => 'no_candidate_after_all_windows',
                'confidence' => 'not_found',
                'missing_evidence' => array_values(array_unique($missing)),
                'candidates' => [],
                'window_used' => 'none',
                'search_windows' => $window['search_windows'],
            ];
        }

        $preliminary = [];
        foreach ($window['orders'] as $order) {
            $preliminary[] = $this->scoreCandidate(
                $review,
                $order,
                $roomMapping,
                false,
                false,
                false,
                $options,
                $strongLink
            );
        }
        usort($preliminary, [$this, 'compareCandidates']);

        $topScore = (int)($preliminary[0]['score'] ?? 0);
        $secondScore = isset($preliminary[1]) ? (int)$preliminary[1]['score'] : null;
        $closeScores = $secondScore !== null && ($topScore - $secondScore) < 10;
        $clearRoomCount = count(array_filter(
            $preliminary,
            static fn(array $candidate): bool => (int)($candidate['score_breakdown']['room_score'] ?? 0) >= 25
        ));
        $uniqueTop = !$closeScores && $clearRoomCount === 1;

        $rescored = [];
        foreach ($window['orders'] as $order) {
            $normalized = $this->normalizeOrder($order);
            $candidateId = (string)$normalized['order_id'];
            $isTopOrder = $candidateId !== '' && $candidateId === (string)($preliminary[0]['order_id'] ?? '');
            $isDuplicate = in_array($candidateId, $duplicateOrderIds, true);
            $candidateScore = $this->scoreCandidate(
                $review,
                $order,
                $roomMapping,
                false,
                $closeScores,
                $isDuplicate,
                $options,
                $strongLink,
                $isTopOrder && $uniqueTop && !$isDuplicate
            );
            $rescored[] = $candidateScore;
        }
        usort($rescored, [$this, 'compareCandidates']);
        $rescored = array_slice($rescored, 0, 5);
        $top = $rescored[0];
        $second = $rescored[1] ?? null;
        $finalGapAmbiguous = is_array($second) && ((int)$top['score'] - (int)$second['score']) < 10;
        $duplicateTop = in_array((string)$top['order_id'], $duplicateOrderIds, true);
        $timeConflict = (bool)($top['score_breakdown']['time_logic_conflict'] ?? false);
        $roomConflict = (int)($top['score_breakdown']['room_score'] ?? 0) === 0;
        $ambiguous = $finalGapAmbiguous || $duplicateTop || $timeConflict || $roomConflict;

        $status = (string)$top['status'];
        if ($ambiguous && $status !== 'confirmed') {
            $status = 'ambiguous';
            $top['status'] = 'ambiguous';
            $rescored[0] = $top;
        }
        $flags = [];
        if ($finalGapAmbiguous) {
            $flags[] = '前两名候选分差小于10';
        }
        if ($duplicateTop) {
            $flags[] = '同一订单命中多条点评';
        }
        if ($timeConflict) {
            $flags[] = '点评时间与离店时间硬冲突';
        }
        if ($roomConflict) {
            $flags[] = '房型未映射或存在冲突';
        }

        return $base + [
            'status' => $status,
            'review_status' => $status,
            'reason' => $status === 'high_confidence'
                ? 'high_confidence_candidate'
                : ($status === 'ambiguous' ? 'ambiguous_candidates' : 'candidate_evidence_insufficient'),
            'confidence' => $status,
            'match_method' => 'multidimensional_candidate_scoring',
            'order' => in_array($status, ['confirmed', 'high_confidence'], true)
                ? $this->normalizeOrder($top['_order'])
                : null,
            'candidates' => array_map([$this, 'withoutInternalOrder'], $rescored),
            'score' => (int)$top['score'],
            'score_breakdown' => $top['score_breakdown'],
            'missing_evidence' => $top['missing_evidence'],
            'evidence' => [
                'window_used' => $window['window_used'],
                'search_windows' => $window['search_windows'],
                'candidate_count' => count($window['orders']),
                'store_mapping_verified' => $this->isTrue($options['store_mapping_verified'] ?? false),
                'store_mapping' => is_array($options['store_mapping'] ?? null) ? $options['store_mapping'] : [],
                'identity_resolution' => 'blocked_not_attempted',
            ],
            'review_flags' => $flags,
            'window_used' => $window['window_used'],
            'search_windows' => $window['search_windows'],
        ];
    }

    /**
     * @param array<string, mixed> $review
     * @param array<string, mixed> $order
     * @param array<string, mixed> $roomMapping
     * @param array<string, mixed> $options
     * @param array{order_id:string,method:string,evidence:array<int,string>} $strongLink
     * @return array<string, mixed>
     */
    private function scoreCandidate(
        array $review,
        array $order,
        array $roomMapping,
        bool $strong,
        bool $ambiguous,
        bool $duplicateConflict,
        array $options,
        array $strongLink,
        bool $unique = false
    ): array {
        $normalized = $this->normalizeOrder($order);
        $breakdown = [
            'strong_id_score' => 0,
            'room_score' => 0,
            'date_score' => 0,
            'time_logic_conflict' => false,
            'status_score' => 0,
            'amount_score' => 0,
            'content_score' => 0,
            'uniqueness_score' => 0,
            'duplicate_penalty' => 0,
            'detail_review_score' => 0,
        ];
        $evidence = [];
        $missing = [];

        if ($strong) {
            $breakdown['strong_id_score'] = 100;
            $evidence[] = '点评可见订单标识与携程渠道订单标识完全一致';
        } else {
            [$breakdown['room_score'], $roomEvidence, $roomMissing] = $this->roomScore(
                $this->reviewRoom($review),
                (string)$normalized['room_name'],
                $roomMapping
            );
            [$breakdown['date_score'], $timeConflict, $dateEvidence, $dateMissing] = $this->dateScore(
                $this->publishValue($review),
                $this->orderCheckoutValue($order)
            );
            $breakdown['time_logic_conflict'] = $timeConflict;
            [$breakdown['status_score'], $statusEvidence, $statusMissing] = $this->statusScore((string)$normalized['order_status']);
            [$breakdown['amount_score'], $amountEvidence, $amountMissing] = $this->amountScore($normalized['amount']);
            [$breakdown['content_score'], $contentEvidence, $contentMissing] = $this->contentScore(
                $this->reviewContent($review),
                (string)$normalized['room_name']
            );
            [$breakdown['detail_review_score'], $detailEvidence, $detailMissing] = $this->detailScore((bool)$normalized['detail_verified']);
            $breakdown['uniqueness_score'] = $unique ? 5 : 0;
            if ($duplicateConflict) {
                $breakdown['duplicate_penalty'] = -15;
            }

            $evidence = array_merge(
                $roomEvidence,
                $dateEvidence,
                $statusEvidence,
                $amountEvidence,
                $contentEvidence,
                $detailEvidence,
                [$unique ? '同窗口同映射房型候选唯一' : '候选不唯一或唯一性证据不足']
            );
            $missing = array_merge($roomMissing, $dateMissing, $statusMissing, $amountMissing, $contentMissing, $detailMissing);
            $missing[] = '点评订单号或渠道订单号未命中';
            if (!$this->isTrue($options['store_mapping_verified'] ?? false)) {
                $missing[] = '携程点评门店与订单来源门店映射未显式复核';
            }
            if ($duplicateConflict) {
                $evidence[] = '同一订单命中多条点评，已扣15分并降级';
                $missing[] = '同一订单对应多条点评，缺少强订单号证据';
            }
        }

        $numericParts = array_filter($breakdown, static fn($value): bool => is_int($value));
        $score = max(0, min(100, array_sum($numericParts)));
        $validStatus = (int)$breakdown['status_score'] === 15;
        $amount = $this->amountValue($normalized['amount']);
        $storeVerified = $this->isTrue($options['store_mapping_verified'] ?? false);
        $high = !$strong
            && $score >= 75
            && $unique
            && !$ambiguous
            && !$duplicateConflict
            && (int)$breakdown['room_score'] >= 25
            && (int)$breakdown['date_score'] > 0
            && !$breakdown['time_logic_conflict']
            && $validStatus
            && $amount !== null
            && $amount > 0
            && (bool)$normalized['detail_verified']
            && $storeVerified;
        $status = $strong ? 'confirmed' : ($ambiguous || $duplicateConflict ? 'ambiguous' : ($high ? 'high_confidence' : 'candidate'));

        $missing = array_values(array_unique(array_filter(
            $missing,
            static fn($value): bool => is_string($value) && trim($value) !== ''
        )));
        $breakdown['evidence'] = $evidence;
        $breakdown['missing_evidence'] = $missing;

        return $normalized + [
            'score' => $score,
            'status' => $status,
            'score_breakdown' => $breakdown,
            'evidence' => $evidence,
            'missing_evidence' => $missing,
            'detail_review_status' => $normalized['detail_verified'] ? '已复核详情' : '未复核详情',
            '_order' => $order,
            '_strong_link' => $strongLink,
        ];
    }

    /**
     * @param array<string, mixed> $review
     * @param array<int, array<string, mixed>> $orders
     * @return array{orders:array<int,array<string,mixed>>,window_used:string,search_windows:array<int,array<string,mixed>>}
     */
    private function selectCandidateWindow(array $review, array $orders): array
    {
        $searchWindows = [];
        $arrival = $this->reviewArrivalDate($review);
        $departure = $this->reviewDepartureDate($review);
        if ($arrival !== '' || $departure !== '') {
            $explicit = array_values(array_filter($orders, function (array $order) use ($arrival, $departure): bool {
                $orderArrival = $this->dateOnly($this->orderArrivalValue($order));
                $orderDeparture = $this->dateOnly($this->orderCheckoutValue($order));
                if ($arrival !== '' && $orderArrival !== $arrival) {
                    return false;
                }
                if ($departure !== '' && $orderDeparture !== $departure) {
                    return false;
                }
                return true;
            }));
            $searchWindows[] = ['window' => 'explicit_stay_dates', 'candidate_count' => count($explicit)];
            if ($explicit !== []) {
                return ['orders' => $explicit, 'window_used' => 'explicit_stay_dates', 'search_windows' => $searchWindows];
            }
        }

        $publish = $this->parseDateTime($this->publishValue($review));
        if ($publish['date_time'] instanceof \DateTimeImmutable) {
            $within14 = $this->ordersByCheckoutDelta($orders, $publish['date_time'], 0, 14);
            $searchWindows[] = ['window' => 'checkout_0_14_days_before_review', 'candidate_count' => count($within14)];
            if ($within14 !== []) {
                return ['orders' => $within14, 'window_used' => 'checkout_0_14_days_before_review', 'search_windows' => $searchWindows];
            }

            $within30 = $this->ordersByCheckoutDelta($orders, $publish['date_time'], 15, 30);
            $searchWindows[] = ['window' => 'checkout_15_30_days_before_review', 'candidate_count' => count($within30)];
            if ($within30 !== []) {
                return ['orders' => $within30, 'window_used' => 'checkout_15_30_days_before_review', 'search_windows' => $searchWindows];
            }
        } else {
            $searchWindows[] = ['window' => 'checkout_0_14_days_before_review', 'candidate_count' => 0, 'skipped_reason' => 'review_publish_time_missing'];
            $searchWindows[] = ['window' => 'checkout_15_30_days_before_review', 'candidate_count' => 0, 'skipped_reason' => 'review_publish_time_missing'];
        }

        $month = $this->reviewStayMonth($review);
        if ($month !== '') {
            $monthOrders = array_values(array_filter($orders, function (array $order) use ($month): bool {
                $arrival = $this->dateOnly($this->orderArrivalValue($order));
                return $arrival !== '' && substr($arrival, 0, 7) === $month;
            }));
            $searchWindows[] = ['window' => 'stay_month', 'month' => $month, 'candidate_count' => count($monthOrders)];
            if ($monthOrders !== []) {
                return ['orders' => $monthOrders, 'window_used' => 'stay_month', 'search_windows' => $searchWindows];
            }
        } else {
            $searchWindows[] = ['window' => 'stay_month', 'candidate_count' => 0, 'skipped_reason' => 'review_stay_month_missing'];
        }

        return ['orders' => [], 'window_used' => 'none', 'search_windows' => $searchWindows];
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @return array<int, array<string, mixed>>
     */
    private function ordersByCheckoutDelta(array $orders, \DateTimeImmutable $publish, int $minimumDays, int $maximumDays): array
    {
        return array_values(array_filter($orders, function (array $order) use ($publish, $minimumDays, $maximumDays): bool {
            $checkout = $this->parseDateTime($this->orderCheckoutValue($order));
            if (!$checkout['date_time'] instanceof \DateTimeImmutable) {
                return false;
            }
            $days = (int)$checkout['date_time']->setTime(0, 0)->diff($publish->setTime(0, 0))->format('%r%a');
            return $days >= $minimumDays && $days <= $maximumDays;
        }));
    }

    /** @return array{0:int,1:array<int,string>,2:array<int,string>} */
    private function roomScore(string $reviewRoom, string $orderRoom, array $mapping): array
    {
        $reviewNormalized = $this->normalizeRoom($reviewRoom);
        $orderNormalized = $this->normalizeRoom($orderRoom);
        foreach ($mapping as $source => $targets) {
            $sourceNormalized = $this->normalizeRoom((string)$source);
            if ($sourceNormalized === '' || ($reviewNormalized !== $sourceNormalized && !str_contains($reviewNormalized, $sourceNormalized))) {
                continue;
            }
            $targetList = is_array($targets) ? $targets : [$targets];
            foreach ($targetList as $target) {
                $targetNormalized = $this->normalizeRoom((string)$target);
                if ($targetNormalized !== '' && ($orderNormalized === $targetNormalized || str_contains($orderNormalized, $targetNormalized) || str_contains($targetNormalized, $orderNormalized))) {
                    return [30, ['房型明确映射：' . $source . ' -> ' . $orderRoom], []];
                }
            }
        }
        if ($reviewNormalized !== '' && $reviewNormalized === $orderNormalized) {
            return [30, ['点评房型与订单房型标准化后完全一致'], []];
        }
        if (
            $reviewNormalized !== ''
            && $orderNormalized !== ''
            && min($this->textLength($reviewNormalized), $this->textLength($orderNormalized)) >= 3
            && (str_contains($reviewNormalized, $orderNormalized) || str_contains($orderNormalized, $reviewNormalized))
        ) {
            return [25, ['房型主体名称一致'], []];
        }
        $shared = array_values(array_filter(
            self::ROOM_KEYWORDS,
            static fn(string $word): bool => str_contains($reviewRoom, $word) && str_contains($orderRoom, $word)
        ));
        if ($shared !== []) {
            return [min(25, 20 + count($shared) * 2), ['房型关键词重合：' . implode('、', $shared)], []];
        }
        if ($reviewRoom !== '' && $orderRoom !== '') {
            return [0, ['房型无明确映射'], ['房型未明确映射：点评=' . $reviewRoom . '，订单=' . $orderRoom]];
        }
        return [0, ['房型字段缺失'], ['缺少点评房型或订单房型']];
    }

    /** @return array{0:int,1:bool,2:array<int,string>,3:array<int,string>} */
    private function dateScore(string $publishValue, string $checkoutValue): array
    {
        $publish = $this->parseDateTime($publishValue);
        $checkout = $this->parseDateTime($checkoutValue);
        if (!$publish['date_time'] instanceof \DateTimeImmutable || !$checkout['date_time'] instanceof \DateTimeImmutable) {
            return [0, false, ['缺少点评发表时间或离店时间'], ['无法核对离店后点评窗口']];
        }
        $publishAt = $publish['date_time'];
        $checkoutAt = $checkout['has_time'] ? $checkout['date_time'] : $checkout['date_time']->setTime(14, 0, 0);
        if (!$publish['has_time'] && $publishAt->format('Y-m-d') === $checkoutAt->format('Y-m-d')) {
            return [0, false, ['点评与离店为同一天，但点评时间精度不足'], ['点评发表时间只有日期，无法确认是否晚于离店日14:00']];
        }
        if ($publishAt < $checkoutAt) {
            return [-30, true, ['点评早于离店日14:00，时间逻辑硬冲突'], ['点评时间早于最早可点评时间，候选不能高置信']];
        }
        $delta = (int)$checkoutAt->setTime(0, 0)->diff($publishAt->setTime(0, 0))->format('%r%a');
        if ($delta <= 3) {
            return [30, false, ['离店后' . $delta . '天发表点评'], []];
        }
        if ($delta <= 7) {
            return [22, false, ['离店后' . $delta . '天发表点评'], []];
        }
        if ($delta <= 14) {
            return [12, false, ['离店后' . $delta . '天发表点评'], []];
        }
        return [4, false, ['离店后' . $delta . '天发表点评，时间较远'], ['时间窗口偏远，需要复核']];
    }

    /** @return array{0:int,1:array<int,string>,2:array<int,string>} */
    private function statusScore(string $status): array
    {
        $normalized = $this->lower($status);
        if ($this->containsAny($normalized, self::INVALID_STATUSES)) {
            return [0, ['订单状态为低可信状态：' . $status], ['订单已取消/关闭/NoShow，不能高置信']];
        }
        if ($this->containsAny($normalized, self::VALID_STATUSES)) {
            return [15, ['有效入住状态：' . $status], []];
        }
        if ($this->containsAny($normalized, self::WEAK_STATUSES)) {
            return [6, ['弱状态：' . $status], ['订单状态未达到已退房/已完成']];
        }
        return [4, ['状态未明确：' . $status], ['订单状态需要复核']];
    }

    /** @return array{0:int,1:array<int,string>,2:array<int,string>} */
    private function amountScore($amount): array
    {
        $number = $this->amountValue($amount);
        if ($number === null) {
            return [4, ['金额缺失'], ['金额缺失，不能高置信']];
        }
        if ($number > 0) {
            return [10, ['金额有效：' . rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.')], []];
        }
        return [0, ['金额为0'], ['0元订单保留为低分候选，不能高置信']];
    }

    /** @return array{0:int,1:array<int,string>,2:array<int,string>} */
    private function contentScore(string $content, string $orderRoom): array
    {
        $hits = [];
        $conflicts = [];
        foreach (self::CONTENT_HINTS as $name => $rule) {
            $hasContent = $this->containsAny($content, $rule['words']);
            $hasRoom = $this->containsAny($orderRoom, $rule['room_words']);
            if ($hasContent && $hasRoom) {
                $hits[] = $name;
            } elseif ($hasContent && !$hasRoom && $rule['hard_conflict']) {
                $conflicts[] = $name;
            }
        }
        if ($conflicts !== []) {
            return [-10, ['内容线索与房型冲突：' . implode('、', $conflicts)], ['点评内容与候选房型存在冲突']];
        }
        if ($hits !== []) {
            return [count($hits) >= 2 ? 10 : 4, ['内容线索吻合：' . implode('、', $hits)], []];
        }
        return [0, ['无明显内容线索'], []];
    }

    /** @return array{0:int,1:array<int,string>,2:array<int,string>} */
    private function detailScore(bool $verified): array
    {
        return $verified
            ? [0, ['订单详情已复核'], []]
            : [-5, ['订单详情未复核'], ['未完成订单详情复核，不能高置信']];
    }

    /**
     * @param array<string, mixed> $review
     * @param array<int, array<string, mixed>> $imSessions
     * @return array{order_id:string,method:string,evidence:array<int,string>}
     */
    private function strongOrderLink(array $review, array $imSessions): array
    {
        $orderId = $this->firstString($review, [
            'ctripOrderNo', 'ctrip_order_no', 'channelOrderNo', 'channel_order_no',
            'orderId', 'order_id', 'orderNo', 'order_no', 'orderSn', 'order_sn',
            'platform_order_id', 'bookingOrderId', 'booking_order_id',
        ]);
        if ($orderId !== '') {
            return ['order_id' => $orderId, 'method' => 'platform_review_order_link', 'evidence' => ['review_order_identifier', 'ctrip_order_pool']];
        }
        $groupId = $this->firstString($review, ['groupId', 'group_id', 'sessionGroupId', 'session_group_id', 'conversationId', 'conversation_id']);
        if ($groupId !== '') {
            foreach ($imSessions as $session) {
                if ($groupId !== $this->firstString($session, ['groupId', 'group_id', 'sessionGroupId', 'conversationId'])) {
                    continue;
                }
                $sessionOrderId = $this->firstString($session, ['orderId', 'order_id', 'orderNo', 'order_no', 'channelOrderNo', 'channel_order_no']);
                if ($sessionOrderId !== '') {
                    return ['order_id' => $sessionOrderId, 'method' => 'platform_im_group_order_link', 'evidence' => ['review_im_group_id', 'im_group_order_link', 'ctrip_order_pool']];
                }
            }
        }
        return ['order_id' => '', 'method' => '', 'evidence' => []];
    }

    /** @param array<string, mixed> $order */
    private function isCtripOrder(array $order): bool
    {
        $channel = $this->lower($this->firstString($order, ['channel', 'channelName', 'channel_name', 'sourcePlatform', 'source_platform', 'platform']));
        return $channel !== '' && (str_contains($channel, '携程') || str_contains($channel, 'ctrip'));
    }

    /** @param array<string, mixed> $order @return array<int,string> */
    private function orderIdentifiers(array $order): array
    {
        $identifiers = [];
        foreach ([
            'channelOrderNo', 'channel_order_no', 'ctripOrderNo', 'ctrip_order_no',
            'orderId', 'order_id', 'orderNo', 'order_no', 'pmsOrderNo', 'pms_order_no',
            'orderSn', 'order_sn', 'platform_order_id', 'bookingOrderId', 'booking_order_id',
        ] as $field) {
            $value = trim((string)($order[$field] ?? ''));
            if ($value !== '') {
                $identifiers[] = $value;
            }
        }
        return array_values(array_unique($identifiers));
    }

    /** @param array<string, mixed> $order @return array<string,mixed> */
    private function normalizeOrder(array $order): array
    {
        $pmsOrderNo = $this->firstString($order, ['pmsOrderNo', 'pms_order_no', 'orderNo', 'order_no']);
        $channelOrderNo = $this->firstString($order, ['channelOrderNo', 'channel_order_no', 'ctripOrderNo', 'ctrip_order_no', 'orderId', 'order_id', 'orderSn', 'order_sn']);
        $orderId = $pmsOrderNo !== '' ? $pmsOrderNo : $channelOrderNo;
        $amount = $this->firstValue($order, ['amount', 'totalAmount', 'total_amount', 'paidAmount', 'paid_amount', 'orderAmount', 'order_amount']);
        return [
            'order_id' => $orderId,
            'channel_order_no' => $channelOrderNo,
            'arrival_date' => $this->dateOnly($this->orderArrivalValue($order)),
            'departure_date' => $this->dateOnly($this->orderCheckoutValue($order)),
            'room_name' => $this->firstString($order, ['roomType', 'room_type', 'roomName', 'room_name', 'room_type_name', 'productName', 'product_name']),
            'order_status' => $this->firstString($order, ['status', 'orderStatus', 'order_status', 'orderState', 'order_state']),
            'amount' => $this->amountValue($amount),
            'detail_verified' => $this->isTrue($this->firstValue($order, ['detailVerified', 'detail_verified', 'orderDetailVerified', 'order_detail_verified'])),
            'channel' => 'ctrip',
        ];
    }

    /** @param array<string,mixed> $candidate @return array<string,mixed> */
    private function withoutInternalOrder(array $candidate): array
    {
        unset($candidate['_order'], $candidate['_strong_link']);
        return $candidate;
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private function compareCandidates(array $left, array $right): int
    {
        return ((int)$right['score'] <=> (int)$left['score'])
            ?: strcmp((string)$left['order_id'], (string)$right['order_id']);
    }

    /** @param array<string,mixed> $review */
    private function reviewRoom(array $review): string
    {
        return $this->firstString($review, ['hotelRoomInfo', 'hotel_room_info', 'roomName', 'room_name', 'roomType', 'room_type', 'room_type_name', 'productName', 'product_name']);
    }

    /** @param array<string,mixed> $review */
    private function reviewContent(array $review): string
    {
        return $this->firstString($review, ['content', 'commentContent', 'comment_content', 'reviewContent', 'review_content', 'reviewText', 'review_text']);
    }

    /** @param array<string,mixed> $review */
    private function publishValue(array $review): string
    {
        return $this->firstString($review, ['publishTime', 'publish_time', 'publishedAt', 'published_at', 'addtime', 'addTime', 'commentTime', 'comment_time', 'reviewTime', 'review_time', 'reviewDate', 'review_date', 'date']);
    }

    /** @param array<string,mixed> $review */
    private function reviewArrivalDate(array $review): string
    {
        return $this->dateOnly($this->firstString($review, ['checkinTimeStr', 'checkInDate', 'check_in_date', 'checkinDate', 'checkin_date', 'arrivalDate', 'arrival_date', 'stayDate', 'stay_date']));
    }

    /** @param array<string,mixed> $review */
    private function reviewDepartureDate(array $review): string
    {
        return $this->dateOnly($this->firstString($review, ['checkoutTimeStr', 'checkOutDate', 'check_out_date', 'checkoutDate', 'checkout_date', 'departureDate', 'departure_date']));
    }

    /** @param array<string,mixed> $review */
    private function reviewStayMonth(array $review): string
    {
        $raw = $this->firstString($review, ['checkinTimeStr', 'checkInDate', 'check_in_date', 'checkinDate', 'checkin_date', 'arrivalDate', 'arrival_date', 'stayDate', 'stay_date', 'stayMonth', 'stay_month']);
        if (preg_match('/(20\d{2})[-\/.年](\d{1,2})/u', $raw, $matches)) {
            return sprintf('%04d-%02d', (int)$matches[1], (int)$matches[2]);
        }
        return '';
    }

    /** @param array<string,mixed> $order */
    private function orderArrivalValue(array $order): string
    {
        return $this->firstString($order, ['arrivalDateTime', 'arrival_date_time', 'arrivalDate', 'arrival_date', 'checkInDate', 'check_in_date', 'checkIn', 'check_in', 'checkinTime', 'checkin_time']);
    }

    /** @param array<string,mixed> $order */
    private function orderCheckoutValue(array $order): string
    {
        return $this->firstString($order, ['departureDateTime', 'departure_date_time', 'departureDate', 'departure_date', 'checkOutDate', 'check_out_date', 'checkOut', 'check_out', 'checkoutTime', 'checkout_time']);
    }

    /** @param array<string,mixed> $data @param array<int,string> $fields */
    private function firstString(array $data, array $fields): string
    {
        foreach ($fields as $field) {
            if (!array_key_exists($field, $data) || is_array($data[$field]) || is_object($data[$field])) {
                continue;
            }
            $value = trim((string)$data[$field]);
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    /** @param array<string,mixed> $data @param array<int,string> $fields */
    private function firstValue(array $data, array $fields)
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== '') {
                return $data[$field];
            }
        }
        return null;
    }

    /** @return array{date_time:?\DateTimeImmutable,has_time:bool} */
    private function parseDateTime(string $value): array
    {
        $text = trim($value);
        if ($text === '') {
            return ['date_time' => null, 'has_time' => false];
        }
        $timezone = new \DateTimeZone('Asia/Shanghai');
        if (preg_match('/\/Date\((\d{10,13})(?:[+-]\d{4})?\)\//', $text, $matches)) {
            $timestamp = (int)$matches[1];
            if ($timestamp > 9999999999) {
                $timestamp = (int)floor($timestamp / 1000);
            }
            return [
                'date_time' => (new \DateTimeImmutable('@' . $timestamp))->setTimezone($timezone),
                'has_time' => true,
            ];
        }
        $hasTime = (bool)preg_match('/\d{1,2}[:：]\d{1,2}/u', $text);
        $normalized = preg_replace(['/年|\//u', '/月/u', '/日/u', '/：/u'], ['-', '-', '', ':'], $text) ?? $text;
        try {
            $dateTime = new \DateTimeImmutable($normalized, $timezone);
        } catch (\Throwable $e) {
            return ['date_time' => null, 'has_time' => false];
        }
        return ['date_time' => $dateTime, 'has_time' => $hasTime];
    }

    private function dateOnly(string $value): string
    {
        if (!preg_match('/(20\d{2})[-\/.年](\d{1,2})[-\/.月](\d{1,2})/u', trim($value), $matches)) {
            return '';
        }
        return sprintf('%04d-%02d-%02d', (int)$matches[1], (int)$matches[2], (int)$matches[3]);
    }

    private function normalizeRoom(string $value): string
    {
        $value = $this->lower(trim($value));
        $value = preg_replace('/[（(][^）)]*[）)]/u', '', $value) ?? $value;
        return preg_replace('/[\s\-—_·,，.。\/\\|]+/u', '', $value) ?? $value;
    }

    private function amountValue($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (float)$value;
        }
        $text = str_replace(',', '', (string)$value);
        return preg_match('/-?\d+(?:\.\d+)?/', $text, $matches) ? (float)$matches[0] : null;
    }

    private function isTrue($value): bool
    {
        if ($value === true || $value === 1) {
            return true;
        }
        return in_array($this->lower((string)$value), ['1', 'true', 'yes', '已复核', '已核对', 'verified'], true);
    }

    /** @param array<int,string> $needles */
    private function containsAny(string $haystack, array $needles): bool
    {
        $haystack = $this->lower($haystack);
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

    private function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
