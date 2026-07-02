<?php
declare(strict_types=1);

namespace app\service;

final class MeituanReviewOrderMatchService
{
    /**
     * @param array<string, mixed> $review
     * @param array<int, array<string, mixed>> $orders
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function matchReviewToOrder(array $review, array $orders, array $options = []): array
    {
        return (new OtaReviewRiskPolicyService())->blockedOperation('meituan_review_order_match_service', [
            'identity_reverse_lookup',
            'phone_acquisition',
            'anonymous_user_matching',
        ]);

        $coverageStart = $this->normalizeDate((string)($options['coverage_start_date'] ?? ''));
        $reviewDate = $this->extractDate($review, ['checkInDate', 'check_in_date', 'checkinDate', 'arrivalDate', 'arrival_date', 'consumeDate', 'consume_date']);
        if ($coverageStart !== '' && $reviewDate !== '' && $reviewDate < $coverageStart) {
            return [
                'status' => 'out_of_coverage',
                'reason' => 'review_before_order_coverage',
                'confidence' => 'none',
                'review_date' => $reviewDate,
                'coverage_start_date' => $coverageStart,
                'scope' => 'meituan_ota_channel',
            ];
        }

        $scored = [];
        foreach ($orders as $order) {
            if (!$this->isMeituanOrder($order)) {
                continue;
            }

            $score = $this->scoreCandidate($review, $order);
            if ($score['score'] <= 0) {
                continue;
            }

            $scored[] = [
                'order' => $order,
                'score' => $score['score'],
                'reasons' => $score['reasons'],
                'has_strong_identity' => $score['has_strong_identity'],
            ];
        }

        if ($scored === []) {
            return [
                'status' => 'needs_ops',
                'reason' => 'no_candidate_order',
                'confidence' => 'none',
                'scope' => 'meituan_ota_channel',
            ];
        }

        usort($scored, static fn(array $left, array $right): int => $right['score'] <=> $left['score']);
        $top = $scored[0];
        $secondScore = (int)($scored[1]['score'] ?? 0);
        $confidence = $this->confidenceForScore((int)$top['score']);
        $method = $this->buildMatchMethod($top['reasons']);

        if (
            $top['has_strong_identity']
            && $top['score'] >= 120
            && ((int)$top['score'] - $secondScore >= 20)
        ) {
            return [
                'status' => 'found',
                'confidence' => $confidence,
                'match_method' => $method,
                'order' => $this->normalizeOrderForResponse($top['order']),
                'evidence' => [
                    'source' => 'meituan_order_pool',
                    'score' => $top['score'],
                    'reasons' => $top['reasons'],
                    'scope' => 'meituan_ota_channel',
                    'business_metric' => false,
                ],
                'scope' => 'meituan_ota_channel',
            ];
        }

        return [
            'status' => 'candidate_review_order',
            'reason' => $top['has_strong_identity'] ? 'ambiguous_candidates' : 'lacks_strong_identity',
            'confidence' => $confidence,
            'match_method' => 'weighted_candidate',
            'requires_manual_bind' => true,
            'candidates' => array_map(function (array $candidate): array {
                $order = $this->normalizeOrderForResponse($candidate['order']);
                $order['score'] = $candidate['score'];
                $order['confidence'] = $this->confidenceForScore((int)$candidate['score']);
                $order['reasons'] = $candidate['reasons'];
                return $order;
            }, array_slice($scored, 0, 5)),
            'scope' => 'meituan_ota_channel',
        ];
    }

    /**
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

        $appSessionStatus = strtolower(trim((string)($context['app_session_status'] ?? $order['app_session_status'] ?? 'unknown')));
        $appSessionReady = in_array($appSessionStatus, ['ready', 'authorized', 'available'], true);
        $phoneValue = $this->extractPhoneValue($order);
        $last4 = $this->last4($phoneValue);

        if ($last4 !== '') {
            $isFullPhone = preg_match('/^\D*\d{7,}\D*$/', $phoneValue) === 1 && !str_contains($phoneValue, '*');
            return [
                'phone_status' => $isFullPhone ? 'available_masked' : 'masked_only',
                'phone_masked' => $this->maskPhone($phoneValue),
                'phone_last4' => $last4,
                'phone_source' => $this->extractPhoneSource($order),
                'app_session_status' => $appSessionStatus,
                'can_request_reveal' => $appSessionReady,
                'reveal_policy' => 'requires_separate_permission',
                'next_action' => $appSessionReady ? 'request_authorized_reveal' : 'requires_authorized_app_session',
                'business_metric' => false,
                'scope' => 'meituan_ota_channel',
            ];
        }

        return [
            'phone_status' => $appSessionReady ? 'not_returned_by_order_api' : 'app_session_not_ready',
            'phone_masked' => '',
            'phone_last4' => '',
            'phone_source' => 'not_returned',
            'app_session_status' => $appSessionStatus,
            'can_request_reveal' => false,
            'reveal_policy' => 'requires_separate_permission',
            'next_action' => $appSessionReady ? 'check_authorized_order_detail_source' : 'requires_authorized_app_session',
            'business_metric' => false,
            'scope' => 'meituan_ota_channel',
        ];
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    public function sanitizeOrderForStorage(array $order): array
    {
        return $this->sanitizePayload($order, false);
    }

    /**
     * @param array<string, mixed> $review
     * @return array<string, mixed>
     */
    public function normalizeReviewForStorage(array $review): array
    {
        return [
            'review_id' => $this->extractReviewId($review),
            'meituan_user_id' => $this->extractMeituanUserId($review),
            'source_username' => $this->firstString($review, ['userName', 'user_name', 'nickName', 'nick_name', 'username']),
            'review_date' => $this->extractDate($review, ['reviewDate', 'review_date', 'commentTime', 'comment_time', 'addTime', 'add_time']),
            'checkin_date' => $this->extractDate($review, ['checkInDate', 'check_in_date', 'checkinDate', 'arrivalDate', 'arrival_date', 'consumeDate', 'consume_date']),
            'room_name' => $this->extractRoomName($review),
            'score' => $this->extractScore($review),
            'content' => trim((string)($review['content'] ?? $review['comment'] ?? $review['reviewContent'] ?? '')),
            'raw_review_json' => json_encode($this->sanitizePayload($review, false), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}',
        ];
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    public function normalizeOrderForStorage(array $order): array
    {
        $phone = $this->buildPhoneHandlingState($order, [
            'app_session_status' => $order['app_session_status'] ?? 'unknown',
        ]);

        return [
            'order_id' => $this->extractOrderId($order),
            'meituan_user_id' => $this->extractMeituanUserId($order),
            'guest_name_masked' => $this->maskName($this->firstString($order, ['guestName', 'guest_name', 'customerName', 'customer_name', 'contactName', 'contact_name'])),
            'arrival_date' => $this->extractDate($order, ['checkInDate', 'check_in_date', 'checkinDate', 'arrivalDate', 'arrival_date']),
            'departure_date' => $this->extractDate($order, ['checkOutDate', 'check_out_date', 'departureDate', 'departure_date']),
            'room_name' => $this->extractRoomName($order),
            'order_status' => $this->firstString($order, ['orderStatus', 'order_status', 'status']),
            'phone_masked' => (string)($phone['phone_masked'] ?? ''),
            'phone_last4' => (string)($phone['phone_last4'] ?? ''),
            'phone_status' => (string)($phone['phone_status'] ?? $phone['status'] ?? OtaReviewRiskPolicyService::STATUS_BLOCKED),
            'phone_source' => (string)($phone['phone_source'] ?? 'blocked_by_policy'),
            'raw_order_json' => json_encode($this->sanitizeOrderForStorage($order), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}',
        ];
    }

    /**
     * @param array<string, mixed> $review
     */
    public function extractReviewId(array $review): string
    {
        return $this->firstString($review, ['reviewId', 'review_id', 'commentId', 'comment_id', 'id']);
    }

    /**
     * @param array<string, mixed> $order
     */
    public function extractOrderId(array $order): string
    {
        return $this->firstString($order, ['orderId', 'order_id', 'orderNo', 'order_no', 'platform_order_id', 'id']);
    }

    /**
     * @param array<string, mixed> $review
     * @param array<string, mixed> $order
     * @return array{score:int, reasons:array<int, string>, has_strong_identity:bool}
     */
    private function scoreCandidate(array $review, array $order): array
    {
        $score = 0;
        $reasons = [];
        $hasStrongIdentity = false;

        $reviewHotel = $this->extractHotelId($review);
        $orderHotel = $this->extractHotelId($order);
        if ($reviewHotel !== '' && $orderHotel !== '' && $reviewHotel === $orderHotel) {
            $score += 30;
            $reasons[] = 'hotel_id';
        }

        $reviewUser = $this->extractMeituanUserId($review);
        $orderUser = $this->extractMeituanUserId($order);
        if ($reviewUser !== '' && $orderUser !== '' && $reviewUser === $orderUser) {
            $score += 70;
            $reasons[] = 'meituan_user_id';
            $hasStrongIdentity = true;
        }

        $reviewDate = $this->extractDate($review, ['checkInDate', 'check_in_date', 'checkinDate', 'arrivalDate', 'arrival_date', 'consumeDate', 'consume_date']);
        $orderDate = $this->extractDate($order, ['checkInDate', 'check_in_date', 'checkinDate', 'arrivalDate', 'arrival_date']);
        if ($reviewDate !== '' && $orderDate !== '' && $reviewDate === $orderDate) {
            $score += 35;
            $reasons[] = 'date';
        }

        if ($this->roomNamesAlign($this->extractRoomName($review), $this->extractRoomName($order))) {
            $score += 25;
            $reasons[] = 'room';
        }

        $reviewLast4 = $this->extractPhoneLast4($review);
        $orderLast4 = $this->extractPhoneLast4($order);
        if ($reviewLast4 !== '' && $orderLast4 !== '' && $reviewLast4 === $orderLast4) {
            $score += 25;
            $reasons[] = 'phone_last4';
            $hasStrongIdentity = true;
        }

        $status = strtolower($this->firstString($order, ['orderStatus', 'order_status', 'status']));
        if ($status !== '' && !preg_match('/cancel|refund|close|invalid|void/i', $status)) {
            $score += 10;
            $reasons[] = 'valid_order_status';
        }

        return [
            'score' => $score,
            'reasons' => $reasons,
            'has_strong_identity' => $hasStrongIdentity,
        ];
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function normalizeOrderForResponse(array $order): array
    {
        return [
            'order_id' => $this->extractOrderId($order),
            'meituan_user_id' => $this->extractMeituanUserId($order),
            'guest_name_masked' => $this->maskName($this->firstString($order, ['guestName', 'guest_name', 'customerName', 'customer_name', 'contactName', 'contact_name', 'guestNameMasked', 'guest_name_masked'])),
            'arrival_date' => $this->extractDate($order, ['checkInDate', 'check_in_date', 'checkinDate', 'arrivalDate', 'arrival_date']),
            'departure_date' => $this->extractDate($order, ['checkOutDate', 'check_out_date', 'departureDate', 'departure_date']),
            'room_name' => $this->extractRoomName($order),
            'order_status' => $this->firstString($order, ['orderStatus', 'order_status', 'status']),
            'phone' => $this->buildPhoneHandlingState($order, [
                'app_session_status' => $order['app_session_status'] ?? 'unknown',
            ]),
            'match_source' => 'meituan_order_pool',
        ];
    }

    private function confidenceForScore(int $score): string
    {
        if ($score >= 130) {
            return 'high';
        }
        if ($score >= 70) {
            return 'medium';
        }
        if ($score > 0) {
            return 'low';
        }
        return 'none';
    }

    /**
     * @param array<int, string> $reasons
     */
    private function buildMatchMethod(array $reasons): string
    {
        $parts = [];
        if (in_array('meituan_user_id', $reasons, true)) {
            $parts[] = 'meituan_user';
        }
        if (in_array('date', $reasons, true)) {
            $parts[] = 'date';
        }
        if (in_array('room', $reasons, true)) {
            $parts[] = 'room';
        }
        if (in_array('phone_last4', $reasons, true)) {
            $parts[] = 'phone';
        }

        return $parts === [] ? 'weighted_candidate' : implode('_', $parts);
    }

    /**
     * @param array<string, mixed> $order
     */
    private function isMeituanOrder(array $order): bool
    {
        $platform = strtolower(trim((string)($order['platform'] ?? $order['sourcePlatform'] ?? $order['source_platform'] ?? 'meituan')));
        return $platform === '' || $platform === 'meituan' || $platform === 'mt';
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $fields
     */
    private function firstString(array $data, array $fields): string
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
     */
    private function extractMeituanUserId(array $data): string
    {
        return strtolower($this->firstString($data, ['mtUserId', 'mt_user_id', 'meituanUserId', 'meituan_user_id', 'userId', 'user_id', 'memberId', 'member_id', 'uid']));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractHotelId(array $data): string
    {
        return strtolower($this->firstString($data, ['hotelId', 'hotel_id', 'poiId', 'poi_id', 'shopId', 'shop_id', 'storeId', 'store_id']));
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $fields
     */
    private function extractDate(array $data, array $fields): string
    {
        foreach ($fields as $field) {
            if (!isset($data[$field]) || trim((string)$data[$field]) === '') {
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
            return sprintf('%04d-%02d-%02d', (int)$matches[1], (int)$matches[2], (int)$matches[3]);
        }
        if (preg_match('/(20\d{2})(\d{2})(\d{2})/', $text, $matches)) {
            return sprintf('%04d-%02d-%02d', (int)$matches[1], (int)$matches[2], (int)$matches[3]);
        }

        $timestamp = strtotime($text);
        return $timestamp === false ? '' : date('Y-m-d', $timestamp);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractRoomName(array $data): string
    {
        return $this->firstString($data, ['roomName', 'room_name', 'roomType', 'room_type', 'hotelRoomInfo', 'hotel_room_info']);
    }

    private function roomNamesAlign(string $reviewRoom, string $orderRoom): bool
    {
        $review = $this->normalizeRoomName($reviewRoom);
        $order = $this->normalizeRoomName($orderRoom);
        if ($review === '' || $order === '') {
            return false;
        }

        return str_contains($order, $review) || str_contains($review, $order);
    }

    private function normalizeRoomName(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = (string)preg_replace('/\s+/', '', $normalized);
        return (string)preg_replace('/[^\p{L}\p{N}]+/u', '', $normalized);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractPhoneLast4(array $data): string
    {
        foreach (['phoneLast4', 'phone_last4', 'mobileLast4', 'mobile_last4'] as $field) {
            if (isset($data[$field])) {
                $last4 = $this->last4((string)$data[$field]);
                if ($last4 !== '') {
                    return $last4;
                }
            }
        }

        return $this->last4($this->extractPhoneValue($data));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractPhoneValue(array $data): string
    {
        foreach (['phone', 'mobile', 'tel', 'contactPhone', 'contact_phone', 'guestPhone', 'guest_phone', 'ownerPhone', 'owner_phone'] as $field) {
            if (isset($data[$field]) && trim((string)$data[$field]) !== '') {
                return trim((string)$data[$field]);
            }
        }

        foreach (['contacts', 'contactList', 'contact_list'] as $field) {
            if (!isset($data[$field]) || !is_array($data[$field])) {
                continue;
            }
            foreach ($data[$field] as $contact) {
                if (is_array($contact)) {
                    $phone = $this->extractPhoneValue($contact);
                    if ($phone !== '') {
                        return $phone;
                    }
                }
            }
        }

        foreach (['ownerPhones', 'owner_phones', 'phones'] as $field) {
            if (!isset($data[$field]) || !is_array($data[$field])) {
                continue;
            }
            foreach ($data[$field] as $phone) {
                if (trim((string)$phone) !== '') {
                    return trim((string)$phone);
                }
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractPhoneSource(array $data): string
    {
        foreach (['phone_source', 'phoneSource', 'source'] as $field) {
            if (isset($data[$field]) && trim((string)$data[$field]) !== '') {
                return trim((string)$data[$field]);
            }
        }
        return 'authorized_order_payload';
    }

    private function maskPhone(string $value): string
    {
        $last4 = $this->last4($value);
        if ($last4 === '') {
            return '';
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';
        $length = strlen($digits) >= 7 ? strlen($digits) : 11;
        return str_repeat('*', max(0, $length - 4)) . $last4;
    }

    private function last4(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        return strlen($digits) >= 4 ? substr($digits, -4) : '';
    }

    private function maskName(string $value): string
    {
        $name = trim($value);
        if ($name === '') {
            return '';
        }
        if (function_exists('mb_substr') && function_exists('mb_strlen')) {
            return mb_substr($name, 0, 1) . str_repeat('*', max(1, mb_strlen($name) - 1));
        }
        return substr($name, 0, 1) . str_repeat('*', max(1, strlen($name) - 1));
    }

    private function extractScore(array $review): ?float
    {
        foreach (['score', 'avgScore', 'avg_score', 'rating'] as $field) {
            if (isset($review[$field]) && is_numeric($review[$field])) {
                return (float)$review[$field];
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sanitizePayload(array $payload, bool $orderContext): array
    {
        $output = [];
        foreach ($payload as $key => $value) {
            $normalizedKey = $this->normalizeKey((string)$key);
            if ($this->isSensitiveTextKey($normalizedKey)) {
                continue;
            }
            if ($this->isOrderIdKey($normalizedKey, $orderContext)) {
                $output['order_id_hash'] = hash('sha256', 'meituan_order|' . (string)$value);
                continue;
            }
            if ($this->isPhoneKey($normalizedKey)) {
                $output[$this->redactedFieldName((string)$key, 'masked')] = $this->maskPhone((string)$value);
                continue;
            }
            if ($this->isGuestNameKey($normalizedKey, $orderContext)) {
                $output[$this->redactedFieldName((string)$key, 'masked')] = $this->maskName((string)$value);
                continue;
            }
            if (is_array($value)) {
                $output[(string)$key] = $this->sanitizePayload($value, $orderContext || $this->isOrderContainerKey($normalizedKey));
                continue;
            }

            $output[(string)$key] = $value;
        }

        return $output;
    }

    private function normalizeKey(string $key): string
    {
        $key = preg_replace('/(?<!^)[A-Z]/', '_$0', $key) ?? $key;
        return strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', '_', $key));
    }

    private function isOrderContainerKey(string $key): bool
    {
        return preg_match('/order_?(list|rows|items|data|detail|details|info)|orders/', $key) === 1;
    }

    private function isOrderIdKey(string $key, bool $orderContext): bool
    {
        if ($orderContext && in_array($key, ['id', 'sn'], true)) {
            return true;
        }
        return preg_match('/^(order_?(id|no|num|number|sn)|booking_?(id|no|number))$/', $key) === 1;
    }

    private function isPhoneKey(string $key): bool
    {
        return preg_match('/(phone|mobile|tel)$/', $key) === 1;
    }

    private function isGuestNameKey(string $key, bool $orderContext): bool
    {
        if ($orderContext && in_array($key, ['name', 'full_name'], true)) {
            return true;
        }
        return preg_match('/(guest|customer|contact|user|traveller|passenger)_?name$/', $key) === 1;
    }

    private function isSensitiveTextKey(string $key): bool
    {
        return preg_match('/certificate|credential|id_?card|card_?no|passport|remark|memo|note|address/', $key) === 1;
    }

    private function redactedFieldName(string $key, string $suffix): string
    {
        $name = $this->normalizeKey($key);
        $name = trim($name, '_');
        return ($name !== '' ? $name : 'field') . '_' . $suffix;
    }
}
