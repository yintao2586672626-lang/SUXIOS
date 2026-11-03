<?php
declare(strict_types=1);
namespace Tests;

use app\service\OperationManagementService;
use app\service\RevenueCockpitApprovalService;
use app\service\RevenueCockpitIntentProvenanceService;
use app\service\RevenueCockpitStrictEvidenceService;
use app\service\RevenueDecisionSnapshotService;
use app\service\RevenueDecisionViewModelAttestationService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

require_once __DIR__.'/RevenueSnapshotPmsSelectionTest.php';

/** local_synthetic_fixture_only: original services and private SQLite; no real source writes. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RevenueMetricFailureReasonTest extends TestCase
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

    private function model(array $overview, array $comparisons): array
    {
        $context = (new RevenueCockpitApprovalService())->evidenceContext($overview, 10, 20, '2026-08-20', 'ctrip');
        return (new RevenueDecisionViewModelAttestationService())->issue($overview, $comparisons, $context, 10, 20, '2026-08-20', 'ctrip');
    }

    private function inputs(): array
    {
        [$overview, $comparisons] = RevenueSnapshotPmsSelectionTest::inputs();
        foreach (['current', 'previous'] as $basis) {
            $input =& $overview;
            if ($basis === 'previous') $input =& $comparisons['previous_overview'];
            $input['three_source_fact_layer']['sources']['ctrip_ota']['facts']['room_nights'] = 4;
            $input['three_source_fact_layer']['sources']['ctrip_ota']['fact_statuses']['room_nights'] = ['status' => 'readback_verified', 'reason' => 'local_synthetic_fixture_only'];
            $input['dual_ota_field_closure']['platforms']['ctrip']['fields'][] = ['metric_key' => 'room_nights',
                'consumer_metric_keys' => ['room_nights'], 'source_record_ids' => [101, 102],
                'revenue_analysis_consumable' => true, 'strict_final_gate' => true,
                'readback_status' => 'readback_verified', 'status' => 'readback_verified'];
            $input['cockpit_strict_evidence'] = (new RevenueCockpitStrictEvidenceService())->build($input, 10, 20,
                $basis === 'current' ? '2026-08-20' : '2026-08-19', 'ctrip', $input['dual_ota_field_closure']);
            unset($input);
        }
        return [$overview, $comparisons];
    }

    private function card(array $model, string $key): array
    {
        foreach ($model['visibleSections'] as $section) foreach ($section['cards'] as $card) if ($card['key'] === $key) return $card;
        self::fail('Original card missing: '.$key);
    }

    private function rows(): array
    {
        return [Db::name('revenue_decision_snapshots')->order('id')->select()->toArray(),
            Db::name('operation_execution_intents')->order('id')->select()->toArray(),
            Db::name('operation_execution_tasks')->count(), Db::name('operation_execution_evidence')->count()];
    }

    private function save(array $overview, array $comparisons, ?array $model = null): array
    {
        return (new RevenueDecisionSnapshotService())->saveFromOverview($overview, 10, 20, '2026-08-20', 'ctrip', 7,
            $model ?? $this->model($overview, $comparisons), $comparisons);
    }

    public function testBlankWhitespaceAndLegacyAbsentReasonsRemainExplicitFailures(): void
    {
        [$healthy, $comparisons] = $this->inputs();
        $normal = $this->model($healthy, $comparisons);
        self::assertSame(8, $this->card($normal, 'ctrip_ota:orders')['value']);
        self::assertSame(4, $this->card($normal, 'ctrip_ota:room_nights')['value']);
        foreach ([['not_verified', '', 'metric_truth_unverified'], ['not_verified', '   ', 'metric_truth_unverified'],
            ['not_verified', null, 'metric_truth_unverified'], ['missing', '', 'metric_value_missing'],
            ['partial', '', 'metric_truth_partial'], ['collection_failed', '', 'metric_truth_collection_failed']] as [$status, $reason, $code]) {
            $overview = $healthy;
            $overview['three_source_fact_layer']['sources']['ctrip_ota']['fact_statuses']['revenue'] = ['status' => $status];
            if ($reason !== null) $overview['three_source_fact_layer']['sources']['ctrip_ota']['fact_statuses']['revenue']['reason'] = $reason;
            if ($status === 'missing') $overview['three_source_fact_layer']['sources']['ctrip_ota']['facts']['revenue'] = null;
            $model = $this->model($overview, $comparisons);
            $card = $this->card($model, 'ctrip_ota:revenue');
            self::assertNull($card['value']);
            self::assertSame('—', $card['display']);
            self::assertSame($status, $card['status']);
            self::assertSame($code, $card['reasonCode']);
            self::assertNotSame('', trim($card['reasonText']));
            self::assertNotSame('数据已命中当前口径。', $card['reasonText']);
            self::assertSame('ctrip_ota', $card['sourceKey']);
            self::assertSame('2026-08-20', $card['businessDate']);
            self::assertStringContainsString('online_daily_data', implode('；', $card['evidenceLines']));
            self::assertStringContainsString($code, implode('；', $card['evidenceLines']));
            foreach (['orders', 'room_nights'] as $key) self::assertSame($this->card($normal, 'ctrip_ota:'.$key), $this->card($model, 'ctrip_ota:'.$key));
            self::assertIsArray(json_decode(json_encode($model, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
        }
    }

    public function testFailureResultPersistsExactlyAndCannotBePresentedAsSuccessfulRevenue(): void
    {
        [$overview, $comparisons] = $this->inputs();
        $overview['three_source_fact_layer']['sources']['ctrip_ota']['fact_statuses']['revenue'] = ['status' => 'not_verified', 'reason' => ''];
        $model = $this->model($overview, $comparisons);
        $saved = $this->save($overview, $comparisons, $model);
        $service = new RevenueDecisionSnapshotService();
        self::assertSame($model, $saved['visible_model']);
        self::assertSame($saved['visible_model'], $service->readExact($saved['id'], 10, 20, $overview, $comparisons)['visible_model']);
        self::assertNull($service->readExact($saved['id'], 11, 20, $overview, $comparisons));
        self::assertNull($service->readExact($saved['id'], 10, 21, $overview, $comparisons));
        $pending = $service->createOpportunityPendingApproval($saved['id'], 10, 20, 7, 'traffic_entry_shortage', $overview, $comparisons);
        self::assertSame('pending_approval', $pending['status']);
        self::assertSame(0, $pending['execution_task_count']);
        $before = $this->rows();
        self::assertSame($saved['id'], $this->save($overview, $comparisons)['id']);
        self::assertSame($pending['execution_intent']['id'], $service->createOpportunityPendingApproval($saved['id'], 10, 20, 7, 'traffic_entry_shortage', $overview, $comparisons)['execution_intent']['id']);
        foreach (['value' => 800, 'display' => '0', 'reasonCode' => '', 'reasonText' => '数据已命中当前口径。'] as $field => $value) {
            $tampered = $model;
            foreach (['sections', 'visibleSections'] as $name) foreach ($tampered[$name] as &$section) foreach ($section['cards'] as &$card) if ($card['key'] === 'ctrip_ota:revenue') $card[$field] = $value;
            unset($section, $card);
            try { $this->save($overview, $comparisons, $tampered); self::fail('A failed revenue claim was saved'); }
            catch (\InvalidArgumentException $e) { self::assertStringContainsString('ctrip_ota:revenue:'.$field, $e->getMessage()); }
            self::assertSame($before, $this->rows());
        }
        self::assertSame(0, $before[2]);
        self::assertSame(0, $before[3]);
    }

    public function testOldPendingDriftsAndRecoversWithSameIdentityWithoutWrites(): void
    {
        [$healthy, $comparisons] = $this->inputs();
        $saved = $this->save($healthy, $comparisons);
        $service = new RevenueDecisionSnapshotService();
        $pending = $service->createOpportunityPendingApproval($saved['id'], 10, 20, 7, 'traffic_entry_shortage', $healthy, $comparisons);
        $id = (int)$pending['execution_intent']['id'];
        $before = $this->rows();
        $failed = $healthy;
        $failed['three_source_fact_layer']['sources']['ctrip_ota']['fact_statuses']['revenue'] = ['status' => 'not_verified', 'reason' => ''];
        foreach ([$failed, $healthy, $failed, $healthy] as $overview) {
            $current = $overview === $healthy;
            $read = $service->readExact($saved['id'], 10, 20, $overview, $comparisons);
            self::assertSame($saved['content_digest'], $read['content_digest']);
            self::assertSame($saved['visible_model'], $read['visible_model']);
            self::assertSame($current ? 'matched_current' : 'stale_current_evidence', $read['evidence_identity_status']);
            $intent = (new OperationManagementService())->readExecutionIntent($id, [20]);
            self::assertSame('pending_approval', $intent['status']);
            $reader = static fn($tenant, $hotel, $date, $platform) => $date === '2026-08-20' ? $overview : $comparisons['previous_overview'];
            self::assertSame($current, (new RevenueCockpitIntentProvenanceService($reader))->isIntentCurrent($intent));
            $overview['cockpit_comparison_contexts'] = $comparisons;
            $manifest = (new RevenueCockpitApprovalService())->readFromOverview($overview, 10, 20, '2026-08-20', 'ctrip');
            self::assertSame($current ? 'verified' : 'drifted', $manifest['fact_integrity_status']);
            self::assertSame(!$current, $manifest['approval_blocked']);
            self::assertSame($id, $manifest['execution_intent']['id']);
            self::assertSame($before, $this->rows());
        }
    }

    public function testSpecificReasonsAndVerifiedZeroRemainCompatible(): void
    {
        [$overview, $comparisons] = $this->inputs();
        $overview['three_source_fact_layer']['sources']['ctrip_ota']['facts']['revenue'] = 0;
        $card = $this->card($this->model($overview, $comparisons), 'ctrip_ota:revenue');
        self::assertSame(0, $card['value']);
        self::assertSame('readback_verified', $card['status']);
        self::assertSame('', $card['reasonCode']);
        $overview['three_source_fact_layer']['sources']['ctrip_ota']['fact_statuses']['revenue'] = ['status' => 'not_verified', 'reason' => 'numeric_aggregate_nonfinite'];
        $card = $this->card($this->model($overview, $comparisons), 'ctrip_ota:revenue');
        self::assertNull($card['value']);
        self::assertSame('numeric_aggregate_nonfinite', $card['reasonCode']);
        self::assertStringContainsString('超出可表示范围', $card['reasonText']);
        $overview['three_source_fact_layer']['sources']['ctrip_ota']['facts']['revenue'] = 800;
        $overview['three_source_fact_layer']['sources']['ctrip_ota']['fact_statuses']['revenue'] = ['status' => 'missing', 'reason' => ''];
        $card = $this->card($this->model($overview, $comparisons), 'ctrip_ota:revenue');
        self::assertNull($card['value']);
        self::assertSame('metric_value_missing', $card['reasonCode']);
    }

    public function testVerifiedClaimsWithUnavailableValuesShowMissingInsteadOfVerifiedBadges(): void
    {
        [$healthy, $comparisons] = $this->inputs();
        $normal = $this->model($healthy, $comparisons);
        foreach (['readback_verified', 'derived_verified', 'verified'] as $status) {
            foreach (['null', 'absent', 'infinite', 'nan'] as $kind) {
                $overview = $healthy;
                $source =& $overview['three_source_fact_layer']['sources']['ctrip_ota'];
                $source['fact_statuses']['revenue'] = ['status' => $status, 'reason' => '   '];
                if ($kind === 'absent') unset($source['facts']['revenue']);
                // Wire-valid source values can still overflow conversion or be nonnumeric.
                else $source['facts']['revenue'] = match ($kind) { 'infinite' => '1e309', 'nan' => 'NaN', default => null };
                unset($source);
                $model = $this->model($overview, $comparisons);
                $card = $this->card($model, 'ctrip_ota:revenue');
                self::assertNull($card['value']);
                self::assertSame('—', $card['display']);
                self::assertSame('missing', $card['status'], $status.' '.$kind);
                self::assertSame('缺失', $card['statusLabel']);
                self::assertStringNotContainsString('emerald', $card['statusClass']);
                self::assertSame('metric_value_missing', $card['reasonCode']);
                self::assertSame('指标值缺失。', $card['reasonText']);
                self::assertSame('ctrip_ota', $card['sourceKey']);
                self::assertSame('2026-08-20', $card['businessDate']);
                self::assertStringContainsString('online_daily_data', implode('；', $card['evidenceLines']));
                foreach (['orders', 'room_nights'] as $key) self::assertSame($this->card($normal, 'ctrip_ota:'.$key), $this->card($model, 'ctrip_ota:'.$key));
                self::assertIsArray(json_decode(json_encode($model, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
            }
        }
        $overview = $healthy;
        $overview['three_source_fact_layer']['sources']['ctrip_ota']['facts']['revenue'] = null;
        $overview['three_source_fact_layer']['sources']['ctrip_ota']['fact_statuses']['revenue']['reason'] = 'numeric_aggregate_nonfinite';
        self::assertSame('numeric_aggregate_nonfinite', $this->card($this->model($overview, $comparisons), 'ctrip_ota:revenue')['reasonCode']);
        $overview = $healthy;
        $overview['cockpit_strict_evidence']['platforms']['ctrip']['metrics']['revenue']['strict_readback'] = false;
        $strictCard = $this->card($this->model($overview, $comparisons), 'ctrip_ota:revenue');
        self::assertSame('not_verified', $strictCard['status']);
        self::assertSame('cockpit_strict_metric_readback_missing', $strictCard['reasonCode']);
    }

    public function testVerifiedMissingModelPersistsAndRejectsForgedSuccessBadges(): void
    {
        [$overview, $comparisons] = $this->inputs();
        unset($overview['three_source_fact_layer']['sources']['ctrip_ota']['facts']['revenue']);
        $overview['three_source_fact_layer']['sources']['ctrip_ota']['fact_statuses']['revenue']['reason'] = '';
        $model = $this->model($overview, $comparisons);
        self::assertSame('missing', $this->card($model, 'ctrip_ota:revenue')['status']);
        $saved = $this->save($overview, $comparisons, $model);
        $service = new RevenueDecisionSnapshotService();
        self::assertSame($model, $saved['visible_model']);
        self::assertSame($saved['visible_model'], $service->readExact($saved['id'], 10, 20, $overview, $comparisons)['visible_model']);
        self::assertNull($service->readExact($saved['id'], 11, 20, $overview, $comparisons));
        self::assertNull($service->readExact($saved['id'], 10, 21, $overview, $comparisons));
        $pending = $service->createOpportunityPendingApproval($saved['id'], 10, 20, 7, 'traffic_entry_shortage', $overview, $comparisons);
        self::assertSame('pending_approval', $pending['status']);
        $before = $this->rows();
        self::assertSame($saved['id'], $this->save($overview, $comparisons)['id']);
        self::assertSame($pending['execution_intent']['id'], $service->createOpportunityPendingApproval($saved['id'], 10, 20, 7, 'traffic_entry_shortage', $overview, $comparisons)['execution_intent']['id']);
        foreach (['value' => 800, 'display' => '0', 'status' => 'readback_verified', 'statusLabel' => '已严格回读',
            'statusClass' => 'border-emerald-200 bg-emerald-50 text-emerald-700'] as $field => $value) {
            $tampered = $model;
            foreach (['sections', 'visibleSections'] as $name) foreach ($tampered[$name] as &$section) foreach ($section['cards'] as &$card) if ($card['key'] === 'ctrip_ota:revenue') $card[$field] = $value;
            unset($section, $card);
            try { $this->save($overview, $comparisons, $tampered); self::fail('A verified missing value was saved as success'); }
            catch (\InvalidArgumentException $e) { self::assertStringContainsString('ctrip_ota:revenue:'.$field, $e->getMessage()); }
            self::assertSame($before, $this->rows());
        }
        self::assertSame(0, $before[2]);
        self::assertSame(0, $before[3]);
    }

    public function testHealthyPendingDriftsForMissingValueAndRecoversWithoutWrites(): void
    {
        [$healthy, $comparisons] = $this->inputs();
        $service = new RevenueDecisionSnapshotService();
        $saved = $this->save($healthy, $comparisons);
        $pending = $service->createOpportunityPendingApproval($saved['id'], 10, 20, 7, 'traffic_entry_shortage', $healthy, $comparisons);
        $id = (int)$pending['execution_intent']['id'];
        $before = $this->rows();
        $missing = $healthy;
        $missing['three_source_fact_layer']['sources']['ctrip_ota']['facts']['revenue'] = null;
        try { $service->createOpportunityPendingApproval($saved['id'], 10, 20, 7, 'traffic_entry_shortage', $missing, $comparisons); self::fail('Stale revenue snapshot accepted'); }
        catch (\RuntimeException $e) { self::assertSame('revenue_decision_snapshot_evidence_stale', $e->getMessage()); }
        self::assertSame($before, $this->rows());
        foreach ([$missing, $healthy, $missing, $healthy] as $overview) {
            $current = $overview === $healthy;
            $read = $service->readExact($saved['id'], 10, 20, $overview, $comparisons);
            self::assertSame($saved['content_digest'], $read['content_digest']);
            self::assertSame($saved['visible_model'], $read['visible_model']);
            self::assertSame($current ? 'matched_current' : 'stale_current_evidence', $read['evidence_identity_status']);
            $intent = (new OperationManagementService())->readExecutionIntent($id, [20]);
            $reader = static fn($tenant, $hotel, $date, $platform) => $date === '2026-08-20' ? $overview : $comparisons['previous_overview'];
            self::assertSame($current, (new RevenueCockpitIntentProvenanceService($reader))->isIntentCurrent($intent));
            $overview['cockpit_comparison_contexts'] = $comparisons;
            $manifest = (new RevenueCockpitApprovalService())->readFromOverview($overview, 10, 20, '2026-08-20', 'ctrip');
            self::assertSame($current ? 'verified' : 'drifted', $manifest['fact_integrity_status']);
            self::assertSame(!$current, $manifest['approval_blocked']);
            self::assertSame($id, $manifest['execution_intent']['id']);
            self::assertSame($before, $this->rows());
        }
    }
}
