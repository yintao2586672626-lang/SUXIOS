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

    public function testServiceInventoryEntriesDeclareBehaviorOrAnImmutableConstantContract(): void
    {
        foreach ($this->serviceClasses() as $class) {
            $reflection = new ReflectionClass($class);
            self::assertFalse($reflection->isAbstract(), "Service should be concrete: {$class}");

            $publicMethods = array_filter(
                $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
                static fn (ReflectionMethod $method): bool => $method->class === $class && !str_starts_with($method->name, '__')
            );

            self::assertTrue(
                $publicMethods !== [] || $this->isImmutableConstantContract($reflection),
                "Service inventory entry has neither public behavior nor a strict immutable contract: {$class}"
            );
        }
    }

    /**
     * A dependency-free identity contract belongs in the service inventory, but
     * it must not gain state or executable behavior merely to satisfy discovery.
     * The strict shape prevents an arbitrary behaviorless service from passing.
     *
     * @param ReflectionClass<object> $reflection
     */
    private function isImmutableConstantContract(ReflectionClass $reflection): bool
    {
        if (!$reflection->isFinal() || !str_ends_with($reflection->getShortName(), 'Contract')) {
            return false;
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null || !$constructor->isPrivate()) {
            return false;
        }

        $declaredProperties = array_filter(
            $reflection->getProperties(),
            static fn ($property): bool => $property->class === $reflection->getName()
        );
        if ($declaredProperties !== []) {
            return false;
        }

        $declaredBehavior = array_filter(
            $reflection->getMethods(),
            static fn (ReflectionMethod $method): bool => $method->class === $reflection->getName()
                && $method->name !== '__construct'
        );
        if ($declaredBehavior !== []) {
            return false;
        }

        $declaredConstants = array_filter(
            $reflection->getReflectionConstants(),
            static fn ($constant): bool => $constant->getDeclaringClass()->getName()
                === $reflection->getName()
        );

        $publicConstants = array_filter(
            $declaredConstants,
            static fn ($constant): bool => $constant->isPublic()
        );

        return $declaredConstants !== [] && count($publicConstants) === count($declaredConstants);
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
            $source = (string)file_get_contents($file->getPathname());
            if (preg_match('/\btrait\s+[A-Za-z_][A-Za-z0-9_]*/', $source) === 1) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            $classes[] = 'app\\service\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
        }

        sort($classes);

        return $classes;
    }
}
