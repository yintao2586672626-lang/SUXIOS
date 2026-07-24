<?php
declare(strict_types=1);

use app\controller\Agent;
use app\model\Hotel;
use app\model\User;
use think\App;
use think\Request;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = new App(dirname(__DIR__));
$app->initialize();

$user = null;
foreach (User::where('status', 1)->order('id', 'asc')->limit(100)->select() as $candidate) {
    if ($candidate->isSuperAdmin()) {
        $user = $candidate;
        break;
    }
}
if (!$user) {
    throw new RuntimeException('Revenue Agent bundle smoke check requires one active super administrator.');
}

$hotelId = (int)Hotel::where('status', 1)->order('id', 'asc')->value('id');
if ($hotelId <= 0) {
    throw new RuntimeException('Revenue Agent bundle smoke check requires one active hotel.');
}

$today = date('Y-m-d');
$request = new class extends Request {
    public function isCli(): bool
    {
        return false;
    }
};
$request->setMethod('GET')
    ->setUrl('/api/agent/revenue-bundle')
    ->setBaseUrl('/api/agent/revenue-bundle')
    ->setPathinfo('api/agent/revenue-bundle')
    ->withGet([
        'hotel_id' => $hotelId,
        'start_date' => date('Y-m-d', strtotime('-7 days')),
        'end_date' => $today,
        'business_date' => $today,
        'date' => $today,
        'competitor_date' => $today,
        'page' => 1,
        'page_size' => 10,
    ])
    ->withHeader(['Accept' => 'application/json']);
$request->user = $user;
$app->instance('request', $request);

$response = (new Agent($app))->revenueBundle();
$payload = json_decode((string)$response->getContent(), true, 512, JSON_THROW_ON_ERROR);
if ($response->getCode() !== 200 || (int)($payload['code'] ?? 0) !== 200) {
    throw new RuntimeException('Revenue Agent bundle returned a non-success response.');
}

$data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
$requiredSections = [
    'overview',
    'analysis',
    'dashboard',
    'forecasts',
    'competitor',
    'room_types',
    'price_suggestions',
    'query_scope',
];
$missing = array_values(array_filter(
    $requiredSections,
    static fn(string $section): bool => !array_key_exists($section, $data)
));
if ($missing !== []) {
    throw new RuntimeException('Revenue Agent bundle is missing sections: ' . implode(', ', $missing));
}
if ((int)($data['query_scope']['hotel_id'] ?? 0) !== $hotelId
    || (string)($data['query_scope']['metric_scope'] ?? '') !== 'ota_channel') {
    throw new RuntimeException('Revenue Agent bundle query scope does not match the requested OTA hotel scope.');
}

echo json_encode([
    'status' => 'passed',
    'hotel_id' => $hotelId,
    'section_count' => count($requiredSections),
    'forecast_rows' => count((array)($data['forecasts']['forecasts'] ?? [])),
    'competitor_trend_groups' => count((array)($data['competitor']['trends'] ?? [])),
    'room_type_rows' => count((array)($data['room_types']['list'] ?? [])),
    'price_suggestion_rows' => count((array)($data['price_suggestions']['list'] ?? [])),
    'metric_scope' => $data['query_scope']['metric_scope'],
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
