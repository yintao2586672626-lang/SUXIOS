<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Hotel;
use PHPUnit\Framework\TestCase;
use think\App;

final class HotelDeletePolicyTest extends TestCase
{
    public function testHotelNameMustMatchBeforeDelete(): void
    {
        $controller = new HotelDeletePolicyHarness(new App());

        self::assertTrue($controller->exposeHotelDeleteConfirmationMatches('敦煌莫月山', ' 敦煌莫月山 '));
        self::assertFalse($controller->exposeHotelDeleteConfirmationMatches('敦煌莫月山', '敦煌莫月'));
    }

    public function testForceFlagAcceptsDeleteRequestPayloadValues(): void
    {
        $controller = new HotelDeletePolicyHarness(new App());

        self::assertTrue($controller->exposeIsForceDeleteRequested(['force' => true]));
        self::assertTrue($controller->exposeIsForceDeleteRequested(['force' => 1]));
        self::assertTrue($controller->exposeIsForceDeleteRequested(['force' => 'true']));
        self::assertFalse($controller->exposeIsForceDeleteRequested([]));
    }
}

final class HotelDeletePolicyHarness extends Hotel
{
    public function exposeHotelDeleteConfirmationMatches(string $hotelName, string $confirmation): bool
    {
        return $this->hotelDeleteConfirmationMatches($hotelName, $confirmation);
    }

    public function exposeIsForceDeleteRequested(array $data): bool
    {
        return $this->isForceDeleteRequested($data);
    }
}
