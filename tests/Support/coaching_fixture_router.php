<?php
declare(strict_types=1);
// An isolated test adapter. No credentials, sessions or business database access.
if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true) || !getenv('SUXI_COACHING_TEST_DB')) exit(1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/CoachingKnowledgeFixture.php';
use Tests\Support\CoachingKnowledgeFixture;
use app\service\ManagerCapabilityScoringService;
use app\service\KnowledgeReferenceService;
use app\service\KnowledgeSourceImportService;
use think\facade\Db;

$db = (string)getenv('SUXI_COACHING_TEST_DB');
if (pathinfo($db, PATHINFO_EXTENSION) !== 'sqlite' || !str_contains(basename($db), 'coaching-test-')) exit(1);
if (($argv[1] ?? '') === '--seed') {
    CoachingKnowledgeFixture::connect($db);
    CoachingKnowledgeFixture::createCase();
    Db::name('users')->insert(['id' => 10, 'tenant_id' => 10, 'hotel_id' => 20, 'role_id' => 2, 'status' => 1, 'username' => 'synthetic-second', 'realname' => '样例第二负责人']);
    $store = new KnowledgeSourceImportService();
    $raw = "先说明交接标准。\n负责人示范操作。\n独立演练后抽样核对。";
    $store->persist(['name' => '隔离样例：交接标准', 'source' => 'text', 'status' => 'done', 'description' => '合成测试来源', 'tags' => [],
        'hotel_id' => 20, 'created_by' => 7], ['raw_text' => $raw], $store->identity(20, 7, $raw, 'text', 'fixture', []));
    echo "synthetic_seed_ready\n";
    exit;
}
if (($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(403); exit; }
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$public = dirname(__DIR__, 2) . '/public/';
$allowed = ['vue.global.prod.js', 'tailwind.min.css', 'components/system/manager-coaching-panel.js', 'components/system/app-main-components.js'];
if (in_array(ltrim($path, '/'), $allowed, true)) {
    header('Content-Type: ' . (str_ends_with($path, '.css') ? 'text/css' : 'text/javascript') . '; charset=utf-8');
    readfile($public . ltrim($path, '/')); exit;
}
CoachingKnowledgeFixture::connect($db, false);
if ($path === '/' || $path === '/parent') {
    header('Content-Type: text/html; charset=utf-8');
    $cases = json_encode(Db::name('manager_capability_cases')->select()->toArray(), JSON_UNESCAPED_UNICODE);
    $panel = $path === '/parent'
        ? '<manager-capability-panel :hotel-id="hotel" :request="request"/>'
        : '<manager-coaching-panel :hotel-id="hotel" :manager-id="7" :cases="cases" :can-manage="true" :request="request"/>';
    echo '<!doctype html><html lang="zh"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="/tailwind.min.css"><style>body{font-family:system-ui;margin:0;background:#f5f7f6}#app{max-width:1000px;margin:auto;padding:16px}button,input,select{min-height:44px}*{box-sizing:border-box}</style>
    <body><div id="app"><p>隔离合成样例验收 · 未连接酒店业务数据库</p>' . $panel . '</div>
    <script src="/vue.global.prod.js"></script><script src="/components/system/manager-coaching-panel.js"></script><script src="/components/system/app-main-components.js"></script><script>
    window.fixtureApp=Vue.createApp({data:()=>({hotel:20,cases:' . $cases . '}),methods:{request:async(url,options={})=>(await fetch(url,{...options,headers:{"Content-Type":"application/json"}})).json()}});
    fixtureApp.component("ManagerCoachingPanel",SUXI_SYSTEM_COMPONENTS.ManagerCoachingPanel);
    fixtureApp.component("ManagerCapabilityPanel",SUXI_APP_MAIN_COMPONENTS_FULL.create({Vue,h:Vue.h}).ManagerCapabilityPanel);
    window.fixtureVm=fixtureApp.mount("#app");
    </script></body></html>'; exit;
}
header('Content-Type: application/json; charset=utf-8');
try {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    if (str_starts_with($path, '/operation/manager-capability/')) {
        // Real controller/permission code, with a declared synthetic identity supplied only by this test adapter.
        $reflection = new ReflectionClass(app\controller\ManagerCapability::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('service')->setValue($controller, new ManagerCapabilityScoringService());
        $reflection->getProperty('request')->setValue($controller, new class($input, $_GET) {
            public function __construct(private array $input, private array $query) {}
            public function post(): array { return $this->input; }
            public function param($key, $default = null): mixed { return $this->query[$key] ?? $default; }
            public function method(): string { return $_SERVER['REQUEST_METHOD']; }
            public function getContent(): string { return ''; }
        });
        $identity = new class {
            public int $id = 7;
            public int $tenant_id = 10;
            public function isSuperAdmin(): bool { return false; }
            public function getPermittedHotelIds(): array { return [20]; }
            public function hasHotelPermission($hotel, $capability): bool { return $hotel === 20 && !isset($_GET['read_only']); }
        };
        $reflection->getProperty('currentUser')->setValue($controller, isset($_GET['unauthenticated']) ? null : $identity);
        $readMethods = ['managers' => 'managers', 'profile' => 'profile', 'followup-queue' => 'followupQueue'];
        $readMethod = $readMethods[basename($path)] ?? null;
        if ($readMethod !== null) {
            echo json_encode($controller->$readMethod()->getData(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); exit;
        }
        preg_match('#/coaching(?:/(\d+))?(?:/([a-z]+))?$#', $path, $match);
        $result = !empty($match[2]) ? $controller->coachingAction((int)$match[1], $match[2])
            : (!empty($match[1]) ? $controller->coachingRead((int)$match[1])
            : ($_SERVER['REQUEST_METHOD'] === 'POST' ? $controller->coachingCreate() : $controller->coachingList()));
        echo json_encode($result->getData(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); exit;
    }
    $code = 200;
    if ($path === '/knowledge/references' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = (new KnowledgeReferenceService())->save((int)($input['hotel_id'] ?? 0), 7, $input);
    } elseif ($path === '/knowledge/list') {
        $code = 0;
        $data = ['list' => Db::name('knowledge_units')->where('hotel_id', (int)($_GET['hotel_id'] ?? 20))->select()->toArray()];
    } elseif (preg_match('#^/knowledge/reference-sources/(\d+)$#', $path, $m)) {
        $data = (new KnowledgeReferenceService())->source((int)$m[1], (int)($_GET['hotel_id'] ?? 0), 7);
    } elseif (preg_match('#^/knowledge/(\d+)$#', $path, $m)) {
        $code = 0;
        $data = ['unit' => Db::name('knowledge_units')->where('unit_id', (int)$m[1])->where('hotel_id', 20)->find(),
            'chunks' => Db::name('knowledge_chunks')->where('unit_id', (int)$m[1])->select()->toArray()];
        foreach ($data['chunks'] as &$chunk) $chunk['content'] = json_decode($chunk['content'], true);
    } else { http_response_code(404); throw new RuntimeException('未知测试路由'); }
    echo json_encode(['code' => $code, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $e) { echo json_encode(['code' => 422, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE); }
