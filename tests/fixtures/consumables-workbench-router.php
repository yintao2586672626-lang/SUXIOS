<?php
declare(strict_types=1);

// TEST ONLY: serve the actual compiled workbench with synthetic identities and isolated SQLite.
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__, 2);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-SUXI-Fixture: synthetic-test-only');
$assets = [
    '/vue.runtime.global.prod.js' => ['public/vue.runtime.global.prod.js', 'text/javascript'],
    '/tailwind.min.css' => ['public/tailwind.min.css', 'text/css'],
    '/components/system/investment-scenario.min.js' => ['public/components/system/investment-scenario.min.js', 'text/javascript'],
];
if (isset($assets[$path]) && $method === 'GET') {
    [$relative, $type] = $assets[$path];
    header('Content-Type: ' . $type . '; charset=utf-8');
    readfile($root . '/' . $relative);
    exit;
}
if (in_array($path, ['/', '/index.html'], true) && $method === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    $page = getenv('CONSUMABLES_WORKBENCH_FIXTURE_VIEW') === 'procurement'
        ? 'output/validation/consumables-procurement-20261002/index.html'
        : 'output/validation/consumables-cost-20261001/index.html';
    readfile($root . '/' . $page);
    exit;
}
header('Content-Type: application/json; charset=utf-8');
try {
    $allowed = ($path === '/_fixture' && $method === 'GET')
        || (preg_match('#^/api/investment-payback/projects/[1-9]\d*/scenario/consumables-reference$#D', (string)$path) && $method === 'GET')
        || (preg_match('#^/api/investment-payback/projects/[1-9]\d*/scenario$#D', (string)$path) && in_array($method, ['GET', 'POST'], true))
        || (preg_match('#^/api/investment-payback/projects/[1-9]\d*/scenario/preview$#D', (string)$path) && $method === 'POST');
    if (!$allowed) {
        throw new RuntimeException('测试夹具未开放此路由', 404);
    }
    $dbPath = getenv('CONSUMABLES_WORKBENCH_FIXTURE_DB') ?: '';
    $tempDir = realpath(sys_get_temp_dir());
    $dbDir = $dbPath === '' ? false : realpath(dirname($dbPath));
    if ($dbDir === false || $tempDir === false || strcasecmp($dbDir, $tempDir) !== 0
        || !str_starts_with(basename($dbPath), 'investment-scenario-test-browser-')
        || pathinfo($dbPath, PATHINFO_EXTENSION) !== 'sqlite') {
        throw new RuntimeException('必须使用临时目录中的隔离合成SQLite', 503);
    }
    require_once $root . '/vendor/autoload.php';
    require_once $root . '/tests/Support/InvestmentScenarioFixture.php';
    // The lock covers schema and seed creation before any request can observe a partial fixture.
    $lock = fopen($dbPath . '.init.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('合成夹具初始化锁不可用', 503);
    }
    try {
        \Tests\Support\InvestmentScenarioFixture::connect($dbPath);
        $connections = \think\facade\Config::get('database.connections');
        if (!is_array($connections) || array_keys($connections) !== ['investment_scenario_test']
            || ($connections['investment_scenario_test']['type'] ?? '') !== 'sqlite'
            || ($connections['investment_scenario_test']['database'] ?? '') !== $dbPath) {
            throw new RuntimeException('隔离数据库配置不一致', 503);
        }
        $tables = \think\facade\Db::query("SELECT name FROM sqlite_master WHERE type='table' AND name='investment_payback_projects'");
        if ($tables === []) {
            \think\facade\Db::transaction(static function (): void {
                \Tests\Support\InvestmentScenarioFixture::schema();
                $saved = \Tests\Support\InvestmentScenarioFixture::ledger()->saveProject(
                    \Tests\Support\InvestmentScenarioFixture::project([
                        'project_name' => '易耗品浏览器合成验收项目（非真实酒店）',
                        'investor_name' => '合成验收身份', 'client_request_id' => 'consumables-browser-synthetic-project',
                    ])
                );
                $cash = [];
                for ($year = 1; $year <= 5; ++$year) {
                    $cash[] = ['year' => $year, 'tax_cash' => 0, 'financing_net_cash' => 0, 'maintenance_capex' => 0,
                        'working_capital_change' => 0, 'deposit_refund' => 0, 'salvage_cash' => 0];
                }
                $input = [
                    'scenario_name' => '合成1间房测试假设', 'as_of' => '2026-10-01', 'currency' => 'CNY',
                    'rooms' => 1, 'leased_rooms' => 0, 'years' => 5, 'adr_first_year' => 10,
                    'occupancy_first_year' => 1, 'occupancy_mature' => 1, 'mature_from_year' => 1,
                    'adr_growth_rate' => 0, 'adr_growth_from_year' => 2, 'operating_cost_basis' => 'occupied_room_night',
                    'operating_cost_per_night' => 7, 'fixed_annual_operating_cost' => null, 'operating_cost_growth_rate' => 0,
                    'monthly_rent_per_room' => 0, 'rent_free_months' => 0, 'construction_months' => 0,
                    'renovation_cash' => 0, 'franchise_cash' => 0, 'refundable_deposit_cash' => 0,
                    'other_initial_cash' => 0, 'working_capital_cash' => 0, 'depreciable_amount' => 0, 'depreciation_years' => 5,
                    'management_fee_rate' => 0, 'days_per_year' => 1, 'rent_escalations' => [], 'cash_adjustments' => $cash,
                    'source_label' => '本地自编合成验收输入；零现金项是显式测试条件',
                    'source_ref' => 'test-only:consumables-workbench-browser', 'reference_example' => false,
                ];
                \Tests\Support\InvestmentScenarioFixture::scenarios()->save((int)$saved['project']['id'], [
                    'expected_version' => (int)$saved['project']['version'], 'scenario' => $input,
                ]);
            });
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    $ledger = \Tests\Support\InvestmentScenarioFixture::ledger();
    $scenario = \Tests\Support\InvestmentScenarioFixture::scenarios();
    if ($path === '/_fixture') {
        $detail = $ledger->detail(1);
        $data = [
            'project' => $detail['project'], 'scenario' => $scenario->detail(1),
            'fixture' => ['kind' => 'synthetic_test_only', 'database' => 'isolated_sqlite',
                'business_database_connected' => false, 'real_account_verified' => false,
                'component_sha256' => strtoupper((string)hash_file('sha256', $root . '/public/components/system/investment-scenario.min.js'))],
        ];
    } else {
        $raw = (string)file_get_contents('php://input');
        if (strlen($raw) > 1024 * 1024) {
            throw new InvalidArgumentException('合成测试请求过大');
        }
        $payload = $raw === '' ? [] : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new InvalidArgumentException('合成测试参数必须是对象');
        }
        preg_match('#^/api/investment-payback/projects/(\d+)/scenario(/preview|/consumables-reference)?$#D', (string)$path, $match);
        $projectId = (int)$match[1];
        if (($match[2] ?? '') === '/consumables-reference') {
            $data = $scenario->consumablesReference($projectId);
        } elseif (($match[2] ?? '') === '/preview') {
            $data = $scenario->preview($projectId, $payload);
        } else {
            $data = $method === 'GET' ? $scenario->detail($projectId) : $scenario->save($projectId, $payload);
        }
    }
    echo json_encode(['code' => 200, 'message' => 'synthetic-test-only / isolated SQLite / no real hotel', 'data' => $data],
        JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    $status = $error instanceof InvalidArgumentException || $error instanceof JsonException ? 422
        : (in_array($error->getCode(), [403, 404, 405, 409, 503], true) ? $error->getCode() : 500);
    http_response_code($status);
    echo json_encode(['code' => $status, 'message' => $status === 500 ? '隔离合成验收失败' : $error->getMessage(), 'data' => null], JSON_UNESCAPED_UNICODE);
}
