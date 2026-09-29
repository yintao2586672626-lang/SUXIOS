import assert from 'node:assert/strict';
import test from 'node:test';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';
import { createHash, webcrypto } from 'node:crypto';
import * as Vue from 'vue';
import { compile } from '@vue/compiler-dom';

// Original 19c parent, component wrapper, full PMS on-books component and native
// v-model directives in a Vue memory renderer. Only a synthetic fetch exists.
const option = name => process.argv.find(s => s.startsWith(`--${name}=`))?.slice(name.length + 3);
const repository = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const root = path.resolve(option('source-root') || repository);
const readers = [], sections = [], attempts = [];
const hash = value => createHash('sha256').update(value).digest('hex').toUpperCase();
const read = file => { const resolved = path.join(root, file), bytes = fs.readFileSync(resolved);
  readers.push({ path: file, resolved_path: resolved, sha256: hash(bytes) }); return bytes.toString('utf8'); };
const main = read('public/app-main.js'), systemSource = read('public/system-static.js');
const componentSource = read('public/components/system/operating-finance-control-center.js');
const components = read('public/components/system/app-main-components.js');
const parentTemplate = read('resources/frontend/templates/fragments/19c-page-operating-finance.html');
function cut(source, start, end) { const a = source.indexOf(start), b = source.indexOf(end, a + start.length);
  assert.ok(a >= 0 && b > a, start); const text = source.slice(a, b); sections.push({ start, end, sha256: hash(text) }); return text; }
const methods = [
  cut(main, '            const captureAuthSession =', '            const createDefaultAuthContext ='),
  cut(main, '            const terminalAuthFailureReason =', '            const applyAuthContext ='),
  cut(main, '            const BUSINESS_CONTEXT_ENDPOINT_PREFIXES =', '            const canManageOwnHotels ='),
  cut(main, '            const setHotel = value =>', '            const dualOtaSelectedHotel ='),
  cut(main, '            const createRequestAbortError =', '            const clearPageLifecycleTimers ='),
  cut(main, '            const currentPageReadPolicy =', '            const runPageLoadOnce ='),
  cut(main, '            const COORDINATED_GET_MAX_CONCURRENCY =', '            // API 请求'),
  cut(main, '            const request = async (', '            const apiRequest = request;'),
].join('\n');
const wrapperSource = cut(components, '    const OperatingFinanceControlCenter = {', '    const platformAutoPanelsScript =');
assert.match(parentTemplate, /v-if="currentPage === 'operating-finance'"/);
assert.match(parentTemplate, /:can-execute="operationFinanceCanExecute"/);
assert.match(components, /OperatingFinanceControlCenterBody/);
const renderParent = new Function('Vue', compile(parentTemplate, { mode: 'function', prefixIdentifiers: true }).code)(Vue);
const clone = value => JSON.parse(JSON.stringify(value));
const tick = async () => { await Vue.nextTick(); await new Promise(resolve => setTimeout(resolve, 0)); await Vue.nextTick(); };
async function until(fn, label) { for (let i = 0; i < 40 && !fn(); i++) await tick(); assert.ok(fn(), label); }
const marker = 'synthetic-session-only-round143';
const docBefore = globalThis.Document, shadowBefore = globalThis.ShadowRoot;
function memoryHost() {
  const make = (type, text = '') => ({ type, text, props: {}, children: [], parent: null, style: {}, value: '', checked: false, selected: false, listeners: {},
    get options() { return this.children.filter(n => n.type === 'option'); },
    addEventListener(key, fn) { (this.listeners[key] ||= []).push(fn); },
    removeEventListener(key, fn) { this.listeners[key] = (this.listeners[key] || []).filter(x => x !== fn); },
    dispatchEvent(event) { for (const fn of this.listeners[event.type] || []) fn({ ...event, target: this }); },
    getRootNode() { let n = this; while (n.parent) n = n.parent; return n; },
  });
  const remove = node => { if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1); node.parent = null; };
  return { root: make('root'), options: {
    createElement: tag => make(tag), createText: text => make('text', text), createComment: text => make('comment', text),
    setText: (n, text) => { n.text = text; }, setElementText: (n, text) => { n.text = text; n.children = []; },
    parentNode: n => n.parent, nextSibling: n => n.parent?.children[n.parent.children.indexOf(n) + 1] || null,
    insert(n, p, anchor = null) { remove(n); n.parent = p; const i = anchor ? p.children.indexOf(anchor) : -1; if (i < 0) p.children.push(n); else p.children.splice(i, 0, n); },
    remove, patchProp(n, key, old, value) { n.props[key] = value; if (key === 'value') { n.value = value; n._value = value; } if (key === 'checked') n.checked = value; },
  } };
}
// Source-derived public hydrate DTO; no service, DB or external PMS is executed.
const normalizedCapture = value => {
  const match = /^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})(?::(\d{2}))?$/.exec(value);
  assert.ok(match, 'fixed native datetime-local fixture');
  return `${match[1]} ${match[2]}:${match[3] || '00'}.000000`;
};
function savedSnapshot(body, id = 501) {
  assert.ok(['dingdandao_pms', 'manual_all_channels'].includes(body.platform));
  assert.equal(body.fact_scope, 'accommodation_room_fee');
  const content = { contract_version: 'hotel_on_books_snapshot.v1', tenant_id: 70,
    hotel_id: body.hotel_id, source_hotel_id: body.hotel_id, platform: body.platform, fact_scope: body.fact_scope,
    stay_date: body.stay_date, captured_at: normalizedCapture(body.captured_at), source_method: 'manual_entry',
    source_ref_hash: createHash('sha256').update('on-books-source-v1|' + body.source_ref.replace(/^[\x00\x09\x0a\x0b\x0d\x20]+|[\x00\x09\x0a\x0b\x0d\x20]+$/g, '')).digest('hex'),
    on_books_room_nights: body.on_books_room_nights, on_books_room_revenue: body.on_books_room_revenue,
    cumulative_cancel_room_nights: body.cumulative_cancel_room_nights, gross_booking_room_nights: body.gross_booking_room_nights,
    quality_status: body.operator_attested ? 'manual_confirmed' : 'unverified', readback_verified: 1 };
  for (const key of ['on_books_room_nights', 'on_books_room_revenue', 'cumulative_cancel_room_nights', 'gross_booking_room_nights']) {
    assert.ok(content[key] === null || (Number.isFinite(content[key]) && !Number.isInteger(content[key])), 'fixed fractional public float fixture');
  }
  const canonical = value => Array.isArray(value) ? value.map(canonical) : value && typeof value === 'object'
    ? Object.fromEntries(Object.keys(value).sort().map(k => [k, canonical(value[k])])) : value;
  const digestContent = { ...content }; delete digestContent.hotel_id;
  return { id, ...content, readback_verified: true,
    idempotency_key: createHash('sha256').update('operating-finance-idempotency-v1|' + body.idempotency_key).digest('hex'),
    content_digest: createHash('sha256').update(JSON.stringify(canonical(digestContent))).digest('hex'),
    created_by: 77, created_at: '2026-09-20 10:00:00.000000', external_write_count: 0, idempotent: false };
}
function bookingOverview(query, snapshot) {
  const result = { contract_version: 'booking_pace_risk.v1', tenant_id: 70, hotel_id: Number(query.hotel_id),
    platform: query.platform, stay_date: query.stay_date, status: 'blocked', fact_scope: null,
    current_snapshot_ref: null, previous_snapshot_ref: null, current_captured_at: null, previous_captured_at: null,
    current_on_books_room_nights: null, current_on_books_room_revenue: null, current_cumulative_cancel_room_nights: null,
    current_gross_booking_room_nights: null, lead_time_days: null, elapsed_hours: null,
    net_pickup_room_nights: null, gross_pickup_room_nights: null, pickup_room_nights_per_hour: null,
    room_revenue_delta: null, room_revenue_per_hour: null, cancellation_rate_percent: null,
    risk_status: 'not_classified_without_same_scope_baseline', data_gaps: [], automatic_pricing: false,
    automatic_inventory_write: false, external_write_count: 0 };
  if (!snapshot || snapshot.quality_status === 'unverified') { result.data_gaps = ['verified_on_books_snapshot_missing']; return result; }
  assert.equal(snapshot.hotel_id, result.hotel_id); assert.equal(snapshot.platform, result.platform); assert.equal(snapshot.stay_date, result.stay_date);
  return { ...result, status: 'baseline_only', fact_scope: snapshot.fact_scope,
    current_snapshot_ref: 'hotel_on_books_snapshots#' + snapshot.id, current_captured_at: snapshot.captured_at,
    current_on_books_room_nights: snapshot.on_books_room_nights, current_on_books_room_revenue: snapshot.on_books_room_revenue,
    current_cumulative_cancel_room_nights: snapshot.cumulative_cancel_room_nights, current_gross_booking_room_nights: snapshot.gross_booking_room_nights,
    lead_time_days: Math.round((Date.parse(query.stay_date + 'T00:00:00Z') - Date.parse(snapshot.captured_at.slice(0, 10) + 'T00:00:00Z')) / 86400000),
    data_gaps: ['previous_verified_on_books_snapshot_missing'] };
}
function overview(call, snapshot = null) {
  const q = call.query, hotel = Number(q.hotel_id);
  // Consumed booking-pacing projection, not empty-success claims for other modules.
  return { contract_version: 'operating_finance_control_center.v1', tenant_id: 70, hotel_id: hotel, hotel_name: `合成酒店 ${hotel}`,
    business_date: q.business_date, period_month: q.period_month, stay_date: q.stay_date, platform: q.platform,
    booking_pace: bookingOverview(q, snapshot),
    boundaries: { automatic_approval: false, automatic_external_send: false, automatic_ota_write: false, automatic_pms_write: false, external_write_count: 0 } };
}
function harness(attempt) {
  const calls = [], notices = [], errors = [], warnings = [], diagnostics = [];
  const scope = Vue.effectScope(), host = memoryHost(); let app, component;
  const sandbox = { ...Vue, window: {}, h: Vue.h, crypto: webcrypto, TextEncoder, JSON, Date, Intl, Headers, Response, URL, URLSearchParams, FormData,
    AbortController, DOMException, setTimeout, clearTimeout, API_BASE: 'https://synthetic.invalid/api',
    authSessionEpoch: 1, pageRequestGeneration: 1, pageLoadRequests: new Map(),
    token: Vue.ref(marker), user: Vue.ref({ id: 77, tenant_id: 70, is_super_admin: true, capabilities: ['all'] }),
    authContext: Vue.ref({ tenantId: 70, hotelId: 7, permissionStatus: 'allowed' }),
    permittedHotels: Vue.ref([{ id: 7, tenant_id: 70 }, { id: 8, tenant_id: 70 }]),
    filterReportHotel: Vue.ref('7'), currentPage: Vue.ref('operating-finance'),
    revenueAiBusinessDate: Vue.ref('2026-09-20'), coreOperationsTargetDate: Vue.ref('2026-09-20'),
    normalizeCanonicalPage: s => s, readRequestCooldown: { check: () => null, record() {} },
    document: { documentElement: { dataset: { suxiRenderPhase: 'full' } } },
    console: { error: (...args) => diagnostics.push(args.map(x => x?.message || String(x))), warn: (...args) => warnings.push(args.map(String).join(' ')) },
    fetch(url, options) {
      const parsed = new URL(url), endpoint = parsed.pathname.replace(/^\/api/, ''), method = options.method || 'GET';
      assert.equal(parsed.origin, 'https://synthetic.invalid'); assert.equal(new Headers(options.headers).get('Authorization'), marker);
      assert.ok((method === 'GET' && endpoint === '/operating-finance/overview') || (method === 'POST' && endpoint === '/operating-finance/on-books-snapshots'));
      if (method === 'GET') assert.ok(options.signal, 'original coordinator GET signal'); else assert.equal(options.signal, undefined, 'original direct POST');
      return new Promise((resolve, reject) => {
        const call = { method, endpoint, query: Object.fromEntries(parsed.searchParams), body: options.body ? JSON.parse(options.body) : null,
          settled: false, aborted: false,
          reply(data) { assert.equal(call.settled, false); call.settled = true; call.response = clone(data);
            resolve(new Response(JSON.stringify({ code: 200, message: '操作成功', data, time: 1790000000 }), { status: 200 })); },
          fail() { assert.equal(call.settled, false); call.settled = true; call.failure = 'controlled500';
            call.response = { code: 500, message: method === 'POST' ? '在手预订快照保存失败' : '经营财务与恢复中心读取失败', data: null, time: 1790000000 };
            resolve(new Response(JSON.stringify(call.response), { status: 500 })); },
          teardown() { if (call.settled) return; call.settled = true; call.teardown_only = true; reject(new TypeError('Synthetic teardown only')); } };
        calls.push(call); options.signal?.addEventListener('abort', () => { call.aborted = true; if (!call.settled) { call.settled = true; reject(new DOMException('Aborted', 'AbortError')); } });
      });
    },
  };
  attempt.capture = () => ({ calls: calls.map(({ reply, fail, teardown, ...c }) => c), notices, errors, warnings, diagnostics });
  attempt.stop = async () => { app?.unmount(); scope.stop(); calls.forEach(c => c.teardown()); await tick(); };
  vm.createContext(sandbox); vm.runInContext(systemSource, sandbox); vm.runInContext(componentSource, sandbox);
  sandbox.appSystemStatic = sandbox.window.SUXI_SYSTEM_STATIC; sandbox.requireAppSystemStatic = key => sandbox.appSystemStatic[key];
  scope.run(() => vm.runInContext(methods + '\nglobalThis.api = request; globalThis.canFinance = operationFinanceCanExecute; globalThis.selectHotel = setHotel; globalThis.coordinator = () => ({ active: coordinatedGetActiveCount, inflight: coordinatedGetRequests.size, queued: coordinatedGetQueue.length, page_reads: pageLoadRequests.size });', sandbox));
  const body = sandbox.window.SUXI_SYSTEM_COMPONENTS.OperatingFinanceControlCenterBody;
  body.render = new Function('Vue', compile(body.template, { mode: 'function', prefixIdentifiers: true }).code)(Vue); delete body.template;
  sandbox.OperatingFinanceControlCenterAsync = body;
  vm.runInContext(wrapperSource + '\nglobalThis.wrapper = OperatingFinanceControlCenter;', sandbox);
  const renderer = Vue.createRenderer(host.options);
  app = renderer.createApp({ setup: () => ({ currentPage: sandbox.currentPage, filterReportHotel: sandbox.filterReportHotel,
    hotels: [{ id: 7, name: '合成酒店七' }, { id: 8, name: '合成酒店八' }], managerCapabilityRequest: sandbox.api,
    operationFinanceCanExecute: sandbox.canFinance, setHotel: sandbox.selectHotel,
    showToast: (message, type) => notices.push({ message, type }) }), render: renderParent });
  app.component('OperatingFinanceControlCenter', sandbox.wrapper);
  app.mixin({ mounted() { if (this.$options.name === 'OperatingFinanceControlCenterBody') component = this; } });
  app.config.warnHandler = msg => warnings.push(msg); app.config.errorHandler = e => errors.push(e.stack || String(e));
  const walk = n => [n, ...n.children.flatMap(walk)], text = n => n.type === 'comment' ? '' : n.text + n.children.map(text).join('');
  const nodes = () => walk(host.root);
  const usable = n => { assert.ok(n, 'original visible control exists'); for (let p = n; p; p = p.parent) assert.ok(!p.props.disabled && p.style.display !== 'none'); return n; };
  const booking = () => nodes().find(n => n.props['data-testid'] === 'operating-finance-booking');
  const selectText = wanted => nodes().find(n => n.type === 'select' && n.options.some(o => text(o) === wanted));
  async function edit(node, value) {
    usable(node);
    if (node.type === 'select') { node.options.forEach(o => { o.selected = String(o._value ?? o.value) === String(value); }); node.value = value; node.dispatchEvent({ type: 'change' }); node.props.onChange?.({ target: node }); }
    else if (node.props.type === 'checkbox') { node.checked = value; node.dispatchEvent({ type: 'change' }); }
    else { node.value = value; node.dispatchEvent({ type: 'input' }); }
    await tick();
  }
  async function flushReads(snapshot = null) {
    for (let i = 0; i < 12; i++) { await tick(); const pending = calls.filter(c => c.method === 'GET' && !c.settled);
      if (!pending.length && !component?.loading) return;
      for (const c of pending) c.reply(overview(c, snapshot && snapshot.hotel_id === Number(c.query.hotel_id) && snapshot.stay_date === c.query.stay_date && snapshot.platform === c.query.platform ? snapshot : null)); }
    assert.fail('original overview reads did not close');
  }
  attempt.capture = () => ({ calls: calls.map(({ reply, fail, teardown, ...c }) => c), notices, errors, warnings, diagnostics,
    coordinator: clone(sandbox.coordinator()), text: text(host.root), state: component ? { hotel: component.hotelId, stay_date: component.stayDate, platform: component.platform,
      form: clone(component.onBooks), saving: component.savingOnBooks, loading: component.loading, error: component.error,
      overview: clone(component.overview) } : null });
  app.mount(host.root);
  return { calls, notices, errors, warnings, diagnostics, sandbox, flushReads, text: () => text(host.root),
    get component() { return component; },
    async failReads() { await tick(); const pending = calls.filter(c => c.method === 'GET' && !c.settled); assert.ok(pending.length);
      for (const c of pending) c.fail(); await until(() => !component.loading, 'current overview failure closed'); },
    async ready() { await flushReads(); usable(nodes().find(n => n.props['data-testid'] === 'operating-finance-tab-booking')).props.onClick(); await tick();
      await edit(nodes().find(n => n.type === 'input' && n.props.type === 'date' && n.parent && text(n.parent).includes('目标入住日')), '2026-09-22');
      await edit(selectText('订单来了 PMS'), 'dingdandao_pms'); await flushReads(); assert.ok(booking()); },
    async scope(hotel, stayDate, platform) { await edit(selectText('合成酒店七'), String(hotel));
      await edit(nodes().find(n => n.type === 'input' && n.props.type === 'date' && n.parent && text(n.parent).includes('目标入住日')), stayDate);
      await edit(selectText('订单来了 PMS'), platform); },
    async fill(rooms, ref, captured, confirmed) { const fields = () => walk(booking());
      await edit(fields().find(n => n.type === 'input' && n.props.type === 'datetime-local'), captured);
      await edit(fields().find(n => n.type === 'input' && n.props.placeholder === '在手间夜'), String(rooms));
      await edit(fields().find(n => n.type === 'input' && n.props.placeholder === '在手房费'), '1250.75');
      await edit(fields().find(n => n.type === 'input' && n.props.placeholder === '累计取消间夜，可空'), '1.25');
      await edit(fields().find(n => n.type === 'input' && n.props.placeholder === '累计毛预订间夜，可空'), '33.75');
      await edit(fields().find(n => n.type === 'input' && n.props.placeholder === '来源引用/文件指纹'), ref);
      await edit(fields().find(n => n.type === 'input' && n.props.type === 'checkbox'), confirmed); },
    async submit() { const form = usable(walk(booking()).find(n => n.type === 'form')), button = usable(walk(form).find(n => n.type === 'button' && n.props.type === 'submit'));
      const count = calls.length, completion = form.props.onSubmit({ target: form, preventDefault() {}, stopPropagation() {} });
      await until(() => calls.slice(count).some(c => c.method === 'POST'), 'original POST reached transport');
      assert.equal(Boolean(button.props.disabled), true); return { completion, call: calls.slice(count).find(c => c.method === 'POST') }; },
  };
}
async function scenario(name, run) { await test(name, async () => { const attempt = { name }; attempts.push(attempt);
  try { const h = harness(attempt); await h.ready(); await run(h); await tick();
    assert.deepEqual(h.errors, []); assert.deepEqual(h.warnings, []);
    assert.equal(h.diagnostics.length, h.calls.filter(c => c.failure === 'controlled500').length);
    for (const row of h.diagnostics) { assert.equal(row[0], 'API请求失败:'); assert.match(row[1], /^\/operating-finance\/(overview|on-books-snapshots)/);
      assert.match(row[2], /经营财务.*失败|在手预订快照保存失败/); }
    assert.deepEqual(clone(h.sandbox.coordinator()), { active: 0, inflight: 0, queued: 0, page_reads: 0 });
    assert.ok(h.calls.every(c => c.settled && !c.teardown_only && !c.aborted)); attempt.passed = true;
  } catch (e) { attempt.failure = e.stack; throw e; }
  finally { if (attempt.capture) attempt.before_teardown = attempt.capture(); if (attempt.stop) await attempt.stop();
    if (attempt.capture) attempt.after_teardown = attempt.capture(); delete attempt.capture; delete attempt.stop; } }); }

if (option('prepare-only') === 'true') {
  const s = { window: {} }; vm.runInNewContext(componentSource, s);
  compile(s.window.SUXI_SYSTEM_COMPONENTS.OperatingFinanceControlCenterBody.template, { mode: 'function', prefixIdentifiers: true });
  console.log(JSON.stringify({ readers, sections, full_parent_and_component_compile: true, behavior: 0 }));
} else {
  globalThis.Document ??= class MemoryDocument {}; globalThis.ShadowRoot ??= class MemoryShadowRoot {};
  try {
    await scenario('unverified submitted PMS snapshot stays unverified and later attested draft survives, then normal attested save reads back', async h => {
      await h.fill(12.5, ' \u00a0synthetic:A\u00a0 ', '2026-09-19T10:00', false); const a = await h.submit();
      assert.equal(a.call.body.hotel_id, 7); assert.equal(a.call.body.platform, 'dingdandao_pms');
      assert.equal(a.call.body.stay_date, '2026-09-22'); assert.equal(a.call.body.operator_attested, false);
      assert.equal(a.call.body.source_ref, ' \u00a0synthetic:A\u00a0 ', 'original source text and client payload are not trimmed');
      await h.fill(22.5, 'synthetic:B', '2026-09-19T10:05:15', true); const b = clone(h.component.onBooks), savedA = savedSnapshot(a.call.body);
      a.call.reply(savedA); await tick();
      assert.deepEqual(clone(h.component.onBooks), b, 'pending later draft not cleared');
      assert.match(h.notices.at(-1).message, /未核验.*不参与正式节奏.*提交版本.*后续修改已保留/);
      assert.doesNotMatch(h.notices.at(-1).message, /人工核对.*已保存|可进入/);
      await h.flushReads(savedA); await a.completion;
      assert.equal(h.component.currentBooking.current_snapshot_ref, null); assert.match(h.text(), /缺少已核对的在手预订快照/);
      const next = await h.submit(), savedB = savedSnapshot(next.call.body, 502);
      assert.equal(next.call.body.operator_attested, true); assert.equal(next.call.body.captured_at, '2026-09-19T10:05:15');
      next.call.reply(savedB); await h.flushReads(savedB); await next.completion;
      assert.equal(h.component.onBooks.source_ref, ''); assert.equal(h.component.onBooks.confirmed, false);
      assert.equal(h.component.currentBooking.current_snapshot_ref, 'hotel_on_books_snapshots#502');
      assert.equal(h.component.currentBooking.current_captured_at, '2026-09-19 10:05:15.000000');
      assert.equal(h.component.currentBooking.current_on_books_room_nights, 22.5);
      assert.match(h.notices.at(-1).message, /酒店 #7.*dingdandao_pms.*2026-09-22.*人工核对.*本地回读/);
      assert.match(h.text(), /缺少上一条同范围快照/); assert.equal(h.component.savingOnBooks, false);
    });
    await scenario('late successful A cannot clear a new hotel stay-date source draft or replace its read error', async h => {
      await h.fill(12.5, 'synthetic:A', '2026-09-19T10:00:00', true); const a = await h.submit();
      await h.scope(8, '2026-09-23', 'manual_all_channels'); await h.failReads();
      await h.fill(32.5, 'synthetic:B', '2026-09-19T11:00:00', false);
      const b = clone(h.component.onBooks), count = h.calls.length, error = h.component.error;
      assert.match(error, /读取失败/); a.call.reply(savedSnapshot(a.call.body)); await tick();
      assert.deepEqual(clone(h.component.onBooks), b, 'late A preserves new B draft');
      assert.equal(h.component.error, error); assert.equal(h.calls.length, count); assert.equal(h.notices.length, 0);
      await a.completion; assert.equal(h.component.hotelId, '8'); assert.equal(h.component.stayDate, '2026-09-23');
      assert.equal(h.component.platform, 'manual_all_channels'); assert.equal(h.component.savingOnBooks, false);
    });
    await scenario('current failure and incompatible receipt preserve original draft and the original retry recovers', async h => {
      await h.fill(12.5, 'synthetic:A', '2026-09-19T10:00', false); const a = await h.submit(), draft = clone(h.component.onBooks);
      a.call.fail(); await a.completion; assert.deepEqual(clone(h.component.onBooks), draft); assert.match(h.component.error, /保存失败/);
      assert.equal(h.component.savingOnBooks, false);
      for (const kind of ['identity', 'quality']) {
        const retry = await h.submit(); assert.deepEqual(retry.call.body, a.call.body);
        // Both are legitimate public shapes for a different saved submission, not a corrupt digest payload.
        const otherBody = kind === 'identity' ? { ...retry.call.body, hotel_id: 8 } : { ...retry.call.body, operator_attested: true };
        otherBody.idempotency_key = 'onbooks:' + (kind === 'identity' ? 'a' : 'b').repeat(64);
        retry.call.reply(savedSnapshot(otherBody, kind === 'identity' ? 503 : 504)); await tick();
        assert.deepEqual(clone(h.component.onBooks), draft); assert.match(h.component.error, /回读身份或来源质量不一致/);
        await retry.completion;
      }
      const final = await h.submit(), saved = savedSnapshot(final.call.body); assert.deepEqual(final.call.body, a.call.body);
      final.call.reply(saved); await h.flushReads(saved); await final.completion;
      assert.equal(h.component.error, ''); assert.equal(h.component.onBooks.source_ref, '');
      assert.equal(h.component.currentBooking.current_snapshot_ref, null); assert.match(h.notices.at(-1).message, /未核验/);
    });
    await scenario('old failed A cannot change new B error or release its pending overview read', async h => {
      await h.fill(12.5, 'synthetic:A', '2026-09-19T10:00:00', true); const a = await h.submit();
      await h.scope(8, '2026-09-23', 'dingdandao_pms'); await h.fill(32.5, 'synthetic:B', '2026-09-19T11:00:00', false);
      const b = clone(h.component.onBooks), count = h.calls.length; assert.equal(h.component.loading, true);
      a.call.fail(); await a.completion;
      assert.equal(h.component.error, ''); assert.equal(h.component.loading, true); assert.equal(h.calls.length, count);
      assert.equal(h.notices.length, 0); assert.deepEqual(clone(h.component.onBooks), b); assert.equal(h.component.savingOnBooks, false);
      await h.flushReads(); assert.equal(h.component.overview.hotel_id, 8); assert.equal(h.component.currentBooking.stay_date, '2026-09-23');
    });
  } finally {
    if (docBefore === undefined) delete globalThis.Document; else globalThis.Document = docBefore;
    if (shadowBefore === undefined) delete globalThis.ShadowRoot; else globalThis.ShadowRoot = shadowBefore;
    if (option('evidence')) fs.writeFileSync(option('evidence'), JSON.stringify({ readers, sections, attempts,
      boundary: 'Synthetic public consumer DTOs/original request/auth/coordinator and full19c/component in memory renderer; no real HTTP/DB/browser or PHP.' }, null, 2) + '\n');
  }
}
