import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const context = { window: {} };
vm.runInNewContext(readFileSync('public/data-health-static.js', 'utf8'), context, {
  filename: 'public/data-health-static.js',
});

const helpers = context.window.SUXI_DATA_HEALTH_STATIC;

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
});

test('manual one-click fetch result helpers keep Ctrip Qunar gaps blocking', () => {
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
  assert.match(ctripQunarGap.message, /携程和去哪儿都成功才算成功/);
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

  const summary = helpers.summarizeManualOneClickFetchRows([
    { status: 'success', savedCount: 5 },
    { status: 'no_saved' },
    { status: 'failed' },
    { status: 'queued' },
  ]);
  assert.equal(summary.savedHotels, 1);
  assert.equal(summary.noSaved, 1);
  assert.equal(summary.failed, 1);
  assert.equal(summary.skipped, 0);
  assert.equal(summary.pending, 1);
  assert.equal(summary.savedCount, 5);

  const cards = helpers.buildManualOneClickFetchCards({
    ctripReadyCount: 2,
    meituanReadyCount: 1,
    summary,
    lastRunAt: '2026/7/8 09:00:00',
  });
  assert.equal(cards[0].value, '2');
  assert.equal(cards[1].label, '美团完整配置');
  assert.equal(cards[2].value, '2');
  assert.equal(cards[3].detail, '2026/7/8 09:00:00');

  assert.match(helpers.buildManualOneClickFetchEmptyText({ running: 'all' }), /正在执行/);
  assert.match(helpers.buildManualOneClickFetchEmptyText({}), /暂无可手动获取/);
  assert.match(helpers.buildManualOneClickFetchEmptyText({ ctripReadyCount: 1 }), /还没有执行记录/);
  assert.match(helpers.manualOneClickFetchStatusClass('failed'), /red/);
  assert.equal(helpers.manualOneClickFetchPlatformText('ctrip'), '携程');
  assert.equal(helpers.manualOneClickFetchMessageIsQunarVisitorZero('去哪儿访客为 0'), true);

  const sorted = helpers.sortManualOneClickFetchRows([
    { status: 'success', key: 'ok', timeText: '2026/7/8 09:02:00' },
    { status: 'failed', key: 'bad', timeText: '2026/7/8 09:01:00' },
    { status: 'no_saved', key: 'empty', timeText: '2026/7/8 09:03:00' },
  ]);
  assert.equal(sorted.map(row => row.key).join(','), 'bad,empty,ok');
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

  calls.length = 0;
  await Promise.all(helpers.buildDataHealthPanelRefreshJobs({
    ...options,
    normalizedMode: 'full',
  }));
  assert.equal(calls.some(([name]) => name === 'loadReleaseEvidenceStatus'), true);
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
