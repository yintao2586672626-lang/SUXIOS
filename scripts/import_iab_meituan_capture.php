#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\service\PlatformDataSyncService;
use think\App;

require dirname(__DIR__) . '/vendor/autoload.php';
(new App())->initialize();

$options = getopt('', ['input:', 'source-id:']);
$input = trim((string)($options['input'] ?? ''));
$sourceId = max(0, (int)($options['source-id'] ?? 0));
$resolvedInput = $input !== '' ? realpath($input) : false;
if ($sourceId <= 0
    || !is_string($resolvedInput)
    || strtolower((string)pathinfo($resolvedInput, PATHINFO_EXTENSION)) !== 'json'
    || (int)filesize($resolvedInput) <= 0
    || (int)filesize($resolvedInput) > 65_536
) {
    fwrite(STDERR, json_encode(['status' => 'failed', 'reason' => 'capture_input_invalid']) . PHP_EOL);
    exit(1);
}

try {
    $capture = json_decode((string)file_get_contents($resolvedInput), true, 64, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    fwrite(STDERR, json_encode(['status' => 'failed', 'reason' => 'capture_json_invalid']) . PHP_EOL);
    exit(1);
}
if (!is_array($capture) || (int)($capture['data_source_id'] ?? 0) !== $sourceId) {
    fwrite(STDERR, json_encode(['status' => 'failed', 'reason' => 'capture_source_mismatch']) . PHP_EOL);
    exit(1);
}

$user = new class {
    public int $id = 1;

    public function isSuperAdmin(): bool
    {
        return true;
    }
};

$result = (new PlatformDataSyncService())->syncDataSource($user, $sourceId, [
    'trigger_type' => 'in_app_browser_capture',
    'interactive_browser' => true,
    'data_date' => (string)($capture['data_date'] ?? ''),
    'data_period' => 'realtime_snapshot',
    'snapshot_time' => (string)($capture['captured_at'] ?? ''),
    'in_app_browser_capture' => $capture,
]);

$payload = is_array($result['payload'] ?? null) ? $result['payload'] : [];
$receipt = is_array($payload['_save_receipt'] ?? null) ? $payload['_save_receipt'] : [];
$facts = is_array($capture['facts'] ?? null) ? $capture['facts'] : [];
$safeFacts = [];
foreach (['browse_users', 'stay_room_nights', 'sales_amount', 'full_room_rate', 'lost_order_count'] as $key) {
    $safeFacts[$key] = array_key_exists($key, $facts) ? $facts[$key] : null;
}

$output = [
    'status' => (string)($result['status'] ?? 'failed'),
    'message' => (string)($result['message'] ?? ''),
    'data_source_id' => $sourceId,
    'sync_task_id' => (int)($result['task_id'] ?? 0),
    'data_date' => (string)($capture['data_date'] ?? ''),
    'captured_at' => (string)($capture['captured_at'] ?? ''),
    'facts' => $safeFacts,
    'normalized_count' => (int)($result['normalized_count'] ?? 0),
    'saved_count' => (int)($result['saved_count'] ?? 0),
    'readback_count' => (int)($result['readback_count'] ?? $receipt['readback_count'] ?? 0),
    'readback_verified' => ($result['readback_verified'] ?? $receipt['readback_verified'] ?? false) === true,
];
echo json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

$successful = in_array($output['status'], ['success', 'partial_success'], true)
    && $output['saved_count'] > 0
    && $output['readback_verified'] === true;
exit($successful ? 0 : 1);
