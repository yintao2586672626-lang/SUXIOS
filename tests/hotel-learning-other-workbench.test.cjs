const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const Vue = require('vue');

// Synthetic responses exercise the source template and real Vue event handlers;
// they do not establish authenticated API, database or production evidence.
const sourcePath = path.resolve(__dirname, '../public/components/system/hotel-learning-workbench.js');
const windowFixture = { SUXI_SYSTEM_COMPONENTS: {} };
let nonce = 0;
vm.runInNewContext(fs.readFileSync(sourcePath, 'utf8'), { window: windowFixture, Vue, URLSearchParams, crypto: { randomUUID: () => `synthetic-other-${++nonce}` }, console });
const component = windowFixture.SUXI_SYSTEM_COMPONENTS.HotelLearningWorkbench;
component.render = Vue.compile(component.template);
delete component.template;
const plain = value => JSON.parse(JSON.stringify(value));
const ok = data => ({ code: 200, data });
const kinds = { profile: 'jhira_profile', geo_observation: 'jhira_geo', ota_scene: 'jhira_ota_scene', market_sample: 'jhira_market', operating_review: 'jhira_review' };
const captions = { profile: '基础资料', geo_observation: 'AI问答观测', ota_scene: 'OTA搜索与价格', market_sample: '商圈样本评分', operating_review: '计划与实际复盘' };
const fixtures = {
    profile: { fields: [{ key: 'room_count', value: '80', unit: '间', source_ref: '合成房量台账', as_of: '2026-10-01', quality: 'operator_attested' }] },
    geo_observation: { question: '合成酒店适合出差吗', model: '合成模型', model_version: '合成v1', region: '合成地区', network: '合成网络', observed_at: '2026-10-01T09:30', source_ref: '合成回答记录', response_summary: '合成摘要', citations: [{ url: 'https://example.org/synthetic-hotel', fact_consistency: 'unverified' }] },
    ota_scene: { scene: { keyword: '合成商圈', location: '合成定位', device: '手机', login_state: '未登录', sort: '推荐', filters: '无', platform_store_id: 'synthetic-store', source_ref: '合成截图', observed_at: '2026-10-01T09:30:00', check_in: '2026-10-02', check_out: '2026-10-03', page_capacity: 20 }, visibility: 'observed', rank_min: 1, rank_max: 3, observed_through_rank: 20, conversion_rate: 3.5, rate_unit: 'percentage_point', price: 200, price_terms: { room_type: '大床', cancellation: '可取消', breakfast: '双早', guest_count: '2', membership: '无', tax_basis: '含税', payment: '到店付' } },
    market_sample: { sample_ref: '合成样本', model_version: '合成算法v1', comparison_key: 'synthetic-scene', comparison_attested: true, rate_unit: 'percentage_point', weights: { traffic: 0.4, conversion: 0.3, revenue: 0.3 }, hotels: [{ platform_store_id: 'synthetic-a', name: '合成甲', traffic: 100, conversion: 5, revenue: 200, comparison_key: 'synthetic-scene', rate_unit: 'percentage_point' }, { platform_store_id: 'synthetic-b', name: '合成乙', traffic: 200, conversion: 6, revenue: 300, comparison_key: 'synthetic-scene', rate_unit: 'percentage_point' }] },
    operating_review: { period_start: '2026-10-01', period_end: '2026-10-31', plan: { period_start: '2026-10-01', period_end: '2026-10-31', source_ref: '合成计划', basis: '全酒店', available_room_nights: 100, sold_room_nights: 80, revenue: 8000, operating_cost: 3000, debt_service: 100, project_net_cash: 4900, investor_received_cash: 0 }, actual: { period_start: '2026-10-01', period_end: '2026-10-31', source_ref: '合成实际', basis: '全酒店', available_room_nights: 100, sold_room_nights: 70, revenue: 7000, operating_cost: 3000, debt_service: 100, project_net_cash: 3900, investor_received_cash: 1000 } },
};
const settle = async () => { for (let i = 0; i < 5; i++) { await Promise.resolve(); await Vue.nextTick(); } };
const text = node => node.text + node.children.map(text).join('');
const children = node => { const out = []; const visit = item => { out.push(item); item.children.forEach(visit); }; visit(node); return out; };
function overview(scope) { return { scope: { ...scope, kind: kinds[scope.mode] }, can_execute: true, latest: null, history: [{ snapshot_id: 32, created_at: '2026-10-01 09:30:00' }] }; }
function snapshot(scope, inputs, result, id = 32) { return { snapshot_id: id, scope: { tenant_id: 7, ...scope, kind: kinds[scope.mode] }, inputs: plain(inputs), result: { mode: scope.mode, status: 'recorded', source_quality: 'manual_reference', ...result }, readback_verified: true, content_digest: 'a'.repeat(64) }; }
function mount(request) {
    const oldDocument = globalThis.Document; const oldShadow = globalThis.ShadowRoot;
    if (!oldDocument) globalThis.Document = class SyntheticDocument {};
    if (!oldShadow) globalThis.ShadowRoot = class SyntheticShadowRoot {};
    const node = (type, content = '') => ({ type, tagName: type.toUpperCase(), text: content, children: [], parent: null, props: {}, listeners: {}, addEventListener(name, listener) { this.listeners[name] = listener; }, getRootNode() { let root = this; while (root.parent) root = root.parent; return root; }, get options() { return this.children.filter(child => child.type === 'option'); } });
    const detach = child => { if (child.parent) child.parent.children.splice(child.parent.children.indexOf(child), 1); };
    const renderer = Vue.createRenderer({ createElement: type => node(type), createText: value => node('#text', value), createComment: value => node('#comment', value),
        setText: (target, value) => { target.text = value; }, setElementText: (target, value) => { target.text = value; target.children = []; },
        parentNode: target => target.parent, nextSibling: target => target.parent?.children[target.parent.children.indexOf(target) + 1] || null,
        patchProp: (target, key, old, value) => { target.props[key] = value; if (key === 'value') { target.value = value; target._value = value; } },
        insert: (child, parent, anchor) => { detach(child); const index = anchor ? parent.children.indexOf(anchor) : -1; if (index < 0) parent.children.push(child); else parent.children.splice(index, 0, child); child.parent = parent; }, remove: detach,
    });
    const root = node('root'); const requests = [];
    const app = renderer.createApp(component, { module: 'all', selectedHotelId: 80, canExecute: true, hotels: [{ id: 80, name: '合成酒店' }, { id: 81, name: '另一个合成酒店' }], request: async (url, options = {}) => {
        const scope = Object.fromEntries(new URL('http://fixture.test' + url).searchParams);
        const body = options.body ? JSON.parse(options.body) : null;
        requests.push({ url, body });
        return request ? request(url, options, scope, body) : ok(overview(scope));
    } });
    const state = app.mount(root); const all = () => children(root);
    const button = caption => { const found = all().find(item => item.type === 'button' && text(item) === caption); assert.ok(found, caption); return found; };
    const click = async caption => { const found = button(caption); assert.notEqual(found.props.disabled, true, caption); await found.props.onClick?.({}); await settle(); };
    const input = label => {
        const found = all().find(item => item.type === 'label' && item.children.some(child => child.type === 'span' && text(child) === label));
        assert.ok(found, label); const control = found.children.find(child => ['input', 'textarea', 'select'].includes(child.type)); assert.ok(control, label); return control;
    };
    const enter = async (label, value) => { const control = input(label); control.value = value; control.props.onInput({ target: control }); await settle(); };
    const choose = async (control, value) => {
        control.value = value; control.options.forEach(item => { item.selected = item._value === value; });
        control.listeners.change?.({ target: control }); control.props.onChange?.({ target: control }); await settle();
    };
    const check = async label => { const control = input(label); control.checked = true; control.listeners.change?.({ target: control }); control.props.onChange?.({ target: control }); await settle(); };
    const close = () => { app.unmount(); if (!oldDocument) delete globalThis.Document; if (!oldShadow) delete globalThis.ShadowRoot; };
    return { state, all, requests, click, input, enter, choose, check, close, root };
}
async function open(mode, request) { const mounted = mount(request); await settle(); mounted.state.periodMonth = '2026-10'; await settle(); if (mode !== 'profile') await mounted.click(captions[mode]); return mounted; }
const resultRows = mounted => plain(mounted.state.resultRows);

test('real Vue: existing room_count reads as a business name while key, exact content, sources and missing state stay intact', async () => {
    const mounted = await open('profile', async (url, options, scope, body) => body ? ok({ scope: body, inputs: body.inputs, result: { mode: body.mode, status: 'partial', field_count: 1, fields: [{ ...body.inputs.fields[0], status: 'unverified' }], missing_items: ['room_count'] } }) : ok(overview(scope)));
    try {
        await mounted.click('添加基础资料字段');
        const keyLabel = mounted.all().find(item => item.type === 'label' && item.children.some(child => child.type === 'span' && /字段标识|资料名称/.test(text(child))));
        const key = keyLabel.children.find(child => child.type === 'input'); key.props.onInput({ target: { value: 'room_count' } }); await settle();
        await mounted.enter('字段值', 'unknown'); await mounted.enter('来源', 'partial'); await mounted.enter('资料日期', '2026-10-01');
        await mounted.state.calculate(false); await settle();
        assert.equal(mounted.requests.at(-1).body.inputs.fields[0].key, 'room_count');
        assert.equal(resultRows(mounted)[1][0], '房间数量');
        assert.equal(resultRows(mounted)[1][1], 'unknown');
        assert.equal(resultRows(mounted)[2][0], '来源与核对 · 房间数量');
        assert.equal(resultRows(mounted)[2][1], 'partial · 2026-10-01 · 未核对');
        assert.equal(resultRows(mounted).at(-1)[1], '房间数量');
    } finally { mounted.close(); }
});

test('real Vue: market missing metrics identify the correct hotel even when platform IDs contain dots', async () => {
    const mounted = await open('market_sample', async (url, options, scope, body) => body ? ok({ scope: body, inputs: body.inputs, result: { mode: body.mode, status: 'partial', items: body.inputs.hotels.map(row => ({ ...row, reference_score: null })), missing_items: ['synthetic.b.traffic'] } }) : ok(overview(scope)));
    try {
        const input = plain(fixtures.market_sample); input.hotels[1].platform_store_id = 'synthetic.b'; mounted.state.hydrate(input); await settle();
        const traffic = mounted.all().filter(item => item.type === 'label' && item.children.some(child => child.type === 'span' && text(child) === '同期间流量（次）'))[1].children.find(child => child.type === 'input');
        traffic.props.onInput({ target: { value: '' } }); await settle(); assert.equal(mounted.state.form.comparison_attested, false);
        await mounted.check('我已人工核对每家样本使用同一搜索及价格口径引用键');
        await mounted.state.calculate(false); await settle(); assert.equal(mounted.requests.at(-1).body.inputs.hotels[1].traffic, null);
        assert.equal(resultRows(mounted).at(-1)[1], '合成乙 · 平台酒店 synthetic.b · 流量');
        assert.ok(text(mounted.root).includes('合成乙 · 平台酒店 synthetic.b · 流量'));
    } finally { mounted.close(); }
});

test('real Vue: market missing conversion has no percent suffix while real zero and one retain their unit', async () => {
    const mounted = await open('market_sample', async (url, options, scope, body) => body ? ok({ scope: body, inputs: body.inputs, result: { mode: body.mode, status: body.inputs.hotels[1].conversion === null ? 'partial' : 'calculated_reference', items: body.inputs.hotels.map(row => ({ ...row, reference_score: null })), missing_items: body.inputs.hotels[1].conversion === null ? ['synthetic-b.conversion'] : [] } }) : ok(overview(scope)));
    try {
        mounted.state.hydrate(fixtures.market_sample); await settle();
        for (const value of [null, 0, 1]) {
            const conversion = mounted.all().filter(item => item.type === 'label' && item.children.some(child => child.type === 'span' && text(child) === '同期间转化率（依全局单位）'))[1].children.find(child => child.type === 'input');
            conversion.props.onInput({ target: { value: value === null ? '' : String(value) } }); await settle();
            await mounted.check('我已人工核对每家样本使用同一搜索及价格口径引用键'); await mounted.state.calculate(false); await settle();
            assert.equal(mounted.requests.at(-1).body.inputs.hotels[1].conversion, value);
            const row = resultRows(mounted).find(([label]) => label === '合成乙 · 平台酒店 synthetic-b')[1];
            assert.ok(row.includes('转化率 ' + (value === null ? '未取得' : value + '%') + '；'), row);
            assert.equal(row.includes('未取得%'), false); assert.ok(text(mounted.root).includes(row));
        }
    } finally { mounted.close(); }
});

for (const scenario of [
    { id: 'missing metric coverage', reasons: { conversion: 'incomplete_metric_coverage' }, expected: '转化率：样本该指标资料不完整', field: '同期间转化率（依全局单位）', value: '' },
    { id: 'no metric variation', reasons: { traffic: 'no_metric_variation' }, expected: '流量：样本该指标全部相同', field: '同期间流量（次）', value: '100' },
    { id: 'insufficient sample size', reasons: { sample: 'insufficient_sample_size' }, expected: '样本：不足两家酒店', remove: true },
    { id: 'unknown reference reasons', reasons: { conversion: 'synthetic_future_reason', synthetic_future_metric: 'incomplete_metric_coverage' }, expected: '未解释参考信息；未解释参考信息' },
]) test(`real Vue: market score limitations explain ${scenario.id} without treating an observed metric as missing`, async () => {
    const mounted = await open('market_sample', async (url, options, scope, body) => body ? ok({ scope: body, inputs: body.inputs, result: { mode: body.mode, status: 'partial', items: body.inputs.hotels.map(row => ({ ...row, reference_score: null })), missing_items: body.inputs.hotels[1]?.conversion === null ? ['synthetic-b.conversion'] : [], score_unavailable_reasons: scenario.reasons } }) : ok(overview(scope)));
    try {
        mounted.state.hydrate(fixtures.market_sample); await settle();
        if (scenario.remove) await mounted.click('移除第 2 项');
        else if (scenario.field) {
            const control = mounted.all().filter(item => item.type === 'label' && item.children.some(child => child.type === 'span' && text(child) === scenario.field))[1].children.find(child => child.type === 'input');
            control.props.onInput({ target: { value: scenario.value } }); await settle();
        } else await mounted.enter('样本来源', '合成未知参考资料');
        await mounted.check('我已人工核对每家样本使用同一搜索及价格口径引用键'); await mounted.state.calculate(false); await settle();
        const rows = resultRows(mounted); assert.equal(rows.find(([label]) => label === '评分基准限制')?.[1], scenario.expected); assert.ok(text(mounted.root).includes(scenario.expected));
        const gaps = rows.find(([label]) => label === '待补或待核对资料');
        if (scenario.field?.includes('转化率')) assert.equal(gaps?.[1], '合成乙 · 平台酒店 synthetic-b · 转化率');
        else assert.equal(gaps, undefined);
        assert.ok(rows.find(([label]) => label === '合成甲 · 平台酒店 synthetic-a')[1].includes('转化率 5%'));
        if (scenario.id === 'unknown reference reasons') assert.ok(mounted.state.csv().includes('synthetic_future_reason'));
    } finally { mounted.close(); }
});

test('real Vue: legacy review metric gaps never falsely label actual missing data as plan data', async () => {
    const mounted = await open('operating_review', async (url, options, scope, body) => body ? ok({ scope: body, inputs: body.inputs, result: { mode: body.mode, status: 'partial', rows: [{ metric: 'operating_cost', plan: 3000, actual: null, difference: null }], missing_items: ['operating_cost'] } }) : ok(overview(scope)));
    try {
        mounted.state.hydrate(fixtures.operating_review); await settle(); await mounted.enter('实际 · 经营成本（元）', '');
        await mounted.state.calculate(false); await settle();
        assert.equal(mounted.requests.at(-1).body.inputs.actual.operating_cost, null);
        assert.equal(resultRows(mounted).at(-1)[1], '经营成本（元）');
        assert.equal(resultRows(mounted).find(([label]) => label === '经营成本（元）')[1], '计划 3,000；实际 未取得；实际减计划 未取得');
    } finally { mounted.close(); }
});

test('real Vue: review exposes comparison readiness and exact source/metric sides without changing scope alignment', async () => {
    const mounted = await open('operating_review', async (url, options, scope, body) => body ? ok({ scope: body, inputs: body.inputs, result: { mode: body.mode, status: 'partial', scope_aligned: true, comparison_ready: false, rows: [{ metric: 'operating_cost', plan: 3000, actual: null, difference: null }], missing_items: ['actual.source_ref', 'operating_cost', 'actual.operating_cost', 'sold_room_nights', 'plan.sold_room_nights'] } }) : ok(overview(scope)));
    try {
        mounted.state.hydrate(fixtures.operating_review); await settle(); await mounted.enter('实际来源', ''); await mounted.enter('实际 · 经营成本（元）', '');
        await mounted.state.calculate(false); await settle(); const rows = resultRows(mounted);
        assert.equal(rows.find(([label]) => label === '计划与实际口径一致')[1], true);
        assert.equal(rows.find(([label]) => label === '是否具备可比条件')?.[1], false);
        assert.equal(rows.at(-1)[1], '实际 · 来源；实际 · 经营成本（元）；计划 · 已售间夜（间夜）');
    } finally { mounted.close(); }
});

for (const mode of Object.keys(fixtures)) test(`real Vue ${mode}: restore, edit, interrupted exact readback and retry preserve inputs and scope`, async () => {
    const posts = []; let saved; let reads = 0; let restoreFails = false;
    const mounted = await open(mode, async (url, options, scope, body) => {
        if (body) { posts.push(body); saved = snapshot(body, body.inputs, { true_zero: 0, missing_value: null }, 45); return ok(saved); }
        if (url.includes('/snapshots/45')) { if (++reads === 1) return { code: 503, message: '合成精确回读中断' }; return ok(saved); }
        if (url.includes('/snapshots/32')) { if (restoreFails) return { code: 503, message: '合成旧版读取中断' }; return ok(snapshot(scope, fixtures[mode], {})); }
        return ok(overview(scope));
    });
    try {
        await mounted.click('版本 #32 · 2026-10-01 09:30:00'); assert.equal(mounted.state.saved.snapshot_id, 32);
        const field = mode === 'profile' ? '来源' : mode === 'geo_observation' ? '回答截图或保存来源' : mode === 'ota_scene' ? '截图或观测来源' : mode === 'market_sample' ? '样本来源' : '实际来源';
        await mounted.enter(field, '合成编辑来源'); assert.equal(mounted.state.resultCurrent, false);
        if (mode === 'market_sample') await mounted.check('我已人工核对每家样本使用同一搜索及价格口径引用键');
        const edited = plain(mounted.state.inputs()); restoreFails = true;
        await mounted.click('版本 #32 · 2026-10-01 09:30:00'); assert.equal(mounted.state.restoreRequested, 32);
        await mounted.click('确认载入该版本'); assert.match(mounted.state.error, /旧版读取中断/); assert.deepEqual(plain(mounted.state.inputs()), edited);
        await mounted.click('保存新版本并核对'); assert.match(mounted.state.error, /精确回读中断/); assert.equal(mounted.state.saved, null); assert.equal(mounted.state.resultCurrent, false);
        await mounted.click('保存新版本并核对'); assert.equal(mounted.state.error, ''); assert.equal(mounted.state.saved.snapshot_id, 45); assert.equal(mounted.state.resultCurrent, true);
        assert.equal(posts[0].idempotency_key, posts[1].idempotency_key); assert.deepEqual(posts[0].inputs, posts[1].inputs); assert.deepEqual(posts[1].inputs, edited);
        assert.equal(posts[1].hotel_id, 80); assert.equal(posts[1].period_month, '2026-10'); assert.equal(posts[1].platform, ['ota_scene', 'market_sample'].includes(mode) ? 'ctrip' : 'whole_hotel');
        assert.ok(mounted.state.csv().includes('"result.true_zero","0"')); assert.ok(mounted.state.csv().includes('"result.missing_value","未取得"'));
    } finally { mounted.close(); }
});

for (const mode of Object.keys(fixtures)) test(`real Vue ${mode}: failed preview retries keep dates, sources, platform and literal values`, async () => {
    let fails = true; const posts = [];
    const mounted = await open(mode, async (url, options, scope, body) => {
        if (!body) return ok(overview(scope)); posts.push(body);
        return fails ? { code: 422, message: '合成来源核对失败' } : ok({ scope: body, inputs: body.inputs, result: { mode, status: 'partial', missing_items: [] } });
    });
    try {
        mounted.state.hydrate(fixtures[mode]); await settle(); const before = plain(mounted.state.inputs());
        const form = mounted.all().find(item => item.type === 'form'); await form.props.onSubmit({ preventDefault() {} }); await settle();
        assert.match(mounted.state.error, /来源核对失败/); assert.equal(mounted.state.resultCurrent, false); assert.deepEqual(plain(mounted.state.inputs()), before);
        fails = false; await form.props.onSubmit({ preventDefault() {} }); await settle();
        assert.equal(mounted.state.error, ''); assert.equal(mounted.state.resultCurrent, true); assert.deepEqual(posts[0].inputs, posts[1].inputs); assert.deepEqual(posts[1].inputs, before);
    } finally { mounted.close(); }
});

test('real Vue: custom profile names and status-like content stay literal after add, save and exact readback', async () => {
    let saved;
    const mounted = await open('profile', async (url, options, scope, body) => {
        if (body) { saved = snapshot(body, body.inputs, { field_count: body.inputs.fields.length, fields: body.inputs.fields.map(row => ({ ...row, status: row.quality })), missing_items: [] }, 45); return ok(saved); }
        return url.includes('/snapshots/45') ? ok(saved) : ok(overview(scope));
    });
    try {
        mounted.state.hydrate({ fields: [{ key: '客房定位', value: 'partial', unit: '', source_ref: 'unknown', as_of: '2026-10-01', quality: 'manual_reference' }, { key: 'constructor', value: 'unknown', unit: '', source_ref: 'partial', as_of: '2026-10-01', quality: 'unverified' }] }); await settle();
        await mounted.enter('字段值', 'constructor'); await mounted.click('保存新版本并核对');
        const rows = resultRows(mounted); assert.deepEqual(rows[1], ['客房定位', 'constructor']); assert.equal(rows[3][0], 'constructor'); assert.equal(rows[3][1], 'unknown');
        assert.deepEqual(plain(mounted.state.saved.inputs), plain(mounted.state.inputs()));
    } finally { mounted.close(); }
});

test('real Vue: AI citation add, URL edit and fact check keep the observation separate from operating claims', async () => {
    const posts = [];
    const mounted = await open('geo_observation', async (url, options, scope, body) => {
        if (!body) return ok(overview(scope)); posts.push(body); return ok({ scope: body, inputs: body.inputs, result: { mode: body.mode, status: 'recorded', record: body.inputs } });
    });
    try {
        mounted.state.hydrate({ ...fixtures.geo_observation, citations: [] }); await settle(); await mounted.click('添加回答引用');
        await mounted.enter('HTTP/HTTPS来源链接', 'https://example.org/synthetic-evidence'); await mounted.choose(mounted.input('与酒店事实一致性'), 'consistent');
        const form = mounted.all().find(item => item.type === 'form'); await form.props.onSubmit({ preventDefault() {} }); await settle();
        assert.deepEqual(posts[0].inputs.citations, [{ url: 'https://example.org/synthetic-evidence', fact_consistency: 'consistent' }]);
        assert.equal(resultRows(mounted).at(-1)[1], 'https://example.org/synthetic-evidence · 人工核对一致');
        assert.equal(posts[0].platform, 'whole_hotel'); assert.equal(posts[0].inputs.observed_at, '2026-10-01T09:30');
    } finally { mounted.close(); }
});

test('real Vue: OTA platform and month switches clear old identity, while a new observation keeps independent stay dates', async () => {
    const mounted = await open('ota_scene');
    try {
        mounted.state.hydrate(fixtures.ota_scene); mounted.state.record = snapshot(mounted.state.scope, fixtures.ota_scene, {}); mounted.state.saved = mounted.state.record; await settle();
        const platform = mounted.all().find(item => item.type === 'select' && item.props['aria-label'] === 'OTA平台'); await mounted.choose(platform, 'meituan');
        assert.equal(mounted.state.record, null); assert.equal(mounted.state.saved, null); assert.equal(mounted.state.form.scene.source_ref, '');
        const month = mounted.all().find(item => item.type === 'input' && item.props['aria-label'] === '业务账期'); month.value = '2026-11'; month.listeners.input({ target: month }); await settle();
        assert.equal(mounted.state.scope.platform, 'meituan'); assert.equal(mounted.state.scope.period_month, '2026-11');
        const input = plain(fixtures.ota_scene); input.scene.observed_at = '2026-11-30T09:30:00'; input.scene.check_in = '2026-12-01'; input.scene.check_out = '2026-12-02'; mounted.state.hydrate(input); await settle();
        assert.equal(mounted.state.inputs().scene.check_in, '2026-12-01'); assert.equal(mounted.state.inputs().scene.check_out, '2026-12-02');
    } finally { mounted.close(); }
});

test('real Vue: old market versions with missing or conflicting attestation require explicit checking before a new version', async () => {
    const mounted = await open('market_sample');
    try {
        for (const mutate of [input => { delete input.comparison_attested; }, input => { input.comparison_attested = 'true'; }, input => { input.hotels[1].comparison_key = 'other-scene'; }]) {
            const input = plain(fixtures.market_sample); mutate(input); mounted.state.hydrate(input); await settle();
            assert.equal(mounted.state.form.comparison_attested, false); assert.equal(mounted.input('我已人工核对每家样本使用同一搜索及价格口径引用键').props.checked, false);
            assert.throws(() => mounted.state.inputs(), /人工核对/); await mounted.check('我已人工核对每家样本使用同一搜索及价格口径引用键'); assert.equal(mounted.state.inputs().comparison_attested, true);
        }
    } finally { mounted.close(); }
});

test('real Vue: review legacy periods and missing result values remain missing after restoration and one-side correction', async () => {
    const input = plain(fixtures.operating_review); input.actual.period_start = '2026-09-01'; delete input.actual.period_end; input.actual.operating_cost = null;
    const mounted = await open('operating_review', async (url, options, scope) => url.includes('/snapshots/32') ? ok(snapshot(scope, input, { scope_aligned: false, rows: [{ metric: 'operating_cost', plan: 3000, actual: null, difference: null }], missing_items: ['actual.period_mismatch', 'operating_cost'] })) : ok(overview(scope)));
    try {
        await mounted.click('版本 #32 · 2026-10-01 09:30:00');
        assert.equal(mounted.input('实际来源期间起日').props.value, '2026-09-01'); assert.equal(mounted.input('实际来源期间止日').props.value, '');
        assert.equal(resultRows(mounted).some(([label]) => label === '是否具备可比条件'), false);
        await mounted.enter('实际来源期间起日', '2026-10-01'); const restored = mounted.state.inputs();
        assert.equal(restored.actual.period_start, '2026-10-01'); assert.equal(restored.actual.period_end, ''); assert.equal(restored.plan.period_end, '2026-10-31'); assert.equal(restored.actual.operating_cost, null);
        assert.equal(mounted.state.resultCurrent, false);
    } finally { mounted.close(); }
});
