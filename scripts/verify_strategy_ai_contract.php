<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
// A worktree can share vendor; bind app classes to this verifier's checkout.
foreach (spl_autoload_functions() ?: [] as $autoloadFunction) {
    $loader = is_array($autoloadFunction) ? ($autoloadFunction[0] ?? null) : null;
    if ($loader instanceof \Composer\Autoload\ClassLoader) {
        $loader->setPsr4('app\\', [dirname(__DIR__) . '/app']);
    }
}
require_once __DIR__ . '/../vendor/topthink/framework/src/helper.php';
new \think\App(dirname(__DIR__));

use app\controller\Base;
use app\controller\StrategySimulation;

function assert_strategy_ai_contract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$ref = new ReflectionClass(StrategySimulation::class);
assert_strategy_ai_contract(realpath((string)$ref->getFileName()) === realpath(__DIR__ . '/../app/controller/StrategySimulation.php'), 'strategy verifier must use this checkout');
foreach (['simulate' => [], 'createExecutionIntent' => [37], 'archive' => [37]] as $action => $arguments) {
    // No request, service, LLM, map client or DB state exists in this probe.
    $controller = $ref->newInstanceWithoutConstructor();
    assert_strategy_ai_contract($controller->$action(...$arguments)->getCode() === 401, $action . ': authentication must precede retirement');
    (new ReflectionProperty(Base::class, 'currentUser'))->setValue($controller, (object)['id' => 3]);
    $response = $controller->$action(...$arguments);
    assert_strategy_ai_contract($response->getCode() === 410, $action . ': new generation/execution/archive must be retired');
    assert_strategy_ai_contract(($response->getData()['data']['status'] ?? '') === 'retired_read_only', $action . ': retirement status must be explicit');
    assert_strategy_ai_contract(($response->getData()['data']['history_preserved'] ?? false) === true, $action . ': existing history must remain preserved');
}
foreach (['records', 'detail', 'formatRecord', 'applyTenantScope'] as $method) {
    assert_strategy_ai_contract($ref->hasMethod($method), 'strategy history boundary missing: ' . $method);
}
assert_strategy_ai_contract(!$ref->hasMethod('buildAiStrategyEvaluation') && !$ref->hasMethod('collectExternalData'), 'retired AI/POI generators must not remain executable');

// Read a fixed synthetic historical snapshot; do not regenerate or call a model.
$row = [
    'id' => 37, 'tenant_id' => 9,
    'input_json' => ['hotel_id' => 7, 'ota_target_date' => '2026-08-13'],
    'score_json' => ['total_score' => 0, 'decision_ready' => false, 'data_gaps' => ['market_evidence_missing']],
    'data_snapshot_json' => [
        'ai_data_available' => true, 'ai_data_used' => false,
        'external_data_available' => false, 'ai_search_used' => false,
        'status' => 'unverified', 'data_date' => '2026-08-13', 'source' => 'ctrip',
    ],
];
$controller = $ref->newInstanceWithoutConstructor();
$formatter = $ref->getMethod('formatRecord');
$detail = $formatter->invoke($controller, $row, true);
$list = $formatter->invoke($controller, $row, false);
assert_strategy_ai_contract($detail['data_snapshot'] === $row['data_snapshot_json'], 'historical AI/map/source/date flags must read back exactly');
assert_strategy_ai_contract($detail['total_score'] === 0 && $list['total_score'] === 0, 'observed historical zero must survive list/detail');
assert_strategy_ai_contract($detail['decision_ready'] === false, 'historical missing evidence must not become decision-ready');
assert_strategy_ai_contract($detail['input']['hotel_id'] === 7 && $detail['_execution_source_tenant_id'] === 9, 'historical hotel and tenant identities must remain exact');
$missing = $formatter->invoke($controller, ['id' => 38, 'score_json' => []], false);
assert_strategy_ai_contract($missing['total_score'] === null, 'missing historical score must not become zero');
echo 'Strategy retired-write and historical-truth contract verification passed.' . PHP_EOL;
