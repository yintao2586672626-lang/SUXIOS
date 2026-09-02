<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bootstrap.php';

date_default_timezone_set('Asia/Shanghai');

use app\service\FileManualOnlineFetchTaskStatusStore;
use app\service\ManualOnlineFetchTaskService;

$taskRoot = trim((string)($argv[1] ?? ''));
$taskId = trim((string)($argv[2] ?? ''));
$ownerId = trim((string)($argv[3] ?? ''));
$resultPath = trim((string)($argv[4] ?? ''));
if ($taskRoot === '' || $taskId === '' || $ownerId === '' || $resultPath === '') {
    exit(2);
}

$store = new FileManualOnlineFetchTaskStatusStore($taskRoot);
$service = new ManualOnlineFetchTaskService($store, $taskRoot);
$result = $service->claimTaskForExecution($taskId, $ownerId);
$encoded = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
exit(file_put_contents($resultPath, $encoded, LOCK_EX) === false ? 3 : 0);
