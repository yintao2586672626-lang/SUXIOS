<?php
declare(strict_types=1);

use think\facade\Route;

Route::get('/broadcast-snapshots/latest', 'AiDailyReportBroadcast/latest');
Route::get('/broadcast-snapshots/:snapshotId', 'AiDailyReportBroadcast/read');
Route::post('/broadcast-snapshots', 'AiDailyReportBroadcast/generate');
Route::get('/latest', 'AiDailyReport/latest');
Route::post('/generate', 'AiDailyReport/generate');
Route::get('/tasks/:taskId', 'AiDailyReport/generationTask');
