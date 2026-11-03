<?php
declare(strict_types=1);

namespace Tests;

/** Fixed synthetic history; never a live input or a business default. */
final class RetiredHistoryFixture
{
    public static function result(string $domain, string $key): array
    {
        $fixtures = json_decode((string)file_get_contents(__DIR__ . '/fixtures/retired-generation-history.json'), true, 512, JSON_THROW_ON_ERROR);
        return $fixtures[$domain][$key];
    }
}
