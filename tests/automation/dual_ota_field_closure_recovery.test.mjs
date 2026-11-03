import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

// synthetic-test-only: the actual component runs against isolated request/DOM fixtures.
const clicked = [];
const downloadBlobs = new Map();
const URL = { createObjectURL: blob => {
    const url = `blob:synthetic-${downloadBlobs.size + 1}`;
    downloadBlobs.set(url, blob);
    return url;
}, revokeObjectURL() {} };
const window = { setTimeout: fn => fn() };
const document = {
    createElement: () => ({ click() { clicked.push({ href: this.href, download: this.download }); }, remove() { this.isConnected = false; } }),
    body: { appendChild(anchor) { anchor.isConnected = true; } },
};
vm.runInNewContext(readFileSync('public/components/system/dual-ota-field-closure-panel.js', 'utf8'), { window, document, URLSearchParams, URL, Blob });
const api = window.SUXI_DUAL_OTA_FIELD_CLOSURE;
const h = (tag, props, children) => ({ tag, props: props || {}, children });
const component = api.createPanel({ h });
const day = '2026-10-01';
const closure = (overrides = {}) => ({
    contract_version: 'dual_ota_field_closure.v1', tenant_id: 801, hotel_id: 901,
    business_date: day, page_identity: 'dual_ota_field_closure#synthetic-901',
    sensitive_values_exposed: false, status: 'partial', revenue_analysis_consumable_field_count: 0,
    platforms: {
        ctrip: { platform: 'ctrip', platform_label: '携程', fields: [
            { key: 'revenue', label: '收入', unit: 'CNY', status: 'strict_readback', value: 0 },
        ] },
        meituan: { platform: 'meituan', platform_label: '美团', fields: [
            { key: 'revenue', label: '收入', unit: 'CNY', status: 'source_missing', value: null },
        ] },
    }, ...overrides,
});
const response = value => ({ code: 200, data: { dual_ota_field_closure: value } });
const deferred = () => { let resolve, reject; const promise = new Promise((a, b) => { resolve = a; reject = b; }); return { promise, resolve, reject }; };
function instance(overrides = {}) {
    const value = { ...component.data(), hotelId: 901, businessDate: day,
        surface: 'revenue_cockpit', closure: null, forceRead: true, request: null, ...overrides };
    for (const [name, method] of Object.entries(component.methods)) value[name] = method.bind(value);
    for (const [name, getter] of Object.entries(component.computed)) Object.defineProperty(value, name, { get: getter.bind(value) });
    return value;
}
const nodes = (tree) => [tree, ...(Array.isArray(tree?.children) ? tree.children.flatMap(nodes) : [])].filter(value => value && typeof value === 'object');
const text = tree => typeof tree === 'string' ? tree : (Array.isArray(tree?.children) ? tree.children.map(text).join(' ') : String(tree?.children || ''));
const refreshButton = value => nodes(component.render.call(value)).find(node => node.props?.['data-testid'] === 'dual-ota-field-refresh-revenue_cockpit');

test('supplemental evidence read failure stays visible with metadata and clears on current readonly recovery', async () => {
    const failed = closure({ supplemental_evidence_read: { status: 'failed' } });
    for (const platform of Object.values(failed.platforms)) {
        platform.fields.forEach(field => { field.status = 'readback_failed'; field.value = null; });
    }
    let current = failed;
    const value = instance({ request: async () => response(current) });
    await value.refreshClosure({ forceReload: true });
    assert.match(text(component.render.call(value)), /补证记录读取失败.*本地来源元数据已保留/);
    assert.ok(nodes(component.render.call(value)).some(node => node.props?.role === 'alert'));
    assert.equal(api.buildClosureDownloadPayload(value.activeClosure).rows.every(row => row.display === '—'), true);
    current = closure();
    await value.refreshClosure({ forceReload: true });
    assert.equal(nodes(component.render.call(value)).some(node => node.props?.role === 'alert'), false);
    assert.equal(value.closureError, '');
});

test('download refuses an unshown parent from another hotel instead of using its raw payload', () => {
    clicked.length = 0;
    const value = instance({ closure: closure({ hotel_id: 902 }) });
    assert.equal(value.activeClosure, null);
    const result = value.downloadClosure();
    assert.equal(result.ok, false);
    assert.equal(clicked.length, 0);
});

test('download refuses an unshown parent from another business day', () => {
    clicked.length = 0;
    const value = instance({ closure: closure({ business_date: '2026-09-30' }) });
    assert.equal(value.downloadClosure().ok, false);
    assert.equal(clicked.length, 0);
});

for (const [name, overrides] of [['contract', { contract_version: 'wrong' }], ['sensitive flag', { sensitive_values_exposed: true }], ['missing platform', { platforms: { ctrip: {} } }]]) {
    test(`same-scope parent with invalid ${name} is hidden and cannot be exported`, () => {
        const value = instance({ closure: closure(overrides) });
        assert.equal(value.activeClosure, null);
        assert.equal(value.downloadClosure().ok, false);
    });
}

test('failed source read exposes a direct retry that recovers actual scoped data', async () => {
    let calls = 0;
    const good = closure();
    const value = instance({ request: async path => {
        calls += 1;
        assert.match(path, /hotel_id=901/); assert.match(path, /end_date=2026-10-01/);
        if (calls === 1) throw new Error('synthetic transient source failure');
        return response(good);
    } });
    await value.refreshClosure();
    assert.match(value.closureError, /synthetic transient/);
    const button = refreshButton(value);
    assert.ok(button, 'failed read needs a direct retry control');
    assert.equal(button.props.disabled, false);
    await button.props.onClick();
    assert.equal(calls, 2);
    assert.equal(value.closureError, '');
    assert.equal(value.activeClosure, good);
});

test('manual refresh reads again despite a parent payload and blocks old display/download while loading', async () => {
    const pending = deferred(); let calls = 0;
    const old = closure(), fresh = closure({ page_identity: 'dual_ota_field_closure#synthetic-new' });
    const value = instance({ closure: old, request: async () => { calls += 1; return pending.promise; } });
    const read = value.refreshClosure({ forceReload: true });
    assert.equal(calls, 1);
    assert.equal(value.closureLoading, true);
    assert.equal(value.activeClosure, null);
    assert.equal(value.downloadClosure().ok, false);
    assert.equal(refreshButton(value).props.disabled, true);
    pending.resolve(response(fresh));
    await read;
    assert.equal(value.activeClosure, fresh);
    assert.equal(value.closureLoading, false);
});

test('failed refresh cannot silently revive a previously valid parent', async () => {
    const value = instance({ closure: closure(), request: async () => { throw new Error('synthetic unavailable'); } });
    await value.refreshClosure({ forceReload: true });
    assert.equal(value.activeClosure, null);
    assert.equal(value.downloadClosure().ok, false);
    assert.match(text(component.render.call(value)), /synthetic unavailable/);
});

test('legitimate missing-field diagnostic and actual zero export the same visible rows', async () => {
    clicked.length = 0;
    const good = closure();
    const value = instance({ fetchedClosure: good });
    const result = value.downloadClosure();
    assert.equal(result.ok, true);
    assert.equal(clicked.length, 1);
    assert.equal(Buffer.from(await downloadBlobs.get(clicked[0].href).arrayBuffer()).toString('utf8'), result.csv);
    assert.deepEqual(Array.from(result.rows, row => row.display), ['¥0.00', '—']);
    assert.deepEqual(Array.from(result.rows, row => row.business_date), [day, day]);
});

test('same-scope normal watcher reads remain deduplicated', async () => {
    const pending = deferred(); let calls = 0;
    const value = instance({ request: () => { calls += 1; return pending.promise; } });
    const first = value.refreshClosure();
    await value.refreshClosure();
    assert.equal(calls, 1);
    pending.resolve(response(closure())); await first;
    assert.ok(value.activeClosure);
});

test('old hotel late success cannot overwrite newer hotel data', async () => {
    const old = deferred(), newer = deferred();
    const value = instance({ request: path => /hotel_id=901\b/.test(path) ? old.promise : newer.promise });
    const first = value.refreshClosure();
    value.hotelId = 902;
    const second = value.refreshClosure();
    const good = closure({ hotel_id: 902 });
    newer.resolve(response(good)); await second;
    old.resolve(response(closure())); await first;
    assert.equal(value.activeClosure, good);
});

test('unmount invalidates an outstanding read', async () => {
    const pending = deferred();
    const value = instance({ request: () => pending.promise });
    const read = value.refreshClosure();
    component.beforeUnmount.call(value);
    pending.resolve(response(closure())); await read;
    assert.equal(value.fetchedClosure, null);
});

for (const [name, patch] of [['missing tenant', {tenant_id: undefined}], ['invalid tenant', {tenant_id: 0}], ['boolean platforms', {platforms:{ctrip:true,meituan:true}}]]) {
    test(`response and supplied parent reject ${name}`, () => {
        const bad = closure(patch);
        assert.equal(api.resolveClosureResponse(response(bad), {hotelId:901,businessDate:day}).ok, false);
        assert.equal(instance({closure:bad}).activeClosure, null);
    });
}

for (const [name, field] of [['tenant',{tenant_id:802}], ['hotel',{system_hotel_id:902}], ['platform',{platform:'meituan'}], ['business day',{business_date:'2026-09-30'}]]) {
    test(`provided field ${name} identity cannot contradict its displayed closure scope`, () => {
        const bad = closure();
        Object.assign(bad.platforms.ctrip.fields[0], field);
        assert.equal(api.resolveClosureResponse(response(bad), {hotelId:901,businessDate:day}).ok, false);
        assert.equal(instance({closure:bad}).downloadClosure().ok, false);
    });
}

test('snapshot-free diagnostic CSV persists identity, quoted labels and unknown legacy identity accurately', () => {
    const good = closure();
    good.platforms.ctrip.fields[0].label = '收入, "核验"\n来源';
    const rows = api.buildClosureDownloadRows(good);
    const csv = api.buildClosureCsv(good);
    assert.deepEqual(Array.from(rows, row => [row.tenant_id,row.hotel_id,row.closure_identity,row.closure_contract_version]), [
        ['801','901',good.page_identity,good.contract_version], ['801','901',good.page_identity,good.contract_version],
    ]);
    assert.ok(csv.startsWith('\uFEFF'));
    assert.match(csv, /"收入, ""核验""\n来源"/);
    assert.match(csv, /"租户ID","酒店ID","字段闭环合同身份","字段闭环合同版本"/);
    const legacy = closure({tenant_id:undefined,hotel_id:undefined,page_identity:undefined,contract_version:undefined});
    assert.deepEqual(Array.from(api.buildClosureDownloadRows(legacy), row => [row.tenant_id,row.hotel_id,row.closure_identity,row.closure_contract_version]), [['','','',''],['','','','']]);
});

test('scope mismatch failure permits a later exact-scope retry without stale facts', async () => {
    let bad = true;
    const value = instance({ request: async () => response(closure({hotel_id:bad ? 902 : 901})) });
    await value.refreshClosure();
    assert.equal(value.activeClosure, null);
    assert.match(value.closureError, /身份不一致/);
    bad = false; await refreshButton(value).props.onClick();
    assert.equal(value.activeClosure.hotel_id,901);
    assert.equal(value.closureError,'');
});

test('auth-session cancellation has a meaningful local retry message', async () => {
    const value = instance({ request: async () => { throw new Error('Authentication session changed'); } });
    await value.refreshClosure();
    assert.equal(value.activeClosure,null);
    assert.match(value.closureError,/登录会话已更新.*重新读取/);
    assert.ok(refreshButton(value));
});

test('invalid scope never initiates a request and direct retry remains disabled', async () => {
    let calls = 0;
    const value = instance({hotelId:0,businessDate:'',request:async () => {calls += 1; return response(closure());}});
    await value.refreshClosure({forceReload:true});
    assert.equal(calls,0); assert.equal(value.activeClosure,null);
    assert.equal(refreshButton(value).props.disabled,true);
});

test('server unavailable placeholder stays a read failure and direct retry can recover', async () => {
    let available = false;
    const value = instance({request: async () => response(available ? closure() : {
        contract_version: 'dual_ota_field_closure.v1', hotel_id: 901, business_date: day,
        status: 'unavailable', reason: 'dual_ota_field_closure_read_failed', platforms: [],
    })});
    await value.refreshClosure();
    assert.equal(value.activeClosure, null);
    assert.match(value.closureError, /读取失败.*重新读取/);
    assert.equal(value.downloadClosure().ok, false);
    available = true;
    await refreshButton(value).props.onClick();
    assert.ok(value.activeClosure);
    assert.equal(value.closureError, '');
});

for (const [name, scope] of [['hotel', {hotelId:0}], ['business day', {businessDate:''}]]) {
    test(`cleared ${name} selection cannot inherit an old parent scope`, async () => {
        let calls=0;
        const value=instance({closure:closure(),...scope,request:async()=>{calls+=1;return response(closure());}});
        assert.equal(value.activeClosure,null);
        assert.equal(value.downloadClosure().ok,false);
        await value.refreshClosure({forceReload:true});
        assert.equal(calls,0);
        assert.equal(refreshButton(value).props.disabled,true);
    });
}

test('explicit source refresh keeps ownership when a same-scope parent watcher arrives', async () => {
    const pending = deferred();
    const old = closure(), fresh = closure({ page_identity: 'dual_ota_field_closure#synthetic-fresh-read' });
    const value = instance({ closure: old, request: () => pending.promise });
    const read = value.refreshClosure({ forceReload: true });
    const seq = value.closureRequestSeq;
    value.closure = structuredClone(old);
    component.watch.closure.call(value);
    await Promise.resolve();
    assert.equal(value.closureLoading, true);
    assert.equal(value.closureRequestSeq, seq);
    assert.equal(value.activeClosure, null);
    assert.equal(value.downloadClosure().ok, false);
    pending.resolve(response(fresh)); await read;
    assert.equal(value.activeClosure, fresh);
    value.closure = structuredClone(old);
    component.watch.closure.call(value); await Promise.resolve();
    assert.equal(value.activeClosure, fresh);
    assert.equal(value.downloadClosure().rows[0].closure_identity, fresh.page_identity);
});

test('explicit source refresh failure survives an old parent watcher and readonly retry recovers', async () => {
    const pending = deferred(); let calls = 0;
    const old = closure(), fresh = closure({ page_identity: 'dual_ota_field_closure#synthetic-retry' });
    const value = instance({ closure: old, request: () => ++calls === 1 ? pending.promise : Promise.resolve(response(fresh)) });
    const read = value.refreshClosure({ forceReload: true });
    value.closure = structuredClone(old); component.watch.closure.call(value);
    await Promise.resolve();
    assert.equal(value.closureLoading, true);
    pending.reject(new Error('synthetic source refresh unavailable')); await read;
    value.closure = structuredClone(old); component.watch.closure.call(value);
    await Promise.resolve();
    assert.match(value.closureError, /synthetic source refresh unavailable/);
    assert.equal(value.activeClosure, null);
    assert.equal(value.downloadClosure().ok, false);
    await refreshButton(value).props.onClick();
    assert.equal(calls, 2);
    assert.equal(value.closureError, '');
    assert.equal(value.activeClosure, fresh);
});

test('explicit source refresh ownership ends on a new hotel and business day', async () => {
    const pending = deferred();
    const value = instance({ closure: closure(), request: () => pending.promise });
    const read = value.refreshClosure({ forceReload: true });
    const newScope = closure({ hotel_id: 902, business_date: '2026-10-02', page_identity: 'dual_ota_field_closure#synthetic-902-next-day' });
    value.hotelId = 902; value.businessDate = '2026-10-02'; value.closure = newScope;
    component.watch.closure.call(value); await Promise.resolve();
    assert.equal(value.activeClosure, newScope);
    pending.reject(new Error('synthetic old-scope failure')); await read;
    assert.equal(value.activeClosure, newScope);
    assert.equal(value.closureError, '');
    const csv = value.downloadClosure();
    assert.equal(csv.ok, true);
    assert.ok(csv.rows.every(row => row.hotel_id === '902' && row.business_date === '2026-10-02'));
});

test('explicit source refresh ownership leaves ordinary parent updates compatible', async () => {
    let calls = 0;
    const old = closure(), fresh = closure({ page_identity: 'dual_ota_field_closure#synthetic-parent-new' });
    const value = instance({ closure: old, request: async () => { calls += 1; return response(old); } });
    await value.refreshClosure();
    value.closure = fresh; component.watch.closure.call(value); await Promise.resolve();
    assert.equal(value.activeClosure, fresh);
    assert.equal(calls, 0);
});
