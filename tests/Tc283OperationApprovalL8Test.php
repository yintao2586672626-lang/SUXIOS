<?php
declare(strict_types=1);

namespace Tests;

use DateTimeImmutable;
use app\service\OperationManagementService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Tc283OperationApprovalL8Test extends TestCase
{
    private const HOTEL_ID = 7;
    private const REVIEWER_ID = 283;
    private const TARGET_DATE = '2026-07-15';
    private const STALE_DATE = '2026-07-01';
    private const REVIEWED_AT = '2026-07-15T12:00:00+08:00';

    /**
     * TC-283 operation approval L8 contract.
     *
     * This test exercises service-boundary business behavior only. It does not
     * claim HTTP, database, UI, or external OTA evidence. The only approvable
     * row remains pending for an explicit human approval, and every non-approved
     * production payload is refused by the real execution-task builder.
     *
     * @param array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string} $factors
     */
    #[DataProvider('l8VariantProvider')]
    public function testTc283OperationApprovalRequiresAllFourGuards(
        string $caseId,
        array $factors
    ): void {
        $service = new OperationManagementService();
        $input = $this->inputForFactors($caseId, $factors);
        $permittedHotelIds = $factors['actor_scope'] === 'authorized'
            ? [self::HOTEL_ID]
            : [self::HOTEL_ID + 1];
        $message = $caseId . ' factors=' . json_encode($factors, JSON_UNESCAPED_SLASHES);
        $payload = null;
        $scopeDeniedByProduction = false;

        try {
            $payload = $service->buildExecutionIntentPayload(
                $permittedHotelIds,
                self::HOTEL_ID,
                $input,
                self::REVIEWER_ID
            );
        } catch (InvalidArgumentException $exception) {
            if ($factors['actor_scope'] !== 'restricted') {
                throw $exception;
            }
            self::assertSame('hotel_id is not permitted', $exception->getMessage(), $message);
            $scopeDeniedByProduction = true;
        }

        self::assertSame(
            $factors['actor_scope'] === 'restricted',
            $scopeDeniedByProduction,
            $message . ' actor_scope must land on the production hotel permission guard'
        );

        $preflight = $this->evaluateTestOnlyBusinessApprovalPreflight(
            $permittedHotelIds,
            $input,
            $payload
        );
        $this->assertFourFactorsLanded($input, $payload, $preflight, $factors, $message);

        $expectedApprovalReady = $factors === self::factors(
            'authorized',
            'complete',
            'fresh',
            'success'
        );
        self::assertSame($expectedApprovalReady, $preflight['can_enter_approval'], $message);
        self::assertSame('manual', $preflight['approval_mode'], $message);
        self::assertFalse($preflight['auto_execute_allowed'], $message);

        if ($expectedApprovalReady) {
            self::assertNotNull($payload, $message);
            self::assertSame('approval_ready', $preflight['status'], $message);
            self::assertSame([], $preflight['blocked_reasons'], $message);
            self::assertTrue($preflight['human_approval_required'], $message);
            self::assertSame('pending_approval', $payload['status'], $message);
            self::assertSame('', $payload['blocked_reason'], $message);

            $flow = $service->buildExecutionFlowItem($this->intentRow($caseId, $payload));
            self::assertSame('approval', $flow['stage'], $message);
            self::assertSame('pending_approval', $flow['approval']['status'], $message);
            self::assertSame(0, $flow['approval']['approved_by'], $message);
            self::assertSame('', $flow['approval']['approved_at'], $message);
            self::assertSame('', $flow['approval']['remark'], $message);
            self::assertSame('pending_create', $flow['execution']['status'], $message);
            self::assertSame('approve_intent', $flow['next_action']['key'], $message);
        } else {
            self::assertSame('blocked', $preflight['status'], $message);
            self::assertNotEmpty($preflight['blocked_reasons'], $message);
            self::assertFalse($preflight['human_approval_required'], $message);

            if ($payload !== null) {
                self::assertSame('blocked', $payload['status'], $message);
                self::assertNotSame('', $payload['blocked_reason'], $message);

                $flow = $service->buildExecutionFlowItem($this->intentRow($caseId, $payload));
                self::assertSame('blocked', $flow['stage'], $message);
                self::assertSame('blocked', $flow['approval']['status'], $message);
                self::assertSame('pending_create', $flow['execution']['status'], $message);
                self::assertSame('resolve_blocker', $flow['next_action']['key'], $message);
            }
        }

        if ($payload !== null) {
            $this->assertProductionExecutionStillRequiresHumanApproval($service, $payload, $message);
        }
    }

    /**
     * @return array<string, array{0:string,1:array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string}}>
     */
    public static function l8VariantProvider(): array
    {
        return [
            'DX-2257 authorized complete fresh success' => ['DX-2257', self::factors('authorized', 'complete', 'fresh', 'success')],
            'DX-2258 authorized complete stale failure' => ['DX-2258', self::factors('authorized', 'complete', 'stale', 'failure')],
            'DX-2259 authorized missing fresh failure' => ['DX-2259', self::factors('authorized', 'missing_required', 'fresh', 'failure')],
            'DX-2260 authorized missing stale success' => ['DX-2260', self::factors('authorized', 'missing_required', 'stale', 'success')],
            'DX-2261 restricted complete fresh failure' => ['DX-2261', self::factors('restricted', 'complete', 'fresh', 'failure')],
            'DX-2262 restricted complete stale success' => ['DX-2262', self::factors('restricted', 'complete', 'stale', 'success')],
            'DX-2263 restricted missing fresh success' => ['DX-2263', self::factors('restricted', 'missing_required', 'fresh', 'success')],
            'DX-2264 restricted missing stale failure' => ['DX-2264', self::factors('restricted', 'missing_required', 'stale', 'failure')],
        ];
    }

    /**
     * @param array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string} $factors
     * @return array<string,mixed>
     */
    private function inputForFactors(string $caseId, array $factors): array
    {
        $fresh = $factors['freshness'] === 'fresh';
        $upstreamSuccess = $factors['upstream_state'] === 'success';
        $complete = $factors['data_completeness'] === 'complete';
        $date = $fresh ? self::TARGET_DATE : self::STALE_DATE;
        $windowStart = $date . 'T00:00:00+08:00';
        $windowEnd = $date . 'T23:59:59+08:00';
        $gaps = [];

        if (!$complete) {
            $gaps[] = [
                'code' => 'approval_input_required_evidence_missing',
                'message' => 'Approval input and execution-intent evidence are incomplete.',
            ];
        }
        if (!$fresh) {
            $gaps[] = [
                'code' => 'approval_action_window_expired',
                'message' => 'The operation action window expired before review.',
            ];
        }
        if (!$upstreamSuccess) {
            $gaps[] = [
                'code' => 'upstream_diagnosis_failed',
                'message' => 'The upstream diagnosis did not complete successfully.',
            ];
            $gaps[] = [
                'code' => 'upstream_ai_review_failed',
                'message' => 'The upstream AI review did not complete successfully.',
            ];
        }

        $evidence = [
            'source_data_date' => $date,
            'action_window_status' => $fresh ? 'valid' : 'expired',
            'action_window_start' => $windowStart,
            'action_window_end' => $windowEnd,
            'diagnosis_status' => $upstreamSuccess ? 'success' : 'failed',
            'ai_review_status' => $upstreamSuccess ? 'success' : 'failed',
            'data_gaps' => $gaps,
            'source_policy' => 'local_fixture_only_no_external_ota_access',
            'protected_boundary' => 'Approval test does not write to or call an external OTA.',
        ];

        $input = [
            'hotel_id' => self::HOTEL_ID,
            'source_module' => 'revenue_research',
            'source_record_id' => 283,
            'platform' => 'internal',
            'object_type' => 'revenue_research',
            'action_type' => 'review_operation_recommendation',
            'date_start' => $date,
            'date_end' => $date,
            'target_value' => [],
            'evidence' => $evidence,
            'risk_level' => 'medium',
            'status' => 'pending_approval',
        ];

        if ($complete) {
            $input['target_value'] = [
                'research_product' => 'operation_approval_package',
                'action_text' => $caseId . ' review the operation recommendation',
                'target_metric' => 'operation_approval_closure',
            ];
            $input['expected_metric'] = 'operation_approval_closure';
            $input['evidence']['research_readiness_stage'] = 'research_ready_for_execution';
            $input['evidence']['evidence_refs'] = [$caseId . ':diagnosis', $caseId . ':ai-review'];
            $input['evidence']['approval_reason'] = $caseId . ' requires a human approval decision';
        }

        return $input;
    }

    /**
     * Test-layer business preflight only. This is deliberately not presented as
     * a production method or as HTTP, database, UI, or external-system evidence.
     * It makes the four approval factors individually observable while the real
     * service remains the source of truth for hotel scope, intent state, flow,
     * and execution approval enforcement.
     *
     * @param list<int> $permittedHotelIds
     * @param array<string,mixed> $input
     * @param array<string,mixed>|null $payload
     * @return array{status:string,can_enter_approval:bool,human_approval_required:bool,approval_mode:string,auto_execute_allowed:bool,blocked_reasons:list<string>}
     */
    private function evaluateTestOnlyBusinessApprovalPreflight(
        array $permittedHotelIds,
        array $input,
        ?array $payload
    ): array {
        $reasons = [];
        $hotelId = (int)($input['hotel_id'] ?? 0);
        if (!in_array($hotelId, array_map('intval', $permittedHotelIds), true)) {
            $reasons[] = 'hotel_permission_denied';
        }

        $target = is_array($input['target_value'] ?? null) ? $input['target_value'] : [];
        $evidence = is_array($input['evidence'] ?? null) ? $input['evidence'] : [];
        foreach (['research_product', 'action_text', 'target_metric'] as $field) {
            if (trim((string)($target[$field] ?? '')) === '') {
                $reasons[] = 'approval_input_required_evidence_missing';
                break;
            }
        }
        if (
            trim((string)($evidence['research_readiness_stage'] ?? '')) !== 'research_ready_for_execution'
            || empty($evidence['evidence_refs'])
            || trim((string)($evidence['approval_reason'] ?? '')) === ''
        ) {
            $reasons[] = 'approval_input_required_evidence_missing';
        }

        $windowStart = $this->dateTimeOrNull((string)($evidence['action_window_start'] ?? ''));
        $windowEnd = $this->dateTimeOrNull((string)($evidence['action_window_end'] ?? ''));
        $reviewedAt = new DateTimeImmutable(self::REVIEWED_AT);
        if (
            ($evidence['action_window_status'] ?? '') !== 'valid'
            || $windowStart === null
            || $windowEnd === null
            || $reviewedAt < $windowStart
            || $reviewedAt > $windowEnd
        ) {
            $reasons[] = 'approval_action_window_expired_or_inactive';
        }

        if (($evidence['diagnosis_status'] ?? '') !== 'success') {
            $reasons[] = 'upstream_diagnosis_failed';
        }
        if (($evidence['ai_review_status'] ?? '') !== 'success') {
            $reasons[] = 'upstream_ai_review_failed';
        }
        if ($payload !== null && ($payload['status'] ?? '') !== 'pending_approval') {
            $reasons[] = 'production_intent_blocked';
        }

        $reasons = array_values(array_unique($reasons));
        $approvalReady = $payload !== null && $reasons === [];

        return [
            'status' => $approvalReady ? 'approval_ready' : 'blocked',
            'can_enter_approval' => $approvalReady,
            'human_approval_required' => $approvalReady,
            'approval_mode' => 'manual',
            'auto_execute_allowed' => false,
            'blocked_reasons' => $reasons,
        ];
    }

    private function dateTimeOrNull(string $value): ?DateTimeImmutable
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed>|null $payload
     * @param array{blocked_reasons:list<string>} $preflight
     * @param array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string} $factors
     */
    private function assertFourFactorsLanded(
        array $input,
        ?array $payload,
        array $preflight,
        array $factors,
        string $message
    ): void {
        $reasons = $preflight['blocked_reasons'];
        self::assertSame(
            $factors['actor_scope'] === 'restricted',
            in_array('hotel_permission_denied', $reasons, true),
            $message . ' actor_scope'
        );

        $complete = $factors['data_completeness'] === 'complete';
        self::assertSame($complete, !empty($input['target_value']), $message . ' data_completeness target');
        self::assertSame($complete, !empty($input['evidence']['evidence_refs']), $message . ' data_completeness evidence');
        self::assertSame(
            !$complete,
            in_array('approval_input_required_evidence_missing', $reasons, true),
            $message . ' data_completeness gate'
        );

        $fresh = $factors['freshness'] === 'fresh';
        self::assertSame($fresh ? 'valid' : 'expired', $input['evidence']['action_window_status'], $message . ' freshness state');
        self::assertSame($fresh ? self::TARGET_DATE : self::STALE_DATE, $input['evidence']['source_data_date'], $message . ' freshness date');
        self::assertSame(
            !$fresh,
            in_array('approval_action_window_expired_or_inactive', $reasons, true),
            $message . ' freshness gate'
        );

        $upstreamSuccess = $factors['upstream_state'] === 'success';
        self::assertSame($upstreamSuccess ? 'success' : 'failed', $input['evidence']['diagnosis_status'], $message . ' diagnosis state');
        self::assertSame($upstreamSuccess ? 'success' : 'failed', $input['evidence']['ai_review_status'], $message . ' AI review state');
        self::assertSame(
            !$upstreamSuccess,
            in_array('upstream_diagnosis_failed', $reasons, true),
            $message . ' diagnosis gate'
        );
        self::assertSame(
            !$upstreamSuccess,
            in_array('upstream_ai_review_failed', $reasons, true),
            $message . ' AI review gate'
        );

        if ($payload !== null) {
            self::assertSame(self::HOTEL_ID, $payload['hotel_id'], $message);
            self::assertSame(self::REVIEWER_ID, $payload['created_by'], $message);
            self::assertSame('revenue_research', $payload['object_type'], $message);
            self::assertSame('internal', $payload['platform'], $message);
            self::assertSame($input['evidence']['action_window_status'], $payload['evidence']['action_window_status'], $message);
            self::assertSame($input['evidence']['diagnosis_status'], $payload['evidence']['diagnosis_status'], $message);
            self::assertSame($input['evidence']['ai_review_status'], $payload['evidence']['ai_review_status'], $message);

            $gapCodes = array_column($payload['evidence']['data_gaps'] ?? [], 'code');
            self::assertSame(!$complete, in_array('approval_input_required_evidence_missing', $gapCodes, true), $message);
            self::assertSame(!$fresh, in_array('approval_action_window_expired', $gapCodes, true), $message);
            self::assertSame(!$upstreamSuccess, in_array('upstream_diagnosis_failed', $gapCodes, true), $message);
            self::assertSame(!$upstreamSuccess, in_array('upstream_ai_review_failed', $gapCodes, true), $message);
        }
    }

    /** @param array<string,mixed> $payload */
    private function intentRow(string $caseId, array $payload): array
    {
        return [
            'id' => 283000 + (int)substr($caseId, 3),
            'source_module' => $payload['source_module'],
            'source_record_id' => $payload['source_record_id'],
            'hotel_id' => $payload['hotel_id'],
            'platform' => $payload['platform'],
            'object_type' => $payload['object_type'],
            'action_type' => $payload['action_type'],
            'date_start' => $payload['date_start'],
            'date_end' => $payload['date_end'],
            'current_value_json' => json_encode($payload['current_value'], JSON_UNESCAPED_UNICODE),
            'target_value_json' => json_encode($payload['target_value'], JSON_UNESCAPED_UNICODE),
            'evidence_json' => json_encode($payload['evidence'], JSON_UNESCAPED_UNICODE),
            'expected_metric' => $payload['expected_metric'],
            'expected_delta' => $payload['expected_delta'],
            'risk_level' => $payload['risk_level'],
            'status' => $payload['status'],
            'blocked_reason' => $payload['blocked_reason'],
            'approved_by' => 0,
            'approved_at' => '',
            'review_remark' => '',
            'created_at' => '2026-07-15 11:00:00',
        ];
    }

    /** @param array<string,mixed> $payload */
    private function assertProductionExecutionStillRequiresHumanApproval(
        OperationManagementService $service,
        array $payload,
        string $message
    ): void {
        try {
            $service->buildExecutionTaskUpdate(
                ['id' => 283, 'status' => 'pending_execute'],
                ['id' => 283, 'status' => $payload['status']],
                [
                    'status' => 'executed',
                    'evidence' => ['remark' => 'test-only attempt without approval'],
                ],
                self::REVIEWER_ID
            );
            self::fail($message . ' non-approved intent unexpectedly entered execution');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('intent must be approved before execution', $exception->getMessage(), $message);
        }
    }

    /** @return array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string} */
    private static function factors(
        string $actorScope,
        string $dataCompleteness,
        string $freshness,
        string $upstreamState
    ): array {
        return [
            'actor_scope' => $actorScope,
            'data_completeness' => $dataCompleteness,
            'freshness' => $freshness,
            'upstream_state' => $upstreamState,
        ];
    }
}
