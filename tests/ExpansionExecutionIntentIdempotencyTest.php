<?php
declare(strict_types=1);

namespace Tests;

use app\service\ExpansionService;
use app\service\FeasibilityReportService;
use app\service\OpeningService;
use app\service\LlmClient;
use app\service\OperationManagementService;
use app\service\PriceSuggestionOtaTargetMappingService;
use app\service\OtaPublicPageDiagnosisService;
use app\service\QuantSimulationService;
use app\service\SourceBackedExecutionIntentIdentityService;
use app\service\SourceBackedExecutionBridgeProjectionService;
use app\service\TransferDecisionService;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;
use ReflectionProperty;
use RuntimeException;
use Throwable;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class ExpansionExecutionIntentIdempotencyTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();

        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'expansion_execution_intent_idempotency_' . getmypid() . '.sqlite';
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
            throw new RuntimeException('Unable to remove expansion idempotency SQLite fixture.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Db::name('operation_execution_evidence')->delete(true);
        Db::name('operation_execution_tasks')->delete(true);
        Db::name('operation_execution_intents')->delete(true);
        Db::name('quant_simulation_records')->delete(true);
        Db::name('opening_tasks')->delete(true);
        Db::name('opening_projects')->delete(true);
        Db::name('transfer_records')->delete(true);
        Db::name('feasibility_reports')->delete(true);
        Db::name('expansion_records')->delete(true);
        Db::name('price_suggestions')->delete(true);
        Db::name('room_types')->delete(true);
        Db::name('operation_alerts')->delete(true);
        Db::name('users')->delete(true);
        Db::name('hotels')->where('id', 7)->update(['tenant_id' => 7]);
    }

    public function testQuantSimulationSaveRequiresAuthoritativeHotelTableAndWritesNothing(): void
    {
        if ((int)Db::name('users')->where('id', 3)->count() === 0) {
            Db::name('users')->insert(['id' => 3, 'tenant_id' => 7, 'hotel_id' => 7]);
        } else {
            Db::name('users')->where('id', 3)->update(['tenant_id' => 7, 'hotel_id' => 7]);
        }
        $before = (int)Db::name('quant_simulation_records')->count();
        $client = new class extends LlmClient {
            public function createJsonResponse(
                array $messages,
                array $schema,
                string $modelKey = 'deepseek_v4_default'
            ): array {
                throw new RuntimeException('model access is not expected in hotel-scope rejection');
            }
        };

        Db::execute('ALTER TABLE hotels RENAME TO hotels_unavailable_for_quant_test');
        try {
            try {
                (new QuantSimulationService($client))->calculateAndSave([
                    'input' => [
                        'hotel_id' => 7,
                        'roomCount' => 10,
                        'decorationInvestment' => 100000,
                        'furnitureInvestment' => 50000,
                        'openingCost' => 20000,
                        'otherInvestment' => 0,
                        'adr' => 300,
                        'occupancyRate' => 60,
                        'otherIncome' => 0,
                        'monthlyRent' => 10000,
                        'laborCost' => 1000,
                        'utilityCost' => 1000,
                        'otaCommissionRate' => 10,
                        'consumableCost' => 1000,
                        'maintenanceCost' => 500,
                        'otherFixedCost' => 500,
                    ],
                ], 3, [7]);
                self::fail('Missing authoritative hotels table must reject quant simulation persistence');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('cannot be verified', $exception->getMessage());
            }
        } finally {
            Db::execute('ALTER TABLE hotels_unavailable_for_quant_test RENAME TO hotels');
        }

        self::assertSame($before, (int)Db::name('quant_simulation_records')->count());
    }

    public function testQuantRecordAccessFilterRunsBeforeTheThirtyRowLimitAndKeepsLegacyReadOnlyRows(): void
    {
        Db::name('users')->insert(['id' => 3, 'tenant_id' => 7, 'hotel_id' => 7]);
        $now = '2026-08-30 10:00:00';
        Db::name('quant_simulation_records')->insert([
            'tenant_id' => 7,
            'project_name' => 'legacy-visible',
            'input_json' => '{}',
            'result_json' => '{}',
            'scenarios_json' => '[]',
            'risk_hints_json' => '[]',
            'monthly_net_cashflow' => 0,
            'payback_months' => null,
            'risk_level' => '',
            'created_by' => 3,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        for ($index = 1; $index <= 31; $index++) {
            Db::name('quant_simulation_records')->insert([
                'tenant_id' => 7,
                'project_name' => 'newer-denied-' . $index,
                'input_json' => json_encode(['hotel_id' => 8, 'system_hotel_id' => 8], JSON_THROW_ON_ERROR),
                'result_json' => '{}',
                'scenarios_json' => '[]',
                'risk_hints_json' => '[]',
                'monthly_net_cashflow' => 0,
                'payback_months' => null,
                'risk_level' => '',
                'created_by' => 3,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $records = (new QuantSimulationService())->recordsForAccess(
            3,
            false,
            static fn(array $record): bool => ($record['access_policy']['mode'] ?? '') === 'legacy_read_only'
        );

        self::assertCount(1, $records);
        self::assertSame('legacy-visible', $records[0]['project_name']);
        self::assertSame('legacy_hotel_binding_required', $records[0]['access_policy']['reason_code']);
    }

    public function testTrustedExpansionIntentCreationReplaysTheExistingIntent(): void
    {
        $service = new OperationManagementService();
        $input = $this->expansionInput(19, 7);

        $first = $service->createExecutionIntent([7], 7, $input, 3, true);
        Db::name('operation_execution_intents')->where('id', (int)$first['id'])->update([
            'source_module' => ' Expansion ',
        ]);
        $second = $service->createExecutionIntent([7], 7, $input, 3, true);

        self::assertSame($first['id'], $second['id']);
        self::assertSame('expansion', $second['source_module']);
        self::assertSame(1, (int)Db::name('operation_execution_intents')->count());

        $row = Db::name('operation_execution_intents')->find((int)$first['id']);
        self::assertIsArray($row);
        self::assertSame(
            SourceBackedExecutionIntentIdentityService::key(['tenant_id' => 7] + $input, null),
            $row['idempotency_key']
        );
    }

    public function testExpansionSnapshotDriftCreatesANewGovernedLifecycleAndThenReplaysIt(): void
    {
        $operation = new OperationManagementService();
        $expansion = new ExpansionService();
        $first = $operation->createExecutionIntent([7], 7, $this->expansionInput(19, 7), 3, true);
        $expansion->attachExecutionTracking(19, 3, true, [
            'execution_intent_id' => (int)$first['id'],
            'hotel_id' => 7,
            'status' => (string)$first['status'],
        ]);

        $source = Db::name('expansion_records')->where('id', 19)->find();
        self::assertIsArray($source);
        $result = json_decode((string)$source['result_json'], true, 512, JSON_THROW_ON_ERROR);
        $result['business_fact'] = 'changed-after-first-intent';
        $result['decision_reason'] = 'new verified lease evidence';
        Db::name('expansion_records')->where('id', 19)->update([
            'result_json' => json_encode($result, JSON_THROW_ON_ERROR),
            'risk_level' => 'high',
            'updated_at' => '2026-08-13 12:00:00',
        ]);

        $currentInput = $this->expansionInput(19, 7);
        $second = $operation->createExecutionIntent([7], 7, $currentInput, 3, true);
        self::assertNotSame((int)$first['id'], (int)$second['id']);
        self::assertFalse(($second['idempotent_replay'] ?? false) === true);

        $linked = $expansion->attachExecutionTracking(19, 3, true, [
            'execution_intent_id' => (int)$second['id'],
            'hotel_id' => 7,
            'status' => (string)$second['status'],
        ]);
        self::assertSame((int)$second['id'], (int)$linked['execution_intent_id']);
        self::assertSame(
            [(int)$first['id'], (int)$second['id']],
            array_map('intval', array_column($linked['result']['execution_tracking'], 'execution_intent_id'))
        );

        $replay = $operation->createExecutionIntent([7], 7, $currentInput, 3, true);
        self::assertSame((int)$second['id'], (int)$replay['id']);
        self::assertTrue($replay['idempotent_replay']);

        try {
            $operation->approveExecutionIntent((int)$first['id'], true, 'must stay stale', 3, [7]);
            self::fail('The superseded expansion snapshot must remain ineligible for approval.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('changed', $exception->getMessage());
        }
        $approved = $operation->approveExecutionIntent((int)$second['id'], true, 'approve current lifecycle', 3, [7]);
        self::assertSame('approved', $approved['status']);
    }

    public function testExpansionTenantMigrationIgnoresTheOldTenantLifecycleButDoesNotExposeIt(): void
    {
        $operation = new OperationManagementService();
        $expansion = new ExpansionService();
        $first = $operation->createExecutionIntent([7], 7, $this->expansionInput(19, 7), 3, true);
        $expansion->attachExecutionTracking(19, 3, true, [
            'execution_intent_id' => (int)$first['id'], 'hotel_id' => 7, 'status' => (string)$first['status'],
        ]);

        Db::name('hotels')->where('id', 7)->update(['tenant_id' => 8]);
        Db::name('expansion_records')->where('id', 19)->update([
            'tenant_id' => 8,
            'updated_at' => '2026-08-13 12:05:00',
        ]);
        $currentInput = $this->expansionInput(19, 7);

        $second = $operation->createExecutionIntent([7], 7, $currentInput, 3, true);
        self::assertNotSame((int)$first['id'], (int)$second['id']);
        self::assertSame(8, (int)$second['tenant_id']);
        $linked = $expansion->attachExecutionTracking(19, 3, true, [
            'execution_intent_id' => (int)$second['id'], 'hotel_id' => 7, 'status' => (string)$second['status'],
        ]);
        self::assertSame((int)$second['id'], (int)$linked['execution_intent_id']);
        self::assertSame(
            [(int)$first['id'], (int)$second['id']],
            array_map('intval', array_column($linked['result']['execution_tracking'], 'execution_intent_id'))
        );
        $replay = $operation->createExecutionIntent([7], 7, $currentInput, 3, true);
        self::assertSame((int)$second['id'], (int)$replay['id']);
        self::assertTrue($replay['idempotent_replay']);

        try {
            $operation->readExecutionIntent((int)$first['id'], [7]);
            self::fail('The old tenant lifecycle must not be readable after the hotel transfer.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('current tenant scope', $exception->getMessage());
        }
        self::assertSame((int)$second['id'], (int)$operation->readExecutionIntent((int)$second['id'], [7])['id']);
    }

    public function testLegacyExpansionKeyOnlyConvergesForTheSameTenantHotelAndSnapshot(): void
    {
        $operation = new OperationManagementService();
        $input = $this->expansionInput(19, 7);
        $first = $operation->createExecutionIntent([7], 7, $input, 3, true);
        Db::name('operation_execution_intents')->where('id', (int)$first['id'])->update([
            'idempotency_key' => 'expansion:v1:19',
        ]);

        $same = $operation->createExecutionIntent([7], 7, $input, 3, true);
        self::assertSame((int)$first['id'], (int)$same['id']);
        self::assertTrue($same['idempotent_replay']);

        $source = Db::name('expansion_records')->where('id', 19)->find();
        self::assertIsArray($source);
        $result = json_decode((string)$source['result_json'], true, 512, JSON_THROW_ON_ERROR);
        $result['business_fact'] = 'digest-drift';
        Db::name('expansion_records')->where('id', 19)->update([
            'result_json' => json_encode($result, JSON_THROW_ON_ERROR),
            'updated_at' => '2026-08-13 12:10:00',
        ]);
        $changed = $operation->createExecutionIntent([7], 7, $this->expansionInput(19, 7), 3, true);
        self::assertNotSame((int)$first['id'], (int)$changed['id']);
        self::assertFalse(($changed['idempotent_replay'] ?? false) === true);
    }

    public function testLegacyExpansionKeyDoesNotConvergeAcrossATenantMigration(): void
    {
        $operation = new OperationManagementService();
        $first = $operation->createExecutionIntent([7], 7, $this->expansionInput(19, 7), 3, true);
        Db::name('operation_execution_intents')->where('id', (int)$first['id'])->update([
            'idempotency_key' => 'expansion:v1:19',
        ]);
        Db::name('hotels')->where('id', 7)->update(['tenant_id' => 8]);
        Db::name('expansion_records')->where('id', 19)->update(['tenant_id' => 8]);

        $current = $operation->createExecutionIntent([7], 7, $this->expansionInput(19, 7), 3, true);
        self::assertNotSame((int)$first['id'], (int)$current['id']);
        self::assertSame(8, (int)$current['tenant_id']);
        self::assertMatchesRegularExpression(
            '/^source_intent_[a-f0-9]{32}$/D',
            (string)Db::name('operation_execution_intents')->where('id', (int)$current['id'])->value('idempotency_key')
        );
    }

    public function testExpansionIdentityKeyCollisionCannotReplayAMismatchedStoredSnapshot(): void
    {
        $operation = new OperationManagementService();
        $input = $this->expansionInput(19, 7);
        $first = $operation->createExecutionIntent([7], 7, $input, 3, true);
        $evidence = $first['evidence'];
        $evidence['source_snapshot_digest'] = str_repeat('f', 64);
        Db::name('operation_execution_intents')->where('id', (int)$first['id'])->update([
            'evidence_json' => json_encode($evidence, JSON_THROW_ON_ERROR),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('snapshot changed');
        $operation->createExecutionIntent([7], 7, $input, 3, true);
    }

    public function testExpansionControllerDelegatesEveryLifecycleDecisionToTheOperationService(): void
    {
        $controller = (string)file_get_contents(__DIR__ . '/../app/controller/Expansion.php');
        $start = strpos($controller, 'public function createExecutionIntent');
        $end = strpos($controller, 'public function archive', $start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $method = substr($controller, $start, $end - $start);

        self::assertStringNotContainsString('$linkedIntentId', $method);
        self::assertStringNotContainsString('readExecutionIntent(', $method);
        self::assertStringContainsString('buildExecutionIntentInput(', $method);
        self::assertStringContainsString('createExecutionIntent(', $method);
        self::assertStringContainsString("(\$intent['idempotent_replay'] ?? false) === true", $method);
    }

    public function testExpansionApprovalRejectsArchivedAndChangedBusinessSnapshotsWithoutWritingTasks(): void
    {
        $operation = new OperationManagementService();
        $expansion = new ExpansionService();

        $this->insertExpansionFixture(301, 7);
        $archivedInput = $expansion->buildExecutionIntentInput(
            $expansion->detail(301, 3, true),
            7,
            ['date_start' => '2026-08-13', 'date_end' => '2026-08-13']
        );
        $archivedIntent = $operation->createExecutionIntent([7], 7, $archivedInput, 3, true);
        $archivedBefore = Db::name('operation_execution_intents')->where('id', (int)$archivedIntent['id'])->find();
        Db::name('expansion_records')->where('id', 301)->update([
            'deleted_at' => '2026-08-13 10:00:00',
            'updated_at' => '2026-08-13 10:00:00',
        ]);
        try {
            $operation->approveExecutionIntent((int)$archivedIntent['id'], true, 'must not approve', 3, [7]);
            self::fail('An archived expansion source must invalidate approval.');
        } catch (InvalidArgumentException|RuntimeException $exception) {
            self::assertMatchesRegularExpression('/source|tenant|current|identity/i', $exception->getMessage());
        }
        self::assertSame($archivedBefore, Db::name('operation_execution_intents')->where('id', (int)$archivedIntent['id'])->find());
        self::assertSame(0, (int)Db::name('operation_execution_tasks')->where('intent_id', (int)$archivedIntent['id'])->count());

        $mutations = [
            'decision' => static fn(array $row): array => ['decision' => 'different decision'],
            'risk' => static fn(array $row): array => ['risk_level' => 'high'],
            'input' => static function (array $row): array {
                $input = json_decode((string)$row['input_json'], true, 512, JSON_THROW_ON_ERROR);
                $input['property_area'] = 3601;
                return ['input_json' => json_encode($input, JSON_THROW_ON_ERROR)];
            },
            'result' => static function (array $row): array {
                $result = json_decode((string)$row['result_json'], true, 512, JSON_THROW_ON_ERROR);
                $result['business_fact'] = 'changed';
                return ['result_json' => json_encode($result, JSON_THROW_ON_ERROR)];
            },
            'readiness' => static function (array $row): array {
                $input = json_decode((string)$row['input_json'], true, 512, JSON_THROW_ON_ERROR);
                $input['review_status'] = 'approved';
                return ['input_json' => json_encode($input, JSON_THROW_ON_ERROR)];
            },
        ];
        $recordId = 310;
        foreach ($mutations as $label => $mutation) {
            $this->insertExpansionFixture($recordId, 7);
            $input = $expansion->buildExecutionIntentInput(
                $expansion->detail($recordId, 3, true),
                7,
                ['date_start' => '2026-08-13', 'date_end' => '2026-08-13']
            );
            $intent = $operation->createExecutionIntent([7], 7, $input, 3, true);
            $before = Db::name('operation_execution_intents')->where('id', (int)$intent['id'])->find();
            $source = Db::name('expansion_records')->where('id', $recordId)->find();
            self::assertIsArray($source);
            Db::name('expansion_records')->where('id', $recordId)->update($mutation($source));
            try {
                $operation->approveExecutionIntent((int)$intent['id'], true, 'must not approve', 3, [7]);
                self::fail($label . ' source mutation must invalidate expansion approval.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('snapshot changed', $exception->getMessage(), $label);
            }
            self::assertSame($before, Db::name('operation_execution_intents')->where('id', (int)$intent['id'])->find(), $label);
            self::assertSame(0, (int)Db::name('operation_execution_tasks')->where('intent_id', (int)$intent['id'])->count(), $label);
            $recordId++;
        }
    }

    public function testExpansionCreationRejectsCrossTenantSourceEvenForSuperAdminReadPath(): void
    {
        $this->insertExpansionFixture(401, 8);
        $expansion = new ExpansionService();
        $recordVisibleToSuperAdmin = $expansion->detail(401, 3, true);
        self::assertSame(8, (int)Db::name('expansion_records')->where('id', 401)->value('tenant_id'));
        $input = $expansion->buildExecutionIntentInput($recordVisibleToSuperAdmin, 7, [
            'date_start' => '2026-08-13',
            'date_end' => '2026-08-13',
        ]);

        try {
            (new OperationManagementService())->createExecutionIntent([7], 7, $input, 3, true);
            self::fail('Super-admin source visibility must not allow cross-tenant expansion execution binding.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('tenant scope', $exception->getMessage());
        }
        self::assertSame(0, (int)Db::name('operation_execution_intents')->count());
    }

    public function testCurrentSameTenantExpansionSourceCanStillBeApproved(): void
    {
        $this->insertExpansionFixture(402, 7);
        $expansion = new ExpansionService();
        $input = $expansion->buildExecutionIntentInput($expansion->detail(402, 3, true), 7, [
            'date_start' => '2026-08-13',
            'date_end' => '2026-08-13',
        ]);
        $operation = new OperationManagementService();
        $intent = $operation->createExecutionIntent([7], 7, $input, 3, true);
        $approved = $operation->approveExecutionIntent((int)$intent['id'], true, 'approved', 3, [7]);

        self::assertSame('approved', $approved['status']);
        self::assertCount(1, $approved['tasks']);
        self::assertSame(7, (int)$approved['tenant_id']);
        self::assertSame(7, (int)$approved['tasks'][0]['tenant_id']);
    }

    public function testExecutionIntentAndTaskDetailFailClosedWhenHotelScopeCannotBeQueried(): void
    {
        $service = new OperationManagementService();
        $intentId = $this->insertSourceIntent('manual', 403, 7, 7);
        $approved = $service->approveExecutionIntent($intentId, true, 'fixture approval', 3, [7]);
        $taskId = (int)($approved['tasks'][0]['id'] ?? 0);
        self::assertGreaterThan(0, $taskId);

        Db::execute('ALTER TABLE hotels RENAME TO hotels_detail_scope_backup');
        try {
            foreach ([
                'intent' => fn(): array => $service->readExecutionIntent($intentId, [7]),
                'task' => fn(): array => $service->readExecutionTask($taskId, [7]),
            ] as $label => $read) {
                try {
                    $read();
                    self::fail($label . ' detail must not return tenant data without the hotel scope table.');
                } catch (RuntimeException $exception) {
                    self::assertStringContainsString('migration_required', $exception->getMessage(), $label);
                }
            }

            Db::execute('CREATE VIEW hotels AS SELECT id, tenant_id FROM missing_hotel_scope_source');
            foreach ([
                'intent query error' => fn(): array => (new OperationManagementService())->readExecutionIntent($intentId, [7]),
                'task query error' => fn(): array => (new OperationManagementService())->readExecutionTask($taskId, [7]),
            ] as $label => $read) {
                try {
                    $read();
                    self::fail($label . ' must fail closed when hotel scope lookup raises an error.');
                } catch (RuntimeException $exception) {
                    self::assertStringContainsString(
                        'database_table_probe_failed:hotels',
                        $exception->getMessage(),
                        $label
                    );
                }
            }
            Db::execute('DROP VIEW hotels');
        } finally {
            try {
                Db::execute('DROP VIEW IF EXISTS hotels');
            } catch (Throwable) {
            }
            Db::execute('ALTER TABLE hotels_detail_scope_backup RENAME TO hotels');
        }
    }

    public function testTrustedPriceSuggestionIntentCreationReplaysTheExistingIntent(): void
    {
        $service = new OperationManagementService();
        $this->insertApprovedPriceSuggestion(88, 7, 7);
        $input = $this->priceSuggestionInput(88, 7);

        $first = $service->createExecutionIntent([7], 7, $input, 3, false, null, true);
        $second = $service->createExecutionIntent([7], 7, $input, 3, false, null, true);

        self::assertSame($first['id'], $second['id']);
        self::assertTrue($second['idempotent_replay']);
        self::assertSame(1, (int)Db::name('operation_execution_intents')->count());
        self::assertMatchesRegularExpression(
            '/^source_intent_[a-f0-9]{32}$/D',
            (string)Db::name('operation_execution_intents')->value('idempotency_key')
        );
    }

    public function testPriceSuggestionCreationFailsClosedWhenLockedSourceDriftedAfterInputRead(): void
    {
        $service = new OperationManagementService();
        $this->insertApprovedPriceSuggestion(88, 7, 7);
        $staleInput = $this->priceSuggestionInput(88, 7);
        $source = Db::name('price_suggestions')->where('id', 88)->find();
        self::assertIsArray($source);
        $factors = json_decode((string)$source['factors'], true, 512, JSON_THROW_ON_ERROR);
        $factors['manual_review_versions'] = [['action' => 'approve_with_changes', 'approved_price' => 338]];
        Db::name('price_suggestions')->where('id', 88)->update([
            'suggested_price' => 338,
            'factors' => json_encode($factors, JSON_THROW_ON_ERROR),
        ]);

        try {
            $service->createExecutionIntent([7], 7, $staleInput, 3, false, null, true);
            self::fail('A stale price-suggestion snapshot must not be persisted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('changed', $exception->getMessage());
        }
        self::assertSame(0, (int)Db::name('operation_execution_intents')->count());

        $freshInput = $this->priceSuggestionInput(88, 7);
        $fresh = $service->createExecutionIntent([7], 7, $freshInput, 3, false, null, true);
        self::assertGreaterThan(0, (int)$fresh['id']);
    }

    public function testPriceSuggestionCreationFailsClosedForMissingTenantHotelApprovalAndSnapshotFacts(): void
    {
        $service = new OperationManagementService();
        foreach ([
            'missing source' => static fn(): int => Db::name('price_suggestions')->where('id', 88)->delete(),
            'missing tenant' => static fn(): int => Db::name('price_suggestions')->where('id', 88)->update(['tenant_id' => 0]),
            'different hotel' => static fn(): int => Db::name('price_suggestions')->where('id', 88)->update(['hotel_id' => 8]),
            'not approved' => static fn(): int => Db::name('price_suggestions')->where('id', 88)->update(['status' => 1]),
            'missing approver' => static fn(): int => Db::name('price_suggestions')->where('id', 88)->update(['applied_by' => 0]),
        ] as $label => $drift) {
            Db::name('price_suggestions')->delete(true);
            $this->insertApprovedPriceSuggestion(88, 7, 7);
            $input = $this->priceSuggestionInput(88, 7);
            $drift();
            try {
                $service->createExecutionIntent([7], 7, $input, 3, false, null, true);
                self::fail($label . ' must fail closed.');
            } catch (Throwable) {
                self::assertSame(0, (int)Db::name('operation_execution_intents')->count(), $label);
            }
        }

        Db::name('price_suggestions')->delete(true);
        $this->insertApprovedPriceSuggestion(88, 7, 7);
        $missingDigest = $this->priceSuggestionInput(88, 7);
        unset($missingDigest['evidence']['source_snapshot_digest']);
        try {
            $service->createExecutionIntent([7], 7, $missingDigest, 3, false, null, true);
            self::fail('Missing current snapshot digest must fail closed.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('snapshot changed', $exception->getMessage());
        }
        self::assertSame(0, (int)Db::name('operation_execution_intents')->count());
    }

    public function testPriceSuggestionSourceDigestCreatesOneLifecyclePerBusinessSnapshot(): void
    {
        $service = new OperationManagementService();
        $this->insertApprovedPriceSuggestion(88, 7, 7);
        $firstInput = $this->priceSuggestionInput(88, 7);
        $first = $service->createExecutionIntent([7], 7, $firstInput, 3, false, null, true);

        Db::name('price_suggestions')->where('id', 88)->update([
            'suggested_price' => 336,
            'remark' => 'approved business snapshot v2',
        ]);
        $secondInput = $this->priceSuggestionInput(88, 7);
        $second = $service->createExecutionIntent([7], 7, $secondInput, 3, false, null, true);
        $replay = $service->createExecutionIntent([7], 7, $secondInput, 3, false, null, true);

        self::assertNotSame($first['id'], $second['id']);
        self::assertSame($second['id'], $replay['id']);
        self::assertTrue($replay['idempotent_replay']);
        self::assertSame(2, (int)Db::name('operation_execution_intents')->count());
    }

    public function testPriceSuggestionReplayIdentityIncludesTheExactExecutionTarget(): void
    {
        $service = new OperationManagementService();
        $this->insertApprovedPriceSuggestion(88, 7, 7);
        $firstInput = $this->priceSuggestionInput(88, 7);
        $first = $service->createExecutionIntent([7], 7, $firstInput, 3, false, null, true);
        $same = $service->createExecutionIntent([7], 7, $firstInput, 3, false, null, true);

        $differentRatePlan = $firstInput;
        $differentRatePlan['target_value']['rate_plan_key'] = 'non-refundable';
        try {
            $service->createExecutionIntent([7], 7, $differentRatePlan, 3, false, null, true);
            self::fail('An arbitrary rate-plan key must not create a separate lifecycle.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('mapping changed', $exception->getMessage());
        }

        self::assertSame($first['id'], $same['id']);
        self::assertTrue($same['idempotent_replay']);
        self::assertSame(1, (int)Db::name('operation_execution_intents')->count());
    }

    public function testPriceSuggestionExecutionContractFieldsEachCreateANewNormalizedLifecycle(): void
    {
        $service = new OperationManagementService();
        $this->insertApprovedPriceSuggestion(88, 7, 7);
        $baseInput = $this->priceSuggestionInput(88, 7);
        $base = $service->createExecutionIntent([7], 7, $baseInput, 3, false, null, true);

        $normalized = $baseInput;
        $normalized['expected_metric'] = ' ORDERS ';
        $normalized['risk_level'] = ' MEDIUM ';
        self::assertSame(
            (int)$base['id'],
            (int)$service->createExecutionIntent([7], 7, $normalized, 3, false, null, true)['id']
        );

        foreach ([
            'expected_metric' => 'revenue',
            'expected_delta' => 2,
            'risk_level' => 'high',
        ] as $field => $value) {
            $changed = $baseInput;
            $changed[$field] = $value;
            $lifecycle = $service->createExecutionIntent([7], 7, $changed, 3, false, null, true);
            self::assertNotSame((int)$base['id'], (int)$lifecycle['id'], $field);
        }
        self::assertSame(4, (int)Db::name('operation_execution_intents')->count());
        $stored = Db::name('operation_execution_intents')->where('id', (int)$base['id'])->find();
        self::assertSame('orders', (string)$stored['expected_metric']);
        self::assertSame(1.0, (float)$stored['expected_delta']);
        self::assertSame('medium', (string)$stored['risk_level']);
        self::assertSame(
            'approved',
            $service->approveExecutionIntent((int)$base['id'], true, 'approve persisted contract', 3, [7])['status']
        );
    }

    public function testPriceSuggestionOtaTargetMappingRejectsWrongKeyCrossHotelAndMappingDrift(): void
    {
        $service = new OperationManagementService();
        $this->insertApprovedPriceSuggestion(88, 7, 7);
        $input = $this->priceSuggestionInput(88, 7);
        $wrongKey = $input;
        $wrongKey['target_value']['room_type_key'] = 'foreign-room';
        try {
            $service->createExecutionIntent([7], 7, $wrongKey, 3, false, null, true);
            self::fail('An arbitrary OTA room key must not create an execution lifecycle.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('mapping changed', $exception->getMessage());
        }

        Db::name('room_types')->where('id', 3)->update(['hotel_id' => 8]);
        try {
            $service->createExecutionIntent([7], 7, $input, 3, false, null, true);
            self::fail('A room mapping owned by another hotel must fail closed.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('current tenant and hotel', $exception->getMessage());
        }
        Db::name('room_types')->where('id', 3)->update(['hotel_id' => 7]);

        $lifecycle = $service->createExecutionIntent([7], 7, $input, 3, false, null, true);
        $approved = $service->approveExecutionIntent((int)$lifecycle['id'], true, 'approve mapped target', 3, [7]);
        $taskId = (int)($approved['tasks'][0]['id'] ?? 0);
        $source = Db::name('price_suggestions')->where('id', 88)->find();
        $factors = json_decode((string)$source['factors'], true, 512, JSON_THROW_ON_ERROR);
        $factors[PriceSuggestionOtaTargetMappingService::FACTOR_KEY]['mapping_version'] = 'v2';
        $factors[PriceSuggestionOtaTargetMappingService::FACTOR_KEY]['rate_plan_key'] = 'NONREF';
        $factors[PriceSuggestionOtaTargetMappingService::FACTOR_KEY]['mapping_digest'] =
            PriceSuggestionOtaTargetMappingService::mappingDigest(
                $factors[PriceSuggestionOtaTargetMappingService::FACTOR_KEY]
            );
        Db::name('price_suggestions')->where('id', 88)->update([
            'factors' => json_encode($factors, JSON_THROW_ON_ERROR),
        ]);
        $taskBefore = Db::name('operation_execution_tasks')->where('id', $taskId)->find();
        try {
            $service->executeExecutionTask($taskId, [7], ['status' => 'executing'], 3);
            self::fail('A task must reject a mapping version or rate-plan drift.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('mapping changed', $exception->getMessage());
        }
        self::assertSame($taskBefore, Db::name('operation_execution_tasks')->where('id', $taskId)->find());

        $currentInput = $this->priceSuggestionInput(88, 7);
        $pending = $service->createExecutionIntent([7], 7, $currentInput, 3, false, null, true);
        self::assertNotSame((int)$lifecycle['id'], (int)$pending['id']);
        $source = Db::name('price_suggestions')->where('id', 88)->find();
        self::assertIsArray($source);
        $factors = json_decode((string)$source['factors'], true, 512, JSON_THROW_ON_ERROR);
        $factors[PriceSuggestionOtaTargetMappingService::FACTOR_KEY]['mapping_version'] = 'v3';
        $factors[PriceSuggestionOtaTargetMappingService::FACTOR_KEY]['mapping_digest'] =
            PriceSuggestionOtaTargetMappingService::mappingDigest(
                $factors[PriceSuggestionOtaTargetMappingService::FACTOR_KEY]
            );
        Db::name('price_suggestions')->where('id', 88)->update([
            'factors' => json_encode($factors, JSON_THROW_ON_ERROR),
        ]);
        try {
            $service->approveExecutionIntent((int)$pending['id'], true, 'approve stale mapping', 3, [7]);
            self::fail('A pending intent must reject mapping drift before approval.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('mapping changed', $exception->getMessage());
        }
        self::assertSame(
            'pending_approval',
            (string)Db::name('operation_execution_intents')->where('id', (int)$pending['id'])->value('status')
        );
    }

    public function testOperationAlertMaterialDriftRejectsOldApprovalAndTaskButAllowsCurrentLifecycle(): void
    {
        $service = new OperationManagementService();
        $this->insertOperationAlert(501, 'Observed 2.4%', 2.4);
        $first = $service->createExecutionIntentFromAlert(501, [7], 3)['execution_intent'];
        $approved = $service->approveExecutionIntent((int)$first['id'], true, 'approve v1', 3, [7]);
        $taskId = (int)($approved['tasks'][0]['id'] ?? 0);
        self::assertGreaterThan(0, $taskId);

        Db::name('operation_alerts')->where('id', 501)->update([
            'message' => 'Observed 1.8%',
            'raw_data' => json_encode([
                'metric_key' => 'ota_conversion_rate', 'threshold_value' => 3,
                'observed_value' => 1.8, 'comparison_rule' => 'observed_value < threshold_value',
                'action_suggestion' => 'Review conversion funnel',
            ], JSON_THROW_ON_ERROR),
            'status' => 'unread',
        ]);
        $taskBefore = Db::name('operation_execution_tasks')->where('id', $taskId)->find();
        try {
            $service->executeExecutionTask($taskId, [7], ['status' => 'executing'], 3);
            self::fail('Task from the previous alert evidence must be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('snapshot changed', $exception->getMessage());
        }
        self::assertSame($taskBefore, Db::name('operation_execution_tasks')->where('id', $taskId)->find());

        $second = $service->createExecutionIntentFromAlert(501, [7], 3)['execution_intent'];
        self::assertNotSame((int)$first['id'], (int)$second['id']);
        Db::name('operation_alerts')->where('id', 501)->update([
            'message' => 'Observed 1.2%', 'status' => 'unread',
        ]);
        try {
            $service->approveExecutionIntent((int)$second['id'], true, 'must reject stale v2', 3, [7]);
            self::fail('Pending intent from the previous alert evidence must be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('snapshot changed', $exception->getMessage());
        }

        $third = $service->createExecutionIntentFromAlert(501, [7], 3)['execution_intent'];
        self::assertNotSame((int)$second['id'], (int)$third['id']);
        self::assertSame('approved', $service->approveExecutionIntent(
            (int)$third['id'], true, 'approve current alert evidence', 3, [7]
        )['status']);
    }

    public function testPriceSuggestionApprovalAndTaskMutationRejectStaleSnapshotAndTenantMigration(): void
    {
        $service = new OperationManagementService();
        $this->insertApprovedPriceSuggestion(88, 7, 7);
        $this->insertApprovedPriceSuggestion(89, 7, 7);

        $taskLifecycle = $service->createExecutionIntent(
            [7],
            7,
            $this->priceSuggestionInput(88, 7),
            3,
            false,
            null,
            true
        );
        $approved = $service->approveExecutionIntent((int)$taskLifecycle['id'], true, 'approved', 3, [7]);
        $taskId = (int)($approved['tasks'][0]['id'] ?? 0);
        self::assertGreaterThan(0, $taskId);

        $pending = $service->createExecutionIntent(
            [7],
            7,
            $this->priceSuggestionInput(89, 7),
            3,
            false,
            null,
            true
        );
        Db::name('price_suggestions')->where('id', 89)->update(['status' => 3]);
        try {
            $service->approveExecutionIntent((int)$pending['id'], true, 'must fail', 3, [7]);
            self::fail('A no-longer-approved source must not approve its execution intent.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('approved', $exception->getMessage());
        }
        self::assertSame('pending_approval', (string)Db::name('operation_execution_intents')
            ->where('id', (int)$pending['id'])->value('status'));

        Db::name('hotels')->where('id', 7)->update(['tenant_id' => 8]);
        Db::name('price_suggestions')->where('id', 88)->update(['tenant_id' => 8]);
        $taskBefore = Db::name('operation_execution_tasks')->where('id', $taskId)->find();
        try {
            $service->executeExecutionTask($taskId, [7], ['status' => 'executing'], 3);
            self::fail('A pre-transfer task must not be mutated by the new tenant.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('current tenant scope', $exception->getMessage());
        }
        self::assertSame($taskBefore, Db::name('operation_execution_tasks')->where('id', $taskId)->find());

        try {
            $service->createExecutionIntent([7], 7, $this->priceSuggestionInput(88, 7), 3, false, null, true);
            self::fail('The previous tenant snapshot must not be replayed after a hotel transfer.');
        } catch (RuntimeException|InvalidArgumentException $exception) {
            self::assertStringContainsString('tenant', strtolower($exception->getMessage()));
        }

        $this->insertApprovedPriceSuggestion(90, 7, 8);
        $newTenantLifecycle = $service->createExecutionIntent(
            [7],
            7,
            $this->priceSuggestionInput(90, 7),
            4,
            false,
            null,
            true
        );
        self::assertSame(8, (int)$newTenantLifecycle['tenant_id']);
        self::assertSame(90, (int)$newTenantLifecycle['source_record_id']);
    }

    public function testTrustedSourceBackedIntentCreationUsesDatabaseIdempotency(): void
    {
        $service = new OperationManagementService();
        $this->insertTransferRecord(91, 7, []);
        $transfer = new TransferDecisionService();
        $input = $transfer->buildExecutionIntentInput(
            $transfer->detail(91, [7], 3, true),
            [
            'date_start' => '2026-08-13',
            'date_end' => '2026-08-13',
            ]
        );

        $first = $service->createExecutionIntent([7], 7, $input, 3, false, null, true);
        $second = $service->createExecutionIntent([7], 7, $input, 3, false, null, true);

        self::assertSame($first['id'], $second['id']);
        self::assertTrue($second['idempotent_replay']);
        self::assertSame(1, (int)Db::name('operation_execution_intents')->count());
        self::assertMatchesRegularExpression(
            '/^source_intent_[a-f0-9]{32}$/D',
            (string)Db::name('operation_execution_intents')->value('idempotency_key')
        );
    }

    public function testSourceSnapshotDigestIgnoresOnlyExecutionLinkMetadata(): void
    {
        $base = [
            'project' => ['id' => 31, 'hotel_id' => 7, 'status' => 'preparing'],
            'tasks' => [['id' => 41, 'status' => 'doing', 'progress_percent' => 50]],
        ];
        $linked = $base;
        $linked['project']['execution_intent_id'] = 91;
        $linked['project']['post_decision_tracking'] = ['latest_execution_intent_id' => 91];
        $linked['tasks'][0]['execution_tracking'] = [['execution_intent_id' => 91]];
        $changed = $linked;
        $changed['tasks'][0]['progress_percent'] = 100;

        self::assertSame(
            SourceBackedExecutionIntentIdentityService::snapshotDigest('opening', $base),
            SourceBackedExecutionIntentIdentityService::snapshotDigest('opening', $linked)
        );
        self::assertNotSame(
            SourceBackedExecutionIntentIdentityService::snapshotDigest('opening', $base),
            SourceBackedExecutionIntentIdentityService::snapshotDigest('opening', $changed)
        );
    }

    public function testSourceBackedIdentitySupportsCanonicalReservedModuleNames(): void
    {
        foreach ([
            ' Expansion ',
            ' Opening ',
            'TRANSFER_DECISION',
            ' Feasibility_Report ',
            'Strategy_Simulation',
            ' Quant_Simulation ',
        ] as $sourceModule) {
            self::assertTrue(
                SourceBackedExecutionIntentIdentityService::supports(['source_module' => $sourceModule]),
                $sourceModule . ' must stay inside the source-backed authorization path'
            );
        }
        self::assertFalse(SourceBackedExecutionIntentIdentityService::supports(['source_module' => 'crm']));
        $identity = ['tenant_id' => 7, 'source_module' => 'opening', 'source_record_id' => 91, 'hotel_id' => 7];
        self::assertSame(
            SourceBackedExecutionIntentIdentityService::key($identity, null),
            SourceBackedExecutionIntentIdentityService::key(array_replace($identity, ['source_module' => ' Opening ']), null)
        );
    }

    public function testSourceSnapshotDigestIgnoresEveryDeclaredBridgeFieldAcrossSourceModules(): void
    {
        $base = [
            'business' => [
                'tracking_records' => ['business_note' => 'ordinary business term without a bridge structure'],
                'status' => 'stable',
            ],
        ];
        $linked = $base + [
            'Tracking_Records' => ['bridge' => ['Execution_Intent_Id' => 91]],
            'EXECUTION_TRACKING' => [['Execution_Intent_Id' => 91]],
            'POST_DECISION_TRACKING' => ['Latest_Execution_Intent_Id' => 91],
            'Tracking_Record_Id' => 92,
            'Post_Decision_Tracking_Id' => 93,
            'Opening_Project_Id' => 94,
            'Investment_Tracking_Id' => 95,
            'Operation_Execution_Intent_Id' => 96,
            'Execution_Status' => 'linked',
            'Execution_Idempotency_Key' => 'bridge-only',
        ];
        $changed = $linked;
        $changed['business']['status'] = 'changed';
        $ordinarySameNameChanged = $base;
        $ordinarySameNameChanged['business']['tracking_records']['business_note'] = 'changed ordinary business term';

        foreach (['expansion', 'opening', 'transfer_decision', 'feasibility_report', 'strategy_simulation', 'quant_simulation'] as $module) {
            self::assertSame(
                SourceBackedExecutionIntentIdentityService::snapshotDigest($module, $base),
                SourceBackedExecutionIntentIdentityService::snapshotDigest($module, $linked),
                $module . ' bridge projection must not change business snapshot identity'
            );
            self::assertNotSame(
                SourceBackedExecutionIntentIdentityService::snapshotDigest($module, $base),
                SourceBackedExecutionIntentIdentityService::snapshotDigest($module, $changed),
                $module . ' real business fact must change business snapshot identity'
            );
            self::assertNotSame(
                SourceBackedExecutionIntentIdentityService::snapshotDigest($module, $base),
                SourceBackedExecutionIntentIdentityService::snapshotDigest($module, $ordinarySameNameChanged),
                $module . ' ordinary same-name business fact must not be broadly deleted'
            );
        }
    }

    public function testSourceBackedIdentityKeyIgnoresMixedCaseBridgeCollectionsWithoutDigestFallback(): void
    {
        $base = [
            'tenant_id' => 7,
            'source_module' => 'transfer_decision',
            'source_record_id' => 91,
            'hotel_id' => 7,
            'platform' => 'investment',
            'object_type' => 'investment',
            'action_type' => 'post_decision_tracking',
            'date_start' => '2026-08-13',
            'date_end' => '2026-08-13',
            'current_value' => ['business_status' => 'stable'],
        ];
        $linked = $base;
        $linked['current_value']['Tracking_Records'] = ['bridge' => ['Execution_Intent_Id' => 91]];
        $changed = $linked;
        $changed['current_value']['business_status'] = 'changed';

        self::assertSame(
            SourceBackedExecutionIntentIdentityService::key($base, null),
            SourceBackedExecutionIntentIdentityService::key($linked, null)
        );
        self::assertNotSame(
            SourceBackedExecutionIntentIdentityService::key($base, null),
            SourceBackedExecutionIntentIdentityService::key($changed, null)
        );
    }

    public function testTransferDetailBridgeKeepsTrackingRecordsVisibleButReusesBusinessDigestAndIdentityKey(): void
    {
        $intentId = $this->insertSourceIntent('transfer_decision', 79, 7, 7);
        $this->insertTransferRecord(79, 7, ['business_status' => 'stable']);
        $service = new TransferDecisionService();
        $before = $service->buildExecutionIntentInput($service->detail(79, [7], 3, true));

        $linkedPayload = [
            'business_status' => 'stable',
            'Tracking_Records' => ['bridge' => [
                'Execution_Intent_Id' => $intentId,
                'Hotel_Id' => 7,
                'Source_Module' => 'transfer_decision',
                'Source_Record_Id' => 79,
                'Tenant_Id' => 7,
            ]],
        ];
        Db::name('transfer_records')->where('id', 79)->update([
            'result_json' => json_encode($linkedPayload, JSON_THROW_ON_ERROR),
        ]);
        $storedBeforeRead = (string)Db::name('transfer_records')->where('id', 79)->value('result_json');
        $detail = $service->detail(79, [7], 3, true);
        $linked = $service->buildExecutionIntentInput($detail);

        self::assertSame($intentId, $detail['result']['tracking_records']['bridge']['execution_intent_id']);
        self::assertSame(
            $before['evidence']['source_snapshot_digest'],
            $linked['evidence']['source_snapshot_digest']
        );
        self::assertSame(
            SourceBackedExecutionIntentIdentityService::key(['tenant_id' => 7] + $before, null),
            SourceBackedExecutionIntentIdentityService::key(['tenant_id' => 7] + $linked, null)
        );
        self::assertSame($storedBeforeRead, (string)Db::name('transfer_records')->where('id', 79)->value('result_json'));

        $linkedPayload['business_status'] = 'changed';
        Db::name('transfer_records')->where('id', 79)->update([
            'result_json' => json_encode($linkedPayload, JSON_THROW_ON_ERROR),
        ]);
        $changed = $service->buildExecutionIntentInput($service->detail(79, [7], 3, true));
        self::assertNotSame(
            $linked['evidence']['source_snapshot_digest'],
            $changed['evidence']['source_snapshot_digest']
        );
    }

    public function testDigestAndFallbackKeyPreserveOrdinaryTrackingNamedObjectsWithCommonFields(): void
    {
        $ordinary = [
            'tracking_records' => [
                'type' => 'customer_followup',
                'status' => 'active',
                'hotel_id' => 7,
                'tenant_id' => 7,
                'source_module' => 'crm',
                'business_note' => 'guest requested late checkout',
            ],
            'execution_tracking' => [
                'type' => 'staff_training',
                'status' => 'scheduled',
                'hotel_id' => 7,
                'business_note' => 'front desk training',
            ],
        ];
        $changed = $ordinary;
        $changed['tracking_records']['business_note'] = 'guest cancelled request';
        $changed['execution_tracking']['business_note'] = 'training completed';

        self::assertNotSame(
            SourceBackedExecutionIntentIdentityService::snapshotDigest('transfer_decision', $ordinary),
            SourceBackedExecutionIntentIdentityService::snapshotDigest('transfer_decision', $changed)
        );

        $basePayload = [
            'tenant_id' => 7,
            'source_module' => 'transfer_decision',
            'source_record_id' => 901,
            'hotel_id' => 7,
            'platform' => 'investment',
            'object_type' => 'investment',
            'action_type' => 'post_decision_tracking',
            'date_start' => '2026-08-13',
            'date_end' => '2026-08-13',
            'current_value' => $ordinary,
        ];
        $changedPayload = $basePayload;
        $changedPayload['current_value'] = $changed;
        self::assertNotSame(
            SourceBackedExecutionIntentIdentityService::key($basePayload, null),
            SourceBackedExecutionIntentIdentityService::key($changedPayload, null)
        );

        $realBridge = $basePayload;
        $realBridge['current_value']['Tracking_Records'] = [
            'Execution_Intent_Id' => 88,
            'Post_Decision_Tracking_Id' => 89,
        ];
        self::assertSame(
            SourceBackedExecutionIntentIdentityService::key($basePayload, null),
            SourceBackedExecutionIntentIdentityService::key($realBridge, null)
        );
    }

    public function testDigestFallbackKeyAndProjectionPreserveOrdinaryBridgeNamedBusinessFacts(): void
    {
        $ordinary = [
            'business_context' => [
                'opening_project_id' => 703,
                'investment_tracking_id' => 704,
                'business_note' => 'renovation funding approved',
            ],
            'tracking_records' => [
                'execution_status' => 'training_scheduled',
                'business_note' => 'front desk coaching',
            ],
        ];
        $changed = $ordinary;
        $changed['business_context']['opening_project_id'] = 705;
        $changed['tracking_records']['execution_status'] = 'training_completed';

        self::assertNotSame(
            SourceBackedExecutionIntentIdentityService::snapshotDigest('opening', $ordinary),
            SourceBackedExecutionIntentIdentityService::snapshotDigest('opening', $changed)
        );

        $payload = [
            'tenant_id' => 7,
            'source_module' => 'opening',
            'source_record_id' => 901,
            'hotel_id' => 7,
            'platform' => 'investment',
            'object_type' => 'opening',
            'action_type' => 'post_decision_tracking',
            'date_start' => '2026-08-13',
            'date_end' => '2026-08-13',
            'current_value' => $ordinary,
        ];
        $changedPayload = $payload;
        $changedPayload['current_value'] = $changed;
        self::assertNotSame(
            SourceBackedExecutionIntentIdentityService::key($payload, null),
            SourceBackedExecutionIntentIdentityService::key($changedPayload, null)
        );

        $projected = (new SourceBackedExecutionBridgeProjectionService())->trackingForResponse(
            'opening',
            ['id' => 901, 'tenant_id' => 7, 'hotel_id' => 7],
            $ordinary
        );
        self::assertSame($ordinary, $projected);
        self::assertFalse(SourceBackedExecutionBridgeProjectionService::hasProjectedTracking($projected));

        $structuredTracking = ['execution_tracking' => ['opening_project_id' => 8]];
        self::assertFalse(SourceBackedExecutionBridgeProjectionService::hasProjectedTracking($structuredTracking));
        self::assertFalse(SourceBackedExecutionBridgeProjectionService::hasProjectedTracking(
            ['post_decision_tracking' => false] + $structuredTracking
        ));
        self::assertFalse(SourceBackedExecutionBridgeProjectionService::hasProjectedTracking(
            ['post_decision_tracking' => false]
        ));
        self::assertNotSame(
            SourceBackedExecutionIntentIdentityService::snapshotDigest('feasibility_report', []),
            SourceBackedExecutionIntentIdentityService::snapshotDigest('feasibility_report', $structuredTracking)
        );
    }

    public function testSourceBackedKeyUsesBusinessDigestInsteadOfTrackingDerivedReadiness(): void
    {
        $payload = [
            'tenant_id' => 7, 'source_module' => 'feasibility_report', 'source_record_id' => 91, 'hotel_id' => 7,
            'platform' => 'investment', 'object_type' => 'investment', 'action_type' => 'post_decision_tracking',
            'date_start' => '2026-08-13', 'date_end' => '2026-08-13',
            'current_value' => ['readiness_stage' => 'approved_pending_tracking'],
            'evidence' => [
                'source_snapshot_digest' => str_repeat('a', 64),
                'readiness_score' => 80,
                'missing_evidence' => ['execution_tracking_missing'],
            ],
        ];
        $linked = $payload;
        $linked['current_value']['readiness_stage'] = 'decision_ready';
        $linked['evidence']['readiness_score'] = 100;
        $linked['evidence']['missing_evidence'] = [];
        $changed = $linked;
        $changed['evidence']['source_snapshot_digest'] = str_repeat('b', 64);
        $movedTenant = $linked;
        $movedTenant['tenant_id'] = 8;

        self::assertSame(
            SourceBackedExecutionIntentIdentityService::key($payload, null),
            SourceBackedExecutionIntentIdentityService::key($linked, null)
        );
        self::assertNotSame(
            SourceBackedExecutionIntentIdentityService::key($payload, null),
            SourceBackedExecutionIntentIdentityService::key($changed, null)
        );
        self::assertNotSame(
            SourceBackedExecutionIntentIdentityService::key($payload, null),
            SourceBackedExecutionIntentIdentityService::key($movedTenant, null)
        );
    }

    public function testSourceControllersDelegateReplayToSnapshotIdempotency(): void
    {
        foreach ([
            'StrategySimulation.php', 'Simulation.php', 'Opening.php', 'TransferDecision.php', 'Agent.php',
        ] as $controllerFile) {
            $source = file_get_contents(__DIR__ . '/../app/controller/' . $controllerFile);
            self::assertIsString($source);
            $start = strpos($source, $controllerFile === 'Agent.php'
                ? 'public function createFeasibilityExecutionIntent'
                : 'public function createExecutionIntent');
            self::assertNotFalse($start, $controllerFile);
            $next = strpos($source, "\n    public function ", $start + 30);
            $method = substr($source, $start, $next === false ? null : $next - $start);
            self::assertStringNotContainsString('already linked to execution intent', $method, $controllerFile);
            self::assertStringContainsString('createExecutionIntent(', $method, $controllerFile);
        }

        $strategy = file_get_contents(__DIR__ . '/../app/controller/StrategySimulation.php');
        self::assertIsString($strategy);
        $methodStart = strpos($strategy, 'public function createExecutionIntent');
        $methodEnd = strpos($strategy, "\n    public function ", $methodStart + 30);
        $method = substr($strategy, $methodStart, $methodEnd - $methodStart);
        self::assertLessThan(strpos($method, 'strategyExecutionHotelId('), strpos($method, 'formatRecord('));
        self::assertLessThan(strpos($method, 'resolveExecutionHotelScope($hotelId)'), strpos($method, 'strategyExecutionHotelId('));
    }

    public function testOpeningIntentApprovalRejectsChangedTaskSnapshot(): void
    {
        $this->insertOpeningFixture();
        $opening = new OpeningService();
        $input = $opening->currentExecutionIntentInput(31, [7], 3, true, [
            'date_start' => '2026-08-13',
            'date_end' => '2026-08-13',
        ]);
        $service = new OperationManagementService();
        $intent = $service->createExecutionIntent([7], 7, $input, 3, false, null, true);

        Db::name('opening_tasks')->where('id', 41)->update([
            'status' => 'done',
            'progress_percent' => 100,
            'updated_at' => '2026-08-13 11:00:00',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('snapshot changed');
        $service->approveExecutionIntent((int)$intent['id'], true, 'approve stale snapshot', 3, [7]);
    }

    public function testOpeningIntentCreationRejectsSourceUpdatedAfterInputReadAndWritesNothing(): void
    {
        $this->insertOpeningFixture();
        $input = (new OpeningService())->currentExecutionIntentInput(31, [7], 3, true, [
            'date_start' => '2026-08-13',
            'date_end' => '2026-08-13',
        ]);
        Db::name('opening_tasks')->where('id', 41)->update([
            'status' => 'done',
            'progress_percent' => 100,
            'updated_at' => '2026-08-13 11:00:00',
        ]);

        try {
            (new OperationManagementService())->createExecutionIntent([7], 7, $input, 3, false, null, true);
            self::fail('Source facts updated after the input read must reject execution intent creation');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('snapshot changed', $exception->getMessage());
        }
        self::assertSame(0, (int)Db::name('operation_execution_intents')->count());
    }

    public function testOpeningIntentApprovalAcceptsTheUnchangedLockedSnapshot(): void
    {
        $this->insertOpeningFixture();
        $opening = new OpeningService();
        $input = $opening->currentExecutionIntentInput(31, [7], 3, true, [
            'date_start' => '2026-08-13',
            'date_end' => '2026-08-13',
        ]);
        $service = new OperationManagementService();
        $intent = $service->createExecutionIntent([7], 7, $input, 3, false, null, true);

        $approved = $service->approveExecutionIntent((int)$intent['id'], true, 'approved', 3, [7]);

        self::assertSame('approved', $approved['status']);
        self::assertSame('opening', $approved['source_module']);
    }

    public function testOpeningIntentApprovalRejectsOldIntentWhenHotelAndSourceTenantMoveTogether(): void
    {
        $this->insertOpeningFixture();
        $opening = new OpeningService();
        $input = $opening->currentExecutionIntentInput(31, [7], 3, true, [
            'date_start' => '2026-08-13', 'date_end' => '2026-08-13',
        ]);
        $service = new OperationManagementService();
        $intent = $service->createExecutionIntent([7], 7, $input, 3, false, null, true);
        $beforeIntent = Db::name('operation_execution_intents')->where('id', (int)$intent['id'])->find();
        $beforeSource = Db::name('opening_projects')->where('id', 31)->find();
        $transferConnection = new \PDO('sqlite:' . self::$sqlitePath);
        $transferConnection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $transferConnection->beginTransaction();
        $transferConnection->exec('UPDATE hotels SET tenant_id = 8 WHERE id = 7');
        $transferConnection->exec('UPDATE opening_projects SET tenant_id = 8 WHERE id = 31');
        $transferConnection->commit();

        try {
            $service->approveExecutionIntent((int)$intent['id'], true, 'tenant moved', 3, [7]);
            self::fail('An intent owned by the previous tenant must not be approved.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('tenant scope', $e->getMessage());
        } finally {
            self::assertSame($beforeIntent, Db::name('operation_execution_intents')->where('id', (int)$intent['id'])->find());
            self::assertSame(0, (int)Db::name('operation_execution_tasks')->where('intent_id', (int)$intent['id'])->count());
            self::assertSame(0, (int)Db::name('operation_execution_evidence')->count());
            self::assertSame(
                (string)($beforeSource['updated_at'] ?? ''),
                (string)Db::name('opening_projects')->where('id', 31)->value('updated_at')
            );
            Db::name('hotels')->where('id', 7)->update(['tenant_id' => 7]);
        }
    }

    public function testTransferAndFeasibilityApprovalRejectSecondConnectionTenantTransferWithoutWrites(): void
    {
        foreach ([
            ['transfer_decision', 'transfer_records', 81],
            ['feasibility_report', 'feasibility_reports', 82],
        ] as [$sourceModule, $sourceTable, $sourceId]) {
            Db::name('hotels')->where('id', 7)->update(['tenant_id' => 7]);
            if ($sourceModule === 'transfer_decision') {
                $this->insertTransferRecord($sourceId, 7, []);
            } else {
                $this->insertFeasibilityRecord($sourceId, 7, []);
            }
            $intentId = $this->insertSourceIntent($sourceModule, $sourceId, 7, 7);
            $beforeIntent = Db::name('operation_execution_intents')->where('id', $intentId)->find();
            $beforeSource = Db::name($sourceTable)->where('id', $sourceId)->find();

            $transferConnection = new \PDO('sqlite:' . self::$sqlitePath);
            $transferConnection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $transferConnection->beginTransaction();
            $transferConnection->exec('UPDATE hotels SET tenant_id = 8 WHERE id = 7');
            $transferConnection->exec(
                'UPDATE ' . $sourceTable . ' SET tenant_id = 8 WHERE id = ' . $sourceId
            );
            $transferConnection->commit();

            try {
                (new OperationManagementService())->approveExecutionIntent(
                    $intentId,
                    true,
                    'must not approve after tenant transfer',
                    3,
                    [7]
                );
                self::fail($sourceModule . ' approval must reject the committed tenant transfer.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('tenant scope', $exception->getMessage(), $sourceModule);
            }

            self::assertSame($beforeIntent, Db::name('operation_execution_intents')->where('id', $intentId)->find());
            self::assertSame(0, (int)Db::name('operation_execution_tasks')->where('intent_id', $intentId)->count());
            self::assertSame(0, (int)Db::name('operation_execution_evidence')->count());
            self::assertSame(
                (string)($beforeSource['result_json'] ?? $beforeSource['report_json'] ?? ''),
                (string)Db::name($sourceTable)->where('id', $sourceId)
                    ->value($sourceModule === 'transfer_decision' ? 'result_json' : 'report_json')
            );
        }
    }

    public function testSourceBackedApprovalDeclaresStableHotelTaskIntentSourceLockOrder(): void
    {
        $method = new \ReflectionMethod(
            OperationManagementService::class,
            'withSourceBackedExecutionIntentApprovalAuthorization'
        );
        $lines = file($method->getFileName()) ?: [];
        $source = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $hotelLock = strpos($source, "Db::name('hotels')");
        $taskLock = strpos($source, "Db::name('operation_execution_tasks')");
        $intentLock = strpos($source, 'executionIntentRow(');
        $sourceLock = strpos($source, 'executionSourceRecordQuery(');
        self::assertNotFalse($hotelLock);
        self::assertNotFalse($taskLock);
        self::assertNotFalse($intentLock);
        self::assertNotFalse($sourceLock);
        self::assertLessThan($taskLock, $hotelLock);
        self::assertLessThan($intentLock, $taskLock);
        self::assertLessThan($sourceLock, $intentLock);
        self::assertStringContainsString("->order('id', 'asc')", $source);
        self::assertStringContainsString('return $approval(', $source);
    }

    public function testSourceBackedCreationDeclaresStableHotelSourceValidationPersistenceOrder(): void
    {
        $method = new \ReflectionMethod(
            OperationManagementService::class,
            'withSourceBackedExecutionIntentCreationAuthorization'
        );
        $lines = file($method->getFileName()) ?: [];
        $source = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $hotelLock = strpos($source, "Db::name('hotels')");
        $sourceLock = strpos($source, 'executionSourceRecordQuery(');
        $digestValidation = strpos($source, 'assertSourceBackedIntentCurrentWithAuthorization(');
        $persistence = strpos($source, 'return $creation(');
        self::assertNotFalse($hotelLock);
        self::assertNotFalse($sourceLock);
        self::assertNotFalse($digestValidation);
        self::assertNotFalse($persistence);
        self::assertLessThan($sourceLock, $hotelLock);
        self::assertLessThan($digestValidation, $sourceLock);
        self::assertLessThan($persistence, $digestValidation);
    }

    public function testSourceBackedCreationLockSerializesSecondConnectionTenantTransfer(): void
    {
        $this->insertOpeningFixture();
        $payload = (new OpeningService())->currentExecutionIntentInput(31, [7], 3, true, [
            'date_start' => '2026-08-13',
            'date_end' => '2026-08-13',
        ]);
        $payload['tenant_id'] = 7;
        $transferConnection = new \PDO('sqlite:' . self::$sqlitePath);
        $transferConnection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $transferConnection->exec('PRAGMA busy_timeout = 1');
        $transferBlocked = false;

        $method = new \ReflectionMethod(
            OperationManagementService::class,
            'withSourceBackedExecutionIntentCreationAuthorization'
        );
        $method->invoke(
            new OperationManagementService(),
            $payload,
            [7],
            function (array $lockedPayload) use ($transferConnection, &$transferBlocked): array {
                Db::name('opening_projects')->where('id', 31)->update([
                    'updated_at' => (string)Db::name('opening_projects')->where('id', 31)->value('updated_at'),
                ]);
                try {
                    $transferConnection->exec('UPDATE hotels SET tenant_id = 8 WHERE id = 7');
                } catch (\PDOException $exception) {
                    $transferBlocked = str_contains(strtolower($exception->getMessage()), 'locked');
                }
                return $lockedPayload;
            }
        );

        self::assertTrue($transferBlocked, 'tenant transfer must wait for source-backed creation locks');
        self::assertSame(0, (int)Db::name('operation_execution_intents')->count());
    }

    public function testSourceBackedApprovalLockSerializesSecondConnectionTenantTransfer(): void
    {
        $cases = [
            ['opening', 'opening_projects', 31],
            ['transfer_decision', 'transfer_records', 83],
            ['feasibility_report', 'feasibility_reports', 84],
        ];
        $lockMethod = new \ReflectionMethod(
            OperationManagementService::class,
            'withSourceBackedExecutionIntentApprovalAuthorization'
        );

        foreach ($cases as [$sourceModule, $sourceTable, $sourceId]) {
            Db::name('operation_execution_evidence')->delete(true);
            Db::name('operation_execution_tasks')->delete(true);
            Db::name('operation_execution_intents')->delete(true);
            Db::name($sourceTable)->delete(true);
            Db::name('hotels')->where('id', 7)->update(['tenant_id' => 7]);
            if ($sourceModule === 'opening') {
                $this->insertOpeningFixture();
            } elseif ($sourceModule === 'transfer_decision') {
                $this->insertTransferRecord($sourceId, 7, []);
            } else {
                $this->insertFeasibilityRecord($sourceId, 7, []);
            }
            $intentId = $this->insertSourceIntent($sourceModule, $sourceId, 7, 7);
            $transferConnection = new \PDO('sqlite:' . self::$sqlitePath);
            $transferConnection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $transferConnection->exec('PRAGMA busy_timeout = 1');
            $transferBlocked = false;

            $lockMethod->invoke(
                new OperationManagementService(),
                $intentId,
                [7],
                function (array $authorization) use (
                    $intentId,
                    $transferConnection,
                    &$transferBlocked
                ): void {
                    Db::name('operation_execution_intents')
                        ->where('id', $intentId)
                        ->update(['updated_at' => (string)$authorization['intent']['updated_at']]);
                    try {
                        $transferConnection->exec('UPDATE hotels SET tenant_id = 8 WHERE id = 7');
                    } catch (\PDOException $exception) {
                        $transferBlocked = str_contains(strtolower($exception->getMessage()), 'locked');
                    }
                }
            );

            self::assertTrue($transferBlocked, $sourceModule . ' tenant transfer must wait for approval locks');
            self::assertSame('pending_approval', Db::name('operation_execution_intents')->where('id', $intentId)->value('status'));
            self::assertSame(0, (int)Db::name('operation_execution_tasks')->where('intent_id', $intentId)->count());

            Db::connect()->close();
            Db::connect(null, true);
            $transferConnection = null;
            $transferConnection = new \PDO('sqlite:' . self::$sqlitePath);
            $transferConnection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $transferConnection->beginTransaction();
            $transferConnection->exec('UPDATE hotels SET tenant_id = 8 WHERE id = 7');
            $transferConnection->exec(
                'UPDATE ' . $sourceTable . ' SET tenant_id = 8 WHERE id = ' . $sourceId
            );
            $transferConnection->commit();

            try {
                (new OperationManagementService())->approveExecutionIntent(
                    $intentId,
                    true,
                    'serialized transfer wins after approval lock releases',
                    3,
                    [7]
                );
                self::fail($sourceModule . ' old tenant approval must fail after serialized transfer.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('tenant scope', $exception->getMessage(), $sourceModule);
            }
            self::assertSame(0, (int)Db::name('operation_execution_tasks')->where('intent_id', $intentId)->count());
            self::assertSame(0, (int)Db::name('operation_execution_evidence')->count());
        }
    }

    public function testOpeningResponseMapsOnlyTheIntentOwnedByTheCurrentSourceAndHotelTenant(): void
    {
        $this->insertOpeningFixture();
        $oldIntentId = $this->insertSourceIntent('opening', 31, 7, 7);
        $service = new OpeningService();

        self::assertSame($oldIntentId, $service->projects([7], 3, true)[0]['execution_intent_id']);

        Db::name('hotels')->where('id', 7)->update(['tenant_id' => 8]);
        Db::name('opening_projects')->where('id', 31)->update(['tenant_id' => 8]);
        self::assertSame(0, $service->projects([7], 3, true)[0]['execution_intent_id']);

        $currentIntentId = $this->insertSourceIntent('opening', 31, 8, 7);
        self::assertSame($currentIntentId, $service->projects([7], 3, true)[0]['execution_intent_id']);
        self::assertSame($currentIntentId, $service->projects([7], 3, true)[0]['execution_intent_id']);
    }

    public function testOpeningBoundProjectIsSharedWithinPermittedHotelButUnboundDraftStaysPrivate(): void
    {
        $this->insertOpeningFixture();
        $service = new OpeningService(
            null,
            null,
            null,
            static fn(int $userId, int $hotelId, string $capability): bool =>
                $userId === 8 && $hotelId === 7 && $capability === 'operation.execute'
        );

        $collaboratorProjects = $service->projects([7], 8, false);
        self::assertSame([31], array_column($collaboratorProjects, 'id'));
        $updated = $service->forActor(8, false)->updateProject(31, [
            'manager_name' => '同店协作负责人',
        ], [7]);
        self::assertSame('同店协作负责人', $updated['manager_name']);

        try {
            (new OpeningService(null, null, null, static fn(): bool => false))
                ->forActor(8, false)
                ->updateProject(31, ['manager_name' => '越权修改'], [7]);
            self::fail('read-only hotel collaborator must not mutate an opening project');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('operation.execute', $exception->getMessage());
        }
        self::assertSame('同店协作负责人', Db::name('opening_projects')->where('id', 31)->value('manager_name'));

        try {
            $service->forActor(8, false)->updateProject(31, ['hotel_id' => 0], [7]);
            self::fail('non-owner collaborator must not unbind a shared opening project');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('无权操作', $exception->getMessage());
        }
        self::assertSame(7, (int)Db::name('opening_projects')->where('id', 31)->value('hotel_id'));

        Db::name('users')->where('id', 3)->update(['tenant_id' => 7, 'hotel_id' => 7]);
        $unboundId = $service->createProject([
            'project_name' => '未绑定筹建草稿',
            'hotel_name' => '待定酒店',
            'opening_date' => '2026-12-01',
            'status' => 'preparing',
        ], 3, [7, 8]);
        self::assertSame(0, (int)Db::name('opening_projects')->where('id', $unboundId)->value('hotel_id'));
        self::assertContains($unboundId, array_column($service->projects([7, 8], 3, false), 'id'));
        self::assertNotContains($unboundId, array_column($service->projects([7, 8], 8, false), 'id'));

        Db::name('users')->where('id', 3)->update(['tenant_id' => 8]);
        self::assertNotContains($unboundId, array_column($service->projects([7, 8], 3, false), 'id'));
        Db::name('users')->where('id', 3)->update(['tenant_id' => 7]);

        Db::name('hotels')->where('id', 7)->update(['tenant_id' => 8]);
        self::assertSame([], $service->projects([7], 8, false));
    }

    public function testOpeningTaskGenerationCallsTheLlmOnceAfterTheWriteTransactionCommits(): void
    {
        $this->insertOpeningFixture();
        Db::name('opening_tasks')->where('project_id', 31)->delete();
        $client = new class extends LlmClient {
            /** @var list<bool> */
            public array $transactionStates = [];

            public function createJsonResponse(
                array $messages,
                array $schema,
                string $modelKey = 'deepseek_v4_default'
            ): array {
                $this->transactionStates[] = Db::connect()->getPdo()->inTransaction();
                return ['suggestions' => ['提交后生成的开业建议']];
            }
        };
        $service = new OpeningService(
            $client,
            null,
            null,
            static fn(int $userId, int $hotelId, string $capability): bool =>
                $userId === 3 && $hotelId === 7 && $capability === 'operation.execute'
        );

        $result = $service->generateTasks(31, [7], 3, false);

        self::assertTrue($result['generated']);
        self::assertNotEmpty($result['tasks']);
        self::assertSame([false], $client->transactionStates);
        self::assertSame('llm', $result['overview']['opening_suggestion_source']);
    }

    public function testOpeningTaskUpdateRollsBackWhenAggregateRecalculationFails(): void
    {
        $this->insertOpeningFixture();
        $beforeTask = Db::name('opening_tasks')->where('id', 41)->find();
        $beforeProject = Db::name('opening_projects')->where('id', 31)->find();
        self::assertIsArray($beforeTask);
        self::assertIsArray($beforeProject);
        Db::execute(<<<'SQL'
CREATE TRIGGER fail_opening_project_recalculation
BEFORE UPDATE OF overall_score ON opening_projects
WHEN OLD.id = 31
BEGIN
    SELECT RAISE(ABORT, 'forced opening aggregate failure');
END
SQL);
        $service = new OpeningService(
            null,
            null,
            null,
            static fn(int $userId, int $hotelId, string $capability): bool =>
                $userId === 3 && $hotelId === 7 && $capability === 'operation.execute'
        );
        $failure = null;

        try {
            $service->updateTask(41, ['progress_percent' => 75], [7], 3, false);
        } catch (Throwable $exception) {
            $failure = $exception;
        } finally {
            Db::execute('DROP TRIGGER IF EXISTS fail_opening_project_recalculation');
        }

        self::assertInstanceOf(Throwable::class, $failure);
        self::assertStringContainsString('forced opening aggregate failure', $failure->getMessage());
        self::assertSame($beforeTask, Db::name('opening_tasks')->where('id', 41)->find());
        self::assertSame($beforeProject, Db::name('opening_projects')->where('id', 31)->find());
    }

    public function testTransferResponseKeepsHistoricalTrackingButProjectsOnlyTheCurrentTenantIntent(): void
    {
        $oldIntentId = $this->insertSourceIntent('transfer_decision', 51, 7, 7);
        Db::name('transfer_records')->insert([
            'id' => 51,
            'tenant_id' => 7,
            'record_type' => 'pricing',
            'hotel_id' => 7,
            'hotel_name' => 'Hotel 7',
            'source_date' => '2026-08-13',
            'input_json' => json_encode([], JSON_THROW_ON_ERROR),
            'result_json' => json_encode($this->trackingPayload($oldIntentId, 'transfer_decision'), JSON_THROW_ON_ERROR),
            'snapshot_json' => json_encode([], JSON_THROW_ON_ERROR),
            'decision' => 'review',
            'risk_level' => 'medium',
            'created_by' => 3,
            'created_at' => '2026-08-13 09:00:00',
            'updated_at' => '2026-08-13 09:00:00',
            'deleted_at' => null,
        ]);
        Db::name('hotels')->where('id', 7)->update(['tenant_id' => 8]);
        Db::name('transfer_records')->where('id', 51)->update(['tenant_id' => 8]);
        $service = new TransferDecisionService();

        $moved = $service->detail(51, [7], 3, true);
        self::assertSame(0, $moved['execution_intent_id']);
        self::assertSame([], $moved['result']['execution_tracking']);
        self::assertArrayNotHasKey('post_decision_tracking', $moved['result']);

        $currentIntentId = $this->insertSourceIntent('transfer_decision', 51, 8, 7);
        $linked = $service->attachExecutionTracking(51, [7], 3, true, [
            'execution_intent_id' => $currentIntentId,
            'hotel_id' => 7,
            'status' => 'pending',
        ]);
        $replayed = $service->attachExecutionTracking(51, [7], 3, true, [
            'execution_intent_id' => $currentIntentId,
            'hotel_id' => 7,
            'status' => 'pending',
        ]);

        self::assertSame($currentIntentId, $linked['execution_intent_id']);
        self::assertSame([$currentIntentId], array_column($linked['result']['execution_tracking'], 'execution_intent_id'));
        self::assertSame([$currentIntentId], array_column($replayed['result']['execution_tracking'], 'execution_intent_id'));
        $stored = json_decode((string)Db::name('transfer_records')->where('id', 51)->value('result_json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([$oldIntentId, $currentIntentId], array_column($stored['execution_tracking'], 'execution_intent_id'));
    }

    public function testFeasibilityResponseKeepsHistoricalTrackingButProjectsOnlyTheCurrentTenantIntent(): void
    {
        $oldIntentId = $this->insertSourceIntent('feasibility_report', 61, 7, 7);
        Db::name('feasibility_reports')->insert([
            'id' => 61,
            'tenant_id' => 7,
            'project_name' => 'Feasibility 61',
            'input_json' => json_encode(['hotel_id' => 7, 'system_hotel_id' => 7], JSON_THROW_ON_ERROR),
            'snapshot_json' => json_encode(['snapshot_scope' => ['hotel_id' => 7]], JSON_THROW_ON_ERROR),
            'report_json' => json_encode($this->trackingPayload($oldIntentId, 'feasibility_report'), JSON_THROW_ON_ERROR),
            'conclusion_grade' => null,
            'payback_months' => null,
            'total_investment' => null,
            'created_by' => 3,
            'created_at' => '2026-08-13 09:00:00',
            'updated_at' => '2026-08-13 09:00:00',
            'deleted_at' => null,
        ]);
        Db::name('hotels')->where('id', 7)->update(['tenant_id' => 8]);
        Db::name('feasibility_reports')->where('id', 61)->update(['tenant_id' => 8]);
        $service = new FeasibilityReportService();

        $moved = $service->detail(61, 3, true);
        self::assertIsArray($moved);
        self::assertSame([], $moved['report']['execution_tracking']);
        self::assertArrayNotHasKey('execution_intent_id', $moved['report']);
        self::assertArrayNotHasKey('post_decision_tracking', $moved['report']);

        $currentIntentId = $this->insertSourceIntent('feasibility_report', 61, 8, 7);
        $linked = $service->attachExecutionTracking(61, 3, true, [
            'execution_intent_id' => $currentIntentId,
            'hotel_id' => 7,
            'status' => 'pending',
        ]);
        $replayed = $service->attachExecutionTracking(61, 3, true, [
            'execution_intent_id' => $currentIntentId,
            'hotel_id' => 7,
            'status' => 'pending',
        ]);

        self::assertSame([$currentIntentId], array_column($linked['report']['execution_tracking'], 'execution_intent_id'));
        self::assertSame([$currentIntentId], array_column($replayed['report']['execution_tracking'], 'execution_intent_id'));
        $stored = json_decode((string)Db::name('feasibility_reports')->where('id', 61)->value('report_json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([$oldIntentId, $currentIntentId], array_column($stored['execution_tracking'], 'execution_intent_id'));
    }

    public function testBridgeSanitizerRecursivelyRemovesUnverifiableReferencesWithoutBoostingReadiness(): void
    {
        $oldIntentId = $this->insertSourceIntent('transfer_decision', 71, 7, 7);
        $payload = $this->trackingPayload($oldIntentId, 'transfer_decision') + [
            'tracking_record_id' => 701,
            'post_decision_tracking_id' => 702,
            'opening_project_id' => 703,
            'investment_tracking_id' => 704,
            'tracking_records' => ['execution_intent_id' => $oldIntentId, 'tracking_record_id' => 705],
            'nested' => [
                'post_decision_tracking' => true,
                'tracking_records' => [[
                    'execution_intent_id' => $oldIntentId,
                    'post_decision_tracking_id' => 706,
                ]],
            ],
        ];
        $this->insertTransferRecord(71, 7, $payload);
        Db::name('hotels')->where('id', 7)->update(['tenant_id' => 8]);
        Db::name('transfer_records')->where('id', 71)->update(['tenant_id' => 8]);

        $detail = (new TransferDecisionService())->detail(71, [7], 3, true);

        self::assertNotContains($oldIntentId, $this->trackingReferenceIds($detail));
        self::assertSame(
            $payload,
            json_decode((string)Db::name('transfer_records')->where('id', 71)->value('result_json'), true, 512, JSON_THROW_ON_ERROR)
        );
        foreach (['tracking_record_id', 'post_decision_tracking_id', 'opening_project_id', 'investment_tracking_id'] as $field) {
            self::assertFalse($this->containsArrayKey($detail, $field), $field . ' must fail closed');
        }
        $trackingCheck = $this->readinessCheck($detail['decision_readiness'], 'post_decision_tracking');
        self::assertFalse($trackingCheck['passed']);

        $feasibilityIntentId = $this->insertSourceIntent('feasibility_report', 72, 7, 7);
        $feasibilityPayload = $payload;
        $feasibilityPayload['execution_intent_id'] = $feasibilityIntentId;
        $feasibilityPayload['operation_execution_intent_id'] = $feasibilityIntentId;
        $this->insertFeasibilityRecord(72, 7, $feasibilityPayload);
        Db::name('feasibility_reports')->where('id', 72)->update(['tenant_id' => 8]);

        $feasibility = (new FeasibilityReportService())->detail(72, 3, true);
        self::assertIsArray($feasibility);
        self::assertNotContains($feasibilityIntentId, $this->trackingReferenceIds($feasibility));
        self::assertSame(
            $feasibilityPayload,
            json_decode((string)Db::name('feasibility_reports')->where('id', 72)->value('report_json'), true, 512, JSON_THROW_ON_ERROR)
        );
        $feasibilityTracking = $this->readinessCheck($feasibility['feasibility_readiness'], 'post_decision_tracking');
        self::assertFalse($feasibilityTracking['passed']);
    }

    public function testBridgeSanitizerPreservesVerifiedNonListAndNestedTrackingShapes(): void
    {
        $intentId = $this->insertSourceIntent('transfer_decision', 73, 7, 7);
        $tracking = [
            'execution_intent_id' => $intentId,
            'hotel_id' => 7,
            'source_module' => 'transfer_decision',
        ];
        $payload = [
            'execution_tracking' => $tracking,
            'tracking_records' => ['bridge' => $tracking],
            'post_decision_tracking' => [
                'latest_execution_intent_id' => $intentId,
                'hotel_id' => 7,
                'source_module' => 'transfer_decision',
            ],
            'nested' => ['execution_tracking' => [$tracking]],
        ];
        $this->insertTransferRecord(73, 7, $payload);

        $detail = (new TransferDecisionService())->detail(73, [7], 3, true);

        self::assertSame($intentId, $detail['result']['execution_tracking']['execution_intent_id']);
        self::assertSame($intentId, $detail['result']['tracking_records']['bridge']['execution_intent_id']);
        self::assertSame($intentId, $detail['result']['post_decision_tracking']['latest_execution_intent_id']);
        self::assertSame($intentId, $detail['result']['nested']['execution_tracking'][0]['execution_intent_id']);
        self::assertTrue($this->readinessCheck($detail['decision_readiness'], 'post_decision_tracking')['passed']);
    }

    public function testTransferListBridgeProjectionUsesConstantLookupQueriesForOneAndEightyRows(): void
    {
        $intentId = $this->insertSourceIntent('transfer_decision', 1001, 7, 7);
        $this->insertTransferRecord(1001, 7, $this->trackingPayload($intentId, 'transfer_decision'));
        $queries = [];
        Db::listen(static function ($sql) use (&$queries): void {
            $queries[] = strtolower((string)$sql);
        });
        $service = new TransferDecisionService();

        self::assertCount(1, $service->records([7], 3, true));
        $oneRowLookups = $this->bridgeLookupQueryCount($queries);

        for ($id = 1002; $id <= 1080; $id++) {
            $rowIntentId = $this->insertSourceIntent('transfer_decision', $id, 7, 7);
            $this->insertTransferRecord($id, 7, $this->trackingPayload($rowIntentId, 'transfer_decision'));
        }
        $beforeEighty = $this->bridgeLookupQueryCount($queries);
        self::assertCount(80, $service->records([7], 3, true));
        $eightyRowLookups = $this->bridgeLookupQueryCount($queries) - $beforeEighty;

        self::assertSame(2, $oneRowLookups);
        self::assertSame($oneRowLookups, $eightyRowLookups);
    }

    public function testFeasibilityListBridgeProjectionUsesConstantLookupQueriesForOneAndEightyRows(): void
    {
        $intentId = $this->insertSourceIntent('feasibility_report', 2001, 7, 7);
        $this->insertFeasibilityRecord(2001, 7, $this->trackingPayload($intentId, 'feasibility_report'));
        $queries = [];
        Db::listen(static function ($sql) use (&$queries): void {
            $queries[] = strtolower((string)$sql);
        });
        $service = new FeasibilityReportService();

        self::assertCount(1, $service->list(1, 80, 3, true)['list']);
        $oneRowLookups = $this->bridgeLookupQueryCount($queries);

        for ($id = 2002; $id <= 2080; $id++) {
            $rowIntentId = $this->insertSourceIntent('feasibility_report', $id, 7, 7);
            $this->insertFeasibilityRecord($id, 7, $this->trackingPayload($rowIntentId, 'feasibility_report'));
        }
        $beforeEighty = $this->bridgeLookupQueryCount($queries);
        self::assertCount(80, $service->list(1, 80, 3, true)['list']);
        $eightyRowLookups = $this->bridgeLookupQueryCount($queries) - $beforeEighty;

        self::assertSame(2, $oneRowLookups);
        self::assertSame($oneRowLookups, $eightyRowLookups);
    }

    public function testBridgeProjectionFailsClosedWithoutWritingWhenCurrentScopeIsMissing(): void
    {
        $intentId = $this->insertSourceIntent('transfer_decision', 74, 7, 7);
        $payload = $this->trackingPayload($intentId, 'transfer_decision');
        $before = Db::name('operation_execution_intents')->where('id', $intentId)->find();

        $projected = (new SourceBackedExecutionBridgeProjectionService())->trackingForResponse(
            'transfer_decision',
            ['id' => 74, 'tenant_id' => 7, 'hotel_id' => 999999],
            $payload
        );

        self::assertNotContains($intentId, $this->trackingReferenceIds($projected));
        self::assertSame($before, Db::name('operation_execution_intents')->where('id', $intentId)->find());
    }

    public function testBridgeSanitizerClassifiesMixedCaseTrackingKeysAtEveryShapeAfterTenantMove(): void
    {
        $oldIntentId = $this->insertSourceIntent('transfer_decision', 75, 7, 7);
        $payload = [
            'Execution_Intent_Id' => $oldIntentId,
            'Post_Decision_Tracking_Id' => 7501,
            'EXECUTION_TRACKING' => [
                'Execution_Intent_Id' => $oldIntentId,
                'Hotel_Id' => 7,
                'Source_Module' => 'transfer_decision',
                'Source_Record_Id' => 75,
                'Tenant_Id' => 7,
            ],
            'nested' => [
                'POST_DECISION_TRACKING' => [
                    'Latest_Execution_Intent_Id' => $oldIntentId,
                    'Hotel_Id' => 7,
                    'Source_Module' => 'transfer_decision',
                ],
                'TRACKING_RECORDS' => [[
                    'Execution_Intent_Id' => $oldIntentId,
                    'Post_Decision_Tracking_Id' => 7502,
                ]],
                'associative' => [
                    'Tracking_Records' => [
                        'bridge' => ['Execution_Intent_Id' => $oldIntentId],
                    ],
                ],
            ],
            'ordinaryBusinessField' => ['Mixed_Case_Label' => 'preserve-me'],
        ];
        $this->insertTransferRecord(75, 7, $payload);
        $storedBefore = (string)Db::name('transfer_records')->where('id', 75)->value('result_json');
        Db::name('hotels')->where('id', 7)->update(['tenant_id' => 8]);
        Db::name('transfer_records')->where('id', 75)->update(['tenant_id' => 8]);

        $detail = (new TransferDecisionService())->detail(75, [7], 3, true);

        self::assertSame([], $this->trackingReferenceIdsCaseInsensitive($detail));
        self::assertSame('preserve-me', $detail['result']['ordinaryBusinessField']['Mixed_Case_Label']);
        self::assertFalse($this->readinessCheck($detail['decision_readiness'], 'post_decision_tracking')['passed']);
        self::assertSame($storedBefore, (string)Db::name('transfer_records')->where('id', 75)->value('result_json'));
    }

    public function testBridgeSanitizerKeepsMixedCaseCurrentTenantTrackingAndCanonicalNewTenantOnly(): void
    {
        $currentIntentId = $this->insertSourceIntent('transfer_decision', 76, 7, 7);
        $validTracking = [
            'Execution_Intent_Id' => $currentIntentId,
            'Hotel_Id' => 7,
            'Source_Module' => 'transfer_decision',
            'Source_Record_Id' => 76,
            'Tenant_Id' => 7,
        ];
        $mixedCase = [
            'Execution_Intent_Id' => $currentIntentId,
            'EXECUTION_TRACKING' => [
                $validTracking,
                array_replace($validTracking, ['Tenant_Id' => 999, 'label' => 'wrong-scope']),
            ],
            'Tracking_Records' => ['bridge' => $validTracking],
            'POST_DECISION_TRACKING' => $validTracking,
        ];
        $this->insertTransferRecord(76, 7, $mixedCase);
        $service = new TransferDecisionService();

        $sameTenant = $service->detail(76, [7], 3, true);
        self::assertSame([$currentIntentId], $this->trackingReferenceIdsCaseInsensitive($sameTenant));
        self::assertCount(1, $sameTenant['result']['execution_tracking']);
        self::assertSame($currentIntentId, $sameTenant['result']['tracking_records']['bridge']['execution_intent_id']);
        self::assertSame($currentIntentId, $sameTenant['result']['post_decision_tracking']['execution_intent_id']);
        self::assertTrue($this->readinessCheck($sameTenant['decision_readiness'], 'post_decision_tracking')['passed']);

        Db::name('hotels')->where('id', 7)->update(['tenant_id' => 8]);
        Db::name('transfer_records')->where('id', 76)->update(['tenant_id' => 8]);
        $newIntentId = $this->insertSourceIntent('transfer_decision', 76, 8, 7);
        $canonical = $this->trackingPayload($newIntentId, 'transfer_decision');
        $raw = $mixedCase + $canonical;
        Db::name('transfer_records')->where('id', 76)->update([
            'result_json' => json_encode($raw, JSON_THROW_ON_ERROR),
        ]);
        $storedBefore = (string)Db::name('transfer_records')->where('id', 76)->value('result_json');

        $newTenant = $service->detail(76, [7], 3, true);

        self::assertSame([$newIntentId], $this->trackingReferenceIdsCaseInsensitive($newTenant));
        self::assertSame($storedBefore, (string)Db::name('transfer_records')->where('id', 76)->value('result_json'));
    }

    public function testBridgeSanitizerCanonicalizesCamelAndKebabKeysAndClearsThemAfterTenantMove(): void
    {
        $intentId = $this->insertSourceIntent('transfer_decision', 84, 7, 7);
        $validTracking = [
            'executionIntentId' => $intentId,
            'hotelId' => 7,
            'tenantId' => 7,
            'sourceModule' => 'transfer_decision',
            'sourceRecordId' => 84,
            'status' => 'pending',
        ];
        $payload = [
            'executionIntentId' => $intentId,
            'executionTracking' => [$validTracking],
            'tracking-records' => ['bridge' => $validTracking],
            'postDecisionTracking' => [
                'latestExecutionIntentId' => $intentId,
                'hotelId' => 7,
                'tenantId' => 7,
                'sourceModule' => 'transfer_decision',
                'sourceRecordId' => 84,
            ],
            'ordinary' => [
                'trackingRecords' => [
                    'businessNote' => 'preserve ordinary business tracking label',
                    'hotelId' => 999,
                ],
            ],
        ];
        $this->insertTransferRecord(84, 7, $payload);
        $service = new TransferDecisionService();

        $current = $service->detail(84, [7], 3, true);

        self::assertSame([$intentId], $this->trackingReferenceIdsCaseInsensitive($current));
        self::assertSame($intentId, $current['result']['execution_intent_id'] ?? null);
        self::assertSame($intentId, $current['result']['execution_tracking'][0]['execution_intent_id'] ?? null);
        self::assertSame($intentId, $current['result']['tracking_records']['bridge']['execution_intent_id'] ?? null);
        self::assertSame($intentId, $current['result']['post_decision_tracking']['latest_execution_intent_id'] ?? null);
        self::assertTrue(SourceBackedExecutionBridgeProjectionService::hasProjectedTracking($current['result']));
        self::assertFalse(SourceBackedExecutionBridgeProjectionService::hasProjectedTracking([
            'executionIntentId' => $intentId,
            'executionTracking' => $validTracking,
        ]));
        self::assertFalse(SourceBackedExecutionBridgeProjectionService::hasProjectedTracking([
            '_source_bridge_verified' => true,
            'execution_intent_id' => $intentId,
        ]));
        self::assertSame(
            'preserve ordinary business tracking label',
            $current['result']['ordinary']['trackingRecords']['businessNote'] ?? null
        );

        $businessSnapshot = [
            'business' => ['amount' => 120, 'trackingRecords' => ['businessNote' => 'ordinary']],
        ];
        self::assertSame(
            SourceBackedExecutionIntentIdentityService::snapshotDigest('transfer_decision', $businessSnapshot),
            SourceBackedExecutionIntentIdentityService::snapshotDigest(
                'transfer_decision',
                $businessSnapshot + ['executionTracking' => [$validTracking], 'post-decision-tracking' => true]
            )
        );
        $changedBusinessSnapshot = $businessSnapshot;
        $changedBusinessSnapshot['business']['trackingRecords']['businessNote'] = 'changed ordinary tracking note';
        self::assertNotSame(
            SourceBackedExecutionIntentIdentityService::snapshotDigest('transfer_decision', $businessSnapshot),
            SourceBackedExecutionIntentIdentityService::snapshotDigest('transfer_decision', $changedBusinessSnapshot)
        );

        $storedBefore = (string)Db::name('transfer_records')->where('id', 84)->value('result_json');
        Db::name('hotels')->where('id', 7)->update(['tenant_id' => 8]);
        Db::name('transfer_records')->where('id', 84)->update(['tenant_id' => 8]);

        $moved = $service->detail(84, [7], 3, true);

        self::assertSame([], $this->trackingReferenceIdsCaseInsensitive($moved));
        self::assertFalse(SourceBackedExecutionBridgeProjectionService::hasProjectedTracking($moved['result']));
        self::assertSame(
            'preserve ordinary business tracking label',
            $moved['result']['ordinary']['trackingRecords']['businessNote'] ?? null
        );
        self::assertFalse($this->readinessCheck($moved['decision_readiness'], 'post_decision_tracking')['passed']);
        self::assertSame($storedBefore, (string)Db::name('transfer_records')->where('id', 84)->value('result_json'));
    }

    public function testCamelCaseAncestorScopeConflictSuppressesDescendantBridgeProjection(): void
    {
        $intentId = $this->insertSourceIntent('feasibility_report', 85, 7, 7);
        $payload = [
            'tenantId' => 999,
            'business' => ['amount' => 321, 'label' => 'preserve-me'],
            'nested' => [
                'executionTracking' => [[
                    'executionIntentId' => $intentId,
                    'hotelId' => 7,
                    'tenantId' => 7,
                    'sourceModule' => 'feasibility_report',
                    'sourceRecordId' => 85,
                ]],
            ],
        ];
        $this->insertFeasibilityRecord(85, 7, $payload);
        $storedBefore = (string)Db::name('feasibility_reports')->where('id', 85)->value('report_json');

        $detail = (new FeasibilityReportService())->detail(85, 3, true);

        self::assertIsArray($detail);
        self::assertSame([], $this->trackingReferenceIdsCaseInsensitive($detail));
        self::assertArrayNotHasKey('executionTracking', $detail['report']['nested']);
        self::assertSame([], $detail['report']['nested']['execution_tracking'] ?? null);
        self::assertSame(['amount' => 321, 'label' => 'preserve-me'], $detail['report']['business']);
        self::assertFalse(SourceBackedExecutionBridgeProjectionService::hasProjectedTracking($detail['report']));
        self::assertFalse($this->readinessCheck($detail['feasibility_readiness'], 'post_decision_tracking')['passed']);
        self::assertSame($storedBefore, (string)Db::name('feasibility_reports')->where('id', 85)->value('report_json'));
    }

    public function testAncestorScopeConflictSuppressesAllDescendantTrackingButPreservesBusinessSubtrees(): void
    {
        $intentId = $this->insertSourceIntent('transfer_decision', 77, 7, 7);
        $validTracking = [
            'Execution_Intent_Id' => $intentId,
            'Hotel_Id' => 7,
            'Source_Module' => 'transfer_decision',
            'Source_Record_Id' => 77,
            'Tenant_Id' => 7,
        ];
        $payload = [
            'Tenant_Id' => 999,
            'business' => ['note' => 'preserve-me', 'details' => ['amount' => 123]],
            'deep' => [
                'level' => [
                    'EXECUTION_TRACKING' => [$validTracking],
                    'TRACKING_RECORDS' => ['bridge' => $validTracking],
                ],
            ],
        ];
        $this->insertTransferRecord(77, 7, $payload);
        $storedBefore = (string)Db::name('transfer_records')->where('id', 77)->value('result_json');

        $detail = (new TransferDecisionService())->detail(77, [7], 3, true);

        self::assertSame([], $this->trackingReferenceIdsCaseInsensitive($detail));
        self::assertSame(['note' => 'preserve-me', 'details' => ['amount' => 123]], $detail['result']['business']);
        self::assertFalse($this->readinessCheck($detail['decision_readiness'], 'post_decision_tracking')['passed']);
        self::assertSame($storedBefore, (string)Db::name('transfer_records')->where('id', 77)->value('result_json'));
    }

    public function testFeasibilityAncestorScopeConflictDoesNotBoostReadinessOrRewriteStoredReport(): void
    {
        $intentId = $this->insertSourceIntent('feasibility_report', 78, 7, 7);
        $payload = [
            'business' => ['note' => 'preserve-feasibility'],
            'outer' => [
                'Source_Module' => 'transfer_decision',
                'nested' => ['Tracking_Records' => ['Execution_Intent_Id' => $intentId]],
            ],
        ];
        $this->insertFeasibilityRecord(78, 7, $payload);
        $storedBefore = (string)Db::name('feasibility_reports')->where('id', 78)->value('report_json');

        $detail = (new FeasibilityReportService())->detail(78, 3, true);

        self::assertIsArray($detail);
        self::assertSame([], $this->trackingReferenceIdsCaseInsensitive($detail));
        self::assertSame('preserve-feasibility', $detail['report']['business']['note']);
        self::assertFalse($this->readinessCheck($detail['feasibility_readiness'], 'post_decision_tracking')['passed']);
        self::assertSame($storedBefore, (string)Db::name('feasibility_reports')->where('id', 78)->value('report_json'));
    }

    public function testTransferAndFeasibilityDetailsPreserveOrdinaryTrackingNamedBusinessObjects(): void
    {
        $ordinary = [
            'tracking_records' => [
                'type' => 'guest_service',
                'status' => 'active',
                'hotel_id' => 7,
                'tenant_id' => 7,
                'source_module' => 'crm',
                'business_note' => 'ordinary transfer note',
            ],
            'execution_tracking' => [
                'type' => 'staff_training',
                'status' => 'scheduled',
                'hotel_id' => 7,
                'business_note' => 'ordinary training note',
            ],
        ];
        $this->insertTransferRecord(80, 7, $ordinary);
        $this->insertFeasibilityRecord(81, 7, $ordinary);
        $transferStored = (string)Db::name('transfer_records')->where('id', 80)->value('result_json');
        $feasibilityStored = (string)Db::name('feasibility_reports')->where('id', 81)->value('report_json');

        $transfer = (new TransferDecisionService())->detail(80, [7], 3, true);
        $feasibility = (new FeasibilityReportService())->detail(81, 3, true);

        self::assertSame($ordinary['tracking_records'], $transfer['result']['tracking_records']);
        self::assertSame($ordinary['execution_tracking'], $transfer['result']['execution_tracking']);
        self::assertSame($ordinary['tracking_records'], $feasibility['report']['tracking_records']);
        self::assertSame($ordinary['execution_tracking'], $feasibility['report']['execution_tracking']);
        self::assertFalse($this->readinessCheck($transfer['decision_readiness'], 'post_decision_tracking')['passed']);
        self::assertFalse($this->readinessCheck($feasibility['feasibility_readiness'], 'post_decision_tracking')['passed']);
        self::assertSame($transferStored, (string)Db::name('transfer_records')->where('id', 80)->value('result_json'));
        self::assertSame($feasibilityStored, (string)Db::name('feasibility_reports')->where('id', 81)->value('report_json'));
    }

    public function testDomainTrackingIdsRemainBusinessDataButCannotProveExecutionClosure(): void
    {
        $domainOnly = [
            'tracking_records' => [
                'tracking_record_id' => 9101,
                'opening_project_id' => 9102,
                'investment_tracking_id' => 9103,
                'business_note' => 'retain domain references without promoting readiness',
            ],
            'post_decision_tracking' => [
                'post_decision_tracking_id' => 9104,
                'status' => 'planned',
            ],
        ];
        $this->insertTransferRecord(82, 7, $domainOnly);
        $this->insertFeasibilityRecord(83, 7, $domainOnly);

        $transfer = (new TransferDecisionService())->detail(82, [7], 3, true);
        $feasibility = (new FeasibilityReportService())->detail(83, 3, true);

        self::assertSame($domainOnly['tracking_records'], $transfer['result']['tracking_records']);
        self::assertSame($domainOnly['post_decision_tracking'], $transfer['result']['post_decision_tracking']);
        self::assertSame($domainOnly['tracking_records'], $feasibility['report']['tracking_records']);
        self::assertSame($domainOnly['post_decision_tracking'], $feasibility['report']['post_decision_tracking']);
        self::assertFalse($this->readinessCheck($transfer['decision_readiness'], 'post_decision_tracking')['passed']);
        self::assertFalse($this->readinessCheck($feasibility['feasibility_readiness'], 'post_decision_tracking')['passed']);
    }

    public function testProjectionCanonicalizesHistoricalIntentModuleWithoutCrossModuleBroadening(): void
    {
        $intentId = $this->insertSourceIntent('transfer_decision', 84, 7, 7);
        Db::name('operation_execution_intents')->where('id', $intentId)->update([
            'source_module' => '  TrAnSfEr_DeCiSiOn  ',
        ]);
        $this->insertTransferRecord(84, 7, $this->trackingPayload($intentId, 'transfer_decision'));

        $detail = (new TransferDecisionService())->detail(84, [7], 3, true);

        self::assertSame($intentId, $detail['result']['execution_intent_id']);
        self::assertTrue($this->readinessCheck($detail['decision_readiness'], 'post_decision_tracking')['passed']);

        $wrongModuleId = $this->insertSourceIntent('feasibility_report', 84, 7, 7);
        $mixed = $this->trackingPayload($wrongModuleId, 'feasibility_report');
        Db::name('transfer_records')->where('id', 84)->update([
            'result_json' => json_encode($mixed, JSON_THROW_ON_ERROR),
        ]);
        $wrongModule = (new TransferDecisionService())->detail(84, [7], 3, true);
        self::assertArrayNotHasKey('execution_intent_id', $wrongModule['result']);
        self::assertFalse($this->readinessCheck($wrongModule['decision_readiness'], 'post_decision_tracking')['passed']);
    }

    public function testRejectedSourceIntentDoesNotProjectAsCurrentTracking(): void
    {
        $intentId = $this->insertSourceIntent('feasibility_report', 85, 7, 7);
        Db::name('operation_execution_intents')->where('id', $intentId)->update(['status' => 'rejected']);
        $this->insertFeasibilityRecord(85, 7, $this->trackingPayload($intentId, 'feasibility_report'));

        $detail = (new FeasibilityReportService())->detail(85, 3, true);

        self::assertIsArray($detail);
        self::assertArrayNotHasKey('execution_intent_id', $detail['report']);
        self::assertFalse($this->readinessCheck($detail['feasibility_readiness'], 'post_decision_tracking')['passed']);
    }

    public function testSourceBackedTaskRejectsHotelTenantTransferAndNewTenantGetsFreshLifecycle(): void
    {
        $this->insertOpeningFixture();
        $service = new OperationManagementService();
        $oldIntent = $this->createApprovedOpeningIntent($service, 3);
        $oldTaskId = (int)($oldIntent['tasks'][0]['id'] ?? 0);
        self::assertGreaterThan(0, $oldTaskId);
        $beforeTask = Db::name('operation_execution_tasks')->where('id', $oldTaskId)->find();
        self::assertIsArray($beforeTask);

        Db::name('hotels')->where('id', 7)->update(['tenant_id' => 8]);
        Db::name('opening_projects')->where('id', 31)->update(['tenant_id' => 8]);

        $blockedCalls = [
            fn(): array => $service->readExecutionTask($oldTaskId, [7]),
            function () use ($service, $oldTaskId): array {
                $service->assertExecutionTaskMutationAuthorized($oldTaskId, [7]);
                return [];
            },
            fn(): array => $service->executeExecutionTask($oldTaskId, [7], ['status' => 'executing'], 4),
            fn(): array => $service->addExecutionEvidence($oldTaskId, [7], [
                'evidence_type' => 'manual_screenshot',
                'attachment_path' => '/runtime/evidence/tenant-transfer.png',
            ], 4),
            fn(): array => $service->reconcileScheduledExecutionTask($oldTaskId, [7]),
            fn(): array => $service->reviewExecutionTask($oldTaskId, [7], [
                'result_status' => 'observing',
                'result_summary' => 'must not persist',
            ], 4),
        ];
        foreach ($blockedCalls as $call) {
            try {
                $call();
                self::fail('The new tenant must not access or mutate the previous tenant task.');
            } catch (RuntimeException $e) {
                self::assertStringContainsString('current tenant scope', $e->getMessage());
            }
        }

        self::assertSame($beforeTask, Db::name('operation_execution_tasks')->where('id', $oldTaskId)->find());
        self::assertSame(0, (int)Db::name('operation_execution_evidence')->count());
        self::assertSame(1, (int)Db::name('operation_execution_intents')->count());

        $newIntent = $this->createApprovedOpeningIntent($service, 4);
        $newTaskId = (int)($newIntent['tasks'][0]['id'] ?? 0);
        self::assertGreaterThan(0, $newTaskId);
        self::assertNotSame($oldTaskId, $newTaskId);
        self::assertSame(8, (int)($newIntent['tenant_id'] ?? 0));
        self::assertSame(8, (int)Db::name('operation_execution_tasks')->where('id', $newTaskId)->value('tenant_id'));

        $executing = $service->executeExecutionTask($newTaskId, [7], ['status' => 'executing'], 4);
        self::assertSame('executing', $executing['status']);
        self::assertSame(8, (int)$executing['tenant_id']);
        self::assertSame(2, (int)Db::name('operation_execution_intents')->count());
    }

    public function testSavedOtaAndManualExecutionRowsCannotCrossAHotelTenantTransfer(): void
    {
        $service = new OperationManagementService();
        $oldIntentId = $this->insertSourceIntent('ota_diagnosis_saved', 901, 7, 7);
        $oldTaskId = (int)Db::name('operation_execution_tasks')->insertGetId([
            'tenant_id' => 7,
            'intent_id' => $oldIntentId,
            'hotel_id' => 7,
            'execution_mode' => 'manual',
            'operator_id' => 3,
            'target_value_json' => '{}',
            'current_value_json' => '{}',
            'blocked_reason' => '',
            'action_track_id' => 0,
            'result_status' => 'observing',
            'result_summary' => 'old tenant review',
            'status' => 'executed',
            'executed_at' => '2026-08-13 10:00:00',
            'created_at' => '2026-08-13 09:00:00',
            'updated_at' => '2026-08-13 10:00:00',
            'deleted_at' => null,
        ]);
        $beforeIntent = Db::name('operation_execution_intents')->where('id', $oldIntentId)->find();
        $beforeTask = Db::name('operation_execution_tasks')->where('id', $oldTaskId)->find();

        $transfer = new \PDO('sqlite:' . self::$sqlitePath);
        $transfer->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $transfer->exec('UPDATE hotels SET tenant_id = 8 WHERE id = 7');

        self::assertSame([], $service->executionIntents([7], 7)['list']);
        self::assertSame([], $service->executionFlow([7], 7)['list']);
        foreach ([
            fn(): array => $service->readExecutionIntent($oldIntentId, [7]),
            fn(): array => $service->approveExecutionIntent($oldIntentId, true, 'must not approve', 3, [7]),
            fn(): array => $service->readExecutionTask($oldTaskId, [7]),
            fn(): array => $service->executeExecutionTask($oldTaskId, [7], ['status' => 'executed'], 3),
            fn(): array => $service->addExecutionEvidence($oldTaskId, [7], [
                'evidence_type' => 'manual_screenshot',
                'attachment_path' => '/runtime/evidence/old-tenant.png',
            ], 3),
            fn(): array => $service->reconcileScheduledExecutionTask($oldTaskId, [7]),
            fn(): array => $service->reviewExecutionTask($oldTaskId, [7], [
                'result_status' => 'observing',
                'result_summary' => 'must not persist',
            ], 3),
        ] as $blocked) {
            try {
                $blocked();
                self::fail('Old execution lifecycle must be hidden after hotel tenant transfer.');
            } catch (\Throwable $exception) {
                self::assertStringContainsString('tenant scope', $exception->getMessage());
            }
        }
        self::assertSame($beforeIntent, Db::name('operation_execution_intents')->where('id', $oldIntentId)->find());
        self::assertSame($beforeTask, Db::name('operation_execution_tasks')->where('id', $oldTaskId)->find());
        self::assertSame(0, (int)Db::name('operation_execution_evidence')->where('task_id', $oldTaskId)->count());

        $legacyTenantlessIntentId = $this->insertSourceIntent('manual', 903, 0, 7);
        self::assertSame([], $service->executionIntents([7], 7)['list']);
        foreach ([
            fn(): array => $service->readExecutionIntent($legacyTenantlessIntentId, [7]),
            fn(): array => $service->approveExecutionIntent($legacyTenantlessIntentId, true, 'must fail closed', 4, [7]),
        ] as $tenantlessAccess) {
            try {
                $tenantlessAccess();
                self::fail('Historical tenant_id=0 execution rows must fail closed.');
            } catch (\Throwable $exception) {
                self::assertStringContainsString('tenant scope', $exception->getMessage());
            }
        }
        self::assertSame('pending_approval', (string)Db::name('operation_execution_intents')
            ->where('id', $legacyTenantlessIntentId)->value('status'));

        $freshIntentId = $this->insertSourceIntent('manual', 902, 8, 7);
        $approved = $service->approveExecutionIntent($freshIntentId, true, 'new tenant lifecycle', 4, [7]);
        self::assertSame(8, (int)$approved['tenant_id']);
        self::assertSame(8, (int)$approved['tasks'][0]['tenant_id']);
    }

    public function testPendingIntentRescheduleRejectsTransferredSavedOtaSourceBackedManualAndTenantlessRowsWithoutMutation(): void
    {
        $this->insertOpeningFixture();
        $service = new OperationManagementService();
        $openingInput = (new OpeningService())->currentExecutionIntentInput(31, [7], 3, true, [
            'date_start' => '2026-08-13',
            'date_end' => '2026-08-13',
        ]);
        $intentIds = [
            $this->insertSourceIntent('ota_diagnosis_saved', 910, 7, 7),
            (int)$service->createExecutionIntent([7], 7, $openingInput, 3, false, null, true)['id'],
            $this->insertSourceIntent('manual', 911, 7, 7),
            $this->insertSourceIntent('manual', 912, 0, 7),
        ];
        $before = [];
        foreach ($intentIds as $intentId) {
            $before[$intentId] = Db::name('operation_execution_intents')->where('id', $intentId)->find();
        }

        $transfer = new \PDO('sqlite:' . self::$sqlitePath);
        $transfer->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $transfer->beginTransaction();
        $transfer->exec('UPDATE hotels SET tenant_id = 8 WHERE id = 7');
        $transfer->exec('UPDATE opening_projects SET tenant_id = 8 WHERE id = 31');
        $transfer->commit();
        $schedule = [
            'assignee_id' => 4,
            'due_at' => '2099-08-20 18:00:00',
            'review_at' => '2099-08-21 10:00:00',
        ];

        foreach ($intentIds as $intentId) {
            try {
                $service->reschedulePendingExecutionIntent($intentId, [7], $schedule, 4);
                self::fail('A previous-tenant or tenantless intent must not be rescheduled.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('tenant scope', $exception->getMessage());
            }
            self::assertSame(
                $before[$intentId],
                Db::name('operation_execution_intents')->where('id', $intentId)->find(),
                'target/evidence/status bytes must remain unchanged after rejected reschedule'
            );
        }

        $freshIntentId = $this->insertSourceIntent('manual', 913, 8, 7);
        $fresh = $service->reschedulePendingExecutionIntent($freshIntentId, [7], $schedule, 4);
        self::assertSame($freshIntentId, (int)$fresh['id']);
        self::assertSame(8, (int)$fresh['tenant_id']);
        self::assertSame($schedule['due_at'], $fresh['target_value']['workflow_schedule']['due_at']);
    }

    public function testPendingSourceBackedIntentRescheduleRejectsAChangedSourceSnapshotWithoutMutation(): void
    {
        $this->insertOpeningFixture();
        $service = new OperationManagementService();
        $input = (new OpeningService())->currentExecutionIntentInput(31, [7], 3, true, [
            'date_start' => '2026-08-13',
            'date_end' => '2026-08-13',
        ]);
        $intent = $service->createExecutionIntent([7], 7, $input, 3, false, null, true);
        $valid = $service->reschedulePendingExecutionIntent((int)$intent['id'], [7], [
            'assignee_id' => 3,
            'due_at' => '2099-08-20 18:00:00',
            'review_at' => '2099-08-21 10:00:00',
        ], 3);
        self::assertSame('2099-08-20 18:00:00', $valid['target_value']['workflow_schedule']['due_at']);
        $before = Db::name('operation_execution_intents')->where('id', (int)$intent['id'])->find();
        Db::name('opening_tasks')->where('id', 41)->update([
            'progress_percent' => 90,
            'updated_at' => '2026-08-13 13:00:00',
        ]);

        try {
            $service->reschedulePendingExecutionIntent((int)$intent['id'], [7], [
                'assignee_id' => 3,
                'due_at' => '2099-08-22 18:00:00',
                'review_at' => '2099-08-23 10:00:00',
            ], 3);
            self::fail('A stale source-backed intent must not be rescheduled.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('snapshot changed', $exception->getMessage());
        }

        self::assertSame($before, Db::name('operation_execution_intents')->where('id', (int)$intent['id'])->find());
    }

    public function testPendingIntentRescheduleRollsBackWhenItsFinalReadbackLosesTenantScope(): void
    {
        $service = new OperationManagementService();
        $intentId = $this->insertSourceIntent('manual', 914, 7, 7);
        $before = Db::name('operation_execution_intents')->where('id', $intentId)->find();
        Db::execute(<<<'SQL'
CREATE TRIGGER reschedule_final_readback_scope_loss
AFTER UPDATE OF target_value_json ON operation_execution_intents
BEGIN
    UPDATE hotels SET tenant_id = 99 WHERE id = NEW.hotel_id;
END
SQL);

        try {
            $service->reschedulePendingExecutionIntent($intentId, [7], [
                'assignee_id' => 3,
                'due_at' => '2099-08-24 18:00:00',
                'review_at' => '2099-08-25 10:00:00',
            ], 3);
            self::fail('A failed final tenant readback must roll back the reschedule update.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('tenant scope', $exception->getMessage());
        } finally {
            Db::execute('DROP TRIGGER IF EXISTS reschedule_final_readback_scope_loss');
        }

        self::assertSame(7, (int)Db::name('hotels')->where('id', 7)->value('tenant_id'));
        self::assertSame($before, Db::name('operation_execution_intents')->where('id', $intentId)->find());
    }

    public function testExecutionIntentListScopesTenantBeforeItsHundredRowLimitAndSurvivesReturnTransfer(): void
    {
        $service = new OperationManagementService();
        $returnTenantIntentId = $this->insertSourceIntent('manual', 1000, 7, 7);
        Db::name('hotels')->where('id', 7)->update(['tenant_id' => 8]);
        for ($recordId = 1001; $recordId <= 1101; $recordId++) {
            $this->insertSourceIntent('manual', $recordId, 8, 7);
        }
        Db::name('hotels')->where('id', 7)->update(['tenant_id' => 7]);

        $list = (new OperationManagementService())->executionIntents([7], 7);

        self::assertSame([$returnTenantIntentId], array_column($list['list'], 'id'));
        self::assertSame([7], array_values(array_unique(array_column($list['list'], 'tenant_id'))));
    }

    public function testExecutionFlowScopesMixedTenantsBeforeCountLimitAndTruncation(): void
    {
        $service = new OperationManagementService();
        Db::name('hotels')->where('id', 7)->update(['tenant_id' => 8]);
        $currentIds = [];
        for ($recordId = 1201; $recordId <= 1203; $recordId++) {
            $currentIds[] = $this->insertSourceIntent('manual', $recordId, 8, 7);
        }
        $oldTenantIds = [];
        for ($recordId = 1301; $recordId <= 1405; $recordId++) {
            $oldTenantIds[] = $this->insertSourceIntent('manual', $recordId, 7, 7);
        }

        $current = $service->executionFlow([7], 7, ['limit' => 2]);
        self::assertSame(3, $current['matched_total']);
        self::assertSame(2, $current['returned_count']);
        self::assertTrue($current['truncated']);
        self::assertSame(array_reverse(array_slice($currentIds, -2)), array_column($current['list'], 'id'));
        self::assertSame([], array_values(array_intersect($oldTenantIds, array_column($current['list'], 'id'))));

        Db::name('hotels')->where('id', 7)->update(['tenant_id' => 7]);
        $returned = $service->executionFlow([7], 7, ['limit' => 100]);
        self::assertSame(105, $returned['matched_total']);
        self::assertSame(100, $returned['returned_count']);
        self::assertTrue($returned['truncated']);
        self::assertSame([], array_values(array_intersect($currentIds, array_column($returned['list'], 'id'))));
    }

    public function testExecutionIntentListsFailClosedWhenHotelOrIntentTenantSchemaIsUnavailable(): void
    {
        $service = new OperationManagementService();
        $this->insertSourceIntent('manual', 1501, 7, 7);

        Db::execute('ALTER TABLE hotels RENAME TO hotels_execution_scope_backup');
        try {
            self::assertSame([], $service->executionIntents([7], 7)['list']);
            $flow = $service->executionFlow([7], 7, ['limit' => 10]);
            self::assertSame(0, $flow['matched_total']);
            self::assertSame([], $flow['list']);
        } finally {
            Db::execute('ALTER TABLE hotels_execution_scope_backup RENAME TO hotels');
        }

        Db::execute('ALTER TABLE operation_execution_intents RENAME TO operation_execution_intents_scope_backup');
        Db::execute('CREATE TABLE operation_execution_intents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            hotel_id INTEGER NOT NULL,
            platform TEXT,
            object_type TEXT,
            status TEXT,
            deleted_at TEXT
        )');
        Db::name('operation_execution_intents')->insert([
            'hotel_id' => 7,
            'platform' => 'internal',
            'object_type' => 'test',
            'status' => 'pending_approval',
            'deleted_at' => null,
        ]);
        try {
            self::assertSame([], $service->executionIntents([7], 7)['list']);
            $flow = $service->executionFlow([7], 7, ['limit' => 10]);
            self::assertSame(0, $flow['matched_total']);
            self::assertSame([], $flow['list']);
        } finally {
            Db::execute('DROP TABLE operation_execution_intents');
            Db::execute('ALTER TABLE operation_execution_intents_scope_backup RENAME TO operation_execution_intents');
        }
    }

    public function testEmptyExecutionFlowStillRequiresTaskAndEvidenceSchema(): void
    {
        $service = new OperationManagementService();

        foreach ([
            'operation_execution_tasks' => 'operation_execution_tasks_missing',
            'operation_execution_evidence' => 'operation_execution_evidence_missing',
        ] as $table => $expectedGap) {
            $backup = $table . '_execution_flow_backup';
            Db::execute('ALTER TABLE ' . $table . ' RENAME TO ' . $backup);
            try {
                $flow = $service->executionFlow([7], 7);
                self::assertSame('migration_required', $flow['data_status'], $table);
                self::assertSame([], $flow['list'], $table);
                self::assertSame($expectedGap, $flow['data_gaps'][0]['code'], $table);
                self::assertFalse($flow['statistics']['execution_total_loaded'], $table);
                self::assertFalse($flow['statistics']['task_status_loaded'], $table);
                self::assertFalse($flow['statistics']['evidence_loaded'], $table);
                self::assertFalse($flow['statistics']['roi_loaded'], $table);
            } finally {
                Db::execute('ALTER TABLE ' . $backup . ' RENAME TO ' . $table);
            }
        }
    }

    public function testApprovalExecutionEvidenceAndMemoryGatewayFailClosedWithoutHotelTenantSchema(): void
    {
        $service = new OperationManagementService();
        $pendingIntentId = $this->insertSourceIntent('manual', 1601, 7, 7);
        $approvedIntentId = $this->insertSourceIntent('manual', 1602, 7, 7);
        $approved = $service->approveExecutionIntent($approvedIntentId, true, 'fixture approval', 3, [7]);
        $taskId = (int)($approved['tasks'][0]['id'] ?? 0);
        self::assertGreaterThan(0, $taskId);
        Db::name('operation_execution_intents')->where('id', $approvedIntentId)->update([
            'source_module' => 'ota_diagnosis',
        ]);

        $pendingBefore = Db::name('operation_execution_intents')->where('id', $pendingIntentId)->find();
        $taskBefore = Db::name('operation_execution_tasks')->where('id', $taskId)->find();
        $evidenceBefore = (int)Db::name('operation_execution_evidence')->where('task_id', $taskId)->count();
        $mutations = [
            'approval' => fn(): array => $service->approveExecutionIntent($pendingIntentId, true, 'must not write', 3, [7]),
            'execute' => fn(): array => $service->executeExecutionTask($taskId, [7], [], 3),
            'evidence' => fn(): array => $service->addExecutionEvidence($taskId, [7], [
                'evidence_type' => 'manual_screenshot',
                'attachment_path' => '/runtime/evidence/schema-gap.png',
            ], 3),
            'memory_gateway' => function () use ($service, $taskId): null {
                $service->assertExecutionTaskMutationAuthorized($taskId, [7]);
                return null;
            },
        ];

        $assertAllFailClosed = function (string $expectedMessage) use (
            $mutations,
            $pendingIntentId,
            $taskId,
            $pendingBefore,
            $taskBefore,
            $evidenceBefore
        ): void {
            foreach ($mutations as $name => $mutation) {
                try {
                    $mutation();
                    self::fail($name . ' must fail closed when hotel tenant schema is unavailable.');
                } catch (Throwable $exception) {
                    self::assertStringContainsString($expectedMessage, $exception->getMessage(), $name);
                }
            }
            self::assertSame($pendingBefore, Db::name('operation_execution_intents')->where('id', $pendingIntentId)->find());
            self::assertSame($taskBefore, Db::name('operation_execution_tasks')->where('id', $taskId)->find());
            self::assertSame($evidenceBefore, (int)Db::name('operation_execution_evidence')->where('task_id', $taskId)->count());
        };

        Db::execute('ALTER TABLE hotels RENAME TO hotels_execution_mutation_backup');
        try {
            $assertAllFailClosed('migration_required');
        } finally {
            Db::execute('ALTER TABLE hotels_execution_mutation_backup RENAME TO hotels');
        }

        Db::execute('ALTER TABLE hotels DROP COLUMN tenant_id');
        try {
            $assertAllFailClosed('migration_required');
        } finally {
            Db::execute('ALTER TABLE hotels ADD COLUMN tenant_id INTEGER NOT NULL DEFAULT 7');
        }

        Db::name('hotels')->where('id', 7)->update(['tenant_id' => 0]);
        try {
            $assertAllFailClosed('tenant scope');
        } finally {
            Db::name('hotels')->where('id', 7)->update(['tenant_id' => 7]);
        }
    }

    public function testLegacyMixedCaseSourceModuleReplaysCanonicallyAndStillRejectsDifferentFacts(): void
    {
        $this->insertOpeningFixture();
        $service = new OperationManagementService();
        $input = (new OpeningService())->currentExecutionIntentInput(31, [7], 3, true, [
            'date_start' => '2026-08-13',
            'date_end' => '2026-08-13',
        ]);
        $first = $service->createExecutionIntent([7], 7, $input, 3, false, null, true);
        Db::name('operation_execution_intents')->where('id', (int)$first['id'])->update([
            'source_module' => ' Opening ',
        ]);

        $replay = $service->createExecutionIntent([7], 7, $input, 3, false, null, true);
        self::assertSame((int)$first['id'], (int)$replay['id']);
        self::assertTrue($replay['idempotent_replay']);
        self::assertSame('opening', $replay['source_module']);

        Db::name('operation_execution_intents')->where('id', (int)$first['id'])->update([
            'object_type' => 'different_fact',
        ]);
        try {
            $service->createExecutionIntent([7], 7, $input, 3, false, null, true);
            self::fail('Canonical source replay must still reject a different business fact.');
        } catch (RuntimeException $exception) {
            self::assertSame(409, $exception->getCode());
            self::assertStringContainsString('different request', $exception->getMessage());
        }
    }

    public function testUniqueConflictConvergenceCanonicalizesLegacyMixedCaseSourceModule(): void
    {
        $this->insertOpeningFixture();
        $service = new OperationManagementService();
        $input = (new OpeningService())->currentExecutionIntentInput(31, [7], 3, true, [
            'date_start' => '2026-08-13',
            'date_end' => '2026-08-13',
        ]);
        $payload = ['tenant_id' => 7] + $input;
        $key = SourceBackedExecutionIntentIdentityService::key($payload, null);
        $intentId = (int)Db::name('operation_execution_intents')->insertGetId([
            'tenant_id' => 7,
            'idempotency_key' => $key,
            'source_module' => ' Opening ',
            'source_record_id' => (int)$payload['source_record_id'],
            'hotel_id' => 7,
            'platform' => (string)$payload['platform'],
            'object_type' => (string)$payload['object_type'],
            'action_type' => (string)$payload['action_type'],
            'date_start' => (string)$payload['date_start'],
            'date_end' => (string)$payload['date_end'],
            'current_value_json' => json_encode($payload['current_value'], JSON_UNESCAPED_UNICODE),
            'target_value_json' => json_encode($payload['target_value'], JSON_UNESCAPED_UNICODE),
            'evidence_json' => json_encode($payload['evidence'], JSON_UNESCAPED_UNICODE),
            'expected_metric' => (string)$payload['expected_metric'],
            'expected_delta' => (float)$payload['expected_delta'],
            'risk_level' => (string)$payload['risk_level'],
            'blocked_reason' => '',
            'status' => (string)$payload['status'],
            'created_by' => 3,
            'created_at' => '2026-08-13 09:00:00',
            'updated_at' => '2026-08-13 09:00:00',
        ]);

        $converge = new \ReflectionMethod(OperationManagementService::class, 'replayTrustedExecutionIntent');
        $intent = $converge->invoke($service, $key, $payload, [7]);
        self::assertIsArray($intent);
        self::assertSame($intentId, (int)$intent['id']);
        self::assertTrue($intent['idempotent_replay']);
        self::assertSame('opening', $intent['source_module']);

        $createMethod = new \ReflectionMethod(OperationManagementService::class, 'persistExecutionIntentPayload');
        $lines = file($createMethod->getFileName()) ?: [];
        $source = implode('', array_slice(
            $lines,
            $createMethod->getStartLine() - 1,
            $createMethod->getEndLine() - $createMethod->getStartLine() + 1
        ));
        self::assertGreaterThanOrEqual(2, substr_count($source, 'replayTrustedExecutionIntent('));
        self::assertStringContainsString('catch (\Throwable $exception)', $source);
    }

    public function testOpeningMutationLockKeepsWriteAndTenantTransferStrictlySerialized(): void
    {
        $this->insertOpeningFixture();
        $service = new OperationManagementService();
        $intent = $this->createApprovedOpeningIntent($service, 3);
        $taskId = (int)($intent['tasks'][0]['id'] ?? 0);
        self::assertGreaterThan(0, $taskId);

        $transferConnection = new \PDO('sqlite:' . self::$sqlitePath);
        $transferConnection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $transferConnection->exec('PRAGMA busy_timeout = 1');
        $transferBlocked = false;

        $service->withExecutionTaskMutationAuthorization(
            $taskId,
            [7],
            function (array $authorization) use ($taskId, $transferConnection, &$transferBlocked): void {
                Db::name('operation_execution_evidence')->insert([
                    'tenant_id' => (int)$authorization['task']['tenant_id'],
                    'task_id' => $taskId,
                    'evidence_type' => 'manual_screenshot',
                    'before_json' => '{}',
                    'after_json' => '{}',
                    'platform_response_json' => '{}',
                    'attachment_path' => '/runtime/evidence/write-first.png',
                    'remark' => 'write acquired before transfer',
                    'created_by' => 3,
                    'created_at' => '2026-08-13 10:00:00',
                    'updated_at' => '2026-08-13 10:00:00',
                    'deleted_at' => null,
                ]);
                try {
                    $transferConnection->exec('UPDATE hotels SET tenant_id = 8 WHERE id = 7');
                } catch (\PDOException $exception) {
                    $transferBlocked = str_contains(strtolower($exception->getMessage()), 'locked');
                }
            }
        );

        self::assertTrue($transferBlocked, 'The second connection must wait while the task mutation owns the write transaction.');
        self::assertSame(1, (int)Db::name('operation_execution_evidence')->where('task_id', $taskId)->count());

        Db::connect()->close();
        Db::connect(null, true);
        $transferConnection = null;
        $transferConnection = new \PDO('sqlite:' . self::$sqlitePath);
        $transferConnection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $transferConnection->beginTransaction();
        $transferConnection->exec('UPDATE hotels SET tenant_id = 8 WHERE id = 7');
        $transferConnection->exec('UPDATE opening_projects SET tenant_id = 8 WHERE id = 31');
        $transferConnection->commit();
        try {
            $service->addExecutionEvidence($taskId, [7], [
                'evidence_type' => 'manual_screenshot',
                'attachment_path' => '/runtime/evidence/after-transfer.png',
            ], 3);
            self::fail('A later mutation must observe the completed tenant transfer.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('current tenant scope', $exception->getMessage());
        }
        self::assertSame(1, (int)Db::name('operation_execution_evidence')->where('task_id', $taskId)->count());
    }

    public function testEverySourceBackedTaskMutationRevalidatesCurrentSourceSnapshot(): void
    {
        $this->insertOpeningFixture();
        $service = new OperationManagementService();
        $intent = $this->createApprovedOpeningIntent($service, 3);
        $taskId = (int)($intent['tasks'][0]['id'] ?? 0);
        $beforeTask = Db::name('operation_execution_tasks')->where('id', $taskId)->find();
        self::assertIsArray($beforeTask);

        Db::name('opening_tasks')->where('id', 41)->update([
            'progress_percent' => 75,
            'updated_at' => '2026-08-13 12:00:00',
        ]);

        self::assertSame($taskId, (int)$service->readExecutionTask($taskId, [7])['id']);
        foreach ([
            function () use ($service, $taskId): array {
                $service->assertExecutionTaskMutationAuthorized($taskId, [7]);
                return [];
            },
            fn(): array => $service->executeExecutionTask($taskId, [7], ['status' => 'executing'], 3),
            fn(): array => $service->addExecutionEvidence($taskId, [7], [
                'evidence_type' => 'manual_screenshot',
                'attachment_path' => '/runtime/evidence/stale-source.png',
            ], 3),
            fn(): array => $service->reconcileScheduledExecutionTask($taskId, [7]),
            fn(): array => $service->reviewExecutionTask($taskId, [7], [
                'result_status' => 'observing',
                'result_summary' => 'must not persist',
            ], 3),
        ] as $call) {
            try {
                $call();
                self::fail('Every source-backed task write must reject a stale source snapshot.');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('snapshot changed', $e->getMessage());
            }
        }

        self::assertSame($beforeTask, Db::name('operation_execution_tasks')->where('id', $taskId)->find());
        self::assertSame(0, (int)Db::name('operation_execution_evidence')->count());
    }

    public function testOpeningApprovalLocksTasksAndReplayControllersSkipDuplicateAttach(): void
    {
        $approval = (string)file_get_contents(__DIR__ . '/../app/service/SourceBackedExecutionIntentApprovalService.php');
        $opening = (string)file_get_contents(__DIR__ . '/../app/service/OpeningService.php');
        self::assertStringContainsString("\$dates,\n                true", $approval);
        self::assertStringContainsString('$lockTasksForUpdate', $opening);
        self::assertStringContainsString('$query->lock(true);', $opening);

        $agent = (string)file_get_contents(__DIR__ . '/../app/controller/Agent.php');
        self::assertStringContainsString("(\$intent['idempotent_replay'] ?? false) === true", $agent, 'Agent.php');

        $method = new \ReflectionMethod(\app\controller\TransferDecision::class, 'createExecutionIntent');
        $lines = file($method->getFileName()) ?: [];
        $transfer = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));
        $transactionOffset = strpos($transfer, '$result = Db::transaction');
        self::assertNotFalse($transactionOffset, 'Transfer replay binding must run in the write transaction.');
        $transaction = substr($transfer, (int)$transactionOffset);
        self::assertStringContainsString('lockExecutionTrackingSource(', $transaction);
        self::assertStringContainsString('buildExecutionIntentInput($record,', $transaction);
        self::assertStringContainsString('createExecutionIntent(', $transaction);
        self::assertStringContainsString('attachExecutionTracking(', $transaction);
        self::assertStringNotContainsString('$this->service->detail(', $transaction);
        self::assertStringNotContainsString('idempotent_replay', $transaction);
        self::assertLessThan(
            strpos($transaction, 'attachExecutionTracking('),
            strpos($transaction, 'lockExecutionTrackingSource('),
            'Transfer must lock the source before attaching both new and replayed intent identities.'
        );
    }

    public function testDifferentRecordGetsANewKeyButDifferentHotelCannotRelinkTheRecord(): void
    {
        $service = new OperationManagementService();

        $first = $service->createExecutionIntent([7, 8], 7, $this->expansionInput(19, 7), 3, true);
        $differentRecord = $service->createExecutionIntent([7, 8], 7, $this->expansionInput(20, 7), 3, true);

        self::assertNotSame($first['id'], $differentRecord['id']);
        try {
            $service->createExecutionIntent([7, 8], 8, $this->expansionInput(19, 8), 3, true);
            self::fail('The same expansion record must not be relinked to a different hotel.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('different hotel', $e->getMessage());
            self::assertSame(409, $e->getCode());
        }

        self::assertSame(2, (int)Db::name('operation_execution_intents')->count());
        $keys = Db::name('operation_execution_intents')->order('idempotency_key', 'asc')->column('idempotency_key');
        self::assertCount(2, $keys);
        foreach ($keys as $key) {
            self::assertMatchesRegularExpression('/^source_intent_[a-f0-9]{32}$/D', (string)$key);
        }
    }

    public function testTrustedOtaDiagnosisKeyUsesTheDatabaseUniqueConstraint(): void
    {
        $service = new OperationManagementService();
        $input = [
            'source_module' => 'ota_diagnosis_saved',
            'source_record_id' => 91,
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'object_type' => 'campaign',
            'action_type' => 'ota_operation_follow_up',
            'date_start' => '2026-07-17',
            'date_end' => '2026-07-17',
            'target_value' => [
                'target_metric' => 'book_order_num',
                'workflow_schedule' => [
                    'assignee_id' => 3,
                    'due_at' => '2026-07-18 18:00:00',
                    'review_at' => '2026-07-19 10:00:00',
                ],
            ],
            'evidence' => ['evidence_refs' => ['ota-public-profile#91']],
            'expected_metric' => 'book_order_num',
            'status' => 'pending_approval',
        ];
        $key = 'ota_diagnosis_action_' . str_repeat('a', 32) . ':attempt:1';

        $first = $service->createExecutionIntent([7], 7, $input, 3, false, $key, true);
        $second = $service->createExecutionIntent([7], 7, $input, 3, false, $key, true);

        self::assertSame($first['id'], $second['id']);
        self::assertTrue($second['idempotent_replay']);
        self::assertSame(1, (int)Db::name('operation_execution_intents')->count());
        self::assertSame($key, Db::name('operation_execution_intents')->value('idempotency_key'));
    }

    public function testPublicPageDiagnosisDraftIsPersistedReadBackAndReplayed(): void
    {
        $diagnosis = [
            'status' => 'insufficient_evidence',
            'platform' => 'ctrip',
            'system_hotel_id' => 7,
            'business_date' => '2026-07-19',
            'platform_source_status' => 'persisted_public_profile_snapshots',
            'evidence_coverage' => [
                'observed_field_count' => 1,
                'verified_field_count' => 0,
                'expected_field_count' => 36,
                'coverage_rate' => 2.78,
            ],
            'dimensions' => [[
                'key' => 'platform_basics',
                'status' => 'partial',
                'unknown_fields' => ['address'],
                'facts' => [[
                    'field_key' => 'name',
                    'quality_status' => 'partial',
                ]],
            ]],
            'sources' => [[
                'platform_hotel_id' => '3456814',
                'source_url' => 'https://hotels.ctrip.com/hotels/3456814.html',
                'response_ref' => 'ota_ctrip_entity_snapshots#901',
                'persistence_readback_status' => 'readback_verified',
                'source_validation_status' => 'partial',
            ]],
            'next_action' => '补齐未知公开页字段。',
            'score_status' => 'not_calculated_no_validated_scoring_rule',
            'source_policy' => 'persisted_public_page_facts_only_no_default_score_no_ota_write',
            'scope_notice' => '仅为 OTA 公开页证据目录。',
        ];
        $diagnosisService = new OtaPublicPageDiagnosisService();
        $draft = $diagnosisService->buildExecutionIntentDraft($diagnosis, [
            'assignee_id' => 3,
            'due_at' => '2099-07-20T18:00',
            'review_at' => '2099-07-21T10:00',
        ]);
        $rescheduledDraft = $diagnosisService->buildExecutionIntentDraft($diagnosis, [
            'assignee_id' => 3,
            'due_at' => '2099-07-22T18:00',
            'review_at' => '2099-07-23T10:00',
        ]);
        $service = new OperationManagementService();

        $first = $service->createExecutionIntent([7], 7, $draft['input'], 3, false, $draft['idempotency_key'], true);
        $second = $service->createExecutionIntent(
            [7],
            7,
            $rescheduledDraft['input'],
            3,
            false,
            $rescheduledDraft['idempotency_key'],
            true
        );

        self::assertSame($draft['idempotency_key'], $rescheduledDraft['idempotency_key']);
        self::assertSame($first['id'], $second['id']);
        self::assertTrue($second['idempotent_replay']);
        $readback = $service->readExecutionIntentByIdempotencyKey($draft['idempotency_key'], [7]);
        self::assertIsArray($readback);
        self::assertSame($first['id'], $readback['id']);
        self::assertNull($service->readExecutionIntentByIdempotencyKey($draft['idempotency_key'], [8]));
        self::assertSame('ota_diagnosis', $first['source_module']);
        self::assertGreaterThan(4294967295, $first['source_record_id']);
        self::assertSame('data_collection', $first['object_type']);
        self::assertSame('complete_public_page_evidence', $first['action_type']);
        self::assertSame(7, $first['hotel_id']);
        self::assertSame('2026-07-19', $first['date_start']);
        self::assertContains('ota_ctrip_entity_snapshots#901', $first['evidence']['evidence_refs']);
        self::assertContains('platform_basics:address:missing', $first['evidence']['data_gaps']);
        self::assertSame([
            'assignee_id' => 3,
            'due_at' => '2099-07-20 18:00:00',
            'review_at' => '2099-07-21 10:00:00',
            'source_policy' => 'human_assigned_schedule_requires_manual_approval_and_readback_review',
        ], $first['target_value']['workflow_schedule']);
        $latest = $service->readLatestOtaDiagnosisExecutionIntentAttempt($draft['idempotency_base_key'], [7]);
        self::assertIsArray($latest);
        self::assertSame(1, $latest['attempt']);
        self::assertSame($first['id'], $latest['intent']['id']);

        $rescheduled = $service->reschedulePendingExecutionIntent(
            (int)$first['id'],
            [7],
            $rescheduledDraft['input']['target_value']['workflow_schedule'],
            3
        );
        self::assertSame($first['id'], $rescheduled['id']);
        self::assertSame(
            $rescheduledDraft['input']['target_value']['workflow_schedule'],
            $rescheduled['target_value']['workflow_schedule']
        );
        self::assertSame(
            $rescheduledDraft['input']['target_value']['workflow_schedule'],
            $rescheduled['evidence']['workflow_schedule']
        );
        self::assertSame(3, $rescheduled['evidence']['schedule_updated_by']);

        $service->approveExecutionIntent((int)$first['id'], false, 'fixture rejection', 3, [7]);
        try {
            $service->reschedulePendingExecutionIntent(
                (int)$first['id'],
                [7],
                $draft['input']['target_value']['workflow_schedule'],
                3
            );
            self::fail('A terminal execution intent must not be rescheduled.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('draft or pending_approval', $exception->getMessage());
        }
        self::assertSame(1, (int)Db::name('operation_execution_intents')->count());
    }

    public function testLatestPublicPageAttemptUsesNumericAttemptInsteadOfRowWindowOrLexicalOrder(): void
    {
        $diagnosisService = new OtaPublicPageDiagnosisService();
        $diagnosis = $diagnosisService->build(7, 'ctrip', '2026-07-19', []);
        $draft = $diagnosisService->buildExecutionIntentDraft($diagnosis, [
            'assignee_id' => 3,
            'due_at' => '2099-07-20T18:00',
            'review_at' => '2099-07-21T10:00',
        ]);
        $service = new OperationManagementService();
        $created = [];
        foreach ([1, 10, 2] as $attempt) {
            $input = $draft['input'];
            $input['evidence']['intent_attempt'] = $attempt;
            $created[$attempt] = $service->createExecutionIntent(
                [7],
                7,
                $input,
                3,
                false,
                $draft['idempotency_base_key'] . ':attempt:' . $attempt,
                true
            );
        }

        $latest = $service->readLatestOtaDiagnosisExecutionIntentAttempt($draft['idempotency_base_key'], [7]);
        self::assertIsArray($latest);
        self::assertSame(10, $latest['attempt']);
        self::assertSame($created[10]['id'], $latest['intent']['id']);
        self::assertSame($draft['idempotency_base_key'] . ':attempt:10', $latest['idempotency_key']);
    }

    public function testSchemaAndControllerExposeTheConcurrencyContract(): void
    {
        $migration = file_get_contents(__DIR__ . '/../database/migrations/20260716_add_execution_intent_idempotency_key.sql');
        $priceSuggestionMigration = file_get_contents(__DIR__ . '/../database/migrations/20260722_backfill_price_suggestion_intent_idempotency.sql');
        $baseSchema = file_get_contents(__DIR__ . '/../database/migrations/20260526_create_operation_execution_loop_tables.sql');
        $initSchema = file_get_contents(__DIR__ . '/../database/init_full.sql');
        $controller = file_get_contents(__DIR__ . '/../app/controller/Expansion.php');
        $expansionService = file_get_contents(__DIR__ . '/../app/service/ExpansionService.php');

        self::assertIsString($migration);
        self::assertStringContainsString('ADD COLUMN IF NOT EXISTS `idempotency_key`', $migration);
        self::assertStringContainsString("CONCAT('expansion:v1:', `source_record_id`)", $migration);
        self::assertStringContainsString('ADD UNIQUE INDEX IF NOT EXISTS `uniq_operation_exec_intent_idempotency`', $migration);
        self::assertIsString($priceSuggestionMigration);
        self::assertStringContainsString("CONCAT('price_suggestion:v1:', `canonical`.`source_record_id`)", $priceSuggestionMigration);
        self::assertStringContainsString('MIN(`id`) AS `canonical_id`', $priceSuggestionMigration);
        self::assertStringContainsString('`existing`.`id` IS NULL', $priceSuggestionMigration);
        self::assertIsString($baseSchema);
        self::assertStringContainsString('`idempotency_key` VARCHAR(191)', $baseSchema);
        self::assertStringContainsString('UNIQUE KEY `uniq_operation_exec_intent_idempotency`', $baseSchema);
        self::assertIsString($initSchema);
        self::assertStringContainsString('20260716_add_execution_intent_idempotency_key.sql', $initSchema);
        self::assertIsString($controller);
        self::assertStringContainsString('$this->service->detail($id, $userId, $isSuperAdmin, true)', $controller);
        self::assertStringContainsString("'idempotent_replay' => (\$intent['idempotent_replay'] ?? false) === true", $controller);
        self::assertIsString($expansionService);
        self::assertStringContainsString('if ($lockForUpdate) {', $expansionService);
        self::assertStringContainsString('$query->lock(true);', $expansionService);

        $methodStart = strpos($controller, 'public function createExecutionIntent');
        $methodEnd = strpos($controller, 'public function archive', $methodStart);
        self::assertNotFalse($methodStart);
        self::assertNotFalse($methodEnd);
        $methodSource = substr($controller, $methodStart, $methodEnd - $methodStart);
        self::assertLessThan(
            strpos($methodSource, 'Db::transaction('),
            strpos($methodSource, '$this->service->ensureTable();'),
            'Schema DDL must run before the transaction so it cannot implicitly commit the row lock.'
        );
    }

    public function testDatabaseUniqueConstraintRejectsDuplicateExpansionKey(): void
    {
        $service = new OperationManagementService();
        $service->createExecutionIntent([7], 7, $this->expansionInput(19, 7), 3, true);
        $row = Db::name('operation_execution_intents')->where('source_module', 'expansion')->find();
        self::assertIsArray($row);
        unset($row['id']);

        $this->expectException(Throwable::class);
        Db::name('operation_execution_intents')->insert($row);
    }

    public function testExpansionTrackingReplayDoesNotAppendDuplicateHistory(): void
    {
        Db::name('users')->insert([
            'id' => 3,
            'tenant_id' => 7,
            'hotel_id' => 7,
        ]);
        $operation = new OperationManagementService();
        $intent = $operation->createExecutionIntent([7], 7, $this->expansionInput(19, 7), 3, true);
        $service = new ExpansionService();
        $tracking = [
            'execution_intent_id' => (int)$intent['id'],
            'hotel_id' => 7,
            'status' => 'pending_approval',
        ];
        $first = $service->attachExecutionTracking(19, 3, false, $tracking);
        $second = $service->attachExecutionTracking(19, 3, false, $tracking);

        self::assertSame((int)$intent['id'], $first['execution_intent_id']);
        self::assertSame((int)$intent['id'], $second['execution_intent_id']);
        $result = json_decode(
            (string)Db::name('expansion_records')->where('id', 19)->value('result_json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertCount(1, $result['execution_tracking']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('current expansion execution intent is required');
        $service->attachExecutionTracking(19, 3, false, [
            'execution_intent_id' => (int)$intent['id'] + 1,
            'hotel_id' => 7,
            'status' => 'pending_approval',
        ]);
    }

    /** @return array<string, mixed> */
    private function expansionInput(int $recordId, int $hotelId): array
    {
        $record = Db::name('expansion_records')->where('id', $recordId)->whereNull('deleted_at')->find();
        if (!is_array($record)) {
            $this->insertExpansionFixture($recordId, 7);
            $record = Db::name('expansion_records')->where('id', $recordId)->find();
        }
        self::assertIsArray($record);

        return (new ExpansionService())->buildExecutionIntentInput($record, $hotelId, [
            'date_start' => '2026-07-16',
            'date_end' => '2026-07-31',
        ]);
    }

    /** @param array<string,mixed> $overrides */
    private function insertExpansionFixture(int $recordId, int $tenantId, array $overrides = []): void
    {
        $input = [
            'project_name' => 'Expansion project ' . $recordId,
            'property_area' => 3600,
            'estimated_rent' => 120000,
            'target_room_count' => 88,
            'lease_years' => 10,
            'rent_free_months' => 3,
            'fitout_budget' => 420,
            'expected_adr' => 328,
            'expected_occupancy_rate' => 0.82,
            'source_evidence' => ['competitor_samples' => 'field checked'],
            'market_result' => [
                'market_heat_score' => 82,
                'decision' => '推进复核',
                'investment_risk_level' => 'medium',
            ],
            'benchmark_result' => [
                'recommended_benchmarks' => [['hotel' => 'Comparable A', 'adr' => 320]],
                'source' => 'user_provided_competitor_sample',
            ],
        ];
        $result = [
            'task_board' => [[
                'name' => 'Confirm lease evidence',
                'status' => 'doing',
                'owner' => 'operator-3',
                'due_date' => '2026-08-20',
                'risk_level' => 'low',
                'is_observed' => true,
                'source' => 'human_confirmed',
                'evidence_status' => 'confirmed',
            ]],
            'delay_risk' => ['level' => 'low', 'points' => []],
            'business_fact' => 'initial',
        ];
        Db::name('expansion_records')->insert(array_replace([
            'id' => $recordId,
            'tenant_id' => $tenantId,
            'record_type' => 'collaboration',
            'project_name' => 'Expansion project ' . $recordId,
            'city_area' => 'Hangzhou',
            'input_json' => json_encode($input, JSON_THROW_ON_ERROR),
            'result_json' => json_encode($result, JSON_THROW_ON_ERROR),
            'decision' => 'review_ready',
            'risk_level' => 'medium',
            'created_by' => 3,
            'created_at' => '2026-08-13 09:00:00',
            'updated_at' => '2026-08-13 09:00:00',
            'deleted_at' => null,
        ], $overrides));
    }

    private function insertOpeningFixture(): void
    {
        Db::name('users')->insert(['id' => 3, 'tenant_id' => 7, 'hotel_id' => 7]);
        Db::name('opening_projects')->insert([
            'id' => 31,
            'tenant_id' => 7,
            'hotel_id' => 7,
            'project_name' => 'Opening 31',
            'hotel_name' => 'Hotel 7',
            'opening_date' => '2026-09-01',
            'status' => 'preparing',
            'overall_score' => 0,
            'risk_level' => 'low',
            'ai_penetration_rate' => 0,
            'created_by' => 3,
            'created_at' => '2026-08-13 09:00:00',
            'updated_at' => '2026-08-13 09:00:00',
            'deleted_at' => null,
        ]);
        Db::name('opening_tasks')->insert([
            'id' => 41,
            'project_id' => 31,
            'category' => 'system',
            'task_name' => 'Verify PMS',
            'task_desc' => 'Read back PMS status',
            'is_core' => 1,
            'deadline' => '2026-08-20',
            'status' => 'doing',
            'progress_percent' => 50,
            'risk_level' => 'low',
            'acceptance_standard' => 'readback verified',
            'sort_order' => 1,
            'created_at' => '2026-08-13 09:00:00',
            'updated_at' => '2026-08-13 09:00:00',
        ]);
    }

    private function insertSourceIntent(string $sourceModule, int $sourceRecordId, int $tenantId, int $hotelId): int
    {
        return (int)Db::name('operation_execution_intents')->insertGetId([
            'tenant_id' => $tenantId,
            'idempotency_key' => sprintf('%s:%d:tenant:%d', $sourceModule, $sourceRecordId, $tenantId),
            'source_module' => $sourceModule,
            'source_record_id' => $sourceRecordId,
            'hotel_id' => $hotelId,
            'platform' => 'internal',
            'object_type' => 'test',
            'action_type' => 'tracking',
            'date_start' => '2026-08-13',
            'date_end' => '2026-08-13',
            'current_value_json' => '{}',
            'target_value_json' => '{}',
            'evidence_json' => '{}',
            'expected_metric' => 'closure',
            'expected_delta' => 0,
            'risk_level' => 'medium',
            'blocked_reason' => '',
            'status' => 'pending_approval',
            'created_by' => 3,
            'approved_by' => 0,
            'approved_at' => null,
            'review_remark' => '',
            'created_at' => '2026-08-13 09:00:00',
            'updated_at' => '2026-08-13 09:00:00',
            'deleted_at' => null,
        ]);
    }

    /** @return array<string,mixed> */
    private function trackingPayload(int $intentId, string $sourceModule): array
    {
        return [
            'execution_tracking' => [[
                'type' => 'operation_execution_intent',
                'execution_intent_id' => $intentId,
                'hotel_id' => 7,
                'status' => 'pending',
                'source_module' => $sourceModule,
                'linked_at' => '2026-08-13 09:00:00',
            ]],
            'operation_execution_intent_id' => $intentId,
            'execution_intent_id' => $intentId,
            'post_decision_tracking' => [
                'status' => 'linked',
                'latest_execution_intent_id' => $intentId,
                'latest_status' => 'pending',
                'hotel_id' => 7,
                'linked_at' => '2026-08-13 09:00:00',
            ],
        ];
    }

    /** @param array<string,mixed> $payload */
    private function insertTransferRecord(int $id, int $tenantId, array $payload): void
    {
        Db::name('transfer_records')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'record_type' => 'pricing',
            'hotel_id' => 7,
            'hotel_name' => 'Hotel 7',
            'source_date' => '2026-08-13',
            'input_json' => json_encode([], JSON_THROW_ON_ERROR),
            'result_json' => json_encode($payload, JSON_THROW_ON_ERROR),
            'snapshot_json' => json_encode([], JSON_THROW_ON_ERROR),
            'decision' => 'review',
            'risk_level' => 'medium',
            'created_by' => 3,
            'created_at' => '2026-08-13 09:00:00',
            'updated_at' => '2026-08-13 09:00:00',
            'deleted_at' => null,
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function insertFeasibilityRecord(int $id, int $tenantId, array $payload): void
    {
        Db::name('feasibility_reports')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'project_name' => 'Feasibility ' . $id,
            'input_json' => json_encode(['hotel_id' => 7, 'system_hotel_id' => 7], JSON_THROW_ON_ERROR),
            'snapshot_json' => json_encode(['snapshot_scope' => ['hotel_id' => 7]], JSON_THROW_ON_ERROR),
            'report_json' => json_encode($payload, JSON_THROW_ON_ERROR),
            'conclusion_grade' => null,
            'payback_months' => null,
            'total_investment' => null,
            'created_by' => 3,
            'created_at' => '2026-08-13 09:00:00',
            'updated_at' => '2026-08-13 09:00:00',
            'deleted_at' => null,
        ]);
    }

    /** @return array<int,int> */
    private function trackingReferenceIds(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $ids = [];
        foreach ($value as $key => $child) {
            if (is_string($key) && in_array($key, [
                'operation_execution_intent_id',
                'execution_intent_id',
                'latest_execution_intent_id',
                'tracking_record_id',
                'post_decision_tracking_id',
                'opening_project_id',
                'investment_tracking_id',
            ], true) && (int)$child > 0) {
                $ids[] = (int)$child;
            }
            $ids = array_merge($ids, $this->trackingReferenceIds($child));
        }
        return array_values(array_unique($ids));
    }

    /** @return array<int,int> */
    private function trackingReferenceIdsCaseInsensitive(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $fields = [
            'operation_execution_intent_id',
            'execution_intent_id',
            'latest_execution_intent_id',
            'tracking_record_id',
            'post_decision_tracking_id',
            'opening_project_id',
            'investment_tracking_id',
        ];
        $ids = [];
        foreach ($value as $key => $child) {
            if (is_string($key) && in_array(strtolower($key), $fields, true) && !is_array($child) && (int)$child > 0) {
                $ids[] = (int)$child;
            }
            $ids = array_merge($ids, $this->trackingReferenceIdsCaseInsensitive($child));
        }
        return array_values(array_unique($ids));
    }

    private function containsArrayKey(mixed $value, string $needle): bool
    {
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $key => $child) {
            if ($key === $needle || $this->containsArrayKey($child, $needle)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $readiness @return array<string,mixed> */
    private function readinessCheck(array $readiness, string $key): array
    {
        foreach ((array)($readiness['checks'] ?? []) as $check) {
            if (is_array($check) && ($check['key'] ?? null) === $key) {
                return $check;
            }
        }
        self::fail('Missing readiness check: ' . $key);
    }

    /** @param array<int,string> $queries */
    private function bridgeLookupQueryCount(array $queries): int
    {
        return count(array_filter($queries, static fn(string $sql): bool =>
            preg_match('/\bfrom\s+[`"]?hotels[`"]?\b/i', $sql) === 1
            || str_contains($sql, 'operation_execution_intents')
        ));
    }

    /** @return array<string, mixed> */
    private function createApprovedOpeningIntent(OperationManagementService $service, int $createdBy): array
    {
        $input = (new OpeningService())->currentExecutionIntentInput(31, [7], $createdBy, true, [
            'date_start' => '2026-08-13',
            'date_end' => '2026-08-13',
        ]);
        $intent = $service->createExecutionIntent([7], 7, $input, $createdBy, false, null, true);

        return $service->approveExecutionIntent((int)$intent['id'], true, 'approved', $createdBy, [7]);
    }

    private function priceSuggestionInput(int $recordId, int $hotelId): array
    {
        $source = Db::name('price_suggestions')->where('id', $recordId)->find();
        if (!is_array($source)) {
            throw new RuntimeException('price suggestion fixture is missing');
        }
        $mapping = $this->priceSuggestionMappingFromSource($source);
        return [
            'source_module' => 'price_suggestion',
            'source_record_id' => $recordId,
            'hotel_id' => $hotelId,
            'platform' => 'ctrip',
            'object_type' => 'price',
            'action_type' => 'price_adjust',
            'date_start' => '2026-08-13',
            'date_end' => '2026-08-13',
            'current_value' => [
                'current_price' => (float)$source['current_price'],
                'room_type_id' => (int)$source['room_type_id'],
            ],
            'target_value' => [
                'target_price' => (float)$source['suggested_price'],
                'min_price' => (float)$source['min_price'],
                'max_price' => (float)$source['max_price'],
                'room_type_key' => (string)$mapping['room_type_key'],
                'rate_plan_key' => (string)$mapping['rate_plan_key'],
                'room_type_id' => (int)$source['room_type_id'],
            ],
            'evidence' => [
                'manual_review' => ['action' => 'approve'],
                'metric_scope' => 'ota_channel',
                'source_business_date' => (string)$source['suggestion_date'],
                'source_snapshot_digest' => SourceBackedExecutionIntentIdentityService::priceSuggestionSnapshotDigest($source),
                'ota_target_mapping' => $mapping,
            ],
            'expected_metric' => 'orders',
            'expected_delta' => 1,
            'risk_level' => 'medium',
            'status' => 'pending_approval',
        ];
    }

    private function insertApprovedPriceSuggestion(int $recordId, int $hotelId, int $tenantId): void
    {
        $roomType = Db::name('room_types')->where('id', 3)->find();
        if (is_array($roomType)) {
            Db::name('room_types')->where('id', 3)->update([
                'tenant_id' => $tenantId, 'hotel_id' => $hotelId, 'is_enabled' => 1,
            ]);
        } else {
            Db::name('room_types')->insert([
                'id' => 3, 'tenant_id' => $tenantId, 'hotel_id' => $hotelId,
                'name' => 'Deluxe King', 'is_enabled' => 1,
            ]);
        }
        $mapping = [
            'mapping_record_id' => 'fixture-ctrip-room-3',
            'mapping_version' => 'v1',
            'status' => 'confirmed',
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'platform' => 'ctrip',
            'room_type_id' => 3,
            'room_type_key' => 'deluxe-king',
            'rate_plan_key' => 'standard',
            'confirmed_by' => 3,
            'confirmed_at' => '2026-08-13 07:55:00',
        ];
        $mapping['mapping_digest'] = PriceSuggestionOtaTargetMappingService::mappingDigest($mapping);
        Db::name('price_suggestions')->insert([
            'id' => $recordId,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'room_type_id' => 3,
            'demand_forecast_id' => 0,
            'suggestion_date' => '2026-08-13',
            'suggestion_type' => 1,
            'current_price' => 300,
            'suggested_price' => 320,
            'min_price' => 260,
            'max_price' => 380,
            'confidence_score' => 0.91,
            'competitor_data' => json_encode(['avg_price' => 330]),
            'factors' => json_encode([
                'manual_review_versions' => [['action' => 'approve']],
                PriceSuggestionOtaTargetMappingService::FACTOR_KEY => $mapping,
            ], JSON_THROW_ON_ERROR),
            'reason' => 'approved price decision',
            'status' => 2,
            'applied_by' => 3,
            'applied_time' => '2026-08-13 08:00:00',
            'remark' => 'approved source',
        ]);
    }

    /** @param array<string,mixed> $source @return array<string,mixed> */
    private function priceSuggestionMappingFromSource(array $source): array
    {
        $factors = json_decode((string)($source['factors'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        $mapping = $factors[PriceSuggestionOtaTargetMappingService::FACTOR_KEY] ?? [];
        self::assertIsArray($mapping);
        $mapping['mapping_source'] = 'price_suggestions.factors.' . PriceSuggestionOtaTargetMappingService::FACTOR_KEY;
        return $mapping;
    }

    private function insertOperationAlert(int $recordId, string $message, float $observedValue): void
    {
        Db::name('operation_alerts')->insert([
            'id' => $recordId, 'tenant_id' => 7, 'hotel_id' => 7,
            'alert_type' => 'conversion_low', 'level' => 'medium',
            'title' => 'Conversion alert', 'message' => $message, 'source' => 'rule',
            'status' => 'unread', 'related_date' => '2026-08-13',
            'action_suggestion' => 'Review conversion funnel',
            'raw_data' => json_encode([
                'metric_key' => 'ota_conversion_rate', 'threshold_value' => 3,
                'observed_value' => $observedValue, 'comparison_rule' => 'observed_value < threshold_value',
                'action_suggestion' => 'Review conversion funnel',
            ], JSON_THROW_ON_ERROR),
            'deleted_at' => null,
        ]);
    }

    private static function createSchema(): void
    {
        Db::execute(<<<'SQL'
CREATE TABLE hotels (
    id INTEGER PRIMARY KEY,
    tenant_id INTEGER NOT NULL
)
SQL);
        Db::name('hotels')->insertAll([
            ['id' => 7, 'tenant_id' => 7],
            ['id' => 8, 'tenant_id' => 7],
        ]);
        Db::execute(<<<'SQL'
CREATE TABLE operation_execution_intents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER,
    idempotency_key TEXT UNIQUE,
    source_module TEXT NOT NULL DEFAULT '',
    source_record_id INTEGER NOT NULL DEFAULT 0,
    hotel_id INTEGER NOT NULL,
    platform TEXT NOT NULL DEFAULT '',
    object_type TEXT NOT NULL DEFAULT '',
    action_type TEXT NOT NULL DEFAULT '',
    date_start TEXT,
    date_end TEXT,
    current_value_json TEXT,
    target_value_json TEXT,
    evidence_json TEXT,
    expected_metric TEXT NOT NULL DEFAULT '',
    expected_delta REAL NOT NULL DEFAULT 0,
    risk_level TEXT NOT NULL DEFAULT 'medium',
    blocked_reason TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL,
    created_by INTEGER NOT NULL DEFAULT 0,
    approved_by INTEGER NOT NULL DEFAULT 0,
    approved_at TEXT,
    review_remark TEXT NOT NULL DEFAULT '',
    created_at TEXT,
    updated_at TEXT,
    deleted_at TEXT
)
SQL);
        Db::execute(<<<'SQL'
CREATE TABLE operation_alerts (
    id INTEGER PRIMARY KEY,
    tenant_id INTEGER NOT NULL,
    hotel_id INTEGER NOT NULL,
    alert_type TEXT NOT NULL,
    level TEXT NOT NULL,
    title TEXT NOT NULL,
    message TEXT NOT NULL,
    source TEXT NOT NULL,
    status TEXT NOT NULL,
    related_date TEXT NOT NULL,
    action_suggestion TEXT,
    raw_data TEXT,
    deleted_at TEXT,
    created_at TEXT,
    updated_at TEXT
)
SQL);
        Db::execute(<<<'SQL'
CREATE TABLE room_types (
    id INTEGER PRIMARY KEY,
    tenant_id INTEGER NOT NULL,
    hotel_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    is_enabled INTEGER NOT NULL DEFAULT 1,
    update_time TEXT
)
SQL);
        Db::execute(<<<'SQL'
CREATE TABLE operation_execution_tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER,
    intent_id INTEGER NOT NULL,
    hotel_id INTEGER NOT NULL,
    execution_mode TEXT NOT NULL DEFAULT 'manual',
    operator_id INTEGER NOT NULL DEFAULT 0,
    target_value_json TEXT,
    current_value_json TEXT,
    blocked_reason TEXT NOT NULL DEFAULT '',
    action_track_id INTEGER NOT NULL DEFAULT 0,
    result_status TEXT NOT NULL DEFAULT 'observing',
    result_summary TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL,
    executed_at TEXT,
    created_at TEXT,
    updated_at TEXT,
    deleted_at TEXT
)
SQL);
        Db::execute(<<<'SQL'
CREATE TABLE operation_execution_evidence (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER,
    task_id INTEGER NOT NULL,
    evidence_type TEXT NOT NULL DEFAULT 'manual',
    before_json TEXT,
    after_json TEXT,
    attachment_path TEXT NOT NULL DEFAULT '',
    platform_response_json TEXT,
    remark TEXT NOT NULL DEFAULT '',
    created_by INTEGER NOT NULL DEFAULT 0,
    created_at TEXT,
    updated_at TEXT,
    deleted_at TEXT
)
SQL);
        Db::execute(<<<'SQL'
CREATE TABLE users (
    id INTEGER PRIMARY KEY,
    tenant_id INTEGER,
    hotel_id INTEGER
)
SQL);
        Db::execute(<<<'SQL'
CREATE TABLE quant_simulation_records (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER,
    project_name TEXT NOT NULL DEFAULT '',
    input_json TEXT,
    result_json TEXT,
    scenarios_json TEXT,
    risk_hints_json TEXT,
    monthly_net_cashflow REAL NOT NULL DEFAULT 0,
    payback_months REAL,
    risk_level TEXT NOT NULL DEFAULT '',
    created_by INTEGER NOT NULL DEFAULT 0,
    created_at TEXT,
    updated_at TEXT,
    deleted_at TEXT
)
SQL);
        Db::execute(<<<'SQL'
CREATE TABLE expansion_records (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER,
    record_type TEXT NOT NULL DEFAULT '',
    project_name TEXT NOT NULL DEFAULT '',
    city_area TEXT NOT NULL DEFAULT '',
    input_json TEXT,
    result_json TEXT,
    decision TEXT NOT NULL DEFAULT '',
    risk_level TEXT NOT NULL DEFAULT '',
    created_by INTEGER NOT NULL DEFAULT 0,
    created_at TEXT,
    updated_at TEXT,
    deleted_at TEXT
)
SQL);
        Db::execute(<<<'SQL'
CREATE TABLE opening_projects (
    id INTEGER PRIMARY KEY,
    tenant_id INTEGER NOT NULL,
    hotel_id INTEGER NOT NULL,
    project_name TEXT NOT NULL DEFAULT '',
    hotel_name TEXT NOT NULL DEFAULT '',
    city TEXT NOT NULL DEFAULT '',
    brand TEXT NOT NULL DEFAULT '',
    positioning TEXT NOT NULL DEFAULT '',
    room_count INTEGER NOT NULL DEFAULT 0,
    opening_date TEXT NOT NULL,
    manager_name TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'preparing',
    overall_score REAL NOT NULL DEFAULT 0,
    risk_level TEXT NOT NULL DEFAULT 'low',
    ai_penetration_rate REAL NOT NULL DEFAULT 0,
    created_by INTEGER NOT NULL DEFAULT 0,
    created_at TEXT,
    updated_at TEXT,
    deleted_at TEXT
)
SQL);
        Db::execute(<<<'SQL'
CREATE TABLE opening_tasks (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL,
    category TEXT NOT NULL DEFAULT '',
    task_name TEXT NOT NULL DEFAULT '',
    task_desc TEXT NOT NULL DEFAULT '',
    is_core INTEGER NOT NULL DEFAULT 0,
    owner_name TEXT NOT NULL DEFAULT '',
    collaborator_name TEXT NOT NULL DEFAULT '',
    deadline TEXT,
    status TEXT NOT NULL DEFAULT 'todo',
    progress_percent INTEGER NOT NULL DEFAULT 0,
    risk_level TEXT NOT NULL DEFAULT 'low',
    acceptance_standard TEXT NOT NULL DEFAULT '',
    ai_suggestion TEXT NOT NULL DEFAULT '',
    remark TEXT NOT NULL DEFAULT '',
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT,
    updated_at TEXT
)
SQL);
        Db::execute(<<<'SQL'
CREATE TABLE transfer_records (
    id INTEGER PRIMARY KEY,
    record_type TEXT NOT NULL,
    tenant_id INTEGER NOT NULL,
    hotel_id INTEGER NOT NULL,
    hotel_name TEXT NOT NULL DEFAULT '',
    source_date TEXT,
    input_json TEXT,
    result_json TEXT,
    snapshot_json TEXT,
    decision TEXT NOT NULL DEFAULT '',
    risk_level TEXT NOT NULL DEFAULT '',
    created_by INTEGER NOT NULL DEFAULT 0,
    created_at TEXT,
    updated_at TEXT,
    deleted_at TEXT
)
SQL);
        Db::execute(<<<'SQL'
CREATE TABLE feasibility_reports (
    id INTEGER PRIMARY KEY,
    tenant_id INTEGER NOT NULL,
    project_name TEXT NOT NULL DEFAULT '',
    input_json TEXT,
    snapshot_json TEXT,
    report_json TEXT,
    conclusion_grade TEXT,
    payback_months REAL,
    total_investment REAL,
    created_by INTEGER NOT NULL DEFAULT 0,
    created_at TEXT,
    updated_at TEXT,
    deleted_at TEXT
)
SQL);
        Db::execute(<<<'SQL'
CREATE TABLE price_suggestions (
    id INTEGER PRIMARY KEY,
    tenant_id INTEGER NOT NULL,
    hotel_id INTEGER NOT NULL,
    room_type_id INTEGER NOT NULL,
    demand_forecast_id INTEGER NOT NULL DEFAULT 0,
    suggestion_date TEXT NOT NULL,
    suggestion_type INTEGER NOT NULL DEFAULT 1,
    current_price REAL NOT NULL,
    suggested_price REAL NOT NULL,
    min_price REAL NOT NULL DEFAULT 0,
    max_price REAL NOT NULL DEFAULT 0,
    confidence_score REAL NOT NULL DEFAULT 0,
    competitor_data TEXT,
    factors TEXT,
    reason TEXT NOT NULL DEFAULT '',
    status INTEGER NOT NULL,
    applied_by INTEGER NOT NULL DEFAULT 0,
    applied_time TEXT,
    remark TEXT NOT NULL DEFAULT ''
)
SQL);
    }
}
