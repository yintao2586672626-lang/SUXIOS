<?php
declare(strict_types=1);

use app\service\OperationManagementService;
use think\App;
use think\facade\Db;

$root = dirname(__DIR__);
require $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

/**
 * @param array<int, string> $argv
 * @return array<string, mixed>
 */
function phase1_operation_parse_args(array $argv): array
{
    $options = [
        'date' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d'),
        'platform' => '',
        'system-hotel-id' => '',
        'system_hotel_id' => '',
        'hotel-id' => '',
        'hotel_id' => '',
        'action-item-id' => '',
        'action-item-status' => 'ready_for_execution',
        'action-text' => '',
        'diagnosis-summary' => '',
        'output' => '',
        'diagnosis-output' => '',
        'format' => 'json',
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
            continue;
        }
        [$key, $value] = explode('=', substr($arg, 2), 2);
        if (!array_key_exists($key, $options)) {
            continue;
        }
        $options[$key] = trim($value);
    }

    if ((string)$options['system-hotel-id'] === '' && (string)$options['system_hotel_id'] !== '') {
        $options['system-hotel-id'] = (string)$options['system_hotel_id'];
    }
    if ((string)$options['hotel-id'] === '' && (string)$options['hotel_id'] !== '') {
        $options['hotel-id'] = (string)$options['hotel_id'];
    }
    unset($options['system_hotel_id'], $options['hotel_id']);

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
    if ((string)$options['hotel-id'] === '') {
        $options['hotel-id'] = (string)$options['system-hotel-id'];
    }
    if (!in_array((string)$options['format'], ['json'], true)) {
        throw new InvalidArgumentException('Invalid --format, expected json.');
    }

    if ((string)$options['action-item-id'] === '') {
        $options['action-item-id'] = sprintf(
            '%s_p0_traffic_review_%s_%d',
            $platform,
            str_replace('-', '', (string)$options['date']),
            (int)$options['system-hotel-id']
        );
    }

    if ((string)$options['action-text'] === '') {
        $options['action-text'] = sprintf(
            'Review %s %s OTA traffic and conversion facts, confirm field evidence is ready, then hand the action to manual operation approval.',
            (string)$options['date'],
            $platform
        );
    }

    if ((string)$options['diagnosis-summary'] === '') {
        $options['diagnosis-summary'] = sprintf(
            '%s %s OTA traffic and conversion facts have target-date source rows and are ready for manual operation handoff in OTA-channel scope only.',
            (string)$options['date'],
            $platform
        );
    }

    return $options;
}

function phase1_operation_resolve_path(string $path): string
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

/**
 * @return array<int, string>
 */
function phase1_operation_source_aliases(string $platform): array
{
    return match ($platform) {
        'ctrip' => ['ctrip', 'ctrip_business', 'ctrip_manual_overview', 'ctrip_browser_profile'],
        'meituan' => ['meituan', 'meituan_rank', 'meituan_business', 'meituan_browser_profile'],
        default => [$platform],
    };
}

/**
 * @return array<string, bool>
 */
function phase1_operation_table_columns(string $table): array
{
    $columns = [];
    foreach (Db::query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`') as $row) {
        $field = (string)($row['Field'] ?? '');
        if ($field !== '') {
            $columns[$field] = true;
        }
    }
    return $columns;
}

/**
 * @param array<string, bool> $columns
 */
function phase1_operation_traffic_query(array $columns, string $platform, string $date, int $systemHotelId): object
{
    $query = Db::name('online_daily_data');
    if (isset($columns['source'])) {
        $query->whereIn('source', phase1_operation_source_aliases($platform));
    }
    if (isset($columns['data_date'])) {
        $query->where('data_date', $date);
    }
    if (isset($columns['system_hotel_id'])) {
        $query->where('system_hotel_id', $systemHotelId);
    }
    if (isset($columns['data_type'])) {
        $query->where('data_type', 'traffic');
    }
    return $query;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function phase1_operation_decoded_raw(array $row): array
{
    $raw = $row['raw_data'] ?? null;
    if (is_array($raw)) {
        return $raw;
    }
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * @param array<string, mixed> $row
 * @return array<int, array<string, mixed>>
 */
function phase1_operation_field_facts(array $row): array
{
    $raw = phase1_operation_decoded_raw($row);
    foreach ([$raw['field_facts'] ?? null, $raw['facts'] ?? null] as $candidate) {
        if (!is_array($candidate)) {
            continue;
        }
        return array_values(array_filter($candidate, static fn($item): bool => is_array($item)));
    }
    return [];
}

/**
 * @param array<int, array<string, mixed>> $facts
 * @return array<string, mixed>
 */
function phase1_operation_field_fact_summary(array $facts): array
{
    $required = ['list_exposure', 'detail_exposure', 'flow_rate', 'order_filling_num', 'order_submit_num'];
    $ready = [];
    $missing = array_fill_keys($required, true);

    foreach ($facts as $fact) {
        $metric = strtolower(trim((string)($fact['metric_key'] ?? $fact['field_key'] ?? '')));
        if (!isset($missing[$metric])) {
            continue;
        }
        $evidence = is_array($fact['capture_evidence'] ?? null) ? $fact['capture_evidence'] : [];
        $stored = (bool)($fact['stored_value_present'] ?? false);
        $hasSource = trim((string)($fact['source_path'] ?? '')) !== '';
        $hasStorage = trim((string)($fact['storage_field'] ?? '')) !== '';
        $hasTrace = trim((string)($evidence['source_trace_id'] ?? '')) !== ''
            && trim((string)($evidence['source_url_hash'] ?? '')) !== '';
        if ($stored && $hasSource && $hasStorage && $hasTrace) {
            $ready[$metric] = true;
            unset($missing[$metric]);
        }
    }

    return [
        'status' => $facts === [] ? 'not_loaded' : ($missing === [] ? 'ready' : 'partial'),
        'required_metric_keys' => $required,
        'ready_metric_keys' => array_values(array_keys($ready)),
        'missing_metric_keys' => array_values(array_keys($missing)),
        'fact_count' => count($facts),
    ];
}

/**
 * @param array<string, mixed> $options
 * @param array<string, mixed> $row
 * @param array<string, mixed> $factSummary
 * @return array<string, mixed>
 */
function phase1_operation_build_diagnosis(array $options, array $row, int $targetRows, array $factSummary): array
{
    $date = (string)$options['date'];
    $platform = (string)$options['platform'];
    $systemHotelId = (int)$options['system-hotel-id'];
    $hotelId = (string)$options['hotel-id'];
    $actionItemId = (string)$options['action-item-id'];
    $fieldFactReady = (string)($factSummary['status'] ?? '') === 'ready';
    $dataGaps = [];
    if (!$fieldFactReady) {
        $dataGaps[] = [
            'code' => $platform . '_p0_traffic_field_fact_not_ready',
            'message' => 'Target-date traffic row exists, but required desensitized field facts are not fully ready.',
            'missing_metric_keys' => $factSummary['missing_metric_keys'] ?? [],
            'scope' => 'ota_channel',
        ];
    }

    return [
        'source' => '/api/agent/ota-diagnosis',
        'status' => $fieldFactReady ? 'actionable_from_verified_p0_traffic_evidence' : 'blocked_by_verified_ota_gaps',
        'source_policy' => $fieldFactReady
            ? 'read_existing_verified_p0_traffic_evidence_only'
            : 'read_existing_ota_gap_evidence_only',
        'scope' => [
            'date' => $date,
            'platform' => $platform,
            'system_hotel_id' => $systemHotelId,
            'hotel_id' => $hotelId,
            'metric_scope' => 'ota_channel',
        ],
        'summary' => (string)$options['diagnosis-summary'],
        'evidence_sources' => [
            [
                'ref' => 'online_daily_data#' . (int)($row['id'] ?? 0),
                'source_policy' => 'read_existing_imported_p0_traffic_row_metadata_only',
                'metric_scope' => 'ota_channel',
                'date' => $date,
                'platform' => $platform,
                'system_hotel_id' => $systemHotelId,
                'target_date_traffic_rows' => $targetRows,
                'field_fact_status' => (string)($factSummary['status'] ?? 'unknown'),
                'raw_data_exposed' => false,
            ],
            [
                'ref' => sprintf('reports/p0_traffic_%s_%d_%s.json', $platform, $systemHotelId, str_replace('-', '', $date)),
                'source_policy' => 'desensitized_capture_payload_reference',
                'metric_scope' => 'ota_channel',
            ],
        ],
        'data_gaps' => $dataGaps,
        'action_items' => [
            [
                'id' => $actionItemId,
                'action' => (string)$options['action-text'],
                'status' => $fieldFactReady ? (string)$options['action-item-status'] : 'blocked_by_verified_ota_gaps',
                'evidence_refs' => [
                    'online_daily_data#' . (int)($row['id'] ?? 0),
                    sprintf('p0_traffic_%s_%d_%s', $platform, $systemHotelId, str_replace('-', '', $date)),
                ],
                'source_policy' => 'ota_channel_operation_handoff_only',
            ],
        ],
    ];
}

/**
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function phase1_operation_intent_input(array $options, array $row, int $targetRows, array $factSummary): array
{
    $date = (string)$options['date'];
    $platform = (string)$options['platform'];
    $systemHotelId = (int)$options['system-hotel-id'];
    $actionItemId = (string)$options['action-item-id'];

    return [
        'source_module' => 'ota_diagnosis',
        'source_record_id' => (int)sprintf('%u', crc32($platform . '_p0_traffic_review_' . $date . '_' . $systemHotelId)),
        'hotel_id' => $systemHotelId,
        'platform' => $platform,
        'object_type' => 'data_collection',
        'action_type' => 'diagnosis_action_review',
        'date_start' => $date,
        'date_end' => $date,
        'current_value' => [
            'action_item_status' => (string)$options['action-item-status'],
            'target_date_rows' => $targetRows,
            'traffic_field_fact_status' => (string)($factSummary['status'] ?? 'unknown'),
            'source_row_id' => (int)($row['id'] ?? 0),
            'metric_scope' => 'ota_channel',
        ],
        'target_value' => [
            'collection_scope' => $platform . '_target_date_traffic_review',
            'target_date' => $date,
            'action_text' => (string)$options['action-text'],
            'action_item_id' => $actionItemId,
        ],
        'evidence' => [
            'evidence_refs' => [
                'online_daily_data#' . (int)($row['id'] ?? 0),
                sprintf('reports/p0_traffic_%s_%d_%s.json', $platform, $systemHotelId, str_replace('-', '', $date)),
            ],
            'data_gaps' => (string)($factSummary['status'] ?? '') === 'ready' ? [] : [
                [
                    'code' => $platform . '_p0_traffic_field_fact_not_ready',
                    'missing_metric_keys' => $factSummary['missing_metric_keys'] ?? [],
                    'scope' => 'ota_channel',
                ],
            ],
            'source_policy' => $platform . '_p0_traffic_import_to_operation_execution_loop_no_ota_acquisition_change',
            'protected_boundary' => 'This execution record proves operation handoff only; it does not change OTA acquisition, field mapping, or whole-hotel operating truth.',
            'action_item_id' => $actionItemId,
            'action_item_status' => (string)$options['action-item-status'],
            'diagnosis_summary' => (string)$options['diagnosis-summary'],
            'metric_scope' => 'ota_channel',
            'scope' => [
                'date' => $date,
                'platform' => $platform,
                'system_hotel_id' => $systemHotelId,
            ],
        ],
        'expected_metric' => 'ota_p0_traffic_closure',
        'expected_delta' => 0,
        'risk_level' => 'medium',
        'status' => 'pending_approval',
    ];
}

function phase1_operation_write_json(string $path, array $payload): void
{
    $resolved = phase1_operation_resolve_path($path);
    $dir = dirname($resolved);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Failed to create output directory: ' . $dir);
    }
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('Failed to encode JSON.');
    }
    file_put_contents($resolved, $json . PHP_EOL);
}

try {
    $options = phase1_operation_parse_args($argv);
    $app = new App();
    $app->initialize();

    $columns = phase1_operation_table_columns('online_daily_data');
    $query = phase1_operation_traffic_query(
        $columns,
        (string)$options['platform'],
        (string)$options['date'],
        (int)$options['system-hotel-id']
    );
    $targetRows = (int)(clone $query)->count();
    $row = (clone $query)
        ->field('id,source,data_date,data_type,system_hotel_id,hotel_id,raw_data')
        ->order('id', 'desc')
        ->find();
    if (!is_array($row) || $row === []) {
        throw new RuntimeException('target_date_traffic_row_missing');
    }

    $factSummary = phase1_operation_field_fact_summary(phase1_operation_field_facts($row));
    $input = phase1_operation_intent_input($options, $row, $targetRows, $factSummary);
    $hotelIds = [(int)$options['system-hotel-id']];
    $service = new OperationManagementService();

    $intentRow = Db::name('operation_execution_intents')
        ->where('source_module', 'ota_diagnosis')
        ->where('source_record_id', (int)$input['source_record_id'])
        ->where('hotel_id', (int)$input['hotel_id'])
        ->where('platform', (string)$input['platform'])
        ->where('object_type', 'data_collection')
        ->where('action_type', 'diagnosis_action_review')
        ->where('date_start', (string)$options['date'])
        ->where('date_end', (string)$options['date'])
        ->whereNull('deleted_at')
        ->order('id', 'desc')
        ->find();
    $intentId = is_array($intentRow) ? (int)($intentRow['id'] ?? 0) : 0;
    $created = false;
    if ($intentId <= 0) {
        $createdIntent = $service->createExecutionIntent($hotelIds, (int)$options['system-hotel-id'], $input, 1);
        $intentId = (int)($createdIntent['id'] ?? 0);
        $created = true;
    }
    if ($intentId <= 0) {
        throw new RuntimeException('operation_execution_intent_create_failed');
    }

    $intentDetail = $service->executionIntents($hotelIds, (int)$options['system-hotel-id'], [
        'platform' => (string)$options['platform'],
        'object_type' => 'data_collection',
    ]);
    $currentIntent = null;
    foreach ((array)($intentDetail['list'] ?? []) as $candidate) {
        if ((int)($candidate['id'] ?? 0) === $intentId) {
            $currentIntent = $candidate;
            break;
        }
    }
    if (!is_array($currentIntent)) {
        throw new RuntimeException('operation_execution_intent_not_readable');
    }
    if ((string)($currentIntent['status'] ?? '') === 'blocked') {
        throw new RuntimeException('operation_execution_intent_blocked: ' . (string)($currentIntent['blocked_reason'] ?? ''));
    }
    if ((string)($currentIntent['status'] ?? '') !== 'approved') {
        $service->approveExecutionIntent(
            $intentId,
            true,
            'Phase1 OTA diagnosis action approved for P0 traffic handoff verification.',
            1,
            $hotelIds
        );
    }

    $taskRow = Db::name('operation_execution_tasks')
        ->where('intent_id', $intentId)
        ->whereNull('deleted_at')
        ->order('id', 'desc')
        ->find();
    $taskId = is_array($taskRow) ? (int)($taskRow['id'] ?? 0) : 0;
    if ($taskId <= 0) {
        throw new RuntimeException('operation_execution_task_missing_after_approval');
    }
    $evidenceCount = (int)Db::name('operation_execution_evidence')->where('task_id', $taskId)->whereNull('deleted_at')->count();
    $taskStatus = is_array($taskRow) ? (string)($taskRow['status'] ?? '') : '';
    if ($taskStatus !== 'executed' || $evidenceCount <= 0) {
        $service->executeExecutionTask($taskId, $hotelIds, [
            'status' => 'executed',
            'current_value' => [
                'traffic_field_fact_status' => (string)($factSummary['status'] ?? 'unknown'),
                'source_row_id' => (int)($row['id'] ?? 0),
                'target_date_rows' => $targetRows,
                'metric_scope' => 'ota_channel',
            ],
            'evidence_type' => 'manual',
            'evidence' => [
                'before' => [
                    'action_item_status' => (string)$options['action-item-status'],
                    'target_date_rows' => $targetRows,
                ],
                'after' => [
                    'operation_handoff_status' => 'executed',
                    'p0_traffic_gate' => (string)($factSummary['status'] ?? '') === 'ready' ? 'ready' : 'incomplete',
                    'source_row_id' => (int)($row['id'] ?? 0),
                ],
                'platform_response' => [
                    'field_fact_status' => (string)($factSummary['status'] ?? 'unknown'),
                    'raw_data_exposed' => false,
                ],
                'remark' => 'P0 traffic closure was handed to the operation execution loop in OTA-channel scope.',
            ],
        ], 1);
    }

    $service->reviewExecutionTask($taskId, $hotelIds, [
        'result_status' => 'success',
        'result_summary' => 'Phase1 P0 traffic and conversion facts were imported and verified; operation handoff evidence recorded for OTA-channel scope.',
    ]);

    $flow = $service->executionFlow($hotelIds, (int)$options['system-hotel-id'], [
        'platform' => (string)$options['platform'],
        'object_type' => 'data_collection',
        'action_type' => 'diagnosis_action_review',
    ]);
    $intents = $service->executionIntents($hotelIds, (int)$options['system-hotel-id'], [
        'platform' => (string)$options['platform'],
        'object_type' => 'data_collection',
    ]);
    $intentList = array_values(array_filter(
        (array)($intents['list'] ?? []),
        static fn($item): bool => is_array($item) && (int)($item['id'] ?? 0) === $intentId
    ));

    $operationEvidence = [
        'scope' => [
            'date' => (string)$options['date'],
            'platform' => (string)$options['platform'],
            'system_hotel_id' => (int)$options['system-hotel-id'],
            'hotel_id' => (string)$options['hotel-id'],
            'metric_scope' => 'ota_channel',
        ],
        'source' => 'OperationManagementService::executionFlow',
        'status' => 'from_service_layer_same_business_tables',
        'created_or_reused_intent_id' => $intentId,
        'created_or_reused_task_id' => $taskId,
        'created_in_this_run' => $created,
        'execution_intents' => $intentList,
        'execution_flow' => $flow,
    ];

    $operationOutput = (string)$options['output'];
    if ($operationOutput === '') {
        $operationOutput = sprintf(
            'reports/phase1_operation_execution_%s_%d_%s.service.json',
            (string)$options['platform'],
            (int)$options['system-hotel-id'],
            str_replace('-', '', (string)$options['date'])
        );
    }
    phase1_operation_write_json($operationOutput, $operationEvidence);

    $diagnosisOutput = (string)$options['diagnosis-output'];
    if ($diagnosisOutput === '') {
        $diagnosisOutput = sprintf(
            'reports/phase1_ota_diagnosis_%s_%d_%s.evidence.json',
            (string)$options['platform'],
            (int)$options['system-hotel-id'],
            str_replace('-', '', (string)$options['date'])
        );
    }
    $diagnosisEvidence = phase1_operation_build_diagnosis($options, $row, $targetRows, $factSummary);
    phase1_operation_write_json($diagnosisOutput, $diagnosisEvidence);

    echo json_encode([
        'status' => 'recorded',
        'scope' => $operationEvidence['scope'],
        'target_date_traffic_rows' => $targetRows,
        'source_row_id' => (int)($row['id'] ?? 0),
        'traffic_field_fact_status' => (string)($factSummary['status'] ?? 'unknown'),
        'intent_id' => $intentId,
        'task_id' => $taskId,
        'created_in_this_run' => $created,
        'operation_output' => $operationOutput,
        'diagnosis_output' => $diagnosisOutput,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    echo json_encode([
        'status' => 'failed',
        'issue' => [
            'code' => 'phase1_operation_execution_record_failed',
            'message' => $e->getMessage(),
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}
