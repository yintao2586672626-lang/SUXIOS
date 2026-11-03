<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperationActionLifecycleService;
use app\service\RevenueCockpitApprovalService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Throwable;

require_once __DIR__ . '/RevenueCockpitApprovalServiceTest.php';

/** local_synthetic_fixture_only: production validation/envelope, no database or operating write. */
final class RevenueCockpitPendingCardIntegrityTest extends TestCase
{
    private function fixture(): array
    {
        $source = new RevenueCockpitApprovalServiceTest('testEvidenceIdentityChangesWhenTheSameSourceRowFactsChange');
        $overview = (new ReflectionMethod($source, 'overview'))->invoke($source);
        $service = new RevenueCockpitApprovalService();
        $context = $service->evidenceContext($overview, 10, 20, '2026-08-20', 'meituan');
        $args = [$overview, &$context, 7];
        $card = (new ReflectionMethod($service, 'managedActionCard'))->invokeArgs($service, $args);
        $card['approval']['approval_expires_at'] = '2099-01-01 00:00:00';
        $card = $this->resign($card);
        $intent = ['id' => 91, 'tenant_id' => 10, 'hotel_id' => 20, 'created_by' => 7,
            'source_module' => $card['source']['module'], 'source_record_id' => $card['source']['record_id'],
            'platform' => 'meituan', 'date_start' => '2026-08-20', 'date_end' => '2026-08-20',
            'expected_metric' => $card['metric_contract']['metric_key'], 'status' => 'pending_approval', 'tasks' => [],
            'target_value' => ['action_card' => $card], 'evidence' => ['action_card' => $card]];
        return [$service, $overview, $context, $intent];
    }

    private function resign(array $card): array
    {
        $card['content_digest'] = (new ReflectionMethod(OperationActionLifecycleService::class, 'cardDigest'))
            ->invoke(new OperationActionLifecycleService(), $card);
        return $card;
    }

    private function validate(array $fixture, array $intent, bool $requireCurrent): array
    {
        return (new ReflectionMethod(RevenueCockpitApprovalService::class, 'assertManagedIntentReadback'))
            ->invoke($fixture[0], $intent, $fixture[1], $fixture[2], $requireCurrent);
    }

    private function assertRejected(array $fixture, array $intent, bool $requireCurrent, string $reason): void
    {
        $failure = null;
        try {
            $this->validate($fixture, $intent, $requireCurrent);
        } catch (Throwable $e) {
            $failure = $e;
        }
        self::assertNotNull($failure, 'Invalid persisted card must fail before verified readback.');
        self::assertStringContainsString($reason, $failure->getMessage());
    }

    public function testValidRepeatedReadbackKeepsIdentityContentAndNoExecution(): void
    {
        $fixture = $this->fixture();
        $envelopes = [];
        foreach ([false, true, true] as $requireCurrent) {
            $checked = $this->validate($fixture, $fixture[3], $requireCurrent);
            self::assertSame('verified', $checked['fact_integrity_status']);
            self::assertSame($fixture[3]['target_value'], $checked['target_value']);
            $envelope = (new ReflectionMethod($fixture[0], 'managedEnvelope'))->invoke($fixture[0], $checked, true);
            self::assertSame(91, $envelope['execution_intent']['id']);
            self::assertSame('pending_approval', $envelope['status']);
            self::assertSame('readback_verified', $envelope['persistence_status']);
            self::assertSame(0, $envelope['execution_task_count']);
            self::assertFalse($envelope['external_action_triggered']);
            self::assertTrue($envelope['reused_existing_intent']);
            $envelopes[] = $envelope;
        }
        self::assertSame($envelopes[0], $envelopes[1]);
        self::assertSame($envelopes[1], $envelopes[2]);
    }

    public function testModifiedCardContentFailsBothReuseAndReadValidation(): void
    {
        $fixture = $this->fixture();
        $intent = $fixture[3];
        $intent['target_value']['action_card']['action']['title'] = 'synthetic content changed after persistence';
        foreach ([false, true] as $mode) {
            $this->assertRejected($fixture, $intent, $mode, '行动卡内容摘要不一致');
        }
    }

    public function testIntentScopeAndSourceProjectionMustMatchItsSignedCard(): void
    {
        $fixture = $this->fixture();
        foreach (['tenant_id' => 11, 'hotel_id' => 21, 'source_record_id' => 99999,
            'platform' => 'ctrip', 'date_start' => '2026-08-21', 'date_end' => '2026-08-21',
            'expected_metric' => 'synthetic_other_metric'] as $field => $value) {
            $intent = $fixture[3];
            $intent[$field] = $value;
            foreach ([false, true] as $mode) {
                $this->assertRejected($fixture, $intent, $mode, '漂移');
            }
        }
    }

    public function testExpiredPendingCardFailsButApprovedHistoryIsReadable(): void
    {
        $fixture = $this->fixture();
        $intent = $fixture[3];
        $card = $intent['target_value']['action_card'];
        $card['approval']['approval_expires_at'] = '2026-01-01 00:00:00';
        $intent['target_value']['action_card'] = $this->resign($card);
        foreach ([false, true] as $mode) {
            $this->assertRejected($fixture, $intent, $mode, '行动卡已过期');
        }
        // Historical persisted status is a synthetic fixture; this test does not approve anything.
        $intent['status'] = 'approved';
        $intent['tasks'] = [['id' => 501, 'status' => 'pending_execute', 'result_status' => 'pending']];
        $read = $this->validate($fixture, $intent, false);
        self::assertSame(91, $read['id']);
        self::assertSame('approved', $read['status']);
        self::assertSame($intent['tasks'], $read['tasks']);
    }

    public function testChangedFactsRemainHistoricalButCannotBeSuccessfulCurrentReuse(): void
    {
        $fixture = $this->fixture();
        $intent = $fixture[3];
        $card = $intent['target_value']['action_card'];
        $card['trace']['cockpit_identity_digest'] = str_repeat('f', 64);
        $intent['target_value']['action_card'] = $this->resign($card);
        $historical = $this->validate($fixture, $intent, false);
        self::assertSame('drifted', $historical['fact_integrity_status']);
        self::assertSame(91, $historical['id']);
        self::assertSame($intent['target_value'], $historical['target_value']);
        $this->assertRejected($fixture, $intent, true, '原始事实已漂移');
    }
}
