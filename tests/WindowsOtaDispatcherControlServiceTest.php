<?php
declare(strict_types=1);

use app\service\WindowsOtaDispatcherControlService;
use PHPUnit\Framework\TestCase;

final class WindowsOtaDispatcherControlServiceTest extends TestCase
{
    public function testInspectReturnsExactDisabledTaskWithoutCallingItSuccess(): void
    {
        $service = $this->serviceWithReceipt($this->receipt());

        $actual = $service->inspect(80);

        self::assertSame('blocked', $actual['status']);
        self::assertSame('scheduler_disabled_catch_up_enabled', $actual['reason_code']);
        self::assertFalse($actual['enabled']);
        self::assertTrue($actual['scope_verified']);
        self::assertTrue($actual['can_enable']);
        self::assertFalse($actual['catch_up_disabled']);
        self::assertTrue($actual['safe_enable_transition_required']);
        self::assertFalse($actual['task_started']);
        self::assertFalse($actual['starts_task_immediately']);
        self::assertFalse($actual['production_ready']);
    }

    public function testScopeDriftFailsClosedAndRemovesEnableDigest(): void
    {
        $receipt = $this->receipt();
        $receipt['scope']['source_ids'] = [25, 69];
        $service = $this->serviceWithReceipt($receipt);

        $actual = $service->inspect(80);

        self::assertSame('blocked', $actual['status']);
        self::assertSame('scheduler_scope_mismatch', $actual['reason_code']);
        self::assertFalse($actual['scope_verified']);
        self::assertFalse($actual['can_enable']);
        self::assertNull($actual['contract_digest']);
    }

    public function testEnableRequiresExactDigestBeforeInvokingRunner(): void
    {
        $calls = 0;
        $service = new WindowsOtaDispatcherControlService(
            static function () use (&$calls): array {
                $calls++;
                return [];
            },
            dirname(__DIR__)
        );

        $actual = $service->enable(80, 'not-a-digest');

        self::assertSame(0, $calls);
        self::assertSame('scheduler_contract_digest_invalid', $actual['reason_code']);
        self::assertFalse($actual['can_enable']);
    }

    public function testEnableAcceptsOnlyExactReadbackAndNeverClaimsTaskStarted(): void
    {
        $receipt = $this->receipt();
        $receipt['status'] = 'ready';
        $receipt['reason_code'] = 'scheduler_enabled_waiting_natural_run';
        $receipt['task_state'] = 'Ready';
        $receipt['enabled'] = true;
        $receipt['catch_up_disabled'] = true;
        $receipt['safe_enable_transition_required'] = false;
        $receipt['can_enable'] = false;
        $receipt['enable_action_performed'] = true;
        $receipt['settings_action_performed'] = true;
        $service = $this->serviceWithReceipt($receipt);

        $actual = $service->enable(80, str_repeat('a', 64));

        self::assertSame('ready', $actual['status']);
        self::assertSame('scheduler_enabled_waiting_natural_run', $actual['reason_code']);
        self::assertTrue($actual['enabled']);
        self::assertTrue($actual['enable_action_performed']);
        self::assertTrue($actual['last_run_unchanged']);
        self::assertFalse($actual['task_started']);
        self::assertFalse($actual['starts_task_immediately']);
    }

    public function testAlreadyEnabledIsIdempotentAndDoesNotPretendToRunTask(): void
    {
        $receipt = $this->receipt();
        $receipt['status'] = 'ready';
        $receipt['reason_code'] = 'scheduler_already_enabled_waiting_natural_run';
        $receipt['task_state'] = 'Ready';
        $receipt['enabled'] = true;
        $receipt['catch_up_disabled'] = true;
        $receipt['safe_enable_transition_required'] = false;
        $receipt['can_enable'] = false;
        $service = $this->serviceWithReceipt($receipt);

        $actual = $service->enable(80, str_repeat('a', 64));

        self::assertSame('ready', $actual['status']);
        self::assertFalse($actual['enable_action_performed']);
        self::assertFalse($actual['task_started']);
    }

    public function testRunningOrQueuedTaskFailsClosed(): void
    {
        $receipt = $this->receipt();
        $receipt['task_state'] = 'Running';
        $receipt['task_state_active'] = true;
        $receipt['reason_code'] = 'scheduler_task_active';
        $service = $this->serviceWithReceipt($receipt);

        $actual = $service->inspect(80);

        self::assertSame('blocked', $actual['status']);
        self::assertSame('scheduler_task_active', $actual['reason_code']);
        self::assertTrue($actual['task_state_active']);
        self::assertFalse($actual['can_enable']);
    }

    public function testIsoDurationsAreNormalizedWithoutLowercaseContractDrift(): void
    {
        $receipt = $this->receipt();
        $receipt['trigger']['retry_interval'] = 'pt14m';
        $receipt['trigger']['retry_duration'] = 'pt1h25m';
        $service = $this->serviceWithReceipt($receipt);

        $actual = $service->inspect(80);

        self::assertSame('PT14M', $actual['trigger']['retry_interval']);
        self::assertSame('PT1H25M', $actual['trigger']['retry_duration']);
        self::assertTrue($actual['scope_verified']);
    }

    public function testUnexpectedRunReasonAndTaskStartedEvidenceArePreserved(): void
    {
        $receipt = $this->receipt();
        $receipt['reason_code'] = 'scheduler_enable_triggered_unexpected_run';
        $receipt['task_state'] = 'Running';
        $receipt['task_state_active'] = true;
        $receipt['enabled'] = true;
        $receipt['catch_up_disabled'] = true;
        $receipt['safe_enable_transition_required'] = false;
        $receipt['can_enable'] = false;
        $receipt['enable_action_performed'] = true;
        $receipt['last_run_unchanged'] = false;
        $receipt['task_started'] = true;
        $receipt['starts_task_immediately'] = true;
        $service = $this->serviceWithReceipt($receipt);

        $actual = $service->enable(80, str_repeat('a', 64));

        self::assertSame('blocked', $actual['status']);
        self::assertSame('scheduler_enable_triggered_unexpected_run', $actual['reason_code']);
        self::assertTrue($actual['task_started']);
        self::assertTrue($actual['starts_task_immediately']);
        self::assertFalse($actual['can_enable']);
    }

    public function testSafeSettingsReadbackFailureReasonIsPreserved(): void
    {
        $receipt = $this->receipt();
        $receipt['reason_code'] = 'scheduler_safe_settings_readback_failed';
        $receipt['settings_action_performed'] = true;
        $receipt['can_enable'] = false;
        $service = $this->serviceWithReceipt($receipt);

        $actual = $service->enable(80, str_repeat('a', 64));

        self::assertSame('blocked', $actual['status']);
        self::assertSame('scheduler_safe_settings_readback_failed', $actual['reason_code']);
        self::assertTrue($actual['settings_action_performed']);
        self::assertFalse($actual['task_started']);
    }

    public function testProcessOutputMustContainOneCanonicalReceipt(): void
    {
        $receipt = $this->receipt();
        $service = new WindowsOtaDispatcherControlService(
            static fn(): array => [
                'exit_code' => 0,
                'stdout' => "diagnostic\nSUXIOS_OTA_WINDOWS_SCHEDULER="
                    . json_encode($receipt, JSON_UNESCAPED_SLASHES)
                    . "\n",
                'stderr' => 'not exposed',
            ],
            dirname(__DIR__)
        );

        $actual = $service->inspect(80);

        self::assertSame('scheduler_disabled_catch_up_enabled', $actual['reason_code']);
        self::assertArrayNotHasKey('stderr', $actual);
        self::assertFalse($actual['sensitive_values_exposed']);
    }

    public function testMissingProcessReceiptPreservesUnavailableAndUnknownState(): void
    {
        $service = new WindowsOtaDispatcherControlService(
            static fn(): array => [
                'exit_code' => 2,
                'stdout' => '',
                'stderr' => 'not exposed',
            ],
            dirname(__DIR__)
        );

        $actual = $service->inspect(80);

        self::assertSame('blocked', $actual['status']);
        self::assertSame('scheduler_receipt_unavailable', $actual['reason_code']);
        self::assertFalse($actual['control_state_verified']);
        self::assertSame(2, $actual['control_process_exit_code']);
        self::assertNull($actual['last_run_unchanged']);
        self::assertNull($actual['task_started']);
        self::assertNull($actual['starts_task_immediately']);
        self::assertNull($actual['task_state_active']);
    }

    public function testNonzeroProcessExitRejectsSuccessShapedReceipt(): void
    {
        $receipt = $this->receipt();
        $receipt['status'] = 'ready';
        $receipt['reason_code'] = 'scheduler_enabled_waiting_natural_run';
        $receipt['task_state'] = 'Ready';
        $receipt['enabled'] = true;
        $receipt['catch_up_disabled'] = true;
        $receipt['safe_enable_transition_required'] = false;
        $receipt['can_enable'] = false;
        $receipt['enable_action_performed'] = true;
        $service = new WindowsOtaDispatcherControlService(
            static fn(): array => [
                'exit_code' => 2,
                'stdout' => 'SUXIOS_OTA_WINDOWS_SCHEDULER='
                    . json_encode($receipt, JSON_UNESCAPED_SLASHES)
                    . "\n",
                'stderr' => '',
            ],
            dirname(__DIR__)
        );

        $actual = $service->enable(80, str_repeat('a', 64));

        self::assertSame('blocked', $actual['status']);
        self::assertSame('scheduler_process_exit_nonzero', $actual['reason_code']);
        self::assertSame(2, $actual['control_process_exit_code']);
        self::assertFalse($actual['can_enable']);
    }

    /** @param array<string,mixed> $receipt */
    private function serviceWithReceipt(array $receipt): WindowsOtaDispatcherControlService
    {
        return new WindowsOtaDispatcherControlService(
            static fn(): array => $receipt,
            dirname(__DIR__)
        );
    }

    /** @return array<string,mixed> */
    private function receipt(): array
    {
        return [
            'schema_version' => WindowsOtaDispatcherControlService::SCHEMA_VERSION,
            'status' => 'blocked',
            'reason_code' => 'scheduler_disabled_catch_up_enabled',
            'local_only' => true,
            'production_ready' => false,
            'hotel_id' => 80,
            'task_name' => WindowsOtaDispatcherControlService::TASK_NAME,
            'task_exists' => true,
            'task_state' => 'Disabled',
            'enabled' => false,
            'scope' => [
                'hotel_id' => 80,
                'source_ids' => [25, 68],
                'platforms' => ['ctrip', 'meituan'],
                'mode' => 'Daily',
            ],
            'action_verified' => true,
            'trigger_verified' => true,
            'principal_verified' => true,
            'settings_verified' => true,
            'scope_verified' => true,
            'control_state_verified' => true,
            'catch_up_disabled' => false,
            'safe_enable_transition_required' => true,
            'task_state_active' => false,
            'trigger' => [
                'count' => 1,
                'start_boundary' => '2026-08-10T08:30:00+08:00',
                'retry_interval' => 'PT14M',
                'retry_duration' => 'PT1H25M',
            ],
            'last_run_time' => '2026-08-11T09:54:54+08:00',
            'last_task_result' => 78,
            'next_run_time' => '2026-08-12T08:30:30+08:00',
            'contract_digest' => str_repeat('a', 64),
            'can_enable' => true,
            'enable_action_performed' => false,
            'settings_action_performed' => false,
            'last_run_unchanged' => true,
            'task_started' => false,
            'starts_task_immediately' => false,
            'sensitive_values_exposed' => false,
        ];
    }
}
