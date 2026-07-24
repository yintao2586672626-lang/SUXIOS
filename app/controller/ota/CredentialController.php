<?php
declare(strict_types=1);

namespace app\controller\ota;

use app\service\Ota\CredentialService;
use think\Response;

final class CredentialController extends OtaController
{
    private ?CredentialService $credentialService = null;

    public function saveCookies(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function getCookiesList(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function getCookiesDetail(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function deleteCookies(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function batchDeleteCookies(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function bookmarklet(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function saveMeituanConfig(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function getMeituanConfig(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function saveMeituanConfigItem(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function getMeituanConfigList(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function getMeituanConfigDetail(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function deleteMeituanConfig(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function generateMeituanBookmarklet(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function saveMeituanCommentConfig(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function getMeituanCommentConfigList(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function saveCtripCommentConfig(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function getCtripCommentConfigList(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function saveCtripConfig(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function getCtripConfigList(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function getCtripConfigDetail(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function deleteCtripConfig(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function generateCtripBookmarklet(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function autoCaptureCtripCookie(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function saveCtripConfigByBookmark(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function receiveCookies(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function cookieStatus(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    private function service(): CredentialService
    {
        return $this->credentialService ??= $this->app->make(
            CredentialService::class,
            [$this->otaActionHandler()],
            true
        );
    }
}
