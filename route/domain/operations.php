<?php
declare(strict_types=1);

use think\facade\Route;

// ==================== 运营管理 API ====================
Route::group('api/operating-loop', function () {
    Route::get('/current', 'OperatingLoop/current');
    Route::post('/reconcile', 'OperatingLoop/reconcile');
    Route::post('/', 'OperatingLoop/open');
    Route::post('/:id/transitions', 'OperatingLoop/transition');
    Route::get('/:id', 'OperatingLoop/read');
})->middleware(\app\middleware\Auth::class);

// Five user-visible operating opportunity features. Writes are scoped
// calculation records or human pending approvals; none execute OTA/PMS actions.
Route::group('api/operating-opportunities', function () {
    Route::get('/overview', 'OperatingOpportunity/overview');
    Route::get('/weekly-plan/latest', 'OperatingOpportunity/weeklyPlanLatest');
    Route::get('/weekly-plan/snapshots/:id', 'OperatingOpportunity/weeklyPlanRead');
    Route::post('/runs/:id/pending-approval', 'OperatingOpportunity/pendingApproval');
    Route::get('/runs/:id', 'OperatingOpportunity/read');
    Route::post('/evaluate', 'OperatingOpportunity/evaluate');
    Route::post('/daily-preview/feedback', 'OperatingOpportunity/dailyPreviewFeedback');
    Route::post('/priority', 'OperatingOpportunity/priority');
})->middleware(\app\middleware\Auth::class);

// One user-visible control center for settlement truth, blocker recovery,
// real on-books pace, demand references, structured WeCom receipts, monthly
// operating finance and same-scope portfolio comparison. No route performs an
// OTA/PMS write, external send or automatic approval.
Route::group('api/operating-finance', function () {
    Route::get('/overview', 'OperatingFinance/overview');
    Route::post('/settlements/import', 'OperatingFinance/importSettlement');
    Route::post('/settlements/import-file', 'OperatingFinance/importSettlementFile');
    Route::post('/on-books-snapshots', 'OperatingFinance/saveOnBooksSnapshot');
    Route::post('/demand-events', 'OperatingFinance/saveDemandEvent');
    Route::post('/monthly-finance', 'OperatingFinance/saveMonthlyFinance');
})->middleware(\app\middleware\Auth::class);

Route::group('api/operation', function () {
    Route::get('/full-data', 'OperationManagement/fullData');
    Route::post('/root-cause', 'OperationManagement/rootCause');
    Route::get('/manager-capability/managers', 'ManagerCapability/managers');
    Route::get('/manager-capability/profile', 'ManagerCapability/profile');
    Route::get('/manager-capability/followup-queue', 'ManagerCapability/followupQueue');
    Route::get('/manager-capability/cases/:id', 'ManagerCapability/readCase');
    Route::post('/manager-capability/cases/:id/followups', 'ManagerCapability/createFollowup');
    Route::post('/manager-capability/cases/:id/adjustments', 'ManagerCapability/createAdjustment');
    Route::post('/manager-capability/cases/:id/score-reviews', 'ManagerCapability/createScoreReview');
    Route::post('/manager-capability/cases', 'ManagerCapability/createCase');
    Route::get('/goal-intervention-overview', 'OperationManagement/operatingGoalInterventionOverview');
    Route::post('/goal-contracts', 'OperationManagement/createOperatingGoalContract');
    Route::post('/interventions', 'OperationManagement/createManualIntervention');
    Route::get('/alerts', 'OperationManagement/alerts');
    Route::post('/alerts/read', 'OperationManagement/alertsRead');
    Route::post('/alerts/:id/execution-intent', 'OperationManagement/alertExecutionIntent');
    Route::post('/strategy-simulation', 'OperationManagement/strategySimulation');
    Route::post('/execution-intents/:id/approve', 'OperationManagement/approveExecutionIntent');
    Route::post('/execution-intents/:id/cancel', 'OperationManagement/cancelExecutionIntent');
    Route::post('/execution-tasks/:id/execute', 'OperationManagement/executeExecutionTask');
    Route::post('/execution-tasks/:id/evidence', 'OperationManagement/executionTaskEvidence');
    Route::post('/execution-tasks/:id/intervention-assessments', 'OperationManagement/assessExecutionTaskIntervention');
    Route::post('/execution-tasks/:id/reconcile-review', 'OperationManagement/reconcileExecutionTaskReview');
    Route::post('/execution-tasks/:id/review', 'OperationManagement/reviewExecutionTask');
    Route::post('/execution-tasks/:id/operating-memory', 'OperationManagement/saveExecutionTaskOperatingMemory');
    Route::get('/closure-overview', 'OperationManagement/closureOverview');
    Route::get('/execution-flow', 'OperationManagement/executionFlow');
    Route::get('/my-tasks', 'OperationManagement/myTasks');
    Route::get('/growth-archive/timeline', 'OperationManagement/growthArchiveTimeline');
    Route::post('/growth-archive/events', 'OperationManagement/createGrowthArchiveEvent');
    Route::post('/growth-archive/:id/annotations', 'OperationManagement/addGrowthArchiveAnnotation');
    Route::post('/growth-archive/:id/milestone', 'OperationManagement/markGrowthArchiveMilestone');
    Route::get('/operating-memories/:id', 'OperationManagement/readOperatingMemory');
    Route::get('/operating-memories', 'OperationManagement/operatingMemories');
    Route::get('/operating-network', 'OperatingIntelligence/operatingNetwork');
    Route::get('/operating-profiles/preview', 'OperatingIntelligence/previewOperatingProfile');
    Route::post('/operating-profiles', 'OperatingIntelligence/saveOperatingProfile');
    Route::post('/operating-sop-replications/:id/execution-intent', 'OperatingIntelligence/createReplicationExecutionIntent');
    Route::get('/operating-sop-replications/:id/reviews', 'OperatingIntelligence/replicationReviews');
    Route::post('/operating-sop-replications/:id/reviews', 'OperatingIntelligence/reviewReplication');
    Route::post('/operating-sops/:id/replications', 'OperatingIntelligence/replicateSop');
    Route::post('/operating-sops/:id/validate', 'OperatingIntelligence/validateSop');
    Route::get('/operating-sop-replications/:id', 'OperatingIntelligence/readReplication');
    Route::get('/operating-sops/:id', 'OperatingIntelligence/readSop');
    Route::get('/operating-sops', 'OperatingIntelligence/sops');
    Route::post('/operating-sops', 'OperatingIntelligence/createSop');
    Route::post('/execution-intents/:id/intervention', 'OperationManagement/saveExecutionIntentIntervention');
    Route::get('/execution-intents/:id', 'OperationManagement/readExecutionIntent');
    Route::get('/execution-tasks/:id', 'OperationManagement/readExecutionTask');
    Route::get('/execution-intents', 'OperationManagement/executionIntents');
    Route::post('/execution-intents', 'OperationManagement/createExecutionIntent');
    Route::post('/actions/:id/finish', 'OperationManagement/finishAction');
    Route::post('/actions', 'OperationManagement/actions');
    Route::get('/action-tracking', 'OperationManagement/actionTracking');
})->middleware(\app\middleware\Auth::class);

// ==================== 开业管理 API ====================
Route::group('api/opening', function () {
    Route::get('/projects/:id/overview', 'Opening/overview');
    Route::post('/projects/:id/generate-tasks', 'Opening/generateTasks');
    Route::get('/projects/:id/tasks', 'Opening/tasks');
    Route::put('/projects/:id', 'Opening/updateProject');
    Route::delete('/projects/:id', 'Opening/archiveProject');
    Route::post('/projects/:id/execution-intent', 'Opening/createExecutionIntent');
    Route::put('/tasks/:id', 'Opening/updateTask');
    Route::post('/projects/:id/recalculate', 'Opening/recalculate');
    Route::post('/projects', 'Opening/createProject');
    Route::get('/projects', 'Opening/projects');
})->middleware(\app\middleware\Auth::class);

// ==================== 扩张管理 API ====================
Route::group('api/expansion', function () {
    Route::post('/market-evaluation', 'Expansion/marketEvaluation');
    Route::post('/benchmark-model', 'Expansion/benchmarkModel');
    Route::post('/collaboration-efficiency', 'Expansion/collaborationEfficiency');
    Route::post('/records/:id/execution-intent', 'Expansion/createExecutionIntent');
    Route::delete('/records/market-evaluation', 'Expansion/clearMarketEvaluation');
    Route::delete('/records/:id', 'Expansion/archive');
    Route::delete('/records', 'Expansion/clearRecords');
    Route::get('/records/:id', 'Expansion/detail');
    Route::get('/records', 'Expansion/records');
})->middleware(\app\middleware\Auth::class);

// ==================== 转让管理 API ====================
Route::group('api/transfer', function () {
    Route::get('/source', 'TransferDecision/source');
    Route::post('/pricing', 'TransferDecision/pricing');
    Route::post('/timing', 'TransferDecision/timing');
    Route::post('/dashboard', 'TransferDecision/dashboard');
    Route::post('/records/:id/execution-intent', 'TransferDecision/createExecutionIntent');
    Route::delete('/records/:id', 'TransferDecision/archive');
    Route::get('/records/:id', 'TransferDecision/detail');
    Route::get('/records', 'TransferDecision/records');
})->middleware(\app\middleware\Auth::class);
