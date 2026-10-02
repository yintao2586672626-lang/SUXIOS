<?php
declare(strict_types=1);

use app\service\AutomationRunMonitorService;
use PHPUnit\Framework\TestCase;

final class AutomationDeliveryDateAuditTest extends TestCase
{
    private function overview(int $todayCount, array $tasks = [], string $receiptStatus = 'verified', string $dispatchStatus = 'sent'): array
    {
        $service = new AutomationRunMonitorService(
            fn(): array => [],
            fn(): array => [
                'binding_status' => 'configured',
                'selected_provider' => 'dingdandao_pms',
                'selected_source' => [
                    'provider' => 'dingdandao_pms',
                    'fact_gate' => ['allowed' => false, 'blockers' => []],
                    'latest_dispatch' => [
                        'business_date' => '2026-07-30',
                        'delivery_status' => $dispatchStatus,
                        'delivered_at' => '2026-07-30 01:36:30',
                    ],
                ],
            ],
            fn(): array => ['tasks' => $tasks],
            new DateTimeImmutable('2026-09-26 22:44:00', new DateTimeZone('Asia/Shanghai')),
            fn(): array => [7],
            fn(): array => [7 => ['delivery' => [
                'status' => $receiptStatus,
                'success_count' => $todayCount,
                'last_success_at' => $todayCount ? '2026-09-26 20:00:00' : null,
            ]]]
        );
        return $service->overview([['id' => 7, 'tenant_id' => 2, 'name' => 'SYNTHETIC AUDIT']], '2026-09-26', 9);
    }

    public function testHistoricalReceiptCannotBecomeTodayPushSuccess(): void
    {
        $result = $this->overview(0);
        self::assertSame(0, $result['rows'][0]['push_success_count']);
        self::assertSame(0, $result['summary']['push_succeeded_count'], 'Historical July receipt must not count as September delivery.');
        self::assertNotSame('sent', $result['rows'][0]['push_status']);
    }

    public function testConfirmedTodayReceiptCountsAsSuccess(): void
    {
        $result = $this->overview(1);
        self::assertSame(1, $result['summary']['push_succeeded_count']);
        self::assertSame('2026-09-26 20:00:00', $result['rows'][0]['push_result_at']);
    }

    public function testUnverifiedCountCannotEstablishDelivery(): void
    {
        $result=$this->overview(1, [], 'read_failed');
        self::assertSame(0, $result['summary']['push_succeeded_count']);
        self::assertNotSame('sent', $result['rows'][0]['push_status']);
        self::assertNull($result['rows'][0]['push_success_count']);
    }

    public function testTaskTextCannotSubstituteForCurrentBusinessDateReceipt(): void
    {
        foreach (['2026-09-26', '2026-07-30', ''] as $date) {
            $result=$this->overview(0, [['business_date'=>$date,'last_run_at'=>'2026-09-26 20:00:00',
                'last_result'=>'已发送']], 'verified', '');
            self::assertSame(0, $result['summary']['push_succeeded_count']);
            self::assertNotSame('sent', $result['rows'][0]['push_status']);
        }
    }

    public function testHistoricalDispatchFailureDoesNotBecomeTargetDayPushFailure(): void
    {
        $result=$this->overview(0, [], 'verified', 'failed');
        self::assertNotSame('failed', $result['rows'][0]['push_status']);
    }
}
