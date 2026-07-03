<?php
declare(strict_types=1);

use app\controller\OnlineData;
use app\service\MeituanRankDataExtractionService;
use app\service\OnlineDailyDataPersistenceService;
use app\service\OnlineDataFieldFactService;
use app\service\OnlineTrafficDataExtractionService;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php is missing.\n");
    exit(1);
}

require $autoload;

$checks = [];

function add_field_fact_check(array &$checks, string $code, bool $ok, string $message, array $details = []): void
{
    $row = [
        'code' => $code,
        'status' => $ok ? 'passed' : 'failed',
        'message' => $message,
    ];
    if ($details !== []) {
        $row['details'] = $details;
    }
    $checks[] = $row;
}

function assert_field_fact_check(array &$checks, string $code, bool $ok, string $message, array $details = []): void
{
    add_field_fact_check($checks, $code, $ok, $message, $details);
}

function field_fact_status(array $row, array $raw): array
{
    $ref = new ReflectionClass(OnlineData::class);
    $controller = $ref->newInstanceWithoutConstructor();
    $method = $ref->getMethod('buildOnlineDataFieldFactStatus');
    $method->setAccessible(true);
    /** @var array<string, mixed> $result */
    $result = $method->invoke($controller, $row, $raw);
    return $result;
}

$notLoaded = field_fact_status(['source' => 'ctrip', 'data_type' => 'business'], []);
assert_field_fact_check(
    $checks,
    'not_loaded_without_facts',
    ($notLoaded['status'] ?? '') === 'not_loaded'
        && ($notLoaded['raw_data_exposed'] ?? true) === false
        && ($notLoaded['inferred_storage_field_count'] ?? -1) === 0,
    'Rows without field facts stay explicitly not_loaded and do not expose raw data.',
    ['status' => $notLoaded['status'] ?? null]
);

$explicit = field_fact_status(
    ['source' => 'meituan', 'data_type' => 'traffic'],
    [
        'field_facts' => [
            [
                'metric_key' => 'list_exposure',
                'source_path' => 'data.flowData.0.listExposure',
                'storage_field' => 'list_exposure',
                'status' => 'captured',
                'capture_evidence' => ['source_path' => 'data.flowData.0'],
            ],
            [
                'metric_key' => 'flow_rate',
                'source_path' => 'data.flowData.0.flowRate',
                'storage_field' => 'flow_rate',
                'status' => 'captured',
                'capture_evidence' => ['source_path' => 'data.flowData.0'],
            ],
        ],
    ]
);
assert_field_fact_check(
    $checks,
    'explicit_field_facts_ready',
    ($explicit['status'] ?? '') === 'ready'
        && ($explicit['captured_count'] ?? 0) === 2
        && ($explicit['capture_evidence_count'] ?? 0) === 2
        && ($explicit['desensitized_capture_evidence_count'] ?? -1) === 0
        && ($explicit['storage_field_count'] ?? 0) === 2
        && ($explicit['inferred_storage_field_count'] ?? -1) === 0,
    'Explicit platform field_facts with source_path and storage_field are ready.',
    ['result' => $explicit]
);

$captureEvidenceMissing = field_fact_status(
    ['source' => 'meituan', 'data_type' => 'traffic'],
    [
        'field_facts' => [
            [
                'metric_key' => 'list_exposure',
                'source_path' => 'data.flowData.0.listExposure',
                'storage_field' => 'list_exposure',
                'status' => 'captured',
            ],
        ],
    ]
);
assert_field_fact_check(
    $checks,
    'capture_evidence_required_for_closure',
    ($captureEvidenceMissing['status'] ?? '') === 'missing'
        && ($captureEvidenceMissing['capture_evidence_count'] ?? -1) === 0
        && in_array('list_exposure', $captureEvidenceMissing['missing_metric_keys'] ?? [], true),
    'A field fact without capture_evidence is not treated as closed even when source_path and storage_field exist.',
    ['result' => $captureEvidenceMissing]
);

$legacy = field_fact_status(
    ['source' => 'ctrip', 'data_type' => 'business', 'source_trace_id' => 'ctrip:demo-trace'],
    [
        'facts' => [
            [
                'metric_key' => 'order_amount',
                'source_path' => 'data.amount',
                'value' => 123.45,
            ],
            [
                'metric_key' => 'custom_fact',
                'source_path' => 'data.customFact',
                'value' => 'Y',
            ],
            [
                'metric_key' => 'missing_fact',
                'status' => 'missing',
            ],
        ],
    ]
);
$legacySamples = $legacy['sample_facts'] ?? [];
assert_field_fact_check(
    $checks,
    'legacy_facts_infer_storage_without_hiding_missing',
    ($legacy['status'] ?? '') === 'partial'
        && ($legacy['storage_field_count'] ?? 0) === 2
        && ($legacy['inferred_storage_field_count'] ?? 0) === 2
        && in_array('missing_fact', $legacy['missing_metric_keys'] ?? [], true)
        && (($legacySamples[0]['storage_field'] ?? '') === 'online_daily_data.amount')
        && (($legacySamples[0]['storage_field_source'] ?? '') === 'metric_key_map')
        && (($legacySamples[1]['storage_field'] ?? '') === 'online_daily_data.raw_data.facts.metric_key=custom_fact')
        && (($legacySamples[1]['storage_field_source'] ?? '') === 'raw_data_facts')
        && (($legacySamples[2]['status'] ?? '') === 'missing'),
    'Legacy Ctrip raw_data.facts infer storage targets but keep missing facts visible.',
    ['result' => $legacy]
);

$rawMetric = field_fact_status(
    ['source' => 'ctrip', 'data_type' => 'business', 'source_trace_id' => 'ctrip:raw-metrics'],
    [
        'metrics' => [
            'hot_spot_name' => '附近热区',
        ],
        'facts' => [
            [
                'metric_key' => 'hot_spot_name',
                'source_path' => 'otherDataList.0.hotSpotName',
                'capture_evidence' => ['source_path' => 'otherDataList.0'],
            ],
        ],
    ]
);
$rawMetricSamples = $rawMetric['sample_facts'] ?? [];
assert_field_fact_check(
    $checks,
    'raw_metrics_storage_value_present',
    ($rawMetric['status'] ?? '') === 'ready'
        && ($rawMetric['stored_value_present_count'] ?? 0) === 1
        && ($rawMetric['stored_value_missing_count'] ?? -1) === 0
        && (($rawMetricSamples[0]['storage_field'] ?? '') === 'online_daily_data.raw_data.metrics.hot_spot_name')
        && (($rawMetricSamples[0]['stored_value_present'] ?? null) === true),
    'raw_data.metrics storage fields are counted as stored values without exposing raw metric values.',
    ['result' => $rawMetric]
);

$sourcePathMissing = field_fact_status(
    ['source' => 'ctrip', 'data_type' => 'business'],
    [
        'facts' => [
            [
                'metric_key' => 'order_amount',
                'value' => 123.45,
            ],
        ],
    ]
);
assert_field_fact_check(
    $checks,
    'source_path_required_for_closure',
    ($sourcePathMissing['status'] ?? '') === 'missing'
        && in_array('order_amount', $sourcePathMissing['missing_metric_keys'] ?? [], true)
        && ($sourcePathMissing['source_path_count'] ?? -1) === 0,
    'A metric_key/value without source_path is not treated as a closed field fact.',
    ['result' => $sourcePathMissing]
);

$weakSourcePath = field_fact_status(
    ['source' => 'meituan', 'data_type' => 'traffic', 'list_exposure' => 10],
    [
        'field_facts' => [
            [
                'metric_key' => 'list_exposure',
                'source_path' => 'listExposure',
                'storage_field' => 'online_daily_data.list_exposure',
                'capture_evidence' => ['source_trace_id' => 'meituan:weak-source-path'],
                'stored_value_present' => true,
            ],
        ],
    ]
);
assert_field_fact_check(
    $checks,
    'structured_source_path_required_for_ready_status',
    ($weakSourcePath['status'] ?? '') === 'missing'
        && ($weakSourcePath['source_path_count'] ?? -1) === 1
        && ($weakSourcePath['structured_source_path_count'] ?? -1) === 0
        && (($weakSourcePath['sample_facts'][0]['source_path_structured'] ?? null) === false)
        && in_array('list_exposure', $weakSourcePath['missing_metric_keys'] ?? [], true),
    'A field-name-only source_path is not treated as a ready UI field fact.',
    ['result' => $weakSourcePath]
);

$meituanTrafficSource = [
    '_source_path' => 'traffic.0',
    'source_trace_id' => 'meituan:traffic-demo',
    'url_hash' => str_repeat('a', 64),
    'listExposure' => 123,
    'detailExposure' => 45,
    'flowRate' => 36.5,
    'orderFillingNum' => 9,
    'orderSubmitNum' => 6,
];
$meituanTrafficRow = OnlineDataFieldFactService::attachToOnlineDailyRow(
    [
        'source' => 'meituan',
        'data_type' => 'traffic',
        'data_date' => '2026-06-14',
        'hotel_id' => 'demo',
        'dimension' => 'traffic',
        'list_exposure' => 123,
        'detail_exposure' => 45,
        'flow_rate' => 36.5,
        'order_filling_num' => 9,
        'order_submit_num' => 6,
        'raw_data' => json_encode($meituanTrafficSource, JSON_UNESCAPED_UNICODE),
    ],
    $meituanTrafficSource
);
$meituanTrafficRaw = json_decode((string)($meituanTrafficRow['raw_data'] ?? '{}'), true);
$meituanTrafficStatus = field_fact_status($meituanTrafficRow, is_array($meituanTrafficRaw) ? $meituanTrafficRaw : []);
$meituanTrafficFactEvidence = (array)($meituanTrafficRaw['field_facts'][0]['capture_evidence'] ?? []);
$meituanTrafficSummary = (array)($meituanTrafficRaw['field_fact_summary'] ?? []);
assert_field_fact_check(
    $checks,
    'meituan_persistence_field_facts_ready',
    ($meituanTrafficStatus['status'] ?? '') === 'ready'
        && ($meituanTrafficStatus['captured_count'] ?? 0) >= 5
        && ($meituanTrafficStatus['capture_evidence_count'] ?? 0) >= 5
        && ($meituanTrafficStatus['desensitized_capture_evidence_count'] ?? 0) >= 5
        && ($meituanTrafficSummary['desensitized_capture_evidence_count'] ?? 0) >= 5
        && ($meituanTrafficStatus['source_path_count'] ?? 0) >= 5
        && ($meituanTrafficStatus['storage_field_count'] ?? 0) >= 5
        && ($meituanTrafficStatus['stored_value_missing_count'] ?? -1) === 0
        && ($meituanTrafficFactEvidence['source_trace_id'] ?? '') === 'meituan:traffic-demo'
        && ($meituanTrafficFactEvidence['source_url_hash'] ?? '') === str_repeat('a', 64)
        && ($meituanTrafficStatus['raw_data_exposed'] ?? true) === false,
    'Meituan persistence rows can attach source_path/metric_key/storage_field facts with desensitized capture evidence for UI status.',
    ['result' => $meituanTrafficStatus, 'capture_evidence' => $meituanTrafficFactEvidence]
);

$missingStoredTrafficRow = OnlineDataFieldFactService::attachToOnlineDailyRow(
    [
        'source' => 'meituan',
        'data_type' => 'traffic',
        'data_date' => '2026-06-14',
        'hotel_id' => 'demo',
        'dimension' => 'traffic',
        'raw_data' => json_encode($meituanTrafficSource, JSON_UNESCAPED_UNICODE),
    ],
    $meituanTrafficSource
);
$missingStoredTrafficRaw = json_decode((string)($missingStoredTrafficRow['raw_data'] ?? '{}'), true);
$missingStoredTrafficStatus = field_fact_status($missingStoredTrafficRow, is_array($missingStoredTrafficRaw) ? $missingStoredTrafficRaw : []);
assert_field_fact_check(
    $checks,
    'stored_value_required_for_ready_status',
    in_array(($missingStoredTrafficStatus['status'] ?? ''), ['missing', 'partial'], true)
        && ($missingStoredTrafficStatus['stored_value_missing_count'] ?? 0) > 0
        && in_array('list_exposure', $missingStoredTrafficStatus['missing_metric_keys'] ?? [], true),
    'Field facts with source_path/storage_field but missing normalized stored values stay partial.',
    ['result' => $missingStoredTrafficStatus]
);

$meituanRankExtraction = MeituanRankDataExtractionService::extractForPersistenceWithSource([
    'data' => [
        'peerRankData' => [
            [
                'dimName' => '入住间夜榜',
                'aiMetricName' => '入住间夜',
                'roundRanks' => [
                    [
                        'poiId' => 'demo',
                        'poiName' => 'demo hotel',
                        'percent' => 18.2,
                        'rankType' => 'P_RZ',
                        'rank' => 3,
                    ],
                ],
            ],
        ],
    ],
]);
$meituanRankSource = $meituanRankExtraction['rows'][0] ?? [];
$meituanRankRow = OnlineDataFieldFactService::attachToOnlineDailyRow(
    [
        'source' => 'meituan',
        'data_type' => 'peer_rank',
        'data_date' => '2026-06-14',
        'hotel_id' => 'demo',
        'hotel_name' => 'demo hotel',
        'dimension' => '入住间夜榜',
        'data_value' => 18.2,
        'raw_data' => json_encode([
            'percent' => 18.2,
            'rankType' => 'P_RZ',
            'rank' => 3,
            'dimension' => '入住间夜榜',
            '_source_path' => $meituanRankSource['_source_path'] ?? '',
            '_capture_source' => $meituanRankExtraction['source'] ?? '',
        ], JSON_UNESCAPED_UNICODE),
    ],
    is_array($meituanRankSource) ? $meituanRankSource : []
);
$meituanRankRaw = json_decode((string)($meituanRankRow['raw_data'] ?? '{}'), true);
$meituanRankStatus = field_fact_status($meituanRankRow, is_array($meituanRankRaw) ? $meituanRankRaw : []);
assert_field_fact_check(
    $checks,
    'meituan_rank_source_path_field_facts_ready',
    ($meituanRankStatus['status'] ?? '') === 'ready'
        && ($meituanRankStatus['captured_count'] ?? 0) >= 4
        && ($meituanRankStatus['capture_evidence_count'] ?? 0) >= 4
        && ($meituanRankStatus['source_path_count'] ?? 0) >= 4
        && str_starts_with((string)($meituanRankStatus['sample_facts'][0]['source_path'] ?? ''), 'data.peerRankData.0.roundRanks.0.'),
    'Meituan peer-rank extraction preserves row source_path and attaches closed field facts.',
    ['result' => $meituanRankStatus]
);

$genericTrafficRows = OnlineTrafficDataExtractionService::extractGenericTrafficRows([
    'data' => [
        'flowData' => [
            [
                'poiId' => 'demo',
                'poiName' => 'demo hotel',
                'capture_evidence' => [
                    'source_trace_id' => 'meituan:generic-traffic-demo',
                    'url_hash' => str_repeat('b', 64),
                ],
                'listExposure' => 321,
                'detailExposure' => 98,
                'flowRate' => 30.53,
                'orderFillingNum' => 7,
                'orderSubmitNum' => 11,
            ],
        ],
    ],
]);
$genericTrafficSource = $genericTrafficRows[0] ?? [];
$genericTrafficRow = OnlineDataFieldFactService::attachToOnlineDailyRow(
    [
        'source' => 'meituan',
        'data_type' => 'traffic',
        'data_date' => '2026-06-14',
        'hotel_id' => 'demo',
        'hotel_name' => 'demo hotel',
        'dimension' => 'traffic',
        'list_exposure' => 321,
        'detail_exposure' => 98,
        'flow_rate' => 30.53,
        'order_filling_num' => 7,
        'order_submit_num' => 11,
        'raw_data' => json_encode($genericTrafficSource, JSON_UNESCAPED_UNICODE),
    ],
    is_array($genericTrafficSource) ? $genericTrafficSource : []
);

$persistenceRef = new ReflectionClass(OnlineDailyDataPersistenceService::class);
$persistence = $persistenceRef->newInstanceWithoutConstructor();
$metricMethod = $persistenceRef->getMethod('extractGenericTrafficMetrics');
$metricMethod->setAccessible(true);
/** @var array<string, mixed> $genericTrafficMetrics */
$genericTrafficMetrics = $metricMethod->invoke($persistence, $genericTrafficSource);
assert_field_fact_check(
    $checks,
    'generic_traffic_persistence_structured_fields_ready',
    (int)($genericTrafficMetrics['list_exposure'] ?? 0) === 321
        && (int)($genericTrafficMetrics['detail_exposure'] ?? 0) === 98
        && abs((float)($genericTrafficMetrics['flow_rate'] ?? 0) - 30.53) < 0.001
        && (int)($genericTrafficMetrics['order_filling_num'] ?? 0) === 7
        && (int)($genericTrafficMetrics['order_submit_num'] ?? 0) === 11,
    'Generic traffic persistence maps source fields into normalized online_daily_data columns.',
    ['metrics' => $genericTrafficMetrics]
);
$genericTrafficRaw = json_decode((string)($genericTrafficRow['raw_data'] ?? '{}'), true);
$genericTrafficStatus = field_fact_status($genericTrafficRow, is_array($genericTrafficRaw) ? $genericTrafficRaw : []);
$genericTrafficFactEvidence = (array)($genericTrafficRaw['field_facts'][0]['capture_evidence'] ?? []);
$genericTrafficSummary = (array)($genericTrafficRaw['field_fact_summary'] ?? []);
assert_field_fact_check(
    $checks,
    'generic_traffic_extraction_source_paths_ready',
    ($genericTrafficSource['_source_path'] ?? '') === 'data.flowData.0'
        && ($genericTrafficStatus['status'] ?? '') === 'ready'
        && ($genericTrafficStatus['captured_count'] ?? 0) >= 5
        && ($genericTrafficStatus['capture_evidence_count'] ?? 0) >= 5
        && ($genericTrafficStatus['desensitized_capture_evidence_count'] ?? 0) >= 5
        && ($genericTrafficSummary['desensitized_capture_evidence_count'] ?? 0) >= 5
        && ($genericTrafficStatus['stored_value_missing_count'] ?? -1) === 0
        && ($genericTrafficFactEvidence['source_trace_id'] ?? '') === 'meituan:generic-traffic-demo'
        && ($genericTrafficFactEvidence['source_url_hash'] ?? '') === str_repeat('b', 64)
        && str_starts_with((string)($genericTrafficStatus['sample_facts'][0]['source_path'] ?? ''), 'data.flowData.0.'),
    'Generic traffic extraction preserves data.flowData source paths and desensitized capture evidence for field-fact closure.',
    ['source_path' => $genericTrafficSource['_source_path'] ?? null, 'capture_evidence' => $genericTrafficFactEvidence, 'result' => $genericTrafficStatus]
);

$failed = array_values(array_filter($checks, static fn(array $row): bool => ($row['status'] ?? '') !== 'passed'));
$result = [
    'script' => 'scripts/verify_online_data_field_fact_status.php',
    'status' => $failed === [] ? 'passed' : 'failed',
    'checks' => $checks,
    'summary' => [
        'passed' => count($checks) - count($failed),
        'failed' => count($failed),
    ],
];

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

if ($failed !== []) {
    exit(1);
}
