import nodeTest from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import {ref} from 'vue';

const source = readFileSync('public/app-main.js', 'utf8');
const test = (name, run) => nodeTest(name, {timeout: 1500}, run);
const formFactory = source.slice(source.indexOf('const createRoomTypeConfigForm ='), source.indexOf('const roomTypeConfigForm ='));
const lifecycle = source.slice(source.indexOf('const resetRoomTypeConfigForm ='), source.indexOf('const syncRevenuePricingInputDate ='));
const flush = () => new Promise(resolve => setImmediate(resolve));
const draft = {id: null, name: '隔离价保房型', base_price: 328.5, min_price: 218.2, max_price: 588.8, room_count: 0, sort_order: 3, is_enabled: 0};
const row = (overrides = {}) => ({...draft, id: 501, hotel_id: 9001, base_price: '328.50', min_price: '218.20', max_price: '588.80', ...overrides});
const savedResponse = (saved = row()) => ({code: 200, data: {room_type: saved, evidence_status: 'operator_provided', auto_write_ota: false}});

function harness(initialDraft = draft) {
    const hotel = ref('9001'), calls = [], toasts = [], readState = ref({roomTypes: {status: 'not_loaded', error: ''}});
    const context = {
        URLSearchParams, Array, Number, String, Object, Math, Error, console: {error() {}},
        filterReportHotel: hotel, roomTypeConfigForm: ref({...initialDraft}), roomTypeConfigSaving: ref(false),
        roomTypeConfigSaveReadback: ref(null), roomTypeConfigList: ref([]), roomTypeConfigMeta: ref({}), revenueLoadState: readState,
        demandForecastForm: ref({room_type_id: 0}), competitorPriceForm: ref({room_type_id: 0}),
        captureAgentRevenueRequestContext: () => ({hotelId: hotel.value}),
        isAgentRevenueRequestCurrent: captured => captured.hotelId === hotel.value,
        setRevenueLoadState: (key, status, error = '') => { readState.value = {...readState.value, [key]: {status, error}}; },
        showToast: (...args) => toasts.push(args), firstEnabledRoomTypeId: () => 0,
        loadRevenueDashboard: async () => {}, loadRevenueAiOverview: async () => {},
        request: (url, options = {}) => new Promise((resolve, reject) => { calls.push({url, options, resolve, reject}); }),
    };
    vm.createContext(context);
    vm.runInContext(`let roomTypesRequestSequence=0; ${formFactory}; ${lifecycle}; this.save=saveRoomTypeConfig; this.verify=typeof verifyRoomTypeConfigSaveReadback==='function'?verifyRoomTypeConfigSaveReadback:null; this.read=loadRoomTypes; this.clear=resetRoomTypeConfigForm;`, context);
    return {hotel, calls, toasts, readState, form: context.roomTypeConfigForm, saving: context.roomTypeConfigSaving,
        receipt: context.roomTypeConfigSaveReadback, rows: context.roomTypeConfigList, save: context.save, verify: context.verify, read: context.read, clear: context.clear};
}
const acceptPost = async (h, response = savedResponse()) => { h.calls[0].resolve(response); await flush(); };
const resolveRead = async (h, rows = [row()]) => { const call = h.calls.findLast(item => !item.options.method); assert.ok(call, 'verification must perform an independent GET'); call.resolve({code: 200, data: {list: rows}}); await flush(); };

test('save success waits for independent exact readback before clearing the draft or claiming completion', async () => {
    const h = harness(), pending = h.save();
    assert.equal(h.saving.value, true); assert.equal(JSON.parse(h.calls[0].options.body).room_count, 0);
    await acceptPost(h);
    assert.equal(h.form.value.name, draft.name); assert.equal(h.form.value.id, 501);
    assert.doesNotMatch(h.toasts.map(item => item[0]).join(' '), /已保存并准确回读/);
    await resolveRead(h); await pending;
    assert.equal(h.receipt.value.status, 'verified'); assert.equal(h.form.value.name, ''); assert.equal(h.saving.value, false);
    assert.match(h.receipt.value.message, /已保存并准确回读/);
});

test('failed readback retains the saved id and draft; verification retry makes no second POST', async () => {
    const h = harness(), pending = h.save(); await acceptPost(h);
    h.calls.findLast(item => !item.options.method).reject(new Error('隔离回读失败')); await pending;
    assert.equal(h.receipt.value.status, 'failed'); assert.equal(h.form.value.id, 501); assert.equal(h.form.value.min_price, 218.2);
    assert.equal(h.saving.value, false); assert.ok(h.verify);
    const retry = h.verify(); await resolveRead(h); await retry;
    assert.equal(h.receipt.value.status, 'verified'); assert.equal(h.calls.filter(call => call.options.method === 'POST').length, 1);
});

for (const [name, rows] of [['missing record', []], ['different protection price', [row({min_price: '219.20'})]],
    ['foreign hotel', [row({hotel_id: 9002})]], ['different record', [row({id: 502})]]]) {
    test(`${name} cannot prove successful save readback`, async () => {
        const h = harness(), pending = h.save(); await acceptPost(h); await resolveRead(h, rows); await pending;
        assert.equal(h.receipt.value.status, 'failed'); assert.equal(h.form.value.name, draft.name);
        assert.equal(h.form.value.id, 501); assert.doesNotMatch(h.receipt.value.message, /已保存并准确回读/);
    });
}

for (const oldResult of ['success', 'failure']) {
    test(`an earlier room ${oldResult} cannot overwrite the configuration verified after saving`, async () => {
        const h = harness(), oldRead = h.read({silent: true}), pending = h.save();
        h.calls.find(call => call.options.method === 'POST').resolve(savedResponse()); await flush();
        await resolveRead(h); await pending;
        if (oldResult === 'success') h.calls[0].resolve({code: 200, data: {list: [row({min_price: '219.20'})]}});
        else h.calls[0].reject(new Error('隔离旧读取失败'));
        await oldRead;
        assert.equal(h.rows.value[0]?.min_price, '218.20'); assert.equal(h.readState.value.roomTypes.status, 'ready');
        assert.equal(h.receipt.value.status, 'verified');
    });
}

test('an unconfirmed create response preserves the draft and prevents an accidental second create', async () => {
    const h = harness(), pending = h.save(); await acceptPost(h, {code: 200, data: {}}); await pending;
    assert.equal(h.receipt.value.status, 'unconfirmed'); assert.equal(h.form.value.name, draft.name);
    await h.save(); assert.equal(h.calls.filter(call => call.options.method === 'POST').length, 1);
    assert.doesNotMatch(h.receipt.value.message, /已保存并准确回读/);
});

test('a POST transport failure remains unconfirmed and does not automatically resend the create', async () => {
    const h = harness(), pending = h.save(); h.calls[0].reject(new Error('隔离保存响应中断')); await pending;
    assert.equal(h.receipt.value.status, 'unconfirmed'); assert.equal(h.form.value.name, draft.name);
    await h.save(); assert.equal(h.calls.length, 1); assert.equal(h.saving.value, false);
});

test('an explicit rejected save keeps editable values and does not claim a server write', async () => {
    const h = harness(), pending = h.save(); await acceptPost(h, {code: 422, message: '隔离价格约束失败'}); await pending;
    assert.equal(h.form.value.name, draft.name); assert.equal(h.form.value.id, null); assert.equal(h.saving.value, false);
    assert.equal(h.toasts.at(-1)[0], '隔离价格约束失败');
    assert.notEqual(h.receipt.value?.status, 'verified');
});

test('a mismatched save response id cannot redirect an existing configuration update', async () => {
    const h = harness({...draft, id: 501}), pending = h.save(); await acceptPost(h, savedResponse(row({id: 502}))); await pending;
    assert.equal(h.receipt.value.status, 'unconfirmed'); assert.equal(h.form.value.id, 501);
    assert.equal(h.calls.length, 1);
});

test('an obsolete hotel save response cannot change the new hotel draft or result', async () => {
    const h = harness(), pending = h.save(); h.hotel.value = '9002'; h.form.value = {...draft, name: '酒店9002草稿'}; h.receipt.value = null;
    await acceptPost(h); await pending;
    assert.equal(h.form.value.name, '酒店9002草稿'); assert.equal(h.receipt.value, null); assert.equal(h.calls.length, 1);
});

test('a same-hotel list refresh failure does not destroy unsaved editing values', async () => {
    const h = harness({...draft, id: 501}), pending = h.read({silent: true});
    assert.equal(h.form.value.name, draft.name); h.calls[0].reject(new Error('隔离列表失败')); await pending;
    assert.equal(h.form.value.min_price, 218.2); assert.equal(h.form.value.id, 501);
});

test('confirmed PHP-style money normalization and legacy numeric text remain verifiable', async () => {
    const h = harness({...draft, base_price: '328.505', min_price: '218.205', max_price: '588.805'}), pending = h.save();
    const normalized = row({base_price: '328.51', min_price: '218.21', max_price: '588.81'});
    await acceptPost(h, savedResponse(normalized)); await resolveRead(h, [normalized]); await pending;
    assert.equal(h.receipt.value.status, 'verified');
});

test('duplicate invocations while saving cannot submit the same draft twice', async () => {
    const h = harness(), pending = h.save(); await h.save(); assert.equal(h.calls.length, 1);
    await acceptPost(h); await resolveRead(h); await pending;
});
