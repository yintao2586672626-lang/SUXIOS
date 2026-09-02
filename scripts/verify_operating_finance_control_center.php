<?php
declare(strict_types=1);

use app\service\BookingDemandPlanningService;
use app\service\MonthlyOperatingFinanceService;
use app\service\OperatingBlockerRecoveryService;
use app\service\OtaSettlementReconciliationService;
use think\facade\Db;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$database = trim((string)getenv('DB_NAME'));
$e2eDatabase = trim((string)getenv('SUXI_E2E_DB_NAME'));
if ($database === ''
    || $database !== $e2eDatabase
    || preg_match('/_e2e$/D', $database) !== 1
    || getenv('SUXI_E2E_DB_OVERRIDE') !== '1'
) {
    throw new RuntimeException('Refusing operating-finance verification outside a dedicated E2E database.');
}

$app = new think\App();
$app->initialize();

$tenantId = 1;
$hotelId = 1;
$portfolioHotelId = 980001;
if (!Db::name('hotels')->where('id', $portfolioHotelId)->find()) {
    Db::name('hotels')->insert([
        'id' => $portfolioHotelId,
        'tenant_id' => $tenantId,
        'name' => 'Operating Finance E2E Hotel',
        'city' => 'E2E',
        'status' => 1,
        'owner_user_id' => 0,
        'ota_channel_strategy' => 'none',
        'created_by' => 0,
    ]);
}
$permittedHotelIds = [$hotelId, $portfolioHotelId];

$booking = new BookingDemandPlanningService();
$baseSnapshot = [
    'platform' => 'ctrip',
    'fact_scope' => 'ota_channel',
    'stay_date' => '2026-08-31',
    'source_method' => 'synthetic_test_fixture',
    'source_ref' => 'fixture:operating-finance:on-books',
    'quality_status' => 'manual_confirmed',
    'readback_verified' => true,
];
$first = $booking->saveOnBooksSnapshot($tenantId, $permittedHotelIds, $hotelId, $baseSnapshot + [
    'captured_at' => '2026-08-30 08:00:00',
    'on_books_room_nights' => 8,
    'on_books_room_revenue' => 800,
    'cumulative_cancel_room_nights' => 1,
    'gross_booking_room_nights' => 10,
    'idempotency_key' => 'e2e-on-books-0800',
], 1);
$second = $booking->saveOnBooksSnapshot($tenantId, $permittedHotelIds, $hotelId, $baseSnapshot + [
    'captured_at' => '2026-08-30 10:00:00',
    'on_books_room_nights' => 10,
    'on_books_room_revenue' => 1060,
    'cumulative_cancel_room_nights' => 2,
    'gross_booking_room_nights' => 13,
    'idempotency_key' => 'e2e-on-books-1000',
], 1);
$bookingOverview = $booking->bookingOverview($tenantId, $permittedHotelIds, $hotelId, 'ctrip', '2026-08-31');
if (($bookingOverview['status'] ?? '') !== 'ready'
    || (float)($bookingOverview['net_pickup_room_nights'] ?? -1) !== 2.0
    || (float)($bookingOverview['gross_pickup_room_nights'] ?? -1) !== 3.0
) {
    throw new RuntimeException('On-books exact readback or pickup calculation failed.');
}

$event = $booking->saveDemandEvent($tenantId, $permittedHotelIds, $hotelId, [
    'event_name' => 'E2E Exhibition',
    'event_type' => 'exhibition',
    'event_start_date' => '2026-08-31',
    'event_end_date' => '2026-09-02',
    'area_label' => 'E2E area',
    'source_method' => 'synthetic_test_fixture',
    'source_ref' => 'fixture:operating-finance:event',
    'source_status' => 'reference_only',
    'observed_at' => '2026-08-30 10:00:00',
    'idempotency_key' => 'e2e-demand-event',
], 1);
$calendar = $booking->demandCalendar($tenantId, $permittedHotelIds, $hotelId, '2026-08-31', '2026-09-06');
if (($event['reference_only'] ?? false) !== true
    || ($calendar['causality_claimed'] ?? true) !== false
    || ($calendar['automatic_pricing'] ?? true) !== false
) {
    throw new RuntimeException('Demand event reference-only boundary failed.');
}
$demandPlan = $booking->demandPlan($tenantId, $permittedHotelIds, $hotelId, 'ctrip', '2026-08-30');
if (($demandPlan['requested_horizons'] ?? []) !== [1, 3, 7]
    || ($demandPlan['windows'][0]['window_key'] ?? '') !== 'tomorrow'
    || ($demandPlan['windows'][0]['status'] ?? '') !== 'ready'
    || ($demandPlan['windows'][1]['on_books_room_nights_total'] ?? null) !== null
    || ($demandPlan['automatic_pricing'] ?? true) !== false
    || ($demandPlan['automatic_inventory_write'] ?? true) !== false
) {
    throw new RuntimeException('Tomorrow/3-day/7-day demand plan contract failed.');
}

$finance = new MonthlyOperatingFinanceService();
$financeInputs = [
    'room_operating_revenue' => 10000,
    'non_room_operating_revenue' => 2000,
    'departmental_expense' => 3000,
    'undistributed_operating_expense' => 2000,
    'rent_expense' => 1000,
    'other_fixed_cash_cost' => 500,
    'budget_total_operating_revenue' => 11000,
    'budget_gop' => 6500,
];
$financeOne = $finance->saveSnapshot(
    $tenantId,
    $permittedHotelIds,
    $hotelId,
    '2026-08',
    'whole_hotel',
    $financeInputs,
    ['fixture:pms:hotel1', 'fixture:cost:hotel1'],
    ['source_method' => 'manual_entry', 'source_quality_status' => 'operator_attested', 'currency' => 'CNY', 'tax_basis' => 'tax_inclusive'],
    'e2e-finance-hotel1',
    1
);
$financeTwoInputs = $financeInputs;
$financeTwoInputs['departmental_expense'] = 4500;
$financeTwo = $finance->saveSnapshot(
    $tenantId,
    $permittedHotelIds,
    $portfolioHotelId,
    '2026-08',
    'whole_hotel',
    $financeTwoInputs,
    ['fixture:pms:hotel2', 'fixture:cost:hotel2'],
    ['source_method' => 'manual_entry', 'source_quality_status' => 'operator_attested', 'currency' => 'CNY', 'tax_basis' => 'tax_inclusive'],
    'e2e-finance-hotel2',
    1
);
$portfolio = $finance->portfolioOverview($tenantId, $permittedHotelIds, '2026-08');
if (($financeOne['readback_verified'] ?? false) !== true
    || ($financeTwo['readback_verified'] ?? false) !== true
    || ($portfolio['ranking_status'] ?? '') !== 'same_scope_manual_snapshot_comparable'
    || ($portfolio['employee_evaluation_authorized'] ?? true) !== false
) {
    throw new RuntimeException('Monthly finance or same-scope portfolio verification failed.');
}

$settlement = (new OtaSettlementReconciliationService())->importAndReadback([
    'tenant_id' => $tenantId,
    'hotel_id' => $hotelId,
    'platform' => 'ctrip',
    'period_start' => '2026-08-01',
    'period_end' => '2026-08-31',
    'file_sha256' => str_repeat('a', 64),
    'source_evidence_sha256' => str_repeat('b', 64),
    'source_method' => 'synthetic_test_fixture',
    'source_quality_status' => 'synthetic_test_only',
    'parser_version' => 'operating-finance-e2e.v1',
], [[
    'source_line_no' => 1,
    'business_date' => '2026-08-10',
    'amount_scope' => 'settlement',
    'ota_order_ref' => 'E2E-OTA-ORDER-1',
    'pms_stay_ref' => 'E2E-PMS-STAY-1',
    'gross_amount' => 1000,
    'gross_amount_basis' => 'source_direct',
    'commission_amount' => 150,
    'commission_amount_basis' => 'source_direct',
    'subsidy_amount_basis' => 'not_applicable',
    'refund_amount_basis' => 'not_applicable',
    'settlement_amount' => 850,
    'settlement_amount_basis' => 'source_direct',
    'net_revenue_derivation' => 'gross_minus_commission',
    'match_status' => 'matched',
    'ota_comparison_amount' => 850,
    'pms_comparison_amount' => 850,
    'comparison_basis' => 'net_revenue',
]], 1);
if (($settlement['readback_verified'] ?? false) !== true
    || (float)($settlement['totals']['net_revenue']['value'] ?? -1) !== 850.0
    || ($settlement['authorization']['external_write_authorized'] ?? true) !== false
) {
    throw new RuntimeException('Settlement exact readback or authorization boundary failed.');
}

$recovery = (new OperatingBlockerRecoveryService())->build([
    'tenant_id' => $tenantId,
    'hotel_id' => $hotelId,
    'business_date' => '2026-08-30',
], [
    ['source' => 'database', 'status' => 'ready', 'reason_code' => 'ready', 'tenant_id' => $tenantId, 'hotel_id' => $hotelId, 'business_date' => '2026-08-30'],
    ['source' => 'pms', 'status' => 'session_expired', 'reason_code' => 'capture_session_expired', 'tenant_id' => $tenantId, 'hotel_id' => $hotelId, 'business_date' => '2026-08-30', 'business_impact' => 'critical', 'evidence_quality' => 'verified'],
    ['source' => 'wecom', 'status' => 'binding_missing', 'reason_code' => 'robot_binding_missing', 'tenant_id' => $tenantId, 'hotel_id' => $hotelId, 'business_date' => '2026-08-30', 'business_impact' => 'medium'],
]);
if (($recovery['selected_count'] ?? 0) !== 1
    || ($recovery['selected']['source'] ?? '') !== 'pms'
    || ($recovery['safety']['writes_executed'] ?? true) !== false
) {
    throw new RuntimeException('Unique blocker recovery selection failed.');
}

$request = new class extends think\Request {
    public function isCli(): bool
    {
        return false;
    }
};
$request->setMethod('GET')
    ->setUrl('/api/operating-finance/overview')
    ->setBaseUrl('/api/operating-finance/overview')
    ->setPathinfo('api/operating-finance/overview')
    ->withGet([
        'hotel_id' => $hotelId,
        'business_date' => '2026-08-30',
        'period_month' => '2026-08',
        'stay_date' => '2026-08-31',
        'platform' => 'ctrip',
    ])
    ->withHeader(['Accept' => 'application/json']);
$request->user = app\model\User::find(2);
$app->instance('request', $request);
$controllerResponse = (new app\controller\OperatingFinance($app))->overview();
$controllerPayload = json_decode((string)$controllerResponse->getContent(), true, 512, JSON_THROW_ON_ERROR);
$controllerData = is_array($controllerPayload['data'] ?? null) ? $controllerPayload['data'] : [];
if ($controllerResponse->getCode() !== 200
    || (int)($controllerPayload['code'] ?? 0) !== 200
    || ($controllerData['contract_version'] ?? '') !== 'operating_finance_control_center.v1'
    || (int)($controllerData['hotel_id'] ?? 0) !== $hotelId
    || ($controllerData['settlement']['readback_verified'] ?? false) !== true
    || ($controllerData['booking_pace']['status'] ?? '') !== 'ready'
    || ($controllerData['booking_demand_plan']['requested_horizons'] ?? []) !== [1, 3, 7]
    || ($controllerData['monthly_finance']['readback_verified'] ?? false) !== true
    || (int)($controllerData['boundaries']['external_write_count'] ?? -1) !== 0
) {
    throw new RuntimeException('Operating-finance controller exact overview failed.');
}

$triggerNames = array_map(
    static fn(array $row): string => (string)$row['TRIGGER_NAME'],
    Db::query(
        'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS '
        . 'WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME IN ('
        . "'trg_ota_settlement_batch_no_update','trg_ota_settlement_line_no_update',"
        . "'trg_wecom_task_receipt_no_update','trg_on_books_snapshot_no_update',"
        . "'trg_demand_event_no_update','trg_monthly_finance_no_update')"
    )
);
if (count(array_unique($triggerNames)) !== 6) {
    throw new RuntimeException('Append-only trigger verification failed.');
}

$ordinaryMutationBlocked = false;
try {
    Db::name('ota_settlement_import_batches')
        ->where('id', (int)$settlement['batch_id'])
        ->update(['imported_by' => 2]);
} catch (Throwable) {
    $ordinaryMutationBlocked = true;
}
if (!$ordinaryMutationBlocked) {
    throw new RuntimeException('Append-only batch accepted an ordinary mutation.');
}
try {
    Db::execute('SET @suxi_cloud_hotel_id_migration = 1');
    Db::name('ota_settlement_import_batches')
        ->where('id', (int)$settlement['batch_id'])
        ->update(['hotel_id' => $portfolioHotelId]);
} finally {
    Db::execute('SET @suxi_cloud_hotel_id_migration = 0');
}
$migratedSettlement = (new OtaSettlementReconciliationService())->latestForScope(
    $tenantId,
    $portfolioHotelId,
    'ctrip',
    '2026-08-01',
    '2026-08-31'
);
if ((int)($migratedSettlement['scope']['hotel_id'] ?? 0) !== $portfolioHotelId
    || (int)($migratedSettlement['scope']['source_hotel_id'] ?? 0) !== $hotelId
    || ($migratedSettlement['batch_fingerprint'] ?? '') !== ($settlement['batch_fingerprint'] ?? '')
) {
    throw new RuntimeException('Controlled canonical hotel-id migration broke settlement source identity.');
}
try {
    Db::execute('SET @suxi_cloud_hotel_id_migration = 1');
    Db::name('ota_settlement_import_batches')
        ->where('id', (int)$settlement['batch_id'])
        ->update(['hotel_id' => $hotelId]);
} finally {
    Db::execute('SET @suxi_cloud_hotel_id_migration = 0');
}

echo json_encode([
    'status' => 'passed',
    'database' => $database,
    'migration_count' => (int)Db::name('schema_versions')->count(),
    'booking_snapshot_ids' => [$first['id'], $second['id']],
    'net_pickup_room_nights' => $bookingOverview['net_pickup_room_nights'],
    'gross_pickup_room_nights' => $bookingOverview['gross_pickup_room_nights'],
    'demand_event_id' => $event['id'],
    'monthly_snapshot_ids' => [$financeOne['id'], $financeTwo['id']],
    'portfolio_ranking_status' => $portfolio['ranking_status'],
    'settlement_batch_id' => $settlement['batch_id'],
    'settlement_net_revenue' => $settlement['totals']['net_revenue']['value'],
    'selected_blocker_source' => $recovery['selected']['source'],
    'controller_overview_status' => 'passed',
    'append_only_triggers_verified' => count(array_unique($triggerNames)),
    'controlled_hotel_id_migration_verified' => true,
    'external_write_count' => 0,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
