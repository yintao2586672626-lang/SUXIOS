<?php
declare(strict_types=1);
// Loopback-only synthetic acceptance harness; never connects to the business database.
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '',['127.0.0.1','::1'],true)) { http_response_code(404); exit; }
$fixturePath = getenv('INVESTMENT_SCENARIO_FIXTURE_DB') ?: '';
if (!str_starts_with(basename($fixturePath),'investment-scenario-test-') || pathinfo($fixturePath,PATHINFO_EXTENSION) !== 'sqlite') { http_response_code(503); exit('Isolated fixture database required'); }
$repo = dirname(__DIR__,2); $path = parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if (str_starts_with((string)$path,'/api/investment-payback/')) { require __DIR__.'/investment_scenario_fixture_router.php'; exit; }
$assets = ['/vue.runtime.global.prod.js','/tailwind.min.css','/components/system/business-closure-loader.js','/components/system/investment-scenario.min.js','/components/system/investment-payback.min.js','/components/system/operating-economics-workbench.min.js','/components/system/booking-monitoring-panel.js'];
if (in_array($path,$assets,true)) { header('Content-Type: '.(str_ends_with($path,'.css')?'text/css':'text/javascript').'; charset=utf-8'); header('Cache-Control: no-store'); readfile($repo.'/public'.$path); exit; }
if ($path === '/') {
    header('Content-Type: text/html; charset=utf-8');
    echo <<<'HTML'
<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>系统增强 · 隔离合成验收</title><link rel="stylesheet" href="/tailwind.min.css"><style>body{margin:0;background:#f6f7f9;color:#17202d;font-family:system-ui,sans-serif}main{max-width:1280px;margin:auto;padding:20px;min-width:0}.btn-primary{background:#187a57;color:white;border-radius:8px}button:disabled{opacity:.55}button,input,select{min-height:36px}#fixture-banner{padding:12px;background:#fff4d6;margin-bottom:18px}nav{display:flex;gap:12px;margin-bottom:18px}nav button{border:1px solid #bbb;padding:8px}@media(max-width:640px){main{padding:10px}}</style></head><body><main><p id="fixture-banner">隔离合成验收 · 合成酒店80 / 合成项目 / 临时SQLite。未连接业务数据库，未验证真实账号权限。</p><div id="app"></div></main><script src="/vue.runtime.global.prod.js"></script><script src="/components/system/business-closure-loader.js"></script><script src="/components/system/operating-economics-workbench.min.js"></script><script src="/components/system/booking-monitoring-panel.js"></script><script>
const request=async(path,options={})=>(await fetch('/api'+path,{method:options.method||'GET',headers:{'Content-Type':'application/json'},body:options.body})).json();
Vue.createApp({data:()=>({page:'economics'}),render(){const c=window.SUXI_SYSTEM_COMPONENTS;return Vue.h('div',[Vue.h('nav',[['economics','经营核算'],['booking','预订监控'],['investment','投资项目']].map(([key,title])=>Vue.h('button',{onClick:()=>this.page=key},title))),this.page==='economics'?Vue.h(c.OperatingEconomicsWorkbench,{request,hotelId:80,periodMonth:'2026-10',platform:'ctrip',canExecute:true}):this.page==='booking'?Vue.h(c.BookingMonitoringPanel,{request,hotels:[{id:80,name:'合成验收酒店'}],selectedHotelId:80,canExecute:true}):Vue.h(c.InvestmentPaybackView,{request,hotels:[{id:80,name:'合成验收酒店'}]})]);}}).mount('#app');
</script></body></html>
HTML;
    exit;
}
require $repo.'/vendor/autoload.php'; require __DIR__.'/InvestmentScenarioFixture.php';
use Tests\Support\InvestmentScenarioFixture as Fixture;
use think\facade\Db;
use app\service\OperatingEvidenceSnapshotStore as Store;
use app\service\ConsumablesActualCostService;
use app\service\ChannelEconomicsService;
use app\service\BookingMonitoringService;
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
try {
    $new = !is_file($fixturePath); Fixture::connect($fixturePath);
    if ($new) {
        Fixture::schema(); Db::execute('ALTER TABLE hotels ADD COLUMN name TEXT'); Db::execute("UPDATE hotels SET name='合成验收酒店'");
        Fixture::ledger()->saveProject(Fixture::project(['project_name'=>'合成验收项目 A']));
        Fixture::ledger()->saveProject(Fixture::project(['project_name'=>'合成空白项目 B','client_request_id'=>'scenario-fixture-project-b']));
        Db::execute('CREATE TABLE hotel_operating_evidence_snapshots (id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,hotel_id INTEGER,source_hotel_id INTEGER,kind TEXT,period_month TEXT,platform TEXT,payload_json TEXT,content_digest TEXT,idempotency_key TEXT,created_by INTEGER,created_at TEXT,UNIQUE(tenant_id,hotel_id,kind,period_month,platform,idempotency_key))');
        Db::execute('CREATE TABLE room_types (id INTEGER PRIMARY KEY,hotel_id INTEGER,name TEXT)'); Db::execute("INSERT INTO room_types VALUES (1,80,'合成大床房')");
        $shared = 'id INTEGER PRIMARY KEY AUTOINCREMENT,contract_version TEXT,tenant_id INTEGER,hotel_id INTEGER,source_hotel_id INTEGER,platform TEXT,fact_scope TEXT,stay_date TEXT,captured_at TEXT,source_method TEXT,source_ref_hash TEXT,on_books_room_nights REAL,on_books_room_revenue REAL,cumulative_cancel_room_nights REAL,gross_booking_room_nights REAL,quality_status TEXT,readback_verified INTEGER,idempotency_key TEXT,content_digest TEXT,created_by INTEGER,created_at TEXT';
        Db::execute('CREATE TABLE hotel_on_books_snapshots ('.$shared.',UNIQUE(tenant_id,hotel_id,platform,stay_date,idempotency_key))');
        Db::execute('CREATE TABLE hotel_room_type_on_books_snapshots ('.$shared.',room_type_id INTEGER,room_type_name TEXT,supersedes_snapshot_id INTEGER,UNIQUE(tenant_id,hotel_id,idempotency_key))');
    }
    $payload = json_decode(file_get_contents('php://input') ?: '{}',true,512,JSON_THROW_ON_ERROR); $method = $_SERVER['REQUEST_METHOD'];
    if (str_starts_with((string)$path,'/api/operating-finance/evidence/')) {
        $p = $method==='GET' ? $_GET : $payload; $store = new Store();
        $scope = $store->scope(10,[80],(int)($p['hotel_id'] ?? 0),(string)($p['period_month'] ?? ''),(string)($p['platform'] ?? ''),(string)($p['kind'] ?? ''));
        if ($path === '/api/operating-finance/evidence/overview' && $method==='GET') $data = ['scope'=>$scope,'latest'=>$store->latest($scope),'history'=>$store->history($scope),'sources'=>['status'=>'synthetic_no_platform_sources']];
        elseif (preg_match('#^/api/operating-finance/evidence/snapshots/(\d+)$#D',$path,$match) && $method==='GET') $data = $store->read($scope,(int)$match[1]);
        elseif (in_array($path,['/api/operating-finance/evidence/preview','/api/operating-finance/evidence/snapshots'],true) && $method==='POST') {
            if ($scope['kind']==='consumables_actual') foreach (($p['inputs']['items'] ?? []) as $item) if (($item['enabled'] ?? false)===true && !str_starts_with((string)($item['source_date'] ?? ''),$scope['period_month'].'-')) throw new InvalidArgumentException('来源日期须属于当前账月');
            $result = $scope['kind']==='consumables_actual' ? (new ConsumablesActualCostService())->calculate($p['inputs']) : (new ChannelEconomicsService())->calculate($p['inputs']);
            $data = ['inputs'=>$result['inputs'],'result'=>$result,'source_quality'=>$result['source_quality']];
            $data = str_ends_with($path,'/snapshots') ? $store->save($scope,$data,$p['idempotency_key'],7) : $data+['scope'=>$scope,'readback_verified'=>false];
        } else throw new RuntimeException('Fixture route unavailable',404);
    } elseif ($path==='/api/booking-monitoring/overview' && $method==='GET') $data=(new BookingMonitoringService())->overview(10,[80],array_map('intval',explode(',',(string)($_GET['hotel_ids'] ?? '80'))),$_GET);
    elseif ($path==='/api/booking-monitoring/snapshots' && $method==='POST') $data=(new BookingMonitoringService())->saveSnapshots(10,[80],$payload['rows'] ?? [],7);
    elseif (preg_match('#^/api/booking-monitoring/snapshots/(\d+)$#D',$path,$match) && $method==='GET') $data=(new BookingMonitoringService())->readSnapshot(10,[80],(int)($_GET['hotel_id'] ?? 0),(int)$match[1]);
    else throw new RuntimeException('Fixture route unavailable',404);
    echo json_encode(['code'=>200,'message'=>'isolated synthetic fixture','data'=>$data],JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
} catch (Throwable $e) { $status=$e instanceof InvalidArgumentException ? 422 : (in_array($e->getCode(),[403,404,409],true)?$e->getCode():500); http_response_code($status); echo json_encode(['code'=>$status,'message'=>$status===500?'隔离验收服务失败':$e->getMessage(),'data'=>null],JSON_UNESCAPED_UNICODE); }
