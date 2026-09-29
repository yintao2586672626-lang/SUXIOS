import assert from 'node:assert/strict';
import test from 'node:test';
import * as Vue from 'vue';
import { parse, compile } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';
import { harness, raw, clone, tick } from './helpers/knowledge_import_ui_harness.mjs';

// Original row/header controls, domain and request/coordinator. All list DTOs
// below are synthetic public unitList subsets, never a query or business write.
const unit = (name = 'Synthetic saved knowledge', id = 501) => ({ unit_id: id, hotel_id: 80,
  name, source: 'document', status: 'done', description: 'Synthetic reference only', tags: ['synthetic'], chunk_count: 1 });
const pagination = { total: 12, page: 2, page_size: 10, total_page: 2 };
const body = (list = [unit()], paging = pagination) => ({ code: 0, msg: '', data: { list: clone(list), pagination: clone(paging) } });
const walk = (node, found = []) => { found.push(node); (node.children || []).forEach(child => walk(child, found)); return found; };
const templateNodes = walk(parse(raw.page));
const emptyNode = templateNodes.find(node => node.type === 1 && node.tag === 'div' && node.props.some(prop => prop.name === 'if' && prop.exp?.content === '!knowledgeCenterLoading && !knowledgeCenterListError && knowledgeCenterUnits.length === 0'));
const errorNode = templateNodes.find(node => node.type === 1 && node.props.some(prop => prop.name === 'data-testid' && prop.value?.content === 'knowledge-center-list-error'));
const paginationNode = templateNodes.find(node => node.type === 1 && node.tag === 'div' && node.props.some(prop => prop.name === 'class' && prop.value?.content === 'flex items-center justify-between bg-white rounded-xl shadow-sm border border-gray-100 p-4'));
assert.ok(emptyNode && paginationNode && errorNode);
const emptyRender = new Function('Vue', compile('<section>' + errorNode.loc.source + emptyNode.loc.source + paginationNode.loc.source + '</section>', { mode: 'function', prefixIdentifiers: true }).code)(Vue);
const filteredStart = raw.main.indexOf('            const knowledgeCenterIsFiltered = computed(() => {');
const filteredEnd = raw.main.indexOf('\n            });', filteredStart) + '\n            });'.length;
assert.ok(filteredStart >= 0 && filteredEnd > filteredStart);
const filteredFactory = new Function('computed', 'knowledgeCenterFilter', raw.main.slice(filteredStart, filteredEnd) + '\nreturn knowledgeCenterIsFiltered;');
const emptyHtml = p => renderToString(Vue.createSSRApp({ setup: () => ({ ...p.state, ...p.methods,
  knowledgeCenterIsFiltered: filteredFactory(Vue.computed, p.state.knowledgeCenterFilter) }), render: emptyRender }));
const snapshot = p => JSON.stringify({ rows: p.state.knowledgeCenterUnits.value, pagination: p.state.knowledgeCenterPagination.value, selected: p.state.selectedKnowledgeCenterUnitIds.value });

async function clickRefresh(p, entry = 'row') {
  await p.html();
  const button = entry === 'header' ? p.findButton('刷新') : p.nodes().find(node => node.type === 'button' && node.props?.title === '刷新');
  assert.ok(button && !button.props?.disabled, 'Original visible refresh control is enabled');
  assert.equal(p.state.showKnowledgeCenterImportModal.value, false, 'No modal covers the original list control');
  const pending = button.props.onClick(); await tick();
  const req = p.pending('list'); assert.ok(req);
  assert.equal(req.options.method || 'GET', 'GET');
  assert.equal(new URL(req.url).searchParams.has('hotel_id'), false, 'Preserve authorized aggregate list behavior');
  return { pending, req };
}
async function prepared() {
  const p = harness(); const load = await clickRefresh(p, 'header');
  p.reply(load.req, body()); await load.pending; await tick();
  assert.equal(p.state.knowledgeCenterUnits.value[0].unit_id, 501);
  // This setup represents an existing selected checkbox. Selection interaction
  // is outside this test; preservation on refresh is the oracle.
  p.state.selectedKnowledgeCenterUnitIds.value = [501];
  p.notices.length = 0;
  return p;
}
async function complete(p, clicked, response, status = 200) {
  p.reply(clicked.req, response, status); const result = await clicked.pending; await tick();
  assert.equal(p.state.knowledgeCenterLoading.value, false);
  assert.ok(p.requests.every(req => (req.options.method || 'GET') === 'GET'));
  return result;
}
function assertFailed(p, before, result) {
  assert.equal(snapshot(p), before, 'Failed or missing list data must preserve rows, pagination and selection');
  assert.equal(result, false, 'Refresh exposes an explicit failed completion');
  assert.equal(p.notices.length, 1);
  assert.equal(p.notices[0].type, 'error');
  assert.ok(!p.notices.some(notice => notice.message === '已刷新'));
  assert.ok(p.state.knowledgeCenterListError.value, 'A preserved list remains explicitly failed for this refresh');
}

test('initial list failure renders its real alert without empty success or a fabricated zero pager', async () => {
  const p = harness(), click = await clickRefresh(p, 'header');
  assert.equal(await complete(p, click, { code: 500, msg: 'Synthetic initial read failure', data: null }, 500), false);
  const html = await emptyHtml(p);
  assert.ok(html.includes('knowledge-center-list-error') && html.includes('Synthetic initial read failure'));
  assert.ok(!html.includes('暂无门店知识') && !html.includes('共 0 条'));
  assert.deepEqual(clone(p.state.knowledgeCenterUnits.value), []);
});

for (const status of [200, 500]) test(`prior-session list ${status} completion cannot replace a new session's state or notify`, async () => {
  const p = await prepared(), click = await clickRefresh(p, 'header');
  p.sandbox.authSessionEpoch++;
  p.state.knowledgeCenterUnits.value = [unit('Synthetic new session row', 502)];
  p.state.selectedKnowledgeCenterUnitIds.value = [502];
  const before = snapshot(p);
  p.reply(click.req, status === 200 ? body([unit('Synthetic stale session row')]) : { code: 500, msg: 'Synthetic prior session failure', data: null }, status);
  assert.equal(await click.pending, false); await tick();
  assert.equal(snapshot(p), before, 'An obsolete request cannot replace the current session list or selection');
  assert.deepEqual(p.notices, []);
  assert.equal(p.state.knowledgeCenterListError.value, '', 'An obsolete failure cannot label the current session read as failed');
});

for (const [name, filter, options] of [
  ['keyword', { keyword: 'NEW SCOPE' }, {}],
  ['platform', { platform: 'meituan' }, {}],
  ['hotel', {}, { hotelId: '81' }],
]) test(`changed ${name} failure cannot retain the prior scope rows, selection or visible pager`, async () => {
  const p = await prepared(), priorPagination = clone(p.state.knowledgeCenterPagination.value);
  p.state.knowledgeCenterFilter.value = filter;
  const pending = p.methods.loadKnowledgeCenter(options); await tick();
  const req = p.pending('list'); assert.ok(req);
  assert.deepEqual(clone(p.state.knowledgeCenterUnits.value), [], 'Prior-scope rows are removed when the new query starts');
  assert.deepEqual(clone(p.state.selectedKnowledgeCenterUnitIds.value), []);
  p.reply(req, { code: 500, msg: 'Synthetic changed scope failure', data: null }, 500);
  assert.equal(await pending, false); await tick();
  assert.deepEqual(clone(p.state.knowledgeCenterPagination.value), priorPagination, 'Failure cannot invent confirmed pagination');
  const html = await emptyHtml(p);
  assert.ok(html.includes('Synthetic changed scope failure'));
  assert.ok(!html.includes('共 12 条') && !html.includes('暂无门店知识') && !html.includes('没有匹配的知识'));
  assert.deepEqual(clone(p.state.knowledgeCenterUnits.value), []);
});

test('actual header refresh applies a complete list and returns true without a row toast', async () => {
  const p = await prepared(), click = await clickRefresh(p, 'header');
  const result = await complete(p, click, body([unit('Synthetic refreshed header')]));
  assert.equal(p.state.knowledgeCenterUnits.value[0].name, 'Synthetic refreshed header');
  assert.deepEqual(clone(p.state.selectedKnowledgeCenterUnitIds.value), [501]);
  assert.equal(result, true); assert.deepEqual(p.notices, []);
  assert.ok((await p.html()).includes('Synthetic refreshed header'));
});

test('actual row refresh reports success only after applying the complete returned list', async () => {
  const p = await prepared(), click = await clickRefresh(p);
  const result = await complete(p, click, body([unit('Synthetic refreshed row')]));
  assert.equal(result, true);
  assert.equal(p.state.knowledgeCenterUnits.value[0].name, 'Synthetic refreshed row');
  assert.deepEqual(p.notices, [{ message: '已刷新', type: 'success' }]);
  assert.ok((await p.html()).includes('Synthetic refreshed row'));
});

for (const entry of ['header', 'row']) test(`actual ${entry} HTTP500 preserves the last list and never reports refresh success`, async () => {
  const p = await prepared(), before = snapshot(p), click = await clickRefresh(p, entry);
  const result = await complete(p, click, { code: 500, msg: 'Synthetic list read failed', data: null }, 500);
  assertFailed(p, before, result);
  assert.ok((await p.html()).includes('Synthetic saved knowledge'));
  const html = await emptyHtml(p);
  assert.ok(html.includes('Synthetic list read failed') && html.includes('上方保留本范围内上次确认的结果'));
  assert.ok(html.includes('共 12 条'), 'Same-scope failure keeps the confirmed pager alongside the alert');
});

test('body-level list failure also returns false and keeps the prior list', async () => {
  const p = await prepared(), before = snapshot(p), click = await clickRefresh(p);
  const result = await complete(p, click, { code: 422, msg: 'Synthetic list validation failed', data: null });
  assertFailed(p, before, result);
});

const malformed = [
  ['missing data', { code: 0, msg: '' }],
  ['missing list', { code: 0, data: { pagination } }],
  ['non-array list', { code: 0, data: { list: {}, pagination } }],
  ['missing pagination', { code: 0, data: { list: [unit('Must not apply')] } }],
  ['null row', { code: 0, data: { list: [null], pagination } }],
  ['missing total', { code: 0, data: { list: [], pagination: { page: 1, page_size: 10, total_page: 0 } } }],
  ['boolean total', { code: 0, data: { list: [], pagination: { total: false, page: 1, page_size: 10, total_page: 0 } } }],
  ['invalid page size', { code: 0, data: { list: [], pagination: { total: 0, page: 1, page_size: 'not-a-number', total_page: 0 } } }],
];
for (const [name, receipt] of malformed) test(`malformed successful ${name} preserves all last loaded state`, async () => {
  const p = await prepared(), before = snapshot(p), click = await clickRefresh(p);
  const result = await complete(p, click, receipt); assertFailed(p, before, result);
  const html = await emptyHtml(p);
  assert.ok(!html.includes('暂无门店知识'), 'Missing data must not render an empty success');
  assert.ok(html.includes('共 12 条'));
});

test('a valid empty list preserves the real zero total and zero total_page', async () => {
  const p = await prepared(), click = await clickRefresh(p);
  const result = await complete(p, click, body([], { total: 0, page: 1, page_size: 10, total_page: 0 }));
  assert.equal(result, true);
  assert.deepEqual(clone(p.state.knowledgeCenterUnits.value), []);
  assert.deepEqual(clone(p.state.selectedKnowledgeCenterUnitIds.value), []);
  assert.equal(p.state.knowledgeCenterPagination.value.total, 0);
  assert.equal(p.state.knowledgeCenterPagination.value.total_page, 0);
  assert.deepEqual(p.notices, [{ message: '已刷新', type: 'success' }]);
  const html = await emptyHtml(p); assert.ok(html.includes('暂无门店知识')); assert.ok(html.includes('共 0 条'));
});

test('numeric strings and non-atomic count/select differences remain legitimate', async () => {
  const p = await prepared(), click = await clickRefresh(p);
  const row = unit('Synthetic concurrent insert', '502');
  const result = await complete(p, click, body([row], { total: '0', page: '3', page_size: '10', total_page: '0' }));
  assert.equal(result, true);
  assert.equal(p.state.knowledgeCenterUnits.value[0].name, 'Synthetic concurrent insert');
  assert.deepEqual(clone(p.state.knowledgeCenterPagination.value), { total: 0, page: 3, page_size: 10, total_page: 0 });
  assert.deepEqual(clone(p.state.selectedKnowledgeCenterUnitIds.value), []);
  assert.deepEqual(p.notices, [{ message: '已刷新', type: 'success' }]);
});

test('after a failed row refresh the same real control can recover with a complete response', async () => {
  const p = await prepared(), before = snapshot(p), failed = await clickRefresh(p);
  assertFailed(p, before, await complete(p, failed, { code: 500, msg: 'Synthetic temporary read failure', data: null }, 500));
  const retried = await clickRefresh(p);
  assert.equal(await complete(p, retried, body([unit('Synthetic recovered knowledge')])), true);
  assert.equal(p.state.knowledgeCenterUnits.value[0].name, 'Synthetic recovered knowledge');
  assert.deepEqual(clone(p.state.selectedKnowledgeCenterUnitIds.value), [501]);
  assert.deepEqual(p.notices.map(item => item.type), ['error', 'success']);
  assert.equal(p.requests.length, 3, 'One seed GET and two explicit user refresh GETs');
  assert.ok((await p.html()).includes('Synthetic recovered knowledge'));
});
