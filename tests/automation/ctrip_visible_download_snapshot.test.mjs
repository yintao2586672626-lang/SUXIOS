import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const ctripStaticSource = readFileSync('public/ctrip-static.js', 'utf8');
const appMainSource = readFileSync('public/app-main.js', 'utf8');
const ctripTemplate = readFileSync('resources/frontend/templates/fragments/24-page-ctrip-ebooking.html', 'utf8');

const loadCtripStaticApi = () => {
  const context = { window: {}, console };
  vm.runInNewContext(ctripStaticSource, context, { filename: 'public/ctrip-static.js' });
  return context.window.SUXI_CTRIP_STATIC || {};
};

const fakeNode = ({
  text = '',
  className = '',
  width = 0,
  attrs = {},
  one = {},
  many = {},
} = {}) => ({
  innerText: text,
  textContent: text,
  className,
  getAttribute: name => attrs[name] ?? null,
  getBoundingClientRect: () => ({ width }),
  querySelector: selector => one[selector] ?? null,
  querySelectorAll: selector => many[selector] ?? [],
});

const headerCell = (text, { colspan = 1, rowspan = 1, width = 0 } = {}) => fakeNode({
  text,
  width,
  attrs: { colspan: String(colspan), rowspan: String(rowspan) },
});

test('competition download snapshot reads the exact visible cards headers rows and source state', () => {
  const api = loadCtripStaticApi();
  assert.equal(typeof api.captureCtripBusinessDownloadSnapshot, 'function');

  const card = fakeNode({
    className: 'ctrip-summary-card bg-green-50',
    one: {
      '.ctrip-summary-card-value': fakeNode({ text: '12,580.0' }),
      '.ctrip-summary-card-label': fakeNode({ text: '离店销售额' }),
      '.ctrip-summary-card-level': fakeNode({ text: 'OTA渠道' }),
    },
  });
  const headerRows = [
    fakeNode({ many: { th: [
      headerCell('排名', { rowspan: 2, width: 58 }),
      headerCell('酒店名称', { rowspan: 2, width: 260 }),
      headerCell('竞争力', { colspan: 2, width: 290 }),
    ] } }),
    fakeNode({ many: { th: [
      headerCell('平均房价指数(ARI) ↑', { width: 145 }),
      headerCell('综合竞争力指数(SCI)', { width: 145 }),
    ] } }),
  ];
  const bodyCells = [
    fakeNode({ text: '1', width: 58, className: 'text-center' }),
    fakeNode({ text: '测试酒店', width: 260, className: 'text-left' }),
    fakeNode({ text: '88.0', width: 145, className: 'text-center' }),
    fakeNode({ text: '90.0', width: 145, className: 'text-center' }),
  ];
  const table = fakeNode({
    attrs: { 'data-download-title': '榜单与排名' },
    many: {
      'thead tr': headerRows,
      'tbody tr': [fakeNode({ many: { td: bodyCells } })],
    },
  });
  const root = fakeNode({
    one: {
      '[data-download-table]': table,
      '[data-testid="ctrip-business-source-notice"]': fakeNode({ text: '凌晨更新中  缺来源' }),
    },
    many: {
      '[data-testid="ctrip-summary-card"]': [card],
    },
  });

  const snapshot = api.captureCtripBusinessDownloadSnapshot({ root });

  assert.deepEqual(JSON.parse(JSON.stringify(snapshot.cards)), [{
    value: '12,580.0',
    label: '离店销售额',
    level: 'OTA渠道',
    panelClass: 'ctrip-summary-card bg-green-50',
  }]);
  assert.equal(snapshot.sourceNotice, '凌晨更新中 · 缺来源');
  assert.equal(snapshot.table.title, '榜单与排名');
  assert.deepEqual(
    JSON.parse(JSON.stringify(snapshot.table.columns.map(column => column.label))),
    ['排名', '酒店名称', '竞争力 · 平均房价指数(ARI) ↑', '竞争力 · 综合竞争力指数(SCI)'],
  );
  assert.deepEqual(JSON.parse(JSON.stringify(snapshot.table.rows)), [['1', '测试酒店', '88.0', '90.0']]);
  assert.equal(snapshot.table.columns[1].align, 'left');
  assert.equal(snapshot.table.columns[2].value(snapshot.table.rows[0]), '88.0');
});

test('competition download fails closed instead of falling back to a second field map', () => {
  const api = loadCtripStaticApi();
  assert.throws(
    () => api.captureCtripBusinessDownloadSnapshot({ root: null }),
    /ctrip_visible_download_root_unavailable/,
  );
  assert.throws(
    () => api.captureCtripBusinessDownloadSnapshot({ root: fakeNode() }),
    /ctrip_visible_download_table_unavailable/,
  );
});

test('competition download adapter uses the rendered surface and removes the legacy export map', () => {
  assert.match(appMainSource, /requireCtripStatic\('captureCtripBusinessDownloadSnapshot'\)/);
  assert.match(appMainSource, /document\.querySelector\('\[data-download-target="ctrip-business"\]'\)/);
  assert.match(appMainSource, /captureCtripBusinessDownloadSnapshot\(\{/);
  assert.doesNotMatch(appMainSource, /const ctripDownloadRows =/);
  assert.doesNotMatch(appMainSource, /table: ctripDownloadRows\(\)/);
  assert.doesNotMatch(appMainSource, /当前返回/);
  assert.match(appMainSource, /当前竞争圈页面尚未完成渲染，请刷新后重新下载/);
  assert.match(ctripTemplate, /data-download-table="current-visible"/);
  assert.match(ctripTemplate, /data-download-title="销售与订单"/);
  assert.doesNotMatch(ctripTemplate, /访客\/转化来源/);
  assert.match(ctripTemplate, /data-testid="ctrip-business-source-notice"/);
  assert.match(ctripTemplate, /ctrip-summary-card-label/);
  assert.match(ctripTemplate, /ctrip-summary-card-level/);
});
