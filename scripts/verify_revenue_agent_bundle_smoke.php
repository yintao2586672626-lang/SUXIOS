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
    || (string)($data['query_scope']['metric_scope'] ?? '') !== 'three_source_layered') {
    throw new RuntimeException('Revenue Agent bundle query scope does not match the requested three-source hotel scope.');
}
$analysisFactLayer = is_array($data['analysis']['fact_layer'] ?? null)
    ? $data['analysis']['fact_layer']
    : [];
$diagnostics = is_array($analysisFactLayer['analysis_diagnostics'] ?? null)
    ? $analysisFactLayer['analysis_diagnostics']
    : [];
if ((string)($diagnostics['contract_version'] ?? '')
        !== \app\service\RevenueAnalysisDiagnosticsService::CONTRACT_VERSION
    || (int)($diagnostics['scope']['system_hotel_id'] ?? 0) !== $hotelId
    || ($diagnostics['methodology']['external_connector_used'] ?? null) !== false
    || !is_array($diagnostics['checks'] ?? null)
    || !is_array($diagnostics['metric_diagnostics'] ?? null)
    || !is_array($diagnostics['issues'] ?? null)
) {
    throw new RuntimeException('Revenue Agent bundle diagnostics contract is missing or out of scope.');
}
$overviewDiagnostics = $data['overview']['three_source_fact_layer']['analysis_diagnostics'] ?? null;
if (!is_array($overviewDiagnostics)
    || (string)($overviewDiagnostics['contract_version'] ?? '')
        !== (string)$diagnostics['contract_version']
    || (string)($overviewDiagnostics['overall_assessment'] ?? '')
        !== (string)($diagnostics['overall_assessment'] ?? '')
) {
    throw new RuntimeException('Revenue Agent overview and analysis diagnostics are not aligned.');
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
    'diagnostic_assessment' => $diagnostics['overall_assessment'] ?? 'unknown',
    'diagnostic_verified_sources' => (int)(
        $diagnostics['evidence_summary']['readback_verified_source_count'] ?? 0
    ),
    'diagnostic_issue_count' => count((array)($diagnostics['issues'] ?? [])),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
