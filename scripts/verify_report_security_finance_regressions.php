<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use app\service\QuantSimulationService;
use app\controller\admin\CompetitorWechatRobotController;

function fail_regression(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assert_regression(bool $condition, string $message): void
{
    if (!$condition) {
        fail_regression($message);
    }
}

function assert_regression_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fail_regression($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

function assert_regression_float(float $expected, $actual, string $message): void
{
    $actualFloat = round((float)$actual, 4);
    if (abs($expected - $actualFloat) > 0.0001) {
        fail_regression($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actualFloat, true));
    }
}

function call_private_regression(object $object, string $method, array $args = [])
{
    $ref = new ReflectionObject($object);
    while (!$ref->hasMethod($method) && $ref->getParentClass()) {
        $ref = $ref->getParentClass();
    }
    $methodRef = $ref->getMethod($method);
    $methodRef->setAccessible(true);
    return $methodRef->invokeArgs($object, $args);
}

function extract_method_source_regression(string $source, string $methodName): string
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

$serviceRef = new ReflectionClass(QuantSimulationService::class);
$service = $serviceRef->newInstanceWithoutConstructor();
$result = call_private_regression($service, 'calculateSimulation', [[
    'roomCount' => 10,
    'decorationInvestment' => 0,
    'furnitureInvestment' => 0,
    'openingCost' => 0,
    'otherInvestment' => 0,
    'adr' => 100,
    'occupancyRate' => 50,
    'otherIncome' => 0,
    'monthlyRent' => 10000,
    'laborCost' => 0,
    'utilityCost' => 0,
    'otaCommissionRate' => 10,
    'consumableCost' => 0,
    'maintenanceCost' => 0,
    'otherFixedCost' => 0,
]]);
assert_regression_float(0.3704, $result['breakEvenOccupancy'], 'break-even occupancy must solve fixed cost against net room revenue after OTA commission');

$robotControllerRef = new ReflectionClass(CompetitorWechatRobotController::class);
$robotController = $robotControllerRef->newInstanceWithoutConstructor();
$validRobotWebhook = 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=abc123';
assert_regression_same($validRobotWebhook, call_private_regression($robotController, 'normalizeRobotWebhook', [$validRobotWebhook]), 'competitor robot webhook validator must accept Enterprise WeChat robot URLs');
$maskedRobotWebhook = call_private_regression($robotController, 'maskRobotWebhook', [$validRobotWebhook]);
assert_regression(!str_contains((string)$maskedRobotWebhook, 'abc123') && !str_contains((string)$maskedRobotWebhook, 'c123'), 'competitor robot webhook mask must not expose robot key characters');
assert_regression_same(null, call_private_regression($robotController, 'normalizeRobotWebhook', ['http://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=abc123']), 'competitor robot webhook validator must reject non-HTTPS URLs');
assert_regression_same(null, call_private_regression($robotController, 'normalizeRobotWebhook', ['https://qyapi.weixin.qq.com.evil.test/cgi-bin/webhook/send?key=abc123']), 'competitor robot webhook validator must reject lookalike hosts');
assert_regression_same(null, call_private_regression($robotController, 'normalizeRobotWebhook', ['https://qyapi.weixin.qq.com:8443/cgi-bin/webhook/send?key=abc123']), 'competitor robot webhook validator must reject non-standard ports');
assert_regression_same(null, call_private_regression($robotController, 'normalizeRobotWebhook', ['https://user:pass@qyapi.weixin.qq.com/cgi-bin/webhook/send?key=abc123']), 'competitor robot webhook validator must reject userinfo credentials');
assert_regression_same(null, call_private_regression($robotController, 'normalizeRobotWebhook', ['https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=abc123#fragment']), 'competitor robot webhook validator must reject URL fragments');
assert_regression_same(null, call_private_regression($robotController, 'normalizeRobotWebhook', ['https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key[]=abc123']), 'competitor robot webhook validator must reject non-scalar robot keys');
assert_regression_same(null, call_private_regression($robotController, 'normalizeRobotWebhook', ['https://qyapi.weixin.qq.com/cgi-bin/webhook/send']), 'competitor robot webhook validator must require a robot key');

$authSource = file_get_contents(__DIR__ . '/../app/controller/Auth.php');
$baseSource = file_get_contents(__DIR__ . '/../app/controller/Base.php');
$competitorSource = file_get_contents(__DIR__ . '/../app/controller/CompetitorApi.php');
$autoFetchSource = file_get_contents(__DIR__ . '/../app/controller/concern/AutoFetchConcern.php');
$cookieEndpointSource = file_get_contents(__DIR__ . '/../app/controller/concern/CookieEndpointConcern.php');
$onlineDataRequestSource = file_get_contents(__DIR__ . '/../app/controller/concern/OnlineDataRequestConcern.php');
$operationWorkbenchSource = file_get_contents(__DIR__ . '/../app/controller/concern/OperationWorkbenchConcern.php');
$robotControllerSource = file_get_contents(__DIR__ . '/../app/controller/admin/CompetitorWechatRobotController.php');
$systemConfigControllerSource = file_get_contents(__DIR__ . '/../app/controller/SystemConfigController.php');
$userSource = file_get_contents(__DIR__ . '/../app/controller/User.php');
$hotelSource = file_get_contents(__DIR__ . '/../app/controller/Hotel.php');
$authMiddlewareSource = file_get_contents(__DIR__ . '/../app/middleware/Auth.php');
$compassViewSource = file_get_contents(__DIR__ . '/../app/view/admin/compass/index.html');
$robotListViewSource = file_get_contents(__DIR__ . '/../app/view/admin/competitor_wechat_robot/index.html');
$robotAddViewSource = file_get_contents(__DIR__ . '/../app/view/admin/competitor_wechat_robot/add.html');
$robotEditViewSource = file_get_contents(__DIR__ . '/../app/view/admin/competitor_wechat_robot/edit.html');
$publicIndexSource = file_get_contents(__DIR__ . '/../public/index.html');
$routeSource = file_get_contents(__DIR__ . '/../route/app.php');
$ctripBrowserAdapterSource = file_get_contents(__DIR__ . '/../app/service/platform/CtripBrowserProfileDataSourceAdapter.php');
$meituanBrowserAdapterSource = file_get_contents(__DIR__ . '/../app/service/platform/MeituanBrowserProfileDataSourceAdapter.php');
$platformProfileCaptureSource = file_get_contents(__DIR__ . '/../app/controller/concern/PlatformProfileCaptureConcern.php');
$chromiumCookieExtractorSource = file_get_contents(__DIR__ . '/extract_chromium_cookie_header.php');

$logoutSource = extract_method_source_regression($authSource, 'logout');
$cronTriggerSource = extract_method_source_regression($autoFetchSource, 'cronTrigger');
$receiveCookiesSource = extract_method_source_regression($cookieEndpointSource, 'receiveCookies');
$corsErrorSource = extract_method_source_regression($cookieEndpointSource, 'corsError');
$bookmarkletSource = extract_method_source_regression($cookieEndpointSource, 'bookmarklet');
$ctripBookmarkletSource = extract_method_source_regression($onlineDataRequestSource, 'generateCtripBookmarklet');
$disabledCookieBookmarkletHelperSource = extract_method_source_regression($cookieEndpointSource, 'buildDisabledCookieBookmarkletScript');
$dailyPatrolCronSource = extract_method_source_regression($operationWorkbenchSource, 'dailyWorkbenchPatrolCron');
$competitorTaskSource = extract_method_source_regression($competitorSource, 'task');
$competitorReportSource = extract_method_source_regression($competitorSource, 'report');
$competitorReportTokenSource = extract_method_source_regression($competitorSource, 'isValidReportToken');
$competitorAuditSanitizerSource = extract_method_source_regression($competitorSource, 'sanitizeExternalAuditText');
$robotIndexSource = extract_method_source_regression($robotControllerSource, 'index');
$robotApiIndexSource = extract_method_source_regression($robotControllerSource, 'apiIndex');
$robotApiDetailSource = extract_method_source_regression($robotControllerSource, 'apiDetail');
$robotDetailFormatterSource = extract_method_source_regression($robotControllerSource, 'formatRobotDetailRow');
$robotWebhookNormalizeSource = extract_method_source_regression($robotControllerSource, 'normalizeRobotWebhook');
$robotPostJsonSource = extract_method_source_regression($robotControllerSource, 'postJson');
$userDeleteSource = extract_method_source_regression($userSource, 'delete');
$hotelDeleteSource = extract_method_source_regression($hotelSource, 'delete');

assert_regression(str_contains($baseSource, 'return json($result, $httpStatus)'), 'Base::error must pass HTTP status into json()');
assert_regression((bool)preg_match('/Bearer\s*\\\\s\+/', $logoutSource) || str_contains($logoutSource, 'extractTokenFromAuthorizationHeader'), 'logout must strip Bearer prefix before cache deletion');
assert_regression(str_contains($authSource, '$this->enforceRegistrationRateLimit()'), 'public self-registration must enforce a route-local rate limit before validation');
assert_regression(str_contains($authSource, "register_rate_") && str_contains($authSource, "\$ipHash = substr(sha1((string)\$this->request->ip()), 0, 16);"), 'public self-registration rate limit must be keyed by IP hash');
assert_regression(str_contains($authSource, "'register_rate_limited'"), 'rate-limited self-registration attempts must be audited');
assert_regression(!str_contains($authSource, "return \$this->error('用户名已存在', 409);"), 'public self-registration must not disclose username existence');

assert_regression(!str_contains($competitorSource, 'DEV_FALLBACK_TOKEN'), 'competitor API must not keep a fixed fallback token');
assert_regression(!str_contains($competitorSource, 'isLocalOrDevEnvironment'), 'competitor API token validation must not depend on debug/local fallback');
assert_regression(str_contains($competitorTaskSource, '$this->extractTaskToken()') && str_contains($competitorSource, "header('X-Task-Token', '')"), 'competitor task token must be read from X-Task-Token header only');
assert_regression(!str_contains($competitorTaskSource, "post('token'") && !str_contains($competitorTaskSource, 'post("token"'), 'competitor task token must not be accepted from request body');
assert_regression(str_contains($competitorReportSource, '$this->isValidReportToken($expectedToken)') && str_contains($competitorReportTokenSource, '$this->extractReportToken()') && str_contains($competitorSource, "header('X-Report-Token', '')"), 'competitor report token must be read from X-Report-Token header only');
assert_regression(!str_contains($competitorReportTokenSource, "post('report_token'") && !str_contains($competitorReportTokenSource, 'post("report_token"') && !str_contains($competitorReportTokenSource, "post('token'") && !str_contains($competitorReportTokenSource, 'post("token"'), 'competitor report_token must not be accepted from request body');
assert_regression(str_contains($competitorAuditSanitizerSource, 'Authorization') && str_contains($competitorAuditSanitizerSource, 'cookie|token|authorization'), 'competitor public endpoint audit text must redact credential-shaped values');
assert_regression(str_contains($competitorAuditSanitizerSource, '1[3-9]') && str_contains($competitorAuditSanitizerSource, '\\d{12,}'), 'competitor public endpoint audit text must mask phone numbers and long identifiers');
assert_regression(str_contains($competitorSource, "\$ipHash = substr(sha1((string)\$this->request->ip()), 0, 16);"), 'competitor public token APIs must rate limit pre-auth attempts by IP hash');
assert_regression(!str_contains($competitorSource, "\$identity . '|' . (string)\$this->request->ip()"), 'competitor public token APIs must not let request identity bypass pre-auth rate limits');
assert_regression(str_contains($competitorReportSource, 'CompetitorHotel::where'), 'competitor report must validate the target competitor hotel');
assert_regression(str_contains($competitorReportSource, "where('store_id', \$storeId)"), 'competitor report must bind store_id to the configured competitor hotel');
foreach ([
    'Ctrip browser Profile adapter' => $ctripBrowserAdapterSource,
    'Meituan browser Profile adapter' => $meituanBrowserAdapterSource,
    'Profile capture concern' => $platformProfileCaptureSource,
    'Chromium Cookie extractor' => $chromiumCookieExtractorSource,
] as $label => $source) {
    assert_regression(str_contains($source, 'chmod($path, 0600)'), $label . ' must restrict temporary Cookie file permissions after writing');
    assert_regression(str_contains($source, '@unlink($path)'), $label . ' must delete temporary Cookie files when permission hardening fails');
}

assert_regression(!str_contains($authMiddlewareSource, "param('token'") && !str_contains($authMiddlewareSource, 'param("token"'), 'Auth middleware must not accept protected-route tokens from URL query parameters');
assert_regression(str_contains($cronTriggerSource, "header('X-Cron-Token', '')"), 'cronTrigger must read cron token from X-Cron-Token header');
assert_regression(!str_contains($cronTriggerSource, "get('token'") && !str_contains($cronTriggerSource, 'get("token"'), 'cronTrigger must not accept cron token from URL query parameters');
assert_regression(str_contains($cronTriggerSource, 'hash_equals($configToken, $token)'), 'cronTrigger must compare cron token with hash_equals');
assert_regression(str_contains($dailyPatrolCronSource, "header('X-Cron-Token', '')"), 'daily workbench patrol cron must read cron token from X-Cron-Token header');
assert_regression(!str_contains($dailyPatrolCronSource, "get('token'") && !str_contains($dailyPatrolCronSource, 'get("token"'), 'daily workbench patrol cron must not accept cron token from URL query parameters');
assert_regression(str_contains($dailyPatrolCronSource, 'hash_equals($configToken, $token)'), 'daily workbench patrol cron must compare cron token with hash_equals');
assert_regression(str_contains($cookieEndpointSource, "'auth' => 'X-Cron-Token header only'") && !str_contains($cookieEndpointSource, 'X-Cron-Token or token query parameter'), 'public endpoint security panel must document cron auth as header-only');
assert_regression(str_contains($systemConfigControllerSource, 'containsRedactedExportSecretPlaceholder'), 'system config import must detect redacted export placeholders before writing configs');
assert_regression(str_contains($systemConfigControllerSource, 'skipped_redacted_values'), 'system config import must report redacted export placeholders skipped during import');
assert_regression(str_contains($publicIndexSource, 'skipped_redacted_values'), 'system config import UI must show skipped redacted placeholder count');
assert_regression(!str_contains($compassViewSource, 'save-layout?token='), 'compass layout save must not put token in URL query');
assert_regression(!str_contains($compassViewSource, "URLSearchParams(location.search).get('token')"), 'compass layout save must not read token from location.search');
assert_regression(str_contains($compassViewSource, '$escapeCompassValue') && str_contains($compassViewSource, 'htmlspecialchars'), 'compass admin view must HTML-escape runtime panel text');
assert_regression(str_contains($compassViewSource, '$encodeCompassJson') && str_contains($compassViewSource, 'JSON_HEX_TAG'), 'compass admin view must encode script JSON with hex escaping');
assert_regression(!str_contains($compassViewSource, 'json_encode($layout, JSON_UNESCAPED_UNICODE);'), 'compass layout JSON must not be embedded without script-context escaping');
assert_regression(!str_contains($compassViewSource, 'json_encode($metrics, JSON_UNESCAPED_UNICODE);'), 'compass metrics JSON must not be embedded without script-context escaping');
assert_regression(!str_contains($compassViewSource, 'item.innerHTML ='), 'compass layout list must not rebuild controls with innerHTML from layout keys');
assert_regression(str_contains($compassViewSource, 'label.textContent = labels[key] || key'), 'compass layout list labels must be rendered with textContent');
foreach ([
    "<?php echo \$item['date']; ?>",
    "<?php echo \$item['week']; ?>",
    "<?php echo \$item['condition']; ?>",
    "<?php echo \$item['wind']; ?>",
    "<?php echo \$todo['title']; ?>",
    "<?php echo \$todo['owner']; ?>",
    "<?php echo \$todo['deadline']; ?>",
    "<?php echo \$todo['status']; ?>",
    "<?php echo \$alert['type']; ?>",
    "<?php echo \$alert['message']; ?>",
    "<?php echo \$holiday['name']; ?>",
    "<?php echo \$day['date']; ?>",
    "<?php echo \$day['king']; ?>",
    "<?php echo \$day['twin']; ?>",
    "<?php echo \$day['total']; ?>",
] as $rawCompassEcho) {
    assert_regression(!str_contains($compassViewSource, $rawCompassEcho), 'compass admin view must not echo runtime panel text without escaping: ' . $rawCompassEcho);
}
assert_regression(!str_contains($publicIndexSource, 'competitor-wechat-robot?token='), 'competitor robot admin entry must not put token in URL query');
assert_regression(str_contains($robotListViewSource . $robotAddViewSource . $robotEditViewSource, 'htmlspecialchars'), 'competitor robot admin views must HTML-escape stored text fields');
assert_regression(!str_contains($robotListViewSource, "<?php echo \$item['name']; ?>"), 'competitor robot list must not echo stored robot names without escaping');
assert_regression(!str_contains($robotListViewSource, "<?php echo \$item['webhook']; ?>"), 'competitor robot list must not echo stored webhooks without escaping');
assert_regression(!str_contains($robotListViewSource . $robotAddViewSource . $robotEditViewSource, "<?php echo \$s['name']; ?>"), 'competitor robot store options must not echo store names without escaping');
assert_regression(!str_contains($robotEditViewSource, "<?php echo \$robot['name']; ?>"), 'competitor robot edit form must not echo robot names without escaping');
assert_regression(!str_contains($robotEditViewSource, "<?php echo \$robot['webhook']; ?>"), 'competitor robot edit form must not echo webhooks without escaping');
assert_regression(str_contains($robotEditViewSource, 'autocomplete="off"') && str_contains($robotEditViewSource, 'webhook_masked'), 'competitor robot edit form must not render full webhook secrets and must show only masked status');
assert_regression(str_contains($robotControllerSource, 'formatRobotListRow') && str_contains($robotControllerSource, 'maskRobotWebhook'), 'competitor robot list API must mask stored webhook secrets');
assert_regression(str_contains($robotIndexSource, 'formatRobotListRow'), 'competitor robot legacy list must use the masked list row formatter');
assert_regression(str_contains($robotApiIndexSource, 'formatRobotListRow'), 'competitor robot API list must use the masked list row formatter');
assert_regression(!str_contains($robotApiIndexSource, '$list = $query->page($pagination[\'page\'], $pagination[\'page_size\'])->select()->toArray();'), 'competitor robot API list must not directly paginate raw database rows');
assert_regression(str_contains($robotApiDetailSource, 'formatRobotDetailRow'), 'competitor robot detail API must use the detail formatter');
assert_regression(str_contains($routeSource, "Route::get('/detail/:id', 'admin.CompetitorWechatRobotController/apiDetail')"), 'competitor robot detail API route must be explicit');
assert_regression(str_contains($robotListViewSource, 'webhook_masked'), 'competitor robot legacy list must display masked webhook text');
assert_regression(str_contains($robotDetailFormatterSource, "\$row['webhook'] = '';") && str_contains($robotDetailFormatterSource, 'webhook_placeholder'), 'competitor robot detail formatter must not return full webhook secrets');
assert_regression(!str_contains($robotDetailFormatterSource, "\$row['webhook'] = (string)(\$robot['webhook'] ?? '')"), 'competitor robot detail formatter must not expose stored webhook secrets');
assert_regression(str_contains($robotControllerSource, 'resolveRobotWebhookForUpdate') && substr_count($robotControllerSource, 'resolveRobotWebhookForUpdate($data, $robot)') >= 2, 'competitor robot updates must preserve existing webhook when edit form leaves the secret blank');
assert_regression(!str_contains($publicIndexSource, 'competitorRobotForm.value = { ...item };'), 'competitor robot SPA edit form must not reuse masked list rows as editable secrets');
assert_regression(str_contains($publicIndexSource, '/admin/competitor-wechat-robot/detail/'), 'competitor robot SPA edit form must fetch an explicit detail row instead of reusing masked list rows');
assert_regression(str_contains($robotWebhookNormalizeSource, "isset(\$parts['user'])") && str_contains($robotWebhookNormalizeSource, "isset(\$parts['pass'])"), 'competitor robot webhook validation must reject URL userinfo credentials');
assert_regression(str_contains($robotWebhookNormalizeSource, "isset(\$parts['fragment'])"), 'competitor robot webhook validation must reject URL fragments');
assert_regression(str_contains($robotWebhookNormalizeSource, "(isset(\$parts['port']) && (int)\$parts['port'] !== 443)"), 'competitor robot webhook validation must reject non-standard ports');
assert_regression(str_contains($robotWebhookNormalizeSource, '!is_string($key)') && str_contains($robotWebhookNormalizeSource, 'trim($key) ==='), 'competitor robot webhook validation must require a non-empty string key');
assert_regression_same('企业微信 Webhook 请求失败，请检查网络或机器人配置', call_private_regression($robotController, 'robotWebhookRequestFailureMessage'), 'competitor robot webhook transport failures must use a generic safe message');
assert_regression(str_contains($robotPostJsonSource, 'robotWebhookRequestFailureMessage'), 'competitor robot webhook send failures must use the generic safe message helper');
assert_regression(!str_contains($robotPostJsonSource, 'error_get_last'), 'competitor robot webhook send failures must not return raw transport errors that may contain webhook keys');

assert_regression(!str_contains($receiveCookiesSource, "param('token'"), 'receiveCookies must not read auth token from URL parameters');
assert_regression(str_contains($receiveCookiesSource, "header('Access-Control-Allow-Headers: Content-Type, Authorization')"), 'receiveCookies CORS must allow Authorization header');
assert_regression(!str_contains($receiveCookiesSource, "Access-Control-Allow-Origin: *"), 'receiveCookies must not allow every origin');
assert_regression(!str_contains($corsErrorSource, "'Access-Control-Allow-Origin' => '*'"), 'receiveCookies error helper must not return wildcard CORS');

assert_regression(str_contains($receiveCookiesSource, 'legacy_bookmarklet_disabled'), 'receiveCookies must reject legacy cross-origin bookmarklet submissions');
assert_regression(!str_contains($receiveCookiesSource, "cache('token_'"), 'receiveCookies must not accept current login tokens from legacy bookmarklets');
assert_regression(str_contains($bookmarkletSource . $ctripBookmarkletSource . $disabledCookieBookmarkletHelperSource, 'disabled_by_policy'), 'bookmarklet endpoints must report disabled_by_policy');
assert_regression(!str_contains($bookmarkletSource . $ctripBookmarkletSource . $disabledCookieBookmarkletHelperSource, 'receive-cookies'), 'bookmarklet endpoints must not generate receive-cookies submitters');
assert_regression(!str_contains($bookmarkletSource . $ctripBookmarkletSource . $disabledCookieBookmarkletHelperSource, 'document.cookie'), 'bookmarklet endpoints must not read OTA document.cookie');
assert_regression(!str_contains($bookmarkletSource . $ctripBookmarkletSource . $disabledCookieBookmarkletHelperSource, 'Authorization'), 'bookmarklet endpoints must not embed the main login Authorization header');

assert_regression(str_contains($userDeleteSource, 'ensureUserCanBeDeleted'), 'user deletion must run association protection before hard delete');
assert_regression(str_contains($hotelDeleteSource, 'ensureHotelCanBeDeleted'), 'hotel deletion must run association protection before hard delete');

echo 'Report, security, and finance regression verification passed.' . PHP_EOL;
