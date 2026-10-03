<?php
declare(strict_types=1);

// Explicitly synthetic, loopback-only UI acceptance; never connect to the business database.
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) { http_response_code(404); exit; }
$fixturePath = getenv('DEEP_OPTIMIZATION_FIXTURE_DB') ?: '';
if (!str_starts_with(basename($fixturePath), 'deep-optimization-test-') || pathinfo($fixturePath, PATHINFO_EXTENSION) !== 'sqlite') { http_response_code(503); exit('Isolated synthetic SQLite required'); }
$repo = dirname(__DIR__, 2);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$assets = ['/vue.runtime.global.prod.js', '/tailwind.min.css', '/components/system/operating-economics-workbench.min.js', '/components/system/booking-monitoring-panel.js', '/components/system/operating-finance-control-center.min.js'];
header('Cache-Control: no-store');
if (in_array($path, $assets, true)) { header('Content-Type: ' . (str_ends_with($path, '.css') ? 'text/css' : 'text/javascript')); readfile($repo . '/public' . $path); exit; }
if ($path === '/' || $path === '/control-center') {
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>深度优化 · 隔离合成验收</title><link rel="stylesheet" href="/tailwind.min.css"></head><body class="bg-slate-50"><main class="mx-auto max-w-6xl p-4"><p class="mb-4 rounded border bg-amber-50 p-4">隔离合成验收：TEST-ONLY 酒店80，临时SQLite；未连接业务数据库，未验证真实账号权限。</p><div id="app"></div></main><script src="/vue.runtime.global.prod.js"></script><script src="/components/system/operating-economics-workbench.min.js"></script><script src="/components/system/booking-monitoring-panel.js"></script><script src="/components/system/operating-finance-control-center.min.js"></script><script>
const request = async (path, options = {}) => {
    const response = await fetch(path, {method: options.method || 'GET', headers:{'Content-Type':'application/json'}, body:options.body});
    return response.json();
};
const controlCenter = <?= $path === '/control-center' ? 'true' : 'false' ?>;
Vue.createApp({render(){return controlCenter
    ? Vue.h(window.SUXI_SYSTEM_COMPONENTS.OperatingFinanceControlCenterBody,{request,hotels:[{id:80,name:'TEST-ONLY酒店80'}],selectedHotelId:80,canExecute:true})
    : Vue.h('div',{class:'space-y-6'},[
    Vue.h(window.SUXI_SYSTEM_COMPONENTS.OperatingEconomicsWorkbench,{request,hotelId:80,periodMonth:'2026-09',platform:'ctrip',canExecute:true}),
    Vue.h(window.SUXI_SYSTEM_COMPONENTS.BookingMonitoringPanel,{request,hotels:[{id:80,name:'TEST-ONLY酒店80'}],selectedHotelId:80,canExecute:true})
]);}}).mount('#app');
</script></body></html>
    <?php exit;
}
require $repo . '/tests/bootstrap.php';
use app\service\BookingMonitoringService;
use app\service\ChannelEconomicsService;
use app\service\ConsumablesActualCostService;
use app\service\OperatingEvidenceSnapshotStore;
use think\App;
use think\facade\Config;
use think\facade\Db;
try {
    (new App($repo))->initialize();
    $fresh = !is_file($fixturePath);
    Config::set(['default'=>'sqlite','connections'=>['sqlite'=>['type'=>'sqlite','database'=>$fixturePath,'prefix'=>'','fields_strict'=>false]]], 'database');
    Db::connect(null, true);
    if ($fresh) {
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY,tenant_id INTEGER,name TEXT)');
        Db::execute("INSERT INTO hotels VALUES (80,7,'TEST-ONLY酒店80')");
        Db::execute('CREATE TABLE room_types (id INTEGER PRIMARY KEY,hotel_id INTEGER,name TEXT)');
        Db::execute("INSERT INTO room_types VALUES (1,80,'TEST-ONLY大床')");
        Db::execute('CREATE TABLE hotel_operating_evidence_snapshots (id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,hotel_id INTEGER,source_hotel_id INTEGER,kind TEXT,period_month TEXT,platform TEXT,payload_json TEXT,content_digest TEXT,idempotency_key TEXT,created_by INTEGER,created_at TEXT,UNIQUE(tenant_id,hotel_id,kind,period_month,platform,idempotency_key))');
        $columns='id INTEGER PRIMARY KEY AUTOINCREMENT,contract_version TEXT,tenant_id INTEGER,hotel_id INTEGER,source_hotel_id INTEGER,platform TEXT,fact_scope TEXT,stay_date TEXT,captured_at TEXT,source_method TEXT,source_ref_hash TEXT,on_books_room_nights REAL,on_books_room_revenue REAL,cumulative_cancel_room_nights REAL,gross_booking_room_nights REAL,quality_status TEXT,readback_verified INTEGER,idempotency_key TEXT,content_digest TEXT,created_by INTEGER,created_at TEXT';
        Db::execute('CREATE TABLE hotel_on_books_snapshots ('.$columns.')');
        Db::execute('CREATE TABLE hotel_room_type_on_books_snapshots ('.$columns.',room_type_id INTEGER,room_type_name TEXT,supersedes_snapshot_id INTEGER,UNIQUE(tenant_id,hotel_id,idempotency_key))');
    }
    $clock=static fn(): DateTimeImmutable => new DateTimeImmutable('2026-10-02 12:00:00', new DateTimeZone('Asia/Shanghai'));
    $monitor=new BookingMonitoringService($clock);
    $input=$_SERVER['REQUEST_METHOD']==='POST' ? json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR) : $_GET;
    if ($path === '/operating-finance/overview') {
        $data = ['contract_version'=>'operating_finance_control_center.v1','hotel_id'=>80,
            'boundaries'=>['external_write_count'=>0], 'settlement'=>['status'=>'missing'],
            'recovery'=>['status'=>'missing'],'booking_pace'=>['status'=>'missing'],
            'demand_calendar'=>['status'=>'missing'],'wecom_task_receipt'=>['status'=>'missing'],
            'monthly_finance'=>['status'=>'missing'],'portfolio'=>['status'=>'missing']];
    } elseif (str_starts_with($path,'/operating-finance/evidence/')) {
        $store=new OperatingEvidenceSnapshotStore();
        $scope=$store->scope(7,[80],(int)($input['hotel_id']??0),(string)($input['period_month']??''),(string)($input['platform']??''),(string)($input['kind']??''));
        $sources=['scope'=>array_diff_key($scope,['kind'=>true]),'period_closed'=>$scope['period_month']<'2026-10','marketing'=>['complete'=>false,'reason'=>'ctrip_marketing_period_requires_manual_evidence']];
        if (str_ends_with($path,'/overview')) $data=['scope'=>$scope,'history'=>$store->history($scope),'latest'=>$store->latest($scope),'sources'=>$sources];
        elseif (preg_match('~/snapshots/(\d+)$~',$path,$match)) $data=$store->read($scope,(int)$match[1]);
        else {
            $result=$scope['kind']==='channel_economics' ? (new ChannelEconomicsService())->calculate($input['inputs']??[],$sources) : (new ConsumablesActualCostService())->calculate($input['inputs']??[]);
            $payload=['inputs'=>$result['inputs'],'result'=>$result,'status'=>$result['status'],'source_quality'=>$result['source_quality']];
            $data=str_ends_with($path,'/snapshots') ? $store->save($scope,$payload,(string)($input['idempotency_key']??''),1) : ['scope'=>$scope]+$payload+['readback_verified'=>false];
        }
    } elseif ($path==='/booking-monitoring/overview') {
        $data=$monitor->overview(7,[80],array_map('intval',explode(',',(string)($input['hotel_ids']??''))),$input);
    } elseif ($path==='/booking-monitoring/snapshots' && $_SERVER['REQUEST_METHOD']==='POST') $data=$monitor->saveSnapshots(7,[80],$input['rows']??[],1);
    elseif (preg_match('~/booking-monitoring/snapshots/(\d+)$~',$path,$match)) $data=$monitor->readSnapshot(7,[80],(int)($input['hotel_id']??0),(int)$match[1]);
    else { http_response_code(404); exit; }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code'=>200,'message'=>'TEST-ONLY synthetic acceptance','data'=>$data],JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code(422);header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code'=>422,'message'=>$error->getMessage(),'data'=>null],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}
