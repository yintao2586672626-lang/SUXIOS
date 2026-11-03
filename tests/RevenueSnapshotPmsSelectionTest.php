<?php
declare(strict_types=1);
namespace Tests;

use app\service\RevenueCockpitApprovalService;
use app\service\RevenueDecisionSnapshotService;
use app\service\RevenueDecisionViewModelAttestationService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

require_once __DIR__.'/RevenueComparisonFiniteTest.php';

/** local_synthetic_fixture_only: original services and private SQLite; no PMS connection. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RevenueSnapshotPmsSelectionTest extends TestCase
{
    private RevenueSnapshotFiniteMetricTest $fixture;
    protected function setUp(): void
    {
        $this->fixture = new RevenueSnapshotFiniteMetricTest('testLargeFiniteValuesSurviveIssueJsonAndSelfAttestation');
        (new \ReflectionMethod($this->fixture, 'setUp'))->invoke($this->fixture);
    }
    protected function tearDown(): void
    {
        (new \ReflectionMethod($this->fixture, 'tearDown'))->invoke($this->fixture);
    }
    public static function inputs(string $mode = 'normal'): array
    {
        [$overview, $comparisons] = RevenueComparisonFiniteTest::inputs('normal');
        foreach (['current', 'previous'] as $basis) {
            $input =& $overview;
            if ($basis === 'previous') $input =& $comparisons['previous_overview'];
            $date = $basis === 'current' ? '2026-08-20' : '2026-08-19';
            foreach (['dingdandao_pms' => 9999, 'meituan_cloud_pms' => ($basis === 'current' ? 7200 : 6000)] as $key => $revenue) {
                $facts = ['room_revenue' => $revenue, 'sold_room_nights' => 12,
                    'occupancy_rate_percent' => 60, 'adr' => $revenue / 12, 'revpar' => $revenue / 20];
                $statuses = [];
                foreach ($facts as $metric => $value) $statuses[$metric] = ['status' => 'readback_verified', 'reason' => 'local_synthetic_fixture_only'];
                $input['three_source_fact_layer']['sources'][$key] = ['data_status' => 'readback_verified',
                    'business_date' => $date, 'actual_business_date' => $date, 'facts' => $facts,
                    'fact_statuses' => $statuses, 'source' => ['provider' => $key,
                        'table' => $key === 'dingdandao_pms' ? 'dingdandao_operating_target_captures' : 'meituan_cloud_pms_captures',
                        'record_id' => $key === 'dingdandao_pms' ? 101 : 202, 'tenant_id' => 10,
                        'system_hotel_id' => 20, 'data_date' => $date, 'readback_status' => 'readback_verified']];
            }
            $input['three_source_fact_layer']['pms_binding'] = ['effective_provider' => 'meituan_cloud_pms'];
            unset($input);
        }
        $source =& $overview['three_source_fact_layer']['sources']['meituan_cloud_pms'];
        if ($mode === 'missing') unset($overview['three_source_fact_layer']['sources']['meituan_cloud_pms']);
        if ($mode === 'provider_mismatch') $source['source']['provider'] = 'dingdandao_pms';
        if ($mode === 'wrong_tenant') $source['source']['tenant_id'] = 11;
        if ($mode === 'wrong_hotel') $source['source']['system_hotel_id'] = 21;
        if ($mode === 'wrong_date') $source['source']['data_date'] = '2026-08-19';
        if ($mode === 'conflict') $overview['three_source_fact_layer']['source_completeness']['meituan_cloud_pms'] = 'missing';
        if ($mode === 'unknown_binding') $overview['three_source_fact_layer']['pms_binding']['effective_provider'] = 'unknown';
        if ($mode === 'corrected') $source['facts']['room_revenue'] = 9000;
        if ($mode === 'baseline_corrected') $comparisons['previous_overview']['three_source_fact_layer']['sources']['meituan_cloud_pms']['facts']['room_revenue'] = 7100;
        if ($mode === 'baseline_binding_switch') $comparisons['previous_overview']['three_source_fact_layer']['pms_binding']['effective_provider'] = 'dingdandao_pms';
        if ($mode === 'inactive_changed') {
            $overview['three_source_fact_layer']['sources']['dingdandao_pms']['facts']['room_revenue'] = 12345;
            $comparisons['previous_overview']['three_source_fact_layer']['sources']['dingdandao_pms']['facts']['room_revenue'] = 12345;
        }
        return [$overview, $comparisons];
    }
    private function model(array $overview, array $comparisons): array
    {
        $context = (new RevenueCockpitApprovalService())->evidenceContext($overview, 10, 20, '2026-08-20', 'ctrip');
        return (new RevenueDecisionViewModelAttestationService())->issue($overview, $comparisons, $context, 10, 20, '2026-08-20', 'ctrip');
    }
    private function save(array $overview, array $comparisons): array
    {
        return (new RevenueDecisionSnapshotService())->saveFromOverview($overview, 10, 20, '2026-08-20', 'ctrip', 7, $this->model($overview, $comparisons), $comparisons);
    }
    private function card(array $model, string $key): array
    {
        foreach ($model['visibleSections'] as $section) foreach ($section['cards'] as $card) if ($card['key'] === $key) return $card;
        self::fail('Original card absent: '.$key);
    }
    private function rows(): array
    {
        return [Db::name('revenue_decision_snapshots')->order('id')->select()->toArray(),
            Db::name('operation_execution_intents')->order('id')->select()->toArray(),
            Db::name('operation_execution_tasks')->count(), Db::name('operation_execution_evidence')->count()];
    }
    private function rejectOldPending(int $id, array $overview, array $comparisons): void
    {
        $before = $this->rows();
        try {
            (new RevenueDecisionSnapshotService())->createOpportunityPendingApproval($id, 10, 20, 7, 'traffic_entry_shortage', $overview, $comparisons);
            self::fail('An old snapshot created a pending intent');
        } catch (\RuntimeException $e) {
            self::assertSame('revenue_decision_snapshot_evidence_stale', $e->getMessage());
        }
        self::assertSame($before, $this->rows());
    }
    public function testSelectedMeituanCardsSaveRepeatExactlyReadAndCreateOnlyPending(): void
    {
        [$overview, $comparisons] = self::inputs();
        $model = $this->model($overview, $comparisons);
        foreach (['room_revenue' => 7200, 'sold_room_nights' => 12, 'occupancy_rate_percent' => 60, 'adr' => 600, 'revpar' => 360] as $key => $value) {
            $card = $this->card($model, 'meituan_cloud_pms:'.$key);
            self::assertEquals($value, $card['value']);
            self::assertSame('PMS（美团云）', $card['sourceLabel']);
            self::assertSame('readback_verified', $card['status']);
            self::assertSame('whole_hotel_accommodation', $card['scope']);
        }
        self::assertSame(1200, $this->card($model, 'compare:meituan_cloud_pms:room_revenue')['value']);
        self::assertSame(8, $this->card($model, 'ctrip_ota:orders')['value']);
        $pmsRecord = array_values(array_filter($model['sourceRecords'], static fn($r) => $r['factScope'] === 'whole_hotel_accommodation'))[0];
        self::assertSame('meituan_cloud_pms_captures', $pmsRecord['table']);
        self::assertSame([202], $pmsRecord['rowIds']);
        self::assertIsArray(json_decode(json_encode($model, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
        $snapshot = $this->save($overview, $comparisons);
        $service = new RevenueDecisionSnapshotService();
        $pending = $service->createOpportunityPendingApproval($snapshot['id'], 10, 20, 7, 'traffic_entry_shortage', $overview, $comparisons);
        self::assertSame('pending_approval', $pending['status']);
        $before = $this->rows();
        self::assertSame($snapshot['id'], $this->save($overview, $comparisons)['id']);
        $read = $service->readExact($snapshot['id'], 10, 20, $overview, $comparisons);
        self::assertSame($snapshot['visible_model'], $read['visible_model']);
        self::assertSame($snapshot['content_digest'], $read['content_digest']);
        self::assertSame('matched_current', $read['evidence_identity_status']);
        self::assertSame($pending['execution_intent']['id'], $service->createOpportunityPendingApproval($snapshot['id'], 10, 20, 7, 'traffic_entry_shortage', $overview, $comparisons)['execution_intent']['id']);
        self::assertNull($service->readExact($snapshot['id'], 11, 20, $overview, $comparisons));
        self::assertNull($service->readExact($snapshot['id'], 10, 21, $overview, $comparisons));
        self::assertSame($before, $this->rows());
        self::assertSame([0, 0], array_slice($before, 2));
    }
    public function testMissingConflictingOrWrongScopeProviderNeverFallsBackAndReadOnlyRecovers(): void
    {
        [$overview, $comparisons] = self::inputs();
        $snapshot = $this->save($overview, $comparisons);
        $service = new RevenueDecisionSnapshotService();
        foreach (['missing', 'provider_mismatch', 'wrong_tenant', 'wrong_hotel', 'wrong_date', 'conflict', 'unknown_binding'] as $mode) {
            [$bad, $comparison] = self::inputs($mode);
            $model = $this->model($bad, $comparison);
            $key = $mode === 'unknown_binding' ? 'pms' : 'meituan_cloud_pms';
            self::assertNull($this->card($model, $key.':room_revenue')['value'], $mode);
            self::assertNotSame('readback_verified', $this->card($model, 'source:'.$key)['status'], $mode);
            self::assertSame(8, $this->card($model, 'ctrip_ota:orders')['value']);
            self::assertSame('stale_current_evidence', $service->readExact($snapshot['id'], 10, 20, $bad, $comparison)['evidence_identity_status']);
            $this->rejectOldPending($snapshot['id'], $bad, $comparison);
        }
        $before = $this->rows();
        $read = $service->readExact($snapshot['id'], 10, 20, $overview, $comparisons);
        self::assertSame('matched_current', $read['evidence_identity_status']);
        self::assertSame($snapshot['visible_model'], $read['visible_model']);
        self::assertSame($before, $this->rows());
    }
    public function testSameProviderCurrentCorrectionInvalidatesOnlyCurrentEvidence(): void
    {
        [$overview, $comparisons] = self::inputs(); $snapshot = $this->save($overview, $comparisons);
        [$changed, $comparison] = self::inputs('corrected');
        $service = new RevenueDecisionSnapshotService();
        self::assertSame('stale_current_evidence', $service->readExact($snapshot['id'], 10, 20, $changed, $comparison)['evidence_identity_status']);
        $this->rejectOldPending($snapshot['id'], $changed, $comparison);
        $new = $this->save($changed, $comparison);
        self::assertNotSame($snapshot['id'], $new['id']);
        self::assertSame(9000, $this->card($new['visible_model'], 'meituan_cloud_pms:room_revenue')['value']);
        self::assertSame('matched_current', $service->readExact($snapshot['id'], 10, 20, $overview, $comparisons)['evidence_identity_status']);
    }
    public function testSelectedBaselineValueAndBindingDriftRejectPendingAndRecoverOriginalIntent(): void
    {
        [$overview, $comparisons] = self::inputs(); $snapshot = $this->save($overview, $comparisons);
        $service = new RevenueDecisionSnapshotService();
        $pending = $service->createOpportunityPendingApproval($snapshot['id'], 10, 20, 7, 'traffic_entry_shortage', $overview, $comparisons);
        foreach (['baseline_corrected', 'baseline_binding_switch'] as $mode) {
            [$changed, $comparison] = self::inputs($mode);
            $read = $service->readExact($snapshot['id'], 10, 20, $changed, $comparison);
            self::assertSame('stale_current_comparison', $read['evidence_identity_status']);
            self::assertSame($snapshot['visible_model'], $read['visible_model']);
            $this->rejectOldPending($snapshot['id'], $changed, $comparison);
        }
        self::assertSame($pending['execution_intent']['id'], $service->createOpportunityPendingApproval($snapshot['id'], 10, 20, 7, 'traffic_entry_shortage', $overview, $comparisons)['execution_intent']['id']);
        self::assertSame([0, 0], array_slice($this->rows(), 2));
    }
    public function testNonEffectiveSourceChangesDoNotInvalidateSelectedFacts(): void
    {
        [$overview, $comparisons] = self::inputs(); $snapshot = $this->save($overview, $comparisons);
        [$changed, $comparison] = self::inputs('inactive_changed'); $before = $this->rows();
        self::assertSame('matched_current', (new RevenueDecisionSnapshotService())->readExact($snapshot['id'], 10, 20, $changed, $comparison)['evidence_identity_status']);
        self::assertSame($snapshot['id'], $this->save($changed, $comparison)['id']);
        self::assertSame($before, $this->rows());
    }
    public function testLegacySingleDingAndDeclaredDingBindingRemainCompatible(): void
    {
        foreach ([false, true] as $binding) {
            [$overview, $comparisons] = self::inputs();
            foreach (['current', 'previous'] as $basis) {
                $input =& $overview;
                if ($basis === 'previous') $input =& $comparisons['previous_overview'];
                unset($input['three_source_fact_layer']['sources']['meituan_cloud_pms']);
                if ($binding) $input['three_source_fact_layer']['pms_binding']['effective_provider'] = 'dingdandao_pms';
                else {unset($input['three_source_fact_layer']['pms_binding']); unset($input['three_source_fact_layer']['sources']['dingdandao_pms']['source']['provider']);}
                unset($input);
            }
            $snapshot = $this->save($overview, $comparisons);
            self::assertSame(9999, $this->card($snapshot['visible_model'], 'dingdandao_pms:room_revenue')['value']);
            self::assertSame('PMS（订单来了）', $this->card($snapshot['visible_model'], 'dingdandao_pms:room_revenue')['sourceLabel']);
            self::assertSame($snapshot['id'], $this->save($overview, $comparisons)['id']);
        }
    }
    public function testSelectedFactLayerFallbackIsDisplayedAndBoundInCurrentAndBaselineDigests(): void
    {
        [$overview, $comparisons] = self::inputs();
        foreach (['current', 'previous'] as $basis) {
            $input =& $overview;
            if ($basis === 'previous') $input =& $comparisons['previous_overview'];
            $input['three_source_fact_layer']['facts']['meituan_cloud_pms'] = $input['three_source_fact_layer']['sources']['meituan_cloud_pms']['facts'];
            unset($input['three_source_fact_layer']['sources']['meituan_cloud_pms']['facts'], $input);
        }
        $snapshot = $this->save($overview, $comparisons);
        self::assertSame(7200, $this->card($snapshot['visible_model'], 'meituan_cloud_pms:room_revenue')['value']);
        $changed = $overview; $changed['three_source_fact_layer']['facts']['meituan_cloud_pms']['room_revenue'] = 8000;
        self::assertSame('stale_current_evidence', (new RevenueDecisionSnapshotService())->readExact($snapshot['id'], 10, 20, $changed, $comparisons)['evidence_identity_status']);
        $comparison = $comparisons; $comparison['previous_overview']['three_source_fact_layer']['facts']['meituan_cloud_pms']['room_revenue'] = 6500;
        self::assertSame('stale_current_comparison', (new RevenueDecisionSnapshotService())->readExact($snapshot['id'], 10, 20, $overview, $comparison)['evidence_identity_status']);
    }

    private function layerPmsFacts(array $overview, string $provider): array
    {
        $overview['three_source_fact_layer']['facts'][$provider] = $overview['three_source_fact_layer']['sources'][$provider]['facts'];
        unset($overview['three_source_fact_layer']['sources'][$provider]['facts']);
        return $overview;
    }

    private function intentCurrentness(array $overview, array $comparisons): \app\service\RevenueCockpitIntentProvenanceService
    {
        return new \app\service\RevenueCockpitIntentProvenanceService(
            static fn(int $tenantId, int $hotelId, string $date, string $platform): array =>
                $date === '2026-08-20' ? $overview : $comparisons['previous_overview']
        );
    }

    public function testEquivalentSelectedPmsFactsRemainCurrentOnExactPendingReadback(): void
    {
        [$overview, $comparisons] = self::inputs();
        $service = new RevenueDecisionSnapshotService();
        $snapshot = $this->save($overview, $comparisons);
        $pending = $service->createOpportunityPendingApproval($snapshot['id'], 10, 20, 7, 'traffic_entry_shortage', $overview, $comparisons);
        $overview = $this->layerPmsFacts($overview, 'meituan_cloud_pms');
        $comparisons['previous_overview'] = $this->layerPmsFacts($comparisons['previous_overview'], 'meituan_cloud_pms');
        $same = $this->save($overview, $comparisons);
        self::assertSame($snapshot['id'], $same['id']);
        self::assertSame($snapshot['content_digest'], $same['content_digest']);
        $repeated = $service->createOpportunityPendingApproval($same['id'], 10, 20, 7, 'traffic_entry_shortage', $overview, $comparisons);
        self::assertSame($pending['execution_intent']['id'], $repeated['execution_intent']['id']);
        $before = $this->rows();
        $intent = (new \app\service\OperationManagementService())->readExecutionIntent($pending['execution_intent']['id'], [20]);
        self::assertSame('pending_approval', $intent['status']);
        self::assertSame([], $intent['tasks']);
        $validated = $this->intentCurrentness($overview, $comparisons)->assertIntentCurrent($intent);
        self::assertSame($intent['id'], $validated['id']);
        self::assertSame('verified', $validated['fact_integrity_status']);
        self::assertTrue($this->intentCurrentness($overview, $comparisons)->isIntentCurrent($intent));
        $overview['cockpit_comparison_contexts'] = $comparisons;
        self::assertSame('verified', (new RevenueCockpitApprovalService())->readFromOverview($overview, 10, 20, '2026-08-20', 'ctrip')['fact_integrity_status']);
        self::assertSame($before, $this->rows());
    }

    public function testSelectedPmsIntentRejectsActualDriftAndReadOnlyRecoversSameIdentity(): void
    {
        [$overview, $comparisons] = self::inputs();
        $overview = $this->layerPmsFacts($overview, 'meituan_cloud_pms');
        $comparisons['previous_overview'] = $this->layerPmsFacts($comparisons['previous_overview'], 'meituan_cloud_pms');
        $snapshot = $this->save($overview, $comparisons);
        $service = new RevenueDecisionSnapshotService();
        $pending = $service->createOpportunityPendingApproval($snapshot['id'], 10, 20, 7, 'traffic_entry_shortage', $overview, $comparisons);
        $operations = new \app\service\OperationManagementService();
        $intent = $operations->readExecutionIntent($pending['execution_intent']['id'], [20]);
        $before = $this->rows();
        $badCases = [];
        $changed = $overview; $changed['three_source_fact_layer']['facts']['meituan_cloud_pms']['room_revenue'] = 8000; $badCases[] = $changed;
        $changed = $overview; $changed['three_source_fact_layer']['pms_binding']['effective_provider'] = 'dingdandao_pms'; $badCases[] = $changed;
        foreach (['missing', 'wrong_tenant', 'wrong_hotel', 'wrong_date', 'provider_mismatch', 'conflict'] as $mode) $badCases[] = self::inputs($mode)[0];
        foreach ($badCases as $bad) {
            self::assertFalse($this->intentCurrentness($bad, $comparisons)->isIntentCurrent($intent));
            $read = $operations->readExecutionIntent($intent['id'], [20]);
            self::assertSame($intent['id'], $read['id']);
            self::assertSame('pending_approval', $read['status']);
            self::assertSame($intent['evidence'], $read['evidence']);
            self::assertSame('stale_current_evidence', $service->readExact($snapshot['id'], 10, 20, $bad, $comparisons)['evidence_identity_status']);
            $bad['cockpit_comparison_contexts'] = $comparisons;
            $manifest = (new RevenueCockpitApprovalService())->readFromOverview($bad, 10, 20, '2026-08-20', 'ctrip');
            self::assertSame($intent['id'], $manifest['execution_intent']['id']);
            self::assertSame('pending_approval', $manifest['status']);
            self::assertSame('drifted', $manifest['fact_integrity_status']);
            self::assertTrue($manifest['approval_blocked']);
            self::assertSame(0, $manifest['execution_task_count']);
        }
        $inactive = $overview; $inactive['three_source_fact_layer']['sources']['dingdandao_pms']['facts']['room_revenue'] = 12345;
        self::assertTrue($this->intentCurrentness($inactive, $comparisons)->isIntentCurrent($intent));
        self::assertTrue($this->intentCurrentness($overview, $comparisons)->isIntentCurrent($intent));
        self::assertSame($snapshot['content_digest'], $service->readExact($snapshot['id'], 10, 20, $overview, $comparisons)['content_digest']);
        foreach ([['tenant_id' => 11], ['hotel_id' => 21], ['date_start' => '2026-08-19', 'date_end' => '2026-08-19'], ['platform' => 'meituan']] as $scope) {
            self::assertFalse($this->intentCurrentness($overview, $comparisons)->isIntentCurrent(array_replace($intent, $scope)));
        }
        try { $operations->readExecutionIntent($intent['id'], [21]); self::fail('Cross-hotel read succeeded'); }
        catch (\RuntimeException $e) { self::assertSame('execution intent not found', $e->getMessage()); }
        self::assertSame($before, $this->rows());
        self::assertSame([0, 0], array_slice($before, 2));
    }

    public function testLegacySingleDingIntentAcceptsEquivalentLayerFacts(): void
    {
        [$overview, $comparisons] = self::inputs();
        foreach (['current', 'previous'] as $basis) {
            $input =& $overview;
            if ($basis === 'previous') $input =& $comparisons['previous_overview'];
            unset($input['three_source_fact_layer']['pms_binding'], $input['three_source_fact_layer']['sources']['meituan_cloud_pms'], $input['three_source_fact_layer']['sources']['dingdandao_pms']['source']['provider'], $input);
        }
        $snapshot = $this->save($overview, $comparisons);
        $pending = (new RevenueDecisionSnapshotService())->createOpportunityPendingApproval($snapshot['id'], 10, 20, 7, 'traffic_entry_shortage', $overview, $comparisons);
        $intent = (new \app\service\OperationManagementService())->readExecutionIntent($pending['execution_intent']['id'], [20]);
        self::assertTrue($this->intentCurrentness($overview, $comparisons)->isIntentCurrent($intent));
        $overview = $this->layerPmsFacts($overview, 'dingdandao_pms');
        $comparisons['previous_overview'] = $this->layerPmsFacts($comparisons['previous_overview'], 'dingdandao_pms');
        $before = $this->rows();
        self::assertTrue($this->intentCurrentness($overview, $comparisons)->isIntentCurrent($intent));
        self::assertSame($snapshot['content_digest'], (new RevenueDecisionSnapshotService())->readExact($snapshot['id'], 10, 20, $overview, $comparisons)['content_digest']);
        self::assertSame($before, $this->rows());
    }
}
