<?php
declare(strict_types=1);

namespace Tests;

use app\controller\OperatingFinance;
use app\middleware\Auth;
use app\service\OperatingEvidenceSnapshotStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Tests\Support\InvestmentScenarioFixture as Fixture;
use think\App;
use think\exception\RouteNotFoundException;
use think\facade\Db;
use think\Request;
use think\Response;
use think\route\Dispatch;
use think\route\dispatch\Controller;
use think\route\Rule;

require_once __DIR__ . '/InvestmentPaybackRoutingTest.php';
require_once __DIR__ . '/Support/InvestmentScenarioFixture.php';

/** Exact production routes and direct controllers, with synthetic identities and isolated SQLite only. */
final class OperatingEvidenceRoutingTest extends TestCase
{
    private InvestmentPaybackRoutingProbe $router;
    private ?string $databasePath = null;

    protected function setUp(): void
    {
        $app = new App(dirname(__DIR__));
        $app->setRuntimePath(sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'suxios-operating-evidence-route-probe-' . bin2hex(random_bytes(6)) . DIRECTORY_SEPARATOR);
        $app->config->set(require dirname(__DIR__) . '/config/route.php', 'route');
        $this->router = new InvestmentPaybackRoutingProbe($app);
        $app->instance('route', $this->router);
        require dirname(__DIR__) . '/route/app.php';
    }

    protected function tearDown(): void
    {
        if ($this->databasePath !== null) {
            Db::connect('investment_scenario_test')->close();
            @unlink($this->databasePath);
        }
    }

    public static function evidenceRoutes(): array
    {
        return [
            'overview' => ['GET', '/api/operating-finance/evidence/overview', 'evidenceOverview', []],
            'preview' => ['POST', '/api/operating-finance/evidence/preview', 'previewEvidence', []],
            'save snapshot' => ['POST', '/api/operating-finance/evidence/snapshots', 'saveEvidence', []],
            'read snapshot' => ['GET', '/api/operating-finance/evidence/snapshots/37', 'readEvidence', ['id' => '37']],
        ];
    }

    #[DataProvider('evidenceRoutes')]
    public function testFourRoutesResolveExactlyAndRequireAuth(string $method, string $path, string $action, array $parameters): void
    {
        $dispatch = $this->router->resolve($this->request($method, $path));
        self::assertInstanceOf(Controller::class, $dispatch);
        self::assertSame(['OperatingFinance', $action], $dispatch->getDispatch());
        self::assertSame($parameters, $dispatch->getParam());
        self::assertTrue((new ReflectionMethod(OperatingFinance::class, $action))->isPublic());
        $rule = $this->rule($dispatch);
        self::assertTrue($rule->getOption('complete_match'), $path);
        self::assertContains(Auth::class, $this->middlewares($rule));
        self::assertNotContains(Auth::class, $rule->getOption('without_middleware', []));
    }

    #[DataProvider('evidenceRoutes')]
    public function testMissingTokenIsRejectedBeforeTheBusinessAction(string $method, string $path, string $action, array $parameters): void
    {
        $request = $this->request($method, $path);
        $dispatch = $this->router->resolve($request);
        self::assertInstanceOf(Controller::class, $dispatch);
        self::assertSame(['OperatingFinance', $action], $dispatch->getDispatch());
        self::assertSame($parameters, $dispatch->getParam());
        self::assertContains(Auth::class, $this->middlewares($this->rule($dispatch)));
        $response = (new Auth())->handle($request, static function (Request $request): Response {
            self::fail('Anonymous requests must not enter an operating evidence action.');
        });
        $payload = $response->getData();
        self::assertSame(401, $response->getCode());
        self::assertSame(401, $payload['code']);
        self::assertSame('missing_token', $payload['data']['reason']);
        self::assertSame('operating-evidence-route-test', $payload['request_id']);
    }

    #[DataProvider('evidenceRoutes')]
    public function testDirectControllerAlsoRequiresLogin(string $method, string $path, string $action, array $parameters): void
    {
        $response = $this->call($action, $this->input(), null, $parameters);
        self::assertSame(401, $response->getCode(), $path);
        self::assertSame(401, $response->getData()['code']);
        self::assertNull($response->getData()['data']);
    }

    public static function invalidPaths(): array
    {
        return [
            'overview extra child' => ['GET', '/api/operating-finance/evidence/overview/extra'],
            'overview wrong verb' => ['POST', '/api/operating-finance/evidence/overview'],
            'preview extra child' => ['POST', '/api/operating-finance/evidence/preview/extra'],
            'preview wrong verb' => ['GET', '/api/operating-finance/evidence/preview'],
            'save extra child' => ['POST', '/api/operating-finance/evidence/snapshots/extra'],
            'save wrong verb' => ['PATCH', '/api/operating-finance/evidence/snapshots'],
            'read extra child' => ['GET', '/api/operating-finance/evidence/snapshots/37/extra'],
            'read wrong verb' => ['POST', '/api/operating-finance/evidence/snapshots/37'],
        ];
    }

    #[DataProvider('invalidPaths')]
    public function testUnknownChildrenAndWrongMethodsDoNotFallBackToAnEvidenceAction(string $method, string $path): void
    {
        $this->expectException(RouteNotFoundException::class);
        $this->router->resolve($this->request($method, $path));
    }

    public function testViewPermissionAllowsPreviewButCannotSave(): void
    {
        $this->database();
        $user = $this->user([80], ['operation.view']);
        $before = $this->storedRows();
        $preview = $this->call('previewEvidence', $this->input(), $user);
        self::assertSame(200, $preview->getCode());
        self::assertSame('calculated', $preview->getData()['data']['status']);
        $denied = $this->call('saveEvidence', $this->input(), $user);
        self::assertSame(403, $denied->getCode());
        self::assertSame(403, $denied->getData()['code']);
        self::assertSame($before, $this->storedRows());
    }

    #[DataProvider('evidenceRoutes')]
    public function testExecutionCapabilityDoesNotBypassThePermittedHotelList(string $method, string $path, string $action, array $parameters): void
    {
        $this->database();
        // This identity says yes to both capabilities for every hotel, but its explicit list only contains 80.
        $before = $this->storedRows();
        $response = $this->call($action, $this->input(['hotel_id' => 81]), $this->user([80]), $parameters);
        self::assertSame(403, $response->getCode(), $path);
        self::assertSame(403, $response->getData()['code']);
        self::assertSame($before, $this->storedRows());
    }

    #[DataProvider('evidenceRoutes')]
    public function testForeignTenantIsRejectedEvenWithAnOverbroadPermittedHotelList(string $method, string $path, string $action, array $parameters): void
    {
        $this->database();
        $store = new OperatingEvidenceSnapshotStore();
        $scope = $store->scope(20, [90], 90, '2026-09', 'whole_hotel', 'consumables_actual');
        $foreign = $store->save($scope, ['synthetic' => true], 'foreign-tenant-snapshot', 9);
        if ($action === 'readEvidence') $parameters['id'] = $foreign['snapshot_id'];
        $before = $this->storedRows();
        $response = $this->call($action, $this->input(['hotel_id' => 90, 'tenant_id' => 20]), $this->user([80, 90]), $parameters);
        self::assertSame(403, $response->getCode(), $path);
        self::assertSame(403, $response->getData()['code']);
        self::assertSame($before, $this->storedRows());
    }

    #[DataProvider('evidenceRoutes')]
    public function testAuthorizedSuperAdminUsesSelectedHotelsTenantForEveryEvidenceAction(string $method, string $path, string $action, array $parameters): void
    {
        $this->database();
        $store = new OperatingEvidenceSnapshotStore();
        $scope = $store->scope(20, [90], 90, '2026-09', 'whole_hotel', 'consumables_actual');
        $foreign = $store->save($scope, ['synthetic' => true], 'super-admin-read-fixture', 9);
        if ($action === 'readEvidence') $parameters['id'] = $foreign['snapshot_id'];
        $response = $this->call($action, $this->input(['hotel_id' => 90, 'tenant_id' => 999]), $this->user([80, 90], ['operation.view', 'operation.execute'], true), $parameters);
        self::assertSame(200, $response->getCode(), $path . ': ' . $response->getContent());
        $data = $response->getData()['data'];
        self::assertSame(20, $data['scope']['tenant_id']);
        self::assertSame(90, $data['scope']['hotel_id']);
        if ($action === 'saveEvidence') {
            self::assertTrue($data['readback_verified']);
            $row = Db::name(OperatingEvidenceSnapshotStore::TABLE)->where('id', $data['snapshot_id'])->find();
            self::assertSame(20, (int)$row['tenant_id']);
            self::assertSame(90, (int)$row['hotel_id']);
        }
    }

    public function testSuperAdminFlagDoesNotBypassCentralHotelOrCapabilityAuthorization(): void
    {
        $this->database();
        $before = $this->storedRows();
        $withoutHotel = $this->call('saveEvidence', $this->input(['hotel_id' => 90]), $this->user([80], ['operation.view', 'operation.execute'], true));
        self::assertSame(403, $withoutHotel->getCode());
        $withoutExecution = $this->call('saveEvidence', $this->input(['hotel_id' => 90]), $this->user([80, 90], ['operation.view'], true));
        self::assertSame(403, $withoutExecution->getCode());
        self::assertSame($before, $this->storedRows());
    }

    #[DataProvider('evidenceRoutes')]
    public function testInvalidAccountingMonthIsRejectedBeforeReadOrSave(string $method, string $path, string $action, array $parameters): void
    {
        $this->database();
        $before = $this->storedRows();
        foreach (['2026-13', '2026-9', '2026-09-01', ''] as $month) {
            $response = $this->call($action, $this->input(['period_month' => $month]), $this->user(), $parameters);
            self::assertSame(422, $response->getCode(), $path . ' month=' . $month);
            self::assertSame('period_month_invalid', $response->getData()['message']);
            self::assertSame($before, $this->storedRows());
        }
    }

    public static function previewKinds(): array
    {
        return ['actual inventory' => ['consumables_actual'], 'channel economics' => ['channel_economics']];
    }

    #[DataProvider('previewKinds')]
    public function testPreviewHasNoDatabasePersistenceSideEffect(string $kind): void
    {
        $this->database();
        $input = $this->input(['kind' => $kind]);
        if ($kind === 'channel_economics') {
            $input['platform'] = 'ctrip';
            $input['inputs'] = ['net_revenue' => 1000, 'advertising_spend' => 100, 'attributed_order_amount' => 400,
                'effective_order_amount' => 1200, 'refund_amount' => 50, 'attribution_basis' => 'synthetic-same-window',
                'advertising_included_in_net_revenue' => false, 'advertising_in_direct_costs' => false,
                'cost_coverage_complete' => true, 'operator_attested' => true, 'source_refs' => ['synthetic-monthly-ledger'],
                'costs' => [['label' => '合成履约成本', 'amount' => 200, 'source_ref' => 'synthetic-cost', 'included_in_net_revenue' => false]]];
        }
        $before = $this->storedRows();
        $response = $this->call('previewEvidence', $input, $this->user());
        self::assertSame(200, $response->getCode(), $response->getContent());
        $data = $response->getData()['data'];
        self::assertSame(10, $data['scope']['tenant_id']);
        self::assertSame(80, $data['scope']['hotel_id']);
        self::assertSame($kind, $data['scope']['kind']);
        self::assertFalse($data['readback_verified']);
        self::assertArrayNotHasKey('snapshot_id', $data);
        self::assertSame($before, $this->storedRows());
    }

    public function testSavedSnapshotReadbackCannotUseAnotherSameTenantHotel(): void
    {
        $this->database();
        $saved = $this->call('saveEvidence', $this->input(), $this->user())->getData();
        self::assertSame(200, $saved['code']);
        self::assertTrue($saved['data']['readback_verified']);
        $id = $saved['data']['snapshot_id'];
        $read = $this->call('readEvidence', $this->input(), $this->user(), ['id' => $id]);
        self::assertSame(200, $read->getCode());
        self::assertSame($saved['data'], $read->getData()['data'] + ['idempotent' => false]);
        $before = $this->storedRows();
        $other = $this->call('readEvidence', $this->input(['hotel_id' => 81]), $this->user([80, 81]), ['id' => $id]);
        self::assertSame(404, $other->getCode());
        self::assertSame('operating_evidence_not_found', $other->getData()['message']);
        self::assertSame($before, $this->storedRows());
    }

    public static function malformedHotelIdentities(): array
    {
        $cases = [];
        foreach (self::evidenceRoutes() as $route => [, , $action, $arguments]) {
            foreach (['fractional' => 80.9, 'suffix' => '80wrong', 'boolean' => true, 'array' => [80]] as $label => $hotelId) {
                $cases[$route . ' ' . $label] = [$action, $arguments, $hotelId];
            }
        }
        return $cases;
    }

    #[DataProvider('malformedHotelIdentities')]
    public function testMalformedHotelIdentityIsRejectedWithoutCoercion(string $action, array $arguments, mixed $hotelId): void
    {
        $this->database();
        $before = $this->storedRows();
        $response = $this->call($action, $this->input(['hotel_id' => $hotelId]), $this->user(), $arguments);
        self::assertSame(422, $response->getCode(), $response->getContent());
        self::assertNull($response->getData()['data']);
        self::assertSame($before, $this->storedRows());
    }

    public function testIntegerQueryHotelIdentityStillSavesAndReadsBackExactly(): void
    {
        $this->database();
        $input = $this->input(['hotel_id' => '80']);
        $saved = $this->call('saveEvidence', $input, $this->user());
        self::assertSame(200, $saved->getCode(), $saved->getContent());
        $snapshot = $saved->getData()['data'];
        self::assertSame(80, $snapshot['scope']['hotel_id']);
        $read = $this->call('readEvidence', $input, $this->user(), ['id' => $snapshot['snapshot_id']]);
        self::assertSame(200, $read->getCode());
        self::assertSame($snapshot['content_digest'], $read->getData()['data']['content_digest']);
    }

    public function testMalformedIdentityDoesNotBypassLogin(): void
    {
        $response = $this->call('saveEvidence', $this->input(['hotel_id' => '80wrong']), null);
        self::assertSame(401, $response->getCode());
        self::assertNull($response->getData()['data']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hundredItemTextCases')]
    public function testAcceptedHundredItemPreviewSavesAndReadsExactly(string $character, bool $partial): void
    {
        $this->database();
        $request = $this->input();
        $base = $request['inputs']['items'][0];
        $request['inputs']['items'] = [];
        for ($i = 0; $i < 100; ++$i) {
            $item = array_replace($base, ['id'=>str_repeat($character, 97).sprintf('%03d', $i),
                'name'=>str_repeat($character, 160), 'source_ref'=>str_repeat($character, 500)]);
            if ($partial) foreach (['opening_quantity','purchased_quantity','transfer_in_quantity','closing_quantity','transfer_out_quantity','returned_quantity','written_off_quantity','unit_price'] as $key) $item[$key] = null;
            $request['inputs']['items'][] = $item;
        }
        $preview = $this->call('previewEvidence', $request, $this->user());
        self::assertSame(200, $preview->getCode(), $preview->getContent());
        $saved = $this->call('saveEvidence', $request, $this->user());
        self::assertSame(200, $saved->getCode(), $saved->getContent());
        $data = $saved->getData()['data'];
        $read = $this->call('readEvidence', $request, $this->user(), ['id'=>$data['snapshot_id']]);
        self::assertSame(200, $read->getCode(), $read->getContent());
        self::assertEquals($preview->getData()['data']['result'], $read->getData()['data']['result']);
        self::assertSame($data, $read->getData()['data'] + ['idempotent'=>false]);
        self::assertCount(100, $data['inputs']['items']);
        $json = (string)Db::name(OperatingEvidenceSnapshotStore::TABLE)->where('id',$data['snapshot_id'])->value('payload_json');
        self::assertSame(100, substr_count($json, '"name":'));
        self::assertLessThan(1000000, strlen($json));
        self::assertSame(hash('sha256',$json), $data['content_digest']);
        $retry = $this->call('saveEvidence', $request, $this->user())->getData()['data'];
        self::assertSame($data['snapshot_id'], $retry['snapshot_id']);
        self::assertTrue($retry['idempotent']);
        self::assertSame(1, Db::name(OperatingEvidenceSnapshotStore::TABLE)->count());
    }
    public static function hundredItemTextCases(): array
    {
        return ['ascii complete'=>['A',false], 'unicode complete'=>['🧴',false], 'unicode missing quantities'=>['🧴',true]];
    }

    public function testReplayReturnsTheOriginalVersionAfterSourceFactsChangeAndRejectsChangedInputs(): void
    {
        $this->database();
        $request = $this->input(['kind'=>'channel_economics','platform'=>'ctrip','inputs'=>[
            'net_revenue'=>1000,'advertising_spend'=>100,'attributed_order_amount'=>400,'effective_order_amount'=>1200,'refund_amount'=>50,
            'attribution_basis'=>'synthetic-same-window','advertising_included_in_net_revenue'=>false,'advertising_in_direct_costs'=>false,
            'cost_coverage_complete'=>true,'operator_attested'=>true,'source_refs'=>['synthetic-ledger'],'costs'=>[],
        ]]);
        $sources = ['settlement'=>['readback_verified'=>true,'projection_status'=>'latest_attempt','latest_attempt'=>['batch_status'=>'validated'],
            'source'=>['source_quality_status'=>'operator_attested'],'basis_ledger'=>['components'=>['net_revenue'=>['value'=>900]]]]];
        $result = (new \app\service\ChannelEconomicsService())->calculate($request['inputs'],$sources);
        self::assertSame(900.0,$result['net_revenue']);
        $store = new OperatingEvidenceSnapshotStore();
        $scope = $store->scope(10,[80],80,'2026-09','ctrip','channel_economics');
        $original = $store->save($scope,['inputs'=>$result['inputs'],'result'=>$result,'status'=>$result['status'],'source_quality'=>$result['source_quality']],$request['idempotency_key'],7,$request['inputs']);
        // The current source tables do not contain that captured settlement. Recalculating would use the manual 1000 instead.
        $response = $this->call('saveEvidence',$request,$this->user());
        self::assertSame(200,$response->getCode(),$response->getContent());
        self::assertSame($original['snapshot_id'],$response->getData()['data']['snapshot_id']);
        self::assertSame($original['content_digest'],$response->getData()['data']['content_digest']);
        self::assertSame(900,$response->getData()['data']['result']['net_revenue']);
        self::assertTrue($response->getData()['data']['idempotent']);
        self::assertSame(1,Db::name(OperatingEvidenceSnapshotStore::TABLE)->count());
        $request['inputs']['net_revenue'] = 1001;
        $changed = $this->call('saveEvidence',$request,$this->user());
        self::assertSame(409,$changed->getCode());
        self::assertSame(1,Db::name(OperatingEvidenceSnapshotStore::TABLE)->count());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('legacyStorageFormats')]
    public function testLegacyVersionWithoutRequestDigestRetainsItsOriginalReplayContract(bool $uncompacted): void
    {
        $this->database();
        $request = $this->input();
        $result = (new \app\service\ConsumablesActualCostService())->calculate($request['inputs']);
        $store = new OperatingEvidenceSnapshotStore();
        $scope = $store->scope(10,[80],80,'2026-09','whole_hotel','consumables_actual');
        $payload = ['inputs'=>$result['inputs'],'result'=>$result,'status'=>$result['status'],'source_quality'=>$result['source_quality']];
        $original = $store->save($scope,$payload,$request['idempotency_key'],7);
        if ($uncompacted) {
            $json = json_encode(['contract_version'=>'operating_evidence.v1','scope'=>$scope] + $payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            Db::name(OperatingEvidenceSnapshotStore::TABLE)->where('id',$original['snapshot_id'])->update(['payload_json'=>$json,'content_digest'=>hash('sha256',$json)]);
            $original = $store->read($scope,$original['snapshot_id']);
        }
        $expected = $original;
        $expected['idempotent'] = true;
        self::assertSame($expected,$store->replayRequest($scope,$request['idempotency_key'],$request['inputs']));
        $legacyReplay = $store->save($scope,$payload,$request['idempotency_key'],7);
        self::assertSame($original['snapshot_id'],$legacyReplay['snapshot_id']);
        self::assertSame($original['content_digest'],$legacyReplay['content_digest']);
        self::assertTrue($legacyReplay['idempotent']);
        $controllerReplay = $this->call('saveEvidence',$request,$this->user());
        self::assertSame(200,$controllerReplay->getCode(),$controllerReplay->getContent());
        self::assertSame($original['content_digest'],$controllerReplay->getData()['data']['content_digest']);
        self::assertTrue($controllerReplay->getData()['data']['idempotent']);
        self::assertSame(1,Db::name(OperatingEvidenceSnapshotStore::TABLE)->count());
    }
    public static function legacyStorageFormats(): array { return ['original full format'=>[true],'compact format without request digest'=>[false]]; }

    public function testControllerReplaysLegacyChannelVersionBeforeNewEvidenceFieldsAreCalculated(): void
    {
        $this->database();
        $request = $this->input(['kind'=>'channel_economics','platform'=>'ctrip','inputs'=>[
            'net_revenue'=>1000,'advertising_spend'=>100,'attributed_order_amount'=>400,'effective_order_amount'=>1200,'refund_amount'=>50,
            'attribution_basis'=>'synthetic-legacy-same-window','advertising_included_in_net_revenue'=>false,'advertising_in_direct_costs'=>false,
            'cost_coverage_complete'=>true,'operator_attested'=>true,'source_refs'=>['synthetic-legacy-ledger'],'costs'=>[],
        ]]);
        $result = (new \app\service\ChannelEconomicsService())->calculate($request['inputs']);
        unset($result['evidence_chain'],$result['evidence_refs_by_metric'],$result['source_receipts'],$result['inputs']['evidence_refs_by_metric']);
        $store = new OperatingEvidenceSnapshotStore();
        $scope = $store->scope(10,[80],80,'2026-09','ctrip','channel_economics');
        $original = $store->save($scope,['inputs'=>$result['inputs'],'result'=>$result,'status'=>$result['status'],'source_quality'=>$result['source_quality']],$request['idempotency_key'],7);
        $originalJson = Db::name(OperatingEvidenceSnapshotStore::TABLE)->where('id',$original['snapshot_id'])->value('payload_json');
        $response = $this->call('saveEvidence',$request,$this->user());
        self::assertSame(200,$response->getCode(),$response->getContent());
        $expected = $original;
        $expected['idempotent'] = true;
        self::assertSame($expected,$response->getData()['data']);
        self::assertArrayNotHasKey('evidence_chain',$response->getData()['data']['result']);
        self::assertSame($originalJson,Db::name(OperatingEvidenceSnapshotStore::TABLE)->where('id',$original['snapshot_id'])->value('payload_json'));
        $request['inputs']['net_revenue'] = 1001;
        self::assertSame(409,$this->call('saveEvidence',$request,$this->user())->getCode());
        self::assertSame(1,Db::name(OperatingEvidenceSnapshotStore::TABLE)->count());
    }

    public function testFutureActualAccountingMonthAndInventoryDateCannotPreviewOrSave(): void
    {
        $this->database();
        $today = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai'));
        $futureMonth = $today->modify('first day of next month')->format('Y-m');
        $futurePeriod = $this->input(['period_month'=>$futureMonth]);
        $futurePeriod['inputs']['items'][0]['source_date'] = $futureMonth.'-01';
        $futureDate = $this->input(['period_month'=>$today->format('Y-m')]);
        $futureDate['inputs']['items'][0]['source_date'] = $today->modify('+1 day')->format('Y-m-d');
        $before = $this->storedRows();
        foreach ([$futurePeriod, $futureDate] as $input) foreach (['previewEvidence','saveEvidence'] as $action) {
            $response = $this->call($action, $input, $this->user());
            self::assertSame(422, $response->getCode(), $response->getContent());
            self::assertNull($response->getData()['data']);
            self::assertSame($before, $this->storedRows());
        }
    }

    public function testFutureChannelActualsCannotPreviewSaveOrReplayAnOldVersion(): void
    {
        $this->database();
        $today = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai'));
        $inputs = ['net_revenue'=>1000, 'advertising_spend'=>100, 'attributed_order_amount'=>400,
            'effective_order_amount'=>1200, 'refund_amount'=>50, 'attribution_basis'=>'synthetic-same-window',
            'advertising_included_in_net_revenue'=>false, 'advertising_in_direct_costs'=>false,
            'cost_coverage_complete'=>true, 'operator_attested'=>true, 'source_refs'=>['synthetic-monthly-ledger'],
            'costs'=>[['label'=>'合成履约成本', 'amount'=>200, 'source_ref'=>'synthetic-cost', 'included_in_net_revenue'=>false]]];
        foreach (['ctrip','meituan'] as $platform) {
            $input = $this->input(['kind'=>'channel_economics', 'platform'=>$platform, 'inputs'=>$inputs,
                'period_month'=>$today->format('Y-m'), 'idempotency_key'=>'synthetic-future-'.$platform]);
            foreach (['previewEvidence','saveEvidence'] as $action) {
                $allowed = $this->call($action, $input, $this->user());
                self::assertSame(200, $allowed->getCode(), $allowed->getContent());
            }
            $input['period_month'] = $today->modify('first day of next month')->format('Y-m');
            $store = new OperatingEvidenceSnapshotStore();
            $scope = $store->scope(10,[80],80,$input['period_month'],$platform,'channel_economics');
            // An old future-dated record remains readable, but cannot bypass the actual-period write guard.
            $old = $store->save($scope,['inputs'=>$inputs,'result'=>['status'=>'calculated']],$input['idempotency_key'],7,$inputs);
            $before = $this->storedRows();
            foreach (['previewEvidence','saveEvidence'] as $action) {
                $blocked = $this->call($action, $input, $this->user());
                self::assertSame(422, $blocked->getCode(), $blocked->getContent());
                self::assertStringContainsString('渠道', $blocked->getData()['message']);
                self::assertNull($blocked->getData()['data']);
                self::assertSame($before, $this->storedRows());
            }
            $read = $this->call('readEvidence',$input,$this->user(),['id'=>$old['snapshot_id']]);
            self::assertSame(200,$read->getCode(),$read->getContent());
            self::assertSame($old['content_digest'],$read->getData()['data']['content_digest']);
        }
    }

    private function database(): void
    {
        $this->databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'investment-scenario-test-' . bin2hex(random_bytes(6)) . '.sqlite';
        Fixture::connect($this->databasePath);
        Fixture::schema();
        Db::execute('ALTER TABLE hotels ADD COLUMN name TEXT NOT NULL DEFAULT "合成测试酒店"');
        Db::execute('CREATE TABLE hotel_operating_evidence_snapshots ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, source_hotel_id INTEGER NOT NULL, '
            . 'kind TEXT NOT NULL, period_month TEXT NOT NULL, platform TEXT NOT NULL, payload_json TEXT NOT NULL, '
            . 'content_digest TEXT NOT NULL, idempotency_key TEXT NOT NULL, created_by INTEGER NOT NULL, created_at TEXT NOT NULL, '
            . 'UNIQUE(tenant_id,hotel_id,kind,period_month,platform,idempotency_key))');
    }

    private function storedRows(): array
    {
        $result = [];
        foreach (['hotels', OperatingEvidenceSnapshotStore::TABLE, 'investment_payback_projects', 'investment_payback_entries', 'investment_payback_events'] as $table) {
            $result[$table] = Db::name($table)->order('id')->select()->toArray();
        }
        return $result;
    }

    private function input(array $changes = []): array
    {
        return array_replace(['hotel_id' => 80, 'period_month' => '2026-09', 'platform' => 'whole_hotel', 'kind' => 'consumables_actual',
            'idempotency_key' => 'synthetic-controller-snapshot', 'inputs' => ['occupied_room_nights' => 100,
                'occupied_room_nights_source_ref' => 'synthetic-whole-hotel-monthly-room-ledger',
                'denominator_scope' => 'whole_hotel', 'operator_attested' => true,
                'items' => [['id' => 'towel', 'name' => '合成耗材', 'enabled' => true, 'unit' => 'piece',
                    'source_ref' => 'synthetic-inventory-count', 'source_date' => '2026-09-20', 'opening_quantity' => 30,
                    'purchased_quantity' => 100, 'transfer_in_quantity' => 0, 'closing_quantity' => 20,
                    'transfer_out_quantity' => 0, 'returned_quantity' => 0, 'written_off_quantity' => 10, 'unit_price' => 2]]]], $changes);
    }

    private function user(array $permitted = [80], array $capabilities = ['operation.view', 'operation.execute'], bool $superAdmin = false): object
    {
        return new class($permitted, $capabilities, $superAdmin) {
            public int $id = 7;
            public int $tenant_id = 10;
            public function __construct(private array $permitted, private array $capabilities, private bool $superAdmin) {}
            public function getPermittedHotelIds(): array { return $this->permitted; }
            public function hasHotelPermission(int $hotelId, string $capability): bool { return in_array($capability, $this->capabilities, true); }
            public function isSuperAdmin(): bool { return $this->superAdmin; }
        };
    }

    private function call(string $action, array $params, ?object $user, array $arguments = []): Response
    {
        $class = new ReflectionClass(OperatingFinance::class);
        $controller = $class->newInstanceWithoutConstructor();
        $request = new class($params) {
            public function __construct(private array $values) {}
            public function param(?string $key = null, mixed $default = null): mixed { return $key === null ? $this->values : ($this->values[$key] ?? $default); }
            public function post(): array { return $this->values; }
            public function method(): string { return 'POST'; }
            public function getContent(): string { return ''; }
        };
        $class->getProperty('request')->setValue($controller, $request);
        $class->getProperty('currentUser')->setValue($controller, $user);
        return $class->getMethod($action)->invokeArgs($controller, array_values($arguments));
    }

    private function request(string $method, string $path): Request
    {
        return (new Request())->setMethod($method)->setUrl($path)->setBaseUrl($path)->setPathinfo(ltrim($path, '/'))
            ->withHeader(['accept' => 'application/json', 'authorization' => '', 'x-request-id' => 'operating-evidence-route-test']);
    }

    private function rule(Dispatch $dispatch): Rule
    {
        $rule = (new ReflectionProperty(Dispatch::class, 'rule'))->getValue($dispatch);
        self::assertInstanceOf(Rule::class, $rule);
        return $rule;
    }

    private function middlewares(Rule $rule): array
    {
        return array_map(static fn($middleware) => is_array($middleware) ? $middleware[0] : $middleware, $rule->getOption('middleware', []));
    }

    public function testMalformedConsumablesCollectionsRejectAsInputErrorsWithoutSaving(): void
    {
        $this->database();
        $before = $this->storedRows();
        foreach (['not-an-array', [['enabled' => true, 'source_date' => ['2026-09-20']]]] as $items) {
            $request = $this->input(); $request['inputs']['items'] = $items;
            $response = $this->call('saveEvidence', $request, $this->user());
            self::assertSame(422, $response->getCode(), $response->getContent());
            self::assertSame($before, $this->storedRows());
        }
    }

    public function testFractionalHotelIdentityCannotReadOrWriteAnotherHotelEvidence(): void
    {
        $this->database();
        $before = $this->storedRows();
        foreach (['80.5', 80.5] as $hotelId) {
            foreach (['evidenceOverview', 'previewEvidence', 'saveEvidence'] as $action) {
                $response = $this->call($action, $this->input(['hotel_id' => $hotelId]), $this->user());
                self::assertSame(422, $response->getCode(), $response->getContent());
                self::assertSame($before, $this->storedRows());
            }
        }
    }

    public function testMalformedAccountingMonthIsRejectedWithoutServerFailure(): void
    {
        $this->database();
        $before = $this->storedRows();
        $response = $this->call('saveEvidence', $this->input(['period_month' => '2026-' . chr(0) . '9']), $this->user());
        self::assertSame(422, $response->getCode(), $response->getContent());
        self::assertSame($before, $this->storedRows());
    }
}
