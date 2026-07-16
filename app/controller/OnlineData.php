<?php
declare(strict_types=1);

namespace app\controller;

use app\controller\concern\CollectionReliabilityConcern;
use app\controller\concern\CookieEndpointConcern;
use app\controller\concern\CtripAdsConcern;
use app\controller\concern\CtripCaptureDiagnosticsConcern;
use app\controller\concern\CtripCaptureProcessConcern;
use app\controller\concern\CtripCapturedPayloadConcern;
use app\controller\concern\CtripCommentsConcern;
use app\controller\concern\CtripCompetitiveOperationsConcern;
use app\controller\concern\CtripCollectorWorkflowConcern;
use app\controller\concern\CtripDiagnosisSnapshotConcern;
use app\controller\concern\CtripOverviewRowsConcern;
use app\controller\concern\CtripOverviewRequestConcern;
use app\controller\concern\CtripReviewOrderMatchConcern;
use app\controller\concern\CtripSearchOpportunityConcern;
use app\controller\concern\CtripProfileConfigConcern;
use app\controller\concern\MeituanConfigConcern;
use app\controller\concern\MeituanCapturedDataConcern;
use app\controller\concern\MeituanReviewOrderMatchConcern;
use app\controller\concern\MeituanUtilityConcern;
use app\controller\concern\OnlineDataRecordConcern;
use app\controller\concern\OperationWorkbenchConcern;
use app\controller\concern\OtaConfigConcern;
use app\controller\concern\AutoFetchConcern;
use app\controller\concern\BusinessDisplayConcern;
use app\controller\concern\OnlineDataHistoryConcern;
use app\controller\concern\OnlineDataSummaryConcern;
use app\controller\concern\OnlineDataAnalyticsConcern;
use app\controller\concern\OnlineDailyDataPersistenceConcern;
use app\controller\concern\OnlineDataQualityConcern;
use app\controller\concern\OnlineDataRequestConcern;
use app\controller\concern\OnlineDataManualFetchConcern;
use app\controller\concern\OnlineDataSupportConcern;
use app\controller\concern\PlatformDataSourceConcern;
use app\controller\concern\Phase1EmployeeConsoleConcern;
use app\controller\concern\PlatformProfileCaptureConcern;
use app\controller\concern\ReleaseEvidenceConcern;

class OnlineData extends Base
{
    use OnlineDataSupportConcern;
    use CollectionReliabilityConcern;
    use CookieEndpointConcern;
    use CtripAdsConcern;
    use CtripCaptureDiagnosticsConcern;
    use CtripCaptureProcessConcern;
    use CtripCapturedPayloadConcern;
    use CtripCommentsConcern;
    use CtripCompetitiveOperationsConcern;
    use CtripCollectorWorkflowConcern;
    use CtripDiagnosisSnapshotConcern;
    use CtripOverviewRowsConcern;
    use CtripOverviewRequestConcern;
    use CtripReviewOrderMatchConcern;
    use CtripSearchOpportunityConcern;
    use CtripProfileConfigConcern;
    use MeituanConfigConcern;
    use MeituanCapturedDataConcern;
    use MeituanReviewOrderMatchConcern;
    use MeituanUtilityConcern;
    use OnlineDataRecordConcern;
    use OperationWorkbenchConcern;
    use OtaConfigConcern;
    use AutoFetchConcern;
    use BusinessDisplayConcern;
    use OnlineDataHistoryConcern;
    use OnlineDataSummaryConcern;
    use OnlineDataAnalyticsConcern;
    use OnlineDailyDataPersistenceConcern;
    use OnlineDataQualityConcern;
    use OnlineDataRequestConcern;
    use OnlineDataManualFetchConcern;
    use PlatformDataSourceConcern;
    use Phase1EmployeeConsoleConcern;
    use PlatformProfileCaptureConcern;
    use ReleaseEvidenceConcern;

    private const CTRIP_PROFILE_FIELDS_CONFIG_KEY = 'ctrip_profile_capture_fields';
    private const CTRIP_PROFILE_FIELDS_CONFIG_VERSION = 31;
    private const CTRIP_PROFILE_MODULES_CONFIG_KEY = 'ctrip_profile_capture_modules';
    private const CTRIP_PROFILE_MODULES_CONFIG_VERSION = 3;
    private const MEITUAN_COMPETITOR_BATCH_WINDOW_SECONDS = 120;
    private const CTRIP_BUSINESS_REPORT_PAGE_URL = 'https://ebooking.ctrip.com/datacenter/inland/businessreport/outline?microJump=true';
    private const CTRIP_FLOW_TRANSFORM_PAGE_URL = 'https://ebooking.ctrip.com/datacenter/inland/businessreport/flowdata?microJump=true';
    private const CTRIP_FLOW_TRANSFORM_REQUEST_URL = 'https://ebooking.ctrip.com/datacenter/api/inland/marketanalysis/flowanalysis/queryFlowTransforNewV1?hostType=Ebooking';
    private const CTRIP_PSI_PAGE_URL = 'https://ebooking.ctrip.com/psi/index?microJump=true';
    private const CTRIP_PSI_REQUEST_URL = 'https://ebooking.ctrip.com/psi/api/getHotelPsiV2';
    private const CTRIP_ADS_PAGE_URL = 'https://ebooking.ctrip.com/toolcenter/cpc/pyramid?microJump=true';
    private const CTRIP_ADS_REQUEST_URL = 'https://ebooking.ctrip.com/toolcenter/api/cpc/queryCampaignReportList?hostType=HE';
    private const AUTO_FETCH_LIGHT_READ_CACHE_TTL_SECONDS = 5;

    private array $autoFetchLightReadCache = [];

}
