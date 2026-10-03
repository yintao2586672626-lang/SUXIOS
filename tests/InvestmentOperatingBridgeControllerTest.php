<?php
declare(strict_types=1);

use app\controller\OperatingFinance;
use app\model\Role;
use app\model\User;
use app\service\InvestmentOperatingBridgeService;
use app\service\InvestmentPaybackCalculator;
use app\service\InvestmentPaybackService;
use PHPUnit\Framework\TestCase;
use Tests\Support\InvestmentScenarioFixture as Fixture;
use think\App;
use think\facade\Config;
use think\facade\Db;

require_once __DIR__ . '/Support/InvestmentScenarioFixture.php';

final class InvestmentOperatingBridgeControllerTest extends TestCase
{
    private array $originalConfig;
    private string $path;

    protected function setUp(): void
    {
        (new App())->initialize();
        restore_error_handler();
        restore_exception_handler();
        $this->originalConfig = Config::get('database');
        $this->path = sys_get_temp_dir() . '/investment-scenario-test-bridge-controller-' . bin2hex(random_bytes(5)) . '.sqlite';
        Fixture::connect($this->path);
        Fixture::schema();
        Db::execute('ALTER TABLE hotels ADD COLUMN name TEXT NOT NULL DEFAULT "synthetic-scope-only"');
        Db::execute('CREATE TABLE user_hotel_permissions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, tenant_id INTEGER, hotel_id INTEGER, status TEXT, can_view INTEGER, can_operation INTEGER, can_investment INTEGER)');
        Db::execute('CREATE TABLE system_config (id INTEGER PRIMARY KEY AUTOINCREMENT, config_key TEXT NOT NULL UNIQUE, config_value TEXT NULL, description TEXT NOT NULL DEFAULT "", create_time INTEGER NULL, update_time INTEGER NULL)');
    }

    protected function tearDown(): void
    {
        Db::connect()->close();
        Config::set($this->originalConfig, 'database');
        Db::connect(null, true);
        if (is_file($this->path)) unlink($this->path);
    }

    public function testOperatingViewDoesNotGrantInvestmentCashAccess(): void
    {
        $response = $this->overview($this->user([]));
        self::assertSame(200, $response['code']);
        self::assertSame(80, $response['data']['hotel_id']);
        self::assertSame('blocked', $response['data']['investment_bridge']['status']);
        self::assertSame('investment_view_permission_required', $response['data']['investment_bridge']['reason_code']);
        self::assertNull($response['data']['investment_bridge']['projects']);
        self::assertNull($response['data']['investment_bridge']['totals']);
        self::assertSame(0, $response['data']['boundaries']['external_write_count']);
    }

    public function testViewOnlyAndSimulateOnlyUsersReadThroughActualControllerAndPermissionServices(): void
    {
        $saved = $this->seedProject();
        foreach (['investment.view', 'investment.simulate'] as $index => $capability) {
            $user = $this->user([$capability], 7 + $index);
            self::assertSame($capability === 'investment.view', $user->hasHotelPermission(80, 'investment.view'));
            self::assertSame($capability === 'investment.simulate', $user->hasHotelPermission(80, 'investment.simulate'));
            $before = $this->records();
            $response = $this->overview($user);
            self::assertSame(200, $response['code']);
            $bridge = $response['data']['investment_bridge'];
            self::assertSame('ready', $bridge['status']);
            self::assertSame($saved['project']['id'], $bridge['projects'][0]['project_id']);
            self::assertSame('100.01', $bridge['totals']['actual_invested']);
            self::assertSame($before, $this->records());
        }
    }

    public function testViewOnlyCannotConstructWritableLedgerOrUseAnyWriteOnReadOnlyLedger(): void
    {
        $saved = $this->seedProject();
        $id = $saved['project']['id'];
        $user = $this->user(['investment.view']);
        $this->assertDenied(static fn() => new InvestmentPaybackService($user));
        $reader = new InvestmentPaybackService($user, readOnly: true);
        self::assertSame($id, $reader->detail($id, '2026-09-30')['project']['id']);
        self::assertFalse($reader->detail($id, '2026-09-30')['can_delete_entries']);
        $before = $this->records();
        foreach ([
            fn() => $reader->saveProject(Fixture::project(['client_request_id' => 'view-only-create'])),
            fn() => $reader->saveProject(['id' => $id, 'project_name' => 'must-not-edit']),
            fn() => $reader->archive($id),
            fn() => $reader->saveEntry($id, ['kind' => 'investment', 'amount' => '1.00', 'date' => '2026-09-01', 'source' => 'synthetic', 'client_request_id' => 'view-only-entry']),
            fn() => $reader->voidEntry($id, 1, ['reason' => 'must-not-void']),
            fn() => $reader->deleteEntry($id, 1, ['expected_version' => 1]),
            fn() => $reader->saveLayout(['order' => [$id]]),
            fn() => $reader->saveLayout(['order' => []]),
        ] as $action) {
            $this->assertDenied($action);
            self::assertSame($before, $this->records());
        }
    }

    public function testNoInvestmentPermissionAndForeignHotelOrTenantAreRejected(): void
    {
        $this->seedProject();
        $this->assertDenied(fn() => new InvestmentPaybackService($this->user([], 8), readOnly: true));
        $user = $this->user(['investment.view']);
        $reader = new InvestmentPaybackService($user, readOnly: true);
        foreach ([['hotel_id' => 81], ['hotel_id' => 90], ['tenant_id' => 20, 'hotel_id' => 80]] as $filters) {
            $this->assertDenied(fn() => $reader->projects($filters));
        }
        self::assertSame(403, $this->overview($user, 81)['code']);
        self::assertSame(403, $this->overview($user, 90)['code']);
        $bridge = new InvestmentOperatingBridgeService($reader, null, static fn(): string => '2026-10-02');
        $denied = $bridge->overview(10, [80, 81], 81, '2026-09');
        self::assertSame('read_failed', $denied['status']);
        self::assertNull($denied['projects']);
        self::assertNull($denied['totals']);
        $wrongTenant = $bridge->overview(20, [80], 80, '2026-09');
        self::assertSame('read_failed', $wrongTenant['status']);
        self::assertNull($wrongTenant['projects']);

        $hotelDeniedUser = $this->user(['investment.view'], 9);
        Db::name('user_hotel_permissions')->where('user_id', 9)->update(['can_investment' => 0]);
        self::assertTrue($hotelDeniedUser->hasHotelPermission(80, 'operation.view'));
        self::assertFalse($hotelDeniedUser->hasHotelPermission(80, 'investment.view'));
        $hotelDeniedReader = new InvestmentPaybackService($hotelDeniedUser, readOnly: true);
        $this->assertDenied(fn() => $hotelDeniedReader->projects(['hotel_id' => 80]));
        self::assertSame('investment_view_permission_required', $this->overview($hotelDeniedUser)['data']['investment_bridge']['reason_code']);
    }

    public function testSimulateOnlyRetainsOriginalWritableLedgerAndHotelBoundary(): void
    {
        $user = $this->user(['investment.simulate']);
        self::assertFalse($user->hasHotelPermission(80, 'investment.view'));
        $writer = new InvestmentPaybackService($user);
        $saved = $writer->saveProject(Fixture::project(['client_request_id' => 'simulate-only-project']));
        $id = $saved['project']['id'];
        $savedEntry = $writer->saveEntry($id, ['kind' => 'investment', 'amount' => '100.01', 'date' => '2026-09-01', 'source' => 'synthetic-simulate-only', 'client_request_id' => 'simulate-only-entry']);
        self::assertSame('100.01', $savedEntry['summary']['invested_amount']);
        self::assertSame($savedEntry, $writer->detail($id));
        self::assertSame(['order' => [$id]], $writer->saveLayout(['order' => [$id]]));
        self::assertSame([$id], $writer->projects()['layout']['order']);
        $this->assertDenied(fn() => $writer->saveProject(Fixture::project(['hotel_id' => 81, 'client_request_id' => 'simulate-only-foreign'])));
        $reader = new InvestmentPaybackService($user, readOnly: true);
        $this->assertDenied(fn() => $reader->saveLayout(['order' => [$id]]));
    }

    public function testHotelFilterPrecedesCountPaginationAndCashSummaryAndPreservesUnfilteredList(): void
    {
        $saved = $this->seedProject();
        $template = Db::name('investment_payback_projects')->where('id', $saved['project']['id'])->find();
        unset($template['id']);
        foreach ([['hotel_id' => 81], ['hotel_id' => null], ['tenant_id' => 20]] as $index => $scope) {
            Db::name('investment_payback_projects')->insert(array_replace($template, $scope, ['client_request_id' => 'scope-' . $index]));
        }
        $user = $this->user(['investment.view'], 7, [80, 81]);
        $summarized = [];
        $calculator = new class($summarized) extends InvestmentPaybackCalculator {
            public function __construct(private array &$seen) {}
            public function summarize(array $project, array $entries, ?string $asOf = null): array
            {
                $this->seen[] = ['id' => $project['id'], 'hotel_id' => $project['hotel_id'], 'tenant_id' => $project['tenant_id']];
                return parent::summarize($project, $entries, $asOf);
            }
        };
        $reader = new InvestmentPaybackService($user, calculator: $calculator, readOnly: true);
        $unfiltered = $reader->projects(['as_of' => '2026-09-30', 'page_size' => 2]);
        self::assertSame(3, $unfiltered['pagination']['total']);
        self::assertSame(2, $unfiltered['pagination']['total_page']);
        self::assertCount(2, $unfiltered['list']);
        self::assertCount(1, $reader->projects(['as_of' => '2026-09-30', 'page_size' => 2, 'page' => 2])['list']);
        $summarized = [];
        for ($index = 1; $index <= 100; $index++) {
            Db::name('investment_payback_projects')->insert(array_replace($template, ['client_request_id' => 'current-hotel-' . $index, 'archived_at' => $index === 100 ? '2026-09-29' : null]));
        }
        for ($index = 1; $index <= 150; $index++) {
            Db::name('investment_payback_projects')->insert(array_replace($template, ['hotel_id' => 81, 'opening_invested' => 'malformed-other-hotel-cash', 'client_request_id' => 'other-hotel-' . $index]));
        }
        Db::name('investment_payback_projects')->where('tenant_id', 20)->update(['opening_invested' => 'malformed-other-tenant-cash']);
        $bridge = new InvestmentOperatingBridgeService($reader, null, static fn(): string => '2026-10-02');
        $result = $bridge->overview(10, [80, 81], 80, '2026-09');
        self::assertSame('ready', $result['status']);
        self::assertSame(2, $result['coverage']['pages_read']);
        self::assertSame(101, $result['coverage']['scanned_project_count']);
        self::assertSame(101, $result['coverage']['linked_project_count']);
        self::assertTrue($result['coverage']['read_complete']);
        self::assertSame('10101.01', $result['totals']['actual_invested']);
        self::assertCount(101, $summarized);
        self::assertSame([80], array_values(array_unique(array_column($summarized, 'hotel_id'))));
        self::assertSame([10], array_values(array_unique(array_column($summarized, 'tenant_id'))));
        $page = $reader->projects(['hotel_id' => '80', 'tenant_id' => 10, 'include_archived' => true, 'as_of' => '2026-09-30', 'page_size' => 100, 'page' => 2]);
        self::assertSame(101, $page['pagination']['total']);
        self::assertSame(2, $page['pagination']['total_page']);
        self::assertCount(1, $page['list']);
        self::assertSame([], $reader->projects(['hotel_id' => 80, 'include_archived' => true, 'as_of' => '2026-09-30', 'page_size' => 100, 'page' => 3])['list']);
        self::assertStringNotContainsString('malformed-other', json_encode($result));
    }

    private function user(array $investmentCapabilities, int $id = 7, array $hotelIds = [80]): User
    {
        $user = new User(['id' => $id, 'tenant_id' => 10, 'role_id' => Role::BETA_USER]);
        $user->setRelation('role', new Role(['id' => Role::BETA_USER, 'name' => 'synthetic_bridge_role', 'status' => Role::STATUS_ENABLED, 'level' => 2, 'permissions' => json_encode(array_merge(['operation.view'], $investmentCapabilities))]));
        foreach ($hotelIds as $hotelId) {
            Db::name('user_hotel_permissions')->insert(['user_id' => $id, 'tenant_id' => 10, 'hotel_id' => $hotelId, 'status' => 'active', 'can_view' => 1, 'can_operation' => 1, 'can_investment' => 1]);
        }
        return $user;
    }

    private function seedProject(): array
    {
        return Fixture::ledger()->saveProject(Fixture::project(['history_complete_through' => '2026-09-30', 'opening_as_of' => '2026-08-31', 'opening_invested' => '100.01', 'opening_recovered' => '30.01', 'opening_source' => 'synthetic-bank-reference']));
    }

    private function overview(User $user, int $hotelId = 80): array
    {
        $controller = (new ReflectionClass(OperatingFinance::class))->newInstanceWithoutConstructor();
        $request = new class($hotelId) {
            public function __construct(private int $hotelId) {}
            public function param($key = null, $default = null): mixed
            {
                $values = ['hotel_id' => $this->hotelId, 'platform' => 'manual_all_channels', 'business_date' => '2026-09-30', 'period_month' => '2026-09', 'stay_date' => '2026-10-01'];
                return $values[$key] ?? $default;
            }
        };
        (new ReflectionProperty(OperatingFinance::class, 'request'))->setValue($controller, $request);
        (new ReflectionProperty(OperatingFinance::class, 'currentUser'))->setValue($controller, $user);
        return $controller->overview()->getData();
    }

    private function records(): array
    {
        return array_map(static fn(string $table): array => Db::name($table)->order('id')->select()->toArray(), ['investment_payback_projects', 'investment_payback_entries', 'investment_payback_events', 'system_config']);
    }

    private function assertDenied(callable $action): void
    {
        try {
            $action();
            self::fail('Expected authorization denial');
        } catch (RuntimeException $error) {
            self::assertSame(403, $error->getCode());
        }
    }
}
