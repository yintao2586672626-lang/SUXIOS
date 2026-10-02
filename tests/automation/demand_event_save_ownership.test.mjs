import assert from 'node:assert/strict';
import test from 'node:test';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';
import { createHash, webcrypto } from 'node:crypto';
import * as Vue from 'vue';
import { compile } from '@vue/compiler-dom';

// The real source component and its full template run through Vue's native
// v-model/submit handlers. Transport and overview data are synthetic only.
const option = name => process.argv.find(s => s.startsWith(`--${name}=`))?.slice(name.length + 3);
let root = path.dirname(fileURLToPath(import.meta.url));
while (!fs.existsSync(path.join(root, 'public/components/system/operating-finance-control-center.js'))) {
  const parent = path.dirname(root); assert.notEqual(parent, root, 'repository root not found'); root = parent;
}
root = path.resolve(option('source-root') || root);
const readers = [], attempts = [];
const hash = value => createHash('sha256').update(value).digest('hex');
const read = file => { const bytes = fs.readFileSync(path.join(root, file)); readers.push({ path: file, sha256: hash(bytes) }); return bytes.toString('utf8'); };
const componentSource = read('public/components/system/operating-finance-control-center.js');
const serviceSource = read('app/service/BookingDemandPlanningService.php');
const parentTemplate = read('resources/frontend/templates/fragments/19c-page-operating-finance.html');
assert.match(parentTemplate, /:can-execute="operationFinanceCanExecute"/);
assert.match(serviceSource, /return \$this->hydrateEvent\(\$row\)/);
assert.match(serviceSource, /'source_ref_hash' => hash\('sha256', 'demand-event-source-v1\|'/);
assert.match(serviceSource, /'reference_only' => true/);
const clone = value => JSON.parse(JSON.stringify(value));
const tick = async () => { await Vue.nextTick(); await new Promise(resolve => setTimeout(resolve, 0)); await Vue.nextTick(); };
async function until(fn, label) { for (let i = 0; i < 35 && !fn(); i++) await tick(); assert.ok(fn(), label); }
const canonical = value => Array.isArray(value) ? value.map(canonical) : value && typeof value === 'object'
  ? Object.fromEntries(Object.keys(value).sort().map(k => [k, canonical(value[k])])) : value;
const normalizedTime = value => { const m = /^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})(?::(\d{2}))?$/.exec(value);
  assert.ok(m, 'fixed datetime-local fixture'); return `${m[1]} ${m[2]}:${m[3] || '00'}.000000`; };
function savedEvent(body, id = 501, tenantId = 70) {
  assert.ok(body.source_method); assert.ok(body.source_status);
  const content = { contract_version: 'hotel_demand_event_fact.v1', tenant_id: tenantId, hotel_id: body.hotel_id,
    source_hotel_id: body.hotel_id, event_name: body.event_name, event_type: body.event_type,
    event_start_date: body.event_start_date, event_end_date: body.event_end_date, area_label: body.area_label,
    source_method: body.source_method, source_ref_hash: hash('demand-event-source-v1|' + body.source_ref.trim()),
    source_status: body.source_status, observed_at: normalizedTime(body.observed_at), reference_only: 1 };
  const digest = hash(JSON.stringify(canonical(content)));
  return { id, ...content, reference_only: true,
    idempotency_key: hash('operating-finance-idempotency-v1|' + body.idempotency_key), content_digest: digest,
    created_by: 77, created_at: '2026-09-20 10:00:00.000000', causality_claimed: false,
    automatic_pricing: false, external_write_count: 0, idempotent: false };
}
function overview(call, saved = null) {
  const hotel = Number(call.query.hotel_id);
  const events = saved && saved.hotel_id === hotel ? [saved] : [];
  return { contract_version: 'operating_finance_control_center.v1', tenant_id: 70, hotel_id: hotel,
    hotel_name: `合成酒店 ${hotel}`, business_date: call.query.business_date, period_month: call.query.period_month,
    stay_date: call.query.stay_date, platform: call.query.platform,
    demand_calendar: { contract_version: 'hotel_demand_calendar.v1', tenant_id: 70, hotel_id: hotel,
      status: events.length ? 'ready' : 'empty', events, event_count: events.length,
      reference_only: true, causality_claimed: false, automatic_pricing: false, external_write_count: 0 },
    boundaries: { automatic_approval: false, automatic_external_send: false,
      automatic_ota_write: false, automatic_pms_write: false, external_write_count: 0 } };
}
function memoryHost() {
  const make = (type, text = '') => ({ type, text, props: {}, children: [], parent: null, style: {}, value: '', checked: false, selected: false, listeners: {},
    get options() { return this.children.filter(n => n.type === 'option'); },
    addEventListener(key, fn) { (this.listeners[key] ||= []).push(fn); },
    removeEventListener(key, fn) { this.listeners[key] = (this.listeners[key] || []).filter(x => x !== fn); },
    dispatchEvent(event) { for (const fn of this.listeners[event.type] || []) fn({ ...event, target: this }); },
    getRootNode() { let n = this; while (n.parent) n = n.parent; return n; },
  });
  const remove = n => { if (n.parent) n.parent.children.splice(n.parent.children.indexOf(n), 1); n.parent = null; };
  return { root: make('root'), options: {
    createElement: tag => make(tag), createText: text => make('text', text), createComment: text => make('comment', text),
    setText: (n, text) => { n.text = text; }, setElementText: (n, text) => { n.text = text; n.children = []; },
    parentNode: n => n.parent, nextSibling: n => n.parent?.children[n.parent.children.indexOf(n) + 1] || null,
    insert(n, p, anchor = null) { remove(n); n.parent = p; const i = anchor ? p.children.indexOf(anchor) : -1; if (i < 0) p.children.push(n); else p.children.splice(i, 0, n); },
    remove, patchProp(n, key, old, value) { n.props[key] = value; if (key === 'value') { n.value = value; n._value = value; } if (key === 'checked') n.checked = value; },
  } };
}
function harness(attempt) {
  const calls = [], notices = [], errors = [], warnings = [], host = memoryHost(); let app, component;
  const sandbox = { window: {}, crypto: webcrypto, TextEncoder, Date, Intl, URLSearchParams };
  vm.runInNewContext(componentSource, sandbox);
  const body = sandbox.window.SUXI_SYSTEM_COMPONENTS.OperatingFinanceControlCenterBody;
  body.render = new Function('Vue', compile(body.template, { mode: 'function', prefixIdentifiers: true }).code)(Vue); delete body.template;
  const renderer = Vue.createRenderer(host.options);
  const request = (route, options = {}) => {
    const url = new URL(route, 'https://synthetic.invalid');
    const method = options.method || 'GET';
    assert.ok((method === 'GET' && url.pathname === '/operating-finance/overview')
      || (method === 'POST' && url.pathname === '/operating-finance/demand-events'));
    const call = { method, route: url.pathname, query: Object.fromEntries(url.searchParams),
      body: options.body ? JSON.parse(options.body) : null, context: clone(options.businessContext), settled: false };
    calls.push(call);
    return new Promise(resolve => {
      call.reply = data => { assert.equal(call.settled, false); call.settled = true; call.response = clone(data);
        resolve({ code: 200, message: '操作成功', data }); };
      call.fail = () => { assert.equal(call.settled, false); call.settled = true; call.failure = true;
        resolve({ code: 500, message: method === 'POST' ? '需求事件保存失败' : '经营财务与恢复中心读取失败', data: null }); };
    });
  };
  const walk = n => [n, ...n.children.flatMap(walk)];
  const nodes = () => walk(host.root), text = n => n.type === 'comment' ? '' : n.text + n.children.map(text).join('');
  const section = () => nodes().find(n => n.props['data-testid'] === 'operating-finance-demand');
  const field = placeholder => walk(section()).find(n => n.props.placeholder === placeholder);
  const usable = n => { assert.ok(n, 'visible control exists'); for (let p = n; p; p = p.parent)
    assert.ok(!p.props.disabled && p.style.display !== 'none'); return n; };
  async function edit(n, value) { usable(n);
    if (n.type === 'select') { n.options.forEach(o => { o.selected = String(o._value ?? o.value) === String(value); }); n.value = value; n.dispatchEvent({ type: 'change' }); n.props.onChange?.({ target: n }); }
    else { n.value = value; n.dispatchEvent({ type: 'input' }); }
    await tick();
  }
  async function flushReads(saved = null) { for (let i = 0; i < 12; i++) { await tick();
    const pending = calls.filter(c => c.method === 'GET' && !c.settled);
    if (!pending.length && !component?.loading) return;
    for (const c of pending) c.reply(overview(c, saved)); }
    assert.fail('overview reads did not close'); }
  app = renderer.createApp({ methods: { showToast(message, type) { notices.push({ message, type }); } },
    render() { return Vue.h(body, { hotels: [{ id: 7, name: '合成酒店七' }, { id: 8, name: '合成酒店八' }],
      selectedHotelId: '7', canExecute: true, request }); } });
  app.mixin({ mounted() { if (this.$options.name === 'OperatingFinanceControlCenterBody') component = this; } });
  app.config.warnHandler = msg => warnings.push(msg); app.config.errorHandler = e => errors.push(e.stack || String(e));
  app.mount(host.root);
  attempt.capture = () => ({ calls: calls.map(({ reply, fail, ...c }) => c), notices, errors, warnings,
    state: component ? { hotel: component.hotelId, form: clone(component.eventForm), saving: component.savingEvent,
      loading: component.loading, error: component.error, overview: clone(component.overview) } : null, visible_text: text(host.root) });
  attempt.stop = async () => { app.unmount(); await tick(); };
  return { calls, notices, errors, warnings, flushReads, text: () => text(host.root),
    get component() { return component; },
    async ready() { await flushReads(); usable(nodes().find(n => n.props['data-testid'] === 'operating-finance-tab-demand')).props.onClick(); await tick(); assert.ok(section()); },
    async hotel(id) { const s = nodes().find(n => n.type === 'select' && n.options.some(o => text(o) === '合成酒店七')); await edit(s, String(id)); },
    async fill(name, ref) { await edit(field('事件名称'), name); await edit(field('影响区域'), '合成区域');
      await edit(field('来源引用/内容指纹'), ref); await edit(walk(section()).find(n => n.type === 'input' && n.props.type === 'datetime-local'), '2026-09-19T10:00:00'); },
    async submit() { const form = usable(walk(section()).find(n => n.type === 'form'));
      const count = calls.length, completion = form.props.onSubmit({ target: form, preventDefault() {}, stopPropagation() {} });
      await until(() => calls.slice(count).some(c => c.method === 'POST'), 'visible demand form reaches POST');
      const call = calls.slice(count).find(c => c.method === 'POST'); assert.equal(call.context.hotelId, call.body.hotel_id);
      return { completion, call }; },
  };
}
async function scenario(name, run) { await test(name, async () => { const attempt = { name }; attempts.push(attempt);
  try { const h = harness(attempt); await h.ready(); await run(h); await tick();
    assert.deepEqual(h.errors, []); assert.deepEqual(h.warnings, []); attempt.passed = true;
  } catch (e) { attempt.failure = e.stack; throw e; }
  finally { if (attempt.capture) attempt.before_teardown = attempt.capture(); if (attempt.stop) await attempt.stop();
    if (attempt.capture) attempt.after_teardown = attempt.capture(); delete attempt.capture; delete attempt.stop; } }); }

const docBefore = globalThis.Document, shadowBefore = globalThis.ShadowRoot;
globalThis.Document ??= class MemoryDocument {}; globalThis.ShadowRoot ??= class MemoryShadowRoot {};
try {
  await scenario('submitted A success preserves later edited B draft and identifies the saved version', async h => {
    await h.fill('合成活动 A', 'synthetic:A'); const a = await h.submit(); const submitted = clone(a.call.body);
    await h.fill('合成活动 B', 'synthetic:B'); const b = clone(h.component.eventForm);
    a.call.reply(savedEvent(submitted)); await tick();
    assert.deepEqual(clone(h.component.eventForm), b, 'late A must not clear B draft');
    assert.match(h.notices.at(-1)?.message || '', /提交版本.*后续修改已保留/);
    await h.flushReads(savedEvent(submitted)); await a.completion;
    assert.match(h.text(), /合成活动 A/); assert.equal(h.component.currentDemand.events[0].source_status, 'reference_only');
  });
  await scenario('late A success and failure cannot overwrite hotel B draft or read state', async h => {
    await h.fill('合成活动 A', 'synthetic:A'); const a = await h.submit();
    await h.hotel(8); await h.fill('合成活动 B', 'synthetic:B'); await h.flushReads();
    const b = clone(h.component.eventForm), count = h.calls.length;
    a.call.reply(savedEvent(a.call.body)); await tick();
    assert.deepEqual(clone(h.component.eventForm), b); assert.equal(h.calls.length, count);
    assert.equal(h.notices.length, 0); assert.equal(h.component.overview.hotel_id, 8);
    await h.flushReads(); await a.completion;
    const bSave = await h.submit(); await h.hotel(7); await h.flushReads();
    bSave.call.fail(); await bSave.completion;
    assert.equal(h.component.hotelId, '7'); assert.equal(h.component.error, ''); assert.equal(h.notices.length, 0);
  });
  await scenario('late A failure cannot place hotel A error on hotel B', async h => {
    await h.fill('合成活动 A', 'synthetic:A'); const a = await h.submit();
    await h.hotel(8); await h.fill('合成活动 B', 'synthetic:B'); await h.flushReads();
    const b = clone(h.component.eventForm), count = h.calls.length;
    a.call.fail(); await a.completion;
    assert.deepEqual(clone(h.component.eventForm), b); assert.equal(h.component.hotelId, '8');
    assert.equal(h.component.error, ''); assert.equal(h.notices.length, 0); assert.equal(h.calls.length, count);
  });
  await scenario('wrong hotel, quality and content receipt retain draft for exact retry', async h => {
    await h.fill('合成活动 A', 'synthetic:A'); let pending = await h.submit(); const originalBody = clone(pending.call.body), draft = clone(h.component.eventForm);
    const variants = [
      { hotel_id: 8, idempotency_key: 'event:' + 'a'.repeat(64) },
      { source_method: 'external_reference', source_status: 'verified_source', idempotency_key: 'event:' + 'b'.repeat(64) },
      { event_name: '其他活动', idempotency_key: 'event:' + 'c'.repeat(64) },
    ];
    for (const variant of variants) {
      const bad = savedEvent({ ...pending.call.body, ...variant }); pending.call.reply(bad); await tick();
      assert.deepEqual(clone(h.component.eventForm), draft); assert.equal(h.notices.at(-1)?.type, 'error');
      await h.flushReads(); await pending.completion;
      pending = await h.submit(); assert.deepEqual(pending.call.body, originalBody);
    }
    const saved = savedEvent(pending.call.body); pending.call.reply(saved); await h.flushReads(saved); await pending.completion;
    assert.equal(h.component.eventForm.name, ''); assert.match(h.notices.at(-1).message, /参考/);
    assert.match(h.text(), /合成活动 A.*仅作参考/);
  });
  await scenario('current request failure preserves draft and retry succeeds with overview', async h => {
    await h.fill('合成活动 A', 'synthetic:A'); const a = await h.submit(), draft = clone(h.component.eventForm);
    a.call.fail(); await a.completion; assert.deepEqual(clone(h.component.eventForm), draft);
    assert.match(h.component.error, /保存失败/); assert.equal(h.component.savingEvent, false);
    const retry = await h.submit(); assert.deepEqual(retry.call.body, a.call.body);
    const saved = savedEvent(retry.call.body); retry.call.reply(saved); await h.flushReads(saved); await retry.completion;
    assert.equal(h.component.error, ''); assert.equal(h.component.eventForm.name, '');
    assert.equal(h.component.currentDemand.events[0].id, saved.id); assert.match(h.text(), /合成活动 A/);
  });
  await scenario('tenant captured before overview refresh still rejects another tenant receipt', async h => {
    assert.equal(h.component.overview.tenant_id, 70);
    await h.fill('合成活动 租户负控', 'synthetic:tenant-negative');
    const pending = await h.submit(), draft = clone(h.component.eventForm), readSeq = h.component.requestSeq;
    const refresh = h.component.loadOverview(); await tick();
    const refreshCall = h.calls.at(-1), count = h.calls.length, refreshSeq = h.component.requestSeq;
    assert.equal(refreshCall.method, 'GET'); assert.ok(refreshSeq > readSeq);
    assert.equal(h.component.overview, null); assert.equal(h.component.loading, true);
    pending.call.reply(savedEvent(pending.call.body, 501, 71)); await pending.completion; await tick();
    assert.deepEqual(clone(h.component.eventForm), draft, 'wrong tenant cannot clear the submitted draft');
    assert.equal(h.component.savingEvent, false); assert.deepEqual(h.notices, []);
    assert.equal(h.component.error, '', 'older save failure cannot replace the current refresh state');
    assert.equal(h.component.overview, null); assert.equal(h.component.loading, true);
    assert.equal(h.component.requestSeq, refreshSeq); assert.equal(h.calls.length, count);
    assert.equal(refreshCall.settled, false);
    refreshCall.reply(overview(refreshCall)); await refresh; await tick();
    assert.equal(h.component.overview.tenant_id, 70); assert.equal(h.component.currentDemand.events.length, 0);
    assert.deepEqual(clone(h.component.eventForm), draft); assert.deepEqual(h.notices, []);
    assert.equal(h.component.loading, false);
  });
  await scenario('matching captured tenant receipt leaves the newer refresh in control of readback', async h => {
    assert.equal(h.component.overview.tenant_id, 70);
    await h.fill('合成活动 租户正控', 'synthetic:tenant-positive'); const pending = await h.submit();
    const readSeq = h.component.requestSeq, saved = savedEvent(pending.call.body);
    const refresh = h.component.loadOverview(); await tick();
    const refreshCall = h.calls.at(-1), count = h.calls.length, refreshSeq = h.component.requestSeq;
    assert.equal(refreshCall.method, 'GET'); assert.ok(refreshSeq > readSeq);
    assert.equal(h.component.overview, null); assert.equal(h.component.loading, true);
    pending.call.reply(saved); await pending.completion; await tick();
    assert.equal(h.component.eventForm.name, ''); assert.equal(h.component.savingEvent, false);
    assert.equal(h.notices.length, 1); assert.equal(h.notices[0].type, 'success');
    assert.match(h.notices[0].message, /已保存为仅作参考并完成服务端回读/);
    assert.equal(h.calls.length, count, 'save receipt cannot start a replacement overview request');
    assert.equal(h.component.requestSeq, refreshSeq); assert.equal(refreshCall.settled, false);
    assert.equal(h.component.overview, null); assert.equal(h.component.loading, true);
    refreshCall.reply(overview(refreshCall, saved)); await refresh; await tick();
    assert.equal(h.component.overview.tenant_id, 70); assert.equal(h.component.overview.hotel_id, 7);
    assert.equal(h.component.currentDemand.events[0].id, saved.id);
    assert.equal(h.component.currentDemand.events[0].tenant_id, 70);
    assert.equal(h.component.currentDemand.events[0].source_status, 'reference_only');
    assert.match(h.text(), /合成活动 租户正控/); assert.equal(h.component.loading, false);
    assert.equal(h.component.error, ''); assert.equal(h.calls.length, count);
  });
} finally {
  if (docBefore === undefined) delete globalThis.Document; else globalThis.Document = docBefore;
  if (shadowBefore === undefined) delete globalThis.ShadowRoot; else globalThis.ShadowRoot = shadowBefore;
  if (option('evidence')) fs.writeFileSync(option('evidence'), JSON.stringify({ readers, attempts,
    boundary: 'Full source component/template in Vue memory renderer; synthetic request/overview only; no HTTP, DB, browser, or PHP execution.' }, null, 2) + '\n');
}
