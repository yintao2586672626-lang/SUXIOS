<?php
declare(strict_types=1);

use app\service\OtaRevenueMetricService;
use app\service\OtaStandardEtlService;
use think\App;
use think\facade\Db;

require __DIR__ . '/../vendor/autoload.php';

$root = dirname(__DIR__);

function parse_args(array $argv): array
{
    $options = [
        'date' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d'),
        'platform' => '',
        'hotel_id' => '',
        'system_hotel_id' => '',
        'diagnosis' => '',
        'operation' => '',
        'output' => '',
        'limit' => 5000,
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

    return $options;
}

function resolve_path(string $path): string
{
    global $root;
    if ($path === '') {
        return '';
    }
    if (preg_match('/^[A-Za-z]:[\\\\\/]/', $path) || str_starts_with($path, DIRECTORY_SEPARATOR)) {
        return $path;
    }
    return $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
}

function read_json_file(string $path): array
{
    $resolved = resolve_path($path);
    if (!is_file($resolved)) {
        throw new RuntimeException('JSON file does not exist: ' . $path);
    }
    $decoded = json_decode((string)file_get_contents($resolved), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('JSON file is not valid JSON: ' . $path);
    }
    return $decoded;
}

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

function filters_for(string $platform, array $options): array
{
    return array_filter([
        'source' => $platform,
        'start_date' => $options['date'],
        'end_date' => $options['date'],
        'hotel_id' => $options['hotel_id'],
        'system_hotel_id' => $options['system_hotel_id'],
        'limit' => $options['limit'],
    ], static fn($value): bool => $value !== '' && $value !== null);
}

function rows_list(mixed $value): array
{
    return is_array($value) ? array_values($value) : [];
}

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

function sample_traces(array $rows): array
{
    $samples = [];
    foreach (array_slice($rows, 0, 10) as $row) {
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

function unwrap_api_data(array $payload): array
{
    if (is_array($payload['data'] ?? null)) {
        return $payload['data'];
    }
    return $payload;
}

function array_value(array $source, array $paths, mixed $default = null): mixed
{
    foreach ($paths as $path) {
        $cursor = $source;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                continue 2;
            }
            $cursor = $cursor[$segment];
        }
        return $cursor;
    }
    return $default;
}

function normalize_diagnosis(array $payload): array
{
    $data = unwrap_api_data($payload);
    $evidence = array_value($data, ['evidence_sources', 'diagnosis.evidence_sources'], []);
    $gaps = array_value($data, ['data_gaps', 'diagnosis.data_gaps', 'metrics.data_gaps'], []);
    $actions = array_value($data, ['action_items', 'recommended_actions', 'diagnosis.action_items'], []);

    return [
        'source' => '/api/agent/ota-diagnosis',
        'status' => 'from_api_response',
        'summary' => array_value($data, ['summary', 'diagnosis.summary', 'core_conclusion'], null),
        'evidence_sources' => is_array($evidence) ? array_values($evidence) : [],
        'data_gaps' => is_array($gaps) ? array_values($gaps) : [],
        'action_items' => is_array($actions) ? array_values($actions) : [],
    ];
}

function normalize_operation(array $payload): array
{
    $data = unwrap_api_data($payload);
    $intents = array_value($data, ['execution_intents', 'list', 'items'], []);
    $flow = array_value($data, ['execution_flow', 'flow'], []);

    return [
        'source' => '/api/operation/execution-intents',
        'status' => 'from_api_response',
        'execution_intents' => is_array($intents) ? array_values($intents) : [],
        'execution_flow' => is_array($flow) ? $flow : [],
    ];
}

function inspect_platform(string $platform, array $columns, array $options): array
{
    $rows = query_source_rows($columns, $platform, $options);
    $filters = filters_for($platform, $options);
    $dataset = (new OtaStandardEtlService())->buildDataset($filters);
    $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);

    return [
        'platform' => $platform,
        'filters' => $filters,
        'source_rows' => [
            'count' => count($rows),
            'data_types' => data_types($rows),
            'latest_trace_time' => latest_time($rows),
            'sample_traces' => sample_traces($rows),
        ],
        'etl' => [
            'status' => $dataset['status'] ?? null,
            'daily_facts' => count(rows_list($dataset['fact_ota_daily'] ?? [])),
            'traffic_facts' => count(rows_list($dataset['fact_ota_traffic'] ?? [])),
            'advertising_facts' => count(rows_list($dataset['fact_ota_advertising'] ?? [])),
            'quality_facts' => count(rows_list($dataset['fact_ota_quality'] ?? [])),
            'accepted_rows' => $dataset['data_quality']['accepted_rows'] ?? null,
            'rejected_rows' => count($dataset['data_quality']['rejected_rows'] ?? []),
        ],
        'revenue_metrics' => [
            'status' => $metrics['status'] ?? null,
            'source' => 'OtaRevenueMetricService::summarizeDataset',
            'totals' => $metrics['totals'] ?? [],
            'traffic' => $metrics['traffic'] ?? [],
            'advertising' => $metrics['advertising'] ?? [],
            'quality' => $metrics['quality'] ?? [],
            'data_gaps' => $metrics['data_gaps'] ?? [],
            'metric_trust' => $metrics['metric_trust'] ?? [],
        ],
    ];
}

try {
    $options = parse_args($argv);
    $platforms = trim((string)$options['platform']) !== ''
        ? [strtolower((string)$options['platform'])]
        : ['ctrip', 'meituan'];

    $app = new App();
    $app->initialize();

    if (!table_exists('online_daily_data')) {
        throw new RuntimeException('online_daily_data table does not exist.');
    }
    $columns = table_columns('online_daily_data');

    $platformEvidence = [];
    foreach ($platforms as $platform) {
        if (!in_array($platform, ['ctrip', 'meituan'], true)) {
            throw new InvalidArgumentException('Unsupported --platform, expected ctrip or meituan.');
        }
        $platformEvidence[] = inspect_platform($platform, $columns, $options);
    }

    $primary = $platformEvidence[0] ?? [];
    $diagnosis = (string)$options['diagnosis'] !== ''
        ? normalize_diagnosis(read_json_file((string)$options['diagnosis']))
        : [
            'source' => '/api/agent/ota-diagnosis',
            'status' => 'missing_real_api_response',
            'evidence_sources' => [],
            'data_gaps' => [],
            'action_items' => [],
        ];
    $operation = (string)$options['operation'] !== ''
        ? normalize_operation(read_json_file((string)$options['operation']))
        : [
            'source' => '/api/operation/execution-intents',
            'status' => 'missing_real_api_response',
            'execution_intents' => [],
            'execution_flow' => [],
        ];

    $evidence = [
        'scope' => [
            'date' => $options['date'],
            'platform' => count($platforms) === 1 ? $platforms[0] : null,
            'platforms' => $platforms,
            'system_hotel_id' => $options['system_hotel_id'] !== '' ? (int)$options['system_hotel_id'] : null,
            'hotel_id' => $options['hotel_id'] !== '' ? (string)$options['hotel_id'] : null,
            'metric_scope' => 'ota_channel',
        ],
        'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d H:i:s'),
        'generator' => [
            'script' => 'scripts/build_phase1_ota_live_closure_evidence.php',
            'mode' => 'read_only',
            'writes_ota_data' => false,
            'changes_acquisition_logic' => false,
        ],
        'collection_reliability' => [
            'status' => array_sum(array_map(static fn(array $item): int => (int)($item['source_rows']['count'] ?? 0), $platformEvidence)) > 0 ? 'source_rows_present' : 'source_rows_missing',
            'source' => 'online_daily_data',
            'platforms' => array_map(static fn(array $item): array => [
                'platform' => $item['platform'],
                'source_rows' => $item['source_rows']['count'],
                'data_types' => $item['source_rows']['data_types'],
                'latest_trace_time' => $item['source_rows']['latest_trace_time'],
                'sample_traces' => $item['source_rows']['sample_traces'],
            ], $platformEvidence),
        ],
        'platform_evidence' => $platformEvidence,
        'revenue_metrics' => [
            'status' => $primary['revenue_metrics']['status'] ?? 'empty',
            'source' => '/api/ota-standard/revenue-metrics',
            'data_gaps' => $primary['revenue_metrics']['data_gaps'] ?? [],
            'metric_trust' => $primary['revenue_metrics']['metric_trust'] ?? [],
            'totals' => $primary['revenue_metrics']['totals'] ?? [],
            'traffic' => $primary['revenue_metrics']['traffic'] ?? [],
            'advertising' => $primary['revenue_metrics']['advertising'] ?? [],
            'quality' => $primary['revenue_metrics']['quality'] ?? [],
        ],
        'ota_diagnosis' => $diagnosis,
        'operation_execution' => $operation,
        'limitations' => array_values(array_filter([
            (string)$options['diagnosis'] === '' ? 'ota_diagnosis_api_response_missing' : null,
            (string)$options['operation'] === '' ? 'operation_execution_api_response_missing' : null,
        ])),
    ];

    $json = json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('Failed to encode evidence JSON.');
    }

    if ((string)$options['output'] !== '') {
        $output = resolve_path((string)$options['output']);
        $dir = dirname($output);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create output directory: ' . $dir);
        }
        file_put_contents($output, $json . PHP_EOL);
        fwrite(STDERR, 'Wrote phase-one OTA live closure evidence: ' . $output . PHP_EOL);
    } else {
        echo $json . PHP_EOL;
    }
} catch (Throwable $e) {
    fwrite(STDERR, '[build:phase1-live-evidence] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
