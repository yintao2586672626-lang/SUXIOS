import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';
import { createHash } from 'node:crypto';
import * as Vue from 'vue';
import { parse, compile } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';

// A saved-detail consumer projection, not a PHP calculation or persistence proof.
// The real request boundary is injected: no HTTP, DB, authorization change or save.
const repository = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const option = name => process.argv.find(value => value.startsWith(`--${name}=`))?.slice(name.length + 3);
const sourceRoot = path.resolve(option('source-root') || repository);
const readers = [];
const read = relative => {
  const resolved = path.join(sourceRoot, relative), bytes = fs.readFileSync(resolved);
  readers.push({ path: relative, resolved_path: resolved, sha256: createHash('sha256').update(bytes).digest('hex').toUpperCase() });
  return bytes.toString('utf8');
};
const main = read('public/app-main.js');
const staticSource = read('public/simulation-static.js');
const template = read('resources/frontend/templates/fragments/02-page-ai-simulation.html');
const cut = (source, start, end) => {
  const a = source.indexOf(start), b = source.indexOf(end, a);
  assert.ok(a >= 0 && b > a, start); return source.slice(a, b);
};
const clone = value => JSON.parse(JSON.stringify(value));
const flatten = node => !node || typeof node !== 'object' ? [] : [node, ...(Array.isArray(node.children) ? node.children.flatMap(flatten) : [])];
const tick = async () => { await Promise.resolve(); await Vue.nextTick(); };
function savedRecord() {
  // Public keys from QuantSimulationService::normalizeInput/formatRecord and
  // QuantOperatingScenarioService. Omitted result metadata stays omitted.
  const input = { hotel_id: 901, system_hotel_id: 901, input_source_status: 'manual_unverified',
    roomCount: 10, adr: 100, occupancyRate: 50, weekdayDays: 29, weekdayAdr: 100, weekdayOccupancyRate: 50,
    weekendDays: 0, weekendAdr: 0, weekendOccupancyRate: 0, holidayDays: 0, holidayAdr: 0, holidayOccupancyRate: 0,
    decorationInvestment: 30000.12, decorationHardCost: 30000.12, decorationSoftCost: 0, fireSafetyCost: 0, signageDesignCost: 0,
    furnitureInvestment: 0, roomFurnitureCost: 0, applianceEquipmentCost: 0, linenSuppliesCost: 0, techSystemCost: 0,
    openingCost: 0, licensePermitCost: 0, openingMarketingCost: 0, recruitmentTrainingCost: 0, openingMaterialCost: 0,
    otherInvestment: 0, contingencyCost: 0, rentDepositCost: 0, otherProjectCost: 0,
    otherIncome: 0, breakfastIncome: 0, meetingIncome: 0, retailIncome: 0, parkingLaundryIncome: 0, otherMiscIncome: 0,
    monthlyRent: 5000, baseRentCost: 5000, propertyManagementCost: 0,
    laborCost: 1000, frontDeskLaborCost: 1000, housekeepingLaborCost: 0, managementLaborCost: 0, socialSecurityCost: 0,
    utilityCost: 500, electricityCost: 500, waterGasCost: 0, networkEnergyCost: 0,
    otaCommissionRate: 10, ctripRevenueShare: 100, ctripCommissionRate: 10, meituanRevenueShare: 0, meituanCommissionRate: 0, otherOtaRevenueShare: 0, otherOtaCommissionRate: 0,
    consumableCost: 0, roomConsumableCost: 0, cleaningSuppliesCost: 0, linenReplacementCost: 0,
    maintenanceCost: 0, routineRepairCost: 0, equipmentMaintenanceCost: 0, roomRenovationReserve: 0,
    otherFixedCost: 0, marketingSystemCost: 0, insuranceTaxCost: 0, adminMiscCost: 0,
    client_request_id: 'internal-request-not-for-report',
    operatingScenario: { schema_version: 'quant-operating.v1', case_type: 'existing_hotel', case_name: '合成 A | "东楼"',
      start_month: '2024-02', horizon_months: 1, target_payback_months: 1, ramp_months: 0, ramp_start_occupancy: 0,
      loan_amount: 0, annual_interest_rate: 0, loan_term_months: 0, opening_cash: 40000.12, minimum_monthly_cashflow: 0,
      currency: 'CNY', monetary_unit: 'yuan', evidence_basis: 'manual_pms_cost_unverified',
      source_note: '合成来源，业务日期2024-01-31 | "A"\n<script>not executable</script>' } };
  const result = { totalInvestment: 30000.12, roomRevenue: 14500, monthlyRevenue: 14500, monthlyCost: 7950, monthlyNetCashflow: 6550,
    revPAR: 50, paybackMonths: 4.58, rentRatio: 0.3448, breakEvenOccupancy: 0.249, breakEvenOccupancyStatus: 'reachable', riskLevel: '中风险',
    operatingScenario: { status: 'calculated_assumptions', currency: 'CNY', monetary_unit: 'yuan', end_month: '2024-02',
      project_payback: { status: 'not_recovered_within_horizon', months: null }, equity_payback: { status: 'not_recovered_within_horizon', months: null },
      funding_required: 30000.12, additional_cash_gap: 0, ending_cash_balance: 16550, outstanding_loan: 0,
      cash_break_even_occupancy: 0.249042, cash_break_even_status: 'reachable', monthly_rent_ceiling: 11550, rent_status: 'within_ceiling',
      target_payback_months: 1, target_rent_ceiling: -18450.12, target_status: 'unreachable_even_without_rent',
      monthly_cash_target: { minimum: 0, status: 'met' },
      cashflow_series: [{ date: '2024-02-01', phase: 'stable', days: 29, occupancy_pct: 50, minimum_cash_target_status: 'met',
        revenue: 14500, commission: 1450, fixed_cost: 6500, interest: 0, principal: 0, loan_balance: 0,
        project_cashflow: 6550, equity_cashflow: 6550, project_cumulative: -23450.12, equity_cumulative: -23450.12, cash_balance: 16550 }],
      formulas: ['合成已存公式说明：项目现金流 = 房费 + 其他月收入 − 佣金 − 月成本；未折现。'],
      sensitivity: [{ label: '融资年利率增加2个百分点', status: 'not_applicable_no_loan' }] } };
  const { operatingScenario: _operatingScenario, ...steady } = result;
  return { id: 301, project_name: '合成 A | "东楼"', created_at: '2026-09-20 10:00:00', created_by: 999,
    _execution_source_tenant_id: 9, input, result,
    scenarios: [{ ...steady, scenarioType: '保守情景', monthlyRevenue: 9280, monthlyCost: 7428, monthlyNetCashflow: 1852, revPAR: 32, paybackMonths: 16.2, rentRatio: 0.5388, breakEvenOccupancy: 0.3113 },
      { ...steady, scenarioType: '基准情景' },
      { ...steady, scenarioType: '乐观情景', monthlyRevenue: 22184, monthlyCost: 8518.4, monthlyNetCashflow: 13665.6, revPAR: 69.6, paybackMonths: 2.2, rentRatio: 0.2254, breakEvenOccupancy: 0.1437 }],
    risk_hints: [{ title: '回本约束', riskLevel: '需复核', content: '仅在合成假设与期限内有效，不是经营实绩。' }],
    model_analysis: { source: 'deterministic_formula', summary: '合成公式解释，非投资建议。', generated_at: '2026-09-20 10:00:00' },
    truth_context: { hotel_id: 901, tenant_id: 9, status: 'unverified', scope_label: '投资情景测算，不是OTA数据，也不是全酒店经营实绩',
      failure_reason: '人工输入缺少经营来源核验', persistence: { readback_verified: true } } };
}

function harness(t, row) {
  const downloads = [], toasts = [], requests = [], timers = [], urls = new Map(), errors = [];
  let pending = 0, tree;
  t.after(() => {
    timers.forEach(timer => { assert.equal(timer.ms, 60000); timer.fn(); });
    assert.equal(pending, 0); assert.equal(urls.size, 0); assert.deepEqual(errors, []);
    t.diagnostic(JSON.stringify({ sourceReaders: readers, requests: requests.map(value => ({ url: value.url, settled: value.settled })),
      blobs: downloads.length, pending, objectUrls: urls.size, vueErrors: errors.length, boundary: 'injected original request port; no HTTP/DB/PHP/native browser; only Blob cleanup timers run' }));
  });
  // Keep every original ancestor, v-if and v-for; prune only sibling subtrees.
  const keep = node => {
    if (node.type === 1 && node.tag === 'button' && node.props.some(prop => prop.type === 7 && prop.exp?.content === 'downloadSimulationRecord(record)')) return node;
    const children = (node.children || []).map(keep).filter(Boolean); return children.length ? { ...node, children } : null;
  };
  const pruned = keep(parse(template));
  assert.ok(pruned, 'Saved history has a visible export action (baseline lacks this feature)');
  const render = new Function('Vue', compile(pruned, { mode: 'function', prefixIdentifiers: true }).code)(Vue);
  const sandbox = { ...Vue, Blob, URL: class extends URL {}, window: {}, authSessionEpoch: 1, pageRequestGeneration: 1,
    token: Vue.ref('synthetic-session-not-a-credential'), currentPage: Vue.ref('ai-simulation'),
    authContext: Vue.ref({ tenant_id: 9 }), filterReportHotel: Vue.ref('901'), revenueAiBusinessDate: Vue.ref('2026-09-20'), coreOperationsTargetDate: Vue.ref('2026-09-20'),
    currentBusinessRequestContext: () => ({ tenant_id: 9, system_hotel_id: 901, business_date: '2026-09-20' }),
    aiSimulationParams: Vue.ref({ hotel_id: 902, roomCount: 88, decorationInvestment: 999999, operatingScenario: { source_note: 'UNSAVED DRAFT DO NOT EXPORT' } }),
    aiSimulationRecords: Vue.ref([clone(row)]), showToast: (message, type = 'success') => toasts.push({ message, type }),
    request: (url, options) => {
      assert.match(url, /^\/simulation\/records\/\d+$/); assert.equal(options.requestPolicy.pageKey, 'ai-simulation');
      assert.equal(options.method, undefined); assert.equal(options.body, undefined); pending++;
      return new Promise((resolve, reject) => { requests.push({ url, settled: false,
        resolve(value) { this.settled = true; pending--; resolve(value); }, reject(error) { this.settled = true; pending--; reject(error); } }); });
    },
    fetch: () => { throw new Error('Real HTTP forbidden'); },
    document: { createElement: tag => { assert.equal(tag, 'a'); return { style: {}, isConnected: false,
      click() { downloads.push({ blob: urls.get(this.href), filename: this.download }); }, remove() { this.isConnected = false; } }; },
      body: { appendChild(link) { link.isConnected = true; } } },
  };
  sandbox.URL.createObjectURL = blob => { const url = `blob:synthetic/${urls.size}`; urls.set(url, blob); return url; };
  sandbox.URL.revokeObjectURL = url => urls.delete(url);
  sandbox.window.setTimeout = (fn, ms) => { timers.push({ fn, ms }); return timers.length; };
  vm.runInNewContext(staticSource, sandbox);
  sandbox.simulationStatic = Vue.ref(sandbox.window.SUXI_SIMULATION_STATIC);
  sandbox.ensureSimulationStaticReady = async () => sandbox.simulationStatic.value; // Already-loaded helper boundary.
  const definitions = [cut(main, '            const captureAuthSession =', '            const createDefaultAuthContext ='),
    cut(main, '            const currentPageReadPolicy =', '            // /compass is scoped'),
    cut(main, '            const isPageLoadPolicyCurrent =', '            const cancelPageLoadRequests ='),
    cut(main, '            const simulationStaticOption =', '            const requireSimulationStaticFunction ='),
    cut(main, '            const downloadBlob =', '            const buildCtripBusinessCanvas ='),
    cut(main, '            const simulationExportLoadingId =', '            const reuseSimulationRecord =')];
  vm.runInNewContext(definitions.join('\n') + '\nglobalThis.ui = { downloadSimulationRecord, simulationExportLoadingId };', sandbox);
  async function button() {
    const app = Vue.createSSRApp({ setup: () => ({ ...sandbox.ui, simulationHistoryState: { loaded: true }, currentPage: sandbox.currentPage, aiSimulationRecords: sandbox.aiSimulationRecords }),
      render(...args) { tree = render.apply(this, args); return tree; } });
    app.config.warnHandler = message => errors.push(message); app.config.errorHandler = error => errors.push(error.message);
    await renderToString(app); assert.deepEqual(errors, []);
    const buttons = flatten(tree).filter(node => node.props?.['data-testid'] === `history-simulation-export-${row.id}`);
    assert.equal(buttons.length, 1); return buttons[0];
  }
  async function start() { const control = await button(); assert.equal(Boolean(control.props.disabled), false); const promise = control.props.onClick(); await tick(); return { promise }; }
  return { sandbox, downloads, toasts, requests, button, start };
}

test('saved record -> original export button -> exact detail -> readable Markdown without current draft', async t => {
  const saved = savedRecord(), before = clone(saved), h = harness(t, saved), draft = clone(h.sandbox.aiSimulationParams.value);
  const action = await h.start(); assert.equal(Boolean((await h.button()).props.disabled), true);
  assert.equal(h.requests.length, 1); assert.equal(h.requests[0].url, '/simulation/records/301');
  h.requests[0].resolve({ code: 200, data: saved }); await action.promise;
  assert.equal(h.downloads.length, 1); const file = h.downloads[0], contents = await file.blob.text();
  assert.equal(file.blob.type, 'text/markdown;charset=utf-8');
  assert.equal(file.filename, '测算报告-301-合成 A _ _东楼_-2026-09-20.md');
  for (const text of ['人工输入与情景测算，来源未核验', '记录 ID：301', '酒店 ID：901', '2026-09-20 10:00:00（不是来源业务日期）',
    '2024-02 起，共 1 月', '业务日期2024-01-31', '30000.12 元', '| 押金/保证金 | 0 元 |', '| 综合入住率 | 50% |',
    '保守情景', '基准情景', '乐观情景', '13665.6 元', 'CNY / yuan', '| 额外现金缺口 | 0 元 |',
    '测算期内未回本；月数：未记录', '16550', '回本约束', '确定性公式解释（假设测算）', '记录未保存三情景公式版本',
    '合成已存公式说明', '已确认保存记录；输入仍未核验', '\\| "A"<br>&lt;script&gt;not executable&lt;/script&gt;']) assert.ok(contents.includes(text), text);
  for (const forbidden of ['UNSAVED DRAFT', '999999', '<script>', 'client_request_id', 'internal-request-not-for-report', 'created_by', '_execution_source_tenant_id']) assert.equal(contents.includes(forbidden), false, forbidden);
  assert.deepEqual(saved, before); assert.deepEqual(clone(h.sandbox.aiSimulationParams.value), draft);
  assert.equal(Boolean((await h.button()).props.disabled), false); assert.equal(h.toasts.length, 1); assert.equal(h.toasts[0].type, 'success');
});

test('read failure produces no file; explicit retry exports old record zero/missing without new scenario defaults', async t => {
  const old = { id: 302, project_name: '旧方案', created_at: '', input: { roomCount: 10, monthlyRent: 0 },
    result: { monthlyRevenue: 0, monthlyCost: 0, monthlyNetCashflow: 0, paybackMonths: null }, scenarios: [], risk_hints: [],
    truth_context: { hotel_id: null, tenant_id: 9, status: 'unverified', persistence: { readback_verified: true } }, access_policy: { mode: 'legacy_read_only', mutation_allowed: false } };
  const h = harness(t, old), first = await h.start();
  h.requests[0].reject(new Error('Synthetic private exception body MUST_NOT_LEAK')); await first.promise;
  assert.equal(h.downloads.length, 0); assert.equal(h.toasts[0].message, '测算报告导出失败，请重试');
  assert.equal(Boolean((await h.button()).props.disabled), false);
  const retry = await h.start(); assert.equal(h.requests.length, 2); h.requests[1].resolve({ code: 200, data: old }); await retry.promise;
  assert.equal(h.downloads.length, 1); const contents = await h.downloads[0].blob.text();
  assert.match(contents, /旧版单月方案：未记录融资、爬坡及现金约束/); assert.match(contents, /酒店 ID：未记录/);
  assert.match(contents, /\| 月租金合计 \| 0 元 \|/); assert.match(contents, /\| 人工成本合计 \| 未记录 \|/);
  assert.match(contents, /基准结果 \| 0 元 \| 0 元 \| 0 元 \| 未记录 \| 当前现金流下不可回本/);
  assert.match(contents, /未记录风险提示，不等于无风险/); assert.equal(contents.includes('MUST_NOT_LEAK'), false);
  assert.equal(h.downloads[0].filename, '测算报告-302-旧方案-日期未记录.md'); assert.equal(Boolean((await h.button()).props.disabled), false);
});

test('a different detail identity or hotel never becomes the requested saved report', async t => {
  const saved = savedRecord(), h = harness(t, saved);
  for (const change of [value => { value.id = 999; }, value => { value.truth_context.hotel_id = 902; }]) {
    const action = await h.start(), wrong = clone(saved); change(wrong);
    h.requests.at(-1).resolve({ code: 200, data: wrong }); await action.promise;
    assert.equal(h.downloads.length, 0); assert.equal(Boolean((await h.button()).props.disabled), false);
  }
  assert.equal(h.requests.length, 2); assert.ok(h.toasts.every(value => value.type === 'error'));
});
