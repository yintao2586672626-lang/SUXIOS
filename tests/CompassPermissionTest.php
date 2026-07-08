<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Base;
use app\controller\admin\Compass;
use app\model\SystemConfig;
use app\model\User;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

final class CompassPermissionTest extends TestCase
{
    public function testSuperAdminUsesGlobalCompassLayoutKey(): void
    {
        $controller = $this->controllerWithUser($this->user(1, true));

        self::assertSame('compass_layout', $this->invokeLayoutConfigKey($controller));
    }

    public function testHotelBoundUserUsesOwnCompassLayoutKey(): void
    {
        $controller = $this->controllerWithUser($this->user(42, false));

        self::assertSame('compass_layout_user_42', $this->invokeLayoutConfigKey($controller));
    }

    public function testDefaultCompassLayoutKeepsFrontendPanelsAllowed(): void
    {
        $controller = $this->controllerWithUser($this->user(1, true));
        $layout = $this->invokeDefaultLayout($controller);

        self::assertSame(['weather', 'todo', 'metrics', 'alerts', 'holiday'], $layout['order']);
        self::assertSame([], $layout['hidden']);
    }

    public function testCompassDataReturnsFrontendPanelContractWithoutFacts(): void
    {
        $controller = $this->controllerWithUser($this->user(1, true));
        $originalCache = $this->systemConfigValueCache();

        try {
            $this->seedSystemConfigValueCache([
                'compass_layout' => ['found' => false, 'value' => null],
            ]);

            $payload = $this->invokeBuildCompassData($controller);
        } finally {
            $this->seedSystemConfigValueCache($originalCache);
        }

        self::assertIsArray($payload['weather']);
        self::assertSame([], $payload['todos']);
        self::assertSame([], $payload['alerts']);
        self::assertSame([], $payload['holidays']);
        self::assertSame('not_loaded', $payload['metrics']['data_status']);
        self::assertSame('compass_contract_only_no_metric_facts', $payload['metrics']['source_policy']);
        self::assertSame([
            'todos' => 'not_loaded',
            'metrics' => 'not_loaded',
            'alerts' => 'not_loaded',
            'holidays' => 'not_loaded',
            'source_policy' => 'compass_contract_only_no_operating_facts',
        ], $payload['contract_status']);
    }

    private function controllerWithUser(User $user): Compass
    {
        $reflection = new ReflectionClass(Compass::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        $property = new ReflectionProperty(Base::class, 'currentUser');
        $property->setAccessible(true);
        $property->setValue($controller, $user);

        return $controller;
    }

    private function invokeLayoutConfigKey(Compass $controller): string
    {
        $reflection = new ReflectionClass(Compass::class);
        $method = $reflection->getMethod('layoutConfigKey');
        $method->setAccessible(true);

        return (string)$method->invoke($controller);
    }

    /**
     * @return array<string, mixed>
     */
    private function invokeDefaultLayout(Compass $controller): array
    {
        $reflection = new ReflectionClass(Compass::class);
        $method = $reflection->getMethod('getDefaultLayout');
        $method->setAccessible(true);

        return $method->invoke($controller);
    }

    /**
     * @return array<string, mixed>
     */
    private function invokeBuildCompassData(Compass $controller): array
    {
        $reflection = new ReflectionClass(Compass::class);
        $method = $reflection->getMethod('buildCompassData');
        $method->setAccessible(true);

        return $method->invoke($controller, 0);
    }

    /**
     * @param array<string, array{found: bool, value: mixed}> $cache
     */
    private function seedSystemConfigValueCache(array $cache): void
    {
        $property = new ReflectionProperty(SystemConfig::class, 'valueCache');
        $property->setAccessible(true);
        $property->setValue(null, $cache);
    }

    /**
     * @return array<string, array{found: bool, value: mixed}>
     */
    private function systemConfigValueCache(): array
    {
        $property = new ReflectionProperty(SystemConfig::class, 'valueCache');
        $property->setAccessible(true);

        return $property->getValue();
    }

    private function user(int $id, bool $superAdmin): User
    {
        $user = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isSuperAdmin', '__get', '__isset'])
            ->getMock();

        $user->method('isSuperAdmin')->willReturn($superAdmin);
        $user->method('__isset')->willReturnCallback(
            static fn(string $key): bool => $key === 'id'
        );
        $user->method('__get')->willReturnCallback(
            static fn(string $key) => $key === 'id' ? $id : null
        );

        return $user;
    }
}
