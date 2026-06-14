<?php
declare(strict_types=1);

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
        'limit' => 5000,
        'format' => 'json',
        'traffic-evidence' => '',
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

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$options['date'])) {
        throw new InvalidArgumentException('Invalid --date, expected YYYY-MM-DD.');
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
 * @param array<int, array<string, mixed>> $checks
 */
function p0_add_global_check(array &$checks, string $code, bool $passed, string $message): void
{
    $checks[] = [
        'code' => $code,
        'status' => $passed ? 'passed' : 'failed',
        'message' => $message,
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
 * @param array<int|string, mixed> $value
 * @return array<int, array{path:string, reason:string}>
 */
function p0_find_sensitive_external_values(array $value, string $path = ''): array
{
    $hits = [];
    foreach ($value as $key => $item) {
        $segment = is_string($key) ? $key : (string)$key;
        $nextPath = $path === '' ? $segment : $path . '.' . $segment;
        $lowerKey = strtolower($segment);
        if (preg_match('/(^|_)(cookie|token|spidertoken|authorization|password|secret)($|_)/i', $segment)
            || preg_match('/^source_url$/i', $segment)
            || preg_match('/profile_(path|dir)/i', $segment)
            || preg_match('/raw_(cookie|token|profile)/i', $segment)
        ) {
            $hits[] = ['path' => $nextPath, 'reason' => 'sensitive_key_present'];
        }
        if (is_string($item)) {
            $trimmed = trim($item);
            if (preg_match('/\b(Bearer|Cookie|Authorization)\s*[:=]/i', $trimmed)
                || preg_match('/spidertoken|sess|csrf|access[_-]?token|refresh[_-]?token/i', $trimmed)
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
 * @param array<string, mixed> $row
 * @param array<string, mixed> $data
 * @param array<int, string> $expectedPlatforms
 * @return array<string, mixed>
 */
function p0_validate_external_traffic_evidence_row(array $row, array $data, array $expectedPlatforms, string $targetDate): array
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

    $scopePolicy = trim((string)($row['scope_policy'] ?? $row['source_scope'] ?? $scope['source_scope'] ?? $scope['scope_policy'] ?? ''));
    if ($scopePolicy !== 'ota_channel_only') {
        $issues[] = [
            'code' => 'scope_policy_not_ota_channel_only',
            'message' => 'External traffic evidence must keep OTA channel scope explicit.',
            'scope_policy' => $scopePolicy,
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
    if (trim((string)($row['source_trace_id'] ?? $captureEvidence['source_trace_id'] ?? '')) === '') {
        $issues[] = [
            'code' => 'source_trace_id_missing',
            'message' => 'Evidence row must include a desensitized source_trace_id.',
        ];
    }
    if (trim((string)($captureEvidence['source_url_hash'] ?? '')) === '') {
        $issues[] = [
            'code' => 'source_url_hash_missing',
            'message' => 'Evidence row must include capture_evidence.source_url_hash instead of raw source_url.',
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
        if (!array_key_exists('stored_value_present', $fact)) {
            $issues[] = [
                'code' => 'stored_value_present_missing',
                'message' => 'field_facts[].stored_value_present must be explicit; it is not treated as DB proof.',
                'metric_key' => $metricKey,
            ];
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
        'status' => $issues === [] ? 'valid' : 'invalid',
        'validated_desensitized_evidence_present' => $issues === [],
        'metric_keys' => array_values(array_keys($presentMetricKeys)),
        'missing_metric_keys' => $missingMetricKeys,
        'source_paths' => array_values(array_keys($sourcePaths)),
        'storage_fields' => array_values(array_keys($storageFields)),
        'source_trace_id_present' => trim((string)($row['source_trace_id'] ?? $captureEvidence['source_trace_id'] ?? '')) !== '',
        'source_url_hash_present' => trim((string)($captureEvidence['source_url_hash'] ?? '')) !== '',
        'sensitive_values_exposed' => $sensitiveDeclared !== false || $sensitiveHits !== [],
        'issues' => $issues,
    ];
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
    $base = [
        'status' => 'not_provided',
        'path' => '',
        'required_metric_keys' => $requiredMetricKeys,
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
            'sensitive_values_exposed' => false,
            'issues' => [],
        ];
    }

    $unknownIssues = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $validation = p0_validate_external_traffic_evidence_row($row, $decoded, $platforms, (string)$options['date']);
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
            if ($managedByP0) {
                $trafficManagedCount++;
            }
            if ($secretConfigured) {
                $trafficSecretConfiguredCount++;
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
            'reason' => 'Use when a local authorized browser Profile exists and the platform page must trigger traffic responses.',
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
 * @return array<string, mixed>
 */
function p0_traffic_field_fact_closure(string $platform, string $targetDate): array
{
    $requiredMetricKeys = p0_required_traffic_metric_keys();
    $requiredStorageFields = p0_required_traffic_storage_field_map();
    $base = [
        'status' => 'not_loaded',
        'target_date' => $targetDate,
        'required_metric_keys' => $requiredMetricKeys,
        'required_storage_fields' => array_values($requiredStorageFields),
        'traffic_row_count' => 0,
        'rows_with_field_facts' => 0,
        'complete_metric_keys' => [],
        'missing_metric_keys' => $requiredMetricKeys,
        'incomplete_metric_keys' => [],
        'sample_facts' => [],
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
    $rows = $query->field('id,raw_data')->select()->toArray();
    $base['traffic_row_count'] = count($rows);
    if ($rows === []) {
        $base['status'] = 'no_target_date_traffic_rows';
        return $base;
    }

    $complete = [];
    $incomplete = [];
    foreach ($rows as $row) {
        $raw = json_decode((string)($row['raw_data'] ?? ''), true);
        if (!is_array($raw)) {
            continue;
        }
        $facts = p0_array($raw['field_facts'] ?? null);
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
            $sourcePath = trim((string)($fact['source_path'] ?? ''));
            $storageField = trim((string)($fact['storage_field'] ?? ''));
            $captureEvidence = p0_array($fact['capture_evidence'] ?? null);
            $storedValuePresent = $fact['stored_value_present'] ?? null;
            $factReady = $sourcePath !== ''
                && $storageField === $requiredStorageFields[$metricKey]
                && $captureEvidence !== []
                && $storedValuePresent === true;
            if ($factReady) {
                $complete[$metricKey] = true;
            } else {
                $incomplete[$metricKey] = true;
            }
            if (count($base['sample_facts']) < 10) {
                $base['sample_facts'][] = [
                    'row_id' => $row['id'] ?? null,
                    'metric_key' => $metricKey,
                    'source_path_present' => $sourcePath !== '',
                    'storage_field' => $storageField,
                    'expected_storage_field' => $requiredStorageFields[$metricKey],
                    'capture_evidence_present' => $captureEvidence !== [],
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
    $base['status'] = $missingKeys === [] && $incompleteKeys === [] ? 'ready' : 'incomplete';
    if ((int)$base['rows_with_field_facts'] === 0) {
        $base['status'] = 'field_facts_missing';
    }

    return $base;
}

/**
 * @param array<string, array<string, mixed>> $sourceSummaryMap
 * @param array<int, string> $platforms
 * @return array<int, array<string, mixed>>
 */
function p0_traffic_evidence_availability(array $sourceSummaryMap, array $platforms, string $targetDate, array $externalTrafficEvidence = []): array
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
        $trafficFieldFactClosure = p0_traffic_field_fact_closure($platform, $targetDate);
        $requiredInputs = p0_traffic_required_inputs($platform, $config, $profile, $sources, $template, $summary);
        $pathOptions = p0_traffic_closure_path_options($platform, $config, $profile, $sources, $template, $summary);
        $recommendedAction = p0_recommended_traffic_action($platform, $pathOptions);

        $result[] = [
            'platform' => $platform,
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
    $sourcePathCount = (int)($fieldFacts['source_path_count'] ?? 0);
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
            && ($traceCount > 0 || trim((string)($sourceRows['latest_trace_time'] ?? '')) !== '')
            && $completeFactCount > 0
            && $captureEvidenceCount >= $completeFactCount,
        'Target-date OTA source rows and field facts must include capture evidence.',
        [
            'target_date_rows' => $targetRows,
            'sample_trace_id_count' => $traceCount,
            'latest_trace_time_present' => trim((string)($sourceRows['latest_trace_time'] ?? '')) !== '',
            'capture_evidence_count' => $captureEvidenceCount,
            'complete_fact_count' => $completeFactCount,
        ]
    );
    p0_require_stage(
        $stages,
        $issues,
        $name,
        'source_path',
        $completeFactCount > 0 && $sourcePathCount >= $completeFactCount,
        'Field facts must prove source_path for every complete captured fact.',
        ['source_path_count' => $sourcePathCount, 'complete_fact_count' => $completeFactCount]
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
        'data_types' => array_values(array_map('strval', (array)($summary['target_date_data_types'] ?? $sourceRows['data_types'] ?? []))),
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
            'source_path_count' => $sourcePathCount,
            'storage_field_count' => $storageFieldCount,
            'stored_value_present_count' => $storedValuePresentCount,
            'stored_value_missing_count' => $storedValueMissingCount,
            'raw_data_exposed' => $rawDataExposed,
        ],
        'chain' => $stages,
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
    $lines[] = '';
    $lines[] = '| platform | rows | field status | facts | complete | stored values | incomplete | chain |';
    $lines[] = '| --- | ---: | --- | ---: | ---: | ---: | ---: | --- |';
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
        $lines[] = sprintf(
            '| `%s` | %d | `%s` | %d | %d | %d/%d | %d | %s |',
            (string)($platform['platform'] ?? ''),
            (int)($platform['target_date_rows'] ?? 0),
            (string)($platform['field_fact_status'] ?? ''),
            (int)($field['fact_count'] ?? 0),
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
        $lines[] = '| platform | status | traffic rows | traffic field facts | missing traffic metrics | traffic sources | required next inputs | action entry |';
        $lines[] = '| --- | --- | ---: | --- | --- | --- | --- | --- |';
        foreach ((array)$result['traffic_evidence_availability'] as $traffic) {
            if (!is_array($traffic)) {
                continue;
            }
            $targetDate = is_array($traffic['target_date'] ?? null) ? $traffic['target_date'] : [];
            $sources = is_array($traffic['registered_sources'] ?? null) ? $traffic['registered_sources'] : [];
            $trafficFieldFacts = is_array($traffic['traffic_field_fact_closure'] ?? null) ? $traffic['traffic_field_fact_closure'] : [];
            $sourceText = sprintf(
                'registered %d / ready %d / waiting_config %d',
                (int)($sources['traffic_source_count'] ?? 0),
                (int)($sources['traffic_ready_count'] ?? 0),
                (int)($sources['traffic_waiting_config_count'] ?? 0)
            );
            $lines[] = sprintf(
                '| `%s` | `%s` | %d | `%s` | %s | %s | %s | `%s` |',
                (string)($traffic['platform'] ?? ''),
                (string)($traffic['status'] ?? ''),
                (int)($targetDate['traffic_rows'] ?? 0),
                (string)($trafficFieldFacts['status'] ?? 'not_loaded'),
                implode(', ', array_map(static fn($item): string => '`' . (string)$item . '`', (array)($trafficFieldFacts['missing_metric_keys'] ?? []))),
                $sourceText,
                implode(', ', array_map(static fn($item): string => '`' . (string)$item . '`', (array)($traffic['required_next_inputs'] ?? []))),
                (string)($traffic['action_entry'] ?? '')
            );
        }
        $lines[] = '';
        $lines[] = '### Traffic Closure Path Options';
        $lines[] = '';
        $lines[] = '| platform | mode | entry | payload import | recommended | status | can run now | missing inputs | required metric keys | selection policy |';
        $lines[] = '| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |';
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
                    '| `%s` | `%s` | `%s` | `%s` | `%s` | `%s` | `%s` | %s | %s | `%s` |',
                    (string)($traffic['platform'] ?? ''),
                    (string)($option['mode'] ?? ''),
                    (string)($option['entry'] ?? ''),
                    (string)($option['payload_import_command'] ?? ''),
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
    $uiBackend = p0_source_contains('app/controller/OnlineData.php', 'buildOnlineDataFieldFactStatus')
        && p0_source_contains('app/controller/OnlineData.php', 'field_fact_status');
    $uiFrontend = p0_source_contains('public/index.html', 'onlineAnalysisFieldFactStatusText(item)')
        && p0_source_contains('public/index.html', 'onlineAnalysisFieldFactStatusClass(item)')
        && p0_source_contains('public/index.html', 'onlineAnalysisFieldFactDetailText(item)')
        && p0_source_contains('public/index.html', 'field_fact_status');
    $verifierRegistered = ($scripts['verify:p0-ota-field-loop'] ?? '') === $p0Command
        && ($scripts['import:p0-ota-traffic-payload'] ?? '') === $p0ImportCommand
        && ($scripts['import:p0-ota-traffic-payload:execute'] ?? '') === $p0ImportCommand . ' --execute=1'
        && ($scripts['verify:online-data-field-fact-status'] ?? '') === 'C:\\xampp\\php\\php.exe scripts\\verify_online_data_field_fact_status.php'
        && str_contains($scripts['verify:platform-data-source-contract'] ?? '', 'verify_online_data_field_fact_status.php')
        && ($scripts['verify:phase1-live-action-queue'] ?? '') === 'node scripts/verify_phase1_live_action_queue_runtime.mjs'
        && p0_source_contains('scripts/verify_phase1_ota_trusted_loop_contract.mjs', 'verify:p0-ota-field-loop');

    p0_add_global_check($globalChecks, 'ui_backend_field_fact_status', $uiBackend, 'OnlineData daily rows expose field_fact_status.');
    p0_add_global_check($globalChecks, 'ui_frontend_field_fact_status', $uiFrontend, 'Online analysis UI renders field_fact_status.');
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
        'Live inspector source summary proves capture_evidence, source_path, metric_key, storage_field, stored values, and raw-data safety.'
    );
    p0_add_global_check(
        $globalChecks,
        'runtime_traffic_readiness_visible',
        p0_runtime_traffic_readiness_visible($inspection, $expectedPlatforms),
        'Live employee question evidence exposes traffic source readiness from metadata without sensitive values.'
    );
    foreach ($globalChecks as $check) {
        if (($check['status'] ?? '') !== 'passed') {
            p0_add_issue($issues, 'failed', (string)$check['code'], (string)$check['message']);
        }
    }

    $trafficAvailability = p0_traffic_evidence_availability($sourceSummaryMap, $expectedPlatforms, (string)$options['date'], $externalTrafficEvidence);
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
    foreach ($expectedPlatforms as $platform) {
        $platformResults[] = p0_analyze_platform(
            $platform,
            p0_array($platformMap[$platform] ?? null),
            p0_array($sourceSummaryMap[$platform] ?? null),
            $globalReadiness,
            $issues
        );
    }

    $failedIssueCount = count(array_filter($issues, static fn(array $issue): bool => ($issue['severity'] ?? '') === 'failed'));
    $status = $failedIssueCount > 0 ? 'failed' : ($issues === [] ? 'passed' : 'incomplete');

    $result = [
        'script' => 'scripts/verify_p0_ota_field_loop_closure.php',
        'status' => $status,
        'scope' => [
            'date' => $options['date'],
            'platforms' => $expectedPlatforms,
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
            'platforms_ready' => count(array_filter($platformResults, static function (array $platform): bool {
                foreach ((array)($platform['chain'] ?? []) as $stage) {
                    if (!is_array($stage) || ($stage['status'] ?? '') !== 'passed') {
                        return false;
                    }
                }
                return true;
            })),
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
