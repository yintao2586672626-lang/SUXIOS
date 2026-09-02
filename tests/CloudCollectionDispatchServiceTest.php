<?php
declare(strict_types=1);

namespace Tests;

use app\service\CloudCollectionDispatchService;
use app\service\CloudBrowserProfileService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class CloudCollectionDispatchServiceTest extends TestCase
{
    private static array $databaseConfig;
    private static string $databasePath;

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$databaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir() . '/cloud_collection_dispatch_' . getmypid() . '.sqlite';
        @unlink(self::$databasePath);
        $config = self::$databaseConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = ['type' => 'sqlite', 'database' => self::$databasePath, 'prefix' => '', 'fields_strict' => false];
        Config::set($config, 'database');
        Db::connect(null, true);
    }

    public static function tearDownAfterClass(): void
    {
        Config::set(self::$databaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$databasePath);
    }

    protected function setUp(): void
    {
        Db::execute('CREATE TABLE IF NOT EXISTS cloud_browser_profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, system_hotel_id INTEGER, owner_user_id INTEGER, platform TEXT, profile_public_id TEXT, authorization_status TEXT, ready_at TEXT NULL, session_expires_at TEXT NULL)');
        Db::execute('CREATE TABLE IF NOT EXISTS cloud_collection_tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, task_public_id TEXT, profile_id INTEGER, profile_public_id TEXT, tenant_id INTEGER, system_hotel_id INTEGER, owner_user_id INTEGER, platform TEXT, collection_mode TEXT, target_date TEXT, window_key TEXT, field_priority_json TEXT, task_status TEXT, truth_gate_status TEXT, gap_codes_json TEXT NULL, receipt_evidence_json TEXT NULL, receipt_fingerprint TEXT NULL, formal_message_allowed INTEGER NOT NULL DEFAULT 0, idempotency_key TEXT UNIQUE, started_at TEXT NULL, finished_at TEXT NULL, create_time TEXT, update_time TEXT)');
        Db::name('cloud_collection_tasks')->delete(true);
        Db::name('cloud_browser_profiles')->delete(true);
        Db::name('cloud_browser_profiles')->insertAll([
            ['tenant_id' => 8, 'system_hotel_id' => 80, 'owner_user_id' => 7, 'platform' => 'ctrip', 'profile_public_id' => 'cbp_ctrip_ready', 'authorization_status' => CloudBrowserProfileService::READY_TO_COLLECT, 'ready_at' => '2026-07-25 08:00:00', 'session_expires_at' => null],
            ['tenant_id' => 8, 'system_hotel_id' => 80, 'owner_user_id' => 7, 'platform' => 'meituan', 'profile_public_id' => 'cbp_meituan_ready', 'authorization_status' => CloudBrowserProfileService::READY_TO_COLLECT, 'ready_at' => '2026-07-25 08:00:00', 'session_expires_at' => null],
            ['tenant_id' => 8, 'system_hotel_id' => 81, 'owner_user_id' => 7, 'platform' => 'meituan', 'profile_public_id' => 'cbp_expired', 'authorization_status' => CloudBrowserProfileService::READY_TO_COLLECT, 'ready_at' => '2026-07-25 08:00:00', 'session_expires_at' => '2020-01-01 00:00:00'],
            ['tenant_id' => 8, 'system_hotel_id' => 82, 'owner_user_id' => 7, 'platform' => 'ctrip', 'profile_public_id' => 'cbp_ready_without_evidence', 'authorization_status' => CloudBrowserProfileService::READY_TO_COLLECT, 'ready_at' => null, 'session_expires_at' => null],
            ['tenant_id' => 8, 'system_hotel_id' => 83, 'owner_user_id' => 7, 'platform' => 'ctrip', 'profile_public_id' => 'cbp_waiting', 'authorization_status' => CloudBrowserProfileService::AWAITING_LOGIN, 'ready_at' => null, 'session_expires_at' => null],
        ]);
    }

    public function testPreviewQueuesOnlyReadyUnexpiredProfileWithDifferentFieldPriority(): void
    {
        $service = new CloudCollectionDispatchService();
        $yesterday = $service->preview(CloudCollectionDispatchService::YESTERDAY_FINAL, '2026-07-24');
        self::assertSame(2, $yesterday['task_count']);
        self::assertSame(
            ['order_amount', 'room_nights', 'order_count', 'list_exposure', 'detail_exposure', 'flow_rate', 'order_filling_num', 'order_submit_num'],
            $this->taskForPlatform($yesterday, 'ctrip')['field_priority']
        );
        self::assertSame(
            ['order_amount', 'room_nights', 'order_count', 'list_exposure', 'detail_exposure', 'flow_rate'],
            $this->taskForPlatform($yesterday, 'meituan')['field_priority']
        );
        self::assertSame(['session_expired', 'ready_evidence_missing'], array_column($yesterday['skipped'], 'reason'));
        self::assertNotContains(83, array_column($yesterday['tasks'], 'hotel_id'));
        $today = $service->preview(CloudCollectionDispatchService::TODAY_REALTIME, '2026-07-25');
        self::assertSame(
            ['list_exposure', 'detail_exposure', 'flow_rate', 'order_filling_num', 'order_submit_num', 'order_amount', 'room_nights', 'order_count'],
            $this->taskForPlatform($today, 'ctrip')['field_priority']
        );
        self::assertSame('cbp_ctrip_ready', $this->taskForPlatform($today, 'ctrip')['profile_id']);
        self::assertSame(0, (int)Db::name('cloud_collection_tasks')->count());
    }

    public function testEnqueueIsIdempotentAndTruthGateBlocksIncompleteReceipt(): void
    {
        $service = new CloudCollectionDispatchService();
        $first = $service->enqueue(CloudCollectionDispatchService::YESTERDAY_FINAL, '2026-07-24');
        $second = $service->enqueue(CloudCollectionDispatchService::YESTERDAY_FINAL, '2026-07-24');
        $firstTask = $this->taskForPlatform($first, 'ctrip');
        self::assertSame('queued', $firstTask['dispatch_status']);
        self::assertSame('reused', $this->taskForPlatform($second, 'ctrip')['dispatch_status']);
        self::assertSame(2, (int)Db::name('cloud_collection_tasks')->count());
        $receipt = $this->completeReceipt($firstTask);
        $receipt['readback_verified'] = false;
        $blocked = $service->recordReceipt($firstTask['task_id'], $receipt);
        self::assertFalse($blocked['formal_message_allowed']);
        self::assertSame(['missing_readback'], $blocked['gaps']);
        $replayed = $service->recordReceipt($firstTask['task_id'], $receipt);
        self::assertSame('reused', $replayed['receipt_status']);
        self::assertSame(
            0,
            (int)Db::name('cloud_collection_tasks')->where('task_public_id', $firstTask['task_id'])->value('formal_message_allowed')
        );
    }

    public function testTruthGateAllowsOnlyExactFullyPersistedReceipt(): void
    {
        $service = new CloudCollectionDispatchService();
        $task = $this->taskForPlatform(
            $service->enqueue(CloudCollectionDispatchService::YESTERDAY_FINAL, '2026-07-24'),
            'ctrip'
        );
        $ready = $service->recordReceipt($task['task_id'], $this->completeReceipt($task));
        self::assertTrue($ready['formal_message_allowed']);
        self::assertSame('passed', $ready['truth_gate_status']);
        self::assertSame(
            1,
            (int)Db::name('cloud_collection_tasks')->where('task_public_id', $task['task_id'])->value('formal_message_allowed')
        );
        $reused = $this->taskForPlatform(
            $service->enqueue(CloudCollectionDispatchService::YESTERDAY_FINAL, '2026-07-24'),
            'ctrip'
        );
        self::assertSame('reused', $reused['dispatch_status']);
        self::assertTrue($reused['formal_message_allowed']);
    }

    public function testBlockedReceiptCreatesOneRecoverableAttemptAndPreservesPriorEvidence(): void
    {
        $service = new CloudCollectionDispatchService();
        $firstTask = $this->taskForPlatform(
            $service->enqueue(CloudCollectionDispatchService::YESTERDAY_FINAL, '2026-07-24'),
            'ctrip'
        );
        $receipt = $this->completeReceipt($firstTask);
        $receipt['readback_verified'] = false;
        $blocked = $service->recordReceipt($firstTask['task_id'], $receipt);
        self::assertSame('blocked_by_data_gap', $blocked['truth_gate_status']);

        $backoff = $this->taskForPlatform(
            $service->enqueue(CloudCollectionDispatchService::YESTERDAY_FINAL, '2026-07-24'),
            'ctrip'
        );
        self::assertSame('retry_backoff', $backoff['dispatch_status']);
        self::assertSame($firstTask['task_id'], $backoff['task_id']);
        self::assertSame(2, (int)Db::name('cloud_collection_tasks')->count());
        $this->openRetryWindow($firstTask['task_id']);

        $retryTask = $this->taskForPlatform(
            $service->enqueue(CloudCollectionDispatchService::YESTERDAY_FINAL, '2026-07-24'),
            'ctrip'
        );
        self::assertSame('requeued', $retryTask['dispatch_status']);
        self::assertSame(2, $retryTask['attempt_no']);
        self::assertSame($firstTask['task_id'], $retryTask['retry_of_task_id']);
        self::assertNotSame($firstTask['task_id'], $retryTask['task_id']);
        self::assertSame('queued', $retryTask['task_status']);
        self::assertSame('waiting_for_identity_date_fields_save_readback', $retryTask['truth_gate_status']);
        self::assertSame(3, (int)Db::name('cloud_collection_tasks')->count());

        $prior = Db::name('cloud_collection_tasks')
            ->where('task_public_id', $firstTask['task_id'])
            ->find();
        self::assertIsArray($prior);
        self::assertSame('blocked', $prior['task_status']);
        self::assertSame('blocked_by_data_gap', $prior['truth_gate_status']);
        self::assertSame(['missing_readback'], json_decode((string)$prior['gap_codes_json'], true));
        self::assertNotSame('', trim((string)$prior['receipt_fingerprint']));
        $retryEvidence = json_decode((string)Db::name('cloud_collection_tasks')
            ->where('task_public_id', $retryTask['task_id'])
            ->value('receipt_evidence_json'), true);
        self::assertSame(2, $retryEvidence['dispatch']['attempt_no'] ?? null);
        self::assertSame($firstTask['task_id'], $retryEvidence['dispatch']['retry_of_task_id'] ?? null);

        $sameRetry = $this->taskForPlatform(
            $service->enqueue(CloudCollectionDispatchService::YESTERDAY_FINAL, '2026-07-24'),
            'ctrip'
        );
        self::assertSame('reused', $sameRetry['dispatch_status']);
        self::assertSame($retryTask['task_id'], $sameRetry['task_id']);
        self::assertSame(3, (int)Db::name('cloud_collection_tasks')->count());

        $readyReceipt = $this->completeReceipt($retryTask);
        self::assertTrue($service->recordReceipt($retryTask['task_id'], $readyReceipt)['formal_message_allowed']);
    }

    public function testPermanentGapDoesNotCreateRetryAttempt(): void
    {
        $service = new CloudCollectionDispatchService();
        $task = $this->taskForPlatform(
            $service->enqueue(CloudCollectionDispatchService::YESTERDAY_FINAL, '2026-07-23'),
            'ctrip'
        );
        $receipt = $this->completeReceipt($task);
        $receipt['identity_verified'] = false;
        self::assertContains('missing_identity', $service->recordReceipt($task['task_id'], $receipt)['gaps']);

        $blocked = $this->taskForPlatform(
            $service->enqueue(CloudCollectionDispatchService::YESTERDAY_FINAL, '2026-07-23'),
            'ctrip'
        );
        self::assertSame('blocked_requires_review', $blocked['dispatch_status']);
        self::assertFalse($blocked['retry_allowed']);
        self::assertSame(2, (int)Db::name('cloud_collection_tasks')->count());
    }

    public function testRetryAttemptsAreBoundedAndLateParentReceiptCannotCompeteWithChild(): void
    {
        $service = new CloudCollectionDispatchService();
        $targetDate = '2026-07-22';
        $task = $this->taskForPlatform(
            $service->enqueue(CloudCollectionDispatchService::YESTERDAY_FINAL, $targetDate),
            'ctrip'
        );
        $firstReceipt = $this->completeReceipt($task);
        $firstReceipt['readback_verified'] = false;
        $service->recordReceipt($task['task_id'], $firstReceipt);
        $this->openRetryWindow($task['task_id']);
        $child = $this->taskForPlatform(
            $service->enqueue(CloudCollectionDispatchService::YESTERDAY_FINAL, $targetDate),
            'ctrip'
        );

        $late = $service->recordReceipt($task['task_id'], $this->completeReceipt($task));
        self::assertSame('superseded_attempt', $late['receipt_status']);
        self::assertFalse($late['formal_message_allowed']);
        self::assertSame('blocked', Db::name('cloud_collection_tasks')
            ->where('task_public_id', $task['task_id'])->value('task_status'));
        self::assertSame('queued', Db::name('cloud_collection_tasks')
            ->where('task_public_id', $child['task_id'])->value('task_status'));

        $secondReceipt = $this->completeReceipt($child);
        $secondReceipt['readback_verified'] = false;
        $service->recordReceipt($child['task_id'], $secondReceipt);
        $this->openRetryWindow($child['task_id']);
        $third = $this->taskForPlatform(
            $service->enqueue(CloudCollectionDispatchService::YESTERDAY_FINAL, $targetDate),
            'ctrip'
        );
        self::assertSame(3, $third['attempt_no']);
        $thirdReceipt = $this->completeReceipt($third);
        $thirdReceipt['readback_verified'] = false;
        $service->recordReceipt($third['task_id'], $thirdReceipt);
        $this->openRetryWindow($third['task_id']);

        $exhausted = $this->taskForPlatform(
            $service->enqueue(CloudCollectionDispatchService::YESTERDAY_FINAL, $targetDate),
            'ctrip'
        );
        self::assertSame('retry_exhausted', $exhausted['dispatch_status']);
        self::assertSame($third['task_id'], $exhausted['task_id']);
        self::assertSame(4, (int)Db::name('cloud_collection_tasks')->count());
    }

    public function testLegacyPassedReceiptFingerprintRemainsIdempotentAfterUpgrade(): void
    {
        $service = new CloudCollectionDispatchService();
        $task = $this->taskForPlatform(
            $service->enqueue(CloudCollectionDispatchService::YESTERDAY_FINAL, '2026-07-21'),
            'ctrip'
        );
        $receipt = $this->completeReceipt($task);
        self::assertTrue($service->recordReceipt($task['task_id'], $receipt)['formal_message_allowed']);
        $row = Db::name('cloud_collection_tasks')->where('task_public_id', $task['task_id'])->find();
        self::assertIsArray($row);
        $legacyEvidence = json_decode((string)$row['receipt_evidence_json'], true);
        unset($legacyEvidence['dispatch']);
        $legacyFingerprint = hash('sha256', (string)json_encode(
            $legacyEvidence,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
        Db::name('cloud_collection_tasks')->where('id', (int)$row['id'])->update([
            'receipt_evidence_json' => json_encode($legacyEvidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'receipt_fingerprint' => $legacyFingerprint,
        ]);

        $replayed = $service->recordReceipt($task['task_id'], $receipt);
        self::assertSame('reused', $replayed['receipt_status']);
        self::assertSame('passed', $replayed['truth_gate_status']);
        self::assertTrue($replayed['formal_message_allowed']);
        self::assertSame('truth_ready', Db::name('cloud_collection_tasks')
            ->where('task_public_id', $task['task_id'])->value('task_status'));
    }

    public function testTruthGateRejectsLooseBooleansAndMissingExactEvidenceAtEveryStage(): void
    {
        $service = new CloudCollectionDispatchService();
        $mutations = [
            'identity' => static function (array $receipt): array {
                $receipt['identity_verified'] = 'true';
                return $receipt;
            },
            'target_date' => static function (array $receipt): array {
                $receipt['target_date'] = '2026-07-23';
                return $receipt;
            },
            'fields' => static function (array $receipt): array {
                array_pop($receipt['collected_fields']);
                return $receipt;
            },
            'saved' => static function (array $receipt): array {
                $receipt['saved_count'] = 0;
                return $receipt;
            },
            'readback' => static function (array $receipt): array {
                $receipt['readback_count']--;
                return $receipt;
            },
        ];
        $day = 10;
        foreach ($mutations as $gap => $mutate) {
            $targetDate = sprintf('2026-07-%02d', $day++);
            $task = $this->taskForPlatform(
                $service->enqueue(CloudCollectionDispatchService::YESTERDAY_FINAL, $targetDate),
                'ctrip'
            );
            $receipt = $this->completeReceipt($task);
            $receipt['target_date'] = $targetDate;
            $blocked = $service->recordReceipt($task['task_id'], $mutate($receipt));
            self::assertFalse($blocked['formal_message_allowed'], $gap);
            self::assertContains('missing_' . $gap, $blocked['gaps'], $gap);
        }
    }

    public function testConflictingReceiptAfterPassFailsClosed(): void
    {
        $service = new CloudCollectionDispatchService();
        $task = $this->taskForPlatform(
            $service->enqueue(CloudCollectionDispatchService::YESTERDAY_FINAL, '2026-07-24'),
            'ctrip'
        );
        $receipt = $this->completeReceipt($task);
        self::assertTrue($service->recordReceipt($task['task_id'], $receipt)['formal_message_allowed']);
        $receipt['readback_verified'] = false;
        $conflict = $service->recordReceipt($task['task_id'], $receipt);
        self::assertFalse($conflict['formal_message_allowed']);
        self::assertContains('receipt_conflict', $conflict['gaps']);
    }

    /** @param array<string,mixed> $dispatch @return array<string,mixed> */
    private function taskForPlatform(array $dispatch, string $platform): array
    {
        foreach ($dispatch['tasks'] as $task) {
            if (($task['platform'] ?? null) === $platform) {
                return $task;
            }
        }
        self::fail('Task not found for platform ' . $platform);
    }

    /** @param array<string,mixed> $task @return array<string,mixed> */
    private function completeReceipt(array $task): array
    {
        return [
            'identity_verified' => true,
            'profile_id' => $task['profile_id'],
            'tenant_id' => $task['tenant_id'],
            'hotel_id' => $task['hotel_id'],
            'owner_user_id' => $task['owner_user_id'],
            'platform' => $task['platform'],
            'platform_hotel_id' => 'platform-store-80',
            'target_date' => $task['target_date'],
            'required_fields_present' => true,
            'collected_fields' => $task['field_priority'],
            'saved' => true,
            'saved_count' => 2,
            'readback_verified' => true,
            'readback_count' => 2,
            'readback_fields' => $task['field_priority'],
        ];
    }

    private function openRetryWindow(string $taskPublicId): void
    {
        $row = Db::name('cloud_collection_tasks')->where('task_public_id', $taskPublicId)->find();
        self::assertIsArray($row);
        $evidence = json_decode((string)$row['receipt_evidence_json'], true);
        self::assertIsArray($evidence);
        $evidence['dispatch']['retry_not_before'] = '2000-01-01 00:00:00';
        Db::name('cloud_collection_tasks')->where('id', (int)$row['id'])->update([
            'receipt_evidence_json' => json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'receipt_fingerprint' => hash('sha256', (string)json_encode(
                $evidence,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            )),
        ]);
    }
}
