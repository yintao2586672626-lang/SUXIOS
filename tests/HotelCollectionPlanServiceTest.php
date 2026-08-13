<?php
declare(strict_types=1);

namespace tests;

use app\service\HotelCollectionPlanService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class HotelCollectionPlanServiceTest extends TestCase
{
    private static array $databaseConfig;
    private static string $databasePath;

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$databaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR . 'hotel_collection_plan_' . getmypid() . '.sqlite';
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
        Db::name('dingdandao_operating_target_captures')->delete(true);
        Db::name('hotel_collection_plan_run_sources')->delete(true);
        Db::name('hotel_collection_plan_runs')->delete(true);
        Db::name('hotel_collection_plans')->delete(true);
    }

    public function testMigrationPersistsOnlySecretFreeHotelScopedPlanMaterial(): void
    {
        $migration = (string)file_get_contents(
            dirname(__DIR__) . '/database/migrations/20260810_create_hotel_collection_plans.sql'
        );

        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `hotel_collection_plans`', $migration);
        self::assertStringContainsString(
            'UNIQUE KEY `uq_hotel_collection_plan_scope` (`tenant_id`, `system_hotel_id`)',
            $migration
        );
        self::assertStringContainsString('`binding_digest` char(64) NOT NULL', $migration);
        self::assertStringContainsString('`plan_hash` char(64) NOT NULL', $migration);
        self::assertStringNotContainsString('cookie', strtolower($migration));
        self::assertStringNotContainsString('password', strtolower($migration));
        self::assertStringNotContainsString('profile_path', strtolower($migration));
        self::assertStringNotContainsString('device_id', strtolower($migration));
        self::assertStringNotContainsString('account_id', strtolower($migration));

        $versionMigration = (string)file_get_contents(
            dirname(__DIR__) . '/database/migrations/20260810_z_version_hotel_collection_plans.sql'
        );
        self::assertStringContainsString('DROP INDEX IF EXISTS `uq_hotel_collection_plan_scope`', $versionMigration);
        self::assertStringContainsString('ADD COLUMN IF NOT EXISTS `active_slot`', $versionMigration);
        self::assertStringContainsString('ADD UNIQUE INDEX IF NOT EXISTS', $versionMigration);
        self::assertStringContainsString('`uq_hotel_collection_plan_version`', $versionMigration);
        self::assertStringContainsString('`uq_hotel_collection_plan_active`', $versionMigration);
    }

    public function testReadyPlanSavesReadsBackAndAuthorizesOnlyExactBoundExecution(): void
    {
        $service = $this->service($this->bindingReceipt());

        $saved = $service->save($this->hotel(80), 7, $this->input(25, 68, true));
        $executionGate = $service->authorizeExecutionScope(
            $this->hotel(80),
            '2026-08-09',
            [68, 25],
            ['meituan', 'ctrip'],
            'daily'
        );

        self::assertTrue($saved['save_verified']);
        self::assertTrue($saved['readback_verified']);
        self::assertTrue($saved['execution_authorized']);
        self::assertSame('active_ready', $saved['status']);
        self::assertSame('active', $saved['plan_status']);
        self::assertSame(25, $saved['sources']['ctrip']['data_source_id']);
        self::assertSame(68, $saved['sources']['meituan']['data_source_id']);
        self::assertSame('DD-80', $saved['sources']['pms']['provider_hotel_id']);
        self::assertFalse($saved['automatic_device_substitution']);
        self::assertSame(1, Db::name('hotel_collection_plans')->count());
        self::assertSame(1, (int)Db::name('hotel_collection_plans')->value('enabled'));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $saved['plan_hash']);
        self::assertTrue($executionGate['collection_allowed']);
        self::assertSame('ready', $executionGate['status']);
        self::assertSame([25, 68], $executionGate['expected_source_ids']);
        self::assertSame([25, 68], $executionGate['actual_source_ids']);
        self::assertSame([], $executionGate['failure_reasons']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $executionGate['scope_hash']);

        $json = json_encode($saved, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('cookie_value', strtolower($json));
        self::assertStringNotContainsString('device-public-id', strtolower($json));
        self::assertStringNotContainsString('profile_path', strtolower($json));
        self::assertStringNotContainsString('device_id', strtolower($json));
        self::assertStringNotContainsString('account_id', strtolower($json));
    }

    public function testPlanReadReturnsOnlyLatestExactHotelDateRunReceipt(): void
    {
        $service = $this->service($this->bindingReceipt());
        $service->save($this->hotel(80), 7, $this->input(25, 68, true));

        $this->seedRunReceipt(
            '11111111-1111-4111-8111-111111111111',
            80,
            '2026-08-09',
            25,
            68,
            'failed'
        );
        $expectedRunId = '22222222-2222-4222-8222-222222222222';
        $this->seedRunReceipt($expectedRunId, 80, '2026-08-09', 25, 68, 'succeeded');
        $this->seedRunReceipt(
            '33333333-3333-4333-8333-333333333333',
            81,
            '2026-08-09',
            125,
            168,
            'succeeded'
        );
        $this->seedRunReceipt(
            '44444444-4444-4444-8444-444444444444',
            80,
            '2026-08-08',
            25,
            68,
            'succeeded'
        );

        $readback = $service->read($this->hotel(80), 7, '2026-08-09');
        $run = $readback['latest_run_receipt'];

        self::assertSame($expectedRunId, $run['dispatcher_run_id']);
        self::assertSame(80, $run['system_hotel_id']);
        self::assertSame('2026-08-09', $run['business_date']);
        self::assertSame('succeeded', $run['status']);
        self::assertTrue($run['ledger_structure_verified']);
        // This fixture writes only the public ledger rows; it deliberately has
        // no producer/raw/daily evidence. The latest exact run is still shown,
        // but a claimed `succeeded` row must not become trusted by shape alone.
        self::assertFalse($run['readback_verified']);
        self::assertSame([25, 68], array_column($run['source_receipts'], 'data_source_id'));
        self::assertSame('verified', $run['pms_receipt']['status']);
        self::assertSame('verified', $run['page_acceptance']['status']);
        self::assertFalse($run['automatic_device_substitution']);
        self::assertFalse($run['sensitive_values_exposed']);
    }

    public function testExecutionGateRejectsSourcePlatformAndModeSubstitution(): void
    {
        $service = $this->service($this->bindingReceipt());
        $service->save($this->hotel(80), 7, $this->input(25, 68, true));

        $wrongSources = $service->authorizeExecutionScope(
            $this->hotel(80),
            '2026-08-09',
            [25, 101],
            ['ctrip', 'meituan'],
            'daily'
        );
        $wrongPlatforms = $service->authorizeExecutionScope(
            $this->hotel(80),
            '2026-08-09',
            [25, 68],
            ['ctrip'],
            'daily'
        );
        $wrongMode = $service->authorizeExecutionScope(
            $this->hotel(80),
            '2026-08-09',
            [25, 68],
            ['ctrip', 'meituan'],
            'realtime'
        );

        self::assertFalse($wrongSources['collection_allowed']);
        self::assertContains(
            'hotel_collection_execution_source_scope_mismatch',
            array_column($wrongSources['failure_reasons'], 'code')
        );
        self::assertFalse($wrongPlatforms['collection_allowed']);
        self::assertContains(
            'hotel_collection_execution_platform_scope_mismatch',
            array_column($wrongPlatforms['failure_reasons'], 'code')
        );
        self::assertFalse($wrongMode['collection_allowed']);
        self::assertContains(
            'hotel_collection_execution_mode_mismatch',
            array_column($wrongMode['failure_reasons'], 'code')
        );
        self::assertFalse($wrongSources['automatic_device_substitution']);
    }

    public function testCrossHotelTenantSourceAndPmsReceiptSubstitutionIsRejected(): void
    {
        $mutations = [
            static function (array $binding): array {
                $binding['system_hotel']['tenant_id'] = 9;
                return $binding;
            },
            static function (array $binding): array {
                $binding['system_hotel']['system_hotel_id'] = 81;
                return $binding;
            },
            static function (array $binding): array {
                $binding['bindings']['ctrip']['source_id'] = 999;
                return $binding;
            },
            static function (array $binding): array {
                $binding['bindings']['pms']['system_hotel_id'] = 81;
                return $binding;
            },
        ];

        foreach ($mutations as $mutate) {
            try {
                $this->service($mutate($this->bindingReceipt()))->save(
                    $this->hotel(80),
                    7,
                    $this->input(25, 68, false)
                );
                self::fail('Cross-scope binding receipt must be rejected.');
            } catch (\RuntimeException $error) {
                self::assertSame(
                    'hotel_collection_binding_receipt_scope_mismatch',
                    $error->getMessage()
                );
            }
        }
        self::assertSame(0, Db::name('hotel_collection_plans')->count());
    }

    public function testSavingDraftDoesNotStopCurrentActivePlan(): void
    {
        $service = $this->service($this->bindingReceipt());
        $active = $service->save($this->hotel(80), 7, $this->input(25, 68, true));
        $draftInput = $this->input(25, 68, false);
        $draftInput['schedule_time'] = '09:10';
        $draft = $service->save($this->hotel(80), 7, $draftInput);

        $current = $service->read($this->hotel(80), 7, '2026-08-09');
        $gate = $service->authorizeExecutionScope(
            $this->hotel(80),
            '2026-08-09',
            [25, 68],
            ['ctrip', 'meituan'],
            'daily'
        );

        self::assertSame(1, $active['plan_version']);
        self::assertSame(2, $draft['plan_version']);
        self::assertSame('draft', $draft['status']);
        self::assertSame(2, Db::name('hotel_collection_plans')->count());
        self::assertSame(1, $current['plan_version']);
        self::assertSame(2, $current['pending_draft']['plan_version']);
        self::assertTrue($current['execution_authorized']);
        self::assertTrue($gate['collection_allowed']);
        self::assertSame(1, Db::name('hotel_collection_plans')->where('active_slot', 1)->count());
    }

    public function testVerifiedLocalCollectorSourcesAtomicallyReplaceTheLegacyActivePlan(): void
    {
        $loader = function (array $hotel, int $actor, string $date, array $designated): array {
            $ctripSourceId = (int)($designated['ctrip'] ?? 0);
            $meituanSourceId = (int)($designated['meituan'] ?? 0);
            $binding = $this->bindingReceipt(
                'ready',
                hash('sha256', $ctripSourceId . ':' . $meituanSourceId),
                $ctripSourceId,
                $meituanSourceId
            );
            $method = $ctripSourceId >= 500 ? 'local_collector' : 'browser_profile';
            foreach (['ctrip', 'meituan'] as $platform) {
                $binding['bindings'][$platform]['ingestion_method'] = $method;
                $binding['bindings'][$platform]['execution_device_binding']['binding_kind'] =
                    $method === 'local_collector'
                        ? 'local_collector_device'
                        : 'browser_profile_single_user_local';
            }
            return $binding;
        };
        $service = new HotelCollectionPlanService($loader, $this->clock(), $this->signingKey());

        $legacy = $service->save($this->hotel(80), 7, $this->input(25, 68, true));
        $replacement = $service->save($this->hotel(80), 7, $this->input(501, 502, true));
        $readback = $service->read($this->hotel(80), 7, '2026-08-09');

        $legacyRow = Db::name('hotel_collection_plans')->where('id', (int)$legacy['id'])->find();
        self::assertIsArray($legacyRow);
        self::assertSame('superseded', $legacyRow['plan_status']);
        self::assertSame(0, (int)$legacyRow['enabled']);
        self::assertNull($legacyRow['active_slot']);
        self::assertSame(2, $replacement['plan_version']);
        self::assertSame('active_ready', $replacement['status']);
        self::assertTrue($replacement['save_verified']);
        self::assertTrue($replacement['readback_verified']);
        self::assertTrue($replacement['execution_authorized']);
        self::assertSame(501, $replacement['sources']['ctrip']['data_source_id']);
        self::assertSame('local_collector', $replacement['sources']['ctrip']['ingestion_method']);
        self::assertSame(502, $replacement['sources']['meituan']['data_source_id']);
        self::assertSame('local_collector', $replacement['sources']['meituan']['ingestion_method']);
        self::assertSame(
            7,
            (int)Db::name('hotel_collection_plans')
                ->where('id', (int)$replacement['id'])
                ->where('tenant_id', 8)
                ->where('system_hotel_id', 80)
                ->value('execution_owner_user_id')
        );
        self::assertSame(2, $readback['plan_version']);
        self::assertSame(1, Db::name('hotel_collection_plans')->where('active_slot', 1)->count());
    }

    public function testBindingDriftDuringActivationRollsBackAndPreservesCurrentActive(): void
    {
        $stable = $this->service($this->bindingReceipt('ready', str_repeat('a', 64)));
        $stable->save($this->hotel(80), 7, $this->input(25, 68, true));
        $calls = 0;
        $loader = function () use (&$calls): array {
            $calls++;
            return $this->bindingReceipt(
                'ready',
                $calls === 1 ? str_repeat('b', 64) : str_repeat('c', 64)
            );
        };
        $drifting = new HotelCollectionPlanService(
            $loader,
            $this->clock(),
            $this->signingKey()
        );

        try {
            $drifting->save($this->hotel(80), 7, $this->input(25, 68, true));
            self::fail('Activation must roll back when the binding changes during save.');
        } catch (\RuntimeException $error) {
            self::assertSame('hotel_collection_plan_final_binding_not_ready', $error->getMessage());
        }

        self::assertSame(1, Db::name('hotel_collection_plans')->count());
        $active = Db::name('hotel_collection_plans')->where('active_slot', 1)->find();
        self::assertIsArray($active);
        self::assertSame(1, (int)$active['plan_version']);
        self::assertSame('active', $active['plan_status']);
        self::assertSame(str_repeat('a', 64), $active['binding_digest']);
    }

    public function testDisabledHotelCannotActivatePlan(): void
    {
        $hotel = $this->hotel(80);
        $hotel['status'] = 0;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('hotel_collection_plan_hotel_disabled');

        $this->service($this->bindingReceipt())->save($hotel, 7, $this->input(25, 68, true));
    }

    public function testFailureMessagesAreCodeMappedBeforePersistenceAndReadback(): void
    {
        $binding = $this->bindingReceipt('blocked');
        $binding['blockers'] = [[
            'code' => 'ota_execution_device_binding_missing',
            'platform' => 'ctrip',
            'message' => 'device-public-501 C:\\Users\\operator profile_path account_id=425',
        ]];
        $binding['bindings']['ctrip']['status'] = 'blocked';
        $binding['bindings']['ctrip']['blockers'] = $binding['blockers'];

        $saved = $this->service($binding)->save(
            $this->hotel(80),
            7,
            $this->input(25, 68, false)
        );
        $stored = (string)Db::name('hotel_collection_plans')->value('validation_reasons_json');
        $material = strtolower($stored . json_encode($saved, JSON_THROW_ON_ERROR));

        self::assertStringNotContainsString('device-public-501', $material);
        self::assertStringNotContainsString('c:\\users', $material);
        self::assertStringNotContainsString('profile_path', $material);
        self::assertStringNotContainsString('account_id', $material);
        self::assertStringContainsString('ota_execution_device_binding_missing', $material);
    }

    public function testBlockedBindingCanBeSavedAsDraftWithTruthfulFailureReasons(): void
    {
        $binding = $this->bindingReceipt('blocked');
        $binding['blockers'] = [[
            'code' => 'ota_execution_device_binding_missing',
            'platform' => 'ctrip',
            'message' => 'Device binding is missing.',
        ]];
        $binding['bindings']['ctrip']['status'] = 'blocked';
        $binding['bindings']['ctrip']['blockers'] = $binding['blockers'];
        $service = $this->service($binding);

        $saved = $service->save($this->hotel(80), 7, $this->input(25, 68, false));

        self::assertSame('draft', $saved['status']);
        self::assertTrue($saved['readback_verified']);
        self::assertFalse($saved['execution_authorized']);
        self::assertFalse($saved['enabled']);
        self::assertContains(
            'ota_execution_device_binding_missing',
            array_column($saved['failure_reasons'], 'code')
        );
        self::assertContains(
            'hotel_collection_plan_not_active',
            array_column($saved['failure_reasons'], 'code')
        );
    }

    public function testBlockedBindingCannotBeActivated(): void
    {
        $binding = $this->bindingReceipt('blocked');
        $binding['blockers'] = [[
            'code' => 'ota_platform_hotel_id_canonical_missing',
            'platform' => 'meituan',
            'message' => 'Canonical hotel id missing.',
        ]];
        $service = $this->service($binding);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('hotel_collection_plan_binding_not_ready');

        $service->save($this->hotel(80), 7, $this->input(25, 68, true));
    }

    public function testCurrentBindingDriftRevokesExecutionWithoutChangingPlan(): void
    {
        $digest = str_repeat('a', 64);
        $loader = function () use (&$digest): array {
            return $this->bindingReceipt('ready', $digest);
        };
        $service = new HotelCollectionPlanService($loader, $this->clock(), $this->signingKey());
        $saved = $service->save($this->hotel(80), 7, $this->input(25, 68, true));
        self::assertTrue($saved['execution_authorized']);

        $digest = str_repeat('b', 64);
        $readback = $service->read($this->hotel(80), 7, '2026-08-09');

        self::assertTrue($readback['readback_verified']);
        self::assertFalse($readback['binding_digest_matches']);
        self::assertFalse($readback['execution_authorized']);
        self::assertContains(
            'hotel_collection_plan_binding_drifted',
            array_column($readback['failure_reasons'], 'code')
        );
    }

    public function testTamperedStoredPlanFailsExactReadback(): void
    {
        $service = $this->service($this->bindingReceipt());
        $service->save($this->hotel(80), 7, $this->input(25, 68, true));
        Db::name('hotel_collection_plans')->where('system_hotel_id', 80)->update([
            'source_plan_json' => '{"ctrip":{"data_source_id":999}}',
            'plan_hash' => hash('sha256', '{"ctrip":{"data_source_id":999}}'),
        ]);

        $readback = $service->read($this->hotel(80), 7, '2026-08-09');

        self::assertFalse($readback['readback_verified']);
        self::assertFalse($readback['execution_authorized']);
        self::assertContains(
            'hotel_collection_plan_signature_mismatch',
            array_column($readback['failure_reasons'], 'code')
        );
    }

    public function testTwoHotelsPersistAndReadPlansInIndependentScopes(): void
    {
        $loader = function (array $hotel, int $actor, string $date, array $designated): array {
            $hotelId = (int)$hotel['id'];
            return $this->bindingReceipt(
                'ready',
                hash('sha256', 'binding-' . $hotelId),
                $designated['ctrip'],
                $designated['meituan'],
                'DD-' . $hotelId,
                $hotelId
            );
        };
        $service = new HotelCollectionPlanService($loader, $this->clock(), $this->signingKey());
        $service->save($this->hotel(80), 7, $this->input(25, 68, true));
        $service->save($this->hotel(81), 7, $this->input(125, 168, true, 'dingdandao_pms'));

        $hotel80 = $service->read($this->hotel(80), 7, '2026-08-09');
        $hotel81 = $service->read($this->hotel(81), 7, '2026-08-09');

        self::assertSame(2, Db::name('hotel_collection_plans')->count());
        self::assertSame(25, $hotel80['sources']['ctrip']['data_source_id']);
        self::assertSame(125, $hotel81['sources']['ctrip']['data_source_id']);
        self::assertNotSame($hotel80['plan_hash'], $hotel81['plan_hash']);
        self::assertTrue($hotel80['execution_authorized']);
        self::assertTrue($hotel81['execution_authorized']);
    }

    private function service(array $binding): HotelCollectionPlanService
    {
        return new HotelCollectionPlanService(
            static fn(array $hotel, int $actor, string $date, array $designated): array => $binding,
            $this->clock(),
            $this->signingKey()
        );
    }

    private function signingKey(): string
    {
        return str_repeat('k', 32);
    }

    private function clock(): callable
    {
        return static fn(): DateTimeImmutable => new DateTimeImmutable(
            '2026-08-10 08:00:00',
            new DateTimeZone('Asia/Shanghai')
        );
    }

    /** @return array<string,mixed> */
    private function hotel(int $id): array
    {
        return [
            'id' => $id,
            'tenant_id' => 8,
            'name' => 'Hotel ' . $id,
            'status' => 1,
        ];
    }

    /** @return array<string,mixed> */
    private function input(
        int $ctripSourceId,
        int $meituanSourceId,
        bool $activate,
        string $pmsProvider = 'dingdandao_pms'
    ): array {
        return [
            'sources' => [
                'ctrip' => ['data_source_id' => $ctripSourceId],
                'meituan' => ['data_source_id' => $meituanSourceId],
                'pms' => ['provider' => $pmsProvider],
            ],
            'business_date' => '2026-08-09',
            'business_date_policy' => 'previous_business_day',
            'timezone' => 'Asia/Shanghai',
            'schedule_time' => '08:30',
            'retry_interval_minutes' => 14,
            'max_attempts' => 7,
            'activate' => $activate,
        ];
    }

    /** @return array<string,mixed> */
    private function bindingReceipt(
        string $status = 'ready',
        string $digest = '',
        int $ctripSourceId = 25,
        int $meituanSourceId = 68,
        string $pmsHotelId = 'DD-80',
        int $systemHotelId = 80
    ): array {
        $digest = $digest !== '' ? $digest : str_repeat('a', 64);
        $ctrip = $this->otaBinding(
            'ctrip',
            $ctripSourceId,
            'CTRIP-' . $ctripSourceId,
            $systemHotelId
        );
        $meituan = $this->otaBinding(
            'meituan',
            $meituanSourceId,
            'MT-' . $meituanSourceId,
            $systemHotelId
        );
        return [
            'status' => $status,
            'binding_digest' => $digest,
            'system_hotel' => [
                'tenant_id' => 8,
                'system_hotel_id' => $systemHotelId,
                'enabled' => true,
            ],
            'bindings' => [
                'ctrip' => $ctrip,
                'meituan' => $meituan,
                'pms' => [
                    'platform' => 'pms',
                    'status' => 'ready',
                    'tenant_id' => 8,
                    'system_hotel_id' => $systemHotelId,
                    'provider' => 'dingdandao_pms',
                    'provider_hotel_id' => $pmsHotelId,
                    'provider_hotel_name' => 'Hotel',
                    'blockers' => [],
                    'recovery_reasons' => [],
                ],
            ],
            'blockers' => [],
            'recovery_reasons' => [],
        ];
    }

    private function seedRunReceipt(
        string $dispatcherRunId,
        int $hotelId,
        string $businessDate,
        int $ctripSourceId,
        int $meituanSourceId,
        string $status
    ): void {
        $pmsCaptureId = $status === 'succeeded'
            ? (int)hexdec(substr($dispatcherRunId, 0, 8))
            : 0;
        $runId = (int)Db::name('hotel_collection_plan_runs')->insertGetId([
            'dispatcher_run_id' => $dispatcherRunId,
            'tenant_id' => 8,
            'system_hotel_id' => $hotelId,
            'business_date' => $businessDate,
            'run_mode' => 'daily',
            'plan_id' => 1,
            'plan_version' => 1,
            'scope_hash' => hash('sha256', $dispatcherRunId),
            'status' => $status,
            'failure_stage' => $status === 'succeeded' ? '' : 'collection',
            'failure_code' => $status === 'succeeded' ? '' : 'collection_failed',
            'collection_anchor_contract_version' => $status === 'succeeded'
                ? 'suxios.ota_collection_anchor.v1'
                : null,
            'collection_anchor_hash' => $status === 'succeeded'
                ? hash('sha256', 'anchor-' . $dispatcherRunId)
                : null,
            'trust_receipt_digest' => $status === 'succeeded'
                ? hash('sha256', 'trust-' . $dispatcherRunId)
                : null,
            'page_status' => $status === 'succeeded' ? 'verified' : 'not_evaluated',
            'page_receipt_id' => $status === 'succeeded' ? 91 : null,
            'page_contract_hash' => $status === 'succeeded'
                ? hash('sha256', 'page-' . $dispatcherRunId)
                : null,
            'pms_provider' => 'dingdandao_pms',
            'pms_status' => $status === 'succeeded' ? 'verified' : 'not_run',
            'pms_capture_id' => $status === 'succeeded' ? (string)$pmsCaptureId : null,
            'pms_readback_verified' => $status === 'succeeded' ? 1 : null,
            'started_at' => '2026-08-10 08:00:00',
            'finished_at' => '2026-08-10 08:05:00',
        ]);
        foreach ([
            'ctrip' => $ctripSourceId,
            'meituan' => $meituanSourceId,
        ] as $platform => $sourceId) {
            Db::name('hotel_collection_plan_run_sources')->insert([
                'run_id' => $runId,
                'platform' => $platform,
                'data_source_id' => $sourceId,
                'ingestion_method' => 'local_collector',
                'platform_sync_task_id' => $status === 'succeeded' ? $sourceId + 1000 : null,
                'local_collector_task_id' => $status === 'succeeded' ? $sourceId + 2000 : null,
                'status' => $status === 'succeeded' ? 'success' : 'failed',
                'failure_stage' => $status === 'succeeded' ? '' : 'collection',
                'failure_code' => $status === 'succeeded' ? '' : 'collection_failed',
                'saved_row_count' => $status === 'succeeded' ? 1 : 0,
                'readback_row_count' => $status === 'succeeded' ? 1 : 0,
                'readback_verified' => $status === 'succeeded' ? 1 : 0,
                'page_acceptance_status' => $status === 'succeeded'
                    ? 'verified'
                    : 'not_evaluated',
                'page_acceptance_log_id' => $status === 'succeeded' ? 91 : null,
                'started_at' => '2026-08-10 08:00:00',
                'finished_at' => '2026-08-10 08:05:00',
            ]);
        }
        if ($status === 'succeeded') {
            Db::name('dingdandao_operating_target_captures')->insert([
                'id' => $pmsCaptureId,
                'tenant_id' => 8,
                'hotel_id' => $hotelId,
                'provider' => 'dingdandao_pms',
                'business_date' => $businessDate,
                'identity_status' => 'matched',
                'capture_status' => 'verified',
                'quality_status' => 'verified',
                'reconciliation_status' => 'matched',
                'readback_status' => 'readback_verified',
            ]);
        }
    }

    /** @return array<string,mixed> */
    private function otaBinding(
        string $platform,
        int $sourceId,
        string $platformHotelId,
        int $systemHotelId
    ): array
    {
        return [
            'platform' => $platform,
            'status' => 'ready',
            'tenant_id' => 8,
            'system_hotel_id' => $systemHotelId,
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

    private static function createSchema(): void
    {
        Db::execute('CREATE TABLE hotel_collection_plans (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            system_hotel_id INTEGER NOT NULL,
            plan_version INTEGER NOT NULL,
            plan_status TEXT NOT NULL,
            enabled INTEGER NOT NULL,
            active_slot INTEGER NULL,
            business_date_policy TEXT NOT NULL,
            timezone TEXT NOT NULL,
            schedule_time TEXT NOT NULL,
            retry_interval_minutes INTEGER NOT NULL,
            max_attempts INTEGER NOT NULL,
            execution_owner_user_id INTEGER NULL,
            binding_digest TEXT NOT NULL,
            plan_hash TEXT NOT NULL,
            source_plan_json TEXT NOT NULL,
            validation_status TEXT NOT NULL,
            validation_reasons_json TEXT NOT NULL,
            activated_at TEXT NULL,
            created_by INTEGER NOT NULL,
            updated_by INTEGER NOT NULL,
            create_time TEXT NOT NULL,
            update_time TEXT NOT NULL,
            UNIQUE (tenant_id, system_hotel_id, plan_version),
            UNIQUE (tenant_id, system_hotel_id, active_slot)
        )');
        Db::execute('CREATE TABLE hotel_collection_plan_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            dispatcher_run_id TEXT NOT NULL,
            tenant_id INTEGER NOT NULL,
            system_hotel_id INTEGER NOT NULL,
            business_date TEXT NOT NULL,
            run_mode TEXT NOT NULL,
            plan_id INTEGER NULL,
            plan_version INTEGER NOT NULL,
            scope_hash TEXT NOT NULL,
            status TEXT NOT NULL,
            failure_stage TEXT NULL,
            failure_code TEXT NULL,
            collection_anchor_contract_version TEXT NULL,
            collection_anchor_hash TEXT NULL,
            trust_receipt_digest TEXT NULL,
            page_status TEXT NOT NULL,
            page_receipt_id INTEGER NULL,
            page_contract_hash TEXT NULL,
            pms_provider TEXT NULL,
            pms_status TEXT NOT NULL,
            pms_capture_id TEXT NULL,
            pms_readback_verified INTEGER NULL,
            started_at TEXT NULL,
            finished_at TEXT NULL
        )');
        Db::execute('CREATE TABLE hotel_collection_plan_run_sources (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            run_id INTEGER NOT NULL,
            platform TEXT NOT NULL,
            data_source_id INTEGER NULL,
            ingestion_method TEXT NOT NULL,
            platform_sync_task_id INTEGER NULL,
            local_collector_task_id INTEGER NULL,
            status TEXT NOT NULL,
            failure_stage TEXT NULL,
            failure_code TEXT NULL,
            saved_row_count INTEGER NOT NULL,
            readback_row_count INTEGER NOT NULL,
            readback_verified INTEGER NOT NULL,
            page_acceptance_status TEXT NOT NULL,
            page_acceptance_log_id INTEGER NULL,
            started_at TEXT NULL,
            finished_at TEXT NULL
        )');
        Db::execute('CREATE TABLE dingdandao_operating_target_captures (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            hotel_id INTEGER NOT NULL,
            provider TEXT NOT NULL,
            business_date TEXT NOT NULL,
            identity_status TEXT NOT NULL,
            capture_status TEXT NOT NULL,
            quality_status TEXT NOT NULL,
            reconciliation_status TEXT NOT NULL,
            readback_status TEXT NOT NULL
        )');
    }
}
