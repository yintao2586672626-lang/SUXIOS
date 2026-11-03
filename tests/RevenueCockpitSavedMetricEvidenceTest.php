<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenueCockpitApprovalService;
use app\service\RevenueCockpitStrictEvidenceService;
use app\service\RevenueDecisionSnapshotService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RevenueCockpitSavedMetricEvidenceTest extends TestCase
{
    private const REASON = '已保存基准指标“详情曝光”缺少同酒店、平台和营业日的严格回读证据；补齐该指标证据后只读刷新。';
    private RevenueCockpitSnapshotGenerationTest $fixture;

    protected function setUp(): void
    {
        // Reuse only the existing private SQLite setup and synthetic producers.
        $this->fixture = new RevenueCockpitSnapshotGenerationTest('testNonBaselineSourceChangeCreatesCurrentVersionAndRetriesReuseIt');
        $this->fixture('setUp');
    }

    protected function tearDown(): void
    {
        $this->fixture('tearDown');
    }

    public function testLostSavedBaselineProofKeepsHistoryAndSpecificRecoveryReason(): void
    {
        [$overview, $snapshot, $pending] = $this->twoVersions();
        $before = $this->rows();
        $lost = $this->withoutBaselineProof($overview);
        $read = (new RevenueCockpitApprovalService())->readFromOverview($lost, 10, 20, '2026-08-20', 'meituan');
        self::assertTrue($read['approval_blocked']);
        self::assertSame($pending['execution_intent']['id'], $read['execution_intent']['id']);
        self::assertCount(2, $read['execution_intents']);
        foreach ($read['execution_intents'] as $intent) {
            self::assertSame('drifted', $intent['fact_integrity_status']);
            self::assertSame(self::REASON, $intent['fact_integrity_failure_reason']);
            $card = $intent['target_value']['action_card'];
            self::assertSame('detail_exposure', $card['metric_contract']['metric_key']);
            self::assertSame(400.0, (float)$card['metric_contract']['baseline_window']['value']);
        }
        $historical = (new RevenueDecisionSnapshotService())->readExact((int)$snapshot['id'], 10, 20, $lost);
        self::assertSame('stale_current_evidence', $historical['evidence_identity_status']);
        self::assertSame($snapshot['content_digest'], $historical['content_digest']);
        self::assertSame($before, $this->rows());
    }

    public function testSavedMetricCannotFallbackWhileNewActionsRetainMetricSelection(): void
    {
        $overview = $this->withoutBaselineProof($this->fixture('overview', 601));
        $service = new RevenueCockpitApprovalService();
        $context = $service->evidenceContext($overview, 10, 20, '2026-08-20', 'meituan');
        $context['action_context'] = ['opportunity_key' => 'traffic_entry_shortage'];
        $method = new \ReflectionMethod($service, 'observationMetricContext');
        $newAction = $method->invoke($service, $overview, $context);
        self::assertSame('orders', $newAction['metric_key']);
        $context['forced_metric_key'] = 'detail_exposure';
        try {
            $method->invoke($service, $overview, $context);
            self::fail('Saved baseline was replaced with an unrelated metric');
        } catch (\RuntimeException $exception) {
            self::assertSame(self::REASON, $exception->getMessage());
            self::assertSame(422, $exception->getCode());
        }
        self::assertTrue($overview['cockpit_strict_evidence']['platforms']['meituan']['metrics']['orders']['strict_readback']);
    }

    public function testOriginalProofRestorationReusesExactSavedVersions(): void
    {
        [$overview, $snapshot, $pending] = $this->twoVersions();
        $before = $this->rows();
        $service = new RevenueCockpitApprovalService();
        $service->readFromOverview($this->withoutBaselineProof($overview), 10, 20, '2026-08-20', 'meituan');
        $restored = $service->readFromOverview($overview, 10, 20, '2026-08-20', 'meituan');
        self::assertFalse($restored['approval_blocked']);
        self::assertSame($pending['execution_intent']['id'], $restored['execution_intent']['id']);
        self::assertSame('verified', $restored['fact_integrity_status']);
        $saved = $this->fixture('snapshot', $overview);
        self::assertSame($snapshot['id'], $saved['id']);
        $reused = $this->fixture('pending', $saved, $overview);
        self::assertTrue($reused['reused_existing_intent']);
        self::assertSame($pending['execution_intent']['id'], $reused['execution_intent']['id']);
        self::assertSame($pending['execution_intent']['target_value']['action_card'], $reused['execution_intent']['target_value']['action_card']);
        self::assertSame($before, $this->rows());
        self::assertSame(0, Db::name('operation_execution_tasks')->count());
        self::assertSame(0, Db::name('operation_execution_evidence')->count());
    }

    private function twoVersions(): array
    {
        $old = $this->fixture('overview', 600);
        $this->fixture('pending', $this->fixture('snapshot', $old), $old);
        $current = $this->fixture('overview', 601);
        $snapshot = $this->fixture('snapshot', $current);
        return [$current, $snapshot, $this->fixture('pending', $snapshot, $current)];
    }

    private function withoutBaselineProof(array $overview): array
    {
        foreach ($overview['dual_ota_field_closure']['platforms']['meituan']['fields'] as &$field) {
            if ($field['metric_key'] === 'detail_exposure') {
                $field['revenue_analysis_consumable'] = false;
                $field['strict_final_gate'] = false;
                $field['readback_status'] = 'unverified';
                $field['status'] = 'unverified';
            }
        }
        unset($field);
        $overview['cockpit_strict_evidence'] = (new RevenueCockpitStrictEvidenceService())->build(
            $overview, 10, 20, '2026-08-20', 'meituan', $overview['dual_ota_field_closure']
        );
        return $overview;
    }

    private function fixture(string $method, mixed ...$arguments): mixed
    {
        return (new \ReflectionMethod($this->fixture, $method))->invoke($this->fixture, ...$arguments);
    }

    private function rows(): array
    {
        return [Db::name('operation_execution_intents')->order('id')->select()->toArray(),
            Db::name('revenue_decision_snapshots')->order('id')->select()->toArray()];
    }
}
