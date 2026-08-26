<?php
declare(strict_types=1);

use think\facade\Route;

// ==================== AI模型配置 API ====================
Route::group('api/ai-config', function () {
    Route::get('/models', 'AiConfig/models');
    Route::post('/providers/quick-setup', 'AiConfig/quickSetupProvider');
    Route::post('/models/<id>/test', 'AiConfig/testModel');
    Route::post('/models', 'AiConfig/createModel');
    Route::put('/models/<id>', 'AiConfig/updateModel');
    Route::delete('/models/<id>', 'AiConfig/deleteModel');
})->middleware(\app\middleware\Auth::class);

// ==================== AI治理 API ====================
Route::group('api/ai-governance', function () {
    Route::get('/summary', 'AiGovernance/summary');
    Route::get('/logs/:id', 'AiGovernance/logDetail');
    Route::post('/logs/:id/confirm', 'AiGovernance/confirmLog');
    Route::get('/logs', 'AiGovernance/logs');
    Route::get('/prompt-versions', 'AiGovernance/promptVersions');
    Route::post('/prompt-versions', 'AiGovernance/savePromptVersion');
    Route::post('/evaluation-cases/replay', 'AiGovernance/replayEvaluationCases');
    Route::get('/evaluation-runs/:id', 'AiGovernance/evaluationRunDetail');
    Route::get('/evaluation-runs', 'AiGovernance/evaluationRuns');
    Route::delete('/evaluation-cases/:id', 'AiGovernance/archiveEvaluationCase');
    Route::get('/evaluation-cases', 'AiGovernance/evaluationCases');
    Route::post('/evaluation-cases', 'AiGovernance/saveEvaluationCase');
})->middleware(\app\middleware\Auth::class);
