<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;

final class DatabaseMigrationExecutionGuard
{
    public const SHARED_DATABASE_APPROVAL_ENV = 'SUXI_LINKED_WORKTREE_SHARED_DB_MIGRATION_APPROVED';

    private const DEDICATED_TEST_DATABASE_PATTERN =
        '/(?:^|[_-])(?:test(?:ing)?|e2e)(?:$|[_-])/iD';

    /**
     * A linked Git worktree must not advance a shared database by accident.
     * The primary checkout has a .git directory; linked worktrees have a .git
     * file that points back to the common repository metadata.
     *
     * @param array<string, string>|null $environment
     */
    public static function assertAllowed(
        string $root,
        string $databaseName,
        string $databaseType = 'mysql',
        ?array $environment = null
    ): void {
        if (strtolower(trim($databaseType)) !== 'mysql') {
            return;
        }

        $resolvedRoot = realpath($root);
        if (!is_string($resolvedRoot)) {
            throw new RuntimeException('Database migration root does not exist.');
        }

        $gitMarker = rtrim($resolvedRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.git';
        if (!is_file($gitMarker)) {
            return;
        }

        $databaseName = trim($databaseName);
        $e2eOverride = self::environmentValue($environment, 'SUXI_E2E_DB_OVERRIDE') === '1';
        $e2eDatabaseName = trim(self::environmentValue($environment, 'SUXI_E2E_DB_NAME'));
        $dedicatedDatabase = preg_match(self::DEDICATED_TEST_DATABASE_PATTERN, $databaseName) === 1;
        if ($e2eOverride
            && $dedicatedDatabase
            && $e2eDatabaseName !== ''
            && strcasecmp($e2eDatabaseName, $databaseName) === 0
        ) {
            return;
        }

        $approvedDatabase = trim(self::environmentValue(
            $environment,
            self::SHARED_DATABASE_APPROVAL_ENV
        ));
        if ($databaseName !== ''
            && $approvedDatabase !== ''
            && strcasecmp($approvedDatabase, $databaseName) === 0
        ) {
            return;
        }

        throw new RuntimeException(
            'linked_worktree_shared_database_migration_refused: '
            . "linked Git worktree cannot migrate database '{$databaseName}'. "
            . 'Use SUXI_E2E_DB_OVERRIDE=1 with the same dedicated *_test/*_testing/*_e2e '
            . 'SUXI_E2E_DB_NAME, or run shared-database migrations from the primary HOTEL checkout. '
            . 'After explicit approval only, set '
            . self::SHARED_DATABASE_APPROVAL_ENV
            . "='{$databaseName}' for that one command."
        );
    }

    /** @param array<string, string>|null $environment */
    private static function environmentValue(?array $environment, string $key): string
    {
        if (is_array($environment)) {
            return (string)($environment[$key] ?? '');
        }

        $value = getenv($key);
        return $value === false ? '' : (string)$value;
    }
}
