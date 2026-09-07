<?php
declare(strict_types=1);

use think\facade\Route;

// Included inside the existing api/revenue-ai group; all routes inherit Auth.
Route::get('/forecast-workbench/context', 'RevenueForecastWorkbench/context');
Route::post('/forecast-workbench/preview', 'RevenueForecastWorkbench/preview');
Route::post('/forecast-workbench/plans', 'RevenueForecastWorkbench/save');
Route::get('/forecast-workbench/plans', 'RevenueForecastWorkbench/history');
Route::get('/forecast-workbench/plans/:id', 'RevenueForecastWorkbench/detail');
Route::get('/overview', 'RevenueAi/overview');
Route::get('/cockpit/decision-snapshots', 'RevenueAi/readCockpitDecisionSnapshot');
Route::post('/cockpit/decision-snapshots/:id/pending-approval', 'RevenueAi/createCockpitOpportunityPendingApproval');
Route::post('/cockpit/decision-snapshots', 'RevenueAi/createCockpitDecisionSnapshot');
Route::get('/cockpit/pending-approval', 'RevenueAi/readCockpitPendingApproval');
Route::post('/price-suggestions/:id/review', 'RevenueAi/reviewPriceSuggestion');
Route::post('/price-suggestions/:id/execution-intent', 'RevenueAi/createPriceSuggestionExecutionIntent');
