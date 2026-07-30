<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Base;
use app\controller\OperationLogController;
use app\model\Role;
use app\model\User;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;
use think\Request;

final class OperationLogHighRiskSummaryTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();

        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'operation_log_high_risk_summary_' . getmypid() . '.sqlite';
        @unlink(self::$sqlitePath);

        $config = self::$originalDatabaseConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$sqlitePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($config, 'database');
        Db::connect(null, true);

        Db::execute('CREATE TABLE operation_logs (
            id INTEGER PRIMARY KEY,
            user_id INTEGER NULL,
            hotel_id INTEGER NULL,
            module VARCHAR(50),
            action VARCHAR(50),
            description TEXT,
            ip VARCHAR(50),
            user_agent VARCHAR(255),
            create_time DATETIME,
            error_info TEXT NULL,
            extra_data TEXT NULL
        )');
        Db::execute('CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            username VARCHAR(50),
            realname VARCHAR(50),
            password VARCHAR(255) NULL
        )');
        Db::execute('CREATE TABLE hotels (
            id INTEGER PRIMARY KEY,
            name VARCHAR(100)
        )');
    }

    public static function tearDownAfterClass(): void
    {
        Db::connect()->close();
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        if (is_file(self::$sqlitePath) && !unlink(self::$sqlitePath)) {
            throw new RuntimeException('Unable to remove operation-log high-risk SQLite fixture.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Db::name('operation_logs')->delete(true);
    }

    public function testBenignRecentRowsCannotHideAnOlderHighRiskCandidate(): void
    {
        $now = date('Y-m-d H:i:s');
        Db::name('operation_logs')->insert($this->row(1, 'hotel', 'delete', $now));

        $benignRows = [];
        for ($id = 2; $id <= 101; $id++) {
            $benignRows[] = $this->row($id, 'online_data', 'view_data', $now);
        }
        Db::name('operation_logs')->insertAll($benignRows);

        $payload = $this->summary(20);

        self::assertSame(['delete'], array_column($payload['list'], 'action'));
        self::assertFalse($payload['truncated']);
        self::assertSame('sql_filtered_high_risk_candidates', $payload['scan_scope']['type']);
        self::assertSame(1, $payload['scan_scope']['fetched_candidate_count']);
        self::assertTrue($payload['scan_scope']['complete_within_limit']);
    }

    public function testIdentityPermissionHotelScopeAndDeviceChangesAreHighRiskAndTruncatedTruthfully(): void
    {
        $now = date('Y-m-d H:i:s');
        $events = [
            ['auth', 'change_password'],
            ['auth', 'reset_password'],
            ['role', 'update'],
            ['user', 'batch_hotel_assignment'],
            ['competitor_device', 'rotate_token'],
            ['competitor_device', 'status'],
        ];
        foreach ($events as $index => [$module, $action]) {
            Db::name('operation_logs')->insert($this->row($index + 1, $module, $action, $now));
        }

        $completePayload = $this->summary(20);
        $returned = array_map(
            static fn(array $row): string => $row['module'] . ':' . $row['action'],
            $completePayload['list']
        );
        foreach ($events as [$module, $action]) {
            self::assertContains($module . ':' . $action, $returned);
        }
        foreach ($completePayload['list'] as $row) {
            self::assertSame('high', $row['risk_priority'], $row['module'] . ':' . $row['action']);
        }
        self::assertFalse($completePayload['truncated']);

        $truncatedPayload = $this->summary(3);
        self::assertCount(3, $truncatedPayload['list']);
        self::assertTrue($truncatedPayload['truncated']);
        self::assertTrue($truncatedPayload['scan_scope']['truncated']);
        self::assertFalse($truncatedPayload['scan_scope']['complete_within_limit']);
        self::assertSame(4, $truncatedPayload['scan_scope']['fetched_candidate_count']);
        self::assertSame(4, $truncatedPayload['scan_scope']['candidate_limit']);
    }

    /** @return array<string, mixed> */
    private function row(int $id, string $module, string $action, string $createTime): array
    {
        return [
            'id' => $id,
            'user_id' => null,
            'hotel_id' => null,
            'module' => $module,
            'action' => $action,
            'description' => $module . ':' . $action,
            'ip' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'create_time' => $createTime,
            'error_info' => null,
            'extra_data' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function summary(int $limit): array
    {
        $controller = (new ReflectionClass(OperationLogController::class))->newInstanceWithoutConstructor();
        $admin = new User();
        $admin->id = 1;
        $admin->tenant_id = 0;
        $admin->role_id = Role::SUPER_ADMIN;
        request()->user = $admin;

        $currentUser = new ReflectionProperty(Base::class, 'currentUser');
        $currentUser->setAccessible(true);
        $currentUser->setValue($controller, $admin);

        $request = (new Request())->withGet([
            'days' => 7,
            'limit' => $limit,
        ]);
        $response = $controller->highRiskSummary($request);
        $payload = json_decode((string)$response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $payload['data'];
    }
}
