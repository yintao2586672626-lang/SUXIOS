import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const appMain = readFileSync('public/app-main.js', 'utf8');
const homeStatic = readFileSync('public/home-static.js', 'utf8');
const compassSummary = readFileSync('resources/frontend/templates/fragments/23a-page-compass-summary.html', 'utf8');
const routes = readFileSync('route/app.php', 'utf8');
const reliability = readFileSync('app/controller/concern/CollectionReliabilityConcern.php', 'utf8');

const sliceBetween = (source, start, end) => {
  const startIndex = source.indexOf(start);
  const endIndex = source.indexOf(end, startIndex + start.length);
  assert.notEqual(startIndex, -1, `missing start marker: ${start}`);
  assert.notEqual(endIndex, -1, `missing end marker: ${end}`);
  return source.slice(startIndex, endIndex);
};

const deferred = () => {
  let resolve;
  let reject;
  const promise = new Promise((resolvePromise, rejectPromise) => {
    resolve = resolvePromise;
    reject = rejectPromise;
  });
  return { promise, resolve, reject };
};

test('dashboard reads the base fact layer without changing Revenue AI permission semantics', () => {
  assert.match(routes, /Route::get\('\/revenue-facts', 'OnlineData\/dashboardRevenueFacts'\)/);
  assert.match(reliability, /public function dashboardRevenueFacts\(\): Response/);
  assert.match(reliability, /RevenueFactLayerService\(\)\)->build\(\(int\)\$hotelId, \$businessDate\)/);

  const factLoader = sliceBetween(
    appMain,
    'const loadHomeRevenueFactLayer = async',
    'const loadRevenueAiOverview = async',
  );
  assert.match(factLoader, /request\(\`\/dashboard\/revenue-facts\?\$\{params\.toString\(\)\}\`/);
  assert.doesNotMatch(factLoader, /canUseRevenueAi|revenueAiOverview/);
  assert.match(appMain, /revenueFactLayer: homeRevenueFactLayer\.value/);
  assert.match(appMain, /revenueFactLayerError: homeRevenueFactLayerError\.value/);
  assert.doesNotMatch(appMain, /revenueFactLayerError: revenueAiOverviewError\.value/);

  const permissionGate = sliceBetween(
    appMain,
    'const canUseRevenueAi = () =>',
    'const guardSuperAdminPageAccess',
  );
  assert.match(permissionGate, /can_use_ai_decision/);
  assert.match(permissionGate, /ai\.view/);
  assert.match(permissionGate, /ai\.execute/);
});

test('dashboard endpoint rejects missing or malformed explicit hotel scope before permission resolution', () => {
  const endpoint = sliceBetween(
    reliability,
    'public function dashboardRevenueFacts(): Response',
    'private function resolveDashboardDateRange',
  );
  assert.match(endpoint, /\$hotelInput = \$this->request->get/);
  assert.match(endpoint, /!is_scalar\(\$hotelInput\)/);
  assert.match(endpoint, /preg_match\('\/\^\[1-9\]\\d\*\$\/D'/);
  assert.match(endpoint, /throw new \\InvalidArgumentException\('请选择有效酒店'\)/);
  assert.ok(
    endpoint.indexOf('请选择有效酒店') < endpoint.indexOf('resolveDashboardHotelId'),
    'hotel input must be validated before resolving permitted hotel scope',
  );
  assert.match(endpoint, /请选择有效业务日期，格式为 YYYY-MM-DD/);
  assert.match(endpoint, /checkdate\(\$month, \$day, \$year\)/);
});

test('late fact responses cannot overwrite a newer hotel and business date', async () => {
  const loaderSource = sliceBetween(
    appMain,
    'const loadHomeRevenueFactLayer = async',
    'const loadRevenueAiOverview = async',
  );
  const token = { value: 'test-token' };
  const currentPage = { value: 'compass' };
  const filterReportHotel = { value: '80' };
  const homeRevenueFactBusinessDate = { value: '2026-08-14' };
  const homeRevenueFactLayer = { value: null };
  const homeRevenueFactLayerLoading = { value: false };
  const homeRevenueFactLayerError = { value: '' };
  const pending = new Map();
  const request = (url) => {
    const task = deferred();
    pending.set(url, task);
    return task.promise;
  };
  const context = {
    token,
    isCompassDataPage: () => true,
    captureAuthSession: () => ({ epoch: 7, token: token.value }),
    currentPage,
    filterReportHotel,
    homeRevenueFactBusinessDate,
    homeRevenueFactLayer,
    homeRevenueFactLayerLoading,
    homeRevenueFactLayerError,
    authSessionEpoch: 7,
    isAuthSessionCurrent: () => true,
    request,
    currentPageReadPolicy: () => ({}),
    URLSearchParams,
    console,
  };
  const names = Object.keys(context);
  const createHarness = Function(
    ...names,
    `let homeRevenueFactLayerRequestSeq = 0;
     const homeRevenueFactLayerRequestPromises = new Map();
     ${loaderSource}
     return { loadHomeRevenueFactLayer };`,
  );
  const { loadHomeRevenueFactLayer } = createHarness(
    ...names.map(name => context[name]),
  );

  const oldRequest = loadHomeRevenueFactLayer();
  filterReportHotel.value = '81';
  homeRevenueFactBusinessDate.value = '2026-08-13';
  const newRequest = loadHomeRevenueFactLayer();

  const newUrl = '/dashboard/revenue-facts?hotel_id=81&business_date=2026-08-13';
  const oldUrl = '/dashboard/revenue-facts?hotel_id=80&business_date=2026-08-14';
  pending.get(newUrl).resolve({
    code: 200,
    data: {
      hotel: { system_hotel_id: 81 },
      business_date: '2026-08-13',
      status: 'partial',
    },
  });
  await newRequest;
  pending.get(oldUrl).resolve({
    code: 200,
    data: {
      hotel: { system_hotel_id: 80 },
      business_date: '2026-08-14',
      status: 'ready',
    },
  });
  await oldRequest;

  assert.equal(homeRevenueFactLayer.value.hotel.system_hotel_id, 81);
  assert.equal(homeRevenueFactLayer.value.business_date, '2026-08-13');
  assert.equal(homeRevenueFactLayerLoading.value, false);
  assert.equal(homeRevenueFactLayerError.value, '');
});

test('home exposes an explicit business date control and one visible blocker', () => {
  assert.match(homeStatic, /selectedBusinessDate: targetDate/);
  assert.match(homeStatic, /maxBusinessDate: String\(past\?\.period\?\.end_date \|\| ''\)/);
  assert.match(homeStatic, /'aria-label': '经营事实业务日期'/);
  assert.match(homeStatic, /'update:selectedBusinessDate'/);
  assert.match(homeStatic, /'当前唯一阻塞'/);
  assert.match(homeStatic, /下方指标受其影响，不重复计为独立故障/);
  assert.match(compassSummary, /@update:selected-business-date="homeRevenueFactBusinessDate = \$event"/);
  assert.match(appMain, /businessDate === String\(homeRevenueFactBusinessDate\.value \|\| ''\)\.trim\(\)/);
  assert.match(appMain, /watch\(homeRevenueFactBusinessDate/);
});

test('operation static loader performs only one cache-busted integrity repair attempt', () => {
  assert.match(appMain, /const operationStaticIntegrityKeys = \[/);
  assert.match(appMain, /'buildOperatingGoalContractPayload'/);
  assert.match(appMain, /operationStaticMissingIntegrityKeys\(currentStatic\)\.length === 0/);
  assert.match(appMain, /const repairQuery = repair \? \`&repair=\$\{Date\.now\(\)\}\` : ''/);
  assert.match(appMain, /if \(!repair\) \{\s*appendOperationStaticScript\(true\)/);
});
