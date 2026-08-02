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
  const start = operationStatic.indexOf('const loadAiDailyReport = async');
  const end = operationStatic.indexOf('const loadOperationActions = async', start);
  const bridge = operationStatic.slice(start, end);
  assert.match(bridge, /operationFilters\.value\.hotel_id = String\(hotelId\)/);
  assert.match(bridge, /reportHotelId/);
  assert.match(bridge, /operationFilters\.value\.hotel_id = reportHotelId/);
});

test('non-price execution evidence can be saved without fabricating revenue or ROI', () => {
  const start = operationStatic.indexOf('const recordOperationExecutionEvidence = async');
  const end = operationStatic.indexOf('const recordOperationRoiEvidence = async', start);
  const evidenceFlow = operationStatic.slice(start, end);
  assert.match(evidenceFlow, /currentPage\.value !== 'ops-track'/);
  assert.match(evidenceFlow, /currentPage\.value = 'ops-track'/);
  assert.match(evidenceFlow, /operationEvidenceModalOpen\.value = true/);
  assert.match(evidenceFlow, /evidence_type: 'manual_operation_execution'/);
  assert.match(evidenceFlow, /effect_status: 'pending_observation'/);
  assert.match(evidenceFlow, /evidence_boundary: 'local_manual_evidence_no_ota_write'/);
  assert.match(evidenceFlow, /businessContext: \{ hotelId: executionHotelId \}/);
  assert.match(evidenceFlow, /readOperationExecutionTask\(responseTaskId, executionHotelId\)/);
  assert.match(evidenceFlow, /不自动生成收入或ROI/);
  assert.match(trackPage, /data-testid="operation-evidence-modal"/);
  assert.match(trackPage, /已完成运营动作（效果待观察）/);
  assert.match(trackPage, /未观察到的收入、成本和 ROI 保持为空/);
  assert.match(trackPage, /submitOperationExecutionEvidence/);
});

test('revenue node check is independent from completed-action evidence and reads back exact identity', () => {
  assert.doesNotMatch(trackPage, /<option value="3">节点口径<\/option>/);
  assert.doesNotMatch(trackPage, /\['1', '3'\]\.includes\(operationEvidenceForm\.mode\)/);
  assert.match(trackPage, /data-testid="operation-node-check-action"/);
  assert.match(trackPage, /recordOperationRevenueNodeCheck\(item\)/);
  assert.match(trackPage, /更新节点.*节点检查/);
  assert.match(operationStatic, /PMS \+ OTA 交叉核对/);
  const start = operationStatic.indexOf('const recordOperationRevenueNodeCheck = async');
  const end = operationStatic.indexOf('const recordOperationExecutionEvidence = async', start);
  const nodeFlow = operationStatic.slice(start, end);
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
  assert.match(operationStatic, /node_record: nodeRecord/);
  assert.match(appMain, /const nodeText = \(item\) => operationExecutionNodeRecordText\(item\)/);
});

test('operation execution requests keep the selected hotel identity consistent through readback', () => {
  const loadStart = operationStatic.indexOf('const loadOperationActions = async');
  const loadEnd = operationStatic.indexOf('const parseOperationEvidenceNumber', loadStart);
  const loadFlow = operationStatic.slice(loadStart, loadEnd);
  assert.match(loadFlow, /params\.append\('hotel_id', requestHotelId\)/);
  assert.match(loadFlow, /params\.append\('system_hotel_id', requestHotelId\)/);
  const memoryStart = operationStatic.indexOf('const loadOperatingMemories = async');
  const memoryEnd = operationStatic.indexOf('const saveOperationExecutionMemory = async', memoryStart);
  const memoryFlow = operationStatic.slice(memoryStart, memoryEnd);
  assert.match(memoryFlow, /params\.set\('hotel_id', requestedHotelId\)/);
  assert.match(memoryFlow, /params\.set\('system_hotel_id', requestedHotelId\)/);
  assert.match(operationStatic, /const operationExecutionHotelId = \(item\)/);
  assert.match(operationStatic, /执行任务与当前酒店身份不一致/);
  assert.match(operationStatic, /执行任务回读酒店身份不一致/);
});

test('execution approval uses an in-page two-click confirmation without a native dialog', () => {
  const start = operationStatic.indexOf('const operationApprovalConfirming =');
  const end = operationStatic.indexOf('const recordOperationExecutionEvidence = async', start);
  assert.ok(start > 0 && end > start, 'approval confirmation flow must be present');
  const approvalFlow = operationStatic.slice(start, end);
  assert.match(approvalFlow, /operationApprovalConfirmingIntentId\.value = Number\(item\.id\)/);
  assert.match(approvalFlow, /请再次点击“确认审批”/);
  assert.match(approvalFlow, /rejectOrCancelOperationApproval/);
  assert.doesNotMatch(approvalFlow, /\bconfirm\s*\(/);
  assert.match(onlineDataPage, /operationApprovalText\(item\)/);
  assert.match(onlineDataPage, /rejectOrCancelOperationApproval\(item\)/);
});

test('effect review uses an in-page form and preserves the observing state when evidence is pending', () => {
  assert.match(trackPage, /data-testid="operation-review-modal"/);
  assert.match(trackPage, /没有次日同口径数据时请选择“继续观察”/);
  assert.match(trackPage, /<option value="observing">继续观察<\/option>/);
  assert.match(trackPage, /submitOperationExecutionReview/);

  const start = operationStatic.indexOf('const reviewOperationExecutionTask = async');
  const end = operationStatic.indexOf('const finishOperationAction = async', start);
  const reviewFlow = operationStatic.slice(start, end);
  assert.match(reviewFlow, /operationReviewModalOpen\.value = true/);
  assert.match(reviewFlow, /result_summary: resultSummary \|\| '继续观察，等待次日收益或ROI证据'/);
  assert.match(reviewFlow, /readback_evidence:/);
  assert.match(reviewFlow, /operator_attested: true/);
  assert.match(reviewFlow, /verification_status: 'operator_attested'/);
  assert.doesNotMatch(reviewFlow, /readback_verified: true/);
  assert.match(reviewFlow, /必须提交人工平台复查声明/);
  assert.match(trackPage, /data-testid="operation-review-readback-gate"/);
  assert.match(trackPage, /不代表 OTA 来源已被服务端验证/);
  assert.match(trackPage, /不会自动向 OTA 写入价格、库存或活动/);
});
