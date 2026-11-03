<?php
declare(strict_types=1);
namespace Tests;

use app\service\RevenueCockpitApprovalService;
use app\service\RevenueCockpitIntentProvenanceService;
use app\service\RevenueDecisionSnapshotService;
use app\service\RevenueDecisionViewModelAttestationService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

require_once __DIR__ . '/RevenueComparisonFiniteTest.php';

/** local_synthetic_fixture_only: original services, private SQLite, no approval/execution. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RevenueComparisonCurrentnessTest extends TestCase
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
    private function save(array $overview, array $comparisons): array
    {
        $context = (new RevenueCockpitApprovalService())->evidenceContext($overview, 10, 20, '2026-08-20', 'ctrip');
        $model = (new RevenueDecisionViewModelAttestationService())->issue($overview, $comparisons, $context, 10, 20, '2026-08-20', 'ctrip');
        return (new RevenueDecisionSnapshotService())->saveFromOverview($overview, 10, 20, '2026-08-20', 'ctrip', 7, $model, $comparisons);
    }
    private function read(array $snapshot, array $overview, ?array $comparisons): array
    {
        return (new RevenueDecisionSnapshotService())->readExact($snapshot['id'], 10, 20, $overview, $comparisons);
    }
    private function pending(array $snapshot, array $overview, array $comparisons): array
    {
        return (new RevenueDecisionSnapshotService())->createOpportunityPendingApproval($snapshot['id'], 10, 20, 7, 'traffic_entry_shortage', $overview, $comparisons);
    }
    private function assertPendingRejected(array $snapshot, array $overview, array $comparisons): void
    {
        $before = $this->rows();
        try { $this->pending($snapshot, $overview, $comparisons); self::fail('Stale comparison created pending intent'); }
        catch (\RuntimeException $e) { self::assertSame('revenue_decision_snapshot_evidence_stale', $e->getMessage()); }
        self::assertSame($before, $this->rows());
    }
    private function rows(): array
    {
        return array_map(static fn($table) => Db::name($table)->order('id')->select()->toArray(),
            ['revenue_decision_snapshots', 'operation_execution_intents', 'operation_execution_tasks', 'operation_execution_evidence']);
    }
    private function assertImmutable(array $saved, array $read): void
    {
        foreach (['id', 'content_digest', 'visible_model_digest', 'evidence_digest', 'visible_model', 'source_refs', 'evidence_summary'] as $key) {
            self::assertSame($saved[$key], $read[$key], $key);
        }
        self::assertSame('readback_verified', $read['persistence_status']);
        self::assertJson(json_encode($read, JSON_THROW_ON_ERROR));
    }
    public function testBaselineCorrectionKeepsExactHistoryButBlocksOldPendingSuggestion(): void
    {
        [$overview, $comparisons] = RevenueComparisonFiniteTest::inputs('normal');
        $saved = $this->save($overview, $comparisons);
        $corrected = $comparisons;
        $corrected['previous_overview']['three_source_fact_layer']['sources']['ctrip_ota']['facts']['list_exposure'] = 500;
        $before = $this->rows();
        $read = $this->read($saved, $overview, $corrected);
        self::assertSame('stale_current_comparison', $read['evidence_identity_status']);
        $this->assertImmutable($saved, $read);
        self::assertSame($before, $this->rows());
        $this->assertPendingRejected($saved, $overview, $corrected);
        $new = $this->save($overview, $corrected);
        self::assertNotSame($saved['id'], $new['id']);
        $opportunity = array_values(array_filter($new['visible_model']['opportunities'], static fn($o) => $o['opportunityKey'] === 'traffic_entry_shortage'))[0];
        self::assertSame('no_signal', $opportunity['state']);
        self::assertFalse($opportunity['canCreatePendingApproval']);
        self::assertSame('stale_current_comparison', $this->read($saved, $overview, $corrected)['evidence_identity_status']);
        $this->assertImmutable($saved, (new RevenueDecisionSnapshotService())->readHistoricalExact($saved['id'], 10, 20, '2026-08-20', 'ctrip'));
    }
    public function testSameValuesWithChangedBaselineSourceIdentityAreStaleAndOtherPlatformIsIndependent(): void
    {
        [$overview, $comparisons] = RevenueComparisonFiniteTest::inputs('normal');
        $saved = $this->save($overview, $comparisons);
        foreach (['source', 'strict', 'receipt', 'pms_source'] as $kind) {
            $changed = $comparisons;
            if ($kind === 'source') $changed['previous_overview']['three_source_fact_layer']['sources']['ctrip_ota']['provenance']['row_ids'] = [999];
            if ($kind === 'strict') $changed['previous_overview']['cockpit_strict_evidence']['platforms']['ctrip']['accepted_row_ids'] = [999];
            if ($kind === 'receipt') $changed['previous_overview']['dual_ota_field_closure']['platforms']['ctrip']['current_receipt_record_ids'] = [999];
            if ($kind === 'pms_source') $changed['previous_overview']['three_source_fact_layer']['sources']['dingdandao_pms']['source'] = ['table' => 'dingdandao_operating_target_captures', 'record_id' => 999];
            self::assertSame('stale_current_comparison', $this->read($saved, $overview, $changed)['evidence_identity_status'], $kind);
            $this->assertPendingRejected($saved, $overview, $changed);
        }
        $other = $comparisons;
        $other['previous_overview']['three_source_fact_layer']['sources']['meituan_ota']['facts']['revenue'] = 9;
        $other['previous_overview']['cockpit_strict_evidence']['platforms']['meituan']['accepted_row_ids'] = [999];
        self::assertSame('matched_current', $this->read($saved, $overview, $other)['evidence_identity_status']);
    }
    public function testMissingBaselineAndRecoveryReuseOriginalSnapshotAndPendingIdentity(): void
    {
        [$overview, $comparisons] = RevenueComparisonFiniteTest::inputs('normal');
        $saved = $this->save($overview, $comparisons);
        $pending = $this->pending($saved, $overview, $comparisons);
        $before = $this->rows();
        foreach (['missing_baseline', 'zero_baseline'] as $mode) {
            [, $failed] = RevenueComparisonFiniteTest::inputs($mode);
            self::assertSame('stale_current_comparison', $this->read($saved, $overview, $failed)['evidence_identity_status']);
            $this->assertPendingRejected($saved, $overview, $failed);
        }
        self::assertSame('matched_current', $this->read($saved, $overview, $comparisons)['evidence_identity_status']);
        self::assertSame($saved['id'], $this->save($overview, $comparisons)['id']);
        self::assertSame($pending['execution_intent']['id'], $this->pending($saved, $overview, $comparisons)['execution_intent']['id']);
        self::assertSame($before, $this->rows());
        self::assertSame([], $pending['execution_intent']['tasks']);
        self::assertSame([], $before[2]); self::assertSame([], $before[3]);
    }
    public function testUnavailableOrMismatchedCurrentBaselinesStayUnverifiedWithoutWrites(): void
    {
        [$overview, $comparisons] = RevenueComparisonFiniteTest::inputs('normal');
        $saved = $this->save($overview, $comparisons);
        $cases = [['current_read_failed' => true]];
        foreach (['tenant', 'hotel', 'platform', 'date'] as $kind) {
            $wrong = $comparisons;
            if ($kind === 'tenant') $wrong['previous_overview']['three_source_fact_layer']['hotel']['tenant_id'] = 11;
            if ($kind === 'hotel') $wrong['previous_overview']['hotel_id'] = 21;
            if ($kind === 'platform') $wrong['previous_overview']['cockpit_strict_evidence']['platform'] = 'meituan';
            if ($kind === 'date') $wrong['previous_overview']['business_date'] = '2026-08-18';
            $cases[] = $wrong;
        }
        foreach ($cases as $wrong) {
            self::assertSame('comparison_currentness_not_verified', $this->read($saved, $overview, $wrong)['evidence_identity_status']);
            $this->assertPendingRejected($saved, $overview, $wrong);
        }
        // No current baseline reader succeeds in this private schema: do not reuse frozen inputs.
        self::assertSame('comparison_currentness_not_verified', $this->read($saved, $overview, null)['evidence_identity_status']);
        self::assertSame('matched_current', $this->read($saved, $overview, $comparisons)['evidence_identity_status']);
    }
    public function testLegacyBaselineSnapshotRemainsReadableAndRequiresFreshSaveForCurrentness(): void
    {
        [$overview, $comparisons] = RevenueComparisonFiniteTest::inputs('normal');
        $new = $this->save($overview, $comparisons);
        $row = Db::name('revenue_decision_snapshots')->where('id', $new['id'])->find();
        $content = array_intersect_key($new, array_flip(['contract_version', 'tenant_id', 'system_hotel_id', 'platform', 'business_date', 'source_refs', 'metric_definitions', 'visible_model', 'missing_items', 'evidence_summary', 'visible_model_digest', 'evidence_digest', 'created_by']));
        unset($content['evidence_summary']['comparison_evidence']);
        unset($row['id']);
        $row['evidence_summary_json'] = json_encode($content['evidence_summary'], JSON_THROW_ON_ERROR);
        $row['content_digest'] = (new \ReflectionMethod(RevenueDecisionSnapshotService::class, 'digest'))->invoke(null, $content);
        $row['idempotency_key'] = (new \ReflectionMethod(RevenueDecisionSnapshotService::class, 'snapshotIdempotencyKey'))->invoke(null, 10, 20, 7, $row['content_digest']);
        $legacyId = Db::name('revenue_decision_snapshots')->insertGetId($row); // Private legacy fixture, append only.
        $before = $this->rows();
        $legacy = (new RevenueDecisionSnapshotService())->readHistoricalExact($legacyId, 10, 20, '2026-08-20', 'ctrip');
        self::assertSame($row['content_digest'], $legacy['content_digest']);
        self::assertSame('not_checked', $legacy['evidence_identity_status']);
        self::assertSame('comparison_currentness_not_verified', $this->read($legacy, $overview, $comparisons)['evidence_identity_status']);
        $this->assertPendingRejected($legacy, $overview, $comparisons);
        self::assertSame($new['id'], $this->save($overview, $comparisons)['id']);
        self::assertSame($before, $this->rows());
    }
    public function testInternalLineageAndReadOnlyOperationProvenanceRejectAndRecoverBaselineDrift(): void
    {
        [$overview, $comparisons] = RevenueComparisonFiniteTest::inputs('normal');
        $saved = $this->save($overview, $comparisons);
        $pending = $this->pending($saved, $overview, $comparisons);
        $intent = $pending['execution_intent'];
        $readDates = [];
        $reader = function ($tenant, $hotel, $date, $platform) use ($overview, &$comparisons, &$readDates) {
            self::assertSame([10, 20, 'ctrip'], [$tenant, $hotel, $platform]);
            $readDates[] = $date;
            return $date === '2026-08-20' ? $overview : $comparisons['previous_overview'];
        };
        $service = new RevenueCockpitIntentProvenanceService($reader);
        self::assertTrue($service->isIntentCurrent($intent));
        $before = $this->rows();
        $comparisons['previous_overview']['three_source_fact_layer']['sources']['ctrip_ota']['facts']['list_exposure'] = 500;
        self::assertFalse($service->isIntentCurrent($intent));
        $trace = $intent['target_value']['action_card']['trace'];
        try {
            (new RevenueDecisionSnapshotService())->assertOpportunityCurrent($saved['id'], 10, 20, '2026-08-20', 'ctrip', $saved['content_digest'], 'traffic_entry_shortage', $trace['opportunity_digest'], $overview, $comparisons);
            self::fail('Internal lineage accepted baseline drift');
        } catch (\RuntimeException $e) { self::assertSame('revenue_decision_snapshot_action_lineage_stale', $e->getMessage()); }
        $comparisons['previous_overview']['three_source_fact_layer']['sources']['ctrip_ota']['facts']['list_exposure'] = 1000;
        self::assertTrue($service->isIntentCurrent($intent));
        self::assertContains('2026-08-19', $readDates);
        self::assertSame($before, $this->rows());
    }
    public function testNoBaselineCompatibilityAndNewBasisSelectionStayExplicit(): void
    {
        [$overview, $comparisons] = RevenueComparisonFiniteTest::inputs('normal');
        $empty = $comparisons; $empty['previous_date'] = ''; $empty['previous_overview'] = [];
        $saved = $this->save($overview, $empty);
        self::assertArrayNotHasKey('comparison_evidence', $saved['evidence_summary']);
        self::assertSame('matched_current', $this->read($saved, $overview, null)['evidence_identity_status']);
        self::assertSame('stale_current_comparison', $this->read($saved, $overview, $comparisons)['evidence_identity_status']);
        self::assertSame($saved['id'], $this->save($overview, $empty)['id']);
    }
    public function testSameWeekdayBasisIsBoundIndependentlyAndWrongWeekdayCannotAttest(): void
    {
        [$overview, $comparisons] = RevenueComparisonFiniteTest::inputs('normal');
        $comparisons['same_weekday_date'] = '2026-08-13';
        $comparisons['same_weekday_overview'] = json_decode(str_replace('2026-08-19', '2026-08-13',
            json_encode($comparisons['previous_overview'], JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
        $saved = $this->save($overview, $comparisons);
        self::assertSame('2026-08-13', $saved['evidence_summary']['comparison_evidence']['baselines']['same_weekday']['business_date']);
        $changed = $comparisons;
        $changed['same_weekday_overview']['three_source_fact_layer']['sources']['ctrip_ota']['facts']['list_exposure'] = 500;
        self::assertSame('stale_current_comparison', $this->read($saved, $overview, $changed)['evidence_identity_status']);
        $this->assertPendingRejected($saved, $overview, $changed);
        $wrong = $comparisons; $wrong['same_weekday_date'] = '2026-08-12';
        self::assertSame('comparison_currentness_not_verified', $this->read($saved, $overview, $wrong)['evidence_identity_status']);
        $this->assertPendingRejected($saved, $overview, $wrong);
        self::assertSame('matched_current', $this->read($saved, $overview, $comparisons)['evidence_identity_status']);
        self::assertSame($saved['id'], $this->save($overview, $comparisons)['id']);
    }
}
