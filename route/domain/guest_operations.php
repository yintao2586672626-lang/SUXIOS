<?php
declare(strict_types=1);
use think\facade\Route;
Route::group('api/guest-operations',function(){
    Route::get('/overview','GuestOperations/overview')->completeMatch(true);
    Route::post('/stays','GuestOperations/importStays')->completeMatch(true);
    Route::post('/coverage','GuestOperations/saveCoverage')->completeMatch(true);
    Route::post('/feedback','GuestOperations/saveFeedback')->completeMatch(true);
    Route::post('/feedback/:caseKey/facts','GuestOperations/appendFact')->completeMatch(true);
    Route::post('/entries','GuestOperations/saveEntry')->completeMatch(true);
    Route::get('/records/:recordId','GuestOperations/read')->completeMatch(true);
    Route::get('/history','GuestOperations/history')->completeMatch(true);
})->middleware(\app\middleware\Auth::class);
