<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Auth as AuthController;
use app\service\LoginRateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use think\App;
use think\Request;

final class AuthLoginInputGuardTest extends TestCase
{
    /**
     * @param array<string, mixed> $post
     */
    #[DataProvider('invalidPayloadProvider')]
    public function testMalformedLoginPayloadIsRejectedBeforeAuthentication(array $post): void
    {
        $app = new App(dirname(__DIR__));
        $controller = new AuthLoginInputHarness($app, $post);

        $response = $controller->login();
        $payload = json_decode((string)$response->getContent(), true);

        self::assertSame(422, $response->getCode());
        self::assertSame('invalid_login_payload', $payload['data']['reason'] ?? null);
        self::assertStringNotContainsString('TypeError', (string)$response->getContent());
        self::assertSame(0, $controller->persistentAuditWrites());
    }

    public static function invalidPayloadProvider(): array
    {
        return [
            'array username' => [[
                'username' => ['admin'],
                'password' => 'test',
            ]],
            'array password' => [[
                'username' => 'admin',
                'password' => ['test'],
            ]],
            'scalar client info' => [[
                'username' => 'admin',
                'password' => 'test',
                'client_info' => 'not-an-object',
            ]],
            'oversized username' => [[
                'username' => str_repeat('a', 51),
                'password' => 'test',
            ]],
            'oversized password' => [[
                'username' => 'admin',
                'password' => str_repeat('a', 1025),
            ]],
            'nested allowed client info' => [[
                'username' => 'admin',
                'password' => 'test',
                'client_info' => ['browser' => ['nested']],
            ]],
        ];
    }

    public function testMalformedPayloadsShareTheSameBoundedLoginLimiter(): void
    {
        $app = new App(dirname(__DIR__));
        $controller = new AuthLoginInputHarness($app, [
            'username' => ['admin'],
            'password' => 'test',
        ]);

        for ($attempt = 1; $attempt <= LoginRateLimiter::IDENTITY_LIMIT; $attempt++) {
            $response = $controller->login();
            self::assertSame(422, $response->getCode(), 'reserved malformed attempt ' . $attempt);
        }

        $limited = $controller->login();
        $payload = json_decode((string)$limited->getContent(), true);
        self::assertSame(429, $limited->getCode());
        self::assertSame('login_rate_limited', $payload['data']['reason'] ?? null);
        self::assertSame(0, $controller->persistentAuditWrites());
    }

    public function testUnavailableLimiterFailsClosedBeforeAuthentication(): void
    {
        $controller = new AuthLoginInputHarness(new App(dirname(__DIR__)), [
            'username' => 'admin',
            'password' => 'test',
        ], true);

        $response = $controller->login();
        $payload = json_decode((string)$response->getContent(), true);
        self::assertSame(503, $response->getCode());
        self::assertSame('login_rate_limiter_unavailable', $payload['data']['reason'] ?? null);
        self::assertSame(0, $controller->persistentAuditWrites());
    }
}

final class AuthLoginInputHarness extends AuthController
{
    /** @var array<string, mixed> */
    private array $rateLimitStore = [];
    private bool $failLimiter;
    private int $persistentAuditWrites = 0;

    /** @param array<string, mixed> $post */
    public function __construct(App $app, array $post, bool $failLimiter = false)
    {
        parent::__construct($app);
        $this->failLimiter = $failLimiter;
        $this->request = (new Request())
            ->setMethod('POST')
            ->setUrl('/api/auth/login')
            ->setBaseUrl('/api/auth/login')
            ->setPathinfo('api/auth/login')
            ->withPost($post)
            ->withHeader(['Accept' => 'application/json']);
    }

    protected function makeLoginRateLimiter(): LoginRateLimiter
    {
        if ($this->failLimiter) {
            return new LoginRateLimiter(
                static fn(string $key): mixed => throw new \RuntimeException('test_limiter_unavailable'),
                static function (string $key, int $count, int $ttl): void {},
                static function (string $key): void {}
            );
        }
        return new LoginRateLimiter(
            fn(string $key): mixed => $this->rateLimitStore[$key] ?? null,
            function (string $key, int $count, int $ttl): void {
                $this->rateLimitStore[$key] = $count;
            },
            function (string $key): void {
                unset($this->rateLimitStore[$key]);
            },
            static fn(): int => 1_750_000_000
        );
    }

    protected function recordLoginFailure(string $username, string $reason, array $clientInfo = []): void
    {
        $this->persistentAuditWrites++;
    }

    public function persistentAuditWrites(): int
    {
        return $this->persistentAuditWrites;
    }
}
