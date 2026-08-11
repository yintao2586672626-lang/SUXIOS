<?php
declare(strict_types=1);

namespace Tests;

use app\service\CanonicalOtaInvestigationActionService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class CanonicalOtaInvestigationActionServiceTest extends TestCase
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
            . 'canonical_ota_investigation_actions_' . getmypid() . '.sqlite';
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
            throw new RuntimeException('Unable to remove canonical action SQLite fixture.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['operation_execution_evidence', 'operation_execution_tasks', 'operation_execution_intents'] as $table) {
            Db::name($table)->delete(true);
        }
        if ((int)Db::name('hotels')->where('id', 80)->count() === 0) {
            Db::name('hotels')->insert(['id' => 80, 'tenant_id' => 80]);
        }
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

    public function testPreflightBuildsFourDeterministicChecksWithoutDatabaseWrites(): void
    {
        $result = $this->service()->preflight($this->scope);

        self::assertSame('ready', $result['status']);
        self::assertFalse($result['execute']);
        self::assertSame(4, $result['planned_operational_check_count']);
        self::assertSame(0, $result['trusted_operational_check_count']);
        self::assertSame(0, $result['trusted_external_operation_count']);
        self::assertFalse($result['external_action_triggered']);
        self::assertFalse($result['business_outcome_claimed']);
        self::assertSame(0, Db::name('operation_execution_intents')->count());

        $actions = $result['action_set']['actions'];
        self::assertSame('18.82352941', $actions[0]['deterministic_result']['computed_rate_8dp']);
        self::assertSame('18.82', $actions[0]['deterministic_result']['computed_rate_2dp']);
        self::assertSame('18.82', $actions[0]['deterministic_result']['observed_rate_2dp']);
        self::assertSame('-0.00352941', $actions[0]['deterministic_result']['signed_delta_pp_8dp']);
        self::assertSame('consistent_at_2dp', $actions[0]['deterministic_result']['result_status']);
        self::assertSame('0.00', $actions[1]['deterministic_result']['computed_rate_2dp']);
        self::assertSame('positive_detail_zero_fill_observed', $actions[1]['deterministic_result']['result_status']);
        self::assertNull($actions[2]['deterministic_result']['computed_rate_2dp']);
        self::assertSame('not_computable_zero_denominator', $actions[2]['deterministic_result']['calculation_status']);
        self::assertNull($actions[3]['deterministic_result']['runtime_collection_eligible']);
        self::assertSame('not_checked', $actions[3]['deterministic_result']['fresh_profile_session_preflight']);
    }

    public function testActionTypesForMeituanExposeIndependentFourActionManifest(): void
    {
        self::assertSame([
            'meituan_list_detail_count_order_check',
            'meituan_list_detail_rate_check',
            'meituan_observed_flow_rate_alignment_check',
            'same_scope_recollection_eligibility_check',
        ], CanonicalOtaInvestigationActionService::actionTypesForPlatform('meituan'));
    }

    public function testMeituanPreflightUsesOnlyThreeObservedMetricsAndIndependentFormulas(): void
    {
        $scope = $this->meituanScope();
        $result = $this->service($this->draftResult([], $scope))->preflight($scope);
        $actions = $result['action_set']['actions'];

        self::assertSame([
            'meituan_list_detail_count_order_check',
            'meituan_list_detail_rate_check',
            'meituan_observed_flow_rate_alignment_check',
            'same_scope_recollection_eligibility_check',
        ], array_column($actions, 'action_type'));
        self::assertSame([
            'meituan_list_detail_count_order.v1',
            'meituan_list_to_detail_rate.v1',
            'meituan_observed_flow_rate_alignment.v1',
            'meituan_same_scope_recollection_eligibility.v1',
        ], array_column(array_column($actions, 'formula_contract'), 'formula_id'));
        self::assertSame(900, $actions[0]['deterministic_result']['arithmetic_count_difference']);
        self::assertSame('consistent', $actions[0]['deterministic_result']['count_order_status']);
        self::assertSame('25.00000000', $actions[1]['deterministic_result']['computed_rate_8dp']);
        self::assertSame('25.00', $actions[1]['deterministic_result']['computed_rate_2dp']);
        self::assertSame('25.00', $actions[2]['deterministic_result']['observed_rate_2dp']);
        self::assertSame('0.00000000', $actions[2]['deterministic_result']['signed_delta_pp_8dp']);
        self::assertSame('consistent_at_2dp', $actions[2]['deterministic_result']['result_status']);

        $serialized = strtolower(json_encode(
            $result['action_set'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
        self::assertStringNotContainsString('ctrip', $serialized);
        self::assertStringNotContainsString('order_filling_num', $serialized);
        self::assertStringNotContainsString('order_submit_num', $serialized);
    }

    public function testMeituanZeroListExposureRemainsNotComputableInsteadOfSyntheticZeroRate(): void
    {
        $scope = $this->meituanScope();
        $draft = $this->draftResult([
            'list_exposure' => 0,
            'detail_exposure' => 0,
            'flow_rate' => 0,
        ], $scope);

        $actions = $this->service($draft)->preflight($scope)['action_set']['actions'];

        self::assertNull($actions[1]['deterministic_result']['computed_rate_8dp']);
        self::assertNull($actions[1]['deterministic_result']['computed_rate_2dp']);
        self::assertSame(
            'not_computable_zero_denominator',
            $actions[1]['deterministic_result']['calculation_status']
        );
        self::assertNull($actions[2]['deterministic_result']['computed_rate_8dp']);
        self::assertNull($actions[2]['deterministic_result']['computed_rate_2dp']);
        self::assertNull($actions[2]['deterministic_result']['signed_delta_pp_8dp']);
        self::assertSame(
            'not_computable_zero_denominator',
            $actions[2]['deterministic_result']['calculation_status']
        );
    }

    public function testMeituanExecutePersistsFourAnalysisOnlyTripletsAndExactFlowReadback(): void
    {
        $scope = $this->meituanScope();
        $this->scope = $scope;

        $result = $this->service($this->draftResult([], $scope))->execute($scope);

        self::assertSame('completed', $result['status']);
        self::assertTrue($result['db_readback_verified']);
        self::assertTrue($result['operation_flow_readback_verified']);
        self::assertSame(4, $result['trusted_operational_check_count']);
        self::assertSame(0, $result['trusted_external_operation_count']);
        self::assertFalse($result['external_action_triggered']);
        self::assertFalse($result['business_outcome_claimed']);
        self::assertSame('meituan', $result['action_set']['scope']['platform']);
        self::assertSame('meituan', $result['daily_platform_selection']['selected_platform']);
        self::assertSame('intent_evidence', $result['daily_platform_selection']['owner_source']);
        self::assertTrue($result['daily_platform_selection']['readback_verified']);
        self::assertSame('source_verified', $result['flow_summary']['evidence_status']);
        self::assertCount(4, $result['records']);
        self::assertSame(4, Db::name('operation_execution_intents')->where('platform', 'meituan')->count());
        self::assertSame(4, Db::name('operation_execution_tasks')->count());
        self::assertSame(4, Db::name('operation_execution_evidence')->count());
    }

    public function testScopeKeepsTenantAndHotelAsIndependentPositiveIdentities(): void
    {
        $method = new \ReflectionMethod(CanonicalOtaInvestigationActionService::class, 'normalizeScope');
        $scope = $this->scope;
        $scope['tenant_id'] = 1;
        $scope['hotel_id'] = 80;

        $normalized = $method->invoke($this->service(), $scope);

        self::assertSame(1, $normalized['tenant_id']);
        self::assertSame(80, $normalized['hotel_id']);
    }

    public function testExecutePersistsFourAnalysisOnlyTripletsAndExactFlowReadback(): void
    {
        $result = $this->service()->execute($this->scope);

        self::assertSame('completed', $result['status']);
        self::assertFalse($result['idempotent']);
        self::assertTrue($result['db_readback_verified']);
        self::assertTrue($result['operation_flow_readback_verified']);
        self::assertSame(4, $result['trusted_operational_check_count']);
        self::assertSame(0, $result['trusted_external_operation_count']);
        self::assertFalse($result['effect_review_written']);
        self::assertFalse($result['action_track_written']);
        self::assertFalse($result['external_action_triggered']);
        self::assertFalse($result['business_outcome_claimed']);
        self::assertCount(4, $result['records']);
        self::assertSame('source_verified', $result['flow_summary']['evidence_status']);
        self::assertSame('unverified_observing', $result['flow_summary']['outcome_status']);
        self::assertSame('ctrip', $result['daily_platform_selection']['selected_platform']);
        self::assertSame('intent_evidence', $result['daily_platform_selection']['owner_source']);
        self::assertTrue($result['daily_platform_selection']['readback_verified']);

        self::assertSame(4, Db::name('operation_execution_intents')->count());
        self::assertSame(4, Db::name('operation_execution_tasks')->count());
        self::assertSame(4, Db::name('operation_execution_evidence')->count());
        self::assertSame(4, Db::name('operation_execution_intents')
            ->where('status', CanonicalOtaInvestigationActionService::INTENT_STATUS)->count());
        self::assertSame(4, Db::name('operation_execution_tasks')
            ->where('execution_mode', 'analysis_only')
            ->where('status', 'executed')
            ->where('result_status', 'observing')
            ->where('action_track_id', 0)
            ->count());
        self::assertSame(4, Db::name('operation_execution_evidence')
            ->where('evidence_type', CanonicalOtaInvestigationActionService::EVIDENCE_TYPE)
            ->where('created_by', 0)
            ->count());

        $intent = Db::name('operation_execution_intents')
            ->where('action_type', 'list_detail_math_check')
            ->find();
        self::assertIsArray($intent);
        self::assertSame(0, (int)$intent['approved_by']);
        self::assertSame('', trim((string)$intent['approved_at']));
        self::assertNull($intent['expected_delta']);
        $intentEvidence = json_decode((string)$intent['evidence_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($intentEvidence['human_approval_claimed']);
        self::assertFalse($intentEvidence['external_write']);
        self::assertFalse($intentEvidence['causality_claimed']);
        self::assertFalse($intentEvidence['outcome_claimed']);
        self::assertSame('ctrip', $intentEvidence['owner_platform']);
        self::assertSame(
            \app\service\CanonicalOtaDailyPlatformSelectionService::POLICY,
            $intentEvidence['selection_policy']
        );
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $intentEvidence['owner_scope_digest']);
    }

    public function testExecuteIsIdempotentWithStableRecordIdsAndNoDuplicateEvidence(): void
    {
        $first = $this->service()->execute($this->scope);
        $second = $this->service()->execute($this->scope);

        self::assertFalse($first['idempotent']);
        self::assertTrue($second['idempotent']);
        self::assertSame($first['records'], $second['records']);
        self::assertSame($first['action_set_digest'], $second['action_set_digest']);
        self::assertSame($first['daily_platform_selection'], $second['daily_platform_selection']);
        self::assertSame(4, Db::name('operation_execution_intents')->count());
        self::assertSame(4, Db::name('operation_execution_tasks')->count());
        self::assertSame(4, Db::name('operation_execution_evidence')->count());
    }

    public function testDailyOwnerBlocksSecondPlatformInsteadOfCreatingEightChecks(): void
    {
        $ctrip = $this->service()->execute($this->scope);
        self::assertSame('ctrip', $ctrip['daily_platform_selection']['selected_platform']);

        $meituanScope = $this->meituanScope();
        $meituanService = $this->service($this->draftResult([], $meituanScope));
        try {
            $meituanService->execute($meituanScope);
            self::fail('Expected the durable daily platform owner to block a second platform.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'canonical_daily_platform_selection_owner_scope_conflict',
                $exception->getMessage()
            );
        }

        self::assertSame(4, Db::name('operation_execution_intents')->count());
        self::assertSame(4, Db::name('operation_execution_tasks')->count());
        self::assertSame(4, Db::name('operation_execution_evidence')->count());
        self::assertSame(0, Db::name('operation_execution_intents')->where('platform', 'meituan')->count());
    }

    public function testScheduledExecutionPersistsExactAuthorizationReceiptAndReplaysIdempotently(): void
    {
        $authorization = [
            'schema_version' => CanonicalOtaInvestigationActionService::SCHEDULED_AUTHORIZATION_VERSION,
            'enabled' => true,
            'plan_id' => 'hotel80_ctrip_daily_goal_019fe32a_v1',
            'tenant_id' => 80,
            'hotel_id' => 80,
            'platform' => 'ctrip',
            'trigger' => 'historical_daily_canonical_promotion',
            'authorized_at' => '2026-08-09T10:00:00+08:00',
            'authorized_by' => 'user_goal',
            'analysis_only' => true,
            'operation_count' => 4,
            'external_action_allowed' => false,
        ];
        $digestService = $this->service();
        $digest = new \ReflectionMethod($digestService, 'digest');
        $authorization['content_digest'] = $digest->invoke($digestService, $authorization);
        $grantResolver = static function (
            array $candidate,
            int $tenantId,
            int $hotelId,
            string $platform
        ) use ($authorization): array {
            if ($tenantId !== 80 || $hotelId !== 80 || $platform !== 'ctrip' || $candidate !== $authorization) {
                throw new RuntimeException('canonical_scheduled_analysis_grant_mismatch');
            }
            return $authorization;
        };
        $service = $this->service(null, null, null, $grantResolver);

        $first = $service->executeScheduled($this->scope, $authorization);
        $second = $service->executeScheduled($this->scope, $authorization);

        self::assertFalse($first['idempotent']);
        self::assertTrue($second['idempotent']);
        self::assertSame($first['records'], $second['records']);
        self::assertSame(4, Db::name('operation_execution_intents')->count());
        self::assertSame(4, Db::name('operation_execution_tasks')->count());
        self::assertSame(4, Db::name('operation_execution_evidence')->count());

        $intent = Db::name('operation_execution_intents')->order('id')->find();
        self::assertIsArray($intent);
        $intentEvidence = json_decode((string)$intent['evidence_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('system_scheduled_analysis', $intentEvidence['approval_authority']);
        self::assertSame($authorization, $intentEvidence['scheduled_analysis_authorization']);
        self::assertSame($authorization['content_digest'], $intentEvidence['scheduled_analysis_authorization_digest']);

        $evidence = Db::name('operation_execution_evidence')->order('id')->find();
        self::assertIsArray($evidence);
        $wrapper = json_decode((string)$evidence['platform_response_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('system_scheduled_analysis', $wrapper['analysis_receipt']['approval_authority']);
        self::assertSame($authorization, $wrapper['analysis_receipt']['scheduled_analysis_authorization']);
    }

    public function testSelfRehashedFabricatedScheduledAuthorizationCannotWriteWithoutServerGrant(): void
    {
        $authorization = [
            'schema_version' => CanonicalOtaInvestigationActionService::SCHEDULED_AUTHORIZATION_VERSION,
            'enabled' => true,
            'plan_id' => 'forged_but_rehashed_plan',
            'tenant_id' => 80,
            'hotel_id' => 80,
            'platform' => 'ctrip',
            'trigger' => 'historical_daily_canonical_promotion',
            'authorized_at' => '2026-08-09T10:00:00+08:00',
            'authorized_by' => 'user_goal',
            'analysis_only' => true,
            'operation_count' => 4,
            'external_action_allowed' => false,
        ];
        $serviceForDigest = $this->service();
        $digest = new \ReflectionMethod($serviceForDigest, 'digest');
        $authorization['content_digest'] = $digest->invoke($serviceForDigest, $authorization);
        $service = $this->service(null, null, null, static function (
            array $candidate,
            int $tenantId,
            int $hotelId,
            string $platform
        ): array {
            throw new RuntimeException('canonical_scheduled_analysis_grant_mismatch');
        });

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('canonical_action_scheduled_authorization_not_granted');
        try {
            $service->executeScheduled($this->scope, $authorization);
        } finally {
            self::assertSame(0, Db::name('operation_execution_intents')->count());
            self::assertSame(0, Db::name('operation_execution_tasks')->count());
            self::assertSame(0, Db::name('operation_execution_evidence')->count());
        }
    }

    public function testAnyMidBatchFailureRollsBackTheWholeFourActionSet(): void
    {
        $service = $this->service(null, static function (int $index): void {
            if ($index === 2) {
                throw new RuntimeException('canonical_action_test_injected_failure');
            }
        });

        try {
            $service->execute($this->scope);
            self::fail('Expected injected failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('canonical_action_test_injected_failure', $exception->getMessage());
        }
        self::assertSame(0, Db::name('operation_execution_intents')->count());
        self::assertSame(0, Db::name('operation_execution_tasks')->count());
        self::assertSame(0, Db::name('operation_execution_evidence')->count());
    }

    public function testCanonicalAnalysisCannotBeMutatedThroughOperatorEvidenceOrReviewApis(): void
    {
        $result = $this->service()->execute($this->scope);
        $taskId = (int)$result['records'][0]['task_id'];
        $service = new \app\service\OperationManagementService();

        try {
            $service->addExecutionEvidence($taskId, [80], [
                'evidence_type' => 'manual',
                'evidence' => ['remark' => 'must not be appended'],
            ], 7);
            self::fail('Expected immutable canonical evidence boundary.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('system-authorized analysis task is immutable', $exception->getMessage());
        }

        try {
            $service->reviewExecutionTask($taskId, [80], [
                'result_status' => 'failed',
                'result_summary' => 'must not overwrite the deterministic result',
            ], 7);
            self::fail('Expected immutable canonical review boundary.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('system-authorized analysis task is immutable', $exception->getMessage());
        }

        self::assertSame(4, Db::name('operation_execution_evidence')->count());
        $task = Db::name('operation_execution_tasks')->where('id', $taskId)->find();
        self::assertIsArray($task);
        self::assertSame('executed', (string)$task['status']);
        self::assertSame('observing', (string)$task['result_status']);
        self::assertSame(0, (int)$task['action_track_id']);
    }

    public function testExactScopeDriftFailsClosedBeforeAnyWrite(): void
    {
        $scope = $this->scope;
        $scope['row_id'] = 81819;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('canonical_action_saved_draft_readback_required');
        try {
            $this->service()->execute($scope);
        } finally {
            self::assertSame(0, Db::name('operation_execution_intents')->count());
        }
    }

    public function testListAndDetailZeroNeverBecomeAZeroPercentClaim(): void
    {
        $draft = $this->draftResult(['list_exposure' => 0, 'detail_exposure' => 0]);
        $result = $this->service($draft)->preflight($this->scope);

        $listDetail = $result['action_set']['actions'][0]['deterministic_result'];
        $detailFill = $result['action_set']['actions'][1]['deterministic_result'];
        self::assertNull($listDetail['computed_rate_8dp']);
        self::assertSame('not_computable_zero_denominator', $listDetail['calculation_status']);
        self::assertNull($detailFill['computed_rate_2dp']);
        self::assertSame('not_computable_zero_denominator', $detailFill['calculation_status']);
    }

    public function testFractionalTrafficCountIsRejectedInsteadOfRounded(): void
    {
        $draft = $this->draftResult(['detail_exposure' => '96.5']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('canonical_action_metric_non_negative_integer_required:detail_exposure');
        $this->service($draft)->preflight($this->scope);
    }

    public function testDatabaseDecimalStringsWithOnlyZeroFractionRemainExactIntegers(): void
    {
        $draft = $this->draftResult([
            'list_exposure' => '510.00',
            'detail_exposure' => '96.0000',
            'order_filling_num' => '0.00',
            'order_submit_num' => '0.000000',
        ]);

        $result = $this->service($draft)->preflight($this->scope);

        self::assertSame(510, $result['action_set']['actions'][0]['deterministic_result']['inputs']['list_exposure']);
        self::assertSame(96, $result['action_set']['actions'][0]['deterministic_result']['inputs']['detail_exposure']);
        self::assertSame('18.82', $result['action_set']['actions'][0]['deterministic_result']['computed_rate_2dp']);
    }

    public function testTamperedPersistedBoundaryIsNotSourceVerified(): void
    {
        $this->service()->execute($this->scope);
        $evidence = Db::name('operation_execution_evidence')->order('id', 'asc')->find();
        self::assertIsArray($evidence);
        $wrapper = json_decode((string)$evidence['platform_response_json'], true, 512, JSON_THROW_ON_ERROR);
        $wrapper['analysis_receipt']['external_action_triggered'] = true;
        $wrapper['analysis_receipt']['content_digest'] = $this->canonicalDigest($wrapper['analysis_receipt']);
        Db::name('operation_execution_evidence')->where('id', (int)$evidence['id'])->update([
            'platform_response_json' => json_encode($wrapper, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);

        $flow = (new \app\service\OperationManagementService())->executionFlow(
            [80],
            80,
            ['source_module' => CanonicalOtaInvestigationActionService::SOURCE_MODULE, 'target_date' => '2026-08-08']
        );
        $item = array_values(array_filter($flow['list'], static fn(array $candidate): bool =>
            (int)$candidate['execution']['task_id'] === (int)$evidence['task_id']))[0];
        self::assertFalse($item['evidence_truth']['source_verified']);
        self::assertSame('evidence', $item['stage']);
        self::assertContains(
            'canonical_analysis_protected_boundary_invalid',
            $item['evidence_truth']['failure_reasons']
        );
    }

    public function testTamperedDeterministicResultCannotSelfVerifyByRehashingReceipt(): void
    {
        $this->service()->execute($this->scope);
        $evidence = Db::name('operation_execution_evidence')->order('id', 'asc')->find();
        self::assertIsArray($evidence);
        $wrapper = json_decode((string)$evidence['platform_response_json'], true, 512, JSON_THROW_ON_ERROR);
        $wrapper['analysis_receipt']['deterministic_result']['computed_rate_2dp'] = '99.99';
        $wrapper['analysis_receipt']['content_digest'] = $this->canonicalDigest($wrapper['analysis_receipt']);
        Db::name('operation_execution_evidence')->where('id', (int)$evidence['id'])->update([
            'platform_response_json' => json_encode($wrapper, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);

        $flow = (new \app\service\OperationManagementService())->executionFlow(
            [80],
            80,
            ['source_module' => CanonicalOtaInvestigationActionService::SOURCE_MODULE, 'target_date' => '2026-08-08']
        );
        $item = array_values(array_filter($flow['list'], static fn(array $candidate): bool =>
            (int)$candidate['execution']['task_id'] === (int)$evidence['task_id']))[0];
        self::assertFalse($item['evidence_truth']['source_verified']);
        self::assertContains(
            'canonical_analysis_deterministic_review_invalid',
            $item['evidence_truth']['failure_reasons']
        );
    }

    public function testTamperedCanonicalScopeCannotSelfVerifyByRehashingReceipt(): void
    {
        $this->service()->execute($this->scope);
        $evidence = Db::name('operation_execution_evidence')->order('id', 'asc')->find();
        self::assertIsArray($evidence);
        $originalJson = (string)$evidence['platform_response_json'];
        $originalWrapper = json_decode($originalJson, true, 512, JSON_THROW_ON_ERROR);

        foreach ([
            'data_source_id' => 26,
            'sync_task_id' => 3093,
            'data_period' => 'realtime_snapshot',
        ] as $field => $tamperedValue) {
            $wrapper = $originalWrapper;
            $wrapper['analysis_receipt'][$field] = $tamperedValue;
            $wrapper['analysis_receipt']['content_digest'] = $this->canonicalDigest($wrapper['analysis_receipt']);
            Db::name('operation_execution_evidence')->where('id', (int)$evidence['id'])->update([
                'platform_response_json' => json_encode(
                    $wrapper,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                ),
            ]);

            $flow = (new \app\service\OperationManagementService())->executionFlow(
                [80],
                80,
                ['source_module' => CanonicalOtaInvestigationActionService::SOURCE_MODULE, 'target_date' => '2026-08-08']
            );
            $item = array_values(array_filter($flow['list'], static fn(array $candidate): bool =>
                (int)$candidate['execution']['task_id'] === (int)$evidence['task_id']))[0];
            self::assertFalse($item['evidence_truth']['source_verified'], $field);
            self::assertContains(
                $field === 'data_period'
                    ? 'canonical_analysis_scope_alignment_invalid'
                    : 'canonical_analysis_source_identity_invalid',
                $item['evidence_truth']['failure_reasons'],
                $field
            );

            Db::name('operation_execution_evidence')->where('id', (int)$evidence['id'])->update([
                'platform_response_json' => $originalJson,
            ]);
        }
    }

    public function testOperationFlowReadbackFailureRollsBackWholeActionSet(): void
    {
        $service = $this->service(
            null,
            null,
            static fn(array $hotelIds, int $hotelId, array $filters): array => ['list' => []]
        );

        try {
            $service->execute($this->scope);
            self::fail('Expected exact operation-flow readback failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('canonical_action_operation_flow_membership_invalid', $exception->getMessage());
        }
        self::assertSame(0, Db::name('operation_execution_intents')->count());
        self::assertSame(0, Db::name('operation_execution_tasks')->count());
        self::assertSame(0, Db::name('operation_execution_evidence')->count());
    }

    public function testSourceDriftBetweenPreflightAndTransactionFailsBeforeAnyWrite(): void
    {
        $first = $this->draftResult();
        $second = $this->draftResult(['flow_rate' => '18.83']);
        $calls = 0;
        $service = new CanonicalOtaInvestigationActionService(
            static function (array $scope) use (&$calls, $first, $second): array {
                $calls++;
                return $calls === 1 ? $first : $second;
            },
            null,
            static fn(): string => '2026-08-09 10:15:00'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('canonical_action_source_drift_detected');
        try {
            $service->execute($this->scope);
        } finally {
            self::assertSame(0, Db::name('operation_execution_intents')->count());
            self::assertSame(0, Db::name('operation_execution_tasks')->count());
            self::assertSame(0, Db::name('operation_execution_evidence')->count());
        }
    }

    private function service(
        ?array $draftResult = null,
        ?callable $beforePersist = null,
        ?callable $flowReader = null,
        ?callable $scheduledAuthorizationResolver = null
    ): CanonicalOtaInvestigationActionService
    {
        $draftResult ??= $this->draftResult();
        if ($flowReader === null && $scheduledAuthorizationResolver !== null) {
            $flowReader = static function (
                array $hotelIds,
                int $hotelId,
                array $filters
            ) use ($scheduledAuthorizationResolver): array {
                $outcome = new \app\service\operation\ExecutionOutcomeService();
                $reader = new \app\service\operation\ExecutionFlowReadService(
                    $outcome,
                    $scheduledAuthorizationResolver
                );
                return (new \app\service\OperationManagementService(null, $outcome, $reader))
                    ->executionFlow($hotelIds, $hotelId, $filters);
            };
        }
        return new CanonicalOtaInvestigationActionService(
            static function (array $scope) use ($draftResult): array {
                return $scope === ($draftResult['scope'] ?? null)
                    ? $draftResult
                    : [
                        'status' => 'blocked',
                        'scope' => $scope,
                        'readback_verified' => false,
                        'idempotent' => false,
                        'draft_count' => 0,
                        'draft_set' => [],
                    ];
            },
            $beforePersist,
            static fn(): string => '2026-08-09 10:15:00',
            $flowReader,
            $scheduledAuthorizationResolver
        );
    }

    /**
     * @param array<string,mixed> $metricOverrides
     * @param array<string,mixed>|null $scope
     * @return array<string,mixed>
     */
    private function draftResult(array $metricOverrides = [], ?array $scope = null): array
    {
        $scope ??= $this->scope;
        $platform = (string)$scope['platform'];
        $baseMetrics = $platform === 'meituan'
            ? [
                'list_exposure' => 1200,
                'detail_exposure' => 300,
                'flow_rate' => 25,
            ]
            : [
                'list_exposure' => 510,
                'detail_exposure' => 96,
                'flow_rate' => '18.82',
                'order_filling_num' => 0,
                'order_submit_num' => 0,
            ];
        $metrics = array_replace($baseMetrics, $metricOverrides);
        $hasNonzero = false;
        foreach ($metrics as $value) {
            if (abs((float)$value) > 0.000001) {
                $hasNonzero = true;
                break;
            }
        }
        $sourceFact = [
            'canonical_row' => 'online_daily_data#' . $scope['row_id'],
            'sync_task' => 'platform_data_sync_tasks#' . $scope['task_id'],
            'tenant_id' => $scope['tenant_id'],
            'hotel_id' => $scope['hotel_id'],
            'platform' => $platform,
            'data_source_id' => $scope['data_source_id'],
            'sync_task_id' => $scope['task_id'],
            'row_id' => $scope['row_id'],
            'target_date' => $scope['target_date'],
            'data_period' => $scope['data_period'],
            'validation_status' => 'verified',
            'history_status' => 'success',
            'readback_verified' => true,
            'p0_status' => 'ready',
            'traffic_attribution' => 'authoritative_p0',
            'promotion_version' => 'ota_canonical_history_promotion.v3',
            'promotion_content_digest' => str_repeat('a', 64),
            'authoritative_fact_digest' => str_repeat('b', 64),
            'promotion_authoritative_fact_digest' => str_repeat('c', 64),
            'platform_hotel_identity_digest' => str_repeat('d', 64),
            'promotion_verified_at' => '2026-08-09 09:28:00',
            'run_readback_row_ids' => [$scope['row_id']],
            'traffic_metric_values' => $metrics,
            'traffic_value_status' => $hasNonzero ? 'nonzero' : 'explicit_zero',
            'nonzero_required_metric_rows' => $hasNonzero ? 1 : 0,
            'explicit_zero_confirmed_rows' => $hasNonzero ? 0 : 1,
        ];
        $definitions = [
            ['check_list_to_detail_mathematical_consistency', '列表到详情数学一致性核查'],
            ['investigate_detail_to_order_fill_breakpoint', '详情到订单填写断点核查'],
            ['investigate_fill_to_submit_chain', '订单填写到提交链路核查'],
            ['prepare_same_scope_recollection_and_entry_eligibility_check', '同范围复采与入口资格核查'],
        ];
        if ($platform === 'meituan') {
            $definitions = [
                ['check_meituan_list_detail_count_order', 'Meituan list and detail count order check'],
                ['calculate_meituan_list_to_detail_rate', 'Meituan list to detail rate calculation'],
                ['check_meituan_observed_flow_rate_alignment', 'Meituan observed flow rate alignment check'],
                ['prepare_same_scope_recollection_and_entry_eligibility_check', 'Meituan same-scope recollection eligibility check'],
            ];
        }
        $drafts = [];
        foreach ($definitions as $index => [$code, $title]) {
            $drafts[] = [
                'draft_id' => $code . '-' . sprintf('%02d', $index + 1),
                'hotel_id' => $scope['hotel_id'],
                'platform' => $platform,
                'target_date' => $scope['target_date'],
                'action_code' => $code,
                'action_kind' => 'investigation_check',
                'title' => $title,
                'action_text' => '执行该项确定性核查，不触发外部动作。',
                'acceptance_criteria' => ['精确范围一致', '无因果或成效声明'],
                'causality_claimed' => false,
                'outcome_claimed' => false,
                'cause_status' => 'unknown_requires_investigation',
                'assignee' => null,
                'due' => null,
                'reviewer' => null,
                'review_at' => null,
                'assignment_status' => 'blocked_by_missing_assignee',
                'due_status' => 'blocked_by_missing_due',
                'review_status' => 'blocked_by_missing_reviewer_and_review_at',
                'approval_status' => 'blocked_by_missing_assignment_due_review',
                'execution_status' => 'not_authorized',
                'evidence_refs' => [$sourceFact],
                'protected_boundary' => 'Investigation/check only.',
            ];
        }
        $draftSet = [
            'schema_version' => 'canonical_ota_investigation_drafts.v2',
            'draft_set_id' => 'canonical_ota_investigation_' . str_repeat('e', 24),
            'idempotency_key' => str_repeat('f', 64),
            'scope' => $scope,
            'draft_status' => 'blocked_by_missing_assignment_due_review',
            'approval_status' => 'not_submitted',
            'execution_status' => 'not_authorized',
            'causality_claimed' => false,
            'source_fact' => $sourceFact,
            'draft_count' => 4,
            'drafts' => $drafts,
            'storage_policy' => 'local_runtime_json_only',
            'protected_boundary' => 'Investigation drafts only.',
        ];
        $draftSet['content_digest'] = $this->legacyDraftDigest($draftSet);
        return [
            'status' => 'ready',
            'execute' => false,
            'would_write' => false,
            'idempotent' => true,
            'readback_verified' => true,
            'draft_count' => 4,
            'content_digest' => $draftSet['content_digest'],
            'scope' => $scope,
            'draft_set' => $draftSet,
        ];
    }

    /** @return array<string,mixed> */
    private function meituanScope(): array
    {
        return [
            'tenant_id' => 80,
            'hotel_id' => 80,
            'data_source_id' => 68,
            'task_id' => 6800,
            'row_id' => 6801,
            'platform' => 'meituan',
            'target_date' => '2026-08-08',
            'data_period' => 'historical_daily',
        ];
    }

    /** @param array<string,mixed> $value */
    private function legacyDraftDigest(array $value): string
    {
        unset($value['content_digest']);
        ksort($value, SORT_STRING);
        return hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed> $value */
    private function canonicalDigest(array $value): string
    {
        unset($value['content_digest']);
        return hash('sha256', json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
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

    private static function createSchema(): void
    {
        Db::execute('CREATE TABLE hotels (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL
        )');
        Db::execute('CREATE TABLE operation_execution_intents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER,
            idempotency_key VARCHAR(191) UNIQUE,
            source_module VARCHAR(80) NOT NULL DEFAULT \'\',
            source_record_id INTEGER NOT NULL DEFAULT 0,
            hotel_id INTEGER NOT NULL DEFAULT 0,
            platform VARCHAR(40) NOT NULL DEFAULT \'\',
            object_type VARCHAR(30) NOT NULL DEFAULT \'\',
            action_type VARCHAR(50) NOT NULL DEFAULT \'\',
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
