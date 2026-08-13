<?php
declare(strict_types=1);

namespace Tests;

use app\service\HotelCollectionPlanService;
use app\service\OperatingLoopCoordinatorService;
use app\service\OperatingLoopKernelService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OperatingLoopCoordinatorServiceTest extends TestCase
{
    private const TENANT_ID = 10;
    private const HOTEL_ID = 80;
    private const BUSINESS_DATE = '2026-08-11';

    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App(dirname(__DIR__));
        $app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'operating_loop_coordinator_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.sqlite';
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
            'hotel_operating_cycle_evidence',
            'hotel_operating_cycle_events',
            'hotel_operating_cycles',
            'hotel_operating_memories',
            'operation_effect_reviews',
            'operation_execution_evidence',
            'operation_execution_tasks',
            'operation_execution_intents',
            'online_daily_data',
            'dingdandao_operating_target_captures',
            'hotel_collection_plan_run_sources',
            'hotel_collection_plan_runs',
            'hotel_collection_plans',
            'dingdandao_pms_integrations',
            'platform_data_sources',
            'hotels',
        ] as $table) {
            Db::execute('DROP TABLE IF EXISTS ' . $table);
        }
        $this->createSchema();
        $this->seedIdentities();
    }

    public function testReconcileFreezesActivePlanAndIgnoresStalePlanReceipts(): void
    {
        $planService = $this->planService();
        $plan = $this->activatePlan($planService);
        self::assertTrue($plan['readback_verified']);
        self::assertSame('ready', $plan['stored_validation_status']);

        $this->seedStalePlanReceipt();
        $coordinator = new OperatingLoopCoordinatorService(
            new OperatingLoopKernelService(),
            $planService,
            static fn(): array => self::readyRevenueLayer()
        );

        $result = $coordinator->reconcile(
            self::TENANT_ID,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            7
        );

        self::assertSame(['identity_business_date_confirmed'], $result['reconciled_stages']);
        self::assertSame('trusted_collection', $result['waiting']['stage']);
        self::assertSame('ota_collection_receipt_missing', $result['waiting']['code']);
        self::assertSame('readback_verified', $result['persistence_status']);
        self::assertSame(1, $result['operating_loop']['record_id']);
        self::assertSame(1, $result['operating_loop']['revision']);
        self::assertSame('trusted_collection', $result['operating_loop']['next_required_stage']);

        $cycle = Db::name('hotel_operating_cycles')->where('id', 1)->find();
        self::assertIsArray($cycle);
        $identities = json_decode((string)$cycle['source_identities_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(3, $identities);
        self::assertSame(
            ['ctrip' => 25, 'meituan' => 68],
            $this->otaSourceIdsByPlatform($identities)
        );
        foreach ($identities as $identity) {
            self::assertSame((int)$plan['id'], (int)$identity['collection_plan_id']);
            self::assertSame((int)$plan['plan_version'], (int)$identity['collection_plan_version']);
            self::assertSame((string)$plan['plan_hash'], (string)$identity['collection_plan_hash']);
        }
        self::assertSame(1, Db::name('hotel_operating_cycle_events')->count());
        self::assertSame(4, Db::name('hotel_operating_cycle_evidence')->count());

        $again = $coordinator->reconcile(
            self::TENANT_ID,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            7
        );
        self::assertSame([], $again['reconciled_stages']);
        self::assertSame('ota_collection_receipt_missing', $again['waiting']['code']);
        self::assertSame(1, Db::name('hotel_operating_cycles')->count());
        self::assertSame(1, Db::name('hotel_operating_cycle_events')->count());
    }

    public function testFormalThreeSourceReceiptsReachFactsThenWaitForHumanDecision(): void
    {
        $planService = $this->planService();
        $plan = $this->activatePlan($planService);
        $this->seedAuthoritativePlanReceipt($plan);
        $coordinator = new OperatingLoopCoordinatorService(
            new OperatingLoopKernelService(),
            $planService,
            static fn(): array => self::readyRevenueLayer()
        );

        $result = $coordinator->reconcile(
            self::TENANT_ID,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            7
        );

        self::assertSame([
            'identity_business_date_confirmed',
            'trusted_collection',
            'formal_save_exact_readback',
            'operating_facts_established',
        ], $result['reconciled_stages']);
        self::assertSame('recommendation_human_decision', $result['waiting']['stage']);
        self::assertSame('human_decision_not_recorded', $result['waiting']['code']);
        self::assertSame('decision_owner', $result['waiting']['owner']['role']);
        self::assertSame('readback_verified', $result['persistence_status']);
        self::assertTrue($result['operating_loop']['authoritative']);
        self::assertTrue($result['operating_loop']['readback_verified']);
        self::assertSame(4, $result['operating_loop']['revision']);
        self::assertSame('operating_facts_established', $result['operating_loop']['last_completed_stage']);
        self::assertSame('recommendation_human_decision', $result['operating_loop']['next_required_stage']);

        self::assertSame(1, Db::name('hotel_operating_cycles')->count());
        self::assertSame(4, Db::name('hotel_operating_cycle_events')->count());
        self::assertSame(16, Db::name('hotel_operating_cycle_evidence')->count());
        self::assertSame([
            'identity_business_date_confirmed',
            'trusted_collection',
            'formal_save_exact_readback',
            'operating_facts_established',
        ], Db::name('hotel_operating_cycle_events')->order('sequence_no', 'asc')->column('stage_key'));

        $factEvidence = Db::name('hotel_operating_cycle_evidence')
            ->where('stage_key', 'operating_facts_established')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        self::assertCount(3, $factEvidence);
        self::assertCount(1, array_filter(
            $factEvidence,
            static fn(array $row): bool => $row['fact_scope'] === 'whole_hotel_accommodation'
        ));
        self::assertCount(2, array_filter(
            $factEvidence,
            static fn(array $row): bool => $row['fact_scope'] === 'ota_channel'
        ));
        foreach ($factEvidence as $row) {
            self::assertSame(1, (int)$row['readback_verified']);
            self::assertSame(
                (string)$factEvidence[0]['metric_definition_digest'],
                (string)$row['metric_definition_digest']
            );
        }

        $savedRowIds = Db::name('hotel_operating_cycle_evidence')
            ->where('stage_key', 'formal_save_exact_readback')
            ->where('evidence_role', 'saved_rows')
            ->order('source_row_id', 'asc')
            ->column('source_row_ids_json');
        self::assertSame([[901], [1001], [1002]], array_map(
            static fn(string $json): array => json_decode($json, true, 512, JSON_THROW_ON_ERROR),
            $savedRowIds
        ));

        $again = $coordinator->reconcile(
            self::TENANT_ID,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            7
        );
        self::assertSame([], $again['reconciled_stages']);
        self::assertSame('human_decision_not_recorded', $again['waiting']['code']);
        self::assertSame(1, Db::name('hotel_operating_cycles')->count());
        self::assertSame(4, Db::name('hotel_operating_cycle_events')->count());
        self::assertSame(16, Db::name('hotel_operating_cycle_evidence')->count());
    }

    public function testDecisionSupportMemoryProjectsToCandidateExperience(): void
    {
        Db::name('operation_effect_reviews')->insert([
            'id' => 501,
            'task_id' => 601,
        ]);
        Db::name('hotel_operating_memories')->insert([
            'id' => 701,
            'tenant_id' => self::TENANT_ID,
            'hotel_id' => self::HOTEL_ID,
            'business_date' => self::BUSINESS_DATE,
            'platform' => 'ctrip',
            'quality_status' => 'verified',
            'lifecycle_status' => 'active',
            'usage_level' => 'decision_support',
            'source_record_type' => 'operation_effect_review',
            'source_record_id' => 501,
            'summary' => 'Verified review is useful as decision support.',
            'recorded_by' => 7,
            'occurred_at' => '2026-08-12 10:00:00',
            'created_at' => '2026-08-12 10:00:00',
        ]);
        $cycle = [
            'tenant_id' => self::TENANT_ID,
            'hotel_id' => self::HOTEL_ID,
            'business_date' => self::BUSINESS_DATE,
            'revision' => 7,
            'events' => [[
                'stage_key' => 'comparable_outcome_readback',
                'evidence_refs' => [[
                    'role' => 'outcome_readback',
                    'row_ids' => [501],
                ]],
            ]],
        ];

        $method = new \ReflectionMethod(OperatingLoopCoordinatorService::class, 'experienceTransition');
        $adapter = $method->invoke(new OperatingLoopCoordinatorService(), $cycle, 7);

        self::assertTrue($adapter['ready']);
        self::assertSame('candidate', $adapter['input']['payload']['experience_status']);
        self::assertSame(701, $adapter['input']['evidence_refs'][0]['row_ids'][0]);
        self::assertSame(7, $adapter['input']['expected_version']);
    }

    public function testExecutionUsesLatestCompletedApprovalAfterAnEarlierRejection(): void
    {
        $cycle = [
            'events' => [
                [
                    'stage_key' => 'recommendation_human_decision',
                    'stage_status' => 'blocked',
                    'evidence_refs' => [[
                        'role' => 'recommendation',
                        'table' => 'operation_execution_intents',
                        'row_ids' => [54],
                    ]],
                ],
                [
                    'stage_key' => 'recommendation_human_decision',
                    'stage_status' => 'completed',
                    'evidence_refs' => [[
                        'role' => 'recommendation',
                        'table' => 'operation_execution_intents',
                        'row_ids' => [98],
                    ]],
                ],
            ],
        ];

        $method = new \ReflectionMethod(OperatingLoopCoordinatorService::class, 'decisionEvidenceIntentId');
        self::assertSame(98, $method->invoke(new OperatingLoopCoordinatorService(), $cycle));
    }

    public function testExecutionAdapterRejectsPlaceholderBeforeAfterEvidence(): void
    {
        Db::name('operation_execution_intents')->insert([
            'id' => 98,
            'tenant_id' => self::TENANT_ID,
            'hotel_id' => self::HOTEL_ID,
            'platform' => 'ctrip',
            'object_type' => 'campaign',
            'action_type' => 'update_image',
            'date_start' => self::BUSINESS_DATE,
            'target_value_json' => '{}',
            'evidence_json' => '{}',
            'status' => 'approved',
        ]);
        Db::name('operation_execution_tasks')->insert([
            'id' => 601,
            'tenant_id' => self::TENANT_ID,
            'hotel_id' => self::HOTEL_ID,
            'intent_id' => 98,
            'operator_id' => 8,
            'status' => 'executed',
            'result_summary' => 'updated campaign image',
            'executed_at' => '2026-08-12 09:00:00',
        ]);
        Db::name('operation_execution_evidence')->insert([
            'id' => 701,
            'tenant_id' => self::TENANT_ID,
            'task_id' => 601,
            'evidence_type' => 'manual_operation_execution',
            'before_json' => '{"arbitrary":"same-placeholder"}',
            'after_json' => '{"arbitrary":"same-placeholder"}',
            'attachment_path' => '',
            'platform_response_json' => null,
            'remark' => 'placeholder only',
            'created_by' => 8,
        ]);
        $cycle = [
            'tenant_id' => self::TENANT_ID,
            'hotel_id' => self::HOTEL_ID,
            'business_date' => self::BUSINESS_DATE,
            'revision' => 5,
            'details' => ['recommendation_human_decision' => []],
            'events' => [[
                'stage_key' => 'recommendation_human_decision',
                'stage_status' => 'completed',
                'evidence_refs' => [[
                    'role' => 'recommendation',
                    'table' => 'operation_execution_intents',
                    'row_ids' => [98],
                ]],
            ]],
        ];

        $method = new \ReflectionMethod(OperatingLoopCoordinatorService::class, 'executionTransition');
        $blocked = $method->invoke(new OperatingLoopCoordinatorService(), $cycle, 8);
        self::assertFalse($blocked['ready']);
        self::assertSame('execution_evidence_missing', $blocked['code']);

        Db::name('operation_execution_evidence')->where('id', 701)->update([
            'before_json' => '{"hero_image":"baseline"}',
            'after_json' => '{"hero_image":"candidate-b"}',
        ]);
        $ready = $method->invoke(new OperatingLoopCoordinatorService(), $cycle, 8);
        self::assertTrue($ready['ready']);
        self::assertSame([701], $ready['input']['evidence_refs'][2]['row_ids']);
    }

    public function testFailedReceiptIsEvidenceLinkedAndVerifiedRetryRecoversSameCycle(): void
    {
        $planService = $this->planService();
        $plan = $this->activatePlan($planService);
        $this->seedFailedPlanReceipt($plan);
        $coordinator = new OperatingLoopCoordinatorService(
            new OperatingLoopKernelService(),
            $planService,
            static fn(): array => self::readyRevenueLayer()
        );

        $blocked = $coordinator->reconcile(
            self::TENANT_ID,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            7
        );

        self::assertSame([
            'identity_business_date_confirmed',
            'trusted_collection',
        ], $blocked['reconciled_stages']);
        self::assertNull($blocked['waiting']);
        self::assertSame('blocked', $blocked['operating_loop']['authoritative_state']);
        self::assertSame('trusted_collection', $blocked['operating_loop']['next_required_stage']);
        self::assertSame(2, $blocked['operating_loop']['revision']);
        $blockedEvidence = Db::name('hotel_operating_cycle_evidence')
            ->where('stage_key', 'trusted_collection')
            ->find();
        self::assertIsArray($blockedEvidence);
        self::assertSame('hotel_collection_plan_run_sources', $blockedEvidence['source_table']);
        self::assertSame('unverified', $blockedEvidence['verification_status']);
        self::assertSame(0, (int)$blockedEvidence['readback_verified']);

        $this->seedAuthoritativePlanReceipt($plan);
        $recovered = $coordinator->reconcile(
            self::TENANT_ID,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            7
        );

        self::assertSame([
            'trusted_collection',
            'formal_save_exact_readback',
            'operating_facts_established',
        ], $recovered['reconciled_stages']);
        self::assertSame('human_decision_not_recorded', $recovered['waiting']['code']);
        self::assertSame('active', $recovered['operating_loop']['authoritative_state']);
        self::assertSame(5, $recovered['operating_loop']['revision']);
        self::assertSame(1, Db::name('hotel_operating_cycles')->count());
        self::assertSame(5, Db::name('hotel_operating_cycle_events')->count());
        self::assertSame(17, Db::name('hotel_operating_cycle_evidence')->count());
    }

    public function testRevenueFactLayerFailureRemainsWaitingWithoutFalseAnalysisCompletion(): void
    {
        $planService = $this->planService();
        $plan = $this->activatePlan($planService);
        $this->seedAuthoritativePlanReceipt($plan);
        $coordinator = new OperatingLoopCoordinatorService(
            new OperatingLoopKernelService(),
            $planService,
            static function (): array {
                throw new \RuntimeException('synthetic_fact_layer_failure');
            }
        );

        $result = $coordinator->reconcile(
            self::TENANT_ID,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            7
        );

        self::assertSame([
            'identity_business_date_confirmed',
            'trusted_collection',
            'formal_save_exact_readback',
        ], $result['reconciled_stages']);
        self::assertSame('operating_facts_established', $result['waiting']['stage']);
        self::assertSame('revenue_fact_layer_read_failed', $result['waiting']['code']);
        self::assertSame('formal_save_exact_readback', $result['operating_loop']['last_completed_stage']);
        self::assertSame(0, Db::name('hotel_operating_cycle_events')
            ->where('stage_key', 'operating_facts_established')
            ->count());
    }

    /** @return array<string,mixed> */
    private static function readyRevenueLayer(): array
    {
        return [
            'contract_version' => \app\service\RevenueFactLayerService::CONTRACT_VERSION,
            'status' => 'ready',
            'revenue_analysis_status' => 'ready',
            'hotel' => [
                'tenant_id' => self::TENANT_ID,
                'system_hotel_id' => self::HOTEL_ID,
            ],
            'business_date' => self::BUSINESS_DATE,
            'all_three_sources_readback_verified' => true,
            'analysis_diagnostics' => [
                'summary' => 'Three-source revenue facts passed exact readback.',
                'issues' => [],
            ],
        ];
    }

    /** @param list<array<string,mixed>> $identities */
    private function otaSourceIdsByPlatform(array $identities): array
    {
        $result = [];
        foreach ($identities as $identity) {
            if (($identity['source_kind'] ?? '') === 'ota') {
                $result[(string)$identity['platform']] = (int)$identity['data_source_id'];
            }
        }
        ksort($result);
        return $result;
    }

    private function planService(): HotelCollectionPlanService
    {
        return new HotelCollectionPlanService(
            fn(array $hotel, int $actorId, string $date, array $designated): array => $this->bindingReceipt(),
            static fn(): DateTimeImmutable => new DateTimeImmutable(
                '2026-08-12 08:00:00',
                new DateTimeZone('Asia/Shanghai')
            ),
            str_repeat('k', 32)
        );
    }

    /** @return array<string,mixed> */
    private function activatePlan(HotelCollectionPlanService $service): array
    {
        return $service->save(
            [
                'id' => self::HOTEL_ID,
                'tenant_id' => self::TENANT_ID,
                'name' => 'Hotel 80',
                'status' => 1,
            ],
            7,
            [
                'sources' => [
                    'ctrip' => ['data_source_id' => 25],
                    'meituan' => ['data_source_id' => 68],
                    'pms' => ['provider' => 'dingdandao_pms'],
                ],
                'business_date' => self::BUSINESS_DATE,
                'business_date_policy' => 'previous_business_day',
                'timezone' => 'Asia/Shanghai',
                'schedule_time' => '08:30',
                'retry_interval_minutes' => 14,
                'max_attempts' => 7,
                'activate' => true,
            ]
        );
    }

    /** @return array<string,mixed> */
    private function bindingReceipt(): array
    {
        return [
            'status' => 'ready',
            'binding_digest' => str_repeat('a', 64),
            'system_hotel' => [
                'tenant_id' => self::TENANT_ID,
                'system_hotel_id' => self::HOTEL_ID,
                'enabled' => true,
            ],
            'bindings' => [
                'ctrip' => $this->otaBinding('ctrip', 25, 'CTRIP-25'),
                'meituan' => $this->otaBinding('meituan', 68, 'MT-68'),
                'pms' => [
                    'platform' => 'pms',
                    'status' => 'ready',
                    'tenant_id' => self::TENANT_ID,
                    'system_hotel_id' => self::HOTEL_ID,
                    'provider' => 'dingdandao_pms',
                    'provider_hotel_id' => 'DD-80',
                    'provider_hotel_name' => 'Hotel 80',
                    'blockers' => [],
                    'recovery_reasons' => [],
                ],
            ],
            'blockers' => [],
            'recovery_reasons' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function otaBinding(string $platform, int $sourceId, string $platformHotelId): array
    {
        return [
            'platform' => $platform,
            'status' => 'ready',
            'tenant_id' => self::TENANT_ID,
            'system_hotel_id' => self::HOTEL_ID,
            'source_id' => $sourceId,
            'designated_source_id' => $sourceId,
            'execution_owner_user_id' => 7,
            'platform_hotel_id' => $platformHotelId,
            'profile_binding' => [
                'profile_binding_digest' => hash('sha256', 'profile-' . $platform . '-' . $sourceId),
            ],
            'execution_device_binding' => [
                'execution_binding_digest' => hash('sha256', 'execution-' . $platform . '-' . $sourceId),
                'device_binding_digest' => hash('sha256', 'device-public-id'),
            ],
            'blockers' => [],
            'recovery_reasons' => [],
        ];
    }

    private function seedIdentities(): void
    {
        Db::name('hotels')->insert([
            'id' => self::HOTEL_ID,
            'tenant_id' => self::TENANT_ID,
            'name' => 'Hotel 80',
            'status' => 1,
        ]);
        foreach ([
            [25, 'ctrip', 'CTRIP-25'],
            [68, 'meituan', 'MT-68'],
        ] as [$id, $platform, $externalHotelId]) {
            Db::name('platform_data_sources')->insert([
                'id' => $id,
                'tenant_id' => self::TENANT_ID,
                'system_hotel_id' => self::HOTEL_ID,
                'platform' => $platform,
                'config_json' => json_encode(['platform_hotel_id' => $externalHotelId]),
            ]);
        }
        Db::name('dingdandao_pms_integrations')->insert([
            'id' => 3,
            'tenant_id' => self::TENANT_ID,
            'hotel_id' => self::HOTEL_ID,
            'provider' => 'dingdandao_pms',
            'provider_hotel_id' => 'DD-80',
            'status' => 1,
        ]);
    }

    private function seedStalePlanReceipt(): void
    {
        Db::name('hotel_collection_plan_runs')->insert([
            'id' => 90,
            'dispatcher_run_id' => 'stale-plan-run',
            'tenant_id' => self::TENANT_ID,
            'system_hotel_id' => self::HOTEL_ID,
            'business_date' => self::BUSINESS_DATE,
            'run_mode' => 'daily',
            'plan_id' => 99,
            'plan_version' => 1,
            'plan_hash' => str_repeat('b', 64),
            'scope_hash' => str_repeat('c', 64),
            'status' => 'succeeded',
            'page_status' => 'verified',
            'pms_provider' => 'dingdandao_pms',
            'pms_status' => 'verified',
            'pms_capture_id' => '901',
            'pms_readback_verified' => 1,
            'started_at' => '2026-08-12 07:00:00',
            'finished_at' => '2026-08-12 07:05:00',
        ]);
        foreach ([25 => 'ctrip', 68 => 'meituan'] as $sourceId => $platform) {
            Db::name('hotel_collection_plan_run_sources')->insert([
                'run_id' => 90,
                'platform' => $platform,
                'data_source_id' => $sourceId,
                'ingestion_method' => 'local_collector',
                'platform_sync_task_id' => 1000 + $sourceId,
                'status' => 'success',
                'saved_row_count' => 1,
                'readback_row_count' => 1,
                'readback_verified' => 1,
                'page_acceptance_status' => 'verified',
                'finished_at' => '2026-08-12 07:05:00',
            ]);
        }
    }

    /** @param array<string,mixed> $plan */
    private function seedFailedPlanReceipt(array $plan): void
    {
        $runId = (int)Db::name('hotel_collection_plan_runs')->insertGetId([
            'dispatcher_run_id' => 'current-plan-failed-run',
            'tenant_id' => self::TENANT_ID,
            'system_hotel_id' => self::HOTEL_ID,
            'business_date' => self::BUSINESS_DATE,
            'run_mode' => 'daily',
            'plan_id' => (int)$plan['id'],
            'plan_version' => (int)$plan['plan_version'],
            'plan_hash' => (string)$plan['plan_hash'],
            'scope_hash' => hash('sha256', 'current-plan-failed-run'),
            'status' => 'failed',
            'failure_stage' => 'collection',
            'failure_code' => 'profile_session_unverified',
            'page_status' => 'not_evaluated',
            'pms_provider' => 'dingdandao_pms',
            'pms_status' => 'not_run',
            'pms_readback_verified' => 0,
            'started_at' => '2026-08-12 07:30:00',
            'finished_at' => '2026-08-12 07:31:00',
        ]);
        foreach ([25 => 'ctrip', 68 => 'meituan'] as $sourceId => $platform) {
            Db::name('hotel_collection_plan_run_sources')->insert([
                'run_id' => $runId,
                'platform' => $platform,
                'data_source_id' => $sourceId,
                'ingestion_method' => 'local_collector',
                'status' => 'failed',
                'failure_stage' => 'collection',
                'failure_code' => 'profile_session_unverified',
                'saved_row_count' => 0,
                'readback_row_count' => 0,
                'readback_verified' => 0,
                'page_acceptance_status' => 'not_evaluated',
                'started_at' => '2026-08-12 07:30:00',
                'finished_at' => '2026-08-12 07:31:00',
            ]);
        }
    }

    /** @param array<string,mixed> $plan */
    private function seedAuthoritativePlanReceipt(array $plan): void
    {
        Db::name('dingdandao_operating_target_captures')->insert([
            'id' => 901,
            'tenant_id' => self::TENANT_ID,
            'hotel_id' => self::HOTEL_ID,
            'provider' => 'dingdandao_pms',
            'business_date' => self::BUSINESS_DATE,
            'identity_status' => 'matched',
            'capture_status' => 'verified',
            'quality_status' => 'verified',
            'reconciliation_status' => 'matched',
            'readback_status' => 'readback_verified',
        ]);
        $runId = (int)Db::name('hotel_collection_plan_runs')->insertGetId([
            'dispatcher_run_id' => 'current-authoritative-plan-run',
            'tenant_id' => self::TENANT_ID,
            'system_hotel_id' => self::HOTEL_ID,
            'business_date' => self::BUSINESS_DATE,
            'run_mode' => 'daily',
            'plan_id' => (int)$plan['id'],
            'plan_version' => (int)$plan['plan_version'],
            'plan_hash' => (string)$plan['plan_hash'],
            'scope_hash' => hash('sha256', 'current-authoritative-plan-run'),
            'status' => 'succeeded',
            'page_status' => 'verified',
            'pms_provider' => 'dingdandao_pms',
            'pms_status' => 'verified',
            'pms_capture_id' => '901',
            'pms_readback_verified' => 1,
            'started_at' => '2026-08-12 08:00:00',
            'finished_at' => '2026-08-12 08:05:00',
        ]);
        foreach ([
            ['platform' => 'ctrip', 'source_id' => 25, 'sync_task_id' => 1025, 'row_id' => 1001],
            ['platform' => 'meituan', 'source_id' => 68, 'sync_task_id' => 1068, 'row_id' => 1002],
        ] as $source) {
            Db::name('online_daily_data')->insert([
                'id' => $source['row_id'],
                'tenant_id' => self::TENANT_ID,
                'data_source_id' => $source['source_id'],
                'sync_task_id' => $source['sync_task_id'],
                'system_hotel_id' => self::HOTEL_ID,
                'data_date' => self::BUSINESS_DATE,
                'data_period' => 'historical_daily',
                'source' => $source['platform'],
                'history_status' => 'success',
                'validation_status' => 'verified',
                'readback_verified' => 1,
            ]);
            $digest = hash('sha256', (string)json_encode([
                'platform' => $source['platform'],
                'data_source_id' => $source['source_id'],
                'sync_task_id' => $source['sync_task_id'],
                'row_ids' => [$source['row_id']],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            Db::name('hotel_collection_plan_run_sources')->insert([
                'run_id' => $runId,
                'platform' => $source['platform'],
                'data_source_id' => $source['source_id'],
                'ingestion_method' => 'local_collector',
                'platform_sync_task_id' => $source['sync_task_id'],
                'status' => 'success',
                'saved_row_count' => 1,
                'readback_row_count' => 1,
                'readback_verified' => 1,
                'evidence_digest' => $digest,
                'page_acceptance_status' => 'verified',
                'finished_at' => '2026-08-12 08:05:00',
            ]);
        }
    }

    private function createSchema(): void
    {
        Db::execute('PRAGMA foreign_keys = ON');
        foreach ([
            'CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT NOT NULL, status INTEGER NOT NULL)',
            'CREATE TABLE platform_data_sources (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL, platform TEXT NOT NULL, config_json TEXT NOT NULL)',
            'CREATE TABLE dingdandao_pms_integrations (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, provider TEXT NOT NULL, provider_hotel_id TEXT NOT NULL, status INTEGER NOT NULL)',
            'CREATE TABLE hotel_collection_plans (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL, plan_version INTEGER NOT NULL, plan_status TEXT NOT NULL, enabled INTEGER NOT NULL, active_slot INTEGER NULL, business_date_policy TEXT NOT NULL, timezone TEXT NOT NULL, schedule_time TEXT NOT NULL, retry_interval_minutes INTEGER NOT NULL, max_attempts INTEGER NOT NULL, execution_owner_user_id INTEGER NULL, binding_digest TEXT NOT NULL, plan_hash TEXT NOT NULL, source_plan_json TEXT NOT NULL, validation_status TEXT NOT NULL, validation_reasons_json TEXT NOT NULL, activated_at TEXT NULL, created_by INTEGER NOT NULL, updated_by INTEGER NOT NULL, create_time TEXT NOT NULL, update_time TEXT NOT NULL, UNIQUE (tenant_id, system_hotel_id, plan_version), UNIQUE (tenant_id, system_hotel_id, active_slot))',
            'CREATE TABLE hotel_collection_plan_runs (id INTEGER PRIMARY KEY AUTOINCREMENT, dispatcher_run_id TEXT NOT NULL, tenant_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL, business_date TEXT NOT NULL, run_mode TEXT NOT NULL, plan_id INTEGER NULL, plan_version INTEGER NOT NULL, plan_hash TEXT NOT NULL DEFAULT \'\', scope_hash TEXT NOT NULL, status TEXT NOT NULL, failure_stage TEXT NULL, failure_code TEXT NULL, collection_anchor_contract_version TEXT NULL, collection_anchor_hash TEXT NULL, trust_receipt_digest TEXT NULL, page_status TEXT NOT NULL, page_receipt_id INTEGER NULL, page_contract_hash TEXT NULL, pms_provider TEXT NULL, pms_status TEXT NOT NULL, pms_capture_id TEXT NULL, pms_readback_verified INTEGER NULL, started_at TEXT NULL, finished_at TEXT NULL)',
            'CREATE TABLE hotel_collection_plan_run_sources (id INTEGER PRIMARY KEY AUTOINCREMENT, run_id INTEGER NOT NULL, platform TEXT NOT NULL, data_source_id INTEGER NULL, ingestion_method TEXT NOT NULL, platform_sync_task_id INTEGER NULL, local_collector_task_id INTEGER NULL, status TEXT NOT NULL, failure_stage TEXT NULL, failure_code TEXT NULL, saved_row_count INTEGER NOT NULL, readback_row_count INTEGER NOT NULL, readback_verified INTEGER NOT NULL, evidence_digest TEXT NOT NULL DEFAULT \'\', page_acceptance_status TEXT NOT NULL, page_acceptance_log_id INTEGER NULL, started_at TEXT NULL, finished_at TEXT NULL)',
            'CREATE TABLE online_daily_data (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, data_source_id INTEGER NOT NULL, sync_task_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL, data_date TEXT NOT NULL, data_period TEXT NOT NULL, source TEXT NOT NULL, history_status TEXT NOT NULL, validation_status TEXT NOT NULL, readback_verified INTEGER NOT NULL)',
            'CREATE TABLE dingdandao_operating_target_captures (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, provider TEXT NOT NULL, business_date TEXT NOT NULL, identity_status TEXT NOT NULL, capture_status TEXT NOT NULL, quality_status TEXT NOT NULL, reconciliation_status TEXT NOT NULL, readback_status TEXT NOT NULL)',
            'CREATE TABLE operation_execution_intents (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, platform TEXT NOT NULL DEFAULT \'\', object_type TEXT NOT NULL DEFAULT \'\', action_type TEXT NOT NULL DEFAULT \'\', date_start TEXT NOT NULL, date_end TEXT NULL, target_value_json TEXT NOT NULL DEFAULT \'{}\', evidence_json TEXT NOT NULL DEFAULT \'{}\', status TEXT NOT NULL DEFAULT \'draft\', approved_at TEXT NULL, deleted_at TEXT NULL)',
            'CREATE TABLE operation_execution_tasks (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, intent_id INTEGER NOT NULL, operator_id INTEGER NOT NULL, status TEXT NOT NULL, result_summary TEXT NOT NULL DEFAULT \'\', executed_at TEXT NULL, deleted_at TEXT NULL)',
            'CREATE TABLE operation_execution_evidence (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, task_id INTEGER NOT NULL, evidence_type TEXT NOT NULL, before_json TEXT NULL, after_json TEXT NULL, attachment_path TEXT NOT NULL DEFAULT \'\', platform_response_json TEXT NULL, remark TEXT NOT NULL DEFAULT \'\', created_by INTEGER NOT NULL, deleted_at TEXT NULL)',
            'CREATE TABLE operation_effect_reviews (id INTEGER PRIMARY KEY, task_id INTEGER NOT NULL)',
            'CREATE TABLE hotel_operating_memories (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, business_date TEXT NOT NULL, platform TEXT NOT NULL, quality_status TEXT NOT NULL, lifecycle_status TEXT NOT NULL, usage_level TEXT NOT NULL, source_record_type TEXT NOT NULL, source_record_id INTEGER NOT NULL, summary TEXT NOT NULL, recorded_by INTEGER NOT NULL, occurred_at TEXT NULL, created_at TEXT NOT NULL, deleted_at TEXT NULL)',
            'CREATE TABLE hotel_operating_cycles (id INTEGER PRIMARY KEY AUTOINCREMENT, authority_key TEXT NOT NULL UNIQUE, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, hotel_name_snapshot TEXT NOT NULL, business_date TEXT NOT NULL, metric_version TEXT NOT NULL, metric_definition_json TEXT NOT NULL, metric_definition_digest TEXT NOT NULL, source_identities_json TEXT NOT NULL, source_identity_digest TEXT NOT NULL, last_completed_stage TEXT NOT NULL DEFAULT \'\', last_completed_stage_index INTEGER NOT NULL DEFAULT -1, next_required_stage TEXT NOT NULL, cycle_status TEXT NOT NULL, block_code TEXT NOT NULL DEFAULT \'\', block_detail TEXT NOT NULL DEFAULT \'\', truth_summary TEXT NOT NULL, priority_issue TEXT NOT NULL DEFAULT \'\', next_action TEXT NOT NULL DEFAULT \'\', next_owner_json TEXT NULL, review_due_at TEXT NULL, outcome_status TEXT NOT NULL, experience_status TEXT NOT NULL, state_version INTEGER NOT NULL, last_event_id INTEGER NULL, last_event_digest TEXT NOT NULL, projection_digest TEXT NOT NULL, created_by INTEGER NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE (tenant_id, hotel_id, business_date))',
            'CREATE TABLE hotel_operating_cycle_events (id INTEGER PRIMARY KEY AUTOINCREMENT, cycle_id INTEGER NOT NULL, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, sequence_no INTEGER NOT NULL, command_key TEXT NOT NULL, command_digest TEXT NOT NULL, from_stage TEXT NOT NULL, to_stage TEXT NOT NULL, from_version INTEGER NOT NULL, to_version INTEGER NOT NULL, stage_key TEXT NOT NULL, stage_status TEXT NOT NULL, actor_kind TEXT NOT NULL, actor_id INTEGER NOT NULL, source_module TEXT NOT NULL, payload_json TEXT NOT NULL, evidence_digest TEXT NOT NULL, previous_event_digest TEXT NOT NULL, event_digest TEXT NOT NULL, occurred_at TEXT NOT NULL, created_at TEXT NOT NULL, UNIQUE (cycle_id, sequence_no), UNIQUE (cycle_id, command_key), FOREIGN KEY (cycle_id) REFERENCES hotel_operating_cycles(id) ON DELETE RESTRICT)',
            'CREATE TABLE hotel_operating_cycle_evidence (id INTEGER PRIMARY KEY AUTOINCREMENT, cycle_id INTEGER NOT NULL, event_id INTEGER NOT NULL, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, stage_key TEXT NOT NULL, evidence_role TEXT NOT NULL, source_kind TEXT NOT NULL, fact_scope TEXT NOT NULL, metric_definition_digest TEXT NOT NULL, platform TEXT NOT NULL, business_date TEXT NULL, source_table TEXT NOT NULL, source_row_id INTEGER NOT NULL, source_row_ids_json TEXT NOT NULL, source_row_count INTEGER NOT NULL, source_rows_digest TEXT NOT NULL, verification_status TEXT NOT NULL, readback_verified INTEGER NOT NULL, created_at TEXT NOT NULL, FOREIGN KEY (cycle_id) REFERENCES hotel_operating_cycles(id) ON DELETE RESTRICT, FOREIGN KEY (event_id) REFERENCES hotel_operating_cycle_events(id) ON DELETE RESTRICT)',
        ] as $sql) {
            Db::execute($sql);
        }
    }
}
