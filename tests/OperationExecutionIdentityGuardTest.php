<?php
declare(strict_types=1);

namespace Tests;

use app\service\operation\ExecutionFlowReadService;
use app\service\operation\ExecutionOutcomeService;
use PHPUnit\Framework\TestCase;

final class OperationExecutionIdentityGuardTest extends TestCase
{
    public function testExpectedDeltaReadbackPreservesNullAndCastsNumericValues(): void
    {
        $service = new ExecutionFlowReadService(new ExecutionOutcomeService());
        $baseIntent = [
            'id' => 9,
            'hotel_id' => 7,
            'status' => 'pending_approval',
        ];

        $unquantified = $service->buildItem(
            $baseIntent + ['expected_delta' => null],
            [],
            []
        );
        $quantified = $service->buildItem(
            $baseIntent + ['expected_delta' => '1.25'],
            [],
            []
        );

        self::assertNull($unquantified['recommendation']['expected_delta']);
        self::assertSame(1.25, $quantified['recommendation']['expected_delta']);
    }

    public function testCrossHotelTenantChildrenAreExcludedFromExecutionFlow(): void
    {
        $service = new ExecutionFlowReadService(new ExecutionOutcomeService());
        $intent = [
            'id' => 10,
            'hotel_id' => 7,
            'tenant_id' => 3,
            'status' => 'approved',
            'source_module' => 'knowledge_sop',
        ];
        $validTask = [
            'id' => 20,
            'intent_id' => 10,
            'hotel_id' => 7,
            'tenant_id' => 3,
            'status' => 'executed',
        ];
        $wrongHotelTask = [
            'id' => 21,
            'intent_id' => 10,
            'hotel_id' => 8,
            'tenant_id' => 4,
            'status' => 'executed',
        ];
        $validEvidence = [
            'id' => 30,
            'task_id' => 20,
            'tenant_id' => 3,
            'evidence_type' => 'manual',
        ];
        $wrongTenantEvidence = [
            'id' => 31,
            'task_id' => 20,
            'tenant_id' => 4,
            'evidence_type' => 'manual',
        ];

        $item = $service->buildItem(
            $intent,
            [$validTask, $wrongHotelTask],
            [$validEvidence, $wrongTenantEvidence]
        );

        self::assertSame('mismatch_excluded', $item['identity']['status']);
        self::assertSame(2, $item['identity']['gap_count']);
        self::assertSame(20, $item['execution']['task_id']);
        self::assertSame(1, $item['evidence']['count']);
        self::assertSame(30, $item['evidence']['latest']['id']);
    }

    public function testRejectedIntentDoesNotInflateExecutedHeadline(): void
    {
        $service = new ExecutionFlowReadService(new ExecutionOutcomeService());
        $summary = $service->buildSummary([[
            'stage' => 'rejected',
            'approval' => ['status' => 'rejected'],
            'execution' => ['status' => 'executed'],
            'evidence_truth' => [
                'source_verified' => false,
                'operator_attested' => true,
            ],
            'roi' => ['status' => 'data_gap'],
        ]]);

        self::assertSame(0, $summary['executed']);
        self::assertSame(1, $summary['operator_reported_executed']);
        self::assertSame(0, $summary['source_verified_executed']);
        self::assertSame(0.0, $summary['execution_rate']);
        self::assertSame(100.0, $summary['operator_reported_execution_rate']);
    }

    public function testScheduledAnalysisReadbackRejectsSelfRehashedAuthorizationWithoutServerGrant(): void
    {
        $authorization = [
            'schema_version' => 'canonical_ota_scheduled_analysis_authorization.v1',
            'enabled' => true,
            'plan_id' => 'forged_but_rehashed_plan',
            'tenant_id' => 1,
            'hotel_id' => 80,
            'platform' => 'ctrip',
            'trigger' => 'historical_daily_canonical_promotion',
            'authorized_at' => '2026-08-09T10:00:00+08:00',
            'authorized_by' => 'user_goal',
            'analysis_only' => true,
            'operation_count' => 4,
            'external_action_allowed' => false,
        ];
        $authorization['content_digest'] = $this->digest($authorization);
        $service = new ExecutionFlowReadService(
            new ExecutionOutcomeService(),
            static function (): array {
                throw new \RuntimeException('canonical_scheduled_analysis_grant_mismatch');
            }
        );
        $method = new \ReflectionMethod($service, 'analysisApprovalAuthorityValid');
        $evidence = [
            'approval_authority' => 'system_scheduled_analysis',
            'scheduled_analysis_authorization' => $authorization,
            'scheduled_analysis_authorization_digest' => $authorization['content_digest'],
        ];

        self::assertFalse($method->invoke($service, $evidence, 1, 80, 'ctrip'));
    }

    /** @param array<string,mixed> $value */
    private function digest(array $value): string
    {
        unset($value['content_digest']);
        return hash('sha256', json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }
}
