<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use app\controller\OnlineData;
use app\service\TransferDecisionService;

function fail(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fail($message);
    }
}

function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fail($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

function set_private_property(object $object, string $property, $value): void
{
    $ref = new ReflectionObject($object);
    while (!$ref->hasProperty($property) && $ref->getParentClass()) {
        $ref = $ref->getParentClass();
    }
    $prop = $ref->getProperty($property);
    $prop->setAccessible(true);
    $prop->setValue($object, $value);
}

function call_private(object $object, string $method, array $args = [])
{
    $ref = new ReflectionObject($object);
    while (!$ref->hasMethod($method) && $ref->getParentClass()) {
        $ref = $ref->getParentClass();
    }
    $methodRef = $ref->getMethod($method);
    $methodRef->setAccessible(true);
    return $methodRef->invokeArgs($object, $args);
}

function extract_method_source(string $source, string $methodName): string
{
    $marker = 'function ' . $methodName . '(';
    $offset = strpos($source, $marker);
    if ($offset === false) {
        return '';
    }
    $start = strpos($source, '{', $offset);
    if ($start === false) {
        return '';
    }
    $depth = 0;
    $length = strlen($source);
    for ($i = $start; $i < $length; $i++) {
        if ($source[$i] === '{') {
            $depth++;
        } elseif ($source[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $start, $i - $start + 1);
            }
        }
    }
    return '';
}

$onlineRef = new ReflectionClass(OnlineData::class);
$online = $onlineRef->newInstanceWithoutConstructor();
$hotelUser = new class {
    public int $id = 6;
    public int $hotel_id = 7;
    public function isSuperAdmin(): bool { return false; }
    public function hasPermission(string $permission): bool { return $permission === 'can_fetch_online_data'; }
    public function getPermittedHotelIds(): array { return [7]; }
};
set_private_property($online, 'currentUser', $hotelUser);
try {
    call_private($online, 'resolveOnlineDataSystemHotelId', [99]);
    fail('non-super admin must reject unpermitted system_hotel_id');
} catch (\think\exception\HttpException $e) {
    assert_same(403, $e->getStatusCode(), 'unpermitted system_hotel_id must return 403');
}
assert_same(7, call_private($online, 'resolveOnlineDataSystemHotelId', [null]), 'non-super admin must fall back to own hotel');

$superOnline = $onlineRef->newInstanceWithoutConstructor();
$superUser = new class {
    public int $id = 1;
    public ?int $hotel_id = null;
    public function isSuperAdmin(): bool { return true; }
    public function hasPermission(string $permission): bool { return true; }
    public function getPermittedHotelIds(): array { return [7, 99]; }
};
set_private_property($superOnline, 'currentUser', $superUser);
assert_same(99, call_private($superOnline, 'resolveOnlineDataSystemHotelId', [99]), 'super admin may select a target hotel');

$transfer = new TransferDecisionService();
$transferMetrics = call_private($transfer, 'aggregateTransferMetrics', [[
    [
        'hotel_id' => 7,
        'report_date' => '2026-05-01',
        'revenue' => 1000,
        'guest_count' => 10,
        'room_count' => 20,
        'occupancy_rate' => 50,
        'report_data' => '{}',
    ],
], [
    [
        'system_hotel_id' => 7,
        'data_date' => '2026-05-01',
        'amount' => 600,
        'quantity' => 6,
        'book_order_num' => 3,
        'raw_data' => '{"visitors":30}',
    ],
]]);
assert_same(1000.0, $transferMetrics['revenue'], 'daily report financials must not double count same-day OTA revenue');
assert_same(10.0, $transferMetrics['room_nights'], 'daily report room nights must not double count same-day OTA room nights');
assert_same(3, $transferMetrics['orders'], 'OTA orders should still enrich transfer metrics');

$multiHotelMetrics = call_private($transfer, 'aggregateTransferMetrics', [[
    [
        'hotel_id' => 7,
        'report_date' => '2026-05-01',
        'revenue' => 1000,
        'guest_count' => 10,
        'room_count' => 20,
        'occupancy_rate' => 50,
        'report_data' => '{}',
    ],
], [
    [
        'system_hotel_id' => 8,
        'data_date' => '2026-05-01',
        'amount' => 600,
        'quantity' => 6,
        'book_order_num' => 3,
        'raw_data' => '{"visitors":30}',
    ],
]]);
assert_same(1600.0, $multiHotelMetrics['revenue'], 'daily report financial keys must stay hotel-scoped');
assert_same(16.0, $multiHotelMetrics['room_nights'], 'daily report room-night keys must stay hotel-scoped');

$onlineSource = file_get_contents(__DIR__ . '/../app/controller/OnlineData.php');
$authSource = file_get_contents(__DIR__ . '/../app/middleware/Auth.php');
$dailyReportSource = file_get_contents(__DIR__ . '/../app/controller/DailyReport.php');
$platformSyncSource = file_get_contents(__DIR__ . '/../app/service/PlatformDataSyncService.php');
$competitorSource = file_get_contents(__DIR__ . '/../app/controller/CompetitorApi.php');
$aiConfigSource = file_get_contents(__DIR__ . '/../app/controller/AiConfig.php');
$userSource = file_get_contents(__DIR__ . '/../app/controller/User.php');
$operationSource = file_get_contents(__DIR__ . '/../app/service/OperationManagementService.php');
$transferSource = file_get_contents(__DIR__ . '/../app/service/TransferDecisionService.php');
$loginLogSource = file_get_contents(__DIR__ . '/../app/model/LoginLog.php');
$tenantMigrationSource = file_get_contents(__DIR__ . '/../database/migrations/20260529_add_tenant_security_fields.sql');
$initFullSource = file_get_contents(__DIR__ . '/../database/init_full.sql');
$commandSource = file_get_contents(__DIR__ . '/../app/command/AutoFetchOnlineData.php');
$legacyCronSource = file_get_contents(__DIR__ . '/auto_fetch_online_data.php');
$competitorTaskSource = extract_method_source($competitorSource, 'task');
$competitorReportSource = extract_method_source($competitorSource, 'report');

assert_true((bool)preg_match('/function\s+fetchCtrip\s*\([^)]*\)\s*:\s*Response\s*\{\s*\$this->checkPermission\(\);/s', $onlineSource), 'fetchCtrip must check login and hotel binding before reading cookies');
assert_true((bool)preg_match('/function\s+saveCtripConfig\s*\([^)]*\)\s*:\s*Response\s*\{\s*\$this->checkPermission\(\);/s', $onlineSource), 'saveCtripConfig must check login and hotel binding');
assert_true(str_contains($onlineSource, "checkActionPermission('can_fetch_online_data')"), 'online data write/fetch endpoints must enforce can_fetch_online_data');
assert_true(str_contains($onlineSource, "checkActionPermission('can_delete_online_data')"), 'online data delete endpoints must enforce can_delete_online_data');
assert_true(str_contains($authSource, 'enforceRateLimit'), 'authenticated APIs must enforce request rate limits');
assert_true(str_contains($authSource, 'rate_limited'), 'rate-limited requests must be written to operation logs');
assert_true(str_contains($competitorSource, 'enforceExternalRateLimit'), 'public competitor token APIs must enforce route-local rate limits');
assert_true(str_contains($competitorTaskSource, "enforceExternalRateLimit('task'"), 'competitor task endpoint must rate limit external devices');
assert_true(str_contains($competitorReportSource, "enforceExternalRateLimit('report'"), 'competitor report endpoint must rate limit external devices');
assert_true(str_contains($competitorSource, 'external_rate_limited'), 'rate-limited competitor token requests must be audited');
assert_true(str_contains($competitorTaskSource, "OperationLog::record('competitor', 'task'"), 'competitor task endpoint must write operation audit logs');
assert_true(str_contains($competitorReportSource, "OperationLog::record('competitor', 'report'"), 'competitor report endpoint must write operation audit logs');
assert_true(str_contains($dailyReportSource, 'EXPORT_BATCH_LIMIT'), 'daily report exports must have a batch download limit');
assert_true(str_contains($dailyReportSource, 'SUXIOS Export Watermark'), 'daily report exports must include a user watermark');
assert_true(!preg_match('/\beval\s*\(/', $dailyReportSource), 'daily report formulas must not use eval');
assert_true(!preg_match('/\bshell_exec\s*\(/', $dailyReportSource), 'daily report Excel parsing must not use shell_exec');
assert_true(str_contains($onlineSource, 'tenantIdForSystemHotel'), 'online daily data writes must populate tenant_id when available');
assert_true(str_contains($platformSyncSource, "'tenant_id'"), 'platform sync writes must populate tenant_id when available');
assert_true(str_contains($loginLogSource, 'tenantIdForUser'), 'login logs must populate tenant_id for authenticated users when available');
assert_true(str_contains($operationSource, 'withTenantId'), 'operation management writes must populate tenant_id when available');
assert_true(str_contains($transferSource, "'tenant_id' => \$hotelId"), 'transfer records must populate tenant_id on write');
assert_true(str_contains($initFullSource, '20260529_add_tenant_security_fields.sql'), 'full database initialization must apply tenant security migration');
$tenantScopedTables = [
    'hotels', 'users', 'user_hotel_permissions', 'daily_reports', 'monthly_tasks', 'online_daily_data',
    'operation_logs', 'platform_data_sources', 'platform_data_sync_tasks', 'platform_data_raw_records',
    'platform_data_sync_logs', 'agent_configs', 'agent_logs', 'agent_tasks', 'knowledge_categories',
    'knowledge_base', 'room_types', 'price_suggestions', 'devices', 'energy_consumption',
    'demand_forecasts', 'competitor_analysis', 'competitor_hotel', 'agent_work_orders', 'agent_conversations',
    'energy_benchmarks', 'energy_saving_suggestions', 'maintenance_plans', 'hotel_field_templates',
    'competitor_price_log', 'opening_projects', 'operation_alerts', 'operation_action_tracks',
    'operation_execution_intents', 'operation_execution_tasks', 'operation_execution_evidence',
    'transfer_records', 'complaint_rooms', 'complaint_feedbacks', 'field_mappings',
    'ai_model_call_logs', 'login_logs', 'quant_simulation_records', 'expansion_records',
    'strategy_simulation_records', 'feasibility_reports',
];
foreach ($tenantScopedTables as $table) {
    assert_true(str_contains($tenantMigrationSource, '`' . $table . '`'), "tenant migration must cover {$table}");
}
assert_true(!str_contains($onlineSource, "getConfigList('online_data_cookies_list')"), 'controller auto fetch must not fall back to global cookie list');
assert_true(!str_contains($commandSource, "Cache::get('online_data_cookies_list'"), 'scheduled auto fetch must not fall back to global cookie list');

assert_true(str_contains($aiConfigSource, 'checkSuperAdmin()'), 'AI config controller must have a super admin guard');
assert_true(substr_count($aiConfigSource, '$this->checkSuperAdmin();') >= 6, 'all AI model config endpoints must require super admin');
assert_true((bool)preg_match('/function\s+read\s*\([^)]*\)[\s\S]*isSuperAdmin\(\)[\s\S]*hotel_id/s', $userSource), 'User::read must enforce hotel scope for non-super admins');
$operationOnlineRows = extract_method_source($operationSource, 'onlineRows');
$transferOnlineRows = extract_method_source($transferSource, 'onlineRows');
assert_true(!str_contains($operationOnlineRows, 'system_hotel_id IS NULL'), 'operation online rows must not include unbound OTA data in hotel scope');
assert_true(!str_contains($transferOnlineRows, 'hotel_id IN'), 'transfer online rows must not match OTA platform hotel_id as system hotel id');

$dailyDataSummary = extract_method_source($onlineSource, 'dailyDataSummary');
$dataAnalysis = extract_method_source($onlineSource, 'dataAnalysis');
$applyHistoryHotelFilter = extract_method_source($onlineSource, 'applyOnlineHistoryHotelIdFilter');
$applyCtripHotelScope = extract_method_source($onlineSource, 'applyCtripHotelScope');
$saveMeituanCommentConfig = extract_method_source($onlineSource, 'saveMeituanCommentConfig');
$saveCtripCommentConfig = extract_method_source($onlineSource, 'saveCtripCommentConfig');
$fetchCustom = extract_method_source($onlineSource, 'fetchCustom');
$sendHttpRequest = extract_method_source($onlineSource, 'sendHttpRequest');
$sendMeituanRequest = extract_method_source($onlineSource, 'sendMeituanRequest');
$sendCtripJsonRequest = extract_method_source($onlineSource, 'sendCtripJsonRequest');
$getMeituanCommentConfigList = extract_method_source($onlineSource, 'getMeituanCommentConfigList');
$getCtripCommentConfigList = extract_method_source($onlineSource, 'getCtripCommentConfigList');
$deleteCookies = extract_method_source($onlineSource, 'deleteCookies');
$deleteMeituanConfig = extract_method_source($onlineSource, 'deleteMeituanConfig');
$deleteCtripConfig = extract_method_source($onlineSource, 'deleteCtripConfig');
$isOtaConfigVisible = extract_method_source($onlineSource, 'isOtaConfigVisibleToCurrentUser');

assert_true(str_contains($dailyDataSummary, "whereIn('system_hotel_id'"), 'dailyDataSummary must enforce hotel scope for non-super admins');
assert_true(!str_contains($dataAnalysis, "where('hotel_id', \$hotelId)"), 'dataAnalysis must not treat OTA platform hotel_id as system hotel id');
assert_true(!str_contains($applyHistoryHotelFilter, "whereOr('hotel_id'"), 'history hotel filter must not OR system hotel id with OTA hotel_id');
assert_true(!str_contains($applyCtripHotelScope, "whereOr('hotel_id'"), 'Ctrip hotel scope must not OR system hotel id with OTA hotel_id');
assert_true(str_contains($saveMeituanCommentConfig, "checkActionPermission('can_fetch_online_data')"), 'Meituan comment config save must require online data fetch permission');
assert_true(str_contains($saveCtripCommentConfig, "checkActionPermission('can_fetch_online_data')"), 'Ctrip comment config save must require online data fetch permission');
assert_true(str_contains($saveMeituanCommentConfig, "'system_hotel_id'"), 'Meituan comment config must be bound to a system hotel');
assert_true(str_contains($saveCtripCommentConfig, "'system_hotel_id'"), 'Ctrip comment config must be bound to a system hotel');
assert_true(str_contains($fetchCustom, "checkActionPermission('can_fetch_online_data')"), 'custom OTA fetch must require online data fetch permission');
assert_true(str_contains($fetchCustom, 'isAllowedOtaRequestUrl'), 'custom OTA fetch must restrict target hosts');
assert_true(str_contains($sendHttpRequest, 'isAllowedOtaRequestUrl'), 'Ctrip HTTP requests must restrict target hosts');
assert_true(str_contains($sendCtripJsonRequest, 'isAllowedOtaRequestUrl'), 'Ctrip JSON requests must restrict target hosts');
assert_true(str_contains($sendMeituanRequest, 'isAllowedOtaRequestUrl'), 'Meituan HTTP requests must restrict target hosts');
assert_true(str_contains($commandSource, 'isAllowedCtripRequestUrl'), 'scheduled Ctrip command must restrict target hosts');
assert_true(str_contains($getMeituanCommentConfigList, 'filterOtaConfigListForCurrentUser'), 'Meituan comment config list must be scoped to current user');
assert_true(str_contains($getCtripCommentConfigList, 'filterOtaConfigListForCurrentUser'), 'Ctrip comment config list must be scoped to current user');
assert_true(!str_contains($isOtaConfigVisible, "\$item['hotel_id']"), 'config visibility must not treat OTA platform hotel_id as system hotel id');
assert_true(str_contains($deleteCookies, "checkActionPermission('can_delete_online_data')"), 'cookie deletion must require online data delete permission');
assert_true(!str_contains($deleteCookies, '$globalList'), 'cookie deletion must not fall back from hotel cookies to global cookies');
assert_true(str_contains($deleteMeituanConfig, "checkActionPermission('can_delete_online_data')"), 'Meituan config deletion must require online data delete permission');
assert_true(str_contains($deleteCtripConfig, "checkActionPermission('can_delete_online_data')"), 'Ctrip config deletion must require online data delete permission');
assert_true(!str_contains($legacyCronSource, "online_data_cookies_list"), 'legacy cron script must not use global cookie list');
assert_true(!str_contains($legacyCronSource, "whereNull('system_hotel_id')"), 'legacy cron script must not write unbound OTA data');
assert_true(!str_contains($legacyCronSource, "'system_hotel_id' => null"), 'legacy cron script must bind saved rows to a system hotel');

echo 'High-risk security verification passed.' . PHP_EOL;
