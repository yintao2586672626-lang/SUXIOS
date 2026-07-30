<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class AiDailyReportP0GateContractTest extends TestCase
{
    public function testSynchronousAndQueuedFormalReportGenerationShareExternalP0Gate(): void
    {
        $root = dirname(__DIR__);
        $controller = (string)file_get_contents($root . '/app/controller/AiDailyReport.php');
        $worker = (string)file_get_contents($root . '/app/command/GenerateAiDailyReportOnce.php');

        foreach ([$controller, $worker] as $source) {
            self::assertStringContainsString('P0OtaDownstreamGateService', $source);
            self::assertStringContainsString('->resolveRuntime(', $source);
            self::assertStringContainsString("['ctrip', 'meituan']", $source);
            self::assertStringContainsString("'blocked_by_p0_ota_gate'", $source);
        }
        self::assertStringContainsString("'formal_report_generated' => false", $controller);
        self::assertStringContainsString(
            "'blocked_by_p0_ota_gate'\n                );",
            $worker
        );
    }
}
