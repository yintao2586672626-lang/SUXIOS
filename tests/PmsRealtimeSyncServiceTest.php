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

            self::assertNotFalse(file_put_contents(
                $receiptPath,
                json_encode(self::loginExpiredReceipt(), JSON_UNESCAPED_SLASHES)
            ));
            $recoveryReceipt = $loader->invoke($service, 80, 7);
            self::assertSame('blocked', $recoveryReceipt['status'] ?? null);
            self::assertSame(
                'sbx_dingdandao_h80_primary',
                $recoveryReceipt['sandbox_id'] ?? null
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
        $handoffSandbox = '';
        $service = new PmsRealtimeSyncService(
            bindingResolver: static fn(): array => [
                'binding_status' => 'configured',
                'selected_provider' => 'dingdandao_pms',
            ],
            receiptLoader: static fn(): array => self::trustedReceipt(),
            cdpProbe: static fn(): bool => false,
            loginHandoffRunner: static function (string $sandboxId) use (
                &$handoffSandbox
            ): array {
                $handoffSandbox = $sandboxId;
                return self::trustedHandoffReceipt();
            },
            clock: static fn(): DateTimeImmutable => new DateTimeImmutable(
                '2026-07-30 09:45:05',
                new DateTimeZone('Asia/Shanghai')
            )
        );

        $result = $service->sync(1, 80, 7, '2026-07-30');

        self::assertSame('blocked', $result['status']);
        self::assertSame('pms_live_session_unavailable', $result['blocker_code']);
        self::assertSame(80, $result['system_hotel_id']);
        self::assertTrue($result['requires_login']);
        self::assertFalse($result['saved']);
        self::assertFalse($result['readback_verified']);
        self::assertSame('sbx_dingdandao_h80_primary', $handoffSandbox);
        self::assertSame('ready', $result['login_handoff']['status'] ?? null);
        self::assertSame(80, $result['login_handoff']['system_hotel_id'] ?? null);
        self::assertSame(
            'sbx_dingdandao_h80_primary',
            $result['login_handoff']['sandbox_id'] ?? null
        );
        self::assertTrue(
            $result['login_handoff']['window_target_activated'] ?? false
        );
        self::assertSame(
            'pms_manage',
            $result['login_handoff']['activated_target_scope'] ?? null
        );
        self::assertTrue(
            $result['login_handoff']['window_foreground_requested'] ?? false
        );
        self::assertFalse($result['login_handoff']['login_verified'] ?? true);
        self::assertFalse(
            $result['login_handoff']['codex_iab_is_execution_browser'] ?? true
        );
    }

    public function testAuthenticatedRouteExposesExplicitRealtimeSyncOnly(): void
    {
        $routes = (string)file_get_contents(dirname(__DIR__) . '/route/app.php');
        self::assertStringContainsString(
            "Route::post('/pms/realtime-sync', 'OperatingTarget/syncSelectedPmsRealtime')",
            $routes
        );
    }

    public function testExactLoginExpiredReceiptReusesSameSandboxWithoutClaimingSuccess(): void
    {
        $previous = getenv('SUXIOS_DINGDANDAO_LOCAL_SANDBOX_ID');
        putenv('SUXIOS_DINGDANDAO_LOCAL_SANDBOX_ID=sbx_dingdandao_h80_primary');
        $collectorCalled = false;
        $captureRead = false;
        $command = [];
        try {
            $receipt = self::loginExpiredReceipt();

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
                processRunner: static function (array $input) use (
                    &$collectorCalled,
                    &$command
                ): array {
                    $collectorCalled = true;
                    $command = $input;
                    return [
                        'exit_code' => 1,
                        'stdout' => '',
                        'stderr' => json_encode([
                            'status' => 'blocked',
                            'reason' => 'capture_session_expired',
                            'collection_success' => false,
                            'business_data_persisted' => false,
                            'capture_id' => 0,
                        ], JSON_UNESCAPED_SLASHES),
                    ];
                },
                captureReader: static function () use (&$captureRead): array {
                    $captureRead = true;
                    return [];
                },
                clock: static fn(): DateTimeImmutable => new DateTimeImmutable(
                    '2026-07-30 09:45:05',
                    new DateTimeZone('Asia/Shanghai')
                ),
                projectRoot: dirname(__DIR__),
                phpBinary: PHP_BINARY,
                loginHandoffRunner: static fn(string $sandboxId): array =>
                    self::trustedHandoffReceipt($sandboxId)
            );

            $result = $service->sync(1, 80, 7, '2026-07-30');

            self::assertSame('blocked', $result['status']);
            self::assertSame('capture_session_expired', $result['blocker_code']);
            self::assertTrue($result['requires_login']);
            self::assertSame(
                'login_in_bound_local_sandbox',
                $result['recovery_action']
            );
            self::assertSame(
                'same_bound_device_only',
                $result['recovery_device_policy']
            );
            self::assertFalse($result['automatic_device_substitution']);
            self::assertSame('ready', $result['login_handoff']['status'] ?? null);
            self::assertSame(80, $result['login_handoff']['system_hotel_id'] ?? null);
            self::assertFalse(
                $result['login_handoff']['automatic_device_substitution'] ?? true
            );
            self::assertFalse(
                $result['login_handoff']['profile_material_copied'] ?? true
            );
            self::assertFalse(
                $result['login_handoff']['session_material_exposed'] ?? true
            );
            self::assertStringContainsString('独立 Google Chrome', $result['message']);
            self::assertStringContainsString('不要复制 Cookie', $result['message']);
            self::assertTrue($collectorCalled);
            self::assertContains('--hotel-id=80', $command);
            self::assertContains('--owner-user-id=7', $command);
            self::assertContains('--sandbox-id=sbx_dingdandao_h80_primary', $command);
            self::assertFalse($captureRead);
            self::assertFalse($result['saved']);
            self::assertFalse($result['readback_verified']);

            $successGate = new \ReflectionMethod(
                $service,
                'trustedCollectionSuccessReceipt'
            );
            self::assertFalse($successGate->invoke($service, $receipt, 80, 7));
        } finally {
            if ($previous === false) {
                putenv('SUXIOS_DINGDANDAO_LOCAL_SANDBOX_ID');
            } else {
                putenv('SUXIOS_DINGDANDAO_LOCAL_SANDBOX_ID=' . $previous);
            }
        }
    }

    public function testLoginHandoffRejectsForeignOrSensitiveReceiptWithoutLeakingIt(): void
    {
        $foreignSandbox = 'sbx_dingdandao_h81_foreign';
        $service = new PmsRealtimeSyncService(
            bindingResolver: static fn(): array => [
                'binding_status' => 'configured',
                'selected_provider' => 'dingdandao_pms',
            ],
            receiptLoader: static fn(): array => self::trustedReceipt(),
            cdpProbe: static fn(): bool => false,
            loginHandoffRunner: static fn(string $sandboxId): array => [
                ...self::trustedHandoffReceipt($foreignSandbox),
                'cookie' => 'must-not-escape',
            ],
            clock: static fn(): DateTimeImmutable => new DateTimeImmutable(
                '2026-07-30 09:45:05',
                new DateTimeZone('Asia/Shanghai')
            )
        );

        $result = $service->sync(1, 80, 7, '2026-07-30');
        $serialized = json_encode($result, JSON_UNESCAPED_SLASHES);

        self::assertSame('blocked', $result['status']);
        self::assertSame('unavailable', $result['login_handoff']['status'] ?? null);
        self::assertSame(
            'pms_login_handoff_receipt_invalid',
            $result['login_handoff']['failure_code'] ?? null
        );
        self::assertSame(
            'sbx_dingdandao_h80_primary',
            $result['login_handoff']['sandbox_id'] ?? null
        );
        self::assertIsString($serialized);
        self::assertStringNotContainsString($foreignSandbox, $serialized);
        self::assertStringNotContainsString('must-not-escape', $serialized);
        self::assertStringNotContainsString('"cookie":', strtolower($serialized));
        self::assertFalse(
            $result['login_handoff']['automatic_device_substitution'] ?? true
        );

        $handoffGate = new \ReflectionMethod($service, 'trustedLoginHandoffReceipt');
        self::assertTrue($handoffGate->invoke(
            $service,
            self::trustedHandoffReceipt(),
            'sbx_dingdandao_h80_primary'
        ));
        self::assertFalse($handoffGate->invoke(
            $service,
            [
                ...self::trustedHandoffReceipt(),
                'activated_target_scope' => 'public_root',
            ],
            'sbx_dingdandao_h80_primary'
        ));
        self::assertTrue($handoffGate->invoke(
            $service,
            [
                ...self::trustedHandoffReceipt(),
                'activated_target_scope' => 'login_entry',
            ],
            'sbx_dingdandao_h80_primary'
        ));
    }

    public function testSandboxRecoveryReceiptFailsClosedOnScopeOrDeviceMismatch(): void
    {
        $service = new PmsRealtimeSyncService();
        $bindingGate = new \ReflectionMethod(
            $service,
            'trustedSandboxBindingReceipt'
        );
        $receipt = self::loginExpiredReceipt();

        self::assertTrue($bindingGate->invoke($service, $receipt, 80, 7));

        $cases = [
            'other hotel argument' => [$receipt, 81, 7],
            'other owner argument' => [$receipt, 80, 8],
            'receipt hotel changed' => [[...$receipt, 'hotel_id' => 81], 80, 7],
            'receipt owner changed' => [[...$receipt, 'owner_user_id' => 8], 80, 7],
            'foreign execution mode' => [[
                ...$receipt,
                'execution_mode' => 'in_app_browser',
            ], 80, 7],
            'legacy cookie scan' => [[
                ...$receipt,
                'sandbox_selection' => 'legacy_cookie_scan',
            ], 80, 7],
            'remote cdp' => [[...$receipt, 'cdp_scope' => 'remote'], 80, 7],
            'browser host unavailable' => [[
                ...$receipt,
                'browser_host_status' => 'unavailable',
            ], 80, 7],
            'scope mismatch present' => [[
                ...$receipt,
                'scope_mismatch_codes' => ['hotel_id_mismatch'],
            ], 80, 7],
            'automatic device substitution' => [[
                ...$receipt,
                'automatic_device_substitution' => true,
            ], 80, 7],
            'non-login blocker' => [[
                ...$receipt,
                'reason' => 'hotel_identity_mismatch',
            ], 80, 7],
            'invalid sandbox id' => [[
                ...$receipt,
                'sandbox_id' => 'sbx_invalid',
            ], 80, 7],
        ];
        foreach ($cases as $label => [$candidate, $hotelId, $userId]) {
            self::assertFalse(
                $bindingGate->invoke($service, $candidate, $hotelId, $userId),
                $label
            );
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

    /** @return array<string,mixed> */
    private static function loginExpiredReceipt(): array
    {
        return [
            ...self::trustedReceipt(),
            'status' => 'blocked',
            'reason' => 'capture_session_expired',
            'collection_success' => false,
            'business_data_persisted' => false,
            'capture_id' => 0,
            'identity_status' => '',
            'reconciliation_status' => '',
            'quality_status' => '',
            'readback_status' => '',
        ];
    }

    /** @return array<string,mixed> */
    private static function trustedHandoffReceipt(
        string $sandboxId = 'sbx_dingdandao_h80_primary'
    ): array {
        return [
            'status' => 'handoff_ready',
            'cdp_status' => 'ready',
            'cdp_scope' => 'loopback_only',
            'cdp_port' => 9223,
            'browser_started' => false,
            'headless' => false,
            'mode_switch_performed' => false,
            'platform' => 'dingdandao',
            'sandbox_id' => $sandboxId,
            'isolation' => 'process_profile',
            'start_url' =>
                'https://www.dingdandao.com/pmsManage/report/pro/dataCenter/accommodationData',
            'session_status' => 'login_required',
            'login_required' => true,
            'window_target_activated' => true,
            'window_target_reused' => true,
            'activated_target_scope' => 'pms_manage',
            'window_foreground_requested' => true,
            'next_action' => 'complete_login_in_bound_browser_then_retry',
            'automatic_device_substitution' => false,
            'profile_material_copied' => false,
            'browser_process_exposed' => false,
            'raw_response_exposed' => false,
            'session_material_exposed' => false,
            'sensitive_values_exposed' => false,
        ];
    }
}
