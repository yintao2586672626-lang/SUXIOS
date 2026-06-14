<?php
declare(strict_types=1);

use app\controller\OnlineData;
use app\service\CtripTrafficDisplayService;
use app\service\OnlineDailyDataPersistenceService;
use app\service\OnlineDataFieldFactService;
use app\service\OnlineTrafficDataExtractionService;
use think\App;
use think\facade\Db;

$root = dirname(__DIR__);
require $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

$options = ['format' => 'json'];

/**
 * @param array<int, string> $argv
 * @return array<string, mixed>
 */
function p0_import_parse_args(array $argv): array
{
    $options = [
        'platform' => '',
        'date' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d'),
        'system-hotel-id' => '',
        'payload' => '',
        'format' => 'json',
        'execute' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--execute') {
            $options['execute'] = true;
            continue;
        }
        if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
            continue;
        }
        [$key, $value] = explode('=', substr($arg, 2), 2);
        if (!array_key_exists($key, $options)) {
            continue;
        }
        $options[$key] = $key === 'execute'
            ? in_array(strtolower(trim($value)), ['1', 'true', 'yes'], true)
            : trim($value);
    }

    $platform = strtolower((string)$options['platform']);
    if (!in_array($platform, ['ctrip', 'meituan'], true)) {
        throw new InvalidArgumentException('Invalid --platform, expected ctrip or meituan.');
    }
    $options['platform'] = $platform;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$options['date'])) {
        throw new InvalidArgumentException('Invalid --date, expected YYYY-MM-DD.');
    }
    if (!is_numeric($options['system-hotel-id']) || (int)$options['system-hotel-id'] <= 0) {
        throw new InvalidArgumentException('Invalid --system-hotel-id, expected a positive integer.');
    }
    $options['system-hotel-id'] = (int)$options['system-hotel-id'];
    if ((string)$options['payload'] === '') {
        throw new InvalidArgumentException('Missing --payload=<json-file>.');
    }
    if (!in_array((string)$options['format'], ['json', 'markdown'], true)) {
        throw new InvalidArgumentException('Invalid --format, expected json or markdown.');
    }

    return $options;
}

/**
 * @return array<string, mixed>
 */
function p0_import_read_payload(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('payload_file_unreadable');
    }

    $raw = file_get_contents($path);
    if (!is_string($raw)) {
        throw new RuntimeException('payload_file_read_failed');
    }

    $decoded = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('payload_json_invalid: ' . json_last_error_msg());
    }

    return $decoded;
}

/**
 * @param string $segment
 */
function p0_import_is_raw_url_key(string $segment): bool
{
    $normalized = strtolower((string)preg_replace('/(?<!^)[A-Z]/', '_$0', $segment));
    $normalized = (string)preg_replace('/[^a-z0-9]+/', '_', $normalized);
    $normalized = ltrim($normalized, '_');
    return $normalized === 'url'
        || $normalized === 'endpoint'
        || $normalized === 'request_uri'
        || str_ends_with($normalized, '_url');
}

function p0_import_is_raw_url_value(string $value): bool
{
    return preg_match('#https?://#i', $value) === 1;
}

/**
 * @param mixed $value
 * @return array<int, array{path:string, reason:string}>
 */
function p0_import_sensitive_hits(mixed $value, string $path = ''): array
{
    if (!is_array($value)) {
        return [];
    }

    $hits = [];
    foreach ($value as $key => $item) {
        $segment = is_string($key) ? $key : (string)$key;
        $nextPath = $path === '' ? $segment : $path . '.' . $segment;
        if (preg_match('/(^|_)(cookie|token|spidertoken|authorization|password|secret)($|_)/i', $segment)
            || preg_match('/profile_(path|dir)/i', $segment)
            || preg_match('/raw_(cookie|token|profile)/i', $segment)
            || p0_import_is_raw_url_key($segment)
        ) {
            $hits[] = ['path' => $nextPath, 'reason' => 'sensitive_key_present'];
        }
        if (is_string($item)) {
            $trimmed = trim($item);
            if (preg_match('/\b(Bearer|Cookie|Authorization)\s*[:=]/i', $trimmed)
                || preg_match('/spidertoken|access[_-]?token|refresh[_-]?token/i', $trimmed)
                || p0_import_is_raw_url_value($trimmed)
            ) {
                $hits[] = ['path' => $nextPath, 'reason' => 'sensitive_value_pattern'];
            }
        } elseif (is_array($item)) {
            foreach (p0_import_sensitive_hits($item, $nextPath) as $hit) {
                $hits[] = $hit;
                if (count($hits) >= 10) {
                    return array_slice($hits, 0, 10);
                }
            }
        }
        if (count($hits) >= 10) {
            return array_slice($hits, 0, 10);
        }
    }

    return $hits;
}

function p0_import_safe_capture_evidence_value(mixed $value): string
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
 * @param array<string, mixed> $source
 * @return array<string, string>
 */
function p0_import_desensitized_capture_evidence(array $source): array
{
    $evidence = [];
    $aliases = [
        'source_trace_id' => ['source_trace_id', '_source_trace_id', 'trace_id', '_trace_id'],
        'source_url_hash' => ['source_url_hash', '_source_url_hash'],
        'request_hash' => ['request_hash', '_request_hash'],
        'payload_hash' => ['payload_hash', '_payload_hash'],
    ];
    foreach ($aliases as $target => $keys) {
        foreach ($keys as $key) {
            $value = p0_import_safe_capture_evidence_value($source[$key] ?? null);
            if ($value !== '') {
                $evidence[$target] = $value;
                break;
            }
        }
    }

    $nested = $source['capture_evidence'] ?? null;
    if (is_array($nested)) {
        foreach (p0_import_desensitized_capture_evidence($nested) as $key => $value) {
            if (!isset($evidence[$key])) {
                $evidence[$key] = $value;
            }
        }
    }

    return $evidence;
}

/**
 * @param array<string, mixed> $row
 * @param array<string, string> $payloadEvidence
 * @return array<string, mixed>
 */
function p0_import_with_payload_capture_evidence(array $row, array $payloadEvidence): array
{
    if ($payloadEvidence === []) {
        return $row;
    }
    $nested = is_array($row['capture_evidence'] ?? null) ? $row['capture_evidence'] : [];
    foreach ($payloadEvidence as $key => $value) {
        if (p0_import_safe_capture_evidence_value($row[$key] ?? null) === ''
            && p0_import_safe_capture_evidence_value($nested[$key] ?? null) === ''
        ) {
            $nested[$key] = $value;
        }
    }
    if ($nested !== []) {
        $row['capture_evidence'] = $nested;
    }
    return $row;
}

/**
 * @param array<string, mixed> $fact
 */
function p0_import_fact_has_desensitized_capture_evidence(array $fact): bool
{
    $captureEvidence = $fact['capture_evidence'] ?? null;
    return is_array($captureEvidence)
        && p0_import_desensitized_capture_evidence($captureEvidence) !== [];
}

/**
 * @return array<int, array<string, mixed>>
 */
function p0_import_extract_rows(array $payload, string $platform): array
{
    if ($platform === 'ctrip') {
        return OnlineTrafficDataExtractionService::extractCtripTrafficRows($payload);
    }

    $service = new OnlineDailyDataPersistenceService();
    $ref = new ReflectionClass($service);
    $method = $ref->getMethod('resolveGenericTrafficDataList');
    $method->setAccessible(true);
    $rows = $method->invoke($service, $payload);
    return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
}

/**
 * @param array<string, mixed> $row
 */
function p0_import_explicit_row_date(array $row): string
{
    $value = $row['date'] ?? $row['dataDate'] ?? $row['statDate'] ?? $row['stat_date'] ?? $row['data_date'] ?? $row['reportDate'] ?? $row['day'] ?? '';
    if (trim((string)$value) === '') {
        return '';
    }
    $timestamp = strtotime((string)$value);
    return $timestamp === false ? '' : date('Y-m-d', $timestamp);
}

/**
 * @param array<string, mixed> $row
 */
function p0_import_row_date(array $row, string $defaultDate): string
{
    $explicitDate = p0_import_explicit_row_date($row);
    return $explicitDate !== '' ? $explicitDate : $defaultDate;
}

/**
 * @param array<string, mixed> $row
 */
function p0_import_explicit_source_path(array $row): string
{
    foreach (['_source_path', 'source_path', 'json_path', '_capture_source'] as $key) {
        $value = trim((string)($row[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function p0_import_source_path_is_structured(string $sourcePath): bool
{
    $sourcePath = trim($sourcePath);
    return $sourcePath !== ''
        && (str_contains($sourcePath, '.') || str_contains($sourcePath, '[') || str_contains($sourcePath, '/'));
}

/**
 * @param array<string, mixed> $row
 * @return array{list_exposure:int,detail_exposure:int,flow_rate:float,order_filling_num:int,order_submit_num:int}
 */
function p0_import_traffic_metrics(array $row, string $platform): array
{
    if ($platform === 'ctrip') {
        $listExposure = (int)CtripTrafficDisplayService::readTrafficNumber($row, ['listExposure', 'list_exposure', 'exposure', 'exposureCount', 'impressions', 'showCount', 'PV', 'pv', 'pageView', 'pageViews', 'page_view'], 0.0);
        $detailExposure = (int)CtripTrafficDisplayService::readTrafficNumber($row, ['detailExposure', 'detail_exposure', 'detailVisitors', 'detailUv', 'visitorCount', 'UV', 'uv', 'uniqueVisitors', 'unique_visitors', 'views'], 0.0);
        $flowRate = round(CtripTrafficDisplayService::normalizeTrafficPercent(CtripTrafficDisplayService::readTrafficNumber($row, ['flowRate', 'flow_rate', 'conversionRate', 'conversion_rate', 'convertionRate', 'convertRate', 'transforRate', 'transferRate', 'transRate', 'cvr', 'listTransforDetailRate'], $listExposure > 0 ? $detailExposure / $listExposure * 100 : 0.0)), 2);
        $orderFillingNum = (int)CtripTrafficDisplayService::readTrafficNumber($row, ['orderFillingNum', 'order_filling_num', 'orderVisitors', 'clickCount', 'click_count', 'clickNum', 'clicks'], 0.0);
        $orderSubmitNum = (int)CtripTrafficDisplayService::readTrafficNumber($row, ['orderSubmitNum', 'order_submit_num', 'submitUsers', 'submitNum', 'orderCount', 'order_count', 'orderNum', 'bookOrderNum', 'dealNum', 'orders'], 0.0);
        return [
            'list_exposure' => $listExposure,
            'detail_exposure' => $detailExposure,
            'flow_rate' => $flowRate,
            'order_filling_num' => $orderFillingNum,
            'order_submit_num' => $orderSubmitNum,
        ];
    }

    $service = new OnlineDailyDataPersistenceService();
    $ref = new ReflectionClass($service);
    $method = $ref->getMethod('extractGenericTrafficMetrics');
    $method->setAccessible(true);
    $metrics = $method->invoke($service, $row);
    return is_array($metrics) ? [
        'list_exposure' => (int)($metrics['list_exposure'] ?? 0),
        'detail_exposure' => (int)($metrics['detail_exposure'] ?? 0),
        'flow_rate' => (float)($metrics['flow_rate'] ?? 0.0),
        'order_filling_num' => (int)($metrics['order_filling_num'] ?? 0),
        'order_submit_num' => (int)($metrics['order_submit_num'] ?? 0),
    ] : [
        'list_exposure' => 0,
        'detail_exposure' => 0,
        'flow_rate' => 0.0,
        'order_filling_num' => 0,
        'order_submit_num' => 0,
    ];
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function p0_import_preview_field_facts(array $row, array $metrics, string $platform, int $systemHotelId, string $date): array
{
    $requiredStorageFields = p0_import_required_traffic_storage_fields();
    $previewRow = [
        'source' => $platform,
        'data_type' => 'traffic',
        'data_date' => p0_import_row_date($row, $date) ?: $date,
        'hotel_id' => (string)($row['hotelId'] ?? $row['hotel_id'] ?? $row['HotelId'] ?? $row['hotelID'] ?? $row['poiId'] ?? $row['poi_id'] ?? $systemHotelId),
        'system_hotel_id' => $systemHotelId,
        'dimension' => (string)($row['metric'] ?? $row['metricName'] ?? $row['dimension'] ?? $row['_metric'] ?? 'traffic'),
        'list_exposure' => $metrics['list_exposure'],
        'detail_exposure' => $metrics['detail_exposure'],
        'flow_rate' => $metrics['flow_rate'],
        'order_filling_num' => $metrics['order_filling_num'],
        'order_submit_num' => $metrics['order_submit_num'],
        'raw_data' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
    ];
    $withFacts = OnlineDataFieldFactService::attachToOnlineDailyRow($previewRow, $row);
    $raw = json_decode((string)($withFacts['raw_data'] ?? '{}'), true);
    $facts = is_array($raw) ? (array)($raw['field_facts'] ?? []) : [];
    $required = array_keys($requiredStorageFields);
    $complete = [];
    $missing = [];
    foreach ($required as $metricKey) {
        $matched = false;
        foreach ($facts as $fact) {
            if (!is_array($fact) || (string)($fact['metric_key'] ?? '') !== $metricKey) {
                continue;
            }
            $matched = p0_import_source_path_is_structured((string)($fact['source_path'] ?? ''))
                && trim((string)($fact['storage_field'] ?? '')) === $requiredStorageFields[$metricKey]
                && ($fact['stored_value_present'] ?? null) === true
                && p0_import_fact_has_desensitized_capture_evidence($fact);
            break;
        }
        if ($matched) {
            $complete[] = $metricKey;
        } else {
            $missing[] = $metricKey;
        }
    }

    $uiStatus = p0_import_external_ui_status(p0_import_preview_ui_status($withFacts, $raw));

    return [
        'status' => $missing === [] ? 'ready' : 'incomplete',
        'complete_metric_keys' => $complete,
        'missing_metric_keys' => $missing,
        'fact_count' => count($facts),
        'desensitized_capture_evidence_present' => $missing === [] && $facts !== [],
        'ui_status' => $uiStatus,
        'field_facts' => p0_import_external_field_facts($facts),
    ];
}

/**
 * @param array<int, array<string, mixed>> $facts
 * @return array<int, array<string, mixed>>
 */
function p0_import_external_field_facts(array $facts): array
{
    $result = [];
    foreach ($facts as $fact) {
        if (!is_array($fact)) {
            continue;
        }
        $metricKey = trim((string)($fact['metric_key'] ?? ''));
        $sourcePath = trim((string)($fact['source_path'] ?? ''));
        $storageField = trim((string)($fact['storage_field'] ?? ''));
        if ($metricKey === '' || $sourcePath === '' || $storageField === '') {
            continue;
        }
        $result[] = [
            'metric_key' => $metricKey,
            'source_path' => $sourcePath,
            'storage_field' => $storageField,
            'stored_value_present' => $fact['stored_value_present'] ?? null,
            'capture_evidence' => p0_import_desensitized_capture_evidence((array)($fact['capture_evidence'] ?? [])),
        ];
    }

    return $result;
}

/**
 * @param array<string, mixed> $row
 * @param array<string, mixed> $raw
 * @return array<string, mixed>
 */
function p0_import_preview_ui_status(array $row, array $raw): array
{
    static $controller = null;
    static $method = null;

    if ($method === null) {
        $ref = new ReflectionClass(OnlineData::class);
        $controller = $ref->newInstanceWithoutConstructor();
        $method = $ref->getMethod('buildOnlineDataFieldFactStatus');
        $method->setAccessible(true);
    }

    $result = $method->invoke($controller, $row, $raw);
    return is_array($result) ? $result : [];
}

/**
 * @param array<string, mixed> $status
 * @return array<string, mixed>
 */
function p0_import_external_ui_status(array $status): array
{
    return [
        'field_fact_status' => (string)($status['field_fact_status'] ?? $status['status'] ?? ''),
        'label' => (string)($status['label'] ?? ''),
        'detail' => (string)($status['detail'] ?? ''),
        'captured_count' => (int)($status['captured_count'] ?? 0),
        'missing_count' => (int)($status['missing_count'] ?? 0),
        'capture_evidence_count' => (int)($status['capture_evidence_count'] ?? 0),
        'source_path_count' => (int)($status['source_path_count'] ?? 0),
        'structured_source_path_count' => (int)($status['structured_source_path_count'] ?? 0),
        'metric_key_count' => (int)($status['metric_key_count'] ?? 0),
        'storage_field_count' => (int)($status['storage_field_count'] ?? 0),
        'stored_value_present_count' => (int)($status['stored_value_present_count'] ?? 0),
        'stored_value_missing_count' => (int)($status['stored_value_missing_count'] ?? 0),
        'raw_data_exposed' => (bool)($status['raw_data_exposed'] ?? true),
    ];
}

function p0_import_summarize_rows(array $rows, string $platform, int $systemHotelId, string $date, array $payload = []): array
{
    $required = ['list_exposure', 'detail_exposure', 'flow_rate', 'order_filling_num', 'order_submit_num'];
    $targetRows = 0;
    $defaultedDateRows = 0;
    $completeRows = 0;
    $rowsWithDesensitizedCaptureEvidence = 0;
    $rowsWithExplicitSourcePath = 0;
    $metricKeys = [];
    $sample = [];
    $payloadEvidence = p0_import_desensitized_capture_evidence($payload);

    foreach ($rows as $row) {
        $row = p0_import_with_payload_capture_evidence($row, $payloadEvidence);
        $explicitDate = p0_import_explicit_row_date($row);
        $rowDate = p0_import_row_date($row, $date);
        if ($rowDate !== $date) {
            continue;
        }
        $targetRows++;
        if ($explicitDate === '') {
            $defaultedDateRows++;
        }
        $explicitSourcePath = p0_import_explicit_source_path($row);
        $sourcePathStructured = p0_import_source_path_is_structured($explicitSourcePath);
        if ($sourcePathStructured) {
            $rowsWithExplicitSourcePath++;
        }
        if (p0_import_desensitized_capture_evidence($row) !== []) {
            $rowsWithDesensitizedCaptureEvidence++;
        }
        $metrics = p0_import_traffic_metrics($row, $platform);
        $preview = p0_import_preview_field_facts($row, $metrics, $platform, $systemHotelId, $date);
        foreach ((array)$preview['complete_metric_keys'] as $metricKey) {
            $metricKeys[(string)$metricKey] = true;
        }
        if ((string)$preview['status'] === 'ready') {
            $completeRows++;
        }
        if (count($sample) < 3) {
            $sample[] = [
                'row_date' => $rowDate,
                'source_path' => $explicitSourcePath,
                'source_path_structured' => $sourcePathStructured,
                'desensitized_capture_evidence_present' => p0_import_desensitized_capture_evidence($row) !== [],
                'metrics' => $metrics,
                'field_fact_preview' => $preview,
            ];
        }
    }

    $completeMetricKeys = array_values(array_intersect($required, array_keys($metricKeys)));
    return [
        'extracted_rows' => count($rows),
        'target_date_rows' => $targetRows,
        'defaulted_date_rows' => $defaultedDateRows,
        'complete_field_fact_preview_rows' => $completeRows,
        'incomplete_field_fact_preview_rows' => max(0, $targetRows - $completeRows),
        'rows_with_desensitized_capture_evidence' => $rowsWithDesensitizedCaptureEvidence,
        'missing_capture_evidence_rows' => max(0, $targetRows - $rowsWithDesensitizedCaptureEvidence),
        'explicit_source_path_rows' => $rowsWithExplicitSourcePath,
        'missing_source_path_rows' => max(0, $targetRows - $rowsWithExplicitSourcePath),
        'complete_metric_keys' => $completeMetricKeys,
        'missing_metric_keys' => array_values(array_diff($required, $completeMetricKeys)),
        'sample_rows' => $sample,
    ];
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<string, mixed>>
 */
function p0_import_build_traffic_evidence(array $rows, string $platform, int $systemHotelId, string $date, array $payload = [], bool $allowEvidence = true): array
{
    if (!$allowEvidence) {
        return [];
    }

    $payloadEvidence = p0_import_desensitized_capture_evidence($payload);
    $evidenceRows = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $row = p0_import_with_payload_capture_evidence($row, $payloadEvidence);
        $rowDate = p0_import_row_date($row, $date);
        if ($rowDate !== $date) {
            continue;
        }
        $captureEvidence = p0_import_desensitized_capture_evidence($row);
        if ($captureEvidence === []) {
            continue;
        }

        $metrics = p0_import_traffic_metrics($row, $platform);
        $preview = p0_import_preview_field_facts($row, $metrics, $platform, $systemHotelId, $date);
        $fieldFacts = array_values(array_filter(
            (array)($preview['field_facts'] ?? []),
            static fn($fact): bool => is_array($fact)
        ));
        if ($fieldFacts === []) {
            continue;
        }

        $evidenceRows[] = [
            'platform' => $platform,
            'target_date' => $rowDate,
            'system_hotel_id' => $systemHotelId,
            'scope_policy' => 'ota_channel_only',
            'source_trace_id' => (string)($captureEvidence['source_trace_id'] ?? ''),
            'capture_evidence' => $captureEvidence,
            'sensitive_values_exposed' => false,
            'ui_status' => p0_import_external_ui_status((array)($preview['ui_status'] ?? [])),
            'field_facts' => $fieldFacts,
        ];
    }

    return $evidenceRows;
}

function p0_import_prepare_execute_payload(array $rows, string $platform, int $systemHotelId, string $date, array $payload = []): array
{
    $payloadEvidence = p0_import_desensitized_capture_evidence($payload);
    $preparedRows = [];
    $evidenceRows = 0;

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $row = p0_import_with_payload_capture_evidence($row, $payloadEvidence);
        $rowDate = p0_import_row_date($row, $date);
        if ($rowDate !== $date) {
            continue;
        }

        $metrics = p0_import_traffic_metrics($row, $platform);
        $row['source'] = $platform;
        $row['data_type'] = 'traffic';
        $row['data_date'] = $rowDate;
        $row['date'] = $rowDate;
        $row['system_hotel_id'] = $systemHotelId;
        $row['list_exposure'] = $metrics['list_exposure'];
        $row['detail_exposure'] = $metrics['detail_exposure'];
        $row['flow_rate'] = $metrics['flow_rate'];
        $row['order_filling_num'] = $metrics['order_filling_num'];
        $row['order_submit_num'] = $metrics['order_submit_num'];

        $hasHotelId = trim((string)($row['hotelId'] ?? $row['hotel_id'] ?? $row['HotelId'] ?? $row['hotelID'] ?? $row['poiId'] ?? $row['poi_id'] ?? '')) !== '';
        $hasHotelName = trim((string)($row['hotelName'] ?? $row['hotel_name'] ?? $row['HotelName'] ?? $row['name'] ?? $row['poiName'] ?? $row['poi_name'] ?? '')) !== '';
        if (!$hasHotelId) {
            $row[$platform === 'meituan' ? 'poi_id' : 'hotel_id'] = (string)$systemHotelId;
        }
        if (!$hasHotelName && $platform === 'meituan') {
            $row['poi_name'] = 'system_hotel_' . $systemHotelId;
        }
        if (trim((string)($row['_source_path'] ?? $row['source_path'] ?? '')) === '') {
            $row['_source_path'] = 'validated_target_date_rows.' . count($preparedRows);
        }
        if (p0_import_desensitized_capture_evidence($row) !== []) {
            $evidenceRows++;
        }

        $preparedRows[] = $row;
    }

    return [
        'payload' => [
            'source' => $platform,
            'data_type' => 'traffic',
            'data' => [
                'flowData' => $preparedRows,
            ],
        ],
        'row_count' => count($preparedRows),
        'rows_with_desensitized_capture_evidence' => $evidenceRows,
    ];
}

/**
 * @param array{payload:array<string, mixed>, row_count:int, rows_with_desensitized_capture_evidence:int} $prepared
 * @return array<string, mixed>
 */
function p0_import_execute_plan(array $prepared): array
{
    return [
        'input_source' => 'validated_target_date_rows',
        'payload_shape' => 'data.flowData',
        'target_date_row_count' => $prepared['row_count'],
        'rows_with_desensitized_capture_evidence' => $prepared['rows_with_desensitized_capture_evidence'],
    ];
}

/**
 * @return array<string, string>
 */
function p0_import_required_traffic_storage_fields(): array
{
    return [
        'list_exposure' => 'online_daily_data.list_exposure',
        'detail_exposure' => 'online_daily_data.detail_exposure',
        'flow_rate' => 'online_daily_data.flow_rate',
        'order_filling_num' => 'online_daily_data.order_filling_num',
        'order_submit_num' => 'online_daily_data.order_submit_num',
    ];
}

/**
 * @return array<string, bool>
 */
function p0_import_table_columns(string $table): array
{
    $safeTable = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    if ($safeTable === '') {
        return [];
    }

    $columns = [];
    try {
        foreach (Db::query('SHOW COLUMNS FROM `' . $safeTable . '`') as $row) {
            if (is_array($row) && isset($row['Field'])) {
                $columns[(string)$row['Field']] = true;
            }
        }
    } catch (Throwable) {
        return [];
    }

    return $columns;
}

/**
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function p0_import_post_execute_verification(array $options): array
{
    $requiredStorageFields = p0_import_required_traffic_storage_fields();
    $requiredMetricKeys = array_keys($requiredStorageFields);
    $base = [
        'status' => 'not_run',
        'target_date' => (string)$options['date'],
        'platform' => (string)$options['platform'],
        'system_hotel_id' => (int)$options['system-hotel-id'],
        'traffic_row_count' => 0,
        'rows_with_field_facts' => 0,
        'ready_ui_status_rows' => 0,
        'ui_status_ready_rows' => 0,
        'ui_status_incomplete_rows' => 0,
        'complete_metric_keys' => [],
        'missing_metric_keys' => $requiredMetricKeys,
        'incomplete_metric_keys' => [],
        'ui_statuses' => [],
        'sensitive_values_exposed' => false,
        'sample_ui_statuses' => [],
        'sample_rows' => [],
    ];

    $columns = p0_import_table_columns('online_daily_data');
    foreach (['source', 'data_date', 'data_type', 'raw_data'] as $column) {
        if (!isset($columns[$column])) {
            $base['status'] = 'required_column_missing';
            $base['missing_column'] = $column;
            return $base;
        }
    }

    $fieldList = array_values(array_filter([
        'id',
        'source',
        'data_date',
        'data_type',
        'raw_data',
        'list_exposure',
        'detail_exposure',
        'flow_rate',
        'order_filling_num',
        'order_submit_num',
        isset($columns['system_hotel_id']) ? 'system_hotel_id' : '',
        isset($columns['source_trace_id']) ? 'source_trace_id' : '',
        isset($columns['sync_task_id']) ? 'sync_task_id' : '',
    ], static fn(string $field): bool => $field !== '' && isset($columns[$field])));

    try {
        $query = Db::name('online_daily_data')
            ->where('source', (string)$options['platform'])
            ->where('data_date', (string)$options['date'])
            ->whereIn('data_type', ['traffic', 'flow', 'conversion']);
        if (isset($columns['system_hotel_id'])) {
            $query->where('system_hotel_id', (int)$options['system-hotel-id']);
        }
        $rows = $query->field(implode(',', $fieldList))->select()->toArray();
    } catch (Throwable $e) {
        $base['status'] = 'read_failed';
        $base['issues'] = [[
            'code' => 'post_execute_verification_read_failed',
            'message' => $e->getMessage(),
        ]];
        return $base;
    }

    $base['traffic_row_count'] = count($rows);
    if ($rows === []) {
        $base['status'] = 'no_target_date_traffic_rows';
        return $base;
    }

    $completeMetricKeys = [];
    $incompleteMetricKeys = [];
    $uiStatuses = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $raw = json_decode((string)($row['raw_data'] ?? ''), true);
        $raw = is_array($raw) ? $raw : [];
        if (p0_import_sensitive_hits($raw) !== []) {
            $base['sensitive_values_exposed'] = true;
        }

        $uiStatus = p0_import_external_ui_status(p0_import_preview_ui_status($row, $raw));
        $uiFieldFactStatus = (string)($uiStatus['field_fact_status'] ?? '');
        if ($uiFieldFactStatus !== '') {
            $uiStatuses[$uiFieldFactStatus] = true;
        }
        $uiReady = $uiFieldFactStatus === 'ready'
            && (bool)($uiStatus['raw_data_exposed'] ?? true) === false
            && (int)($uiStatus['missing_count'] ?? -1) === 0
            && (int)($uiStatus['stored_value_missing_count'] ?? -1) === 0
            && (int)($uiStatus['captured_count'] ?? 0) >= count($requiredMetricKeys)
            && (int)($uiStatus['capture_evidence_count'] ?? 0) >= count($requiredMetricKeys)
            && (int)($uiStatus['source_path_count'] ?? 0) >= count($requiredMetricKeys)
            && (int)($uiStatus['structured_source_path_count'] ?? 0) >= count($requiredMetricKeys)
            && (int)($uiStatus['storage_field_count'] ?? 0) >= count($requiredMetricKeys);
        if ($uiReady) {
            $base['ready_ui_status_rows']++;
            $base['ui_status_ready_rows']++;
        } else {
            $base['ui_status_incomplete_rows']++;
        }
        if (count($base['sample_ui_statuses']) < 5) {
            $base['sample_ui_statuses'][] = [
                'row_id' => $row['id'] ?? null,
                'field_fact_status' => $uiFieldFactStatus,
                'raw_data_exposed' => (bool)($uiStatus['raw_data_exposed'] ?? true),
                'captured_count' => (int)($uiStatus['captured_count'] ?? 0),
                'structured_source_path_count' => (int)($uiStatus['structured_source_path_count'] ?? 0),
                'missing_count' => (int)($uiStatus['missing_count'] ?? 0),
                'stored_value_missing_count' => (int)($uiStatus['stored_value_missing_count'] ?? 0),
                'status' => $uiReady ? 'ready' : 'incomplete',
            ];
        }

        $facts = is_array($raw['field_facts'] ?? null) ? (array)$raw['field_facts'] : [];
        if ($facts !== []) {
            $base['rows_with_field_facts']++;
        }
        foreach ($facts as $fact) {
            if (!is_array($fact)) {
                continue;
            }
            $metricKey = trim((string)($fact['metric_key'] ?? ''));
            if (!isset($requiredStorageFields[$metricKey])) {
                continue;
            }
            $captureEvidence = is_array($fact['capture_evidence'] ?? null) ? (array)$fact['capture_evidence'] : [];
            $factReady = p0_import_source_path_is_structured((string)($fact['source_path'] ?? ''))
                && trim((string)($fact['storage_field'] ?? '')) === $requiredStorageFields[$metricKey]
                && p0_import_desensitized_capture_evidence($captureEvidence) !== []
                && ($fact['stored_value_present'] ?? null) === true;
            if ($factReady) {
                $completeMetricKeys[$metricKey] = true;
            } else {
                $incompleteMetricKeys[$metricKey] = true;
            }
        }

        if (count($base['sample_rows']) < 3) {
            $base['sample_rows'][] = [
                'id' => $row['id'] ?? null,
                'field_fact_status' => $uiFieldFactStatus,
                'raw_data_exposed' => (bool)($uiStatus['raw_data_exposed'] ?? true),
                'field_fact_count' => count($facts),
            ];
        }
    }

    $completeKeys = array_values(array_intersect($requiredMetricKeys, array_keys($completeMetricKeys)));
    $missingKeys = array_values(array_diff($requiredMetricKeys, $completeKeys));
    $incompleteKeys = array_values(array_diff(array_keys($incompleteMetricKeys), $completeKeys));
    $base['complete_metric_keys'] = $completeKeys;
    $base['missing_metric_keys'] = $missingKeys;
    $base['incomplete_metric_keys'] = $incompleteKeys;
    $base['ui_statuses'] = array_values(array_keys($uiStatuses));
    $base['status'] = $missingKeys === []
        && $incompleteKeys === []
        && (int)$base['rows_with_field_facts'] > 0
        && (int)$base['ready_ui_status_rows'] > 0
        && (int)$base['ui_status_incomplete_rows'] === 0
        && !(bool)$base['sensitive_values_exposed']
        ? 'ready'
        : 'incomplete';
    if ((int)$base['rows_with_field_facts'] === 0) {
        $base['status'] = 'field_facts_missing';
    } elseif ((int)$base['ready_ui_status_rows'] === 0 || (int)$base['ui_status_incomplete_rows'] > 0) {
        $base['status'] = 'ui_status_incomplete';
    }

    return $base;
}

/**
 * @return array<string, mixed>
 */
function p0_import_execute(array $executePayload, array $options): array
{
    $app = new App();
    $app->initialize();

    $service = new OnlineDailyDataPersistenceService();
    $saved = $service->parseAndSaveTrafficData(
        $executePayload,
        (string)$options['date'],
        (string)$options['date'],
        (string)$options['platform'],
        (int)$options['system-hotel-id'],
        (string)$options['platform'] === 'ctrip' ? 'Ctrip' : null
    );

    return [
        'saved_count' => $saved,
        'execute_policy' => 'explicit_execute_only',
        'execute_input_source' => 'validated_target_date_rows',
        'execute_payload_shape' => 'data.flowData',
        'execute_payload_row_count' => count((array)($executePayload['data']['flowData'] ?? [])),
        'post_execute_verification' => p0_import_post_execute_verification($options),
    ];
}

function p0_import_render_markdown(array $result): string
{
    $lines = [];
    $lines[] = '# P0 OTA Traffic Payload Import';
    $lines[] = '';
    $lines[] = '- status: `' . (string)($result['status'] ?? 'unknown') . '`';
    $lines[] = '- mode: `' . (string)($result['mode'] ?? 'dry_run') . '`';
    $lines[] = '- platform: `' . (string)($result['platform'] ?? '') . '`';
    $lines[] = '- date: `' . (string)($result['date'] ?? '') . '`';
    $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
    $lines[] = '- extracted rows: `' . (int)($summary['extracted_rows'] ?? 0) . '`';
    $lines[] = '- target-date rows: `' . (int)($summary['target_date_rows'] ?? 0) . '`';
    $lines[] = '- defaulted date rows: `' . (int)($summary['defaulted_date_rows'] ?? 0) . '`';
    $lines[] = '- missing source path rows: `' . (int)($summary['missing_source_path_rows'] ?? 0) . '`';
    $lines[] = '- incomplete field-fact preview rows: `' . (int)($summary['incomplete_field_fact_preview_rows'] ?? 0) . '`';
    $lines[] = '- rows with desensitized capture evidence: `' . (int)($summary['rows_with_desensitized_capture_evidence'] ?? 0) . '`';
    $lines[] = '- missing metric keys: ' . implode(', ', array_map(static fn($item): string => '`' . (string)$item . '`', (array)($summary['missing_metric_keys'] ?? [])));
    $trafficEvidence = is_array($result['traffic_evidence'] ?? null) ? $result['traffic_evidence'] : [];
    $lines[] = '- traffic evidence rows: `' . count($trafficEvidence) . '`';
    $executePlan = is_array($result['execute_plan'] ?? null) ? $result['execute_plan'] : [];
    if ($executePlan !== []) {
        $lines[] = '- execute input source: `' . (string)($executePlan['input_source'] ?? '') . '`';
        $lines[] = '- execute payload rows: `' . (int)($executePlan['target_date_row_count'] ?? 0) . '`';
    }
    if (isset($result['saved_count'])) {
        $lines[] = '- saved count: `' . (int)$result['saved_count'] . '`';
    }
    $postExecute = is_array($result['post_execute_verification'] ?? null) ? $result['post_execute_verification'] : [];
    if ($postExecute !== []) {
        $lines[] = '- post-execute verification: `' . (string)($postExecute['status'] ?? 'unknown') . '`';
        $lines[] = '- post-execute traffic rows: `' . (int)($postExecute['traffic_row_count'] ?? 0) . '`';
    }
    if (($result['issues'] ?? []) !== []) {
        $lines[] = '';
        $lines[] = '## Issues';
        foreach ((array)$result['issues'] as $issue) {
            if (is_array($issue)) {
                $lines[] = '- `' . (string)($issue['code'] ?? 'issue') . '`: ' . (string)($issue['message'] ?? '');
            }
        }
    }
    return implode(PHP_EOL, $lines);
}

try {
    $options = p0_import_parse_args($argv);
    $payload = p0_import_read_payload((string)$options['payload']);
    $sensitiveHits = p0_import_sensitive_hits($payload);
    $rows = p0_import_extract_rows($payload, (string)$options['platform']);
    $summary = p0_import_summarize_rows($rows, (string)$options['platform'], (int)$options['system-hotel-id'], (string)$options['date'], $payload);
    $preparedExecute = p0_import_prepare_execute_payload($rows, (string)$options['platform'], (int)$options['system-hotel-id'], (string)$options['date'], $payload);
    $executePlan = p0_import_execute_plan($preparedExecute);

    $issues = [];
    if ($sensitiveHits !== []) {
        $issues[] = [
            'code' => 'sensitive_payload_keys_detected',
            'message' => 'Payload contains sensitive-looking keys or values; remove secrets and retry.',
            'hits' => $sensitiveHits,
        ];
    }
    if ((int)$summary['target_date_rows'] <= 0) {
        $issues[] = [
            'code' => 'target_date_traffic_rows_missing',
            'message' => 'Payload did not produce target-date traffic rows.',
        ];
    }
    if ((int)$summary['target_date_rows'] > 0 && (int)($summary['defaulted_date_rows'] ?? 0) > 0) {
        $issues[] = [
            'code' => 'target_date_explicit_row_date_missing',
            'message' => 'Every target-date traffic row must carry an explicit source date; command --date cannot be used as row-date evidence.',
            'defaulted_date_rows' => (int)$summary['defaulted_date_rows'],
        ];
    }
    if ((int)$summary['target_date_rows'] > 0 && (int)($summary['missing_source_path_rows'] ?? 0) > 0) {
        $issues[] = [
            'code' => 'target_date_source_path_missing',
            'message' => 'Every target-date traffic row must carry an explicit structured source path; field names alone are not accepted as source-path evidence.',
            'missing_source_path_rows' => (int)$summary['missing_source_path_rows'],
        ];
    }
    if ((array)$summary['missing_metric_keys'] !== []) {
        $issues[] = [
            'code' => 'required_traffic_metric_keys_missing',
            'message' => 'Payload rows do not prove every required traffic metric key.',
            'missing_metric_keys' => $summary['missing_metric_keys'],
        ];
    }
    if ((int)$summary['target_date_rows'] > 0 && (int)($summary['incomplete_field_fact_preview_rows'] ?? 0) > 0) {
        $issues[] = [
            'code' => 'traffic_field_fact_preview_rows_incomplete',
            'message' => 'Every target-date traffic row must preview as a complete field loop before import; cross-row metric coverage is not accepted.',
            'incomplete_field_fact_preview_rows' => (int)$summary['incomplete_field_fact_preview_rows'],
        ];
    }
    if ((int)$summary['target_date_rows'] > 0 && (int)$summary['missing_capture_evidence_rows'] > 0) {
        $issues[] = [
            'code' => 'desensitized_capture_evidence_missing',
            'message' => 'Target-date traffic rows must include desensitized capture evidence such as source_trace_id, source_url_hash, request_hash, or payload_hash.',
            'missing_capture_evidence_rows' => $summary['missing_capture_evidence_rows'],
        ];
    }

    $trafficEvidence = p0_import_build_traffic_evidence(
        $rows,
        (string)$options['platform'],
        (int)$options['system-hotel-id'],
        (string)$options['date'],
        $payload,
        $issues === []
    );
    if ($issues === []) {
        $targetDateRowCount = (int)$summary['target_date_rows'];
        $executeRowCount = (int)($preparedExecute['row_count'] ?? 0);
        $trafficEvidenceRowCount = count($trafficEvidence);
        if ($targetDateRowCount !== $executeRowCount || $targetDateRowCount !== $trafficEvidenceRowCount) {
            $issues[] = [
                'code' => 'traffic_evidence_execute_row_count_mismatch',
                'message' => 'Traffic evidence rows, target-date rows, and execute payload rows must match before import.',
                'target_date_rows' => $targetDateRowCount,
                'execute_payload_rows' => $executeRowCount,
                'traffic_evidence_rows' => $trafficEvidenceRowCount,
            ];
        }
    }
    $status = $issues === [] ? 'ready_to_import' : 'blocked';
    $executeResult = [];
    if ($issues === [] && (bool)$options['execute']) {
        $executeResult = p0_import_execute((array)$preparedExecute['payload'], $options);
        $postExecuteVerification = is_array($executeResult['post_execute_verification'] ?? null) ? $executeResult['post_execute_verification'] : [];
        $status = (int)$executeResult['saved_count'] > 0 && (string)($postExecuteVerification['status'] ?? '') === 'ready' ? 'imported' : 'blocked';
        if ((int)$executeResult['saved_count'] <= 0) {
            $issues[] = [
                'code' => 'traffic_payload_saved_zero_rows',
                'message' => 'Persistence completed but saved zero rows; run the P0 verifier for details.',
            ];
        } elseif ((string)($postExecuteVerification['status'] ?? '') !== 'ready') {
            $issues[] = [
                'code' => 'post_execute_verification_incomplete',
                'message' => 'Saved traffic rows were not accepted as a complete P0 traffic field loop after DB readback.',
                'post_execute_status' => (string)($postExecuteVerification['status'] ?? 'unknown'),
            ];
        }
    }

    $result = array_merge([
        'script' => 'scripts/import_p0_ota_traffic_payload.php',
        'status' => $status,
        'mode' => (bool)$options['execute'] ? 'execute' : 'dry_run',
        'platform' => $options['platform'],
        'date' => $options['date'],
        'system_hotel_id' => $options['system-hotel-id'],
        'payload_path' => $options['payload'],
        'scope_policy' => 'ota_channel_only',
        'target_storage_table' => 'online_daily_data',
        'target_data_type' => 'traffic',
        'sensitive_values_exposed' => $sensitiveHits !== [],
        'summary' => $summary,
        'execute_plan' => $executePlan,
        'traffic_evidence' => $trafficEvidence,
        'traffic_evidence_contract' => [
            'status' => $trafficEvidence !== [] && $issues === [] ? 'ready_for_p0_verifier_external_evidence' : 'blocked_or_not_available',
            'verifier_command' => 'npm.cmd run verify:p0-ota-field-loop -- --date=' . (string)$options['date'] . ' --platform=' . (string)$options['platform'] . ' --system-hotel-id=' . (int)$options['system-hotel-id'] . ' --traffic-evidence=<this-json-output>',
            'completion_policy' => 'External traffic_evidence validates desensitized source proof only; P0 closure still requires --execute import plus verify:p0-ota-field-loop target-date traffic rows.',
        ],
        'issues' => $issues,
        'completion_policy' => 'Import is only accepted as P0 closure after verify:p0-ota-field-loop proves target-date traffic rows and traffic field facts.',
        'next_verifier_command' => 'npm.cmd run verify:p0-ota-field-loop -- --date=' . (string)$options['date'],
    ], $executeResult);
} catch (Throwable $e) {
    $result = [
        'script' => 'scripts/import_p0_ota_traffic_payload.php',
        'status' => 'failed',
        'mode' => 'unknown',
        'issues' => [[
            'code' => 'p0_traffic_payload_import_failed',
            'message' => $e->getMessage(),
        ]],
    ];
}

echo ((($options['format'] ?? 'json') === 'markdown')
    ? p0_import_render_markdown($result)
    : json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
) . PHP_EOL;

exit(($result['status'] ?? '') === 'ready_to_import' || ($result['status'] ?? '') === 'imported' ? 0 : (($result['status'] ?? '') === 'blocked' ? 1 : 2));
