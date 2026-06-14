<?php
declare(strict_types=1);

namespace app\service;

final class OnlineDataFieldFactService
{
    private const DEFINITIONS = [
        'business' => [
            [
                'metric_key' => 'meituan_rank_value',
                'normalized_field' => 'data_value',
                'storage_field' => 'data_value',
                'source_keys' => ['dataValue', 'data_value', 'percent', 'rankPercent', 'rank_percent'],
            ],
            [
                'metric_key' => 'meituan_rank_position',
                'normalized_field' => 'raw_data.rank',
                'storage_field' => 'raw_data.rank',
                'source_keys' => ['rank', 'ranking'],
            ],
            [
                'metric_key' => 'meituan_rank_type',
                'normalized_field' => 'raw_data.rankType',
                'storage_field' => 'raw_data.rankType',
                'source_keys' => ['rankType', 'rank_type'],
            ],
            [
                'metric_key' => 'meituan_rank_dimension',
                'normalized_field' => 'dimension',
                'storage_field' => 'dimension',
                'source_keys' => ['_dimName', 'dimName', 'dimension'],
            ],
        ],
        'traffic' => [
            [
                'metric_key' => 'list_exposure',
                'normalized_field' => 'list_exposure',
                'storage_field' => 'list_exposure',
                'source_keys' => ['list_exposure', 'listExposure', 'exposure_count', 'exposureCount', 'impression', 'impressions', 'exposure'],
            ],
            [
                'metric_key' => 'detail_exposure',
                'normalized_field' => 'detail_exposure',
                'storage_field' => 'detail_exposure',
                'source_keys' => ['detail_exposure', 'detailExposure', 'page_views', 'pageViews', 'unique_visitors', 'uniqueVisitors', 'visitor_count', 'visitorCount', 'click_count', 'clickCount', 'clicks', 'click', 'uv', 'UV', 'pv', 'views'],
            ],
            [
                'metric_key' => 'flow_rate',
                'normalized_field' => 'flow_rate',
                'storage_field' => 'flow_rate',
                'source_keys' => ['flow_rate', 'flowRate', 'conversion_rate', 'conversionRate', 'convertionRate', 'convertRate', 'transforRate', 'transferRate', 'transRate', 'cvr', 'listTransforDetailRate', 'orderRate'],
            ],
            [
                'metric_key' => 'order_filling_num',
                'normalized_field' => 'order_filling_num',
                'storage_field' => 'order_filling_num',
                'source_keys' => ['order_filling_num', 'orderFillingNum', 'orderVisitors', 'click_count', 'clickCount', 'clickNum', 'clicks', 'click'],
            ],
            [
                'metric_key' => 'order_submit_num',
                'normalized_field' => 'order_submit_num',
                'storage_field' => 'order_submit_num',
                'source_keys' => ['order_submit_num', 'orderSubmitNum', 'submit_users', 'submitUsers', 'submitNum', 'orderCount', 'order_count', 'orderNum', 'bookOrderNum', 'dealNum', 'orders'],
            ],
        ],
        'advertising' => [
            [
                'metric_key' => 'ad_exposure',
                'normalized_field' => 'list_exposure',
                'storage_field' => 'list_exposure',
                'source_keys' => ['exposure_count', 'exposureCount', 'impression', 'impressions', 'exposure'],
            ],
            [
                'metric_key' => 'ad_clicks',
                'normalized_field' => 'detail_exposure',
                'storage_field' => 'detail_exposure',
                'source_keys' => ['click_count', 'clickCount', 'clickNum', 'clicks', 'click'],
            ],
            [
                'metric_key' => 'ad_conversion_rate',
                'normalized_field' => 'flow_rate',
                'storage_field' => 'flow_rate',
                'source_keys' => ['conversion_rate', 'conversionRate', 'flowRate', 'orderRate'],
            ],
        ],
        'order' => [
            [
                'metric_key' => 'order_amount',
                'normalized_field' => 'amount',
                'storage_field' => 'amount',
                'source_keys' => ['total_amount', 'totalAmount', 'amount', 'payAmount', 'pay_amount'],
            ],
            [
                'metric_key' => 'room_nights',
                'normalized_field' => 'quantity',
                'storage_field' => 'quantity',
                'source_keys' => ['nights', 'night_count', 'nightCount', 'room_count', 'roomCount', 'rooms'],
            ],
            [
                'metric_key' => 'order_count',
                'normalized_field' => 'book_order_num',
                'storage_field' => 'book_order_num',
                'source_keys' => ['order_id', 'orderId', 'id', 'order_count', 'orderCount'],
            ],
            [
                'metric_key' => 'average_price',
                'normalized_field' => 'data_value',
                'storage_field' => 'data_value',
                'source_keys' => ['avg_price', 'avgPrice'],
            ],
        ],
        'review' => [
            [
                'metric_key' => 'comment_score',
                'normalized_field' => 'comment_score',
                'storage_field' => 'comment_score',
                'source_keys' => ['score', 'star', 'rating', 'totalScore'],
            ],
            [
                'metric_key' => 'review_count',
                'normalized_field' => 'quantity',
                'storage_field' => 'quantity',
                'source_keys' => ['review_id', 'reviewId', 'comment_id', 'commentId', 'id'],
            ],
        ],
    ];

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $sourceRow
     * @return array<string, mixed>
     */
    public static function attachToOnlineDailyRow(array $row, array $sourceRow = []): array
    {
        $dataType = self::normalizeDataType((string)($row['data_type'] ?? ''));
        $raw = self::decodeRawData($row['raw_data'] ?? null);
        $source = $sourceRow !== [] ? $sourceRow : $raw;
        $facts = self::buildFacts($source, $row, $raw, $dataType);
        if ($facts === []) {
            return $row;
        }

        $raw['field_facts'] = $facts;
        $raw['field_fact_summary'] = self::summarizeFacts($facts);
        $row['raw_data'] = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        return $row;
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     * @return array<int, array<string, mixed>>
     */
    private static function buildFacts(array $source, array $row, array $raw, string $dataType): array
    {
        $definitions = self::DEFINITIONS[$dataType] ?? [];
        if ($definitions === []) {
            return [];
        }

        $facts = [];
        foreach ($definitions as $definition) {
            $sourceKey = self::firstPresentKey($source, (array)($definition['source_keys'] ?? []));
            if ($sourceKey === '' && $raw !== $source) {
                $sourceKey = self::firstPresentKey($raw, (array)($definition['source_keys'] ?? []));
            }
            if ($sourceKey === '') {
                continue;
            }

            $normalizedField = (string)($definition['normalized_field'] ?? '');
            $facts[] = [
                'metric_key' => (string)$definition['metric_key'],
                'data_type' => $dataType,
                'source_key' => $sourceKey,
                'source_path' => self::sourcePath($source, $raw, $sourceKey),
                'storage_table' => 'online_daily_data',
                'storage_field' => 'online_daily_data.' . (string)$definition['storage_field'],
                'normalized_field' => $normalizedField,
                'status' => 'captured',
                'missing_state' => '',
                'stored_value_present' => self::storedValuePresent($row, $raw, $normalizedField),
                'capture_evidence' => self::captureEvidence($source, $raw),
            ];
        }

        return $facts;
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $raw
     */
    private static function sourcePath(array $source, array $raw, string $sourceKey): string
    {
        $basePath = trim((string)($source['_source_path'] ?? $source['source_path'] ?? $source['json_path'] ?? ''));
        if ($basePath === '') {
            $basePath = trim((string)($raw['_source_path'] ?? $raw['source_path'] ?? $raw['json_path'] ?? ''));
        }
        if ($basePath === '') {
            $basePath = trim((string)($source['_capture_source'] ?? $raw['_capture_source'] ?? ''));
        }

        $sourceKey = trim($sourceKey);
        if ($basePath === '') {
            return $sourceKey;
        }
        if ($sourceKey === '') {
            return $basePath;
        }
        return rtrim($basePath, '.') . '.' . $sourceKey;
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private static function captureEvidence(array $source, array $raw): array
    {
        $evidence = [];
        foreach (['_source_path', 'source_path', 'json_path', '_capture_source'] as $key) {
            $value = $source[$key] ?? $raw[$key] ?? null;
            if (is_scalar($value) && trim((string)$value) !== '') {
                $evidence[ltrim($key, '_')] = mb_substr((string)$value, 0, 300);
            }
        }
        foreach ([$source['capture_evidence'] ?? null, $raw['capture_evidence'] ?? null] as $nestedEvidence) {
            if (is_array($nestedEvidence)) {
                self::appendSafeCaptureEvidence($evidence, $nestedEvidence);
            }
        }
        self::appendSafeCaptureEvidence($evidence, $source);
        if ($raw !== $source) {
            self::appendSafeCaptureEvidence($evidence, $raw);
        }
        foreach (['_source_url', 'source_url', 'url'] as $key) {
            $value = $source[$key] ?? $raw[$key] ?? null;
            if (is_scalar($value) && trim((string)$value) !== '') {
                $evidence['source_url_hash'] = hash('sha256', (string)$value);
                break;
            }
        }
        return $evidence;
    }

    /**
     * @param array<string, mixed> $evidence
     * @param array<string, mixed> $source
     */
    private static function appendSafeCaptureEvidence(array &$evidence, array $source): void
    {
        $aliases = [
            'source_trace_id' => ['source_trace_id', '_source_trace_id', 'trace_id', '_trace_id'],
            'source_url_hash' => ['source_url_hash', '_source_url_hash'],
            'request_hash' => ['request_hash', '_request_hash'],
            'payload_hash' => ['payload_hash', '_payload_hash'],
            'method' => ['method', 'http_method', '_method'],
            'source_path' => ['source_path', '_source_path', 'json_path'],
        ];
        foreach ($aliases as $target => $keys) {
            if (isset($evidence[$target]) && self::safeCaptureEvidenceValue($evidence[$target]) !== '') {
                continue;
            }
            foreach ($keys as $key) {
                $value = self::safeCaptureEvidenceValue($source[$key] ?? null);
                if ($value !== '') {
                    $evidence[$target] = $value;
                    break;
                }
            }
        }
    }

    private static function safeCaptureEvidenceValue(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $text = trim((string)$value);
        if ($text === ''
            || preg_match('/\b(cookie|authorization|bearer|token|password|secret)\b/i', $text)
        ) {
            return '';
        }
        return mb_substr($text, 0, 300);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     */
    private static function storedValuePresent(array $row, array $raw, string $field): bool
    {
        if ($field === '') {
            return false;
        }
        if (str_starts_with($field, 'raw_data.')) {
            return self::valuePresent(self::readPath($raw, substr($field, 9)));
        }
        return array_key_exists($field, $row) && self::valuePresent($row[$field]);
    }

    private static function valuePresent(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_string($value) && trim($value) === '') {
            return false;
        }
        if (is_array($value) && $value === []) {
            return false;
        }
        return true;
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, mixed> $keys
     */
    private static function firstPresentKey(array $source, array $keys): string
    {
        foreach ($keys as $key) {
            $key = (string)$key;
            if ($key === '' || !array_key_exists($key, $source)) {
                continue;
            }
            if (self::valuePresent($source[$key])) {
                return $key;
            }
        }
        return '';
    }

    /**
     * @param array<int, array<string, mixed>> $facts
     * @return array<string, mixed>
     */
    private static function summarizeFacts(array $facts): array
    {
        $metricKeys = [];
        $captureEvidenceCount = 0;
        foreach ($facts as $fact) {
            $metricKey = trim((string)($fact['metric_key'] ?? ''));
            if ($metricKey !== '') {
                $metricKeys[] = $metricKey;
            }
            $captureEvidence = $fact['capture_evidence'] ?? null;
            if ((is_array($captureEvidence) && $captureEvidence !== [])
                || (is_scalar($captureEvidence) && trim((string)$captureEvidence) !== '')
            ) {
                $captureEvidenceCount++;
            }
        }

        return [
            'captured_count' => count($metricKeys),
            'missing_count' => 0,
            'capture_evidence_count' => $captureEvidenceCount,
            'captured_metric_keys' => array_values(array_unique($metricKeys)),
            'missing_metric_keys' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeRawData(mixed $rawData): array
    {
        if (is_array($rawData)) {
            return $rawData;
        }
        if (!is_string($rawData) || trim($rawData) === '') {
            return [];
        }
        $decoded = json_decode($rawData, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function readPath(array $value, string $path): mixed
    {
        $current = $value;
        foreach (explode('.', $path) as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return null;
            }
            $current = $current[$part];
        }
        return $current;
    }

    private static function normalizeDataType(string $dataType): string
    {
        $value = strtolower(trim($dataType));
        if (in_array($value, ['ads', 'ad', 'advertisement'], true)) {
            return 'advertising';
        }
        if (in_array($value, ['orders', 'booking'], true)) {
            return 'order';
        }
        if (in_array($value, ['reviews', 'comment', 'comments'], true)) {
            return 'review';
        }
        return $value;
    }
}
