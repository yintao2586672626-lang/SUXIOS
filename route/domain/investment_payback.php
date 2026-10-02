<?php
declare(strict_types=1);

use think\facade\Route;

Route::group('api/investment-payback', function () {
    Route::post('/import/preview', 'InvestmentPayback/importPreview')->completeMatch(true);
    Route::post('/import/confirm', 'InvestmentPayback/importConfirm')->completeMatch(true);
    Route::post('/layout', 'InvestmentPayback/saveLayout')->completeMatch(true);
    Route::get('/scenario/reference-example', 'InvestmentScenario/referenceExample')->completeMatch(true);
    Route::post('/scenario/compare', 'InvestmentScenario/compare')->completeMatch(true);
    Route::get('/projects/:id/scenario', 'InvestmentScenario/detail')->completeMatch(true);
    Route::get('/projects/:id/scenario/library', 'InvestmentScenario/library')->completeMatch(true);
    Route::get('/projects/:id/scenario/history', 'InvestmentScenario/history')->completeMatch(true);
    Route::get('/projects/:id/scenario/history/:eventId', 'InvestmentScenario/version')->completeMatch(true);
    Route::post('/projects/:id/scenario/history/:eventId/copy', 'InvestmentScenario/copyVersion')->completeMatch(true);
    Route::get('/projects/:id/scenario/consumables-reference', 'InvestmentScenario/consumablesReference')->completeMatch(true);
    Route::post('/projects/:id/scenario', 'InvestmentScenario/save')->completeMatch(true);
    Route::post('/projects/:id/scenario/preview', 'InvestmentScenario/preview')->completeMatch(true);
    Route::get('/projects', 'InvestmentPayback/projects')->completeMatch(true);
    Route::post('/projects', 'InvestmentPayback/saveProject')->completeMatch(true);
    Route::get('/projects/:id', 'InvestmentPayback/detail')->completeMatch(true);
    Route::post('/projects/:id/archive', 'InvestmentPayback/archive')->completeMatch(true);
    Route::post('/projects/:id/entries', 'InvestmentPayback/saveEntry')->completeMatch(true);
    Route::post('/projects/:id/entries/:entryId/void', 'InvestmentPayback/voidEntry')->completeMatch(true);
    Route::post('/projects/:id/entries/:entryId/delete', 'InvestmentPayback/deleteEntry')->completeMatch(true);
})->middleware(\app\middleware\Auth::class);

// Keep the existing decision overview adjacent to the investment ledger routes.
Route::group('api/investment-decision', function () {
    Route::get('/overview', 'InvestmentDecision/overview');
})->middleware(\app\middleware\Auth::class);
