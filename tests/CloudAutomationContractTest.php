<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class CloudAutomationContractTest extends TestCase
{
    public function testRetryImplementationCannotCallPatrolOrReportGeneration(): void
    {
        $method = new ReflectionMethod(\app\service\CloudAutomationService::class, 'runRetry');
        $source = (string)file_get_contents($method->getFileName());
        $lines = explode("\n", $source);
        $body = implode("\n", array_slice(
            $lines,
            max(0, $method->getStartLine() - 1),
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        self::assertStringContainsString('dueDeliveries', $body);
        self::assertStringContainsString('deliverToHotel', $body);
        self::assertStringNotContainsString('triggerPatrol', $body);
        self::assertStringNotContainsString('reportService->generate', $body);
        self::assertStringNotContainsString('inspectHotel', $body);
    }

    public function testSystemdUnitsAreSerialAndResourceBounded(): void
    {
        $root = dirname(__DIR__);
        $service = (string)file_get_contents($root . '/deploy/systemd/suxios-cloud-automation@.service');
        $retryTimer = (string)file_get_contents($root . '/deploy/systemd/suxios-cloud-retry.timer');

        self::assertStringContainsString('MemoryMax=512M', $service);
        self::assertStringContainsString('CPUQuota=80%', $service);
        self::assertStringContainsString('cloud-automation:run --mode=%i', $service);
        self::assertStringContainsString('OnUnitActiveSec=15min', $retryTimer);
    }
}
