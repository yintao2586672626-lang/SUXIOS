<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperationActionLifecycleService;
use app\service\OperatingOpportunityLabService;
use app\service\WeeklyOperatingPlanSnapshotService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class WeeklyOperatingPlanReviewCompletionTest extends TestCase
{
    private static array $databaseConfig;
    private static string $databasePath;
    private array $intent;
    private array $task;

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$databaseConfig = Config::get('database');
        self::$databasePath = tempnam(sys_get_temp_dir(), 'weekly-review-');
        $config = self::$databaseConfig;
        $config['default'] = 'weekly_review_fixture';
        $config['connections']['weekly_review_fixture'] = [
            'type' => 'sqlite', 'database' => self::$databasePath,
            'prefix' => '', 'fields_strict' => false,
        ];
        Config::set($config, 'database');
        Db::connect(null, true);
        Db::execute('CREATE TABLE operation_execution_intents (id INTEGER PRIMARY KEY, tenant_id INTEGER, hotel_id INTEGER, source_module TEXT, status TEXT, target_value_json TEXT)');
        Db::execute('CREATE TABLE operation_execution_tasks (id INTEGER PRIMARY KEY, intent_id INTEGER, tenant_id INTEGER, hotel_id INTEGER, status TEXT, result_status TEXT, deleted_at TEXT)');
        Db::execute('CREATE TABLE operation_action_lifecycle_events (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, intent_id INTEGER, task_id INTEGER, sequence_no INTEGER, event_type TEXT, from_status TEXT, to_status TEXT, actor_id INTEGER, event_payload_json TEXT, previous_digest TEXT, content_digest TEXT, created_at TEXT)');
        Db::execute('CREATE TABLE operation_action_reviews (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, intent_id INTEGER, task_id INTEGER, effect_review_id INTEGER, contract_version TEXT, metric_key TEXT, metric_unit TEXT, baseline_window_json TEXT, followup_window_json TEXT, before_value REAL, after_value REAL, delta_value REAL, metric_change_status TEXT, evidence_sufficiency TEXT, evidence_refs_json TEXT, non_attribution_reasons_json TEXT, recommendation TEXT, result_status TEXT, result_summary TEXT, causality_claimed INTEGER, reviewed_by INTEGER, reviewed_at TEXT, previous_review_id INTEGER, previous_digest TEXT, content_digest TEXT, created_at TEXT)');
    }

    public static function tearDownAfterClass(): void
    {
        Db::connect()->close();
        Config::set(self::$databaseConfig, 'database');
        Db::connect(null, true);
        unlink(self::$databasePath);
    }

    protected function setUp(): void
    {
        foreach (['operation_action_lifecycle_events', 'operation_action_reviews', 'operation_execution_tasks', 'operation_execution_intents'] as $table) {
            Db::name($table)->delete(true);
        }
        $this->intent = [
            'id' => 501, 'tenant_id' => 80, 'hotel_id' => 80,
            'source_module' => 'daily_one_thing', 'status' => 'approved',
            'expected_metric' => 'ctrip_strict_core_fact_count',
            'date_start' => '2026-08-26', 'date_end' => '2026-08-26',
            'target_value' => ['action_card' => [
                'contract_version' => 'operation_action_card.v2',
                'metric_contract' => ['unit' => 'verified_fields', 'target_type' => 'observation'],
            ]],
        ];
        $this->task = ['id' => 601, 'intent_id' => 501, 'tenant_id' => 80, 'hotel_id' => 80,
            'status' => 'executed', 'result_status' => 'observing', 'deleted_at' => null];
        Db::name('operation_execution_intents')->insert([
            'id' => 501, 'tenant_id' => 80, 'hotel_id' => 80,
            'source_module' => 'daily_one_thing', 'status' => 'approved',
            'target_value_json' => json_encode($this->intent['target_value']),
        ]);
        Db::name('operation_execution_tasks')->insert($this->task);
        $previous = '';
        foreach (['draft', 'pending_approval', 'approved', 'executing', 'evidence_recorded', 'review_pending'] as $status) {
            (new OperationActionLifecycleService())->appendEvent($this->intent, 601, $previous, $status, $status, 7);
            $previous = $status;
        }
    }

    public function testVerifiedObservingReviewCountsAsCompletedAndPersistsExactly(): void
    {
        $this->saveReview();
        $rows = [];
        $service = $this->snapshotService($rows);
        $saved = $service->generateAndReadback(80, 80, '2026-08-30');
        self::assertSame(1, $saved['lifecycle_summary']['reviewed']);
        self::assertSame(0, $saved['lifecycle_summary']['review_pending']);
        self::assertNotSame('review_pending', $saved['selected_focus']['type']);
        self::assertSame('observing', Db::name('operation_execution_tasks')->value('result_status'));
        $exact = $service->readExact(80, 80, $saved['snapshot_id']);
        self::assertSame($saved['snapshot_fingerprint'], $exact['snapshot_fingerprint']);
        self::assertSame($saved['lifecycle_summary'], $exact['lifecycle_summary']);
    }

    public function testBareReviewedProjectionCannotSubstituteForSavedReview(): void
    {
        $this->task['action_management'] = ['lifecycle' => ['status' => 'reviewed']];
        $this->task['_weekly_review_completion'] = ['completed' => true];
        $draft = $this->draft();
        self::assertSame(0, $draft['lifecycle_summary']['reviewed']);
        self::assertSame(1, $draft['lifecycle_summary']['review_pending']);
    }

    public function testMissingOrCorruptReviewCannotClearTheBacklog(): void
    {
        $this->saveReview();
        Db::name('operation_action_reviews')->where('intent_id', 501)->update(['result_summary' => 'tampered']);
        $draft = $this->draft();
        self::assertSame('blocked_by_source_errors', $draft['status']);
        self::assertSame(0, $draft['lifecycle_summary']['reviewed']);
        Db::name('operation_action_reviews')->delete(true);
        $draft = $this->draft();
        self::assertSame('blocked_by_source_errors', $draft['status']);
        self::assertSame(0, $draft['lifecycle_summary']['reviewed']);
    }

    public function testCorruptEventAndWrongReviewReferenceCannotClearBacklog(): void
    {
        $this->saveReview();
        Db::name('operation_action_lifecycle_events')->where('to_status', 'reviewed')->update(['event_payload_json' => '{"review_ref":"operation_action_reviews#999"}']);
        self::assertSame('blocked_by_source_errors', $this->draft()['status']);
    }

    public function testValidEventChainStillRequiresTheExactSavedTaskReview(): void
    {
        $this->saveReview();
        (new OperationActionLifecycleService())->appendEvent(
            $this->intent, 601, 'reviewed', 'reviewed', 'review_reassessed', 7,
            ['review_ref' => 'operation_action_reviews#999']
        );
        self::assertSame('blocked_by_source_errors', $this->draft()['status']);
        self::assertSame(0, $this->draft()['lifecycle_summary']['reviewed']);
    }

    public function testReviewCannotBeReusedAcrossHotelScope(): void
    {
        $this->saveReview();
        $this->task['hotel_id'] = 81;
        $draft = $this->draft();
        self::assertSame('blocked_by_source_errors', $draft['status']);
        self::assertSame(0, $draft['lifecycle_summary']['reviewed']);
    }

    public function testReviewEvidenceChangesInvalidateAnOtherwiseIdenticalSnapshot(): void
    {
        $this->saveReview();
        $rows = [];
        $service = $this->snapshotService($rows);
        $first = $service->generateAndReadback(80, 80, '2026-08-30');
        $this->saveReview('reviewed');
        $next = $service->generateAndReadback(80, 80, '2026-08-30');
        self::assertNotSame($first['source_digest'], $next['source_digest']);
        self::assertNotSame($first['snapshot_id'], $next['snapshot_id']);
        self::assertSame($first['lifecycle_summary'], $next['lifecycle_summary']);
        self::assertTrue($service->generateAndReadback(80, 80, '2026-08-30')['idempotent_replay']);
    }

    public function testLegacyTerminalTasksRetainTheirCompletedClassification(): void
    {
        unset($this->intent['target_value']);
        $this->task['result_status'] = 'success';
        $draft = $this->draft();
        self::assertSame(1, $draft['lifecycle_summary']['reviewed']);
        self::assertSame(0, $draft['lifecycle_summary']['review_pending']);
    }

    #[DataProvider('naturalOutcomes')]
    public function testNaturalOutcomePersistsAndReadsBackAsObservation(int $after, string $change): void
    {
        $fields = [['key' => 'collected_at', 'value' => '2026-08-27 11:00:00']];
        foreach (array_slice(['revenue', 'order_count', 'room_nights', 'exposure', 'visits', 'conversion'], 0, $after) as $key) {
            $fields[] = ['key' => $key, 'status' => 'strict_readback',
                'identity_binding_verified' => true, 'strict_final_gate' => true];
        }
        $readback = (new \ReflectionMethod(OperatingOpportunityLabService::class, 'strictFactCountReadbackFromClosure'))
            ->invoke(new OperatingOpportunityLabService(),
                $this->task + ['executed_at' => '2026-08-27 10:00:00'],
                $this->intent + ['source_record_id' => 901],
                ['expected_observation_metric' => ['baseline_value' => 4]],
                ['tenant_id' => 80, 'hotel_id' => 80, 'business_date' => '2026-08-26',
                    'platforms' => ['ctrip' => ['fields' => $fields, 'current_receipt_record_ids' => [701]]]]);
        self::assertIsArray($readback);
        $this->saveReview('review_pending', $readback);
        $review = (new OperationActionLifecycleService())->reviewsForIntent(80, 80, 501)[0];
        self::assertSame('observing', $review['result_status']);
        self::assertSame($change, $review['metric_change_status']);
        self::assertSame((float)$after, $review['after_value']);
        self::assertSame('sufficient', $review['evidence_sufficiency']);
        self::assertFalse($review['causality_claimed']);
        self::assertSame('adjust', $review['recommendation']);
        self::assertSame(1, $this->draft()['lifecycle_summary']['reviewed']);
    }

    public static function naturalOutcomes(): array
    {
        return ['no improvement' => [4, 'unchanged'], 'deteriorated' => [3, 'decreased']];
    }

    private function saveReview(string $from = 'review_pending', ?array $readback = null): void
    {
        $lifecycle = new OperationActionLifecycleService();
        $review = $lifecycle->appendReview($this->intent, $this->task, [$readback ?? [
            'id' => 701, 'evidence_type' => 'source_verified_metric_readback',
            'before' => ['ctrip_strict_core_fact_count' => 4],
            'after' => ['ctrip_strict_core_fact_count' => 4],
            'platform_response' => [
                'readback_verified' => true, 'database_written' => true,
                'metric_key' => 'ctrip_strict_core_fact_count', 'metric_unit' => 'verified_fields',
            ],
        ]], 'observing', 'No change; observation completed.', 7, '2026-08-28 12:00:00');
        $lifecycle->appendEvent($this->intent, 601, $from, 'reviewed', 'reviewed', 7,
            ['review_ref' => 'operation_action_reviews#' . $review['id']]);
    }

    private function sources(): array
    {
        $sources = ['hotel_name' => 'Fixture', 'daily_runs' => [], 'broadcasts' => [],
            'intents' => [$this->intent], 'tasks' => [$this->task]];
        for ($i = 0; $i < 7; $i++) {
            $date = (new \DateTimeImmutable('2026-08-24'))->modify('+' . $i . ' days')->format('Y-m-d');
            $sources['daily_runs'][] = ['id' => 100 + $i, 'business_date' => $date];
            $sources['broadcasts'][] = ['id' => 200 + $i, 'business_date' => $date];
        }
        return $sources;
    }

    private function draft(): array
    {
        return (new WeeklyOperatingPlanSnapshotService())->buildDraft(80, 80, '2026-08-24', '2026-08-30', $this->sources());
    }

    private function snapshotService(array &$rows): WeeklyOperatingPlanSnapshotService
    {
        return new WeeklyOperatingPlanSnapshotService(
            sourceReader: fn() => $this->sources(),
            snapshotReader: static function (string $action, array $scope) use (&$rows) {
                if ($action === 'next_version') return count($rows) + 1;
                if ($action === 'exact') return $rows[$scope['id']] ?? null;
                foreach (array_reverse($rows, true) as $row) {
                    if ($action === 'latest' || $row['source_digest'] === $scope['source_digest']) return $row;
                }
                return null;
            },
            snapshotWriter: static function (array $row) use (&$rows): int {
                $id = count($rows) + 1;
                $rows[$id] = $row + ['id' => $id];
                return $id;
            },
            scopeVerifier: static fn() => true
        );
    }
}
