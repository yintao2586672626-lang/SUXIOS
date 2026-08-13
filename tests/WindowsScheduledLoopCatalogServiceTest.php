<?php
declare(strict_types=1);

namespace Tests;

use app\service\WindowsScheduledLoopCatalogService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class WindowsScheduledLoopCatalogServiceTest extends TestCase
{
    public function testCatalogKeepsSchedulerFactsSeparateAndFiltersHotelScope(): void
    {
        $service = new WindowsScheduledLoopCatalogService(
            fn(): array => $this->receipt(),
            'Windows',
            new DateTimeImmutable('2026-08-12 22:45:00', new DateTimeZone('Asia/Shanghai'))
        );

        $result = $service->overview([
            ['id' => 80, 'tenant_id' => 7, 'name' => '酒店 80'],
        ], false);

        self::assertSame('ready', $result['status']);
        self::assertTrue($result['readback_verified']);
        self::assertCount(2, $result['items']);
        $items = array_column($result['items'], null, 'name');
        self::assertArrayHasKey('SUXIOS OTA Dispatcher H80', $items);
        self::assertArrayHasKey('Dingdandao H80 Historical D1', $items);
        self::assertArrayNotHasKey('SUXIOS Hotel Autopilot Coordinator', $items);
        self::assertArrayNotHasKey('SUXIOS Meituan Temporal H81', $items);
        self::assertSame(7, $items['SUXIOS OTA Dispatcher H80']['tenant_id']);
        self::assertSame('disabled', $items['SUXIOS OTA Dispatcher H80']['status']);
        self::assertSame('scheduler_completed', $items['SUXIOS OTA Dispatcher H80']['last_result_status']);
        self::assertStringContainsString('仅代表调度进程', $items['SUXIOS OTA Dispatcher H80']['last_result_summary']);
        self::assertSame('nonzero', $items['Dingdandao H80 Historical D1']['last_result_status']);
        self::assertSame(1, $result['summary']['enabled_count']);
        self::assertSame(1, $result['summary']['disabled_count']);
        self::assertFalse($result['sensitive_values_exposed']);
        self::assertArrayNotHasKey('arguments', $items['SUXIOS OTA Dispatcher H80']);
        self::assertArrayNotHasKey('principal', $items['SUXIOS OTA Dispatcher H80']);
    }

    public function testSuperAdminSeesGlobalTasksAndUnknownPurposeStaysExplicit(): void
    {
        $service = new WindowsScheduledLoopCatalogService(
            fn(): array => $this->receipt(),
            'Windows'
        );
        $result = $service->overview([
            ['id' => 80, 'tenant_id' => 7, 'name' => '酒店 80'],
        ], true);

        $items = array_column($result['items'], null, 'name');
        self::assertArrayHasKey('SUXIOS Hotel Autopilot Coordinator', $items);
        self::assertSame('disabled', $items['SUXIOS Hotel Autopilot Coordinator']['desired_state']);
        self::assertStringContainsString('重新启用 OTA Dispatcher', $items['SUXIOS Hotel Autopilot Coordinator']['risk_note']);
        self::assertArrayHasKey('SUXIOS Unregistered Daily Scan', $items);
        self::assertSame('unregistered', $items['SUXIOS Unregistered Daily Scan']['catalog_status']);
        self::assertStringContainsString('用途尚未登记', $items['SUXIOS Unregistered Daily Scan']['purpose']);
    }

    public function testUnsupportedPlatformFailsClosedWithoutHidingApplicationLoops(): void
    {
        $service = new WindowsScheduledLoopCatalogService(null, 'Linux');
        $result = $service->overview([], true);

        self::assertSame('unsupported', $result['status']);
        self::assertSame('windows_task_scheduler_unsupported_platform', $result['reason_code']);
        self::assertFalse($result['readback_verified']);
        self::assertSame([], $result['items']);
        self::assertCount(3, $result['application_loops']);
    }

    public function testInvalidReceiptRemainsUnavailableInsteadOfInventingRows(): void
    {
        $service = new WindowsScheduledLoopCatalogService(fn(): string => 'not-json', 'Windows');
        $result = $service->overview([], true);

        self::assertSame('unavailable', $result['status']);
        self::assertSame('windows_task_scheduler_read_failed', $result['reason_code']);
        self::assertSame([], $result['items']);
    }

    /** @return array<string, mixed> */
    private function receipt(): array
    {
        $daily = [[
            'type' => 'MSFT_TaskDailyTrigger',
            'start_boundary' => '2026-08-12T08:30:00+08:00',
            'days_interval' => '1',
            'weeks_interval' => '',
            'repetition_interval' => '',
            'repetition_duration' => '',
        ]];
        return [
            'status' => 'ready',
            'reason_code' => null,
            'observed_at' => '2026-08-12 22:44:12',
            'readback_verified' => true,
            'items' => [
                $this->item('SUXIOS OTA Dispatcher H80', false, 'Ready', 0, $daily),
                $this->item('Dingdandao H80 Historical D1', true, 'Ready', 1, $daily),
                $this->item('SUXIOS Meituan Temporal H81', true, 'Ready', 2, $daily),
                $this->item('SUXIOS Hotel Autopilot Coordinator', false, 'Disabled', 0, $daily),
                $this->item('SUXIOS Unregistered Daily Scan', false, 'Disabled', 0, $daily),
            ],
        ];
    }

    /** @param array<int, array<string, string>> $triggers @return array<string, mixed> */
    private function item(string $name, bool $enabled, string $state, int $result, array $triggers): array
    {
        return [
            'task_path' => '\\',
            'task_name' => $name,
            'enabled' => $enabled,
            'state' => $state,
            'last_run_at' => '2026-08-12 20:00:00',
            'next_run_at' => '2026-08-13 08:30:00',
            'last_result' => $result,
            'triggers' => $triggers,
            'readback_verified' => true,
            'arguments' => '--secret=must-not-leak',
            'principal' => 'Administrator',
        ];
    }
}
