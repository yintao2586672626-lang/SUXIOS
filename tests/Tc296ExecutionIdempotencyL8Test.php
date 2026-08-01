<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperationManagementService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;
use Throwable;

final class Tc296ExecutionIdempotencyL8Test extends TestCase
{
    private const HOTEL_ID = 296;
    private const OUT_OF_SCOPE_HOTEL_ID = 1296;
    private const TENANT_ID = 296;
    private const OPERATOR_ID = 2960;
    private const REPLAY_SENTINEL = '2026-01-01 00:00:00';

    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        (new App(dirname(__DIR__)))->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . '/tc296_execution_idempotency_l8_' . getmypid() . '.sqlite';
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
            throw new RuntimeException('Unable to remove TC-296 SQLite fixture.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            'operation_execution_evidence',
            'operation_execution_tasks',
            'operation_execution_intents',
            'operation_action_tracks',
        ] as $table) {
            Db::name($table)->delete(true);
        }
    }

    /**
     * Restricted replay variants first establish the old request with an
     * authorized actor, then verify that an out-of-scope actor cannot replay it.
     *
     * @param array{actor_scope: string, data_completeness: string, freshness: string, upstream_state: string} $factors
     */
    #[DataProvider('l8VariantProvider')]
    public function testTc296ExecutionSideEffectsAreIdempotent(string $caseId, array $factors): void
    {
        $taskId = $this->seedApprovedExecutionTask($caseId);
        $service = new OperationManagementService();
        $input = $this->executionInput($caseId, $factors);
        $message = $caseId . ' factors=' . json_encode($factors, JSON_UNESCAPED_SLASHES);
        $actorHotelIds = $factors['actor_scope'] === 'authorized'
            ? [self::HOTEL_ID]
            : [self::OUT_OF_SCOPE_HOTEL_ID];

        if ($factors['freshness'] === 'stale') {
            $first = $service->executeExecutionTask(
                $taskId,
                [self::HOTEL_ID],
                $input,
                self::OPERATOR_ID
            );
            $this->assertFirstRequestOutcome($first, $taskId, $factors, $message);

            // A stale marker makes a second task write observable even when both
            // requests happen within the same wall-clock second.
            Db::name('operation_execution_tasks')->where('id', $taskId)->update([
                'updated_at' => self::REPLAY_SENTINEL,
            ]);
            $beforeReplay = $this->sideEffectSnapshot($taskId);

            [$replayResult, $replayError] = $this->attemptExecution(
                $service,
                $taskId,
                $actorHotelIds,
                $input
            );
            $afterReplay = $this->sideEffectSnapshot($taskId);

            if ($factors['actor_scope'] === 'restricted') {
                $this->assertScopeRejection($replayError, $message);
                self::assertSame($beforeReplay, $afterReplay, $message . ' restricted replay changed local side effects');
                $this->assertNoCredentialMaterial([$replayResult, $afterReplay], $message);
                return;
            }

            if ($replayError !== null) {
                self::assertMatchesRegularExpression(
                    '/idempot|duplicate|already|conflict|replay|terminal|executed|failed/i',
                    $replayError->getMessage(),
                    $message . ' replay rejection is not an explicit idempotency conflict'
                );
                self::assertSame($beforeReplay, $afterReplay, $message . ' rejected replay changed local side effects');
                $this->assertNoCredentialMaterial($afterReplay, $message);
                return;
            }

            self::assertIsArray($replayResult, $message);
            self::assertSame(
                $beforeReplay,
                $afterReplay,
                $message . ' replay was accepted but task/evidence/action_track side effects were not idempotent'
            );
            $this->assertNoCredentialMaterial([$replayResult, $afterReplay], $message);
            return;
        }

        $beforeRequest = $this->sideEffectSnapshot($taskId);
        [$result, $error] = $this->attemptExecution($service, $taskId, $actorHotelIds, $input);
        if ($factors['actor_scope'] === 'restricted') {
            $this->assertScopeRejection($error, $message);
            self::assertSame(
                $beforeRequest,
                $this->sideEffectSnapshot($taskId),
                $message . ' restricted first request changed local side effects'
            );
            $this->assertNoCredentialMaterial($this->sideEffectSnapshot($taskId), $message);
            return;
        }

        self::assertNull($error, $message . ' authorized first request was unexpectedly rejected');
        self::assertIsArray($result, $message);
        $this->assertFirstRequestOutcome($result, $taskId, $factors, $message);
    }

    /**
     * @return array<string, array{0: string, 1: array{actor_scope: string, data_completeness: string, freshness: string, upstream_state: string}}>
     */
    public static function l8VariantProvider(): array
    {
        return [
            'DX-2361 authorized complete fresh success' => ['DX-2361', self::factors('authorized', 'complete', 'fresh', 'success')],
            'DX-2362 authorized complete stale failure' => ['DX-2362', self::factors('authorized', 'complete', 'stale', 'failure')],
            'DX-2363 authorized missing fresh failure' => ['DX-2363', self::factors('authorized', 'missing_required', 'fresh', 'failure')],
            'DX-2364 authorized missing stale success' => ['DX-2364', self::factors('authorized', 'missing_required', 'stale', 'success')],
            'DX-2365 restricted complete fresh failure' => ['DX-2365', self::factors('restricted', 'complete', 'fresh', 'failure')],
            'DX-2366 restricted complete stale success' => ['DX-2366', self::factors('restricted', 'complete', 'stale', 'success')],
            'DX-2367 restricted missing fresh success' => ['DX-2367', self::factors('restricted', 'missing_required', 'fresh', 'success')],
            'DX-2368 restricted missing stale failure' => ['DX-2368', self::factors('restricted', 'missing_required', 'stale', 'failure')],
        ];
    }

    private static function createSchema(): void
    {
        Db::execute('CREATE TABLE operation_execution_intents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER,
            source_module VARCHAR(80),
            source_record_id INTEGER,
            hotel_id INTEGER NOT NULL,
            platform VARCHAR(40),
            object_type VARCHAR(40),
            action_type VARCHAR(80),
            date_start DATE,
            date_end DATE,
            current_value_json TEXT,
            target_value_json TEXT,
            evidence_json TEXT,
            expected_metric VARCHAR(80),
            expected_delta DECIMAL(12,4),
            risk_level VARCHAR(20),
            blocked_reason TEXT,
            status VARCHAR(30) NOT NULL,
            created_by INTEGER,
            approved_by INTEGER,
            approved_at DATETIME,
            review_remark TEXT,
            created_at DATETIME,
            updated_at DATETIME,
            deleted_at DATETIME
        )');
        Db::execute('CREATE TABLE operation_execution_tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER,
            intent_id INTEGER NOT NULL,
            hotel_id INTEGER NOT NULL,
            execution_mode VARCHAR(30),
            target_value_json TEXT,
            current_value_json TEXT,
            status VARCHAR(30) NOT NULL,
            operator_id INTEGER,
            action_track_id INTEGER,
            blocked_reason TEXT,
            result_status VARCHAR(30),
            result_summary TEXT,
            executed_at DATETIME,
            created_at DATETIME,
            updated_at DATETIME,
            deleted_at DATETIME
        )');
        Db::execute('CREATE TABLE operation_execution_evidence (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER,
            task_id INTEGER NOT NULL,
            evidence_type VARCHAR(40),
            before_json TEXT,
            after_json TEXT,
            attachment_path TEXT,
            platform_response_json TEXT,
            remark TEXT,
            created_by INTEGER,
            created_at DATETIME,
            updated_at DATETIME,
            deleted_at DATETIME
        )');
        Db::execute('CREATE TABLE operation_action_tracks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER,
            hotel_id INTEGER NOT NULL,
            action_type VARCHAR(80),
            action_title VARCHAR(160),
            start_date DATE,
            end_date DATE,
            target_metric VARCHAR(80),
            target_change_rate DECIMAL(12,4),
            before_data_json TEXT,
            after_data_json TEXT,
            result_status VARCHAR(30),
            result_summary TEXT,
            remark TEXT,
            status VARCHAR(30),
            created_at DATETIME,
            updated_at DATETIME
        )');
    }

    private function seedApprovedExecutionTask(string $caseId): int
    {
        $now = '2026-07-15 10:00:00';
        $intentId = (int)Db::name('operation_execution_intents')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'source_module' => 'tc296_fixture',
            'source_record_id' => (int)substr($caseId, 3),
            'hotel_id' => self::HOTEL_ID,
            'platform' => 'internal',
            'object_type' => 'campaign',
            'action_type' => 'manual_campaign_verification',
            'date_start' => '2026-07-15',
            'date_end' => '2026-07-15',
            'current_value_json' => json_encode(['orders' => 10], JSON_THROW_ON_ERROR),
            'target_value_json' => json_encode([
                'campaign_type' => 'manual_fixture',
                'target_metric' => 'orders',
                'target_orders' => 12,
            ], JSON_THROW_ON_ERROR),
            'evidence_json' => json_encode([
                'source_scope' => 'test_only_sqlite',
                'external_ota_write' => false,
            ], JSON_THROW_ON_ERROR),
            'expected_metric' => 'orders',
            'expected_delta' => 2,
            'risk_level' => 'low',
            'blocked_reason' => '',
            'status' => 'approved',
            'created_by' => self::OPERATOR_ID,
            'approved_by' => self::OPERATOR_ID,
            'approved_at' => $now,
            'review_remark' => 'TC-296 test-only approval',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int)Db::name('operation_execution_tasks')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'intent_id' => $intentId,
            'hotel_id' => self::HOTEL_ID,
            'execution_mode' => 'manual',
            'target_value_json' => json_encode(['target_orders' => 12], JSON_THROW_ON_ERROR),
            'current_value_json' => json_encode(['orders' => 10], JSON_THROW_ON_ERROR),
            'status' => 'pending_execute',
            'operator_id' => 0,
            'action_track_id' => 0,
            'blocked_reason' => '',
            'result_status' => '',
            'result_summary' => '',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param array{actor_scope: string, data_completeness: string, freshness: string, upstream_state: string} $factors
     * @return array<string, mixed>
     */
    private function executionInput(string $caseId, array $factors): array
    {
        $executionId = 'tc296-' . strtolower($caseId) . '-execution';
        $status = $factors['upstream_state'] === 'success' ? 'executed' : 'failed';
        $input = [
            'execution_id' => $executionId,
            'status' => $status,
            'current_value' => ['orders' => 10, 'scope' => 'test_only'],
            'target_value' => ['orders' => 12, 'scope' => 'test_only'],
        ];

        if ($factors['data_completeness'] === 'complete') {
            $input['evidence_type'] = 'manual_test_receipt';
            $input['evidence'] = [
                'before' => ['orders' => 10, 'scope' => 'test_only'],
                'after' => [
                    'orders' => $status === 'executed' ? 12 : 10,
                    'status' => $status,
                    'scope' => 'test_only',
                ],
                'platform_response' => [
                    'execution_id' => $executionId,
                    'receipt_id' => 'receipt-' . strtolower($caseId),
                    'reported_at' => '2026-07-15T10:01:00+08:00',
                    'outcome' => $factors['upstream_state'],
                    'mode' => 'test_fixture',
                    'external_write' => false,
                ],
                'remark' => 'TC-296 local SQLite execution receipt',
            ];
        }

        return $input;
    }

    /**
     * @param array<int> $hotelIds
     * @param array<string, mixed> $input
     * @return array{0: ?array, 1: ?Throwable}
     */
    private function attemptExecution(
        OperationManagementService $service,
        int $taskId,
        array $hotelIds,
        array $input
    ): array {
        try {
            return [
                $service->executeExecutionTask($taskId, $hotelIds, $input, self::OPERATOR_ID),
                null,
            ];
        } catch (Throwable $error) {
            return [null, $error];
        }
    }

    /**
     * @param array<string, mixed> $result
     * @param array{actor_scope: string, data_completeness: string, freshness: string, upstream_state: string} $factors
     */
    private function assertFirstRequestOutcome(
        array $result,
        int $taskId,
        array $factors,
        string $message
    ): void {
        $complete = $factors['data_completeness'] === 'complete';
        $success = $factors['upstream_state'] === 'success';
        $expectedStatus = $complete ? ($success ? 'executed' : 'failed') : 'blocked';
        $expectedEvidenceCount = $complete ? 1 : 0;
        $expectedActionTrackCount = $expectedStatus === 'executed' ? 1 : 0;

        self::assertSame($expectedStatus, $result['status'] ?? null, $message);
        self::assertSame(1, Db::name('operation_execution_tasks')->where('id', $taskId)->count(), $message);
        self::assertSame(
            $expectedEvidenceCount,
            Db::name('operation_execution_evidence')->where('task_id', $taskId)->count(),
            $message
        );
        self::assertSame(
            $expectedActionTrackCount,
            Db::name('operation_action_tracks')->where('hotel_id', self::HOTEL_ID)->count(),
            $message
        );

        if ($complete) {
            self::assertCount(1, $result['evidence'] ?? [], $message);
            $storedEvidence = $result['evidence'][0];
            self::assertNotEmpty($storedEvidence['before'] ?? [], $message);
            self::assertNotEmpty($storedEvidence['after'] ?? [], $message);
            self::assertNotEmpty($storedEvidence['remark'] ?? '', $message);
            self::assertSame(false, $storedEvidence['platform_response']['external_write'] ?? null, $message);
            self::assertNotEmpty($storedEvidence['platform_response']['execution_id'] ?? '', $message);
        } else {
            self::assertSame([], $result['evidence'] ?? [], $message);
            self::assertStringContainsString('evidence', (string)($result['blocked_reason'] ?? ''), $message);
        }

        $this->assertNoCredentialMaterial([$result, $this->sideEffectSnapshot($taskId)], $message);
    }

    private function assertScopeRejection(?Throwable $error, string $message): void
    {
        self::assertNotNull($error, $message . ' out-of-scope hotelIds unexpectedly reached execution');
        self::assertMatchesRegularExpression(
            '/not found|not permitted|forbidden/i',
            $error->getMessage(),
            $message . ' scope rejection was not explicit'
        );
    }

    /** @return array<string, mixed> */
    private function sideEffectSnapshot(int $taskId): array
    {
        return [
            'task_count' => (int)Db::name('operation_execution_tasks')->where('id', $taskId)->count(),
            'task' => Db::name('operation_execution_tasks')->where('id', $taskId)->find(),
            'evidence' => Db::name('operation_execution_evidence')
                ->where('task_id', $taskId)
                ->order('id', 'asc')
                ->select()
                ->toArray(),
            'action_tracks' => Db::name('operation_action_tracks')
                ->where('hotel_id', self::HOTEL_ID)
                ->order('id', 'asc')
                ->select()
                ->toArray(),
        ];
    }

    private function assertNoCredentialMaterial(mixed $value, string $message): void
    {
        $encoded = strtolower((string)json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));
        foreach (['authorization', 'cookie', 'password', 'access_token', 'refresh_token', 'mtgsig', 'client_secret'] as $needle) {
            self::assertStringNotContainsString($needle, $encoded, $message . ' leaked credential material: ' . $needle);
        }
    }

    /**
     * @return array{actor_scope: string, data_completeness: string, freshness: string, upstream_state: string}
     */
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
}
