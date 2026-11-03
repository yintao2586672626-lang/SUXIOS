<?php
declare(strict_types=1);
namespace Tests;

use app\controller\Base;
use app\controller\RevenueAi;
use app\model\User;
use app\service\RevenueDecisionSnapshotService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RevenueDecisionSnapshotHistoryTest extends TestCase
{
    private RevenueCockpitSnapshotGenerationTest $fixture;
    protected function setUp(): void
    {
        $this->fixture = new RevenueCockpitSnapshotGenerationTest('testNonBaselineSourceChangeCreatesCurrentVersionAndRetriesReuseIt');
        $this->fixture('setUp');
    }
    protected function tearDown(): void { $this->fixture('tearDown'); }
    private function fixture(string $method, mixed ...$args): mixed
    { return (new \ReflectionMethod($this->fixture, $method))->invoke($this->fixture, ...$args); }
    private function seed(): array
    {
        return array_map(fn($value) => $this->fixture('snapshot', $this->fixture('overview', $value)), [600, 601, 602]);
    }
    private function read(array $params, int $tenant = 10, bool $allowed = true, bool $superAdmin = false, bool $scoped = true): \think\Response
    {
        $actor = new class(['id' => 7, 'tenant_id' => $tenant]) extends User {
            public bool $allowed = true;
            public bool $superAdmin = false;
            public function getPermittedHotelIds(): array { return [20]; }
            public function isSuperAdmin(): bool { return $this->superAdmin; }
            public function hasHotelPermission(int $hotelId, string $permission): bool { return $this->allowed && $hotelId === 20; }
        };
        $actor->allowed = $allowed;
        $actor->superAdmin = $superAdmin;
        $controller = new RevenueAi(app());
        $defaults = ['history' => '1', 'hotel_id' => '20'];
        if ($scoped) $defaults += ['business_date' => '2026-08-20', 'platform' => 'meituan'];
        $request = (new \think\Request())->withGet(array_replace($defaults, $params))->withServer(['REQUEST_METHOD' => 'GET']);
        $request->setMethod('GET');
        foreach (['request' => $request, 'currentUser' => $actor] as $property => $value) {
            (new \ReflectionProperty(Base::class, $property))->setValue($controller, $value);
        }
        return $controller->readCockpitDecisionSnapshot();
    }
    private function savedScope(string $date, string $platform): array
    {
        $overview = json_decode(str_replace('2026-08-20', $date,
            json_encode($this->fixture('overview', 603), JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
        $overview['cockpit_strict_evidence'] = (new \app\service\RevenueCockpitStrictEvidenceService())->build(
            $overview, 10, 20, $date, $platform, $overview['dual_ota_field_closure']);
        $comparisons = ['previous_date' => '', 'same_weekday_date' => '', 'previous_overview' => [],
            'same_weekday_overview' => [], 'selected_platform_label' => $platform,
            'latest_verified_date' => $date, 'scope_notice' => 'local_synthetic_fixture_only'];
        $context = (new \app\service\RevenueCockpitApprovalService())->evidenceContext($overview, 10, 20, $date, $platform);
        $model = (new \app\service\RevenueDecisionViewModelAttestationService())->issue(
            $overview, $comparisons, $context, 10, 20, $date, $platform);
        return (new RevenueDecisionSnapshotService())->saveFromOverview($overview, 10, 20, $date, $platform, 7, $model, $comparisons);
    }
    public function testHotelHistoryDiscoversSavedDatesAndPlatformsWithoutLiveSourceOptions(): void
    {
        $this->seed();
        $older = $this->savedScope('2026-08-19', 'meituan');
        $other = $this->savedScope('2026-08-18', 'ctrip');
        $before = Db::name('revenue_decision_snapshots')->order('id')->select()->toArray();
        $response = $this->read(['hotel_history' => '1', 'limit' => '2'], scoped: false);
        self::assertSame(200, $response->getCode(), json_encode($response->getData()));
        $page = $response->getData()['data'];
        self::assertSame('saved_hotel_history', $page['scope_mode']);
        self::assertSame(['tenant_id' => 10, 'system_hotel_id' => 20], $page['scope']);
        self::assertSame([$other['id'], $older['id']], array_column($page['items'], 'id'));
        self::assertSame(['2026-08-18', '2026-08-19'], array_column($page['items'], 'business_date'));
        self::assertSame(['ctrip', 'meituan'], array_column($page['items'], 'platform'));
        self::assertTrue($page['read_only']); self::assertFalse($page['current_evidence_verified']);
        $next = $this->read(['hotel_history' => '1', 'before_id' => (string)$page['next_before_id']], scoped: false)->getData()['data'];
        self::assertSame([3, 2, 1], array_column($next['items'], 'id'));
        self::assertNull($next['next_before_id']);
        $exact = $this->read(['snapshot_id' => (string)$other['id'], 'business_date' => '2026-08-18', 'platform' => 'ctrip'])->getData()['data']['snapshot'];
        self::assertSame($other['visible_model'], $exact['visible_model']);
        self::assertSame($other['content_digest'], $exact['content_digest']);
        self::assertSame('not_checked', $exact['evidence_identity_status']);
        self::assertSame($before, Db::name('revenue_decision_snapshots')->order('id')->select()->toArray());
        self::assertSame(0, Db::name('operation_execution_intents')->count());
    }
    public function testHotelHistoryRequiresExplicitModeAndPreservesScopeAuthorization(): void
    {
        $this->seed();
        foreach ([['hotel_history' => '0'], ['hotel_history' => ['1']], ['hotel_history' => '1', 'snapshot_id' => '1'],
            ['hotel_history' => '1', 'platform' => 'ctrip'], ['hotel_history' => '1', 'business_date' => '2026-08-18'],
            ['hotel_history' => '1', 'limit' => '21'], ['hotel_history' => '1', 'before_id' => '0'], []] as $params) {
            self::assertSame(422, $this->read($params, scoped: false)->getCode(), json_encode($params));
        }
        self::assertSame(403, $this->read(['hotel_history' => '1'], tenant: 11, scoped: false)->getCode());
        self::assertSame(403, $this->read(['hotel_history' => '1'], allowed: false, scoped: false)->getCode());
        self::assertSame(403, $this->read(['hotel_history' => '1', 'hotel_id' => '21'], scoped: false)->getCode());
        $service = new RevenueDecisionSnapshotService();
        self::assertSame([], $service->readHotelHistory(11, 20)['items']);
        self::assertSame([], $service->readHotelHistory(10, 21)['items']);
        self::assertSame([3, 2, 1], array_column($this->read([])->getData()['data']['items'], 'id'));
    }
    public function testHotelHistoryRejectsTamperedSavedVersionWithoutEmptySuccess(): void
    {
        $this->seed(); $other = $this->savedScope('2026-08-18', 'ctrip');
        Db::name('revenue_decision_snapshots')->where('id', $other['id'])->update(['content_digest' => str_repeat('0', 64)]);
        $before = Db::name('revenue_decision_snapshots')->order('id')->select()->toArray();
        self::assertSame(409, $this->read(['hotel_history' => '1'], scoped: false)->getCode());
        self::assertSame(200, $this->read([])->getCode());
        self::assertSame($before, Db::name('revenue_decision_snapshots')->order('id')->select()->toArray());
    }
    public function testHistoryPagesAndExactContentAreImmutableWithoutCurrentSources(): void
    {
        $saved = $this->seed();
        $before = Db::name('revenue_decision_snapshots')->order('id')->select()->toArray();
        $first = $this->read(['limit' => '2']);
        self::assertSame(200, $first->getCode(), json_encode($first->getData()));
        $page = $first->getData()['data'];
        self::assertSame([3, 2], array_column($page['items'], 'id'));
        self::assertSame(2, $page['next_before_id']);
        self::assertTrue($page['read_only']);
        self::assertFalse($page['current_evidence_verified']);
        self::assertSame('not_checked', $page['items'][0]['evidence_identity_status']);
        self::assertArrayNotHasKey('source_refs', $page['items'][0]);
        self::assertArrayNotHasKey('visible_model', $page['items'][0]);
        $last = $this->read(['before_id' => '2', 'limit' => '2'])->getData()['data'];
        self::assertSame([1], array_column($last['items'], 'id'));
        self::assertNull($last['next_before_id']);
        $exact = $this->read(['snapshot_id' => '1'])->getData()['data'];
        self::assertTrue($exact['found']);
        self::assertSame($saved[0]['visible_model'], $exact['snapshot']['visible_model']);
        self::assertSame($saved[0]['content_digest'], $exact['snapshot']['content_digest']);
        self::assertSame('not_checked', $exact['snapshot']['evidence_identity_status']);
        self::assertSame($before, Db::name('revenue_decision_snapshots')->order('id')->select()->toArray());
        self::assertSame(0, Db::name('operation_execution_intents')->count());
    }
    public function testHistoryRejectsUnauthorizedScopeAndInvalidExplicitSelectors(): void
    {
        $this->seed();
        foreach ([['snapshot_id' => '0'], ['snapshot_id' => '-1'], ['snapshot_id' => '1junk'],
            ['snapshot_id' => '9999999999999999999999999'], ['limit' => '21'], ['before_id' => '0'],
            ['business_date' => '2026-02-30'], ['platform' => 'pms']] as $params) {
            self::assertSame(422, $this->read($params)->getCode(), json_encode($params));
        }
        self::assertSame(403, $this->read(['hotel_id' => '21'])->getCode());
        self::assertSame(403, $this->read([], 11)->getCode());
        self::assertSame(403, $this->read([], 10, false)->getCode());
        foreach ([['snapshot_id' => '1', 'business_date' => '2026-08-21'],
            ['snapshot_id' => '1', 'platform' => 'ctrip']] as $params) {
            self::assertSame(409, $this->read($params)->getCode());
        }
        self::assertFalse($this->read(['snapshot_id' => '999'])->getData()['data']['found']);
        $service = new RevenueDecisionSnapshotService();
        foreach ([[11, 20, '2026-08-20', 'meituan'], [10, 21, '2026-08-20', 'meituan'],
            [10, 20, '2026-08-21', 'meituan'], [10, 20, '2026-08-20', 'ctrip']] as $scope) {
            self::assertSame([], $service->readHistory(...$scope)['items']);
        }
    }
    public function testHistoryDigestFailureCannotBecomeEmptyOrCurrentSuccess(): void
    {
        $this->seed();
        Db::name('revenue_decision_snapshots')->where('id', 1)->update(['content_digest' => str_repeat('0', 64)]);
        self::assertSame(409, $this->read(['snapshot_id' => '1'])->getCode());
        self::assertSame(409, $this->read([])->getCode());
        self::assertSame(str_repeat('0', 64), Db::name('revenue_decision_snapshots')->where('id', 1)->value('content_digest'));
    }
    public function testSuperAdminHistoryUsesSelectedHotelsTenantRatherThanActorOrClientTenant(): void
    {
        Db::name('hotels')->insert(['id' => 21, 'tenant_id' => 11]);
        $response = $this->read(['hotel_id' => '21', 'tenant_id' => '999'], 10, true, true);
        self::assertSame(200, $response->getCode());
        self::assertSame(11, $response->getData()['data']['scope']['tenant_id']);
        self::assertSame(21, $response->getData()['data']['scope']['system_hotel_id']);
        self::assertSame([], $response->getData()['data']['items']);
    }
}
