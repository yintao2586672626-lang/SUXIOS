import { readFileSync } from 'node:fs';
import assert from 'node:assert/strict';
import { test } from 'node:test';
import * as Vue from 'vue';
import vm from 'node:vm';

const source = readFileSync(process.env.OPERATING_TARGET_SOURCE || 'public/app-main.js', 'utf8');
const runtime = { window: {} };
vm.runInNewContext(readFileSync('public/operation-static.js', 'utf8'), runtime);
const block = (start, end) => source.slice(source.indexOf(start), source.indexOf(end, source.indexOf(start)));
export const handlerSource = block('            const operatingTargetContext =', '            const applyDingdandaoPmsIntegration =')
    + block('            const loadOperatingTarget =', '            const prefillOperatingTargetFromDailyReport =')
    + block('            const prefillOperatingTargetFromDailyReport =', '            const openOperatingTargetTaskDraft =');
export const initialForm = { hotel_id: '90001', target_date: '2026-09-20', target_revenue: 1000, source_type: 'manual', actual_revenue: '', sold_room_nights: '', sellable_room_nights: '', quality_status: 'unverified' };
export const record = (form = initialForm) => ({ id: 1, tenant_id: 9001, hotel_id: Number(form.hotel_id), target_date: form.target_date,
    facts: { ...form, target_revenue: Number(form.target_revenue), actual_revenue: 0, sold_room_nights: 0, sellable_room_nights: 10 } });
const deferred = () => { let resolve, reject; const promise = new Promise((r, j) => { resolve = r; reject = j; }); return { promise, resolve, reject }; };
export function mount(request, loadHelpers = async () => runtime.window.SUXI_OPERATION_STATIC) {
    const refs = {
        operatingTargetForm: { ...initialForm }, operatingTargetResult: null, operatingTargetPreview: null,
        operatingTargetError: '', operatingTargetTaskDraft: null, operatingTargetTaskDraftError: '', operatingTargetTaskDraftLoading: false,
        operatingTargetLoading: {}, operatingTargetHistory: { list: [] }, operatingTargetSnapshots: { list: [] },
        operatingTargetSelectedSnapshot: null, operatingTargetReportGate: null,
        operatingTargetTestResult: null, operatingTargetTestFirstConfirmed: false,
        filterReportHotel: '90001', currentPage: 'operating-targets',
        operatingTargetPmsStatus: null, operatingTargetMeituanCloudPmsStatus: null, operatingTargetPmsReconciliation: null,
        operatingHotelPmsBinding: null, operatingHotelPmsBindingLoading: false, operatingHotelPmsBindingError: '', operatingPmsRealtimeSyncResult: null, dualOtaPmsSelected: false,
    };
    const scope = Object.fromEntries(Object.entries(refs).map(([key, value]) => [key, Vue.ref(value)]));
    const calls = [], toasts = []; let session = 1;
    Object.assign(scope, {
        watch: Vue.watch, captureAuthSession: () => session, isAuthSessionCurrent: s => s === session,
        isOperationHotelPermitted: id => ['90001', '90002'].includes(String(id)),
        apiRequest: async (path, options) => { calls.push({ path, options }); return request(path, options); },
        showToast: (...args) => toasts.push(args), operationErrorMessage: (e, fallback) => e.message || fallback,
        resetOperatingTargetFormForContext: (_form, hotelId, targetDate) => ({ ...initialForm, hotel_id: hotelId, target_date: targetDate, target_revenue: '' }),
        loadOperationStatic: loadHelpers,
        recordPmsHotelUsage: () => {},
    });
    const functions = new Function(...Object.keys(scope), `
        let operatingTargetRequestSequence=0,operatingTargetHistoryRequestSequence=0,operatingTargetSnapshotsRequestSequence=0,operatingTargetReportGateRequestSequence=0,operatingPmsRealtimeRequestSequence=0,operatingHotelPmsBindingRequestSequence=0;
        ${handlerSource}
        return {saveOperatingTarget,loadOperatingTarget,syncOperatingPmsRealtime,loadOperatingTargetHistory,loadOperatingTargetSnapshots,loadOperatingTargetReportGate,prefillOperatingTargetFromDailyReport,prefillOperatingTargetFromDingdandao,prefillOperatingTargetFromMeituanCloud,createOperatingTargetTaskDraft};
    `)(...Object.values(scope));
    return { ...scope, ...functions, calls, toasts, changeSession: () => { session++; } };
}
const pmsReply = (provider = 'dingdandao') => ({ code: 200, data: {
    prefill: { target_date: '2026-09-20', source_type: 'pms', actual_revenue: 0, sold_room_nights: 0, sellable_room_nights: 10, quality_status: 'verified', source_reference: 'SYNTHETIC ' + provider, fact_scope: 'accommodation_room_fee' },
    capture: { id: 91, hotel_id: 90001, business_date: '2026-09-20', quality_status: 'verified' },
} });
const providers = [['dingdandao', 'prefillOperatingTargetFromDingdandao', 'operatingTargetPmsStatus'], ['meituan-cloud', 'prefillOperatingTargetFromMeituanCloud', 'operatingTargetMeituanCloudPmsStatus']];
test('legacy daily-report prefill keeps the current goal and remains unverified', async () => {
    const m = mount(() => ({ code: 200, data: { prefill: { target_date: '2026-09-20', actual_revenue: 0, sold_room_nights: 0, sellable_room_nights: null, source_type: 'daily_report', quality_status: 'verified', fact_scope: 'whole_hotel' } } }));
    await m.prefillOperatingTargetFromDailyReport();
    assert.equal(m.operatingTargetForm.value.target_revenue, 1000);
    assert.equal(m.operatingTargetForm.value.actual_revenue, 0); assert.equal(m.operatingTargetForm.value.sellable_room_nights, null);
    assert.equal(m.operatingTargetForm.value.quality_status, 'unverified'); assert.equal(m.operatingTargetForm.value.fact_scope, 'whole_hotel');
});
test('legacy daily-report late response cannot restore a prior scope', async () => {
    const pending = deferred(), m = mount(() => pending.promise); const done = m.prefillOperatingTargetFromDailyReport(); await flush();
    m.operatingTargetForm.value.target_date = '2026-09-21';
    pending.resolve({ code: 200, data: { prefill: { target_date: '2026-09-20', actual_revenue: 100, source_type: 'daily_report' } } }); await done;
    assert.equal(m.operatingTargetForm.value.target_date, '2026-09-21'); assert.equal(m.operatingTargetForm.value.source_type, 'manual');
    assert.equal(m.toasts.length, 0);
});
for (const [provider, action, status] of providers) {
    for (const bad of ['hotel', 'capture-date', 'prefill-date']) {
        test(provider + ' rejects a successful-looking wrong ' + bad + ' receipt', async () => {
            const reply = pmsReply(provider);
            if (bad === 'hotel') reply.data.capture.hotel_id = 90002;
            if (bad === 'capture-date') reply.data.capture.business_date = '2026-09-21';
            if (bad === 'prefill-date') reply.data.prefill.target_date = '2026-09-21';
            const m = mount(() => reply); await m[action]();
            assert.equal(m.operatingTargetForm.value.source_type, 'manual'); assert.equal(m[status].value, null);
            assert.ok(m.operatingTargetError.value); assert.equal(m.toasts.length, 0);
        });
    }
    test(provider + ' lazy-load failure recovers and preserves the same goal', async () => {
        let failed = false;
        const m = mount(() => pmsReply(provider), async () => {
            if (!failed) { failed = true; throw Error('SYNTHETIC helper unavailable'); }
            return runtime.window.SUXI_OPERATION_STATIC;
        });
        await m[action](); assert.equal(m.calls.length, 0); assert.equal(m.operatingTargetLoading.value.prefill, false);
        await m[action](); assert.equal(m.operatingTargetForm.value.target_revenue, 1000); assert.equal(m.toasts.length, 1);
    });
}
const success = form => ({ code: 200, data: { record: record(form), report_preview: { status: 'incomplete' } } });
const flush = async () => { for (let i = 0; i < 12; i++) await Promise.resolve(); };
const postPending = async () => {
    const pending = deferred(); const m = mount((_path, options) => options?.method === 'POST' ? pending.promise : { code: 200, data: { list: [], gate: { status: 'blocked' } } });
    const done = m.saveOperatingTarget(); await flush();
    return { m, pending, done };
};

for (const kind of ['hotel', 'date', 'amount', 'hotel-aba', 'date-aba', 'amount-aba', 'session', 'page', 'replaced-draft']) {
    test('pending save cannot replace newer ' + kind, async () => {
        const { m, pending, done } = await postPending(), form = m.operatingTargetForm.value;
        if (kind.startsWith('hotel')) { form.hotel_id = '90002'; if (kind.endsWith('aba')) form.hotel_id = '90001'; }
        if (kind.startsWith('date')) { form.target_date = '2026-09-21'; if (kind.endsWith('aba')) form.target_date = '2026-09-20'; }
        if (kind.startsWith('amount')) { form.target_revenue = 2222; if (kind.endsWith('aba')) form.target_revenue = 1000; }
        if (kind === 'session') { m.changeSession(); m.changeSession(); }
        if (kind === 'page') { m.currentPage.value = 'pms-operating-data'; m.currentPage.value = 'operating-targets'; }
        if (kind === 'replaced-draft') m.operatingTargetForm.value = { ...form };
        const current = JSON.stringify(m.operatingTargetForm.value);
        pending.resolve(success()); await done;
        assert.equal(JSON.stringify(m.operatingTargetForm.value), current);
        assert.equal(m.operatingTargetResult.value, null); assert.equal(m.operatingTargetPreview.value, null);
        assert.equal(m.calls.length, 1); assert.equal(m.toasts.length, 0); assert.equal(m.operatingTargetLoading.value.save, false);
    });
}
test('stale failure preserves current error and draft', async () => {
    const { m, pending, done } = await postPending();
    m.operatingTargetForm.value.target_revenue = 2222; m.operatingTargetError.value = 'current notice';
    pending.reject(Error('old failure')); await done;
    assert.equal(m.operatingTargetError.value, 'current notice'); assert.equal(m.operatingTargetLoading.value.save, false);
});
test('visible history refresh accepts the DOM click event', async () => {
    const m = mount(() => ({ code: 200, data: { list: [{ id: 42 }] } }));
    await m.loadOperatingTargetHistory({ type: 'click' });
    assert.equal(m.operatingTargetHistory.value.list[0]?.id, 42); assert.equal(m.operatingTargetError.value, '');
});
test('same pending save is single flight', async () => {
    const { m, pending, done } = await postPending(); const second = m.saveOperatingTarget();
    assert.equal(m.calls.length, 1); pending.resolve(success()); await Promise.all([done, second]);
});
test('explicit current-record read supersedes a pending save even before its response', async () => {
    const pending = deferred(), read = deferred();
    const m = mount((path, options) => options?.method === 'POST' ? pending.promise : path.includes('/current?') ? read.promise : { code: 200, data: { list: [] } });
    const done = m.saveOperatingTarget(); await flush(); const reading = m.loadOperatingTarget();
    pending.resolve(success()); await done;
    assert.equal(m.operatingTargetResult.value, null); assert.equal(m.toasts.length, 0);
    assert.equal(m.calls.length, 2); read.resolve(success()); await reading;
});
for (const kind of ['missing', 'wrong-hotel', 'wrong-date']) {
    test('invalid saved record cannot reset or retarget draft: ' + kind, async () => {
        const { m, pending, done } = await postPending(); const data = success();
        if (kind === 'missing') data.data.record = null;
        if (kind === 'wrong-hotel') data.data.record.hotel_id = 90002;
        if (kind === 'wrong-date') data.data.record.target_date = '2026-09-21';
        pending.resolve(data); await done;
        assert.deepEqual(m.operatingTargetForm.value, initialForm);
        assert.ok(m.operatingTargetError.value); assert.equal(m.operatingTargetResult.value, null);
        assert.equal(m.toasts.length, 0); assert.equal(m.calls.length, 1);
    });
}
test('current failure remains visible, clears busy, and retries normally', async () => {
    let fail = true;
    const m = mount((_path, options) => {
        if (options?.method === 'POST') { if (fail) { fail = false; throw Error('synthetic failure'); } return success(); }
        return { code: 200, data: { list: [], gate: { status: 'blocked' } } };
    });
    await m.saveOperatingTarget(); assert.match(m.operatingTargetError.value, /synthetic failure/);
    assert.equal(m.operatingTargetLoading.value.save, false);
    await m.saveOperatingTarget(); assert.equal(m.operatingTargetError.value, '');
    assert.equal(m.operatingTargetResult.value.hotel_id, 90001); assert.equal(m.toasts.length, 1);
});
test('manual target cannot submit edited synthetic PMS facts as verified', async () => {
    const { m, pending, done } = await postPending();
    const payload = JSON.parse(m.calls[0].options.body);
    assert.equal(payload.quality_status, 'unverified'); assert.equal(payload.actual_revenue, null);
    assert.equal(payload.sold_room_nights, null); assert.equal(payload.source_reference, '');
    pending.resolve(success()); await done; assert.equal(m.toasts.length, 1);
});
test('PMS zero and unknown facts survive payload and exact readback', async () => {
    let saved;
    const m = mount((_path, options) => {
        if (options?.method === 'POST') { saved = JSON.parse(options.body); return { code: 200, data: { record: { ...record(saved), facts: saved } } }; }
        return { code: 200, data: { list: [] } };
    });
    Object.assign(m.operatingTargetForm.value, { source_type: 'pms', actual_revenue: 0, sold_room_nights: 0, sellable_room_nights: null, quality_status: 'partial', source_reference: 'SYNTHETIC-readback' });
    await m.saveOperatingTarget(); assert.equal(saved.actual_revenue, 0); assert.equal(saved.sellable_room_nights, null);
    assert.equal(m.operatingTargetForm.value.actual_revenue, 0); assert.equal(m.operatingTargetForm.value.quality_status, 'partial');
});
for (const outcome of ['success', 'error']) {
    test('follow-up reads and toast cannot cross a later draft ABA: ' + outcome, async () => {
        const reads = deferred(); const m = mount((_path, options) => options?.method === 'POST' ? success() : reads.promise);
        const done = m.saveOperatingTarget(); await flush();
        assert.equal(m.calls.length, 4);
        m.operatingTargetForm.value.target_revenue = 3333; m.operatingTargetForm.value.target_revenue = 1000;
        m.operatingTargetError.value = 'new notice';
        if (outcome === 'success') reads.resolve({ code: 200, data: { list: [{ id: 999 }], gate: { status: 'wrong old read' } } });
        else reads.reject(Error('old related read failure'));
        await done;
        assert.deepEqual(m.operatingTargetHistory.value.list, []); assert.deepEqual(m.operatingTargetSnapshots.value.list, []);
        assert.equal(m.operatingTargetReportGate.value, null); assert.equal(m.operatingTargetError.value, 'new notice');
        assert.equal(m.toasts.length, 0);
        assert.equal(m.operatingTargetLoading.value.save, false);
    });
}
test('draft changes during lazy loading prevent a stale POST', async () => {
    const loading = deferred(); const m = mount(() => success(), () => loading.promise);
    const done = m.saveOperatingTarget();
    m.operatingTargetForm.value.target_revenue = 2000; m.operatingTargetForm.value.target_revenue = 1000;
    loading.resolve(runtime.window.SUXI_OPERATION_STATIC); await done;
    assert.equal(m.calls.length, 0); assert.equal(m.operatingTargetLoading.value.save, false);
});
test('lazy load failure releases busy and allows save retry', async () => {
    let failed = false;
    const m = mount((_path, options) => options?.method === 'POST' ? success() : { code: 200, data: { list: [] } }, async () => {
        if (!failed) { failed = true; throw Error('SYNTHETIC helpers unavailable'); }
        return runtime.window.SUXI_OPERATION_STATIC;
    });
    await m.saveOperatingTarget(); assert.match(m.operatingTargetError.value, /helpers unavailable/);
    assert.equal(m.calls.length, 0); assert.equal(m.operatingTargetLoading.value.save, false);
    await m.saveOperatingTarget(); assert.equal(m.toasts.length, 1);
});

for (const [provider, action, status] of providers) {
    for (const kind of ['hotel', 'date', 'amount-aba', 'scope-aba', 'session', 'page', 'read', 'save']) {
        test(provider + ' prefill cannot overwrite later ' + kind, async () => {
            const pending = deferred();
            const m = mount((path, options) => path.includes('/prefill/') ? pending.promise : options?.method === 'POST' || path.includes('/current?') ? success() : { code: 200, data: { list: [] } });
            const done = m[action](); await flush();
            if (kind === 'hotel') m.operatingTargetForm.value.hotel_id = '90002';
            if (kind === 'date') m.operatingTargetForm.value.target_date = '2026-09-21';
            if (kind === 'amount-aba') { m.operatingTargetForm.value.target_revenue = 2222; m.operatingTargetForm.value.target_revenue = 1000; }
            if (kind === 'scope-aba') { m.operatingTargetForm.value.hotel_id = '90002'; m.operatingTargetForm.value.hotel_id = '90001'; }
            if (kind === 'session') m.changeSession();
            if (kind === 'page') { m.currentPage.value = 'other'; m.currentPage.value = 'operating-targets'; }
            if (kind === 'read') await m.loadOperatingTarget();
            if (kind === 'save') await m.saveOperatingTarget();
            const before = JSON.stringify(m.operatingTargetForm.value), toasts = m.toasts.length;
            pending.resolve(pmsReply(provider)); await done;
            assert.equal(JSON.stringify(m.operatingTargetForm.value), before);
            assert.equal(m[status].value, null); assert.equal(m.toasts.length, toasts);
            assert.equal(m.operatingTargetLoading.value.prefill, false);
        });
    }
    test(provider + ' stale failure cannot replace current notice', async () => {
        const pending = deferred(), m = mount(() => pending.promise);
        const done = m[action](); await flush(); m.operatingTargetForm.value.target_revenue = 2222;
        m.operatingTargetError.value = 'current notice'; pending.reject(Error('old prefill error')); await done;
        assert.equal(m.operatingTargetError.value, 'current notice'); assert.equal(m.operatingTargetLoading.value.prefill, false);
    });
    test(provider + ' current failure/retry preserves target and explicit PMS zero', async () => {
        let failed = false;
        const m = mount(() => { if (!failed) { failed = true; throw Error('SYNTHETIC prefill failed'); } return pmsReply(provider); });
        await m[action](); assert.match(m.operatingTargetError.value, /prefill failed/); assert.equal(m.operatingTargetLoading.value.prefill, false);
        await m[action](); assert.equal(m.operatingTargetForm.value.actual_revenue, 0);
        assert.equal(m.operatingTargetForm.value.target_revenue, 1000); assert.equal(m[status].value.id, 91);
        assert.equal(m.operatingTargetError.value, ''); assert.equal(m.toasts.length, 1);
    });
    test(provider + ' failed fresh check cannot leave earlier verified facts in the save form', async () => {
        const m = mount(() => { throw Error('SYNTHETIC current check failed'); });
        Object.assign(m.operatingTargetForm.value, pmsReply(provider).data.prefill);
        m.operatingTargetResult.value = record(m.operatingTargetForm.value);
        m.operatingTargetPreview.value = { status: 'ready' };
        await m[action]();
        assert.equal(m.operatingTargetForm.value.target_revenue, 1000);
        assert.equal(m.operatingTargetForm.value.source_type, 'manual');
        assert.equal(m.operatingTargetForm.value.actual_revenue, '');
        assert.equal(m.operatingTargetForm.value.quality_status, 'unverified');
        assert.equal(m.operatingTargetResult.value, null); assert.equal(m.operatingTargetPreview.value, null);
        assert.match(m.operatingTargetError.value, /current check failed/);
    });
    test(provider + ' missing capture replaces stale status and reports its blocker', async () => {
        const m = mount(() => ({ code: 200, data: { prefill: null, capture: null, gaps: [{ message: 'SYNTHETIC missing exact capture' }] } }));
        m[status].value = { id: 77, quality_status: 'verified' }; await m[action]();
        assert.equal(m[status].value, null); assert.match(m.operatingTargetError.value, /missing exact capture/);
        assert.equal(m.operatingTargetForm.value.source_type, 'manual'); assert.equal(m.toasts.length, 0);
    });
    for (const earlier of ['read', 'save']) {
        test(provider + ' later prefill wins over older ' + earlier, async () => {
            const pending = deferred();
            const m = mount((path, options) => path.includes('/prefill/') ? pmsReply(provider)
                : (earlier === 'save' ? options?.method === 'POST' : path.includes('/current?')) ? pending.promise : { code: 200, data: { list: [] } });
            const old = earlier === 'save' ? m.saveOperatingTarget() : m.loadOperatingTarget(); await flush();
            await m[action](); const before = JSON.stringify(m.operatingTargetForm.value), toasts = m.toasts.length;
            pending.resolve(success()); await old;
            assert.equal(JSON.stringify(m.operatingTargetForm.value), before); assert.equal(m.toasts.length, toasts);
            assert.equal(m.operatingTargetForm.value.source_type, 'pms');
            assert.equal(m.operatingTargetLoading.value[earlier === 'save' ? 'save' : 'current'], false);
        });
    }
    test(provider + ' scope change removes saved PMS facts before another save', async () => {
        let payload;
        const m = mount((path, options) => path.includes('/prefill/') ? pmsReply(provider) : options?.method === 'POST'
            ? (payload = JSON.parse(options.body), success(payload)) : { code: 200, data: { list: [] } });
        await m[action](); m.operatingTargetForm.value.hotel_id = '90002'; m.operatingTargetForm.value.target_revenue = 2222;
        await m.saveOperatingTarget();
        assert.equal(payload.hotel_id, '90002'); assert.equal(payload.actual_revenue, null);
        assert.equal(payload.source_type, 'manual'); assert.equal(payload.quality_status, 'unverified');
        assert.equal(payload.source_reference, '');
    });
}

const taskRecord = () => ({ ...record(), revision_no: 2 });
const taskReply = () => ({ code: 200, data: { status: 'task_draft_ready', target: { record_id: 1, revision_no: 2, target_date: initialForm.target_date }, execution_intent: { id: 42, tenant_id: 9001, hotel_id: 90001, source_record_id: 1, source_module: 'operating_target', date_start: initialForm.target_date, date_end: initialForm.target_date, status: 'pending_approval' }, reused_existing_intent: false } });
const taskMount = (request, loader) => { const m = mount(request, loader); m.operatingTargetResult.value = taskRecord(); return m; };
for (const kind of ['hotel', 'date', 'amount', 'hotel-aba', 'date-aba', 'amount-aba', 'page-aba', 'session', 'record', 'record-aba']) {
    test('task draft ignores stale ' + kind + ' success', async () => {
        const pending = deferred(), m = taskMount(() => pending.promise); const done = m.createOperatingTargetTaskDraft(); await flush();
        const form = m.operatingTargetForm.value, saved = m.operatingTargetResult.value;
        if (kind.startsWith('hotel')) { form.hotel_id = '90002'; if (kind.endsWith('aba')) form.hotel_id = '90001'; }
        if (kind.startsWith('date')) { form.target_date = '2026-09-21'; if (kind.endsWith('aba')) form.target_date = initialForm.target_date; }
        if (kind.startsWith('amount')) { form.target_revenue = 2222; if (kind.endsWith('aba')) form.target_revenue = 1000; }
        if (kind === 'page-aba') { m.currentPage.value = 'ops-track'; m.currentPage.value = 'operating-targets'; }
        if (kind === 'session') m.changeSession();
        if (kind.startsWith('record')) { m.operatingTargetResult.value = { ...saved, revision_no: 3 }; if (kind.endsWith('aba')) m.operatingTargetResult.value = saved; }
        pending.resolve(taskReply()); await done;
        assert.equal(m.operatingTargetTaskDraft.value, null); assert.equal(m.toasts.length, 0); assert.equal(m.operatingTargetTaskDraftLoading.value, false);
    });
}
test('task draft ignores obsolete failure and keeps newer notice', async () => {
    const pending = deferred(), m = taskMount(() => pending.promise), done = m.createOperatingTargetTaskDraft(); await flush();
    m.operatingTargetForm.value.target_date = '2026-09-21'; m.operatingTargetTaskDraftError.value = 'current notice';
    pending.reject(Error('old failure')); await done; assert.equal(m.operatingTargetTaskDraftError.value, 'current notice'); assert.equal(m.operatingTargetTaskDraftLoading.value, false);
});
test('task draft is single flight', async () => {
    const pending = deferred(), m = taskMount(() => pending.promise), one = m.createOperatingTargetTaskDraft(); await flush(); const two = m.createOperatingTargetTaskDraft(); await flush();
    assert.equal(m.calls.length, 1); pending.resolve(taskReply()); await Promise.all([one, two]);
});
test('task draft succeeds with exact saved target and retains real task identity', async () => {
    const m = taskMount(() => taskReply()); await m.createOperatingTargetTaskDraft();
    const { status_message, ...data } = JSON.parse(JSON.stringify(m.operatingTargetTaskDraft.value));
    assert.deepEqual(data, taskReply().data); assert.match(status_message, /待审批/);
    assert.deepEqual(JSON.parse(m.calls[0].options.body), { hotel_id: '90001', target_date: initialForm.target_date }); assert.equal(m.toasts.length, 1);
});
test('task draft current error can retry through original endpoint', async () => {
    let attempts = 0; const m = taskMount(() => { if (++attempts === 1) throw Error('SYNTHETIC draft unavailable'); return taskReply(); });
    await m.createOperatingTargetTaskDraft(); assert.match(m.operatingTargetTaskDraftError.value, /draft unavailable/); assert.equal(m.operatingTargetTaskDraftLoading.value, false);
    await m.createOperatingTargetTaskDraft(); assert.equal(m.operatingTargetTaskDraft.value.execution_intent.id, 42); assert.equal(m.operatingTargetTaskDraftError.value, '');
});
test('task draft lazy loading failure can retry before sending', async () => {
    let attempts = 0; const m = taskMount(() => taskReply(), async () => { if (++attempts === 1) throw Error('SYNTHETIC module failed'); return runtime.window.SUXI_OPERATION_STATIC; });
    await m.createOperatingTargetTaskDraft(); assert.equal(m.calls.length, 0); assert.equal(m.operatingTargetTaskDraftLoading.value, false);
    await m.createOperatingTargetTaskDraft(); assert.equal(m.operatingTargetTaskDraft.value.execution_intent.id, 42);
});
for (const busy of ['current', 'save', 'prefill']) test('task draft waits for active ' + busy, async () => {
    const m = taskMount(() => taskReply()); m.operatingTargetLoading.value[busy] = true; await m.createOperatingTargetTaskDraft();
    assert.equal(m.calls.length, 0); assert.equal(m.operatingTargetTaskDraft.value, null); assert.ok(m.operatingTargetTaskDraftError.value);
});
test('task draft waits for a saved record before sending', async () => {
    const m = mount(() => taskReply()); await m.createOperatingTargetTaskDraft(); assert.equal(m.calls.length, 0); assert.ok(m.operatingTargetTaskDraftError.value);
});
test('task draft reports persisted blocked state as blocked rather than pending success', async () => {
    const reply = taskReply(); reply.data.execution_intent.status = 'blocked'; reply.data.execution_intent.blocked_reason = 'SYNTHETIC unresolved facts';
    const m = taskMount(() => reply); await m.createOperatingTargetTaskDraft();
    assert.equal(m.operatingTargetTaskDraft.value, null); assert.equal(m.toasts.length, 0); assert.match(m.operatingTargetTaskDraftError.value, /已保存.*阻塞.*unresolved facts/);
});
test('task draft replay of approved intent does not claim still awaiting approval', async () => {
    const reply = taskReply(); reply.data.execution_intent.status = 'approved'; reply.data.reused_existing_intent = true;
    const m = taskMount(() => reply); await m.createOperatingTargetTaskDraft();
    assert.equal(m.operatingTargetTaskDraft.value.execution_intent.id, 42); assert.doesNotMatch(m.operatingTargetTaskDraft.value.status_message, /待审批/);
});
test('blocked task-draft click does not invalidate the active target read', async () => {
    const pending = deferred(), m = taskMount(path => path.includes('/current?') ? pending.promise : { code: 200, data: { list: [] } });
    const reading = m.loadOperatingTarget(); await flush(); await m.createOperatingTargetTaskDraft();
    pending.resolve({ code: 200, data: { record: { ...taskRecord(), revision_no: 3 } } }); await reading;
    assert.equal(m.operatingTargetResult.value.revision_no, 3); assert.equal(m.calls.some(c => c.path.endsWith('/task-draft')), false);
});
test('task draft loses ownership immediately when a newer explicit read begins', async () => {
    const pending = deferred(), reading = deferred(), m = taskMount(path => path.endsWith('/task-draft') ? pending.promise : reading.promise);
    const done = m.createOperatingTargetTaskDraft(); await flush(); const fresh = m.loadOperatingTarget(); await flush();
    pending.resolve(taskReply()); await done; assert.equal(m.operatingTargetTaskDraft.value, null); assert.equal(m.toasts.length, 0);
    reading.resolve({ code: 200, data: { record: taskRecord(), list: [] } }); await fresh;
});
for (const kind of ['missing', 'hotel', 'tenant', 'date', 'record', 'revision', 'intent-id', 'source', 'intent-date']) test('task draft rejects mismatched ' + kind + ' receipt', async () => {
    const reply = taskReply();
    if (kind === 'missing') reply.data = null;
    if (kind === 'hotel') reply.data.execution_intent.hotel_id = 90002;
    if (kind === 'tenant') reply.data.execution_intent.tenant_id = 9002;
    if (kind === 'date') reply.data.target.target_date = '2026-09-21';
    if (kind === 'record') reply.data.target.record_id = 2;
    if (kind === 'revision') reply.data.target.revision_no = 3;
    if (kind === 'intent-id') reply.data.execution_intent.id = 0;
    if (kind === 'source') reply.data.execution_intent.source_record_id = 2;
    if (kind === 'intent-date') reply.data.execution_intent.date_start = '2026-09-21';
    const m = taskMount(() => reply); await m.createOperatingTargetTaskDraft();
    assert.equal(m.operatingTargetTaskDraft.value, null); assert.ok(m.operatingTargetTaskDraftError.value); assert.equal(m.toasts.length, 0);
});
