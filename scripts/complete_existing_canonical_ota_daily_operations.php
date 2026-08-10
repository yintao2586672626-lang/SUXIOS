<?php
declare(strict_types=1);

use app\service\CanonicalOtaDailyOperationFinalizer;
use app\service\OtaCanonicalHistoryPromotionCoordinator;
use app\service\OtaCanonicalHistoryPromotionService;
use app\service\P0OtaFieldLoopVerifierRunner;
use think\App;
use think\facade\Cache;
use think\facade\Db;

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
    '  php scripts/complete_existing_canonical_ota_daily_operations.php',
    '    --tenant-id=<id> --hotel-id=<id> --source-id=<id> --task-id=<id>',
    '    --platform=ctrip|meituan --date=<YYYY-MM-DD> --period=historical_daily [--execute]',
    '',
    'Default mode is read-only preflight. --execute promotes only existing exact rows and writes four local analysis-only checks.',
    'The command never triggers collection, an OTA write, an external action, or a business-outcome claim.',
]);

/** @param array<int,string> $arguments @return array<string,mixed> */
function canonicalExistingDailyOperationCliArguments(array $arguments): array
{
    $mapping = [
        'tenant-id' => 'tenant_id',
        'hotel-id' => 'hotel_id',
        'source-id' => 'data_source_id',
        'task-id' => 'task_id',
        'platform' => 'platform',
        'date' => 'target_date',
        'period' => 'data_period',
    ];
    $values = [];
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
        if (!isset($mapping[$name])) {
            throw new InvalidArgumentException('unsupported_cli_argument');
        }
        $field = $mapping[$name];
        if (array_key_exists($field, $values)) {
            throw new InvalidArgumentException('duplicate_cli_argument');
        }
        if (trim($value) === '') {
            throw new InvalidArgumentException('empty_cli_argument');
        }
        $values[$field] = trim($value);
    }
    foreach ($mapping as $field) {
        if (!array_key_exists($field, $values)) {
            throw new InvalidArgumentException('required_cli_argument_missing');
        }
    }
    foreach (['tenant_id', 'hotel_id', 'data_source_id', 'task_id'] as $field) {
        $validated = filter_var($values[$field], FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($validated === false) {
            throw new InvalidArgumentException('invalid_cli_argument');
        }
        $values[$field] = (int)$validated;
    }
    $values['platform'] = strtolower((string)$values['platform']);
    $values['data_period'] = strtolower((string)$values['data_period']);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$values['target_date']);
    if (!in_array($values['platform'], ['ctrip', 'meituan'], true)
        || $values['data_period'] !== 'historical_daily'
        || !$date instanceof DateTimeImmutable
        || $date->format('Y-m-d') !== $values['target_date']
    ) {
        throw new InvalidArgumentException('invalid_cli_argument');
    }
    $values['execute'] = $execute;
    return $values;
}

function canonicalExistingDailyOperationSafeReason(Throwable $exception): string
{
    $reason = trim($exception->getMessage());
    if (strlen($reason) <= 160
        && preg_match(
            '/^(?:(?:canonical|promotion|verifier)_[a-z0-9_:-]{1,140}|(?:invalid|unsupported|duplicate|empty)_cli_argument|required_cli_argument_missing)$/D',
            $reason
        ) === 1
    ) {
        return $reason;
    }
    return 'canonical_existing_daily_operation_unexpected_error';
}

/** @param array<string,mixed> $scope @return array<string,mixed> */
function canonicalExistingDailyCollectionReceipt(array $scope): array
{
    $task = Db::name('platform_data_sync_tasks')
        ->where('id', $scope['task_id'])
        ->where('tenant_id', $scope['tenant_id'])
        ->where('system_hotel_id', $scope['hotel_id'])
        ->where('data_source_id', $scope['data_source_id'])
        ->where('platform', $scope['platform'])
        ->find();
    if (!is_array($task) || strtolower(trim((string)($task['status'] ?? ''))) !== 'success') {
        throw new RuntimeException('canonical_existing_daily_operation_task_not_success');
    }
    $stats = json_decode((string)($task['stats_json'] ?? ''), true);
    $readback = is_array($stats) && is_array($stats['run_readback'] ?? null)
        ? $stats['run_readback']
        : [];
    $rowIds = array_values(array_unique(array_filter(array_map(
        'intval',
        is_array($readback['row_ids'] ?? null) ? $readback['row_ids'] : []
    ), static fn(int $id): bool => $id > 0)));
    sort($rowIds, SORT_NUMERIC);
    if (($readback['readback_verified'] ?? false) !== true
        || strtolower(trim((string)($readback['p0_status'] ?? ''))) !== 'ready'
        || (int)($readback['sync_task_id'] ?? 0) !== $scope['task_id']
        || (int)($readback['data_source_id'] ?? 0) !== $scope['data_source_id']
        || (int)($readback['system_hotel_id'] ?? 0) !== $scope['hotel_id']
        || strtolower(trim((string)($readback['platform'] ?? ''))) !== $scope['platform']
        || substr(trim((string)($readback['target_date'] ?? '')), 0, 10) !== $scope['target_date']
        || strtolower(trim((string)($readback['data_period'] ?? ''))) !== $scope['data_period']
        || $rowIds === []
    ) {
        throw new RuntimeException('canonical_existing_daily_operation_run_readback_invalid');
    }
    $sourceTask = [
        'data_source_id' => $scope['data_source_id'],
        'sync_task_id' => $scope['task_id'],
        'platform' => $scope['platform'],
        'collection_status' => 'success',
        'p0_status' => 'ready',
        'row_ids' => $rowIds,
    ];
    $anchor = hash('sha256', json_encode([$sourceTask], JSON_UNESCAPED_SLASHES));
    return [
        'hotel_id' => $scope['hotel_id'],
        'target_date' => $scope['target_date'],
        'data_period' => $scope['data_period'],
        'required_platforms' => [$scope['platform']],
        'source_tasks' => [$sourceTask],
        'collection_anchor_hash' => $anchor,
    ];
}

$execute = false;
try {
    $scope = canonicalExistingDailyOperationCliArguments(array_slice($argv, 1));
    $execute = (bool)$scope['execute'];
    unset($scope['execute']);
    $app = new App(dirname(__DIR__));
    $app->initialize();
    $collection = canonicalExistingDailyCollectionReceipt($scope);

    if (!$execute) {
        $verifier = (new P0OtaFieldLoopVerifierRunner())->verify(
            $scope['hotel_id'],
            $scope['target_date'],
            [$scope['platform']],
            $collection['collection_anchor_hash']
        );
        $promotion = (new OtaCanonicalHistoryPromotionService())->preflight(
            $collection,
            $verifier,
            $scope['platform'],
            $scope['tenant_id'],
            $scope['hotel_id']
        );
        $ready = ($verifier['authority_ready'] ?? false) === true
            && ($promotion['status'] ?? '') === 'ready';
        echo json_encode([
            'status' => $ready ? 'ready' : 'blocked',
            'execute' => false,
            'reason' => $ready ? '' : (string)($promotion['reason'] ?? $verifier['reason'] ?? 'verifier_not_ready'),
            'scope' => $scope,
            'row_ids' => $collection['source_tasks'][0]['row_ids'],
            'verifier_status' => (string)($verifier['status'] ?? ''),
            'promotion_status' => (string)($promotion['status'] ?? ''),
            'would_promote_count' => (int)($promotion['would_promote_count'] ?? 0),
            'promotion_row_ids' => array_values(array_map(
                'intval',
                is_array($promotion['row_ids'] ?? null) ? $promotion['row_ids'] : []
            )),
            'selected_operation_row_id' => (int)($promotion['selected_operation_row_id'] ?? 0),
            'planned_operational_check_count' => $ready ? 4 : 0,
            'collection_triggered' => false,
            'external_action_triggered' => false,
            'sensitive_values_exposed' => false,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
        exit($ready ? 0 : 2);
    }

    $finalization = (new OtaCanonicalHistoryPromotionCoordinator())->finalize(
        $collection,
        $scope['tenant_id'],
        $scope['hotel_id']
    );
    $status = Cache::get('online_data_auto_fetch_status_' . $scope['hotel_id'], []);
    $status = is_array($status) ? $status : [];
    $grants = is_array($status['canonical_daily_analysis_authorizations'] ?? null)
        ? $status['canonical_daily_analysis_authorizations']
        : [];
    if ($scope['platform'] === 'ctrip'
        && is_array($status['canonical_daily_analysis_authorization'] ?? null)
    ) {
        $grants['ctrip'] = $grants['ctrip'] ?? $status['canonical_daily_analysis_authorization'];
    }
    $analysis = (new CanonicalOtaDailyOperationFinalizer())->finalize(
        $collection,
        $finalization,
        $scope['tenant_id'],
        $scope['hotel_id'],
        $grants
    );
    $verified = ($analysis['status'] ?? '') === 'verified'
        && (int)($analysis['trusted_operational_check_count'] ?? 0) === 4;
    echo json_encode([
        'status' => $verified ? 'completed' : 'blocked',
        'execute' => true,
        'reason' => $verified ? '' : (string)($analysis['reason'] ?? 'canonical_operation_incomplete'),
        'scope' => $analysis['scope'] ?? $scope,
        'selected_platform' => (string)($analysis['selected_platform'] ?? ''),
        'promotion_status' => (string)($finalization['platform_results'][$scope['platform']]['status'] ?? ''),
        'promotion_row_ids' => $finalization['platform_results'][$scope['platform']]['promotion']['row_ids'] ?? [],
        'selected_operation_row_id' => (int)(
            $finalization['platform_results'][$scope['platform']]['promotion']['selected_operation_row_id'] ?? 0
        ),
        'trusted_operational_check_count' => (int)($analysis['trusted_operational_check_count'] ?? 0),
        'trusted_external_operation_count' => (int)($analysis['trusted_external_operation_count'] ?? 0),
        'records' => $analysis['records'] ?? [],
        'draft_readback_verified' => ($analysis['draft_readback_verified'] ?? false) === true,
        'db_readback_verified' => ($analysis['db_readback_verified'] ?? false) === true,
        'operation_flow_readback_verified' =>
            ($analysis['operation_flow_readback_verified'] ?? false) === true,
        'collection_triggered' => false,
        'external_action_triggered' => false,
        'business_outcome_claimed' => false,
        'causality_claimed' => false,
        'sensitive_values_exposed' => false,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit($verified ? 0 : 2);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'status' => 'blocked',
        'execute' => $execute,
        'reason' => canonicalExistingDailyOperationSafeReason($exception),
        'trusted_operational_check_count' => 0,
        'trusted_external_operation_count' => 0,
        'collection_triggered' => false,
        'external_action_triggered' => false,
        'business_outcome_claimed' => false,
        'sensitive_values_exposed' => false,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL . $usage . PHP_EOL);
    exit(1);
}
