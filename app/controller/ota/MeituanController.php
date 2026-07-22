<?php
declare(strict_types=1);

namespace app\controller\ota;

use app\service\Ota\MeituanService;
use think\Response;

final class MeituanController extends OtaController
{
    private ?MeituanService $meituanService = null;

    public function fetchMeituan(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function commitMeituanRankCandidate(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function meituanDisplayModel(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function fetchMeituanTraffic(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function fetchMeituanOrderFlow(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function fetchMeituanOrders(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function fetchMeituanAds(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function fetchMeituanComments(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function captureMeituanBrowserData(?array $requestDataOverride = null): Response
    {
        return $this->service()->execute(__FUNCTION__, [$requestDataOverride]);
    }

    public function saveMeituanCapturedData(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function saveMeituanReviewForMatch(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function saveMeituanOrderForMatch(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function lookupMeituanReviewOrderMatch(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function bindMeituanReviewOrderMatch(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function unbindMeituanReviewOrderMatch(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function meituanOrderPhoneState(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    private function service(): MeituanService
    {
        return $this->meituanService ??= $this->app->make(
            MeituanService::class,
            [$this->otaActionHandler()],
            true
        );
    }
}
