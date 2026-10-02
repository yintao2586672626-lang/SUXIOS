import assert from 'node:assert/strict';
import { test } from 'node:test';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

// Runs the existing app-main history handlers with synthetic responses. The
// optional source override allows a red baseline against the prior candidate.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const sourcePath = process.env.SUXI_QUESTION_HISTORY_SOURCE
  || path.join(root, 'public/app-main.js');
const source = fs.readFileSync(sourcePath, 'utf8');
const marker = source.includes('const invalidateOperatingQuestionHistory =')
  ? 'const invalidateOperatingQuestionHistory =' : 'const loadOperatingQuestionHistory =';
const start = source.indexOf(marker);
const end = source.indexOf('const updateOperatingQuestionScope =', start);
assert.ok(start > 0 && end > start, 'actual history handlers are present');
const handlers = source.slice(start, end);
const scopeStart = source.indexOf('const loadOperatingQuestionScopeOptions =');
const scopeEnd = source.indexOf('const applyOperatingQuestionIntentReadback =', scopeStart);
assert.ok(scopeStart > 0 && scopeEnd > scopeStart, 'actual scope handler is present');
const scopeHandler = source.slice(scopeStart, scopeEnd);
const updateStart = source.indexOf('const updateOperatingQuestionScope =');
const updateEnd = source.indexOf('            watch(', updateStart);
assert.ok(updateStart > 0 && updateEnd > updateStart, 'actual scope switch handler is present');
const updateHandler = source.slice(updateStart, updateEnd);
const digest = 'a'.repeat(64);
const row = (id = 501, hotelId = 7) => ({
  id, tenant_id: 70, hotel_id: hotelId, platform: 'ctrip',
  date_start: '2026-09-01', date_end: '2026-09-02',
  question_text: `合成问题 ${id}`, answer_status: 'blocked_by_missing_facts',
  content_digest: digest, readback_verified: true,
  answer: { status: 'blocked_by_missing_facts', summary: '缺少同范围事实',
    scope: { tenant_id: 70, hotel_id: hotelId, platform: 'ctrip',
      date_start: '2026-09-01', date_end: '2026-09-02' } },
});
const listing = (items = [row()], overrides = {}) => ({ code: 200, data: {
  data_status: 'ok', list: items, count: items.length, data_gaps: [], ...overrides,
} });
const deferred = () => { let resolve; let reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
};
function harness(request, council = async () => null) {
  let sessionEpoch = 1;
  const state = { history: [], history_error: '', history_loading: false,
    history_loaded_hotel_id: '', history_loading_hotel_id: '', history_opening_id: 0,
    error: '', action_error: '', council_generation: 0, result: null };
  const form = { hotel_id: '7', platform: 'ctrip', date_start: '2026-09-01',
    date_end: '2026-09-02', decision_object: '' };
  const context = { operatingQuestionState: { value: state },
    operatingQuestionForm: { value: form }, request,
    ensureOperatingQuestionScope: () => form.hotel_id,
    applyOperatingQuestionIntentReadback: () => {},
    loadLatestOperatingQuestionCouncil: council,
    operatingQuestionPlatformText: value => value,
    operatingQuestionScopeCooldown: () => ({ blocked: false }),
    applyRecommendedOperatingQuestionScope: () => {},
    loadOperatingQuestionScopeOptions: async () => null,
    captureAuthSession: () => sessionEpoch,
    isAuthSessionCurrent: epoch => epoch === sessionEpoch,
    operatingQuestionPanelIsActive: () => true,
    reportHotelOptionExists: () => true };
  const methods = vm.runInNewContext(`(() => {
    let operatingQuestionHistoryRequestId = 0;
    let operatingQuestionHistoryOpenRequestId = 0;
    let operatingQuestionScopeRequestId = 0;
    ${scopeHandler}
    ${handlers}
    ${updateHandler}
    return { loadOperatingQuestionScopeOptions, loadOperatingQuestionHistory, openOperatingQuestionHistory,
      updateOperatingQuestionScope,
      invalidateOperatingQuestionHistory: typeof invalidateOperatingQuestionHistory === 'function'
        ? invalidateOperatingQuestionHistory : null };
  })()`, context);
  return { state, form, advanceSession: () => { sessionEpoch += 1; }, ...methods };
}

test('switching hotel clears the prior hotel recommendation before a deferred read', () => {
  const h = harness(async () => ({ code: 503 }));
  h.state.scope_loaded_hotel_id = '7';
  h.state.scope_recommended = { hotel_id: 7, platform: 'ctrip', date_start: '2026-09-01', date_end: '2026-09-01' };
  h.state.scope_options = [{ platform: 'ctrip' }];
  h.state.scope_notice = '酒店七最近可用范围';
  assert.equal(h.updateOperatingQuestionScope('hotel_id', '8', { load: false }), true);
  assert.equal(h.state.scope_recommended, null);
  assert.deepEqual(Array.from(h.state.scope_options), []);
  assert.equal(h.state.scope_notice, '');
  assert.equal(h.state.scope_loaded_hotel_id, '');
});

test('a late old-hotel scope response cannot restore its recommendation after hotel switch', async () => {
  const old = deferred();
  const h = harness(async () => old.promise);
  const loading = h.loadOperatingQuestionScopeOptions({ force: true });
  h.updateOperatingQuestionScope('hotel_id', '8', { load: false });
  old.resolve({ code: 200, data: { contract_version: 'operating_question_scope_options.v1',
    hotel_id: 7, data_status: 'ready', platforms: [{ platform: 'ctrip' }],
    recommended: { hotel_id: 7, platform: 'ctrip', date_start: '2026-09-01',
      date_end: '2026-09-01', verified_fact_count: 1 },
    boundary: { source_scope: 'ota_channel', strict_gate: 'dual_ota_field_closure.v1:revenue_analysis_consumable',
      fact_authority: 'trusted_ota_daily_fact_consumer.v1', silent_date_fallback: false,
      pms_included: false, whole_hotel_conclusion: false }, data_gaps: [] } });
  await loading;
  assert.equal(h.state.scope_recommended, null);
  assert.equal(h.state.scope_loaded_hotel_id, '');
  assert.equal(h.state.scope_loading, false);
});

test('wrong-hotel rows never appear in the current hotel history', async () => {
  const h = harness(async () => listing([row(601, 8)]));
  await h.loadOperatingQuestionHistory();
  assert.equal(h.state.history.length, 0);
  assert.match(h.state.history_error, /酒店|范围|身份/);
});

test('migration-required history is not presented as a true empty hotel', async () => {
  const h = harness(async () => listing([], { data_status: 'migration_required',
    data_gaps: [{ code: 'operating_question_table_missing' }] }));
  await h.loadOperatingQuestionHistory();
  assert.equal(h.state.history.length, 0);
  assert.notEqual(h.state.history_error, '');
  assert.equal(h.state.history_loaded_hotel_id, '');
});

test('old detail failure after a hotel switch cannot write an error onto the new hotel', async () => {
  const old = deferred();
  const h = harness(async () => old.promise);
  h.state.history = [row()]; h.state.history_loaded_hotel_id = '7';
  const pending = h.openOperatingQuestionHistory(row());
  h.form.hotel_id = '8';
  h.state.result = row(802, 8);
  old.reject(new Error('旧酒店合成失败'));
  await pending;
  assert.equal(h.state.error, '');
  assert.equal(h.state.result.id, 802);
});

test('newer selected saved question wins over an older pending detail', async () => {
  const old = deferred(); const newer = deferred();
  const h = harness(url => url.endsWith('/501') ? old.promise : newer.promise);
  h.state.history = [row(501), row(502)]; h.state.history_loaded_hotel_id = '7';
  const pendingOld = h.openOperatingQuestionHistory(row(501));
  const pendingNew = h.openOperatingQuestionHistory(row(502));
  newer.resolve({ code: 200, data: row(502) });
  await pendingNew;
  old.resolve({ code: 200, data: row(501) });
  await pendingOld;
  assert.equal(h.state.result?.id, 502);
  assert.equal(h.state.history_opening_id, 0);
});

test('a different same-hotel document cannot satisfy the selected list entry', async () => {
  const h = harness(async () => ({ code: 200, data: { ...row(501), question_text: '另一条问题' } }));
  h.state.history = [row(501)]; h.state.history_loaded_hotel_id = '7';
  await h.openOperatingQuestionHistory(row(501));
  assert.equal(h.state.result, null);
  assert.match(h.state.error, /回读|一致|身份/);
});

test('a valid older saved scope opens its exact answer and restores its platform and dates', async () => {
  const saved = row(503);
  saved.platform = saved.answer.scope.platform = 'meituan';
  saved.date_start = saved.answer.scope.date_start = '2026-08-01';
  saved.date_end = saved.answer.scope.date_end = '2026-08-03';
  const h = harness(async url => url.includes('?hotel_id=')
    ? listing([saved]) : { code: 200, data: saved });
  await h.loadOperatingQuestionHistory();
  const exact = await h.openOperatingQuestionHistory(saved);
  assert.equal(exact?.id, 503);
  assert.equal(h.state.result?.id, 503);
  assert.equal(h.form.platform, 'meituan');
  assert.equal(h.form.date_start, '2026-08-01');
  assert.equal(h.form.date_end, '2026-08-03');
  assert.equal(h.state.question, saved.question_text);
});

test('a later draft edit is not replaced by an older detail response', async () => {
  const pendingDetail = deferred();
  const h = harness(async () => pendingDetail.promise);
  h.state.history = [row()]; h.state.history_loaded_hotel_id = '7';
  h.state.question = '原草稿';
  const pending = h.openOperatingQuestionHistory(row());
  h.state.question = '用户后来输入的草稿';
  pendingDetail.resolve({ code: 200, data: row() });
  await pending;
  assert.equal(h.state.result, null);
  assert.equal(h.state.question, '用户后来输入的草稿');
});

test('a failed old detail does not mark a later draft as failed', async () => {
  const pendingDetail = deferred();
  const h = harness(async () => pendingDetail.promise);
  h.state.history = [row()]; h.state.history_loaded_hotel_id = '7';
  h.state.question = '原草稿';
  const pending = h.openOperatingQuestionHistory(row());
  h.state.question = '后来输入的草稿';
  pendingDetail.reject(new Error('旧回读失败'));
  await pending;
  assert.equal(h.state.question, '后来输入的草稿');
  assert.equal(h.state.error, '');
});

test('an exact question from an older login session cannot replace the new session page', async () => {
  const pendingDetail = deferred();
  const h = harness(async () => pendingDetail.promise);
  h.state.history = [row()]; h.state.history_loaded_hotel_id = '7';
  const opening = h.openOperatingQuestionHistory(row());
  h.advanceSession();
  pendingDetail.resolve({ code: 200, data: row() });
  assert.equal(await opening, null);
  assert.equal(h.state.result, null);
  assert.equal(h.state.error, '');
});

test('old-session history failure cannot mark the new session as failed', async () => {
  const pendingList = deferred();
  const h = harness(async () => pendingList.promise);
  const loading = h.loadOperatingQuestionHistory({ force: true });
  h.advanceSession();
  pendingList.reject(new Error('old session failed'));
  await loading;
  assert.equal(h.state.history_error, '');
});

test('old-session scope recommendation cannot populate the new session', async () => {
  const pendingScope = deferred();
  const h = harness(async () => pendingScope.promise);
  const loading = h.loadOperatingQuestionScopeOptions({ force: true });
  h.advanceSession();
  pendingScope.resolve({ code: 200, data: {
    contract_version: 'operating_question_scope_options.v1', hotel_id: 7,
    data_status: 'ok', platforms: ['ctrip'], recommended: { platform: 'ctrip' },
  } });
  await loading;
  assert.equal(h.state.scope_loaded_hotel_id || '', '');
  assert.equal(h.state.scope_recommended || null, null);
});

test('scope options reject incomplete success instead of presenting missing facts as true empty', async () => {
  const empty = {
    contract_version: 'operating_question_scope_options.v1', hotel_id: 7,
    data_status: 'empty', recommended: null, platforms: [],
    boundary: { source_scope: 'ota_channel', strict_gate: 'dual_ota_field_closure.v1:revenue_analysis_consumable',
      fact_authority: 'trusted_ota_daily_fact_consumer.v1', silent_date_fallback: false,
      pms_included: false, whole_hotel_conclusion: false },
    data_gaps: [{ code: 'strict_readback_fact_scope_missing' }],
  };
  const valid = harness(async () => ({ code: 200, data: empty }));
  await valid.loadOperatingQuestionScopeOptions({ force: true });
  assert.equal(valid.state.scope_data_status, 'empty');
  assert.equal(valid.state.scope_loaded_hotel_id, '7');
  const readyScope = { hotel_id: 7, platform: 'ctrip', date_start: '2026-09-01',
    date_end: '2026-09-01', verified_fact_count: 2, selection_reason: 'latest_strict_readback' };
  const ready = harness(async () => ({ code: 200, data: { ...empty, data_status: 'ready',
    recommended: readyScope, platforms: [{ platform: 'ctrip', latest_verified_date: '2026-09-01',
      verified_fact_count: 2, available_dates: ['2026-09-01'], available_date_count: 1 }], data_gaps: [] } }));
  assert.equal((await ready.loadOperatingQuestionScopeOptions({ force: true }))?.platform, 'ctrip');
  assert.equal(ready.state.scope_data_status, 'ready');
  const migration = harness(async () => ({ code: 200, data: { ...empty,
    data_status: 'migration_required', data_gaps: [{ code: 'online_daily_data_missing' }] } }));
  await migration.loadOperatingQuestionScopeOptions({ force: true });
  assert.equal(migration.state.scope_data_status, 'migration_required');

  for (const [label, payload] of [
    ['missing status', { ...empty, data_status: undefined }],
    ['missing platforms', { ...empty, platforms: undefined }],
    ['missing gaps', { ...empty, data_gaps: undefined }],
    ['wrong OTA boundary', { ...empty, boundary: { ...empty.boundary, whole_hotel_conclusion: true } }],
    ['ready without recommendation', { ...empty, data_status: 'ready' }],
  ]) {
    const candidate = harness(async () => ({ code: 200, data: payload }));
    await candidate.loadOperatingQuestionScopeOptions({ force: true });
    assert.equal(candidate.state.scope_data_status, 'error', label);
    assert.equal(candidate.state.scope_loaded_hotel_id || '', '', label);
    assert.notEqual(candidate.state.scope_error || '', '', label);
  }
});

test('opening a saved answer cannot report success after the session changes during optional council read', async () => {
  const pendingCouncil = deferred();
  const h = harness(async () => ({ code: 200, data: row() }), () => pendingCouncil.promise);
  h.state.history = [row()]; h.state.history_loaded_hotel_id = '7';
  const opening = h.openOperatingQuestionHistory(row());
  for (let n = 0; n < 8 && !h.state.result; n += 1) await Promise.resolve();
  assert.equal(h.state.result?.id, 501);
  h.advanceSession();
  pendingCouncil.resolve(null);
  assert.equal(await opening, null);
});

test('A to B to A switch invalidates the first A list even when it arrives last', async () => {
  const old = deferred(); const fresh = deferred();
  let calls = 0;
  const h = harness(async () => ++calls === 1 ? old.promise : fresh.promise);
  const first = h.loadOperatingQuestionHistory();
  h.form.hotel_id = '8'; h.invalidateOperatingQuestionHistory();
  h.form.hotel_id = '7'; h.invalidateOperatingQuestionHistory();
  const second = h.loadOperatingQuestionHistory();
  fresh.resolve(listing([row(502)])); await second;
  old.resolve(listing([row(501)])); await first;
  assert.equal(h.state.history.length, 1);
  assert.equal(h.state.history[0].id, 502);
  assert.equal(h.state.history_loaded_hotel_id, '7');
});

test('a history refresh cancels an older detail before the selected list changes', async () => {
  const oldDetail = deferred();
  const h = harness(url => url.includes('?hotel_id=')
    ? Promise.resolve(listing([row(502)])) : oldDetail.promise);
  h.state.history = [row(501)]; h.state.history_loaded_hotel_id = '7';
  const pending = h.openOperatingQuestionHistory(row(501));
  await h.loadOperatingQuestionHistory({ force: true });
  oldDetail.resolve({ code: 200, data: row(501) }); await pending;
  assert.equal(h.state.result, null);
  assert.equal(h.state.history[0].id, 502);
});

test('history panel exposes an actionable retry and lets a newer row supersede a pending row', () => {
  const componentSource = fs.readFileSync(path.join(root,
    'public/components/system/operating-intelligence-components.js'), 'utf8');
  const state = { history: [], history_error: '合成读取失败', history_loading: false,
    history_loaded_hotel_id: '', history_opening_id: 0 };
  const form = { hotel_id: '7', platform: 'ctrip', date_start: '2026-09-01',
    date_end: '2026-09-02' };
  const calls = [];
  const ui = { state: { value: state }, form: { value: form },
    selectedHotel: { value: { name: '合成酒店' } }, hotels: { value: [] },
    loadHistory: options => calls.push(options), ensureScope: () => 7 };
  const componentContext = { window: { SUXI_HOTEL_DATA_ANALYST_COMPONENTS: {
    create: () => ({ suggestions: [], createFeedbackUi: () => ({}),
      renderQualityReceipt: () => null, hotelDataAnalystProfile: {} }),
  } } };
  vm.runInNewContext(componentSource, componentContext);
  const factory = componentContext.window.SUXI_OPERATING_INTELLIGENCE_COMPONENTS_FULL;
  const h = (type, props, children) => ({ type, props: props || {}, children });
  const panel = factory.create({ ref: value => ({ value }), computed: getter => ({ get value() { return getter(); } }),
    inject: () => ui, h, nextTick: async () => {}, onMounted: () => {}, onUnmounted: () => {} })
    .operatingQuestionPanel;
  const render = panel.setup();
  const find = (node, testId) => {
    if (!node || typeof node !== 'object') return null;
    if (node.props?.['data-testid'] === testId) return node;
    for (const child of [node.children].flat(Infinity)) {
      const match = find(child, testId);
      if (match) return match;
    }
    return null;
  };
  const retry = find(render(), 'operating-question-history-retry');
  assert.ok(retry);
  retry.props.onClick();
  assert.equal(calls[0]?.force, true);
  state.history_error = ''; state.history = [row(501), row(502)];
  state.history_loaded_hotel_id = '7'; state.history_opening_id = 501;
  const tree = render();
  assert.equal(find(tree, 'operating-question-history-501').props.disabled, true);
  assert.equal(find(tree, 'operating-question-history-502').props.disabled, false);
});
