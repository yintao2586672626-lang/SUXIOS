<?php
declare(strict_types=1);

// Loopback-only synthetic browser acceptance. No login state or business database is used.
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}
$fixturePath = getenv('AI_REPORT_REVIEW_FIXTURE_DB') ?: '';
$tempRoot = realpath(sys_get_temp_dir());
if (!str_starts_with(basename($fixturePath), 'ai-report-review-test-')
    || pathinfo($fixturePath, PATHINFO_EXTENSION) !== 'sqlite'
    || realpath(dirname($fixturePath)) !== $tempRoot) {
    http_response_code(503);
    exit('Temporary isolated fixture SQLite required');
}

$repo = dirname(__DIR__, 2);
$path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
header('Cache-Control: no-store');
header('X-SUXIOS-Fixture: synthetic-loopback-sqlite');
header('X-Content-Type-Options: nosniff');
$assets = ['/vue.global.prod.js', '/tailwind.min.css', '/components/system/ai-daily-report-delivery.js'];
if ($method === 'GET' && in_array($path, $assets, true)) {
    header('Content-Type: ' . (str_ends_with($path, '.css') ? 'text/css' : 'text/javascript') . '; charset=utf-8');
    readfile($repo . '/public' . $path);
    exit;
}

require $repo . '/vendor/autoload.php';

use app\service\AiDailyReportPresentationArtifactService;
use app\service\AiDailyReportPresentationReviewService;
use app\service\AiDailyReportPresentationSpecService;
use think\App;
use think\facade\Config;
use think\facade\Db;

function aiReviewFixtureReport(): array
{
    return [
        'id' => 9988, 'tenant_id' => 9903, 'hotel_id' => 9907, 'report_date' => '2026-10-02',
        'summary' => 'TEST-ONLY 隔离合成日报；仅用于页面操作验收，未连接携程、美团或PMS。',
        'result_contract' => ['result_version' => hash('sha256', 'synthetic-report-review-fixture-v1'),
            'metric_version' => 'ai_daily_report_metric.v1', 'reference_version' => hash('sha256', 'synthetic-reference'),
            'boundary' => 'TEST-ONLY 合成 OTA 渠道范围，非真实酒店事实'],
        'source_refs' => [['key' => 'synthetic-fixture#9907', 'platform' => 'ctrip', 'hotel_id' => 9907,
            'data_date' => '2026-10-02', 'quality_status' => 'synthetic', 'readback_verified' => false]],
        'result_layers' => ['source_facts' => [['key' => 'orders', 'label' => 'TEST-ONLY 合成订单来源未核验',
            'value' => null, 'unit' => '单', 'data_status' => 'unverified', 'metric_scope' => 'ota_channel']],
            'derived_metrics' => [], 'anomaly_signals' => [], 'ai_assistance' => [], 'human_judgments' => []],
        'recommended_actions' => [['title' => 'TEST-ONLY 复核演示包', 'action' => '仅合成验收建议，未授权经营执行', 'status' => 'pending_approval']],
        'data_gaps' => [['code' => 'synthetic_cost_missing', 'message' => 'TEST-ONLY 实际成本缺失，不计算全酒店利润']],
        'synthetic' => true,
    ];
}

function aiReviewFixtureConnect(string $repo, string $fixturePath): void
{
    $new = !is_file($fixturePath);
    $app = new App($repo);
    $app->setRuntimePath(sys_get_temp_dir() . DIRECTORY_SEPARATOR . basename($fixturePath, '.sqlite') . '-runtime' . DIRECTORY_SEPARATOR);
    $app->initialize();
    Config::set(['default' => 'ai_report_review_fixture', 'connections' => ['ai_report_review_fixture' => [
        'type' => 'sqlite', 'database' => $fixturePath, 'prefix' => '', 'fields_strict' => false,
    ]]], 'database');
    Db::connect(null, true);
    if (!$new) return;
    Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT NOT NULL)');
    Db::execute('CREATE TABLE fixture_reports (id INTEGER PRIMARY KEY, payload_json TEXT NOT NULL)');
    Db::execute('CREATE TABLE ai_report_presentation_specs (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER,
        hotel_id INTEGER, report_id INTEGER, audience TEXT, schema_version TEXT, adapter_version TEXT,
        source_result_version TEXT, spec_fingerprint TEXT, spec_json TEXT, data_status TEXT, render_status TEXT,
        created_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE(report_id,audience,adapter_version,spec_fingerprint))');
    Db::execute('CREATE TABLE ai_report_presentation_reviews (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER,
        hotel_id INTEGER, report_id INTEGER, presentation_spec_id INTEGER, spec_fingerprint TEXT, review_fingerprint TEXT,
        review_json TEXT, review_status TEXT, request_key TEXT, request_hash TEXT, created_by INTEGER,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE(tenant_id,hotel_id,presentation_spec_id,request_key))');
    Db::execute('CREATE TABLE ai_report_presentation_artifacts (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER,
        hotel_id INTEGER, report_id INTEGER, presentation_spec_id INTEGER, audience TEXT, format TEXT, renderer_version TEXT,
        spec_fingerprint TEXT, content_sha256 TEXT, content_bytes INTEGER, mime_type TEXT, artifact_filename TEXT,
        manifest_json TEXT, artifact_blob BLOB, render_status TEXT, created_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(presentation_spec_id,renderer_version))');
    Db::name('hotels')->insert(['id' => 9907, 'tenant_id' => 9903, 'name' => 'TEST-ONLY 隔离合成酒店']);
    Db::name('fixture_reports')->insert(['id' => 9988, 'payload_json' => json_encode(aiReviewFixtureReport(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
}

try {
    aiReviewFixtureConnect($repo, $fixturePath);
    $report = json_decode((string)Db::name('fixture_reports')->where('id', 9988)->value('payload_json'), true, 512, JSON_THROW_ON_ERROR);
    if ($path === '/' && $method === 'GET') {
        // The exact presentation section is read from the maintained production fragment.
        $source = file_get_contents($repo . '/resources/frontend/templates/fragments/16-page-ai-daily-report.html');
        $start = strpos($source, '<section data-testid="ai-daily-presentation-delivery"');
        $end = $start === false ? false : strpos($source, '<section class="rounded-lg border border-gray-100 bg-white p-4">', $start + 1);
        if ($start === false || $end === false) throw new RuntimeException('Maintained presentation template section unavailable');
        $template = substr($source, $start, $end - $start);
        header('Content-Type: text/html; charset=utf-8');
        echo <<<'HTML'
<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>报告逐项复核 · 隔离合成验收</title><link rel="stylesheet" href="/tailwind.min.css"><style>body{margin:0;background:#f6f7f9;color:#17202d;font-family:system-ui,sans-serif}main{max-width:1100px;margin:auto;padding:20px;min-width:0}#fixture-banner{padding:14px;background:#fff4d6;border:1px solid #e5bd5a;margin-bottom:16px;border-radius:8px}button:disabled{opacity:.45}button,input,select{min-height:36px}#fixture-toast{position:sticky;top:0;padding:10px;background:#e9f8ef;border:1px solid #78b98c;z-index:3;margin:10px 0}#fixture-runtime-error{background:#fee2e2;color:#991b1b;padding:10px}#fixture-meta{font-size:12px;color:#536173;margin:12px 0;overflow-wrap:anywhere}@media(max-width:640px){main{padding:10px}}</style></head><body><main><div id="fixture-banner"><strong>TEST-ONLY · 隔离合成页面验收</strong><p>租户9903 / 合成酒店9907 / 合成日报9988 / 业务日期2026-10-02 / 携程渠道样例 / 临时SQLite。未连接业务数据库、账号或平台。</p><p>复核选择仅验证交互与保存回读。即使标记“已逐项核对”，也不代表真实报告已经人工检查。</p></div><p id="fixture-meta">真实服务：AiDailyReportPresentationSpecService / AiDailyReportPresentationReviewService / AiDailyReportPresentationArtifactService；真实组件与模板直接读取当前工作树。</p><div id="fixture-toast" role="status" hidden></div><div id="fixture-runtime-error" role="alert" hidden></div><div id="app"></div>
HTML;
        echo '<template id="fixture-delivery-template">' . $template . '</template>';
        echo '<script id="fixture-report" type="application/json">' . json_encode($report, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR) . '</script>';
        echo <<<'HTML'
<script src="/vue.global.prod.js"></script><script src="/components/system/ai-daily-report-delivery.js"></script><script>
const showFixtureError = message => { const el=document.getElementById('fixture-runtime-error');el.hidden=false;el.textContent=message; };
window.addEventListener('error', event => showFixtureError(event.message));
window.addEventListener('unhandledrejection', event => showFixtureError(String(event.reason?.message || event.reason)));
const report=JSON.parse(document.getElementById('fixture-report').textContent);
const request=async(path,options={})=>(await fetch('/api'+path,{method:options.method||'GET',headers:{'Content-Type':'application/json'},body:options.body,credentials:'omit'})).json();
const ctx=Vue.reactive({aiDailyReport:report,selectedHotelId:9907,aiDailyReportDate:'2026-10-02',
    aiDailyReportDeliveryRequest:request,showToast(message,type){const el=document.getElementById('fixture-toast');el.hidden=false;el.textContent='合成验收 '+type+'：'+message;}});
const component={props:['ctx'],setup:window.SUXI_AI_DAILY_REPORT_DELIVERY.setup,template:document.getElementById('fixture-delivery-template').innerHTML};
const app=Vue.createApp({render(){return Vue.h(component,{ctx});}});
app.config.errorHandler=(error)=>showFixtureError(String(error?.message||error));
app.mount('#app');
</script></main></body></html>
HTML;
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    $input = $method === 'POST' ? json_decode(file_get_contents('php://input') ?: '{}', true, 512, JSON_THROW_ON_ERROR) : $_GET;
    if (!is_array($input)) throw new InvalidArgumentException('Fixture input must be an object');
    $audience = (string)($input['audience'] ?? 'owner');
    if (!in_array($audience, ['owner', 'expert', 'training'], true)) throw new InvalidArgumentException('Fixture audience invalid');
    $specs = new AiDailyReportPresentationSpecService();
    $reviews = new AiDailyReportPresentationReviewService();
    $artifacts = new AiDailyReportPresentationArtifactService();
    if ($path === '/api/fixture/probe' && $method === 'GET') {
        $stored = $specs->readLatest(9988, [9907], 9903, $audience);
        $review = $stored ? $reviews->readForSpec($stored, [9907]) : null;
        $data = ['synthetic' => true, 'scope' => ['tenant_id' => 9903, 'hotel_id' => 9907, 'report_id' => 9988,
            'business_date' => '2026-10-02', 'platform' => 'ctrip', 'audience' => $audience],
            'sqlite_basename' => basename($fixturePath), 'business_database_connected' => false,
            'report_seed_readback_verified' => $report === aiReviewFixtureReport(),
            'counts' => ['specs' => Db::name('ai_report_presentation_specs')->count(), 'reviews' => Db::name('ai_report_presentation_reviews')->count(),
                'artifacts' => Db::name('ai_report_presentation_artifacts')->count()],
            'review' => $review, 'external_write_count' => 0,
            'service_sources' => array_map(static fn(string $class): array => ['class' => $class,
                'sha256' => hash_file('sha256', (new ReflectionClass($class))->getFileName())], [$specs::class, $reviews::class, $artifacts::class])];
    } elseif (preg_match('#^/api/ai-daily-reports/9988/(presentation-spec|presentation-review|presentation-artifacts)(?:/(\d+))?$#D', $path, $match)) {
        $resource = $match[1];
        if ($resource === 'presentation-spec' && $method === 'POST') {
            header('X-SUXIOS-Fixture-Service: ' . $specs::class . '::saveAndReadback');
            $data = $specs->saveAndReadback($report, $audience, 9909);
        } else {
            $stored = $specs->readLatest(9988, [9907], 9903, $audience);
            if (!$stored) throw new RuntimeException('No saved synthetic spec', 404);
            if (isset($input['presentation_spec_id']) && (int)$input['presentation_spec_id'] !== $stored['record_id']) throw new RuntimeException('Fixture spec identity mismatch', 409);
            if (isset($input['expected_spec_fingerprint']) && !hash_equals($stored['spec_fingerprint'], (string)$input['expected_spec_fingerprint'])) throw new RuntimeException('Fixture spec fingerprint mismatch', 409);
            if ($resource === 'presentation-spec' && $method === 'GET') $data = $stored;
            elseif ($resource === 'presentation-review' && $method === 'GET') {
                header('X-SUXIOS-Fixture-Service: ' . $reviews::class . '::readForSpec');
                $data = $reviews->readForSpec($stored, [9907]);
            } elseif ($resource === 'presentation-review' && $method === 'POST') {
                header('X-SUXIOS-Fixture-Service: ' . $reviews::class . '::saveAndReadback');
                $data = $reviews->saveAndReadback($stored, [9907], $input, 9909);
            } elseif ($resource === 'presentation-artifacts' && $method === 'POST') {
                header('X-SUXIOS-Fixture-Service: ' . $artifacts::class . '::saveAndReadback');
                $data = $artifacts->saveAndReadback($stored, 9909, true, (string)($input['export_mode'] ?? 'draft'), (string)($input['expected_review_fingerprint'] ?? ''));
            } elseif ($resource === 'presentation-artifacts' && $method === 'GET') {
                header('X-SUXIOS-Fixture-Service: ' . $artifacts::class . (isset($match[2]) ? '::readExact' : '::readLatest'));
                $data = isset($match[2]) ? $artifacts->readExact(9988, (int)$match[2], [9907], 9903, false)
                    : $artifacts->readLatest(9988, [9907], 9903, $audience, false, $stored['record_id'], $stored['spec_fingerprint']);
                if (!$data) throw new RuntimeException('No generated synthetic artifact', 404);
            } else throw new RuntimeException('Fixture route unavailable', 404);
        }
    } else throw new RuntimeException('Fixture route unavailable', 404);
    echo json_encode(['code' => 200, 'message' => 'TEST-ONLY isolated synthetic fixture', 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    $status = $error instanceof InvalidArgumentException ? 422 : (in_array($error->getCode(), [403, 404, 409], true) ? $error->getCode() : 500);
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => $status, 'message' => $status === 500 ? 'Isolated synthetic fixture failed: ' . get_class($error) : $error->getMessage(), 'data' => null], JSON_UNESCAPED_UNICODE);
}
