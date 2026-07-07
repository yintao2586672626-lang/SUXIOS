<?php
declare(strict_types=1);

use app\service\CtripCollectorWorkflowService;
use app\service\OtaBrowserAssistImportService;

$root = dirname(__DIR__);
require $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

$checks = [];
$failures = [];

$check = static function (string $name, bool $passed, array $evidence = []) use (&$checks, &$failures): void {
    $checks[] = [
        'name' => $name,
        'passed' => $passed,
        'evidence' => $evidence,
    ];
    if (!$passed) {
        $failures[] = $name;
    }
};

$workflow = new CtripCollectorWorkflowService();
$contract = $workflow->buildContract([], ['collector_flow' => 'full']);
$check(
    'contract exposes review/full/realtime flows',
    isset($contract['flows']['review_only'], $contract['flows']['full'], $contract['flows']['realtime']),
    ['flows' => array_keys($contract['flows'] ?? [])]
);
$check(
    'full flow maps to wide Ctrip capture',
    ($contract['flows']['full']['capture_sections'] ?? '') === 'wide',
    ['capture_sections' => $contract['flows']['full']['capture_sections'] ?? null]
);

$disabledGate = $workflow->collectionGate(['config' => ['collect_ctrip' => false]], ['collector_flow' => 'review_only']);
$check(
    'collect_ctrip false blocks collection',
    empty($disabledGate['allowed']) && ($disabledGate['reason'] ?? '') === 'collect_ctrip_disabled',
    $disabledGate
);

$realtime = $workflow->validateRealtimeRows([
    [
        'source' => 'ctrip',
        'data_type' => 'peer_rank',
        'dimension' => 'realtime:zhixing:rank',
        'data_value' => 9,
        'raw_data' => ['channel' => 'zhixing', 'rank_metrics' => ['realtime_rank' => 9]],
    ],
]);
$check(
    'realtime flow validates at least one core field',
    ($realtime['status'] ?? '') === 'ready' && in_array('ctrip_rank', $realtime['found_fields'] ?? [], true),
    $realtime
);

$audit = $workflow->auditSubChannels([
    ['source' => 'ctrip', 'platform' => 'ctrip', 'dimension' => 'realtime:tongcheng', 'quantity' => 0, 'raw_data' => ['channel' => 'tongcheng']],
    ['source' => 'ctrip', 'platform' => 'ctrip', 'dimension' => 'realtime:qunar', 'quantity' => 2, 'raw_data' => ['channel' => 'qunar']],
]);
$check(
    'sub-channel audit keeps Ctrip family in OTA scope and flags all-zero room nights',
    ($audit['status'] ?? '') === 'warning'
        && isset($audit['channels']['tongcheng'], $audit['channels']['qunar'])
        && in_array('ctrip_family_room_nights_all_zero_suspicious', array_column($audit['warnings'] ?? [], 'code'), true),
    $audit
);

$importService = new OtaBrowserAssistImportService();
$normalized = $importService->normalizeCapturePackages([
    'system_hotel_id' => 58,
    'hotel_id' => '6866634',
    'data_date' => '2026-07-08',
    'snapshot_time' => '2026-07-08 10:20:00',
    'capture' => [
        'ctripStats' => [
            'updatedAt' => '2026-07-08 10:20:00',
            'metrics' => [
                'ctrip' => ['realtimeVisitors' => ['value' => '128']],
                'qunar' => ['realtimeVisitors' => ['value' => '56']],
                'tongcheng' => ['realtimeVisitors' => ['value' => '17']],
                'zhixing' => ['realtimeRank' => ['value' => '9']],
            ],
        ],
    ],
]);
$dimensions = array_values(array_filter(array_map(
    static fn(array $row): string => (string)($row['dimension'] ?? ''),
    $normalized['rows'] ?? []
)));
sort($dimensions);
$check(
    'browser assist import preserves Ctrip family sub-channel dimensions',
    in_array('realtime:qunar', $dimensions, true)
        && in_array('realtime:tongcheng', $dimensions, true)
        && in_array('realtime:zhixing:rank', $dimensions, true)
        && count(array_filter($normalized['rows'] ?? [], static fn(array $row): bool => ($row['source'] ?? '') === 'ctrip')) === count($normalized['rows'] ?? []),
    ['dimensions' => $dimensions]
);

$payload = [
    'status' => $failures === [] ? 'passed' : 'failed',
    'scope' => 'ctrip_ota_channel',
    'checks' => $checks,
    'failures' => $failures,
];

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 1);
