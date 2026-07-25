<?php
declare(strict_types=1);

use app\service\CtripMetricFactProjectionService;
use think\App;
use think\facade\Db;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$hotelId = 0;
$date = '';
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--hotel-id=(\d+)$/', $argument, $match) === 1) $hotelId = (int)$match[1];
    if (preg_match('/^--date=(\d{4}-\d{2}-\d{2})$/', $argument, $match) === 1) $date = $match[1];
}
if ($hotelId <= 0 || $date === '') {
    fwrite(STDERR, "Usage: php scripts/project_ctrip_metric_facts.php --hotel-id=<id> --date=YYYY-MM-DD\n");
    exit(2);
}

(new App($root))->initialize();
$rows = Db::name('online_daily_data')
    ->where('system_hotel_id', $hotelId)
    ->where('source', 'ctrip')
    ->where('data_date', $date)
    ->where('data_period', 'historical_daily')
    ->select()->toArray();
$result = (new CtripMetricFactProjectionService())->project($rows);
echo json_encode([
    'hotel_id' => $hotelId,
    'date' => $date,
    'daily_rows' => count($rows),
    'projection' => $result,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
