<?php
declare(strict_types=1);

use app\service\InvestmentOperatingBridgeService;
use PHPUnit\Framework\TestCase;
use Tests\Support\InvestmentScenarioFixture as Fixture;
use think\facade\Config;
use think\facade\Db;

require_once __DIR__ . '/Support/InvestmentScenarioFixture.php';

final class InvestmentOperatingBridgeLedgerTest extends TestCase
{
    public function testSavedLedgerReadbackIsVisibleInBridgeWithoutWritingCashOrIncludingPlans(): void
    {
        (new \think\App())->initialize();
        restore_error_handler();
        restore_exception_handler();
        $original = Config::get('database');
        $path = sys_get_temp_dir() . '/investment-scenario-test-bridge-' . bin2hex(random_bytes(5)) . '.sqlite';
        try {
            Fixture::connect($path);
            Fixture::schema();
            $ledger = Fixture::ledger(10, 7, [80, 81]);
            $saved = $ledger->saveProject(Fixture::project(['history_complete_through' => '2026-09-30']));
            $projectId = (int)$saved['project']['id'];
            $ledger->saveEntry($projectId, ['kind' => 'investment', 'amount' => '1000.01', 'date' => '2026-09-01', 'source' => 'synthetic-bank-reference#1', 'client_request_id' => 'bridge-investment-1']);
            $receipt = $ledger->saveEntry($projectId, ['kind' => 'recovery', 'amount' => '300.02', 'date' => '2026-09-15', 'source' => 'synthetic-bank-reference#2', 'client_request_id' => 'bridge-recovery-1']);
            $ledger->saveEntry($projectId, ['kind' => 'recovery', 'amount' => '9000.00', 'date' => '2026-09-20', 'is_planned' => true, 'source' => 'synthetic-plan#1', 'client_request_id' => 'bridge-plan-1']);
            $bridge = new InvestmentOperatingBridgeService($ledger, null, static fn(): string => '2026-10-02');
            $unconfirmed = $bridge->overview(10, [80], 80, '2026-09');
            self::assertSame('partial', $unconfirmed['status']);
            self::assertNull($unconfirmed['totals']);
            self::assertSame('300.02', $unconfirmed['recorded_totals']['net_actual_recovered']);
            $projectAfterEntries = $ledger->detail($projectId, '2026-09-30')['project'];
            self::assertNull($projectAfterEntries['history_complete_through']);
            $ledger->saveProject(['id' => $projectId, 'version' => $projectAfterEntries['version'], 'history_complete_through' => '2026-09-30', 'as_of' => '2026-09-30']);
            $entriesBeforeRead = Db::name('investment_payback_entries')->count();
            $eventsBeforeRead = Db::name('investment_payback_events')->count();
            $result = $bridge->overview(10, [80], 80, '2026-09');
            self::assertSame('ready', $result['status']);
            self::assertSame('1000.01', $result['totals']['actual_invested']);
            self::assertSame('300.02', $result['totals']['net_actual_recovered']);
            self::assertSame('699.99', $result['totals']['unrecovered']);
            self::assertSame($entriesBeforeRead, Db::name('investment_payback_entries')->count());
            self::assertSame($eventsBeforeRead, Db::name('investment_payback_events')->count());
            self::assertSame('manual_unverified', $result['quality']['source_quality_status']);

            $actualReceipt = array_values(array_filter($receipt['entries'], static fn(array $row): bool => $row['kind'] === 'recovery' && !$row['is_planned']))[0];
            $ledger->saveEntry($projectId, ['id' => $actualReceipt['id'], 'version' => $actualReceipt['version'], 'amount' => '350.03', 'as_of' => '2026-09-30']);
            $changed = $bridge->overview(10, [80], 80, '2026-09');
            self::assertSame('partial', $changed['status']);
            self::assertNull($changed['totals']);
            self::assertSame('350.03', $changed['recorded_totals']['net_actual_recovered']);
            $projectAfterEdit = $ledger->detail($projectId, '2026-09-30')['project'];
            $ledger->saveProject(['id' => $projectId, 'version' => $projectAfterEdit['version'], 'history_complete_through' => '2026-09-30', 'as_of' => '2026-09-30']);
            $updated = $bridge->overview(10, [80], 80, '2026-09');
            self::assertSame('350.03', $updated['totals']['net_actual_recovered']);
            self::assertSame('649.98', $updated['totals']['unrecovered']);
            self::assertSame($projectId, $updated['projects'][0]['project_id']);
            self::assertSame('missing', $bridge->overview(10, [81], 81, '2026-09')['status']);
            $wrongTenant = $bridge->overview(20, [80], 80, '2026-09');
            self::assertSame('read_failed', $wrongTenant['status']);
            self::assertNull($wrongTenant['projects']);
        } finally {
            Db::connect()->close();
            Config::set($original, 'database');
            Db::connect(null, true);
            if (is_file($path)) unlink($path);
        }
    }

    public function testLegalCumulativeAmountBeyondSingleEntryLimitReadsExactlyWithoutCashWrites(): void
    {
        (new \think\App())->initialize();
        restore_error_handler();
        restore_exception_handler();
        $original = Config::get('database');
        $path = sys_get_temp_dir() . '/investment-scenario-test-bridge-large-' . bin2hex(random_bytes(5)) . '.sqlite';
        try {
            Fixture::connect($path);
            Fixture::schema();
            $ledger = Fixture::ledger();
            $saved = $ledger->saveProject(Fixture::project());
            $projectId = (int)$saved['project']['id'];
            foreach ([1, 2] as $number) {
                $ledger->saveEntry($projectId, [
                    'kind' => 'investment', 'amount' => '999999999999.99',
                    'date' => '2026-09-0' . $number, 'source' => 'synthetic-large-cash-reference#' . $number,
                    'client_request_id' => 'bridge-large-investment-' . $number,
                ]);
            }
            $projectAfterEntries = $ledger->detail($projectId, '2026-09-30')['project'];
            self::assertNull($projectAfterEntries['history_complete_through']);
            $ledger->saveProject([
                'id' => $projectId, 'version' => $projectAfterEntries['version'],
                'history_complete_through' => '2026-09-30', 'as_of' => '2026-09-30',
            ]);
            $entriesBeforeRead = Db::name('investment_payback_entries')->select()->toArray();
            $eventsBeforeRead = Db::name('investment_payback_events')->select()->toArray();
            $bridge = new InvestmentOperatingBridgeService($ledger, null, static fn(): string => '2026-10-03');
            $result = $bridge->overview(10, [80], 80, '2026-09');
            self::assertSame('ready', $result['status']);
            self::assertSame('1999999999999.98', $result['totals']['actual_invested']);
            self::assertSame('1999999999999.98', $result['totals']['unrecovered']);
            self::assertSame('0.00', $result['totals']['net_actual_recovered']);
            self::assertSame('0.00', $result['totals']['excess_return']);
            self::assertSame($result['totals'], $result['recorded_totals']);
            self::assertSame('1999999999999.98', $result['projects'][0]['summary']['invested_amount']);
            self::assertSame(1, $result['coverage']['linked_project_count']);
            self::assertSame($entriesBeforeRead, Db::name('investment_payback_entries')->select()->toArray());
            self::assertSame($eventsBeforeRead, Db::name('investment_payback_events')->select()->toArray());
            self::assertSame(0, $result['boundaries']['external_write_count']);
        } finally {
            Db::connect()->close();
            Config::set($original, 'database');
            Db::connect(null, true);
            if (is_file($path)) unlink($path);
        }
    }
}
