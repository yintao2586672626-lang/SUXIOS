<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenueCockpitApprovalService;
use app\service\RevenueCockpitStrictEvidenceService;
use app\service\RevenueDecisionSnapshotService;
use app\service\RevenueDecisionViewModelAttestationService;
use app\service\RevenueOverviewDateContract;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RevenueCockpitSnapshotGenerationTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        $app = new App(dirname(__DIR__));
        $app->setRuntimePath(sys_get_temp_dir() . '/suxios-snapshot-generation-' . getmypid() . '/');
        $app->initialize();
        $this->databasePath = sys_get_temp_dir() . '/suxios-snapshot-generation-' . getmypid() . '.sqlite';
        Config::set(['default' => 'generation_fixture', 'connections' => ['generation_fixture' => [
            'type' => 'sqlite', 'database' => $this->databasePath, 'prefix' => '', 'fields_strict' => false,
        ]]], 'database');
        Db::connect(null, true);
        self::assertSame($this->databasePath, Db::connect()->getConfig('database'));
        foreach ([
            'hotels (id INTEGER PRIMARY KEY,tenant_id INTEGER)',
            'operation_execution_intents (id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,idempotency_key TEXT UNIQUE,source_module TEXT,source_record_id INTEGER,hotel_id INTEGER,platform TEXT,object_type TEXT,action_type TEXT,date_start TEXT,date_end TEXT,current_value_json TEXT,target_value_json TEXT,evidence_json TEXT,expected_metric TEXT,expected_delta REAL,risk_level TEXT,blocked_reason TEXT,status TEXT,created_by INTEGER,approved_by INTEGER DEFAULT 0,approved_at TEXT,review_remark TEXT,created_at TEXT,updated_at TEXT,deleted_at TEXT)',
            'operation_execution_tasks (id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,intent_id INTEGER,hotel_id INTEGER,status TEXT,deleted_at TEXT)',
            'operation_execution_evidence (id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,task_id INTEGER,deleted_at TEXT)',
            'operation_action_lifecycle_events (id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,hotel_id INTEGER,intent_id INTEGER,task_id INTEGER,sequence_no INTEGER,event_type TEXT,from_status TEXT,to_status TEXT,actor_id INTEGER,event_payload_json TEXT,previous_digest TEXT,content_digest TEXT,created_at TEXT,UNIQUE(tenant_id,hotel_id,intent_id,sequence_no))',
            'operation_action_reviews (id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,hotel_id INTEGER,intent_id INTEGER,task_id INTEGER)',
            'revenue_decision_snapshots (id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,system_hotel_id INTEGER,platform TEXT,business_date TEXT,contract_version TEXT,source_refs_json TEXT,metric_definitions_json TEXT,visible_model_json TEXT,missing_items_json TEXT,evidence_summary_json TEXT,visible_model_digest TEXT,evidence_digest TEXT,content_digest TEXT,idempotency_key TEXT UNIQUE,created_by INTEGER,created_at TEXT)',
        ] as $schema) {
            Db::execute('CREATE TABLE ' . $schema);
        }
        Db::name('hotels')->insert(['id' => 20, 'tenant_id' => 10]);
    }

    protected function tearDown(): void
    {
        Db::connect()->close();
        if (is_file($this->databasePath)) {
            unlink($this->databasePath);
        }
    }

    public function testNonBaselineSourceChangeCreatesCurrentVersionAndRetriesReuseIt(): void
    {
        $oldOverview = $this->overview(600);
        $oldSnapshot = $this->snapshot($oldOverview);
        $old = $this->pending($oldSnapshot, $oldOverview);
        $newOverview = $this->overview(601);
        $newSnapshot = $this->snapshot($newOverview);
        $new = $this->pending($newSnapshot, $newOverview);
        $oldCard = $old['execution_intent']['evidence']['action_card'];
        $newCard = $new['execution_intent']['evidence']['action_card'];
        self::assertNotSame($oldSnapshot['content_digest'], $newSnapshot['content_digest']);
        self::assertSame($oldCard['trace']['cockpit_identity_digest'], $newCard['trace']['cockpit_identity_digest']);
        self::assertSame(400.0, (float)$newCard['metric_contract']['baseline_window']['value']);
        self::assertNotSame($old['execution_intent']['id'], $new['execution_intent']['id']);
        self::assertSame($newSnapshot['id'], $newCard['trace']['decision_snapshot_id']);
        $repeated = $this->pending($newSnapshot, $newOverview, 8);
        self::assertTrue($repeated['reused_existing_intent']);
        self::assertSame($new['execution_intent']['id'], $repeated['execution_intent']['id']);
        self::assertSame($newCard, $repeated['execution_intent']['evidence']['action_card']);
        $this->assertCounts(2);
    }

    public function testRestoredSourceReusesEarlierCurrentVersionAndPreservesBothHistories(): void
    {
        $oldOverview = $this->overview(600);
        $oldSnapshot = $this->snapshot($oldOverview);
        $old = $this->pending($oldSnapshot, $oldOverview);
        $newOverview = $this->overview(601);
        $newSnapshot = $this->snapshot($newOverview);
        $new = $this->pending($newSnapshot, $newOverview);
        $oldRow = Db::name('operation_execution_intents')->where('id', $old['execution_intent']['id'])->find();
        $newRow = Db::name('operation_execution_intents')->where('id', $new['execution_intent']['id'])->find();
        $restoredSnapshot = $this->snapshot($oldOverview);
        $restored = $this->pending($restoredSnapshot, $oldOverview);
        self::assertSame($oldSnapshot['id'], $restoredSnapshot['id']);
        self::assertSame($old['execution_intent']['id'], $restored['execution_intent']['id']);
        self::assertTrue($restored['reused_existing_intent']);
        self::assertSame($oldRow, Db::name('operation_execution_intents')->where('id', $oldRow['id'])->find());
        self::assertSame($newRow, Db::name('operation_execution_intents')->where('id', $newRow['id'])->find());
        $this->assertCounts(2);
    }

    public function testStaleCompleteSnapshotContextCannotCreateAnotherVersion(): void
    {
        $oldOverview = $this->overview(600);
        $old = $this->pending($this->snapshot($oldOverview), $oldOverview);
        $card = $old['execution_intent']['evidence']['action_card'];
        $actionContext = [
            'opportunity_key' => $card['trace']['opportunity_key'],
            'action_title' => $card['action']['title'],
            'action_object' => $card['action']['object'],
            'action_description' => $card['action']['description'],
            'reason' => $card['reason'],
            'decision_snapshot_id' => $card['trace']['decision_snapshot_id'],
            'decision_snapshot_digest' => $card['trace']['decision_snapshot_digest'],
            'opportunity_digest' => $card['trace']['opportunity_digest'],
        ];
        try {
            (new RevenueCockpitApprovalService())->createFromOverview(
                $this->overview(601), 10, 20, '2026-08-20', 'meituan', 7,
                $actionContext
            );
            self::fail('Historical full-source lineage was accepted for a new version');
        } catch (\RuntimeException $exception) {
            self::assertSame('revenue_decision_snapshot_action_lineage_stale', $exception->getMessage());
        }
        $this->assertCounts(1);
    }

    public function testCorruptCardCannotBeSkippedAsOrdinaryHistoricalSource(): void
    {
        $oldOverview = $this->overview(600);
        $old = $this->pending($this->snapshot($oldOverview), $oldOverview);
        $row = Db::name('operation_execution_intents')->where('id', $old['execution_intent']['id'])->find();
        $target = json_decode($row['target_value_json'], true, 512, JSON_THROW_ON_ERROR);
        $target['action_card']['content_digest'] = str_repeat('0', 64);
        Db::name('operation_execution_intents')->where('id', $row['id'])->update([
            'target_value_json' => json_encode($target, JSON_THROW_ON_ERROR),
        ]);
        $newOverview = $this->overview(601);
        try {
            $this->pending($this->snapshot($newOverview), $newOverview);
            self::fail('Corrupt existing card was bypassed');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('行动卡内容摘要不一致，请基于当前事实重新生成', $exception->getMessage());
        }
        $this->assertCounts(1);
    }

    private function assertCounts(int $intents): void
    {
        self::assertSame($intents, Db::name('operation_execution_intents')->count());
        self::assertSame(0, Db::name('operation_execution_tasks')->count());
        self::assertSame(0, Db::name('operation_execution_evidence')->count());
        self::assertSame(0, Db::name('operation_execution_intents')->where('approved_by', '>', 0)->count());
    }

    private function overview(int $revenue): array
    {
        // Existing synthetic source proof; never read an actual hotel database.
        $source = new RevenueCockpitApprovalServiceTest('testAllOtaApprovalUsesSeparateVerifiedSourceRefsAndStopsBeforeExecution');
        $overview = (new \ReflectionMethod($source, 'overview'))->invoke($source);
        foreach ($overview['dual_ota_field_closure']['platforms'] as $platform => &$closure) {
            $closure['fields'] = [];
            foreach ($overview['cockpit_strict_evidence']['platforms'][$platform]['metrics'] as $key => $proof) {
                $closure['fields'][] = ['metric_key' => $key, 'consumer_metric_keys' => [$key],
                    'source_record_ids' => $proof['accepted_row_ids'], 'revenue_analysis_consumable' => true,
                    'strict_final_gate' => true, 'readback_status' => 'readback_verified', 'status' => 'readback_verified'];
            }
        }
        unset($closure);
        $overview['cockpit_strict_evidence'] = (new RevenueCockpitStrictEvidenceService())->build(
            $overview, 10, 20, '2026-08-20', 'meituan', $overview['dual_ota_field_closure']
        );
        $overview['three_source_fact_layer']['sources']['meituan_ota']['facts']['revenue'] = $revenue;
        foreach ($overview['cockpit_strict_evidence']['platforms'] as $platform => $proof) {
            foreach ($proof['metrics'] as $key => $metric) {
                if (($metric['strict_readback'] ?? false) && ($metric['accepted_row_ids'] ?? []) !== []
                    && is_numeric($overview['three_source_fact_layer']['sources'][$platform . '_ota']['facts'][$key] ?? null)) {
                    $overview['three_source_fact_layer']['sources'][$platform . '_ota']['fact_statuses'][$key] = [
                        'status' => 'readback_verified', 'reason' => 'local_synthetic_fixture_only',
                    ];
                }
            }
        }
        $overview['as_of_date'] = RevenueOverviewDateContract::serverAsOfDate();
        $overview['as_of_date_contract_version'] = RevenueOverviewDateContract::VERSION;
        return $overview;
    }

    private function snapshot(array $overview): array
    {
        $comparisons = ['previous_date' => '', 'same_weekday_date' => '', 'previous_overview' => [],
            'same_weekday_overview' => [], 'selected_platform_label' => '美团',
            'latest_verified_date' => '2026-08-20', 'scope_notice' => 'local_synthetic_fixture_only'];
        $context = (new RevenueCockpitApprovalService())->evidenceContext($overview, 10, 20, '2026-08-20', 'meituan');
        $model = (new RevenueDecisionViewModelAttestationService())->issue(
            $overview, $comparisons, $context, 10, 20, '2026-08-20', 'meituan'
        );
        return (new RevenueDecisionSnapshotService())->saveFromOverview(
            $overview, 10, 20, '2026-08-20', 'meituan', 7, $model, $comparisons
        );
    }

    private function pending(array $snapshot, array $overview, int $actor = 7): array
    {
        return (new RevenueDecisionSnapshotService())->createOpportunityPendingApproval(
            (int)$snapshot['id'], 10, 20, $actor, 'traffic_entry_shortage', $overview
        );
    }
}
