<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperationManagementService;
use app\service\SourceBackedExecutionIntentIdentityService;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OperationAlertTaskBridgeTest extends TestCase
{
    use ReflectionHelper;

    private static array $originalDatabaseConfig = [];
    private static string $databasePath = '';

    public static function setUpBeforeClass(): void
    {
        (new App(dirname(__DIR__)))->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'operation_alert_task_bridge_' . getmypid() . '.sqlite';
        @unlink(self::$databasePath);
        $database = self::$originalDatabaseConfig;
        $database['default'] = 'sqlite';
        $database['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$databasePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($database, 'database');
        Db::connect(null, true);
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER)');
        Db::name('hotels')->insertAll([
            ['id' => 7, 'tenant_id' => 1],
            ['id' => 8, 'tenant_id' => 1],
            ['id' => 9, 'tenant_id' => 2],
        ]);
        Db::execute('CREATE TABLE operation_alerts (id INTEGER PRIMARY KEY, tenant_id INTEGER, hotel_id INTEGER, alert_type TEXT, monitor_dedupe_key TEXT UNIQUE, level TEXT, title TEXT, message TEXT, source TEXT, status TEXT, related_date TEXT, action_suggestion TEXT, raw_data TEXT, deleted_at TEXT, created_at TEXT, updated_at TEXT)');
        Db::execute('CREATE TABLE operation_execution_intents (id INTEGER PRIMARY KEY, tenant_id INTEGER, source_module TEXT, source_record_id INTEGER, hotel_id INTEGER, status TEXT, blocked_reason TEXT, evidence_json TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        Db::execute('CREATE TABLE operation_execution_tasks (id INTEGER PRIMARY KEY)');
        Db::execute('CREATE TABLE operation_execution_evidence (id INTEGER PRIMARY KEY)');
    }

    public static function tearDownAfterClass(): void
    {
        Db::connect()->close();
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$databasePath);
    }

    protected function setUp(): void
    {
        Db::name('operation_alerts')->delete(true);
        Db::name('operation_execution_intents')->delete(true);
    }

    public function testGenericExecutionIntentCannotForgeReservedOperationAlertSource(): void
    {
        $service = new OperationManagementService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reserved execution source');
        $service->createExecutionIntent([7], 7, [
            'hotel_id' => 7,
            'source_module' => 'operation_alert',
            'source_record_id' => 91,
            'object_type' => 'operation_checklist',
            'action_type' => 'review_operation_alert',
            'date_start' => '2026-07-19',
            'date_end' => '2026-07-19',
        ], 3);
    }

    public function testScopedAlertEndpointCrossesReservedSourceBoundaryExplicitly(): void
    {
        Db::name('operation_alerts')->insert([
            'id' => 501,
            'tenant_id' => 1,
            'hotel_id' => 7,
            'alert_type' => 'conversion_low',
            'level' => 'medium',
            'title' => 'Conversion alert',
            'message' => 'Conversion is below the configured threshold',
            'source' => 'rule',
            'status' => 'unread',
            'related_date' => '2026-07-19',
            'action_suggestion' => 'Review the conversion funnel',
            'raw_data' => json_encode([
                'metric_key' => 'ota_conversion_rate',
                'threshold_value' => 3,
                'observed_value' => 2.4,
                'comparison_rule' => 'observed_value < threshold_value',
            ], JSON_UNESCAPED_UNICODE),
        ]);
        $service = new class extends OperationManagementService {
            /** @var array<int,mixed> */
            public array $capturedArguments = [];

            public function createExecutionIntent(
                array $hotelIds,
                ?int $hotelId,
                array $input,
                int $createdBy,
                bool $trustedExpansionSource = false,
                ?string $trustedIdempotencyKey = null,
                bool $trustedReservedSource = false
            ): array {
                $this->capturedArguments = func_get_args();
                return ['id' => 77, 'status' => 'pending_approval', 'blocked_reason' => ''];
            }
        };

        $result = $service->createExecutionIntentFromAlert(501, [7], 3);

        self::assertSame('operation_alert', $service->capturedArguments[2]['source_module']);
        self::assertSame(501, $service->capturedArguments[2]['source_record_id']);
        self::assertTrue($service->capturedArguments[6]);
        self::assertNull($service->capturedArguments[5]);
        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/D',
            $service->capturedArguments[2]['evidence']['source_snapshot_digest']
        );
        self::assertSame(77, $result['execution_intent']['id']);
        self::assertSame('read', Db::name('operation_alerts')->where('id', 501)->value('status'));
    }

    public function testPersistedThresholdAlertBuildsPendingHumanChecklistWithoutOtaWrite(): void
    {
        $service = new OperationManagementService();
        self::assertTrue(method_exists($service, 'createExecutionIntentFromAlert'));

        $input = $this->invokeNonPublic($service, 'buildAlertExecutionIntentInput', [[
            'id' => 91,
            'hotel_id' => 7,
            'alert_type' => 'conversion_low',
            'level' => 'medium',
            'title' => '转化偏低',
            'message' => '订单/访客转化率低于3%',
            'source' => 'rule',
            'status' => 'unread',
            'related_date' => '2026-07-19',
            'action_suggestion' => '复核详情页到下单漏斗',
            'raw_data' => [
                'metric_key' => 'ota_conversion_rate',
                'threshold_value' => 3,
                'observed_value' => 2.4,
                'cookie' => 'must-not-enter-execution-evidence',
            ],
        ]]);

        self::assertSame('operation_alert', $input['source_module']);
        self::assertSame(91, $input['source_record_id']);
        self::assertSame(7, $input['hotel_id']);
        self::assertSame('ota', $input['platform']);
        self::assertSame('operation_checklist', $input['object_type']);
        self::assertSame('review_conversion_funnel', $input['action_type']);
        self::assertSame('ota_conversion_rate', $input['expected_metric']);
        self::assertSame('ota_channel', $input['target_value']['metric_scope']);
        self::assertFalse($input['evidence']['auto_write_ota']);
        self::assertSame(['operation_alert#91'], $input['evidence']['evidence_refs']);
        self::assertArrayNotHasKey('cookie', $input['evidence']['alert_context']);

        $payload = $service->buildExecutionIntentPayload([7], 7, $input, 3);
        self::assertSame('pending_approval', $payload['status']);
        self::assertSame('', $payload['blocked_reason']);
    }

    public function testMeituanCompetitorAlertKeepsPlatformAndUsesStableIdempotencyNamespace(): void
    {
        $service = new OperationManagementService();
        $input = $this->invokeNonPublic($service, 'buildAlertExecutionIntentInput', [[
            'id' => 92,
            'hotel_id' => 7,
            'alert_type' => 'meituan_competitor_top1_changed',
            'level' => 'high',
            'title' => '美团重点竞对变化',
            'message' => 'TOP1竞对发生变化',
            'status' => 'unread',
            'related_date' => '2026-07-19',
            'raw_data' => ['change_signal_type' => 'top1_changed'],
        ]]);

        self::assertSame('meituan', $input['platform']);
        self::assertSame('review_meituan_competitor_change', $input['action_type']);
        self::assertSame('meituan_competitor_rank_signal', $input['expected_metric']);
        self::assertSame(
            'operation_alert_' . str_repeat('a', 32),
            $this->invokeNonPublic($service, 'normalizeTrustedExecutionIntentIdempotencyKey', [
                'operation_alert_' . str_repeat('a', 32),
            ])
        );
    }

    public function testAlertBridgeUsesExactHotelAndAlertPairAcrossMultipleHotels(): void
    {
        $alertA = ['id' => 101, 'tenant_id' => 1, 'hotel_id' => 7];
        $alertB = ['id' => 202, 'tenant_id' => 1, 'hotel_id' => 8];
        Db::name('operation_execution_intents')->insertAll([
            ['id' => 11, 'tenant_id' => 1, 'source_module' => 'operation_alert', 'source_record_id' => 101, 'hotel_id' => 7, 'status' => 'pending_approval', 'evidence_json' => $this->alertDigestEvidence($alertA)],
            ['id' => 12, 'tenant_id' => 1, 'source_module' => 'operation_alert', 'source_record_id' => 202, 'hotel_id' => 8, 'status' => 'draft', 'evidence_json' => $this->alertDigestEvidence($alertB)],
            ['id' => 21, 'tenant_id' => 1, 'source_module' => 'operation_alert', 'source_record_id' => 101, 'hotel_id' => 8, 'status' => 'blocked', 'evidence_json' => '{}'],
            ['id' => 22, 'tenant_id' => 1, 'source_module' => 'operation_alert', 'source_record_id' => 202, 'hotel_id' => 7, 'status' => 'blocked', 'evidence_json' => '{}'],
        ]);
        $service = new OperationManagementService();

        $alerts = $this->invokeNonPublic($service, 'attachAlertExecutionBridges', [[
            $alertA,
            $alertB,
        ], true]);

        self::assertSame(11, $alerts[0]['task_bridge']['intent_id']);
        self::assertSame('pending_approval', $alerts[0]['task_bridge']['intent_status']);
        self::assertSame(12, $alerts[1]['task_bridge']['intent_id']);
        self::assertSame('draft', $alerts[1]['task_bridge']['intent_status']);
    }

    public function testAlertBridgeRejectsStaleSnapshotAndMaterialRefreshRestoresUnreadVisibility(): void
    {
        $service = new OperationManagementService();
        $base = [
            'id' => 1, 'tenant_id' => 1, 'hotel_id' => 7,
            'alert_type' => 'conversion_low', 'level' => 'medium',
            'title' => 'Conversion alert', 'message' => 'Observed 2.4%',
            'source' => 'rule', 'status' => 'unread', 'related_date' => '2026-08-13',
            'action_suggestion' => 'Review conversion funnel',
            'raw_data' => [
                'metric_key' => 'ota_conversion_rate', 'threshold_value' => 3,
                'observed_value' => 2.4, 'comparison_rule' => 'observed_value < threshold_value',
            ],
        ];
        $persisted = $this->invokeNonPublic($service, 'persistRuleAlerts', [[$base]]);
        self::assertSame('unread', $persisted[0]['status']);
        $alertId = (int)$persisted[0]['id'];
        Db::name('operation_alerts')->where('id', $alertId)->update(['status' => 'read']);

        $same = $this->invokeNonPublic($service, 'persistRuleAlerts', [[$base]]);
        self::assertSame($alertId, (int)$same[0]['id']);
        self::assertSame('read', $same[0]['status'], 'display-only refresh must preserve read tracking');

        Db::name('operation_execution_intents')->insert([
            'id' => 90, 'tenant_id' => 1, 'source_module' => 'operation_alert',
            'source_record_id' => $alertId, 'hotel_id' => 7, 'status' => 'pending_approval',
            'evidence_json' => $this->alertDigestEvidence($same[0]),
        ]);
        $changed = $base;
        $changed['message'] = 'Observed 1.8%';
        $changed['raw_data']['observed_value'] = 1.8;
        $refreshed = $this->invokeNonPublic($service, 'persistRuleAlerts', [[$changed]]);
        self::assertSame($alertId, (int)$refreshed[0]['id']);
        self::assertSame('unread', $refreshed[0]['status']);

        $bridged = $this->invokeNonPublic($service, 'attachAlertExecutionBridges', [[$refreshed[0]], true, true]);
        self::assertFalse($bridged[0]['task_bridge']['linked']);
        self::assertTrue($bridged[0]['task_bridge']['can_convert']);
    }

    public function testMySqlDedupeIndexContractRejectsCompositeAndPrefixUniqueIndexes(): void
    {
        $service = new OperationManagementService();
        $exact = [[
            'Key_name' => 'uniq_alert_dedupe', 'Non_unique' => 0, 'Seq_in_index' => 1,
            'Column_name' => 'monitor_dedupe_key', 'Sub_part' => null,
        ]];
        $composite = array_merge($exact, [[
            'Key_name' => 'uniq_alert_dedupe', 'Non_unique' => 0, 'Seq_in_index' => 2,
            'Column_name' => 'tenant_id', 'Sub_part' => null,
        ]]);
        $prefix = $exact;
        $prefix[0]['Sub_part'] = 64;

        self::assertTrue($this->invokeNonPublic($service, 'operationAlertMySqlIndexRowsHaveExactDedupeUnique', [$exact]));
        self::assertFalse($this->invokeNonPublic($service, 'operationAlertMySqlIndexRowsHaveExactDedupeUnique', [$composite]));
        self::assertFalse($this->invokeNonPublic($service, 'operationAlertMySqlIndexRowsHaveExactDedupeUnique', [$prefix]));
    }

    /** @param array<string,mixed> $alert */
    private function alertDigestEvidence(array $alert): string
    {
        return json_encode([
            'source_snapshot_digest' => SourceBackedExecutionIntentIdentityService::operationAlertSnapshotDigest($alert),
        ], JSON_THROW_ON_ERROR);
    }

    public function testAlertBridgeIgnoresForeignHotelIntentAndKeepsConversionAvailable(): void
    {
        Db::name('operation_execution_intents')->insert([
            'id' => 31,
            'tenant_id' => 2,
            'source_module' => 'operation_alert',
            'source_record_id' => 303,
            'hotel_id' => 9,
            'status' => 'blocked',
        ]);
        $service = new OperationManagementService();

        $alerts = $this->invokeNonPublic($service, 'attachAlertExecutionBridges', [[
            [
                'id' => 303,
                'tenant_id' => 1,
                'hotel_id' => 7,
                'source' => 'rule',
                'alert_type' => 'conversion_low',
                'related_date' => '2026-07-19',
                'raw_data' => [
                    'metric_key' => 'ota_conversion_rate',
                    'threshold_value' => 3,
                    'observed_value' => 2.4,
                    'comparison_rule' => '0 < observed_value < threshold_value',
                ],
            ],
        ], true, true]);

        self::assertFalse($alerts[0]['task_bridge']['linked']);
        self::assertSame(0, $alerts[0]['task_bridge']['intent_id']);
        self::assertTrue($alerts[0]['task_bridge']['can_convert']);
    }

    public function testLegacyThresholdAlertWithoutStructuredEvidenceCannotConvert(): void
    {
        $service = new OperationManagementService();

        $alerts = $this->invokeNonPublic($service, 'attachAlertExecutionBridges', [[
            [
                'id' => 404,
                'tenant_id' => 1,
                'hotel_id' => 7,
                'source' => 'rule',
                'alert_type' => 'conversion_low',
                'related_date' => '2026-07-19',
                'raw_data' => [],
            ],
        ], true, true]);

        self::assertFalse($alerts[0]['task_bridge']['can_convert']);
        self::assertStringContainsString('缺少实际阈值或观测值', $alerts[0]['task_bridge']['unavailable_reason']);
    }

    public function testViewOnlyUserCannotConvertEvidenceReadyAlert(): void
    {
        $service = new OperationManagementService();

        $alerts = $this->invokeNonPublic($service, 'attachAlertExecutionBridges', [[
            [
                'id' => 405,
                'tenant_id' => 1,
                'hotel_id' => 7,
                'source' => 'rule',
                'alert_type' => 'traffic_zero',
                'related_date' => '2026-07-19',
                'raw_data' => [
                    'metric_key' => 'ota_exposure',
                    'threshold_value' => 0,
                    'observed_value' => 0,
                    'comparison_rule' => 'observed_value <= threshold_value',
                ],
            ],
        ], true, false]);

        self::assertFalse($alerts[0]['task_bridge']['can_convert']);
        self::assertStringContainsString('只有查看权限', $alerts[0]['task_bridge']['unavailable_reason']);
    }
}
