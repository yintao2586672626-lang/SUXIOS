<?php
declare(strict_types=1);

namespace Tests;

use app\service\KnowledgeContentDigestService;
use PHPUnit\Framework\TestCase;

final class KnowledgeContentDigestServiceTest extends TestCase
{
    public function testAssociativeKeyOrderDoesNotChangeCanonicalDigest(): void
    {
        $service = new KnowledgeContentDigestService();

        self::assertSame(
            $service->digest(['b' => 2, 'a' => ['y' => 2, 'x' => 1]]),
            $service->digest(['a' => ['x' => 1, 'y' => 2], 'b' => 2])
        );
    }

    public function testStoredDigestMustBeValidAndMatchEveryContentPoint(): void
    {
        $service = new KnowledgeContentDigestService();
        $content = ['title' => 'approved SOP', 'steps' => ['one', 'two']];
        $digest = $service->digest($content);

        self::assertTrue($service->isValid($digest));
        self::assertTrue($service->matches($digest, $content));
        self::assertFalse($service->matches($digest, [
            'title' => 'approved SOP',
            'steps' => ['one', 'changed'],
        ]));
        self::assertFalse($service->matches('not-a-digest', $content));
    }
}
