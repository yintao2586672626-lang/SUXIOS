<?php
declare(strict_types=1);

// Local acceptance harness only: isolated SQLite identities, never the application database or login state.
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}
$fixturePath = getenv('PAYBACK_IMPORT_FIXTURE_DB') ?: '';
if (!str_starts_with(basename($fixturePath), 'investment-scenario-test-import-') || pathinfo($fixturePath, PATHINFO_EXTENSION) !== 'sqlite') {
    http_response_code(503);
    exit('Isolated synthetic database required');
}
$repo = dirname(__DIR__, 2);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$assets = ['/vue.runtime.global.prod.js', '/tailwind.min.css', '/components/system/business-closure-loader.js', '/components/system/investment-scenario.min.js', '/components/system/investment-payback.min.js'];
if (in_array($path, $assets, true)) {
    header('Content-Type: ' . (str_ends_with($path, '.css') ? 'text/css' : 'application/javascript'));
    readfile($repo . '/public' . $path);
    exit;
}
if ($path === '/' || $path === '/index.html') {
    header('Content-Type: text/html; charset=utf-8');
    if (($_GET['narrow'] ?? null) === '1') {
        echo '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><title>320px 导入验收 · 合成数据</title><body style="margin:12px;width:320px;background:#eee;font-family:system-ui"><p style="width:320px;font-size:13px;margin:0 0 12px">320px 合成验收，不改变原项目页面视口</p><iframe title="320px导入验收" src="/?frame=1" style="width:320px;height:600px;border:1px solid #ccc"></iframe></body></html>';
        exit;
    }
    echo <<<'HTML'
<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>导入功能独立验收 · 合成数据</title><link rel="stylesheet" href="/tailwind.min.css"><body style="background:#f4f6f4;color:#1c3028"><div style="background:#fff8dc;border-bottom:1px solid #d9c895;padding:12px;font-size:13px">合成验收 · 独立 SQLite 账本 · 不连接真实项目、账号或财务数据</div><main id="app" style="max-width:1180px;margin:24px auto;padding:16px"></main><script src="/vue.runtime.global.prod.js"></script><script src="/components/system/business-closure-loader.js"></script><script>
const request=async(path,options={})=>{const response=await fetch('/api'+path,{...options,headers:{'Content-Type':'application/json'}});return await response.json();};
Vue.createApp({render(){return Vue.h(window.SUXI_SYSTEM_COMPONENTS.InvestmentPaybackView,{request,hotels:[{id:80,name:'合成验收酒店'}]});}}).mount('#app');
</script></body></html>
HTML;
    exit;
}
require $repo . '/vendor/autoload.php';
require_once __DIR__ . '/InvestmentScenarioFixture.php';
\Tests\Support\InvestmentScenarioFixture::connect($fixturePath);
use think\facade\Db;
if (Db::query("SELECT name FROM sqlite_master WHERE type='table' AND name='investment_payback_projects'") === []) {
    \Tests\Support\InvestmentScenarioFixture::schema();
    Db::execute('CREATE TABLE system_config (id INTEGER PRIMARY KEY AUTOINCREMENT, config_key TEXT NOT NULL UNIQUE, config_value TEXT NULL, description TEXT NOT NULL DEFAULT "", create_time INTEGER NULL, update_time INTEGER NULL)');
    \Tests\Support\InvestmentScenarioFixture::ledger()->saveProject(\Tests\Support\InvestmentScenarioFixture::project(['project_name' => '合成导入目标项目', 'client_request_id' => 'import-fixture-seed']));
    if (getenv('PAYBACK_UI_FIXTURE') === '1') {
        $seedLedger = \Tests\Support\InvestmentScenarioFixture::ledger();
        $seed = $seedLedger->saveProject(\Tests\Support\InvestmentScenarioFixture::project(['project_name' => '合成回本展示项目', 'client_request_id' => 'payback-ui-fixture-seed']));
        foreach ([['investment','520000.00','2026-04-01'],['recovery','81600.00','2026-07-09'],['recovery','50000.00','2026-08-03'],['recovery','50000.00','2026-08-04'],['recovery','50000.00','2026-08-05'],['recovery','70000.00','2026-08-12'],['recovery','40000.00','2026-08-25'],['recovery','30000.00','2026-09-01'],['recovery','30000.00','2026-09-29']] as $index => [$kind,$amount,$date]) {
            $seedLedger->saveEntry($seed['project']['id'], ['kind' => $kind, 'amount' => $amount, 'date' => $date, 'precision' => 'day', 'source' => '合成验收录入', 'notes' => '', 'client_request_id' => 'payback-ui-entry-' . $index, 'as_of' => '2026-10-01']);
        }
        $seedLedger->saveProject(\Tests\Support\InvestmentScenarioFixture::project(['project_name' => '合成已回本项目', 'client_request_id' => 'payback-ui-surplus', 'opening_as_of' => '2026-09-30', 'opening_invested' => '480000.00', 'opening_recovered' => '493490.00', 'opening_source' => '合成累计余额']));
    }
}
$ledger = \Tests\Support\InvestmentScenarioFixture::ledger();
$import = new \app\service\InvestmentPaybackImportService(\Tests\Support\InvestmentScenarioFixture::user(), $ledger);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = json_decode((string)file_get_contents('php://input'), true) ?: [];
header('Content-Type: application/json; charset=utf-8');
try {
    if ($path === '/api/investment-payback/import/preview' && $method === 'POST') $data = $import->preview($input);
    elseif ($path === '/api/investment-payback/import/confirm' && $method === 'POST') $data = $import->confirm($input);
    elseif ($path === '/api/investment-payback/projects' && $method === 'GET') $data = $ledger->projects($_GET);
    elseif ($path === '/api/investment-payback/projects' && $method === 'POST') $data = $ledger->saveProject($input);
    elseif ($path === '/api/investment-payback/layout' && $method === 'POST') $data = $ledger->saveLayout($input);
    elseif (preg_match('#^/api/investment-payback/projects/(\d+)/entries$#D', (string)$path, $matches) && $method === 'POST') $data = $ledger->saveEntry((int)$matches[1], $input);
    elseif (preg_match('#^/api/investment-payback/projects/(\d+)$#D', (string)$path, $matches) && $method === 'GET') $data = $ledger->detail((int)$matches[1], $_GET['as_of'] ?? null);
    else throw new RuntimeException('Synthetic route unavailable', 404);
    echo json_encode(['code' => 200, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    $code = $error instanceof InvalidArgumentException ? 422 : (in_array($error->getCode(), [401,403,404,409,422,503], true) ? $error->getCode() : 500);
    http_response_code($code);
    echo json_encode(['code' => $code, 'message' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
}
