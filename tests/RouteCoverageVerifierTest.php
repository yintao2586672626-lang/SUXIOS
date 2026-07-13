<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class RouteCoverageVerifierTest extends TestCase
{
    public function testAutomaticControllerRoutingIsDisabled(): void
    {
        $config = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'route.php';

        self::assertTrue(
            $config['url_route_must'] ?? false,
            'Controller/action fallback routing must stay disabled so requests cannot bypass route middleware.'
        );
    }

    public function testRouteCoverageVerifierResolvesConcernTraitActionsThroughController(): void
    {
        $script = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_route_coverage.php';
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1';

        exec($command, $output, $exitCode);
        $text = implode("\n", $output);

        self::assertSame(0, $exitCode, $text);
        self::assertStringContainsString('All public controller actions are covered by route/app.php.', $text);
        self::assertStringNotContainsString('app\\controller\\concern\\', $text);
    }
}
