<?php
declare(strict_types=1);

use app\model\User;
use think\App;

require dirname(__DIR__) . '/vendor/autoload.php';

$hotelId = 80;
$startDate = '2026-08-01';
$endDate = '2026-08-01';
$platform = 'all_ota';
$endpoint = 'http://127.0.0.1:8080/api/agent/ota-diagnosis';

foreach ($argv as $argument) {
    if (str_starts_with($argument, '--hotel-id=')) {
        $hotelId = (int)substr($argument, strlen('--hotel-id='));
    } elseif (str_starts_with($argument, '--start-date=')) {
        $startDate = trim(substr($argument, strlen('--start-date=')));
    } elseif (str_starts_with($argument, '--end-date=')) {
        $endDate = trim(substr($argument, strlen('--end-date=')));
    } elseif (str_starts_with($argument, '--platform=')) {
        $platform = strtolower(trim(substr($argument, strlen('--platform='))));
    } elseif (str_starts_with($argument, '--endpoint=')) {
        $endpoint = trim(substr($argument, strlen('--endpoint=')));
    }
}

$endpointParts = parse_url($endpoint);

if ($hotelId <= 0
    || preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) !== 1
    || preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) !== 1
    || strtotime($startDate) > strtotime($endDate)
    || $platform !== 'all_ota'
    || !is_array($endpointParts)
    || ($endpointParts['scheme'] ?? '') !== 'http'
    || !in_array((string)($endpointParts['host'] ?? ''), ['127.0.0.1', 'localhost'], true)
    || ($endpointParts['path'] ?? '') !== '/api/agent/ota-diagnosis'
) {
    throw new RuntimeException('Invalid OTA diagnosis smoke scope.');
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
    'start_date' => $startDate,
    'end_date' => $endDate,
];
$token = 'ota_diagnosis_smoke_' . bin2hex(random_bytes(18));
try {
    cache('token_' . $token, [
        'user_id' => (int)$user->id,
        'created_at' => time(),
        'auth_version' => $user->authSessionVersion(),
    ], 300);

    $postBody = json_encode($scope + [
        'analysis_type' => 'all',
        'analysis_mode' => 'rules_only',
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $postCurl = curl_init($endpoint);
    if ($postCurl === false) {
        throw new RuntimeException('Unable to initialize local diagnosis HTTP client.');
    }
    curl_setopt_array($postCurl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_POSTFIELDS => $postBody,
    ]);
    $savedBody = curl_exec($postCurl);
    $savedHttpStatus = (int)curl_getinfo($postCurl, CURLINFO_RESPONSE_CODE);
    $savedCurlError = curl_error($postCurl);
    curl_close($postCurl);
    if (!is_string($savedBody) || $savedBody === '') {
        throw new RuntimeException('Local diagnosis POST returned no body: ' . $savedCurlError);
    }
    $savedPayload = json_decode($savedBody, true, 512, JSON_THROW_ON_ERROR);
    if ($savedHttpStatus !== 200 || (int)($savedPayload['code'] ?? 0) !== 200) {
        throw new RuntimeException(sprintf(
            'Save failed: HTTP %d response code %d: %s',
            $savedHttpStatus,
            (int)($savedPayload['code'] ?? 0),
            (string)($savedPayload['msg'] ?? $savedPayload['message'] ?? 'unknown error')
        ));
    }
    $saved = is_array($savedPayload['data'] ?? null) ? $savedPayload['data'] : [];
    $savedRecord = is_array($saved['saved_record'] ?? null) ? $saved['saved_record'] : [];
    $recordId = (int)($savedRecord['id'] ?? 0);
    if ($recordId <= 0
        || ($savedRecord['saved'] ?? false) !== true
        || ($savedRecord['readback_verified'] ?? false) !== true
    ) {
        throw new RuntimeException('Save response did not prove persisted exact readback.');
    }
    if (($saved['analysis_runtime']['model_called'] ?? null) !== false
        || ($saved['analysis_runtime']['use_rules_only'] ?? null) !== true
    ) {
        throw new RuntimeException('Rules-only acceptance did not prove model_called=false.');
    }

    $readEndpoint = $endpoint . '?' . http_build_query($scope);
    $getCurl = curl_init($readEndpoint);
    if ($getCurl === false) {
        throw new RuntimeException('Unable to initialize local diagnosis readback HTTP client.');
    }
    curl_setopt_array($getCurl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
        ],
    ]);
    $readBody = curl_exec($getCurl);
    $readHttpStatus = (int)curl_getinfo($getCurl, CURLINFO_RESPONSE_CODE);
    $readCurlError = curl_error($getCurl);
    curl_close($getCurl);
    if (!is_string($readBody) || $readBody === '') {
        throw new RuntimeException('Local diagnosis GET returned no body: ' . $readCurlError);
    }
    $readPayload = json_decode($readBody, true, 512, JSON_THROW_ON_ERROR);
    $readData = is_array($readPayload['data'] ?? null) ? $readPayload['data'] : [];
    $diagnosis = is_array($readData['diagnosis'] ?? null) ? $readData['diagnosis'] : [];
    $readRecord = is_array($diagnosis['saved_record'] ?? null) ? $diagnosis['saved_record'] : [];
    if ($readHttpStatus !== 200
        || (int)($readPayload['code'] ?? 0) !== 200
        || (string)($readData['status'] ?? '') !== 'ready'
        || (int)($readRecord['id'] ?? 0) !== $recordId
        || ($readRecord['readback_verified'] ?? false) !== true
    ) {
        throw new RuntimeException('Exact GET readback failed for saved record #' . $recordId . '.');
    }
    if (($diagnosis['analysis_runtime']['model_called'] ?? null) !== false
        || strtolower((string)($diagnosis['platform'] ?? '')) !== $platform
        || (int)($diagnosis['hotel']['id'] ?? $diagnosis['hotel_id'] ?? 0) !== $hotelId
        || (array)($diagnosis['requested_date_range'] ?? []) !== [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]
    ) {
        throw new RuntimeException('Readback identity or no-LLM contract mismatch for saved record #' . $recordId . '.');
    }

    $decisionRoute = is_array($diagnosis['decision_route'] ?? null) ? $diagnosis['decision_route'] : [];
    $routeStages = [];
    foreach ((array)($decisionRoute['stages'] ?? []) as $stage) {
        if (is_array($stage) && trim((string)($stage['key'] ?? '')) !== '') {
            $routeStages[(string)$stage['key']] = $stage;
        }
    }
    $evidenceStage = is_array($routeStages['verified_evidence'] ?? null)
        ? $routeStages['verified_evidence']
        : [];
    $modelStage = is_array($routeStages['model'] ?? null) ? $routeStages['model'] : [];
    if (($diagnosis['coverage']['complete'] ?? false) !== true
        || (string)($diagnosis['decision_status'] ?? '') !== 'blocked_by_data'
        || (string)($decisionRoute['final_status'] ?? '') !== 'blocked'
        || (string)($evidenceStage['status'] ?? '') !== 'blocked'
        || (string)($evidenceStage['status_label'] ?? '') !== '关键指标不完整'
        || count((array)($evidenceStage['refs'] ?? [])) <= 0
        || (string)($modelStage['status'] ?? '') !== 'skipped'
        || (string)($modelStage['status_label'] ?? '') !== '未调用'
    ) {
        throw new RuntimeException('All-OTA blocked decision route truth contract mismatch for saved record #' . $recordId . '.');
    }

    $platforms = [];
    foreach (['ctrip', 'meituan'] as $scopedPlatform) {
        $coverage = is_array($diagnosis['coverage']['per_platform'][$scopedPlatform] ?? null)
            ? $diagnosis['coverage']['per_platform'][$scopedPlatform]
            : [];
        $summary = is_array($diagnosis['platform_summaries'][$scopedPlatform] ?? null)
            ? $diagnosis['platform_summaries'][$scopedPlatform]
            : [];
        $decisionEligibleRows = (int)($coverage['decision_eligible_row_count'] ?? 0);
        $evidenceRefCount = count((array)($coverage['evidence_refs'] ?? []));
        if ((string)($coverage['platform'] ?? '') !== $scopedPlatform
            || (int)($coverage['hotel_id'] ?? 0) !== $hotelId
            || (array)($coverage['requested_date_range'] ?? []) !== [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]
            || (string)($summary['platform'] ?? '') !== $scopedPlatform
            || $decisionEligibleRows <= 0
            || $evidenceRefCount <= 0
        ) {
            throw new RuntimeException('Saved diagnosis lost decision-eligible ' . $scopedPlatform . ' facts.');
        }
        $platforms[$scopedPlatform] = [
            'coverage_status' => (string)($coverage['status'] ?? ''),
            'decision_eligible_rows' => $decisionEligibleRows,
            'evidence_ref_count' => $evidenceRefCount,
        ];
    }

    echo json_encode([
        'status' => 'passed',
        'transport' => 'local_http_route',
        'post_http_status' => $savedHttpStatus,
        'get_http_status' => $readHttpStatus,
        'record_id' => $recordId,
        'scope' => $scope,
        'record_status' => (string)($diagnosis['record_status'] ?? ''),
        'decision_status' => (string)($diagnosis['decision_status'] ?? ''),
        'decision_route_final_status' => (string)($decisionRoute['final_status'] ?? ''),
        'evidence_stage_status_label' => (string)($evidenceStage['status_label'] ?? ''),
        'model_stage_status_label' => (string)($modelStage['status_label'] ?? ''),
        'coverage_complete' => (bool)($diagnosis['coverage']['complete'] ?? false),
        'analysis_mode' => (string)($diagnosis['analysis_runtime']['mode'] ?? ''),
        'model_called' => (bool)($diagnosis['analysis_runtime']['model_called'] ?? true),
        'saved' => (bool)($readRecord['saved'] ?? false),
        'readback_verified' => (bool)($readRecord['readback_verified'] ?? false),
        'platforms' => $platforms,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
} finally {
    cache('token_' . $token, null);
}
