<?php
declare(strict_types=1);

use app\service\CanonicalOtaDailyNaturalAcceptanceService;
use think\App;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$autoload = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (!is_file($autoload)) {
    exit(1);
}
require $autoload;

/** @param array<int,string> $arguments @return array<string,mixed> */
function canonicalNaturalAcceptanceArguments(array $arguments): array
{
    $allowed = [
        'hotel-id' => 'hotel_id',
        'target-date' => 'target_date',
        'source-ids' => 'source_ids',
        'platforms' => 'platforms',
        'dispatcher-log' => 'dispatcher_log',
    ];
    $values = [];
    foreach ($arguments as $argument) {
        if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
            throw new InvalidArgumentException('daily_acceptance_cli_argument_invalid');
        }
        [$name, $value] = explode('=', substr($argument, 2), 2);
        if (!isset($allowed[$name]) || array_key_exists($allowed[$name], $values)) {
            throw new InvalidArgumentException('daily_acceptance_cli_argument_invalid');
        }
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException('daily_acceptance_cli_argument_invalid');
        }
        $values[$allowed[$name]] = $value;
    }
    if (count($values) !== count($allowed)) {
        throw new InvalidArgumentException('daily_acceptance_cli_argument_missing');
    }
    if (!ctype_digit($values['hotel_id']) || (int)$values['hotel_id'] <= 0) {
        throw new InvalidArgumentException('daily_acceptance_cli_scope_invalid');
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $values['target_date']);
    if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $values['target_date']) {
        throw new InvalidArgumentException('daily_acceptance_cli_scope_invalid');
    }
    $sourceIds = [];
    foreach (explode(',', $values['source_ids']) as $sourceId) {
        $sourceId = trim($sourceId);
        if (!ctype_digit($sourceId) || (int)$sourceId <= 0) {
            throw new InvalidArgumentException('daily_acceptance_cli_scope_invalid');
        }
        $sourceIds[(int)$sourceId] = (int)$sourceId;
    }
    $sourceIds = array_values($sourceIds);
    sort($sourceIds, SORT_NUMERIC);
    $platforms = [];
    foreach (explode(',', strtolower($values['platforms'])) as $platform) {
        $platform = trim($platform);
        if (!in_array($platform, ['ctrip', 'meituan'], true)) {
            throw new InvalidArgumentException('daily_acceptance_cli_scope_invalid');
        }
        $platforms[$platform] = $platform;
    }
    $platforms = array_values($platforms);
    sort($platforms, SORT_STRING);
    if ($platforms !== ['ctrip', 'meituan'] || count($sourceIds) !== 2) {
        throw new InvalidArgumentException('daily_acceptance_cli_scope_invalid');
    }
    return [
        'hotel_id' => (int)$values['hotel_id'],
        'target_date' => $values['target_date'],
        'source_ids' => $sourceIds,
        'platforms' => $platforms,
        'dispatcher_log' => $values['dispatcher_log'],
    ];
}

/** @return array<string,mixed> */
function canonicalNaturalAcceptanceBlocked(string $reason): array
{
    $safeReason = preg_match('/^[a-z][a-z0-9_]{0,119}$/D', $reason) === 1
        ? $reason
        : 'daily_acceptance_unexpected_error';
    return [
        'schema_version' => CanonicalOtaDailyNaturalAcceptanceService::SCHEMA_VERSION,
        'status' => 'blocked',
        'reason_codes' => [$safeReason],
        'stability' => [
            'status' => 'collecting_evidence',
            'consecutive_verified_natural_days' => 0,
            'required_days' => CanonicalOtaDailyNaturalAcceptanceService::REQUIRED_STABLE_DAYS,
            'stable' => false,
            'reason' => 'streak_below_three',
        ],
        'collection_triggered_by_acceptance' => false,
        'business_data_written_by_acceptance' => false,
        'external_action_triggered' => false,
        'business_outcome_claimed' => false,
        'causality_claimed' => false,
        'sensitive_values_exposed' => false,
    ];
}

try {
    $arguments = canonicalNaturalAcceptanceArguments(array_slice($argv, 1));
    $app = new App(dirname(__DIR__));
    $app->initialize();
    $dispatcherDirectory = dirname($arguments['dispatcher_log']);
    $receipt = (new CanonicalOtaDailyNaturalAcceptanceService())->inspect(
        $arguments['hotel_id'],
        $arguments['target_date'],
        $arguments['source_ids'],
        $arguments['platforms'],
        $arguments['dispatcher_log'],
        $dispatcherDirectory
    );
} catch (Throwable $exception) {
    $message = strtolower(trim($exception->getMessage()));
    $receipt = canonicalNaturalAcceptanceBlocked($message);
}

$json = json_encode($receipt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($json)) {
    $json = json_encode(canonicalNaturalAcceptanceBlocked('daily_acceptance_encoding_failed'));
}
echo CanonicalOtaDailyNaturalAcceptanceService::LINE_PREFIX, $json, PHP_EOL;
exit(($receipt['status'] ?? '') === 'verified' ? 0 : 2);
