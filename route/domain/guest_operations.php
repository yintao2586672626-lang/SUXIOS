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
    Route::post('/rooms','GuestOperations/saveRooms')->completeMatch(true);
    Route::post('/public-entries','GuestOperations/savePublicEntries')->completeMatch(true);
    Route::post('/jd06/preview','GuestStayImport/preview')->completeMatch(true);
    Route::post('/jd06/import','GuestStayImport/import')->completeMatch(true);
    Route::get('/records/:recordId','GuestOperations/read')->completeMatch(true);
    Route::get('/history','GuestOperations/history')->completeMatch(true);
})->middleware(\app\middleware\Auth::class);

// Dedicated room capability authorizes only minimal feedback intake, never staff reads.
Route::post('api/guest-feedback/entry','GuestFeedback/entry')->completeMatch(true);
Route::post('api/guest-feedback/submit','GuestFeedback/submit')->completeMatch(true);
