<?php
declare(strict_types=1);

namespace Tests;

use app\service\AuthTokenState;
use PHPUnit\Framework\TestCase;
use think\App;

final class AuthTokenStateTest extends TestCase
{
    private static ?App $app = null;

    public static function setUpBeforeClass(): void
    {
        self::$app = new App(dirname(__DIR__));
        self::$app->initialize();
    }

    public function testUserTokenIndexStoresOnlyAOneWayDigest(): void
    {
        $token = 'raw-login-token-' . bin2hex(random_bytes(8));
        $digest = AuthTokenState::userTokenDigest($token);

        self::assertSame(64, strlen($digest));
        self::assertNotSame($token, $digest);
        self::assertTrue(AuthTokenState::userTokenIndexMatches($digest, $token));
        self::assertFalse(AuthTokenState::userTokenIndexMatches($digest, $token . '-other'));
        self::assertFalse(AuthTokenState::userTokenIndexMatches($token, $token));
    }

    public function testOldSessionCannotDeleteNewSessionIndex(): void
    {
        $userId = random_int(1000000, 9999999);
        $oldToken = 'old-' . bin2hex(random_bytes(8));
        $newToken = 'new-' . bin2hex(random_bytes(8));
        $key = 'user_token_' . $userId;

        try {
            cache($key, AuthTokenState::userTokenDigest($newToken), 60);

            AuthTokenState::forgetUserTokenIndexIfCurrent($userId, $oldToken);
            self::assertSame(AuthTokenState::userTokenDigest($newToken), cache($key));

            AuthTokenState::forgetUserTokenIndexIfCurrent($userId, $newToken);
            self::assertNull(cache($key));
        } finally {
            cache($key, null);
        }
    }
}
