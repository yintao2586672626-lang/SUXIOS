<?php
declare(strict_types=1);

use app\model\User;
use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

$hotelId = 80;
$businessDate = '2026-04-20';
$platform = 'all_ota';
$endpoint = 'http://127.0.0.1:8080/api/agent/ota-diagnosis';

foreach ($argv as $argument) {
    if (str_starts_with($argument, '--hotel-id=')) {
        $hotelId = (int)substr($argument, strlen('--hotel-id='));
    } elseif (str_starts_with($argument, '--business-date=')) {
        $businessDate = trim(substr($argument, strlen('--business-date=')));
    } elseif (str_starts_with($argument, '--endpoint=')) {
        $endpoint = trim(substr($argument, strlen('--endpoint=')));
    }
}

$endpointParts = parse_url($endpoint);
if ($hotelId <= 0
    || preg_match('/^\d{4}-\d{2}-\d{2}$/', $businessDate) !== 1
    || !is_array($endpointParts)
    || ($endpointParts['scheme'] ?? '') !== 'http'
    || !in_array((string)($endpointParts['host'] ?? ''), ['127.0.0.1', 'localhost'], true)
    || ($endpointParts['path'] ?? '') !== '/api/agent/ota-diagnosis'
) {
    throw new RuntimeException('Invalid missing-facts diagnosis acceptance scope.');
}

/** @return array{http_status:int,payload:array<string,mixed>} */
function requestLocalJson(string $url, string $token, string $method, ?array $body = null): array
{
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

$scope = [
    'hotel_id' => $hotelId,
    'platform' => $platform,
    'start_date' => $businessDate,
    'end_date' => $businessDate,
];
$token = 'ota_missing_facts_smoke_' . bin2hex(random_bytes(18));

try {
    cache('token_' . $token, [
        'user_id' => (int)$user->id,
        'created_at' => time(),
        'auth_version' => $user->authSessionVersion(),
    ], 300);

    $savedResponse = requestLocalJson($endpoint, $token, 'POST', $scope + [
        'analysis_type' => 'all',
        'analysis_mode' => 'rules_only',
    ]);
    $savedPayload = $savedResponse['payload'];
    $saved = is_array($savedPayload['data'] ?? null) ? $savedPayload['data'] : [];
    $savedRecord = is_array($saved['saved_record'] ?? null) ? $saved['saved_record'] : [];
    $recordId = (int)($savedRecord['id'] ?? 0);
    if ($savedResponse['http_status'] !== 200
        || (int)($savedPayload['code'] ?? 0) !== 200
        || $recordId <= 0
        || ($savedRecord['saved'] ?? false) !== true
        || ($savedRecord['readback_verified'] ?? false) !== true
    ) {
        throw new RuntimeException('POST did not prove exact persisted readback for a blocked diagnosis.');
    }

    $readResponse = requestLocalJson($endpoint . '?' . http_build_query($scope), $token, 'GET');
    $readPayload = $readResponse['payload'];
    $readData = is_array($readPayload['data'] ?? null) ? $readPayload['data'] : [];
    $diagnosis = is_array($readData['diagnosis'] ?? null) ? $readData['diagnosis'] : [];
    $readRecord = is_array($diagnosis['saved_record'] ?? null) ? $diagnosis['saved_record'] : [];
    $decisionClosure = is_array($diagnosis['decision_closure'] ?? null) ? $diagnosis['decision_closure'] : [];
    $suggestedActions = is_array($decisionClosure['suggested_actions'] ?? null)
        ? $decisionClosure['suggested_actions']
        : [];

    $identityMatches = strtolower((string)($diagnosis['platform'] ?? '')) === $platform
        && (int)($diagnosis['hotel']['id'] ?? $diagnosis['hotel_id'] ?? 0) === $hotelId
        && (array)($diagnosis['requested_date_range'] ?? []) === [
            'start_date' => $businessDate,
            'end_date' => $businessDate,
        ];
    $actionsEmpty = (array)($diagnosis['action_items'] ?? []) === []
        && (array)($diagnosis['recommended_actions'] ?? []) === []
        && (array)($suggestedActions['items'] ?? []) === [];
    $linkedIntentCount = (int)Db::name('operation_execution_intents')
        ->where('source_record_id', $recordId)
        ->whereLike('source_module', 'ota_diagnosis%')
        ->count();

    if ($readResponse['http_status'] !== 200
        || (int)($readPayload['code'] ?? 0) !== 200
        || (int)($readRecord['id'] ?? 0) !== $recordId
        || ($readRecord['readback_verified'] ?? false) !== true
        || !$identityMatches
        || (string)($diagnosis['workflow_status'] ?? '') !== 'blocked_by_missing_facts'
        || (string)($diagnosis['decision_status'] ?? '') !== 'blocked_by_missing_facts'
        || (string)($decisionClosure['status'] ?? '') !== 'blocked_by_missing_facts'
        || (array)($diagnosis['missing_fact_codes'] ?? []) === []
        || !$actionsEmpty
        || (array)($diagnosis['metrics'] ?? []) !== []
        || ($diagnosis['analysis_runtime']['model_called'] ?? null) !== false
        || $linkedIntentCount !== 0
    ) {
        throw new RuntimeException('Saved missing-facts diagnosis violated the blocked/readback/no-action contract.');
    }

    echo json_encode([
        'status' => 'passed',
        'transport' => 'local_http_route',
        'record_id' => $recordId,
        'scope' => $scope,
        'workflow_status' => (string)$diagnosis['workflow_status'],
        'decision_status' => (string)$diagnosis['decision_status'],
        'missing_fact_codes' => array_values((array)$diagnosis['missing_fact_codes']),
        'actions_empty' => $actionsEmpty,
        'metrics_empty' => true,
        'model_called' => false,
        'linked_execution_intent_count' => $linkedIntentCount,
        'saved' => true,
        'readback_verified' => true,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
} finally {
    cache('token_' . $token, null);
}
