<?php
declare(strict_types=1);
namespace Tests;

use app\service\LlmClient;
use app\service\QuantSimulationService;
use PHPUnit\Framework\TestCase;
use Tests\Support\QuantOperatingFixture as Fixture;
use Tests\Support\QuantOperatingControllerFixture;
use think\facade\Config;
use think\facade\Db;

final class QuantOperatingPersistenceTest extends TestCase
{
    private static array $original;
    private static string $path;

    public static function setUpBeforeClass(): void
    {
        self::$original = Config::get('database', []);
        self::$path = sys_get_temp_dir() . '/l09-synthetic-' . bin2hex(random_bytes(8)) . '.sqlite';
        Fixture::database(self::$path);
    }

    public static function tearDownAfterClass(): void
    {
        Db::connect()->close();
        Config::set(self::$original, 'database');
        unlink(self::$path);
    }

    protected function setUp(): void { Db::name('quant_simulation_records')->delete(true); }

    private function service(): QuantSimulationService
    {
        return new QuantSimulationService(new class extends LlmClient {
            public function __construct() {}
            public function createJsonResponse(array $messages, array $schema, string $modelKey = 'deepseek_v4_default'): array
            { throw new \LogicException('No LLM allowed in synthetic test'); }
        });
    }

    public function testSaveReadbackEditNewRevisionAndDuplicateRetry(): void
    {
        $service = $this->service();
        $input = Fixture::input();
        $payload = ['input'=>$input, 'project_name'=>'synthetic original', 'client_request_id'=>'l09-save-original'];
        $saved = $service->calculateAndSave($payload, 91, [901]);
        $detail = $service->detail($saved['id'], 91, false);
        self::assertSame($saved, $detail);
        self::assertSame(9, $detail['truth_context']['tenant_id']);
        self::assertSame(901, $detail['truth_context']['hotel_id']);
        self::assertTrue($detail['truth_context']['persistence']['readback_verified']);
        self::assertSame('unverified', $detail['truth_context']['status']);
        self::assertSame('deterministic_formula', $detail['model_analysis']['source']);
        $row = Db::name('quant_simulation_records')->find($saved['id']);
        self::assertEquals($detail['result'], json_decode($row['result_json'], true, 512, JSON_THROW_ON_ERROR));
        self::assertSame($saved['id'], $service->calculateAndSave($payload, 91, [901])['id']);
        self::assertSame(1, Db::name('quant_simulation_records')->count());
        $input['monthlyRent']=6000;
        $input['operatingScenario']['case_name']='synthetic revised';
        $changed = $service->calculateAndSave(['input'=>$input,'project_name'=>'synthetic revised','client_request_id'=>'l09-save-revised'],91,[901]);
        self::assertNotSame($saved['id'], $changed['id']);
        self::assertSame(6000.0, (float)$changed['input']['monthlyRent']);
        self::assertEqualsWithDelta(-12000, $changed['result']['operatingScenario']['ending_cash_balance']-$saved['result']['operatingScenario']['ending_cash_balance'], 0.001);
        self::assertSame($saved, $service->detail($saved['id'],91,false));
        self::assertSame('synthetic revised', $service->records(91,false)[0]['summary']['operatingScenario']['case_name']);
        $this->expectExceptionMessage('请求标识已用于');
        $service->calculateAndSave(['input'=>$input,'client_request_id'=>'l09-save-original'],91,[901]);
    }

    public function testTenantOwnerHotelScopeAndMalformedHotelConflict(): void
    {
        $service=$this->service(); $input=Fixture::input();
        $saved=$service->calculateAndSave(['input'=>$input],91,[901]);
        self::assertSame([], $service->records(92,false));
        self::assertSame([], $service->records(93,false));
        foreach ([[92,902],[93,903]] as [$user,$hotel]) {
            try { $service->detail($saved['id'],$user,false); self::fail('Cross owner detail accepted'); } catch (\RuntimeException $e) { self::assertStringContainsString('无权访问',$e->getMessage()); }
        }
        foreach ([['hotel_id'=>902],['system_hotel_id'=>903]] as $patch) {
            try { $service->calculateAndSave(['input'=>array_replace($input,$patch)],91,[901]); self::fail('Cross hotel accepted'); } catch (\InvalidArgumentException $e) { self::assertNotSame('', $e->getMessage()); }
        }
        self::assertSame(1,Db::name('quant_simulation_records')->count());
    }

    public function testUnroundedWeightedInputSurvivesSaveReadbackAndIdempotentRetry(): void
    {
        $input = array_replace(Fixture::input(), [
            'roomCount'=>100, 'adr'=>300, 'occupancyRate'=>50,
            'weekdayDays'=>22, 'weekdayAdr'=>300, 'weekdayOccupancyRate'=>50,
            'weekendDays'=>9, 'weekendAdr'=>300, 'weekendOccupancyRate'=>80,
            'holidayDays'=>0, 'holidayAdr'=>300, 'holidayOccupancyRate'=>50,
            'monthlyRent'=>546001, 'laborCost'=>0, 'utilityCost'=>0,
            'otaCommissionRate'=>0, 'decorationInvestment'=>1.004,
        ]);
        $input['operatingScenario'] = array_replace($input['operatingScenario'], [
            'start_month'=>'2026-01', 'horizon_months'=>1, 'target_payback_months'=>1, 'opening_cash'=>1.004,
        ]);
        $service = $this->service();
        $payload = ['input'=>$input, 'project_name'=>'synthetic precision', 'client_request_id'=>'l09-precision-retry'];
        $saved = $service->calculateAndSave($payload, 91, [901]);
        $detail = $service->detail($saved['id'], 91, false);
        self::assertSame($saved, $detail);
        self::assertEqualsWithDelta(1820 / 3100 * 100, $detail['input']['occupancyRate'], 1e-12);
        self::assertSame(-1.004, $detail['result']['operatingScenario']['cashflow_series'][0]['project_cashflow']);
        self::assertSame(-1.0, (float)$detail['result']['operatingScenario']['cashflow_series'][1]['equity_cashflow']);
        self::assertNull($detail['result']['operatingScenario']['equity_payback']['months']);
        self::assertSame('not_met', $detail['result']['operatingScenario']['target_status']);
        self::assertSame($saved['id'], $service->calculateAndSave($payload, 91, [901])['id']);
        $payload['input'] = $detail['input'];
        self::assertSame($saved['id'], $service->calculateAndSave($payload, 91, [901])['id']);
        self::assertSame(1, Db::name('quant_simulation_records')->count());
    }

    public function testRetryMatchesNativeJsonWhitespaceAndKeyReordering(): void
    {
        $service=$this->service();$payload=['input'=>Fixture::input(),'client_request_id'=>'l09-native-json-layout'];
        $saved=$service->calculateAndSave($payload,91,[901]);
        $input=$saved['input'];$input['operatingScenario']=array_reverse($input['operatingScenario'],true);
        $nativeStyle=json_encode(array_reverse($input,true),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        Db::name('quant_simulation_records')->where('id',$saved['id'])->update(['input_json'=>$nativeStyle]);
        $retry=$service->calculateAndSave($payload,91,[901]);
        self::assertSame($saved['id'],$retry['id']);
        self::assertEquals($saved['input'],$retry['input']);
        self::assertSame(1,Db::name('quant_simulation_records')->count());
    }

    public function testWriteFailureRollsBackAndSameRequestCanRecover(): void
    {
        Db::execute("CREATE TRIGGER l09_fail BEFORE INSERT ON quant_simulation_records BEGIN SELECT RAISE(ABORT, 'synthetic save failure'); END");
        $service=$this->service(); $payload=['input'=>Fixture::input(),'client_request_id'=>'l09-retry-after-failure'];
        try { $service->calculateAndSave($payload,91,[901]); self::fail('Write should fail'); } catch (\Throwable $e) { self::assertStringContainsString('synthetic save failure',$e->getMessage()); }
        self::assertSame(0,Db::name('quant_simulation_records')->count());
        Db::execute('DROP TRIGGER l09_fail');
        $saved=$service->calculateAndSave($payload,91,[901]);
        self::assertSame($saved,$service->detail($saved['id'],91,false));
    }

    public function testOldRecordMissingValuesStayMissingWithoutNewScenarioDefaults(): void
    {
        $id=(int)Db::name('quant_simulation_records')->insertGetId(['tenant_id'=>9,'created_by'=>91,'input_json'=>'{}','result_json'=>'{}','scenarios_json'=>'[]','risk_hints_json'=>'[]','monthly_net_cashflow'=>0,'payback_months'=>null]);
        $detail=$this->service()->detail($id,91,false);
        self::assertSame([], $detail['input']);
        self::assertNull($detail['summary']['operatingScenario']);
        self::assertNull($detail['summary']['monthlyNetCashflow']);
        self::assertSame('legacy_read_only',$detail['access_policy']['mode']);
    }

    public function testControllerPreservesScopeConflictsAndUsesProductionSaveDetailContracts(): void
    {
        $controller=(new QuantOperatingControllerFixture(app()))->input(['input'=>Fixture::input(),'hotel_id'=>901]);
        $saved=$controller->calculate()->getData();
        self::assertSame(200,$saved['code']);
        self::assertSame($saved['data'],$controller->detail($saved['data']['id'])->getData()['data']);
        foreach ([['hotel_id'=>902],['system_hotel_id'=>902],['hotel_id'=>901.5]] as $patch) {
            $response=$controller->input(array_replace(['input'=>Fixture::input()],$patch))->calculate();
            self::assertSame(400,$response->getCode());self::assertSame(400,$response->getData()['code']);
        }
        $controller->permit=false;
        $denied=$controller->input(['input'=>Fixture::input()])->calculate();
        self::assertSame(403,$denied->getCode());
        self::assertSame(1,Db::name('quant_simulation_records')->count());
    }
}
