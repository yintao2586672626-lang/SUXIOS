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

    public function testTransferControllerUsesStrictDatesAndShanghaiBusinessDefault(): void
    {
        $controller = new TransferDecision(new App());
        foreach (['2026-02-30', 'tomorrow', '', ' 2026-08-13 '] as $invalid) {
            try {
                $this->invokeNonPublic($controller, 'normalizeDate', [$invalid]);
                self::fail('Invalid controller transfer date must fail: ' . json_encode($invalid));
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        self::assertSame('2026-08-13', $this->invokeNonPublic($controller, 'normalizeDate', ['2026-08-13']));
        self::assertSame(
            '2026-08-13',
            $this->invokeNonPublic($controller, 'currentBusinessDate', [
                new \DateTimeImmutable('2026-08-12 16:30:00', new \DateTimeZone('UTC')),
            ])
        );

        $source = (string)file_get_contents(dirname(__DIR__) . '/app/controller/TransferDecision.php');
        self::assertStringContainsString("param('date', \$this->currentBusinessDate())", $source);
    }

    public function testTransferControllerRejectsAmbiguousSnapshotAliasesAndCrossHotelBinding(): void
    {
        $controller = new TransferDecision(new App());

        foreach ([
            [
                'method' => 'payloadSnapshot',
                'arguments' => [[
                    'snapshot' => ['hotel_id' => 7],
                    'data_snapshot' => ['hotel_id' => 8],
                ]],
            ],
            [
                'method' => 'payloadSnapshot',
                'arguments' => [['snapshot' => 'not-an-array']],
            ],
            [
                'method' => 'recordHotelId',
                'arguments' => [
                    ['hotel_id' => 8],
                    ['hotel_id' => 7],
                    [7, 8],
                    8,
                ],
            ],
        ] as $case) {
            try {
                $this->invokeNonPublic($controller, $case['method'], $case['arguments']);
                self::fail($case['method'] . ' must reject ambiguous or cross-hotel scope.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('scope mismatch', $exception->getMessage());
            }
        }

        self::assertSame(
            ['hotel_id' => 7],
            $this->invokeNonPublic($controller, 'payloadSnapshot', [[
                'snapshot' => ['hotel_id' => 7],
                'data_snapshot' => ['hotel_id' => 7],
            ]])
        );
    }

    public function testExecutionIntentReauthorizesTheTransferSourceInsideTheWriteTransaction(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/controller/TransferDecision.php');

        self::assertMatchesRegularExpression(
            '/Db::transaction\(function \(\)[\s\S]*?lockExecutionTrackingSource\([\s\S]*?buildExecutionIntentInput\([\s\S]*?createExecutionIntent\([\s\S]*?attachExecutionTracking\(/',
            $source
        );
    }
}
