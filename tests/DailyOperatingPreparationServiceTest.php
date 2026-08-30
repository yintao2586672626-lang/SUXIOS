<?php
declare(strict_types=1);

namespace Tests;

use app\service\DailyOperatingPreparationService;
use PHPUnit\Framework\TestCase;

final class DailyOperatingPreparationServiceTest extends TestCase
{
    public function testPreparesOnePendingPriorityAndOneBackgroundBroadcastWithoutExternalWrites(): void
    {
        $calls = [];
        $service = new DailyOperatingPreparationService(
            static fn(int $tenantId, int $hotelId): array => [
                'status' => 'ready',
                'actor_id' => 7,
                'resolution' => 'active_collection_plan_owner',
                'reason_code' => '',
            ],
            static function (
                int $tenantId,
                int $hotelId,
                int $actorId,
                string $businessDate
            ) use (&$calls): array {
                $calls[] = ['priority', $tenantId, $hotelId, $actorId, $businessDate];
                return [
                    'run' => ['id' => 101],
                    'execution_intent_id' => 202,
                    'execution_task_count' => 0,
                    'lifecycle_status' => 'pending_approval',
                    'readback_verified' => true,
                    'automation_status' => 'created_pending_approval',
                    'existing_item_preserved' => false,
                    'source_changed' => false,
                ];
            },
            static function (int $hotelId, string $businessDate) use (&$calls): array {
                $calls[] = ['broadcast', $hotelId, $businessDate];
                return [
                    'snapshot_id' => 303,
                    'version_no' => 1,
                    'facts_broadcast_status' => 'facts_broadcast_ready',
                    'analysis_status' => 'analysis_blocked',
                    'generation_trigger' => 'background',
                    'view_status' => 'pending_view',
                    'persisted' => true,
                    'readback_verified' => true,
                ];
            },
            static fn(): bool => true,
            static fn(): array => ['status' => 'ready', 'reason_code' => '']
        );

        $result = $service->prepare(80, 80, '2026-08-28');

        self::assertSame('prepared', $result['status']);
        self::assertSame(7, $result['actor']['actor_id']);
        self::assertSame(101, $result['daily_priority']['run_id']);
        self::assertSame(202, $result['daily_priority']['execution_intent_id']);
        self::assertSame('pending_approval', $result['daily_priority']['lifecycle_status']);
        self::assertSame(0, $result['daily_priority']['execution_task_count']);
        self::assertSame(303, $result['trusted_broadcast']['snapshot_id']);
        self::assertSame('background', $result['trusted_broadcast']['generation_trigger']);
        self::assertSame('pending_view', $result['trusted_broadcast']['view_status']);
        self::assertFalse($result['automatic_approval']);
        self::assertFalse($result['automatic_execution']);
        self::assertSame(0, $result['external_write_count']);
        self::assertSame(0, $result['external_message_count']);
        self::assertFalse($result['message_sent']);
        self::assertSame([
            ['broadcast', 80, '2026-08-28'],
            ['priority', 80, 80, 7, '2026-08-28'],
        ], $calls);
    }

    public function testMissingActorBlocksPriorityButStillPersistsAvailableBroadcast(): void
    {
        $priorityCalls = 0;
        $service = new DailyOperatingPreparationService(
            static fn(): array => [
                'status' => 'blocked',
                'actor_id' => 0,
                'resolution' => 'unresolved',
                'reason_code' => 'daily_operating_actor_unavailable',
            ],
            static function () use (&$priorityCalls): array {
                $priorityCalls++;
                return [];
            },
            static fn(): array => [
                'snapshot_id' => 9,
                'version_no' => 1,
                'facts_broadcast_status' => 'facts_broadcast_ready',
                'analysis_status' => 'analysis_blocked',
                'generation_trigger' => 'background',
                'view_status' => 'pending_view',
                'persisted' => true,
                'readback_verified' => true,
            ],
            static fn(): bool => true,
            static fn(): array => ['status' => 'ready', 'reason_code' => '']
        );

        $result = $service->prepare(80, 80, '2026-08-28');

        self::assertSame('partial', $result['status']);
        self::assertSame(0, $priorityCalls);
        self::assertSame('blocked', $result['daily_priority']['status']);
        self::assertSame('daily_operating_actor_unavailable', $result['daily_priority']['reason_code']);
        self::assertTrue($result['trusted_broadcast']['readback_verified']);
        self::assertSame(0, $result['external_message_count']);
    }

    public function testPreparationFailuresStayBoundedAndDoNotClaimWrites(): void
    {
        $service = new DailyOperatingPreparationService(
            static fn(): array => [
                'status' => 'ready',
                'actor_id' => 7,
                'resolution' => 'hotel_owner',
            ],
            static fn(): never => throw new \RuntimeException('strict fact layer unavailable'),
            static fn(): never => throw new \RuntimeException('snapshot table missing'),
            static fn(): bool => true,
            static fn(): array => ['status' => 'ready', 'reason_code' => '']
        );

        $result = $service->prepare(80, 80, '2026-08-28');

        self::assertSame('blocked', $result['status']);
        self::assertSame('strict_fact_layer_unavailable', $result['daily_priority']['reason_code']);
        self::assertSame('snapshot_table_missing', $result['trusted_broadcast']['reason_code']);
        self::assertSame(0, $result['external_write_count']);
        self::assertSame(0, $result['external_message_count']);
    }

    public function testDailyPreparationRetriesOnlyTheInternalNoMessageCommand(): void
    {
        $root = dirname(__DIR__);
        $command = (string)file_get_contents($root . '/app/command/PrepareDailyOperating.php');
        $config = (string)file_get_contents($root . '/config/console.php');
        $automation = (string)file_get_contents($root . '/app/service/CloudAutomationService.php');
        $timer = (string)file_get_contents($root . '/deploy/systemd/suxios-cloud-daily.timer');
        $retryService = (string)file_get_contents($root . '/deploy/systemd/suxios-daily-operating-preparation@.service');
        $retryTimer = (string)file_get_contents($root . '/deploy/systemd/suxios-daily-operating-preparation@.timer');

        self::assertStringContainsString("setName('operation:prepare-daily')", $command);
        self::assertStringContainsString('DailyOperatingPreparationService', $command);
        self::assertStringContainsString("'operation:prepare-daily'", $config);
        self::assertStringContainsString('DailyOperatingPreparationService', $automation);
        self::assertStringContainsString('suxios-cloud-automation@daily.service', $timer);
        self::assertStringContainsString('operation:prepare-daily --hotel-id=%i', $retryService);
        self::assertStringContainsString('Restart=on-failure', $retryService);
        self::assertStringContainsString('RestartSec=5min', $retryService);
        self::assertStringContainsString('StartLimitBurst=6', $retryService);
        self::assertStringContainsString("=== 'prepared' ? 0 : 2", $command);
        self::assertStringNotContainsString("['prepared', 'partial', 'blocked']", $command);
        self::assertStringContainsString('OnCalendar=*-*-* 09:05:00 Asia/Shanghai', $retryTimer);
        self::assertStringNotContainsString('wechat', strtolower($command . $timer . $retryService . $retryTimer));
        self::assertStringNotContainsString('send', strtolower($retryService . $retryTimer));
        self::assertStringNotContainsString('approve', strtolower($retryService . $retryTimer));
    }

    public function testScopeMismatchPreventsBothGeneratorsFromRunning(): void
    {
        $calls = 0;
        $service = new DailyOperatingPreparationService(
            static fn(): array => ['status' => 'ready', 'actor_id' => 7],
            static function () use (&$calls): array { $calls++; return []; },
            static function () use (&$calls): array { $calls++; return []; },
            static fn(): bool => false,
            static fn(): array => ['status' => 'ready', 'reason_code' => '']
        );

        $this->expectException(\RuntimeException::class);
        try {
            $service->prepare(81, 80, '2026-08-28');
        } finally {
            self::assertSame(0, $calls);
        }
    }

    public function testRunningCollectionDefersWithoutFreezingArtifacts(): void
    {
        $calls = 0;
        $service = new DailyOperatingPreparationService(
            static fn(): array => ['status' => 'ready', 'actor_id' => 7],
            static function () use (&$calls): array { $calls++; return []; },
            static function () use (&$calls): array { $calls++; return []; },
            static fn(): bool => true,
            static fn(): array => ['status' => 'waiting', 'reason_code' => 'daily_collection_still_running']
        );

        $result = $service->prepare(80, 80, '2026-08-28');
        self::assertSame('waiting_for_collection', $result['status']);
        self::assertSame(0, $calls);
        self::assertFalse($result['daily_priority']['readback_verified']);
        self::assertFalse($result['trusted_broadcast']['readback_verified']);
    }
}
