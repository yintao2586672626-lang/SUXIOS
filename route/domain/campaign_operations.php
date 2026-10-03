<?php
declare(strict_types=1);

use think\facade\Route;

// Local records and downloadable native creative. No external/platform writes.
Route::group('api/campaign-operations', function () {
    Route::get('/overview', 'CampaignOperations/overview')->completeMatch(true);
    Route::get('/weekly', 'CampaignOperations/weekly')->completeMatch(true);
    Route::get('/creative-capability', 'CampaignOperations/creativeCapability')->completeMatch(true);
    Route::post('/records', 'CampaignOperations/save')->completeMatch(true);
    Route::post('/records/:id/handover-action', 'CampaignOperations/handoverAction')->completeMatch(true);
    Route::get('/records/:id/artifact', 'CampaignOperations/artifact')->completeMatch(true);
    Route::get('/records/:id', 'CampaignOperations/read')->completeMatch(true);
})->middleware(\app\middleware\Auth::class);
