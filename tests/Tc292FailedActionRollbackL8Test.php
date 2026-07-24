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

final class Tc292FailedActionRollbackL8Test extends TestCase
{
    private const HOTEL_ID = 7;
    private const OUT_OF_SCOPE_HOTEL_ID = 8;
    private const INTENT_ID = 29201;
    private const TASK_ID = 29202;
    private const USER_ID = 42;
    private const CURRENT_EVENT_AT = '2026-07-15 10:00:00';
    private const STALE_EVENT_AT = '2026-07-01 10:00:00';

    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . '/tc292_failed_action_rollback_l8_' . getmypid() . '.sqlite';
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
            throw new RuntimeException('Unable to remove TC-292 SQLite fixture.');
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
     * Restricted hotel scope short-circuits receipt validation. Stale takes
     * precedence over completeness because an old rollback receipt must never
     * mutate the current record, even when its shape is otherwise complete.
     *
     * @param array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string} $factors
     */
    #[DataProvider('l8VariantProvider')]
    public function testTc292FailedActionRequiresCurrentCompleteTraceableCompensationReceipt(
        string $caseId,
        array $factors
    ): void {
        $this->seedFailedAction($factors);
        $input = $this->compensationInput($caseId, $factors);
        $hotelIds = $factors['actor_scope'] === 'authorized'
            ? [self::HOTEL_ID]
            : [self::OUT_OF_SCOPE_HOTEL_ID];
        $message = $caseId . ' factors=' . json_encode($factors, JSON_UNESCAPED_SLASHES);
        $initialTask = $this->taskRow();
        $initialEvidenceCount = $this->evidenceCount();

        $this->assertFixtureRepresentsFactors($input, $factors, $message);
        self::assertSame('failed', $initialTask['status'], $message);

        if ($factors['actor_scope'] === 'restricted') {
            try {
                (new OperationManagementService())->addExecutionEvidence(
                    self::TASK_ID,
                    $hotelIds,
                    $input,
                    self::USER_ID
                );
                self::fail($message . ' restricted hotel scope unexpectedly saved compensation evidence');
            } catch (RuntimeException $exception) {
                self::assertSame('execution task not found', $exception->getMessage(), $message);
            }

            self::assertSame('failed', $this->taskRow()['status'], $message);
            self::assertSame($initialEvidenceCount, $this->evidenceCount(), $message);
            return;
        }

        if ($factors['freshness'] === 'stale') {
            $this->attemptExpectedReceiptRejection($input, $hotelIds, $message, 'stale');
            self::assertSame('failed', $this->taskRow()['status'], $message);
            self::assertSame(
                $initialEvidenceCount,
                $this->evidenceCount(),
                $message . ' old rollback receipt must not append or replace compensation evidence'
            );
            $this->assertCurrentCompensationEvidenceSurvives($factors, $message);
            return;
        }

        if ($factors['data_completeness'] === 'missing_required') {
            $this->attemptExpectedReceiptRejection($input, $hotelIds, $message, 'missing');
            self::assertSame('failed', $this->taskRow()['status'], $message);
            self::assertSame(
                0,
                $this->evidenceCount(),
                $message . ' incomplete compensation receipt must not be persisted'
            );
            return;
        }

        $result = (new OperationManagementService())->addExecutionEvidence(
            self::TASK_ID,
            $hotelIds,
            $input,
            self::USER_ID
        );

        // A successful compensation is not a successful original OTA action.
        self::assertSame('failed', $result['status'], $message);
        self::assertSame('failed', $this->taskRow()['status'], $message);
        self::assertSame(1, $this->evidenceCount(), $message);
        self::assertCount(1, $result['evidence'], $message);
        $receipt = $result['evidence'][0]['platform_response'];
        $this->assertTraceableReceipt($receipt, $factors, self::CURRENT_EVENT_AT, $message);
    }

    /**
     * @return array<string, array{0:string,1:array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string}}>
     */
    public static function l8VariantProvider(): array
    {
        return [
            'DX-2329 authorized complete fresh success' => ['DX-2329', self::factors('authorized', 'complete', 'fresh', 'success')],
            'DX-2330 authorized complete stale failure' => ['DX-2330', self::factors('authorized', 'complete', 'stale', 'failure')],
            'DX-2331 authorized missing fresh failure' => ['DX-2331', self::factors('authorized', 'missing_required', 'fresh', 'failure')],
            'DX-2332 authorized missing stale success' => ['DX-2332', self::factors('authorized', 'missing_required', 'stale', 'success')],
            'DX-2333 restricted complete fresh failure' => ['DX-2333', self::factors('restricted', 'complete', 'fresh', 'failure')],
            'DX-2334 restricted complete stale success' => ['DX-2334', self::factors('restricted', 'complete', 'stale', 'success')],
            'DX-2335 restricted missing fresh success' => ['DX-2335', self::factors('restricted', 'missing_required', 'fresh', 'success')],
            'DX-2336 restricted missing stale failure' => ['DX-2336', self::factors('restricted', 'missing_required', 'stale', 'failure')],
        ];
    }

    /** @param array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string} $factors */
    private function seedFailedAction(array $factors): void
    {
        Db::name('operation_execution_intents')->insert([
            'id' => self::INTENT_ID,
            'tenant_id' => 1,
            'hotel_id' => self::HOTEL_ID,
            'source_module' => 'revenue_ai',
            'source_record_id' => 292,
            'platform' => 'ctrip',
            'object_type' => 'price',
            'action_type' => 'price_adjust',
            'date_start' => '2026-07-15',
            'date_end' => '2026-07-15',
            'current_value_json' => json_encode(['price' => 208], JSON_THROW_ON_ERROR),
            'target_value_json' => json_encode(['price' => 188], JSON_THROW_ON_ERROR),
            'evidence_json' => json_encode(['case' => 'TC-292'], JSON_THROW_ON_ERROR),
            'expected_metric' => 'orders',
            'expected_delta' => 5,
            'risk_level' => 'high',
            'status' => 'approved',
            'created_by' => 9,
            'created_at' => '2026-07-15 08:00:00',
            'updated_at' => '2026-07-15 08:00:00',
            'deleted_at' => null,
        ]);

        Db::name('operation_execution_tasks')->insert([
            'id' => self::TASK_ID,
            'tenant_id' => 1,
            'intent_id' => self::INTENT_ID,
            'hotel_id' => self::HOTEL_ID,
            'execution_mode' => 'manual',
            'operator_id' => 11,
            'target_value_json' => json_encode(['price' => 188], JSON_THROW_ON_ERROR),
            'current_value_json' => json_encode(['price' => 198], JSON_THROW_ON_ERROR),
            'blocked_reason' => 'OTA action partially applied before failure',
            'action_track_id' => 0,
            'result_status' => 'failed',
            'result_summary' => 'one target applied and one target rejected',
            'status' => 'failed',
            'executed_at' => '2026-07-15 09:00:00',
            'created_at' => '2026-07-15 08:10:00',
            'updated_at' => '2026-07-15 09:00:00',
            'deleted_at' => null,
        ]);

        if ($factors['freshness'] === 'stale') {
            $currentStatus = $factors['upstream_state'] === 'success' ? 'success' : 'failure';
            Db::name('operation_execution_evidence')->insert([
                'id' => 29203,
                'tenant_id' => 1,
                'task_id' => self::TASK_ID,
                'evidence_type' => 'compensation_receipt',
                'before_json' => json_encode(['price' => 208], JSON_THROW_ON_ERROR),
                'after_json' => json_encode(['price' => 198], JSON_THROW_ON_ERROR),
                'attachment_path' => '',
                'platform_response_json' => json_encode($this->completeReceipt('current-' . $currentStatus, $currentStatus, self::CURRENT_EVENT_AT), JSON_THROW_ON_ERROR),
                'remark' => 'current compensation receipt already recorded',
                'created_by' => 11,
                'created_at' => self::CURRENT_EVENT_AT,
                'updated_at' => self::CURRENT_EVENT_AT,
                'deleted_at' => null,
            ]);
        }
    }

    /**
     * @param array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string} $factors
     * @return array<string,mixed>
     */
    private function compensationInput(string $caseId, array $factors): array
    {
        $eventAt = $factors['freshness'] === 'fresh' ? self::CURRENT_EVENT_AT : self::STALE_EVENT_AT;
        $receipt = $factors['data_completeness'] === 'complete'
            ? $this->completeReceipt($caseId, $factors['upstream_state'], $eventAt)
            : [
                'case_id' => $caseId,
                'partial' => true,
                'compensation_status' => $factors['upstream_state'],
                'event_at' => $eventAt,
            ];

        return [
            'evidence_type' => 'compensation_receipt',
            'evidence' => [
                'before' => ['price' => 208],
                'after' => ['price' => 198],
                'platform_response' => $receipt,
                'remark' => 'isolated rollback/compensation receipt; no external OTA call',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function completeReceipt(string $caseId, string $upstreamState, string $eventAt): array
    {
        return [
            'case_id' => $caseId,
            'partial' => true,
            'applied' => [
                ['room_type_key' => 'deluxe', 'rate_plan_key' => 'breakfast', 'price' => 198],
            ],
            'unapplied' => [
                ['room_type_key' => 'standard', 'rate_plan_key' => 'room_only', 'reason' => 'platform_rejected'],
            ],
            'affected_scope' => [
                'platform' => 'ctrip',
                'hotel_id' => self::HOTEL_ID,
                'business_date' => '2026-07-15',
            ],
            'compensation_status' => $upstreamState,
            'manual_required' => $upstreamState === 'failure',
            'event_at' => $eventAt,
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @param array<int,int> $hotelIds
     */
    private function attemptExpectedReceiptRejection(
        array $input,
        array $hotelIds,
        string $message,
        string $reason
    ): void {
        try {
            (new OperationManagementService())->addExecutionEvidence(
                self::TASK_ID,
                $hotelIds,
                $input,
                self::USER_ID
            );
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $pattern = $reason === 'stale'
                ? '/stale|old|date|receipt|compensation/i'
                : '/missing|required|receipt|compensation|evidence/i';
            self::assertMatchesRegularExpression($pattern, $exception->getMessage(), $message);
        }
    }

    /**
     * @param array<string,mixed> $input
     * @param array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string} $factors
     */
    private function assertFixtureRepresentsFactors(array $input, array $factors, string $message): void
    {
        $receipt = $input['evidence']['platform_response'];
        self::assertSame($factors['upstream_state'], $receipt['compensation_status'], $message);
        self::assertSame(
            $factors['freshness'] === 'fresh' ? self::CURRENT_EVENT_AT : self::STALE_EVENT_AT,
            $receipt['event_at'],
            $message
        );
        self::assertSame(
            $factors['data_completeness'] === 'complete',
            array_key_exists('affected_scope', $receipt),
            $message
        );
        self::assertSame(
            $factors['data_completeness'] === 'complete',
            array_key_exists('manual_required', $receipt),
            $message
        );
    }

    /** @param array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string} $factors */
    private function assertCurrentCompensationEvidenceSurvives(array $factors, string $message): void
    {
        $row = Db::name('operation_execution_evidence')
            ->where('task_id', self::TASK_ID)
            ->order('id', 'desc')
            ->find();
        self::assertIsArray($row, $message);
        $receipt = json_decode((string)$row['platform_response_json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertTraceableReceipt($receipt, $factors, self::CURRENT_EVENT_AT, $message);
    }

    /**
     * @param array<string,mixed> $receipt
     * @param array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string} $factors
     */
    private function assertTraceableReceipt(
        array $receipt,
        array $factors,
        string $expectedEventAt,
        string $message
    ): void {
        self::assertTrue($receipt['partial'], $message);
        self::assertNotEmpty($receipt['applied'], $message);
        self::assertNotEmpty($receipt['unapplied'], $message);
        self::assertSame('ctrip', $receipt['affected_scope']['platform'], $message);
        self::assertSame(self::HOTEL_ID, $receipt['affected_scope']['hotel_id'], $message);
        self::assertSame($factors['upstream_state'], $receipt['compensation_status'], $message);
        self::assertSame($factors['upstream_state'] === 'failure', $receipt['manual_required'], $message);
        self::assertSame($expectedEventAt, $receipt['event_at'], $message);
    }

    /** @return array<string,mixed> */
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
