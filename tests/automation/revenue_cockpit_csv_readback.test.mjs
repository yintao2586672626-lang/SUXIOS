import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import test from 'node:test';

const root = path.resolve(import.meta.dirname, '../..');
const context = vm.createContext({ window: {}, URLSearchParams });
vm.runInContext(fs.readFileSync(path.join(root, 'public/revenue-cockpit-static.js'), 'utf8'), context);
const helpers = context.window.SUXI_REVENUE_COCKPIT_STATIC.create({
  revenueAiReasonText: value => String(value || ''),
  resolveRevenueOverviewAsOfDate: () => ({ ok: true }),
  revenueAiIsoDate: value => /^\d{4}-\d{2}-\d{2}$/.test(String(value || '')),
  revenueOverviewAsOfDateContractVersion: 'revenue_overview_as_of_date.v1',
});

const metadata = [
  ['tenant_id', '租户ID', 'tenantId'], ['hotel_id', '酒店ID', 'hotelId'],
  ['hotel_name', '酒店名称', 'hotelName'], ['platform', '所选渠道', 'selectedPlatform'],
  ['request_business_date', '所选业务日期', 'businessDate'], ['as_of_date', '数据基准日', 'asOfDate'],
  ['as_of_date_contract_version', '数据基准日合同版本', 'asOfDateContractVersion'],
  ['view_model_contract_version', '驾驶舱合同版本', 'contractVersion'],
];
const originalHeaders = ['顺序', '分区', '卡片', '页面显示', '单位', '来源', '业务日期',
  '验证状态', '口径', '缺失状态', '机会键', '机会顺序', '证据等级', '关系类型',
  '是否因果结论', '说明', '证据'];
const model = () => ({
  contractVersion: 'revenue_daily_cockpit.v2', tenantId: 9, hotelId: 80,
  hotelName: '测试酒店,"A"', selectedPlatform: 'ctrip', businessDate: '2026-08-20',
  asOfDate: '2026-08-21', asOfDateContractVersion: 'revenue_overview_as_of_date.v1',
  visibleSections: [{ title: '渠道事实', cards: [
    { label: '收入', display: '不可计算', unitLabel: '元', sourceLabel: '携程 OTA',
      businessDate: '2026-08-20', statusLabel: '不可计算', scopeLabel: '携程渠道',
      missingState: 'not_calculable', reasonText: '有限收入累计溢出，\n请检查原始记录',
      evidenceLines: ['online_daily_data#101', 'synthetic-test-only'] },
    { label: '基期收入', display: '0', unitLabel: '元', sourceLabel: '人工导入',
      businessDate: '2026-08-13', statusLabel: '人工导入', scopeLabel: '携程渠道',
      missingState: '', evidenceLines: ['fixture#102'] },
    { label: '净贡献', display: '—', unitLabel: '元', sourceLabel: '来源缺失',
      businessDate: '2026-08-20', statusLabel: '缺失', scopeLabel: '携程渠道',
      missingState: 'missing', reasonText: '成本缺失，不能按0计算' },
  ] }],
});

// Parse actual RFC4180 quoted CSV bytes, including embedded commas, quotes and newlines.
function parseCsv(csv) {
  const input = csv.replace(/^\uFEFF/, '');
  const rows = []; let row = []; let cell = ''; let quoted = false;
  for (let i = 0; i < input.length; i++) {
    const char = input[i];
    if (char === '"') {
      if (quoted && input[i + 1] === '"') { cell += '"'; i++; }
      else quoted = !quoted;
    } else if (!quoted && char === ',') { row.push(cell); cell = ''; }
    else if (!quoted && (char === '\r' || char === '\n')) {
      if (char === '\r' && input[i + 1] === '\n') i++;
      row.push(cell); rows.push(row); row = []; cell = '';
    } else cell += char;
  }
  assert.equal(quoted, false, 'download must be valid quoted CSV');
  row.push(cell); rows.push(row);
  return rows;
}

test('export and file readback retain complete scope, card identity and selected as-of date', () => {
  const current = model(); const before = JSON.stringify(current);
  const payload = helpers.buildRevenueCockpitDownloadPayload(current);
  assert.equal(payload.ok, true);
  const directory = path.join(root, 'csv-readback');
  fs.mkdirSync(directory, { recursive: true });
  const file = path.join(directory, payload.fileName);
  fs.writeFileSync(file, payload.csv, 'utf8');
  const csv = fs.readFileSync(file, 'utf8');
  assert.equal(csv, payload.csv, 'saved bytes must match download exactly');
  assert.equal(csv.charCodeAt(0), 0xFEFF);
  const [headers, ...rows] = parseCsv(csv);
  assert.deepEqual(headers.slice(0, originalHeaders.length), originalHeaders);
  assert.equal(rows.length, current.visibleSections[0].cards.length);
  for (const [index, row] of rows.entries()) {
    assert.equal(row.length, headers.length);
    for (const [key, label, sourceKey] of metadata) {
      assert.equal(payload.rows[index][key], String(current[sourceKey]), `${key} must remain in download rows`);
      assert.ok(headers.includes(label), `${label} must remain in the downloaded CSV`);
      assert.equal(row[headers.indexOf(label)], String(current[sourceKey]));
    }
  }
  assert.equal(rows[0][headers.indexOf('页面显示')], '不可计算');
  assert.equal(rows[0][headers.indexOf('缺失状态')], 'not_calculable');
  assert.equal(rows[0][headers.indexOf('说明')], current.visibleSections[0].cards[0].reasonText);
  assert.equal(rows[0][headers.indexOf('证据')], 'online_daily_data#101；synthetic-test-only');
  assert.equal(rows[1][headers.indexOf('页面显示')], '0');
  assert.equal(rows[1][headers.indexOf('业务日期')], '2026-08-13', 'baseline card date differs from requested date');
  assert.equal(rows[2][headers.indexOf('页面显示')], '—');
  assert.equal(rows[2][headers.indexOf('缺失状态')], 'missing');
  assert.equal(JSON.stringify(current), before, 'download must leave the visible model unchanged');
  // Evidence file is deliberately retained; it is synthetic, never a real hotel record.
});

test('same hotel and business date exports distinguish selected as-of dates', () => {
  const first = model(); const second = { ...model(), asOfDate: '2026-08-22' };
  const firstCsv = helpers.buildRevenueCockpitCsv(first);
  const secondCsv = helpers.buildRevenueCockpitCsv(second);
  assert.notEqual(firstCsv, secondCsv, 'different as-of evidence scopes must not export identical CSV');
});

test('legacy missing scope remains explicit blank without synthetic dates or tenant defaults', () => {
  const legacy = { visibleSections: model().visibleSections };
  const payload = helpers.buildRevenueCockpitDownloadPayload(legacy);
  assert.equal(payload.ok, true);
  const [headers, ...rows] = parseCsv(payload.csv);
  for (const [key, label] of metadata) {
    assert.equal(payload.rows[0][key], '');
    assert.ok(headers.includes(label));
    assert.equal(rows[0][headers.indexOf(label)], '');
  }
  assert.equal(rows[0][headers.indexOf('业务日期')], '2026-08-20', 'known card date remains known');
});

test('empty or blocked cockpit never exports empty success; normal recovery is retained', () => {
  for (const current of [{}, { status: 'blocked', visibleSections: [] }, { status: 'loading' }]) {
    const payload = helpers.buildRevenueCockpitDownloadPayload(current);
    assert.equal(payload.ok, false);
    assert.equal(payload.csv, '');
  }
  const recovered = model(); recovered.visibleSections[0].cards[0].display = '200';
  recovered.visibleSections[0].cards[0].missingState = '';
  recovered.visibleSections[0].cards[0].statusLabel = '严格回读';
  assert.equal(helpers.buildRevenueCockpitDownloadPayload(recovered).ok, true);
  assert.equal(parseCsv(helpers.buildRevenueCockpitCsv(recovered))[1][3], '200');
});

test('actual cockpit download action produces the same scoped CSV Blob and blocks empty actions', async () => {
  const main = fs.readFileSync(path.join(root, 'public/app-main.js'), 'utf8');
  const start = main.indexOf('const downloadRevenueCockpit = () => {');
  const end = main.indexOf('const openRevenueCockpitDataHealth', start);
  assert.ok(start >= 0 && end > start, 'use the existing app action, never duplicate its implementation');
  const downloads = []; const notices = [];
  const scope = { value: model() };
  const actionContext = vm.createContext({
    Blob, revenueCockpitModel: scope, filterReportHotel: { value: 80 },
    getHotelName: () => '测试酒店',
    revenueAiRunCockpitHelper: (name, ...args) => helpers[name](...args),
    downloadBlob: (blob, fileName) => downloads.push({ blob, fileName }),
    showToast: (...args) => notices.push(args),
  });
  const action = vm.runInContext(`${main.slice(start, end)}; downloadRevenueCockpit`, actionContext);
  assert.equal(action(), true);
  assert.equal(downloads.length, 1);
  assert.equal(downloads[0].blob.type, 'text/csv;charset=utf-8');
  // Blob.text() strips a UTF-8 BOM; arrayBuffer preserves the actual download bytes.
  const csv = Buffer.from(await downloads[0].blob.arrayBuffer()).toString('utf8');
  assert.equal(csv, helpers.buildRevenueCockpitCsv(scope.value));
  const [headers, row] = parseCsv(csv);
  assert.equal(row[headers.indexOf('数据基准日')], scope.value.asOfDate);
  assert.equal(row[headers.indexOf('页面显示')], '不可计算');
  scope.value = { status: 'empty', visibleSections: [] };
  assert.equal(action(), false);
  assert.equal(downloads.length, 1, 'no empty success download');
  assert.equal(notices.at(-1)[1], 'warning');
});
