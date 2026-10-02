<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class AiDailyReportP0GateContractTest extends TestCase
{
    public function testGenerationDateUsesShanghaiBusinessYesterdayBeforeTheGate(): void
    {
        $shanghaiYesterday = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))
            ->modify('-1 day')->format('Y-m-d');
        $alternateZone = 'Pacific/Honolulu';
        if ((new \DateTimeImmutable('now', new \DateTimeZone($alternateZone)))
            ->modify('-1 day')->format('Y-m-d') === $shanghaiYesterday) {
            $alternateZone = 'Pacific/Kiritimati';
        }
        $originalZone = date_default_timezone_get();
        date_default_timezone_set($alternateZone);
        try {
            $dateFromInput = new \ReflectionMethod(\app\controller\AiDailyReport::class, 'reportDateFromInput');
            self::assertSame($shanghaiYesterday, $dateFromInput->invoke(null, []));
            self::assertSame('2026-02-28', $dateFromInput->invoke(null, ['report_date' => '2026-02-28']));
        } finally {
            date_default_timezone_set($originalZone);
        }
    }

    public function testGenerationRejectsNonexistentExplicitDateBeforeTheGate(): void
    {
        $dateFromInput = new \ReflectionMethod(\app\controller\AiDailyReport::class, 'reportDateFromInput');
        $this->expectException(\InvalidArgumentException::class);
        $dateFromInput->invoke(null, ['report_date' => '2026-02-30']);
    }

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
