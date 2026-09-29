import assert from 'node:assert/strict';
import test from 'node:test';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';
import { createHash, webcrypto } from 'node:crypto';
import * as Vue from 'vue';
import { compile } from '@vue/compiler-dom';

// Original 19c parent, component wrapper, full monthly component and native
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
const marker = 'synthetic-session-only-round141';
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
// Source-derived public hydrate DTO. No service/DB is executed.
// ota_channel has one supplied revenue and explicit whole-hotel gaps; no GOP is fabricated.
const inputKeys = ['ota_net_revenue', 'room_operating_revenue', 'non_room_operating_revenue', 'departmental_expense',
  'undistributed_operating_expense', 'rent_expense', 'other_fixed_cash_cost', 'budget_total_operating_revenue', 'budget_gop'];
function savedSnapshot(body, id = 501) {
  assert.equal(body.fact_scope, 'ota_channel');
  const inputs = Object.fromEntries(inputKeys.map(k => [k, body.inputs[k]]));
  assert.ok([123.45, 456.78].includes(inputs.ota_net_revenue), 'fixed fractional synthetic fixture, no PHP float/integer ambiguity');
  const content = { contract_version: 'hotel_monthly_operating_finance.v1', tenant_id: 70, hotel_id: body.hotel_id, source_hotel_id: body.hotel_id,
    period_month: body.period_month, fact_scope: body.fact_scope,
    source: { source_method: 'manual_entry', source_quality_status: body.operator_attested ? 'operator_attested' : 'unverified',
      currency: 'CNY', tax_basis: body.tax_basis, metric_definition_version: 'hotel_monthly_operating_finance_metrics.v1' },
    source_refs: [...new Set(body.source_refs)].sort(), inputs,
    results: { contract_version: 'hotel_monthly_operating_finance.v1', fact_scope: 'ota_channel', status: 'partial',
      recognized_revenue: inputs.ota_net_revenue, total_operating_revenue: null, room_operating_contribution: null,
      gop: null, gop_margin_percent: null, owner_cash_proxy_before_tax_capex_and_financing: null,
      budget_total_operating_revenue_variance: null, budget_gop_variance: null,
      formulas: { total_operating_revenue: 'room_operating_revenue + non_room_operating_revenue',
        gop: 'total_operating_revenue - departmental_expense - undistributed_operating_expense',
        gop_margin_percent: 'gop / total_operating_revenue * 100',
        owner_cash_proxy_before_tax_capex_and_financing: 'gop - rent_expense - other_fixed_cash_cost', budget_variance: 'actual - budget' },
      boundaries: { ota_settlement_is_not_whole_hotel_revenue: true, owner_cash_proxy_is_not_accounting_cash_flow: true,
        tax_capex_financing_and_depreciation_excluded: true, tax_capex_financing_depreciation_working_capital_and_debt_service_excluded: true,
        automatic_approval: false, external_write_count: 0 } },
    missing_items: ['whole_hotel_revenue_scope_unavailable', 'gop_not_calculable_from_ota_channel_scope'] };
  const canonical = value => Array.isArray(value) ? value.map(canonical) : value && typeof value === 'object'
    ? Object.fromEntries(Object.keys(value).sort().map(k => [k, canonical(value[k])])) : value;
  const digestContent = { ...content }; delete digestContent.hotel_id;
  return { id, ...content, version_no: id - 500,
    idempotency_key: createHash('sha256').update('monthly-operating-finance-idempotency-v1|' + body.idempotency_key).digest('hex'),
    // Canonical ASCII/null/fractional fixture only; not a PHP execution or DB proof.
    content_digest: createHash('sha256').update(JSON.stringify(canonical(digestContent))).digest('hex'),
    created_by: 77, created_at: '2026-09-20 10:00:00', readback_verified: true, external_write_count: 0, idempotent: false };
}
function overview(call, snapshot = null) {
  const q = call.query, hotel = Number(q.hotel_id);
  if (snapshot) { assert.equal(snapshot.hotel_id, hotel); assert.equal(snapshot.period_month, q.period_month); }
  const stored = snapshot ? clone(snapshot) : null; if (stored) delete stored.idempotent;
  // Only the monthly component's consumed public projection; other modules are
  // omitted, not represented as empty-success or asserted healthy.
  return { contract_version: 'operating_finance_control_center.v1', tenant_id: 70, hotel_id: hotel, hotel_name: `合成酒店 ${hotel}`,
    business_date: q.business_date, period_month: q.period_month, stay_date: q.stay_date, platform: q.platform,
    monthly_finance: stored || { contract_version: 'hotel_monthly_operating_finance.v1', tenant_id: 70, hotel_id: hotel,
      period_month: q.period_month, status: 'missing', missing_items: ['monthly_operating_finance_snapshot_missing'], external_write_count: 0 },
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
      assert.ok((method === 'GET' && endpoint === '/operating-finance/overview') || (method === 'POST' && endpoint === '/operating-finance/monthly-finance'));
      if (method === 'GET') assert.ok(options.signal, 'original coordinator GET signal'); else assert.equal(options.signal, undefined, 'original direct POST');
      return new Promise((resolve, reject) => {
        const call = { method, endpoint, query: Object.fromEntries(parsed.searchParams), body: options.body ? JSON.parse(options.body) : null,
          settled: false, aborted: false,
          reply(data) { assert.equal(call.settled, false); call.settled = true; call.response = clone(data);
            resolve(new Response(JSON.stringify({ code: 200, message: '操作成功', data, time: 1790000000 }), { status: 200 })); },
          fail() { assert.equal(call.settled, false); call.settled = true; call.failure = 'controlled500';
            call.response = { code: 500, message: method === 'POST' ? '月度经营财务保存失败' : '经营财务与恢复中心读取失败', data: null, time: 1790000000 };
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
  const finance = () => nodes().find(n => n.props['data-testid'] === 'operating-finance-monthly');
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
      for (const c of pending) c.reply(overview(c, snapshot && snapshot.hotel_id === Number(c.query.hotel_id) && snapshot.period_month === c.query.period_month ? snapshot : null)); }
    assert.fail('original overview reads did not close');
  }
  attempt.capture = () => ({ calls: calls.map(({ reply, fail, teardown, ...c }) => c), notices, errors, warnings, diagnostics,
    coordinator: clone(sandbox.coordinator()), text: text(host.root), state: component ? { hotel: component.hotelId, month: component.periodMonth,
      form: clone(component.financeForm), saving: component.savingFinance, loading: component.loading, error: component.error,
      overview: clone(component.overview) } : null });
  app.mount(host.root);
  return { calls, notices, errors, warnings, diagnostics, sandbox, flushReads, text: () => text(host.root),
    get component() { return component; },
    async failReads() { await tick(); const pending = calls.filter(c => c.method === 'GET' && !c.settled); assert.ok(pending.length);
      for (const c of pending) c.fail(); await until(() => !component.loading, 'current overview failure closed'); },
    async ready() { await flushReads(); usable(nodes().find(n => n.props['data-testid'] === 'operating-finance-tab-finance')).props.onClick(); await tick();
      await edit(nodes().find(n => n.type === 'input' && n.props.type === 'month'), '2026-08'); await flushReads(); assert.ok(finance()); },
    async scope(hotel, month) { await edit(selectText('合成酒店七'), String(hotel)); await edit(nodes().find(n => n.type === 'input' && n.props.type === 'month'), month); },
    async fill(amount, ref) { await edit(selectText('OTA渠道范围'), 'ota_channel'); await edit(selectText('含税口径'), 'tax_inclusive');
      await edit(walk(finance()).find(n => n.type === 'input' && n.props.inputmode === 'decimal'), String(amount));
      await edit(walk(finance()).find(n => n.type === 'textarea'), ref); },
    async submit() { const form = usable(walk(finance()).find(n => n.type === 'form')), button = usable(walk(form).find(n => n.type === 'button' && n.props.type === 'submit'));
      const count = calls.length, completion = form.props.onSubmit({ target: form, preventDefault() {}, stopPropagation() {} });
      await until(() => calls.slice(count).some(c => c.method === 'POST'), 'original POST reached transport');
      assert.equal(Boolean(button.props.disabled), true); return { completion, call: calls.slice(count).find(c => c.method === 'POST') }; },
  };
}
async function scenario(name, run) { await test(name, async () => { const attempt = { name }; attempts.push(attempt);
  try { const h = harness(attempt); await h.ready(); await run(h); await tick();
    assert.deepEqual(h.errors, []); assert.deepEqual(h.warnings, []);
    assert.equal(h.diagnostics.length, h.calls.filter(c => c.failure === 'controlled500').length);
    for (const row of h.diagnostics) { assert.equal(row[0], 'API请求失败:'); assert.match(row[1], /^\/operating-finance\/(overview|monthly-finance)/);
      assert.match(row[2], /经营财务.*失败/); }
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
    await scenario('late saved A cannot clear the visible new hotel/month B draft or start a B refresh', async h => {
      await h.fill(123.45, 'synthetic:A'); const a = await h.submit();
      assert.equal(a.call.body.hotel_id, 7); assert.equal(a.call.body.period_month, '2026-08');
      await h.scope(8, '2026-07'); await h.failReads(); await h.fill(987.65, 'synthetic:B');
      const draft = clone(h.component.financeForm), count = h.calls.length, bError = h.component.error;
      assert.match(bError, /读取失败/);
      a.call.reply(savedSnapshot(a.call.body)); await tick();
      assert.deepEqual(clone(h.component.financeForm), draft, 'late A preserves B draft');
      assert.equal(h.component.error, bError, 'late A preserves B read failure');
      assert.equal(h.calls.length, count, 'late A starts no current B overview'); assert.equal(h.notices.length, 0);
      assert.equal(h.component.hotelId, '8'); assert.equal(h.component.periodMonth, '2026-07'); assert.equal(h.component.savingFinance, false);
      await a.completion;
    });
    await scenario('same-scope later edits survive while normal unchanged save clears and reads the submitted version', async h => {
      await h.fill(123.45, 'synthetic:A'); const a = await h.submit();
      await h.fill(456.78, 'synthetic:B'); const b = clone(h.component.financeForm), savedA = savedSnapshot(a.call.body);
      a.call.reply(savedA); await until(() => h.calls.some(c => c.method === 'GET' && !c.settled), 'normal same-scope readback'); await h.flushReads(savedA); await a.completion;
      assert.deepEqual(clone(h.component.financeForm), b); assert.match(h.notices.at(-1).message, /提交版本.*后续修改已保留/);
      assert.equal(h.component.currentFinance.inputs.ota_net_revenue, 123.45); assert.equal(h.component.currentFinance.source.source_quality_status, 'unverified');
      const next = await h.submit(), savedB = savedSnapshot(next.call.body, 502); assert.equal(next.call.body.inputs.ota_net_revenue, 456.78);
      next.call.reply(savedB); await until(() => h.calls.some(c => c.method === 'GET' && !c.settled), 'unchanged save original readback'); await h.flushReads(savedB); await next.completion;
      assert.equal(h.component.financeForm.source_refs, ''); assert.equal(h.component.currentFinance.id, 502);
      assert.equal(h.component.currentFinance.hotel_id, 7); assert.equal(h.component.currentFinance.period_month, '2026-08');
      assert.match(h.text(), /456\.78/); assert.equal(h.component.savingFinance, false);
    });
    await scenario('current failure and another submission receipt retain draft, then original retry confirms the same submitted body', async h => {
      await h.fill(123.45, 'synthetic:A'); const draft = clone(h.component.financeForm), first = await h.submit();
      first.call.fail(); await first.completion; assert.deepEqual(clone(h.component.financeForm), draft); assert.match(h.component.error, /保存失败/);
      assert.equal(h.component.savingFinance, false); const retry = await h.submit(); assert.deepEqual(retry.call.body, first.call.body);
      const wrong = savedSnapshot({ ...retry.call.body, idempotency_key: 'monthly:' + 'b'.repeat(64) }, 503);
      retry.call.reply(wrong); await tick();
      assert.deepEqual(clone(h.component.financeForm), draft); assert.match(h.component.error, /回读身份或来源不一致/);
      await retry.completion;
      const final = await h.submit(), saved = savedSnapshot(final.call.body); assert.deepEqual(final.call.body, first.call.body);
      final.call.reply(saved); await until(() => h.calls.some(c => c.method === 'GET' && !c.settled), 'retry exact readback'); await h.flushReads(saved); await final.completion;
      assert.equal(h.component.error, ''); assert.equal(h.component.financeForm.source_refs, ''); assert.equal(h.component.currentFinance.id, saved.id);
    });
    await scenario('old A failure cannot replace B error or release a later B overview busy state', async h => {
      await h.fill(123.45, 'synthetic:A'); const a = await h.submit(); await h.scope(8, '2026-07');
      // B reads were started by the original selectors/watchers; keep them pending.
      await h.fill(999, 'synthetic:B'); const b = clone(h.component.financeForm), count = h.calls.length;
      assert.equal(h.component.loading, true); a.call.fail(); await a.completion;
      assert.equal(h.component.error, ''); assert.equal(h.component.loading, true); assert.equal(h.calls.length, count);
      assert.equal(h.notices.length, 0); assert.deepEqual(clone(h.component.financeForm), b); assert.equal(h.component.savingFinance, false);
      await h.flushReads(); assert.equal(h.component.overview.hotel_id, 8); assert.equal(h.component.overview.period_month, '2026-07');
    });
  } finally {
    if (docBefore === undefined) delete globalThis.Document; else globalThis.Document = docBefore;
    if (shadowBefore === undefined) delete globalThis.ShadowRoot; else globalThis.ShadowRoot = shadowBefore;
    if (option('evidence')) fs.writeFileSync(option('evidence'), JSON.stringify({ readers, sections, attempts,
      boundary: 'Synthetic public consumer DTOs/original request/auth/coordinator and full19c/component in memory renderer; no real HTTP/DB/browser or PHP.' }, null, 2) + '\n');
  }
}
