<?php
declare(strict_types=1);

use think\facade\Route;

Route::get('/system-guidance/context', 'SystemLearning/context');
Route::post('/system-guidance/preferences/revoke', 'SystemLearning/revokePreference');
Route::post('/system-guidance/preferences/reset', 'SystemLearning/resetPreferences');
Route::post('/system-guidance/preferences', 'SystemLearning/savePreference');
Route::post('/system-guidance/journey/transition', 'SystemLearning/transitionJourney');
Route::post('/system-guidance/journey/archive', 'SystemLearning/archiveJourney');
Route::post('/system-guidance/journey', 'SystemLearning/saveJourney');
Route::post('/system-guidance/feedback', 'SystemLearning/recordSuggestionFeedback');
Route::post('/system-guidance', 'SystemGuidance/guide');
Route::get('/precise-query-lexicon', 'PreciseQuery/lexicon');
Route::get('/precise-queries/:id', 'PreciseQuery/read');
Route::post('/precise-queries', 'PreciseQuery/create');
Route::get('/operating-questions/:questionId/feedbacks/mine', 'HotelDataAnalystFeedback/mine');
Route::get('/operating-questions/:questionId/feedbacks/:feedbackId', 'HotelDataAnalystFeedback/read');
Route::post('/operating-questions/:questionId/feedbacks', 'HotelDataAnalystFeedback/create');
Route::post('/price-suggestions/:id/shadow-replays', 'Agent/createPriceSuggestionShadowReplay');
Route::get('/price-suggestions/:id/shadow-replays', 'Agent/priceSuggestionShadowReplays');
Route::post('/operating-questions/:id/council-runs/:runId/resume', 'OperatingIntelligence/resumeQuestionCouncil');
