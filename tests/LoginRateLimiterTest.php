<?php
declare(strict_types=1);

namespace Tests;

use app\service\LoginRateLimiter;
use PHPUnit\Framework\TestCase;

final class LoginRateLimiterTest extends TestCase
{
    public function testIdentityIsLimitedAfterTenFailuresWithoutPersistingPlainIdentity(): void
    {
        $store = [];
        $now = 1_000_000;
        $service = $this->service($store, $now);

        for ($attempt = 0; $attempt < LoginRateLimiter::IDENTITY_LIMIT; $attempt++) {
            $service->recordFailure('203.0.113.7', 'VIP001');
        }

        $result = $service->inspect('203.0.113.7', 'VIP001');
        self::assertFalse($result['allowed']);
        self::assertSame('identity', $result['scope']);
        self::assertSame(LoginRateLimiter::IDENTITY_LIMIT, $result['identity_failures']);
        self::assertGreaterThan(0, $result['retry_after']);
        foreach (array_keys($store) as $key) {
            self::assertStringNotContainsString('203.0.113.7', $key);
            self::assertStringNotContainsString('vip001', strtolower($key));
        }
    }

    public function testIpLimitStopsUsernameRotation(): void
    {
        $store = [];
        $now = 1_000_000;
        $service = $this->service($store, $now);

        for ($attempt = 0; $attempt < LoginRateLimiter::IP_LIMIT; $attempt++) {
            $service->recordFailure('203.0.113.8', 'user-' . $attempt);
        }

        $result = $service->inspect('203.0.113.8', 'fresh-user');
        self::assertFalse($result['allowed']);
        self::assertSame('ip', $result['scope']);
        self::assertSame(LoginRateLimiter::IP_LIMIT, $result['ip_failures']);
    }

    public function testUsernameLimitStopsSourceIpRotation(): void
    {
        $store = [];
        $now = 1_000_000;
        $service = $this->service($store, $now);

        for ($attempt = 0; $attempt < LoginRateLimiter::USERNAME_LIMIT; $attempt++) {
            $service->recordFailure('203.0.113.' . ($attempt + 1), 'VIP-ROTATED');
        }

        $result = $service->inspect('198.51.100.9', 'VIP-ROTATED');
        self::assertFalse($result['allowed']);
        self::assertSame('username', $result['scope']);
        self::assertSame(LoginRateLimiter::USERNAME_LIMIT, $result['username_failures']);
    }

    public function testAttemptReservationAppliesThresholdBeforeAuthenticationWithoutLostCounts(): void
    {
        $store = [];
        $now = 1_000_000;
        $service = $this->service($store, $now);

        for ($attempt = 0; $attempt < LoginRateLimiter::IDENTITY_LIMIT; $attempt++) {
            self::assertTrue($service->consumeAttempt('203.0.113.11', 'VIP011')['allowed']);
        }

        $denied = $service->consumeAttempt('203.0.113.11', 'VIP011');
        self::assertFalse($denied['allowed']);
        self::assertSame('identity', $denied['scope']);
        self::assertSame(LoginRateLimiter::IDENTITY_LIMIT, $denied['identity_failures']);
    }

    public function testSuccessfulIdentityCleanupDoesNotEraseIpProtection(): void
    {
        $store = [];
        $now = 1_000_000;
        $service = $this->service($store, $now);

        for ($attempt = 0; $attempt < LoginRateLimiter::IDENTITY_LIMIT; $attempt++) {
            $service->recordFailure('203.0.113.9', 'VIP009');
        }
        $service->clearIdentityFailures('203.0.113.9', 'VIP009');

        $result = $service->inspect('203.0.113.9', 'VIP009');
        self::assertTrue($result['allowed']);
        self::assertSame(0, $result['identity_failures']);
        self::assertSame(LoginRateLimiter::IDENTITY_LIMIT, $result['ip_failures']);
    }

    public function testSuccessfulReservedAttemptIsReleasedFromAllRelevantCounters(): void
    {
        $store = [];
        $now = 1_000_000;
        $service = $this->service($store, $now);

        self::assertTrue($service->consumeAttempt('203.0.113.12', 'VIP012')['allowed']);
        $service->releaseSuccessfulAttempt('203.0.113.12', 'VIP012');
        $result = $service->inspect('203.0.113.12', 'VIP012');

        self::assertTrue($result['allowed']);
        self::assertSame(0, $result['ip_failures']);
        self::assertSame(0, $result['username_failures']);
        self::assertSame(0, $result['identity_failures']);
    }

    public function testSuccessfulReleaseOnlyRemovesItsOwnConcurrentReservation(): void
    {
        $store = [];
        $now = 1_000_000;
        $service = $this->service($store, $now);

        $first = $service->consumeAttempt('203.0.113.21', 'shared-user');
        $second = $service->consumeAttempt('203.0.113.22', 'shared-user');
        self::assertTrue($first['allowed']);
        self::assertTrue($second['allowed']);

        $service->releaseSuccessfulAttempt(
            '203.0.113.21',
            'shared-user',
            $first['reservation_bucket']
        );
        $remaining = $service->inspect('203.0.113.22', 'shared-user');

        self::assertSame(1, $remaining['ip_failures']);
        self::assertSame(1, $remaining['username_failures']);
        self::assertSame(1, $remaining['identity_failures']);
    }

    public function testReservationIsReleasedFromOriginalWindowAfterClockMoves(): void
    {
        $store = [];
        $now = 1_000_000;
        $service = $this->service($store, $now);

        $reservation = $service->consumeAttempt('203.0.113.23', 'window-user');
        self::assertIsInt($reservation['reservation_bucket']);
        self::assertNotEmpty($store);

        $now += LoginRateLimiter::WINDOW_SECONDS;
        $service->releaseSuccessfulAttempt(
            '203.0.113.23',
            'window-user',
            $reservation['reservation_bucket']
        );

        self::assertSame([], $store);
        self::assertSame(0, $service->inspect('203.0.113.23', 'window-user')['identity_failures']);
    }

    public function testPartialCustomStoreIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LoginRateLimiter(static fn(string $key): ?int => null);
    }

    public function testNewWindowStartsWithFreshCounters(): void
    {
        $store = [];
        $now = 1_000_000;
        $service = $this->service($store, $now);

        for ($attempt = 0; $attempt < LoginRateLimiter::IDENTITY_LIMIT; $attempt++) {
            $service->recordFailure('203.0.113.10', 'VIP010');
        }
        self::assertFalse($service->inspect('203.0.113.10', 'VIP010')['allowed']);

        $now += LoginRateLimiter::WINDOW_SECONDS;
        self::assertTrue($service->inspect('203.0.113.10', 'VIP010')['allowed']);
    }

    /**
     * @param array<string, int> $store
     */
    private function service(array &$store, int &$now): LoginRateLimiter
    {
        return new LoginRateLimiter(
            static function (string $key) use (&$store): ?int {
                return $store[$key] ?? null;
            },
            static function (string $key, int $value, int $ttl) use (&$store): void {
                $store[$key] = $value;
            },
            static function (string $key) use (&$store): void {
                unset($store[$key]);
            },
            static function () use (&$now): int {
                return $now;
            }
        );
    }
}
