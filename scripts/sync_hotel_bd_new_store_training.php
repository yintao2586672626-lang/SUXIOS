#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\service\HotelBdNewStoreTrainingSyncService;
use think\App;

require dirname(__DIR__) . '/vendor/autoload.php';
(new App())->initialize();

$options = getopt('', ['persist', 'source:']);
$persist = array_key_exists('persist', $options);
$sourcePath = isset($options['source']) ? trim((string)$options['source']) : null;

try {
    if ($persist && ($sourcePath === null || $sourcePath === '')) {
        throw new RuntimeException('hotel_bd_training_source_verification_required');
    }
    $result = (new HotelBdNewStoreTrainingSyncService(null, $sourcePath))->sync($persist);
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
