<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperationManagementService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class Tc288OperationTaskStateMachineL8Test extends TestCase
{
    private const HOTEL_ID = 7;
    private const OUT_OF_SCOPE_HOTEL_ID = 8;
    private const INTENT_ID = 28801;
    private const TASK_ID = 28802;
    private const OPERATOR_ID = 42;
    private const TARGET_DATE = '2026-07-15';
    private const STALE_EVENT_DATE = '2026-07-01';

    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . '/tc288_operation_state_machine_l8_' . getmypid() . '.sqlite';
        @unlink(self::$sqlitePath);

        $config = self::$originalDatabaseConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$sqlitePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($config, 'database');
        Db::connect(null, true);

        self::createSchema();
    }

    public static function tearDownAfterClass(): void
    {
        Db::connect()->close();
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        if (is_file(self::$sqlitePath) && !unlink(self::$sqlitePath)) {
            throw new RuntimeException('Unable to remove TC-288 SQLite fixture.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Db::name('operation_execution_evidence')->delete(true);
        Db::name('operation_execution_tasks')->delete(true);
        Db::name('operation_execution_intents')->delete(true);
    }

    /**
     * Restricted hotel scope and stale terminal state intentionally take
     * precedence over later evidence/outcome validation. The remaining factor
     * fixture is still asserted, but no downstream transition is claimed.
     *
     * @param array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string} $factors
     */
    #[DataProvider('l8VariantProvider')]
    public function testTc288OnlyAllowsCurrentEvidenceBackedTransitionsAndNeverRollsBackTerminalState(
        string $caseId,
        array $factors
    ): void {
        $this->seedExecutionState($factors);
        $input = $this->executionInput($caseId, $factors);
        $hotelIds = $factors['actor_scope'] === 'authorized'
            ? [self::HOTEL_ID]
            : [self::OUT_OF_SCOPE_HOTEL_ID];
        $message = $caseId . ' factors=' . json_encode($factors, JSON_UNESCAPED_SLASHES);
        $initialTask = $this->taskRow();
        $initialEvidenceCount = $this->evidenceCount();

        $this->assertFixtureRepresentsFactors($input, $initialTask, $factors, $message);

        if ($factors['actor_scope'] === 'restricted') {
            try {
                (new OperationManagementService())->executeExecutionTask(
                    self::TASK_ID,
                    $hotelIds,
                    $input,
                    self::OPERATOR_ID
                );
                self::fail($message . ' restricted hotel scope unexpectedly updated the task');
            } catch (RuntimeException $exception) {
                self::assertSame('execution task not found', $exception->getMessage(), $message);
            }

            self::assertSame($initialTask['status'], $this->taskRow()['status'], $message);
            self::assertSame($initialEvidenceCount, $this->evidenceCount(), $message);
            return;
        }

        if ($factors['freshness'] === 'stale') {
            $rejected = false;
            try {
                (new OperationManagementService())->executeExecutionTask(
                    self::TASK_ID,
                    $hotelIds,
                    $input,
                    self::OPERATOR_ID
                );
            } catch (InvalidArgumentException|RuntimeException $exception) {
                $rejected = true;
                self::assertMatchesRegularExpression(
                    '/terminal|transition|stale|already|cannot|not supported/i',
                    $exception->getMessage(),
                    $message
                );
            }

            $persisted = $this->taskRow();
            self::assertSame(
                $initialTask['status'],
                $persisted['status'],
                $message . ' terminal state must survive an old request' . ($rejected ? ' rejected by guard' : ' as no-op')
            );
            self::assertSame(
                (int)$initialTask['operator_id'],
                (int)$persisted['operator_id'],
                $message . ' stale request must not replace the terminal operator'
            );
            self::assertSame(
                $initialEvidenceCount,
                $this->evidenceCount(),
                $message . ' stale request must not append execution evidence'
            );
            return;
        }

        $result = (new OperationManagementService())->executeExecutionTask(
            self::TASK_ID,
            $hotelIds,
            $input,
            self::OPERATOR_ID
        );
        $persisted = $this->taskRow();

        if ($factors['data_completeness'] === 'missing_required') {
            self::assertSame('blocked', $result['status'], $message);
            self::assertSame('blocked', $persisted['status'], $message);
            self::assertStringContainsString('evidence', (string)$persisted['blocked_reason'], $message);
            self::assertSame(0, $this->evidenceCount(), $message);
            self::assertEmpty($persisted['executed_at'], $message);
            return;
        }

        $expectedStatus = $factors['upstream_state'] === 'success' ? 'executed' : 'failed';
        self::assertSame($expectedStatus, $result['status'], $message);
        self::assertSame($expectedStatus, $persisted['status'], $message);
        self::assertSame(self::OPERATOR_ID, (int)$persisted['operator_id'], $message);
        self::assertNotEmpty($persisted['executed_at'], $message);
        self::assertSame(1, $this->evidenceCount(), $message);

        $storedEvidence = Db::name('operation_execution_evidence')
            ->where('task_id', self::TASK_ID)
            ->find();
        self::assertIsArray($storedEvidence, $message);
        $platformResponse = json_decode(
            (string)$storedEvidence['platform_response_json'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame($factors['upstream_state'], $platformResponse['status'] ?? null, $message);
        self::assertSame(self::TARGET_DATE, $platformResponse['event_date'] ?? null, $message);
    }

    /**
     * @return array<string, array{0:string,1:array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string}}>
     */
    public static function l8VariantProvider(): array
    {
        return [
            'DX-2297 authorized complete fresh success' => ['DX-2297', self::factors('authorized', 'complete', 'fresh', 'success')],
            'DX-2298 authorized complete stale failure' => ['DX-2298', self::factors('authorized', 'complete', 'stale', 'failure')],
            'DX-2299 authorized missing fresh failure' => ['DX-2299', self::factors('authorized', 'missing_required', 'fresh', 'failure')],
            'DX-2300 authorized missing stale success' => ['DX-2300', self::factors('authorized', 'missing_required', 'stale', 'success')],
            'DX-2301 restricted complete fresh failure' => ['DX-2301', self::factors('restricted', 'complete', 'fresh', 'failure')],
            'DX-2302 restricted complete stale success' => ['DX-2302', self::factors('restricted', 'complete', 'stale', 'success')],
            'DX-2303 restricted missing fresh success' => ['DX-2303', self::factors('restricted', 'missing_required', 'fresh', 'success')],
            'DX-2304 restricted missing stale failure' => ['DX-2304', self::factors('restricted', 'missing_required', 'stale', 'failure')],
        ];
    }

    /** @param array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string} $factors */
    private function seedExecutionState(array $factors): void
    {
        Db::name('operation_execution_intents')->insert([
            'id' => self::INTENT_ID,
            'tenant_id' => 1,
            'hotel_id' => self::HOTEL_ID,
            'source_module' => 'ota_diagnosis',
            'source_record_id' => 288,
            'platform' => 'ctrip',
            'object_type' => 'price',
            'action_type' => 'price_adjust',
            'date_start' => self::TARGET_DATE,
            'date_end' => self::TARGET_DATE,
            'current_value_json' => json_encode(['price' => 208], JSON_THROW_ON_ERROR),
            'target_value_json' => json_encode(['price' => 188], JSON_THROW_ON_ERROR),
            'evidence_json' => json_encode(['case' => 'TC-288'], JSON_THROW_ON_ERROR),
            'expected_metric' => 'orders',
            'expected_delta' => 5,
            'risk_level' => 'medium',
            'status' => 'approved',
            'created_by' => 9,
            'created_at' => self::TARGET_DATE . ' 08:00:00',
            'updated_at' => self::TARGET_DATE . ' 08:00:00',
            'deleted_at' => null,
        ]);

        $initialStatus = 'pending_execute';
        $executedAt = null;
        $operatorId = 0;
        if ($factors['freshness'] === 'stale') {
            $initialStatus = $factors['upstream_state'] === 'success' ? 'executed' : 'failed';
            $executedAt = self::TARGET_DATE . ' 09:00:00';
            $operatorId = 11;
        }

        Db::name('operation_execution_tasks')->insert([
            'id' => self::TASK_ID,
            'tenant_id' => 1,
            'intent_id' => self::INTENT_ID,
            'hotel_id' => self::HOTEL_ID,
            'execution_mode' => 'manual',
            'operator_id' => $operatorId,
            'target_value_json' => json_encode(['price' => 188], JSON_THROW_ON_ERROR),
            'current_value_json' => json_encode(['price' => 208], JSON_THROW_ON_ERROR),
            'blocked_reason' => '',
            'action_track_id' => 0,
            'result_status' => 'observing',
            'result_summary' => '',
            'status' => $initialStatus,
            'executed_at' => $executedAt,
            'created_at' => self::TARGET_DATE . ' 08:10:00',
            'updated_at' => self::TARGET_DATE . ' 09:00:00',
            'deleted_at' => null,
        ]);

        if ($factors['freshness'] === 'stale' && $factors['data_completeness'] === 'complete') {
            Db::name('operation_execution_evidence')->insert([
                'id' => 28803,
                'tenant_id' => 1,
                'task_id' => self::TASK_ID,
                'evidence_type' => 'api_response',
                'before_json' => json_encode(['price' => 208], JSON_THROW_ON_ERROR),
                'after_json' => json_encode(['price' => 188], JSON_THROW_ON_ERROR),
                'attachment_path' => '',
                'platform_response_json' => json_encode([
                    'status' => $factors['upstream_state'],
                    'event_date' => self::TARGET_DATE,
                ], JSON_THROW_ON_ERROR),
                'remark' => 'terminal result already recorded',
                'created_by' => 11,
                'created_at' => self::TARGET_DATE . ' 09:00:00',
                'updated_at' => self::TARGET_DATE . ' 09:00:00',
                'deleted_at' => null,
            ]);
        }
    }

    /**
     * @param array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string} $factors
     * @return array<string, mixed>
     */
    private function executionInput(string $caseId, array $factors): array
    {
        $input = [
            'status' => $factors['freshness'] === 'stale'
                ? 'executing'
                : ($factors['upstream_state'] === 'success' ? 'executed' : 'failed'),
            'current_value' => ['price' => 188],
            'upstream_state' => $factors['upstream_state'],
            'event_date' => $factors['freshness'] === 'fresh' ? self::TARGET_DATE : self::STALE_EVENT_DATE,
        ];

        if ($factors['data_completeness'] === 'complete') {
            $input['evidence_type'] = 'api_response';
            $input['evidence'] = [
                'before' => ['price' => 208],
                'after' => ['price' => 188],
                'platform_response' => [
                    'case_id' => $caseId,
                    'status' => $factors['upstream_state'],
                    'event_date' => $input['event_date'],
                    'failure_reason' => $factors['upstream_state'] === 'failure' ? 'network_timeout' : '',
                ],
                'remark' => 'isolated TC-288 state transition evidence',
            ];
        }

        return $input;
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $initialTask
     * @param array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string} $factors
     */
    private function assertFixtureRepresentsFactors(
        array $input,
        array $initialTask,
        array $factors,
        string $message
    ): void {
        self::assertSame(
            $factors['data_completeness'] === 'complete',
            array_key_exists('evidence', $input),
            $message
        );
        self::assertSame(
            $factors['freshness'] === 'fresh' ? self::TARGET_DATE : self::STALE_EVENT_DATE,
            $input['event_date'],
            $message
        );
        self::assertSame($factors['upstream_state'], $input['upstream_state'], $message);

        if ($factors['freshness'] === 'fresh') {
            self::assertSame('pending_execute', $initialTask['status'], $message);
            self::assertSame(
                $factors['upstream_state'] === 'success' ? 'executed' : 'failed',
                $input['status'],
                $message
            );
        } else {
            self::assertContains($initialTask['status'], ['executed', 'failed'], $message);
            self::assertSame('executing', $input['status'], $message);
        }
    }

    /** @return array<string, mixed> */
    private function taskRow(): array
    {
        $row = Db::name('operation_execution_tasks')->where('id', self::TASK_ID)->find();
        self::assertIsArray($row);
        return $row;
    }

    private function evidenceCount(): int
    {
        return (int)Db::name('operation_execution_evidence')
            ->where('task_id', self::TASK_ID)
            ->count();
    }

    /** @return array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string} */
    private static function factors(
        string $actorScope,
        string $dataCompleteness,
        string $freshness,
        string $upstreamState
    ): array {
        return [
            'actor_scope' => $actorScope,
            'data_completeness' => $dataCompleteness,
            'freshness' => $freshness,
            'upstream_state' => $upstreamState,
        ];
    }

    private static function createSchema(): void
    {
        Db::execute(<<<'SQL'
CREATE TABLE operation_execution_intents (
    id INTEGER PRIMARY KEY,
    tenant_id INTEGER,
    source_module TEXT NOT NULL DEFAULT '',
    source_record_id INTEGER NOT NULL DEFAULT 0,
    hotel_id INTEGER NOT NULL,
    platform TEXT NOT NULL DEFAULT '',
    object_type TEXT NOT NULL DEFAULT '',
    action_type TEXT NOT NULL DEFAULT '',
    date_start TEXT,
    date_end TEXT,
    current_value_json TEXT,
    target_value_json TEXT,
    evidence_json TEXT,
    expected_metric TEXT NOT NULL DEFAULT '',
    expected_delta REAL NOT NULL DEFAULT 0,
    risk_level TEXT NOT NULL DEFAULT 'medium',
    blocked_reason TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL,
    created_by INTEGER NOT NULL DEFAULT 0,
    approved_by INTEGER NOT NULL DEFAULT 0,
    approved_at TEXT,
    review_remark TEXT NOT NULL DEFAULT '',
    created_at TEXT,
    updated_at TEXT,
    deleted_at TEXT
)
SQL);
        Db::execute(<<<'SQL'
CREATE TABLE operation_execution_tasks (
    id INTEGER PRIMARY KEY,
    tenant_id INTEGER,
    intent_id INTEGER NOT NULL,
    hotel_id INTEGER NOT NULL,
    execution_mode TEXT NOT NULL DEFAULT 'manual',
    operator_id INTEGER NOT NULL DEFAULT 0,
    target_value_json TEXT,
    current_value_json TEXT,
    blocked_reason TEXT NOT NULL DEFAULT '',
    action_track_id INTEGER NOT NULL DEFAULT 0,
    result_status TEXT NOT NULL DEFAULT 'observing',
    result_summary TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL,
    executed_at TEXT,
    created_at TEXT,
    updated_at TEXT,
    deleted_at TEXT
)
SQL);
        Db::execute(<<<'SQL'
CREATE TABLE operation_execution_evidence (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER,
    task_id INTEGER NOT NULL,
    evidence_type TEXT NOT NULL DEFAULT 'manual',
    before_json TEXT,
    after_json TEXT,
    attachment_path TEXT NOT NULL DEFAULT '',
    platform_response_json TEXT,
    remark TEXT NOT NULL DEFAULT '',
    created_by INTEGER NOT NULL DEFAULT 0,
    created_at TEXT,
    updated_at TEXT,
    deleted_at TEXT
)
SQL);
    }
}
