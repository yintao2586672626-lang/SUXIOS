<?php
declare(strict_types=1);

namespace Tests;

use app\service\CanonicalOtaDailyPlatformSelectionService;
use app\service\CanonicalOtaInvestigationActionService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class CanonicalOtaDailyPlatformSelectionServiceTest extends TestCase
{
    /** @var array<string,mixed> */
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    /** @var array<string,mixed> */
    private array $scope;

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'canonical_ota_daily_platform_selection_' . getmypid() . '.sqlite';
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
            throw new RuntimeException('Unable to remove platform-selection SQLite fixture.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['operation_execution_evidence', 'operation_execution_tasks', 'operation_execution_intents', 'hotels'] as $table) {
            Db::name($table)->delete(true);
        }
        Db::name('hotels')->insert(['id' => 80, 'tenant_id' => 80]);
        $this->scope = [
            'tenant_id' => 80,
            'hotel_id' => 80,
            'data_source_id' => 25,
            'task_id' => 3092,
            'row_id' => 81818,
            'platform' => 'ctrip',
            'target_date' => '2026-08-08',
            'data_period' => 'historical_daily',
        ];
    }

    public function testNoOwnerResolvesNoneAndExactHotelLockMakesScopeClaimable(): void
    {
        $service = new CanonicalOtaDailyPlatformSelectionService();

        $resolved = $service->resolve(80, 80, '2026-08-08');
        $claim = $service->assertScopeMayPersist($this->scope);

        self::assertSame('none', $resolved['status']);
        self::assertFalse($resolved['selected']);
        self::assertNull($resolved['selection_receipt']);
        self::assertSame('claimable', $claim['status']);
        self::assertTrue($claim['claimable']);
        self::assertFalse($claim['replay']);
        self::assertSame($this->scope, $claim['scope']);
    }

    public function testLegacyFourIntentOwnerIsStrictlyRecoveredWithoutRewrite(): void
    {
        $ids = $this->seedValidOwner($this->scope);
        $before = Db::name('operation_execution_intents')->order('id')->select()->toArray();

        $result = (new CanonicalOtaDailyPlatformSelectionService())->resolve(80, 80, '2026-08-08');

        self::assertSame('selected', $result['status']);
        self::assertTrue($result['selected']);
        self::assertSame('ctrip', $result['platform']);
        self::assertSame($this->scope, $result['scope']);
        self::assertSame($ids, $result['selection_receipt']['intent_ids']);
        self::assertCount(4, $result['selection_receipt']['triplets']);
        self::assertSame($ids, array_column($result['selection_receipt']['triplets'], 'intent_id'));
        self::assertNotContains(0, array_column($result['selection_receipt']['triplets'], 'task_id'));
        self::assertNotContains(0, array_column($result['selection_receipt']['triplets'], 'evidence_id'));
        self::assertSame('legacy_four_intent_inference', $result['selection_receipt']['owner_source']);
        self::assertTrue($result['selection_receipt']['legacy_owner_inferred']);
        self::assertTrue($result['selection_receipt']['readback_verified']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $result['selection_receipt']['selection_policy_digest']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $result['selection_receipt']['owner_scope_digest']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $result['selection_receipt']['content_digest']);
        self::assertSame($before, Db::name('operation_execution_intents')->order('id')->select()->toArray());
    }

    public function testOnlyTheExactRecoveredScopeMayReplay(): void
    {
        $this->seedValidOwner($this->scope);
        $service = new CanonicalOtaDailyPlatformSelectionService();

        $replay = $service->assertScopeMayPersist($this->scope);

        self::assertSame('replay', $replay['status']);
        self::assertFalse($replay['claimable']);
        self::assertTrue($replay['replay']);
        self::assertSame($this->scope, $replay['scope']);

        $differentCapture = $this->scope;
        $differentCapture['task_id'] = 4000;
        $differentCapture['row_id'] = 90000;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('canonical_daily_platform_selection_owner_scope_conflict');
        $service->assertScopeMayPersist($differentCapture);
    }

    public function testOneToThreeIntentsFailClosedInsteadOfBecomingClaimable(): void
    {
        $this->seedValidOwner($this->scope);
        $last = Db::name('operation_execution_intents')->order('id', 'desc')->find();
        self::assertIsArray($last);
        Db::name('operation_execution_intents')->where('id', (int)$last['id'])->delete();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('canonical_daily_platform_selection_intent_membership_invalid');
        (new CanonicalOtaDailyPlatformSelectionService())->resolve(80, 80, '2026-08-08');
    }

    public function testMoreThanFourIntentsFailClosed(): void
    {
        $this->seedValidOwner($this->scope);
        $row = Db::name('operation_execution_intents')->order('id')->find();
        self::assertIsArray($row);
        unset($row['id']);
        $row['idempotency_key'] = 'fixture-extra-' . str_repeat('1', 32);
        Db::name('operation_execution_intents')->insert($row);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('canonical_daily_platform_selection_intent_membership_invalid');
        (new CanonicalOtaDailyPlatformSelectionService())->resolve(80, 80, '2026-08-08');
    }

    public function testMixedPlatformOrExactSourceScopeFailsClosed(): void
    {
        $this->seedValidOwner($this->scope);
        $row = Db::name('operation_execution_intents')->order('id')->find();
        self::assertIsArray($row);
        Db::name('operation_execution_intents')->where('id', (int)$row['id'])->update([
            'platform' => 'meituan',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('canonical_daily_platform_selection_intent_scope_invalid');
        (new CanonicalOtaDailyPlatformSelectionService())->resolve(80, 80, '2026-08-08');
    }

    public function testIncompleteTripletOrTamperedEvidenceTruthFailsClosed(): void
    {
        $this->seedValidOwner($this->scope);
        $evidence = Db::name('operation_execution_evidence')->order('id')->find();
        self::assertIsArray($evidence);
        $wrapper = json_decode((string)$evidence['platform_response_json'], true, 512, JSON_THROW_ON_ERROR);
        $wrapper['analysis_receipt']['external_action_triggered'] = true;
        $wrapper['analysis_receipt']['content_digest'] = $this->canonicalDigest($wrapper['analysis_receipt']);
        Db::name('operation_execution_evidence')->where('id', (int)$evidence['id'])->update([
            'platform_response_json' => json_encode(
                $wrapper,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('canonical_daily_platform_selection_evidence_truth_unverified');
        (new CanonicalOtaDailyPlatformSelectionService())->assertScopeMayPersist($this->scope);
    }

    public function testDuplicateTaskMembershipFailsClosed(): void
    {
        $this->seedValidOwner($this->scope);
        $task = Db::name('operation_execution_tasks')->order('id')->find();
        self::assertIsArray($task);
        unset($task['id']);
        Db::name('operation_execution_tasks')->insert($task);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('canonical_daily_platform_selection_task_membership_invalid');
        (new CanonicalOtaDailyPlatformSelectionService())->resolve(80, 80, '2026-08-08');
    }

    public function testActionSetDigestDriftFailsClosed(): void
    {
        $this->seedValidOwner($this->scope);
        $intent = Db::name('operation_execution_intents')->order('id', 'desc')->find();
        self::assertIsArray($intent);
        $intentEvidence = json_decode((string)$intent['evidence_json'], true, 512, JSON_THROW_ON_ERROR);
        $intentEvidence['action_set_digest'] = str_repeat('9', 64);
        Db::name('operation_execution_intents')->where('id', (int)$intent['id'])->update([
            'evidence_json' => $this->json($intentEvidence),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('canonical_daily_platform_selection_action_set_mismatch');
        (new CanonicalOtaDailyPlatformSelectionService())->resolve(80, 80, '2026-08-08');
    }

    public function testDeletedOwnerRowAndWrongTenantHotelLockBothFailClosed(): void
    {
        $this->seedValidOwner($this->scope);
        $row = Db::name('operation_execution_intents')->order('id')->find();
        self::assertIsArray($row);
        Db::name('operation_execution_intents')->where('id', (int)$row['id'])->update([
            'deleted_at' => '2026-08-09 12:00:00',
        ]);

        try {
            (new CanonicalOtaDailyPlatformSelectionService())->resolve(80, 80, '2026-08-08');
            self::fail('Expected a deleted owner row to block recovery.');
        } catch (RuntimeException $exception) {
            self::assertSame('canonical_daily_platform_selection_intent_deleted', $exception->getMessage());
        }

        $wrongTenant = $this->scope;
        $wrongTenant['tenant_id'] = 81;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('canonical_daily_platform_selection_hotel_scope_invalid');
        (new CanonicalOtaDailyPlatformSelectionService())->assertScopeMayPersist($wrongTenant);
    }

    /** @param array<string,mixed> $scope @return array<int,int> */
    private function seedValidOwner(array $scope): array
    {
        $actionSetDigest = str_repeat('a', 64);
        $draftSetDigest = str_repeat('b', 64);
        $promotionDigest = str_repeat('c', 64);
        $factDigest = str_repeat('d', 64);
        $identityDigest = str_repeat('e', 64);
        $contractDigest = str_repeat('f', 64);
        $review = [
            'reviewer_contract_version' => 'canonical_ota_investigation_deterministic_review.v1',
            'formula_result_match' => true,
            'scope_match' => true,
            'boundary_match' => true,
            'verdict' => 'PASS',
            'process_status' => 'READY',
        ];
        $intentIds = [];
        foreach (CanonicalOtaInvestigationActionService::actionTypesForPlatform((string)$scope['platform']) as $index => $actionType) {
            $formula = ['formula_id' => 'fixture.' . $actionType . '.v1'];
            $result = ['result_status' => 'fixture_verified', 'sequence' => $index + 1];
            $snapshot = [
                'action_type' => $actionType,
                'formula_contract' => $formula,
                'deterministic_result' => $result,
                'deterministic_review' => $review,
                'external_action_triggered' => false,
                'ota_mutation_performed' => false,
                'causality_claimed' => false,
                'business_outcome_claimed' => false,
            ];
            $snapshot['action_content_digest'] = $this->canonicalDigest($snapshot, 'action_content_digest');
            $actionDigest = $snapshot['action_content_digest'];
            $intentEvidence = [
                'schema_version' => CanonicalOtaInvestigationActionService::ACTION_SET_VERSION,
                'draft_set_id' => 'legacy-fixture-draft',
                'draft_set_content_digest' => $draftSetDigest,
                'action_set_digest' => $actionSetDigest,
                'action_id' => 'legacy-action-' . ($index + 1),
                'action_code' => 'legacy-code-' . ($index + 1),
                'action_content_digest' => $actionDigest,
                'tenant_id' => $scope['tenant_id'],
                'hotel_id' => $scope['hotel_id'],
                'data_source_id' => $scope['data_source_id'],
                'sync_task_id' => $scope['task_id'],
                'row_id' => $scope['row_id'],
                'platform' => $scope['platform'],
                'target_date' => $scope['target_date'],
                'data_period' => $scope['data_period'],
                'promotion_content_digest' => $promotionDigest,
                'authoritative_fact_digest' => $factDigest,
                'platform_hotel_identity_digest' => $identityDigest,
                'contract_digest' => $contractDigest,
                'metric_scope' => 'ota_channel',
                'execution_scope' => 'analysis_only',
                'approval_authority' => 'system_goal_scoped_analysis',
                'human_approval_claimed' => false,
                'expected_delta_status' => 'not_quantified',
                'external_write' => false,
                'causality_claimed' => false,
                'outcome_claimed' => false,
            ];
            $now = '2026-08-09 10:15:00';
            $intentId = (int)Db::name('operation_execution_intents')->insertGetId([
                'tenant_id' => $scope['tenant_id'],
                'idempotency_key' => 'legacy-fixture-' . ($index + 1),
                'source_module' => CanonicalOtaInvestigationActionService::SOURCE_MODULE,
                'source_record_id' => $scope['row_id'],
                'hotel_id' => $scope['hotel_id'],
                'platform' => $scope['platform'],
                'object_type' => 'operation_checklist',
                'action_type' => $actionType,
                'date_start' => $scope['target_date'],
                'date_end' => $scope['target_date'],
                'current_value_json' => $this->json([]),
                'target_value_json' => $this->json([]),
                'evidence_json' => $this->json($intentEvidence),
                'expected_metric' => 'investigation_completion',
                'expected_delta' => null,
                'risk_level' => 'low',
                'status' => CanonicalOtaInvestigationActionService::INTENT_STATUS,
                'blocked_reason' => '',
                'review_remark' => 'fixture',
                'created_by' => 0,
                'approved_by' => 0,
                'approved_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ]);
            $taskId = (int)Db::name('operation_execution_tasks')->insertGetId([
                'tenant_id' => $scope['tenant_id'],
                'intent_id' => $intentId,
                'hotel_id' => $scope['hotel_id'],
                'execution_mode' => 'analysis_only',
                'operator_id' => 0,
                'target_value_json' => $this->json(['action_content_digest' => $actionDigest]),
                'current_value_json' => $this->json(['deterministic_result' => $result]),
                'blocked_reason' => '',
                'action_track_id' => 0,
                'result_status' => 'observing',
                'result_summary' => 'fixture',
                'status' => 'executed',
                'executed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ]);
            $receipt = [
                'schema_version' => CanonicalOtaInvestigationActionService::EVIDENCE_VERSION,
                'verification_authority' => 'canonical_ota_investigation_service',
                'source' => 'online_daily_data',
                'source_ref' => 'online_daily_data#' . $scope['row_id'],
                'tenant_id' => $scope['tenant_id'],
                'system_hotel_id' => $scope['hotel_id'],
                'data_source_id' => $scope['data_source_id'],
                'sync_task_id' => $scope['task_id'],
                'row_id' => $scope['row_id'],
                'platform' => $scope['platform'],
                'object_type' => 'operation_checklist',
                'date_start' => $scope['target_date'],
                'date_end' => $scope['target_date'],
                'data_period' => $scope['data_period'],
                'operation_intent_id' => $intentId,
                'operation_task_id' => $taskId,
                'action_type' => $actionType,
                'draft_action_code' => 'legacy-code-' . ($index + 1),
                'action_content_digest' => $actionDigest,
                'action_set_digest' => $actionSetDigest,
                'source_draft_set_digest' => $draftSetDigest,
                'promotion_content_digest' => $promotionDigest,
                'authoritative_fact_digest' => $factDigest,
                'platform_hotel_identity_digest' => $identityDigest,
                'contract_digest' => $contractDigest,
                'formula_contract' => $formula,
                'deterministic_result' => $result,
                'deterministic_review' => $review,
                'action_snapshot' => $snapshot,
                'metric_key' => 'investigation_completion',
                'database_written' => true,
                'readback_verified' => true,
                'readback_count' => 1,
                'readback_at' => $now,
                'validation_status' => 'verified',
                'failure_reason' => '',
                'execution_scope' => 'analysis_only',
                'external_write' => false,
                'external_action_triggered' => false,
                'ota_mutation_performed' => false,
                'causality_claimed' => false,
                'business_outcome_claimed' => false,
                'approval_authority' => 'system_goal_scoped_analysis',
            ];
            $receipt['content_digest'] = $this->canonicalDigest($receipt);
            Db::name('operation_execution_evidence')->insert([
                'tenant_id' => $scope['tenant_id'],
                'task_id' => $taskId,
                'evidence_type' => CanonicalOtaInvestigationActionService::EVIDENCE_TYPE,
                'before_json' => $this->json([]),
                'after_json' => $this->json([]),
                'attachment_path' => '',
                'platform_response_json' => $this->json(['analysis_receipt' => $receipt]),
                'remark' => 'fixture',
                'created_by' => 0,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ]);
            $intentIds[] = $intentId;
        }
        sort($intentIds, SORT_NUMERIC);
        return $intentIds;
    }

    private function canonicalDigest(array $value, string $digestField = 'content_digest'): string
    {
        unset($value[$digestField]);
        return hash('sha256', $this->json($this->canonicalize($value)));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }

    private function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    private static function createSchema(): void
    {
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL)');
        Db::execute('CREATE TABLE operation_execution_intents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER,
            idempotency_key VARCHAR(191) UNIQUE,
            source_module VARCHAR(80) NOT NULL DEFAULT \'\',
            source_record_id INTEGER NOT NULL DEFAULT 0,
            hotel_id INTEGER NOT NULL DEFAULT 0,
            platform VARCHAR(40) NOT NULL DEFAULT \'\',
            object_type VARCHAR(30) NOT NULL DEFAULT \'\',
            action_type VARCHAR(80) NOT NULL DEFAULT \'\',
            date_start DATE NOT NULL,
            date_end DATE,
            current_value_json TEXT,
            target_value_json TEXT,
            evidence_json TEXT,
            expected_metric VARCHAR(50) NOT NULL DEFAULT \'\',
            expected_delta DECIMAL(20,6) NULL,
            risk_level VARCHAR(30) NOT NULL DEFAULT \'medium\',
            status VARCHAR(30) NOT NULL DEFAULT \'pending_approval\',
            blocked_reason VARCHAR(500) NOT NULL DEFAULT \'\',
            review_remark VARCHAR(500) NOT NULL DEFAULT \'\',
            created_by INTEGER NOT NULL DEFAULT 0,
            approved_by INTEGER NOT NULL DEFAULT 0,
            approved_at DATETIME,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            deleted_at DATETIME
        )');
        Db::execute('CREATE TABLE operation_execution_tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER,
            intent_id INTEGER NOT NULL DEFAULT 0,
            hotel_id INTEGER NOT NULL DEFAULT 0,
            execution_mode VARCHAR(30) NOT NULL DEFAULT \'manual\',
            operator_id INTEGER NOT NULL DEFAULT 0,
            target_value_json TEXT,
            current_value_json TEXT,
            blocked_reason VARCHAR(500) NOT NULL DEFAULT \'\',
            action_track_id INTEGER NOT NULL DEFAULT 0,
            result_status VARCHAR(30) NOT NULL DEFAULT \'observing\',
            result_summary VARCHAR(500) NOT NULL DEFAULT \'\',
            status VARCHAR(30) NOT NULL DEFAULT \'pending_execute\',
            executed_at DATETIME,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            deleted_at DATETIME
        )');
        Db::execute('CREATE TABLE operation_execution_evidence (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER,
            task_id INTEGER NOT NULL DEFAULT 0,
            evidence_type VARCHAR(50) NOT NULL DEFAULT \'manual\',
            before_json TEXT,
            after_json TEXT,
            attachment_path VARCHAR(500) NOT NULL DEFAULT \'\',
            platform_response_json TEXT,
            remark VARCHAR(500) NOT NULL DEFAULT \'\',
            created_by INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            deleted_at DATETIME
        )');
    }
}
