<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperationManagementService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OperationManagementBaselineScopeTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App(dirname(__DIR__));
        $app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'operation_baseline_scope_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.sqlite';

        $config = self::$originalDatabaseConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$sqlitePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($config, 'database');
    }

    public static function tearDownAfterClass(): void
    {
        try {
            Db::connect('sqlite')->close();
        } catch (\Throwable) {
        }
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        if (self::$sqlitePath !== '' && is_file(self::$sqlitePath)) {
            @unlink(self::$sqlitePath);
        }
    }

    protected function setUp(): void
    {
        try {
            Db::connect('sqlite')->close();
        } catch (\Throwable) {
        }
        if (is_file(self::$sqlitePath)) {
            @unlink(self::$sqlitePath);
        }
        Db::connect(null, true);

        Db::execute('CREATE TABLE daily_reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER,
            hotel_id INTEGER NOT NULL,
            report_date DATE NOT NULL,
            report_data TEXT,
            occupancy_rate DECIMAL(8,2),
            room_count INTEGER,
            guest_count INTEGER,
            revenue DECIMAL(12,2),
            expenses DECIMAL(12,2),
            notes TEXT,
            submitter_id INTEGER,
            status TINYINT DEFAULT 1,
            create_time DATETIME,
            update_time DATETIME
        )');
        Db::execute('CREATE TABLE hotels (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL
        )');
        Db::name('hotels')->insert(['id' => 7, 'tenant_id' => 1]);
        Db::execute('CREATE TABLE online_daily_data (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER,
            system_hotel_id INTEGER NOT NULL,
            data_source_id INTEGER,
            hotel_id INTEGER,
            data_date DATE NOT NULL,
            source VARCHAR(50),
            platform VARCHAR(30),
            compare_type VARCHAR(30),
            data_type VARCHAR(50),
            dimension VARCHAR(255),
            validation_status VARCHAR(30),
            readback_verified INTEGER,
            ingestion_method VARCHAR(50),
            data_period VARCHAR(50),
            is_final INTEGER,
            snapshot_time DATETIME,
            collected_at DATETIME,
            received_at DATETIME,
            raw_data TEXT,
            amount DECIMAL(12,2),
            quantity INTEGER,
            book_order_num INTEGER,
            create_time DATETIME,
            update_time DATETIME
        )');
        Db::execute('CREATE TABLE operation_action_tracks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER,
            hotel_id INTEGER NOT NULL,
            action_type TEXT,
            action_title TEXT,
            start_date DATE,
            end_date DATE,
            target_metric TEXT,
            target_change_rate DECIMAL(8,2),
            before_data_json TEXT,
            after_data_json TEXT,
            result_status TEXT,
            result_summary TEXT,
            remark TEXT,
            status TEXT,
            created_at DATETIME,
            updated_at DATETIME,
            deleted_at DATETIME
        )');
        Db::execute('CREATE TABLE operation_alerts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER,
            hotel_id INTEGER NOT NULL,
            alert_type TEXT,
            monitor_dedupe_key TEXT UNIQUE,
            level TEXT,
            title TEXT,
            message TEXT,
            source TEXT,
            status TEXT,
            related_date DATE,
            raw_data TEXT,
            created_at DATETIME,
            updated_at DATETIME,
            deleted_at DATETIME
        )');
        Db::execute('CREATE TABLE price_suggestions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER,
            hotel_id INTEGER NOT NULL,
            suggestion_date DATE,
            status INTEGER
        )');
        Db::execute('CREATE TABLE operation_execution_intents (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, idempotency_key TEXT UNIQUE,
            source_module TEXT, source_record_id INTEGER, hotel_id INTEGER, platform TEXT,
            object_type TEXT, action_type TEXT, date_start TEXT, date_end TEXT,
            current_value_json TEXT, target_value_json TEXT, evidence_json TEXT,
            expected_metric TEXT, expected_delta REAL, risk_level TEXT, blocked_reason TEXT,
            status TEXT, created_by INTEGER, approved_by INTEGER, approved_at TEXT,
            review_remark TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT
        )');
        Db::execute('CREATE TABLE operation_execution_tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, intent_id INTEGER,
            hotel_id INTEGER, status TEXT, deleted_at TEXT
        )');
        Db::execute('CREATE TABLE operation_execution_evidence (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, task_id INTEGER,
            deleted_at TEXT
        )');
    }

    public function testAlternatingWholeHotelAndOtaDaysCannotProduceSimulation(): void
    {
        $wholeHotelDate = date('Y-m-d', strtotime('-2 days'));
        $otaDate = date('Y-m-d', strtotime('-1 day'));
        $this->insertWholeHotelDay($wholeHotelDate, 1200, 6, 4);
        $this->insertOtaDay($otaDate, 900, 5, 3);

        $result = (new OperationManagementService())->strategySimulation(
            [7],
            7,
            ['strategy_type' => 'price_adjust', 'adjust_amount' => -5]
        );

        self::assertFalse($result['simulated']);
        self::assertSame('insufficient_data', $result['status']);
        self::assertSame('partial', $result['baseline']['data_status']);
        self::assertEqualsCanonicalizing(
            ['whole_hotel_daily_report', 'ota_channel'],
            $result['baseline']['source_scopes']
        );
        self::assertContains('baseline_scope_drift', array_column($result['baseline']['data_gaps'], 'code'));
        self::assertNull($result['baseline']['avg_orders']);
        self::assertNull($result['baseline']['avg_revenue']);
        self::assertNull($result['baseline']['avg_room_nights']);
        self::assertNull($result['baseline']['avg_conversion']);
        self::assertNull($result['rule_scenario']['avg_orders']);
        self::assertNull($result['forecast']['avg_revenue']);
    }

    public function testSingleWholeHotelScopeKeepsExistingBaselineBehavior(): void
    {
        $this->insertWholeHotelDay(date('Y-m-d', strtotime('-2 days')), 1200, 6, 4);
        $this->insertWholeHotelDay(date('Y-m-d', strtotime('-1 day')), 1800, 8, 6);

        $result = (new OperationManagementService())->strategySimulation(
            [7],
            7,
            ['strategy_type' => 'price_adjust', 'adjust_amount' => -5]
        );

        self::assertFalse($result['simulated']);
        self::assertSame('partial', $result['baseline']['data_status']);
        self::assertSame(['whole_hotel_daily_report'], $result['baseline']['source_scopes']);
        self::assertSame(7.0, $result['baseline']['avg_orders']);
        self::assertSame(1500.0, $result['baseline']['avg_revenue']);
        self::assertSame(5.0, $result['baseline']['avg_room_nights']);
        self::assertContains('insufficient_baseline_days', array_column($result['baseline']['data_gaps'], 'code'));
        self::assertNotContains('baseline_scope_drift', array_column($result['baseline']['data_gaps'], 'code'));
    }

    public function testTwentyNineObservedDaysCannotProduceThirtyDaySimulation(): void
    {
        for ($offset = 29; $offset >= 1; --$offset) {
            $this->insertWholeHotelDay(date('Y-m-d', strtotime('-' . $offset . ' days')), 1200, 6, 4);
        }

        $result = (new OperationManagementService())->strategySimulation(
            [7],
            7,
            ['strategy_type' => 'price_adjust', 'adjust_amount' => -5]
        );

        self::assertFalse($result['simulated']);
        self::assertSame('insufficient_data', $result['status']);
        self::assertSame(30, $result['baseline']['days']);
        self::assertSame(29, $result['baseline']['actual_days']);
        self::assertSame('partial', $result['baseline']['data_status']);
        self::assertContains('insufficient_baseline_days', array_column($result['baseline']['data_gaps'], 'code'));
        self::assertSame(1200.0, $result['baseline']['avg_revenue']);
        self::assertNull($result['rule_scenario']['avg_revenue']);
    }

    public function testOneObservedDayCannotProduceThirtyDaySimulation(): void
    {
        $this->insertWholeHotelDay(date('Y-m-d', strtotime('-1 day')), 1350, 7, 5);

        $result = (new OperationManagementService())->strategySimulation(
            [7],
            7,
            ['strategy_type' => 'price_adjust', 'adjust_amount' => -5]
        );

        self::assertFalse($result['simulated']);
        self::assertSame('insufficient_data', $result['status']);
        self::assertSame(1, $result['baseline']['actual_days']);
        self::assertSame('partial', $result['baseline']['data_status']);
        self::assertContains('insufficient_baseline_days', array_column($result['baseline']['data_gaps'], 'code'));
        self::assertContains(
            'Baseline evidence covers 1/30 requested days',
            array_column($result['baseline']['data_gaps'], 'message')
        );
        self::assertSame(1350.0, $result['baseline']['avg_revenue']);
        self::assertNull($result['rule_scenario']['avg_revenue']);
    }

    public function testThirtySubmittedProductionSchemaRowsCanProduceThirtyDaySimulation(): void
    {
        for ($offset = 30; $offset >= 1; --$offset) {
            $this->insertWholeHotelDay(date('Y-m-d', strtotime('-' . $offset . ' days')), 1200, 6, 4);
        }

        $result = (new OperationManagementService())->strategySimulation(
            [7],
            7,
            ['strategy_type' => 'price_adjust', 'adjust_amount' => -5]
        );

        self::assertTrue($result['simulated']);
        self::assertSame('rule_scenario', $result['status']);
        self::assertSame(30, $result['baseline']['days']);
        self::assertSame(30, $result['baseline']['actual_days']);
        self::assertSame('ok', $result['baseline']['data_status']);
        self::assertNotContains('insufficient_baseline_days', array_column($result['baseline']['data_gaps'], 'code'));
    }

    public function testDailyReportsRemainBoundToTheCurrentPersistedHotelTenantAfterReassignment(): void
    {
        $timezone = new \DateTimeZone('Asia/Shanghai');
        $today = new \DateTimeImmutable('today', $timezone);
        for ($offset = 30; $offset >= 1; --$offset) {
            $this->insertWholeHotelDay(
                $today->modify('-' . $offset . ' days')->format('Y-m-d'),
                1100,
                5,
                4,
                2,
                1
            );
        }
        $dashboardDate = $today->modify('-1 day')->format('Y-m-d');

        Db::name('hotels')->where('id', 7)->update(['tenant_id' => 2]);

        $service = new OperationManagementService();
        $dashboardBeforeNewTenantReport = $service->fullData([7], 7, $dashboardDate);
        $strategyBeforeNewTenantReports = $service->strategySimulation(
            [7],
            7,
            ['strategy_type' => 'price_adjust', 'adjust_amount' => -5]
        );
        $directMethod = new \ReflectionMethod($service, 'buildSummaryFromRows');
        $directMethod->setAccessible(true);
        $oldTenantDirectSummary = $directMethod->invoke($service, [[
            'id' => 999,
            'tenant_id' => 1,
            'hotel_id' => 7,
            'report_date' => $dashboardDate,
            'status' => 2,
            'report_data' => json_encode([
                'xb_revenue' => 1100,
                'xb_rooms' => 4,
                'order_count' => 5,
                'salable_rooms' => 10,
            ], JSON_THROW_ON_ERROR),
        ]], [], [7], 7, $dashboardDate);

        self::assertNull($dashboardBeforeNewTenantReport['summary']['revenue']);
        self::assertNotContains(
            'daily_reports',
            array_column($dashboardBeforeNewTenantReport['summary']['evidence_refs'], 'source')
        );
        self::assertFalse($strategyBeforeNewTenantReports['simulated']);
        self::assertSame(0, $strategyBeforeNewTenantReports['baseline']['actual_days']);
        self::assertNull($oldTenantDirectSummary['revenue']);

        for ($offset = 30; $offset >= 1; --$offset) {
            $this->insertWholeHotelDay(
                $today->modify('-' . $offset . ' days')->format('Y-m-d'),
                2200,
                8,
                6,
                2,
                2
            );
        }
        for ($offset = 31; $offset <= 45; ++$offset) {
            $this->insertWholeHotelDay(
                $today->modify('-' . $offset . ' days')->format('Y-m-d'),
                999999,
                99,
                99,
                2,
                1
            );
        }

        $dashboard = $service->fullData([7], 7, $dashboardDate);
        $strategy = $service->strategySimulation(
            [7],
            7,
            ['strategy_type' => 'price_adjust', 'adjust_amount' => -5]
        );

        self::assertSame(2200.0, $dashboard['summary']['revenue']);
        self::assertSame(['daily_reports'], array_column($dashboard['summary']['evidence_refs'], 'source'));
        self::assertTrue($strategy['simulated']);
        self::assertSame(30, $strategy['baseline']['actual_days']);
        self::assertSame(2200.0, $strategy['baseline']['avg_revenue']);
        self::assertSame('ok', $strategy['baseline']['data_status']);
    }

    public function testOnlineRowsRemainBoundToCurrentHotelTenantAfterReassignment(): void
    {
        $date = '2026-08-12';
        $this->insertOtaDay($date, 900, 5, 3, 'ctrip', 'ctrip', 1);
        Db::name('hotels')->where('id', 7)->update(['tenant_id' => 2]);

        $oldTenant = (new OperationManagementService())->fullData([7], 7, $date);
        self::assertNull($oldTenant['summary']['revenue']);
        self::assertSame([], $oldTenant['summary']['evidence_refs']);
        self::assertNotSame('ok', $oldTenant['ota']['data_status']);

        $this->insertOtaDay($date, 1800, 8, 6, 'ctrip', 'ctrip', 2);
        $currentTenant = (new OperationManagementService())->fullData([7], 7, $date);
        self::assertSame(1800.0, $currentTenant['summary']['revenue']);
        self::assertSame(['ctrip'], array_column($currentTenant['summary']['evidence_refs'], 'source'));
    }

    public function testMissingOnlineTenantSchemaFailsClosedAsMigrationRequired(): void
    {
        Db::execute('DROP TABLE online_daily_data');
        Db::execute('CREATE TABLE online_daily_data (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            system_hotel_id INTEGER NOT NULL,
            data_date DATE NOT NULL
        )');

        $result = (new OperationManagementService())->fullData([7], 7, '2026-08-12');

        self::assertSame('migration_required', $result['summary']['data_status']);
        self::assertContains(
            'operation_online_daily_data_tenant_schema_missing',
            array_column($result['summary']['data_gaps'], 'code')
        );
    }

    public function testActionTracksExcludeAndCannotFinishPreviousTenantRows(): void
    {
        $oldId = $this->insertActionTrack(1, 'old tenant action');
        Db::name('hotels')->where('id', 7)->update(['tenant_id' => 2]);
        $currentId = $this->insertActionTrack(2, 'current tenant action');

        $service = new OperationManagementService();
        $tracking = $service->actionTracking([7], 7);

        self::assertSame([$currentId], array_column($tracking['actions'], 'id'));
        self::assertSame(1, $tracking['effect_validation']['action_counts']['total']);
        self::assertFalse($service->finishAction($oldId, [7]));
        self::assertSame('active', Db::name('operation_action_tracks')->where('id', $oldId)->value('status'));
        self::assertTrue($service->finishAction($currentId, [7]));
        self::assertSame('finished', Db::name('operation_action_tracks')->where('id', $currentId)->value('status'));
    }

    public function testFinishActionPersistsShanghaiTimestampOutsideProcessTimezone(): void
    {
        $originalTimezone = date_default_timezone_get();
        date_default_timezone_set('America/Los_Angeles');
        try {
            $actionId = $this->insertActionTrack(1, 'shanghai timestamp action');
            self::assertTrue((new OperationManagementService())->finishAction($actionId, [7]));
            $updatedAt = (string)Db::name('operation_action_tracks')->where('id', $actionId)->value('updated_at');
            $shanghaiNow = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai'));
            self::assertSame($shanghaiNow->format('Y-m-d'), substr($updatedAt, 0, 10));
            self::assertLessThanOrEqual(
                2,
                abs($shanghaiNow->getTimestamp() - (new \DateTimeImmutable(
                    $updatedAt,
                    new \DateTimeZone('Asia/Shanghai')
                ))->getTimestamp())
            );
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    public function testAlertsAndEffectStatisticsRemainBoundToCurrentHotelTenantAfterTransfer(): void
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
        $oldAlertId = $this->insertOperationAlert(1, 'manual_review', 'old tenant alert', $today, true);
        Db::name('price_suggestions')->insert([
            'tenant_id' => 1, 'hotel_id' => 7, 'suggestion_date' => $today, 'status' => 2,
        ]);
        Db::name('hotels')->where('id', 7)->update(['tenant_id' => 2]);

        $persist = new \ReflectionMethod(OperationManagementService::class, 'persistRuleAlerts');
        $persisted = $persist->invoke(new OperationManagementService(), [[
            'hotel_id' => 7,
            'alert_type' => 'manual_review',
            'level' => 'medium',
            'title' => 'current tenant alert',
            'message' => 'current tenant alert',
            'source' => 'manual',
            'status' => 'unread',
            'related_date' => $today,
            'raw_data' => ['accuracy_review' => ['accurate' => false]],
        ]]);
        self::assertCount(1, $persisted);
        $currentAlertId = (int)$persisted[0]['id'];
        self::assertNotSame($oldAlertId, $currentAlertId);
        self::assertSame('old tenant alert', Db::name('operation_alerts')->where('id', $oldAlertId)->value('title'));
        self::assertSame(1, (int)Db::name('operation_alerts')->where('id', $oldAlertId)->value('tenant_id'));
        Db::name('operation_execution_intents')->insert([
            'tenant_id' => 1,
            'idempotency_key' => 'old-tenant-alert-bridge',
            'source_module' => 'operation_alert',
            'source_record_id' => $currentAlertId,
            'hotel_id' => 7,
            'platform' => 'ota',
            'object_type' => 'operation',
            'action_type' => 'legacy',
            'date_start' => $today,
            'date_end' => $today,
            'status' => 'pending_approval',
            'created_by' => 9,
            'created_at' => $today . ' 09:00:00',
            'updated_at' => $today . ' 09:00:00',
        ]);

        $convertibleAlertId = $this->insertOperationAlert(2, 'manual_convertible', 'current convertible', $today, false);
        Db::name('operation_alerts')->where('id', $convertibleAlertId)->update(['raw_data' => json_encode([
            'action_suggestion' => '先确认影响范围和责任模块，再安排负责人处理并在次日复盘数据变化。',
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        Db::name('price_suggestions')->insert([
            'tenant_id' => 2, 'hotel_id' => 7, 'suggestion_date' => $today, 'status' => 1,
        ]);
        $service = new OperationManagementService();
        $listed = $service->alerts([7], 7, true);
        self::assertNotContains($oldAlertId, array_column($listed['list'], 'id'));
        self::assertContains($currentAlertId, array_column($listed['list'], 'id'));
        $currentListedAlert = array_values(array_filter(
            $listed['list'],
            static fn(array $alert): bool => (int)$alert['id'] === $currentAlertId
        ))[0];
        self::assertFalse($currentListedAlert['task_bridge']['linked']);

        self::assertSame(0, $service->markAlertsRead([$oldAlertId], [7]));
        self::assertSame('unread', Db::name('operation_alerts')->where('id', $oldAlertId)->value('status'));
        try {
            $service->createExecutionIntentFromAlert($oldAlertId, [7], 9);
            self::fail('Previous-tenant alert must not create an execution intent');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('not found', strtolower($exception->getMessage()));
        }
        self::assertSame(0, (int)Db::name('operation_execution_intents')->where('tenant_id', 2)->count());

        $created = $service->createExecutionIntentFromAlert($convertibleAlertId, [7], 9);
        self::assertSame(2, (int)$created['execution_intent']['tenant_id']);
        self::assertSame('read', Db::name('operation_alerts')->where('id', $convertibleAlertId)->value('status'));
        self::assertSame(1, $service->markAlertsRead([$currentAlertId], [7]));

        $effect = $service->actionTracking([7], 7)['effect_validation'];
        $suggestionMetric = $this->effectMetric($effect, 'suggestion_adoption_rate');
        $alertMetric = $this->effectMetric($effect, 'alert_accuracy_rate');
        self::assertSame(1, $suggestionMetric['sample_count']);
        self::assertSame(0.0, $suggestionMetric['value']);
        self::assertSame(1, $alertMetric['sample_count']);
        self::assertSame(0.0, $alertMetric['value']);
    }

    public function testGeneratedAlertCannotBeRelabeledAfterHotelTenantChanges(): void
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
        Db::name('hotels')->where('id', 7)->update(['tenant_id' => 2]);
        $persist = new \ReflectionMethod(OperationManagementService::class, 'persistRuleAlerts');

        try {
            $persist->invoke(new OperationManagementService(), [[
                'tenant_id' => 1,
                'hotel_id' => 7,
                'alert_type' => 'tenant_transfer_race',
                'level' => 'high',
                'title' => 'old tenant fact',
                'message' => 'must not be relabeled',
                'source' => 'rule',
                'related_date' => $today,
                'raw_data' => [],
            ]]);
            self::fail('A generated old-tenant fact must not be persisted under the reassigned hotel tenant.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('tenant', strtolower($exception->getMessage()));
        }

        self::assertSame(0, (int)Db::name('operation_alerts')->count());
    }

    public function testExactAlertIdentityConvergesToOneWinnerAndOneExecutionIntent(): void
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
        $alert = [
            'tenant_id' => 1,
            'hotel_id' => 7,
            'alert_type' => 'conversion_low',
            'level' => 'medium',
            'title' => 'conversion alert',
            'message' => 'conversion below threshold',
            'source' => 'rule',
            'related_date' => $today,
            'raw_data' => [
                'metric_key' => 'ota_conversion_rate',
                'threshold_value' => 3,
                'observed_value' => 2,
                'comparison_rule' => 'observed_value < threshold_value',
            ],
        ];
        Db::execute('PRAGMA recursive_triggers = OFF');
        Db::execute("CREATE TRIGGER operation_alert_exact_identity_race
            BEFORE INSERT ON operation_alerts
            WHEN NEW.alert_type = 'conversion_low'
            BEGIN
                INSERT INTO operation_alerts (
                    tenant_id, hotel_id, alert_type, monitor_dedupe_key, level, title, message,
                    source, status, related_date, raw_data, created_at, updated_at, deleted_at
                ) VALUES (
                    NEW.tenant_id, NEW.hotel_id, NEW.alert_type, NEW.monitor_dedupe_key,
                    NEW.level, 'concurrent winner', NEW.message, NEW.source, 'unread',
                    NEW.related_date, NEW.raw_data, NEW.created_at, NEW.updated_at, NULL
                );
                SELECT RAISE(IGNORE);
            END");
        $persist = new \ReflectionMethod(OperationManagementService::class, 'persistRuleAlerts');
        $first = $persist->invoke(new OperationManagementService(), [$alert]);
        $second = $persist->invoke(new OperationManagementService(), [$alert]);

        self::assertCount(1, $first);
        self::assertCount(1, $second);
        self::assertSame($first[0]['id'], $second[0]['id']);
        self::assertSame(1, (int)Db::name('operation_alerts')->count());
        self::assertSame(
            hash('sha256', implode('|', [1, 7, 'conversion_low', 'rule', $today])),
            Db::name('operation_alerts')->value('monitor_dedupe_key')
        );

        $service = new OperationManagementService();
        $created = $service->createExecutionIntentFromAlert((int)$first[0]['id'], [7], 9);
        $replayed = $service->createExecutionIntentFromAlert((int)$second[0]['id'], [7], 9);
        self::assertSame($created['execution_intent']['id'], $replayed['execution_intent']['id']);
        self::assertTrue($replayed['reused_existing_intent']);
        self::assertSame(1, (int)Db::name('operation_execution_intents')->count());
    }

    public function testAlertAndPriceSuggestionTenantSchemaGapsFailClosedWithoutWrites(): void
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
        Db::execute('DROP TABLE operation_alerts');
        Db::execute('CREATE TABLE operation_alerts (
            id INTEGER PRIMARY KEY AUTOINCREMENT, hotel_id INTEGER, alert_type TEXT,
            source TEXT, status TEXT, related_date TEXT, deleted_at TEXT, updated_at TEXT
        )');
        Db::name('operation_alerts')->insert([
            'hotel_id' => 7, 'alert_type' => 'legacy', 'source' => 'manual',
            'status' => 'unread', 'related_date' => $today,
        ]);
        $service = new OperationManagementService();
        $alerts = $service->alerts([7], 7, true);
        self::assertSame('migration_required', $alerts['data_status']);
        self::assertSame([], $alerts['list']);
        try {
            $service->markAlertsRead([1], [7]);
            self::fail('Missing alert tenant schema must not be reported as updated=0 success.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('migration_required', $exception->getMessage());
        }
        self::assertSame('unread', Db::name('operation_alerts')->where('id', 1)->value('status'));
        try {
            $service->createExecutionIntentFromAlert(1, [7], 9);
            self::fail('Missing alert tenant schema must reject intent creation');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('migration_required', $exception->getMessage());
        }
        self::assertSame(0, (int)Db::name('operation_execution_intents')->count());

        Db::execute('DROP TABLE price_suggestions');
        Db::execute('CREATE TABLE price_suggestions (
            id INTEGER PRIMARY KEY AUTOINCREMENT, hotel_id INTEGER,
            suggestion_date TEXT, status INTEGER
        )');
        $tracking = $service->actionTracking([7], 7);
        self::assertSame('migration_required', $tracking['effect_validation']['data_status']);
        self::assertContains(
            'price_suggestions_tenant_schema_missing',
            array_column($tracking['effect_validation']['data_gaps'], 'code')
        );
    }

    public function testMissingAlertTableFailsClosedForReadAndMarkInsteadOfReportingUpdatedZero(): void
    {
        Db::execute('DROP TABLE operation_alerts');
        $service = new OperationManagementService();

        $alerts = $service->alerts([7], 7, true);
        self::assertSame('migration_required', $alerts['data_status']);
        self::assertFalse($alerts['capabilities']['can_mark_read']);
        self::assertSame([], $alerts['list']);

        try {
            $service->markAlertsRead([1], [7]);
            self::fail('Missing operation_alerts must not be reported as an HTTP-success-compatible updated=0 result.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('migration_required', $exception->getMessage());
        }
    }

    public function testAlertDedupeColumnWithoutUniqueIndexFailsClosed(): void
    {
        Db::execute('DROP TABLE operation_alerts');
        Db::execute('CREATE TABLE operation_alerts (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER,
            alert_type TEXT, monitor_dedupe_key TEXT, source TEXT, status TEXT,
            related_date TEXT, deleted_at TEXT, updated_at TEXT
        )');

        $alerts = (new OperationManagementService())->alerts([7], 7, true);
        self::assertSame('migration_required', $alerts['data_status']);
        self::assertContains(
            'operation_alerts_dedupe_unique_index_missing',
            array_column($alerts['data_gaps'], 'code')
        );
        self::assertFalse($alerts['capabilities']['can_mark_read']);
    }

    public function testActionEffectWindowAndObservationUseShanghaiCalendarAcrossUtcBoundary(): void
    {
        $service = new OperationManagementService();
        $boundaryInstant = new \DateTimeImmutable('2026-08-12 16:30:00', new \DateTimeZone('UTC'));
        $row = [
            'hotel_id' => 7,
            'start_date' => '2026-08-10',
            'end_date' => null,
            'target_metric' => 'orders',
            'target_change_rate' => 10,
        ];

        $window = (new \ReflectionMethod(OperationManagementService::class, 'operationActionEffectWindow'))
            ->invoke($service, $row, $boundaryInstant);
        self::assertSame('2026-08-10', $window['start_date']);
        self::assertSame('2026-08-13', $window['end_date']);
        self::assertSame('2026-08-14', $window['as_of_date']);
        self::assertSame(4, $window['days']);

        $metricIdentity = [[
            'metric' => 'orders',
            'scope' => 'ota_channel',
            'platform' => 'ctrip',
            'source' => 'ctrip',
            'measurement_grain' => 'daily_average',
        ]];
        $beforeEvidence = [
            'data_status' => 'ok',
            'days' => 7,
            'actual_days' => 7,
            'window_start_date' => '2026-08-03',
            'window_end_date' => '2026-08-09',
            'metric_sample_days' => ['orders' => 7],
            'source_scopes' => ['ota_channel'],
            'metric_identities' => ['orders' => $metricIdentity],
        ];
        $afterEvidence = array_replace($beforeEvidence, [
            'days' => 4,
            'actual_days' => 4,
            'window_start_date' => '2026-08-10',
            'window_end_date' => '2026-08-13',
            'metric_sample_days' => ['orders' => 4],
        ]);
        $result = (new \ReflectionMethod(OperationManagementService::class, 'evaluateActionResult'))
            ->invoke(
                $service,
                $row,
                array_replace($beforeEvidence, ['avg_orders' => 10]),
                array_replace($afterEvidence, ['avg_orders' => 12]),
                $boundaryInstant
            );
        self::assertSame('observing', $result['status']);
        self::assertSame('operation_action_effect_window_mismatch', $result['gap_code']);

        $completedInstant = new \DateTimeImmutable('2026-08-20 08:00:00', new \DateTimeZone('Asia/Shanghai'));
        $completedWindow = (new \ReflectionMethod(OperationManagementService::class, 'operationActionEffectWindow'))
            ->invoke($service, $row, $completedInstant);
        self::assertSame('2026-08-16', $completedWindow['end_date']);
        self::assertSame(7, $completedWindow['days']);
        $completedAfter = array_replace($beforeEvidence, [
            'window_start_date' => '2026-08-10',
            'window_end_date' => '2026-08-16',
            'avg_orders' => 12,
        ]);
        $completedResult = (new \ReflectionMethod(OperationManagementService::class, 'evaluateActionResult'))
            ->invoke(
                $service,
                $row,
                array_replace($beforeEvidence, ['avg_orders' => 10]),
                $completedAfter,
                $completedInstant
            );
        self::assertSame('success', $completedResult['status']);

        $row['start_date'] = '2026-02-30';
        $this->expectException(\InvalidArgumentException::class);
        (new \ReflectionMethod(OperationManagementService::class, 'operationActionEffectWindow'))
            ->invoke($service, $row, $boundaryInstant);
    }

    public function testAlertMutationLocksHotelBeforeAlertAndSerializesTenantTransfer(): void
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
        $alertId = $this->insertOperationAlert(1, 'manual_lock', 'lock fixture', $today, false);
        $method = new \ReflectionMethod(
            OperationManagementService::class,
            'withOperationAlertMutationAuthorization'
        );
        $sourceLines = file($method->getFileName()) ?: [];
        $source = implode('', array_slice(
            $sourceLines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));
        self::assertLessThan(strpos($source, "Db::name('operation_alerts')"), strpos($source, "Db::name('hotels')"));

        $second = new \PDO('sqlite:' . self::$sqlitePath);
        $second->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $second->exec('PRAGMA busy_timeout = 1');
        $transferBlocked = false;
        $method->invoke(
            new OperationManagementService(),
            [$alertId],
            [7],
            function (array $alerts) use ($alertId, $second, &$transferBlocked): int {
                Db::name('operation_alerts')->where('id', $alertId)->update(['status' => 'read']);
                try {
                    $second->exec('UPDATE hotels SET tenant_id = 2 WHERE id = 7');
                } catch (\PDOException $exception) {
                    $transferBlocked = str_contains(strtolower($exception->getMessage()), 'locked');
                }
                return count($alerts);
            }
        );
        self::assertTrue($transferBlocked);
        self::assertSame(1, (int)Db::name('hotels')->where('id', 7)->value('tenant_id'));
    }

    public function testMissingDailyReportOrHotelTenantSchemaFailsClosed(): void
    {
        Db::execute('DROP TABLE daily_reports');
        Db::execute('CREATE TABLE daily_reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            hotel_id INTEGER NOT NULL,
            report_date DATE NOT NULL,
            report_data TEXT,
            status TINYINT DEFAULT 1
        )');

        $missingDailyTenant = (new OperationManagementService())->fullData([7], 7, '2026-08-12');
        self::assertSame('migration_required', $missingDailyTenant['summary']['data_status']);
        self::assertContains(
            'operation_daily_reports_tenant_schema_missing',
            array_column($missingDailyTenant['summary']['data_gaps'], 'code')
        );

        Db::execute('DROP TABLE daily_reports');
        Db::execute('CREATE TABLE daily_reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER,
            hotel_id INTEGER NOT NULL,
            report_date DATE NOT NULL,
            report_data TEXT,
            status TINYINT DEFAULT 1
        )');
        Db::execute('DROP TABLE hotels');
        $missingHotelTenant = (new OperationManagementService())->fullData([7], 7, '2026-08-12');
        self::assertSame('migration_required', $missingHotelTenant['summary']['data_status']);
        self::assertContains(
            'operation_hotels_tenant_schema_missing',
            array_column($missingHotelTenant['summary']['data_gaps'], 'code')
        );
    }

    public function testDailyReportReadFailureFailsClosedInsteadOfLookingEmpty(): void
    {
        $pdo = Db::connect()->getPdo();
        $pdo->sqliteCreateCollation(
            'temporary_operation_tenant_collation',
            static fn(string $left, string $right): int => strcmp($left, $right)
        );
        Db::execute('DROP TABLE daily_reports');
        Db::execute('CREATE TABLE daily_reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id TEXT COLLATE temporary_operation_tenant_collation,
            hotel_id INTEGER NOT NULL,
            report_date DATE NOT NULL,
            report_data TEXT,
            status TINYINT DEFAULT 1
        )');
        Db::name('daily_reports')->insert([
            'tenant_id' => '1',
            'hotel_id' => 7,
            'report_date' => '2026-08-12',
            'report_data' => '{}',
            'status' => 2,
        ]);
        unset($pdo);
        Db::connect()->close();
        Db::connect(null, true);

        $result = (new OperationManagementService())->fullData([7], 7, '2026-08-12');

        self::assertSame('migration_required', $result['summary']['data_status']);
        self::assertContains(
            'operation_daily_reports_read_failed',
            array_column($result['summary']['data_gaps'], 'code')
        );
    }

    public function testOtaTodayPeriodAndFinalityAlwaysUseAsiaShanghaiBusinessDate(): void
    {
        $timezone = new \DateTimeZone('Asia/Shanghai');
        $today = (new \DateTimeImmutable('today', $timezone))->format('Y-m-d');
        $yesterday = (new \DateTimeImmutable('today', $timezone))->modify('-1 day')->format('Y-m-d');
        $originalTimezone = date_default_timezone_get();
        $differentTimezone = null;
        foreach (['Pacific/Pago_Pago', 'Pacific/Kiritimati'] as $candidate) {
            date_default_timezone_set($candidate);
            if (date('Y-m-d') !== $today) {
                $differentTimezone = $candidate;
                break;
            }
        }
        self::assertNotNull($differentTimezone, 'The test must exercise another process calendar date.');

        $row = static fn(string $date, string $period, int $isFinal): array => [
            'system_hotel_id' => 7,
            'data_source_id' => 11,
            'hotel_id' => 130079194,
            'data_date' => $date,
            'source' => 'ctrip',
            'platform' => 'ctrip',
            'compare_type' => 'self',
            'validation_status' => 'verified',
            'readback_verified' => 1,
            'ingestion_method' => 'browser_profile',
            'data_period' => $period,
            'is_final' => $isFinal,
            'snapshot_time' => $date . ' 09:00:00',
            'raw_data' => '{}',
        ];

        try {
            $service = new OperationManagementService();
            $method = new \ReflectionMethod($service, 'isTrustedSelfOtaFactRow');
            $method->setAccessible(true);

            self::assertTrue($method->invoke($service, $row($today, 'realtime_snapshot', 0)));
            self::assertFalse($method->invoke($service, $row($today, 'historical_daily', 1)));
            self::assertTrue($method->invoke($service, $row($yesterday, 'historical_daily', 1)));
            self::assertFalse($method->invoke($service, $row($yesterday, 'realtime_snapshot', 0)));
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    public function testDefaultBaselineWindowAlwaysUsesAsiaShanghaiBusinessDate(): void
    {
        $shanghaiTimezone = new \DateTimeZone('Asia/Shanghai');
        $shanghaiToday = new \DateTimeImmutable('today', $shanghaiTimezone);
        for ($offset = 30; $offset >= 1; --$offset) {
            $this->insertWholeHotelDay(
                $shanghaiToday->modify('-' . $offset . ' days')->format('Y-m-d'),
                1200,
                6,
                4
            );
        }
        $this->insertWholeHotelDay($shanghaiToday->format('Y-m-d'), 999999, 99, 99);

        $originalTimezone = date_default_timezone_get();
        $differentTimezone = null;
        foreach (['Pacific/Pago_Pago', 'Pacific/Kiritimati'] as $candidate) {
            date_default_timezone_set($candidate);
            if (date('Y-m-d') !== $shanghaiToday->format('Y-m-d')) {
                $differentTimezone = $candidate;
                break;
            }
        }
        self::assertNotNull($differentTimezone, 'The test must exercise a process timezone on another calendar date.');

        try {
            $result = (new OperationManagementService())->strategySimulation(
                [7],
                7,
                ['strategy_type' => 'price_adjust', 'adjust_amount' => -5]
            );

            self::assertTrue($result['simulated']);
            self::assertSame('rule_scenario', $result['status']);
            self::assertSame(30, $result['baseline']['actual_days']);
            self::assertSame('ok', $result['baseline']['data_status']);
            self::assertSame(1200.0, $result['baseline']['avg_revenue']);
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    public function testExplicitBaselineEndDateRemainsExclusiveAndTimezoneStable(): void
    {
        $start = new \DateTimeImmutable('2026-08-01', new \DateTimeZone('Asia/Shanghai'));
        for ($offset = 0; $offset < 7; ++$offset) {
            $this->insertWholeHotelDay($start->modify('+' . $offset . ' days')->format('Y-m-d'), 1200, 6, 4);
        }
        $this->insertWholeHotelDay('2026-08-08', 9999, 99, 99);

        $originalTimezone = date_default_timezone_get();
        date_default_timezone_set('America/New_York');
        try {
            $method = new \ReflectionMethod(OperationManagementService::class, 'baseline');
            $method->setAccessible(true);
            $baseline = $method->invoke(new OperationManagementService(), [7], 7, '2026-08-08');

            self::assertSame(7, $baseline['actual_days']);
            self::assertSame('ok', $baseline['data_status']);
            self::assertSame(1200.0, $baseline['avg_revenue']);
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonSubmittedDailyReportStatuses')]
    public function testThirtyNonSubmittedDailyReportDaysCannotBecomeBaseline(int $reportStatus): void
    {
        for ($offset = 30; $offset >= 1; --$offset) {
            $this->insertWholeHotelDay(
                date('Y-m-d', strtotime('-' . $offset . ' days')),
                1200,
                6,
                4,
                $reportStatus
            );
        }

        $result = (new OperationManagementService())->strategySimulation(
            [7],
            7,
            ['strategy_type' => 'price_adjust', 'adjust_amount' => -5]
        );

        $gapCodes = array_column($result['baseline']['data_gaps'], 'code');
        self::assertFalse($result['simulated']);
        self::assertSame('insufficient_data', $result['status']);
        self::assertSame(0, $result['baseline']['actual_days']);
        self::assertSame('partial', $result['baseline']['data_status']);
        self::assertContains('baseline_daily_report_validation_untrusted', $gapCodes);
        self::assertSame(30, $result['baseline']['rejected_daily_report_count']);
        self::assertSame(30, $result['baseline']['rejected_daily_report_days']);
        $this->assertAllBaselineAveragesAreNull($result['baseline']);
        self::assertNull($result['rule_scenario']['avg_revenue']);
    }

    /** @return array<string, array{int}> */
    public static function nonSubmittedDailyReportStatuses(): array
    {
        return [
            'draft' => [1],
            'zero' => [0],
            'unknown numeric workflow state' => [3],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('genericCompatibilitySuccessStatuses')]
    public function testGenericCompatibilitySuccessWordsDoNotValidateDailyReport(string $validationStatus): void
    {
        $service = new OperationManagementService();
        $method = new \ReflectionMethod($service, 'buildSummaryFromRows');
        $method->setAccessible(true);

        $summary = $method->invoke($service, [[
            'tenant_id' => 1,
            'hotel_id' => 7,
            'report_date' => '2026-08-12',
            'validation_status' => $validationStatus,
            'report_data' => json_encode([
                'xb_revenue' => 1200,
                'xb_rooms' => 4,
                'order_count' => 6,
                'salable_rooms' => 10,
            ], JSON_THROW_ON_ERROR),
        ]], [], [7], 7, '2026-08-12');

        self::assertNull($summary['revenue']);
        self::assertSame(1, $summary['rejected_daily_report_count']);
        self::assertSame(['validation_status_untrusted' => 1], $summary['rejected_daily_report_reasons']);
    }

    /** @return array<string, array{string}> */
    public static function genericCompatibilitySuccessStatuses(): array
    {
        return [
            'success' => ['success'],
            'complete' => ['complete'],
            'completed' => ['completed'],
            'approved' => ['approved'],
            'passed' => ['passed'],
            'ok' => ['ok'],
        ];
    }

    public function testDraftStatusCannotBeUpgradedByCompatibilityVerifiedWord(): void
    {
        $service = new OperationManagementService();
        $method = new \ReflectionMethod($service, 'buildSummaryFromRows');
        $method->setAccessible(true);

        $summary = $method->invoke($service, [[
            'tenant_id' => 1,
            'hotel_id' => 7,
            'report_date' => '2026-08-12',
            'status' => 1,
            'validation_status' => 'verified',
            'report_data' => json_encode(['xb_revenue' => 1200], JSON_THROW_ON_ERROR),
        ]], [], [7], 7, '2026-08-12');

        self::assertNull($summary['revenue']);
        self::assertSame(['report_status_draft' => 1], $summary['rejected_daily_report_reasons']);
    }

    public function testExactVerifiedCompatibilityRowWithoutWorkflowStatusRemainsReadable(): void
    {
        $service = new OperationManagementService();
        $method = new \ReflectionMethod($service, 'buildSummaryFromRows');
        $method->setAccessible(true);

        $summary = $method->invoke($service, [[
            'tenant_id' => 1,
            'hotel_id' => 7,
            'report_date' => '2026-08-12',
            'validation_status' => 'verified',
            'report_data' => json_encode([
                'xb_revenue' => 1200,
                'xb_rooms' => 4,
                'order_count' => 6,
                'salable_rooms' => 10,
            ], JSON_THROW_ON_ERROR),
        ]], [], [7], 7, '2026-08-12');

        self::assertSame(1200.0, $summary['revenue']);
        self::assertSame(6, $summary['orders']);
        self::assertSame(4.0, $summary['room_nights']);
        self::assertSame(0, $summary['rejected_daily_report_count']);
    }

    public function testOneUntrustedDailyReportDayKeepsThirtyDayBaselineIncomplete(): void
    {
        for ($offset = 30; $offset >= 1; --$offset) {
            $this->insertWholeHotelDay(
                date('Y-m-d', strtotime('-' . $offset . ' days')),
                1200,
                6,
                4,
                $offset === 1 ? 1 : 2
            );
        }

        $result = (new OperationManagementService())->strategySimulation(
            [7],
            7,
            ['strategy_type' => 'price_adjust', 'adjust_amount' => -5]
        );

        $gapCodes = array_column($result['baseline']['data_gaps'], 'code');
        self::assertFalse($result['simulated']);
        self::assertSame('insufficient_data', $result['status']);
        self::assertSame(29, $result['baseline']['actual_days']);
        self::assertSame('partial', $result['baseline']['data_status']);
        self::assertContains('baseline_daily_report_validation_untrusted', $gapCodes);
        self::assertSame(1, $result['baseline']['rejected_daily_report_count']);
        self::assertSame(1, $result['baseline']['rejected_daily_report_days']);
        self::assertNull($result['rule_scenario']['avg_revenue']);
    }

    public function testThirtyCoveredDaysWithScopeDriftStillCannotProduceSimulation(): void
    {
        for ($offset = 29; $offset >= 2; --$offset) {
            $this->insertWholeHotelDay(date('Y-m-d', strtotime('-' . $offset . ' days')), 1200, 6, 4);
        }
        $this->insertWholeHotelDay(date('Y-m-d', strtotime('-30 days')), 1200, 6, 4);
        $this->insertOtaDay(date('Y-m-d', strtotime('-1 day')), 900, 5, 3);

        $result = (new OperationManagementService())->strategySimulation(
            [7],
            7,
            ['strategy_type' => 'price_adjust', 'adjust_amount' => -5]
        );

        $gapCodes = array_column($result['baseline']['data_gaps'], 'code');
        self::assertFalse($result['simulated']);
        self::assertSame(30, $result['baseline']['actual_days']);
        self::assertSame('partial', $result['baseline']['data_status']);
        self::assertNotContains('insufficient_baseline_days', $gapCodes);
        self::assertContains('baseline_scope_drift', $gapCodes);
        $this->assertAllBaselineAveragesAreNull($result['baseline']);
        self::assertNull($result['rule_scenario']['avg_revenue']);
    }

    public function testSourceOnlyCtripAndMeituanOperatingDaysCannotShareBaseline(): void
    {
        $this->insertOtaDay(date('Y-m-d', strtotime('-2 days')), 900, 5, 3, 'ctrip', '');
        $this->insertOtaDay(date('Y-m-d', strtotime('-1 day')), 1000, 6, 4, 'meituan', '');

        $result = (new OperationManagementService())->strategySimulation(
            [7],
            7,
            ['strategy_type' => 'price_adjust', 'adjust_amount' => -5]
        );

        self::assertFalse($result['simulated']);
        self::assertSame('partial', $result['baseline']['data_status']);
        self::assertContains('baseline_scope_drift', array_column($result['baseline']['data_gaps'], 'code'));
        $this->assertAllBaselineAveragesAreNull($result['baseline']);
    }

    public function testIndependentTrafficConversionDateCannotAugmentWholeHotelBaseline(): void
    {
        $this->insertWholeHotelDay(date('Y-m-d', strtotime('-3 days')), 1200, 6, 4);
        $this->insertWholeHotelDay(date('Y-m-d', strtotime('-2 days')), 1800, 8, 6);
        $this->insertTrafficDay(date('Y-m-d', strtotime('-1 day')), 'ctrip', '', 100, 10);

        $result = (new OperationManagementService())->strategySimulation(
            [7],
            7,
            ['strategy_type' => 'price_adjust', 'adjust_amount' => -5]
        );

        self::assertFalse($result['simulated']);
        self::assertSame('partial', $result['baseline']['data_status']);
        self::assertContains('baseline_scope_drift', array_column($result['baseline']['data_gaps'], 'code'));
        $this->assertAllBaselineAveragesAreNull($result['baseline']);
    }

    public function testSameSourceOnlyPlatformAndScopeRemainComparableAcrossDates(): void
    {
        foreach ([
            [date('Y-m-d', strtotime('-2 days')), 900, 5, 3, 100, 10],
            [date('Y-m-d', strtotime('-1 day')), 1200, 7, 4, 200, 20],
        ] as [$date, $revenue, $orders, $roomNights, $visitors, $flowOrders]) {
            $this->insertOtaDay($date, $revenue, $orders, $roomNights, 'ctrip', '');
            $this->insertTrafficDay($date, 'ctrip', '', $visitors, $flowOrders);
        }

        $result = (new OperationManagementService())->strategySimulation(
            [7],
            7,
            ['strategy_type' => 'price_adjust', 'adjust_amount' => -5]
        );

        self::assertFalse($result['simulated']);
        self::assertSame('partial', $result['baseline']['data_status']);
        self::assertNotContains('baseline_scope_drift', array_column($result['baseline']['data_gaps'], 'code'));
        self::assertSame(6.0, $result['baseline']['avg_orders']);
        self::assertSame(1050.0, $result['baseline']['avg_revenue']);
        self::assertSame(3.5, $result['baseline']['avg_room_nights']);
        self::assertSame(10.0, $result['baseline']['avg_conversion']);
    }

    public function testFlowAliasesShareOneChannelAndKeepHighestRankThenLatestFact(): void
    {
        $date = date('Y-m-d', strtotime('-1 day'));
        $this->insertOtaDay($date, 1200, 7, 4, 'ctrip', '');
        $this->insertTrafficDay($date, 'ctrip', '', 100, 10, 0, '10:00:00');
        $this->insertTrafficDay($date, 'trip', '', 200, 30, 1000, '09:00:00');
        $this->insertTrafficDay($date, '', 'trip.com', 250, 50, 1000, '11:00:00');

        $result = (new OperationManagementService())->strategySimulation(
            [7],
            7,
            ['strategy_type' => 'price_adjust', 'adjust_amount' => -5]
        );

        self::assertFalse($result['simulated']);
        self::assertSame('partial', $result['baseline']['data_status']);
        self::assertSame(1, $result['baseline']['metric_sample_days']['conversion']);
        self::assertSame(20.0, $result['baseline']['avg_conversion']);
    }

    public function testProductionEtlSourceAliasesShareCanonicalChannelAndKeepHighestRank(): void
    {
        $date = date('Y-m-d', strtotime('-1 day'));
        $this->insertOtaDay($date, 1200, 7, 4, 'ctrip_business', '');
        $this->insertTrafficDay($date, 'ctrip', '', 100, 10, 0, '11:00:00');
        $this->insertTrafficDay($date, 'ctrip_browser_profile', '', 200, 40, 1000, '09:00:00');
        $this->insertTrafficDay($date, 'meituan_rank', 'ctrip', 1000, 900, 1000, '12:00:00');

        $result = (new OperationManagementService())->strategySimulation(
            [7],
            7,
            ['strategy_type' => 'price_adjust', 'adjust_amount' => -5]
        );

        self::assertFalse($result['simulated']);
        self::assertSame('partial', $result['baseline']['data_status']);
        self::assertSame(1, $result['baseline']['metric_sample_days']['conversion']);
        self::assertSame(20.0, $result['baseline']['avg_conversion']);
    }

    public function testConflictingSourceAndPlatformFlowFactIsRejected(): void
    {
        $date = date('Y-m-d', strtotime('-1 day'));
        $this->insertOtaDay($date, 1200, 7, 4, 'ctrip', '');
        $this->insertTrafficDay($date, 'ctrip', '', 100, 10);
        $this->insertTrafficDay($date, 'ctrip', 'meituan', 1000, 900, 1000, '11:00:00');

        $result = (new OperationManagementService())->strategySimulation(
            [7],
            7,
            ['strategy_type' => 'price_adjust', 'adjust_amount' => -5]
        );

        self::assertFalse($result['simulated']);
        self::assertSame('partial', $result['baseline']['data_status']);
        self::assertSame(10.0, $result['baseline']['avg_conversion']);
    }

    public function testFlowSelectionUsesBusinessSnapshotTimeBeforePersistenceUpdateTime(): void
    {
        $date = date('Y-m-d', strtotime('-1 day'));
        $this->insertOtaDay($date, 1200, 7, 4, 'ctrip', '');
        $this->insertTrafficDay($date, 'ctrip', '', 100, 90, 1000, '09:00:00', '23:00:00');
        $this->insertTrafficDay($date, 'ctrip', '', 200, 40, 1000, '11:00:00', '11:00:00');

        $result = (new OperationManagementService())->strategySimulation(
            [7], 7, ['strategy_type' => 'price_adjust', 'adjust_amount' => -5]
        );

        self::assertFalse($result['simulated']);
        self::assertSame(20.0, $result['baseline']['avg_conversion']);
    }

    public function testFlowSelectionUsesLaterIdForSameBusinessTime(): void
    {
        $date = date('Y-m-d', strtotime('-1 day'));
        $this->insertOtaDay($date, 1200, 7, 4, 'ctrip', '');
        $this->insertTrafficDay($date, 'ctrip', '', 100, 10, 1000, '11:00:00', '13:00:00');
        $this->insertTrafficDay($date, 'ctrip', '', 200, 60, 1000, '11:00:00', '12:00:00');

        $result = (new OperationManagementService())->strategySimulation(
            [7], 7, ['strategy_type' => 'price_adjust', 'adjust_amount' => -5]
        );

        self::assertFalse($result['simulated']);
        self::assertSame(30.0, $result['baseline']['avg_conversion']);
    }

    public function testFlowSelectionUsesFetchedCollectionTimeBeforePersistenceTime(): void
    {
        $date = date('Y-m-d', strtotime('-1 day'));
        $this->insertOtaDay($date, 1200, 7, 4, 'ctrip', '');
        $firstId = $this->insertTrafficDay($date, 'ctrip', '', 100, 90, 1000, '09:00:00', '23:00:00');
        $secondId = $this->insertTrafficDay($date, 'ctrip', '', 200, 40, 1000, '09:00:00', '11:00:00');
        foreach ([[$firstId, 100, 90, '09:00:00'], [$secondId, 200, 40, '11:00:00']] as [$id, $visitors, $orders, $fetched]) {
            Db::name('online_daily_data')->where('id', $id)->update([
                'snapshot_time' => null,
                'raw_data' => json_encode([
                    'exposure' => 1000,
                    'visitors' => $visitors,
                    'orders' => $orders,
                    'fetched_at' => $date . ' ' . $fetched,
                ], JSON_THROW_ON_ERROR),
            ]);
        }

        $result = (new OperationManagementService())->strategySimulation(
            [7], 7, ['strategy_type' => 'price_adjust', 'adjust_amount' => -5]
        );

        self::assertFalse($result['simulated']);
        self::assertSame(20.0, $result['baseline']['avg_conversion']);
    }

    public function testEquivalentCollectionTimeZonesAreOneTrustedInstant(): void
    {
        $date = date('Y-m-d', strtotime('-1 day'));
        $this->insertOtaDay($date, 1200, 7, 4, 'ctrip', '');
        $olderId = $this->insertTrafficDay($date, 'ctrip', '', 100, 10, 1000, '09:00:00', '23:00:00');
        Db::name('online_daily_data')->where('id', $olderId)->update([
            'snapshot_time' => null,
            'received_at' => $date . 'T01:00:00Z',
            'raw_data' => json_encode(['exposure' => 1000, 'visitors' => 100, 'orders' => 10], JSON_THROW_ON_ERROR),
        ]);
        $equivalentId = $this->insertTrafficDay($date, 'ctrip', '', 200, 40, 1000, '09:00:00', '08:00:00');
        Db::name('online_daily_data')->where('id', $equivalentId)->update([
            'snapshot_time' => null,
            'received_at' => $date . 'T10:00:00+08:00',
            'raw_data' => json_encode([
                'exposure' => 1000,
                'visitors' => 200,
                'orders' => 40,
                'fetched_at' => $date . 'T02:00:00Z',
            ], JSON_THROW_ON_ERROR),
        ]);

        $result = (new OperationManagementService())->strategySimulation(
            [7], 7, ['strategy_type' => 'price_adjust', 'adjust_amount' => -5]
        );

        self::assertFalse($result['simulated']);
        self::assertSame(20.0, $result['baseline']['avg_conversion']);
    }

    public function testOffsetlessCollectionTimesAlwaysMeanAsiaShanghai(): void
    {
        $originalTimezone = date_default_timezone_get();
        date_default_timezone_set('America/New_York');
        try {
            $method = new \ReflectionMethod(OperationManagementService::class, 'onlineRowTimeIdentity');
            $method->setAccessible(true);
            $identity = $method->invoke(new OperationManagementService(), [
                '2026-08-13 09:00:00.123456',
                '2026-08-13T09:00:00.123456+08:00',
                '2026-08-13T01:00:00.123456Z',
            ]);

            self::assertSame('valid', $identity['status']);
            self::assertSame(1786582800, $identity['timestamp']);
            self::assertSame(1786582800123456, $identity['sort_timestamp']);
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    public function testCollectionTimeOrderingRetainsMicrosecondsOutsideShanghaiDefaultTimezone(): void
    {
        $originalTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        try {
            $method = new \ReflectionMethod(OperationManagementService::class, 'onlineRowTimeIdentity');
            $method->setAccessible(true);
            $service = new OperationManagementService();
            $older = $method->invoke($service, ['2026-08-13 09:00:00.100001']);
            $newer = $method->invoke($service, ['2026-08-13T09:00:00.100002+08:00']);

            self::assertSame('valid', $older['status']);
            self::assertSame('valid', $newer['status']);
            self::assertSame(1, $newer['sort_timestamp'] - $older['sort_timestamp']);
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    public function testFlowSelectionRejectsConflictingTrustedCollectionTimes(): void
    {
        $date = date('Y-m-d', strtotime('-1 day'));
        $this->insertOtaDay($date, 1200, 7, 4, 'ctrip', '');
        $validId = $this->insertTrafficDay($date, 'ctrip', '', 100, 10, 1000, '09:00:00', '10:00:00');
        Db::name('online_daily_data')->where('id', $validId)->update([
            'snapshot_time' => null,
            'received_at' => $date . ' 10:00:00',
            'raw_data' => json_encode(['exposure' => 1000, 'visitors' => 100, 'orders' => 10], JSON_THROW_ON_ERROR),
        ]);
        $conflictId = $this->insertTrafficDay($date, 'ctrip', '', 1000, 900, 1000, '09:00:00', '23:00:00');
        Db::name('online_daily_data')->where('id', $conflictId)->update([
            'snapshot_time' => null,
            'received_at' => $date . ' 12:00:00',
            'raw_data' => json_encode([
                'exposure' => 1000,
                'visitors' => 1000,
                'orders' => 900,
                'fetched_at' => $date . ' 13:00:00',
            ], JSON_THROW_ON_ERROR),
        ]);

        $result = (new OperationManagementService())->strategySimulation(
            [7], 7, ['strategy_type' => 'price_adjust', 'adjust_amount' => -5]
        );

        self::assertFalse($result['simulated']);
        self::assertSame(10.0, $result['baseline']['avg_conversion']);
    }

    public function testFlowSelectionKeepsFractionalSecondPrecisionBeforeIdTieBreak(): void
    {
        $date = date('Y-m-d', strtotime('-1 day'));
        $this->insertOtaDay($date, 1200, 7, 4, 'ctrip', '');
        $newerLowerId = $this->insertTrafficDay(
            $date,
            'ctrip',
            '',
            100,
            20,
            1000,
            '10:00:00.900'
        );
        $olderHigherId = $this->insertTrafficDay(
            $date,
            'ctrip',
            '',
            100,
            10,
            1000,
            '10:00:00.100'
        );
        self::assertLessThan($olderHigherId, $newerLowerId);

        $result = (new OperationManagementService())->strategySimulation(
            [7],
            7,
            ['strategy_type' => 'price_adjust', 'adjust_amount' => -5]
        );

        self::assertFalse($result['simulated']);
        self::assertSame(20.0, $result['baseline']['avg_conversion']);
    }

    public function testOnlineRowTimestampFallsBackToPersistenceOnlyWhenAllCollectionTimesAreMissing(): void
    {
        $method = new \ReflectionMethod(OperationManagementService::class, 'onlineRowTimestamp');
        $method->setAccessible(true);

        self::assertSame(strtotime('2026-08-13 12:00:00'), $method->invoke(
            new OperationManagementService(),
            ['raw_data' => '{}', 'update_time' => '2026-08-13 12:00:00', 'create_time' => '2026-08-13 10:00:00']
        ));
    }

    public function testFlowSelectionUsesPersistenceTimeForOtherwiseTrustedHistoricalRowsWithoutCollectionTimes(): void
    {
        $date = date('Y-m-d', strtotime('-1 day'));
        $this->insertOtaDay($date, 1200, 7, 4, 'ctrip', '');
        $olderId = $this->insertTrafficDay($date, 'ctrip', '', 100, 10, 1000, '09:00:00', '10:00:00');
        $newerId = $this->insertTrafficDay($date, 'ctrip', '', 200, 40, 1000, '09:00:00', '12:00:00');
        foreach ([[$olderId, 100, 10], [$newerId, 200, 40]] as [$id, $visitors, $orders]) {
            Db::name('online_daily_data')->where('id', $id)->update([
                'snapshot_time' => null,
                'collected_at' => null,
                'received_at' => null,
                'raw_data' => json_encode([
                    'exposure' => 1000,
                    'visitors' => $visitors,
                    'orders' => $orders,
                    'capture_evidence' => ['endpoint_id' => 'traffic_flow_transform'],
                ], JSON_THROW_ON_ERROR),
            ]);
        }

        $result = (new OperationManagementService())->strategySimulation(
            [7], 7, ['strategy_type' => 'price_adjust', 'adjust_amount' => -5]
        );

        self::assertFalse($result['simulated']);
        self::assertSame('partial', $result['baseline']['data_status']);
        self::assertSame(1, $result['baseline']['metric_sample_days']['conversion']);
        self::assertSame(20.0, $result['baseline']['avg_conversion']);
    }

    public function testPersistenceFallbackDoesNotAdmitTimestampLessRowsWithoutCollectionEndpointEvidence(): void
    {
        $date = date('Y-m-d', strtotime('-1 day'));
        $this->insertOtaDay($date, 1200, 7, 4, 'ctrip', '');
        $trustedId = $this->insertTrafficDay($date, 'ctrip', '', 100, 10, 1000, '09:00:00', '10:00:00');
        $unprovenId = $this->insertTrafficDay($date, 'ctrip', '', 1000, 900, 1000, '09:00:00', '12:00:00');
        foreach ([[$trustedId, 100, 10], [$unprovenId, 1000, 900]] as [$id, $visitors, $orders]) {
            Db::name('online_daily_data')->where('id', $id)->update([
                'snapshot_time' => null,
                'raw_data' => json_encode([
                    'exposure' => 1000,
                    'visitors' => $visitors,
                    'orders' => $orders,
                    'capture_evidence' => $id === $trustedId
                        ? ['endpoint_id' => 'traffic_flow_transform']
                        : [],
                ], JSON_THROW_ON_ERROR),
                'dimension' => $id === $trustedId
                    ? 'catalog:ctrip:traffic_flow_transform:v1'
                    : 'legacy_flow_transform',
            ]);
        }

        $result = (new OperationManagementService())->strategySimulation(
            [7], 7, ['strategy_type' => 'price_adjust', 'adjust_amount' => -5]
        );

        self::assertFalse($result['simulated']);
        self::assertSame(10.0, $result['baseline']['avg_conversion']);
    }

    public function testFlowSelectionRejectsInvalidOrConflictingBusinessTimes(): void
    {
        $date = date('Y-m-d', strtotime('-1 day'));
        $this->insertOtaDay($date, 1200, 7, 4, 'ctrip', '');
        $this->insertTrafficDay($date, 'ctrip', '', 100, 10, 1000, '10:00:00', '10:00:00');
        $invalidId = $this->insertTrafficDay($date, 'ctrip', '', 1000, 900, 1000, '12:00:00', '23:00:00');
        Db::name('online_daily_data')->where('id', $invalidId)->update(['snapshot_time' => 'not-a-time']);
        $conflictingId = $this->insertTrafficDay($date, 'ctrip', '', 1000, 800, 1000, '13:00:00', '23:30:00');
        Db::name('online_daily_data')->where('id', $conflictingId)->update([
            'collected_at' => $date . ' 14:00:00',
        ]);

        $result = (new OperationManagementService())->strategySimulation(
            [7], 7, ['strategy_type' => 'price_adjust', 'adjust_amount' => -5]
        );

        self::assertFalse($result['simulated']);
        self::assertSame(10.0, $result['baseline']['avg_conversion']);
    }

    private function insertWholeHotelDay(
        string $date,
        float $revenue,
        int $orders,
        int $roomNights,
        int $status = 2,
        int $tenantId = 1
    ): void
    {
        Db::name('daily_reports')->insert([
            'tenant_id' => $tenantId,
            'hotel_id' => 7,
            'report_date' => $date,
            'report_data' => json_encode([
                'xb_revenue' => $revenue,
                'xb_rooms' => $roomNights,
                'order_count' => $orders,
                'salable_rooms' => 10,
            ], JSON_THROW_ON_ERROR),
            'status' => $status,
            'create_time' => $date . ' 23:00:00',
            'update_time' => $date . ' 23:00:00',
        ]);
    }

    private function insertOtaDay(
        string $date,
        float $revenue,
        int $orders,
        int $roomNights,
        string $source = 'ctrip',
        string $platform = 'ctrip',
        int $tenantId = 1
    ): void
    {
        Db::name('online_daily_data')->insert([
            'tenant_id' => $tenantId,
            'system_hotel_id' => 7,
            'data_source_id' => 11,
            'hotel_id' => 130079194,
            'data_date' => $date,
            'source' => $source,
            'platform' => $platform,
            'compare_type' => 'self',
            'data_type' => 'business',
            'dimension' => '',
            'validation_status' => 'verified',
            'readback_verified' => 1,
            'ingestion_method' => 'browser_profile',
            'data_period' => 'historical_daily',
            'is_final' => 1,
            'snapshot_time' => $date . ' 09:00:00',
            'raw_data' => '{}',
            'amount' => $revenue,
            'quantity' => $roomNights,
            'book_order_num' => $orders,
            'create_time' => $date . ' 09:00:00',
            'update_time' => $date . ' 09:00:00',
        ]);
    }

    private function insertActionTrack(int $tenantId, string $title): int
    {
        return (int)Db::name('operation_action_tracks')->insertGetId([
            'tenant_id' => $tenantId,
            'hotel_id' => 7,
            'action_type' => 'promotion',
            'action_title' => $title,
            'start_date' => '2026-08-10',
            'end_date' => '2026-08-12',
            'target_metric' => 'orders',
            'target_change_rate' => 10,
            'before_data_json' => '{}',
            'after_data_json' => '{}',
            'result_status' => 'observing',
            'result_summary' => '',
            'remark' => '',
            'status' => 'active',
            'created_at' => '2026-08-10 09:00:00',
            'updated_at' => '2026-08-10 09:00:00',
            'deleted_at' => null,
        ]);
    }

    private function insertOperationAlert(
        int $tenantId,
        string $type,
        string $title,
        string $date,
        bool $accurate
    ): int {
        return (int)Db::name('operation_alerts')->insertGetId([
            'tenant_id' => $tenantId,
            'hotel_id' => 7,
            'alert_type' => $type,
            'level' => 'medium',
            'title' => $title,
            'message' => $title,
            'source' => 'manual',
            'status' => 'unread',
            'related_date' => $date,
            'raw_data' => json_encode(['accuracy_review' => ['accurate' => $accurate]], JSON_THROW_ON_ERROR),
            'created_at' => $date . ' 09:00:00',
            'updated_at' => $date . ' 09:00:00',
            'deleted_at' => null,
        ]);
    }

    /** @param array<string,mixed> $effect @return array<string,mixed> */
    private function effectMetric(array $effect, string $key): array
    {
        foreach ((array)($effect['metrics'] ?? []) as $metric) {
            if (is_array($metric) && ($metric['key'] ?? null) === $key) {
                return $metric;
            }
        }
        self::fail('Missing effect metric: ' . $key);
    }

    private function insertTrafficDay(
        string $date,
        string $source,
        string $platform,
        int $visitors,
        int $orders,
        int $exposure = 0,
        string $time = '10:00:00',
        ?string $persistenceTime = null
    ): int {
        $persistenceTime ??= $time;
        return (int)Db::name('online_daily_data')->insertGetId([
            'tenant_id' => 1,
            'system_hotel_id' => 7,
            'data_source_id' => 11,
            'hotel_id' => 130079194,
            'data_date' => $date,
            'source' => $source,
            'platform' => $platform,
            'compare_type' => 'self',
            'data_type' => 'traffic',
            'dimension' => 'catalog:' . $source . ':traffic_flow_transform:v1',
            'validation_status' => 'verified',
            'readback_verified' => 1,
            'ingestion_method' => 'browser_profile',
            'data_period' => 'historical_daily',
            'is_final' => 1,
            'snapshot_time' => $date . ' ' . $time,
            'raw_data' => json_encode([
                'exposure' => $exposure,
                'visitors' => $visitors,
                'orders' => $orders,
                'capture_evidence' => ['endpoint_id' => 'traffic_flow_transform'],
            ], JSON_THROW_ON_ERROR),
            'create_time' => $date . ' ' . $persistenceTime,
            'update_time' => $date . ' ' . $persistenceTime,
        ]);
    }

    /** @param array<string, mixed> $baseline */
    private function assertAllBaselineAveragesAreNull(array $baseline): void
    {
        self::assertNull($baseline['avg_orders']);
        self::assertNull($baseline['avg_revenue']);
        self::assertNull($baseline['avg_room_nights']);
        self::assertNull($baseline['avg_conversion']);
    }
}
