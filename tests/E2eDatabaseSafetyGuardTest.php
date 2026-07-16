<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Automation\E2eDatabaseSafetyGuard;

require_once dirname(__DIR__) . '/tests/automation/E2eDatabaseSafetyGuard.php';

final class E2eDatabaseSafetyGuardTest extends TestCase
{
    #[DataProvider('dedicatedDatabaseProvider')]
    public function testDedicatedLocalDatabaseNamesAreAllowed(string $databaseName): void
    {
        $result = E2eDatabaseSafetyGuard::inspect($databaseName, '127.0.0.1', '', '');

        self::assertSame('dedicated_test_database', $result['mode']);
        self::assertTrue($result['dedicated_database']);
        self::assertSame('local', $result['database_host_scope']);
    }

    /** @return array<string, array{string}> */
    public static function dedicatedDatabaseProvider(): array
    {
        return [
            'suffix test' => ['hotelx_test'],
            'suffix testing' => ['hotelx_testing'],
            'prefix test' => ['test_hotelx'],
            'suffix e2e' => ['hotelx_e2e'],
        ];
    }

    public function testSharedDatabaseIsRejectedWithoutExactOptIn(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SUXI_E2E_ALLOW_SHARED_DB=1');

        E2eDatabaseSafetyGuard::inspect('hotelx', 'localhost', '', '');
    }

    public function testSimilarNonTestNameIsStillRejected(): void
    {
        $this->expectException(RuntimeException::class);

        E2eDatabaseSafetyGuard::inspect('hotelx_contest', 'localhost', '', '');
    }

    public function testSharedDatabaseRequiresExactOneOptIn(): void
    {
        $result = E2eDatabaseSafetyGuard::inspect('hotelx', 'localhost', '1', '');

        self::assertSame('explicit_shared_database', $result['mode']);
        self::assertFalse($result['dedicated_database']);
    }

    public function testRemoteDatabaseIsRejectedEvenWhenNamedAsTest(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SUXI_E2E_ALLOW_REMOTE_TEST_DB=1');

        E2eDatabaseSafetyGuard::inspect('hotelx_test', '10.20.30.40', '', '');
    }

    public function testRemoteTestDatabaseRequiresExplicitRemoteOptIn(): void
    {
        $result = E2eDatabaseSafetyGuard::inspect('hotelx_test', '10.20.30.40', '', '1');

        self::assertSame('dedicated_test_database', $result['mode']);
        self::assertSame('explicit_remote', $result['database_host_scope']);
    }
}
