import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
import { webcrypto } from 'node:crypto';

const source = fs.readFileSync(new URL('../../public/components/system/investment-payback-import.js', import.meta.url), 'utf8');
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
    const state = registry.InvestmentPaybackImport.setup({ request, today: '2026-10-01', projects: [], project: null, ...props }, { emit: (...args) => events.push(args) });
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
    await s.usePasted(); s.generate();
    assert.equal(s.staged.value[0].amount, '100.00');
});

test('Excel pasted quoted cells retain embedded tabs, newlines and escaped quotes in one record', async () => {
    const { state: s } = make(undefined, { project: { id: 81 } });
    s.pasted.value = '日期\t类型\t金额\t备注\r\n2026-09-29\t收回\t"1,234.56"\t"第一行\r\n第二行\t含""引号"""\r\n2026-09-30\t投入\t200\t普通备注';
    await s.usePasted(); s.generate();
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
    await s.usePasted(); s.generate();
    assert.equal(s.staged.value.length, 1);
    assert.equal(s.staged.value[0].note, '合计');
    const { state: p } = make();
    p.pasted.value = '项目名称\t投资人\t累计投入\t累计净收回\t截至日\n测试酒店\t合计\t100\t50\t2026-09-29';
    await p.usePasted(); p.generate();
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
    s.generate();
    assert.equal(s.staged.value.length, 1);
    const row = s.staged.value[0];
    assert.equal(row.opening_invested, '480000.00');
    assert.equal(row.opening_recovered, '493490.00');
    assert.equal(row.opening_as_of, '2026-09-30');
    assert.equal(s.canConfirm.value, false);
    await s.confirm();
    assert.equal(writes.length, 0, 'recognition and preview cannot write a ledger');
    s.reviewed.value = true;
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
    s.generate();
    assert.equal(s.staged.value[0].amount, '28245.80');
    assert.equal(s.staged.value[0].kind, '', 'unknown funds type must require a choice');
    assert.equal(s.invalidCount.value, 1);
    s.year.value = '2026'; s.defaultKind.value = 'recovery'; s.generate();
    assert.equal(s.staged.value[0].date, '2026-07-08');
    assert.equal(s.invalidCount.value, 0);
    assert.equal(s.projectId.value, 81);
});

test('monthly entry preview displays a month, while cumulative opening balance cannot invent a day', async () => {
    const { state: s } = make(undefined, { project: { id: 81 } });
    s.pasted.value = '日期\t金额\n2026年9月\t66.01';
    await s.usePasted(); s.defaultKind.value = 'recovery'; s.generate();
    assert.equal(s.staged.value[0].precision, 'month');
    assert.equal(s.staged.value[0].date, '2026-09');
    assert.equal(s.invalidCount.value, 0);
    const { state: p } = make();
    p.pasted.value = '项目名称\t累计投入\t累计收回\t截至日\n月度项目\t100\t50\t2026年9月';
    await p.usePasted(); p.generate();
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
    s.reviewed.value = true;
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
    s.reviewed.value = true;
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
    s.reviewed.value = true;
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
    s.reviewed.value = true;
    await s.confirm();
    assert.equal(payload.confirmed, true);
    assert.equal(payload.rows[0].confirmed_zero, true);
});
