<?php
declare(strict_types=1);

namespace Tests;

use app\service\AiReportGenerationTaskService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use think\App;

final class ComposerWorktreeAutoloadTest extends TestCase
{
    public function testApplicationAndFrameworkClassesLoadFromTheCheckoutThatOwnsTheTests(): void
    {
        $expectedRoot = $this->normalizePath(dirname(__DIR__));
        $loadedFile = (new ReflectionClass(AiReportGenerationTaskService::class))->getFileName();
        $frameworkFile = (new ReflectionClass(App::class))->getFileName();

        self::assertIsString($loadedFile);
        self::assertIsString($frameworkFile);
        self::assertStringStartsWith(
            $expectedRoot . DIRECTORY_SEPARATOR,
            $this->normalizePath($loadedFile)
        );
        self::assertStringStartsWith(
            $expectedRoot . DIRECTORY_SEPARATOR,
            $this->normalizePath($frameworkFile),
            'ThinkPHP must be installed inside the active checkout; a shared external vendor path can redirect new App() roots.'
        );
        self::assertSame($expectedRoot, $this->normalizePath(app()->getRootPath()));
    }

    private function normalizePath(string $path): string
    {
        $realPath = realpath($path);
        $normalized = $realPath !== false ? $realPath : $path;
        $normalized = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $normalized), DIRECTORY_SEPARATOR);

        return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
    }
}
