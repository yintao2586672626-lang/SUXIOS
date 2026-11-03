<?php
declare(strict_types=1);
namespace Tests;

use app\service\InvestmentScenarioCalculator;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\InvestmentScenarioFixture as Fixture;
use think\facade\Db;
require_once __DIR__ . '/Support/InvestmentScenarioFixture.php';

final class InvestmentScenarioPortfolioTest extends TestCase
{
    private string $path;
    protected function setUp(): void { $this->path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'investment-scenario-test-' . bin2hex(random_bytes(6)) . '.sqlite'; Fixture::connect($this->path); Fixture::schema(); }
    protected function tearDown(): void { Db::connect('investment_scenario_test')->close(); @unlink($this->path); }
    private function input(string $name): array
    {
        $input = (new InvestmentScenarioCalculator())->referenceExample();
        $input['scenario_name'] = $name; $input['as_of'] = '2026-10-01';
        $input['cash_adjustments'] = array_map(fn($year) => ['year' => $year, 'tax_cash' => 0, 'financing_net_cash' => 0, 'maintenance_capex' => 0, 'working_capital_change' => 0, 'deposit_refund' => 0, 'salvage_cash' => 0], range(1, 10));
        return $input;
    }
    public function testThreeIndependentScenariosAndHistoricalCopyAppendWithoutChangingActualCash(): void
    {
        $id = Fixture::ledger()->saveProject(Fixture::project())['project']['id']; $service = Fixture::scenarios();
        $version = 1; $saved = [];
        foreach (['base', 'conservative', 'optimistic'] as $key) {
            $saved[$key] = $service->save($id, ['expected_version' => $version, 'scenario_key' => $key, 'scenario' => $this->input($key)]);
            $version = $saved[$key]['project_version'];
            self::assertSame(1, $saved[$key]['scenario_version']);
        }
        self::assertSame('base', $service->detail($id)['input']['scenario_name']);
        self::assertSame('conservative', $service->detail($id, 'conservative')['input']['scenario_name']);
        self::assertCount(3, $service->library($id)['items']); self::assertCount(3, $service->history($id)['items']);
        $sourceId = $saved['conservative']['scenario_event_id'];
        $raw = Db::name('investment_payback_events')->where('id', $sourceId)->value('payload_json');
        $copied = $service->copyVersion($id, $sourceId, ['expected_version' => $version, 'scenario_key' => 'base']);
        self::assertSame(2, $copied['scenario_version']); self::assertSame('conservative', $copied['input']['scenario_name']);
        self::assertSame($saved['optimistic']['content_digest'], $service->detail($id, 'optimistic')['content_digest']);
        $retry = $service->copyVersion($id, $sourceId, ['expected_version' => $version, 'scenario_key' => 'base']);
        self::assertSame('unchanged', $retry['action']); self::assertSame(2, $retry['scenario_version']);
        self::assertSame($raw, Db::name('investment_payback_events')->where('id', $sourceId)->value('payload_json'));
        self::assertSame(0, Db::name('investment_payback_entries')->count());
        self::assertNull(Fixture::ledger()->detail($id)['summary']['invested_amount']);
    }
    public function testProjectComparisonRequiresSameDateModelWindowAndCompleteCash(): void
    {
        $service = Fixture::scenarios(); $ids = [];
        foreach (['project1', 'project2'] as $name) { $id = Fixture::ledger()->saveProject(Fixture::project(['project_name' => $name, 'client_request_id' => $name]))['project']['id']; $ids[] = $id; $service->save($id, ['expected_version' => 1, 'scenario' => $this->input($name)]); }
        $selection = ['selections' => array_map(fn($id) => ['project_id' => $id, 'scenario_key' => 'base'], $ids)];
        $compare = $service->compare($selection);
        self::assertTrue($compare['comparable']); self::assertFalse($compare['ranking_performed']);
        self::assertNull($compare['items'][0]['actual_cash']['invested_amount']);
        $input = $this->input('other-date'); $input['as_of'] = '2026-11-01';
        $service->save($ids[1], ['expected_version' => 2, 'scenario' => $input]);
        $compare = $service->compare($selection); self::assertFalse($compare['comparable']); self::assertStringContainsString('as_of', implode(';', $compare['reasons']));
        $selection['selections'][1]['scenario_key'] = 'optimistic';
        $compare = $service->compare($selection); self::assertFalse($compare['comparable']); self::assertSame('no_saved_scenario', $compare['items'][1]['readback']);
    }
    public function testHistoryCopyAndCompareCannotCrossProjectTenantHotelOrArchive(): void
    {
        $ledger = Fixture::ledger(); $id = $ledger->saveProject(Fixture::project())['project']['id']; $service = Fixture::scenarios();
        $saved = $service->save($id, ['expected_version' => 1, 'scenario' => $this->input('base')]);
        foreach ([Fixture::scenarios(20, 8, [90]), Fixture::scenarios(10, 8, [81])] as $other) {
            foreach ([fn() => $other->library($id), fn() => $other->history($id), fn() => $other->version($id, $saved['scenario_event_id']), fn() => $other->copyVersion($id, $saved['scenario_event_id'], ['expected_version' => 2])] as $action) {
                try { $action(); self::fail('Scope must reject'); } catch (RuntimeException $e) { self::assertContains($e->getCode(), [403, 404]); }
            }
        }
        $ledger->archive($id, ['reason' => '合成只读项目']);
        self::assertSame('exact', $service->version($id, $saved['scenario_event_id'])['readback']);
        try { $service->copyVersion($id, $saved['scenario_event_id'], ['expected_version' => 3]); self::fail('Archived copy must reject'); } catch (RuntimeException $e) { self::assertSame(409, $e->getCode()); }
        self::assertSame(0, Db::name('investment_payback_entries')->count());
    }
    public function testCashPlanConstraintsSaveEditAndReadBackExactly(): void
    {
        $id = Fixture::ledger()->saveProject(Fixture::project())['project']['id']; $service = Fixture::scenarios(); $input = $this->input('cash plan');
        $input['cash_plan'] = ['start_month' => '2026-10', 'months' => 1, 'opening_liquidity' => 0, 'source_label' => '合成', 'loans' => [['id' => 'l', 'principal' => 1000, 'annual_rate' => .12, 'term_months' => 1, 'start_month' => '2026-10', 'funding' => 'existing', 'method' => 'equal_principal']], 'monthly_inputs' => [['month' => '2026-10', 'operating_net_cash' => 100, 'capex_cash' => 0, 'other_net_cash' => 0]]];
        $input['decision_constraints'] = ['target_payback_months' => 12, 'contract_start_on' => '2026-01-01', 'contract_end_on' => '2027-10-01', 'contract_source' => '合成合同', 'contract_confirmed' => true];
        $saved = $service->save($id, ['expected_version' => 1, 'scenario' => $input]);
        self::assertSame('910.00', $saved['result']['cash_pressure']['funding_gap']);
        self::assertSame($saved['result'], $service->detail($id)['result']);
        $input['cash_plan']['opening_liquidity'] = 1000;
        $edit = $service->save($id, ['expected_version' => 2, 'scenario' => $input]);
        self::assertSame('0.00', $edit['result']['cash_pressure']['funding_gap']);
        self::assertSame('910.00', $service->version($id, $saved['scenario_event_id'])['result']['cash_pressure']['funding_gap']);
    }
    public function testEarlierVersionsCanBePagedWithoutReturningRawLargeSnapshots(): void
    {
        $id = Fixture::ledger()->saveProject(Fixture::project())['project']['id']; $service = Fixture::scenarios();
        $saved = $service->save($id, ['expected_version' => 1, 'scenario' => $this->input('history')]);
        $event = Db::name('investment_payback_events')->where('id', $saved['scenario_event_id'])->find(); unset($event['id']);
        for ($n = 0; $n < 100; $n++) Db::name('investment_payback_events')->insert($event);
        $first = $service->history($id); self::assertCount(100, $first['items']); self::assertTrue($first['has_more']);
        self::assertArrayNotHasKey('input', $first['items'][0]); self::assertArrayNotHasKey('result', $first['items'][0]);
        $second = $service->history($id, $first['next_before_event_id']); self::assertCount(1, $second['items']); self::assertFalse($second['has_more']);
        self::assertSame($saved['scenario_event_id'], $second['items'][0]['scenario_event_id']);
    }
}
