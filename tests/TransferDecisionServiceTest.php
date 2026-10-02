<?php
declare(strict_types=1);

namespace Tests;

use app\service\LlmClient;
use app\service\OnlineDataFieldFactService;
use app\service\TransferDecisionService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\ReflectionHelper;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class TransferDecisionServiceTest extends TestCase
{
    use ReflectionHelper;

    public function testBuildSourcePayloadHoldsOneHotelTenantSnapshotAcrossAllSourceQueries(): void
    {
        $this->withTransferTenantDatabase(function (): void {
            Db::name('daily_reports')->insertAll([
                ['id' => 1, 'tenant_id' => 7, 'hotel_id' => 7, 'report_date' => '2026-08-13', 'revenue' => 700],
                ['id' => 2, 'tenant_id' => 8, 'hotel_id' => 7, 'report_date' => '2026-08-13', 'revenue' => 800],
            ]);
            Db::name('online_daily_data')->insertAll([
                ['id' => 1, 'tenant_id' => 7, 'system_hotel_id' => 7, 'data_date' => '2026-08-13', 'amount' => 70],
                ['id' => 2, 'tenant_id' => 8, 'system_hotel_id' => 7, 'data_date' => '2026-08-13', 'amount' => 80],
            ]);
            $peer = new \PDO('sqlite:' . (string)Config::get('database.connections.sqlite.database'));
            $peer->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $peer->exec('PRAGMA busy_timeout = 10');

            $attempted = false;
            $migrationBlocked = false;
            $sourceSelects = 0;
            $active = true;
            Db::event('before_select', function (mixed $query) use (
                &$attempted,
                &$migrationBlocked,
                &$sourceSelects,
                &$active,
                &$peer
            ): void {
                if (!$active) {
                    return;
                }
                $table = (string)$query->getTable();
                if (!in_array($table, ['daily_reports', 'online_daily_data'], true)) {
                    return;
                }
                $sourceSelects++;
                if ($sourceSelects !== 2) {
                    return;
                }
                $attempted = true;
                try {
                    $peer->exec('UPDATE hotels SET tenant_id = 8 WHERE id = 7');
                } catch (\Throwable) {
                    $migrationBlocked = true;
                    try {
                        $peer->exec('ROLLBACK');
                    } catch (\Throwable) {
                    }
                    $peer = null;
                }
            });

            try {
                $payload = (new TransferDecisionService())->buildSourcePayload([7], 7, '2026-08-13');
            } finally {
                $active = false;
            }

            self::assertTrue($attempted, 'The migration probe must run between source queries.');
            self::assertTrue($migrationBlocked, 'A peer tenant migration must not commit inside one source snapshot.');
            self::assertSame(7, (int)Db::name('hotels')->where('id', 7)->value('tenant_id'));
            self::assertSame(7, $payload['snapshot']['tenant_id'] ?? null);
            self::assertSame(
                ['hotel_id' => 7, 'tenant_id' => 7],
                $payload['snapshot']['source_identity'] ?? null
            );
            self::assertSame(1, $payload['snapshot']['source_counts']['daily_reports'] ?? null);
            self::assertSame(1, $payload['snapshot']['source_counts']['annual_daily_reports'] ?? null);
            self::assertSame(1, $payload['snapshot']['source_counts']['online_daily_data_scoped'] ?? null);
        });
    }

    public function testSourceReadsFailClosedInsteadOfMasqueradingAsEmptyBusinessData(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/service/TransferDecisionService.php');

        self::assertStringContainsString("\$this->assertTransferTableColumns('daily_reports'", $source);
        self::assertStringContainsString("\$this->assertTransferTableColumns('online_daily_data'", $source);
        self::assertStringContainsString('transfer_source_read_failed:daily_reports', $source);
        self::assertStringContainsString('transfer_source_read_failed:online_daily_data', $source);
        self::assertStringContainsString('transfer_source_read_failed:hotels', $source);
        self::assertStringContainsString("'source_read_status' => \$this->sourceReadStatus", $source);
    }

    public function testTransferRecordsFollowTheAuthoritativeCurrentHotelTenantAfterMigration(): void
    {
        $this->withTransferTenantDatabase(function (): void {
            $service = new TransferDecisionService();
            self::assertCount(1, $service->records([7], 3, true));
            self::assertSame(91, $service->detail(91, [7], 3, true)['id']);

            Db::name('hotels')->where('id', 7)->update(['tenant_id' => 8]);

            self::assertSame([], $service->records([7], 3, true));
            foreach (['detail'] as $method) {
                try {
                    $service->{$method}(91, [7], 3, true);
                    self::fail($method . ' must reject a record owned by the hotel previous tenant.');
                } catch (RuntimeException) {
                    self::assertTrue(true);
                }
            }
            self::assertNull(Db::name('transfer_records')->where('id', 91)->value('deleted_at'));

            Db::name('transfer_records')->where('id', 91)->update(['tenant_id' => 8]);
            self::assertSame(91, $service->detail(91, [7], 0, false)['id']);
            try {
                $service->archive(91, [7], 0, false);
                self::fail('Retired history cannot be archived by a direct service call.');
            } catch (RuntimeException $exception) {
                self::assertSame('retired_read_only', $exception->getMessage());
                self::assertSame(410, $exception->getCode());
            }
            self::assertNull(Db::name('transfer_records')->where('id', 91)->value('deleted_at'));
        });
    }

    public function testTransferSourceRowsRequireTheAuthoritativeCurrentHotelTenantBeforeAggregation(): void
    {
        $this->withTransferTenantDatabase(function (): void {
            Db::name('hotels')->where('id', 7)->update(['tenant_id' => 8]);
            Db::name('daily_reports')->insertAll([
                ['id' => 1, 'tenant_id' => 7, 'hotel_id' => 7, 'report_date' => '2026-08-13', 'revenue' => 9000],
                ['id' => 2, 'tenant_id' => 8, 'hotel_id' => 7, 'report_date' => '2026-08-13', 'revenue' => 300],
            ]);
            Db::name('online_daily_data')->insertAll([
                ['id' => 1, 'tenant_id' => 7, 'system_hotel_id' => 7, 'data_date' => '2026-08-13', 'amount' => 8000],
                ['id' => 2, 'tenant_id' => 8, 'system_hotel_id' => 7, 'data_date' => '2026-08-13', 'amount' => 200],
            ]);
            $service = new TransferDecisionService();

            $dailyRows = $this->invokeNonPublic(
                $service,
                'dailyReportRows',
                [[7], '2026-08-01', '2026-08-13']
            );
            $onlineRows = $this->invokeNonPublic(
                $service,
                'onlineRows',
                [[7], '2026-08-01', '2026-08-13']
            );

            self::assertSame([2], array_column($dailyRows, 'id'));
            self::assertSame([2], array_column($onlineRows, 'id'));
        });
    }

    public function testTransferTenantColumnsAreMandatorySchemaInsteadOfAnUnscopedFallback(): void
    {
        $this->withTransferTenantDatabase(function (): void {
            Db::execute('ALTER TABLE daily_reports RENAME TO daily_reports_with_tenant');
            Db::execute('CREATE TABLE daily_reports (id INTEGER PRIMARY KEY, hotel_id INTEGER, report_date TEXT)');

            try {
                $this->invokeNonPublic(
                    new TransferDecisionService(),
                    'dailyReportRows',
                    [[7], '2026-08-01', '2026-08-13']
                );
                self::fail('Missing daily_reports.tenant_id must require a schema migration.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('Database schema upgrade required', $exception->getMessage());
                self::assertStringContainsString('tenant_id', $exception->getMessage());
            }

            Db::execute('DROP TABLE daily_reports');
            Db::execute('ALTER TABLE daily_reports_with_tenant RENAME TO daily_reports');
            Db::execute('ALTER TABLE online_daily_data RENAME TO online_daily_data_with_tenant');
            Db::execute('CREATE TABLE online_daily_data (id INTEGER PRIMARY KEY, system_hotel_id INTEGER, data_date TEXT)');
            try {
                $this->invokeNonPublic(
                    new TransferDecisionService(),
                    'onlineRows',
                    [[7], '2026-08-01', '2026-08-13']
                );
                self::fail('Missing online_daily_data.tenant_id must require a schema migration.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('Database schema upgrade required', $exception->getMessage());
                self::assertStringContainsString('tenant_id', $exception->getMessage());
            }

            Db::execute('ALTER TABLE transfer_records RENAME TO transfer_records_with_tenant');
            Db::execute('CREATE TABLE transfer_records (id INTEGER PRIMARY KEY, hotel_id INTEGER)');
            foreach (['records', 'detail'] as $method) {
                try {
                    $service = new TransferDecisionService();
                    $method === 'records'
                        ? $service->records([7], 3, true)
                        : $service->{$method}(91, [7], 3, true);
                    self::fail('Missing transfer_records.tenant_id must fail: ' . $method);
                } catch (RuntimeException $exception) {
                    self::assertSame('transfer_records_migration_required', $exception->getMessage(), $method);
                }
            }

            Db::execute('DROP TABLE transfer_records');
            Db::execute('ALTER TABLE transfer_records_with_tenant RENAME TO transfer_records');
            Db::execute('ALTER TABLE hotels RENAME TO hotels_with_tenant');
            Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, name TEXT)');
            foreach (['records', 'detail'] as $method) {
                try {
                    $service = new TransferDecisionService();
                    $method === 'records'
                        ? $service->records([7], 3, true)
                        : $service->{$method}(91, [7], 3, true);
                    self::fail('Missing hotels.tenant_id must fail: ' . $method);
                } catch (RuntimeException $exception) {
                    self::assertSame('transfer_records_migration_required', $exception->getMessage(), $method);
                }
            }
        });
    }

    public function testTransferRecordQueryErrorsUseTheSameMigrationRequiredStatusAcrossAllEntryPoints(): void
    {
        $this->withTransferTenantDatabase(function (): void {
            foreach (['records', 'detail'] as $method) {
                $service = new TransferDecisionService();
                $service->ensureTable();
                Db::execute('ALTER TABLE transfer_records RENAME TO transfer_records_query_unavailable');
                try {
                    $method === 'records'
                        ? $service->records([7], 3, true)
                        : $service->{$method}(91, [7], 3, true);
                    self::fail('Query failure must be normalized: ' . $method);
                } catch (RuntimeException $exception) {
                    self::assertSame('transfer_records_migration_required', $exception->getMessage(), $method);
                } finally {
                    Db::execute('ALTER TABLE transfer_records_query_unavailable RENAME TO transfer_records');
                }
            }
        });
    }

    public function testAnnualBenchmarkScalesRevenueAndOrdersToThirtyDays(): void
    {
        $benchmark = $this->invokeNonPublic(new TransferDecisionService(), 'annualThirtyDayBenchmark', [[
            'actual_days' => 60,
            'revenue' => 600000,
            'orders' => 120,
            'adr' => 300,
            'occupancy_rate' => 75,
        ]]);

        self::assertSame(300000.0, $benchmark['revenue']);
        self::assertSame(60, $benchmark['orders']);
        self::assertSame(300.0, $benchmark['adr']);
        self::assertSame(75.0, $benchmark['occupancy_rate']);
    }

    public function testOtaChannelRevenueIsNotPromotedToWholeHotelRevenue(): void
    {
        $verifiedRow = $this->verifiedOtaRow([
            'amount' => 30000,
            'book_order_num' => 20,
            'quantity' => 25,
        ]);
        $verifiedRaw = json_decode((string)$verifiedRow['raw_data'], true);
        $fieldStatus = OnlineDataFieldFactService::buildStatus($verifiedRow, is_array($verifiedRaw) ? $verifiedRaw : []);
        self::assertSame('ready', $fieldStatus['status'], json_encode($fieldStatus, JSON_UNESCAPED_UNICODE));
        $metrics = $this->invokeNonPublic(new TransferDecisionService(), 'aggregateTransferMetrics', [
            [],
            [
                $verifiedRow,
                [
                    'system_hotel_id' => 7,
                    'platform' => 'ctrip',
                    'source' => 'ctrip',
                    'data_date' => '2026-07-15',
                    'amount' => 999999,
                    'book_order_num' => 999,
                    'quantity' => 999,
                ],
            ],
            $this->otaScope(),
        ]);

        self::assertSame(0.0, $metrics['revenue']);
        self::assertSame(30000.0, $metrics['ota_channel_revenue'], json_encode($metrics['truth_context'], JSON_UNESCAPED_UNICODE));
        self::assertSame(20, $metrics['ota_channel_orders']);
        self::assertSame(0.0, $metrics['room_nights']);
        self::assertSame(25.0, $metrics['ota_channel_room_nights']);
        self::assertSame('partial', $metrics['truth_context']['status']);
        self::assertSame('ota_channel', $metrics['truth_context']['metric_scope']);
        self::assertSame(1, $metrics['truth_context']['included_verified_count']);
        self::assertSame(1, $metrics['truth_context']['excluded_untrusted_count']);
        self::assertSame(1, $metrics['truth_context']['status_counts']['unverified']);
        self::assertStringContainsString('不得互相替代', $metrics['scope_note']);
    }

    public function testVerifiedZeroOtaFactsRemainObservedInsteadOfBecomingMissing(): void
    {
        $metrics = $this->invokeNonPublic(new TransferDecisionService(), 'aggregateTransferMetrics', [
            [],
            [$this->verifiedOtaRow([
                'amount' => 0,
                'book_order_num' => 0,
                'quantity' => 0,
            ])],
            $this->otaScope(),
        ]);

        self::assertSame(0.0, $metrics['ota_channel_revenue']);
        self::assertSame(0, $metrics['ota_channel_orders']);
        self::assertSame(0.0, $metrics['ota_channel_room_nights']);
        self::assertTrue($metrics['ota_channel_revenue_observed']);
        self::assertTrue($metrics['ota_channel_orders_observed']);
        self::assertTrue($metrics['ota_channel_room_nights_observed']);
        self::assertSame(1, $metrics['ota_channel_days']);
        self::assertSame('verified', $metrics['truth_context']['status']);
        self::assertSame(1, $metrics['truth_context']['included_verified_count']);
    }

    public function testOnlyVerifiedOtaRowsContributeAcrossFourTruthStates(): void
    {
        $verified = $this->verifiedOtaRow([
            'id' => 1,
            'amount' => 100,
            'book_order_num' => 2,
            'quantity' => 3,
        ]);
        $partial = $this->verifiedOtaRow([
            'id' => 2,
            'amount' => 200,
            'book_order_num' => 20,
            'quantity' => 30,
            'validation_status' => 'partial',
        ]);
        $manual = $this->verifiedOtaRow([
            'id' => 3,
            'amount' => 300,
            'book_order_num' => 30,
            'quantity' => 40,
            'ingestion_method' => 'manual',
        ]);
        $legacy = $this->verifiedOtaRow([
            'id' => 4,
            'amount' => 400,
            'book_order_num' => 40,
            'quantity' => 50,
            'ingestion_method' => 'legacy',
        ]);
        $failed = $this->verifiedOtaRow([
            'id' => 5,
            'amount' => 500,
            'book_order_num' => 50,
            'quantity' => 60,
            'validation_status' => 'failed',
        ]);

        $metrics = $this->invokeNonPublic(new TransferDecisionService(), 'aggregateTransferMetrics', [
            [],
            [$verified, $partial, $manual, $legacy, $failed],
            $this->otaScope(),
        ]);

        self::assertSame(100.0, $metrics['ota_channel_revenue']);
        self::assertSame(2, $metrics['ota_channel_orders']);
        self::assertSame(3.0, $metrics['ota_channel_room_nights']);
        self::assertSame(1, $metrics['truth_context']['included_verified_count']);
        self::assertSame(4, $metrics['truth_context']['excluded_untrusted_count']);
        self::assertSame(1, $metrics['truth_context']['status_counts']['verified']);
        self::assertSame(1, $metrics['truth_context']['status_counts']['partial']);
        self::assertSame(2, $metrics['truth_context']['status_counts']['unverified']);
        self::assertSame(1, $metrics['truth_context']['status_counts']['collection_failed']);
        self::assertSame('partial', $metrics['truth_context']['status']);
        self::assertNotEmpty($metrics['truth_context']['failure_reasons']);
    }

    public function testVerifiedRowsOutsideHotelDateOrOtaPlatformScopeAreExcluded(): void
    {
        $wrongHotel = $this->verifiedOtaRow(['id' => 11, 'system_hotel_id' => 8, 'amount' => 100]);
        $wrongDate = $this->verifiedOtaRow(['id' => 12, 'data_date' => '2026-07-14', 'amount' => 200]);
        $wrongPlatform = $this->verifiedOtaRow([
            'id' => 13,
            'platform' => 'internal',
            'source' => 'internal',
            'amount' => 300,
        ]);

        $metrics = $this->invokeNonPublic(new TransferDecisionService(), 'aggregateTransferMetrics', [
            [],
            [$wrongHotel, $wrongDate, $wrongPlatform],
            $this->otaScope(),
        ]);

        self::assertSame(0.0, $metrics['ota_channel_revenue']);
        self::assertSame(0, $metrics['truth_context']['included_verified_count']);
        self::assertSame(3, $metrics['truth_context']['excluded_untrusted_count']);
        self::assertSame(1, $metrics['truth_context']['scope_exclusion_counts']['hotel_scope_mismatch']);
        self::assertSame(1, $metrics['truth_context']['scope_exclusion_counts']['date_scope_mismatch']);
        self::assertSame(1, $metrics['truth_context']['scope_exclusion_counts']['unsupported_ota_platform']);
        self::assertSame('unverified', $metrics['truth_context']['status']);
    }

    public function testDecisionReadinessKeepsManualPricingAsInputOnly(): void
    {
        $service = $this->fallbackService();
        $pricing = RetiredHistoryFixture::result('transfer', 'pricing');

        $readiness = $service->buildDecisionReadiness('pricing', $this->pricingInput(), $pricing, [], 7);

        self::assertSame('manual_input_only', $readiness['stage']);
        self::assertFalse($readiness['decision_ready']);
        self::assertSame('manual_input_only', $readiness['source_scope']);
        self::assertContains('source_snapshot', array_column($readiness['missing_evidence'], 'code'));
    }

    public function testDecisionReadinessRequiresDiligenceEvidenceBeforeDecisionReady(): void
    {
        $service = $this->fallbackService();
        $pricingInput = $this->pricingInput();
        $timingInput = [
            'current_revenue' => 120,
            'previous_revenue' => 100,
            'current_orders' => 620,
            'previous_orders' => 520,
            'current_adr' => 320,
            'previous_adr' => 300,
            'current_occupancy_rate' => 82,
            'previous_occupancy_rate' => 76,
            'rating' => 4.8,
            'exposure' => 12000,
            'visitors' => 1800,
            'conversion_rate' => 6.5,
            'order_count' => 620,
            'room_nights' => 980,
        ];
        $pricing = RetiredHistoryFixture::result('transfer', 'pricing');
        $timing = RetiredHistoryFixture::result('transfer', 'timing');
        $dashboard = RetiredHistoryFixture::result('transfer', 'dashboard');
        $snapshot = [
            'hotel_id' => 7,
            'source_date' => '2026-06-14',
            'current' => ['actual_days' => 30, 'has_data_anomaly' => false],
            'source_counts' => ['daily_reports' => 30, 'online_daily_data' => 30],
            'data_status' => '已接入真实数据',
        ];
        $dashboardInput = [
            'pricing' => $pricing,
            'timing' => $timing,
            'pricing_input' => $pricingInput,
            'timing_input' => $timingInput,
        ];

        $readiness = $service->buildDecisionReadiness('dashboard', $dashboardInput, $dashboard, $snapshot, 7);

        self::assertSame('diligence_required', $readiness['stage']);
        self::assertFalse($readiness['decision_ready']);
        self::assertContains('diligence_document_evidence', array_column($readiness['missing_evidence'], 'code'));

        $approved = $service->buildDecisionReadiness('dashboard', array_merge($dashboardInput, [
            'diligence_evidence' => ['lease_contract' => 'checked'],
            'review_status' => 'approved',
            'operation_execution_intent_id' => 88,
        ]), $dashboard, $snapshot, 7);

        self::assertSame('approved_pending_tracking', $approved['stage']);
        self::assertFalse($approved['decision_ready']);
        self::assertContains('post_decision_tracking', array_column($approved['missing_evidence'], 'code'));
    }

    public function testBuildExecutionIntentInputRequiresTransferRecordHotel(): void
    {
        $service = $this->fallbackService();

        $this->expectException(\InvalidArgumentException::class);
        $service->buildExecutionIntentInput(['id' => 12, 'hotel_id' => 0]);
    }

    public function testBuildExecutionIntentInputUsesTransferDecisionScope(): void
    {
        $service = $this->fallbackService();
        $pricingInput = $this->pricingInput();
        $pricing = RetiredHistoryFixture::result('transfer', 'pricing');
        $timing = RetiredHistoryFixture::result('transfer', 'timing');
        $dashboard = RetiredHistoryFixture::result('transfer', 'dashboard');
        $snapshot = [
            'hotel_id' => 7,
            'hotel_name' => 'Hotel A',
            'source_date' => '2026-06-14',
            'current' => ['actual_days' => 30, 'has_data_anomaly' => false],
            'source_counts' => ['daily_reports' => 30, 'online_daily_data' => 30],
        ];
        $input = [
            'pricing' => $pricing,
            'timing' => $timing,
            'pricing_input' => $pricingInput,
            'diligence_evidence' => ['lease_contract' => 'checked'],
            'review_status' => 'approved',
        ];
        $readiness = $service->buildDecisionReadiness('dashboard', $input, $dashboard, $snapshot, 7);

        $intentInput = $service->buildExecutionIntentInput([
            'id' => 12,
            'record_type' => 'dashboard',
            'hotel_id' => 7,
            'hotel_name' => 'Hotel A',
            'source_date' => '2026-06-14',
            'decision' => (string)($dashboard['suggested_action'] ?? ''),
            'risk_level' => 'medium',
            'input' => $input,
            'result' => $dashboard,
            'snapshot' => $snapshot,
            'decision_readiness' => $readiness,
        ], ['date_start' => '2026-06-14']);

        self::assertSame('transfer_decision', $intentInput['source_module']);
        self::assertSame(12, $intentInput['source_record_id']);
        self::assertSame(7, $intentInput['hotel_id']);
        self::assertSame('investment', $intentInput['platform']);
        self::assertSame('investment', $intentInput['object_type']);
        self::assertSame('transfer_decision_closure', $intentInput['target_value']['target_metric']);
        self::assertSame($readiness['stage'], $intentInput['evidence']['readiness_stage']);
        self::assertSame('medium', $intentInput['risk_level']);
    }

    public function testTransferBusinessDatesUseStrictCalendarRoundTripAndShanghaiDefault(): void
    {
        $service = $this->fallbackService();
        $normalize = new \ReflectionMethod(TransferDecisionService::class, 'normalizeTransferBusinessDate');
        foreach (['2026-02-30', 'tomorrow', '', null, ' 2026-08-13 '] as $invalid) {
            try {
                $normalize->invoke($service, $invalid);
                self::fail('Invalid transfer business date must fail: ' . json_encode($invalid));
            } catch (\ReflectionException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                self::assertInstanceOf(InvalidArgumentException::class, $exception);
            }
        }
        self::assertSame('2026-08-13', $normalize->invoke($service, '2026-08-13'));

        $today = new \ReflectionMethod(TransferDecisionService::class, 'transferBusinessToday');
        self::assertSame(
            '2026-08-13',
            $today->invoke($service, new \DateTimeImmutable('2026-08-12 16:30:00', new \DateTimeZone('UTC')))
        );
        $window = new \ReflectionMethod(TransferDecisionService::class, 'transferBusinessWindow');
        self::assertSame(
            ['start' => '2026-07-15', 'end' => '2026-08-13'],
            $window->invoke($service, '2026-08-13', 30)
        );
        self::assertSame(
            ['start' => '2025-08-14', 'end' => '2026-08-13'],
            $window->invoke($service, '2026-08-13', 365)
        );
    }

    public function testTransferExecutionIntentDatesRejectExplicitInvalidValuesWithoutTodayFallback(): void
    {
        $service = $this->fallbackService();
        $record = [
            'id' => 99,
            'hotel_id' => 7,
            'record_type' => 'pricing',
            'hotel_name' => 'Hotel 7',
            'source_date' => '2026-08-13',
            'input' => [],
            'result' => [],
            'snapshot' => [],
        ];
        foreach ([
            ['date_start' => '2026-02-30'],
            ['date_start' => 'tomorrow'],
            ['date_start' => ''],
            ['date_start' => null],
            ['date_start' => ' 2026-08-13 '],
            ['date_start' => '2026-08-13', 'date_end' => '2026-02-30'],
            ['date_start' => '2026-08-13', 'date_end' => ''],
            ['date_start' => '2026-08-13', 'date_end' => null],
        ] as $overrides) {
            try {
                $service->buildExecutionIntentInput($record, $overrides);
                self::fail('Invalid explicit transfer intent date must fail: ' . json_encode($overrides));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $defaulted = $service->buildExecutionIntentInput($record);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/D', $defaulted['date_start']);
        self::assertSame($defaulted['date_start'], $defaulted['date_end']);
    }

    public function testExecutionTrackingLocksHotelThenCurrentRecordInsideOneTransaction(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/service/TransferDecisionService.php');

        self::assertMatchesRegularExpression(
            '/public function lockExecutionTrackingSource\b[\s\S]*?Db::transaction\(function \(\)[\s\S]*?lockedHotelIdentity\([\s\S]*?currentTenantTransferRecordQuery\([\s\S]*?lock\(true\)/',
            $source
        );
        self::assertMatchesRegularExpression(
            '/public function attachExecutionTracking\b[\s\S]*?Db::transaction\(function \(\)[\s\S]*?lockExecutionTrackingSource\([\s\S]*?execution_tracking[\s\S]*?->update\(/',
            $source
        );
    }

    public function testExecutionTrackingIsAppendOnlyIdempotentAndRejectsTenantMigrationWithoutWriting(): void
    {
        $this->withTransferTenantDatabase(function (): void {
            $service = new TransferDecisionService();

            $service->attachExecutionTracking(91, [7], 3, true, [
                'execution_intent_id' => 101,
                'hotel_id' => 7,
                'status' => 'pending_approval',
            ]);
            $stored = json_decode((string)Db::name('transfer_records')->where('id', 91)->value('result_json'), true);
            self::assertSame([101], array_column($stored['execution_tracking'], 'execution_intent_id'));

            $service->attachExecutionTracking(91, [7], 3, true, [
                'execution_intent_id' => 101,
                'hotel_id' => 7,
                'status' => 'approved',
            ]);
            $stored = json_decode((string)Db::name('transfer_records')->where('id', 91)->value('result_json'), true);
            self::assertSame([101], array_column($stored['execution_tracking'], 'execution_intent_id'));

            $service->attachExecutionTracking(91, [7], 3, true, [
                'execution_intent_id' => 102,
                'hotel_id' => 7,
                'status' => 'pending_approval',
            ]);
            $stored = json_decode((string)Db::name('transfer_records')->where('id', 91)->value('result_json'), true);
            self::assertSame([101, 102], array_column($stored['execution_tracking'], 'execution_intent_id'));

            $beforeMigrationAttempt = (string)Db::name('transfer_records')->where('id', 91)->value('result_json');
            Db::name('hotels')->where('id', 7)->update(['tenant_id' => 8]);
            try {
                $service->attachExecutionTracking(91, [7], 3, true, [
                    'execution_intent_id' => 103,
                    'hotel_id' => 7,
                    'status' => 'pending_approval',
                ]);
                self::fail('A request based on the hotel previous tenant must not attach tracking.');
            } catch (RuntimeException $exception) {
                self::assertSame(
                    'Transfer record does not exist or is outside current tenant scope',
                    $exception->getMessage()
                );
            }
            self::assertSame(
                $beforeMigrationAttempt,
                (string)Db::name('transfer_records')->where('id', 91)->value('result_json')
            );
        });
    }

    private function verifiedOtaRow(array $overrides = []): array
    {
        $sourceUrlHash = str_repeat('d', 64);
        $row = array_merge([
            'id' => 1,
            'system_hotel_id' => 7,
            'hotel_id' => 'ctrip-7001',
            'hotel_name' => 'Hotel A',
            'platform' => 'ctrip',
            'source' => 'ctrip',
            'data_type' => 'order',
            'data_date' => '2026-07-15',
            'amount' => 30000,
            'book_order_num' => 20,
            'quantity' => 25,
            'ingestion_method' => 'browser_profile',
            'source_trace_id' => 'trace-safe-1',
            'source_url_hash' => $sourceUrlHash,
            'snapshot_time' => '2026-07-15 09:00:00',
            'validation_status' => 'normal',
            'readback_verified' => 1,
            'create_time' => '2026-07-15 09:01:00',
            'update_time' => '2026-07-15 09:01:00',
            'raw_data' => '{}',
        ], $overrides);

        $raw = is_array($row['raw_data'])
            ? $row['raw_data']
            : json_decode((string)$row['raw_data'], true);
        $raw = is_array($raw) ? $raw : [];
        $raw['source_trace_id'] = (string)$row['source_trace_id'];
        $raw['source_url_hash'] = $sourceUrlHash;
        $raw['field_facts'] = [];
        foreach ([
            'order_amount' => 'amount',
            'order_count' => 'book_order_num',
            'room_nights' => 'quantity',
        ] as $metricKey => $storageField) {
            $raw['field_facts'][] = [
                'metric_key' => $metricKey,
                'source_path' => '$.payload.' . $metricKey,
                'storage_field' => 'online_daily_data.' . $storageField,
                'status' => 'captured',
                'stored_value_present' => true,
                'capture_evidence' => [
                    'source_trace_id' => (string)$row['source_trace_id'],
                    'source_url_hash' => $sourceUrlHash,
                ],
            ];
        }
        $row['raw_data'] = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return $row;
    }

    private function otaScope(array $overrides = []): array
    {
        return array_merge([
            'target_hotel_id' => 7,
            'start_date' => '2026-07-15',
            'end_date' => '2026-07-15',
        ], $overrides);
    }

    private function pricingInput(): array
    {
        return [
            'hotel_name' => '虹桥样板店',
            'location' => '上海虹桥',
            'room_count' => 80,
            'monthly_revenue' => 30,
            'monthly_rent' => 8,
            'labor_cost' => 5,
            'utility_cost' => 1,
            'ota_commission' => 2,
            'other_fixed_cost' => 1,
            'decoration_investment' => 200,
            'remaining_lease_months' => 72,
            'expected_transfer_price' => 180,
            'occupancy_rate' => 82,
            'adr' => 320,
            'rating' => 4.8,
            'order_count' => 900,
            'licenses_complete' => true,
        ];
    }

    private function fallbackService(): TransferDecisionService
    {
        return new TransferDecisionService(new class extends LlmClient {
            public function createJsonResponse(array $messages, array $schema, string $modelKey = 'deepseek_v4_default'): array
            {
                throw new RuntimeException('missing model config');
            }
        });
    }

    /** @param callable():void $callback */
    private function withTransferTenantDatabase(callable $callback): void
    {
        $app = new App();
        $app->initialize();
        restore_error_handler();
        restore_exception_handler();
        $originalConfig = Config::get('database');
        $sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'transfer_tenant_contract_' . getmypid() . '_' . bin2hex(random_bytes(6)) . '.sqlite';
        $config = $originalConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => $sqlitePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        $config['connections']['sqlite_peer'] = [
            'type' => 'sqlite',
            'database' => $sqlitePath,
            'prefix' => '',
            'fields_strict' => false,
            'options' => [\PDO::ATTR_TIMEOUT => 1],
        ];

        try {
            Config::set($config, 'database');
            Db::connect(null, true);
            Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT, address TEXT)');
            Db::execute(<<<'SQL'
CREATE TABLE transfer_records (
    id INTEGER PRIMARY KEY,
    record_type TEXT NOT NULL,
    tenant_id INTEGER NOT NULL,
    hotel_id INTEGER NOT NULL,
    hotel_name TEXT,
    source_date TEXT,
    input_json TEXT,
    result_json TEXT,
    snapshot_json TEXT,
    decision TEXT,
    risk_level TEXT,
    created_by INTEGER,
    created_at TEXT,
    updated_at TEXT,
    deleted_at TEXT
)
SQL);
            Db::execute('CREATE TABLE daily_reports (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, report_date TEXT NOT NULL, report_data TEXT, revenue REAL)');
            Db::execute('CREATE TABLE online_daily_data (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL, data_date TEXT NOT NULL, amount REAL)');
            Db::name('hotels')->insert(['id' => 7, 'tenant_id' => 7, 'name' => 'Hotel 7', 'address' => 'Shanghai']);
            Db::name('transfer_records')->insert([
                'id' => 91,
                'record_type' => 'pricing',
                'tenant_id' => 7,
                'hotel_id' => 7,
                'hotel_name' => 'Hotel 7',
                'source_date' => '2026-08-13',
                'input_json' => '{}',
                'result_json' => '{}',
                'snapshot_json' => '{}',
                'decision' => 'review',
                'risk_level' => 'medium',
                'created_by' => 3,
                'created_at' => '2026-08-13 09:00:00',
                'updated_at' => '2026-08-13 09:00:00',
                'deleted_at' => null,
            ]);
            $callback();
        } finally {
            Db::connect('sqlite_peer')->close();
            Db::connect()->close();
            Config::set($originalConfig, 'database');
            Db::connect(null, true);
            if (is_file($sqlitePath)) {
                unlink($sqlitePath);
            }
        }
    }
}
