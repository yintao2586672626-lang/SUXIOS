import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync('public/ota-diagnosis-static.js', 'utf8');
const sandbox = { window: {} };
vm.runInNewContext(`${source}\nthis.api = window.SUXI_OTA_DIAGNOSIS_STATIC;`, sandbox);
const api = sandbox.api;

test('no_action is shown as a completed daily decision without an execution task', () => {
  const result = {
    decision_status: 'no_action',
    decision_closure: {
      status: 'no_action',
      data_evidence_input: {
        enough_for_decision: true,
        evidence_refs: ['online_daily_data#1'],
        data_gaps: [],
      },
      diagnostic_conclusion: { summary: 'No threshold breach' },
      suggested_actions: { ready_count: 0, blocked_count: 0, items: [] },
      blocked_state: { is_blocked: false, blocked_reasons: [] },
      human_confirmation: { required: false, status: 'not_required' },
    },
  };

  const cards = api.buildOtaDiagnosisDecisionClosureCards(result);
  const steps = api.buildOtaDiagnosisBusinessLoopSteps(result);
  assert.equal(cards.find(card => card.key === 'suggested_actions').status, 'no_action');
  assert.equal(cards.find(card => card.key === 'suggested_actions').value, '本次无需新增行动');
  assert.equal(steps.find(step => step.key === 'operation_management').status, 'not_required');
  assert.equal(steps.find(step => step.key === 'effect_review').status, 'not_required');
});

test('action rows keep the saved action index and only enable evidence-ready handoff', () => {
  const rows = api.buildOtaDiagnosisActionRows({
    action_items: [
      {
        id: 'ota_action_1',
        action: 'Optimize listing conversion',
        status: 'pending_manual_review',
        execution_ready: true,
        can_request_execution_intent: true,
        evidence_refs: ['online_daily_data#10'],
      },
      {
        id: 'ota_action_2',
        action: 'Collect missing data',
        status: 'blocked_by_data_gap',
        execution_ready: false,
        evidence_refs: [],
      },
    ],
  });

  assert.equal(rows[0].index, 0);
  assert.equal(rows[0].canCreateIntent, true);
  assert.equal(rows[1].index, 1);
  assert.equal(rows[1].canCreateIntent, false);
});

test('superseded saved diagnosis remains reviewable but cannot create an execution intent', () => {
  const rows = api.buildOtaDiagnosisActionRows({
    record_status: 'superseded',
    saved_record: { id: 210, saved: true, status: 'superseded', superseded_by_log_id: 211 },
    action_items: [{
      id: 'ota_action_1',
      action: 'Old action that must not execute',
      status: 'pending_manual_review',
      execution_ready: true,
      can_request_execution_intent: true,
      evidence_refs: ['online_daily_data#901'],
    }],
  });

  assert.equal(rows[0].status, 'superseded');
  assert.equal(rows[0].executionReady, false);
  assert.equal(rows[0].canCreateIntent, false);
  assert.match(rows[0].blockedReason, /仅供审计回看/);
});

test('metric cards distinguish missing values from verified zero', () => {
  const cards = api.buildOtaDiagnosisMetricCards({
    result: {
      metrics: { record_count: 2, book_order_num: 0, list_exposure: null },
      data_summary: {},
    },
  });

  assert.equal(cards.find(card => card.label === '订单').value, '0');
  assert.equal(cards.find(card => card.label === '曝光').value, '-');
});

test('blocked target-date evidence keeps revenue analysis and review blocked without duplicate gaps', () => {
  const result = {
    decision_status: 'blocked_by_data',
    data_summary: { core_metrics_complete: false },
    metrics: { record_count: 348, book_order_num: null, list_exposure: null },
    data_gaps: ['metric_missing:amount', 'metric_missing:list_exposure'],
    missing_sections: ['OTA流量数据', '竞对数据'],
    decision_closure: {
      status: 'blocked_by_data',
      data_evidence_input: {
        enough_for_decision: false,
        evidence_refs: ['source_summary'],
        data_gaps: [
          { code: 'metric_missing:amount', message: '指标未返回：amount' },
          { code: 'metric_missing:list_exposure', message: '指标未返回：list_exposure' },
        ],
      },
      diagnostic_conclusion: { summary: '核心数据缺失', confidence_level: 'low' },
      suggested_actions: { ready_count: 0, blocked_count: 1, items: [] },
      blocked_state: { is_blocked: true, blocked_reasons: ['metric_missing:amount'] },
      human_confirmation: { required: false, status: 'not_required' },
    },
  };

  const cards = api.buildOtaDiagnosisDecisionClosureCards(result);
  const steps = api.buildOtaDiagnosisBusinessLoopSteps(result);
  const gaps = api.buildOtaDiagnosisDataGapRows(result);
  const metrics = api.buildOtaDiagnosisMetricCards({ result });
  assert.equal(cards.find(card => card.key === 'diagnostic_conclusion').status, 'blocked_by_data');
  assert.equal(cards.find(card => card.key === 'diagnostic_conclusion').value, '仅形成缺数结论');
  assert.equal(steps.find(step => step.key === 'revenue_analysis').status, 'blocked_by_data');
  assert.equal(steps.find(step => step.key === 'effect_review').status, 'blocked_by_data');
  assert.equal(gaps.filter(gap => gap.code === 'metric_missing:amount').length, 1);
  assert.equal(gaps.filter(gap => gap.code === 'optional_missing_section').length, 2);
  assert.match(metrics.find(card => card.label === '入库记录').hint, /不代表核心经营事实/);
});

test('daily Meituan diagnosis uses the bound browser Profile before supplemental APIs', async () => {
  const selectedHotel = { system_hotel_id: 80, hotel_id: 80, name: '敦煌漠蓝新' };
  const form = {
    hotel_id: '80',
    platform: 'meituan',
    start_date: '2026-07-14',
    end_date: '2026-07-14',
  };
  const profileTask = api.buildOtaDiagnosisProfileSyncTask({
    selectedHotel,
    form,
    platformDataSources: [
      { id: 69, system_hotel_id: 80, platform: 'meituan', ingestion_method: 'browser_profile', enabled: true, status: 'pending' },
      { id: 68, system_hotel_id: 80, platform: 'meituan', ingestion_method: 'browser_profile', enabled: true, status: 'ready' },
    ],
  });

  assert.equal(profileTask.kind, 'browser_profile');
  assert.equal(profileTask.url, '/online-data/data-sources/68/sync');
  assert.equal(profileTask.body.capture_sections, 'traffic,orders');
  assert.equal(profileTask.body.data_date, '2026-07-14');
  assert.equal(profileTask.body.interactive_browser, false);
  assert.doesNotMatch(JSON.stringify(profileTask.body), /cookie|token|secret/i);

  const requested = [];
  const result = await api.runOtaDiagnosisHotelFetchFlow({
    selectedHotel,
    form,
    platformDataSources: [{ id: 68, system_hotel_id: 80, platform: 'meituan', ingestion_method: 'browser_profile', enabled: true, status: 'ready' }],
    findCtripConfigByHotelId: () => ({ id: 'ctrip-80', config_id: 'ctrip-80', credential_status: 'ready', has_cookies: true, node_id: 'ctrip' }),
    findMeituanConfigByHotelId: () => ({ id: 'mt-80', config_id: 'mt-80', credential_status: 'ready', has_cookies: true, partner_id: 'partner', poi_id: 'poi' }),
    requestTask: async task => {
      requested.push(task);
      if (task.kind === 'browser_profile') {
        return { code: 200, data: { status: 'success', saved_count: 2, sync_diagnostics: { p0_status: 'ready' } } };
      }
      return { code: 200, data: { saved_count: 1 } };
    },
  });

  assert.equal(requested[0].kind, 'browser_profile');
  assert.ok(requested.slice(1).every(task => task.url === '/online-data/fetch-meituan'));
  assert.equal(requested.some(task => task.url === '/online-data/fetch-ctrip'), false);
  assert.equal(result.failed, 0);
});

test('Profile sync is reported failed when the target-date P0 evidence is not ready', async () => {
  const result = await api.runOtaDiagnosisHotelFetchFlow({
    selectedHotel: { system_hotel_id: 80, hotel_id: 80 },
    form: { hotel_id: '80', platform: 'meituan', start_date: '2026-07-14', end_date: '2026-07-14' },
    platformDataSources: [{ id: 68, system_hotel_id: 80, platform: 'meituan', ingestion_method: 'browser_profile', enabled: true, status: 'ready' }],
    requestTask: async () => ({ code: 200, data: { status: 'failed', saved_count: 0, sync_diagnostics: { p0_status: 'blocked', operator_message: 'profile_session_unverified' } } }),
  });

  assert.equal(result.attempted, 1);
  assert.equal(result.failed, 1);
  assert.equal(result.results[0].kind, 'browser_profile');
  assert.equal(result.results[0].p0_status, 'blocked');
});
