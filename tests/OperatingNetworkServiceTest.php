<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperatingNetworkService;
use app\service\OperationManagementService;
use app\service\OperatingSopService;
use app\service\operation\OperationEffectReviewService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OperatingNetworkServiceTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App(dirname(__DIR__));
        $app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'operating_network_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.sqlite';
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
        foreach ([
            'hotel_operating_sop_replication_reviews',
            'hotel_operating_profiles',
            'hotel_operating_sop_replications',
            'hotel_operating_sop_versions',
            'hotel_operating_cycle_evidence',
            'hotel_operating_cycle_events',
            'hotel_operating_cycles',
            'operation_effect_reviews',
            'operation_execution_evidence',
            'operation_execution_tasks',
            'operation_execution_intents',
            'hotel_operating_memories',
            'platform_data_sources',
            'online_daily_data',
            'room_types',
            'hotels',
        ] as $table) {
            Db::execute('DROP TABLE IF EXISTS ' . $table);
        }
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT, city TEXT, address TEXT, status INTEGER NOT NULL, update_time TEXT)');
        Db::execute("INSERT INTO hotels (id,tenant_id,name,status) VALUES (20,10,'来源店',1),(21,10,'目标店',1),(22,10,'可比店',1),(30,11,'其他租户',1)");
        Db::execute(
            'CREATE TABLE hotel_operating_profiles ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, version_no INTEGER, previous_version_id INTEGER, '
            . 'profile_json TEXT, quality_status TEXT, effective_date TEXT, evidence_valid_until TEXT, evidence_refs_json TEXT, '
            . 'source_method TEXT, content_digest TEXT, is_current INTEGER, created_by INTEGER, created_at TEXT, updated_at TEXT, deleted_at TEXT, '
            . 'UNIQUE(tenant_id,hotel_id,version_no))'
        );
        Db::execute(
            'CREATE TABLE hotel_operating_sop_versions ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, sop_key TEXT, version_no INTEGER, '
            . 'previous_version_id INTEGER, title TEXT, objective TEXT, steps_json TEXT, stop_conditions_json TEXT, scope_json TEXT, '
            . 'source_memory_ids_json TEXT, evidence_refs_json TEXT, validation_status TEXT, validation_note TEXT, content_digest TEXT, '
            . 'lifecycle_status TEXT, created_by INTEGER, validated_by INTEGER, validated_at TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT)'
        );
        Db::execute(
            'CREATE TABLE hotel_operating_sop_replications ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, source_sop_version_id INTEGER, source_hotel_id INTEGER, '
            . 'target_hotel_id INTEGER, status TEXT, target_validation_status TEXT, draft_json TEXT, target_fact_refs_json TEXT, '
            . 'data_gaps_json TEXT, content_digest TEXT, created_by INTEGER, created_at TEXT, updated_at TEXT, deleted_at TEXT, '
            . 'UNIQUE(tenant_id,source_sop_version_id,target_hotel_id))'
        );
        Db::execute(
            'CREATE TABLE hotel_operating_sop_replication_reviews ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, replication_id INTEGER, review_no INTEGER, '
            . 'source_sop_version_id INTEGER, source_hotel_id INTEGER, target_hotel_id INTEGER, outcome TEXT, review_json TEXT, '
            . 'content_digest TEXT, created_by INTEGER, created_at TEXT, deleted_at TEXT, UNIQUE(tenant_id,replication_id,review_no))'
        );
        Db::execute(
            'CREATE TABLE operation_effect_reviews ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, '
            . 'intent_id INTEGER NOT NULL, task_id INTEGER NOT NULL, platform TEXT NOT NULL, '
            . 'baseline_business_date TEXT NOT NULL, review_business_date TEXT NOT NULL, metric_key TEXT NOT NULL, '
            . 'metric_definition_json TEXT NOT NULL, metric_definition_digest TEXT NOT NULL, approval_target_digest TEXT NOT NULL, '
            . 'before_value REAL NOT NULL, after_value REAL NOT NULL, expected_direction TEXT NOT NULL, '
            . 'target_type TEXT NOT NULL, target_value REAL, expected_delta REAL, expected_delta_status TEXT NOT NULL, '
            . 'target_confirmed_by INTEGER NOT NULL, target_confirmed_at TEXT NOT NULL, '
            . 'baseline_refs_json TEXT NOT NULL, followup_refs_json TEXT NOT NULL, source_readback_evidence_id INTEGER NOT NULL, '
            . 'outcome_status TEXT NOT NULL, outcome_json TEXT NOT NULL, result_status TEXT NOT NULL, result_summary TEXT NOT NULL, '
            . 'causality_claimed INTEGER NOT NULL, reviewed_by INTEGER NOT NULL, reviewed_at TEXT NOT NULL, '
            . 'content_digest TEXT NOT NULL, created_at TEXT NOT NULL, UNIQUE(tenant_id,hotel_id,task_id,content_digest))'
        );
        Db::execute(
            'CREATE TABLE operation_execution_intents ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, idempotency_key TEXT UNIQUE, '
            . 'source_module TEXT, source_record_id INTEGER, hotel_id INTEGER, platform TEXT, object_type TEXT, action_type TEXT, '
            . 'date_start TEXT, date_end TEXT, current_value_json TEXT, target_value_json TEXT, evidence_json TEXT, '
            . 'expected_metric TEXT, expected_delta REAL, risk_level TEXT, status TEXT, blocked_reason TEXT, review_remark TEXT, '
            . 'created_by INTEGER, approved_by INTEGER, approved_at TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT)'
        );
        Db::execute(
            'CREATE TABLE operation_execution_tasks ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, intent_id INTEGER, hotel_id INTEGER, '
            . 'execution_mode TEXT, operator_id INTEGER, target_value_json TEXT, current_value_json TEXT, blocked_reason TEXT, '
            . 'action_track_id INTEGER, result_status TEXT, result_summary TEXT, status TEXT, executed_at TEXT, '
            . 'created_at TEXT, updated_at TEXT, deleted_at TEXT)'
        );
        Db::execute(
            'CREATE TABLE operation_execution_evidence ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, task_id INTEGER, evidence_type TEXT, '
            . 'before_json TEXT, after_json TEXT, attachment_path TEXT, platform_response_json TEXT, remark TEXT, '
            . 'created_by INTEGER, created_at TEXT, updated_at TEXT, deleted_at TEXT)'
        );
        Db::execute(
            'CREATE TABLE hotel_operating_memories ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, memory_layer TEXT, platform TEXT, '
            . 'source_scope TEXT, source_record_id INTEGER, business_date TEXT, context_json TEXT, quality_status TEXT, '
            . 'usage_level TEXT, lifecycle_status TEXT, deleted_at TEXT)'
        );
        Db::execute(
            'CREATE TABLE hotel_operating_cycles ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, business_date TEXT, '
            . 'last_completed_stage TEXT, last_completed_stage_index INTEGER, next_required_stage TEXT, cycle_status TEXT, '
            . 'outcome_status TEXT, experience_status TEXT, state_version INTEGER, last_event_id INTEGER, '
            . 'last_event_digest TEXT, projection_digest TEXT)'
        );
        Db::execute(
            'CREATE TABLE hotel_operating_cycle_events ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, cycle_id INTEGER, tenant_id INTEGER, hotel_id INTEGER, '
            . 'stage_key TEXT, stage_status TEXT, event_digest TEXT)'
        );
        Db::execute(
            'CREATE TABLE hotel_operating_cycle_evidence ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, cycle_id INTEGER, event_id INTEGER, tenant_id INTEGER, hotel_id INTEGER, '
            . 'stage_key TEXT, evidence_role TEXT, source_table TEXT, source_row_id INTEGER, source_row_ids_json TEXT, '
            . 'verification_status TEXT, readback_verified INTEGER)'
        );
        Db::execute(
            'CREATE TABLE platform_data_sources ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, system_hotel_id INTEGER, platform TEXT, '
            . 'status TEXT, enabled INTEGER DEFAULT 1)'
        );
        Db::execute(
            'CREATE TABLE online_daily_data ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, system_hotel_id INTEGER, hotel_id TEXT, hotel_name TEXT, '
            . 'data_date TEXT, platform TEXT, source TEXT, data_type TEXT, list_exposure INTEGER, ingestion_method TEXT, '
            . 'source_trace_id TEXT, snapshot_time TEXT, readback_verified INTEGER, readback_verified_at TEXT, '
            . 'validation_status TEXT, raw_data TEXT, create_time TEXT, update_time TEXT)'
        );
        Db::execute(
            'CREATE TABLE room_types ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, name TEXT, room_count INTEGER, '
            . 'base_price REAL, min_price REAL, max_price REAL, is_enabled INTEGER, update_time TEXT)'
        );
    }

    public function testExpectedReplicationNotFoundBecomesPartialWithoutHidingItsCount(): void
    {
        $replicationId = $this->insertReplicationReadbackFixture();
        $network = new OperatingNetworkService(
            static function (int $id, int $tenantId, array $hotelIds) use ($replicationId): array {
                self::assertSame($replicationId, $id);
                self::assertSame(10, $tenantId);
                self::assertSame([20, 21], $hotelIds);
                throw new RuntimeException('operating SOP replication not found');
            }
        );

        $overview = $network->overview(10, 21, [20, 21]);

        self::assertSame('partial', $overview['data_status']);
        self::assertSame('partial', $overview['replications']['data_status']);
        self::assertSame(1, $overview['replications']['matched_total']);
        self::assertSame(1, $overview['replications']['unavailable_count']);
        self::assertSame('replication_exact_readback_failed', $overview['replications']['unavailable_rows'][0]['reason_code']);
    }

    public function testUnexpectedReplicationReadFailureIsRethrownInsteadOfReportedAsPartial(): void
    {
        $this->insertReplicationReadbackFixture();
        $network = new OperatingNetworkService(
            static function (): array {
                throw new RuntimeException('database query failed');
            }
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('database query failed');

        $network->overview(10, 21, [20, 21]);
    }

    private function insertReplicationReadbackFixture(): int
    {
        return (int)Db::name(OperatingSopService::REPLICATION_TABLE)->insertGetId([
            'tenant_id' => 10,
            'source_sop_version_id' => 9001,
            'source_hotel_id' => 20,
            'target_hotel_id' => 21,
            'status' => 'draft_pending_target_validation',
            'target_validation_status' => 'pending',
            'draft_json' => '{}',
            'target_fact_refs_json' => '[]',
            'data_gaps_json' => '[]',
            'content_digest' => str_repeat('a', 64),
            'created_by' => 7,
            'created_at' => '2026-08-30 09:00:00',
            'updated_at' => '2026-08-30 09:00:00',
            'deleted_at' => null,
        ]);
    }

    public function testSixMatchesTwoMissingAndOneFailedCounterexampleRemainDraftOnly(): void
    {
        $network = new OperatingNetworkService();
        $sourceDimensions = $this->sourceDimensions();
        $network->saveProfile(10, 20, $this->profileInput($sourceDimensions, 'verified'), 7);
        $target = $network->saveProfile(10, 21, $this->profileInput($sourceDimensions, 'verified'), 7);
        self::assertSame('readback_verified', $target['persistence_status']);
        self::assertSame('verified', $target['profile']['quality_status']);
        self::assertSame('current', $target['profile']['freshness_status']);

        $otherDimensions = $sourceDimensions;
        $otherDimensions['price_band'] = ['高端', '800元以上'];
        $network->saveProfile(10, 22, $this->profileInput($otherDimensions, 'verified'), 7);

        $versionId = $this->insertVerifiedSop($sourceDimensions);
        $this->insertTrustedCollection(21);
        $this->insertTrustedCollection(22);
        $this->insertCompletedOperatingCycle(20, null, '2026-08-01');
        $this->insertCompletedOperatingCycle(21, null, '2026-08-01');
        $this->insertCompletedOperatingCycle(22, null, '2026-08-01');

        $sops = new OperatingSopService();
        $first = $sops->replicate($versionId, 10, [20, 21, 22], 21, 8, [
            'target_date_start' => '2026-08-12',
            'target_date_end' => '2026-08-12',
        ]);
        self::assertSame('draft_pending_target_validation', $first['replication']['status']);
        self::assertSame('validation_draft_only', $first['replication']['draft']['applicability_assessment']['recommendation']);
        self::assertSame(8, $first['replication']['draft']['applicability_assessment']['matched_count']);
        self::assertSame(0, $first['replication']['draft']['applicability_assessment']['missing_count']);
        self::assertSame(0, $first['replication']['draft']['applicability_assessment']['counterexample_count']);
        self::assertFalse($first['write_boundaries']['automatic_execution']);
        self::assertFalse($first['write_boundaries']['ota_write']);

        $targetOverview = $network->overview(10, 21, [20, 21, 22]);
        self::assertSame('ok', $targetOverview['replications']['data_status']);
        self::assertSame(1, $targetOverview['replications']['matched_total']);
        self::assertSame(1, $targetOverview['replications']['returned_count']);
        self::assertFalse($targetOverview['replications']['truncated']);
        self::assertSame((int)$first['replication']['id'], (int)$targetOverview['replications']['list'][0]['id']);
        self::assertSame(21, (int)$targetOverview['replications']['list'][0]['target_hotel_id']);
        self::assertSame(0, $network->overview(10, 20, [20, 21, 22])['replications']['matched_total']);

        $restrictedOverview = $network->overview(10, 21, [21, 22]);
        self::assertSame('partial', $restrictedOverview['data_status']);
        self::assertSame('partial', $restrictedOverview['replications']['data_status']);
        self::assertSame(1, $restrictedOverview['replications']['matched_total']);
        self::assertSame(0, $restrictedOverview['replications']['accessible_total']);
        self::assertSame(1, $restrictedOverview['replications']['unavailable_count']);
        self::assertSame([], $restrictedOverview['replications']['list']);
        self::assertContains('operating_sop_replication_readback_partial', array_column($restrictedOverview['data_gaps'], 'code'));

        $failedLineage = $this->createFormalReplicationEffectReview(
            $network,
            (int)$first['replication']['id'],
            21,
            'failed',
            1
        );
        $failedEffectReviewId = $failedLineage['effect_review_id'];
        $this->insertCompletedOperatingCycle(21, $failedEffectReviewId, $failedLineage['review_business_date']);
        $review = $network->recordReplicationReview(
            (int)$first['replication']['id'],
            10,
            [20, 21],
            [
                'outcome' => 'failed',
                'note' => '目标店周末休闲客群不足，动作未达到成功条件。',
                'failure_conditions' => ['需求结构不匹配'],
                'evidence_refs' => ['operation_effect_reviews#' . $failedEffectReviewId],
                'reviewed_business_date' => $failedLineage['review_business_date'],
            ],
            9
        );
        self::assertSame('readback_verified', $review['persistence_status']);
        self::assertSame(1, $review['review']['review_no']);

        $successLineage = $this->createFormalReplicationEffectReview(
            $network,
            (int)$first['replication']['id'],
            21,
            'success',
            2
        );
        $successEffectReviewId = $successLineage['effect_review_id'];
        $this->insertCompletedOperatingCycle(21, $successEffectReviewId, $successLineage['review_business_date']);
        $success = $network->recordReplicationReview(
            (int)$first['replication']['id'],
            10,
            [20, 21, 22],
            [
                'outcome' => 'success',
                'note' => '第二轮在同一目标画像下达到冻结的成功条件。',
                'observed_conditions' => ['详情页访问率提升且订单转化未下降'],
                'evidence_refs' => ['operation_effect_reviews#' . $successEffectReviewId],
                'reviewed_business_date' => $successLineage['review_business_date'],
            ],
            9
        );
        self::assertSame(2, $success['review']['review_no']);

        $other = $sops->replicate($versionId, 10, [20, 21, 22], 22, 8, [
            'target_date_start' => '2026-08-12',
            'target_date_end' => '2026-08-12',
        ]);
        $otherLineage = $this->createFormalReplicationEffectReview(
            $network,
            (int)$other['replication']['id'],
            22,
            'failed',
            1
        );
        $otherEffectReviewId = $otherLineage['effect_review_id'];
        $this->insertCompletedOperatingCycle(22, $otherEffectReviewId, $otherLineage['review_business_date']);
        $network->recordReplicationReview(
            (int)$other['replication']['id'],
            10,
            [20, 21, 22],
            [
                'outcome' => 'failed',
                'note' => '价格带冲突酒店的失败只属于另一种画像。',
                'failure_conditions' => ['价格带不同'],
                'evidence_refs' => ['operation_effect_reviews#' . $otherEffectReviewId],
                'reviewed_business_date' => $otherLineage['review_business_date'],
            ],
            9
        );

        $targetDimensions = $sourceDimensions;
        $targetDimensions['data_quality'] = [];
        $targetDimensions['pre_action_state'] = [];
        $partialTarget = $network->saveProfile(10, 21, $this->profileInput($targetDimensions, 'partial'), 7);
        self::assertSame('readback_verified', $partialTarget['persistence_status']);
        self::assertSame('partial', $partialTarget['profile']['quality_status']);
        self::assertSame('current', $partialTarget['profile']['freshness_status']);

        $reassessed = $sops->replicate($versionId, 10, [20, 21, 22], 21, 8, [
            'target_date_start' => '2026-08-12',
            'target_date_end' => '2026-08-12',
        ]);
        $assessment = $reassessed['replication']['draft']['applicability_assessment'];
        self::assertSame(6, $assessment['matched_count']);
        self::assertSame(2, $assessment['missing_count']);
        self::assertSame(1, $assessment['counterexample_count']);
        self::assertSame(1, $assessment['success_count']);
        self::assertSame(1, $assessment['replication_evidence']['other_profile_review_count']);
        self::assertSame('validation_draft_only', $assessment['recommendation']);
        self::assertStringContainsString('满足6项、缺少2项', $assessment['summary']);
        self::assertStringContainsString('成功1条', $assessment['summary']);
        self::assertStringContainsString('反例1条', $assessment['summary']);
        self::assertFalse($assessment['boundaries']['automatic_execution']);
        self::assertFalse($assessment['boundaries']['ota_write']);

        $assets = $network->overview(10, 21, [20, 21, 22])['network_asset_summary'];
        self::assertSame(3, $assets['hotel_count']);
        self::assertSame(3, $assets['current_profile_count']);
        self::assertSame(2, $assets['network_ready_profile_count']);
        self::assertSame(3, $assets['authoritative_operating_loop_hotel_count']);
        self::assertSame(1, $assets['eligible_sop_count']);
        self::assertSame(2, $assets['replication_draft_count']);
        self::assertSame(1, $assets['validation_ready_draft_count']);
        self::assertSame(1, $assets['blocked_draft_count']);
        self::assertSame(3, $assets['verified_replication_review_count']);
        self::assertSame(1, $assets['success_count']);
        self::assertSame(2, $assets['failed_count']);
        self::assertSame(2, $assets['reviewed_target_hotel_count']);
        self::assertSame('repeated', $assets['field_evidence_status']);
        self::assertSame('learning_active', $assets['network_learning_status']);
        self::assertFalse($assets['field_validated']);
        self::assertFalse($assets['automatic_execution']);
    }

    public function testOnboardingReadbackKeepsOrderedStagesAndOnlyIdentifiesComparableCandidates(): void
    {
        $network = new OperatingNetworkService();
        $dimensions = $this->sourceDimensions();
        $network->saveProfile(10, 20, $this->profileInput($dimensions, 'verified'), 7);
        $network->saveProfile(10, 22, $this->profileInput($dimensions, 'verified'), 7);
        Db::name('platform_data_sources')->insert([
            'tenant_id' => 10,
            'system_hotel_id' => 20,
            'platform' => 'ctrip',
            'status' => 'active',
        ]);
        Db::name('online_daily_data')->insert([
            'tenant_id' => 10,
            'system_hotel_id' => 20,
            'data_date' => '2026-08-12',
            'platform' => 'ctrip',
            'source' => 'ctrip',
            'data_type' => 'traffic',
            'readback_verified' => 1,
            'validation_status' => 'normal',
        ]);
        $this->insertCompletedOperatingCycle(20, null, '2026-08-01');
        $this->insertCompletedOperatingCycle(22, null, '2026-08-01');

        $overview = $network->overview(10, 20, [20, 21, 22]);
        self::assertSame('ok', $overview['data_status']);
        self::assertSame([
            'identity_confirmation',
            'data_source_binding',
            'room_rate_mapping',
            'metric_definition',
            'first_trusted_collection',
            'first_operating_loop',
            'comparable_hotel_identification',
        ], array_column($overview['onboarding']['stages'], 'key'));
        self::assertSame('review_required', $overview['onboarding']['stages'][6]['status']);
        self::assertTrue($overview['onboarding']['ready_for_comparable_review']);
        self::assertSame(22, $overview['comparable_hotels'][0]['hotel_id']);
        self::assertSame(8, $overview['comparable_hotels'][0]['matched_count']);
        self::assertSame('candidate_review_required', $overview['comparable_hotels'][0]['status']);
    }

    public function testOnboardingRecognizesCanonicalEnabledSourceStatusesOnly(): void
    {
        Db::name('platform_data_sources')->insertAll([
            ['tenant_id' => 10, 'system_hotel_id' => 20, 'platform' => 'ctrip', 'status' => 'ready', 'enabled' => 1],
            ['tenant_id' => 10, 'system_hotel_id' => 21, 'platform' => 'ctrip', 'status' => 'success', 'enabled' => 1],
            ['tenant_id' => 10, 'system_hotel_id' => 22, 'platform' => 'ctrip', 'status' => 'partial_success', 'enabled' => 1],
            ['tenant_id' => 11, 'system_hotel_id' => 30, 'platform' => 'ctrip', 'status' => 'ready', 'enabled' => 0],
            ['tenant_id' => 10, 'system_hotel_id' => 21, 'platform' => 'meituan', 'status' => 'failed', 'enabled' => 1],
            ['tenant_id' => 10, 'system_hotel_id' => 22, 'platform' => 'meituan', 'status' => 'waiting_config', 'enabled' => 1],
        ]);

        $network = new OperatingNetworkService();
        foreach ([20, 21, 22] as $hotelId) {
            $overview = $network->overview(10, $hotelId, [20, 21, 22]);
            self::assertSame('complete', $overview['onboarding']['stages'][1]['status']);
        }

        $disabled = $network->overview(11, 30, [30]);
        self::assertSame('missing', $disabled['onboarding']['stages'][1]['status']);

        Db::name('platform_data_sources')->where('system_hotel_id', 20)->update([
            'status' => 'failed',
        ]);
        $failed = $network->overview(10, 20, [20, 21, 22]);
        self::assertSame('missing', $failed['onboarding']['stages'][1]['status']);
    }

    public function testProfileDraftPreviewIsZeroWriteUnverifiedAndEvidenceScoped(): void
    {
        Db::name('hotels')->where('id', 20)->update([
            'city' => '杭州',
            'address' => '西湖区测试路',
            'update_time' => '2026-08-10 12:00:00',
        ]);
        Db::name('room_types')->insertAll([
            [
                'tenant_id' => 10,
                'hotel_id' => 20,
                'name' => '大床房',
                'room_count' => 60,
                'base_price' => 360,
                'min_price' => 300,
                'max_price' => 420,
                'is_enabled' => 1,
                'update_time' => '2026-08-11 09:00:00',
            ],
            [
                'tenant_id' => 10,
                'hotel_id' => 20,
                'name' => '双床房',
                'room_count' => 20,
                'base_price' => 400,
                'min_price' => 340,
                'max_price' => 480,
                'is_enabled' => 1,
                'update_time' => '2026-08-11 09:00:00',
            ],
            [
                'tenant_id' => 10,
                'hotel_id' => 20,
                'name' => '停用房型',
                'room_count' => 99,
                'base_price' => 999,
                'min_price' => 999,
                'max_price' => 999,
                'is_enabled' => 0,
                'update_time' => '2026-08-11 09:00:00',
            ],
            [
                'tenant_id' => 11,
                'hotel_id' => 30,
                'name' => '其他租户房型',
                'room_count' => 88,
                'base_price' => 888,
                'min_price' => 800,
                'max_price' => 900,
                'is_enabled' => 1,
                'update_time' => '2026-08-12 09:00:00',
            ],
        ]);
        Db::name('platform_data_sources')->insertAll([
            ['tenant_id' => 10, 'system_hotel_id' => 20, 'platform' => 'ctrip', 'status' => 'ready', 'enabled' => 1],
            ['tenant_id' => 10, 'system_hotel_id' => 20, 'platform' => 'meituan', 'status' => 'failed', 'enabled' => 1],
            ['tenant_id' => 10, 'system_hotel_id' => 21, 'platform' => 'meituan', 'status' => 'ready', 'enabled' => 1],
            ['tenant_id' => 11, 'system_hotel_id' => 30, 'platform' => 'meituan', 'status' => 'ready', 'enabled' => 1],
        ]);
        $verifiedTraceId = 'ctrip:' . str_repeat('a', 64);
        $verifiedSourceUrlHash = str_repeat('b', 64);
        $verifiedRaw = json_encode([
            'source_url_hash' => $verifiedSourceUrlHash,
            'field_facts' => [[
                'metric_key' => 'list_exposure',
                'source_path' => 'data.list_exposure',
                'storage_field' => 'list_exposure',
                'stored_value_present' => true,
                'status' => 'captured',
                'capture_evidence' => [
                    'source_trace_id' => $verifiedTraceId,
                    'source_url_hash' => $verifiedSourceUrlHash,
                ],
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $onlineDailyRows = [
            [
                'tenant_id' => 10,
                'system_hotel_id' => 20,
                'hotel_id' => 'ctrip-20',
                'hotel_name' => '来源店',
                'data_date' => '2026-08-10',
                'platform' => 'ctrip',
                'source' => 'ctrip',
                'data_type' => 'traffic',
                'list_exposure' => 123,
                'ingestion_method' => 'browser_profile',
                'source_trace_id' => $verifiedTraceId,
                'snapshot_time' => '2026-08-10 09:30:00',
                'readback_verified' => 1,
                'readback_verified_at' => '2026-08-10 09:31:00',
                'validation_status' => 'normal',
                'raw_data' => $verifiedRaw,
                'create_time' => '2026-08-10 09:30:30',
                'update_time' => '2026-08-10 09:31:00',
            ],
            [
                'tenant_id' => 10,
                'system_hotel_id' => 20,
                'hotel_id' => 'ctrip-20',
                'hotel_name' => '来源店',
                'data_date' => '2026-08-11',
                'platform' => 'ctrip',
                'source' => 'ctrip',
                'data_type' => 'traffic',
                'list_exposure' => 456,
                'ingestion_method' => 'browser_profile',
                'source_trace_id' => 'ctrip:partial',
                'snapshot_time' => '2026-08-11 09:30:00',
                'readback_verified' => 1,
                'readback_verified_at' => '2026-08-11 09:31:00',
                'validation_status' => 'verified',
                'raw_data' => '{}',
                'create_time' => '2026-08-11 09:30:30',
                'update_time' => '2026-08-11 09:31:00',
            ],
            ['tenant_id' => 10, 'system_hotel_id' => 20, 'data_date' => '2026-08-12', 'platform' => 'meituan', 'source' => 'meituan', 'data_type' => 'traffic', 'readback_verified' => 0, 'validation_status' => 'normal'],
            ['tenant_id' => 10, 'system_hotel_id' => 21, 'data_date' => '2026-08-12', 'platform' => 'meituan', 'source' => 'meituan', 'data_type' => 'traffic', 'readback_verified' => 1, 'validation_status' => 'normal'],
            ['tenant_id' => 11, 'system_hotel_id' => 30, 'data_date' => '2026-08-12', 'platform' => 'meituan', 'source' => 'meituan', 'data_type' => 'traffic', 'readback_verified' => 1, 'validation_status' => 'normal'],
        ];
        foreach ($onlineDailyRows as $onlineDailyRow) {
            Db::name('online_daily_data')->insert($onlineDailyRow);
        }

        $before = (int)Db::name('hotel_operating_profiles')->count();
        $preview = (new OperatingNetworkService())->previewProfileDraft(10, 20, [20, 21]);
        $after = (int)Db::name('hotel_operating_profiles')->count();

        self::assertSame($before, $after);
        self::assertTrue($preview['preview_only']);
        self::assertSame('not_persisted', $preview['persistence_status']);
        self::assertFalse($preview['automatic_verification']);
        self::assertSame('unverified', $preview['draft']['quality_status']);
        self::assertNull($preview['draft']['evidence_valid_until']);
        self::assertSame('2026-08-10', $preview['draft']['effective_date']);
        self::assertSame('2026-08-11', $preview['summary']['metadata_updated_date']);
        self::assertSame(['物理房量：80间'], $preview['draft']['profile']['dimensions']['hotel_type_and_scale']);
        self::assertSame(['城市：杭州'], $preview['draft']['profile']['dimensions']['city_district_demand']);
        self::assertSame(['配置价带：300-480元'], $preview['draft']['profile']['dimensions']['price_band']);
        self::assertSame(['房型：大床房（60间）', '房型：双床房（20间）'], $preview['draft']['profile']['dimensions']['room_type_structure']);
        self::assertContains('已绑定渠道：携程', $preview['draft']['profile']['dimensions']['platform_channel_structure']);
        self::assertContains('有完整真值门证据的渠道：携程', $preview['draft']['profile']['dimensions']['platform_channel_structure']);
        self::assertNotContains('已绑定渠道：美团', $preview['draft']['profile']['dimensions']['platform_channel_structure']);
        self::assertSame([], $preview['draft']['profile']['dimensions']['seasonality']);
        self::assertSame([], $preview['draft']['profile']['dimensions']['pre_action_state']);
        self::assertSame(1, $preview['summary']['verified_fact_count']);
        self::assertSame(['携程'], $preview['summary']['verified_platforms']);
        self::assertSame('2026-08-10', $preview['summary']['verified_business_date_start']);
        self::assertSame('2026-08-10', $preview['summary']['verified_business_date_end']);
        self::assertSame(2, $preview['summary']['readback_candidate_count']);
        self::assertSame(2, $preview['summary']['evaluated_readback_candidate_count']);
        self::assertSame([
            'verified' => 1,
            'partial' => 1,
            'unverified' => 0,
            'collection_failed' => 0,
        ], $preview['summary']['readback_candidate_status_counts']);
        self::assertFalse($preview['summary']['readback_candidate_evaluation_truncated']);
        self::assertSame('missing', $preview['draft']['profile']['onboarding_confirmations']['room_rate_mapping']['status']);
        self::assertSame('missing', $preview['draft']['profile']['onboarding_confirmations']['metric_definition']['status']);
        self::assertFalse($preview['boundaries']['automatic_execution']);
        self::assertFalse($preview['boundaries']['ota_write']);
        self::assertFalse($preview['boundaries']['external_message']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $preview['preview_digest']);

        $serialized = json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('其他租户房型', $serialized);
        self::assertStringNotContainsString('已绑定渠道：美团', $serialized);
        self::assertStringNotContainsString($verifiedTraceId, $serialized);
        self::assertStringNotContainsString($verifiedSourceUrlHash, $serialized);
        self::assertStringNotContainsString('field_facts', $serialized);
        self::assertStringNotContainsString('2026-08-12', json_encode($preview['draft']['profile']['dimensions'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function testProfileDraftPreviewPreservesUnknownsWhenEvidenceIsMissing(): void
    {
        $preview = (new OperatingNetworkService())->previewProfileDraft(10, 20, [20, 21]);

        self::assertSame('unavailable', $preview['preview_status']);
        self::assertSame(0, $preview['summary']['filled_dimension_count']);
        self::assertSame(8, $preview['summary']['missing_dimension_count']);
        self::assertSame('', $preview['draft']['effective_date']);
        self::assertSame([], array_filter(
            $preview['draft']['profile']['dimensions'],
            static fn(array $values): bool => $values !== []
        ));
        self::assertCount(8, $preview['data_gaps']);
        self::assertSame(0, Db::name('hotel_operating_profiles')->count());
    }

    public function testProfileDraftPreviewRejectsAnInaccessibleOrCrossTenantHotel(): void
    {
        $network = new OperatingNetworkService();
        $this->expectException(\RuntimeException::class);
        $network->previewProfileDraft(10, 30, [20, 30]);
    }

    public function testLegacyMemoryFlagCannotReplaceAuthoritativeFirstOperatingLoop(): void
    {
        $network = new OperatingNetworkService();
        $dimensions = $this->sourceDimensions();
        $network->saveProfile(10, 20, $this->profileInput($dimensions, 'verified'), 7);
        $network->saveProfile(10, 22, $this->profileInput($dimensions, 'verified'), 7);
        Db::name('platform_data_sources')->insert([
            'tenant_id' => 10,
            'system_hotel_id' => 20,
            'platform' => 'ctrip',
            'status' => 'active',
        ]);
        $this->insertTrustedCollection(20);
        Db::name('hotel_operating_memories')->insert([
            'tenant_id' => 10,
            'hotel_id' => 20,
            'memory_layer' => 'execution_review',
            'platform' => 'ctrip',
            'source_scope' => 'ota_channel',
            'source_record_id' => 88,
            'business_date' => '2026-08-12',
            'context_json' => json_encode(['outcome_verified' => true], JSON_THROW_ON_ERROR),
            'quality_status' => 'verified',
            'usage_level' => 'decision_support',
            'lifecycle_status' => 'active',
            'deleted_at' => null,
        ]);

        $overview = $network->overview(10, 20, [20, 22]);
        self::assertSame('missing', $overview['onboarding']['stages'][5]['status']);
        self::assertSame('blocked', $overview['onboarding']['stages'][6]['status']);
        self::assertFalse($overview['onboarding']['ready_for_comparable_review']);
        self::assertSame([], $overview['comparable_hotels']);
    }

    public function testPartialDimensionOverlapIsConflictAndExposesUnmetSourceValues(): void
    {
        $network = new OperatingNetworkService();
        $sourceDimensions = $this->sourceDimensions();
        $targetDimensions = $sourceDimensions;
        $targetDimensions['hotel_type_and_scale'] = ['精品酒店', '200间'];
        $network->saveProfile(10, 20, $this->profileInput($sourceDimensions, 'verified'), 7);
        $network->saveProfile(10, 21, $this->profileInput($targetDimensions, 'verified'), 7);
        $versionId = $this->insertVerifiedSop($sourceDimensions);
        $this->insertTrustedCollection(21);
        $this->insertCompletedOperatingCycle(20, null, '2026-08-01');
        $this->insertCompletedOperatingCycle(21, null, '2026-08-01');

        $replication = (new OperatingSopService())->replicate($versionId, 10, [20, 21], 21, 8, [
            'target_date_start' => '2026-08-12',
            'target_date_end' => '2026-08-12',
        ]);
        $assessment = $replication['replication']['draft']['applicability_assessment'];
        self::assertSame(7, $assessment['matched_count']);
        self::assertSame(1, $assessment['conflict_count']);
        $dimension = array_values(array_filter(
            $assessment['dimension_results'],
            static fn(array $item): bool => $item['dimension'] === 'hotel_type_and_scale'
        ))[0];
        self::assertSame('conflict', $dimension['status']);
        self::assertSame(['精品酒店'], $dimension['matched_values']);
        self::assertSame(['80间'], $dimension['unmet_source_values']);
        self::assertContains(
            'target_applicability_hotel_type_and_scale_conflict',
            array_column($assessment['data_gaps'], 'code')
        );
    }

    public function testComparableCandidatesExcludeExpiredAndUnverifiedProfiles(): void
    {
        $network = new OperatingNetworkService();
        $dimensions = $this->sourceDimensions();
        $network->saveProfile(10, 20, $this->profileInput($dimensions, 'verified'), 7);
        $this->insertCompletedOperatingCycle(20, null, '2026-08-01');

        $expired = $this->profileInput($dimensions, 'verified');
        $expired['effective_date'] = '2000-01-01';
        $expired['evidence_valid_until'] = '2000-01-02';
        $network->saveProfile(10, 21, $expired, 7);
        $network->saveProfile(10, 22, $this->profileInput($dimensions, 'unverified'), 7);

        $overview = $network->overview(10, 20, [20, 21, 22]);
        self::assertSame([], $overview['comparable_hotels']);
    }

    public function testReplicationReviewRejectsEvidenceFromAnotherTargetHotel(): void
    {
        $network = new OperatingNetworkService();
        $dimensions = $this->sourceDimensions();
        $network->saveProfile(10, 20, $this->profileInput($dimensions, 'verified'), 7);
        $network->saveProfile(10, 21, $this->profileInput($dimensions, 'verified'), 7);
        $versionId = $this->insertVerifiedSop($dimensions);
        $this->insertTrustedCollection(21);
        $this->insertCompletedOperatingCycle(20, null, '2026-08-01');
        $this->insertCompletedOperatingCycle(21, null, '2026-08-01');
        $replication = (new OperatingSopService())->replicate($versionId, 10, [20, 21], 21, 8, [
            'target_date_start' => '2026-08-12',
            'target_date_end' => '2026-08-12',
        ]);
        $wrongHotelEvidenceId = $this->insertEffectReview(22, 'failed');

        try {
            $network->recordReplicationReview(
                (int)$replication['replication']['id'],
                10,
                [20, 21],
                [
                    'outcome' => 'failed',
                    'note' => '不能借用另一家酒店的效果复盘。',
                    'failure_conditions' => ['效果未达标'],
                    'evidence_refs' => ['operation_effect_reviews#' . $wrongHotelEvidenceId],
                    'reviewed_business_date' => '2026-08-12',
                ],
                9
            );
            self::fail('Expected target-hotel evidence validation to reject the review.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('目标酒店', $exception->getMessage());
        }
    }

    public function testReplicationReviewRejectsEffectReviewOutsideAuthoritativeOperatingLoop(): void
    {
        $network = new OperatingNetworkService();
        $dimensions = $this->sourceDimensions();
        $network->saveProfile(10, 20, $this->profileInput($dimensions, 'verified'), 7);
        $network->saveProfile(10, 21, $this->profileInput($dimensions, 'verified'), 7);
        $versionId = $this->insertVerifiedSop($dimensions);
        $this->insertTrustedCollection(21);
        $this->insertCompletedOperatingCycle(20, null, '2026-08-01');
        $this->insertCompletedOperatingCycle(21, null, '2026-08-01');
        $replication = (new OperatingSopService())->replicate($versionId, 10, [20, 21], 21, 8, [
            'target_date_start' => '2026-08-12',
            'target_date_end' => '2026-08-12',
        ]);
        $unlinkedLineage = $this->createFormalReplicationEffectReview(
            $network,
            (int)$replication['replication']['id'],
            21,
            'failed',
            1
        );
        $unlinkedEffectReviewId = $unlinkedLineage['effect_review_id'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('权威经营闭环');
        $network->recordReplicationReview(
            (int)$replication['replication']['id'],
            10,
            [20, 21],
            [
                'outcome' => 'failed',
                'note' => '同酒店但没有进入权威闭环的效果行不能用于网络学习。',
                'failure_conditions' => ['效果未达标'],
                'evidence_refs' => ['operation_effect_reviews#' . $unlinkedEffectReviewId],
                'reviewed_business_date' => $unlinkedLineage['review_business_date'],
            ],
            9
        );
    }

    public function testReplicationReviewRejectsEffectReviewFromUnrelatedExecutionLineage(): void
    {
        $network = new OperatingNetworkService();
        $dimensions = $this->sourceDimensions();
        $network->saveProfile(10, 20, $this->profileInput($dimensions, 'verified'), 7);
        $network->saveProfile(10, 21, $this->profileInput($dimensions, 'verified'), 7);
        $versionId = $this->insertVerifiedSop($dimensions);
        $this->insertTrustedCollection(21);
        $this->insertCompletedOperatingCycle(20, null, '2026-08-01');
        $this->insertCompletedOperatingCycle(21, null, '2026-08-01');
        $replication = (new OperatingSopService())->replicate($versionId, 10, [20, 21], 21, 8, [
            'target_date_start' => '2026-08-12',
            'target_date_end' => '2026-08-12',
        ]);

        $intentId = (int)Db::name('operation_execution_intents')->insertGetId([
            'tenant_id' => 10,
            'source_module' => 'manual',
            'source_record_id' => 0,
            'hotel_id' => 21,
            'platform' => 'ctrip',
            'object_type' => 'campaign',
            'action_type' => 'unrelated_action',
            'date_start' => '2026-08-11',
            'date_end' => '2026-08-11',
            'current_value_json' => '{}',
            'target_value_json' => '{}',
            'evidence_json' => '{}',
            'expected_metric' => 'conversion_rate',
            'expected_delta' => 1,
            'risk_level' => 'low',
            'status' => 'approved',
            'blocked_reason' => '',
            'created_by' => 8,
            'approved_by' => 9,
            'approved_at' => '2026-08-11 10:00:00',
            'created_at' => '2026-08-11 09:00:00',
            'updated_at' => '2026-08-11 10:00:00',
            'deleted_at' => null,
        ]);
        $taskId = (int)Db::name('operation_execution_tasks')->insertGetId([
            'tenant_id' => 10,
            'intent_id' => $intentId,
            'hotel_id' => 21,
            'execution_mode' => 'manual',
            'target_value_json' => '{}',
            'current_value_json' => '{}',
            'result_status' => 'failed',
            'result_summary' => 'Unrelated action failed.',
            'status' => 'executed',
            'executed_at' => '2026-08-11 12:00:00',
            'created_at' => '2026-08-11 10:00:00',
            'updated_at' => '2026-08-11 12:00:00',
            'deleted_at' => null,
        ]);
        $evidenceId = (int)Db::name('operation_execution_evidence')->insertGetId([
            'tenant_id' => 10,
            'task_id' => $taskId,
            'evidence_type' => 'api_response',
            'before_json' => '{}',
            'after_json' => '{}',
            'platform_response_json' => '{}',
            'remark' => 'Unrelated action receipt.',
            'created_by' => 9,
            'created_at' => '2026-08-11 12:00:00',
            'updated_at' => '2026-08-11 12:00:00',
            'deleted_at' => null,
        ]);
        $effectReviewId = $this->insertEffectReview(21, 'failed');
        Db::name('operation_effect_reviews')->where('id', $effectReviewId)->update([
            'intent_id' => $intentId,
            'task_id' => $taskId,
            'source_readback_evidence_id' => $evidenceId,
        ]);
        $this->insertCompletedOperatingCycle(21, $effectReviewId, '2026-08-12');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('复制草稿执行血缘');
        $network->recordReplicationReview(
            (int)$replication['replication']['id'],
            10,
            [20, 21],
            [
                'outcome' => 'failed',
                'note' => '同店同日但属于另一项动作，不能计入复制学习。',
                'failure_conditions' => ['效果未达标'],
                'evidence_refs' => ['operation_effect_reviews#' . $effectReviewId],
                'reviewed_business_date' => '2026-08-12',
            ],
            9
        );
    }

    public function testReadyReplicationCreatesPendingApprovalIntentWithImmutableLineage(): void
    {
        $network = new OperatingNetworkService();
        $dimensions = $this->sourceDimensions();
        $network->saveProfile(10, 20, $this->profileInput($dimensions, 'verified'), 7);
        $network->saveProfile(10, 21, $this->profileInput($dimensions, 'verified'), 7);
        $versionId = $this->insertVerifiedSop($dimensions);
        $this->insertTrustedCollection(21);
        $this->insertCompletedOperatingCycle(20, null, '2026-08-01');
        $this->insertCompletedOperatingCycle(21, null, '2026-08-01');
        $replication = (new OperatingSopService())->replicate($versionId, 10, [20, 21], 21, 8, [
            'target_date_start' => '2026-08-12',
            'target_date_end' => '2026-08-12',
        ])['replication'];
        self::assertSame('draft_pending_target_validation', $replication['status']);

        $created = $network->createReplicationExecutionIntent(
            (int)$replication['id'],
            10,
            [20, 21],
            [
                'platform' => 'ctrip',
                'object_type' => 'campaign',
                'action_type' => 'replace_hero_image',
                'date_start' => '2026-08-13',
                'date_end' => '2026-08-13',
                'current_value' => ['hero_image' => 'baseline', 'conversion_rate' => 10],
                'target_value' => ['hero_image' => 'candidate_b'],
                'expected_metric' => 'conversion_rate',
                'expected_delta' => 1.5,
                'risk_level' => 'low',
            ],
            9
        );

        self::assertSame('readback_verified', $created['persistence_status']);
        self::assertSame('pending_approval', $created['execution_intent']['status']);
        self::assertSame(OperatingNetworkService::EXECUTION_SOURCE_MODULE, $created['execution_intent']['source_module']);
        self::assertSame((int)$replication['id'], $created['execution_intent']['source_record_id']);
        self::assertSame(
            $replication['content_digest'],
            $created['execution_intent']['evidence']['operating_network_replication']['replication_content_digest']
        );
        self::assertFalse($created['write_boundaries']['automatic_execution']);
        self::assertFalse($created['write_boundaries']['ota_write']);

        $driftedIntent = $created['execution_intent'];
        $driftedIntent['action_type'] = 'tampered_action';
        try {
            $network->assertReplicationExecutionIntentCurrent($driftedIntent);
            self::fail('A modified execution contract must not remain approvable.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('execution_contract_action_type_mismatch', $exception->getMessage());
        }

        $approved = (new OperationManagementService())->approveExecutionIntent(
            (int)$created['execution_intent']['id'],
            true,
            'Human approved a bounded validation attempt.',
            9,
            [20, 21],
            [
                'expected_metric' => 'conversion_rate',
                'expected_direction' => 'increase',
                'target_type' => 'delta',
                'expected_delta' => 1.5,
                'review_business_date' => '2026-08-14',
            ]
        );
        self::assertSame('approved', $approved['status']);
        self::assertCount(1, $approved['tasks']);
        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/D',
            (string)$approved['evidence']['approval_target']['content_digest']
        );
        self::assertSame(
            (string)$approved['evidence']['approval_target']['content_digest'],
            (string)$approved['tasks'][0]['target_value']['approval_target_digest']
        );
    }

    public function testBlockedReplicationCannotCreateExecutionIntent(): void
    {
        $network = new OperatingNetworkService();
        $dimensions = $this->sourceDimensions();
        $targetDimensions = $dimensions;
        $targetDimensions['data_quality'] = [];
        $targetDimensions['pre_action_state'] = [];
        $network->saveProfile(10, 20, $this->profileInput($dimensions, 'verified'), 7);
        $network->saveProfile(10, 21, $this->profileInput($targetDimensions, 'partial'), 7);
        $versionId = $this->insertVerifiedSop($dimensions);
        $this->insertTrustedCollection(21);
        $this->insertCompletedOperatingCycle(20, null, '2026-08-01');
        $this->insertCompletedOperatingCycle(21, null, '2026-08-01');
        $replication = (new OperatingSopService())->replicate($versionId, 10, [20, 21], 21, 8, [
            'target_date_start' => '2026-08-12',
            'target_date_end' => '2026-08-12',
        ])['replication'];
        self::assertSame('blocked_applicability_evidence_incomplete', $replication['status']);
        self::assertSame(6, $replication['draft']['applicability_assessment']['matched_count']);
        self::assertSame(2, $replication['draft']['applicability_assessment']['missing_count']);

        $this->expectException(\InvalidArgumentException::class);
        $network->createReplicationExecutionIntent(
            (int)$replication['id'],
            10,
            [20, 21],
            [
                'platform' => 'ctrip',
                'object_type' => 'campaign',
                'action_type' => 'replace_hero_image',
                'date_start' => '2026-08-13',
                'date_end' => '2026-08-13',
                'current_value' => ['conversion_rate' => 10],
                'target_value' => ['hero_image' => 'candidate_b'],
                'expected_metric' => 'conversion_rate',
                'expected_delta' => 1.5,
                'risk_level' => 'low',
            ],
            9
        );
    }

    public function testStoppedReviewRequiresExecutionEvidenceFromAuthoritativeOperatingLoop(): void
    {
        $network = new OperatingNetworkService();
        $dimensions = $this->sourceDimensions();
        $network->saveProfile(10, 20, $this->profileInput($dimensions, 'verified'), 7);
        $network->saveProfile(10, 21, $this->profileInput($dimensions, 'verified'), 7);
        $versionId = $this->insertVerifiedSop($dimensions);
        $this->insertTrustedCollection(21);
        $this->insertCompletedOperatingCycle(20, null, '2026-08-01');
        $this->insertCompletedOperatingCycle(21, null, '2026-08-01');
        $replication = (new OperatingSopService())->replicate($versionId, 10, [20, 21], 21, 8, [
            'target_date_start' => '2026-08-12',
            'target_date_end' => '2026-08-12',
        ]);
        $stopLineage = $this->createReplicationStopEvidence(
            $network,
            (int)$replication['replication']['id'],
            21,
            1
        );
        $executionEvidenceId = $stopLineage['evidence_id'];

        try {
            $network->recordReplicationReview(
                (int)$replication['replication']['id'],
                10,
                [20, 21],
                [
                    'outcome' => 'stopped',
                    'note' => '触发冻结的停止条件，但该回执尚未进入权威闭环。',
                    'stop_triggered' => ['订单转化连续两天下降'],
                    'evidence_refs' => ['operation_execution_evidence#' . $executionEvidenceId],
                    'reviewed_business_date' => $stopLineage['review_business_date'],
                ],
                9
            );
            self::fail('Expected unlinked execution evidence to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('权威经营闭环', $exception->getMessage());
        }

        $cycleId = $this->insertCompletedOperatingCycle(21, null, '2026-08-04');
        $this->linkExecutionEvidenceToCycle($cycleId, $executionEvidenceId, 21);
        $saved = $network->recordReplicationReview(
            (int)$replication['replication']['id'],
            10,
            [20, 21],
            [
                'outcome' => 'stopped',
                'note' => '触发冻结的停止条件，执行回执已进入权威闭环。',
                'stop_triggered' => ['订单转化连续两天下降'],
                'evidence_refs' => ['operation_execution_evidence#' . $executionEvidenceId],
                'reviewed_business_date' => $stopLineage['review_business_date'],
            ],
            9
        );
        self::assertSame('verified', $saved['review']['review']['evidence_verification']['status']);
        self::assertSame(
            ['hotel_operating_cycles#' . $cycleId],
            $saved['review']['review']['evidence_verification']['verified_operating_cycle_refs']
        );
    }

    public function testVerifiedSopEligibilityRequiresFullReplicationContractAndCurrentEvidence(): void
    {
        $network = new OperatingNetworkService();
        $dimensions = $this->sourceDimensions();
        $network->saveProfile(10, 21, $this->profileInput($dimensions, 'verified'), 7);
        $this->insertCompletedOperatingCycle(20, null, '2026-08-01');
        $eligibleId = $this->insertVerifiedSop($dimensions);
        $incompleteId = $this->insertVerifiedSop($dimensions, ['success_conditions' => []]);

        $sops = [];
        foreach ($network->overview(10, 21, [20, 21])['verified_sops'] as $sop) {
            $sops[(int)$sop['id']] = $sop;
        }
        self::assertSame('eligible_for_validation_draft', $sops[$eligibleId]['replication_eligibility']);
        self::assertSame('contract_incomplete', $sops[$incompleteId]['replication_eligibility']);
        self::assertContains('success_conditions_missing', $sops[$incompleteId]['replication_gaps']);
    }

    /** @return array<string,list<string>> */
    private function sourceDimensions(): array
    {
        return [
            'hotel_type_and_scale' => ['精品酒店', '80间'],
            'city_district_demand' => ['杭州', '西湖商圈', '周末休闲'],
            'price_band' => ['中端', '300-500元'],
            'room_type_structure' => ['大床为主', '亲子房'],
            'platform_channel_structure' => ['携程', '美团'],
            'seasonality' => ['暑期旺季'],
            'data_quality' => ['严格回读'],
            'pre_action_state' => ['曝光高转化低'],
        ];
    }

    /** @param array<string,list<string>> $dimensions @return array<string,mixed> */
    private function profileInput(array $dimensions, string $qualityStatus): array
    {
        return [
            'profile' => $dimensions,
            'quality_status' => $qualityStatus,
            'effective_date' => '2026-08-12',
            'evidence_valid_until' => '2099-12-31',
            'source_method' => 'manual_reviewed_profile',
            'evidence_refs' => ['hotel_profile_review#20260812'],
            'onboarding' => [
                'room_rate_mapping' => [
                    'status' => 'verified',
                    'evidence_refs' => ['room_rate_mapping#20260812'],
                ],
                'metric_definition' => [
                    'status' => 'verified',
                    'evidence_refs' => ['metric_definition#20260812'],
                ],
            ],
        ];
    }

    /** @param array<string,list<string>> $dimensions */
    private function insertVerifiedSop(array $dimensions, array $scopeOverrides = []): int
    {
        $scope = [
            'tenant_id' => 10,
            'hotel_id' => 20,
            'platform' => 'ctrip',
            'source_scope' => 'ota_channel',
            'evidence_date_start' => '2026-08-01',
            'evidence_date_end' => '2026-08-03',
            'applicable_data_types' => ['traffic'],
            'metric_definitions' => ['曝光与浏览按携程OTA渠道口径'],
            'replication_scope' => 'same_tenant_draft_only',
            'applicability_contract_version' => OperatingNetworkService::CONTRACT_VERSION,
            'applicability_profile' => $dimensions,
            'action_parameters' => ['连续3天调整首图并保持价格不变'],
            'success_conditions' => ['详情页访问率提升且订单转化不下降'],
            'failure_samples' => ['曝光增加但详情访问率无改善'],
            'evidence_valid_until' => '2099-12-31',
        ];
        $scope = array_replace($scope, $scopeOverrides);
        return (int)Db::name('hotel_operating_sop_versions')->insertGetId([
            'tenant_id' => 10,
            'hotel_id' => 20,
            'sop_key' => 'controlled-network-test',
            'version_no' => 1,
            'previous_version_id' => null,
            'title' => '携程流量承接优化',
            'objective' => '验证首图调整是否改善流量承接',
            'steps_json' => json_encode(['保存执行前状态', '调整首图', '观察3天'], JSON_THROW_ON_ERROR),
            'stop_conditions_json' => json_encode(['订单转化连续两天下降'], JSON_THROW_ON_ERROR),
            'scope_json' => json_encode($scope, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'source_memory_ids_json' => json_encode([1, 2, 3], JSON_THROW_ON_ERROR),
            'evidence_refs_json' => json_encode(['hotel_operating_memories#1'], JSON_THROW_ON_ERROR),
            'validation_status' => 'verified',
            'validation_note' => '人工验证',
            'content_digest' => str_repeat('a', 64),
            'lifecycle_status' => 'active',
            'created_by' => 7,
            'validated_by' => 8,
            'validated_at' => '2026-08-12 12:00:00',
            'created_at' => '2026-08-12 12:00:00',
            'updated_at' => '2026-08-12 12:00:00',
            'deleted_at' => null,
        ]);
    }

    private function insertTrustedCollection(int $hotelId): int
    {
        return (int)Db::name('online_daily_data')->insertGetId([
            'tenant_id' => 10,
            'system_hotel_id' => $hotelId,
            'data_date' => '2026-08-12',
            'platform' => 'ctrip',
            'source' => 'ctrip',
            'data_type' => 'traffic',
            'readback_verified' => 1,
            'validation_status' => 'normal',
        ]);
    }

    private function insertEffectReview(int $hotelId, string $resultStatus): int
    {
        $sequence = (int)Db::name('operation_effect_reviews')->where('hotel_id', $hotelId)->count() + 1;
        return (int)Db::name('operation_effect_reviews')->insertGetId([
            'tenant_id' => 10,
            'hotel_id' => $hotelId,
            'intent_id' => 0,
            'task_id' => 0,
            'platform' => 'ctrip',
            'baseline_business_date' => '2026-08-11',
            'review_business_date' => '2026-08-12',
            'metric_key' => 'conversion_rate',
            'metric_definition_json' => '{}',
            'metric_definition_digest' => str_repeat('b', 64),
            'before_value' => 10,
            'after_value' => $resultStatus === 'success' ? 12 : 9,
            'expected_direction' => 'increase',
            'target_type' => 'delta',
            'target_value' => null,
            'expected_delta' => 1,
            'expected_delta_status' => 'manual_confirmed',
            'target_confirmed_by' => 9,
            'target_confirmed_at' => '2026-08-11 10:00:00',
            'baseline_refs_json' => '["online_daily_data#1"]',
            'followup_refs_json' => '["online_daily_data#2"]',
            'source_readback_evidence_id' => 0,
            'outcome_status' => $resultStatus === 'success' ? 'met' : 'missed',
            'outcome_json' => '{}',
            'result_status' => $resultStatus,
            'result_summary' => 'Synthetic bootstrap operating-cycle review.',
            'causality_claimed' => 0,
            'reviewed_by' => 9,
            'reviewed_at' => '2026-08-12 12:00:00',
            'approval_target_digest' => str_repeat('c', 64),
            'content_digest' => hash('sha256', implode(':', [$hotelId, $resultStatus, $sequence])),
            'created_at' => '2026-08-12 12:00:00',
        ]);
    }

    private function insertExecutionEvidence(int $hotelId): int
    {
        $taskId = (int)Db::name('operation_execution_tasks')->insertGetId([
            'tenant_id' => 10,
            'hotel_id' => $hotelId,
            'deleted_at' => null,
        ]);
        return (int)Db::name('operation_execution_evidence')->insertGetId([
            'tenant_id' => 10,
            'task_id' => $taskId,
            'deleted_at' => null,
        ]);
    }

    /** @return array{effect_review_id:int,review_business_date:string,intent_id:int,task_id:int,evidence_id:int} */
    private function createFormalReplicationEffectReview(
        OperatingNetworkService $network,
        int $replicationId,
        int $hotelId,
        string $resultStatus,
        int $attempt
    ): array {
        $baselineDate = (new \DateTimeImmutable('2026-08-13'))
            ->modify('+' . (($attempt - 1) * 2) . ' days')
            ->format('Y-m-d');
        $reviewDate = (new \DateTimeImmutable($baselineDate))->modify('+1 day')->format('Y-m-d');
        $created = $network->createReplicationExecutionIntent(
            $replicationId,
            10,
            [20, 21, 22],
            [
                'platform' => 'ctrip',
                'object_type' => 'campaign',
                'action_type' => 'replace_hero_image_attempt_' . $attempt,
                'date_start' => $baselineDate,
                'date_end' => $baselineDate,
                'current_value' => ['conversion_rate' => 10],
                'target_value' => ['hero_image' => 'candidate_' . $attempt],
                'expected_metric' => 'conversion_rate',
                'expected_delta' => 1.5,
                'risk_level' => 'low',
            ],
            9
        );
        $intentId = (int)$created['execution_intent']['id'];
        $approved = (new OperationManagementService())->approveExecutionIntent(
            $intentId,
            true,
            'Human approved controlled replication attempt ' . $attempt . '.',
            9,
            [20, 21, 22],
            [
                'expected_metric' => 'conversion_rate',
                'expected_direction' => 'increase',
                'target_type' => 'delta',
                'expected_delta' => 1.5,
                'review_business_date' => $reviewDate,
            ]
        );
        $taskId = (int)$approved['tasks'][0]['id'];
        $summary = $resultStatus === 'success'
            ? 'The controlled replication met the frozen success target.'
            : 'The controlled replication missed the frozen success target.';
        Db::name('operation_execution_tasks')->where('id', $taskId)->update([
            'status' => 'executed',
            'result_status' => $resultStatus,
            'result_summary' => $summary,
            'operator_id' => 9,
            'executed_at' => $baselineDate . ' 18:00:00',
            'updated_at' => $baselineDate . ' 18:00:00',
        ]);
        $afterValue = $resultStatus === 'success' ? 12.0 : 9.0;
        $evidenceId = (int)Db::name('operation_execution_evidence')->insertGetId([
            'tenant_id' => 10,
            'task_id' => $taskId,
            'evidence_type' => 'source_verified_metric_readback',
            'before_json' => json_encode(['conversion_rate' => 10], JSON_THROW_ON_ERROR),
            'after_json' => json_encode(['conversion_rate' => $afterValue], JSON_THROW_ON_ERROR),
            'attachment_path' => '',
            'platform_response_json' => json_encode([
                'verification_authority' => 'system_readback',
                'source' => 'online_daily_data',
                'source_ref' => 'online_daily_data#' . (100 + $attempt) . ',' . (200 + $attempt),
                'system_hotel_id' => $hotelId,
                'tenant_id' => 10,
                'platform' => 'ctrip',
                'object_type' => 'campaign',
                'date_start' => $baselineDate,
                'date_end' => $baselineDate,
                'baseline_date' => $baselineDate,
                'review_date' => $reviewDate,
                'metric_key' => 'conversion_rate',
                'database_written' => true,
                'readback_verified' => true,
                'readback_count' => 1,
                'readback_at' => $reviewDate . ' 10:00:00',
                'validation_status' => 'verified',
                'source_validation_status' => 'source_verified',
                'baseline_source_ref' => 'online_daily_data#' . (100 + $attempt),
                'followup_source_ref' => 'online_daily_data#' . (200 + $attempt),
                'causality_claimed' => false,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'remark' => 'Synthetic formal same-scope readback for controlled replication.',
            'created_by' => 0,
            'created_at' => $reviewDate . ' 10:00:00',
            'updated_at' => $reviewDate . ' 10:00:00',
            'deleted_at' => null,
        ]);
        $effect = (new OperationEffectReviewService())->create(
            10,
            $hotelId,
            $intentId,
            $taskId,
            [
                'tenant_id' => 10,
                'hotel_id' => $hotelId,
                'intent_id' => $intentId,
                'task_id' => $taskId,
                'platform' => 'ctrip',
                'metric_key' => 'conversion_rate',
                'baseline_business_date' => $baselineDate,
                'review_business_date' => $reviewDate,
                'source_readback_evidence_id' => $evidenceId,
                'result_status' => $resultStatus,
                'result_summary' => $summary,
                'reviewed_at' => $reviewDate . ' 12:00:00',
                'causality_claimed' => false,
            ],
            9
        );
        return [
            'effect_review_id' => (int)$effect['review']['id'],
            'review_business_date' => $reviewDate,
            'intent_id' => $intentId,
            'task_id' => $taskId,
            'evidence_id' => $evidenceId,
        ];
    }

    /** @return array{review_business_date:string,intent_id:int,task_id:int,evidence_id:int} */
    private function createReplicationStopEvidence(
        OperatingNetworkService $network,
        int $replicationId,
        int $hotelId,
        int $attempt
    ): array {
        $baselineDate = '2026-08-13';
        $reviewDate = '2026-08-14';
        $created = $network->createReplicationExecutionIntent(
            $replicationId,
            10,
            [20, 21, 22],
            [
                'platform' => 'ctrip',
                'object_type' => 'campaign',
                'action_type' => 'stop_condition_attempt_' . $attempt,
                'date_start' => $baselineDate,
                'date_end' => $baselineDate,
                'current_value' => ['conversion_rate' => 10],
                'target_value' => ['hero_image' => 'candidate_stop'],
                'expected_metric' => 'conversion_rate',
                'expected_delta' => 1.5,
                'risk_level' => 'low',
            ],
            9
        );
        $intentId = (int)$created['execution_intent']['id'];
        $approved = (new OperationManagementService())->approveExecutionIntent(
            $intentId,
            true,
            'Human approved a bounded stop-condition attempt.',
            9,
            [20, 21, 22],
            [
                'expected_metric' => 'conversion_rate',
                'expected_direction' => 'increase',
                'target_type' => 'delta',
                'expected_delta' => 1.5,
                'review_business_date' => $reviewDate,
            ]
        );
        $taskId = (int)$approved['tasks'][0]['id'];
        Db::name('operation_execution_tasks')->where('id', $taskId)->update([
            'status' => 'blocked',
            'blocked_reason' => 'Frozen stop condition triggered.',
            'operator_id' => 9,
            'updated_at' => $baselineDate . ' 18:00:00',
        ]);
        $evidenceId = (int)Db::name('operation_execution_evidence')->insertGetId([
            'tenant_id' => 10,
            'task_id' => $taskId,
            'evidence_type' => 'manual_operation_execution',
            'before_json' => '{}',
            'after_json' => '{}',
            'attachment_path' => '',
            'platform_response_json' => json_encode([
                'mode' => 'operator_attested',
                'stop_condition_triggered' => true,
            ], JSON_THROW_ON_ERROR),
            'remark' => 'Frozen stop condition triggered before completion.',
            'created_by' => 9,
            'created_at' => $baselineDate . ' 18:00:00',
            'updated_at' => $baselineDate . ' 18:00:00',
            'deleted_at' => null,
        ]);
        return [
            'review_business_date' => $reviewDate,
            'intent_id' => $intentId,
            'task_id' => $taskId,
            'evidence_id' => $evidenceId,
        ];
    }

    private function linkExecutionEvidenceToCycle(int $cycleId, int $evidenceId, int $hotelId): void
    {
        $eventId = (int)Db::name('hotel_operating_cycles')->where('id', $cycleId)->value('last_event_id');
        Db::name('hotel_operating_cycle_evidence')->insert([
            'cycle_id' => $cycleId,
            'event_id' => $eventId,
            'tenant_id' => 10,
            'hotel_id' => $hotelId,
            'stage_key' => 'real_execution_receipt',
            'evidence_role' => 'execution_receipt',
            'source_table' => 'operation_execution_evidence',
            'source_row_id' => $evidenceId,
            'source_row_ids_json' => json_encode([$evidenceId], JSON_THROW_ON_ERROR),
            'verification_status' => 'readback_verified',
            'readback_verified' => 1,
        ]);
    }

    private function insertCompletedOperatingCycle(
        int $hotelId,
        ?int $effectReviewId,
        string $businessDate
    ): int {
        $effectReviewId ??= $this->insertEffectReview($hotelId, 'success');
        $digest = hash('sha256', implode(':', [$hotelId, $effectReviewId, $businessDate]));
        $cycleId = (int)Db::name('hotel_operating_cycles')->insertGetId([
            'tenant_id' => 10,
            'hotel_id' => $hotelId,
            'business_date' => $businessDate,
            'last_completed_stage' => 'review_experience_promotion',
            'last_completed_stage_index' => 7,
            'next_required_stage' => 'next_cycle_identity_confirmation',
            'cycle_status' => 'completed',
            'outcome_status' => 'supported',
            'experience_status' => 'not_reusable',
            'state_version' => 8,
            'last_event_id' => 0,
            'last_event_digest' => $digest,
            'projection_digest' => str_repeat('e', 64),
        ]);
        $eventId = (int)Db::name('hotel_operating_cycle_events')->insertGetId([
            'cycle_id' => $cycleId,
            'tenant_id' => 10,
            'hotel_id' => $hotelId,
            'stage_key' => 'review_experience_promotion',
            'stage_status' => 'completed',
            'event_digest' => $digest,
        ]);
        Db::name('hotel_operating_cycles')->where('id', $cycleId)->update(['last_event_id' => $eventId]);
        Db::name('hotel_operating_cycle_evidence')->insertAll([
            [
                'cycle_id' => $cycleId,
                'event_id' => $eventId,
                'tenant_id' => 10,
                'hotel_id' => $hotelId,
                'stage_key' => 'comparable_outcome_readback',
                'evidence_role' => 'outcome_readback',
                'source_table' => 'operation_effect_reviews',
                'source_row_id' => $effectReviewId,
                'source_row_ids_json' => json_encode([$effectReviewId], JSON_THROW_ON_ERROR),
                'verification_status' => 'readback_verified',
                'readback_verified' => 1,
            ],
            [
                'cycle_id' => $cycleId,
                'event_id' => $eventId,
                'tenant_id' => 10,
                'hotel_id' => $hotelId,
                'stage_key' => 'review_experience_promotion',
                'evidence_role' => 'operating_memory',
                'source_table' => 'hotel_operating_memories',
                'source_row_id' => 1,
                'source_row_ids_json' => '[1]',
                'verification_status' => 'readback_verified',
                'readback_verified' => 1,
            ],
        ]);
        return $cycleId;
    }
}
