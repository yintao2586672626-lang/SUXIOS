#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\service\AirportForecastReferenceService;
use think\App;

require dirname(__DIR__) . '/vendor/autoload.php';
(new App())->initialize();

$options = getopt('', ['persist', 'source-2025:', 'source-2026:']);
$persist = array_key_exists('persist', $options);
$sourcePaths = [
    '2025' => trim((string)($options['source-2025'] ?? '')),
    '2026' => trim((string)($options['source-2026'] ?? '')),
];

try {
    $result = (new AirportForecastReferenceService())->sync($persist, $sourcePaths);
    echo json_encode(
        $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
    exit(in_array((string)($result['status'] ?? ''), ['preview_ready', 'success'], true) ? 0 : 2);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'status' => 'failed',
        'reason' => preg_replace('/[^a-zA-Z0-9:_-]+/', '_', $exception->getMessage()),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(2);
}
