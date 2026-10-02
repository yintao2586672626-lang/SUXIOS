<?php
declare(strict_types=1);

namespace Tests;

use app\service\ConsumablesActualCostService;
use app\service\InvestmentScenarioCalculator;
use app\service\OperatingEvidenceSnapshotStore;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\InvestmentScenarioFixture as Fixture;
use think\facade\Db;

require_once __DIR__ . '/Support/InvestmentScenarioFixture.php';

/** Synthetic monthly inventory and an isolated SQLite database; no account or business data. */
final class ActualConsumablesScenarioReferenceTest extends TestCase
{
    private string $path;

    public function testComposedScenarioReportsBothInstalledReferenceCapabilities(): void
    {
        $id = Fixture::ledger()->saveProject(Fixture::project())['project']['id'];
        self::assertSame(['procurement_reference' => true, 'actual_consumables_reference' => true],
            Fixture::scenarios()->detail($id)['capabilities']);
        self::assertSame(0, Db::name('investment_payback_entries')->count());
    }

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'investment-scenario-test-' . bin2hex(random_bytes(6)) . '.sqlite';
        Fixture::connect($this->path);
        Fixture::schema();
        Db::execute('CREATE TABLE hotel_operating_evidence_snapshots ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, source_hotel_id INTEGER NOT NULL, '
            . 'kind TEXT NOT NULL, period_month TEXT NOT NULL, platform TEXT NOT NULL, payload_json TEXT NOT NULL, '
            . 'content_digest TEXT NOT NULL, idempotency_key TEXT NOT NULL, created_by INTEGER NOT NULL, created_at TEXT NOT NULL, '
            . 'UNIQUE(tenant_id,hotel_id,kind,period_month,platform,idempotency_key))');
    }

    protected function tearDown(): void
    {
        Db::connect('investment_scenario_test')->close();
        @unlink($this->path);
    }

    private function actualInput(): array
    {
        $row = ['id' => 'towel', 'name' => '合成计量用品', 'enabled' => true, 'unit' => 'piece',
            'source_ref' => 'synthetic-inventory-count#1', 'source_date' => '2026-09-20',
            'opening_quantity' => 30, 'purchased_quantity' => 100, 'transfer_in_quantity' => 0,
            'closing_quantity' => 20, 'transfer_out_quantity' => 0, 'returned_quantity' => 0,
            'written_off_quantity' => 10, 'unit_price' => 2];
        return ['occupied_room_nights' => 100, 'occupied_room_nights_source_ref' => 'synthetic-pms-room-nights', 'denominator_scope' => 'whole_hotel', 'operator_attested' => true,
            'items' => [$row, array_replace($row, ['id' => 'soap', 'source_ref' => 'synthetic-inventory-count#2', 'source_date' => '2026-09-28']),
                array_replace($row, ['id' => 'disabled', 'enabled' => false, 'source_date' => '2099-01-01'])]];
    }

    private function evidence(array $inputChanges = [], int $tenant = 10, int $hotel = 80, string $month = '2026-09'): array
    {
        $input = array_replace($this->actualInput(), $inputChanges);
        $result = (new ConsumablesActualCostService())->calculate($input);
        $store = new OperatingEvidenceSnapshotStore();
        $scope = $store->scope($tenant, [$hotel], $hotel, $month, 'whole_hotel', 'consumables_actual');
        return $store->save($scope, ['inputs' => $result['inputs'], 'result' => $result, 'source_quality' => $result['source_quality']], 'synthetic-actual-reference', 7);
    }

    private function scenario(array $snapshot): array
    {
        $input = (new InvestmentScenarioCalculator())->referenceExample();
        $input['scenario_name'] = '合成实际耗材参考方案';
        $input['as_of'] = '2026-10-01';
        $input['operating_cost_basis'] = 'occupied_room_night';
        $input['cost_evidence_snapshot_id'] = $snapshot['snapshot_id'];
        $input['cost_evidence_digest'] = $snapshot['content_digest'];
        $input['cost_evidence_confirmed'] = true;
        $input['consumables_cost'] = ['schema_version' => 'consumables-v1', 'mode' => 'derived', 'other_variable_cost_per_night' => 0,
            'items' => [['id' => 'actual-evidence-' . $snapshot['snapshot_id'], 'name' => '人工月度耗材成本参考', 'enabled' => true,
                'package_price' => $snapshot['result']['actual_consumables_cost_per_room_night'], 'package_quantity' => 1,
                'unit' => 'piece', 'usage_quantity' => 1, 'usage_basis' => 'occupied_room_night', 'occurrences_per_occupied_night' => 1,
                'source_label' => '客户端未核实文字，应由服务覆盖', 'as_of' => '2040-01-01']]];
        return $input;
    }

    public function testAttestedSameHotelReferencePreviewsSavesEditsAndReadsBackWithoutChangingCashEntries(): void
    {
        $ledger = Fixture::ledger();
        $id = $ledger->saveProject(Fixture::project())['project']['id'];
        $ledger->saveEntry($id, ['kind' => 'investment', 'amount' => '1000.01', 'date' => '2026-10-01', 'precision' => 'day',
            'source' => '合成资金记录', 'client_request_id' => 'synthetic-reference-investment']);
        $cashBefore = Db::name('investment_payback_entries')->select()->toArray();
        $summaryBefore = $ledger->detail($id)['summary'];
        $eventsBefore = Db::name('investment_payback_events')->count();
        $snapshot = $this->evidence(); $input = $this->scenario($snapshot); $service = Fixture::scenarios();
        $preview = $service->preview($id, ['scenario' => $input]);
        self::assertSame('preview_only', $preview['readback']);
        self::assertSame($eventsBefore, Db::name('investment_payback_events')->count());
        self::assertSame('2026-09-28', $preview['input']['consumables_cost']['items'][0]['as_of']);
        self::assertEqualsWithDelta(4.0, $preview['result']['effective_operating_cost_per_night'], 1e-12);
        self::assertStringContainsString('人工核对月度耗材证据', $preview['input']['consumables_cost']['items'][0]['source_label']);
        $saved = $service->save($id, ['expected_version' => $ledger->detail($id)['project']['version'], 'scenario' => $input]);
        $read = $service->detail($id);
        self::assertSame('exact', $read['readback']); self::assertSame($saved['input'], $read['input']);
        self::assertSame($saved['result'], $read['result']); self::assertSame($saved['content_digest'], $read['content_digest']);
        self::assertSame($snapshot['snapshot_id'], $read['input']['cost_evidence_snapshot_id']);
        self::assertSame($snapshot['content_digest'], $read['input']['cost_evidence_digest']);
        self::assertTrue($read['input']['cost_evidence_confirmed']);
        self::assertSame('scenario_assumption', $read['quality_status']); self::assertFalse($read['actual_cash_written']);
        $retry = $service->save($id, ['expected_version' => $saved['project_version'], 'scenario' => $read['input']]);
        self::assertSame('unchanged', $retry['action']);
        $edit = $read['input']; $edit['scenario_name'] = '修改后的合成方案';
        $edited = $service->save($id, ['expected_version' => $read['project_version'], 'scenario' => $edit]);
        self::assertSame(2, $edited['scenario_version']); self::assertSame($edited['result'], $service->detail($id)['result']);
        self::assertSame($cashBefore, Db::name('investment_payback_entries')->select()->toArray());
        self::assertSame($summaryBefore, $ledger->detail($id)['summary']);
    }

    public static function invalidReferences(): array
    {
        return ['cross hotel' => ['hotel', 404], 'cross tenant' => ['tenant', 404], 'wrong digest' => ['digest', 409],
            'changed adopted price' => ['price', 409], 'operator not attested' => ['unattested', 409],
            'incomplete inventory' => ['partial', 409], 'adoption not confirmed' => ['unconfirmed', 422],
            'changed conversion' => ['conversion', 422], 'manual mode' => ['mode', 422],
            'legacy source outside snapshot month' => ['source_month', 409],
            'legacy source contains internal NUL' => ['source_nul', 409]];
    }

    #[DataProvider('invalidReferences')]
    public function testInvalidReferencesRejectBothPreviewAndSaveWithoutSideEffects(string $variant, int $expectedCode): void
    {
        $id = Fixture::ledger()->saveProject(Fixture::project())['project']['id'];
        if ($variant === 'hotel') $snapshot = $this->evidence([], 10, 81);
        elseif ($variant === 'tenant') $snapshot = $this->evidence([], 20, 90);
        elseif ($variant === 'unattested') $snapshot = $this->evidence(['operator_attested' => false]);
        elseif ($variant === 'partial') $snapshot = $this->evidence(['occupied_room_nights' => null]);
        elseif ($variant === 'source_month') {
            $actual = $this->actualInput(); $actual['items'][0]['source_date'] = '2026-08-20';
            $snapshot = $this->evidence(['items' => $actual['items']]);
        }
        else $snapshot = $this->evidence();
        if ($variant === 'source_nul') {
            $result = $snapshot['result']; $result['items'][0]['source_date'] = '2026-09-' . chr(0) . '3';
            $snapshot = (new OperatingEvidenceSnapshotStore())->save($snapshot['scope'],
                ['source_quality' => 'operator_attested', 'inputs' => $snapshot['inputs'], 'result' => $result], 'synthetic-legacy-source-nul', 7);
        }
        $input = $this->scenario($snapshot);
        if ($variant === 'digest') $input['cost_evidence_digest'] = str_repeat('0', 64);
        if ($variant === 'price') $input['consumables_cost']['items'][0]['package_price'] += 1;
        if ($variant === 'unconfirmed') $input['cost_evidence_confirmed'] = false;
        if ($variant === 'conversion') $input['consumables_cost']['items'][0]['package_quantity'] = 2;
        if ($variant === 'mode') $input['consumables_cost']['mode'] = 'manual';
        $eventsBefore = Db::name('investment_payback_events')->count();
        $snapshotBefore = Db::name(OperatingEvidenceSnapshotStore::TABLE)->where('id', $snapshot['snapshot_id'])->find();
        $service = Fixture::scenarios();
        foreach ([fn() => $service->preview($id, ['scenario' => $input]), fn() => $service->save($id, ['expected_version' => 1, 'scenario' => $input])] as $action) {
            try { $action(); self::fail('Invalid reference should be rejected: ' . $variant); }
            catch (InvalidArgumentException $error) { self::assertSame(422, $expectedCode, $error->getMessage()); }
            catch (RuntimeException $error) { self::assertSame($expectedCode, $error->getCode(), $error->getMessage()); }
        }
        self::assertSame($eventsBefore, Db::name('investment_payback_events')->count());
        self::assertSame(0, Db::name('investment_payback_entries')->count());
        self::assertSame(1, $service->detail($id)['project_version']);
        self::assertNull($service->detail($id)['result']);
        self::assertSame($snapshotBefore, Db::name(OperatingEvidenceSnapshotStore::TABLE)->where('id', $snapshot['snapshot_id'])->find());
    }

    public function testLegacyAssumptionsWithoutActualReferenceStayCompatible(): void
    {
        $id = Fixture::ledger()->saveProject(Fixture::project())['project']['id'];
        $input = (new InvestmentScenarioCalculator())->referenceExample();
        $input['as_of'] = '2026-10-01';
        $preview = Fixture::scenarios()->preview($id, ['scenario' => $input]);
        self::assertNull($preview['input']['cost_evidence_snapshot_id']); self::assertFalse($preview['input']['cost_evidence_confirmed']);
        $saved = Fixture::scenarios()->save($id, ['expected_version' => 1, 'scenario' => $input]);
        self::assertSame('exact', $saved['readback']); self::assertSame($saved['result'], Fixture::scenarios()->detail($id)['result']);
        self::assertSame(0, Db::name('investment_payback_entries')->count());
        self::assertSame(0, Db::name(OperatingEvidenceSnapshotStore::TABLE)->count());
    }

    public function testSourceDatesOverrideClientAndCreatedAtButDoNotAlterTheEvidenceSnapshot(): void
    {
        $snapshot = $this->evidence();
        Db::name(OperatingEvidenceSnapshotStore::TABLE)->where('id', $snapshot['snapshot_id'])->update(['created_at' => '2030-01-01 12:00:00']);
        $original = Db::name(OperatingEvidenceSnapshotStore::TABLE)->where('id', $snapshot['snapshot_id'])->find();
        $id = Fixture::ledger()->saveProject(Fixture::project())['project']['id'];
        $saved = Fixture::scenarios()->save($id, ['expected_version' => 1, 'scenario' => $this->scenario($snapshot)]);
        self::assertSame('2026-09-28', $saved['input']['consumables_cost']['items'][0]['as_of']);
        self::assertNotSame('2030-01-01', $saved['input']['consumables_cost']['items'][0]['as_of']);
        self::assertNotSame('2099-01-01', $saved['input']['consumables_cost']['items'][0]['as_of']);
        self::assertSame($original, Db::name(OperatingEvidenceSnapshotStore::TABLE)->where('id', $snapshot['snapshot_id'])->find());
        self::assertSame($saved['input'], Fixture::scenarios()->detail($id)['input']);
    }

    public function testShanghaiTodayEvidenceSavesAdoptsAndReadsBackWhileExcludedFutureRowsStayExcluded(): void
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
        $items = $this->actualInput()['items'];
        foreach ($items as &$item) if ($item['enabled']) $item['source_date'] = $today;
        unset($item);
        $snapshot = $this->evidence(['items' => $items], 10, 80, substr($today, 0, 7));
        $id = Fixture::ledger()->saveProject(Fixture::project())['project']['id'];
        $input = $this->scenario($snapshot); $input['as_of'] = $today;
        $before = Db::name(OperatingEvidenceSnapshotStore::TABLE)->where('id', $snapshot['snapshot_id'])->find();
        $saved = Fixture::scenarios()->save($id, ['expected_version' => 1, 'scenario' => $input]);
        $read = Fixture::scenarios()->detail($id);
        self::assertSame('exact', $saved['readback']);
        self::assertSame($saved['input'], $read['input']); self::assertSame($saved['result'], $read['result']);
        self::assertSame($today, $read['input']['consumables_cost']['items'][0]['as_of']);
        self::assertSame($snapshot['snapshot_id'], $read['input']['cost_evidence_snapshot_id']);
        self::assertEqualsWithDelta(4.0, $read['result']['effective_operating_cost_per_night'], 1e-12);
        self::assertSame($before, Db::name(OperatingEvidenceSnapshotStore::TABLE)->where('id', $snapshot['snapshot_id'])->find());
        self::assertSame(0, Db::name('investment_payback_entries')->count());
    }

    public static function legacyFutureEvidence(): array
    {
        return ['future source' => ['2026-09', '2099-01-31'], 'future period' => ['2099-01', '2026-09-28'],
            'future source and period' => ['2099-01', '2099-01-31']];
    }

    #[DataProvider('legacyFutureEvidence')]
    public function testPreviouslySavedFutureActualEvidenceCannotEnterPreviewOrSave(string $month, string $sourceDate): void
    {
        // A valid immutable historical snapshot models data already saved by the earlier service.
        $result = (new ConsumablesActualCostService())->calculate($this->actualInput());
        foreach ($result['items'] as &$item) if ($item['enabled']) $item['source_date'] = $sourceDate;
        unset($item);
        foreach ($result['inputs']['items'] as &$item) if ($item['enabled']) $item['source_date'] = $sourceDate;
        unset($item);
        $store = new OperatingEvidenceSnapshotStore();
        $scope = $store->scope(10, [80], 80, $month, 'whole_hotel', 'consumables_actual');
        $snapshot = $store->save($scope, ['inputs' => $result['inputs'], 'result' => $result, 'source_quality' => $result['source_quality']], 'synthetic-legacy-future', 7);
        $id = Fixture::ledger()->saveProject(Fixture::project())['project']['id'];
        $input = $this->scenario($snapshot); $service = Fixture::scenarios();
        $snapshotBefore = Db::name(OperatingEvidenceSnapshotStore::TABLE)->where('id', $snapshot['snapshot_id'])->find();
        $eventsBefore = Db::name('investment_payback_events')->count();
        foreach ([fn() => $service->preview($id, ['scenario' => $input]), fn() => $service->save($id, ['expected_version' => 1, 'scenario' => $input])] as $action) {
            try { $action(); self::fail('Previously saved future inventory must not become an adopted actual reference'); }
            catch (RuntimeException $error) { self::assertSame(409, $error->getCode(), $error->getMessage()); self::assertStringContainsString('未来', $error->getMessage()); }
        }
        self::assertSame($eventsBefore, Db::name('investment_payback_events')->count());
        self::assertSame(0, Db::name('investment_payback_entries')->count());
        self::assertSame(1, $service->detail($id)['project_version']); self::assertNull($service->detail($id)['result']);
        self::assertSame($snapshotBefore, Db::name(OperatingEvidenceSnapshotStore::TABLE)->where('id', $snapshot['snapshot_id'])->find());
    }
}
