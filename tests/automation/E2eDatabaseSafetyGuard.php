<?php
declare(strict_types=1);

namespace Tests\Automation;

use RuntimeException;

final class E2eDatabaseSafetyGuard
{
    /**
     * @return array{
     *     database_name: string,
     *     mode: 'dedicated_test_database'|'explicit_shared_database',
     *     dedicated_database: bool,
     *     database_host_scope: 'local'|'explicit_remote'
     * }
     */
    public static function inspect(
        string $databaseName,
        string $databaseHost,
        string $allowSharedDatabase,
        string $allowRemoteTestDatabase
    ): array {
        $databaseName = trim($databaseName);
        if ($databaseName === '') {
            throw new RuntimeException('Isolated E2E database guard could not resolve the active database name');
        }

        $databaseHost = strtolower(trim($databaseHost));
        $localHosts = ['127.0.0.1', 'localhost', '::1', '[::1]'];
        $localHost = in_array($databaseHost, $localHosts, true);
        $remoteOptIn = hash_equals('1', trim($allowRemoteTestDatabase));
        if (!$localHost && !$remoteOptIn) {
            throw new RuntimeException(
                'Isolated E2E database guard refused a non-loopback database host; '
                . 'set SUXI_E2E_ALLOW_REMOTE_TEST_DB=1 only for an intentional remote test database run'
            );
        }

        $dedicatedDatabase = preg_match(
            '/(?:^|[_-])(?:test(?:ing)?|e2e)(?:$|[_-])/iD',
            $databaseName
        ) === 1;
        $sharedOptIn = hash_equals('1', trim($allowSharedDatabase));
        if (!$dedicatedDatabase && !$sharedOptIn) {
            throw new RuntimeException(
                sprintf(
                    'Isolated E2E database guard refused database "%s"; use a dedicated '
                    . '*_test/*_testing/*_e2e database or set SUXI_E2E_ALLOW_SHARED_DB=1 '
                    . 'for an intentional shared-database run',
                    $databaseName
                )
            );
        }

        return [
            'database_name' => $databaseName,
            'mode' => $dedicatedDatabase ? 'dedicated_test_database' : 'explicit_shared_database',
            'dedicated_database' => $dedicatedDatabase,
            'database_host_scope' => $localHost ? 'local' : 'explicit_remote',
        ];
    }
}
