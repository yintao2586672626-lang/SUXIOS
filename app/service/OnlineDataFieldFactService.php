<?php
declare(strict_types=1);

namespace app\service;

final class OnlineDataFieldFactService
{
    private const DEFINITIONS = [
        'peer_rank' => [
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
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public static function buildStatus(array $row, array $raw): array
    {
        $facts = self::extractFieldFacts($raw);
        if ($facts === []) {
            return [
                'status' => 'not_loaded',
                'label' => '字段事实未写入',
                'detail' => '该行未返回 raw_data.field_facts，需从采集证据、source_path、metric_key、入库字段复核。',
                'captured_count' => 0,
                'missing_count' => 0,
                'capture_evidence_count' => 0,
                'desensitized_capture_evidence_count' => 0,
                'source_path_count' => 0,
                'structured_source_path_count' => 0,
                'metric_key_count' => 0,
                'storage_field_count' => 0,
                'inferred_storage_field_count' => 0,
                'stored_value_present_count' => 0,
                'stored_value_missing_count' => 0,
                'captured_metric_keys' => [],
                'missing_metric_keys' => [],
                'sample_facts' => [],
                'raw_data_exposed' => false,
            ];
        }

        $captured = [];
        $missing = [];
        $captureEvidenceCount = 0;
        $desensitizedCaptureEvidenceCount = 0;
        $sourcePathCount = 0;
        $structuredSourcePathCount = 0;
        $metricKeyCount = 0;
        $storageFieldCount = 0;
        $inferredStorageFieldCount = 0;
        $storedValuePresentCount = 0;
        $storedValueMissingCount = 0;
        $sampleFacts = [];

        foreach ($facts as $fact) {
            if (!is_array($fact)) {
                continue;
            }
            $metricKey = trim((string)($fact['metric_key'] ?? $fact['field_key'] ?? $fact['field'] ?? ''));
            $sourcePath = trim((string)($fact['source_path'] ?? ''));
            $sourcePathStructured = self::fieldFactSourcePathStructured($sourcePath);
            $storageField = trim((string)($fact['storage_field'] ?? $fact['storage_target'] ?? ''));
            $storageFieldSource = trim((string)($fact['storage_field_source'] ?? ''));
            $storageFieldInferred = false;
            $hasCaptureEvidence = self::fieldFactHasCaptureEvidence($fact, $row, $raw);
            $hasDesensitizedCaptureEvidence = self::fieldFactHasDesensitizedCaptureEvidence($fact);
            if ($storageField === '') {
                $storageField = self::inferFieldFactStorageField($metricKey, $row, $raw, $fact);
                $storageFieldInferred = $storageField !== '';
                $storageFieldSource = $storageFieldInferred ? self::fieldFactStorageFieldSource($storageField) : $storageFieldSource;
            }
            $status = trim((string)($fact['status'] ?? ''));
            $storedValueState = self::fieldFactStoredValueState($fact, $row, $raw, $storageField, $metricKey);
            $storedValueMissing = $storedValueState === false;
            if ($storedValueState === true) {
                $storedValuePresentCount++;
            } elseif ($storedValueMissing) {
                $storedValueMissingCount++;
            }
            $isMissing = $status === 'missing'
                || ($metricKey !== '' && (!$hasCaptureEvidence || !$sourcePathStructured || $storageField === ''))
                || $storedValueMissing;

            if ($metricKey !== '') {
                $metricKeyCount++;
            }
            if ($hasCaptureEvidence) {
                $captureEvidenceCount++;
            }
            if ($hasDesensitizedCaptureEvidence) {
                $desensitizedCaptureEvidenceCount++;
            }
            if ($sourcePath !== '') {
                $sourcePathCount++;
            }
            if ($sourcePathStructured) {
                $structuredSourcePathCount++;
            }
            if ($storageField !== '') {
                $storageFieldCount++;
            }
            if ($storageFieldInferred) {
                $inferredStorageFieldCount++;
            }
            if ($isMissing) {
                $missing[] = $metricKey !== '' ? $metricKey : 'unknown_metric';
            } else {
                $captured[] = $metricKey !== '' ? $metricKey : 'unknown_metric';
            }
            if (count($sampleFacts) < 4) {
                $sampleFacts[] = [
                    'metric_key' => $metricKey,
                    'source_path' => $sourcePath,
                    'source_path_structured' => $sourcePathStructured,
                    'storage_field' => $storageField,
                    'storage_field_inferred' => $storageFieldInferred,
                    'storage_field_source' => $storageFieldSource,
                    'capture_evidence_present' => $hasCaptureEvidence,
                    'desensitized_capture_evidence_present' => $hasDesensitizedCaptureEvidence,
                    'stored_value_present' => $storedValueState,
                    'status' => $isMissing ? 'missing' : ($status !== '' ? $status : 'captured'),
                    'missing_state' => trim((string)($fact['missing_state'] ?? '')),
                ];
            }
        }

        $captured = array_values(array_unique(array_filter($captured, static fn(string $value): bool => $value !== '')));
        $missing = array_values(array_unique(array_filter($missing, static fn(string $value): bool => $value !== '')));
        $total = count($facts);
        $capturedCount = count($captured);
        $missingCount = count($missing);
        $status = 'ready';
        $label = '字段闭环';
        if ($capturedCount === 0) {
            $status = 'missing';
            $label = '字段缺失';
        } elseif ($missingCount > 0 || $captureEvidenceCount < $capturedCount || $structuredSourcePathCount < $capturedCount || $storageFieldCount < $capturedCount || $storedValueMissingCount > 0) {
            $status = 'partial';
            $label = '字段待复核';
        }

        $detailParts = [
            sprintf(
                'metric_key %d/%d',
                $metricKeyCount,
                $total
            ),
            '采集证据 ' . $captureEvidenceCount,
            'desensitized_capture_evidence ' . $desensitizedCaptureEvidenceCount,
            'source_path ' . $sourcePathCount,
            'structured_source_path ' . $structuredSourcePathCount,
            '入库字段 ' . $storageFieldCount,
        ];
        if ($storedValuePresentCount > 0 || $storedValueMissingCount > 0) {
            $detailParts[] = '入库值 ' . $storedValuePresentCount;
        }
        $detailParts[] = '缺失 ' . $missingCount;

        return [
            'status' => $status,
            'label' => $label,
            'detail' => implode('，', $detailParts),
            'captured_count' => $capturedCount,
            'missing_count' => $missingCount,
            'capture_evidence_count' => $captureEvidenceCount,
            'desensitized_capture_evidence_count' => $desensitizedCaptureEvidenceCount,
            'source_path_count' => $sourcePathCount,
            'structured_source_path_count' => $structuredSourcePathCount,
            'metric_key_count' => $metricKeyCount,
            'storage_field_count' => $storageFieldCount,
            'inferred_storage_field_count' => $inferredStorageFieldCount,
            'stored_value_present_count' => $storedValuePresentCount,
            'stored_value_missing_count' => $storedValueMissingCount,
            'captured_metric_keys' => array_slice($captured, 0, 12),
            'missing_metric_keys' => array_slice($missing, 0, 12),
            'sample_facts' => $sampleFacts,
            'raw_data_exposed' => false,
        ];
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
            'source_url_hash' => ['source_url_hash', '_source_url_hash', 'url_hash', '_url_hash'],
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

    private static function fieldFactHasDesensitizedCaptureEvidence(array $fact): bool
    {
        $evidence = $fact['capture_evidence'] ?? null;
        return is_array($evidence) && self::hasDesensitizedCaptureEvidence($evidence);
    }

    /**
     * @param array<string, mixed> $fact
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     */
    private static function fieldFactHasCaptureEvidence(array $fact, array $row, array $raw): bool
    {
        $evidence = $fact['capture_evidence'] ?? null;
        if (is_array($evidence) && $evidence !== []) {
            return true;
        }
        if (is_scalar($evidence) && trim((string)$evidence) !== '') {
            return true;
        }
        foreach (['source_trace_id', 'data_source_id', 'sync_task_id'] as $key) {
            $value = $row[$key] ?? $raw[$key] ?? null;
            if (is_scalar($value) && trim((string)$value) !== '') {
                return true;
            }
        }
        foreach (['_source_path', 'source_path', 'json_path', '_capture_source'] as $key) {
            $value = $raw[$key] ?? null;
            if (is_scalar($value) && trim((string)$value) !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<int, array<string, mixed>>
     */
    private static function extractFieldFacts(array $raw): array
    {
        foreach ([
            $raw['field_facts'] ?? null,
            $raw['row']['field_facts'] ?? null,
            $raw['raw_data']['field_facts'] ?? null,
            $raw['row']['raw_data']['field_facts'] ?? null,
            $raw['facts'] ?? null,
            $raw['row']['facts'] ?? null,
            $raw['raw_data']['facts'] ?? null,
            $raw['row']['raw_data']['facts'] ?? null,
        ] as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $facts = array_values(array_filter($candidate, static fn($item): bool => is_array($item)));
            if ($facts !== []) {
                return $facts;
            }
        }
        return [];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $fact
     */
    private static function inferFieldFactStorageField(string $metricKey, array $row, array $raw, array $fact): string
    {
        $metricKey = strtolower(trim($metricKey));
        if ($metricKey === '') {
            return '';
        }

        $structuredField = self::fieldFactStructuredStorageField($metricKey);
        if ($structuredField !== '') {
            return 'online_daily_data.' . $structuredField;
        }

        foreach ([
            'metrics' => 'online_daily_data.raw_data.metrics.',
            'rank_metrics' => 'online_daily_data.raw_data.rank_metrics.',
        ] as $rawKey => $prefix) {
            if (is_array($raw[$rawKey] ?? null) && array_key_exists($metricKey, $raw[$rawKey])) {
                return $prefix . $metricKey;
            }
        }

        if (array_key_exists('value', $fact) || trim((string)($fact['source_path'] ?? '')) !== '') {
            return 'online_daily_data.raw_data.facts.metric_key=' . $metricKey;
        }

        return '';
    }

    private static function fieldFactStorageFieldSource(string $storageField): string
    {
        if (str_starts_with($storageField, 'online_daily_data.raw_data.metrics.')) {
            return 'raw_data_metrics';
        }
        if (str_starts_with($storageField, 'online_daily_data.raw_data.rank_metrics.')) {
            return 'raw_data_rank_metrics';
        }
        if (str_starts_with($storageField, 'online_daily_data.raw_data.facts.metric_key=')) {
            return 'raw_data_facts';
        }
        if (str_starts_with($storageField, 'online_daily_data.')) {
            return 'metric_key_map';
        }
        return 'inferred';
    }

    /**
     * @param array<string, mixed> $fact
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     */
    private static function fieldFactStoredValueState(array $fact, array $row, array $raw, string $storageField, string $metricKey): ?bool
    {
        $explicit = self::fieldFactBoolState($fact['stored_value_present'] ?? null);
        if ($explicit !== null) {
            return $explicit;
        }

        $storageField = trim($storageField);
        if ($storageField === '') {
            return null;
        }

        $factsPrefix = 'online_daily_data.raw_data.facts.metric_key=';
        if (str_starts_with($storageField, $factsPrefix)) {
            $targetMetric = strtolower(trim(substr($storageField, strlen($factsPrefix))));
            if (self::valuePresent($fact['value'] ?? null)) {
                return true;
            }
            foreach (self::extractFieldFacts($raw) as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }
                $candidateMetric = strtolower(trim((string)($candidate['metric_key'] ?? $candidate['field_key'] ?? $candidate['field'] ?? '')));
                if ($candidateMetric === $targetMetric && self::valuePresent($candidate['value'] ?? null)) {
                    return true;
                }
            }
            return null;
        }

        $rawPrefix = 'online_daily_data.raw_data.';
        if (str_starts_with($storageField, $rawPrefix)) {
            return self::valuePresent(self::readPath($raw, substr($storageField, strlen($rawPrefix))));
        }

        $rowPrefix = 'online_daily_data.';
        if (str_starts_with($storageField, $rowPrefix)) {
            $field = substr($storageField, strlen($rowPrefix));
            return array_key_exists($field, $row) ? self::valuePresent($row[$field]) : null;
        }

        return null;
    }

    private static function fieldFactBoolState(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no'], true)) {
                return false;
            }
        }
        return null;
    }

    private static function fieldFactSourcePathStructured(string $sourcePath): bool
    {
        $sourcePath = trim($sourcePath);
        return $sourcePath !== ''
            && (str_contains($sourcePath, '.') || str_contains($sourcePath, '[') || str_contains($sourcePath, '/'));
    }

    private static function fieldFactStructuredStorageField(string $metricKey): string
    {
        $map = [
            'order_amount' => 'amount',
            'business_amount' => 'amount',
            'loss_order_amount' => 'amount',
            'ad_cost' => 'amount',
            'room_nights' => 'quantity',
            'business_room_nights' => 'quantity',
            'loss_room_nights' => 'quantity',
            'ad_room_nights' => 'quantity',
            'occupied_rooms' => 'quantity',
            'order_count' => 'book_order_num',
            'loss_order_count' => 'book_order_num',
            'ad_orders' => 'book_order_num',
            'visitor_count' => 'detail_exposure',
            'detail_visitor' => 'detail_exposure',
            'competitor_detail_visitor' => 'detail_exposure',
            'qunar_detail_visitor' => 'detail_exposure',
            'qunar_competitor_detail_visitor' => 'detail_exposure',
            'list_exposure' => 'list_exposure',
            'competitor_list_exposure' => 'list_exposure',
            'qunar_list_exposure' => 'list_exposure',
            'qunar_competitor_list_exposure' => 'list_exposure',
            'ad_impressions' => 'list_exposure',
            'order_page_visitor' => 'order_filling_num',
            'competitor_order_page_visitor' => 'order_filling_num',
            'qunar_order_page_visitor' => 'order_filling_num',
            'qunar_competitor_order_page_visitor' => 'order_filling_num',
            'order_submit_user' => 'order_submit_num',
            'competitor_order_submit_user' => 'order_submit_num',
            'qunar_order_submit_user' => 'order_submit_num',
            'qunar_competitor_order_submit_user' => 'order_submit_num',
            'flow_rate' => 'flow_rate',
            'competitor_flow_rate' => 'flow_rate',
            'qunar_flow_rate' => 'flow_rate',
            'qunar_competitor_flow_rate' => 'flow_rate',
            'conversion_rate' => 'flow_rate',
            'order_conversion_rate' => 'flow_rate',
            'common_view_rate' => 'flow_rate',
            'ctr' => 'flow_rate',
            'cvr' => 'flow_rate',
            'reply_rate' => 'flow_rate',
            'five_min_reply_rate' => 'flow_rate',
            'manual_reply_rate' => 'flow_rate',
            'im_order_conversion_rate' => 'flow_rate',
            'agreement_accept_rate' => 'flow_rate',
            'business_commission_rate' => 'flow_rate',
            'comment_response_rate' => 'flow_rate',
            'comment_score_summary' => 'comment_score',
            'comment_score' => 'comment_score',
            'ctrip_rating' => 'comment_score',
            'qunar_rating' => 'qunar_comment_score',
            'avg_price' => 'data_value',
            'close_rate' => 'data_value',
            'occupancy_rate' => 'data_value',
            'tensity' => 'data_value',
            'comment_count' => 'data_value',
            'bad_review_count' => 'data_value',
            'comment_unreply_count' => 'data_value',
            'ctrip_comment_count' => 'data_value',
            'qunar_comment_count' => 'data_value',
            'elong_comment_count' => 'data_value',
            'zx_comment_count' => 'data_value',
            'avg_user_age' => 'data_value',
            'avg_booking_days' => 'data_value',
            'avg_stay_days' => 'data_value',
            'ad_order_amount' => 'data_value',
        ];

        return $map[$metricKey] ?? '';
    }

    /**
     * @param array<int, array<string, mixed>> $facts
     * @return array<string, mixed>
     */
    private static function summarizeFacts(array $facts): array
    {
        $metricKeys = [];
        $captureEvidenceCount = 0;
        $desensitizedCaptureEvidenceCount = 0;
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
            if (is_array($captureEvidence) && self::hasDesensitizedCaptureEvidence($captureEvidence)) {
                $desensitizedCaptureEvidenceCount++;
            }
        }

        return [
            'captured_count' => count($metricKeys),
            'missing_count' => 0,
            'capture_evidence_count' => $captureEvidenceCount,
            'desensitized_capture_evidence_count' => $desensitizedCaptureEvidenceCount,
            'captured_metric_keys' => array_values(array_unique($metricKeys)),
            'missing_metric_keys' => [],
        ];
    }

    /**
     * @param array<string, mixed> $captureEvidence
     */
    private static function hasDesensitizedCaptureEvidence(array $captureEvidence): bool
    {
        $traceId = trim((string)($captureEvidence['source_trace_id'] ?? $captureEvidence['_source_trace_id'] ?? ''));
        $sourceUrlHash = trim((string)($captureEvidence['source_url_hash'] ?? $captureEvidence['_source_url_hash'] ?? $captureEvidence['url_hash'] ?? $captureEvidence['_url_hash'] ?? ''));

        return $traceId !== '' && $sourceUrlHash !== '';
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
