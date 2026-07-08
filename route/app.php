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
            'Cache-Control' => 'no-cache',
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
        if ($etagMatches || ($ifModifiedSince !== '' && strtotime($ifModifiedSince) >= $mtime)) {
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
    Route::post('/fetch-ctrip', 'OnlineData/fetchCtrip');
    Route::post('/fetch-meituan', 'OnlineData/fetchMeituan');
    Route::post('/meituan/display-model', 'OnlineData/meituanDisplayModel');
    Route::get('/competitor-summary', 'OnlineData/competitorSummary');
    Route::post('/fetch-ctrip-traffic', 'OnlineData/fetchCtripTraffic');
    Route::post('/ctrip/traffic', 'OnlineData/fetchCtripTraffic');
    Route::post('/fetch-meituan-traffic', 'OnlineData/fetchMeituanTraffic');
    Route::post('/fetch-meituan-orders', 'OnlineData/fetchMeituanOrders');
    Route::post('/fetch-meituan-ads', 'OnlineData/fetchMeituanAds');
    Route::post('/fetch-meituan-comments', 'OnlineData/fetchMeituanComments');
    Route::post('/capture-meituan-browser', 'OnlineData/captureMeituanBrowserData');
    Route::post('/save-meituan-captured-data', 'OnlineData/saveMeituanCapturedData');
    Route::get('/ctrip/latest', 'OnlineData/ctripLatest');
    Route::get('/ctrip/history', 'OnlineData/ctripHistory');
    Route::post('/fetch-custom', 'OnlineData/fetchCustom');
    Route::post('/save-cookies', 'OnlineData/saveCookies');
    Route::get('/cookies-list', 'OnlineData/getCookiesList');
    Route::get('/cookies-detail', 'OnlineData/getCookiesDetail');
    Route::post('/delete-cookies', 'OnlineData/deleteCookies');
    Route::post('/batch-delete-cookies', 'OnlineData/batchDeleteCookies');
    Route::get('/bookmarklet', 'OnlineData/bookmarklet');
    // 美团配置
    Route::post('/save-meituan-config', 'OnlineData/saveMeituanConfig');
    Route::get('/get-meituan-config', 'OnlineData/getMeituanConfig');
    Route::post('/save-meituan-config-item', 'OnlineData/saveMeituanConfigItem');
    Route::get('/get-meituan-config-list', 'OnlineData/getMeituanConfigList');
    Route::get('/get-meituan-config-detail', 'OnlineData/getMeituanConfigDetail');
    Route::delete('/delete-meituan-config', 'OnlineData/deleteMeituanConfig');
    Route::get('/generate-meituan-bookmarklet', 'OnlineData/generateMeituanBookmarklet');
    // 美团点评配置（优化版）
    Route::post('/save-meituan-comment-config', 'OnlineData/saveMeituanCommentConfig');
    Route::get('/get-meituan-comment-config-list', 'OnlineData/getMeituanCommentConfigList');
    // 携程点评配置
    Route::post('/fetch-ctrip-comments', 'OnlineData/fetchCtripComments');
    Route::post('/capture-ctrip-comments-browser', 'OnlineData/captureCtripCommentsBrowserData');
    Route::post('/capture-ctrip-browser', 'OnlineData/captureCtripBrowserData');
    Route::get('/ctrip-diagnosis-snapshot', 'OnlineData/ctripDiagnosisSnapshot');
    Route::get('/ctrip-profile-status', 'OnlineData/ctripProfileStatus');
    Route::get('/ctrip-profile-fields', 'OnlineData/getCtripProfileFields');
    Route::get('/ctrip-profile-modules', 'OnlineData/getCtripProfileModules');
    Route::post('/sync-ctrip-profile-fields', 'OnlineData/syncCtripProfileFields');
    Route::post('/save-ctrip-profile-field', 'OnlineData/saveCtripProfileField');
    Route::post('/save-ctrip-profile-module', 'OnlineData/saveCtripProfileModule');
    Route::post('/verify-ctrip-profile-field-sample', 'OnlineData/verifyCtripProfileFieldSample');
    Route::post('/recheck-ctrip-profile-mismatched-fields', 'OnlineData/recheckCtripProfileMismatchedFields');
    Route::delete('/delete-ctrip-profile-field', 'OnlineData/deleteCtripProfileField');
    Route::delete('/delete-ctrip-profile-module', 'OnlineData/deleteCtripProfileModule');
    Route::get('/meituan-profile-status', 'OnlineData/meituanProfileStatus');
    Route::get('/platform-profile-status', 'OnlineData/platformProfileStatus');
    Route::post('/profile-binding-unbind', 'OnlineData/deletePlatformProfileBinding');
    Route::post('/profile-login-trigger/:platform', 'OnlineData/triggerPlatformProfileLogin');
    Route::get('/profile-login-status/:platform', 'OnlineData/platformProfileLoginStatus');
    Route::get('/ctrip-collector-contract', 'OnlineData/ctripCollectorContract');
    Route::post('/fetch-ctrip-cookie-api', 'OnlineData/fetchCtripCookieApiData');
    Route::post('/validate-ctrip-endpoint-evidence', 'OnlineData/validateCtripEndpointEvidence');
    Route::post('/fetch-ctrip-overview', 'OnlineData/fetchCtripOverviewData');
    Route::post('/fetch-ctrip-ads', 'OnlineData/fetchCtripAds');
    Route::post('/save-ctrip-comment-config', 'OnlineData/saveCtripCommentConfig');
    Route::get('/get-ctrip-comment-config-list', 'OnlineData/getCtripCommentConfigList');
    Route::post('/ctrip-review-matches/im-sessions', 'OnlineData/saveCtripReviewImSession');
    Route::post('/ctrip-review-matches/reviews', 'OnlineData/saveCtripReviewForMatch');
    Route::post('/ctrip-review-matches/orders', 'OnlineData/saveCtripOrderForMatch');
    Route::post('/ctrip-review-matches/lookup', 'OnlineData/lookupCtripReviewOrderMatch');
    Route::post('/ctrip-review-matches/identity-preview', 'OnlineData/previewCtripReviewOrdererIdentity');
    Route::post('/ctrip-review-matches/run', 'OnlineData/runCtripReviewOrderMatchAutomation');
    Route::post('/ctrip-review-matches/closure', 'OnlineData/checkCtripReviewOrderMatchClosure');
    Route::post('/ctrip-review-matches/bind', 'OnlineData/bindCtripReviewOrderMatch');
    Route::post('/meituan-review-matches/reviews', 'OnlineData/saveMeituanReviewForMatch');
    Route::post('/meituan-review-matches/orders', 'OnlineData/saveMeituanOrderForMatch');
    Route::post('/meituan-review-matches/lookup', 'OnlineData/lookupMeituanReviewOrderMatch');
    Route::post('/meituan-review-matches/bind', 'OnlineData/bindMeituanReviewOrderMatch');
    Route::post('/meituan-review-matches/unbind', 'OnlineData/unbindMeituanReviewOrderMatch');
    Route::post('/meituan-orders/phone-state', 'OnlineData/meituanOrderPhoneState');
    // 携程配置
    Route::post('/save-ctrip-config', 'OnlineData/saveCtripConfig');
    Route::get('/get-ctrip-config-list', 'OnlineData/getCtripConfigList');
    Route::get('/get-ctrip-config-detail', 'OnlineData/getCtripConfigDetail');
    Route::delete('/delete-ctrip-config', 'OnlineData/deleteCtripConfig');
    Route::get('/generate-ctrip-bookmarklet', 'OnlineData/generateCtripBookmarklet');
    Route::any('/auto-capture-ctrip-cookie', 'OnlineData/autoCaptureCtripCookie');
    Route::post('/save-ctrip-config-by-bookmark', 'OnlineData/saveCtripConfigByBookmark');
    // 线上数据管理
    Route::get('/collection-resources', 'OnlineData/collectionResourceCatalog');
    Route::get('/collection-status', 'OnlineData/collectionStatus');
    Route::get('/data-sources', 'OnlineData/dataSourceList');
    Route::post('/data-sources/:id/sync', 'OnlineData/syncDataSource');
    Route::post('/data-sources', 'OnlineData/saveDataSource');
    Route::delete('/data-sources/:id', 'OnlineData/deleteDataSource');
    Route::post('/data-import', 'OnlineData/importDataSourceRows');
    Route::post('/browser-assist-import', 'OnlineData/importBrowserAssistCapture');
    Route::get('/sync-tasks', 'OnlineData/syncTaskList');
    Route::get('/sync-logs', 'OnlineData/syncLogList');
    Route::post('/save-daily-data', 'OnlineData/saveDailyData');
    Route::post('/update-data', 'OnlineData/updateData');
    Route::post('/delete-data', 'OnlineData/deleteData');
    Route::delete('/delete-data', 'OnlineData/deleteData');
    Route::get('/cookie-status', 'OnlineData/cookieStatus');
    Route::get('/public-endpoint-security', 'OnlineData/publicEndpointSecurity');
    Route::get('/release-evidence-status', 'OnlineData/releaseEvidenceStatus');
    Route::get('/collection-reliability', 'OnlineData/collectionReliability');
    Route::get('/daily-workbench', 'OnlineData/dailyWorkbench');
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
    Route::post('/auto-fetch', 'OnlineData/autoFetch');
    Route::get('/auto-fetch-status', 'OnlineData/autoFetchStatus');
    Route::get('/auto-fetch-records', 'OnlineData/autoFetchRecords');
    Route::post('/batch-delete-auto-fetch-records', 'OnlineData/batchDeleteAutoFetchRecords');
    Route::post('/clear-auto-fetch-records', 'OnlineData/clearAutoFetchRecords');
    Route::post('/toggle-auto-fetch', 'OnlineData/toggleAutoFetch');
    Route::post('/set-fetch-schedule', 'OnlineData/setFetchSchedule');
    Route::post('/retry-auto-fetch', 'OnlineData/retryAutoFetch');
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

// ==================== AI 筹建管理路由 ====================
Route::group('api/ai', function () {
    Route::post('/strategy', 'Ai/strategy');
    Route::post('/simulation', 'Ai/simulation');
    Route::post('/feasibility', 'Ai/feasibility');
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
    Route::post('/strategy-simulation', 'OperationManagement/strategySimulation');
    Route::post('/execution-intents/:id/approve', 'OperationManagement/approveExecutionIntent');
    Route::post('/execution-tasks/:id/execute', 'OperationManagement/executeExecutionTask');
    Route::post('/execution-tasks/:id/evidence', 'OperationManagement/executionTaskEvidence');
    Route::post('/execution-tasks/:id/review', 'OperationManagement/reviewExecutionTask');
    Route::get('/closure-overview', 'OperationManagement/closureOverview');
    Route::get('/execution-flow', 'OperationManagement/executionFlow');
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
Route::rule('api/online-data/receive-cookies', 'OnlineData/receiveCookies', 'POST|OPTIONS');

// 定时任务触发接口（不需要认证，通过X-Cron-Token验证）
Route::get('api/online-data/cron-trigger', 'OnlineData/cronTrigger');
Route::get('api/online-data/daily-workbench-patrol-cron', 'OnlineData/dailyWorkbenchPatrolCron');

// ==================== 操作日志路由 ====================
Route::group('api/operation-logs', function () {
    Route::get('/', 'OperationLogController/index');
    Route::get('/stats', 'OperationLogController/stats');
    Route::get('/high-risk-summary', 'OperationLogController/highRiskSummary');
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
    return json(['status' => 'ok', 'time' => date('Y-m-d H:i:s')]);
});
// ==================== AI Agent 路由 ====================
Route::group('api/agent', function () {
    // 概览
    Route::get('/overview', 'Agent/overview');
    Route::post('/test-llm', 'Agent/testLlm');
    Route::post('/ota-diagnosis', 'Agent/otaDiagnosis');
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

    // ========== 智能员工Agent ==========
    // 知识库
    Route::get('/knowledge', 'Agent/knowledgeList');
    Route::post('/knowledge', 'Agent/saveKnowledge');
    Route::delete('/knowledge/:id', 'Agent/deleteKnowledge');
    Route::get('/knowledge-categories', 'Agent/knowledgeCategories');

    // 工单管理
    Route::get('/work-orders', 'Agent/workOrders');
    Route::post('/work-orders', 'Agent/createWorkOrder');
    Route::post('/work-orders/:id/assign', 'Agent/assignWorkOrder');
    Route::post('/work-orders/:id/resolve', 'Agent/resolveWorkOrder');
    Route::get('/work-order-stats', 'Agent/workOrderStats');

    // 对话记录
    Route::get('/conversations', 'Agent/conversations');
    Route::get('/conversation-stats', 'Agent/conversationStats');

    // 智能员工仪表板
    Route::get('/staff-dashboard', 'Agent/staffDashboard');

    // ========== 收益管理Agent ==========
    // 定价建议
    Route::get('/price-suggestions', 'Agent/priceSuggestions');
    Route::post('/price-suggestions/generate', 'Agent/generatePriceSuggestions');
    Route::post('/price-suggestions/:id/approve', 'Agent/approvePrice');
    Route::post('/price-suggestions/:id/apply', 'Agent/applyPrice');
    Route::post('/price-suggestions/:id/execution-intent', 'Agent/createPriceSuggestionExecutionIntent');
    Route::get('/price-suggestions/:id/review', 'Agent/priceSuggestionReview');
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

    // ========== 资产运维Agent ==========
    // 设备管理
    Route::get('/devices', 'Agent/deviceList');
    Route::post('/devices', 'Agent/saveDevice');
    Route::get('/device-stats', 'Agent/deviceStats');

    // 能耗管理
    Route::get('/energy-data', 'Agent/energyData');
    Route::get('/energy-benchmarks', 'Agent/energyBenchmarks');
    Route::post('/energy-benchmarks', 'Agent/saveEnergyBenchmark');
    Route::post('/energy-benchmarks/auto-calculate', 'Agent/autoCalculateBenchmark');

    // 节能建议
    Route::get('/energy-suggestions', 'Agent/energySuggestions');
    Route::post('/energy-suggestions/generate', 'Agent/generateEnergySuggestions');
    Route::post('/energy-suggestions/:id/update', 'Agent/updateEnergySuggestion');

    // 维护计划
    Route::get('/maintenance-plans', 'Agent/maintenancePlans');
    Route::post('/maintenance-plans', 'Agent/createMaintenancePlan');
    Route::post('/maintenance-plans/:id/execute', 'Agent/executeMaintenancePlan');
    Route::get('/maintenance-reminders', 'Agent/maintenanceReminders');
    Route::post('/maintenance-plans/auto-generate', 'Agent/autoGenerateMaintenancePlans');

    // 资产运维仪表板
    Route::get('/asset-dashboard', 'Agent/assetDashboard');

    // 日志和任务
    Route::get('/logs', 'Agent/logs');
    Route::get('/tasks', 'Agent/tasks');
    Route::post('/tasks', 'Agent/createTask');
})->middleware(\app\middleware\Auth::class);
