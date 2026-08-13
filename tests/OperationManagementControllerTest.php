<?php
declare(strict_types=1);

namespace Tests;

use app\controller\OperationManagement;
use app\model\User;
use app\service\OperationManagementService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ReflectionHelper;
use think\Request;

final class OperationManagementControllerTest extends TestCase
{
    use ReflectionHelper;

    public function testStrategyExecutionIntentRequiresExecutableRuleScenario(): void
    {
        $controller = (new ReflectionClass(OperationManagement::class))->newInstanceWithoutConstructor();

        self::assertFalse($this->invokeNonPublic($controller, 'canCreateStrategyExecutionIntent', [[
            'simulated' => false,
            'status' => 'insufficient_data',
        ]]));
        self::assertFalse($this->invokeNonPublic($controller, 'canCreateStrategyExecutionIntent', [[
            'simulated' => true,
            'status' => 'insufficient_data',
        ]]));
        self::assertTrue($this->invokeNonPublic($controller, 'canCreateStrategyExecutionIntent', [[
            'simulated' => true,
            'status' => 'rule_scenario',
        ]]));
    }

    public function testHotelScopeFiltersWriteOperationsByExecuteCapability(): void
    {
        $reflection = new ReflectionClass(OperationManagement::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $user = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPermittedHotelIds', 'hasHotelPermission'])
            ->getMock();
        $user->method('getPermittedHotelIds')->willReturn([7, 8]);
        $user->method('hasHotelPermission')->willReturnCallback(
            static fn(int $hotelId, string $capability): bool => $capability === 'operation.view'
                || ($capability === 'operation.execute' && $hotelId === 7)
        );

        $baseReflection = $reflection->getParentClass();
        self::assertNotFalse($baseReflection);
        $currentUser = $baseReflection->getProperty('currentUser');
        $currentUser->setAccessible(true);
        $currentUser->setValue($controller, $user);
        $request = $baseReflection->getProperty('request');
        $request->setAccessible(true);
        $request->setValue($controller, new class {
            public function param(string $key, mixed $default = null): mixed
            {
                return $default;
            }
        });

        self::assertSame([[7, 8], null], $this->invokeNonPublic($controller, 'resolveHotelScope', [0, 'operation.view']));
        self::assertSame([[7], 7], $this->invokeNonPublic($controller, 'resolveHotelScope', [0, 'operation.execute']));

        $this->expectException(\RuntimeException::class);
        $this->invokeNonPublic($controller, 'resolveHotelScope', [8, 'operation.execute']);
    }

    public function testControllerDefaultsEveryOperationBusinessDateToShanghaiDay(): void
    {
        $originalTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');

        try {
            $reflection = new ReflectionClass(OperationManagement::class);
            $controller = $reflection->newInstanceWithoutConstructor();
            $service = new class extends OperationManagementService {
                public function __construct()
                {
                }

                public function fullData(array $hotelIds, ?int $hotelId, string $date): array
                {
                    return ['date' => $date];
                }

                public function rootCause(array $hotelIds, ?int $hotelId, string $date, string $problemType): array
                {
                    return ['date' => $date, 'problem_type' => $problemType];
                }

                public function createExecutionIntent(
                    array $hotelIds,
                    ?int $hotelId,
                    array $input,
                    int $createdBy,
                    bool $trustedExpansionSource = false,
                    ?string $trustedIdempotencyKey = null,
                    bool $trustedReservedSource = false
                ): array {
                    return $this->buildExecutionIntentPayload($hotelIds, $hotelId, $input, $createdBy);
                }
            };
            $user = new class extends User {
                public int $id = 9;

                public function __construct()
                {
                }

                public function getPermittedHotelIds(): array
                {
                    return [7];
                }

                public function hasHotelPermission(int $hotelId, string $permission): bool
                {
                    return $hotelId === 7;
                }
            };

            $serviceProperty = $reflection->getProperty('service');
            $serviceProperty->setValue($controller, $service);
            $baseReflection = $reflection->getParentClass();
            self::assertNotFalse($baseReflection);
            $currentUser = $baseReflection->getProperty('currentUser');
            $currentUser->setValue($controller, $user);
            $requestProperty = $baseReflection->getProperty('request');

            $boundaryInstant = new DateTimeImmutable('2026-08-12 16:30:00', new DateTimeZone('UTC'));
            self::assertSame(
                '2026-08-13',
                $this->invokeNonPublic($controller, 'currentBusinessDate', [$boundaryInstant]),
                'The hotel business date must cross midnight in Asia/Shanghai even when PHP defaults to UTC.'
            );

            $expectedToday = (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');

            $requestProperty->setValue($controller, (new Request())->withGet(['hotel_id' => 7]));
            self::assertSame($expectedToday, $this->responseData($controller->fullData())['date']);

            $requestProperty->setValue($controller, (new Request())->withGet([
                'hotel_id' => 7,
                'date' => '2026-09-05',
            ]));
            self::assertSame('2026-09-05', $this->responseData($controller->fullData())['date']);

            $requestProperty->setValue($controller, (new Request())->withGet([
                'hotel_id' => 7,
                'date' => 'not-a-date',
            ]));
            self::assertSame(400, $this->responsePayload($controller->fullData())['code']);

            $requestProperty->setValue($controller, (new Request())->withPost([
                'hotel_id' => 7,
                'problem_type' => 'traffic',
            ]));
            self::assertSame($expectedToday, $this->responseData($controller->rootCause())['date']);

            $requestProperty->setValue($controller, (new Request())->withPost([
                'hotel_id' => 7,
                'problem_type' => 'traffic',
                'date' => '2026-09-05',
            ]));
            self::assertSame('2026-09-05', $this->responseData($controller->rootCause())['date']);

            $requestProperty->setValue($controller, (new Request())->withPost([
                'hotel_id' => 7,
                'problem_type' => 'traffic',
                'date' => 'not-a-date',
            ]));
            self::assertSame(422, $this->responsePayload($controller->rootCause())['code']);

            $requestProperty->setValue($controller, (new Request())->withPost([
                'hotel_id' => 7,
                'object_type' => 'inventory',
                'action_type' => 'inventory_review',
            ]));
            $defaultIntent = $this->responseData($controller->createExecutionIntent());
            self::assertSame($expectedToday, $defaultIntent['date_start']);
            self::assertSame($expectedToday, $defaultIntent['date_end']);

            $strategyIntent = $this->invokeNonPublic($controller, 'buildStrategyExecutionIntentInput', [[
                'target_metric' => 'orders',
            ], [
                'baseline' => [],
                'risk' => ['level' => 'medium'],
            ], 'promotion', 7]);
            self::assertSame($expectedToday, $strategyIntent['date_start']);
            self::assertSame($expectedToday, $strategyIntent['date_end']);

            $requestProperty->setValue($controller, (new Request())->withPost([
                'hotel_id' => 7,
                'object_type' => 'inventory',
                'action_type' => 'inventory_review',
                'date_start' => '2026-09-05',
                'date_end' => '2026-09-06',
            ]));
            $explicitIntent = $this->responseData($controller->createExecutionIntent());
            self::assertSame('2026-09-05', $explicitIntent['date_start']);
            self::assertSame('2026-09-06', $explicitIntent['date_end']);

            $requestProperty->setValue($controller, (new Request())->withPost([
                'hotel_id' => 7,
                'object_type' => 'inventory',
                'action_type' => 'inventory_review',
                'date_start' => 'not-a-date',
            ]));
            $invalid = $this->responsePayload($controller->createExecutionIntent());
            self::assertSame(422, $invalid['code']);

            $requestProperty->setValue($controller, (new Request())->withPost([
                'hotel_id' => 7,
                'object_type' => 'inventory',
                'action_type' => 'inventory_review',
                'date_start' => null,
            ]));
            self::assertSame(422, $this->responsePayload($controller->createExecutionIntent())['code']);

            foreach (['2026-02-30', 'tomorrow'] as $invalidDate) {
                $requestProperty->setValue($controller, (new Request())->withPost([
                    'hotel_id' => 7,
                    'object_type' => 'inventory',
                    'action_type' => 'inventory_review',
                    'date_start' => $invalidDate,
                ]));
                self::assertSame(422, $this->responsePayload($controller->createExecutionIntent())['code']);
            }
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    public function testAlertsReadDoesNotReturnHttpSuccessWhenNothingWasUpdated(): void
    {
        $reflection = new ReflectionClass(OperationManagement::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $service = new class extends OperationManagementService {
            public function __construct()
            {
            }

            public function markAlertsRead(array $ids, array $hotelIds): int
            {
                return 0;
            }
        };
        $user = new class extends User {
            public function __construct()
            {
            }

            public function getPermittedHotelIds(): array
            {
                return [7];
            }

            public function hasHotelPermission(int $hotelId, string $permission): bool
            {
                return $hotelId === 7 && $permission === 'operation.execute';
            }
        };
        $reflection->getProperty('service')->setValue($controller, $service);
        $baseReflection = $reflection->getParentClass();
        self::assertNotFalse($baseReflection);
        $baseReflection->getProperty('currentUser')->setValue($controller, $user);
        $baseReflection->getProperty('request')->setValue(
            $controller,
            (new Request())->withPost(['ids' => [91]])
        );

        $payload = $this->responsePayload($controller->alertsRead());
        self::assertNotSame(200, $payload['code']);
        self::assertStringNotContainsString('updated', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function responseData(\think\Response $response): array
    {
        $payload = $this->responsePayload($response);
        self::assertSame(200, $payload['code'] ?? null);
        self::assertIsArray($payload['data'] ?? null);

        return $payload['data'];
    }

    /** @return array<string, mixed> */
    private function responsePayload(\think\Response $response): array
    {
        $payload = json_decode((string)$response->getContent(), true);
        self::assertIsArray($payload);

        return $payload;
    }
}
