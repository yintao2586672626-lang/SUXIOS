<?php
declare(strict_types=1);

// Standalone synthetic SQLite process. Never include tests/bootstrap.php or
// initialize think\App. Run with -n, allow_url_fopen=0 and disabled network/process
// functions as documented in the preflight report. No original DB restoration.
$options = getopt('', ['source-root:', 'runtime-root:', 'evidence:']);
$sourceRoot = realpath($options['source-root'] ?? getcwd());
$runtimeRoot = realpath($options['runtime-root'] ?? getcwd());
if ($sourceRoot === false || $runtimeRoot === false || ini_get('allow_url_fopen') !== '0') {
    throw new RuntimeException('Explicit existing source/runtime roots and allow_url_fopen=0 required');
}
foreach (['curl_exec', 'curl_multi_exec', 'fsockopen', 'pfsockopen', 'stream_socket_client', 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen'] as $function) {
    if (function_exists($function)) throw new RuntimeException('Network/process function must be disabled: ' . $function);
}
foreach (['http', 'https', 'ftp', 'ftps', 'phar'] as $wrapper) {
    if (in_array($wrapper, stream_get_wrappers(), true)) stream_wrapper_unregister($wrapper);
}
$evidence = ['mode' => 'synthetic_sqlite_memory_only', 'sources' => [], 'cases' => [], 'connections' => [], 'assertions' => 0];
register_shutdown_function(static function () use (&$evidence, $options): void {
    if (!array_key_exists('passed', $evidence)) {
        $evidence['passed'] = false;
        $evidence['bootstrap_failure'] = error_get_last()['message'] ?? 'Bootstrap did not finish';
        if (isset($options['evidence'])) file_put_contents($options['evidence'], json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    }
});
$allowedApp = [
    'app\\service\\QuantSimulationService', 'app\\service\\AiDecisionQualityService',
    'app\\service\\SimulationExecutionReadinessService', 'app\\service\\SimulationExecutionBridgeService',
    'app\\service\\SourceBackedExecutionIntentIdentityService', 'app\\service\\DatabaseSchemaRequirement',
    'app\\controller\\Simulation', 'app\\controller\\Base',
];
// The first loader rejects App/config/environment/LLM before Composer can load
// them and routes every allowed app class exclusively to the selected source root.
spl_autoload_register(static function (string $class) use ($sourceRoot, $allowedApp, &$evidence): void {
    if (in_array($class, ['think\\App', 'think\\Config', 'think\\Env', 'app\\service\\LlmClient'], true)) {
        throw new RuntimeException('Forbidden initialization dependency: ' . $class);
    }
    if (!str_starts_with($class, 'app\\')) return;
    if (!in_array($class, $allowedApp, true)) throw new RuntimeException('Unreviewed app dependency: ' . $class);
    $path = $sourceRoot . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
    if (!is_file($path)) throw new RuntimeException('Missing frozen app source: ' . $class);
    $evidence['sources'][] = ['path' => str_replace('\\', '/', $class) . '.php', 'resolved_path' => realpath($path), 'sha256' => strtoupper(hash_file('sha256', $path))];
    require_once $path;
}, true, true);
$loader = require $runtimeRoot . '/vendor/autoload.php';
// Composer registers itself with prepend=true; restore the barrier ahead of it.
$autoloaders = spl_autoload_functions();
foreach ($autoloaders as $autoload) if ($autoload instanceof Closure) {
    spl_autoload_unregister($autoload); spl_autoload_register($autoload, true, true);
}
require_once $runtimeRoot . '/vendor/topthink/framework/src/helper.php';

final class MemoryOnlyConnection extends \think\db\connector\Sqlite
{
    public function connect(array $config = [], $linkNum = 0, $autoConnection = false): PDO
    {
        $actual = array_merge($this->config, $config);
        if (($actual['type'] ?? '') !== 'sqlite' || ($actual['database'] ?? '') !== ':memory:'
            || !empty($actual['dsn']) || !empty($actual['hostname']) || !empty($actual['username'])
            || !empty($actual['password']) || !empty($actual['deploy']) || !empty($actual['rw_separate'])) {
            throw new RuntimeException('Only the synthetic SQLite memory connection is permitted');
        }
        return parent::connect($config, $linkNum, $autoConnection);
    }
}
final class MemoryOnlyDb extends \think\DbManager
{
    private const CONNECTION = ['type' => 'sqlite', 'database' => ':memory:', 'hostname' => '', 'username' => '', 'password' => '', 'prefix' => '', 'fields_cache' => false];
    public function __construct()
    {
        parent::__construct();
        parent::setConfig(['default' => 'synthetic_memory', 'connections' => ['synthetic_memory' => self::CONNECTION]]);
    }
    public function setConfig($config): void { throw new RuntimeException('Database configuration changes forbidden'); }
    public function connect(string|array|null $name = null, bool $force = false)
    {
        if (($name !== null && $name !== 'synthetic_memory') || $force) throw new RuntimeException('Alternate connections forbidden');
        return parent::connect('synthetic_memory', false);
    }
    protected function createConnection(string|array $config): \think\db\ConnectionInterface
    {
        if ($config !== 'synthetic_memory') throw new RuntimeException('Alternate connection configuration forbidden');
        $connection = new MemoryOnlyConnection(self::CONNECTION);
        $connection->setDb($this);
        $GLOBALS['evidence']['connections'][] = ['driver' => 'sqlite', 'database' => ':memory:'];
        return $connection;
    }
}
$container = new \think\Container();
\think\Container::setInstance($container);
$database = new MemoryOnlyDb();
$container->instance(\think\DbManager::class, $database);
$requestReflection = new ReflectionClass(\think\Request::class);
$emptyRequest = $requestReflection->newInstanceWithoutConstructor();
$container->instance(\think\Cookie::class, new \think\Cookie($emptyRequest));
// No Request constructor reads php://input and no Cookie method reads host data.
function check(bool $condition, string $message): void {
    $GLOBALS['evidence']['assertions']++;
    if (!$condition) throw new RuntimeException($message);
}
function same(mixed $actual, mixed $expected, string $message): void { check($actual === $expected, $message); }
function setProperty(object $object, string $class, string $name, mixed $value): void {
    (new ReflectionProperty($class, $name))->setValue($object, $value);
}
function newService(): \app\service\QuantSimulationService {
    $service = (new ReflectionClass(\app\service\QuantSimulationService::class))->newInstanceWithoutConstructor();
    setProperty($service, \app\service\QuantSimulationService::class, 'decisionQualityService', new \app\service\AiDecisionQualityService());
    return $service;
}
function newController(\app\service\QuantSimulationService $service, array $query = []): \app\controller\Simulation {
    $controller = (new ReflectionClass(\app\controller\Simulation::class))->newInstanceWithoutConstructor();
    $request = (new ReflectionClass(\think\Request::class))->newInstanceWithoutConstructor();
    $request->withGet($query);
    setProperty($controller, \app\controller\Simulation::class, 'service', $service);
    setProperty($controller, \app\controller\Base::class, 'request', $request);
    setProperty($controller, \app\controller\Base::class, 'currentUser', new class {
        public int $id = 3;
        public function isSuperAdmin(): bool { return false; }
        public function hasHotelPermission(int $id, string $capability): bool { return $id === 7 && $capability === 'investment.view'; }
    });
    return $controller;
}
function fixture(int $id, ?int $hotel = 7, int $tenant = 7, int $owner = 3, ?string $deleted = null): array {
    return ['id' => $id, 'tenant_id' => $tenant, 'project_name' => 'synthetic saved ' . $id,
        'input_json' => json_encode($hotel ? ['hotel_id' => $hotel, 'system_hotel_id' => $hotel] : new stdClass()),
        'result_json' => '{}', 'scenarios_json' => '[]', 'risk_hints_json' => '[]', 'monthly_net_cashflow' => null,
        'payback_months' => null, 'risk_level' => '', 'created_by' => $owner, 'created_at' => '2026-09-20 09:00:00',
        'updated_at' => '2026-09-20 09:00:00', 'deleted_at' => $deleted];
}
function ids(array $rows): array { return array_column($rows, 'id'); }
function runCase(string $name, callable $body): void {
    $GLOBALS['evidence']['cases'][] = ['name' => $name, 'passed' => false]; $index = count($GLOBALS['evidence']['cases']) - 1;
    try { $body(); $GLOBALS['evidence']['cases'][$index]['passed'] = true; }
    catch (Throwable $error) { $GLOBALS['evidence']['cases'][$index]['failure'] = $error->getMessage(); throw $error; }
}
try {
    // These are the only writes: a private memory database, never application DB.
    $database->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, tenant_id INTEGER, hotel_id INTEGER)');
    $database->execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER)');
    $database->execute('CREATE TABLE quant_simulation_records (id INTEGER PRIMARY KEY, tenant_id INTEGER, project_name TEXT, input_json TEXT, result_json TEXT, scenarios_json TEXT, risk_hints_json TEXT, monthly_net_cashflow REAL, payback_months REAL, risk_level TEXT, created_by INTEGER, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
    $database->execute('CREATE TABLE operation_execution_intents (id INTEGER PRIMARY KEY, tenant_id INTEGER, hotel_id INTEGER, source_module TEXT, source_record_id INTEGER, status TEXT, deleted_at TEXT)');
    $database->name('users')->insert(['id' => 3, 'tenant_id' => 7, 'hotel_id' => 7]);
    $database->name('hotels')->insertAll([['id' => 7, 'tenant_id' => 7], ['id' => 8, 'tenant_id' => 7]]);
    $service = newService();
    runCase('original array API preserves clamps and legacy readonly policy', function () use ($database, $service): void {
        for ($id = 1; $id <= 105; $id++) $database->name('quant_simulation_records')->insert(fixture($id, $id === 1 ? null : 7));
        same(count($service->recordsForAccess(3, false, null, 0)), 1, 'old zero limit clamps to one');
        same(count($service->recordsForAccess(3, false, null, 999)), 100, 'old excessive limit clamps to100');
        $legacy = $service->recordsForAccess(3, false, static fn(array $record): bool => $record['access_policy']['mode'] === 'legacy_read_only');
        same(ids($legacy), [1], 'legacy remains reachable beyond the first raw batch');
        same($legacy[0]['access_policy'], ['mode' => 'legacy_read_only', 'hotel_binding_required' => true, 'mutation_allowed' => false, 'reason_code' => 'legacy_hotel_binding_required'], 'legacy public policy unchanged');
    });
    runCase('controller permissions before paging and stable authorized lookahead across raw batches', function () use ($database, $service): void {
        $database->name('quant_simulation_records')->delete(true);
        for ($id = 1; $id <= 36; $id++) $database->name('quant_simulation_records')->insert(fixture($id, $id === 1 ? null : 7));
        for ($id = 40; $id <= 144; $id++) $database->name('quant_simulation_records')->insert(fixture($id, 8));
        $database->name('quant_simulation_records')->insertAll([fixture(200, 7, 8), fixture(201, 7, 7, 4), fixture(202, 7, 7, 3, '2026-09-20 10:00:00')]);
        $first = newController($service)->records()->getData();
        same($first['code'], 200, 'controller succeeds'); same(ids($first['data']['list']), range(36, 7), 'all denied/other tenant/owner/archived excluded before page count');
        same($first['data']['pagination'], ['page_size' => 30, 'returned_count' => 30, 'has_more' => true, 'next_before_id' => 7], 'cursor is last displayed, not lookahead6');
        $database->name('quant_simulation_records')->insert(fixture(300));
        $database->name('quant_simulation_records')->where('id', 7)->update(['deleted_at' => '2026-09-20 10:01:00']);
        $second = newController($service, ['before_id' => '7'])->records()->getData()['data'];
        same(ids($second['list']), range(6, 1), 'new ID and archived cursor do not skip lookahead or legacy');
        same($second['pagination'], ['page_size' => 30, 'returned_count' => 6, 'has_more' => false, 'next_before_id' => null], 'authorized end confirmed');
        same(newController($service)->records()->getData()['data']['list'][0]['id'], 300, 'refresh restarts newest list');
    });
    runCase('maximum page has real101st lookahead and invalid HTTP inputs are422', function () use ($database, $service): void {
        $database->name('quant_simulation_records')->delete(true);
        for ($id = 1; $id <= 101; $id++) $database->name('quant_simulation_records')->insert(fixture($id));
        $page = newController($service, ['page_size' => '100'])->records()->getData()['data'];
        same(ids($page['list']), range(101, 2), 'max page returns100');
        same($page['pagination'], ['page_size' => 100, 'returned_count' => 100, 'has_more' => true, 'next_before_id' => 2], '101st authorized row proves more');
        same(ids(newController($service, ['page_size' => '100', 'before_id' => '2'])->records()->getData()['data']['list']), [1], 'lookahead returned next');
        foreach ([['page_size' => '0'], ['page_size' => '101'], ['page_size' => '1.5'], ['before_id' => '-1'], ['before_id' => '99999999999999999999999999'], ['before_id' => ['2']]] as $query) {
            $response = newController($service, $query)->records(); same($response->getCode(), 422, 'invalid query HTTP422'); same($response->getData()['code'], 422, 'invalid query envelope422');
        }
    });
    same($evidence['connections'], [['driver' => 'sqlite', 'database' => ':memory:']], 'only one synthetic memory connection');
    $evidence['passed'] = true;
} catch (Throwable $error) {
    $evidence['passed'] = false; $evidence['failure'] = $error::class . ': ' . $error->getMessage();
} finally {
    $database->connect()->close();
    // The process exits; never restore or reconnect a default application DB.
    if (isset($options['evidence'])) file_put_contents($options['evidence'], json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
    echo json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
}
exit($evidence['passed'] ? 0 : 1);
