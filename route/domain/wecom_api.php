<?php
declare(strict_types=1);

use think\facade\Route;

// Hotel notification templates, formal schedules, test sends, and receipts.
Route::group('api/manual-notifications', function () {
    Route::get('/metadata', 'ManualNotification/metadata');
    Route::get('/monitor', 'ManualNotification/monitor');
    Route::get('/history', 'ManualNotification/history');
    Route::get('/dispatch-history', 'ManualNotification/dispatchHistory');
    Route::post('/preview', 'ManualNotification/preview');
    Route::post('/dispatches/:dispatchId/retry', 'ManualNotification/retryDispatch');
    Route::post('/:id/test-push', 'ManualNotification/testPush');
    Route::get('/:id', 'ManualNotification/read');
    Route::post('/', 'ManualNotification/save');
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

// Account self-service enterprise WeChat onboarding. Every request is scoped
// to the logged-in user's permitted hotel; complete Webhooks are never read.
Route::group('api/wechat-notification', function () {
    Route::get('/status', 'WechatNotificationOnboarding/status');
    Route::post('/bind', 'WechatNotificationOnboarding/bind');
    Route::post('/test', 'WechatNotificationOnboarding/test');
})->middleware(\app\middleware\Auth::class);
