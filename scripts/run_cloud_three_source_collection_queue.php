#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\service\CloudThreeSourceCollectionQueueService;
use think\App;

const CLOUD_THREE_SOURCE_QUEUE_LOCK = '/run/suxios-cloud-three-source-queue/queue.lock';

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
(new App($root))->initialize();

set_exception_handler(static function (Throwable $error): never {
    queueFail(queueSafeCode($error->getMessage()) ?: 'cloud_three_source_queue_failed');
});

$options = getopt('', [
    'target-date::',
    'child-timeout-seconds::',
    'deadline-seconds::',
    'control-token-file::',
]);
$queueOptions = [
    'target_date' => trim((string)($options['target-date'] ?? '')),
    'child_timeout_seconds' => (int)($options['child-timeout-seconds'] ?? 540),
    'deadline_seconds' => (int)($options['deadline-seconds'] ?? 1500),
    'control_token_file' => trim((string)($options['control-token-file']
        ?? '/run/credentials/suxios-cloud-three-source-queue.service/control-token')),
];
if ($queueOptions['target_date'] === '') {
    unset($queueOptions['target_date']);
}

$lockDirectory = dirname(CLOUD_THREE_SOURCE_QUEUE_LOCK);
if (!is_dir($lockDirectory)) {
    queueFail('cloud_three_source_queue_lock_directory_missing');
}
$lock = fopen(CLOUD_THREE_SOURCE_QUEUE_LOCK, 'c+');
if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
    queueFail('cloud_three_source_queue_already_running');
}

try {
    $receipt = (new CloudThreeSourceCollectionQueueService())->run($queueOptions);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

echo json_encode(
    $receipt,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
) . PHP_EOL;
exit(in_array((string)($receipt['status'] ?? ''), [
    'all_hotels_saved_and_readback_verified',
    'no_eligible_plans',
    'no_due_plans',
], true) ? 0 : 1);

function queueFail(string $reason): never
{
    echo json_encode([
        'status' => 'blocked',
        'reason' => queueSafeCode($reason) ?: 'cloud_three_source_queue_failed',
        'execution_mode' => 'global_serial',
        'message_sent' => false,
        'sensitive_values_exposed' => false,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}

function queueSafeCode(string $value): string
{
    $value = trim((string)preg_replace('/[^a-zA-Z0-9_-]+/', '_', strtolower($value)), '_');
    return substr($value, 0, 120);
}
