<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use app\controller\DailyReport;
use app\controller\HolidayRevenue;
use app\controller\OnlineData;
use app\controller\StrategySimulation;
use app\service\MacroSignalService;
use app\service\OperationManagementService;
use app\service\TransferDecisionService;

function fail_contract(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assert_contract(bool $condition, string $message): void
{
    if (!$condition) {
        fail_contract($message);
    }
}

function assert_contract_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fail_contract($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

function assert_contract_float(float $expected, $actual, string $message): void
{
    $actualFloat = round((float)$actual, 2);
    if (abs($expected - $actualFloat) > 0.001) {
        fail_contract($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actualFloat, true));
    }
}

function call_daily_private(string $method, array $args)
{
    $ref = new ReflectionClass(DailyReport::class);
    $controller = $ref->newInstanceWithoutConstructor();
    $methodRef = $ref->getMethod($method);
    $methodRef->setAccessible(true);
    return $methodRef->invokeArgs($controller, $args);
}

function call_service_private(string $className, string $method, array $args)
{
    $ref = new ReflectionClass($className);
    $service = $ref->newInstanceWithoutConstructor();
    $methodRef = $ref->getMethod($method);
    $methodRef->setAccessible(true);
    return $methodRef->invokeArgs($service, $args);
}

$hotel = (object)['name' => '测试酒店'];
$reportData = [
    'salable_rooms' => 100,
    'xb_revenue' => 1000,
    'xb_rooms' => 5,
    'booking_revenue' => 500,
    'booking_rooms' => 2,
    'hourly_revenue' => 300,
    'hourly_rooms' => 3,
    'overnight_rooms' => 7,
];
$monthSum = [
    'xb_revenue' => 1000,
    'xb_rooms' => 5,
    'booking_revenue' => 500,
    'booking_rooms' => 2,
    'agoda_revenue' => 200,
    'agoda_rooms' => 1,
    'expedia_revenue' => 300,
    'expedia_rooms' => 1,
    'hourly_revenue' => 300,
    'hourly_rooms' => 3,
];

$detail = call_daily_private('calculateReportDetail', [
    $hotel,
    $reportData,
    [],
    $monthSum,
    '2026-05-10',
    10,
    31,
]);

assert_contract_same(100.0, $detail['total_rooms'], 'missing monthly salable_rooms_total must not fall back to 59');
assert_contract_same(10, $detail['day_total_rooms'], 'daily total rooms must include Booking and hourly rooms');
assert_contract_float(180.0, $detail['day_adr'], 'daily ADR must use revenue and room count with the same channel scope');
assert_contract_float(10.0, $detail['day_occ_rate'], 'daily OCC must use the real salable room denominator');
assert_contract_float(18.0, $detail['day_revpar'], 'daily RevPAR must use the real salable room denominator');
assert_contract_float(191.67, $detail['month_adr'], 'monthly ADR must include Booking/Agoda/Expedia/hourly room nights');
assert_contract_float(1.2, $detail['month_occ_rate'], 'monthly OCC must include all room nights in numerator');
assert_contract_float(2.3, $detail['month_revpar'], 'monthly RevPAR must use the real salable room denominator');

$missingRoomsDetail = call_daily_private('calculateReportDetail', [
    $hotel,
    ['xb_revenue' => 100, 'xb_rooms' => 1],
    [],
    [],
    '2026-05-10',
    10,
    31,
]);
assert_contract_same(0.0, $missingRoomsDetail['total_rooms'], 'unknown salable rooms must stay unknown instead of using 59');

$macroData = [
    'booking_revenue' => 500,
    'booking_rooms' => 2,
    'agoda_revenue' => 200,
    'agoda_rooms' => 1,
    'expedia_revenue' => 300,
    'expedia_rooms' => 1,
    'hourly_revenue' => 150,
    'hourly_rooms' => 1,
];
$macroRevenue = call_service_private(MacroSignalService::class, 'dailyReportRevenue', [[], $macroData]);
$macroRooms = call_service_private(MacroSignalService::class, 'dailyReportRoomNights', [$macroData]);
assert_contract_float(1150.0, $macroRevenue, 'macro signal daily revenue must include Booking/Agoda/Expedia/hourly revenue when derived from fields');
assert_contract_float(5.0, $macroRooms, 'macro signal daily room nights must include Booking/Agoda/Expedia/hourly rooms when derived from fields');

$downstreamReportData = [
    'booking_revenue' => 500,
    'booking_rooms' => 2,
    'agoda_revenue' => 200,
    'agoda_rooms' => 1,
    'expedia_revenue' => 300,
    'expedia_rooms' => 1,
    'hourly_revenue' => 150,
    'hourly_rooms' => 1,
];
$operationRevenue = call_service_private(OperationManagementService::class, 'extractRevenue', [['revenue' => 0], $downstreamReportData]);
assert_contract_float(1150.0, $operationRevenue, 'operation dashboard must derive revenue from Booking/Agoda/Expedia/hourly fields when summary fields are absent');
$operationRef = new ReflectionClass(OperationManagementService::class);
assert_contract($operationRef->hasMethod('extractRoomNights'), 'operation dashboard must centralize room-night extraction for old daily report data');
$operationRooms = call_service_private(OperationManagementService::class, 'extractRoomNights', [['guest_count' => 0], $downstreamReportData]);
assert_contract_float(5.0, $operationRooms, 'operation dashboard must derive room nights from Booking/Agoda/Expedia/hourly fields when summary fields are absent');
assert_contract($operationRef->hasMethod('extractSalableRoomCount'), 'operation dashboard must centralize salable-room extraction for old daily report data');
$operationSalableRooms = call_service_private(OperationManagementService::class, 'extractSalableRoomCount', [['room_count' => 0], ['salable_rooms' => 100]]);
assert_contract_float(100.0, $operationSalableRooms, 'operation dashboard RevPAR/OCC denominator must fallback to report_data salable rooms');

$transferRevenue = call_service_private(TransferDecisionService::class, 'extractRevenue', [['revenue' => 0], $downstreamReportData]);
assert_contract_float(1150.0, $transferRevenue, 'transfer analysis must derive revenue from Booking/Agoda/Expedia/hourly fields when summary fields are absent');
$transferRef = new ReflectionClass(TransferDecisionService::class);
assert_contract($transferRef->hasMethod('extractRoomNights'), 'transfer analysis must centralize room-night extraction for old daily report data');
$transferRooms = call_service_private(TransferDecisionService::class, 'extractRoomNights', [['guest_count' => 0], $downstreamReportData]);
assert_contract_float(5.0, $transferRooms, 'transfer analysis must derive room nights from Booking/Agoda/Expedia/hourly fields when summary fields are absent');
assert_contract($transferRef->hasMethod('extractSalableRoomCount'), 'transfer analysis must centralize salable-room extraction for old daily report data');
$transferSalableRooms = call_service_private(TransferDecisionService::class, 'extractSalableRoomCount', [['room_count' => 0], ['salable_rooms' => 100]]);
assert_contract_float(100.0, $transferSalableRooms, 'transfer analysis RevPAR/OCC denominator must fallback to report_data salable rooms');

$holidayRevenue = call_service_private(HolidayRevenue::class, 'extractReportRevenue', [['revenue' => 0, 'report_data' => json_encode($downstreamReportData, JSON_UNESCAPED_UNICODE)]]);
assert_contract_float(1150.0, $holidayRevenue, 'holiday revenue must include Booking/Agoda/Expedia/hourly revenue when derived from daily report fields');

$strategySummary = call_service_private(StrategySimulation::class, 'summarizeDailyReports', [[[
    'occupancy_rate' => 0,
    'revenue' => 0,
    'room_count' => 0,
    'report_data' => json_encode(['day_revenue' => 1150, 'day_total_rooms' => 5, 'day_occ_rate' => 50], JSON_UNESCAPED_UNICODE),
]]]);
assert_contract_float(1150.0, $strategySummary['avg_revenue'] ?? 0, 'strategy simulation must fallback from zero row revenue to report_data day revenue');
assert_contract_float(5.0, $strategySummary['avg_room_count'] ?? 0, 'strategy simulation must fallback from zero row room_count to report_data day total rooms');
assert_contract_float(50.0, $strategySummary['avg_occupancy'] ?? 0, 'strategy simulation must fallback from zero row occupancy to report_data day occupancy');

$onlineRanking = call_service_private(OnlineData::class, 'buildHotelRanking', [[
    ['system_hotel_id' => 7, 'hotel_id' => 'ota-a', 'hotel_name' => '同一酒店A', 'data_date' => '2026-05-10', 'amount' => 100, 'quantity' => 1, 'book_order_num' => 1],
    ['system_hotel_id' => 7, 'hotel_id' => 'ota-b', 'hotel_name' => '同一酒店B', 'data_date' => '2026-05-10', 'amount' => 200, 'quantity' => 2, 'book_order_num' => 2],
], 'day']);
assert_contract_same(1, count($onlineRanking), 'online data ranking must group multiple OTA hotel IDs by system_hotel_id');
assert_contract_same(7, $onlineRanking[0]['hotel_id'] ?? null, 'online data ranking must expose system hotel ID when it is available');
assert_contract_float(3.0, $onlineRanking[0]['quantity'] ?? 0, 'online data ranking must aggregate OTA rows under the same system hotel');
$onlineDataRef = new ReflectionClass(OnlineData::class);
assert_contract($onlineDataRef->hasMethod('mergeOnlineDataHotelList'), 'online data hotel filter list must merge rows by system_hotel_id');
$mergedOnlineHotels = call_service_private(OnlineData::class, 'mergeOnlineDataHotelList', [[
    ['system_hotel_id' => 7, 'hotel_id' => 'ota-a', 'hotel_name' => '同一酒店A'],
    ['system_hotel_id' => 7, 'hotel_id' => 'ota-b', 'hotel_name' => '同一酒店B'],
]]);
assert_contract_same(1, count($mergedOnlineHotels), 'online data hotel filter list must not duplicate one system hotel for multiple OTA IDs');
assert_contract_same(7, $mergedOnlineHotels[0]['id'] ?? null, 'online data hotel filter list option value must be the system hotel ID when available');

$dailySource = file_get_contents(__DIR__ . '/../app/controller/DailyReport.php');
$monthlySource = file_get_contents(__DIR__ . '/../app/controller/MonthlyTask.php');
$authSource = file_get_contents(__DIR__ . '/../app/controller/Auth.php');
$operationSource = file_get_contents(__DIR__ . '/../app/service/OperationManagementService.php');
$onlineDataSource = file_get_contents(__DIR__ . '/../app/controller/OnlineData.php');
$publicSource = file_get_contents(__DIR__ . '/../public/index.html');

assert_contract(!str_contains($dailySource, 'array_merge($existingData, $reportData)'), 'daily report update must replace submitted JSON fields so cleared values do not survive');
assert_contract(!str_contains($monthlySource, 'array_merge($existingData, $taskData)'), 'monthly task update must replace submitted JSON fields so cleared values do not survive');
assert_contract(str_contains($dailySource, 'month_task_key'), 'batch export must bind month task data per hotel/month report row');
assert_contract(str_contains($authSource, "'can_view_online_data'"), 'auth user payload must include can_view_online_data');
assert_contract(str_contains($operationSource, 'buildDailyFinancialKeys'), 'operation summary must not double count online financials when daily financials exist');
assert_contract(str_contains($onlineDataSource, 'applyOnlineDailyDataHotelFilter'), 'online data list must filter selected system hotels by system_hotel_id');
assert_contract(!str_contains($onlineDataSource, "where('system_hotel_id', intval(\$hotelId))->whereOr('hotel_id', \$hotelId)"), 'online history system hotel filter must not OR platform hotel_id because IDs can collide');
assert_contract(!str_contains($onlineDataSource, "where('system_hotel_id', (int)\$hotelId)->whereOr('hotel_id', \$hotelId)"), 'Ctrip latest system hotel filter must not OR platform hotel_id because IDs can collide');
assert_contract(str_contains($publicSource, 'dedupeHotels'), 'hotel dropdown options must be de-duplicated after loading');
assert_contract(str_contains($publicSource, 'seenIds') && str_contains($publicSource, 'seenFallbackNames'), 'hotel dropdown de-duplication must filter repeated hotel ids and only name-only fallback rows');
assert_contract(!str_contains($publicSource, '(name && seenNames.has(name))'), 'hotel de-duplication must not collapse same-name hotels with different ids');
assert_contract(str_contains($publicSource, 'hotelDeleteIdentityText'), 'hotel delete confirmation must use a distinct hotel identity');
assert_contract(str_contains($publicSource, '编码：') && str_contains($publicSource, 'ID：'), 'hotel delete confirmation must include code and id for same-name hotels');
assert_contract(str_contains($publicSource, 'showHotelDeleteModal'), 'hotel delete confirmation must use the in-app modal');
assert_contract(str_contains($publicSource, 'hotelDeleteReferences'), 'hotel delete failure must expose related data references');
assert_contract(!str_contains($publicSource, '确定要删除酒店"${hotelIdentity}"'), 'hotel delete flow must not use native confirm text only');
assert_contract(!str_contains($publicSource, "if (num === null || num === undefined) return '0';"), 'formatNumber must not display missing values as zero');

echo 'Display metric contract verification passed.' . PHP_EOL;
