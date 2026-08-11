<?php
declare(strict_types=1);

use app\model\User;
use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

$recordId = 0;
$actionIndex = 0;
$endpointBase = 'http://127.0.0.1:8080/api';
$dueAt = '';
$reviewAt = '';

foreach ($argv as $argument) {
    if (str_starts_with($argument, '--record-id=')) {
        $recordId = (int)substr($argument, strlen('--record-id='));
    } elseif (str_starts_with($argument, '--action-index=')) {
        $actionIndex = (int)substr($argument, strlen('--action-index='));
    } elseif (str_starts_with($argument, '--due-at=')) {
        $dueAt = trim(substr($argument, strlen('--due-at=')));
    } elseif (str_starts_with($argument, '--review-at=')) {
        $reviewAt = trim(substr($argument, strlen('--review-at=')));
    } elseif (str_starts_with($argument, '--endpoint-base=')) {
        $endpointBase = rtrim(trim(substr($argument, strlen('--endpoint-base='))), '/');
    }
}

$endpointParts = parse_url($endpointBase);
if ($recordId <= 0
    || $actionIndex < 0
    || !is_array($endpointParts)
    || ($endpointParts['scheme'] ?? '') !== 'http'
    || !in_array((string)($endpointParts['host'] ?? ''), ['127.0.0.1', 'localhost'], true)
    || ($endpointParts['path'] ?? '') !== '/api'
) {
    throw new RuntimeException('Invalid pending-approval intent acceptance scope.');
}

/** @return array{http_status:int,payload:array<string,mixed>} */
function pendingIntentRequest(
    string $url,
    string $token,
    string $method,
    ?array $body = null
): array {
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Unable to initialize local HTTP client.');
    }
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
    ];
    if ($method === 'POST') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = json_encode($body ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
    curl_setopt_array($curl, $options);
    $responseBody = curl_exec($curl);
    $httpStatus = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);
    if (!is_string($responseBody) || $responseBody === '') {
        throw new RuntimeException('Local HTTP response was empty: ' . $curlError);
    }
    $payload = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new RuntimeException('Local HTTP response was not a JSON object.');
    }
    return ['http_status' => $httpStatus, 'payload' => $payload];
}

$app = new App(dirname(__DIR__));
$app->initialize();
$log = Db::name('agent_logs')
    ->where('id', $recordId)
    ->where('action', 'ota_diagnosis')
    ->find();
if (!is_array($log)) {
    throw new RuntimeException('Saved OTA diagnosis was not found.');
}
$context = json_decode((string)($log['context_data'] ?? ''), true);
$context = is_array($context) ? $context : [];
$diagnosis = is_array($context['diagnosis_result'] ?? null) ? $context['diagnosis_result'] : [];
$actionItems = array_values(array_filter((array)($diagnosis['action_items'] ?? []), 'is_array'));
$action = $actionItems[$actionIndex] ?? null;
if (($diagnosis['record_status'] ?? 'active') !== 'active'
    || ($diagnosis['decision_status'] ?? '') !== 'action_required'
    || !is_array($action)
    || ($action['execution_ready'] ?? false) !== true
    || ($action['can_request_execution_intent'] ?? false) !== true
) {
    throw new RuntimeException('Saved diagnosis action is not eligible for a pending approval intent.');
}

$hotelId = (int)($diagnosis['hotel']['id'] ?? $log['hotel_id'] ?? 0);
$platform = strtolower(trim((string)($diagnosis['platform'] ?? '')));
$dateRange = is_array($diagnosis['requested_date_range'] ?? null)
    ? $diagnosis['requested_date_range']
    : (is_array($diagnosis['date_range'] ?? null) ? $diagnosis['date_range'] : []);
$businessDate = trim((string)($dateRange['end_date'] ?? $dateRange['start_date'] ?? ''));
$actionId = trim((string)($action['id'] ?? ''));
$metricKey = trim((string)($action['metric_key'] ?? $action['expected_metric'] ?? ''));
$metricSemantic = is_array($action['metric_semantic'] ?? null) ? $action['metric_semantic'] : [];
$evidenceRefs = array_values(array_filter(array_map('strval', (array)($action['evidence_refs'] ?? []))));
if ($hotelId <= 0
    || !in_array($platform, ['ctrip', 'meituan'], true)
    || preg_match('/^\d{4}-\d{2}-\d{2}$/', $businessDate) !== 1
    || $actionId === ''
    || $metricKey === ''
    || $evidenceRefs === []
) {
    throw new RuntimeException('Saved diagnosis action identity or evidence is incomplete.');
}
if ($metricKey === 'list_exposure'
    && ($platform !== 'ctrip'
        || ($metricSemantic['contract_version'] ?? '') !== 'ota_metric_semantic_binding.v2'
        || ($metricSemantic['source_endpoint_family'] ?? '') !== 'ctrip_query_flow_transform_new_v1'
        || (array)($metricSemantic['source_endpoint_ids'] ?? []) !== ['business_flow_transform', 'traffic_flow_transform']
        || ($metricSemantic['semantic_key'] ?? '') !== 'ctrip_datacenter_list_exposure_uv'
        || ($metricSemantic['unit'] ?? '') !== 'unique_users'
        || ($metricSemantic['value_type'] ?? '') !== 'non_negative_integer'
        || ($metricSemantic['field_fact_required'] ?? false) !== true)
) {
    throw new RuntimeException('list_exposure pending intent requires the frozen Ctrip unique-user semantic binding.');
}
if ($metricKey === 'list_exposure') {
    $allowedEndpointIds = (array)$metricSemantic['source_endpoint_ids'];
    $factReady = false;
    foreach ((array)($diagnosis['evidence_sources'] ?? []) as $source) {
        if (!is_array($source)
            || !in_array((string)($source['ref'] ?? ''), $evidenceRefs, true)
            || !in_array((string)($source['source_endpoint_id'] ?? ''), $allowedEndpointIds, true)
        ) {
            continue;
        }
        $statuses = is_array($source['metric_fact_statuses'] ?? null)
            ? $source['metric_fact_statuses']
            : [];
        $fact = is_array($statuses['list_exposure'] ?? null) ? $statuses['list_exposure'] : [];
        if (($fact['status'] ?? '') === 'ready'
            && (array)($fact['missing_requested_metric_keys'] ?? ['list_exposure']) === []
            && in_array((string)($fact['source_endpoint_id'] ?? ''), $allowedEndpointIds, true)
            && (string)($fact['source_key'] ?? '') === 'listExposure'
            && trim((string)($fact['source_path'] ?? '')) !== ''
        ) {
            $factReady = true;
            break;
        }
    }
    if (!$factReady) {
        throw new RuntimeException('list_exposure pending intent requires exact flow-transform field-fact evidence.');
    }
}

if ($dueAt === '') {
    $dueAt = $businessDate . ' 18:00:00';
}
if ($reviewAt === '') {
    $reviewAt = (new DateTimeImmutable($businessDate))->modify('+1 day')->format('Y-m-d') . ' 10:00:00';
}
foreach ([$dueAt, $reviewAt] as $dateTime) {
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dateTime);
    if (!$parsed || $parsed->format('Y-m-d H:i:s') !== $dateTime) {
        throw new RuntimeException('due-at and review-at must use YYYY-MM-DD HH:MM:SS.');
    }
}
if ($reviewAt < $dueAt) {
    throw new RuntimeException('Review time cannot precede due time.');
}

$user = null;
foreach (User::where('status', 1)->order('id', 'asc')->limit(100)->select() as $candidate) {
    if ($candidate->isSuperAdmin()) {
        $user = $candidate;
        break;
    }
}
if (!$user) {
    throw new RuntimeException('An active super administrator is required for local acceptance.');
}
$assigneeId = (int)$user->id;
$token = 'ota_pending_intent_smoke_' . bin2hex(random_bytes(18));

try {
    cache('token_' . $token, [
        'user_id' => $assigneeId,
        'created_at' => time(),
        'auth_version' => $user->authSessionVersion(),
    ], 300);

    $createResponse = pendingIntentRequest(
        $endpointBase . '/agent/ota-diagnoses/' . $recordId . '/actions/' . $actionIndex . '/execution-intent',
        $token,
        'POST',
        [
            'assignee_id' => $assigneeId,
            'due_at' => $dueAt,
            'review_at' => $reviewAt,
        ]
    );
    $createPayload = $createResponse['payload'];
    $createData = is_array($createPayload['data'] ?? null) ? $createPayload['data'] : [];
    $createdIntent = is_array($createData['execution_intent'] ?? null)
        ? $createData['execution_intent']
        : [];
    $intentId = (int)($createdIntent['id'] ?? 0);
    if ($createResponse['http_status'] !== 200
        || (int)($createPayload['code'] ?? 0) !== 200
        || $intentId <= 0
        || (string)($createdIntent['status'] ?? '') !== 'pending_approval'
        || trim((string)($createdIntent['blocked_reason'] ?? '')) !== ''
    ) {
        throw new RuntimeException('Intent POST did not return an unblocked pending-approval record.');
    }

    $readResponse = pendingIntentRequest(
        $endpointBase . '/operation/execution-intents/' . $intentId,
        $token,
        'GET'
    );
    $readPayload = $readResponse['payload'];
    $intent = is_array($readPayload['data'] ?? null) ? $readPayload['data'] : [];
    $targetValue = is_array($intent['target_value'] ?? null) ? $intent['target_value'] : [];
    $evidence = is_array($intent['evidence'] ?? null) ? $intent['evidence'] : [];
    $currentValue = is_array($intent['current_value'] ?? null) ? $intent['current_value'] : [];
    $workflowSchedule = is_array($targetValue['workflow_schedule'] ?? null)
        ? $targetValue['workflow_schedule']
        : [];
    if ($readResponse['http_status'] !== 200
        || (int)($readPayload['code'] ?? 0) !== 200
        || (int)($intent['id'] ?? 0) !== $intentId
        || (int)($intent['hotel_id'] ?? 0) !== $hotelId
        || strtolower((string)($intent['platform'] ?? '')) !== $platform
        || (string)($intent['source_module'] ?? '') !== 'ota_diagnosis_saved'
        || (int)($intent['source_record_id'] ?? 0) !== $recordId
        || (string)($intent['status'] ?? '') !== 'pending_approval'
        || (int)($intent['approved_by'] ?? 0) !== 0
        || !empty($intent['approved_at'])
        || (string)($intent['expected_metric'] ?? '') !== $metricKey
        || !array_key_exists('expected_delta', $intent)
        || $intent['expected_delta'] !== null
        || (string)($evidence['action_item_id'] ?? '') !== $actionId
        || (array)($evidence['evidence_refs'] ?? []) !== $evidenceRefs
        || (int)($workflowSchedule['assignee_id'] ?? 0) !== $assigneeId
        || (string)($workflowSchedule['due_at'] ?? '') !== $dueAt
        || (string)($workflowSchedule['review_at'] ?? '') !== $reviewAt
    ) {
        throw new RuntimeException('Pending intent strict GET readback did not match the saved diagnosis action.');
    }
    if ($metricKey === 'list_exposure') {
        $targetSemantic = is_array($targetValue['metric_semantic'] ?? null)
            ? $targetValue['metric_semantic']
            : [];
        $evidenceSemantic = is_array($evidence['metric_semantic'] ?? null)
            ? $evidence['metric_semantic']
            : [];
        $baseline = $currentValue['list_exposure'] ?? null;
        $allowedEndpointIds = (array)($metricSemantic['source_endpoint_ids'] ?? []);
        $factReady = false;
        foreach ((array)($evidence['evidence_sources'] ?? []) as $source) {
            if (!is_array($source) || !in_array((string)($source['ref'] ?? ''), $evidenceRefs, true)) {
                continue;
            }
            $statuses = is_array($source['metric_fact_statuses'] ?? null)
                ? $source['metric_fact_statuses']
                : [];
            $fact = is_array($statuses['list_exposure'] ?? null) ? $statuses['list_exposure'] : [];
            if (($fact['status'] ?? '') === 'ready'
                && (array)($fact['missing_requested_metric_keys'] ?? ['list_exposure']) === []
                && in_array((string)($source['source_endpoint_id'] ?? ''), $allowedEndpointIds, true)
                && in_array((string)($fact['source_endpoint_id'] ?? ''), $allowedEndpointIds, true)
                && (string)($fact['source_key'] ?? '') === 'listExposure'
                && trim((string)($fact['source_path'] ?? '')) !== ''
            ) {
                $factReady = true;
                break;
            }
        }
        if ($targetSemantic !== $metricSemantic
            || $evidenceSemantic !== $metricSemantic
            || !is_numeric($baseline)
            || (float)$baseline < 0
            || floor((float)$baseline) !== (float)$baseline
            || !$factReady
        ) {
            throw new RuntimeException('Pending list_exposure intent semantic, integer baseline, or field-fact readback drifted.');
        }
    }

    $taskCount = (int)Db::name('operation_execution_tasks')
        ->where('intent_id', $intentId)
        ->whereNull('deleted_at')
        ->count();
    if ($taskCount !== 0) {
        throw new RuntimeException('A task was created before human approval.');
    }

    $updatedLog = Db::name('agent_logs')->where('id', $recordId)->find();
    $updatedContext = json_decode((string)($updatedLog['context_data'] ?? ''), true);
    $updatedSnapshot = is_array($updatedContext['diagnosis_result'] ?? null)
        ? $updatedContext['diagnosis_result']
        : [];
    $updatedActions = array_values(array_filter((array)($updatedSnapshot['action_items'] ?? []), 'is_array'));
    $updatedAction = $updatedActions[$actionIndex] ?? [];
    if ((int)($updatedAction['execution_intent_id'] ?? 0) !== $intentId
        || (string)($updatedAction['execution_status'] ?? '') !== 'pending_approval'
    ) {
        throw new RuntimeException('Saved diagnosis did not strictly read back its pending intent link.');
    }

    echo json_encode([
        'status' => 'passed',
        'transport' => 'local_http_route',
        'diagnosis_record_id' => $recordId,
        'action_index' => $actionIndex,
        'action_item_id' => $actionId,
        'hotel_id' => $hotelId,
        'platform' => $platform,
        'business_date' => $businessDate,
        'metric_key' => $metricKey,
        'semantic_key' => (string)($metricSemantic['semantic_key'] ?? ''),
        'evidence_ref_count' => count($evidenceRefs),
        'intent_id' => $intentId,
        'intent_status' => 'pending_approval',
        'reused_existing_intent' => (bool)($createData['reused_existing_intent'] ?? false),
        'task_count' => 0,
        'approved_by' => 0,
        'approved_at' => null,
        'due_at' => $dueAt,
        'review_at' => $reviewAt,
        'ota_write_performed' => false,
        'readback_verified' => true,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
} finally {
    cache('token_' . $token, null);
}
