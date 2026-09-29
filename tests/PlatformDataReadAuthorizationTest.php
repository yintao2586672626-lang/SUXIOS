<?php
declare(strict_types=1);

namespace Tests;

use app\controller\concern\OnlineDataSupportConcern;
use app\service\PlatformDataSyncService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\Collection;
use think\Container;
use think\DbManager;

final class PlatformDataReadAuthorizationTest extends TestCase
{
    private Container $originalContainer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalContainer = Container::getInstance();
        $container = new Container();
        $container->instance(DbManager::class, new PlatformDataReadAuthorizationStore());
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->originalContainer);
        parent::tearDown();
    }

    public static function readEntries(): array
    {
        return [
            'data sources' => ['listDataSources'],
            'sync tasks' => ['listSyncTasks'],
            'sync logs' => ['listSyncLogs'],
        ];
    }

    #[DataProvider('readEntries')]
    public function testPrimaryHotelPermissionDoesNotExposeOtherHotelsOnlineData(string $entry): void
    {
        $user = $this->user([7]);
        $this->passPrimaryHotelGate($user);

        $rows = (new PlatformDataSyncService([]))->{$entry}($user);

        self::assertSame([7], array_column($rows, 'system_hotel_id'));
    }

    #[DataProvider('readEntries')]
    public function testDeniedSourceOrTaskFilterCannotWidenTheViewableHotelScope(string $entry): void
    {
        $filters = $entry === 'listSyncLogs'
            ? ['sync_task_id' => 108]
            : ['data_source_id' => 108, 'system_hotel_id' => 8];

        $rows = (new PlatformDataSyncService([]))->{$entry}($this->user([7]), $filters);

        self::assertSame([], $rows);
    }

    #[DataProvider('readEntries')]
    public function testAuthorizedHotelStillReturnsRowsWhenExplicitlySelected(string $entry): void
    {
        $rows = (new PlatformDataSyncService([]))->{$entry}($this->user([7]), [
            'system_hotel_id' => 7,
            'data_source_id' => 107,
            'sync_task_id' => 107,
        ]);

        self::assertSame([7], array_column($rows, 'system_hotel_id'));
    }

    #[DataProvider('readEntries')]
    public function testNoOnlineDataPermissionProducesNoRows(string $entry): void
    {
        self::assertSame([], (new PlatformDataSyncService([]))->{$entry}($this->user([])));
    }

    #[DataProvider('readEntries')]
    public function testPermissionLookupFailureDoesNotReturnUnscopedRows(string $entry): void
    {
        self::assertSame([], (new PlatformDataSyncService([]))->{$entry}($this->user([7], false, true)));
    }

    #[DataProvider('readEntries')]
    public function testSuperAdminRetainsExplicitCrossHotelScope(string $entry): void
    {
        $rows = (new PlatformDataSyncService([]))->{$entry}($this->user([], true));

        self::assertSame([9, 8, 7], array_column($rows, 'system_hotel_id'));
    }

    #[DataProvider('readEntries')]
    public function testTenantScopeStillExcludesForeignTenantFromThePermittedHotelList(string $entry): void
    {
        $rows = (new PlatformDataSyncService([]))->{$entry}($this->user([7, 8, 9]));

        self::assertSame([8, 7], array_column($rows, 'system_hotel_id'));
    }

    private function user(array $viewableHotels, bool $superAdmin = false, bool $permissionFailure = false): object
    {
        return new class($viewableHotels, $superAdmin, $permissionFailure) {
            public int $tenant_id = 11;
            public int $hotel_id = 7;

            public function __construct(
                private array $viewableHotels,
                private bool $superAdmin,
                private bool $permissionFailure
            ) {
            }

            public function isSuperAdmin(): bool { return $this->superAdmin; }
            public function getPermittedHotelIds(): array { return [7, 8, 9]; }

            public function hasHotelPermission(int $hotelId, string $permission): bool
            {
                if ($this->permissionFailure) {
                    throw new RuntimeException('Fixture permission lookup unavailable.');
                }
                return $permission === 'can_view_online_data' && in_array($hotelId, $this->viewableHotels, true);
            }

            public function hasHotelPermissionOrFail(int $hotelId, string $permission, string $message): void
            {
                if (!$this->hasHotelPermission($hotelId, $permission)) {
                    throw new RuntimeException($message, 403);
                }
            }
        };
    }

    private function passPrimaryHotelGate(object $user): void
    {
        $gate = new class($user) {
            use OnlineDataSupportConcern;

            public object $request;

            public function __construct(public object $currentUser)
            {
                $this->request = new class {
                    public function param(string $key, mixed $default = null): mixed { return $default; }
                };
            }

            public function authorizeList(): void { $this->checkActionPermission('can_view_online_data'); }
        };
        $gate->authorizeList();
    }
}

/** In-memory facade substitute: these tests never connect to a database. */
final class PlatformDataReadAuthorizationStore
{
    public function name(string $table): PlatformDataReadAuthorizationQuery
    {
        if ($table === 'hotels') {
            return new PlatformDataReadAuthorizationQuery([
                ['id' => 7, 'tenant_id' => 11],
                ['id' => 8, 'tenant_id' => 11],
                ['id' => 9, 'tenant_id' => 12],
            ]);
        }
        if (!in_array($table, ['platform_data_sources', 'platform_data_sync_tasks', 'platform_data_sync_logs'], true)) {
            throw new RuntimeException('Unexpected fixture table: ' . $table);
        }
        return new PlatformDataReadAuthorizationQuery(array_map(static fn(int $hotelId): array => [
            'id' => 100 + $hotelId,
            'tenant_id' => $hotelId === 9 ? 12 : 11,
            'system_hotel_id' => $hotelId,
            'data_source_id' => 100 + $hotelId,
            'sync_task_id' => 100 + $hotelId,
            'platform' => $hotelId === 8 ? 'meituan' : 'ctrip',
            'data_type' => 'order',
            'ingestion_method' => 'manual',
            'status' => 'success',
        ], [7, 8, 9]));
    }
}

final class PlatformDataReadAuthorizationQuery
{
    private array $conditions = [];
    private bool $descending = false;
    private ?int $rowLimit = null;

    public function __construct(private array $rows) {}
    public function withoutField(string $field): self { return $this; }
    public function field(string $fields): self { return $this; }

    public function order(string $field, string $direction): self
    {
        if ($field !== 'id') {
            throw new RuntimeException('Unexpected fixture order: ' . $field);
        }
        $this->descending = $direction === 'desc';
        return $this;
    }

    public function limit(int $limit): self { $this->rowLimit = $limit; return $this; }

    public function where(string $field, mixed $value): self
    {
        $this->conditions[] = static fn(array $row): bool => ($row[$field] ?? null) === $value;
        return $this;
    }

    public function whereIn(string $field, array $values): self
    {
        $this->conditions[] = static fn(array $row): bool => in_array($row[$field] ?? null, $values, true);
        return $this;
    }

    public function whereRaw(string $expression): self
    {
        if ($expression !== '1=0') {
            throw new RuntimeException('Unexpected fixture expression: ' . $expression);
        }
        $this->conditions[] = static fn(array $row): bool => false;
        return $this;
    }

    public function select(): Collection
    {
        $rows = array_values(array_filter($this->rows, function (array $row): bool {
            foreach ($this->conditions as $condition) {
                if (!$condition($row)) {
                    return false;
                }
            }
            return true;
        }));
        if ($this->descending) {
            usort($rows, static fn(array $left, array $right): int => $right['id'] <=> $left['id']);
        }
        return new Collection($this->rowLimit === null ? $rows : array_slice($rows, 0, $this->rowLimit));
    }

    public function value(string $field): mixed
    {
        $rows = $this->select()->toArray();
        return $rows[0][$field] ?? null;
    }
}
