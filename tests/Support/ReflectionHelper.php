<?php
declare(strict_types=1);

namespace Tests\Support;

use ReflectionObject;

trait ReflectionHelper
{
    /**
     * @param array<int, mixed> $arguments
     */
    private function invokeNonPublic(object $object, string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionObject($object);
        while (!$reflection->hasMethod($method) && $reflection->getParentClass()) {
            $reflection = $reflection->getParentClass();
        }

        $methodReflection = $reflection->getMethod($method);
        $methodReflection->setAccessible(true);

        return $methodReflection->invokeArgs($object, $arguments);
    }
}
