<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Hotel;
use PHPUnit\Framework\TestCase;
use think\App;

final class HotelDeletePolicyTest extends TestCase
{
    public function testReferencedHotelRequiresExplicitForceBeforeDelete(): void
    {
        $controller = new HotelDeletePolicyHarness(new App());

        self::assertTrue($controller->exposeShouldBlockHotelDelete([
            ['table' => 'online_daily_data', 'label' => 'online data', 'count' => 1],
        ], false));
    }

    public function testReferencedHotelCanBeDeletedAfterSuperAdminForceConfirmation(): void
    {
        $controller = new HotelDeletePolicyHarness(new App());

        self::assertFalse($controller->exposeShouldBlockHotelDelete([
            ['table' => 'online_daily_data', 'label' => 'online data', 'count' => 1],
        ], true));
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
    public function exposeShouldBlockHotelDelete(array $references, bool $forceDelete): bool
    {
        return $this->shouldBlockHotelDelete($references, $forceDelete);
    }

    public function exposeIsForceDeleteRequested(array $data): bool
    {
        return $this->isForceDeleteRequested($data);
    }
}
