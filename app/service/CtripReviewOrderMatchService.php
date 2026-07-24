<?php
declare(strict_types=1);

namespace app\service;

final class CtripReviewOrderMatchService
{
    private const SYSTEM_NICKNAMES = ['智能客服'];
    private const SYSTEM_NICKNAME_PREFIXES = ['IMK'];

    /**
     * @param array<string, mixed> $review
     * @param array<int, array<string, mixed>> $imSessions
     * @return array<string, mixed>
     */
    public function matchReviewIdentity(array $review, array $imSessions): array
    {
        return (new OtaReviewRiskPolicyService())->blockedOperation('ctrip_review_identity_match_service', [
            'identity_reverse_lookup',
            'anonymous_user_matching',
            'masked_data_reconstruction_risk',
        ]);

        $username = $this->extractReviewUsername($review);
        if ($username === '') {
            return [
                'status' => 'unmatched',
                'reason' => 'review_username_missing',
                'confidence' => 'none',
            ];
        }

        $members = $this->buildMemberIndex($imSessions);
        if ($members === []) {
            return [
                'status' => 'unmatched',
                'reason' => 'im_member_cache_empty',
                'confidence' => 'none',
                'source_username' => $username,
            ];
        }

        $uidSet = array_fill_keys(array_keys($members), true);
        $masked = (bool)preg_match('/^(.+)\*{4}$/u', $username);
        $match = $masked
            ? $this->matchMaskedUsername($username, $uidSet, $members)
            : $this->matchDirectUsername($username, $uidSet, $members);

        if ($match === null) {
            return [
                'status' => 'unmatched',
                'reason' => $masked ? 'masked_username_hash_not_found' : 'direct_username_hash_not_found',
                'confidence' => 'none',
                'source_username' => $username,
            ];
        }

        return [
            'status' => 'person_locked',
            'confidence' => $masked ? 'medium' : 'high',
            'match_method' => 'member_uid_md5_candidate',
            'source_username' => $username,
            'identity' => [
                'guest_uid' => $match['uid'],
                'guest_name' => $match['nick_name'],
                'avatar_url' => $match['avatar_url'],
                'matched_candidate' => $match['candidate'],
            ],
            'evidence' => [
                'source' => 'ctrip_im_member_cache',
                'hash_algorithm' => 'md5',
                'candidate_strategy' => $match['candidate_strategy'],
                'scope' => 'ctrip_ota_channel',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $review
     * @param array<int, array<string, mixed>> $imSessions
     * @param array<int, array<string, mixed>> $orders
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function matchReviewToOrder(array $review, array $imSessions, array $orders, array $options = []): array
    {
        $roomMapping = is_array($options['room_mapping'] ?? null) ? $options['room_mapping'] : [];
        return (new CtripReviewOrderCandidateScoringService())->matchReview(
            $review,
            $orders,
            $roomMapping,
            $imSessions,
            $options
        );

        $reviewDateInfo = $this->extractDateMatchInfo($review, ['checkinTimeStr', 'checkInDate', 'check_in_date', 'checkinDate', 'checkin_date', 'arrivalDate', 'arrival_date', 'stayDate', 'stay_date']);
        $reviewDate = $reviewDateInfo['date'];
        $reviewMonth = $reviewDateInfo['month'];
        $reviewDatePrecision = $reviewDateInfo['precision'];
        $coverageStart = $this->normalizeDate((string)($options['coverage_start_date'] ?? ''));
        if (
            $coverageStart !== ''
            && (
                ($reviewDate !== '' && $reviewDate < $coverageStart)
                || ($reviewDate === '' && $reviewMonth !== '' && $reviewMonth < substr($coverageStart, 0, 7))
            )
        ) {
            return [
                'status' => 'out_of_coverage',
                'reason' => 'review_before_order_coverage',
                'confidence' => 'none',
                'review_date' => $reviewDate,
                'review_month' => $reviewMonth,
                'coverage_start_date' => $coverageStart,
                'scope' => 'ctrip_ota_channel',
            ];
        }

        $identityResult = $this->matchReviewIdentity($review, $imSessions);
        if (($identityResult['status'] ?? '') !== 'person_locked') {
            return [
                'status' => 'needs_ops',
                'reason' => $identityResult['reason'] ?? 'identity_unmatched',
                'confidence' => 'none',
                'identity' => null,
                'scope' => 'ctrip_ota_channel',
            ];
        }

        $identity = $identityResult['identity'];
        $personOrders = $this->filterOrdersForIdentity($orders, $identity);
        if ($personOrders === []) {
            return [
                'status' => 'needs_ops',
                'reason' => 'person_locked_no_order',
                'confidence' => $identityResult['confidence'],
                'identity' => $identity,
                'scope' => 'ctrip_ota_channel',
            ];
        }

        $reviewPublishedAt = $this->extractReviewPublishedAt($review);
        $publishEligibleOrders = $this->filterOrdersByReviewPublishEligibility($personOrders, $reviewPublishedAt);
        if ($reviewPublishedAt !== '' && $publishEligibleOrders === []) {
            return [
                'status' => 'person_locked',
                'reason' => 'person_locked_publish_time_mismatch',
                'confidence' => $identityResult['confidence'],
                'match_method' => 'im_uid_publish_time_mismatch',
                'person_name' => $identity['guest_name'],
                'identity' => $identity,
                'candidates' => array_map(fn(array $order): array => $this->normalizeOrderForResponse($order), $personOrders),
                'evidence' => [
                    'review_published_at' => $reviewPublishedAt,
                    'review_rule' => 'checkout_day_after_14_00',
                    'scope' => 'ctrip_ota_channel',
                ],
                'scope' => 'ctrip_ota_channel',
            ];
        }

        $dateFiltered = $publishEligibleOrders;
        if ($reviewDate !== '') {
            $dateFiltered = $this->filterOrdersByDate($publishEligibleOrders, $reviewDate);
        } elseif ($reviewMonth !== '') {
            $dateFiltered = $this->filterOrdersByMonth($publishEligibleOrders, $reviewMonth);
        }

        if (($reviewDate !== '' || $reviewMonth !== '') && $dateFiltered === []) {
            return [
                'status' => 'person_locked',
                'reason' => 'person_locked_date_mismatch',
                'confidence' => $identityResult['confidence'],
                'match_method' => 'im_uid_date_mismatch',
                'person_name' => $identity['guest_name'],
                'identity' => $identity,
                'candidates' => array_map(fn(array $order): array => $this->normalizeOrderForResponse($order), $personOrders),
                'scope' => 'ctrip_ota_channel',
            ];
        }

        $roomName = $this->extractRoomName($review);
        $roomFiltered = $this->filterOrdersByRoomPrefix($dateFiltered, $roomName);
        if ($roomName !== '' && $roomFiltered === []) {
            return [
                'status' => 'person_locked',
                'reason' => 'person_locked_room_mismatch',
                'confidence' => $identityResult['confidence'],
                'match_method' => 'im_uid_room_mismatch',
                'person_name' => $identity['guest_name'],
                'identity' => $identity,
                'candidates' => array_map(fn(array $order): array => $this->normalizeOrderForResponse($order), $dateFiltered),
                'scope' => 'ctrip_ota_channel',
            ];
        }

        if (count($roomFiltered) === 1) {
            $matchMethod = $this->resolveOrderMatchMethod($reviewDate, $reviewMonth, $roomName);
            if ($reviewDatePrecision !== 'day') {
                return [
                    'status' => 'person_locked',
                    'reason' => 'person_locked_order_evidence_insufficient',
                    'confidence' => $identityResult['confidence'],
                    'match_method' => $matchMethod . '_candidate',
                    'person_name' => $identity['guest_name'],
                    'identity' => $identity,
                    'candidates' => array_map(fn(array $order): array => $this->normalizeOrderForResponse($order), $roomFiltered),
                    'evidence' => [
                        'identity' => $identityResult['evidence'],
                        'order_filter' => [
                            'guest_uid' => $identity['guest_uid'],
                            'review_date' => $reviewDate,
                            'review_month' => $reviewMonth,
                            'review_date_precision' => $reviewDatePrecision,
                            'review_published_at' => $reviewPublishedAt,
                            'review_rule' => $reviewPublishedAt !== '' ? 'checkout_day_after_14_00' : '',
                            'review_room_name' => $roomName,
                            'candidate_count' => 1,
                            'auto_confirm_blocked_reason' => 'review_date_is_not_day_precision',
                        ],
                        'scope' => 'ctrip_ota_channel',
                    ],
                    'scope' => 'ctrip_ota_channel',
                ];
            }

            return [
                'status' => 'found',
                'confidence' => 'high',
                'match_method' => $matchMethod,
                'identity' => $identity,
                'order' => $this->normalizeOrderForResponse($roomFiltered[0]),
                'evidence' => [
                    'identity' => $identityResult['evidence'],
                    'order_filter' => [
                        'guest_uid' => $identity['guest_uid'],
                        'review_date' => $reviewDate,
                        'review_month' => $reviewMonth,
                        'review_date_precision' => $reviewDatePrecision,
                        'review_published_at' => $reviewPublishedAt,
                        'review_rule' => $reviewPublishedAt !== '' ? 'checkout_day_after_14_00' : '',
                        'review_room_name' => $roomName,
                    ],
                    'scope' => 'ctrip_ota_channel',
                ],
            ];
        }

        return [
            'status' => 'person_locked',
            'confidence' => $identityResult['confidence'],
            'match_method' => 'im_uid_multiple_orders',
            'person_name' => $identity['guest_name'],
            'identity' => $identity,
            'candidates' => array_map(fn(array $order): array => $this->normalizeOrderForResponse($order), $roomFiltered),
            'scope' => 'ctrip_ota_channel',
        ];
    }

    /**
     * Batch scoring is required to detect one order being selected by more
     * than one review. Single-review matching cannot prove that conflict.
     *
     * @param array<int, array<string, mixed>> $reviews
     * @param array<int, array<string, mixed>> $imSessions
     * @param array<int, array<string, mixed>> $orders
     * @param array<string, mixed> $options
     * @return array<int, array<string, mixed>>
     */
    public function buildReviewOrderMatches(array $reviews, array $imSessions, array $orders, array $options = []): array
    {
        $roomMapping = is_array($options['room_mapping'] ?? null) ? $options['room_mapping'] : [];
        return (new CtripReviewOrderCandidateScoringService())->buildMatches(
            $reviews,
            $orders,
            $roomMapping,
            $imSessions,
            $options
        );
    }

    /** @param array<string, mixed> $order */
    private function extractOrderId(array $order): string
    {
        return trim((string)($order['orderId'] ?? $order['order_id'] ?? $order['orderNo'] ?? $order['order_no'] ?? $order['orderSn'] ?? $order['order_sn'] ?? $order['platform_order_id'] ?? $order['bookingOrderId'] ?? $order['booking_order_id'] ?? $order['reservationOrderId'] ?? $order['reservation_order_id'] ?? ''));
    }

    /**
     * @param array<int, array<string, mixed>> $imSessions
     * @return array<string, array<string, string>>
     */
    public function buildMemberIndex(array $imSessions): array
    {
        $index = [];
        foreach ($imSessions as $session) {
            $members = $session['members'] ?? [];
            if (!is_array($members)) {
                continue;
            }
            foreach ($members as $key => $member) {
                if (!is_array($member)) {
                    continue;
                }
                $keyText = trim((string)$key);
                $keyUid = ($keyText !== '' && (!ctype_digit($keyText) || strlen($keyText) >= 6)) ? $keyText : '';
                $uid = strtolower(trim((string)($member['uid'] ?? $member['guestUid'] ?? $member['guest_uid'] ?? $member['memberUid'] ?? $member['member_uid'] ?? $member['userId'] ?? $member['user_id'] ?? $keyUid)));
                $nickName = trim((string)($member['nickName'] ?? $member['nickname'] ?? $member['name'] ?? ''));
                if ($uid === '' || !$this->isEligibleGuestMember($member, $nickName)) {
                    continue;
                }
                $index[$uid] = [
                    'uid' => $uid,
                    'nick_name' => $nickName,
                    'avatar_url' => trim((string)($member['pic'] ?? $member['avatar'] ?? $member['avatarUrl'] ?? '')),
                ];
            }
        }

        return $index;
    }

    /**
     * @param array<string, bool> $uidSet
     * @param array<string, array<string, string>> $members
     * @return array<string, string>|null
     */
    private function matchMaskedUsername(string $username, array $uidSet, array $members): ?array
    {
        $prefix = strtolower((string)preg_replace('/\*{4}$/u', '', trim($username)));
        if ($prefix === '') {
            return null;
        }

        $strategy = preg_match('/[a-z]$/', $prefix) ? 'masked_base36_suffix' : 'masked_numeric_suffix';
        if ($strategy === 'masked_base36_suffix') {
            $chars = str_split('0123456789abcdefghijklmnopqrstuvwxyz');
            foreach ($chars as $a) {
                foreach ($chars as $b) {
                    foreach ($chars as $c) {
                        foreach ($chars as $d) {
                            $candidate = $prefix . $a . $b . $c . $d;
                            $match = $this->matchCandidate($candidate, $uidSet, $members, $strategy);
                            if ($match !== null) {
                                return $match;
                            }
                        }
                    }
                }
            }
            return null;
        }

        for ($i = 0; $i <= 9999; $i++) {
            $candidate = $prefix . str_pad((string)$i, 4, '0', STR_PAD_LEFT);
            $match = $this->matchCandidate($candidate, $uidSet, $members, $strategy);
            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    /**
     * @param array<string, bool> $uidSet
     * @param array<string, array<string, string>> $members
     * @return array<string, string>|null
     */
    private function matchDirectUsername(string $username, array $uidSet, array $members): ?array
    {
        $trimmed = trim($username);
        if ($trimmed === '') {
            return null;
        }

        $candidates = array_values(array_unique([
            lcfirst($trimmed),
            ucfirst($trimmed),
            strtolower($trimmed),
        ]));

        foreach ($candidates as $candidate) {
            $match = $this->matchCandidate($candidate, $uidSet, $members, 'direct_case_variants');
            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    /**
     * @param array<string, bool> $uidSet
     * @param array<string, array<string, string>> $members
     * @return array<string, string>|null
     */
    private function matchCandidate(string $candidate, array $uidSet, array $members, string $strategy): ?array
    {
        $rawUid = strtolower(trim($candidate));
        $md5Uid = md5($rawUid);
        $uid = isset($uidSet[$rawUid]) ? $rawUid : $md5Uid;
        if (!isset($uidSet[$uid])) {
            return null;
        }

        return [
            'uid' => $uid,
            'nick_name' => $members[$uid]['nick_name'],
            'avatar_url' => $members[$uid]['avatar_url'],
            'candidate' => $candidate,
            'candidate_strategy' => $strategy,
        ];
    }

    /**
     * @param array<string, mixed> $member
     */
    private function isEligibleGuestMember(array $member, string $nickName): bool
    {
        if ($nickName === '' || in_array($nickName, self::SYSTEM_NICKNAMES, true)) {
            return false;
        }
        foreach (self::SYSTEM_NICKNAME_PREFIXES as $prefix) {
            if (str_starts_with($nickName, $prefix)) {
                return false;
            }
        }

        $roleType = strtolower(trim((string)($member['roleType'] ?? $member['role_type'] ?? '')));
        return $roleType === '' || $roleType === 'guest';
    }

    /**
     * @param array<string, mixed> $review
     */
    private function extractReviewUsername(array $review): string
    {
        foreach (['userName', 'user_name', 'sourceUsername', 'source_username', 'username', 'nickName', 'nick_name', 'user_name_masked'] as $field) {
            if (isset($review[$field]) && trim((string)$review[$field]) !== '') {
                return trim((string)$review[$field]);
            }
        }
        return '';
    }

    /**
     * @param array<string, mixed> $review
     * @param array<int, string> $fields
     */
    private function extractDate(array $review, array $fields): string
    {
        return $this->extractDateMatchInfo($review, $fields)['date'];
    }

    /**
     * @param array<string, mixed> $review
     * @param array<int, string> $fields
     * @return array{date:string,month:string,precision:string}
     */
    private function extractDateMatchInfo(array $review, array $fields): array
    {
        foreach ($fields as $field) {
            if (!isset($review[$field]) || trim((string)$review[$field]) === '') {
                continue;
            }
            $raw = (string)$review[$field];
            $date = $this->normalizeDate($raw);
            if ($date !== '') {
                return [
                    'date' => $date,
                    'month' => substr($date, 0, 7),
                    'precision' => 'day',
                ];
            }

            $month = $this->normalizeYearMonth($raw);
            if ($month !== '') {
                return [
                    'date' => '',
                    'month' => $month,
                    'precision' => 'month',
                ];
            }
        }

        return [
            'date' => '',
            'month' => '',
            'precision' => 'none',
        ];
    }

    private function normalizeDate(string $value): string
    {
        $text = trim($value);
        if ($text === '') {
            return '';
        }
        if (preg_match('/^20\d{2}$/', $text)) {
            return '';
        }
        if ($this->normalizeYearMonth($text) !== '') {
            return '';
        }
        if (preg_match('/(20\d{2})[-\/.年](\d{1,2})[-\/.月](\d{1,2})/u', $text, $matches)) {
            return sprintf('%04d-%02d-%02d', (int)$matches[1], (int)$matches[2], (int)$matches[3]);
        }
        if (preg_match('/(20\d{2})(\d{2})(\d{2})/', $text, $matches)) {
            return sprintf('%04d-%02d-%02d', (int)$matches[1], (int)$matches[2], (int)$matches[3]);
        }

        $timestamp = strtotime($text);
        return $timestamp === false ? '' : date('Y-m-d', $timestamp);
    }

    private function normalizeYearMonth(string $value): string
    {
        $text = trim($value);
        if ($text === '') {
            return '';
        }
        if (preg_match('/^(20\d{2})[-\/.](\d{1,2})$/', $text, $matches)) {
            return sprintf('%04d-%02d', (int)$matches[1], (int)$matches[2]);
        }
        if (preg_match('/^(20\d{2})\s*\x{5E74}\s*(\d{1,2})\s*\x{6708}\s*$/u', $text, $matches)) {
            return sprintf('%04d-%02d', (int)$matches[1], (int)$matches[2]);
        }
        return '';
    }

    /**
     * @param array<string, mixed> $review
     */
    private function extractReviewPublishedAt(array $review): string
    {
        foreach (['publishTime', 'publish_time', 'publishedAt', 'published_at', 'addtime', 'addTime', 'commentTime', 'comment_time', 'reviewTime', 'review_time', 'createTime', 'create_time', 'submitTime', 'submit_time', 'date'] as $field) {
            if (!isset($review[$field]) || trim((string)$review[$field]) === '') {
                continue;
            }
            $publishedAt = $this->normalizeDateTime((string)$review[$field]);
            if ($publishedAt !== '') {
                return $publishedAt;
            }
        }
        return '';
    }

    private function normalizeDateTime(string $value): string
    {
        $text = trim($value);
        if ($text === '') {
            return '';
        }
        $timezone = new \DateTimeZone('Asia/Shanghai');

        if (preg_match('/\/Date\((\d{10,13})(?:[+-]\d{4})?\)\//', $text, $matches)) {
            $timestamp = (int)$matches[1];
            if ($timestamp > 9999999999) {
                $timestamp = (int)floor($timestamp / 1000);
            }
            return (new \DateTimeImmutable('@' . $timestamp))
                ->setTimezone($timezone)
                ->format('Y-m-d H:i:s');
        }

        if (preg_match('/(20\d{2})\s*\x{5E74}\s*(\d{1,2})\s*\x{6708}\s*(\d{1,2})\s*\x{65E5}\s*(\d{1,2})[:\x{FF1A}](\d{1,2})(?:[:\x{FF1A}](\d{1,2}))?/u', $text, $matches)) {
            return sprintf('%04d-%02d-%02d %02d:%02d:%02d', (int)$matches[1], (int)$matches[2], (int)$matches[3], (int)$matches[4], (int)$matches[5], (int)($matches[6] ?? 0));
        }

        if (preg_match('/(20\d{2})[-\/.](\d{1,2})[-\/.](\d{1,2})(?:[ T]+\s*)?(\d{1,2})[:\x{FF1A}](\d{1,2})(?:[:\x{FF1A}](\d{1,2}))?/u', $text, $matches)) {
            return sprintf('%04d-%02d-%02d %02d:%02d:%02d', (int)$matches[1], (int)$matches[2], (int)$matches[3], (int)$matches[4], (int)$matches[5], (int)($matches[6] ?? 0));
        }

        $timestamp = strtotime($text);
        if ($timestamp === false || !preg_match('/\d{1,2}[:\x{FF1A}]\d{1,2}/u', $text)) {
            return '';
        }
        return (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone($timezone)
            ->format('Y-m-d H:i:s');
    }

    private function reviewEligibleAtForOrder(array $order): string
    {
        foreach (['departureDate', 'departure_date', 'departure', 'checkOutDate', 'check_out_date', 'checkOut', 'check_out', 'checkoutTime', 'checkout_time', 'checkOutTime', 'check_out_time'] as $field) {
            if (!isset($order[$field])) {
                continue;
            }
            $departureDate = $this->normalizeDate((string)$order[$field]);
            if ($departureDate === '') {
                continue;
            }
            $eligibleAt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $departureDate . ' 14:00:00', new \DateTimeZone('Asia/Shanghai'));
            if ($eligibleAt === false) {
                continue;
            }
            return $eligibleAt->format('Y-m-d H:i:s');
        }
        return '';
    }

    private function resolveOrderMatchMethod(string $reviewDate, string $reviewMonth, string $roomName): string
    {
        if ($reviewDate !== '' && $roomName !== '') {
            return 'im_uid_date_room';
        }
        if ($reviewMonth !== '' && $roomName !== '') {
            return 'im_uid_month_room';
        }
        if ($reviewDate !== '') {
            return 'im_uid_date_unique';
        }
        if ($reviewMonth !== '') {
            return 'im_uid_month_unique';
        }
        return 'im_uid_order_unique';
    }

    /**
     * @param array<string, mixed> $review
     */
    private function extractRoomName(array $review): string
    {
        foreach (['hotelRoomInfo', 'hotel_room_info', 'roomName', 'room_name', 'roomType', 'room_type', 'room_type_name', 'productName', 'product_name', 'ratePlanName', 'rate_plan_name'] as $field) {
            if (isset($review[$field]) && trim((string)$review[$field]) !== '') {
                return trim((string)$review[$field]);
            }
        }
        return '';
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @param array<string, string> $identity
     * @return array<int, array<string, mixed>>
     */
    private function filterOrdersForIdentity(array $orders, array $identity): array
    {
        $guestUid = strtolower((string)($identity['guest_uid'] ?? ''));
        $matchedCandidate = strtolower((string)($identity['matched_candidate'] ?? ''));
        $guestName = trim((string)($identity['guest_name'] ?? ''));

        return array_values(array_filter($orders, function (array $order) use ($guestUid, $matchedCandidate, $guestName): bool {
            $platform = strtolower(trim((string)($order['platform'] ?? $order['sourcePlatform'] ?? $order['source_platform'] ?? 'ctrip')));
            if ($platform !== '' && $platform !== 'ctrip') {
                return false;
            }

            foreach (['guestUid', 'guest_uid', 'ctrip_guest_uid', 'memberUid', 'member_uid', 'uid'] as $field) {
                if (isset($order[$field]) && $this->identityUidMatchesOrderUid((string)$order[$field], $guestUid, $matchedCandidate)) {
                    return true;
                }
            }

            foreach (['guestName', 'guest_name', 'customerName', 'customer_name', 'contactName', 'contact_name', 'clientName', 'client_name'] as $field) {
                if ($guestName !== '' && isset($order[$field]) && trim((string)$order[$field]) === $guestName) {
                    return true;
                }
            }

            return false;
        }));
    }

    private function identityUidMatchesOrderUid(string $orderUid, string $identityUid, string $matchedCandidate): bool
    {
        $orderUid = strtolower(trim($orderUid));
        $identityUid = strtolower(trim($identityUid));
        $matchedCandidate = strtolower(trim($matchedCandidate));
        if ($orderUid === '') {
            return false;
        }

        foreach (array_filter([$identityUid, $matchedCandidate]) as $candidate) {
            if ($orderUid === $candidate || md5($orderUid) === $candidate || $orderUid === md5($candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @return array<int, array<string, mixed>>
     */
    private function filterOrdersByReviewPublishEligibility(array $orders, string $reviewPublishedAt): array
    {
        if ($reviewPublishedAt === '') {
            return $orders;
        }

        return array_values(array_filter($orders, function (array $order) use ($reviewPublishedAt): bool {
            $eligibleAt = $this->reviewEligibleAtForOrder($order);
            if ($eligibleAt === '') {
                return true;
            }
            return $reviewPublishedAt >= $eligibleAt;
        }));
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @return array<int, array<string, mixed>>
     */
    private function filterOrdersByDate(array $orders, string $reviewDate): array
    {
        if ($reviewDate === '') {
            return $orders;
        }

        $matched = array_values(array_filter($orders, function (array $order) use ($reviewDate): bool {
            foreach (['arrivalDate', 'arrival_date', 'arrival', 'checkInDate', 'check_in_date', 'checkIn', 'check_in', 'checkinTime', 'checkin_time', 'checkInTime', 'check_in_time', 'stayDate', 'stay_date'] as $field) {
                if (isset($order[$field]) && $this->normalizeDate((string)$order[$field]) === $reviewDate) {
                    return true;
                }
            }
            return false;
        }));

        return $matched;
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @return array<int, array<string, mixed>>
     */
    private function filterOrdersByMonth(array $orders, string $reviewMonth): array
    {
        if ($reviewMonth === '') {
            return $orders;
        }

        return array_values(array_filter($orders, function (array $order) use ($reviewMonth): bool {
            foreach (['arrivalDate', 'arrival_date', 'arrival', 'checkInDate', 'check_in_date', 'checkIn', 'check_in', 'checkinTime', 'checkin_time', 'checkInTime', 'check_in_time', 'stayDate', 'stay_date'] as $field) {
                if (!isset($order[$field])) {
                    continue;
                }
                $orderDate = $this->normalizeDate((string)$order[$field]);
                if ($orderDate !== '' && substr($orderDate, 0, 7) === $reviewMonth) {
                    return true;
                }
            }
            return false;
        }));
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @return array<int, array<string, mixed>>
     */
    private function filterOrdersByRoomPrefix(array $orders, string $roomName): array
    {
        if ($roomName === '') {
            return $orders;
        }

        $matched = array_values(array_filter($orders, function (array $order) use ($roomName): bool {
            foreach (['roomName', 'room_name', 'roomType', 'room_type', 'room_type_name', 'productName', 'product_name', 'ratePlanName', 'rate_plan_name'] as $field) {
                $orderRoom = trim((string)($order[$field] ?? ''));
                if ($orderRoom !== '' && str_starts_with($orderRoom, $roomName)) {
                    return true;
                }
            }
            return false;
        }));

        return $matched;
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function normalizeOrderForResponse(array $order): array
    {
        return [
            'order_id' => (string)($order['orderId'] ?? $order['order_id'] ?? $order['orderNo'] ?? $order['order_no'] ?? $order['orderSn'] ?? $order['order_sn'] ?? $order['platform_order_id'] ?? $order['bookingOrderId'] ?? $order['booking_order_id'] ?? $order['reservationOrderId'] ?? $order['reservation_order_id'] ?? ''),
            'guest_name' => (string)($order['guestName'] ?? $order['guest_name'] ?? $order['customerName'] ?? $order['customer_name'] ?? $order['contactName'] ?? $order['contact_name'] ?? $order['clientName'] ?? $order['client_name'] ?? ''),
            'guest_uid' => (string)($order['guestUid'] ?? $order['guest_uid'] ?? $order['ctrip_guest_uid'] ?? $order['memberUid'] ?? $order['member_uid'] ?? $order['uid'] ?? ''),
            'arrival_date' => $this->normalizeDate((string)($order['arrivalDate'] ?? $order['arrival_date'] ?? $order['arrival'] ?? $order['checkInDate'] ?? $order['check_in_date'] ?? $order['checkIn'] ?? $order['check_in'] ?? $order['checkinTime'] ?? $order['checkin_time'] ?? $order['checkInTime'] ?? $order['check_in_time'] ?? $order['stayDate'] ?? $order['stay_date'] ?? '')),
            'departure_date' => $this->normalizeDate((string)($order['departureDate'] ?? $order['departure_date'] ?? $order['departure'] ?? $order['checkOutDate'] ?? $order['check_out_date'] ?? $order['checkOut'] ?? $order['check_out'] ?? $order['checkoutTime'] ?? $order['checkout_time'] ?? $order['checkOutTime'] ?? $order['check_out_time'] ?? '')),
            'room_name' => (string)($order['roomName'] ?? $order['room_name'] ?? $order['roomType'] ?? $order['room_type'] ?? $order['room_type_name'] ?? $order['productName'] ?? $order['product_name'] ?? $order['ratePlanName'] ?? $order['rate_plan_name'] ?? ''),
            'match_source' => 'ctrip_order_pool',
        ];
    }
}
