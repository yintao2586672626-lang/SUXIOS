<?php
declare(strict_types=1);

namespace app\controller\ota;

use app\domain\Ota\OtaDomain;
use think\Response;

final class ProfileController extends OtaController
{
    public function getCtripProfileFields(): Response
    {
        return $this->execute(__FUNCTION__);
    }

    public function getCtripProfileModules(): Response
    {
        return $this->execute(__FUNCTION__);
    }

    public function syncCtripProfileFields(): Response
    {
        return $this->execute(__FUNCTION__);
    }

    public function saveCtripProfileField(): Response
    {
        return $this->execute(__FUNCTION__);
    }

    public function saveCtripProfileModule(): Response
    {
        return $this->execute(__FUNCTION__);
    }

    public function verifyCtripProfileFieldSample(): Response
    {
        return $this->execute(__FUNCTION__);
    }

    public function recheckCtripProfileMismatchedFields(): Response
    {
        return $this->execute(__FUNCTION__);
    }

    public function deleteCtripProfileField(): Response
    {
        return $this->execute(__FUNCTION__);
    }

    public function deleteCtripProfileModule(): Response
    {
        return $this->execute(__FUNCTION__);
    }

    public function ctripProfileStatus(): Response
    {
        return $this->execute(__FUNCTION__);
    }

    public function meituanProfileStatus(): Response
    {
        return $this->execute(__FUNCTION__);
    }

    public function platformProfileStatus(): Response
    {
        return $this->execute(__FUNCTION__);
    }

    public function deletePlatformProfileBinding(): Response
    {
        return $this->execute(__FUNCTION__);
    }

    public function triggerPlatformProfileLogin(string $platform): Response
    {
        return $this->execute(__FUNCTION__, [$platform]);
    }

    public function platformProfileLoginStatus(string $platform): Response
    {
        return $this->execute(__FUNCTION__, [$platform]);
    }

    /** @param list<mixed> $arguments */
    private function execute(string $action, array $arguments = []): Response
    {
        return $this->executeDomainAction(OtaDomain::PROFILE, $action, $arguments);
    }
}
