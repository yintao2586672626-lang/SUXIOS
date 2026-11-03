<?php
declare(strict_types=1);
use think\facade\Route;
Route::group('api/business-workspace',function(){
    Route::get('/overview','BusinessWorkspace/overview')->completeMatch(true);
    Route::post('/snapshots','BusinessWorkspace/save')->completeMatch(true);
    Route::get('/snapshots/:id','BusinessWorkspace/read')->completeMatch(true);
    Route::post('/source-preview','BusinessWorkspace/mappingPreview')->completeMatch(true);
})->middleware(\app\middleware\Auth::class);
