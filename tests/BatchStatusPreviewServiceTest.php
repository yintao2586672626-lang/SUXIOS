<?php
declare(strict_types=1);

namespace Tests;

use app\service\BatchStatusPreviewService;
use PHPUnit\Framework\TestCase;

final class BatchStatusPreviewServiceTest extends TestCase
{
    public function testPreviewIsBoundToActorScopeIdsAndStatusAndCanBeConsumedOnlyOnce(): void
    {
        $store = [];
        $service = new BatchStatusPreviewService(
            static function (string $key) use (&$store): mixed {
                return $store[$key] ?? null;
            },
            static function (string $key, array $value, int $ttl) use (&$store): bool {
                $store[$key] = $value;
                return $ttl === 300;
            },
            static function (string $key) use (&$store): bool {
                unset($store[$key]);
                return true;
            },
            static fn(): int => 1_000,
            static fn(): string => str_repeat('a', 32),
        );

        $preview = $service->issue('hotel_batch_status', 9, [3, 1, 3], 0);

        self::assertSame(str_repeat('a', 32), $preview['preview_id']);
        self::assertSame(300, $preview['expires_in']);
        self::assertFalse($service->consume($preview['preview_id'], 'hotel_batch_status', 10, [1, 3], 0));

        $preview = $service->issue('hotel_batch_status', 9, [3, 1], 0);
        self::assertFalse($service->consume($preview['preview_id'], 'hotel_batch_status', 9, [1, 3], 1));

        $preview = $service->issue('hotel_batch_status', 9, [3, 1], 0);
        self::assertTrue($service->consume($preview['preview_id'], 'hotel_batch_status', 9, [1, 3], 0));
        self::assertFalse($service->consume($preview['preview_id'], 'hotel_batch_status', 9, [1, 3], 0));
    }

    public function testExpiredPreviewFailsClosed(): void
    {
        $store = [];
        $now = 1_000;
        $service = new BatchStatusPreviewService(
            static function (string $key) use (&$store): mixed {
                return $store[$key] ?? null;
            },
            static function (string $key, array $value) use (&$store): bool {
                $store[$key] = $value;
                return true;
            },
            static function (string $key) use (&$store): bool {
                unset($store[$key]);
                return true;
            },
            static function () use (&$now): int {
                return $now;
            },
            static fn(): string => str_repeat('b', 32),
        );

        $preview = $service->issue('user_batch_status', 5, [7], 1);
        $now = 1_301;

        self::assertFalse($service->consume($preview['preview_id'], 'user_batch_status', 5, [7], 1));
    }
}
