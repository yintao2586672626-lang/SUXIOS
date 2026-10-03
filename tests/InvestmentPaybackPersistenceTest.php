<?php
declare(strict_types=1);

namespace Tests;

use app\controller\InvestmentPayback;
use app\model\User;
use app\model\SystemConfig;
use app\model\Role;
use app\service\HotelScopeService;
use app\service\InvestmentPaybackService;
use app\service\PermissionService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;
use think\Request;

/** Synthetic isolated SQLite: never connects to the application's business database. */
final class InvestmentPaybackPersistenceTest extends TestCase
{
    private static array $originalConfig;
    private static string $path;
    private Request $originalRequest;

    public static function setUpBeforeClass(): void
    {
        (new App(dirname(__DIR__)))->initialize();
        self::$originalConfig = Config::get('database');
        self::$path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'investment_payback_' . getmypid() . '_' . bin2hex(random_bytes(6)) . '.sqlite';
        Config::set(['default' => 'investment_payback_test', 'connections' => ['investment_payback_test' => [
            'type' => 'sqlite', 'database' => self::$path, 'prefix' => '', 'fields_strict' => true,
        ]]], 'database');
        Db::connect(null, true);
    }

    public static function tearDownAfterClass(): void
    {
        Db::connect('investment_payback_test')->close();
        Config::set(self::$originalConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$path);
    }

    protected function setUp(): void
    {
        $this->originalRequest = request();
        foreach (['investment_payback_projects', 'investment_payback_entries', 'investment_payback_events', 'hotels', 'system_config'] as $table) {
            Db::execute('DROP TABLE IF EXISTS ' . $table);
        }
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, status INTEGER NOT NULL)');
        Db::execute('INSERT INTO hotels VALUES (80,10,1),(81,10,1),(90,20,1)');
        Db::execute('CREATE TABLE system_config (id INTEGER PRIMARY KEY AUTOINCREMENT, config_key TEXT NOT NULL UNIQUE, '
            . 'config_value TEXT NULL, description TEXT NOT NULL DEFAULT "", create_time INTEGER NULL, update_time INTEGER NULL)');
        Db::execute('CREATE TABLE investment_payback_projects ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, hotel_id INTEGER NULL, '
            . 'project_name TEXT NOT NULL, investor_name TEXT NOT NULL, basis TEXT NOT NULL, currency TEXT NOT NULL, status TEXT NOT NULL, '
            . 'first_invested_on TEXT NULL, expected_monthly_amount TEXT NULL, expected_source TEXT NOT NULL, forecast_as_of TEXT NOT NULL, '
            . 'history_complete_through TEXT NULL, opening_as_of TEXT NULL, opening_invested TEXT NULL, opening_recovered TEXT NULL, opening_source TEXT NOT NULL, '
            . 'notes TEXT NOT NULL, client_request_id TEXT NOT NULL, input_digest TEXT NOT NULL, version INTEGER NOT NULL, '
            . 'created_by INTEGER NOT NULL, updated_by INTEGER NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, archived_at TEXT NULL, archive_reason TEXT NOT NULL DEFAULT "", '
            . 'UNIQUE (tenant_id,created_by,client_request_id))');
        Db::execute('CREATE TABLE investment_payback_entries ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, project_id INTEGER NOT NULL, kind TEXT NOT NULL, amount TEXT NOT NULL, '
            . 'business_date TEXT NOT NULL, precision TEXT NOT NULL, is_planned INTEGER NOT NULL, confirmed_zero INTEGER NOT NULL, category TEXT NOT NULL, source TEXT NOT NULL, notes TEXT NOT NULL, '
            . 'original_entry_id INTEGER NULL, client_request_id TEXT NOT NULL, input_digest TEXT NOT NULL, version INTEGER NOT NULL, created_by INTEGER NOT NULL, updated_by INTEGER NOT NULL, '
            . 'created_at TEXT NOT NULL, updated_at TEXT NOT NULL, voided_at TEXT NULL, voided_by INTEGER NULL, void_reason TEXT NOT NULL DEFAULT "", '
            . 'UNIQUE (tenant_id,project_id,created_by,client_request_id))');
        Db::execute('CREATE TABLE investment_payback_events ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, project_id INTEGER NOT NULL, entry_id INTEGER NULL, actor_id INTEGER NOT NULL, '
            . 'event_type TEXT NOT NULL, project_version INTEGER NOT NULL, payload_json TEXT NOT NULL, created_at TEXT NOT NULL)');
    }

    protected function tearDown(): void
    {
        app()->instance('request', $this->originalRequest);
    }

    public function testCreateReadRetryEditVoidAndArchiveRetainExactHistory(): void
    {
        $service = $this->service();
        $input = $this->project();
        $first = $service->saveProject($input);
        $id = $first['project']['id'];
        self::assertSame($first, $service->detail($id));
        self::assertNull($first['summary']['invested_amount']);
        self::assertSame($id, $service->saveProject($input)['project']['id']);
        self::assertSame(1, Db::name('investment_payback_projects')->count());
        $entryInput = $this->entry('investment', '1000.01', 'entry-invest-001');
        $withInvestment = $service->saveEntry($id, $entryInput);
        $investmentId = $withInvestment['entries'][0]['id'];
        self::assertSame('1000.01', $withInvestment['entries'][0]['amount']);
        self::assertSame(2, $withInvestment['project']['version']);
        self::assertSame($withInvestment, $service->detail($id));
        $retry = $service->saveEntry($id, $entryInput);
        self::assertSame(1, count($retry['entries']));
        self::assertSame(2, $retry['project']['version']);
        $recovery = $service->saveEntry($id, $this->entry('recovery', '300.01', 'entry-recover-001'));
        self::assertSame('700.00', $recovery['summary']['unrecovered_amount']);
        $recoveryId = $recovery['entries'][1]['id'];
        $edited = $service->saveEntry($id, ['id' => $recoveryId, 'amount' => '350.02', 'expected_version' => 1, 'notes' => '银行到账更正']);
        self::assertSame('350.02', $edited['entries'][1]['amount']);
        self::assertSame(2, $edited['entries'][1]['version']);
        self::assertSame('649.99', $edited['summary']['unrecovered_amount']);
        $voided = $service->voidEntry($id, $recoveryId, ['reason' => '重复记账已核对', 'expected_version' => 2]);
        self::assertSame('0.00', $voided['summary']['net_recovered_amount']);
        self::assertSame('重复记账已核对', $voided['entries'][1]['void_reason']);
        self::assertNotNull($voided['entries'][1]['voided_at']);
        self::assertSame($voided, $service->detail($id));
        self::assertSame('350.02', $voided['audit_history'][0]['payload']['before']['amount']);
        self::assertSame('entry_voided', $voided['audit_history'][0]['event_type']);
        self::assertSame($investmentId, $voided['entries'][0]['id']);
        $archived = $service->archive($id, ['reason' => '项目退出后归档']);
        self::assertNotNull($archived['project']['archived_at']);
        self::assertSame([], $service->projects()['list']);
        self::assertSame(1, count($service->projects(['include_archived' => '1'])['list']));
        self::assertSame(2, count($service->detail($id)['entries']));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(409);
        $service->saveEntry($id, $this->entry('recovery', '1.00', 'entry-after-archive'));
    }

    public function testListSearchStatusAndTenantCannotSeeEachOther(): void
    {
        $service = $this->service();
        $own = $service->saveProject($this->project(['project_name' => '酒店甲', 'hotel_id' => 80, 'status' => 'operating']));
        $this->service(20, 8, [90])->saveProject($this->project(['project_name' => '酒店乙', 'hotel_id' => 90, 'client_request_id' => 'project-tenant20']));
        self::assertSame(1, $service->projects(['search' => '酒店甲', 'status' => 'operating'])['pagination']['total']);
        self::assertSame([], $service->projects(['search' => '酒店乙'])['list']);
        $other = $this->service(20, 8, [90]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(404);
        $other->detail($own['project']['id']);
    }

    public function testCrossProjectEntryEditsVoidsAndRefundLinksAreDenied(): void
    {
        $service = $this->service();
        $first = $service->saveProject($this->project());
        $second = $service->saveProject($this->project(['client_request_id' => 'project-second-001']));
        $saved = $service->saveEntry($first['project']['id'], $this->entry('recovery', '100.00', 'entry-first-recover'));
        $foreignEntry = $saved['entries'][0]['id'];
        foreach ([
            fn() => $service->saveEntry($second['project']['id'], ['id' => $foreignEntry, 'amount' => '20.00']),
            fn() => $service->voidEntry($second['project']['id'], $foreignEntry, ['reason' => '越权模拟']),
            fn() => $service->saveEntry($second['project']['id'], $this->entry('refund', '20.00', 'refund-cross-project') + ['original_entry_id' => $foreignEntry, 'notes' => '银行原付款退回']),
        ] as $action) {
            $this->assertFailure($action, 404);
        }
        self::assertSame([], $service->detail($second['project']['id'])['entries']);
        self::assertSame('100.00', $service->detail($first['project']['id'])['entries'][0]['amount']);
    }

    public function testOpeningPreventsOverlapAndAllowsOnlyLaterActualIncrement(): void
    {
        $service = $this->service();
        $saved = $service->saveProject($this->project(['opening_as_of' => '2026-08-31', 'opening_invested' => '1000.01', 'opening_recovered' => '300.01', 'opening_source' => '人工银行流水汇总']));
        $id = $saved['project']['id'];
        $overlap = $this->entry('investment', '1000.01', 'overlap-2026-0831', ['date' => '2026-08-31']);
        $this->assertFailure(fn() => $service->saveEntry($id, $overlap), 409);
        $monthOverlap = $this->entry('recovery', '50.00', 'overlap-month-0831', ['date' => '2026-08', 'precision' => 'month']);
        $this->assertFailure(fn() => $service->saveEntry($id, $monthOverlap), 409);
        $next = $service->saveEntry($id, $this->entry('recovery', '100.00', 'after-opening-001', ['date' => '2026-09-01']));
        self::assertSame('400.01', $next['summary']['net_recovered_amount']);
        self::assertSame('600.00', $next['summary']['unrecovered_amount']);
        $this->assertFailure(fn() => $service->saveProject(['id' => $id, 'opening_as_of' => '2026-09-02']), 409);
        self::assertSame('2026-08-31', $service->detail($id)['project']['opening_as_of']);
    }

    public function testActualHistoryFreezesInvestorAndHotelButDraftMayChange(): void
    {
        $service = $this->service(10, 7, [80, 81]);
        $saved = $service->saveProject($this->project());
        $id = $saved['project']['id'];
        $changed = $service->saveProject(['id' => $id, 'investor_name' => '投资人乙', 'hotel_id' => 80]);
        self::assertSame('投资人乙', $changed['project']['investor_name']);
        $service->saveEntry($id, $this->entry('investment', '1000.00', 'actual-freezes-scope'));
        foreach ([['investor_name' => '投资人丙'], ['hotel_id' => 81], ['hotel_id' => null]] as $change) {
            $this->assertFailure(fn() => $service->saveProject(['id' => $id] + $change), 409);
        }
    }

    public function testLinkedRefundCapAndReceiptChangesCannotLeaveOrOverdrawActiveRefunds(): void
    {
        $service = $this->service();
        $project = $service->saveProject($this->project());
        $id = $project['project']['id'];
        $service->saveEntry($id, $this->entry('investment', '1000.00', 'refund-cap-invest'));
        $receipt = $service->saveEntry($id, $this->entry('recovery', '100.01', 'refund-cap-receipt'));
        $originalId = $receipt['entries'][1]['id'];
        $refundInput = $this->entry('refund', '60.01', 'refund-cap-first', ['original_entry_id' => $originalId, 'notes' => '部分款项退款']);
        $first = $service->saveEntry($id, $refundInput);
        $retry = $service->saveEntry($id, $refundInput);
        self::assertSame(3, count($retry['entries']));
        self::assertSame($first['project']['version'], $retry['project']['version']);
        $refundId = $first['entries'][2]['id'];
        $this->assertValidationFailure(fn() => $service->saveEntry($id, $this->entry('refund', '40.01', 'refund-cap-overdraw', ['original_entry_id' => $originalId, 'notes' => '累计超过原款一分钱'])), '不能超过');
        $this->assertValidationFailure(fn() => $service->saveEntry($id, ['id' => $originalId, 'amount' => '60.00']), '不能小于');
        $this->assertValidationFailure(fn() => $service->saveEntry($id, ['id' => $originalId, 'date' => '2026-09-30']), '不能晚于');
        $this->assertValidationFailure(fn() => $service->saveEntry($id, ['id' => $originalId, 'kind' => 'refund', 'original_entry_id' => $originalId, 'notes' => '自关联应拒绝']), '自身');
        $this->assertFailure(fn() => $service->saveEntry($id, ['id' => $originalId, 'kind' => 'investment']), 409);
        $this->assertFailure(fn() => $service->voidEntry($id, $originalId, ['reason' => '先作废原款']), 409);
        $updated = $service->saveEntry($id, ['id' => $refundId, 'amount' => '50.01']);
        self::assertSame('50.00', $updated['summary']['net_recovered_amount']);
        $second = $service->saveEntry($id, $this->entry('refund', '50.00', 'refund-cap-second', ['original_entry_id' => $originalId, 'notes' => '余额全退']));
        self::assertSame('0.00', $second['summary']['net_recovered_amount']);
        self::assertSame('100.01', $second['entries'][1]['amount']);
        $service->voidEntry($id, $refundId, ['reason' => '核对修正退款']);
        $secondId = $second['entries'][3]['id'];
        $service->voidEntry($id, $secondId, ['reason' => '核对修正退款']);
        $voided = $service->voidEntry($id, $originalId, ['reason' => '退款已先作废，可修正原款']);
        self::assertNotNull($voided['entries'][1]['voided_at']);
        self::assertSame('0.00', $voided['summary']['net_recovered_amount']);
    }

    public function testListKeepsEachSavedCutoffAndCanReadExplicitSameDayScopeWithoutCombiningProjects(): void
    {
        $service = $this->service();
        $first = $service->saveProject($this->project(['forecast_as_of' => '2026-08-31']));
        $id = $first['project']['id'];
        $service->saveEntry($id, $this->entry('investment', '100.00', 'cutoff-first-invest', ['date' => '2026-08-01']));
        $service->saveEntry($id, $this->entry('investment', '20.00', 'cutoff-added-invest', ['date' => '2026-09-01']));
        $second = $service->saveProject($this->project(['forecast_as_of' => '2026-09-30', 'client_request_id' => 'cutoff-draft-second']));
        $rows = $service->projects()['list'];
        self::assertSame($second['project']['id'], $rows[0]['id']);
        self::assertSame('2026-09-30', $rows[0]['summary']['as_of']);
        foreach (['invested_amount', 'net_recovered_amount', 'unrecovered_amount', 'excess_recovered_amount', 'recovery_percent'] as $field) {
            self::assertNull($rows[0]['summary'][$field]);
        }
        self::assertSame('2026-08-31', $rows[1]['summary']['as_of']);
        self::assertSame('100.00', $rows[1]['summary']['invested_amount']);
        $current = $service->projects(['as_of' => '2026-09-30'])['list'];
        self::assertSame('2026-09-30', $current[1]['summary']['as_of']);
        self::assertSame('120.00', $current[1]['summary']['invested_amount']);
        self::assertSame('2026-08-31', $current[1]['forecast_as_of']);
    }

    public function testMutationsReadBackTheSelectedCutoffAndRejectInvalidScopeBeforeWriting(): void
    {
        $service = $this->service();
        $created = $service->saveProject($this->project(['forecast_as_of' => '2026-08-31', 'as_of' => '2026-09-30']));
        $id = $created['project']['id'];
        self::assertSame('2026-09-30', $created['summary']['as_of']);
        self::assertSame('2026-08-31', $created['project']['forecast_as_of']);
        $saved = $service->saveEntry($id, $this->entry('investment', '100.00', 'selected-asof-invest', ['as_of' => '2026-09-30']));
        self::assertSame('2026-09-30', $saved['summary']['as_of']);
        self::assertSame('100.00', $saved['summary']['invested_amount']);
        $this->assertValidationFailure(fn() => $service->saveEntry($id, $this->entry('recovery', '20.00', 'invalid-cutoff-save', ['as_of' => '2099-01-01'])), '不能晚于');
        self::assertSame(1, count($service->detail($id)['entries']));
        $voided = $service->voidEntry($id, $saved['entries'][0]['id'], ['reason' => '选定日期回读测试', 'as_of' => '2026-09-30']);
        self::assertSame('2026-09-30', $voided['summary']['as_of']);
        self::assertNull($voided['summary']['invested_amount']);
        $archived = $service->archive($id, ['as_of' => '2026-09-30']);
        self::assertSame('2026-09-30', $archived['summary']['as_of']);
    }

    public function testCommittedProjectEditReplaysExactLostReplyWithoutChangingHistoryOrAudit(): void
    {
        $service = $this->service();
        $created = $service->saveProject($this->project(['opening_as_of' => '2026-08-31',
            'opening_invested' => '1000.00', 'opening_recovered' => '0.00', 'opening_source' => '合成期初银行核对']));
        $id = $created['project']['id'];
        $request = ['id' => $id, 'expected_version' => $created['project']['version'],
            'opening_invested' => '1200.01', 'history_complete_through' => '2026-09-30', 'notes' => '合成期初更正'];
        $saved = $service->saveProject($request); // The committed POST reply is lost before the caller sees it.
        self::assertNull($saved['project']['history_complete_through']);
        self::assertSame($request['expected_version'] + 1, $saved['project']['version']);
        $durable = $this->durablePaybackSnapshot();
        $retry = $this->reconnectPaybackService()->saveProject($request);
        self::assertSame($saved, $retry);
        self::assertSame($durable, $this->durablePaybackSnapshot());
        self::assertSame($retry, $this->service()->detail($id));
    }

    public function testCommittedEntryEditReplaysExactLostReplyWithoutChangingCashOrAudit(): void
    {
        $service = $this->service();
        $id = $service->saveProject($this->project())['project']['id'];
        $cash = $service->saveEntry($id, $this->entry('investment', '100.01', 'lost-entry-reply'));
        $entryId = $cash['entries'][0]['id'];
        $request = ['id' => $entryId, 'expected_version' => $cash['entries'][0]['version'],
            'amount' => '125.37', 'date' => '2026-09-02', 'source' => '合成更正银行流水', 'notes' => '合成资金更正'];
        $saved = $service->saveEntry($id, $request); // Retry the same draft, including the old version.
        self::assertSame($request['expected_version'] + 1, $saved['entries'][0]['version']);
        $durable = $this->durablePaybackSnapshot();
        $retry = $this->reconnectPaybackService()->saveEntry($id, $request);
        self::assertSame($saved, $retry);
        self::assertSame($durable, $this->durablePaybackSnapshot());
        self::assertSame($retry, $this->service()->detail($id));
    }

    public function testCommittedUpdateReplayRejectsOtherActorsDifferentContentAndLaterTransitions(): void
    {
        foreach (['project', 'entry'] as $mode) {
            $edit = $this->committedUpdateFixture($mode, 'boundary-' . $mode);
            $durable = $this->durablePaybackSnapshot();
            $this->assertFailure(fn() => $this->repeatCommittedUpdate($edit, $edit['request'], $this->service(10, 8)), 409);
            $this->assertFailure(fn() => $this->repeatCommittedUpdate($edit, $edit['request'], $this->service(20, 7, [90])), 404);
            $this->assertFailure(fn() => $this->repeatCommittedUpdate($edit, array_merge($edit['request'], ['notes' => '另一份草稿'])), 409);
            $this->assertFailure(fn() => $this->repeatCommittedUpdate($edit, array_merge($edit['request'], ['expected_version' => 99])), 409);
            self::assertSame($durable, $this->durablePaybackSnapshot(), $mode);
            $canonical = $edit['request']; $canonical['expected_version'] = (string)$canonical['expected_version'];
            self::assertSame($edit['saved'], $this->repeatCommittedUpdate($edit, $canonical));
            self::assertSame($durable, $this->durablePaybackSnapshot(), $mode);
            $version = $mode === 'project' ? $edit['saved']['project']['version'] : $edit['saved']['entries'][0]['version'];
            $changed = $this->repeatCommittedUpdate($edit, array_merge($edit['request'], ['expected_version' => $version, 'notes' => '中间更正']));
            $version = $mode === 'project' ? $changed['project']['version'] : $changed['entries'][0]['version'];
            $restored = $this->repeatCommittedUpdate($edit, array_merge($edit['request'], ['expected_version' => $version]));
            self::assertSame($edit['request']['notes'], $mode === 'project' ? $restored['project']['notes'] : $restored['entries'][0]['notes']);
            $durable = $this->durablePaybackSnapshot();
            $this->assertFailure(fn() => $this->repeatCommittedUpdate($edit, $edit['request']), 409);
            self::assertSame($durable, $this->durablePaybackSnapshot(), $mode . ': identical content cannot bypass later versions');
        }
    }

    public function testCommittedUpdateReplayRequiresExactUntamperedScopedUpdateEvidence(): void
    {
        foreach (['project', 'entry'] as $mode) {
            foreach (['missing', 'json', 'before_version', 'before_scope', 'after_version', 'after_content', 'actor', 'digest'] as $damage) {
                $edit = $this->committedUpdateFixture($mode, $mode . '-' . $damage);
                $query = Db::name('investment_payback_events')->where('project_id', $edit['project_id'])
                    ->where('event_type', $mode === 'project' ? 'project_updated' : 'entry_updated');
                $event = $query->order('id', 'desc')->find();
                $payload = json_decode($event['payload_json'], true, 512, JSON_THROW_ON_ERROR);
                if ($damage === 'missing') Db::name('investment_payback_events')->where('id', $event['id'])->delete();
                elseif ($damage === 'json') Db::name('investment_payback_events')->where('id', $event['id'])->update(['payload_json' => '{broken']);
                elseif ($damage === 'actor') Db::name('investment_payback_events')->where('id', $event['id'])->update(['actor_id' => 8]);
                elseif ($damage === 'digest') Db::name($mode === 'project' ? 'investment_payback_projects' : 'investment_payback_entries')
                    ->where('id', $edit['record_id'])->update(['input_digest' => str_repeat('a', 64)]);
                else {
                    if ($damage === 'before_version') $payload['before']['version'] = 99;
                    elseif ($damage === 'before_scope') $payload['before']['tenant_id'] = 20;
                    elseif ($damage === 'after_version') $payload['after']['version'] = 99;
                    elseif ($damage === 'after_content') $payload['after']['notes'] = '被改坏的审计内容';
                    Db::name('investment_payback_events')->where('id', $event['id'])->update(['payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
                }
                $durable = $this->durablePaybackSnapshot();
                $this->assertFailure(fn() => $this->repeatCommittedUpdate($edit, $edit['request'], $this->reconnectPaybackService()), 409);
                self::assertSame($durable, $this->durablePaybackSnapshot(), $mode . ':' . $damage);
            }
        }
    }

    public function testRequestIdentityConflictAndOptimisticVersionProtectAgainstLostWrites(): void
    {
        $service = $this->service();
        $project = $service->saveProject($this->project());
        $id = $project['project']['id'];
        $this->assertFailure(fn() => $service->saveProject($this->project(['project_name' => '不同内容'])), 409);
        $input = $this->entry('investment', '1000.00', 'entry-identity-001');
        $first = $service->saveEntry($id, $input);
        $this->assertFailure(fn() => $service->saveEntry($id, array_merge($input, ['amount' => '1001.00'])), 409);
        $entryId = $first['entries'][0]['id'];
        $service->saveEntry($id, ['id' => $entryId, 'amount' => '1002.00', 'expected_version' => 1]);
        $this->assertFailure(fn() => $service->saveEntry($id, ['id' => $entryId, 'amount' => '1003.00', 'expected_version' => 1]), 409);
        $this->assertFailure(fn() => $service->saveProject(['id' => $id, 'notes' => 'stale', 'expected_version' => 1]), 409);
        self::assertSame('1002.00', $service->detail($id)['summary']['invested_amount']);
    }

    public function testCreateRetryProjectIdentityDoesNotRebindToEditedValues(): void
    {
        $input = $this->project(['client_request_id' => 'retry-project-original', 'history_complete_through' => null]);
        $first = $this->service()->saveProject($input);
        $id = $first['project']['id'];
        $before = $this->durablePaybackSnapshot();
        $service = $this->reconnectPaybackService();
        self::assertSame($first, $service->saveProject($input));
        self::assertSame($before, $this->durablePaybackSnapshot());

        $edited = $service->saveProject(['id' => $id, 'expected_version' => 1, 'project_name' => '已编辑项目名']);
        $before = $this->durablePaybackSnapshot();
        $service = $this->reconnectPaybackService();
        foreach ([$input, array_merge($input, ['project_name' => '已编辑项目名'])] as $retry) {
            $this->assertFailure(fn() => $service->saveProject($retry), 409);
            self::assertSame($before, $this->durablePaybackSnapshot());
            self::assertSame($edited, $service->detail($id));
        }
    }

    public function testCreateRetryProjectWithImmutableAuditStillSucceedsAfterNewCash(): void
    {
        $service = $this->service();
        $input = $this->project(['client_request_id' => 'retry-project-after-cash', 'history_complete_through' => null]);
        $id = $service->saveProject($input)['project']['id'];
        $created = Db::name('investment_payback_projects')->where('id', $id)->find();
        $event = Db::name('investment_payback_events')->where('project_id', $id)->where('event_type', 'project_created')->find();
        $payload = json_decode($event['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($created['input_digest'], $payload['create_input_digest']);
        $saved = $service->saveEntry($id, $this->entry('investment', '100.01', 'retry-project-new-cash'));
        self::assertSame(2, $saved['project']['version']);
        $before = $this->durablePaybackSnapshot();
        self::assertSame($saved, $this->reconnectPaybackService()->saveProject($input));
        self::assertSame($before, $this->durablePaybackSnapshot());
    }

    public function testCreateRetryEntryIdentityDoesNotRebindToEditedAmountOrDate(): void
    {
        $service = $this->service();
        $id = $service->saveProject($this->project())['project']['id'];
        $input = $this->entry('investment', '100.01', 'retry-entry-original');
        $first = $service->saveEntry($id, $input);
        $entryId = $first['entries'][0]['id'];
        $before = $this->durablePaybackSnapshot();
        $service = $this->reconnectPaybackService();
        self::assertSame($first, $service->saveEntry($id, $input));
        self::assertSame($before, $this->durablePaybackSnapshot());

        $edited = $service->saveEntry($id, ['id' => $entryId, 'expected_version' => 1, 'amount' => '200.02', 'date' => '2026-09-02']);
        $before = $this->durablePaybackSnapshot();
        $service = $this->reconnectPaybackService();
        foreach ([$input, array_merge($input, ['amount' => '200.02', 'date' => '2026-09-02'])] as $retry) {
            $this->assertFailure(fn() => $service->saveEntry($id, $retry), 409);
            self::assertSame($before, $this->durablePaybackSnapshot());
            self::assertSame($edited, $service->detail($id));
        }
    }

    public function testCreateRetrySupportsUneditedLegacyAuditsButRejectsMissingCreationEvidence(): void
    {
        $service = $this->service();
        $projectInput = $this->project(['client_request_id' => 'retry-legacy-project', 'history_complete_through' => null]);
        $project = $service->saveProject($projectInput);
        $id = $project['project']['id'];
        $entryInput = $this->entry('investment', '100.01', 'retry-legacy-entry');
        foreach (Db::name('investment_payback_events')->where('project_id', $id)->select()->toArray() as $event) {
            $payload = json_decode($event['payload_json'], true, 512, JSON_THROW_ON_ERROR);
            unset($payload['create_input_digest']);
            Db::name('investment_payback_events')->where('id', $event['id'])->update(['payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        }
        $before = $this->durablePaybackSnapshot();
        $service = $this->reconnectPaybackService();
        self::assertSame($id, $service->saveProject($projectInput)['project']['id']);
        self::assertSame($before, $this->durablePaybackSnapshot());

        $cash = $service->saveEntry($id, $entryInput);
        $event = Db::name('investment_payback_events')->where('entry_id', $cash['entries'][0]['id'])->where('event_type', 'entry_created')->find();
        $payload = json_decode($event['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        unset($payload['create_input_digest']);
        Db::name('investment_payback_events')->where('id', $event['id'])->update(['payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        $before = $this->durablePaybackSnapshot();
        $service = $this->reconnectPaybackService();
        self::assertSame($cash['entries'], $service->saveEntry($id, $entryInput)['entries']);
        self::assertSame($before, $this->durablePaybackSnapshot());

        Db::name('investment_payback_events')->where('project_id', $id)->where('event_type', 'entry_created')->delete();
        $before = $this->durablePaybackSnapshot();
        $service = $this->reconnectPaybackService();
        $this->assertFailure(fn() => $service->saveEntry($id, $entryInput), 409);
        self::assertSame($before, $this->durablePaybackSnapshot());
        self::assertCount(1, $service->detail($id)['entries']);

        $separateInput = $this->project(['client_request_id' => 'retry-no-audit-project', 'project_name' => '缺创建证据', 'history_complete_through' => null]);
        $separateId = $service->saveProject($separateInput)['project']['id'];
        Db::name('investment_payback_events')->where('project_id', $separateId)->where('event_type', 'project_created')->delete();
        $before = $this->durablePaybackSnapshot();
        $service = $this->reconnectPaybackService();
        $this->assertFailure(fn() => $service->saveProject($separateInput), 409);
        self::assertSame($before, $this->durablePaybackSnapshot());
    }

    public function testCreateRetryRejectsEditedLegacyRecordsAndInvalidImmutableDigest(): void
    {
        $service = $this->service();
        $projectInput = $this->project(['client_request_id' => 'retry-legacy-edited-project', 'history_complete_through' => null]);
        $id = $service->saveProject($projectInput)['project']['id'];
        $entryInput = $this->entry('investment', '100.01', 'retry-legacy-edited-entry');
        $entryId = $service->saveEntry($id, $entryInput)['entries'][0]['id'];
        foreach (Db::name('investment_payback_events')->where('project_id', $id)->select()->toArray() as $event) {
            $payload = json_decode($event['payload_json'], true, 512, JSON_THROW_ON_ERROR);
            unset($payload['create_input_digest']);
            Db::name('investment_payback_events')->where('id', $event['id'])->update(['payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        }
        $service->saveProject(['id' => $id, 'project_name' => '旧记录已编辑']);
        $service->saveEntry($id, ['id' => $entryId, 'expected_version' => 1, 'amount' => '200.02', 'date' => '2026-09-02']);
        $before = $this->durablePaybackSnapshot();
        $service = $this->reconnectPaybackService();
        $this->assertFailure(fn() => $service->saveProject(array_merge($projectInput, ['project_name' => '旧记录已编辑'])), 409);
        $this->assertFailure(fn() => $service->saveEntry($id, array_merge($entryInput, ['amount' => '200.02', 'date' => '2026-09-02'])), 409);
        self::assertSame($before, $this->durablePaybackSnapshot());

        $otherInput = $this->project(['client_request_id' => 'retry-invalid-digest-project', 'project_name' => '摘要损坏', 'history_complete_through' => null]);
        $otherId = $service->saveProject($otherInput)['project']['id'];
        $event = Db::name('investment_payback_events')->where('project_id', $otherId)->where('event_type', 'project_created')->find();
        $payload = json_decode($event['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        $payload['create_input_digest'] = null;
        Db::name('investment_payback_events')->where('id', $event['id'])->update(['payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        $before = $this->durablePaybackSnapshot();
        $this->assertFailure(fn() => $this->reconnectPaybackService()->saveProject($otherInput), 409);
        self::assertSame($before, $this->durablePaybackSnapshot());
    }

    public function testCreateRetryKeysAndCreationEvidenceStayTenantAndActorScoped(): void
    {
        $created = [];
        foreach ([[10, 7, [80]], [10, 8, [80]], [20, 7, [90]]] as [$tenantId, $actorId, $hotels]) {
            $service = $this->service($tenantId, $actorId, $hotels);
            $projectInput = $this->project(['client_request_id' => 'retry-scope-project', 'project_name' => '作用域' . $tenantId . '-' . $actorId, 'history_complete_through' => null]);
            $id = $service->saveProject($projectInput)['project']['id'];
            self::assertSame($id, $service->saveProject($projectInput)['project']['id']);
            $entryInput = $this->entry('investment', $tenantId . '.' . str_pad((string)$actorId, 2, '0', STR_PAD_LEFT), 'retry-scope-entry');
            $entryId = $service->saveEntry($id, $entryInput)['entries'][0]['id'];
            $created[] = [$tenantId, $actorId, $hotels, $id, $entryId, $entryInput];
        }
        self::assertCount(3, array_unique(array_column($created, 3)));
        $before = $this->durablePaybackSnapshot();
        $this->reconnectPaybackService();
        foreach ($created as [$tenantId, $actorId, $hotels, $id, $entryId, $entryInput]) {
            self::assertSame($entryId, $this->service($tenantId, $actorId, $hotels)->saveEntry($id, $entryInput)['entries'][0]['id']);
        }
        self::assertSame($before, $this->durablePaybackSnapshot());

        foreach (['actor_id' => 8, 'tenant_id' => 20] as $field => $wrongScope) {
            $event = Db::name('investment_payback_events')->where('tenant_id', 10)->where('project_id', $created[0][3])->where('entry_id', $created[0][4])->where('event_type', 'entry_created')->find();
            if ($field === 'tenant_id') {
                Db::name('investment_payback_events')->where('id', $event['id'])->update(['actor_id' => 7]);
            }
            Db::name('investment_payback_events')->where('id', $event['id'])->update([$field => $wrongScope]);
            $before = $this->durablePaybackSnapshot();
            $this->assertFailure(fn() => $this->reconnectPaybackService()->saveEntry($created[0][3], $created[0][5]), 409);
            self::assertSame($before, $this->durablePaybackSnapshot());
        }
    }

    public function testMalformedExplicitVersionsCannotChangeProjectCashVoidArchiveOrAudit(): void
    {
        $service = $this->service();
        $id = $service->saveProject($this->project())['project']['id'];
        $cash = $service->saveEntry($id, $this->entry('investment', '100.00', 'version-format-invest'));
        $entryId = $cash['entries'][0]['id'];
        $before = $service->saveEntry($id, ['id' => $entryId, 'amount' => '200.00', 'expected_version' => 1]);
        foreach ([null, true, false, 2.0, 2.5, '', '2x', '2.0', '02', '+2', ' 2', '2 ', 0, -1, [], (string)PHP_INT_MAX . '0'] as $version) {
            $actions = [
                fn() => $service->saveProject(['id' => $id, 'notes' => '不应写入', 'expected_version' => $version]),
                fn() => $service->saveEntry($id, ['id' => $entryId, 'amount' => '300.00', 'expected_version' => $version]),
                fn() => $service->voidEntry($id, $entryId, ['reason' => '不应作废', 'expected_version' => $version]),
                fn() => $service->archive($id, ['reason' => '不应归档', 'expected_version' => $version]),
            ];
            foreach ($actions as $action) {
                $this->assertValidationFailure($action, '版本');
                self::assertSame($before, $this->service()->detail($id));
            }
        }
        Db::connect('investment_payback_test')->close();
        Db::connect(null, true);
        self::assertSame($before, $this->service()->detail($id));
        $updated = $service->saveEntry($id, ['id' => $entryId, 'amount' => '201.00', 'expected_version' => '2']);
        self::assertSame(3, $updated['entries'][0]['version']);
        $this->assertFailure(fn() => $service->saveEntry($id, ['id' => $entryId, 'amount' => '999.00', 'expected_version' => '2']), 409);
        self::assertSame($updated, $service->detail($id));
        // Existing clients may omit the field, while a supplied value must be exact.
        $legacy = $service->saveEntry($id, ['id' => $entryId, 'amount' => '202.00']);
        self::assertSame(4, $legacy['entries'][0]['version']);
        self::assertSame($legacy, $this->service()->detail($id));
    }

    public function testMonthlyZeroReceiptRetainsExactPaybackAfterSaveConfirmationAndConnectionReopen(): void
    {
        $service = $this->service();
        $id = $service->saveProject($this->project())['project']['id'];
        $service->saveEntry($id, $this->entry('investment', '100.00', 'zero-precision-invest', ['date' => '2026-01-01']));
        $service->saveEntry($id, $this->entry('recovery', '100.00', 'zero-precision-recovery', ['date' => '2026-06-01']));
        $before = $service->saveProject(['id' => $id, 'history_complete_through' => '2026-09-30']);
        $zero = $service->saveEntry($id, $this->entry('recovery', '0.00', 'zero-precision-month', [
            'date' => '2026-02', 'precision' => 'month', 'confirmed_zero' => true,
        ]));
        self::assertSame('2026-06-01', $zero['summary']['first_payback']['date']);
        self::assertSame('day', $zero['summary']['first_payback']['precision']);
        self::assertFalse($zero['summary']['data_quality']['history_complete']);
        self::assertSame($before['summary']['first_payback'], $zero['audit_history'][0]['payload']['summary_before']['first_payback']);
        $confirmed = $service->saveProject(['id' => $id, 'expected_version' => $zero['project']['version'], 'history_complete_through' => '2026-09-30']);
        self::assertSame($before['summary']['first_payback'], $confirmed['summary']['first_payback']);
        self::assertSame('100.00', $confirmed['summary']['net_recovered_amount']);
        self::assertSame('0.00', $confirmed['entries'][1]['amount']);
        self::assertTrue($confirmed['entries'][1]['confirmed_zero']);
        Db::connect('investment_payback_test')->close();
        Db::connect(null, true);
        self::assertSame($confirmed, $this->service()->detail($id));
    }

    public function testForecastVersionsAndFirstPaybackCorrectionsRemainReadable(): void
    {
        $service = $this->service();
        $project = $service->saveProject($this->project(['expected_monthly_amount' => '100.00', 'expected_source' => '人工假设甲']));
        $id = $project['project']['id'];
        $service->saveEntry($id, $this->entry('investment', '1000.00', 'forecast-invest-001', ['date' => '2026-01-01']));
        $before = $service->saveEntry($id, $this->entry('recovery', '1000.00', 'forecast-recover-001', ['date' => '2026-02-01']));
        $before = $service->saveProject(['id' => $id, 'history_complete_through' => '2026-09-30']);
        self::assertSame('confirmed', $before['summary']['first_payback']['status']);
        $entryId = $before['entries'][1]['id'];
        $corrected = $service->saveEntry($id, ['id' => $entryId, 'amount' => '900.00', 'notes' => '按银行流水更正']);
        self::assertSame('not_reached', $corrected['summary']['first_payback']['status']);
        self::assertSame('confirmed', $corrected['audit_history'][0]['payload']['summary_before']['first_payback']['status']);
        self::assertSame('not_reached', $corrected['audit_history'][0]['payload']['summary_after']['first_payback']['status']);
        $service->saveProject(['id' => $id, 'history_complete_through' => '2026-09-30']);
        $forecast = $service->saveProject(['id' => $id, 'expected_monthly_amount' => '50.00', 'expected_source' => '人工假设乙', 'forecast_as_of' => '2026-09-30']);
        self::assertSame(2, $forecast['summary']['forecast']['whole_months']);
        self::assertSame('100.00', $forecast['audit_history'][0]['payload']['before']['expected_monthly_amount']);
        self::assertSame('50.00', $forecast['audit_history'][0]['payload']['after']['expected_monthly_amount']);
    }

    public function testHotelPermissionAndTenantAssociationAreCheckedOnServer(): void
    {
        $service = $this->service();
        $this->assertFailure(fn() => $service->saveProject($this->project(['hotel_id' => 81])), 403);
        $tenantMismatch = $this->service(10, 7, [80, 90]);
        $this->assertFailure(fn() => $tenantMismatch->saveProject($this->project(['hotel_id' => 90])), 403);
        $saved = $service->saveProject($this->project(['hotel_id' => 80]));
        $denied = $this->service(10, 8, []);
        $this->assertFailure(fn() => $denied->detail($saved['project']['id']), 403);
        self::assertSame([], $denied->projects()['list']);
        $permission = $this->createMock(PermissionService::class);
        $permission->method('authorize')->willReturn(['allowed' => false]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(403);
        new InvestmentPaybackService(new User(['id' => 9, 'tenant_id' => 10]), $permission);
    }

    public function testValidationFailureKeepsPersistedBalanceAndZeroRecoveryIsRetained(): void
    {
        $service = $this->service();
        $saved = $service->saveProject($this->project());
        $id = $saved['project']['id'];
        $service->saveEntry($id, $this->entry('investment', '100.00', 'valid-invest-001'));
        try {
            $service->saveEntry($id, $this->entry('investment', '0', 'invalid-invest-zero'));
            self::fail('0 initial investment must fail');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('大于0', $exception->getMessage());
        }
        $zero = $service->saveEntry($id, $this->entry('recovery', '0', 'zero-recovery-001', ['date' => '2026-09', 'precision' => 'month', 'confirmed_zero' => true]));
        $zeroEntry = array_values(array_filter($zero['entries'], fn(array $row): bool => $row['kind'] === 'recovery'))[0];
        self::assertSame('0.00', $zeroEntry['amount']);
        self::assertTrue($zeroEntry['confirmed_zero']);
        self::assertSame('100.00', $zero['summary']['invested_amount']);
        self::assertSame(2, count($zero['entries']));
    }

    public function testControllerReturnsSemanticHttpErrorsAndNeverExposesDatabaseExceptions(): void
    {
        $controller = $this->controller(null);
        self::assertSame(401, $controller->projects()->getCode());
        $user = new User(['id' => 7, 'tenant_id' => 10, 'role_id' => 1]);
        $controller = $this->controller($user);
        self::assertSame(404, $controller->detail(999)->getCode());
        self::assertSame(422, $controller->saveProject()->getCode());
        $saved = $this->service()->saveProject($this->project());
        $invalidDate = $this->controller($user, ['as_of' => '2026-02-30'])->detail($saved['project']['id']);
        self::assertSame(422, $invalidDate->getCode());
        Db::execute('DROP TABLE investment_payback_entries');
        $failure = $controller->detail($saved['project']['id']);
        self::assertSame(500, $failure->getCode());
        $body = json_decode($failure->getContent(), true);
        self::assertSame(500, $body['code']);
        self::assertSame('回本项目详情读取失败', $body['message']);
        self::assertNull($body['data']);
        self::assertStringNotContainsString('SQL', $failure->getContent());
        self::assertStringNotContainsString(self::$path, $failure->getContent());
    }

    public function testPersonalLayoutPersistsAfterConnectionReopenAndSortsBeforePagination(): void
    {
        $service = $this->service();
        $ids = $this->createLayoutProjects($service, 4);
        self::assertSame(array_reverse($ids), array_column($service->projects()['list'], 'id'));
        self::assertSame([], $service->projects()['layout']['order']);
        $order = [$ids[0], $ids[2], $ids[1], $ids[3]];
        self::assertSame(['order' => $order], $service->saveLayout(['order' => $order]));
        self::assertSame($order, json_decode((string)Db::name('system_config')->where('config_key', 'investment_payback_order_t10_u7')->value('config_value'), true));
        Db::connect('investment_payback_test')->close();
        Db::connect(null, true);
        $reopened = $this->service();
        $pageOne = $reopened->projects(['page_size' => 2]);
        $pageTwo = $reopened->projects(['page' => 2, 'page_size' => 2]);
        self::assertSame(array_slice($order, 0, 2), array_column($pageOne['list'], 'id'));
        self::assertSame(array_slice($order, 2, 2), array_column($pageTwo['list'], 'id'));
        self::assertSame($order, $pageTwo['layout']['order']);
        $newId = $reopened->saveProject($this->project(['project_name' => '后续新增项目', 'client_request_id' => 'layout-added-project']))['project']['id'];
        self::assertSame(array_merge($order, [$newId]), array_column($reopened->projects()['list'], 'id'));
        self::assertSame($order, $reopened->projects()['layout']['order']);
        $newOrder = array_merge([$newId], $order);
        self::assertSame($newOrder, $reopened->saveLayout(['order' => $newOrder])['order']);
    }

    public function testLayoutIsPersonalTenantScopedAndDoesNotChangeLedgerValuesVersionsOrHistory(): void
    {
        $service = $this->service();
        $ids = $this->createLayoutProjects($service, 2);
        $service->saveEntry($ids[0], $this->entry('investment', '1234.56', 'layout-investment'));
        $service->saveEntry($ids[0], $this->entry('recovery', '111.22', 'layout-recovery'));
        $otherUser = $this->service(10, 8);
        $otherTenant = $this->service(20, 7, [90]);
        $tenantIds = $this->createLayoutProjects($otherTenant, 2);
        Db::name('system_config')->insert(['config_key' => 'unrelated_interface_setting', 'config_value' => 'preserved', 'description' => 'test-only']);
        $before = [];
        foreach (['investment_payback_projects', 'investment_payback_entries', 'investment_payback_events'] as $table) {
            $before[$table] = Db::name($table)->order('id')->select()->toArray();
        }
        $detailBefore = $service->detail($ids[0]);
        $service->saveLayout(['order' => $ids]);
        self::assertSame([], $otherUser->projects()['layout']['order']);
        self::assertSame([], $otherTenant->projects()['layout']['order']);
        $otherUser->saveLayout(['order' => array_reverse($ids)]);
        $otherTenant->saveLayout(['order' => array_reverse($tenantIds)]);
        self::assertSame($ids, $service->projects()['layout']['order']);
        self::assertSame(array_reverse($ids), $otherUser->projects()['layout']['order']);
        self::assertSame(array_reverse($tenantIds), $otherTenant->projects()['layout']['order']);
        self::assertSame('preserved', Db::name('system_config')->where('config_key', 'unrelated_interface_setting')->value('config_value'));
        self::assertSame(4, Db::name('system_config')->count());
        self::assertSame($detailBefore, $service->detail($ids[0]));
        foreach ($before as $table => $rows) {
            self::assertSame($rows, Db::name($table)->order('id')->select()->toArray(), $table);
        }
    }

    public function testSubsetMergeKeepsHiddenAndUnloadedProjectSlotsAndArchivedPreferences(): void
    {
        $service = $this->service();
        $ids = $this->createLayoutProjects($service, 4);
        $service->saveLayout(['order' => $ids]);
        $service->archive($ids[1]);
        $subset = [$ids[2], $ids[0]];
        $expected = [$ids[2], $ids[1], $ids[0], $ids[3]];
        self::assertSame($expected, $service->saveLayout(['order' => $subset])['order']);
        $search = $service->projects(['search' => '布局项目0', 'page_size' => 1]);
        self::assertSame([$ids[0]], array_column($search['list'], 'id'));
        self::assertSame($expected, $search['layout']['order']);
        self::assertSame([$ids[2], $ids[0], $ids[3]], array_column($service->projects()['list'], 'id'));
        self::assertSame($expected, array_column($service->projects(['include_archived' => true])['list'], 'id'));
        self::assertSame($expected, $service->saveLayout(['order' => []])['order']);
    }

    public function testLayoutRejectsMalformedUnknownCrossTenantAndInaccessibleIdsWithoutChangingPreference(): void
    {
        $service = $this->service();
        $ids = $this->createLayoutProjects($service, 2);
        $service->saveLayout(['order' => $ids]);
        $badOrders = [null, 'not-an-array', [1 => $ids[0]], [0], [-1], [true], ['1'], [1.5], [$ids[0], $ids[0]], range(1, 1001)];
        foreach ($badOrders as $order) {
            $this->assertValidationFailure(fn() => $service->saveLayout(['order' => $order]), '卡片顺序');
        }
        $this->assertFailure(fn() => $service->saveLayout(['order' => [999]]), 404);
        $foreign = $this->service(20, 8, [90])->saveProject($this->project(['hotel_id' => 90, 'client_request_id' => 'layout-foreign']))['project']['id'];
        $this->assertFailure(fn() => $service->saveLayout(['order' => [$foreign]]), 404);
        $hotelId = $service->saveProject($this->project(['hotel_id' => 80, 'client_request_id' => 'layout-denied-hotel']))['project']['id'];
        $denied = $this->service(10, 7, []);
        $this->assertFailure(fn() => $denied->saveLayout(['order' => [$hotelId]]), 403);
        self::assertSame($ids, $service->projects()['layout']['order']);
        self::assertSame(1, Db::name('system_config')->count());
    }

    public function testLayoutReadIsFreshAndFiltersDeletedOrInaccessibleIdsWithoutRewritingConfig(): void
    {
        $service = $this->service();
        $ids = $this->createLayoutProjects($service, 2);
        $hotelId = $service->saveProject($this->project(['hotel_id' => 80, 'client_request_id' => 'layout-filtered-hotel']))['project']['id'];
        $service->saveLayout(['order' => array_merge($ids, [$hotelId])]);
        $key = 'investment_payback_order_t10_u7';
        $cached = SystemConfig::getValue($key);
        $freshOrder = [$hotelId, $ids[1], $ids[0], 999];
        Db::name('system_config')->where('config_key', $key)->update(['config_value' => json_encode($freshOrder)]);
        self::assertSame($cached, SystemConfig::getValue($key));
        self::assertSame([$hotelId, $ids[1], $ids[0]], $service->projects()['layout']['order']);
        self::assertSame([$ids[1], $ids[0]], $this->service(10, 7, [])->projects()['layout']['order']);
        self::assertSame($freshOrder, json_decode((string)Db::name('system_config')->where('config_key', $key)->value('config_value'), true));
    }

    public function testBrokenLayoutStillReturnsReadableProjectsWithExplicitPreferenceErrorAndSaveFails(): void
    {
        $service = $this->service();
        $ids = $this->createLayoutProjects($service, 2);
        Db::name('system_config')->insert(['config_key' => 'investment_payback_order_t10_u7', 'config_value' => 'broken-json']);
        $projects = $service->projects();
        self::assertSame(array_reverse($ids), array_column($projects['list'], 'id'));
        self::assertNull($projects['layout']['order']);
        self::assertSame('error', $projects['layout']['status']);
        self::assertSame('卡片顺序读取失败，请重试', $projects['layout']['message']);
        $user = new User(['id' => 7, 'tenant_id' => 10, 'role_id' => 1]);
        $failure = $this->controller($user, [], ['order' => $ids])->saveLayout();
        self::assertSame(500, $failure->getCode());
        self::assertSame('卡片顺序保存失败', json_decode($failure->getContent(), true)['message']);
        Db::execute('DROP TABLE system_config');
        self::assertSame('error', $service->projects()['layout']['status']);
        self::assertSame(500, $this->controller($user, [], ['order' => $ids])->saveLayout()->getCode());
    }

    public function testLayoutSaveReadsActualStoredValueAndRollsBackWhenDatabaseDisagrees(): void
    {
        $service = $this->service();
        $ids = $this->createLayoutProjects($service, 2);
        $service->saveLayout(['order' => $ids]);
        // Synthetic trigger simulates storage rewriting the value after a write.
        Db::execute('CREATE TRIGGER reject_layout_readback AFTER UPDATE ON system_config '
            . 'WHEN NEW.config_key = "investment_payback_order_t10_u7" '
            . 'BEGIN UPDATE system_config SET config_value = "[]" WHERE id = NEW.id; END');
        try {
            $service->saveLayout(['order' => array_reverse($ids)]);
            self::fail('Database readback mismatch must fail');
        } catch (RuntimeException $exception) {
            self::assertSame('卡片顺序保存回读不一致', $exception->getMessage());
        }
        self::assertSame($ids, $service->projects()['layout']['order']);
    }

    public function testLayoutControllerUsesStandardResponseAndSemanticValidationErrors(): void
    {
        $service = $this->service();
        $ids = $this->createLayoutProjects($service, 2);
        self::assertSame(401, $this->controller(null, [], ['order' => $ids])->saveLayout()->getCode());
        $user = new User(['id' => 7, 'tenant_id' => 10, 'role_id' => 1]);
        self::assertSame(422, $this->controller($user)->saveLayout()->getCode());
        self::assertSame(422, $this->controller($user, [], ['order' => [$ids[0], $ids[0]]])->saveLayout()->getCode());
        self::assertSame(404, $this->controller($user, [], ['order' => [999]])->saveLayout()->getCode());
        $response = $this->controller($user, [], ['order' => $ids])->saveLayout();
        self::assertSame(200, $response->getCode());
        $body = json_decode($response->getContent(), true);
        self::assertSame(200, $body['code']);
        self::assertArrayHasKey('message', $body);
        self::assertSame(['order' => $ids], $body['data']);
    }

    public function testAdministratorDeletesActiveRecordRecalculatesAndRetainsExactAuditAfterReopen(): void
    {
        $service = $this->service();
        $projectId = $service->saveProject($this->project())['project']['id'];
        $service->saveEntry($projectId, $this->entry('investment', '1000.01', 'delete-investment'));
        $before = $service->saveEntry($projectId, $this->entry('recovery', '300.02', 'delete-recovery'));
        $entry = $before['entries'][1];
        self::assertTrue($before['can_delete_entries']);
        $saved = $service->deleteEntry($projectId, $entry['id'], ['expected_version' => $entry['version'], 'reason' => '管理员核对重复流水后删除']);
        self::assertSame([$before['entries'][0]], $saved['entries']);
        self::assertSame('1000.01', $saved['summary']['invested_amount']);
        self::assertSame('0.00', $saved['summary']['net_recovered_amount']);
        self::assertSame('1000.01', $saved['summary']['unrecovered_amount']);
        self::assertSame($before['project']['version'] + 1, $saved['project']['version']);
        $event = $saved['audit_history'][0];
        self::assertSame('entry_deleted', $event['event_type']);
        self::assertSame(7, $event['actor_id']);
        self::assertSame($entry['id'], (int)$event['entry_id']);
        self::assertSame($entry, $event['payload']['before']);
        self::assertSame(['id' => $entry['id'], 'deleted' => true, 'delete_reason' => '管理员核对重复流水后删除'], $event['payload']['after']);
        // JSON retains exact money strings; integral percentages can decode as ints.
        self::assertEquals($before['summary'], $event['payload']['summary_before']);
        self::assertEquals($saved['summary'], $event['payload']['summary_after']);
        self::assertSame(array_column($before['audit_history'], 'id'), array_column(array_slice($saved['audit_history'], 1), 'id'));
        self::assertNull(Db::name('investment_payback_entries')->where('id', $entry['id'])->find());
        Db::connect('investment_payback_test')->close();
        Db::connect(null, true);
        self::assertSame($saved, $this->service()->detail($projectId));
        $this->assertFailure(fn() => $service->deleteEntry($projectId, $entry['id'], ['expected_version' => $entry['version']]), 404);
    }

    public function testAdministratorCanDeleteVoidedRecordWithDefaultReasonAndPreservesBalance(): void
    {
        $service = $this->service();
        $projectId = $service->saveProject($this->project())['project']['id'];
        $service->saveEntry($projectId, $this->entry('investment', '500.00', 'delete-voided-investment'));
        $actual = $service->saveEntry($projectId, $this->entry('recovery', '100.00', 'delete-voided-recovery'));
        $entryId = $actual['entries'][1]['id'];
        $voided = $service->voidEntry($projectId, $entryId, ['reason' => '合成重复记录', 'expected_version' => 1]);
        $saved = $service->deleteEntry($projectId, $entryId, ['expected_version' => 2]);
        self::assertSame($voided['summary'], $saved['summary']);
        self::assertSame([$voided['entries'][0]], $saved['entries']);
        self::assertSame($voided['entries'][1], $saved['audit_history'][0]['payload']['before']);
        self::assertSame('管理员主动删除', $saved['audit_history'][0]['payload']['after']['delete_reason']);
        self::assertSame('entry_voided', $saved['audit_history'][1]['event_type']);
        self::assertSame($saved, $service->detail($projectId));
    }

    public function testDeleteRequiresAdministratorAndCurrentTenantHotelProjectAndVersion(): void
    {
        $service = $this->service();
        $projectId = $service->saveProject($this->project(['hotel_id' => 80]))['project']['id'];
        $saved = $service->saveEntry($projectId, $this->entry('investment', '500.00', 'delete-scope-investment'));
        $entryId = $saved['entries'][0]['id'];
        $input = ['expected_version' => 1];
        $ordinary = $this->service(10, 8, [80], false);
        self::assertFalse($ordinary->detail($projectId)['can_delete_entries']);
        $this->assertFailure(fn() => $ordinary->deleteEntry($projectId, $entryId, $input), 403);
        $this->assertFailure(fn() => $this->service(20, 7, [90])->deleteEntry($projectId, $entryId, $input), 404);
        $this->assertFailure(fn() => $this->service(10, 7, [])->deleteEntry($projectId, $entryId, $input), 403);
        $otherId = $service->saveProject($this->project(['client_request_id' => 'delete-scope-other-project']))['project']['id'];
        $this->assertFailure(fn() => $service->deleteEntry($otherId, $entryId, $input), 404);
        $this->assertFailure(fn() => $service->deleteEntry($projectId, $entryId, ['expected_version' => 2]), 409);
        foreach ([[], ['expected_version' => 0], ['expected_version' => '1'], ['expected_version' => true]] as $invalid) {
            $this->assertValidationFailure(fn() => $service->deleteEntry($projectId, $entryId, $invalid), '版本');
        }
        self::assertSame($saved, $service->detail($projectId));
        $archived = $service->archive($projectId);
        self::assertFalse($archived['can_delete_entries']);
        $this->assertFailure(fn() => $service->deleteEntry($projectId, $entryId, $input), 409);
        self::assertSame($archived, $service->detail($projectId));
    }

    public function testOriginalRecordCannotBeDeletedUntilEveryLinkedRefundIncludingVoidedIsDeleted(): void
    {
        $service = $this->service();
        $projectId = $service->saveProject($this->project())['project']['id'];
        $service->saveEntry($projectId, $this->entry('investment', '500.00', 'delete-refund-investment'));
        $actual = $service->saveEntry($projectId, $this->entry('recovery', '100.00', 'delete-refund-recovery'));
        $originalId = $actual['entries'][1]['id'];
        $withRefund = $service->saveEntry($projectId, $this->entry('refund', '20.00', 'delete-refund-linked', ['original_entry_id' => $originalId, 'notes' => '合成验收关联退款']));
        $refundId = $withRefund['entries'][2]['id'];
        $this->assertFailure(fn() => $service->deleteEntry($projectId, $originalId, ['expected_version' => 1]), 409);
        self::assertSame($withRefund, $service->detail($projectId));
        $voided = $service->voidEntry($projectId, $refundId, ['expected_version' => 1, 'reason' => '合成退款核对作废']);
        $this->assertFailure(fn() => $service->deleteEntry($projectId, $originalId, ['expected_version' => 1]), 409);
        self::assertSame($voided, $service->detail($projectId));
        $service->deleteEntry($projectId, $refundId, ['expected_version' => 2]);
        $deleted = $service->deleteEntry($projectId, $originalId, ['expected_version' => 1]);
        self::assertSame([$withRefund['entries'][0]], $deleted['entries']);
        self::assertSame('0.00', $deleted['summary']['net_recovered_amount']);
        self::assertSame($originalId, $deleted['audit_history'][0]['payload']['before']['id']);
        self::assertSame($refundId, $deleted['audit_history'][1]['payload']['before']['id']);
    }

    public function testAuditFailureRollsBackHardDeletionBalanceAndProjectVersion(): void
    {
        $service = $this->service();
        $projectId = $service->saveProject($this->project())['project']['id'];
        $before = $service->saveEntry($projectId, $this->entry('investment', '500.00', 'delete-rollback-investment'));
        $entryId = $before['entries'][0]['id'];
        Db::execute('CREATE TRIGGER reject_delete_audit BEFORE INSERT ON investment_payback_events '
            . 'WHEN NEW.event_type = "entry_deleted" BEGIN SELECT RAISE(ABORT, "synthetic audit write failure"); END');
        $user = new User(['id' => 7, 'tenant_id' => 10, 'role_id' => 1]);
        $response = $this->controller($user, [], ['expected_version' => 1])->deleteEntry($projectId, $entryId);
        self::assertSame(500, $response->getCode());
        $body = json_decode($response->getContent(), true);
        self::assertSame('资金记录删除失败', $body['message']);
        self::assertNull($body['data']);
        self::assertStringNotContainsString('synthetic audit', $response->getContent());
        self::assertSame($before, $service->detail($projectId));
    }

    public function testDeleteControllerReturnsStandardResponseAndSemanticErrors(): void
    {
        $service = $this->service();
        $projectId = $service->saveProject($this->project())['project']['id'];
        $before = $service->saveEntry($projectId, $this->entry('investment', '100.00', 'delete-controller-investment'));
        $entryId = $before['entries'][0]['id'];
        self::assertSame(401, $this->controller(null)->deleteEntry($projectId, $entryId)->getCode());
        $user = new User(['id' => 7, 'tenant_id' => 10, 'role_id' => 1]);
        self::assertSame(422, $this->controller($user)->deleteEntry($projectId, $entryId)->getCode());
        self::assertSame(409, $this->controller($user, [], ['expected_version' => 2])->deleteEntry($projectId, $entryId)->getCode());
        $response = $this->controller($user, [], ['expected_version' => 1])->deleteEntry($projectId, $entryId);
        self::assertSame(200, $response->getCode());
        $body = json_decode($response->getContent(), true);
        self::assertSame(200, $body['code']);
        self::assertSame([], $body['data']['entries']);
        self::assertNull($body['data']['summary']['invested_amount']);
        self::assertSame('entry_deleted', $body['data']['audit_history'][0]['event_type']);
        self::assertSame($service->detail($projectId), $body['data']);
    }

    public function testActualMutationClearsReviewedHistoryAndExplicitReconfirmationPersists(): void
    {
        $service = $this->service();
        $created = $service->saveProject($this->project([
            'opening_as_of' => '2026-08-31', 'opening_invested' => '1000.00',
            'opening_recovered' => '0.00', 'opening_source' => '合成验收期初',
        ]));
        $id = $created['project']['id'];
        self::assertTrue($created['summary']['data_quality']['history_complete']);
        $input = $this->entry('recovery', '100.00', 'history-create-001', ['date' => '2026-09-30']);
        $changed = $service->saveEntry($id, $input);
        self::assertNull($changed['project']['history_complete_through']);
        self::assertFalse($changed['summary']['data_quality']['history_complete']);
        self::assertTrue($changed['audit_history'][0]['payload']['summary_before']['data_quality']['history_complete']);
        self::assertFalse($changed['audit_history'][0]['payload']['summary_after']['data_quality']['history_complete']);
        self::assertSame($changed, $this->service()->detail($id));
        $confirmed = $service->saveProject(['id' => $id, 'expected_version' => $changed['project']['version'], 'history_complete_through' => '2026-09-30']);
        self::assertSame('2026-09-30', $confirmed['project']['history_complete_through']);
        self::assertTrue($confirmed['summary']['data_quality']['history_complete']);
        self::assertSame($changed['project']['version'] + 1, $confirmed['project']['version']);
        self::assertSame($confirmed, $this->service()->detail($id));
        // A retry did not change the reviewed ledger and must preserve the new confirmation.
        self::assertSame($confirmed, $service->saveEntry($id, $input));
        self::assertSame($confirmed, $service->saveEntry($id, ['id' => $changed['entries'][0]['id'], 'amount' => '100.00', 'expected_version' => 1]));
    }

    public function testReviewedEditsUseBothOldAndNewDatesAndActualStates(): void
    {
        $cases = [
            'amount-covered' => [['date' => '2026-09-15'], ['amount' => '120.00'], true],
            'source-covered' => [['date' => '2026-09-15'], ['source' => '更正后的合成证据'], true],
            'move-out' => [['date' => '2026-09-15'], ['date' => '2026-09-16'], true],
            'move-in' => [['date' => '2026-09-16'], ['date' => '2026-09-15'], true],
            'actual-to-plan' => [['date' => '2026-09-15'], ['is_planned' => true], true],
            'plan-to-actual' => [['date' => '2026-09-15', 'is_planned' => true], ['is_planned' => false], true],
            'plan-edit' => [['date' => '2026-09-15', 'is_planned' => true], ['amount' => '120.00'], false],
            'future-plan' => [['date' => '2099-01-01', 'is_planned' => true], ['amount' => '120.00'], false],
            'after-cutoff' => [['date' => '2026-09-16'], ['amount' => '120.00'], false],
            'monthly-overlap' => [['date' => '2026-09', 'precision' => 'month'], ['amount' => '120.00'], true],
            'zero-covered' => [['date' => '2026-09-15'], ['amount' => '0.00', 'confirmed_zero' => true], true],
        ];
        $service = $this->service();
        foreach ($cases as $name => [$initial, $changes, $invalidates]) {
            $before = $this->reviewedCashProject($service, 'edit-' . $name, $initial);
            $id = $before['project']['id'];
            $entryId = $before['entries'][0]['id'];
            $saved = $service->saveEntry($id, array_merge($changes, ['id' => $entryId, 'expected_version' => 1]));
            self::assertSame($invalidates ? null : '2026-09-15', $saved['project']['history_complete_through'], $name);
            self::assertSame($before['project']['version'] + 1, $saved['project']['version'], $name);
            self::assertSame(2, $saved['entries'][0]['version'], $name);
            self::assertSame($saved, $this->service()->detail($id), $name);
            self::assertSame('2026-09-15', $saved['audit_history'][0]['payload']['summary_before']['data_quality']['history_complete_through'], $name);
            self::assertSame($saved['project']['history_complete_through'], $saved['audit_history'][0]['payload']['summary_after']['data_quality']['history_complete_through'], $name);
        }
    }

    public function testNewInvestmentReceiptRefundAndPlanRespectReviewedDateBoundary(): void
    {
        $service = $this->service();
        $cases = [
            'covered-investment' => ['investment', ['date' => '2026-09-15'], true],
            'covered-recovery' => ['recovery', ['date' => '2026-09-15'], true],
            'covered-refund' => ['refund', ['date' => '2026-09-15', 'notes' => '合成原收款退回'], true],
            'monthly-recovery' => ['recovery', ['date' => '2026-09', 'precision' => 'month'], true],
            'later-investment' => ['investment', ['date' => '2026-09-16'], false],
            'later-recovery' => ['recovery', ['date' => '2026-09-16'], false],
            'past-plan' => ['recovery', ['date' => '2026-09-01', 'is_planned' => true], false],
            'future-plan' => ['investment', ['date' => '2099-01-01', 'is_planned' => true], false],
        ];
        foreach ($cases as $name => [$kind, $changes, $invalidates]) {
            $before = $this->reviewedCashProject($service, 'create-' . $name);
            $id = $before['project']['id'];
            if ($kind === 'refund') {
                $changes['original_entry_id'] = $before['entries'][0]['id'];
            }
            $input = $this->entry($kind, '10.00', 'new-' . $name, $changes);
            $saved = $service->saveEntry($id, $input);
            self::assertSame($invalidates ? null : '2026-09-15', $saved['project']['history_complete_through'], $name);
            self::assertSame($saved, $this->service()->detail($id), $name);
            self::assertSame($saved, $service->saveEntry($id, $input), $name);
        }
    }

    public function testChangedReviewedReceiptDowngradesPaybackAndForecastUntilReconfirmed(): void
    {
        $service = $this->service();
        $id = $service->saveProject($this->project(['expected_monthly_amount' => '100.00', 'expected_source' => '合成预测来源']))['project']['id'];
        $service->saveEntry($id, $this->entry('investment', '1000.00', 'quality-invest-001', ['date' => '2026-01-01']));
        $cash = $service->saveEntry($id, $this->entry('recovery', '1000.00', 'quality-recover-001', ['date' => '2026-02-01']));
        $before = $service->saveProject(['id' => $id, 'history_complete_through' => '2026-09-30']);
        self::assertSame('confirmed', $before['summary']['first_payback']['status']);
        self::assertSame('recovered', $before['summary']['state']);
        self::assertSame('already_recovered', $before['summary']['forecast']['status']);
        $saved = $service->saveEntry($id, ['id' => $cash['entries'][1]['id'], 'expected_version' => 1, 'source' => '已更正的合成收款来源']);
        self::assertSame('recorded_only', $saved['summary']['first_payback']['status']);
        self::assertSame('recorded_only', $saved['summary']['state']);
        self::assertSame('trial_recovered', $saved['summary']['forecast']['status']);
        self::assertSame('1000.00', $saved['summary']['net_recovered_amount']);
        self::assertSame($saved, $this->service()->detail($id));
        $confirmed = $service->saveProject(['id' => $id, 'expected_version' => $saved['project']['version'], 'history_complete_through' => '2026-09-30']);
        self::assertSame('confirmed', $confirmed['summary']['first_payback']['status']);
        self::assertSame('already_recovered', $confirmed['summary']['forecast']['status']);
        self::assertSame($confirmed, $this->service()->detail($id));
    }

    public function testVoidAndDeleteInvalidateOnlyEffectiveReviewedActualRows(): void
    {
        $service = $this->service();
        $cases = [
            'actual-void' => ['void', [], false, false, true],
            'actual-delete' => ['delete', [], false, false, true],
            'planned-void' => ['void', ['is_planned' => true], false, false, false],
            'planned-delete' => ['delete', ['is_planned' => true], false, false, false],
            'future-delete' => ['delete', ['date' => '2099-01-01', 'is_planned' => true], false, false, false],
            'voided-delete' => ['delete', [], true, false, false],
            'opening-void' => ['void', [], false, true, false],
            'opening-delete' => ['delete', [], false, true, false],
            'after-void' => ['void', ['date' => '2026-09-16'], false, false, false],
            'after-delete' => ['delete', ['date' => '2026-09-16'], false, false, false],
        ];
        foreach ($cases as $name => [$operation, $initial, $voided, $excluded, $invalidates]) {
            $before = $this->reviewedCashProject($service, $name, $initial);
            $id = $before['project']['id'];
            $entryId = $before['entries'][0]['id'];
            if ($voided) {
                $service->voidEntry($id, $entryId, ['expected_version' => 1, 'reason' => '合成验收预先作废']);
            }
            if ($excluded) {
                // Legacy imported overlap is excluded by the calculator. The
                // public save path correctly rejects creating such a row.
                Db::name('investment_payback_entries')->where('id', $entryId)->update(['business_date' => '2026-08-31']);
            }
            $before = $excluded ? $service->detail($id) : $service->saveProject(['id' => $id, 'history_complete_through' => '2026-09-15']);
            $version = $before['entries'][0]['version'];
            $saved = $operation === 'void'
                ? $service->voidEntry($id, $entryId, ['expected_version' => $version, 'reason' => '合成核对作废'])
                : $service->deleteEntry($id, $entryId, ['expected_version' => $version]);
            self::assertSame($invalidates ? null : '2026-09-15', $saved['project']['history_complete_through'], $name);
            self::assertSame($before['project']['version'] + 1, $saved['project']['version'], $name);
            self::assertSame($saved, $this->service()->detail($id), $name);
            if ($operation === 'void') {
                $confirmed = $service->saveProject(['id' => $id, 'history_complete_through' => '2026-09-15']);
                self::assertSame($confirmed, $service->voidEntry($id, $entryId, ['expected_version' => $version, 'reason' => '合成重试']), $name);
            }
        }
    }

    public function testOpeningAndInvestmentBasisChangesRequireSeparateReconfirmation(): void
    {
        $service = $this->service();
        $cases = [
            'opening-date' => ['opening_as_of' => '2026-08-30'],
            'opening-invested' => ['opening_invested' => '1100.00'],
            'opening-recovered' => ['opening_recovered' => '20.00'],
            'opening-source' => ['opening_source' => '合成期初来源乙'],
            'first-date' => ['first_invested_on' => '2026-08-01'],
            'opening-remove' => ['opening_as_of' => null, 'opening_invested' => null, 'opening_recovered' => null, 'opening_source' => ''],
        ];
        foreach ($cases as $name => $changes) {
            $before = $this->reviewedCashProject($service, $name);
            $id = $before['project']['id'];
            // An old confirmation submitted together with a changed basis is
            // not proof that the new basis has been reviewed.
            $saved = $service->saveProject(array_merge($changes, ['id' => $id, 'expected_version' => $before['project']['version'], 'history_complete_through' => '2026-09-15']));
            self::assertNull($saved['project']['history_complete_through'], $name);
            self::assertFalse($saved['summary']['data_quality']['history_complete'], $name);
            self::assertSame($saved, $this->service()->detail($id), $name);
            $confirmed = $service->saveProject(['id' => $id, 'expected_version' => $saved['project']['version'], 'history_complete_through' => '2026-09-15']);
            self::assertSame('2026-09-15', $confirmed['project']['history_complete_through'], $name);
            self::assertSame($saved['project']['version'] + 1, $confirmed['project']['version'], $name);
            self::assertSame($confirmed, $this->service()->detail($id), $name);
        }
        $before = $this->reviewedCashProject($service, 'metadata');
        $saved = $service->saveProject(['id' => $before['project']['id'], 'project_name' => '新名称', 'notes' => '合成备注', 'expected_monthly_amount' => '50.00', 'expected_source' => '合成预测', 'forecast_as_of' => '2026-09-14']);
        self::assertSame('2026-09-15', $saved['project']['history_complete_through']);
        self::assertSame($saved, $this->service()->detail($before['project']['id']));
    }

    public function testRejectedAndFailedMutationsPreserveReviewedReadbackAtomically(): void
    {
        $service = $this->service();
        $before = $this->reviewedCashProject($service, 'boundary-failures');
        $id = $before['project']['id'];
        $entryId = $before['entries'][0]['id'];
        $this->assertFailure(fn() => $service->saveEntry($id, ['id' => $entryId, 'amount' => '110.00', 'expected_version' => 99]), 409);
        $this->assertFailure(fn() => $service->voidEntry($id, $entryId, ['expected_version' => 99, 'reason' => '过期版本']), 409);
        $this->assertFailure(fn() => $service->deleteEntry($id, $entryId, ['expected_version' => 99]), 409);
        $this->assertFailure(fn() => $service->saveEntry($id, $this->entry('recovery', '10.00', 'history-overlap-fail', ['date' => '2026-08-31'])), 409);
        $this->assertFailure(fn() => $service->saveProject(['id' => $id, 'opening_as_of' => '2026-09-01']), 409);
        $this->assertValidationFailure(fn() => $service->saveEntry($id, $this->entry('recovery', '10.00', 'history-future-fail', ['date' => '2099-01-01'])), '未来');
        self::assertSame($before, $this->service()->detail($id));
        $actions = [
            'entry_created' => fn() => $service->saveEntry($id, $this->entry('recovery', '10.00', 'history-audit-fail')),
            'entry_updated' => fn() => $service->saveEntry($id, ['id' => $entryId, 'expected_version' => 1, 'amount' => '110.00']),
            'entry_voided' => fn() => $service->voidEntry($id, $entryId, ['expected_version' => 1, 'reason' => '合成作废失败']),
            'entry_deleted' => fn() => $service->deleteEntry($id, $entryId, ['expected_version' => 1]),
            'project_updated' => fn() => $service->saveProject(['id' => $id, 'opening_invested' => '1100.00']),
        ];
        foreach ($actions as $type => $action) {
            Db::execute('CREATE TRIGGER reject_history_audit BEFORE INSERT ON investment_payback_events '
                . 'WHEN NEW.event_type = "' . $type . '" BEGIN SELECT RAISE(ABORT, "synthetic history audit failure"); END');
            $exception = null;
            try {
                $action();
            } catch (\Throwable $caught) {
                $exception = $caught;
            }
            self::assertNotNull($exception, $type);
            self::assertStringContainsString('synthetic history audit failure', $exception->getMessage(), $type);
            self::assertSame($before, $this->service()->detail($id), $type);
            Db::execute('DROP TRIGGER reject_history_audit');
        }
    }

    private function reviewedCashProject(InvestmentPaybackService $service, string $name, array $entryChanges = []): array
    {
        $created = $service->saveProject($this->project([
            'client_request_id' => 'history-' . $name, 'history_complete_through' => null, 'forecast_as_of' => '2026-09-15',
            'opening_as_of' => '2026-08-31', 'opening_invested' => '1000.00', 'opening_recovered' => '0.00', 'opening_source' => '合成验收期初',
        ]));
        $id = $created['project']['id'];
        $service->saveEntry($id, $this->entry('recovery', '100.00', 'cash-' . $name, $entryChanges));
        return $service->saveProject(['id' => $id, 'history_complete_through' => '2026-09-15']);
    }

    private function createLayoutProjects(InvestmentPaybackService $service, int $count): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = $service->saveProject($this->project(['project_name' => '布局项目' . $i, 'client_request_id' => 'layout-project-' . $i]))['project']['id'];
        }
        return $ids;
    }

    private function controller(?User $user, array $query = [], array $post = []): InvestmentPayback
    {
        $reflection = new \ReflectionClass(InvestmentPayback::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('currentUser')->setValue($controller, $user);
        $request = (new Request())->withGet($query)->withPost($post);
        $request->user = $user;
        app()->instance('request', $request);
        $reflection->getProperty('request')->setValue($controller, $request);
        return $controller;
    }

    private function committedUpdateFixture(string $mode, string $key): array
    {
        $service = $this->service();
        $created = $service->saveProject($this->project(['client_request_id' => 'edit-replay-' . $key,
            'project_name' => '合成重试项目-' . $key, 'hotel_id' => 80, 'history_complete_through' => null]));
        $id = $created['project']['id'];
        if ($mode === 'project') $request = ['id' => $id, 'expected_version' => $created['project']['version'], 'notes' => '同一份原始草稿'];
        else {
            $cash = $service->saveEntry($id, $this->entry('investment', '100.01', 'edit-cash-' . $key));
            $request = ['id' => $cash['entries'][0]['id'], 'expected_version' => $cash['entries'][0]['version'],
                'amount' => '125.37', 'source' => '合成原始更正流水', 'notes' => '同一份原始草稿'];
        }
        $edit = ['mode' => $mode, 'project_id' => $id, 'record_id' => $request['id'], 'request' => $request];
        $edit['saved'] = $this->repeatCommittedUpdate($edit, $request, $service);
        return $edit;
    }

    private function repeatCommittedUpdate(array $edit, array $request, ?InvestmentPaybackService $service = null): array
    {
        $service ??= $this->service();
        return $edit['mode'] === 'project' ? $service->saveProject($request) : $service->saveEntry($edit['project_id'], $request);
    }

    private function durablePaybackSnapshot(): array
    {
        $pdo = new \PDO('sqlite:' . self::$path);
        $snapshot = [];
        foreach (['investment_payback_projects', 'investment_payback_entries', 'investment_payback_events', 'system_config'] as $table) {
            $snapshot[$table] = $pdo->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        }
        return $snapshot;
    }

    private function reconnectPaybackService(): InvestmentPaybackService
    {
        Db::connect('investment_payback_test')->close();
        Db::connect(null, true);
        return $this->service();
    }

    private function service(int $tenantId = 10, int $actorId = 7, array $allowedHotels = [80], bool $administrator = true): InvestmentPaybackService
    {
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('authorize')->willReturnCallback(static fn(User $user, string $capability, ?int $hotelId = null): array => ['allowed' => $capability === 'investment.simulate' && ($hotelId === null || in_array($hotelId, $allowedHotels, true))]);
        $scope = $this->createMock(HotelScopeService::class);
        $scope->method('accessibleHotelIds')->willReturn($allowedHotels);
        $user = new User(['id' => $actorId, 'tenant_id' => $tenantId, 'role_id' => $administrator ? Role::ADMIN : Role::NORMAL_USER]);
        if (!$administrator) {
            // Even a legacy ordinary role with an all permission is not admin.
            $user->setRelation('role', new Role(['id' => Role::NORMAL_USER, 'status' => Role::STATUS_ENABLED, 'level' => 3, 'permissions' => '["all"]']));
        }
        return new InvestmentPaybackService($user, $permissions, null, $scope);
    }

    private function project(array $changes = []): array
    {
        return array_merge(['project_name' => '本地验收投资项目', 'investor_name' => '测试投资人', 'hotel_id' => null, 'client_request_id' => 'project-test-001', 'forecast_as_of' => '2026-09-30', 'history_complete_through' => '2026-09-30'], $changes);
    }

    private function entry(string $kind, string $amount, string $requestId, array $changes = []): array
    {
        return array_merge(['kind' => $kind, 'amount' => $amount, 'date' => '2026-09-01', 'precision' => 'day', 'client_request_id' => $requestId, 'source' => '测试银行流水说明'], $changes);
    }

    private function assertFailure(callable $action, int $code): void
    {
        try {
            $action();
            self::fail('Expected an authorization/conflict failure');
        } catch (RuntimeException $exception) {
            self::assertSame($code, $exception->getCode(), $exception->getMessage());
        }
    }

    private function assertValidationFailure(callable $action, string $messagePart): void
    {
        try {
            $action();
            self::fail('Expected validation to reject inconsistent cash records');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString($messagePart, $exception->getMessage());
        }
    }
}
