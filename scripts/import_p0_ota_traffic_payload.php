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

function p0_import_normalize_sensitive_key_segment(string $segment): string
{
    $normalized = strtolower((string)preg_replace('/(?<!^)[A-Z]/', '_$0', $segment));
    $normalized = (string)preg_replace('/[^a-z0-9]+/', '_', $normalized);
    return trim($normalized, '_');
}

/**
 * @param string $segment
 */
function p0_import_is_raw_url_key(string $segment): bool
{
    $normalized = p0_import_normalize_sensitive_key_segment($segment);
    return $normalized === 'url'
        || $normalized === 'endpoint'
        || $normalized === 'request_uri'
        || str_ends_with($normalized, '_url');
}

function p0_import_is_raw_url_value(string $value): bool
{
    return preg_match('#https?://#i', $value) === 1;
}

function p0_import_is_sensitive_browser_metadata_key(string $segment): bool
{
    $normalized = p0_import_normalize_sensitive_key_segment($segment);
    return preg_match('/(^|_)(cookie|token|spider_token|authorization|password|secret)($|_)/i', $normalized) === 1
        || preg_match('/(^|_)profile_(path|dir|directory)($|_)/i', $normalized) === 1
        || preg_match('/(^|_)raw_(cookie|token|profile)($|_)/i', $normalized) === 1
        || p0_import_is_raw_url_key($segment);
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
        if (p0_import_is_sensitive_browser_metadata_key($segment)) {
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

/**
 * @return array{payload:array<string, mixed>, metadata:array<string, mixed>}
 */
function p0_import_project_payload_for_import(array $payload): array
{
    if (!p0_import_payload_is_browser_capture($payload)) {
        return [
            'payload' => $payload,
            'metadata' => [
                'applied' => false,
                'reason' => 'not_browser_capture_payload',
                'removed_sensitive_metadata_count' => 0,
                'removed_sensitive_metadata_paths' => [],
            ],
        ];
    }

    $allowedTopLevelKeys = [
        'source',
        'mode',
        'system_hotel_id',
        'hotel_id',
        'hotelId',
        'poi_id',
        'poiId',
        'store_id',
        'storeId',
        'partner_id',
        'partnerId',
        'default_data_date',
        'auth_status',
        'capture_gate',
        'capture_sections',
        'requested_sections',
        'traffic',
        'standard_rows',
        'responses',
    ];
    $removedPaths = [];
    $projected = [];
    $payloadTopLevelKeys = array_keys($payload);
    $retainedTopLevelKeys = array_values(array_intersect($allowedTopLevelKeys, $payloadTopLevelKeys));
    $droppedTopLevelKeys = array_values(array_diff($payloadTopLevelKeys, $allowedTopLevelKeys));
    foreach ($allowedTopLevelKeys as $key) {
        if (!array_key_exists($key, $payload)) {
            continue;
        }
        $projected[$key] = p0_import_sanitize_browser_import_value($payload[$key], $key, $removedPaths);
    }

    return [
        'payload' => $projected,
        'metadata' => [
            'applied' => true,
            'reason' => 'browser_capture_import_projection',
            'retained_top_level_keys' => $retainedTopLevelKeys,
            'dropped_top_level_key_count' => count($droppedTopLevelKeys),
            'dropped_top_level_keys' => array_slice($droppedTopLevelKeys, 0, 20),
            'removed_sensitive_metadata_count' => count($removedPaths),
            'removed_sensitive_metadata_paths' => array_slice($removedPaths, 0, 20),
        ],
    ];
}

function p0_import_sanitize_browser_import_value(mixed $value, string $path, array &$removedPaths): mixed
{
    if (is_array($value)) {
        $result = [];
        foreach ($value as $key => $item) {
            $segment = is_string($key) ? $key : (string)$key;
            $nextPath = $path === '' ? $segment : $path . '.' . $segment;
            if (p0_import_is_sensitive_browser_metadata_key($segment)) {
                $removedPaths[] = $nextPath;
                continue;
            }
            $sanitized = p0_import_sanitize_browser_import_value($item, $nextPath, $removedPaths);
            if ($sanitized === null) {
                continue;
            }
            $result[$key] = $sanitized;
        }
        return $result;
    }

    if (is_string($value)) {
        $trimmed = trim($value);
        if (preg_match('/\b(Bearer|Cookie|Authorization)\s*[:=]/i', $trimmed)
            || preg_match('/spidertoken|access[_-]?token|refresh[_-]?token/i', $trimmed)
            || p0_import_is_raw_url_value($trimmed)
        ) {
            $removedPaths[] = $path;
            return null;
        }
    }

    return $value;
}

/**
 * @return array<int, array<string, mixed>>
 */
function p0_import_payload_is_browser_capture(array $payload): bool
{
    $source = strtolower(trim((string)($payload['source'] ?? '')));
    return str_contains($source, 'browser_profile')
        || isset($payload['auth_status'])
        || isset($payload['capture_gate']);
}

/**
 * @return array<int, array<string, mixed>>
 */
function p0_import_payload_scope_issues(array $payload, string $platform, int $systemHotelId): array
{
    $issues = [];
    $payloadSystemHotelId = (int)($payload['system_hotel_id'] ?? 0);
    if ($payloadSystemHotelId > 0 && $payloadSystemHotelId !== $systemHotelId) {
        $issues[] = [
            'code' => 'system_hotel_id_mismatch',
            'message' => 'Payload system_hotel_id does not match the import command hotel scope.',
            'payload_system_hotel_id' => $payloadSystemHotelId,
            'command_system_hotel_id' => $systemHotelId,
        ];
    }

    $source = strtolower(trim((string)($payload['source'] ?? '')));
    if (!p0_import_payload_is_browser_capture($payload)) {
        return $issues;
    }

    if ($source === '') {
        $issues[] = [
            'code' => 'browser_capture_source_missing',
            'message' => 'Browser capture output must include a source such as ctrip_browser_profile or meituan_browser_profile.',
        ];
    }

    if ($payloadSystemHotelId <= 0) {
        $issues[] = [
            'code' => 'browser_capture_system_hotel_id_missing',
            'message' => 'Browser capture output must include system_hotel_id to prove the selected hotel scope.',
            'command_system_hotel_id' => $systemHotelId,
        ];
    }

    if ($source !== '' && !str_contains($source, $platform)) {
        $issues[] = [
            'code' => 'browser_capture_platform_mismatch',
            'message' => 'Browser capture source does not match the import command platform.',
            'payload_source' => $source,
            'command_platform' => $platform,
        ];
    }

    if (p0_import_platform_hotel_identifier($payload, $platform) === '') {
        $issues[] = [
            'code' => 'browser_capture_platform_hotel_identifier_missing',
            'message' => $platform === 'ctrip'
                ? 'Ctrip browser capture output must include a top-level hotel_id or hotelId from the OTA platform; profile_id and system_hotel_id cannot replace it.'
                : 'Meituan browser capture output must include a top-level poi_id, poiId, store_id, or storeId from the OTA platform; system_hotel_id cannot replace it.',
        ];
    }

    $payloadMode = strtolower(trim((string)($payload['mode'] ?? '')));
    $authStatus = is_array($payload['auth_status'] ?? null) ? (array)$payload['auth_status'] : [];
    $captureGate = is_array($payload['capture_gate'] ?? null) ? (array)$payload['capture_gate'] : [];
    $gateMode = strtolower(trim((string)($captureGate['mode'] ?? '')));
    $authOk = false;
    if ($payloadMode === 'login_only' || $gateMode === 'login_only') {
        $issues[] = [
            'code' => 'browser_capture_login_only_not_importable',
            'message' => 'Login-only browser capture output is not importable as target-date traffic evidence.',
        ];
    }

    if ($authStatus === []) {
        $issues[] = [
            'code' => 'browser_capture_auth_status_missing',
            'message' => 'Browser capture output must include auth_status before traffic import.',
        ];
    } else {
        $authOk = ($authStatus['ok'] ?? null) === true
            || strtolower(trim((string)($authStatus['status'] ?? ''))) === 'logged_in';
        if (!$authOk) {
            $issues[] = [
                'code' => 'browser_capture_auth_not_verified',
                'message' => 'Browser capture auth_status is not verified as logged in.',
                'auth_status' => (string)($authStatus['status'] ?? 'unknown'),
            ];
        }
    }

    if ($captureGate === []) {
        $issues[] = [
            'code' => 'browser_capture_gate_missing',
            'message' => 'Browser capture output must include capture_gate before traffic import.',
        ];
    } elseif (strtolower(trim((string)($captureGate['status'] ?? ''))) !== 'pass') {
        $failedCheckIds = p0_import_capture_gate_failed_check_ids($captureGate);
        $blockingFailedCheckIds = p0_import_capture_gate_blocking_failed_check_ids($failedCheckIds);
        if ($authOk && $failedCheckIds !== [] && $blockingFailedCheckIds === []) {
            return $issues;
        }
        $issues[] = [
            'code' => 'browser_capture_gate_not_pass',
            'message' => 'Browser capture gate did not pass; failed capture output cannot be imported.',
            'capture_gate_status' => (string)($captureGate['status'] ?? 'unknown'),
            'failed_check_ids' => $failedCheckIds,
            'blocking_failed_check_ids' => $blockingFailedCheckIds,
        ];
    }

    return $issues;
}

/**
 * @return array<int, string>
 */
function p0_import_capture_gate_failed_check_ids(array $captureGate): array
{
    return array_values(array_filter(array_map(
        static fn($item): string => trim((string)$item),
        array_filter((array)($captureGate['failed_check_ids'] ?? []), 'is_scalar')
    ), static fn(string $item): bool => $item !== ''));
}

/**
 * @param array<int, string> $failedCheckIds
 * @return array<int, string>
 */
function p0_import_capture_gate_blocking_failed_check_ids(array $failedCheckIds): array
{
    $softCheckIds = ['field_coverage', 'endpoint_coverage'];
    return array_values(array_filter(
        $failedCheckIds,
        static fn(string $checkId): bool => !in_array($checkId, $softCheckIds, true)
    ));
}

/**
 * @return array<int, array<string, mixed>>
 */
function p0_import_payload_warnings(array $payload): array
{
    if (!p0_import_payload_is_browser_capture($payload)) {
        return [];
    }
    $captureGate = is_array($payload['capture_gate'] ?? null) ? (array)$payload['capture_gate'] : [];
    if ($captureGate === [] || strtolower(trim((string)($captureGate['status'] ?? ''))) === 'pass') {
        return [];
    }
    $authStatus = is_array($payload['auth_status'] ?? null) ? (array)$payload['auth_status'] : [];
    $authOk = ($authStatus['ok'] ?? null) === true
        || strtolower(trim((string)($authStatus['status'] ?? ''))) === 'logged_in';
    $failedCheckIds = p0_import_capture_gate_failed_check_ids($captureGate);
    $blockingFailedCheckIds = p0_import_capture_gate_blocking_failed_check_ids($failedCheckIds);
    if (!$authOk || $failedCheckIds === [] || $blockingFailedCheckIds !== []) {
        return [];
    }

    return [[
        'code' => 'browser_capture_gate_soft_warning',
        'message' => 'Browser capture gate has non-blocking endpoint/field coverage gaps; row-level date, source path, metric, capture evidence, and response evidence checks remain required before import.',
        'capture_gate_status' => (string)($captureGate['status'] ?? 'unknown'),
        'failed_check_ids' => $failedCheckIds,
        'blocking_failed_check_ids' => $blockingFailedCheckIds,
    ]];
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
        'source_url_hash' => ['source_url_hash', '_source_url_hash', 'url_hash', '_url_hash'],
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
 * @return array{responses:array<int, array{source_trace_id:string, source_url_hash:string, row_count:int, remaining_row_count:int}>, source_trace_ids:array<string, bool>, source_url_hashes:array<string, bool>}
 */
function p0_import_browser_response_evidence(array $payload): array
{
    $result = [
        'responses' => [],
        'source_trace_ids' => [],
        'source_url_hashes' => [],
    ];
    $responses = is_array($payload['responses'] ?? null) ? (array)$payload['responses'] : [];
    foreach ($responses as $response) {
        if (!is_array($response)) {
            continue;
        }
        if (!p0_import_browser_response_is_traffic_evidence($response)) {
            continue;
        }
        $evidence = p0_import_desensitized_capture_evidence($response);
        $traceId = (string)($evidence['source_trace_id'] ?? '');
        $urlHash = (string)($evidence['source_url_hash'] ?? '');
        $rowCount = p0_import_browser_response_row_count($response);
        $result['responses'][] = [
            'source_trace_id' => $traceId,
            'source_url_hash' => $urlHash,
            'row_count' => $rowCount,
            'remaining_row_count' => $rowCount,
        ];
        if ($traceId !== '') {
            $result['source_trace_ids'][$traceId] = true;
        }
        if ($urlHash !== '') {
            $result['source_url_hashes'][$urlHash] = true;
        }
    }
    return $result;
}

/**
 * @param array<string, mixed> $response
 */
function p0_import_browser_response_is_traffic_evidence(array $response): bool
{
    $status = $response['status'] ?? null;
    if ($status !== null && ((int)$status < 200 || (int)$status >= 300)) {
        return false;
    }

    if (p0_import_browser_response_row_count($response) <= 0) {
        return false;
    }

    $labels = [
        $response['section'] ?? '',
        $response['capture_section'] ?? '',
        $response['data_type'] ?? '',
        $response['dataType'] ?? '',
        $response['endpoint_id'] ?? '',
        $response['endpoint_label'] ?? '',
    ];
    foreach ($labels as $label) {
        $text = strtolower(trim((string)$label));
        if ($text === '') {
            continue;
        }
        foreach (['traffic', 'flow', 'conversion'] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * @param array<string, mixed> $response
 */
function p0_import_browser_response_row_count(array $response): int
{
    $rowCount = (int)($response['row_count'] ?? $response['rowCount'] ?? 0);
    $standardRowCount = (int)($response['standard_row_count'] ?? $response['standardRowCount'] ?? 0);
    return max(0, $rowCount) + max(0, $standardRowCount);
}

/**
 * @param array<string, string> $captureEvidence
 * @param array{responses?:array<int, array<string, mixed>>, source_trace_ids?:array<string, bool>, source_url_hashes?:array<string, bool>} $responseEvidence
 */
function p0_import_capture_evidence_matches_response(array $captureEvidence, array &$responseEvidence): bool
{
    $traceId = (string)($captureEvidence['source_trace_id'] ?? '');
    $urlHash = (string)($captureEvidence['source_url_hash'] ?? '');
    $responses = is_array($responseEvidence['responses'] ?? null) ? (array)$responseEvidence['responses'] : [];
    foreach ($responses as $index => $response) {
        if (!is_array($response)) {
            continue;
        }
        $remaining = (int)($response['remaining_row_count'] ?? 0);
        if ($remaining <= 0) {
            continue;
        }
        $responseTraceId = (string)($response['source_trace_id'] ?? '');
        $responseUrlHash = (string)($response['source_url_hash'] ?? '');
        $matches = ($traceId !== '' && $responseTraceId !== '' && $traceId === $responseTraceId)
            || ($urlHash !== '' && $responseUrlHash !== '' && $urlHash === $responseUrlHash);
        if (!$matches) {
            continue;
        }
        $responseEvidence['responses'][$index]['remaining_row_count'] = $remaining - 1;
        return true;
    }

    return false;
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
    if (!is_array($captureEvidence)) {
        return false;
    }
    $desensitized = p0_import_desensitized_capture_evidence($captureEvidence);
    return trim((string)($desensitized['source_trace_id'] ?? '')) !== ''
        && trim((string)($desensitized['source_url_hash'] ?? '')) !== '';
}

function p0_import_has_complete_desensitized_capture_evidence(array $source): bool
{
    $evidence = p0_import_desensitized_capture_evidence($source);
    return trim((string)($evidence['source_trace_id'] ?? '')) !== ''
        && trim((string)($evidence['source_url_hash'] ?? '')) !== '';
}

function p0_import_fact_capture_evidence_matches_row(array $fact, string $rowSourceTraceId = '', string $rowSourceUrlHash = ''): bool
{
    $captureEvidence = $fact['capture_evidence'] ?? null;
    if (!is_array($captureEvidence)) {
        return false;
    }

    $desensitized = p0_import_desensitized_capture_evidence($captureEvidence);
    $factSourceTraceId = trim((string)($desensitized['source_trace_id'] ?? ''));
    $factSourceUrlHash = trim((string)($desensitized['source_url_hash'] ?? ''));
    if ($factSourceTraceId === '' || $factSourceUrlHash === '') {
        return false;
    }
    if ($rowSourceTraceId !== '' && $factSourceTraceId !== $rowSourceTraceId) {
        return false;
    }
    if ($rowSourceUrlHash !== '' && $factSourceUrlHash !== $rowSourceUrlHash) {
        return false;
    }

    return true;
}

/**
 * @return array<int, array<string, mixed>>
 */
function p0_import_extract_rows(array $payload, string $platform): array
{
    if (isset($payload['traffic']) && is_array($payload['traffic'])) {
        return array_values(array_filter($payload['traffic'], 'is_array'));
    }

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
function p0_import_row_date_source_is_context_default(array $row): bool
{
    $source = p0_import_row_date_source($row);
    return $source !== ''
        && (str_contains($source, 'default_data_date')
            || str_contains($source, 'command_date')
            || str_contains($source, 'command --date')
            || $source === 'capture_argument');
}

/**
 * @param array<string, mixed> $row
 */
function p0_import_row_date_source(array $row): string
{
    foreach (['date_source', 'dateSource', 'data_date_source', 'dataDateSource', '_date_source', '_data_date_source'] as $key) {
        $source = strtolower(trim((string)($row[$key] ?? '')));
        if ($source !== '') {
            return $source;
        }
    }

    return '';
}

/**
 * @param array<string, mixed> $row
 */
function p0_import_explicit_row_date(array $row): string
{
    if (p0_import_row_date_source_is_context_default($row)) {
        return '';
    }
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
 */
function p0_import_platform_hotel_identifier(array $row, string $platform): string
{
    $keys = $platform === 'meituan'
        ? ['poiId', 'poi_id', 'storeId', 'store_id', 'shopId', 'shop_id', 'mtPoiId', 'mt_poi_id', 'partnerId', 'partner_id']
        : ['hotelId', 'hotel_id', 'HotelId', 'hotelID', 'masterHotelId', 'master_hotel_id', 'nodeId', 'node_id'];
    foreach ($keys as $key) {
        $value = trim((string)($row[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return '';
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
 * @param array<string, mixed> $metrics
 */
function p0_import_has_nonzero_required_traffic_metric(array $metrics): bool
{
    foreach (['list_exposure', 'detail_exposure', 'flow_rate', 'order_filling_num', 'order_submit_num'] as $key) {
        if (abs((float)($metrics[$key] ?? 0)) > 0.000001) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function p0_import_preview_field_facts(array $row, array $metrics, string $platform, int $systemHotelId, string $date): array
{
    $requiredStorageFields = p0_import_required_traffic_storage_fields();
    $platformHotelIdentifier = p0_import_platform_hotel_identifier($row, $platform);
    $previewRow = [
        'source' => $platform,
        'data_type' => 'traffic',
        'data_date' => p0_import_row_date($row, $date) ?: $date,
        'hotel_id' => $platformHotelIdentifier,
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
    $rowEvidence = p0_import_desensitized_capture_evidence($row);
    $rowSourceTraceId = trim((string)($row['source_trace_id'] ?? $row['_source_trace_id'] ?? $rowEvidence['source_trace_id'] ?? ''));
    $rowSourceUrlHash = trim((string)($rowEvidence['source_url_hash'] ?? ''));
    foreach ($required as $metricKey) {
        $matched = false;
        foreach ($facts as $fact) {
            if (!is_array($fact) || (string)($fact['metric_key'] ?? '') !== $metricKey) {
                continue;
            }
            $matched = p0_import_source_path_is_structured((string)($fact['source_path'] ?? ''))
                && trim((string)($fact['storage_field'] ?? '')) === $requiredStorageFields[$metricKey]
                && ($fact['stored_value_present'] ?? null) === true
                && p0_import_fact_capture_evidence_matches_row($fact, $rowSourceTraceId, $rowSourceUrlHash);
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
        'desensitized_capture_evidence_count' => (int)($status['desensitized_capture_evidence_count'] ?? 0),
        'source_path_count' => (int)($status['source_path_count'] ?? 0),
        'structured_source_path_count' => (int)($status['structured_source_path_count'] ?? 0),
        'metric_key_count' => (int)($status['metric_key_count'] ?? 0),
        'storage_field_count' => (int)($status['storage_field_count'] ?? 0),
        'stored_value_present_count' => (int)($status['stored_value_present_count'] ?? 0),
        'stored_value_missing_count' => (int)($status['stored_value_missing_count'] ?? 0),
        'raw_data_exposed' => (bool)($status['raw_data_exposed'] ?? true),
    ];
}

/**
 * @param array<int, array<string, mixed>> $fieldFacts
 * @param array<string, mixed> $uiStatus
 * @return array<string, array<string, mixed>>
 */
function p0_import_traffic_closure_chain(array $fieldFacts, array $uiStatus, bool $platformHotelIdentifierPresent, string $platformHotelIdentifierSource): array
{
    $requiredStorageFields = p0_import_required_traffic_storage_fields();
    $requiredMetricKeys = array_keys($requiredStorageFields);
    $factByMetric = [];
    foreach ($fieldFacts as $fact) {
        if (!is_array($fact)) {
            continue;
        }
        $metricKey = trim((string)($fact['metric_key'] ?? ''));
        if ($metricKey === '' || !array_key_exists($metricKey, $requiredStorageFields)) {
            continue;
        }
        $factByMetric[$metricKey] = $fact;
    }

    $allRequiredFacts = static function (callable $predicate) use ($requiredMetricKeys, $factByMetric): bool {
        if ($requiredMetricKeys === []) {
            return false;
        }
        foreach ($requiredMetricKeys as $metricKey) {
            $fact = $factByMetric[$metricKey] ?? null;
            if (!is_array($fact) || !$predicate($fact, $metricKey)) {
                return false;
            }
        }
        return true;
    };
    $chainStatus = static fn(bool $ready): string => $ready ? 'ready' : 'incomplete';
    $metricKeyReady = $requiredMetricKeys !== [] && count($factByMetric) >= count($requiredMetricKeys);
    $sourcePathReady = $allRequiredFacts(static fn(array $fact): bool => p0_import_source_path_is_structured((string)($fact['source_path'] ?? '')));
    $captureEvidenceReady = $allRequiredFacts(static fn(array $fact): bool => p0_import_fact_has_desensitized_capture_evidence($fact));
    $storageFieldReady = $allRequiredFacts(static fn(array $fact, string $metricKey): bool => trim((string)($fact['storage_field'] ?? '')) === $requiredStorageFields[$metricKey]);
    $storedValueReady = $allRequiredFacts(static fn(array $fact): bool => ($fact['stored_value_present'] ?? null) === true);
    $uiStatusReady = (string)($uiStatus['field_fact_status'] ?? '') === 'ready'
        && (int)($uiStatus['missing_count'] ?? 0) === 0
        && (int)($uiStatus['stored_value_missing_count'] ?? 0) === 0
        && (bool)($uiStatus['raw_data_exposed'] ?? true) === false;

    return [
        'capture_evidence' => [
            'status' => $chainStatus($captureEvidenceReady),
            'required' => 'desensitized source_trace_id plus source_url_hash for every required traffic metric',
        ],
        'source_path' => [
            'status' => $chainStatus($sourcePathReady),
            'required' => 'structured source_path for every required traffic metric',
        ],
        'metric_key' => [
            'status' => $chainStatus($metricKeyReady),
            'required' => implode(',', $requiredMetricKeys),
        ],
        'storage_field' => [
            'status' => $chainStatus($storageFieldReady),
            'required' => implode(',', array_values($requiredStorageFields)),
        ],
        'stored_value' => [
            'status' => $chainStatus($storedValueReady),
            'required' => 'stored_value_present=true for every required traffic metric',
        ],
        'ui_status' => [
            'status' => $chainStatus($uiStatusReady),
            'required' => 'ready UI field_fact_status with zero missing fields and no raw_data exposure',
        ],
        'platform_hotel_identifier' => [
            'status' => $chainStatus($platformHotelIdentifierPresent),
            'required' => $platformHotelIdentifierSource,
        ],
        'verifier' => [
            'status' => 'requires_execute_and_p0_verifier',
            'required' => 'P0 complete only after --execute saves target-date traffic rows and verify:p0-ota-field-loop returns ready',
        ],
    ];
}

function p0_import_summarize_rows(array $rows, string $platform, int $systemHotelId, string $date, array $payload = []): array
{
    $required = ['list_exposure', 'detail_exposure', 'flow_rate', 'order_filling_num', 'order_submit_num'];
    $targetRows = 0;
    $defaultedDateRows = 0;
    $completeRows = 0;
    $rowsWithDesensitizedCaptureEvidence = 0;
    $rowsWithCompleteDesensitizedCaptureEvidence = 0;
    $rowsWithRowLevelDesensitizedCaptureEvidence = 0;
    $rowsWithRowLevelCompleteDesensitizedCaptureEvidence = 0;
    $rowsWithBrowserResponseEvidence = 0;
    $rowsWithBrowserDateSourceEvidence = 0;
    $rowsWithExplicitSourcePath = 0;
    $rowsWithPlatformHotelIdentifier = 0;
    $targetDateNonzeroRequiredMetricRows = 0;
    $targetDateZeroRequiredMetricRows = 0;
    $metricKeys = [];
    $sample = [];
    $payloadEvidence = p0_import_desensitized_capture_evidence($payload);
    $responseEvidence = p0_import_browser_response_evidence($payload);

    foreach ($rows as $row) {
        $rowLevelCaptureEvidence = p0_import_desensitized_capture_evidence($row);
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
        $dateSource = p0_import_row_date_source($row);
        $dateSourceProven = $dateSource !== '' && !p0_import_row_date_source_is_context_default($row);
        if ($dateSourceProven) {
            $rowsWithBrowserDateSourceEvidence++;
        }
        $explicitSourcePath = p0_import_explicit_source_path($row);
        $sourcePathStructured = p0_import_source_path_is_structured($explicitSourcePath);
        if ($sourcePathStructured) {
            $rowsWithExplicitSourcePath++;
        }
        $platformHotelIdentifier = p0_import_platform_hotel_identifier($row, $platform);
        if ($platformHotelIdentifier !== '') {
            $rowsWithPlatformHotelIdentifier++;
        }
        if (p0_import_desensitized_capture_evidence($row) !== []) {
            $rowsWithDesensitizedCaptureEvidence++;
        }
        if (p0_import_has_complete_desensitized_capture_evidence($row)) {
            $rowsWithCompleteDesensitizedCaptureEvidence++;
        }
        if ($rowLevelCaptureEvidence !== []) {
            $rowsWithRowLevelDesensitizedCaptureEvidence++;
        }
        if (trim((string)($rowLevelCaptureEvidence['source_trace_id'] ?? '')) !== ''
            && trim((string)($rowLevelCaptureEvidence['source_url_hash'] ?? '')) !== ''
        ) {
            $rowsWithRowLevelCompleteDesensitizedCaptureEvidence++;
        }
        $browserResponseEvidencePresent = p0_import_capture_evidence_matches_response($rowLevelCaptureEvidence, $responseEvidence);
        if ($browserResponseEvidencePresent) {
            $rowsWithBrowserResponseEvidence++;
        }
        $metrics = p0_import_traffic_metrics($row, $platform);
        $hasNonzeroRequiredMetric = p0_import_has_nonzero_required_traffic_metric($metrics);
        if ($hasNonzeroRequiredMetric) {
            $targetDateNonzeroRequiredMetricRows++;
        } else {
            $targetDateZeroRequiredMetricRows++;
        }
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
                'platform_hotel_identifier_present' => $platformHotelIdentifier !== '',
                'desensitized_capture_evidence_present' => p0_import_desensitized_capture_evidence($row) !== [],
                'complete_desensitized_capture_evidence_present' => p0_import_has_complete_desensitized_capture_evidence($row),
                'row_level_desensitized_capture_evidence_present' => $rowLevelCaptureEvidence !== [],
                'row_level_complete_desensitized_capture_evidence_present' => trim((string)($rowLevelCaptureEvidence['source_trace_id'] ?? '')) !== ''
                    && trim((string)($rowLevelCaptureEvidence['source_url_hash'] ?? '')) !== '',
                'browser_response_evidence_present' => $browserResponseEvidencePresent,
                'date_source' => $dateSource,
                'browser_date_source_evidence_present' => $dateSourceProven,
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
        'rows_with_complete_desensitized_capture_evidence' => $rowsWithCompleteDesensitizedCaptureEvidence,
        'missing_complete_capture_evidence_rows' => max(0, $targetRows - $rowsWithCompleteDesensitizedCaptureEvidence),
        'row_level_desensitized_capture_evidence_rows' => $rowsWithRowLevelDesensitizedCaptureEvidence,
        'missing_row_level_capture_evidence_rows' => max(0, $targetRows - $rowsWithRowLevelDesensitizedCaptureEvidence),
        'row_level_complete_desensitized_capture_evidence_rows' => $rowsWithRowLevelCompleteDesensitizedCaptureEvidence,
        'missing_row_level_complete_capture_evidence_rows' => max(0, $targetRows - $rowsWithRowLevelCompleteDesensitizedCaptureEvidence),
        'browser_response_evidence_rows' => $rowsWithBrowserResponseEvidence,
        'missing_browser_response_evidence_rows' => max(0, $targetRows - $rowsWithBrowserResponseEvidence),
        'browser_date_source_evidence_rows' => $rowsWithBrowserDateSourceEvidence,
        'missing_browser_date_source_evidence_rows' => max(0, $targetRows - $rowsWithBrowserDateSourceEvidence),
        'explicit_source_path_rows' => $rowsWithExplicitSourcePath,
        'missing_source_path_rows' => max(0, $targetRows - $rowsWithExplicitSourcePath),
        'platform_hotel_identifier_rows' => $rowsWithPlatformHotelIdentifier,
        'missing_platform_hotel_identifier_rows' => max(0, $targetRows - $rowsWithPlatformHotelIdentifier),
        'target_date_nonzero_required_metric_rows' => $targetDateNonzeroRequiredMetricRows,
        'target_date_zero_required_metric_rows' => $targetDateZeroRequiredMetricRows,
        'target_date_required_metric_value_status' => $targetDateNonzeroRequiredMetricRows > 0 ? 'ready' : ($targetRows > 0 ? 'zero_value_unverified' : 'no_target_date_traffic_rows'),
        'target_date_required_metric_value_policy' => 'Target-date P0 traffic closure requires at least one non-zero core traffic metric row; all-zero rows need explicit source-side zero confirmation before they can be treated as business-ready.',
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
        $sourcePath = p0_import_explicit_source_path($row);
        $fieldFactSourcePathCount = 0;
        $fieldFactStructuredSourcePathCount = 0;
        foreach ($fieldFacts as $fieldFact) {
            $fieldFactSourcePath = trim((string)($fieldFact['source_path'] ?? ''));
            if ($fieldFactSourcePath === '') {
                continue;
            }
            $fieldFactSourcePathCount++;
            if (p0_import_source_path_is_structured($fieldFactSourcePath)) {
                $fieldFactStructuredSourcePathCount++;
            }
        }
        $platformHotelIdentifier = p0_import_platform_hotel_identifier($row, $platform);
        $platformHotelIdentifierSource = $platform === 'meituan' ? 'poi_id_family' : 'hotel_id_family';
        $uiStatus = p0_import_external_ui_status((array)($preview['ui_status'] ?? []));

        $evidenceRows[] = [
            'platform' => $platform,
            'target_date' => $rowDate,
            'system_hotel_id' => $systemHotelId,
            'scope_policy' => 'ota_channel_only',
            'platform_hotel_identifier_present' => $platformHotelIdentifier !== '',
            'platform_hotel_identifier_source' => $platformHotelIdentifierSource,
            'source_path' => $sourcePath,
            'source_path_structured' => p0_import_source_path_is_structured($sourcePath),
            'source_trace_id' => (string)($captureEvidence['source_trace_id'] ?? ''),
            'capture_evidence' => $captureEvidence,
            'sensitive_values_exposed' => false,
            'raw_data_field_facts_present' => $fieldFacts !== [],
            'raw_data_exposed' => false,
            'field_fact_source_path_count' => $fieldFactSourcePathCount,
            'field_fact_structured_source_path_count' => $fieldFactStructuredSourcePathCount,
            'ui_status' => $uiStatus,
            'field_facts' => $fieldFacts,
            'traffic_closure_chain' => p0_import_traffic_closure_chain($fieldFacts, $uiStatus, $platformHotelIdentifier !== '', $platformHotelIdentifierSource),
            'traffic_closure_chain_policy' => 'External traffic_evidence closure chain is pre-import source proof only; P0 remains incomplete until --execute saves target-date traffic rows and verify:p0-ota-field-loop returns ready.',
        ];
    }

    return $evidenceRows;
}

function p0_import_prepare_execute_payload(array $rows, string $platform, int $systemHotelId, string $date, array $payload = []): array
{
    $payloadEvidence = p0_import_desensitized_capture_evidence($payload);
    $preparedRows = [];
    $evidenceRows = 0;
    $completeEvidenceRows = 0;
    $nonzeroRequiredMetricRows = 0;
    $zeroRequiredMetricRows = 0;

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
        if (p0_import_has_nonzero_required_traffic_metric($metrics)) {
            $nonzeroRequiredMetricRows++;
        } else {
            $zeroRequiredMetricRows++;
        }
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

        $explicitSourcePath = p0_import_explicit_source_path($row);
        if ($explicitSourcePath !== '' && trim((string)($row['_source_path'] ?? '')) === '') {
            $row['_source_path'] = $explicitSourcePath;
        }
        if (p0_import_desensitized_capture_evidence($row) !== []) {
            $evidenceRows++;
        }
        if (p0_import_has_complete_desensitized_capture_evidence($row)) {
            $completeEvidenceRows++;
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
        'rows_with_complete_desensitized_capture_evidence' => $completeEvidenceRows,
        'nonzero_required_metric_rows' => $nonzeroRequiredMetricRows,
        'zero_required_metric_rows' => $zeroRequiredMetricRows,
    ];
}

/**
 * @param array{payload:array<string, mixed>, row_count:int, rows_with_desensitized_capture_evidence:int, rows_with_complete_desensitized_capture_evidence?:int} $prepared
 * @return array<string, mixed>
 */
function p0_import_execute_plan(array $prepared): array
{
    return [
        'input_source' => 'validated_target_date_rows',
        'payload_shape' => 'data.flowData',
        'target_date_row_count' => $prepared['row_count'],
        'rows_with_desensitized_capture_evidence' => $prepared['rows_with_desensitized_capture_evidence'],
        'rows_with_complete_desensitized_capture_evidence' => (int)($prepared['rows_with_complete_desensitized_capture_evidence'] ?? 0),
        'nonzero_required_metric_rows' => (int)($prepared['nonzero_required_metric_rows'] ?? 0),
        'zero_required_metric_rows' => (int)($prepared['zero_required_metric_rows'] ?? 0),
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
 * @param array<string, mixed> $verification
 * @param array<string, array<string, bool>> $stageMetricKeys
 * @param array<int, string> $requiredMetricKeys
 * @param array<string, string> $requiredStorageFields
 * @return array<string, array<string, mixed>>
 */
function p0_import_post_execute_closure_chain(array $verification, array $stageMetricKeys, array $requiredMetricKeys, array $requiredStorageFields): array
{
    $trafficRows = (int)($verification['traffic_row_count'] ?? 0);
    $stageStatus = static function (string $stage) use ($trafficRows, $stageMetricKeys, $requiredMetricKeys): string {
        if ($trafficRows <= 0) {
            return 'no_target_date_traffic_rows';
        }
        foreach ($requiredMetricKeys as $metricKey) {
            if (empty($stageMetricKeys[$stage][$metricKey])) {
                return 'incomplete';
            }
        }
        return 'ready';
    };
    $uiStatusReady = $trafficRows > 0
        && (int)($verification['ui_status_ready_rows'] ?? 0) > 0
        && (int)($verification['ui_status_incomplete_rows'] ?? 0) === 0;
    $platformIdentifierReady = $trafficRows > 0
        && (int)($verification['platform_hotel_identifier_rows'] ?? 0) > 0
        && (int)($verification['missing_platform_hotel_identifier_rows'] ?? 0) === 0;

    return [
        'capture_evidence' => [
            'status' => $stageStatus('capture_evidence'),
            'required' => 'desensitized source_trace_id plus source_url_hash, matched to the stored traffic row and each field fact',
        ],
        'source_path' => [
            'status' => $stageStatus('source_path'),
            'required' => 'structured source_path for every required traffic metric',
        ],
        'metric_key' => [
            'status' => $stageStatus('metric_key'),
            'required' => implode(',', $requiredMetricKeys),
        ],
        'storage_field' => [
            'status' => $stageStatus('storage_field'),
            'required' => implode(',', array_values($requiredStorageFields)),
        ],
        'stored_value' => [
            'status' => $stageStatus('stored_value'),
            'required' => 'stored value present for every required traffic metric',
        ],
        'ui_status' => [
            'status' => $uiStatusReady ? 'ready' : ($trafficRows > 0 ? 'incomplete' : 'no_target_date_traffic_rows'),
            'required' => 'ready UI field_fact_status with no raw_data exposure',
        ],
        'platform_hotel_identifier' => [
            'status' => $platformIdentifierReady ? 'ready' : ($trafficRows > 0 ? 'incomplete' : 'no_target_date_traffic_rows'),
            'required' => (string)($verification['platform_hotel_identifier_source'] ?? ''),
        ],
        'verifier' => [
            'status' => (string)($verification['status'] ?? '') === 'ready' ? 'ready' : 'incomplete',
            'required' => 'post_execute_verification.status=ready and verify:p0-ota-field-loop target-date traffic gate ready',
        ],
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
        'nonzero_required_metric_rows' => 0,
        'zero_required_metric_rows' => 0,
        'required_metric_value_status' => 'not_loaded',
        'required_metric_value_policy' => 'DB readback P0 traffic closure requires at least one non-zero core traffic metric row; all-zero rows need explicit source-side zero confirmation before they can be treated as business-ready.',
        'ready_ui_status_rows' => 0,
        'ui_status_ready_rows' => 0,
        'ui_status_incomplete_rows' => 0,
        'platform_hotel_identifier_source' => $options['platform'] === 'meituan' ? 'poi_id_family' : 'hotel_id_family',
        'platform_hotel_identifier_rows' => 0,
        'missing_platform_hotel_identifier_rows' => 0,
        'complete_metric_keys' => [],
        'missing_metric_keys' => $requiredMetricKeys,
        'incomplete_metric_keys' => [],
        'ui_statuses' => [],
        'sensitive_values_exposed' => false,
        'sample_ui_statuses' => [],
        'sample_rows' => [],
        'traffic_closure_chain' => p0_import_post_execute_closure_chain([
            'status' => 'not_run',
            'traffic_row_count' => 0,
            'platform_hotel_identifier_source' => $options['platform'] === 'meituan' ? 'poi_id_family' : 'hotel_id_family',
        ], [], $requiredMetricKeys, $requiredStorageFields),
        'traffic_closure_chain_policy' => 'Post-execute closure chain is DB readback evidence only; P0 remains incomplete unless stored target-date traffic rows and verify:p0-ota-field-loop are ready.',
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
    $stageMetricKeys = [
        'capture_evidence' => [],
        'source_path' => [],
        'metric_key' => [],
        'storage_field' => [],
        'stored_value' => [],
    ];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $raw = json_decode((string)($row['raw_data'] ?? ''), true);
        $raw = is_array($raw) ? $raw : [];
        if (p0_import_sensitive_hits($raw) !== []) {
            $base['sensitive_values_exposed'] = true;
        }
        $metrics = [
            'list_exposure' => (float)($row['list_exposure'] ?? 0),
            'detail_exposure' => (float)($row['detail_exposure'] ?? 0),
            'flow_rate' => (float)($row['flow_rate'] ?? 0),
            'order_filling_num' => (float)($row['order_filling_num'] ?? 0),
            'order_submit_num' => (float)($row['order_submit_num'] ?? 0),
        ];
        if (p0_import_has_nonzero_required_traffic_metric($metrics)) {
            $base['nonzero_required_metric_rows']++;
        } else {
            $base['zero_required_metric_rows']++;
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
            && (int)($uiStatus['desensitized_capture_evidence_count'] ?? 0) >= count($requiredMetricKeys)
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
                'desensitized_capture_evidence_count' => (int)($uiStatus['desensitized_capture_evidence_count'] ?? 0),
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
        $rowEvidence = p0_import_desensitized_capture_evidence($raw);
        $rowSourceTraceId = trim((string)($row['source_trace_id'] ?? $raw['source_trace_id'] ?? $rowEvidence['source_trace_id'] ?? ''));
        $rowSourceUrlHash = trim((string)($rowEvidence['source_url_hash'] ?? ''));
        if (p0_import_platform_hotel_identifier($raw, (string)$options['platform']) !== '') {
            $base['platform_hotel_identifier_rows']++;
        } else {
            $base['missing_platform_hotel_identifier_rows']++;
        }
        foreach ($facts as $fact) {
            if (!is_array($fact)) {
                continue;
            }
            $metricKey = trim((string)($fact['metric_key'] ?? ''));
            if (!isset($requiredStorageFields[$metricKey])) {
                continue;
            }
            $stageMetricKeys['metric_key'][$metricKey] = true;
            $captureEvidence = is_array($fact['capture_evidence'] ?? null) ? (array)$fact['capture_evidence'] : [];
            if (p0_import_source_path_is_structured((string)($fact['source_path'] ?? ''))) {
                $stageMetricKeys['source_path'][$metricKey] = true;
            }
            if (trim((string)($fact['storage_field'] ?? '')) === $requiredStorageFields[$metricKey]) {
                $stageMetricKeys['storage_field'][$metricKey] = true;
            }
            if (p0_import_fact_capture_evidence_matches_row($fact, $rowSourceTraceId, $rowSourceUrlHash)) {
                $stageMetricKeys['capture_evidence'][$metricKey] = true;
            }
            if (($fact['stored_value_present'] ?? null) === true) {
                $stageMetricKeys['stored_value'][$metricKey] = true;
            }
            $factReady = p0_import_source_path_is_structured((string)($fact['source_path'] ?? ''))
                && trim((string)($fact['storage_field'] ?? '')) === $requiredStorageFields[$metricKey]
                && p0_import_fact_capture_evidence_matches_row($fact, $rowSourceTraceId, $rowSourceUrlHash)
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
                'metrics' => $metrics,
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
    $base['required_metric_value_status'] = (int)$base['nonzero_required_metric_rows'] > 0 ? 'ready' : 'zero_value_unverified';
    $base['status'] = $missingKeys === []
        && $incompleteKeys === []
        && (int)$base['rows_with_field_facts'] > 0
        && (int)$base['nonzero_required_metric_rows'] > 0
        && (int)$base['ready_ui_status_rows'] > 0
        && (int)$base['ui_status_incomplete_rows'] === 0
        && (int)$base['missing_platform_hotel_identifier_rows'] === 0
        && (int)$base['platform_hotel_identifier_rows'] > 0
        && !(bool)$base['sensitive_values_exposed']
        ? 'ready'
        : 'incomplete';
    if ((int)$base['rows_with_field_facts'] === 0) {
        $base['status'] = 'field_facts_missing';
    } elseif ((int)$base['ready_ui_status_rows'] === 0 || (int)$base['ui_status_incomplete_rows'] > 0) {
        $base['status'] = 'ui_status_incomplete';
    } elseif ((int)$base['missing_platform_hotel_identifier_rows'] > 0 || (int)$base['platform_hotel_identifier_rows'] === 0) {
        $base['status'] = 'platform_hotel_identifier_missing';
    } elseif ((int)$base['nonzero_required_metric_rows'] === 0) {
        $base['status'] = 'zero_value_unverified';
    }
    $base['traffic_closure_chain'] = p0_import_post_execute_closure_chain($base, $stageMetricKeys, $requiredMetricKeys, $requiredStorageFields);

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
    $projection = is_array($result['payload_import_projection'] ?? null) ? $result['payload_import_projection'] : [];
    if ($projection !== []) {
        $lines[] = '- payload import projection: `' . (!empty($projection['applied']) ? 'applied' : 'not_applied') . '` / `' . (string)($projection['reason'] ?? '') . '`';
        $lines[] = '- projection removed sensitive metadata: `' . (int)($projection['removed_sensitive_metadata_count'] ?? 0) . '`';
        $lines[] = '- projection dropped top-level metadata: `' . (int)($projection['dropped_top_level_key_count'] ?? 0) . '`';
    }
    $lines[] = '- sensitive values exposed: `' . (!empty($result['sensitive_values_exposed']) ? 'true' : 'false') . '`';
    $lines[] = '- defaulted date rows: `' . (int)($summary['defaulted_date_rows'] ?? 0) . '`';
    $lines[] = '- missing source path rows: `' . (int)($summary['missing_source_path_rows'] ?? 0) . '`';
    $lines[] = '- missing platform hotel identifier rows: `' . (int)($summary['missing_platform_hotel_identifier_rows'] ?? 0) . '`';
    $lines[] = '- incomplete field-fact preview rows: `' . (int)($summary['incomplete_field_fact_preview_rows'] ?? 0) . '`';
    $lines[] = '- rows with desensitized capture evidence: `' . (int)($summary['rows_with_desensitized_capture_evidence'] ?? 0) . '`';
    $lines[] = '- rows with complete desensitized capture evidence: `' . (int)($summary['rows_with_complete_desensitized_capture_evidence'] ?? 0) . '`';
    $lines[] = '- row-level desensitized capture evidence rows: `' . (int)($summary['row_level_desensitized_capture_evidence_rows'] ?? 0) . '`';
    $lines[] = '- row-level complete desensitized capture evidence rows: `' . (int)($summary['row_level_complete_desensitized_capture_evidence_rows'] ?? 0) . '`';
    $lines[] = '- browser response evidence rows: `' . (int)($summary['browser_response_evidence_rows'] ?? 0) . '`';
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
    $trafficEvidenceContract = is_array($result['traffic_evidence_contract'] ?? null) ? $result['traffic_evidence_contract'] : [];
    if ($trafficEvidenceContract !== []) {
        $lines[] = '- traffic evidence contract: `' . (string)($trafficEvidenceContract['status'] ?? 'unknown') . '`';
        $lines[] = '- traffic evidence verifier command: `' . (string)($trafficEvidenceContract['verifier_command'] ?? '') . '`';
        $lines[] = '- traffic evidence completion policy: `' . (string)($trafficEvidenceContract['completion_policy'] ?? '') . '`';
    }
    $lines[] = '- completion policy: `' . (string)($result['completion_policy'] ?? '') . '`';
    $lines[] = '- next verifier command: `' . (string)($result['next_verifier_command'] ?? '') . '`';
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
    $rawPayload = p0_import_read_payload((string)$options['payload']);
    $payloadProjection = p0_import_project_payload_for_import($rawPayload);
    $payload = (array)$payloadProjection['payload'];
    $payloadProjectionMetadata = (array)$payloadProjection['metadata'];
    $sensitiveHits = p0_import_sensitive_hits($payload);
    $rows = p0_import_extract_rows($payload, (string)$options['platform']);
    $summary = p0_import_summarize_rows($rows, (string)$options['platform'], (int)$options['system-hotel-id'], (string)$options['date'], $payload);
    $preparedExecute = p0_import_prepare_execute_payload($rows, (string)$options['platform'], (int)$options['system-hotel-id'], (string)$options['date'], $payload);
    $executePlan = p0_import_execute_plan($preparedExecute);

    $issues = p0_import_payload_scope_issues($payload, (string)$options['platform'], (int)$options['system-hotel-id']);
    $warnings = p0_import_payload_warnings($payload);
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
    if ((int)$summary['target_date_rows'] > 0 && (int)($summary['missing_platform_hotel_identifier_rows'] ?? 0) > 0) {
        $issues[] = [
            'code' => 'target_date_platform_hotel_identifier_missing',
            'message' => 'Every target-date traffic row must carry a platform hotel identifier such as hotelId/hotel_id or poiId/poi_id; system_hotel_id is only the local scope and cannot replace OTA source identity.',
            'missing_platform_hotel_identifier_rows' => (int)$summary['missing_platform_hotel_identifier_rows'],
        ];
    }
    if ((array)$summary['missing_metric_keys'] !== []) {
        $issues[] = [
            'code' => 'required_traffic_metric_keys_missing',
            'message' => 'Payload rows do not prove every required traffic metric key.',
            'missing_metric_keys' => $summary['missing_metric_keys'],
        ];
    }
    if ((int)$summary['target_date_rows'] > 0 && (int)($summary['target_date_nonzero_required_metric_rows'] ?? 0) <= 0) {
        $issues[] = [
            'code' => 'target_date_required_traffic_metrics_zero_unverified',
            'message' => 'Target-date traffic rows carry no non-zero required traffic metric; all-zero core metrics are not accepted as P0 traffic closure without explicit source-side zero confirmation.',
            'target_date_rows' => (int)$summary['target_date_rows'],
            'target_date_zero_required_metric_rows' => (int)($summary['target_date_zero_required_metric_rows'] ?? 0),
            'target_date_required_metric_value_policy' => (string)($summary['target_date_required_metric_value_policy'] ?? ''),
        ];
    }
    if ((int)$summary['target_date_rows'] > 0 && (int)($summary['incomplete_field_fact_preview_rows'] ?? 0) > 0) {
        $issues[] = [
            'code' => 'traffic_field_fact_preview_rows_incomplete',
            'message' => 'Every target-date traffic row must preview as a complete field loop before import; cross-row metric coverage is not accepted.',
            'incomplete_field_fact_preview_rows' => (int)$summary['incomplete_field_fact_preview_rows'],
        ];
    }
    if ((int)$summary['target_date_rows'] > 0 && (int)($summary['missing_complete_capture_evidence_rows'] ?? 0) > 0) {
        $issues[] = [
            'code' => 'desensitized_capture_evidence_missing',
            'message' => 'Target-date traffic rows must include complete desensitized capture evidence: source_trace_id plus source_url_hash/url_hash.',
            'missing_capture_evidence_rows' => (int)($summary['missing_complete_capture_evidence_rows'] ?? 0),
            'rows_with_desensitized_capture_evidence' => (int)($summary['rows_with_desensitized_capture_evidence'] ?? 0),
            'rows_with_complete_desensitized_capture_evidence' => (int)($summary['rows_with_complete_desensitized_capture_evidence'] ?? 0),
        ];
    }
    if (p0_import_payload_is_browser_capture($payload)
        && (int)$summary['target_date_rows'] > 0
        && (int)($summary['missing_row_level_complete_capture_evidence_rows'] ?? 0) > 0
    ) {
        $issues[] = [
            'code' => 'browser_capture_row_capture_evidence_missing',
            'message' => 'Browser capture traffic rows must carry row-level source_trace_id plus source_url_hash/url_hash evidence; payload-level evidence is not sufficient for Profile capture imports.',
            'missing_row_level_capture_evidence_rows' => (int)($summary['missing_row_level_complete_capture_evidence_rows'] ?? 0),
            'row_level_desensitized_capture_evidence_rows' => (int)($summary['row_level_desensitized_capture_evidence_rows'] ?? 0),
            'row_level_complete_desensitized_capture_evidence_rows' => (int)($summary['row_level_complete_desensitized_capture_evidence_rows'] ?? 0),
        ];
    }
    if (p0_import_payload_is_browser_capture($payload)
        && (int)$summary['target_date_rows'] > 0
        && (int)($summary['missing_browser_date_source_evidence_rows'] ?? 0) > 0
    ) {
        $issues[] = [
            'code' => 'browser_capture_row_date_source_missing',
            'message' => 'Browser capture traffic rows must carry row-level date_source evidence from the response row or request URL/payload; capture_context.default_data_date is not accepted.',
            'missing_browser_date_source_evidence_rows' => (int)$summary['missing_browser_date_source_evidence_rows'],
        ];
    }
    if (p0_import_payload_is_browser_capture($payload)
        && (int)$summary['target_date_rows'] > 0
        && (int)($summary['missing_browser_response_evidence_rows'] ?? 0) > 0
    ) {
        $issues[] = [
            'code' => 'browser_capture_response_evidence_missing',
            'message' => 'Browser capture traffic rows must match desensitized response evidence in responses[] by source_trace_id or source_url_hash/url_hash.',
            'missing_browser_response_evidence_rows' => (int)$summary['missing_browser_response_evidence_rows'],
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
    $p0CompletionStatus = 'blocked_not_p0_complete';
    if ($status === 'ready_to_import') {
        $p0CompletionStatus = 'pre_import_ready_not_p0_complete';
    } elseif ($status === 'imported') {
        $p0CompletionStatus = 'imported_post_execute_readback_ready_requires_p0_verifier';
    }

    $result = array_merge([
        'script' => 'scripts/import_p0_ota_traffic_payload.php',
        'status' => $status,
        'p0_completion_status' => $p0CompletionStatus,
        'p0_completion_gate' => 'P0 complete only when --execute saves target-date traffic rows and a separate verify:p0-ota-field-loop run returns ready.',
        'post_execute_readback_policy' => 'Importer post_execute_verification is DB readback evidence only; final P0 closure still requires npm.cmd run verify:p0-ota-field-loop on the target date.',
        'mode' => (bool)$options['execute'] ? 'execute' : 'dry_run',
        'platform' => $options['platform'],
        'date' => $options['date'],
        'system_hotel_id' => $options['system-hotel-id'],
        'payload_path' => $options['payload'],
        'scope_policy' => 'ota_channel_only',
        'target_storage_table' => 'online_daily_data',
        'target_data_type' => 'traffic',
        'payload_import_projection' => $payloadProjectionMetadata,
        'sensitive_values_exposed' => $sensitiveHits !== [],
        'summary' => $summary,
        'execute_plan' => $executePlan,
        'traffic_evidence' => $trafficEvidence,
        'traffic_evidence_contract' => [
            'status' => $trafficEvidence !== [] && $issues === [] ? 'ready_for_p0_verifier_external_evidence' : 'blocked_or_not_available',
            'verifier_command' => 'npm.cmd run verify:p0-ota-field-loop -- --date=' . (string)$options['date'] . ' --platform=' . (string)$options['platform'] . ' --system-hotel-id=' . (int)$options['system-hotel-id'] . ' --traffic-evidence=<this-json-output>',
            'completion_policy' => 'External traffic_evidence validates desensitized source proof only; P0 closure still requires --execute import plus verify:p0-ota-field-loop target-date traffic rows.',
        ],
        'warnings' => $warnings,
        'issues' => $issues,
        'completion_policy' => 'Import is only accepted as P0 closure after verify:p0-ota-field-loop proves target-date traffic rows and traffic field facts.',
        'next_verifier_command' => 'npm.cmd run verify:p0-ota-field-loop -- --date=' . (string)$options['date'] . ' --platform=' . (string)$options['platform'] . ' --system-hotel-id=' . (int)$options['system-hotel-id'],
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
