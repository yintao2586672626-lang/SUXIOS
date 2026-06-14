<?php
declare(strict_types=1);

use app\service\CtripTrafficDisplayService;
use app\service\OnlineDailyDataPersistenceService;
use app\service\OnlineDataFieldFactService;
use app\service\OnlineTrafficDataExtractionService;
use think\App;

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
            || preg_match('/(^|_)source_url$/i', $segment)
        ) {
            $hits[] = ['path' => $nextPath, 'reason' => 'sensitive_key_present'];
        }
        if (is_string($item)) {
            $trimmed = trim($item);
            if (preg_match('/\b(Bearer|Cookie|Authorization)\s*[:=]/i', $trimmed)
                || preg_match('/spidertoken|access[_-]?token|refresh[_-]?token/i', $trimmed)
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
function p0_import_row_date(array $row, string $defaultDate): string
{
    $value = $row['date'] ?? $row['dataDate'] ?? $row['statDate'] ?? $row['stat_date'] ?? $row['data_date'] ?? $row['reportDate'] ?? $row['day'] ?? '';
    if (trim((string)$value) === '') {
        return $defaultDate;
    }
    $timestamp = strtotime((string)$value);
    return $timestamp === false ? '' : date('Y-m-d', $timestamp);
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
    $required = ['list_exposure', 'detail_exposure', 'flow_rate', 'order_filling_num', 'order_submit_num'];
    $complete = [];
    $missing = [];
    foreach ($required as $metricKey) {
        $matched = false;
        foreach ($facts as $fact) {
            if (!is_array($fact) || (string)($fact['metric_key'] ?? '') !== $metricKey) {
                continue;
            }
            $matched = trim((string)($fact['source_path'] ?? '')) !== ''
                && trim((string)($fact['storage_field'] ?? '')) !== ''
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

    return [
        'status' => $missing === [] ? 'ready' : 'incomplete',
        'complete_metric_keys' => $complete,
        'missing_metric_keys' => $missing,
        'fact_count' => count($facts),
        'desensitized_capture_evidence_present' => $missing === [] && $facts !== [],
    ];
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<string, mixed>
 */
function p0_import_summarize_rows(array $rows, string $platform, int $systemHotelId, string $date, array $payload = []): array
{
    $required = ['list_exposure', 'detail_exposure', 'flow_rate', 'order_filling_num', 'order_submit_num'];
    $targetRows = 0;
    $defaultedDateRows = 0;
    $completeRows = 0;
    $rowsWithDesensitizedCaptureEvidence = 0;
    $metricKeys = [];
    $sample = [];
    $payloadEvidence = p0_import_desensitized_capture_evidence($payload);

    foreach ($rows as $row) {
        $row = p0_import_with_payload_capture_evidence($row, $payloadEvidence);
        $explicitDate = trim((string)($row['date'] ?? $row['dataDate'] ?? $row['statDate'] ?? $row['stat_date'] ?? $row['data_date'] ?? $row['reportDate'] ?? $row['day'] ?? ''));
        $rowDate = p0_import_row_date($row, $date);
        if ($rowDate !== $date) {
            continue;
        }
        $targetRows++;
        if ($explicitDate === '') {
            $defaultedDateRows++;
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
                'source_path' => (string)($row['_source_path'] ?? $row['source_path'] ?? ''),
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
        'rows_with_desensitized_capture_evidence' => $rowsWithDesensitizedCaptureEvidence,
        'missing_capture_evidence_rows' => max(0, $targetRows - $rowsWithDesensitizedCaptureEvidence),
        'complete_metric_keys' => $completeMetricKeys,
        'missing_metric_keys' => array_values(array_diff($required, $completeMetricKeys)),
        'sample_rows' => $sample,
    ];
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array{payload:array<string, mixed>, row_count:int, rows_with_desensitized_capture_evidence:int}
 */
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
    $lines[] = '- rows with desensitized capture evidence: `' . (int)($summary['rows_with_desensitized_capture_evidence'] ?? 0) . '`';
    $lines[] = '- missing metric keys: ' . implode(', ', array_map(static fn($item): string => '`' . (string)$item . '`', (array)($summary['missing_metric_keys'] ?? [])));
    $executePlan = is_array($result['execute_plan'] ?? null) ? $result['execute_plan'] : [];
    if ($executePlan !== []) {
        $lines[] = '- execute input source: `' . (string)($executePlan['input_source'] ?? '') . '`';
        $lines[] = '- execute payload rows: `' . (int)($executePlan['target_date_row_count'] ?? 0) . '`';
    }
    if (isset($result['saved_count'])) {
        $lines[] = '- saved count: `' . (int)$result['saved_count'] . '`';
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
    if ((array)$summary['missing_metric_keys'] !== []) {
        $issues[] = [
            'code' => 'required_traffic_metric_keys_missing',
            'message' => 'Payload rows do not prove every required traffic metric key.',
            'missing_metric_keys' => $summary['missing_metric_keys'],
        ];
    }
    if ((int)$summary['target_date_rows'] > 0 && (int)$summary['missing_capture_evidence_rows'] > 0) {
        $issues[] = [
            'code' => 'desensitized_capture_evidence_missing',
            'message' => 'Target-date traffic rows must include desensitized capture evidence such as source_trace_id, source_url_hash, request_hash, or payload_hash.',
            'missing_capture_evidence_rows' => $summary['missing_capture_evidence_rows'],
        ];
    }

    $status = $issues === [] ? 'ready_to_import' : 'blocked';
    $executeResult = [];
    if ($issues === [] && (bool)$options['execute']) {
        $executeResult = p0_import_execute((array)$preparedExecute['payload'], $options);
        $status = (int)$executeResult['saved_count'] > 0 ? 'imported' : 'blocked';
        if ((int)$executeResult['saved_count'] <= 0) {
            $issues[] = [
                'code' => 'traffic_payload_saved_zero_rows',
                'message' => 'Persistence completed but saved zero rows; run the P0 verifier for details.',
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
