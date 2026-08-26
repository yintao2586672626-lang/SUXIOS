#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\service\HotelManagerInterviewKnowledgeSyncService;
use think\App;

require dirname(__DIR__) . '/vendor/autoload.php';
$projectRoot = dirname(__DIR__);
foreach (spl_autoload_functions() ?: [] as $autoloadFunction) {
    $loader = is_array($autoloadFunction) ? ($autoloadFunction[0] ?? null) : null;
    if ($loader instanceof \Composer\Autoload\ClassLoader) {
        $loader->setPsr4('app\\', [$projectRoot . DIRECTORY_SEPARATOR . 'app']);
    }
}
(new App($projectRoot))->initialize();

$options = getopt('', ['persist', 'source-interview:', 'source-distillation:']);
$persist = array_key_exists('persist', $options);
$sourcePaths = [
    'manager_interview_questions' => trim((string)($options['source-interview'] ?? '')),
    'distillation_controller_prompt' => trim((string)($options['source-distillation'] ?? '')),
];

try {
    $result = (new HotelManagerInterviewKnowledgeSyncService(null, $sourcePaths))->sync($persist);
    echo json_encode(
        $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
    exit(in_array((string)($result['status'] ?? ''), ['validated', 'success'], true) ? 0 : 2);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'status' => 'failed',
        'reason' => preg_replace('/[^a-zA-Z0-9:_-]+/', '_', $exception->getMessage()),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(2);
}
