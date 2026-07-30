<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Base;
use app\controller\OtaStandard;
use app\service\OtaStandardEtlService;
use app\service\RevenueAiOverviewService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Tests\Support\ReflectionHelper;

final class OtaHotelScopeAuthorizationTest extends TestCase
{
    use ReflectionHelper;

    public function testOtaStandardRejectsHotelOutsideOrdinaryUserScope(): void
    {
        $controller = $this->otaStandardController([7, 8]);

        try {
            $this->invokeScopeMethod($controller, 'authorizeHotelFilters', [[
                'system_hotel_id' => 9,
            ]]);
            self::fail('Expected hotel scope rejection.');
        } catch (RuntimeException $e) {
            self::assertSame(403, $e->getCode());
            self::assertSame('system_hotel_id is outside permitted scope', $e->getMessage());
        }
    }

    public function testOtaStandardRequiresExplicitPortfolioForOmittedMultiHotelScope(): void
    {
        $controller = $this->otaStandardController([7, 8]);

        try {
            $this->invokeScopeMethod($controller, 'authorizeHotelFilters', [[]]);
            self::fail('Expected explicit portfolio requirement.');
        } catch (RuntimeException $e) {
            self::assertSame(422, $e->getCode());
            self::assertSame('hotel_scope_required_for_multi_hotel_user', $e->getMessage());
        }
    }

    public function testOtaStandardPortfolioCarriesOnlyPermittedSystemHotels(): void
    {
        $filters = $this->invokeScopeMethod(
            $this->otaStandardController([8, 7, 8]),
            'authorizeHotelFilters',
            [['portfolio' => true]]
        );

        self::assertSame([7, 8], $filters['permitted_hotel_ids']);
        self::assertTrue($filters['portfolio']);
        self::assertArrayNotHasKey('system_hotel_id', $filters);
    }

    public function testSinglePermittedHotelBecomesTheDefaultScopeWithoutPortfolio(): void
    {
        try {
            $otaFilters = $this->invokeScopeMethod(
                $this->otaStandardController([7]),
                'authorizeHotelFilters',
                [[]]
            );
            $revenueScope = $this->invokeScopeMethod(new RevenueAiOverviewService(), 'resolveHotelScope', [[
                'permitted_hotel_ids' => [7],
            ]]);
        } catch (RuntimeException $e) {
            self::fail('A single permitted hotel must not require portfolio mode: ' . $e->getMessage());
        }

        self::assertSame(7, $otaFilters['system_hotel_id']);
        self::assertSame(7, $revenueScope['hotel_id']);
    }

    public function testRevenueAiScopeRejectsUnauthorizedHotelAndAmbiguousMultiHotelRequest(): void
    {
        $service = new RevenueAiOverviewService();

        foreach ([
            [['hotel_id' => 9, 'permitted_hotel_ids' => [7, 8]], 403, 'hotel_id is outside permitted scope'],
            [['permitted_hotel_ids' => [7, 8]], 422, 'hotel_scope_required_for_multi_hotel_user'],
        ] as [$filters, $code, $message]) {
            try {
                $this->invokeScopeMethod($service, 'resolveHotelScope', [$filters]);
                self::fail('Expected Revenue AI hotel scope rejection.');
            } catch (RuntimeException $e) {
                self::assertSame($code, $e->getCode());
                self::assertSame($message, $e->getMessage());
            }
        }
    }

    public function testRevenueAiPortfolioScopeKeepsPermittedHotelAllowlist(): void
    {
        $scope = $this->invokeScopeMethod(new RevenueAiOverviewService(), 'resolveHotelScope', [[
            'portfolio' => true,
            'permitted_hotel_ids' => [8, 7, 8],
        ]]);

        self::assertNull($scope['hotel_id']);
        self::assertSame([7, 8], $scope['permitted_hotel_ids']);
        self::assertTrue($scope['portfolio']);
    }

    public function testEtlPushesPermittedHotelAllowlistIntoSystemHotelIdQuery(): void
    {
        $query = new class {
            /** @var array<int, array{0:string,1:array<int,int>}> */
            public array $whereInCalls = [];

            /** @param array<int, int> $values */
            public function whereIn(string $field, array $values): self
            {
                $this->whereInCalls[] = [$field, $values];
                return $this;
            }
        };

        $this->invokeScopeMethod(new OtaStandardEtlService(), 'applySystemHotelScopeFilter', [
            $query,
            ['permitted_hotel_ids' => [8, '7', 8, 0, 'invalid']],
            ['system_hotel_id' => true],
        ]);

        self::assertSame([['system_hotel_id', [7, 8]]], $query->whereInCalls);
    }

    /** @param array<int, int> $permittedHotelIds */
    private function otaStandardController(array $permittedHotelIds): OtaStandard
    {
        $reflection = new ReflectionClass(OtaStandard::class);
        /** @var OtaStandard $controller */
        $controller = $reflection->newInstanceWithoutConstructor();
        $currentUser = new ReflectionProperty(Base::class, 'currentUser');
        $currentUser->setAccessible(true);
        $currentUser->setValue($controller, new class($permittedHotelIds) {
            /** @param array<int, int> $permittedHotelIds */
            public function __construct(private array $permittedHotelIds)
            {
            }

            public function isSuperAdmin(): bool
            {
                return false;
            }

            /** @return array<int, int> */
            public function getPermittedHotelIds(): array
            {
                return $this->permittedHotelIds;
            }
        });

        return $controller;
    }

    /** @param array<int, mixed> $arguments */
    private function invokeScopeMethod(object $object, string $method, array $arguments): mixed
    {
        try {
            return $this->invokeNonPublic($object, $method, $arguments);
        } catch (\ReflectionException) {
            self::fail('Missing required hotel-scope method: ' . $method);
        }
    }
}
