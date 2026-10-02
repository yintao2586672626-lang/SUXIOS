<?php
declare(strict_types=1);

namespace Tests;

use app\service\InvestmentScenarioCalculator;
use app\service\KnowledgeContentDigestService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\InvestmentScenarioFixture as Fixture;
use think\facade\Db;

require_once __DIR__ . '/Support/InvestmentScenarioFixture.php';

final class InvestmentScenarioPersistenceTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'investment-scenario-test-' . bin2hex(random_bytes(6)) . '.sqlite';
        Fixture::connect($this->path);
        Fixture::schema();
    }

    protected function tearDown(): void
    {
        Db::connect('investment_scenario_test')->close();
        @unlink($this->path);
    }

    public function testSaveGetRetryEditKeepsExactSnapshotAndActualCashUntouched(): void
    {
        $ledger = Fixture::ledger();
        $project = $ledger->saveProject(Fixture::project());
        $id = $project['project']['id'];
        $cash = $ledger->saveEntry($id, ['kind' => 'investment', 'amount' => '1000.01', 'date' => '2026-10-01', 'precision' => 'day',
            'source' => '合成验收款项', 'client_request_id' => 'scenario-cash-test']);
        $version = $cash['project']['version'];
        $summary = $cash['summary'];
        $entries = $cash['entries'];
        $service = Fixture::scenarios();
        $example = (new InvestmentScenarioCalculator())->referenceExample();
        $saved = $service->save($id, ['expected_version' => $version, 'scenario' => $example]);
        self::assertSame('saved', $saved['action']);
        self::assertSame('exact', $saved['readback']);
        self::assertSame(1, $saved['scenario_version']);
        self::assertSame($version + 1, $saved['project_version']);
        $read = $service->detail($id);
        foreach (['input', 'result', 'content_digest', 'scenario_version', 'scenario_event_id'] as $key) self::assertSame($saved[$key], $read[$key]);
        self::assertSame('scenario_assumption', $saved['quality_status']);
        $count = Db::name('investment_payback_events')->count();
        $retry = $service->save($id, ['expected_version' => $version, 'scenario' => $example]);
        self::assertSame('unchanged', $retry['action']);
        self::assertSame($count, Db::name('investment_payback_events')->count());
        $example['adr_first_year'] = 260;
        $edited = $service->save($id, ['expected_version' => $saved['project_version'], 'scenario' => $example]);
        self::assertSame(2, $edited['scenario_version']);
        self::assertSame(260.0, (float)$edited['input']['adr_first_year']);
        self::assertNotSame($saved['content_digest'], $edited['content_digest']);
        self::assertSame($edited['result'], $service->detail($id)['result']);
        $after = $ledger->detail($id);
        self::assertSame($summary, $after['summary']);
        self::assertSame($entries, $after['entries']);
        self::assertSame('scenario_saved', $after['audit_history'][0]['event_type']);
        self::assertArrayNotHasKey('scenario_snapshot', $after['audit_history'][0]['payload']);
        self::assertSame(1, Db::name('investment_payback_entries')->count());
    }

    public function testConsumableBreakdownSavesReadsEditsAndPreservesDisabledRows(): void
    {
        $project = Fixture::ledger()->saveProject(Fixture::project());
        $id = $project['project']['id'];
        $service = Fixture::scenarios();
        $input = (new InvestmentScenarioCalculator())->referenceExample();
        $input['operating_cost_basis'] = 'occupied_room_night';
        $input['consumables_cost'] = ['mode' => 'derived', 'other_variable_cost_per_night' => 0, 'items' => [
            ['id' => 'amenity', 'name' => '合成一次性用品', 'enabled' => true, 'package_price' => 20, 'package_quantity' => 100,
                'unit' => 'piece', 'usage_quantity' => 2, 'usage_basis' => 'occupied_room_night', 'source_label' => '合成测试', 'as_of' => '2026-10-01'],
            ['id' => 'disabled', 'name' => '停用草稿', 'enabled' => false, 'unit' => 'ml', 'usage_basis' => 'guest_night'],
        ]];
        $saved = $service->save($id, ['expected_version' => 1, 'scenario' => $input]);
        $read = $service->detail($id);
        self::assertSame($saved['input'], $read['input']);
        self::assertSame($saved['result'], $read['result']);
        self::assertSame($saved['content_digest'], $read['content_digest']);
        self::assertSame('exact', $read['readback']);
        self::assertCount(2, $read['input']['consumables_cost']['items']);
        self::assertFalse($read['input']['consumables_cost']['items'][1]['enabled']);
        self::assertEqualsWithDelta(0.4, $read['result']['effective_operating_cost_per_night'], 1e-10);
        $input['consumables_cost']['items'][0]['usage_quantity'] = 3;
        $edited = $service->save($id, ['expected_version' => $saved['project_version'], 'scenario' => $input]);
        self::assertSame(2, $edited['scenario_version']);
        self::assertNotSame($saved['content_digest'], $edited['content_digest']);
        self::assertEqualsWithDelta(0.6, $edited['result']['effective_operating_cost_per_night'], 1e-10);
        self::assertSame($edited['result'], $service->detail($id)['result']);
        self::assertSame(0, Db::name('investment_payback_entries')->count());
    }

    public function testOldProjectAndMissingDraftDoNotBecomeZeroCashOrZeroPayback(): void
    {
        $project = Fixture::ledger()->saveProject(Fixture::project());
        $service = Fixture::scenarios();
        $old = $service->detail($project['project']['id']);
        self::assertNull($old['input']);
        self::assertNull($old['result']);
        self::assertSame('no_saved_scenario', $old['readback']);
        $saved = $service->save($project['project']['id'], ['expected_version' => 1, 'scenario' => ['scenario_name' => '缺参草稿', 'as_of' => '2026-10-01']]);
        self::assertSame('inputs_missing', $saved['result']['status']);
        self::assertNull($saved['result']['payback']);
        self::assertNotEmpty($saved['result']['missing_fields']);
        self::assertSame([], $saved['result']['annual_rows']);
        self::assertSame(0, Db::name('investment_payback_entries')->count());
    }

    public function testUnavailableProcurementCatalogAndReferenceRejectWithoutAnyLedgerWrites(): void
    {
        $project = Fixture::ledger()->saveProject(Fixture::project());
        $id = $project['project']['id'];
        $service = Fixture::scenarios();
        self::assertFalse(class_exists(\app\service\ConsumablesProcurementReferenceService::class));
        $before = $this->ledgerRows();
        $this->failure(fn() => $service->consumablesReference($id), 503);
        self::assertSame($before, $this->ledgerRows());
        $input = (new InvestmentScenarioCalculator())->referenceExample();
        $input['operating_cost_basis'] = 'occupied_room_night';
        $input['consumables_cost'] = ['mode' => 'derived', 'other_variable_cost_per_night' => 0, 'items' => [
            ['id' => 'quoted', 'name' => '合成引用', 'enabled' => true, 'unit' => 'piece', 'usage_basis' => 'occupied_room_night',
                'package_price' => 20, 'package_quantity' => 100, 'usage_quantity' => 2,
                'source_label' => '合成测试', 'as_of' => '2026-10-01',
                'procurement_reference' => ['catalog_id' => 'synthetic-unavailable-catalog', 'source_sha256' => str_repeat('a', 64),
                    'item_id' => 1, 'tier_id' => 'd', 'confirmed_for_scenario' => true]],
        ]];
        foreach (['preview', 'save'] as $action) {
            try {
                $service->{$action}($id, ['expected_version' => 1, 'scenario' => $input]);
                self::fail('A submitted reference cannot bypass an unavailable procurement module');
            } catch (InvalidArgumentException $error) {
                self::assertStringContainsString('采购参考目录尚未接入', $error->getMessage());
            }
            self::assertSame($before, $this->ledgerRows(), $action);
        }
        self::assertSame(['procurement_reference' => false, 'actual_consumables_reference' => true], $service->detail($id)['capabilities']);
    }

    public function testPreviewHasNoWritesAndChangedStaleSaveIsRejected(): void
    {
        $project = Fixture::ledger()->saveProject(Fixture::project());
        $id = $project['project']['id'];
        $service = Fixture::scenarios();
        $example = (new InvestmentScenarioCalculator())->referenceExample();
        $preview = $service->preview($id, ['scenario' => $example]);
        self::assertSame('preview_only', $preview['readback']);
        self::assertSame(1, Db::name('investment_payback_events')->count());
        self::assertSame(1, $service->detail($id)['project_version']);
        $service->save($id, ['expected_version' => 1, 'scenario' => $example]);
        $example['years'] = 5;
        $this->failure(fn() => $service->save($id, ['expected_version' => 1, 'scenario' => $example]), 409);
        self::assertSame(10, $service->detail($id)['input']['years']);
        self::assertSame(1, $service->detail($id)['scenario_version']);
    }

    public function testMaximumManualCostRowsCanBeSavedAndResubmittedWithoutLosingSnapshots(): void
    {
        $project = Fixture::ledger()->saveProject(Fixture::project());
        $id = $project['project']['id'];
        $service = Fixture::scenarios();
        $input = (new InvestmentScenarioCalculator())->referenceExample();
        $input['operating_cost_basis'] = 'occupied_room_night';
        $input['consumables_cost'] = ['mode' => 'derived', 'other_variable_cost_per_night' => 0, 'items' => []];
        foreach (range(1, 100) as $number) {
            $input['consumables_cost']['items'][] = ['id' => 'manual-' . $number, 'name' => '合成用品 ' . $number,
                'enabled' => true, 'unit' => 'piece', 'package_price' => 20, 'package_quantity' => 100,
                'usage_quantity' => 2, 'usage_basis' => 'occupied_room_night',
                'source_label' => '手工合成测试', 'as_of' => '2026-10-01'];
        }
        $saved = $service->save($id, ['expected_version' => 1, 'scenario' => $input]);
        self::assertCount(100, $saved['input']['consumables_cost']['items']);
        self::assertEqualsWithDelta(40.0, $saved['result']['effective_operating_cost_per_night'], 1e-10);
        $repeated = $service->save($id, ['expected_version' => $saved['project_version'], 'scenario' => $saved['input']]);
        self::assertSame('unchanged', $repeated['action']);
        self::assertSame($saved['content_digest'], $repeated['content_digest']);
        self::assertSame($saved['input'], $service->detail($id)['input']);
        self::assertSame(0, Db::name('investment_payback_entries')->count());
    }

    public function testEmptyActualEvidenceFieldsSaveAndReadBackWithTheIntegratedEvidenceModule(): void
    {
        self::assertTrue(class_exists(\app\service\ActualConsumablesScenarioReferenceService::class));
        $id = Fixture::ledger()->saveProject(Fixture::project())['project']['id'];
        $service = Fixture::scenarios();
        $input = (new InvestmentScenarioCalculator())->referenceExample();
        $input['cost_evidence_snapshot_id'] = null;
        $input['cost_evidence_digest'] = '';
        $input['cost_evidence_confirmed'] = false;
        $preview = $service->preview($id, ['scenario' => $input]);
        self::assertSame('preview_only', $preview['readback']);
        self::assertSame(1, Db::name('investment_payback_events')->count());
        $saved = $service->save($id, ['expected_version' => 1, 'scenario' => $input]);
        $read = $service->detail($id);
        self::assertSame('exact', $read['readback']);
        foreach (['input', 'result', 'content_digest', 'scenario_version'] as $key) self::assertSame($saved[$key], $read[$key]);
        self::assertNull($read['input']['cost_evidence_snapshot_id']);
        self::assertNull($read['input']['cost_evidence_digest']);
        self::assertFalse($read['input']['cost_evidence_confirmed']);
        self::assertTrue($read['capabilities']['actual_consumables_reference']);
        $before = $this->ledgerRows();
        self::assertSame('unchanged', $service->save($id, ['expected_version' => 1, 'scenario' => $input])['action']);
        self::assertSame($before, $this->ledgerRows());
    }

    public function testInvalidOrUnresolvedActualEvidenceBindingRejectsWithoutChangingTheSavedScenarioOrCash(): void
    {
        self::assertTrue(class_exists(\app\service\ActualConsumablesScenarioReferenceService::class));
        // This isolated ledger fixture has no operating evidence. A complete binding
        // must reach the integrated evidence lookup and fail explicitly as missing.
        Db::execute('CREATE TABLE ' . \app\service\OperatingEvidenceSnapshotStore::TABLE . ' (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, '
            . 'hotel_id INTEGER NOT NULL, kind TEXT NOT NULL, period_month TEXT NOT NULL)');
        $ledger = Fixture::ledger();
        $id = $ledger->saveProject(Fixture::project())['project']['id'];
        $cash = $ledger->saveEntry($id, ['kind' => 'investment', 'amount' => '1000.01', 'date' => '2026-10-01',
            'precision' => 'day', 'source' => '合成实际出资', 'client_request_id' => 'optional-module-cash']);
        $service = Fixture::scenarios();
        $input = (new InvestmentScenarioCalculator())->referenceExample();
        $saved = $service->save($id, ['expected_version' => $cash['project']['version'], 'scenario' => $input]);
        $before = $this->ledgerRows();
        foreach ([['cost_evidence_snapshot_id' => 5], ['cost_evidence_digest' => str_repeat('a', 64)],
            ['cost_evidence_confirmed' => true], ['cost_evidence_snapshot_id' => 5,
                'cost_evidence_digest' => str_repeat('a', 64), 'cost_evidence_confirmed' => true]] as $binding) {
            foreach (['preview', 'save'] as $action) {
                try {
                    $service->{$action}($id, ['expected_version' => $saved['project_version'], 'scenario' => array_replace($input, $binding)]);
                    self::fail('An invalid or unresolved actual-cost evidence binding must fail explicitly');
                } catch (InvalidArgumentException $error) {
                    self::assertNotCount(3, $binding, 'A complete binding must reach the scoped evidence lookup');
                    self::assertStringContainsString('实际耗材引用须提供完整快照与明确采用确认', $error->getMessage());
                } catch (RuntimeException $error) {
                    self::assertCount(3, $binding);
                    self::assertSame(404, $error->getCode());
                    self::assertStringContainsString('实际耗材证据不存在或与当前项目酒店不一致', $error->getMessage());
                }
                self::assertSame($before, $this->ledgerRows(), $action . ':' . implode(',', array_keys($binding)));
                self::assertSame($saved['content_digest'], $service->detail($id)['content_digest']);
            }
        }
        self::assertSame($cash['entries'], $ledger->detail($id)['entries']);
    }

    public function testLibraryHistoryVersionCopyAndCompareUseExactProjectSnapshotsWithoutCashWrites(): void
    {
        $ledger = Fixture::ledger();
        $first = $ledger->saveProject(Fixture::project());
        $second = $ledger->saveProject(Fixture::project(['project_name' => '合成比较项目', 'client_request_id' => 'scenario-second-project']));
        $id = $first['project']['id'];
        $otherId = $second['project']['id'];
        $service = Fixture::scenarios();
        $input = (new InvestmentScenarioCalculator())->referenceExample();
        $saved = $service->save($id, ['expected_version' => 1, 'scenario' => $input]);
        $other = $service->save($otherId, ['expected_version' => 1, 'scenario' => $input]);
        $library = $service->library($id);
        self::assertSame(['conservative', 'base', 'optimistic'], array_column($library['items'], 'scenario_key'));
        self::assertSame($saved['scenario_event_id'], $library['items'][1]['scenario_event_id']);
        self::assertSame('exact', $library['items'][1]['readback']);
        self::assertSame('no_saved_scenario', $library['items'][0]['readback']);
        $history = $service->history($id);
        self::assertCount(1, $history['items']);
        self::assertSame($saved['scenario_event_id'], $history['items'][0]['scenario_event_id']);
        $version = $service->version($id, $saved['scenario_event_id']);
        self::assertTrue($version['historical_version']);
        foreach (['input', 'result', 'content_digest'] as $key) self::assertSame($saved[$key], $version[$key]);
        $this->failure(fn() => $service->version($id, $other['scenario_event_id']), 404);
        $copied = $service->copyVersion($id, $saved['scenario_event_id'], ['expected_version' => $saved['project_version'], 'scenario_key' => 'conservative']);
        self::assertSame('exact', $copied['readback']);
        self::assertSame('conservative', $copied['scenario_key']);
        self::assertNotSame($saved['scenario_event_id'], $copied['scenario_event_id']);
        self::assertSame($saved['input'], $copied['input']);
        self::assertSame($saved['content_digest'], $copied['content_digest']);
        self::assertSame($saved['result'], $service->version($id, $saved['scenario_event_id'])['result']);
        self::assertCount(2, $service->history($id)['items']);
        $comparison = $service->compare(['selections' => [['project_id' => $id, 'scenario_key' => 'base'], ['project_id' => $otherId, 'scenario_key' => 'base']]]);
        self::assertCount(2, $comparison['items']);
        self::assertSame([$id, $otherId], array_column($comparison['items'], 'project_id'));
        self::assertSame(['exact', 'exact'], array_column($comparison['items'], 'readback'));
        self::assertSame('scenario_assumption', $comparison['quality_status']);
        self::assertFalse($comparison['ranking_performed']);
        self::assertFalse($comparison['actual_cash_written']);
        self::assertSame(0, Db::name('investment_payback_entries')->count());
    }

    public function testCrossTenantAndUnauthorizedHotelCannotReadOrPreviewOrSave(): void
    {
        $project = Fixture::ledger()->saveProject(Fixture::project());
        $id = $project['project']['id'];
        $foreign = Fixture::scenarios(20, 8, [90]);
        $sameTenantWrongHotel = Fixture::scenarios(10, 8, [81]);
        $example = (new InvestmentScenarioCalculator())->referenceExample();
        foreach ([$foreign, $sameTenantWrongHotel] as $index => $service) {
            $code = $index === 0 ? 404 : 403;
            $this->failure(fn() => $service->detail($id), $code);
            $this->failure(fn() => $service->library($id), $code);
            $this->failure(fn() => $service->history($id), $code);
            $this->failure(fn() => $service->version($id, 1), $code);
            $this->failure(fn() => $service->copyVersion($id, 1, ['expected_version' => 1, 'scenario_key' => 'base']), $code);
            $this->failure(fn() => $service->compare(['selections' => [['project_id' => $id, 'scenario_key' => 'base'], ['project_id' => $id, 'scenario_key' => 'conservative']]]), $code);
            $this->failure(fn() => $service->consumablesReference($id), $code);
            $this->failure(fn() => $service->preview($id, ['scenario' => $example]), $code);
            $this->failure(fn() => $service->save($id, ['expected_version' => 1, 'scenario' => $example]), $code);
        }
        self::assertSame(0, Db::name('investment_payback_events')->where('event_type', 'scenario_saved')->count());
    }

    public function testArchivedProjectsAreReadOnlyAndInvalidScenarioHasNoSideEffects(): void
    {
        $ledger = Fixture::ledger();
        $project = $ledger->saveProject(Fixture::project());
        $id = $project['project']['id'];
        $service = Fixture::scenarios();
        $input = (new InvestmentScenarioCalculator())->referenceExample();
        $saved = $service->save($id, ['expected_version' => 1, 'scenario' => $input]);
        $input['occupancy_first_year'] = 1.1;
        try { $service->save($id, ['expected_version' => $saved['project_version'], 'scenario' => $input]); self::fail('Invalid occupancy must fail'); }
        catch (InvalidArgumentException $exception) { self::assertNotEmpty($exception->getMessage()); }
        self::assertSame(1, $service->detail($id)['scenario_version']);
        $ledger->archive($id, ['reason' => '合成退出']);
        $read = $service->detail($id);
        $this->failure(fn() => $service->save($id, ['expected_version' => $read['project_version'], 'scenario' => $read['input']]), 409);
        self::assertSame($saved['content_digest'], $read['content_digest']);
    }

    public function testHistoricalModelReadPreservesSnapshotAndUpgradeAppendsRatherThanOverwrites(): void
    {
        $project = Fixture::ledger()->saveProject(Fixture::project());
        $id = $project['project']['id'];
        $service = Fixture::scenarios();
        $saved = $service->save($id, ['expected_version' => 1, 'scenario' => (new InvestmentScenarioCalculator())->referenceExample()]);
        $event = Db::name('investment_payback_events')->where('id', $saved['scenario_event_id'])->find();
        $payload = json_decode($event['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        $historical = &$payload['scenario_snapshot'];
        $historical['model_version'] = 'investment-scenario-v1';
        $historical['result']['model_version'] = 'investment-scenario-v1';
        $historical['result']['annual_rows'][0]['pretax_cash_proxy'] = -123.45;
        $historical['content_digest'] = (new KnowledgeContentDigestService())->digest(['input' => $historical['input'], 'result' => $historical['result']]);
        $raw = json_encode($payload, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        Db::name('investment_payback_events')->where('id', $saved['scenario_event_id'])->update(['payload_json' => $raw]);

        $read = $service->detail($id);
        self::assertSame('historical_snapshot', $read['model_status']);
        self::assertSame('exact', $read['readback']);
        self::assertSame($historical['result'], $read['result']);
        self::assertSame(1, $read['scenario_version']);
        $preview = $service->preview($id, ['scenario' => $read['input']]);
        self::assertSame(InvestmentScenarioCalculator::MODEL_VERSION, $preview['model_version']);
        self::assertSame(2, Db::name('investment_payback_events')->count());
        self::assertSame($historical['content_digest'], $service->detail($id)['content_digest']);

        $upgraded = $service->save($id, ['expected_version' => $read['project_version'], 'scenario' => $read['input']]);
        self::assertSame(2, $upgraded['scenario_version']);
        self::assertSame('current', $upgraded['model_status']);
        self::assertSame(InvestmentScenarioCalculator::MODEL_VERSION, $upgraded['model_version']);
        self::assertSame($raw, Db::name('investment_payback_events')->where('id', $saved['scenario_event_id'])->value('payload_json'));
        self::assertSame(0, Db::name('investment_payback_entries')->count());
    }

    public function testCorruptStoredSnapshotFailsClosedAndHistoryIsNotDeleted(): void
    {
        $project = Fixture::ledger()->saveProject(Fixture::project());
        $id = $project['project']['id'];
        $service = Fixture::scenarios();
        $saved = $service->save($id, ['expected_version' => 1, 'scenario' => (new InvestmentScenarioCalculator())->referenceExample()]);
        $event = Db::name('investment_payback_events')->where('id', $saved['scenario_event_id'])->find();
        $payload = json_decode($event['payload_json'], true);
        $payload['scenario_snapshot']['input']['rooms'] = 999;
        Db::name('investment_payback_events')->where('id', $saved['scenario_event_id'])->update(['payload_json' => json_encode($payload)]);
        $this->failure(fn() => $service->detail($id), 409);
        self::assertSame(2, Db::name('investment_payback_events')->count());
    }

    private function ledgerRows(): array
    {
        $rows = [];
        foreach (['investment_payback_projects', 'investment_payback_entries', 'investment_payback_events'] as $table) {
            $rows[$table] = Db::name($table)->order('id')->select()->toArray();
        }
        return $rows;
    }

    private function failure(callable $action, int $code): void
    {
        try { $action(); self::fail('Expected scope/version failure'); }
        catch (RuntimeException $exception) { self::assertSame($code, $exception->getCode(), $exception->getMessage()); }
    }
}
