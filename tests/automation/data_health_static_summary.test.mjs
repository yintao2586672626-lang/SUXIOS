import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { readFrontendContractSource } from './helpers/frontend_source.mjs';

const context = { window: {} };
const cookieEndpointConcern = readFileSync('app/controller/concern/CookieEndpointConcern.php', 'utf8');
const onlineDataRequestConcern = readFileSync('app/controller/concern/OnlineDataRequestConcern.php', 'utf8');
const ctripOverviewRequestConcern = readFileSync('app/controller/concern/CtripOverviewRequestConcern.php', 'utf8');
const businessDisplayConcern = readFileSync('app/controller/concern/BusinessDisplayConcern.php', 'utf8');
const routeApp = readFileSync('route/app.php', 'utf8');
const publicEntry = readFrontendContractSource();
const dataHealthStaticSource = readFileSync('public/data-health-static.js', 'utf8');
vm.runInNewContext(dataHealthStaticSource, context, {
  filename: 'public/data-health-static.js',
});

const helpers = context.window.SUXI_DATA_HEALTH_STATIC;

test('truth summaries use concise Chinese and hide raw storage codes by default', () => {
  const missingText = helpers.onlineTruthDetailText({
    status: 'unverified',
    status_label: '未验证',
    source: { table: 'online_daily_data' },
    failure_reason: 'source_rows_missing; source_update_time_missing; hotel_missing; platform_missing; data_date_missing; source_method_or_trace_missing; collected_at_missing',
  });
  assert.match(missingText, /^未验证；OTA入库数据；原因：目标日没有可用数据；门店、平台或日期信息不完整；另有 2 项信息待补$/);
  assert.doesNotMatch(missingText, /source_rows_missing|online_daily_data|状态：|计算：/);
  assert.equal(
    helpers.onlineTruthSummaryText({
      status: 'unverified',
      failure_reason: 'source_rows_missing; hotel_missing; platform_missing; data_date_missing',
    }),
    '未验证：目标日没有可用数据',
  );
  assert.equal(
    helpers.onlineTruthNextActionText({
      status: 'unverified',
      failure_reason: 'source_rows_missing; hotel_missing; platform_missing; data_date_missing',
    }),
    '补齐门店、平台和目标日期',
  );

  const verifiedText = helpers.onlineTruthDetailText({
    status: 'verified',
    status_label: '已验证',
    hotels: [{ system_hotel_id: 80, name: '敦煌漠蓝新' }],
    data_date: '2026-07-23',
    source: { table: 'online_daily_data', methods: ['profile_browser'] },
    persistence: { record_count: 2, stored_count: 2, readback_verified_count: 2 },
  });
  assert.equal(verifiedText, '已验证；敦煌漠蓝新（ID 80）；2026-07-23；OTA入库数据、本机浏览器 Profile；入库已验证');
  assert.equal(helpers.onlineTruthSummaryText({ status: 'verified' }), '已验证，可用于当前指标');
  assert.equal(helpers.onlineTruthNextActionText({ status: 'verified' }), '');
});

test('Ctrip overview does not show verified authorization as pending', () => {
  const authState = helpers.buildCollectionHealthCtripOverviewAuthState([
    { is_usable: true, status: 'ok' },
  ]);
  const cards = helpers.buildCollectionHealthCtripOverviewStatusCards({
    authState,
    catalogAuthText: '待验证',
    sourceRowCount: 27,
  });

  assert.equal(authState.value, '登录态已验证');
  assert.equal(cards[0].value, '登录态已验证');
  assert.equal(cards[0].sub, '凭据可用');
});

test('daily workbench write boundary requires confirmation for patrol and export writes', () => {
  assert.equal(typeof helpers.buildDailyWorkbenchWriteBoundary, 'function');

  const boundary = helpers.buildDailyWorkbenchWriteBoundary();
  assert.match(boundary.summaryText, /运行巡检会写入运行时快照和操作日志/);
  assert.match(boundary.summaryText, /导出会写入导出审计日志/);
  assert.equal(boundary.run.requiresConfirmation, true);
  assert.equal(boundary.run.runtimeSnapshotWritten, true);
  assert.equal(boundary.run.operationLogWritten, true);
  assert.equal(boundary.run.otaCollectionTriggered, false);
  assert.match(boundary.run.confirmText, /runtime/);
  assert.match(boundary.run.confirmText, /操作日志/);
  assert.equal(boundary.export.requiresConfirmation, true);
  assert.equal(boundary.export.runtimeSnapshotWritten, false);
  assert.equal(boundary.export.operationLogWritten, true);
  assert.match(boundary.export.confirmText, /导出审计日志/);
});

test('data health field-gap summary stays read-only and source-aware', () => {
  assert.equal(typeof helpers.summarizeDataHealthFieldGapActions, 'function');

  const rows = [{
    status: 'missing',
    sourceRef: 'missing_field_codes',
  }, {
    status: 'forbidden',
    sourceRef: 'field_asset_summary.forbidden_fields',
  }, {
    status: 'not_returned_visible',
    sourceRef: 'field_asset_summary.not_returned_fields',
  }];

  const summary = helpers.summarizeDataHealthFieldGapActions(rows);
  assert.equal(summary.countText, '3 项缺口');
  assert.match(summary.detailText, /待补 2/);
  assert.match(summary.detailText, /禁止采集 1/);
  assert.match(summary.detailText, /来源 3/);
  assert.match(summary.boundaryText, /未返回字段不按成功处理/);
  assert.equal(summary.hasForbidden, true);
});

test('employee OTA checklist display helpers stay static and priority aware', () => {
  assert.equal(typeof helpers.employeeOtaChecklistPriorityRank, 'function');
  assert.equal(typeof helpers.employeeOtaChecklistCategoryClass, 'function');
  assert.equal(typeof helpers.employeeOtaChecklistCategoryText, 'function');
  assert.equal(typeof helpers.buildEmployeeOtaChecklistHeadline, 'function');

  assert.equal(helpers.employeeOtaChecklistPriorityRank('high'), 0);
  assert.equal(helpers.employeeOtaChecklistPriorityRank('ok'), 3);
  assert.equal(helpers.employeeOtaChecklistPriorityRank('unknown'), 4);
  assert.match(helpers.employeeOtaChecklistCategoryClass('gap'), /amber/);
  assert.match(helpers.employeeOtaChecklistCategoryClass('anomaly'), /red/);
  assert.equal(helpers.employeeOtaChecklistCategoryText('action'), '今日动作');
  assert.equal(helpers.employeeOtaChecklistCategoryText('other'), '待确认');

  const high = helpers.buildEmployeeOtaChecklistHeadline([{ priority: 'medium' }, { priority: 'high' }]);
  assert.equal(high.text, '先处理高优先级');
  assert.match(high.className, /red|amber/);

  const medium = helpers.buildEmployeeOtaChecklistHeadline([{ priority: 'medium' }, { priority: 'low' }]);
  assert.equal(medium.text, '2 项待处理');

  const empty = helpers.buildEmployeeOtaChecklistHeadline([]);
  assert.equal(empty.text, '暂无待处理');
});

test('OTA field gap queue exposes only unclosed source-to-UI evidence gaps', () => {
  assert.equal(typeof helpers.buildOtaFieldGapQueueRows, 'function');
  assert.equal(typeof helpers.summarizeOtaFieldGapQueue, 'function');

  const rows = helpers.buildOtaFieldGapQueueRows({
    sourceDateEvidence: {
      target_date: '2026-07-08',
      platforms: [{
        platform: 'ctrip',
        target_date: '2026-07-08',
        p0_traffic_gate_status: 'requires_p0_verifier',
        p0_traffic_field_fact_status: 'not_loaded',
        p0_field_loop_matrix: [{
          metric_key: 'list_exposure',
          expected_storage_field: 'list_exposure',
          source_path: 'data.flow.0.listExposure',
          status: 'ready',
          ui_status_ready: true,
        }, {
          metric_key: 'detail_exposure',
          expected_storage_field: 'detail_exposure',
          status: 'missing',
          ui_status_ready: false,
        }],
      }],
    },
  });

  assert.equal(rows.length, 1);
  assert.equal(rows[0].platform, 'ctrip');
  assert.equal(rows[0].targetDate, '2026-07-08');
  assert.equal(rows[0].metricKey, 'detail_exposure');
  assert.equal(rows[0].storageField, 'detail_exposure');
  assert.equal(rows[0].sourcePath, 'source_path_missing');
  assert.equal(rows[0].uiStatus, 'not_loaded');
  assert.equal(rows[0].priority, 'high');
  assert.match(rows[0].nextAction, /source_path/);

  const summary = helpers.summarizeOtaFieldGapQueue(rows);
  assert.equal(summary.status, 'high');
  assert.equal(summary.openCount, 1);
  assert.equal(summary.sourcePathMissing, 1);
  assert.equal(summary.storageMissing, 0);
  assert.equal(summary.uiOpen, 1);
  assert.match(summary.boundaryText, /OTA/);
  assert.match(summary.boundaryText, /verifier/);
});

test('OTA field gap queue falls back to missing field rows without fabricating closure', () => {
  assert.equal(helpers.otaFieldGapQueueStatusText('missing_target_date_traffic_rows'), '目标日 traffic 未入库');

  const rows = helpers.buildOtaFieldGapQueueRows({
    sourceDateEvidence: {
      target_date: '2026-07-08',
      platforms: [],
    },
    missingFieldRows: [{
      platform: 'meituan',
      code: 'order_submit_num',
      storageField: 'order_submit_num',
      sourceRef: 'missing_field_summary',
      nextActionText: 'collect target-date Meituan traffic field facts',
    }],
  });

  assert.equal(rows.length, 1);
  assert.equal(rows[0].platform, 'meituan');
  assert.equal(rows[0].metricKey, 'order_submit_num');
  assert.equal(rows[0].verifierStatus, 'requires_p0_verifier');
  assert.equal(rows[0].sourcePath, 'source_path_missing');
  assert.equal(rows[0].priority, 'high');
});

test('OTA field gap queue treats missing target-date traffic gate as high risk', () => {
  const rows = helpers.buildOtaFieldGapQueueRows({
    sourceDateEvidence: {
      target_date: '2026-07-08',
      platforms: [{
        platform: 'ctrip',
        target_date: '2026-07-08',
        p0_traffic_gate_status: 'missing_target_date_traffic_rows',
        p0_missing_metric_keys: ['traffic_index'],
        p0_required_storage_fields: ['online_daily_data.traffic_index'],
      }],
    },
  });

  assert.equal(rows.length, 1);
  assert.equal(rows[0].status, 'missing_target_date_traffic_rows');
  assert.equal(rows[0].statusText, '目标日 traffic 未入库');
  assert.equal(rows[0].priority, 'high');
});

test('manual one-click fetch task builders stay static and skip only proven stored rows', () => {
  assert.match(routeApp, /api\/online-data[\s\S]*\/manual-fetch-evidence/);
  assert.match(publicEntry, /\/online-data\/manual-fetch-evidence\?\$\{params\.toString\(\)\}/);
  assert.equal(typeof helpers.findManualOneClickFetchExistingStoredRow, 'function');
  assert.equal(typeof helpers.buildManualOneClickFetchTasks, 'function');
  assert.equal(typeof helpers.buildManualOneClickFetchBaseRow, 'function');
  assert.equal(typeof helpers.buildManualOneClickFetchCoverageRows, 'function');

  const storedRows = [
    {
      hotelId: '58',
      sourceRows: 130,
      fieldHasGap: false,
      acquisitionStatusKind: 'ready',
      platformRows: [{
        platform: 'ctrip',
        target_date_rows: 130,
        target_date_competition_hotel_count: 26,
        target_date_competition_self_count: 1,
        target_date_competition_competitor_count: 25,
      }],
    },
    {
      hotelId: '64',
      sourceRows: 102,
      fieldHasGap: false,
      acquisitionStatusKind: 'ready',
      platformRows: [{ platform: 'ctrip', target_date_rows: 102, target_date_competition_hotel_count: 0 }],
    },
  ];

  assert.equal(helpers.findManualOneClickFetchExistingStoredRow({ rows: storedRows, hotelId: 58, platform: 'ctrip' }), storedRows[0]);
  assert.equal(helpers.findManualOneClickFetchExistingStoredRow({ rows: storedRows, hotelId: 58, platform: 'meituan' }), null);
  assert.equal(helpers.findManualOneClickFetchExistingStoredRow({ rows: storedRows, hotelId: 64 }), null);
  assert.equal(helpers.findManualOneClickFetchExistingStoredRow({ rows: storedRows, hotelId: 64, platform: 'ctrip' }), null);
  assert.deepEqual(
    JSON.parse(JSON.stringify(helpers.manualOneClickFetchStoredCompetitionSummary(storedRows[0], 'ctrip'))),
    { total: 26, self: 1, competitors: 25, ready: true },
  );

  const ctripTasks = helpers.buildManualOneClickFetchTasks({
    platform: 'ctrip',
    ctripHotels: [{ id: 58, name: 'Ctrip A' }],
    meituanHotels: [{ id: 7, name: 'Meituan A' }],
    storedRows,
    hasCtripQunarVisitorZeroFailure: () => false,
  });
  assert.equal(ctripTasks.length, 0);

  const allTasks = helpers.buildManualOneClickFetchTasks({
    platform: 'all',
    ctripHotels: [{ id: 58, name: 'Ctrip A' }],
    meituanHotels: [{ id: 7, hotel_name: 'Meituan A' }],
    storedRows,
    hasCtripQunarVisitorZeroFailure: id => String(id) === '58',
  });
  assert.equal(allTasks.length, 2);
  assert.equal(allTasks[0].existingStoredRow, null);
  assert.equal(allTasks[1].platform, 'meituan');

  const baseRow = helpers.buildManualOneClickFetchBaseRow({
    platform: 'meituan',
    hotel: { id: 7, hotel_name: 'Meituan A' },
    runId: 'run-1',
    savedCount: '2',
    existingCount: '3',
    nowText: '2026-07-08 17:00:00',
    operatorName: '管理员',
  });
  assert.equal(baseRow.key, 'run-1:meituan:7');
  assert.equal(baseRow.hotelName, 'Meituan A');
  assert.equal(baseRow.savedCount, 2);
  assert.equal(baseRow.existingCount, 3);
  assert.equal(baseRow.timeText, '2026-07-08 17:00:00');
  assert.equal(baseRow.fetchedBy, '管理员');
  assert.equal(baseRow.handledBy, '管理员');

  const fallbackRow = helpers.buildManualOneClickFetchBaseRow({
    platform: 'ctrip',
    hotel: { id: 88 },
    runId: 'run-2',
    getHotelNameById: id => id === '88' ? 'Fallback Hotel' : '',
    nowText: 'fixed',
  });
  assert.equal(fallbackRow.key, 'run-2:ctrip:88');
  assert.equal(fallbackRow.hotelName, 'Fallback Hotel');

  const coverageRows = helpers.buildManualOneClickFetchCoverageRows({
    ctripHotels: [{ id: 58, name: '携程已入库' }, { id: 64, name: '携程待处理' }],
    meituanHotels: [{ id: 58, name: '美团待补采' }],
    resultRows: [
      { key: 'ctrip:58', platform: 'ctrip', hotelId: '58', hotelName: '携程已入库', status: 'success', statusText: '已入库', message: '旧批次成功', timeText: '2026/7/12 10:00:00' },
      { key: 'ctrip:64', platform: 'ctrip', hotelId: '64', hotelName: '携程待处理', status: 'failed', statusText: '失败', message: 'Cookie 已失效', timeText: '2026/7/12 10:01:00' },
    ],
    storedRows: [{
      hotelId: '58',
      targetDate: '2026-07-13',
      acquisitionStatusKind: 'ready',
      fieldHasGap: false,
      platformRows: [
        { platform: 'ctrip', target_date_competition_hotel_count: 26, target_date_competition_self_count: 1, target_date_competition_competitor_count: 25 },
        { platform: 'meituan', target_date_rows: 0 },
      ],
    }],
    targetDate: '2026-07-13',
  });
  assert.equal(coverageRows.length, 3);
  assert.equal(coverageRows[0].status, 'skipped');
  assert.equal(coverageRows[0].statusText, '目标日已入库');
  assert.equal(coverageRows[0].existingCount, 26);
  assert.equal(coverageRows[1].status, 'failed');
  assert.match(coverageRows[1].message, /仍无入库证据/);
  assert.equal(coverageRows[2].status, 'not_run');
  assert.equal(coverageRows[2].statusText, '待补采');
  assert.equal(helpers.summarizeManualOneClickFetchRows(coverageRows).notRun, 1);

  const activeCoverageRows = helpers.buildManualOneClickFetchCoverageRows({
    ctripHotels: [{ id: 58, name: '携程执行中' }],
    resultRows: [{ key: 'running:58', platform: 'ctrip', hotelId: '58', status: 'running', statusText: '获取中' }],
    storedRows,
    targetDate: '2026-07-13',
    activeRun: true,
  });
  assert.equal(activeCoverageRows[0].status, 'running');

  assert.equal(typeof helpers.buildManualOneClickFetchRunningRow, 'function');
  const runningRow = helpers.buildManualOneClickFetchRunningRow({
    baseRow,
    isRetryAttempt: false,
    savedCount: '1',
    retryCount: '0',
    retryLimit: 2,
    nowText: 'running-time',
  });
  assert.equal(runningRow.status, 'running');
  assert.equal(runningRow.statusText, '获取中');
  assert.equal(runningRow.message, '正在调用手动获取接口');
  assert.equal(runningRow.savedCount, 1);
  assert.equal(runningRow.retryCount, 0);
  assert.equal(runningRow.timeText, 'running-time');

  const retryRow = helpers.buildManualOneClickFetchRunningRow({
    baseRow,
    isRetryAttempt: true,
    retryCount: 1,
    retryLimit: 2,
    nowText: 'retry-time',
  });
  assert.equal(retryRow.statusText, '重抓中');
  assert.match(retryRow.message, /1\/2/);
  assert.equal(retryRow.timeText, 'retry-time');

  assert.equal(typeof helpers.buildManualOneClickFetchResultRow, 'function');
  assert.equal(typeof helpers.buildManualOneClickFetchFailureRow, 'function');
  const resultRow = helpers.buildManualOneClickFetchResultRow({
    baseRow,
    resultSummary: {
      status: 'success',
      statusText: '已入库',
      message: 'done',
      qunarVisitorIncomplete: false,
    },
    savedCount: '5',
    retryCount: '1',
    attemptCount: '2',
    ctripQunarQuality: { total: 12 },
    nowText: 'done-time',
  });
  assert.equal(resultRow.status, 'success');
  assert.equal(resultRow.savedCount, 5);
  assert.equal(resultRow.retryCount, 1);
  assert.equal(resultRow.attemptCount, 2);
  assert.equal(resultRow.qunarVisitorTotal, 12);
  assert.equal(resultRow.qunarVisitorIncomplete, false);
  assert.equal(resultRow.timeText, 'done-time');

  const failedRow = helpers.buildManualOneClickFetchFailureRow({
    baseRow,
    error: new Error('backend login required'),
    nowText: 'failed-time',
  });
  assert.equal(failedRow.status, 'failed');
  assert.equal(failedRow.statusText, '失败');
  assert.equal(failedRow.message, 'backend login required');
  assert.equal(failedRow.savedCount, 0);
  assert.equal(failedRow.timeText, 'failed-time');
});

test('release evidence panel rows keep release readiness blockers non-closing', () => {
  assert.equal(typeof helpers.buildReleaseEvidencePanelRows, 'function');
  assert.equal(typeof helpers.summarizeReleaseEvidencePanel, 'function');

  const gapPack = {
    release_ready: false,
    blocking_requirements: [
      {
        id: 'design-handoff-missing',
        status: 'missing',
        acceptance_command: 'npm run review:release-design',
        evidence: 'controlled design handoff manifest is missing',
      },
      {
        id: 'ota-credential-rotation-attestation-missing',
        status: 'missing',
        acceptance_command: 'npm run review:release-ota-credentials',
      },
      {
        id: 'local-git-state-open',
        status: 'open',
        acceptance_command: 'npm run review:release-external-state',
      },
    ],
    operator_intake_packet: {
      does_not_close_release_readiness: true,
      required_external_inputs: [
        {
          id: 'design_handoff_manifest',
          required_file: '../release-evidence-temp/design_handoff_manifest.json',
          creation_command: 'npm run release:create-design-manifest',
          isolated_review_command: 'npm run review:release-design',
        },
        {
          id: 'ota_credential_rotation_attestation',
          required_file: '../release-evidence-temp/ota_credential_rotation_attestation.json',
          creation_command: 'npm run release:create-ota-attestation',
          isolated_review_command: 'npm run review:release-ota-credentials',
        },
        {
          id: 'final_release_pr_and_local_state',
          required_result_file: '../release-evidence-temp/release-external-state-result.json',
          selection_command: 'npm run review:release-pr-candidates',
          isolated_review_command: 'npm run review:release-external-state',
        },
      ],
    },
    source_status: {
      local_worktree_close_plan: {
        status: 'blocked_until_clean_or_isolated',
        changed_entries: 3,
      },
    },
  };

  const rows = helpers.buildReleaseEvidencePanelRows(gapPack);
  assert.equal(rows.length, 3);
  assert.equal(rows.map(row => row.id).join(','), 'design_handoff_manifest,ota_credential_rotation_attestation,final_release_pr_and_local_state');
  assert.equal(rows.every(row => row.priority === 'high'), true);
  assert.equal(rows.find(row => row.id === 'final_release_pr_and_local_state').statusText, '未关闭');
  assert.match(rows.find(row => row.id === 'design_handoff_manifest').acceptanceCommand, /review:release-design/);

  const summary = helpers.summarizeReleaseEvidencePanel(gapPack);
  assert.equal(summary.releaseReady, false);
  assert.equal(summary.doesNotCloseReleaseReadiness, true);
  assert.equal(summary.blockerCount, 3);
  assert.equal(summary.worktreeStatus, 'blocked_until_clean_or_isolated');
  assert.equal(summary.changedEntries, 3);
  assert.match(summary.boundaryText, /不替代最终设计交付/);
  assert.match(summary.boundaryText, /review:release-readiness/);
});

test('release evidence panel accepts backend release status projection', () => {
  const payload = {
    overall_status: 'not_release_ready',
    release_ready: false,
    does_not_close_release_readiness: true,
    blocking_requirements: [
      {
        id: 'design-handoff-missing',
        status: 'open',
        evidence: 'missing controlled design manifest',
        next_action: 'provide controlled manifest',
        acceptance_command: 'npm run review:release-design',
      },
      {
        id: 'ota-credential-rotation-attestation-missing',
        status: 'open',
        evidence: 'missing OTA rotation attestation',
        next_action: 'provide credential-free attestation',
        acceptance_command: 'npm run review:release-ota-credentials',
      },
      {
        id: 'local-git-state-open',
        status: 'open',
        evidence: 'local worktree is dirty and RELEASE_PR_NUMBER is missing',
        next_action: 'select final release PR and pass external-state',
        acceptance_command: 'npm run review:release-external-state',
      },
    ],
    operator_intake_packet: {
      does_not_close_release_readiness: true,
      required_external_inputs: [
        { id: 'design_handoff_manifest', required_file: '../release-evidence-temp/design_handoff_manifest.json' },
        { id: 'ota_credential_rotation_attestation', required_file: '../release-evidence-temp/ota_credential_rotation_attestation.json' },
        { id: 'final_release_pr_and_local_state', required_result_file: '../release-evidence-temp/release-external-state-result.json' },
      ],
    },
    source_status: {
      local_worktree_close_plan: {
        status: 'blocked_until_clean_or_isolated',
      },
    },
  };

  const rows = helpers.buildReleaseEvidencePanelRows(payload);
  assert.equal(rows.length, 3);
  assert.equal(rows.every(row => row.doesNotCloseReleaseReadiness), true);
  assert.match(rows.find(row => row.id === 'ota_credential_rotation_attestation').nextAction, /credential-free/);

  const summary = helpers.summarizeReleaseEvidencePanel(payload);
  assert.equal(summary.status, 'high');
  assert.equal(summary.blockerCount, 3);
  assert.equal(summary.releaseReady, false);
  assert.equal(summary.doesNotCloseReleaseReadiness, true);
});

test('release evidence panel does not reopen PR state after clean external-state passes', () => {
  const payload = {
    release_ready: false,
    does_not_close_release_readiness: true,
    blocking_requirements: [
      {
        id: 'design-handoff-missing',
        status: 'open',
        evidence: 'missing controlled design manifest',
        acceptance_command: 'npm run review:release-design',
      },
      {
        id: 'ota-credential-rotation-attestation-missing',
        status: 'open',
        evidence: 'missing OTA rotation attestation',
        acceptance_command: 'npm run review:release-ota-credentials',
      },
    ],
    operator_intake_packet: {
      does_not_close_release_readiness: true,
      required_external_inputs: [
        { id: 'design_handoff_manifest', required_file: '../release-evidence-temp/design_handoff_manifest.json' },
        { id: 'ota_credential_rotation_attestation', required_file: '../release-evidence-temp/ota_credential_rotation_attestation.json' },
        { id: 'final_release_pr_and_local_state', required_result_file: '../release-evidence-temp/release-external-state-result.json' },
      ],
    },
    source_status: {
      external_state_check: {
        status: 'passing_from_clean_verification_worktree',
        open_failures: [],
      },
      local_worktree_close_plan: {
        status: 'passing_from_clean_verification_worktree',
      },
    },
  };

  const rows = helpers.buildReleaseEvidencePanelRows(payload);
  const prRow = rows.find(row => row.id === 'final_release_pr_and_local_state');
  assert.equal(prRow.status, 'passed');
  assert.equal(prRow.priority, 'ok');
  assert.match(prRow.evidenceText, /clean checkout/);

  const summary = helpers.summarizeReleaseEvidencePanel(payload);
  assert.equal(summary.blockerCount, 2);
  assert.equal(summary.requiredInputCount, 3);
});

test('release evidence panel trusts backend required input status', () => {
  const payload = {
    release_ready: false,
    does_not_close_release_readiness: true,
    blocking_requirements: [
      { id: 'design-handoff-missing', status: 'open', acceptance_command: 'npm run review:release-design' },
      { id: 'ota-credential-rotation-attestation-missing', status: 'open', acceptance_command: 'npm run review:release-ota-credentials' },
    ],
    operator_intake_packet: {
      required_external_inputs: [
        { id: 'design_handoff_manifest', status: 'missing' },
        { id: 'ota_credential_rotation_attestation', status: 'missing' },
        {
          id: 'final_release_pr_and_local_state',
          status: 'passed',
          success_evidence: 'external-state passed from clean verification checkout',
          next_action: 'rerun after PR update',
        },
      ],
    },
  };

  const rows = helpers.buildReleaseEvidencePanelRows(payload);
  const prRow = rows.find(row => row.id === 'final_release_pr_and_local_state');
  assert.equal(prRow.status, 'passed');
  assert.equal(prRow.priority, 'ok');
  assert.match(prRow.evidenceText, /external-state passed/);
  assert.equal(helpers.summarizeReleaseEvidencePanel(payload).blockerCount, 2);
});

test('public endpoint security summary escalates any unconfigured public token', () => {
  const summary = helpers.summarizePublicEndpointSecurity({
    isSuperAdmin: true,
    payload: {
      period: { days: 7 },
      scan_scope: { scanned_count: 0 },
    },
    rows: [
      { endpoint: 'cron_trigger', token_configured: true, recent_failure_count: 0, rate_limited_count: 0 },
      { endpoint: 'daily_workbench_patrol_cron', token_configured: false, recent_failure_count: 0, rate_limited_count: 0 },
      { endpoint: 'competitor_task', token_configured: true, recent_failure_count: 0, rate_limited_count: 0 },
    ],
  });

  assert.equal(summary.status, 'high');
  assert.equal(summary.text, '高优先复核');
  assert.equal(summary.failureCount, 0);
  assert.equal(summary.rateLimitedCount, 0);
  assert.equal(summary.unconfiguredTokenCount, 1);
  assert.equal(helpers.publicEndpointDisplayName('daily_workbench_patrol_cron'), 'daily-workbench-patrol-cron');
  assert.match(helpers.publicEndpointSecurityBoundaryText(), /daily-workbench-patrol-cron/);
  assert.equal(helpers.publicEndpointPathText({
    method: 'GET',
    path: '/api/online-data/daily-workbench-patrol-cron',
  }), 'GET /api/online-data/daily-workbench-patrol-cron');
  assert.match(cookieEndpointConcern, /daily_workbench_patrol_cron/);
  assert.match(cookieEndpointConcern, /\/api\/online-data\/daily-workbench-patrol-cron/);
  assert.match(routeApp, /api\/online-data\/daily-workbench-patrol-cron/);
});

test('manual one-click fetch result helpers make Ctrip Qunar zero retryable failure', () => {
  assert.equal(helpers.manualOneClickFetchSavedCount({ data: { summary: { saved_count: 3 } } }), 3);
  assert.equal(helpers.manualOneClickFetchResultMessage({ response: { msg: '需要登录' } }), '需要登录');

  const meituanSuccess = helpers.summarizeManualOneClickFetchResult({
    platform: 'meituan',
    result: { code: 200 },
    savedCount: 2,
  });
  assert.equal(meituanSuccess.status, 'success');
  assert.equal(meituanSuccess.statusText, '已入库');
  assert.match(meituanSuccess.message, /实际返回 2 条入库/);

  const noSaved = helpers.summarizeManualOneClickFetchResult({
    platform: 'meituan',
    result: { code: 200, message: '获取成功' },
    savedCount: 0,
  });
  assert.equal(noSaved.status, 'no_saved');
  assert.match(noSaved.message, /本次入库 0 条/);

  const meituanFailed = helpers.summarizeManualOneClickFetchResult({
    platform: 'meituan',
    result: {
      status: 'failed',
      results: [
        { rankName: '入住榜', status: 'login_required', credentialStatus: 'login_required', message: '登录态已失效' },
        { rankName: '销售榜', status: 'missing_resource_id', message: '缺 partnerId / poiId' },
      ],
    },
    savedCount: 0,
  });
  assert.equal(meituanFailed.status, 'failed');
  assert.match(meituanFailed.message, /入住榜: 登录态已失效/);
  assert.match(meituanFailed.message, /销售榜: 缺 partnerId \/ poiId/);

  const repeatedMeituanLoginFailure = helpers.summarizeManualOneClickFetchResult({
    platform: 'meituan',
    result: {
      status: 'login_required',
      results: [
        { rankName: '入住榜', status: 'login_required', message: '美团登录态已失效，请重新登录美团后台后更新 Cookie/API 辅助内容' },
        { rankName: '销售榜', status: 'login_required', message: '美团登录态已失效，请重新登录美团后台后更新 Cookie/API 辅助内容' },
        { rankName: '转化榜', status: 'login_required', message: '美团登录态已失效，请重新登录美团后台后更新 Cookie/API 辅助内容' },
      ],
    },
    savedCount: 0,
  });
  assert.equal(repeatedMeituanLoginFailure.status, 'failed');
  assert.equal(repeatedMeituanLoginFailure.message, '美团登录失效，3 个榜单未获取。请重新登录后重试。');
  assert.match(repeatedMeituanLoginFailure.detailMessage, /入住榜/);
  assert.match(repeatedMeituanLoginFailure.detailMessage, /转化榜/);

  const meituanPartial = helpers.summarizeManualOneClickFetchResult({
    platform: 'meituan',
    result: {
      status: 'partial',
      results: [
        { rankName: '入住榜', status: 'partial', message: '平台仅返回排名百分比，未返回实际数值' },
        { rankName: '销售榜', status: 'partial', message: '平台仅返回排名百分比，未返回实际数值' },
        { rankName: '转化榜', status: 'success', message: '操作成功' },
      ],
    },
    savedCount: 84,
  });
  assert.equal(meituanPartial.status, 'partial');
  assert.equal(meituanPartial.statusText, '部分入库');
  assert.equal(meituanPartial.message, '已入库 84 条，2 个榜单缺少实际数值。');
  assert.match(meituanPartial.detailMessage, /入住榜/);
  assert.match(meituanPartial.detailMessage, /销售榜/);

  const ctripQunarGap = helpers.summarizeManualOneClickFetchResult({
    platform: 'ctrip',
    result: { code: 200, message: '获取成功' },
    savedCount: 5,
    ctripQunarQuality: { rowCount: 3, total: 0 },
    qunarRetryCount: 3,
    qunarVisitorNeedsRetry: true,
  });
  assert.equal(ctripQunarGap.status, 'failed');
  assert.equal(ctripQunarGap.qunarVisitorIncomplete, true);
  assert.match(ctripQunarGap.message, /3 瀹?/);
  assert.match(ctripQunarGap.message, /去哪儿字段未返回有效数据/);
  assert.match(ctripQunarGap.message, /本次补采判定失败，可重新补采/);
  assert.doesNotMatch(ctripQunarGap.message, /去哪儿访客\s*\d+/);

  const ctripSuccess = helpers.summarizeManualOneClickFetchResult({
    platform: 'ctrip',
    result: { code: 200, message: '获取成功' },
    savedCount: 2,
    ctripQunarQuality: {
      rowCount: 2,
      total: 9,
      ready: true,
      selfHotelCount: 1,
      competitorHotelCount: 1,
    },
  });
  assert.equal(ctripSuccess.status, 'success');
  assert.match(ctripSuccess.message, /2 瀹?/);
  assert.doesNotMatch(ctripSuccess.message, /9 瀹?/);
  assert.match(ctripSuccess.message, /本店 1 家、竞店 1 家/);
  assert.match(ctripSuccess.message, /数量以平台实际返回为准/);
  assert.doesNotMatch(ctripSuccess.message, /去哪儿访客\s*\d+/);

  const ctripNoRows = helpers.summarizeManualOneClickFetchResult({
    platform: 'ctrip',
    result: { code: 200, message: '获取成功', data: { display_hotel_count: 0 } },
    savedCount: 0,
    ctripQunarQuality: { rowCount: 0, total: 0, ready: false },
  });
  assert.equal(ctripNoRows.status, 'no_saved');
  assert.equal(ctripNoRows.message, '携程未返回竞争圈数据，请重试。');
  assert.doesNotMatch(ctripNoRows.message, /携程和去哪儿都成功才算成功/);

  const ctripLoginFailure = helpers.summarizeManualOneClickFetchResult({
    platform: 'ctrip',
    result: { status: 'failed', response: { message: 'Cookie已失效，请重新登录携程' } },
    savedCount: 0,
    ctripQunarQuality: { rowCount: 0, total: 0, ready: false },
  });
  assert.equal(ctripLoginFailure.status, 'failed');
  assert.equal(ctripLoginFailure.message, 'Cookie已失效，请重新登录携程');
});

test('manual one-click fetch skips Qunar auto retry from midnight until 06:00', () => {
  assert.equal(typeof helpers.manualOneClickFetchQunarAutoRetryAllowedAt, 'function');
  assert.equal(helpers.manualOneClickFetchQunarAutoRetryAllowedAt({ getHours: () => 0 }), false);
  assert.equal(helpers.manualOneClickFetchQunarAutoRetryAllowedAt({ getHours: () => 5 }), false);
  assert.equal(helpers.manualOneClickFetchQunarAutoRetryAllowedAt({ getHours: () => 6 }), true);
  assert.equal(helpers.manualOneClickFetchQunarAutoRetryAllowedAt({ getHours: () => 23 }), true);

  const quietHoursGap = helpers.summarizeManualOneClickFetchResult({
    platform: 'ctrip',
    result: { code: 200, message: '获取成功' },
    savedCount: 26,
    ctripQunarQuality: { rowCount: 26, total: 0, ready: false },
    qunarRetryCount: 0,
    qunarVisitorNeedsRetry: true,
    qunarAutoRetrySuppressed: true,
  });
  assert.equal(quietHoursGap.status, 'failed');
  assert.match(quietHoursGap.message, /00:00–05:59/);
  assert.match(quietHoursGap.message, /已跳过自动重抓/);
  assert.doesNotMatch(quietHoursGap.message, /已自动重抓/);

  assert.match(publicEntry, /requireDataHealthStatic\('manualOneClickFetchQunarAutoRetryAllowedAt'\)/);
  assert.match(publicEntry, /qunarAutoRetryAllowed = manualOneClickFetchQunarAutoRetryAllowedAt\(\)/);
  assert.match(publicEntry, /!qunarAutoRetryAllowed/);
});

test('manual one-click fetch display helpers stay pure and status aware', () => {
  const normalized = helpers.normalizeManualOneClickFetchStoredRows([
    { status: 'running', savedCount: '2', retryCount: '1', qunarVisitorTotal: '0' },
    { status: 'success', savedCount: '5', timeText: '2026/7/8 09:02:00' },
  ]);
  assert.equal(normalized[0].status, 'failed');
  assert.equal(normalized[0].statusText, '未完成');
  assert.equal(normalized[0].savedCount, 2);
  assert.equal(normalized[1].savedCount, 5);
  assert.equal(normalized[0].fetchedBy, '未记录');
  assert.equal(
    helpers.normalizeManualOneClickFetchStoredMessage('请求失败: HTTP错误: 302 (可能Cookie已失效，请重新登录携程)'),
    '请求失败: HTTP错误: 302 (Cookie已失效，请重新登录携程)',
  );
  assert.match(
    helpers.normalizeManualOneClickFetchStoredMessage('携程竞争圈未返回可展示行，携程和去哪儿都成功才算成功。'),
    /去哪儿字段未返回有效数据；本次补采判定失败/,
  );
  assert.equal(helpers.normalizeManualOneClickFetchStoredMessage('手动获取失败'), '手动获取失败：接口未返回错误详情');
  assert.doesNotMatch(
    helpers.normalizeManualOneClickFetchStoredMessage('手动获取完成：携程和去哪儿均已返回；本次入库 24 条；去哪儿访客 6209。'),
    /去哪儿访客|6209/,
  );
  assert.doesNotMatch(
    helpers.normalizeManualOneClickFetchStoredMessage('目标日已入库 130 条竞争圈数据，本次不重复获取；竞争圈来源可能少于 26 条，不按满额判失败。'),
    /130|26 条|抓满|满额/,
  );

  const storedLoginMessage = '入住榜（入住间夜+房费收入）: 请求失败: 美团登录态已失效，请重新登录美团后台后更新 Cookie/API 辅助内容；销售榜（销售间夜+销售额）: 请求失败: 美团登录态已失效，请重新登录美团后台后更新 Cookie/API 辅助内容；转化榜（浏览转化+支付转化）: 请求失败: 美团登录态已失效，请重新登录美团后台后更新 Cookie/API 辅助内容';
  const storedPartialMessage = '入住榜（入住间夜+房费收入）: 平台仅返回排名百分比，未返回实际数值；销售榜（销售间夜+销售额）: 平台仅返回排名百分比，未返回实际数值；转化榜（浏览转化+支付转化）: 操作成功';
  const normalizedProblemRows = helpers.normalizeManualOneClickFetchStoredRows([
    { platform: 'meituan', status: 'failed', message: storedLoginMessage, savedCount: 0 },
    { platform: 'meituan', status: 'success', message: storedPartialMessage, savedCount: 84 },
  ]);
  assert.equal(normalizedProblemRows[0].message, '美团登录失效，3 个榜单未获取。请重新登录后重试。');
  assert.equal(normalizedProblemRows[0].detailMessage, storedLoginMessage);
  assert.equal(normalizedProblemRows[1].status, 'partial');
  assert.equal(normalizedProblemRows[1].statusText, '部分入库');
  assert.equal(normalizedProblemRows[1].message, '已入库 84 条，2 个榜单缺少实际数值。');
  assert.equal(normalizedProblemRows[1].detailMessage, storedPartialMessage);

  const normalizedInflatedCompetitionMessage = helpers.normalizeManualOneClickFetchStoredMessage('\u643a\u7a0b\u7ade\u4e89\u5708\u672c\u6b21\u8fd4\u56de 2039 \u5bb6\uff08\u672c\u5e97 1 \u5bb6\u3001\u7ade\u5e97 25 \u5bb6\uff09\u5e76\u5165\u5e93 26 \u6761\uff1b\u6570\u91cf\u4ee5\u5e73\u53f0\u5b9e\u9645\u8fd4\u56de\u4e3a\u51c6\u3002');
  assert.match(normalizedInflatedCompetitionMessage, /\u8fd4\u56de 26 \u5bb6/);
  assert.doesNotMatch(normalizedInflatedCompetitionMessage, /2039/);

  const summary = helpers.summarizeManualOneClickFetchRows([
    { status: 'success', savedCount: 5 },
    { status: 'partial', savedCount: 84 },
    { status: 'no_saved' },
    { status: 'failed' },
    { status: 'queued' },
  ]);
  assert.equal(summary.savedHotels, 2);
  assert.equal(summary.partial, 1);
  assert.equal(summary.noSaved, 1);
  assert.equal(summary.failed, 1);
  assert.equal(summary.skipped, 0);
  assert.equal(summary.notRun, 0);
  assert.equal(summary.pending, 1);
  assert.equal(summary.savedCount, 89);

  const cards = helpers.buildManualOneClickFetchCards({
    ctripReadyCount: 2,
    meituanReadyCount: 1,
    summary,
    lastRunAt: '2026/7/8 09:00:00',
  });
  assert.equal(cards[0].value, '2');
  assert.equal(cards[1].label, '美团字段配置');
  assert.equal(cards[1].detail, '仅代表已填写，不代表登录有效');
  assert.equal(cards[2].label, '需处理');
  assert.equal(cards[2].value, '3');
  const settledCards = helpers.buildManualOneClickFetchCards({ summary: { ...summary, pending: 0 } });
  assert.match(settledCards[2].detail, /1 个部分入库/);
  assert.match(settledCards[2].detail, /0 个待补采/);
  assert.doesNotMatch(cards[2].detail, /已入库跳过/);
  assert.equal(cards[3].detail, '2026/7/8 09:00:00');

  assert.match(helpers.buildManualOneClickFetchEmptyText({ running: 'all' }), /正在执行/);
  assert.match(helpers.buildManualOneClickFetchEmptyText({}), /暂无可手动获取/);
  assert.match(helpers.buildManualOneClickFetchEmptyText({ ctripReadyCount: 1, pendingCount: 0 }), /完整成功的门店已隐藏/);
  assert.match(helpers.buildManualOneClickFetchEmptyText({ ctripReadyCount: 1 }), /还没有执行记录/);
  assert.match(helpers.manualOneClickFetchStatusClass('failed'), /red/);
  assert.match(helpers.manualOneClickFetchStatusClass('partial'), /amber/);
  assert.equal(helpers.manualOneClickFetchPlatformText('ctrip'), '携程');
  assert.equal(helpers.manualOneClickFetchMessageIsQunarVisitorZero('去哪儿访客为 0'), true);
  assert.equal(helpers.manualOneClickFetchActionableStatus('failed'), true);
  assert.equal(helpers.manualOneClickFetchActionableStatus('partial'), true);
  assert.equal(helpers.manualOneClickFetchActionableStatus('success'), false);
  assert.equal(helpers.manualOneClickFetchCanEditRow({ status: 'failed' }, true), true);
  assert.equal(helpers.manualOneClickFetchCanEditRow({ status: 'failed' }, false), false);
  assert.equal(helpers.manualOneClickFetchCanRetryRow({ status: 'no_saved', hotelId: '7' }), true);
  assert.equal(helpers.manualOneClickFetchCanRetryRow({ status: 'partial', hotelId: '7' }), true);
  assert.equal(helpers.manualOneClickFetchCanRetryRow({ status: 'failed', hotelId: '' }), false);
  assert.equal(helpers.manualOneClickFetchCanDeleteRow({ status: 'failed', hotelId: '7' }, true), true);
  assert.equal(helpers.manualOneClickFetchCanDeleteRow({ status: 'partial', hotelId: '7' }, true), false);
  assert.equal(helpers.manualOneClickFetchCanSupplementRow({ status: 'partial', hotelId: '7' }, true), false);
  assert.equal(helpers.manualOneClickFetchCanSupplementRow({ status: 'success', hotelId: '7' }, true), false);
  assert.equal(helpers.manualOneClickFetchHasQunarVisitorZeroFailureInRows({
    platform: 'ctrip',
    hotelId: '60',
    rows: [{ platform: 'ctrip', hotelId: '60', message: '去哪儿访客为 0' }],
  }), true);
  assert.equal(helpers.manualOneClickFetchHasQunarVisitorZeroFailureInRows({
    platform: 'meituan',
    hotelId: '60',
    rows: [{ platform: 'ctrip', hotelId: '60', message: '去哪儿访客为 0' }],
  }), false);
  assert.equal(helpers.manualOneClickFetchQunarVisitorNumber({ qunar_detail_visitors: '4' }), 4);
  assert.equal(helpers.manualOneClickFetchQunarVisitorNumber({ qunarDetailVisitors: '-', views: '5' }), 5);

  const qunarGapQuality = helpers.summarizeManualOneClickFetchQunarVisitorQuality([
    { qunarDetailVisitors: 0 },
    { views: '0' },
  ]);
  assert.equal(qunarGapQuality.rowCount, 2);
  assert.equal(qunarGapQuality.total, 0);
  assert.equal(qunarGapQuality.ready, false);
  assert.equal(helpers.manualOneClickFetchQunarVisitorNeedsRetry(qunarGapQuality), true);

  const qunarReadyQuality = helpers.summarizeManualOneClickFetchQunarVisitorQuality([
    { hotelName: '我的酒店', uv: '3' },
    { hotelName: '竞店A', uv: '6' },
  ]);
  assert.equal(qunarReadyQuality.ready, true);
  assert.equal(qunarReadyQuality.rowCount, 2);
  assert.equal(qunarReadyQuality.selfHotelCount, 1);
  assert.equal(qunarReadyQuality.competitorHotelCount, 1);
  assert.equal(helpers.manualOneClickFetchQunarVisitorNeedsRetry(qunarReadyQuality), false);
  assert.equal(helpers.manualOneClickFetchQunarVisitorNeedsRetry({ rowCount: 0, total: 0, ready: false }), false);
  assert.match(publicEntry, /const CTRIP_QUNAR_VISITOR_AUTO_RETRY_LIMIT = 5;/);
  assert.match(publicEntry, /qunarRetryCount >= CTRIP_QUNAR_VISITOR_AUTO_RETRY_LIMIT/);
  assert.match(publicEntry, /Math\.min\(1800, 600 \* qunarRetryCount\)/);

  const retryUntilReady = [{ rowCount: 2, total: 0, ready: false }, { rowCount: 2, total: 0, ready: false }, { rowCount: 2, total: 9, ready: true }];
  let attemptsUntilReady = 0;
  let retriesUntilReady = 0;
  while (attemptsUntilReady < retryUntilReady.length) {
    const quality = retryUntilReady[attemptsUntilReady];
    attemptsUntilReady += 1;
    if (!helpers.manualOneClickFetchQunarVisitorNeedsRetry(quality) || retriesUntilReady >= 5) break;
    retriesUntilReady += 1;
  }
  assert.equal(attemptsUntilReady, 3);
  assert.equal(retriesUntilReady, 2);

  let exhaustedAttempts = 0;
  let exhaustedRetries = 0;
  while (true) {
    exhaustedAttempts += 1;
    const quality = { rowCount: 2, total: 0, ready: false };
    if (!helpers.manualOneClickFetchQunarVisitorNeedsRetry(quality) || exhaustedRetries >= 5) break;
    exhaustedRetries += 1;
  }
  assert.equal(exhaustedAttempts, 6);
  assert.equal(exhaustedRetries, 5);

  const sorted = helpers.sortManualOneClickFetchRows([
    { status: 'success', key: 'ok', timeText: '2026/7/8 09:02:00' },
    { status: 'failed', key: 'bad', timeText: '2026/7/8 09:01:00' },
    { status: 'no_saved', key: 'empty', timeText: '2026/7/8 09:03:00' },
    { status: 'partial', key: 'partial', timeText: '2026/7/8 09:04:00' },
  ]);
  assert.equal(sorted.map(row => row.key).join(','), 'bad,empty,partial,ok');
  const visible = helpers.filterManualOneClickFetchDisplayRows([
    ...sorted,
    { status: 'skipped', key: 'stored' },
  ]);
  assert.equal(visible.map(row => row.key).join(','), 'bad,empty,partial,ok,stored');
  const successfulVisible = helpers.filterManualOneClickFetchDisplayRows(visible, { status: 'success' });
  assert.equal(successfulVisible.map(row => row.key).join(','), 'ok,stored');
  const failedVisible = helpers.filterManualOneClickFetchDisplayRows(visible, { status: 'failed' });
  assert.equal(failedVisible.map(row => row.key).join(','), 'bad,empty,partial');
  const pendingVisible = helpers.filterManualOneClickFetchDisplayRows([
    ...visible,
    { status: 'not_run', key: 'pending' },
  ], { status: 'not_run' });
  assert.equal(pendingVisible.map(row => row.key).join(','), 'pending');
  assert.match(publicEntry, /manualOneClickFetchStatusFilter/);
  assert.match(publicEntry, /完整\/已入库/);
  assert.match(publicEntry, /需处理/);
  assert.match(publicEntry, /采集人：\{\{ row\.fetchedBy \|\| '未记录' \}\}/);
  assert.match(publicEntry, /处理人：\{\{ row\.handledBy \|\| '未记录' \}\}/);

  const resultTableStart = publicEntry.indexOf('<div v-if="manualOneClickFetchDisplayRows.length"');
  const resultTableEnd = publicEntry.indexOf('<div v-else class="mt-4 rounded-lg', resultTableStart);
  const resultTable = publicEntry.slice(resultTableStart, resultTableEnd);
  assert.match(resultTable, /<table class="w-full table-fixed[^\"]*" style="min-width: 920px;">/);
  assert.match(resultTable, /<colgroup>/);
  assert.match(resultTable, /-webkit-line-clamp:\s*2/);
  assert.match(resultTable, /row\.detailMessage/);
  assert.match(resultTable, /查看详情/);
  assert.doesNotMatch(resultTable, /min-w-\[\d+rem\]/);
  const dataHealthAssetMatch = publicEntry.match(/data-health-static\.js\?v=[^"'\s>]*h([0-9a-f]{10})/);
  assert.ok(dataHealthAssetMatch, 'runtime entry must load data-health-static.js with a content hash');
  assert.equal(
    dataHealthAssetMatch[1],
    createHash('sha256').update(dataHealthStaticSource).digest('hex').slice(0, 10),
    'runtime entry data-health-static.js hash must match the current helper source',
  );

  const directIssueStart = publicEntry.indexOf('const otaDirectManualFailureBuckets = computed');
  const directIssueEnd = publicEntry.indexOf('const otaDirectViewCards = computed', directIssueStart);
  const directIssueBuckets = publicEntry.slice(directIssueStart, directIssueEnd);
  assert.match(directIssueBuckets, /\['failed', 'no_saved', 'partial'\]/);
  assert.match(directIssueBuckets, /\['partial', '部分入库'/);
});

test('manual fetch credential errors use deterministic wording', () => {
  for (const source of [onlineDataRequestConcern, ctripOverviewRequestConcern, businessDisplayConcern]) {
    assert.doesNotMatch(source, /可能Cookie|Cookie 可能/);
  }
  assert.match(onlineDataRequestConcern, /HTTP错误: \{\$httpCode\}[\s\S]*Cookie已失效，请重新登录携程/);
  assert.match(businessDisplayConcern, /Cookie已失效或当前账号无权限/);
});

test('full data health panel refresh includes release evidence status without light refresh', async () => {
  assert.equal(typeof helpers.buildDataHealthPanelRefreshJobs, 'function');

  const calls = [];
  const loader = (name) => (...args) => {
    calls.push([name, ...args]);
    return Promise.resolve(name);
  };
  const options = {
    loadAutoFetchStatus: loader('loadAutoFetchStatus'),
    loadDailyWorkbench: loader('loadDailyWorkbench'),
    loadDailyWorkbenchPatrols: loader('loadDailyWorkbenchPatrols'),
    loadPhase3OperationEffectLoop: loader('loadPhase3OperationEffectLoop'),
    loadPhase3OperationEffectLoopLedger: loader('loadPhase3OperationEffectLoopLedger'),
    loadCollectionReliability: loader('loadCollectionReliability'),
    loadDataHealthOperationLogs: loader('loadDataHealthOperationLogs'),
    loadPublicEndpointSecurity: loader('loadPublicEndpointSecurity'),
    loadReleaseEvidenceStatus: loader('loadReleaseEvidenceStatus'),
    loadHotelDataDashboard: loader('loadHotelDataDashboard'),
    loadPlatformCollectionResources: loader('loadPlatformCollectionResources'),
  };

  await Promise.all(helpers.buildDataHealthPanelRefreshJobs({
    ...options,
    normalizedMode: 'light',
  }));
  assert.equal(calls.some(([name]) => name === 'loadReleaseEvidenceStatus'), false);
  assert.equal(calls.some(([name]) => name === 'loadDailyWorkbenchPatrols'), false);
  assert.equal(calls.some(([name]) => name === 'loadPhase3OperationEffectLoop'), false);
  assert.equal(calls.some(([name]) => name === 'loadPhase3OperationEffectLoopLedger'), false);

  calls.length = 0;
  await Promise.all(helpers.buildDataHealthPanelRefreshJobs({
    ...options,
    normalizedMode: 'full',
  }));
  assert.equal(calls.some(([name]) => name === 'loadReleaseEvidenceStatus'), true);
  assert.equal(calls.some(([name]) => name === 'loadDailyWorkbenchPatrols'), false);
  assert.equal(calls.some(([name]) => name === 'loadPhase3OperationEffectLoop'), false);
  assert.equal(calls.some(([name]) => name === 'loadPhase3OperationEffectLoopLedger'), false);
});

test('OTA field gap queue exposes source path metric storage UI and verifier state', () => {
  assert.equal(typeof helpers.buildOtaFieldGapQueueRows, 'function');
  assert.equal(typeof helpers.summarizeOtaFieldGapQueue, 'function');

  const rows = helpers.buildOtaFieldGapQueueRows({
    sourceDateEvidence: {
      target_date: '2026-07-08',
      platforms: [{
        platform: 'meituan',
        p0_traffic_gate_status: 'missing_target_date_traffic_rows',
        p0_traffic_field_fact_status: 'no_target_date_traffic_rows',
        p0_field_loop_matrix: [{
          metric_key: 'list_exposure',
          expected_storage_field: 'online_daily_data.list_exposure',
          status: 'no_target_date_traffic_rows',
          source_path_structured: false,
          ui_status_ready: false,
        }],
      }],
    },
  });

  assert.equal(rows.length, 1);
  assert.equal(rows[0].platform, 'meituan');
  assert.equal(rows[0].targetDate, '2026-07-08');
  assert.equal(rows[0].metricKey, 'list_exposure');
  assert.equal(rows[0].storageField, 'online_daily_data.list_exposure');
  assert.equal(rows[0].sourcePath, 'source_path_missing');
  assert.equal(rows[0].uiStatus, 'no_target_date_traffic_rows');
  assert.equal(rows[0].verifierStatus, 'missing_target_date_traffic_rows');
  assert.match(rows[0].nextAction, /traffic/);

  const summary = helpers.summarizeOtaFieldGapQueue(rows);
  assert.equal(summary.status, 'high');
  assert.equal(summary.openCount, 1);
  assert.equal(summary.sourcePathMissing, 1);
  assert.match(summary.boundaryText, /source_path/);
  assert.match(summary.boundaryText, /verifier/);
});
