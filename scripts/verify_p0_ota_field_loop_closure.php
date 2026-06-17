<?php
declare(strict_types=1);

use app\controller\OnlineData;
use think\App;
use think\facade\Db;

$root = dirname(__DIR__);

/**
 * @param array<int, string> $argv
 * @return array<string, mixed>
 */
function p0_parse_args(array $argv): array
{
    $options = [
        'date' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d'),
        'platform' => '',
        'system-hotel-id' => '',
        'system_hotel_id' => '',
        'limit' => 5000,
        'format' => 'json',
        'traffic-evidence' => '',
        'evidence' => '',
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
            continue;
        }
        [$key, $value] = explode('=', substr($arg, 2), 2);
        if (!array_key_exists($key, $options)) {
            continue;
        }
        $options[$key] = $key === 'limit' ? max(1, min(5000, (int)$value)) : trim($value);
    }

    if ((string)$options['system-hotel-id'] === '' && (string)$options['system_hotel_id'] !== '') {
        $options['system-hotel-id'] = (string)$options['system_hotel_id'];
    }
    unset($options['system_hotel_id']);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$options['date'])) {
        throw new InvalidArgumentException('Invalid --date, expected YYYY-MM-DD.');
    }
    if ((string)$options['system-hotel-id'] !== '') {
        if (!is_numeric($options['system-hotel-id']) || (int)$options['system-hotel-id'] <= 0) {
            throw new InvalidArgumentException('Invalid --system-hotel-id, expected a positive integer.');
        }
        $options['system-hotel-id'] = (int)$options['system-hotel-id'];
    }
    if (!in_array((string)$options['format'], ['json', 'markdown'], true)) {
        throw new InvalidArgumentException('Invalid --format, expected json or markdown.');
    }

    return $options;
}

/**
 * @return array<int, string>
 */
function p0_expected_platforms(string $value): array
{
    if (trim($value) === '') {
        return ['ctrip', 'meituan'];
    }

    $platforms = array_values(array_unique(array_filter(array_map(
        static fn(string $platform): string => strtolower(trim($platform)),
        explode(',', $value)
    ))));

    foreach ($platforms as $platform) {
        if (!in_array($platform, ['ctrip', 'meituan'], true)) {
            throw new InvalidArgumentException('Unsupported --platform, expected ctrip and/or meituan.');
        }
    }

    return $platforms;
}

/**
 * @param array<int, string> $args
 * @return array{exit_code:int, stdout:string, stderr:string}
 */
function p0_run_process(array $args, string $cwd): array
{
    $command = implode(' ', array_map('escapeshellarg', $args));
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptors, $pipes, $cwd);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start inspector process.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'exit_code' => proc_close($process),
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
}

/**
 * @param array<string, mixed> $options
 * @param array<int, string> $platforms
 * @return array<string, mixed>
 */
function p0_run_inspector(array $options, array $platforms): array
{
    global $root;

    $args = [
        PHP_BINARY,
        $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'inspect_phase1_ota_live_closure.php',
        '--format=json',
        '--date=' . $options['date'],
        '--limit=' . $options['limit'],
    ];

    if (count($platforms) === 1) {
        $args[] = '--platform=' . $platforms[0];
    }
    if ((int)($options['system-hotel-id'] ?? 0) > 0) {
        $args[] = '--system_hotel_id=' . (int)$options['system-hotel-id'];
    }
    if (trim((string)($options['evidence'] ?? '')) !== '') {
        $args[] = '--evidence=' . trim((string)$options['evidence']);
    }

    $result = p0_run_process($args, $root);
    if ($result['exit_code'] !== 0) {
        return [
            'status' => 'failed',
            'issue' => [
                'code' => 'live_closure_inspector_failed',
                'message' => 'inspect_phase1_ota_live_closure.php returned a non-zero exit code.',
                'exit_code' => $result['exit_code'],
                'stderr' => trim($result['stderr']),
            ],
        ];
    }

    $decoded = json_decode(trim($result['stdout']), true);
    if (!is_array($decoded)) {
        return [
            'status' => 'failed',
            'issue' => [
                'code' => 'live_closure_inspector_invalid_json',
                'message' => 'inspect_phase1_ota_live_closure.php did not return valid JSON.',
                'json_error' => json_last_error_msg(),
                'stderr' => trim($result['stderr']),
            ],
        ];
    }

    return $decoded;
}

/**
 * @return array<string, string>
 */
function p0_package_scripts(): array
{
    global $root;

    $package = json_decode((string)file_get_contents($root . DIRECTORY_SEPARATOR . 'package.json'), true);
    if (!is_array($package) || !is_array($package['scripts'] ?? null)) {
        return [];
    }

    $scripts = [];
    foreach ($package['scripts'] as $name => $command) {
        $scripts[(string)$name] = (string)$command;
    }
    return $scripts;
}

function p0_source_contains(string $path, string $needle): bool
{
    global $root;

    $fullPath = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    return is_file($fullPath) && str_contains((string)file_get_contents($fullPath), $needle);
}

/**
 * @param array<int, string> $paths
 */
function p0_source_contains_any(array $paths, string $needle): bool
{
    foreach ($paths as $path) {
        if (p0_source_contains($path, $needle)) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<int, array<string, mixed>> $checks
 */
function p0_add_global_check(array &$checks, string $code, bool $passed, string $message, string $failureSeverity = 'failed'): void
{
    $checks[] = [
        'code' => $code,
        'status' => $passed ? 'passed' : 'failed',
        'message' => $message,
        'failure_severity' => in_array($failureSeverity, ['failed', 'incomplete'], true) ? $failureSeverity : 'failed',
    ];
}

/**
 * @param array<int, array<string, mixed>> $issues
 */
function p0_add_issue(array &$issues, string $severity, string $code, string $message, array $details = []): void
{
    $issue = [
        'severity' => $severity,
        'code' => $code,
        'message' => $message,
    ];
    if ($details !== []) {
        $issue['details'] = $details;
    }
    $issues[] = $issue;
}

/**
 * @param array<string, mixed> $stage
 * @param array<int, array<string, mixed>> $issues
 */
function p0_require_stage(array &$stage, array &$issues, string $platform, string $code, bool $passed, string $message, array $details = []): void
{
    $stage[$code] = [
        'status' => $passed ? 'passed' : 'missing',
        'message' => $message,
    ];
    if ($details !== []) {
        $stage[$code]['details'] = $details;
    }
    if (!$passed) {
        p0_add_issue($issues, 'incomplete', $platform . '_' . $code . '_missing', $message, $details);
    }
}

/**
 * @return array<string, mixed>
 */
function p0_array(mixed $value): array
{
    return is_array($value) ? $value : [];
}

/**
 * @return array<int, string>
 */
function p0_required_traffic_metric_keys(): array
{
    return [
        'list_exposure',
        'detail_exposure',
        'flow_rate',
        'order_filling_num',
        'order_submit_num',
    ];
}

/**
 * @return array<string, string>
 */
function p0_required_traffic_storage_field_map(): array
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
 * @param array<int|string, mixed> $data
 * @return array<int, array<string, mixed>>
 */
function p0_collect_external_traffic_evidence_rows(array $data): array
{
    $rows = [];

    $appendRows = static function (mixed $value, ?string $platformHint = null) use (&$rows, &$appendRows): void {
        if (!is_array($value)) {
            return;
        }
        if (isset($value['traffic_evidence']) && is_array($value['traffic_evidence'])) {
            foreach ($value['traffic_evidence'] as $nestedKey => $nestedValue) {
                $appendRows($nestedValue, is_string($nestedKey) ? $nestedKey : $platformHint);
            }
            return;
        }
        if (isset($value['field_facts']) || isset($value['capture_evidence']) || isset($value['metric_key'])) {
            if ($platformHint !== null && !isset($value['platform'])) {
                $value['platform'] = $platformHint;
            }
            $rows[] = $value;
            return;
        }
        foreach ($value as $nestedKey => $nestedValue) {
            $appendRows($nestedValue, is_string($nestedKey) ? $nestedKey : $platformHint);
        }
    };

    $appendRows($data);

    return $rows;
}

/**
 * @param string $segment
 */
function p0_external_normalize_sensitive_key_segment(string $segment): string
{
    $normalized = strtolower((string)preg_replace('/(?<!^)[A-Z]/', '_$0', $segment));
    $normalized = (string)preg_replace('/[^a-z0-9]+/', '_', $normalized);
    return trim($normalized, '_');
}

/**
 * @param string $segment
 */
function p0_external_is_raw_url_key(string $segment): bool
{
    $normalized = p0_external_normalize_sensitive_key_segment($segment);
    return $normalized === 'url'
        || $normalized === 'endpoint'
        || $normalized === 'request_uri'
        || str_ends_with($normalized, '_url');
}

function p0_external_is_raw_url_value(string $value): bool
{
    return preg_match('#https?://#i', $value) === 1;
}

function p0_external_is_sensitive_metadata_key(string $segment): bool
{
    $normalized = p0_external_normalize_sensitive_key_segment($segment);
    return preg_match('/(^|_)(cookie|token|spider_token|authorization|password|secret|session|csrf)($|_)/i', $normalized) === 1
        || preg_match('/(^|_)profile_(path|dir|directory)($|_)/i', $normalized) === 1
        || preg_match('/(^|_)raw_(cookie|token|profile)($|_)/i', $normalized) === 1
        || p0_external_is_raw_url_key($segment);
}

function p0_source_path_is_structured(string $sourcePath): bool
{
    $sourcePath = trim($sourcePath);
    return $sourcePath !== ''
        && (str_contains($sourcePath, '.') || str_contains($sourcePath, '[') || str_contains($sourcePath, '/'));
}

function p0_external_source_path_is_structured(string $sourcePath): bool
{
    return p0_source_path_is_structured($sourcePath);
}

/**
 * @param array<int|string, mixed> $value
 * @return array<int, array{path:string, reason:string}>
 */
function p0_find_sensitive_external_values(array $value, string $path = ''): array
{
    $hits = [];
    foreach ($value as $key => $item) {
        $segment = is_string($key) ? $key : (string)$key;
        $nextPath = $path === '' ? $segment : $path . '.' . $segment;
        if (p0_external_is_sensitive_metadata_key($segment)) {
            $hits[] = ['path' => $nextPath, 'reason' => 'sensitive_key_present'];
        }
        if (is_string($item)) {
            $trimmed = trim($item);
            if (preg_match('/\b(Bearer|Cookie|Authorization)\s*[:=]/i', $trimmed)
                || preg_match('/spidertoken|sess|csrf|access[_-]?token|refresh[_-]?token/i', $trimmed)
                || p0_external_is_raw_url_value($trimmed)
            ) {
                $hits[] = ['path' => $nextPath, 'reason' => 'sensitive_value_pattern'];
            }
        } elseif (is_array($item)) {
            foreach (p0_find_sensitive_external_values($item, $nextPath) as $hit) {
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
 * @param array<string, mixed> $source
 * @return array<string, string>
 */
function p0_external_desensitized_capture_evidence(array $source): array
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
            $value = $source[$key] ?? null;
            if (is_scalar($value) && trim((string)$value) !== '') {
                $evidence[$target] = mb_substr((string)$value, 0, 300);
                break;
            }
        }
    }

    $nested = $source['capture_evidence'] ?? null;
    if (is_array($nested)) {
        foreach (p0_external_desensitized_capture_evidence($nested) as $key => $value) {
            if (!isset($evidence[$key])) {
                $evidence[$key] = $value;
            }
        }
    }

    return $evidence;
}

function p0_field_fact_capture_evidence_matches_row(array $fact, string $rowSourceTraceId = '', string $rowSourceUrlHash = ''): bool
{
    $captureEvidence = p0_array($fact['capture_evidence'] ?? null);
    if ($captureEvidence === []) {
        return false;
    }

    $desensitized = p0_external_desensitized_capture_evidence($captureEvidence);
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
 * @param array<string, mixed> $row
 * @param array<string, mixed> $data
 * @param array<int, string> $expectedPlatforms
 * @return array<string, mixed>
 */
function p0_validate_external_traffic_evidence_row(array $row, array $data, array $expectedPlatforms, string $targetDate, int $systemHotelId = 0): array
{
    $requiredMetricKeys = p0_required_traffic_metric_keys();
    $storageMap = p0_required_traffic_storage_field_map();
    $expectedPlatformMap = array_fill_keys($expectedPlatforms, true);
    $issues = [];
    $presentMetricKeys = [];
    $sourcePaths = [];
    $storageFields = [];

    $scope = p0_array($data['scope'] ?? null);
    $platform = strtolower(trim((string)($row['platform'] ?? $scope['platform'] ?? '')));
    if ($platform === '' || !isset($expectedPlatformMap[$platform])) {
        $issues[] = [
            'code' => 'platform_not_in_scope',
            'message' => 'Evidence row platform must match --platform scope.',
            'platform' => $platform,
        ];
    }

    $rowDate = trim((string)($row['target_date'] ?? $scope['date'] ?? ''));
    if ($rowDate !== $targetDate) {
        $issues[] = [
            'code' => 'target_date_mismatch',
            'message' => 'Evidence row target_date must match verifier --date.',
            'target_date' => $rowDate,
            'expected_date' => $targetDate,
        ];
    }

    $rowSystemHotelId = (int)($row['system_hotel_id'] ?? $scope['system_hotel_id'] ?? $data['system_hotel_id'] ?? 0);
    if ($systemHotelId > 0 && $rowSystemHotelId !== $systemHotelId) {
        $issues[] = [
            'code' => 'system_hotel_id_mismatch',
            'message' => 'Evidence row system_hotel_id must match verifier --system-hotel-id.',
            'system_hotel_id' => $rowSystemHotelId,
            'expected_system_hotel_id' => $systemHotelId,
        ];
    }

    $scopePolicy = trim((string)($row['scope_policy'] ?? $row['source_scope'] ?? $scope['source_scope'] ?? $scope['scope_policy'] ?? ''));
    if ($scopePolicy !== 'ota_channel_only') {
        $issues[] = [
            'code' => 'scope_policy_not_ota_channel_only',
            'message' => 'External traffic evidence must keep OTA channel scope explicit.',
            'scope_policy' => $scopePolicy,
        ];
    }

    $platformHotelIdentifierPresent = $row['platform_hotel_identifier_present'] ?? null;
    $platformHotelIdentifierSource = trim((string)($row['platform_hotel_identifier_source'] ?? ''));
    $expectedHotelIdentifierSource = p0_platform_hotel_identifier_source($platform);
    if ($platformHotelIdentifierPresent !== true) {
        $issues[] = [
            'code' => 'platform_hotel_identifier_missing',
            'message' => 'External traffic evidence must prove the OTA platform hotel identifier is present without exposing the raw identifier.',
            'expected_source' => $expectedHotelIdentifierSource,
        ];
    } elseif ($platformHotelIdentifierSource !== $expectedHotelIdentifierSource) {
        $issues[] = [
            'code' => 'platform_hotel_identifier_source_invalid',
            'message' => 'External traffic evidence must identify the expected OTA platform hotel identifier source family without exposing the raw identifier.',
            'source' => $platformHotelIdentifierSource,
            'expected_source' => $expectedHotelIdentifierSource,
        ];
    }

    $sensitiveDeclared = $row['sensitive_values_exposed'] ?? null;
    if ($sensitiveDeclared !== false) {
        $issues[] = [
            'code' => 'sensitive_values_exposed_not_false',
            'message' => 'Evidence row must declare sensitive_values_exposed=false.',
        ];
    }
    $sensitiveHits = p0_find_sensitive_external_values($row);
    if ($sensitiveHits !== []) {
        $issues[] = [
            'code' => 'sensitive_material_present',
            'message' => 'Evidence row contains sensitive-looking keys or values.',
            'hits' => $sensitiveHits,
        ];
    }

    $captureEvidence = p0_array($row['capture_evidence'] ?? null);
    $desensitizedRowCaptureEvidence = p0_external_desensitized_capture_evidence($captureEvidence);
    $rowSourceTraceId = trim((string)($row['source_trace_id'] ?? $desensitizedRowCaptureEvidence['source_trace_id'] ?? ''));
    $rowSourceUrlHash = trim((string)($desensitizedRowCaptureEvidence['source_url_hash'] ?? ''));
    if ($rowSourceTraceId === '') {
        $issues[] = [
            'code' => 'source_trace_id_missing',
            'message' => 'Evidence row must include a desensitized source_trace_id.',
        ];
    }
    if ($rowSourceUrlHash === '') {
        $issues[] = [
            'code' => 'source_url_hash_missing',
            'message' => 'Evidence row must include capture_evidence.source_url_hash/url_hash instead of raw source_url.',
        ];
    }

    $fieldFacts = p0_array($row['field_facts'] ?? null);
    if ($fieldFacts === []) {
        $issues[] = [
            'code' => 'field_facts_missing',
            'message' => 'Evidence row must include field_facts for every required traffic metric.',
        ];
    }
    foreach ($fieldFacts as $fact) {
        if (!is_array($fact)) {
            continue;
        }
        $metricKey = trim((string)($fact['metric_key'] ?? ''));
        $sourcePath = trim((string)($fact['source_path'] ?? ''));
        $storageField = trim((string)($fact['storage_field'] ?? ''));
        if ($metricKey !== '') {
            $presentMetricKeys[$metricKey] = true;
        }
        if ($sourcePath !== '') {
            $sourcePaths[$sourcePath] = true;
        }
        if ($storageField !== '') {
            $storageFields[$storageField] = true;
        }
        if ($metricKey === '' || !isset($storageMap[$metricKey])) {
            $issues[] = [
                'code' => 'metric_key_invalid',
                'message' => 'field_facts[].metric_key must be one of the required traffic metrics.',
                'metric_key' => $metricKey,
            ];
        }
        if ($sourcePath === '') {
            $issues[] = [
                'code' => 'source_path_missing',
                'message' => 'field_facts[].source_path is required.',
                'metric_key' => $metricKey,
            ];
        } elseif (!p0_external_source_path_is_structured($sourcePath)) {
            $issues[] = [
                'code' => 'source_path_not_structured',
                'message' => 'field_facts[].source_path must identify a structured source location, not only a field name.',
                'metric_key' => $metricKey,
                'source_path' => $sourcePath,
            ];
        }
        if ($storageField === '' || !str_starts_with($storageField, 'online_daily_data.')) {
            $issues[] = [
                'code' => 'storage_field_invalid',
                'message' => 'field_facts[].storage_field must target online_daily_data.',
                'metric_key' => $metricKey,
                'storage_field' => $storageField,
            ];
        }
        if ($metricKey !== '' && isset($storageMap[$metricKey]) && $storageField !== $storageMap[$metricKey]) {
            $issues[] = [
                'code' => 'storage_field_mismatch',
                'message' => 'field_facts[].storage_field must match the expected storage field for metric_key.',
                'metric_key' => $metricKey,
                'storage_field' => $storageField,
                'expected_storage_field' => $storageMap[$metricKey],
            ];
        }
        $factCaptureEvidence = p0_array($fact['capture_evidence'] ?? null);
        $desensitizedFactCaptureEvidence = p0_external_desensitized_capture_evidence($factCaptureEvidence);
        if ($desensitizedFactCaptureEvidence === []) {
            $issues[] = [
                'code' => 'field_fact_capture_evidence_missing',
                'message' => 'field_facts[].capture_evidence must include desensitized capture evidence for its metric.',
                'metric_key' => $metricKey,
            ];
        }
        $factSourceTraceId = trim((string)($desensitizedFactCaptureEvidence['source_trace_id'] ?? ''));
        if ($factSourceTraceId === '') {
            $issues[] = [
                'code' => 'field_fact_source_trace_id_missing',
                'message' => 'field_facts[].capture_evidence.source_trace_id must prove the same response trace as the traffic row.',
                'metric_key' => $metricKey,
            ];
        } elseif ($rowSourceTraceId !== '' && $factSourceTraceId !== $rowSourceTraceId) {
            $issues[] = [
                'code' => 'field_fact_source_trace_id_mismatch',
                'message' => 'field_facts[].capture_evidence.source_trace_id must match the traffic row source_trace_id.',
                'metric_key' => $metricKey,
                'source_trace_id' => $factSourceTraceId,
                'expected_source_trace_id' => $rowSourceTraceId,
            ];
        }
        $factSourceUrlHash = trim((string)($desensitizedFactCaptureEvidence['source_url_hash'] ?? ''));
        if ($factSourceUrlHash === '') {
            $issues[] = [
                'code' => 'field_fact_source_url_hash_missing',
                'message' => 'field_facts[].capture_evidence.source_url_hash must prove the same source response as the traffic row.',
                'metric_key' => $metricKey,
            ];
        } elseif ($rowSourceUrlHash !== '' && $factSourceUrlHash !== $rowSourceUrlHash) {
            $issues[] = [
                'code' => 'field_fact_source_url_hash_mismatch',
                'message' => 'field_facts[].capture_evidence.source_url_hash must match the traffic row capture_evidence.source_url_hash.',
                'metric_key' => $metricKey,
                'source_url_hash' => $factSourceUrlHash,
                'expected_source_url_hash' => $rowSourceUrlHash,
            ];
        }
        if (!array_key_exists('stored_value_present', $fact)) {
            $issues[] = [
                'code' => 'stored_value_present_missing',
                'message' => 'field_facts[].stored_value_present must be explicit; it is not treated as DB proof.',
                'metric_key' => $metricKey,
            ];
        } elseif (($fact['stored_value_present'] ?? null) !== true) {
            $issues[] = [
                'code' => 'stored_value_present_not_true',
                'message' => 'field_facts[].stored_value_present must be true for external traffic evidence.',
                'metric_key' => $metricKey,
            ];
        }
    }

    $uiStatus = p0_array($row['ui_status'] ?? $row['field_fact_ui_status'] ?? null);
    $uiFieldFactStatus = trim((string)($uiStatus['field_fact_status'] ?? $uiStatus['status'] ?? ''));
    if ($uiStatus === []) {
        $issues[] = [
            'code' => 'ui_status_missing',
            'message' => 'Evidence row must include the UI field fact status for the traffic row.',
        ];
    } elseif ($uiFieldFactStatus !== 'ready') {
        $issues[] = [
            'code' => 'ui_status_not_ready',
            'message' => 'Evidence row UI field_fact_status must be ready.',
            'field_fact_status' => $uiFieldFactStatus,
        ];
    }
    if (($uiStatus['raw_data_exposed'] ?? null) !== false) {
        $issues[] = [
            'code' => 'ui_status_raw_data_exposed_not_false',
            'message' => 'Evidence row UI status must explicitly keep raw_data_exposed=false.',
        ];
    }
    $requiredUiCounts = [
        'captured_count' => count($requiredMetricKeys),
        'capture_evidence_count' => count($requiredMetricKeys),
        'desensitized_capture_evidence_count' => count($requiredMetricKeys),
        'source_path_count' => count($requiredMetricKeys),
        'structured_source_path_count' => count($requiredMetricKeys),
        'metric_key_count' => count($requiredMetricKeys),
        'storage_field_count' => count($requiredMetricKeys),
        'stored_value_present_count' => count($requiredMetricKeys),
    ];
    $uiCountsReady = true;
    foreach ($requiredUiCounts as $key => $minimum) {
        if (!array_key_exists($key, $uiStatus) || (int)$uiStatus[$key] < $minimum) {
            $uiCountsReady = false;
            $issues[] = [
                'code' => 'ui_status_count_incomplete',
                'message' => 'Evidence row UI status counts must cover every required traffic metric.',
                'field' => $key,
                'value' => $uiStatus[$key] ?? null,
                'minimum' => $minimum,
            ];
        }
    }
    $uiMissingCountsReady = true;
    if (!array_key_exists('missing_count', $uiStatus) || (int)$uiStatus['missing_count'] !== 0
        || !array_key_exists('stored_value_missing_count', $uiStatus) || (int)$uiStatus['stored_value_missing_count'] !== 0
    ) {
        $uiMissingCountsReady = false;
        $issues[] = [
            'code' => 'ui_status_missing_counts_not_zero',
            'message' => 'Evidence row UI status must keep missing_count and stored_value_missing_count at zero.',
            'missing_count' => $uiStatus['missing_count'] ?? null,
            'stored_value_missing_count' => $uiStatus['stored_value_missing_count'] ?? null,
        ];
    }

    $closureChainValidation = p0_validate_external_traffic_closure_chain($row, $expectedHotelIdentifierSource);
    foreach ((array)($closureChainValidation['issues'] ?? []) as $issue) {
        if (is_array($issue)) {
            $issues[] = $issue;
        }
    }

    $missingMetricKeys = array_values(array_diff($requiredMetricKeys, array_keys($presentMetricKeys)));
    foreach ($missingMetricKeys as $metricKey) {
        $issues[] = [
            'code' => 'required_metric_key_missing',
            'message' => 'External traffic evidence is missing a required traffic metric.',
            'metric_key' => $metricKey,
        ];
    }

    return [
        'platform' => $platform,
        'target_date' => $rowDate,
        'system_hotel_id' => $rowSystemHotelId,
        'status' => $issues === [] ? 'valid' : 'invalid',
        'validated_desensitized_evidence_present' => $issues === [],
        'metric_keys' => array_values(array_keys($presentMetricKeys)),
        'missing_metric_keys' => $missingMetricKeys,
        'source_paths' => array_values(array_keys($sourcePaths)),
        'storage_fields' => array_values(array_keys($storageFields)),
        'ui_status' => $uiFieldFactStatus,
        'ui_status_ready' => $uiFieldFactStatus === 'ready'
            && ($uiStatus['raw_data_exposed'] ?? null) === false
            && (int)($uiStatus['missing_count'] ?? -1) === 0
            && (int)($uiStatus['stored_value_missing_count'] ?? -1) === 0
            && $uiCountsReady
            && $uiMissingCountsReady,
        'platform_hotel_identifier_present' => $platformHotelIdentifierPresent === true,
        'platform_hotel_identifier_source' => $platformHotelIdentifierSource,
        'source_trace_id_present' => $rowSourceTraceId !== '',
        'source_url_hash_present' => $rowSourceUrlHash !== '',
        'traffic_closure_chain_ready' => (bool)($closureChainValidation['ready'] ?? false),
        'sensitive_values_exposed' => $sensitiveDeclared !== false || $sensitiveHits !== [],
        'issues' => $issues,
    ];
}

/**
 * @return array<int, string>
 */
function p0_external_traffic_closure_chain_required_keys(): array
{
    return [
        'capture_evidence',
        'source_path',
        'metric_key',
        'storage_field',
        'stored_value',
        'ui_status',
        'platform_hotel_identifier',
        'verifier',
    ];
}

/**
 * @param array<string, mixed> $row
 * @return array{ready:bool,issues:array<int, array<string, mixed>>}
 */
function p0_validate_external_traffic_closure_chain(array $row, string $expectedHotelIdentifierSource): array
{
    $issues = [];
    $chain = p0_array($row['traffic_closure_chain'] ?? null);
    $requiredKeys = p0_external_traffic_closure_chain_required_keys();
    if ($chain === []) {
        return [
            'ready' => false,
            'issues' => [[
                'code' => 'traffic_closure_chain_missing',
                'message' => 'Evidence row must include traffic_closure_chain for capture evidence, source path, metric key, storage field, UI status, platform hotel identifier, and verifier.',
            ]],
        ];
    }

    foreach ($requiredKeys as $key) {
        if (!isset($chain[$key]) || !is_array($chain[$key])) {
            $issues[] = [
                'code' => 'traffic_closure_chain_stage_missing',
                'message' => 'traffic_closure_chain must include every required stage.',
                'stage' => $key,
            ];
        }
    }

    foreach (array_diff($requiredKeys, ['verifier']) as $key) {
        $item = p0_array($chain[$key] ?? null);
        $status = trim((string)($item['status'] ?? ''));
        if ($status !== 'ready') {
            $issues[] = [
                'code' => 'traffic_closure_chain_stage_not_ready',
                'message' => 'External traffic evidence closure stages before verifier must be ready.',
                'stage' => $key,
                'status' => $status,
            ];
        }
    }

    $metricKeyStage = p0_array($chain['metric_key'] ?? null);
    $metricKeyRequired = trim((string)($metricKeyStage['required'] ?? ''));
    foreach (p0_required_traffic_metric_keys() as $metricKey) {
        if ($metricKeyRequired === '' || !str_contains($metricKeyRequired, $metricKey)) {
            $issues[] = [
                'code' => 'traffic_closure_chain_metric_key_required_incomplete',
                'message' => 'traffic_closure_chain.metric_key.required must list every required P0 traffic metric key.',
                'metric_key' => $metricKey,
            ];
        }
    }

    $storageFieldStage = p0_array($chain['storage_field'] ?? null);
    $storageFieldRequired = trim((string)($storageFieldStage['required'] ?? ''));
    foreach (p0_required_traffic_storage_field_map() as $metricKey => $storageField) {
        if ($storageFieldRequired === '' || !str_contains($storageFieldRequired, $storageField)) {
            $issues[] = [
                'code' => 'traffic_closure_chain_storage_field_required_incomplete',
                'message' => 'traffic_closure_chain.storage_field.required must list every required P0 online_daily_data storage field.',
                'metric_key' => (string)$metricKey,
                'storage_field' => $storageField,
            ];
        }
    }

    $platformIdentifierStage = p0_array($chain['platform_hotel_identifier'] ?? null);
    $platformIdentifierRequired = trim((string)($platformIdentifierStage['required'] ?? ''));
    if ($platformIdentifierRequired !== $expectedHotelIdentifierSource) {
        $issues[] = [
            'code' => 'traffic_closure_chain_platform_identifier_source_invalid',
            'message' => 'traffic_closure_chain.platform_hotel_identifier.required must match the OTA platform identifier source family.',
            'source' => $platformIdentifierRequired,
            'expected_source' => $expectedHotelIdentifierSource,
        ];
    }

    $verifierStage = p0_array($chain['verifier'] ?? null);
    $verifierStatus = trim((string)($verifierStage['status'] ?? ''));
    if ($verifierStatus !== 'requires_execute_and_p0_verifier') {
        $issues[] = [
            'code' => 'traffic_closure_chain_verifier_status_invalid',
            'message' => 'External traffic evidence must not mark verifier closure ready before --execute and the P0 verifier have passed.',
            'status' => $verifierStatus,
            'expected_status' => 'requires_execute_and_p0_verifier',
        ];
    }
    $verifierRequired = strtolower(trim((string)($verifierStage['required'] ?? '')));
    if ($verifierRequired === ''
        || !str_contains($verifierRequired, '--execute')
        || !str_contains($verifierRequired, 'verify:p0-ota-field-loop')
        || !str_contains($verifierRequired, 'target-date traffic rows')
    ) {
        $issues[] = [
            'code' => 'traffic_closure_chain_verifier_required_incomplete',
            'message' => 'traffic_closure_chain.verifier.required must state the execute import and verify:p0-ota-field-loop requirements before P0 completion.',
        ];
    }

    $policy = strtolower(trim((string)($row['traffic_closure_chain_policy'] ?? '')));
    if ($policy === '' || !str_contains($policy, 'pre-import') || !str_contains($policy, 'p0 remains incomplete')) {
        $issues[] = [
            'code' => 'traffic_closure_chain_policy_missing',
            'message' => 'Evidence row must state that the closure chain is pre-import source proof only and P0 remains incomplete until target-date rows are ingested.',
        ];
    }

    return [
        'ready' => $issues === [],
        'issues' => $issues,
    ];
}

function p0_platform_hotel_identifier_source(string $platform): string
{
    return strtolower(trim($platform)) === 'meituan' ? 'poi_id_family' : 'hotel_id_family';
}

function p0_platform_hotel_identifier_present(array $row, string $platform): bool
{
    $keys = strtolower(trim($platform)) === 'meituan'
        ? ['poiId', 'poi_id', 'storeId', 'store_id', 'shopId', 'shop_id', 'mtPoiId', 'mt_poi_id', 'partnerId', 'partner_id']
        : ['hotelId', 'hotel_id', 'HotelId', 'hotelID', 'masterHotelId', 'master_hotel_id', 'nodeId', 'node_id'];
    foreach ($keys as $key) {
        if (trim((string)($row[$key] ?? '')) !== '') {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string, mixed> $options
 * @param array<int, string> $platforms
 * @return array<string, mixed>
 */
function p0_external_traffic_evidence(array $options, array $platforms): array
{
    $path = trim((string)($options['traffic-evidence'] ?? ''));
    $requiredMetricKeys = p0_required_traffic_metric_keys();
    $systemHotelId = (int)($options['system-hotel-id'] ?? 0);
    $base = [
        'status' => 'not_provided',
        'path' => '',
        'required_metric_keys' => $requiredMetricKeys,
        'system_hotel_id' => $systemHotelId > 0 ? $systemHotelId : null,
        'platforms' => [],
        'validated_desensitized_evidence_present' => false,
        'sensitive_values_exposed' => false,
        'completion_policy' => 'External evidence validates desensitized source proof only; P0 still requires ingested target-date traffic rows.',
    ];
    if ($path === '') {
        return $base;
    }

    $base['path'] = $path;
    if (!is_file($path) || !is_readable($path)) {
        $base['status'] = 'invalid';
        $base['issues'] = [[
            'code' => 'traffic_evidence_file_unreadable',
            'message' => 'The --traffic-evidence file is missing or unreadable.',
        ]];
        return $base;
    }

    $raw = file_get_contents($path);
    if (!is_string($raw)) {
        $base['status'] = 'invalid';
        $base['issues'] = [[
            'code' => 'traffic_evidence_file_read_failed',
            'message' => 'Unable to read --traffic-evidence file.',
        ]];
        return $base;
    }

    $decoded = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw, true);
    if (!is_array($decoded)) {
        $base['status'] = 'invalid';
        $base['issues'] = [[
            'code' => 'traffic_evidence_json_invalid',
            'message' => 'The --traffic-evidence file must be valid JSON.',
            'json_error' => json_last_error_msg(),
        ]];
        return $base;
    }

    $rows = p0_collect_external_traffic_evidence_rows($decoded);
    if ($rows === []) {
        $base['status'] = 'invalid';
        $base['issues'] = [[
            'code' => 'traffic_evidence_rows_missing',
            'message' => 'The --traffic-evidence file must contain traffic_evidence rows.',
        ]];
        return $base;
    }

    $platformResults = [];
    foreach ($platforms as $platform) {
        $platformResults[$platform] = [
            'platform' => $platform,
            'status' => 'missing',
            'evidence_rows' => 0,
            'valid_evidence_rows' => 0,
            'validated_desensitized_evidence_present' => false,
            'metric_keys' => [],
            'missing_metric_keys' => $requiredMetricKeys,
            'source_paths' => [],
            'storage_fields' => [],
            'system_hotel_ids' => [],
            'platform_hotel_identifier_rows' => 0,
            'platform_hotel_identifier_sources' => [],
            'ui_statuses' => [],
            'ui_status_ready_rows' => 0,
            'traffic_closure_chain_ready_rows' => 0,
            'sensitive_values_exposed' => false,
            'issues' => [],
        ];
    }

    $unknownIssues = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $validation = p0_validate_external_traffic_evidence_row($row, $decoded, $platforms, (string)$options['date'], $systemHotelId);
        $platform = (string)($validation['platform'] ?? '');
        if (!isset($platformResults[$platform])) {
            $unknownIssues[] = [
                'code' => 'traffic_evidence_platform_not_selected',
                'message' => 'Evidence row platform is missing or outside the selected platform scope.',
                'platform' => $platform,
            ];
            continue;
        }

        $platformResults[$platform]['evidence_rows']++;
        $platformResults[$platform]['valid_evidence_rows'] += (string)($validation['status'] ?? '') === 'valid' ? 1 : 0;
        $platformResults[$platform]['sensitive_values_exposed'] = (bool)$platformResults[$platform]['sensitive_values_exposed'] || (bool)($validation['sensitive_values_exposed'] ?? false);
        foreach ((array)($validation['metric_keys'] ?? []) as $metricKey) {
            $platformResults[$platform]['metric_keys'][(string)$metricKey] = true;
        }
        foreach ((array)($validation['source_paths'] ?? []) as $sourcePath) {
            $platformResults[$platform]['source_paths'][(string)$sourcePath] = true;
        }
        foreach ((array)($validation['storage_fields'] ?? []) as $storageField) {
            $platformResults[$platform]['storage_fields'][(string)$storageField] = true;
        }
        $rowSystemHotelId = (int)($validation['system_hotel_id'] ?? 0);
        if ($rowSystemHotelId > 0) {
            $platformResults[$platform]['system_hotel_ids'][(string)$rowSystemHotelId] = true;
        }
        if ((bool)($validation['platform_hotel_identifier_present'] ?? false)) {
            $platformResults[$platform]['platform_hotel_identifier_rows']++;
        }
        $platformHotelIdentifierSource = trim((string)($validation['platform_hotel_identifier_source'] ?? ''));
        if ($platformHotelIdentifierSource !== '') {
            $platformResults[$platform]['platform_hotel_identifier_sources'][$platformHotelIdentifierSource] = true;
        }
        $uiStatus = trim((string)($validation['ui_status'] ?? ''));
        if ($uiStatus !== '') {
            $platformResults[$platform]['ui_statuses'][$uiStatus] = true;
        }
        $platformResults[$platform]['ui_status_ready_rows'] += (bool)($validation['ui_status_ready'] ?? false) ? 1 : 0;
        $platformResults[$platform]['traffic_closure_chain_ready_rows'] += (bool)($validation['traffic_closure_chain_ready'] ?? false) ? 1 : 0;
        foreach ((array)($validation['issues'] ?? []) as $issue) {
            if (is_array($issue)) {
                $platformResults[$platform]['issues'][] = $issue;
            }
        }
    }

    foreach ($platformResults as $platform => $row) {
        $metricKeys = array_values(array_keys((array)$row['metric_keys']));
        $missingMetricKeys = array_values(array_diff($requiredMetricKeys, $metricKeys));
        $valid = (int)$row['valid_evidence_rows'] > 0
            && $missingMetricKeys === []
            && (array)$row['issues'] === []
            && !(bool)$row['sensitive_values_exposed'];
        $platformResults[$platform]['status'] = $valid ? 'valid' : ((int)$row['evidence_rows'] > 0 ? 'invalid' : 'missing');
        $platformResults[$platform]['validated_desensitized_evidence_present'] = $valid;
        $platformResults[$platform]['metric_keys'] = $metricKeys;
        $platformResults[$platform]['missing_metric_keys'] = $missingMetricKeys;
        $platformResults[$platform]['source_paths'] = array_values(array_keys((array)$row['source_paths']));
        $platformResults[$platform]['storage_fields'] = array_values(array_keys((array)$row['storage_fields']));
        $platformResults[$platform]['system_hotel_ids'] = array_values(array_map('intval', array_keys((array)$row['system_hotel_ids'])));
        $platformResults[$platform]['platform_hotel_identifier_sources'] = array_values(array_keys((array)$row['platform_hotel_identifier_sources']));
        $platformResults[$platform]['ui_statuses'] = array_values(array_keys((array)$row['ui_statuses']));
    }

    $validPlatforms = count(array_filter($platformResults, static fn(array $row): bool => (bool)($row['validated_desensitized_evidence_present'] ?? false)));
    $sensitiveExposed = count(array_filter($platformResults, static fn(array $row): bool => (bool)($row['sensitive_values_exposed'] ?? false))) > 0;
    $base['status'] = $validPlatforms === count($platforms) && $unknownIssues === [] ? 'valid' : ($validPlatforms > 0 ? 'partial' : 'invalid');
    $base['platforms'] = $platformResults;
    $base['validated_desensitized_evidence_present'] = $base['status'] === 'valid';
    $base['sensitive_values_exposed'] = $sensitiveExposed;
    if ($unknownIssues !== []) {
        $base['issues'] = $unknownIssues;
    }

    return $base;
}

/**
 * @param array<string, mixed> $inspection
 * @return array<string, array<string, mixed>>
 */
function p0_platform_map(array $inspection): array
{
    $map = [];
    foreach (p0_array($inspection['platforms'] ?? null) as $platform) {
        if (!is_array($platform)) {
            continue;
        }
        $name = strtolower((string)($platform['platform'] ?? ''));
        if ($name !== '') {
            $map[$name] = $platform;
        }
    }
    return $map;
}

/**
 * @param array<string, mixed> $inspection
 * @return array<string, array<string, mixed>>
 */
function p0_source_summary_map(array $inspection): array
{
    $map = [];
    foreach (p0_array($inspection['collection_source_summary'] ?? null) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = strtolower((string)($row['platform'] ?? ''));
        if ($name !== '') {
            $map[$name] = $row;
        }
    }
    return $map;
}

/**
 * @param array<string, array<string, mixed>> $sourceSummaryMap
 * @param array<int, string> $platforms
 */
function p0_runtime_field_fact_summary_ready(array $sourceSummaryMap, array $platforms): bool
{
    foreach ($platforms as $platform) {
        $summary = p0_array($sourceSummaryMap[$platform] ?? null);
        $facts = p0_array($summary['field_fact_closure_summary'] ?? null);
        $completeCount = (int)($facts['complete_fact_count'] ?? 0);
        if ((string)($summary['field_fact_status'] ?? '') !== 'ready'
            || $completeCount <= 0
            || (int)($facts['capture_evidence_count'] ?? 0) < $completeCount
            || (int)($facts['source_path_count'] ?? 0) < $completeCount
            || (int)($facts['structured_source_path_count'] ?? 0) < $completeCount
            || (int)($facts['storage_field_count'] ?? 0) < $completeCount
            || (int)($facts['stored_value_present_count'] ?? 0) < $completeCount
            || (int)($facts['stored_value_missing_count'] ?? 0) !== 0
            || (bool)($facts['raw_data_exposed'] ?? false)
        ) {
            return false;
        }
    }
    return true;
}

/**
 * @param array<string, mixed> $inspection
 * @param array<int, string> $platforms
 */
function p0_runtime_traffic_readiness_visible(array $inspection, array $platforms): bool
{
    $question = [];
    foreach (p0_array($inspection['employee_questions'] ?? null) as $row) {
        if (is_array($row) && (string)($row['key'] ?? '') === 'revenue_traffic_conversion') {
            $question = $row;
            break;
        }
    }
    $evidence = p0_array($question['evidence'] ?? null);
    $readinessRows = p0_array($evidence['traffic_source_readiness'] ?? null);
    $readinessByPlatform = [];
    foreach ($readinessRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = strtolower((string)($row['platform'] ?? ''));
        if ($name !== '') {
            $readinessByPlatform[$name] = $row;
        }
    }
    foreach ($platforms as $platform) {
        $row = p0_array($readinessByPlatform[$platform] ?? null);
        if ($row === []
            || (string)($row['source_policy'] ?? '') !== 'read_platform_data_sources_metadata_only'
            || (bool)($row['sensitive_values_exposed'] ?? true)
            || !array_key_exists('target_date_traffic_rows', $row)
            || !array_key_exists('traffic_source_count', $row)
            || !array_key_exists('p0_source_chain_reference_only', $row)
            || !array_key_exists('p0_source_chain_scope', $row)
            || !array_key_exists('p0_source_chain_policy', $row)
            || trim((string)($row['status'] ?? '')) === ''
        ) {
            return false;
        }
    }
    return true;
}

/**
 * @return array{ok:bool, reason?:string}
 */
function p0_initialize_app(): array
{
    global $root;

    static $state = null;
    if (is_array($state)) {
        return $state;
    }

    $autoload = $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    if (!is_file($autoload)) {
        $state = ['ok' => false, 'reason' => 'vendor_autoload_missing'];
        return $state;
    }

    require_once $autoload;

    try {
        $app = new App();
        $app->initialize();
        $state = ['ok' => true];
    } catch (Throwable $e) {
        $state = ['ok' => false, 'reason' => 'app_initialize_failed:' . $e->getMessage()];
    }

    return $state;
}

function p0_table_exists(string $table): bool
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return false;
    }

    return Db::query("SHOW TABLES LIKE '" . addslashes($table) . "'") !== [];
}

/**
 * @return array<string, bool>
 */
function p0_table_columns(string $table): array
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !p0_table_exists($table)) {
        $cache[$table] = [];
        return $cache[$table];
    }

    $columns = [];
    foreach (Db::query('SHOW COLUMNS FROM `' . $table . '`') as $row) {
        $field = (string)($row['Field'] ?? '');
        if ($field !== '') {
            $columns[$field] = true;
        }
    }
    $cache[$table] = $columns;
    return $columns;
}

/**
 * @param array<int, string> $fields
 * @return array<int, string>
 */
function p0_existing_columns(string $table, array $fields): array
{
    $columns = p0_table_columns($table);
    return array_values(array_filter(
        $fields,
        static fn(string $field): bool => isset($columns[$field])
    ));
}

function p0_value_present(mixed $value): bool
{
    if (is_string($value)) {
        return trim($value) !== '';
    }
    if (is_array($value)) {
        return $value !== [];
    }
    if ($value === null) {
        return false;
    }
    return $value !== false;
}

/**
 * @return array<int, array<string, mixed>>
 */
function p0_config_items(string $platform): array
{
    $tableCandidates = $platform === 'meituan'
        ? ['system_config', 'system_configs']
        : ['system_configs', 'system_config'];
    $key = $platform === 'meituan' ? 'meituan_config_list' : 'ctrip_config_list';

    foreach ($tableCandidates as $table) {
        if (!p0_table_exists($table)) {
            continue;
        }
        $raw = (string)Db::name($table)->where('config_key', $key)->value('config_value');
        if (trim($raw) === '') {
            continue;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            continue;
        }

        $items = [];
        foreach ($decoded as $item) {
            if (is_array($item)) {
                $items[] = $item;
            }
        }
        return $items;
    }

    return [];
}

/**
 * @param array<string, mixed> $item
 * @param array<int, string> $keys
 */
function p0_item_has_any_key(array $item, array $keys): bool
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $item) && p0_value_present($item[$key])) {
            return true;
        }
    }
    return false;
}

/**
 * @param array<string, mixed> $item
 * @param array<int, string> $keys
 */
function p0_item_url_looks_traffic(array $item, array $keys): bool
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $item) || !is_string($item[$key])) {
            continue;
        }
        $value = strtolower($item[$key]);
        foreach (['traffic', 'flow', 'exposure', 'conversion', 'funnel'] as $needle) {
            if (str_contains($value, $needle)) {
                return true;
            }
        }
    }
    return false;
}

/**
 * @param array<int, array<string, mixed>> $items
 * @param array<int, string> $keys
 */
function p0_count_items_with_any_key(array $items, array $keys): int
{
    return count(array_filter($items, static fn(array $item): bool => p0_item_has_any_key($item, $keys)));
}

/**
 * @param array<int, array<string, mixed>> $items
 * @param array<int, string> $keys
 */
function p0_count_items_with_traffic_url(array $items, array $keys): int
{
    return count(array_filter($items, static fn(array $item): bool => p0_item_url_looks_traffic($item, $keys)));
}

/**
 * @return array<string, mixed>
 */
function p0_config_availability(string $platform): array
{
    $items = p0_config_items($platform);
    $urlKeys = ['traffic_url', 'trafficUrl', 'flow_url', 'flowUrl', 'url', 'endpoint', 'request_url'];
    $payloadKeys = ['traffic_payload', 'trafficPayload', 'flow_payload', 'flowPayload', 'payload', 'params', 'extra_params', 'query_params'];
    $authKeys = ['cookies', 'cookie', 'auth_data', 'headers', 'authorization'];
    $idKeys = $platform === 'meituan'
        ? ['partner_id', 'poi_id', 'store_id', 'hotel_id']
        : ['node_id', 'hotel_id', 'platform_hotel_id', 'ota_hotel_id'];

    return [
        'config_count' => count($items),
        'with_any_url_count' => p0_count_items_with_any_key($items, $urlKeys),
        'with_traffic_url_count' => p0_count_items_with_traffic_url($items, $urlKeys),
        'with_payload_context_count' => p0_count_items_with_any_key($items, $payloadKeys),
        'with_auth_context_count' => p0_count_items_with_any_key($items, $authKeys),
        'with_platform_id_count' => p0_count_items_with_any_key($items, $idKeys),
        'sensitive_values_exposed' => false,
    ];
}

/**
 * @return array<string, mixed>
 */
function p0_profile_dir_availability(string $platform): array
{
    global $root;

    $pattern = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . $platform . '_profile_*';
    $dirs = array_values(array_filter((array)glob($pattern, GLOB_ONLYDIR), 'is_string'));

    return [
        'profile_dir_count' => count($dirs),
        'profile_path_exposed' => false,
    ];
}

/**
 * @param array<string, mixed> $config
 */
function p0_traffic_profile_login_state_verified(array $config): bool
{
    foreach (['manual_login_state_verified', 'login_state_verified', 'profile_login_verified'] as $key) {
        if (($config[$key] ?? false) === true) {
            return true;
        }
    }

    $profileStatus = strtolower(trim((string)($config['profile_status'] ?? $config['login_status'] ?? '')));
    if (in_array($profileStatus, ['logged_in', 'login_verified', 'verified'], true)) {
        return true;
    }

    $authStatus = $config['auth_status'] ?? null;
    if (is_array($authStatus) && ($authStatus['ok'] ?? false) === true) {
        return true;
    }

    return false;
}

/**
 * @return array<string, mixed>
 */
function p0_platform_data_source_availability(string $platform): array
{
    if (!p0_table_exists('platform_data_sources')) {
        return [
            'table_present' => false,
            'source_count' => 0,
            'traffic_source_count' => 0,
            'enabled_count' => 0,
            'ready_count' => 0,
            'browser_profile_count' => 0,
            'traffic_enabled_count' => 0,
            'traffic_ready_count' => 0,
            'traffic_waiting_config_count' => 0,
            'traffic_managed_count' => 0,
            'traffic_secret_configured_count' => 0,
            'traffic_profile_login_verified_count' => 0,
            'traffic_last_sync_status_counts' => [],
            'traffic_source_samples' => [],
            'method_counts' => [],
            'status_counts' => [],
        ];
    }

    $fields = p0_existing_columns('platform_data_sources', [
        'id',
        'platform',
        'data_type',
        'ingestion_method',
        'status',
        'enabled',
        'system_hotel_id',
        'last_sync_status',
        'last_sync_time',
        'last_error',
        'config_json',
        'secret_json',
    ]);
    $rows = Db::name('platform_data_sources')
        ->field(implode(',', $fields))
        ->where('platform', $platform)
        ->select()
        ->toArray();

    $methodCounts = [];
    $statusCounts = [];
    $trafficLastSyncStatusCounts = [];
    $trafficSourceCount = 0;
    $enabledCount = 0;
    $readyCount = 0;
    $browserProfileCount = 0;
    $trafficEnabledCount = 0;
    $trafficReadyCount = 0;
    $trafficWaitingConfigCount = 0;
    $trafficManagedCount = 0;
    $trafficSecretConfiguredCount = 0;
    $trafficProfileLoginVerifiedCount = 0;
    $trafficSourceSamples = [];
    foreach ($rows as $row) {
        $dataType = strtolower((string)($row['data_type'] ?? ''));
        $method = strtolower((string)($row['ingestion_method'] ?? 'unknown'));
        $status = strtolower((string)($row['status'] ?? 'unknown'));
        $enabled = (int)($row['enabled'] ?? 0) === 1;

        $methodCounts[$method] = ($methodCounts[$method] ?? 0) + 1;
        $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
        if (in_array($dataType, ['traffic', 'flow', 'conversion'], true)) {
            $trafficSourceCount++;
            if ($enabled) {
                $trafficEnabledCount++;
            }
            if ($status === 'ready') {
                $trafficReadyCount++;
            }
            if ($status === 'waiting_config') {
                $trafficWaitingConfigCount++;
            }
            $lastSyncStatus = strtolower(trim((string)($row['last_sync_status'] ?? '')));
            if ($lastSyncStatus !== '') {
                $trafficLastSyncStatusCounts[$lastSyncStatus] = ($trafficLastSyncStatusCounts[$lastSyncStatus] ?? 0) + 1;
            }

            $config = json_decode((string)($row['config_json'] ?? ''), true);
            $config = is_array($config) ? $config : [];
            $secret = json_decode((string)($row['secret_json'] ?? ''), true);
            $secretConfigured = is_array($secret) ? $secret !== [] : trim((string)($row['secret_json'] ?? '')) !== '';
            $managedByP0 = ($config['registered_by'] ?? '') === 'p0_ota_field_loop';
            $loginStateVerified = $method === 'browser_profile' && p0_traffic_profile_login_state_verified($config);
            if ($managedByP0) {
                $trafficManagedCount++;
            }
            if ($secretConfigured) {
                $trafficSecretConfiguredCount++;
            }
            if ($loginStateVerified) {
                $trafficProfileLoginVerifiedCount++;
            }
            $captureSections = $config['capture_sections'] ?? $config['captureSections'] ?? [];
            $captureSectionsText = is_array($captureSections)
                ? strtolower(implode(',', array_map('strval', $captureSections)))
                : strtolower((string)$captureSections);
            if (count($trafficSourceSamples) < 5) {
                $trafficSourceSamples[] = [
                    'id' => (int)($row['id'] ?? 0),
                    'system_hotel_id' => (int)($row['system_hotel_id'] ?? 0),
                    'ingestion_method' => $method,
                    'status' => $status,
                    'enabled' => $enabled,
                    'last_sync_status' => $lastSyncStatus,
                    'last_sync_time_present' => trim((string)($row['last_sync_time'] ?? '')) !== '',
                    'last_error_present' => trim((string)($row['last_error'] ?? '')) !== '',
                    'managed_by_p0' => $managedByP0,
                    'capture_sections_has_traffic' => str_contains($captureSectionsText, 'traffic'),
                    'manual_login_state_verified' => $loginStateVerified,
                    'secret_configured' => $secretConfigured,
                ];
            }
        }
        if ($enabled) {
            $enabledCount++;
        }
        if ($status === 'ready') {
            $readyCount++;
        }
        if ($method === 'browser_profile') {
            $browserProfileCount++;
        }
    }

    ksort($methodCounts);
    ksort($statusCounts);
    ksort($trafficLastSyncStatusCounts);

    return [
        'table_present' => true,
        'source_count' => count($rows),
        'traffic_source_count' => $trafficSourceCount,
        'enabled_count' => $enabledCount,
        'ready_count' => $readyCount,
        'browser_profile_count' => $browserProfileCount,
        'traffic_enabled_count' => $trafficEnabledCount,
        'traffic_ready_count' => $trafficReadyCount,
        'traffic_waiting_config_count' => $trafficWaitingConfigCount,
        'traffic_managed_count' => $trafficManagedCount,
        'traffic_secret_configured_count' => $trafficSecretConfiguredCount,
        'traffic_profile_login_verified_count' => $trafficProfileLoginVerifiedCount,
        'traffic_last_sync_status_counts' => $trafficLastSyncStatusCounts,
        'traffic_source_samples' => $trafficSourceSamples,
        'method_counts' => $methodCounts,
        'status_counts' => $statusCounts,
    ];
}

/**
 * @return array<string, mixed>
 */
function p0_endpoint_template_availability(string $platform): array
{
    global $root;

    if ($platform === 'meituan') {
        $scriptPath = $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'meituan_browser_capture.mjs';
        $docPath = $root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'meituan_browser_capture.md';
        $script = is_file($scriptPath) ? (string)file_get_contents($scriptPath) : '';
        $doc = is_file($docPath) ? (string)file_get_contents($docPath) : '';

        return [
            'template_present' => is_file($docPath),
            'traffic_template_count' => str_contains($doc, '默认等同于') && str_contains($doc, '--sections=traffic,orders') ? 1 : 0,
            'catalog_present' => false,
            'traffic_catalog_endpoint_count' => 0,
            'default_traffic_url_available' => false,
            'profile_capture_script_present' => is_file($scriptPath),
            'profile_capture_doc_present' => is_file($docPath),
            'profile_capture_sections_include_traffic' => str_contains($script, "traffic: 'https://")
                && str_contains($script, 'newTraffic')
                && str_contains($script, 'normalizeCapturedList')
                && str_contains($script, "'traffic'"),
            'status' => is_file($scriptPath) && is_file($docPath) ? 'profile_capture_entry_documented' : 'missing_profile_capture_entry',
        ];
    }

    $path = $root . DIRECTORY_SEPARATOR . 'reports' . DIRECTORY_SEPARATOR . 'ctrip_endpoint_evidence_templates.json';
    $catalogPath = $root . DIRECTORY_SEPARATOR . 'reports' . DIRECTORY_SEPARATOR . 'ctrip_capture_catalog.json';
    $normalizerPath = $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'service' . DIRECTORY_SEPARATOR . 'OtaTrafficUrlNormalizer.php';
    $normalizerVerifierPath = $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_ota_traffic_url_normalizer.php';
    $controllerPath = $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR . 'OnlineData.php';
    $captureScriptPath = $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'ctrip_browser_capture.mjs';
    $profileDocPath = $root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'ctrip_browser_capture_method.md';
    $templateSourcePath = $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'ctrip_capture_catalog.mjs';
    $normalizerSource = is_file($normalizerPath) ? (string)file_get_contents($normalizerPath) : '';
    $normalizerVerifier = is_file($normalizerVerifierPath) ? (string)file_get_contents($normalizerVerifierPath) : '';
    $controllerSource = is_file($controllerPath) ? (string)file_get_contents($controllerPath) : '';
    $profileDoc = is_file($profileDocPath) ? (string)file_get_contents($profileDocPath) : '';
    $templateSource = is_file($templateSourcePath) ? (string)file_get_contents($templateSourcePath) : '';
    $defaultTrafficUrlAvailable = str_contains($normalizerSource, 'queryFlowTransforNewV1')
        && str_contains($normalizerSource, 'hostType=Ebooking')
        && str_contains($normalizerVerifier, "normalizeCtripTrafficUrl('')")
        && str_contains($normalizerVerifier, 'default URL must target queryFlowTransforNewV1')
        && str_contains($controllerSource, 'normalizeCtripTrafficUrl($url)');
    $profileCaptureDocPresent = is_file($profileDocPath)
        && str_contains($profileDoc, 'capture-ctrip-browser')
        && str_contains($profileDoc, 'diagnosis_summary')
        && str_contains($profileDoc, '流量转化');
    $sourceTrafficTemplateCount = str_contains($templateSource, "id: 'traffic_report'")
        && str_contains($templateSource, "dataType: 'traffic'")
        && str_contains($templateSource, 'queryflowtransfornewv1')
        ? 1
        : 0;
    $catalog = is_file($catalogPath) ? json_decode((string)file_get_contents($catalogPath), true) : null;
    $catalogEndpoints = is_array($catalog) && is_array($catalog['endpoints'] ?? null) ? $catalog['endpoints'] : [];
    $catalogTrafficCount = 0;
    foreach ($catalogEndpoints as $endpoint) {
        if (!is_array($endpoint)) {
            continue;
        }
        if (($endpoint['section'] ?? '') === 'traffic_report' || ($endpoint['dataType'] ?? '') === 'traffic') {
            $catalogTrafficCount++;
        }
    }

    if (!is_file($path)) {
        return [
            'template_present' => false,
            'traffic_template_count' => $sourceTrafficTemplateCount,
            'catalog_present' => is_file($catalogPath),
            'traffic_catalog_endpoint_count' => $catalogTrafficCount,
            'default_traffic_url_available' => $defaultTrafficUrlAvailable,
            'profile_capture_script_present' => is_file($captureScriptPath),
            'profile_capture_doc_present' => $profileCaptureDocPresent,
            'profile_capture_sections_include_traffic' => $catalogTrafficCount > 0,
            'status' => 'missing',
        ];
    }

    $decoded = json_decode((string)file_get_contents($path), true);
    $templates = is_array($decoded) && is_array($decoded['templates'] ?? null) ? $decoded['templates'] : [];
    $trafficCount = 0;
    foreach ($templates as $template) {
        if (!is_array($template)) {
            continue;
        }
        $haystack = strtolower(implode(' ', [
            (string)($template['candidate_section'] ?? ''),
            (string)($template['candidate_label'] ?? ''),
            (string)($template['data_type'] ?? ''),
        ]));
        if (str_contains($haystack, 'traffic') || str_contains($haystack, 'flow')) {
            $trafficCount++;
        }
    }

    return [
        'template_present' => true,
        'traffic_template_count' => max($trafficCount, $sourceTrafficTemplateCount),
        'catalog_present' => is_file($catalogPath),
        'traffic_catalog_endpoint_count' => $catalogTrafficCount,
        'default_traffic_url_available' => $defaultTrafficUrlAvailable,
        'profile_capture_script_present' => is_file($captureScriptPath),
        'profile_capture_doc_present' => $profileCaptureDocPresent,
        'profile_capture_sections_include_traffic' => $catalogTrafficCount > 0,
        'status' => is_array($decoded) ? (string)($decoded['status'] ?? 'unknown') : 'invalid_json',
    ];
}

/**
 * @param array<string, mixed> $summary
 * @return array<int, string>
 */
function p0_traffic_required_inputs(string $platform, array $config, array $profile, array $sources, array $template, array $summary): array
{
    $trafficRows = (int)($summary['traffic_rows'] ?? 0);
    if ($trafficRows > 0) {
        return [];
    }

    $required = [];
    $hasManualTrafficUrl = (int)($config['with_traffic_url_count'] ?? 0) > 0
        || (bool)($template['default_traffic_url_available'] ?? false);
    if (!$hasManualTrafficUrl) {
        $required[] = 'traffic_request_url_or_cdp_endpoint_evidence';
    }
    if ((int)($config['with_payload_context_count'] ?? 0) === 0) {
        $required[] = 'traffic_payload_or_query_params';
    }
    if ((int)($profile['profile_dir_count'] ?? 0) === 0) {
        $required[] = 'authorized_' . $platform . '_profile_dir';
    } elseif ((int)($sources['traffic_profile_login_verified_count'] ?? 0) === 0) {
        $required[] = 'manual_login_state_verified';
    }
    $hasTrafficEvidenceEntry = (int)($template['traffic_template_count'] ?? 0) > 0
        || (int)($template['traffic_catalog_endpoint_count'] ?? 0) > 0
        || (bool)($template['profile_capture_sections_include_traffic'] ?? false);
    if (!$hasTrafficEvidenceEntry) {
        $required[] = 'desensitized_traffic_evidence_template';
    }
    if ((int)($sources['traffic_source_count'] ?? 0) === 0) {
        $required[] = 'registered_traffic_data_source';
    }

    return array_values(array_unique($required));
}

/**
 * @return array<string, mixed>
 */
function p0_traffic_input_contract(string $platform, string $mode): array
{
    $platform = strtolower(trim($platform));
    $mode = strtolower(trim($mode));
    $requiredMetricKeys = p0_required_traffic_metric_keys();
    $base = [
        'scope_policy' => 'ota_channel_only',
        'target_storage_table' => 'online_daily_data',
        'target_data_type' => 'traffic',
        'required_metric_keys' => $requiredMetricKeys,
        'required_storage_fields' => [
            'online_daily_data.list_exposure',
            'online_daily_data.detail_exposure',
            'online_daily_data.flow_rate',
            'online_daily_data.order_filling_num',
            'online_daily_data.order_submit_num',
            'online_daily_data.raw_data.field_facts',
        ],
        'required_field_fact_keys' => [
            'capture_evidence',
            'source_path',
            'metric_key',
            'storage_field',
            'stored_value_present',
        ],
        'sensitive_values_allowed' => false,
    ];

    if ($mode === 'manual_cookie_api') {
        return array_merge($base, [
            'required_inputs' => [
                'target_date',
                'system_hotel_id',
                $platform === 'ctrip' ? 'ctrip_hotel_id_or_node_id' : 'meituan_poi_id_or_partner_id',
                'authorized_cookie_or_headers',
                'traffic_request_url_or_cdp_endpoint_evidence',
                'traffic_payload_or_query_params',
                'desensitized_traffic_response_sample_or_source_trace_id',
            ],
            'forbidden_inputs' => [
                'raw_cookie_value_in_report',
                'raw_token_value_in_report',
                'raw_profile_path_in_report',
            ],
        ]);
    }

    if ($mode === 'browser_profile') {
        return array_merge($base, [
            'required_inputs' => [
                'target_date',
                'system_hotel_id',
                'authorized_' . $platform . '_profile_dir',
                'manual_login_state_verified',
                'traffic_response_listener',
                'desensitized_traffic_response_sample_or_source_trace_id',
            ],
            'forbidden_inputs' => [
                'captcha_bypass',
                'sms_bypass',
                'raw_profile_path_in_report',
                'raw_cookie_value_in_report',
                'raw_token_value_in_report',
            ],
        ]);
    }

    return $base;
}

/**
 * @return array<string, mixed>
 */
function p0_traffic_acceptance_contract(): array
{
    return [
        'target_date_traffic_rows' => '>0',
        'field_facts_status' => 'ready',
        'required_chain' => [
            'capture_evidence',
            'source_path',
            'metric_key',
            'storage_field',
            'stored_value',
            'ui_status',
            'verifier',
        ],
        'verifier_command' => 'npm.cmd run verify:p0-ota-field-loop -- --date=<target-date>',
        'traffic_evidence_verifier_command' => 'npm.cmd run verify:p0-ota-field-loop -- --date=<target-date> --platform=<platform> --system-hotel-id=<system-hotel-id> --traffic-evidence=<importer-json-output>',
        'traffic_evidence_policy' => 'External traffic evidence validates desensitized source proof only; it does not complete P0 without ingested target-date traffic rows.',
        'completion_policy' => 'Traffic closure is not complete until target-date traffic rows exist and field_facts prove every required chain item.',
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function p0_traffic_closure_path_options(string $platform, array $config, array $profile, array $sources, array $template, array $summary): array
{
    if ((int)($summary['traffic_rows'] ?? 0) > 0) {
        return [[
            'mode' => 'already_ingested',
            'status' => 'ready',
            'missing_inputs' => [],
            'can_run_now' => false,
            'reason' => 'Target-date traffic rows already exist.',
            'input_contract' => p0_traffic_input_contract($platform, 'already_ingested'),
            'acceptance_contract' => p0_traffic_acceptance_contract(),
        ]];
    }

    $manualMissing = [];
    $hasManualTrafficUrl = (int)($config['with_traffic_url_count'] ?? 0) > 0
        || (bool)($template['default_traffic_url_available'] ?? false);
    if (!$hasManualTrafficUrl) {
        $manualMissing[] = 'traffic_request_url_or_cdp_endpoint_evidence';
    }
    if ((int)($config['with_payload_context_count'] ?? 0) === 0) {
        $manualMissing[] = 'traffic_payload_or_query_params';
    }
    if ((int)($config['with_auth_context_count'] ?? 0) === 0) {
        $manualMissing[] = 'authorized_cookie_or_headers';
    }
    if ((int)($config['with_platform_id_count'] ?? 0) === 0) {
        $manualMissing[] = 'platform_hotel_or_poi_id';
    }
    if ((int)($sources['traffic_source_count'] ?? 0) === 0) {
        $manualMissing[] = 'registered_traffic_data_source';
    }

    $profileMissing = [];
    if ((int)($profile['profile_dir_count'] ?? 0) === 0) {
        $profileMissing[] = 'authorized_' . $platform . '_profile_dir';
    } elseif ((int)($sources['traffic_profile_login_verified_count'] ?? 0) === 0) {
        $profileMissing[] = 'manual_login_state_verified';
    }
    if (!(bool)($template['profile_capture_script_present'] ?? false)) {
        $profileMissing[] = $platform . '_profile_capture_script';
    }
    if (!(bool)($template['profile_capture_sections_include_traffic'] ?? false)) {
        $profileMissing[] = 'profile_capture_traffic_section';
    }

    $evidenceMissing = [];
    $hasTrafficEvidenceEntry = (int)($template['traffic_template_count'] ?? 0) > 0
        || (int)($template['traffic_catalog_endpoint_count'] ?? 0) > 0
        || (bool)($template['profile_capture_sections_include_traffic'] ?? false);
    if (!$hasTrafficEvidenceEntry) {
        $evidenceMissing[] = 'desensitized_traffic_evidence_template';
    }

    return [
        [
            'mode' => 'manual_cookie_api',
            'entry' => $platform === 'ctrip' ? '/api/online-data/fetch-ctrip-traffic' : '/api/online-data/fetch-meituan-traffic',
            'payload_import_command' => 'npm.cmd run import:p0-ota-traffic-payload -- --platform=' . $platform . ' --date=<target-date> --system-hotel-id=<system-hotel-id> --payload=<authorized-traffic-json>',
            'payload_import_execute_command' => 'npm.cmd run import:p0-ota-traffic-payload:execute -- --platform=' . $platform . ' --date=<target-date> --system-hotel-id=<system-hotel-id> --payload=<authorized-traffic-json>',
            'traffic_evidence_output' => 'traffic_evidence',
            'traffic_evidence_verifier_command' => 'npm.cmd run verify:p0-ota-field-loop -- --date=<target-date> --platform=' . $platform . ' --system-hotel-id=<system-hotel-id> --traffic-evidence=<importer-json-output>',
            'traffic_evidence_policy' => 'External traffic evidence validates desensitized source proof only; it does not complete P0 without ingested target-date traffic rows.',
            'status' => $manualMissing === [] && $evidenceMissing === [] ? 'ready_to_attempt' : 'missing_inputs',
            'missing_inputs' => array_values(array_unique(array_merge($manualMissing, $evidenceMissing))),
            'can_run_now' => $manualMissing === [] && $evidenceMissing === [],
            'reason' => 'Use when a real traffic URL, payload/query params, auth context, and platform hotel/POI id are available.',
            'boundary' => 'Does not auto-login to OTA and does not infer missing payload fields.',
            'input_contract' => p0_traffic_input_contract($platform, 'manual_cookie_api'),
            'acceptance_contract' => p0_traffic_acceptance_contract(),
        ],
        [
            'mode' => 'browser_profile',
            'entry' => $platform === 'ctrip' ? '/api/online-data/capture-ctrip-browser' : '/api/online-data/capture-meituan-browser',
            'status' => $profileMissing === [] ? 'ready_to_attempt' : 'missing_inputs',
            'missing_inputs' => array_values(array_unique($profileMissing)),
            'can_run_now' => $profileMissing === [],
            'reason' => 'Use when a local authorized browser Profile exists, login state has been manually verified, and the platform page must trigger traffic responses.',
            'boundary' => 'Does not bypass captcha, SMS, human verification, or platform permissions.',
            'input_contract' => p0_traffic_input_contract($platform, 'browser_profile'),
            'acceptance_contract' => p0_traffic_acceptance_contract(),
        ],
    ];
}

/**
 * @param array<int, array<string, mixed>> $pathOptions
 * @return array<string, mixed>
 */
function p0_recommended_traffic_action(string $platform, array $pathOptions): array
{
    $preferredMode = $platform === 'meituan' ? 'browser_profile' : 'manual_cookie_api';
    $ranked = [];
    foreach ($pathOptions as $index => $option) {
        if (!is_array($option)) {
            continue;
        }
        $missingCount = count(array_values(array_filter((array)($option['missing_inputs'] ?? []), static fn($item): bool => trim((string)$item) !== '')));
        $ranked[] = [
            'index' => $index,
            'ready_rank' => (bool)($option['can_run_now'] ?? false) || (string)($option['status'] ?? '') === 'ready_to_attempt' ? 0 : 1,
            'missing_count' => $missingCount,
            'preferred_rank' => (string)($option['mode'] ?? '') === $preferredMode ? 0 : 1,
            'option' => $option,
        ];
    }

    usort($ranked, static function (array $left, array $right): int {
        foreach (['ready_rank', 'missing_count', 'preferred_rank', 'index'] as $field) {
            if ($left[$field] !== $right[$field]) {
                return $left[$field] <=> $right[$field];
            }
        }
        return 0;
    });

    $selected = $ranked[0]['option'] ?? [];
    if (!is_array($selected)) {
        return [];
    }

    return [
        'mode' => (string)($selected['mode'] ?? ''),
        'entry' => (string)($selected['entry'] ?? ''),
        'status' => (string)($selected['status'] ?? ''),
        'missing_inputs' => array_values(array_map('strval', (array)($selected['missing_inputs'] ?? []))),
        'can_run_now' => (bool)($selected['can_run_now'] ?? false),
        'input_contract' => p0_array($selected['input_contract'] ?? null),
        'acceptance_contract' => p0_array($selected['acceptance_contract'] ?? null),
        'selection_policy' => 'prefer_ready_then_fewest_missing_inputs_then_platform_default',
    ];
}

/**
 * @return array<string, string>
 */
function p0_hotel_scoped_traffic_commands(string $platform, string $targetDate, int $systemHotelId): array
{
    if ($systemHotelId <= 0) {
        return [];
    }

    $baseArgs = '--platform=' . $platform
        . ' --date=' . $targetDate
        . ' --system-hotel-id=' . $systemHotelId;

    return [
        'payload_import_command' => 'npm.cmd run import:p0-ota-traffic-payload -- ' . $baseArgs . ' --payload=<authorized-traffic-json>',
        'payload_import_execute_command' => 'npm.cmd run import:p0-ota-traffic-payload:execute -- ' . $baseArgs . ' --payload=<authorized-traffic-json>',
        'traffic_evidence_verifier_command' => 'npm.cmd run verify:p0-ota-field-loop -- ' . $baseArgs . ' --traffic-evidence=<importer-json-output>',
        'p0_verifier_command' => 'npm.cmd run verify:p0-ota-field-loop -- --date=' . $targetDate . ' --platform=' . $platform . ' --system-hotel-id=' . $systemHotelId,
    ];
}

/**
 * @return array<string, mixed>
 */
function p0_hotel_scoped_capture_bridge_contract(string $platform, string $targetDate, int $systemHotelId): array
{
    if ($systemHotelId <= 0) {
        return [];
    }

    $output = 'reports/p0_traffic_' . $platform . '_' . $systemHotelId . '_' . str_replace('-', '', $targetDate) . '.json';
    if ($platform === 'ctrip') {
        $loginCommand = 'node scripts/ctrip_browser_capture.mjs --profile-id=<authorized-profile-id> --hotel-id=<authorized-platform-hotel-id> --system-hotel-id=' . $systemHotelId . ' --sections=traffic --data-date=' . $targetDate . ' --login-only --headless=false --interactive-login=true --post-login-wait-ms=120000 --output=' . $output;
        $captureCommand = 'node scripts/ctrip_browser_capture.mjs --profile-id=<authorized-profile-id> --hotel-id=<authorized-platform-hotel-id> --system-hotel-id=' . $systemHotelId . ' --sections=traffic --data-date=' . $targetDate . ' --output=' . $output;
        $expectedKeys = ['auth_status', 'capture_gate', 'system_hotel_id', 'hotel_id', 'default_data_date', 'requested_sections', 'traffic[]', 'traffic[].hotelId|hotel_id', 'traffic[].date_source', 'traffic[].capture_evidence.source_trace_id', 'traffic[].capture_evidence.source_url_hash', 'standard_rows[]', 'standard_rows[].hotelId|hotel_id', 'standard_rows[].date_source', 'standard_rows[].capture_evidence.source_trace_id', 'standard_rows[].capture_evidence.source_url_hash', 'responses[]', 'responses[].section', 'responses[].row_count', 'responses[].standard_row_count', 'responses[].source_trace_id', 'responses[].url_hash', 'responses[].request_date_source'];
        $platformHotelIdentifierSource = 'hotel_id_family';
    } else {
        $loginCommand = 'node scripts/meituan_browser_capture.mjs --store-id=<authorized-platform-store-id> --system-hotel-id=' . $systemHotelId . ' --sections=traffic --data-date=' . $targetDate . ' --login-only --headless=false --interactive-login=true --post-login-wait-ms=120000 --output=' . $output;
        $captureCommand = 'node scripts/meituan_browser_capture.mjs --store-id=<authorized-platform-store-id> --system-hotel-id=' . $systemHotelId . ' --sections=traffic --data-date=' . $targetDate . ' --output=' . $output;
        $expectedKeys = ['auth_status', 'capture_gate', 'system_hotel_id', 'store_id|poi_id', 'default_data_date', 'capture_sections', 'traffic[]', 'traffic[].poiId|poi_id|storeId|store_id', 'traffic[].date_source', 'traffic[].capture_evidence.source_trace_id', 'traffic[].capture_evidence.source_url_hash', 'responses[]', 'responses[].section', 'responses[].row_count', 'responses[].source_trace_id', 'responses[].url_hash', 'responses[].request_date_source'];
        $platformHotelIdentifierSource = 'poi_id_family';
    }

    return [
        'status' => 'waiting_manual_login_state_and_real_traffic_response',
        'platform' => $platform,
        'target_date' => $targetDate,
        'system_hotel_id' => $systemHotelId,
        'capture_output_path' => $output,
        'browser_login_prepare_command' => $loginCommand,
        'browser_capture_command' => $captureCommand,
        'capture_sections' => ['traffic'],
        'expected_capture_output_keys' => $expectedKeys,
        'required_capture_scope_proof' => [
            'system_hotel_id' => $systemHotelId,
            'platform_hotel_identifier_source' => $platformHotelIdentifierSource,
            'platform_hotel_identifier_policy' => 'Capture/import payload rows must include the OTA platform hotel identifier; verifier and UI expose only presence/source-family proof, never the raw identifier.',
        ],
        'bridge_to_importer_command' => 'npm.cmd run import:p0-ota-traffic-payload -- --platform=' . $platform . ' --date=' . $targetDate . ' --system-hotel-id=' . $systemHotelId . ' --payload=' . $output . ' --format=json',
        'bridge_execute_command' => 'npm.cmd run import:p0-ota-traffic-payload:execute -- --platform=' . $platform . ' --date=' . $targetDate . ' --system-hotel-id=' . $systemHotelId . ' --payload=' . $output . ' --format=json',
        'bridge_importer_acceptance' => [
            'status' => 'ready_to_import',
            'payload_import_projection.applied' => true,
            'payload_import_projection.reason' => 'browser_capture_import_projection',
            'sensitive_values_exposed' => false,
            'summary.target_date_rows' => '>0',
            'summary.missing_platform_hotel_identifier_rows' => 0,
            'summary.rows_with_complete_desensitized_capture_evidence' => 'equals summary.target_date_rows',
            'summary.row_level_desensitized_capture_evidence_rows' => 'equals summary.target_date_rows',
            'summary.row_level_complete_desensitized_capture_evidence_rows' => 'equals summary.target_date_rows',
            'summary.browser_response_evidence_rows' => 'equals summary.target_date_rows',
            'traffic_evidence[].platform_hotel_identifier_present' => true,
            'traffic_evidence[].platform_hotel_identifier_source' => $platformHotelIdentifierSource,
            'traffic_evidence[].ui_status.field_fact_status' => 'ready',
        ],
        'post_import_verifier_command' => 'npm.cmd run verify:p0-ota-field-loop -- --date=' . $targetDate . ' --platform=' . $platform . ' --system-hotel-id=' . $systemHotelId,
        'manual_gates' => [
            'manual_login_state_verified',
            'authorized_profile_matches_selected_hotel',
            'authorized_platform_hotel_identifier_provided',
            'target_date_traffic_response_captured',
        ],
        'forbidden_actions' => [
            'captcha_bypass',
            'sms_bypass',
            'raw_cookie_value_in_report',
            'raw_token_value_in_report',
            'raw_profile_path_in_report',
        ],
        'completion_sequence' => [
            'prepare_or_verify_authorized_profile',
            'capture_real_traffic_response',
            'dry_run_importer_contract',
            'execute_import_only_after_ready_to_import',
            'run_p0_field_loop_verifier',
        ],
    ];
}

function p0_hotel_scoped_payload_path(string $platform, string $targetDate, int $systemHotelId): string
{
    return 'reports/p0_traffic_' . $platform . '_' . $systemHotelId . '_' . str_replace('-', '', $targetDate) . '.json';
}

/**
 * @return array<string, mixed>
 */
function p0_hotel_scoped_payload_candidate_scan(string $platform, string $targetDate, int $systemHotelId): array
{
    global $root;

    if ($systemHotelId <= 0) {
        return [];
    }

    $payloadPath = p0_hotel_scoped_payload_path($platform, $targetDate, $systemHotelId);
    $absolutePayloadPath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $payloadPath);
    $base = [
        'platform' => $platform,
        'target_date' => $targetDate,
        'system_hotel_id' => $systemHotelId,
        'payload_path' => $payloadPath,
        'status' => 'missing_expected_payload',
        'ready_to_execute' => false,
        'scan_policy' => 'dry_run_only_no_import',
        'payload_policy' => 'path_and_importer_summary_only_no_payload_content',
        'storage_policy' => 'does_not_write_online_daily_data',
        'dry_run_command' => 'npm.cmd run import:p0-ota-traffic-payload -- --platform=' . $platform . ' --date=' . $targetDate . ' --system-hotel-id=' . $systemHotelId . ' --payload=' . $payloadPath . ' --format=json',
        'execute_command' => 'npm.cmd run import:p0-ota-traffic-payload:execute -- --platform=' . $platform . ' --date=' . $targetDate . ' --system-hotel-id=' . $systemHotelId . ' --payload=' . $payloadPath . ' --format=json',
        'verification_command' => 'npm.cmd run verify:p0-ota-field-loop -- --date=' . $targetDate . ' --platform=' . $platform . ' --system-hotel-id=' . $systemHotelId,
        'importer_status' => 'not_run',
        'importer_exit_code' => null,
        'target_date_rows' => 0,
        'traffic_evidence_rows' => 0,
        'issue_codes' => ['expected_payload_file_missing'],
    ];

    if (!is_file($absolutePayloadPath)) {
        return $base;
    }

    $result = p0_run_process([
        PHP_BINARY,
        $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'import_p0_ota_traffic_payload.php',
        '--platform=' . $platform,
        '--date=' . $targetDate,
        '--system-hotel-id=' . $systemHotelId,
        '--payload=' . $absolutePayloadPath,
        '--format=json',
    ], $root);

    $base['importer_exit_code'] = (int)$result['exit_code'];
    $decoded = json_decode(trim($result['stdout']), true);
    if (!is_array($decoded)) {
        $base['status'] = 'importer_invalid_json';
        $base['importer_status'] = 'invalid_json';
        $base['issue_codes'] = ['importer_invalid_json'];
        return $base;
    }

    $issues = array_values(array_filter((array)($decoded['issues'] ?? []), 'is_array'));
    $summary = p0_array($decoded['summary'] ?? null);
    $base['importer_status'] = (string)($decoded['status'] ?? 'unknown');
    $base['target_date_rows'] = (int)($summary['target_date_rows'] ?? 0);
    $base['traffic_evidence_rows'] = count(array_values(array_filter((array)($decoded['traffic_evidence'] ?? []), 'is_array')));
    $base['p0_completion_status'] = (string)($decoded['p0_completion_status'] ?? '');
    $base['issue_codes'] = array_values(array_filter(array_map(
        static fn(array $issue): string => (string)($issue['code'] ?? ''),
        $issues
    )));
    $base['ready_to_execute'] = (int)$result['exit_code'] === 0 && $base['importer_status'] === 'ready_to_import';
    $base['status'] = $base['ready_to_execute'] ? 'ready_to_import' : 'blocked';

    return $base;
}

/**
 * @return array<string, mixed>
 */
function p0_hotel_scoped_traffic_payload_contract(string $platform, string $targetDate, int $systemHotelId): array
{
    $hotelIdField = $platform === 'meituan' ? 'poiId|poi_id' : 'hotelId|hotel_id';
    $acceptedContainers = $platform === 'ctrip'
        ? ['traffic[]', 'standard_rows[]', 'nested Ctrip traffic rows discovered by extractor']
        : ['traffic[]', 'data.flowData[]', 'data.trafficData[]', 'data.list[]', 'list[]'];

    return [
        'status' => 'requires_real_ota_payload',
        'template_policy' => 'contract_only_not_importable',
        'platform' => $platform,
        'target_date' => $targetDate,
        'system_hotel_id' => $systemHotelId,
        'accepted_payload_containers' => $acceptedContainers,
        'required_row_evidence' => [
            'row_date' => 'Each imported row must carry an explicit date/dataDate/statDate/stat_date/data_date/reportDate/day equal to ' . $targetDate . '; browser-captured dates must carry row/date_source evidence from the response row or request.query.* / request.payload.*, while command/default dates are not accepted as row-date evidence.',
            'source_path' => 'Each imported row must carry _source_path/source_path/json_path/_capture_source with a structured location such as data.flowData.0.',
            'capture_evidence' => 'Each imported row must carry complete desensitized evidence: source_trace_id plus source_url_hash/url_hash. request_hash and payload_hash are supplementary only; raw URL, Cookie, token, and profile path are forbidden.',
            'hotel_identifier' => 'Each row should include ' . $hotelIdField . '; missing platform hotel id is not proof of OTA scope.',
        ],
        'required_metric_aliases' => [
            'list_exposure' => ['listExposure', 'list_exposure', 'exposure', 'exposureCount', 'impressions'],
            'detail_exposure' => ['detailExposure', 'detail_exposure', 'visitorCount', 'uniqueVisitors', 'views'],
            'flow_rate' => ['flowRate', 'flow_rate', 'conversionRate', 'conversion_rate', 'cvr'],
            'order_filling_num' => ['orderFillingNum', 'order_filling_num', 'clickCount', 'clicks', 'orderVisitors'],
            'order_submit_num' => ['orderSubmitNum', 'order_submit_num', 'submitUsers', 'orderCount', 'bookOrderNum', 'orders'],
        ],
        'required_storage_fields' => [
            'online_daily_data.list_exposure',
            'online_daily_data.detail_exposure',
            'online_daily_data.flow_rate',
            'online_daily_data.order_filling_num',
            'online_daily_data.order_submit_num',
            'online_daily_data.raw_data.field_facts',
        ],
        'importer_rejects' => [
            'sensitive_payload_keys_detected',
            'target_date_traffic_rows_missing',
            'target_date_explicit_row_date_missing',
            'target_date_source_path_missing',
            'required_traffic_metric_keys_missing',
            'traffic_field_fact_preview_rows_incomplete',
            'desensitized_capture_evidence_missing',
            'target_date_platform_hotel_identifier_missing',
        ],
        'dry_run_acceptance' => [
            'status' => 'ready_to_import',
            'summary.target_date_rows' => '>0',
            'summary.defaulted_date_rows' => 0,
            'summary.missing_source_path_rows' => 0,
            'summary.missing_platform_hotel_identifier_rows' => 0,
            'summary.missing_complete_capture_evidence_rows' => 0,
            'summary.rows_with_complete_desensitized_capture_evidence' => 'equals summary.target_date_rows',
            'summary.missing_metric_keys' => [],
            'traffic_evidence_contract.status' => 'ready_for_p0_verifier_external_evidence',
        ],
    ];
}

/**
 * @param array<string, mixed> $sources
 * @return array<int, array<string, mixed>>
 */
function p0_hotel_scoped_traffic_sources(string $platform, string $targetDate, int $scopeSystemHotelId, array $sources): array
{
    $rows = [];
    $seen = [];
    foreach ((array)($sources['traffic_source_samples'] ?? []) as $sample) {
        if (!is_array($sample)) {
            continue;
        }
        $systemHotelId = (int)($sample['system_hotel_id'] ?? 0);
        if ($systemHotelId <= 0) {
            continue;
        }
        if ($scopeSystemHotelId > 0 && $systemHotelId !== $scopeSystemHotelId) {
            continue;
        }
        $dataSourceId = (int)($sample['id'] ?? 0);
        $key = $platform . ':' . $systemHotelId . ':' . $dataSourceId;
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $rows[] = array_merge([
            'platform' => $platform,
            'system_hotel_id' => $systemHotelId,
            'data_source_id' => $dataSourceId > 0 ? $dataSourceId : null,
            'ingestion_method' => (string)($sample['ingestion_method'] ?? ''),
            'status' => (string)($sample['status'] ?? ''),
            'enabled' => (bool)($sample['enabled'] ?? false),
            'last_sync_status' => (string)($sample['last_sync_status'] ?? ''),
            'managed_by_p0' => (bool)($sample['managed_by_p0'] ?? false),
            'capture_sections_has_traffic' => (bool)($sample['capture_sections_has_traffic'] ?? false),
            'manual_login_state_verified' => (bool)($sample['manual_login_state_verified'] ?? false),
            'secret_configured' => (bool)($sample['secret_configured'] ?? false),
            'payload_candidate_scan' => p0_hotel_scoped_payload_candidate_scan($platform, $targetDate, $systemHotelId),
            'capture_bridge' => p0_hotel_scoped_capture_bridge_contract($platform, $targetDate, $systemHotelId),
            'payload_contract' => p0_hotel_scoped_traffic_payload_contract($platform, $targetDate, $systemHotelId),
        ], p0_hotel_scoped_traffic_commands($platform, $targetDate, $systemHotelId));
    }

    if ($rows === [] && $scopeSystemHotelId > 0) {
        $rows[] = array_merge([
            'platform' => $platform,
            'system_hotel_id' => $scopeSystemHotelId,
            'data_source_id' => null,
            'ingestion_method' => '',
            'status' => 'not_registered',
            'enabled' => false,
            'last_sync_status' => '',
            'managed_by_p0' => false,
            'capture_sections_has_traffic' => false,
            'manual_login_state_verified' => false,
            'secret_configured' => false,
            'payload_candidate_scan' => p0_hotel_scoped_payload_candidate_scan($platform, $targetDate, $scopeSystemHotelId),
            'capture_bridge' => p0_hotel_scoped_capture_bridge_contract($platform, $targetDate, $scopeSystemHotelId),
            'payload_contract' => p0_hotel_scoped_traffic_payload_contract($platform, $targetDate, $scopeSystemHotelId),
        ], p0_hotel_scoped_traffic_commands($platform, $targetDate, $scopeSystemHotelId));
    }

    return $rows;
}

/**
 * @param array<string, mixed> $summary
 * @param array<int, string> $requiredInputs
 */
function p0_traffic_availability_status(array $summary, array $config, array $profile, array $sources, array $requiredInputs): string
{
    if ((int)($summary['traffic_rows'] ?? 0) > 0) {
        return 'ready';
    }
    $hasManualTrafficUrl = (int)($config['with_traffic_url_count'] ?? 0) > 0
        || in_array('traffic_request_url_or_cdp_endpoint_evidence', $requiredInputs, true) === false;
    $hasManualPayload = (int)($config['with_payload_context_count'] ?? 0) > 0;
    $hasManualAuth = (int)($config['with_auth_context_count'] ?? 0) > 0;
    $hasTrafficSource = (int)($sources['traffic_source_count'] ?? 0) > 0;
    if ($hasManualTrafficUrl && $hasManualPayload && $hasManualAuth && $hasTrafficSource) {
        return 'manual_traffic_context_present_unverified';
    }
    if ((int)($profile['profile_dir_count'] ?? 0) > 0) {
        if ((int)($sources['traffic_profile_login_verified_count'] ?? 0) > 0) {
            return 'profile_login_verified_without_target_date_rows';
        }
        return 'profile_context_present_unverified';
    }
    if ((int)($sources['traffic_ready_count'] ?? 0) > 0) {
        return 'traffic_data_source_ready_without_target_date_rows';
    }
    if ((int)($sources['traffic_waiting_config_count'] ?? 0) > 0) {
        return 'traffic_data_source_registered_waiting_config_without_target_date_rows';
    }
    if ((int)($sources['traffic_source_count'] ?? 0) > 0) {
        return 'traffic_data_source_registered_without_target_date_rows';
    }
    if ($requiredInputs !== []) {
        return 'blocked_missing_traffic_context';
    }

    return 'unknown';
}

/**
 * @param array<int, string> $requiredMetricKeys
 * @param array<string, string> $requiredStorageFields
 * @return array<string, array<string, mixed>>
 */
function p0_traffic_field_loop_matrix_index(array $requiredMetricKeys, array $requiredStorageFields, string $status = 'missing'): array
{
    $matrix = [];
    foreach ($requiredMetricKeys as $metricKey) {
        $metricKey = (string)$metricKey;
        $matrix[$metricKey] = [
            'metric_key' => $metricKey,
            'expected_storage_field' => (string)($requiredStorageFields[$metricKey] ?? ''),
            'status' => $status,
            'row_count' => 0,
            'complete_row_count' => 0,
            'sample_row_id' => null,
            'capture_evidence_present' => false,
            'desensitized_capture_evidence_present' => false,
            'capture_evidence_matches_row' => false,
            'source_path_present' => false,
            'source_path_structured' => false,
            'metric_key_present' => false,
            'storage_field_present' => false,
            'storage_field_matches_expected' => false,
            'stored_value_present' => false,
            'ui_status_ready' => false,
        ];
    }

    return $matrix;
}

/**
 * @param array<string, array<string, mixed>> $matrix
 * @return array<int, array<string, mixed>>
 */
function p0_traffic_field_loop_matrix_values(array $matrix): array
{
    return array_values($matrix);
}

/**
 * @param array<string, mixed> $entry
 */
function p0_mark_traffic_field_loop_metric(array &$entry, array $fact, mixed $rowId, bool $uiReady, string $expectedStorageField, bool $factReady, bool $sourcePathStructured, bool $captureEvidenceMatchesRow, bool $desensitizedCaptureEvidencePresent): void
{
    $sourcePath = trim((string)($fact['source_path'] ?? ''));
    $storageField = trim((string)($fact['storage_field'] ?? ''));
    $storedValuePresent = ($fact['stored_value_present'] ?? null) === true;

    $entry['row_count'] = (int)($entry['row_count'] ?? 0) + 1;
    if ($factReady) {
        $entry['complete_row_count'] = (int)($entry['complete_row_count'] ?? 0) + 1;
    }
    if (($entry['sample_row_id'] ?? null) === null) {
        $entry['sample_row_id'] = $rowId;
    }
    $entry['capture_evidence_present'] = (bool)($entry['capture_evidence_present'] ?? false) || p0_array($fact['capture_evidence'] ?? null) !== [];
    $entry['desensitized_capture_evidence_present'] = (bool)($entry['desensitized_capture_evidence_present'] ?? false) || $desensitizedCaptureEvidencePresent;
    $entry['capture_evidence_matches_row'] = (bool)($entry['capture_evidence_matches_row'] ?? false) || $captureEvidenceMatchesRow;
    $entry['source_path_present'] = (bool)($entry['source_path_present'] ?? false) || $sourcePath !== '';
    $entry['source_path_structured'] = (bool)($entry['source_path_structured'] ?? false) || $sourcePathStructured;
    $entry['metric_key_present'] = true;
    $entry['storage_field_present'] = (bool)($entry['storage_field_present'] ?? false) || $storageField !== '';
    $entry['storage_field_matches_expected'] = (bool)($entry['storage_field_matches_expected'] ?? false) || $storageField === $expectedStorageField;
    $entry['stored_value_present'] = (bool)($entry['stored_value_present'] ?? false) || $storedValuePresent;
    $entry['ui_status_ready'] = (bool)($entry['ui_status_ready'] ?? false) || $uiReady;
    $entry['status'] = ((int)($entry['complete_row_count'] ?? 0) > 0 && (bool)($entry['ui_status_ready'] ?? false))
        ? 'complete'
        : 'incomplete';
}

/**
 * @param array<string, array<string, mixed>> $matrix
 */
function p0_finalize_traffic_field_loop_matrix(array &$matrix): void
{
    foreach ($matrix as &$entry) {
        if ((int)($entry['row_count'] ?? 0) <= 0 && (string)($entry['status'] ?? '') !== 'no_target_date_traffic_rows') {
            $entry['status'] = 'missing';
        }
    }
    unset($entry);
}

/**
 * @param array<string, mixed> $row
 * @param array<string, mixed> $raw
 * @return array<string, mixed>
 */
function p0_traffic_row_ui_status(array $row, array $raw): array
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
 * @return array<string, mixed>
 */
function p0_traffic_field_fact_closure(string $platform, string $targetDate, int $systemHotelId = 0): array
{
    $requiredMetricKeys = p0_required_traffic_metric_keys();
    $requiredStorageFields = p0_required_traffic_storage_field_map();
    $base = [
        'status' => 'not_loaded',
        'target_date' => $targetDate,
        'system_hotel_id' => $systemHotelId > 0 ? $systemHotelId : null,
        'hotel_scope_policy' => $systemHotelId > 0 ? 'system_hotel_id' : 'platform_date',
        'required_metric_keys' => $requiredMetricKeys,
        'required_storage_fields' => array_values($requiredStorageFields),
        'traffic_row_count' => 0,
        'rows_with_field_facts' => 0,
        'complete_metric_keys' => [],
        'missing_metric_keys' => $requiredMetricKeys,
        'incomplete_metric_keys' => [],
        'ui_statuses' => [],
        'ui_status_ready_rows' => 0,
        'ui_status_incomplete_rows' => 0,
        'platform_hotel_identifier_source' => p0_platform_hotel_identifier_source($platform),
        'platform_hotel_identifier_status' => 'not_loaded',
        'platform_hotel_identifier_rows' => 0,
        'missing_platform_hotel_identifier_rows' => 0,
        'desensitized_capture_evidence_count' => 0,
        'matched_capture_evidence_count' => 0,
        'capture_evidence_mismatch_count' => 0,
        'sample_ui_statuses' => [],
        'sample_facts' => [],
        'field_loop_matrix' => p0_traffic_field_loop_matrix_values(p0_traffic_field_loop_matrix_index($requiredMetricKeys, $requiredStorageFields, 'not_loaded')),
        'sensitive_values_exposed' => false,
    ];

    if (!p0_table_exists('online_daily_data')) {
        $base['status'] = 'source_table_missing';
        return $base;
    }

    $columns = p0_table_columns('online_daily_data');
    foreach (['source', 'data_date', 'data_type', 'raw_data'] as $column) {
        if (!isset($columns[$column])) {
            $base['status'] = 'required_column_missing';
            $base['missing_column'] = $column;
            return $base;
        }
    }

    $query = Db::name('online_daily_data')
        ->where('source', $platform)
        ->where('data_date', $targetDate)
        ->whereIn('data_type', ['traffic', 'flow', 'conversion']);
    if ($systemHotelId > 0 && isset($columns['system_hotel_id'])) {
        $query->where('system_hotel_id', $systemHotelId);
    } elseif ($systemHotelId > 0) {
        $base['status'] = 'required_column_missing';
        $base['missing_column'] = 'system_hotel_id';
        return $base;
    }
    $fieldList = array_values(array_filter([
        'id',
        'source',
        'data_date',
        'data_type',
        'raw_data',
        isset($columns['list_exposure']) ? 'list_exposure' : '',
        isset($columns['detail_exposure']) ? 'detail_exposure' : '',
        isset($columns['flow_rate']) ? 'flow_rate' : '',
        isset($columns['order_filling_num']) ? 'order_filling_num' : '',
        isset($columns['order_submit_num']) ? 'order_submit_num' : '',
        isset($columns['source_trace_id']) ? 'source_trace_id' : '',
        isset($columns['sync_task_id']) ? 'sync_task_id' : '',
    ], static fn(string $field): bool => $field !== ''));
    $rows = $query->field(implode(',', $fieldList))->select()->toArray();
    $base['traffic_row_count'] = count($rows);
    if ($rows === []) {
        $base['status'] = 'no_target_date_traffic_rows';
        $base['platform_hotel_identifier_status'] = 'no_target_date_traffic_rows';
        $base['field_loop_matrix'] = p0_traffic_field_loop_matrix_values(p0_traffic_field_loop_matrix_index($requiredMetricKeys, $requiredStorageFields, 'no_target_date_traffic_rows'));
        return $base;
    }

    $complete = [];
    $incomplete = [];
    $fieldLoopMatrix = p0_traffic_field_loop_matrix_index($requiredMetricKeys, $requiredStorageFields);
    foreach ($rows as $row) {
        $raw = json_decode((string)($row['raw_data'] ?? ''), true);
        if (!is_array($raw)) {
            continue;
        }
        $facts = p0_array($raw['field_facts'] ?? null);
        if ($facts !== []) {
            $base['rows_with_field_facts']++;
        }
        $rowEvidence = p0_external_desensitized_capture_evidence($raw);
        $rowSourceTraceId = trim((string)($row['source_trace_id'] ?? $raw['source_trace_id'] ?? $rowEvidence['source_trace_id'] ?? ''));
        $rowSourceUrlHash = trim((string)($rowEvidence['source_url_hash'] ?? ''));
        if (p0_platform_hotel_identifier_present($raw, $platform)) {
            $base['platform_hotel_identifier_rows']++;
        } else {
            $base['missing_platform_hotel_identifier_rows']++;
        }
        $uiStatus = p0_traffic_row_ui_status($row, $raw);
        $uiFieldFactStatus = trim((string)($uiStatus['status'] ?? $uiStatus['field_fact_status'] ?? ''));
        if ($uiFieldFactStatus !== '') {
            $base['ui_statuses'][$uiFieldFactStatus] = true;
        }
        $uiReady = $uiFieldFactStatus === 'ready'
            && ($uiStatus['raw_data_exposed'] ?? null) === false
            && (int)($uiStatus['missing_count'] ?? -1) === 0
            && (int)($uiStatus['stored_value_missing_count'] ?? -1) === 0
            && (int)($uiStatus['captured_count'] ?? 0) >= count($requiredMetricKeys)
            && (int)($uiStatus['capture_evidence_count'] ?? 0) >= count($requiredMetricKeys)
            && (int)($uiStatus['desensitized_capture_evidence_count'] ?? 0) >= count($requiredMetricKeys)
            && (int)($uiStatus['source_path_count'] ?? 0) >= count($requiredMetricKeys)
            && (int)($uiStatus['structured_source_path_count'] ?? 0) >= count($requiredMetricKeys)
            && (int)($uiStatus['storage_field_count'] ?? 0) >= count($requiredMetricKeys);
        if ($uiReady) {
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
        foreach ($facts as $fact) {
            if (!is_array($fact)) {
                continue;
            }
            $metricKey = trim((string)($fact['metric_key'] ?? ''));
            if (!isset($requiredStorageFields[$metricKey])) {
                continue;
            }
            $sourcePath = trim((string)($fact['source_path'] ?? ''));
            $storageField = trim((string)($fact['storage_field'] ?? ''));
            $captureEvidence = p0_array($fact['capture_evidence'] ?? null);
            $desensitizedCaptureEvidence = p0_external_desensitized_capture_evidence($captureEvidence);
            $captureEvidenceMatchesRow = p0_field_fact_capture_evidence_matches_row($fact, $rowSourceTraceId, $rowSourceUrlHash);
            if ($desensitizedCaptureEvidence !== []) {
                $base['desensitized_capture_evidence_count']++;
            }
            if ($captureEvidenceMatchesRow) {
                $base['matched_capture_evidence_count']++;
            } else {
                $base['capture_evidence_mismatch_count']++;
            }
            $storedValuePresent = $fact['stored_value_present'] ?? null;
            $sourcePathStructured = p0_source_path_is_structured($sourcePath);
            $factReady = $sourcePathStructured
                && $storageField === $requiredStorageFields[$metricKey]
                && $captureEvidenceMatchesRow
                && $storedValuePresent === true;
            if ($factReady) {
                $complete[$metricKey] = true;
            } else {
                $incomplete[$metricKey] = true;
            }
            p0_mark_traffic_field_loop_metric(
                $fieldLoopMatrix[$metricKey],
                $fact,
                $row['id'] ?? null,
                $uiReady,
                $requiredStorageFields[$metricKey],
                $factReady,
                $sourcePathStructured,
                $captureEvidenceMatchesRow,
                $desensitizedCaptureEvidence !== []
            );
            if (count($base['sample_facts']) < 10) {
                $base['sample_facts'][] = [
                    'row_id' => $row['id'] ?? null,
                    'metric_key' => $metricKey,
                    'source_path_present' => $sourcePath !== '',
                    'source_path_structured' => $sourcePathStructured,
                    'storage_field' => $storageField,
                    'expected_storage_field' => $requiredStorageFields[$metricKey],
                    'capture_evidence_present' => $captureEvidence !== [],
                    'desensitized_capture_evidence_present' => $desensitizedCaptureEvidence !== [],
                    'capture_evidence_matches_row' => $captureEvidenceMatchesRow,
                    'stored_value_present' => $storedValuePresent,
                    'status' => $factReady ? 'complete' : 'incomplete',
                ];
            }
        }
    }

    $completeKeys = array_values(array_intersect($requiredMetricKeys, array_keys($complete)));
    $missingKeys = array_values(array_diff($requiredMetricKeys, $completeKeys));
    $incompleteKeys = array_values(array_diff(array_keys($incomplete), $completeKeys));
    $base['complete_metric_keys'] = $completeKeys;
    $base['missing_metric_keys'] = $missingKeys;
    $base['incomplete_metric_keys'] = $incompleteKeys;
    $base['ui_statuses'] = array_values(array_keys((array)$base['ui_statuses']));
    $base['platform_hotel_identifier_status'] = (int)$base['missing_platform_hotel_identifier_rows'] === 0
        && (int)$base['platform_hotel_identifier_rows'] > 0
        ? 'ready'
        : 'missing';
    p0_finalize_traffic_field_loop_matrix($fieldLoopMatrix);
    $base['field_loop_matrix'] = p0_traffic_field_loop_matrix_values($fieldLoopMatrix);
    $base['status'] = $missingKeys === []
        && $incompleteKeys === []
        && (int)$base['ui_status_ready_rows'] > 0
        && (int)$base['ui_status_incomplete_rows'] === 0
        && (string)$base['platform_hotel_identifier_status'] === 'ready'
        ? 'ready'
        : 'incomplete';
    if ((int)$base['rows_with_field_facts'] === 0) {
        $base['status'] = 'field_facts_missing';
    } elseif ((int)$base['ui_status_ready_rows'] === 0 || (int)$base['ui_status_incomplete_rows'] > 0) {
        $base['status'] = 'ui_status_incomplete';
    } elseif ((string)$base['platform_hotel_identifier_status'] !== 'ready') {
        $base['status'] = 'platform_hotel_identifier_missing';
    }

    return $base;
}

/**
 * @param array<string, array<string, mixed>> $sourceSummaryMap
 * @param array<int, string> $platforms
 * @return array<int, array<string, mixed>>
 */
function p0_traffic_evidence_availability(array $sourceSummaryMap, array $platforms, string $targetDate, array $externalTrafficEvidence = [], int $systemHotelId = 0): array
{
    $app = p0_initialize_app();
    if (!($app['ok'] ?? false)) {
        return [[
            'status' => 'unavailable',
            'reason' => (string)($app['reason'] ?? 'unknown'),
            'sensitive_values_exposed' => false,
        ]];
    }

    $result = [];
    $externalPlatforms = p0_array($externalTrafficEvidence['platforms'] ?? null);
    foreach ($platforms as $platform) {
        $summary = p0_array($sourceSummaryMap[$platform] ?? null);
        $config = p0_config_availability($platform);
        $profile = p0_profile_dir_availability($platform);
        $sources = p0_platform_data_source_availability($platform);
        $template = p0_endpoint_template_availability($platform);
        $externalPlatformEvidence = p0_array($externalPlatforms[$platform] ?? null);
        $trafficFieldFactClosure = p0_traffic_field_fact_closure($platform, $targetDate, $systemHotelId);
        $requiredInputs = p0_traffic_required_inputs($platform, $config, $profile, $sources, $template, $summary);
        $pathOptions = p0_traffic_closure_path_options($platform, $config, $profile, $sources, $template, $summary);
        $recommendedAction = p0_recommended_traffic_action($platform, $pathOptions);
        $hotelScopedSources = p0_hotel_scoped_traffic_sources($platform, $targetDate, $systemHotelId, $sources);
        $hotelScopedCommands = [];
        $hotelScopedPayloadContracts = [];
        $hotelScopedCaptureBridges = [];
        foreach ($hotelScopedSources as $hotelScopedSource) {
            if (!is_array($hotelScopedSource)) {
                continue;
            }
            $hotelScopedCommands[] = [
                'platform' => (string)($hotelScopedSource['platform'] ?? $platform),
                'system_hotel_id' => (int)($hotelScopedSource['system_hotel_id'] ?? 0),
                'data_source_id' => $hotelScopedSource['data_source_id'] ?? null,
                'payload_import_command' => (string)($hotelScopedSource['payload_import_command'] ?? ''),
                'payload_import_execute_command' => (string)($hotelScopedSource['payload_import_execute_command'] ?? ''),
                'traffic_evidence_verifier_command' => (string)($hotelScopedSource['traffic_evidence_verifier_command'] ?? ''),
                'p0_verifier_command' => (string)($hotelScopedSource['p0_verifier_command'] ?? ''),
            ];
            $payloadContract = p0_array($hotelScopedSource['payload_contract'] ?? null);
            if ($payloadContract !== []) {
                $hotelScopedPayloadContracts[] = $payloadContract;
            }
            $captureBridge = p0_array($hotelScopedSource['capture_bridge'] ?? null);
            if ($captureBridge !== []) {
                $hotelScopedCaptureBridges[] = $captureBridge;
            }
        }

        $result[] = [
            'platform' => $platform,
            'system_hotel_id' => $systemHotelId > 0 ? $systemHotelId : null,
            'hotel_scope_policy' => $systemHotelId > 0 ? 'system_hotel_id' : 'platform_date',
            'status' => p0_traffic_availability_status($summary, $config, $profile, $sources, $requiredInputs),
            'target_date' => [
                'rows' => (int)($summary['target_date_rows'] ?? 0),
                'traffic_rows' => (int)($summary['traffic_rows'] ?? 0),
                'data_types' => array_values(array_map('strval', (array)($summary['target_date_data_types'] ?? []))),
            ],
            'manual_context' => $config,
            'automatic_context' => $profile,
            'registered_sources' => $sources,
            'evidence_template' => $template,
            'traffic_field_fact_closure' => $trafficFieldFactClosure,
            'required_next_inputs' => $requiredInputs,
            'closure_path_options' => $pathOptions,
            'hotel_scoped_sources' => $hotelScopedSources,
            'hotel_scoped_commands' => $hotelScopedCommands,
            'hotel_scoped_payload_contracts' => $hotelScopedPayloadContracts,
            'hotel_scoped_capture_bridges' => $hotelScopedCaptureBridges,
            'recommended_action' => $recommendedAction,
            'acceptance_contract' => p0_traffic_acceptance_contract(),
            'external_traffic_evidence' => $externalPlatformEvidence,
            'validated_desensitized_evidence_present' => (bool)($externalPlatformEvidence['validated_desensitized_evidence_present'] ?? false),
            'action_mode' => (string)($recommendedAction['mode'] ?? ''),
            'action_entry' => (string)($recommendedAction['entry'] ?? ''),
            'scope_policy' => 'ota_channel_only',
            'sensitive_values_exposed' => false,
        ];
    }

    return $result;
}

/**
 * @param array<string, mixed> $platform
 * @param array<string, mixed> $summary
 * @param array<string, bool> $globalReadiness
 * @param array<int, array<string, mixed>> $issues
 * @return array<string, mixed>
 */
function p0_analyze_platform(string $name, array $platform, array $summary, array $globalReadiness, array &$issues): array
{
    $sourceRows = p0_array($platform['source_rows'] ?? null);
    $fieldFacts = p0_array($platform['field_facts'] ?? null);
    if ($fieldFacts === []) {
        $fieldFacts = p0_array($summary['field_fact_closure_summary'] ?? null);
    }

    $targetRows = (int)($sourceRows['count'] ?? $summary['target_date_rows'] ?? 0);
    $dataTypes = array_values(array_map('strval', (array)($summary['target_date_data_types'] ?? $sourceRows['data_types'] ?? [])));
    $trafficDataTypes = array_values(array_intersect($dataTypes, ['traffic', 'flow', 'conversion']));
    $sourceChainReferenceOnly = $targetRows > 0 && $trafficDataTypes === [];
    if ($targetRows <= 0) {
        $sourceChainScope = 'no_target_date_source_rows';
        $sourceChainPolicy = 'No target-date source rows are loaded; P0 traffic closure still requires target-date traffic rows and p0_traffic_gate.status=ready.';
    } elseif ($sourceChainReferenceOnly) {
        $sourceChainScope = 'reference_only_non_traffic_source_rows';
        $sourceChainPolicy = 'Source field facts are non-traffic reference evidence; P0 traffic closure still requires target-date traffic rows and p0_traffic_gate.status=ready.';
    } else {
        $sourceChainScope = 'traffic_source_rows';
        $sourceChainPolicy = 'Source field facts include traffic-scope rows but P0 closure still requires p0_traffic_gate.status=ready.';
    }
    $sampleTraces = p0_array($sourceRows['sample_traces'] ?? null);
    $traceCount = 0;
    foreach ($sampleTraces as $trace) {
        if (is_array($trace) && trim((string)($trace['source_trace_id'] ?? '')) !== '') {
            $traceCount++;
        }
    }

    $factCount = (int)($fieldFacts['fact_count'] ?? 0);
    $completeFactCount = (int)($fieldFacts['complete_fact_count'] ?? 0);
    $incompleteCapturedFactCount = (int)($fieldFacts['incomplete_captured_fact_count'] ?? 0);
    $metricKeyCount = (int)($fieldFacts['metric_key_count'] ?? 0);
    $captureEvidenceCount = (int)($fieldFacts['capture_evidence_count'] ?? 0);
    $desensitizedCaptureEvidenceCount = (int)($fieldFacts['desensitized_capture_evidence_count'] ?? 0);
    $sourcePathCount = (int)($fieldFacts['source_path_count'] ?? 0);
    $structuredSourcePathCount = (int)($fieldFacts['structured_source_path_count'] ?? 0);
    $storageFieldCount = (int)($fieldFacts['storage_field_count'] ?? 0);
    $storedValuePresentCount = (int)($fieldFacts['stored_value_present_count'] ?? 0);
    $storedValueMissingCount = (int)($fieldFacts['stored_value_missing_count'] ?? 0);
    $rawDataExposed = (bool)($fieldFacts['raw_data_exposed'] ?? false);
    $fieldFactStatus = (string)($fieldFacts['status'] ?? $summary['field_fact_status'] ?? 'not_loaded');
    $storageTable = (string)($summary['storage_table'] ?? $fieldFacts['storage_table'] ?? 'online_daily_data');

    $stages = [];
    p0_require_stage(
        $stages,
        $issues,
        $name,
        'capture_evidence',
        $targetRows > 0
            && $traceCount > 0
            && $completeFactCount > 0
            && $desensitizedCaptureEvidenceCount >= $completeFactCount,
        'Target-date OTA source rows must include desensitized source_trace_id samples, and field facts must include desensitized source_trace_id plus source_url_hash evidence.',
        [
            'target_date_rows' => $targetRows,
            'sample_trace_id_count' => $traceCount,
            'latest_trace_time_present' => trim((string)($sourceRows['latest_trace_time'] ?? '')) !== '',
            'latest_trace_time_reference_only' => true,
            'capture_evidence_count' => $captureEvidenceCount,
            'desensitized_capture_evidence_count' => $desensitizedCaptureEvidenceCount,
            'complete_fact_count' => $completeFactCount,
        ]
    );
    p0_require_stage(
        $stages,
        $issues,
        $name,
        'source_path',
        $completeFactCount > 0 && $sourcePathCount >= $completeFactCount && $structuredSourcePathCount >= $completeFactCount,
        'Field facts must prove structured source_path for every complete captured fact.',
        [
            'source_path_count' => $sourcePathCount,
            'structured_source_path_count' => $structuredSourcePathCount,
            'complete_fact_count' => $completeFactCount,
        ]
    );
    p0_require_stage(
        $stages,
        $issues,
        $name,
        'metric_key',
        $completeFactCount > 0 && $metricKeyCount >= $completeFactCount,
        'Field facts must prove metric_key for every complete captured fact.',
        ['metric_key_count' => $metricKeyCount, 'complete_fact_count' => $completeFactCount]
    );
    p0_require_stage(
        $stages,
        $issues,
        $name,
        'storage_field',
        $completeFactCount > 0 && $storageFieldCount >= $completeFactCount && $storageTable === 'online_daily_data',
        'Field facts must prove the online_daily_data storage target.',
        [
            'storage_field_count' => $storageFieldCount,
            'complete_fact_count' => $completeFactCount,
            'storage_table' => $storageTable,
        ]
    );
    p0_require_stage(
        $stages,
        $issues,
        $name,
        'stored_value',
        $completeFactCount > 0 && $storedValuePresentCount >= $completeFactCount && $storedValueMissingCount === 0,
        'Field facts must prove stored values for every complete captured fact.',
        [
            'stored_value_present_count' => $storedValuePresentCount,
            'stored_value_missing_count' => $storedValueMissingCount,
            'complete_fact_count' => $completeFactCount,
        ]
    );
    p0_require_stage(
        $stages,
        $issues,
        $name,
        'ui_status',
        $fieldFactStatus === 'ready' && ($globalReadiness['ui_status'] ?? false),
        'UI status must expose a ready field_fact_status without raw_data exposure.',
        [
            'field_fact_status' => $fieldFactStatus,
            'raw_data_exposed' => $rawDataExposed,
        ]
    );
    p0_require_stage(
        $stages,
        $issues,
        $name,
        'verifier',
        (bool)($globalReadiness['verifier'] ?? false),
        'Verifier commands must cover runtime field status, live action queue, and P0 field-loop closure.',
        []
    );

    if ($targetRows > 0 && $factCount === 0) {
        p0_add_issue($issues, 'incomplete', $name . '_field_facts_missing', 'Target-date source rows exist but field facts are not loaded.');
    }
    if ($incompleteCapturedFactCount > 0) {
        p0_add_issue(
            $issues,
            'incomplete',
            $name . '_field_fact_closure_incomplete',
            'Captured field facts are missing capture_evidence, source_path, metric_key, or storage_field.',
            ['incomplete_captured_fact_count' => $incompleteCapturedFactCount]
        );
    }
    if ($rawDataExposed) {
        p0_add_issue($issues, 'failed', $name . '_raw_data_exposed', 'P0 verifier output must not expose raw_data.');
    }

    return [
        'platform' => $name,
        'target_date_rows' => $targetRows,
        'data_types' => $dataTypes,
        'traffic_data_types' => $trafficDataTypes,
        'source_chain_reference_only' => $sourceChainReferenceOnly,
        'source_chain_scope' => $sourceChainScope,
        'source_chain_policy' => $sourceChainPolicy,
        'latest_available' => $summary['latest_available'] ?? $sourceRows['latest_available'] ?? null,
        'latest_available_reference_only' => (bool)($summary['latest_available_reference_only'] ?? false),
        'field_fact_status' => $fieldFactStatus,
        'field_fact_summary' => [
            'fact_count' => $factCount,
            'complete_fact_count' => $completeFactCount,
            'explicit_missing_fact_count' => (int)($fieldFacts['explicit_missing_fact_count'] ?? 0),
            'incomplete_captured_fact_count' => $incompleteCapturedFactCount,
            'metric_key_count' => $metricKeyCount,
            'capture_evidence_count' => $captureEvidenceCount,
            'desensitized_capture_evidence_count' => $desensitizedCaptureEvidenceCount,
            'source_path_count' => $sourcePathCount,
            'structured_source_path_count' => $structuredSourcePathCount,
            'storage_field_count' => $storageFieldCount,
            'stored_value_present_count' => $storedValuePresentCount,
            'stored_value_missing_count' => $storedValueMissingCount,
            'raw_data_exposed' => $rawDataExposed,
        ],
        'chain' => $stages,
    ];
}

/**
 * @param array<int, array<string, mixed>> $trafficAvailability
 * @return array<string, array<string, mixed>>
 */
function p0_traffic_availability_map(array $trafficAvailability): array
{
    $map = [];
    foreach ($trafficAvailability as $traffic) {
        if (!is_array($traffic)) {
            continue;
        }
        $platform = strtolower(trim((string)($traffic['platform'] ?? '')));
        if ($platform !== '') {
            $map[$platform] = $traffic;
        }
    }

    return $map;
}

/**
 * @param array<string, mixed> $traffic
 * @return array<int, array<string, mixed>>
 */
function p0_platform_traffic_gate_next_steps(array $traffic): array
{
    $commandsByHotel = [];
    foreach (p0_array($traffic['hotel_scoped_commands'] ?? null) as $command) {
        if (!is_array($command)) {
            continue;
        }
        $systemHotelId = (int)($command['system_hotel_id'] ?? 0);
        if ($systemHotelId > 0) {
            $commandsByHotel[$systemHotelId] = $command;
        }
    }

    $bridgesByHotel = [];
    foreach (p0_array($traffic['hotel_scoped_capture_bridges'] ?? null) as $bridge) {
        if (!is_array($bridge)) {
            continue;
        }
        $systemHotelId = (int)($bridge['system_hotel_id'] ?? 0);
        if ($systemHotelId > 0) {
            $bridgesByHotel[$systemHotelId] = $bridge;
        }
    }

    $steps = [];
    foreach (p0_array($traffic['hotel_scoped_sources'] ?? null) as $source) {
        if (!is_array($source)) {
            continue;
        }
        $systemHotelId = (int)($source['system_hotel_id'] ?? 0);
        if ($systemHotelId <= 0) {
            continue;
        }
        $command = p0_array($commandsByHotel[$systemHotelId] ?? null);
        $bridge = p0_array($bridgesByHotel[$systemHotelId] ?? null);
        $payloadCandidateScan = p0_array($source['payload_candidate_scan'] ?? null);
        $steps[] = [
            'platform' => (string)($source['platform'] ?? $traffic['platform'] ?? ''),
            'system_hotel_id' => $systemHotelId,
            'data_source_id' => $source['data_source_id'] ?? null,
            'data_source_status' => (string)($source['status'] ?? ''),
            'last_sync_status' => (string)($source['last_sync_status'] ?? ''),
            'capture_sections_has_traffic' => (bool)($source['capture_sections_has_traffic'] ?? false),
            'manual_login_state_verified' => (bool)($source['manual_login_state_verified'] ?? false),
            'payload_candidate_status' => (string)($payloadCandidateScan['status'] ?? ''),
            'payload_candidate_ready_to_execute' => (bool)($payloadCandidateScan['ready_to_execute'] ?? false),
            'payload_candidate_path' => (string)($payloadCandidateScan['payload_path'] ?? ''),
            'payload_candidate_issue_codes' => array_values(array_map('strval', (array)($payloadCandidateScan['issue_codes'] ?? []))),
            'payload_import_command' => (string)($command['payload_import_command'] ?? ''),
            'payload_import_execute_command' => (string)($command['payload_import_execute_command'] ?? ''),
            'traffic_evidence_verifier_command' => (string)($command['traffic_evidence_verifier_command'] ?? ''),
            'p0_verifier_command' => (string)($command['p0_verifier_command'] ?? ''),
            'capture_output_path' => (string)($bridge['capture_output_path'] ?? ''),
            'browser_login_prepare_command' => (string)($bridge['browser_login_prepare_command'] ?? ''),
            'browser_capture_command' => (string)($bridge['browser_capture_command'] ?? ''),
            'bridge_to_importer_command' => (string)($bridge['bridge_to_importer_command'] ?? ''),
            'bridge_execute_command' => (string)($bridge['bridge_execute_command'] ?? ''),
            'post_import_verifier_command' => (string)($bridge['post_import_verifier_command'] ?? ''),
            'manual_gates' => array_values(array_map('strval', (array)($bridge['manual_gates'] ?? []))),
        ];
    }

    return $steps;
}

/**
 * @param array<string, mixed> $traffic
 * @return array<string, mixed>
 */
function p0_platform_traffic_gate(array $traffic): array
{
    $targetDate = p0_array($traffic['target_date'] ?? null);
    $trafficFieldFacts = p0_array($traffic['traffic_field_fact_closure'] ?? null);
    $externalEvidence = p0_array($traffic['external_traffic_evidence'] ?? null);
    $trafficRows = (int)($targetDate['traffic_rows'] ?? 0);
    $availabilityStatus = (string)($traffic['status'] ?? 'not_loaded');
    $fieldFactStatus = (string)($trafficFieldFacts['status'] ?? 'not_loaded');
    $platformHotelIdentifierStatus = (string)($trafficFieldFacts['platform_hotel_identifier_status'] ?? 'not_loaded');
    $platformHotelIdentifierSource = (string)($trafficFieldFacts['platform_hotel_identifier_source'] ?? '');
    $platformHotelIdentifierRows = (int)($trafficFieldFacts['platform_hotel_identifier_rows'] ?? 0);
    $missingPlatformHotelIdentifierRows = (int)($trafficFieldFacts['missing_platform_hotel_identifier_rows'] ?? 0);
    $fieldLoopMatrix = array_values(array_filter(p0_array($trafficFieldFacts['field_loop_matrix'] ?? null), 'is_array'));
    $requiredMetricKeys = array_values(array_map('strval', (array)($trafficFieldFacts['required_metric_keys'] ?? [])));
    $requiredMetricCount = count($requiredMetricKeys);
    $fieldLoopCoversRequired = $fieldLoopMatrix !== []
        && ($requiredMetricCount === 0 || count($fieldLoopMatrix) >= $requiredMetricCount);
    $fieldLoopAll = static function (string $key) use ($fieldLoopMatrix, $fieldLoopCoversRequired): bool {
        if (!$fieldLoopCoversRequired) {
            return false;
        }
        foreach ($fieldLoopMatrix as $fieldLoop) {
            if (!is_array($fieldLoop) || empty($fieldLoop[$key])) {
                return false;
            }
        }
        return true;
    };
    $chainItemStatus = static function (bool $ready) use ($trafficRows): string {
        if ($trafficRows <= 0) {
            return 'no_target_date_traffic_rows';
        }
        return $ready ? 'ready' : 'incomplete';
    };
    $captureEvidenceReady = $fieldLoopAll('capture_evidence_present')
        && $fieldLoopAll('desensitized_capture_evidence_present')
        && $fieldLoopAll('capture_evidence_matches_row');
    $sourcePathReady = $fieldLoopAll('source_path_present')
        && $fieldLoopAll('source_path_structured');
    $metricKeyReady = $fieldLoopAll('metric_key_present')
        && (array)($trafficFieldFacts['missing_metric_keys'] ?? []) === []
        && (array)($trafficFieldFacts['incomplete_metric_keys'] ?? []) === [];
    $storageFieldReady = $fieldLoopAll('storage_field_present')
        && $fieldLoopAll('storage_field_matches_expected');
    $storedValueReady = $fieldLoopAll('stored_value_present');
    $uiStatusReady = $fieldLoopAll('ui_status_ready')
        && (int)($trafficFieldFacts['ui_status_ready_rows'] ?? 0) > 0
        && (int)($trafficFieldFacts['ui_status_incomplete_rows'] ?? 0) === 0;
    $recommendedAction = p0_array($traffic['recommended_action'] ?? null);
    $nextSteps = p0_platform_traffic_gate_next_steps($traffic);
    $externalEvidenceStatus = (string)($externalEvidence['status'] ?? 'not_provided');
    $externalEvidenceValid = (bool)($traffic['validated_desensitized_evidence_present'] ?? $externalEvidence['validated_desensitized_evidence_present'] ?? false);
    $preImportEvidenceStatus = 'not_provided';
    if ($externalEvidenceStatus !== 'not_provided') {
        $preImportEvidenceStatus = $externalEvidenceValid
            ? ($trafficRows > 0 ? 'valid_external_evidence_with_ingested_rows' : 'valid_external_evidence_not_ingested')
            : 'external_evidence_not_valid';
    }
    $ready = $availabilityStatus === 'ready'
        && $fieldFactStatus === 'ready'
        && $trafficRows > 0
        && (bool)($traffic['sensitive_values_exposed'] ?? false) === false;

    $status = 'traffic_context_incomplete';
    if ($ready) {
        $status = 'ready';
    } elseif ($availabilityStatus === 'unavailable') {
        $status = 'unavailable';
    } elseif ($trafficRows <= 0) {
        $status = 'missing_target_date_traffic_rows';
    } elseif ($fieldFactStatus !== 'ready') {
        $status = 'traffic_field_fact_closure_incomplete';
    }

    return [
        'status' => $status,
        'traffic_availability_status' => $availabilityStatus,
        'traffic_rows' => $trafficRows,
        'traffic_field_fact_status' => $fieldFactStatus,
        'platform_hotel_identifier_source' => $platformHotelIdentifierSource,
        'platform_hotel_identifier_status' => $platformHotelIdentifierStatus,
        'platform_hotel_identifier_rows' => $platformHotelIdentifierRows,
        'missing_platform_hotel_identifier_rows' => $missingPlatformHotelIdentifierRows,
        'external_evidence_status' => $externalEvidenceStatus,
        'external_evidence_validated_desensitized_source_proof' => $externalEvidenceValid,
        'pre_import_evidence_status' => $preImportEvidenceStatus,
        'pre_import_evidence_policy' => 'Valid external traffic_evidence proves desensitized source proof only; it is not P0 complete until target-date traffic rows are ingested and this gate is ready.',
        'traffic_closure_chain' => [
            'capture_evidence' => [
                'status' => $chainItemStatus($captureEvidenceReady),
                'required' => 'desensitized source_trace_id plus source_url_hash, matched to the stored traffic row and each field fact',
            ],
            'source_path' => [
                'status' => $chainItemStatus($sourcePathReady),
                'required' => 'structured source_path for every required traffic metric',
            ],
            'metric_key' => [
                'status' => $chainItemStatus($metricKeyReady),
                'required' => implode(',', $requiredMetricKeys),
            ],
            'storage_field' => [
                'status' => $chainItemStatus($storageFieldReady),
                'required' => 'expected online_daily_data traffic columns for every required metric',
            ],
            'stored_value' => [
                'status' => $chainItemStatus($storedValueReady),
                'required' => 'stored value present for every required traffic metric',
            ],
            'ui_status' => [
                'status' => $chainItemStatus($uiStatusReady),
                'required' => 'ready UI field_fact_status with no raw_data exposure',
            ],
            'platform_hotel_identifier' => [
                'status' => $platformHotelIdentifierStatus,
                'required' => $platformHotelIdentifierSource,
            ],
            'verifier' => [
                'status' => $ready ? 'ready' : 'incomplete',
                'required' => 'p0_traffic_gate.status=ready',
            ],
        ],
        'traffic_closure_chain_policy' => 'Every chain item is OTA-channel evidence only; none of these fields may be promoted to whole-hotel operating truth without non-OTA coverage.',
        'missing_metric_keys' => array_values(array_map('strval', (array)($trafficFieldFacts['missing_metric_keys'] ?? []))),
        'required_next_inputs' => array_values(array_map('strval', (array)($traffic['required_next_inputs'] ?? []))),
        'action_mode' => (string)($traffic['action_mode'] ?? $recommendedAction['mode'] ?? ''),
        'action_entry' => (string)($traffic['action_entry'] ?? $recommendedAction['entry'] ?? ''),
        'p0_next_action_mode' => (string)($traffic['action_mode'] ?? $recommendedAction['mode'] ?? ''),
        'p0_next_action_entry' => (string)($traffic['action_entry'] ?? $recommendedAction['entry'] ?? ''),
        'action_status' => (string)($recommendedAction['status'] ?? ''),
        'can_run_now' => (bool)($recommendedAction['can_run_now'] ?? false),
        'action_missing_inputs' => array_values(array_map('strval', (array)($recommendedAction['missing_inputs'] ?? []))),
        'hotel_scoped_next_step_count' => count($nextSteps),
        'p0_next_step_count' => count($nextSteps),
        'hotel_scoped_next_steps' => $nextSteps,
        'next_command_policy' => 'metadata_only_no_sensitive_commands',
        'next_command_policy_detail' => 'Commands are operational handoffs only; they do not prove P0 completion until target-date traffic rows are imported and this verifier returns ready.',
        'gate_policy' => 'Source field facts are reference evidence only; P0 traffic closure requires target-date traffic rows plus ready traffic field facts.',
    ];
}

/**
 * @param array<string, mixed> $result
 */
function p0_render_markdown(array $result): string
{
    $lines = [];
    $lines[] = '# P0 OTA Field Loop Closure';
    $lines[] = '';
    $lines[] = '- status: `' . (string)($result['status'] ?? 'unknown') . '`';
    $lines[] = '- date: `' . (string)($result['scope']['date'] ?? '') . '`';
    $lines[] = '- scope: `ota_channel` / `online_daily_data`';
    if (isset($result['scope']['system_hotel_id']) && $result['scope']['system_hotel_id'] !== null) {
        $lines[] = '- system_hotel_id: `' . (int)$result['scope']['system_hotel_id'] . '`';
    }
    $lines[] = '- completion gate: P0 passes only when each platform `p0_traffic_gate.status` is `ready`; source field status alone is reference evidence.';
    $lines[] = '';
    $lines[] = '| platform | source rows | source scope | source field status | P0 traffic rows | P0 traffic gate | external evidence | pre-import evidence | traffic field facts | facts | complete | structured source paths | stored values | incomplete | chain |';
    $lines[] = '| --- | ---: | --- | --- | ---: | --- | --- | --- | --- | ---: | ---: | ---: | ---: | ---: | --- |';
    foreach ((array)($result['platforms'] ?? []) as $platform) {
        if (!is_array($platform)) {
            continue;
        }
        $chain = [];
        foreach ((array)($platform['chain'] ?? []) as $code => $stage) {
            if (is_array($stage)) {
                $chain[] = $code . ':' . (string)($stage['status'] ?? '-');
            }
        }
        $field = is_array($platform['field_fact_summary'] ?? null) ? $platform['field_fact_summary'] : [];
        $trafficGate = p0_array($platform['p0_traffic_gate'] ?? null);
        $lines[] = sprintf(
            '| `%s` | %d | `%s` | `%s` | %d | `%s` | `%s` | `%s` | `%s` | %d | %d | %d/%d | %d/%d | %d | %s |',
            (string)($platform['platform'] ?? ''),
            (int)($platform['target_date_rows'] ?? 0),
            (string)($platform['source_chain_scope'] ?? 'unknown'),
            (string)($platform['field_fact_status'] ?? ''),
            (int)($trafficGate['traffic_rows'] ?? 0),
            (string)($trafficGate['status'] ?? 'not_loaded'),
            (string)($trafficGate['external_evidence_status'] ?? 'not_provided'),
            (string)($trafficGate['pre_import_evidence_status'] ?? 'not_provided'),
            (string)($trafficGate['traffic_field_fact_status'] ?? 'not_loaded'),
            (int)($field['fact_count'] ?? 0),
            (int)($field['complete_fact_count'] ?? 0),
            (int)($field['structured_source_path_count'] ?? 0),
            (int)($field['complete_fact_count'] ?? 0),
            (int)($field['stored_value_present_count'] ?? 0),
            (int)($field['complete_fact_count'] ?? 0),
            (int)($field['incomplete_captured_fact_count'] ?? 0),
            implode(', ', $chain)
        );
    }
    if (($result['traffic_evidence_availability'] ?? []) !== []) {
        $lines[] = '';
        $lines[] = '## Traffic Evidence Availability';
        $lines[] = '';
        $lines[] = '- pre-import evidence policy: valid external evidence is source proof only; it is not P0 complete until target-date traffic rows are ingested and `p0_traffic_gate.status` is `ready`.';
        $lines[] = '';
        $lines[] = '| platform | status | traffic rows | traffic field facts | external evidence | pre-import evidence | missing traffic metrics | traffic sources | required next inputs | action entry |';
        $lines[] = '| --- | --- | ---: | --- | --- | --- | --- | --- | --- | --- |';
        foreach ((array)$result['traffic_evidence_availability'] as $traffic) {
            if (!is_array($traffic)) {
                continue;
            }
            $targetDate = is_array($traffic['target_date'] ?? null) ? $traffic['target_date'] : [];
            $sources = is_array($traffic['registered_sources'] ?? null) ? $traffic['registered_sources'] : [];
            $trafficFieldFacts = is_array($traffic['traffic_field_fact_closure'] ?? null) ? $traffic['traffic_field_fact_closure'] : [];
            $trafficGate = p0_platform_traffic_gate($traffic);
            $sourceText = sprintf(
                'registered %d / ready %d / waiting_config %d',
                (int)($sources['traffic_source_count'] ?? 0),
                (int)($sources['traffic_ready_count'] ?? 0),
                (int)($sources['traffic_waiting_config_count'] ?? 0)
            );
            $lines[] = sprintf(
                '| `%s` | `%s` | %d | `%s` | `%s` | `%s` | %s | %s | %s | `%s` |',
                (string)($traffic['platform'] ?? ''),
                (string)($traffic['status'] ?? ''),
                (int)($targetDate['traffic_rows'] ?? 0),
                (string)($trafficFieldFacts['status'] ?? 'not_loaded'),
                (string)($trafficGate['external_evidence_status'] ?? 'not_provided'),
                (string)($trafficGate['pre_import_evidence_status'] ?? 'not_provided'),
                implode(', ', array_map(static fn($item): string => '`' . (string)$item . '`', (array)($trafficFieldFacts['missing_metric_keys'] ?? []))),
                $sourceText,
                implode(', ', array_map(static fn($item): string => '`' . (string)$item . '`', (array)($traffic['required_next_inputs'] ?? []))),
                (string)($traffic['action_entry'] ?? '')
            );
        }
        $fieldLoopRows = [];
        foreach ((array)$result['traffic_evidence_availability'] as $traffic) {
            if (!is_array($traffic)) {
                continue;
            }
            $trafficFieldFacts = p0_array($traffic['traffic_field_fact_closure'] ?? null);
            foreach (p0_array($trafficFieldFacts['field_loop_matrix'] ?? null) as $fieldLoop) {
                if (is_array($fieldLoop)) {
                    $fieldLoopRows[] = array_merge(['platform' => (string)($traffic['platform'] ?? '')], $fieldLoop);
                }
            }
        }
        if ($fieldLoopRows !== []) {
            $lines[] = '';
            $lines[] = '### Traffic Field Loop Matrix';
            $lines[] = '';
            $lines[] = '| platform | metric key | status | capture evidence | source path | expected storage field | stored value | UI ready |';
            $lines[] = '| --- | --- | --- | --- | --- | --- | --- | --- |';
            foreach ($fieldLoopRows as $fieldLoop) {
                $lines[] = sprintf(
                    '| `%s` | `%s` | `%s` | `%s/%s` | `%s/%s` | `%s` | `%s` | `%s` |',
                    (string)($fieldLoop['platform'] ?? ''),
                    (string)($fieldLoop['metric_key'] ?? ''),
                    (string)($fieldLoop['status'] ?? ''),
                    !empty($fieldLoop['capture_evidence_present']) ? 'present' : 'missing',
                    !empty($fieldLoop['capture_evidence_matches_row']) ? 'matched' : 'not_matched',
                    !empty($fieldLoop['source_path_present']) ? 'present' : 'missing',
                    !empty($fieldLoop['source_path_structured']) ? 'structured' : 'unstructured',
                    (string)($fieldLoop['expected_storage_field'] ?? '') . ' ' . (!empty($fieldLoop['storage_field_matches_expected']) ? '(matched)' : '(missing_or_mismatch)'),
                    !empty($fieldLoop['stored_value_present']) ? 'present' : 'missing',
                    !empty($fieldLoop['ui_status_ready']) ? 'ready' : 'not_ready'
                );
            }
        }
        $hotelScopedSources = [];
        $hotelScopedCommands = [];
        $hotelScopedPayloadContracts = [];
        $hotelScopedCaptureBridges = [];
        foreach ((array)$result['traffic_evidence_availability'] as $traffic) {
            if (!is_array($traffic)) {
                continue;
            }
            foreach ((array)($traffic['hotel_scoped_sources'] ?? []) as $source) {
                if (is_array($source)) {
                    $hotelScopedSources[] = $source;
                }
            }
            foreach ((array)($traffic['hotel_scoped_commands'] ?? []) as $command) {
                if (is_array($command)) {
                    $hotelScopedCommands[] = $command;
                }
            }
            foreach ((array)($traffic['hotel_scoped_payload_contracts'] ?? []) as $contract) {
                if (is_array($contract)) {
                    $hotelScopedPayloadContracts[] = $contract;
                }
            }
            foreach ((array)($traffic['hotel_scoped_capture_bridges'] ?? []) as $bridge) {
                if (is_array($bridge)) {
                    $hotelScopedCaptureBridges[] = $bridge;
                }
            }
        }
        if ($hotelScopedSources !== []) {
            $lines[] = '';
            $lines[] = '### Hotel Scoped Traffic Sources';
            $lines[] = '';
            $lines[] = '| platform | system_hotel_id | data_source_id | method | status | last sync | managed by P0 | traffic section | login verified | payload candidate | scoped verifier |';
            $lines[] = '| --- | ---: | ---: | --- | --- | --- | --- | --- | --- | --- | --- |';
            foreach ($hotelScopedSources as $source) {
                $payloadCandidateScan = p0_array($source['payload_candidate_scan'] ?? null);
                $payloadCandidateText = (string)($payloadCandidateScan['status'] ?? '');
                if (($payloadCandidateScan['payload_path'] ?? '') !== '') {
                    $payloadCandidateText .= ':' . (string)$payloadCandidateScan['payload_path'];
                }
                $lines[] = sprintf(
                    '| `%s` | %d | %s | `%s` | `%s` | `%s` | `%s` | `%s` | `%s` | `%s` | `%s` |',
                    (string)($source['platform'] ?? ''),
                    (int)($source['system_hotel_id'] ?? 0),
                    ($source['data_source_id'] ?? null) === null ? '`null`' : (string)(int)$source['data_source_id'],
                    (string)($source['ingestion_method'] ?? ''),
                    (string)($source['status'] ?? ''),
                    (string)($source['last_sync_status'] ?? ''),
                    !empty($source['managed_by_p0']) ? 'true' : 'false',
                    !empty($source['capture_sections_has_traffic']) ? 'true' : 'false',
                    !empty($source['manual_login_state_verified']) ? 'true' : 'false',
                    $payloadCandidateText,
                    (string)($source['p0_verifier_command'] ?? '')
                );
            }
        }
        if ($hotelScopedCommands !== []) {
            $lines[] = '';
            $lines[] = '### Hotel Scoped Traffic Commands';
            $lines[] = '';
            $lines[] = '| platform | system_hotel_id | data_source_id | dry-run import | execute import | evidence verifier | P0 verifier |';
            $lines[] = '| --- | ---: | ---: | --- | --- | --- | --- |';
            foreach ($hotelScopedCommands as $command) {
                $lines[] = sprintf(
                    '| `%s` | %d | %s | `%s` | `%s` | `%s` | `%s` |',
                    (string)($command['platform'] ?? ''),
                    (int)($command['system_hotel_id'] ?? 0),
                    ($command['data_source_id'] ?? null) === null ? '`null`' : (string)(int)$command['data_source_id'],
                    (string)($command['payload_import_command'] ?? ''),
                    (string)($command['payload_import_execute_command'] ?? ''),
                    (string)($command['traffic_evidence_verifier_command'] ?? ''),
                    (string)($command['p0_verifier_command'] ?? '')
                );
            }
        }
        if ($hotelScopedCaptureBridges !== []) {
            $lines[] = '';
            $lines[] = '### Hotel Scoped Capture Bridges';
            $lines[] = '';
            $lines[] = '| platform | system_hotel_id | bridge status | capture output | login prepare | browser capture | importer dry-run | importer acceptance | execute import | post verifier | manual gates |';
            $lines[] = '| --- | ---: | --- | --- | --- | --- | --- | --- | --- | --- | --- |';
            foreach ($hotelScopedCaptureBridges as $bridge) {
                $bridgeAcceptance = p0_array($bridge['bridge_importer_acceptance'] ?? null);
                $bridgeAcceptanceText = [];
                foreach ($bridgeAcceptance as $key => $value) {
                    if (!is_scalar($value)) {
                        continue;
                    }
                    $bridgeAcceptanceText[] = '`' . (string)$key . '=' . (is_bool($value) ? ($value ? 'true' : 'false') : (string)$value) . '`';
                }
                $lines[] = sprintf(
                    '| `%s` | %d | `%s` | `%s` | `%s` | `%s` | `%s` | %s | `%s` | `%s` | %s |',
                    (string)($bridge['platform'] ?? ''),
                    (int)($bridge['system_hotel_id'] ?? 0),
                    (string)($bridge['status'] ?? ''),
                    (string)($bridge['capture_output_path'] ?? ''),
                    (string)($bridge['browser_login_prepare_command'] ?? ''),
                    (string)($bridge['browser_capture_command'] ?? ''),
                    (string)($bridge['bridge_to_importer_command'] ?? ''),
                    $bridgeAcceptanceText === [] ? '`not_declared`' : implode(', ', $bridgeAcceptanceText),
                    (string)($bridge['bridge_execute_command'] ?? ''),
                    (string)($bridge['post_import_verifier_command'] ?? ''),
                    implode(', ', array_map(static fn($item): string => '`' . (string)$item . '`', (array)($bridge['manual_gates'] ?? [])))
                );
            }
        }
        if ($hotelScopedPayloadContracts !== []) {
            $lines[] = '';
            $lines[] = '### Hotel Scoped Payload Contracts';
            $lines[] = '';
            $lines[] = '| platform | system_hotel_id | contract status | accepted containers | required row evidence | required metrics | dry-run acceptance | importer rejects |';
            $lines[] = '| --- | ---: | --- | --- | --- | --- | --- | --- |';
            foreach ($hotelScopedPayloadContracts as $contract) {
                $requiredRowEvidence = p0_array($contract['required_row_evidence'] ?? null);
                $dryRunAcceptance = p0_array($contract['dry_run_acceptance'] ?? null);
                $dryRunAcceptanceText = [];
                foreach ($dryRunAcceptance as $key => $value) {
                    $renderedValue = is_array($value) ? '[]' : (string)$value;
                    $separator = str_starts_with($renderedValue, '>') || str_starts_with($renderedValue, '<') ? '' : '=';
                    $dryRunAcceptanceText[] = '`' . (string)$key . $separator . $renderedValue . '`';
                }
                $lines[] = sprintf(
                    '| `%s` | %d | `%s` | %s | %s | %s | %s | %s |',
                    (string)($contract['platform'] ?? ''),
                    (int)($contract['system_hotel_id'] ?? 0),
                    (string)($contract['status'] ?? ''),
                    implode(', ', array_map(static fn($item): string => '`' . (string)$item . '`', (array)($contract['accepted_payload_containers'] ?? []))),
                    implode(', ', array_map(static fn($item): string => '`' . (string)$item . '`', array_keys($requiredRowEvidence))),
                    implode(', ', array_map(static fn($item): string => '`' . (string)$item . '`', array_keys(p0_array($contract['required_metric_aliases'] ?? null)))),
                    implode(', ', $dryRunAcceptanceText),
                    implode(', ', array_map(static fn($item): string => '`' . (string)$item . '`', (array)($contract['importer_rejects'] ?? [])))
                );
            }
        }
        $lines[] = '';
        $lines[] = '### Traffic Closure Path Options';
        $lines[] = '';
        $lines[] = '| platform | mode | entry | payload import | evidence verifier | recommended | status | can run now | missing inputs | required metric keys | selection policy |';
        $lines[] = '| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |';
        foreach ((array)$result['traffic_evidence_availability'] as $traffic) {
            if (!is_array($traffic)) {
                continue;
            }
            $recommendedAction = is_array($traffic['recommended_action'] ?? null) ? $traffic['recommended_action'] : [];
            $recommendedMode = (string)($recommendedAction['mode'] ?? '');
            $recommendedEntry = (string)($recommendedAction['entry'] ?? '');
            $selectionPolicy = (string)($recommendedAction['selection_policy'] ?? '');
            foreach ((array)($traffic['closure_path_options'] ?? []) as $option) {
                if (!is_array($option)) {
                    continue;
                }
                $inputContract = p0_array($option['input_contract'] ?? null);
                $requiredMetricKeys = array_values(array_map('strval', (array)($inputContract['required_metric_keys'] ?? [])));
                $isRecommended = $recommendedMode !== ''
                    && (string)($option['mode'] ?? '') === $recommendedMode
                    && (string)($option['entry'] ?? '') === $recommendedEntry;
                $lines[] = sprintf(
                    '| `%s` | `%s` | `%s` | `%s` | `%s` | `%s` | `%s` | `%s` | %s | %s | `%s` |',
                    (string)($traffic['platform'] ?? ''),
                    (string)($option['mode'] ?? ''),
                    (string)($option['entry'] ?? ''),
                    (string)($option['payload_import_command'] ?? ''),
                    (string)($option['traffic_evidence_verifier_command'] ?? ''),
                    $isRecommended ? 'true' : 'false',
                    (string)($option['status'] ?? ''),
                    !empty($option['can_run_now']) ? 'true' : 'false',
                    implode(', ', array_map(static fn($item): string => '`' . (string)$item . '`', (array)($option['missing_inputs'] ?? []))),
                    implode(', ', array_map(static fn($item): string => '`' . (string)$item . '`', $requiredMetricKeys)),
                    $isRecommended ? $selectionPolicy : ''
                );
            }
        }
    }
    $externalTrafficEvidence = is_array($result['external_traffic_evidence'] ?? null) ? $result['external_traffic_evidence'] : [];
    if ($externalTrafficEvidence !== [] && (string)($externalTrafficEvidence['status'] ?? 'not_provided') !== 'not_provided') {
        $lines[] = '';
        $lines[] = '## External Traffic Evidence';
        $lines[] = '';
        $lines[] = '- status: `' . (string)($externalTrafficEvidence['status'] ?? 'unknown') . '`';
        $lines[] = '- completion policy: `' . (string)($externalTrafficEvidence['completion_policy'] ?? '') . '`';
        $lines[] = '';
        $lines[] = '| platform | status | rows | valid rows | metric keys | missing metric keys | sensitive values exposed |';
        $lines[] = '| --- | --- | ---: | ---: | --- | --- | --- |';
        foreach (p0_array($externalTrafficEvidence['platforms'] ?? null) as $platformEvidence) {
            if (!is_array($platformEvidence)) {
                continue;
            }
            $lines[] = sprintf(
                '| `%s` | `%s` | %d | %d | %s | %s | `%s` |',
                (string)($platformEvidence['platform'] ?? ''),
                (string)($platformEvidence['status'] ?? ''),
                (int)($platformEvidence['evidence_rows'] ?? 0),
                (int)($platformEvidence['valid_evidence_rows'] ?? 0),
                implode(', ', array_map(static fn($item): string => '`' . (string)$item . '`', (array)($platformEvidence['metric_keys'] ?? []))),
                implode(', ', array_map(static fn($item): string => '`' . (string)$item . '`', (array)($platformEvidence['missing_metric_keys'] ?? []))),
                !empty($platformEvidence['sensitive_values_exposed']) ? 'true' : 'false'
            );
        }
    }
    if (($result['issues'] ?? []) !== []) {
        $lines[] = '';
        $lines[] = '## Issues';
        foreach ((array)$result['issues'] as $issue) {
            if (!is_array($issue)) {
                continue;
            }
            $lines[] = '- `' . (string)($issue['code'] ?? 'issue') . '`: ' . (string)($issue['message'] ?? '');
        }
    }

    return implode(PHP_EOL, $lines);
}

try {
    $options = p0_parse_args($argv);
    $expectedPlatforms = p0_expected_platforms((string)$options['platform']);
    $inspection = p0_run_inspector($options, $expectedPlatforms);

    $issues = [];
    $globalChecks = [];

    if (($inspection['status'] ?? '') === 'failed') {
        p0_add_issue($issues, 'failed', (string)($inspection['issue']['code'] ?? 'live_closure_inspector_failed'), (string)($inspection['issue']['message'] ?? 'Inspector failed.'), p0_array($inspection['issue'] ?? null));
    } elseif (($inspection['status'] ?? '') === 'incomplete') {
        $missingCodes = [];
        foreach (p0_array($inspection['missing_requirements'] ?? null) as $missing) {
            if (is_array($missing) && trim((string)($missing['code'] ?? '')) !== '') {
                $missingCodes[] = (string)$missing['code'];
            }
        }
        p0_add_issue($issues, 'incomplete', 'live_closure_incomplete', 'Live OTA revenue/AI data foundation is still incomplete; field loop evidence cannot be treated as full closure.', [
            'inspector_status' => 'incomplete',
            'missing_codes' => array_values(array_unique($missingCodes)),
        ]);
    }

    $scripts = p0_package_scripts();
    $p0Command = 'C:\\xampp\\php\\php.exe scripts\\verify_p0_ota_field_loop_closure.php';
    $p0ImportCommand = 'C:\\xampp\\php\\php.exe scripts\\import_p0_ota_traffic_payload.php';
    $frontendSourcePaths = ['public/index.html', 'public/data-health-static.js'];
    $onlineDataBackendPaths = ['app/controller/OnlineData.php', 'app/controller/concern/OnlineDataQualityConcern.php'];
    $uiBackend = p0_source_contains_any($onlineDataBackendPaths, 'buildOnlineDataFieldFactStatus')
        && p0_source_contains_any($onlineDataBackendPaths, 'field_fact_status');
    $uiFrontend = p0_source_contains_any($frontendSourcePaths, 'onlineAnalysisFieldFactStatusText')
        && p0_source_contains_any($frontendSourcePaths, 'onlineAnalysisFieldFactStatusClass')
        && p0_source_contains_any($frontendSourcePaths, 'onlineAnalysisFieldFactDetailText')
        && p0_source_contains_any($frontendSourcePaths, 'field_fact_status');
    $fieldFactBackendPaths = ['app/controller/OnlineData.php', 'app/controller/concern/OnlineDataQualityConcern.php', 'app/service/OnlineDataFieldFactService.php'];
    $uiP0SourceEvidence = p0_source_contains_any($frontendSourcePaths, 'onlineAnalysisP0CaptureEvidenceStatusText(item)')
        && p0_source_contains_any($frontendSourcePaths, 'onlineAnalysisP0CaptureEvidenceStatusClass(item)')
        && p0_source_contains_any($frontendSourcePaths, 'onlineAnalysisP0CaptureEvidenceDetailText(item)')
        && p0_source_contains_any($frontendSourcePaths, 'desensitized_capture_evidence_count')
        && p0_source_contains_any($frontendSourcePaths, 'P0证据待补')
        && p0_source_contains_any($fieldFactBackendPaths, 'desensitized_capture_evidence_count')
        && p0_source_contains_any($fieldFactBackendPaths, 'fieldFactHasDesensitizedCaptureEvidence');
    $uiTrafficStatus = p0_source_contains_any($frontendSourcePaths, 'traffic_source_readiness')
        && p0_source_contains_any($frontendSourcePaths, 'target_date_traffic_rows')
        && p0_source_contains_any($frontendSourcePaths, 'p0_source_chain_reference_only')
        && p0_source_contains_any($frontendSourcePaths, 'p0_source_chain_scope')
        && p0_source_contains_any($frontendSourcePaths, 'reference_only_non_traffic_source_rows')
        && p0_source_contains_any($frontendSourcePaths, '流量缺失')
        && p0_source_contains_any($frontendSourcePaths, '流量已入库')
        && p0_source_contains('app/controller/concern/Phase1EmployeeConsoleConcern.php', 'traffic_status=ready')
        && p0_source_contains('app/controller/concern/Phase1EmployeeConsoleConcern.php', 'p0_source_chain_reference_only')
        && p0_source_contains('app/controller/concern/Phase1EmployeeConsoleConcern.php', 'reference_only_non_traffic_source_rows')
        && p0_source_contains('app/controller/concern/Phase1EmployeeConsoleConcern.php', '目标日流量事实已入库')
        && p0_source_contains('app/controller/concern/Phase1EmployeeConsoleConcern.php', '流量/转化事实缺失');
    $verifierRegistered = ($scripts['verify:p0-ota-field-loop'] ?? '') === $p0Command
        && ($scripts['import:p0-ota-traffic-payload'] ?? '') === $p0ImportCommand
        && ($scripts['import:p0-ota-traffic-payload:execute'] ?? '') === $p0ImportCommand . ' --execute=1'
        && ($scripts['verify:online-data-field-fact-status'] ?? '') === 'C:\\xampp\\php\\php.exe scripts\\verify_online_data_field_fact_status.php'
        && str_contains($scripts['verify:platform-data-source-contract'] ?? '', 'verify_online_data_field_fact_status.php')
        && ($scripts['verify:phase1-live-action-queue'] ?? '') === 'node scripts/verify_phase1_live_action_queue_runtime.mjs'
        && p0_source_contains('scripts/verify_phase1_ota_trusted_loop_contract.mjs', 'verify:p0-ota-field-loop');

    p0_add_global_check($globalChecks, 'ui_backend_field_fact_status', $uiBackend, 'OnlineData daily rows expose field_fact_status.');
    p0_add_global_check($globalChecks, 'ui_frontend_field_fact_status', $uiFrontend, 'Online analysis UI renders field_fact_status.');
    p0_add_global_check($globalChecks, 'ui_frontend_p0_source_evidence_status', $uiP0SourceEvidence, 'Online analysis UI exposes P0 desensitized capture evidence separately from loose field facts.');
    p0_add_global_check($globalChecks, 'ui_frontend_p0_traffic_status', $uiTrafficStatus, 'Employee UI exposes target-date traffic status separately from source field status.');
    p0_add_global_check($globalChecks, 'p0_verifier_registered', $verifierRegistered, 'Package scripts and trusted-loop contract register the P0 field-loop verifier.');

    $platformMap = p0_platform_map($inspection);
    $sourceSummaryMap = p0_source_summary_map($inspection);
    $externalTrafficEvidence = p0_external_traffic_evidence($options, $expectedPlatforms);
    if (($externalTrafficEvidence['status'] ?? 'not_provided') !== 'not_provided'
        && ($externalTrafficEvidence['status'] ?? '') !== 'valid'
    ) {
        p0_add_issue($issues, 'incomplete', 'external_traffic_evidence_not_valid', 'External traffic evidence was supplied but does not satisfy the desensitized traffic evidence contract.', [
            'status' => (string)($externalTrafficEvidence['status'] ?? 'unknown'),
            'platforms' => p0_array($externalTrafficEvidence['platforms'] ?? null),
            'issues' => p0_array($externalTrafficEvidence['issues'] ?? null),
            'completion_policy' => (string)($externalTrafficEvidence['completion_policy'] ?? ''),
            'sensitive_values_exposed' => (bool)($externalTrafficEvidence['sensitive_values_exposed'] ?? false),
        ]);
    }
    p0_add_global_check(
        $globalChecks,
        'runtime_field_fact_summary_ready',
        p0_runtime_field_fact_summary_ready($sourceSummaryMap, $expectedPlatforms),
        'Live inspector source summary proves capture_evidence, source_path, metric_key, storage_field, stored values, and raw-data safety.',
        'incomplete'
    );
    p0_add_global_check(
        $globalChecks,
        'runtime_traffic_readiness_visible',
        p0_runtime_traffic_readiness_visible($inspection, $expectedPlatforms),
        'Live employee question evidence exposes traffic source readiness from metadata without sensitive values.'
    );
    foreach ($globalChecks as $check) {
        if (($check['status'] ?? '') !== 'passed') {
            p0_add_issue($issues, (string)($check['failure_severity'] ?? 'failed'), (string)$check['code'], (string)$check['message']);
        }
    }

    $trafficAvailability = p0_traffic_evidence_availability($sourceSummaryMap, $expectedPlatforms, (string)$options['date'], $externalTrafficEvidence, (int)($options['system-hotel-id'] ?? 0));
    foreach ($trafficAvailability as $traffic) {
        if (!is_array($traffic)) {
            continue;
        }
        $trafficStatus = (string)($traffic['status'] ?? '');
        $platformName = strtolower((string)($traffic['platform'] ?? ''));
        if ($platformName === '' || $trafficStatus === 'ready' || $trafficStatus === 'unavailable') {
            if ($platformName !== '' && $trafficStatus === 'ready') {
                $trafficFieldFactClosure = p0_array($traffic['traffic_field_fact_closure'] ?? null);
                if ((string)($trafficFieldFactClosure['status'] ?? '') !== 'ready') {
                    p0_add_issue(
                        $issues,
                        'incomplete',
                        $platformName . '_traffic_field_fact_closure_incomplete',
                        'Target-date traffic rows exist but required traffic metric field facts are not fully closed.',
                        [
                            'traffic_field_fact_closure' => $trafficFieldFactClosure,
                            'required_metric_keys' => p0_required_traffic_metric_keys(),
                            'required_storage_fields' => p0_required_traffic_storage_field_map(),
                            'sensitive_values_exposed' => false,
                        ]
                    );
                }
            }
            continue;
        }
        p0_add_issue(
            $issues,
            'incomplete',
            $platformName . '_traffic_evidence_availability_incomplete',
            'Traffic facts are missing and current local context does not prove a same-day traffic collection closure.',
            [
                'status' => $trafficStatus,
                'target_date' => p0_array($traffic['target_date'] ?? null),
                'manual_context' => p0_array($traffic['manual_context'] ?? null),
                'automatic_context' => p0_array($traffic['automatic_context'] ?? null),
                'registered_sources' => p0_array($traffic['registered_sources'] ?? null),
                'traffic_field_fact_closure' => p0_array($traffic['traffic_field_fact_closure'] ?? null),
                'required_next_inputs' => array_values(array_map('strval', (array)($traffic['required_next_inputs'] ?? []))),
                'closure_path_options' => array_values(array_filter(
                    p0_array($traffic['closure_path_options'] ?? null),
                    static fn($item): bool => is_array($item)
                )),
                'external_traffic_evidence' => p0_array($traffic['external_traffic_evidence'] ?? null),
                'action_entry' => (string)($traffic['action_entry'] ?? ''),
                'sensitive_values_exposed' => false,
            ]
        );
    }
    $globalReadiness = [
        'ui_status' => $uiBackend && $uiFrontend,
        'verifier' => $verifierRegistered,
    ];

    $platformResults = [];
    $trafficAvailabilityMap = p0_traffic_availability_map($trafficAvailability);
    foreach ($expectedPlatforms as $platform) {
        $platformResult = p0_analyze_platform(
            $platform,
            p0_array($platformMap[$platform] ?? null),
            p0_array($sourceSummaryMap[$platform] ?? null),
            $globalReadiness,
            $issues
        );
        $platformResult['p0_traffic_gate'] = p0_platform_traffic_gate(p0_array($trafficAvailabilityMap[$platform] ?? null));
        $platformResults[] = $platformResult;
    }

    $failedIssueCount = count(array_filter($issues, static fn(array $issue): bool => ($issue['severity'] ?? '') === 'failed'));
    $status = $failedIssueCount > 0 ? 'failed' : ($issues === [] ? 'passed' : 'incomplete');
    $sourcePlatformsReady = count(array_filter($platformResults, static function (array $platform): bool {
        foreach ((array)($platform['chain'] ?? []) as $stage) {
            if (!is_array($stage) || ($stage['status'] ?? '') !== 'passed') {
                return false;
            }
        }
        return true;
    }));
    $trafficGatesReady = count(array_filter($platformResults, static fn(array $platform): bool => (string)($platform['p0_traffic_gate']['status'] ?? '') === 'ready'));
    $p0PlatformsReady = count(array_filter($platformResults, static function (array $platform): bool {
        foreach ((array)($platform['chain'] ?? []) as $stage) {
            if (!is_array($stage) || ($stage['status'] ?? '') !== 'passed') {
                return false;
            }
        }
        return (string)($platform['p0_traffic_gate']['status'] ?? '') === 'ready';
    }));

    $result = [
        'script' => 'scripts/verify_p0_ota_field_loop_closure.php',
        'status' => $status,
        'scope' => [
            'date' => $options['date'],
            'platforms' => $expectedPlatforms,
            'system_hotel_id' => (int)($options['system-hotel-id'] ?? 0) > 0 ? (int)$options['system-hotel-id'] : null,
            'hotel_scope_policy' => (int)($options['system-hotel-id'] ?? 0) > 0 ? 'system_hotel_id' : 'platform_date',
            'storage_table' => 'online_daily_data',
            'metric_scope' => 'ota_channel',
            'source_policy' => 'read_existing_online_daily_data_only',
        ],
        'global_checks' => $globalChecks,
        'platforms' => $platformResults,
        'traffic_evidence_availability' => $trafficAvailability,
        'external_traffic_evidence' => $externalTrafficEvidence,
        'issues' => $issues,
        'inspector_status' => $inspection['status'] ?? 'unknown',
        'summary' => [
            'platform_count' => count($platformResults),
            'platforms_ready' => $p0PlatformsReady,
            'p0_platforms_ready' => $p0PlatformsReady,
            'p0_platforms_incomplete' => max(0, count($platformResults) - $p0PlatformsReady),
            'source_platforms_ready' => $sourcePlatformsReady,
            'traffic_gates_ready' => $trafficGatesReady,
            'traffic_gates_incomplete' => max(0, count($platformResults) - $trafficGatesReady),
            'summary_policy' => 'platforms_ready counts only platforms whose source chain and p0_traffic_gate are both ready; source_platforms_ready is reference-only.',
            'incomplete_issues' => count(array_filter($issues, static fn(array $issue): bool => ($issue['severity'] ?? '') === 'incomplete')),
            'failed_issues' => $failedIssueCount,
        ],
    ];
} catch (Throwable $e) {
    $result = [
        'script' => 'scripts/verify_p0_ota_field_loop_closure.php',
        'status' => 'failed',
        'scope' => [],
        'global_checks' => [],
        'platforms' => [],
        'issues' => [[
            'severity' => 'failed',
            'code' => 'p0_field_loop_verifier_runtime_error',
            'message' => $e->getMessage(),
        ]],
        'summary' => [
            'platform_count' => 0,
            'platforms_ready' => 0,
            'p0_platforms_ready' => 0,
            'p0_platforms_incomplete' => 0,
            'source_platforms_ready' => 0,
            'traffic_gates_ready' => 0,
            'traffic_gates_incomplete' => 0,
            'summary_policy' => 'platforms_ready counts only platforms whose source chain and p0_traffic_gate are both ready; source_platforms_ready is reference-only.',
            'incomplete_issues' => 0,
            'failed_issues' => 1,
        ],
    ];
}

echo (($result['scope']['format'] ?? null) === 'markdown' || (($options['format'] ?? 'json') === 'markdown')
    ? p0_render_markdown($result)
    : json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
) . PHP_EOL;

exit(($result['status'] ?? '') === 'passed' ? 0 : (($result['status'] ?? '') === 'incomplete' ? 1 : 2));
