<?php
declare(strict_types=1);

use app\model\User;
use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

$hotelId = 80;
$businessDate = date('Y-m-d');
$platform = 'ctrip';
$endpoint = 'http://127.0.0.1:8080/api/agent/ota-diagnosis';

foreach ($argv as $argument) {
    if (str_starts_with($argument, '--hotel-id=')) {
        $hotelId = (int)substr($argument, strlen('--hotel-id='));
    } elseif (str_starts_with($argument, '--business-date=')) {
        $businessDate = trim(substr($argument, strlen('--business-date=')));
    } elseif (str_starts_with($argument, '--platform=')) {
        $platform = strtolower(trim(substr($argument, strlen('--platform='))));
    } elseif (str_starts_with($argument, '--endpoint=')) {
        $endpoint = trim(substr($argument, strlen('--endpoint=')));
    }
}

$endpointParts = parse_url($endpoint);
if ($hotelId <= 0
    || preg_match('/^\d{4}-\d{2}-\d{2}$/', $businessDate) !== 1
    || !in_array($platform, ['ctrip', 'meituan'], true)
    || !is_array($endpointParts)
    || ($endpointParts['scheme'] ?? '') !== 'http'
    || !in_array((string)($endpointParts['host'] ?? ''), ['127.0.0.1', 'localhost'], true)
    || ($endpointParts['path'] ?? '') !== '/api/agent/ota-diagnosis'
) {
    throw new RuntimeException('Invalid single-platform diagnosis acceptance scope.');
}

/** @return array{http_status:int,payload:array<string,mixed>} */
function singlePlatformDiagnosisRequest(
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
$token = 'ota_single_platform_smoke_' . bin2hex(random_bytes(18));

try {
    cache('token_' . $token, [
        'user_id' => (int)$user->id,
        'created_at' => time(),
        'auth_version' => $user->authSessionVersion(),
    ], 300);

    $savedResponse = singlePlatformDiagnosisRequest($endpoint, $token, 'POST', $scope + [
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
        throw new RuntimeException('POST did not prove exact persisted diagnosis readback.');
    }

    $readResponse = singlePlatformDiagnosisRequest(
        $endpoint . '?' . http_build_query($scope),
        $token,
        'GET'
    );
    $readPayload = $readResponse['payload'];
    $readData = is_array($readPayload['data'] ?? null) ? $readPayload['data'] : [];
    $diagnosis = is_array($readData['diagnosis'] ?? null) ? $readData['diagnosis'] : [];
    $readRecord = is_array($diagnosis['saved_record'] ?? null) ? $diagnosis['saved_record'] : [];
    $readRange = is_array($diagnosis['requested_date_range'] ?? null)
        ? $diagnosis['requested_date_range']
        : (is_array($diagnosis['date_range'] ?? null) ? $diagnosis['date_range'] : []);
    $identityMatches = strtolower((string)($diagnosis['platform'] ?? '')) === $platform
        && (int)($diagnosis['hotel']['id'] ?? $diagnosis['hotel_id'] ?? 0) === $hotelId
        && $readRange === [
            'start_date' => $businessDate,
            'end_date' => $businessDate,
        ];
    if ($readResponse['http_status'] !== 200
        || (int)($readPayload['code'] ?? 0) !== 200
        || (int)($readRecord['id'] ?? 0) !== $recordId
        || ($readRecord['readback_verified'] ?? false) !== true
        || !$identityMatches
        || ($diagnosis['analysis_runtime']['model_called'] ?? null) !== false
    ) {
        throw new RuntimeException('GET did not prove exact single-platform diagnosis identity and readback.');
    }

    $storedLog = Db::name('agent_logs')->where('id', $recordId)->find();
    $storedContext = is_array($storedLog) ? ($storedLog['context_data'] ?? []) : [];
    if (is_string($storedContext)) {
        $storedContext = json_decode($storedContext, true, 512, JSON_THROW_ON_ERROR);
    }
    $expectedStorageSchema = $platform === 'ctrip' ? 4 : 2;
    $storedDiagnosis = is_array($storedContext['diagnosis_result'] ?? null)
        ? $storedContext['diagnosis_result']
        : [];
    if (!is_array($storedContext)
        || (int)($storedContext['schema_version'] ?? 0) !== $expectedStorageSchema
        || trim((string)($storedContext['readback_identity_digest'] ?? '')) === ''
        || (int)($storedDiagnosis['saved_record']['id'] ?? 0) !== $recordId
    ) {
        throw new RuntimeException('Database context did not preserve the expected diagnosis schema and identity.');
    }

    $radarAcceptance = null;
    if ($platform === 'ctrip') {
        $savedRadar = is_array($saved['operating_radar'] ?? null) ? $saved['operating_radar'] : [];
        $readRadar = is_array($diagnosis['operating_radar'] ?? null) ? $diagnosis['operating_radar'] : [];
        $expectedDimensionKeys = [
            'information_score',
            'friendliness',
            'quality',
            'welcome',
            'platform_technical_service_fee',
        ];
        $dimensionKeys = array_values(array_map(
            static fn(array $dimension): string => (string)($dimension['key'] ?? ''),
            array_values(array_filter((array)($readRadar['dimensions'] ?? []), 'is_array'))
        ));
        $scopeReadback = is_array($readRadar['scope'] ?? null) ? $readRadar['scope'] : [];
        $scorePolicy = is_array($readRadar['score_policy'] ?? null) ? $readRadar['score_policy'] : [];
        $guards = is_array($readRadar['guards'] ?? null) ? $readRadar['guards'] : [];
        if ($savedRadar === []
            || $readRadar !== $savedRadar
            || (int)($readRadar['schema_version'] ?? 0) !== 2
            || (string)($readRadar['contract_version'] ?? '') !== 'ctrip_operating_radar.v2'
            || (string)($readRadar['knowledge']['truth_profile_version'] ?? '') !== '2026-08-11.4'
            || (int)($scopeReadback['hotel_id'] ?? 0) !== $hotelId
            || (string)($scopeReadback['platform'] ?? '') !== 'ctrip'
            || (string)($scopeReadback['requested_start_date'] ?? '') !== $businessDate
            || (string)($scopeReadback['requested_end_date'] ?? '') !== $businessDate
            || (string)($scopeReadback['source_scope'] ?? '') !== 'ctrip_ota_channel_only'
            || $dimensionKeys !== $expectedDimensionKeys
            || ($scorePolicy['official_score_available'] ?? null) !== false
            || ($scorePolicy['official_weights_available'] ?? null) !== false
            || ($scorePolicy['official_formula_available'] ?? null) !== false
            || !array_key_exists('composite_score', $scorePolicy)
            || $scorePolicy['composite_score'] !== null
        ) {
            throw new RuntimeException('Ctrip operating radar was not saved and read back with its exact five-dimension truth contract.');
        }
        foreach ([
            'decision_safe',
            'task_draft_safe',
            'external_write_authorized',
            'automatic_pricing',
            'automatic_inventory_change',
            'automatic_commission_change',
            'automatic_marketing',
            'automatic_ota_write',
            'automatic_pms_write',
        ] as $guardKey) {
            if (($guards[$guardKey] ?? null) !== false) {
                throw new RuntimeException('Ctrip operating radar guard is not closed: ' . $guardKey);
            }
        }
        $nonBlockedRootRefs = [];
        foreach ((array)($readRadar['dimensions'] ?? []) as $dimension) {
            if (!is_array($dimension)
                || !array_key_exists('official_score', $dimension)
                || $dimension['official_score'] !== null
            ) {
                throw new RuntimeException('Ctrip operating radar exposed an inferred official dimension score.');
            }
            if ((string)($dimension['key'] ?? '') === 'platform_technical_service_fee'
                && in_array('commission_rate', array_map(
                    static fn(array $metric): string => (string)($metric['key'] ?? ''),
                    array_values(array_filter((array)($dimension['metrics'] ?? []), 'is_array'))
                ), true)
            ) {
                throw new RuntimeException('Ctrip commission rate was used as technical service fee evidence.');
            }
            $rootRefs = array_values(array_map('strval', (array)($dimension['root_evidence_refs'] ?? [])));
            if ((string)($dimension['status'] ?? '') !== 'blocked_by_data'
                && ($rootRefs === [] || (string)($dimension['root_evidence_status'] ?? '') !== 'verified')
            ) {
                throw new RuntimeException('Ctrip operating radar exposed a non-blocked dimension without channel root evidence.');
            }
            if ((string)($dimension['status'] ?? '') !== 'blocked_by_data') {
                $nonBlockedRootRefs[(string)($dimension['key'] ?? '')] = $rootRefs;
            }
        }
        if (($storedDiagnosis['operating_radar'] ?? null) !== $readRadar) {
            throw new RuntimeException('Database context and GET radar payload are not identical.');
        }
        $radarAcceptance = [
            'status' => (string)($readRadar['status'] ?? ''),
            'dimension_count' => count($dimensionKeys),
            'observed_count' => (int)($readRadar['summary']['observed_count'] ?? 0),
            'partial_count' => (int)($readRadar['summary']['partial_count'] ?? 0),
            'blocked_count' => (int)($readRadar['summary']['blocked_count'] ?? 0),
            'knowledge_version' => (string)($readRadar['knowledge']['truth_profile_version'] ?? ''),
            'exact_readback' => true,
            'non_blocked_root_refs' => $nonBlockedRootRefs,
            'official_score_available' => false,
            'external_write_authorized' => false,
        ];
    } elseif (isset($diagnosis['operating_radar'])) {
        throw new RuntimeException('Non-Ctrip diagnosis unexpectedly exposed a Ctrip operating radar.');
    }

    $decisionStatus = (string)($diagnosis['decision_status'] ?? '');
    if (!in_array($decisionStatus, ['action_required', 'no_action', 'blocked_by_missing_facts'], true)) {
        throw new RuntimeException('Single-platform diagnosis returned an imprecise decision status: ' . $decisionStatus);
    }
    $actionItems = array_values(array_filter((array)($diagnosis['action_items'] ?? []), 'is_array'));
    $evidenceSources = array_values(array_filter((array)($diagnosis['evidence_sources'] ?? []), 'is_array'));
    $readyActions = [];
    $evidenceIds = [];
    foreach ($actionItems as $item) {
        if (($item['can_request_execution_intent'] ?? false) !== true
            || ($item['execution_ready'] ?? false) !== true
        ) {
            continue;
        }
        $metricKey = trim((string)($item['metric_key'] ?? $item['expected_metric'] ?? ''));
        $actionId = trim((string)($item['id'] ?? ''));
        $actionText = trim((string)($item['action'] ?? ''));
        $evidenceRefs = array_values(array_filter(array_map('strval', (array)($item['evidence_refs'] ?? []))));
        if ($metricKey === '' || $actionId === '' || $actionText === '' || $evidenceRefs === []) {
            throw new RuntimeException('An execution-ready action is missing its metric, action text, or source references.');
        }
        $semanticKey = '';
        if ($metricKey === 'list_exposure') {
            $semantic = is_array($item['metric_semantic'] ?? null) ? $item['metric_semantic'] : [];
            if ($platform !== 'ctrip'
                || ($semantic['platform'] ?? '') !== 'ctrip'
                || ($semantic['contract_version'] ?? '') !== 'ota_metric_semantic_binding.v2'
                || ($semantic['source_endpoint_family'] ?? '') !== 'ctrip_query_flow_transform_new_v1'
                || (array)($semantic['source_endpoint_ids'] ?? []) !== ['business_flow_transform', 'traffic_flow_transform']
                || ($semantic['semantic_key'] ?? '') !== 'ctrip_datacenter_list_exposure_uv'
                || ($semantic['unit'] ?? '') !== 'unique_users'
                || ($semantic['value_type'] ?? '') !== 'non_negative_integer'
                || ($semantic['field_fact_required'] ?? false) !== true
            ) {
                throw new RuntimeException('Execution-ready list_exposure action lacks the frozen Ctrip unique-user semantic binding.');
            }
            $factReady = false;
            foreach ($evidenceSources as $source) {
                if (!in_array((string)($source['ref'] ?? ''), $evidenceRefs, true)
                    || strtolower((string)($source['platform'] ?? '')) !== 'ctrip'
                ) {
                    continue;
                }
                $metricFacts = is_array($source['metric_fact_statuses'] ?? null)
                    ? $source['metric_fact_statuses']
                    : [];
                $fact = is_array($metricFacts['list_exposure'] ?? null)
                    ? $metricFacts['list_exposure']
                    : [];
                $sourceMetrics = is_array($source['metrics'] ?? null) ? $source['metrics'] : [];
                $value = $sourceMetrics['list_exposure'] ?? null;
                if (($fact['status'] ?? '') === 'ready'
                    && (array)($fact['missing_requested_metric_keys'] ?? ['list_exposure']) === []
                    && in_array(
                        (string)($source['source_endpoint_id'] ?? ''),
                        (array)$semantic['source_endpoint_ids'],
                        true
                    )
                    && in_array(
                        (string)($fact['source_endpoint_id'] ?? ''),
                        (array)$semantic['source_endpoint_ids'],
                        true
                    )
                    && (string)($fact['source_key'] ?? '') === 'listExposure'
                    && trim((string)($fact['source_path'] ?? '')) !== ''
                    && is_numeric($value)
                    && (float)$value >= 0
                    && floor((float)$value) === (float)$value
                ) {
                    $factReady = true;
                    break;
                }
            }
            if (!$factReady) {
                throw new RuntimeException('Execution-ready list_exposure action lacks captured field-fact readback evidence.');
            }
            $semanticKey = (string)$semantic['semantic_key'];
        }
        foreach ($evidenceRefs as $ref) {
            if (preg_match('/^online_daily_data#(\d+)$/', $ref, $matches) === 1) {
                $evidenceIds[] = (int)$matches[1];
            }
        }
        $readyActions[] = [
            'id' => $actionId,
            'metric_key' => $metricKey,
            'semantic_key' => $semanticKey,
            'evidence_ref_count' => count($evidenceRefs),
        ];
    }

    if ($decisionStatus === 'action_required' && $readyActions === []) {
        throw new RuntimeException('Action-required diagnosis has no execution-ready, evidence-bound action.');
    }
    if ($decisionStatus === 'blocked_by_missing_facts' && $actionItems !== []) {
        throw new RuntimeException('Missing-facts diagnosis must not expose action items.');
    }
    if ($decisionStatus === 'blocked_by_missing_facts'
        && ((string)($diagnosis['workflow_status'] ?? '') !== 'blocked_by_missing_facts'
            || (array)($diagnosis['missing_fact_codes'] ?? []) === [])
    ) {
        throw new RuntimeException('Missing-facts diagnosis lacks its persisted workflow status or exact fact codes.');
    }

    $evidenceIds = array_values(array_unique(array_filter($evidenceIds)));
    $verifiedEvidenceIds = [];
    if ($evidenceIds !== []) {
        $rows = Db::name('online_daily_data')->whereIn('id', $evidenceIds)->select()->toArray();
        foreach ($rows as $row) {
            if ((int)($row['system_hotel_id'] ?? 0) !== $hotelId
                || strtolower(trim((string)($row['source'] ?? $row['platform'] ?? ''))) !== $platform
                || (string)($row['data_date'] ?? '') !== $businessDate
                || (int)($row['readback_verified'] ?? 0) !== 1
            ) {
                continue;
            }
            $verifiedEvidenceIds[] = (int)$row['id'];
        }
    }
    $verifiedEvidenceIds = array_values(array_unique($verifiedEvidenceIds));
    if ($readyActions !== [] && $verifiedEvidenceIds === []) {
        throw new RuntimeException('Execution-ready actions have no same-scope verified source rows.');
    }

    $linkedIntentCount = (int)Db::name('operation_execution_intents')
        ->where('source_record_id', $recordId)
        ->whereLike('source_module', 'ota_diagnosis%')
        ->count();
    if ($linkedIntentCount !== 0) {
        throw new RuntimeException('Diagnosis generation unexpectedly created an execution intent.');
    }

    echo json_encode([
        'status' => 'passed',
        'transport' => 'local_http_route',
        'record_id' => $recordId,
        'storage_schema_version' => $expectedStorageSchema,
        'scope' => $scope,
        'decision_status' => $decisionStatus,
        'workflow_status' => (string)($diagnosis['workflow_status'] ?? ''),
        'missing_fact_codes' => array_values((array)($diagnosis['missing_fact_codes'] ?? [])),
        'source_policy' => (string)($diagnosis['source_policy'] ?? ''),
        'action_count' => count($actionItems),
        'ready_actions' => $readyActions,
        'same_scope_verified_source_row_count' => count($verifiedEvidenceIds),
        'model_called' => false,
        'linked_execution_intent_count' => 0,
        'ctrip_operating_radar' => $radarAcceptance,
        'saved' => true,
        'readback_verified' => true,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
} finally {
    cache('token_' . $token, null);
}
