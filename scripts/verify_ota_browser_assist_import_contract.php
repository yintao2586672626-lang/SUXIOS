<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use app\service\OtaBrowserAssistImportService;

$root = dirname(__DIR__);
$checks = [];

$check = static function (string $name, bool $ok, string $detail = '') use (&$checks): void {
    $checks[] = [
        'name' => $name,
        'ok' => $ok,
        'detail' => $detail,
    ];
};

$route = file_get_contents($root . '/route/app.php') ?: '';
$controller = file_get_contents($root . '/app/controller/concern/PlatformDataSourceConcern.php') ?: '';
$serviceSource = file_get_contents($root . '/app/service/OtaBrowserAssistImportService.php') ?: '';

$check(
    'browser assist import route exists',
    str_contains($route, "Route::post('/browser-assist-import', 'ota.SyncController/importBrowserAssistCapture');"),
    '/api/online-data/browser-assist-import'
);
$check(
    'controller exposes browser assist import method',
    str_contains($controller, 'function importBrowserAssistCapture'),
    'PlatformDataSourceConcern::importBrowserAssistCapture'
);
$check(
    'controller parses uploaded capture json without logging raw payload',
    str_contains($controller, 'browserAssistCaptureRequestData') && str_contains($controller, "\$payload['capture'] = \$decoded;"),
    'browserAssistCaptureRequestData'
);
$check(
    'service routes through existing importRows',
    str_contains($serviceSource, 'importRows($user, $package)'),
    'PlatformDataSyncService::importRows'
);

$service = new OtaBrowserAssistImportService();
$normalized = $service->normalizeCapturePackages([
    'system_hotel_id' => 58,
    'capture' => [
        'ctrip' => [
            'url' => 'https://ebooking.ctrip.com/ebkovsroom/inventory/calendar?token=secret',
            'rooms' => [
                [
                    'name' => '大床房',
                    'days' => [
                        [
                            'date' => '2026-06-27',
                            'state' => '开房',
                            'remain' => '剩余3',
                            'sold' => 2,
                        ],
                    ],
                ],
            ],
        ],
        'ctripStats' => [
            'url' => 'https://ebooking.ctrip.com/datacenter/inland/businessreport/flowdata?token=secret',
            'updatedAt' => '2026-06-27 10:20:00',
            'metrics' => [
                'ctrip' => [
                    'realtimeVisitors' => ['label' => '实时访客', 'value' => '128'],
                    'orderConversionRate' => ['label' => '订单转化率', 'value' => '4.5%'],
                    'realtimeRank' => ['label' => '实时排名', 'value' => '12'],
                ],
                'qunar' => [
                    'realtimeVisitors' => ['label' => '实时访客', 'value' => '56'],
                    'orderConversionRate' => ['label' => '订单转化率', 'value' => '3.2%'],
                ],
                'tongcheng' => [
                    'realtimeVisitors' => ['label' => '实时访客', 'value' => '17'],
                    'orderConversionRate' => ['label' => '订单转化率', 'value' => '2.1%'],
                ],
                'zhixing' => [
                    'realtimeRank' => ['label' => '实时排名', 'value' => '9'],
                ],
            ],
        ],
        'meituanStats' => [
            'url' => 'https://eb.meituan.com/newhb-sub-app/data-center-pc/home/index.html?token=secret',
            'updatedAt' => '2026-06-27 10:25:00',
            'metrics' => [
                'exposureUsers' => ['label' => '曝光人数', 'value' => '1000'],
                'browseUsers' => ['label' => '浏览人数', 'value' => '150'],
                'paidOrders' => ['label' => '支付订单数', 'value' => '8'],
                'browsePayRate' => ['label' => '浏览支付率', 'value' => '5.3%'],
            ],
        ],
    ],
]);

$packageKeys = array_map(
    static fn(array $package): string => $package['platform'] . ':' . $package['data_type'],
    $normalized['packages']
);
sort($packageKeys);

$check(
    'normalizer splits packages by platform and data_type',
    $packageKeys === ['ctrip:inventory', 'ctrip:peer_rank', 'ctrip:traffic', 'meituan:traffic'],
    implode(',', $packageKeys)
);
$check(
    'normalizer keeps ctrip family channels under ctrip platform with dimensions',
    count(array_filter($normalized['rows'], static fn(array $row): bool => ($row['source'] ?? '') === 'ctrip' && ($row['dimension'] ?? '') === 'realtime:qunar')) === 1,
    'dimension=realtime:qunar'
);
$check(
    'normalizer preserves tongcheng and zhixing as Ctrip-family dimensions',
    count(array_filter($normalized['rows'], static fn(array $row): bool => ($row['source'] ?? '') === 'ctrip' && ($row['dimension'] ?? '') === 'realtime:tongcheng')) === 1
        && count(array_filter($normalized['rows'], static fn(array $row): bool => ($row['source'] ?? '') === 'ctrip' && ($row['dimension'] ?? '') === 'realtime:zhixing:rank')) === 1,
    'dimension=realtime:tongcheng,realtime:zhixing:rank'
);
$check(
    'normalizer does not leak raw source urls',
    !str_contains(json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '', 'https://'),
    'source_url_hash only'
);
$check(
    'inventory missing states remain explicit',
    count(array_filter($normalized['rows'], static fn(array $row): bool => ($row['data_type'] ?? '') === 'inventory' && ($row['raw_data']['field_facts'][0]['status'] ?? '') === 'captured')) === 1,
    'raw_data.field_facts'
);

$hookNormalized = $service->normalizeCapturePackages([
    'system_hotel_id' => 58,
    'capture' => [
        'P_RZ_0' => [
            'rankType' => 'P_RZ',
            'rankTypeName' => '入住榜',
            'dateRange' => '0',
            'dateRangeName' => '今日实时',
            'source' => 'peer',
            'capturedAt' => '2026-06-29T08:10:00.000Z',
            'data' => [
                'peerRankData' => [
                    [
                        'dimName' => '入住间夜',
                        'roundRanks' => [
                            ['poiId' => 'peer-1', 'poiName' => '同行酒店A', 'rank' => 2, 'percent' => '35.5', 'dataValue' => 18],
                        ],
                    ],
                ],
            ],
        ],
        'FLOW_CONV_0' => [
            'rankType' => 'FLOW_CONV',
            'dateRange' => '0',
            'source' => 'flow',
            'capturedAt' => '2026-06-29T08:11:00.000Z',
            'data' => [
                'exposeCount' => 1000,
                'visitCount' => 200,
                'orderCount' => 20,
                'exposeVisitRate' => 20,
                'visitOrderRate' => 10,
            ],
        ],
        'FLOW_SRC_0' => [
            'rankType' => 'FLOW_SRC',
            'dateRange' => '0',
            'source' => 'flow',
            'capturedAt' => '2026-06-29T08:12:00.000Z',
            'data' => [
                'list' => [
                    ['name' => '非广告曝光', 'value' => 800, 'percent' => 80],
                ],
            ],
        ],
        'FORECAST_2' => [
            'rankType' => 'FORECAST',
            'forecastType' => '2',
            'source' => 'forecast',
            'capturedAt' => '2026-06-29T08:13:00.000Z',
            'data' => [
                'detail' => [
                    ['dateTime' => '20260701', 'current' => 88, 'peerAvg' => 120],
                ],
            ],
        ],
        'KEYWORDS' => [
            'rankType' => 'KEYWORDS',
            'source' => 'keywords',
            'capturedAt' => '2026-06-29T08:14:00.000Z',
            'data' => [
                'cards' => [
                    [
                        'title' => '热门搜索',
                        'itemList' => [
                            ['name' => '机场酒店', 'value' => 320],
                        ],
                    ],
                ],
            ],
        ],
    ],
]);

$hookPackageKeys = array_map(
    static fn(array $package): string => $package['platform'] . ':' . $package['data_type'],
    $hookNormalized['packages']
);
sort($hookPackageKeys);

$check(
    'normalizer accepts Meituan hook payload keys',
    $hookPackageKeys === ['meituan:peer_rank', 'meituan:search_keyword', 'meituan:traffic_analysis', 'meituan:traffic_forecast'],
    implode(',', $hookPackageKeys)
);
$check(
    'Meituan hook peer rank keeps OTA-channel dimension and percent',
    count(array_filter($hookNormalized['rows'], static fn(array $row): bool => ($row['data_type'] ?? '') === 'peer_rank'
        && ($row['dimension'] ?? '') === 'peer_rank:P_RZ:入住间夜'
        && (float)($row['rank_percent'] ?? 0) === 35.5)) === 1,
    'peer_rank:P_RZ:入住间夜'
);
$check(
    'Meituan hook flow conversion maps to traffic_analysis',
    count(array_filter($hookNormalized['rows'], static fn(array $row): bool => ($row['data_type'] ?? '') === 'traffic_analysis'
        && ($row['analysis_type'] ?? '') === 'conversion_funnel'
        && (int)($row['order_submit_num'] ?? 0) === 20)) === 1,
    'traffic_analysis:conversion_funnel'
);
$check(
    'Meituan hook forecast remains signal-only',
    count(array_filter($hookNormalized['rows'], static fn(array $row): bool => ($row['data_type'] ?? '') === 'traffic_forecast'
        && ($row['raw_data']['quality_status'] ?? '') === 'signal_only')) === 1,
    'traffic_forecast signal_only'
);

$failed = array_values(array_filter($checks, static fn(array $check): bool => !$check['ok']));
foreach ($checks as $item) {
    echo ($item['ok'] ? '[OK] ' : '[FAIL] ') . $item['name'];
    if ($item['detail'] !== '') {
        echo ' - ' . $item['detail'];
    }
    echo PHP_EOL;
}

if ($failed !== []) {
    exit(1);
}
