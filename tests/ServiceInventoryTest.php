<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use SplFileInfo;

final class ServiceInventoryTest extends TestCase
{
    public function testEveryServiceClassCanBeAutoloaded(): void
    {
        $classes = $this->serviceClasses();

        self::assertGreaterThanOrEqual(10, count($classes));

        foreach ($classes as $class) {
            self::assertTrue(class_exists($class), "Service class is not autoloadable: {$class}");
        }
    }

    public function testServicePublicMethodsAreDeclaredOnConcreteServices(): void
    {
        foreach ($this->serviceClasses() as $class) {
            $reflection = new ReflectionClass($class);
            self::assertFalse($reflection->isAbstract(), "Service should be concrete: {$class}");

            $publicMethods = array_filter(
                $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
                static fn (ReflectionMethod $method): bool => $method->class === $class && !str_starts_with($method->name, '__')
            );

            self::assertNotEmpty($publicMethods, "Service has no public behavior: {$class}");
        }
    }

    /**
     * @return array<int, class-string>
     */
    private function serviceClasses(): array
    {
        $root = realpath(__DIR__ . '/../app/service');
        self::assertIsString($root);

        $classes = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            $classes[] = 'app\\service\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
        }

        sort($classes);

        return $classes;
    }
}
