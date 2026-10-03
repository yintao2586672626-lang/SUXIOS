import assert from 'node:assert/strict';
import { after, test } from 'node:test';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';
import { createHash, webcrypto } from 'node:crypto';
import { File } from 'node:buffer';
import * as Vue from 'vue';
import { compile } from '@vue/compiler-dom';

// Actual source and full template, compiled with Vue and mounted in a memory renderer.
// Every request, response, hotel, date, file, and toast in this test is synthetic.
const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '../..');
const sourcePath = 'public/components/system/operating-finance-control-center.js';
const source = fs.readFileSync(path.join(root, sourcePath), 'utf8');
const sha = value => createHash('sha256').update(value).digest('hex');
const clone = value => value == null ? value : JSON.parse(JSON.stringify(value));
const sourceSha256 = sha(source);
const cases = [];
const reportPath = process.env.SUXI_SETTLEMENT_REPORT_PATH;
const documentBefore = globalThis.Document, shadowBefore = globalThis.ShadowRoot;
globalThis.Document ??= class MemoryDocument {};
globalThis.ShadowRoot ??= class MemoryShadowRoot {};
after(() => {
  if (reportPath) fs.writeFileSync(reportPath, JSON.stringify({
    source_path: path.join(root, sourcePath), source_sha256: sourceSha256,
    runner: 'Vue memory renderer, actual source/template, synthetic requests only', cases,
  }, null, 2) + '\n');
  if (documentBefore === undefined) delete globalThis.Document; else globalThis.Document = documentBefore;
  if (shadowBefore === undefined) delete globalThis.ShadowRoot; else globalThis.ShadowRoot = shadowBefore;
});

const tick = async () => { await Vue.nextTick(); await new Promise(resolve => setTimeout(resolve, 0)); await Vue.nextTick(); };
async function until(predicate, label) {
  for (let i = 0; i < 30 && !predicate(); i++) await tick();
  assert.ok(predicate(), label);
}
const canonical = value => Array.isArray(value) ? value.map(canonical) : value && typeof value === 'object'
  ? Object.fromEntries(Object.keys(value).sort().map(key => [key, canonical(value[key])])) : value;
const lines = marker => [{
  source_line_no: 1, business_date: '2026-09-01', amount_scope: 'settlement',
  gross_amount: marker === 'A' ? 1000 : 2000, gross_amount_basis: 'source_direct',
  commission_amount: 150, commission_amount_basis: 'source_direct', match_status: 'not_evaluated',
}];
const draft = marker => JSON.stringify(lines(marker));

function memoryHost() {
  const make = (type, text = '') => ({
    type, text, props: {}, children: [], parent: null, style: {}, value: '', checked: false,
    selected: false, listeners: {},
    get options() { return this.children.filter(node => node.type === 'option'); },
    addEventListener(key, fn) { (this.listeners[key] ||= []).push(fn); },
    removeEventListener(key, fn) { this.listeners[key] = (this.listeners[key] || []).filter(item => item !== fn); },
    dispatchEvent(event) { for (const fn of this.listeners[event.type] || []) fn({ ...event, target: this }); },
    getRootNode() { let node = this; while (node.parent) node = node.parent; return node; },
  });
  const remove = node => { if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1); node.parent = null; };
  return { root: make('root'), options: {
    createElement: tag => make(tag), createText: text => make('text', text), createComment: text => make('comment', text),
    setText: (node, text) => { node.text = text; }, setElementText: (node, text) => { node.text = text; node.children = []; },
    parentNode: node => node.parent, nextSibling: node => node.parent?.children[node.parent.children.indexOf(node) + 1] || null,
    insert(node, parent, anchor = null) { remove(node); node.parent = parent;
      const index = anchor ? parent.children.indexOf(anchor) : -1;
      if (index < 0) parent.children.push(node); else parent.children.splice(index, 0, node); },
    remove, patchProp(node, key, old, value) { node.props[key] = value;
      if (key === 'value') { node.value = value; node._value = value; }
      if (key === 'checked') node.checked = value; },
  } };
}

function receipt(call, override = {}) {
  const body = call.body;
  const scope = body.scope || {
    platform: body.form.platform, period_start: body.form.period_start, period_end: body.form.period_end,
    parser_version: 'canonical_settlement_json.v1', operator_attested: body.form.operator_attested === '1',
  };
  const fileSha = body.scope
    ? sha(JSON.stringify(canonical({ contract_version: 'suxios.ota_settlement_submitted_lines.v1', lines: body.lines })))
    : sha(draft('A'));
  // One row with two sourced amounts, no net-revenue or match evidence, is
  // partial under summarize(); manual_export can never yield available.
  const inputLine = body.lines?.[0] || lines('A')[0];
  const gaps = ['net_revenue_missing', 'refund_amount_missing',
    'settlement_amount_missing', 'subsidy_amount_missing'];
  const line = {
    id: 601, batch_id: 501, source_line_no: 1,
    source_line_sha256: sha('synthetic-source-line-A'), line_fingerprint: sha('synthetic-normalized-line-A'),
    business_date: inputLine.business_date, amount_scope: 'settlement',
    ota_order_ref_sha256: null, pms_stay_ref_sha256: null,
    gross_amount: inputLine.gross_amount, gross_amount_basis: 'source_direct',
    commission_amount: inputLine.commission_amount, commission_amount_basis: 'source_direct',
    subsidy_amount: null, subsidy_amount_basis: 'missing',
    refund_amount: null, refund_amount_basis: 'missing',
    settlement_amount: null, settlement_amount_basis: 'missing',
    net_revenue: null, net_revenue_basis: 'missing', net_revenue_formula: null,
    match_status: 'not_evaluated', ota_comparison_amount: null, pms_comparison_amount: null,
    comparison_basis: null, discrepancy_amount: null, discrepancy_basis: 'missing',
    quality_status: 'partial', gap_codes: gaps,
  };
  const totals = Object.fromEntries([
    ['gross_amount', inputLine.gross_amount, 'complete_source_direct'],
    ['commission_amount', inputLine.commission_amount, 'complete_source_direct'],
    ['subsidy_amount', null, 'missing'], ['refund_amount', null, 'missing'],
    ['settlement_amount', null, 'missing'], ['net_revenue', null, 'missing'],
  ].map(([key, value, basis]) => [key, { value, basis }]));
  const components = Object.fromEntries([
    ['order_gross_amount', 'gross_amount'], ['commission_amount', 'commission_amount'],
    ['refund_amount', 'refund_amount'], ['adjustment', 'subsidy_amount'],
    ['settlement_amount', 'settlement_amount'], ['net_revenue', 'net_revenue'],
  ].map(([key, metric]) => [key, { metric_key: metric, ...totals[metric] }]));
  components.adjustment.component_scope = 'platform_subsidy_only';
  components.adjustment.generic_adjustment_amount_claimed = false;
  return {
    contract_version: 'ota_settlement_reconciliation.v1', batch_id: 501,
    supersedes_batch_id: null, supersession_reason: null,
    batch_fingerprint: sha('synthetic-partial-batch-A'), reused: false,
    read_status: 'available', readback_verified: true, request_status: 'saved_and_readback_verified',
    batch_status: 'partial', business_result_status: 'partial', business_success: false,
    usable_net_revenue_fact_created: false, warning_code: 'settlement_batch_partial_review_required',
    scope: { tenant_id: 70, hotel_id: call.context.hotelId, source_hotel_id: call.context.hotelId,
      platform: scope.platform, period_start: scope.period_start, period_end: scope.period_end },
    source: { file_sha256: fileSha, source_evidence_sha256: null, source_method: 'manual_export',
      source_quality_status: scope.operator_attested ? 'operator_attested' : 'unverified',
      parser_version: scope.parser_version },
    file_parser: body.form ? { contract_version: 'ota_settlement_file_parser.v1',
      parser_version: scope.parser_version, row_count: 1, file_sha256: fileSha,
      original_filename_retained: false } : undefined,
    counts: { line_count: 1, available: 0, partial: 1, invalid: 0 }, totals,
    basis_ledger: { contract_version: 'ota_settlement_financial_basis_ledger.v1',
      metric_scope: 'ota_channel_settlement', components,
      boundaries: { components_are_interchangeable: false,
        generic_adjustment_amount_claimed: false, settlement_amount_is_net_revenue: false,
        whole_hotel_gop_claimed: false } },
    authorization: { external_write_authorized: false, ota_write_authorized: false,
      pms_write_authorized: false, accounting_write_authorized: false },
    lines: [line], ranked_discrepancies: [], ...override,
  };
}

function harness() {
  const host = memoryHost(), calls = [], notices = [], errors = [], warnings = [];
  const sandbox = { window: {}, crypto: webcrypto, TextEncoder, Date, Intl, URLSearchParams, FormData };
  vm.runInNewContext(source, sandbox);
  const body = sandbox.window.SUXI_SYSTEM_COMPONENTS.OperatingFinanceControlCenterBody;
  body.render = new Function('Vue', compile(body.template, { mode: 'function', prefixIdentifiers: true }).code)(Vue);
  delete body.template;
  const walk = node => [node, ...node.children.flatMap(walk)];
  const text = node => node.type === 'comment' ? '' : node.text + node.children.map(text).join('');
  const nodes = () => walk(host.root);
  const select = label => nodes().find(node => node.type === 'select' && node.options.some(option => text(option) === label));
  const settlement = () => nodes().find(node => node.props['data-testid'] === 'operating-finance-settlement');
  let component;
  const request = (route, options = {}) => {
    const url = new URL(route, 'https://synthetic.invalid');
    const method = options.method || 'GET';
    assert.ok((method === 'GET' && url.pathname === '/operating-finance/overview')
      || (method === 'POST' && url.pathname.startsWith('/operating-finance/settlements/import')));
    if (method === 'GET') {
      calls.push({ method, route: url.pathname, query: Object.fromEntries(url.searchParams) });
      return Promise.resolve({ code: 200, data: { contract_version: 'operating_finance_control_center.v1',
        tenant_id: 70, hotel_id: Number(url.searchParams.get('hotel_id')),
        boundaries: { external_write_count: 0 } } });
    }
    const form = options.body instanceof FormData ? Object.fromEntries(options.body.entries()) : null;
    const call = { method, route: url.pathname, context: clone(options.businessContext),
      body: form ? { form: Object.fromEntries(Object.entries(form).map(([key, value]) =>
        [key, key === 'file' ? { name: value.name, size: value.size } : value])) } : JSON.parse(options.body), settled: false };
    calls.push(call);
    return new Promise(resolve => { call.reply = (data = receipt(call)) => {
      assert.equal(call.settled, false); call.settled = true;
      resolve({ code: 200, message: '结算批次已保存并精确回读，但仅部分可用；请按缺口修正后再用于经营判断', data }); };
      call.fail = () => { assert.equal(call.settled, false); call.settled = true;
        resolve({ code: 500, message: '合成 A 请求失败', data: null }); }; });
  };
  const renderer = Vue.createRenderer(host.options);
  const app = renderer.createApp({ methods: { showToast(message, type) { notices.push({ message, type }); } },
    render() { return Vue.h(body, { hotels: [{ id: 7, name: '合成酒店七' }, { id: 8, name: '合成酒店八' }],
      selectedHotelId: '7', canExecute: true, request }); } });
  // The production finance facade owns these unrelated async siblings.
  // Keep this ownership fixture isolated while retaining warning capture.
  for (const name of ['BusinessFeatureWorkspace', 'OperatingEconomicsWorkbench', 'BookingMonitoringPanel', 'InvestmentOperatingBridgePanel']) {
    app.component(name, { name: `${name}Fixture`, render: () => null });
  }
  app.mixin({ mounted() { if (this.$options.name === 'OperatingFinanceControlCenterBody') component = this; } });
  app.config.warnHandler = message => warnings.push(message);
  app.config.errorHandler = error => errors.push(error.stack || String(error));
  app.mount(host.root);
  async function edit(node, value) {
    assert.ok(node, 'actual visible input');
    if (node.type === 'select') { node.options.forEach(option => {
      option.selected = String(option._value ?? option.value) === String(value); });
      node.value = value; node.dispatchEvent({ type: 'change' }); node.props.onChange?.({ target: node }); }
    else { node.value = value; node.dispatchEvent({ type: 'input' });
      node.props.onInput?.({ target: node }); }
    await tick();
  }
  return {
    calls, notices, errors, warnings, get component() { return component; },
    async ready() { await until(() => component && !component.loading && settlement(), 'settlement UI ready');
      await edit(nodes().find(node => node.type === 'input' && node.props.type === 'month'), '2026-09'); },
    async text(value) { await edit(walk(settlement()).find(node => node.type === 'textarea'), value); },
    async scope(kind) {
      if (kind === 'hotel') await edit(select('合成酒店七'), '8');
      if (kind === 'month') await edit(nodes().find(node => node.type === 'input' && node.props.type === 'month'), '2026-10');
      if (kind === 'platform') await edit(select('携程'), 'meituan');
    },
    startSubmit() { const form = walk(settlement()).find(node => node.type === 'form');
      assert.ok(form, 'actual settlement form visible');
      return form.props.onSubmit({ target: form, preventDefault() {}, stopPropagation() {} }); },
    async submit() {
      const count = calls.length;
      const completion = this.startSubmit();
      await until(() => calls.slice(count).some(call => call.method === 'POST'), 'POST reached synthetic transport');
      return { completion, call: calls.slice(count).find(call => call.method === 'POST') }; },
    async choose(file) { const input = walk(settlement()).find(node => node.type === 'input' && node.props.type === 'file');
      assert.ok(input, 'actual file input visible'); input.files = [file];
      input.props.onChange?.({ target: input }); await tick(); },
    state() { return { hotel: component.hotelId, month: component.periodMonth, platform: component.platform,
      text: component.settlementText, file: component.settlementFileName, saving: component.savingSettlement,
      error: component.error, notice: clone(component.settlementImportNotice),
      post_count: calls.filter(call => call.method === 'POST').length,
      get_count: calls.filter(call => call.method === 'GET').length,
      toasts: clone(notices), errors: clone(errors), warnings: clone(warnings) }; },
    async stop() { app.unmount(); await tick(); },
  };
}

async function scenario(name, action) {
  const h = harness(); let actual;
  try { await h.ready(); await action(h); actual = { status: 'PASS', state: h.state() }; }
  catch (error) { actual = { status: 'FAIL', message: error.message, state: h.state() }; throw error; }
  finally { cases.push({ name, ...actual }); await h.stop(); }
}

test('control: matching JSON receipt reaches success path', () => scenario('json_matching_receipt', async h => {
  await h.text(draft('A'));
  const { completion, call } = await h.submit();
  assert.equal(call.route, '/operating-finance/settlements/import');
  assert.equal(call.context.hotelId, 7);
  assert.equal(call.body.scope.platform, 'ctrip');
  assert.equal(call.body.scope.period_start, '2026-09-01');
  call.reply(); await completion; await tick();
  assert.equal(h.state().notice?.status, 'partial');
  assert.equal(h.state().text, '');
}));

test('delayed JSON A success preserves later textarea edit B', () => scenario('json_edit_B_preserved', async h => {
  await h.text(draft('A'));
  const { completion, call } = await h.submit();
  await h.text(draft('B'));
  call.reply(); await completion; await tick();
  assert.equal(h.state().text, draft('B'), 'submitted A must not erase later B');
}));

for (const kind of ['hotel', 'month', 'platform']) {
  for (const outcome of ['success', 'failure']) {
    test(`stale ${kind} A ${outcome} does not update current B UI`, () => scenario(`stale_${kind}_${outcome}`, async h => {
      await h.text(draft('A'));
      const { completion, call } = await h.submit();
      await h.scope(kind);
      await h.text(draft('B'));
      const getCount = h.state().get_count, toastCount = h.state().toasts.length;
      if (outcome === 'success') call.reply(); else call.fail();
      await completion; await tick();
      assert.equal(h.state().text, draft('B'), 'new scope draft B is still visible');
      assert.equal(h.state().notice, null, 'old scope result cannot become new scope notice');
      assert.equal(h.state().error, '', 'old scope failure cannot become new scope error');
      assert.equal(h.state().toasts.length, toastCount, 'old scope cannot toast in new scope');
      assert.equal(h.state().get_count, getCount, 'old scope cannot refresh new scope');
    }));
  }
}

for (const mismatch of ['hotel', 'platform', 'period', 'malformed_file_hash', 'quality', 'parser']) {
  test(`JSON ${mismatch} receipt mismatch is rejected`, () => scenario(`json_receipt_mismatch_${mismatch}`, async h => {
    await h.text(draft('A'));
    const { completion, call } = await h.submit();
    const saved = receipt(call);
    if (mismatch === 'hotel') saved.scope.hotel_id = 8;
    if (mismatch === 'platform') saved.scope.platform = 'meituan';
    if (mismatch === 'period') saved.scope.period_end = '2026-10-31';
    if (mismatch === 'malformed_file_hash') saved.source.file_sha256 = 'malformed-hash';
    if (mismatch === 'quality') saved.source.source_quality_status = 'operator_attested';
    if (mismatch === 'parser') saved.source.parser_version = 'other_parser.v1';
    call.reply(saved); await completion; await tick();
    assert.equal(h.state().text, draft('A'), 'mismatched receipt cannot clear submitted draft');
    assert.equal(h.state().notice, null, 'mismatched receipt cannot claim confirmed success');
    assert.ok(h.state().error, 'mismatched receipt must surface unconfirmed result');
  }));
}

test('control: file path submits the selected JSON with its scope', () => scenario('file_matching_receipt', async h => {
  const file = new File([Buffer.from(draft('A'))], 'A.json');
  await h.choose(file);
  const { completion, call } = await h.submit();
  assert.equal(call.route, '/operating-finance/settlements/import-file');
  assert.equal(call.body.form.hotel_id, '7');
  assert.equal(call.body.form.platform, 'ctrip');
  assert.equal(call.body.form.period_start, '2026-09-01');
  call.reply(); await completion; await tick();
  assert.equal(h.state().notice?.status, 'partial');
}));

test('file path rejects receipt for another hotel', () => scenario('file_receipt_mismatch_hotel', async h => {
  await h.choose(new File([Buffer.from(draft('A'))], 'A.json'));
  const { completion, call } = await h.submit();
  const saved = receipt(call); saved.scope.hotel_id = 8;
  call.reply(saved); await completion; await tick();
  assert.equal(h.state().file, 'A.json', 'mismatched file receipt cannot clear selected file');
  assert.equal(h.state().notice, null);
  assert.ok(h.state().error);
}));

test('file path rejects a receipt for different file bytes', () => scenario('file_receipt_mismatch_hash', async h => {
  await h.choose(new File([Buffer.from(draft('A'))], 'A.json'));
  const { completion, call } = await h.submit();
  const saved = receipt(call);
  saved.source.file_sha256 = 'f'.repeat(64);
  saved.file_parser.file_sha256 = saved.source.file_sha256;
  call.reply(saved); await completion; await tick();
  assert.equal(h.state().file, 'A.json', 'different file receipt cannot clear selected file');
  assert.equal(h.state().notice, null);
  assert.ok(h.state().error);
}));

test('new hotel can submit B while old hotel A is pending', () => scenario('new_scope_not_blocked_by_old_save', async h => {
  await h.text(draft('A'));
  const old = await h.submit();
  await h.scope('hotel');
  await h.text(draft('B'));
  const next = await h.submit();
  assert.equal(next.call.context.hotelId, 8);
  next.call.reply(); await next.completion; await tick();
  const notice = h.state().notice;
  assert.equal(notice?.status, 'partial');
  old.call.reply(); await old.completion; await tick();
  assert.deepEqual(h.state().notice, notice, 'old scope cannot replace new scope result');
}));

test('same-scope overview read does not hide save failure', () => scenario('same_scope_read_then_save_failure', async h => {
  await h.text(draft('A'));
  const { completion, call } = await h.submit();
  await h.component.loadOverview();
  call.fail(); await completion; await tick();
  assert.equal(h.state().text, draft('A'));
  assert.match(h.state().error, /合成 A 请求失败/);
}));

test('switching hotel before a slow file hash cancels the old write', () => scenario('scope_change_before_file_post', async h => {
  let releaseHash;
  class SlowFile extends File {
    arrayBuffer() { return new Promise(resolve => {
      releaseHash = async () => resolve(await super.arrayBuffer());
    }); }
  }
  await h.choose(new SlowFile([Buffer.from(draft('A'))], 'A.json'));
  const completion = h.startSubmit();
  await until(() => releaseHash, 'file hash started');
  await h.scope('hotel');
  await h.text(draft('B'));
  await releaseHash(); await completion; await tick();
  assert.equal(h.state().post_count, 0, 'cancelled old scope must not write');
  assert.equal(h.state().text, draft('B'));
  assert.equal(h.state().saving, false);
}));

test('delayed file A parse cannot overwrite later textarea B', () => scenario('file_parse_edit_B_preserved', async h => {
  let release;
  const file = { name: 'A.json', text: () => new Promise(resolve => { release = resolve; }) };
  await h.choose(file);
  await until(() => release, 'file text read started');
  await h.text(draft('B'));
  assert.equal(h.state().file, '', 'textarea edit cancelled selected file');
  release(draft('A')); await tick();
  assert.equal(h.state().text, draft('B'), 'old file parse cannot overwrite current textarea B');
}));
