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

function returns_sanitized_ota_config_detail(string $source): bool
{
    $directSanitizedReturn = preg_match(
        '/success\s*\(\s*\$this->sanitizeSecretConfig\s*\(\s*\$list\s*\[\s*\$id\s*\]\s*\)\s*\)/',
        $source
    ) === 1;
    $runtimeSanitizedReturn = preg_match(
        '/(\$[A-Za-z_][A-Za-z0-9_]*)\s*=\s*\$this->sanitizeStoredOtaConfigListForRuntime\s*\(\s*\[\s*\$id\s*=>\s*\$list\s*\[\s*\$id\s*\]\s*\]\s*\)\s*;[\s\S]*?success\s*\(\s*\1\s*\[\s*\$id\s*\]\s*(?:\?\?\s*\[\s*\])?\s*\)/',
        $source
    ) === 1;

    return $directSanitizedReturn || $runtimeSanitizedReturn;
}

function verified_transfer_ota_row(int $id, int $systemHotelId, string $date): array
{
    $traceId = 'security-verifier-transfer-' . $id;
    return [
        'id' => $id,
        'system_hotel_id' => $systemHotelId,
        'hotel_id' => 'ctrip-' . $systemHotelId,
        'hotel_name' => 'Verifier Hotel',
        'platform' => 'ctrip',
        'source' => 'ctrip',
        'data_type' => 'order',
        'data_date' => $date,
        'amount' => 600,
        'quantity' => 6,
        'book_order_num' => 3,
        'ingestion_method' => 'browser_profile',
        'source_trace_id' => $traceId,
        'snapshot_time' => $date . ' 09:00:00',
        'validation_status' => 'normal',
        'readback_verified' => 1,
        'create_time' => $date . ' 09:01:00',
        'update_time' => $date . ' 09:01:00',
        'raw_data' => json_encode([
            'visitors' => 30,
            'field_facts' => array_map(
                static fn(array $fact): array => array_merge($fact, [
                    'status' => 'captured',
                    'stored_value_present' => true,
                    'capture_evidence' => [
                        'source_trace_id' => $traceId,
                        'source_url_hash' => 'sha256:security-verifier-safe',
                    ],
                ]),
                [
                    ['metric_key' => 'order_amount', 'source_path' => '$.payload.total_amount', 'storage_field' => 'online_daily_data.amount'],
                    ['metric_key' => 'order_count', 'source_path' => '$.payload.order_count', 'storage_field' => 'online_daily_data.book_order_num'],
                    ['metric_key' => 'room_nights', 'source_path' => '$.payload.room_nights', 'storage_field' => 'online_daily_data.quantity'],
                ]
            ),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    ];
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
], [verified_transfer_ota_row(7001, 7, '2026-05-01')], [
    'target_hotel_id' => 7,
    'start_date' => '2026-05-01',
    'end_date' => '2026-05-01',
]]);
assert_same(1000.0, $transferMetrics['revenue'], 'daily report financials must not double count same-day OTA revenue');
assert_same(10.0, $transferMetrics['room_nights'], 'daily report room nights must not double count same-day OTA room nights');
assert_same(3, $transferMetrics['orders'], 'OTA orders should still enrich transfer metrics');
assert_same(600.0, $transferMetrics['ota_channel_revenue'], 'OTA revenue must remain visible only in the channel-scoped metric');
assert_same(6.0, $transferMetrics['ota_channel_room_nights'], 'OTA room nights must remain visible only in the channel-scoped metric');

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
], [verified_transfer_ota_row(8001, 8, '2026-05-01')], [
    'target_hotel_id' => 7,
    'start_date' => '2026-05-01',
    'end_date' => '2026-05-01',
]]);
assert_same(1000.0, $multiHotelMetrics['revenue'], 'OTA channel revenue from another hotel must not be promoted to whole-hotel revenue');
assert_same(10.0, $multiHotelMetrics['room_nights'], 'OTA channel room nights from another hotel must not be promoted to whole-hotel room nights');
assert_same(0.0, $multiHotelMetrics['ota_channel_revenue'], 'cross-hotel OTA revenue must be excluded from the target hotel channel metrics');
assert_same(0.0, $multiHotelMetrics['ota_channel_room_nights'], 'cross-hotel OTA room nights must be excluded from the target hotel channel metrics');
assert_same(1, $multiHotelMetrics['truth_context']['scope_exclusion_counts']['hotel_scope_mismatch'], 'cross-hotel OTA rows must report a hotel scope exclusion');

$onlineSource = file_get_contents(__DIR__ . '/../app/controller/OnlineData.php');
foreach (glob(__DIR__ . '/../app/controller/concern/*.php') ?: [] as $concernFile) {
    $onlineSource .= "\n" . file_get_contents($concernFile);
}
$authSource = file_get_contents(__DIR__ . '/../app/middleware/Auth.php');
$authControllerSource = file_get_contents(__DIR__ . '/../app/controller/Auth.php');
$loginRateLimiterSource = file_get_contents(__DIR__ . '/../app/service/LoginRateLimiter.php');
$loginRateLimiterMigrationSource = file_get_contents(__DIR__ . '/../database/migrations/20260719_create_login_rate_limit_counters.sql');
$manualOnlineFetchTaskSource = file_get_contents(__DIR__ . '/../app/service/ManualOnlineFetchTaskService.php');
$exceptionHandleSource = file_get_contents(__DIR__ . '/../app/ExceptionHandle.php');
$dailyReportSource = file_get_contents(__DIR__ . '/../app/controller/DailyReport.php');
$publicEntrySource = file_get_contents(__DIR__ . '/../public/index.html');
$publicRouterSource = file_get_contents(__DIR__ . '/../public/router.php');
$frontendLogicSource = file_get_contents(__DIR__ . '/../public/app-main.js');
$frontendTemplateSource = file_get_contents(__DIR__ . '/../resources/frontend/app-template.html');
$systemStaticSource = file_get_contents(__DIR__ . '/../public/system-static.js');
$hotelControllerSource = file_get_contents(__DIR__ . '/../app/controller/Hotel.php');
$hotelDataMergeSource = file_get_contents(__DIR__ . '/../app/service/HotelDataMergeService.php');
$platformSyncSource = file_get_contents(__DIR__ . '/../app/service/PlatformDataSyncService.php');
$onlineDailyPersistenceSource = file_get_contents(__DIR__ . '/../app/service/OnlineDailyDataPersistenceService.php');
$competitorSource = file_get_contents(__DIR__ . '/../app/controller/CompetitorApi.php');
$competitorAnalysisModelSource = file_get_contents(__DIR__ . '/../app/model/CompetitorAnalysis.php');
$systemConfigControllerSource = file_get_contents(__DIR__ . '/../app/controller/SystemConfigController.php');
$aiConfigSource = file_get_contents(__DIR__ . '/../app/controller/AiConfig.php');
$llmClientSource = file_get_contents(__DIR__ . '/../app/service/LlmClient.php');
$apiDataSourceAdapterSource = file_get_contents(__DIR__ . '/../app/service/platform/ApiDataSourceAdapter.php');
$revenueResearchSource = file_get_contents(__DIR__ . '/../app/service/RevenueResearchService.php');
$userSource = file_get_contents(__DIR__ . '/../app/controller/User.php');
$protectedCapabilitySource = file_get_contents(__DIR__ . '/../app/service/ProtectedCapabilityService.php');
$lifecycleSource = file_get_contents(__DIR__ . '/../app/controller/Lifecycle.php');
$openingSource = file_get_contents(__DIR__ . '/../app/service/OpeningService.php');
$ctripCollectorConcernSource = file_get_contents(__DIR__ . '/../app/controller/concern/CtripCollectorWorkflowConcern.php');
$competitorDeviceAuthSource = file_get_contents(__DIR__ . '/../app/service/CompetitorDeviceAuthService.php');
$competitorDeviceMigrationSource = file_get_contents(__DIR__ . '/../database/migrations/20260719_bind_competitor_devices_to_hotel_scope.sql');
$operationSource = file_get_contents(__DIR__ . '/../app/service/OperationManagementService.php');
$transferSource = file_get_contents(__DIR__ . '/../app/service/TransferDecisionService.php');
$ctripBrowserAdapterSource = file_get_contents(__DIR__ . '/../app/service/platform/CtripBrowserProfileDataSourceAdapter.php');
$meituanBrowserAdapterSource = file_get_contents(__DIR__ . '/../app/service/platform/MeituanBrowserProfileDataSourceAdapter.php');
$platformProfileCaptureSource = file_get_contents(__DIR__ . '/../app/controller/concern/PlatformProfileCaptureConcern.php');
$chromiumCookieExtractorSource = file_get_contents(__DIR__ . '/extract_chromium_cookie_header.php');
$loginLogSource = file_get_contents(__DIR__ . '/../app/model/LoginLog.php');
$tenantMigrationSource = file_get_contents(__DIR__ . '/../database/migrations/20260529_add_tenant_security_fields.sql');
$initFullSource = file_get_contents(__DIR__ . '/../database/init_full.sql');
$commandSource = file_get_contents(__DIR__ . '/../app/command/AutoFetchOnlineData.php');
$legacyCronSource = file_get_contents(__DIR__ . '/auto_fetch_online_data.php');
$systemConfigModelSource = file_get_contents(__DIR__ . '/../app/model/SystemConfig.php');
$otaConfigConcernSource = file_get_contents(__DIR__ . '/../app/controller/concern/OtaConfigConcern.php');
$otaMigrationCommandSource = file_get_contents(__DIR__ . '/../app/command/MigrateOtaCredentials.php');
$otaMigrationServiceSource = file_get_contents(__DIR__ . '/../app/service/OtaCredentialMigrationService.php');
$packageSource = file_get_contents(__DIR__ . '/../package.json');
$meituanCapturedPersistenceSource = extract_method_source($onlineSource, 'saveMeituanCapturedDailyRows');
$competitorTaskSource = extract_method_source($competitorSource, 'task');
$competitorReportSource = extract_method_source($competitorSource, 'report')
    . "\n"
    . extract_method_source($competitorSource, 'reportLegacy');
$competitorReportTokenSource = extract_method_source($competitorSource, 'isValidReportToken');
$competitorAuditSanitizerSource = extract_method_source($competitorSource, 'sanitizeExternalAuditText');
$cronTriggerSource = extract_method_source($onlineSource, 'cronTrigger');
$dailyPatrolCronSource = extract_method_source($onlineSource, 'dailyWorkbenchPatrolCron');
$competitorAlertSource = extract_method_source($competitorAnalysisModelSource, 'getAlertCompetitors');
$hotelMergePreviewSource = extract_method_source($hotelControllerSource, 'mergePreview');
$hotelMergeExecuteSource = extract_method_source($hotelControllerSource, 'mergeExecute');
$registerSource = extract_method_source($authControllerSource, 'register');
$userUpdateSource = extract_method_source($userSource, 'update');
$manualTaskApiUrlSource = extract_method_source($manualOnlineFetchTaskSource, 'normalizeTaskApiUrl');
$lifecycleResolveHotelsSource = extract_method_source($lifecycleSource, 'resolveHotelIds');
$openingRequireProjectSource = extract_method_source($openingSource, 'requireProject');
$ctripCollectorContractSource = extract_method_source($ctripCollectorConcernSource, 'ctripCollectorContract');

assert_true((bool)preg_match('/function\s+fetchCtrip\s*\([^)]*\)\s*:\s*Response\s*\{\s*\$this->checkPermission\(\);/s', $onlineSource), 'fetchCtrip must check login and hotel binding before reading cookies');
assert_true((bool)preg_match('/function\s+saveCtripConfig\s*\([^)]*\)\s*:\s*Response\s*\{\s*\$this->checkPermission\(\);/s', $onlineSource), 'saveCtripConfig must check login and hotel binding');
assert_true(str_contains($onlineSource, "checkActionPermission('can_fetch_online_data')"), 'online data write/fetch endpoints must enforce can_fetch_online_data');
assert_true(str_contains($onlineSource, "checkActionPermission('can_delete_online_data')"), 'online data delete endpoints must enforce can_delete_online_data');
assert_true(str_contains($authSource, 'enforceRateLimit'), 'authenticated APIs must enforce request rate limits');
assert_true(str_contains($authSource, 'rate_limited'), 'rate-limited requests must be written to operation logs');
assert_true(str_contains($authControllerSource, 'private const TOKEN_TTL_SECONDS = 259200'), 'login tokens must use the product-approved 72-hour TTL');
assert_true(str_contains($authControllerSource, 'new LoginRateLimiter()') && str_contains($authControllerSource, 'consumeAttempt($ip, $username)') && str_contains($authControllerSource, "'Retry-After'"), 'public login must reserve a bounded attempt before password verification');
$loginValidationBranch = substr(
    $authControllerSource,
    strpos($authControllerSource, "\$rawUsername = \$this->request->post('username', '')"),
    strpos($authControllerSource, "\$user = User::with(['role', 'hotel'])") - strpos($authControllerSource, "\$rawUsername = \$this->request->post('username', '')")
);
assert_true(!str_contains($loginValidationBranch, 'recordLoginFailure') && !str_contains($loginValidationBranch, 'LoginLog::record'), 'malformed and empty login payloads must not append persistent login audit rows');
$loginDeniedBranch = substr(
    $authControllerSource,
    strpos($authControllerSource, "if (!\$rateLimit['allowed'])"),
    strpos($authControllerSource, "\$reservationBucket = isset(\$rateLimit['reservation_bucket'])") - strpos($authControllerSource, "if (!\$rateLimit['allowed'])")
);
assert_true(!str_contains($loginDeniedBranch, 'recordLoginFailure') && !str_contains($loginDeniedBranch, 'LoginLog::record'), '429 login responses must not create one persistent audit row per denied request');
assert_true(str_contains($authControllerSource, 'invalidLoginPayload()') && str_contains($authControllerSource, 'normalizeLoginClientInfo'), 'public login must reject malformed input before authentication and log persistence');
assert_true(strpos($authControllerSource, 'invalidLoginPayload()') < strpos($authControllerSource, "User::with(['role', 'hotel'])"), 'login input validation must run before the user lookup');
assert_true(str_contains($loginRateLimiterSource, 'IDENTITY_LIMIT = 10') && str_contains($loginRateLimiterSource, 'USERNAME_LIMIT = 25') && str_contains($loginRateLimiterSource, 'IP_LIMIT = 40'), 'login rate limiting must cover identity, distributed source rotation, and source IP abuse');
assert_true(substr_count($loginRateLimiterSource, "hash('sha256'") >= 3, 'login limiter cache keys must hash IP and username identity material');
assert_true(str_contains($loginRateLimiterSource, "private const TABLE = 'login_rate_limit_counters'") && str_contains($loginRateLimiterSource, 'Db::transaction'), 'login limiter must serialize reservations in the shared database across application instances');
assert_true(str_contains($authControllerSource, "reservation_bucket") && str_contains($authControllerSource, 'releaseSuccessfulAttempt($ip, $username, $reservationBucket)'), 'successful authentication must release only its own reserved bucket');
assert_true(str_contains($loginRateLimiterSource, 'flock($handle, LOCK_EX)'), 'login limiter custom test store must remain serialized locally');
assert_true(str_contains($loginRateLimiterMigrationSource, 'CREATE TABLE IF NOT EXISTS `login_rate_limit_counters`') && str_contains($loginRateLimiterMigrationSource, 'ENGINE=InnoDB'), 'shared login limiter must ship an atomic InnoDB migration');
assert_true(str_contains($publicRouterSource, "str_starts_with(\$basename, '.')") && str_contains($publicRouterSource, '!array_key_exists($extension, $mimeTypes)'), 'PHP development router must reject dotfiles and non-whitelisted static extensions');
assert_true(str_contains($publicRouterSource, 'str_contains($decodedStaticPath, "\\0")'), 'PHP development router must reject null-byte paths before realpath');
assert_true(str_contains($publicRouterSource, '$hasHiddenPathSegment') && str_contains($publicRouterSource, "str_starts_with(\$segment, '.')"), 'PHP development router must reject hidden files and directories at every path segment');
assert_true(!str_contains($publicRouterSource, "\$mimeTypes[\$extension] ?? 'application/octet-stream'"), 'PHP development router must not download unknown files as octet-stream');
foreach (['X-Content-Type-Options: nosniff', 'X-Frame-Options: SAMEORIGIN', 'Referrer-Policy: strict-origin-when-cross-origin'] as $securityHeader) {
    assert_true(str_contains($publicRouterSource, $securityHeader), 'PHP development router must emit security header ' . $securityHeader);
}
assert_true(str_contains($publicRouterSource, 'Content-Security-Policy-Report-Only') && str_contains($publicRouterSource, "script-src-attr 'none'"), 'PHP development router must stage CSP in report-only mode');
assert_true(!str_contains($publicRouterSource, "header('Content-Security-Policy:"), 'PHP development router must not enforce CSP before violations are reviewed');
assert_true(str_contains($publicRouterSource, 'Strict-Transport-Security: max-age=31536000') && !str_contains($publicRouterSource, "SERVER_PORT") && !str_contains($publicRouterSource, 'HTTP_X_FORWARDED_PROTO'), 'PHP development router must emit HSTS only for explicitly verified HTTPS');
assert_true(str_contains($exceptionHandleSource, "'route_not_found'") && str_contains($exceptionHandleSource, "'internal_error'"), 'exception handler must return stable API reasons without framework details');
assert_true(!str_contains($exceptionHandleSource, "return parent::render(\$request, \$e);"), 'exception handler must not delegate public errors to the debug stack renderer');
assert_true(!str_contains($dailyPatrolCronSource, "'Daily workbench patrol cron failed: ' . \$e->getMessage()"), 'public patrol cron must not return raw exception messages');
assert_true(str_contains($dailyPatrolCronSource, "'daily_workbench_patrol_cron_failed'"), 'public patrol cron must return a stable failure reason');
assert_true(!str_contains($manualTaskApiUrlSource, "\$_SERVER['SERVER_NAME']"), 'manual OTA worker URL allowlist must not trust the request Host/SERVER_NAME');
assert_true(str_contains($manualTaskApiUrlSource, '$allowedOrigins') && str_contains($manualTaskApiUrlSource, "getenv('APP_URL')"), 'manual OTA worker may forward authorization only to an exact configured origin');
assert_true(str_contains($registerSource, "return \$this->error('系统已关闭自助注册，请联系管理员创建账号', 403);"), 'public registration must remain a fixed 403 compatibility tombstone');
assert_true(!str_contains($authControllerSource, 'registerLegacyDisabled') && !str_contains($registerSource, 'new User'), 'public registration must not retain a hidden account-creation path');
assert_true(str_contains($authSource, 'private const TOKEN_MAX_AGE_SECONDS = 259200'), 'auth middleware must reject tokens older than the product-approved 72-hour limit');
assert_true(str_contains($authSource, 'isTokenExpiredByAge'), 'auth middleware must enforce token created_at age');
assert_true(str_contains($userUpdateSource, '修改本人密码请使用专用改密接口并验证原密码'), 'generic user update must reject self-service password changes without the old password');
assert_true(str_contains($userUpdateSource, "'reset_password'") && str_contains($userUpdateSource, "cache('auth_revoked_after_'"), 'administrator password resets must write a durable audit and revoke sessions');
assert_true(str_contains($authSource, "['change_password', 'reset_password']"), 'legacy token upgrade must honor both self-change and administrator-reset audits');
assert_true(str_contains($protectedCapabilitySource, "'api/lifecycle'") && str_contains($protectedCapabilitySource, "'api/investment-decision'"), 'lifecycle and investment overview must require the investment capability');
assert_true(str_contains($lifecycleResolveHotelsSource, "hasHotelPermission(\$hotelId, 'can_use_investment')"), 'lifecycle overview must keep only hotels with investment permission');
assert_true(str_contains($lifecycleSource, 'withCurrentUser') && str_contains($lifecycleSource, "Db::name('feasibility_reports')") && str_contains($lifecycleSource, "Db::name('strategy_simulation_records')"), 'lifecycle investment records must be scoped to the current non-super user');
assert_true(str_contains($openingRequireProjectSource, 'canAccessOwnedProject($project, $hotelIds'), 'opening project reads and mutations must enforce the current hotel scope');
assert_true(str_contains($openingSource, 'applyProjectScope($query, $hotelIds') && str_contains($openingSource, 'resolveProjectTenantId((int)$data'), 'opening project lists and hotel changes must keep hotel and tenant scope aligned');
assert_true(str_contains($ctripCollectorContractSource, "where('platform', 'ctrip')") && str_contains($ctripCollectorContractSource, 'canAccessCtripCollectorSource($row)'), 'Ctrip collector contracts must reject cross-platform and cross-hotel source IDs');
assert_true(str_contains($competitorSource, 'enforceExternalRateLimit'), 'public competitor token APIs must enforce route-local rate limits');
assert_true(str_contains($competitorTaskSource, 'findAuthorizedBinding(') && !str_contains($competitorTaskSource, '$device = new CompetitorDevice();'), 'competitor task endpoint must require a pre-registered hotel-scoped device binding');
assert_true(str_contains($competitorTaskSource, "where('tenant_id', \$tenantId)") && str_contains($competitorTaskSource, "where('store_id', \$storeId)"), 'competitor tasks must stay inside the bound tenant and store');
assert_true(str_contains($competitorDeviceAuthSource, 'password_verify') && str_contains($competitorDeviceAuthSource, 'bindingScopeIsActive'), 'competitor device tokens must be one-way and revalidate current hotel permission');
assert_true(str_contains($competitorDeviceAuthSource, "authorize(\$user, 'ota.collect', \$storeId)"), 'competitor device activation must pass the full ota.collect authorization gate for the target hotel');
assert_true(str_contains($competitorDeviceAuthSource, '$userTenantId !== $tenantId'), 'competitor device activation must reject a user whose tenant differs from the hotel tenant');
assert_true(
    str_contains($competitorDeviceMigrationSource, 'JOIN `hotels` AS `h` ON `h`.`id` = `ch`.`store_id`')
    && str_contains($competitorDeviceMigrationSource, 'SET `ch`.`tenant_id` = `h`.`tenant_id`'),
    'competitor hotel tenant backfill must use the authoritative hotels table'
);
assert_true(
    str_contains($competitorDeviceMigrationSource, 'LEFT JOIN `hotels` AS `h` ON `h`.`id` = `ch`.`store_id`')
    && str_contains($competitorDeviceMigrationSource, 'SET `ch`.`tenant_id` = NULL,')
    && str_contains($competitorDeviceMigrationSource, '`ch`.`status` = 0'),
    'orphaned or tenantless competitor hotels must be unbound and disabled during migration'
);
assert_true(
    str_contains($competitorDeviceMigrationSource, 'SET `status` = 0,')
    && str_contains($competitorDeviceMigrationSource, '`revoked_at` = COALESCE(`revoked_at`, NOW())')
    && str_contains($competitorDeviceMigrationSource, '`tenant_id` IS NULL')
    && str_contains($competitorDeviceMigrationSource, '`user_id` IS NULL')
    && str_contains($competitorDeviceMigrationSource, '`store_id` IS NULL')
    && str_contains($competitorDeviceMigrationSource, "`platform` = ''")
    && str_contains($competitorDeviceMigrationSource, "`token_hash` = ''"),
    'legacy competitor devices with an incomplete binding must be revoked and disabled during migration'
);
$baseTenantMigrationOffset = strpos($initFullSource, '20260529_add_tenant_security_fields.sql');
$competitorDeviceMigrationOffset = strpos($initFullSource, '20260719_bind_competitor_devices_to_hotel_scope.sql');
assert_true(
    $baseTenantMigrationOffset !== false
    && $competitorDeviceMigrationOffset !== false
    && $baseTenantMigrationOffset < $competitorDeviceMigrationOffset,
    'full database initialization must apply competitor device scope binding after the base tenant fields exist'
);
assert_true(str_contains($competitorTaskSource, "enforceExternalRateLimit('task'"), 'competitor task endpoint must rate limit external devices');
assert_true(str_contains($competitorReportSource, "enforceExternalRateLimit('report'"), 'competitor report endpoint must rate limit external devices');
assert_true(str_contains($competitorSource, "\$ipHash = substr(sha1((string)\$this->request->ip()), 0, 16);"), 'public competitor token APIs must rate limit pre-auth attempts by IP hash');
assert_true(!str_contains($competitorSource, "\$identity . '|' . (string)\$this->request->ip()"), 'public competitor token APIs must not let user-controlled identity bypass pre-auth rate limits');
assert_true(str_contains($competitorTaskSource, '$this->extractTaskToken()') && str_contains($competitorSource, "header('X-Task-Token', '')"), 'competitor task endpoint must read auth token from X-Task-Token header');
assert_true(!str_contains($competitorTaskSource, "post('token'") && !str_contains($competitorTaskSource, 'post("token"'), 'competitor task endpoint must not read auth token from request body');
assert_true(str_contains($competitorReportSource, '$this->isValidReportToken($expectedToken)') && str_contains($competitorReportTokenSource, '$this->extractReportToken()') && str_contains($competitorSource, "header('X-Report-Token', '')"), 'competitor report endpoint must read auth token from X-Report-Token header');
assert_true(!str_contains($competitorReportTokenSource, "post('report_token'") && !str_contains($competitorReportTokenSource, 'post("report_token"') && !str_contains($competitorReportTokenSource, "post('token'") && !str_contains($competitorReportTokenSource, 'post("token"'), 'competitor report endpoint must not read report token from request body');
assert_true(str_contains($competitorAuditSanitizerSource, 'Authorization') && str_contains($competitorAuditSanitizerSource, 'cookie|token|authorization'), 'competitor public endpoint audit text must redact credential-shaped values');
assert_true(str_contains($competitorAuditSanitizerSource, '1[3-9]') && str_contains($competitorAuditSanitizerSource, '\\d{12,}'), 'competitor public endpoint audit text must mask phone numbers and long identifiers');
assert_true(str_contains($cronTriggerSource, "header('X-Cron-Token', '')"), 'cron trigger endpoint must read auth token from X-Cron-Token header');
assert_true(!str_contains($cronTriggerSource, "get('token'") && !str_contains($cronTriggerSource, 'get("token"'), 'cron trigger endpoint must not read auth token from URL query');
assert_true(str_contains($cronTriggerSource, 'hash_equals($configToken, $token)'), 'cron trigger endpoint must compare auth token with hash_equals');
assert_true(str_contains($dailyPatrolCronSource, "header('X-Cron-Token', '')"), 'daily patrol cron endpoint must read auth token from X-Cron-Token header');
assert_true(!str_contains($dailyPatrolCronSource, "get('token'") && !str_contains($dailyPatrolCronSource, 'get("token"'), 'daily patrol cron endpoint must not read auth token from URL query');
assert_true(str_contains($dailyPatrolCronSource, 'hash_equals($configToken, $token)'), 'daily patrol cron endpoint must compare auth token with hash_equals');
assert_true(str_contains($competitorSource, 'external_rate_limited'), 'rate-limited competitor token requests must be audited');
assert_true(str_contains($competitorSource, 'SCREENSHOT_MAX_BYTES'), 'competitor report screenshot uploads must have a binary size limit');
assert_true(str_contains($competitorSource, 'getimagesizefromstring'), 'competitor report screenshots must be validated as real images');
assert_true(str_contains($competitorSource, 'SCREENSHOT_ALLOWED_MIME_EXTENSIONS'), 'competitor report screenshots must enforce image MIME allowlist');
foreach ([
    'Ctrip browser Profile adapter' => $ctripBrowserAdapterSource,
    'Meituan browser Profile adapter' => $meituanBrowserAdapterSource,
] as $label => $source) {
    assert_true(!str_contains($source, "['secret']"), $label . ' must not hydrate stored credential material');
    assert_true(!str_contains($source, '--cookies-file='), $label . ' must not inject a durable credential through a temporary file');
    assert_true(!str_contains($source, 'createCookieFile('), $label . ' must not create a temporary stored-Cookie file');
}
foreach ([
    'Profile capture concern' => $platformProfileCaptureSource,
    'Chromium Cookie extractor' => $chromiumCookieExtractorSource,
] as $label => $source) {
    assert_true(str_contains($source, 'chmod($path, 0600)'), $label . ' must restrict temporary Cookie file permissions after writing');
    assert_true(str_contains($source, '@unlink($path)'), $label . ' must delete temporary Cookie files when permission hardening fails');
}
assert_true(str_contains($competitorTaskSource, "OperationLog::record('competitor', 'task'"), 'competitor task endpoint must write operation audit logs');
assert_true(
    str_contains($competitorReportSource, "OperationLog::record('competitor', 'report'")
        || str_contains($competitorReportSource, "OperationLog::record('competitor', \$auditError === null ? 'report' : 'report_failed'"),
    'competitor report endpoint must write terminal success/failure operation audit logs'
);
assert_true(str_contains($competitorAlertSource, "whereRaw('ABS(price_difference) >= :threshold'"), 'competitor alert threshold must use a bound SQL parameter');
assert_true(str_contains($competitorAlertSource, "'threshold' => \$threshold"), 'competitor alert threshold binding must pass the threshold value separately');
assert_true(!str_contains($competitorAlertSource, 'whereRaw("ABS(price_difference) >= {$threshold}")'), 'competitor alert threshold must not be interpolated into raw SQL');
assert_true(str_contains($dailyReportSource, 'EXPORT_BATCH_LIMIT'), 'daily report exports must have a batch download limit');
assert_true(str_contains($dailyReportSource, 'IMPORT_XLSX_MAX_BYTES'), 'daily report imports must have an upload size limit');
assert_true(str_contains($dailyReportSource, 'IMPORT_XLSX_MAX_UNCOMPRESSED_BYTES'), 'daily report imports must limit uncompressed XLSX size');
assert_true(str_contains($dailyReportSource, 'validateDailyImportZipArchive'), 'daily report imports must validate XLSX zip structure before parsing');
assert_true(str_contains($dailyReportSource, 'SUXIOS Export Watermark'), 'daily report exports must include a user watermark');
assert_true(!preg_match('/\beval\s*\(/', $dailyReportSource), 'daily report formulas must not use eval');
assert_true(!preg_match('/\bshell_exec\s*\(/', $dailyReportSource), 'daily report Excel parsing must not use shell_exec');
assert_true(str_contains($systemConfigControllerSource, 'IMPORT_MAX_BYTES'), 'system config import must have a file size limit');
assert_true(str_contains($systemConfigControllerSource, 'validateSystemConfigImportData'), 'system config import must validate JSON shape before applying configs');
assert_true(str_contains($systemConfigControllerSource, 'containsRedactedExportSecretPlaceholder'), 'system config import must detect redacted export placeholders before applying configs');
assert_true(str_contains($systemConfigControllerSource, 'skipped_redacted_values'), 'system config import must report skipped redacted export placeholders');
assert_true(str_contains($frontendLogicSource, 'skipped_redacted_values'), 'system config import UI must show skipped redacted placeholder count');
assert_true(str_contains($onlineDailyPersistenceSource, 'resolveTenantIdForSystemHotel'), 'online daily data writes must resolve tenant_id from the owning system hotel');
assert_true(str_contains($onlineDailyPersistenceSource, 'applyTenantScope'), 'online daily data writes must apply the resolved tenant scope');
assert_true(str_contains($meituanCapturedPersistenceSource, 'OnlineDailyDataPersistenceService::applyTenantScope($row, $columns)'), 'direct Meituan capture writes must apply tenant scope before persistence');
assert_true(str_contains($platformSyncSource, "'tenant_id'"), 'platform sync writes must populate tenant_id when available');
assert_true(str_contains($loginLogSource, 'tenantIdForUser'), 'login logs must populate tenant_id for authenticated users when available');
assert_true(str_contains($operationSource, 'withHotelTenantId'), 'hotel-scoped operation writes must resolve tenant_id from the owning hotel');
assert_true(str_contains($operationSource, 'withExecutionTaskTenantId'), 'execution evidence writes must inherit and verify the task tenant scope');
assert_true(str_contains($transferSource, "'tenant_id' => \$hotelId"), 'transfer records must populate tenant_id on write');
assert_true(str_contains($initFullSource, '20260529_add_tenant_security_fields.sql'), 'full database initialization must apply tenant security migration');
assert_true(str_contains($hotelMergePreviewSource, '$this->checkPermission(true);'), 'hotel data merge preview must require super admin');
assert_true(str_contains($hotelMergeExecuteSource, '$this->checkPermission(true);'), 'hotel data merge execution must require super admin');
assert_true(str_contains($hotelMergeExecuteSource, '$service->execute($sourceHotelId, $targetHotelId, $actualConfirmation, $deactivateSource)'), 'hotel data merge execution must pass confirmation text into the service');
assert_true(str_contains($hotelMergeExecuteSource, "OperationLog::record(\n                'hotel',\n                'merge_data'"), 'hotel data merge execution must write operation audit logs');
assert_true((bool)preg_match('/function\s+execute\s*\(\s*int\s+\$sourceHotelId\s*,\s*int\s+\$targetHotelId\s*,\s*string\s+\$confirmationText\s*,\s*bool\s+\$deactivateSource\s*=\s*false\s*\)/', $hotelDataMergeSource), 'hotel data merge service execute must require explicit confirmation text');
assert_true(str_contains($hotelDataMergeSource, "['table' => 'online_daily_data', 'column' => 'system_hotel_id'"), 'hotel data merge must migrate online_daily_data by system_hotel_id only');
assert_true(!str_contains($hotelDataMergeSource, "['table' => 'online_daily_data', 'column' => 'hotel_id'"), 'hotel data merge must not migrate OTA platform hotel_id');
assert_true(str_contains($hotelDataMergeSource, "'tenant_id_retargeted' => true"), 'hotel data merge preview must disclose tenant_id retargeting');
assert_true(str_contains($hotelDataMergeSource, "'merges_duplicate_user_permissions' => true"), 'hotel data merge preview must disclose duplicate user permission merge policy');
assert_true(str_contains($hotelDataMergeSource, "'expected_update_rows'"), 'hotel data merge preview must separate expected updates from skippable duplicate grants');
assert_true(str_contains($hotelDataMergeSource, "'merged_conflict_total'"), 'hotel data merge execution must report merged duplicate grants');
assert_true(str_contains($hotelDataMergeSource, "\$payload['tenant_id'] = \$targetTenantId"), 'hotel data merge updates must retarget tenant_id when available');
assert_true(str_contains($hotelDataMergeSource, "in_array(\$indexColumn, \$migratingColumns, true)"), 'hotel data merge unique conflict detection must treat tenant_id as a migrating column');
assert_true(str_contains($hotelDataMergeSource, 'Db::query($sql, [$sourceHotelId, $targetHotelId])'), 'hotel data merge unique conflict SQL must bind hotel ids');
assert_true(str_contains($hotelDataMergeSource, 'merge_then_remove_source_duplicate_permission'), 'hotel data merge duplicate user grants must be merged before source duplicates are removed');
assert_true(str_contains($hotelDataMergeSource, 'duplicatePermissionMergeAssignments') && str_contains($hotelDataMergeSource, 'GREATEST(COALESCE(t.'), 'hotel data merge duplicate user grants must merge permission flags');
assert_true(!str_contains($hotelDataMergeSource, 'skip_source_duplicate_permission'), 'hotel data merge must not describe duplicate grants as simple skips');
assert_true(str_contains($systemStaticSource, 'const createHotelMergeForm = () => ({') && str_contains($systemStaticSource, 'deactivate_source: false'), 'hotel data merge UI must not deactivate the source hotel by default');
assert_true(str_contains($frontendLogicSource, '系统门店归属和 tenant_id 会改写') && str_contains($frontendLogicSource, 'OTA平台酒店ID不会改写'), 'hotel data merge UI must disclose tenant_id retargeting and OTA platform hotel id boundary');
assert_true(str_contains($frontendTemplateSource, '先合并源/目标权限位') && str_contains($systemStaticSource, '合并重复授权'), 'hotel data merge UI must disclose duplicate permission merge semantics');
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

$durableCacheKeysMatch = [];
assert_true(
    preg_match('/private const DURABLE_VALUE_CACHE_KEYS\s*=\s*\[([\s\S]*?)\];/', $systemConfigModelSource, $durableCacheKeysMatch) === 1,
    'SystemConfig must declare a durable-cache allowlist'
);
$durableCacheKeysSource = (string)($durableCacheKeysMatch[1] ?? '');
assert_true(str_contains($systemConfigModelSource, "'ctrip_config_list' => true") && str_contains($systemConfigModelSource, "'meituan_config_list' => true"), 'SystemConfig must declare protected OTA config keys');
assert_true(!str_contains($durableCacheKeysSource, 'ctrip_config_list') && !str_contains($durableCacheKeysSource, 'meituan_config_list'), 'protected OTA config keys must not enter durable cache');
assert_true(str_contains($systemConfigControllerSource, 'guardProtectedOtaKey($requestedKey)') && str_contains($systemConfigControllerSource, 'guardProtectedOtaKeys($data)'), 'generic system config read/update must reject protected OTA keys');
assert_true(str_contains($systemConfigControllerSource, 'getAllConfigsWithoutProtectedOtaCache()'), 'generic system config index must filter protected OTA keys');

$getCtripConfigDetail = extract_method_source($onlineSource, 'getCtripConfigDetail');
$getMeituanConfigDetail = extract_method_source($onlineSource, 'getMeituanConfigDetail');
assert_true(returns_sanitized_ota_config_detail($getCtripConfigDetail), 'Ctrip config detail must return sanitized metadata only');
assert_true(returns_sanitized_ota_config_detail($getMeituanConfigDetail), 'Meituan config detail must return sanitized metadata only');
assert_true(!preg_match('/success\s*\(\s*\$list\s*\[\s*\$id\s*\]\s*(?:\?\?\s*\[\s*\])?\s*\)/', $getCtripConfigDetail . $getMeituanConfigDetail), 'OTA config detail endpoints must not return raw list items');
assert_true(str_contains($otaConfigConcernSource, 'withPayloadForExecution('), 'OTA execution must cross the vault callback boundary');
foreach (['ctrip_config_list', 'meituan_config_list', 'online_data_cookies_', 'data_config_'] as $legacySecretStore) {
    assert_true(!str_contains($commandSource, $legacySecretStore), 'scheduled OTA execution must not parse legacy secret store ' . $legacySecretStore);
}
assert_true(str_contains($commandSource, 'Scheduled collection is Profile-only.'), 'scheduled OTA execution must remain Profile-only');
assert_true(str_contains($commandSource, "where('ingestion_method', 'browser_profile')"), 'scheduled OTA execution must select browser Profile sources only');
assert_true(!str_contains($commandSource, 'withPayloadForExecution('), 'scheduled OTA execution must not decrypt reusable Cookie/API credentials');

$migrationRunSource = extract_method_source($otaMigrationServiceSource, 'run');
$migrationSummarySource = extract_method_source($otaMigrationServiceSource, 'safeSummary');
$migrationDryRunOffset = strpos($migrationRunSource, 'if (!$execute)');
$migrationTransactionOffset = strpos($migrationRunSource, 'Db::transaction(');
assert_true(str_contains($otaMigrationCommandSource, "addOption('execute', null, Option::VALUE_NONE") && str_contains($otaMigrationCommandSource, "getOption('execute')"), 'OTA migration mutation must require an explicit --execute flag');
assert_true($migrationDryRunOffset !== false && $migrationTransactionOffset !== false && $migrationDryRunOffset < $migrationTransactionOffset, 'OTA migration must return a dry-run summary before any transaction');
assert_true(!preg_match('/[\'\"](?:cookies?|auth_data|authorization|token|api_key|password|secret_payload|fingerprint_payload|encrypted_payload|ciphertext|config_id|key_id|payload_version)[\'\"]\s*=>/i', $migrationSummarySource), 'OTA migration summary must not expose secret-valued or raw locator fields');
assert_true(!str_contains($otaMigrationCommandSource, 'getMessage('), 'OTA migration command must not print exception text');
assert_true(str_contains($packageSource, '"verify:ota-credential-vault": "node scripts/verify_ota_credential_vault.mjs"'), 'package scripts must register the OTA credential vault verifier');

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
$saveCookies = extract_method_source($onlineSource, 'saveCookies');
$getCookiesList = extract_method_source($onlineSource, 'getCookiesList');
$getCookiesDetail = extract_method_source($onlineSource, 'getCookiesDetail');
$deleteCookies = extract_method_source($onlineSource, 'deleteCookies');
$batchDeleteCookies = extract_method_source($onlineSource, 'batchDeleteCookies');
$autoCaptureCtripCookie = extract_method_source($onlineSource, 'autoCaptureCtripCookie');
$saveCtripConfigByBookmark = extract_method_source($onlineSource, 'saveCtripConfigByBookmark');
$deleteMeituanConfig = extract_method_source($onlineSource, 'deleteMeituanConfig');
$deleteCtripConfig = extract_method_source($onlineSource, 'deleteCtripConfig');
$isOtaConfigVisible = extract_method_source($onlineSource, 'isOtaConfigVisibleToCurrentUser');
$getCookieAlerts = extract_method_source($onlineSource, 'getCookieAlerts');

assert_true(str_contains($dailyDataSummary, "whereIn('system_hotel_id'"), 'dailyDataSummary must enforce hotel scope for non-super admins');
assert_true(!str_contains($dataAnalysis, "where('hotel_id', \$hotelId)"), 'dataAnalysis must not treat OTA platform hotel_id as system hotel id');
assert_true(!str_contains($applyHistoryHotelFilter, "whereOr('hotel_id'"), 'history hotel filter must not OR system hotel id with OTA hotel_id');
assert_true(!str_contains($applyCtripHotelScope, "whereOr('hotel_id'"), 'Ctrip hotel scope must not OR system hotel id with OTA hotel_id');
assert_true(str_contains($saveMeituanCommentConfig, '$this->checkPermission();') && str_contains($saveMeituanCommentConfig, 'return $this->error('), 'Meituan comment Cookie/API config must remain explicitly disabled');
assert_true(str_contains($saveCtripCommentConfig, "checkActionPermission('can_fetch_online_data')"), 'Ctrip comment config save must require online data fetch permission');
assert_true(!str_contains($saveMeituanCommentConfig, "saveOtaDataConfigValue('meituan-comments'"), 'Meituan comment Cookie/API config must not be persisted');
assert_true(str_contains($saveCtripCommentConfig, 'return $this->error(') && str_contains($saveCtripCommentConfig, 'Legacy Ctrip comment Cookie/API config storage is disabled.'), 'Ctrip comment Cookie/API config must remain explicitly disabled');
assert_true(!str_contains($saveCtripCommentConfig, "saveOtaDataConfigValue('ctrip-comments'") && !str_contains($saveCtripCommentConfig, "'cookies'") && !str_contains($saveCtripCommentConfig, "'spidertoken'"), 'Ctrip comment config must not persist reusable credential fields');
assert_true(str_contains($fetchCustom, "checkActionPermission('can_fetch_online_data')"), 'custom OTA fetch must require online data fetch permission');
assert_true(str_contains($fetchCustom, 'isAllowedOtaRequestUrl'), 'custom OTA fetch must restrict target hosts');
assert_true(str_contains($sendHttpRequest, 'isAllowedOtaRequestUrl'), 'Ctrip HTTP requests must restrict target hosts');
assert_true(str_contains($sendCtripJsonRequest, 'isAllowedOtaRequestUrl'), 'Ctrip JSON requests must restrict target hosts');
assert_true(str_contains($sendMeituanRequest, 'isAllowedOtaRequestUrl'), 'Meituan HTTP requests must restrict target hosts');
assert_true(str_contains($commandSource, 'normalizeScheduledCtripRequestUrl') && str_contains($commandSource, "'ebooking.ctrip.com'"), 'retained scheduled Ctrip URL normalization must restrict target hosts');
assert_true(str_contains($getMeituanCommentConfigList, 'return $this->success([]);') && !str_contains($getMeituanCommentConfigList, "readOtaDataConfigValue('meituan-comments')"), 'Meituan comment config list must expose no legacy Cookie/API config');
assert_true(str_contains($getCtripCommentConfigList, 'return $this->success([]);') && !str_contains($getCtripCommentConfigList, "readOtaDataConfigValue('ctrip-comments')"), 'Ctrip comment config list must expose no legacy Cookie/API config');
assert_true(str_contains($saveCookies, 'Legacy Cookie storage is disabled.') && !str_contains($saveCookies, 'setConfigList'), 'legacy Cookie save endpoint must not persist plaintext');
assert_true(str_contains($getCookiesList, 'return $this->success([]);') && !str_contains($getCookiesList, 'getConfigList'), 'legacy Cookie list endpoint must not read plaintext');
assert_true(str_contains($getCookiesDetail, 'Legacy Cookie detail access is disabled.') && !str_contains($getCookiesDetail, 'getConfigList'), 'legacy Cookie detail endpoint must never return plaintext');
assert_true(!str_contains($isOtaConfigVisible, "\$item['hotel_id']"), 'config visibility must not treat OTA platform hotel_id as system hotel id');
assert_true(str_contains($getCookieAlerts, 'sanitizeCookieAlertsForStorage($data)'), 'historical OTA credential alerts must be sanitized on every read');
assert_true(str_contains($deleteCookies, "checkActionPermission('can_delete_online_data')"), 'cookie deletion must require online data delete permission');
assert_true(str_contains($deleteCookies, 'Legacy Cookie deletion is disabled.') && !str_contains($deleteCookies, 'getConfigList'), 'legacy Cookie deletion must not parse generic Cookie storage');
assert_true(str_contains($batchDeleteCookies, 'Legacy Cookie batch deletion is disabled.') && !str_contains($batchDeleteCookies, 'getConfigList'), 'legacy Cookie batch deletion must not parse generic Cookie storage');
assert_true(str_contains($autoCaptureCtripCookie, '410') && !str_contains($autoCaptureCtripCookie, "request->header('cookie'"), 'legacy Ctrip auto-capture endpoint must not read browser Cookie headers');
assert_true(
    str_contains($saveCtripConfigByBookmark, '410')
    && str_contains($saveCtripConfigByBookmark, '$this->checkPermission();')
    && !str_contains($saveCtripConfigByBookmark, "file_get_contents('php://input')")
    && !str_contains($saveCtripConfigByBookmark, 'saveCtripConfigPayload('),
    'legacy Ctrip bookmark save endpoint must not ingest or persist Cookie payloads'
);
assert_true(str_contains($deleteMeituanConfig, "checkActionPermission('can_delete_online_data')"), 'Meituan config deletion must require online data delete permission');
assert_true(str_contains($deleteCtripConfig, "checkActionPermission('can_delete_online_data')"), 'Ctrip config deletion must require online data delete permission');
assert_true(!str_contains($legacyCronSource, "online_data_cookies_list"), 'legacy cron script must not use global cookie list');
assert_true(!str_contains($legacyCronSource, "whereNull('system_hotel_id')"), 'legacy cron script must not write unbound OTA data');
assert_true(!str_contains($legacyCronSource, "'system_hotel_id' => null"), 'legacy cron script must bind saved rows to a system hotel');
foreach ([
    'LLM client' => $llmClientSource,
    'API data source adapter' => $apiDataSourceAdapterSource,
    'AI config connectivity test' => $aiConfigSource,
    'Revenue research request' => $revenueResearchSource,
] as $label => $guardedTransportSource) {
    assert_true(
        str_contains($guardedTransportSource, "CURLOPT_PROXY => ''")
        && str_contains($guardedTransportSource, "CURLOPT_NOPROXY => '*'"),
        $label . ' must disable environment proxies so CURLOPT_RESOLVE remains authoritative'
    );
}

echo 'High-risk security verification passed.' . PHP_EOL;
