<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperatingBlockerRecoveryService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OperatingBlockerRecoveryServiceTest extends TestCase
{
    public function testNormalizesAllOperatingSourcesAndSelectsOneSystemBlocker(): void
    {
        $result = $this->service()->build($this->scope(), [
            'wecom' => $this->evidence('pending_approval'),
            'meituan' => $this->evidence('target_date_rows_missing'),
            'worker' => $this->evidence('worker_unavailable'),
            'ctrip' => $this->evidence('login_expired'),
            'pms' => $this->evidence('binding_missing'),
            'database' => $this->evidence('database_unavailable'),
        ]);

        self::assertSame(OperatingBlockerRecoveryService::CONTRACT_VERSION, $result['contract_version']);
        self::assertSame('blocked', $result['status']);
        self::assertSame('not_attempted', $result['recovery_status']);
        self::assertSame(6, $result['blocker_count']);
        self::assertSame(1, $result['selected_count']);
        self::assertCount(1, array_filter(
            $result['items'],
            static fn(array $item): bool => $item['selected'] === true
        ));

        $bySource = [];
        foreach ($result['items'] as $item) {
            $bySource[$item['source']] = $item;
            self::assertSame(80, $item['scope']['hotel_id']);
            self::assertSame('2026-08-30', $item['scope']['business_date']);
            self::assertSame('matched', $item['scope_status']);
            self::assertSame(
                OperatingBlockerRecoveryService::RESUME_CONTRACT_VERSION,
                $item['resume_contract']['contract_version']
            );
            self::assertSame($item['scope'], $item['resume_contract']['resume_scope']);
        }

        self::assertSame('human_login_verification', $bySource['ctrip']['category']);
        self::assertSame('config_binding', $bySource['pms']['category']);
        self::assertSame('data_missing', $bySource['meituan']['category']);
        self::assertSame('external_authorization', $bySource['wecom']['category']);
        self::assertSame('retryable_runtime', $bySource['database']['category']);
        self::assertSame('retryable_runtime', $bySource['worker']['category']);

        self::assertSame('database', $result['selected']['source']);
        self::assertSame('verify_runtime_then_retry_read_only', $result['selected']['next_action_code']);
        self::assertTrue($result['selected']['resumable']);
        self::assertSame('attention_priority_only', $result['selection_policy']['purpose']);
        self::assertSame('instruction_only', $result['execution_mode']);
        self::assertSame('not_wired', $result['resume_executor_status']);
        self::assertNull($result['resume_endpoint']);
        self::assertFalse($result['selection_policy']['monetary_value_claimed']);
        self::assertFalse($result['safety']['automatic_recovery']);
        self::assertFalse($result['safety']['credentials_accessed']);
        self::assertFalse($result['safety']['external_actions_executed']);
        self::assertFalse($result['safety']['writes_executed']);
    }

    public function testSelectionAndOrderingAreIndependentOfEvidenceOrder(): void
    {
        $evidence = [
            ['source' => 'worker'] + $this->evidence('worker_unavailable'),
            ['source' => 'database'] + $this->evidence('database_unavailable'),
            ['source' => 'ctrip'] + $this->evidence('login_expired'),
            ['source' => 'pms'] + $this->evidence('binding_missing'),
        ];

        $first = $this->service()->build($this->scope(), $evidence);
        $second = $this->service()->build($this->scope(), array_reverse($evidence));

        self::assertSame($first['selected']['blocker_id'], $second['selected']['blocker_id']);
        self::assertSame(
            array_column($first['items'], 'blocker_id'),
            array_column($second['items'], 'blocker_id')
        );
        self::assertSame('database', $first['selected']['source']);
    }

    public function testHumanLoginAndExternalAuthorizationHaveDifferentResumeBoundaries(): void
    {
        $human = $this->service()->build($this->scope(), [
            'ctrip' => $this->evidence('captcha_required'),
        ])['selected'];

        self::assertSame('human_login_verification', $human['category']);
        self::assertSame('authorized_human', $human['next_action_actor']);
        self::assertTrue($human['resumable']);
        self::assertSame('after_human_session_verification', $human['resume_contract']['resume_mode']);
        self::assertSame('rerun_original_read_only_check', $human['resume_contract']['resume_action']);
        self::assertContains(
            'automatic_login_or_verification_bypass',
            $human['resume_contract']['prohibited_actions']
        );

        $authorization = $this->service()->build($this->scope(), [
            'wecom' => $this->evidence('send_authorization_required'),
        ])['selected'];

        self::assertSame('external_authorization', $authorization['category']);
        self::assertSame('user', $authorization['next_action_actor']);
        self::assertFalse($authorization['resumable']);
        self::assertSame('new_explicit_authorization_required', $authorization['resume_contract']['resume_mode']);
        self::assertContains('automatic_approval', $authorization['resume_contract']['prohibited_actions']);
        self::assertContains('automatic_external_send', $authorization['resume_contract']['prohibited_actions']);
        self::assertContains('ota_or_pms_write', $authorization['resume_contract']['prohibited_actions']);
    }

    public function testForeignScopeIsQuarantinedAsPermissionBlockerWithoutAdoptingIt(): void
    {
        $result = $this->service()->build($this->scope(), [[
            'source' => 'ctrip',
            'status' => 'login_expired',
            'tenant_id' => 2,
            'hotel_id' => 81,
            'business_date' => '2026-08-29',
            'evidence_quality' => 'verified',
            'message' => 'password=must-not-be-reflected',
            'evidence_ref' => 'token must-not-be-reflected',
        ]]);

        $selected = $result['selected'];
        self::assertSame(1, $result['blocker_count']);
        self::assertSame('permission', $selected['category']);
        self::assertSame('evidence_scope_mismatch', $selected['reason_code']);
        self::assertSame('mismatch', $selected['scope_status']);
        self::assertSame('invalid_scope', $selected['evidence_quality']);
        self::assertSame(80, $selected['scope']['hotel_id']);
        self::assertSame(81, $selected['reported_scope']['hotel_id']);
        self::assertFalse($selected['resumable']);
        self::assertNull($selected['evidence_ref']);

        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('must-not-be-reflected', $encoded);
        self::assertStringContainsString('不得切换门店', $selected['next_action']);
    }

    public function testUnknownPartialEvidenceStaysExplicitAndOnlyRequestsReadOnlyEvidence(): void
    {
        $result = $this->service()->build($this->scope(), [[
            'status' => 'vendor_code_x',
            'message' => 'opaque raw error with secret material',
            'observed_at' => 'not-a-time',
        ]]);

        $selected = $result['selected'];
        self::assertSame('unknown', $selected['source']);
        self::assertSame('unknown', $selected['category']);
        self::assertSame('inherited', $selected['scope_status']);
        self::assertSame('partial', $selected['evidence_quality']);
        self::assertSame('vendor_code_x', $selected['reason_code']);
        self::assertSame('collect_redacted_read_only_evidence', $selected['next_action_code']);
        self::assertFalse($selected['resumable']);
        self::assertSame('reclassification_required', $selected['resume_contract']['resume_mode']);
        self::assertNull($selected['observed_at']);

        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('secret material', $encoded);
        self::assertStringNotContainsString('opaque raw error', $encoded);
    }

    public function testHealthyEvidenceDoesNotPretendThatRecoveryWasExecuted(): void
    {
        $result = $this->service()->build($this->scope(), [
            'pms' => $this->evidence('verified'),
            'ctrip' => $this->evidence('readback_verified'),
            'meituan' => $this->evidence('ready'),
            'wecom' => $this->evidence('sent'),
            'database' => $this->evidence('healthy'),
            'worker' => $this->evidence('available'),
        ]);

        self::assertSame('no_blocker_observed', $result['status']);
        self::assertSame('not_attempted', $result['recovery_status']);
        self::assertSame(6, $result['non_blocking_evidence_count']);
        self::assertSame(0, $result['blocker_count']);
        self::assertSame(0, $result['selected_count']);
        self::assertNull($result['selected']);
        self::assertSame([], $result['items']);
    }

    public function testDuplicateBlockerEvidenceCollapsesToStrongestSameScopeReceipt(): void
    {
        $partial = [
            'source' => 'ctrip',
            'status' => 'login_expired',
            'evidence_quality' => 'partial',
        ];
        $verified = [
            'source' => 'ctrip',
            'status' => 'login_expired',
        ] + $this->evidence('login_expired');

        $result = $this->service()->build($this->scope(), [$partial, $verified]);

        self::assertSame(2, $result['evidence_count']);
        self::assertSame(1, $result['blocker_count']);
        self::assertSame('verified', $result['selected']['evidence_quality']);
        self::assertSame('matched', $result['selected']['scope_status']);
    }

    public function testUpstreamLoginPrerequisiteOutranksVerifiedMissingDataForSameSource(): void
    {
        $login = [
            'source' => 'ctrip',
            'status' => 'login_expired',
            'evidence_quality' => 'partial',
        ] + $this->evidence('login_expired');
        $login['evidence_quality'] = 'partial';
        $missing = [
            'source' => 'ctrip',
            'status' => 'target_data_missing',
        ] + $this->evidence('target_data_missing');

        $first = $this->service()->build($this->scope(), [$missing, $login]);
        $second = $this->service()->build($this->scope(), [$login, $missing]);

        self::assertSame('human_login_verification', $first['selected']['category']);
        self::assertSame('human_login_verification', $second['selected']['category']);
        self::assertSame('after_human_session_verification', $first['selected']['resume_contract']['resume_mode']);
        self::assertSame('prerequisite', $first['selection_policy']['order'][2]);
    }

    public function testInvalidBaseScopeFailsBeforeAnyEvidenceIsUsed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('operating_blocker_business_date_invalid');

        $this->service()->build([
            'tenant_id' => 1,
            'hotel_id' => 80,
            'business_date' => '2026-02-30',
        ], [[
            'source' => 'database',
            'status' => 'database_unavailable',
        ]]);
    }

    private function service(): OperatingBlockerRecoveryService
    {
        return new OperatingBlockerRecoveryService();
    }

    /** @return array{tenant_id:int,hotel_id:int,business_date:string} */
    private function scope(): array
    {
        return [
            'tenant_id' => 1,
            'hotel_id' => 80,
            'business_date' => '2026-08-30',
        ];
    }

    /** @return array<string, mixed> */
    private function evidence(string $status): array
    {
        return [
            'status' => $status,
            'tenant_id' => 1,
            'hotel_id' => 80,
            'business_date' => '2026-08-30',
            'evidence_quality' => 'verified',
            'observed_at' => '2026-08-30 10:20:30',
            'evidence_ref' => 'test-fixture#80',
        ];
    }
}
