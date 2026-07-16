import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (file) => readFileSync(file, 'utf8');
const sourcePage = read('resources/frontend/templates/fragments/15a-page-ops-source.html');
const analysisPage = read('resources/frontend/templates/fragments/15b-page-ops-analysis.html');
const insightPage = read('resources/frontend/templates/fragments/15c-page-ops-insight.html');
const trackPage = read('resources/frontend/templates/fragments/17-page-ops-track.html');
const researchPage = read('resources/frontend/templates/fragments/19-page-revenue-research-center.html');
const appMain = read('public/app-main.js');
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

test('revenue research execution bridge is single-hotel and ready-only', () => {
  assert.match(appMain, /hotelScope\.mode !== 'single_hotel'/);
  assert.match(appMain, /readiness\.stage !== 'research_ready_for_execution'/);
  assert.match(appMain, /readiness\.execution_ready !== true/);
  assert.match(appMain, /result\.status !== 'done'/);
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
  assert.match(bridge, /research,/);
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
  assert.match(evidenceFlow, /operationEvidenceModalOpen\.value = true/);
  assert.match(evidenceFlow, /evidence_type: 'manual_operation_execution'/);
  assert.match(evidenceFlow, /effect_status: 'pending_observation'/);
  assert.match(evidenceFlow, /evidence_boundary: 'local_manual_evidence_no_ota_write'/);
  assert.match(evidenceFlow, /不自动生成收入或ROI/);
  assert.match(trackPage, /data-testid="operation-evidence-modal"/);
  assert.match(trackPage, /已完成运营动作（效果待观察）/);
  assert.match(trackPage, /未观察到的收入、成本和 ROI 保持为空/);
  assert.match(trackPage, /submitOperationExecutionEvidence/);
});

test('effect review uses an in-page form and preserves the observing state when evidence is pending', () => {
  assert.match(trackPage, /data-testid="operation-review-modal"/);
  assert.match(trackPage, /没有次日同口径数据时请选择“继续观察”/);
  assert.match(trackPage, /<option value="observing">继续观察<\/option>/);
  assert.match(trackPage, /submitOperationExecutionReview/);

  const start = appMain.indexOf('const reviewOperationExecutionTask = async');
  const end = appMain.indexOf('const finishOperationAction = async', start);
  const reviewFlow = appMain.slice(start, end);
  assert.match(reviewFlow, /operationReviewModalOpen\.value = true/);
  assert.match(reviewFlow, /result_summary: resultSummary \|\| '继续观察，等待次日收益或ROI证据'/);
});
