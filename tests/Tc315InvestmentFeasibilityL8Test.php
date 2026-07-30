<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Base;
use app\controller\InvestmentDecision;
use app\service\BusinessClosureOverviewService;
use app\service\InvestmentDecisionSupportService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

final class Tc315InvestmentFeasibilityL8Test extends TestCase
{
    private const TARGET_HOTEL_ID = 7315;
    private const TARGET_DATE = '2026-07-15';
    private const STALE_DATE = '2026-06-30';

    #[DataProvider('l8VariantProvider')]
    public function testTc315RequiresAuthorizedCompleteFreshSuccessfulEvidenceWithoutClaimingHttpAcl(
        string $caseId,
        string $actorScope,
        string $dataCompleteness,
        string $freshness,
        string $upstreamState
    ): void {
        $authorized = $this->assertTargetHotelControllerGuard($actorScope, $caseId);
        $complete = $dataCompleteness === 'complete';
        $fresh = $freshness === 'fresh';
        $upstreamSuccess = $upstreamState === 'success';

        $closureOverview = $this->closureOverview($complete, $fresh, $upstreamSuccess);
        $evidence = $this->decisionEvidence($complete, $fresh);
        $service = new InvestmentDecisionSupportService();
        $domainOverview = $service->buildOverviewFromEvidence(
            $closureOverview,
            $evidence['expansion'],
            $evidence['transfer'],
            $evidence['feasibility'],
            $evidence['competitor']
        );

        // Pure-domain factor evidence is separate from endpoint authorization evidence.
        $scopedOverview = $authorized
            ? $domainOverview
            : $service->buildOverviewFromEvidence(
                $closureOverview,
                $this->permissionDeniedEvidence(),
                $this->permissionDeniedEvidence(),
                $this->permissionDeniedEvidence(),
                $this->permissionDeniedCompetitorEvidence()
            );

        $this->assertEvidenceCompleteness($domainOverview, $complete, $caseId);
        $this->assertFreshness($domainOverview, $fresh, $caseId);
        $this->assertUpstreamState($closureOverview, $domainOverview, $upstreamSuccess, $caseId);
        $this->assertActorScope($scopedOverview, $authorized, $caseId);

        $expectedDecisionAllowed = $authorized && $complete && $fresh && $upstreamSuccess;
        self::assertSame($expectedDecisionAllowed, $scopedOverview['summary']['decision_allowed'], $caseId);
        self::assertSame(
            $expectedDecisionAllowed ? 'decision_ready' : 'not_ready',
            $scopedOverview['summary']['status'],
            $caseId
        );
        self::assertSame(
            $expectedDecisionAllowed ? 1 : 0,
            $scopedOverview['sections']['decision_records']['eligible_count'],
            $caseId
        );

        self::assertSame('closed_operating_data_only', $scopedOverview['source_scope'], $caseId);
        self::assertStringContainsString(
            'does not turn missing evidence into investment conclusions',
            $scopedOverview['protected_boundary'],
            $caseId
        );
        $formulas = array_column(
            $scopedOverview['sections']['investment_calculation']['formula_inventory'],
            'formula',
            'scope'
        );
        self::assertSame(
            'RevPAR = ADR * OCC; payback_months from base scenario net cashflow',
            $formulas['feasibility'],
            $caseId
        );

        if ($expectedDecisionAllowed) {
            self::assertSame('closed', $scopedOverview['business_closure_chain']['status'], $caseId);
            self::assertSame(0, $scopedOverview['sections']['risk_alerts']['blocking_count'], $caseId);
            self::assertSame(
                'feasible_under_base_case_assumptions',
                $scopedOverview['sections']['decision_records']['records'][0]['decision'],
                $caseId
            );
        } else {
            self::assertNotSame('closed', $scopedOverview['business_closure_chain']['status'], $caseId);
        }
    }

    /**
     * @return array<string, array{0:string,1:string,2:string,3:string,4:string}>
     */
    public static function l8VariantProvider(): array
    {
        return [
            'DX-2513 authorized complete fresh success' => ['DX-2513', 'authorized', 'complete', 'fresh', 'success'],
            'DX-2514 authorized complete stale failure' => ['DX-2514', 'authorized', 'complete', 'stale', 'failure'],
            'DX-2515 authorized missing fresh failure' => ['DX-2515', 'authorized', 'missing_required', 'fresh', 'failure'],
            'DX-2516 authorized missing stale success' => ['DX-2516', 'authorized', 'missing_required', 'stale', 'success'],
            'DX-2517 restricted complete fresh failure' => ['DX-2517', 'restricted', 'complete', 'fresh', 'failure'],
            'DX-2518 restricted complete stale success' => ['DX-2518', 'restricted', 'complete', 'stale', 'success'],
            'DX-2519 restricted missing fresh success' => ['DX-2519', 'restricted', 'missing_required', 'fresh', 'success'],
            'DX-2520 restricted missing stale failure' => ['DX-2520', 'restricted', 'missing_required', 'stale', 'failure'],
        ];
    }

    private function assertEvidenceCompleteness(array $overview, bool $complete, string $caseId): void
    {
        $calculation = $overview['sections']['investment_calculation'];
        $decisionRecords = $overview['sections']['decision_records'];
        $competitor = $overview['sections']['competitor_comparison'];
        $operatingGapCodes = array_column($overview['operating_data_gate']['missing_evidence'], 'code');

        self::assertSame(1, $calculation['record_count'], $caseId);
        self::assertCount(1, $decisionRecords['records'], $caseId);

        if ($complete) {
            self::assertSame(1, $calculation['ready_record_count'], $caseId);
            self::assertTrue($decisionRecords['records'][0]['readiness_ready'], $caseId);
            self::assertSame(
                'feasible_under_base_case_assumptions',
                $decisionRecords['records'][0]['decision'],
                $caseId
            );
            self::assertSame([], $decisionRecords['records'][0]['missing_evidence'], $caseId);
            self::assertSame(3, $competitor['sample_count'], $caseId);
            self::assertNotContains('competitor_decision_eligible_sample_missing', array_column($competitor['missing_evidence'], 'code'), $caseId);
            return;
        }

        self::assertSame(0, $calculation['ready_record_count'], $caseId);
        self::assertFalse($decisionRecords['records'][0]['readiness_ready'], $caseId);
        self::assertSame('', $decisionRecords['records'][0]['decision'], $caseId);
        $recordGapCodes = array_column($decisionRecords['records'][0]['missing_evidence'], 'code');
        foreach ([
            'closed_operating_roi_missing',
            'operation_process_closure_missing',
            'investment_assumptions_missing',
            'competitor_evidence_missing',
        ] as $gapCode) {
            self::assertContains($gapCode, $recordGapCodes, $caseId);
        }
        self::assertContains('closed_operating_roi_missing', $operatingGapCodes, $caseId);
        self::assertContains('operation_process_closure_missing', $operatingGapCodes, $caseId);
        self::assertSame(0, $competitor['sample_count'], $caseId);
        self::assertContains('competitor_decision_eligible_sample_missing', array_column($competitor['missing_evidence'], 'code'), $caseId);
    }

    private function assertFreshness(array $overview, bool $fresh, string $caseId): void
    {
        $expectedDate = $fresh ? self::TARGET_DATE : self::STALE_DATE;
        self::assertSame(
            $expectedDate . ' 10:00:00',
            $overview['sections']['investment_calculation']['records'][0]['updated_at'],
            $caseId
        );
        self::assertSame($expectedDate, $overview['sections']['competitor_comparison']['latest_at'], $caseId);

        $otaStage = $this->stage($overview, 'ota_data');
        $otaGapCodes = array_column($otaStage['missing_evidence'], 'code');
        if ($fresh) {
            self::assertNotContains('target_date_stale', $otaGapCodes, $caseId);
        } else {
            self::assertContains('target_date_stale', $otaGapCodes, $caseId);
            self::assertFalse($overview['operating_data_gate']['can_use_for_investment_judgement'], $caseId);
        }
    }

    private function assertUpstreamState(
        array $closureOverview,
        array $overview,
        bool $upstreamSuccess,
        string $caseId
    ): void {
        $modules = array_column($closureOverview['modules'], null, 'key');
        if ($upstreamSuccess) {
            self::assertNotSame('blocked_by_p0_ota_gate', $overview['operating_data_gate']['status'], $caseId);
            self::assertNotSame('blocked_by_p0_ota_gate', $modules['ai_daily_report']['status'], $caseId);
            self::assertNotSame('blocked_by_p0_ota_gate', $modules['operation_execution']['status'], $caseId);
            return;
        }

        self::assertSame('blocked_by_p0_ota_gate', $overview['operating_data_gate']['status'], $caseId);
        self::assertSame(
            'blocked_by_p0_ota_gate',
            $overview['operating_data_gate']['p0_downstream_gate']['status'],
            $caseId
        );
        self::assertSame('blocked_by_ai_summary_failure', $modules['ai_daily_report']['pre_p0_status'], $caseId);
        self::assertSame('blocked_by_p0_ota_gate', $modules['ai_daily_report']['status'], $caseId);
        self::assertSame('blocked_by_p0_ota_gate', $modules['operation_execution']['status'], $caseId);
        self::assertFalse($modules['operation_execution']['roi_ready'], $caseId);
        self::assertContains(
            'p0_ota_gate_not_ready',
            array_column($overview['operating_data_gate']['missing_evidence'], 'code'),
            $caseId
        );
    }

    private function assertActorScope(array $overview, bool $authorized, string $caseId): void
    {
        $permissionGap = 'target_hotel_permission_denied';
        $calculationGapCodes = array_column(
            $overview['sections']['investment_calculation']['missing_evidence'],
            'code'
        );

        if ($authorized) {
            self::assertNotContains($permissionGap, $calculationGapCodes, $caseId);
            return;
        }

        // This is service-envelope redaction evidence, not an HTTP response or a real ACL request.
        self::assertContains($permissionGap, $calculationGapCodes, $caseId);
        self::assertContains(
            $permissionGap,
            array_column($overview['sections']['competitor_comparison']['missing_evidence'], 'code'),
            $caseId
        );
        self::assertSame(0, $overview['sections']['decision_records']['record_count'], $caseId);
        self::assertFalse($overview['summary']['decision_allowed'], $caseId);
    }

    private function assertTargetHotelControllerGuard(string $actorScope, string $caseId): bool
    {
        $permittedHotelIds = $actorScope === 'authorized'
            ? [self::TARGET_HOTEL_ID]
            : [self::TARGET_HOTEL_ID + 1];
        $reflection = new ReflectionClass(InvestmentDecision::class);
        /** @var InvestmentDecision $controller */
        $controller = $reflection->newInstanceWithoutConstructor();
        $currentUser = new ReflectionProperty(Base::class, 'currentUser');
        $currentUser->setAccessible(true);
        $currentUser->setValue($controller, new class($permittedHotelIds) {
            /** @param array<int, int> $permittedHotelIds */
            public function __construct(private readonly array $permittedHotelIds)
            {
            }

            public function isSuperAdmin(): bool
            {
                return false;
            }

            /** @return array<int, int> */
            public function getPermittedHotelIds(): array
            {
                return $this->permittedHotelIds;
            }

            public function hasHotelPermission(int $hotelId, string $permission): bool
            {
                return $permission === 'can_use_investment'
                    && in_array($hotelId, $this->permittedHotelIds, true);
            }
        });

        $resolveScope = new ReflectionMethod(InvestmentDecision::class, 'resolveHotelScope');
        $resolveScope->setAccessible(true);
        if ($actorScope === 'authorized') {
            self::assertSame(
                [[self::TARGET_HOTEL_ID], self::TARGET_HOTEL_ID],
                $resolveScope->invoke($controller, self::TARGET_HOTEL_ID),
                $caseId
            );
            return true;
        }

        // Direct controller-guard/status-mapping evidence only; no HTTP request is fabricated here.
        try {
            $resolveScope->invoke($controller, self::TARGET_HOTEL_ID);
            self::fail($caseId . ': expected target hotel scope rejection.');
        } catch (RuntimeException $e) {
            $statusCode = new ReflectionMethod(InvestmentDecision::class, 'statusCode');
            $statusCode->setAccessible(true);
            self::assertSame(403, $statusCode->invoke($controller, $e), $caseId);
        }
        return false;
    }

    private function closureOverview(bool $complete, bool $fresh, bool $upstreamSuccess): array
    {
        $evidenceReady = $complete && $fresh;
        $latestAt = $fresh ? self::TARGET_DATE : self::STALE_DATE;
        $dataGaps = [];
        if (!$complete) {
            $dataGaps[] = $this->gap('required_roi_process_evidence_missing');
        }
        if (!$fresh) {
            $dataGaps[] = $this->gap('target_date_stale');
        }
        if (!$upstreamSuccess) {
            $dataGaps[] = $this->gap('upstream_ai_operation_failure');
        }

        $signals = [];
        foreach ([
            'ai_daily_report' => 'AI decision evidence',
            'revenue_pricing' => 'Revenue ROI evidence',
            'operation_execution' => 'Operation execution evidence',
        ] as $key => $label) {
            $signals[] = [
                'key' => $key,
                'label' => $label,
                'table_status' => $fresh ? 'ok' : 'stale',
                'record_count' => 2,
                'linked_execution_count' => $evidenceReady ? 2 : 0,
                'approved_count' => $evidenceReady ? 2 : 0,
                'executed_count' => $evidenceReady ? 2 : 0,
                'evidence_ready_count' => $evidenceReady ? 2 : 0,
                'reviewed_count' => $evidenceReady ? 2 : 0,
                'roi_ready_count' => $evidenceReady ? 1 : 0,
                'ai_summary_failure_count' => !$upstreamSuccess && $key === 'ai_daily_report' ? 1 : 0,
                'source_scope' => 'target_hotel=' . self::TARGET_HOTEL_ID . ';target_date=' . self::TARGET_DATE,
                'latest_at' => $latestAt,
                'data_gaps' => $dataGaps,
            ];
        }

        $p0Gate = $upstreamSuccess ? [
            'status' => 'ready',
            'current_upstream_status' => 'ready',
            'required_upstream_status' => 'ready',
        ] : [
            'status' => 'blocked_by_p0_ota_gate',
            'current_upstream_status' => 'failure',
            'required_upstream_status' => 'ready',
            'blocking_missing_inputs' => [
                'p0_field_loop_verifier_ready',
                'ai_decision_success',
                'operation_execution_success',
            ],
        ];

        return (new BusinessClosureOverviewService())->buildOverviewFromSignals(
            $signals,
            ['total' => 2, 'roi_ready' => $evidenceReady ? 1 : 0],
            $dataGaps,
            $p0Gate
        );
    }

    /** @return array<string, array<string, mixed>> */
    private function decisionEvidence(bool $complete, bool $fresh): array
    {
        $updatedAt = ($fresh ? self::TARGET_DATE : self::STALE_DATE) . ' 10:00:00';
        $missing = $complete ? [] : [
            $this->gap('closed_operating_roi_missing'),
            $this->gap('operation_process_closure_missing'),
            $this->gap('investment_assumptions_missing'),
            $this->gap('competitor_evidence_missing'),
        ];
        $freshnessGaps = $fresh ? [] : [$this->gap('target_date_stale')];

        return [
            'expansion' => ['status' => 'ok', 'records' => [], 'data_gaps' => []],
            'transfer' => ['status' => 'ok', 'records' => [], 'data_gaps' => []],
            'feasibility' => [
                'status' => $complete ? 'ok' : 'partial',
                'records' => [[
                    'source_module' => 'feasibility_report',
                    'id' => 31501,
                    'record_type' => 'feasibility',
                    'title' => 'TC-315 isolated target hotel feasibility',
                    'decision' => $complete ? 'feasible_under_base_case_assumptions' : '',
                    'risk_level' => $complete ? 'medium' : 'unknown',
                    'readiness' => [
                        'stage' => $complete ? 'feasibility_ready' : 'evidence_gap',
                        'status_label' => $complete ? 'assumption-bound review ready' : 'required evidence missing',
                        'score' => $complete ? 100 : 40,
                        'feasibility_ready' => $complete,
                        'source_scope' => 'target_hotel_feasibility_scope',
                        'missing_evidence' => $missing,
                    ],
                    'updated_at' => $updatedAt,
                ]],
                'data_gaps' => array_merge($missing, $freshnessGaps),
            ],
            'competitor' => [
                'status' => $complete ? 'ok' : 'partial',
                'sample_count' => $complete ? 3 : 0,
                'decision_eligible_sample_count' => $complete ? 3 : 0,
                'latest_at' => $fresh ? self::TARGET_DATE : self::STALE_DATE,
                'data_sources' => $complete
                    ? [['table' => 'isolated_competitor_fixture', 'count' => 3]]
                    : [],
                'data_gaps' => array_merge(
                    $complete ? [] : [$this->gap('competitor_evidence_missing')],
                    $freshnessGaps
                ),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function permissionDeniedEvidence(): array
    {
        return [
            'status' => 'permission_denied',
            'records' => [],
            'data_gaps' => [$this->gap('target_hotel_permission_denied')],
        ];
    }

    /** @return array<string, mixed> */
    private function permissionDeniedCompetitorEvidence(): array
    {
        return [
            'status' => 'permission_denied',
            'sample_count' => 0,
            'decision_eligible_sample_count' => 0,
            'latest_at' => '',
            'data_sources' => [],
            'data_gaps' => [$this->gap('target_hotel_permission_denied')],
        ];
    }

    /** @return array{code:string,label:string,next_action:string,message:string} */
    private function gap(string $code): array
    {
        return [
            'code' => $code,
            'label' => $code,
            'next_action' => 'Supply verified target-hotel evidence before investment review.',
            'message' => $code,
        ];
    }

    /** @return array<string, mixed> */
    private function stage(array $overview, string $key): array
    {
        foreach ($overview['business_closure_chain']['stages'] as $stage) {
            if (($stage['key'] ?? '') === $key) {
                return $stage;
            }
        }
        self::fail('Missing business closure stage: ' . $key);
    }
}
