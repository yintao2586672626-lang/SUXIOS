<?php
declare(strict_types=1);

namespace app\controller\ota;

use app\service\Ota\CtripService;
use think\Response;

final class CtripController extends OtaController
{
    private ?CtripService $ctripService = null;

    public function fetchCtrip(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function fetchCtripTemporaryCookie(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function ctripCompetitiveOperations(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function ctripPublicProfiles(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function otaPublicPageDiagnosis(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function saveOtaPublicPageEvidence(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function createOtaPublicPageDiagnosisExecutionIntent(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function addCtripPublicProfile(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function syncCtripPublicProfiles(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function fetchCtripTraffic(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function ctripLatest(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function ctripSearchOpportunity(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function ctripHistory(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function fetchCtripComments(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function captureCtripCommentsBrowserData(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function captureCtripBrowserData(?array $requestDataOverride = null): Response
    {
        return $this->service()->execute(__FUNCTION__, [$requestDataOverride]);
    }

    public function ctripDiagnosisSnapshot(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function ctripCollectorContract(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function fetchCtripCookieApiData(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function validateCtripEndpointEvidence(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function fetchCtripOverviewData(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function fetchCtripAds(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function saveCtripReviewImSession(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function saveCtripReviewForMatch(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function saveCtripOrderForMatch(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function lookupCtripReviewOrderMatch(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function previewCtripReviewOrdererIdentity(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function runCtripReviewOrderMatchAutomation(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function checkCtripReviewOrderMatchClosure(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    public function bindCtripReviewOrderMatch(): Response
    {
        return $this->service()->execute(__FUNCTION__);
    }

    private function service(): CtripService
    {
        return $this->ctripService ??= $this->app->make(
            CtripService::class,
            [$this->otaActionHandler()],
            true
        );
    }
}
