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
    str_contains($route, "Route::post('/browser-assist-import', 'OnlineData/importBrowserAssistCapture');"),
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
    'normalizer keeps ctrip and qunar under ctrip platform with dimensions',
    count(array_filter($normalized['rows'], static fn(array $row): bool => ($row['source'] ?? '') === 'ctrip' && ($row['dimension'] ?? '') === 'realtime:qunar')) === 1,
    'dimension=realtime:qunar'
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
