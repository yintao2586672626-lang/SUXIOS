<?php
declare(strict_types=1);

use think\facade\Route;

Route::post('/system-guidance', 'SystemGuidance/guide');
Route::get('/precise-query-lexicon', 'PreciseQuery/lexicon');
Route::get('/precise-queries/:id', 'PreciseQuery/read');
Route::post('/precise-queries', 'PreciseQuery/create');
Route::post('/operating-questions/:id/council-runs/:runId/resume', 'OperatingIntelligence/resumeQuestionCouncil');
