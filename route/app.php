<?php
declare(strict_types=1);

// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
use think\facade\Route;

if (!function_exists('suxi_root_index_response')) {
    function suxi_root_index_response(): \think\Response
    {
        $indexFile = public_path() . 'index.html';
        if (!is_file($indexFile)) {
            return response('index.html missing', 500);
        }

        $mtime = (int)filemtime($indexFile);
        $size = (int)filesize($indexFile);
        $etag = '"' . md5($indexFile . '|' . $mtime . '|' . $size . '|root-index-gzip-v1') . '"';
        $lastModified = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';
        $headers = [
            'Content-Type' => 'text/html; charset=utf-8',
            'ETag' => $etag,
            'Last-Modified' => $lastModified,
            'Vary' => 'Accept-Encoding',
            'Cache-Control' => 'public, max-age=60, s-maxage=60, stale-while-revalidate=30',
            'CDN-Cache-Control' => 'public, max-age=60, stale-while-revalidate=30',
            'Cloudflare-CDN-Cache-Control' => 'public, max-age=60, stale-while-revalidate=30',
        ];

        $request = request();
        $requestHeaders = function_exists('getallheaders') ? (array)getallheaders() : [];
        $ifNoneMatch = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
        if ($ifNoneMatch === '') {
            $ifNoneMatch = trim((string)$request->header('If-None-Match', ''));
        }
        if ($ifNoneMatch === '') {
            $ifNoneMatch = trim((string)$request->header('if-none-match', ''));
        }
        if ($ifNoneMatch === '') {
            $ifNoneMatch = trim((string)($requestHeaders['If-None-Match'] ?? $requestHeaders['if-none-match'] ?? ''));
        }

        $ifModifiedSince = trim((string)($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));
        if ($ifModifiedSince === '') {
            $ifModifiedSince = trim((string)$request->header('If-Modified-Since', ''));
        }
        if ($ifModifiedSince === '') {
            $ifModifiedSince = trim((string)$request->header('if-modified-since', ''));
        }
        if ($ifModifiedSince === '') {
            $ifModifiedSince = trim((string)($requestHeaders['If-Modified-Since'] ?? $requestHeaders['if-modified-since'] ?? ''));
        }
        $etagValue = trim($etag, '"');
        $ifNoneMatchValues = array_map(
            static fn(string $value): string => trim($value, " \t\n\r\0\x0B\""),
            explode(',', $ifNoneMatch)
        );
        $etagMatches = in_array('*', $ifNoneMatchValues, true)
            || in_array($etagValue, $ifNoneMatchValues, true);
        $notModified = $ifNoneMatch !== ''
            ? $etagMatches
            : ($ifModifiedSince !== '' && strtotime($ifModifiedSince) >= $mtime);
        if ($notModified) {
            return response('', 304, $headers);
        }

        $acceptEncoding = strtolower((string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''));
        if ($size > 1024 && function_exists('gzencode') && str_contains($acceptEncoding, 'gzip')) {
            $gzipRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'static-gzip';
            $gzipFile = $gzipRoot . DIRECTORY_SEPARATOR . md5($indexFile) . '-' . $mtime . '-' . $size . '.gz';
            if (!is_file($gzipFile)) {
                $content = (string)file_get_contents($indexFile);
                $encoded = gzencode($content, 1);
                if ($encoded !== false && (is_dir($gzipRoot) || mkdir($gzipRoot, 0775, true)) && is_writable($gzipRoot)) {
                    file_put_contents($gzipFile, $encoded, LOCK_EX);
                }
            }

            if (is_file($gzipFile)) {
                $headers['Content-Encoding'] = 'gzip';
                $headers['Content-Length'] = (string)filesize($gzipFile);
                return response((string)file_get_contents($gzipFile), 200, $headers);
            }
        }

        $content = (string)file_get_contents($indexFile);
        $headers['Content-Length'] = (string)strlen($content);

        return response($content, 200, $headers);
    }
}

// 根路径 - 返回前端页面
Route::get('/', function () {
    return suxi_root_index_response();
});

// CORS 预检请求
Route::options('api/:any', function() {
    return response('', 204);
})->pattern(['any' => '.*']);

// ==================== Auth routes ====================
// Public auth endpoints.
Route::post('api/auth/login', 'Auth/login');
Route::get('api/auth/login-support', 'Auth/loginSupport');
// Self-registration is disabled by Auth/register and kept routed for explicit 403.
Route::post('api/auth/register', 'Auth/register');

// Protected auth endpoints.
Route::group('api/auth', function () {
    Route::post('logout', 'Auth/logout');
    Route::get('info', 'Auth/info');
    Route::post('changePassword', 'Auth/changePassword');
})->middleware(\app\middleware\Auth::class);

// ==================== 酒店管理路由 ====================
Route::get('api/hotels/', 'Hotel/index')->middleware(\app\middleware\Auth::class);
Route::post('api/hotels/', 'Hotel/create')->middleware(\app\middleware\Auth::class);
Route::group('api/hotels', function () {
    Route::get('/', 'Hotel/index');
    Route::get('/all', 'Hotel/all');
    Route::post('/batch-status', 'Hotel/batchStatus');
    Route::get('/merge-preview', 'Hotel/mergePreview');
    Route::post('/merge-execute', 'Hotel/mergeExecute');
    Route::get('/:id', 'Hotel/read');
    Route::post('/', 'Hotel/create');
    Route::put('/:id', 'Hotel/update');
    Route::delete('/:id', 'Hotel/delete');
})->middleware(\app\middleware\Auth::class);

// ==================== 用户管理路由 ====================
Route::group('api/users', function () {
    Route::get('/', 'User/index');
    Route::get('/roles', 'User/roles');
    Route::post('/batch-status', 'User/batchStatus');
    Route::post('/hotel-assignments', 'User/batchHotelAssignments');
    Route::get('/:id', 'User/read');
    Route::post('/', 'User/create');
    Route::put('/:id', 'User/update');
    Route::delete('/:id', 'User/delete');
})->middleware(\app\middleware\Auth::class);

// ==================== 角色管理路由 ====================
Route::group('api/roles', function () {
    Route::get('/permissions', 'RoleController/permissions');
    Route::get('/', 'RoleController/index');
    Route::get('/:id', 'RoleController/read');
    Route::post('/', 'RoleController/create');
    Route::put('/:id', 'RoleController/update');
    Route::delete('/:id', 'RoleController/delete');
})->middleware(\app\middleware\Auth::class);

// ==================== 门店字段映射模板路由 ====================
Route::group('api/hotel-field-templates', function () {
    Route::get('/', 'HotelFieldTemplate/index');
    Route::get('/:id', 'HotelFieldTemplate/read');
    Route::get('/:id/items', 'HotelFieldTemplate/items');
    Route::post('/', 'HotelFieldTemplate/create');
    Route::put('/:id', 'HotelFieldTemplate/update');
    Route::delete('/:id', 'HotelFieldTemplate/delete');
})->middleware(\app\middleware\Auth::class);

// ==================== 报表路由 ====================
Route::group('api/daily-reports', function () {
    Route::get('/config', 'DailyReport/config');
    Route::get('/view-mapping', 'DailyReport/getViewMapping');
    Route::post('/view-mapping', 'DailyReport/saveViewMapping');
    Route::post('/parse-import', 'DailyReport/parseImport');
    Route::get('/export', 'DailyReport/export');
    Route::get('/:id/detail', 'DailyReport/detail');
    Route::get('/:id', 'DailyReport/read');
    Route::put('/:id', 'DailyReport/update');
    Route::delete('/:id', 'DailyReport/delete');
    Route::get('/', 'DailyReport/index');
    Route::post('/', 'DailyReport/create');
})->middleware(\app\middleware\Auth::class);

Route::group('api/ai-daily-reports', function () {
    Route::get('/latest', 'AiDailyReport/latest');
    Route::post('/generate', 'AiDailyReport/generate');
    Route::get('/tasks/:taskId', 'AiDailyReport/generationTask');
    Route::post('/:id/send-wecom', 'admin.CompetitorWechatRobotController/apiSendAiDailyReport');
    Route::post('/:id/human-judgments', 'AiDailyReport/recordHumanJudgment');
    Route::post('/:id/actions/:actionIndex/execution-intent', 'AiDailyReport/createExecutionIntent');
    Route::get('/:id', 'AiDailyReport/read');
    Route::get('/', 'AiDailyReport/index');
})->middleware(\app\middleware\Auth::class);

Route::group('api/monthly-tasks', function () {
    Route::get('/config', 'MonthlyTask/config');
    Route::get('/:id', 'MonthlyTask/read');
    Route::put('/:id', 'MonthlyTask/update');
    Route::delete('/:id', 'MonthlyTask/delete');
    Route::get('/', 'MonthlyTask/index');
    Route::post('/', 'MonthlyTask/create');
})->middleware(\app\middleware\Auth::class);

Route::group('api/report-configs', function () {
    Route::get('/all', 'ReportConfig/all');
    Route::get('/:id', 'ReportConfig/read');
    Route::put('/:id', 'ReportConfig/update');
    Route::delete('/:id', 'ReportConfig/delete');
    Route::get('/', 'ReportConfig/index');
    Route::post('/', 'ReportConfig/create');
})->middleware(\app\middleware\Auth::class);

// ==================== 系统配置路由 ====================
Route::group('api/system-config', function () {
    Route::get('/', 'SystemConfigController/index');
    Route::put('/', 'SystemConfigController/update');
    Route::get('/groups', 'SystemConfigController/groups');
    Route::get('/export', 'SystemConfigController/export');
    Route::post('/import', 'SystemConfigController/import');
    Route::post('/reset', 'SystemConfigController/reset');
})->middleware(\app\middleware\Auth::class);

// ==================== 线上数据获取路由 ====================
Route::group('api/online-data', function () {
    Route::post('/fetch-ctrip', 'ota.CtripController/fetchCtrip');
    Route::post('/fetch-ctrip-temporary-cookie', 'ota.CtripController/fetchCtripTemporaryCookie');
    Route::post('/fetch-meituan', 'ota.MeituanController/fetchMeituan');
    Route::post('/meituan/rank-candidates/commit', 'ota.MeituanController/commitMeituanRankCandidate');
    Route::post('/meituan/display-model', 'ota.MeituanController/meituanDisplayModel');
    Route::get('/competitor-summary', 'OnlineData/competitorSummary');
    Route::get('/ctrip/competitive-operations', 'ota.CtripController/ctripCompetitiveOperations');
    Route::get('/ctrip/public-profiles', 'ota.CtripController/ctripPublicProfiles');
    Route::get('/public-page-diagnosis', 'ota.CtripController/otaPublicPageDiagnosis');
    Route::post('/public-page-evidence', 'ota.CtripController/saveOtaPublicPageEvidence');
    Route::post('/public-page-diagnosis/execution-intent', 'ota.CtripController/createOtaPublicPageDiagnosisExecutionIntent');
    Route::post('/ctrip/public-profiles/add', 'ota.CtripController/addCtripPublicProfile');
    Route::post('/ctrip/public-profiles/sync', 'ota.CtripController/syncCtripPublicProfiles');
    Route::post('/fetch-ctrip-traffic', 'ota.CtripController/fetchCtripTraffic');
    Route::post('/ctrip/traffic', 'ota.CtripController/fetchCtripTraffic');
    Route::post('/fetch-meituan-traffic', 'ota.MeituanController/fetchMeituanTraffic');
    Route::post('/fetch-meituan-order-flow', 'ota.MeituanController/fetchMeituanOrderFlow');
    Route::post('/fetch-meituan-orders', 'ota.MeituanController/fetchMeituanOrders');
    Route::post('/fetch-meituan-ads', 'ota.MeituanController/fetchMeituanAds');
    Route::post('/fetch-meituan-comments', 'ota.MeituanController/fetchMeituanComments');
    Route::post('/capture-meituan-browser', 'ota.MeituanController/captureMeituanBrowserData');
    Route::post('/save-meituan-captured-data', 'ota.MeituanController/saveMeituanCapturedData');
    Route::get('/ctrip/latest', 'ota.CtripController/ctripLatest');
    Route::get('/ctrip/search-opportunity', 'ota.CtripController/ctripSearchOpportunity');
    Route::get('/ctrip/history', 'ota.CtripController/ctripHistory');
    Route::post('/fetch-custom', 'OnlineData/fetchCustom');
    Route::post('/save-cookies', 'ota.CredentialController/saveCookies');
    Route::get('/cookies-list', 'ota.CredentialController/getCookiesList');
    Route::get('/cookies-detail', 'ota.CredentialController/getCookiesDetail');
    Route::post('/delete-cookies', 'ota.CredentialController/deleteCookies');
    Route::post('/batch-delete-cookies', 'ota.CredentialController/batchDeleteCookies');
    Route::get('/bookmarklet', 'ota.CredentialController/bookmarklet');
    // 美团配置
    Route::post('/save-meituan-config', 'ota.CredentialController/saveMeituanConfig');
    Route::get('/get-meituan-config', 'ota.CredentialController/getMeituanConfig');
    Route::post('/save-meituan-config-item', 'ota.CredentialController/saveMeituanConfigItem');
    Route::get('/get-meituan-config-list', 'ota.CredentialController/getMeituanConfigList');
    Route::get('/get-meituan-config-detail', 'ota.CredentialController/getMeituanConfigDetail');
    Route::delete('/delete-meituan-config', 'ota.CredentialController/deleteMeituanConfig');
    Route::get('/generate-meituan-bookmarklet', 'ota.CredentialController/generateMeituanBookmarklet');
    // 美团点评配置（优化版）
    Route::post('/save-meituan-comment-config', 'ota.CredentialController/saveMeituanCommentConfig');
    Route::get('/get-meituan-comment-config-list', 'ota.CredentialController/getMeituanCommentConfigList');
    // 携程点评配置
    Route::post('/fetch-ctrip-comments', 'ota.CtripController/fetchCtripComments');
    Route::post('/capture-ctrip-comments-browser', 'ota.CtripController/captureCtripCommentsBrowserData');
    Route::post('/capture-ctrip-browser', 'ota.CtripController/captureCtripBrowserData');
    Route::get('/ctrip-diagnosis-snapshot', 'ota.CtripController/ctripDiagnosisSnapshot');
    Route::get('/ctrip-profile-status', 'ota.ProfileController/ctripProfileStatus');
    Route::get('/ctrip-profile-fields', 'ota.ProfileController/getCtripProfileFields');
    Route::get('/ctrip-profile-modules', 'ota.ProfileController/getCtripProfileModules');
    Route::post('/sync-ctrip-profile-fields', 'ota.ProfileController/syncCtripProfileFields');
    Route::post('/save-ctrip-profile-field', 'ota.ProfileController/saveCtripProfileField');
    Route::post('/save-ctrip-profile-module', 'ota.ProfileController/saveCtripProfileModule');
    Route::post('/verify-ctrip-profile-field-sample', 'ota.ProfileController/verifyCtripProfileFieldSample');
    Route::post('/recheck-ctrip-profile-mismatched-fields', 'ota.ProfileController/recheckCtripProfileMismatchedFields');
    Route::delete('/delete-ctrip-profile-field', 'ota.ProfileController/deleteCtripProfileField');
    Route::delete('/delete-ctrip-profile-module', 'ota.ProfileController/deleteCtripProfileModule');
    Route::get('/meituan-profile-status', 'ota.ProfileController/meituanProfileStatus');
    Route::get('/platform-profile-status', 'ota.ProfileController/platformProfileStatus');
    Route::post('/profile-binding-unbind', 'ota.ProfileController/deletePlatformProfileBinding');
    Route::post('/profile-login-trigger/:platform', 'ota.ProfileController/triggerPlatformProfileLogin');
    Route::get('/profile-login-status/:platform', 'ota.ProfileController/platformProfileLoginStatus');
    Route::get('/ctrip-collector-contract', 'ota.CtripController/ctripCollectorContract');
    Route::post('/fetch-ctrip-cookie-api', 'ota.CtripController/fetchCtripCookieApiData');
    Route::post('/validate-ctrip-endpoint-evidence', 'ota.CtripController/validateCtripEndpointEvidence');
    Route::post('/fetch-ctrip-overview', 'ota.CtripController/fetchCtripOverviewData');
    Route::post('/fetch-ctrip-ads', 'ota.CtripController/fetchCtripAds');
    Route::post('/save-ctrip-comment-config', 'ota.CredentialController/saveCtripCommentConfig');
    Route::get('/get-ctrip-comment-config-list', 'ota.CredentialController/getCtripCommentConfigList');
    Route::post('/ctrip-review-matches/im-sessions', 'ota.CtripController/saveCtripReviewImSession');
    Route::post('/ctrip-review-matches/reviews', 'ota.CtripController/saveCtripReviewForMatch');
    Route::post('/ctrip-review-matches/orders', 'ota.CtripController/saveCtripOrderForMatch');
    Route::post('/ctrip-review-matches/lookup', 'ota.CtripController/lookupCtripReviewOrderMatch');
    Route::post('/ctrip-review-matches/identity-preview', 'ota.CtripController/previewCtripReviewOrdererIdentity');
    Route::post('/ctrip-review-matches/run', 'ota.CtripController/runCtripReviewOrderMatchAutomation');
    Route::post('/ctrip-review-matches/closure', 'ota.CtripController/checkCtripReviewOrderMatchClosure');
    Route::post('/ctrip-review-matches/bind', 'ota.CtripController/bindCtripReviewOrderMatch');
    Route::post('/meituan-review-matches/reviews', 'ota.MeituanController/saveMeituanReviewForMatch');
    Route::post('/meituan-review-matches/orders', 'ota.MeituanController/saveMeituanOrderForMatch');
    Route::post('/meituan-review-matches/lookup', 'ota.MeituanController/lookupMeituanReviewOrderMatch');
    Route::post('/meituan-review-matches/bind', 'ota.MeituanController/bindMeituanReviewOrderMatch');
    Route::post('/meituan-review-matches/unbind', 'ota.MeituanController/unbindMeituanReviewOrderMatch');
    Route::post('/meituan-orders/phone-state', 'ota.MeituanController/meituanOrderPhoneState');
    // 携程配置
    Route::post('/save-ctrip-config', 'ota.CredentialController/saveCtripConfig');
    Route::get('/get-ctrip-config-list', 'ota.CredentialController/getCtripConfigList');
    Route::get('/get-ctrip-config-detail', 'ota.CredentialController/getCtripConfigDetail');
    Route::delete('/delete-ctrip-config', 'ota.CredentialController/deleteCtripConfig');
    Route::get('/generate-ctrip-bookmarklet', 'ota.CredentialController/generateCtripBookmarklet');
    Route::any('/auto-capture-ctrip-cookie', 'ota.CredentialController/autoCaptureCtripCookie');
    Route::post('/save-ctrip-config-by-bookmark', 'ota.CredentialController/saveCtripConfigByBookmark');
    // 线上数据管理
    Route::get('/collection-resources', 'ota.SyncController/collectionResourceCatalog');
    Route::get('/collection-status', 'ota.SyncController/collectionStatus');
    Route::get('/data-sources', 'ota.SyncController/dataSourceList');
    Route::post('/data-sources/:id/sync', 'ota.SyncController/syncDataSource');
    Route::post('/data-sources', 'ota.SyncController/saveDataSource');
    Route::delete('/data-sources/:id', 'ota.SyncController/deleteDataSource');
    Route::post('/data-import', 'ota.SyncController/importDataSourceRows');
    Route::post('/browser-assist-import', 'ota.SyncController/importBrowserAssistCapture');
    Route::get('/sync-tasks', 'ota.SyncController/syncTaskList');
    Route::get('/sync-logs', 'ota.SyncController/syncLogList');
    Route::post('/save-daily-data', 'OnlineData/saveDailyData');
    Route::post('/update-data', 'OnlineData/updateData');
    Route::post('/delete-data', 'OnlineData/deleteData');
    Route::delete('/delete-data', 'OnlineData/deleteData');
    Route::get('/correction-ledger', 'OnlineData/correctionLedger');
    Route::post('/restore-data', 'OnlineData/restoreData');
    Route::get('/cookie-status', 'ota.CredentialController/cookieStatus');
    Route::get('/public-endpoint-security', 'OnlineData/publicEndpointSecurity');
    Route::get('/release-evidence-status', 'OnlineData/releaseEvidenceStatus');
    Route::get('/collection-reliability', 'OnlineData/collectionReliability');
    Route::get('/daily-workbench', 'OnlineData/dailyWorkbench');
    Route::get('/manual-fetch-evidence', 'OnlineData/manualFetchEvidence');
    Route::get('/manual-fetch-task-status', 'ota.SyncController/manualFetchTaskStatus');
    Route::get('/daily-workbench-patrols', 'OnlineData/dailyWorkbenchPatrols');
    Route::get('/daily-workbench-patrols/report', 'OnlineData/dailyWorkbenchPatrolReport');
    Route::post('/daily-workbench-patrols/run', 'OnlineData/runDailyWorkbenchPatrol');
    Route::post('/daily-workbench-patrols/actions/update', 'OnlineData/updateDailyWorkbenchPatrolAction');
    Route::post('/daily-workbench-patrols/actions/review', 'OnlineData/reviewDailyWorkbenchPatrolAction');
    Route::get('/phase3-operation-effect-loop', 'OnlineData/phase3OperationEffectLoop');
    Route::get('/phase3-operation-effect-loop/ledger', 'OnlineData/phase3OperationEffectLoopLedger');
    Route::post('/phase3-operation-effect-loop/sops/publish', 'OnlineData/publishPhase3OperationSop');
    Route::post('/phase3-operation-effect-loop/replications/create', 'OnlineData/createPhase3ReplicationPlan');
    Route::get('/history/:id', 'OnlineData/historyDetail');
    Route::get('/history', 'OnlineData/history');
    Route::get('/daily-data-list', 'OnlineData/dailyDataList');
    Route::get('/daily-data-summary', 'OnlineData/dailyDataSummary');
    Route::get('/hotel-list', 'OnlineData/hotelList');
    Route::post('/auto-fetch', 'ota.SyncController/autoFetch');
    Route::get('/auto-fetch-status', 'ota.SyncController/autoFetchStatus');
    Route::get('/auto-fetch-records', 'ota.SyncController/autoFetchRecords');
    Route::post('/batch-delete-auto-fetch-records', 'ota.SyncController/batchDeleteAutoFetchRecords');
    Route::post('/clear-auto-fetch-records', 'ota.SyncController/clearAutoFetchRecords');
    Route::post('/toggle-auto-fetch', 'ota.SyncController/toggleAutoFetch');
    Route::post('/set-fetch-schedule', 'ota.SyncController/setFetchSchedule');
    Route::post('/retry-auto-fetch', 'ota.SyncController/retryAutoFetch');
    Route::post('/batch-delete', 'OnlineData/batchDelete');
    // 数据分析
    Route::get('/data-analysis', 'OnlineData/dataAnalysis');
    // AI智能分析
    Route::post('/ai-analysis', 'OnlineData/aiAnalysis');
})->middleware(\app\middleware\Auth::class);

// ==================== 酒店数据驾驶舱 API ====================
Route::group('api/dashboard', function () {
    Route::get('/account-overview', 'OnlineData/dashboardAccountOverview');
    Route::get('/hotel-portrait', 'OnlineData/dashboardHotelPortrait');
    Route::get('/data-sources', 'OnlineData/dashboardDataSources');
})->middleware(\app\middleware\Auth::class);

// ==================== 智能知识中枢 API ====================
Route::group('api/knowledge', function () {
    Route::get('/distillation/options', 'Knowledge/distillationOptions');
    Route::post('/distillation/run', 'Knowledge/runDistillation');
    Route::get('/list', 'Knowledge/unitList');
    Route::post('/add', 'Knowledge/add');
    Route::post('/import', 'Knowledge/importMaterials');
    Route::post('/document-text', 'Knowledge/extractDocumentText');
    Route::post('/:unit_id/add-chunk', 'Knowledge/addChunk');
    Route::post('/:unit_id/chunks/:chunk_id/execution-intent', 'Knowledge/createExecutionIntent');
    Route::post('/:unit_id/update', 'Knowledge/update');
    Route::post('/:unit_id/status', 'Knowledge/status');
    Route::delete('/:unit_id', 'Knowledge/delete');
    Route::get('/:unit_id', 'Knowledge/detail');
})->middleware(\app\middleware\Auth::class);

// ==================== 酒店收益管理研究中心 API ====================
Route::group('api/revenue-research', function () {
    Route::post('/run', 'RevenueResearch/run');
    Route::post('/execution-intent', 'RevenueResearch/createExecutionIntent');
})->middleware(\app\middleware\Auth::class);

// ==================== OTA 数据标准化 API ====================
Route::group('api/ota-standard', function () {
    Route::get('/etl', 'OtaStandard/dataset');
    Route::post('/etl', 'OtaStandard/dataset');
    Route::get('/revenue-metrics', 'OtaStandard/revenueMetrics');
    Route::post('/revenue-metrics', 'OtaStandard/revenueMetrics');
    Route::get('/analysis', 'OtaStandard/analysis');
    Route::post('/analysis', 'OtaStandard/analysis');
})->middleware(\app\middleware\Auth::class);

// ==================== Revenue AI 首页只读总览 API ====================
Route::group('api/revenue-ai', function () {
    Route::get('/overview', 'RevenueAi/overview');
    Route::post('/price-suggestions/:id/review', 'RevenueAi/reviewPriceSuggestion');
    Route::post('/price-suggestions/:id/execution-intent', 'RevenueAi/createPriceSuggestionExecutionIntent');
})->middleware(\app\middleware\Auth::class);

// ==================== AI模型配置 API ====================
Route::group('api/ai-config', function () {
    Route::get('/models', 'AiConfig/models');
    Route::post('/providers/quick-setup', 'AiConfig/quickSetupProvider');
    Route::post('/models/<id>/test', 'AiConfig/testModel');
    Route::post('/models', 'AiConfig/createModel');
    Route::put('/models/<id>', 'AiConfig/updateModel');
    Route::delete('/models/<id>', 'AiConfig/deleteModel');
})->middleware(\app\middleware\Auth::class);

// ==================== AI治理 API ====================
Route::group('api/ai-governance', function () {
    Route::get('/summary', 'AiGovernance/summary');
    Route::get('/logs/:id', 'AiGovernance/logDetail');
    Route::post('/logs/:id/confirm', 'AiGovernance/confirmLog');
    Route::get('/logs', 'AiGovernance/logs');
    Route::get('/prompt-versions', 'AiGovernance/promptVersions');
    Route::post('/prompt-versions', 'AiGovernance/savePromptVersion');
    Route::post('/evaluation-cases/replay', 'AiGovernance/replayEvaluationCases');
    Route::delete('/evaluation-cases/:id', 'AiGovernance/archiveEvaluationCase');
    Route::get('/evaluation-cases', 'AiGovernance/evaluationCases');
    Route::post('/evaluation-cases', 'AiGovernance/saveEvaluationCase');
})->middleware(\app\middleware\Auth::class);

// ==================== 节假期收益倒计时 API ====================
Route::group('api/holiday-revenue', function () {
    Route::get('/countdown', 'HolidayRevenue/countdown');
})->middleware(\app\middleware\Auth::class);

// ==================== 宏观经营信号 API ====================
Route::group('api/macro-signals', function () {
    Route::get('/overview', 'MacroSignal/overview');
    Route::get('/detail', 'MacroSignal/detail');
    Route::get('/trends', 'MacroSignal/trends');
    Route::get('/external', 'MacroSignal/external');
})->middleware(\app\middleware\Auth::class);

// ==================== Unified past / present / future API ====================
Route::group('api/temporal-insights', function () {
    Route::get('/overview', 'TemporalInsight/overview');
    Route::post('/forecasts', 'TemporalInsight/generateForecast');
})->middleware(\app\middleware\Auth::class);

// ==================== 全生命周期真实数据 API ====================
Route::group('api/lifecycle', function () {
    Route::get('/overview', 'Lifecycle/overview');
})->middleware(\app\middleware\Auth::class);
// ==================== P4 投资决策辅助 API ====================
Route::group('api/investment-decision', function () {
    Route::get('/overview', 'InvestmentDecision/overview');
})->middleware(\app\middleware\Auth::class);
// ==================== 智略·战略推演 API ====================
Route::group('api/strategy', function () {
    Route::post('/simulate', 'StrategySimulation/simulate');
    Route::post('/records/:id/execution-intent', 'StrategySimulation/createExecutionIntent');
    Route::delete('/records/:id', 'StrategySimulation/archive');
    Route::get('/records/:id', 'StrategySimulation/detail');
    Route::get('/records', 'StrategySimulation/records');
})->middleware(\app\middleware\Auth::class);
// ==================== 智算·量化模拟 API ====================
Route::group('api/simulation', function () {
    Route::post('/calculate', 'Simulation/calculate');
    Route::post('/records/:id/execution-intent', 'Simulation/createExecutionIntent');
    Route::delete('/records/:id', 'Simulation/archive');
    Route::get('/records/:id', 'Simulation/detail');
    Route::get('/records', 'Simulation/records');
})->middleware(\app\middleware\Auth::class);
// ==================== 运营管理 API ====================
Route::group('api/operation', function () {
    Route::get('/full-data', 'OperationManagement/fullData');
    Route::post('/root-cause', 'OperationManagement/rootCause');
    Route::get('/alerts', 'OperationManagement/alerts');
    Route::post('/alerts/read', 'OperationManagement/alertsRead');
    Route::post('/alerts/:id/execution-intent', 'OperationManagement/alertExecutionIntent');
    Route::post('/strategy-simulation', 'OperationManagement/strategySimulation');
    Route::post('/execution-intents/:id/approve', 'OperationManagement/approveExecutionIntent');
    Route::post('/execution-tasks/:id/execute', 'OperationManagement/executeExecutionTask');
    Route::post('/execution-tasks/:id/evidence', 'OperationManagement/executionTaskEvidence');
    Route::post('/execution-tasks/:id/review', 'OperationManagement/reviewExecutionTask');
    Route::get('/closure-overview', 'OperationManagement/closureOverview');
    Route::get('/execution-flow', 'OperationManagement/executionFlow');
    Route::get('/execution-intents/:id', 'OperationManagement/readExecutionIntent');
    Route::get('/execution-tasks/:id', 'OperationManagement/readExecutionTask');
    Route::get('/execution-intents', 'OperationManagement/executionIntents');
    Route::post('/execution-intents', 'OperationManagement/createExecutionIntent');
    Route::post('/actions/:id/finish', 'OperationManagement/finishAction');
    Route::post('/actions', 'OperationManagement/actions');
    Route::get('/action-tracking', 'OperationManagement/actionTracking');
})->middleware(\app\middleware\Auth::class);

// ==================== 开业管理 API ====================
Route::group('api/opening', function () {
    Route::get('/projects/:id/overview', 'Opening/overview');
    Route::post('/projects/:id/generate-tasks', 'Opening/generateTasks');
    Route::get('/projects/:id/tasks', 'Opening/tasks');
    Route::put('/projects/:id', 'Opening/updateProject');
    Route::delete('/projects/:id', 'Opening/archiveProject');
    Route::post('/projects/:id/execution-intent', 'Opening/createExecutionIntent');
    Route::put('/tasks/:id', 'Opening/updateTask');
    Route::post('/projects/:id/recalculate', 'Opening/recalculate');
    Route::post('/projects', 'Opening/createProject');
    Route::get('/projects', 'Opening/projects');
})->middleware(\app\middleware\Auth::class);
// ==================== 扩张管理 API ====================
Route::group('api/expansion', function () {
    Route::post('/market-evaluation', 'Expansion/marketEvaluation');
    Route::post('/benchmark-model', 'Expansion/benchmarkModel');
    Route::post('/collaboration-efficiency', 'Expansion/collaborationEfficiency');
    Route::post('/records/:id/execution-intent', 'Expansion/createExecutionIntent');
    Route::delete('/records/market-evaluation', 'Expansion/clearMarketEvaluation');
    Route::delete('/records/:id', 'Expansion/archive');
    Route::delete('/records', 'Expansion/clearRecords');
    Route::get('/records/:id', 'Expansion/detail');
    Route::get('/records', 'Expansion/records');
})->middleware(\app\middleware\Auth::class);
// ==================== 转让管理 API ====================
Route::group('api/transfer', function () {
    Route::get('/source', 'TransferDecision/source');
    Route::post('/pricing', 'TransferDecision/pricing');
    Route::post('/timing', 'TransferDecision/timing');
    Route::post('/dashboard', 'TransferDecision/dashboard');
    Route::post('/records/:id/execution-intent', 'TransferDecision/createExecutionIntent');
    Route::delete('/records/:id', 'TransferDecision/archive');
    Route::get('/records/:id', 'TransferDecision/detail');
    Route::get('/records', 'TransferDecision/records');
})->middleware(\app\middleware\Auth::class);
// ==================== 竞对价格监控 API ====================
Route::get('api/competitor/events', 'CompetitorApi/events')->middleware(\app\middleware\Auth::class);
Route::get('api/competitor/targets', 'CompetitorApi/targets')->middleware(\app\middleware\Auth::class);
Route::post('api/competitor/manual-observation', 'CompetitorApi/manualObservation')->middleware(\app\middleware\Auth::class);
Route::post('api/competitor/task', 'CompetitorApi/task');
Route::post('api/competitor/report', 'CompetitorApi/report');

// ==================== 竞对价格监控 管理 ====================
Route::group('api/admin/competitor-hotels', function () {
    Route::get('/', 'admin.CompetitorHotelController/index');
    Route::get('/stores', 'admin.CompetitorHotelController/stores');
    Route::get('/platforms', 'admin.CompetitorHotelController/platforms');
    Route::post('/', 'admin.CompetitorHotelController/create');
    Route::put('/:id', 'admin.CompetitorHotelController/update');
    Route::delete('/:id', 'admin.CompetitorHotelController/delete');
})->middleware(\app\middleware\Auth::class);

Route::group('api/admin/competitor-price-logs', function () {
    Route::get('/', 'admin.CompetitorPriceLogController/index');
})->middleware(\app\middleware\Auth::class);

Route::group('api/admin/competitor-devices', function () {
    Route::get('/', 'admin.CompetitorDeviceController/index');
    Route::post('/', 'admin.CompetitorDeviceController/create');
    Route::put('/:id/rebind', 'admin.CompetitorDeviceController/rebind');
    Route::post('/:id/rotate-token', 'admin.CompetitorDeviceController/rotateToken');
    Route::put('/:id/status', 'admin.CompetitorDeviceController/updateStatus');
})->middleware(\app\middleware\Auth::class);

// ==================== 企业微信机器人 ====================
Route::group('admin/competitor-wechat-robot', function () {
    Route::get('/', 'admin.CompetitorWechatRobotController/index');
    Route::get('/add', 'admin.CompetitorWechatRobotController/add');
    Route::post('/save', 'admin.CompetitorWechatRobotController/save');
    Route::get('/edit/:id', 'admin.CompetitorWechatRobotController/edit');
    Route::post('/update/:id', 'admin.CompetitorWechatRobotController/update');
    Route::post('/delete/:id', 'admin.CompetitorWechatRobotController/delete');
    Route::post('/test/:id', 'admin.CompetitorWechatRobotController/testSend');
    Route::post('/test-store/:storeId', 'admin.CompetitorWechatRobotController/testSendStore');
})->middleware(\app\middleware\Auth::class);

// ==================== 门店罗盘 ====================
Route::group('compass', function () {
    Route::get('index', 'admin.Compass/index');
    Route::post('save-layout', 'admin.Compass/saveLayout');
})->middleware(\app\middleware\Auth::class);

// ==================== 门店罗盘 API ====================
Route::group('api/compass', function () {
    Route::get('/', 'admin.Compass/apiIndex');
    Route::post('/layout', 'admin.Compass/apiSaveLayout');
})->middleware(\app\middleware\Auth::class);

// ==================== 企业微信机器人 API（SPA） ====================
Route::group('api/admin/competitor-wechat-robot', function () {
    Route::get('/', 'admin.CompetitorWechatRobotController/apiIndex');
    Route::get('/detail/:id', 'admin.CompetitorWechatRobotController/apiDetail');
    Route::post('/save', 'admin.CompetitorWechatRobotController/apiSave');
    Route::post('/update/:id', 'admin.CompetitorWechatRobotController/apiUpdate');
    Route::post('/delete/:id', 'admin.CompetitorWechatRobotController/apiDelete');
    Route::post('/test-store/:storeId', 'admin.CompetitorWechatRobotController/apiTestStore');
})->middleware(\app\middleware\Auth::class);

// 接收书签脚本的Cookies（不需要认证中间件，通过token参数验证）
Route::rule('api/online-data/receive-cookies', 'ota.CredentialController/receiveCookies', 'POST|OPTIONS');

// 定时任务触发接口（不需要认证，通过X-Cron-Token验证）
Route::get('api/online-data/cron-trigger', 'ota.SyncController/cronTrigger');
Route::get('api/online-data/daily-workbench-patrol-cron', 'OnlineData/dailyWorkbenchPatrolCron');

// ==================== 操作日志路由 ====================
Route::group('api/operation-logs', function () {
    Route::get('/', 'OperationLogController/index');
    Route::get('/stats', 'OperationLogController/stats');
    Route::get('/high-risk-summary', 'OperationLogController/highRiskSummary');
    Route::get('/security-overview', 'OperationLogController/securityOverview');
    Route::get('/:id', 'OperationLogController/detail');
})->middleware(\app\middleware\Auth::class);

// ==================== 系统通知路由 ====================
Route::group('api/notifications', function () {
    Route::get('/', 'SystemNotificationController/index');
    Route::post('/read', 'SystemNotificationController/markRead');
    Route::post('/read-all', 'SystemNotificationController/markAllRead');
    Route::post('/clear', 'SystemNotificationController/clear');
})->middleware(\app\middleware\Auth::class);

// 健康检查
Route::get('api/health', function () {
    $checkedAt = date('Y-m-d H:i:s');
    try {
        $result = \think\facade\Db::query('SELECT 1 AS ready');
        if ((int)($result[0]['ready'] ?? 0) !== 1) {
            throw new \RuntimeException('database readiness probe returned an invalid result');
        }
    } catch (\Throwable) {
        return json([
            'status' => 'unavailable',
            'time' => $checkedAt,
            'checks' => [
                'application' => 'ok',
                'database' => 'unavailable',
            ],
        ], 503);
    }

    return json([
        'status' => 'ok',
        'time' => $checkedAt,
        'checks' => [
            'application' => 'ok',
            'database' => 'ok',
        ],
    ]);
});
// ==================== AI Agent 路由 ====================
Route::group('api/agent', function () {
    // 概览
    Route::get('/overview', 'Agent/overview');
    Route::post('/test-llm', 'Agent/testLlm');
    Route::get('/ota-diagnosis', 'Agent/latestOtaDiagnosis');
    Route::post('/ota-diagnosis', 'Agent/otaDiagnosis');
    Route::post('/ota-diagnoses/:id/actions/:actionIndex/execution-intent', 'Agent/createOtaDiagnosisExecutionIntent');
    Route::post('/analyze-captured-ota-data', 'Agent/analyzeCapturedOtaData');
    Route::post('/summarize-captured-ota-analysis', 'Agent/summarizeCapturedOtaAnalysis');

    // 智策·可行性报告
    // Feasibility report
    Route::post('/feasibility-report/generate', 'Agent/feasibilityReportGenerate');
    Route::get('/feasibility-report/detail/:id', 'Agent/feasibilityReportDetail');
    Route::post('/feasibility-report/regenerate/:id', 'Agent/feasibilityReportRegenerate');
    Route::post('/feasibility-report/:id/execution-intent', 'Agent/createFeasibilityExecutionIntent');
    Route::delete('/feasibility-report/:id', 'Agent/feasibilityReportArchive');
    Route::get('/feasibility-report/list', 'Agent/feasibilityReportList');
    // 配置管理
    Route::get('/config', 'Agent/getConfig');
    Route::post('/config', 'Agent/saveConfig');

    // 知识库
    Route::get('/knowledge', 'Agent/knowledgeList');
    Route::post('/knowledge', 'Agent/saveKnowledge');
    Route::delete('/knowledge/:id', 'Agent/deleteKnowledge');
    Route::get('/knowledge-categories', 'Agent/knowledgeCategories');

    // ========== 收益管理Agent ==========
    // 定价建议
    Route::get('/price-suggestions', 'Agent/priceSuggestions');
    Route::post('/price-suggestions/generate', 'Agent/generatePriceSuggestions');
    Route::post('/price-suggestions/:id/approve', 'Agent/approvePrice');
    Route::post('/price-suggestions/:id/apply', 'Agent/applyPrice');
    Route::post('/price-suggestions/:id/execution-intent', 'Agent/createPriceSuggestionExecutionIntent');
    Route::get('/price-suggestions/:id/review', 'Agent/priceSuggestionReview');
    Route::get('/revenue-bundle', 'Agent/revenueBundle');
    Route::get('/revenue-analysis', 'Agent/revenueAnalysis');
    Route::get('/cookie-warnings', 'Agent/cookieWarnings');
    Route::get('/room-types', 'Agent/roomTypes');
    Route::post('/room-types', 'Agent/saveRoomType');

    // 需求预测
    Route::get('/demand-forecasts', 'Agent/demandForecasts');
    Route::post('/demand-forecasts', 'Agent/createForecast');

    // 竞对分析
    Route::get('/competitor-analysis', 'Agent/competitorAnalysis');
    Route::post('/competitor-analysis', 'Agent/recordCompetitorPrice');

    // 收益管理仪表板
    Route::get('/revenue-dashboard', 'Agent/revenueDashboard');

    // 日志
    Route::get('/logs', 'Agent/logs');
})->middleware(\app\middleware\Auth::class);
