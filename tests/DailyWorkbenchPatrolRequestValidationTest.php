<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Base;
use app\controller\concern\OperationWorkbenchConcern;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use think\Request;
use think\Response;

final class DailyWorkbenchPatrolRequestValidationTest extends TestCase
{
    public static function invalidRequests(): array
    {
        return [
            'impossible day' => [['target_date' => '2026-02-30']],
            'non leap year' => [['target_date' => '2026-02-29']],
            'invalid format' => [['target_date' => 'bad']],
            'legacy end date alias' => [['end_date' => '2026-02-30']],
        ];
    }

    #[DataProvider('invalidRequests')]
    public function testListRejectsInvalidDateAsInputError(array $query): void
    {
        $controller = new class($query) extends Base {
            use OperationWorkbenchConcern;

            public int $internalErrorCount = 0;
            public array $capabilityChecks = [];

            public function __construct(array $query)
            {
                $this->request = (new Request())->withGet(['hotel_id' => '7'] + $query);
            }

            protected function checkPermission(): void {}

            private function resolveDashboardHotelId($hotelId, bool $required): int
            {
                TestCase::assertSame('7', $hotelId);
                TestCase::assertTrue($required);
                return 7;
            }

            private function requireOperationHotelCapability(int $hotelId, string $capability): void
            {
                $this->capabilityChecks[] = [$hotelId, $capability];
            }

            private function operationWorkbenchInternalError(\Throwable $exception, string $event, string $message): Response
            {
                $this->internalErrorCount++;
                return $this->error($message, 500);
            }
        };

        $response = $controller->dailyWorkbenchPatrols();

        self::assertSame(400, $response->getCode());
        self::assertSame(400, $response->getData()['code']);
        self::assertStringContainsString('target_date', $response->getData()['message']);
        self::assertSame([[7, 'operation.view']], $controller->capabilityChecks);
        self::assertSame(0, $controller->internalErrorCount);
    }
}
