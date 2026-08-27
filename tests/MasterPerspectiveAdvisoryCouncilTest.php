<?php
declare(strict_types=1);

namespace Tests;

use app\service\LocalAiRuntimeService;
use app\service\MasterPerspectiveAdvisoryCatalog;
use app\service\OperatingQuestionCouncilService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class MasterPerspectiveAdvisoryCouncilTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App(dirname(__DIR__));
        $app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'master_perspective_council_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.sqlite';
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
    }

    public static function tearDownAfterClass(): void
    {
        try {
            Db::connect('sqlite')->close();
        } catch (\Throwable) {
        }
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$sqlitePath);
    }

    protected function setUp(): void
    {
        Db::execute('DROP TABLE IF EXISTS hotel_operating_question_council_runs');
        Db::execute('DROP TABLE IF EXISTS hotel_operating_questions');
        Db::execute('DROP TABLE IF EXISTS online_daily_data');
        Db::execute('DROP TABLE IF EXISTS hotels');
        Db::execute(
            'CREATE TABLE hotel_operating_question_council_runs ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, question_id INTEGER, '
            . 'request_key TEXT, mode TEXT, status TEXT, members_json TEXT, synthesis_json TEXT, '
            . 'evidence_refs_json TEXT, model_meta_json TEXT, decision_effect TEXT, content_digest TEXT, '
            . 'created_by INTEGER, created_at TEXT, updated_at TEXT, '
            . 'UNIQUE(tenant_id,hotel_id,question_id,request_key))'
        );
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER, status INTEGER)');
        Db::execute(
            'CREATE TABLE hotel_operating_questions ('
            . 'id INTEGER PRIMARY KEY, tenant_id INTEGER, hotel_id INTEGER)'
        );
        Db::execute(
            'CREATE TABLE online_daily_data ('
            . 'id INTEGER PRIMARY KEY, tenant_id INTEGER, system_hotel_id INTEGER, data_date TEXT, '
            . 'platform TEXT, source TEXT, data_type TEXT, dimension TEXT, validation_status TEXT, '
            . 'history_status TEXT, readback_verified INTEGER, readback_verified_at TEXT, '
            . 'ingestion_method TEXT, source_trace_id TEXT, list_exposure INTEGER)'
        );
        Db::name('hotels')->insert(['id' => 20, 'tenant_id' => 10, 'status' => 1]);
        Db::name('hotels')->insert(['id' => 21, 'tenant_id' => 10, 'status' => 1]);
        Db::name('hotel_operating_questions')->insert(['id' => 41, 'tenant_id' => 10, 'hotel_id' => 20]);
        Db::name('hotel_operating_questions')->insert(['id' => 42, 'tenant_id' => 10, 'hotel_id' => 20]);
        Db::name('hotel_operating_questions')->insert(['id' => 43, 'tenant_id' => 10, 'hotel_id' => 20]);
    }

    public function testCatalogSelectsBoundedProblemRelevantLensesWithoutClaimingHumanAuthority(): void
    {
        $catalog = new MasterPerspectiveAdvisoryCatalog();
        $panel = $catalog->select('店长团队处理客诉时涉及员工评分和隐私权限，怎么形成公平流程？');
        $keys = array_column($panel['selected_lenses'], 'key');

        self::assertSame(MasterPerspectiveAdvisoryCatalog::SOURCE_OUTER_ZIP_SHA256, $panel['source']['outer_zip_sha256']);
        self::assertSame(165, $panel['source']['source_entry_count']);
        self::assertSame('hash_verified_binary_duplicate', $panel['source']['attachment_status']);
        self::assertContains('evidence_and_uncertainty', $keys);
        self::assertContains('customer_and_value', $keys);
        self::assertContains('communication_and_alignment', $keys);
        self::assertContains('ethics_and_fairness', $keys);
        self::assertLessThanOrEqual(5, count($keys));
        self::assertCount(count(array_unique($keys)), $keys);
        self::assertTrue($panel['boundaries']['reference_lens_only']);
        self::assertFalse($panel['boundaries']['personality_impersonation']);
        self::assertFalse($panel['boundaries']['real_human_opinion']);
        self::assertFalse($panel['boundaries']['automatic_action']);

        $generic = $catalog->select('今天这组已验证数据应该怎么看？');
        self::assertSame(
            ['evidence_and_uncertainty', 'customer_and_value', 'risk_and_resilience'],
            array_column($generic['selected_lenses'], 'key')
        );
    }

    public function testCouncilSavesSelectedLensAdviceAndReturnsExactIdempotentReadback(): void
    {
        $fakeClient = $this->fakeClient();
        $question = $this->readyQuestion();
        $service = new OperatingQuestionCouncilService(
            $fakeClient,
            static fn(): array => ['text' => ['ready' => true]],
            static fn(int $questionId, int $tenantId, array $hotelIds): array => $question,
            null,
            $this->strictFactReader($question)
        );

        $saved = $service->runShadow(41, 10, [20], 7, 'advisory1234');

        self::assertSame(OperatingQuestionCouncilService::CONTRACT_VERSION, $saved['contract_version']);
        self::assertSame('completed', $saved['status']);
        self::assertSame('readback_verified', $saved['persistence_status']);
        self::assertTrue($saved['created']);
        self::assertSame('none', $saved['decision_effect']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $saved['content_digest']);
        self::assertSame(OperatingQuestionCouncilService::CONTRACT_VERSION, $saved['persisted_contract_version']);
        self::assertFalse($saved['legacy_migration_required']);
        self::assertCount(5, $saved['members']);
        self::assertSame(6, $fakeClient->calls);
        self::assertSame(165, $saved['synthesis']['advisory_source']['source_entry_count']);
        self::assertSame(
            'primary_action_draft_requires_user_trigger',
            $saved['synthesis']['execution_handoff']['status']
        );
        self::assertTrue($saved['synthesis']['execution_handoff']['user_trigger_required']);
        self::assertFalse($saved['synthesis']['execution_handoff']['automatic_execution']);
        self::assertFalse($saved['boundaries']['action_creation_allowed']);
        self::assertFalse($saved['boundaries']['real_human_consensus']);
        self::assertFalse($saved['members'][0]['real_human_opinion']);
        self::assertNotEmpty($saved['members'][0]['source_lenses']);
        self::assertSame('verified_scope_guard_passed', $saved['members'][0]['grounding_status']);
        self::assertFalse($saved['members'][0]['causality_claimed']);
        self::assertFalse($saved['members'][0]['outcome_claimed']);
        self::assertSame(['online_daily_data#9001'], $saved['evidence_refs']);
        self::assertSame('verified', $saved['synthesis']['artifact_integrity']['status']);
        self::assertSame(5, $saved['synthesis']['artifact_integrity']['member_count']);
        self::assertSame(1, $saved['synthesis']['artifact_integrity']['evidence_ref_count']);

        $replayed = $service->runShadow(41, 10, [20], 7, 'advisory1234');
        self::assertFalse($replayed['created']);
        self::assertSame('readback_verified', $replayed['persistence_status']);
        self::assertSame($saved['id'], $replayed['id']);
        self::assertSame($saved['content_digest'], $replayed['content_digest']);
        self::assertSame(6, $fakeClient->calls, '幂等回读不得再次调用模型');

        $latest = $service->latest(41, 10, [20]);
        self::assertSame($saved['id'], $latest['id']);
        self::assertSame($saved['content_digest'], $latest['content_digest']);
    }

    public function testCouncilReserveIsModelFreeAtomicAndDispatchesOnlyTheCreatedRun(): void
    {
        $client = $this->fakeClient();
        $question = $this->readyQuestion();
        $capabilityCalls = 0;
        $factCalls = 0;
        $launches = [];
        $service = null;
        $service = new OperatingQuestionCouncilService(
            $client,
            static function () use (&$capabilityCalls): array {
                $capabilityCalls++;
                return ['text' => ['ready' => true]];
            },
            static fn(int $questionId, int $tenantId, array $hotelIds): array => $question,
            null,
            static function () use (&$factCalls): array {
                $factCalls++;
                return [];
            },
            static function (
                int $runId,
                int $tenantId,
                int $hotelId,
                string $parentDigest,
                bool $retryFailed
            ) use (&$launches, &$service): array {
                $launches[] = [$runId, $tenantId, $hotelId, $parentDigest, $retryFailed];
                $service->claimRunForWorker(
                    $runId,
                    $tenantId,
                    [$hotelId],
                    $parentDigest,
                    $retryFailed,
                    'test-worker-lease-' . count($launches)
                );
                return ['started' => true, 'exit_code' => null];
            }
        );

        $reserved = $service->reserveShadow(41, 10, [20], 7, 'async-reserve-1234');
        $duplicate = $service->reserveShadow(41, 10, [20], 7, 'async-reserve-1234');
        $differentKey = $service->reserveShadow(41, 10, [20], 7, 'different-active-key', false);

        self::assertSame('running', $reserved['status']);
        self::assertTrue($reserved['created']);
        self::assertTrue($reserved['accepted']);
        self::assertTrue($reserved['worker_dispatched']);
        self::assertSame('running', $reserved['synthesis']['worker']['status']);
        self::assertSame('acknowledged', $reserved['worker_receipt']['status']);
        self::assertFalse($duplicate['created']);
        self::assertFalse($duplicate['worker_dispatched']);
        self::assertSame('already_running', $duplicate['worker_receipt']['status']);
        self::assertFalse($duplicate['worker_receipt']['acknowledged']);
        self::assertTrue($duplicate['worker_receipt']['existing_active_worker']);
        self::assertSame((int)$reserved['id'], (int)$duplicate['id']);
        self::assertSame((int)$reserved['id'], (int)$differentKey['id']);
        self::assertTrue($differentKey['reused_active']);
        self::assertSame($reserved['request_key'], $differentKey['request_key']);
        self::assertSame(1, (int)Db::name(OperatingQuestionCouncilService::TABLE)->count());
        self::assertSame(0, $client->calls, 'HTTP reserve must not call the model');
        self::assertSame(0, $capabilityCalls, 'HTTP reserve must not probe model runtime');
        self::assertSame(0, $factCalls, 'strict fact readback belongs to the worker');
        self::assertCount(1, $launches);
        self::assertSame([(int)$reserved['id'], 10, 20], array_slice($launches[0], 0, 3));

        $exact = $service->read((int)$reserved['id'], 10, [20]);
        self::assertSame($reserved['content_digest'], $exact['content_digest']);
        self::assertSame('readback_verified', $reserved['persistence_status']);

        $this->expireCouncilWorkerLease($service, (int)$reserved['id']);
        $staleRetry = $service->reserveShadow(41, 10, [20], 7, 'async-reserve-1234');
        self::assertFalse($staleRetry['created']);
        self::assertTrue($staleRetry['worker_dispatched']);
        self::assertSame(2, count($launches), 'a stale pending run must be safely redispatched');
        self::assertSame(1, (int)Db::name(OperatingQuestionCouncilService::TABLE)->count());

        $this->expireCouncilWorkerLease($service, (int)$reserved['id']);
        $failedStaleService = new OperatingQuestionCouncilService(
            $client,
            static fn(): array => ['text' => ['ready' => true]],
            static fn(int $questionId, int $tenantId, array $hotelIds): array => $question,
            null,
            $this->strictFactReader($question),
            static fn(): array => ['started' => true, 'exit_code' => 7]
        );
        $failedStale = $failedStaleService->reserveShadow(41, 10, [20], 7, 'async-reserve-1234');
        self::assertSame('running', $failedStale['status']);
        self::assertFalse($failedStale['worker_dispatched']);
        self::assertSame('worker_exited_before_ack', $failedStale['worker_receipt']['status']);
        self::assertTrue($failedStale['worker_receipt']['persisted']);
        self::assertSame(
            'worker_exited_before_ack',
            $failedStale['synthesis']['worker']['dispatch_history'][0]['status']
        );
        self::assertSame(2, $failedStale['synthesis']['worker']['lease_generation']);

        $question42 = $question;
        $question42['id'] = 42;
        $failedDispatchService = new OperatingQuestionCouncilService(
            $client,
            static fn(): array => ['text' => ['ready' => true]],
            static fn(int $questionId, int $tenantId, array $hotelIds): array => $question42,
            null,
            $this->strictFactReader($question42),
            static fn(int $runId, int $tenantId, int $hotelId, string $parentDigest, bool $retryFailed): array => [
                'started' => false,
                'exit_code' => 7,
            ]
        );
        $failedDispatch = $failedDispatchService->reserveShadow(42, 10, [20], 7, 'async-dispatch-fail');
        self::assertSame('pending', $failedDispatch['status']);
        self::assertTrue($failedDispatch['accepted']);
        self::assertSame('readback_verified', $failedDispatch['persistence_status']);
        self::assertFalse($failedDispatch['worker_dispatched']);
        self::assertSame('queued', $failedDispatch['synthesis']['worker']['status']);
        self::assertSame('dispatch_failed', $failedDispatch['synthesis']['worker']['stage']);
        self::assertSame('council_worker_dispatch_failed', $failedDispatch['synthesis']['error_code']);
        self::assertSame('worker_exited_before_ack', $failedDispatch['worker_receipt']['status']);
        self::assertSame(0, $client->calls);

        $retryDispatchService = null;
        $retryDispatchService = new OperatingQuestionCouncilService(
            $client,
            static fn(): array => ['text' => ['ready' => true]],
            static fn(int $questionId, int $tenantId, array $hotelIds): array => $question42,
            null,
            $this->strictFactReader($question42),
            static function (
                int $runId,
                int $tenantId,
                int $hotelId,
                string $parentDigest,
                bool $retryFailed
            ) use (&$retryDispatchService): array {
                $retryDispatchService->claimRunForWorker(
                    $runId,
                    $tenantId,
                    [$hotelId],
                    $parentDigest,
                    $retryFailed,
                    'retry-after-old-failure-token'
                );
                return ['started' => true, 'exit_code' => null];
            }
        );
        $retriedDispatch = $retryDispatchService->reserveShadow(42, 10, [20], 7, 'another-client-key');
        self::assertSame((int)$failedDispatch['id'], (int)$retriedDispatch['id']);
        self::assertTrue($retriedDispatch['reused_active']);
        self::assertTrue($retriedDispatch['worker_dispatched']);
        self::assertSame('acknowledged', $retriedDispatch['worker_receipt']['status']);
        self::assertNotSame(
            $failedDispatch['worker_receipt']['dispatch_attempt_id'],
            $retriedDispatch['worker_receipt']['dispatch_attempt_id']
        );
        self::assertCount(1, $retriedDispatch['synthesis']['worker']['dispatch_history']);

        $question43 = $question;
        $question43['id'] = 43;
        $caseService = new OperatingQuestionCouncilService(
            $client,
            static fn(): array => ['text' => ['ready' => true]],
            static fn(int $questionId, int $tenantId, array $hotelIds): array => $question43,
            null,
            $this->strictFactReader($question43)
        );
        $caseVariantA = $caseService->reserveShadow(43, 10, [20], 7, 'Case-Key-1234', false);
        $caseVariantB = $caseService->reserveShadow(43, 10, [20], 7, 'case-key-1234', false);
        self::assertSame((int)$caseVariantA['id'], (int)$caseVariantB['id']);
        self::assertSame('council:case-key-1234', $caseVariantA['request_key']);
        self::assertSame($caseVariantA['request_key'], $caseVariantB['request_key']);
    }

    public function testCouncilWorkerReusesReadyLensCheckpointWhenRetryingFailedRun(): void
    {
        $question = $this->readyQuestion();
        $healthy = $this->fakeClient();
        $recoverable = new class($healthy) {
            public int $calls = 0;

            public function __construct(private object $healthy)
            {
            }

            public function createJsonResponseEnvelope(array $messages, array $schema, string $modelKey): array
            {
                $this->calls++;
                if ($this->calls >= 2 && $this->calls <= 5) {
                    throw new RuntimeException('synthetic worker interruption');
                }
                return $this->healthy->createJsonResponseEnvelope($messages, $schema, $modelKey);
            }
        };
        $service = new OperatingQuestionCouncilService(
            $recoverable,
            static fn(): array => ['text' => ['ready' => true]],
            static fn(int $questionId, int $tenantId, array $hotelIds): array => $question,
            null,
            $this->strictFactReader($question),
            static fn(): bool => true
        );
        $reserved = $service->reserveShadow(41, 10, [20], 7, 'async-retry-1234', false);

        $partial = $service->processRun((int)$reserved['id'], 10, [20]);
        self::assertSame('partial', $partial['status']);
        self::assertSame(6, $recoverable->calls);
        self::assertSame('ready', $partial['members'][0]['status']);
        self::assertCount(4, array_filter(
            $partial['members'],
            static fn(array $member): bool => ($member['status'] ?? '') === 'failed'
        ));
        try {
            $service->resumeRun((int)$reserved['id'], 42, 10, [20]);
            self::fail('A resume request for another question must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('不可恢复', $e->getMessage());
        }

        $resumeService = null;
        $resumeService = new OperatingQuestionCouncilService(
            $recoverable,
            static fn(): array => ['text' => ['ready' => true]],
            static fn(int $questionId, int $tenantId, array $hotelIds): array => $question,
            null,
            $this->strictFactReader($question),
            static function (
                int $runId,
                int $tenantId,
                int $hotelId,
                string $parentDigest,
                bool $retryFailed
            ) use (&$resumeService): array {
                $resumeService->processRun($runId, $tenantId, [$hotelId], $retryFailed, $parentDigest);
                return ['started' => true, 'exit_code' => null];
            }
        );
        $completed = $resumeService->resumeRun((int)$reserved['id'], 41, 10, [20]);
        self::assertSame('completed', $completed['status']);
        self::assertSame('acknowledged', $completed['worker_receipt']['status']);
        self::assertTrue($completed['accepted']);
        self::assertSame(11, $recoverable->calls, 'one ready lens checkpoint must be reused');
        self::assertCount(5, array_filter(
            $completed['members'],
            static fn(array $member): bool => ($member['status'] ?? '') === 'ready'
        ));
        self::assertSame('completed', $completed['synthesis']['worker']['status']);
        self::assertSame(1, (int)Db::name(OperatingQuestionCouncilService::TABLE)->count());

        $duplicateWorker = $resumeService->processRun((int)$reserved['id'], 10, [20]);
        self::assertSame('completed', $duplicateWorker['status']);
        self::assertSame('terminal', $duplicateWorker['worker_status']);
        self::assertSame(11, $recoverable->calls, 'terminal duplicate worker must not call a model');
    }

    public function testCouncilResumeRejectsInternallyConsistentLegacyPanelCheckpointContract(): void
    {
        $question = $this->readyQuestion();
        $healthy = $this->fakeClient();
        $recoverable = new class($healthy) {
            public int $calls = 0;

            public function __construct(private object $healthy)
            {
            }

            public function createJsonResponseEnvelope(array $messages, array $schema, string $modelKey): array
            {
                $this->calls++;
                if ($this->calls >= 2 && $this->calls <= 5) {
                    throw new RuntimeException('synthetic checkpoint interruption');
                }
                return $this->healthy->createJsonResponseEnvelope($messages, $schema, $modelKey);
            }
        };
        $service = new OperatingQuestionCouncilService(
            $recoverable,
            static fn(): array => ['text' => ['ready' => true]],
            static fn(int $questionId, int $tenantId, array $hotelIds): array => $question,
            null,
            $this->strictFactReader($question)
        );
        $reserved = $service->reserveShadow(41, 10, [20], 7, 'panel-contract-1234', false);
        $partial = $service->processRun((int)$reserved['id'], 10, [20]);
        self::assertSame('partial', $partial['status']);
        self::assertSame(6, $recoverable->calls);

        $synthesis = $partial['synthesis'];
        $legacyContract = $synthesis['advisory_panel_contract'];
        $legacyContract['method_version'] = 'legacy-method-v0';
        $digestMethod = new \ReflectionMethod($service, 'digest');
        $legacyPanelDigest = (string)$digestMethod->invoke($service, $legacyContract);
        $synthesis['advisory_method_version'] = 'legacy-method-v0';
        $synthesis['advisory_panel_contract'] = $legacyContract;
        $synthesis['advisory_panel_contract_digest'] = $legacyPanelDigest;
        $members = $partial['members'];
        foreach ($members as &$member) {
            $member['panel_contract_digest'] = $legacyPanelDigest;
        }
        unset($member);
        $record = [
            'contract_version' => OperatingQuestionCouncilService::CONTRACT_VERSION,
            'tenant_id' => (int)$partial['tenant_id'],
            'hotel_id' => (int)$partial['hotel_id'],
            'question_id' => (int)$partial['question_id'],
            'request_key' => (string)$partial['request_key'],
            'mode' => (string)$partial['mode'],
            'status' => (string)$partial['status'],
            'members' => $members,
            'synthesis' => $synthesis,
            'evidence_refs' => (array)$partial['evidence_refs'],
            'model_meta' => (array)$partial['model_meta'],
            'decision_effect' => 'none',
        ];
        $tamperedDigest = (string)$digestMethod->invoke($service, $record);
        Db::name(OperatingQuestionCouncilService::TABLE)
            ->where('id', (int)$partial['id'])
            ->update([
                'members_json' => json_encode($members, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'synthesis_json' => json_encode($synthesis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'content_digest' => $tamperedDigest,
            ]);

        $failed = $service->processRun((int)$partial['id'], 10, [20], true, $tamperedDigest);
        self::assertSame('failed', $failed['status']);
        self::assertSame('council_panel_contract_drift', $failed['synthesis']['error_code']);
        self::assertSame([], $failed['members']);
        self::assertSame([], $failed['evidence_refs']);
        self::assertSame([], $failed['model_meta']);
        self::assertFalse($failed['synthesis']['quarantine']['content_retained']);
        self::assertSame(5, $failed['synthesis']['quarantine']['member_count']);
        self::assertArrayNotHasKey('advisory_panel_contract', $failed['synthesis']);
        self::assertSame(6, $recoverable->calls, 'panel drift must be rejected before any checkpoint or chair model call');
    }

    public function testDispatchFailureCasCannotDowngradeConcurrentRunningReceipt(): void
    {
        $question = $this->readyQuestion();
        $service = null;
        $service = new OperatingQuestionCouncilService(
            $this->fakeClient(),
            static fn(): array => ['text' => ['ready' => true]],
            static fn(int $questionId, int $tenantId, array $hotelIds): array => $question,
            null,
            $this->strictFactReader($question),
            static function (
                int $runId,
                int $tenantId,
                int $hotelId,
                string $parentDigest,
                bool $retryFailed
            ) use (&$service): array {
                $service->claimRunForWorker(
                    $runId,
                    $tenantId,
                    [$hotelId],
                    $parentDigest,
                    $retryFailed,
                    'concurrent-lease-token-1'
                );
                return ['started' => true, 'exit_code' => 7];
            }
        );

        $reserved = $service->reserveShadow(41, 10, [20], 7, 'dispatch-cas-race');

        self::assertSame('running', $reserved['status']);
        self::assertFalse($reserved['worker_dispatched']);
        self::assertSame('already_running', $reserved['worker_receipt']['status']);
        self::assertFalse($reserved['worker_receipt']['acknowledged']);
        self::assertSame(7, $reserved['worker_receipt']['dispatch_failure']['exit_code']);
        self::assertSame('acknowledged', $reserved['synthesis']['worker']['start_receipt']['status']);
        self::assertNotSame('dispatch_failed', $reserved['synthesis']['worker']['status']);
        self::assertSame('', (string)($reserved['synthesis']['error_code'] ?? ''));
    }

    public function testDispatchFailureNeverBorrowsAcknowledgementFromAnotherLeaseGeneration(): void
    {
        $question = $this->readyQuestion();
        $service = null;
        $service = new OperatingQuestionCouncilService(
            $this->fakeClient(),
            static fn(): array => ['text' => ['ready' => true]],
            static fn(int $questionId, int $tenantId, array $hotelIds): array => $question,
            null,
            $this->strictFactReader($question),
            function (
                int $runId,
                int $tenantId,
                int $hotelId,
                string $parentDigest,
                bool $retryFailed
            ) use (&$service): array {
                $service->claimRunForWorker(
                    $runId,
                    $tenantId,
                    [$hotelId],
                    $parentDigest,
                    $retryFailed,
                    'generation-one-token'
                );
                $this->expireCouncilWorkerLease($service, $runId);
                $expired = $service->read($runId, $tenantId, [$hotelId]);
                $service->claimRunForWorker(
                    $runId,
                    $tenantId,
                    [$hotelId],
                    (string)$expired['content_digest'],
                    false,
                    'generation-two-token'
                );
                return ['started' => true, 'exit_code' => 7];
            }
        );

        $reserved = $service->reserveShadow(41, 10, [20], 7, 'dispatch-generation-race');

        self::assertFalse($reserved['worker_dispatched']);
        self::assertSame('already_running', $reserved['worker_receipt']['status']);
        self::assertFalse($reserved['worker_receipt']['acknowledged']);
        self::assertSame(2, $reserved['worker_receipt']['lease_generation']);
        self::assertSame(1, $reserved['worker_receipt']['dispatch_failure']['expected_lease_generation']);
        self::assertNotSame(
            $reserved['worker_receipt']['parent_digest'],
            $reserved['worker_receipt']['dispatch_failure']['parent_digest']
        );
        self::assertSame(7, $reserved['worker_receipt']['dispatch_failure']['exit_code']);
    }

    public function testWorkerLauncherExitSevenNeverReturnsDispatchedTrue(): void
    {
        $question = $this->readyQuestion();
        $service = new OperatingQuestionCouncilService(
            $this->fakeClient(),
            static fn(): array => ['text' => ['ready' => true]],
            static fn(int $questionId, int $tenantId, array $hotelIds): array => $question,
            null,
            $this->strictFactReader($question),
            static fn(): array => ['started' => true, 'exit_code' => 7]
        );

        $reserved = $service->reserveShadow(41, 10, [20], 7, 'worker-exit-seven');

        self::assertTrue($reserved['accepted']);
        self::assertFalse($reserved['worker_dispatched']);
        self::assertSame('worker_exited_before_ack', $reserved['worker_receipt']['status']);
        self::assertFalse($reserved['worker_receipt']['acknowledged']);
        self::assertSame('pending', $reserved['status']);
    }

    public function testWorkerLeaseFencingRejectsOlderWorkerAfterNewLeaseClaim(): void
    {
        $question = $this->readyQuestion();
        $service = new OperatingQuestionCouncilService(
            $this->fakeClient(),
            static fn(): array => ['text' => ['ready' => true]],
            static fn(int $questionId, int $tenantId, array $hotelIds): array => $question,
            null,
            $this->strictFactReader($question)
        );
        $reserved = $service->reserveShadow(41, 10, [20], 7, 'lease-fencing-1234', false);
        $first = $service->claimRunForWorker(
            (int)$reserved['id'],
            10,
            [20],
            (string)$reserved['content_digest'],
            false,
            'worker-lease-token-one'
        );
        $this->expireCouncilWorkerLease($service, (int)$reserved['id']);
        $expired = $service->read((int)$reserved['id'], 10, [20]);
        $persist = new \ReflectionMethod($service, 'persistRunStateCas');
        try {
            $persist->invoke(
                $service,
                (int)$reserved['id'],
                10,
                20,
                41,
                (string)$reserved['request_key'],
                'running',
                (array)$expired['members'],
                (array)$expired['synthesis'],
                (array)$expired['evidence_refs'],
                (array)$expired['model_meta'],
                (string)$expired['content_digest'],
                'worker-lease-token-one'
            );
            self::fail('An expired worker must not renew itself before a reclaim wins.');
        } catch (RuntimeException $e) {
            self::assertSame('council_worker_lease_expired', $e->getMessage());
        }
        $second = $service->claimRunForWorker(
            (int)$reserved['id'],
            10,
            [20],
            (string)$expired['content_digest'],
            false,
            'worker-lease-token-two'
        );
        self::assertSame(2, $second['lease_generation']);

        $method = new \ReflectionMethod($service, 'persistRunStateCas');
        try {
            $method->invoke(
                $service,
                (int)$reserved['id'],
                10,
                20,
                41,
                (string)$reserved['request_key'],
                'running',
                (array)$first['run']['members'],
                (array)$first['run']['synthesis'],
                (array)$first['run']['evidence_refs'],
                (array)$first['run']['model_meta'],
                (string)$first['content_digest'],
                'worker-lease-token-one'
            );
            self::fail('An older worker lease must not overwrite the newer claim.');
        } catch (RuntimeException $e) {
            self::assertSame('council_worker_fencing_conflict', $e->getMessage());
        }
        $current = $service->read((int)$reserved['id'], 10, [20]);
        self::assertSame((string)$second['content_digest'], (string)$current['content_digest']);
        self::assertSame(hash('sha256', 'worker-lease-token-two'), $current['synthesis']['worker']['lease_token_hash']);
        self::assertSame(hash('sha256', 'worker-lease-token-two'), $current['synthesis']['worker']['fencing_token_hash']);
    }

    public function testLegacyV5RunningRequiresStaleNoLeaseUpgradeAndUsesDatabaseClock(): void
    {
        $question = $this->readyQuestion();
        $service = new OperatingQuestionCouncilService(
            $this->fakeClient(),
            static fn(): array => ['text' => ['ready' => true]],
            static fn(int $questionId, int $tenantId, array $hotelIds): array => $question,
            null,
            $this->strictFactReader($question),
            static fn(): array => ['started' => false, 'exit_code' => 7]
        );
        $databaseEpoch = (int)(new \ReflectionMethod($service, 'databaseEpoch'))->invoke($service);
        $legacySynthesis = [
            'status' => 'pending',
            'summary' => 'legacy running without a lease',
            'worker' => ['status' => 'running', 'stage' => 'legacy_running'],
        ];
        $legacyRecord = [
            'contract_version' => 'operating_question_council.v5',
            'tenant_id' => 10,
            'hotel_id' => 20,
            'question_id' => 41,
            'request_key' => 'council:legacy-v5-run',
            'mode' => 'shadow',
            'status' => 'running',
            'members' => [],
            'synthesis' => $legacySynthesis,
            'evidence_refs' => [],
            'model_meta' => [],
            'decision_effect' => 'none',
        ];
        $digest = (string)(new \ReflectionMethod($service, 'digest'))->invoke($service, $legacyRecord);
        $runId = (int)Db::name(OperatingQuestionCouncilService::TABLE)->insertGetId([
            'tenant_id' => 10,
            'hotel_id' => 20,
            'question_id' => 41,
            'request_key' => 'council:legacy-v5-run',
            'mode' => 'shadow',
            'status' => 'running',
            'members_json' => '[]',
            'synthesis_json' => json_encode($legacySynthesis),
            'evidence_refs_json' => '[]',
            'model_meta_json' => '[]',
            'decision_effect' => 'none',
            'content_digest' => $digest,
            'created_by' => 7,
            'created_at' => date('Y-m-d H:i:s', $databaseEpoch),
            'updated_at' => date('Y-m-d H:i:s', $databaseEpoch),
        ]);

        $legacyRead = $service->read($runId, 10, [20]);
        self::assertSame('operating_question_council.v5', $legacyRead['persisted_contract_version']);
        self::assertTrue($legacyRead['legacy_migration_required']);
        $recent = $service->reserveShadow(41, 10, [20], 7, 'new-client-key-1');
        self::assertSame('legacy_worker_recent_busy', $recent['worker_receipt']['status']);
        self::assertSame('operating_question_council.v5', $recent['persisted_contract_version']);
        self::assertTrue($recent['legacy_migration_required']);

        Db::name(OperatingQuestionCouncilService::TABLE)
            ->where('id', $runId)
            ->update(['updated_at' => date('Y-m-d H:i:s', $databaseEpoch - 180)]);
        $upgradeService = null;
        $upgradeService = new OperatingQuestionCouncilService(
            $this->fakeClient(),
            static fn(): array => ['text' => ['ready' => true]],
            static fn(int $questionId, int $tenantId, array $hotelIds): array => $question,
            null,
            $this->strictFactReader($question),
            static function (
                int $claimedRunId,
                int $tenantId,
                int $hotelId,
                string $parentDigest,
                bool $retryFailed
            ) use (&$upgradeService): array {
                $upgradeService->claimRunForWorker(
                    $claimedRunId,
                    $tenantId,
                    [$hotelId],
                    $parentDigest,
                    $retryFailed,
                    'legacy-v5-upgrade-token'
                );
                return ['started' => true, 'exit_code' => null];
            }
        );
        $upgraded = $upgradeService->reserveShadow(41, 10, [20], 7, 'new-client-key-2');
        self::assertSame($runId, (int)$upgraded['id']);
        self::assertSame('operating_question_council.v6', $upgraded['persisted_contract_version']);
        self::assertFalse($upgraded['legacy_migration_required']);
        self::assertTrue($upgraded['worker_dispatched']);
        self::assertSame(
            180,
            (int)$upgraded['synthesis']['worker']['lease_expires_epoch']
                - (int)$upgraded['synthesis']['worker']['lease_started_epoch']
        );
        self::assertLessThanOrEqual(
            2,
            abs((int)$upgraded['synthesis']['worker']['lease_started_epoch']
                - (int)(new \ReflectionMethod($upgradeService, 'databaseEpoch'))->invoke($upgradeService))
        );
    }

    public function testCouncilTerminalFactRecheckBlocksMidRunEvidenceDriftBeforeCommit(): void
    {
        $question = $this->readyQuestion();
        $factReads = 0;
        $client = $this->fakeClient();
        $strictReader = static function () use (&$factReads): array {
            $factReads++;
            $fact = self::factSample();
            if ($factReads >= 3) {
                $fact['metric_values']['list_exposure'] = 1300;
            }
            return [$fact];
        };
        $service = new OperatingQuestionCouncilService(
            $client,
            static fn(): array => ['text' => ['ready' => true]],
            static fn(int $questionId, int $tenantId, array $hotelIds): array => $question,
            null,
            $strictReader
        );
        $reserved = $service->reserveShadow(41, 10, [20], 7, 'terminal-fact-drift', false);

        $blocked = $service->processRun((int)$reserved['id'], 10, [20]);

        self::assertSame('blocked_by_missing_facts', $blocked['status']);
        self::assertSame('council_terminal_fact_drift', $blocked['synthesis']['error_code']);
        self::assertSame('verified_fact_source_drift_detected', $blocked['synthesis']['fact_recheck_code']);
        self::assertSame([], $blocked['members']);
        self::assertSame([], $blocked['evidence_refs']);
        self::assertSame([], $blocked['model_meta']);
        self::assertFalse($blocked['synthesis']['quarantine']['content_retained']);
        self::assertSame(5, $blocked['synthesis']['quarantine']['member_count']);
        self::assertSame(6, $client->calls, 'post-chair fact drift must still block the terminal commit');
        self::assertSame(3, $factReads);
        try {
            $service->resumeRun((int)$reserved['id'], 41, 10, [20]);
            self::fail('Terminal fact drift must require a new upstream fact/question run.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('council_terminal_fact_drift_requires_new_question', $e->getMessage());
        }
    }

    public function testCouncilKeepsCompletedTerminalWhenFirstPostCommitReadbackFails(): void
    {
        $question = $this->readyQuestion();
        $service = new OperatingQuestionCouncilService(
            $this->fakeClient(),
            static fn(): array => ['text' => ['ready' => true]],
            static fn(int $questionId, int $tenantId, array $hotelIds): array => $question,
            null,
            $this->strictFactReader($question)
        );
        $reserved = $service->reserveShadow(41, 10, [20], 7, 'terminal-readback-1234', false);
        $terminalUpdateSeen = false;
        $readbackFailureInjected = false;
        Db::listen(static function (string $sql) use (&$terminalUpdateSeen, &$readbackFailureInjected): void {
            if (str_starts_with(
                $sql,
                "UPDATE `hotel_operating_question_council_runs` SET `status` = 'completed'"
            )) {
                $terminalUpdateSeen = true;
                return;
            }
            if ($terminalUpdateSeen
                && !$readbackFailureInjected
                && str_starts_with($sql, 'SELECT * FROM `hotel_operating_question_council_runs`')
            ) {
                $readbackFailureInjected = true;
                throw new RuntimeException('synthetic post-terminal readback failure');
            }
        });

        $completed = $service->processRun((int)$reserved['id'], 10, [20]);

        self::assertTrue($terminalUpdateSeen);
        self::assertTrue($readbackFailureInjected);
        self::assertSame('completed', $completed['status']);
        self::assertSame('terminal_readback_recovered', $completed['worker_status']);
        self::assertSame('completed', $service->read((int)$reserved['id'], 10, [20])['status']);
    }

    public function testCouncilWindowsWorkerCommandKeepsAllArgumentsQuotedAndHidden(): void
    {
        $command = OperatingQuestionCouncilService::buildWindowsWorkerLauncherCommand(
            'C:\\xampp\\php\\php.exe',
            [
                'D:\\桌面\\SUXIOS\\think',
                OperatingQuestionCouncilService::WORKER_COMMAND,
                '--run-id=41',
                '--tenant-id=10',
                '--hotel-id=20',
                '--parent-digest=' . str_repeat('a', 64),
                "--probe=x'; Write-Output injected; '",
            ],
            'D:\\桌面\\SUXIOS',
            'D:\\桌面\\SUXIOS\\runtime\\council.stdout.log',
            'D:\\桌面\\SUXIOS\\runtime\\council.stderr.log'
        );
        self::assertMatchesRegularExpression('/-EncodedCommand [A-Za-z0-9+\/=]+ > NUL 2>&1$/', $command);
        preg_match('/-EncodedCommand ([A-Za-z0-9+\/=]+)/', $command, $matches);
        $decoded = base64_decode((string)($matches[1] ?? ''), true);
        self::assertIsString($decoded);
        $script = mb_convert_encoding($decoded, 'UTF-8', 'UTF-16LE');
        self::assertStringContainsString('-WindowStyle Hidden -PassThru', $script);
        self::assertStringContainsString("'--run-id=41'", $script);
        self::assertStringContainsString("'--tenant-id=10'", $script);
        self::assertStringContainsString("'--hotel-id=20'", $script);
        self::assertStringContainsString("'--parent-digest=" . str_repeat('a', 64) . "'", $script);
        self::assertStringContainsString("'--probe=x''; Write-Output injected; '''", $script);
    }

    public function testCouncilRejectsUnsupportedBenchmarkPercentUnitAndOutcomeClaimsBeforeSaving(): void
    {
        $question = $this->readyQuestion();
        $question['answer']['fact_samples'][0]['metric_values']['flow_rate'] = 1.99;
        $question['answer']['fact_samples'][0]['metric_units']['flow_rate'] = 'source_defined_rate';
        $cases = [
            'percent' => ['流量转化率1.99%。', 'ungrounded_percent_unit'],
            'benchmark' => ['流量转化率低于行业平均。', 'ungrounded_benchmark_claim'],
            'outcome' => ['当前存在可优化空间。', 'ungrounded_outcome_claim'],
            'causal' => ['该调整导致订单增加。', 'ungrounded_causal_claim'],
        ];

        foreach ($cases as $name => [$claim, $expectedCode]) {
            $ungroundedClient = new class($claim) {
                public int $calls = 0;

                public function __construct(private string $claim)
                {
                }

                public function createJsonResponseEnvelope(array $messages, array $schema, string $modelKey): array
                {
                    $this->calls++;
                    return [
                        'data' => [
                            'assessment' => $this->claim,
                            'supported_points' => ['已取得严格回读事实。'],
                            'conflicting_points' => [],
                            'risks' => [],
                            'missing_information' => [],
                            'falsification_check' => '观察后续同口径事实。',
                            'supporting_evidence_refs' => ['online_daily_data#9001'],
                            'conflicting_evidence_refs' => [],
                            'evidence_refs' => ['online_daily_data#9001'],
                            'confidence' => 'high',
                        ],
                        'meta' => [
                            'provider' => 'ollama',
                            'model_key' => $modelKey,
                            'model' => LocalAiRuntimeService::TEXT_MODEL,
                            'finish_reason' => 'stop',
                            'fallback_used' => false,
                            'cache_hit' => false,
                            'degraded' => false,
                        ],
                    ];
                }
            };
            $service = new OperatingQuestionCouncilService(
                $ungroundedClient,
                static fn(): array => ['text' => ['ready' => true]],
                static fn(int $questionId, int $tenantId, array $hotelIds): array => $question,
                null,
                $this->strictFactReader($question)
            );

            $saved = $service->runShadow(41, 10, [20], 7, 'grounding-' . $name . '-1234');

            self::assertSame('failed', $saved['status'], $name);
            self::assertSame(5, $ungroundedClient->calls, $name);
            self::assertSame('all_persona_calls_failed', $saved['synthesis']['error_code'], $name);
            self::assertSame(
                [$expectedCode],
                array_values(array_unique(array_column($saved['members'], 'error_code'))),
                $name
            );
            self::assertStringNotContainsString(
                $claim,
                json_encode($saved['members'], JSON_UNESCAPED_UNICODE),
                $name
            );
            self::assertSame('readback_verified', $saved['persistence_status'], $name);
        }
    }

    public function testCouncilRejectsEveryUnboundAmountCountDateAndUnitClaim(): void
    {
        $question = $this->readyQuestion();
        $cases = [
            'amount' => '渠道收入为1200元。',
            'count' => '携程曝光为1200次。',
            'date' => '数据日期为2026-08-22。',
            'unit' => '携程曝光单位为次。',
        ];

        foreach ($cases as $name => $claim) {
            $client = $this->quantitativeClient($claim, []);
            $service = new OperatingQuestionCouncilService(
                $client,
                static fn(): array => ['text' => ['ready' => true]],
                static fn(int $questionId, int $tenantId, array $hotelIds): array => $question,
                null,
                $this->strictFactReader($question)
            );

            $saved = $service->runShadow(41, 10, [20], 7, 'quant-unbound-' . $name);

            self::assertSame('failed', $saved['status'], $name);
            self::assertSame(5, $client->calls, $name);
            self::assertSame(
                ['ungrounded_quantitative_claim'],
                array_values(array_unique(array_column($saved['members'], 'error_code'))),
                $name
            );
            self::assertNotContains(
                'verified_scope_guard_passed',
                array_column($saved['members'], 'grounding_status'),
                $name
            );
        }
    }

    public function testCouncilRejectsQuantitativeBindingWithWrongFactUnit(): void
    {
        $question = $this->readyQuestion();
        $claim = '携程曝光为1200次。';
        $client = $this->quantitativeClient($claim, [[
            'claim_text' => $claim,
            'value' => '1200',
            'unit' => 'currency_cny',
            'scope' => $this->quantitativeScope(),
            'date' => '2026-08-22',
            'ref' => 'online_daily_data#9001',
        ]]);
        $service = new OperatingQuestionCouncilService(
            $client,
            static fn(): array => ['text' => ['ready' => true]],
            static fn(int $questionId, int $tenantId, array $hotelIds): array => $question,
            null,
            $this->strictFactReader($question)
        );

        $saved = $service->runShadow(41, 10, [20], 7, 'quant-wrong-unit');

        self::assertSame('failed', $saved['status']);
        self::assertSame(
            ['ungrounded_quantitative_binding'],
            array_values(array_unique(array_column($saved['members'], 'error_code')))
        );
        self::assertNotContains(
            'verified_scope_guard_passed',
            array_column($saved['members'], 'grounding_status')
        );
    }

    public function testCouncilAcceptsQuantitativeClaimOnlyWithExactValueUnitScopeDateAndRef(): void
    {
        $question = $this->readyQuestion();
        $claim = '携程曝光为1200次。';
        $bindings = [[
            'claim_text' => $claim,
            'value' => '1200',
            'unit' => 'exposure_count',
            'scope' => $this->quantitativeScope(),
            'date' => '2026-08-22',
            'ref' => 'online_daily_data#9001',
        ]];
        $client = $this->quantitativeClient($claim, $bindings);
        $service = new OperatingQuestionCouncilService(
            $client,
            static fn(): array => ['text' => ['ready' => true]],
            static fn(int $questionId, int $tenantId, array $hotelIds): array => $question,
            null,
            $this->strictFactReader($question)
        );

        $saved = $service->runShadow(41, 10, [20], 7, 'quant-exact-binding');

        self::assertSame('completed', $saved['status']);
        self::assertSame(6, $client->calls);
        self::assertSame('verified_scope_guard_passed', $saved['members'][0]['grounding_status']);
        self::assertSame($bindings, $saved['members'][0]['quantitative_claims']);
        self::assertSame($bindings, $saved['synthesis']['quantitative_claims']);
    }

    public function testCouncilFailsClosedWithoutVerifiedFactAndStillExplainsSelectedFrameworks(): void
    {
        $fakeClient = $this->fakeClient();
        $question = $this->readyQuestion();
        $question['answer_status'] = 'blocked_by_missing_facts';
        $question['answer_summary'] = '缺少严格回读事实。';
        $question['fact_refs'] = [];
        $question['answer']['fact_samples'] = [];
        $question['answer']['action_drafts'] = [];
        $service = new OperatingQuestionCouncilService(
            $fakeClient,
            static fn(): array => ['text' => ['ready' => true]],
            static fn(int $questionId, int $tenantId, array $hotelIds): array => $question,
            null,
            static fn(): array => []
        );

        $saved = $service->runShadow(42, 10, [20], 7, 'blocked1234');

        self::assertSame('blocked_by_missing_facts', $saved['status']);
        self::assertSame('verified_fact_reference_missing', $saved['synthesis']['error_code']);
        self::assertSame([], $saved['members']);
        self::assertNotEmpty($saved['synthesis']['selected_lenses']);
        self::assertSame('advisory_only_no_action_draft', $saved['synthesis']['execution_handoff']['status']);
        self::assertSame([], $saved['evidence_refs']);
        self::assertSame(0, $fakeClient->calls);
        self::assertSame('readback_verified', $saved['persistence_status']);
    }

    public function testCouncilFailsClosedForInvalidMissingOutOfScopeOrDriftedFactReadback(): void
    {
        $cases = [
            'invalid' => [
                'mutate_question' => static function (array $question): array {
                    $question['fact_refs'] = ['online_daily_data#garbage'];
                    $question['answer']['fact_samples'][0]['ref'] = 'online_daily_data#garbage';
                    return $question;
                },
                'reader' => static fn(): array => [],
                'code' => 'verified_fact_reference_invalid',
            ],
            'missing' => [
                'mutate_question' => static fn(array $question): array => $question,
                'reader' => static fn(): array => [],
                'code' => 'verified_fact_readback_mismatch',
            ],
            'scope' => [
                'mutate_question' => static fn(array $question): array => $question,
                'reader' => static function (
                    int $tenantId,
                    int $hotelId,
                    string $platform,
                    string $dateStart,
                    string $dateEnd,
                    array $refs
                ): array {
                    $fact = self::factSample();
                    $fact['platform'] = 'meituan';
                    return [$fact];
                },
                'code' => 'verified_fact_scope_mismatch',
            ],
            'drift' => [
                'mutate_question' => static fn(array $question): array => $question,
                'reader' => static function (
                    int $tenantId,
                    int $hotelId,
                    string $platform,
                    string $dateStart,
                    string $dateEnd,
                    array $refs
                ): array {
                    $fact = self::factSample();
                    $fact['metric_values']['list_exposure'] = 1300;
                    return [$fact];
                },
                'code' => 'verified_fact_source_drift_detected',
            ],
        ];

        foreach ($cases as $name => $case) {
            $fakeClient = $this->fakeClient();
            $question = $case['mutate_question']($this->readyQuestion());
            $service = new OperatingQuestionCouncilService(
                $fakeClient,
                static fn(): array => ['text' => ['ready' => true]],
                static fn(int $questionId, int $tenantId, array $hotelIds): array => $question,
                null,
                $case['reader']
            );

            $saved = $service->runShadow(41, 10, [20], 7, 'strict-' . $name . '-1234');

            self::assertSame('blocked_by_missing_facts', $saved['status'], $name);
            self::assertSame($case['code'], $saved['synthesis']['error_code'], $name);
            self::assertSame([], $saved['members'], $name);
            self::assertSame(0, $fakeClient->calls, $name);
            self::assertSame('readback_verified', $saved['persistence_status'], $name);
        }
    }

    public function testCouncilExactReadRejectsTamperedSavedContent(): void
    {
        $question = $this->readyQuestion();
        $service = new OperatingQuestionCouncilService(
            $this->fakeClient(),
            static fn(): array => ['text' => ['ready' => true]],
            static fn(int $questionId, int $tenantId, array $hotelIds): array => $question,
            null,
            $this->strictFactReader($question)
        );
        $saved = $service->runShadow(41, 10, [20], 7, 'tamper-check-1234');
        Db::name(OperatingQuestionCouncilService::TABLE)
            ->where('id', (int)$saved['id'])
            ->update(['members_json' => '[]']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('摘要不一致');
        $service->read((int)$saved['id'], 10, [20]);
    }

    public function testCouncilReadyCheckPreservesTransientDatabaseFailureStage(): void
    {
        $throwNextReadyRead = true;
        Db::listen(static function (string $sql) use (&$throwNextReadyRead): void {
            if ($throwNextReadyRead
                && str_starts_with($sql, 'SELECT * FROM `hotel_operating_question_council_runs`')
            ) {
                $throwNextReadyRead = false;
                throw new RuntimeException('synthetic transient database read failure');
            }
        });
        $question = $this->readyQuestion();
        $service = new OperatingQuestionCouncilService(
            $this->fakeClient(),
            static fn(): array => ['text' => ['ready' => true]],
            static fn(int $questionId, int $tenantId, array $hotelIds): array => $question,
            null,
            $this->strictFactReader($question)
        );

        try {
            $service->reserveShadow(41, 10, [20], 7, 'ready-transient-1234', false);
            self::fail('A transient database failure must remain distinct from a missing migration.');
        } catch (RuntimeException $e) {
            self::assertSame('经营顾问会诊存储就绪检查失败', $e->getMessage());
            self::assertSame('synthetic transient database read failure', $e->getPrevious()?->getMessage());
        }
    }

    public function testCouncilReadyCheckReportsOnlyActualMissingTableAsMigrationRequired(): void
    {
        Db::execute('DROP TABLE hotel_operating_question_council_runs');
        $question = $this->readyQuestion();
        $service = new OperatingQuestionCouncilService(
            $this->fakeClient(),
            static fn(): array => ['text' => ['ready' => true]],
            static fn(int $questionId, int $tenantId, array $hotelIds): array => $question,
            null,
            $this->strictFactReader($question)
        );

        try {
            $service->reserveShadow(41, 10, [20], 7, 'ready-missing-1234', false);
            self::fail('A genuinely missing council table must retain the migration-required status.');
        } catch (RuntimeException $e) {
            self::assertSame('多角色影子复核表尚未迁移', $e->getMessage());
        }
    }

    public function testCouncilProductionStrictReaderAcceptsOnlyCurrentSameHotelPlatformAndDateFact(): void
    {
        Db::name('online_daily_data')->insert([
            'id' => 9001,
            'tenant_id' => 10,
            'system_hotel_id' => 20,
            'data_date' => '2026-08-22',
            'platform' => 'ctrip',
            'source' => 'ctrip',
            'data_type' => 'traffic',
            'dimension' => 'hotel_daily',
            'validation_status' => 'verified',
            'history_status' => 'success',
            'readback_verified' => 1,
            'readback_verified_at' => '2026-08-22 10:00:00',
            'ingestion_method' => 'local_browser_profile',
            'source_trace_id' => 'trace-9001',
            'list_exposure' => 1200,
        ]);
        $question = $this->readyQuestion();
        $strictReadback = (new \app\service\OperatingQuestionService())
            ->readCurrentVerifiedFactsForRefs(
                10,
                20,
                'ctrip',
                '2026-08-22',
                '2026-08-22',
                ['online_daily_data#9001']
            );
        $question['answer']['fact_samples'] = $strictReadback;
        $readyClient = $this->fakeClient();
        $service = new OperatingQuestionCouncilService(
            $readyClient,
            static fn(): array => ['text' => ['ready' => true]],
            static fn(int $questionId, int $tenantId, array $hotelIds): array => $question
        );

        $saved = $service->runShadow(41, 10, [20], 7, 'strict-real-1234');
        self::assertSame('completed', $saved['status']);
        self::assertSame(['online_daily_data#9001'], $saved['evidence_refs']);
        self::assertSame(6, $readyClient->calls);

        Db::name('online_daily_data')->where('id', 9001)->update(['system_hotel_id' => 21]);
        $blockedClient = $this->fakeClient();
        $blockedService = new OperatingQuestionCouncilService(
            $blockedClient,
            static fn(): array => ['text' => ['ready' => true]],
            static fn(int $questionId, int $tenantId, array $hotelIds): array => $question
        );
        $blocked = $blockedService->runShadow(41, 10, [20], 7, 'strict-cross-hotel-1234');
        self::assertSame('blocked_by_missing_facts', $blocked['status']);
        self::assertSame('verified_fact_readback_mismatch', $blocked['synthesis']['error_code']);
        self::assertSame(0, $blockedClient->calls);
    }

    private function fakeClient(): object
    {
        return new class {
            public int $calls = 0;

            /** @param list<array<string,string>> $messages @param array<string,mixed> $schema */
            public function createJsonResponseEnvelope(
                array $messages,
                array $schema,
                string $modelKey = 'local_second_brain'
            ): array {
                $this->calls++;
                $scenario = (string)($schema['x-governance']['scenario'] ?? '');
                $data = $scenario === 'synthesis_chair'
                    ? [
                        'summary' => '证据支持先做小范围人工核对，不支持直接归因。',
                        'agreements' => ['先核对同口径曝光和价格事实。'],
                        'conflicts' => ['客人价值解释仍缺少行为证据。'],
                        'missing_information' => ['缺少竞品与活动成本。'],
                        'falsification_checks' => ['若同口径曝光没有变化，则推翻当前流量假设。'],
                        'recommended_next_step' => '人工复核目标日页面展示与同口径曝光。',
                        'evidence_refs' => ['online_daily_data#9001'],
                    ]
                    : [
                        'assessment' => '当前事实只支持渠道范围内的待验证解释。',
                        'supported_points' => ['已保存携程曝光事实。'],
                        'conflicting_points' => ['缺少竞品与活动成本。'],
                        'risks' => ['不能把相关性写成因果。'],
                        'missing_information' => ['客群与竞品事实。'],
                        'falsification_check' => '复核同口径曝光是否在下一观察期重复出现。',
                        'supporting_evidence_refs' => ['online_daily_data#9001'],
                        'conflicting_evidence_refs' => [],
                        'evidence_refs' => ['online_daily_data#9001'],
                        'confidence' => 'medium',
                    ];
                return [
                    'data' => $data,
                    'meta' => [
                        'provider' => 'ollama',
                        'model_key' => $modelKey,
                        'model' => LocalAiRuntimeService::TEXT_MODEL,
                        'finish_reason' => 'stop',
                        'fallback_used' => false,
                        'cache_hit' => false,
                        'degraded' => false,
                    ],
                ];
            }
        };
    }

    /** @param list<array<string,string>> $bindings */
    private function quantitativeClient(string $claim, array $bindings): object
    {
        return new class($claim, $bindings) {
            public int $calls = 0;

            /** @param list<array<string,string>> $bindings */
            public function __construct(private string $claim, private array $bindings)
            {
            }

            public function createJsonResponseEnvelope(array $messages, array $schema, string $modelKey): array
            {
                $this->calls++;
                $scenario = (string)($schema['x-governance']['scenario'] ?? '');
                $data = $scenario === 'synthesis_chair'
                    ? [
                        'summary' => $this->claim,
                        'agreements' => [],
                        'conflicts' => [],
                        'missing_information' => [],
                        'falsification_checks' => ['继续核对同口径事实。'],
                        'recommended_next_step' => '仅作人工核对。',
                        'evidence_refs' => ['online_daily_data#9001'],
                        'quantitative_claims' => $this->bindings,
                    ]
                    : [
                        'assessment' => $this->claim,
                        'supported_points' => ['已取得严格回读事实。'],
                        'conflicting_points' => [],
                        'risks' => [],
                        'missing_information' => [],
                        'falsification_check' => '继续核对同口径事实。',
                        'supporting_evidence_refs' => ['online_daily_data#9001'],
                        'conflicting_evidence_refs' => [],
                        'evidence_refs' => ['online_daily_data#9001'],
                        'quantitative_claims' => $this->bindings,
                        'confidence' => 'high',
                    ];

                return [
                    'data' => $data,
                    'meta' => [
                        'provider' => 'ollama',
                        'model_key' => $modelKey,
                        'model' => LocalAiRuntimeService::TEXT_MODEL,
                        'finish_reason' => 'stop',
                        'fallback_used' => false,
                        'cache_hit' => false,
                        'degraded' => false,
                    ],
                ];
            }
        };
    }

    private function quantitativeScope(): string
    {
        return '{"tenant_id":10,"hotel_id":20,"platform":"ctrip","source_scope":"ota_channel",'
            . '"data_type":"traffic","dimension":"hotel_daily"}';
    }

    private function strictFactReader(array $question): callable
    {
        return static function (
            int $tenantId,
            int $hotelId,
            string $platform,
            string $dateStart,
            string $dateEnd,
            array $refs
        ) use ($question): array {
            return array_values(array_filter(
                (array)($question['answer']['fact_samples'] ?? []),
                static fn(mixed $fact): bool => is_array($fact)
                    && in_array((string)($fact['ref'] ?? ''), $refs, true)
            ));
        };
    }

    private function expireCouncilWorkerLease(OperatingQuestionCouncilService $service, int $runId): void
    {
        $run = $service->read($runId, 10, [20]);
        $synthesis = $run['synthesis'];
        $synthesis['worker']['lease_expires_at'] = date(DATE_ATOM, time() - 60);
        $synthesis['worker']['lease_expires_epoch'] = time() - 60;
        $record = [
            'contract_version' => OperatingQuestionCouncilService::CONTRACT_VERSION,
            'tenant_id' => (int)$run['tenant_id'],
            'hotel_id' => (int)$run['hotel_id'],
            'question_id' => (int)$run['question_id'],
            'request_key' => (string)$run['request_key'],
            'mode' => (string)$run['mode'],
            'status' => (string)$run['status'],
            'members' => (array)$run['members'],
            'synthesis' => $synthesis,
            'evidence_refs' => (array)$run['evidence_refs'],
            'model_meta' => (array)$run['model_meta'],
            'decision_effect' => 'none',
        ];
        $digestMethod = new \ReflectionMethod($service, 'digest');
        $digest = (string)$digestMethod->invoke($service, $record);
        Db::name(OperatingQuestionCouncilService::TABLE)
            ->where('id', $runId)
            ->update([
                'synthesis_json' => json_encode($synthesis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'content_digest' => $digest,
                'updated_at' => date('Y-m-d H:i:s', time() - 180),
            ]);
    }

    /** @return array<string,mixed> */
    private static function factSample(): array
    {
        return [
            'ref' => 'online_daily_data#9001',
            'platform' => 'ctrip',
            'data_date' => '2026-08-22',
            'data_type' => 'traffic',
            'dimension' => 'hotel_daily',
            'quality_status' => 'verified',
            'history_status' => 'success',
            'readback_status' => 'readback_verified',
            'ingestion_method' => 'local_browser_profile',
            'source_trace_id' => 'trace-9001',
            'metric_values' => ['list_exposure' => 1200],
            'metric_units' => ['list_exposure' => 'exposure_count'],
        ];
    }

    /** @return array<string,mixed> */
    private function readyQuestion(): array
    {
        return [
            'id' => 41,
            'tenant_id' => 10,
            'hotel_id' => 20,
            'question_text' => '携程价格下降后曝光变高，是否应执行降价行动？',
            'platform' => 'ctrip',
            'date_start' => '2026-08-22',
            'date_end' => '2026-08-22',
            'answer_status' => 'answered_by_grounded_ai',
            'answer_summary' => '只确认同酒店携程渠道曝光事实，不确认降价因果。',
            'fact_refs' => ['online_daily_data#9001'],
            'knowledge_refs' => [],
            'memory_refs' => [],
            'execution_refs' => [],
            'data_gaps' => [],
            'answer' => [
                'fact_samples' => [self::factSample()],
                'data_gaps' => [],
                'action_drafts' => [[
                    'title' => '人工复核携程页面展示',
                    'status' => 'ready_for_ai_review',
                ]],
            ],
        ];
    }
}
