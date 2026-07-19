<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Auth as AuthController;
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
}

final class AuthLoginInputHarness extends AuthController
{
    /** @param array<string, mixed> $post */
    public function __construct(App $app, array $post)
    {
        parent::__construct($app);
        $this->request = (new Request())
            ->setMethod('POST')
            ->setUrl('/api/auth/login')
            ->setBaseUrl('/api/auth/login')
            ->setPathinfo('api/auth/login')
            ->withPost($post)
            ->withHeader(['Accept' => 'application/json']);
    }
}
