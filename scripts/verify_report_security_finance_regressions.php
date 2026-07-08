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
$cookieEndpointSource = file_get_contents(__DIR__ . '/../app/controller/concern/CookieEndpointConcern.php');
$onlineDataRequestSource = file_get_contents(__DIR__ . '/../app/controller/concern/OnlineDataRequestConcern.php');
$robotControllerSource = file_get_contents(__DIR__ . '/../app/controller/admin/CompetitorWechatRobotController.php');
$userSource = file_get_contents(__DIR__ . '/../app/controller/User.php');
$hotelSource = file_get_contents(__DIR__ . '/../app/controller/Hotel.php');
$authMiddlewareSource = file_get_contents(__DIR__ . '/../app/middleware/Auth.php');
$compassViewSource = file_get_contents(__DIR__ . '/../app/view/admin/compass/index.html');
$robotListViewSource = file_get_contents(__DIR__ . '/../app/view/admin/competitor_wechat_robot/index.html');
$robotAddViewSource = file_get_contents(__DIR__ . '/../app/view/admin/competitor_wechat_robot/add.html');
$robotEditViewSource = file_get_contents(__DIR__ . '/../app/view/admin/competitor_wechat_robot/edit.html');
$publicIndexSource = file_get_contents(__DIR__ . '/../public/index.html');
$routeSource = file_get_contents(__DIR__ . '/../route/app.php');

$logoutSource = extract_method_source_regression($authSource, 'logout');
$receiveCookiesSource = extract_method_source_regression($cookieEndpointSource, 'receiveCookies');
$corsErrorSource = extract_method_source_regression($cookieEndpointSource, 'corsError');
$corsSuccessSource = extract_method_source_regression($cookieEndpointSource, 'corsSuccess');
$bookmarkletSource = extract_method_source_regression($cookieEndpointSource, 'bookmarklet');
$ctripBookmarkletSource = extract_method_source_regression($onlineDataRequestSource, 'generateCtripBookmarklet');
$cookieBookmarkletHelperSource = extract_method_source_regression($cookieEndpointSource, 'buildCookieBookmarkletScript');
$competitorReportSource = extract_method_source_regression($competitorSource, 'report');
$robotIndexSource = extract_method_source_regression($robotControllerSource, 'index');
$robotApiIndexSource = extract_method_source_regression($robotControllerSource, 'apiIndex');
$robotApiDetailSource = extract_method_source_regression($robotControllerSource, 'apiDetail');
$robotWebhookNormalizeSource = extract_method_source_regression($robotControllerSource, 'normalizeRobotWebhook');
$userDeleteSource = extract_method_source_regression($userSource, 'delete');
$hotelDeleteSource = extract_method_source_regression($hotelSource, 'delete');

assert_regression(str_contains($baseSource, 'return json($result, $httpStatus)'), 'Base::error must pass HTTP status into json()');
assert_regression((bool)preg_match('/Bearer\s*\\\\s\+/', $logoutSource) || str_contains($logoutSource, 'extractTokenFromAuthorizationHeader'), 'logout must strip Bearer prefix before cache deletion');

assert_regression(!str_contains($competitorSource, 'DEV_FALLBACK_TOKEN'), 'competitor API must not keep a fixed fallback token');
assert_regression(!str_contains($competitorSource, 'isLocalOrDevEnvironment'), 'competitor API token validation must not depend on debug/local fallback');
assert_regression(str_contains($competitorReportSource, 'CompetitorHotel::where'), 'competitor report must validate the target competitor hotel');
assert_regression(str_contains($competitorReportSource, "where('store_id', \$storeId)"), 'competitor report must bind store_id to the configured competitor hotel');

assert_regression(!str_contains($authMiddlewareSource, "param('token'") && !str_contains($authMiddlewareSource, 'param("token"'), 'Auth middleware must not accept protected-route tokens from URL query parameters');
assert_regression(!str_contains($compassViewSource, 'save-layout?token='), 'compass layout save must not put token in URL query');
assert_regression(!str_contains($compassViewSource, "URLSearchParams(location.search).get('token')"), 'compass layout save must not read token from location.search');
assert_regression(!str_contains($publicIndexSource, 'competitor-wechat-robot?token='), 'competitor robot admin entry must not put token in URL query');
assert_regression(str_contains($robotListViewSource . $robotAddViewSource . $robotEditViewSource, 'htmlspecialchars'), 'competitor robot admin views must HTML-escape stored text fields');
assert_regression(!str_contains($robotListViewSource, "<?php echo \$item['name']; ?>"), 'competitor robot list must not echo stored robot names without escaping');
assert_regression(!str_contains($robotListViewSource, "<?php echo \$item['webhook']; ?>"), 'competitor robot list must not echo stored webhooks without escaping');
assert_regression(!str_contains($robotListViewSource . $robotAddViewSource . $robotEditViewSource, "<?php echo \$s['name']; ?>"), 'competitor robot store options must not echo store names without escaping');
assert_regression(!str_contains($robotEditViewSource, "<?php echo \$robot['name']; ?>"), 'competitor robot edit form must not echo robot names without escaping');
assert_regression(!str_contains($robotEditViewSource, "<?php echo \$robot['webhook']; ?>"), 'competitor robot edit form must not echo webhooks without escaping');
assert_regression(str_contains($robotControllerSource, 'formatRobotListRow') && str_contains($robotControllerSource, 'maskRobotWebhook'), 'competitor robot list API must mask stored webhook secrets');
assert_regression(str_contains($robotIndexSource, 'formatRobotListRow'), 'competitor robot legacy list must use the masked list row formatter');
assert_regression(str_contains($robotApiIndexSource, 'formatRobotListRow'), 'competitor robot API list must use the masked list row formatter');
assert_regression(!str_contains($robotApiIndexSource, '$list = $query->page($pagination[\'page\'], $pagination[\'page_size\'])->select()->toArray();'), 'competitor robot API list must not directly paginate raw database rows');
assert_regression(str_contains($robotApiDetailSource, 'formatRobotDetailRow'), 'competitor robot detail API must isolate full webhook return to explicit detail requests');
assert_regression(str_contains($routeSource, "Route::get('/detail/:id', 'admin.CompetitorWechatRobotController/apiDetail')"), 'competitor robot detail API route must be explicit');
assert_regression(str_contains($robotListViewSource, 'webhook_masked'), 'competitor robot legacy list must display masked webhook text');
assert_regression(!str_contains($publicIndexSource, 'competitorRobotForm.value = { ...item };'), 'competitor robot SPA edit form must not reuse masked list rows as editable secrets');
assert_regression(str_contains($publicIndexSource, '/admin/competitor-wechat-robot/detail/'), 'competitor robot SPA edit form must fetch full webhook only from the detail API');
assert_regression(str_contains($robotWebhookNormalizeSource, "isset(\$parts['user'])") && str_contains($robotWebhookNormalizeSource, "isset(\$parts['pass'])"), 'competitor robot webhook validation must reject URL userinfo credentials');
assert_regression(str_contains($robotWebhookNormalizeSource, "isset(\$parts['fragment'])"), 'competitor robot webhook validation must reject URL fragments');
assert_regression(str_contains($robotWebhookNormalizeSource, "(isset(\$parts['port']) && (int)\$parts['port'] !== 443)"), 'competitor robot webhook validation must reject non-standard ports');
assert_regression(str_contains($robotWebhookNormalizeSource, '!is_string($key)') && str_contains($robotWebhookNormalizeSource, 'trim($key) ==='), 'competitor robot webhook validation must require a non-empty string key');

assert_regression(!str_contains($receiveCookiesSource, "param('token'"), 'receiveCookies must not read auth token from URL parameters');
assert_regression(str_contains($receiveCookiesSource, "header('Access-Control-Allow-Headers: Content-Type, Authorization')"), 'receiveCookies CORS must allow Authorization header');
assert_regression(!str_contains($receiveCookiesSource, "Access-Control-Allow-Origin: *"), 'receiveCookies must not allow every origin');
assert_regression(!str_contains($corsErrorSource . $corsSuccessSource, "'Access-Control-Allow-Origin' => '*'"), 'receiveCookies response helpers must not return wildcard CORS');

assert_regression(!str_contains($bookmarkletSource, 'alert("bookmarklet")'), 'bookmarklet endpoint must not return placeholder alert script');
assert_regression(str_contains($bookmarkletSource . $cookieBookmarkletHelperSource, 'receive-cookies'), 'bookmarklet endpoint must post cookies to receive-cookies');
assert_regression(str_contains($bookmarkletSource . $cookieBookmarkletHelperSource, 'Authorization'), 'bookmarklet endpoint must send token in Authorization header');
assert_regression(!str_contains($ctripBookmarkletSource, 'javascript:test'), 'Ctrip bookmarklet endpoint must not return a test placeholder');
assert_regression(str_contains($ctripBookmarkletSource . $cookieBookmarkletHelperSource, 'receive-cookies'), 'Ctrip bookmarklet endpoint must return a working cookie submitter');

assert_regression(str_contains($userDeleteSource, 'ensureUserCanBeDeleted'), 'user deletion must run association protection before hard delete');
assert_regression(str_contains($hotelDeleteSource, 'ensureHotelCanBeDeleted'), 'hotel deletion must run association protection before hard delete');

echo 'Report, security, and finance regression verification passed.' . PHP_EOL;
