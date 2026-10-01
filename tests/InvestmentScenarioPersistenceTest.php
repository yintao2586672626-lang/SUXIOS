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

    private function failure(callable $action, int $code): void
    {
        try { $action(); self::fail('Expected scope/version failure'); }
        catch (RuntimeException $exception) { self::assertSame($code, $exception->getCode(), $exception->getMessage()); }
    }
}
