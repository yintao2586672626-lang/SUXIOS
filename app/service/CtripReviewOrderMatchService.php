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
        $reviewDate = $this->extractDate($review, ['checkinTimeStr', 'checkInDate', 'checkinDate', 'arrivalDate', 'arrival_date']);
        $coverageStart = $this->normalizeDate((string)($options['coverage_start_date'] ?? ''));
        if ($coverageStart !== '' && $reviewDate !== '' && $reviewDate < $coverageStart) {
            return [
                'status' => 'out_of_coverage',
                'reason' => 'review_before_order_coverage',
                'confidence' => 'none',
                'review_date' => $reviewDate,
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

        $dateFiltered = $this->filterOrdersByDate($personOrders, $reviewDate);
        $roomFiltered = $this->filterOrdersByRoomPrefix($dateFiltered, $this->extractRoomName($review));

        if (count($roomFiltered) === 1) {
            return [
                'status' => 'found',
                'confidence' => 'high',
                'match_method' => $reviewDate !== '' && $this->extractRoomName($review) !== '' ? 'im_uid_date_room' : 'im_uid_order_unique',
                'identity' => $identity,
                'order' => $this->normalizeOrderForResponse($roomFiltered[0]),
                'evidence' => [
                    'identity' => $identityResult['evidence'],
                    'order_filter' => [
                        'guest_uid' => $identity['guest_uid'],
                        'review_date' => $reviewDate,
                        'review_room_name' => $this->extractRoomName($review),
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
                $uid = strtolower(trim((string)($member['uid'] ?? $key)));
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
        $uid = md5($candidate);
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
        foreach (['userName', 'user_name', 'sourceUsername', 'source_username', 'username', 'nickName', 'nick_name'] as $field) {
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
        foreach ($fields as $field) {
            if (!isset($review[$field]) || trim((string)$review[$field]) === '') {
                continue;
            }
            $date = $this->normalizeDate((string)$review[$field]);
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
        if (preg_match('/(20\d{2})[-\/.年](\d{1,2})[-\/.月](\d{1,2})/u', $text, $matches)) {
            return sprintf('%04d-%02d-%02d', (int)$matches[1], (int)$matches[2], (int)$matches[3]);
        }
        if (preg_match('/(20\d{2})(\d{2})(\d{2})/', $text, $matches)) {
            return sprintf('%04d-%02d-%02d', (int)$matches[1], (int)$matches[2], (int)$matches[3]);
        }

        $timestamp = strtotime($text);
        return $timestamp === false ? '' : date('Y-m-d', $timestamp);
    }

    /**
     * @param array<string, mixed> $review
     */
    private function extractRoomName(array $review): string
    {
        foreach (['hotelRoomInfo', 'hotel_room_info', 'roomName', 'room_name', 'roomType', 'room_type'] as $field) {
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
        $guestName = trim((string)($identity['guest_name'] ?? ''));

        return array_values(array_filter($orders, function (array $order) use ($guestUid, $guestName): bool {
            $platform = strtolower(trim((string)($order['platform'] ?? $order['sourcePlatform'] ?? $order['source_platform'] ?? 'ctrip')));
            if ($platform !== '' && $platform !== 'ctrip') {
                return false;
            }

            foreach (['guestUid', 'guest_uid', 'ctrip_guest_uid', 'memberUid', 'member_uid', 'uid'] as $field) {
                if (isset($order[$field]) && strtolower(trim((string)$order[$field])) === $guestUid) {
                    return true;
                }
            }

            foreach (['guestName', 'guest_name', 'customerName', 'customer_name'] as $field) {
                if ($guestName !== '' && isset($order[$field]) && trim((string)$order[$field]) === $guestName) {
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
    private function filterOrdersByDate(array $orders, string $reviewDate): array
    {
        if ($reviewDate === '') {
            return $orders;
        }

        $matched = array_values(array_filter($orders, function (array $order) use ($reviewDate): bool {
            foreach (['arrivalDate', 'arrival_date', 'checkInDate', 'check_in_date'] as $field) {
                if (isset($order[$field]) && $this->normalizeDate((string)$order[$field]) === $reviewDate) {
                    return true;
                }
            }
            return false;
        }));

        return $matched === [] ? $orders : $matched;
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
            foreach (['roomName', 'room_name', 'roomType', 'room_type'] as $field) {
                $orderRoom = trim((string)($order[$field] ?? ''));
                if ($orderRoom !== '' && str_starts_with($orderRoom, $roomName)) {
                    return true;
                }
            }
            return false;
        }));

        return $matched === [] ? $orders : $matched;
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function normalizeOrderForResponse(array $order): array
    {
        return [
            'order_id' => (string)($order['orderId'] ?? $order['order_id'] ?? $order['platform_order_id'] ?? ''),
            'guest_name' => (string)($order['guestName'] ?? $order['guest_name'] ?? $order['customerName'] ?? $order['customer_name'] ?? ''),
            'guest_uid' => (string)($order['guestUid'] ?? $order['guest_uid'] ?? $order['ctrip_guest_uid'] ?? ''),
            'arrival_date' => $this->normalizeDate((string)($order['arrivalDate'] ?? $order['arrival_date'] ?? $order['checkInDate'] ?? $order['check_in_date'] ?? '')),
            'room_name' => (string)($order['roomName'] ?? $order['room_name'] ?? $order['roomType'] ?? $order['room_type'] ?? ''),
            'match_source' => 'ctrip_order_pool',
        ];
    }
}
