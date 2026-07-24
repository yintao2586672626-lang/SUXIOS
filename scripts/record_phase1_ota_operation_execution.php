<?php
declare(strict_types=1);

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
        'diagnosis-id' => '',
        'diagnosis_id' => '',
        'action-index' => '',
        'action_index' => '',
        'action-item-id' => '',
        'output' => '',
        'diagnosis-output' => '',
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
        $options[$key] = trim($value);
    }
    $options['execute'] = $options['execute'] === true
        || in_array(strtolower(trim((string)$options['execute'])), ['1', 'true', 'yes', 'on'], true);

    if ((string)$options['system-hotel-id'] === '' && (string)$options['system_hotel_id'] !== '') {
        $options['system-hotel-id'] = (string)$options['system_hotel_id'];
    }
    if ((string)$options['hotel-id'] === '' && (string)$options['hotel_id'] !== '') {
        $options['hotel-id'] = (string)$options['hotel_id'];
    }
    if ((string)$options['diagnosis-id'] === '' && (string)$options['diagnosis_id'] !== '') {
        $options['diagnosis-id'] = (string)$options['diagnosis_id'];
    }
    if ((string)$options['action-index'] === '' && (string)$options['action_index'] !== '') {
        $options['action-index'] = (string)$options['action_index'];
    }
    unset($options['system_hotel_id'], $options['hotel_id'], $options['diagnosis_id'], $options['action_index']);

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

    $hasDiagnosisId = (string)$options['diagnosis-id'] !== '';
    $hasActionIndex = (string)$options['action-index'] !== '';
    if ($hasDiagnosisId !== $hasActionIndex) {
        throw new InvalidArgumentException('--diagnosis-id and --action-index must be provided together.');
    }
    if ($hasDiagnosisId) {
        if (!is_numeric($options['diagnosis-id']) || (int)$options['diagnosis-id'] <= 0) {
            throw new InvalidArgumentException('Invalid --diagnosis-id, expected a positive integer.');
        }
        if (!is_numeric($options['action-index']) || (int)$options['action-index'] < 0) {
            throw new InvalidArgumentException('Invalid --action-index, expected a non-negative integer.');
        }
        $options['diagnosis-id'] = (int)$options['diagnosis-id'];
        $options['action-index'] = (int)$options['action-index'];
    } else {
        $options['diagnosis-id'] = null;
        $options['action-index'] = null;
    }

    return $options;
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

function phase1_operation_execution_entry(): string
{
    return '/api/agent/ota-diagnoses/:id/actions/:actionIndex/execution-intent';
}

function phase1_operation_table_exists(string $table): bool
{
    return Db::query("SHOW TABLES LIKE '" . addslashes($table) . "'") !== [];
}

/**
 * @param array<string, mixed> $row
 */
function phase1_operation_intent_matches_action(array $row, int $actionIndex, string $actionItemId): bool
{
    $evidence = json_decode((string)($row['evidence_json'] ?? ''), true);
    $evidence = is_array($evidence) ? $evidence : [];
    if ((int)($evidence['action_index'] ?? -1) !== $actionIndex) {
        return false;
    }
    if ($actionItemId === '') {
        return true;
    }
    $storedActionItemId = trim((string)($evidence['action_item_id'] ?? ''));
    return $storedActionItemId !== ''
        && hash_equals($actionItemId, $storedActionItemId);
}

try {
    $options = phase1_operation_parse_args($argv);
    if (($options['execute'] ?? false) === true) {
        throw new RuntimeException(
            'direct_operation_write_disabled: use authenticated POST '
            . phase1_operation_execution_entry()
            . ' with a saved OTA diagnosis action, assignee, due_at, and review_at'
        );
    }
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
    $diagnosisId = is_int($options['diagnosis-id']) ? $options['diagnosis-id'] : 0;
    $actionIndex = is_int($options['action-index']) ? $options['action-index'] : -1;
    $lookupRequested = $diagnosisId > 0 && $actionIndex >= 0;
    $operationTables = [
        'operation_execution_intents' => phase1_operation_table_exists('operation_execution_intents'),
        'operation_execution_tasks' => phase1_operation_table_exists('operation_execution_tasks'),
        'operation_execution_evidence' => phase1_operation_table_exists('operation_execution_evidence'),
    ];
    $lookupStatus = $lookupRequested ? 'not_found' : 'identity_required';
    $intentRow = null;
    if ($lookupRequested && !$operationTables['operation_execution_intents']) {
        $lookupStatus = 'intent_table_missing';
    } elseif ($lookupRequested) {
        $candidates = Db::name('operation_execution_intents')
            ->where('source_module', 'ota_diagnosis_saved')
            ->where('source_record_id', $diagnosisId)
            ->where('hotel_id', (int)$options['system-hotel-id'])
            ->where('platform', (string)$options['platform'])
            ->where('date_start', (string)$options['date'])
            ->where('date_end', (string)$options['date'])
            ->whereNull('deleted_at')
            ->order('id', 'desc')
            ->select()
            ->toArray();
        foreach ($candidates as $candidate) {
            if (is_array($candidate) && phase1_operation_intent_matches_action(
                $candidate,
                $actionIndex,
                trim((string)$options['action-item-id'])
            )) {
                $intentRow = $candidate;
                $lookupStatus = 'exact_match';
                break;
            }
        }
    }
    $intentId = is_array($intentRow) ? (int)($intentRow['id'] ?? 0) : 0;
    $taskCount = $intentId > 0 && $operationTables['operation_execution_tasks']
        ? (int)Db::name('operation_execution_tasks')->where('intent_id', $intentId)->whereNull('deleted_at')->count()
        : ($operationTables['operation_execution_tasks'] ? 0 : null);
    $evidenceCount = $operationTables['operation_execution_tasks']
        && $operationTables['operation_execution_evidence']
        ? 0
        : null;
    if ($intentId > 0
        && is_int($taskCount)
        && $taskCount > 0
        && $operationTables['operation_execution_evidence']
    ) {
        $taskIds = Db::name('operation_execution_tasks')
            ->where('intent_id', $intentId)
            ->whereNull('deleted_at')
            ->column('id');
        if ($taskIds !== []) {
            $evidenceCount = (int)Db::name('operation_execution_evidence')
                ->whereIn('task_id', array_map('intval', $taskIds))
                ->whereNull('deleted_at')
                ->count();
        }
    }

    $preview = [
        'status' => 'preview',
        'mode' => 'read_only',
        'scope' => [
            'date' => (string)$options['date'],
            'platform' => (string)$options['platform'],
            'system_hotel_id' => (int)$options['system-hotel-id'],
            'hotel_id' => (string)$options['hotel-id'],
            'metric_scope' => 'ota_channel',
        ],
        'target_date_traffic_rows' => $targetRows,
        'source_row_id' => (int)($row['id'] ?? 0),
        'traffic_field_fact_status' => (string)($factSummary['status'] ?? 'unknown'),
        'writes_database' => false,
        'writes_files' => false,
        'creates_execution_intent' => false,
        'approves_execution_intent' => false,
        'executes_operation_task' => false,
        'records_execution_evidence' => false,
        'records_review_or_roi' => false,
        'closure_claim_allowed' => false,
        'proof_status' => 'not_execution_proof',
        'existing_flow_metadata' => [
            'lookup_status' => $lookupStatus,
            'exact_identity_verified' => $lookupStatus === 'exact_match',
            'identity' => [
                'source_module' => 'ota_diagnosis_saved',
                'source_record_id' => $diagnosisId > 0 ? $diagnosisId : null,
                'action_index' => $actionIndex >= 0 ? $actionIndex : null,
                'action_item_id' => trim((string)$options['action-item-id']) !== ''
                    ? trim((string)$options['action-item-id'])
                    : null,
            ],
            'table_availability' => $operationTables,
            'intent_id' => $intentId > 0 ? $intentId : null,
            'intent_status' => is_array($intentRow) ? (string)($intentRow['status'] ?? 'unknown') : null,
            'task_count' => $taskCount,
            'evidence_count' => $evidenceCount,
        ],
        'pending_approval_request_status' => $intentId > 0
            ? 'existing_intent_requires_human_status_review'
            : ($lookupRequested ? 'requires_authenticated_api_validation' : 'requires_saved_diagnosis_identity'),
        'execution_entry' => phase1_operation_execution_entry(),
        'required_execution_inputs' => [
            'saved OTA diagnosis record id',
            'execution-ready action index with non-derived OTA evidence refs',
            'authenticated administrator session',
            'authorized assignee_id with operation.execute permission',
            'due_at',
            'review_at',
        ],
        'next_action' => 'Open the saved OTA diagnosis in Agent Center and submit its execution-ready action for human approval.',
        'legacy_output_requested' => (string)$options['output'] !== '' || (string)$options['diagnosis-output'] !== '',
        'legacy_output_written' => false,
    ];

    echo json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    $issueCode = str_starts_with($e->getMessage(), 'direct_operation_write_disabled')
        ? 'direct_operation_write_disabled'
        : 'phase1_operation_execution_record_failed';
    echo json_encode([
        'status' => 'failed',
        'issue' => [
            'code' => $issueCode,
            'message' => $e->getMessage(),
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}
