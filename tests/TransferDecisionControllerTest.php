<?php
declare(strict_types=1);

namespace Tests;

use app\controller\TransferDecision;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\ReflectionHelper;
use think\App;

final class TransferDecisionControllerTest extends TestCase
{
    use ReflectionHelper;

    public function testPricingAiDependencyFailureUsesClientErrorStatus(): void
    {
        $controller = new TransferDecision(new App());

        $status = $this->invokeNonPublic($controller, 'pricingFailureStatusCode', [
            new RuntimeException('AI模型调用失败，未生成AI评估结果：AI治理日志写入失败，已阻断模型结论输出'),
        ]);

        self::assertSame(422, $status);
    }

    public function testSourceFailureCodeOnlyExposesStableReadFailureCodes(): void
    {
        $controller = new TransferDecision(new App());

        self::assertSame(
            'transfer_source_read_failed:online_daily_data',
            $this->invokeNonPublic($controller, 'sourceFailureCode', [
                new RuntimeException('transfer_source_read_failed:online_daily_data', 503),
            ])
        );
        self::assertNull($this->invokeNonPublic($controller, 'sourceFailureCode', [
            new RuntimeException('SQLSTATE[HY000] access denied for password=secret', 503),
        ]));
    }

    public function testUnexpectedTransferFailuresDoNotExposeRawExceptionMessages(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/controller/TransferDecision.php');

        self::assertStringNotContainsString("'资产定价计算失败: ' . \$e->getMessage()", $source);
        self::assertStringNotContainsString("'时机推演计算失败: ' . \$e->getMessage()", $source);
        self::assertStringNotContainsString("'数据看板生成失败: ' . \$e->getMessage()", $source);
        self::assertStringContainsString("'transfer_pricing_ai_unavailable'", $source);
        self::assertStringContainsString("'transfer_timing_failed'", $source);
        self::assertStringContainsString("'transfer_dashboard_failed'", $source);
    }
}
