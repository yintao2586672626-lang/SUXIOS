<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use app\service\QuantSimulationService;

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

$authSource = file_get_contents(__DIR__ . '/../app/controller/Auth.php');
$baseSource = file_get_contents(__DIR__ . '/../app/controller/Base.php');
$competitorSource = file_get_contents(__DIR__ . '/../app/controller/CompetitorApi.php');
$onlineSource = file_get_contents(__DIR__ . '/../app/controller/OnlineData.php');
$userSource = file_get_contents(__DIR__ . '/../app/controller/User.php');
$hotelSource = file_get_contents(__DIR__ . '/../app/controller/Hotel.php');
$authMiddlewareSource = file_get_contents(__DIR__ . '/../app/middleware/Auth.php');
$compassViewSource = file_get_contents(__DIR__ . '/../app/view/admin/compass/index.html');

$logoutSource = extract_method_source_regression($authSource, 'logout');
$receiveCookiesSource = extract_method_source_regression($onlineSource, 'receiveCookies');
$corsErrorSource = extract_method_source_regression($onlineSource, 'corsError');
$corsSuccessSource = extract_method_source_regression($onlineSource, 'corsSuccess');
$bookmarkletSource = extract_method_source_regression($onlineSource, 'bookmarklet');
$ctripBookmarkletSource = extract_method_source_regression($onlineSource, 'generateCtripBookmarklet');
$cookieBookmarkletHelperSource = extract_method_source_regression($onlineSource, 'buildCookieBookmarkletScript');
$competitorReportSource = extract_method_source_regression($competitorSource, 'report');
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
