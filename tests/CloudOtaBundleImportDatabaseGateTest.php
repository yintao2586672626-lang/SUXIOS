<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class CloudOtaBundleImportDatabaseGateTest extends TestCase
{
    public function testDatabaseNameGateAcceptsOnlyExplicitTestIdentities(): void
    {
        $gate = new ReflectionMethod(CloudOtaBundleImportIntegrationTest::class, 'isDedicatedTestDatabaseName');

        self::assertTrue($gate->invoke(null, 'hotelx_ci_test'));
        self::assertTrue($gate->invoke(null, 'hotelx-e2e'));
        self::assertTrue($gate->invoke(null, 'testing_hotelx'));
        self::assertFalse($gate->invoke(null, 'hotelx'));
        self::assertFalse($gate->invoke(null, 'hotelx_production'));
        self::assertFalse($gate->invoke(null, 'contest'));
        self::assertFalse($gate->invoke(null, ''));
    }

    public function testEnvironmentGateRunsBeforeApplicationInitialization(): void
    {
        $method = new ReflectionMethod(CloudOtaBundleImportIntegrationTest::class, 'setUpBeforeClass');
        $file = $method->getFileName();
        self::assertIsString($file);
        $lines = file($file);
        self::assertIsArray($lines);
        $source = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $skip = strpos($source, 'self::markTestSkipped');
        $initialize = strpos($source, '(new App(dirname(__DIR__)))->initialize()');
        self::assertIsInt($skip);
        self::assertIsInt($initialize);
        self::assertLessThan($initialize, $skip);
    }
}
