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
}
