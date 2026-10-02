import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import test from 'node:test';
import { compile, computed, createSSRApp, ref } from 'vue';
import { renderToString } from '@vue/server-renderer';

const source = fs.readFileSync('public/app-main.js', 'utf8');
const operation = fs.readFileSync('public/operation-static.js', 'utf8');
const template = fs.readFileSync('resources/frontend/templates/fragments/19a-page-operation-optimizer.html', 'utf8');
const candidate = process.argv.find(value => value.startsWith('--candidate='))?.slice('--candidate='.length);
const slice = (start, end) => {
  const a = source.indexOf(start), b = source.indexOf(end, a);
  assert.ok(a >= 0 && b > a, `Source markers: ${start} / ${end}`);
  return source.slice(a, b);
};
const authHelpers = slice('const captureAuthSession =', 'const createDefaultAuthContext =');
const helpersAndLoad = slice('const operationOptimizerExecutionFlow =', 'const openOperationOptimizerRecovery =');
const openExecution = slice('const openOperationOptimizerExecution =', 'const createOperationOptimizerTask =');
const createTask = candidate ? fs.readFileSync(candidate, 'utf8') : slice('const createOperationOptimizerTask =', 'const revenueResearchStaticScript');
const createState = candidate ? fs.readFileSync(path.join(path.dirname(candidate), 'state-addition.js'), 'utf8')
  : source.match(/\blet operationOptimizerCreateRequestSeq = 0;/)?.[0] || '';
const actionButton = template.match(/<button @click="createOperationOptimizerTask\(row\.recommendation\)"[\s\S]*?<\/button>/)?.[0];
const refreshButton = template.match(/<button @click="loadOperationOptimizer\(\{ showSuccess: true \}\)"[\s\S]*?<\/button>/)?.[0];
const dateFields = template.match(/<input v-model="operationOptimizerFilter\.(?:start_date|end_date)"[^>]*>/g);
const hotelSelect = template.match(/<select v-model="operationOptimizerFilter\.hotel_id"[\s\S]*?<\/select>/)?.[0];
assert.ok(actionButton && refreshButton && hotelSelect && dateFields?.length === 2);
const renderControls = compile(`<div>${hotelSelect}${dateFields.join('')}${actionButton}${refreshButton}</div>`);
const disabledAttribute = /\sdisabled(?:\s|=|>)/;
const actionA = { id: 'a'.repeat(32), code: 'keyword_exposure', can_create_task: true, task_payload: { hotel_id: 7 } };
const actionB = { ...actionA, id: 'b'.repeat(32), code: 'room_product_mix' };
const deferred = () => { let resolve, reject; const promise = new Promise((a, b) => { resolve = a; reject = b; }); return { promise, resolve, reject }; };
const drain = async () => { for (let index = 0; index < 12; index++) await Promise.resolve(); };
const dataset = (marker = 'current') => ({ marker, keyword_workbench: { rows: [{ recommendation: actionA }, { recommendation: actionB }] }, room_product_mix: { rows: [] } });

function fixture() {
  const state = Object.fromEntries(Object.entries({
    token: 'synthetic-session-a', operationOptimizerFilter: { hotel_id: '7', start_date: '2026-09-08', end_date: '2026-09-14' },
    operationOptimizerCreatedIntents: {}, operationOptimizerCreatingActionId: '', operationOptimizerData: dataset(),
    operationOptimizerLoading: false, operationOptimizerError: '', operationFilters: { hotel_id: '7' },
    currentPage: 'operation-optimizer', revenueAiExecutionFocus: null,
  }).map(([key, value]) => [key, ref(value)]));
  state.operationOptimizerHotelOptions = ref([{ id: 7, name: '合成门店 A' }, { id: 8, name: '合成门店 B' }]);
  state.operationOptimizerKeywordRows = computed(() => state.operationOptimizerData.value?.keyword_workbench?.rows || []);
  state.operationOptimizerRoomRows = computed(() => state.operationOptimizerData.value?.room_product_mix?.rows || []);
  const posts = [], lists = [], exactReads = [], notices = [], navigations = [], intents = new Map();
  let holdList = false, holdRead = false, operationReadSucceeds = true;
  const sandbox = { window: {}, URLSearchParams, Date, ...state, authSessionEpoch: 0,
    operationOptimizerRequestSeq: 0,
    findVisibleOperationIntentRow: id => sandbox.document.querySelector(`[data-operation-execution-intent-id="${Number(id)}"]`),
    loadHotels: async () => {}, nextTick: async () => {},
    showToast: (message, type = 'success') => notices.push({ message, type }),
    loadOperationActions: async options => { navigations.push({ kind: 'execution-list', ...options }); return operationReadSucceeds; },
    document: { querySelector: selector => { navigations.push({ kind: 'visible-intent', selector }); return { scrollIntoView: () => {} }; } },
    request: async (url, options) => {
      if (options?.method === 'POST') {
        const pending = deferred(); posts.push({ url, body: JSON.parse(options.body), ...pending }); return pending.promise;
      }
      if (url.startsWith('/ota-standard/operation-optimizer?')) {
        const pending = deferred(); lists.push({ url, ...pending });
        if (holdList) holdList = false; else pending.resolve({ code: 200, data: dataset() });
        return pending.promise;
      }
      const match = url.match(/^\/operation\/execution-intents\/(\d+)(?:\?|$)/);
      assert.ok(match, `Only synthetic creation/read endpoints expected: ${url}`);
      const pending = deferred(); exactReads.push({ url, options, ...pending });
      if (holdRead) holdRead = false; else pending.resolve({ code: 200, data: intents.get(Number(match[1])) });
      return pending.promise;
    },
  };
  vm.runInNewContext(operation, sandbox);
  sandbox.readOperationExecutionIntent = (id, hotel = 0) => sandbox.window.SUXI_OPERATION_STATIC.readOperationExecutionIntent(sandbox.request, id, hotel);
  vm.runInNewContext(`${authHelpers}\n${createState}\n${helpersAndLoad}\n${openExecution}\n${createTask}\nglobalThis.methods = { createOperationOptimizerTask, openOperationOptimizerExecution, loadOperationOptimizer, operationOptimizerActionDisabled, operationOptimizerActionClass, operationOptimizerActionText };`, sandbox);
  const methods = sandbox.methods;
  const render = (recommendation = actionA) => renderToString(createSSRApp({ setup: () => ({ ...state, ...methods, row: { recommendation } }), render: renderControls }));
  // Dispatch only when the real template's disabled expression allows the click.
  const click = (recommendation = actionA) => methods.operationOptimizerActionDisabled(recommendation) ? null : methods.createOperationOptimizerTask(recommendation);
  const refresh = () => state.operationOptimizerLoading.value ? null : methods.loadOperationOptimizer({ showSuccess: true });
  const succeed = (index, overrides = {}) => {
    const post = posts[index];
    const intent = { id: 101 + index, hotel_id: post.body.system_hotel_id, source_module: 'operation_optimizer', status: 'pending_approval',
      date_start: post.body.start_date, date_end: post.body.end_date, evidence: { optimizer_action_id: post.body.recommendation_id }, ...overrides };
    intents.set(intent.id, intent);
    post.resolve({ code: 200, data: { recommendation_id: post.body.recommendation_id, execution_intent: intent, readback_status: 'readback_verified' } });
    return intent;
  };
  const newSession = () => { sandbox.authSessionEpoch++; state.token.value = 'synthetic-session-b'; };
  return { state, posts, lists, exactReads, notices, navigations, render, click, refresh, succeed, newSession,
    holdNextList: () => { holdList = true; }, holdNextRead: () => { holdRead = true; }, setOperationReadSuccess: value => { operationReadSucceeds = value; }, openExecution: methods.openOperationOptimizerExecution,
    resetHotelScope: hotelId => {
      state.operationOptimizerFilter.value.hotel_id = String(hotelId);
      // Same direct optimizer resets as resetUnifiedHotelScopedResults; no real session/storage is loaded.
      sandbox.operationOptimizerRequestSeq++;
      state.operationOptimizerData.value = null; state.operationOptimizerLoading.value = false; state.operationOptimizerError.value = '';
    },
  };
}

test('optimizer saved intent does not scroll or claim entry when operations read is partial', async () => {
  const f = fixture();
  f.state.operationOptimizerCreatedIntents.value[actionA.id] = 101;
  f.setOperationReadSuccess(false);
  assert.equal(await f.openExecution(actionA), false);
  assert.equal(f.navigations.some(item => item.kind === 'visible-intent'), false);
  f.setOperationReadSuccess(true);
  assert.equal(await f.openExecution(actionA), true);
  assert.equal(f.navigations.filter(item => item.kind === 'visible-intent').length, 1);
});

test('optimizer creation keeps the verified saved ID without claiming entry after a partial operations read', async () => {
  const f = fixture();f.setOperationReadSuccess(false);
  const pending = f.click();f.succeed(0);
  assert.equal(await pending,101);
  assert.equal(f.state.operationOptimizerCreatedIntents.value[actionA.id],101);
  assert.match(f.notices[0].message,/已生成待审批任务 #101/);
  assert.doesNotMatch(f.notices[0].message,/进入执行/);
  assert.equal(f.navigations.some(item=>item.kind==='visible-intent'),false);
});

test('real recommendation button preserves evidence gate and suppresses repeated pending clicks', async () => {
  const f = fixture(), blocked = { ...actionA, can_create_task: false };
  assert.match((await f.render(blocked)).match(/<button\b[^>]*>/)[0], disabledAttribute);
  assert.equal(f.click(blocked), null); assert.equal(f.posts.length, 0);
  const pending = f.click(); const html = await f.render();
  assert.match(html.match(/<button\b[^>]*>/)[0], disabledAttribute);
  assert.doesNotMatch(html, /<(?:input|select)\b[^>]*\s(?:disabled|readonly)(?:\s|=|>)/);
  assert.equal(f.click(), null); assert.equal(f.posts.length, 1);
  f.posts[0].reject(new Error('合成中断')); await pending;
});

test('current scope retains exact receipt and normal refresh/navigation without approval or execution', async () => {
  const f = fixture(), pending = f.click(); f.succeed(0); assert.equal(await pending, 101);
  assert.equal(f.state.operationOptimizerCreatedIntents.value[actionA.id], 101);
  assert.equal(f.state.currentPage.value, 'ops-track'); assert.equal(f.state.revenueAiExecutionFocus.value.intentId, 101);
  assert.equal(f.lists.length, 1); assert.match(f.notices[0].message, /待审批任务 #101/);
  assert.equal(f.posts[0].body.recommendation_id, actionA.id);
  assert.doesNotMatch(JSON.stringify([...f.posts, ...f.exactReads].map(item => item.url)), /\/approve\b|\/execute\b/);
});

for (const order of ['refresh-first', 'receipt-first']) {
  test(`date selection and real refresh retain new scope when old receipt arrives: ${order}`, async () => {
    const f = fixture(), pending = f.click();
    f.state.operationOptimizerFilter.value.end_date = '2026-09-15';
    const html = await f.render(); assert.match(html, /value="2026-09-15"/);
    assert.doesNotMatch(html.match(/<button\b[^>]*data-testid="operation-optimizer-refresh"[^>]*>/)[0], disabledAttribute);
    f.holdNextList(); const refreshing = f.refresh(); await drain();
    if (order === 'refresh-first') { f.lists[0].resolve({ code: 200, data: dataset('new-date') }); await refreshing; }
    f.succeed(0); assert.equal(await pending, 101);
    if (order === 'receipt-first') { f.lists[0].resolve({ code: 200, data: dataset('new-date') }); await refreshing; }
    assert.equal(f.state.currentPage.value, 'operation-optimizer'); assert.equal(f.state.revenueAiExecutionFocus.value, null);
    assert.equal(f.state.operationOptimizerData.value.marker, 'new-date'); assert.equal(f.lists.length, 1);
    assert.equal(f.state.operationOptimizerCreatedIntents.value[actionA.id], 101);
    assert.equal(f.notices.filter(item => /任务 #101/.test(item.message)).length, 0);
    assert.equal(f.posts[0].body.end_date, '2026-09-14'); assert.equal(f.state.operationOptimizerFilter.value.end_date, '2026-09-15');
  });
}

test('saved old-date receipt remains available through the existing original recommendation button', async () => {
  const f = fixture(), pending = f.click(); f.state.operationOptimizerFilter.value.end_date = '2026-09-15';
  f.succeed(0); await pending;
  assert.equal(f.state.currentPage.value, 'operation-optimizer');
  f.state.operationOptimizerFilter.value.end_date = '2026-09-14'; await f.refresh();
  // This synthetic list omits backend flow hydration; the existing cached-ID entry must still open.
  assert.equal(f.state.operationOptimizerCreatedIntents.value[actionA.id], 101);
  assert.match(await f.render(), /进入/);
  assert.equal(await f.click(), 101); assert.equal(f.posts.length, 1);
  assert.equal(f.state.currentPage.value, 'ops-track'); assert.equal(f.state.revenueAiExecutionFocus.value.intentId, 101);
});

test('changed hotel pins exact readback to the original hotel and preserves the new view', async () => {
  const f = fixture(), pending = f.click(); f.resetHotelScope(8); await f.refresh(); f.succeed(0); await pending;
  assert.equal(f.exactReads.length, 1);
  assert.equal(f.exactReads[0].url, '/operation/execution-intents/101?hotel_id=7&system_hotel_id=7');
  assert.equal(f.exactReads[0].options.businessContext.hotelId, 7);
  assert.equal(f.state.currentPage.value, 'operation-optimizer'); assert.equal(f.state.operationOptimizerFilter.value.hotel_id, '8');
  assert.equal(f.lists.length, 1); assert.equal(f.state.operationOptimizerCreatedIntents.value[actionA.id], 101);
});

test('leaving the optimizer while POST is pending does not redirect the new page', async () => {
  const f = fixture(), pending = f.click(); f.state.currentPage.value = 'revenue'; f.succeed(0); await pending;
  assert.equal(f.state.currentPage.value, 'revenue'); assert.equal(f.lists.length, 0); assert.equal(f.navigations.length, 0);
  assert.equal(f.notices.length, 0); assert.equal(f.state.operationOptimizerCreatedIntents.value[actionA.id], 101);
});

test('session replacement before POST response does not read or cache into the new session', async () => {
  const f = fixture(), pending = f.click(); f.newSession(); f.succeed(0); await pending;
  assert.equal(f.exactReads.length, 0); assert.equal(f.lists.length, 0); assert.equal(f.notices.length, 0);
  assert.equal(f.state.operationOptimizerCreatedIntents.value[actionA.id], undefined); assert.equal(f.state.currentPage.value, 'operation-optimizer');
});

test('session replacement during exact readback does not cache or navigate in the new session', async () => {
  const f = fixture(); f.holdNextRead(); const pending = f.click(); const intent = f.succeed(0); await drain();
  assert.equal(f.exactReads.length, 1); f.newSession(); f.exactReads[0].resolve({ code: 200, data: intent }); await pending;
  assert.equal(f.state.operationOptimizerCreatedIntents.value[actionA.id], undefined); assert.equal(f.lists.length, 0); assert.equal(f.navigations.length, 0);
});

test('same-scope GET refresh does not cancel a valid creation', async () => {
  const f = fixture(), pending = f.click(); await f.refresh(); f.succeed(0); assert.equal(await pending, 101);
  assert.equal(f.state.currentPage.value, 'ops-track'); assert.equal(f.state.operationOptimizerCreatedIntents.value[actionA.id], 101);
});

test('scope change during post-save refresh prevents late navigation while preserving verified receipt', async () => {
  const f = fixture(); f.holdNextList(); const pending = f.click(); f.succeed(0); await drain();
  assert.equal(f.lists.length, 1); f.state.currentPage.value = 'revenue';
  f.lists[0].resolve({ code: 200, data: dataset() }); assert.equal(await pending, 101);
  assert.equal(f.state.currentPage.value, 'revenue'); assert.equal(f.navigations.length, 0); assert.equal(f.notices.length, 0);
  assert.equal(f.state.operationOptimizerCreatedIntents.value[actionA.id], 101);
});

test('older action completion does not clear the new action lock or steal its navigation', async () => {
  const f = fixture(), first = f.click(actionA), second = f.click(actionB);
  assert.equal(f.posts.length, 2); assert.match((await f.render(actionB)).match(/<button\b[^>]*>/)[0], disabledAttribute);
  f.succeed(0); assert.equal(await first, 101);
  assert.equal(f.state.operationOptimizerCreatingActionId.value, actionB.id);
  assert.equal(f.click(actionB), null); assert.equal(f.posts.length, 2); assert.equal(f.lists.length, 0); assert.equal(f.navigations.length, 0);
  f.succeed(1); assert.equal(await second, 102);
  assert.equal(f.state.operationOptimizerCreatingActionId.value, ''); assert.equal(f.state.revenueAiExecutionFocus.value.intentId, 102);
  assert.equal(f.state.operationOptimizerCreatedIntents.value[actionA.id], 101); assert.equal(f.state.operationOptimizerCreatedIntents.value[actionB.id], 102);
});

test('editing the date during the post-create GET prevents navigation and leaves manual refresh usable', async () => {
  const f = fixture(); f.holdNextList(); const pending = f.click(); f.succeed(0); await drain();
  assert.equal(f.lists.length, 1);
  f.state.operationOptimizerFilter.value.end_date = '2026-09-15';
  // The existing refresh button is disabled while this GET is pending: no second GET is dispatched yet.
  assert.equal(f.refresh(), null);
  f.lists[0].resolve({ code: 200, data: dataset('original-date') }); await pending;
  assert.equal(f.state.currentPage.value, 'operation-optimizer'); assert.equal(f.navigations.length, 0);
  await f.refresh();
  assert.equal(f.lists.length, 2); assert.match(f.lists[1].url, /end_date=2026-09-15/);
  assert.equal(f.state.operationOptimizerCreatedIntents.value[actionA.id], 101);
});

test('an older receipt arriving after the new action navigated cannot focus the old action', async () => {
  const f = fixture(), first = f.click(actionA), second = f.click(actionB);
  f.succeed(1); assert.equal(await second, 102); const navigationCount = f.navigations.length;
  f.succeed(0); assert.equal(await first, 101);
  assert.equal(f.state.revenueAiExecutionFocus.value.intentId, 102); assert.equal(f.navigations.length, navigationCount);
  assert.equal(f.lists.length, 1); assert.equal(f.state.operationOptimizerCreatedIntents.value[actionA.id], 101);
  assert.equal(f.notices.filter(item => /任务 #101/.test(item.message)).length, 0);
});

test('late old-session finally cannot unlock a new-session create', async () => {
  const f = fixture(), first = f.click(); f.newSession(); const second = f.click(actionB);
  f.posts[0].reject(new Error('旧会话合成中断')); await first;
  assert.equal(f.state.operationOptimizerCreatingActionId.value, actionB.id); assert.equal(f.notices.length, 0);
  f.succeed(1); assert.equal(await second, 102); assert.equal(f.state.revenueAiExecutionFocus.value.intentId, 102);
});

test('current transport failure preserves the recommendation and allows an explicit retry', async () => {
  const f = fixture(), pending = f.click(); f.posts[0].reject(new Error('合成网络中断')); assert.equal(await pending, null);
  assert.equal(f.state.operationOptimizerData.value.marker, 'current'); assert.equal(f.state.operationOptimizerCreatingActionId.value, '');
  assert.equal(f.state.currentPage.value, 'operation-optimizer'); assert.match(f.notices[0].message, /合成网络中断/);
  const retry = f.click(); assert.equal(f.posts.length, 2); assert.deepEqual(f.posts[1].body, f.posts[0].body);
  f.succeed(1); assert.equal(await retry, 102);
});

test('stale-scope failure does not show an unrelated error in the new scope', async () => {
  const f = fixture(), pending = f.click(); f.state.operationOptimizerFilter.value.end_date = '2026-09-15';
  f.posts[0].reject(new Error('旧日期合成失败')); assert.equal(await pending, null);
  assert.equal(f.notices.length, 0); assert.equal(f.state.operationOptimizerCreatingActionId.value, '');
  assert.equal(f.state.operationOptimizerData.value.marker, 'current');
});

test('exact readback failure does not invent a verified receipt or prevent retry', async () => {
  const f = fixture(); f.holdNextRead(); const pending = f.click(); f.succeed(0); await drain();
  f.exactReads[0].reject(new Error('合成精确回读中断')); assert.equal(await pending, null);
  assert.equal(f.state.operationOptimizerCreatedIntents.value[actionA.id], undefined); assert.equal(f.state.currentPage.value, 'operation-optimizer');
  assert.equal(f.state.operationOptimizerCreatingActionId.value, ''); assert.match(f.notices[0].message, /精确回读中断/);
  assert.equal(f.lists.length, 0);
});

test('mismatched readback hotel remains an explicit failure without caching or navigation', async () => {
  const f = fixture(), pending = f.click(); f.succeed(0, { hotel_id: 8 }); assert.equal(await pending, null);
  assert.equal(f.state.operationOptimizerCreatedIntents.value[actionA.id], undefined);
  assert.equal(f.lists.length, 0); assert.equal(f.navigations.length, 0); assert.equal(f.notices[0].type, 'error');
  assert.equal(f.state.operationOptimizerCreatingActionId.value, '');
});
