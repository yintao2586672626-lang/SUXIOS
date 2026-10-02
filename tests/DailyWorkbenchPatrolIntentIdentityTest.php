<?php
declare(strict_types=1);

namespace Tests;

use app\controller\OnlineData;
use app\service\DailyWorkbenchPatrolService;
use app\service\OperationManagementService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use think\facade\Config;
use think\facade\Db;

final class DailyWorkbenchPatrolIntentIdentityTest extends TestCase
{
    private const RUN_A = 'daily_workbench_20260915_120000_912e74bc06d2062b152c02ba66c5c365';
    private const RUN_B = 'daily_workbench_20260915_120000_2ae4685ff806426e218b0cfa4edd56fa';
    private const SOURCE_ID = 642167665;
    private string $originalRuntimePath;
    private array $originalDatabaseConfig;
    private string $temporaryRuntimePath;
    private string $sqlitePath;
    private string $baseDir;

    public static function setUpBeforeClass(): void
    {
        app()->initialize();
    }

    protected function setUp(): void
    {
        $this->originalRuntimePath = app()->getRuntimePath();
        $this->originalDatabaseConfig = Config::get('database', []);
        $this->temporaryRuntimePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'synthetic_patrol_intent_identity_' . getmypid() . '_' . bin2hex(random_bytes(6));
        self::assertDirectoryDoesNotExist($this->temporaryRuntimePath);
        mkdir($this->temporaryRuntimePath, 0775, true);
        app()->setRuntimePath($this->temporaryRuntimePath . DIRECTORY_SEPARATOR);
        $this->baseDir = $this->temporaryRuntimePath . DIRECTORY_SEPARATOR . 'phase2_daily_workbench_patrol';
        $this->sqlitePath = $this->temporaryRuntimePath . DIRECTORY_SEPARATOR . 'fixture.sqlite';
        Config::set(['default' => 'sqlite', 'connections' => ['sqlite' => [
            'type' => 'sqlite', 'database' => $this->sqlitePath, 'prefix' => '', 'fields_strict' => false,
        ]]], 'database');
        Db::connect(null, true);
        $this->createSchema();
        Db::name('hotels')->insert(['id' => 7, 'tenant_id' => 42]);
    }

    protected function tearDown(): void
    {
        Db::connect()->close();
        Config::set($this->originalDatabaseConfig, 'database');
        app()->setRuntimePath($this->originalRuntimePath);
        foreach (array_keys($this->snapshotContents()) as $path) unlink($path);
        foreach (glob($this->baseDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (preg_match('/^\d{8}$/', basename($dir)) && count(scandir($dir)) === 2) rmdir($dir);
        }
        if (is_dir($this->baseDir) && count(scandir($this->baseDir)) === 2) rmdir($this->baseDir);
        if (is_file($this->sqlitePath)) unlink($this->sqlitePath);
        if (is_dir($this->temporaryRuntimePath) && count(scandir($this->temporaryRuntimePath)) === 2) rmdir($this->temporaryRuntimePath);
    }

    public function testCollidingRunCannotReusePendingIntentOrBindItsSnapshotToTheOldRun(): void
    {
        $patrol = new DailyWorkbenchPatrolService();
        $inputA = $this->writeSnapshotAndInput(self::RUN_A);
        $inputB = $this->writeSnapshotAndInput(self::RUN_B);
        $service = new OperationManagementService();
        $sourceId = new ReflectionMethod($service, 'dailyWorkbenchPatrolSourceRecordId');
        foreach ([self::RUN_A, self::RUN_B] as $runId) {
            self::assertSame(self::SOURCE_ID, $sourceId->invoke($service, $runId, 7, 'price_adjust', 'conversion_gap'));
            self::assertSame($runId, $patrol->findByRunIdForHotel($runId, 7)['run_id']);
        }

        $syncA = $service->syncDailyWorkbenchPatrolAction([7], $inputA, 3);
        $patrol->updateActionStatusForHotel($inputA + ['operation_execution' => $syncA], 7, 3);
        $intentA = $service->readExecutionIntent((int)$syncA['intent_id'], [7]);
        self::assertSame('pending_approval', $intentA['status']);
        self::assertContains('daily_workbench_patrol#' . self::RUN_A, $intentA['evidence']['evidence_refs']);
        $before = Db::name('operation_execution_intents')->where('id', $syncA['intent_id'])->find();
        $snapshotsBefore = $this->snapshotContents();
        $failure = null;
        $syncB = null;
        try {
            $syncB = $service->syncDailyWorkbenchPatrolAction([7], $inputB, 3);
            $patrol->updateActionStatusForHotel($inputB + ['operation_execution' => $syncB], 7, 3);
        } catch (\InvalidArgumentException $exception) {
            $failure = $exception;
        }
        $readB = $patrol->findByRunIdForHotel(self::RUN_B, 7);
        $bindingB = $readB['action_tracking']['items']['7|price_adjust']['operation_execution'] ?? null;
        if ($failure === null) {
            $reused = $service->readExecutionIntent((int)$syncB['intent_id'], [7]);
            fwrite(STDOUT, PHP_EOL . json_encode([
                'synthetic_collision' => true, 'run_a' => self::RUN_A, 'run_b' => self::RUN_B,
                'source_record_id' => self::SOURCE_ID, 'intent_id_a' => $syncA['intent_id'],
                'intent_id_b' => $syncB['intent_id'], 'stored_status' => $reused['status'],
                'stored_reference' => $reused['evidence']['evidence_refs'][0],
                'snapshot_b_intent_id' => $bindingB['intent_id'] ?? null,
                'snapshot_b_run_id' => $readB['run_id'],
            ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
        }
        self::assertSame($before, Db::name('operation_execution_intents')->where('id', $syncA['intent_id'])->find());
        self::assertSame(1, (int)Db::name('operation_execution_intents')->count());
        self::assertSame(0, (int)Db::name('operation_execution_tasks')->count());
        self::assertSame(0, (int)Db::name('operation_execution_evidence')->count());
        self::assertNull($bindingB, 'Different run B must not acquire run A pending intent through a CRC collision.');
        self::assertInstanceOf(\InvalidArgumentException::class, $failure);
        self::assertSame($snapshotsBefore, $this->snapshotContents());
    }

    public function testExactSameRunReplayKeepsPendingIntentAndEvidenceReference(): void
    {
        $input = $this->writeSnapshotAndInput(self::RUN_A);
        $service = new OperationManagementService();
        $first = $service->syncDailyWorkbenchPatrolAction([7], $input, 3);
        $before = Db::name('operation_execution_intents')->where('id', $first['intent_id'])->find();
        $again = $service->syncDailyWorkbenchPatrolAction([7], $input, 3);
        self::assertSame($first['intent_id'], $again['intent_id']);
        self::assertSame(self::SOURCE_ID, $again['source_record_id']);
        self::assertSame($before, Db::name('operation_execution_intents')->where('id', $first['intent_id'])->find());
        self::assertContains('daily_workbench_patrol#' . self::RUN_A, $service->readExecutionIntent((int)$again['intent_id'], [7])['evidence']['evidence_refs']);
        self::assertSame(1, (int)Db::name('operation_execution_intents')->count());
        self::assertSame(0, (int)Db::name('operation_execution_tasks')->count());
    }

    public function testRepositorySchemaAllowsSameCrcWithDifferentNullIdempotencyRows(): void
    {
        $input = $this->writeSnapshotAndInput(self::RUN_A);
        $sync = (new OperationManagementService())->syncDailyWorkbenchPatrolAction([7], $input, 3);
        $copy = Db::name('operation_execution_intents')->where('id', $sync['intent_id'])->find();
        self::assertNull($copy['idempotency_key']);
        unset($copy['id']);
        $evidence = json_decode($copy['evidence_json'], true, 512, JSON_THROW_ON_ERROR);
        $evidence['evidence_refs'][0] = 'daily_workbench_patrol#' . self::RUN_B;
        $copy['evidence_json'] = json_encode($evidence, JSON_THROW_ON_ERROR);
        $secondId = (int)Db::name('operation_execution_intents')->insertGetId($copy);
        self::assertNotSame((int)$sync['intent_id'], $secondId);
        self::assertSame(2, (int)Db::name('operation_execution_intents')->where('source_record_id', self::SOURCE_ID)->where('hotel_id', 7)->count());
        $indexes = Db::query("PRAGMA index_list('operation_execution_intents')");
        $uniqueColumns = [];
        foreach ($indexes as $index) {
            if ((int)$index['unique'] === 1) {
                $uniqueColumns[] = array_column(Db::query('PRAGMA index_info(' . $index['name'] . ')'), 'name');
            }
        }
        self::assertSame([['idempotency_key']], $uniqueColumns);
        self::assertSame(0, (int)Db::name('operation_execution_tasks')->count());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('validSourceReferences')]
    public function testUniqueOriginalSourceReferencesRemainReplayable(array $references, string $runId, bool $padRequest): void
    {
        $input = $this->writeSnapshotAndInput(self::RUN_A);
        $input['run_id'] = $runId;
        $service = new OperationManagementService();
        $first = $service->syncDailyWorkbenchPatrolAction([7], $input, 3);
        $this->replaceStoredReferences((int)$first['intent_id'], $references);
        $before = Db::name('operation_execution_intents')->where('id', $first['intent_id'])->find();
        $snapshotsBefore = $this->snapshotContents();
        if ($padRequest) $input['run_id'] = " \t" . $runId . "\n";
        $replay = $service->syncDailyWorkbenchPatrolAction([7], $input, 3);
        self::assertSame($first['intent_id'], $replay['intent_id']);
        self::assertSame($first['source_record_id'], $replay['source_record_id']);
        self::assertSame($before, Db::name('operation_execution_intents')->where('id', $first['intent_id'])->find());
        self::assertSame('pending_approval', $service->readExecutionIntent((int)$replay['intent_id'], [7])['status']);
        self::assertSame($snapshotsBefore, $this->snapshotContents());
        self::assertSame(1, (int)Db::name('operation_execution_intents')->count());
        self::assertSame(0, (int)Db::name('operation_execution_tasks')->count());
        self::assertSame(0, (int)Db::name('operation_execution_evidence')->count());
    }

    public static function validSourceReferences(): array
    {
        $a = 'daily_workbench_patrol#' . self::RUN_A;
        return [
            'string and unrelated API reference' => [[$a, '/api/online-data/daily-workbench'], self::RUN_A, false],
            'source_ref object' => [[['source_ref' => $a]], self::RUN_A, false],
            'ref object' => [[['ref' => $a]], self::RUN_A, false],
            'null primary reference uses ref' => [[['source_ref' => null, 'ref' => $a]], self::RUN_A, false],
            'duplicate same run across supported forms' => [[$a, ['source_ref' => $a], ['ref' => $a]], self::RUN_A, false],
            'whitespace on stored and requested run' => [[" \tdaily_workbench_patrol#  " . self::RUN_A . " \n"], self::RUN_A, true],
            'nonstandard legacy run stays compatible' => [['daily_workbench_patrol#patrol-run-20260717'], 'patrol-run-20260717', true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidSourceReferences')]
    public function testMissingEmptyOrConflictingOriginalReferencesRejectWithoutWrites(?array $references): void
    {
        $input = $this->writeSnapshotAndInput(self::RUN_A);
        $service = new OperationManagementService();
        $first = $service->syncDailyWorkbenchPatrolAction([7], $input, 3);
        $this->replaceStoredReferences((int)$first['intent_id'], $references);
        $before = Db::name('operation_execution_intents')->where('id', $first['intent_id'])->find();
        $snapshotsBefore = $this->snapshotContents();
        $failure = null;
        try {
            $service->syncDailyWorkbenchPatrolAction([7], $input, 3);
        } catch (\InvalidArgumentException $exception) {
            $failure = $exception;
        }
        self::assertSame($before, Db::name('operation_execution_intents')->where('id', $first['intent_id'])->find());
        self::assertSame($snapshotsBefore, $this->snapshotContents());
        self::assertSame(1, (int)Db::name('operation_execution_intents')->count());
        self::assertSame(0, (int)Db::name('operation_execution_tasks')->count());
        self::assertSame(0, (int)Db::name('operation_execution_evidence')->count());
        self::assertInstanceOf(\InvalidArgumentException::class, $failure);
        self::assertStringContainsString('stored patrol source', $failure->getMessage());
    }

    public static function invalidSourceReferences(): array
    {
        $a = 'daily_workbench_patrol#' . self::RUN_A;
        $b = 'daily_workbench_patrol#' . self::RUN_B;
        return [
            'missing refs' => [null],
            'empty refs' => [[]],
            'only unrelated references' => [['/api/online-data/daily-workbench']],
            'empty patrol run' => [['daily_workbench_patrol#  ']],
            'empty patrol before valid run' => [['daily_workbench_patrol# ', $a]],
            'valid run before empty patrol' => [[$a, 'daily_workbench_patrol# ']],
            'different colliding run only' => [[$b]],
            'expected run followed by different run' => [[$a, $b]],
            'different run followed by expected run' => [[$b, $a]],
            'conflicting object and string runs' => [[['source_ref' => $b], ['ref' => $a], $a]],
        ];
    }

    private function replaceStoredReferences(int $intentId, ?array $references): void
    {
        $row = Db::name('operation_execution_intents')->where('id', $intentId)->find();
        $evidence = json_decode($row['evidence_json'], true, 512, JSON_THROW_ON_ERROR);
        if ($references === null) unset($evidence['evidence_refs']);
        else $evidence['evidence_refs'] = $references;
        Db::name('operation_execution_intents')->where('id', $intentId)->update([
            'evidence_json' => json_encode($evidence, JSON_THROW_ON_ERROR),
        ]);
    }

    private function writeSnapshotAndInput(string $runId): array
    {
        $service = new DailyWorkbenchPatrolService();
        $snapshot = (new ReflectionMethod($service, 'buildSnapshot'))->invoke($service, [
            'scope' => ['hotel_id' => 7, 'target_date' => '2026-09-15'],
            'summary' => ['hotel_count' => 1], 'rows' => [['hotel_id' => 7]],
            'next_actions' => [[
                'hotel_id' => 7, 'action_code' => 'price_adjust', 'question_key' => 'conversion_gap',
                'platform' => 'ctrip', 'priority' => 'medium', 'action' => 'Review same synthetic OTA price.', 'entry' => '/operations',
            ]],
        ], ['trigger_type' => 'test']);
        $snapshot['run_id'] = $runId;
        $dateDir = $this->baseDir . DIRECTORY_SEPARATOR . '20260915';
        if (!is_dir($dateDir)) mkdir($dateDir, 0775, true);
        (new ReflectionMethod($service, 'writeNewSnapshotFile'))->invoke($service, $dateDir . DIRECTORY_SEPARATOR . $runId . '.json', $snapshot);
        file_put_contents($this->baseDir . DIRECTORY_SEPARATOR . 'latest.json', json_encode($snapshot, JSON_THROW_ON_ERROR), LOCK_EX);
        $controller = (new ReflectionClass(OnlineData::class))->newInstanceWithoutConstructor();
        return (new ReflectionMethod($controller, 'dailyWorkbenchPatrolActionInput'))->invoke($controller, $snapshot, [
            'run_id' => $runId, 'hotel_id' => 7, 'action_code' => 'price_adjust', 'question_key' => 'conversion_gap', 'status' => 'pending',
        ]);
    }

    private function snapshotContents(): array
    {
        $paths = array_merge(glob($this->baseDir . DIRECTORY_SEPARATOR . 'latest.json') ?: [],
            glob($this->baseDir . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'daily_workbench_*.json') ?: []);
        sort($paths);
        $contents = [];
        foreach ($paths as $path) $contents[$path] = (string)file_get_contents($path);
        return $contents;
    }

    private function createSchema(): void
    {
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL)');
        // Existing execution-loop migration: only idempotency_key is unique; source_record_id is not.
        Db::execute(<<<'SQL'
CREATE TABLE operation_execution_intents (
 id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, source_module TEXT NOT NULL,
 source_record_id INTEGER NOT NULL, idempotency_key TEXT UNIQUE, hotel_id INTEGER NOT NULL,
 platform TEXT NOT NULL, object_type TEXT NOT NULL, action_type TEXT NOT NULL,
 date_start TEXT, date_end TEXT, current_value_json TEXT, target_value_json TEXT, evidence_json TEXT,
 expected_metric TEXT, expected_delta REAL, risk_level TEXT, blocked_reason TEXT, status TEXT,
 created_by INTEGER, approved_by INTEGER DEFAULT 0, approved_at TEXT, review_remark TEXT DEFAULT '',
 created_at TEXT, updated_at TEXT, deleted_at TEXT
)
SQL);
        Db::execute(<<<'SQL'
CREATE TABLE operation_execution_tasks (
 id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, intent_id INTEGER NOT NULL,
 hotel_id INTEGER NOT NULL, execution_mode TEXT DEFAULT 'manual', operator_id INTEGER DEFAULT 0,
 target_value_json TEXT, current_value_json TEXT, blocked_reason TEXT DEFAULT '', action_track_id INTEGER DEFAULT 0,
 result_status TEXT DEFAULT 'observing', result_summary TEXT DEFAULT '', status TEXT,
 executed_at TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT
)
SQL);
        Db::execute(<<<'SQL'
CREATE TABLE operation_execution_evidence (
 id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, task_id INTEGER NOT NULL,
 evidence_type TEXT DEFAULT 'manual', before_json TEXT, after_json TEXT, attachment_path TEXT DEFAULT '',
 platform_response_json TEXT, remark TEXT DEFAULT '', created_by INTEGER DEFAULT 0,
 created_at TEXT, updated_at TEXT, deleted_at TEXT
)
SQL);
    }
}
