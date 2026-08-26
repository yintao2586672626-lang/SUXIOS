import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { readRouteContractSource } from '../../scripts/lib/route_contract_source.mjs';

const appMain = readFileSync('public/app-main.js', 'utf8');
const homeStatic = readFileSync('public/home-static.js', 'utf8');
const compassSummary = readFileSync('resources/frontend/templates/fragments/23a-page-compass-summary.html', 'utf8');
const routes = readRouteContractSource(process.cwd());
const reliability = readFileSync('app/controller/concern/CollectionReliabilityConcern.php', 'utf8');
const homeContext = { window: {}, URLSearchParams };
vm.runInNewContext(homeStatic, homeContext, { filename: 'public/home-static.js' });
const { createHomeRevenueFactLayerController } = homeContext.window.SUXI_HOME_STATIC;

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

  const factController = sliceBetween(
    homeStatic,
    'const createHomeRevenueFactLayerController =',
    'const buildHomeBusinessTimeModel =',
  );
  assert.match(factController, /request\(`\/dashboard\/revenue-facts\?\$\{params\.toString\(\)\}`/);
  assert.match(factController, /layer\.hotel\?\.system_hotel_id/);
  assert.match(factController, /layer\.business_date/);
  assert.doesNotMatch(factController, /canUseRevenueAi|revenueAiOverview/);
  assert.doesNotMatch(factController, /method:\s*['"](?:POST|PUT|PATCH|DELETE)['"]/);
  const factBridge = sliceBetween(
    appMain,
    'homeRevenueFactLayerController = createHomeRevenueFactLayerController',
    'const loadRevenueAiOverview = async',
  );
  assert.match(factBridge, /active: !!token\.value && isCompassDataPage\(\)/);
  assert.match(factBridge, /businessDate: homeRevenueFactBusinessDate\.value/);
  assert.match(factBridge, /homeRevenueFactLayer\.value = state\.layer/);
  assert.doesNotMatch(factBridge, /canUseRevenueAi|revenueAiOverview/);
  assert.match(appMain, /revenueFactLayer: homeRevenueFactLayer\.value/);
  assert.match(appMain, /revenueFactLayerError: homeRevenueFactLayerError\.value/);
  assert.doesNotMatch(appMain, /revenueFactLayerError: revenueAiOverviewError\.value/);
  assert.match(homeStatic, /动作需经过审批、执行证据和 ROI 复盘/);
  assert.match(homeStatic, /不自动执行/);

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
  const scope = {
    active: true,
    sessionKey: 'session-7',
    hotelId: '80',
    businessDate: '2026-08-14',
  };
  let visibleState = null;
  const pending = new Map();
  const requestOptions = new Map();
  const request = (url, options) => {
    const task = deferred();
    pending.set(url, task);
    requestOptions.set(url, options);
    return task.promise;
  };
  const controller = createHomeRevenueFactLayerController({
    request,
    readContext: () => ({ ...scope }),
    isContextCurrent: context => context.sessionKey === scope.sessionKey
      && context.hotelId === scope.hotelId
      && context.businessDate === scope.businessDate,
    requestPolicyFor: () => ({ scope: 'page', priority: 'current' }),
    onStateChange: state => { visibleState = state; },
  });

  const oldRequest = controller.load();
  scope.hotelId = '81';
  scope.businessDate = '2026-08-13';
  const newRequest = controller.load();

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

  assert.equal(visibleState.layer.hotel.system_hotel_id, 81);
  assert.equal(visibleState.layer.business_date, '2026-08-13');
  assert.equal(visibleState.loading, false);
  assert.equal(visibleState.error, '');
  assert.equal(requestOptions.get(newUrl).method, undefined);

  scope.businessDate = '2026-08-12';
  controller.reset();
  const mismatchedRead = controller.load({ force: true });
  const mismatchUrl = '/dashboard/revenue-facts?hotel_id=81&business_date=2026-08-12';
  pending.get(mismatchUrl).resolve({
    code: 200,
    data: {
      hotel: { system_hotel_id: 81 },
      business_date: '2026-08-11',
      status: 'ready',
    },
  });
  await mismatchedRead;
  assert.equal(visibleState.layer, null);
  assert.match(visibleState.error, /回读范围不一致/);
  assert.equal(visibleState.loading, false);

  const recoveredRead = controller.load({ force: true });
  pending.get(mismatchUrl).resolve({
    code: 200,
    data: {
      hotel: { system_hotel_id: 81 },
      business_date: '2026-08-12',
      status: 'partial',
    },
  });
  await recoveredRead;
  assert.equal(visibleState.layer.business_date, '2026-08-12');
  assert.equal(visibleState.error, '');
});

test('home exposes an explicit business date control and one visible blocker', () => {
  assert.match(homeStatic, /selectedBusinessDate: targetDate/);
  assert.match(homeStatic, /maxBusinessDate: String\(past\?\.period\?\.end_date \|\| ''\)/);
  assert.match(homeStatic, /'aria-label': '经营事实业务日期'/);
  assert.match(homeStatic, /'update:selectedBusinessDate'/);
  assert.match(homeStatic, /'当前唯一阻塞'/);
  assert.match(homeStatic, /下方指标受其影响，不重复计为独立故障/);
  assert.match(compassSummary, /@update:selected-business-date="homeRevenueFactBusinessDate = \$event"/);
  assert.match(appMain, /String\(homeRevenueFactBusinessDate\.value \|\| ''\)\.trim\(\) === String\(context\.businessDate \|\| ''\)\.trim\(\)/);
  assert.match(appMain, /watch\(homeRevenueFactBusinessDate/);
  assert.match(appMain, /homeRevenueFactLayerController\.reset\(\)/);
});

test('operation static loader performs only one cache-busted integrity repair attempt', () => {
  assert.match(appMain, /const operationStaticIntegrityKeys = \[/);
  assert.match(appMain, /'buildOperatingGoalContractPayload'/);
  assert.match(appMain, /operationStaticMissingIntegrityKeys\(currentStatic\)\.length === 0/);
  assert.match(appMain, /const repairQuery = repair \? \`&repair=\$\{Date\.now\(\)\}\` : ''/);
  assert.match(appMain, /if \(!repair\) \{\s*appendOperationStaticScript\(true\)/);
});
