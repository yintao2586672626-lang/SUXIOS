<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class HttpErrorStatusContractTest extends TestCase
{
    public function testLiteralErrorEnvelopesDoNotFallBackToHttp200(): void
    {
        $controllerRoot = dirname(__DIR__) . '/app/controller';
        $violations = [];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($controllerRoot, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string)file_get_contents($file->getPathname());
            if (preg_match_all(
                '/return\s+json\(\s*\[\s*[\'\"]code[\'\"]\s*=>\s*([45]\d\d)(?:(?!;).)*\]\s*\);/s',
                $source,
                $matches,
                PREG_OFFSET_CAPTURE
            ) === false) {
                self::fail('Unable to inspect HTTP error envelopes in ' . $file->getPathname());
            }
            foreach ($matches[0] ?? [] as [$match, $offset]) {
                if (preg_match('/\]\s*,\s*[45]\d\d\s*(?:,|\))/s', $match) === 1) {
                    continue;
                }
                $line = substr_count(substr($source, 0, (int)$offset), "\n") + 1;
                $violations[] = str_replace('\\', '/', $file->getPathname()) . ':' . $line . ' ' . trim($match);
            }
        }

        self::assertSame(
            [],
            $violations,
            "Literal 4xx/5xx JSON envelopes must set an HTTP error status:\n" . implode("\n", $violations)
        );
    }
}
