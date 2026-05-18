<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use app\controller\StrategySimulation;

function fail_strategy_ai_contract(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assert_strategy_ai_contract(bool $condition, string $message): void
{
    if (!$condition) {
        fail_strategy_ai_contract($message);
    }
}

$source = (string)file_get_contents(__DIR__ . '/../app/controller/StrategySimulation.php');
$publicSource = (string)file_get_contents(__DIR__ . '/../public/index.html');

assert_strategy_ai_contract(
    str_contains($source, 'use app\\service\\LlmClient;'),
    'strategy simulation must use the configured LlmClient instead of only local rules'
);
assert_strategy_ai_contract(
    str_contains($source, 'buildAiStrategyEvaluation'),
    'strategy simulation must build an AI evaluation from the configured model'
);
assert_strategy_ai_contract(
    str_contains($source, "'ai_evaluation'"),
    'strategy recommendation payload must include ai_evaluation for save/detail echo'
);
assert_strategy_ai_contract(
    str_contains($source, "'ai_data_available'") && str_contains($source, "'ai_data_used'"),
    'strategy data snapshot must expose AI availability separately from map/POI external data'
);

$ref = new ReflectionClass(StrategySimulation::class);
assert_strategy_ai_contract($ref->hasMethod('buildDataSnapshot'), 'strategy data snapshot method is required');

$controller = $ref->newInstanceWithoutConstructor();
$method = $ref->getMethod('buildDataSnapshot');
$method->setAccessible(true);

$snapshot = $method->invokeArgs($controller, [
    [
        'data_sources' => ['daily_reports'],
        'missing_data' => [],
    ],
    [
        'available' => false,
        'used' => false,
        'reason' => 'missing_api_key',
        'freshness' => 'external_not_configured',
        'source_summary' => ['外部地图数据未接入，当前未使用 POI 推演'],
        'missing_data' => ['AMAP_KEY', 'BAIDU_MAP_KEY'],
        'ai_available' => true,
        'ai_used' => true,
        'ai_source_summary' => 'AI模型已接入：deepseek_chat',
        'ai_model_key' => 'deepseek_chat',
        'ai_error' => '',
    ],
]);

assert_strategy_ai_contract(($snapshot['external_data_available'] ?? true) === false, 'map/POI external status must keep its original meaning');
assert_strategy_ai_contract(($snapshot['ai_data_available'] ?? false) === true, 'AI availability must be shown when DeepSeek config exists');
assert_strategy_ai_contract(($snapshot['ai_data_used'] ?? false) === true, 'AI usage must be shown when the AI result is generated');
assert_strategy_ai_contract(in_array('AI模型已接入：deepseek_chat', $snapshot['source_summary'] ?? [], true), 'AI source summary must be included in data口径');

assert_strategy_ai_contract(
    str_contains($publicSource, 'strategyAiSourceLabel') && str_contains($publicSource, 'ai_data_used'),
    'frontend strategy result must render AI data status instead of only external map status'
);

echo 'Strategy AI contract verification passed.' . PHP_EOL;
