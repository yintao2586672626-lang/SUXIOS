<?php
declare(strict_types=1);

namespace app\service;

final class AuthTokenState
{
    public static function userTokenDigest(string $token): string
    {
        return hash('sha256', trim($token));
    }

    public static function userTokenIndexMatches(mixed $storedDigest, string $token): bool
    {
        if (!is_string($storedDigest) || strlen($storedDigest) !== 64) {
            return false;
        }

        return hash_equals(strtolower($storedDigest), self::userTokenDigest($token));
    }

    public static function forgetUserTokenIndexIfCurrent(int $userId, string $token): void
    {
        if ($userId <= 0 || trim($token) === '') {
            return;
        }

        $key = 'user_token_' . $userId;
        if (self::userTokenIndexMatches(cache($key), $token)) {
            cache($key, null);
        }
    }
}
