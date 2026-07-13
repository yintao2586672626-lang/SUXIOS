import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';

const root = process.cwd();
const sourceCache = new Map();
const readRaw = (file) => {
  if (!sourceCache.has(file)) {
    sourceCache.set(file, fs.readFileSync(path.join(root, file), 'utf8'));
  }
  return sourceCache.get(file);
};
const read = (file) => file === 'public/index.html'
  ? `${readRaw(file)}\n${readRaw('public/app-main.js')}`
  : readRaw(file);
const failures = [];

function assertContract(ok, message) {
  if (!ok) failures.push(message);
}

function includesAll(file, label, needles) {
  const source = read(file);
  for (const needle of needles) {
    assertContract(source.includes(needle), `${file} must include ${label}: ${needle}`);
  }
}

function runSystemStatic() {
  const context = {
    window: {},
    navigator: { language: 'zh-CN' },
    console,
  };
  vm.runInNewContext(read('public/system-static.js'), context, {
    filename: 'public/system-static.js',
  });
  return context.window.SUXI_SYSTEM_STATIC || {};
}

function flattenMenu(items = [], result = []) {
  for (const item of Array.isArray(items) ? items : []) {
    result.push(item);
    flattenMenu(item.children || [], result);
  }
  return result;
}

const systemStatic = runSystemStatic();
const menuItems = systemStatic.menuItemDefinitions || [];
const topLevelNames = menuItems.map((item) => item.name);

assertContract(
  topLevelNames.join('|') === '经营工作台|线上数据|运营执行|系统设置',
  `top-level navigation must be 经营工作台 / 线上数据 / 运营执行 / 系统设置, got ${topLevelNames.join(' / ') || '(empty)'}`
);

const revenueMenu = menuItems.find((item) => item.name === '经营工作台') || {};
const revenueChildren = Array.isArray(revenueMenu.children) ? revenueMenu.children : [];
assertContract(
  revenueChildren[0]?.name === '今日经营工作台' && revenueChildren[0]?.path === 'compass',
  'Today operating workbench must remain the first revenue-management child while preserving compass path'
);

const flattenedMenu = flattenMenu(menuItems);
assertContract(
  !revenueChildren.some((item) => item.name === '开店/扩张/投决'),
  'current navigation must not expose the retired opening, expansion, transfer, or investment group'
);

for (const frozenPath of [
  'lifecycle-auxiliary',
  'investment-decision',
  'ai-strategy',
  'ai-simulation',
  'ai-feasibility',
  'opening-overview',
  'opening-checklist',
  'market-evaluation',
  'benchmark-model',
  'collaboration-efficiency',
  'asset-pricing',
  'timing-strategy',
  'decision-board',
  'hotel-image-optimizer',
]) {
  assertContract(
    !flattenedMenu.some((item) => item.path === frozenPath),
    `phase-1 navigation must not expose frozen lifecycle path: ${frozenPath}`
  );
}

const systemMenu = menuItems.find((item) => item.name === '系统设置') || {};
assertContract(
  flattenMenu([systemMenu]).some((item) => item.name === '酒店AI工具箱' && item.path === 'agent-center'),
  'super-admin hotel AI toolbox must remain available under 系统设置 after lifecycle navigation is frozen'
);

const routeSource = read('route/app.php');
for (const frozenRouteGroup of [
  "Route::group('api/lifecycle'",
  "Route::group('api/investment-decision'",
  "Route::group('api/strategy'",
  "Route::group('api/simulation'",
  "Route::group('api/opening'",
  "Route::group('api/expansion'",
  "Route::group('api/transfer'",
]) {
  assertContract(routeSource.includes(frozenRouteGroup), `route/app.php must retain the frozen backend route group: ${frozenRouteGroup}`);
}

const managerMenu = systemStatic.filterVisibleMenuItems(menuItems, {
  is_super_admin: false,
  role_id: 2,
  permissions: {
    can_view_online_data: true,
    can_manage_own_hotels: true,
  },
});
assertContract(
  !flattenMenu(managerMenu).some((item) => item.path === 'agent-center'),
  'ordinary manager navigation must not expose the super-admin agent-center toolbox'
);

includesAll('docs/revenue_ai_core_scope_priority.md', 'core slimming scope baseline', [
  '宿析OS 收益管理智能体 Revenue AI',
  '携程/美团昨日 OTA 数据',
  'OTA 渠道口径',
  '全酒店经营口径',
  '全生命周期辅助',
  '不自动写携程/美团价格',
  'available_room_nights_missing',
  '人工审核、转运营执行意图、执行证据和 ROI 复盘证据',
]);

includesAll('public/index.html', 'Today operating workbench homepage shell', [
  '今日经营工作台',
  "const currentPage = ref('compass')",
  'loadRevenueAiOverview',
  'revenueAiOverview',
]);

includesAll('public/revenue-ai-static.js', 'Revenue AI display helper and scope labels', [
  'buildRevenueAiOverviewEndpoint',
  '/revenue-ai/overview',
  'OTA渠道口径',
  '全酒店口径',
  'ota_room_revenue',
  'ota_room_nights',
  'ota_adr',
  'ota_contribution_revpar',
  'available_room_nights_missing',
  '该动作只更新本地审核状态，不写入携程/美团价格',
  '该动作只记录本地人工执行证据，不写入携程/美团价格',
]);

includesAll('route/app.php', 'authenticated Revenue AI route group', [
  "Route::group('api/revenue-ai'",
  "Route::get('/overview', 'RevenueAi/overview')",
  "Route::post('/price-suggestions/:id/review', 'RevenueAi/reviewPriceSuggestion')",
  "Route::post('/price-suggestions/:id/execution-intent', 'RevenueAi/createPriceSuggestionExecutionIntent')",
  '->middleware(\\app\\middleware\\Auth::class)',
]);

includesAll('app/service/RevenueAiOverviewService.php', 'Revenue AI metric scope and missing-data contract', [
  "'scope' => 'ota'",
  "'date_basis' => 'data_date'",
  "'ota_room_revenue'",
  "'ota_room_nights'",
  "'ota_adr'",
  "'ota_contribution_revpar'",
  "'available_room_nights_missing'",
  "'hotel_required'",
]);

includesAll('app/controller/RevenueAi.php', 'manual review and no OTA write contract', [
  'reviewPriceSuggestion',
  'createPriceSuggestionExecutionIntent',
  "'auto_write_ota' => false",
  "'local_price_updated' => false",
  "'ota_write' => false",
]);

const revenueAiController = read('app/controller/RevenueAi.php');
assertContract(!revenueAiController.includes('->apply('), 'Revenue AI controller must not apply price suggestions directly');
assertContract(
  !revenueAiController.includes('$roomType->base_price') && !revenueAiController.includes("Db::name('room_types')"),
  'Revenue AI controller must not update room_types base prices'
);

const packageJson = JSON.parse(read('package.json'));
assertContract(
  packageJson.scripts?.['verify:core-scope'] === 'node scripts/verify_core_scope_contract.mjs',
  'package.json must expose verify:core-scope'
);
assertContract(
  String(packageJson.scripts?.['verify:p0-guards'] || '').includes('npm run verify:core-scope'),
  'verify:p0-guards must include verify:core-scope'
);

if (failures.length) {
  console.error('Core scope contract failed:');
  for (const failure of failures) console.error(`- ${failure}`);
  process.exit(1);
}

console.log('Core scope contract passed.');
