<?php
declare(strict_types=1);

namespace Tests;

use app\service\LoginRateLimiter;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Db;

final class LoginRateLimiterDatabaseTest extends TestCase
{
    private string $ip;
    private string $username;

    public static function setUpBeforeClass(): void
    {
        (new App(dirname(__DIR__)))->initialize();
    }

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(8));
        $this->ip = '198.18.' . random_int(1, 250) . '.' . random_int(1, 250);
        $this->username = 'db-limit-' . $suffix;
    }

    protected function tearDown(): void
    {
        $ipHash = hash('sha256', $this->ip);
        $usernameHash = hash('sha256', mb_strtolower($this->username));
        $identityHash = hash('sha256', $this->ip . "\0" . mb_strtolower($this->username));
        Db::name('login_rate_limit_counters')
            ->whereIn('subject_hash', [$ipHash, $usernameHash, $identityHash])
            ->delete();
    }

    public function testIndependentServiceInstancesShareAndReleaseAtomicCounters(): void
    {
        $firstService = new LoginRateLimiter();
        $secondService = new LoginRateLimiter();

        $first = $firstService->consumeAttempt($this->ip, $this->username);
        self::assertTrue($first['allowed']);
        self::assertIsInt($first['reservation_bucket']);
        self::assertSame(1, $secondService->inspect($this->ip, $this->username)['identity_failures']);

        $second = $secondService->consumeAttempt($this->ip, $this->username);
        self::assertTrue($second['allowed']);
        self::assertSame(2, $firstService->inspect($this->ip, $this->username)['identity_failures']);

        $firstService->releaseSuccessfulAttempt($this->ip, $this->username, $first['reservation_bucket']);
        self::assertSame(1, $secondService->inspect($this->ip, $this->username)['identity_failures']);

        $secondService->releaseSuccessfulAttempt($this->ip, $this->username, $second['reservation_bucket']);
        $released = $firstService->inspect($this->ip, $this->username);
        self::assertSame(0, $released['ip_failures']);
        self::assertSame(0, $released['username_failures']);
        self::assertSame(0, $released['identity_failures']);
    }
}
