import assert from 'node:assert/strict';
import test from 'node:test';
import fs from 'node:fs';
import vm from 'node:vm';
import { createHash, webcrypto } from 'node:crypto';
import * as Vue from 'vue';
import { compile } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';

const folder = 'output/autonomous-verification/20260915-round34/';
const source = fs.readFileSync(process.env.SUXI_GROWTH_MAIN_SOURCE || 'public/app-main.js', 'utf8');
const staticSource = fs.readFileSync(process.env.SUXI_GROWTH_STATIC_SOURCE || 'public/operating-growth-static.js', 'utf8');
const systemSource = fs.readFileSync('public/system-static.js', 'utf8');
const template = fs.readFileSync('resources/frontend/templates/fragments/17a-page-operating-growth-archive.html', 'utf8');
const slice = (a, b) => { const start = source.indexOf(a), end = source.indexOf(b, start); assert.ok(start >= 0 && end > start); return source.slice(start, end); };
const domain = slice('const operatingGrowthStaticScript =', 'const addOperatingGrowthAnnotation =');
const dialog = slice('const createWorkflowFormDialogState =', 'let runtimeErrorRecoveryQueued =');
const listenerCode = slice('const operatingGrowthArchiveListeners =', 'let operatingMemoryRequestSeq =');
const requests = slice('const COORDINATED_GET_MAX_CONCURRENCY = 3;', 'const askSystemUsageGuide =');
const business = slice('const BUSINESS_CONTEXT_ENDPOINT_PREFIXES =', 'const userHasPermission =');
const policy = slice('const currentPageReadPolicy =', 'const cancelPageLoadRequests =');
const syncHotel = slice('const syncUnifiedHotelContexts =', '            return {');
const resetGrowth = slice('operatingGrowthRequestSeq += 1;', 'operationEffectValidation.value =');
const auth = slice('const captureAuthSession =', 'const createDefaultAuthContext =');
const renderPage = new Function('Vue', compile(template, { mode: 'function', prefixIdentifiers: true }).code)(Vue);
const clone = value => JSON.parse(JSON.stringify(value));
const deferred = () => { let resolve, reject; const promise = new Promise((a, b) => { resolve = a; reject = b; }); return { promise, resolve, reject }; };
const flush = async () => { await new Promise(resolve => setTimeout(resolve, 0)); await Vue.nextTick(); };
const bodyResponse = (body, status = Number(body.code || 200)) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
const sha = value => createHash('sha256').update(value).digest('hex');
function flatten(node) { return !node || typeof node !== 'object' ? [] : [node, ...(Array.isArray(node.children) ? node.children.flatMap(flatten) : [])]; }
function content(node) { return typeof node?.children === 'string' ? node.children : (Array.isArray(node?.children) ? node.children.map(content).join('') : ''); }
let assertionCount = 0;
const equal = (actual, expected, label) => { assert.equal(actual, expected, label); assertionCount++; };
const matches = (actual, expected) => { assert.match(actual, expected); assertionCount++; };

function fixture() {
  const posts = [], gets = [], notices = [], tasks = [], timeline = [], persisted = new Map(), keys = new Map();
  const operationFilters = Vue.ref({ hotel_id: '7' });
  const operationHotelOptions = Vue.ref([{ id: 7, name: '合成门店七' }, { id: 8, name: '合成门店八' }]);
  const currentPage = Vue.ref('operating-growth-archive');
  let wrongDigest = false, tree, readFailure = null, timelineFailure = false, pauseTimeline = false, ignoreTimelineAbort = false, receiptTransform = null;
  const sandbox = {
    window: { Vue }, crypto: webcrypto, URL, URLSearchParams, Date, JSON, ...Vue, currentPage, operationFilters, operationHotelOptions,
    AbortController, Headers, structuredClone,
    token: Vue.ref('synthetic-only-session'), authSessionEpoch: 0, API_BASE: 'https://synthetic.invalid/api',
    authContext: Vue.ref({ permissionStatus: 'allowed', tenantId: '5', hotelId: '7' }),
    user: Vue.ref({ id: 42, hotel_id: 7, tenant_id: 5, is_super_admin: true }),
    filterReportHotel: Vue.ref('7'), permittedHotels: operationHotelOptions,
    revenueAiBusinessDate: Vue.ref(''), coreOperationsTargetDate: Vue.ref(''), pageRequestGeneration: 1,
    reportHotelOptionExists: id => operationHotelOptions.value.some(item => String(item.id) === String(id)),
    createRequestAbortError: (message = 'Request aborted') => Object.assign(new Error(message), { name: 'AbortError' }),
    formatDate: value => [value.getFullYear(), String(value.getMonth() + 1).padStart(2, '0'), String(value.getDate()).padStart(2, '0')].join('-'),
    operationErrorMessage: (error, fallback) => String(error?.message || fallback),
    showToast: (message, type = 'success') => notices.push({ message, type }),
    isTerminalAuthFailureResponse: () => false,
    console: { error() {} }, openOperatingGrowthSource() {}, addOperatingGrowthAnnotation() {}, setOperatingGrowthMilestone() {},
    fetch: async (fullUrl, options = {}) => {
      assert.ok(fullUrl.startsWith(sandbox.API_BASE)); const url = fullUrl.slice(sandbox.API_BASE.length);
      if (options.method === 'POST') {
        assert.equal(url, '/operation/growth-archive/events');
        const pending = deferred(); posts.push({ url, options, body: JSON.parse(options.body), ...pending }); return pending.promise;
      }
      gets.push({ url, options });
      const parsed = new URL(url, sandbox.API_BASE); const hotelId = Number(parsed.searchParams.get('hotel_id'));
      if (parsed.pathname === '/operation/growth-archive/timeline') {
        if (timelineFailure) return bodyResponse({ code: 503, message: '合成列表刷新失败' }, 503);
        const list = [...persisted.values()].filter(item => item.hotel_id === hotelId);
        const response = bodyResponse({ code: 200, data: { data_status: 'ok', hotel_id: hotelId,
          date_start: parsed.searchParams.get('date_start'), date_end: parsed.searchParams.get('date_end'),
          list, count: list.length, returned_count: list.length, matched_total: list.length, truncated: false,
          overview: { archive_count: list.length, completed_review_count: 0, observing_count: 0, repeated_problem_count: null }, data_gaps: [] } });
        if (!pauseTimeline) return response;
        const delayed = deferred(); timeline.push({ ...delayed, response });
        if (!ignoreTimelineAbort) options.signal?.addEventListener('abort', () => delayed.reject(Object.assign(new Error('Synthetic abort'), { name: 'AbortError' })), { once: true });
        return delayed.promise;
      }
      const match = parsed.pathname.match(/^\/operation\/operating-memories\/(\d+)$/); assert.ok(match, url);
      const memory = persisted.get(Number(match[1])); assert.ok(memory); assert.equal(hotelId, memory.hotel_id);
      if (readFailure) return bodyResponse(readFailure.body, readFailure.status);
      // GET read() uses normalizeRow; these fields belong only to normalizeGrowthRow on write/timeline.
      const readback = { ...memory };
      for (const key of ['event_kind', 'parent_memory_id', 'is_owner_annotation', 'is_milestone', 'source_reference']) delete readback[key];
      return bodyResponse({ code: 200, data: { ...readback, content_digest: wrongDigest ? 'synthetic-wrong-digest' : memory.content_digest } });
    },
  };
  vm.runInNewContext(systemSource, sandbox);
  vm.runInNewContext(staticSource, sandbox);
  const api = sandbox.window.SUXI_OPERATING_GROWTH_STATIC;
  const originalRender = api.OperatingGrowthArchive.render;
  api.OperatingGrowthArchive.render = function (...args) { tree = originalRender.apply(this, args); return tree; };
  vm.runInNewContext(`const appSystemStatic = window.SUXI_SYSTEM_STATIC;
    const requireAppSystemStatic = key => appSystemStatic[key];
    const readRequestCooldown = appSystemStatic.createReadRequestCooldown();
    ${auth}\n${business}\n${policy}\n${requests}\n${dialog}\n${domain}\n${listenerCode}
    const unifiedHotelContextBindings = [{ key: 'operations', pages: ['ops-source', 'ops-analysis', 'ops-insight', 'ops-track', 'operating-growth-archive'], read: () => operationFilters.value.hotel_id, write: hotelId => { operationFilters.value.hotel_id = hotelId; } }];
    let unifiedHotelContextSyncing = false;
    const resetUnifiedHotelScopedResults = () => { ${resetGrowth} };
    ${syncHotel}
    globalThis.ui = { operatingGrowthArchiveBody, operatingGrowthArchiveBindings, operatingGrowthArchiveListeners, operatingGrowthEventDraft, operatingGrowthSaving, operatingGrowthShowEventForm, operatingGrowthArchivePayload, operatingGrowthError, operatingGrowthArchiveModel, ensureOperatingGrowthStaticReady };`, sandbox);
  const ui = sandbox.ui;
  // Observe promises through the actual parent listeners; child $emit and the real fragment remain in the path.
  for (const [event, listener] of Object.entries(ui.operatingGrowthArchiveListeners)) {
    ui.operatingGrowthArchiveListeners[event] = (...args) => {
      const result = listener(...args);
      if (result && typeof result.then === 'function') tasks.push({ event, promise: result });
      return result;
    };
  }
  async function render() {
    await ui.ensureOperatingGrowthStaticReady();
    return renderToString(Vue.createSSRApp({ setup: () => ({ currentPage, ...ui }), render: renderPage }));
  }
  const find = predicate => { const found = flatten(tree).find(predicate); assert.ok(found, 'Actual component control exists'); return found; };
  async function click(kind) {
    await render();
    const button = kind === 'open' ? find(node => node.props?.['data-testid'] === 'operating-growth-create')
      : find(node => node.type === 'button' && (kind === 'submit' ? /保存并严格回读|正在保存并回读|确认上次保存|正在确认上次保存/.test(content(node)) : /重新读取/.test(content(node))));
    if (button.props.disabled) return { disabled: true, done: Promise.resolve() };
    const before = tasks.length; button.props.onClick();
    return { disabled: false, done: tasks.length > before ? tasks.at(-1).promise : Promise.resolve() };
  }
  async function edit(label, value) {
    await render();
    const fieldLabel = find(node => node.type === 'label' && content(node).startsWith(label));
    const field = flatten(fieldLabel).find(node => ['input', 'textarea', 'select'].includes(node.type));
    assert.ok(field && !field.props.disabled && !field.props.readonly, 'Actual editable field: ' + label);
    (field.props.onInput || field.props.onChange)({ target: { value } }); await Vue.nextTick();
  }
  async function fill(title = '合成事件 A', text = '合成事实 A') {
    await edit('标题', title); await edit('事实描述', text);
  }
  async function changeHotel(id) {
    await render(); const control = find(node => node.props?.['data-testid'] === 'operating-growth-hotel');
    assert.equal(Boolean(control.props.disabled), false); const before = tasks.length;
    control.props.onChange({ target: { value: String(id) } });
    if (tasks.length > before) await tasks.at(-1).promise;
  }
  const commit = (index, respond = true) => {
    const post = posts[index], { client_request_id: requestId, ...payload } = post.body;
    const digest = sha(JSON.stringify(payload)), key = post.body.hotel_id + ':' + requestId;
    const old = keys.get(key);
    if (old && old.content_digest !== digest) {
      if (respond) post.resolve(bodyResponse({ code: 500, message: 'growth archive idempotency key conflicts with different content' }, 500));
      return null;
    }
    const memory = old || { ...payload, id: 101 + persisted.size, content_digest: digest, quality_status: 'unverified', usage_level: 'archive_only',
      event_kind: payload.event_kind, source_module: 'operating_growth_archive', source_record_type: 'manual_operating_event',
      context: { event_kind: payload.event_kind, owner_judgement: payload.owner_judgement || null, verification_status: 'unverified', manual_record: true }, evidence_refs: [] };
    keys.set(key, memory); persisted.set(memory.id, memory);
    const receipt = { memory, persistence_status: 'readback_verified', created: !old, write_boundaries: { ota_write: false, external_message: false } };
    if (respond) post.resolve(bodyResponse({ code: 200, data: receiptTransform ? receiptTransform(clone(receipt)) : receipt }));
    return memory;
  };
  return { ui, operationFilters, posts, gets, notices, persisted, render, click, edit, fill, changeHotel, find, commit, timeline,
    pauseTimeline: value => { pauseTimeline = value; },
    ignoreTimelineAbort: value => { ignoreTimelineAbort = value; }, transformReceipt: value => { receiptTransform = value; },
    setReadFailure: value => { readFailure = value; }, setTimelineFailure: value => { timelineFailure = value; }, currentPage, token: sandbox.token, setWrongDigest: value => { wrongDigest = value; } };
}


test('normal real submit strictly reads and refreshes the saved manual event; duplicate button stays disabled', async () => {
  const f = fixture(); await f.click('open'); await f.fill(); const a = await f.click('submit'); await flush();
  equal((await f.click('submit')).disabled, true); equal(f.posts.length, 1);
  f.commit(0); await a.done;
  equal(f.ui.operatingGrowthShowEventForm.value, false); equal(f.ui.operatingGrowthEventDraft.value.title, '');
  equal(f.ui.operatingGrowthSaving.value, false); equal(f.ui.operatingGrowthArchivePayload.value.list[0].id, 101);
  equal(f.persisted.get(101).quality_status, 'unverified'); equal(f.persisted.get(101).usage_level, 'archive_only');
});

test('strictly saved event with failed timeline reports saved but asks to reread the list', async () => {
  const f = fixture(); await f.click('open'); await f.fill();
  const submit = await f.click('submit'); await flush();
  f.setTimelineFailure(true); f.commit(0); await submit.done;
  equal(f.persisted.size, 1);
  equal(f.ui.operatingGrowthShowEventForm.value, false);
  equal(f.ui.operatingGrowthArchivePayload.value.list.length, 0);
  equal(f.notices.at(-1).type, 'warning');
  matches(f.notices.at(-1).message, /已保存并完成严格回读.*列表刷新失败/);
});

for (const refresh of [false, true]) test('old A success preserves edited B and gives its next manual submission a fresh ID; refresh=' + refresh, async () => {
  const f = fixture(); await f.click('open'); await f.fill(); const a = await f.click('submit'); await flush();
  await f.fill('新稿 B', '新事实 B');
  if (refresh) { const read = await f.click('refresh'); await read.done; }
  f.commit(0); await a.done;
  equal(f.ui.operatingGrowthEventDraft.value.title, '新稿 B'); equal(f.ui.operatingGrowthShowEventForm.value, true);
  equal(f.ui.operatingGrowthArchivePayload.value.list[0].id, 101); equal(f.posts.length, 1);
  assert.notEqual(f.ui.operatingGrowthEventDraft.value.clientRequestId, f.posts[0].body.client_request_id);
  matches(f.notices.at(-1).message, /新修改.*未保存/);
  const b = await f.click('submit'); await flush(); equal(f.posts[1].body.title, '新稿 B');
  assert.notEqual(f.posts[1].body.client_request_id, f.posts[0].body.client_request_id);
  f.commit(1); await b.done; equal(f.persisted.size, 2); equal(f.ui.operatingGrowthSaving.value, false);
});

for (const changed of [false, true]) test('unknown committed A is confirmed alone; edits=' + changed, async () => {
  const f = fixture(); await f.click('open'); await f.fill(); const a = await f.click('submit'); await flush();
  f.commit(0, false); f.posts[0].reject(new Error('合成已提交但丢回执')); await a.done;
  if (changed) await f.fill('新稿 B', '新事实 B');
  matches(await f.render(), /确认上次保存/); matches(await f.render(), /新修改.*保留/);
  const again = await f.click('submit'); await flush(); equal((await f.click('submit')).disabled, true);
  equal(f.posts[1].url, f.posts[0].url); equal(f.posts[1].options.body, f.posts[0].options.body);
  f.commit(1); await again.done; equal(f.persisted.size, 1); equal(f.posts.length, 2);
  equal(f.ui.operatingGrowthShowEventForm.value, changed);
  equal(f.ui.operatingGrowthEventDraft.value.title, changed ? '新稿 B' : '');
  if (changed) {
    const b = await f.click('submit'); await flush(); equal(f.posts[2].body.title, '新稿 B');
    assert.notEqual(f.posts[2].body.client_request_id, f.posts[0].body.client_request_id);
    f.commit(2); await b.done; equal(f.persisted.size, 2);
  }
});

test('confirmation failures and edits during confirmation keep original A and current C separately', async () => {
  const f = fixture(); await f.click('open'); await f.fill(); const a = await f.click('submit'); await flush();
  f.commit(0, false); f.posts[0].reject(new Error('合成未知')); await a.done; await f.fill('新稿 B', '新事实 B');
  const retry = await f.click('submit'); await flush();
  f.posts[1].resolve(bodyResponse({ code: 422, message: '500 transport status wins' }, 500)); await retry.done;
  matches(await f.render(), /确认上次保存/); equal(f.ui.operatingGrowthEventDraft.value.title, '新稿 B');
  const confirm = await f.click('submit'); await flush(); await f.fill('新稿 C', '新事实 C');
  equal(f.posts[2].options.body, f.posts[0].options.body); f.commit(2); await confirm.done;
  equal(f.ui.operatingGrowthEventDraft.value.title, '新稿 C'); equal(f.posts.length, 3); equal(f.persisted.size, 1);
});

test('a first explicit write 422 keeps B and allows its revised next payload instead of a confirmation trap', async () => {
  const f = fixture(); await f.click('open'); await f.fill(); const a = await f.click('submit'); await flush();
  await f.fill('修订稿 B', '修订事实 B'); f.posts[0].resolve(bodyResponse({ code: 422, message: '明确写前拒绝' }, 422)); await a.done;
  equal(f.ui.operatingGrowthEventDraft.value.title, '修订稿 B'); assert.doesNotMatch(await f.render(), /确认上次保存/);
  const b = await f.click('submit'); await flush(); equal(f.posts[1].body.title, '修订稿 B'); f.commit(1); await b.done;
  equal(f.persisted.size, 1);
});

test('the first HTTP 500 with body code 422 is unknown and cannot release original A', async () => {
  const f = fixture(); await f.click('open'); await f.fill(); const a = await f.click('submit'); await flush();
  f.commit(0, false); f.posts[0].resolve(bodyResponse({ code: 422, message: '合成 HTTP 500' }, 500)); await a.done;
  await f.fill('B', 'B事实'); matches(await f.render(), /确认上次保存/);
  const confirm = await f.click('submit'); await flush(); equal(f.posts[1].options.body, f.posts[0].options.body);
  f.commit(1); await confirm.done; equal(f.persisted.size, 1); equal(f.ui.operatingGrowthEventDraft.value.title, 'B');
});

test('a transport failure before commit also confirms only original A and leaves B for a later click', async () => {
  const f = fixture(); await f.click('open'); await f.fill(); const a = await f.click('submit'); await flush();
  f.posts[0].reject(new Error('合成传输中断，尚未提交')); await a.done; await f.fill('B', 'B事实');
  const confirm = await f.click('submit'); await flush(); equal(f.posts[1].options.body, f.posts[0].options.body);
  f.commit(1); await confirm.done; equal(f.persisted.size, 1); equal(f.persisted.get(101).title, '合成事件 A');
  equal(f.ui.operatingGrowthEventDraft.value.title, 'B'); equal(f.posts.length, 2);
});

test('a later confirmation rejection does not prove the original unknown A was never committed', async () => {
  const f = fixture(); await f.click('open'); await f.fill(); const a = await f.click('submit'); await flush();
  f.commit(0, false); f.posts[0].reject(new Error('合成已提交，未知')); await a.done; await f.fill('B', 'B事实');
  const confirm = await f.click('submit'); await flush();
  f.posts[1].resolve(bodyResponse({ code: 422, message: '本次确认被拒绝，不证明上次未写' }, 422)); await confirm.done;
  matches(await f.render(), /确认上次保存/); equal(f.ui.operatingGrowthEventDraft.value.title, 'B');
  const again = await f.click('submit'); await flush(); equal(f.posts[2].options.body, f.posts[0].options.body);
  f.commit(2); await again.done; equal(f.persisted.size, 1); equal(f.ui.operatingGrowthEventDraft.value.title, 'B');
});

for (const failure of ['digest', 'read-422', 'read-500']) test('strict GET failure keeps A pending even without new edits: ' + failure, async () => {
  const f = fixture(); await f.click('open'); await f.fill(); const a = await f.click('submit'); await flush();
  if (failure === 'digest') f.setWrongDigest(true); else f.setReadFailure({ body: { code: Number(failure.slice(5)), message: '合成 GET 失败' }, status: Number(failure.slice(5)) });
  f.commit(0); await a.done; matches(await f.render(), /确认上次保存/); equal(f.ui.operatingGrowthEventDraft.value.title, '合成事件 A');
  f.setWrongDigest(false); f.setReadFailure(null); const confirm = await f.click('submit'); await flush();
  equal(f.posts[1].options.body, f.posts[0].options.body); f.commit(1); await confirm.done;
  equal(f.persisted.size, 1); equal(f.ui.operatingGrowthShowEventForm.value, false);
});

for (const transition of ['hotel', 'auth']) test('old scope response cannot clear the new form or release its saving lock: ' + transition, async () => {
  const f = fixture(); await f.click('open'); await f.fill(); const a = await f.click('submit'); await flush();
  if (transition === 'hotel') await f.changeHotel(8);
  else { f.token.value = 'synthetic-next-session'; await Vue.nextTick(); }
  await f.click('open'); await f.fill('新范围 B', '新范围事实 B'); const b = await f.click('submit'); await flush();
  equal(b.disabled, false); equal(f.posts.length, 2);
  f.commit(0); await a.done; equal(f.ui.operatingGrowthEventDraft.value.title, '新范围 B'); equal(f.ui.operatingGrowthSaving.value, true);
  equal(f.gets.some(row => row.url.includes('/operating-memories/101?')), false);
  f.commit(1); await b.done; equal(f.ui.operatingGrowthSaving.value, false);
  equal(f.ui.operatingGrowthArchivePayload.value.list.some(row => row.title === '新范围 B'), true);
  if (transition === 'hotel') equal(f.ui.operatingGrowthArchivePayload.value.list.every(row => row.hotel_id === 8), true);
});

for (const oldCompletesAway of [false, true]) test('page away/back retains A confirmation and B, and an old finally cannot release its confirmation lock; oldCompleted=' + oldCompletesAway, async () => {
  const f = fixture(); await f.click('open'); await f.fill(); const a = await f.click('submit'); await flush(); await f.fill('B', 'B事实');
  f.currentPage.value = 'compass'; await Vue.nextTick();
  if (oldCompletesAway) { f.commit(0); await a.done; }
  f.currentPage.value = 'operating-growth-archive'; await Vue.nextTick();
  equal(f.ui.operatingGrowthEventDraft.value.title, 'B'); matches(await f.render(), /确认上次保存/);
  const confirm = await f.click('submit'); await flush(); equal(confirm.disabled, false);
  equal(f.posts[1].options.body, f.posts[0].options.body);
  if (!oldCompletesAway) { f.commit(0); await a.done; equal(f.ui.operatingGrowthSaving.value, true); }
  f.commit(1); await confirm.done; equal(f.persisted.size, 1); equal(f.ui.operatingGrowthEventDraft.value.title, 'B');
  equal(f.posts.length, 2); const b = await f.click('submit'); await flush(); equal(f.posts[2].body.title, 'B');
  f.commit(2); await b.done; equal(f.persisted.size, 2);
});

test('old failure cannot turn a new scope request into confirmation or release its lock', async () => {
  const f = fixture(); await f.click('open'); await f.fill(); const a = await f.click('submit'); await flush();
  await f.changeHotel(8); await f.click('open'); await f.fill('B', 'B事实'); const b = await f.click('submit'); await flush();
  equal(b.disabled, false); f.posts[0].reject(new Error('旧 A 合成失败')); await a.done;
  equal(f.ui.operatingGrowthSaving.value, true); equal(f.ui.operatingGrowthEventDraft.value.title, 'B'); equal(f.notices.length, 0);
  f.commit(1); await b.done;
});

test('opening the current pending form preserves its original confirmation instead of erasing A or B', async () => {
  const f = fixture(); await f.click('open'); await f.fill(); const a = await f.click('submit'); await flush();
  f.commit(0, false); f.posts[0].reject(new Error('合成未知')); await a.done; await f.fill('B', 'B事实');
  await f.click('open'); equal(f.ui.operatingGrowthEventDraft.value.title, 'B'); matches(await f.render(), /确认上次保存/);
  const confirm = await f.click('submit'); await flush(); equal(f.posts[1].options.body, f.posts[0].options.body);
  f.commit(1); await confirm.done; equal(f.ui.operatingGrowthEventDraft.value.title, 'B');
});

test('successful A forces a fresh timeline instead of joining a pre-save read with no A', async () => {
  const f = fixture(); await f.click('open'); await f.fill(); const a = await f.click('submit'); await flush();
  f.pauseTimeline(true); f.ignoreTimelineAbort(true); const refresh = await f.click('refresh'); await flush(); equal(f.timeline.length, 1);
  await f.fill('B', 'B事实'); f.commit(0); await flush(); await flush();
  equal(f.timeline.length, 2, 'save success must get a timeline captured after A was committed');
  f.timeline[1].resolve(f.timeline[1].response); await a.done; await refresh.done;
  equal(f.ui.operatingGrowthArchivePayload.value.list[0].id, 101); equal(f.ui.operatingGrowthEventDraft.value.title, 'B');
  f.timeline[0].resolve(f.timeline[0].response); await flush();
  equal(f.ui.operatingGrowthArchivePayload.value.list[0].id, 101);
});

test('ordinary repeated timeline reads still share their actual HTTP request', async () => {
  const f = fixture(); f.pauseTimeline(true); const a = await f.click('refresh'); await flush();
  const b = await f.click('refresh'); await flush(); equal(f.timeline.length, 1);
  f.timeline[0].resolve(f.timeline[0].response); await a.done; await b.done;
  equal(f.ui.operatingGrowthArchivePayload.value.hotel_id, 7); equal(f.ui.operatingGrowthArchivePayload.value.list.length, 0);
});

for (const invalid of ['missing-memory', 'wrong-hotel', 'empty-digest', 'wrong-title']) test('unverifiable A acknowledgement retains confirmation without clearing untouched input: ' + invalid, async () => {
  const f = fixture(); await f.click('open'); await f.fill(); const a = await f.click('submit'); await flush();
  f.transformReceipt(receipt => {
    if (invalid === 'missing-memory') delete receipt.memory;
    if (invalid === 'wrong-hotel') receipt.memory.hotel_id = 8;
    if (invalid === 'empty-digest') receipt.memory.content_digest = '';
    return receipt;
  });
  if (invalid === 'wrong-title') f.commit(0, false).title = '不是原 A';
  f.commit(0); await a.done; matches(await f.render(), /确认上次保存/);
  equal(f.ui.operatingGrowthEventDraft.value.title, '合成事件 A'); equal(f.ui.operatingGrowthShowEventForm.value, true);
  f.transformReceipt(null); if (invalid === 'wrong-title') f.persisted.get(101).title = '合成事件 A';
  const confirm = await f.click('submit'); await flush(); equal(f.posts[1].options.body, f.posts[0].options.body);
  f.commit(1); await confirm.done; equal(f.persisted.size, 1); equal(f.ui.operatingGrowthShowEventForm.value, false);
});

test('lazy growth component uses a new version URL and loads the confirmation entry', async () => {
  const declarations = slice('const operatingGrowthStaticScript =', 'const operatingGrowthArchiveBody =');
  const loader = slice('const loadOperatingGrowthStatic =', 'const ensureOperatingGrowthStaticReady =');
  const version = declarations.match(/const operatingGrowthStaticVersion = '([^']+)'/)[1];
  const helperHash = createHash('sha256').update(staticSource).digest('hex').slice(0, 10);
  equal(version, `20260915-growth-milestone-recovery-v4-h${helperHash}`);
  const scripts = [];
  let context;
  context = vm.createContext({
    window: { Vue },
    document: {
      createElement: tag => { equal(tag, 'script'); return {}; },
      head: { appendChild: script => {
        scripts.push(script.src);
        vm.runInContext(staticSource, context);
        script.onload();
      } },
    },
  });
  vm.runInContext(`${declarations}\n${loader}\nglobalThis.load = loadOperatingGrowthStatic;`, context);
  const componentApi = await context.load();
  equal(scripts.length, 1); equal(scripts[0], `operating-growth-static.js?v=${version}`);
  assert.notEqual(version, '20260803-growth-archive-v1', 'changed component must not reuse the old browser cache URL');
  const html = await renderToString(Vue.createSSRApp(componentApi.OperatingGrowthArchive, {
    model: { canCreate: true, metrics: [], visibleRecords: [], eventTypes: [], rangeOptions: [], hotelOptions: [] },
    showEventForm: true, saveNeedsConfirmation: true, pendingSaveTitle: '原 A', eventDraft: { title: 'B' },
  }));
  matches(html, /确认上次保存/); matches(html, /新修改会保留/);
  equal(await context.load(), componentApi); equal(scripts.length, 1);
});

test('manual event strict read rejects mismatched context.event_kind', async () => {
  const f = fixture(); await f.click('open'); await f.fill(); const a = await f.click('submit'); await flush();
  const saved = f.commit(0, false); saved.context.event_kind = 'decision'; f.commit(0); await a.done;
  equal(f.posts.length, 1); equal(f.persisted.size, 1); equal(f.ui.operatingGrowthShowEventForm.value, true);
  matches(await f.render(), /确认上次保存/); equal(f.notices.at(-1).type, 'error');
});
