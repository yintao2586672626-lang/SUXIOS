<?php
declare(strict_types=1);

namespace tests;

use app\model\Role;
use app\service\CloudBrowserProfileService;
use app\service\CloudCollectionDispatchService;
use app\service\DingdandaoCloudCollectionService;
use app\service\DingdandaoOperatingTargetCaptureService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class DingdandaoCloudCollectionServiceTest extends TestCase
{
    private const PROFILE_ID = 'cbp_dingdandao_profile_123456';
    private const SESSION_ID = 'cbcs_collection_session_123456';

    private static array $databaseConfig;
    private static array $aliasRegistryConfig;
    private static string $databasePath;

    public static function setUpBeforeClass(): void
    {
        $app = new App(dirname(__DIR__));
        $app->initialize();
        self::$databaseConfig = Config::get('database');
        self::$aliasRegistryConfig = Config::get('dingdandao_hotel_alias_registry', []);
        self::$databasePath = sys_get_temp_dir()
            . '/dingdandao_cloud_collection_' . getmypid() . '.sqlite';
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
        Config::set(self::$aliasRegistryConfig, 'dingdandao_hotel_alias_registry');
        Db::connect(null, true);
        @unlink(self::$databasePath);
    }

    protected function setUp(): void
    {
        Config::set([
            'schema_version' => 'suxios_hotel_provider_alias_registry.v1',
            'version' => 'fixture.1',
            'aliases' => [[
                'tenant_id' => 1,
                'hotel_id' => 5,
                'system_name' => '敦煌漠蓝新',
                'provider' => 'dingdandao',
                'provider_name' => '敦煌漠蓝',
                'status' => 'user_confirmed',
                'confirmed_date' => '2026-07-27',
                'source_reference' => 'user_explicit_confirmation',
            ]],
        ], 'dingdandao_hotel_alias_registry');

        foreach ([
            'dingdandao_room_fee_capture_details',
            'dingdandao_operating_target_captures',
            'cloud_collection_tasks',
            'cloud_browser_profiles',
            'user_hotel_permissions',
            'users',
            'hotels',
            'system_configs',
            'operation_logs',
        ] as $table) {
            Db::name($table)->delete(true);
        }

        Db::name('hotels')->insert([
            'id' => 5,
            'tenant_id' => 1,
            'name' => '敦煌漠蓝新',
            'status' => 1,
            'owner_user_id' => 0,
            'created_by' => 0,
        ]);
        Db::name('users')->insert([
            'id' => 7,
            'tenant_id' => 1,
            'hotel_id' => null,
            'role_id' => 2,
            'status' => 1,
        ]);
        Db::name('user_hotel_permissions')->insert([
            'tenant_id' => 1,
            'user_id' => 7,
            'hotel_id' => 5,
            'status' => 'active',
            'expires_at' => null,
            'can_fetch_ota' => 1,
            'can_fetch_online_data' => 1,
        ]);
        Db::name('cloud_browser_profiles')->insert([
            'tenant_id' => 1,
            'system_hotel_id' => 5,
            'owner_user_id' => 7,
            'platform' => 'dingdandao',
            'profile_public_id' => self::PROFILE_ID,
            'authorization_status' => CloudBrowserProfileService::READY_TO_COLLECT,
            'ready_at' => '2026-07-27 09:00:00',
            'session_expires_at' => '2026-07-27 11:00:00',
        ]);
        $this->seedBinding();
    }

    public function testClaimIsAtomicIdempotentAndBoundToOneWindow(): void
    {
        $service = $this->service();
        $first = $this->claim($service);
        $second = $this->claim($service);

        self::assertTrue($first['claimed']);
        self::assertSame('recorded', $first['claim_status']);
        self::assertSame('reused', $second['claim_status']);
        self::assertSame($first['claim_id'], $second['claim_id']);
        self::assertSame('today_only', $first['source_scope']);
        self::assertSame('read_only', $first['access_mode']);
        self::assertSame('敦煌漠蓝', $first['provider_hotel_name']);
        self::assertSame('unverified', $first['data_status']);
        self::assertSame(1, (int)Db::name('cloud_collection_tasks')->count());
        self::assertSame(
            'collecting',
            Db::name('cloud_collection_tasks')->where('task_public_id', $first['claim_id'])
                ->value('task_status')
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dingdandao_collection_claim_conflict');
        $service->claim(
            self::PROFILE_ID,
            self::SESSION_ID,
            1,
            5,
            7,
            '2026-07-27',
            'operating_target_today',
            'read_only',
            '2026-07-27 10:11:00'
        );
    }

    public function testClaimPreservesTheGatewayIsoWindowForExactContractValidation(): void
    {
        $claim = $this->service()->claim(
            self::PROFILE_ID,
            self::SESSION_ID,
            1,
            5,
            7,
            '2026-07-27',
            'operating_target_today',
            'read_only',
            '2026-07-27T02:10:00.000Z'
        );

        self::assertSame('2026-07-27T02:10:00.000Z', $claim['window_expires_at']);
    }

    public function testDifferentSessionCannotClaimTheSameActiveProfileWindow(): void
    {
        $service = $this->service();
        $this->claim($service);

        try {
            $service->claim(
                self::PROFILE_ID,
                'cbcs_other_collection_session_123456',
                1,
                5,
                7,
                '2026-07-27',
                'operating_target_today',
                'read_only',
                '2026-07-27 10:10:00'
            );
            self::fail('one profile window must have only one active claim');
        } catch (RuntimeException $error) {
            self::assertSame(
                'dingdandao_collection_claim_already_active',
                $error->getMessage()
            );
        }
        self::assertSame(1, (int)Db::name('cloud_collection_tasks')->count());
    }

    public function testExpiredClaimCannotStartOrCompleteTrustedCollection(): void
    {
        $claim = $this->claim($this->service());
        $expiredService = new DingdandaoCloudCollectionService(
            static fn(): DateTimeImmutable => new DateTimeImmutable('2026-07-27 10:10:01')
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dingdandao_collection_window_invalid');
        $expiredService->trustedCollectorScope(
            $claim['claim_id'],
            self::SESSION_ID,
            self::PROFILE_ID
        );
    }

    public function testExpiredOrphanClaimIsClosedAndDoesNotBlockAReplacementWindow(): void
    {
        $first = $this->claim($this->service());
        $replacementService = new DingdandaoCloudCollectionService(
            static fn(): DateTimeImmutable => new DateTimeImmutable('2026-07-27 10:10:01')
        );
        $replacement = $replacementService->claim(
            self::PROFILE_ID,
            'cbcs_recovered_collection_123456',
            1,
            5,
            7,
            '2026-07-27',
            'operating_target_today',
            'read_only',
            '2026-07-27 10:20:00'
        );

        self::assertSame('recorded', $replacement['claim_status']);
        self::assertNotSame($first['claim_id'], $replacement['claim_id']);
        $orphan = Db::name('cloud_collection_tasks')
            ->where('task_public_id', $first['claim_id'])
            ->find();
        self::assertSame('closed_unverified', $orphan['task_status']);
        self::assertSame(0, (int)$orphan['formal_message_allowed']);
        self::assertSame(
            ['collection_window_orphan_expired'],
            json_decode((string)$orphan['gap_codes_json'], true)
        );
    }

    public function testExpiredProfileTtlIsPersistedBeforeClaimFails(): void
    {
        Db::name('cloud_browser_profiles')
            ->where('profile_public_id', self::PROFILE_ID)
            ->update(['session_expires_at' => '2026-07-27 09:59:59']);

        try {
            $this->claim($this->service());
            self::fail('an expired profile TTL must block collection');
        } catch (RuntimeException $error) {
            self::assertSame(
                'dingdandao_collection_profile_session_expired',
                $error->getMessage()
            );
        }
        self::assertSame(
            CloudBrowserProfileService::SESSION_EXPIRED,
            Db::name('cloud_browser_profiles')
                ->where('profile_public_id', self::PROFILE_ID)
                ->value('authorization_status')
        );
        self::assertSame(
            'dingdandao_session_expired',
            Db::name('cloud_browser_profiles')
                ->where('profile_public_id', self::PROFILE_ID)
                ->value('status_reason')
        );
        self::assertSame(0, (int)Db::name('cloud_collection_tasks')->count());
    }

    public function testClaimFailsClosedForMissingBindingAndRevokedPermission(): void
    {
        $service = $this->service();
        Db::name('system_configs')->delete(true);
        try {
            $this->claim($service);
            self::fail('missing server binding must block the claim');
        } catch (RuntimeException $error) {
            self::assertSame('dingdandao_collection_binding_missing', $error->getMessage());
        }

        $this->seedBinding();
        Db::name('user_hotel_permissions')->where('user_id', 7)->update([
            'status' => 'disabled',
            'can_fetch_ota' => 0,
            'can_fetch_online_data' => 0,
        ]);
        try {
            $this->claim($service);
            self::fail('revoked collection permission must block the claim');
        } catch (RuntimeException $error) {
            self::assertSame('dingdandao_collection_permission_denied', $error->getMessage());
        }
        self::assertSame(0, (int)Db::name('cloud_collection_tasks')->count());
    }

    public function testBindingBootstrapScopeWorksWithoutABindingAndDoesNotWrite(): void
    {
        Db::name('system_configs')->delete(true);
        $scope = $this->service()->bindingBootstrapScope(
            self::PROFILE_ID,
            1,
            5,
            7
        );

        self::assertSame('ready_for_identity_probe', $scope['status']);
        self::assertSame('敦煌漠蓝', $scope['expected_provider_hotel_name']);
        self::assertFalse($scope['binding_persisted']);
        self::assertSame(0, (int)Db::name('system_configs')->count());
        self::assertSame(0, (int)Db::name('operation_logs')->count());
        self::assertSame(
            CloudBrowserProfileService::READY_TO_COLLECT,
            Db::name('cloud_browser_profiles')
                ->where('profile_public_id', self::PROFILE_ID)
                ->value('authorization_status')
        );

        Db::name('cloud_browser_profiles')
            ->where('profile_public_id', self::PROFILE_ID)
            ->update(['session_expires_at' => '2026-07-27 10:03:00']);
        try {
            $this->service()->bindingBootstrapScope(
                self::PROFILE_ID,
                1,
                5,
                7
            );
            self::fail('an expired bootstrap scope must fail without mutating the profile');
        } catch (RuntimeException $error) {
            self::assertSame(
                'dingdandao_collection_profile_session_expired',
                $error->getMessage()
            );
        }
        self::assertSame(
            CloudBrowserProfileService::READY_TO_COLLECT,
            Db::name('cloud_browser_profiles')
                ->where('profile_public_id', self::PROFILE_ID)
                ->value('authorization_status')
        );
        self::assertSame(0, (int)Db::name('system_configs')->count());
    }

    public function testGlobalSuperAdminWithoutTenantCanBootstrapAndClaimExactHotel(): void
    {
        Db::name('users')->where('id', 7)->update([
            'tenant_id' => null,
            'role_id' => Role::SUPER_ADMIN,
        ]);
        Db::name('user_hotel_permissions')->where('user_id', 7)->delete();

        $scope = $this->service()->bindingBootstrapScope(
            self::PROFILE_ID,
            1,
            5,
            7
        );
        $claim = $this->claim($this->service());

        self::assertSame('ready_for_identity_probe', $scope['status']);
        self::assertSame(1, $scope['tenant_id']);
        self::assertSame(5, $scope['hotel_id']);
        self::assertSame(7, $scope['owner_user_id']);
        self::assertTrue($claim['claimed']);
        self::assertSame(1, $claim['tenant_id']);
        self::assertSame(5, $claim['hotel_id']);
        self::assertSame(7, $claim['owner_user_id']);
        self::assertSame(1, (int)Db::name('cloud_collection_tasks')->count());
    }

    public function testNonAdminTenantMismatchCannotBootstrapOrClaimDespiteHotelPermission(): void
    {
        foreach ([null, 2] as $userTenantId) {
            Db::name('users')->where('id', 7)->update([
                'tenant_id' => $userTenantId,
                'role_id' => 2,
            ]);
            foreach ([
                fn() => $this->service()->bindingBootstrapScope(
                    self::PROFILE_ID,
                    1,
                    5,
                    7
                ),
                fn() => $this->claim($this->service()),
            ] as $operation) {
                try {
                    $operation();
                    self::fail('a non-admin outside the hotel tenant must be rejected');
                } catch (RuntimeException $error) {
                    self::assertSame(
                        'dingdandao_collection_user_scope_invalid',
                        $error->getMessage()
                    );
                }
            }
        }
        self::assertSame(0, (int)Db::name('cloud_collection_tasks')->count());
    }

    public function testDisabledGlobalSuperAdminCannotBootstrap(): void
    {
        Db::name('users')->where('id', 7)->update([
            'tenant_id' => null,
            'role_id' => Role::SUPER_ADMIN,
            'status' => 0,
        ]);

        try {
            $this->service()->bindingBootstrapScope(
                self::PROFILE_ID,
                1,
                5,
                7
            );
            self::fail('a disabled global super-admin must be rejected');
        } catch (RuntimeException $error) {
            self::assertSame(
                'dingdandao_collection_user_scope_invalid',
                $error->getMessage()
            );
        }
        self::assertSame(0, (int)Db::name('cloud_collection_tasks')->count());
    }

    public function testVerifiedIdentityCreatesAuditsAndReusesOneBinding(): void
    {
        Db::name('system_configs')->delete(true);
        $service = $this->service();
        $first = $service->registerVerifiedBinding(
            self::PROFILE_ID,
            1,
            5,
            7,
            $this->validBindingIdentity(),
            'BIND DINGDANDAO HOTEL 5'
        );

        self::assertSame('bound', $first['status']);
        self::assertTrue($first['binding_persisted']);
        self::assertSame('readback_verified', $first['readback_status']);
        self::assertSame('readback_verified', $first['post_commit_readback_status']);
        self::assertGreaterThan(0, $first['audit_id']);
        self::assertArrayNotHasKey('provider_hotel_id', $first);
        self::assertStringNotContainsString(
            'provider-bootstrap-hotel-5',
            json_encode($first, JSON_UNESCAPED_SLASHES)
        );

        $stored = json_decode(
            (string)Db::name('system_configs')
                ->where('config_key', 'dingdandao_hotel_bindings')
                ->value('config_value'),
            true
        );
        self::assertCount(1, $stored['bindings']);
        self::assertSame(
            'provider-bootstrap-hotel-5',
            $stored['bindings'][0]['provider_hotel_id']
        );
        self::assertSame(
            'verified_live_identity_probe',
            $stored['bindings'][0]['source_reference']
        );
        self::assertSame(
            0,
            (int)Db::name('dingdandao_operating_target_captures')->count()
        );
        $audit = Db::name('operation_logs')
            ->where('action', 'bootstrap_dingdandao_binding')
            ->find();
        self::assertIsArray($audit);
        self::assertStringNotContainsString(
            'provider-bootstrap-hotel-5',
            json_encode($audit, JSON_UNESCAPED_SLASHES)
        );

        $claim = $this->claim($service);
        self::assertTrue($claim['claimed']);
        $second = $service->registerVerifiedBinding(
            self::PROFILE_ID,
            1,
            5,
            7,
            $this->validBindingIdentity(),
            'BIND DINGDANDAO HOTEL 5'
        );
        self::assertSame('reused', $second['status']);
        $storedAgain = json_decode(
            (string)Db::name('system_configs')
                ->where('config_key', 'dingdandao_hotel_bindings')
                ->value('config_value'),
            true
        );
        self::assertCount(1, $storedAgain['bindings']);
        self::assertSame(
            2,
            (int)Db::name('operation_logs')
                ->where('action', 'bootstrap_dingdandao_binding')
                ->count()
        );
    }

    public function testBindingBootstrapRejectsUntrustedIdentityWithoutWriting(): void
    {
        Db::name('system_configs')->delete(true);
        $service = $this->service();

        foreach ([
            [
                'identity' => $this->validBindingIdentity(),
                'confirmation' => 'BIND DINGDANDAO HOTEL 6',
                'reason' => 'dingdandao_binding_confirmation_required',
            ],
            [
                'identity' => array_replace(
                    $this->validBindingIdentity(),
                    ['provider_hotel_name' => '敦煌漠蓝新']
                ),
                'confirmation' => 'BIND DINGDANDAO HOTEL 5',
                'reason' => 'dingdandao_binding_identity_mismatch',
            ],
            [
                'identity' => array_replace(
                    $this->validBindingIdentity(),
                    ['captured_at' => '2026-07-27T01:50:00.000Z']
                ),
                'confirmation' => 'BIND DINGDANDAO HOTEL 5',
                'reason' => 'dingdandao_binding_identity_invalid',
            ],
            [
                'identity' => $this->validBindingIdentity() + [
                    'cookie' => 'must-not-be-accepted',
                ],
                'confirmation' => 'BIND DINGDANDAO HOTEL 5',
                'reason' => 'dingdandao_capture_sensitive_material_rejected',
            ],
        ] as $case) {
            try {
                $service->registerVerifiedBinding(
                    self::PROFILE_ID,
                    1,
                    5,
                    7,
                    $case['identity'],
                    $case['confirmation']
                );
                self::fail('untrusted binding identity must be rejected');
            } catch (RuntimeException $error) {
                self::assertSame($case['reason'], $error->getMessage());
            }
            self::assertSame(0, (int)Db::name('system_configs')->count());
            self::assertSame(0, (int)Db::name('operation_logs')->count());
        }
    }

    public function testBindingBootstrapRejectsMalformedAndConflictingStoredBindings(): void
    {
        $service = $this->service();
        $cases = [
            [
                'stored' => '{not-json',
                'reason' => 'dingdandao_collection_binding_config_invalid',
            ],
            [
                'stored' => json_encode([
                    'version' => '2026-07-27',
                    'bindings' => [
                        [
                            'tenant_id' => 1,
                            'hotel_id' => 5,
                            'provider_hotel_id' => 'different-provider-id',
                            'provider_hotel_name' => '敦煌漠蓝',
                            'status' => 'verified',
                        ],
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'reason' => 'dingdandao_collection_binding_conflict',
            ],
            [
                'stored' => json_encode([
                    'version' => '2026-07-27',
                    'bindings' => [
                        [
                            'tenant_id' => 2,
                            'hotel_id' => 99,
                            'provider_hotel_id' => 'provider-bootstrap-hotel-5',
                            'provider_hotel_name' => '其他酒店',
                            'status' => 'verified',
                        ],
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'reason' => 'dingdandao_collection_binding_conflict',
            ],
        ];

        foreach ($cases as $case) {
            Db::name('system_configs')->delete(true);
            Db::name('operation_logs')->delete(true);
            Db::name('system_configs')->insert([
                'config_key' => 'dingdandao_hotel_bindings',
                'config_value' => $case['stored'],
            ]);
            try {
                $service->registerVerifiedBinding(
                    self::PROFILE_ID,
                    1,
                    5,
                    7,
                    $this->validBindingIdentity(),
                    'BIND DINGDANDAO HOTEL 5'
                );
                self::fail('malformed or conflicting stored bindings must be rejected');
            } catch (RuntimeException $error) {
                self::assertSame($case['reason'], $error->getMessage());
            }
            self::assertSame(
                $case['stored'],
                Db::name('system_configs')
                    ->where('config_key', 'dingdandao_hotel_bindings')
                    ->value('config_value')
            );
            self::assertSame(0, (int)Db::name('operation_logs')->count());
        }
    }

    public function testBindingWriteRollsBackWhenAuditCannotBeWritten(): void
    {
        Db::name('system_configs')->delete(true);
        Db::execute(
            "CREATE TRIGGER fail_dingdandao_binding_audit "
            . "BEFORE INSERT ON operation_logs "
            . "WHEN NEW.action = 'bootstrap_dingdandao_binding' "
            . "BEGIN SELECT RAISE(ABORT, 'forced audit failure'); END"
        );
        try {
            $this->service()->registerVerifiedBinding(
                self::PROFILE_ID,
                1,
                5,
                7,
                $this->validBindingIdentity(),
                'BIND DINGDANDAO HOTEL 5'
            );
            self::fail('binding write must roll back when its audit cannot be written');
        } catch (\Throwable) {
            self::assertSame(0, (int)Db::name('system_configs')->count());
            self::assertSame(0, (int)Db::name('operation_logs')->count());
        } finally {
            Db::execute('DROP TRIGGER IF EXISTS fail_dingdandao_binding_audit');
        }
    }

    public function testHotelEightyAliasRegistryIsVersionedAuditableAndContainsNoProviderId(): void
    {
        $registry = require dirname(__DIR__) . '/config/dingdandao_hotel_alias_registry.php';

        self::assertSame('suxios_hotel_provider_alias_registry.v1', $registry['schema_version']);
        self::assertSame('2026-07-28.1', $registry['version']);
        self::assertSame([[
            'tenant_id' => 80,
            'hotel_id' => 80,
            'system_name' => '敦煌漠蓝新',
            'provider' => 'dingdandao',
            'provider_name' => '敦煌漠蓝',
            'status' => 'user_confirmed',
            'confirmed_date' => '2026-07-28',
            'source_reference' => 'user_explicit_confirmation',
        ]], $registry['aliases']);
        $encoded = json_encode($registry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::assertIsString($encoded);
        self::assertStringNotContainsString('provider_hotel_id', $encoded);
        self::assertStringNotContainsString('fixture-provider-hotel-5', $encoded);
    }

    public function testRuntimeBindingAndSystemHotelNamesMustExactlyMatchAliasRegistry(): void
    {
        $service = $this->service();
        Db::name('system_configs')->delete(true);
        Db::name('system_configs')->insert([
            'config_key' => 'dingdandao_hotel_bindings',
            'config_value' => json_encode([
                'version' => '2026-07-27',
                'bindings' => [[
                    'binding_id' => 'binding-hotel-5',
                    'tenant_id' => 1,
                    'hotel_id' => 5,
                    'provider_hotel_id' => 'fixture-provider-hotel-5',
                    'provider_hotel_name' => '敦煌漠蓝新',
                    'status' => 'verified',
                ]],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        try {
            $this->claim($service);
            self::fail('runtime provider name must exactly match the user-confirmed alias');
        } catch (RuntimeException $error) {
            self::assertSame(
                'dingdandao_collection_binding_alias_mismatch',
                $error->getMessage()
            );
        }

        Db::name('system_configs')->delete(true);
        $this->seedBinding();
        Db::name('hotels')->where('id', 5)->update(['name' => '敦煌漠蓝']);
        try {
            $this->claim($service);
            self::fail('runtime system hotel name must exactly match the alias registry');
        } catch (RuntimeException $error) {
            self::assertSame(
                'dingdandao_collection_alias_system_name_mismatch',
                $error->getMessage()
            );
        }
        self::assertSame(0, (int)Db::name('cloud_collection_tasks')->count());
    }

    public function testConfirmedTenantOneHotelFiveAliasIsExactAndRunnerUsesPlatformName(): void
    {
        $service = $this->service();
        Db::name('system_configs')->delete(true);
        Db::name('system_configs')->insert([
            'config_key' => 'dingdandao_hotel_bindings',
            'config_value' => json_encode([
                'bindings' => [[
                    'tenant_id' => 1,
                    'hotel_id' => 5,
                    'provider_hotel_id' => 'fixture-provider-hotel-5',
                    'status' => 'verified',
                ]],
            ], JSON_UNESCAPED_SLASHES),
        ]);
        try {
            $this->claim($service);
            self::fail('an unconfirmed platform name must not create a trusted binding');
        } catch (RuntimeException $error) {
            self::assertSame('dingdandao_collection_binding_missing', $error->getMessage());
        }

        Db::name('system_configs')->delete(true);
        $this->seedBinding();
        $claim = $this->claim($service);
        $scope = $service->trustedCollectorScope(
            $claim['claim_id'],
            self::SESSION_ID,
            self::PROFILE_ID
        );
        self::assertSame('敦煌漠蓝新', Db::name('hotels')->where('id', 5)->value('name'));
        self::assertSame('敦煌漠蓝', $scope['provider_hotel_name']);
    }

    public function testLifecycleCompletionCannotSelfAttestSavedDataAndIsIdempotent(): void
    {
        $service = $this->service();
        $claim = $this->claim($service);
        $first = $service->completeLifecycle(
            $claim['claim_id'],
            self::SESSION_ID,
            self::PROFILE_ID,
            'completed'
        );
        $second = $service->completeLifecycle(
            $claim['claim_id'],
            self::SESSION_ID,
            self::PROFILE_ID,
            'completed'
        );

        self::assertTrue($first['completed']);
        self::assertSame('recorded', $first['completion_status']);
        self::assertSame('reused', $second['completion_status']);
        self::assertSame('closed', $first['lifecycle_status']);
        self::assertSame('unverified', $first['data_status']);
        self::assertSame(0, (int)Db::name('dingdandao_operating_target_captures')->count());
        self::assertSame(
            0,
            (int)Db::name('cloud_collection_tasks')->where('task_public_id', $claim['claim_id'])
                ->value('formal_message_allowed')
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dingdandao_collection_outcome_conflict');
        $service->completeLifecycle(
            $claim['claim_id'],
            self::SESSION_ID,
            self::PROFILE_ID,
            'failed'
        );
    }

    public function testClosedLifecycleCannotPersistTrustedFactsAfterBrowserWindowEnded(): void
    {
        $service = $this->service();
        $claim = $this->claim($service);
        $service->completeLifecycle(
            $claim['claim_id'],
            self::SESSION_ID,
            self::PROFILE_ID,
            'completed'
        );

        try {
            $service->completeTrustedCapture(
                $claim['claim_id'],
                self::SESSION_ID,
                self::PROFILE_ID,
                $this->validCapture()
            );
            self::fail('a closed browser claim must not write facts');
        } catch (RuntimeException $error) {
            self::assertSame('dingdandao_collection_claim_closed', $error->getMessage());
        }
        self::assertSame(0, (int)Db::name('dingdandao_operating_target_captures')->count());
    }

    public function testGenericClientReceiptCannotUpgradeDingdandaoTask(): void
    {
        $claim = $this->claim($this->service());
        try {
            (new CloudCollectionDispatchService())->recordReceipt($claim['claim_id'], [
                'identity_verified' => true,
                'saved' => true,
                'saved_count' => 1,
                'readback_verified' => true,
                'readback_count' => 1,
            ]);
            self::fail('Dingdandao receipt must be generated only by the trusted server path');
        } catch (RuntimeException $error) {
            self::assertSame(
                'dingdandao_collection_server_receipt_required',
                $error->getMessage()
            );
        }
        self::assertSame(
            0,
            (int)Db::name('cloud_collection_tasks')
                ->where('task_public_id', $claim['claim_id'])
                ->value('formal_message_allowed')
        );
    }

    public function testTrustedCaptureRejectsAnyCookieOrRawSessionMaterialBeforePersistence(): void
    {
        $service = $this->service();
        $claim = $this->claim($service);
        $input = $this->validCapture();
        $input['transport'] = ['cookie' => 'must-never-reach-storage'];

        try {
            $service->completeTrustedCapture(
                $claim['claim_id'],
                self::SESSION_ID,
                self::PROFILE_ID,
                $input
            );
            self::fail('sensitive browser material must be rejected before persistence');
        } catch (RuntimeException $error) {
            self::assertSame(
                'dingdandao_capture_sensitive_material_rejected',
                $error->getMessage()
            );
        }
        self::assertSame(0, (int)Db::name('dingdandao_operating_target_captures')->count());
    }

    public function testTrustedCompletionBuildsReceiptFromReadbackAndReusesSnapshot(): void
    {
        $service = $this->service();
        $claim = $this->claim($service);
        $first = $service->completeTrustedCapture(
            $claim['claim_id'],
            self::SESSION_ID,
            self::PROFILE_ID,
            $this->validCapture()
        );
        $retryInput = $this->validCapture();
        $retryInput['captured_at'] = '2026-07-27 10:04:00';
        $second = $service->completeTrustedCapture(
            $claim['claim_id'],
            self::SESSION_ID,
            self::PROFILE_ID,
            $retryInput
        );

        self::assertSame('verified', $first['data_status']);
        self::assertSame('waiting_for_operating_target_sync', $first['truth_gate_status']);
        self::assertFalse($first['formal_message_allowed']);
        self::assertSame('recorded', $first['receipt_status']);
        self::assertSame('reused', $second['receipt_status']);
        self::assertSame($first['capture']['id'], $second['capture']['id']);
        self::assertSame(
            $first['capture']['source_fingerprint'],
            $first['receipt']['capture_fingerprint']
        );
        self::assertSame('readback_verified', $first['receipt']['readback_status']);
        self::assertCount(6, $first['capture']['auxiliary_query_status']);
        self::assertSame(
            'county_diagnostic_only',
            $first['capture']['county_context']['fact_scope']
        );
        self::assertSame(
            'readable_separate',
            $first['capture']['county_context']['data_status']
        );
        self::assertSame(200.0, $first['capture']['county_context']['summary']['total_room_fee']);
        self::assertSame(300.0, $first['capture']['summary']['total_room_fee']);
        self::assertSame(1, (int)Db::name('dingdandao_operating_target_captures')->count());
        self::assertSame(3, (int)Db::name('dingdandao_room_fee_capture_details')->count());

        $closed = $service->completeLifecycle(
            $claim['claim_id'],
            self::SESSION_ID,
            self::PROFILE_ID,
            'completed'
        );
        self::assertSame('unverified', $closed['data_status']);
        self::assertSame(
            'passed',
            Db::name('cloud_collection_tasks')->where('task_public_id', $claim['claim_id'])
                ->value('truth_gate_status')
        );
        self::assertSame(
            1,
            (int)Db::name('cloud_collection_tasks')->where('task_public_id', $claim['claim_id'])
                ->value('formal_message_allowed')
        );
    }

    public function testFailedPipelineAfterTrustedReadbackStaysBlockedAndCanRetry(): void
    {
        $service = $this->service();
        $claim = $this->claim($service);
        $service->completeTrustedCapture(
            $claim['claim_id'],
            self::SESSION_ID,
            self::PROFILE_ID,
            $this->validCapture()
        );
        $service->completeLifecycle(
            $claim['claim_id'],
            self::SESSION_ID,
            self::PROFILE_ID,
            'failed'
        );

        $task = Db::name('cloud_collection_tasks')
            ->where('task_public_id', $claim['claim_id'])
            ->find();
        self::assertSame('closed_unverified', $task['task_status']);
        self::assertSame('blocked_by_data_gap', $task['truth_gate_status']);
        self::assertSame(0, (int)$task['formal_message_allowed']);
        self::assertSame(
            ['operating_target_sync_or_pipeline_completion_missing'],
            json_decode((string)$task['gap_codes_json'], true)
        );

        $retry = $service->claim(
            self::PROFILE_ID,
            'cbcs_collection_retry_123456',
            1,
            5,
            7,
            '2026-07-27',
            'operating_target_today',
            'read_only',
            '2026-07-27 10:10:00'
        );
        self::assertSame('recorded', $retry['claim_status']);
    }

    public function testSessionExpiredOutcomeRevokesProfileWithoutMutatingBrowserMaterial(): void
    {
        $service = $this->service();
        $claim = $this->claim($service);
        $service->completeLifecycle(
            $claim['claim_id'],
            self::SESSION_ID,
            self::PROFILE_ID,
            'session_expired'
        );

        self::assertSame(
            CloudBrowserProfileService::SESSION_EXPIRED,
            Db::name('cloud_browser_profiles')
                ->where('profile_public_id', self::PROFILE_ID)
                ->value('authorization_status')
        );
        self::assertSame(
            'dingdandao_session_expired',
            Db::name('cloud_browser_profiles')
                ->where('profile_public_id', self::PROFILE_ID)
                ->value('status_reason')
        );
    }

    public function testReportGateBlockedOutcomeCannotOpenFormalMessageGate(): void
    {
        $service = $this->service();
        $claim = $this->claim($service);
        $service->completeTrustedCapture(
            $claim['claim_id'],
            self::SESSION_ID,
            self::PROFILE_ID,
            $this->validCapture()
        );
        $service->completeLifecycle(
            $claim['claim_id'],
            self::SESSION_ID,
            self::PROFILE_ID,
            'report_blocked'
        );

        $task = Db::name('cloud_collection_tasks')
            ->where('task_public_id', $claim['claim_id'])
            ->find();
        self::assertSame('blocked_by_data_gap', $task['truth_gate_status']);
        self::assertSame(0, (int)$task['formal_message_allowed']);
        self::assertSame(
            ['operating_target_report_gate_blocked'],
            json_decode((string)$task['gap_codes_json'], true)
        );
    }

    public function testCollectionWindowTimeoutDoesNotFalselyExpirePlatformSession(): void
    {
        $service = $this->service();
        $claim = $this->claim($service);
        $service->completeLifecycle(
            $claim['claim_id'],
            self::SESSION_ID,
            self::PROFILE_ID,
            'window_expired'
        );

        self::assertSame(
            CloudBrowserProfileService::READY_TO_COLLECT,
            Db::name('cloud_browser_profiles')
                ->where('profile_public_id', self::PROFILE_ID)
                ->value('authorization_status')
        );
    }

    public function testTrustedCompletionRejectsWrongProviderBindingBeforeWriting(): void
    {
        $service = $this->service();
        $claim = $this->claim($service);
        $input = $this->validCapture();
        $input['provider_hotel_id'] = 'different-provider-hotel';

        try {
            $service->completeTrustedCapture(
                $claim['claim_id'],
                self::SESSION_ID,
                self::PROFILE_ID,
                $input
            );
            self::fail('provider identity mismatch must block trusted persistence');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('dingdandao_capture_not_verified', $error->getMessage());
        }
        self::assertSame(0, (int)Db::name('dingdandao_operating_target_captures')->count());
    }

    public function testTrustedCompletionRequiresTheConfirmedExactPlatformAlias(): void
    {
        $service = $this->service();
        $claim = $this->claim($service);
        $input = $this->validCapture();
        $input['provider_hotel_name'] = '敦煌·漠蓝';

        try {
            $service->completeTrustedCapture(
                $claim['claim_id'],
                self::SESSION_ID,
                self::PROFILE_ID,
                $input
            );
            self::fail('the database hotel name must not replace the confirmed platform alias');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('dingdandao_capture_not_verified', $error->getMessage());
        }
        self::assertSame(0, (int)Db::name('dingdandao_operating_target_captures')->count());
    }

    public function testConfirmedAliasWithMissingTargetFactsKeepsReceiptAndSendGateBlocked(): void
    {
        $service = $this->service();
        $claim = $this->claim($service);
        $input = $this->validCapture();
        unset($input['summary']['revpar']);

        try {
            $service->completeTrustedCapture(
                $claim['claim_id'],
                self::SESSION_ID,
                self::PROFILE_ID,
                $input
            );
            self::fail('an exact alias cannot substitute for missing target facts');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('dingdandao_capture_not_verified', $error->getMessage());
        }
        self::assertSame(0, (int)Db::name('dingdandao_operating_target_captures')->count());
        self::assertSame(
            0,
            (int)Db::name('cloud_collection_tasks')
                ->where('task_public_id', $claim['claim_id'])
                ->value('formal_message_allowed')
        );
        self::assertNotSame(
            'passed',
            Db::name('cloud_collection_tasks')
                ->where('task_public_id', $claim['claim_id'])
                ->value('truth_gate_status')
        );
    }

    public function testConflictingTrustedRetryRollsBackSecondSnapshot(): void
    {
        $service = $this->service();
        $claim = $this->claim($service);
        $service->completeTrustedCapture(
            $claim['claim_id'],
            self::SESSION_ID,
            self::PROFILE_ID,
            $this->validCapture()
        );
        $changed = $this->validCapture();
        $changed['summary']['total_room_fee'] = 400;
        $changed['summary']['adr'] = 200;
        $changed['summary']['revpar'] = 200;
        $changed['room_fee_details'][0]['room_fee'] = 200;
        $changed['room_fee_details'][1]['room_fee'] = 200;
        $changed['room_fee_details'][2]['room_fee'] = 400;

        try {
            $service->completeTrustedCapture(
                $claim['claim_id'],
                self::SESSION_ID,
                self::PROFILE_ID,
                $changed
            );
            self::fail('different facts for a completed claim must conflict');
        } catch (RuntimeException $error) {
            self::assertSame('dingdandao_collection_receipt_conflict', $error->getMessage());
        }
        self::assertSame(1, (int)Db::name('dingdandao_operating_target_captures')->count());
        self::assertSame(3, (int)Db::name('dingdandao_room_fee_capture_details')->count());
    }

    public function testBridgeAndRunnerExposeOnlyTheNarrowServerContract(): void
    {
        $root = dirname(__DIR__);
        $bridge = (string)file_get_contents($root . '/scripts/cloud_browser_gateway_bridge.php');
        $runner = (string)file_get_contents($root . '/scripts/run_dingdandao_cloud_collection.php');

        self::assertStringContainsString("'claim_dingdandao_collection'", $bridge);
        self::assertStringContainsString("'complete_dingdandao_collection'", $bridge);
        self::assertStringContainsString("requiredId(\$input, 'claim_id', 'cct_')", $bridge);
        self::assertStringNotContainsString("'saved_count'", $bridge);
        self::assertStringNotContainsString("'readback_verified'", $bridge);
        self::assertStringContainsString('completeTrustedCapture(', $runner);
        self::assertStringNotContainsString('latestProviderHotelId(', $runner);
        self::assertStringContainsString(
            "'--collection-mode=operating_indicators'",
            $runner
        );
        self::assertStringContainsString(
            "(\$opened['browser_started'] ?? null) !== false",
            $runner
        );
        self::assertStringContainsString(
            "(\$opened['collection_transport'] ?? '') !== 'existing_session_direct_post'",
            $runner
        );
        self::assertStringContainsString(
            "(\$opened['existing_session_required'] ?? null) !== true",
            $runner
        );
        self::assertStringContainsString(
            "(\$opened['profile_mutated'] ?? null) !== false",
            $runner
        );
        self::assertStringContainsString(
            "(\$closed['existing_browser_closed'] ?? null) !== false",
            $runner
        );
        self::assertStringContainsString(
            "(\$closed['profile_mutated'] ?? null) !== false",
            $runner
        );
        self::assertStringContainsString(
            "(\$closed['data_status'] ?? '') !== 'unverified'",
            $runner
        );
        self::assertStringContainsString(
            "\$closeOutcome = \$reportSendEligible ? 'completed' : 'report_blocked';",
            $runner
        );
        self::assertStringContainsString("'collection-only'", $runner);
        self::assertStringContainsString('if (!$collectionOnly)', $runner);
        self::assertStringContainsString(
            "'saved_capture_and_base_facts_ready'",
            $runner
        );
        self::assertStringContainsString(
            "'operating_target_sync_status' => is_array(\$targetSync)",
            $runner
        );
        self::assertStringContainsString("'skipped_collection_only'", $runner);
        self::assertStringContainsString("'--experimental-websocket'", $runner);
    }

    public function testBindingBootstrapRunnerIsDryRunByDefaultAndCannotSend(): void
    {
        $root = dirname(__DIR__);
        $runner = (string)file_get_contents(
            $root . '/scripts/run_dingdandao_binding_bootstrap.php'
        );
        $probe = (string)file_get_contents(
            $root . '/scripts/dingdandao_binding_probe.mjs'
        );

        self::assertStringContainsString("'execute'", $runner);
        self::assertStringContainsString("'runtime-directory::'", $runner);
        self::assertStringContainsString(
            "'/run/suxios-molanxin-three-source-collection'",
            $runner
        );
        self::assertStringContainsString(
            "?? '/run/suxios-dingdandao-collection')), '/');",
            $runner
        );
        self::assertMatchesRegularExpression(
            '/\$allowedRuntimeDirectories\s*=\s*\[\s*'
                . "'\\/run\\/suxios-dingdandao-collection',\\s*"
                . "'\\/run\\/suxios-molanxin-three-source-collection',\\s*"
                . '\];/s',
            $runner
        );
        self::assertStringContainsString(
            "in_array(\$runtimeDirectory, \$allowedRuntimeDirectories, true)",
            $runner
        );
        self::assertStringContainsString(
            "\$lockPath = \$runtimeDirectory . '/hotel-' . \$hotelId . '.lock';",
            $runner
        );
        self::assertStringContainsString(
            "'BIND DINGDANDAO HOTEL ' . \$hotelId",
            $runner
        );
        self::assertStringContainsString("'binding_persisted' => false", $runner);
        self::assertStringContainsString("'business_data_persisted' => false", $runner);
        self::assertStringContainsString("'message_sent' => false", $runner);
        self::assertStringContainsString("'bypass_shell' => true", $runner);
        self::assertStringContainsString('runIdentityProbe(', $runner);
        self::assertStringContainsString("'--identity-fd=3'", $runner);
        self::assertStringContainsString("3 => ['pipe', 'w']", $runner);
        self::assertStringNotContainsString('Wechat', $runner);
        self::assertStringNotContainsString('systemctl', $runner);
        self::assertStringNotContainsString('mkdir(', $runner);
        self::assertStringNotContainsString('unlink(', $runner);
        self::assertStringNotContainsString('proc_terminate', $runner);
        self::assertStringNotContainsString('posix_kill', $runner);
        self::assertStringNotContainsString('completeTrustedCapture', $runner);
        self::assertStringContainsString('probeDingdandaoIdentity', $probe);
        self::assertStringContainsString("'identity_verified_unpersisted'", $probe);
        self::assertStringContainsString('binding_probe_private_pipe_required', $probe);
        self::assertStringContainsString(
            'identity_transferred_via_private_pipe: true',
            $probe
        );
        self::assertStringContainsString('user_tabs_closed: false', $probe);
    }

    private function service(): DingdandaoCloudCollectionService
    {
        return new DingdandaoCloudCollectionService(
            static fn(): DateTimeImmutable => new DateTimeImmutable('2026-07-27 10:00:00')
        );
    }

    /** @return array<string,mixed> */
    private function claim(DingdandaoCloudCollectionService $service): array
    {
        return $service->claim(
            self::PROFILE_ID,
            self::SESSION_ID,
            1,
            5,
            7,
            '2026-07-27',
            'operating_target_today',
            'read_only',
            '2026-07-27 10:10:00'
        );
    }

    private function seedBinding(): void
    {
        Db::name('system_configs')->insert([
            'config_key' => 'dingdandao_hotel_bindings',
            'config_value' => json_encode([
                'version' => '2026-07-27',
                'bindings' => [[
                    'binding_id' => 'binding-hotel-5',
                    'tenant_id' => 1,
                    'hotel_id' => 5,
                    'provider_hotel_id' => 'fixture-provider-hotel-5',
                    'provider_hotel_name' => '敦煌漠蓝',
                    'status' => 'verified',
                ]],
            ], JSON_UNESCAPED_SLASHES),
        ]);
    }

    /** @return array<string,mixed> */
    private function validBindingIdentity(): array
    {
        return [
            'capture_method' => 'existing_session_direct_post',
            'captured_at' => '2026-07-27T02:00:00.000Z',
            'identity_status' => 'matched',
            'provider_hotel_id' => 'provider-bootstrap-hotel-5',
            'provider_hotel_name' => '敦煌漠蓝',
            'request_count' => 1,
            'source_api_path' => '/v2/ntw/web/ntw/get',
        ];
    }

    /** @return array<string,mixed> */
    private function validCapture(): array
    {
        $summary = [
            'total_room_fee' => 300,
            'adr' => 150,
            'occupancy_rate_percent' => 100,
            'revpar' => 150,
            'sold_room_nights' => 2,
            'average_daily_room_nights' => 2,
        ];
        return [
            'source_url' => DingdandaoOperatingTargetCaptureService::SOURCE_URL,
            'source_api_path' => '/api/verified-read',
            'source_scope' => DingdandaoOperatingTargetCaptureService::SOURCE_SCOPE,
            'capture_method' => 'network_response',
            'captured_at' => '2026-07-27 10:05:00',
            'business_date' => '2026-07-27',
            'provider_hotel_id' => 'fixture-provider-hotel-5',
            'provider_hotel_name' => '敦煌漠蓝',
            'identity_evidence_type' => 'verified_api_store_identity',
            'summary' => $summary,
            'room_fee_details' => [
                [
                    'row_kind' => 'room',
                    'room_type' => 'King',
                    'room_number' => '501',
                    'room_fee' => 150,
                ],
                [
                    'row_kind' => 'room',
                    'room_type' => 'King',
                    'room_number' => '502',
                    'room_fee' => 150,
                ],
                [
                    'row_kind' => 'grand_total',
                    'room_type' => null,
                    'room_number' => null,
                    'room_fee' => 300,
                ],
            ],
            'trend' => [],
            'auxiliary_query_status' => [
                [
                    'api_path' => '/v2/um-b/web/pro/data/businessIndicatorsSumDetail',
                    'type' => 1,
                    'fact_scope' => 'auxiliary_metric_only',
                    'status' => 'readable_not_promoted',
                ],
                [
                    'api_path' => '/v2/um-b/web/pro/data/businessIndicatorsDailyDetail',
                    'type' => 1,
                    'fact_scope' => 'auxiliary_metric_only',
                    'status' => 'readable_not_promoted',
                ],
                [
                    'api_path' => '/v2/um-b/web/pro/data/businessIndicatorsSumDetail',
                    'type' => 2,
                    'fact_scope' => 'auxiliary_metric_only',
                    'status' => 'readable_not_promoted',
                ],
                [
                    'api_path' => '/v2/um-b/web/pro/data/businessIndicatorsDailyDetail',
                    'type' => 2,
                    'fact_scope' => 'auxiliary_metric_only',
                    'status' => 'readable_not_promoted',
                ],
                [
                    'api_path' => '/v2/um-b/web/pro/data/businessIndicatorsSumDetail',
                    'type' => 3,
                    'fact_scope' => 'auxiliary_metric_only',
                    'status' => 'readable_not_promoted',
                ],
                [
                    'api_path' => '/v2/um-b/web/pro/data/businessIndicatorsDailyDetail',
                    'type' => 3,
                    'fact_scope' => 'auxiliary_metric_only',
                    'status' => 'readable_not_promoted',
                ],
            ],
            'county_context' => [
                'fact_scope' => 'county_diagnostic_only',
                'data_status' => 'readable_separate',
                'bool_city' => false,
                'summary' => [
                    'total_room_fee' => 200,
                    'adr' => 100,
                    'occupancy_rate_percent' => 50,
                    'revpar' => 50,
                    'sold_room_nights' => 2.5,
                    'average_daily_room_nights' => 2.5,
                ],
                'trend' => [
                    'total_room_fee' => [
                        ['date' => '2026-07-27', 'value' => 200],
                    ],
                ],
                'field_trace' => [
                    'summary' => 'API:/v2/um-b/web/pro/data/businessIndicatorsTotal/county#data',
                    'trend' => 'API:/v2/um-b/web/pro/data/businessIndicatorsTrend/county?type=5#data.list[]',
                ],
            ],
            'field_trace' => array_fill_keys(array_keys($summary), 'API:/api/verified-read'),
        ];
    }

    private static function createSchema(): void
    {
        Db::execute(
            'CREATE TABLE cloud_browser_profiles ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, system_hotel_id INTEGER, '
            . 'owner_user_id INTEGER, platform TEXT, profile_public_id TEXT UNIQUE, '
            . 'authorization_status TEXT, status_reason TEXT NULL, ready_at TEXT NULL, '
            . 'session_expires_at TEXT NULL, last_state_change_at TEXT NULL, update_time TEXT NULL)'
        );
        Db::execute(
            'CREATE TABLE cloud_collection_tasks ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, task_public_id TEXT UNIQUE, profile_id INTEGER, '
            . 'profile_public_id TEXT, tenant_id INTEGER, system_hotel_id INTEGER, owner_user_id INTEGER, '
            . 'platform TEXT, collection_mode TEXT, target_date TEXT, window_key TEXT, '
            . 'field_priority_json TEXT, task_status TEXT, truth_gate_status TEXT, gap_codes_json TEXT NULL, '
            . 'receipt_evidence_json TEXT NULL, receipt_fingerprint TEXT NULL, '
            . 'formal_message_allowed INTEGER NOT NULL DEFAULT 0, idempotency_key TEXT UNIQUE, '
            . 'started_at TEXT NULL, finished_at TEXT NULL, create_time TEXT, update_time TEXT)'
        );
        Db::execute(
            'CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER, name TEXT, status INTEGER, '
            . 'owner_user_id INTEGER, created_by INTEGER)'
        );
        Db::execute(
            'CREATE TABLE users (id INTEGER PRIMARY KEY, tenant_id INTEGER, hotel_id INTEGER NULL, '
            . 'role_id INTEGER, status INTEGER)'
        );
        Db::execute(
            'CREATE TABLE user_hotel_permissions ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, user_id INTEGER, hotel_id INTEGER, '
            . 'status TEXT, expires_at TEXT NULL, can_fetch_ota INTEGER, can_fetch_online_data INTEGER)'
        );
        Db::execute(
            'CREATE TABLE system_configs ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, config_key TEXT UNIQUE, config_value TEXT)'
        );
        Db::execute(
            'CREATE TABLE operation_logs ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, user_id INTEGER, '
            . 'hotel_id INTEGER, module TEXT, action TEXT, description TEXT, error_info TEXT NULL, '
            . 'extra_data TEXT NULL, ip TEXT, user_agent TEXT, create_time TEXT)'
        );
        Db::execute(
            'CREATE TABLE dingdandao_operating_target_captures ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, provider TEXT, '
            . 'provider_hotel_id TEXT NULL, provider_hotel_name TEXT NULL, expected_hotel_name TEXT, '
            . 'identity_evidence_type TEXT, identity_status TEXT, source_url TEXT, source_api_path TEXT NULL, '
            . 'source_scope TEXT, capture_method TEXT, business_date TEXT, total_room_fee REAL NULL, adr REAL NULL, '
            . 'occupancy_rate_percent REAL NULL, revpar REAL NULL, sold_room_nights INTEGER NULL, '
            . 'average_daily_room_nights REAL NULL, derived_sellable_room_nights INTEGER NULL, '
            . 'detail_room_fee_total REAL NULL, detail_row_count INTEGER, reconciliation_status TEXT, '
            . 'capture_status TEXT, quality_status TEXT, quality_reason TEXT NULL, gap_codes_json TEXT NULL, '
            . 'trend_json TEXT NULL, field_trace_json TEXT NULL, snapshot_json TEXT, source_fingerprint TEXT, '
            . 'captured_at TEXT, captured_by INTEGER NULL, readback_status TEXT, readback_verified_at TEXT NULL, '
            . 'create_time TEXT, update_time TEXT)'
        );
        Db::execute(
            'CREATE TABLE dingdandao_room_fee_capture_details ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, capture_id INTEGER, tenant_id INTEGER, hotel_id INTEGER, '
            . 'business_date TEXT, row_kind TEXT, room_type TEXT NULL, room_number TEXT NULL, room_fee REAL, '
            . 'source_row_index INTEGER, create_time TEXT)'
        );
    }
}
