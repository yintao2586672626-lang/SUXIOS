import assert from 'node:assert/strict';
import test from 'node:test';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { createHash } from 'node:crypto';
import * as Vue from 'vue';
import { parse, compile } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';
import { readFrontendTestSource, retiredFrontendManifest } from './helpers/retired_frontend_source.mjs';

const candidate = process.argv.find(arg => arg.startsWith('--candidate-dir='))?.slice('--candidate-dir='.length);
const liveStaticSource = fs.readFileSync(candidate ? path.join(candidate, 'public/simulation-static.js') : 'public/simulation-static.js', 'utf8');
const archived = JSON.parse(fs.readFileSync(new URL('../fixtures/retired-transfer-source-20261002.json', import.meta.url), 'utf8'));
const sha256 = source => createHash('sha256').update(source).digest('hex');
assert.equal(archived.runtime, false, 'archived transfer oracle cannot become runtime code');
assert.equal(archived.source_commit, 'd3e53e176d86d74f7868297125772116f666b3e4');
assert.equal(sha256(archived.static_source), archived.static_source_sha256);
assert.equal(sha256(archived.loader_source), archived.loader_source_sha256);
const staticSource = archived.static_source;
const sandbox = vm.createContext({ window: {} });
vm.runInContext(staticSource, sandbox);
const helper = sandbox.window.SUXI_SIMULATION_STATIC;
const liveSandbox = vm.createContext({ window: {} });
vm.runInContext(liveStaticSource, liveSandbox);
const liveHelper = liveSandbox.window.SUXI_SIMULATION_STATIC;
const snapshot = (metrics = {}) => ({ hotel_id: 7, source_counts: { daily_reports: 1 }, current: { revenue: 0, room_nights: 2, adr: 0, occupancy_rate: 0, ...metrics }, source_verified: false });
const rows = metrics => helper.buildTransferSourceMetricRows({ snapshot: snapshot(metrics) }).filter(row => row.key.startsWith('whole_hotel'));

test('new observed report zero stays visible without upgrading source trust', () => {
  const result = rows({ revenue_observed: true, room_nights_observed: true, adr_observed: true, occupancy_rate_observed: true });
  assert.deepEqual(Array.from(result, row => row.value), ['0万元', '¥0', '0%']);
  assert.ok(result.every(row => row.truth.status === 'partial' && row.calculationStatus === 'calculated'));
});
test('explicit unobserved flags hide legacy numeric placeholders', () => {
  for (const value of [0, 100]) {
    const result = rows({ revenue: value, adr: value, occupancy_rate: value, revenue_observed: false, adr_observed: false, occupancy_rate_observed: false });
    assert.ok(result.every(row => row.value === '—' && row.calculationStatus === 'missing'));
  }
});
test('old snapshot zero has ambiguous provenance and is not an observed fact', () => {
  assert.ok(rows().every(row => row.value === '—' && row.calculationStatus === 'missing'));
});
test('old snapshot positive metrics retain their prior local-report compatibility', () => {
  const result = rows({ revenue: 10000, adr: 200, occupancy_rate: 50 });
  assert.deepEqual(Array.from(result, row => row.value), ['1万元', '¥200', '50%']);
  assert.ok(result.every(row => row.truth.status === 'partial'));
});
test('zero without records and malformed observed flags cannot establish observation', () => {
  const result = helper.buildTransferSourceMetricRows({ snapshot: { ...snapshot({ revenue_observed: true, occupancy_rate_observed: true }), source_counts: { daily_reports: 0 } } });
  assert.ok(result.filter(row => row.key.startsWith('whole_hotel')).every(row => row.calculationStatus === 'missing'));
  assert.ok(rows({ revenue_observed: 'true', adr_observed: 1, occupancy_rate_observed: [] }).every(row => row.calculationStatus === 'missing'));
});
test('source fill applies explicit zero and null for all returned fields but leaves absent manual fields', () => {
  const form = helper.createTransferPricingForm();
  helper.applyTransferSourceFields(form, { monthly_revenue: 0, occupancy_rate: 0, adr: 0, room_count: null, rating: null, order_count: null, ota_channel_revenue: null, location: '', hotel_name: undefined });
  assert.equal(form.monthly_revenue, 0); assert.equal(form.occupancy_rate, 0); assert.equal(form.adr, 0);
  assert.equal(form.room_count, null); assert.equal(form.rating, null); assert.equal(form.order_count, null); assert.equal(form.ota_channel_revenue, null);
  assert.equal(form.location, ''); assert.match(form.hotel_name, /示例数据/);
  assert.equal(form.monthly_rent, 18); assert.equal(form.remaining_lease_months, 60);
});
test('historical applyDefinedFields retains its default null-skip contract', () => {
  const form = { revenue: 12, rating: 4.5 };
  helper.applyDefinedFields(form, { revenue: null, rating: 0 });
  assert.deepEqual(form, { revenue: 12, rating: 0 });
});

const appSource = fs.readFileSync(candidate ? path.join(candidate, 'public/app-main.js') : 'public/app-main.js', 'utf8');
const loaderSource = archived.loader_source;
const contextTemplate = readFrontendTestSource('resources/frontend/templates/fragments/08-shared-transfer-context.html');
const pricingTemplate = readFrontendTestSource('resources/frontend/templates/fragments/09-page-asset-pricing.html');

test('historical zero/missing oracle stays archived while live runtime has no retired transfer helpers or loaders', () => {
  for (const key of Object.keys(liveHelper)) assert.doesNotMatch(key, /^(?:transfer|buildTransfer|createTransfer|resolveTransfer)/);
  assert.equal(liveHelper.applyTransferSourceFields, undefined);
  assert.doesNotMatch(appSource, /const loadTransferSource|const loadTransferRecords|request\(['"]\/transfer/);
  for (const id of ['shared-transfer-context', 'page-asset-pricing']) {
    assert.equal(retiredFrontendManifest.fragments.find(fragment => fragment.id === id)?.runtime, false);
  }
});
function findNode(node, accept) { if (accept(node)) return node; for (const child of node.children || []) { const found = findNode(child, accept); if (found) return found; } }
const button = findNode(parse(contextTemplate), node => node.type === 1 && node.tag === 'button' && node.props.some(prop => prop.type === 7 && prop.name === 'on' && prop.exp?.content === 'loadTransferSource'));
const fields = findNode(parse(pricingTemplate), node => node.type === 1 && node.props.some(prop => prop.type === 7 && prop.name === 'for' && prop.exp?.content === 'field in transferPricingFields'));
assert.ok(button && fields);
const render = new Function('Vue', compile('<div>' + button.loc.source + fields.loc.source + '</div>', { mode: 'function', prefixIdentifiers: true }).code)(Vue);
const flatten = value => Array.isArray(value) ? value.flatMap(flatten) : value?.__v_isVNode ? [value, ...flatten(value.children)] : [];

for (const [name, input, expected] of [['zero source', { monthly_revenue: 0, occupancy_rate: 0, adr: 0 }, '0'], ['missing source', { monthly_revenue: null, occupancy_rate: null, adr: null }, '']]) {
  test('archived original source button overwrites edited values for ' + name + ' and pricing SSR agrees', async () => {
    const state = { currentPage: Vue.ref('asset-pricing'), transferSourceLoading: Vue.ref(false), transferSelectedHotelId: Vue.ref('7'), transferSourceDate: Vue.ref('2026-09-14'), transferSourceSnapshot: Vue.ref(null), transferPricingForm: Vue.ref({ ...helper.createTransferPricingForm(), monthly_revenue: 12 }), transferTimingForm: Vue.ref(helper.createTransferTimingForm()), transferPricingFields: helper.transferPricingFields };
    const requests = [];
    // Explicit fixed page/session fixture; the real loader still executes all round-32 scope/draft gates.
    const auth = { epoch: 1 };
    const deps = { ...state, pageRequestGeneration: 0, captureAuthSession: () => ({ ...auth }), isAuthSessionCurrent: session => session.epoch === auth.epoch, ensureTransferHotelSelected: () => '7', ensureSimulationStaticReady: async () => {}, formatDate: () => '2026-09-14', applyDefinedFields: helper.applyDefinedFields, applyTransferSourceFields: helper.applyTransferSourceFields, showToast() {}, request: async url => { requests.push(url); return { code: 200, data: { hotel_id: 7, snapshot: snapshot(), pricing_input: input, timing_input: { current_revenue: input.monthly_revenue } } }; } };
    state.loadTransferSource = new Function(...Object.keys(deps), loaderSource + '; return loadTransferSource;')(...Object.values(deps));
    let vnode;
    const renderState = () => renderToString(Vue.createSSRApp({ setup: () => state, render(...args) { vnode = render(...args); return vnode; } }));
    await renderState();
    const action = flatten(vnode).find(node => node.type === 'button');
    assert.equal(action.props.disabled, false);
    await action.props.onClick();
    const html = await renderState();
    const tag = html.match(/<input[^>]*data-testid="field-transfer-pricing-monthly_revenue"[^>]*>/)?.[0];
    assert.ok(tag);
    assert.equal(tag.match(/value="([^"]*)"/)?.[1] || '', expected);
    assert.equal(state.transferTimingForm.value.current_revenue, input.monthly_revenue);
    assert.equal(state.transferPricingForm.value.monthly_rent, 18);
    assert.deepEqual(requests, ['/transfer/source?hotel_id=7&date=2026-09-14']);
    assert.equal(state.transferSourceLoading.value, false);
  });
}
