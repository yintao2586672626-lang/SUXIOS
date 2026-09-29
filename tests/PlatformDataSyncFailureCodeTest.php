<?php
declare(strict_types=1);

use app\service\PlatformDataSyncService;
use PHPUnit\Framework\TestCase;

final class PlatformDataSyncFailureCodeTest extends TestCase
{
    public function testProfileSessionProbeFailureIsNotReportedAsCredentialFailure(): void
    {
        $service = new PlatformDataSyncService();
        $failureCode = new ReflectionMethod($service, 'safeOtaExecutionFailureCode');
        $failureCode->setAccessible(true);

        self::assertSame(
            'profile_session_probe_failed',
            $failureCode->invoke(
                $service,
                new RuntimeException(
                    'browser_profile synchronization requires profile_session_probe_failed before capture.'
                )
            )
        );

        $taskMessage = new ReflectionMethod($service, 'safeSyncTaskMessage');
        $taskMessage->setAccessible(true);
        self::assertSame(
            'profile_session_probe_failed',
            $taskMessage->invoke($service, 'failed', 'profile_session_probe_failed')
        );
    }
}
