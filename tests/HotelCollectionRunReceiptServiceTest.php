<?php
declare(strict_types=1);

namespace tests;

use app\service\DualOtaPageVerificationService;
use app\service\HotelCollectionRunReceiptService;
use app\service\OtaCollectionAnchorService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class HotelCollectionRunReceiptServiceTest extends TestCase
{
    private const TENANT_ID = 8;
    private const HOTEL_ID = 80;
    private const BUSINESS_DATE = '2026-08-09';
    private const CTRIP_SOURCE_ID = 25;
    private const MEITUAN_SOURCE_ID = 68;

    private static array $databaseConfig;
    private static string $databasePath;

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$databaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'hotel_collection_run_receipt_' . getmypid() . '.sqlite';
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
        foreach ([
            'operation_logs',
            'hotel_collection_plan_run_sources',
            'hotel_collection_plan_runs',
            'dingdandao_operating_target_captures',
            'ota_local_collector_tasks',
            'ota_local_collector_account_hotels',
            'ota_local_collector_accounts',
            'ota_local_collector_devices',
            'online_daily_data',
            'platform_data_raw_records',
            'platform_data_sync_tasks',
            'platform_data_sources',
            'hotel_collection_plans',
            'hotels',
        ] as $table) {
            Db::name($table)->delete(true);
        }
        $this->seedScope();
    }

    public function testMigrationDefinesParentAndTwoSourceContract(): void
    {
        $migration = (string)file_get_contents(
            dirname(__DIR__)
            . '/database/migrations/20260810_zz_create_hotel_collection_run_receipts.sql'
        );

        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS `hotel_collection_plan_runs`',
            $migration
        );
        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS `hotel_collection_plan_run_sources`',
            $migration
        );
        self::assertStringContainsString('`collection_anchor_hash` char(64) DEFAULT NULL', $migration);
        self::assertStringContainsString('`platform_sync_task_id` bigint unsigned DEFAULT NULL', $migration);
        self::assertStringContainsString('`local_collector_task_id` bigint unsigned DEFAULT NULL', $migration);
        self::assertStringContainsString(
            'UNIQUE KEY `uq_hotel_collection_plan_run_source` (`run_id`, `platform`)',
            $migration
        );
    }

    public function testBlockedBeginPersistsParentAndTwoOtaChildrenWithoutAnchor(): void
    {
        $dispatcherRunId = '11111111-1111-4111-8111-111111111111';
        $service = new HotelCollectionRunReceiptService();

        $service->begin($this->gate($dispatcherRunId, false));

        $parent = $this->parent($dispatcherRunId);
        $children = $this->children((int)$parent['id']);

        self::assertSame('blocked', (string)$parent['status']);
        self::assertSame('plan_not_execution_ready', (string)$parent['failure_code']);
        self::assertNull($parent['collection_anchor_hash']);
        self::assertCount(2, $children);
        self::assertSame(['ctrip', 'meituan'], array_column($children, 'platform'));
        self::assertSame(['blocked', 'blocked'], array_column($children, 'status'));
        foreach ($children as $child) {
            self::assertNull($child['platform_sync_task_id']);
            self::assertNull($child['local_collector_task_id']);
        }
    }

    public function testPublicReadbackSeparatesLedgerStructureFromActiveAndTerminalState(): void
    {
        $service = new HotelCollectionRunReceiptService();
        $startedRunId = '11111111-1111-4111-8111-111111111112';
        $started = $service->begin($this->gate($startedRunId, true));

        self::assertSame('started', $started['status']);
        self::assertTrue($started['ledger_structure_verified']);
        self::assertFalse($started['readback_verified']);

        $runningResult = $this->platformResult(
            $startedRunId,
            'ctrip',
            self::CTRIP_SOURCE_ID,
            590,
            [],
            false,
            false,
            0,
            0
        );
        $runningResult['status'] = 'running';
        $inProgress = $service->recordPlatformResults(
            $startedRunId,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            [$runningResult]
        );
        self::assertSame('in_progress', $inProgress['status']);
        self::assertTrue($inProgress['ledger_structure_verified']);
        self::assertFalse($inProgress['readback_verified']);

        $blockedRunId = '11111111-1111-4111-8111-111111111113';
        $blocked = $service->begin($this->gate($blockedRunId, false));
        self::assertSame('blocked', $blocked['status']);
        self::assertTrue($blocked['ledger_structure_verified']);
        self::assertTrue($blocked['readback_verified']);

        $blockedParent = $this->parent($blockedRunId);
        Db::name('hotel_collection_plan_run_sources')
            ->where('run_id', (int)$blockedParent['id'])
            ->where('platform', 'ctrip')
            ->update(['finished_at' => null]);
        $inconsistent = $service->readExact(
            $blockedRunId,
            self::HOTEL_ID,
            self::BUSINESS_DATE
        );
        self::assertTrue($inconsistent['ledger_structure_verified']);
        self::assertFalse($inconsistent['readback_verified']);
    }

    public function testExactNoCollectionAndIncompleteTerminalReceiptsRemainReadable(): void
    {
        $service = new HotelCollectionRunReceiptService();
        $noCollectionRunId = '11111111-1111-4111-8111-111111111114';
        $service->begin($this->gate($noCollectionRunId, true));
        $noCollection = $service->markNoCollectionOutcome(
            $noCollectionRunId,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            'retry_cooldown'
        );
        self::assertTrue($noCollection['ledger_structure_verified']);
        self::assertTrue($noCollection['readback_verified']);

        $partialRunId = '11111111-1111-4111-8111-111111111115';
        $prepared = $this->prepareDualSuccess($partialRunId, 14900, 24900);
        $partial = $prepared['service']->finalizeCollection(
            $partialRunId,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            $prepared['receipt'],
            false
        );
        self::assertSame('partial', $partial['status']);
        self::assertTrue($partial['ledger_structure_verified']);
        self::assertTrue($partial['readback_verified']);

        Db::name('hotel_collection_plan_runs')
            ->where('id', (int)$partial['id'])
            ->update(['finished_at' => null]);
        $nonTerminal = $prepared['service']->readExact(
            $partialRunId,
            self::HOTEL_ID,
            self::BUSINESS_DATE
        );
        self::assertTrue($nonTerminal['ledger_structure_verified']);
        self::assertFalse($nonTerminal['readback_verified']);
    }

    public function testSucceededReadbackFailsClosedWhenCountsOrTrustEvidenceDrift(): void
    {
        $dispatcherRunId = '11111111-1111-4111-8111-111111111116';
        $prepared = $this->finalizeDualSuccess($dispatcherRunId, 14920, 24920);
        $verified = $prepared['service']->readExact(
            $dispatcherRunId,
            self::HOTEL_ID,
            self::BUSINESS_DATE
        );
        self::assertTrue($verified['ledger_structure_verified']);
        self::assertTrue($verified['readback_verified']);
        $verifiedSources = array_column($verified['source_receipts'], null, 'platform');
        $ctripSource = $verifiedSources['ctrip'];

        Db::name('hotel_collection_plan_run_sources')
            ->where('run_id', (int)$verified['id'])
            ->where('platform', 'ctrip')
            ->update(['saved_row_count' => 0]);
        $badCounts = $prepared['service']->readExact(
            $dispatcherRunId,
            self::HOTEL_ID,
            self::BUSINESS_DATE
        );
        self::assertTrue($badCounts['ledger_structure_verified']);
        self::assertFalse($badCounts['readback_verified']);

        Db::name('hotel_collection_plan_run_sources')
            ->where('run_id', (int)$verified['id'])
            ->where('platform', 'ctrip')
            ->update(['saved_row_count' => (int)$ctripSource['saved_row_count']]);
        Db::name('hotel_collection_plan_run_sources')
            ->where('run_id', (int)$verified['id'])
            ->where('platform', 'ctrip')
            ->update([
                'platform_sync_task_id' => (int)$ctripSource['platform_sync_task_id'] + 1,
            ]);
        $badTask = $prepared['service']->readExact(
            $dispatcherRunId,
            self::HOTEL_ID,
            self::BUSINESS_DATE
        );
        self::assertTrue($badTask['ledger_structure_verified']);
        self::assertFalse($badTask['readback_verified']);

        Db::name('hotel_collection_plan_run_sources')
            ->where('run_id', (int)$verified['id'])
            ->where('platform', 'ctrip')
            ->update([
                'platform_sync_task_id' => (int)$ctripSource['platform_sync_task_id'],
            ]);
        Db::name('hotel_collection_plan_runs')
            ->where('id', (int)$verified['id'])
            ->update(['trust_receipt_digest' => str_repeat('f', 64)]);
        $badTrust = $prepared['service']->readExact(
            $dispatcherRunId,
            self::HOTEL_ID,
            self::BUSINESS_DATE
        );
        self::assertTrue($badTrust['ledger_structure_verified']);
        self::assertFalse($badTrust['readback_verified']);
    }

    public function testPageAndPmsPublicReadbackHideInconsistentSidecarEvidence(): void
    {
        $dispatcherRunId = '11111111-1111-4111-8111-111111111117';
        $prepared = $this->prepareDualSuccess(
            $dispatcherRunId,
            14940,
            24940,
            'dingdandao_pms'
        );
        $prepared['service']->finalizeCollection(
            $dispatcherRunId,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            $prepared['receipt'],
            true
        );
        $contract = $this->pageContract($prepared);
        $confirmed = $this->insertPageEvidence($contract);
        $prepared['service']->recordPageAcceptance(
            self::TENANT_ID,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            $confirmed,
            $contract
        );
        $this->seedPmsCapture(8091);
        $verified = $prepared['service']->recordPmsCapture(
            $dispatcherRunId,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            'dingdandao_pms',
            8091
        );
        self::assertTrue($verified['page_acceptance']['readback_verified']);
        self::assertTrue($verified['pms_receipt']['readback_verified']);

        Db::name('hotel_collection_plan_run_sources')
            ->where('run_id', (int)$verified['id'])
            ->where('platform', 'ctrip')
            ->update(['page_acceptance_log_id' => (int)$confirmed['receipt_id'] + 1]);
        Db::name('hotel_collection_plan_runs')
            ->where('id', (int)$verified['id'])
            ->update(['pms_provider' => 'foreign_pms']);

        $inconsistent = $prepared['service']->readExact(
            $dispatcherRunId,
            self::HOTEL_ID,
            self::BUSINESS_DATE
        );
        self::assertSame('conflict', $inconsistent['page_acceptance']['status']);
        self::assertFalse($inconsistent['page_acceptance']['readback_verified']);
        self::assertNull($inconsistent['page_acceptance']['receipt_id']);
        self::assertNull($inconsistent['page_acceptance']['contract_hash']);
        foreach ($inconsistent['source_receipts'] as $sourceReceipt) {
            self::assertSame('conflict', $sourceReceipt['page_acceptance_status']);
            self::assertNull($sourceReceipt['page_acceptance_log_id']);
        }
        self::assertSame('conflict', $inconsistent['pms_receipt']['status']);
        self::assertFalse($inconsistent['pms_receipt']['readback_verified']);
        self::assertNull($inconsistent['pms_receipt']['provider']);
        self::assertNull($inconsistent['pms_receipt']['capture_id']);
        self::assertTrue($inconsistent['readback_verified']);
    }

    public function testBeginIsIdempotentForSameScopeAndRejectsHotelDateOrSourceDrift(): void
    {
        $dispatcherRunId = '22222222-2222-4222-8222-222222222222';
        $service = new HotelCollectionRunReceiptService();
        $gate = $this->gate($dispatcherRunId, true);

        $service->begin($gate);
        $service->begin($gate);

        self::assertSame(
            1,
            Db::name('hotel_collection_plan_runs')
                ->where('dispatcher_run_id', $dispatcherRunId)
                ->count()
        );
        $parent = $this->parent($dispatcherRunId);
        self::assertCount(2, $this->children((int)$parent['id']));

        $this->assertScopeRejected(
            fn() => $service->begin($this->gate(
                $dispatcherRunId,
                true,
                81,
                self::BUSINESS_DATE,
                125,
                168
            ))
        );
        $this->assertScopeRejected(
            fn() => $service->begin($this->gate(
                $dispatcherRunId,
                true,
                self::HOTEL_ID,
                '2026-08-08'
            ))
        );
        $this->assertScopeRejected(
            fn() => $service->begin($this->gate(
                $dispatcherRunId,
                true,
                self::HOTEL_ID,
                self::BUSINESS_DATE,
                26,
                self::MEITUAN_SOURCE_ID
            ))
        );

        self::assertSame(1, Db::name('hotel_collection_plan_runs')->count());
        self::assertSame(2, Db::name('hotel_collection_plan_run_sources')->count());
    }

    public function testChangedPlanScopeSealsTheActiveRunWithoutBorrowingOrDeletingProducerEvidence(): void
    {
        $dispatcherRunId = '22333333-3333-4333-8333-333333333333';
        $service = new HotelCollectionRunReceiptService();
        $service->begin($this->gate($dispatcherRunId, true));
        $running = $this->platformResult(
            $dispatcherRunId,
            'ctrip',
            self::CTRIP_SOURCE_ID,
            591,
            [],
            false,
            false,
            0,
            0
        );
        $running['status'] = 'running';
        $service->recordPlatformResults(
            $dispatcherRunId,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            [$running]
        );
        $parentBefore = $this->parent($dispatcherRunId);
        $childrenBefore = array_column(
            $this->children((int)$parentBefore['id']),
            null,
            'platform'
        );

        $changedGate = $this->gate($dispatcherRunId, true);
        $changedGate['plan_version'] = 2;
        $changedGate['plan_hash'] = str_repeat('c', 64);
        $changedGate['scope_hash'] = hash('sha256', 'changed-plan-scope');
        $this->assertRuntimeFailure(
            'hotel_collection_run_receipt_scope_mismatch',
            static fn() => $service->begin($changedGate)
        );

        $blocked = $service->blockScopeChangedDuringActiveRun(
            $dispatcherRunId,
            self::HOTEL_ID,
            self::BUSINESS_DATE
        );
        self::assertSame('blocked', $blocked['status']);
        self::assertSame('plan_gate', $blocked['failure_stage']);
        self::assertSame('plan_scope_changed_during_active_run', $blocked['failure_code']);
        self::assertNull($blocked['collection_anchor_hash']);
        self::assertNull($blocked['trust_receipt_digest']);
        self::assertNotSame('', (string)$blocked['finished_at']);
        self::assertTrue($blocked['ledger_structure_verified']);
        self::assertFalse($blocked['readback_verified']);

        $parentAfter = $this->parent($dispatcherRunId);
        $childrenAfter = array_column(
            $this->children((int)$parentAfter['id']),
            null,
            'platform'
        );
        foreach (['ctrip', 'meituan'] as $platform) {
            self::assertSame('blocked', (string)$childrenAfter[$platform]['status']);
            self::assertSame('plan_gate', (string)$childrenAfter[$platform]['failure_stage']);
            self::assertSame(
                'plan_scope_changed_during_active_run',
                (string)$childrenAfter[$platform]['failure_code']
            );
            foreach ([
                'data_source_id',
                'ingestion_method',
                'platform_sync_task_id',
                'local_collector_task_id',
                'saved_row_count',
                'readback_row_count',
                'readback_verified',
                'evidence_digest',
            ] as $field) {
                self::assertSame(
                    $childrenBefore[$platform][$field],
                    $childrenAfter[$platform][$field],
                    $platform . ':' . $field
                );
            }
        }

        $replayed = $service->blockScopeChangedDuringActiveRun(
            $dispatcherRunId,
            self::HOTEL_ID,
            self::BUSINESS_DATE
        );
        self::assertSame($blocked, $replayed);
        self::assertSame($parentAfter, $this->parent($dispatcherRunId));
        self::assertSame(
            array_values($childrenAfter),
            $this->children((int)$parentAfter['id'])
        );
    }

    public function testVerifiedPmsCaptureIsIdempotentAndCannotChangeOtaTrustState(): void
    {
        $dispatcherRunId = '2d000000-0000-4000-8000-000000000001';
        $prepared = $this->prepareDualSuccess(
            $dispatcherRunId,
            15000,
            25000,
            'dingdandao_pms'
        );
        $service = $prepared['service'];
        $service->finalizeCollection(
            $dispatcherRunId,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            $prepared['receipt'],
            true
        );
        $this->seedPmsCapture(8101);
        $this->seedPmsCapture(8102);
        $before = $this->parent($dispatcherRunId);
        $childrenBefore = $this->children((int)$before['id']);

        $receipt = $service->recordPmsCapture(
            $dispatcherRunId,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            'dingdandao_pms',
            8101
        );
        $after = $this->parent($dispatcherRunId);

        self::assertSame('dingdandao_pms', $receipt['pms_receipt']['provider']);
        self::assertSame('verified', $receipt['pms_receipt']['status']);
        self::assertSame('8101', $receipt['pms_receipt']['capture_id']);
        self::assertTrue($receipt['pms_receipt']['readback_verified']);
        self::assertSame('succeeded', (string)$after['status']);
        self::assertSame((string)$before['collection_anchor_hash'], (string)$after['collection_anchor_hash']);
        self::assertSame((string)$before['trust_receipt_digest'], (string)$after['trust_receipt_digest']);
        self::assertSame($childrenBefore, $this->children((int)$before['id']));

        $beforeWithoutPms = $before;
        $afterWithoutPms = $after;
        foreach (['pms_status', 'pms_provider', 'pms_capture_id', 'pms_readback_verified'] as $field) {
            unset($beforeWithoutPms[$field], $afterWithoutPms[$field]);
        }
        self::assertSame($beforeWithoutPms, $afterWithoutPms);

        $replayed = $service->recordPmsCapture(
            $dispatcherRunId,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            'dingdandao_pms',
            8101
        );
        self::assertSame($receipt, $replayed);
        self::assertSame($after, $this->parent($dispatcherRunId));
        self::assertSame($childrenBefore, $this->children((int)$before['id']));

        $this->assertRuntimeFailure(
            'hotel_collection_run_pms_capture_conflict',
            static fn() => $service->recordPmsCapture(
                $dispatcherRunId,
                self::HOTEL_ID,
                self::BUSINESS_DATE,
                'dingdandao_pms',
                8102
            )
        );
        self::assertSame($after, $this->parent($dispatcherRunId));
        self::assertSame($childrenBefore, $this->children((int)$before['id']));
    }

    public function testPmsCaptureRejectsProviderScopeAndReadbackDriftWithoutMutation(): void
    {
        $dispatcherRunId = '2e000000-0000-4000-8000-000000000001';
        $service = new HotelCollectionRunReceiptService();
        $gate = $this->gate($dispatcherRunId, true);
        $gate['sources']['pms'] = ['provider' => 'dingdandao_pms'];
        $service->begin($gate);
        $before = $this->parent($dispatcherRunId);
        $childrenBefore = $this->children((int)$before['id']);
        $this->seedPmsCapture(8201, 81);
        $this->seedPmsCapture(8202, self::HOTEL_ID, '2026-08-08');
        $this->seedPmsCapture(8203, self::HOTEL_ID, self::BUSINESS_DATE, 'pending');
        $this->seedPmsCapture(8204);

        foreach ([8201, 8202] as $captureId) {
            $this->assertRuntimeFailure(
                'hotel_collection_run_pms_capture_scope_mismatch',
                static fn() => $service->recordPmsCapture(
                    $dispatcherRunId,
                    self::HOTEL_ID,
                    self::BUSINESS_DATE,
                    'dingdandao_pms',
                    $captureId
                )
            );
        }
        $this->assertRuntimeFailure(
            'hotel_collection_run_pms_capture_not_verified',
            static fn() => $service->recordPmsCapture(
                $dispatcherRunId,
                self::HOTEL_ID,
                self::BUSINESS_DATE,
                'dingdandao_pms',
                8203
            )
        );
        $this->assertRuntimeFailure(
            'hotel_collection_run_pms_provider_unsupported',
            static fn() => $service->recordPmsCapture(
                $dispatcherRunId,
                self::HOTEL_ID,
                self::BUSINESS_DATE,
                'dingdandao',
                8204
            )
        );
        self::assertSame($before, $this->parent($dispatcherRunId));
        self::assertSame($childrenBefore, $this->children((int)$before['id']));

        $providerMismatchRunId = '2e000000-0000-4000-8000-000000000002';
        $service->begin($this->gate($providerMismatchRunId, true));
        $providerMismatchBefore = $this->parent($providerMismatchRunId);
        $this->assertRuntimeFailure(
            'hotel_collection_run_pms_provider_mismatch',
            static fn() => $service->recordPmsCapture(
                $providerMismatchRunId,
                self::HOTEL_ID,
                self::BUSINESS_DATE,
                'dingdandao_pms',
                8204
            )
        );
        self::assertSame($providerMismatchBefore, $this->parent($providerMismatchRunId));
    }

    public function testNoCollectionOutcomesCloseParentAndBothSourcesWithoutBorrowingAnAnchor(): void
    {
        $cases = [
            'verified_cache_reused' => [
                'dispatcher_run_id' => '2a000000-0000-4000-8000-000000000001',
                'status' => 'skipped',
                'failure_stage' => 'scheduler_cache',
            ],
            'retry_cooldown' => [
                'dispatcher_run_id' => '2a000000-0000-4000-8000-000000000002',
                'status' => 'deferred',
                'failure_stage' => 'scheduler_retry',
            ],
            'retry_exhausted' => [
                'dispatcher_run_id' => '2a000000-0000-4000-8000-000000000003',
                'status' => 'blocked',
                'failure_stage' => 'scheduler_retry',
            ],
            'profile_locked' => [
                'dispatcher_run_id' => '2a000000-0000-4000-8000-000000000004',
                'status' => 'deferred',
                'failure_stage' => 'scheduler_lock',
            ],
        ];
        $service = new HotelCollectionRunReceiptService();

        foreach ($cases as $outcomeCode => $case) {
            $dispatcherRunId = (string)$case['dispatcher_run_id'];
            $service->begin($this->gate($dispatcherRunId, true));

            $first = $service->markNoCollectionOutcome(
                $dispatcherRunId,
                self::HOTEL_ID,
                self::BUSINESS_DATE,
                $outcomeCode
            );
            $parent = $this->parent($dispatcherRunId);
            $children = $this->children((int)$parent['id']);

            self::assertSame((string)$case['status'], (string)$parent['status'], $outcomeCode);
            self::assertSame(
                (string)$case['failure_stage'],
                (string)$parent['failure_stage'],
                $outcomeCode
            );
            self::assertSame($outcomeCode, (string)$parent['failure_code'], $outcomeCode);
            self::assertNotSame('', trim((string)$parent['finished_at']), $outcomeCode);
            self::assertNull($parent['collection_anchor_contract_version'], $outcomeCode);
            self::assertNull($parent['collection_anchor_hash'], $outcomeCode);
            self::assertNull($parent['trust_receipt_digest'], $outcomeCode);
            $parentReceipt = json_decode((string)$parent['receipt_json'], true, 512, JSON_THROW_ON_ERROR);
            self::assertTrue($parentReceipt['no_collection_outcome'], $outcomeCode);
            self::assertFalse($parentReceipt['collection_verified'], $outcomeCode);
            self::assertSame($outcomeCode, $parentReceipt['outcome_code'], $outcomeCode);

            self::assertSame((string)$case['status'], $first['status'], $outcomeCode);
            self::assertSame($outcomeCode, $first['failure_code'], $outcomeCode);
            self::assertNull($first['collection_anchor_contract_version'], $outcomeCode);
            self::assertNull($first['collection_anchor_hash'], $outcomeCode);
            self::assertNull($first['trust_receipt_digest'], $outcomeCode);
            self::assertSame((string)$parent['finished_at'], $first['finished_at'], $outcomeCode);
            self::assertCount(2, $children, $outcomeCode);
            foreach ($children as $child) {
                self::assertSame((string)$case['status'], (string)$child['status'], $outcomeCode);
                self::assertSame(
                    (string)$case['failure_stage'],
                    (string)$child['failure_stage'],
                    $outcomeCode
                );
                self::assertSame($outcomeCode, (string)$child['failure_code'], $outcomeCode);
                self::assertSame((string)$parent['finished_at'], (string)$child['finished_at'], $outcomeCode);
                self::assertNull($child['platform_sync_task_id'], $outcomeCode);
                self::assertNull($child['local_collector_task_id'], $outcomeCode);
                self::assertSame(0, (int)$child['saved_row_count'], $outcomeCode);
                self::assertSame(0, (int)$child['readback_row_count'], $outcomeCode);
                self::assertSame(0, (int)$child['readback_verified'], $outcomeCode);
                self::assertNull($child['evidence_digest'], $outcomeCode);
                $sourceReceipt = json_decode(
                    (string)$child['receipt_json'],
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
                self::assertTrue($sourceReceipt['no_collection_outcome'], $outcomeCode);
                self::assertSame($outcomeCode, $sourceReceipt['outcome_code'], $outcomeCode);
            }

            $beforeReplay = [
                'parent' => $parent,
                'children' => $children,
            ];
            $replayed = $service->markNoCollectionOutcome(
                $dispatcherRunId,
                self::HOTEL_ID,
                self::BUSINESS_DATE,
                $outcomeCode
            );
            self::assertSame((string)$case['status'], $replayed['status'], $outcomeCode);
            self::assertSame($outcomeCode, $replayed['failure_code'], $outcomeCode);
            self::assertSame($beforeReplay, [
                'parent' => $this->parent($dispatcherRunId),
                'children' => $this->children((int)$parent['id']),
            ], $outcomeCode . ' replay must not mutate terminal evidence');
        }
    }

    public function testNoCollectionOutcomeCannotChangeReasonForTheSameDispatcher(): void
    {
        $dispatcherRunId = '2b000000-0000-4000-8000-000000000001';
        $service = new HotelCollectionRunReceiptService();
        $service->begin($this->gate($dispatcherRunId, true));
        $service->markNoCollectionOutcome(
            $dispatcherRunId,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            'retry_cooldown'
        );
        $parentBefore = $this->parent($dispatcherRunId);
        $childrenBefore = $this->children((int)$parentBefore['id']);

        $this->assertRuntimeFailure(
            'hotel_collection_run_no_collection_outcome_conflict',
            static fn() => $service->markNoCollectionOutcome(
                $dispatcherRunId,
                self::HOTEL_ID,
                self::BUSINESS_DATE,
                'profile_locked'
            )
        );

        self::assertSame($parentBefore, $this->parent($dispatcherRunId));
        self::assertSame($childrenBefore, $this->children((int)$parentBefore['id']));
    }

    public function testNoCollectionOutcomeCannotOverwritePersistedTaskAndRowEvidence(): void
    {
        $dispatcherRunId = '2c000000-0000-4000-8000-000000000001';
        $service = new HotelCollectionRunReceiptService();
        $service->begin($this->gate($dispatcherRunId, true));
        $ctrip = $this->platformResult(
            $dispatcherRunId,
            'ctrip',
            self::CTRIP_SOURCE_ID,
            16001,
            [26001],
            true,
            true,
            1,
            1
        );
        $this->seedResultEvidence($ctrip);
        $service->recordPlatformResults(
            $dispatcherRunId,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            [$ctrip]
        );
        $parentBefore = $this->parent($dispatcherRunId);
        $childrenBefore = $this->children((int)$parentBefore['id']);
        $ctripBefore = array_column($childrenBefore, null, 'platform')['ctrip'];
        self::assertSame(16001, (int)$ctripBefore['platform_sync_task_id']);
        self::assertSame(1, (int)$ctripBefore['readback_verified']);
        self::assertNotSame('', trim((string)$ctripBefore['evidence_digest']));

        $this->assertRuntimeFailure(
            'hotel_collection_run_no_collection_outcome_conflict',
            static fn() => $service->markNoCollectionOutcome(
                $dispatcherRunId,
                self::HOTEL_ID,
                self::BUSINESS_DATE,
                'verified_cache_reused'
            )
        );

        self::assertSame($parentBefore, $this->parent($dispatcherRunId));
        self::assertSame($childrenBefore, $this->children((int)$parentBefore['id']));
    }

    public function testOneSuccessfulAndOneFailedSourceCannotCreateSuccessAnchor(): void
    {
        $dispatcherRunId = '33333333-3333-4333-8333-333333333333';
        $service = new HotelCollectionRunReceiptService();
        $service->begin($this->gate($dispatcherRunId, true));

        $ctrip = $this->platformResult(
            $dispatcherRunId,
            'ctrip',
            self::CTRIP_SOURCE_ID,
            501,
            [1001],
            true,
            true,
            1,
            1
        );
        $meituan = $this->platformResult(
            $dispatcherRunId,
            'meituan',
            self::MEITUAN_SOURCE_ID,
            502,
            [],
            false,
            false,
            0,
            0
        );
        $this->seedResultEvidence($ctrip);
        $this->seedResultEvidence($meituan);

        $service->recordPlatformResults(
            $dispatcherRunId,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            [$ctrip, $meituan]
        );
        $service->finalizeCollection(
            $dispatcherRunId,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            $this->exactReceipt($dispatcherRunId, [$ctrip], [$meituan], false),
            true
        );

        $parent = $this->parent($dispatcherRunId);
        $children = array_column($this->children((int)$parent['id']), null, 'platform');
        self::assertNotSame('succeeded', (string)$parent['status']);
        self::assertNull($parent['collection_anchor_hash']);
        self::assertSame('success', (string)$children['ctrip']['status']);
        self::assertSame('failed', (string)$children['meituan']['status']);
    }

    public function testOnlyStrictDualSourceExactFinalizeCreatesAnchor(): void
    {
        $dispatcherRunId = '44444444-4444-4444-8444-444444444444';
        $service = new HotelCollectionRunReceiptService();
        $service->begin($this->gate($dispatcherRunId, true));

        $ctrip = $this->platformResult(
            $dispatcherRunId,
            'ctrip',
            self::CTRIP_SOURCE_ID,
            601,
            [1101, 1102],
            true,
            true,
            2,
            2
        );
        $meituan = $this->platformResult(
            $dispatcherRunId,
            'meituan',
            self::MEITUAN_SOURCE_ID,
            602,
            [1201],
            true,
            true,
            1,
            1
        );
        $this->seedResultEvidence($ctrip);
        $this->seedResultEvidence($meituan);
        $service->recordPlatformResults(
            $dispatcherRunId,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            [$ctrip, $meituan]
        );

        $before = $this->parent($dispatcherRunId);
        self::assertNotSame('succeeded', (string)$before['status']);
        self::assertNull($before['collection_anchor_hash']);

        $receipt = $this->exactReceipt($dispatcherRunId, [$ctrip, $meituan], [], true);
        $service->finalizeCollection(
            $dispatcherRunId,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            $receipt,
            true
        );

        $parent = $this->parent($dispatcherRunId);
        self::assertSame('succeeded', (string)$parent['status']);
        self::assertSame(
            (string)$receipt['collection_anchor_hash'],
            (string)$parent['collection_anchor_hash']
        );
        self::assertSame(
            OtaCollectionAnchorService::CONTRACT_VERSION,
            (string)$parent['collection_anchor_contract_version']
        );
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string)$parent['trust_receipt_digest']);
        $readback = $service->readExact(
            $dispatcherRunId,
            self::HOTEL_ID,
            self::BUSINESS_DATE
        );
        self::assertSame('succeeded', $readback['status']);
        self::assertSame($receipt['collection_anchor_hash'], $readback['collection_anchor_hash']);
        self::assertTrue($readback['readback_verified']);
        self::assertCount(2, $readback['source_receipts']);
        self::assertTrue($service->sourceEvidenceCurrent(
            $dispatcherRunId,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            'ctrip',
            self::CTRIP_SOURCE_ID,
            601,
            0,
            [1101, 1102]
        ));
        self::assertTrue($service->sourceEvidenceCurrent(
            $dispatcherRunId,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            'meituan',
            self::MEITUAN_SOURCE_ID,
            602,
            0,
            [1201]
        ));
        self::assertFalse($service->sourceEvidenceCurrent(
            $dispatcherRunId,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            'ctrip',
            self::CTRIP_SOURCE_ID,
            601,
            0,
            [1101]
        ));
    }

    public function testReadbackFalseOrZeroRowsCanNeverCreateAnchor(): void
    {
        $cases = [
            [
                'dispatcher_run_id' => '55555555-5555-4555-8555-555555555555',
                'sync_task_id' => 701,
                'row_ids' => [1301],
                'readback_verified' => false,
                'saved_count' => 1,
                'readback_count' => 1,
            ],
            [
                'dispatcher_run_id' => '66666666-6666-4666-8666-666666666666',
                'sync_task_id' => 711,
                'row_ids' => [],
                'readback_verified' => true,
                'saved_count' => 0,
                'readback_count' => 0,
            ],
        ];

        foreach ($cases as $index => $case) {
            $dispatcherRunId = (string)$case['dispatcher_run_id'];
            $service = new HotelCollectionRunReceiptService();
            $service->begin($this->gate($dispatcherRunId, true));
            $ctrip = $this->platformResult(
                $dispatcherRunId,
                'ctrip',
                self::CTRIP_SOURCE_ID,
                (int)$case['sync_task_id'],
                (array)$case['row_ids'],
                true,
                (bool)$case['readback_verified'],
                (int)$case['saved_count'],
                (int)$case['readback_count']
            );
            $meituan = $this->platformResult(
                $dispatcherRunId,
                'meituan',
                self::MEITUAN_SOURCE_ID,
                702 + ($index * 10),
                [1401 + $index],
                true,
                true,
                1,
                1
            );
            $this->seedResultEvidence($ctrip);
            $this->seedResultEvidence($meituan);
            $service->recordPlatformResults(
                $dispatcherRunId,
                self::HOTEL_ID,
                self::BUSINESS_DATE,
                [$ctrip, $meituan]
            );
            $service->finalizeCollection(
                $dispatcherRunId,
                self::HOTEL_ID,
                self::BUSINESS_DATE,
                $this->exactReceipt($dispatcherRunId, [$ctrip, $meituan], [], false),
                true
            );

            $parent = $this->parent($dispatcherRunId);
            self::assertNotSame('succeeded', (string)$parent['status']);
            self::assertNull($parent['collection_anchor_hash']);
        }
    }

    public function testLocalCollectorAndPlatformSyncTaskIdsRemainSeparated(): void
    {
        $dispatcherRunId = '77777777-7777-4777-8777-777777777777';
        $service = new HotelCollectionRunReceiptService();
        $this->seedLocalCollectorBinding();
        $service->begin($this->gate(
            $dispatcherRunId,
            true,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            self::CTRIP_SOURCE_ID,
            self::MEITUAN_SOURCE_ID,
            'local_collector'
        ));
        $ctrip = $this->platformResult(
            $dispatcherRunId,
            'ctrip',
            self::CTRIP_SOURCE_ID,
            9001,
            [1501],
            true,
            true,
            1,
            1,
            'local_collector',
            7001
        );
        $this->seedResultEvidence($ctrip);
        $service->recordPlatformResults(
            $dispatcherRunId,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            [$ctrip]
        );

        $parent = $this->parent($dispatcherRunId);
        $child = Db::name('hotel_collection_plan_run_sources')
            ->where('run_id', (int)$parent['id'])
            ->where('platform', 'ctrip')
            ->find();
        self::assertIsArray($child);
        self::assertSame(9001, (int)$child['platform_sync_task_id']);
        self::assertSame(7001, (int)$child['local_collector_task_id']);
        self::assertNotSame(
            (int)$child['platform_sync_task_id'],
            (int)$child['local_collector_task_id']
        );
        self::assertNull($parent['collection_anchor_hash']);
    }

    public function testSelfReportedDualSuccessWithoutPersistedEvidenceCannotCreateAnchor(): void
    {
        $dispatcherRunId = '88888888-8888-4888-8888-888888888888';
        $service = new HotelCollectionRunReceiptService();
        $service->begin($this->gate($dispatcherRunId, true));
        $ctrip = $this->platformResult(
            $dispatcherRunId,
            'ctrip',
            self::CTRIP_SOURCE_ID,
            10001,
            [20001],
            true,
            true,
            1,
            1
        );
        $meituan = $this->platformResult(
            $dispatcherRunId,
            'meituan',
            self::MEITUAN_SOURCE_ID,
            10002,
            [20002],
            true,
            true,
            1,
            1
        );

        self::assertSame(0, Db::name('platform_data_sync_tasks')->count());
        self::assertSame(0, Db::name('platform_data_raw_records')->count());
        self::assertSame(0, Db::name('online_daily_data')->count());
        $service->recordPlatformResults(
            $dispatcherRunId,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            [$ctrip, $meituan]
        );
        $service->finalizeCollection(
            $dispatcherRunId,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            $this->exactReceipt($dispatcherRunId, [$ctrip, $meituan], [], true),
            true
        );

        $parent = $this->parent($dispatcherRunId);
        $children = $this->children((int)$parent['id']);
        self::assertNotSame('succeeded', (string)$parent['status']);
        self::assertNull($parent['collection_anchor_hash']);
        self::assertSame(['partial', 'partial'], array_column($children, 'status'));
        self::assertSame([0, 0], array_map('intval', array_column($children, 'readback_verified')));
        self::assertSame(
            ['collection_persistence_evidence_mismatch', 'collection_persistence_evidence_mismatch'],
            array_column($children, 'failure_code')
        );
    }

    public function testFailedOrBlockedSourceTaskSemanticsCannotCreateAnchor(): void
    {
        $cases = [
            ['collection_status', 'failed'],
            ['p0_status', 'blocked'],
            ['historical_core_contract_status', 'blocked'],
        ];

        foreach ($cases as $index => [$field, $value]) {
            $dispatcherRunId = sprintf(
                '90000000-0000-4000-8000-%012d',
                $index + 1
            );
            $prepared = $this->prepareDualSuccess(
                $dispatcherRunId,
                11000 + ($index * 10),
                21000 + ($index * 10)
            );
            $receipt = $prepared['receipt'];
            foreach ($receipt['source_tasks'] as &$sourceTask) {
                if (($sourceTask['platform'] ?? '') === 'ctrip') {
                    $sourceTask[$field] = $value;
                }
            }
            unset($sourceTask);
            $receipt['collection_anchor_hash'] = OtaCollectionAnchorService::hash(
                $receipt['source_tasks']
            );
            self::assertTrue(OtaCollectionAnchorService::matches(
                $receipt['source_tasks'],
                $receipt['collection_anchor_hash']
            ));

            $prepared['service']->finalizeCollection(
                $dispatcherRunId,
                self::HOTEL_ID,
                self::BUSINESS_DATE,
                $receipt,
                true
            );

            $parent = $this->parent($dispatcherRunId);
            self::assertNotSame('succeeded', (string)$parent['status'], $field);
            self::assertNull($parent['collection_anchor_hash'], $field);
        }
    }

    public function testLocalCollectorMissingOrCrossHotelProducerTaskCannotSucceed(): void
    {
        $this->seedLocalCollectorBinding();
        $cases = [
            ['missing', '91000000-0000-4000-8000-000000000001', 7201],
            ['cross_hotel', '91000000-0000-4000-8000-000000000002', 7202],
        ];

        foreach ($cases as $index => [$mode, $dispatcherRunId, $localTaskId]) {
            $service = new HotelCollectionRunReceiptService();
            $service->begin($this->gate(
                $dispatcherRunId,
                true,
                self::HOTEL_ID,
                self::BUSINESS_DATE,
                self::CTRIP_SOURCE_ID,
                self::MEITUAN_SOURCE_ID,
                'local_collector'
            ));
            $ctrip = $this->platformResult(
                $dispatcherRunId,
                'ctrip',
                self::CTRIP_SOURCE_ID,
                12001 + ($index * 10),
                [22001 + ($index * 10)],
                true,
                true,
                1,
                1,
                'local_collector',
                $localTaskId
            );
            $meituan = $this->platformResult(
                $dispatcherRunId,
                'meituan',
                self::MEITUAN_SOURCE_ID,
                12002 + ($index * 10),
                [22002 + ($index * 10)],
                true,
                true,
                1,
                1
            );
            $this->seedResultEvidence($ctrip);
            $this->seedResultEvidence($meituan);
            if ($mode === 'missing') {
                Db::name('ota_local_collector_tasks')->where('id', $localTaskId)->delete();
            } else {
                Db::name('ota_local_collector_tasks')->where('id', $localTaskId)->update([
                    'system_hotel_id' => 81,
                ]);
            }

            $service->recordPlatformResults(
                $dispatcherRunId,
                self::HOTEL_ID,
                self::BUSINESS_DATE,
                [$ctrip, $meituan]
            );
            $service->finalizeCollection(
                $dispatcherRunId,
                self::HOTEL_ID,
                self::BUSINESS_DATE,
                $this->exactReceipt($dispatcherRunId, [$ctrip, $meituan], [], true),
                true
            );

            $parent = $this->parent($dispatcherRunId);
            $children = array_column($this->children((int)$parent['id']), null, 'platform');
            self::assertNotSame('success', (string)$children['ctrip']['status'], $mode);
            self::assertFalse((int)$children['ctrip']['readback_verified'] === 1, $mode);
            self::assertSame(
                'collection_persistence_evidence_mismatch',
                (string)$children['ctrip']['failure_code'],
                $mode
            );
            self::assertNotSame('succeeded', (string)$parent['status'], $mode);
            self::assertNull($parent['collection_anchor_hash'], $mode);
        }
    }

    public function testBrowserProfileResultCannotCarryLocalCollectorTaskId(): void
    {
        $dispatcherRunId = '92000000-0000-4000-8000-000000000001';
        $service = new HotelCollectionRunReceiptService();
        $service->begin($this->gate($dispatcherRunId, true));
        $result = $this->platformResult(
            $dispatcherRunId,
            'ctrip',
            self::CTRIP_SOURCE_ID,
            13001,
            [23001],
            true,
            true,
            1,
            1,
            'browser_profile',
            7301
        );

        $this->assertRuntimeFailure(
            'hotel_collection_run_local_task_method_mismatch',
            static fn() => $service->recordPlatformResults(
                $dispatcherRunId,
                self::HOTEL_ID,
                self::BUSINESS_DATE,
                [$result]
            )
        );

        $parent = $this->parent($dispatcherRunId);
        $children = $this->children((int)$parent['id']);
        self::assertNotSame('succeeded', (string)$parent['status']);
        self::assertNull($parent['collection_anchor_hash']);
        foreach ($children as $child) {
            self::assertNull($child['platform_sync_task_id']);
            self::assertNull($child['local_collector_task_id']);
        }
    }

    public function testSucceededRunIsImmutableAcrossSequentialTerminalCasReplays(): void
    {
        $dispatcherRunId = '93000000-0000-4000-8000-000000000001';
        $prepared = $this->prepareDualSuccess($dispatcherRunId, 14000, 24000);
        $service = $prepared['service'];
        $receipt = $prepared['receipt'];
        $service->finalizeCollection(
            $dispatcherRunId,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            $receipt,
            true
        );
        $succeeded = $this->parent($dispatcherRunId);
        $anchor = (string)$succeeded['collection_anchor_hash'];
        $trustDigest = (string)$succeeded['trust_receipt_digest'];

        $this->assertRuntimeFailure(
            'hotel_collection_run_success_is_immutable',
            static fn() => $service->finalizeCollection(
                $dispatcherRunId,
                self::HOTEL_ID,
                self::BUSINESS_DATE,
                $receipt,
                false
            )
        );
        $differentAnchor = $receipt;
        $differentAnchor['collection_anchor_hash'] = str_repeat(
            $anchor === str_repeat('f', 64) ? 'e' : 'f',
            64
        );
        $this->assertRuntimeFailure(
            'hotel_collection_run_success_is_immutable',
            static fn() => $service->finalizeCollection(
                $dispatcherRunId,
                self::HOTEL_ID,
                self::BUSINESS_DATE,
                $differentAnchor,
                true
            )
        );
        $this->assertRuntimeFailure(
            'hotel_collection_run_not_collectable',
            static fn() => $service->recordPlatformResults(
                $dispatcherRunId,
                self::HOTEL_ID,
                self::BUSINESS_DATE,
                [$prepared['ctrip'], $prepared['meituan']]
            )
        );

        $replayed = $service->finalizeCollection(
            $dispatcherRunId,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            $receipt,
            true
        );
        $after = $this->parent($dispatcherRunId);
        self::assertSame('succeeded', $replayed['status']);
        self::assertSame('succeeded', (string)$after['status']);
        self::assertSame($anchor, (string)$after['collection_anchor_hash']);
        self::assertSame($trustDigest, (string)$after['trust_receipt_digest']);
    }

    public function testRecordPageAcceptanceIsExactIdempotentAndPreservesCollectionTruth(): void
    {
        $dispatcherRunId = '94000000-0000-4000-8000-000000000001';
        $prepared = $this->finalizeDualSuccess($dispatcherRunId, 15000, 25000);
        $beforeParent = $this->parent($dispatcherRunId);
        $beforeChildren = array_column(
            $this->children((int)$beforeParent['id']),
            null,
            'platform'
        );
        $contract = $this->pageContract($prepared);
        $confirmed = $this->insertPageEvidence($contract);

        $first = $prepared['service']->recordPageAcceptance(
            self::TENANT_ID,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            $confirmed,
            $contract
        );
        $second = $prepared['service']->recordPageAcceptance(
            self::TENANT_ID,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            $confirmed,
            $contract
        );

        self::assertSame('verified', $first['page_acceptance']['status']);
        self::assertSame($confirmed['receipt_id'], $first['page_acceptance']['receipt_id']);
        self::assertSame($confirmed['contract_hash'], $first['page_acceptance']['contract_hash']);
        self::assertSame($first['page_acceptance'], $second['page_acceptance']);
        $afterParent = $this->parent($dispatcherRunId);
        self::assertSame((string)$beforeParent['status'], (string)$afterParent['status']);
        self::assertSame(
            (string)$beforeParent['collection_anchor_contract_version'],
            (string)$afterParent['collection_anchor_contract_version']
        );
        self::assertSame(
            (string)$beforeParent['collection_anchor_hash'],
            (string)$afterParent['collection_anchor_hash']
        );
        self::assertSame(
            (string)$beforeParent['trust_receipt_digest'],
            (string)$afterParent['trust_receipt_digest']
        );
        self::assertSame('verified', (string)$afterParent['page_status']);
        self::assertSame($confirmed['receipt_id'], (int)$afterParent['page_receipt_id']);
        self::assertSame($confirmed['contract_hash'], (string)$afterParent['page_contract_hash']);

        $afterChildren = array_column(
            $this->children((int)$afterParent['id']),
            null,
            'platform'
        );
        foreach (['ctrip', 'meituan'] as $platform) {
            self::assertSame('verified', (string)$afterChildren[$platform]['page_acceptance_status']);
            self::assertSame(
                $confirmed['receipt_id'],
                (int)$afterChildren[$platform]['page_acceptance_log_id']
            );
            foreach ([
                'data_source_id',
                'platform_sync_task_id',
                'status',
                'saved_row_count',
                'readback_row_count',
                'readback_verified',
                'evidence_digest',
            ] as $field) {
                self::assertSame(
                    (string)$beforeChildren[$platform][$field],
                    (string)$afterChildren[$platform][$field],
                    $platform . ':' . $field
                );
            }
        }

        $conflictingContract = $contract;
        $conflictingContract['hotel_name'] = 'Hotel 80 renamed after confirmation';
        $conflictingReceipt = $this->insertPageEvidence($conflictingContract);
        $this->assertRuntimeFailure(
            'hotel_collection_page_acceptance_conflict',
            static fn() => $prepared['service']->recordPageAcceptance(
                self::TENANT_ID,
                self::HOTEL_ID,
                self::BUSINESS_DATE,
                $conflictingReceipt,
                $conflictingContract
            )
        );
        $afterConflict = $this->parent($dispatcherRunId);
        self::assertSame($confirmed['receipt_id'], (int)$afterConflict['page_receipt_id']);
        self::assertSame($confirmed['contract_hash'], (string)$afterConflict['page_contract_hash']);
        self::assertSame(
            (string)$beforeParent['collection_anchor_hash'],
            (string)$afterConflict['collection_anchor_hash']
        );
    }

    public function testRecordPageAcceptanceRejectsAFormallyShapedButUntrustedSucceededRun(): void
    {
        $dispatcherRunId = '94000000-0000-4000-8000-000000000007';
        $prepared = $this->finalizeDualSuccess($dispatcherRunId, 15500, 25500);
        $parent = $this->parent($dispatcherRunId);
        Db::name('hotel_collection_plan_runs')
            ->where('id', (int)$parent['id'])
            ->update(['trust_receipt_digest' => hash('sha256', 'drifted-valid-shape')]);

        $untrusted = $prepared['service']->readExact(
            $dispatcherRunId,
            self::HOTEL_ID,
            self::BUSINESS_DATE
        );
        self::assertSame('succeeded', $untrusted['status']);
        self::assertTrue($untrusted['ledger_structure_verified']);
        self::assertFalse($untrusted['readback_verified']);

        $contract = $this->pageContract($prepared);
        $confirmed = $this->insertPageEvidence($contract);
        $this->assertRuntimeFailure(
            'hotel_collection_page_run_not_found',
            static fn() => $prepared['service']->recordPageAcceptance(
                self::TENANT_ID,
                self::HOTEL_ID,
                self::BUSINESS_DATE,
                $confirmed,
                $contract
            )
        );

        $after = $this->parent($dispatcherRunId);
        self::assertSame('not_evaluated', (string)$after['page_status']);
        self::assertNull($after['page_receipt_id']);
        self::assertNull($after['page_contract_hash']);
        foreach ($this->children((int)$after['id']) as $child) {
            self::assertSame('not_evaluated', (string)$child['page_acceptance_status']);
            self::assertNull($child['page_acceptance_log_id']);
        }
    }

    public function testRecordPageAcceptanceRejectsCrossHotelWrongTaskAndForgedEvidence(): void
    {
        $crossDispatcher = '94000000-0000-4000-8000-000000000002';
        $cross = $this->finalizeDualSuccess($crossDispatcher, 15100, 25100);
        $crossParent = $this->parent($crossDispatcher);
        $crossContract = $this->pageContract($cross);
        $crossReceipt = $this->insertPageEvidence($crossContract, [], 81);
        $this->assertRuntimeFailure(
            'hotel_collection_page_evidence_invalid',
            static fn() => $cross['service']->recordPageAcceptance(
                self::TENANT_ID,
                self::HOTEL_ID,
                self::BUSINESS_DATE,
                $crossReceipt,
                $crossContract
            )
        );
        $crossAfter = $this->parent($crossDispatcher);
        self::assertSame('not_evaluated', (string)$crossAfter['page_status']);
        self::assertSame(
            (string)$crossParent['collection_anchor_hash'],
            (string)$crossAfter['collection_anchor_hash']
        );

        $taskDispatcher = '94000000-0000-4000-8000-000000000003';
        $wrongTask = $this->finalizeDualSuccess($taskDispatcher, 15200, 25200);
        $taskParent = $this->parent($taskDispatcher);
        $wrongTaskContract = $this->pageContract($wrongTask);
        $wrongTaskContract['platforms'][0]['sync_task_id'] += 9000;
        $wrongTaskReceipt = $this->insertPageEvidence($wrongTaskContract);
        $this->assertRuntimeFailure(
            'hotel_collection_page_run_not_found',
            static fn() => $wrongTask['service']->recordPageAcceptance(
                self::TENANT_ID,
                self::HOTEL_ID,
                self::BUSINESS_DATE,
                $wrongTaskReceipt,
                $wrongTaskContract
            )
        );
        $taskAfter = $this->parent($taskDispatcher);
        self::assertSame('not_evaluated', (string)$taskAfter['page_status']);
        self::assertSame(
            (string)$taskParent['collection_anchor_hash'],
            (string)$taskAfter['collection_anchor_hash']
        );

        $fakeDispatcher = '94000000-0000-4000-8000-000000000004';
        $fake = $this->finalizeDualSuccess($fakeDispatcher, 15300, 25300);
        $fakeParent = $this->parent($fakeDispatcher);
        $fakeContract = $this->pageContract($fake);
        $wrongActionReceipt = $this->insertPageEvidence(
            $fakeContract,
            [],
            null,
            DualOtaPageVerificationService::MODULE,
            'unrelated_page_confirmation'
        );
        $this->assertRuntimeFailure(
            'hotel_collection_page_evidence_invalid',
            static fn() => $fake['service']->recordPageAcceptance(
                self::TENANT_ID,
                self::HOTEL_ID,
                self::BUSINESS_DATE,
                $wrongActionReceipt,
                $fakeContract
            )
        );
        $tamperedReceipt = $this->insertPageEvidence($fakeContract, ['outcome' => 'failed']);
        $this->assertRuntimeFailure(
            'hotel_collection_page_evidence_invalid',
            static fn() => $fake['service']->recordPageAcceptance(
                self::TENANT_ID,
                self::HOTEL_ID,
                self::BUSINESS_DATE,
                $tamperedReceipt,
                $fakeContract
            )
        );
        $fakeAfter = $this->parent($fakeDispatcher);
        self::assertSame('not_evaluated', (string)$fakeAfter['page_status']);
        self::assertNull($fakeAfter['page_receipt_id']);
        self::assertSame(
            (string)$fakeParent['collection_anchor_hash'],
            (string)$fakeAfter['collection_anchor_hash']
        );
    }

    public function testRecordPageAcceptanceIgnoresCopiedLedgerRowsWithoutIndependentProducerEvidence(): void
    {
        $dispatcherRunId = '94000000-0000-4000-8000-000000000005';
        $prepared = $this->finalizeDualSuccess($dispatcherRunId, 15400, 25400);
        $original = $this->parent($dispatcherRunId);
        $originalChildren = $this->children((int)$original['id']);

        $duplicate = $original;
        unset($duplicate['id']);
        $duplicate['dispatcher_run_id'] = '94000000-0000-4000-8000-000000000006';
        $duplicateRunId = (int)Db::name('hotel_collection_plan_runs')->insertGetId($duplicate);
        self::assertGreaterThan(0, $duplicateRunId);
        foreach ($originalChildren as $child) {
            unset($child['id']);
            $child['run_id'] = $duplicateRunId;
            Db::name('hotel_collection_plan_run_sources')->insert($child);
        }

        $contract = $this->pageContract($prepared);
        $confirmed = $this->insertPageEvidence($contract);
        $attached = $prepared['service']->recordPageAcceptance(
            self::TENANT_ID,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            $confirmed,
            $contract
        );
        self::assertSame($dispatcherRunId, $attached['dispatcher_run_id']);
        self::assertSame('verified', $attached['page_acceptance']['status']);
        self::assertTrue($attached['page_acceptance']['readback_verified']);

        $runs = Db::name('hotel_collection_plan_runs')
            ->whereIn('id', [(int)$original['id'], $duplicateRunId])
            ->order('id', 'asc')
            ->select()
            ->toArray();
        self::assertCount(2, $runs);
        $runsById = array_column($runs, null, 'id');
        $writtenOriginal = $runsById[(int)$original['id']];
        $writtenDuplicate = $runsById[$duplicateRunId];
        self::assertSame('verified', (string)$writtenOriginal['page_status']);
        self::assertSame($confirmed['receipt_id'], (int)$writtenOriginal['page_receipt_id']);
        self::assertSame('not_evaluated', (string)$writtenDuplicate['page_status']);
        self::assertNull($writtenDuplicate['page_receipt_id']);
        foreach ($this->children((int)$original['id']) as $child) {
            self::assertSame('verified', (string)$child['page_acceptance_status']);
            self::assertSame($confirmed['receipt_id'], (int)$child['page_acceptance_log_id']);
        }
        foreach ($this->children($duplicateRunId) as $child) {
            self::assertSame('not_evaluated', (string)$child['page_acceptance_status']);
            self::assertNull($child['page_acceptance_log_id']);
        }
    }

    /** @return array<string,mixed> */
    private function gate(
        string $dispatcherRunId,
        bool $allowed,
        int $hotelId = self::HOTEL_ID,
        string $businessDate = self::BUSINESS_DATE,
        int $ctripSourceId = self::CTRIP_SOURCE_ID,
        int $meituanSourceId = self::MEITUAN_SOURCE_ID,
        string $ctripIngestionMethod = 'browser_profile'
    ): array {
        $planId = $hotelId === 81 ? 902 : 901;
        return [
            'dispatcher_run_id' => $dispatcherRunId,
            'tenant_id' => self::TENANT_ID,
            'system_hotel_id' => $hotelId,
            'business_date' => $businessDate,
            'run_mode' => 'daily',
            'plan_id' => $planId,
            'plan_version' => 1,
            'plan_hash' => str_repeat($hotelId === 81 ? 'b' : 'a', 64),
            'scope_hash' => hash('sha256', json_encode([
                self::TENANT_ID,
                $hotelId,
                $businessDate,
                $ctripSourceId,
                $meituanSourceId,
            ], JSON_THROW_ON_ERROR)),
            'execution_owner_user_id' => 7,
            'collection_allowed' => $allowed,
            'expected_source_ids' => [$ctripSourceId, $meituanSourceId],
            'expected_platforms' => ['ctrip', 'meituan'],
            'sources' => [
                'ctrip' => [
                    'data_source_id' => $ctripSourceId,
                    'ingestion_method' => $ctripIngestionMethod,
                ],
                'meituan' => [
                    'data_source_id' => $meituanSourceId,
                    'ingestion_method' => 'browser_profile',
                ],
            ],
            'failure_reasons' => $allowed ? [] : [[
                'code' => 'plan_not_execution_ready',
                'platform' => '',
            ]],
        ];
    }

    /** @return array<string,mixed> */
    private function platformResult(
        string $dispatcherRunId,
        string $platform,
        int $sourceId,
        int $syncTaskId,
        array $rowIds,
        bool $success,
        bool $readbackVerified,
        int $savedCount,
        int $readbackCount,
        string $ingestionMethod = 'browser_profile',
        int $localCollectorTaskId = 0
    ): array {
        return [
            'platform' => $platform,
            'data_source_id' => $sourceId,
            'system_hotel_id' => self::HOTEL_ID,
            'target_date' => self::BUSINESS_DATE,
            'dispatcher_run_id' => $dispatcherRunId,
            'success' => $success,
            'status' => $success ? 'success' : 'failed',
            'task_id' => $ingestionMethod === 'local_collector'
                ? $localCollectorTaskId
                : $syncTaskId,
            'platform_sync_task_id' => $syncTaskId,
            'local_collector_task_id' => $localCollectorTaskId > 0
                ? $localCollectorTaskId
                : null,
            'saved_count' => $savedCount,
            'readback_count' => $readbackCount,
            'readback_verified' => $readbackVerified,
            'failure_reason' => $success ? '' : 'source_collection_failed',
            'collection_quality' => [
                'status' => $success && $readbackVerified ? 'verified' : 'not_verified',
            ],
            'run_readback' => [
                'dispatcher_run_id' => $dispatcherRunId,
                'system_hotel_id' => self::HOTEL_ID,
                'target_date' => self::BUSINESS_DATE,
                'data_source_id' => $sourceId,
                'sync_task_id' => $syncTaskId,
                'readback_verified' => $readbackVerified,
                'row_ids' => $rowIds,
                'trigger_type' => $ingestionMethod === 'local_collector'
                    ? 'local_collector_upload'
                    : 'daily_profile_reuse',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function exactReceipt(
        string $dispatcherRunId,
        array $successfulResults,
        array $failedResults,
        bool $complete
    ): array {
        $sourceTasks = array_map(
            static fn(array $result): array => [
                'dispatcher_run_id' => (string)$result['dispatcher_run_id'],
                'system_hotel_id' => (int)$result['system_hotel_id'],
                'target_date' => (string)$result['target_date'],
                'data_source_id' => (int)$result['data_source_id'],
                'sync_task_id' => (int)$result['run_readback']['sync_task_id'],
                'platform' => (string)$result['platform'],
                'collection_status' => (bool)$result['success'] ? 'success' : 'failed',
                'p0_status' => (bool)$result['readback_verified'] ? 'ready' : 'not_ready',
                'historical_core_contract_status' => (bool)$result['readback_verified']
                    ? 'ready'
                    : 'not_ready',
                'trigger_type' => (string)($result['run_readback']['trigger_type'] ?? ''),
                'row_ids' => (array)$result['run_readback']['row_ids'],
                'saved_count' => (int)$result['saved_count'],
                'readback_count' => (int)$result['readback_count'],
                'readback_verified' => (bool)$result['readback_verified'],
            ],
            $successfulResults
        );
        usort(
            $sourceTasks,
            static fn(array $left, array $right): int => $left['data_source_id'] <=> $right['data_source_id']
        );
        $anchor = $complete ? OtaCollectionAnchorService::hash($sourceTasks) : null;

        return [
            'schema_version' => 3,
            'dispatcher_run_id' => $dispatcherRunId,
            'tenant_id' => self::TENANT_ID,
            'hotel_id' => self::HOTEL_ID,
            'system_hotel_id' => self::HOTEL_ID,
            'target_date' => self::BUSINESS_DATE,
            'business_date' => self::BUSINESS_DATE,
            'required_platforms' => ['ctrip', 'meituan'],
            'source_ids' => [self::CTRIP_SOURCE_ID, self::MEITUAN_SOURCE_ID],
            'status' => $complete ? 'success' : 'failed',
            'collection_complete' => $complete,
            'exportable_snapshot_complete' => $complete,
            'historical_core_contract_complete' => $complete,
            'authority_scope_complete' => $complete,
            'dual_ota_p0_complete' => $complete,
            'canonical_history_complete' => $complete,
            'authority_verifier' => ['authority_ready' => $complete],
            'source_tasks' => $sourceTasks,
            'failed_source_tasks' => array_values($failedResults),
            'collection_anchor_contract_version' => OtaCollectionAnchorService::CONTRACT_VERSION,
            'collection_anchor_hash' => $anchor,
        ];
    }

    /**
     * @return array{
     *   service:HotelCollectionRunReceiptService,
     *   ctrip:array<string,mixed>,
     *   meituan:array<string,mixed>,
     *   receipt:array<string,mixed>
     * }
     */
    private function prepareDualSuccess(
        string $dispatcherRunId,
        int $taskBase,
        int $rowBase,
        string $pmsProvider = ''
    ): array {
        $service = new HotelCollectionRunReceiptService();
        $gate = $this->gate($dispatcherRunId, true);
        if ($pmsProvider !== '') {
            $gate['sources']['pms'] = ['provider' => $pmsProvider];
        }
        $service->begin($gate);
        $ctrip = $this->platformResult(
            $dispatcherRunId,
            'ctrip',
            self::CTRIP_SOURCE_ID,
            $taskBase + 1,
            [$rowBase + 1],
            true,
            true,
            1,
            1
        );
        $meituan = $this->platformResult(
            $dispatcherRunId,
            'meituan',
            self::MEITUAN_SOURCE_ID,
            $taskBase + 2,
            [$rowBase + 2],
            true,
            true,
            1,
            1
        );
        $this->seedResultEvidence($ctrip);
        $this->seedResultEvidence($meituan);
        $service->recordPlatformResults(
            $dispatcherRunId,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            [$ctrip, $meituan]
        );

        return [
            'service' => $service,
            'ctrip' => $ctrip,
            'meituan' => $meituan,
            'receipt' => $this->exactReceipt(
                $dispatcherRunId,
                [$ctrip, $meituan],
                [],
                true
            ),
        ];
    }

    /**
     * @return array{
     *   service:HotelCollectionRunReceiptService,
     *   ctrip:array<string,mixed>,
     *   meituan:array<string,mixed>,
     *   receipt:array<string,mixed>
     * }
     */
    private function finalizeDualSuccess(
        string $dispatcherRunId,
        int $taskBase,
        int $rowBase
    ): array {
        $prepared = $this->prepareDualSuccess($dispatcherRunId, $taskBase, $rowBase);
        $prepared['service']->finalizeCollection(
            $dispatcherRunId,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            $prepared['receipt'],
            true
        );
        return $prepared;
    }

    /**
     * @param array{
     *   service:HotelCollectionRunReceiptService,
     *   ctrip:array<string,mixed>,
     *   meituan:array<string,mixed>,
     *   receipt:array<string,mixed>
     * } $prepared
     * @return array<string,mixed>
     */
    private function pageContract(array $prepared): array
    {
        $platforms = [];
        foreach (['ctrip', 'meituan'] as $platform) {
            $result = $prepared[$platform];
            $platforms[] = [
                'platform' => $platform,
                'acceptance_status' => 'verified',
                'system_hotel_id' => self::HOTEL_ID,
                'platform_hotel_id' => $platform . '-hotel-80',
                'platform_hotel_status' => 'bound',
                'target_date' => self::BUSINESS_DATE,
                'observed_target_date' => self::BUSINESS_DATE,
                'target_date_status' => 'exact',
                'captured_at' => '2026-08-10T08:00:00+08:00',
                'source_method' => 'verified_test_fixture',
                'capture_strategy' => [
                    'selected' => 'structured_response',
                    'status' => 'verified',
                    'response_evidence_type' => 'json',
                ],
                'data_source_id' => (int)$result['data_source_id'],
                'sync_task_id' => (int)$result['platform_sync_task_id'],
                'sync_task_status' => 'success',
                'data_period' => 'historical_daily',
                'counts' => [
                    'saved' => (int)$result['saved_count'],
                    'readback' => (int)$result['readback_count'],
                    'saved_readback_match' => true,
                    'target_saved' => (int)$result['saved_count'],
                    'target_readback' => (int)$result['readback_count'],
                    'target_saved_readback_match' => true,
                ],
                'critical_fields' => [
                    'complete' => ['data_source_id', 'sync_task_id'],
                    'missing' => [],
                    'status' => 'complete',
                ],
                'claim_allowed' => true,
                'reason_codes' => [],
            ];
        }
        return [
            'contract_version' => DualOtaPageVerificationService::CONTRACT_VERSION,
            'tenant_id' => self::TENANT_ID,
            'hotel_id' => self::HOTEL_ID,
            'hotel_name' => 'Hotel 80',
            'target_date' => self::BUSINESS_DATE,
            'day_acceptance_status' => 'verified',
            'platforms' => $platforms,
        ];
    }

    /**
     * @param array<string,mixed> $contract
     * @param array<string,mixed> $extraOverrides
     * @return array<string,mixed>
     */
    private function insertPageEvidence(
        array $contract,
        array $extraOverrides = [],
        ?int $logHotelId = null,
        string $module = DualOtaPageVerificationService::MODULE,
        string $action = DualOtaPageVerificationService::ACTION
    ): array {
        $contractHash = DualOtaPageVerificationService::contractHash($contract);
        $extra = array_replace([
            'contract_version' => DualOtaPageVerificationService::CONTRACT_VERSION,
            'contract_hash' => $contractHash,
            'tenant_id' => self::TENANT_ID,
            'hotel_id' => self::HOTEL_ID,
            'target_date' => self::BUSINESS_DATE,
            'surface' => 'online_data.dual_ota_continuous_trust',
            'contract' => $contract,
            'outcome' => 'success',
        ], $extraOverrides);
        $receiptId = (int)Db::name('operation_logs')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'user_id' => 7,
            'hotel_id' => $logHotelId ?? self::HOTEL_ID,
            'module' => $module,
            'action' => $action,
            'description' => 'dual_ota_page:v1:'
                . self::BUSINESS_DATE
                . ':'
                . $contractHash,
            'extra_data' => json_encode(
                $extra,
                JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_PRESERVE_ZERO_FRACTION
                    | JSON_THROW_ON_ERROR
            ),
            'create_time' => '2026-08-10 08:30:00',
        ]);
        self::assertGreaterThan(0, $receiptId);
        return [
            'status' => 'verified',
            'reason' => 'page_confirmation_verified',
            'contract_version' => DualOtaPageVerificationService::CONTRACT_VERSION,
            'contract_hash' => $contractHash,
            'target_date' => self::BUSINESS_DATE,
            'receipt_id' => $receiptId,
            'readback_verified' => true,
        ];
    }

    /** @param callable():void $operation */
    private function assertRuntimeFailure(string $expectedMessage, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected RuntimeException: ' . $expectedMessage);
        } catch (RuntimeException $exception) {
            self::assertSame($expectedMessage, $exception->getMessage());
        }
    }

    /** @param callable():void $operation */
    private function assertScopeRejected(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected scope drift to be rejected.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('scope', $exception->getMessage());
        }
    }

    /** @return array<string,mixed> */
    private function parent(string $dispatcherRunId): array
    {
        $row = Db::name('hotel_collection_plan_runs')
            ->where('dispatcher_run_id', $dispatcherRunId)
            ->find();
        self::assertIsArray($row);
        return $row;
    }

    /** @return array<int,array<string,mixed>> */
    private function children(int $runId): array
    {
        return Db::name('hotel_collection_plan_run_sources')
            ->where('run_id', $runId)
            ->order('platform', 'asc')
            ->select()
            ->toArray();
    }

    /** @param array<string,mixed> $result */
    private function seedResultEvidence(array $result): void
    {
        $readback = (array)$result['run_readback'];
        $syncTaskId = (int)$readback['sync_task_id'];
        Db::name('platform_data_sync_tasks')->insert([
            'id' => $syncTaskId,
            'tenant_id' => self::TENANT_ID,
            'data_source_id' => (int)$result['data_source_id'],
            'system_hotel_id' => self::HOTEL_ID,
            'platform' => (string)$result['platform'],
            'ingestion_method' => (int)($result['local_collector_task_id'] ?? 0) > 0
                ? 'local_collector'
                : 'browser_profile',
            'trigger_type' => (int)($result['local_collector_task_id'] ?? 0) > 0
                ? 'local_collector_upload'
                : 'daily_profile_reuse',
            'status' => (string)$result['status'],
            'stats_json' => json_encode([
                'dispatcher_run_id' => (string)$result['dispatcher_run_id'],
            ], JSON_THROW_ON_ERROR),
            'create_time' => '2026-08-10 08:00:00',
            'update_time' => '2026-08-10 08:00:00',
        ]);
        foreach ((array)$readback['row_ids'] as $rowId) {
            Db::name('platform_data_raw_records')->insert([
                'id' => (int)$rowId,
                'tenant_id' => self::TENANT_ID,
                'data_source_id' => (int)$result['data_source_id'],
                'sync_task_id' => $syncTaskId,
                'system_hotel_id' => self::HOTEL_ID,
                'platform' => (string)$result['platform'],
                'ingestion_method' => (int)($result['local_collector_task_id'] ?? 0) > 0
                    ? 'local_collector'
                    : 'browser_profile',
                'payload_hash' => hash('sha256', (string)$rowId),
                'create_time' => '2026-08-10 08:00:00',
            ]);
            Db::name('online_daily_data')->insert([
                'id' => (int)$rowId,
                'tenant_id' => self::TENANT_ID,
                'data_source_id' => (int)$result['data_source_id'],
                'sync_task_id' => $syncTaskId,
                'system_hotel_id' => self::HOTEL_ID,
                'platform' => (string)$result['platform'],
                'data_date' => self::BUSINESS_DATE,
                'data_period' => 'historical_daily',
                'readback_verified' => 1,
            ]);
        }
        $localTaskId = (int)($result['local_collector_task_id'] ?? 0);
        if ($localTaskId > 0) {
            Db::name('ota_local_collector_tasks')->insert([
                'id' => $localTaskId,
                'tenant_id' => self::TENANT_ID,
                'user_id' => 7,
                'device_id' => 501,
                'account_id' => 601,
                'system_hotel_id' => self::HOTEL_ID,
                'platform' => (string)$result['platform'],
                'data_date' => self::BUSINESS_DATE,
                'status' => (string)$result['status'],
                'request_json' => json_encode([
                    'dispatcher_run_id' => (string)$result['dispatcher_run_id'],
                ], JSON_THROW_ON_ERROR),
                'result_summary_json' => json_encode([
                    'readback_verified' => (bool)$result['readback_verified'],
                    'data_source_id' => (int)$result['data_source_id'],
                    'sync_task_id' => $syncTaskId,
                    'scope_identity' => ['capture_task_id' => $localTaskId],
                    'run_readback' => $result['run_readback'],
                ], JSON_THROW_ON_ERROR),
            ]);
        }
    }

    private function seedLocalCollectorBinding(): void
    {
        $profileHash = str_repeat('c', 64);
        $devicePublicId = 'device-hotel-80';
        Db::name('ota_local_collector_devices')->insert([
            'id' => 501,
            'tenant_id' => self::TENANT_ID,
            'user_id' => 7,
            'device_public_id' => $devicePublicId,
            'status' => 'online',
        ]);
        Db::name('ota_local_collector_accounts')->insert([
            'id' => 601,
            'tenant_id' => self::TENANT_ID,
            'user_id' => 7,
            'device_id' => 501,
            'platform' => 'ctrip',
            'profile_key_hash' => $profileHash,
            'status' => 'active',
        ]);
        Db::name('ota_local_collector_account_hotels')->insert([
            'id' => 701,
            'tenant_id' => self::TENANT_ID,
            'account_id' => 601,
            'system_hotel_id' => self::HOTEL_ID,
            'platform' => 'ctrip',
            'data_source_id' => self::CTRIP_SOURCE_ID,
            'status' => 'active',
        ]);
        Db::name('platform_data_sources')->where('id', self::CTRIP_SOURCE_ID)->update([
            'ingestion_method' => 'local_collector',
            'config_json' => json_encode([
                'local_collector_account_id' => 601,
                'profile_key_hash' => $profileHash,
                'collector_device_id_hash' => hash('sha256', $devicePublicId),
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    private function seedPmsCapture(
        int $captureId,
        int $hotelId = self::HOTEL_ID,
        string $businessDate = self::BUSINESS_DATE,
        string $readbackStatus = 'readback_verified'
    ): void {
        Db::name('dingdandao_operating_target_captures')->insert([
            'id' => $captureId,
            'tenant_id' => self::TENANT_ID,
            'hotel_id' => $hotelId,
            'provider' => 'dingdandao_pms',
            'business_date' => $businessDate,
            'identity_status' => 'matched',
            'capture_status' => 'verified',
            'quality_status' => 'verified',
            'reconciliation_status' => 'matched',
            'readback_status' => $readbackStatus,
        ]);
    }

    private function seedScope(): void
    {
        Db::name('hotels')->insertAll([
            ['id' => 80, 'tenant_id' => self::TENANT_ID, 'name' => 'Hotel 80', 'status' => 1],
            ['id' => 81, 'tenant_id' => self::TENANT_ID, 'name' => 'Hotel 81', 'status' => 1],
        ]);
        Db::name('platform_data_sources')->insertAll([
            $this->source(25, 80, 'ctrip', 'browser_profile'),
            $this->source(26, 80, 'ctrip', 'browser_profile'),
            $this->source(68, 80, 'meituan', 'browser_profile'),
            $this->source(125, 81, 'ctrip', 'browser_profile'),
            $this->source(168, 81, 'meituan', 'browser_profile'),
        ]);
        Db::name('hotel_collection_plans')->insertAll([
            $this->plan(901, 80, 25, 68, 'a'),
            $this->plan(902, 81, 125, 168, 'b'),
        ]);
    }

    /** @return array<string,mixed> */
    private function source(int $id, int $hotelId, string $platform, string $method): array
    {
        return [
            'id' => $id,
            'tenant_id' => self::TENANT_ID,
            'user_id' => 7,
            'system_hotel_id' => $hotelId,
            'platform' => $platform,
            'ingestion_method' => $method,
            'config_json' => '{}',
        ];
    }

    /** @return array<string,mixed> */
    private function plan(
        int $id,
        int $hotelId,
        int $ctripSourceId,
        int $meituanSourceId,
        string $hashCharacter
    ): array {
        return [
            'id' => $id,
            'tenant_id' => self::TENANT_ID,
            'system_hotel_id' => $hotelId,
            'plan_version' => 1,
            'plan_status' => 'active',
            'enabled' => 1,
            'execution_owner_user_id' => 7,
            'plan_hash' => str_repeat($hashCharacter, 64),
            'source_plan_json' => json_encode([
                'ctrip' => [
                    'data_source_id' => $ctripSourceId,
                    'ingestion_method' => 'browser_profile',
                ],
                'meituan' => [
                    'data_source_id' => $meituanSourceId,
                    'ingestion_method' => 'browser_profile',
                ],
            ], JSON_THROW_ON_ERROR),
        ];
    }

    private static function createSchema(): void
    {
        Db::execute('CREATE TABLE hotels (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            status INTEGER NOT NULL
        )');
        Db::execute('CREATE TABLE hotel_collection_plans (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            system_hotel_id INTEGER NOT NULL,
            plan_version INTEGER NOT NULL,
            plan_status TEXT NOT NULL,
            enabled INTEGER NOT NULL,
            execution_owner_user_id INTEGER NULL,
            plan_hash TEXT NOT NULL,
            source_plan_json TEXT NOT NULL
        )');
        Db::execute('CREATE TABLE platform_data_sources (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            system_hotel_id INTEGER NOT NULL,
            platform TEXT NOT NULL,
            ingestion_method TEXT NOT NULL,
            config_json TEXT NOT NULL DEFAULT \'{}\'
        )');
        Db::execute('CREATE TABLE platform_data_sync_tasks (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            data_source_id INTEGER NOT NULL,
            system_hotel_id INTEGER NOT NULL,
            platform TEXT NOT NULL,
            ingestion_method TEXT NOT NULL,
            trigger_type TEXT NOT NULL,
            status TEXT NOT NULL,
            stats_json TEXT NOT NULL,
            create_time TEXT NOT NULL,
            update_time TEXT NOT NULL
        )');
        Db::execute('CREATE TABLE platform_data_raw_records (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            data_source_id INTEGER NOT NULL,
            sync_task_id INTEGER NOT NULL,
            system_hotel_id INTEGER NOT NULL,
            platform TEXT NOT NULL,
            ingestion_method TEXT NOT NULL,
            payload_hash TEXT NOT NULL,
            create_time TEXT NOT NULL
        )');
        Db::execute('CREATE TABLE online_daily_data (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            data_source_id INTEGER NOT NULL,
            sync_task_id INTEGER NOT NULL,
            system_hotel_id INTEGER NOT NULL,
            platform TEXT NOT NULL,
            data_date TEXT NOT NULL,
            data_period TEXT NOT NULL,
            readback_verified INTEGER NOT NULL
        )');
        Db::execute('CREATE TABLE ota_local_collector_devices (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            device_public_id TEXT NOT NULL,
            status TEXT NOT NULL
        )');
        Db::execute('CREATE TABLE ota_local_collector_accounts (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            device_id INTEGER NOT NULL,
            platform TEXT NOT NULL,
            profile_key_hash TEXT NOT NULL,
            status TEXT NOT NULL
        )');
        Db::execute('CREATE TABLE ota_local_collector_account_hotels (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            account_id INTEGER NOT NULL,
            system_hotel_id INTEGER NOT NULL,
            platform TEXT NOT NULL,
            data_source_id INTEGER NOT NULL,
            status TEXT NOT NULL
        )');
        Db::execute('CREATE TABLE ota_local_collector_tasks (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            device_id INTEGER NOT NULL,
            account_id INTEGER NOT NULL,
            system_hotel_id INTEGER NOT NULL,
            platform TEXT NOT NULL,
            data_date TEXT NOT NULL,
            status TEXT NOT NULL,
            request_json TEXT NOT NULL,
            result_summary_json TEXT NOT NULL
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
        Db::execute('CREATE TABLE operation_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            user_id INTEGER NULL,
            hotel_id INTEGER NOT NULL,
            module TEXT NOT NULL,
            action TEXT NOT NULL,
            description TEXT NOT NULL,
            extra_data TEXT NOT NULL,
            create_time TEXT NOT NULL
        )');
        Db::execute('CREATE TABLE hotel_collection_plan_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            dispatcher_run_id TEXT NOT NULL UNIQUE,
            tenant_id INTEGER NOT NULL,
            system_hotel_id INTEGER NOT NULL,
            business_date TEXT NOT NULL,
            run_mode TEXT NOT NULL,
            trigger_type TEXT NOT NULL DEFAULT \'scheduler\',
            plan_id INTEGER NULL,
            plan_version INTEGER NOT NULL DEFAULT 0,
            plan_hash TEXT NOT NULL DEFAULT \'\',
            scope_hash TEXT NOT NULL,
            execution_owner_user_id INTEGER NULL,
            status TEXT NOT NULL DEFAULT \'started\',
            failure_stage TEXT NOT NULL DEFAULT \'\',
            failure_code TEXT NOT NULL DEFAULT \'\',
            collection_anchor_contract_version TEXT NULL,
            collection_anchor_hash TEXT NULL,
            trust_receipt_digest TEXT NULL,
            page_status TEXT NOT NULL DEFAULT \'not_evaluated\',
            page_receipt_id INTEGER NULL,
            page_contract_hash TEXT NULL,
            pms_status TEXT NOT NULL DEFAULT \'not_run\',
            pms_provider TEXT NULL,
            pms_capture_id TEXT NULL,
            pms_readback_verified INTEGER NULL,
            receipt_json TEXT NOT NULL,
            started_at TEXT NOT NULL,
            finished_at TEXT NULL,
            create_time TEXT NOT NULL,
            update_time TEXT NOT NULL,
            UNIQUE (tenant_id, system_hotel_id, business_date, dispatcher_run_id)
        )');
        Db::execute('CREATE TABLE hotel_collection_plan_run_sources (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            run_id INTEGER NOT NULL,
            platform TEXT NOT NULL,
            data_source_id INTEGER NULL,
            ingestion_method TEXT NOT NULL DEFAULT \'\',
            status TEXT NOT NULL DEFAULT \'declared\',
            platform_sync_task_id INTEGER NULL,
            local_collector_task_id INTEGER NULL,
            saved_row_count INTEGER NOT NULL DEFAULT 0,
            readback_row_count INTEGER NOT NULL DEFAULT 0,
            readback_verified INTEGER NOT NULL DEFAULT 0,
            evidence_digest TEXT NULL,
            failure_stage TEXT NOT NULL DEFAULT \'\',
            failure_code TEXT NOT NULL DEFAULT \'\',
            page_acceptance_status TEXT NOT NULL DEFAULT \'not_evaluated\',
            page_acceptance_log_id INTEGER NULL,
            receipt_json TEXT NOT NULL,
            started_at TEXT NOT NULL,
            finished_at TEXT NULL,
            create_time TEXT NOT NULL,
            update_time TEXT NOT NULL,
            UNIQUE (run_id, platform)
        )');
    }
}
