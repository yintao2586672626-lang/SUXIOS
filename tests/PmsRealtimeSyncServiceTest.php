<?php
declare(strict_types=1);

namespace tests;

use app\service\DingdandaoOperatingTargetCaptureService;
use app\service\PmsRealtimeSyncService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class PmsRealtimeSyncServiceTest extends TestCase
{
    public function testSameOwnerFullDiagnosticReceiptProvidesRealtimeSandbox(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'suxi-pms-receipt-' . bin2hex(random_bytes(6));
        $receiptDirectory = $root . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'dingdandao_local_scheduler'
            . DIRECTORY_SEPARATOR . 'hotel_80'
            . DIRECTORY_SEPARATOR . 'user_7'
            . DIRECTORY_SEPARATOR . 'full_diagnostic';
        self::assertTrue(mkdir($receiptDirectory, 0700, true));
        $receiptPath = $receiptDirectory . DIRECTORY_SEPARATOR . 'latest.json';
        self::assertNotFalse(file_put_contents(
            $receiptPath,
            json_encode(self::trustedReceipt(), JSON_UNESCAPED_SLASHES)
        ));

        try {
            $service = new PmsRealtimeSyncService(projectRoot: $root);
            $loader = new \ReflectionMethod($service, 'loadLocalRunnerReceipt');

            $receipt = $loader->invoke($service, 80, 7);

            self::assertSame(
                'sbx_dingdandao_h80_primary',
                $receipt['sandbox_id'] ?? null
            );
            self::assertSame([], $loader->invoke($service, 80, 8));
            self::assertSame([], $loader->invoke($service, 81, 7));
        } finally {
            @unlink($receiptPath);
            @rmdir($receiptDirectory);
            @rmdir(dirname($receiptDirectory));
            @rmdir(dirname($receiptDirectory, 2));
            @rmdir(dirname($receiptDirectory, 3));
            @rmdir(dirname($receiptDirectory, 4));
            @rmdir(dirname($receiptDirectory, 5));
            @rmdir($root);
        }
    }

    public function testRealtimeSyncRunsIsolatedCollectorAndVerifiesDatabaseReadback(): void
    {
        $command = [];
        $service = new PmsRealtimeSyncService(
            bindingResolver: static fn(): array => [
                'binding_status' => 'configured',
                'selected_provider' => 'dingdandao_pms',
            ],
            receiptLoader: static fn(): array => self::trustedReceipt(),
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
                'source_scope' => DingdandaoOperatingTargetCaptureService::SOURCE_SCOPE,
                'captured_at' => '2026-07-30 09:45:00',
                'identity_status' => 'matched',
                'quality_status' => 'verified',
                'readback_status' => 'readback_verified',
            ],
            captureValidator: static fn(): bool => true,
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
        self::assertContains('--collection-mode=operating_indicators', $command);
        self::assertContains('--require-sandbox', $command);
        self::assertNotContains('--push', $command);
    }

    public function testHistoricalDateRunsSingleDateRecoveryWithoutCallingItLive(): void
    {
        $command = [];
        $service = new PmsRealtimeSyncService(
            bindingResolver: static fn(): array => [
                'binding_status' => 'configured',
                'selected_provider' => 'dingdandao_pms',
            ],
            receiptLoader: static fn(): array => self::trustedReceipt(),
            cdpProbe: static fn(): bool => true,
            processRunner: static function (array $input) use (&$command): array {
                $command = $input;
                return [
                    'exit_code' => 0,
                    'stdout' => json_encode([
                        'status' => 'saved_and_readback_verified',
                        'hotel_id' => 80,
                        'target_date' => '2026-07-29',
                        'capture_id' => 322,
                    ], JSON_UNESCAPED_SLASHES),
                    'stderr' => '',
                ];
            },
            captureReader: static fn(): array => [
                'id' => 322,
                'hotel_id' => 80,
                'business_date' => '2026-07-29',
                'source_scope' =>
                    DingdandaoOperatingTargetCaptureService::HISTORICAL_SOURCE_SCOPE,
                'captured_at' => '2026-07-30 09:45:00',
                'identity_status' => 'matched',
                'quality_status' => 'verified',
                'readback_status' => 'readback_verified',
            ],
            captureValidator: static fn(
                array $capture,
                int $tenantId,
                int $hotelId,
                string $targetDate,
                string $sourceScope
            ): bool =>
                $targetDate === '2026-07-29'
                && $sourceScope
                    === DingdandaoOperatingTargetCaptureService::HISTORICAL_SOURCE_SCOPE,
            clock: static fn(): DateTimeImmutable => new DateTimeImmutable(
                '2026-07-30 09:45:05',
                new DateTimeZone('Asia/Shanghai')
            ),
            projectRoot: dirname(__DIR__),
            phpBinary: PHP_BINARY
        );

        $result = $service->sync(1, 80, 7, '2026-07-29');

        self::assertSame('synced', $result['status']);
        self::assertFalse($result['live_read']);
        self::assertTrue($result['historical_read']);
        self::assertSame(
            DingdandaoOperatingTargetCaptureService::HISTORICAL_SOURCE_SCOPE,
            $result['source_scope']
        );
        self::assertContains('--target-date=2026-07-29', $command);
        self::assertContains('--collection-mode=operating_indicators', $command);
        self::assertNotContains('--push', $command);
    }

    public function testFutureDateIsRejectedBeforeCollectorStarts(): void
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

        $result = $service->sync(1, 80, 7, '2026-07-31');

        self::assertSame('blocked', $result['status']);
        self::assertSame('pms_target_date_in_future', $result['blocker_code']);
        self::assertFalse($collectorCalled);
    }

    public function testSavedReadbackIsPreservedWhenDownstreamSyncFails(): void
    {
        $service = new PmsRealtimeSyncService(
            bindingResolver: static fn(): array => [
                'binding_status' => 'configured',
                'selected_provider' => 'dingdandao_pms',
            ],
            receiptLoader: static fn(): array => self::trustedReceipt(),
            cdpProbe: static fn(): bool => true,
            processRunner: static fn(): array => [
                'exit_code' => 1,
                'stdout' => '',
                'stderr' => json_encode([
                    'status' => 'saved_downstream_blocked',
                    'reason' => 'dingdandao_local_collection_target_sync_blocked',
                    'failure_stage' => 'operating_target_sync',
                    'collection_success' => true,
                    'business_data_persisted' => true,
                    'capture_id' => 654,
                ], JSON_UNESCAPED_SLASHES),
            ],
            captureReader: static fn(): array => [
                'id' => 654,
                'hotel_id' => 80,
                'business_date' => '2026-07-30',
                'source_scope' => DingdandaoOperatingTargetCaptureService::SOURCE_SCOPE,
                'captured_at' => '2026-07-30 10:45:00',
                'identity_status' => 'matched',
                'quality_status' => 'verified',
                'readback_status' => 'readback_verified',
            ],
            captureValidator: static fn(): bool => true,
            clock: static fn(): DateTimeImmutable => new DateTimeImmutable(
                '2026-07-30 10:45:05',
                new DateTimeZone('Asia/Shanghai')
            ),
            projectRoot: dirname(__DIR__),
            phpBinary: PHP_BINARY
        );

        $result = $service->sync(1, 80, 7, '2026-07-30');

        self::assertSame('partial', $result['status']);
        self::assertTrue($result['live_read']);
        self::assertTrue($result['saved']);
        self::assertTrue($result['readback_verified']);
        self::assertSame(654, $result['capture_id']);
        self::assertSame(
            'dingdandao_local_collection_target_sync_blocked',
            $result['downstream_blocker_code']
        );
        self::assertSame('operating_target_sync', $result['failure_stage']);
    }

    public function testRealtimeSyncRejectsAStatusOnlyCaptureWithoutVerifiedContract(): void
    {
        $service = new PmsRealtimeSyncService(
            bindingResolver: static fn(): array => [
                'binding_status' => 'configured',
                'selected_provider' => 'dingdandao_pms',
            ],
            receiptLoader: static fn(): array => self::trustedReceipt(),
            cdpProbe: static fn(): bool => true,
            processRunner: static fn(): array => [
                'exit_code' => 0,
                'stdout' => json_encode([
                    'status' => 'saved_and_readback_verified',
                    'capture_id' => 777,
                ], JSON_UNESCAPED_SLASHES),
                'stderr' => '',
            ],
            captureReader: static fn(): array => [
                'id' => 777,
                'hotel_id' => 80,
                'business_date' => '2026-07-30',
                'source_scope' => DingdandaoOperatingTargetCaptureService::SOURCE_SCOPE,
                'captured_at' => '2026-07-30 11:00:00',
                'identity_status' => 'matched',
                'quality_status' => 'verified',
                'readback_status' => 'readback_verified',
            ],
            captureValidator: static fn(): bool => false,
            clock: static fn(): DateTimeImmutable => new DateTimeImmutable(
                '2026-07-30 11:00:05',
                new DateTimeZone('Asia/Shanghai')
            ),
            projectRoot: dirname(__DIR__),
            phpBinary: PHP_BINARY
        );

        $result = $service->sync(1, 80, 7, '2026-07-30');

        self::assertSame('blocked', $result['status']);
        self::assertSame('pms_live_readback_not_verified', $result['blocker_code']);
        self::assertFalse($result['saved']);
        self::assertFalse($result['readback_verified']);
    }

    public function testUnavailableBrowserSessionReturnsLoginBlockerWithoutReadingOldData(): void
    {
        $service = new PmsRealtimeSyncService(
            bindingResolver: static fn(): array => [
                'binding_status' => 'configured',
                'selected_provider' => 'dingdandao_pms',
            ],
            receiptLoader: static fn(): array => self::trustedReceipt(),
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

    public function testBlockedReceiptAndConfiguredSandboxCannotAuthorizeCollection(): void
    {
        $previous = getenv('SUXIOS_DINGDANDAO_LOCAL_SANDBOX_ID');
        putenv('SUXIOS_DINGDANDAO_LOCAL_SANDBOX_ID=sbx_dingdandao_h80_primary');
        $collectorCalled = false;
        try {
            $receipt = self::trustedReceipt();
            $receipt['status'] = 'blocked';
            $receipt['collection_success'] = false;
            $receipt['business_data_persisted'] = false;
            $receipt['capture_id'] = 0;

            $service = new PmsRealtimeSyncService(
                bindingResolver: static fn(): array => [
                    'binding_status' => 'configured',
                    'selected_provider' => 'dingdandao_pms',
                ],
                receiptLoader: static fn(): array => $receipt,
                cdpProbe: static function () use (&$collectorCalled): bool {
                    $collectorCalled = true;
                    return true;
                },
                processRunner: static function () use (&$collectorCalled): array {
                    $collectorCalled = true;
                    return [];
                },
                clock: static fn(): DateTimeImmutable => new DateTimeImmutable(
                    '2026-07-30 09:45:05',
                    new DateTimeZone('Asia/Shanghai')
                )
            );

            $result = $service->sync(1, 80, 7, '2026-07-30');

            self::assertSame('blocked', $result['status']);
            self::assertSame('pms_live_sandbox_not_configured', $result['blocker_code']);
            self::assertFalse($collectorCalled);
        } finally {
            if ($previous === false) {
                putenv('SUXIOS_DINGDANDAO_LOCAL_SANDBOX_ID');
            } else {
                putenv('SUXIOS_DINGDANDAO_LOCAL_SANDBOX_ID=' . $previous);
            }
        }
    }

    /** @return array<string,mixed> */
    private static function trustedReceipt(): array
    {
        return [
            'schema_version' => 1,
            'run_id' => '20260730_094500_000',
            'status' => 'success',
            'source' => 'dingdandao',
            'execution_mode' => 'local_shared_browser_sandbox',
            'collection_mode' => 'full_diagnostic',
            'hotel_id' => 80,
            'owner_user_id' => 7,
            'target_date' => '2026-07-30',
            'sandbox_id' => 'sbx_dingdandao_h80_primary',
            'sandbox_selection' => 'explicit_marker',
            'cdp_scope' => 'loopback',
            'browser_host_status' => 'ready',
            'collection_success' => true,
            'business_data_persisted' => true,
            'capture_id' => 321,
            'identity_status' => 'matched',
            'reconciliation_status' => 'matched',
            'quality_status' => 'verified',
            'readback_status' => 'readback_verified',
            'scope_mismatch_codes' => [],
        ];
    }
}
