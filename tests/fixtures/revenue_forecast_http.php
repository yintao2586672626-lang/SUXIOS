<?php
declare(strict_types=1);

// Test-only router. A dedicated loopback PHP process supplies fake identity and temporary SQLite.
if (PHP_SAPI !== 'cli-server' || !getenv('SUXI_L05_FIXTURE_STATE')) { http_response_code(404); exit; }
$root = dirname(__DIR__, 2);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$files = ['/vue.js' => $root . '/node_modules/vue/dist/vue.runtime.global.prod.js',
    '/bridge.js' => $root . '/public/components/system/app-main-components-loader.js',
    '/components/system/app-main-components.js' => $root . '/public/components/system/app-main-components.js',
    '/components/revenue/forecast-decision-workbench.js' => $root . '/public/components/revenue/forecast-decision-workbench.js'];
if (isset($files[$path])) { header('Content-Type: application/javascript'); readfile($files[$path]); return; }
if ($path === '/') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>L05 synthetic 验收</title><style>body{margin:12px;background:#eef2ef;font-family:Arial,sans-serif}*{box-sizing:border-box}</style><body><p>隔离 synthetic 验收 · 无真实账号/经营数据</p><div id="app"></div><script src="/vue.js"></script><script src="/bridge.js"></script><script>const component=window.SUXI_APP_MAIN_COMPONENTS.create({Vue,h:Vue.h}).ForecastDecisionWorkbench;Vue.createApp({render(){return Vue.h(component,{hotelId:90001,hotels:[{id:90001,name:"Synthetic 验收门店"},{id:90002,name:"Synthetic 无权门店"}],request:async(url,options={})=>{const res=await fetch("/api"+url,{method:options.method||"GET",headers:{"Content-Type":"application/json"},body:options.body,credentials:"omit"});return res.json()}})}}).mount("#app")</script></body></html>';
    return;
}
require $root . '/tests/bootstrap.php';
$state = getenv('SUXI_L05_FIXTURE_STATE');
\think\facade\Config::set(['default' => 'file', 'stores' => ['file' => ['type' => 'File', 'path' => $state . '/cache/']]], 'cache');
\think\facade\Config::set(['default' => 'file', 'close' => true, 'channels' => ['file' => ['type' => 'File', 'path' => $state . '/logs/', 'close' => true]]], 'log');
\think\facade\Config::set(['default' => 'sqlite', 'connections' => ['sqlite' => ['type' => 'sqlite', 'database' => $state . '/fixture.sqlite', 'prefix' => '', 'fields_strict' => false]]], 'database');
\think\facade\Db::execute('CREATE TABLE IF NOT EXISTS hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL)');
\think\facade\Db::execute('INSERT OR IGNORE INTO hotels(id, tenant_id) VALUES (90001,9001)');
$method = $_SERVER['REQUEST_METHOD'];
$request = (new \think\Request())->setMethod($method)->withGet($_GET)->withPost(json_decode(file_get_contents('php://input'), true) ?: []);
$actor = $_SERVER['HTTP_X_SYNTHETIC_ACTOR'] ?? 'allowed';
$request->user = $actor === 'anonymous' ? null : new class($actor) {
    public function __construct(private string $actor) {}
    public function isSuperAdmin(): bool { return false; }
    public function getPermittedHotelIds(): array { return $this->actor === 'allowed' ? [90001] : [90002]; }
};
app()->instance('request', $request);
$controller = new \app\controller\RevenueForecastWorkbench(app(), new \app\service\RevenueForecastWorkbenchService($state . '/plans'));
$suffix = substr((string)$path, strlen('/api/revenue-ai/forecast-workbench/'));
$response = match (true) {
    $suffix === 'context' => $controller->context(),
    $suffix === 'preview' => $controller->preview(),
    $suffix === 'plans' && $method === 'POST' => $controller->save(),
    $suffix === 'plans' => $controller->history(),
    str_starts_with($suffix, 'plans/') => $controller->detail(substr($suffix, 6)),
    default => json(['code' => 404, 'message' => 'fixture route missing'], 404),
};
http_response_code($response->getCode()); header('Content-Type: application/json; charset=utf-8');
echo $response->getContent();
