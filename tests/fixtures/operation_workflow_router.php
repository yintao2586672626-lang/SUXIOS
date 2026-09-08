<?php
declare(strict_types=1);
// Isolated, synthetic-only UI/API harness. Never routed by the application.
require dirname(__DIR__) . '/bootstrap.php';
use Tests\Support\OperationTaskWorkflowFixture as Fixture;

$path = getenv('L06_SYNTHETIC_DB');
if (!$path || !str_contains(basename($path), 'l06-synthetic')) { http_response_code(500); exit('synthetic DB required'); }
Fixture::connect($path);
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === '--init') { Fixture::schema(); Fixture::seed(); exit; }
if (PHP_SAPI !== 'cli-server') exit('test server only');
$route = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($route === '/') {
    header('Content-Type: text/html; charset=utf-8');
    echo <<<'HTML'
<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>L06 synthetic 工作流验证</title><link rel="stylesheet" href="/tailwind.css"><link rel="stylesheet" href="/style.css"><body style="background:#f6f7f6;font-family:Microsoft YaHei,sans-serif"><main style="max-width:1120px;margin:24px auto;padding:12px"><p style="padding:12px;background:#fff3cd">SYNTHETIC 本地隔离样本；无真实账号、OTA/PMS、外发或审批。</p><div id="app"></div></main><script src="/vue.js"></script><script src="/components-loader.js"></script><script>
const request = async (url, options={}) => { const response=await fetch(url,{...options, headers:{'Content-Type':'application/json'}});return response.json(); };
const components=window.SUXI_APP_MAIN_COMPONENTS.create({Vue,h:Vue.h});
Vue.createApp({render(){return Vue.h(components.OperationTaskWorkflowPanel,{hotelId:7,request,canExecute:true});}}).mount('#app');
</script></body></html>
HTML;
    exit;
}
$assets = ['/vue.js' => 'public/vue.runtime.global.prod.js', '/components-loader.js' => 'public/components/system/app-main-components-loader.js', '/components/operations/task-workflow-panel.js' => 'public/components/operations/task-workflow-panel.js', '/style.css' => 'public/style.min.css', '/tailwind.css' => 'public/tailwind.min.css'];
if (isset($assets[$route])) { header('Content-Type: ' . (str_ends_with($route, '.css') ? 'text/css' : 'text/javascript')); readfile(dirname(__DIR__, 2) . '/' . $assets[$route]); exit; }
header('Content-Type: application/json; charset=utf-8');
try {
    $service = Fixture::service();
    if ($route === '/operation/task-workflows') $data = $service->listing([7], (int)($_GET['hotel_id'] ?? 0));
    elseif (preg_match('#^/operation/execution-tasks/(\d+)/workflow$#D', $route, $match)) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
            if (($input['hotel_id'] ?? 0) !== 7) throw new RuntimeException('酒店 not found', 404);
            $data = $service->mutate((int)$match[1], [7], $input, 3);
        } else $data = $service->read((int)$match[1], [7], isset($_GET['version']) ? (int)$_GET['version'] : null);
    } else throw new RuntimeException('not found', 404);
    echo json_encode(['code' => 200, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    $code = $e instanceof InvalidArgumentException ? 422 : (in_array($e->getCode(), [404,409,503], true) ? $e->getCode() : 500);
    http_response_code($code); echo json_encode(['code' => $code, 'message' => $e->getMessage()]);
}
