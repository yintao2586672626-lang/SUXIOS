<?php
declare(strict_types=1);

use app\service\OtaRevenueMetricService;
use app\service\OtaStandardEtlService;
use think\App;
use think\facade\Db;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    echo json_encode([
        'status' => 'failed',
        'mode' => 'inspect',
        'scope' => [],
        'checks' => [],
        'platforms' => [],
        'external_evidence' => null,
        'missing_requirements' => [],
        'issues' => [[
            'severity' => 'error',
            'code' => 'vendor_autoload_missing',
            'message' => 'vendor/autoload.php is missing. Run composer install before live closure inspection.',
        ]],
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}

require $autoload;

$root = dirname(__DIR__);

/**
 * @param array<int, string> $argv
 * @return array<string, mixed>
 */
function parse_args(array $argv): array
{
    $options = [
        'date' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d'),
        'platform' => '',
        'hotel_id' => '',
        'system_hotel_id' => '',
        'evidence' => '',
        'limit' => 5000,
        'strict' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--strict') {
            $options['strict'] = true;
            continue;
        }
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

    return $options;
}

/**
 * @param array<int, array<string, mixed>> $target
 * @param array<string, mixed> $details
 */
function add_check(array &$target, string $code, string $status, string $message, array $details = []): void
{
    $row = [
        'code' => $code,
        'status' => $status,
        'message' => $message,
    ];
    if ($details !== []) {
        $row['details'] = $details;
    }
    $target[] = $row;
}

/**
 * @param array<string, mixed> $result
 * @param array<string, mixed> $details
 */
function add_missing(array &$result, string $code, string $message, array $details = []): void
{
    $row = [
        'code' => $code,
        'message' => $message,
    ];
    if ($details !== []) {
        $row['details'] = $details;
    }
    $result['missing_requirements'][] = $row;
}

/**
 * @return array<int, string>
 */
function source_aliases(string $platform): array
{
    return match ($platform) {
        'ctrip' => ['ctrip', 'ctrip_business', 'ctrip_manual_overview', 'ctrip_browser_profile'],
        'meituan' => ['meituan', 'meituan_rank', 'meituan_business', 'meituan_browser_profile'],
        default => [$platform],
    };
}

function table_exists(string $table): bool
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return false;
    }
    return Db::query("SHOW TABLES LIKE '" . addslashes($table) . "'") !== [];
}

/**
 * @return array<string, bool>
 */
function table_columns(string $table): array
{
    $columns = [];
    foreach (Db::query('SHOW COLUMNS FROM `' . $table . '`') as $row) {
        $name = (string)($row['Field'] ?? '');
        if ($name !== '') {
            $columns[$name] = true;
        }
    }
    return $columns;
}

/**
 * @param array<string, bool> $columns
 * @param array<string, mixed> $options
 * @return array<int, array<string, mixed>>
 */
function query_source_rows(array $columns, string $platform, array $options): array
{
    $fields = array_values(array_intersect([
        'id',
        'system_hotel_id',
        'hotel_id',
        'hotel_name',
        'source',
        'data_date',
        'data_type',
        'amount',
        'quantity',
        'book_order_num',
        'validation_status',
        'validation_flags',
        'data_source_id',
        'sync_task_id',
        'ingestion_method',
        'source_trace_id',
        'status',
        'save_status',
        'error_info',
        'failure_reason',
        'failed_reason',
        'update_time',
        'updated_at',
        'create_time',
        'created_at',
    ], array_keys($columns)));

    $query = Db::name('online_daily_data')->field($fields ?: '*');
    if (isset($columns['source'])) {
        $query->whereIn('source', source_aliases($platform));
    }
    if (isset($columns['data_date'])) {
        $query->where('data_date', (string)$options['date']);
    }
    if ((string)$options['hotel_id'] !== '' && isset($columns['hotel_id'])) {
        $query->where('hotel_id', (string)$options['hotel_id']);
    }
    if ((string)$options['system_hotel_id'] !== '' && isset($columns['system_hotel_id'])) {
        $query->where('system_hotel_id', (int)$options['system_hotel_id']);
    }

    return $query
        ->order('id', 'desc')
        ->limit((int)$options['limit'])
        ->select()
        ->toArray();
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, string>
 */
function data_types(array $rows): array
{
    $types = [];
    foreach ($rows as $row) {
        $type = trim((string)($row['data_type'] ?? ''));
        if ($type !== '') {
            $types[$type] = true;
        }
    }
    return array_keys($types);
}

/**
 * @param array<int, array<string, mixed>> $rows
 */
function latest_time(array $rows): ?string
{
    foreach ($rows as $row) {
        foreach (['updated_at', 'update_time', 'created_at', 'create_time'] as $field) {
            $value = trim((string)($row[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
    }
    return null;
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<string, mixed>>
 */
function sample_traces(array $rows): array
{
    $samples = [];
    foreach (array_slice($rows, 0, 5) as $row) {
        $samples[] = array_filter([
            'row_id' => $row['id'] ?? null,
            'source' => $row['source'] ?? null,
            'data_type' => $row['data_type'] ?? null,
            'hotel_id' => $row['hotel_id'] ?? null,
            'system_hotel_id' => $row['system_hotel_id'] ?? null,
            'data_source_id' => $row['data_source_id'] ?? null,
            'sync_task_id' => $row['sync_task_id'] ?? null,
            'ingestion_method' => $row['ingestion_method'] ?? null,
            'source_trace_id' => $row['source_trace_id'] ?? null,
            'validation_status' => $row['validation_status'] ?? null,
        ], static fn($value): bool => $value !== null && $value !== '');
    }
    return $samples;
}

/**
 * @return array<int, mixed>
 */
function rows_list(mixed $value): array
{
    return is_array($value) ? array_values($value) : [];
}

/**
 * @param array<string, bool> $columns
 * @param array<string, mixed> $options
 * @param array<string, mixed> $result
 * @return array<string, mixed>
 */
function inspect_platform(string $platform, array $columns, array $options, array &$result): array
{
    $checks = [];
    $rows = query_source_rows($columns, $platform, $options);
    $filters = array_filter([
        'source' => $platform,
        'start_date' => $options['date'],
        'end_date' => $options['date'],
        'hotel_id' => $options['hotel_id'],
        'system_hotel_id' => $options['system_hotel_id'],
        'limit' => $options['limit'],
    ], static fn($value): bool => $value !== '' && $value !== null);

    if ($rows === []) {
        add_check($checks, 'source_rows_present', 'missing', 'No same-day OTA source rows found for this scope.', [
            'filters' => $filters,
        ]);
        add_missing($result, $platform . '_source_rows_missing', 'No same-day OTA source rows found.', [
            'platform' => $platform,
            'date' => $options['date'],
        ]);
    } else {
        add_check($checks, 'source_rows_present', 'proved', 'Same-day OTA source rows exist.', [
            'rows' => count($rows),
            'data_types' => data_types($rows),
        ]);
    }

    $dataset = (new OtaStandardEtlService())->buildDataset($filters);
    $daily = rows_list($dataset['fact_ota_daily'] ?? []);
    $traffic = rows_list($dataset['fact_ota_traffic'] ?? []);
    $advertising = rows_list($dataset['fact_ota_advertising'] ?? []);
    $quality = rows_list($dataset['fact_ota_quality'] ?? []);
    $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);

    if (($dataset['status'] ?? '') === 'ready') {
        add_check($checks, 'etl_ready', 'proved', 'OTA standard ETL produced readable facts.');
    } else {
        add_check($checks, 'etl_ready', 'missing', 'OTA standard ETL is not ready for this scope.', [
            'etl_status' => $dataset['status'] ?? null,
        ]);
        add_missing($result, $platform . '_etl_not_ready', 'OTA standard ETL did not produce readable facts.', [
            'platform' => $platform,
        ]);
    }

    if (($metrics['status'] ?? '') === 'ready') {
        add_check($checks, 'revenue_metrics_ready', 'proved', 'Revenue metrics are available for this scope.');
    } else {
        add_check($checks, 'revenue_metrics_ready', 'missing', 'Revenue metrics are not ready for this scope.', [
            'metric_status' => $metrics['status'] ?? null,
        ]);
        add_missing($result, $platform . '_revenue_metrics_not_ready', 'Revenue metrics are not ready.', [
            'platform' => $platform,
        ]);
    }

    $metricTrust = $metrics['metric_trust'] ?? [];
    if (is_array($metricTrust) && $metricTrust !== []) {
        add_check($checks, 'trusted_fields_visible', 'proved', 'metric_trust is present for employee field trust display.');
    } else {
        add_check($checks, 'trusted_fields_visible', 'missing', 'metric_trust is missing or empty.');
        add_missing($result, $platform . '_metric_trust_missing', 'metric_trust is missing or empty.', [
            'platform' => $platform,
        ]);
    }

    if (array_key_exists('data_gaps', $metrics) && is_array($metrics['data_gaps'])) {
        add_check($checks, 'missing_fields_visible', 'proved', 'data_gaps is present for missing-field display.', [
            'gap_codes' => array_values(array_filter(array_column($metrics['data_gaps'], 'code'), 'is_string')),
        ]);
    } else {
        add_check($checks, 'missing_fields_visible', 'missing', 'data_gaps key is missing from metrics.');
        add_missing($result, $platform . '_data_gaps_missing', 'data_gaps key is missing from metrics.', [
            'platform' => $platform,
        ]);
    }

    if ($traffic === []) {
        add_check($checks, 'traffic_conversion_visible', 'missing', 'No traffic facts found; traffic/conversion diagnosis is not proved for this scope.');
        add_missing($result, $platform . '_traffic_facts_missing', 'No traffic facts found for same-day conversion diagnosis.', [
            'platform' => $platform,
        ]);
    } else {
        add_check($checks, 'traffic_conversion_visible', 'proved', 'Traffic and conversion facts are available.', [
            'traffic_rows' => count($traffic),
        ]);
    }

    return [
        'platform' => $platform,
        'filters' => $filters,
        'checks' => $checks,
        'source_rows' => [
            'count' => count($rows),
            'data_types' => data_types($rows),
            'latest_trace_time' => latest_time($rows),
            'sample_traces' => sample_traces($rows),
        ],
        'etl' => [
            'status' => $dataset['status'] ?? null,
            'daily_facts' => count($daily),
            'traffic_facts' => count($traffic),
            'advertising_facts' => count($advertising),
            'quality_facts' => count($quality),
            'accepted_rows' => $dataset['data_quality']['accepted_rows'] ?? null,
            'rejected_rows' => count($dataset['data_quality']['rejected_rows'] ?? []),
        ],
        'metrics' => [
            'status' => $metrics['status'] ?? null,
            'totals' => $metrics['totals'] ?? [],
            'traffic' => $metrics['traffic'] ?? [],
            'advertising' => $metrics['advertising'] ?? [],
            'quality' => $metrics['quality'] ?? [],
            'data_gap_codes' => array_values(array_filter(array_column($metrics['data_gaps'] ?? [], 'code'), 'is_string')),
            'metric_trust_keys' => is_array($metricTrust) ? array_keys($metricTrust) : [],
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function read_json_file(string $path): array
{
    global $root;
    $fullPath = $path;
    if (!preg_match('/^[A-Za-z]:[\\\\\/]/', $path) && !str_starts_with($path, DIRECTORY_SEPARATOR)) {
        $fullPath = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }
    if (!is_file($fullPath)) {
        throw new RuntimeException('Evidence file does not exist: ' . $path);
    }
    $decoded = json_decode((string)file_get_contents($fullPath), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Evidence file is not valid JSON: ' . $path);
    }
    return $decoded;
}

/**
 * @param array<string, mixed> $evidence
 * @param array<string, mixed> $options
 * @param array<string, mixed> $result
 * @return array<int, array<string, mixed>>
 */
function validate_external_evidence(array $evidence, array $options, array &$result): array
{
    $checks = [];
    $scope = is_array($evidence['scope'] ?? null) ? $evidence['scope'] : [];
    if (($scope['date'] ?? '') === $options['date']) {
        add_check($checks, 'evidence_scope_date', 'proved', 'Evidence date matches requested scope.');
    } else {
        add_check($checks, 'evidence_scope_date', 'missing', 'Evidence date does not match requested scope.', [
            'expected' => $options['date'],
            'actual' => $scope['date'] ?? null,
        ]);
        add_missing($result, 'evidence_scope_date_mismatch', 'Evidence date does not match requested scope.');
    }

    $diagnosis = is_array($evidence['ota_diagnosis'] ?? null) ? $evidence['ota_diagnosis'] : [];
    $diagnosisEvidence = $diagnosis['evidence_sources'] ?? [];
    $diagnosisActions = $diagnosis['action_items'] ?? [];
    if (is_array($diagnosisEvidence) && $diagnosisEvidence !== [] && is_array($diagnosisActions) && $diagnosisActions !== [] && array_key_exists('data_gaps', $diagnosis)) {
        add_check($checks, 'ai_diagnosis_evidence', 'proved', 'OTA diagnosis carries evidence_sources, data_gaps, and action_items.');
    } else {
        add_check($checks, 'ai_diagnosis_evidence', 'missing', 'OTA diagnosis evidence sample is incomplete.');
        add_missing($result, 'ai_diagnosis_evidence_sample_missing', 'OTA diagnosis needs evidence_sources, data_gaps, and action_items.');
    }

    $operation = is_array($evidence['operation_execution'] ?? null) ? $evidence['operation_execution'] : [];
    $intents = $operation['execution_intents'] ?? [];
    $flow = is_array($operation['execution_flow'] ?? null) ? $operation['execution_flow'] : [];
    $flowRows = [];
    foreach (['list', 'stages'] as $key) {
        if (is_array($flow[$key] ?? null)) {
            $flowRows = array_merge($flowRows, $flow[$key]);
        }
    }
    if ((is_array($intents) && $intents !== []) || $flowRows !== []) {
        add_check($checks, 'operation_execution_sample', 'proved', 'Operation execution evidence carries intents or execution flow.');
    } else {
        add_check($checks, 'operation_execution_sample', 'missing', 'Operation execution sample is missing.');
        add_missing($result, 'operation_execution_sample_missing', 'Operation loop needs an execution intent or execution-flow sample.');
    }

    return $checks;
}

try {
    $options = parse_args($argv);
    $platforms = trim((string)$options['platform']) !== ''
        ? [strtolower((string)$options['platform'])]
        : ['ctrip', 'meituan'];

    $result = [
        'status' => 'incomplete',
        'mode' => $options['strict'] ? 'verify' : 'inspect',
        'scope' => [
            'date' => $options['date'],
            'platforms' => $platforms,
            'hotel_id' => $options['hotel_id'] ?: null,
            'system_hotel_id' => $options['system_hotel_id'] ?: null,
            'table' => 'online_daily_data',
            'metric_scope' => 'ota_channel',
        ],
        'checks' => [],
        'platforms' => [],
        'external_evidence' => null,
        'missing_requirements' => [],
        'issues' => [],
    ];

    $app = new App();
    $app->initialize();
    add_check($result['checks'], 'app_bootstrap', 'proved', 'ThinkPHP application initialized.');

    if (!table_exists('online_daily_data')) {
        throw new RuntimeException('online_daily_data table does not exist.');
    }
    add_check($result['checks'], 'source_table_present', 'proved', 'online_daily_data table exists.');
    $columns = table_columns('online_daily_data');
    foreach (['id', 'hotel_id', 'data_date', 'source', 'raw_data', 'data_type'] as $column) {
        if (!isset($columns[$column])) {
            throw new RuntimeException('online_daily_data missing required column: ' . $column);
        }
    }
    add_check($result['checks'], 'source_columns_present', 'proved', 'Core online_daily_data columns exist.');

    foreach ($platforms as $platform) {
        if (!in_array($platform, ['ctrip', 'meituan'], true)) {
            add_missing($result, 'unsupported_platform', 'Only ctrip and meituan are supported in phase-one OTA closure.', [
                'platform' => $platform,
            ]);
            continue;
        }
        $result['platforms'][] = inspect_platform($platform, $columns, $options, $result);
    }

    if ((string)$options['evidence'] !== '') {
        $evidence = read_json_file((string)$options['evidence']);
        $result['external_evidence'] = [
            'path' => $options['evidence'],
            'checks' => validate_external_evidence($evidence, $options, $result),
        ];
    } else {
        $result['external_evidence'] = [
            'path' => null,
            'checks' => [],
        ];
        add_missing($result, 'ai_diagnosis_evidence_sample_missing', 'No evidence JSON supplied for OTA diagnosis evidence_sources/data_gaps/action_items.');
        add_missing($result, 'operation_execution_sample_missing', 'No evidence JSON supplied for operation execution intent/flow sample.');
    }

    $result['status'] = $result['missing_requirements'] === [] ? 'passed' : 'incomplete';
} catch (Throwable $e) {
    $result = $result ?? [
        'status' => 'failed',
        'mode' => 'inspect',
        'scope' => [],
        'checks' => [],
        'platforms' => [],
        'external_evidence' => null,
        'missing_requirements' => [],
        'issues' => [],
    ];
    $result['status'] = 'failed';
    $result['issues'][] = [
        'severity' => 'error',
        'code' => 'inspector_runtime_error',
        'message' => $e->getMessage(),
    ];
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

$hasError = ($result['status'] ?? '') === 'failed';
$strictIncomplete = ($result['mode'] ?? '') === 'verify' && ($result['missing_requirements'] ?? []) !== [];
exit($hasError || $strictIncomplete ? 1 : 0);
