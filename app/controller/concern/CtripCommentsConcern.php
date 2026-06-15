<?php
declare(strict_types=1);

namespace app\controller\concern;

use think\Response;
use think\facade\Db;

trait CtripCommentsConcern
{

    /**
     * 获取携程点评数据
     */
    public function fetchCtripComments(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');
        return $this->commentCollectionDisabledResponse();
    }
    private function parseAndSaveCtripComments(array $comments, array $payload, string $requestHotelId, string $fallbackDate = '', ?int $systemHotelId = null): int
    {
        if (empty($comments)) {
            return 0;
        }

        $platformHotelId = $requestHotelId
            ?: (string)($payload['hotelId'] ?? $payload['hotel_id'] ?? $payload['masterHotelId'] ?? $payload['master_hotel_id'] ?? '');
        $fallbackDate = $this->normalizeOnlineDataDate($fallbackDate) ?: date('Y-m-d');

        $aggregate = $this->buildCtripCommentAggregate($comments, $payload, $platformHotelId, $fallbackDate);
        if (($aggregate['hotel_id'] ?? '') === '' && $systemHotelId === null) {
            return 0;
        }

        $rawData = [
            'source' => 'ctrip_comment_aggregate',
            'metric_scope' => 'ota_channel',
            'dimension_values' => array_filter([
                'comment_store_name' => $aggregate['hotel_name'],
                'comment_date' => $aggregate['data_date'],
                'comment_channel' => $aggregate['comment_channel'],
            ], static fn($value): bool => $value !== null && $value !== ''),
            'metrics' => array_filter([
                'comment_score' => $aggregate['comment_score'],
                'comment_count' => $aggregate['comment_count'],
                'bad_review_count' => $aggregate['bad_review_count'],
            ], static fn($value): bool => $value !== null && $value !== ''),
        ];

        $data = $this->filterOnlineDailyDataFields($this->applyOnlineDailyDataValidationFields([
            'hotel_id' => $aggregate['hotel_id'],
            'hotel_name' => $aggregate['hotel_name'],
            'system_hotel_id' => $systemHotelId,
            'data_date' => $aggregate['data_date'],
            'amount' => 0,
            'quantity' => 0,
            'book_order_num' => 0,
            'comment_score' => $aggregate['comment_score'] ?? 0,
            'qunar_comment_score' => 0,
            'data_value' => $aggregate['comment_count'] > 0 ? $aggregate['comment_count'] : ($aggregate['comment_score'] ?? 0),
            'source' => 'ctrip',
            'data_type' => 'quality',
            'dimension' => '点评聚合',
            'platform' => 'Ctrip',
            'compare_type' => 'self',
            'raw_data' => json_encode($rawData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        ]));

        $query = Db::name('online_daily_data')
            ->where('source', 'ctrip')
            ->where('data_type', 'quality')
            ->where('dimension', '点评聚合')
            ->where('hotel_id', $aggregate['hotel_id'])
            ->where('data_date', $aggregate['data_date']);
        if ($systemHotelId !== null) {
            $query->where('system_hotel_id', $systemHotelId);
        } else {
            $query->whereNull('system_hotel_id');
        }
        $exists = $query->find();

        if ($exists) {
            Db::name('online_daily_data')->where('id', $exists['id'])->update($data);
        } else {
            Db::name('online_daily_data')->insert($data);
        }

        return 1;
    }

    /**
     * @param array<int, mixed> $comments
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function buildCtripCommentAggregate(array $comments, array $payload, string $platformHotelId, string $fallbackDate): array
    {
        $payloadHotelId = (string)($this->firstCtripPayloadValue($payload, ['hotelId', 'hotel_id', 'masterHotelId', 'master_hotel_id']) ?? '');
        $hotelId = trim($platformHotelId !== '' ? $platformHotelId : $payloadHotelId);
        $hotelName = trim((string)($this->firstCtripPayloadValue($payload, ['hotelName', 'hotel_name', 'masterHotelName', 'storeName']) ?? ''));
        $date = $this->normalizeOnlineDataDate($this->firstCtripPayloadValue($payload, ['date', 'dataDate', 'statDate']) ?? '') ?: $fallbackDate;
        $channel = trim((string)($this->firstCtripPayloadValue($payload, ['channel', 'channelName', 'platform', 'source', 'commentChannel', 'bizType']) ?? ''));
        $commentCount = $this->firstCtripNumber($payload, ['commentCount', 'commentsCount', 'reviewCount', 'totalCommentCount', 'totalCount', 'allCount']);
        $badReviewCount = $this->firstCtripNumber($payload, ['badReviewCount', 'negativeCommentCount', 'negativeCount', 'badCount', 'lowScoreCount', 'noRecommendCount']);
        $payloadScoreValue = $this->firstCtripNumber($payload, ['score', 'commentScore', 'rating', 'rate', 'totalScore', 'overallScore', 'star', 'ratingall', 'HotelRating', 'ctripRatingall']);
        $payloadScore = $payloadScoreValue !== null ? $this->normalizeCtripCommentScoreValue($payloadScoreValue) : null;
        $scores = [];
        $badByScore = 0;
        $rowCount = 0;

        foreach ($comments as $comment) {
            if (!is_array($comment)) {
                continue;
            }
            $rowCount++;
            if ($hotelId === '') {
                $hotelId = trim((string)($comment['hotelId'] ?? $comment['hotel_id'] ?? $comment['masterHotelId'] ?? ''));
            }
            if ($hotelName === '') {
                $hotelName = trim((string)($comment['hotelName'] ?? $comment['hotel_name'] ?? $comment['masterHotelName'] ?? $comment['storeName'] ?? ''));
            }
            if ($date === '') {
                $date = $this->extractCtripCommentDate($comment, $fallbackDate);
            }
            if ($channel === '') {
                $channel = trim((string)($comment['channel'] ?? $comment['channelName'] ?? $comment['platform'] ?? $comment['source'] ?? $comment['commentChannel'] ?? $comment['bizType'] ?? ''));
            }

            $score = $this->extractCtripCommentScore($comment);
            if ($score > 0) {
                $scores[] = $score;
                if ($score < 4) {
                    $badByScore++;
                }
            }

            $rowBadCount = $this->firstCtripNumber($comment, ['badReviewCount', 'negativeCommentCount', 'negativeCount', 'badCount', 'lowScoreCount', 'noRecommendCount']);
            if ($rowBadCount !== null) {
                $badReviewCount = $badReviewCount === null ? $rowBadCount : max($badReviewCount, $rowBadCount);
            }
        }

        $score = $payloadScore;
        if ($score === null && !empty($scores)) {
            $score = round(array_sum($scores) / count($scores), 1);
        }
        if ($commentCount === null) {
            $commentCount = $rowCount;
        }
        if ($badReviewCount === null) {
            $badReviewCount = $badByScore;
        }

        return [
            'hotel_id' => $hotelId,
            'hotel_name' => $hotelName,
            'data_date' => $date ?: $fallbackDate,
            'comment_channel' => $channel,
            'comment_score' => $score,
            'comment_count' => (int)$commentCount,
            'bad_review_count' => (int)$badReviewCount,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $keys
     */
    private function firstCtripPayloadValue(array $payload, array $keys)
    {
        $sources = [$payload];
        foreach (['data', 'result'] as $container) {
            if (isset($payload[$container]) && is_array($payload[$container])) {
                array_unshift($sources, $payload[$container]);
            }
        }

        foreach ($sources as $source) {
            foreach ($keys as $key) {
                if (isset($source[$key]) && $source[$key] !== '') {
                    return $source[$key];
                }
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, string> $keys
     */
    private function firstCtripNumber(array $source, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source) || $source[$key] === '') {
                continue;
            }
            $value = $source[$key];
            if (is_string($value)) {
                $value = str_replace([',', '%'], '', trim($value));
            }
            if (is_numeric($value)) {
                return (float)$value;
            }
        }
        foreach (['data', 'result'] as $container) {
            if (isset($source[$container]) && is_array($source[$container])) {
                $value = $this->firstCtripNumber($source[$container], $keys);
                if ($value !== null) {
                    return $value;
                }
            }
        }
        return null;
    }
    private function normalizeCtripCommentScoreValue(float $value): float
    {
        if ($value > 5 && $value <= 50) {
            return round($value / 10, 1);
        }
        if ($value > 50 && $value <= 100) {
            return round($value / 20, 1);
        }
        return round($value, 1);
    }
    private function extractCtripCommentId(array $comment): string
    {
        foreach (['commentId', 'comment_id', 'id', 'reviewId', 'review_id', 'orderId'] as $field) {
            if (!empty($comment[$field])) {
                return (string)$comment[$field];
            }
        }
        return '';
    }
    private function extractCtripCommentDate(array $comment, string $fallbackDate): string
    {
        foreach (['commentTime', 'comment_time', 'reviewTime', 'review_time', 'createTime', 'create_time', 'submitTime', 'submit_time', 'checkOutDate', 'date'] as $field) {
            if (!isset($comment[$field]) || $comment[$field] === '') {
                continue;
            }
            $date = $this->normalizeOnlineDataDate($comment[$field]);
            if ($date !== '') {
                return $date;
            }
        }
        return $fallbackDate;
    }
    private function normalizeOnlineDataDate($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $rawText = trim((string)$value);
        if (preg_match('/^(19|20)\d{6}$/', $rawText)) {
            $year = (int)substr($rawText, 0, 4);
            $month = (int)substr($rawText, 4, 2);
            $day = (int)substr($rawText, 6, 2);
            if (checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }
        if (is_numeric($value)) {
            $timestamp = (int)$value;
            if ($timestamp > 9999999999) {
                $timestamp = (int)floor($timestamp / 1000);
            }
            return date('Y-m-d', $timestamp);
        }
        $text = $rawText;
        if (preg_match('/(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/', $text, $matches)) {
            return sprintf('%04d-%02d-%02d', (int)$matches[1], (int)$matches[2], (int)$matches[3]);
        }
        $timestamp = strtotime($text);
        return $timestamp === false ? '' : date('Y-m-d', $timestamp);
    }
    private function extractCtripCommentScore(array $comment): float
    {
        foreach (['score', 'rating', 'rate', 'totalScore', 'overallScore', 'commentScore', 'star'] as $field) {
            if (isset($comment[$field]) && is_numeric($comment[$field])) {
                $score = (float)$comment[$field];
                if ($score > 5 && $score <= 50) {
                    return round($score / 10, 1);
                }
                if ($score > 50 && $score <= 100) {
                    return round($score / 20, 1);
                }
                return round($score, 1);
            }
        }
        return 0.0;
    }

    /**
     * 保存携程点评配置
     */
    public function saveCtripCommentConfig(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        return $this->commentCollectionDisabledResponse();
    }

    /**
     * 获取携程点评配置列表
     */
    public function getCtripCommentConfigList(): Response
    {
        $this->checkPermission();

        return $this->success([]);
    }

}
