<?php
declare(strict_types=1);

namespace Tests;

use app\service\CloudAutomationStateStore;
use app\service\CloudAutomationService;
use PHPUnit\Framework\TestCase;

final class CloudAutomationStateStoreTest extends TestCase
{
    private string $stateDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stateDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'suxi-cloud-state-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->removeTestDirectory($this->stateDir);
        parent::tearDown();
    }

    public function testQueuedMessagePersistsAndSentMessageIsIdempotent(): void
    {
        $store = new CloudAutomationStateStore($this->stateDir);
        $payload = ['msgtype' => 'markdown', 'markdown' => ['content' => '测试消息']];
        $record = $store->queueDelivery(
            'daily_report',
            7,
            ['report_id' => 12, 'report_date' => '2026-07-21'],
            $payload,
            ['collection_triggered' => false]
        );

        self::assertSame('queued', $record['status']);
        self::assertCount(1, $store->dueDeliveries());

        $sent = $store->recordDeliveryAttempt($record, [
            'delivery_status' => 'sent',
            'robot_count' => 1,
            'sent_count' => 1,
            'failed_count' => 0,
            'failures' => [],
        ]);
        self::assertSame('sent', $sent['status']);
        self::assertSame([], $store->dueDeliveries());

        $same = $store->queueDelivery(
            'daily_report',
            7,
            ['report_id' => 12, 'report_date' => '2026-07-21'],
            $payload,
            ['collection_triggered' => false]
        );
        self::assertSame($sent['delivery_key'], $same['delivery_key']);
        self::assertSame('sent', $same['status']);
        self::assertSame(1, $same['attempts']);
    }

    public function testPartialDeliveryKeepsOnlyFailedRobotIdsForMessageRetry(): void
    {
        $store = new CloudAutomationStateStore($this->stateDir);
        $record = $store->queueDelivery(
            'data_health_alert',
            7,
            ['target_date' => '2026-07-21', 'issue_codes' => ['login_expired']],
            ['msgtype' => 'markdown', 'markdown' => ['content' => '登录过期']],
            ['collection_triggered' => false, 'report_generation_triggered' => false]
        );
        $updated = $store->recordDeliveryAttempt($record, [
            'delivery_status' => 'partial',
            'robot_count' => 2,
            'sent_count' => 1,
            'failed_count' => 1,
            'failures' => [['robot_id' => 9, 'reason' => 'timeout']],
        ], 10);

        self::assertSame('partial', $updated['status']);
        self::assertSame([9], $updated['pending_robot_ids']);
        self::assertSame(1, $updated['attempts']);
        self::assertNotEmpty($updated['next_retry_at']);
        self::assertFalse((bool)($updated['context']['collection_triggered'] ?? true));
        self::assertFalse((bool)($updated['context']['report_generation_triggered'] ?? true));
    }

    public function testSendingBoundarySuppressesDuplicateRequestAndSentReplay(): void
    {
        $store = new CloudAutomationStateStore($this->stateDir);
        $payload = ['msgtype' => 'markdown', 'markdown' => ['content' => 'idempotent report']];
        $identity = ['report_id' => 12, 'report_date' => '2026-07-21', 'scope' => 'ota_channel'];
        $record = $store->queueDelivery('daily_report', 7, $identity, $payload);
        $sending = $store->beginDeliveryAttempt($record);
        self::assertSame('sending', $sending['status']);
        self::assertSame([], $store->dueDeliveries());

        $service = new CloudAutomationService($store);
        $ambiguous = $service->deliverSavedDailyReport(7, 12, '2026-07-21', $payload);
        self::assertSame('in_progress', $ambiguous['delivery_status']);
        self::assertSame(0, $ambiguous['sent_count']);

        $sent = $store->recordDeliveryAttempt($sending, [
            'delivery_status' => 'sent',
            'robot_count' => 1,
            'sent_count' => 1,
            'failed_count' => 0,
            'failures' => [],
        ]);
        self::assertSame('sent', $sent['status']);

        $replayed = $service->deliverSavedDailyReport(7, 12, '2026-07-21', $payload);
        self::assertSame('sent', $replayed['delivery_status']);
        self::assertTrue($replayed['idempotent_replay']);
        self::assertSame(1, $replayed['attempts']);
    }

    public function testAmbiguousNetworkOutcomeRequiresManualConfirmationWithoutRetry(): void
    {
        $store = new CloudAutomationStateStore($this->stateDir);
        $payload = ['msgtype' => 'markdown', 'markdown' => ['content' => 'ambiguous report']];
        $record = $store->queueDelivery(
            'daily_report',
            7,
            ['report_id' => 13, 'report_date' => '2026-07-21', 'scope' => 'ota_channel'],
            $payload
        );
        $sending = $store->beginDeliveryAttempt($record);
        $unknown = $store->recordDeliveryAttempt($sending, [
            'delivery_status' => 'failed',
            'robot_count' => 1,
            'sent_count' => 0,
            'failed_count' => 1,
            'failures' => [['robot_id' => 9, 'reason' => 'timeout', 'ambiguous' => true]],
        ]);
        self::assertSame('delivery_outcome_unknown', $unknown['status']);
        self::assertTrue($unknown['last_result']['outcome_ambiguous']);
        self::assertSame([], $store->dueDeliveries());

        $service = new CloudAutomationService($store);
        $repeated = $service->deliverSavedDailyReport(7, 13, '2026-07-21', $payload);
        self::assertSame('in_progress', $repeated['delivery_status']);
        self::assertSame('previous_delivery_outcome_ambiguous', $repeated['reason']);
        self::assertSame(0, $repeated['sent_count']);
    }

    private function removeTestDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeTestDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
