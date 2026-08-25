import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (file) => readFileSync(file, 'utf8');
const sourcePage = read('resources/frontend/templates/fragments/15a-page-ops-source.html');
const analysisPage = read('resources/frontend/templates/fragments/15b-page-ops-analysis.html');
const insightPage = read('resources/frontend/templates/fragments/15c-page-ops-insight.html');
const trackPage = read('resources/frontend/templates/fragments/17-page-ops-track.html');
const onlineDataPage = read('resources/frontend/templates/fragments/35-page-online-data.html');
const researchPage = read('resources/frontend/templates/fragments/19-page-revenue-research-center.html');
const optimizerPage = read('resources/frontend/templates/fragments/19a-page-operation-optimizer.html');
const appMain = read('public/app-main.js');
const operationStatic = read('public/operation-static.js');
const routes = read('route/app.php');
const manifest = JSON.parse(read('resources/frontend/templates/manifest.json'));
const templateSource = read('scripts/lib/frontend_template_source.mjs');

test('three operation menu targets have source templates with explicit loading error and empty states', () => {
  for (const [page, pageKey, loaderKey] of [
    [sourcePage, 'ops-source', 'fullData'],
    [analysisPage, 'ops-analysis', 'rootCause'],
    [insightPage, 'ops-insight', 'alerts'],
  ]) {
    assert.match(page, new RegExp(`currentPage === '${pageKey}'`));
    assert.match(page, new RegExp(`operationLoading\\.${loaderKey}`));
    assert.match(page, new RegExp(`operationError\\.${loaderKey}`));
    assert.match(page, new RegExp(`data-testid="${pageKey}-empty"`));
  }

  assert.match(sourcePage, /loadOperationFullData/);
  assert.match(sourcePage, /operationFullData\.ota\?\.data_status === 'ok'/);
  assert.match(sourcePage, /不能据此判断渠道表现/);
  assert.match(analysisPage, /analyzeOperationRootCause/);
  assert.match(analysisPage, /operationRootCause\.root_causes/);
  assert.match(insightPage, /loadOperationAlerts/);
  assert.match(insightPage, /markOperationAlertsRead/);
  assert.match(insightPage, /标记已读只更新处理状态，不代表问题已经解决/);
});

test('operation fragments are registered in both manifest and source definitions', () => {
  const fragments = new Map(manifest.fragments.map((fragment) => [fragment.id, fragment]));
  for (const [id, path] of [
    ['page-ops-source', 'fragments/15a-page-ops-source.html'],
    ['page-ops-analysis', 'fragments/15b-page-ops-analysis.html'],
    ['page-ops-insight', 'fragments/15c-page-ops-insight.html'],
  ]) {
    assert.equal(fragments.get(id)?.path, path);
    assert.ok(templateSource.includes(`id: '${id}', domain: 'operations', path: '${path}'`));
  }
});

test('operation optimizer surfaces the persisted task lifecycle and truthful next action', () => {
  assert.match(optimizerPage, /operationOptimizerLoopText/);
  assert.match(optimizerPage, /operationOptimizerModules/);
  assert.match(optimizerPage, /operationOptimizerActionText\(row\.recommendation\)/);
  assert.match(optimizerPage, /row\.latest_date \|\| '日期未取得'/);
  assert.match(optimizerPage, /operationOptimizerStatusText\(row\.quality_status\)/);
  assert.doesNotMatch(optimizerPage, /形成草稿任务|草稿 #/);
});

test('operation optimizer creates a scoped pending task then opens execution tracking', () => {
  assert.match(routes, /Route::post\('\/operation-optimizer\/execution-intent', 'OtaStandard\/createOperationOptimizerExecutionIntent'\)/);
  const start = appMain.indexOf('const createOperationOptimizerTask = async');
  const end = appMain.indexOf('const revenueResearchStaticScript', start);
  assert.ok(start > 0 && end > start, 'operation optimizer bridge function must be present');
  const bridge = appMain.slice(start, end);

  assert.match(bridge, /request\('\/ota-standard\/operation-optimizer\/execution-intent'/);
  assert.match(bridge, /recommendation_id: actionId/);
  assert.match(bridge, /String\(readback\.source_module \|\| ''\) !== 'operation_optimizer'/);
  assert.match(bridge, /\['pending_approval', 'approved', 'rejected'\]/);
  assert.match(bridge, /await openOperationOptimizerExecution/);
  assert.doesNotMatch(bridge, /\/approve|\/execute|price-update|inventory-update/i);
});

test('revenue research execution bridge is single-hotel and ready-only', () => {
  assert.match(appMain, /hotelScope\.mode !== 'single_hotel'/);
  assert.match(appMain, /readiness\.stage !== 'research_ready_for_execution'/);
  assert.match(appMain, /readiness\.execution_ready !== true/);
  assert.match(appMain, /result\.status !== 'done'/);
  assert.match(appMain, /artifact\.status !== 'available'/);
  assert.match(appMain, /服务端研究凭证未完成持久化与回读/);
  assert.match(researchPage, /revenueResearchCanCreateExecutionIntent/);
  assert.match(researchPage, /createRevenueResearchExecutionIntent/);
  assert.match(researchPage, /不自动审批、不自动执行、不写 OTA 房价、库存或活动/);
});

test('revenue research execution bridge creates only an intent then opens ops-track', () => {
  assert.match(routes, /Route::post\('\/execution-intent', 'RevenueResearch\/createExecutionIntent'\)/);
  const start = appMain.indexOf('const createRevenueResearchExecutionIntent = async');
  const end = appMain.indexOf('const openRevenueResearchModule = async', start);
  assert.ok(start > 0 && end > start, 'execution bridge function must be present');
  const bridge = appMain.slice(start, end);

  assert.match(bridge, /apiRequest\('\/revenue-research\/execution-intent'/);
  assert.match(bridge, /research_artifact_id: research\.execution_artifact\.id/);
  assert.doesNotMatch(bridge, /\bresearch,\s*\n/);
  assert.match(bridge, /executionIntent: intent/);
  assert.match(bridge, /openRevenueResearchExecutionIntent\(product\)/);
  assert.doesNotMatch(bridge, /\/approve|\/execute|price-update|inventory-update/i);

  const openStart = appMain.indexOf('const openRevenueResearchExecutionIntent = async');
  const openEnd = appMain.indexOf('const createRevenueResearchExecutionIntent = async', openStart);
  const openBridge = appMain.slice(openStart, openEnd);
  assert.match(openBridge, /currentPage\.value = 'ops-track'/);
  assert.match(openBridge, /await loadOperationActions\(\)/);
});

test('AI daily report keeps execution tracking on the selected hotel', () => {
  const start = appMain.indexOf('const loadAiDailyReport = async');
  const end = appMain.indexOf('const loadOperationActions = async', start);
  const bridge = appMain.slice(start, end);
  assert.match(bridge, /operationFilters\.value\.hotel_id = String\(hotelId\)/);
  assert.match(bridge, /reportHotelId/);
  assert.match(bridge, /operationFilters\.value\.hotel_id = reportHotelId/);
});

test('non-price execution evidence can be saved without fabricating revenue or ROI', () => {
  const start = appMain.indexOf('const recordOperationExecutionEvidence = async');
  const end = appMain.indexOf('const recordOperationRoiEvidence = async', start);
  const evidenceFlow = appMain.slice(start, end);
  assert.match(evidenceFlow, /currentPage\.value !== 'ops-track'/);
  assert.match(evidenceFlow, /currentPage\.value = 'ops-track'/);
  assert.match(evidenceFlow, /operationEvidenceModalOpen\.value = true/);
  assert.match(evidenceFlow, /evidence_type: 'manual_operation_execution'/);
  assert.match(evidenceFlow, /executionStatus === 'failed' && !failureReason/);
  assert.match(evidenceFlow, /platform_receipt_id: platformReceipt/);
  assert.match(evidenceFlow, /formal_record_ref: formalRecordRef/);
  assert.match(evidenceFlow, /screenshot_ref: receiptPath/);
  assert.match(evidenceFlow, /executed_at: executedAt/);
  assert.match(evidenceFlow, /effect_status: executionStatus === 'executed' \? 'pending_observation' : 'execution_failed'/);
  assert.match(evidenceFlow, /evidence_boundary: 'local_manual_evidence_no_ota_write'/);
  assert.match(evidenceFlow, /businessContext: \{ hotelId: executionHotelId \}/);
  assert.match(evidenceFlow, /readOperationExecutionTask\(responseTaskId, executionHotelId\)/);
  assert.match(evidenceFlow, /不自动生成收入或ROI/);
  assert.match(trackPage, /data-testid="operation-evidence-modal"/);
  assert.match(trackPage, /保存后标记为“已执行、效果待观察”，不会自动生成收入或 ROI/);
  assert.match(trackPage, /<option value="executed">已执行，等待效果观察<\/option>/);
  assert.match(trackPage, /data-testid="operation-execution-failure-reason-field"/);
  assert.match(trackPage, /operationEvidenceForm\.platform_receipt/);
  assert.match(trackPage, /operationEvidenceForm\.formal_record_ref/);
  assert.match(trackPage, /operationEvidenceForm\.receipt_path/);
  assert.match(trackPage, /缺证不生成收入或 ROI/);
  assert.match(trackPage, /submitOperationExecutionEvidence/);
});

test('revenue node check is independent from completed-action evidence and reads back exact identity', () => {
  assert.doesNotMatch(trackPage, /<option value="3">节点口径<\/option>/);
  assert.doesNotMatch(trackPage, /\['1', '3'\]\.includes\(operationEvidenceForm\.mode\)/);
  assert.match(trackPage, /data-testid="operation-node-check-action"/);
  assert.match(trackPage, /recordOperationRevenueNodeCheck\(item\)/);
  assert.match(trackPage, /更新节点.*节点检查/);
  assert.match(operationStatic, /PMS \+ OTA 交叉核对/);
  const start = appMain.indexOf('const recordOperationRevenueNodeCheck = async');
  const end = appMain.indexOf('const recordOperationExecutionEvidence = async', start);
  const nodeFlow = appMain.slice(start, end);
  assert.match(nodeFlow, /evidence_type: 'revenue_node_check'/);
  assert.match(nodeFlow, /`\/operation\/execution-tasks\/\$\{taskId\}\/evidence`/);
  assert.match(nodeFlow, /system_hotel_id: executionHotelId/);
  assert.match(nodeFlow, /business_date: businessDate/);
  assert.match(nodeFlow, /operator_recorded_scope_not_pms_or_ota_verified/);
  assert.match(nodeFlow, /readOperationExecutionTask\(taskId, executionHotelId\)/);
  const persistedFieldListMatch = nodeFlow.match(/const revenueNodeV2PersistedStringFields = \[([\s\S]*?)\];/);
  assert.ok(persistedFieldListMatch, 'missing explicit revenue node v2 persisted field list');
  const persistedFields = [...persistedFieldListMatch[1].matchAll(/'([^']+)'/g)].map(match => match[1]);
  assert.deepEqual(persistedFields, [
    'business_date',
    'recorded_at',
    'operating_period',
    'special_event',
    'source_scope',
    'room_status_alignment',
    'data_quality_status',
    'metric_definition',
    'comparison_basis',
    'metric_snapshot',
    'progress_status',
    'judgment_basis',
    'primary_risk',
    'success_criteria',
    'stop_condition',
  ]);
  const expectedNode = Object.fromEntries(persistedFields.map(field => [field, 'expected']));
  const truncatedReadback = { ...expectedNode, metric_snapshot: '' };
  assert.equal(persistedFields.some(field => String(truncatedReadback[field] ?? '') !== String(expectedNode[field] ?? '')), true);
  assert.match(nodeFlow, /String\(persistedNode\[field\] \?\? ''\) !== String\(nodeRecord\[field\] \?\? ''\)/);
  assert.match(nodeFlow, /节点检查未按完整口径精确回读/);
  assert.match(appMain, /node_record: nodeRecord/);
  assert.match(appMain, /const nodeText = \(item\) => operationExecutionNodeRecordText\(item\)/);
  assert.match(operationStatic, /节点检查身份不一致，已拒绝回填/);
});

test('operation execution requests keep the selected hotel identity consistent through readback', () => {
  const loadStart = appMain.indexOf('const loadOperationActions = async');
  const loadEnd = appMain.indexOf('const parseOperationEvidenceNumber', loadStart);
  const loadFlow = appMain.slice(loadStart, loadEnd);
  assert.match(loadFlow, /params\.append\('hotel_id', requestHotelId\)/);
  assert.match(loadFlow, /params\.append\('system_hotel_id', requestHotelId\)/);
  const memoryStart = appMain.indexOf('const loadOperatingMemories = async');
  const memoryEnd = appMain.indexOf('const operationMemorySourceIntent', memoryStart);
  const memoryFlow = appMain.slice(memoryStart, memoryEnd);
  assert.match(memoryFlow, /params\.set\('hotel_id', requestedHotelId\)/);
  assert.match(memoryFlow, /params\.set\('system_hotel_id', requestedHotelId\)/);
  assert.match(appMain, /const operationExecutionHotelId = \(item\)/);
  assert.match(appMain, /执行任务与当前酒店身份不一致/);
  assert.match(appMain, /执行任务回读酒店身份不一致/);
});

test('OTA collection capability readback is not discarded when adjacent operation panels fail', () => {
  const loadStart = appMain.indexOf('const loadOperationActions = async');
  const loadEnd = appMain.indexOf('const parseOperationEvidenceNumber', loadStart);
  const loadFlow = appMain.slice(loadStart, loadEnd);

  assert.match(loadFlow, /Promise\.allSettled\(\[/);
  assert.match(loadFlow, /flowResult\.status === 'fulfilled' \? flowResult\.value : null/);
  assert.match(loadFlow, /if \(flowRes\?\.code === 200\) \{[\s\S]*operationExecutionFlow\.value = flowRes\.data/);
  assert.match(loadFlow, /if \(res\?\.code === 200\) \{[\s\S]*operationActions\.value = res\.data\?\.actions/);
  assert.match(loadFlow, /operationClosureOverview\.value = closureRes\?\.code === 200/);

  const capabilityReadback = loadFlow.indexOf('operationExecutionFlow.value = flowRes.data');
  const adjacentFailure = loadFlow.indexOf("if (res?.code !== 200) throw new Error");
  assert.ok(capabilityReadback >= 0 && adjacentFailure > capabilityReadback);
});

test('execution approval uses an in-page two-click confirmation without a native dialog', () => {
  const start = appMain.indexOf('const operationApprovalConfirming =');
  const end = appMain.indexOf('const recordOperationExecutionEvidence = async', start);
  assert.ok(start > 0 && end > start, 'approval confirmation flow must be present');
  const approvalFlow = appMain.slice(start, end);
  assert.match(approvalFlow, /operationApprovalConfirmingIntentId\.value = Number\(item\.id\)/);
  assert.match(approvalFlow, /if \(operationLoading\.value\.actions\) return;/);
  assert.match(approvalFlow, /请再次点击“确认审批”/);
  assert.match(approvalFlow, /confirmation_version = 'operation_action_approval_confirmation\.v1'/);
  assert.match(approvalFlow, /confirmed_intent_id = Number\(item\.id\)/);
  assert.match(approvalFlow, /confirmed_action_digest = actionDigest/);
  assert.match(approvalFlow, /rejectOrCancelOperationApproval/);
  assert.doesNotMatch(approvalFlow, /\bconfirm\s*\(/);
  assert.match(trackPage, /data-testid="operation-approve"/);
  assert.match(trackPage, /:data-confirming="operationApprovalConfirming\(item\)"/);
  assert.match(trackPage, /data-testid="operation-reject"/);
  assert.match(onlineDataPage, /operationApprovalText\(item\)/);
  assert.match(onlineDataPage, /rejectOrCancelOperationApproval\(item\)/);
  assert.match(
    onlineDataPage,
    /:disabled="operationLoading\.actions"\s+@click="approveOperationExecutionIntent\(item, true\)"/,
  );
  assert.match(
    onlineDataPage,
    /:disabled="operationLoading\.actions"\s+@click="rejectOrCancelOperationApproval\(item\)"/,
  );
  assert.match(
    trackPage,
    /:disabled="operationLoading\.actions"\s+@click="approveOperationExecutionIntent\(item, true\)"/,
  );
  assert.match(
    trackPage,
    /:disabled="operationLoading\.actions"\s+@click="rejectOrCancelOperationApproval\(item\)"/,
  );
});

test('verification-only operating questions approve an observation window without inventing a numeric target', () => {
  const start = appMain.indexOf('const operationApprovalConfirming =');
  const end = appMain.indexOf('const recordOperationExecutionEvidence = async', start);
  const approvalFlow = appMain.slice(start, end);
  assert.match(approvalFlow, /const isVerificationOnlyOperatingQuestion = isManagedOperatingQuestion/);
  assert.match(approvalFlow, /expectedEffect\?\.status[\s\S]*verification_target/);
  assert.match(approvalFlow, /expectedEffect\?\.direction[\s\S]*verify/);
  assert.match(approvalFlow, /value: 'observe'/);
  assert.match(approvalFlow, /value: 'observation'/);
  assert.match(approvalFlow, /仅观察变化（不承诺提升）/);
  assert.match(approvalFlow, /\.\.\.\(isVerificationOnlyOperatingQuestion \? \[\] : \[/);
  assert.match(approvalFlow, /targetType === 'absolute' \? \{ target_value: absoluteTarget \} : \{\}/);
  assert.match(approvalFlow, /核验型行动只能按“仅观察变化”口径审批/);
});

test('managed operating questions create only a human-approved pending intent with zero tasks', () => {
  const readinessStart = appMain.indexOf('const operatingQuestionActionIsCurrent =');
  const readinessEnd = appMain.indexOf('const otaDiagnosisLoading =', readinessStart);
  assert.ok(readinessStart > 0 && readinessEnd > readinessStart, 'operating-question readiness gate must exist');
  const readiness = appMain.slice(readinessStart, readinessEnd);
  assert.match(readiness, /operating_question_grounded_ai\.zh-CN\.v4/);
  assert.match(readiness, /operating_question_action_draft\.v2/);
  assert.match(readiness, /ready_for_human_review/);
  assert.match(readiness, /human_confirmation_required === true/);
  assert.doesNotMatch(readiness, /ready_for_ai_review|independent_ai_review_required|human_confirmation_required === false/);

  const start = appMain.indexOf('const createOperatingQuestionActionIntent = async');
  const end = appMain.indexOf('const openOperatingQuestionActionIntent = async', start);
  assert.ok(start > 0 && end > start, 'operating-question pending-intent bridge must exist');
  const bridge = appMain.slice(start, end);
  assert.match(bridge, /human_reviewed_operating_check/);
  assert.match(bridge, /pending_approval/);
  assert.match(bridge, /tasks\.length !== 0/);
  assert.doesNotMatch(bridge, /ai_independent_review|intentStatus === 'approved'|AI 独立评审/);
});

test('managed operating actions expose the versioned card lifecycle start cancel and review readback', () => {
  assert.match(routes, /Route::post\('\/execution-intents\/:id\/cancel', 'OperationManagement\/cancelExecutionIntent'\)/);
  assert.match(trackPage, /data-testid="operation-action-card"/);
  assert.match(trackPage, /action_card\.fact_refs/);
  assert.match(trackPage, /action_card\.metric_contract\?\.unit/);
  assert.match(trackPage, /data-testid="operation-start-task"/);
  assert.match(trackPage, /startOperationExecutionTask\(item\)/);
  assert.match(trackPage, /data-testid="operation-cancel-action"/);
  assert.match(trackPage, /cancelOperationExecution\(item\)/);
  assert.match(trackPage, /latest_review\.non_attribution_reasons/);

  const start = appMain.indexOf('const startOperationExecutionTask = async');
  const cancel = appMain.indexOf('const cancelOperationExecution = async', start);
  const end = appMain.indexOf('const recordOperationRevenueNodeCheck = async', cancel);
  assert.ok(start > 0 && cancel > start && end > cancel, 'managed lifecycle handlers must be present');
  const startFlow = appMain.slice(start, cancel);
  assert.match(startFlow, /status: 'executing'/);
  assert.match(startFlow, /readOperationExecutionTask\(taskId, executionHotelId\)/);
  assert.match(startFlow, /lifecycle\?\.status \|\| ''\) !== 'in_progress'/);
  assert.doesNotMatch(startFlow, /price-update|inventory-update|automatic_ota_write/i);

  const cancelFlow = appMain.slice(cancel, end);
  assert.match(cancelFlow, /\/operation\/execution-intents\/\$\{Number\(item\.id\)\}\/cancel/);
  assert.match(cancelFlow, /readOperationExecutionIntent\(Number\(item\.id\)\)/);
  assert.match(cancelFlow, /lifecycle\?\.status \|\| ''\) !== 'cancelled'/);
  assert.match(cancelFlow, /历史版本仍完整保留/);

  const reviewStart = appMain.indexOf('const submitOperationExecutionReview = async');
  const reviewEnd = appMain.indexOf('const finishOperationAction = async', reviewStart);
  const reviewFlow = appMain.slice(reviewStart, reviewEnd);
  assert.match(reviewFlow, /\['ota_diagnosis_saved', 'operating_question'\]/);
  assert.match(reviewFlow, /latest_review/);
  assert.match(reviewFlow, /\['sufficient', 'insufficient', 'mismatched'\]/);
  assert.match(reviewFlow, /\['continue', 'adjust', 'stop'\]/);
  assert.match(reviewFlow, /managedReview\.causality_claimed !== false/);
});

test('verification-only operating questions approve an observation window without inventing a numeric target', () => {
  const start = appMain.indexOf('const operationApprovalConfirming =');
  const end = appMain.indexOf('const recordOperationExecutionEvidence = async', start);
  const approvalFlow = appMain.slice(start, end);
  assert.match(approvalFlow, /const isVerificationOnlyOperatingQuestion = isManagedRevenueAction/);
  assert.match(approvalFlow, /expectedEffect\?\.status[\s\S]*verification_target/);
  assert.match(approvalFlow, /expectedEffect\?\.direction[\s\S]*verify/);
  assert.match(approvalFlow, /value: 'observe'/);
  assert.match(approvalFlow, /value: 'observation'/);
  assert.match(approvalFlow, /仅观察变化（不承诺提升）/);
  assert.match(approvalFlow, /\.\.\.\(isVerificationOnlyOperatingQuestion \? \[\] : \[/);
  assert.match(approvalFlow, /targetType === 'absolute' \? \{ target_value: absoluteTarget \} : \{\}/);
  assert.match(approvalFlow, /核验型行动只能按“仅观察变化”口径审批/);
});

test('managed operating questions remain human-confirmed and never auto-approve or execute', () => {
  assert.match(operationStatic, /const operationUsesIndependentAiReview = \(item\)/);
  assert.match(operationStatic, /action_card\?\.approval\?\.mode/);
  assert.match(operationStatic, /=== 'ai_independent_review'/);
  assert.match(operationStatic, /!operationUsesIndependentAiReview\(item\)/);

  const start = appMain.indexOf('const createOperatingQuestionActionIntent = async');
  const end = appMain.indexOf('const openOperatingQuestionActionIntent = async', start);
  const bridge = appMain.slice(start, end);
  assert.match(bridge, /intentStatus !== 'pending_approval' \|\| tasks\.length !== 0/);
  assert.match(bridge, /新运营行动必须保持待人工审批且不得提前创建任务/);
  assert.match(bridge, /行动已保存为待人工审批；尚未创建执行任务，也未写 OTA/);
  assert.doesNotMatch(bridge, /AI 独立评审已通过|AI独立评审与运营任务回读不一致/);
  assert.doesNotMatch(bridge, /\/approve|price-update|inventory-update|external-message/i);
});

test('managed operating actions expose the versioned card lifecycle start cancel and review readback', () => {
  assert.match(routes, /Route::post\('\/execution-intents\/:id\/cancel', 'OperationManagement\/cancelExecutionIntent'\)/);
  assert.match(trackPage, /data-testid="operation-action-card"/);
  assert.match(trackPage, /action_card\.fact_refs/);
  assert.match(trackPage, /action_card\.metric_contract\?\.unit/);
  assert.match(trackPage, /data-testid="operation-start-task"/);
  assert.match(trackPage, /startOperationExecutionTask\(item\)/);
  assert.match(trackPage, /data-testid="operation-cancel-action"/);
  assert.match(trackPage, /cancelOperationExecution\(item\)/);
  assert.match(trackPage, /latest_review\.non_attribution_reasons/);

  const start = appMain.indexOf('const startOperationExecutionTask = async');
  const cancel = appMain.indexOf('const cancelOperationExecution = async', start);
  const end = appMain.indexOf('const recordOperationRevenueNodeCheck = async', cancel);
  assert.ok(start > 0 && cancel > start && end > cancel, 'managed lifecycle handlers must be present');
  const startFlow = appMain.slice(start, cancel);
  assert.match(startFlow, /status: 'executing'/);
  assert.match(startFlow, /readOperationExecutionTask\(taskId, executionHotelId\)/);
  assert.match(startFlow, /lifecycle\?\.status \|\| ''\) !== 'in_progress'/);
  assert.doesNotMatch(startFlow, /price-update|inventory-update|automatic_ota_write/i);

  const cancelFlow = appMain.slice(cancel, end);
  assert.match(cancelFlow, /\/operation\/execution-intents\/\$\{Number\(item\.id\)\}\/cancel/);
  assert.match(cancelFlow, /readOperationExecutionIntent\(Number\(item\.id\)\)/);
  assert.match(cancelFlow, /lifecycle\?\.status \|\| ''\) !== 'cancelled'/);
  assert.match(cancelFlow, /历史版本仍完整保留/);

  const reviewStart = appMain.indexOf('const submitOperationExecutionReview = async');
  const reviewEnd = appMain.indexOf('const finishOperationAction = async', reviewStart);
  const reviewFlow = appMain.slice(reviewStart, reviewEnd);
  assert.match(reviewFlow, /\['ota_diagnosis_saved', 'operating_question', 'revenue_cockpit_action'\]/);
  assert.match(reviewFlow, /latest_review/);
  assert.match(reviewFlow, /\['sufficient', 'insufficient', 'mismatched'\]/);
  assert.match(reviewFlow, /\['continue', 'adjust', 'stop'\]/);
  assert.match(reviewFlow, /managedReview\.causality_claimed !== false/);
});

test('effect review uses an in-page form and preserves the observing state when evidence is pending', () => {
  assert.match(trackPage, /data-testid="operation-review-modal"/);
  assert.match(trackPage, /<option value="observing">继续观察<\/option>/);
  assert.match(trackPage, /submitOperationExecutionReview/);

  const start = appMain.indexOf('const reviewOperationExecutionTask = async');
  const end = appMain.indexOf('const finishOperationAction = async', start);
  const reviewFlow = appMain.slice(start, end);
  assert.match(reviewFlow, /operationReviewModalOpen\.value = true/);
  assert.match(reviewFlow, /result_summary: resultSummary \|\| '继续观察，等待次日收益或ROI证据'/);
  assert.match(reviewFlow, /\/reconcile-review/);
  assert.match(reviewFlow, /source_verified_metric_readback/);
  assert.match(reviewFlow, /evidence_truth\?\.source_verified !== true/);
  assert.match(reviewFlow, /effect_review_summary\?\.verified_count/);
  assert.match(reviewFlow, /persistence_status \|\| ''\) !== 'readback_verified'/);
  assert.doesNotMatch(reviewFlow, /readback_evidence:|operator_attested/);
  assert.match(trackPage, /data-testid="operation-review-readback-gate"/);
  assert.match(trackPage, /同酒店、同平台、同指标来源事实/);
  assert.match(trackPage, /不会把人工填值写成来源事实/);
  assert.match(trackPage, /不会自动修改 OTA/);
});
