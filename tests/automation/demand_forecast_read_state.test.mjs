import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import * as Vue from 'vue';
import {compile, parse} from '@vue/compiler-dom';
import {renderToString} from '@vue/server-renderer';

const source = readFileSync('public/app-main.js', 'utf8');
const validatorStart = source.indexOf('const resolveDemandForecastListPayload =');
const validator = validatorStart < 0 ? '' : source.slice(validatorStart, source.indexOf('const loadDemandForecasts ='));
const invalidator = source.slice(source.indexOf('const invalidateDemandForecastRead ='), validatorStart);
const loader = source.slice(source.indexOf('const loadDemandForecasts ='), source.indexOf('const loadCompetitorAnalysis ='));
const template = readFileSync('resources/frontend/templates/fragments/27-page-agent-center.html', 'utf8');
function findTable(nodes) {
    for (const node of nodes) {
        if (node.type === 1 && node.tag === 'tbody' && node.loc.source.includes('v-for="row in demandForecasts"')) return node;
        const found = node.children && findTable(node.children);
        if (found) return found;
    }
}
const table = findTable(parse(template).children);
const render = new Function('Vue', compile(table.loc.source, {mode: 'function', prefixIdentifiers: true}).code)(Vue);
const row = {id: 501, hotel_id: 9001, forecast_date: '2026-10-01', predicted_occupancy: 0, predicted_demand: 0, confidence_score: 0.8};
const renderTable = (status, hotel = '9001', error = '', rows = []) => renderToString(Vue.createSSRApp({
    data: () => ({filterReportHotel: hotel, demandForecastReadState: {status, error}, demandForecasts: rows}),
    methods: {loadDemandForecasts() {}}, render,
}));
for (const [status, message] of [['not_loaded', /尚未读取/], ['loading', /正在读取/], ['failed', /预测读取失败/]]) {
    test(`forecast table distinguishes ${status} from a verified empty list`, async () => {
        const html = await renderTable(status, '9001', '隔离读取失败');
        assert.match(html, message);
        assert.doesNotMatch(html, /暂无需求预测/);
        if (status === 'failed') assert.match(html, /重新读取需求预测/);
    });
}
test('unselected hotel never claims no forecasts exist', async () => {
    const html = await renderTable('empty', '');
    assert.match(html, /请选择酒店/);
    assert.doesNotMatch(html, /暂无需求预测/);
});
test('verified empty forecast table names the selected date scope', async () => {
    assert.match(await renderTable('empty'), /所选日期范围内暂无需求预测/);
});
test('actual zero occupancy remains visible as a recorded forecast', async () => {
    const html = await renderTable('ready', '9001', '', [row]);
    assert.match(html, /0%/);
    assert.doesNotMatch(html, /暂无需求预测/);
});

function harness() {
    const ref = value => ({value});
    const hotel = ref('9001'), state = {}, gates = [];
    const context = {URLSearchParams, Number, String, Object, Array,
        console: {error() {}}, showToast() {},
        forecastFilter: ref({start_date: '2026-10-01', end_date: '2026-10-01'}),
        demandForecasts: ref([]), forecastAccuracy: ref({}), highDemandDates: ref([]),
        captureAgentRevenueRequestContext: extra => ({hotelId: hotel.value, ...extra}),
        isAgentRevenueRequestCurrent: captured => captured.hotelId === hotel.value,
        setRevenueLoadState: (key, status, error = '') => {state[key] = {status, error};},
        request: url => {
            assert.match(url, /hotel_id=9001/);
            let resolve, reject;
            const promise = new Promise((yes, no) => {resolve = yes; reject = no;});
            gates.push({resolve, reject}); return promise;
        },
    };
    vm.createContext(context);
    vm.runInContext(`let demandForecastsRequestSequence=0; ${invalidator}; ${validator}; ${loader}; this.load=loadDemandForecasts; this.invalidate=invalidateDemandForecastRead;`, context);
    return {hotel, state, gates, rows: context.demandForecasts, filter: context.forecastFilter, load: context.load, invalidate: context.invalidate};
}
test('changing the visible date scope clears previous verified rows and invalidates pending reads', async () => {
    const h = harness(), initial = h.load({silent: true});
    h.gates[0].resolve({code: 200, data: {forecasts: [row]}}); await initial;
    const pending = h.load({silent: true}); h.invalidate();
    assert.equal(h.state.forecasts.status, 'not_loaded');
    assert.equal(h.rows.value.length, 0);
    h.gates[1].resolve({code: 200, data: {forecasts: [row]}}); await pending;
    assert.equal(h.state.forecasts.status, 'not_loaded');
    assert.equal(h.rows.value.length, 0);
    assert.match(template, /v-model="forecastFilter.start_date" @change="invalidateDemandForecastRead"/);
    assert.match(template, /v-model="forecastFilter.end_date" @change="invalidateDemandForecastRead"/);
});
for (const [name, payload] of [['missing forecasts array', {}], ['invalid forecasts object', {forecasts: {}}],
    ['foreign hotel', {forecasts: [{...row, hotel_id: 9002}]}],
    ['foreign date', {forecasts: [{...row, forecast_date: '2026-10-02'}]}]]) {
    test(`forecast loader fails rather than reports empty for ${name}`, async () => {
        const h = harness(), pending = h.load({silent: true});
        h.gates[0].resolve({code: 200, data: payload}); await pending;
        assert.equal(h.state.forecasts.status, 'failed');
        assert.equal(h.rows.value.length, 0);
    });
}
test('valid empty and legacy numeric hotel IDs remain readable without zero filling', async () => {
    const h = harness();
    let pending = h.load({silent: true}); h.gates[0].resolve({code: 200, data: {forecasts: []}}); await pending;
    assert.equal(h.state.forecasts.status, 'empty');
    pending = h.load({silent: true}); h.gates[1].resolve({code: 200, data: {forecasts: [{...row, hotel_id: '9001'}]}}); await pending;
    assert.equal(h.state.forecasts.status, 'ready');
    assert.equal(h.rows.value[0].predicted_occupancy, 0);
});
test('late older forecast failure cannot erase a newer verified list', async () => {
    const h = harness(), old = h.load({silent: true}), latest = h.load({silent: true});
    h.gates[1].resolve({code: 200, data: {forecasts: [row]}}); await latest;
    h.gates[0].reject(new Error('旧读取失败')); await old;
    assert.equal(h.state.forecasts.status, 'ready');
    assert.equal(h.rows.value[0].id, 501);
});
test('hotel or date changes invalidate an older forecast response', async () => {
    const h = harness(), pending = h.load({silent: true});
    h.hotel.value = '9002';
    h.gates[0].resolve({code: 200, data: {forecasts: [row]}}); await pending;
    assert.equal(h.rows.value.length, 0);
    const second = harness(), dated = second.load({silent: true});
    second.filter.value.end_date = '2026-10-02';
    second.gates[0].resolve({code: 200, data: {forecasts: [row]}}); await dated;
    assert.equal(second.rows.value.length, 0);
});
