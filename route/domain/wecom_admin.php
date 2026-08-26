<?php
declare(strict_types=1);

use think\facade\Route;

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
