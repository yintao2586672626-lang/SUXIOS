<?php
declare(strict_types=1);

use app\controller\OperatingTarget;
use PHPUnit\Framework\TestCase;

final class PmsConfigurationErrorMessageTest extends TestCase
{
    public function testBothConfigurationEditorsExplainHowToResolveSourceConflict(): void
    {
        $controller = (new ReflectionClass(OperatingTarget::class))->newInstanceWithoutConstructor();
        foreach (['dingdandaoIntegrationInputError', 'meituanCloudIntegrationInputError'] as $name) {
            $method = new ReflectionMethod(OperatingTarget::class, $name);
            self::assertSame('另一套 PMS 已启用，请到门店管理切换主 PMS', $method->invoke($controller, 'hotel_pms_other_provider_enabled'));
        }
    }
}
