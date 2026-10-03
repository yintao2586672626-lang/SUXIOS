import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import * as Vue from 'vue';
import {compile, parse} from '@vue/compiler-dom';
import {renderToString} from '@vue/server-renderer';

const source = readFileSync('public/app-main.js', 'utf8');
const loader = source.slice(source.indexOf('const applyRoomTypeReadback ='), source.indexOf('const saveRoomTypeConfig ='));
const template = readFileSync('resources/frontend/templates/fragments/27-page-agent-center.html', 'utf8');
const findPanel = nodes => {
    for (const node of nodes) {
        if (node.type === 1 && node.props.some(prop => prop.type === 6 && prop.name === 'data-testid'
            && prop.value?.content === 'agent-room-type-pricing-guard')) return node;
        const nested = node.children && findPanel(node.children);
        if (nested) return nested;
    }
};
const panel = findPanel(parse(template).children);
assert.ok(panel, 'the existing room configuration panel must be available');
const render = new Function('Vue', compile(panel.loc.source, {mode: 'function', prefixIdentifiers: true}).code)(Vue);
const row = {id: 501, hotel_id: 9001, name: '隔离验收房型', base_price: '328.50', min_price: '218.20', max_price: '588.80', room_count: 0, is_enabled: 1};
const renderPanel = (status, hotel = '9001', list = [], error = '', receipt = null) => renderToString(Vue.createSSRApp({
    data: () => ({filterReportHotel: hotel, revenueLoadState: {roomTypes: {status, error}}, roomTypeConfigReadState: {status, error}, roomTypeConfigList: list,
        roomTypeConfigSaving: false, roomTypeConfigSaveReadback: receipt, roomTypeConfigForm: {id: receipt?.recordId || null, name: '', is_enabled: 1}}),
    methods: {loadRoomTypes() {}, saveRoomTypeConfig() {}, editRoomTypeConfig() {}, resetRoomTypeConfigForm() {}, verifyRoomTypeConfigSaveReadback() {}},
    render,
}));

test('unselected hotel never claims an empty configuration', async () => {
    const html = await renderPanel('empty', '');
    assert.match(html, /请选择酒店后读取房型配置/);
    assert.doesNotMatch(html, /暂无房型配置/);
});
test('unread configuration is distinct from a verified empty list', async () => {
    const html = await renderPanel('not_loaded');
    assert.match(html, /尚未读取.*房型配置/);
    assert.doesNotMatch(html, /暂无房型配置/);
});
test('pending configuration describes reading and disables the refresh button', async () => {
    const html = await renderPanel('loading');
    assert.match(html, /正在读取.*房型配置/);
    assert.match(html, /<button[^>]*disabled[^>]*>[\s\S]*?刷新/);
    assert.doesNotMatch(html, /暂无房型配置/);
});
test('configuration failure exposes retry and uncertainty instead of an empty success', async () => {
    const html = await renderPanel('failed', '9001', [], '隔离读取失败');
    assert.match(html, /房型配置读取失败：隔离读取失败/);
    assert.match(html, /是否有配置尚未核验/);
    assert.match(html, /请点击刷新重试/);
    assert.doesNotMatch(html, /暂无房型配置/);
});
test('a successful empty list retains its configuration prerequisite explanation', async () => {
    const html = await renderPanel('empty');
    assert.match(html, /当前酒店暂无房型配置/);
    assert.match(html, /调价建议生成会保持受阻/);
    assert.doesNotMatch(html, /正在读取|尚未读取|是否有配置尚未核验/);
});
test('existing manual configuration displays exact values, zero room count and editing', async () => {
    const html = await renderPanel('ready', '9001', [row]);
    for (const value of ['隔离验收房型', '¥328.50', '¥218.20', '¥588.80', '启用', '编辑']) assert.ok(html.includes(value));
    assert.match(html, /<td[^>]*>0<\/td>/);
    assert.match(html, /人工配置项/);
    assert.doesNotMatch(html, /暂无房型配置|尚未读取/);
});

test('save readback failure displays its warning and a read-only verification action', async () => {
    const html = await renderPanel('ready', '9001', [row], '', {status: 'failed', recordId: 501, message: '保存结果尚未准确回读，编辑值已保留'});
    assert.match(html, /保存结果尚未准确回读，编辑值已保留/);
    assert.match(html, /重新核对保存结果/); assert.match(html, /更新/);
});
test('unconfirmed creation disables saving while retaining verification recovery', async () => {
    const html = await renderPanel('empty', '9001', [], '', {status: 'unconfirmed', recordId: 0, message: '保存结果未确认'});
    assert.match(html, /<button[^>]*type="submit"[^>]*disabled/); assert.match(html, /重新核对保存结果/);
});
test('active readback protects the form values and clear/edit actions', async () => {
    const html = await renderPanel('loading', '9001', [row], '', {status: 'reading', recordId: 501, message: '正在核对保存结果'});
    assert.equal((html.match(/<input[^>]*disabled/g) || []).length, 5);
    assert.match(html, /<select[^>]*disabled/); assert.match(html, /<button[^>]*disabled[^>]*title="清空"/);
});

function harness(hotelId = '9001') {
    const hotel = Vue.ref(hotelId), states = Vue.ref({roomTypes: {status: 'not_loaded', error: ''}}), reads = [];
    const context = {URLSearchParams, Array, Number, Error, console: {error() {}},
        filterReportHotel: hotel, revenueLoadState: states,
        roomTypeConfigList: Vue.ref([]), roomTypeConfigMeta: Vue.ref({}), roomTypeConfigForm: Vue.ref({}),
        roomTypeConfigSaving: Vue.ref(false), roomTypeConfigSaveReadback: Vue.ref(null),
        demandForecastForm: Vue.ref({room_type_id: 0}), competitorPriceForm: Vue.ref({room_type_id: 0}),
        createRoomTypeConfigForm: () => ({id: 0, name: '', is_enabled: 1}), showToast() {},
        captureAgentRevenueRequestContext: () => ({hotelId: hotel.value}),
        isAgentRevenueRequestCurrent: captured => captured.hotelId === hotel.value,
        setRevenueLoadState: (key, status, error = '') => { states.value = {...states.value, [key]: {status, error}}; },
        request: url => new Promise((resolve, reject) => {
            assert.equal(url, `/agent/room-types?hotel_id=${hotel.value}`);
            reads.push({resolve, reject});
        }),
    };
    context.firstEnabledRoomTypeId = () => Number(context.roomTypeConfigList.value.find(item => Number(item.is_enabled) === 1)?.id || 0);
    vm.createContext(context); vm.runInContext(`let roomTypesRequestSequence=0; const resetRoomTypeConfigForm=()=>{roomTypeConfigForm.value=createRoomTypeConfigForm();roomTypeConfigSaveReadback.value=null;}; ${loader}; this.load = loadRoomTypes;`, context);
    return {hotel, states, reads, load: context.load, list: context.roomTypeConfigList, meta: context.roomTypeConfigMeta,
        form: context.roomTypeConfigForm, forecast: context.demandForecastForm, competitor: context.competitorPriceForm};
}

test('without a hotel the loader stays unread and makes no request', async () => {
    const h = harness(''); await h.load({silent: true});
    assert.equal(h.reads.length, 0); assert.equal(h.states.value.roomTypes.status, 'not_loaded');
});
test('a direct read accepts a legitimate empty list from the existing response shape', async () => {
    const h = harness(), pending = h.load({silent: true});
    assert.equal(h.states.value.roomTypes.status, 'loading');
    h.reads[0].resolve({code: 200, data: {list: []}}); await pending;
    assert.equal(h.states.value.roomTypes.status, 'empty'); assert.equal(h.list.value.length, 0);
});
test('a direct read preserves manual values, provenance and existing room selections', async () => {
    const h = harness(); h.competitor.value.room_type_id = 602;
    const pending = h.load({silent: true});
    h.reads[0].resolve({code: 200, data: {list: [row], input_scope: 'manual_pricing_configuration', evidence_status: 'operator_provided', auto_write_ota: false}});
    await pending;
    assert.deepEqual(h.list.value[0], row); assert.equal(h.states.value.roomTypes.status, 'ready');
    assert.equal(h.meta.value.evidence_status, 'operator_provided'); assert.equal(h.meta.value.auto_write_ota, false);
    assert.equal(h.forecast.value.room_type_id, 501); assert.equal(h.competitor.value.room_type_id, 602);
});
test('a failed direct read clears old values and retry can verify an empty current result', async () => {
    const h = harness(); h.list.value = [row]; h.meta.value = {evidence_status: 'operator_provided'};
    const failed = h.load({silent: true}); h.reads[0].reject(new Error('隔离读取失败')); await failed;
    assert.equal(h.states.value.roomTypes.status, 'failed'); assert.equal(h.list.value.length, 0);
    assert.equal(Object.keys(h.meta.value).length, 0);
    const retry = h.load({silent: true}); assert.equal(h.states.value.roomTypes.status, 'loading');
    h.reads[1].resolve({code: 200, data: {list: []}}); await retry;
    assert.equal(h.states.value.roomTypes.status, 'empty'); assert.equal(h.states.value.roomTypes.error, '');
});
for (const [name, data] of [['missing list', {}], ['null list', {list: null}], ['non-array list', {list: {length: 0}}]]) {
    test(`a 200 response with ${name} remains failed instead of verified empty`, async () => {
        const h = harness(), pending = h.load({silent: true});
        h.reads[0].resolve({code: 200, data}); await pending;
        assert.equal(h.states.value.roomTypes.status, 'failed');
        assert.match(h.states.value.roomTypes.error, /房型配置列表.*未核验/);
    });
}
test('an obsolete hotel response cannot overwrite the current hotel configuration state', async () => {
    const h = harness(), pending = h.load({silent: true}); h.hotel.value = '9002';
    h.states.value = {roomTypes: {status: 'failed', error: '酒店9002读取失败'}};
    h.reads[0].resolve({code: 200, data: {list: [row]}}); await pending;
    assert.equal(h.list.value.length, 0); assert.equal(h.states.value.roomTypes.error, '酒店9002读取失败');
});
