<?php
declare(strict_types=1);

use app\service\CanonicalOtaInvestigationDraftService;
use think\App;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$autoload = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php is missing.\n");
    exit(1);
}
require $autoload;

$usage = implode(PHP_EOL, [
    'Usage:',
    '  php scripts/create_canonical_ota_investigation_drafts.php',
    '    --tenant-id=<id> --hotel-id=<id> --source-id=<id> --task-id=<id>',
    '    --row-id=<id> --platform=ctrip|meituan --date=<YYYY-MM-DD>',
    '    --period=<data_period> [--execute]',
    '',
    'Accepts one exact Ctrip or Meituan v3 authoritative zero or nonzero traffic row.',
    'Default mode is read-only preflight. Only --execute writes the local runtime JSON draft set; neither mode performs an OTA action.',
]);

/**
 * @param array<int,string> $arguments
 * @return array{scope:array<string,mixed>,execute:bool}
 */
function canonicalDraftCliArguments(array $arguments): array
{
    $fields = [
        'tenant-id' => 'tenant_id',
        'hotel-id' => 'hotel_id',
        'source-id' => 'data_source_id',
        'task-id' => 'task_id',
        'row-id' => 'row_id',
        'platform' => 'platform',
        'date' => 'target_date',
        'period' => 'data_period',
    ];
    $scope = [];
    $execute = false;
    foreach ($arguments as $argument) {
        if ($argument === '--execute') {
            if ($execute) {
                throw new InvalidArgumentException('duplicate_cli_argument');
            }
            $execute = true;
            continue;
        }
        if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
            throw new InvalidArgumentException('invalid_cli_argument');
        }
        [$name, $value] = explode('=', substr($argument, 2), 2);
        if (!array_key_exists($name, $fields)) {
            throw new InvalidArgumentException('unsupported_cli_argument');
        }
        $scopeField = $fields[$name];
        if (array_key_exists($scopeField, $scope)) {
            throw new InvalidArgumentException('duplicate_cli_argument');
        }
        if (trim($value) === '') {
            throw new InvalidArgumentException('empty_cli_argument');
        }
        $scope[$scopeField] = $value;
    }
    foreach ($fields as $scopeField) {
        if (!array_key_exists($scopeField, $scope)) {
            throw new InvalidArgumentException('required_cli_argument_missing');
        }
    }
    return ['scope' => $scope, 'execute' => $execute];
}

function canonicalDraftSafeErrorReason(Throwable $exception): string
{
    $reason = trim($exception->getMessage());
    if (strlen($reason) <= 160
        && preg_match(
            '/^(?:(?:canonical|synthetic)_[a-z0-9_]{1,120}|(?:invalid|unsupported|duplicate|empty)_cli_argument|required_cli_argument_missing)(?::[a-z][a-z0-9_]{0,79})?$/D',
            $reason
        ) === 1
    ) {
        return $reason;
    }
    return 'canonical_investigation_draft_unexpected_error';
}

$execute = false;
try {
    $parsed = canonicalDraftCliArguments(array_slice($argv, 1));
    $execute = $parsed['execute'];
    $app = new App(dirname(__DIR__));
    $app->initialize();

    $service = new CanonicalOtaInvestigationDraftService();
    $result = $service->run($parsed['scope'], $execute);
    echo json_encode(
        $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    ), PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    $error = [
        'status' => 'blocked',
        'execute' => $execute,
        'reason' => canonicalDraftSafeErrorReason($exception),
        'db_intent_written' => false,
        'external_action_triggered' => false,
    ];
    fwrite(STDERR, json_encode(
        $error,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    ) . PHP_EOL . $usage . PHP_EOL);
    exit(1);
}
