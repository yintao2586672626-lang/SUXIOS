<?php
declare(strict_types=1);

namespace app\domain\Ota;

use DomainException;

final class OtaActionCatalog
{
    /** @var array<string, list<string>> */
    private const ACTIONS = [
        OtaDomain::CTRIP => [
            'fetchCtrip',
            'fetchCtripTemporaryCookie',
            'ctripCompetitiveOperations',
            'ctripPublicProfiles',
            'otaPublicPageDiagnosis',
            'saveOtaPublicPageEvidence',
            'createOtaPublicPageDiagnosisExecutionIntent',
            'addCtripPublicProfile',
            'syncCtripPublicProfiles',
            'fetchCtripTraffic',
            'ctripLatest',
            'ctripSearchOpportunity',
            'ctripHistory',
            'fetchCtripComments',
            'captureCtripCommentsBrowserData',
            'captureCtripBrowserData',
            'ctripDiagnosisSnapshot',
            'ctripCollectorContract',
            'fetchCtripCookieApiData',
            'validateCtripEndpointEvidence',
            'fetchCtripOverviewData',
            'fetchCtripAds',
            'saveCtripReviewImSession',
            'saveCtripReviewForMatch',
            'saveCtripOrderForMatch',
            'lookupCtripReviewOrderMatch',
            'previewCtripReviewOrdererIdentity',
            'runCtripReviewOrderMatchAutomation',
            'checkCtripReviewOrderMatchClosure',
            'bindCtripReviewOrderMatch',
        ],
        OtaDomain::MEITUAN => [
            'fetchMeituan',
            'commitMeituanRankCandidate',
            'meituanDisplayModel',
            'fetchMeituanTraffic',
            'fetchMeituanOrderFlow',
            'fetchMeituanOrders',
            'fetchMeituanAds',
            'fetchMeituanComments',
            'captureMeituanBrowserData',
            'saveMeituanCapturedData',
            'saveMeituanReviewForMatch',
            'saveMeituanOrderForMatch',
            'lookupMeituanReviewOrderMatch',
            'bindMeituanReviewOrderMatch',
            'unbindMeituanReviewOrderMatch',
            'meituanOrderPhoneState',
        ],
        OtaDomain::CREDENTIAL => [
            'saveCookies',
            'getCookiesList',
            'getCookiesDetail',
            'deleteCookies',
            'batchDeleteCookies',
            'bookmarklet',
            'saveMeituanConfig',
            'getMeituanConfig',
            'saveMeituanConfigItem',
            'getMeituanConfigList',
            'getMeituanConfigDetail',
            'deleteMeituanConfig',
            'generateMeituanBookmarklet',
            'saveMeituanCommentConfig',
            'getMeituanCommentConfigList',
            'saveCtripCommentConfig',
            'getCtripCommentConfigList',
            'saveCtripConfig',
            'getCtripConfigList',
            'getCtripConfigDetail',
            'deleteCtripConfig',
            'generateCtripBookmarklet',
            'autoCaptureCtripCookie',
            'saveCtripConfigByBookmark',
            'receiveCookies',
            'cookieStatus',
        ],
        OtaDomain::PROFILE => [
            'getCtripProfileFields',
            'getCtripProfileModules',
            'syncCtripProfileFields',
            'saveCtripProfileField',
            'saveCtripProfileModule',
            'verifyCtripProfileFieldSample',
            'recheckCtripProfileMismatchedFields',
            'deleteCtripProfileField',
            'deleteCtripProfileModule',
            'ctripProfileStatus',
            'meituanProfileStatus',
            'platformProfileStatus',
            'deletePlatformProfileBinding',
            'triggerPlatformProfileLogin',
            'platformProfileLoginStatus',
        ],
        OtaDomain::SYNC => [
            'collectionResourceCatalog',
            'collectionStatus',
            'dataSourceList',
            'syncDataSource',
            'saveDataSource',
            'deleteDataSource',
            'importDataSourceRows',
            'importBrowserAssistCapture',
            'syncTaskList',
            'syncLogList',
            'manualFetchTaskStatus',
            'autoFetch',
            'autoFetchStatus',
            'autoFetchRecords',
            'batchDeleteAutoFetchRecords',
            'clearAutoFetchRecords',
            'toggleAutoFetch',
            'setFetchSchedule',
            'retryAutoFetch',
            'cronTrigger',
        ],
    ];

    /** @return array<string, list<string>> */
    public static function all(): array
    {
        return self::ACTIONS;
    }

    /** @return list<string> */
    public static function actionsFor(string $domain): array
    {
        if (!array_key_exists($domain, self::ACTIONS)) {
            throw new DomainException("Unknown OTA domain: {$domain}");
        }

        return self::ACTIONS[$domain];
    }

    public static function owns(string $domain, string $action): bool
    {
        return in_array($action, self::actionsFor($domain), true);
    }

    public static function assertOwned(string $domain, string $action): void
    {
        if (!self::owns($domain, $action)) {
            throw new DomainException("OTA action {$action} does not belong to domain {$domain}");
        }
    }

    public static function ownerOf(string $action): ?string
    {
        foreach (self::ACTIONS as $domain => $actions) {
            if (in_array($action, $actions, true)) {
                return $domain;
            }
        }

        return null;
    }

    private function __construct()
    {
    }
}
