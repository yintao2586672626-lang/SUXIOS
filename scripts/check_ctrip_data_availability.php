<?php
declare(strict_types=1);

use think\App;
use think\facade\Db;

require __DIR__ . '/../vendor/autoload.php';

date_default_timezone_set('Asia/Shanghai');

/** @return array{system_hotel_id:int,data_source_id:int,target_date:string} */
function ctrip_availability_options(array $argv): array
{
    $options = [
        'system_hotel_id' => 0,
        'data_source_id' => 0,
        'target_date' => '',
    ];
    foreach (array_slice($argv, 1) as $argument) {
        if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
            continue;
        }
        [$key, $value] = explode('=', substr($argument, 2), 2);
        $value = trim($value);
        if (in_array($key, ['system-hotel-id', 'system_hotel_id'], true)) {
            $options['system_hotel_id'] = ctype_digit($value) ? (int)$value : 0;
        } elseif (in_array($key, ['data-source-id', 'data_source_id'], true)) {
            $options['data_source_id'] = ctype_digit($value) ? (int)$value : 0;
        } elseif (in_array($key, ['target-date', 'target_date'], true)) {
            $options['target_date'] = $value;
        }
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $options['target_date'], new DateTimeZone('Asia/Shanghai'));
    if ($options['system_hotel_id'] <= 0
        || $options['data_source_id'] <= 0
        || !$date
        || $date->format('Y-m-d') !== $options['target_date']
    ) {
        throw new InvalidArgumentException('system hotel, data source, and target date must be explicit and valid');
    }
    return $options;
}

/** @param array<string, mixed> $row */
function ctrip_availability_identity(array $row): string
{
    $source = strtolower(trim((string)($row['source'] ?? '')));
    $platform = strtolower(trim((string)($row['platform'] ?? '')));
    if ($source === 'qunar' || $platform === 'qunar') {
        return 'qunar';
    }
    if ($source === 'ctrip' || $platform === 'ctrip') {
        return 'ctrip';
    }
    return '';
}

/** @param array<string, mixed> $row */
function ctrip_availability_positive_traffic(array $row): bool
{
    if (!in_array(strtolower(trim((string)($row['data_type'] ?? ''))), ['traffic', 'flow', 'conversion'], true)) {
        return false;
    }
    foreach (['list_exposure', 'detail_exposure', 'order_filling_num', 'order_submit_num'] as $field) {
        if (isset($row[$field]) && is_numeric($row[$field]) && (float)$row[$field] > 0.0) {
            return true;
        }
    }
    return false;
}

/** @param array<string, mixed> $payload */
function ctrip_availability_output(array $payload, int $exitCode): never
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
    exit($exitCode);
}

try {
    $options = ctrip_availability_options($argv);
    $app = new App();
    $app->initialize();

    $columns = array_fill_keys(array_column(Db::query('SHOW COLUMNS FROM online_daily_data'), 'Field'), true);
    $required = [
        'id', 'system_hotel_id', 'data_source_id', 'data_date', 'source', 'platform',
        'data_type', 'readback_verified', 'source_trace_id', 'list_exposure', 'detail_exposure',
        'order_filling_num', 'order_submit_num', 'sync_task_id', 'update_time',
    ];
    $missing = array_values(array_diff($required, array_keys($columns)));
    if ($missing !== []) {
        ctrip_availability_output([
            'schema_version' => 1,
            'status' => 'blocked',
            'error_code' => 'online_daily_data_contract_missing',
            'missing_columns' => $missing,
            'available' => false,
            'claim_allowed' => false,
        ], 2);
    }

    $rows = Db::name('online_daily_data')
        ->where('system_hotel_id', $options['system_hotel_id'])
        ->where('data_source_id', $options['data_source_id'])
        ->where('data_date', $options['target_date'])
        ->where('readback_verified', 1)
        ->field(implode(',', $required))
        ->order('id', 'desc')
        ->limit(5000)
        ->select()
        ->toArray();

    $ctripRows = [];
    $qunarRows = [];
    $qunarPositiveRows = [];
    $latestSyncTaskId = 0;
    $latestUpdateTime = '';
    $qunarMaxListExposure = 0.0;
    $qunarMaxDetailExposure = 0.0;

    foreach ($rows as $row) {
        if (!is_array($row) || trim((string)($row['source_trace_id'] ?? '')) === '') {
            continue;
        }
        $identity = ctrip_availability_identity($row);
        if ($identity === 'ctrip') {
            $ctripRows[] = $row;
        } elseif ($identity === 'qunar') {
            $qunarRows[] = $row;
            if (ctrip_availability_positive_traffic($row)) {
                $qunarPositiveRows[] = $row;
            }
            $qunarMaxListExposure = max($qunarMaxListExposure, (float)($row['list_exposure'] ?? 0));
            $qunarMaxDetailExposure = max($qunarMaxDetailExposure, (float)($row['detail_exposure'] ?? 0));
        }
        $latestSyncTaskId = max($latestSyncTaskId, (int)($row['sync_task_id'] ?? 0));
        $updateTime = trim((string)($row['update_time'] ?? ''));
        if ($updateTime > $latestUpdateTime) {
            $latestUpdateTime = $updateTime;
        }
    }

    $ctripReadbackPresent = $ctripRows !== [];
    $qunarTrafficPositive = $qunarPositiveRows !== [];
    $available = $ctripReadbackPresent && $qunarTrafficPositive;
    $collectionTaskVerified = false;
    $collectionTaskStatus = '';
    if ($latestSyncTaskId > 0 && Db::query("SHOW TABLES LIKE 'platform_data_sync_tasks'") !== []) {
        $task = Db::name('platform_data_sync_tasks')
            ->where('id', $latestSyncTaskId)
            ->where('system_hotel_id', $options['system_hotel_id'])
            ->where('data_source_id', $options['data_source_id'])
            ->field('id,status,stats_json')
            ->find();
        if (is_array($task)) {
            $collectionTaskStatus = strtolower(trim((string)($task['status'] ?? '')));
            $stats = json_decode((string)($task['stats_json'] ?? ''), true);
            $stats = is_array($stats) ? $stats : [];
            $runReadback = is_array($stats['run_readback'] ?? null) ? $stats['run_readback'] : [];
            $taskTargetDate = substr(trim((string)($runReadback['target_date'] ?? $stats['target_date'] ?? '')), 0, 10);
            $collectionTaskVerified = $collectionTaskStatus === 'success'
                && ($runReadback['readback_verified'] ?? false) === true
                && $taskTargetDate === $options['target_date'];
        }
    }
    $gaps = [];
    if (!$ctripReadbackPresent) {
        $gaps[] = 'ctrip_readback_missing';
    }
    if (!$qunarTrafficPositive) {
        $gaps[] = 'qunar_traffic_not_positive';
    }

    ctrip_availability_output([
        'schema_version' => 1,
        'status' => $available ? 'available' : 'waiting',
        'source' => 'ctrip_browser_profile',
        'system_hotel_id' => $options['system_hotel_id'],
        'data_source_id' => $options['data_source_id'],
        'target_date' => $options['target_date'],
        'observed_at' => date('Y-m-d H:i:s'),
        'criteria' => [
            'ctrip_readback_present' => $ctripReadbackPresent,
            'qunar_traffic_positive' => $qunarTrafficPositive,
        ],
        'evidence' => [
            'ctrip_readback_rows' => count($ctripRows),
            'qunar_readback_rows' => count($qunarRows),
            'qunar_positive_traffic_rows' => count($qunarPositiveRows),
            'qunar_list_exposure_max' => $qunarMaxListExposure,
            'qunar_detail_exposure_max' => $qunarMaxDetailExposure,
            'latest_sync_task_id' => $latestSyncTaskId,
            'latest_update_time' => $latestUpdateTime,
        ],
        'collection_task_verified' => $collectionTaskVerified,
        'collection_task_status' => $collectionTaskStatus,
        'available' => $available,
        'claim_allowed' => $available,
        'gaps' => $gaps,
    ], $available ? 0 : 3);
} catch (Throwable $error) {
    ctrip_availability_output([
        'schema_version' => 1,
        'status' => 'blocked',
        'error_code' => str_contains($error->getMessage(), 'SQLSTATE') ? 'database_unavailable' : 'availability_check_failed',
        'exception_type' => get_debug_type($error),
        'available' => false,
        'claim_allowed' => false,
    ], 2);
}
