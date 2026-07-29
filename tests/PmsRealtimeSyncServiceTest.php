<?php
declare(strict_types=1);

namespace tests;

use app\service\PmsRealtimeSyncService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class PmsRealtimeSyncServiceTest extends TestCase
{
    public function testRealtimeSyncRunsIsolatedCollectorAndVerifiesDatabaseReadback(): void
    {
        $command = [];
        $service = new PmsRealtimeSyncService(
            bindingResolver: static fn(): array => [
                'binding_status' => 'configured',
                'selected_provider' => 'dingdandao_pms',
            ],
            receiptLoader: static fn(): array => [
                'execution_mode' => 'local_shared_browser_sandbox',
                'hotel_id' => 80,
                'owner_user_id' => 7,
                'sandbox_id' => 'sbx_dingdandao_h80_primary',
            ],
            cdpProbe: static fn(string $url): bool => $url === 'http://127.0.0.1:9223',
            processRunner: static function (array $input) use (&$command): array {
                $command = $input;
                return [
                    'exit_code' => 0,
                    'stdout' => json_encode([
                        'status' => 'saved_and_readback_verified',
                        'hotel_id' => 80,
                        'target_date' => '2026-07-30',
                        'capture_id' => 321,
                    ], JSON_UNESCAPED_SLASHES),
                    'stderr' => '',
                ];
            },
            captureReader: static fn(): array => [
                'id' => 321,
                'hotel_id' => 80,
                'business_date' => '2026-07-30',
                'captured_at' => '2026-07-30 09:45:00',
                'identity_status' => 'matched',
                'quality_status' => 'verified',
                'readback_status' => 'readback_verified',
            ],
            clock: static fn(): DateTimeImmutable => new DateTimeImmutable(
                '2026-07-30 09:45:05',
                new DateTimeZone('Asia/Shanghai')
            ),
            projectRoot: dirname(__DIR__),
            phpBinary: PHP_BINARY
        );

        $result = $service->sync(1, 80, 7, '2026-07-30');

        self::assertSame('synced', $result['status']);
        self::assertTrue($result['live_read']);
        self::assertTrue($result['readback_verified']);
        self::assertSame(321, $result['capture_id']);
        self::assertContains('--sandbox-id=sbx_dingdandao_h80_primary', $command);
        self::assertContains('--require-sandbox', $command);
        self::assertNotContains('--push', $command);
    }

    public function testHistoricalDateIsNeverPresentedAsRealtimeRefresh(): void
    {
        $collectorCalled = false;
        $service = new PmsRealtimeSyncService(
            processRunner: static function () use (&$collectorCalled): array {
                $collectorCalled = true;
                return [];
            },
            clock: static fn(): DateTimeImmutable => new DateTimeImmutable(
                '2026-07-30 09:45:05',
                new DateTimeZone('Asia/Shanghai')
            )
        );

        $result = $service->sync(1, 80, 7, '2026-07-29');

        self::assertSame('blocked', $result['status']);
        self::assertSame('pms_live_today_only', $result['blocker_code']);
        self::assertFalse($result['live_read']);
        self::assertFalse($collectorCalled);
    }

    public function testUnavailableBrowserSessionReturnsLoginBlockerWithoutReadingOldData(): void
    {
        $service = new PmsRealtimeSyncService(
            bindingResolver: static fn(): array => [
                'binding_status' => 'configured',
                'selected_provider' => 'dingdandao_pms',
            ],
            receiptLoader: static fn(): array => [
                'execution_mode' => 'local_shared_browser_sandbox',
                'hotel_id' => 80,
                'owner_user_id' => 7,
                'sandbox_id' => 'sbx_dingdandao_h80_primary',
            ],
            cdpProbe: static fn(): bool => false,
            clock: static fn(): DateTimeImmutable => new DateTimeImmutable(
                '2026-07-30 09:45:05',
                new DateTimeZone('Asia/Shanghai')
            )
        );

        $result = $service->sync(1, 80, 7, '2026-07-30');

        self::assertSame('blocked', $result['status']);
        self::assertSame('pms_live_session_unavailable', $result['blocker_code']);
        self::assertTrue($result['requires_login']);
        self::assertFalse($result['saved']);
        self::assertFalse($result['readback_verified']);
    }

    public function testAuthenticatedRouteExposesExplicitRealtimeSyncOnly(): void
    {
        $routes = (string)file_get_contents(dirname(__DIR__) . '/route/app.php');
        self::assertStringContainsString(
            "Route::post('/pms/realtime-sync', 'OperatingTarget/syncSelectedPmsRealtime')",
            $routes
        );
    }
}
