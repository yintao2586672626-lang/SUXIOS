<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaLocalCollectorService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Cache;
use think\facade\Config;
use think\facade\Db;

final class OtaLocalCollectorServiceTest extends TestCase
{
    private static App $app;
    private static array $originalDatabaseConfig = [];
    private static string $connection = '';
    private static string $databasePath = '';

    public static function setUpBeforeClass(): void
    {
        self::$app = new App(dirname(__DIR__));
        self::$app->initialize();
        self::$connection = 'ota_local_collector_' . getmypid() . '_' . bin2hex(random_bytes(4));
        self::$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . self::$connection . '.sqlite';
        self::$originalDatabaseConfig = Config::get('database');
        $database = self::$originalDatabaseConfig;
        $database['default'] = self::$connection;
        $database['connections'][self::$connection] = [
            'type' => 'sqlite',
            'database' => self::$databasePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($database, 'database');
        Db::connect(null, true);
        self::createSchema();
    }

    public static function tearDownAfterClass(): void
    {
        try {
            Cache::clear();
            Db::connect()->close();
        } catch (\Throwable) {
        }
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$databasePath);
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            'user_hotel_permissions',
            'platform_data_sync_tasks',
            'online_daily_data',
            'platform_data_sources',
            'ota_local_collector_tasks',
            'ota_local_collector_account_hotels',
            'ota_local_collector_accounts',
            'ota_local_collector_devices',
            'hotels',
            'roles',
            'users',
        ] as $table) {
            Db::name($table)->delete(true);
        }
        Db::name('users')->insert([
            'id' => 7,
            'tenant_id' => 12,
            'username' => 'collector-owner',
            'password' => 'fixture',
            'status' => 1,
            'role_id' => 3,
        ]);
        Db::name('roles')->insert([
            'id' => 3,
            'name' => 'user',
            'status' => 1,
            'level' => 9,
            'permissions' => '[]',
        ]);
        Db::name('hotels')->insertAll([
            ['id' => 101, 'tenant_id' => 12, 'name' => 'Hotel A', 'status' => 1],
            ['id' => 102, 'tenant_id' => 12, 'name' => 'Hotel B', 'status' => 1],
            ['id' => 201, 'tenant_id' => 99, 'name' => 'Other Tenant Hotel', 'status' => 1],
        ]);
        Db::name('user_hotel_permissions')->insertAll([
            ['tenant_id' => 12, 'user_id' => 7, 'hotel_id' => 101, 'status' => 'active', 'can_view' => 1, 'expires_at' => null],
            ['tenant_id' => 12, 'user_id' => 7, 'hotel_id' => 102, 'status' => 'active', 'can_view' => 1, 'expires_at' => null],
        ]);
    }

    public function testSensitiveCamelCaseCollectorKeysAreRejectedBeforePersistence(): void
    {
        $service = new OtaLocalCollectorService();
        $method = new \ReflectionMethod(OtaLocalCollectorService::class, 'assertNoSensitiveMaterial');
        $method->setAccessible(true);

        foreach ([
            'cookieValue',
            'sessionToken',
            'profilePath',
            'rawSession',
            'localStorage',
            'sessionStorage',
            'authorizationHeader',
            'apiKey',
            'requestHeaders',
            'responseHeaders',
            'webhookUrl',
        ] as $key) {
            $rejected = false;
            try {
                $method->invoke($service, [$key => 'forbidden']);
            } catch (RuntimeException $exception) {
                $rejected = true;
                self::assertStringContainsString(strtolower($key), strtolower($exception->getMessage()));
            }
            self::assertTrue($rejected, "Expected {$key} to be rejected");
        }

        $method->invoke($service, [
            'session_status' => 'current_session_verified',
            'profile_key_hash' => str_repeat('a', 64),
        ]);
        self::addToAssertionCount(1);
    }

    public function testDeviceCannotLeaseTaskAfterItsOwnerLosesThatHotelPermission(): void
    {
        $service = new OtaLocalCollectorService();
        $actor = $this->actor();
        $pairCode = $service->createPairCode($actor, ['device_name' => 'Permission scope PC']);
        $paired = $service->pairDevice([
            'pair_code' => $pairCode['pair_code'],
            'device_platform' => 'windows',
        ]);
        $account = $service->createAccount($actor, [
            'device_id' => $paired['device_id'],
            'platform' => 'ctrip',
            'account_alias' => 'Permission scope account',
            'system_hotel_id' => 102,
            'platform_hotel_id' => 'CTRIP-PERM-102',
        ]);
        Db::name('ota_local_collector_accounts')->where('id', $account['account_id'])->update([
            'status' => 'active',
            'session_status' => 'current_session_verified',
        ]);
        $task = $service->createTask($actor, [
            'account_id' => $account['account_id'],
            'system_hotel_id' => 102,
            'task_type' => 'collect',
            'data_date' => '2026-07-23',
        ]);
        Db::name('user_hotel_permissions')->delete(true);
        Db::name('user_hotel_permissions')->insert([
            'tenant_id' => 12,
            'user_id' => 7,
            'hotel_id' => 101,
            'status' => 'active',
            'can_view' => 1,
        ]);

        $leased = $service->nextTask($paired['device_public_id'], $paired['device_token']);
        self::assertSame('idle', $leased['status']);
        self::assertNull($leased['task']);
        self::assertSame('queued', Db::name('ota_local_collector_tasks')->where('id', $task['task']['id'])->value('status'));
    }

    public function testDevicePermissionResolverFailsClosedForEmptyInactiveExpiredAndNoViewGrants(): void
    {
        $service = new OtaLocalCollectorService();
        $actor = $this->actor();
        $pair = $service->pairDevice([
            'pair_code' => $service->createPairCode($actor, ['device_name' => 'Scope resolver PC'])['pair_code'],
            'device_platform' => 'windows',
        ]);
        $device = Db::name('ota_local_collector_devices')->where('id', $pair['device_id'])->find();
        $method = new \ReflectionMethod(OtaLocalCollectorService::class, 'devicePermittedHotelIds');
        $method->setAccessible(true);

        $cases = [
            'empty' => [],
            'inactive' => [['status' => 'inactive', 'can_view' => 1, 'expires_at' => null]],
            'expired' => [['status' => 'active', 'can_view' => 1, 'expires_at' => '2000-01-01 00:00:00']],
            'no_view' => [['status' => 'active', 'can_view' => 0, 'expires_at' => null]],
        ];
        foreach ($cases as $case => $rows) {
            Db::name('user_hotel_permissions')->delete(true);
            foreach ($rows as $row) {
                Db::name('user_hotel_permissions')->insert([
                    'tenant_id' => 12,
                    'user_id' => 7,
                    'hotel_id' => 102,
                    ...$row,
                ]);
            }
            self::assertSame([], $method->invoke($service, $device), $case);
        }
    }

    public function testRevokedHotelLeaseRejectsProgressAndResultWithoutBusinessWrites(): void
    {
        $importCalls = 0;
        $service = new OtaLocalCollectorService(
            static function () use (&$importCalls): array {
                $importCalls++;
                return ['status' => 'success', 'saved_count' => 1, 'readback_verified' => true];
            }
        );
        $actor = $this->actor();
        $pair = $service->pairDevice([
            'pair_code' => $service->createPairCode($actor, ['device_name' => 'Lease revoke PC'])['pair_code'],
            'device_platform' => 'windows',
        ]);
        $account = $service->createAccount($actor, [
            'device_id' => $pair['device_id'],
            'platform' => 'meituan',
            'account_alias' => 'Lease revoke account',
            'system_hotel_id' => 102,
            'platform_hotel_id' => 'MT-LEASE-102',
        ]);
        Db::name('ota_local_collector_accounts')->where('id', $account['account_id'])->update([
            'status' => 'active',
            'session_status' => 'current_session_verified',
        ]);
        $service->createTask($actor, [
            'account_id' => $account['account_id'],
            'system_hotel_id' => 102,
            'task_type' => 'collect',
            'data_date' => '2026-07-23',
        ]);
        $leased = $service->nextTask($pair['device_public_id'], $pair['device_token'])['task'];
        Db::name('user_hotel_permissions')->where('hotel_id', 102)->delete();
        $beforeTask = Db::name('ota_local_collector_tasks')->where('id', $leased['id'])->find();
        $beforeAccount = Db::name('ota_local_collector_accounts')->where('id', $account['account_id'])->find();
        $beforeOnlineCount = Db::name('online_daily_data')->count();

        foreach ([
            static fn() => $service->updateTaskProgress(
                $pair['device_public_id'], $pair['device_token'], (int)$leased['id'],
                ['lease_token' => $leased['lease_token'], 'status' => 'running']
            ),
            static fn() => $service->submitTaskResult(
                $pair['device_public_id'], $pair['device_token'], (int)$leased['id'],
                ['lease_token' => $leased['lease_token'], 'success' => true, 'rows' => []]
            ),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('Revoked hotel lease must be rejected');
            } catch (RuntimeException $exception) {
                self::assertSame(403, $exception->getCode());
            }
        }

        self::assertSame($beforeTask['status'], Db::name('ota_local_collector_tasks')->where('id', $leased['id'])->value('status'));
        self::assertSame($beforeTask['update_time'], Db::name('ota_local_collector_tasks')->where('id', $leased['id'])->value('update_time'));
        self::assertSame($beforeAccount['status'], Db::name('ota_local_collector_accounts')->where('id', $account['account_id'])->value('status'));
        self::assertSame($beforeOnlineCount, Db::name('online_daily_data')->count());
        self::assertSame(0, $importCalls);
    }

    public function testAccountOwnedDeviceProfileAndCollectionLoop(): void
    {
        $importedRows = [];
        $service = new OtaLocalCollectorService(
            static function ($owner, array $task, array $account, array $mapping, array $device, array $rows) use (&$importedRows): array {
                $importedRows = $rows;
                Db::name('ota_local_collector_account_hotels')
                    ->where('id', (int)$mapping['id'])
                    ->where('tenant_id', (int)$task['tenant_id'])
                    ->where('account_id', (int)$task['account_id'])
                    ->where('system_hotel_id', (int)$task['system_hotel_id'])
                    ->where('platform', (string)$task['platform'])
                    ->update(['data_source_id' => 88]);
                Db::name('platform_data_sources')->insert([
                    'id' => 88,
                    'tenant_id' => (int)$task['tenant_id'],
                    'system_hotel_id' => (int)$task['system_hotel_id'],
                    'platform' => (string)$task['platform'],
                    'data_type' => 'business',
                    'ingestion_method' => 'local_collector',
                    'status' => 'active',
                    'enabled' => 1,
                ]);
                Db::name('online_daily_data')->insert([
                    'id' => 7001,
                    'tenant_id' => (int)$task['tenant_id'],
                    'system_hotel_id' => (int)$task['system_hotel_id'],
                    'data_source_id' => 88,
                    'sync_task_id' => 99,
                    'data_date' => (string)$task['data_date'],
                    'platform' => (string)$task['platform'],
                    'source' => (string)$task['platform'],
                    'data_type' => 'business',
                    'data_period' => 'historical_daily',
                    'readback_verified' => 1,
                    'validation_status' => 'valid',
                    'amount' => 688,
                    'quantity' => 2,
                    'book_order_num' => 6,
                    'list_exposure' => 120,
                    'detail_exposure' => 40,
                    'flow_rate' => 0.33,
                    'order_filling_num' => 9,
                    'order_submit_num' => 4,
                ]);
                return [
                    'status' => 'success',
                    'data_source_id' => 88,
                    'task_id' => 99,
                    'normalized_count' => count($rows),
                    'saved_count' => count($rows),
                    'readback_count' => count($rows),
                    'readback_verified' => count($rows) > 0,
                    'run_readback' => [
                        'tenant_id' => (int)$task['tenant_id'],
                        'data_source_id' => 88,
                        'sync_task_id' => 99,
                        'system_hotel_id' => (int)$task['system_hotel_id'],
                        'target_date' => (string)$task['data_date'],
                        'platform' => (string)$task['platform'],
                        'readback_count' => 1,
                        'readback_verified' => true,
                        'p0_status' => 'ready',
                        'row_ids' => [7001],
                    ],
                    'deterministic_readback' => [
                        'tenant_id' => (int)$task['tenant_id'],
                        'data_source_id' => 88,
                        'sync_task_id' => 99,
                        'system_hotel_id' => (int)$task['system_hotel_id'],
                        'target_date' => (string)$task['data_date'],
                        'platform' => (string)$task['platform'],
                        'readback_count' => 1,
                        'readback_verified' => true,
                        'row_ids' => [7001],
                    ],
                    'sync_diagnostics' => [
                        'target_date' => (string)$task['data_date'],
                        'requires_target_date_traffic' => true,
                        'p0_status' => 'ready',
                    ],
                ];
            }
        );
        $actor = $this->actor();

        $pairCode = $service->createPairCode($actor, ['device_name' => 'Owner PC']);
        $paired = $service->pairDevice([
            'pair_code' => $pairCode['pair_code'],
            'device_name' => 'Owner PC',
            'device_platform' => 'windows',
            'collector_version' => 'test',
        ]);
        $storedDevice = Db::name('ota_local_collector_devices')->where('id', $paired['device_id'])->find();
        self::assertNotSame($paired['device_token'], $storedDevice['device_token_hash']);
        self::assertSame(hash('sha256', $paired['device_token']), $storedDevice['device_token_hash']);

        $created = $service->createAccount($actor, [
            'device_id' => $paired['device_id'],
            'platform' => 'ctrip',
            'account_alias' => '华东携程账户',
            'system_hotel_id' => 101,
            'platform_hotel_id' => 'CTRIP-101',
            'platform_hotel_name' => 'Hotel A OTA',
        ]);
        $service->bindHotel($actor, $created['account_id'], [
            'system_hotel_id' => 102,
            'platform_hotel_id' => 'CTRIP-102',
            'platform_hotel_name' => 'Hotel B OTA',
        ]);

        self::assertSame(1, Db::name('ota_local_collector_accounts')->count());
        self::assertSame(2, Db::name('ota_local_collector_account_hotels')->count());
        $status = $service->status($actor);
        self::assertSame(2, count($status['accounts'][0]['hotels']));
        self::assertArrayNotHasKey('profile_key_hash', $status['accounts'][0]);
        self::assertArrayNotHasKey('device_token_hash', $status['devices'][0]);

        $service->createTask($actor, [
            'account_id' => $created['account_id'],
            'system_hotel_id' => 101,
            'task_type' => 'login',
        ]);
        $loginTask = $service->nextTask($paired['device_public_id'], $paired['device_token'])['task'];
        self::assertSame('success', $service->submitTaskResult(
            $paired['device_public_id'],
            $paired['device_token'],
            $loginTask['id'],
            [
                'lease_token' => $loginTask['lease_token'],
                'success' => true,
                'session_status' => 'current_session_verified',
            ]
        )['status']);

        $service->createTask($actor, [
            'account_id' => $created['account_id'],
            'system_hotel_id' => 102,
            'task_type' => 'collect',
            'data_date' => '2026-07-23',
        ]);
        $collectTask = $service->nextTask($paired['device_public_id'], $paired['device_token'])['task'];
        $result = $service->submitTaskResult(
            $paired['device_public_id'],
            $paired['device_token'],
            $collectTask['id'],
            [
                'lease_token' => $collectTask['lease_token'],
                'success' => true,
                'capture_summary' => [
                    'platform_identity_validation' => [
                        'status' => 'matched',
                        'source_validation' => true,
                        'validated_identifier' => 'CTRIP-102',
                    ],
                ],
                'rows' => [[
                    'data_date' => '2026-07-23',
                    'platform_hotel_id' => 'CTRIP-102',
                    'data_type' => 'business',
                    'order_amount' => 688,
                    'room_nights' => 2,
                    'order_count' => 6,
                    'list_exposure' => 120,
                    'detail_exposure' => 40,
                    'flow_rate' => 0.33,
                    'order_filling_num' => 9,
                    'order_submit_num' => 4,
                ]],
            ]
        );
        self::assertSame('success', $result['status']);
        self::assertTrue($result['summary']['readback_verified']);
        self::assertTrue($result['summary']['run_readback_scope_verified']);
        self::assertSame(102, $result['summary']['scope_identity']['system_hotel_id']);
        self::assertSame('ctrip', $result['summary']['scope_identity']['platform']);
        self::assertSame('2026-07-23', $result['summary']['scope_identity']['business_date']);
        self::assertSame(102, $importedRows[0]['system_hotel_id']);
        self::assertSame('local_account_profile', $importedRows[0]['source_method']);
    }

    #[DataProvider('strictRunReadbackCaseProvider')]
    public function testSuccessfulImporterRequiresAnExactScopedDeterministicReadback(
        string $variant,
        bool $expectSuccess
    ): void {
        $service = new OtaLocalCollectorService(
            static function (
                $owner,
                array $task,
                array $account,
                array $mapping,
                array $device,
                array $rows
            ) use ($variant): array {
                $rowIds = [7001];
                $runReadback = [
                    'tenant_id' => (int)$task['tenant_id'],
                    'data_source_id' => 88,
                    'sync_task_id' => 99,
                    'system_hotel_id' => (int)$task['system_hotel_id'],
                    'target_date' => (string)$task['data_date'],
                    'platform' => (string)$task['platform'],
                    'readback_count' => 1,
                    'readback_verified' => true,
                    'p0_status' => 'ready',
                    'row_ids' => $rowIds,
                ];
                $deterministicReadback = [
                    'tenant_id' => (int)$task['tenant_id'],
                    'data_source_id' => 88,
                    'sync_task_id' => 99,
                    'system_hotel_id' => (int)$task['system_hotel_id'],
                    'target_date' => (string)$task['data_date'],
                    'platform' => (string)$task['platform'],
                    'readback_count' => 1,
                    'readback_verified' => true,
                    'row_ids' => $rowIds,
                ];
                if ($variant === 'tenant_mismatch') {
                    $runReadback['tenant_id'] = 99;
                } elseif ($variant === 'data_source_mismatch') {
                    $runReadback['data_source_id'] = 89;
                } elseif ($variant === 'mapping_data_source_mismatch') {
                    // The importer receipt can be internally consistent while
                    // still pointing at a different source than the task's
                    // hotel mapping. The service must reject that scope drift.
                    $runReadback['data_source_id'] = 88;
                } elseif ($variant === 'sync_task_mismatch') {
                    $runReadback['sync_task_id'] = 100;
                } elseif ($variant === 'row_set_mismatch') {
                    $runReadback['row_ids'] = [7002];
                }

                if ($variant !== 'mapping_data_source_missing') {
                    Db::name('platform_data_sources')->insert([
                        'id' => 88,
                        'tenant_id' => (int)$task['tenant_id'],
                        'system_hotel_id' => (int)$task['system_hotel_id'],
                        'platform' => (string)$task['platform'],
                        'data_type' => 'business',
                        'ingestion_method' => 'local_collector',
                        'status' => 'active',
                        'enabled' => 1,
                    ]);
                    Db::name('online_daily_data')->insert([
                        'id' => 7001,
                        'tenant_id' => (int)$task['tenant_id'],
                        'system_hotel_id' => (int)$task['system_hotel_id'],
                        'data_source_id' => 88,
                        'sync_task_id' => 99,
                        'data_date' => (string)$task['data_date'],
                        'platform' => (string)$task['platform'],
                        'source' => (string)$task['platform'],
                        'data_type' => 'business',
                        'data_period' => 'historical_daily',
                        'readback_verified' => 1,
                        'validation_status' => 'valid',
                        'amount' => 688,
                        'quantity' => 2,
                        'book_order_num' => 6,
                        'list_exposure' => 120,
                        'detail_exposure' => 40,
                        'flow_rate' => 0.33,
                        'order_filling_num' => 9,
                        'order_submit_num' => 4,
                    ]);
                }

                $result = [
                    'status' => 'success',
                    'data_source_id' => 88,
                    'task_id' => 99,
                    'normalized_count' => count($rows),
                    'saved_count' => count($rows),
                    'readback_count' => count($rows),
                    'readback_verified' => true,
                    'sync_diagnostics' => [
                        'target_date' => (string)$task['data_date'],
                        'requires_target_date_traffic' => true,
                        'p0_status' => 'ready',
                    ],
                ];
                if ($variant !== 'missing_receipt') {
                    $result['run_readback'] = $runReadback;
                    $result['deterministic_readback'] = $deterministicReadback;
                }
                return $result;
            }
        );
        $actor = $this->actor();
        $paired = $service->pairDevice([
            'pair_code' => $service->createPairCode(
                $actor,
                ['device_name' => 'Strict readback fixture PC']
            )['pair_code'],
            'device_platform' => 'windows',
        ]);
        $account = $service->createAccount($actor, [
            'device_id' => $paired['device_id'],
            'platform' => 'ctrip',
            'account_alias' => '严格回读测试账户',
            'system_hotel_id' => 102,
            'platform_hotel_id' => 'CTRIP-STRICT-102',
        ]);
        Db::name('ota_local_collector_accounts')->where('id', $account['account_id'])->update([
            'status' => 'active',
            'session_status' => 'current_session_verified',
        ]);
        if ($variant === 'mapping_data_source_mismatch') {
            Db::name('ota_local_collector_account_hotels')
                ->where('account_id', $account['account_id'])
                ->where('system_hotel_id', 102)
                ->update(['data_source_id' => 87]);
        } elseif ($variant !== 'mapping_data_source_missing') {
            Db::name('ota_local_collector_account_hotels')
                ->where('tenant_id', 12)
                ->where('account_id', $account['account_id'])
                ->where('system_hotel_id', 102)
                ->where('platform', 'ctrip')
                ->update(['data_source_id' => 88]);
        }
        $service->createTask($actor, [
            'account_id' => $account['account_id'],
            'system_hotel_id' => 102,
            'task_type' => 'collect',
            'data_date' => '2026-07-23',
        ]);
        $task = $service->nextTask($paired['device_public_id'], $paired['device_token'])['task'];
        $result = $service->submitTaskResult(
            $paired['device_public_id'],
            $paired['device_token'],
            (int)$task['id'],
            [
                'lease_token' => $task['lease_token'],
                'success' => true,
                'capture_summary' => [
                    'platform_identity_validation' => [
                        'status' => 'matched',
                        'source_validation' => true,
                        'validated_identifier' => 'CTRIP-STRICT-102',
                    ],
                ],
                'rows' => [[
                    'data_date' => '2026-07-23',
                    'platform_hotel_id' => 'CTRIP-STRICT-102',
                    'data_type' => 'business',
                    'order_amount' => 688,
                    'room_nights' => 2,
                    'order_count' => 6,
                    'list_exposure' => 120,
                    'detail_exposure' => 40,
                    'flow_rate' => 0.33,
                    'order_filling_num' => 9,
                    'order_submit_num' => 4,
                ]],
            ]
        );

        if ($expectSuccess) {
            self::assertSame('success', $result['status']);
            self::assertTrue($result['summary']['run_readback_scope_verified']);
            self::assertSame(12, $result['summary']['run_readback']['tenant_id']);
            self::assertSame([7001], $result['summary']['deterministic_readback']['row_ids']);
            return;
        }
        self::assertNotSame('success', $result['status']);
        self::assertSame('upload_failed', $result['error_code']);
    }

    /** @return array<string, array{string, bool}> */
    public static function strictRunReadbackCaseProvider(): array
    {
        return [
            'success' => ['success', true],
            'tenant mismatch' => ['tenant_mismatch', false],
            'data source mismatch' => ['data_source_mismatch', false],
            'mapping data source mismatch' => ['mapping_data_source_mismatch', false],
            'missing mapping data source fails closed' => ['mapping_data_source_missing', false],
            'sync task mismatch' => ['sync_task_mismatch', false],
            'row set mismatch' => ['row_set_mismatch', false],
            'missing importer receipt remains closed' => ['missing_receipt', false],
        ];
    }

    public function testMappingLookupFailsClosedForTenantOrPlatformAmbiguity(): void
    {
        $service = new OtaLocalCollectorService();
        $actor = $this->actor();
        $paired = $service->pairDevice([
            'pair_code' => $service->createPairCode($actor, ['device_name' => 'Mapping scope PC'])['pair_code'],
            'device_platform' => 'windows',
        ]);
        $account = $service->createAccount($actor, [
            'device_id' => $paired['device_id'],
            'platform' => 'ctrip',
            'account_alias' => 'Mapping scope account',
            'system_hotel_id' => 101,
            'platform_hotel_id' => 'CTRIP-SCOPE-101',
        ]);

        $method = new \ReflectionMethod(OtaLocalCollectorService::class, 'mappingForAccountHotel');
        $method->setAccessible(true);
        foreach ([
            [99, (int)$account['account_id'], 101, 'ctrip'],
            [12, (int)$account['account_id'], 101, 'meituan'],
        ] as [$tenantId, $accountId, $hotelId, $platform]) {
            try {
                $method->invoke($service, $tenantId, $accountId, $hotelId, $platform);
                self::fail('A mapping outside the tenant/platform scope must not be returned.');
            } catch (RuntimeException $exception) {
                self::assertSame(404, $exception->getCode());
            }
        }
    }

    public function testOneHotelCanBeUnboundWithoutChangingOtherHotelOrHistoricalFacts(): void
    {
        $service = new OtaLocalCollectorService();
        $actor = $this->actor();
        $paired = $service->pairDevice([
            'pair_code' => $service->createPairCode($actor, ['device_name' => 'Unbind PC'])['pair_code'],
            'device_name' => 'Unbind PC',
            'device_platform' => 'windows',
        ]);
        $account = $service->createAccount($actor, [
            'device_id' => $paired['device_id'],
            'platform' => 'ctrip',
            'account_alias' => 'Unbind one hotel account',
            'system_hotel_id' => 101,
            'platform_hotel_id' => 'CTRIP-UNBIND-101',
        ]);
        $secondMapping = $service->bindHotel($actor, $account['account_id'], [
            'system_hotel_id' => 102,
            'platform_hotel_id' => 'CTRIP-UNBIND-102',
        ]);
        Db::name('ota_local_collector_accounts')->where('id', $account['account_id'])->update([
            'status' => 'active',
            'session_status' => 'current_session_verified',
        ]);
        $targetMapping = Db::name('ota_local_collector_account_hotels')
            ->where('account_id', $account['account_id'])
            ->where('system_hotel_id', 101)
            ->find();
        $sourceId = (int)Db::name('platform_data_sources')->insertGetId([
            'tenant_id' => 12,
            'system_hotel_id' => 101,
            'platform' => 'ctrip',
            'data_type' => 'business',
            'ingestion_method' => 'local_collector',
            'status' => 'success',
            'enabled' => 1,
            'config_json' => '{"platform_hotel_id":"CTRIP-UNBIND-101"}',
            'last_sync_time' => '2026-07-24 09:00:00',
            'last_sync_status' => 'success',
            'last_error' => null,
        ]);
        Db::name('ota_local_collector_account_hotels')->where('id', (int)$targetMapping['id'])->update([
            'data_source_id' => $sourceId,
        ]);
        Db::name('online_daily_data')->insert([
            'system_hotel_id' => 101,
            'data_source_id' => $sourceId,
            'data_date' => '2026-07-23',
            'platform' => 'ctrip',
            'source' => 'ctrip',
            'data_type' => 'business',
            'data_period' => 'yesterday',
            'readback_verified' => 1,
            'validation_status' => 'normal',
            'amount' => 600,
        ]);

        $targetCollect = $service->createTask($actor, [
            'account_id' => $account['account_id'],
            'system_hotel_id' => 101,
            'task_type' => 'collect',
            'data_date' => '2026-07-23',
        ]);
        $targetBackfill = $service->createTask($actor, [
            'account_id' => $account['account_id'],
            'system_hotel_id' => 101,
            'task_type' => 'backfill',
            'data_date' => '2026-07-22',
            'missing_field_keys' => ['traffic.list_exposure'],
        ]);
        $otherCollect = $service->createTask($actor, [
            'account_id' => $account['account_id'],
            'system_hotel_id' => 102,
            'task_type' => 'collect',
            'data_date' => '2026-07-23',
        ]);
        $targetLogin = $service->createTask($actor, [
            'account_id' => $account['account_id'],
            'system_hotel_id' => 101,
            'task_type' => 'login',
        ]);

        $result = $service->unbindHotel($actor, (int)$account['account_id'], 101);

        self::assertSame('unbound', $result['status']);
        self::assertTrue($result['readback_verified']);
        self::assertFalse($result['already_unbound']);
        self::assertSame('unbound', $result['mapping_status']);
        self::assertSame(3, $result['cancelled_task_count']);
        self::assertSame($sourceId, $result['data_source_id']);
        self::assertSame('disabled', $result['data_source_status']);
        self::assertSame(1, $result['remaining_active_hotel_count']);
        self::assertSame('cancelled', Db::name('ota_local_collector_tasks')
            ->where('id', (int)$targetCollect['task']['id'])->value('status'));
        self::assertSame('cancelled', Db::name('ota_local_collector_tasks')
            ->where('id', (int)$targetBackfill['task']['id'])->value('status'));
        self::assertSame('queued', Db::name('ota_local_collector_tasks')
            ->where('id', (int)$otherCollect['task']['id'])->value('status'));
        self::assertSame('cancelled', Db::name('ota_local_collector_tasks')
            ->where('id', (int)$targetLogin['task']['id'])->value('status'));
        self::assertSame('active', Db::name('ota_local_collector_account_hotels')
            ->where('id', (int)$secondMapping['mapping_id'])->value('status'));
        self::assertSame(0, (int)Db::name('platform_data_sources')->where('id', $sourceId)->value('enabled'));
        self::assertSame('disabled', Db::name('platform_data_sources')->where('id', $sourceId)->value('status'));
        self::assertSame('2026-07-24 09:00:00', Db::name('platform_data_sources')->where('id', $sourceId)->value('last_sync_time'));
        self::assertSame(1, (int)Db::name('online_daily_data')->where('data_source_id', $sourceId)->count());

        $status = $service->status($actor);
        self::assertCount(1, $status['accounts'][0]['hotels']);
        self::assertSame(102, (int)$status['accounts'][0]['hotels'][0]['system_hotel_id']);
        $next = $service->nextTask($paired['device_public_id'], $paired['device_token']);
        self::assertSame('leased', $next['status']);
        self::assertSame(102, (int)$next['task']['system_hotel_id']);

        $retry = $service->unbindHotel($actor, (int)$account['account_id'], 101);
        self::assertSame('unbound', $retry['status']);
        self::assertTrue($retry['already_unbound']);
        self::assertSame(0, $retry['cancelled_task_count']);
        self::assertSame((int)$targetMapping['id'], $retry['mapping_id']);
        self::assertSame(2, (int)Db::name('ota_local_collector_account_hotels')->count());

        $rebound = $service->bindHotel($actor, (int)$account['account_id'], [
            'system_hotel_id' => 101,
            'platform_hotel_id' => 'CTRIP-UNBIND-101',
        ]);
        self::assertSame((int)$targetMapping['id'], $rebound['mapping_id']);
        self::assertTrue($rebound['mapping_readback']['readback_verified']);
        self::assertSame(101, $rebound['mapping_readback']['system_hotel_id']);
        self::assertSame('active', Db::name('ota_local_collector_account_hotels')
            ->where('id', (int)$targetMapping['id'])->value('status'));
        self::assertCount(2, $service->status($actor)['accounts'][0]['hotels']);
    }

    public function testHotelUnbindRequiresCurrentHotelPermissionAndAccountOwnership(): void
    {
        $service = new OtaLocalCollectorService();
        $actor = $this->actor();
        $paired = $service->pairDevice([
            'pair_code' => $service->createPairCode($actor, ['device_name' => 'Unbind scope PC'])['pair_code'],
            'device_platform' => 'windows',
        ]);
        $account = $service->createAccount($actor, [
            'device_id' => $paired['device_id'],
            'platform' => 'meituan',
            'account_alias' => 'Unbind scope account',
            'system_hotel_id' => 101,
            'platform_hotel_id' => 'MT-UNBIND-101',
        ]);

        try {
            $service->unbindHotel($this->actorWithHotels([102]), (int)$account['account_id'], 101);
            self::fail('actor without current hotel permission unexpectedly unbound mapping');
        } catch (RuntimeException $exception) {
            self::assertSame(403, $exception->getCode());
        }
        self::assertSame('active', Db::name('ota_local_collector_account_hotels')
            ->where('account_id', $account['account_id'])->value('status'));

        $otherOwner = new class {
            public int $id = 8;
            public int $tenant_id = 12;

            public function getPermittedHotelIds(): array
            {
                return [101, 102];
            }

            public function isSuperAdmin(): bool
            {
                return false;
            }
        };
        try {
            $service->unbindHotel($otherOwner, (int)$account['account_id'], 101);
            self::fail('another account owner unexpectedly unbound mapping');
        } catch (RuntimeException $exception) {
            self::assertSame(404, $exception->getCode());
        }
        self::assertSame('active', Db::name('ota_local_collector_account_hotels')
            ->where('account_id', $account['account_id'])->value('status'));
    }

    public function testUnboundHotelCanBeReassignedToAnotherOwnedAccountWithoutLosingHistory(): void
    {
        $service = new OtaLocalCollectorService();
        $actor = $this->actor();
        $paired = $service->pairDevice([
            'pair_code' => $service->createPairCode($actor, ['device_name' => 'Reassign PC'])['pair_code'],
            'device_name' => 'Reassign PC',
            'device_platform' => 'windows',
        ]);
        $first = $service->createAccount($actor, [
            'device_id' => $paired['device_id'],
            'platform' => 'ctrip',
            'account_alias' => 'Original Ctrip account',
            'system_hotel_id' => 101,
            'platform_hotel_id' => 'CTRIP-REASSIGN-101',
        ]);
        $second = $service->createAccount($actor, [
            'device_id' => $paired['device_id'],
            'platform' => 'ctrip',
            'account_alias' => 'Replacement Ctrip account',
            'system_hotel_id' => 102,
            'platform_hotel_id' => 'CTRIP-REASSIGN-102',
        ]);
        $mapping = Db::name('ota_local_collector_account_hotels')
            ->where('account_id', $first['account_id'])
            ->where('system_hotel_id', 101)
            ->find();
        $sourceId = (int)Db::name('platform_data_sources')->insertGetId([
            'tenant_id' => 12,
            'system_hotel_id' => 101,
            'platform' => 'ctrip',
            'data_type' => 'business',
            'ingestion_method' => 'local_collector',
            'status' => 'success',
            'enabled' => 1,
            'config_json' => '{"platform_hotel_id":"CTRIP-REASSIGN-101"}',
            'last_sync_status' => 'success',
        ]);
        Db::name('ota_local_collector_account_hotels')->where('id', (int)$mapping['id'])->update([
            'data_source_id' => $sourceId,
        ]);
        Db::name('online_daily_data')->insert([
            'system_hotel_id' => 101,
            'data_source_id' => $sourceId,
            'data_date' => '2026-07-31',
            'platform' => 'ctrip',
            'source' => 'ctrip',
            'data_type' => 'business',
            'data_period' => 'yesterday',
            'readback_verified' => 1,
            'validation_status' => 'normal',
            'amount' => 520,
        ]);

        $unbound = $service->unbindHotel($actor, (int)$first['account_id'], 101);
        self::assertSame('unbound', $unbound['status']);
        self::assertTrue($unbound['readback_verified']);

        try {
            $service->bindHotel($actor, (int)$second['account_id'], [
                'system_hotel_id' => 101,
                'platform_hotel_id' => 'CTRIP-WRONG-IDENTITY',
            ]);
            self::fail('Historical OTA identity was silently replaced during reassignment');
        } catch (RuntimeException $exception) {
            self::assertSame(409, $exception->getCode());
            self::assertStringContainsString('必须保持原 OTA 平台门店标识', $exception->getMessage());
        }
        self::assertSame(2, (int)Db::name('ota_local_collector_account_hotels')->count());

        $reassigned = $service->bindHotel($actor, (int)$second['account_id'], [
            'system_hotel_id' => 101,
            'platform_hotel_id' => 'CTRIP-REASSIGN-101',
        ]);

        self::assertSame('bound', $reassigned['status']);
        self::assertSame('reassigned', $reassigned['write_action']);
        self::assertTrue($reassigned['readback_verified']);
        self::assertNotSame((int)$mapping['id'], $reassigned['mapping_id']);
        self::assertSame((int)$mapping['id'], $reassigned['previous_mapping_id']);
        self::assertSame((int)$first['account_id'], $reassigned['previous_account_id']);
        self::assertSame((int)$second['account_id'], $reassigned['account_id']);
        self::assertSame('active', $reassigned['mapping_status']);
        self::assertSame(0, $reassigned['data_source_id']);
        self::assertSame(3, (int)Db::name('ota_local_collector_account_hotels')->count());

        $historicalMapping = Db::name('ota_local_collector_account_hotels')
            ->where('id', (int)$mapping['id'])
            ->find();
        self::assertIsArray($historicalMapping);
        self::assertSame((int)$first['account_id'], (int)$historicalMapping['account_id']);
        self::assertSame('unbound', $historicalMapping['status']);
        self::assertSame($sourceId, (int)$historicalMapping['data_source_id']);
        self::assertSame('CTRIP-REASSIGN-101', $historicalMapping['platform_hotel_id']);

        $activeMapping = Db::name('ota_local_collector_account_hotels')
            ->where('id', (int)$reassigned['mapping_id'])
            ->find();
        self::assertIsArray($activeMapping);
        self::assertSame((int)$second['account_id'], (int)$activeMapping['account_id']);
        self::assertSame('active', $activeMapping['status']);
        self::assertSame(0, (int)$activeMapping['data_source_id']);
        self::assertSame(1, (int)Db::name('ota_local_collector_account_hotels')
            ->where('tenant_id', 12)
            ->where('system_hotel_id', 101)
            ->where('platform', 'ctrip')
            ->where('status', 'active')
            ->count());
        self::assertSame(1, (int)Db::name('online_daily_data')->where('data_source_id', $sourceId)->count());
        self::assertSame(0, (int)Db::name('platform_data_sources')->where('id', $sourceId)->value('enabled'));
        self::assertSame(
            '{"platform_hotel_id":"CTRIP-REASSIGN-101"}',
            Db::name('platform_data_sources')->where('id', $sourceId)->value('config_json')
        );

        $status = $service->status($actor);
        $accountsById = [];
        foreach ($status['accounts'] as $account) {
            $accountsById[(int)$account['id']] = $account;
        }
        self::assertCount(0, $accountsById[(int)$first['account_id']]['hotels']);
        self::assertCount(2, $accountsById[(int)$second['account_id']]['hotels']);

        $repeatedUnbind = $service->unbindHotel($actor, (int)$first['account_id'], 101);
        self::assertSame('unbound', $repeatedUnbind['status']);
        self::assertTrue($repeatedUnbind['already_unbound']);
        self::assertTrue($repeatedUnbind['readback_verified']);
        self::assertSame('unbound', $repeatedUnbind['mapping_readback']['mapping_status']);
    }

    public function testRevokedAccountCanBeRestoredOnANewOwnedDeviceWithoutDuplicatingMappings(): void
    {
        $service = new OtaLocalCollectorService();
        $actor = $this->actor();
        $firstPair = $service->createPairCode($actor, ['device_name' => 'Old PC']);
        $firstDevice = $service->pairDevice([
            'pair_code' => $firstPair['pair_code'],
            'device_name' => 'Old PC',
            'device_platform' => 'windows',
        ]);
        $created = $service->createAccount($actor, [
            'device_id' => $firstDevice['device_id'],
            'platform' => 'ctrip',
            'account_alias' => '区域携程账户',
            'system_hotel_id' => 101,
            'platform_hotel_id' => 'CTRIP-101',
        ]);
        $service->bindHotel($actor, $created['account_id'], [
            'system_hotel_id' => 102,
            'platform_hotel_id' => 'CTRIP-102',
        ]);
        $service->revokeDevice($actor, $firstDevice['device_id']);

        $secondPair = $service->createPairCode($actor, ['device_name' => 'New PC']);
        $secondDevice = $service->pairDevice([
            'pair_code' => $secondPair['pair_code'],
            'device_name' => 'New PC',
            'device_platform' => 'windows',
        ]);
        $restored = $service->createAccount($actor, [
            'device_id' => $secondDevice['device_id'],
            'platform' => 'ctrip',
            'account_alias' => '区域携程账户',
            'system_hotel_id' => 101,
            'platform_hotel_id' => 'CTRIP-101',
        ]);

        self::assertSame('restored', $restored['status']);
        self::assertSame($created['account_id'], $restored['account_id']);
        self::assertSame(1, Db::name('ota_local_collector_accounts')->count());
        self::assertSame(2, Db::name('ota_local_collector_account_hotels')->count());
        $account = Db::name('ota_local_collector_accounts')->where('id', $created['account_id'])->find();
        self::assertSame($secondDevice['device_id'], (int)$account['device_id']);
        self::assertSame('login_required', $account['session_status']);
        self::assertStringContainsString('新电脑', $restored['next_action']);
    }

    public function testActiveHotelMappingCannotBeClaimedByAnotherAccount(): void
    {
        $service = new OtaLocalCollectorService();
        $actor = $this->actor();
        $paired = $service->pairDevice([
            'pair_code' => $service->createPairCode($actor, ['device_name' => 'Conflict PC'])['pair_code'],
            'device_name' => 'Conflict PC',
            'device_platform' => 'windows',
        ]);
        $first = $service->createAccount($actor, [
            'device_id' => $paired['device_id'],
            'platform' => 'ctrip',
            'account_alias' => 'Active owner',
            'system_hotel_id' => 101,
            'platform_hotel_id' => 'CTRIP-ACTIVE-101',
        ]);
        $second = $service->createAccount($actor, [
            'device_id' => $paired['device_id'],
            'platform' => 'ctrip',
            'account_alias' => 'Competing owner',
            'system_hotel_id' => 102,
            'platform_hotel_id' => 'CTRIP-ACTIVE-102',
        ]);

        try {
            $service->bindHotel($actor, (int)$second['account_id'], [
                'system_hotel_id' => 101,
                'platform_hotel_id' => 'CTRIP-COMPETING-101',
            ]);
            self::fail('An active hotel mapping was claimed by another account');
        } catch (RuntimeException $exception) {
            self::assertSame(409, $exception->getCode());
            self::assertStringContainsString('请先解绑原映射', $exception->getMessage());
        }

        self::assertSame(2, (int)Db::name('ota_local_collector_account_hotels')->count());
        self::assertSame(1, (int)Db::name('ota_local_collector_account_hotels')
            ->where('account_id', (int)$first['account_id'])
            ->where('system_hotel_id', 101)
            ->where('status', 'active')
            ->count());
        self::assertSame(0, (int)Db::name('ota_local_collector_account_hotels')
            ->where('account_id', (int)$second['account_id'])
            ->where('system_hotel_id', 101)
            ->count());
    }

    public function testOnePlatformHotelIdentityCannotBeMappedToTwoSystemHotels(): void
    {
        $service = new OtaLocalCollectorService();
        $actor = $this->actor();
        $pair = $service->createPairCode($actor, ['device_name' => 'Owner PC']);
        $device = $service->pairDevice([
            'pair_code' => $pair['pair_code'],
            'device_name' => 'Owner PC',
            'device_platform' => 'windows',
        ]);
        $account = $service->createAccount($actor, [
            'device_id' => $device['device_id'],
            'platform' => 'meituan',
            'account_alias' => '区域美团账户',
            'system_hotel_id' => 101,
            'platform_hotel_id' => 'POI-ONE',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(409);
        $this->expectExceptionMessage('已映射到其他宿析门店');
        $service->bindHotel($actor, $account['account_id'], [
            'system_hotel_id' => 102,
            'platform_hotel_id' => 'POI-ONE',
        ]);
    }

    public function testStatusHidesMappingsAndTasksOutsideCurrentHotelPermission(): void
    {
        $service = new OtaLocalCollectorService();
        $actor = $this->actor();
        $pair = $service->createPairCode($actor, ['device_name' => 'Owner PC']);
        $device = $service->pairDevice([
            'pair_code' => $pair['pair_code'],
            'device_name' => 'Owner PC',
            'device_platform' => 'windows',
        ]);
        $account = $service->createAccount($actor, [
            'device_id' => $device['device_id'],
            'platform' => 'ctrip',
            'account_alias' => '权限收缩账户',
            'system_hotel_id' => 101,
            'platform_hotel_id' => 'CTRIP-101',
        ]);
        $service->bindHotel($actor, $account['account_id'], [
            'system_hotel_id' => 102,
            'platform_hotel_id' => 'CTRIP-102',
        ]);
        $service->createTask($actor, [
            'account_id' => $account['account_id'],
            'system_hotel_id' => 102,
            'task_type' => 'login',
        ]);

        $limitedActor = $this->actorWithHotels([101]);
        $status = $service->status($limitedActor);

        self::assertCount(1, $status['accounts'][0]['hotels']);
        self::assertSame(101, (int)$status['accounts'][0]['hotels'][0]['system_hotel_id']);
        self::assertSame([], $status['tasks']);
    }

    public function testUnverifiedPostCaptureHotelIdentityStopsBeforeAnyImporterWrite(): void
    {
        $importCalls = 0;
        $service = new OtaLocalCollectorService(
            static function () use (&$importCalls): array {
                $importCalls++;
                return ['status' => 'success'];
            }
        );
        $actor = $this->actor();
        $pairCode = $service->createPairCode($actor, ['device_name' => 'Identity Guard PC']);
        $paired = $service->pairDevice([
            'pair_code' => $pairCode['pair_code'],
            'device_platform' => 'windows',
        ]);
        $account = $service->createAccount($actor, [
            'device_id' => $paired['device_id'],
            'platform' => 'ctrip',
            'account_alias' => 'Identity guard account',
            'system_hotel_id' => 101,
            'platform_hotel_id' => 'CTRIP-IDENTITY-101',
        ]);
        Db::name('ota_local_collector_accounts')->where('id', $account['account_id'])->update([
            'status' => 'active',
            'session_status' => 'current_session_verified',
        ]);
        $service->createTask($actor, [
            'account_id' => $account['account_id'],
            'system_hotel_id' => 101,
            'task_type' => 'collect',
            'data_date' => '2026-07-23',
        ]);
        $task = $service->nextTask(
            $paired['device_public_id'],
            $paired['device_token']
        )['task'];
        $result = $service->submitTaskResult(
            $paired['device_public_id'],
            $paired['device_token'],
            (int)$task['id'],
            [
                'lease_token' => $task['lease_token'],
                'success' => true,
                'rows' => [[
                    'data_date' => '2026-07-23',
                    // A row echo is intentionally insufficient identity proof.
                    'platform_hotel_id' => 'CTRIP-IDENTITY-101',
                    'data_type' => 'business',
                    'order_amount' => 1,
                ]],
            ]
        );

        self::assertSame('retry_wait', $result['status']);
        self::assertSame('identity_unverified', $result['error_code']);
        self::assertSame(0, $importCalls);
        self::assertNull(
            Db::name('ota_local_collector_tasks')->where('id', (int)$task['id'])->value('result_summary_json')
        );
    }

    public function testManualBackfillCreatesOneNewAttemptAfterAutomaticRetriesAreExhausted(): void
    {
        $service = new OtaLocalCollectorService();
        $actor = $this->actor();
        $pairCode = $service->createPairCode($actor, ['device_name' => 'Retry Fixture PC']);
        $paired = $service->pairDevice([
            'pair_code' => $pairCode['pair_code'],
            'device_platform' => 'windows',
            'collector_version' => 'test',
        ]);
        $created = $service->createAccount($actor, [
            'device_id' => $paired['device_id'],
            'platform' => 'ctrip',
            'account_alias' => '重试测试账户',
            'system_hotel_id' => 101,
            'platform_hotel_id' => 'CTRIP-RETRY-101',
        ]);
        Db::name('ota_local_collector_accounts')->where('id', $created['account_id'])->update([
            'status' => 'active',
            'session_status' => 'current_session_verified',
        ]);

        $original = $service->createTask($actor, [
            'account_id' => $created['account_id'],
            'system_hotel_id' => 101,
            'task_type' => 'backfill',
            'data_date' => '2026-07-20',
        ]);
        $originalTaskId = (int)$original['task']['id'];
        Db::name('ota_local_collector_tasks')->where('id', $originalTaskId)->update([
            'status' => 'failed',
            'attempt' => 3,
            'max_attempts' => 3,
            'error_code' => 'network_error',
            'error_summary' => 'fixture: automatic retries exhausted',
            'finished_at' => '2026-07-24 12:00:00',
        ]);
        Db::name('ota_local_collector_accounts')->where('id', $created['account_id'])->update([
            'status' => 'failed',
            'last_error_code' => 'network_error',
            'last_error_summary' => 'fixture: automatic retries exhausted',
            'retry_count' => 3,
            'next_retry_at' => null,
        ]);

        $manualRequest = [
            'account_id' => $created['account_id'],
            'system_hotel_id' => 101,
            'task_type' => 'backfill',
            'data_date' => '2026-07-20',
            'reason' => 'manual_backfill',
        ];
        $manualRetry = $service->createTask($actor, $manualRequest);
        $manualTaskId = (int)$manualRetry['task']['id'];

        self::assertSame('queued', $manualRetry['status']);
        self::assertNotSame($originalTaskId, $manualTaskId);
        self::assertSame(0, (int)$manualRetry['task']['attempt']);
        self::assertSame('failed', Db::name('ota_local_collector_tasks')->where('id', $originalTaskId)->value('status'));

        $storedRetry = Db::name('ota_local_collector_tasks')->where('id', $manualTaskId)->find();
        $request = json_decode((string)$storedRetry['request_json'], true);
        self::assertSame('manual', $request['retry_trigger']);
        self::assertSame($originalTaskId, $request['retry_of_task_id']);

        $account = Db::name('ota_local_collector_accounts')->where('id', $created['account_id'])->find();
        self::assertSame('active', $account['status']);
        self::assertSame('current_session_verified', $account['session_status']);
        self::assertSame('', $account['last_error_code']);
        self::assertSame('', $account['last_error_summary']);
        self::assertSame(0, (int)$account['retry_count']);
        self::assertNull($account['next_retry_at']);

        $sameQueuedRetry = $service->createTask($actor, $manualRequest);
        self::assertSame($manualTaskId, (int)$sameQueuedRetry['task']['id']);
        self::assertSame('queued', $sameQueuedRetry['status']);
        self::assertSame(2, Db::name('ota_local_collector_tasks')->count());

        $leasedRetry = $service->nextTask($paired['device_public_id'], $paired['device_token']);
        self::assertSame($manualTaskId, (int)$leasedRetry['task']['id']);
        self::assertSame('leased', $leasedRetry['status']);
        self::assertSame(1, (int)$leasedRetry['task']['attempt']);

        $sameManualRetry = $service->createTask($actor, $manualRequest);
        self::assertSame($manualTaskId, (int)$sameManualRetry['task']['id']);
        self::assertSame('leased', $sameManualRetry['status']);
        self::assertSame(2, Db::name('ota_local_collector_tasks')->count());
    }

    public function testUnverifiedCollectionQueuesOneSessionPreflightAndAutomaticallyResumesTargetDate(): void
    {
        $service = new OtaLocalCollectorService();
        $actor = $this->actor();
        $pairCode = $service->createPairCode($actor, ['device_name' => 'Session Fixture PC']);
        $paired = $service->pairDevice([
            'pair_code' => $pairCode['pair_code'],
            'device_platform' => 'windows',
        ]);
        $created = $service->createAccount($actor, [
            'device_id' => $paired['device_id'],
            'platform' => 'ctrip',
            'account_alias' => '会话前置账户',
            'system_hotel_id' => 101,
            'platform_hotel_id' => 'CTRIP-SESSION-101',
        ]);

        $request = [
            'account_id' => $created['account_id'],
            'system_hotel_id' => 101,
            'task_type' => 'collect',
            'data_date' => '2026-07-23',
        ];
        $preflight = $service->createTask($actor, $request);
        $samePreflight = $service->createTask($actor, $request);

        self::assertSame('session_probe', $preflight['task']['task_type']);
        self::assertSame((int)$preflight['task']['id'], (int)$samePreflight['task']['id']);
        self::assertSame(1, $preflight['task']['request_summary']['resume_collection_count']);
        self::assertSame(1, Db::name('ota_local_collector_tasks')->count());

        $leased = $service->nextTask($paired['device_public_id'], $paired['device_token']);
        self::assertSame('session_probe', $leased['task']['task_type']);
        $loginResult = $service->submitTaskResult(
            $paired['device_public_id'],
            $paired['device_token'],
            $leased['task']['id'],
            [
                'lease_token' => $leased['task']['lease_token'],
                'success' => true,
                'session_status' => 'current_session_verified',
            ]
        );
        self::assertSame('success', $loginResult['status']);
        self::assertSame('queued', $loginResult['summary']['resume_status']);

        $collection = $service->nextTask($paired['device_public_id'], $paired['device_token']);
        self::assertSame('collect', $collection['task']['task_type']);
        self::assertSame('2026-07-23', $collection['task']['data_date']);
        self::assertSame(
            ['business_overview', 'traffic_report'],
            $collection['task']['request']['sections']
        );
        self::assertArrayNotHasKey('ordered_collection', $collection['task']['request']);
        self::assertSame(2, Db::name('ota_local_collector_tasks')->count());

        Db::name('ota_local_collector_accounts')->where('id', $created['account_id'])->update([
            'session_status' => 'login_required',
        ]);
        $freshPreflight = $service->createTask($actor, [
            'account_id' => $created['account_id'],
            'system_hotel_id' => 101,
            'task_type' => 'collect',
            'data_date' => '2026-07-22',
        ]);
        self::assertSame('session_probe', $freshPreflight['task']['task_type']);
        self::assertNotSame((int)$preflight['task']['id'], (int)$freshPreflight['task']['id']);
        self::assertSame(3, Db::name('ota_local_collector_tasks')->count());
    }

    public function testCollectAndBackfillShareOneActiveScopeAndStatusExposesDeterministicQueueGate(): void
    {
        $service = new OtaLocalCollectorService(
            null,
            null,
            static fn(int $hotelId, string $startDate, string $endDate): array => [
                'hotel_id' => $hotelId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'status' => 'partial',
            ]
        );
        $actor = $this->actor();
        $pairCode = $service->createPairCode($actor, ['device_name' => 'Ordered Queue PC']);
        $paired = $service->pairDevice([
            'pair_code' => $pairCode['pair_code'],
            'device_platform' => 'windows',
        ]);
        $ctrip = $service->createAccount($actor, [
            'device_id' => $paired['device_id'],
            'platform' => 'ctrip',
            'account_alias' => '有序携程账户',
            'system_hotel_id' => 101,
            'platform_hotel_id' => 'CTRIP-ORDERED-101',
            'platform_hotel_name' => 'Hotel A',
        ]);
        $meituan = $service->createAccount($actor, [
            'device_id' => $paired['device_id'],
            'platform' => 'meituan',
            'account_alias' => '有序美团账户',
            'system_hotel_id' => 101,
            'platform_hotel_id' => 'MT-ORDERED-101',
            'platform_hotel_name' => 'Hotel A',
        ]);
        Db::name('ota_local_collector_accounts')->whereIn('id', [
            $ctrip['account_id'],
            $meituan['account_id'],
        ])->update([
            'status' => 'active',
            'session_status' => 'current_session_verified',
        ]);

        $ctripCollect = $service->createTask($actor, [
            'account_id' => $ctrip['account_id'],
            'system_hotel_id' => 101,
            'task_type' => 'collect',
            'data_date' => '2026-07-23',
        ]);
        $sameScope = $service->createTask($actor, [
            'account_id' => $ctrip['account_id'],
            'system_hotel_id' => 101,
            'task_type' => 'backfill',
            'data_date' => '2026-07-23',
        ]);
        self::assertSame((int)$ctripCollect['task']['id'], (int)$sameScope['task']['id']);

        $meituanTask = $service->createTask($actor, [
            'account_id' => $meituan['account_id'],
            'system_hotel_id' => 101,
            'task_type' => 'collect',
            'data_date' => '2026-07-23',
        ]);
        Db::name('ota_local_collector_tasks')->where('id', (int)$ctripCollect['task']['id'])->update([
            'status' => 'running',
        ]);

        $status = $service->status($actor);
        $ordered = $status['ordered_collection'];
        self::assertSame(date('Y-m-d', strtotime('-1 day')), $ordered['target_date']);
        self::assertSame((int)$ctripCollect['task']['id'], (int)$ordered['current']['task_id']);
        self::assertSame((int)$meituanTask['task']['id'], (int)$ordered['next']['task_id']);
        self::assertSame('等待双 OTA 保存、回读与 P0 验证', $ordered['gate']['label']);
        self::assertFalse($ordered['gate']['ready']);
        self::assertSame(
            'blocked_by_p0_ota_gate',
            $ordered['gate']['hotel_states'][0]['p0_downstream_gate_status']
        );
        self::assertSame(
            ['account', 'hotel', 'platform', 'target_date', 'field_completeness'],
            $ordered['order_by']
        );
        self::assertCount(2, $ordered['queue']);
    }

    public function testStatusUsesExistingBrowserProfilesWhenOptionalLocalCollectorIsNotRegistered(): void
    {
        $targetDate = date('Y-m-d', strtotime('-1 day'));
        $ctripSourceId = (int)Db::name('platform_data_sources')->insertGetId([
            'tenant_id' => 12,
            'system_hotel_id' => 101,
            'platform' => 'ctrip',
            'data_type' => 'business',
            'ingestion_method' => 'browser_profile',
            'status' => 'success',
            'enabled' => 1,
            'config_json' => json_encode(['profile_id' => 'ctrip-profile-a'], JSON_THROW_ON_ERROR),
            'last_sync_time' => '2026-07-25 08:40:00',
        ]);
        $meituanSourceId = (int)Db::name('platform_data_sources')->insertGetId([
            'tenant_id' => 12,
            'system_hotel_id' => 101,
            'platform' => 'meituan',
            'data_type' => 'business',
            'ingestion_method' => 'browser_profile',
            'status' => 'partial_success',
            'enabled' => 1,
            'config_json' => json_encode(['store_id' => 'meituan-store-a'], JSON_THROW_ON_ERROR),
            'last_sync_time' => '2026-07-25 08:41:00',
        ]);
        foreach ([
            [
                'system_hotel_id' => 101,
                'data_source_id' => $ctripSourceId,
                'data_date' => $targetDate,
                'platform' => 'ctrip',
                'source' => 'ctrip',
                'data_type' => 'business',
                'data_period' => 'historical_daily',
                'readback_verified' => 1,
                'validation_status' => 'valid',
                'compare_type' => 'self',
                'amount' => 680,
                'quantity' => 3,
                'book_order_num' => 1,
            ],
            [
                'system_hotel_id' => 101,
                'data_source_id' => $ctripSourceId,
                'data_date' => $targetDate,
                'platform' => 'ctrip',
                'source' => 'ctrip',
                'data_type' => 'traffic',
                'data_period' => 'historical_daily',
                'readback_verified' => 1,
                'validation_status' => 'valid',
                'compare_type' => 'self',
                'dimension' => 'catalog:business:business_flow_transform',
                'list_exposure' => 100,
                'detail_exposure' => 20,
                'flow_rate' => 0.2,
                'order_filling_num' => 8,
                'order_submit_num' => 4,
            ],
            [
                'system_hotel_id' => 101,
                'data_source_id' => $meituanSourceId,
                'data_date' => $targetDate,
                'platform' => 'meituan',
                'source' => 'meituan',
                'data_type' => 'order',
                'data_period' => 'historical_daily',
                'readback_verified' => 1,
                'validation_status' => 'valid',
                'compare_type' => 'self',
                'amount' => 520,
                'quantity' => 2,
                'book_order_num' => 1,
            ],
        ] as $row) {
            Db::name('online_daily_data')->insert($row);
        }
        Db::name('platform_data_sync_tasks')->insert([
            'tenant_id' => 12,
            'data_source_id' => $ctripSourceId,
            'system_hotel_id' => 101,
            'platform' => 'ctrip',
            'status' => 'success',
            'stats_json' => json_encode([
                'ordered_collection' => ['target_date' => $targetDate],
                'run_readback' => [
                    'sync_task_id' => 801,
                    'data_source_id' => $ctripSourceId,
                    'system_hotel_id' => 101,
                    'platform' => 'ctrip',
                    'target_date' => $targetDate,
                    'readback_verified' => true,
                    'p0_status' => 'ready',
                    'started_at' => '2026-07-25 08:40:00',
                    'row_ids' => [1, 2],
                    'source_trace_ids' => ['trace-a'],
                    'verified_metric_keys' => ['revenue', 'room_nights', 'adr'],
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        $service = new OtaLocalCollectorService(
            null,
            null,
            static fn(): array => [
                'status' => 'partial',
                'reason' => 'meituan_target_date_traffic_rows_missing',
            ],
            null,
            static fn(): array => [
                'status' => 'blocked_by_p0_ota_gate',
                'blocking_missing_inputs' => ['meituan_target_date_traffic_rows'],
            ]
        );
        $status = $service->status($this->actor());
        $ordered = $status['ordered_collection'];

        self::assertSame('browser_profile', $status['collection_mode']);
        self::assertFalse($status['local_collector_required']);
        self::assertSame('not_registered_optional', $status['local_collector_status']);
        self::assertSame(2, $status['summary']['browser_profile_source_count']);
        self::assertSame('browser_profile', $ordered['source_mode']);
        self::assertFalse($ordered['local_collector_required']);
        self::assertSame(['ctrip', 'meituan'], $ordered['gate']['hotel_states'][0]['mapped_platforms']);
        self::assertNotContains('local_account_hotel_binding_missing', $ordered['gap_report']['gap_codes']);
        self::assertCount(2, $ordered['queue']);
        self::assertSame('meituan', $ordered['next']['platform']);
        self::assertArrayNotHasKey('missing_field_keys', $ordered['next']);
        self::assertSame(['traffic'], $ordered['next']['sections']);
        self::assertSame('redacted', $ordered['implementation_visibility']);
    }

    public function testSecondPlatformSuccessRunsAndPersistsTheExactDateAuthorityVerifierReceipt(): void
    {
        $targetDate = date('Y-m-d', strtotime('-1 day'));
        Cache::delete("online_data_p0_authority_receipt_101_{$targetDate}");
        Cache::delete("online_data_historical_executed_101_{$targetDate}");
        $verifierCalls = 0;
        $service = new OtaLocalCollectorService(
            static function (
                $owner,
                array $task,
                array $account,
                array $mapping,
                array $device,
                array $rows
            ): array {
                $platform = (string)$task['platform'];
                $sourceId = $platform === 'ctrip' ? 201 : 202;
                $syncTaskId = $platform === 'ctrip' ? 301 : 302;
                Db::name('ota_local_collector_account_hotels')
                    ->where('id', (int)$mapping['id'])
                    ->where('tenant_id', (int)$task['tenant_id'])
                    ->where('account_id', (int)$task['account_id'])
                    ->where('system_hotel_id', (int)$task['system_hotel_id'])
                    ->where('platform', $platform)
                    ->update(['data_source_id' => $sourceId]);
                Db::name('platform_data_sources')->insert([
                    'id' => $sourceId,
                    'tenant_id' => (int)$task['tenant_id'],
                    'system_hotel_id' => (int)$task['system_hotel_id'],
                    'platform' => $platform,
                    'data_type' => 'business',
                    'ingestion_method' => 'local_collector',
                    'status' => 'active',
                    'enabled' => 1,
                ]);
                Db::name('online_daily_data')->insert([
                    'id' => $platform === 'ctrip' ? 401 : 402,
                    'tenant_id' => (int)$task['tenant_id'],
                    'system_hotel_id' => (int)$task['system_hotel_id'],
                    'data_source_id' => $sourceId,
                    'sync_task_id' => $syncTaskId,
                    'data_date' => (string)$task['data_date'],
                    'platform' => $platform,
                    'source' => $platform,
                    'data_type' => 'business',
                    'data_period' => 'historical_daily',
                    'readback_verified' => 1,
                    'validation_status' => 'valid',
                    'amount' => 688,
                    'quantity' => 2,
                    'book_order_num' => 6,
                    'list_exposure' => 120,
                    'detail_exposure' => 40,
                    'flow_rate' => 0.33,
                    'order_filling_num' => 9,
                    'order_submit_num' => 4,
                ]);
                return [
                    'status' => 'success',
                    'data_source_id' => $sourceId,
                    'task_id' => $syncTaskId,
                    'normalized_count' => count($rows),
                    'saved_count' => count($rows),
                    'readback_count' => count($rows),
                    'readback_verified' => true,
                    'run_readback' => [
                        'tenant_id' => (int)$task['tenant_id'],
                        'data_source_id' => $sourceId,
                        'sync_task_id' => $syncTaskId,
                        'system_hotel_id' => (int)$task['system_hotel_id'],
                        'target_date' => (string)$task['data_date'],
                        'platform' => $platform,
                        'readback_count' => 1,
                        'readback_verified' => true,
                        'p0_status' => 'ready',
                        'row_ids' => [$platform === 'ctrip' ? 401 : 402],
                    ],
                    'deterministic_readback' => [
                        'tenant_id' => (int)$task['tenant_id'],
                        'data_source_id' => $sourceId,
                        'sync_task_id' => $syncTaskId,
                        'system_hotel_id' => (int)$task['system_hotel_id'],
                        'target_date' => (string)$task['data_date'],
                        'platform' => $platform,
                        'readback_count' => 1,
                        'readback_verified' => true,
                        'row_ids' => [$platform === 'ctrip' ? 401 : 402],
                    ],
                    'sync_diagnostics' => [
                        'target_date' => (string)$task['data_date'],
                        'requires_target_date_traffic' => true,
                        'p0_status' => 'ready',
                    ],
                ];
            },
            null,
            static fn(int $hotelId, string $startDate, string $endDate): array => [
                'hotel_id' => $hotelId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'status' => 'verified',
            ],
            static function (
                int $hotelId,
                string $date,
                array $platforms,
                string $collectionAnchorHash
            ) use (&$verifierCalls, $targetDate): array {
                $verifierCalls++;
                self::assertSame(101, $hotelId);
                self::assertSame($targetDate, $date);
                self::assertSame(['ctrip', 'meituan'], $platforms);
                self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $collectionAnchorHash);
                return [
                    'verification_source' => 'external_p0_verifier',
                    'status' => 'passed',
                    'exit_code' => 0,
                    'authority_ready' => true,
                    'target_date' => $date,
                    'hotel_id' => $hotelId,
                    'required_platforms' => $platforms,
                    'verified_platforms' => $platforms,
                    'collection_anchor_hash' => $collectionAnchorHash,
                    'platform_statuses' => ['ctrip' => 'ready', 'meituan' => 'ready'],
                    'p0_platforms_ready' => 2,
                    'traffic_gates_ready' => 2,
                    'continuous_trust_status' => 'verified',
                    'continuous_trust_missing_steps' => [],
                    'issue_codes' => [],
                    'verifier_report_hash' => str_repeat('a', 64),
                    'checked_at' => '2026-07-25 08:45:00',
                    'sensitive_values_exposed' => false,
                ];
            },
            static fn(string $date, int $hotelId): array => [
                'status' => 'ready',
                'target_date' => $date,
                'hotel_id' => $hotelId,
                'verified_platforms' => ['ctrip', 'meituan'],
            ]
        );
        $actor = $this->actor();
        $pairCode = $service->createPairCode($actor, ['device_name' => 'Dual OTA Receipt PC']);
        $paired = $service->pairDevice([
            'pair_code' => $pairCode['pair_code'],
            'device_platform' => 'windows',
        ]);
        $accounts = [];
        foreach (['ctrip', 'meituan'] as $platform) {
            $accounts[$platform] = $service->createAccount($actor, [
                'device_id' => $paired['device_id'],
                'platform' => $platform,
                'account_alias' => strtoupper($platform) . ' authority account',
                'system_hotel_id' => 101,
                'platform_hotel_id' => strtoupper($platform) . '-AUTHORITY-101',
                'platform_hotel_name' => 'Hotel A',
            ]);
        }
        Db::name('ota_local_collector_accounts')->whereIn('id', [
            $accounts['ctrip']['account_id'],
            $accounts['meituan']['account_id'],
        ])->update([
            'status' => 'active',
            'session_status' => 'current_session_verified',
        ]);
        foreach (['ctrip', 'meituan'] as $platform) {
            $service->createTask($actor, [
                'account_id' => $accounts[$platform]['account_id'],
                'system_hotel_id' => 101,
                'task_type' => 'collect',
                'data_date' => $targetDate,
            ]);
        }

        $results = [];
        foreach (['ctrip', 'meituan'] as $expectedPlatform) {
            $task = $service->nextTask(
                $paired['device_public_id'],
                $paired['device_token']
            )['task'];
            self::assertSame($expectedPlatform, $task['platform']);
            $rows = [[
                'data_date' => $targetDate,
                'platform_hotel_id' => strtoupper($expectedPlatform) . '-AUTHORITY-101',
                'data_type' => 'business',
                'order_amount' => 688,
                'room_nights' => 2,
                'order_count' => 6,
                'list_exposure' => 120,
                'detail_exposure' => 40,
                'flow_rate' => 0.33,
                'order_submit_num' => 4,
            ]];
            if ($expectedPlatform === 'ctrip') {
                $rows[0]['order_filling_num'] = 9;
            }
            $results[$expectedPlatform] = $service->submitTaskResult(
                $paired['device_public_id'],
                $paired['device_token'],
                (int)$task['id'],
                [
                    'lease_token' => $task['lease_token'],
                    'success' => true,
                    'capture_summary' => [
                        'platform' => $expectedPlatform,
                        'target_date' => $targetDate,
                        'platform_identity_validation' => [
                            'status' => 'matched',
                            'source_validation' => true,
                            'validated_identifier' => strtoupper($expectedPlatform) . '-AUTHORITY-101',
                        ],
                    ],
                    'rows' => $rows,
                ]
            );
            self::assertSame('success', $results[$expectedPlatform]['status']);
        }

        self::assertSame('awaiting_other_platform', $results['ctrip']['summary']['dual_ota_authority']['status']);
        self::assertFalse($results['ctrip']['summary']['dual_ota_authority']['ready']);
        self::assertSame('ready', $results['meituan']['summary']['dual_ota_authority']['status']);
        self::assertTrue($results['meituan']['summary']['dual_ota_authority']['ready']);
        self::assertSame(1, $verifierCalls);

        $receipt = Cache::get("online_data_historical_executed_101_{$targetDate}");
        self::assertIsArray($receipt);
        self::assertTrue((new \app\service\ScheduledAutoFetchPolicy())->dailyTrustReceiptReady(
            $receipt,
            $targetDate,
            101
        ));
        self::assertSame(['ctrip', 'meituan'], $receipt['authority_verifier']['verified_platforms']);
        self::assertFalse($receipt['authority_verifier']['sensitive_values_exposed']);
        $downstream = new \app\service\P0OtaDownstreamGateService();
        $authorityStatus = (new \ReflectionMethod(
            \app\service\P0OtaDownstreamGateService::class,
            'authorityReceiptStatus'
        ))->invoke($downstream, $receipt, $targetDate, 101, ['ctrip', 'meituan']);
        self::assertTrue($authorityStatus['ready']);
        self::assertSame([], $authorityStatus['missing_inputs']);
        $status = $service->status($actor);
        self::assertTrue($status['ordered_collection']['gate']['formal_revenue_ready']);
        self::assertTrue($status['ordered_collection']['gate']['formal_report_ready']);
    }

    public function testSavedAndReadBackRowsRemainRetryWaitWhenCoreFieldsOrRealP0AreMissing(): void
    {
        $service = new OtaLocalCollectorService(
            static function ($owner, array $task, array $account, array $mapping): array {
                Db::name('ota_local_collector_account_hotels')
                    ->where('id', (int)$mapping['id'])
                    ->where('tenant_id', (int)$task['tenant_id'])
                    ->where('account_id', (int)$task['account_id'])
                    ->where('system_hotel_id', (int)$task['system_hotel_id'])
                    ->where('platform', (string)$task['platform'])
                    ->update(['data_source_id' => 88]);
                return [
                'status' => 'partial_success',
                'data_source_id' => 88,
                'task_id' => 99,
                'normalized_count' => 1,
                'saved_count' => 1,
                'readback_count' => 1,
                'readback_verified' => true,
                'run_readback' => [
                    'tenant_id' => (int)$task['tenant_id'],
                    'data_source_id' => 88,
                    'sync_task_id' => 99,
                    'system_hotel_id' => (int)$task['system_hotel_id'],
                    'target_date' => (string)$task['data_date'],
                    'platform' => (string)$task['platform'],
                    'readback_count' => 1,
                    'readback_verified' => true,
                    'p0_status' => 'blocked',
                    'row_ids' => [7101],
                ],
                'deterministic_readback' => [
                    'tenant_id' => (int)$task['tenant_id'],
                    'data_source_id' => 88,
                    'sync_task_id' => 99,
                    'system_hotel_id' => (int)$task['system_hotel_id'],
                    'target_date' => (string)$task['data_date'],
                    'platform' => (string)$task['platform'],
                    'readback_count' => 1,
                    'readback_verified' => true,
                    'row_ids' => [7101],
                ],
                'sync_diagnostics' => [
                    'target_date' => '2026-07-23',
                    'requires_target_date_traffic' => true,
                    'p0_status' => 'blocked',
                    'missing_inputs' => ['required_traffic_metric_keys'],
                ],
                ];
            }
        );
        $actor = $this->actor();
        $pairCode = $service->createPairCode($actor, ['device_name' => 'Gap Fixture PC']);
        $paired = $service->pairDevice([
            'pair_code' => $pairCode['pair_code'],
            'device_platform' => 'windows',
        ]);
        $account = $service->createAccount($actor, [
            'device_id' => $paired['device_id'],
            'platform' => 'meituan',
            'account_alias' => '缺口测试账户',
            'system_hotel_id' => 101,
            'platform_hotel_id' => 'MT-GAP-101',
        ]);
        Db::name('ota_local_collector_accounts')->where('id', $account['account_id'])->update([
            'status' => 'active',
            'session_status' => 'current_session_verified',
        ]);
        $service->createTask($actor, [
            'account_id' => $account['account_id'],
            'system_hotel_id' => 101,
            'task_type' => 'collect',
            'data_date' => '2026-07-23',
        ]);
        $task = $service->nextTask($paired['device_public_id'], $paired['device_token'])['task'];
        $result = $service->submitTaskResult(
            $paired['device_public_id'],
            $paired['device_token'],
            $task['id'],
            [
                'lease_token' => $task['lease_token'],
                'success' => true,
                'capture_summary' => [
                    'platform_identity_validation' => [
                        'status' => 'matched',
                        'source_validation' => true,
                        'validated_identifier' => 'MT-GAP-101',
                    ],
                ],
                'rows' => [[
                    'data_date' => '2026-07-23',
                    'platform_hotel_id' => 'MT-GAP-101',
                    'data_type' => 'order',
                    'order_amount' => 680,
                    'room_nights' => 2,
                    'order_count' => 1,
                ]],
            ]
        );

        self::assertSame('retry_wait', $result['status']);
        self::assertSame('field_gap', $result['error_code']);
        $stored = Db::name('ota_local_collector_tasks')->where('id', $task['id'])->find();
        $summary = json_decode((string)$stored['result_summary_json'], true, 64, JSON_THROW_ON_ERROR);
        self::assertTrue($summary['readback_verified']);
        self::assertSame('blocked', $summary['ordered_collection']['p0_status']);
        self::assertNotSame([], $summary['ordered_collection']['missing_field_keys']);
    }

    public function testSensitiveResultAndCrossDeviceTokenAreRejected(): void
    {
        $service = new OtaLocalCollectorService();
        $actor = $this->actor();
        $pairCode = $service->createPairCode($actor, ['device_name' => 'Owner PC']);
        $paired = $service->pairDevice(['pair_code' => $pairCode['pair_code'], 'device_platform' => 'windows']);
        $created = $service->createAccount($actor, [
            'device_id' => $paired['device_id'],
            'platform' => 'meituan',
            'account_alias' => '美团账户',
            'system_hotel_id' => 101,
            'platform_hotel_id' => 'MT-101',
        ]);
        Db::name('ota_local_collector_accounts')->where('id', $created['account_id'])->update([
            'status' => 'active',
            'session_status' => 'current_session_verified',
        ]);
        $service->createTask($actor, [
            'account_id' => $created['account_id'],
            'system_hotel_id' => 101,
            'task_type' => 'collect',
            'data_date' => '2026-07-23',
        ]);
        $task = $service->nextTask($paired['device_public_id'], $paired['device_token'])['task'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(422);
        try {
            $service->submitTaskResult(
                $paired['device_public_id'],
                $paired['device_token'],
                $task['id'],
                [
                    'lease_token' => $task['lease_token'],
                    'success' => true,
                    'rows' => [[
                        'data_date' => '2026-07-23',
                        'platform_hotel_id' => 'MT-101',
                        'cookie' => 'must-never-upload',
                    ]],
                ]
            );
        } finally {
            try {
                $service->nextTask($paired['device_public_id'], 'wrong-token');
                self::fail('Cross-device token should be rejected.');
            } catch (RuntimeException $e) {
                self::assertSame(401, $e->getCode());
            }
        }
    }

    public function testRetryAndLoginRecoveryAreExplicitChineseActions(): void
    {
        $service = new OtaLocalCollectorService();
        $retry = $service->recoveryGuide('network_error', 'ctrip', 'online', '2026-07-24 12:00:00');
        self::assertSame('retry_wait', $retry['status']);
        self::assertTrue($retry['auto_retry']);
        self::assertStringContainsString('自动重试', $retry['message']);

        $login = $service->recoveryGuide('session_expired', 'meituan');
        self::assertSame('login_required', $login['status']);
        self::assertFalse($login['auto_retry']);
        self::assertStringContainsString('重新登录', $login['next_action']);

        $ready = $service->recoveryGuide('');
        self::assertSame('ready', $ready['status']);
        self::assertStringContainsString('仅保留', $ready['next_action']);
    }

    public function testYesterdayWindowEmitsAnExplicitGapReportAfterNineWithoutOpeningFormalReports(): void
    {
        $service = new OtaLocalCollectorService();
        $method = new \ReflectionMethod(
            OtaLocalCollectorService::class,
            'orderedYesterdayGapStatus'
        );
        $now = new \DateTimeImmutable(
            '2026-07-25 09:05:00',
            new \DateTimeZone('Asia/Shanghai')
        );
        $gap = $method->invoke($service, [
            'ready' => false,
            'hotel_states' => [[
                'system_hotel_id' => 101,
                'mapped_platforms' => ['ctrip', 'meituan'],
                'ready' => false,
                'blocking_inputs' => ['meituan_target_date_traffic_rows'],
                'reason' => 'external_p0_verifier_receipt_not_ready',
            ]],
        ], '2026-07-24', $now);

        self::assertSame('gap', $gap['status']);
        self::assertSame('explicit_gap_report', $gap['report_kind']);
        self::assertFalse($gap['formal_report_allowed']);
        self::assertTrue($gap['cutoff_reached']);
        self::assertSame(['meituan'], $gap['missing_platforms']);
        self::assertContains('meituan_target_date_traffic_rows', $gap['gap_codes']);
        self::assertStringContainsString('正式收益与日报保持阻断', $gap['next_action']);

        $ready = $method->invoke($service, [
            'ready' => true,
            'hotel_states' => [],
        ], '2026-07-24', $now);
        self::assertSame('ready', $ready['status']);
        self::assertTrue($ready['formal_report_allowed']);
        self::assertSame([], $ready['missing_platforms']);
    }

    private function actor(): object
    {
        return new class {
            public int $id = 7;
            public int $tenant_id = 12;

            /** @return array<int, int> */
            public function getPermittedHotelIds(): array
            {
                return [101, 102];
            }

            public function isSuperAdmin(): bool
            {
                return false;
            }
        };
    }

    /** @param array<int, int> $hotelIds */
    private function actorWithHotels(array $hotelIds): object
    {
        return new class($hotelIds) {
            public int $id = 7;
            public int $tenant_id = 12;

            /** @param array<int, int> $hotelIds */
            public function __construct(private readonly array $hotelIds)
            {
            }

            /** @return array<int, int> */
            public function getPermittedHotelIds(): array
            {
                return $this->hotelIds;
            }

            public function isSuperAdmin(): bool
            {
                return false;
            }
        };
    }

    private static function createSchema(): void
    {
        Db::execute('CREATE TABLE users (id INTEGER PRIMARY KEY, tenant_id INTEGER, username TEXT, password TEXT, status INTEGER NOT NULL, role_id INTEGER)');
        Db::execute('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT, status INTEGER NOT NULL, level INTEGER, permissions TEXT)');
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT, status INTEGER NOT NULL)');
        Db::execute('CREATE TABLE user_hotel_permissions (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, user_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, status TEXT NOT NULL, can_view INTEGER, expires_at TEXT)');
        Db::execute('CREATE TABLE ota_local_collector_devices (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
            device_public_id TEXT NOT NULL UNIQUE, device_token_hash TEXT NOT NULL, device_name TEXT NOT NULL,
            device_platform TEXT NOT NULL, collector_version TEXT, capabilities_json TEXT, status TEXT NOT NULL,
            last_seen_at TEXT, last_error_code TEXT, last_error_summary TEXT, create_time TEXT, update_time TEXT
        )');
        Db::execute('CREATE TABLE ota_local_collector_accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
            device_id INTEGER NOT NULL, platform TEXT NOT NULL, account_alias TEXT NOT NULL, profile_key_hash TEXT NOT NULL,
            status TEXT NOT NULL, session_status TEXT NOT NULL, last_session_verified_at TEXT, last_success_at TEXT,
            last_error_code TEXT, last_error_summary TEXT, retry_count INTEGER, next_retry_at TEXT, create_time TEXT, update_time TEXT
        )');
        Db::execute('CREATE TABLE ota_local_collector_account_hotels (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, account_id INTEGER NOT NULL,
            system_hotel_id INTEGER NOT NULL, platform TEXT NOT NULL, platform_hotel_id TEXT NOT NULL,
            platform_hotel_name TEXT, data_source_id INTEGER, status TEXT NOT NULL, create_time TEXT, update_time TEXT
        )');
        Db::execute('CREATE UNIQUE INDEX uq_ota_local_account_hotel ON ota_local_collector_account_hotels (account_id, system_hotel_id)');
        Db::execute("CREATE UNIQUE INDEX uq_ota_local_active_hotel_platform ON ota_local_collector_account_hotels (tenant_id, system_hotel_id, platform) WHERE status = 'active'");
        Db::execute("CREATE UNIQUE INDEX uq_ota_local_active_platform_hotel_identity ON ota_local_collector_account_hotels (tenant_id, platform, platform_hotel_id) WHERE status = 'active'");
        Db::execute('CREATE TABLE ota_local_collector_tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
            device_id INTEGER NOT NULL, account_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL,
            platform TEXT NOT NULL, task_type TEXT NOT NULL, data_date TEXT, data_type TEXT NOT NULL,
            status TEXT NOT NULL, priority INTEGER NOT NULL, attempt INTEGER NOT NULL, max_attempts INTEGER NOT NULL,
            available_at TEXT NOT NULL, lease_token_hash TEXT, lease_expires_at TEXT, idempotency_key TEXT NOT NULL UNIQUE,
            request_json TEXT, result_summary_json TEXT, error_code TEXT, error_summary TEXT, created_by INTEGER,
            started_at TEXT, finished_at TEXT, create_time TEXT, update_time TEXT
        )');
        Db::execute('CREATE TABLE platform_data_sources (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL,
            platform TEXT NOT NULL, data_type TEXT NOT NULL, ingestion_method TEXT NOT NULL, status TEXT NOT NULL,
            enabled INTEGER NOT NULL, config_json TEXT, last_sync_time TEXT, last_sync_status TEXT, last_error TEXT
        )');
        Db::execute('CREATE TABLE online_daily_data (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, system_hotel_id INTEGER NOT NULL, data_source_id INTEGER,
            sync_task_id INTEGER, data_date TEXT NOT NULL, platform TEXT, source TEXT, data_type TEXT, data_period TEXT,
            readback_verified INTEGER, validation_status TEXT, compare_type TEXT, dimension TEXT,
            amount REAL, quantity INTEGER, book_order_num INTEGER, list_exposure INTEGER,
            detail_exposure INTEGER, flow_rate REAL, order_filling_num INTEGER, order_submit_num INTEGER,
            raw_data TEXT
        )');
        Db::execute('CREATE TABLE platform_data_sync_tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, data_source_id INTEGER,
            system_hotel_id INTEGER, platform TEXT, status TEXT, stats_json TEXT
        )');
    }
}
