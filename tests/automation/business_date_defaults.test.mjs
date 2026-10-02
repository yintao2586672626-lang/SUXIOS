import { readSourceAggregate as readStaticContractSource } from '../../scripts/lib/source_aggregate.mjs';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import {
  formatDateInTimeZone,
  formatDateOffsetInTimeZone,
} from '../../scripts/lib/shared_helpers.mjs';

test('business date helper uses the configured local calendar day', () => {
  const instant = new Date('2026-07-12T16:30:00Z');

  assert.equal(formatDateInTimeZone(instant, 'Asia/Shanghai'), '2026-07-13');
  assert.equal(formatDateInTimeZone(instant, 'America/Los_Angeles'), '2026-07-12');
  assert.equal(formatDateOffsetInTimeZone(instant, -1, 'Asia/Shanghai'), '2026-07-12');
});

test('business date offset stays on Shanghai yesterday during the UTC early-hours window', () => {
  const instant = new Date('2026-07-27T17:48:00Z');

  assert.equal(formatDateInTimeZone(instant, 'Asia/Shanghai'), '2026-07-28');
  assert.equal(formatDateOffsetInTimeZone(instant, -1, 'Asia/Shanghai'), '2026-07-27');
});

test('Ctrip external-input report defaults to the Shanghai business date', () => {
  const source = fs.readFileSync('scripts/report_revenue_ai_ctrip_external_input_candidates.mjs', 'utf8');

  assert.match(source, /import \{ formatDateInTimeZone \} from '\.\/lib\/shared_helpers\.mjs';/);
  assert.match(source, /options\.date = formatDateInTimeZone\(new Date\(\), 'Asia\/Shanghai'\);/);
  assert.doesNotMatch(source, /options\.date = new Date\(\)\.toISOString\(\)\.slice\(0, 10\);/);
});

test('AI daily report form and generation use Shanghai yesterday across browser time zones', () => {
  const source = fs.readFileSync('public/app-main.js', 'utf8');
  const start = source.indexOf('function shanghaiBusinessDate(');
  assert.ok(start >= 0, 'AI daily report must have an explicit Shanghai business-date default');
  const end = source.indexOf('const aiDailyReportYesterday =', start);
  assert.ok(end > start);
  const expression = source.slice(start, end);
  const instant = new Date('2026-07-12T16:30:00Z');
  class FixedDate extends Date {
    static now() { return instant.getTime(); }
  }
  const dateValues = vm.runInNewContext(`${expression}\n({ today: shanghaiBusinessToday, yesterday: shanghaiBusinessYesterday })`, {
    Date: FixedDate,
    Intl,
    Object,
  });
  assert.equal(dateValues.today, '2026-07-13');
  assert.equal(dateValues.yesterday, '2026-07-12');
  assert.match(source, /const aiDailyReportYesterday = shanghaiBusinessYesterday;/);
  assert.match(source, /const aiDailyReportForm = ref\(\{[\s\S]*?report_date: aiDailyReportYesterday,/);
  assert.match(source, /const requestedReportDate = String\(aiDailyReportForm\.value\.report_date \|\| ''\)\.trim\(\);/);
  assert.match(source, /const reportDate = requestedReportDate;/);
  assert.match(source, /if \(!reportDate\) throw new Error\('请选择日报业务日期'\);/);
});

test('authoritative operating loop reads and reconciles Shanghai yesterday', () => {
  const source = fs.readFileSync('public/app-main.js', 'utf8');
  for (const [pattern, label] of [
    [/const operatingLoopYesterday = shanghaiBusinessYesterday;/, 'date alias'],
    [/params\.append\('business_date', operatingLoopYesterday\)/, 'read query'],
    [/business_date: operatingLoopYesterday/, 'reconcile request'],
    [/receipt\?\.scope\?\.business_date !== operatingLoopYesterday/, 'exact receipt check'],
  ]) {
    assert.ok(pattern.test(source), `operating loop must use Shanghai yesterday for ${label}`);
  }
});

test('active closure summary queries Shanghai yesterday and preserves the selected hotel', () => {
  const source = fs.readFileSync('public/app-main.js', 'utf8');
  assert.ok(/closureParams\.set\('business_date', shanghaiBusinessYesterday\)/.test(source),
    'closure summary must query Shanghai yesterday');
  assert.match(source, /apiRequest\(`\/operation\/closure-overview\$\{closureQuery\}`, readOptions\)/);

  const dateStart = source.indexOf('function shanghaiBusinessDate(');
  const dateEnd = source.indexOf('const aiDailyReportYesterday =', dateStart);
  const actionStart = source.indexOf('const loadOperationActions =');
  const queryStart = source.indexOf('const closureParams = new URLSearchParams(params);', actionStart);
  const queryEnd = source.indexOf('const flowParams = new URLSearchParams(params);', queryStart);
  assert.ok(dateStart >= 0 && dateEnd > dateStart, 'live Shanghai date helper must exist');
  assert.ok(actionStart >= 0 && queryStart > actionStart && queryEnd > queryStart,
    'live operation action loader must construct the closure query');

  for (const timestamp of ['2026-07-12T16:30:00Z', '2026-07-13T00:30:00Z']) {
    class FixedDate extends Date {
      static now() { return Date.parse(timestamp); }
    }
    const params = new URLSearchParams({ hotel_id: '80', system_hotel_id: '80', business_date: '1999-01-01' });
    const closureQuery = vm.runInNewContext(
      `${source.slice(dateStart, dateEnd)}\n${source.slice(queryStart, queryEnd)}\nclosureQuery`,
      { Date: FixedDate, Intl, Object, URLSearchParams, params },
    );
    const actual = new URLSearchParams(closureQuery);
    assert.equal(actual.get('business_date'), '2026-07-12', timestamp);
    assert.equal(actual.get('hotel_id'), '80');
    assert.equal(actual.get('system_hotel_id'), '80');
    assert.equal(params.get('business_date'), '1999-01-01', 'closure date must stay scoped to its own query');
  }
});

test('retired investment-decision summary does not reenter the live business-date path', () => {
  const source = fs.readFileSync('public/app-main.js', 'utf8');
  assert.doesNotMatch(source, /investmentParams\.set\(|loadInvestmentDecision|investmentDecisionResult/);
});

test('manual notification preview and schedule form default to Shanghai dates', () => {
  const source = fs.readFileSync('public/app-main.js', 'utf8')
    + '\n' + fs.readFileSync('public/system-static.js', 'utf8')
    + '\n' + readStaticContractSource('public/system-page-projections.js');
  for (const [pattern, label] of [
    [/const shanghaiBusinessToday =/, 'today helper'],
    [/const manualNotificationForm = ref\(\{[\s\S]*?business_date: shanghaiBusinessToday,/, 'new form'],
    [/business_date: \(item\.business_date_rule \|\| 'today'\) === 'yesterday'[\s\S]*?\? shanghaiBusinessYesterday[\s\S]*?: shanghaiBusinessToday,/, 'edited plan'],
    [/manualNotificationForm\.value\?\.business_date \|\| operationToday/, 'preview summary'],
    [/operationToday: shanghaiBusinessToday,[\s\S]*?operationYesterday: shanghaiBusinessYesterday,/, 'preview orchestration'],
  ]) {
    assert.ok(pattern.test(source), `manual notification must use Shanghai date for ${label}`);
  }
});

test('automation monitor treats Shanghai today as realtime Meituan capture', () => {
  const source = fs.readFileSync('public/app-main.js', 'utf8');
  assert.ok(/dataPeriod: businessDate === shanghaiBusinessToday[\s\S]*?\? 'realtime_snapshot'[\s\S]*?: 'historical_daily'/.test(source),
    'monitor capture period must follow the Shanghai business day');
  assert.ok(/row\?\.business_date \|\| automationMonitorDate\.value \|\| shanghaiBusinessToday/.test(source),
    'monitor action must fall back to the Shanghai business date');
});

test('PMS operating target defaults and realtime label use Shanghai today', () => {
  const source = fs.readFileSync('public/app-main.js', 'utf8');
  assert.ok(/target_date: initialPmsTargetDateOverride \|\| shanghaiBusinessToday/.test(source),
    'target form must default to Shanghai today');
  assert.ok(/operatingPmsRealtimeActionText = computed\([\s\S]*?target_date \|\| ''\) === shanghaiBusinessToday/.test(source),
    'PMS action label must classify Shanghai today as realtime');
});

test('operations source filter and home schedule use Shanghai today', () => {
  const source = fs.readFileSync('public/app-main.js', 'utf8');
  const filter = source.slice(source.indexOf('const operationFilters = ref({'), source.indexOf('const operatingTargetForm = ref({'));
  const schedule = source.slice(source.indexOf('const homeOperatingScheduleModel = computed('), source.indexOf('const operationExecutionTraceRows = computed('));
  assert.ok(/date: shanghaiBusinessToday,/.test(filter),
    'operations source date filter must default to Shanghai today');
  assert.ok(/today: shanghaiBusinessToday,/.test(schedule),
    'home schedule must classify due dates against Shanghai today');
});

test('home revenue facts initialize and reset to Shanghai yesterday', () => {
  const source = fs.readFileSync('public/app-main.js', 'utf8');
  assert.ok(/const homeRevenueFactBusinessDate = ref\(shanghaiBusinessDate\(-1\)\)/.test(source),
    'home fact layer must initialize at Shanghai yesterday');
  assert.ok(/homeRevenueFactBusinessDate\.value = shanghaiBusinessDate\(-1\)/.test(source),
    'hotel change must reset home fact layer to Shanghai yesterday');
  assert.ok(/function shanghaiBusinessDate\(offsetDays = 0\)/.test(source),
    'date formatter must be callable before the home fact ref is initialized');
});

test('manual OTA evidence queries Shanghai yesterday when no target day was selected', () => {
  const source = fs.readFileSync('public/app-main.js', 'utf8');
  const controller = fs.readFileSync('app/controller/concern/OperationWorkbenchConcern.php', 'utf8');
  assert.ok(/const manualOneClickFetchTargetDate = \(\) => shanghaiBusinessDate\(-1\)/.test(source),
    'manual OTA evidence target must use Shanghai yesterday');
  const endpoint = controller.slice(controller.indexOf('public function manualFetchEvidence()'),
    controller.indexOf('public function dailyWorkbenchPatrols()'));
  assert.ok(/new \\DateTimeImmutable\('yesterday', new \\DateTimeZone\('Asia\/Shanghai'\)\)/.test(endpoint),
    'missing target_date must use Shanghai yesterday in the read-only endpoint');
});

test('manual OTA evidence rejects a response for another business date', async () => {
  const source = fs.readFileSync('public/app-main.js', 'utf8');
  const start = source.indexOf('const loadManualOneClickFetchEvidence = async');
  const end = source.indexOf('const manualOneClickFetchStorageKey =', start);
  assert.ok(start >= 0 && end > start);
  const rows = { value: [{ stale: true }] }, error = { value: '' }, loading = { value: false };
  const sandbox = {
    URLSearchParams,
    captureAuthSession: () => ({ userId: 7 }), isAuthSessionCurrent: session => session.userId === 7,
    manualOneClickFetchOwnerKey: () => 'synthetic-tenant:7',
    manualOneClickFetchEvidenceRows: rows,
    manualOneClickFetchEvidenceError: error,
    manualOneClickFetchEvidenceLoading: loading,
    manualOneClickFetchTargetDate: () => '2026-07-12',
    request: async () => ({ code: 200, data: { target_date: '2026-07-11', rows: [{ stale: false }] } }),
    console: { error() {} },
  };
  vm.runInNewContext(`${source.slice(start, end)}\nglobalThis.loadEvidence = loadManualOneClickFetchEvidence`, sandbox);
  const accepted = await sandbox.loadEvidence();
  assert.equal(accepted, false);
  assert.deepEqual(Array.from(rows.value), []);
  assert.match(error.value, /日期|范围/);
  assert.equal(loading.value, false);
  sandbox.request = async url => {
    assert.match(url, /target_date=2026-07-12/);
    return { code: 200, data: { target_date: '2026-07-12', rows: [{ stored: true }] } };
  };
  assert.equal(await sandbox.loadEvidence(), true);
  assert.equal(rows.value[0].stored, true);
  assert.equal(error.value, '');
});

test('OTA backfill and local collector defaults use Shanghai yesterday', () => {
  const source = fs.readFileSync('public/app-main.js', 'utf8');
  const service = fs.readFileSync('app/service/OtaLocalCollectorService.php', 'utf8');
  assert.ok(/const autoFetchMaxBackfillDate = computed\(\(\) => shanghaiBusinessDate\(-1\)\)/.test(source),
    'auto fetch date picker must cap at Shanghai yesterday');
  assert.ok(/localCollectorBackfillDate\.value = shanghaiBusinessDate\(-1\)/.test(source),
    'local collector backfill must default to Shanghai yesterday');
  const task = source.slice(source.indexOf('const createLocalCollectorTask = async'),
    source.indexOf('const contactLocalCollectorAdmin =', source.indexOf('const createLocalCollectorTask = async')));
  assert.ok(/body\.data_date = taskType === 'backfill'[\s\S]*?: shanghaiBusinessDate\(-1\)/.test(task),
    'local collector collect task must target Shanghai yesterday');
  assert.ok(/new \\DateTimeImmutable\('yesterday', new \\DateTimeZone\('Asia\/Shanghai'\)\)/.test(service),
    'service fallback date must match Shanghai yesterday');
});

test('Meituan ranking custom-date cap and freshness notice follow Shanghai time', () => {
  const source = fs.readFileSync('public/app-main.js', 'utf8');
  assert.ok(/const meituanRankMaxDate = computed\(\(\) => shanghaiBusinessDate\(\)\)/.test(source),
    'Meituan custom range must stop at Shanghai today');
  const staticSource = readStaticContractSource('public/meituan-static.js');
  const instant = new Date('2026-07-12T00:30:00Z');
  class OverseasDate extends Date {
    constructor(...args) { super(...(args.length ? args : [instant.getTime()])); }
    getHours() { return 17; }
  }
  const sandbox = { window: {}, Date: OverseasDate, Intl };
  vm.runInNewContext(staticSource, sandbox);
  assert.equal(sandbox.window.SUXI_MEITUAN_STATIC.shouldShowMeituanPreviousDayUpdateNotice(['1']), true,
    'Shanghai 08:30 must show the previous-day update notice');
});
