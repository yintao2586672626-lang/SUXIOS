import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { webcrypto } from 'node:crypto';
import * as Vue from 'vue';
import { compile } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';

// Original handlers/HTTP adapter and compiled product inputs; fetch is synthetic only.
const main = readFileSync(process.env.JUDGMENT_TEST_SOURCE || new URL('../../public/app-main.js', import.meta.url), 'utf8');
const template = readFileSync('resources/frontend/templates/fragments/16-page-ai-daily-report.html', 'utf8');
const component = readFileSync('public/components/system/app-main-components.js', 'utf8');
const extract = (source, from, to) => {
  const start = source.indexOf(from), end = source.indexOf(to, start);
  assert.ok(start >= 0 && end > start, `real source block: ${from}`);
  return source.slice(start, end);
};
const element = attribute => {
  const marker = template.indexOf(attribute);
  assert.ok(marker >= 0, `actual template attribute: ${attribute}`);
  const start = template.lastIndexOf('<', marker), tag = template.slice(start + 1).match(/^\w+/)[0];
  const end = tag === 'input' ? template.indexOf('>', marker) + 1 : template.indexOf(`</${tag}>`, marker) + tag.length + 3;
  return template.slice(start, end);
};
const marker = template.indexOf('专家/老板人工判断');
const section = template.slice(template.lastIndexOf('<section ', marker), template.indexOf('</section>', marker) + 10);
const snippets = {
  judgment: section, refresh: element('@click="loadAiDailyReport"'), generate: element('@click="generateAiDailyReport"'),
  ...Object.fromEntries(['target_type', 'decision', 'target_key', 'comment', 'correction'].map(field => [field, element(`v-model="aiDailyReportJudgmentForm.${field}"`)])),
  hotel: element('v-model="aiDailyReportForm.hotel_id"'), date: element('v-model="aiDailyReportForm.report_date"'),
};
const renders = Object.fromEntries(Object.entries(snippets).map(([key, html]) => [key, new Function('Vue', compile(html, { mode: 'function', prefixIdentifiers: true }).code)(Vue)]));
const tick = async () => { await Vue.nextTick(); await new Promise(resolve => setImmediate(resolve)); };
const report = overrides => ({ id: 101, hotel_id: 7, report_date: '2026-09-15', human_judgments: [], ...overrides });
const initialForm = () => ({ target_type: 'overall', target_key: 'signal A', decision: 'corrected', comment: 'reason A', correction: 'correction A' });
const normalized = payload => ({ ...payload,
  target_type: String(payload.target_type).trim().toLowerCase(), decision: String(payload.decision).trim().toLowerCase(),
  ...Object.fromEntries([['target_key', 120], ['comment', 1000], ['correction', 1000]].map(([key, limit]) => [key, Array.from(String(payload[key]).trim()).slice(0, limit).join('')])),
});
const judgment = (payload, overrides) => ({ ...normalized(payload), id: '0123456789abcdef', review_record_id: null,
  storage_status: 'snapshot_compatibility_migration_required', user_id: 3, user_label: 'synthetic user', recorded_at: '2026-09-15 12:00:00',
  scope: 'single_report_single_hotel', propagate_to_other_hotels: false, ...overrides });
function harness(initial = report()) {
  const requests = [], notices = [];
  const state = {
    aiDailyReport: Vue.ref(initial), aiDailyReportJudgmentForm: Vue.ref(initialForm()), aiDailyReportJudgmentSaving: Vue.ref(false),
    aiDailyReportForm: Vue.ref({ hotel_id: '7', report_date: '2026-09-15', use_llm: false }),
    operationLoading: Vue.ref({ aiDailyReport: false }), operationError: Vue.ref({ aiDailyReport: '' }), operationFilters: Vue.ref({}),
    aiDailyReportGenerationTaskPolling: Vue.ref(false), aiDailyReportGenerationTask: Vue.ref(null),
  };
  let authEpoch = 1, readyGate = null;
  const sandbox = {
    ...state, crypto: webcrypto, URLSearchParams, ref: Vue.ref, watch: Vue.watch, computed: Vue.computed, currentPage: Vue.ref('ai-daily-report'),
    pageRequestGeneration: 1, aiDailyReportTaskReturn: Vue.ref(null),
    operationYesterday: '2026-09-14',
    ensureOperationStaticReady: () => readyGate || Promise.resolve(), ensureRevenueAiStaticReady: async () => {},
    normalizeOperationHotelSelection: form => Number(form.value.hotel_id), loadAiDailyFactGate: async () => {}, loadOperationActions: async () => {},
    showToast: (message, type) => notices.push({ message, type }), operationErrorMessage: (error, fallback) => error.message || fallback,
    API_BASE: 'https://synthetic.invalid/api', console: { error() {} }, readRequestCooldown: { check: () => null, record() {} },
    captureAuthSession: () => ({ epoch: authEpoch }), isAuthSessionCurrent: session => session.epoch === authEpoch,
    isTerminalAuthFailureResponse: () => false,
    fetch: (url, options) => new Promise((resolve, reject) => requests.push({ url, options, resolve, reject })),
  };
  const adapter = extract(main, 'const executeApiRequest = async (', '// API 请求');
  vm.runInNewContext(`let aiDailyReportGenerationRequestSeq = 0;
    ${extract(component, '// AI_DAILY_REPORT_TASK_HELPERS_START', '// AI_DAILY_REPORT_TASK_HELPERS_END')}
    ${adapter}
    const apiRequest = (url, options) => executeApiRequest({requestSession:captureAuthSession(),requestUrl:url,requestOptions:options || {},headers:{}});
    ${extract(main, 'let aiDailyReportRequestSeq = 0;', 'const operationExecutionHotelId =')}
    ${extract(main, 'const aiDailyReportSendScopeCurrent = () =>', 'const wecomContext =')}
    ${extract(main, main.includes('let aiDailyReportJudgmentAttempt =') ? 'let aiDailyReportJudgmentAttempt =' : 'const submitAiDailyReportJudgment = async () => {', 'const aiDailyReportGapActionText =')}
    globalThis.handlers = { submitAiDailyReportJudgment, aiDailyReportJudgmentDisabled, aiDailyReportSendScopeCurrent, loadAiDailyReport, generateAiDailyReport };`, sandbox);
  const view = Vue.proxyRefs({ ...state, ...sandbox.handlers,
    aiDailyReportHumanJudgments: Vue.computed(() => state.aiDailyReport.value?.human_judgments || []),
    aiDailyReportJudgmentTargetText: value => value, aiDailyReportJudgmentDecisionText: value => value,
    operationHotelOptions: [{ id: '7', name: 'synthetic A' }, { id: '8', name: 'synthetic B' }], loadAiDailyFactGate: sandbox.loadAiDailyFactGate,
    aiDailyCompetitionInputsReady: true, aiDailyCompetitionInputStatusText: 'synthetic ready', aiDailyReportGenerationRunning: false,
  });
  const inspect = async (name = 'judgment') => {
    let tree;
    const html = await renderToString(Vue.createSSRApp({ render() { tree = renders[name](view, []); return tree; } }));
    const nodes = [];
    const walk = node => { if (Array.isArray(node)) return node.forEach(walk); if (!node || typeof node !== 'object') return; nodes.push(node); walk(node.children); };
    walk(tree);
    return { html, nodes };
  };
  return { ...state, requests, notices, inspect, submit: sandbox.handlers.submitAiDailyReportJudgment,
    changeAuth: () => { authEpoch++; },
    holdStaticReady: () => { let release; readyGate = new Promise(resolve => { release = resolve; }); return () => { readyGate = null; release(); }; },
  };
}
async function edit(p, field, value) {
  const node = (await p.inspect(field)).nodes.find(node => ['input', 'textarea', 'select'].includes(node.type));
  assert.ok(node && !node.props?.disabled && !node.props?.readonly, `${field} is a visible editable product input`);
  node.props['onUpdate:modelValue'](value);
  if (node.props.onChange) node.props.onChange();
  await tick();
}
async function click(p, kind = 'judgment') {
  const node = (await p.inspect(kind)).nodes.find(node => node.type === 'button');
  assert.ok(node && !node.props.disabled, `${kind} is a reachable enabled button`);
  const done = node.props.onClick();
  await tick();
  return { done };
}
const respond = (request, data, status = 200, code = status) => request.resolve(new Response(JSON.stringify({ code, data, message: status === 200 ? '' : 'synthetic rejection' }), { status }));
const latest = reportRow => ({ data_status: 'ok', report: reportRow, data_gaps: [] });
const posted = p => p.requests.find(item => item.options.method === 'POST');
const sent = p => JSON.parse(posted(p).options.body);
const saved = (p, overrides = {}) => report({ human_judgments: [...(p.aiDailyReport.value?.human_judgments || []), judgment(sent(p))], ...overrides });

for (const refreshed of [false, true]) test(`lost judgment response: explicit retry reuses the original write after refresh=${refreshed}`, async () => {
  const p = harness(), rows = [];
  const persist = request => {
    const body = JSON.parse(request.options.body);
    if (!body.request_id || !rows.some(row => row.request_id === body.request_id)) {
      rows.push(judgment(body, { id: 'review-' + (rows.length + 1), review_record_id: rows.length + 1 }));
    }
    return report({ human_judgments: [...rows] });
  };
  const first = await click(p);
  const persisted = persist(p.requests[0]);
  p.requests[0].reject(new Error('synthetic response lost after write'));
  await first.done;
  assert.equal(p.aiDailyReportJudgmentForm.value.comment, 'reason A');
  assert.equal(p.requests.length, 1, 'no automatic POST retry');
  if (refreshed) {
    const refresh = await click(p, 'refresh');
    respond(p.requests.at(-1), latest(persisted));
    await refresh.done;
    assert.equal(p.aiDailyReport.value.human_judgments.length, 1);
  }
  const retry = await click(p);
  const retried = p.requests.at(-1);
  respond(retried, persist(retried));
  await retry.done;
  assert.equal(rows.length, 1, 'same uncertain submission must not append a second opinion');
  assert.match(JSON.parse(retried.options.body).request_id, /^[a-f0-9-]{36}$/);
  assert.equal(p.aiDailyReportJudgmentForm.value.comment, '');
  assert.equal(p.notices.at(-1).type, 'success');
});

test('a deliberate new judgment after confirmed success gets a fresh identity even for identical text', async () => {
  const p = harness();
  const first = await click(p);
  const firstBody = JSON.parse(p.requests[0].options.body);
  respond(p.requests[0], report({ human_judgments: [judgment(firstBody, { id: 'review-1', review_record_id: 1 })] }));
  await first.done;
  p.aiDailyReportJudgmentForm.value = initialForm();
  const second = await click(p);
  const secondBody = JSON.parse(p.requests[1].options.body);
  respond(p.requests[1], report({ human_judgments: [judgment(firstBody, { id: 'review-1', review_record_id: 1 }), judgment(secondBody, { id: 'review-2', review_record_id: 2 })] }));
  await second.done;
  assert.ok(firstBody.request_id);
  assert.notEqual(firstBody.request_id, secondBody.request_id);
  assert.equal(p.aiDailyReport.value.human_judgments.length, 2);
  assert.equal(p.notices.at(-1).type, 'success');
});

for (const change of ['draft', 'report', 'session']) test(`uncertain judgment identity is not reused for changed ${change}`, async () => {
  const p = harness();
  const first = await click(p);
  const firstBody = JSON.parse(p.requests[0].options.body);
  p.requests[0].reject(new Error('synthetic uncertain write'));
  await first.done;
  if (change === 'draft') p.aiDailyReportJudgmentForm.value.comment = 'revised opinion';
  if (change === 'report') p.aiDailyReport.value = report({ id: 102 });
  if (change === 'session') p.changeAuth();
  const second = await click(p);
  const request = p.requests[1], body = JSON.parse(request.options.body);
  respond(request, { ...p.aiDailyReport.value, human_judgments: [judgment(body)] });
  await second.done;
  assert.ok(firstBody.request_id);
  assert.notEqual(firstBody.request_id, body.request_id);
  assert.equal(p.notices.at(-1).type, 'success');
});

test('a different submission receipt with identical opinion content cannot confirm this write', async () => {
  const p = harness();
  const post = await click(p);
  respond(posted(p), saved(p, { human_judgments: [judgment(sent(p), { request_id: webcrypto.randomUUID() })] }));
  await post.done;
  assert.equal(p.aiDailyReportJudgmentForm.value.comment, 'reason A');
  assert.equal(p.notices.at(-1).type, 'error');
  assert.match(p.notices.at(-1).message, /回读尚未核实/);
});

for (const [field, other, original] of [['hotel', '8', '7'], ['date', '2026-09-14', '2026-09-15']]) {
  test(`changed ${field} before judgment submission blocks the old report and preserves the draft`, async () => {
    const p = harness(), before = JSON.stringify(p.aiDailyReport.value);
    await edit(p, field, other);
    const pending = p.submit();
    await tick();
    assert.equal(p.requests.length, 0, 'a mismatched visible report must not receive a write');
    await pending;
    assert.equal(JSON.stringify(p.aiDailyReport.value), before);
    assert.deepEqual({ ...p.aiDailyReportJudgmentForm.value }, initialForm());
    assert.equal(p.aiDailyReportJudgmentSaving.value, false);
    assert.match(p.notices[0]?.message || '', /历史.*日报/);
    assert.equal(p.notices[0]?.type, 'warning');
  });

  test(`changed ${field} disables the visible save button; matching the report restores exact save`, async () => {
    const p = harness();
    await edit(p, field, other);
    const blocked = await p.inspect();
    assert.equal(blocked.nodes.find(node => node.type === 'button').props.disabled, true);
    assert.match(blocked.html, /核对酒店和营业日/);
    await edit(p, field, original);
    const post = await click(p);
    respond(posted(p), saved(p));
    await post.done;
    assert.equal(p.aiDailyReport.value.human_judgments.length, 1);
    assert.equal(p.aiDailyReportJudgmentForm.value.comment, '');
    assert.equal(p.notices.filter(item => item.type === 'success').length, 1);
  });
}

test('judgment history exposes every returned saved opinion in newest-first order', async () => {
  const rows = Array.from({ length: 7 }, (_, index) => judgment(initialForm(), {
    id: `saved-${index}`, comment: `历史理由-${index}`, correction: '', target_key: `信号-${index}`,
  }));
  const p = harness(report({ human_judgments: rows }));
  const { html } = await p.inspect();
  for (let index = 0; index < rows.length; index++) assert.ok(html.includes(`历史理由-${index}`));
  assert.ok(html.indexOf('历史理由-6') < html.indexOf('历史理由-0'));
  assert.deepEqual(p.aiDailyReport.value.human_judgments.map(row => row.id), rows.map(row => row.id));
});

test('judgment history shows both saved reason and correction without losing either field', async () => {
  const p = harness(report({ human_judgments: [judgment(initialForm(), {
    comment: '携程曝光口径需要单独判断', correction: '修正为渠道范围结论',
  })] }));
  const { html } = await p.inspect();
  assert.ok(html.includes('携程曝光口径需要单独判断'));
  assert.ok(html.includes('修正为渠道范围结论'));
});

test('judgment history retains single-field legacy opinions and escapes saved text', async () => {
  const p = harness(report({ human_judgments: [
    judgment(initialForm(), { id: 'legacy-reason', comment: '<img src=x onerror=alert(1)>原理由', correction: undefined }),
    judgment(initialForm(), { id: 'legacy-correction', comment: undefined, correction: '仅有修正的旧记录' }),
  ] }));
  const { html } = await p.inspect();
  assert.ok(html.includes('&lt;img src=x onerror=alert(1)&gt;原理由'));
  assert.ok(html.includes('仅有修正的旧记录'));
  assert.ok(!html.includes('<img src=x'));
});
const noSuccess = p => assert.equal(p.notices.some(item => item.type === 'success'), false);

for (const storage of ['snapshot', 'append_only', 'legacy_snapshot']) test(`unchanged draft: exact ${storage} save clears submitted text and renders its new judgment`, async () => {
  const p = harness(report({ evidence_readback_status: 'legacy_unverified' }));
  const { done } = await click(p);
  assert.equal((await p.inspect()).nodes.find(node => node.type === 'button').props.disabled, true);
  const record = judgment(sent(p), storage === 'append_only' ? { id: 'review-51', review_record_id: 51, storage_status: 'append_only_persisted' }
    : storage === 'legacy_snapshot' ? { id: '  historical opinion #A  ' } : {});
  respond(posted(p), report({ evidence_readback_status: 'legacy_unverified', human_judgments: [record] }));
  await done;
  assert.equal(p.aiDailyReportJudgmentForm.value.comment, '');
  assert.equal(p.aiDailyReportJudgmentForm.value.correction, '');
  assert.equal(p.aiDailyReportJudgmentForm.value.target_key, 'signal A');
  assert.ok((await p.inspect()).html.includes('correction A'));
  assert.equal(p.notices.at(-1).type, 'success');
  assert.equal(p.aiDailyReportJudgmentSaving.value, false);
  assert.equal(p.requests.length, 1);
});

for (const [name, identity] of Object.entries({
  review_boolean: { id: '', review_record_id: true },
  review_array: { id: '', review_record_id: [51] },
  review_mixed_numeric_text: { id: '', review_record_id: '1e2' },
  review_unsafe_integer: { id: '', review_record_id: 9007199254740992 },
  snapshot_object: { id: {}, review_record_id: null },
  snapshot_array: { id: ['synthetic judgment'], review_record_id: null },
  snapshot_boolean: { id: true, review_record_id: null },
  snapshot_number: { id: 51, review_record_id: null },
})) test(`malformed identity ${name} cannot verify a saved judgment`, async () => {
  const p = harness(), before = JSON.stringify(p.aiDailyReport.value), { done } = await click(p);
  respond(posted(p), report({ human_judgments: [judgment(sent(p), identity)] }));
  await done;
  assert.equal(JSON.stringify(p.aiDailyReport.value), before);
  assert.deepEqual({ ...p.aiDailyReportJudgmentForm.value }, initialForm());
  noSuccess(p);
  assert.equal(p.notices.at(-1).type, 'error');
  assert.equal(p.requests.length, 1);
});

for (const [field, value] of Object.entries({ target_type: 'anomaly_signal', target_key: 'signal B', decision: 'needs_more_evidence', comment: 'reason B', correction: 'correction B' })) test(`editing ${field} while saving keeps the entire new draft and marks it unsaved`, async () => {
  const p = harness(), { done } = await click(p);
  await edit(p, field, value);
  const draft = { ...p.aiDailyReportJudgmentForm.value };
  respond(posted(p), saved(p));
  await done;
  assert.deepEqual({ ...p.aiDailyReportJudgmentForm.value }, draft);
  assert.equal(p.aiDailyReport.value.human_judgments[0].comment, 'reason A');
  assert.match(p.notices.at(-1).message, /新.*未保存|新.*尚未保存/);
  assert.equal(p.requests.length, 1, 'B is never automatically submitted');
});

test('exact receipt compares PHP code-point truncation and trimming', async () => {
  const p = harness();
  // Compatibility input beyond HTML maxlength; no actual user entry claim for this case.
  p.aiDailyReportJudgmentForm.value = { target_type: ' OVERALL ', target_key: ' ' + '😀'.repeat(125) + ' ', decision: ' CORRECTED ', comment: ' ' + '😀'.repeat(1005) + ' ', correction: ' fixed ' };
  const { done } = await click(p);
  respond(posted(p), saved(p));
  await done;
  assert.equal(p.aiDailyReport.value.human_judgments[0].comment, '😀'.repeat(1000));
  assert.equal(p.aiDailyReportJudgmentForm.value.comment, '');
  assert.equal(p.notices.at(-1).type, 'success');
});

test('a genuinely new review of the same content is confirmed by its new review ID', async () => {
  const old = judgment(initialForm(), { id: 'review-9', review_record_id: 9 });
  const p = harness(report({ human_judgments: [old] })), { done } = await click(p);
  respond(posted(p), report({ human_judgments: [old, judgment(sent(p), { id: 'review-10', review_record_id: '10' })] }));
  await done;
  assert.equal(p.aiDailyReport.value.human_judgments.length, 2);
  assert.equal(p.aiDailyReportJudgmentForm.value.comment, '');
  assert.equal(p.notices.at(-1).type, 'success');
});

for (const invalid of ['null', 'empty', 'read_failed', 'wrong_id', 'wrong_hotel', 'wrong_date', 'missing_date', 'missing_judgments', 'old_only', 'same_review_new_id', 'missing_identity', 'wrong_text']) test(`unverified receipt ${invalid} preserves draft and report without claiming saved`, async () => {
  const old = judgment(initialForm(), { id: 'review-9', review_record_id: 9, storage_status: 'append_only_persisted' });
  const p = harness(report({ human_judgments: [old] })), before = JSON.stringify(p.aiDailyReport.value);
  const { done } = await click(p);
  const reply = {
    null: null, empty: {}, read_failed: saved(p, { data_status: 'read_failed' }), wrong_id: saved(p, { id: 102 }),
    wrong_hotel: saved(p, { hotel_id: 8 }), wrong_date: saved(p, { report_date: '2026-09-14' }), missing_date: saved(p, { report_date: '' }),
    missing_judgments: saved(p, { human_judgments: undefined }), old_only: report({ human_judgments: [old] }),
    same_review_new_id: report({ human_judgments: [{ ...old, id: 'new-looking-id' }] }),
    missing_identity: report({ human_judgments: [judgment(sent(p), { id: '', review_record_id: null })] }),
    wrong_text: report({ human_judgments: [judgment(sent(p), { correction: 'another saved opinion' })] }),
  }[invalid];
  respond(posted(p), reply); await done;
  assert.equal(JSON.stringify(p.aiDailyReport.value), before);
  assert.deepEqual({ ...p.aiDailyReportJudgmentForm.value }, initialForm());
  noSuccess(p);
  assert.equal(p.notices.at(-1).type, 'error');
  assert.equal(p.requests.length, 1, 'non-idempotent POST is not replayed');
});

test('write succeeded but readback failed: show server recovery guidance without retrying the write', async () => {
  const p = harness(), { done } = await click(p);
  const message = '人工判断可能已保存，先刷新核对再决定是否重试。本次日报读取或证据校验未通过。';
  posted(p).resolve(new Response(JSON.stringify({ code: 503, message, data: { data_status: 'read_failed', report_id: 101 } }), { status: 503 }));
  await done;
  assert.equal(p.requests.length, 1, 'uncertain write must not be retried automatically');
  assert.equal(p.notices[0]?.message, message);
  assert.equal(p.notices[0]?.type, 'error');
  assert.deepEqual({ ...p.aiDailyReportJudgmentForm.value }, initialForm());
  assert.equal(p.aiDailyReport.value.human_judgments.length, 0);
  assert.equal(p.aiDailyReportJudgmentSaving.value, false);
});

for (const failure of [422, 500, 'transport']) test(`save failure ${failure} leaves current editable draft`, async () => {
  const p = harness(), { done } = await click(p);
  await edit(p, 'comment', 'reason B');
  if (failure === 'transport') posted(p).reject(new Error('synthetic transport failure')); else respond(posted(p), null, failure);
  await done;
  assert.equal(p.aiDailyReportJudgmentForm.value.comment, 'reason B');
  assert.equal(p.aiDailyReportJudgmentForm.value.correction, 'correction A');
  assert.equal(p.aiDailyReportJudgmentSaving.value, false);
  assert.equal((await p.inspect()).nodes.find(node => node.type === 'button').props.disabled, false);
  noSuccess(p);
});

for (const start of ['GET', 'POST']) for (const finish of ['GET', 'POST']) test(`same-report refresh ${start} starts first / ${finish} finishes first retains confirmed judgment`, async () => {
  const p = harness();
  let getDone, postDone;
  if (start === 'GET') { getDone = (await click(p, 'refresh')).done; postDone = (await click(p)).done; }
  else { postDone = (await click(p)).done; getDone = (await click(p, 'refresh')).done; }
  const get = p.requests.find(item => item.options.method !== 'POST'), receipt = saved(p);
  if (finish === 'GET') { respond(get, { report: report() }); await getDone; respond(posted(p), receipt); await postDone; }
  else { respond(posted(p), receipt); await postDone; respond(get, { report: report() }); await getDone; }
  assert.deepEqual(JSON.parse(JSON.stringify(p.aiDailyReport.value)), receipt);
  assert.equal(p.aiDailyReportJudgmentForm.value.comment, '');
  assert.equal(p.operationLoading.value.aiDailyReport, false);
  assert.equal(p.notices.filter(item => item.type === 'success').length, 1);
  assert.equal(p.requests.length, 2);
});

for (const finish of ['GET', 'POST']) test(`same-scope failed GET ${finish} finishes first cannot contradict confirmed save`, async () => {
  const p = harness(), post = await click(p), get = await click(p, 'refresh');
  const request = p.requests.find(item => item.options.method !== 'POST');
  if (finish === 'GET') { respond(request, null, 500); await get.done; respond(posted(p), saved(p)); await post.done; }
  else { respond(posted(p), saved(p)); await post.done; respond(request, null, 500); await get.done; }
  assert.equal(p.operationError.value.aiDailyReport, '');
  assert.equal(p.aiDailyReport.value.human_judgments.length, 1);
  assert.equal(p.aiDailyReportJudgmentForm.value.comment, '');
  assert.equal(p.operationLoading.value.aiDailyReport, false);
});

test('a fresh refresh after successful save remains usable', async () => {
  const p = harness(), post = await click(p), receipt = saved(p);
  respond(posted(p), receipt); await post.done;
  const get = await click(p, 'refresh');
  respond(p.requests.find(item => item.options.method !== 'POST'), latest({ ...receipt, title: 'fresh readback' }));
  await get.done;
  assert.equal(p.aiDailyReport.value.title, 'fresh readback');
  assert.equal(p.aiDailyReport.value.human_judgments.length, 1);
  assert.equal(p.operationLoading.value.aiDailyReport, false);
});

for (const scope of ['hotel', 'date', 'report_id', 'report_date', 'auth']) for (const outcome of ['success', 'failure']) test(`changed ${scope} ignores late ${outcome} without overwriting current report or draft`, async () => {
  const p = harness(), { done } = await click(p), receipt = saved(p);
  await edit(p, 'comment', 'new draft B');
  if (scope === 'hotel' || scope === 'date') await edit(p, scope, scope === 'hotel' ? '8' : '2026-09-14');
  if (scope === 'auth') p.changeAuth(); // Session guard unit boundary, no actual login/account storage.
  else if (scope !== 'date') {
    if (scope === 'report_date') await edit(p, 'date', '2026-09-14');
    const loading = await click(p, 'refresh');
    const newReport = report(scope === 'hotel' ? { id: 202, hotel_id: 8 } : scope === 'report_id' ? { id: 102 } : { report_date: '2026-09-14' });
    respond(p.requests.find(item => item.options.method !== 'POST'), latest(newReport)); await loading.done;
  }
  const before = JSON.stringify(p.aiDailyReport.value);
  if (outcome === 'success') respond(posted(p), receipt); else respond(posted(p), null, 500);
  await done;
  assert.equal(JSON.stringify(p.aiDailyReport.value), before);
  assert.equal(p.aiDailyReportJudgmentForm.value.comment, 'new draft B');
  assert.equal(p.notices.length, 0);
  assert.equal(p.aiDailyReportJudgmentSaving.value, false);
});

test('a reachable new generation owns loading even before its awaited setup clears the old report', async () => {
  const p = harness(), { done } = await click(p), receipt = saved(p);
  const release = p.holdStaticReady(), generation = await click(p, 'generate');
  assert.equal(p.operationLoading.value.aiDailyReport, true);
  assert.equal(p.aiDailyReport.value.id, 101, 'old report remains during actual generation setup await');
  respond(posted(p), receipt); await done;
  assert.equal(p.operationLoading.value.aiDailyReport, true, 'judgment completion cannot clear generation loading');
  assert.equal(p.aiDailyReport.value.human_judgments.length, 0);
  assert.equal(p.aiDailyReportJudgmentForm.value.comment, 'reason A');
  assert.equal(p.notices.length, 0);
  release(); await tick();
  const request = p.requests.find(item => !item.url.includes('human-judgments'));
  assert.ok(request && request.options.method === 'POST');
  respond(request, null, 422); await generation.done;
  assert.equal(p.operationLoading.value.aiDailyReport, false);
});
