<?php
declare(strict_types=1);

namespace Tests;

use app\controller\TransferDecision;
use app\service\TransferDecisionService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\ReflectionHelper;
use think\App;
use think\Request;

final class TransferDecisionControllerTest extends TestCase
{
    use ReflectionHelper;

    public function testSourceReadKeepsExactHotelDateAndServiceInjection(): void
    {
        $service = $this->createMock(TransferDecisionService::class);
        $payload = ['hotel_id' => 7, 'date' => '2026-10-02', 'status' => 'source_unverified'];
        $service->expects(self::once())->method('buildSourcePayload')
            ->with([7], 7, '2026-10-02')->willReturn($payload);
        $controller = $this->sourceController($service, 7);
        $response = $controller->source();
        self::assertSame(200, $response->getCode());
        self::assertSame($payload, $response->getData()['data']);
    }

    public function testSourceReadRejectsOtherHotelsBeforeCallingTheService(): void
    {
        $service = $this->createMock(TransferDecisionService::class);
        $service->expects(self::never())->method('buildSourcePayload');
        $response = $this->sourceController($service, 8)->source();
        self::assertSame(400, $response->getCode());
        self::assertSame('无权查看该酒店数据', $response->getData()['message']);
    }

    public function testSourceFailureRemains503InsteadOfEmptySuccess(): void
    {
        $service = $this->createMock(TransferDecisionService::class);
        $service->expects(self::once())->method('buildSourcePayload')
            ->willThrowException(new RuntimeException('transfer_source_read_failed:online_daily_data', 503));
        $response = $this->sourceController($service, 7)->source();
        self::assertSame(503, $response->getCode());
        self::assertSame('transfer_source_read_failed:online_daily_data', $response->getData()['data']['status_code']);
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


    private function sourceController(TransferDecisionService $service, int $hotelId): TransferDecision
    {
        $app = new App();
        $request = (new Request())->withGet(['hotel_id' => $hotelId, 'date' => '2026-10-02']);
        $request->user = new class {
            public int $id = 3;
            public function getPermittedHotelIds(): array { return [7]; }
            public function isSuperAdmin(): bool { return false; }
        };
        $app->instance('request', $request);
        return new TransferDecision($app, $service);
    }
}
