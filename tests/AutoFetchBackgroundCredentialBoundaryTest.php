<?php
declare(strict_types=1);

namespace Tests;

use app\command\AutoFetchOnlineDataOnce;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AutoFetchBackgroundCredentialBoundaryTest extends TestCase
{
    public function testNewBackgroundTaskInputNeverPersistsAuthorization(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__) . '/app/controller/concern/AutoFetchConcern.php'
        );

        self::assertStringContainsString('$authorizationEnv = $this->autoFetchAuthorizationEnvName($taskId);', $source);
        self::assertStringContainsString("unset(\$persistedTask['authorization']);", $source);
        self::assertStringContainsString('SUXI_AUTO_FETCH_AUTH_', $source);
        self::assertStringContainsString('isValidAutoFetchTaskId($taskId)', $source);
        self::assertStringNotContainsString(
            'file_put_contents($inputPath, json_encode($task,',
            $source
        );
    }

    public function testWorkerConsumesAndClearsTemporaryAuthorizationEnvironment(): void
    {
        $envName = 'SUXI_AUTO_FETCH_AUTH_0123456789ABCDEF01234567';
        putenv($envName . '=Bearer temporary-secret');

        $method = new ReflectionMethod(AutoFetchOnlineDataOnce::class, 'resolveAuthorization');
        $authorization = $method->invoke(new AutoFetchOnlineDataOnce(), [
            'authorization_env' => $envName,
        ]);

        self::assertSame('Bearer temporary-secret', $authorization);
        self::assertFalse(getenv($envName));
    }

    public function testWorkerKeepsCompatibilityForAlreadyQueuedLegacyTaskOnly(): void
    {
        $method = new ReflectionMethod(AutoFetchOnlineDataOnce::class, 'resolveAuthorization');

        self::assertSame('Bearer legacy', $method->invoke(new AutoFetchOnlineDataOnce(), [
            'authorization' => 'Bearer legacy',
        ]));
    }
}
