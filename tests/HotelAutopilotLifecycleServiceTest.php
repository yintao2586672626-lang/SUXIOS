<?php
declare(strict_types=1);

namespace tests;

use app\service\HotelAutopilotDispatcherProvisioningService;
use app\service\HotelAutopilotLifecycleService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class HotelAutopilotLifecycleServiceTest extends TestCase
{
    private static array $databaseConfig;
    private static string $databasePath;

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$databaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'hotel_autopilot_lifecycle_' . getmypid() . '.sqlite';
        @unlink(self::$databasePath);
        $config = self::$databaseConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$databasePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($config, 'database');
        Db::connect(null, true);
        self::createSchema();
    }

    public static function tearDownAfterClass(): void
    {
        Config::set(self::$databaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$databasePath);
    }

    protected function setUp(): void
    {
        Db::name('user_hotel_permissions')->delete(true);
        Db::name('users')->delete(true);
        Db::name('roles')->delete(true);
        Db::name('hotels')->delete(true);
        Db::name(HotelAutopilotLifecycleService::HOTEL_TABLE)->delete(true);
        Db::name(HotelAutopilotLifecycleService::TENANT_TABLE)->delete(true);
    }

    public function testMigrationStoresOnlySafeLifecycleProjection(): void
    {
        $sql = (string)file_get_contents(
            dirname(__DIR__) . '/database/migrations/20260812_zzz_create_hotel_autopilot_lifecycle.sql'
        );
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `tenant_automation_lifecycles`', $sql);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `hotel_automation_lifecycles`', $sql);
        self::assertStringContainsString('UNIQUE KEY `uq_hotel_automation_lifecycle` (`tenant_id`, `system_hotel_id`)', $sql);
        self::assertStringNotContainsString('`cookie', strtolower($sql));
        self::assertStringNotContainsString('`password', strtolower($sql));
        self::assertStringNotContainsString('`profile_path', strtolower($sql));
        self::assertStringNotContainsString('`raw_data', strtolower($sql));
    }

    public function testHotelCreationInitializesExactAwaitingBindingStateIdempotently(): void
    {
        $service = $this->service($this->blockedBinding('ota_source_binding_missing'));

        $created = $service->initializeHotel($this->hotel(), 7);
        $reused = $service->initializeHotel($this->hotel(), 7);

        self::assertSame('awaiting_binding', $created['status']);
        self::assertSame('data_source_binding', $created['current_stage']);
        self::assertSame(1, $created['completed_stage_count']);
        self::assertSame(6, $created['total_stage_count']);
        self::assertSame('open_hotel_binding', $created['next_action_code']);
        self::assertTrue($created['readback_verified']);
        self::assertFalse($created['reused']);
        self::assertTrue($reused['reused']);
        self::assertSame(1, Db::name(HotelAutopilotLifecycleService::HOTEL_TABLE)->count());
        self::assertSame(1, Db::name(HotelAutopilotLifecycleService::TENANT_TABLE)->count());
    }

    public function testLoginEvidenceFailureNeverActivatesPlan(): void
    {
        $planSaveCount = 0;
        $service = $this->service(
            $this->blockedBinding('login_required'),
            static fn(): array => ['status' => 'missing'],
            static function () use (&$planSaveCount): array {
                $planSaveCount++;
                return [];
            }
        );

        $result = $service->reconcileHotel($this->hotel(), 7, true);

        self::assertSame('awaiting_login', $result['status']);
        self::assertSame('login_required', $result['failure_code']);
        self::assertSame('open_hotel_login', $result['next_action_code']);
        self::assertSame(0, $planSaveCount);
        self::assertFalse($result['boundaries']['auto_write_ota']);
    }

    public function testSignedActivePlanDesignatesExactSourcesBeforeBindingRead(): void
    {
        $designatedSources = null;
        $plan = self::readyPlan();
        $plan['status'] = 'blocked';
        $plan['execution_authorized'] = false;
        $plan['failure_reasons'] = [['code' => 'login_required']];
        $service = new HotelAutopilotLifecycleService(
            function (array $hotel, int $actorId, string $date, array $sources) use (&$designatedSources): array {
                $designatedSources = $sources;
                return $this->blockedBinding('login_required');
            },
            static fn(): array => $plan
        );

        $result = $service->reconcileHotel($this->hotel(), 7, false);

        self::assertSame(['ctrip' => 25, 'meituan' => 68], $designatedSources);
        self::assertSame('awaiting_login', $result['status']);
        self::assertSame('login_required', $result['failure_code']);
    }

    public function testReadyBindingCreatesOnePlanAndRequestsFirstRunOnlyOnce(): void
    {
        $activePlan = ['status' => 'missing'];
        $planSaveCount = 0;
        $dispatcherStarts = [];
        $service = $this->service(
            $this->readyBinding(),
            static function () use (&$activePlan): array {
                return $activePlan;
            },
            static function () use (&$activePlan, &$planSaveCount): array {
                $planSaveCount++;
                $activePlan = self::readyPlan();
                return $activePlan;
            },
            static function (array $scope) use (&$dispatcherStarts): array {
                $dispatcherStarts[] = (bool)$scope['start_now'];
                return [
                    'status' => 'ready',
                    'reason_code' => $scope['start_now'] ? 'task_enabled_and_started' : 'task_enabled',
                    'task_name' => 'SUXIOS OTA Dispatcher H80',
                    'task_started' => (bool)$scope['start_now'],
                ];
            },
            static fn(): array => ['status' => 'missing', 'failure_code' => 'hotel_collection_run_receipt_missing']
        );

        $first = $service->reconcileHotel($this->hotel(), 7, true);
        $second = $service->reconcileHotel($this->hotel(), 7, true);

        self::assertSame('scheduled_waiting_first_collection', $first['status']);
        self::assertSame('scheduled_waiting_first_collection', $second['status']);
        self::assertSame(3, $first['completed_stage_count']);
        self::assertSame(1, $planSaveCount);
        self::assertSame([true, false], $dispatcherStarts);
        self::assertSame(3, (int)Db::name(HotelAutopilotLifecycleService::HOTEL_TABLE)->value('state_version'));
        self::assertSame(1, Db::name(HotelAutopilotLifecycleService::HOTEL_TABLE)->count());
    }

    public function testFirstDispatchIsNotRepeatedWhenPostDispatchStateWriteConflicts(): void
    {
        $dispatcherStarts = [];
        $injectConflict = true;
        $service = $this->service(
            $this->readyBinding(),
            static fn(): array => self::readyPlan(),
            static fn(): array => self::readyPlan(),
            static function (array $scope) use (&$dispatcherStarts, &$injectConflict): array {
                $dispatcherStarts[] = (bool)$scope['start_now'];
                if ($scope['start_now'] && $injectConflict) {
                    $injectConflict = false;
                    Db::execute(
                        'UPDATE hotel_automation_lifecycles '
                        . 'SET state_version = state_version + 1 '
                        . 'WHERE tenant_id = 101 AND system_hotel_id = 80'
                    );
                }
                return [
                    'status' => 'ready',
                    'reason_code' => $scope['start_now'] ? 'task_enabled_and_started' : 'task_enabled',
                    'task_name' => 'SUXIOS OTA Dispatcher H80',
                    'task_started' => (bool)$scope['start_now'],
                ];
            },
            static fn(): array => ['status' => 'missing', 'failure_code' => 'hotel_collection_run_receipt_missing']
        );

        try {
            $service->reconcileHotel($this->hotel(), 7, true);
            self::fail('The injected state-version conflict must fail the first reconciliation.');
        } catch (RuntimeException $error) {
            self::assertSame('hotel_automation_lifecycle_concurrent_update', $error->getMessage());
        }

        $second = $service->reconcileHotel($this->hotel(), 7, true);

        self::assertSame([true, false], $dispatcherStarts);
        self::assertSame('scheduled_waiting_first_collection', $second['status']);
        self::assertNotEmpty(
            Db::name(HotelAutopilotLifecycleService::HOTEL_TABLE)->value('first_dispatch_requested_at')
        );
    }

    public function testLatestCollectionLoginFailureStopsBeforeQualityAndAnalysis(): void
    {
        $qualityCalls = 0;
        $service = $this->service(
            $this->readyBinding(),
            static fn(): array => self::readyPlan(),
            static fn(): array => self::readyPlan(),
            null,
            static fn(): array => [
                'status' => 'blocked',
                'dispatcher_run_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                'tenant_id' => 101,
                'system_hotel_id' => 80,
                'business_date' => '2026-08-11',
                'plan_id' => 9,
                'failure_code' => 'login_required',
                'readback_verified' => true,
            ],
            null,
            null,
            static function () use (&$qualityCalls): array {
                $qualityCalls++;
                return [];
            }
        );

        $result = $service->reconcileHotel($this->hotel(), 7, false);

        self::assertSame('awaiting_login', $result['status']);
        self::assertSame('login_required', $result['failure_code']);
        self::assertSame('first_trusted_collection', $result['current_stage']);
        self::assertSame(0, $qualityCalls);
    }

    public function testTrustedCollectionAdvancesAnalysisAndUnverifiedProfilePreview(): void
    {
        $service = $this->service(
            $this->readyBinding(),
            static fn(): array => self::readyPlan(),
            static fn(): array => self::readyPlan(),
            static fn(): array => [
                'status' => 'ready',
                'reason_code' => 'task_enabled',
                'task_name' => 'SUXIOS OTA Dispatcher H80',
                'task_started' => false,
            ],
            static fn(): array => self::trustedRun(),
            static fn(): array => [
                'persistence_status' => 'readback_verified',
                'reconciled_stages' => [
                    'identity_business_date_confirmed',
                    'trusted_collection',
                    'formal_save_exact_readback',
                    'operating_facts_established',
                ],
                'operating_loop' => ['record_id' => 801],
                'waiting' => [
                    'stage' => 'recommendation_human_decision',
                    'code' => 'formal_decision_missing',
                ],
            ],
            static fn(): array => [
                'preview_status' => 'partial',
                'preview_only' => true,
                'persistence_status' => 'not_persisted',
                'automatic_verification' => false,
                'preview_digest' => str_repeat('d', 64),
                'draft' => [
                    'hotel_id' => 80,
                    'quality_status' => 'unverified',
                ],
                'summary' => [
                    'filled_dimension_count' => 6,
                    'missing_dimension_count' => 7,
                    'confirmation_gap_count' => 4,
                    'active_binding_count' => 2,
                    'verified_fact_count' => 2,
                    'verified_business_date_end' => '2026-08-11',
                ],
            ]
        );

        $result = $service->reconcileHotel($this->hotel(), 7, false);

        self::assertSame('continuous_running', $result['status']);
        self::assertSame(6, $result['completed_stage_count']);
        self::assertSame('readback_verified', $result['analysis_status']);
        self::assertSame('partial', $result['profile_draft_status']);
        self::assertSame('pending_human_approval', $result['approval_task_status']);
        self::assertSame('available', $result['data_quality_status']);
        self::assertSame(701, $result['approval_intent_id']);
        self::assertTrue($result['approval_readback_verified']);
        self::assertSame('2026-08-11', $result['last_business_date']);
        self::assertFalse($result['boundaries']['profile_verified']);
        self::assertFalse($result['boundaries']['business_outcome_claimed']);
        self::assertFalse($result['boundaries']['external_action_triggered']);
    }

    public function testPartialQualityReadbackBlocksAnalysisAndApproval(): void
    {
        $analysisCalls = 0;
        $profileCalls = 0;
        $approvalCalls = 0;
        $service = $this->service(
            $this->readyBinding(),
            static fn(): array => self::readyPlan(),
            static fn(): array => self::readyPlan(),
            null,
            static fn(): array => self::trustedRun(),
            static function () use (&$analysisCalls): array {
                $analysisCalls++;
                return [];
            },
            static function () use (&$profileCalls): array {
                $profileCalls++;
                return [];
            },
            static fn(string $runId, int $tenantId, int $hotelId, string $date): array => [
                'tenant_id' => $tenantId,
                'system_hotel_id' => $hotelId,
                'business_date' => $date,
                'dispatcher_run_id' => $runId,
                'evidence_scope' => [
                    'ota_metric_scope' => 'ota_channel',
                    'pms_metric_scope' => 'whole_hotel_accommodation',
                ],
                'conclusion' => [
                    'status' => 'partial',
                    'claim_allowed' => false,
                    'whole_hotel_conclusion_allowed' => false,
                    'business_outcome_claimed' => false,
                ],
                'judgment_digest' => str_repeat('9', 64),
                'readback_verified' => true,
            ],
            static function () use (&$approvalCalls): array {
                $approvalCalls++;
                return [];
            }
        );

        $result = $service->reconcileHotel($this->hotel(), 7, false);

        self::assertSame('awaiting_analysis', $result['status']);
        self::assertSame('partial', $result['data_quality_status']);
        self::assertSame('hotel_lifecycle_data_quality_partial', $result['failure_code']);
        self::assertSame(0, $analysisCalls);
        self::assertSame(0, $profileCalls);
        self::assertSame(0, $approvalCalls);
    }

    public function testApprovalMustHaveExactPendingReadbackBeforeContinuousRunning(): void
    {
        $profileCalls = 0;
        $service = $this->service(
            $this->readyBinding(),
            static fn(): array => self::readyPlan(),
            static fn(): array => self::readyPlan(),
            null,
            static fn(): array => self::trustedRun(),
            static fn(): array => [
                'persistence_status' => 'readback_verified',
                'operating_loop' => ['record_id' => 803],
                'waiting' => [
                    'stage' => 'recommendation_human_decision',
                    'code' => 'formal_decision_missing',
                ],
            ],
            static function () use (&$profileCalls): array {
                $profileCalls++;
                return [];
            },
            null,
            static fn(): array => [
                'status' => 'pending_approval',
                'persistence_status' => 'not_verified',
                'execution_task_created' => false,
                'external_action_triggered' => false,
            ]
        );

        $result = $service->reconcileHotel($this->hotel(), 7, false);

        self::assertSame('awaiting_analysis', $result['status']);
        self::assertSame('readback_failed', $result['approval_task_status']);
        self::assertFalse($result['approval_readback_verified']);
        self::assertSame('hotel_lifecycle_approval_intent_readback_failed', $result['failure_code']);
        self::assertSame(0, $profileCalls);
    }

    public function testUnavailableProfilePreviewRemainsIncompleteAndRequestsBusinessData(): void
    {
        $service = $this->service(
            $this->readyBinding(),
            static fn(): array => self::readyPlan(),
            static fn(): array => self::readyPlan(),
            null,
            static fn(): array => self::trustedRun(),
            static fn(): array => [
                'persistence_status' => 'readback_verified',
                'operating_loop' => ['record_id' => 802],
                'waiting' => [
                    'stage' => 'recommendation_human_decision',
                    'code' => 'formal_decision_missing',
                ],
            ],
            static fn(): array => [
                'preview_status' => 'unavailable',
                'preview_only' => true,
                'persistence_status' => 'not_persisted',
                'automatic_verification' => false,
                'preview_digest' => str_repeat('e', 64),
                'draft' => [
                    'hotel_id' => 80,
                    'quality_status' => 'unverified',
                ],
                'summary' => [
                    'filled_dimension_count' => 0,
                    'missing_dimension_count' => 8,
                ],
            ]
        );

        $result = $service->reconcileHotel($this->hotel(), 7, false);

        self::assertSame('awaiting_profile', $result['status']);
        self::assertSame('profile_draft', $result['current_stage']);
        self::assertSame(5, $result['completed_stage_count']);
        self::assertSame('unavailable', $result['profile_draft_status']);
        self::assertSame('hotel_lifecycle_profile_draft_unavailable', $result['failure_code']);
        self::assertSame('provide_business_profile', $result['next_action_code']);
        self::assertFalse($result['boundaries']['profile_verified']);
    }

    public function testReconcileDueReturnsAProgressingBoundedCursor(): void
    {
        Db::name('hotels')->insertAll([
            ['id' => 80, 'tenant_id' => 101, 'name' => 'A', 'status' => 1, 'ota_channel_strategy' => 'dual', 'owner_user_id' => 7, 'created_by' => 7],
            ['id' => 81, 'tenant_id' => 101, 'name' => 'B', 'status' => 1, 'ota_channel_strategy' => 'dual', 'owner_user_id' => 7, 'created_by' => 7],
            ['id' => 82, 'tenant_id' => 101, 'name' => 'C', 'status' => 1, 'ota_channel_strategy' => 'dual', 'owner_user_id' => 7, 'created_by' => 7],
        ]);
        $service = $this->service($this->blockedBinding('ota_source_binding_missing'));

        $first = $service->reconcileDue(2, false, 0);
        $second = $service->reconcileDue(2, false, (int)$first['next_after_hotel_id']);

        self::assertSame(2, $first['scanned_hotel_count']);
        self::assertSame(2, $first['hotel_count']);
        self::assertSame(81, $first['next_after_hotel_id']);
        self::assertSame(1, $second['scanned_hotel_count']);
        self::assertSame(1, $second['hotel_count']);
        self::assertSame(82, $second['next_after_hotel_id']);
    }

    public function testLegacyHotelUsesOnlyOneExactUnexpiredCollectionPermissionAsCoordinator(): void
    {
        Db::name('hotels')->insert([
            'id' => 90,
            'tenant_id' => 303,
            'name' => 'Legacy hotel',
            'status' => 1,
            'ota_channel_strategy' => 'dual',
            'owner_user_id' => null,
            'created_by' => null,
        ]);
        Db::name('users')->insertAll([
            ['id' => 30, 'tenant_id' => 303, 'role_id' => 2, 'status' => 1],
            ['id' => 31, 'tenant_id' => 404, 'role_id' => 1, 'status' => 1],
            ['id' => 32, 'tenant_id' => 303, 'role_id' => 2, 'status' => 1],
        ]);
        Db::name('user_hotel_permissions')->insertAll([
            [
                'tenant_id' => 303,
                'user_id' => 30,
                'hotel_id' => 90,
                'status' => 'active',
                'can_view' => 1,
                'can_fetch_online_data' => 1,
                'can_fetch_ota' => 1,
                'expires_at' => null,
            ],
            [
                'tenant_id' => 303,
                'user_id' => 31,
                'hotel_id' => 90,
                'status' => 'active',
                'can_view' => 1,
                'can_fetch_online_data' => 1,
                'can_fetch_ota' => 1,
                'expires_at' => null,
            ],
        ]);
        $receivedActor = 0;
        $service = new HotelAutopilotLifecycleService(
            function (array $hotel, int $actorId) use (&$receivedActor): array {
                $receivedActor = $actorId;
                return $this->blockedBinding('ota_source_binding_missing');
            }
        );

        $result = $service->reconcileDue(10, false, 89);

        self::assertSame(30, $receivedActor);
        self::assertSame('awaiting_binding', $result['results'][0]['status']);
        self::assertSame('ota_source_binding_missing', $result['results'][0]['failure_code']);
    }

    public function testBatchReadNeverLeaksAnotherTenantState(): void
    {
        $service = $this->service($this->blockedBinding('ota_source_binding_missing'));
        $service->initializeHotel($this->hotel(80, 101), 7);
        $service->initializeHotel($this->hotel(81, 202), 8);

        $items = $service->readForHotels(101, [80, 81]);

        self::assertSame(101, $items[0]['tenant_id']);
        self::assertSame(80, $items[0]['hotel_id']);
        self::assertSame(101, $items[1]['tenant_id']);
        self::assertSame(81, $items[1]['hotel_id']);
        self::assertSame('hotel_automation_lifecycle_missing', $items[1]['failure_code']);
        self::assertFalse($items[1]['readback_verified']);
        self::assertStringNotContainsString('202', json_encode($items[1], JSON_THROW_ON_ERROR));
    }

    public function testDispatcherBridgeRequiresExactSafeReadback(): void
    {
        $runner = static fn(array $command): array => [
            'exit_code' => 0,
            'stdout' => '',
            'schema_version' => HotelAutopilotDispatcherProvisioningService::SCHEMA_VERSION,
            'status' => 'ready',
            'reason_code' => 'task_enabled_and_started',
            'hotel_id' => 80,
            'task_name' => 'SUXIOS OTA Dispatcher H80',
            'task_exists' => true,
            'enabled' => true,
            'task_started' => true,
            'scope' => [
                'hotel_id' => 80,
                'source_ids' => [25, 68],
                'platforms' => ['ctrip', 'meituan'],
                'mode' => 'daily',
            ],
            'scope_verified' => true,
            'action_verified' => true,
            'trigger_verified' => true,
            'principal_verified' => true,
            'readback_verified' => true,
            'sensitive_values_exposed' => false,
            'process_exit_code' => 0,
        ];
        $service = new HotelAutopilotDispatcherProvisioningService($runner, dirname(__DIR__));

        $receipt = $service->provision([
            'hotel_id' => 80,
            'source_ids' => [25, 68],
            'platforms' => ['ctrip', 'meituan'],
            'schedule_time' => '08:30',
            'start_now' => true,
        ]);

        self::assertSame('ready', $receipt['status']);
        self::assertTrue($receipt['readback_verified']);
        self::assertTrue($receipt['task_started']);
        self::assertFalse($receipt['auto_write_ota']);
        self::assertFalse($receipt['sensitive_values_exposed']);
    }

    /**
     * @param array<string,mixed> $binding
     * @param callable|null $planReader
     * @param callable|null $planSaver
     * @param callable|null $dispatcher
     * @param callable|null $runLoader
     * @param callable|null $loop
     * @param callable|null $profile
     * @param callable|null $quality
     * @param callable|null $approval
     */
    private function service(
        array $binding,
        ?callable $planReader = null,
        ?callable $planSaver = null,
        ?callable $dispatcher = null,
        ?callable $runLoader = null,
        ?callable $loop = null,
        ?callable $profile = null,
        ?callable $quality = null,
        ?callable $approval = null
    ): HotelAutopilotLifecycleService {
        return new HotelAutopilotLifecycleService(
            static fn(): array => $binding,
            $planReader ?? static fn(): array => ['status' => 'missing'],
            $planSaver ?? static fn(): array => self::readyPlan(),
            $dispatcher ?? static fn(): array => [
                'status' => 'ready',
                'reason_code' => 'task_enabled',
                'task_name' => 'SUXIOS OTA Dispatcher H80',
                'task_started' => false,
            ],
            $runLoader ?? static fn(): array => ['status' => 'missing'],
            $loop ?? static fn(): array => [
                'persistence_status' => 'not_written',
                'reconciled_stages' => [],
                'waiting' => ['stage' => 'trusted_collection', 'code' => 'missing'],
            ],
            $profile ?? static fn(): array => [
                'preview_status' => 'unavailable',
                'preview_only' => true,
                'automatic_verification' => false,
                'preview_digest' => str_repeat('e', 64),
                'summary' => [],
            ],
            static fn(): array => [
                'readback_verified' => true,
                'analysis_only' => true,
                'external_action_allowed' => false,
                'authorization_digest' => str_repeat('a', 64),
            ],
            static fn(): DateTimeImmutable => new DateTimeImmutable('2026-08-12 10:00:00', new DateTimeZone('Asia/Shanghai')),
            $quality ?? static fn(string $runId, int $tenantId, int $hotelId, string $date): array => [
                'tenant_id' => $tenantId,
                'system_hotel_id' => $hotelId,
                'business_date' => $date,
                'dispatcher_run_id' => $runId,
                'collection_run_receipt_id' => 501,
                'evidence_scope' => [
                    'ota_metric_scope' => 'ota_channel',
                    'pms_metric_scope' => 'whole_hotel_accommodation',
                ],
                'conclusion' => [
                    'status' => 'available',
                    'claim_allowed' => true,
                    'whole_hotel_conclusion_allowed' => false,
                    'business_outcome_claimed' => false,
                ],
                'judgment_digest' => str_repeat('f', 64),
                'readback_verified' => true,
                'persistence' => ['judgment_id' => 601],
            ],
            $approval ?? static fn(int $tenantId, int $hotelId, string $date): array => [
                'status' => 'pending_approval',
                'persistence_status' => 'readback_verified',
                'execution_task_created' => false,
                'external_action_triggered' => false,
                'execution_intent' => [
                    'id' => 701,
                    'tenant_id' => $tenantId,
                    'hotel_id' => $hotelId,
                    'date_start' => $date,
                    'date_end' => $date,
                    'status' => 'pending_approval',
                    'tasks' => [],
                ],
            ]
        );
    }

    /** @return array<string,mixed> */
    private function hotel(int $id = 80, int $tenantId = 101): array
    {
        return [
            'id' => $id,
            'tenant_id' => $tenantId,
            'name' => 'Lifecycle hotel',
            'status' => 1,
            'ota_channel_strategy' => 'dual',
            'owner_user_id' => 7,
            'created_by' => 7,
        ];
    }

    /** @return array<string,mixed> */
    private function blockedBinding(string $code): array
    {
        return [
            'status' => 'blocked',
            'binding_ready' => false,
            'binding_digest' => str_repeat('b', 64),
            'execution_owner_user_id' => null,
            'bindings' => [],
            'blockers' => [['code' => $code]],
            'recovery_reasons' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function readyBinding(): array
    {
        return [
            'status' => 'ready',
            'binding_ready' => true,
            'binding_digest' => str_repeat('b', 64),
            'execution_owner_user_id' => 7,
            'bindings' => [
                'ctrip' => ['source_id' => 25],
                'meituan' => ['source_id' => 68],
                'pms' => ['provider' => 'dingdandao_pms'],
            ],
            'blockers' => [],
            'recovery_reasons' => [],
        ];
    }

    /** @return array<string,mixed> */
    private static function readyPlan(): array
    {
        return [
            'status' => 'active_ready',
            'id' => 9,
            'plan_status' => 'active',
            'enabled' => true,
            'active_slot' => true,
            'business_date_policy' => 'previous_business_day',
            'timezone' => 'Asia/Shanghai',
            'schedule_time' => '08:30',
            'retry_interval_minutes' => 14,
            'max_attempts' => 7,
            'sources' => [
                'ctrip' => ['data_source_id' => 25],
                'meituan' => ['data_source_id' => 68],
                'pms' => ['provider' => 'dingdandao_pms'],
            ],
            'binding_digest' => str_repeat('b', 64),
            'plan_hash' => str_repeat('c', 64),
            'readback_verified' => true,
            'binding_digest_matches' => true,
            'execution_authorized' => true,
            'failure_reasons' => [],
        ];
    }

    /** @return array<string,mixed> */
    private static function trustedRun(): array
    {
        return [
            'id' => 501,
            'status' => 'succeeded',
            'dispatcher_run_id' => '11111111-1111-4111-8111-111111111111',
            'tenant_id' => 101,
            'system_hotel_id' => 80,
            'business_date' => '2026-08-11',
            'plan_id' => 9,
            'collection_anchor_hash' => str_repeat('1', 64),
            'trust_receipt_digest' => str_repeat('2', 64),
            'ledger_structure_verified' => true,
            'readback_verified' => true,
            'finished_at' => '2026-08-12 08:50:00',
            'pms_receipt' => [
                'status' => 'verified',
                'readback_verified' => true,
            ],
            'source_receipts' => [
                [
                    'platform' => 'ctrip',
                    'status' => 'success',
                    'saved_row_count' => 2,
                    'readback_row_count' => 2,
                    'readback_verified' => true,
                ],
                [
                    'platform' => 'meituan',
                    'status' => 'success',
                    'saved_row_count' => 3,
                    'readback_row_count' => 3,
                    'readback_verified' => true,
                ],
            ],
        ];
    }

    private static function createSchema(): void
    {
        Db::execute('CREATE TABLE hotels (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            status INTEGER NOT NULL,
            ota_channel_strategy TEXT NOT NULL,
            owner_user_id INTEGER NULL,
            created_by INTEGER NULL
        )');
        Db::execute('CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            role_id INTEGER NOT NULL,
            status INTEGER NOT NULL
        )');
        Db::execute('CREATE TABLE roles (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            level INTEGER NOT NULL,
            permissions TEXT NOT NULL,
            status INTEGER NOT NULL
        )');
        Db::name('roles')->insert([
            'id' => 2,
            'name' => 'beta_user',
            'level' => 2,
            'permissions' => '["all"]',
            'status' => 1,
        ]);
        Db::execute('CREATE TABLE user_hotel_permissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            hotel_id INTEGER NOT NULL,
            status TEXT NOT NULL,
            can_view INTEGER NOT NULL,
            can_fetch_online_data INTEGER NOT NULL,
            can_fetch_ota INTEGER NOT NULL,
            expires_at TEXT NULL
        )');
        Db::execute('CREATE TABLE tenant_automation_lifecycles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL UNIQUE,
            status TEXT NOT NULL,
            current_stage TEXT NOT NULL,
            state_version INTEGER NOT NULL,
            state_digest TEXT NOT NULL,
            safe_state_json TEXT NOT NULL,
            created_by INTEGER NOT NULL,
            updated_by INTEGER NOT NULL,
            create_time TEXT NOT NULL,
            update_time TEXT NOT NULL
        )');
        Db::execute('CREATE TABLE hotel_automation_lifecycles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            system_hotel_id INTEGER NOT NULL,
            status TEXT NOT NULL,
            current_stage TEXT NOT NULL,
            ota_channel_strategy TEXT NOT NULL,
            completed_stage_count INTEGER NOT NULL,
            total_stage_count INTEGER NOT NULL,
            binding_status TEXT NOT NULL,
            binding_digest TEXT NULL,
            active_plan_id INTEGER NULL,
            active_plan_hash TEXT NULL,
            dispatcher_status TEXT NOT NULL,
            dispatcher_task_name TEXT NULL,
            first_dispatch_requested_at TEXT NULL,
            first_trusted_business_date TEXT NULL,
            last_business_date TEXT NULL,
            last_dispatcher_run_id TEXT NULL,
            last_run_status TEXT NULL,
            analysis_status TEXT NOT NULL,
            analysis_digest TEXT NULL,
            profile_draft_status TEXT NOT NULL,
            profile_draft_digest TEXT NULL,
            failure_code TEXT NULL,
            upstream_failure_code TEXT NULL,
            retryable INTEGER NOT NULL,
            attempt_count INTEGER NOT NULL,
            next_retry_at TEXT NULL,
            state_version INTEGER NOT NULL,
            state_digest TEXT NOT NULL,
            safe_state_json TEXT NOT NULL,
            created_by INTEGER NOT NULL,
            updated_by INTEGER NOT NULL,
            create_time TEXT NOT NULL,
            update_time TEXT NOT NULL,
            UNIQUE (tenant_id, system_hotel_id)
        )');
    }
}
