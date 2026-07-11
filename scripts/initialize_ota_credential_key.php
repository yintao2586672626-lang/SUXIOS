<?php
declare(strict_types=1);

use app\service\OtaCredentialKeyInitializer;

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

$execute = false;
$envPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
$invalidArguments = false;

for ($index = 1, $count = count($argv); $index < $count; $index++) {
    $argument = (string)$argv[$index];
    if ($argument === '--execute') {
        $execute = true;
        continue;
    }
    if ($argument === '--env' && isset($argv[$index + 1])) {
        $envPath = (string)$argv[++$index];
        continue;
    }
    if (str_starts_with($argument, '--env=')) {
        $envPath = substr($argument, strlen('--env='));
        continue;
    }
    $invalidArguments = true;
}

$safeFailure = static fn(string $reasonCode): array => [
    'mode' => $execute ? 'execute' : 'dry-run',
    'status' => 'blocked',
    'configured' => false,
    'initialized' => false,
    'key_id' => null,
    'fingerprint' => null,
    'reason_code' => $reasonCode,
];

try {
    $summary = $invalidArguments || trim($envPath) === ''
        ? $safeFailure('invalid_arguments')
        : (new OtaCredentialKeyInitializer())->run($envPath, $execute);
} catch (Throwable) {
    $summary = $safeFailure('initialization_failed');
}

echo json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
exit($summary['status'] === 'blocked' ? 1 : 0);
