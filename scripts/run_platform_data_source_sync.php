#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\service\PlatformDataSyncService;
use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';
(new App())->initialize();

$options = getopt('', ['source-id:', 'data-date:', 'data-period:', 'timeout-seconds::', 'trigger-type::', 'capture-sections:']);
$sourceId = max(0, (int)($options['source-id'] ?? 0));
$dataDate = trim((string)($options['data-date'] ?? ''));
$date = \DateTimeImmutable::createFromFormat('!Y-m-d', $dataDate);
if ($sourceId <= 0 || !$date || $date->format('Y-m-d') !== $dataDate) {
    fwrite(STDERR, json_encode(['status' => 'failed', 'reason' => 'source_or_date_invalid']) . PHP_EOL);
    exit(1);
}

$businessTimezone = new \DateTimeZone('Asia/Shanghai');
$today = new \DateTimeImmutable('today', $businessTimezone);
$targetDay = new \DateTimeImmutable($dataDate . ' 00:00:00', $businessTimezone);
$requestedDataPeriod = strtolower(trim((string)($options['data-period'] ?? '')));
if ($targetDay > $today) {
    fwrite(STDERR, json_encode([
        'status' => 'failed',
        'reason' => 'target_date_future',
        'data_date' => $dataDate,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
$dataPeriod = $requestedDataPeriod !== ''
    ? $requestedDataPeriod
    : ($targetDay < $today ? 'historical_daily' : 'realtime_snapshot');
if (!in_array($dataPeriod, ['historical_daily', 'realtime_snapshot'], true)) {
    fwrite(STDERR, json_encode([
        'status' => 'failed',
        'reason' => 'data_period_invalid',
        'allowed_data_periods' => ['historical_daily', 'realtime_snapshot'],
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
if (($dataPeriod === 'historical_daily' && $targetDay >= $today)
    || ($dataPeriod === 'realtime_snapshot' && $targetDay != $today)
) {
    fwrite(STDERR, json_encode([
        'status' => 'failed',
        'reason' => 'data_period_target_date_mismatch',
        'data_date' => $dataDate,
        'data_period' => $dataPeriod,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}

$captureSectionsText = strtolower(trim((string)($options['capture-sections'] ?? '')));
$captureSections = array_values(array_unique(array_filter(array_map(
    static fn(string $section): string => trim($section),
    preg_split('/[,\s]+/', $captureSectionsText) ?: []
))));
if ($captureSectionsText !== '') {
    $sourcePlatform = strtolower(trim((string)Db::name('platform_data_sources')
        ->where('id', $sourceId)
        ->value('platform')));
    $allowedSections = match ($sourcePlatform) {
        'ctrip' => ['business_overview', 'traffic_report'],
        'meituan' => ['orders', 'traffic'],
        default => [],
    };
    if ($captureSections === [] || array_diff($captureSections, $allowedSections) !== []) {
        fwrite(STDERR, json_encode([
            'status' => 'failed',
            'reason' => 'capture_sections_invalid_for_source_platform',
            'platform' => $sourcePlatform,
            'allowed_sections' => $allowedSections,
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(1);
    }
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

$syncOptions = [
    'trigger_type' => $triggerType,
    'data_date' => $dataDate,
    'data_period' => $dataPeriod,
    'snapshot_time' => $dataPeriod === 'realtime_snapshot' ? date('Y-m-d H:i:s') : '',
    'interactive_browser' => false,
    'browser_headless' => true,
    'timeout_seconds' => $timeoutSeconds,
    'ctrip_section_concurrency' => 3,
];
if ($captureSections !== []) {
    $boundedSections = implode(',', $captureSections);
    $syncOptions['capture_sections'] = $boundedSections;
    $syncOptions['bounded_capture_sections'] = $boundedSections;
}
$result = (new PlatformDataSyncService())->syncDataSource($user, $sourceId, $syncOptions);

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
$maxIdentityCaptureBytes = 16 * 1024 * 1024;
$resolvedCaptureOutputSize = is_string($resolvedCaptureOutput)
    ? (int)filesize($resolvedCaptureOutput)
    : 0;
if (is_string($runtimeCaptureRoot)
    && is_string($resolvedCaptureOutput)
    && str_starts_with(
        strtolower(str_replace('\\', '/', $resolvedCaptureOutput)) . '/',
        strtolower(rtrim(str_replace('\\', '/', $runtimeCaptureRoot), '/')) . '/'
    )
    && strtolower((string)pathinfo($resolvedCaptureOutput, PATHINFO_EXTENSION)) === 'json'
    && $resolvedCaptureOutputSize > 0
    && $resolvedCaptureOutputSize <= $maxIdentityCaptureBytes
) {
    $decodedCapture = json_decode((string)file_get_contents($resolvedCaptureOutput), true);
    if (is_array($decodedCapture)) {
        $storedPayload = array_replace($storedPayload, [
            'platform_identity_validation' => $decodedCapture['platform_identity_validation'] ?? null,
            'output' => $resolvedCaptureOutput,
        ]);
    }
}
$saveReceipt = is_array($payload['_save_receipt'] ?? null) ? $payload['_save_receipt'] : [];
$receipt = is_array($taskStats['run_readback'] ?? null)
    ? $taskStats['run_readback']
    : (is_array($payload['run_readback'] ?? null) ? $payload['run_readback'] : []);
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
        ->where('data_period', $dataPeriod)
        ->where('readback_verified', 1)
        ->count()
    : 0;
$targetRowIds = array_values(array_unique(array_filter(array_map(
    static fn(mixed $value): int => max(0, (int)$value),
    is_array($receipt['row_ids'] ?? null) ? $receipt['row_ids'] : []
))));
$exactReadbackVerified = ($receipt['readback_verified'] ?? false) === true
    && (int)($receipt['sync_task_id'] ?? 0) === $taskId
    && (int)($receipt['data_source_id'] ?? 0) === $sourceId
    && (string)($receipt['target_date'] ?? '') === $dataDate
    && (string)($receipt['data_period'] ?? '') === $dataPeriod
    && $targetRowIds !== []
    && (int)($receipt['readback_count'] ?? 0) === count($targetRowIds)
    && $targetDateReadbackCount === count($targetRowIds);
$summary = [
    'status' => (string)($result['status'] ?? ''),
    'message' => (string)($result['message'] ?? ''),
    'task_id' => $taskId,
    'source_id' => $sourceId,
    'data_date' => $dataDate,
    'data_period' => $dataPeriod,
    'capture_sections' => $captureSections,
    'row_count' => (int)($result['row_count'] ?? $taskStats['normalized_count'] ?? 0),
    'task_saved_count' => (int)($result['saved_count'] ?? $taskStats['saved_count'] ?? 0),
    'task_readback_count' => (int)($taskStats['readback_count'] ?? 0),
    'task_readback_verified' => ($taskStats['readback_verified'] ?? false) === true,
    'saved_count' => count($targetRowIds),
    'readback_verified' => $exactReadbackVerified,
    'readback_count' => (int)($receipt['readback_count'] ?? 0),
    'target_saved_count' => count($targetRowIds),
    'target_readback_count' => $targetDateReadbackCount,
    'target_date_readback_count' => $targetDateReadbackCount,
    'run_readback_failure_reason' => (string)($receipt['failure_reason'] ?? ''),
    'inserted_count' => (int)($saveReceipt['inserted_count'] ?? $taskStats['inserted_count'] ?? 0),
    'updated_count' => (int)($saveReceipt['updated_count'] ?? $taskStats['updated_count'] ?? 0),
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
exit(in_array($summary['status'], ['success', 'partial_success'], true) && $exactReadbackVerified ? 0 : 2);
