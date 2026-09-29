import assert from 'node:assert/strict';
import test from 'node:test';
import * as Vue from 'vue';
import { parse, compile } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';
import { harness, raw, clone, tick } from './helpers/knowledge_import_ui_harness.mjs';

// Original enabled controls, domain and HTTP coordinator over synthetic list
// responses. This is sequential navigation, not browser or query-race evidence.
const walkAst = (node, found = []) => { found.push(node); (node.children || []).forEach(child => walkAst(child, found)); return found; };
const template = walkAst(parse(raw.page));
const has = (node, name, expression) => node.props?.some(prop => prop.name === name && prop.exp?.content === expression);
const search = template.find(node => node.type === 1 && node.tag === 'input' && has(node, 'model', 'knowledgeCenterFilter.keyword') && has(node, 'on', 'reloadKnowledgeCenter'));
const pager = template.find(node => node.type === 1 && node.tag === 'div' && node.props.some(prop => prop.name === 'class' && prop.value?.content === 'flex items-center justify-between bg-white rounded-xl shadow-sm border border-gray-100 p-4'));
assert.ok(search && pager, 'Use the visible list search with Enter handler and original pager');
const render = new Function('Vue', compile('<section>' + search.loc.source + pager.loc.source + '</section>', { mode: 'function', prefixIdentifiers: true }).code)(Vue);
const walkNodes = (node, found = []) => {
  if (Array.isArray(node)) { node.forEach(child => walkNodes(child, found)); return found; }
  if (!node || typeof node !== 'object') return found;
  found.push(node); walkNodes(node.children, found); return found;
};
const rows = (page, family = 'INITIAL') => Array.from({ length: 10 }, (_, index) => ({
  unit_id: 1000 + (page - 1) * 10 + index, hotel_id: 80, name: `Synthetic ${family} page${page} row${index}`,
  description: 'Synthetic reference only', source: 'document', status: 'done', tags: [], can_edit: true,
}));
const body = (page, list = rows(page), pagination = {}) => ({ code: 0, msg: '', data: { list: clone(list), pagination: { total: 30, page, page_size: 10, total_page: 3, ...pagination } } });
const ids = p => p.state.knowledgeCenterUnits.value.map(unit => String(unit.unit_id));
const view = p => JSON.stringify({ rows: p.state.knowledgeCenterUnits.value, page: p.state.knowledgeCenterPagination.value });

async function controls(p) {
  assert.equal(p.sandbox.currentPage.value, 'knowledge-center');
  assert.equal(p.state.showKnowledgeCenterImportModal.value, false, 'No modal covers the list');
  let vnode;
  const html = await renderToString(Vue.createSSRApp({ setup: () => ({ ...p.state, ...p.methods }), render() { vnode = render.call(this, this, []); return vnode; } }));
  const nodes = walkNodes(vnode);
  return { html, nodes, button: label => nodes.find(node => node.type === 'button' && p.text(node).trim() === label),
    page: nodes.filter(node => node.type === 'span').map(p.text).find(value => /^\d+ \/ \d+$/.test(value.trim()))?.trim() };
}
function request(p, expectedPage) {
  const req = p.pending('list'); assert.ok(req, 'Original control starts its GET');
  const url = new URL(req.url);
  assert.equal(url.origin, 'https://synthetic.invalid'); assert.equal(url.pathname, '/api/knowledge/list');
  assert.equal(req.options.method || 'GET', 'GET'); assert.equal(url.searchParams.get('page'), String(expectedPage));
  assert.equal(url.searchParams.has('hotel_id'), false, 'Preserve original authorized aggregate query');
  return req;
}
async function header(p, expectedPage) {
  await p.html(); const button = p.findButton('刷新'); assert.ok(button && !button.props.disabled);
  button.props.onClick(); await tick(); return request(p, expectedPage);
}
async function turn(p, label, expectedPage) {
  const button = (await controls(p)).button(label); assert.ok(button && !button.props.disabled, 'Only click an enabled pager control');
  button.props.onClick(); await tick(); return request(p, expectedPage);
}
async function keyword(p, value) {
  const input = (await controls(p)).nodes.find(node => node.type === 'input');
  assert.ok(input && !input.props.disabled && !input.props.readonly);
  input.props['onUpdate:modelValue'](value); await tick();
}
async function enter(p) {
  const input = (await controls(p)).nodes.find(node => node.type === 'input'); assert.ok(input && !input.props.disabled);
  input.props.onKeyup({ key: 'Enter' }); await tick(); return request(p, 1);
}
async function finish(p, req, receipt, status = 200) {
  p.reply(req, receipt, status); await tick();
  assert.equal(p.state.knowledgeCenterLoading.value, false);
  assert.ok(p.requests.every(item => (item.options.method || 'GET') === 'GET'));
}
async function ready(page = 1) {
  const p = harness(); await finish(p, await header(p, 1), body(1));
  for (let next = 2; next <= page; next++) await finish(p, await turn(p, '下一页', next), body(next));
  return p;
}
async function selectPage(p) {
  await p.html(); const input = p.nodes().find(node => node.type === 'input' && node.props?.['aria-label'] === '选择当前页知识');
  assert.ok(input && !input.props.disabled, 'Use the enabled real select-all checkbox');
  assert.ok(p.state.knowledgeCenterUnits.value.every(unit => unit.can_edit === true));
  input.props.onChange({ target: { checked: true } }); await tick();
  assert.deepEqual(clone(p.state.selectedKnowledgeCenterUnitIds.value), ids(p));
}
async function assertFailedPage(p, previous, label, error) {
  assert.equal(view(p), previous, 'Failed target-page GET preserves displayed rows and their page metadata');
  assert.equal((await controls(p)).page, label, 'Rendered page label belongs to the retained rows');
  assert.ok(p.notices.some(item => item.type === 'error' && item.message === error));
}

test('normal next and previous controls display returned pages and preserve boundary disabling', async () => {
  const p = await ready(); assert.equal((await controls(p)).button('上一页').props.disabled, true);
  for (const page of [2, 3]) {
    await finish(p, await turn(p, '下一页', page), body(page));
    assert.equal((await controls(p)).page, `${page} / 3`); assert.deepEqual(ids(p), rows(page).map(row => String(row.unit_id)));
  }
  assert.equal((await controls(p)).button('下一页').props.disabled, true);
  for (const page of [2, 1]) await finish(p, await turn(p, '上一页', page), body(page));
  assert.equal((await controls(p)).page, '1 / 3'); assert.equal((await controls(p)).button('上一页').props.disabled, true);
  assert.equal(p.requests.length, 5, 'Disabled boundaries are observed, never force-clicked'); assert.deepEqual(p.notices, []);
});

test('failed next page keeps page1 and its selection; the same Next retries page2 and intersects visible IDs', async () => {
  const p = await ready(); await selectPage(p); const previous = view(p), selected = clone(p.state.selectedKnowledgeCenterUnitIds.value);
  await finish(p, await turn(p, '下一页', 2), { code: 500, msg: 'Synthetic page2 failure', data: null }, 500);
  await assertFailedPage(p, previous, '1 / 3', 'Synthetic page2 failure');
  assert.deepEqual(clone(p.state.selectedKnowledgeCenterUnitIds.value), selected);
  assert.equal((await controls(p)).button('上一页').props.disabled, true);
  // Non-atomic list reads may keep one previously visible ID; preserve the
  // existing selection intersection, not an unconditional clear on success.
  const second = [rows(1)[0], ...rows(2).slice(1)];
  await finish(p, await turn(p, '下一页', 2), body(2, second));
  assert.equal((await controls(p)).page, '2 / 3'); assert.deepEqual(ids(p), second.map(row => String(row.unit_id)));
  assert.deepEqual(clone(p.state.selectedKnowledgeCenterUnitIds.value), [selected[0]]);
  assert.equal(p.requests.length, 3);
});

test('failed previous page keeps page3 and the same Previous retries page2', async () => {
  const p = await ready(3), previous = view(p);
  await finish(p, await turn(p, '上一页', 2), { code: 422, msg: 'Synthetic previous-page rejection', data: null }, 422);
  await assertFailedPage(p, previous, '3 / 3', 'Synthetic previous-page rejection');
  assert.equal((await controls(p)).button('下一页').props.disabled, true);
  await finish(p, await turn(p, '上一页', 2), body(2));
  assert.equal((await controls(p)).page, '2 / 3'); assert.deepEqual(ids(p), rows(2).map(row => String(row.unit_id)));
});

test('failed Enter with a changed query hides prior-scope rows and pager; Enter retries page1 with the typed draft', async () => {
  const p = await ready(2); await selectPage(p); const previous = view(p);
  await keyword(p, 'NEW QUERY'); const req = await enter(p);
  assert.equal(new URL(req.url).searchParams.get('keyword'), 'NEW QUERY');
  assert.deepEqual(clone(p.state.selectedKnowledgeCenterUnitIds.value), [], 'Search clears selection at request start');
  await finish(p, req, { code: 500, msg: 'Synthetic query failure', data: null }, 500);
  assert.deepEqual(clone(p.state.knowledgeCenterUnits.value), [], 'Changed-query failure cannot display the prior scope as matching results');
  assert.equal(JSON.stringify(p.state.knowledgeCenterPagination.value), JSON.stringify(JSON.parse(previous).page), 'Failed response cannot fabricate new page metadata');
  assert.equal((await controls(p)).page, undefined, 'The previous scope pager is hidden');
  assert.equal(p.state.knowledgeCenterListError.value, 'Synthetic query failure');
  assert.equal(p.state.knowledgeCenterFilter.value.keyword, 'NEW QUERY'); assert.ok((await controls(p)).html.includes('NEW QUERY'));
  const retried = await enter(p); assert.equal(new URL(retried.url).searchParams.get('keyword'), 'NEW QUERY');
  await finish(p, retried, body(1, rows(1, 'NEW QUERY')));
  assert.equal((await controls(p)).page, '1 / 3'); assert.ok(p.state.knowledgeCenterUnits.value.every(row => row.name.includes('NEW QUERY')));
  assert.deepEqual(clone(p.state.selectedKnowledgeCenterUnitIds.value), []); assert.equal(p.requests.length, 4);
});

test('default header refresh after a failed target page reads the still displayed current page', async () => {
  const p = await ready(), previous = view(p);
  await finish(p, await turn(p, '下一页', 2), { code: 500, msg: 'Synthetic unavailable target page', data: null }, 500);
  await assertFailedPage(p, previous, '1 / 3', 'Synthetic unavailable target page');
  await finish(p, await header(p, 1), body(1, rows(1, 'REFRESHED')));
  assert.equal((await controls(p)).page, '1 / 3'); assert.ok(p.state.knowledgeCenterUnits.value.every(row => row.name.includes('REFRESHED')));
});

test('successful Enter resets a later page to the actual page1 response, including trimmed keyword', async () => {
  const p = await ready(2); await selectPage(p); await keyword(p, '  FOUND  ');
  const req = await enter(p); assert.equal(new URL(req.url).searchParams.get('keyword'), 'FOUND');
  await finish(p, req, body(1, rows(1, 'FOUND')));
  assert.equal((await controls(p)).page, '1 / 3'); assert.deepEqual(ids(p), rows(1).map(row => String(row.unit_id)));
  assert.equal(p.state.knowledgeCenterFilter.value.keyword, '  FOUND  ', 'Do not rewrite the typed filter');
  assert.deepEqual(clone(p.state.selectedKnowledgeCenterUnitIds.value), []);
});

test('a legitimate empty search keeps zero counts and both page controls disabled', async () => {
  const p = await ready(2); await keyword(p, 'NO MATCH');
  await finish(p, await enter(p), body(1, [], { total: 0, total_page: 0 }));
  assert.deepEqual(clone(p.state.knowledgeCenterUnits.value), []);
  assert.deepEqual(clone(p.state.knowledgeCenterPagination.value), { total: 0, page: 1, page_size: 10, total_page: 0 });
  const ui = await controls(p); assert.equal(ui.page, '1 / 1');
  assert.equal(ui.button('上一页').props.disabled, true); assert.equal(ui.button('下一页').props.disabled, true);
  assert.deepEqual(p.notices, []);
});

test('authorized aggregate rows and numeric-string pagination remain valid on page navigation', async () => {
  const p = await ready(); const mixed = rows(2); mixed[0].hotel_id = 0; mixed[1].hotel_id = 81;
  await finish(p, await turn(p, '下一页', 2), body('2', mixed, { total: '30', page_size: '10', total_page: '3' }));
  assert.equal((await controls(p)).page, '2 / 3'); assert.deepEqual(clone(p.state.knowledgeCenterUnits.value), mixed);
  assert.equal(p.state.knowledgeCenterPagination.value.page, 2); assert.deepEqual(p.notices, []);
});
