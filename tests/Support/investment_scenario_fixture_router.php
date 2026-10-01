<?php
declare(strict_types=1);

// Loopback-only acceptance harness. Its identities and SQLite data are explicitly synthetic.
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}
$fixturePath = getenv('INVESTMENT_SCENARIO_FIXTURE_DB') ?: '';
if ($fixturePath === '' || !str_starts_with(basename($fixturePath), 'investment-scenario-test-')
    || pathinfo($fixturePath, PATHINFO_EXTENSION) !== 'sqlite') {
    http_response_code(503);
    exit('Isolated synthetic fixture database required');
}
$repo = dirname(__DIR__, 2);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$assets = ['/vue.runtime.global.prod.js', '/tailwind.min.css', '/components/system/business-closure-loader.js', '/components/system/investment-scenario.min.js', '/components/system/investment-payback.min.js'];
if (in_array($path, $assets, true)) {
    header('Content-Type: ' . (str_ends_with($path, '.css') ? 'text/css' : 'text/javascript') . '; charset=utf-8');
    header('Cache-Control: no-store');
    readfile($repo . '/public' . $path);
    exit;
}
if ($path === '/') {
    header('Content-Type: text/html; charset=utf-8');
    echo <<<'HTML'
<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>投资测算 · 隔离合成验收</title><link rel="stylesheet" href="/tailwind.min.css"><style>body{margin:0;background:#f6f7f9;color:#17202d;font-family:system-ui,sans-serif}main{max-width:1400px;margin:auto;padding:20px;min-width:0}.btn-primary{background:#187a57;color:white;border-radius:8px}input,select,textarea{max-width:100%}button:disabled{opacity:.55}#fixture-banner{padding:12px 16px;background:#fff4d6;color:#744600;border:1px solid #eed290;border-radius:8px;margin-bottom:18px}@media(max-width:640px){main{padding:10px}}</style></head><body><main><p id="fixture-banner">隔离合成验收 · 合成酒店 / 合成项目 / 临时 SQLite。未连接业务数据库，未验证真实账号权限。</p><div id="app"></div></main><script src="/vue.runtime.global.prod.js"></script><script src="/components/system/business-closure-loader.js"></script><script>
const request = async (path, options = {}) => {
    const response = await fetch('/api' + path, {method: options.method || 'GET', headers: {'Content-Type':'application/json'}, body: options.body});
    return response.json();
};
Vue.createApp({render(){return Vue.h(window.SUXI_SYSTEM_COMPONENTS.InvestmentPaybackView,{request,hotels:[{id:80,name:'合成验收酒店'}]});}}).mount('#app');
</script></body></html>
HTML;
    exit;
}
require $repo . '/vendor/autoload.php';
require __DIR__ . '/InvestmentScenarioFixture.php';
use Tests\Support\InvestmentScenarioFixture as Fixture;
use think\facade\Db;
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    $new = !is_file($fixturePath);
    Fixture::connect($fixturePath);
    if ($new) {
        Fixture::schema();
        $seed = Fixture::ledger();
        $seed->saveProject(Fixture::project(['project_name' => '合成验收项目 A']));
        $seed->saveProject(Fixture::project(['project_name' => '合成空白项目 B', 'client_request_id' => 'scenario-fixture-project-b']));
    }
    $ledger = Fixture::ledger();
    $scenario = Fixture::scenarios();
    $method = $_SERVER['REQUEST_METHOD'];
    $raw = file_get_contents('php://input');
    $payload = $raw === '' ? [] : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) throw new InvalidArgumentException('对象参数 required');
    if ($path === '/api/investment-payback/projects') {
        $data = $method === 'GET' ? $ledger->projects($_GET) : ($method === 'POST' ? $ledger->saveProject($payload) : throw new RuntimeException('Method unavailable', 405));
    } elseif ($path === '/api/investment-payback/scenario/reference-example' && $method === 'GET') {
        $data = $scenario->referenceExample();
    } elseif (preg_match('#^/api/investment-payback/projects/(\d+)/scenario$#D', (string)$path, $match)) {
        $data = $method === 'GET' ? $scenario->detail((int)$match[1]) : ($method === 'POST' ? $scenario->save((int)$match[1], $payload) : throw new RuntimeException('Method unavailable', 405));
    } elseif (preg_match('#^/api/investment-payback/projects/(\d+)/scenario/preview$#D', (string)$path, $match) && $method === 'POST') {
        $data = $scenario->preview((int)$match[1], $payload);
    } elseif (preg_match('#^/api/investment-payback/projects/(\d+)$#D', (string)$path, $match) && $method === 'GET') {
        $data = $ledger->detail((int)$match[1], $_GET['as_of'] ?? null);
    } elseif (preg_match('#^/api/investment-payback/projects/(\d+)/archive$#D', (string)$path, $match) && $method === 'POST') {
        $data = $ledger->archive((int)$match[1], $payload);
    } else {
        throw new RuntimeException('Fixture route unavailable', 404);
    }
    echo json_encode(['code' => 200, 'message' => 'isolated synthetic fixture', 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    $status = $exception instanceof InvalidArgumentException || $exception instanceof JsonException ? 422 : (in_array($exception->getCode(), [401,403,404,405,409], true) ? $exception->getCode() : 500);
    http_response_code($status);
    echo json_encode(['code' => $status, 'message' => $status === 500 ? '隔离验收服务失败' : $exception->getMessage(), 'data' => null], JSON_UNESCAPED_UNICODE);
}
