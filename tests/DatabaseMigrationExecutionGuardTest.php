<?php
declare(strict_types=1);

namespace Tests;

use app\service\DatabaseMigrationExecutionGuard;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DatabaseMigrationExecutionGuardTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryRoots = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryRoots) as $root) {
            $this->removeTree($root);
        }
        $this->temporaryRoots = [];
    }

    public function testPrimaryCheckoutCanMigrateSharedDatabase(): void
    {
        $root = $this->temporaryRoot();
        mkdir($root . DIRECTORY_SEPARATOR . '.git');

        DatabaseMigrationExecutionGuard::assertAllowed($root, 'hotelx', 'mysql', []);
        self::assertTrue(true);
    }

    public function testStandaloneDeploymentCanMigrateSharedDatabase(): void
    {
        $root = $this->temporaryRoot();

        DatabaseMigrationExecutionGuard::assertAllowed($root, 'hotelx', 'mysql', []);
        self::assertTrue(true);
    }

    public function testLinkedWorktreeRefusesSharedDatabaseBeforeMigration(): void
    {
        $root = $this->linkedWorktreeRoot();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('linked_worktree_shared_database_migration_refused');
        $this->expectExceptionMessage("cannot migrate database 'hotelx'");
        DatabaseMigrationExecutionGuard::assertAllowed($root, 'hotelx', 'mysql', []);
    }

    public function testTestNamedDatabaseStillRequiresExplicitE2eContract(): void
    {
        $root = $this->linkedWorktreeRoot();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('linked_worktree_shared_database_migration_refused');
        DatabaseMigrationExecutionGuard::assertAllowed($root, 'hotelx_feature_e2e', 'mysql', []);
    }

    public function testLinkedWorktreeAllowsMatchingDedicatedE2eDatabase(): void
    {
        $root = $this->linkedWorktreeRoot();

        DatabaseMigrationExecutionGuard::assertAllowed(
            $root,
            'hotelx_feature_e2e',
            'mysql',
            [
                'SUXI_E2E_DB_OVERRIDE' => '1',
                'SUXI_E2E_DB_NAME' => 'hotelx_feature_e2e',
            ]
        );
        self::assertTrue(true);
    }

    public function testE2eOverrideMustMatchTheActualTargetDatabase(): void
    {
        $root = $this->linkedWorktreeRoot();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('linked_worktree_shared_database_migration_refused');
        DatabaseMigrationExecutionGuard::assertAllowed(
            $root,
            'hotelx',
            'mysql',
            [
                'SUXI_E2E_DB_OVERRIDE' => '1',
                'SUXI_E2E_DB_NAME' => 'hotelx_feature_e2e',
            ]
        );
    }

    public function testExactOneShotDatabaseApprovalAllowsLinkedWorktree(): void
    {
        $root = $this->linkedWorktreeRoot();

        DatabaseMigrationExecutionGuard::assertAllowed(
            $root,
            'hotelx',
            'mysql',
            [
                DatabaseMigrationExecutionGuard::SHARED_DATABASE_APPROVAL_ENV => 'hotelx',
            ]
        );
        self::assertTrue(true);
    }

    public function testApprovalForAnotherDatabaseDoesNotBroadenAuthority(): void
    {
        $root = $this->linkedWorktreeRoot();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('linked_worktree_shared_database_migration_refused');
        DatabaseMigrationExecutionGuard::assertAllowed(
            $root,
            'hotelx',
            'mysql',
            [
                DatabaseMigrationExecutionGuard::SHARED_DATABASE_APPROVAL_ENV => 'another_database',
            ]
        );
    }

    public function testOfficialCommandChecksGuardBeforeSchemaCacheMutation(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/command/MigrateDatabaseSchema.php');
        $guardOffset = strpos($source, 'DatabaseMigrationExecutionGuard::assertAllowed');
        $cacheOffset = strpos($source, 'new SchemaVersionStatusCache');

        self::assertNotFalse($guardOffset);
        self::assertNotFalse($cacheOffset);
        self::assertLessThan($cacheOffset, $guardOffset);
    }

    public function testSchemaServiceGuardsEverySchemaWriteEntrypoint(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/service/SchemaVersionService.php');

        self::assertSame(3, substr_count($source, '$this->assertMigrationExecutionAllowed();'));
        self::assertStringContainsString("SELECT DATABASE()", $source);
    }

    private function linkedWorktreeRoot(): string
    {
        $root = $this->temporaryRoot();
        file_put_contents(
            $root . DIRECTORY_SEPARATOR . '.git',
            "gitdir: C:/tmp/repository/.git/worktrees/feature\n"
        );
        return $root;
    }

    private function temporaryRoot(): string
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'suxios-migration-guard-' . bin2hex(random_bytes(8));
        mkdir($root, 0777, true);
        $this->temporaryRoots[] = $root;
        return $root;
    }

    private function removeTree(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($root);
    }
}
