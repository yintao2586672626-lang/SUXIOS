import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
import { webcrypto } from 'node:crypto';

const source = fs.readFileSync(new URL('../../public/components/system/investment-payback-import.js', import.meta.url), 'utf8');
const rowReview = payload => ({ review_token: 'b'.repeat(64), rows: payload.rows.map(row => ({ row_number: row.row_number, selected: row.selected !== false, errors: [], exact_matches: [], batch_duplicates: [], similar_matches: [], similar_match_count: 0, impact_excluded_reason: null })), can_confirm: true, similar_count: 0, invalid_count: 0, exact_count: 0, as_of: '2026-10-01', impact: { actual_invested_delta: '0.00', actual_net_recovered_delta: '0.00', opening_invested_total: '0.00', opening_net_recovered_total: '0.00' } });
const make = (request = async () => ({ code: 200, data: {} }), props = {}, script = source) => {
    const registry = {}, events = [];
    let nonce = 0, unmount = () => {};
    const sandbox = {
        Vue: { ref: value => ({ value }), reactive: value => value, computed: getter => ({ get value() { return getter(); } }), onMounted: () => {}, onUnmounted: callback => { unmount = callback; } },
        window: { SUXI_SYSTEM_COMPONENTS: registry, crypto: { randomUUID: () => `00000000-0000-4000-8000-${String(++nonce).padStart(12, '0')}`, subtle: webcrypto.subtle }, setTimeout, clearTimeout },
        AbortController, TextEncoder, Uint8Array, Intl, Date, BigInt,
        FileReader: class { readAsDataURL(file) { this.result = `data:application/octet-stream;base64,${Buffer.from(file.content).toString('base64')}`; this.onload(); } },
    };
    vm.runInNewContext(script, sandbox);
    const { reviewRequest, ...componentProps } = props;
    const routedRequest = async (path, options) => {
        const payload = JSON.parse(options.body);
        if (path.endsWith('/preview') && payload.review_rows) return reviewRequest ? reviewRequest(payload) : { code: 200, data: rowReview(payload) };
        return request(path, options);
    };
    const state = registry.InvestmentPaybackImport.setup({ request: routedRequest, today: '2026-10-01', projects: [], project: null, ...componentProps }, { emit: (...args) => events.push(args) });
    return { state, helpers: sandbox.window.SUXI_PAYBACK_IMPORT, events, unmount: () => unmount() };
};
const provenance = { file_name: '合成验收.csv', sha256: 'a'.repeat(64), source_method: 'spreadsheet', sheets: [] };

test('money recognition retains cents, Chinese fullwidth OCR text and explicit units without rounding unknowns', () => {
    const { helpers: h } = make();
    assert.equal(h.parseMoney('1 2 ， 345 ． 67'), '12345.67');
    assert.equal(h.parseMoney('１．３４９万元'), '13490.00');
    assert.equal(h.parseMoney('12，345·67'), '12345.67');
    assert.equal(h.parseMoney('0.000001', 'wan'), '0.01');
    assert.equal(h.parseMoney('0.001', 'yuan'), null);
    assert.equal(h.parseMoney('=SUM(A1:A3)'), null);
    assert.equal(h.parseMoney(''), '');
    assert.equal(h.parseMoney('-0.01'), '-0.01');
    assert.equal(h.exactTotal([{ amount: '0.10' }, { amount: '0.20' }], 'amount'), '0.30 元');
    assert.equal(h.exactTotal([{ amount: '' }], 'amount'), '待核对');
});

test('dates require an original year, reject impossible dates and preserve monthly precision', () => {
    const { helpers: h } = make();
    assert.match(h.parseDate('7.8').error, /年份/);
    assert.equal(h.parseDate('7.8', '2026').date, '2026-07-08');
    assert.equal(h.parseDate('2026 一 09 一 29').date, '2026-09-29');
    assert.equal(h.parseDate('２０２６／０９／２９').date, '2026-09-29');
    assert.equal(h.parseDate('2026年9月').precision, 'month');
    assert.match(h.parseDate('2026-02-29').error, /无效/);
    assert.equal(h.parseDate('2024-02-29').date, '2024-02-29');
});

test('explicit cell units take priority over the column unit without multiplying yuan twice', async () => {
    const { helpers: h, state: s } = make(undefined, { project: { id: 81 } });
    assert.equal(h.parseMoney('100元', 'wan'), '100.00');
    assert.equal(h.parseMoney('100万元', 'yuan'), '1000000.00');
    s.pasted.value = '日期\t类型\t金额（万元）\n2026-09-29\t收回\t100元';
    await s.usePasted(); await s.generate();
    assert.equal(s.staged.value[0].amount, '100.00');
});

test('Excel pasted quoted cells retain embedded tabs, newlines and escaped quotes in one record', async () => {
    const { state: s } = make(undefined, { project: { id: 81 } });
    s.pasted.value = '日期\t类型\t金额\t备注\r\n2026-09-29\t收回\t"1,234.56"\t"第一行\r\n第二行\t含""引号"""\r\n2026-09-30\t投入\t200\t普通备注';
    await s.usePasted(); await s.generate();
    assert.equal(s.staged.value.length, 2);
    assert.equal(s.staged.value[0].amount, '1234.56');
    assert.equal(s.staged.value[0].note, '第一行\n第二行\t含"引号"');
    assert.equal(s.staged.value[1].row_number, 3);
    assert.equal(s.invalidCount.value, 0);
    s.pasted.value = '日期\t备注\n2026-09-29\t"未闭合';
    await s.usePasted();
    assert.match(s.error.value, /引号/);
    assert.equal(s.staged.value.length, 0);
});

test('a total label in a note or investor cell never removes a valid transaction or project', async () => {
    const { state: s } = make(undefined, { project: { id: 81 } });
    s.pasted.value = '日期\t类型\t金额\t备注\n2026-09-29\t收回\t100\t合计\n合计\t\t100\t';
    await s.usePasted(); await s.generate();
    assert.equal(s.staged.value.length, 1);
    assert.equal(s.staged.value[0].note, '合计');
    const { state: p } = make();
    p.pasted.value = '项目名称\t投资人\t累计投入\t累计净收回\t截至日\n测试酒店\t合计\t100\t50\t2026-09-29';
    await p.usePasted(); await p.generate();
    assert.equal(p.staged.value.length, 1);
    assert.equal(p.staged.value[0].investor_name, '合计');
});

test('pasted cumulative projects recognize header after title, exact ten-thousand units and ignore total row', async () => {
    const writes = [];
    const { state: s, events } = make(async (path, options) => {
        writes.push({ path, payload: JSON.parse(options.body), options });
        return { code: 200, data: { imported_count: 1, mode: 'projects', project_ids: [91] } };
    });
    s.pasted.value = '合成项目清单\n项目名称\t投资人\t累计投入（万元）\t累计净收回（万元）\t截至日\n合成酒店甲\t测试主体\t48\t49.349\t2026/09/30\n合计\t\t48\t49.349';
    await s.usePasted();
    assert.equal(s.headerRow.value, 2);
    assert.equal(s.preview.value.source_method, 'pasted_table');
    assert.match(s.preview.value.sha256, /^[a-f0-9]{64}$/);
    await s.generate();
    assert.equal(s.staged.value.length, 1);
    const row = s.staged.value[0];
    assert.equal(row.opening_invested, '480000.00');
    assert.equal(row.opening_recovered, '493490.00');
    assert.equal(row.opening_as_of, '2026-09-30');
    assert.equal(s.canConfirm.value, false);
    await s.confirm();
    assert.equal(writes.length, 0, 'recognition and preview cannot write a ledger');
    await s.checkRows?.(); s.reviewed.value = true;
    await s.confirm();
    assert.equal(writes.length, 1);
    assert.equal(writes[0].options.withBusinessContext, false);
    assert.equal(writes[0].payload.rows[0].opening_invested, '480000.00');
    assert.equal(writes[0].payload.confirmed, true);
    assert.equal(events[0][0], 'saved');
});

test('multi-investor table requires year and supports selecting investor column instead of total distribution', async () => {
    const { state: s } = make(undefined, { project: { id: 81 } });
    s.pasted.value = '日期\t高佳龙\t马佳\t分红金额\n7.8\t28245.8\t51074.8\t100000';
    await s.usePasted();
    assert.equal(s.mapping.amount, 3);
    s.mapping.amount = 1;
    await s.generate();
    assert.equal(s.staged.value[0].amount, '28245.80');
    assert.equal(s.staged.value[0].kind, '', 'unknown funds type must require a choice');
    assert.equal(s.invalidCount.value, 1);
    s.year.value = '2026'; s.defaultKind.value = 'recovery'; await s.generate();
    assert.equal(s.staged.value[0].date, '2026-07-08');
    assert.equal(s.invalidCount.value, 0);
    assert.equal(s.projectId.value, 81);
});

test('monthly entry preview displays a month, while cumulative opening balance cannot invent a day', async () => {
    const { state: s } = make(undefined, { project: { id: 81 } });
    s.pasted.value = '日期\t金额\n2026年9月\t66.01';
    await s.usePasted(); s.defaultKind.value = 'recovery'; await s.generate();
    assert.equal(s.staged.value[0].precision, 'month');
    assert.equal(s.staged.value[0].date, '2026-09');
    assert.equal(s.invalidCount.value, 0);
    const { state: p } = make();
    p.pasted.value = '项目名称\t累计投入\t累计收回\t截至日\n月度项目\t100\t50\t2026年9月';
    await p.usePasted(); await p.generate();
    assert.equal(p.invalidCount.value, 1);
});

test('failed confirmation retains edited rows, selection and same request id for safe retry', async () => {
    const writes = [];
    const { state: s, events } = make(async (path, options) => {
        const payload = JSON.parse(options.body); writes.push(payload);
        if (writes.length === 1) throw new Error('合成断网');
        return { code: 200, data: { imported_count: 1, mode: 'entries', entry_ids: [72], replayed: true } };
    }, { project: { id: 81 } });
    s.preview.value = provenance;
    s.staged.value = [{ selected: true, row_number: 2, date: '2026-09-29', precision: 'day', kind: 'recovery', amount: '0.00', note: '原表明确零收回' }, { selected: false, row_number: 3, date: '', precision: 'day', kind: '', amount: '', note: '' }];
    await s.checkRows?.(); s.reviewed.value = true;
    await s.confirm();
    assert.equal(s.busy.value, false);
    assert.equal(s.error.value, '合成断网');
    assert.equal(s.staged.value.length, 2);
    assert.equal(s.canConfirm.value, true);
    await s.confirm();
    assert.equal(writes[0].client_request_id, writes[1].client_request_id);
    assert.equal(writes[0].rows.length, 1);
    assert.equal(writes[0].rows[0].confirmed_zero, true);
    assert.equal(writes[0].project_id, 81);
    assert.equal(events[0][0], 'saved');
});

test('unconfirmed or mismatched import result cannot dismiss the preview as success', async () => {
    const { state: s, events } = make(async () => ({ code: 200, data: { imported_count: 0 } }), { project: { id: 81 } });
    s.preview.value = provenance;
    s.staged.value = [{ selected: true, row_number: 2, date: '2026-09-29', precision: 'day', kind: 'recovery', amount: '10.00', note: '' }];
    await s.checkRows?.(); s.reviewed.value = true;
    await s.confirm();
    assert.match(s.error.value, /数量未确认/);
    assert.equal(events.length, 0);
    assert.equal(s.staged.value[0].amount, '10.00');
});

test('a confirmation arriving after leaving the component cannot refresh a new page', async () => {
    let finish;
    const { state: s, events, unmount } = make(() => new Promise(resolve => { finish = resolve; }), { project: { id: 81 } });
    s.preview.value = provenance;
    s.staged.value = [{ selected: true, row_number: 2, date: '2026-09-29', precision: 'day', kind: 'recovery', amount: '10.00', note: '' }];
    await s.checkRows?.(); s.reviewed.value = true;
    const pending = s.confirm();
    unmount();
    finish({ code: 200, data: { imported_count: 1, mode: 'entries', project_ids: [81] } });
    await pending;
    assert.equal(events.length, 0);
    assert.equal(s.staged.value[0].amount, '10.00');
});

test('upload rejects oversized files and keeps unavailable OCR as visible failure', async () => {
    let calls = 0;
    const { state: s } = make(async () => { calls++; return { code: 503, message: '本机图片识别不可用' }; });
    await s.pickFile({ target: { files: [{ name: '大表.xlsx', size: 11 * 1024 * 1024 }] } });
    assert.equal(calls, 0);
    assert.match(s.error.value, /10 MB/);
    await s.pickFile({ target: { files: [{ name: '合成.png', size: 20, content: 'synthetic-test-image' }] } });
    assert.equal(calls, 1);
    assert.equal(s.error.value, '本机图片识别不可用');
    assert.equal(s.preview.value, null);
    assert.equal(s.busy.value, false);
});

test('the file picker can select the same file again after recognition fails', async () => {
    let calls = 0;
    const { state: s } = make(async () => {
        calls++;
        if (calls === 1) return { code: 503, message: '合成识别失败' };
        return { code: 200, data: { ...provenance, sheets: [{ name: 'Sheet1', rows: [['日期', '金额'], ['2026-09-29', '100']] }] } };
    });
    const input = { value: 'C:\\fakepath\\合成验收.csv', files: [{ name: '合成验收.csv', size: 30, content: 'synthetic' }] };
    await s.pickFile({ target: input });
    assert.equal(input.value, '', 'clear the picker so the browser emits change for the same path');
    assert.match(s.error.value, /识别失败/);
    await s.pickFile({ target: input });
    assert.equal(calls, 2);
    assert.equal(s.error.value, '');
    assert.equal(s.preview.value.sheets.length, 1);
});

test('editing, excluding or changing project makes the old matches and impact unusable until checked again', async () => {
    const checks = [], writes = [];
    const { state: s } = make(async (path, options) => { writes.push(JSON.parse(options.body)); return { code: 200, data: { imported_count: 1 } }; }, { project: { id: 81 }, reviewRequest: async payload => {
        checks.push(structuredClone(payload));
        const data = rowReview(payload);
        data.impact.actual_net_recovered_delta = payload.rows.filter(row => row.selected).reduce((sum, row) => sum + Number(row.amount), 0).toFixed(2);
        return { code: 200, data };
    } });
    s.preview.value = provenance;
    s.staged.value = [{ selected: true, row_number: 2, date: '2026-09-29', precision: 'day', kind: 'recovery', amount: '10.00', note: '' }, { selected: true, row_number: 3, date: '2026-09-30', precision: 'day', kind: 'recovery', amount: '5.01', note: '' }];
    await s.checkRows(); s.reviewed.value = true;
    assert.equal(s.review.value.impact.actual_net_recovered_delta, '15.01');
    assert.equal(s.canConfirm.value, true);
    s.staged.value[0].amount = '11.02';
    assert.equal(s.reviewCurrent.value, false, 'even a change without a UI handler must invalidate the preview');
    await s.confirm(); assert.equal(writes.length, 0);
    s.changed(); await s.checkRows();
    assert.equal(s.review.value.impact.actual_net_recovered_delta, '16.03');
    assert.equal(s.reviewed.value, false);
    s.staged.value[1].selected = false; s.changed(); await s.checkRows();
    assert.equal(s.review.value.impact.actual_net_recovered_delta, '11.02');
    assert.equal(checks.at(-1).rows[1].selected, false);
    s.projectId.value = 82;
    assert.equal(s.reviewCurrent.value, false);
    s.changed(); await s.checkRows();
    assert.equal(checks.at(-1).project_id, 82);
    s.staged.value[0].date = '2026-09-28'; s.changed(); await s.checkRows();
    assert.equal(checks.at(-1).rows[0].date, '2026-09-28');
    s.staged.value[0].kind = 'investment'; s.changed(); await s.checkRows();
    assert.equal(checks.at(-1).rows[0].kind, 'investment');
    s.mapping.amount = 1; s.resetRows();
    assert.equal(s.review.value, null); assert.equal(s.staged.value.length, 0); assert.equal(s.canConfirm.value, false);
});

test('exact duplicates require explicit exclusion and similar receipts require a separate human confirmation', async () => {
    const writes = [];
    const { state: s } = make(async (path, options) => { writes.push(JSON.parse(options.body)); return { code: 200, data: { imported_count: 1 } }; }, { project: { id: 81 }, reviewRequest: async payload => {
        const data = rowReview(payload), exact = data.rows.find(row => row.row_number === 2);
        exact.exact_matches = [{ id: 7, date: '2026-09-29', kind: 'recovery', amount: '10.00', note: '收款A', reason: '完全相同' }];
        data.exact_count = exact.selected ? 1 : 0; data.can_confirm = !exact.selected;
        data.similar_count = 1; data.rows[1].similar_matches = [{ id: 7, date: '2026-09-29', kind: 'recovery', amount: '10.00', note: '收款A', reason: '备注不同，另一筆待核对' }];
        return { code: 200, data };
    } });
    s.preview.value = provenance;
    s.staged.value = [{ selected: true, row_number: 2, date: '2026-09-29', precision: 'day', kind: 'recovery', amount: '10.00', note: '收款A' }, { selected: true, row_number: 3, date: '2026-09-29', precision: 'day', kind: 'recovery', amount: '10.00', note: '收款B' }];
    await s.checkRows(); s.reviewed.value = true; s.similarConfirmed.value = true;
    assert.equal(s.canConfirm.value, false); await s.confirm(); assert.equal(writes.length, 0);
    s.excludeExact(); assert.equal(s.staged.value[0].selected, false); assert.equal(s.staged.value[1].selected, true);
    await s.checkRows(); s.reviewed.value = true;
    assert.equal(s.canConfirm.value, false);
    s.similarConfirmed.value = true; await s.confirm();
    assert.equal(writes.length, 1); assert.equal(writes[0].rows.length, 1); assert.equal(writes[0].rows[0].note, '收款B');
    assert.equal(writes[0].similar_confirmed, true); assert.equal(writes[0].review_token, 'b'.repeat(64));
});

test('late review responses cannot overwrite edits and failed or incomplete reviews cannot enable confirmation', async () => {
    const pending = [];
    const { state: s, unmount } = make(undefined, { project: { id: 81 }, reviewRequest: payload => new Promise(resolve => pending.push({ payload, resolve })) });
    s.preview.value = provenance;
    s.staged.value = [{ selected: true, row_number: 2, date: '2026-09-29', precision: 'day', kind: 'recovery', amount: '10.00', note: '' }];
    const first = s.checkRows();
    s.staged.value[0].amount = '20.00'; s.changed();
    const second = s.checkRows();
    pending[1].resolve({ code: 200, data: { ...rowReview(pending[1].payload), review_token: 'c'.repeat(64) } }); await second;
    pending[0].resolve({ code: 200, data: rowReview(pending[0].payload) }); await first;
    assert.equal(s.review.value.review_token, 'c'.repeat(64));
    const failed = s.checkRows(); pending[2].resolve({ code: 503, message: '合成读取失败' }); await failed;
    assert.match(s.error.value, /合成读取失败/); assert.equal(s.reviewCurrent.value, false); assert.equal(s.canConfirm.value, false);
    const incomplete = s.checkRows(); pending[3].resolve({ code: 200, data: { review_token: 'b'.repeat(64), rows: [] } }); await incomplete;
    assert.match(s.error.value, /未完整返回/); assert.equal(s.canConfirm.value, false);
    const leaving = s.checkRows(); unmount(); pending[4].resolve({ code: 200, data: rowReview(pending[4].payload) }); await leaving;
    assert.equal(s.review.value, null);
});

test('future dates and date precision mismatches stay invalid before any actual import', async () => {
    const { state: s } = make(undefined, { project: { id: 81 } });
    s.staged.value = [{ selected: true, row_number: 2, date: '2026-10-02', precision: 'day', kind: 'recovery', amount: '10.00', note: '' }, { selected: true, row_number: 3, date: '2026-09-29', precision: 'month', kind: 'recovery', amount: '10.00', note: '' }];
    assert.equal(s.invalidCount.value, 2);
    assert.match(s.rowErrors(s.staged.value[0]).join('；'), /未来/);
    assert.match(s.rowErrors(s.staged.value[1]).join('；'), /粒度/);
});

test('a stale ledger conflict invalidates the preview and rechecking the same business rows preserves request identity', async () => {
    const writes = []; let reviewToken = 'b'.repeat(64);
    const { state: s } = make(async (path, options) => { writes.push(JSON.parse(options.body)); return { code: 409, message: '项目账目已变化，请重新检查' }; }, { project: { id: 81 }, reviewRequest: async payload => ({ code: 200, data: { ...rowReview(payload), review_token: reviewToken } }) });
    s.preview.value = provenance;
    s.staged.value = [{ selected: true, row_number: 2, date: '2026-09-29', precision: 'day', kind: 'recovery', amount: '10.00', note: '' }];
    await s.checkRows(); s.reviewed.value = true; await s.confirm();
    assert.equal(s.review.value, null); assert.equal(s.canConfirm.value, false); assert.match(s.error.value, /重新检查/);
    reviewToken = 'c'.repeat(64); await s.checkRows(); s.reviewed.value = true; await s.confirm();
    assert.equal(writes[0].client_request_id, writes[1].client_request_id);
    assert.notEqual(writes[0].review_token, writes[1].review_token);
});

test('shipped compiled bundle preserves true confirmation and zero-recovery booleans in JSON', async () => {
    const artifact = fs.readFileSync(new URL('../../public/components/system/investment-payback.min.js', import.meta.url), 'utf8');
    let payload;
    const { state: s } = make(async (path, options) => {
        payload = JSON.parse(options.body);
        assert.equal(options.withBusinessContext, false);
        return { code: 200, data: { imported_count: 1, mode: 'entries' } };
    }, { project: { id: 81 } }, artifact);
    s.preview.value = provenance;
    s.staged.value = [{ selected: true, row_number: 2, date: '2026-09-29', precision: 'day', kind: 'recovery', amount: '0.00', note: '合成零收回' }];
    await s.checkRows?.(); s.reviewed.value = true;
    await s.confirm();
    assert.equal(payload.confirmed, true);
    assert.equal(payload.rows[0].confirmed_zero, true);
});
