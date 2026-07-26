<?php
declare(strict_types=1);

namespace Tests;

use app\service\LocalStatePathPolicy;
use PHPUnit\Framework\TestCase;

final class LocalStatePathPolicyTest extends TestCase
{
    public function testProductionSingleInstancePathsAreNormalizedAndScoped(): void
    {
        $environment = [
            'SUXIOS_DEPLOYMENT_MODE' => 'single_instance',
            'SUXIOS_REQUIRE_PERSISTENT_LOCAL_STATE' => 'true',
            'SUXIOS_CACHE_PATH' => '/var/lib/suxios/app-cache/',
            'SUXIOS_LOCAL_LOCK_PATH' => '/var/lib/suxios/app-locks/',
        ];

        $policy = LocalStatePathPolicy::resolve($environment);

        self::assertSame('single_instance', $policy['deployment_mode']);
        self::assertSame('/var/lib/suxios/app-cache', $policy['cache_path']);
        self::assertSame('/var/lib/suxios/app-locks', $policy['lock_path']);
        self::assertTrue($policy['persistent_paths_required']);
        self::assertSame(
            '/var/lib/suxios/app-locks' . DIRECTORY_SEPARATOR . 'competitor-task',
            LocalStatePathPolicy::scopedLockDirectory('competitor-task', $environment)
        );
    }

    public function testLocalDevelopmentMayKeepHistoricalRuntimeFallback(): void
    {
        $policy = LocalStatePathPolicy::resolve([
            'SUXIOS_DEPLOYMENT_MODE' => '',
            'SUXIOS_REQUIRE_PERSISTENT_LOCAL_STATE' => 'false',
            'SUXIOS_CACHE_PATH' => '',
            'SUXIOS_LOCAL_LOCK_PATH' => '',
        ]);

        self::assertSame('single_instance', $policy['deployment_mode']);
        self::assertSame('', $policy['cache_path']);
        self::assertSame('', $policy['lock_path']);
        self::assertSame('', LocalStatePathPolicy::scopedLockDirectory('rate-limit', [
            'SUXIOS_DEPLOYMENT_MODE' => 'single_instance',
            'SUXIOS_REQUIRE_PERSISTENT_LOCAL_STATE' => 'false',
            'SUXIOS_CACHE_PATH' => '',
            'SUXIOS_LOCAL_LOCK_PATH' => '',
        ]));
    }

    public function testPersistentModeFailsClosedWhenEitherPathIsMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Persistent local state requires absolute');

        LocalStatePathPolicy::resolve([
            'SUXIOS_DEPLOYMENT_MODE' => 'single_instance',
            'SUXIOS_REQUIRE_PERSISTENT_LOCAL_STATE' => 'true',
            'SUXIOS_CACHE_PATH' => '/var/lib/suxios/app-cache',
            'SUXIOS_LOCAL_LOCK_PATH' => '',
        ]);
    }

    public function testRelativePersistentPathIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must be an absolute path');

        LocalStatePathPolicy::resolve([
            'SUXIOS_DEPLOYMENT_MODE' => 'single_instance',
            'SUXIOS_REQUIRE_PERSISTENT_LOCAL_STATE' => 'true',
            'SUXIOS_CACHE_PATH' => 'runtime/cache',
            'SUXIOS_LOCAL_LOCK_PATH' => '/var/lib/suxios/app-locks',
        ]);
    }

    public function testMultiInstanceModeFailsBeforeUnsafeStartup(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('only supports single_instance');

        LocalStatePathPolicy::resolve([
            'SUXIOS_DEPLOYMENT_MODE' => 'multi_instance',
            'SUXIOS_REQUIRE_PERSISTENT_LOCAL_STATE' => 'true',
            'SUXIOS_CACHE_PATH' => '/var/lib/suxios/app-cache',
            'SUXIOS_LOCAL_LOCK_PATH' => '/var/lib/suxios/app-locks',
        ]);
    }

    public function testDeploymentVerifierBootsSharedEnvironmentConfiguration(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__) . '/scripts/verify_single_instance_state_paths.php'
        );

        self::assertStringContainsString('new App(dirname(__DIR__))', $source);
        self::assertStringContainsString('$app->initialize()', $source);
        self::assertStringContainsString("config->get('cache.local_state'", $source);
    }
}
