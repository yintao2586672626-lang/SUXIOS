<?php
declare(strict_types=1);
use think\facade\Route;

// ThinkPHP Http::loadRoutes loads root route/*.php. Kept separate from concurrent app.php work.
Route::group('api/hotel-learning', function () {
    Route::get('/overview', 'HotelLearning/overview');
    Route::post('/preview', 'HotelLearning/preview');
    Route::post('/snapshots', 'HotelLearning/save');
    Route::get('/snapshots/:id', 'HotelLearning/read');
})->middleware(\app\middleware\Auth::class);
