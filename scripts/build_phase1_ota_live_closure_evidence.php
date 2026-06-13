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

    $query = scoped_source_query($columns, $platform, $options, (string)$options['date'])->field($fields ?: '*');

    return $query
        ->order('id', 'desc')
        ->limit((int)$options['limit'])
        ->select()
        ->toArray();
}

function scoped_source_query(array $columns, string $platform, array $options, ?string $date = null): object
{
    $query = Db::name('online_daily_data');
    if (isset($columns['source'])) {
        $query->whereIn('source', source_aliases($platform));
    }
    if ($date !== null && isset($columns['data_date'])) {
        $query->where('data_date', $date);
    }
    if ((string)$options['hotel_id'] !== '' && isset($columns['hotel_id'])) {
        $query->where('hotel_id', (string)$options['hotel_id']);
    }
    if ((string)$options['system_hotel_id'] !== '' && isset($columns['system_hotel_id'])) {
        $query->where('system_hotel_id', (int)$options['system_hotel_id']);
    }

    return $query;
}

function query_latest_available_source_rows(array $columns, string $platform, array $options): array
{
    if (!isset($columns['data_date'])) {
        return [
            'date' => null,
            'date_relation' => 'unknown',
            'count' => 0,
            'data_types' => [],
            'latest_trace_time' => null,
            'sample_traces' => [],
        ];
    }

    $latestRow = scoped_source_query($columns, $platform, $options)
        ->field('MAX(data_date) AS latest_data_date')
        ->find();
    $latestDate = (string)($latestRow['latest_data_date'] ?? '');
    if ($latestDate === '') {
        return [
            'date' => null,
            'date_relation' => 'none',
            'count' => 0,
            'data_types' => [],
            'latest_trace_time' => null,
            'sample_traces' => [],
        ];
    }

    $latestOptions = $options;
    $latestOptions['date'] = $latestDate;
    $rows = query_source_rows($columns, $platform, $latestOptions);

    return [
        'date' => $latestDate,
        'date_relation' => source_date_relation((string)$options['date'], $latestDate),
        'count' => (int)scoped_source_query($columns, $platform, $latestOptions, $latestDate)->count(),
        'data_types' => data_types($rows),
        'latest_trace_time' => latest_time($rows),
        'sample_traces' => sample_traces($rows),
    ];
}

function source_date_relation(string $targetDate, string $latestDate): string
{
    if ($latestDate === '') {
        return 'none';
    }
    if ($latestDate === $targetDate) {
        return 'target_date';
    }
    return strcmp($latestDate, $targetDate) > 0 ? 'future_dated_for_target' : 'stale_before_target';
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

function evidence_scope_details(array $data, string $expectedDate): array
{
    $scope = array_value($data, ['scope', 'diagnosis.scope', 'operation.scope'], []);
    $scope = is_array($scope) ? $scope : [];
    $scopeDate = trim((string)($scope['date'] ?? array_value($data, ['target_date', 'date'], '')));
    $scopeDateStatus = $scopeDate === ''
        ? 'missing'
        : ($scopeDate === $expectedDate ? 'matched' : 'mismatch');

    return [
        'scope' => $scope,
        'scope_date' => $scopeDate !== '' ? $scopeDate : null,
        'expected_scope_date' => $expectedDate,
        'scope_date_status' => $scopeDateStatus,
    ];
}

function evidence_scope_date_aligned(array $payload): bool
{
    return (string)($payload['scope_date_status'] ?? '') === 'matched';
}

function normalize_diagnosis(array $payload, array $options): array
{
    $data = unwrap_api_data($payload);
    $evidence = array_value($data, ['evidence_sources', 'diagnosis.evidence_sources'], []);
    $gaps = array_value($data, ['data_gaps', 'diagnosis.data_gaps', 'metrics.data_gaps'], []);
    $actions = array_value($data, ['action_items', 'recommended_actions', 'diagnosis.action_items'], []);

    return array_merge([
        'source' => '/api/agent/ota-diagnosis',
        'status' => 'from_api_response',
        'summary' => array_value($data, ['summary', 'diagnosis.summary', 'core_conclusion'], null),
        'evidence_sources' => is_array($evidence) ? array_values($evidence) : [],
        'data_gaps' => is_array($gaps) ? array_values($gaps) : [],
        'action_items' => is_array($actions) ? array_values($actions) : [],
    ], evidence_scope_details($data, (string)$options['date']));
}

function normalize_operation(array $payload, array $options): array
{
    $data = unwrap_api_data($payload);
    $intents = array_value($data, ['execution_intents', 'list', 'items'], []);
    $flow = array_value($data, ['execution_flow', 'flow'], []);

    return array_merge([
        'source' => '/api/operation/execution-intents',
        'status' => 'from_api_response',
        'execution_intents' => is_array($intents) ? array_values($intents) : [],
        'execution_flow' => is_array($flow) ? $flow : [],
    ], evidence_scope_details($data, (string)$options['date']));
}

function inspect_platform(string $platform, array $columns, array $options): array
{
    $rows = query_source_rows($columns, $platform, $options);
    $latestAvailable = query_latest_available_source_rows($columns, $platform, $options);
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
            'latest_available' => $latestAvailable,
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

function total_source_rows(array $platformEvidence): int
{
    return array_sum(array_map(static fn(array $item): int => (int)($item['source_rows']['count'] ?? 0), $platformEvidence));
}

function platform_row_counts(array $platformEvidence): array
{
    return array_values(array_map(static function (array $item): array {
        $latestAvailable = is_array($item['source_rows']['latest_available'] ?? null)
            ? $item['source_rows']['latest_available']
            : [];
        return [
            'platform' => (string)($item['platform'] ?? ''),
            'source_rows' => (int)($item['source_rows']['count'] ?? 0),
            'target_date_rows' => (int)($item['source_rows']['count'] ?? 0),
            'etl_status' => (string)($item['etl']['status'] ?? 'unknown'),
            'metric_status' => (string)($item['revenue_metrics']['status'] ?? 'unknown'),
            'traffic_rows' => (int)($item['revenue_metrics']['traffic']['rows'] ?? 0),
            'latest_available' => $latestAvailable ?: null,
            'latest_available_date' => $latestAvailable['date'] ?? null,
            'latest_available_date_relation' => $latestAvailable['date_relation'] ?? null,
        ];
    }, $platformEvidence));
}

function build_collection_source_summary(array $platformEvidence, string $targetDate = ''): array
{
    return array_values(array_map(static function (array $item) use ($targetDate): array {
        $sourceRows = is_array($item['source_rows'] ?? null) ? $item['source_rows'] : [];
        $latestAvailable = is_array($sourceRows['latest_available'] ?? null) ? $sourceRows['latest_available'] : [];
        $etl = is_array($item['etl'] ?? null) ? $item['etl'] : [];
        $metrics = is_array($item['revenue_metrics'] ?? null) ? $item['revenue_metrics'] : [];
        $traffic = is_array($metrics['traffic'] ?? null) ? $metrics['traffic'] : [];
        $latestRelation = trim((string)($latestAvailable['date_relation'] ?? 'none'));
        if ($latestRelation === '') {
            $latestRelation = 'none';
        }

        return [
            'platform' => strtolower((string)($item['platform'] ?? '')),
            'target_date' => $targetDate,
            'storage_table' => 'online_daily_data',
            'source_policy' => 'read_existing_online_daily_data_only',
            'metric_scope' => 'ota_channel',
            'target_date_rows' => (int)($sourceRows['count'] ?? 0),
            'target_date_data_types' => array_values(array_map('strval', (array)($sourceRows['data_types'] ?? []))),
            'target_date_latest_trace_time' => $sourceRows['latest_trace_time'] ?? null,
            'latest_available' => $latestAvailable === [] ? null : [
                'date' => $latestAvailable['date'] ?? null,
                'date_relation' => $latestRelation,
                'rows' => (int)($latestAvailable['count'] ?? 0),
                'data_types' => array_values(array_map('strval', (array)($latestAvailable['data_types'] ?? []))),
                'latest_trace_time' => $latestAvailable['latest_trace_time'] ?? null,
            ],
            'latest_available_reference_only' => $latestRelation !== 'target_date',
            'etl_status' => (string)($etl['status'] ?? 'unknown'),
            'daily_facts' => (int)($etl['daily_facts'] ?? 0),
            'traffic_rows' => (int)($traffic['rows'] ?? $etl['traffic_facts'] ?? 0),
            'metric_status' => (string)($metrics['status'] ?? 'unknown'),
            'collection_logic_changed' => false,
        ];
    }, $platformEvidence));
}

function coverage_status(array $platformEvidence): string
{
    if ($platformEvidence === []) {
        return 'missing';
    }
    $covered = count(array_filter($platformEvidence, static fn(array $item): bool => (int)($item['source_rows']['count'] ?? 0) > 0));
    if ($covered === count($platformEvidence)) {
        return 'complete';
    }
    return $covered > 0 ? 'partial' : 'missing';
}

function missing_source_platforms(array $platformEvidence): array
{
    return array_values(array_map(
        static fn(array $item): string => (string)($item['platform'] ?? ''),
        array_filter($platformEvidence, static fn(array $item): bool => (int)($item['source_rows']['count'] ?? 0) === 0)
    ));
}

function first_revenue_metrics(array $platformEvidence): array
{
    $primary = $platformEvidence[0] ?? [];
    return is_array($primary['revenue_metrics'] ?? null) ? $primary['revenue_metrics'] : [];
}

function metric_trust_keys(array $platformEvidence): array
{
    $keys = [];
    foreach ($platformEvidence as $item) {
        $targetRows = (int)($item['source_rows']['count'] ?? 0);
        $metricStatus = (string)($item['revenue_metrics']['status'] ?? 'unknown');
        if ($targetRows <= 0 || $metricStatus !== 'ready') {
            continue;
        }
        $trust = $item['revenue_metrics']['metric_trust'] ?? [];
        if (!is_array($trust)) {
            continue;
        }
        foreach (trusted_metric_trust_keys($trust) as $key) {
            $keys[(string)$key] = true;
        }
    }
    return array_values(array_keys($keys));
}

/**
 * @param array<string, mixed> $trust
 * @return array<int, string>
 */
function trusted_metric_trust_keys(array $trust): array
{
    $keys = [];
    foreach ($trust as $key => $row) {
        $keyText = trim((string)$key);
        if ($keyText === '') {
            continue;
        }
        if (is_array($row)) {
            if (($row['saved_success'] ?? false) === true) {
                $keys[] = $keyText;
            }
            continue;
        }
        if ($row === true) {
            $keys[] = $keyText;
        }
    }
    return array_values(array_unique($keys));
}

function platform_field_trust(array $platformEvidence): array
{
    return array_values(array_map(static function (array $item): array {
        $platformName = strtolower(trim((string)($item['platform'] ?? '')));
        $targetRows = (int)($item['source_rows']['count'] ?? 0);
        $metricStatus = (string)($item['revenue_metrics']['status'] ?? 'unknown');
        $trust = $item['revenue_metrics']['metric_trust'] ?? [];
        $reportedTrustKeys = is_array($trust) ? array_values(array_map('strval', array_keys($trust))) : [];
        $trustKeys = $targetRows > 0 && $metricStatus === 'ready' && is_array($trust)
            ? trusted_metric_trust_keys($trust)
            : [];
        $fieldTrustStatus = match (true) {
            $targetRows <= 0 => 'target_date_source_missing',
            $metricStatus === 'ready' && $trustKeys !== [] => 'metric_trust_ready',
            $reportedTrustKeys !== [] => 'metric_trust_reference_only',
            default => 'metric_trust_missing',
        };
        $reasonCodes = [];
        if ($targetRows <= 0 && $platformName !== '') {
            $reasonCodes[] = $platformName . '_source_rows_missing';
        }
        if ($metricStatus !== 'ready' && $platformName !== '') {
            $reasonCodes[] = $platformName . '_revenue_metrics_not_ready';
        }
        if ($trustKeys === [] && $reportedTrustKeys === [] && $platformName !== '') {
            $reasonCodes[] = $platformName . '_metric_trust_missing';
        }
        if ($trustKeys === [] && $reportedTrustKeys !== [] && $platformName !== '') {
            $reasonCodes[] = $platformName . '_metric_trust_not_proved';
        }

        return [
            'platform' => (string)($item['platform'] ?? ''),
            'target_date_rows' => $targetRows,
            'metric_status' => $metricStatus,
            'field_trust_status' => $fieldTrustStatus,
            'metric_trust_key_count' => count($trustKeys),
            'metric_trust_keys' => $trustKeys,
            'reported_metric_trust_key_count' => count($reportedTrustKeys),
            'reason_codes' => array_values(array_unique($reasonCodes)),
            'source_policy' => 'target_date_rows_plus_metric_trust_required',
        ];
    }, $platformEvidence));
}

function metric_trust_count(array $platformEvidence): int
{
    return count(metric_trust_keys($platformEvidence));
}

function data_gap_codes(array $platformEvidence, array $diagnosis): array
{
    $codes = [];
    foreach ($platformEvidence as $item) {
        foreach ((array)($item['revenue_metrics']['data_gaps'] ?? []) as $gap) {
            if (is_array($gap) && trim((string)($gap['code'] ?? '')) !== '') {
                $codes[(string)$gap['code']] = true;
            }
        }
    }
    if (evidence_scope_date_aligned($diagnosis)
        && (string)($diagnosis['source_policy'] ?? '') !== 'generated_blocked_from_verified_missing_requirements'
    ) {
        foreach ((array)($diagnosis['data_gaps'] ?? []) as $gap) {
            if (is_array($gap) && trim((string)($gap['code'] ?? '')) !== '') {
                $codes[(string)$gap['code']] = true;
            }
        }
    }
    return array_keys($codes);
}

function platform_evidence_missing_codes(array $platformEvidence): array
{
    $codes = [];
    foreach ($platformEvidence as $item) {
        $platform = (string)($item['platform'] ?? '');
        if ($platform === '') {
            continue;
        }
        if ((int)($item['source_rows']['count'] ?? 0) === 0) {
            $codes[] = $platform . '_source_rows_missing';
        }
        if ((string)($item['etl']['status'] ?? '') !== 'ready') {
            $codes[] = $platform . '_etl_not_ready';
        }
        if ((string)($item['revenue_metrics']['status'] ?? '') !== 'ready') {
            $codes[] = $platform . '_revenue_metrics_not_ready';
        }
        if ((int)($item['revenue_metrics']['traffic']['rows'] ?? 0) === 0) {
            $codes[] = $platform . '_traffic_facts_missing';
        }
    }
    return array_values(array_unique($codes));
}

function build_blocked_diagnosis_from_platform_evidence(array $platformEvidence, array $options): array
{
    $missingCodes = platform_evidence_missing_codes($platformEvidence);
    if ($missingCodes === []) {
        return [
            'source' => '/api/agent/ota-diagnosis',
            'status' => 'missing_real_api_response',
            'evidence_sources' => [],
            'data_gaps' => [],
            'action_items' => [],
        ];
    }

    $evidenceSources = [[
        'ref' => 'phase1_verified_ota_gap_scope',
        'source_policy' => 'generated_blocked_from_verified_missing_requirements',
        'metric_scope' => 'ota_channel',
        'date' => (string)$options['date'],
        'platforms' => array_values(array_map(static fn(array $item): string => (string)($item['platform'] ?? ''), $platformEvidence)),
    ]];
    foreach ($platformEvidence as $item) {
        $platform = (string)($item['platform'] ?? '');
        if ($platform === '') {
            continue;
        }
        $evidenceSources[] = [
            'ref' => 'phase1_' . $platform . '_target_date_evidence',
            'source_policy' => 'read_only_online_daily_data_and_metric_summary',
            'metric_scope' => 'ota_channel',
            'date' => (string)$options['date'],
            'platform' => $platform,
            'target_date_rows' => (int)($item['source_rows']['count'] ?? 0),
            'etl_status' => (string)($item['etl']['status'] ?? 'unknown'),
            'revenue_status' => (string)($item['revenue_metrics']['status'] ?? 'unknown'),
            'traffic_rows' => (int)($item['revenue_metrics']['traffic']['rows'] ?? 0),
            'latest_available' => $item['source_rows']['latest_available'] ?? null,
        ];
    }

    return [
        'source' => '/api/agent/ota-diagnosis',
        'status' => 'blocked_by_verified_ota_gaps',
        'source_policy' => 'generated_blocked_from_verified_missing_requirements',
        'scope' => [
            'date' => (string)$options['date'],
            'metric_scope' => 'ota_channel',
        ],
        'scope_date' => (string)$options['date'],
        'expected_scope_date' => (string)$options['date'],
        'scope_date_status' => 'matched',
        'summary' => '上游 OTA 数据和指标证据未闭合，当前不能生成确定 AI 经营建议。',
        'evidence_sources' => $evidenceSources,
        'data_gaps' => array_values(array_map(static fn(string $code): array => [
            'code' => $code,
            'message' => 'Phase-one OTA closure blocker: ' . $code,
            'scope' => 'ota_channel',
        ], $missingCodes)),
        'action_items' => [[
            'id' => 'phase1_ai_diagnosis_blocked_by_ota_gaps',
            'action' => '先处理目标日 OTA 数据、收益指标或流量/转化事实缺口，再重新生成 OTA AI 诊断。',
            'status' => 'blocked_by_verified_ota_gaps',
            'evidence_refs' => array_values(array_map(static fn(array $source): string => (string)$source['ref'], $evidenceSources)),
            'blocking_missing_codes' => $missingCodes,
            'source_policy' => 'not_execution_ready_until_ota_evidence_closed',
        ]],
    ];
}

function diagnosis_content_present(array $diagnosis): bool
{
    return !empty($diagnosis['evidence_sources'])
        && array_key_exists('data_gaps', $diagnosis)
        && !empty($diagnosis['action_items']);
}

function has_diagnosis_evidence(array $diagnosis): bool
{
    return diagnosis_content_present($diagnosis) && evidence_scope_date_aligned($diagnosis);
}

function diagnosis_action_item_statuses(array $diagnosis): array
{
    return array_values(array_filter(array_map(
        static fn($item): string => is_array($item) ? trim((string)($item['status'] ?? '')) : '',
        (array)($diagnosis['action_items'] ?? [])
    ), static fn(string $status): bool => $status !== ''));
}

function is_blocked_diagnosis_action_status(string $status): bool
{
    return $status === 'blocked' || str_starts_with($status, 'blocked_');
}

function blocked_diagnosis_action_count(array $diagnosis): int
{
    return count(array_filter(
        diagnosis_action_item_statuses($diagnosis),
        static fn(string $status): bool => is_blocked_diagnosis_action_status($status)
    ));
}

function actionable_diagnosis_action_count(array $diagnosis): int
{
    $count = 0;
    foreach ((array)($diagnosis['action_items'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $status = trim((string)($item['status'] ?? ''));
        $action = trim((string)($item['action'] ?? $item['title'] ?? ''));
        if ($action !== '' && !is_blocked_diagnosis_action_status($status)) {
            $count++;
        }
    }
    return $count;
}

function has_actionable_diagnosis_evidence(array $diagnosis): bool
{
    return has_diagnosis_evidence($diagnosis) && actionable_diagnosis_action_count($diagnosis) > 0;
}

function diagnosis_blocking_code(array $diagnosis): string
{
    if (!diagnosis_content_present($diagnosis)) {
        return 'ai_diagnosis_evidence_sample_missing';
    }
    if (!evidence_scope_date_aligned($diagnosis)) {
        return 'evidence_scope_date_mismatch';
    }
    if (!has_actionable_diagnosis_evidence($diagnosis)) {
        return 'ai_diagnosis_action_items_blocked';
    }
    return '';
}

function diagnosis_evidence_status(array $diagnosis): string
{
    if (has_actionable_diagnosis_evidence($diagnosis)) {
        return 'proved';
    }
    if (diagnosis_content_present($diagnosis)) {
        return 'warning';
    }
    return 'missing';
}

function has_operation_evidence(array $operation): bool
{
    return operation_evidence_status($operation) === 'proved';
}

function operation_flow(array $operation): array
{
    $flow = is_array($operation['execution_flow'] ?? null) ? $operation['execution_flow'] : [];
    return $flow;
}

function operation_flow_list(array $operation): array
{
    $flow = operation_flow($operation);
    $list = is_array($flow['list'] ?? null) ? $flow['list'] : [];
    return array_values(array_filter($list, static fn($item): bool => is_array($item)));
}

function operation_flow_summary(array $operation): array
{
    $flow = operation_flow($operation);
    return is_array($flow['summary'] ?? null) ? $flow['summary'] : [];
}

function operation_stage_counts(array $operation): array
{
    $summary = operation_flow_summary($operation);
    return is_array($summary['stage_counts'] ?? null) ? $summary['stage_counts'] : [];
}

function operation_flow_stage_count(array $operation): int
{
    $flow = operation_flow($operation);
    $stages = is_array($flow['stages'] ?? null) ? $flow['stages'] : [];
    return count($stages);
}

function count_operation_items(array $items, callable $predicate): int
{
    return count(array_filter($items, $predicate));
}

function operation_signal_counts(array $operation): array
{
    $items = operation_flow_list($operation);
    $linkedItems = array_values(array_filter($items, 'operation_item_has_ota_diagnosis_link'));
    $intents = array_values(array_filter(
        (array)($operation['execution_intents'] ?? []),
        static fn($item): bool => is_array($item)
    ));
    $linkedIntents = array_values(array_filter($intents, 'operation_intent_has_ota_diagnosis_link'));
    $summary = operation_flow_summary($operation);
    $stageCounts = operation_stage_counts($operation);
    $stageCountTotal = array_sum(array_map('intval', $stageCounts));

    $approvedItems = count_operation_items($linkedItems, static fn(array $item): bool => (string)($item['approval']['status'] ?? '') === 'approved');
    $executedItems = count_operation_items($linkedItems, static fn(array $item): bool => (string)($item['execution']['status'] ?? '') === 'executed');
    $evidenceReadyItems = count_operation_items($linkedItems, static fn(array $item): bool => (int)($item['evidence']['count'] ?? 0) > 0);
    $reviewedItems = count_operation_items($linkedItems, static fn(array $item): bool => (string)($item['stage'] ?? '') === 'reviewed'
        || in_array((string)($item['review']['status'] ?? ''), ['success', 'near_success', 'failed'], true));
    $roiReadyItems = count_operation_items($linkedItems, static fn(array $item): bool => (string)($item['roi']['status'] ?? '') === 'ready');
    $blockedItems = count_operation_items($linkedItems, static fn(array $item): bool => in_array((string)($item['stage'] ?? ''), ['blocked', 'rejected', 'failed'], true)
        || in_array((string)($item['approval']['status'] ?? ''), ['blocked', 'rejected'], true)
        || in_array((string)($item['execution']['status'] ?? ''), ['blocked', 'failed'], true));
    $executionEvidenceCount = array_sum(array_map(
        static fn(array $item): int => max(0, (int)($item['evidence']['count'] ?? 0)),
        $linkedItems
    ));

    return [
        'execution_intent_count' => count($intents),
        'execution_flow_item_count' => count($items),
        'execution_flow_stage_count' => operation_flow_stage_count($operation),
        'execution_flow_summary_total' => max((int)($summary['total'] ?? 0), $stageCountTotal),
        'ota_diagnosis_linked_intent_count' => count($linkedIntents),
        'ota_diagnosis_linked_flow_item_count' => count($linkedItems),
        'approved_count' => $approvedItems,
        'executed_count' => $executedItems,
        'evidence_ready_count' => $evidenceReadyItems,
        'execution_evidence_count' => $executionEvidenceCount,
        'reviewed_count' => $reviewedItems,
        'roi_ready_count' => $roiReadyItems,
        'blocked_execution_count' => $blockedItems,
        'completion_signal_count' => $approvedItems + $executedItems + $evidenceReadyItems + $reviewedItems + $roiReadyItems,
    ];
}

function operation_item_has_ota_diagnosis_link(array $item): bool
{
    $recommendation = is_array($item['recommendation'] ?? null) ? $item['recommendation'] : [];
    $evidence = is_array($recommendation['evidence'] ?? null) ? $recommendation['evidence'] : [];
    $source = strtolower((string)($recommendation['source'] ?? ''));
    $sourceModule = strtolower((string)($recommendation['source_module'] ?? ''));

    return $sourceModule === 'ota_diagnosis'
        || str_contains($source, 'ota_diagnosis')
        || !empty($evidence['evidence_refs'])
        || !empty($evidence['data_gaps'])
        || array_key_exists('action_item_id', $evidence)
        || array_key_exists('action_item_status', $evidence)
        || array_key_exists('diagnosis_summary', $evidence);
}

function operation_intent_has_ota_diagnosis_link(array $intent): bool
{
    $evidence = is_array($intent['evidence'] ?? null) ? $intent['evidence'] : [];
    $source = strtolower((string)($intent['source'] ?? ''));
    $sourceModule = strtolower((string)($intent['source_module'] ?? ''));

    return $sourceModule === 'ota_diagnosis'
        || str_contains($source, 'ota_diagnosis')
        || !empty($evidence['evidence_refs'])
        || !empty($evidence['data_gaps'])
        || array_key_exists('action_item_id', $evidence)
        || array_key_exists('action_item_status', $evidence)
        || array_key_exists('diagnosis_summary', $evidence);
}

function operation_completion_signal_count(array $operation): int
{
    $counts = operation_signal_counts($operation);
    return (int)$counts['approved_count']
        + (int)$counts['executed_count']
        + (int)$counts['evidence_ready_count']
        + (int)$counts['reviewed_count']
        + (int)$counts['roi_ready_count'];
}

function operation_payload_signal_count(array $operation): int
{
    $counts = operation_signal_counts($operation);
    return (int)$counts['execution_intent_count']
        + (int)$counts['execution_flow_item_count']
        + (int)$counts['execution_flow_summary_total'];
}

function operation_linked_payload_signal_count(array $operation): int
{
    $counts = operation_signal_counts($operation);
    return (int)$counts['ota_diagnosis_linked_intent_count']
        + (int)$counts['ota_diagnosis_linked_flow_item_count'];
}

function operation_evidence_status(array $operation): string
{
    if (operation_payload_signal_count($operation) > 0) {
        if (!evidence_scope_date_aligned($operation)) {
            return 'warning';
        }
        if (operation_completion_signal_count($operation) > 0) {
            return 'proved';
        }
        return 'warning';
    }
    return 'missing';
}

function operation_blocking_code(array $operation): string
{
    if (operation_payload_signal_count($operation) === 0) {
        return 'operation_execution_sample_missing';
    }
    if (operation_linked_payload_signal_count($operation) === 0) {
        return 'operation_execution_ai_action_link_missing';
    }
    if (!evidence_scope_date_aligned($operation)) {
        return 'evidence_scope_date_mismatch';
    }
    if (operation_evidence_status($operation) === 'warning') {
        return 'operation_execution_evidence_incomplete';
    }
    return '';
}

function metric_domain_readiness(array $platformEvidence): array
{
    return array_values(array_map(static function (array $item): array {
        $revenueReady = (string)($item['revenue_metrics']['status'] ?? '') === 'ready';
        $trafficReady = (int)($item['revenue_metrics']['traffic']['rows'] ?? 0) > 0;
        $missingDomains = [];
        if (!$revenueReady) {
            $missingDomains[] = 'revenue';
        }
        if (!$trafficReady) {
            $missingDomains[] = 'traffic';
            $missingDomains[] = 'conversion';
        }

        return [
            'platform' => (string)($item['platform'] ?? ''),
            'revenue_status' => $revenueReady ? 'ready' : 'missing',
            'traffic_status' => $trafficReady ? 'ready' : 'missing',
            'conversion_status' => $trafficReady ? 'ready' : 'missing',
            'missing_domains' => $missingDomains,
            'metric_status' => (string)($item['revenue_metrics']['status'] ?? 'unknown'),
            'traffic_rows' => (int)($item['revenue_metrics']['traffic']['rows'] ?? 0),
            'source_rows' => (int)($item['source_rows']['count'] ?? 0),
        ];
    }, $platformEvidence));
}

function ready_metric_platforms(array $domainReadiness, string $field): array
{
    return array_values(array_map(
        static fn(array $item): string => (string)($item['platform'] ?? ''),
        array_filter($domainReadiness, static fn(array $item): bool => (string)($item[$field] ?? '') === 'ready')
    ));
}

function missing_metric_platforms(array $domainReadiness, string $field): array
{
    return array_values(array_map(
        static fn(array $item): string => (string)($item['platform'] ?? ''),
        array_filter($domainReadiness, static fn(array $item): bool => (string)($item[$field] ?? '') !== 'ready')
    ));
}

function metric_domain_gap_codes(array $domainReadiness): array
{
    $codes = [];
    foreach ($domainReadiness as $item) {
        if (!is_array($item)) {
            continue;
        }
        $platform = strtolower(trim((string)($item['platform'] ?? '')));
        if ($platform === '') {
            continue;
        }
        if ((string)($item['revenue_status'] ?? '') !== 'ready') {
            $codes[$platform . '_revenue_metrics_not_ready'] = true;
        }
        if ((string)($item['traffic_status'] ?? '') !== 'ready') {
            $codes[$platform . '_traffic_facts_missing'] = true;
        }
    }
    return array_values(array_keys($codes));
}

function evidence_missing_codes(array $platformEvidence, array $diagnosis, array $operation): array
{
    $codes = platform_evidence_missing_codes($platformEvidence);
    $diagnosisBlocker = diagnosis_blocking_code($diagnosis);
    if ($diagnosisBlocker !== '') {
        $codes[] = $diagnosisBlocker;
    }
    $operationBlocker = operation_blocking_code($operation);
    if ($operationBlocker !== '') {
        $codes[] = $operationBlocker;
    }
    return array_values(array_unique($codes));
}

function ai_blocking_codes(array $missingCodes): array
{
    return array_values(array_filter($missingCodes, static function (string $code): bool {
        return str_contains($code, 'source_rows_missing')
            || str_contains($code, 'etl_not_ready')
            || str_contains($code, 'revenue_metrics_not_ready')
            || str_contains($code, 'traffic_facts_missing')
            || str_contains($code, 'data_gaps_missing')
            || $code === 'evidence_scope_date_mismatch'
            || $code === 'ai_diagnosis_action_items_blocked';
    }));
}

function next_action_family(string $code): string
{
    if (str_contains($code, 'source_rows_missing')) {
        return 'target_date_source_rows';
    }
    if (str_contains($code, 'etl_not_ready')) {
        return 'standard_facts';
    }
    if (str_contains($code, 'revenue_metrics_not_ready')) {
        return 'revenue_metric_inputs';
    }
    if (str_contains($code, 'traffic_facts_missing')) {
        return 'traffic_conversion_facts';
    }
    if ($code === 'collect_ai_diagnosis_evidence' || $code === 'resolve_ai_diagnosis_blocked_action_items') {
        return 'ai_diagnosis_evidence';
    }
    if ($code === 'collect_operation_execution_evidence') {
        return 'operation_execution_evidence';
    }
    if ($code === 'align_evidence_scope_date') {
        return 'evidence_scope';
    }
    return 'evidence_gap';
}

function next_action_question_key(string $code): string
{
    if (str_contains($code, 'source_rows_missing')) {
        return 'today_ota_collected';
    }
    if (str_contains($code, 'etl_not_ready')
        || str_contains($code, 'revenue_metrics_not_ready')
        || str_contains($code, 'traffic_facts_missing')
    ) {
        return 'revenue_traffic_conversion';
    }
    if ($code === 'collect_ai_diagnosis_evidence' || $code === 'resolve_ai_diagnosis_blocked_action_items') {
        return 'ai_evidence';
    }
    if ($code === 'collect_operation_execution_evidence') {
        return 'next_operation_action';
    }
    if ($code === 'align_evidence_scope_date') {
        return 'ai_evidence';
    }
    return '';
}

function next_action_related_question_keys(string $code): array
{
    $family = next_action_family($code);
    $keys = match ($family) {
        'target_date_source_rows' => ['today_ota_collected', 'trusted_fields', 'revenue_traffic_conversion', 'ai_evidence', 'next_operation_action'],
        'standard_facts' => ['trusted_fields', 'revenue_traffic_conversion', 'ai_evidence', 'next_operation_action'],
        'revenue_metric_inputs' => ['trusted_fields', 'revenue_traffic_conversion', 'ai_evidence', 'next_operation_action'],
        'traffic_conversion_facts' => ['revenue_traffic_conversion', 'ai_evidence', 'next_operation_action'],
        'ai_diagnosis_evidence' => ['ai_evidence', 'next_operation_action'],
        'operation_execution_evidence' => ['next_operation_action'],
        'evidence_scope' => ['ai_evidence', 'next_operation_action'],
        default => [],
    };
    $questionKey = next_action_question_key($code);
    if ($questionKey !== '') {
        array_unshift($keys, $questionKey);
    }
    return array_values(array_unique($keys));
}

function next_action_platform(string $code): string
{
    if (preg_match('/^(ctrip|meituan)_/', $code, $matches)) {
        return $matches[1];
    }
    if (in_array($code, ['collect_ai_diagnosis_evidence', 'resolve_ai_diagnosis_blocked_action_items', 'collect_operation_execution_evidence'], true)) {
        return 'ctrip,meituan';
    }
    return 'ota';
}

function next_action_platform_label(string $code): string
{
    $platform = next_action_platform($code);
    if (str_contains($platform, ',')) {
        return '携程/美团';
    }
    if ($platform === 'ctrip') {
        return '携程';
    }
    if ($platform === 'meituan') {
        return '美团';
    }
    return 'OTA';
}

function next_action_live_closure_gap_codes(string $code): array
{
    return next_action_resolves_missing_codes($code);
}

function next_action_employee_explanation(string $code): array
{
    $family = next_action_family($code);
    $platformLabel = next_action_platform_label($code);

    return match ($family) {
        'target_date_source_rows' => [
            'employee_explanation' => $platformLabel . '目标日没有同日 OTA 源数据行，不能证明今天' . $platformLabel . '数据已采到。',
            'limited_conclusions' => [$platformLabel . '收入', $platformLabel . '流量', $platformLabel . '转化', $platformLabel . '字段可信度', $platformLabel . 'AI 诊断'],
            'still_usable_metrics' => [$platformLabel . '最近可用历史数据只能作参考，不能替代目标日数据。', '其它已采到平台的同日 OTA 指标可按平台单独复核。'],
            'explanation_next_action' => '使用现有' . $platformLabel . '手动或自动获取入口补齐目标日源数据。',
        ],
        'standard_facts' => [
            'employee_explanation' => $platformLabel . '源数据没有形成可读的标准事实层，不能进入统一收益诊断。',
            'limited_conclusions' => [$platformLabel . '标准事实', $platformLabel . '收益指标', $platformLabel . '字段可信判断'],
            'still_usable_metrics' => ['已保存的原始/历史参考状态。', '现有采集日志和数据质量标记。'],
            'explanation_next_action' => '复核现有' . $platformLabel . ' ETL 输入、data_type、raw_data 标准化证据。',
        ],
        'revenue_metric_inputs' => [
            'employee_explanation' => $platformLabel . '收益指标未就绪，不能计算收入、间夜、客单等经营结论。',
            'limited_conclusions' => [$platformLabel . '收益', $platformLabel . 'ADR', $platformLabel . '订单', $platformLabel . '间夜', $platformLabel . '相关 AI 建议'],
            'still_usable_metrics' => ['其它已 ready 平台的收益指标可单独复核。', '缺口本身可作为补证据清单。'],
            'explanation_next_action' => '补齐' . $platformLabel . '目标日源数据和标准事实后复跑收益指标。',
        ],
        'traffic_conversion_facts' => [
            'employee_explanation' => $platformLabel . '目标日缺少流量/转化事实，不能判断曝光、访问、下单链路是否异常。',
            'limited_conclusions' => [$platformLabel . '流量', $platformLabel . '转化率', $platformLabel . '漏斗诊断', 'AI 对流量问题的确定结论'],
            'still_usable_metrics' => [$platformLabel . '已采到且 metric_trust 明确可信的收益事实（如存在）。', '其它平台已就绪的同日指标。'],
            'explanation_next_action' => '使用现有' . $platformLabel . '流量获取入口补齐目标日流量事实，复跑巡检。',
        ],
        'ai_diagnosis_evidence' => [
            'employee_explanation' => 'AI 诊断证据未闭合，不能把当前 action_items 当作可执行经营建议。',
            'limited_conclusions' => ['AI 自动建议', '执行意图创建', '运营闭环完成判断'],
            'still_usable_metrics' => ['阻断原因。', '证据来源。', 'data_gaps 补证据清单。'],
            'explanation_next_action' => '先解除上游 OTA 缺口，再调用现有 OTA 诊断并保留脱敏 evidence_sources、data_gaps、action_items。',
        ],
        'operation_execution_evidence' => [
            'employee_explanation' => '尚无能追溯到 OTA 诊断的执行意图、审批、执行证据或复盘样例。',
            'limited_conclusions' => ['运营执行闭环', '动作完成', '复盘和 ROI 判断'],
            'still_usable_metrics' => ['下一步动作和阻断链可见。', '已验证的 OTA 诊断缺口可继续作为待处理清单。'],
            'explanation_next_action' => '取得可执行 AI action_items 后，创建或附上执行意图和证据。',
        ],
        'evidence_scope' => [
            'employee_explanation' => '外部证据日期与本次巡检目标日不一致，不能证明目标日 OTA 闭环。',
            'limited_conclusions' => ['目标日 AI 诊断', '目标日运营动作', '目标日闭环完成判断'],
            'still_usable_metrics' => ['该证据只能作为历史或非目标日参考。', '本次巡检的同日 OTA 数据和缺口仍可单独查看。'],
            'explanation_next_action' => '重新生成或选择与本次巡检同一业务日期的证据 JSON。',
        ],
        default => [],
    };
}

function next_action_entry(string $code): string
{
    if (str_contains($code, 'source_rows_missing')) {
        if (str_starts_with($code, 'ctrip_')) {
            return '/api/online-data/fetch-ctrip-overview';
        }
        if (str_starts_with($code, 'meituan_')) {
            return '/api/online-data/fetch-meituan';
        }
        return '/api/online-data/collection-reliability';
    }
    if ($code === 'ctrip_traffic_facts_missing_confirm_traffic_collection') {
        return '/api/online-data/fetch-ctrip-traffic';
    }
    if ($code === 'meituan_traffic_facts_missing_confirm_traffic_collection') {
        return '/api/online-data/fetch-meituan-traffic';
    }
    if (str_contains($code, 'etl_not_ready')
        || str_contains($code, 'revenue_metrics_not_ready')
        || str_contains($code, 'traffic_facts_missing')
    ) {
        return '/api/ota-standard/revenue-metrics';
    }
    if ($code === 'collect_ai_diagnosis_evidence' || $code === 'resolve_ai_diagnosis_blocked_action_items') {
        return '/api/agent/ota-diagnosis';
    }
    if ($code === 'collect_operation_execution_evidence') {
        return '/api/operation/execution-intents';
    }
    if ($code === 'align_evidence_scope_date') {
        return 'scripts/inspect_phase1_ota_live_closure.php --evidence=<same-date-json>';
    }
    return '/api/online-data/collection-reliability';
}

function profile_directory_count(string $platform): int
{
    $platform = strtolower(trim($platform));
    if (!in_array($platform, ['ctrip', 'meituan'], true)) {
        return 0;
    }
    $dirs = glob(__DIR__ . '/../storage/' . $platform . '_profile_*', GLOB_ONLYDIR);
    return is_array($dirs) ? count($dirs) : 0;
}

function entry_option_readiness(string $platform, string $mode): array
{
    $platform = strtolower(trim($platform));
    $mode = strtolower(trim($mode));
    if ($mode === 'status_check') {
        return [
            'status' => 'ready',
            'label' => '可直接只读核对',
            'can_run_now' => true,
            'reason' => '只读取 collection-reliability 和 online_daily_data 状态，不写 OTA 数据。',
            'evidence' => 'read_existing_collection_reliability_only',
        ];
    }
    if ($mode === 'browser_profile') {
        $profileCount = profile_directory_count($platform);
        return [
            'status' => $profileCount > 0 ? 'profile_found_login_unverified' : 'profile_missing',
            'label' => $profileCount > 0 ? '发现 Profile，登录态需复核' : '未发现本机 Profile',
            'can_run_now' => false,
            'reason' => $profileCount > 0
                ? '本机存在 Profile 目录，但仍需人工确认平台账号登录态有效。'
                : '未发现对应平台 Profile 目录，需先按现有自动采集流程完成授权登录。',
            'evidence' => 'storage_profile_directory_count',
            'profile_count' => $profileCount,
            'source_policy' => 'read_local_profile_directory_names_only',
        ];
    }
    return [
        'status' => 'requires_user_context',
        'label' => '需提供授权上下文',
        'can_run_now' => false,
        'reason' => '需要用户提供 Cookie/Payload/门店标识等授权上下文后才能调用现有手动入口。',
        'evidence' => 'user_supplied_cookie_or_payload_required',
    ];
}

function entry_options_with_readiness(string $platform, array $options): array
{
    return array_values(array_map(static function (array $option) use ($platform): array {
        $option['readiness'] = entry_option_readiness($platform, (string)($option['mode'] ?? ''));
        return $option;
    }, $options));
}

function next_action_entry_options(string $code): array
{
    if (str_contains($code, 'traffic_facts_missing')) {
        if (str_starts_with($code, 'ctrip_')) {
            return entry_options_with_readiness('ctrip', [
                [
                    'mode' => 'manual_cookie_api',
                    'label' => '手动流量 Cookie/API',
                    'entry' => '/api/online-data/fetch-ctrip-traffic',
                    'use_when' => '已取得携程流量接口 Cookie、URL、spiderkey 或必要参数，需要临时补齐目标日流量事实。',
                    'requires' => '用户提供授权上下文、流量接口参数和目标日期。',
                    'boundary' => '不自动登录携程后台，不改变流量采集字段或字段映射。',
                ],
                [
                    'mode' => 'browser_profile',
                    'label' => '浏览器 Profile',
                    'entry' => '/api/online-data/capture-ctrip-browser',
                    'use_when' => '门店携程浏览器 Profile 已登录授权，需要走现有自动采集路径补齐流量事实。',
                    'requires' => '本地 Profile 存在且携程账号登录态有效。',
                    'boundary' => '不绕过验证码、短信或人机验证，不改变自动采集逻辑。',
                ],
                [
                    'mode' => 'status_check',
                    'label' => '状态核对',
                    'entry' => '/api/online-data/collection-reliability',
                    'use_when' => '只核对目标日流量事实是否已有入库行、最近可用日期和失败原因。',
                    'requires' => '读取现有采集可靠性和 online_daily_data 状态。',
                    'boundary' => '只读状态，不写 OTA 数据，不改变字段映射。',
                ],
            ]);
        }
        if (str_starts_with($code, 'meituan_')) {
            return entry_options_with_readiness('meituan', [
                [
                    'mode' => 'manual_cookie_api',
                    'label' => '手动流量 Cookie/API',
                    'entry' => '/api/online-data/fetch-meituan-traffic',
                    'use_when' => '已取得美团流量接口 Cookie、Partner ID、POI ID 或必要参数，需要临时补齐目标日流量事实。',
                    'requires' => '用户提供授权上下文、门店/POI 标识、流量接口参数和目标日期。',
                    'boundary' => '不代登录美团后台，不改变流量采集字段或字段映射。',
                ],
                [
                    'mode' => 'browser_profile',
                    'label' => '浏览器 Profile',
                    'entry' => '/api/online-data/capture-meituan-browser',
                    'use_when' => '门店美团浏览器 Profile 已登录授权，需要走现有自动采集路径补齐流量事实。',
                    'requires' => '本地 Profile 存在且美团账号登录态有效。',
                    'boundary' => '不绕过验证码、短信或人机验证，不改变自动采集逻辑。',
                ],
                [
                    'mode' => 'status_check',
                    'label' => '状态核对',
                    'entry' => '/api/online-data/collection-reliability',
                    'use_when' => '只核对目标日流量事实是否已有入库行、最近可用日期和失败原因。',
                    'requires' => '读取现有采集可靠性和 online_daily_data 状态。',
                    'boundary' => '只读状态，不写 OTA 数据，不改变字段映射。',
                ],
            ]);
        }
    }
    if (in_array($code, ['collect_ai_diagnosis_evidence', 'resolve_ai_diagnosis_blocked_action_items', 'collect_operation_execution_evidence'], true)) {
        $primaryEntry = $code === 'collect_operation_execution_evidence'
            ? '/api/operation/execution-intents'
            : '/api/agent/ota-diagnosis';
        return entry_options_with_readiness('ctrip', [
            [
                'mode' => 'manual_cookie_api',
                'label' => 'Evidence/API',
                'entry' => $primaryEntry,
                'use_when' => 'Use existing API evidence after target-date OTA facts are present.',
                'requires' => 'Target-date OTA facts, diagnosis/action evidence, and authorized user context.',
                'boundary' => 'Does not change Ctrip/Meituan acquisition logic, fields, or mappings.',
            ],
            [
                'mode' => 'browser_profile',
                'label' => 'Profile evidence refresh',
                'entry' => '/api/online-data/capture-ctrip-browser',
                'use_when' => 'Use only when an authorized browser Profile must refresh target-date OTA evidence.',
                'requires' => 'Local Profile exists and platform login state is manually verified.',
                'boundary' => 'Does not bypass captcha, SMS, human verification, or platform permissions.',
            ],
            [
                'mode' => 'status_check',
                'label' => 'Read-only status check',
                'entry' => '/api/online-data/collection-reliability',
                'use_when' => 'Verify target-date rows, latest available date, and explicit missing reasons.',
                'requires' => 'Read existing collection reliability and online_daily_data state.',
                'boundary' => 'Read-only; does not write OTA data or alter field mappings.',
            ],
        ]);
    }
    if (!str_contains($code, 'source_rows_missing')) {
        return [];
    }
    if (str_starts_with($code, 'ctrip_')) {
        return entry_options_with_readiness('ctrip', [
            [
                'mode' => 'manual_cookie_api',
                'label' => '手动 Cookie/API',
                'entry' => '/api/online-data/fetch-ctrip-overview',
                'use_when' => '已取得携程 Cookie、Payload 或必要参数，需要临时补齐目标日经营概况。',
                'requires' => '用户提供授权上下文、平台酒店标识和目标日期。',
                'boundary' => '不自动登录携程后台，不启动浏览器 Profile，不改变采集字段。',
            ],
            [
                'mode' => 'browser_profile',
                'label' => '浏览器 Profile',
                'entry' => '/api/online-data/capture-ctrip-browser',
                'use_when' => '门店携程浏览器 Profile 已登录授权，需要走现有自动采集路径。',
                'requires' => '本地 Profile 存在且携程账号登录态有效。',
                'boundary' => '不绕过验证码、短信或人机验证，不改变自动采集逻辑。',
            ],
            [
                'mode' => 'status_check',
                'label' => '状态核对',
                'entry' => '/api/online-data/collection-reliability',
                'use_when' => '只核对目标日是否已有入库行、最近可用日期和失败原因。',
                'requires' => '读取现有采集可靠性和 online_daily_data 状态。',
                'boundary' => '只读状态，不写 OTA 数据，不改变字段映射。',
            ],
        ]);
    }
    if (str_starts_with($code, 'meituan_')) {
        return entry_options_with_readiness('meituan', [
            [
                'mode' => 'manual_cookie_api',
                'label' => '手动 Cookie/API',
                'entry' => '/api/online-data/fetch-meituan',
                'use_when' => '已取得美团 Cookie、Session、POI 或必要 Payload，需要临时补齐目标日数据。',
                'requires' => '用户提供授权上下文、门店/POI 标识和目标日期。',
                'boundary' => '不代登录美团后台，不启动浏览器 Profile，不改变采集字段。',
            ],
            [
                'mode' => 'browser_profile',
                'label' => '浏览器 Profile',
                'entry' => '/api/online-data/capture-meituan-browser',
                'use_when' => '门店美团浏览器 Profile 已登录授权，需要走现有自动采集路径。',
                'requires' => '本地 Profile 存在且美团账号登录态有效。',
                'boundary' => '不绕过验证码、短信或人机验证，不改变自动采集逻辑。',
            ],
            [
                'mode' => 'status_check',
                'label' => '状态核对',
                'entry' => '/api/online-data/collection-reliability',
                'use_when' => '只核对目标日是否已有入库行、最近可用日期和失败原因。',
                'requires' => '读取现有采集可靠性和 online_daily_data 状态。',
                'boundary' => '只读状态，不写 OTA 数据，不改变字段映射。',
            ],
        ]);
    }
    return [];
}

function next_action_success_criteria(string $code): string
{
    if (str_contains($code, 'source_rows_missing')) {
        return 'source_date_evidence.platforms 中对应平台 target_date_rows > 0；latest_available 仅作最近可用参考，不能替代或否定目标日行数。';
    }
    if (str_contains($code, 'etl_not_ready')) {
        return '同范围 OTA 标准事实层出现 accepted_rows 或 ETL status=ready，并保留 validation_flags 与 data_type 分布。';
    }
    if (str_contains($code, 'revenue_metrics_not_ready')) {
        return '对应平台 revenue_status=ready，且 revenue_metrics 输出 metric_trust 与 data_gaps。';
    }
    if (str_contains($code, 'traffic_facts_missing')) {
        return '对应平台 traffic_status=ready；未采到时必须保留 data_gaps，不用收益行推断流量或转化。';
    }
    if ($code === 'collect_ai_diagnosis_evidence') {
        return 'OTA 诊断响应包含 evidence_sources、data_gaps 和至少一个非 blocked action_items。';
    }
    if ($code === 'resolve_ai_diagnosis_blocked_action_items') {
        return 'action_items 不再全部为 blocked，且 blocked_by 上游缺口已清空或显式转为待补证。';
    }
    if ($code === 'collect_operation_execution_evidence') {
        return '执行意图或执行流程可追溯到 OTA diagnosis action_items，并出现审批通过、执行证据、复盘或 ROI 任一完成信号。';
    }
    if ($code === 'align_evidence_scope_date') {
        return '证据 JSON 的 scope.date 与本次巡检目标日期一致。';
    }
    return '补齐所需证据后重新运行第一阶段真实闭环巡检，相关员工六问不再处于缺失状态。';
}

function next_action_resolves_missing_codes(string $code): array
{
    if (preg_match('/^(ctrip|meituan)_source_rows_missing_collect_existing_path$/', $code, $matches)) {
        return [$matches[1] . '_source_rows_missing'];
    }
    if (preg_match('/^(ctrip|meituan)_etl_not_ready_check_standard_facts$/', $code, $matches)) {
        return [$matches[1] . '_etl_not_ready'];
    }
    if (preg_match('/^(ctrip|meituan)_revenue_metrics_not_ready_check_metric_inputs$/', $code, $matches)) {
        return [$matches[1] . '_revenue_metrics_not_ready'];
    }
    if (preg_match('/^(ctrip|meituan)_traffic_facts_missing_confirm_traffic_collection$/', $code, $matches)) {
        return [$matches[1] . '_traffic_facts_missing'];
    }
    if ($code === 'collect_ai_diagnosis_evidence') {
        return ['ai_diagnosis_evidence_sample_missing'];
    }
    if ($code === 'resolve_ai_diagnosis_blocked_action_items') {
        return ['ai_diagnosis_action_items_blocked'];
    }
    if ($code === 'collect_operation_execution_evidence') {
        return ['operation_execution_sample_missing', 'operation_execution_ai_action_link_missing', 'operation_execution_evidence_incomplete'];
    }
    if ($code === 'align_evidence_scope_date') {
        return ['evidence_scope_date_mismatch'];
    }
    return [];
}

function next_action_for_blocker_code(string $code): string
{
    if (preg_match('/^(ctrip|meituan)_source_rows_missing$/', $code, $matches)) {
        return $matches[1] . '_source_rows_missing_collect_existing_path';
    }
    if (preg_match('/^(ctrip|meituan)_etl_not_ready$/', $code, $matches)) {
        return $matches[1] . '_etl_not_ready_check_standard_facts';
    }
    if (preg_match('/^(ctrip|meituan)_revenue_metrics_not_ready$/', $code, $matches)) {
        return $matches[1] . '_revenue_metrics_not_ready_check_metric_inputs';
    }
    if (preg_match('/^(ctrip|meituan)_traffic_facts_missing$/', $code, $matches)) {
        return $matches[1] . '_traffic_facts_missing_confirm_traffic_collection';
    }
    if ($code === 'ai_diagnosis_evidence_sample_missing'
        || $code === 'ai_evidence_sources_missing'
        || $code === 'ai_data_gaps_missing'
        || $code === 'ai_action_items_missing'
    ) {
        return 'collect_ai_diagnosis_evidence';
    }
    if ($code === 'ai_diagnosis_action_items_blocked' || $code === 'ai_action_items_blocked') {
        return 'resolve_ai_diagnosis_blocked_action_items';
    }
    if ($code === 'operation_execution_sample_missing'
        || $code === 'operation_execution_ai_action_link_missing'
        || $code === 'operation_execution_evidence_incomplete'
    ) {
        return 'collect_operation_execution_evidence';
    }
    if ($code === 'evidence_scope_date_mismatch') {
        return 'align_evidence_scope_date';
    }
    return '';
}

function next_action_blocked_by_action_codes(array $blockedBy, string $currentActionCode = ''): array
{
    $actions = [];
    foreach ($blockedBy as $blocker) {
        $actionCode = next_action_for_blocker_code((string)$blocker);
        if ($actionCode !== '' && $actionCode !== $currentActionCode) {
            $actions[] = $actionCode;
        }
    }
    return array_values(array_unique($actions));
}

function next_action_row(string $code, string $priority, string $status, string $owner, string $action, array $evidenceNeeded, string $protectedBoundary, array $blockedBy = []): array
{
    $blockedBy = array_values(array_filter(array_map('strval', $blockedBy)));
    $explanation = next_action_employee_explanation($code);

    $row = [
        'action_code' => $code,
        'platform' => next_action_platform($code),
        'action_family' => next_action_family($code),
        'question_key' => next_action_question_key($code),
        'related_question_keys' => next_action_related_question_keys($code),
        'entry' => next_action_entry($code),
        'success_criteria' => next_action_success_criteria($code),
        'resolves_missing_codes' => next_action_resolves_missing_codes($code),
        'live_closure_gap_codes' => next_action_live_closure_gap_codes($code),
        'blocked_by_action_codes' => next_action_blocked_by_action_codes($blockedBy, $code),
        'priority' => $priority,
        'status' => $status,
        'owner' => $owner,
        'action' => $action,
        'evidence_needed' => array_values($evidenceNeeded),
        'blocked_by' => $blockedBy,
        'protected_boundary' => $protectedBoundary,
    ];
    $entryOptions = next_action_entry_options($code);
    if ($entryOptions !== []) {
        $row['entry_options'] = $entryOptions;
    }
    if ($explanation !== []) {
        $row['employee_explanation'] = (string)($explanation['employee_explanation'] ?? '');
        $row['limited_conclusions'] = array_values(array_filter(
            array_map('strval', (array)($explanation['limited_conclusions'] ?? [])),
            static fn(string $value): bool => $value !== ''
        ));
        $row['still_usable_metrics'] = array_values(array_filter(
            array_map('strval', (array)($explanation['still_usable_metrics'] ?? [])),
            static fn(string $value): bool => $value !== ''
        ));
        $row['explanation_next_action'] = (string)($explanation['explanation_next_action'] ?? '');
    }
    $row['employee_action'] = evidence_employee_readable_copy((string)($row['employee_action'] ?? $row['action'] ?? ''));
    $row['employee_evidence_needed'] = array_values(array_filter(array_map(
        static fn($value): string => evidence_employee_readable_copy((string)$value),
        (array)($row['employee_evidence_needed'] ?? $row['evidence_needed'] ?? [])
    ), static fn(string $value): bool => $value !== ''));
    $row['employee_success_criteria'] = evidence_employee_readable_copy((string)($row['employee_success_criteria'] ?? $row['success_criteria'] ?? ''));
    $row['employee_explanation_next_action'] = evidence_employee_readable_copy((string)($row['employee_explanation_next_action'] ?? $row['explanation_next_action'] ?? ''));
    $row['employee_verification_steps'] = array_values(array_filter(array_map(
        static fn($value): string => evidence_employee_readable_copy((string)$value),
        (array)($row['employee_verification_steps'] ?? next_action_employee_verification_steps($row))
    ), static fn(string $value): bool => $value !== ''));
    if ((string)($row['action_code'] ?? '') === 'resolve_ai_diagnosis_blocked_action_items') {
        $row['employee_action'] = 'AI 动作项被上游缺口阻断；先处理上游 OTA 缺口后重新生成非阻断动作项。';
    }
    return $row;
}

/**
 * @param array<string, mixed> $action
 * @return array<int, string>
 */
function next_action_employee_verification_steps(array $action): array
{
    $code = (string)($action['action_code'] ?? '');
    $family = (string)($action['action_family'] ?? next_action_family($code));
    $platformLabel = next_action_platform_label($code);

    return match ($family) {
        'target_date_source_rows' => [
            '刷新数据健康页的员工六问闭环。',
            '确认' . $platformLabel . '目标日入库行数大于 0。',
            '确认相关未完成问题和巡检缺口减少。',
        ],
        'standard_facts' => [
            '刷新员工六问里的收入、流量和转化问题。',
            '确认' . $platformLabel . '标准事实层变为可复核。',
            '确认字段可信或收益指标不再被该项阻断。',
        ],
        'revenue_metric_inputs' => [
            '刷新收入、流量和转化问题。',
            '确认' . $platformLabel . '收益输入变为可复核。',
            '确认 AI 依据不再因为该收益缺口被阻断。',
        ],
        'traffic_conversion_facts' => [
            '刷新收入、流量和转化问题。',
            '确认' . $platformLabel . '流量/转化事实变为可复核。',
            '确认漏斗判断不再显示该平台流量/转化缺口。',
        ],
        'ai_diagnosis_evidence' => [
            '重新运行现有 OTA 诊断。',
            '确认 AI 动作项不再被上游缺口阻断。',
            '确认 AI 依据仍保留证据来源和数据缺口说明。',
        ],
        'operation_execution_evidence' => [
            '刷新运营执行闭环摘要。',
            '确认执行意图能追溯到 OTA 诊断动作。',
            '确认出现审批、执行证据、复盘或 ROI 信号。',
        ],
        'evidence_scope' => [
            '刷新员工六问闭环。',
            '确认目标日期和证据日期一致。',
            '确认最近可用数据没有替代目标日证明。',
        ],
        default => [
            '刷新员工六问闭环。',
            '确认该动作对应缺口从未证明变为可复核。',
        ],
    };
}

function build_next_actions(array $platformEvidence, array $diagnosis, array $operation, array $options): array
{
    $actions = [];
    $missingCodes = evidence_missing_codes($platformEvidence, $diagnosis, $operation);
    $aiBlockers = ai_blocking_codes($missingCodes);
    $aiPrerequisiteBlockers = array_values(array_filter(
        $aiBlockers,
        static fn(string $code): bool => $code !== 'ai_diagnosis_evidence_sample_missing'
    ));
    foreach ($platformEvidence as $item) {
        $platform = (string)($item['platform'] ?? '');
        if ($platform === '') {
            continue;
        }
        $label = strtoupper($platform);
        if ((int)($item['source_rows']['count'] ?? 0) === 0) {
            $actions[] = next_action_row(
                $platform . '_source_rows_missing_collect_existing_path',
                'high',
                'missing',
                '酒店运营人员',
                '使用现有' . $label . '手动或自动获取入口补齐 ' . (string)$options['date'] . ' 的 OTA 数据，然后重新运行真实闭环巡检。',
                ['online_daily_data 同日期源数据行', 'data_source_id 或 sync_task_id', 'source_trace_id 或 raw_data 追踪证据'],
                '不改变采集字段、字段映射、携程/美团手动或自动获取逻辑。'
            );
        }
        if ((string)($item['etl']['status'] ?? '') !== 'ready') {
            $actions[] = next_action_row(
                $platform . '_etl_not_ready_check_standard_facts',
                'medium',
                'missing',
                '产品/技术',
                $label . ' 源数据行存在后，检查同范围 OTA 标准事实层为什么仍然为空。',
                ['accepted_rows', 'rejected_rows', 'validation_flags', 'data_type 分布'],
                '保持源采集不变，只检查下游标准化证据。'
            );
        }
        if ((string)($item['revenue_metrics']['status'] ?? '') !== 'ready') {
            $actions[] = next_action_row(
                $platform . '_revenue_metrics_not_ready_check_metric_inputs',
                'medium',
                'missing',
                '收益运营人员',
                '在输出经营结论前，确认 ' . $label . ' 同日标准事实是否包含最小收益指标输入。',
                ['amount', 'quantity 或 room_nights', 'book_order_num 或 order_count', 'metric_trust', 'data_gaps'],
                '不使用 0 或伪成功值填补缺失指标。'
            );
        }
        if ((int)($item['revenue_metrics']['traffic']['rows'] ?? 0) === 0) {
            $actions[] = next_action_row(
                $platform . '_traffic_facts_missing_confirm_traffic_collection',
                'high',
                'missing',
                'OTA 运营人员',
                '确认 ' . $label . ' 同日流量数据是否已采到；未采到时，流量/转化诊断必须标记为不可用。',
                ['list_exposure', 'detail_exposure', 'flow_rate', 'order_filling_num 或 order_submit_num'],
                '不从只有收益的数据行推断流量或转化问题。'
            );
        }
    }

    if (in_array('evidence_scope_date_mismatch', $missingCodes, true)) {
        $actions[] = next_action_row(
            'align_evidence_scope_date',
            'high',
            'missing',
            '产品/技术',
            '重新生成或选择 scope.date 与本次巡检日期一致的 AI 诊断/运营执行证据 JSON。',
            ['scope.date', '巡检日期 ' . (string)$options['date'], 'metric_scope=ota_channel'],
            '不复用过期或错日期证据证明当天 OTA 闭环。'
        );
    }

    if (!diagnosis_content_present($diagnosis)) {
        $actions[] = next_action_row(
            'collect_ai_diagnosis_evidence',
            'high',
            $aiPrerequisiteBlockers === [] ? 'missing' : 'blocked',
            'AI 运营人员',
            '调用现有 OTA 诊断接口，并为本次巡检范围附上脱敏证据 JSON。',
            ['evidence_sources', 'data_gaps', 'action_items'],
            'AI 建议必须引用 OTA 证据，不能把缺失数据写成确定结论。',
            $aiPrerequisiteBlockers
        );
    } elseif (evidence_scope_date_aligned($diagnosis) && !has_actionable_diagnosis_evidence($diagnosis)) {
        $actions[] = next_action_row(
            'resolve_ai_diagnosis_blocked_action_items',
            'high',
            'blocked',
            'AI 运营人员',
            'AI 诊断已返回证据，但 action_items 仍被阻断；先处理上游 OTA 缺口后重新生成可进入执行意图的动作项。',
            ['非阻断 action_items', 'evidence_sources', 'data_gaps'],
            'AI 诊断可以暴露阻断依据，但不能把阻断 action_items 当成可执行经营建议。',
            $aiBlockers !== [] ? $aiBlockers : ['ai_diagnosis_action_items_blocked']
        );
    }
    $operationStatus = operation_evidence_status($operation);
    $operationBlockingCode = operation_blocking_code($operation);
    if ($operationStatus !== 'proved') {
        $diagnosisBlocker = diagnosis_blocking_code($diagnosis);
        $blockedBy = array_values(array_unique(array_merge(
            $aiBlockers,
            has_actionable_diagnosis_evidence($diagnosis)
                ? []
                : array_filter([$diagnosisBlocker])
        )));
        $actions[] = next_action_row(
            'collect_operation_execution_evidence',
            'medium',
            $blockedBy === [] ? 'missing' : 'blocked',
            '运营负责人',
            match ($operationBlockingCode) {
                'operation_execution_ai_action_link_missing' => '已有执行意图/执行流程数据，但还不能追溯到 OTA 诊断 action_items；补齐 source、evidence_refs 或 action_item_id 关联。',
                'operation_execution_evidence_incomplete' => '已有执行意图/执行流程样例，但还不能证明动作可进入运营闭环；补齐审批通过、执行证据或复盘状态。',
                default => '创建或附上一个真实执行意图/执行流程样例，并关联到 OTA 诊断动作项。',
            },
            $operationBlockingCode === 'operation_execution_ai_action_link_missing'
                ? ['source_module=ota_diagnosis 或 source=ota_diagnosis#action_item', 'evidence_refs 或 action_item_id', 'approval.status=approved、execution.status=executed、evidence.count>0 或 review.status']
                : ['execution_intents 或 execution_flow', 'approval.status=approved', 'execution.status=executed 或 evidence.count>0', 'review.status 或 ROI 复盘状态'],
            $operationBlockingCode === 'operation_execution_ai_action_link_missing'
                ? '只补齐执行证据关联，不改携程/美团采集字段和采集逻辑。'
                : '动作可以处于待审批状态；不能只凭 AI 建议卡片标记闭环完成。',
            $blockedBy
        );
    }

    return sort_next_actions($actions);
}

function sort_next_actions(array $actions): array
{
    $statusRank = ['missing' => 0, 'blocked' => 1];
    $priorityRank = ['high' => 0, 'medium' => 1, 'low' => 2];
    usort($actions, static function (array $a, array $b) use ($statusRank, $priorityRank): int {
        $aStatus = $statusRank[(string)($a['status'] ?? '')] ?? 9;
        $bStatus = $statusRank[(string)($b['status'] ?? '')] ?? 9;
        if ($aStatus !== $bStatus) {
            return $aStatus <=> $bStatus;
        }
        $aFamily = next_action_family_rank($a);
        $bFamily = next_action_family_rank($b);
        if ($aFamily !== $bFamily) {
            return $aFamily <=> $bFamily;
        }
        $aPriority = $priorityRank[(string)($a['priority'] ?? '')] ?? 9;
        $bPriority = $priorityRank[(string)($b['priority'] ?? '')] ?? 9;
        if ($aPriority !== $bPriority) {
            return $aPriority <=> $bPriority;
        }
        return strcmp((string)($a['action_code'] ?? ''), (string)($b['action_code'] ?? ''));
    });
    return array_values($actions);
}

function next_action_family_rank(array $action): int
{
    $family = (string)($action['action_family'] ?? '');
    if ($family === '') {
        $family = next_action_family((string)($action['action_code'] ?? ''));
    }

    return match ($family) {
        'evidence_scope' => 0,
        'target_date_source_rows' => 1,
        'standard_facts' => 2,
        'revenue_metric_inputs' => 3,
        'traffic_conversion_facts' => 4,
        'ai_diagnosis_evidence' => 5,
        'operation_execution_evidence' => 6,
        default => 9,
    };
}

function with_employee_question_action_codes(array $questions, array $actions): array
{
    $actionsByQuestion = [];
    foreach ($actions as $action) {
        if (!is_array($action)) {
            continue;
        }
        $actionCode = (string)($action['action_code'] ?? '');
        if ($actionCode === '') {
            continue;
        }
        $directQuestionKey = (string)($action['question_key'] ?? '');
        $questionKeys = array_values(array_unique(array_filter(array_map(
            'strval',
            array_merge([$directQuestionKey], (array)($action['related_question_keys'] ?? []))
        ))));
        foreach ($questionKeys as $questionKey) {
            if (!isset($actionsByQuestion[$questionKey])) {
                $actionsByQuestion[$questionKey] = [
                    'codes' => [],
                    'primary_action' => null,
                    'direct_action' => null,
                    'blocked_action_codes' => [],
                ];
            }
            $actionsByQuestion[$questionKey]['codes'][] = $actionCode;
            if (!is_array($actionsByQuestion[$questionKey]['primary_action'])) {
                $actionsByQuestion[$questionKey]['primary_action'] = $action;
            }
            if ($directQuestionKey === $questionKey && !is_array($actionsByQuestion[$questionKey]['direct_action'])) {
                $actionsByQuestion[$questionKey]['direct_action'] = $action;
            }
            if ((string)($action['status'] ?? '') === 'blocked') {
                $actionsByQuestion[$questionKey]['blocked_action_codes'][] = $actionCode;
            }
        }
    }

    return array_values(array_map(static function (array $question) use ($actionsByQuestion): array {
        $key = (string)($question['key'] ?? '');
        $summary = is_array($actionsByQuestion[$key] ?? null) ? $actionsByQuestion[$key] : [];
        $actionCodes = array_values(array_unique((array)($summary['codes'] ?? [])));
        $question['next_action_codes'] = $actionCodes;
        $evidence = is_array($question['evidence'] ?? null) ? $question['evidence'] : [];
        $evidence['linked_action_count'] = count($actionCodes);
        $directAction = is_array($summary['direct_action'] ?? null)
            ? $summary['direct_action']
            : ($summary['primary_action'] ?? null);
        foreach ([
            'primary_next_action' => $summary['primary_action'] ?? null,
            'direct_next_action' => $directAction,
        ] as $prefix => $action) {
            if (!is_array($action)) {
                continue;
            }
            $fields = [
                $prefix . '_code' => (string)($action['action_code'] ?? ''),
                $prefix . '_family' => (string)($action['action_family'] ?? ''),
                $prefix . '_entry' => (string)($action['entry'] ?? ''),
                $prefix . '_success_criteria' => (string)($action['success_criteria'] ?? ''),
                $prefix . '_status' => (string)($action['status'] ?? ''),
            ];
            foreach ($fields as $field => $value) {
                if ($value === '') {
                    continue;
                }
                $question[$field] = $value;
                $evidence[$field] = $value;
            }
            $entryOptions = array_values(array_filter(
                (array)($action['entry_options'] ?? []),
                static fn($option): bool => is_array($option) && (string)($option['entry'] ?? '') !== ''
            ));
            if ($entryOptions !== []) {
                $question[$prefix . '_entry_options'] = $entryOptions;
                $evidence[$prefix . '_entry_options'] = $entryOptions;
            }
            foreach ([
                $prefix . '_related_question_keys' => $action['related_question_keys'] ?? [],
                $prefix . '_resolves_missing_codes' => $action['resolves_missing_codes'] ?? [],
                $prefix . '_live_closure_gap_codes' => $action['live_closure_gap_codes'] ?? [],
            ] as $field => $values) {
                $normalizedValues = array_values(array_unique(array_filter(array_map('strval', (array)$values))));
                if ($normalizedValues === []) {
                    continue;
                }
                $question[$field] = $normalizedValues;
                $evidence[$field] = $normalizedValues;
            }
        }
        $blockedActionCodes = array_values(array_unique((array)($summary['blocked_action_codes'] ?? [])));
        if ($blockedActionCodes !== []) {
            $question['blocked_action_codes'] = $blockedActionCodes;
            $evidence['blocked_action_codes'] = $blockedActionCodes;
        }
        $blockingGapCodes = [];
        foreach ([
            $evidence['blocking_missing_codes'] ?? [],
            $evidence['operation_blocking_missing_codes'] ?? [],
            $evidence['metric_domain_gap_codes'] ?? [],
            $evidence['direct_next_action_resolves_missing_codes'] ?? [],
            $evidence['primary_next_action_resolves_missing_codes'] ?? [],
        ] as $values) {
            foreach ((array)$values as $value) {
                $code = trim((string)$value);
                if ($code !== '') {
                    $blockingGapCodes[] = $code;
                }
            }
        }
        $blockingGapCodes = array_values(array_unique($blockingGapCodes));
        if (!in_array((string)($question['status'] ?? ''), ['proved', 'no_gap_reported'], true) && $blockingGapCodes !== []) {
            $question['blocking_gap_codes'] = $blockingGapCodes;
            $evidence['blocking_gap_codes'] = $blockingGapCodes;
        }
        $rawEmployeeDetail = (string)($question['employee_detail'] ?? $question['detail'] ?? '');
        $question['employee_detail'] = evidence_employee_readable_copy($rawEmployeeDetail !== ''
            ? $rawEmployeeDetail
            : evidence_employee_question_detail($question));
        $question['employee_next_action'] = evidence_employee_readable_copy((string)($question['employee_next_action'] ?? $question['next_action'] ?? ''));
        $question['evidence'] = $evidence;
        return $question;
    }, $questions));
}

function evidence_employee_readable_copy(string $text): string
{
    if ($text === '') {
        return '';
    }

    return strtr($text, [
        'CTRIP' => '携程',
        'MEITUAN' => '美团',
        'ctrip_source_rows_missing' => '携程目标日源数据缺失',
        'meituan_source_rows_missing' => '美团目标日源数据缺失',
        'ctrip_target_date_source_rows_missing' => '携程目标日源数据缺失',
        'meituan_target_date_source_rows_missing' => '美团目标日源数据缺失',
        'ctrip_etl_not_ready' => '携程标准事实层未就绪',
        'meituan_etl_not_ready' => '美团标准事实层未就绪',
        'ctrip_revenue_metrics_not_ready' => '携程收益指标未就绪',
        'meituan_revenue_metrics_not_ready' => '美团收益指标未就绪',
        'ctrip_traffic_facts_missing' => '携程流量/转化事实缺失',
        'meituan_traffic_facts_missing' => '美团流量/转化事实缺失',
        'ai_diagnosis_evidence_sample_missing' => 'AI 诊断证据样例缺失',
        'ai_diagnosis_action_items_blocked' => 'AI 动作项被上游缺口阻断',
        'ai_action_items_blocked' => 'AI 动作项被上游缺口阻断',
        'ai_action_items_missing' => 'AI 动作项缺失',
        'operation_execution_sample_missing' => '运营执行样例缺失',
        'operation_execution_ai_action_link_missing' => '运营执行未关联 OTA 诊断动作',
        'operation_execution_evidence_incomplete' => '运营执行证据不完整',
        '按 metric_trust 判断' => '按指标可信证据判断',
        '按 data_gaps 处理' => '按数据缺口处理',
        '按 数据缺口 处理' => '按数据缺口处理',
        '非 blocked action_items' => '非阻断动作项',
        'non blocked action_items' => '非阻断动作项',
        '非阻断 action_items' => '非阻断动作项',
        '非阻断 动作项' => '非阻断动作项',
        '非 blocked 动作项' => '非阻断动作项',
        'blocked action_items' => '阻断动作项',
        'blocked 动作项' => '阻断动作项',
        '为 blocked' => '为阻断',
        'blocked_by 上游缺口' => '上游阻断缺口',
        'blocked_by' => '上游阻断',
        'OTA 诊断 action_items' => 'OTA 诊断动作项',
        'AI action_items' => 'AI 动作项',
        'collection-reliability.source_date_evidence' => '采集可靠性里的目标日来源证据',
        'source_date_evidence' => '目标日来源证据',
        'evidence_sources/data_gaps/action_items' => '证据来源、数据缺口和动作项',
        'evidence_sources、data_gaps、action_items' => '证据来源、数据缺口、动作项',
        'evidence_sources、data_gaps 和 action_items' => '证据来源、数据缺口和动作项',
        'evidence_sources' => '证据来源',
        'evidence_refs' => '证据引用',
        'source_module=ota_diagnosis' => '来源模块=OTA 诊断',
        'source=ota_diagnosis#action_item' => '来源=OTA 诊断动作项',
        'latest_available' => '最近可用数据',
        'ETL status=ready' => '标准化状态已就绪',
        'revenue_status=ready' => '收益状态已就绪',
        'traffic_status=ready' => '流量状态已就绪',
        'conversion_status=ready' => '转化状态已就绪',
        'status=ready' => '状态已就绪',
        'OTA diagnosis' => 'OTA 诊断',
        'source_trace_id' => '来源追踪标识',
        'sync_task_id' => '同步任务标识',
        'data_source_id' => '数据来源标识',
        'data_gaps' => '数据缺口',
        'action_items' => '动作项',
        'action_item_id' => '动作项标识',
        'approval.status=approved' => '审批已通过',
        'execution.status=executed' => '执行已完成',
        'evidence.count>0' => '已有执行证据',
        'review.status' => '复盘状态',
        'execution_intents' => '执行意图',
        'execution_flow' => '执行流程',
        'metric_trust' => '指标可信证据',
        'raw_data' => '脱敏原始响应追踪',
        'data_type' => '数据类型',
        'accepted_rows' => '已接收行数',
        'rejected_rows' => '已拒绝行数',
        'validation_flags' => '校验标记',
        'online_daily_data' => 'OTA 日数据表',
        'source_date_evidence.platforms' => '目标日来源证据平台列表',
        'target_date_rows' => '目标日入库行数',
        'target_date_data_types' => '目标日数据类型',
        'revenue_metrics' => '收益指标',
        'amount' => '收入金额',
        'quantity' => '间夜数量',
        'room_nights' => '间夜数',
        'book_order_num' => '订单数',
        'order_count' => '订单数',
        'list_exposure' => '列表曝光',
        'detail_exposure' => '详情曝光',
        'flow_rate' => '流量转化率',
        'order_filling_num' => '填单数',
        'order_submit_num' => '提交订单数',
        'totals' => '收益汇总',
    ]);
}

function evidence_employee_question_detail(array $question): string
{
    $key = (string)($question['key'] ?? $question['question'] ?? '');
    $status = (string)($question['status'] ?? '');
    $evidence = is_array($question['evidence'] ?? null) ? $question['evidence'] : [];
    $missingPlatforms = evidence_employee_platform_list_text((array)($evidence['missing_platforms'] ?? []));
    $revenueReadyPlatforms = evidence_employee_platform_list_text((array)($evidence['revenue_ready_platforms'] ?? []));

    return match ($key) {
        'today_ota_collected' => $status === 'proved'
            ? '目标日携程和美团 OTA 数据均有入库证据；最近可用数据只作参考。'
            : '目标日 OTA 数据尚未完整证明' . ($missingPlatforms !== '' ? '，缺失平台：' . $missingPlatforms : '') . '；最近可用或历史数据不能替代目标日入库。',
        'trusted_fields' => $status === 'proved'
            ? '字段可信度已有目标日入库、字段资产、数据质量和指标可信证据支撑。'
            : '字段可信度仍受目标日源数据、字段资产、数据质量或指标可信证据缺口影响；未证明字段不能写成可信。',
        'missing_fields' => ((array)($evidence['data_gap_codes'] ?? [])) !== [] || ((array)($evidence['missing_field_codes'] ?? [])) !== []
            ? '字段缺口已显式列出；按数据缺口处理，不用 0 或空值兜底。'
            : '当前未返回字段缺口；仍以目标日采集和指标可信证据为准，不代表所有平台字段完备。',
        'revenue_traffic_conversion' => $status === 'proved'
            ? '收益、流量、转化均可基于目标日 OTA 事实复核。'
            : (($revenueReadyPlatforms !== '' ? '收益可先复核：' . $revenueReadyPlatforms . '；' : '') . '流量或转化事实不足的平台不得输出确定漏斗判断。'),
        'ai_evidence' => $status === 'proved'
            ? 'AI 建议已有证据来源、数据缺口和可执行动作项支撑。'
            : 'AI 依据被上游 OTA 证据缺口阻断；当前只能定位缺口，不能当作可执行经营建议。',
        'next_operation_action' => $status === 'proved'
            ? '运营动作已追溯到 OTA 诊断，并有审批、执行证据、复盘或 ROI 信号。'
            : '执行闭环尚未证明；必须先有可执行 AI 动作项，再保留审批、执行证据和复盘。',
        default => '当前员工问题尚未形成完整说明；按动作队列补齐证据后重新巡检。',
    };
}

function evidence_employee_platform_list_text(array $platforms): string
{
    $labels = [];
    foreach ($platforms as $platform) {
        $value = strtolower(trim((string)$platform));
        $label = match ($value) {
            'ctrip' => '携程',
            'meituan' => '美团',
            default => evidence_employee_readable_copy((string)$platform),
        };
        if ($label !== '') {
            $labels[] = $label;
        }
    }
    return implode('、', array_values(array_unique($labels)));
}

function evidence_missing_field_summary(array $dataGapCodes, array $missingFieldCodes): array
{
    $sourcesByCode = [];
    foreach ($dataGapCodes as $code) {
        $code = trim((string)$code);
        if ($code !== '') {
            $sourcesByCode[$code]['data_gap_codes'] = true;
        }
    }
    foreach ($missingFieldCodes as $code) {
        $code = trim((string)$code);
        if ($code !== '') {
            $sourcesByCode[$code]['missing_field_codes'] = true;
        }
    }

    $summary = [];
    foreach ($sourcesByCode as $code => $sources) {
        $sourceKeys = array_keys($sources);
        $summary[] = [
            'code' => $code,
            'label' => evidence_missing_field_label($code),
            'source_keys' => $sourceKeys,
            'source_text' => evidence_missing_field_source_text($sourceKeys),
            'business_impact' => evidence_missing_field_business_impact($code),
            'next_action' => evidence_missing_field_next_action($code, $sourceKeys),
            'policy' => '显式保留缺口；不使用 0、空值或成功状态替代。',
        ];
    }

    return $summary;
}

function evidence_missing_field_label(string $code): string
{
    return [
        'available_room_nights_missing' => '可售房晚缺失',
        'commission_fields_missing' => '佣金字段缺失',
        'net_revenue_fields_missing' => '净收入字段缺失',
        'lead_time_fields_missing' => '提前预订字段缺失',
        'cancellation_fields_missing' => '取消字段缺失',
        'cancel_room_nights_missing' => '取消房晚缺失',
        'competitor_price_fields_missing' => '竞品价格字段缺失',
    ][$code] ?? ($code !== '' ? '未识别字段缺口' : '未命名缺口');
}

function evidence_missing_field_business_impact(string $code): string
{
    return [
        'available_room_nights_missing' => '缺可售房晚，暂不能可靠计算 OCC、RevPAR 或可售基准。',
        'commission_fields_missing' => '缺佣金金额或佣金率，暂不能核算净收入和渠道成本。',
        'net_revenue_fields_missing' => '缺净收入输入，暂不能输出净 RevPAR 或真实到手收入。',
        'lead_time_fields_missing' => '缺提前预订天数，暂不能判断提前期结构和临近入住风险。',
        'cancellation_fields_missing' => '缺取消订单或取消金额，暂不能判断取消对收入的影响。',
        'cancel_room_nights_missing' => '缺取消房晚，暂不能计算房晚取消率。',
        'competitor_price_fields_missing' => '缺竞品价格，暂不能做竞品价差和调价判断。',
    ][$code] ?? '该缺口需要补齐字段定义或目标日样本后再判断。';
}

function evidence_missing_field_next_action(string $code, array $sourceKeys): string
{
    if (preg_match('/available_room_nights|net_revenue|commission|lead_time|cancellation|cancel_room_nights|competitor_price/i', $code)) {
        return '按字段资产核对平台返回和入库字段，再重跑收益指标核验。';
    }
    if (in_array('missing_field_codes', $sourceKeys, true)) {
        return '按字段缺口清单补齐字段定义或样本证据。';
    }
    return '按数据缺口清单补齐目标日证据后复跑诊断。';
}

function evidence_missing_field_source_text(array $sourceKeys): string
{
    $hasDataGap = in_array('data_gap_codes', $sourceKeys, true);
    $hasFieldGap = in_array('missing_field_codes', $sourceKeys, true);
    if ($hasDataGap && $hasFieldGap) {
        return '数据缺口 / 字段缺口';
    }
    return $hasFieldGap ? '字段缺口' : '数据缺口';
}

function evidence_metric_domain_summary(array $metricDomainReadiness): array
{
    $summary = [];
    foreach ($metricDomainReadiness as $row) {
        if (!is_array($row)) {
            continue;
        }
        $platform = strtolower(trim((string)($row['platform'] ?? '')));
        if ($platform === '') {
            continue;
        }
        $sourceRows = max(0, (int)($row['source_rows'] ?? $row['target_date_rows'] ?? 0));
        $trafficRows = max(0, (int)($row['traffic_rows'] ?? 0));
        $revenueReady = (string)($row['revenue_status'] ?? '') === 'ready';
        $trafficReady = (string)($row['traffic_status'] ?? '') === 'ready';
        $conversionReady = (string)($row['conversion_status'] ?? '') === 'ready';
        $targetTypes = array_values(array_filter(array_map(
            static fn($value): string => strtolower(trim((string)$value)),
            (array)($row['target_date_data_types'] ?? [])
        ), static fn(string $value): bool => $value !== ''));
        $missingDomains = array_values(array_unique(array_filter(array_map(
            static fn($value): string => strtolower(trim((string)$value)),
            (array)($row['missing_domains'] ?? [])
        ), static fn(string $value): bool => $value !== '')));
        $dataTypeText = evidence_metric_domain_data_type_list_text($targetTypes);

        $summary[] = [
            'platform' => $platform,
            'platform_label' => evidence_metric_domain_platform_text($platform),
            'revenue_text' => evidence_metric_domain_status_text((string)($row['revenue_status'] ?? 'missing')),
            'traffic_text' => evidence_metric_domain_status_text((string)($row['traffic_status'] ?? 'missing')),
            'conversion_text' => evidence_metric_domain_status_text((string)($row['conversion_status'] ?? 'missing')),
            'missing_text' => evidence_metric_domain_missing_list_text($missingDomains),
            'source_text' => '目标日源数据 ' . $sourceRows . ' 行 / 流量事实 ' . $trafficRows . ' 行',
            'problem' => evidence_metric_domain_problem_text($revenueReady, $trafficReady, $conversionReady, $sourceRows, $trafficRows),
            'next_action' => evidence_metric_domain_next_action_text($revenueReady, $trafficReady, $conversionReady, $sourceRows, $trafficRows),
            'policy' => '只读目标日 OTA 指标域' . ($dataTypeText !== '' ? ' / ' . $dataTypeText : '') . '；缺失时不输出确定结论。',
        ];
    }

    return $summary;
}

function evidence_metric_domain_platform_text(string $platform): string
{
    return match (strtolower(trim($platform))) {
        'ctrip' => '携程',
        'meituan' => '美团',
        default => $platform !== '' ? 'OTA 平台' : 'OTA',
    };
}

function evidence_metric_domain_status_text(string $status): string
{
    return strtolower(trim($status)) === 'ready' ? '可复核' : '缺失';
}

function evidence_metric_domain_data_type_list_text(array $types): string
{
    $labels = [];
    foreach ($types as $type) {
        $raw = strtolower(trim((string)$type));
        $labels[] = match (true) {
            in_array($raw, ['business', 'business_overview', 'revenue', 'order', 'orders'], true) => '经营/收益',
            in_array($raw, ['traffic', 'flow', 'flow_data'], true) => '流量/转化',
            in_array($raw, ['advertising', 'ads'], true) => '广告',
            in_array($raw, ['quality', 'quality_psi'], true) => '服务质量',
            in_array($raw, ['review', 'comment'], true) => '点评',
            $raw !== '' => '未识别数据类型',
            default => '',
        };
    }

    return implode('、', array_values(array_unique(array_filter($labels))));
}

function evidence_metric_domain_missing_list_text(array $domains): string
{
    $labels = [];
    foreach ($domains as $domain) {
        $labels[] = match (strtolower(trim((string)$domain))) {
            'revenue' => '收益',
            'traffic' => '流量',
            'conversion' => '转化',
            default => '',
        };
    }

    return implode('、', array_values(array_unique(array_filter($labels))));
}

function evidence_metric_domain_problem_text(bool $revenueReady, bool $trafficReady, bool $conversionReady, int $sourceRows, int $trafficRows): string
{
    if ($revenueReady && $trafficReady && $conversionReady) {
        return '收益、流量、转化均可复核。';
    }
    if ($sourceRows <= 0) {
        return '目标日源数据缺失，收益、流量、转化都不能证明。';
    }
    if ($revenueReady && (!$trafficReady || !$conversionReady || $trafficRows <= 0)) {
        return '收益可先复核；流量/转化缺失，不能判断曝光到下单漏斗。';
    }
    if (!$trafficReady || !$conversionReady || $trafficRows <= 0) {
        return '流量/转化缺失，不能判断曝光、访问或下单转化问题。';
    }
    return '收益指标缺失，不能输出收入问题结论。';
}

function evidence_metric_domain_next_action_text(bool $revenueReady, bool $trafficReady, bool $conversionReady, int $sourceRows, int $trafficRows): string
{
    if ($revenueReady && $trafficReady && $conversionReady) {
        return '可进入 OTA 经营诊断。';
    }
    if ($sourceRows <= 0) {
        return '先补目标日 OTA 源数据，再复跑收益指标核验。';
    }
    if (!$revenueReady) {
        return '复核标准事实层和收益指标输入。';
    }
    if (!$trafficReady || !$conversionReady || $trafficRows <= 0) {
        return '补齐流量/转化事实，再复核漏斗诊断。';
    }
    return '按缺口补齐目标日证据后复跑诊断。';
}

function build_employee_questions(array $platformEvidence, array $diagnosis, array $operation): array
{
    $sourceRows = total_source_rows($platformEvidence);
    $metrics = first_revenue_metrics($platformEvidence);
    $gapCodes = data_gap_codes($platformEvidence, $diagnosis);
    $metricTrustKeys = metric_trust_keys($platformEvidence);
    $platformFieldTrust = platform_field_trust($platformEvidence);
    $trafficRows = array_sum(array_map(static fn(array $item): int => (int)($item['revenue_metrics']['traffic']['rows'] ?? 0), $platformEvidence));
    $metricsReady = array_filter($platformEvidence, static fn(array $item): bool => (string)($item['revenue_metrics']['status'] ?? '') === 'ready') !== [];
    $coverageStatus = coverage_status($platformEvidence);
    $missingPlatforms = missing_source_platforms($platformEvidence);
    $missingPlatformText = implode('、', array_map('strtoupper', $missingPlatforms));
    $domainReadiness = metric_domain_readiness($platformEvidence);
    $revenueReadyPlatforms = ready_metric_platforms($domainReadiness, 'revenue_status');
    $revenueReadyText = implode('、', array_map('strtoupper', $revenueReadyPlatforms));
    $trafficReadyPlatforms = ready_metric_platforms($domainReadiness, 'traffic_status');
    $conversionReadyPlatforms = ready_metric_platforms($domainReadiness, 'conversion_status');
    $revenueMissingPlatforms = missing_metric_platforms($domainReadiness, 'revenue_status');
    $trafficMissingPlatforms = missing_metric_platforms($domainReadiness, 'traffic_status');
    $conversionMissingPlatforms = missing_metric_platforms($domainReadiness, 'conversion_status');
    $metricDomainGapCodes = metric_domain_gap_codes($domainReadiness);
    $revenueMetricStatus = count($revenueReadyPlatforms) === count($platformEvidence)
        ? 'ready'
        : ($revenueReadyPlatforms !== [] ? 'partial' : 'empty');
    $aiBlockers = ai_blocking_codes(evidence_missing_codes($platformEvidence, $diagnosis, $operation));
    $aiUpstreamBlockers = array_values(array_filter(
        $aiBlockers,
        static fn(string $code): bool => !in_array($code, ['ai_diagnosis_evidence_sample_missing', 'ai_diagnosis_action_items_blocked', 'ai_action_items_blocked', 'ai_action_items_missing'], true)
    ));
    $aiBlockerText = implode('、', array_slice($aiUpstreamBlockers, 0, 6));
    $diagnosisStatus = diagnosis_evidence_status($diagnosis);
    $actionableDiagnosisCount = actionable_diagnosis_action_count($diagnosis);
    $blockedDiagnosisCount = blocked_diagnosis_action_count($diagnosis);
    $aiDiagnosisBlocker = diagnosis_blocking_code($diagnosis);
    $aiHasExistingBlockerEvidence = $aiUpstreamBlockers !== []
        || $blockedDiagnosisCount > 0
        || in_array('ai_diagnosis_action_items_blocked', $aiBlockers, true);
    $aiQuestionBlockers = $diagnosisStatus === 'proved'
        ? []
        : ($aiBlockers !== [] ? $aiBlockers : array_values(array_filter([$aiDiagnosisBlocker ?: 'ai_diagnosis_evidence_sample_missing'])));
    $aiQuestionBlockerText = implode('、', array_slice($aiQuestionBlockers, 0, 6));
    $operationStatus = operation_evidence_status($operation);
    $operationCounts = operation_signal_counts($operation);
    $operationGapCode = operation_blocking_code($operation);
    $operationBlockingCodes = $operationStatus === 'proved'
        ? []
        : array_values(array_unique(array_filter(array_merge(
            has_actionable_diagnosis_evidence($diagnosis)
                ? []
                : array_merge(
                    $aiBlockers,
                    [diagnosis_blocking_code($diagnosis) === 'ai_diagnosis_action_items_blocked' ? 'ai_action_items_blocked' : diagnosis_blocking_code($diagnosis)]
                ),
            [operation_blocking_code($operation) ?: $operationGapCode]
        ))));
    $operationQuestionStatus = $operationStatus === 'missing' && $operationBlockingCodes !== []
        ? 'warning'
        : $operationStatus;

    return [
        [
            'key' => 'today_ota_collected',
            'question' => '今天 OTA 数据有没有采到',
            'status' => $coverageStatus === 'complete' ? 'proved' : ($coverageStatus === 'partial' ? 'warning' : 'missing'),
            'evidence' => [
                'coverage_status' => $coverageStatus,
                'source_rows' => $sourceRows,
                'missing_platforms' => $missingPlatforms,
                'platforms' => platform_row_counts($platformEvidence),
            ],
            'next_action' => $coverageStatus === 'complete' ? '' : '使用现有携程/美团手动或自动获取入口补齐缺失平台同日数据后重新巡检：' . ($missingPlatformText !== '' ? $missingPlatformText : '携程/美团'),
        ],
        [
            'key' => 'trusted_fields',
            'question' => '哪些字段可信',
            'status' => $coverageStatus === 'complete' && count($metricTrustKeys) > 0 ? 'proved' : ($sourceRows > 0 && count($metricTrustKeys) > 0 ? 'warning' : 'not_proved_no_source_rows'),
            'evidence' => [
                'metric_trust_key_count' => count($metricTrustKeys),
                'metric_trust_keys' => $metricTrustKeys,
                'source_rows' => $sourceRows,
                'coverage_status' => $coverageStatus,
                'missing_platforms' => $missingPlatforms,
                'platform_field_trust' => $platformFieldTrust,
            ],
            'next_action' => $coverageStatus === 'complete' ? '' : '先补齐缺失平台同日源数据，再按 metric_trust 判断字段可信度：' . ($missingPlatformText !== '' ? $missingPlatformText : '携程/美团'),
        ],
        [
            'key' => 'missing_fields',
            'question' => '哪些字段缺失',
            'status' => $gapCodes !== [] ? 'proved' : 'no_gap_reported',
            'evidence' => [
                'data_gap_codes' => $gapCodes,
                'missing_field_codes' => $gapCodes,
                'missing_field_summary' => evidence_missing_field_summary($gapCodes, $gapCodes),
            ],
            'next_action' => $gapCodes !== [] ? '按 data_gaps 处理字段缺口，不使用 0 或空值兜底。' : '',
        ],
        [
            'key' => 'revenue_traffic_conversion',
            'question' => '收入/流量/转化出了什么问题',
            'status' => $metricsReady && $trafficRows > 0 && $metricDomainGapCodes === [] ? 'proved' : ($metricsReady ? 'warning' : 'not_proved'),
            'evidence' => [
                'revenue_metric_status' => $revenueMetricStatus,
                'primary_revenue_metric_status' => (string)($metrics['status'] ?? 'empty'),
                'traffic_rows' => $trafficRows,
                'metric_domain_readiness' => $domainReadiness,
                'metric_domain_summary' => evidence_metric_domain_summary($domainReadiness),
                'revenue_ready_platforms' => $revenueReadyPlatforms,
                'traffic_ready_platforms' => $trafficReadyPlatforms,
                'conversion_ready_platforms' => $conversionReadyPlatforms,
                'revenue_missing_platforms' => $revenueMissingPlatforms,
                'traffic_missing_platforms' => $trafficMissingPlatforms,
                'conversion_missing_platforms' => $conversionMissingPlatforms,
                'metric_domain_gap_codes' => $metricDomainGapCodes,
                'totals' => $metrics['totals'] ?? [],
                'traffic' => $metrics['traffic'] ?? [],
            ],
            'next_action' => $metricsReady ? '收益指标可先复核：' . ($revenueReadyText !== '' ? $revenueReadyText : '部分平台') . '；流量/转化事实不足时，不输出流量/转化确定结论。' : '先补齐同日 OTA 源数据和标准事实层。',
        ],
        [
            'key' => 'ai_evidence',
            'question' => 'AI 建议依据是什么',
            'status' => $diagnosisStatus,
            'evidence' => [
                'diagnosis_status' => $diagnosisStatus === 'proved'
                    ? 'proved'
                    : ($aiHasExistingBlockerEvidence ? 'blocked_by_verified_ota_gaps' : 'missing_real_api_response'),
                'action_item_status' => $actionableDiagnosisCount > 0
                    ? 'actionable'
                    : ($aiHasExistingBlockerEvidence ? 'blocked_by_verified_ota_gaps' : 'missing'),
                'source_policy' => $aiHasExistingBlockerEvidence ? 'read_existing_ota_gap_evidence_only' : 'missing_real_ota_diagnosis_response',
                'evidence_source_count' => count((array)($diagnosis['evidence_sources'] ?? [])),
                'data_gap_count' => count((array)($diagnosis['data_gaps'] ?? [])),
                'action_item_count' => count((array)($diagnosis['action_items'] ?? [])),
                'actionable_action_item_count' => $actionableDiagnosisCount,
                'blocked_action_item_count' => $blockedDiagnosisCount,
                'action_item_statuses' => diagnosis_action_item_statuses($diagnosis),
                'data_gap_evidence_present' => $aiBlockers !== [],
                'scope_date_status' => (string)($diagnosis['scope_date_status'] ?? 'missing'),
                'scope_date' => $diagnosis['scope_date'] ?? null,
                'expected_scope_date' => $diagnosis['expected_scope_date'] ?? null,
                'blocking_missing_codes' => $aiQuestionBlockers,
            ],
            'next_action' => $diagnosisStatus === 'proved'
                ? ''
                : '先处理阻断项后再调用现有 OTA 诊断并附脱敏 evidence_sources/data_gaps/action_items：' . ($aiQuestionBlockerText !== '' ? $aiQuestionBlockerText : 'ai_diagnosis_evidence_sample_missing'),
        ],
        [
            'key' => 'next_operation_action',
            'question' => '下一步该执行什么动作',
            'status' => $operationQuestionStatus,
            'evidence' => [
                'operation_evidence_status' => $operationStatus,
                'execution_intent_count' => $operationCounts['execution_intent_count'],
                'execution_flow_present' => !empty($operation['execution_flow']),
                'execution_flow_item_count' => $operationCounts['execution_flow_item_count'],
                'execution_flow_stage_count' => $operationCounts['execution_flow_stage_count'],
                'execution_flow_summary_total' => $operationCounts['execution_flow_summary_total'],
                'ota_diagnosis_linked_intent_count' => $operationCounts['ota_diagnosis_linked_intent_count'],
                'ota_diagnosis_linked_flow_item_count' => $operationCounts['ota_diagnosis_linked_flow_item_count'],
                'approved_count' => $operationCounts['approved_count'],
                'executed_count' => $operationCounts['executed_count'],
                'evidence_ready_count' => $operationCounts['evidence_ready_count'],
                'execution_evidence_count' => $operationCounts['execution_evidence_count'],
                'reviewed_count' => $operationCounts['reviewed_count'],
                'roi_ready_count' => $operationCounts['roi_ready_count'],
                'blocked_execution_count' => $operationCounts['blocked_execution_count'],
                'completion_signal_count' => $operationCounts['completion_signal_count'],
                'source_policy' => 'read_existing_operation_execution_state_only',
                'raw_data_exposed' => false,
                'scope_date_status' => (string)($operation['scope_date_status'] ?? 'missing'),
                'scope_date' => $operation['scope_date'] ?? null,
                'expected_scope_date' => $operation['expected_scope_date'] ?? null,
                'blocking_missing_codes' => $operationBlockingCodes,
            ],
            'next_action' => $operationStatus === 'proved' ? '' : ($diagnosisStatus === 'proved'
                ? ($operationGapCode === 'operation_execution_ai_action_link_missing'
                    ? '已有执行流但未关联 OTA 诊断 action_items；先补齐 source、evidence_refs 或 action_item_id 关联。'
                    : ($operationStatus === 'warning'
                    ? '补齐执行意图的审批通过、执行证据或复盘状态；未补齐前不标记运营闭环完成。'
                    : '创建或附上一个真实执行意图/执行流程样例，包含审批、执行证据或复盘状态。'))
                : '先取得真实 OTA 诊断 action_items，再创建执行意图并保留审批、执行证据和复盘。'),
        ],
    ];
}

function closure_top_action(array $questions): array
{
    foreach ($questions as $question) {
        if (in_array((string)($question['status'] ?? ''), ['proved', 'no_gap_reported'], true)) {
            continue;
        }
        $directStatus = (string)($question['direct_next_action_status'] ?? '');
        $useDirect = (string)($question['direct_next_action_code'] ?? '') !== '' && $directStatus !== 'blocked';
        $prefix = $useDirect ? 'direct_next_action' : 'primary_next_action';
        $code = (string)($question[$prefix . '_code'] ?? '');
        if ($code === '' && !$useDirect) {
            $prefix = 'direct_next_action';
            $code = (string)($question[$prefix . '_code'] ?? '');
        }
        if ($code === '') {
            continue;
        }
        $entryOptions = array_values(array_filter(
            (array)($question[$prefix . '_entry_options'] ?? []),
            static fn($option): bool => is_array($option) && (string)($option['entry'] ?? '') !== ''
        ));
        $relatedQuestionKeys = array_values(array_unique(array_filter(array_map('strval', (array)($question[$prefix . '_related_question_keys'] ?? [])))));
        $resolvesMissingCodes = array_values(array_unique(array_filter(array_map('strval', (array)($question[$prefix . '_resolves_missing_codes'] ?? [])))));
        $liveClosureGapCodes = array_values(array_unique(array_filter(array_map('strval', (array)($question[$prefix . '_live_closure_gap_codes'] ?? [])))));
        $platform = strtolower(trim((string)($question[$prefix . '_platform'] ?? '')));
        if (!in_array($platform, ['ctrip', 'meituan'], true)) {
            if (str_contains($code, 'ctrip')) {
                $platform = 'ctrip';
            } elseif (str_contains($code, 'meituan')) {
                $platform = 'meituan';
            } else {
                $platform = '';
            }
        }
        return [
            'top_action_code' => $code,
            'top_action_platform' => $platform,
            'top_action_family' => (string)($question[$prefix . '_family'] ?? ''),
            'top_action_entry' => (string)($question[$prefix . '_entry'] ?? ''),
            'top_action_entry_options' => $entryOptions,
            'top_action_success_criteria' => (string)($question[$prefix . '_success_criteria'] ?? ''),
            'top_action_status' => (string)($question[$prefix . '_status'] ?? ''),
            'top_action_related_question_keys' => $relatedQuestionKeys,
            'top_action_resolves_missing_codes' => $resolvesMissingCodes,
            'top_action_live_closure_gap_codes' => $liveClosureGapCodes,
            'top_action' => (string)($question['next_action'] ?? ''),
            'top_action_employee_text' => (string)($question['employee_next_action'] ?? $question['next_action'] ?? ''),
            'top_question_key' => (string)($question['key'] ?? ''),
            'top_question' => (string)($question['question'] ?? ''),
        ];
    }

    return [
        'top_action_code' => '',
        'top_action_platform' => '',
        'top_action_family' => '',
        'top_action_entry' => '',
        'top_action_entry_options' => [],
        'top_action_success_criteria' => '',
        'top_action_status' => '',
        'top_action_related_question_keys' => [],
        'top_action_resolves_missing_codes' => [],
        'top_action_live_closure_gap_codes' => [],
        'top_action' => '',
        'top_action_employee_text' => '',
        'top_question_key' => '',
        'top_question' => '',
    ];
}

/**
 * @param array<string, mixed> $topAction
 * @param array<int, array<string, mixed>> $collectionSourceSummary
 * @return array<string, mixed>
 */
function top_action_source_snapshot(array $topAction, array $collectionSourceSummary): array
{
    $platform = strtolower(trim((string)($topAction['top_action_platform'] ?? $topAction['platform'] ?? '')));
    if (!in_array($platform, ['ctrip', 'meituan'], true)) {
        $actionCode = strtolower((string)($topAction['top_action_code'] ?? $topAction['action_code'] ?? ''));
        foreach (['ctrip', 'meituan'] as $candidate) {
            if (str_contains($actionCode, $candidate)) {
                $platform = $candidate;
                break;
            }
        }
    }
    if (!in_array($platform, ['ctrip', 'meituan'], true)) {
        $actionCode = strtolower((string)($topAction['top_action_code'] ?? $topAction['action_code'] ?? ''));
        if (in_array($actionCode, ['collect_ai_diagnosis_evidence', 'resolve_ai_diagnosis_blocked_action_items', 'collect_operation_execution_evidence'], true)) {
            foreach ($collectionSourceSummary as $row) {
                $candidate = strtolower((string)($row['platform'] ?? ''));
                if (in_array($candidate, ['ctrip', 'meituan'], true)) {
                    $platform = $candidate;
                    break;
                }
            }
        }
    }
    if (!in_array($platform, ['ctrip', 'meituan'], true)) {
        return [];
    }

    foreach ($collectionSourceSummary as $row) {
        if (!is_array($row) || strtolower((string)($row['platform'] ?? '')) !== $platform) {
            continue;
        }
        return [
            'platform' => $platform,
            'target_date' => (string)($row['target_date'] ?? ''),
            'storage_table' => (string)($row['storage_table'] ?? 'online_daily_data'),
            'source_policy' => (string)($row['source_policy'] ?? 'read_existing_online_daily_data_only'),
            'target_date_rows' => max(0, (int)($row['target_date_rows'] ?? 0)),
            'target_date_data_types' => array_values(array_filter(array_map('strval', (array)($row['target_date_data_types'] ?? [])))),
            'latest_available' => is_array($row['latest_available'] ?? null) ? $row['latest_available'] : null,
            'latest_available_reference_only' => (bool)($row['latest_available_reference_only'] ?? true),
            'proof_requirement' => 'source_date_evidence.platforms 中该平台 target_date_rows > 0',
            'reference_policy' => 'latest_available 只能作参考，不能替代目标日入库行证明。',
        ];
    }

    return [];
}

function build_closure_summary(array $platformEvidence, array $diagnosis, array $operation, ?array $questions = null, string $targetDate = ''): array
{
    $questions = $questions ?? build_employee_questions($platformEvidence, $diagnosis, $operation);
    $missing = array_values(array_filter($questions, static fn(array $item): bool => !in_array($item['status'], ['proved', 'no_gap_reported'], true)));
    $collectionSourceSummary = build_collection_source_summary($platformEvidence, $targetDate);
    $topAction = closure_top_action($missing);
    return array_merge([
        'status' => $missing === [] ? 'passed' : 'incomplete',
        'metric_scope' => 'ota_channel',
        'employee_question_count' => count($questions),
        'proved_count' => count($questions) - count($missing),
        'missing_count' => count($missing),
        'missing_questions' => array_values(array_map(static fn(array $item): string => (string)$item['question'], $missing)),
        'missing_question_keys' => array_values(array_map(static fn(array $item): string => (string)$item['key'], $missing)),
        'source_policy' => 'read_existing_employee_questions_only',
        'reference_policy' => 'latest_available_and_history_rows_are_reference_only_not_target_date_proof',
        'protected_boundary' => '不改变携程/美团手动或自动获取逻辑，不改变获取字段和字段映射；证据不足时不生成确定经营结论。',
        'top_action_source_snapshot' => top_action_source_snapshot($topAction, $collectionSourceSummary),
    ], $topAction);
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
        ? normalize_diagnosis(read_json_file((string)$options['diagnosis']), $options)
        : build_blocked_diagnosis_from_platform_evidence($platformEvidence, $options);
    $operation = (string)$options['operation'] !== ''
        ? normalize_operation(read_json_file((string)$options['operation']), $options)
        : [
            'source' => '/api/operation/execution-intents',
            'status' => 'missing_real_api_response',
            'execution_intents' => [],
            'execution_flow' => [],
        ];

    $coverageStatus = coverage_status($platformEvidence);
    $domainReadiness = metric_domain_readiness($platformEvidence);
    $revenueReadyPlatforms = ready_metric_platforms($domainReadiness, 'revenue_status');
    $trafficReadyPlatforms = ready_metric_platforms($domainReadiness, 'traffic_status');
    $conversionReadyPlatforms = ready_metric_platforms($domainReadiness, 'conversion_status');
    $revenueMissingPlatforms = missing_metric_platforms($domainReadiness, 'revenue_status');
    $trafficMissingPlatforms = missing_metric_platforms($domainReadiness, 'traffic_status');
    $conversionMissingPlatforms = missing_metric_platforms($domainReadiness, 'conversion_status');
    $metricDomainGapCodes = metric_domain_gap_codes($domainReadiness);
    $revenueMetricStatus = count($revenueReadyPlatforms) === count($platformEvidence)
        ? 'ready'
        : ($revenueReadyPlatforms !== [] ? 'partial' : 'empty');
    $employeeQuestions = build_employee_questions($platformEvidence, $diagnosis, $operation);
    $nextActions = build_next_actions($platformEvidence, $diagnosis, $operation, $options);
    $employeeQuestions = with_employee_question_action_codes($employeeQuestions, $nextActions);
    $collectionSourceSummary = build_collection_source_summary($platformEvidence, (string)$options['date']);
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
            'status' => match ($coverageStatus) {
                'complete' => 'source_rows_present',
                'partial' => 'source_rows_partial',
                default => 'source_rows_missing',
            },
            'coverage_status' => $coverageStatus,
            'missing_platforms' => missing_source_platforms($platformEvidence),
            'source' => 'online_daily_data',
            'platforms' => array_map(static fn(array $item): array => [
                'platform' => $item['platform'],
                'source_rows' => $item['source_rows']['count'],
                'data_types' => $item['source_rows']['data_types'],
                'latest_trace_time' => $item['source_rows']['latest_trace_time'],
                'latest_available' => $item['source_rows']['latest_available'] ?? null,
                'sample_traces' => $item['source_rows']['sample_traces'],
            ], $platformEvidence),
        ],
        'collection_source_summary' => $collectionSourceSummary,
        'platform_evidence' => $platformEvidence,
        'revenue_metrics' => [
            'status' => $revenueMetricStatus,
            'source' => '/api/ota-standard/revenue-metrics',
            'metric_domain_readiness' => $domainReadiness,
            'revenue_ready_platforms' => $revenueReadyPlatforms,
            'traffic_ready_platforms' => $trafficReadyPlatforms,
            'conversion_ready_platforms' => $conversionReadyPlatforms,
            'revenue_missing_platforms' => $revenueMissingPlatforms,
            'traffic_missing_platforms' => $trafficMissingPlatforms,
            'conversion_missing_platforms' => $conversionMissingPlatforms,
            'metric_domain_gap_codes' => $metricDomainGapCodes,
            'primary_platform' => $primary['platform'] ?? null,
            'primary_platform_status' => $primary['revenue_metrics']['status'] ?? 'empty',
            'data_gaps' => $primary['revenue_metrics']['data_gaps'] ?? [],
            'metric_trust' => $primary['revenue_metrics']['metric_trust'] ?? [],
            'totals' => $primary['revenue_metrics']['totals'] ?? [],
            'traffic' => $primary['revenue_metrics']['traffic'] ?? [],
            'advertising' => $primary['revenue_metrics']['advertising'] ?? [],
            'quality' => $primary['revenue_metrics']['quality'] ?? [],
        ],
        'ota_diagnosis' => $diagnosis,
        'operation_execution' => $operation,
        'employee_questions' => $employeeQuestions,
        'next_actions' => $nextActions,
        'closure_summary' => build_closure_summary($platformEvidence, $diagnosis, $operation, $employeeQuestions, (string)$options['date']),
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
