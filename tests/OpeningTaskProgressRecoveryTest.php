<?php
declare(strict_types=1);

namespace Tests;

use app\service\AiDecisionQualityService;
use app\service\OpeningService;
use app\service\SourceBackedExecutionBridgeProjectionService;
use app\service\SourceBackedExecutionIntentIdentityService;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use think\Container;
use think\DbManager;
use think\facade\Db;

/**
 * Dedicated memory-only command (ordinary suites safely skip this fixture):
 * C:\xampp\php\php.exe -n -d extension_dir=C:\xampp\php\ext -d extension=mbstring -d extension=pdo_sqlite -d allow_url_fopen=0 -d disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,exec,shell_exec,system,passthru,proc_open,popen vendor/bin/phpunit --no-configuration --do-not-cache-result --colors=never tests/OpeningTaskProgressRecoveryTest.php
 */
final class OpeningTaskProgressRecoveryTest extends TestCase
{
    private OpeningProgressMemoryDb $database;
    private OpeningService $service;
    private \Closure $guard;

    protected function setUp(): void
    {
        parent::setUp();
        if (php_ini_loaded_file() !== false || class_exists('think\\App', false) || class_exists('think\\Env', false)) {
            self::markTestSkipped('Requires the documented standalone PHP -n / --no-configuration command; no application database is used.');
        }
        self::assertFalse(class_exists('think\\App', false), 'Use --no-configuration; this fixture must not initialize App.');
        self::assertFalse(class_exists('think\\Env', false));
        $root = dirname(__DIR__);
        $this->guard = static function (string $class) use ($root): void {
            if (in_array($class, ['think\\App', 'think\\Config', 'think\\Env', 'app\\service\\LlmClient'], true)) {
                throw new RuntimeException('Forbidden fixture dependency: ' . $class);
            }
            if (!str_starts_with($class, 'app\\')) return;
            if (!in_array($class, [OpeningService::class, AiDecisionQualityService::class,
                SourceBackedExecutionBridgeProjectionService::class, SourceBackedExecutionIntentIdentityService::class], true)) {
                throw new RuntimeException('Unreviewed fixture dependency: ' . $class);
            }
            require_once $root . '/' . str_replace('\\', '/', $class) . '.php';
        };
        spl_autoload_register($this->guard, true, true);
        $container = new Container();
        Container::setInstance($container);
        $this->database = new OpeningProgressMemoryDb();
        $container->instance(DbManager::class, $this->database);
        self::assertSame(['sqlite'], PDO::getAvailableDrivers(), 'The dedicated test command must only load the SQLite PDO driver.');
        $this->createFixture();
        $this->service = (new ReflectionClass(OpeningService::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(OpeningService::class, 'decisionQualityService'))
            ->setValue($this->service, new AiDecisionQualityService());
        (new ReflectionProperty(OpeningService::class, 'executionBridgeProjection'))
            ->setValue($this->service, new SourceBackedExecutionBridgeProjectionService());
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) $this->database->connect()->close();
        if (isset($this->guard)) spl_autoload_unregister($this->guard);
        parent::tearDown();
    }

    public function testCompletedProgressOnlyReopensAndPersistsExactReadback(): void
    {
        foreach ([50, 1, 99] as $progress) {
            Db::name('opening_tasks')->where('id', 41)->update(['status' => 'done', 'progress_percent' => 100]);
            $saved = $this->service->updateTask(41, ['progress_percent' => $progress], [7], 3, true);
            $readback = $this->taskReadback(41);
            $stored = Db::name('opening_tasks')->where('id', 41)->find();
            self::assertSame($saved, $readback);
            self::assertSame($progress, (int)$stored['progress_percent']);
            self::assertSame('doing', $stored['status'], json_encode([
                'requested_progress' => $progress,
                'saved_status' => $saved['status'],
                'saved_progress' => $saved['progress_percent'],
                'stored_status' => $stored['status'],
                'readback_status' => $readback['status'],
            ], JSON_UNESCAPED_UNICODE));
            self::assertSame('doing', $readback['status']);
            self::assertSame($progress, $readback['progress_percent']);
            self::assertTrue($readback['progress_percent_known']);
            if ($progress === 50) {
                $tasks = $this->service->tasks(31, [7], 3, true);
                $project = Db::name('opening_projects')->where('id', 31)->find();
                $result = (new \ReflectionMethod(OpeningService::class, 'calculateMetrics'))
                    ->invoke($this->service, $project, $tasks, false);
                $metrics = $result['metrics'];
                self::assertSame(2, $metrics['total_tasks']);
                self::assertSame(1, $metrics['completed_tasks']);
                self::assertSame(50.0, $metrics['completion_rate']);
                self::assertNull($metrics['progress_rate']);
                self::assertSame(50.0, $metrics['recorded_progress_rate']);
                self::assertSame(1, $metrics['progress_recorded_tasks']);
                self::assertSame(1, $metrics['progress_missing_tasks']);
                self::assertSame('partial', $metrics['progress_data_status']);
            }
        }
    }

    public function testExplicitDoneStillWinsAndZeroAndHundredKeepTheirBoundaries(): void
    {
        foreach ([
            [['status' => 'done', 'progress_percent' => 25], 'done', 100],
            [['progress_percent' => 0], 'todo', 0],
            [['progress_percent' => 100], 'done', 100],
            [['status' => 'doing', 'progress_percent' => 25], 'doing', 25],
        ] as [$input, $status, $progress]) {
            $saved = $this->service->updateTask(41, $input, [7], 3, true);
            self::assertSame($status, $saved['status']);
            self::assertSame($progress, $saved['progress_percent']);
            self::assertSame($saved, $this->taskReadback(41));
        }
    }

    public function testBlockedProgressRetainsBlockUntilExplicitCompletion(): void
    {
        Db::name('opening_tasks')->where('id', 41)->update(['status' => 'blocked', 'progress_percent' => 20]);
        foreach ([1, 50, 99, 0] as $progress) {
            $saved = $this->service->updateTask(41, ['progress_percent' => $progress], [7], 3, true);
            self::assertSame('blocked', $saved['status']);
            self::assertSame($progress, $saved['progress_percent']);
            self::assertSame($saved, $this->taskReadback(41));
        }
        $saved = $this->service->updateTask(41, ['progress_percent' => 100], [7], 3, true);
        self::assertSame('done', $saved['status']);
        self::assertSame(100, $saved['progress_percent']);
    }

    public function testLegacyMissingProgressIsNotInferredFromDoneStatus(): void
    {
        $legacy = $this->taskReadback(42);
        self::assertSame('done', $legacy['status']);
        self::assertNull($legacy['progress_percent']);
        self::assertFalse($legacy['progress_percent_known']);
        $saved = $this->service->updateTask(42, ['remark' => 'synthetic legacy edit'], [7], 3, true);
        self::assertSame('done', $saved['status']);
        self::assertNull($saved['progress_percent']);
        self::assertSame($saved, $this->taskReadback(42));
        $reopened = $this->service->updateTask(42, ['progress_percent' => 40], [7], 3, true);
        self::assertSame('doing', $reopened['status']);
        self::assertSame(40, $reopened['progress_percent']);
        self::assertSame($reopened, $this->taskReadback(42));
    }

    public function testOrdinaryEditsPreserveUnknownProgressAndAggregateCoverageInEveryStatus(): void
    {
        foreach (['todo', 'doing', 'blocked', 'done'] as $status) {
            Db::name('opening_tasks')->where('id', 42)->update(['status' => $status, 'progress_percent' => null]);
            $loaded = $this->taskReadback(42);
            self::assertNull($loaded['progress_percent']);
            self::assertFalse($loaded['progress_percent_known']);
            // Representative ordinary-edit payload: absence preserves unknown progress.
            $input = [
                'remark' => 'synthetic ordinary edit ' . $status,
                'owner_name' => 'synthetic owner ' . $status,
                'deadline' => '2030-12-20',
            ];
            if ($status !== 'done') $input['status'] = $status;
            $saved = $this->service->updateTask(42, $input, [7], 3, true);
            $readback = $this->taskReadback(42);
            $stored = Db::name('opening_tasks')->where('id', 42)->find();
            self::assertSame($saved, $readback);
            foreach (['remark', 'owner_name', 'deadline'] as $field) {
                self::assertSame($input[$field], $stored[$field]);
                self::assertSame($input[$field], $readback[$field]);
            }
            self::assertSame($status, $stored['status']);
            self::assertNull($stored['progress_percent']);
            self::assertSame($status, $readback['status']);
            self::assertNull($readback['progress_percent']);
            self::assertFalse($readback['progress_percent_known']);

            $project = Db::name('opening_projects')->where('id', 31)->find();
            $tasks = $this->service->tasks(31, [7], 3, true);
            $result = (new \ReflectionMethod(OpeningService::class, 'calculateMetrics'))
                ->invoke($this->service, $project, $tasks, false);
            $metrics = $result['metrics'];
            self::assertSame(2, $metrics['total_tasks']);
            self::assertSame($status === 'done' ? 2 : 1, $metrics['completed_tasks']);
            self::assertSame($status === 'done' ? 100.0 : 50.0, $metrics['completion_rate']);
            self::assertNull($metrics['progress_rate']);
            self::assertSame(100.0, $metrics['recorded_progress_rate']);
            self::assertSame(1, $metrics['progress_recorded_tasks']);
            self::assertSame(1, $metrics['progress_missing_tasks']);
            self::assertSame('partial', $metrics['progress_data_status']);
        }
    }

    public function testExplicitProgressFromUnknownStateSavesZeroMiddleAndHundredAsKnown(): void
    {
        foreach ([[0, 'todo'], [50, 'doing'], [100, 'done']] as [$progress, $status]) {
            Db::name('opening_tasks')->where('id', 42)->update(['status' => 'done', 'progress_percent' => null]);
            $loaded = $this->taskReadback(42);
            self::assertNull($loaded['progress_percent']);
            self::assertFalse($loaded['progress_percent_known']);
            // The previous DTO remains known=false; the explicit edit supplies a real value.
            $input = ['status' => $status, 'progress_percent' => $progress];
            $saved = $this->service->updateTask(42, $input, [7], 3, true);
            $readback = $this->taskReadback(42);
            $stored = Db::name('opening_tasks')->where('id', 42)->find();
            self::assertSame($saved, $readback);
            self::assertSame($status, $stored['status']);
            self::assertSame($progress, (int)$stored['progress_percent']);
            self::assertSame($status, $readback['status']);
            self::assertSame($progress, $readback['progress_percent']);
            self::assertTrue($readback['progress_percent_known']);
        }
    }

    public function testWrongHotelCannotChangeTheTaskOrProject(): void
    {
        $before = $this->snapshot();
        try {
            $this->service->updateTask(41, ['progress_percent' => 50], [8], 3, false);
            self::fail('A task outside the actor hotel scope must be rejected.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('开业项目不存在或无权操作', $error->getMessage());
        }
        self::assertSame($before, $this->snapshot());
    }

    public function testFailedAggregateWriteRollsBackEveryTaskAndTheProject(): void
    {
        $before = $this->snapshot();
        Db::execute("CREATE TRIGGER fail_progress_aggregate BEFORE UPDATE OF overall_score ON opening_projects BEGIN SELECT RAISE(ABORT, 'synthetic aggregate failure'); END");
        $failure = null;
        try {
            $this->service->updateTask(41, ['progress_percent' => 50], [7], 3, true);
        } catch (\Throwable $error) {
            $failure = $error;
        } finally {
            Db::execute('DROP TRIGGER fail_progress_aggregate');
        }
        self::assertInstanceOf(\Throwable::class, $failure);
        self::assertStringContainsString('synthetic aggregate failure', $failure->getMessage());
        self::assertSame($before, $this->snapshot());
        self::assertSame('done', $this->taskReadback(41)['status']);
        self::assertSame(100, $this->taskReadback(41)['progress_percent']);
    }

    private function taskReadback(int $id): array
    {
        foreach ($this->service->tasks(31, [7], 3, true) as $task) {
            if ($task['id'] === $id) return $task;
        }
        self::fail('Expected saved fixture task was not returned.');
    }

    private function snapshot(): array
    {
        return [
            'projects' => Db::name('opening_projects')->order('id')->select()->toArray(),
            'tasks' => Db::name('opening_tasks')->order('id')->select()->toArray(),
        ];
    }

    private function createFixture(): void
    {
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL)');
        Db::execute('CREATE TABLE operation_execution_intents (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, source_module TEXT, source_record_id INTEGER, hotel_id INTEGER, status TEXT, deleted_at TEXT)');
        Db::name('hotels')->insert(['id' => 7, 'tenant_id' => 7]);
        Db::execute("CREATE TABLE opening_projects (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, project_name TEXT NOT NULL DEFAULT '', hotel_name TEXT NOT NULL DEFAULT '', city TEXT NOT NULL DEFAULT '', brand TEXT NOT NULL DEFAULT '', positioning TEXT NOT NULL DEFAULT '', room_count INTEGER NOT NULL DEFAULT 0, opening_date TEXT NOT NULL, manager_name TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'preparing', overall_score REAL NOT NULL DEFAULT 0, risk_level TEXT NOT NULL DEFAULT 'low', ai_penetration_rate REAL NOT NULL DEFAULT 0, created_by INTEGER NOT NULL DEFAULT 0, created_at TEXT, updated_at TEXT, deleted_at TEXT)");
        Db::execute("CREATE TABLE opening_tasks (id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL, category TEXT NOT NULL DEFAULT '', task_name TEXT NOT NULL DEFAULT '', task_desc TEXT NOT NULL DEFAULT '', is_core INTEGER NOT NULL DEFAULT 0, owner_name TEXT NOT NULL DEFAULT '', collaborator_name TEXT NOT NULL DEFAULT '', deadline TEXT, status TEXT NOT NULL DEFAULT 'todo', progress_percent INTEGER DEFAULT NULL, risk_level TEXT NOT NULL DEFAULT 'low', acceptance_standard TEXT NOT NULL DEFAULT '', ai_suggestion TEXT NOT NULL DEFAULT '', remark TEXT NOT NULL DEFAULT '', sort_order INTEGER NOT NULL DEFAULT 0, created_at TEXT, updated_at TEXT)");
        Db::name('opening_projects')->insert([
            'id' => 31, 'tenant_id' => 7, 'hotel_id' => 7, 'project_name' => 'Synthetic progress fixture',
            'opening_date' => '2030-12-31', 'created_by' => 3,
            'created_at' => '2026-09-01 10:00:00', 'updated_at' => '2026-09-01 10:00:00',
        ]);
        foreach ([41 => 100, 42 => null] as $id => $progress) {
            Db::name('opening_tasks')->insert([
                'id' => $id, 'project_id' => 31, 'category' => 'PMS系统配置',
                'task_name' => 'Synthetic task ' . $id, 'status' => 'done', 'progress_percent' => $progress,
                'created_at' => '2026-09-01 10:00:00', 'updated_at' => '2026-09-01 10:00:00',
            ]);
        }
    }
}

final class OpeningProgressMemoryConnection extends \think\db\connector\Sqlite
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

final class OpeningProgressMemoryDb extends DbManager
{
    private const CONNECTION = ['type' => 'sqlite', 'database' => ':memory:', 'hostname' => '', 'username' => '', 'password' => '', 'prefix' => '', 'fields_cache' => false];

    public function __construct()
    {
        parent::__construct();
        parent::setConfig(['default' => 'opening_progress_memory', 'connections' => ['opening_progress_memory' => self::CONNECTION]]);
    }

    public function setConfig($config): void { throw new RuntimeException('Database configuration changes forbidden'); }

    public function connect(string|array|null $name = null, bool $force = false)
    {
        if (($name !== null && $name !== 'opening_progress_memory') || $force) throw new RuntimeException('Alternate connections forbidden');
        return parent::connect('opening_progress_memory', false);
    }

    protected function createConnection(string|array $config): \think\db\ConnectionInterface
    {
        if ($config !== 'opening_progress_memory') throw new RuntimeException('Alternate connection configuration forbidden');
        $connection = new OpeningProgressMemoryConnection(self::CONNECTION);
        $connection->setDb($this);
        return $connection;
    }
}
