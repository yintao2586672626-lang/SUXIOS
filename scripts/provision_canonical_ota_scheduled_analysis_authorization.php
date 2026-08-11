<?php
declare(strict_types=1);

use app\service\CanonicalOtaScheduledAnalysisAuthorizationProvisioningService;
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
    '  php scripts/provision_canonical_ota_scheduled_analysis_authorization.php',
    '    --tenant-id=<id> --hotel-id=<id> --platform=ctrip|meituan --plan-id=<id> [--execute]',
    '',
    'Default mode is read-only preview. --execute stores and exactly reads back one local analysis-only grant.',
    'The command never triggers OTA collection, an OTA write, an external action, or a business-outcome claim.',
]);

/** @param array<int,string> $arguments @return array{tenant_id:int,hotel_id:int,platform:string,plan_id:string,execute:bool} */
function canonicalScheduledAnalysisAuthorizationCliArguments(array $arguments): array
{
    $values = [];
    $execute = false;
    $allowed = ['tenant-id', 'hotel-id', 'platform', 'plan-id'];
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
        if (!in_array($name, $allowed, true)) {
            throw new InvalidArgumentException('unsupported_cli_argument');
        }
        if (array_key_exists($name, $values)) {
            throw new InvalidArgumentException('duplicate_cli_argument');
        }
        if (trim($value) === '') {
            throw new InvalidArgumentException('empty_cli_argument');
        }
        $values[$name] = trim($value);
    }
    foreach ($allowed as $name) {
        if (!array_key_exists($name, $values)) {
            throw new InvalidArgumentException('required_cli_argument_missing');
        }
    }
    $tenantId = filter_var($values['tenant-id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $hotelId = filter_var($values['hotel-id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($tenantId === false || $hotelId === false) {
        throw new InvalidArgumentException('invalid_cli_argument');
    }
    return [
        'tenant_id' => (int)$tenantId,
        'hotel_id' => (int)$hotelId,
        'platform' => strtolower($values['platform']),
        'plan_id' => strtolower($values['plan-id']),
        'execute' => $execute,
    ];
}

function canonicalScheduledAnalysisAuthorizationSafeReason(Throwable $exception): string
{
    $reason = trim($exception->getMessage());
    if (strlen($reason) <= 160
        && preg_match(
            '/^(?:(?:canonical)_[a-z0-9_]{1,140}|(?:invalid|unsupported|duplicate|empty)_cli_argument|required_cli_argument_missing)(?::[a-z][a-z0-9_]{0,79})?$/D',
            $reason
        ) === 1
    ) {
        return $reason;
    }
    return 'canonical_scheduled_analysis_authorization_unexpected_error';
}

$execute = false;
try {
    $arguments = canonicalScheduledAnalysisAuthorizationCliArguments(array_slice($argv, 1));
    $execute = $arguments['execute'];
    $app = new App(dirname(__DIR__));
    $app->initialize();
    $service = new CanonicalOtaScheduledAnalysisAuthorizationProvisioningService();
    $result = $execute
        ? $service->execute(
            $arguments['tenant_id'],
            $arguments['hotel_id'],
            $arguments['platform'],
            $arguments['plan_id']
        )
        : $service->preview(
            $arguments['tenant_id'],
            $arguments['hotel_id'],
            $arguments['platform'],
            $arguments['plan_id']
        );
    echo json_encode(
        $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    ), PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'status' => 'blocked',
        'execute' => $execute,
        'reason' => canonicalScheduledAnalysisAuthorizationSafeReason($exception),
        'authorization_written' => $execute ? null : false,
        'readback_verified' => false,
        'collection_triggered' => false,
        'external_action_triggered' => false,
        'business_outcome_claimed' => false,
        'sensitive_values_exposed' => false,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL . $usage . PHP_EOL);
    exit(1);
}
