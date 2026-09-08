<?php
declare(strict_types=1);
namespace Tests\Support;

use app\controller\Simulation;
use think\Request;
use think\Response;

/** Pre-authorized synthetic actor; does not emulate or claim real authentication. */
final class QuantOperatingControllerFixture extends Simulation
{
    public bool $permit = true;

    public function input(array $payload): self
    {
        $this->request = (new Request())->withPost($payload);
        $this->currentUser = new class {
            public int $id = 91;
            public function getPermittedHotelIds(): array { return [901]; }
            public function isSuperAdmin(): bool { return false; }
            public function hasHotelPermission(int $hotelId, string $permission): bool { return $hotelId === 901; }
        };
        return $this;
    }

    protected function hotelCapabilityDeniedResponse(int $hotelId, string $capability, string $message = 'hotel capability is required'): ?Response
    {
        return $this->permit && $hotelId === 901 ? null : $this->error('synthetic capability denied', 403);
    }
}
