<?php
declare(strict_types=1);

use think\App;
use think\facade\Db;

date_default_timezone_set('Asia/Shanghai');

$root = dirname(__DIR__);
require $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

/**
 * @param array<int, string> $argv
 * @return array<string, mixed>
 */
function ctrip_review_match_closure_parse_args(array $argv): array
{
    $options = [
        'system-hotel-id' => '58',
        'system_hotel_id' => '',
        'hotel-id' => '',
        'hotel_id' => '',
        'min-matched' => '1',
        'min_matched' => '',
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
            continue;
        }
        [$key, $value] = explode('=', substr($arg, 2), 2);
        if (array_key_exists($key, $options)) {
            $options[$key] = trim($value);
        }
    }

    foreach (['system_hotel_id', 'hotel-id', 'hotel_id'] as $key) {
        if ((string)$options[$key] !== '') {
            $options['system-hotel-id'] = (string)$options[$key];
            break;
        }
    }
    if ((string)$options['min_matched'] !== '') {
        $options['min-matched'] = (string)$options['min_matched'];
    }

    $systemHotelId = (string)$options['system-hotel-id'];
    if ($systemHotelId === '' || !ctype_digit($systemHotelId) || (int)$systemHotelId <= 0) {
        throw new InvalidArgumentException('Invalid --system-hotel-id, expected a positive integer.');
    }

    $minMatched = (string)$options['min-matched'];
    if ($minMatched === '' || !ctype_digit($minMatched)) {
        throw new InvalidArgumentException('Invalid --min-matched, expected a non-negative integer.');
    }

    return [
        'system_hotel_id' => (int)$systemHotelId,
        'min_matched' => (int)$minMatched,
    ];
}

/**
 * @param array<string, mixed> $payload
 */
function ctrip_review_match_closure_finish(array $payload, int $exitCode): void
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($exitCode);
}

/**
 * @return array<string, int>
 */
function ctrip_review_match_closure_table_counts(int $systemHotelId): array
{
    return [
        'ctrip_reviews' => (int)Db::name('ota_ctrip_reviews')->where('system_hotel_id', $systemHotelId)->count(),
        'ctrip_im_sessions' => (int)Db::name('ota_ctrip_im_sessions')->where('system_hotel_id', $systemHotelId)->count(),
        'ctrip_orders' => (int)Db::name('ota_ctrip_orders')->where('system_hotel_id', $systemHotelId)->count(),
        'ctrip_review_order_matches' => (int)Db::name('ota_ctrip_review_order_matches')->where('system_hotel_id', $systemHotelId)->count(),
    ];
}

/**
 * @return array<string, int>
 */
function ctrip_review_match_closure_status_counts(int $systemHotelId): array
{
    $rows = Db::name('ota_ctrip_review_order_matches')
        ->where('system_hotel_id', $systemHotelId)
        ->field('match_status, COUNT(*) AS total')
        ->group('match_status')
        ->select()
        ->toArray();

    $counts = [];
    foreach ($rows as $row) {
        $status = trim((string)($row['match_status'] ?? ''));
        if ($status === '') {
            $status = 'unknown';
        }
        $counts[$status] = (int)($row['total'] ?? 0);
    }

    return $counts;
}

try {
    $options = ctrip_review_match_closure_parse_args($argv);
    $app = new App($root);
    $app->initialize();

    $systemHotelId = (int)$options['system_hotel_id'];
    $counts = ctrip_review_match_closure_table_counts($systemHotelId);
    $statusCounts = ctrip_review_match_closure_status_counts($systemHotelId);
    $matchedCount = (int)($statusCounts['found'] ?? 0) + (int)($statusCounts['matched'] ?? 0);

    $missingSources = [];
    foreach ([
        'ctrip_reviews',
        'ctrip_im_sessions',
        'ctrip_orders',
    ] as $key) {
        if (($counts[$key] ?? 0) <= 0) {
            $missingSources[] = $key;
        }
    }
    if ($matchedCount < (int)$options['min_matched']) {
        $missingSources[] = 'matched_results';
    }

    $ready = $missingSources === [];
    ctrip_review_match_closure_finish([
        'status' => $ready ? 'passed' : 'failed',
        'scope' => 'ctrip_ota_channel',
        'system_hotel_id' => $systemHotelId,
        'source_tables' => $counts,
        'match_status_counts' => $statusCounts,
        'required' => [
            'min_matched' => (int)$options['min_matched'],
            'required_sources' => ['ctrip_reviews', 'ctrip_im_sessions', 'ctrip_orders'],
            'accepted_match_statuses' => ['found', 'matched'],
        ],
        'missing_sources' => array_values(array_unique($missingSources)),
        'next_action' => $ready
            ? '携程评价匹配闭环已由真实入库数据证明'
            : '导入真实授权的携程评价明细、IM members 和订单池，并执行入库匹配后重跑本验证',
    ], $ready ? 0 : 2);
} catch (Throwable $e) {
    ctrip_review_match_closure_finish([
        'status' => 'failed',
        'scope' => 'ctrip_ota_channel',
        'error' => $e->getMessage(),
    ], 1);
}
