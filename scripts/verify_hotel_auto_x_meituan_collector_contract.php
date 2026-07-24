<?php
declare(strict_types=1);

use app\controller\concern\MeituanCapturedDataConcern;
use app\controller\concern\MeituanUtilityConcern;
use app\controller\concern\OnlineDataHistoryConcern;
use app\service\BrowserProfileCaptureRequestService;

$root = dirname(__DIR__);
require $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

$checks = [];
$failures = [];

$check = static function (string $name, bool $passed, array $evidence = []) use (&$checks, &$failures): void {
    $checks[] = [
        'name' => $name,
        'passed' => $passed,
        'evidence' => $evidence,
    ];
    if (!$passed) {
        $failures[] = $name;
    }
};

$harness = new class {
    use MeituanCapturedDataConcern {
        buildMeituanCapturedDailyRows as public buildRows;
        summarizeMeituanCapturedRows as public summarizeRows;
    }
    use MeituanUtilityConcern;
    use OnlineDataHistoryConcern {
        buildHistoryMetricSummary as public summarizeHistoryMetric;
    }

    private function normalizeOnlineDataDate($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $rawText = trim((string)$value);
        if (preg_match('/^(19|20)\d{6}$/', $rawText)) {
            $year = (int)substr($rawText, 0, 4);
            $month = (int)substr($rawText, 4, 2);
            $day = (int)substr($rawText, 6, 2);
            if (checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }
        if (is_numeric($value)) {
            $timestamp = (int)$value;
            if ($timestamp > 9999999999) {
                $timestamp = (int)floor($timestamp / 1000);
            }
            return date('Y-m-d', $timestamp);
        }
        if (preg_match('/(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/', $rawText, $matches)) {
            return sprintf('%04d-%02d-%02d', (int)$matches[1], (int)$matches[2], (int)$matches[3]);
        }
        $timestamp = strtotime($rawText);
        return $timestamp === false ? '' : date('Y-m-d', $timestamp);
    }
};

$profileSectionCases = [
    'full' => 'traffic,orders,ads,reviews',
    'complete' => 'traffic,orders,ads,reviews',
    'realtime' => 'traffic',
    'realtime_snapshot' => 'traffic',
    'comments' => 'reviews',
    'advertising' => 'ads',
];
foreach ($profileSectionCases as $input => $expected) {
    $actual = BrowserProfileCaptureRequestService::normalizeMeituanProfileSections($input);
    $check(
        'Meituan section alias maps ' . $input,
        $actual === $expected,
        ['input' => $input, 'expected' => $expected, 'actual' => $actual]
    );
}

$plan = BrowserProfileCaptureRequestService::buildMeituanPlan(
    [
        'store_id' => '68471',
        'poi_id' => '68471',
        'poi_name' => 'Mock Meituan Hotel',
        'sections' => 'full',
        'ads_url' => 'https://eb.meituan.com/mock-ad-entry',
        'data_period' => 'historical_daily',
        'snapshot_time' => '2026-07-08 13:00:00',
    ],
    $root,
    'node',
    false,
    117,
    '20260708T130000'
);
$planArgs = $plan['args'] ?? [];
$check(
    'browser plan exposes full capture, ads entry, and period metadata',
    in_array('--sections=traffic,orders,ads,reviews', $planArgs, true)
        && in_array('--ads-url=https://eb.meituan.com/mock-ad-entry', $planArgs, true)
        && in_array('--data-period=historical_daily', $planArgs, true)
        && in_array('--snapshot-time=2026-07-08 13:00:00', $planArgs, true),
    ['args' => $planArgs]
);

$fullPayload = [
    'store_id' => '68471',
    'poi_id' => '68471',
    'poi_name' => 'Mock Meituan Hotel',
    'default_data_date' => '2026-07-07',
    'data_period' => 'historical_daily',
    'captured_at' => '2026-07-08T05:00:00Z',
    'traffic' => [[
        'mt_exposure' => 1200,
        'mt_intention_uv' => 320,
        'mt_pay_orders' => 16,
        'mt_pay_rooms' => 20,
        'mt_conversion_rate' => '26.67%',
        '_source_path' => 'mock.traffic.0',
    ]],
    'orders' => [[
        'order_id' => 'ORDER-SHOULD-BE-HASHED',
        'order_status' => 'confirmed',
        'total_amount' => 888,
        'room_count' => 2,
        'nights' => 2,
        'guest_name' => 'Alice',
        'phone' => '13800138000',
        '_source_path' => 'mock.orders.0',
    ]],
    'ads' => [[
        'exposure_count' => 5000,
        'click_count' => 200,
        'spend' => 300,
        'order_amount' => 1800,
        'orders' => 9,
        '_source_path' => 'mock.ads.0',
    ]],
    'reviews' => [[
        'commentScore' => 4.6,
        'commentCount' => 23,
        'badReviewCount' => 2,
        'commentTime' => '2026-07-07 10:00:00',
        'commentContent' => 'review text should not be stored',
        'userName' => 'Bob',
        'phone' => '13900139000',
        '_source_path' => 'mock.reviews.0',
    ]],
];
$fullRows = $harness->buildRows($fullPayload, 117);
$fullCounts = $harness->summarizeRows($fullRows);
$check(
    'simulated full capture maps traffic/order/advertising/review rows',
    ($fullCounts['traffic'] ?? 0) === 1
        && ($fullCounts['order'] ?? 0) === 1
        && ($fullCounts['advertising'] ?? 0) === 1
        && ($fullCounts['review'] ?? 0) === 1,
    ['counts' => $fullCounts]
);

$rowsByType = [];
foreach ($fullRows as $row) {
    $rowsByType[(string)($row['data_type'] ?? '')] = $row;
}

$trafficRaw = json_decode((string)($rowsByType['traffic']['raw_data'] ?? '{}'), true) ?: [];
$trafficFactKeys = array_values(array_filter(array_map(
    static fn(array $fact): string => (string)($fact['metric_key'] ?? ''),
    is_array($trafficRaw['field_facts'] ?? null) ? $trafficRaw['field_facts'] : []
)));
$check(
    'realtime traffic facts expose Meituan core metrics',
    in_array('mt_exposure', $trafficFactKeys, true)
        && in_array('mt_pay_orders', $trafficFactKeys, true)
        && (int)($rowsByType['traffic']['book_order_num'] ?? 0) === 16
        && (int)($rowsByType['traffic']['quantity'] ?? 0) === 20,
    ['fact_keys' => $trafficFactKeys, 'traffic_row' => $rowsByType['traffic'] ?? []]
);

$adsRaw = json_decode((string)($rowsByType['advertising']['raw_data'] ?? '{}'), true) ?: [];
$adsFactKeys = array_values(array_filter(array_map(
    static fn(array $fact): string => (string)($fact['metric_key'] ?? ''),
    is_array($adsRaw['field_facts'] ?? null) ? $adsRaw['field_facts'] : []
)));
$check(
    'advertising row preserves spend and order evidence',
    in_array('ad_spend', $adsFactKeys, true)
        && (float)($rowsByType['advertising']['amount'] ?? 0.0) === 300.0
        && (int)($rowsByType['advertising']['book_order_num'] ?? 0) === 9,
    ['fact_keys' => $adsFactKeys, 'advertising_row' => $rowsByType['advertising'] ?? []]
);

$reviewRaw = json_decode((string)($rowsByType['review']['raw_data'] ?? '{}'), true) ?: [];
$reviewRawText = json_encode($reviewRaw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$check(
    'review simulation stores aggregate metrics without private review text',
    (float)($rowsByType['review']['comment_score'] ?? 0.0) === 4.6
        && (int)($rowsByType['review']['quantity'] ?? 0) === 23
        && (int)($rowsByType['review']['data_value'] ?? 0) === 2
        && !str_contains((string)$reviewRawText, 'review text should not be stored')
        && !str_contains((string)$reviewRawText, '13900139000')
        && !str_contains((string)$reviewRawText, 'Bob'),
    ['review_raw' => $reviewRaw]
);

$missingScoreRows = $harness->buildRows([
    'store_id' => '68471',
    'poi_id' => '68471',
    'poi_name' => 'Mock Meituan Hotel',
    'default_data_date' => '2026-07-07',
    'reviews' => [[
        'commentScore' => 0,
        'commentCount' => 5,
        'badReviewCount' => 0,
        '_source_path' => 'mock.reviews.missing-score',
    ]],
], 117);
$missingScoreRow = $missingScoreRows[0] ?? [];
$missingScoreRaw = json_decode((string)($missingScoreRow['raw_data'] ?? '{}'), true) ?: [];
$missingScoreSummary = $harness->summarizeHistoryMetric($missingScoreRow, (string)($missingScoreRow['raw_data'] ?? ''));
$check(
    'missing review score remains explicit in storage evidence and page summary',
    ($missingScoreRow['data_type'] ?? '') === 'review'
        && (float)($missingScoreRow['comment_score'] ?? 0.0) === 0.0
        && ($missingScoreRaw['comment_score_status'] ?? '') === 'missing'
        && ($missingScoreRaw['comment_score_present'] ?? null) === false
        && str_contains($missingScoreSummary, '评分未返回')
        && str_contains($missingScoreSummary, '点评 5'),
    ['review_raw' => $missingScoreRaw, 'summary' => $missingScoreSummary]
);

$orderRaw = json_decode((string)($rowsByType['order']['raw_data'] ?? '{}'), true) ?: [];
$orderRawText = json_encode($orderRaw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$check(
    'order simulation hashes order identity and masks guest fields',
    (int)($rowsByType['order']['quantity'] ?? 0) === 4
        && isset($orderRaw['order_id_hash'])
        && !str_contains((string)$orderRawText, 'ORDER-SHOULD-BE-HASHED')
        && !str_contains((string)$orderRawText, '13800138000')
        && !str_contains((string)$orderRawText, 'Alice'),
    ['order_raw' => $orderRaw]
);

$realtimeRows = $harness->buildRows([
    'store_id' => '68471',
    'poi_id' => '68471',
    'poi_name' => 'Mock Meituan Hotel',
    'default_data_date' => '2026-07-08',
    'data_period' => 'realtime_snapshot',
    'snapshot_time' => '2026-07-08 13:30:00',
    'traffic' => [[
        'exposure_count' => 980,
        'page_views' => 245,
        'pay_orders' => 11,
        'pay_rooms' => 14,
        'conversion_rate' => '4.49%',
    ]],
], 117);
$realtimeRow = $realtimeRows[0] ?? [];
$check(
    'simulated realtime capture preserves snapshot scope',
    ($realtimeRow['data_type'] ?? '') === 'traffic'
        && ($realtimeRow['data_period'] ?? '') === 'realtime_snapshot'
        && ($realtimeRow['snapshot_time'] ?? '') === '2026-07-08 13:30:00'
        && (int)($realtimeRow['book_order_num'] ?? 0) === 11,
    ['realtime_row' => $realtimeRow]
);

$payload = [
    'status' => $failures === [] ? 'passed' : 'failed',
    'scope' => 'meituan_ota_channel',
    'simulated_collection' => [
        'writes_database' => false,
        'touches_live_platform' => false,
        'full_counts' => $fullCounts,
        'realtime_rows' => count($realtimeRows),
    ],
    'checks' => $checks,
    'failures' => $failures,
];

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 1);
