<?php
declare(strict_types=1);

namespace Tests;

use app\service\ManualNotificationExecutionActorService;
use PHPUnit\Framework\TestCase;

final class ManualNotificationExecutionActorServiceTest extends TestCase
{
    public function testCrossTenantCreatorIsReplacedByHotelTenantActor(): void
    {
        $users = [
            1 => $this->actor(1, 7, true),
            155 => $this->actor(155, 80, true),
        ];
        $resolver = new ManualNotificationExecutionActorService(
            static fn(int $userId): mixed => $users[$userId] ?? null,
            static fn(int $tenantId, int $hotelId): array => [155],
            static fn(int $hotelId): int => 80
        );

        $result = $resolver->resolve([
            'tenant_id' => 80,
            'hotel_id' => 80,
            'created_by' => 1,
        ]);

        self::assertSame('ready', $result['status']);
        self::assertSame(155, $result['actor_id']);
        self::assertSame('tenant_hotel_authorized', $result['resolution']);
        self::assertSame(1, $result['plan_creator_id']);
        self::assertTrue($result['creator_replaced']);
    }

    public function testSameTenantAuthorizedCreatorRemainsExecutionActor(): void
    {
        $creator = $this->actor(155, 80, true);
        $resolver = new ManualNotificationExecutionActorService(
            static fn(int $userId): mixed => $userId === 155 ? $creator : null,
            static fn(int $tenantId, int $hotelId): array => [],
            static fn(int $hotelId): int => 80
        );

        $result = $resolver->resolve([
            'tenant_id' => 80,
            'hotel_id' => 80,
            'created_by' => 155,
        ]);

        self::assertSame('ready', $result['status']);
        self::assertSame(155, $result['actor_id']);
        self::assertSame('plan_creator', $result['resolution']);
        self::assertFalse($result['creator_replaced']);
    }

    public function testHotelTenantMismatchFailsClosed(): void
    {
        $candidateCalls = 0;
        $resolver = new ManualNotificationExecutionActorService(
            static fn(int $userId): mixed => null,
            static function () use (&$candidateCalls): array {
                $candidateCalls++;
                return [155];
            },
            static fn(int $hotelId): int => 81
        );

        $result = $resolver->resolve([
            'tenant_id' => 80,
            'hotel_id' => 80,
            'created_by' => 1,
        ]);

        self::assertSame('blocked', $result['status']);
        self::assertSame(
            'manual_notification_execution_hotel_tenant_mismatch',
            $result['reason_code']
        );
        self::assertSame(0, $candidateCalls);
    }

    private function actor(int $id, int $tenantId, bool $allowed): object
    {
        return new class($id, $tenantId, $allowed) {
            public int $status = 1;

            public function __construct(
                public int $id,
                public int $tenant_id,
                private readonly bool $allowed
            ) {
            }

            public function hasHotelPermission(int $hotelId, string $permission): bool
            {
                return $this->allowed
                    && $hotelId === 80
                    && $permission === 'can_fetch_online_data';
            }
        };
    }
}
