<?php
declare(strict_types=1);

$root = dirname(__DIR__);

function read_file(string $relative): string
{
    global $root;
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (!is_file($path)) {
        throw new RuntimeException("Missing file: {$relative}");
    }
    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException("Cannot read file: {$relative}");
    }
    return $content;
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$checks = [];

$route = read_file('route/app.php');
foreach ([
    "Route::post('/update-data', 'OnlineData/updateData')",
    "Route::delete('/delete-data', 'OnlineData/deleteData')",
    "Route::get('/cookie-status', 'OnlineData/cookieStatus')",
    "Route::get('/cookie-warnings', 'Agent/cookieWarnings')",
    "Route::post('/price-suggestions/generate', 'Agent/generatePriceSuggestions')",
    "Route::post('/price-suggestions/:id/apply', 'Agent/applyPrice')",
    "Route::get('/price-suggestions/:id/review', 'Agent/priceSuggestionReview')",
    "Route::group('api/lifecycle'",
] as $needle) {
    assert_true(str_contains($route, $needle), "Route missing: {$needle}");
}
$checks[] = 'routes';

foreach ([
    'database/init_full.sql',
    'database/README_INIT.md',
    'scripts/build_hotelx_full_dump.ps1',
    'app/service/LlmClient.php',
    'app/service/ExternalSignalService.php',
    'app/controller/Lifecycle.php',
    'docs/revenue_agent_api.md',
    'docs/lifecycle_binding_example.md',
] as $file) {
    read_file($file);
}
$checks[] = 'artifacts';

$agent = read_file('app/controller/Agent.php');
assert_true(str_contains($agent, 'use app\\service\\LlmClient;'), 'Agent must import LlmClient');
assert_true(str_contains($agent, 'new LlmClient()'), 'Agent callLlm must use LlmClient');
assert_true(!str_contains($agent, 'getEnvLlmConfigByModelKey('), 'Agent must not fall back to env LLM config');
$checks[] = 'agent_llm';

$llmClient = read_file('app/service/LlmClient.php');
assert_true(!preg_match('/DEEPSEEK_API_KEY|OPENAI_API_KEY|DEEPSEEK_BASE_URL|OPENAI_BASE_URL/', $llmClient), 'LlmClient must not read provider API keys from env');
$checks[] = 'llm_client';

$feasibility = read_file('app/service/FeasibilityReportService.php');
assert_true(str_contains($feasibility, 'LlmClient $client'), 'FeasibilityReportService must use LlmClient');
assert_true(!str_contains($feasibility, 'OpenAIClient'), 'FeasibilityReportService must not depend on OpenAIClient');
$checks[] = 'feasibility_llm';

$ai = read_file('app/controller/Ai.php');
assert_true(str_contains($ai, 'FeasibilityReportService'), 'Ai feasibility must use FeasibilityReportService');
$checks[] = 'ai_controller';

$onlineData = read_file('app/controller/OnlineData.php');
assert_true(str_contains($onlineData, "\$this->request->param('id', 0)"), 'deleteData must accept id from DELETE params/body');
assert_true(str_contains($onlineData, 'function cookieStatus()'), 'OnlineData must expose cookieStatus');
$checks[] = 'online_data';

$macro = read_file('app/service/MacroSignalService.php');
assert_true(str_contains($macro, 'ExternalSignalService'), 'MacroSignalService must use ExternalSignalService');
assert_true(str_contains($macro, "source_text'] = 'AMap weather'"), 'Weather signal must expose external data source');
$checks[] = 'external_signal';

echo 'OK: ' . implode(', ', $checks) . PHP_EOL;
