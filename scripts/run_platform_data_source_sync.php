#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\service\PlatformDataSyncService;
use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';
(new App())->initialize();

$options = getopt('', ['source-id:', 'data-date:', 'timeout-seconds::', 'trigger-type::']);
$sourceId = max(0, (int)($options['source-id'] ?? 0));
$dataDate = trim((string)($options['data-date'] ?? ''));
$date = \DateTimeImmutable::createFromFormat('!Y-m-d', $dataDate);
if ($sourceId <= 0 || !$date || $date->format('Y-m-d') !== $dataDate) {
    fwrite(STDERR, json_encode(['status' => 'failed', 'reason' => 'source_or_date_invalid']) . PHP_EOL);
    exit(1);
}

$timeoutSeconds = max(60, min(900, (int)($options['timeout-seconds'] ?? 600)));
$triggerType = trim((string)($options['trigger-type'] ?? 'operator_cli')) ?: 'operator_cli';
$user = new class {
    public int $id = 1;

    public function isSuperAdmin(): bool
    {
        return true;
    }
};

$result = (new PlatformDataSyncService())->syncDataSource($user, $sourceId, [
    'trigger_type' => $triggerType,
    'data_date' => $dataDate,
    'data_period' => 'historical_daily',
    'snapshot_time' => date('Y-m-d H:i:s'),
    'interactive_browser' => false,
    'browser_headless' => true,
    'timeout_seconds' => $timeoutSeconds,
    'ctrip_section_concurrency' => 3,
]);

$payload = is_array($result['payload'] ?? null) ? $result['payload'] : [];
$taskId = (int)($result['task_id'] ?? 0);
$taskStats = [];
$storedPayload = [];
if ($taskId > 0) {
    $taskRow = Db::name('platform_data_sync_tasks')
        ->field('stats_json')
        ->where('id', $taskId)
        ->where('data_source_id', $sourceId)
        ->find();
    $decodedStats = is_array($taskRow)
        ? json_decode((string)($taskRow['stats_json'] ?? ''), true)
        : null;
    $taskStats = is_array($decodedStats) ? $decodedStats : [];

    $rawRow = Db::name('platform_data_raw_records')
        ->field('raw_payload')
        ->where('sync_task_id', $taskId)
        ->where('data_source_id', $sourceId)
        ->order('id', 'desc')
        ->find();
    $decodedPayload = is_array($rawRow)
        ? json_decode((string)($rawRow['raw_payload'] ?? ''), true)
        : null;
    $storedPayload = is_array($decodedPayload) ? $decodedPayload : [];
}
$captureOutput = trim((string)(
    $payload['output']
    ?? $storedPayload['output']
    ?? $storedPayload['trace']['output']
    ?? ''
));
$runtimeCaptureRoot = realpath(dirname(__DIR__) . '/runtime/platform_data_sources');
$resolvedCaptureOutput = $captureOutput !== '' ? realpath($captureOutput) : false;
if (is_string($runtimeCaptureRoot)
    && is_string($resolvedCaptureOutput)
    && str_starts_with(
        strtolower(str_replace('\\', '/', $resolvedCaptureOutput)) . '/',
        strtolower(rtrim(str_replace('\\', '/', $runtimeCaptureRoot), '/')) . '/'
    )
    && strtolower((string)pathinfo($resolvedCaptureOutput, PATHINFO_EXTENSION)) === 'json'
    && (int)filesize($resolvedCaptureOutput) > 0
    && (int)filesize($resolvedCaptureOutput) <= 10_485_760
) {
    $decodedCapture = json_decode((string)file_get_contents($resolvedCaptureOutput), true);
    if (is_array($decodedCapture)) {
        $storedPayload = array_replace($storedPayload, [
            'platform_identity_validation' => $decodedCapture['platform_identity_validation'] ?? null,
            'output' => $resolvedCaptureOutput,
        ]);
    }
}
$receipt = is_array($payload['_save_receipt'] ?? null)
    ? $payload['_save_receipt']
    : (is_array($taskStats['run_readback'] ?? null) ? $taskStats['run_readback'] : []);
$identity = is_array($payload['platform_identity_validation'] ?? null)
    ? $payload['platform_identity_validation']
    : (is_array($storedPayload['platform_identity_validation'] ?? null)
        ? $storedPayload['platform_identity_validation']
        : []);
$diagnostics = is_array($payload['sync_diagnostics'] ?? null)
    ? $payload['sync_diagnostics']
    : (is_array($taskStats['sync_diagnostics'] ?? null) ? $taskStats['sync_diagnostics'] : []);
$targetDateReadbackCount = $taskId > 0
    ? (int)Db::name('online_daily_data')
        ->where('sync_task_id', $taskId)
        ->where('data_source_id', $sourceId)
        ->where('data_date', $dataDate)
        ->where('readback_verified', 1)
        ->count()
    : 0;
$summary = [
    'status' => (string)($result['status'] ?? ''),
    'message' => (string)($result['message'] ?? ''),
    'task_id' => $taskId,
    'source_id' => $sourceId,
    'data_date' => $dataDate,
    'row_count' => (int)($result['row_count'] ?? $taskStats['normalized_count'] ?? 0),
    'saved_count' => (int)($result['saved_count'] ?? $taskStats['saved_count'] ?? 0),
    'readback_verified' => ($taskStats['readback_verified'] ?? $receipt['readback_verified'] ?? false) === true,
    'readback_count' => (int)($taskStats['readback_count'] ?? $receipt['readback_count'] ?? 0),
    'target_date_readback_count' => $targetDateReadbackCount,
    'inserted_count' => (int)($receipt['inserted_count'] ?? $taskStats['inserted_count'] ?? 0),
    'updated_count' => (int)($receipt['updated_count'] ?? $taskStats['updated_count'] ?? 0),
    'identity' => [
        'status' => (string)($identity['status'] ?? 'unverified'),
        'evidence_source' => (string)($identity['evidence_source'] ?? ''),
        'validated_identifier' => (string)($identity['validated_identifier'] ?? ''),
        'validated_name' => (string)($identity['validated_name'] ?? ''),
        'sensitive_values_exposed' => ($identity['sensitive_values_exposed'] ?? true) === true,
    ],
    'p0_status' => (string)($diagnostics['p0_status'] ?? ''),
    'diagnostic_target_date' => (string)($diagnostics['target_date'] ?? ''),
    'output' => (string)($storedPayload['output'] ?? $captureOutput),
];

echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
exit(in_array($summary['status'], ['success', 'partial_success'], true) ? 0 : 2);
