import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const checks = [];
const onlineDataConcernDir = path.join(root, 'app/controller/concern');
const onlineDataConcernFiles = fs.existsSync(onlineDataConcernDir)
  ? fs.readdirSync(onlineDataConcernDir)
    .filter(file => file.endsWith('.php'))
    .sort()
    .map(file => `app/controller/concern/${file}`)
  : [];
const onlineDataControllerFiles = ['app/controller/OnlineData.php', ...onlineDataConcernFiles];
const readOnlineDataControllerSource = () => onlineDataControllerFiles.map(read).join('\n');

function requireText(file, needle, label) {
  const source = read(file);
  checks.push({
    file,
    label,
    ok: source.includes(needle),
    detail: needle,
  });
}

function requireOnlineDataControllerText(needle, label) {
  const source = readOnlineDataControllerSource();
  checks.push({
    file: onlineDataControllerFiles.join(' + '),
    label,
    ok: source.includes(needle),
    detail: needle,
  });
}

function requireNoText(file, needle, label) {
  const source = read(file);
  checks.push({
    file,
    label,
    ok: !source.includes(needle),
    detail: needle,
  });
}

function requireOrder(file, firstNeedle, secondNeedle, label) {
  const source = read(file);
  const first = source.indexOf(firstNeedle);
  const second = source.indexOf(secondNeedle);
  checks.push({
    file,
    label,
    ok: first >= 0 && second >= 0 && first < second,
    detail: `${firstNeedle} -> ${secondNeedle}`,
  });
}

function requireTextInFiles(files, needle, label) {
  const source = files.map(read).join('\n');
  checks.push({
    file: files.join(' + '),
    label,
    ok: source.includes(needle),
    detail: needle,
  });
}

function requireNoTextInFiles(files, needle, label) {
  const source = files.map(read).join('\n');
  checks.push({
    file: files.join(' + '),
    label,
    ok: !source.includes(needle),
    detail: needle,
  });
}

requireText('public/index.html', 'data-testid="login-username"', 'login username has stable selector');
requireText('public/index.html', 'data-testid="login-password"', 'login password has stable selector');
requireText('public/index.html', 'data-testid="login-submit"', 'login submit has stable selector');
requireText('public/index.html', "requireAppSystemStatic('getRememberedLoginAccount')", 'entry uses extracted remembered login account reader');
requireText('public/index.html', "requireAppSystemStatic('buildLoginRequestPayload')", 'entry uses extracted login payload builder');
requireText('public/index.html', "requireAppSystemStatic('validateLoginRequestPayload')", 'entry uses extracted login validation');
requireText('public/index.html', "requireAppSystemStatic('applyRememberedLoginAccount')", 'entry uses extracted remembered login account writer');
requireText('public/index.html', 'data-testid="open-register"', 'login page exposes self-registration entry selector');
requireText('public/index.html', 'data-testid="register-submit"', 'login page exposes self-registration submit');
requireText('public/index.html', 'data-testid="register-username"', 'login page exposes self-registration fields');
requireText('public/index.html', "request('/auth/register'", 'frontend calls public self-registration API');
requireText('public/index.html', "requireAppSystemStatic('createRegisterForm')", 'entry uses extracted register form builder');
requireText('public/index.html', "requireAppSystemStatic('buildRegisterRequestPayload')", 'entry uses extracted register payload builder');
requireText('public/index.html', "requireAppSystemStatic('validateRegisterRequestPayload')", 'entry uses extracted register validation');
requireText('public/index.html', 'data-testid="app-nav"', 'sidebar nav has stable selector');
requireText('public/index.html', 'data-testid="app-main"', 'main app surface has stable selector');
requireText('public/index.html', ':data-current-page="currentPage"', 'main app surface exposes current page state');
requireNoText('public/index.html', '<link href="font-awesome.min.css" rel="stylesheet">', 'FontAwesome stylesheet must not block core shell first paint');
requireText('public/index.html', "const fontAwesomeStylesheet = 'font-awesome.min.css?v=20260628-static-router-fix';", 'entry keeps explicit versioned FontAwesome idle loader');
requireText('public/index.html', 'window.setTimeout(loadFontAwesomeStylesheet, 1600);', 'FontAwesome icon font loads after core shell first second');
requireText('public/index.html', 'const suxiApp = createApp({', 'entry keeps Vue app instance available before mount');
requireText('public/index.html', 'const renderSuxiStartupError = (error) => {', 'entry renders startup/runtime initialization failures explicitly');
requireText('public/index.html', 'suxiApp.config.errorHandler = (error) => {', 'entry wires Vue runtime errors to explicit startup error surface');
requireText('public/index.html', ".replace(/[<>&\"']/g", 'startup error surface escapes injected error text');
requireText('public/index.html', "const stack = String(error?.stack || '').split('\\n').slice(0, 8).join('\\n');", 'startup error surface keeps bounded stack evidence');
requireText('public/index.html', "[String(error?.message || error || 'unknown startup error'), stack].filter(Boolean).join('\\n')", 'startup error surface combines message and stack evidence');
requireText('public/index.html', "if (appRoot.dataset.startupErrorRendered === '1') return;", 'startup error surface is idempotent');
requireText('public/index.html', "appRoot.dataset.startupErrorRendered = '1';", 'startup error surface marks rendered state');
requireText('public/index.html', "if (!u || typeof u !== 'object') return false;", 'user search skips invalid user rows');
requireText('public/index.html', "const username = String(u.username || '');", 'user search normalizes username before matching');
requireText('public/index.html', "const realname = String(u.realname || '');", 'user search normalizes real name before matching');
requireText('public/index.html', ':key="u?.id || index"', 'user table keeps stable fallback key');
requireText('public/index.html', "{{ u?.username || '-' }}", 'user table renders missing username safely');
requireText('public/index.html', "String(u?.status) === '1'", 'user table renders missing status safely');
requireText('public/index.html', 'v-if="u && (user?.is_super_admin', 'user table actions require a valid row');
requireText('public/index.html', 'v-for="(u, index) in logUsers"', 'operation log user filter exposes row index fallback');
requireText('public/index.html', ':value="u?.id || \'\'"', 'operation log user filter handles missing ids');
requireText('public/index.html', "{{ u?.realname || u?.username || '-' }}", 'operation log user filter handles missing names');
requireText('public/index.html', 'vue.global.prod.js?v=', 'entry versions the local Vue runtime');
requireText('public/index.html', 'system-static.js?v=', 'entry versions the system static helper');
requireText('public/index.html', 'ctrip-static.js?v=20260703-ai-estimated-nights', 'entry bumps Ctrip static helper version after AI estimated room-night sorter change');
requireText('public/index.html', 'meituan-static.js?v=20260703-ai-estimated-nights', 'entry keeps Meituan static helper cache version aligned with the Ctrip AI estimated room-night update');
requireText('public/index.html', ':data-testid="menuTestId(item)"', 'top-level menu uses test id helper');
requireText('public/index.html', ':data-testid="menuTestId(child)"', 'second-level menu uses test id helper');
requireText('public/index.html', ':data-testid="menuTestId(grandChild)"', 'third-level menu uses test id helper');
requireText('public/index.html', 'filterVisibleMenuItems(menuItems.value, user.value)', 'entry uses extracted visible menu filter');
requireText('public/system-static.js', 'const resolveMenuItems', 'system static module resolves menu config keys');
requireText('public/system-static.js', 'const filterVisibleMenuItems', 'system static module filters visible menu items');
requireText('public/index.html', 'buildHotelPlatformBindingRowsStatic', 'entry uses extracted hotel platform binding rows builder');
requireText('public/system-static.js', 'const buildHotelPlatformAccountRow', 'system static builds hotel platform account rows');
requireText('public/system-static.js', 'const buildHotelPlatformBindingRows', 'system static builds hotel platform binding row groups');
requireText('public/index.html', 'const dualOtaEffectiveStoreScope = computed(() => dualOtaResolveStoreScope(dualOtaSelectedStoreScope.value));', 'AI workbench resolves default dual-platform scope by current hotel platform readiness');
requireText('public/index.html', "if (requested !== 'combined') return requested;", 'AI workbench preserves manual single-platform diagnostics instead of auto-collapsing explicit platform choices');
requireText('public/index.html', "return readyScopes.length === 1 ? readyScopes[0] : 'combined';", 'AI workbench still auto-collapses only the default dual-platform view when exactly one OTA platform is ready');
requireText('public/index.html', '<span class="dual-ota-context-item dual-ota-context-item-store">', 'AI workbench keeps the current-hotel selector in the platform scope row');
requireNoText('public/index.html', '<div class="dual-ota-context-strip">', 'AI workbench does not render the current-hotel selector in a separate row above platform buttons');
requireOrder('public/index.html', '<span class="dual-ota-context-item dual-ota-context-item-store">', '<div class="dual-ota-store-scope-list" role="list" aria-label="OTA平台选择">', 'AI workbench aligns current-hotel selector before the platform switch buttons in the same strip');
requireText('public/index.html', '<div class="dual-ota-store-scope-list" role="list" aria-label="OTA平台选择">', 'AI workbench keeps the platform switch buttons in the store-scope strip');
requireNoText('public/index.html', '<div class="dual-ota-store-scope-head">', 'AI workbench does not render the platform switch title or binding notice in the store-scope strip');
requireNoText('public/index.html', '<div class="dual-ota-store-scope-list" role="list" aria-label="平台切换">', 'AI workbench does not keep the removed platform switch title as the store-scope list label');
requireNoText('public/index.html', '<span v-if="dualOtaSelectedStoreScopeNotice">{{ dualOtaSelectedStoreScopeNotice }}</span>', 'AI workbench does not show the selected single-platform binding notice in the store-scope strip');
requireNoText('public/index.html', 'const dualOtaSelectedStoreScopeNotice = computed(() => {', 'AI workbench does not keep the removed single-platform binding notice computed state');
requireNoText('public/index.html', 'const dualOtaStoreScopeAutoNotice = computed(() => {', 'AI workbench does not keep the removed store-scope auto notice computed state');
requireNoText('public/index.html', 'return `未绑定${label}账户`;', 'AI workbench does not keep the removed store-scope unbound-account notice text');
requireNoText('public/index.html', "return `当前门店${missingPlatform}未达到可采集状态，已按${activePlatform}口径展示`;", 'AI workbench does not keep the removed store-scope auto-collapse notice text');
requireText('public/index.html', 'const dualOtaHotelSearchCounts = ref(readDualOtaHotelSearchCounts());', 'AI workbench keeps local hotel search counts for current-hotel ordering');
requireText('public/index.html', 'const dualOtaCurrentHotelOptions = computed(() => {', 'AI workbench current-hotel selector can order hotels by local search count');
requireText('public/index.html', '<option v-for="hotel in dualOtaCurrentHotelOptions" :key="hotel.id" :value="hotel.id">{{ hotel.name }}</option>', 'AI workbench current-hotel selector renders the ordered hotel list');
requireText('public/index.html', 'const resolveDefaultReportHotelId = () => {', 'AI workbench resolves a default current hotel before falling back to all hotels');
requireText('public/index.html', "const boundHotelId = String(user.value?.hotel_id || '').trim();\n                if (boundHotelId && reportHotelOptionExists(boundHotelId)) return boundHotelId;", 'AI workbench defaults current hotel to the account-bound main hotel when available');
requireText('public/index.html', "const firstPermittedHotelId = String(permittedHotels.value?.[0]?.id || '').trim();\n                if (firstPermittedHotelId) return firstPermittedHotelId;", 'AI workbench defaults to the first account-permitted hotel when no explicit main hotel is bound');
requireText('public/index.html', "if (!dualOtaSuppressHotelSearchRecord && currentPage.value === 'ai-workbench') {\n                    recordDualOtaHotelSearch(filterReportHotel.value);\n                }", 'AI workbench records only manual current-hotel searches, not default binding selection');
requireNoText('public/index.html', 'filterReportHotel.value = permittedHotels.value[0].id;', 'AI workbench default hotel selection is no longer limited to non-admin single-hotel users');
requireText('public/index.html', 'const dualOtaMetricNoteText = (note = \'\') => {', 'AI workbench translates metric source notes before rendering');
requireText('public/index.html', 'const dualOtaIsCtripTrafficPendingWindow = () => {', 'AI workbench has an explicit early-morning Ctrip traffic pending window');
requireText('public/index.html', "const dualOtaCtripTrafficPendingNote = '凌晨0-8点携程流量数据待更新';", 'AI workbench labels early-morning missing Ctrip traffic as pending update');
requireText('public/index.html', "return dualOtaMetric(label, '待更新', dualOtaCtripTrafficPendingNote, 'warning');", 'AI workbench renders missing Ctrip traffic as pending during the early-morning window');
requireText('public/index.html', 'const dualOtaRowIsPlatformSelf = (row = {}) => {', 'AI workbench can identify the Ctrip platform self row when the OTA detail name differs from the system hotel name');
requireText('public/index.html', 'return rows.find(dualOtaRowMatchesSelectedHotel) || rows.find(dualOtaRowIsPlatformSelf) || null;', 'AI workbench uses the Ctrip platform self row after explicit current-hotel matching fails');
requireText('public/index.html', "dualOtaMissingMetric('销售额', '当前门店携程明细行未返回')", 'AI workbench current-store Ctrip column keeps the competitor-average metric shape when the self row is missing');
requireText('public/index.html', "dualOtaMetric('竞争力指数', sciValue, '当前门店携程综合竞争力'", 'AI workbench current-store Ctrip column shows own competitive index in the same slot as competitor average');
requireText('public/index.html', "dualOtaMissingMetric('营收', '当前门店美团明细行未返回')", 'AI workbench current-store Meituan column keeps the competitor-average metric shape when the self row is missing');
requireText('public/index.html', "dualOtaMetric('浏览→支付', payConversion, '当前门店美团支付转化'", 'AI workbench current-store Meituan column shows own conversion in the same slot as competitor average');
requireText('public/index.html', "scope === 'ctrip' ? '携程本店指标' : (scope === 'meituan' ? '美团本店指标' : 'OTA本店指标')", 'AI workbench labels the left column as own-store metrics, not a query-status panel');
requireText('public/index.html', "title: String(filterReportHotel.value || '').trim() ? '门店数据' : '筛选数据',", 'AI workbench labels the own-store column as store data');
requireText('public/index.html', '<button type="button" :class="[\'dual-ota-compare-toggle\', dualOtaCompareEnabled ? \'is-active\' : \'\']"', 'AI workbench places the same-period comparison switch before platform buttons');
requireText('public/index.html', 'const dualOtaCompareEnabled = ref(false);', 'AI workbench same-period comparison is off by default');
requireText('public/index.html', '<small v-if="dualOtaCompareEnabled" :title="metric.note">{{ dualOtaMetricComparisonText(metric) }}</small>', 'AI workbench system metric footnotes show previous-period comparison only when enabled');
requireText('public/index.html', '<small v-else class="dual-ota-system-metric-spacer" aria-hidden="true">&nbsp;</small>', 'AI workbench keeps metric card layout stable when comparison is disabled');
requireText('public/index.html', 'const toggleDualOtaCompare = () => {', 'AI workbench exposes a local comparison display toggle');
requireText('public/style.css', 'grid-template-columns: minmax(260px, 1fr) minmax(420px, 500px);', 'AI workbench keeps current-hotel selector and platform controls in a stable two-column strip');
requireText('public/style.css', 'grid-template-columns: repeat(4, minmax(0, 1fr)) !important;', 'AI workbench keeps comparison switch and three platform buttons on one row');
requireText('public/index.html', 'const dualOtaRatePreviousExtra = (value) => {', 'AI workbench normalizes previous-period rate metrics before comparison');
requireText('public/index.html', 'ctripLatestComparison.value = payload?.rank?.comparison || null;', 'AI workbench stores Ctrip latest comparison snapshot from backend response');
requireText('public/index.html', "const latestRange = isCompassDataPage() ? String(dualOtaSelectedRange.value || '').trim() : '';", 'AI workbench sends selected range when loading latest Ctrip data');
requireText('public/index.html', "if (latestRange) params.append('range', latestRange);", 'AI workbench appends selected range to latest Ctrip data request');
requireText('public/index.html', "const res = await request(`/online-data/ctrip/latest${query ? '?' + query : ''}`);", 'AI workbench keeps Ctrip latest request query-driven after adding range');
requireText('public/index.html', "const summaryRange = isCompassDataPage() ? String(dualOtaSelectedRange.value || '').trim() : '';", 'AI workbench sends selected range when loading Meituan competitor summary');
requireText('public/index.html', "if (summaryRange) params.append('range', summaryRange);", 'AI workbench appends selected range to Meituan competitor summary request');
requireText('public/index.html', 'loadCompetitorSummary({ requireCompass: true, force: true });', 'AI workbench refreshes Meituan competitor summary after changing top range');
requireText('public/index.html', 'class="dual-ota-context-select dual-ota-hotel-select"', 'AI workbench current-hotel select uses the dedicated readable select styling');
requireText('public/style.css', 'text-align-last: center;', 'AI workbench current-hotel select centers the displayed selected store');
requireText('public/index.html', 'const refreshDualOtaWorkbenchData = async ({ allowFetch = false, silent = true } = {}) => {', 'AI workbench has one store/range/platform refresh entrypoint');
requireText('public/index.html', 'refreshDualOtaWorkbenchData({ allowFetch: true, silent: false });', 'AI workbench store/range/platform changes trigger data refresh and necessary fetch prompts');
requireText('public/index.html', "const ctripLoaded = await loadLatestCtripData({ silent: true, hotelId });", 'AI workbench reads stored Ctrip snapshot before deciding to fetch');
requireText('public/index.html', 'await dualOtaEnsureCtripWorkbenchData({ hotelId, silent });', 'AI workbench falls back to existing Ctrip fetch only when stored snapshot is missing');
requireText('public/index.html', 'dualOtaApplyCtripYesterdayForm();', 'AI workbench Ctrip auto fetch is pinned to yesterday instead of stale manual dates');
requireText('public/index.html', 'dualOtaApplyMeituanRangeToForm(range);', 'AI workbench syncs selected range into the existing Meituan fetch form');
requireText('public/index.html', "if (!dualOtaMarkWorkbenchFetchAttempted('ctrip', hotelId, range))", 'AI workbench records Ctrip fetch attempts once per store and range');
requireText('public/index.html', "if (!dualOtaMarkWorkbenchFetchAttempted('meituan', hotelId, range))", 'AI workbench records Meituan fetch attempts once per store and range');
requireText('public/index.html', 'const ctripPrevious = ctripRow ? (dualOtaCtripPreviousRow(ctripRow) || {}) : {};', 'AI workbench combined current-store cards read previous-period Ctrip row');
requireText('public/index.html', 'const meituanPrevious = meituanRow ? (dualOtaMeituanPreviousRow(meituanRow) || {}) : {};', 'AI workbench combined current-store cards read previous-period Meituan row');
requireText('public/index.html', 'dualOtaMetricPreviousExtra(meituanPrevious.orderCount))', 'AI workbench combined current-store Meituan order card exposes previous-period comparison');
requireOnlineDataControllerText('private function normalizeCtripLatestRange(string $range): string', 'Ctrip latest endpoint normalizes AI workbench range');
requireOnlineDataControllerText('private function buildCtripLatestRankComparison(array $latest, string $hotelId, $currentUser, array $columns, string $range): ?array', 'Ctrip latest endpoint builds previous-period rank comparison');
requireOnlineDataControllerText('$targetDate = $this->resolveCtripLatestTargetDate($range);', 'Ctrip latest endpoint applies selected daily target date');
requireOnlineDataControllerText('private function normalizeMeituanCompetitorSummaryRange(string $range): string', 'Meituan competitor summary endpoint normalizes AI workbench range');
requireOnlineDataControllerText('private function buildMeituanCompetitorSummaryComparison(array $latest, string $hotelId, $currentUser, array $context, string $range): ?array', 'Meituan competitor summary endpoint builds previous-period comparison');
requireOnlineDataControllerText('$targetDate = $this->resolveMeituanCompetitorSummaryTargetDate($range);', 'Meituan competitor summary endpoint applies selected daily target date');
requireText('public/index.html', "if (range === 'realtime') return '昨日';", 'AI workbench realtime metrics compare with yesterday');
requireText('public/index.html', "if (range === 'yesterday') return '前日';", 'AI workbench yesterday metrics compare with the day before yesterday');
requireText('public/index.html', "if (range === '7d') return '前7天';", 'AI workbench seven-day metrics compare with the previous seven days');
requireText('public/index.html', "if (range === '30d') return '上一个30天';", 'AI workbench thirty-day metrics compare with the previous thirty days');
requireText('public/index.html', "return `相对${period}：未返回`;", 'AI workbench does not invent previous-period movement when comparison data is missing');
requireNoText('public/index.html', "title: String(filterReportHotel.value || '').trim() ? '当前门店' : '当前筛选',", 'AI workbench no longer labels the own-store metrics column as current-store status');
requireNoText('public/index.html', "dualOtaMetric('查询门店', dualOtaSelectedHotelLabel.value, '当前表单门店')", 'AI workbench current-store column no longer renders a query-store status card');
requireNoText('public/index.html', "dualOtaMissingMetric('携程明细行', '本次快照按当前表单门店查询，但未返回本店明细行')", 'AI workbench current-store column no longer renders a Ctrip detail-row status card');
requireNoText('public/index.html', "dualOtaMetric('口径', '携程OTA', '不是全酒店经营口径', 'warning')", 'AI workbench current-store column no longer renders OTA-scope explanation cards as metrics');
requireText('public/index.html', 'const dualOtaPlatformRevenueTitle = computed(() => {', 'AI workbench revenue structure title follows the effective platform scope');
requireText('public/index.html', '<h2>{{ dualOtaPlatformRevenueTitle }}</h2>', 'AI workbench renders platform-aware revenue title');
requireText('public/index.html', '<div v-if="platform.metrics && platform.metrics.length" class="dual-ota-platform-metrics">', 'AI workbench renders single-platform revenue as revenue plus order/night/ADR metrics');
requireText('public/index.html', 'dualOtaPlatformRevenuePlatforms.length === 1 ? \'is-single\' : \'\'', 'AI workbench uses a single-column revenue structure when one OTA platform is selected');
requireText('public/index.html', '<div v-if="dualOtaEffectiveStoreScope === \'combined\'" class="dual-ota-contribution" data-testid="dual-ota-platform-contribution-bar">', 'AI workbench hides the 100 percent contribution bar when a single OTA platform is selected');
requireText('public/dual-ota-home-static.js', "title: '曝光正常',", 'AI workbench loss-chain exposure explanation uses clear wording');
requireText('public/dual-ota-home-static.js', "activeRange: 'yesterday',", 'AI workbench defaults the top time range to yesterday');
requireText('public/dual-ota-home-static.js', "{ name: '携程竞争圈数据', reason: '' }", 'AI workbench bottom module labels Ctrip competitor-circle data explicitly');
requireText('public/dual-ota-home-static.js', "{ name: '美团竞争圈数据', reason: '' }", 'AI workbench bottom module labels Meituan competitor-circle data explicitly');
requireNoText('public/dual-ota-home-static.js', "{ name: '订单来了', reason: '' }", 'AI workbench bottom module no longer labels competitor data as order-coming module');
requireNoText('public/dual-ota-home-static.js', "{ name: '价格监控', reason: '' }", 'AI workbench bottom module no longer labels Meituan competitor data as price monitoring');
requireNoText('public/dual-ota-home-static.js', "title: '入口流量够',", 'AI workbench no longer uses ambiguous exposure explanation wording');
requireNoText('public/index.html', '<div v-if="dualOtaSystemOverviewSourceNote" class="dual-ota-boundary-note">', 'AI workbench system overview does not render the redundant scope explanation strip');
requireNoText('public/index.html', 'const dualOtaSystemOverviewSourceNote = computed(() => {', 'AI workbench does not keep the removed redundant scope explanation generator');
requireText('public/style.css', 'box-shadow: inset 0 3px 0 rgba(139, 86, 49, .40) !important;', 'AI workbench warning loss nodes use a subtle status rule instead of a full tinted card');
requireText('public/style.css', 'color: #8B5631 !important;', 'AI workbench deferred module and risk accents use coffee palette');
requireNoText('public/index.html', 'const meituanIdentifierMissing = [', 'hotel platform binding row group logic is not re-inlined');
requireText('public/system-static.js', "target: 'profile-login'", 'system static keeps profile login direct target metadata');
requireText('public/system-static.js', "target: 'sync-logs'", 'system static keeps sync logs direct target metadata');
requireText('public/index.html', "requireAppSystemStatic('operationExecutionStatusLabel')", 'entry uses extracted operation execution status labels');
requireText('public/index.html', "requireAppSystemStatic('operationClosureStatusClass')", 'entry uses extracted operation closure status classes');
requireText('public/index.html', "requireAppSystemStatic('operationEffectStatusClass')", 'entry uses extracted operation effect status classes');
requireText('public/index.html', "requireAppSystemStatic('operationValue')", 'entry uses extracted operation value formatter');
requireText('public/index.html', "requireAppSystemStatic('operationMetricRows')", 'entry uses extracted operation metric row builder');
requireText('public/index.html', "requireAppSystemStatic('operationActionTarget')", 'entry uses extracted operation action target formatter');
requireText('public/index.html', "requireAppSystemStatic('deferUiTask')", 'entry uses extracted deferred UI task scheduler');
requireText('public/index.html', "requireAppSystemStatic('scheduleDelayedPageTask')", 'entry uses extracted delayed page task scheduler');
requireText('public/index.html', "requireAppSystemStatic('deferFrameTask')", 'entry uses extracted frame task scheduler');
requireText('public/index.html', "requireAppSystemStatic('loadSidebarCollapsedPreference')", 'entry uses extracted sidebar preference reader');
requireText('public/index.html', "requireAppSystemStatic('persistSidebarCollapsedPreference')", 'entry uses extracted sidebar preference writer');
requireText('public/index.html', "requireAppSystemStatic('isCompactViewport')", 'entry uses extracted compact viewport detector');
requireText('public/index.html', "requireAppSystemStatic('toNumber')", 'entry uses extracted numeric parser');
requireText('public/index.html', "requireAppSystemStatic('safeDivide')", 'entry uses extracted safe divide helper');
requireText('public/index.html', "requireAppSystemStatic('formatNumber')", 'entry uses extracted number formatter');
requireText('public/index.html', "requireAppSystemStatic('formatDate')", 'entry uses extracted date formatter');
requireText('public/index.html', "requireAppSystemStatic('formatConfigDate')", 'entry uses extracted config date formatter');
requireText('public/index.html', "requireAppSystemStatic('formatKnowledgeJson')", 'entry uses extracted knowledge JSON formatter');
requireText('public/index.html', "requireAppSystemStatic('formatCommentTime')", 'entry uses extracted comment time formatter');
requireText('public/index.html', "requireAppSystemStatic('formatPaybackMonth')", 'entry uses extracted payback month formatter');
requireText('public/index.html', "requireAppSystemStatic('formatCurrency')", 'entry uses extracted currency formatter');
requireText('public/index.html', "requireAppSystemStatic('formatPercent')", 'entry uses extracted percent formatter');
requireText('public/index.html', "requireAppSystemStatic('formatWan')", 'entry uses extracted ten-thousand formatter');
requireText('public/index.html', "requireAppSystemStatic('normalizeLocale')", 'entry uses extracted locale normalizer');
requireText('public/index.html', "requireAppSystemStatic('getInitialLocale')", 'entry uses extracted initial locale resolver');
requireText('public/index.html', "requireAppSystemStatic('createAiModelConfigText')", 'entry uses extracted AI model config text factory');
requireText('public/index.html', "requireAppSystemStatic('revenueConcentration')", 'entry uses extracted revenue concentration helper');
requireText('public/index.html', "requireAppSystemStatic('visitConcentration')", 'entry uses extracted visit concentration helper');
requireText('public/index.html', "requireAppSystemStatic('isExpansionStaticPage')", 'entry uses extracted expansion static page detector');
requireText('public/index.html', "requireAppSystemStatic('isSimulationStaticPage')", 'entry uses extracted simulation static page detector');
requireText('public/system-static.js', 'const operationExecutionStatusLabel', 'system static owns operation execution status labels');
requireText('public/system-static.js', 'const operationClosureStatusClass', 'system static owns operation closure status classes');
requireText('public/system-static.js', 'const operationEffectStatusClass', 'system static owns operation effect status classes');
requireText('public/system-static.js', 'const operationValue', 'system static owns operation value formatter');
requireText('public/system-static.js', 'const operationMetricRows', 'system static owns operation metric row builder');
requireText('public/system-static.js', 'const operationActionTarget', 'system static owns operation action target formatter');
requireText('public/system-static.js', 'const deferUiTask', 'system static owns deferred UI task scheduler');
requireText('public/system-static.js', 'const scheduleDelayedPageTask', 'system static owns delayed page task scheduler');
requireText('public/system-static.js', 'const deferFrameTask', 'system static owns frame task scheduler');
requireText('public/system-static.js', 'const loadSidebarCollapsedPreference', 'system static owns sidebar preference reader');
requireText('public/system-static.js', 'const persistSidebarCollapsedPreference', 'system static owns sidebar preference writer');
requireText('public/system-static.js', 'const isCompactViewport', 'system static owns compact viewport detector');
requireText('public/system-static.js', 'const toNumber', 'system static owns numeric parser');
requireText('public/system-static.js', 'const safeDivide', 'system static owns safe divide helper');
requireText('public/system-static.js', 'const formatNumber', 'system static owns number formatter');
requireText('public/system-static.js', 'const formatDate', 'system static owns date formatter');
requireText('public/system-static.js', 'const formatConfigDate', 'system static owns config date formatter');
requireText('public/system-static.js', 'const formatKnowledgeJson', 'system static owns knowledge JSON formatter');
requireText('public/system-static.js', 'const formatCommentTime', 'system static owns comment time formatter');
requireText('public/system-static.js', 'const formatPaybackMonth', 'system static owns payback month formatter');
requireText('public/system-static.js', 'const formatCurrency', 'system static owns currency formatter');
requireText('public/system-static.js', 'const formatPercent', 'system static owns percent formatter');
requireText('public/system-static.js', 'const formatWan', 'system static owns ten-thousand formatter');
requireText('public/system-static.js', 'const normalizeLocale', 'system static owns locale normalizer');
requireText('public/system-static.js', 'const getInitialLocale', 'system static owns initial locale resolver');
requireText('public/system-static.js', 'const createAiModelConfigText', 'system static owns AI model config text factory');
requireText('public/system-static.js', 'const revenueConcentration', 'system static owns revenue concentration helper');
requireText('public/system-static.js', 'const visitConcentration', 'system static owns visit concentration helper');
requireText('public/system-static.js', 'const isExpansionStaticPage', 'system static owns expansion static page detector');
requireText('public/system-static.js', 'const isSimulationStaticPage', 'system static owns simulation static page detector');
requireText('public/index.html', "requireCtripStatic('runCtripBrowserCaptureFlow')", 'entry uses extracted Ctrip browser capture flow runner');
requireText('app/service/BrowserProfileCaptureRequestService.php', 'final class BrowserProfileCaptureRequestService', 'browser Profile capture request planning lives in a focused service');
requireOnlineDataControllerText('BrowserProfileCaptureRequestService::buildMeituanPlan(', 'OnlineData delegates Meituan browser Profile capture request planning');
requireOnlineDataControllerText('BrowserProfileCaptureRequestService::buildCtripBasePlan(', 'OnlineData delegates Ctrip browser Profile capture base request planning');
requireOnlineDataControllerText('BrowserProfileCaptureRequestService::buildCtripAutoArgs(', 'OnlineData delegates Ctrip browser Profile auto-fetch base arguments');
requireOnlineDataControllerText('BrowserProfileCaptureRequestService::buildMeituanAutoArgs(', 'OnlineData delegates Meituan browser Profile auto-fetch base arguments');
requireOnlineDataControllerText('BrowserProfileCaptureRequestService::resolveNodeBinary(', 'OnlineData delegates browser capture Node binary resolution');
requireOnlineDataControllerText('BrowserProfileCaptureRequestService::resolveChromePath(', 'OnlineData delegates browser capture Chrome path resolution');
requireOnlineDataControllerText('BrowserProfileCaptureRequestService::normalizeProfileSections(', 'OnlineData delegates browser Profile section normalization');
requireOnlineDataControllerText('BrowserProfileCaptureRequestService::safeFilePart(', 'OnlineData delegates browser capture safe file keys');
requireNoText('app/controller/OnlineData.php', 'resolveMeituanCaptureNodeBinary', 'browser capture Node resolution is not wrapped in OnlineData');
requireNoText('app/controller/OnlineData.php', 'resolveMeituanCaptureChromePath', 'browser capture Chrome resolution is not wrapped in OnlineData');
requireNoText('app/controller/OnlineData.php', 'normalizeProfileCaptureSections', 'browser Profile section normalization is not wrapped in OnlineData');
requireNoText('app/controller/OnlineData.php', 'safeMeituanCaptureFilePart', 'browser capture safe file keys are not wrapped in OnlineData');
requireText('public/ctrip-static.js', 'const buildCtripBrowserCaptureTargetContext', 'Ctrip static builds browser capture target context');
requireText('public/ctrip-static.js', 'const buildCtripBrowserCapturePayload', 'Ctrip static builds browser capture payloads');
requireText('public/ctrip-static.js', 'const buildCtripBrowserCaptureRequestContext', 'Ctrip static builds browser capture request context');
requireText('public/ctrip-static.js', 'const normalizeCtripBrowserCaptureErrorResult', 'Ctrip static normalizes browser capture errors');
requireText('public/ctrip-static.js', 'const runCtripBrowserCaptureFlow', 'Ctrip static runs browser capture flow');
requireText('public/index.html', "requireCtripStatic('runCtripFetchDataFlow')", 'entry uses extracted Ctrip fetch flow runner');
requireText('public/index.html', "requireCtripStatic('isCtripRankingFormAlignedWithConfig')", 'entry uses extracted Ctrip ranking config alignment guard');
requireText('public/ctrip-static.js', 'const buildCtripFetchDateRange', 'Ctrip static builds fetch date ranges');
requireText('public/ctrip-static.js', 'const buildCtripFetchRequestBody', 'Ctrip static builds fetch request bodies');
requireText('public/ctrip-static.js', 'const buildCtripFetchRequestContext', 'Ctrip static builds fetch request context');
requireText('public/ctrip-static.js', 'const runCtripFetchDataFlow', 'Ctrip static runs fetch flow');
requireText('public/ctrip-static.js', 'const isCtripRankingFormAlignedWithConfig', 'Ctrip static skips redundant ranking config application');
requireText('public/index.html', "requireCtripStatic('buildLatestCtripSnapshotModel')", 'entry uses extracted Ctrip latest snapshot model builder');
requireText('public/ctrip-static.js', 'const buildLatestCtripSnapshotModel', 'Ctrip static builds latest snapshot models');
requireText('public/index.html', "requireCtripStatic('runCtripTrafficFetchFlow')", 'entry uses extracted Ctrip traffic fetch flow runner');
requireText('public/ctrip-static.js', 'const buildCtripTrafficFetchRequestBody', 'Ctrip static builds traffic fetch request bodies');
requireText('public/ctrip-static.js', 'const runCtripTrafficFetchFlow', 'Ctrip static runs traffic fetch flow');
requireText('public/index.html', "requireCtripStatic('runCtripOverviewFetchFlow')", 'entry uses extracted Ctrip overview fetch flow runner');
requireText('public/ctrip-static.js', 'const buildCtripOverviewFetchRequestBody', 'Ctrip static builds overview fetch request bodies');
requireText('public/ctrip-static.js', 'const runCtripOverviewFetchFlow', 'Ctrip static runs overview fetch flow');
requireText('public/index.html', "requireCtripStatic('runCtripAdsFetchFlow')", 'entry uses extracted Ctrip ads fetch flow runner');
requireText('public/ctrip-static.js', 'const buildCtripAdsFetchRequestBody', 'Ctrip static builds ads fetch request bodies');
requireText('public/ctrip-static.js', 'const runCtripAdsFetchFlow', 'Ctrip static runs ads fetch flow');
requireText('public/ctrip-static.js', 'const buildCtripCookieApiFetchRequestBody', 'Ctrip static builds Cookie API fetch request bodies');
requireText('public/index.html', "requireCtripStatic('runCtripCookieApiCaptureFlow')", 'entry uses extracted Ctrip Cookie API capture flow runner');
requireText('public/ctrip-static.js', 'const runCtripCookieApiCaptureFlow', 'Ctrip static runs Cookie API capture flow');
requireText('public/index.html', "sortCtripTable('aiEstimatedTotalRoomNights')", 'Ctrip sales table sorts the AI estimated room-night column by its own derived field');
requireText('public/index.html', 'hotel.aiEstimatedTotalRoomNights ||', 'Ctrip sales table renders AI estimated room nights instead of reusing totalOrderNum');
requireText('public/index.html', 'const ctripAiEstimatedRoomNights = (row = {}) => {', 'Ctrip display rows derive AI estimated total room nights for old snapshots');
requireText('public/index.html', 'const ctripTargetHotelOptions = computed(() => {', 'Ctrip manual target hotel list is filtered to configured Ctrip data sources');
requireText('public/index.html', '<option v-for="hotel in ctripTargetHotelOptions" :key="hotel.id" :value="hotel.id">', 'Ctrip manual target selects do not list unbound hotels');
requireText('public/index.html', 'const meituanTargetHotelOptions = computed(() => {', 'Meituan manual target hotel list is filtered to configured Meituan data sources');
requireText('public/index.html', '<option v-for="hotel in meituanTargetHotelOptions" :key="hotel.id" :value="hotel.id">{{ hotel.name }}</option>', 'Meituan manual target selects do not list unbound hotels');
requireText('public/index.html', '<span class="text-xs text-gray-400">仅显示已配置酒店</span>', 'manual OTA target hotel helper text matches the filtered list behavior');
requireText('public/ctrip-static.js', "if (field === 'aiEstimatedTotalRoomNights') return row.aiEstimatedTotalRoomNights || 0;", 'Ctrip static sorter supports AI estimated room nights');
requireText('app/controller/concern/BusinessDisplayConcern.php', "'aiEstimatedTotalRoomNights' => \$this->ctripAiEstimatedTotalRoomNights(\$bookOrderNum, \$hotelSeed),", 'Ctrip backend display rows expose AI estimated room nights from booking orders');
requireText('app/controller/concern/BusinessDisplayConcern.php', '$ratio = 1.15 + (($hash % 21) / 100);', 'Ctrip backend AI estimated room-night ratio stays within the requested 1.15 to 1.35 band');
requireText('app/controller/concern/OnlineDataHistoryConcern.php', 'findLatestCtripRankRowsWithTraffic($latest, $hotelId, $currentUser, $columns)', 'Ctrip latest rank display falls back to the newest rank batch with traffic when the latest batch has no traffic fields');
requireText('app/controller/concern/OnlineDataHistoryConcern.php', "'reason' => 'latest_rank_without_traffic'", 'Ctrip latest traffic fallback exposes an explicit reason');
requireText('app/controller/concern/OnlineDataHistoryConcern.php', '当前最新批次未返回流量字段，已展示最近一组有流量的携程竞争圈数据。', 'Ctrip latest traffic fallback exposes source notice instead of silently mixing data');
requireText('app/controller/concern/OnlineDataHistoryConcern.php', 'ctripLatestBatchKey(array $row, array $columns, bool $includeSystemHotel): string', 'Ctrip latest traffic fallback groups rows by capture batch');
requireText('public/index.html', "requireCtripStatic('isCtripAdsApiUrl')", 'entry uses extracted Ctrip ads URL guard');
requireText('public/index.html', "requireCtripStatic('createCtripConfigForm')", 'entry uses extracted Ctrip config default form builder');
requireText('public/index.html', "requireCtripStatic('runCtripConfigSaveFlow')", 'entry uses extracted Ctrip config save flow runner');
requireText('public/index.html', "requireCtripStatic('runCtripManualTabSwitch')", 'entry uses extracted Ctrip manual tab switch helper');
requireText('public/ctrip-static.js', 'const createCtripConfigForm', 'Ctrip static builds config default forms');
requireText('public/ctrip-static.js', 'const buildCtripConfigSavePayload', 'Ctrip static builds config save payloads');
requireText('public/ctrip-static.js', 'const validateCtripConfigSaveInput', 'Ctrip static validates config save inputs');
requireText('public/ctrip-static.js', 'const runCtripConfigSaveFlow', 'Ctrip static runs config save flow');
requireText('public/ctrip-static.js', 'const runCtripManualTabSwitch = async', 'Ctrip static runs manual tab switch orchestration');
requireText('public/index.html', "requireCtripStatic('createCtripProfileFieldForm')", 'entry uses extracted Ctrip Profile field default form builder');
requireText('public/index.html', "requireCtripStatic('buildCtripProfileFieldSmartDefaults')", 'entry uses extracted Ctrip Profile field smart defaults builder');
requireText('public/index.html', "requireCtripStatic('buildCtripProfileFieldSavePayload')", 'entry uses extracted Ctrip Profile field save payload builder');
requireText('public/index.html', "requireCtripStatic('buildCtripProfileFieldSampleHelpers')", 'entry uses extracted Ctrip Profile field sample helpers');
requireText('public/index.html', "requireCtripStatic('buildCtripProfileFieldDerivationHelpers')", 'entry uses extracted Ctrip Profile field derivation helpers');
requireText('public/index.html', "requireCtripStatic('normalizeCtripProfileFieldVerificationStatus')", 'entry uses extracted Ctrip Profile field verification status helper');
requireText('public/ctrip-static.js', 'const createCtripProfileFieldForm', 'Ctrip static builds Profile field default forms');
requireText('public/ctrip-static.js', 'const buildCtripProfileFieldSmartDefaults', 'Ctrip static builds Profile field smart defaults');
requireText('public/ctrip-static.js', 'const buildCtripProfileFieldSavePayload', 'Ctrip static builds Profile field save payloads');
requireText('public/ctrip-static.js', 'const buildCtripProfileFieldSampleHelpers', 'Ctrip static builds Profile field sample helpers');
requireText('public/ctrip-static.js', 'const buildCtripProfileFieldDerivationHelpers', 'Ctrip static builds Profile field derivation helpers');
requireText('public/ctrip-static.js', 'const normalizeCtripProfileFieldVerificationStatus', 'Ctrip static owns Profile field verification status normalization');
requireNoText('public/index.html', "if (['matched', 'match', 'ok', 'correct'].includes(value)) return 'matched';", 'entry does not re-inline Profile field verification status mapping');
requireText('public/index.html', "requireCtripStatic('buildCtripProfileRecheckRunContext')", 'entry uses extracted Ctrip Profile recheck run context builder');
requireText('public/index.html', "requireCtripStatic('runCtripProfileRecheckFlow')", 'entry uses extracted Ctrip Profile recheck flow runner');
requireText('public/ctrip-static.js', 'const buildCtripProfileRecheckInitialState', 'Ctrip static builds Profile recheck initial state');
requireText('public/ctrip-static.js', 'const buildCtripProfileRecheckRunContext', 'Ctrip static builds Profile recheck run context');
requireText('public/ctrip-static.js', 'const buildCtripProfileRecheckSuccessResult', 'Ctrip static builds Profile recheck success result');
requireText('public/ctrip-static.js', 'const runCtripProfileRecheckFlow', 'Ctrip static runs Profile recheck flow');
requireText('public/index.html', "requireMeituanStatic('runMeituanBatchFetchFlow')", 'entry uses extracted Meituan batch fetch flow runner');
requireText('public/index.html', "requireMeituanStatic('runMeituanTrafficFetchFlow')", 'entry uses extracted Meituan traffic fetch flow runner');
requireText('public/index.html', "requireMeituanStatic('runMeituanOrderFetchFlow')", 'entry uses extracted Meituan order fetch flow runner');
requireText('public/index.html', "requireMeituanStatic('buildMeituanOrderDomCollectorScript')", 'entry uses extracted Meituan order DOM collector script builder');
requireText('public/index.html', "requireMeituanStatic('runMeituanOrderCsvImportFlow')", 'entry uses extracted Meituan order CSV import flow runner');
requireText('public/index.html', "requireMeituanStatic('runMeituanAdsFetchFlow')", 'entry uses extracted Meituan ads fetch flow runner');
requireText('public/index.html', "requireMeituanStatic('runMeituanBrowserCaptureFlow')", 'entry uses extracted Meituan browser capture flow runner');
requireText('public/index.html', "requireMeituanStatic('runMeituanCapturedPayloadSaveFlow')", 'entry uses extracted Meituan captured payload save flow runner');
requireText('public/index.html', "requireMeituanStatic('runMeituanManualTabSwitch')", 'entry uses extracted Meituan manual tab switch helper');
requireText('app/service/MeituanRankDataExtractionService.php', 'final class MeituanRankDataExtractionService', 'Meituan rank response extraction lives in a focused service');
requireText('app/service/MeituanOnlineDataPersistenceService.php', 'final class MeituanOnlineDataPersistenceService', 'Meituan persistence lives in a focused service');
requireText('app/service/MeituanOnlineDataPersistenceService.php', 'MeituanRankDataExtractionService::extractForPersistenceWithSource($responseData)', 'Meituan persistence rank rows use extracted service');
requireText('app/service/MeituanOnlineDataPersistenceService.php', "$rankDataType = 'peer_rank';", 'Meituan peerRankData persistence does not write rank rows as business metrics');
requireText('app/controller/concern/BusinessDisplayConcern.php', "$query->where('data_type', 'peer_rank');", 'Meituan competitor summary reads peer_rank rows instead of business metric rows');
requireText('app/controller/concern/BusinessDisplayConcern.php', "$fields = ['roomNights', 'roomRevenue', 'salesRoomNights', 'sales', 'orderCount', 'viewConversion', 'payConversion', 'exposure', 'views'];", 'Meituan self actual metric derivation keeps order count in the same path as traffic metrics');
requireText('app/controller/concern/BusinessDisplayConcern.php', "foreach (['exposure', 'views', 'orderCount', 'payConversion'] as $field)", 'Meituan stored self traffic values merge stored order count and derived pay conversion');
requireText('app/controller/concern/BusinessDisplayConcern.php', "isset($columns['order_submit_num']) ? 'SUM(COALESCE(order_submit_num, 0)) AS orderCount' : '0 AS orderCount'", 'Meituan stored self traffic query reads persisted submitted orders');
requireText('app/controller/concern/BusinessDisplayConcern.php', "$values['payConversion'] = round((float)$values['orderCount'] / (float)$values['views'], 4);", 'Meituan stored self traffic query derives pay conversion only from persisted orders and views');
requireOnlineDataControllerText('return (new MeituanOnlineDataPersistenceService())->parseAndSaveMeituanData(', 'OnlineData keeps only a compatibility wrapper for Meituan persistence');
requireNoText('app/controller/OnlineData.php', 'MeituanRankDataExtractionService::extractForPersistenceWithSource($responseData)', 'Meituan persistence is not re-inlined in OnlineData');
requireText('app/service/MeituanManualFetchRequestService.php', 'final class MeituanManualFetchRequestService', 'Meituan manual fetch request parameter building lives in a focused service');
requireOnlineDataControllerText('MeituanManualFetchRequestService::buildRankRequestParams(', 'OnlineData delegates Meituan rank request parameters');
requireOnlineDataControllerText('MeituanManualFetchRequestService::buildTrafficRequestParams(', 'OnlineData delegates Meituan traffic request parameters');
requireOnlineDataControllerText('return MeituanManualFetchRequestService::normalizeDateRange($startDate, $endDate);', 'OnlineData keeps only a compatibility wrapper for Meituan manual date ranges');
requireText('app/service/CtripManualFetchRequestService.php', 'final class CtripManualFetchRequestService', 'Ctrip manual fetch request parameter building lives in a focused service');
requireOnlineDataControllerText('CtripManualFetchRequestService::normalizeBusinessReportUrl(', 'OnlineData delegates Ctrip manual report URL defaults');
requireOnlineDataControllerText('CtripManualFetchRequestService::normalizeDateRange($startDate, $endDate)', 'OnlineData delegates Ctrip manual date ranges');
requireOnlineDataControllerText('CtripManualFetchRequestService::buildDailyPostData($nodeId, $currentDate)', 'OnlineData delegates Ctrip daily request payloads');
requireOnlineDataControllerText('CtripManualFetchRequestService::hasRepeatedMultiDayFingerprint(', 'OnlineData delegates Ctrip repeated multi-day fingerprint detection');
requireOnlineDataControllerText('MeituanRankDataExtractionService::extractForDisplay($responseData)', 'Meituan display rank rows use extracted service');
requireNoText('app/controller/OnlineData.php', "isset($responseData['data']['peerRankData']) && is_array($responseData['data']['peerRankData'])", 'OnlineData no longer inlines Meituan peerRankData extraction');
requireText('public/meituan-static.js', 'const buildMeituanBatchFetchTasks', 'Meituan static builds batch fetch tasks');
requireText('public/meituan-static.js', 'const buildMeituanDisplayModelPayload', 'Meituan static builds display model payloads');
requireText('public/meituan-static.js', 'const validateMeituanBatchFetchInput', 'Meituan static validates batch fetch inputs');
requireText('public/meituan-static.js', 'const runMeituanBatchFetchFlow', 'Meituan static runs batch fetch flow');
requireText('public/meituan-static.js', 'const buildMeituanBrowserCaptureRequestContext', 'Meituan static builds browser capture request context');
requireText('public/meituan-static.js', 'const runMeituanBrowserCaptureFlow', 'Meituan static runs browser capture flow');
requireText('public/meituan-static.js', 'const getMeituanBrowserCaptureSupplementModules', 'Meituan static exposes browser capture supplemental modules');
requireText('public/meituan-static.js', 'const buildMeituanBrowserCaptureSupplementCounts', 'Meituan static summarizes browser capture supplemental counts');
requireText('public/meituan-static.js', 'const buildMeituanCapturedPayloadSaveContext', 'Meituan static builds captured payload save context');
requireText('public/meituan-static.js', 'const runMeituanCapturedPayloadSaveFlow', 'Meituan static runs captured payload save flow');
requireText('public/meituan-static.js', 'const buildMeituanTrafficFetchRequestBody', 'Meituan static builds traffic fetch request bodies');
requireText('public/meituan-static.js', 'const runMeituanTrafficFetchFlow', 'Meituan static runs traffic fetch flow');
requireText('public/meituan-static.js', 'const buildMeituanOrderFetchRequestBody', 'Meituan static builds order fetch request bodies');
requireText('public/meituan-static.js', 'const runMeituanOrderFetchFlow', 'Meituan static runs order fetch flow');
requireText('public/meituan-static.js', 'const buildMeituanOrderDomCollectorScript', 'Meituan static builds order DOM collector script');
requireText('public/meituan-static.js', 'const parseMeituanOrderCsvText', 'Meituan static parses order CSV exports');
requireText('public/meituan-static.js', 'const runMeituanOrderCsvImportFlow', 'Meituan static runs order CSV import flow');
requireText('public/meituan-static.js', 'const buildMeituanAdsFetchRequestBody', 'Meituan static builds ads fetch request bodies');
requireText('public/meituan-static.js', 'const runMeituanAdsFetchFlow', 'Meituan static runs ads fetch flow');
requireText('public/meituan-static.js', 'const runMeituanManualTabSwitch = async', 'Meituan static runs manual tab switch orchestration');
requireText('public/components/online-data/platform-auto-settings-panels.js', 'data-testid="meituan-browser-supplement-capture"', 'Platform auto panel exposes Meituan supplemental browser capture entry');
requireNoText('public/index.html', '<script src="auto-fetch-static.js"></script>', 'frontend lazy-loads extracted auto-fetch static helper');
requireText('public/index.html', "const autoFetchStaticScript = 'auto-fetch-static.js'", 'entry keeps auto-fetch static lazy script path');
requireText('public/index.html', 'const ensureAutoFetchStaticReady = async () =>', 'entry keeps auto-fetch static ready guard');
requireText('public/index.html', "requireAutoFetchStatic('runAutoFetchTriggerFlow')", 'entry uses extracted auto-fetch trigger flow runner');
requireText('public/index.html', 'const loadAutoFetchPanel = async', 'entry keeps platform auto-fetch panel loader');
requireText('public/index.html', 'await ensureAutoFetchStaticReady();', 'entry gates auto-fetch actions on static helper readiness');
requireText('public/index.html', 'const prewarmAutoFetchStaticForPlatformAuto = () => {', 'platform-auto static helper prewarm is isolated from the first-paint panel loader');
requireText('public/index.html', "if (!isVisibleOnlineDataTab('platform-auto')) return null;", 'platform-auto static helper prewarm checks visible tab state before starting');
requireText('public/index.html', 'const staticReadyPromise = loadAutoFetchStatic().catch(error => {', 'platform-auto delayed prewarm routes static helper failures outside the first-paint path');
requireText('public/index.html', 'void staticReadyPromise;', 'platform-auto delayed prewarm intentionally keeps static helper loading out of awaited click paths');
requireNoText('public/index.html', "const staticReadyPromise = loadAutoFetchStatic().catch(error => {\n                    autoFetchStaticLoadError.value = error.message || '自动采集静态配置加载失败';\n                    console.error('[auto-fetch-static] prewarm failed:', error);\n                    return null;\n                });\n                void staticReadyPromise;\n\n                let panelLoaded = false;", 'platform-auto panel loader must not start auto-fetch-static.js before light status first paint');
requireText('public/index.html', 'const PLATFORM_AUTO_PANEL_START_DELAY_MS = 16;', 'platform-auto panel yields one frame before its first status request so quick tab exits stay responsive');
requireText('public/index.html', 'const waitForPlatformAutoPanelStart = async (options = {}) => {', 'platform-auto panel checks visibility before starting first requests');
requireText('public/index.html', 'if (!await waitForPlatformAutoPanelStart(options)) {\n                        return;\n                    }', 'platform-auto panel cancels first requests after the user leaves the tab');
requireText('public/index.html', 'const canLoadStatusBeforeHotels = !!autoFetchHotelId.value;', 'platform-auto panel can load light status before the hotel list when a selected hotel is already known');
requireText('public/index.html', "const defaultAutoFetchHotelId = getAutoFetchHotelId();\n                    if (!autoFetchHotelId.value && defaultAutoFetchHotelId) {\n                        autoFetchHotelId.value = defaultAutoFetchHotelId;\n                    }", 'platform-auto resolves cached user hotel before deciding whether status must wait for hotel list');
requireText('public/index.html', 'const autoFetchConfigProofPendingForHotelId = (hotelId) => {', 'platform-auto trigger can recognize in-flight light config proof');
requireText('public/index.html', 'autoFetchStatusRequestPromises.has(`${keyPrefix}light`)', 'platform-auto trigger stays clickable while light status proof is in flight');
requireText('public/index.html', 'const canTriggerAutoFetchByHotelId = (hotelId) => {', 'platform-auto trigger uses a dedicated clickability guard');
requireText('public/index.html', 'hasPlatformFetchConfig: canTriggerAutoFetchByHotelId,', 'platform-auto manual trigger does not block on completed local config proof before backend validation');
requireText('public/index.html', ':disabled="fetchingData || !canTriggerAutoFetchByHotelId(autoFetchHotelId)"', 'platform-auto immediate collection button uses the fast clickability guard');
requireText('public/index.html', 'const hotelsPromise = shouldLoadHotels ? loadHotels({ cacheMs: HOTEL_LIST_CACHE_TTL_MS }) : Promise.resolve();', 'platform-auto panel starts cached hotel loading without forcing a serial status wait');
requireText('public/index.html', 'Promise.all([\n                            loadAutoFetchStatus({ detail: false }),\n                            hotelsPromise,', 'platform-auto panel waits only for light status and hotel list when a selected hotel is already known');
requireNoText('public/index.html', 'Promise.all([\n                            loadAutoFetchStatus({ detail: false }),\n                            staticReadyPromise,\n                            hotelsPromise,', 'platform-auto panel must not block first paint on auto-fetch-static.js');
requireText('public/index.html', "await hotelsPromise;\n                    if (!isVisibleOnlineDataTab('platform-auto')) {\n                        return;\n                    }\n                    if (!autoFetchHotelId.value && hotels.value && hotels.value.length > 0) {", 'platform-auto panel still waits for hotels before choosing a default hotel, but skips state writes after tab exit');
requireText('public/index.html', 'if (panelLoaded) {\n                        autoFetchPanelCache = {', 'platform-auto panel caches only completed visible loads');
requireText('public/index.html', "else if (autoFetchPanelCache.promise === run) {\n                        autoFetchPanelCache = { key: '', expiresAt: 0, promise: null };\n                    }", 'platform-auto panel does not cache a canceled load after tab exit');
requireText('public/index.html', 'const schedulePostFetchRefresh =', 'entry defers post-fetch refresh work');
requireText('public/index.html', 'const AUTO_FETCH_PANEL_CACHE_TTL_MS', 'entry deduplicates platform auto-fetch panel loading');
requireText('public/index.html', 'const ONLINE_DATA_PANEL_CACHE_TTL_MS = 8000;', 'entry caches automatic online-data tab reads for smooth tab switching');
requireText('public/index.html', 'const onlineDataListRequestPromises = new Map();', 'entry deduplicates concurrent online-data list requests');
requireText('public/index.html', 'const onlineDataSummaryRequestPromises = new Map();', 'entry deduplicates concurrent online-data summary requests');
requireText('public/index.html', 'const onlineDataHotelListRequestPromises = new Map();', 'entry deduplicates concurrent online-data hotel-filter requests');
requireText('public/index.html', '@click="refreshOnlineData({ force: true })"', 'manual online-data query bypasses tab-switch cache');
requireText('public/index.html', 'const PLATFORM_PROFILE_STATUS_PANEL_CACHE_TTL_MS = 20000;', 'entry caches platform profile status for smooth platform panel switching');
requireText('public/index.html', 'const platformProfileStatusResultCache = new Map();', 'entry deduplicates recent platform profile status responses by hotel');
requireText('public/index.html', 'return loadPlatformProfileStatus(platformProfileStatusPanelRefreshOptions(params));', 'scheduled platform profile refreshes use panel cache options');
requireText('public/index.html', '@click="loadPlatformProfileStatus({ silent: true, force: true })"', 'manual platform profile refresh bypasses panel cache');
requireText('public/index.html', "newTab === 'platform-auto'", 'entry lazy-loads platform auto-fetch panel only on tab entry');
requireNoText('public/index.html', 'await loadAutoFetchPanel()', 'platform-auto navigation and profile follow-up refreshes do not block on the full panel reload');
requireText('public/index.html', 'openPlatformAutoTab({ force: true, delayMs: 0 });', 'platform-auto navigation schedules full panel refresh through the shared tab scheduler');
requireText('public/index.html', 'deferUiTask(() => {\n                            schedulePlatformProfileStatusRefresh({ silent: true, force: true });\n                            schedulePlatformAutoFetchPanelLoad({ force: true });', 'profile unbind refreshes platform profile and auto-fetch state through guarded forced schedulers');
requireText('public/index.html', 'const isVisibleOnlineDataTab = isOnlineDataTabVisible;', 'online-data deferred loaders only start when the requested tab is still visible');
requireText('public/index.html', "const schedulePlatformAutoFetchPanelLoad = (options = {}) => {", 'platform auto-fetch panel opens through the shared page-load scheduler');
requireText('public/index.html', 'scheduleDelayedPageTask(run, delayMs);', 'platform auto-fetch hotel switching can defer panel refresh until after selection paint');
requireText('public/index.html', "if (!isVisibleOnlineDataTab('platform-auto')) return null;", 'platform auto-fetch panel load is skipped after the user leaves the visible tab');
requireText('public/index.html', 'const openPlatformAutoTab = (options = {}) =>', 'platform auto tab opens through one deduplicated entrypoint');
requireText('public/index.html', 'const openOnlinePlatformAutoTab = (options = {}) =>', 'cross-page platform auto navigation uses the deduplicated entrypoint');
requireText('public/index.html', 'const PLATFORM_AUTO_SETTINGS_PANEL_DELAY_MS = 800;', 'platform auto-fetch delays schedule/browser settings behind immediate collect controls');
requireText('public/index.html', 'const platformAutoSettingsPanelsReady = ref(false);', 'platform auto-fetch tracks settings readiness separately from core controls');
requireText('public/index.html', "const platformAutoPanelsScript = 'components/online-data/platform-auto-settings-panels.js?v=20260613-platform-auto-lazy';", 'platform auto-fetch extension panels use a versioned lazy component script');
requireText('public/index.html', 'const ensurePlatformAutoPanelsReady = async () => {', 'platform auto-fetch extension panels load only after the delayed panel timers fire');
requireText('public/index.html', "requireOnlineDataComponent('PlatformAutoSettingsPanelsBody')", 'platform auto-fetch settings panel resolves the lazy body component after script load');
requireText('public/index.html', "requireOnlineDataComponent('PlatformAutoSecondaryPanelsBody')", 'platform auto-fetch secondary panel resolves the lazy body component after script load');
requireNoText('public/index.html', '<script src="components/online-data/platform-auto-settings-panels.js', 'platform auto-fetch extension panel script must not load before Vue mount');
requireText('public/index.html', 'const platformAutoSettingsPanelsBody = shallowRef(null);', 'platform auto-fetch settings panel stores its lazy body outside the first-paint path');
requireText('public/index.html', 'const schedulePlatformAutoSettingsPanelsReady = (delayMs = PLATFORM_AUTO_SETTINGS_PANEL_DELAY_MS) => {', 'platform auto-fetch schedules settings rendering through a visible-tab timer');
requireText('public/index.html', 'void ensurePlatformAutoPanelsReady().catch', 'platform auto-fetch panel timers start non-blocking component loading');
requireText('public/index.html', '<platform-auto-settings-panels', 'platform auto-fetch schedule/browser settings mount through the split component after immediate collect controls');
requireOrder('public/index.html', '@click="triggerAutoFetch"', '<platform-auto-settings-panels', 'platform auto-fetch immediate collect button stays above delayed settings panels');
requireText('public/index.html', 'data-testid="platform-auto-settings-panels-loading"', 'platform auto-fetch settings wrapper shows an explicit loading state while the lazy body loads');
requireText('public/components/online-data/platform-auto-settings-panels.js', 'components.PlatformAutoSettingsPanelsBody', 'platform auto-fetch settings body registers under a lazy component key');
requireText('public/components/online-data/platform-auto-settings-panels.js', 'data-testid="platform-auto-settings-panels" class="grid grid-cols-1 lg:grid-cols-2 gap-4"', 'platform auto-fetch schedule/browser settings stay in the split component');
requireText('public/index.html', "const ctripProfileFieldConfigPanelScript = 'components/online-data/ctrip-profile-field-config-panel.js?v=20260613-profile-template-split';", 'Ctrip profile-field admin panel uses a versioned lazy component script');
requireText('public/index.html', "const CtripProfileFieldConfigPanel = {", 'Ctrip profile-field admin panel uses a stable sync wrapper component');
requireText('public/index.html', 'const ensureCtripProfileFieldConfigPanelReady = async () => {', 'Ctrip profile-field admin panel loads its heavy template only when the tab is opened');
requireText('public/index.html', "requireOnlineDataComponent('CtripProfileFieldConfigPanelBody')", 'Ctrip profile-field admin panel resolves the lazy body component after script load');
requireText('public/index.html', 'void ensureCtripProfileFieldConfigPanelReady().catch', 'Ctrip profile-field tab starts non-blocking component loading before field data rendering');
requireText('public/index.html', '<ctrip-profile-field-config-panel', 'Ctrip profile-field admin panel mounts through the split wrapper component');
requireText('public/index.html', 'data-testid="ctrip-profile-field-config-loading"', 'Ctrip profile-field admin panel shows an explicit loading state while the lazy body loads');
requireText('public/components/online-data/ctrip-profile-field-config-panel.js', 'components.CtripProfileFieldConfigPanelBody', 'Ctrip profile-field admin body registers under a non-conflicting lazy component key');
requireText('public/components/online-data/ctrip-profile-field-config-panel.js', 'data-testid=\\"ctrip-profile-field-config-panel\\"', 'Ctrip profile-field admin template stays in the lazy component');
requireText('public/components/online-data/ctrip-profile-field-config-panel.js', 'return new Proxy({}, {', 'Ctrip profile-field lazy component bridges existing root bindings through a setup proxy');
requireText('public/components/online-data/ctrip-profile-field-config-panel.js', 'return props.ctx?.[key] ?? target[key];', 'Ctrip profile-field lazy component reads root bindings from the passed context');
requireText('public/components/online-data/ctrip-profile-field-config-panel.js', 'props.ctx[key] = value;', 'Ctrip profile-field lazy component writes v-model updates back to root bindings');
requireText('public/components/online-data/ctrip-profile-field-config-panel.js', 'getOwnPropertyDescriptor() {', 'Ctrip profile-field lazy component exposes proxy properties to Vue setup-state lookup');
requireNoText('public/index.html', '携程登录会话字段配置', 'Ctrip profile-field admin template is no longer in the initial entry HTML');
requireText('public/index.html', 'const PLATFORM_AUTO_SECONDARY_PANEL_DELAY_MS = 2600;', 'platform auto-fetch delays secondary status/result panels behind first paint');
requireText('public/index.html', 'const platformAutoSecondaryPanelsReady = ref(false);', 'platform auto-fetch tracks secondary panel readiness separately from core controls');
requireText('public/index.html', 'const platformAutoSecondaryPanelsBody = shallowRef(null);', 'platform auto-fetch secondary panel stores its lazy body outside the first-paint path');
requireText('public/index.html', 'const schedulePlatformAutoSecondaryPanelsReady = (delayMs = PLATFORM_AUTO_SECONDARY_PANEL_DELAY_MS) => {', 'platform auto-fetch schedules secondary panel rendering through a visible-tab timer');
requireText('public/index.html', '<platform-auto-secondary-panels', 'platform auto-fetch secondary blueprint/profile/result panels mount through the split component after core controls');
requireText('public/index.html', 'data-testid="platform-auto-secondary-panels-loading"', 'platform auto-fetch secondary wrapper shows an explicit loading state while the lazy body loads');
requireText('public/components/online-data/platform-auto-settings-panels.js', 'components.PlatformAutoSecondaryPanelsBody', 'platform auto-fetch secondary body registers under a lazy component key');
requireText('public/components/online-data/platform-auto-settings-panels.js', 'data-testid="platform-auto-secondary-panels" class="space-y-4"', 'platform auto-fetch secondary blueprint/profile/result panels stay in the split component');
requireText('public/index.html', 'platformAutoSettingsPanelsReady.value = false;\n                    schedulePlatformAutoSettingsPanelsReady();', 'platform auto-fetch schedules settings controls before secondary panels');
requireText('public/index.html', 'platformAutoSecondaryPanelsReady.value = false;\n                    schedulePlatformAutoSecondaryPanelsReady();\n                    return runIfCurrent(() => schedulePlatformAutoFetchPanelLoad(options));', 'platform auto-fetch schedules secondary rendering before deferred panel data load');
requireText('public/index.html', 'prewarmAutoFetchStaticForPlatformAuto();', 'platform auto-fetch starts auto-fetch-static.js only when secondary panels become relevant');
requireText('public/index.html', '@change="schedulePlatformAutoFetchPanelLoad({ force: true, delayMs: 80 })"', 'platform-auto hotel switching defers refresh through the shared scheduler');
requireText('public/index.html', '@click="schedulePlatformAutoFetchPanelLoad({ force: true })"', 'platform-auto manual refresh buttons use the shared scheduler');
requireNoText('public/index.html', "onlineDataTab = 'platform-auto'; loadAutoFetchPanel()", 'platform auto tab buttons do not bypass the shared scheduler');
requireNoText('public/index.html', "if (row.tab === 'platform-auto') loadAutoFetchPanel();", 'data-health drilldown does not bypass the shared platform-auto scheduler');
requireNoText('public/index.html', '@change="loadAutoFetchPanel"', 'platform-auto hotel switching does not bypass the shared scheduler');
requireNoText('public/index.html', '@click="loadAutoFetchPanel"', 'platform-auto refresh buttons do not bypass the shared scheduler');
requireText('public/index.html', "const schedulePlatformDataSourcePanelLoad = (options = {}) => runPageLoadOnce(", 'platform source panel loads through the shared page-load scheduler');
requireText('public/index.html', "const schedulePlatformSyncLogPanelRefresh = (options = {}) => runPageLoadOnce(", 'platform sync logs refresh through the shared page-load scheduler');
requireText('public/index.html', "if (!isVisibleOnlineDataTab('platform-sources')) return null;", 'platform source and sync-log loads are skipped after the user leaves the visible tab');
requireText('public/index.html', "const openPlatformSourcesTab = (options = {}) =>", 'platform source tab opens through a single deduplicated entrypoint');
requireText('public/index.html', 'const PLATFORM_SOURCE_SECONDARY_REFRESH_DELAY_MS = 3200;', 'platform source panel delays secondary sync/log/resource refreshes behind first paint');
requireText('public/index.html', 'const PLATFORM_SOURCE_GUIDE_PANEL_DELAY_MS = 1200;', 'platform source panel delays guide and batch-health cards behind first paint');
requireText('public/index.html', 'const PLATFORM_SOURCE_PANEL_CACHE_TTL_MS = 30000;', 'platform source panel uses a 30s cache window for normal tab returns');
requireText('public/index.html', 'const platformSourceGuidePanelsReady = ref(false);', 'platform source panel tracks non-core guide card readiness separately from core controls');
requireText('public/index.html', 'const schedulePlatformSourceGuidePanelsReady = (delayMs = PLATFORM_SOURCE_GUIDE_PANEL_DELAY_MS) => {', 'platform source panel schedules non-core guide cards through a visible-tab timer');
requireText('public/index.html', '<div v-if="platformSourceGuidePanelsReady" data-testid="platform-account-binding-guide"', 'platform source account-binding guide mounts after the core configuration area');
requireText('public/index.html', '<div v-if="platformSourceGuidePanelsReady" data-testid="platform-batch-health-check"', 'platform source batch health card mounts after the core configuration area');
requireText('public/index.html', "if (newTab === 'platform-sources') {\n                    platformSourceGuidePanelsReady.value = false;\n                    schedulePlatformSourceGuidePanelsReady();", 'platform source tab switch schedules guide cards separately from panel data loading');
requireText('public/index.html', 'await Promise.allSettled([\n                    loadPlatformDataSources({', 'platform source panel loads data sources in the primary first-paint group');
requireText('public/index.html', 'loadPlatformProfileStatus({\n                        silent: true,\n                        cacheMs: options.force ? 0 : PLATFORM_PROFILE_STATUS_PANEL_CACHE_TTL_MS,', 'platform source panel keeps profile status in the primary first-paint group');
requireText('public/index.html', 'scheduleDelayedPageTask(() => {\n                    if (!shouldRefreshPlatformDataSourcesPanel()) return null;\n                    return Promise.allSettled([', 'platform source panel schedules secondary refreshes through a real timer and visible-tab guard');
requireText('public/index.html', 'loadCompetitorSummary({\n                            includeByHotel: true,\n                            force: options.force === true,\n                            cacheMs: options.force ? 0 : PLATFORM_SOURCE_PANEL_CACHE_TTL_MS,', 'platform source panel short-caches by-hotel competitor summaries during normal tab returns');
requireText('public/index.html', '}, PLATFORM_SOURCE_SECONDARY_REFRESH_DELAY_MS);\n            };\n\n            const savePlatformDataSource = async () => {', 'platform source panel secondary refreshes use the shared delay constant');
requireNoText('public/index.html', 'deferUiTask(() => {\n                    if (!shouldRefreshPlatformDataSourcesPanel()) return null;\n                    return Promise.allSettled([\n                        loadPlatformSyncTasks({', 'platform source panel must not use requestIdleCallback for secondary sync/log/resource refreshes');
requireText('public/index.html', '@click="schedulePlatformSyncLogPanelRefresh({ force: true })"', 'platform source log button uses the non-blocking sync-log scheduler');
requireNoText('public/index.html', "onlineDataTab = 'platform-sources'; loadPlatformDataSourcePanel()", 'platform source tab switches do not double-trigger the heavy data-source panel load');
requireNoText('public/index.html', 'await loadPlatformDataSourcePanel();', 'platform source mutations do not block on full panel reload');
requireNoText('public/index.html', 'await Promise.all([loadPlatformDataSources(), loadPlatformSyncTasks(), loadPlatformSyncLogs(), loadPlatformCollectionResources(), loadOnlineDataList()]);', 'platform import completion defers heavy follow-up panel and list refreshes');
requireNoText('public/index.html', 'await Promise.all([loadPlatformSyncTasks(), loadPlatformSyncLogs(), loadPlatformProfileStatus({ silent: true })]);', 'platform sync-log navigation does not block on log/profile refreshes');
requireNoText('public/index.html', 'await Promise.all([loadPlatformSyncTasks(), loadPlatformSyncLogs()]);', 'platform source sync failure does not block on log refreshes');
requireNoText('public/index.html', '@click="loadPlatformSyncTasks(); loadPlatformSyncLogs()"', 'platform source log button does not synchronously request logs inline');
requireNoText('public/index.html', '@click="loadPlatformDataSourcePanel"', 'platform source refresh buttons do not bypass the visible-tab scheduler');
requireText('public/index.html', '@click="schedulePlatformDataSourcePanelLoad({ force: true })"', 'platform source refresh buttons use the forced visible-tab scheduler');
requireText('public/index.html', 'platformDataSourceHotelOptions, platformSourceGuidePanelsReady, loadPlatformDataSourcePanel', 'platform source guide readiness is exposed to the Vue template with the platform source controls');
requireText('public/index.html', 'schedulePlatformDataSourcePanelLoad, schedulePlatformSyncLogPanelRefresh', 'platform source refresh scheduler is exposed to the Vue template');
requireText('public/index.html', 'schedulePlatformDataSourcePanelLoad({ force: true });', 'platform source mutations schedule forced panel refresh after server writes');
requireText('public/index.html', 'const platformCollectionResourcesRequestPromises = new Map();', 'platform collection-resource reads are deduplicated');
requireText('public/index.html', 'const platformCollectionResourcesResultCache = new Map();', 'platform collection-resource reads keep a short panel cache');
requireText('public/index.html', 'const loadPlatformCollectionResources = async (options = {}) =>', 'platform collection-resource loader accepts cache options');
requireText('public/index.html', 'readRequestCache(platformCollectionResourcesResultCache, requestKey, cacheMs)', 'platform collection-resource loader reuses recent panel reads');
requireText('public/index.html', 'writeRequestCache(platformCollectionResourcesResultCache, requestKey, cacheMs)', 'platform collection-resource loader writes successful panel reads to cache');
requireText('public/index.html', 'const BUSINESS_CONTEXT_ENDPOINT_PREFIXES = [', 'business requests use the unified auth context layer');
requireText('public/index.html', 'const withBusinessRequestContext = (url, options = {}) =>', 'request wrapper enriches scoped business requests');
requireText('public/index.html', 'data-testid="platform-collection-type-breakdown"', 'platform source page exposes data-type collection status breakdown');
requireText('public/index.html', 'const platformCollectionTypeRows = computed(() => {', 'platform collection type rows are derived from resource and review-policy state');
requireText('public/index.html', 'schedulePlatformCollectionStatusRefresh();', 'post-fetch actions refresh the unified platform collection status');
requireText('public/index.html', "review_collection_policy: 'explicit_review_match_only'", 'Ctrip review order automation explicitly scopes review reads to the match action');
requireText('app/controller/concern/CtripReviewOrderMatchConcern.php', 'ctrip_comment_browser_capture.mjs', 'Ctrip review order automation can run dedicated authorized review capture');
requireText('app/controller/concern/CtripReviewOrderMatchConcern.php', 'explicit_review_match_authorized_profile_or_existing_cache', 'Ctrip review order automation reports the explicit review-match collection policy');
requireText('public/index.html', '携程点评-订单匹配台', 'Ctrip review order main UI exposes the matching workbench');
requireText('public/index.html', '点击单条点评直接查订单；主流程只展示订单、客人和状态。', 'Ctrip review order main UI keeps matching details hidden');
requireText('public/index.html', '点评订单查询', 'Ctrip review order main UI renders review lookup cards');
requireText('public/index.html', '@click="lookupCtripReviewOrderMatch(sample)"', 'Ctrip review order main UI can look up one review card directly');
requireNoText('public/index.html', 'JSON.stringify(ctripReviewMatchResult, null, 2)', 'Ctrip review order main UI must not expose raw match JSON');
requireText('public/index.html', "ctripReviewMatchResult.data.missing_sources.join(' / ')", 'Ctrip review order main UI reports missing sources directly');
requireText('public/index.html', '<select v-model="ctripReviewMatchForm.systemHotelId"', 'Ctrip review order main UI keeps hotel scope selectable');
requireText('public/index.html', '使用当前携程门店 / 当前账号门店', 'Ctrip review order hotel selector can fall back to the current authorized context');
requireNoText('public/index.html', '<div class="text-sm font-medium text-gray-900">授权 payload 导入</div>', 'Ctrip review order main UI does not expose payload import configuration');
requireNoText('public/index.html', 'IM 身份锁定', 'Ctrip review order main UI does not expose matching method tags');
requireNoText('public/index.html', '订单池匹配', 'Ctrip review order main UI does not expose order matching method tags');
requireText('scripts/import_ctrip_review_match_payload.php', 'ctrip_review_match_import_assert_no_placeholders($payload);', 'Ctrip review order CLI rejects placeholder template payloads before import');
requireText('scripts/import_ctrip_review_match_payload.php', "if ($options['preflight'])", 'Ctrip review order CLI supports pure preflight without starting an import transaction');
requireText('package.json', '"import:ctrip-review-match-payload:preflight"', 'Ctrip review order package scripts expose pure payload preflight');
requireText('package.json', '"verify:ctrip-review-match"', 'Ctrip review order package scripts expose the real-data closure verifier');
requireText('scripts/verify_ctrip_review_match_closure.php', "'matched_results'", 'Ctrip review order closure verifier requires real matched results');
requireText('scripts/verify_ctrip_review_match_closure.php', "'ctrip_reviews', 'ctrip_im_sessions', 'ctrip_orders'", 'Ctrip review order closure verifier requires all real detail sources');
requireText('scripts/verify_ctrip_review_match_closure.php', "'accepted_match_statuses' => ['found', 'matched']", 'Ctrip review order closure verifier accepts automatic and manual match statuses');
requireText('scripts/verify_ctrip_review_match_closure.php', "'next_commands' => $ready ? [] : ctrip_review_match_closure_next_commands($systemHotelId)", 'Ctrip review order closure verifier returns executable next commands when real data is missing');
requireText('scripts/verify_ctrip_review_match_closure.php', 'npm.cmd run import:ctrip-review-match-payload:preflight', 'Ctrip review order closure verifier points to authorized payload preflight');
requireText('scripts/verify_ctrip_review_match_closure.php', 'npm.cmd run verify:ctrip-review-match -- --system-hotel-id=', 'Ctrip review order closure verifier points back to the real-data verifier');
requireText('public/index.html', 'const copyCtripReviewMatchCliCommand =', 'Ctrip review order keeps bounded CLI helper off the main surface');
requireText('route/app.php', "Route::post('/ctrip-review-matches/closure'", 'Ctrip review order route exposes the read-only closure check');
requireText('app/controller/concern/CtripReviewOrderMatchConcern.php', 'public function checkCtripReviewOrderMatchClosure()', 'Ctrip review order API exposes the read-only closure check action');
requireText('app/controller/concern/CtripReviewOrderMatchConcern.php', "'policy' => 'real_data_closure_check_only'", 'Ctrip review order closure check is explicitly read-only');
requireText('app/controller/concern/CtripReviewOrderMatchConcern.php', "'next_commands' => $ready ? [] : $this->buildCtripReviewMatchClosureNextCommands($systemHotelId)", 'Ctrip review order closure API returns executable next commands when real data is missing');
requireText('public/index.html', '@click="checkCtripReviewMatchClosure"', 'Ctrip review order UI exposes closure status verification');
requireText('route/app.php', "Route::post('/ctrip-review-matches/identity-preview'", 'Ctrip review order route exposes the page-side identity preview');
requireText('app/controller/concern/CtripReviewOrderMatchConcern.php', 'public function previewCtripReviewOrdererIdentity()', 'Ctrip review order API exposes read-only page identity preview');
requireText('app/controller/concern/CtripReviewOrderMatchConcern.php', "'policy' => 'authorized_page_identity_preview_only'", 'Ctrip review order page preview is explicitly read-only');
requireText('public/index.html', '@click="copyCtripReviewOrdererAssistScript"', 'Ctrip review order UI exposes page assist script copy action');
requireText('public/index.html', 'buildCtripReviewOrdererAssistScript', 'Ctrip review order UI builds the page assist script locally');
requireText('public/index.html', '疑似下单人', 'Ctrip review order UI keeps probabilistic identity label');
requireText('public/index.html', '展开高级补录/复核', 'Ctrip review order keeps manual operations behind an advanced panel');
requireNoText('public/index.html', "ctripReviewMatchResult.data.next_commands", 'Ctrip review order main UI does not render closure commands');
requireNoText('public/index.html', "@click=\"copyToClipboard(command)\"", 'Ctrip review order main UI does not expose command copy buttons');
requireText('app/controller/concern/CtripReviewOrderMatchConcern.php', '$this->assertCtripReviewMatchPayloadHasNoPlaceholders($payload);', 'Ctrip review order API rejects placeholder template payloads before import');
requireText('app/controller/concern/CtripReviewOrderMatchConcern.php', "$data['preflight_only'] ?? $data['preflightOnly']", 'Ctrip review order API supports page preflight-only requests');
requireText('app/controller/concern/CtripReviewOrderMatchConcern.php', "'storage_write' => false", 'Ctrip review order API preflight-only response proves no storage write');
requireText('app/controller/concern/CtripReviewOrderMatchConcern.php', "$data['dry_run'] ?? $data['dryRun']", 'Ctrip review order API supports dry-run matching requests');
requireText('app/controller/concern/CtripReviewOrderMatchConcern.php', "'transaction' => $dryRun ? 'rolled_back' : 'not_wrapped'", 'Ctrip review order dry-run response proves rollback');
requireText('app/controller/concern/CtripReviewOrderMatchConcern.php', "'payload_preflight' => $payloadPreflight", 'Ctrip review order API returns authorized payload preflight evidence');
requireText('scripts/import_ctrip_review_match_payload.php', "'payload_preflight' => is_array($payloadPreflight) ? $payloadPreflight : []", 'Ctrip review order CLI returns authorized payload preflight evidence');
requireText('public/index.html', 'const runCtripReviewMatchPreflight = () =>', 'Ctrip review order keeps page preflight helper available');
requireText('public/index.html', 'payload.preflight_only = true;', 'Ctrip review order page preflight action does not call import mode');
requireNoText('public/index.html', '@click="runCtripReviewMatchPreflight"', 'Ctrip review order main UI does not expose preflight-only action');
requireText('public/index.html', 'const runCtripReviewMatchDryRun = () =>', 'Ctrip review order keeps dry-run helper available');
requireText('public/index.html', 'payload.dry_run = true;', 'Ctrip review order page dry-run action requests rollback mode');
requireNoText('public/index.html', '@click="runCtripReviewMatchDryRun"', 'Ctrip review order main UI does not expose dry-run action');
requireNoText('public/index.html', "写入 {{ ctripReviewMatchResult.data.source_status.storage_write === false ? '否' : '是' }}", 'Ctrip review order main UI hides storage-write mechanics');
requireNoText('public/index.html', '授权 payload 预检：{{ ctripReviewMatchResult.data.payload_preflight.status', 'Ctrip review order main UI hides payload preflight mechanics');
requireNoText('public/index.html', 'sample.reason ||', 'Ctrip review order main UI does not render match sample reasons');
requireNoText('public/index.html', "user_name_masked: 'M519352****'", 'Ctrip review order page template does not include realistic sample reviewer identities');
requireNoText('public/index.html', "check_in_date: '2026-06-28'", 'Ctrip review order page template does not include realistic sample stay dates');
requireText('public/index.html', 'const competitorSummaryRequestPromises = new Map();', 'competitor summary reads are deduplicated');
requireText('public/index.html', 'const competitorSummaryResultCache = new Map();', 'competitor summary reads keep a short panel cache');
requireText('public/index.html', 'readRequestCache(competitorSummaryResultCache, requestKey, cacheMs)', 'competitor summary loader reuses recent panel reads');
requireText('public/index.html', 'competitorSummaryRequestPromises.has(requestKey)', 'competitor summary loader reuses in-flight reads');
requireText('public/index.html', 'writeRequestCache(competitorSummaryResultCache, requestKey, cacheMs);', 'competitor summary loader writes successful panel reads to cache');
requireText('public/index.html', 'const SAVED_OTA_DATA_CONFIG_CACHE_TTL_MS = 30000;', 'saved OTA data-source config reads use a short cache during manual tab switching');
requireText('public/index.html', 'const savedOtaDataConfigLoadingPromises = new Map();', 'saved OTA data-source config reads deduplicate concurrent system-config requests');
requireText('public/index.html', 'savedOtaDataConfigCache.get(configKey)', 'saved OTA data-source config reader checks the short cache before system-config');
requireText('public/index.html', 'if (savedOtaDataConfigLoadingPromises.has(configKey)) {', 'saved OTA data-source config reader reuses in-flight system-config reads');
requireText('public/index.html', 'const res = await request(`/system-config?key=${configKey}`);', 'saved OTA data-source config reader keeps bounded single-key system-config reads');
requireText('public/index.html', 'savedOtaDataConfigCache.set(configKey, {', 'saved OTA data-source config reader caches successful parsed configs');
requireText('public/index.html', 'const loadSavedDataConfigByType = async (type) => {\n                return await readSavedOtaDataConfigFromSystem(type);\n            };', 'Ctrip/Meituan saved config syncs share the cached system-config reader');
requireText('public/index.html', 'clearSavedOtaDataConfigCache(currentDataConfigType.value);', 'saving a data-source config invalidates the saved OTA config short cache');
requireText('public/index.html', 'const CTRIP_PROFILE_FIELDS_TAB_CACHE_TTL_MS = 30000;', 'Ctrip profile-field config reads use a short cache during platform-auto tab switching');
requireText('public/index.html', 'const ctripProfileFieldRequestPromises = new Map();', 'Ctrip profile-field config reads deduplicate concurrent list/sample requests');
requireText('public/index.html', 'const requestCtripProfileFields = async (includeSamples, options = {}) => {', 'Ctrip profile-field list/sample reads share the cached request helper');
requireText('public/index.html', 'if (ctripProfileFieldRequestPromises.has(key)) {', 'Ctrip profile-field request helper reuses in-flight reads');
requireText('public/index.html', 'const res = await requestCtripProfileFields(false, { force });', 'Ctrip profile-field list reads go through the cached request helper');
requireText('public/index.html', 'const res = await requestCtripProfileFields(true, { force: options.force === true });', 'Ctrip profile-field sample reads go through the cached request helper');
requireText('public/index.html', 'return runIfCurrent(() => loadCtripProfileFields(options));', 'profile-field tab scheduling preserves force refresh while allowing cached tab returns');
requireText('public/index.html', 'clearCtripProfileFieldCache();\n                        await loadCtripProfileFields({ force: true });', 'Ctrip profile-field writes invalidate cache and force a fresh reload');
requireText('public/index.html', 'clearCtripProfileFieldCache();\n                        mergeCtripProfileFieldUpdate(res.data || {});', 'Ctrip profile-field inline mutations invalidate the cached sample/list reads');
requireText('public/index.html', 'const ONLINE_ANALYSIS_PANEL_CACHE_TTL_MS = 8000;', 'online analysis tab returns reuse recent analysis reads');
requireText('public/index.html', 'const onlineAnalysisDataRequestPromises = new Map();', 'online analysis summary reads deduplicate concurrent requests');
requireText('public/index.html', 'const onlineAnalysisRowsRequestPromises = new Map();', 'online analysis detail reads deduplicate concurrent requests');
requireText('public/index.html', 'const cached = readOnlineAnalysisResultCache(onlineAnalysisDataResultCache, requestKey, cacheMs);', 'online analysis summary reads check the short cache before requesting');
requireText('public/index.html', 'const cached = readOnlineAnalysisResultCache(onlineAnalysisRowsResultCache, requestKey, cacheMs);', 'online analysis detail reads check the short cache before requesting');
requireText('public/index.html', 'const res = await request(`/online-data/data-analysis?${params}`);', 'online analysis summary request remains the real backend endpoint');
requireText('public/index.html', 'const res = await request(`/online-data/daily-data-list?${params}`);', 'online analysis detail request remains the real backend endpoint');
requireText('public/index.html', 'loadAnalysisData(null, loadOptions),', 'online analysis refresh passes cache options into summary reads');
requireText('public/index.html', 'loadOnlineAnalysisRows(loadOptions),', 'online analysis refresh passes cache options into detail reads');
requireText('public/index.html', 'return refreshOnlineAnalysis(options);', 'analysis tab scheduling preserves force refresh while allowing cached tab returns');
requireText('public/index.html', '@click="loadOnlineAnalysisRows({ force: true })"', 'online analysis manual detail refresh bypasses the short cache');
requireText('public/index.html', 'loadPlatformCollectionResources({\n                            force: options.force === true,\n                            cacheMs: options.force ? 0 : PLATFORM_SOURCE_PANEL_CACHE_TTL_MS,', 'platform source panel loads collection-resource status through the short panel cache');
requireNoText('public/index.html', "onlineDataTab = 'ctrip-fetch-settings'; loadCtripConfigList(); loadAutoFetchPanel()", 'Ctrip fetch settings does not load full platform auto-fetch panel');
requireNoText('public/index.html', "onlineDataTab = 'platform-sources'; loadPlatformDataSourcePanel(); loadPlatformProfileStatus({ silent: true })", 'platform sources tab does not duplicate profile status loading');
requireNoText('public/index.html', 'await loadAutoFetchPanel();\n                    return;\n                }\n                downloadCenterTab.value = tab;', 'download tab switch does not load full platform auto-fetch panel for Ctrip settings');
requireText('public/index.html', 'const scheduleDownloadCenterTabLoad = (tab, context = {}) => {', 'download center tab data loads are scheduled after the tab switches');
requireText('public/index.html', "const switchDownloadTab = (tab) => {", 'download center tab switch is non-blocking');
requireText('public/index.html', "const switchToDownloadCenter = () => {", 'Ctrip download center entry is non-blocking');
requireText('public/index.html', "const switchToMeituanDownloadCenter = () => {", 'Meituan download center entry is non-blocking');
requireText('public/meituan-static.js', 'const buildMeituanDownloadData = (rows = []) => {', 'Meituan download center computes empty data into explicit zero-valued dashboard rows');
requireText('public/index.html', 'const meituanDownloadData = computed(() => buildMeituanDownloadData(onlineDataList.value));', 'Meituan download center uses the static dashboard data builder');
requireText('public/index.html', 'switchToMeituanDownloadCenter, meituanDownloadData,', 'Meituan download center dashboard data is exposed to the Vue template');
requireNoText('public/index.html', 'const switchDownloadTab = async (tab) => {', 'download center tab switch must not serially await tab data loads');
requireNoText('public/index.html', 'const switchToDownloadCenter = async () => {', 'Ctrip download center entry must not wait on history refresh before returning');
requireNoText('public/index.html', 'const switchToMeituanDownloadCenter = async () => {', 'Meituan download center entry must not wait on list refresh before returning');
requireNoText('public/index.html', "onlineDataTab.value = 'ctrip-fetch-settings';\n                    await loadCtripConfigList();", 'Ctrip download traffic switch must not wait on config-list loading before returning');
requireText('public/index.html', "onlineDataTab.value = 'ctrip-fetch-settings';\n                    deferUiTask(async () => {\n                        if (onlineDataTab.value !== 'ctrip-fetch-settings') return null;\n                        await loadCtripConfigList({\n                            cacheMs: MANUAL_CONFIG_LIST_TAB_CACHE_TTL_MS,\n                            applySelectedConfig: false,", 'Ctrip download traffic switch defers short-cache config loading after the tab changes');
requireText('public/index.html', "await refreshOnlineHistory({ refreshHotels: false });\n                        scheduleDelayedPageTask(() => {\n                            if (!isCurrentTab()) return null;\n                            return loadOnlineHistoryHotelList();\n                        }, 720);", 'Ctrip download center loads history first and defers the hotel filter list');
requireText('public/index.html', "await loadOnlineDataList({ cacheMs: ONLINE_DATA_PANEL_CACHE_TTL_MS });\n                    if (seq !== downloadCenterTabLoadSeq || !isCurrentTab()) return null;\n                    scheduleDelayedPageTask(() => {\n                        if (seq !== downloadCenterTabLoadSeq || !isCurrentTab()) return null;\n                        return loadOnlineDataHotelList({ cacheMs: ONLINE_DATA_HOTEL_LIST_CACHE_TTL_MS });\n                    }, 720);", 'download center data tabs defer hotel filter loading after the primary list');
requireNoText('public/index.html', "downloadCenterTab.value = 'overview';\n                await refreshOnlineHistory();", 'Ctrip download center entry must schedule history refresh after switching tabs');
requireNoText('public/index.html', "await refreshOnlineHistory();\n                        return null;", 'Ctrip download center scheduled load must not refresh history with the hotel filter list in the same request group');
requireNoText('public/index.html', 'await loadOnlineDataList();\n                await loadOnlineDataHotelList();', 'Meituan download center entry must schedule list refresh after switching tabs');
requireNoText('public/index.html', 'await loadOnlineDataList();\n                    await loadOnlineDataHotelList();', 'download center history and AI tab switches must not serially await list and hotel refreshes');
requireNoText('public/index.html', "Promise.allSettled([\n                        loadOnlineDataList({ cacheMs: ONLINE_DATA_PANEL_CACHE_TTL_MS }),\n                        loadOnlineDataHotelList({ cacheMs: ONLINE_DATA_HOTEL_LIST_CACHE_TTL_MS }),\n                    ])", 'download center primary list must not wait on the hotel filter list');
requireText('public/index.html', 'Promise.all([\n                            loadAutoFetchStatus({ detail: false }),\n                            hotelsPromise,', 'platform auto-fetch first paint waits only for light status and the known hotel list');
requireText('public/index.html', 'await loadAutoFetchStatus({ detail: false });', 'platform auto-fetch first paint preserves the safe fallback after default hotel selection without waiting on static helper loading');
requireNoText('public/index.html', 'await Promise.all([\n                        loadAutoFetchStatus({ detail: false }),\n                        staticReadyPromise,\n                    ]);', 'platform auto-fetch first paint must not wait on static helper loading after default hotel selection');
requireNoText('public/index.html', 'await loadAutoFetchStatus({ detail: false });\n                    scheduleAutoFetchStatusDetailRefresh();\n                    schedulePlatformProfileStatusRefresh({ silent: true });', 'platform auto-fetch first paint must not auto-start full status and profile refreshes');
requireNoText('public/index.html', 'await loadAutoFetchStatus({ detail: false });\n                    scheduleAutoFetchConfigListPrewarm();', 'platform auto-fetch first paint must not auto-start full saved config-list prewarm');
requireText('public/index.html', 'const scheduleAutoFetchConfigListPrewarm = () => {', 'platform auto-fetch prewarms saved platform configs after first paint');
requireText('public/index.html', "const autoFetchPanelCacheKey = () => [\n                String(getAutoFetchHotelId() || ''),\n                String(hotels.value?.length || 0),\n            ].join('|');", 'platform auto-fetch panel cache key is not invalidated by deferred config-list prewarm');
requireText('public/index.html', '!ctripConfigListLoaded.value && (!ctripConfigList.value || ctripConfigList.value.length === 0)', 'platform auto-fetch does not refetch a known-empty Ctrip config list on every panel open');
requireText('public/index.html', '!meituanConfigListLoaded.value && (!meituanConfigList.value || meituanConfigList.value.length === 0)', 'platform auto-fetch does not refetch a known-empty Meituan config list on every panel open');
requireText('public/index.html', 'const scheduleAutoFetchConfigListPrewarm = () => {', 'platform auto-fetch keeps an explicit saved config-list prewarm helper');
requireNoText('public/index.html', 'await Promise.all([\n                        (!hotels.value || hotels.value.length === 0) ? loadHotels() : Promise.resolve(),\n                        (!ctripConfigList.value || ctripConfigList.value.length === 0) ? loadCtripConfigList() : Promise.resolve(),\n                        (!meituanConfigList.value || meituanConfigList.value.length === 0) ? loadMeituanConfigList() : Promise.resolve(),\n                    ]);', 'platform auto-fetch first paint must not synchronously wait for saved Ctrip/Meituan config lists');
requireText('public/index.html', 'const autoFetchPlatformConfigState = (configured, configName, loading, loaded, failed) => {', 'platform auto-fetch cards distinguish config pending and failed states');
requireText('public/index.html', "configName: '配置待读取'", 'platform auto-fetch config cards do not present unloaded configs as missing');
requireText('public/index.html', "configName: '配置读取失败'", 'platform auto-fetch config cards expose config-list load failure');
requireText('public/index.html', 'const buildCtripAutoFetchPlatformCard = (status, configured, configState) => ({', 'Ctrip platform-auto card construction stays outside the computed list');
requireText('public/index.html', 'const buildMeituanAutoFetchPlatformCard = (status, configured, configState) => ({', 'Meituan platform-auto card construction stays outside the computed list');
requireText('public/index.html', 'const ctripConfigListLoaded = ref(false);', 'Ctrip config-list loader tracks loaded state for platform-auto display');
requireText('public/index.html', 'const meituanConfigListLoaded = ref(false);', 'Meituan config-list loader tracks loaded state for platform-auto display');
requireText('public/index.html', "params.append('include_detail', '0');", 'platform auto-fetch status can request light backend status');
requireText('public/index.html', "const shouldRefreshAutoFetchStatusPanel = () => isOnlineDataTabVisible('platform-auto') || isDataHealthPanelVisible();", 'post-fetch auto-fetch status refresh is scoped to visible panels');
requireText('public/index.html', "const scheduleAutoFetchStatusRefresh = () => schedulePostFetchRefresh('auto-fetch-status', () => {", 'post-fetch status refresh uses light auto-fetch status');
requireText('public/index.html', 'if (!shouldRefreshAutoFetchStatusPanel()) return null;', 'post-fetch status refresh skips when the target panel is no longer visible');
requireText('public/index.html', 'return loadAutoFetchStatus({ detail: false });', 'post-fetch status refresh keeps the light auto-fetch status request');
requireText('public/index.html', 'if (!isOnlineDataTabVisible(\'platform-auto\')) return null;', 'post-fetch full auto-fetch status refresh skips after leaving platform-auto');
requireText('public/index.html', 'const scheduleAutoFetchStatusPanelRefresh = () => {', 'platform auto-fetch operation refreshes use a light status refresh plus deferred detail refresh');
requireText('public/index.html', 'scheduleAutoFetchStatusRefresh();\n                scheduleAutoFetchStatusDetailRefresh();', 'platform auto-fetch panel refresh helper keeps detail refresh deferred');
requireText('public/index.html', 'scheduleAutoFetchStatusPanelRefresh();', 'platform auto-fetch settings and history actions schedule status refreshes instead of loading full status inline');
requireNoText('public/index.html', 'loadAutoFetchStatus();', 'platform auto-fetch actions must not request full status with default detail inline');
requireNoText('public/index.html', 'await loadAutoFetchStatus();', 'platform auto-fetch actions must not block on full status refresh');
requireText('public/index.html', 'const autoFetchStatusRequestPromises = new Map();', 'entry deduplicates concurrent auto-fetch status requests');
requireText('public/index.html', 'const AUTO_FETCH_STATUS_RESULT_CACHE_TTL_MS = AUTO_FETCH_PANEL_CACHE_TTL_MS;', 'entry reuses the platform-auto panel TTL for recent light auto-fetch status requests across core OTA switches');
requireText('public/index.html', 'const autoFetchStatusResultCache = new Map();', 'entry tracks just-completed light auto-fetch status requests');
requireText('public/index.html', 'const resetAutoFetchStatusResultCache = () => {', 'entry can clear just-completed auto-fetch status cache before explicit refreshes');
requireText('public/index.html', "const requestKey = `${String(hotelId || '')}|${includeDetail ? 'full' : 'light'}`;", 'auto-fetch status request dedupe is scoped by hotel and detail level');
requireText('public/index.html', "if (!force && !includeDetail) {", 'auto-fetch status result cache only applies to non-forced light requests');
requireText('public/index.html', 'autoFetchStatusResultCache.set(requestKey, { expiresAt: Date.now() + AUTO_FETCH_STATUS_RESULT_CACHE_TTL_MS });', 'successful light auto-fetch status reads populate the recent result cache');
requireText('public/index.html', 'resetAutoFetchStatusResultCache();\n                return loadAutoFetchStatus({ detail: false });', 'scheduled auto-fetch status refresh clears the recent result cache before explicit refresh');
requireText('public/index.html', '@change="schedulePlatformAutoFetchPanelLoad({ force: true, delayMs: 80 })"', 'platform auto-fetch hotel switches use the deferred non-blocking panel scheduler');
requireNoText('public/index.html', '@change="loadAutoFetchStatus"', 'platform auto-fetch hotel switches must not directly trigger full status loading');
requireText('public/index.html', "loadAutoFetchStatus({ detail: normalizedMode === 'full' })", 'data-health light refresh uses light auto-fetch status');
requireText('public/index.html', "loadCollectionReliability('full')", 'data-health collection-reliability diagnostics run only in full mode');
requireNoText('public/index.html', 'loadCollectionReliability(normalizedMode)', 'data-health light first paint must not run collection-reliability');
requireText('public/index.html', 'const platformProfileStatusRequestPromises = new Map();', 'platform profile status requests are deduplicated by hotel');
requireText('public/index.html', 'ctrip_auto_fetch_mode: autoFetchMode.value', 'platform auto-fetch keeps Ctrip on the selected fast mode by default');
requireText('public/index.html', "const isCoreOtaPageVisible = () => ['online-data', 'ctrip-ebooking', 'meituan-ebooking'].includes(currentPage.value);", 'core OTA pages are explicit so background refreshes can yield to them');
requireText('public/index.html', "requireAppSystemStatic('loadCachedAuthUser')", 'entry uses extracted cached auth reader');
requireText('public/index.html', "requireAppSystemStatic('saveCachedAuthUser')", 'entry uses extracted cached auth writer');
requireText('public/index.html', "requireAppSystemStatic('clearCachedAuthUser')", 'entry uses extracted cached auth cleanup');
requireText('public/system-static.js', "const authUserCacheKey = 'suxios_auth_user_cache_v1';", 'system static caches the last verified auth user for repeat-session first paint');
requireText('public/system-static.js', 'const authUserCacheMaxAgeMs = 12 * 60 * 60 * 1000;', 'cached auth profile has a bounded freshness window');
requireText('public/system-static.js', 'const normalizePermissionMap = (permissions = null) => {', 'cached auth profile normalizes permission arrays for repeat-session menu filtering');
requireText('public/system-static.js', 'if (Array.isArray(permissions)) {', 'cached auth profile accepts array-shaped permissions');
requireText('public/system-static.js', 'const hasPermission = (permissions, key) => {', 'visible menu filter accepts both object and array permission payloads');
requireText('public/system-static.js', 'if (Array.isArray(permissions)) return permissions.includes(key);', 'visible menu filter keeps array permission payloads usable');
requireText('public/system-static.js', 'if (now - Number(payload.saved_at || 0) > authUserCacheMaxAgeMs) return null;', 'expired cached auth profile is ignored');
requireText('public/index.html', 'const cachedAuthUser = token.value ? loadCachedAuthUser() : null;', 'entry loads cached auth user only when a token exists');
requireText('public/index.html', 'const isLoggedIn = ref(!!token.value && !!cachedAuthUser);', 'entry can render the app shell before auth/info returns for repeat sessions');
requireText('public/index.html', 'const user = ref(cachedAuthUser);', 'entry uses cached auth profile for initial permission filtering');
requireText('public/index.html', 'const cachedPermittedHotels = Array.isArray(cachedAuthUser?.permitted_hotels) ? cachedAuthUser.permitted_hotels : [];', 'entry seeds hotel options from cached auth profile');
requireText('public/index.html', 'saveCachedAuthUser(res.data);', 'auth/info refreshes the cached auth profile after verification');
requireText('public/index.html', 'isLoggedIn.value = true;\n                            loadData();', 'auth/info success remains the verified login source after cached first paint');
requireText('public/index.html', 'saveCachedAuthUser(res.data.user);', 'login success writes the cached auth profile');
requireText('public/index.html', 'if (!hotels.value.length && permittedHotels.value.length) {\n                            hotels.value = dedupeHotels(permittedHotels.value);\n                        }', 'login/auth verification seeds hotel options from permitted hotels before full hotel-list refresh');
requireText('public/index.html', 'const clearAuthSession = () => {', 'auth cleanup clears token and cached auth user together');
requireText('public/index.html', 'clearCachedAuthUser();', 'auth cleanup removes cached auth profile');
requireText('public/index.html', 'const scheduleInitialCompassLoad = (options = {}) => {', 'initial compass loading is scheduled instead of blocking fast OTA navigation');
requireText('public/index.html', "scheduleInitialCompassLoad({ force: true, delayMs: 4500 });", 'login startup leaves a larger window for fast OTA page switches before compass loading');
requireText('public/index.html', 'const HOME_SECONDARY_PANEL_DELAY_MS = 4200;', 'home lower panels are delayed so immediate OTA navigation has a lighter first interaction window');
requireText('public/index.html', 'const homeSecondaryPanelsReady = ref(false);', 'home lower panel rendering is gated behind an explicit readiness flag');
requireText('public/index.html', 'const scheduleHomeSecondaryPanelsReady = (delayMs = HOME_SECONDARY_PANEL_DELAY_MS) => {', 'home lower panel readiness is scheduled and cancellable');
requireText('public/index.html', 'clearHomeSecondaryPanelsReadyTimer();\n                    homeSecondaryPanelsReady.value = false;\n                    destroyHomeTrendChart();', 'leaving the home page cancels delayed lower-panel rendering');
requireText('public/index.html', "homeSecondaryPanelsReady.value = false;\n                    scheduleHomeSecondaryPanelsReady();\n                    runPageLoadOnce(newPage, 'main', () => loadCompassData());", 'entering the home page delays lower-panel rendering without prewarming platform auto-fetch helpers');
requireNoText('public/index.html', "runPageLoadOnce(newPage, 'auto-fetch-static', () => ensureAutoFetchStaticReady())", 'home page first paint must not prewarm auto-fetch-static.js');
requireNoText('public/index.html', "runPageLoadOnce('compass', 'auto-fetch-static', () => ensureAutoFetchStaticReady(), runOptions)", 'initial compass reload must not prewarm auto-fetch-static.js');
requireText('public/index.html', '<div v-if="homeSecondaryPanelsReady" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm mb-6" data-testid="daily-ops-monitor-card">', 'home daily ops panel is not mounted during the immediate OTA navigation window');
requireText('public/index.html', '<div v-if="homeSecondaryPanelsReady" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm mb-6" data-testid="home-weather-demand-card">', 'home weather panel is not mounted during the immediate OTA navigation window');
requireText('public/index.html', '<div v-if="homeSecondaryPanelsReady" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm mb-6" data-testid="home-market-signal-card">', 'home market signal panel is not mounted during the immediate OTA navigation window');
requireText('public/index.html', '<div v-if="homeSecondaryPanelsReady && homeTrendCards.length"', 'home trend cards are not mounted during the immediate OTA navigation window');
requireText('public/index.html', 'homeSecondaryPanelsReady, homeClosedLoopStages', 'home lower-panel readiness flag is returned for template gating');
requireText('public/index.html', 'const scheduleInitialBackendNotificationRefresh = (delayMs = 8000) => {', 'startup backend notification refresh is delayed behind core OTA navigation');
requireText('public/index.html', 'if (isLoggedIn.value && token.value && !isCoreOtaPageVisible()) {', 'notification polling is paused while core OTA pages are visible');
requireText('public/index.html', 'const loadHotelsRequestPromises = new Map();', 'hotel-list requests are deduplicated while a matching request is in flight');
requireText('public/index.html', 'if (loadHotelsRequestPromises.has(requestKey))', 'hotel-list loader reuses in-flight requests');
requireText('public/index.html', 'loadHotelsRequestPromises.set(requestKey, run);', 'hotel-list loader records the in-flight request before returning');
requireText('public/index.html', 'const scheduleStartupHotelListLoad = (delayMs = null) => {', 'login startup uses a scheduler for the full hotel list');
requireText('public/index.html', 'if (!hasKnownHotelOptions()) {\n                    return loadHotels({ cacheMs: HOTEL_LIST_CACHE_TTL_MS });\n                }', 'startup hotel scheduler only loads immediately when no hotel context is available');
requireText('public/index.html', 'if (!isLoggedIn.value || !token.value || isCoreOtaPageVisible()) return null;', 'startup hotel scheduler yields while core OTA pages are visible');
requireText('public/index.html', 'scheduleStartupHotelListLoad();\n                schedulePublicSystemConfigRefresh(1800);', 'loadData schedules hotel loading before public system config refresh');
requireNoText('public/index.html', 'const loadData = async () => {\n                loadHotels({ cacheMs: HOTEL_LIST_CACHE_TTL_MS });', 'login startup must not request /hotels/all directly on first paint');
requireText('public/index.html', 'const scheduleCtripEbookingDeferredStartupRefresh = () => {\n                scheduleDelayedPageTask(async () => {', 'Ctrip manual startup refresh is delayed outside first paint');
requireText('public/index.html', 'const CTRIP_EBOOKING_STARTUP_CONFIG_DELAY_MS = 2600;', 'Ctrip manual startup config-list read stays outside the first interaction window while preserving on-demand fetch config reads');
requireText('public/index.html', 'const CTRIP_EBOOKING_LATEST_DATA_DELAY_MS = 5200;', 'Ctrip latest-data refresh stays outside the first interaction window');
requireText('public/index.html', 'const CTRIP_EBOOKING_COOKIE_STATUS_DELAY_MS = 6400;', 'Ctrip cookie-list refresh stays outside the first interaction window');
requireText('public/index.html', 'const CTRIP_EBOOKING_BOOKMARKLET_DELAY_MS = 7600;', 'Ctrip bookmarklet loading stays outside the first interaction window');
requireText('public/index.html', 'const CTRIP_EBOOKING_MODULE_CARD_DELAY_MS = 1000;', 'Ctrip manual module fetch cards yield to the immediate one-click fetch controls');
requireText('public/index.html', 'const ctripEbookingModuleCardsReady = ref(false);', 'Ctrip manual module-card readiness uses explicit state');
requireText('public/index.html', '<div v-if="ctripEbookingModuleCardsReady" class="px-4 py-3 border-b bg-gray-50 grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-2">', 'Ctrip manual status cards are delayed with module fetch cards outside the click frame');
requireText('public/index.html', '<div v-if="ctripEbookingModuleCardsReady" data-testid="ctrip-overview-module-cards" class="p-4">', 'Ctrip manual module fetch cards are not mounted during the first click frame');
requireText('public/index.html', 'const CTRIP_EBOOKING_SECONDARY_PANEL_DELAY_MS = 4200;', 'Ctrip manual overview secondary panels are delayed behind the first interaction and light-refresh window');
requireText('public/index.html', 'const ctripEbookingSecondaryPanelsReady = ref(false);', 'Ctrip manual overview secondary panel readiness uses explicit state');
requireText('public/index.html', '<div v-if="ctripEbookingSecondaryPanelsReady" class="space-y-4">', 'Ctrip manual overview secondary panels are not mounted during the immediate fetch-control window');
requireText('public/index.html', 'const CTRIP_EBOOKING_DEEP_PANEL_DELAY_MS = 6200;', 'Ctrip manual deep overview panels are delayed behind lightweight reminders and work orders');
requireText('public/index.html', 'const ctripEbookingDeepPanelsReady = ref(false);', 'Ctrip manual deep overview readiness uses explicit state');
requireText('public/index.html', '<div v-if="ctripEbookingDeepPanelsReady" class="space-y-4">', 'Ctrip manual deep business and diagnostics panels are not mounted with lightweight secondary panels');
requireText('public/index.html', 'const CTRIP_EBOOKING_BUSINESS_DETAIL_DELAY_MS = 8200;', 'Ctrip manual detailed business panels are delayed behind the first deep revenue overview');
requireText('public/index.html', 'const ctripEbookingBusinessDetailsReady = ref(false);', 'Ctrip manual detailed business readiness uses explicit state');
requireText('public/index.html', '<div v-if="ctripEbookingBusinessDetailsReady" data-testid="ctrip-store-overview-business-details" class="space-y-4">', 'Ctrip manual traffic/funnel/detail business panels are not mounted with the first deep revenue overview');
requireText('public/index.html', 'const ctripEbookingDiagnosticsPanelsReady = ref(false);', 'Ctrip manual diagnostics readiness uses explicit state');
requireText('public/index.html', '@toggle="handleCtripEbookingDiagnosticsToggle"', 'Ctrip manual collapsed diagnostics mount content only after first expansion');
requireText('public/index.html', '<div v-if="ctripEbookingDiagnosticsPanelsReady" class="p-4 border-t space-y-4">', 'Ctrip manual collapsed diagnostics content is not mounted while collapsed');
requireText('public/index.html', '}, CTRIP_EBOOKING_STARTUP_CONFIG_DELAY_MS);\n                scheduleDelayedPageTask(() => {', 'Ctrip manual startup config-list read uses the explicit short delay constant');
requireNoText('public/index.html', "prewarmSelectedCtripConfigSecret();\n                    return null;\n                }, 1800);", 'Ctrip manual startup config-list read must not use an unlabeled hard-coded delay');
requireText('public/index.html', 'return loadLatestCtripData({ silent: true });\n                }, CTRIP_EBOOKING_LATEST_DATA_DELAY_MS);', 'Ctrip latest-data refresh uses a long explicit delay');
requireText('public/index.html', 'return loadCookiesList();\n                }, CTRIP_EBOOKING_COOKIE_STATUS_DELAY_MS);', 'Ctrip cookie-list refresh uses a long explicit delay');
requireText('public/index.html', 'return loadBookmarklet();\n                }, CTRIP_EBOOKING_BOOKMARKLET_DELAY_MS);', 'Ctrip bookmarklet loading uses a long explicit delay');
requireNoText('public/index.html', 'return loadLatestCtripData({ silent: true });\n                }, 2400);', 'Ctrip latest-data refresh must not compete with the first interaction window');
requireNoText('public/index.html', 'return loadCookiesList();\n                }, 3000);', 'Ctrip cookie-list refresh must not compete with the first interaction window');
requireNoText('public/index.html', 'return loadBookmarklet();\n                }, 3600);', 'Ctrip bookmarklet loading must not compete with the first interaction window');
requireText('public/index.html', 'const MEITUAN_EBOOKING_STARTUP_CONFIG_DELAY_MS = 16;', 'Meituan manual startup config-list read starts near immediately without blocking route entry');
requireText('public/index.html', 'const MEITUAN_EBOOKING_SECONDARY_CONFIG_DELAY_MS = 5200;', 'Meituan manual secondary config refresh stays outside the first interaction window');
requireText('public/index.html', 'const MEITUAN_EBOOKING_HOTEL_LIST_DELAY_MS = 6400;', 'Meituan manual hotel-list refresh stays outside the first interaction window');
requireText('public/index.html', 'const scheduleMeituanEbookingDeferredStartupRefresh = () => {\n                ensureMeituanManualHotelSelected();\n                scheduleDelayedPageTask(async () => {', 'Meituan manual startup refresh is delayed outside first paint');
requireText('public/index.html', 'const resolveMeituanManualDefaultHotelId = () => {', 'Meituan manual fetch resolves a default hotel before fast local config matching');
requireText('public/index.html', 'const ensureMeituanManualHotelSelected = () => {', 'Meituan manual fetch sets the current hotel context before fast local matching');
requireText('public/index.html', "onlineDataTab.value = 'meituan-ranking';\n                    ensureMeituanManualHotelSelected();", 'Meituan route entry selects hotel context before startup refresh scheduling');
requireText('public/index.html', 'const openMeituanManualTab = (tab) => {\n                onlineDataTab.value = tab;\n                ensureMeituanManualHotelSelected();', 'Meituan manual tab switching keeps the hotel context ready for fast local matching');
requireText('public/index.html', 'if (suppressNextMeituanHotelConfigApply) {\n                    suppressNextMeituanHotelConfigApply = false;\n                    return;', 'programmatic Meituan hotel selection does not trigger an extra immediate config-list match');
requireText('public/index.html', '}, MEITUAN_EBOOKING_STARTUP_CONFIG_DELAY_MS);\n                scheduleDelayedPageTask(() => {', 'Meituan manual startup config-list read is not scheduled at 0ms');
requireNoText('public/index.html', "prewarmSelectedMeituanConfigSecret();\n                    if (onlineDataTab.value === 'meituan-ranking') {\n                        scheduleMeituanHotelConfigApply({\n                            delayMs: 120,\n                            refreshList: false,\n                            skipIfAligned: true,\n                        });\n                    }\n                    return null;\n                }, 0);", 'Meituan manual startup config-list read must not fire immediately on route entry');
requireText('public/index.html', 'return loadMeituanConfig();\n                }, MEITUAN_EBOOKING_SECONDARY_CONFIG_DELAY_MS);', 'Meituan secondary config refresh uses a long explicit delay');
requireText('public/index.html', 'return loadOnlineDataHotelList({ cacheMs: ONLINE_DATA_HOTEL_LIST_CACHE_TTL_MS });\n                }, MEITUAN_EBOOKING_HOTEL_LIST_DELAY_MS);', 'Meituan hotel-list loading is staggered behind the first interaction window and uses the short hotel-list cache');
requireNoText('public/index.html', 'return loadMeituanConfig();\n                }, 2400);', 'Meituan secondary config refresh must not compete with the first interaction window');
requireNoText('public/index.html', 'return loadOnlineDataHotelList({ cacheMs: ONLINE_DATA_HOTEL_LIST_CACHE_TTL_MS });\n                }, 3000);', 'Meituan hotel-list refresh must not compete with the first interaction window');
requireNoText('public/index.html', 'return Promise.allSettled([\n                        loadCtripConfigList().then(() => {', 'Ctrip manual page must not start config/latest/cookies/bookmarklet in parallel on first paint');
requireNoText('public/index.html', 'return Promise.allSettled([\n                        loadMeituanConfigList().then(() => prewarmSelectedMeituanConfigSecret()),', 'Meituan manual page must not start config/hotel-list in parallel on first paint');
requireText('public/index.html', 'const isCompassDataPage = (page = currentPage.value) => [\'ai-workbench\', \'compass\'].includes(page);', 'AI workbench and business overview share the verified compass data loader');
requireText('public/index.html', "if (!token.value || !isCompassDataPage()) return;", 'home trend and holiday requests do not run after leaving compass-data pages');
requireText('public/index.html', "if (!token.value || !isCompassDataPage() || macroSignalLoading.value) return;", 'macro signal request does not run after leaving compass-data pages');
requireText('public/index.html', "if (options.requireCompass === true && !isCompassDataPage()) return;", 'home competitor summary request can be scoped to compass-data pages');
requireText('public/index.html', 'const delay = Number.isFinite(options.delay) ? options.delay : 1800;', 'Meituan manual ranking summary is delayed so first paint stays responsive');
requireText('public/index.html', 'scheduleDelayedPageTask(async () => {\n                    if (currentPage.value !== \'meituan-ebooking\' || onlineDataTab.value !== \'meituan-ranking\') return;', 'Meituan manual ranking summary skips after page switches');
requireText('public/index.html', 'if (force) {\n                        await loadCompetitorSummary({ includeByHotel: false });\n                        return;\n                    }', 'Meituan manual ranking summary only requests competitor summary when forced');
requireNoText('public/index.html', 'if (force || !competitorSummary.value) {\n                        await loadCompetitorSummary({ includeByHotel: false });', 'Meituan manual page must not auto-start slow competitor summary on first paint');
requireText('public/index.html', "if (currentPage.value !== 'compass') return;", 'weather request does not run after leaving the compass page');
requireText('public/index.html', 'const COMPASS_WEATHER_REFRESH_DELAY_MS = 3200;', 'compass weather refresh stays outside the fast OTA navigation window');
requireText('public/index.html', "scheduleDelayedPageTask(() => {\n                            if (!isCompassDataPage()) return null;\n                            loadWeatherForCity();\n                            return null;\n                        }, COMPASS_WEATHER_REFRESH_DELAY_MS);", 'compass response delays weather and skips after leaving compass-data pages');
requireText('public/index.html', "if (!isCompassDataPage()) return null;", 'deferred compass background jobs are skipped after page switch');
requireText('public/index.html', 'loadCompetitorSummary({ requireCompass: true })', 'deferred compass competitor summary uses page visibility guard');
requireText('public/index.html', 'const compassBackgroundJobs = [', 'deferred compass background jobs are queued explicitly');
requireText('public/index.html', 'await job();', 'deferred compass background jobs run serially instead of in parallel');
requireText('public/index.html', '}, 1200);', 'deferred compass background jobs leave a short window for fast page switches');
requireOnlineDataControllerText("?? $options['auto_fetch_mode'];", 'backend auto-fetch defaults Ctrip mode to the selected auto-fetch mode');
requireOnlineDataControllerText("get('include_detail'", 'backend auto-fetch status supports light detail requests');
requireOnlineDataControllerText("'detail_loaded' => false", 'backend auto-fetch status marks light responses explicitly');
{
  const source = readOnlineDataControllerSource();
  const lightStatusMatch = source.match(/\} else \{\s+\$status\['missed_dates'\] = \[\];\s+\$status\['missed_count'\] = null;([\s\S]*?)\$status\['detail_loaded'\] = false;/);
  const lightStatusBranch = lightStatusMatch ? lightStatusMatch[1] : '';
  checks.push({
    file: onlineDataControllerFiles.join(' + '),
    label: 'backend light auto-fetch status does not run full config/profile diagnostics',
    ok: source.includes('private function buildAutoFetchPlatformLightStatus')
      && lightStatusBranch.includes('buildAutoFetchPlatformLightStatus')
      && !lightStatusBranch.includes('hasAnyPlatformFetchConfigForHotel')
      && !lightStatusBranch.includes('buildAutoFetchPlatformStatus'),
    detail: 'include_detail=false branch must use buildAutoFetchPlatformLightStatus only',
  });
  const lightHelperMatch = source.match(/private function buildAutoFetchPlatformLightStatus\(int \$hotelId, array \$status\): array\s+\{([\s\S]*?)\n    private function autoFetchPlatformsHaveConfig/);
  const lightHelperSource = lightHelperMatch ? lightHelperMatch[1] : '';
  checks.push({
    file: onlineDataControllerFiles.join(' + '),
    label: 'backend light auto-fetch platform status uses raw read-only config resolvers',
    ok: lightHelperSource.includes('resolveCtripFetchConfigForHotelLight')
      && lightHelperSource.includes('resolveMeituanFetchConfigForHotelLight')
      && !lightHelperSource.includes('resolveCtripFetchConfigForHotel($hotelId)')
      && !lightHelperSource.includes('resolveMeituanFetchConfigForHotel($hotelId)')
      && source.includes('private function getStoredCtripConfigListRaw')
      && source.includes('private function getStoredMeituanConfigListRaw'),
    detail: 'buildAutoFetchPlatformLightStatus must not normalize or write stored platform config lists',
  });
  checks.push({
    file: onlineDataControllerFiles.join(' + '),
    label: 'backend light auto-fetch platform status short-caches read-only dependencies',
    ok: source.includes('private const AUTO_FETCH_LIGHT_READ_CACHE_TTL_SECONDS = 5;')
      && source.includes('private array $autoFetchLightReadCache = [];')
      && source.includes('readAutoFetchLightReadCache($cacheKey)')
      && source.includes('writeAutoFetchLightReadCache($cacheKey, $list)')
      && source.includes("writeAutoFetchLightReadCache($cacheKey, array_values(array_filter($rows, 'is_array')))"),
    detail: 'light status reads should reuse recent config-list and browser-profile source reads without caching success/failure results',
  });
  const onlineDataControllerSource = readOnlineDataControllerSource();
  checks.push({
    file: onlineDataControllerFiles.join(' + '),
    label: 'backend light auto-fetch read caches are cleared after config and source mutations',
    ok: onlineDataControllerSource.includes("clearAutoFetchLightConfigListCache('ctrip')")
      && onlineDataControllerSource.includes("clearAutoFetchLightConfigListCache('meituan')")
      && onlineDataControllerSource.includes("clearAutoFetchLightProfileSourcesCache((int)($data['system_hotel_id'] ?? 0)")
      && onlineDataControllerSource.includes('clearAutoFetchLightProfileSourcesCache($hotelId, $platform)'),
    detail: 'config and browser-profile source writes must invalidate the short light-status read caches',
  });
}
requireText('public/index.html', 'const buildDataHealthPanelJobs = (normalizedMode) =>', 'entry builds data-health panel jobs outside the main loader');
requireText('public/index.html', 'const scheduleDataHealthLightDiagnostics = () =>', 'entry defers non-core light data-health diagnostics through a helper');
requireText('public/index.html', "return schedulePostFetchRefresh('data-health-light-diagnostics', () => {", 'data-health light diagnostics use the shared deduplicated post-fetch scheduler');
requireText('public/index.html', "if (currentPage.value !== 'online-data' || onlineDataTab.value !== 'data-health') return null;", 'data-health light diagnostics do not run after the user leaves the data-health tab');
requireNoText('public/index.html', 'const scheduleDataHealthLightDiagnostics = () => {\n                deferUiTask(() => Promise.allSettled([', 'data-health light diagnostics must not use a bare deferred task without tab guards');
requireText('public/index.html', "const initialHotelId = String(getAutoFetchHotelId() || '');\n                const initialCacheKey = dataHealthLightCacheKey(initialHotelId);", 'data-health light cache is checked before target hotel sync');
requireText('public/index.html', "if (normalizedMode === 'light' && !force && cacheKey !== initialCacheKey) {", 'data-health light cache is rechecked only when target hotel sync changes the cache key');
requireText('public/index.html', 'const jobs = buildDataHealthPanelJobs(normalizedMode);', 'data-health panel loader uses extracted job composition');
requireNoText('public/index.html', 'scheduleDataHealthLightDiagnostics();', 'light data-health first paint must not auto-run non-core diagnostics');
requireNoText('public/index.html', 'loadCookieStatus(),\n                    loadCollectionReliability(normalizedMode)', 'data-health panel must not call cookie-status and collection-reliability in the same first-paint group');
requireText('public/index.html', "if (!options.backendOnly) {\n                        scheduleDataHealthPanelRefresh('light');\n                    }\n                    await loadBackendGlobalNotifications();", 'global notification refresh schedules data-health status without waiting on it');
requireNoText('public/index.html', "const jobs = [loadBackendGlobalNotifications()];\n                    if (!options.backendOnly) {\n                        jobs.push(loadDataHealthPanel('light'));\n                    }", 'global notification refresh must not block on data-health light status');
requireText('public/index.html', 'const ensureManualOnlineFetchConfigReady = async', 'entry prewarms saved platform configs for manual online-data fetch');
requireText('public/index.html', 'const MANUAL_CONFIG_LIST_TAB_CACHE_TTL_MS = 15000;', 'manual Ctrip/Meituan tab switching reuses recently loaded config lists');
requireText('public/index.html', 'loadConfigList: () => loadCtripConfigList({\n                        cacheMs: MANUAL_CONFIG_LIST_TAB_CACHE_TTL_MS,\n                        applySelectedConfig: false,\n                    })', 'Ctrip manual tab switching avoids repeat config-list requests and implicit config application within the short cache window');
requireText('public/index.html', 'loadConfigList: () => loadMeituanConfigList({\n                        cacheMs: MANUAL_CONFIG_LIST_TAB_CACHE_TTL_MS,\n                        applySelectedConfig: false,\n                    })', 'Meituan manual tab switching avoids repeat config-list requests and implicit config application within the short cache window');
requireText('public/index.html', 'let ctripConfigListLoadingPromise = null;', 'entry deduplicates concurrent Ctrip config-list loads');
requireText('public/index.html', 'if (ctripConfigListLoadingPromise) {\n                    return ctripConfigListLoadingPromise;', 'Ctrip config-list loader reuses in-flight requests');
requireText('public/index.html', ':disabled="fetchingData || !canFetchCtripManualData()"', 'Ctrip ranking and traffic manual fetch buttons stay clickable while config proof is pending');
requireText('public/index.html', 'const ctripManualFetchConfigProofPending = () => {', 'Ctrip manual fetch can recognize pending config proof');
requireText('public/index.html', 'return !!ctripConfigListLoadingPromise', 'Ctrip manual fetch reuses an in-flight config-list proof request');
requireText('public/index.html', 'const resolveCtripManualFetchConfig = async (config) => {', 'Ctrip manual fetch resolves config before backend submission');
requireText('public/index.html', 'ensureCtripConfigSecret: async config => ensureCtripConfigSecret(await resolveCtripManualFetchConfig(config))', 'Ctrip manual fetch waits for pending config proof without misreporting missing config');
requireText('public/index.html', 'let ctripConfigListLoadedAt = 0;', 'Ctrip config-list loader records recent successful loads for short tab-switch caching');
requireText('public/index.html', 'let meituanConfigListLoadedAt = 0;', 'Meituan config-list loader records recent successful loads for short tab-switch caching');
requireText('public/index.html', 'const ctripConfigDetailCache = new Map();', 'entry caches full Ctrip config details for manual-fetch hotel switching');
requireText('public/index.html', 'const ctripConfigDetailLoadingPromises = new Map();', 'entry deduplicates concurrent full Ctrip config detail loads');
requireText('public/index.html', 'const ensureCtripConfigSecret = async (config, options = {}) => {', 'Ctrip full config detail loader supports silent background prewarm');
requireText('public/index.html', "console.error('[CTrip] 预热完整配置失败:', e);", 'Ctrip config-detail prewarm failure stays silent to the user');
requireText('public/index.html', 'const prewarmSelectedCtripConfigSecret = (config = findCtripConfigByHotelId(selectedCtripHotelId.value)) => {', 'entry can prewarm selected Ctrip config detail without blocking manual fetch UI');
requireText('public/index.html', 'deferUiTask(() => ensureCtripConfigSecret(config, { silent: true }), 80);', 'Ctrip selected config detail prewarm is scheduled outside the current interaction');
requireText('public/index.html', 'const scheduleCtripHotelConfigApply = (event = null, options = {}) => {', 'Ctrip hotel selection uses a non-blocking config apply scheduler');
requireText('public/index.html', 'const applyVersion = ++ctripHotelConfigApplyVersion;', 'Ctrip hotel selection ignores stale deferred full-config responses');
requireText('public/index.html', 'const config = await ensureCtripConfigSecret(configSource, { silent: true });', 'Ctrip hotel selection loads full config detail only in silent deferred work');
requireText('public/index.html', '@change="scheduleCtripHotelConfigApply"', 'Ctrip manual hotel selects use the non-blocking selection handler');
requireNoText('public/index.html', '@change="applyCtripHotelConfig"', 'Ctrip manual hotel selects must not block on full config detail application');
requireText('public/index.html', "clearCtripConfigDetailCache(body?.id || '');", 'entry invalidates Ctrip config detail cache after manual config saves');
requireText('public/index.html', 'const scheduleCtripEbookingDeferredStartupRefresh = () => {', 'Ctrip manual page defers non-first-paint startup refreshes');
requireText('public/index.html', "if (currentPage.value !== 'ctrip-ebooking') return null;", 'deferred Ctrip manual startup refresh is scoped to the active page');
requireText('public/index.html', "await loadCtripConfigList({\n                        cacheMs: MANUAL_CONFIG_LIST_TAB_CACHE_TTL_MS,\n                        applySelectedConfig: false,\n                    });\n                    if (currentPage.value !== 'ctrip-ebooking') return null;\n                    prewarmSelectedCtripConfigSecret();", 'Ctrip manual page prewarms selected config detail during delayed deferred startup refresh through the short config-list cache');
requireText('public/index.html', "const syncCtripOverviewTargetHotel = async ({ clearDisplay = false, loadConfig = true } = {}) =>", 'Ctrip overview hotel switching stays in the shared target-hotel synchronizer');
requireText('public/index.html', "if (!ctripConfigList.value.length) {\n                        await loadCtripConfigList({\n                            cacheMs: MANUAL_CONFIG_LIST_TAB_CACHE_TTL_MS,\n                            applySelectedConfig: false,", 'Ctrip overview hotel switching reuses the short config-list cache before applying manual fetch config');
requireText('public/index.html', "await syncCtripOverviewTargetHotel({ clearDisplay: true, loadConfig: true });\n                scheduleDataHealthPanelRefresh('light', { force: true });", 'Ctrip overview hotel switching schedules data-health refresh without waiting on it');
requireNoText('public/index.html', "await syncCtripOverviewTargetHotel({ clearDisplay: true, loadConfig: true });\n                await loadDataHealthPanel('light');", 'Ctrip overview hotel switching must not wait on data-health light status');
requireText('public/index.html', "if (selectedCtripHotelId.value && shouldApplySelectedConfig) {\n                                prewarmSelectedCtripConfigSecret();\n                                deferUiTask(() => applyCtripHotelConfig(false, {\n                                    refreshList: false,\n                                    skipIfAligned: true,", 'Ctrip config-list loader does not wait for full config detail before returning');
requireNoText('public/index.html', "if (selectedCtripHotelId.value) {\n                                await applyCtripHotelConfig(false);\n                            }\n                            return ctripConfigList.value;", 'Ctrip config-list loader must not wait for full config detail application');
requireText('public/index.html', 'const CTRIP_EBOOKING_DATA_HEALTH_REFRESH_DELAY_MS = 1600;', 'Ctrip manual light health status read stays outside the immediate interaction window');
requireText('public/index.html', "runPageLoadOnce(newPage, 'main', () => {\n                        scheduleDelayedPageTask(() => {\n                            if (!isCtripEbookingDataHealthVisible()) return null;\n                            scheduleDataHealthPanelRefresh('light');\n                            return null;\n                        }, CTRIP_EBOOKING_DATA_HEALTH_REFRESH_DELAY_MS);", 'Ctrip manual page delays light health status without blocking the page switch');
requireNoText('public/index.html', "runPageLoadOnce(newPage, 'main', async () => {\n                        await loadDataHealthPanel('light');", 'Ctrip manual page first paint must not await light health status during page switching');
requireNoText('public/index.html', "await Promise.allSettled([\n                            loadOnlineDataHotelList(),\n                            loadDataHealthPanel('light'),\n                        ]);", 'Ctrip manual page first paint must not block on hotel-list loading');
requireNoText('public/index.html', "runPageLoadOnce(newPage, 'main', () => Promise.allSettled([\n                        loadOnlineDataHotelList(),\n                        loadCtripConfigList().then(() => loadLatestCtripData({ silent: true })),\n                        loadDataHealthPanel('light'),\n                        loadCookiesList(),\n                        loadBookmarklet(),\n                    ]));", 'Ctrip manual page must not start config/latest/cookie/bookmarklet work in the first-paint loader');
requireText('public/index.html', 'const openCtripManualTab = (tab) => {', 'Ctrip manual tabs use a non-blocking tab switch helper');
requireText('public/index.html', 'deferUiTask(() => runCtripManualTabSwitch({', 'Ctrip manual tab switch delegates async branching to static helper');
requireText('public/index.html', 'getCurrentPage: () => currentPage.value', 'Ctrip manual tab static helper receives active page reader');
requireText('public/index.html', 'getCurrentTab: () => onlineDataTab.value', 'Ctrip manual tab static helper receives active tab reader');
requireText('public/index.html', 'loadDataHealthPanel: scheduleDataHealthPanelRefresh', 'Ctrip manual data-health tab schedules light status refresh after switching');
requireText('public/index.html', "if (tab === 'data-health') {\n                    ctripEbookingModuleCardsReady.value = false;\n                    scheduleCtripEbookingModuleCardsReady();", 'Ctrip manual data-health tab schedules delayed module fetch cards after switching');
requireText('public/index.html', "ctripEbookingSecondaryPanelsReady.value = false;\n                    scheduleCtripEbookingSecondaryPanelsReady();", 'Ctrip manual data-health tab schedules delayed secondary overview panels after switching');
requireText('public/index.html', "ctripEbookingDeepPanelsReady.value = false;\n                    scheduleCtripEbookingDeepPanelsReady();", 'Ctrip manual data-health tab schedules deep business and diagnostics panels after lightweight secondary panels');
requireText('public/index.html', "ctripEbookingBusinessDetailsReady.value = false;\n                    scheduleCtripEbookingBusinessDetailsReady();", 'Ctrip manual data-health tab schedules detailed business panels after the first deep revenue overview');
requireText('public/index.html', "ctripEbookingDiagnosticsPanelsReady.value = false;", 'Ctrip manual diagnostics content readiness resets on page and tab entry');
requireText('public/index.html', "if (newPage !== 'ctrip-ebooking') {\n                    clearCtripEbookingModuleCardsReadyTimer();\n                    ctripEbookingModuleCardsReady.value = false;\n                    clearCtripEbookingSecondaryPanelsReadyTimer();\n                    ctripEbookingSecondaryPanelsReady.value = false;\n                    clearCtripEbookingDeepPanelsReadyTimer();\n                    ctripEbookingDeepPanelsReady.value = false;\n                    clearCtripEbookingBusinessDetailsReadyTimer();\n                    ctripEbookingBusinessDetailsReady.value = false;\n                    ctripEbookingDiagnosticsPanelsReady.value = false;\n                }", 'Ctrip manual delayed panels are cleared when leaving the page');
requireNoText('public/index.html', "loadDataHealthPanel,\n                    loadConfigList: () => loadCtripConfigList({ cacheMs: MANUAL_CONFIG_LIST_TAB_CACHE_TTL_MS })", 'Ctrip manual data-health tab must not pass the blocking panel loader into the click path');
requireText('public/index.html', '@click="openCtripManualTab(\'data-health\')"', 'Ctrip data-health tab uses the non-blocking tab helper');
requireText('public/index.html', '@click="openCtripManualTab(\'ctrip-flow-overview\')"', 'Ctrip flow overview tab does not inline config-list loading');
requireText('public/index.html', '@click="openCtripManualTab(\'ctrip-fetch-settings\')"', 'Ctrip fetch settings tab does not inline config-list loading');
requireText('public/index.html', '@click="openCtripManualTab(\'ctrip-ads\')"', 'Ctrip ads tab does not inline ad-config sync');
requireNoText('public/index.html', "onlineDataTab = 'ctrip-flow-overview'; loadCtripConfigList()", 'Ctrip manual tabs must not synchronously request saved configs from inline click handlers');
requireNoText('public/index.html', "onlineDataTab = 'ctrip-fetch-settings'; loadCtripConfigList()", 'Ctrip fetch settings tab must not synchronously request saved configs from inline click handlers');
requireNoText('public/index.html', "onlineDataTab = 'ctrip-ads'; syncCtripAdsDirectConfig(false)", 'Ctrip ads tab must not synchronously sync ad config from inline click handlers');
requireNoText('public/index.html', "await loadCtripConfigList();\n                        if (currentPage.value !== 'ctrip-ebooking' || onlineDataTab.value !== tab) return null;", 'Ctrip manual tab switch must not re-inline config loading and stale-tab checks');
requireText('public/index.html', 'const refreshCtripHotelConfigOptions = () => {', 'Ctrip manual config refresh button is non-blocking');
requireText('public/index.html', 'await Promise.allSettled([loadHotels(), loadCtripConfigList({', 'Ctrip manual config refresh loads hotel/config lists in deferred work');
requireText('public/index.html', 'applySelectedConfig: false,', 'Ctrip manual config refresh loads config lists without implicit form application');
requireNoText('public/index.html', 'const refreshCtripHotelConfigOptions = async () => {', 'Ctrip manual config refresh button must not wait on config-list loading before returning');
requireNoText('public/index.html', 'await Promise.all([loadHotels(), loadCtripConfigList()]);\n                await applyCtripHotelConfig(false);', 'Ctrip manual config refresh must not serially await hotel/config refreshes inline');
requireText('public/index.html', "const openCtripOverviewFetchTab = async (tabName) => {\n                currentPage.value = 'ctrip-ebooking';\n                if (autoFetchHotelId.value) {", 'Ctrip overview external entry keeps the route switch first before syncing the selected hotel');
requireText('public/index.html', "onlineDataTab.value = tabName;\n                deferUiTask(async () => {\n                    if (currentPage.value !== 'ctrip-ebooking' || onlineDataTab.value !== tabName) return null;\n                    await loadCtripConfigList({\n                        cacheMs: MANUAL_CONFIG_LIST_TAB_CACHE_TTL_MS,\n                        applySelectedConfig: false,", 'Ctrip overview external entry reuses the short config-list cache after switching tabs');
requireText('public/index.html', 'onlineDataTab.value = tabName;\n                deferUiTask(async () => {', 'Ctrip overview external entry defers config loading after switching tabs');
requireText('public/index.html', "scheduleDataHealthPanelRefresh('light', { force: true });", 'Ctrip overview fetch completion schedules data-health refresh without waiting on it');
requireNoText('public/index.html', "await loadDataHealthPanel('light', { force: true });\n                } finally {\n                    ctripOverviewCoreFetchRunning.value = false;", 'Ctrip overview one-click core fetch must not wait on data-health refresh before releasing loading state');
requireText('public/index.html', 'const openCtripCookieCreateFromHealth = () => {', 'Ctrip health Cookie create action opens the config form without waiting for config-list loading');
requireNoText('public/index.html', 'const openCtripCookieCreateFromHealth = async () => {', 'Ctrip health Cookie create action must not be an async blocking tab switch');
requireText('public/index.html', "const listConfig = ctripConfigList.value.find(item => String(item.id || '') === configId);\n                    const config = listConfig\n                        ? await ensureCtripConfigSecret(listConfig)\n                        : await loadCtripConfigDetail(configId);", 'Ctrip health Cookie editor reads the exact config detail when the list cache is not already available');
requireNoText('public/index.html', "if (!ctripConfigList.value.length) {\n                        await loadCtripConfigList();\n                    }\n                    const listConfig = ctripConfigList.value.find(item => String(item.id || '') === configId);", 'Ctrip health Cookie editor must not wait for the full config list before reading an exact config detail');
requireText('public/index.html', "loadCtripConfigList();\n                        scheduleDataHealthPanelRefresh('light', { force: true });", 'Ctrip health Cookie save refreshes config list and schedules data-health status without waiting on it');
requireText('public/index.html', "await deleteCtripConfig(configId);\n                scheduleDataHealthPanelRefresh('light', { force: true });", 'Ctrip health Cookie delete schedules data-health status after config delete without waiting on it');
requireNoText('public/index.html', "await loadCtripConfigList();\n                        await loadDataHealthPanel('light', { force: true });", 'Ctrip health Cookie save must not keep the modal saving state waiting on data-health refresh');
requireNoText('public/index.html', "await deleteCtripConfig(configId);\n                await loadDataHealthPanel('light', { force: true });", 'Ctrip health Cookie delete must not wait on data-health refresh');
requireText('public/index.html', 'const results = await Promise.all(ids.map(async (id) => {', 'Ctrip batch config delete runs delete requests in parallel');
requireText('public/index.html', 'deferUiTask(() => loadCtripConfigList(), 80);', 'Ctrip batch config delete refreshes the config list after feedback is released');
requireNoText('public/index.html', "if (deletedCount > 0) {\n                    await loadCtripConfigList();\n                }", 'Ctrip batch config delete must not wait on full config-list refresh before showing feedback');
requireText('public/index.html', 'const scheduleMeituanEbookingDeferredStartupRefresh = () => {', 'Meituan manual page defers config matching and secondary startup refreshes');
requireText('public/index.html', "if (currentPage.value !== 'meituan-ebooking') return null;", 'deferred Meituan manual startup refresh is scoped to the active page');
requireText('public/index.html', 'scheduleMeituanEbookingDeferredStartupRefresh();', 'Meituan manual page schedules deferred startup refresh after route entry');
requireText('public/index.html', 'const ensureMeituanConfigSecret = async (config, options = {}) => {', 'Meituan full config detail loader supports silent background prewarm');
requireText('public/index.html', "console.error('[Meituan] 预热完整配置失败:', e);", 'Meituan config-detail prewarm failure stays silent to the user');
requireText('public/index.html', 'const prewarmSelectedMeituanConfigSecret = (config = selectedMeituanHotelConfig.value) => {', 'entry can prewarm selected Meituan config detail without blocking manual fetch UI');
requireText('public/index.html', 'deferUiTask(() => ensureMeituanConfigSecret(config, { silent: true }), 80);', 'Meituan selected config detail prewarm is scheduled outside the current interaction');
requireText('public/index.html', 'let configSource = options.resolvedConfig || selectedMeituanHotelConfig.value;', 'Meituan config apply can reuse a resolved full config from the fetch flow');
requireText('public/index.html', 'const config = options.resolvedConfig || await ensureMeituanConfigSecret(configSource);', 'Meituan config apply avoids duplicate full config detail requests when resolved config is already available');
requireText('public/meituan-static.js', 'if (!isMeituanRankingFormAlignedWithConfig(form, selectedMeituanConfig)) {', 'Meituan batch fetch flow skips repeat config application when the form already matches the resolved config');
requireText('public/meituan-static.js', 'skipIfAligned: true,', 'Meituan batch fetch flow passes the aligned-form guard into config apply');
requireNoText('public/meituan-static.js', 'await applyMeituanHotelConfig(false);', 'Meituan batch fetch flow must not trigger a second full config apply after resolving config');
requireText('public/index.html', "await loadMeituanConfigList({\n                        cacheMs: MANUAL_CONFIG_LIST_TAB_CACHE_TTL_MS,\n                        applySelectedConfig: false,\n                    });\n                    if (currentPage.value !== 'meituan-ebooking') return null;\n                    prewarmSelectedMeituanConfigSecret();", 'Meituan manual page prewarms selected config detail during delayed deferred startup refresh through the short config-list cache');
requireText('public/index.html', 'const shouldApplySelectedConfig = options.applySelectedConfig === true;', 'Meituan config-list loader only applies selected config when explicitly requested');
requireText('public/index.html', 'if (meituanForm.value.hotelId && shouldApplySelectedConfig) {', 'Meituan config-list loader does not implicitly apply selected config on ordinary tab switches');
requireNoText('public/index.html', "if (meituanForm.value.hotelId) {\n                                await applyMeituanHotelConfig(false, { refreshList: false });\n                            }\n                            return meituanConfigList.value;", 'Meituan config-list loader must not wait for full config detail application');
requireNoText('public/index.html', "runPageLoadOnce(newPage, 'main', () => loadMeituanConfigList());", 'Meituan manual page must not synchronously request saved configs from the first-paint loader');
requireText('public/index.html', 'const openMeituanManualTab = (tab) => {', 'Meituan manual tabs use a non-blocking tab switch helper');
requireText('public/index.html', 'deferUiTask(() => runMeituanManualTabSwitch({', 'Meituan manual tab switch delegates async branching to static helper');
requireText('public/index.html', 'getCurrentPage: () => currentPage.value', 'Meituan manual tab static helper receives active page reader');
requireText('public/index.html', 'getCurrentTab: () => onlineDataTab.value', 'Meituan manual tab static helper receives active tab reader');
requireText('public/index.html', '@click="openMeituanManualTab(\'meituan-ranking\')"', 'Meituan ranking tab does not inline config-list loading');
requireText('public/index.html', '@click="openMeituanManualTab(\'meituan-traffic\')"', 'Meituan traffic tab does not inline config-list loading');
requireText('public/index.html', '@click="openMeituanManualTab(\'meituan-orders\')"', 'Meituan orders tab does not inline config-list loading');
requireText('public/index.html', '@click="openMeituanManualTab(\'meituan-ads\')"', 'Meituan ads tab does not inline config-list loading');
requireNoText('public/index.html', "onlineDataTab = 'meituan-ranking'; loadMeituanConfigList()", 'Meituan manual tabs must not synchronously request saved configs from inline click handlers');
requireNoText('public/index.html', "onlineDataTab = 'meituan-traffic'; loadMeituanConfigList(); syncMeituanTrafficConfigFromSelectedConfig()", 'Meituan traffic tab must not sync before config-list loading settles');
requireNoText('public/index.html', "await loadMeituanConfigList();\n                    if (currentPage.value !== 'meituan-ebooking' || onlineDataTab.value !== tab) return null;", 'Meituan manual tab switch must not re-inline config loading and stale-tab checks');
requireText('public/index.html', "scheduleMeituanEbookingDeferredStartupRefresh();\n                    return;\n                }\n\n                if (target === 'analysis')", 'platform profile Meituan ranking action does not await config-list loading before navigation returns');
requireText('public/index.html', '配置读取失败，请刷新后重试；未读取成功前不会判断为未配置。', 'Meituan manual page exposes config-list load failures explicitly');
requireText('public/index.html', 'meituanConfigListLoaded && !selectedMeituanHotelConfig', 'Meituan manual page only shows unconfigured after the config list has loaded');
requireNoText('public/index.html', '配置待读取，正在准备美团数据源匹配...', 'Meituan manual page must not show a slow pending data-source match state');
requireText('public/index.html', ':disabled="fetchingData || !canFetchMeituanRankingData()"', 'Meituan ranking manual fetch button stays disabled until a config is already matched');
requireText('public/index.html', 'const meituanManualFetchConfigProofPending = () => {', 'Meituan ranking manual fetch keeps the old helper as a non-waiting compatibility boundary');
requireText('public/index.html', 'return !!meituanForm.value.hotelId && !!selectedMeituanHotelConfig.value;', 'Meituan ranking manual fetch requires an already matched config before submission');
requireText('public/index.html', 'const resolveMeituanManualFetchConfig = async (config) => {', 'Meituan ranking manual fetch resolves config before backend submission');
requireNoText('public/index.html', 'return !!meituanConfigListLoadingPromise', 'Meituan ranking manual fetch must not reuse an in-flight config-list request as a click-time wait');
requireNoText('public/index.html', "await loadMeituanConfigList({\n                    cacheMs: MANUAL_CONFIG_LIST_TAB_CACHE_TTL_MS,\n                    applySelectedConfig: false,\n                });", 'Meituan ranking manual fetch must not wait for config-list loading before backend submission');
requireText('public/index.html', 'ensureMeituanConfigSecret: async config => ensureMeituanConfigSecret(await resolveMeituanManualFetchConfig(config))', 'Meituan ranking manual fetch uses only the currently matched config before backend submission');
requireText('public/index.html', 'meituanConfigListLoaded, meituanConfigListLoadFailed', 'Meituan config-list loaded and failed states are exposed to the template');
requireNoText('public/index.html', "await loadMeituanConfigList({\n                            cacheMs: MANUAL_CONFIG_LIST_TAB_CACHE_TTL_MS,\n                            applySelectedConfig: false,\n                        });\n                        if (requestedHotelId !== String(meituanForm.value.hotelId || '')) return;", 'Meituan hotel selection must not wait on the config-list loader during fast matching');
requireText('public/index.html', 'let meituanHotelConfigApplyVersion = 0;', 'Meituan hotel selection tracks stale deferred config applications');
requireText('public/index.html', 'const scheduleMeituanHotelConfigApply = (options = {}) => {', 'Meituan hotel selection uses a deferred config apply scheduler');
requireText('public/index.html', 'scheduleMeituanHotelConfigApply({ delayMs: 0 });', 'Meituan hotel selection applies already matched config immediately after selection');
requireNoText('public/index.html', "if (onlineDataTab.value === 'meituan-ranking') {\n                    applyMeituanHotelConfig(false);\n                }", 'Meituan hotel watcher must not directly start config matching on selection change');
requireText('public/index.html', "const switchMeituanCaptureTab = async (tab, sections = []) => {\n                onlineDataTab.value = tab;\n                meituanBrowserCaptureForm.value.captureSections = normalizeMeituanCaptureSections(sections);\n                meituanBrowserCaptureResult.value = null;\n                await loadMeituanConfigList({ cacheMs: MANUAL_CONFIG_LIST_TAB_CACHE_TTL_MS });", 'Meituan browser capture tab switches reuse the short config-list cache');
requireNoText('public/index.html', "const res = await request('/online-data/get-meituan-config-list');\n                            if (res.code === 200) {\n                                meituanConfigList.value = Array.isArray(res.data) ? res.data : [];\n                            }", 'Meituan hotel selection does not bypass config-list loading flags and request dedupe');
requireText('public/index.html', 'if (!ctripConfigListLoaded.value && !ctripConfigList.value.length)', 'manual online-data config prewarm does not refetch a known-empty Ctrip config list');
requireText('public/index.html', 'if (!meituanConfigListLoaded.value && !meituanConfigList.value.length)', 'manual online-data config prewarm does not refetch a known-empty Meituan config list');
requireText('public/index.html', "if (!ctripConfigListLoaded.value && ctripConfigList.value.length === 0) tasks.push(loadCtripConfigList({\n                    cacheMs: MANUAL_CONFIG_LIST_TAB_CACHE_TTL_MS,\n                    applySelectedConfig: false,", 'hotel OTA config prewarm does not refetch a known-empty Ctrip config list and reuses the short config-list cache');
requireText('public/index.html', "if (!meituanConfigListLoaded.value && meituanConfigList.value.length === 0) tasks.push(loadMeituanConfigList({\n                    cacheMs: MANUAL_CONFIG_LIST_TAB_CACHE_TTL_MS,\n                    applySelectedConfig: false,", 'hotel OTA config prewarm does not refetch a known-empty Meituan config list and reuses the short config-list cache');
requireText('public/index.html', "if (newTab === 'data')", 'manual online-data tab routes through the shared tab-load scheduler');
requireText('public/index.html', 'const MANUAL_ONLINE_DATA_CONFIG_PREWARM_DELAY_MS = 60;', 'manual online-data config prewarm is delayed and excluded from the data-record first paint');
requireText('public/index.html', "const MANUAL_ONLINE_FETCH_CONFIG_TABS = new Set(['ctrip', 'meituan', 'custom']);", 'manual online-data config prewarm is limited to legacy manual fetch tabs');
requireText('public/index.html', "const shouldPrewarmManualOnlineFetchConfig = (newTab) => MANUAL_ONLINE_FETCH_CONFIG_TABS.has(String(newTab || ''));", 'manual online-data config prewarm uses an explicit tab allow-list');
requireText('public/index.html', 'const clearManualOnlineFetchConfigPrewarmTimer = () => {', 'manual online-data config prewarm can be cancelled when leaving manual fetch tabs');
requireText('public/index.html', 'const scheduleManualOnlineFetchConfigPrewarm = (newTab, delayMs = MANUAL_ONLINE_DATA_CONFIG_PREWARM_DELAY_MS) => {', 'manual online-data config prewarm is scheduled through a cancellable helper');
requireText('public/index.html', 'if (!isVisibleOnlineDataTab(newTab)) return;\n                    ensureManualOnlineFetchConfigReady();', 'manual online-data config prewarm rechecks tab visibility before loading saved configs');
requireText('public/index.html', "if (!shouldPrewarmManualConfig) {\n                    clearManualOnlineFetchConfigPrewarmTimer();\n                }", 'leaving manual fetch tabs cancels delayed saved-config prewarm');
requireText('public/index.html', 'refreshOnlineData({ cacheMs: ONLINE_DATA_PANEL_CACHE_TTL_MS });', 'data-records tab loads records through the shared scheduler');
requireNoText('public/index.html', 'refreshOnlineData({ cacheMs: ONLINE_DATA_PANEL_CACHE_TTL_MS });\n                        scheduleManualOnlineFetchConfigPrewarm(newTab, options.configPrewarmDelayMs);', 'data-records tab must not prewarm saved configs in the first-paint path');
requireText('public/index.html', "if (shouldPrewarmManualConfig) {\n                    scheduleManualOnlineFetchConfigPrewarm(newTab, options.configPrewarmDelayMs);\n                    return undefined;\n                }", 'legacy manual fetch tabs keep delayed saved-config prewarm');
requireNoText('public/index.html', 'ensureManualOnlineFetchConfigReady();\n                        refreshOnlineData({ cacheMs: ONLINE_DATA_PANEL_CACHE_TTL_MS });', 'manual online-data tab must not start saved-config prewarm in the same first-paint batch as data records');
requireText('public/index.html', "const scheduleOnlineDataTabLoad = (newTab, options = {}) => {", 'online-data tabs share one deferred tab-load scheduler');
requireText('public/index.html', 'if (!isVisibleOnlineDataTab(newTab)) return null;', 'online-data deferred tab loaders recheck the visible page and tab before running');
requireText('public/index.html', "scheduleDataHealthPanelRefresh('light', options.force ? { force: true } : {})", 'data-health tab switches schedule the light refresh instead of starting it inside the click path');
requireNoText('public/index.html', "return runIfCurrent(() => loadDataHealthPanel('light'));", 'data-health tab switch must not call loadDataHealthPanel directly from the tab scheduler');
requireText('public/index.html', "if (currentPage.value !== 'online-data') {\n                    if (newTab === 'data-health') {\n                        suppressNextDataHealthTabLoad = false;\n                    }\n                    return;\n                }", 'online-data tab watcher does not run generic loaders while Ctrip or Meituan manual pages own the tab state');
requireText('public/index.html', "const openOnlineDataTab = (tab, options = {}) => {", 'online-data tab buttons use the shared non-blocking entrypoint');
requireText('public/index.html', '@click="openOnlineDataTab(\'data-health\')"', 'data-health tab button switches immediately through the shared entrypoint');
requireText('public/index.html', '@click="openOnlineDataTab(\'data\')"', 'manual data tab button switches immediately through the shared entrypoint');
requireNoText('public/index.html', '@click="onlineDataTab = \'data-health\'; loadDataHealthPanel(\'light\')"', 'data-health tab button must not synchronously load the panel during click');
requireNoText('public/index.html', '@click="onlineDataTab = \'data\'; refreshOnlineData()"', 'manual data tab button must not synchronously refresh during click');
requireText('public/index.html', 'const openDataHealthDrilldown = (row) => {\n                if (!row?.tab) return;\n                openOnlineDataTab(row.tab);\n            };', 'data-health drilldowns use the shared non-blocking tab entrypoint');
requireNoText('public/index.html', 'onlineDataTab.value = row.tab;', 'data-health drilldown must not directly change onlineDataTab and trigger watcher loads');
requireNoText('public/index.html', "if (row.tab === 'platform-auto') schedulePlatformAutoFetchPanelLoad();", 'data-health drilldown must not explicitly double-load platform-auto');
requireNoText('public/index.html', "if (row.tab === 'profile-fields') loadCtripProfileFields();", 'data-health drilldown must not explicitly double-load profile fields');
requireNoText('public/index.html', "if (row.tab === 'data') refreshOnlineData();", 'data-health drilldown must not explicitly double-load manual data');
requireText('public/index.html', 'openOnlineDataTab(targetTab);', 'menu online-data tab navigation uses the shared tab-load scheduler');
requireText('public/index.html', "let pendingOnlineDataEntryTab = '';", 'online-data menu navigation tracks direct target tabs');
requireText('public/index.html', "pendingOnlineDataEntryTab = String(item.tab || '');", 'online-data menu target tab is recorded before currentPage watcher runs');
requireText('public/index.html', "if (requestedOnlineDataTab && requestedOnlineDataTab !== 'data-health') {\n                        return;\n                    }", 'online-data direct tab navigation skips default data-health first-paint loading');
requireText('public/index.html', "runPageLoadOnce(newPage, 'main', () => {\n                            scheduleDataHealthPanelRefresh('light');\n                            return null;\n                        });", 'online-data default first paint schedules only light data-health status');
requireNoText('public/index.html', "runPageLoadOnce(newPage, 'main', () => loadDataHealthPanel('light'));", 'online-data default first paint must not directly start light data-health loading from the page switch');
requireNoText('public/index.html', "runPageLoadOnce(newPage, 'main', () => Promise.allSettled([\n                            loadOnlineDataHotelList(),\n                            loadDataHealthPanel('light'),\n                        ]));", 'online-data default first paint must not block on hotel-list loading');
requireText('public/index.html', "const openOnlineDataEntryTab = (tab = 'data-health', options = {}) => {\n                const targetTab = String(tab || 'data-health');", 'online-data external entries share one tab navigation helper');
requireText('public/index.html', "clearDataHealthSecondaryPanelsReadyTimer();\n                dataHealthSecondaryPanelsReady.value = false;\n                clearDataHealthDetailPanelsReadyTimer();\n                dataHealthDetailPanelsReady.value = false;\n                clearDataHealthEmployeePanelsReadyTimer();\n                dataHealthEmployeePanelsReady.value = false;\n                clearPlatformAutoSettingsPanelsReadyTimer();\n                platformAutoSettingsPanelsReady.value = false;\n                clearPlatformAutoSecondaryPanelsReadyTimer();\n                platformAutoSecondaryPanelsReady.value = false;", 'online-data external entries clear secondary, detail, and employee panel readiness before the page switch');
requireText('public/index.html', "if (targetTab !== 'data-health') {\n                    pendingOnlineDataEntryTab = targetTab;\n                }", 'online-data external entries skip default first-paint loading for non-default target tabs');
requireText('public/index.html', "onlineDataTab.value = targetTab;\n                currentPage.value = 'online-data';", 'online-data external entries set the target tab before making the page visible');
requireText('public/index.html', "const openOnlinePlatformAutoTab = (options = {}) => {\n                return openOnlineDataEntryTab('platform-auto', options);\n            };", 'platform-auto quick entry skips the default data-health first-paint load');
requireText('public/index.html', "const openOnlineDataManualEntry = () => {\n                return openOnlineDataEntryTab('data-health');\n            };", 'manual online-data parent entry switches through the shared helper');
requireText('public/index.html', "if (item.path === 'online-data' && !item.tab) {\n                    openOnlineDataManualEntry();\n                    return;\n                }", 'online-data menu click without an explicit tab returns to the default data-health tab');
requireText('public/index.html', '@click="handleParentMenuClick(item)"', 'parent menu clicks use the shared parent menu handler');
requireText('public/index.html', "const handleParentMenuClick = (item) => {\n                const menuName = item?.name || getMenuItemName(item);\n                toggleSubmenu(menuName);\n            };", 'parent menu handler only toggles the submenu so manual-fetch entry clicks stay responsive');
requireNoText('public/index.html', "if (menuName === '线上数据手动获取') {\n                    openOnlineDataManualEntry();\n                }", 'online-data parent menu click must not load the data-health panel before the user chooses a manual platform');
requireText('public/index.html', "if (targetPage === 'online-data') {\n                    openOnlineDataEntryTab(targetTab || 'data-health');", 'global notifications targeting online-data use the shared tab entry helper');
requireNoText('public/index.html', "item.target_page === 'online-data' || item.target_page === 'ctrip-ebooking'", 'global notifications must not load data-health for every online-data target tab');
requireText('public/index.html', "if (entry.page === 'online-data') {\n                    openOnlineDataEntryTab(entry.tab || 'data-health');\n                    return;\n                }", 'home quick entries targeting online-data use the shared tab entry helper');
requireText('public/index.html', 'data-testid="data-health-loading-banner"', 'data-health light refresh uses a non-blocking loading banner');
requireNoText('public/index.html', '<template v-else>\n                                        <div data-testid="data-health-command-center"', 'data-health command center must remain visible while light diagnostics refresh');
requireNoText('public/index.html', '<div v-if="hotelDashboardLoading || collectionReliabilityLoading" class="rounded-xl border border-gray-200 bg-white p-5">', 'data-health loading must not block the whole cockpit body');
requireText('public/index.html', 'const DATA_HEALTH_SECONDARY_PANEL_DELAY_MS = 900;', 'data-health secondary diagnostics are delayed behind the first switch frame');
requireText('public/index.html', 'const DATA_HEALTH_DETAIL_PANEL_DELAY_MS = 2600;', 'data-health detail diagnostics are delayed behind the secondary command center');
requireText('public/index.html', 'const DATA_HEALTH_EMPLOYEE_PANEL_DELAY_MS = 4200;', 'data-health employee six-question panel is delayed behind detail diagnostics');
requireText('public/index.html', 'const dataHealthSecondaryPanelsReady = ref(false);', 'data-health secondary diagnostics use an explicit readiness flag');
requireText('public/index.html', 'const dataHealthDetailPanelsReady = ref(false);', 'data-health detail diagnostics use an explicit readiness flag');
requireText('public/index.html', 'const dataHealthEmployeePanelsReady = ref(false);', 'data-health employee diagnostics use an explicit readiness flag');
requireText('public/index.html', 'const scheduleDataHealthSecondaryPanelsReady = (delayMs = DATA_HEALTH_SECONDARY_PANEL_DELAY_MS) => {', 'data-health secondary diagnostics readiness is scheduled and cancellable');
requireText('public/index.html', 'const scheduleDataHealthDetailPanelsReady = (delayMs = DATA_HEALTH_DETAIL_PANEL_DELAY_MS) => {', 'data-health detail diagnostics readiness is scheduled and cancellable');
requireText('public/index.html', 'const scheduleDataHealthEmployeePanelsReady = (delayMs = DATA_HEALTH_EMPLOYEE_PANEL_DELAY_MS) => {', 'data-health employee diagnostics readiness is scheduled and cancellable');
requireText('public/index.html', "if (currentPage.value !== 'online-data' || onlineDataTab.value !== 'data-health') {\n                    dataHealthSecondaryPanelsReady.value = false;\n                    return;\n                }", 'data-health secondary diagnostics are scoped to the visible online-data health tab');
requireText('public/index.html', "if (currentPage.value !== 'online-data' || onlineDataTab.value !== 'data-health') {\n                    dataHealthDetailPanelsReady.value = false;\n                    return;\n                }", 'data-health detail diagnostics are scoped to the visible online-data health tab');
requireText('public/index.html', "if (currentPage.value !== 'online-data' || onlineDataTab.value !== 'data-health') {\n                    dataHealthEmployeePanelsReady.value = false;\n                    return;\n                }", 'data-health employee diagnostics are scoped to the visible online-data health tab');
requireText('public/index.html', '<div v-if="dataHealthEmployeePanelsReady" data-testid="phase1-employee-six-question-summary"', 'data-health employee six-question panel waits for the employee readiness window');
requireText('public/index.html', '<div v-if="dataHealthSecondaryPanelsReady" data-testid="data-health-command-center"', 'data-health command center is not mounted during the immediate manual-entry switch window');
requireNoText('public/index.html', 'data-testid="hotel-data-cockpit-pending"', 'data-health light workbench must not show a redundant full-diagnostic pending panel');
requireText('public/index.html', '<div v-if="dataHealthDetailPanelsReady && dataHealthFullDiagnosticsLoaded" data-testid="hotel-data-cockpit"', 'data-health full cockpit waits for explicit full diagnostics');
requireText('public/index.html', 'data-testid="data-health-full-diagnostics-detail"', 'data-health low-level collection evidence is grouped behind full diagnostics');
requireText('public/index.html', '<div v-if="dataHealthDetailPanelsReady && dataHealthFullDiagnosticsLoaded" data-testid="data-health-drilldown"', 'data-health drilldown waits for explicit full diagnostics');
requireText('public/index.html', '<div v-if="dataHealthDetailPanelsReady && dataHealthFullDiagnosticsLoaded" data-testid="mixed-collection-lifecycle-panel"', 'data-health lifecycle diagnostics wait for explicit full diagnostics');
requireText('public/index.html', 'dataHealthSecondaryPanelsReady, dataHealthDetailPanelsReady, dataHealthEmployeePanelsReady, ctripEbookingModuleCardsReady, ctripEbookingSecondaryPanelsReady, ctripEbookingDeepPanelsReady, ctripEbookingBusinessDetailsReady, ctripEbookingDiagnosticsPanelsReady, handleCtripEbookingDiagnosticsToggle, dashboardHotelId', 'data-health readiness flags are returned for template gating');
requireText('public/index.html', "currentPage.value = 'online-data';\n                onlineDataTab.value = 'data-health';\n                dataHealthSecondaryPanelsReady.value = false;\n                scheduleDataHealthSecondaryPanelsReady();\n                dataHealthDetailPanelsReady.value = false;\n                scheduleDataHealthDetailPanelsReady();\n                dataHealthEmployeePanelsReady.value = false;\n                scheduleDataHealthEmployeePanelsReady();\n                scheduleDataHealthPanelRefresh('light');", 'AI daily report data-gap navigation schedules data-health refresh and readiness after the route switch');
requireNoText('public/index.html', "currentPage.value = 'online-data';\n                onlineDataTab.value = 'data-health';\n                await loadDataHealthPanel('light');", 'AI daily report data-gap navigation must not wait on light data-health refresh');
requireText('public/index.html', 'const scheduleLatestCtripRefresh', 'entry defers latest Ctrip snapshot refresh after manual collection');
requireText('public/index.html', 'const scheduleDataHealthPanelRefresh', 'entry defers data-health refresh after manual collection');
requireText('public/index.html', "const isDataHealthPanelVisible = () => ['online-data', 'ctrip-ebooking'].includes(currentPage.value) && onlineDataTab.value === 'data-health';", 'entry defines the visible data-health scope');
requireText('public/index.html', 'if (!isDataHealthPanelVisible()) return null;', 'post-fetch data-health refresh does not run after the visible data-health tab is left');
requireNoText('public/index.html', "const scheduleDataHealthPanelRefresh = (mode = 'light', params = {}) => schedulePostFetchRefresh('data-health-panel', () => loadDataHealthPanel(mode, params), 560);", 'post-fetch data-health refresh must include a page and tab guard');
requireText('public/index.html', 'const schedulePlatformProfileStatusRefresh', 'entry defers platform profile refresh after manual collection');
requireText('public/index.html', 'const schedulePlatformDataSourcesRefresh', 'entry defers platform data-source refresh after manual collection');
requireText('app/service/ManualOnlineFetchTaskService.php', 'final class ManualOnlineFetchTaskService', 'manual OTA background task creation and launch lives in a focused service');
requireText('app/service/ManualOnlineFetchTaskService.php', 'online-data:manual-fetch-once', 'manual OTA background task service launches the shared one-shot worker');
requireText('app/service/ManualOnlineFetchTaskService.php', 'launchWindowsBatchFile($batPath)', 'manual OTA background task launch uses the Windows fast launcher wrapper');
requireText('app/service/ManualOnlineFetchTaskService.php', 'launchWindowsScriptHost($launcherPath)', 'manual OTA background task launch confirms the Windows script host path ran');
requireText('app/service/ManualOnlineFetchTaskService.php', 'launchWindowsBatchFileWithStart($batPath)', 'manual OTA background task launch falls back to cmd start when wscript does not execute');
requireText('app/service/ManualOnlineFetchTaskService.php', 'appendWindowsLauncherDiagnostic($batPath', 'manual OTA background task launch records launcher fallback diagnostics');
requireNoText('app/service/ManualOnlineFetchTaskService.php', 'powershell.exe -NoProfile -ExecutionPolicy Bypass -EncodedCommand', 'manual OTA background task service must not launch via powershell on the API request path');
requireOnlineDataControllerText("createTask('ctrip'", 'backend can run Ctrip manual fetch as a background task through the manual OTA task service');
requireOnlineDataControllerText("createTask(strtolower($platform) . '_traffic'", 'backend can run Ctrip traffic manual fetch as a background task through the manual OTA task service');
requireOnlineDataControllerText("createTask('ctrip_ads'", 'backend can run Ctrip ads manual fetch as a background task through the manual OTA task service');
requireOnlineDataControllerText('launchTask($task)', 'backend launches manual OTA fetch tasks through the manual OTA task service');
requireOnlineDataControllerText('launchWindowsBatchFile($batPath)', 'platform auto-fetch background launch uses the Windows fast launcher wrapper');
requireOnlineDataControllerText('launchWindowsScriptHost($launcherPath)', 'platform auto-fetch background launch confirms the Windows script host path ran');
requireOnlineDataControllerText('launchWindowsBatchFileWithStart($batPath)', 'platform auto-fetch background launch falls back to cmd start when wscript does not execute');
requireOnlineDataControllerText('appendWindowsLauncherDiagnostic($batPath', 'platform auto-fetch background launch records launcher fallback diagnostics');
requireNoText('app/controller/OnlineData.php', 'private function createManualCtripFetchBackgroundTask', 'OnlineData does not re-inline Ctrip manual background task creation');
requireNoText('app/controller/OnlineData.php', 'private function launchManualCtripFetchBackgroundTask', 'OnlineData does not re-inline manual background task launching');
requireText('app/command/ManualFetchOnlineDataOnce.php', 'online-data:manual-fetch-once', 'manual Ctrip fetch has a one-shot background worker command');
requireText('config/console.php', "'online-data:manual-fetch-once'", 'console exposes one-shot manual Ctrip fetch worker command');
requireText('app/service/OnlineTrafficDataExtractionService.php', 'extractCtripTrafficRows', 'traffic response extraction lives in a focused service');
requireOnlineDataControllerText('OnlineTrafficDataExtractionService::extractCtripTrafficRows', 'OnlineData keeps a thin Ctrip traffic extraction wrapper');
requireNoText('app/controller/OnlineData.php', 'private function extractCtripTrafficRowsRecursive', 'OnlineData does not re-inline recursive Ctrip traffic extraction');
for (const directRefreshBinding of [
  'refreshLatestCtripData: loadLatestCtripData',
  'refreshLatestCtripData: params => loadLatestCtripData(params)',
  'refreshDataHealthPanel: loadDataHealthPanel',
  'refreshDataHealthPanel: (mode, params) => loadDataHealthPanel(mode, params)',
  'refreshPlatformProfileStatus: loadPlatformProfileStatus',
  'refreshPlatformProfileStatus: params => loadPlatformProfileStatus(params)',
  'refreshPlatformDataSources: loadPlatformDataSources',
  'refreshPlatformDataSources: () => loadPlatformDataSources()',
]) {
  requireNoText('public/index.html', directRefreshBinding, `entry avoids direct post-fetch refresh binding: ${directRefreshBinding}`);
}
requireNoText('public/ctrip-static.js', 'await refreshOnlineHistory();\n                await refreshLatestCtripData({ silent: true });', 'Ctrip manual fetch success does not block on history/latest snapshot refresh');
for (const flowFile of ['public/auto-fetch-static.js', 'public/ctrip-static.js', 'public/meituan-static.js']) {
  requireText(flowFile, 'const runPostFetchRefresh', `${flowFile} uses non-blocking post-fetch refresh helper`);
  requireNoText(flowFile, 'await refreshOnlineHistory(', `${flowFile} does not block collection completion on history refresh`);
  requireNoText(flowFile, 'await refreshLatestCtripData(', `${flowFile} does not block collection completion on latest Ctrip refresh`);
  requireNoText(flowFile, 'await refreshOnlineData(', `${flowFile} does not block collection completion on online data refresh`);
  requireNoText(flowFile, 'await loadAutoFetchStatus(', `${flowFile} does not block auto-fetch completion on status refresh`);
}
requireText('public/auto-fetch-static.js', 'const buildAutoFetchTriggerRequestBody', 'auto-fetch static builds trigger request bodies');
requireText('public/auto-fetch-static.js', 'const buildAutoFetchRunStartState', 'auto-fetch static builds trigger run start state');
requireText('public/auto-fetch-static.js', 'const runAutoFetchTriggerFlow', 'auto-fetch static runs manual trigger flow');
requireText('public/auto-fetch-static.js', 'const resolveDataConfigTestEndpoint', 'auto-fetch static resolves data-source test endpoints');
requireText('public/auto-fetch-static.js', 'const runDataConfigTestFlow', 'auto-fetch static runs data-source config test flow');
requireText('public/index.html', "requireAutoFetchStatic('runDataConfigTestFlow')", 'entry uses extracted data-source config test flow runner');
requireText('public/index.html', 'let dataConfigModalLoadSeq = 0;', 'data-source config modal tracks stale background config loads');
requireText('public/index.html', 'const openDataConfigModal = (type) => {', 'data-source config modal opens without waiting for saved system-config data');
requireText('public/index.html', 'showDataConfigModal.value = true;\n                debugLog', 'data-source config modal is shown before deferred saved-config loading starts');
requireText('public/index.html', 'deferUiTask(async () => {\n                    const isCurrentConfigModal = () =>', 'data-source config modal loads saved config in deferred work with stale checks');
requireText('public/index.html', 'const loadDataConfig = async (type, options = {}) => {', 'data-source config loader supports stale-open guards');
requireText('public/index.html', "const shouldApply = typeof options.shouldApply === 'function' ? options.shouldApply : () => true;", 'data-source config loader can skip stale saved-config application');
requireNoText('public/index.html', 'const openDataConfigModal = async (type) => {', 'data-source config modal must not block opening on async saved-config loading');
requireNoText('public/index.html', 'await loadDataConfig(type);\n                } catch (e) {\n                    console.error', 'data-source config modal must not await saved system-config before opening');
requireText('public/auto-fetch-static.js', 'async: true', 'auto-fetch trigger submits quickly and lets backend continue collection');
requireText('public/auto-fetch-static.js', "return { status: 'accepted'", 'auto-fetch trigger keeps backend queued state non-blocking');
requireText('public/index.html', 'async: true, ...buildAutoFetchModePayload()', 'retry auto-fetch submits quickly and lets backend continue collection');
requireText('public/index.html', "['running', 'queued', 'accepted'].includes(retryStatus)", 'retry auto-fetch treats backend queued state as non-blocking');
requireText('public/ctrip-static.js', 'const isCtripBackgroundAcceptedResponse', 'Ctrip static shares accepted/running/queued background response detection');
requireText('public/ctrip-static.js', 'const requestBody = { ...requestContext.requestBody, async: false, background: false };', 'Ctrip ranking manual fetch requests direct results for immediate display');
requireNoText('public/ctrip-static.js', 'const requestBody = { ...requestContext.requestBody, async: true };', 'Ctrip ranking manual fetch must not enqueue background tasks by default');
requireText('public/ctrip-static.js', 'const directRequestBody = { ...requestBody, async: false, background: false };', 'Ctrip traffic and ads manual fetch flows request direct results');
requireNoText('public/ctrip-static.js', 'const queuedRequestBody = { ...requestBody, async: true };', 'Ctrip manual fetch flows must not enqueue background tasks by default');
requireText('public/ctrip-static.js', "return { status: 'accepted'", 'Ctrip manual fetch flows keep defensive backend queued-state handling');
requireText('public/meituan-static.js', 'const requestBody = { ...task.body, async: false, background: false }', 'Meituan manual ranking fetch requests direct results for immediate display');
requireText('public/meituan-static.js', 'await Promise.all(fetchTasks.map(async (task, index) => {', 'Meituan manual ranking fetch keeps independent direct requests concurrent');
requireNoText('public/meituan-static.js', 'const requestBody = { ...task.body, async: true, background: true }', 'Meituan manual ranking fetch must not enqueue background tasks by default');
requireText('public/meituan-static.js', 'const modelRes = await requestDisplayModel', 'Meituan manual ranking fetch still builds the display model when direct results are returned');
requireOnlineDataControllerText('markAutoFetchRunningStatus', 'backend records running auto-fetch task status');
requireOnlineDataControllerText('createAutoFetchBackgroundTask', 'backend creates one-shot auto-fetch background tasks');
requireOnlineDataControllerText("'/api/online-data/retry-auto-fetch'", 'backend retry auto-fetch posts the one-shot worker back to retry endpoint');
requireOnlineDataControllerText("'retry_auto_fetch_queued'", 'backend records queued retry auto-fetch tasks');
requireOnlineDataControllerText("createTask('meituan'", 'backend creates one-shot Meituan manual fetch background tasks through the manual OTA task service');
requireOnlineDataControllerText("createTask('meituan_traffic'", 'backend creates one-shot Meituan traffic manual fetch background tasks through the manual OTA task service');
requireOnlineDataControllerText("createTask('meituan_' . $section", 'backend creates one-shot Meituan order/ads manual fetch background tasks through the manual OTA task service');
requireNoText('app/controller/OnlineData.php', 'private function createManualMeituanFetchBackgroundTask', 'OnlineData does not re-inline Meituan manual background task creation');
requireText('app/command/AutoFetchOnlineDataOnce.php', 'online-data:auto-fetch-once', 'backend registers a one-shot auto-fetch worker command');
requireText('config/console.php', "'online-data:auto-fetch-once'", 'console exposes one-shot auto-fetch worker command');
requireText('public/index.html', "requireSystemStatic('getDefaultDataConfigForm')", 'entry uses extracted data config default form');
requireText('public/index.html', "requireSystemStatic('getDataConfigTypeDefaults')", 'entry uses extracted data config type defaults');
requireText('public/index.html', "requireSystemStatic('getSystemConfigDefaults')", 'entry uses extracted system config defaults');
requireText('public/index.html', "requireSystemStatic('createHotelForm')", 'entry uses extracted hotel form builder');
requireText('public/index.html', "requireSystemStatic('buildHotelSavePayload')", 'entry uses extracted hotel save payload builder');
requireText('public/index.html', "requireSystemStatic('buildHotelOtaCtripConfigSavePayload')", 'entry uses extracted hotel Ctrip OTA config save payload builder');
requireText('public/index.html', "requireSystemStatic('buildHotelOtaMeituanConfigSavePayload')", 'entry uses extracted hotel Meituan OTA config save payload builder');
requireText('public/system-static.js', 'const createLoginForm', 'system static builds login default forms');
requireText('public/system-static.js', 'const getRememberedLoginAccount', 'system static reads remembered login account and clears legacy password');
requireText('public/system-static.js', 'const buildLoginRequestPayload', 'system static builds login request payloads');
requireText('public/system-static.js', 'const validateLoginRequestPayload', 'system static validates login request payloads');
requireText('public/system-static.js', 'const applyRememberedLoginAccount', 'system static writes remembered login account without persisting passwords');
requireText('public/system-static.js', 'const createRegisterForm', 'system static builds register default forms');
requireText('public/system-static.js', 'const buildRegisterRequestPayload', 'system static builds register request payloads');
requireText('public/system-static.js', 'const validateRegisterRequestPayload', 'system static validates register request payloads');
requireText('public/system-static.js', 'const getDefaultDataConfigForm', 'system static builds data config default form');
requireText('public/system-static.js', 'const getDataConfigTypeDefaults', 'system static owns data config type defaults');
requireText('public/system-static.js', 'const getSystemConfigDefaults', 'system static owns system config defaults');
requireText('public/system-static.js', 'const createHotelForm', 'system static builds hotel admin forms');
requireText('public/system-static.js', 'const buildHotelSavePayload', 'system static builds hotel save payloads');
requireText('public/system-static.js', 'const buildHotelOtaCtripConfigSavePayload', 'system static builds hotel Ctrip OTA config save payloads');
requireText('public/system-static.js', 'const buildHotelOtaMeituanConfigSavePayload', 'system static builds hotel Meituan OTA config save payloads');
requireText('public/system-static.js', 'const getHotelCodeNumber', 'system static parses generated hotel code suffixes');
requireText('public/system-static.js', 'const formatHotelCode', 'system static formats generated hotel codes');
requireText('public/system-static.js', 'const normalizeOtaConfigHotelName', 'system static normalizes OTA config hotel names');
requireText('public/system-static.js', 'const formatHotelBindingDate', 'system static formats hotel platform binding dates');
requireText('public/index.html', "requireAppSystemStatic('getHotelCodeNumber')", 'entry uses extracted hotel-code suffix parser');
requireText('public/index.html', "requireAppSystemStatic('formatHotelCode')", 'entry uses extracted hotel-code formatter');
requireText('public/index.html', "requireAppSystemStatic('normalizeOtaConfigHotelName')", 'entry uses extracted OTA config hotel-name normalizer');
requireText('public/index.html', "requireAppSystemStatic('formatHotelBindingDate')", 'entry uses extracted hotel binding-date formatter');
requireText('public/index.html', 'system-static.js?v=20260630-data-center-label', 'entry bumps system static helper version after data center label rename');
requireText('public/index.html', "requireSystemStatic('buildKnowledgeImportRequestBody')", 'entry uses extracted knowledge import request body builder');
requireText('public/index.html', "requireSystemStatic('knowledgeImportSuccessMessage')", 'entry uses extracted knowledge import success message');
requireText('public/index.html', "requireSystemStatic('knowledgeImportErrorMessage')", 'entry uses extracted knowledge import error message');
requireText('public/system-static.js', 'const buildKnowledgeImportRequestBody', 'system static builds knowledge import request body');
requireText('public/system-static.js', 'const knowledgeImportSuccessMessage', 'system static formats knowledge import success message');
requireText('public/system-static.js', 'const knowledgeImportErrorMessage', 'system static formats knowledge import error message');
requireNoText('public/index.html', "hotelForm.value = { id: null, name: '', code: getNextHotelCode()", 'hotel create defaults are not re-inlined in the SPA entry');
requireNoText('public/index.html', 'name: hotelForm.value.name.trim(),\n                    code: normalizedCode,', 'hotel save payload is not re-inlined in the SPA entry');
requireNoText('public/index.html', 'ctrip_hotel_id: ctrip.ctrip_hotel_id || existing?.ctrip_hotel_id || existing?.ctripHotelId || existing?.ota_hotel_id || \'\',', 'hotel Ctrip OTA config save payload is not re-inlined in the SPA entry');
requireNoText('public/index.html', 'hotel_room_count: meituan.hotel_room_count || existing?.hotel_room_count || \'\',', 'hotel Meituan OTA config save payload is not re-inlined in the SPA entry');
requireNoText('public/index.html', 'const getHotelCodeNumber = (code) => {', 'hotel-code suffix parser is not re-inlined in the SPA entry');
requireNoText('public/index.html', 'const formatHotelCode = (num) => {', 'hotel-code formatter is not re-inlined in the SPA entry');
requireNoText('public/index.html', 'const normalizeOtaConfigHotelName = (value = \'\') => String(value || \'\')', 'OTA config hotel-name normalizer is not re-inlined in the SPA entry');
requireNoText('public/index.html', 'const formatHotelBindingDate = (value) => {', 'hotel binding-date formatter is not re-inlined in the SPA entry');
requireNoText('public/index.html', "successCount = Number(res.data?.success_count", 'knowledge import success message is not re-inlined in the SPA entry');
requireNoText('public/index.html', "error.name === 'AbortError'", 'knowledge import abort message is not re-inlined in the SPA entry');
{
  const context = { window: {} };
  vm.runInNewContext(read('public/system-static.js'), context, {
    filename: 'public/system-static.js',
  });
  const helpers = context.window.SUXI_SYSTEM_STATIC;
  const payload = helpers.buildKnowledgeImportRequestBody({
    form: { mode: 'document', source: '', hotel_id: '12', model_key: '' },
    tags: ['OTA', '复盘'],
    raw: '经营资料',
  });
  checks.push({
    file: 'public/system-static.js',
    label: 'knowledge import helper preserves request and message semantics',
    ok: payload.mode === 'document'
      && payload.source === 'document'
      && payload.hotel_id === 12
      && payload.model_key === 'deepseek_chat'
      && payload.raw === '经营资料'
      && payload.tags.length === 2
      && helpers.knowledgeImportSuccessMessage({ success_count: 3, error_count: 1 }).includes('失败 1 条')
      && helpers.knowledgeImportErrorMessage({ name: 'AbortError' }).includes('超过90秒'),
    detail: 'knowledge import helper must keep the original request defaults and explicit timeout message',
  });
}
{
  const context = { window: {} };
  vm.runInNewContext(read('public/system-static.js'), context, {
    filename: 'public/system-static.js',
  });
  const helpers = context.window.SUXI_SYSTEM_STATIC;
  checks.push({
    file: 'public/system-static.js',
    label: 'hotel config helpers preserve formatting semantics',
    ok: helpers.getHotelCodeNumber('HOTEL042') === 42
      && helpers.getHotelCodeNumber('manual') === 0
      && helpers.formatHotelCode(7) === 'HOTEL007'
      && helpers.normalizeOtaConfigHotelName(' 天成美团数据源 ') === '天成'
      && helpers.normalizeOtaConfigHotelName('天成携程数据源') === '天成'
      && helpers.formatHotelBindingDate('2026-06-16T09:30:45') === '2026-06-16 09:30'
      && helpers.formatHotelBindingDate('') === '-',
    detail: 'hotel config helpers must keep generated hotel codes, OTA data-source suffix cleanup, and compact date display stable',
  });
}
requireText('public/index.html', "requireDataHealthStatic('buildOnlineAnalysisChartConfig')", 'entry uses extracted online analysis chart config');
requireText('public/data-health-static.js', 'const buildOnlineAnalysisChartConfig', 'data-health static builds online analysis chart config');
requireText('public/index.html', 'new ChartLib(ctx, buildOnlineAnalysisChartConfig(analysisData.value.chart_data))', 'analysis chart rendering keeps only lifecycle wiring in the SPA entry');
requireText('public/index.html', "requireDataHealthStatic('buildOnlineHistoryQueryParams')", 'entry uses extracted online history query parameter builder');
requireText('public/index.html', "requireDataHealthStatic('formatOnlineHistoryHotelOption')", 'entry uses extracted online history hotel option formatter');
requireText('public/index.html', "requireDataHealthStatic('buildHotelDataDashboardRequests')", 'entry uses extracted hotel data dashboard request builder');
requireText('public/index.html', "requireDataHealthStatic('buildCollectionHealthCtripCatalogDetailRows')", 'entry uses extracted Ctrip catalog detail rows builder');
requireText('public/index.html', "requireDataHealthStatic('buildCollectionHealthCtripCatalogActionRows')", 'entry uses extracted Ctrip catalog action rows builder');
requireText('public/index.html', "requireDataHealthStatic('collectionHealthCtripCatalogStatus')", 'entry uses extracted Ctrip catalog status helper');
requireText('public/index.html', "requireDataHealthStatic('collectionHealthCtripCatalogMessage')", 'entry uses extracted Ctrip catalog message helper');
requireText('public/index.html', "requireDataHealthStatic('collectionHealthCtripCatalogGateText')", 'entry uses extracted Ctrip catalog gate text helper');
requireText('public/index.html', "requireDataHealthStatic('buildCollectionHealthCtripPersistedRows')", 'entry uses extracted Ctrip persisted rows builder');
requireText('public/index.html', "requireDataHealthStatic('collectionHealthCtripIdentityBlocked')", 'entry uses extracted Ctrip identity blocked helper');
requireText('public/index.html', "requireDataHealthStatic('collectionHealthCtripIdentityMessage')", 'entry uses extracted Ctrip identity message helper');
requireText('public/index.html', "requireDataHealthStatic('buildCollectionHealthCtripOverviewAuthState')", 'entry uses extracted Ctrip overview auth state builder');
requireText('public/index.html', "requireDataHealthStatic('collectionHealthCtripMetricValue')", 'entry uses extracted Ctrip metric value helper');
requireText('public/index.html', "requireDataHealthStatic('collectionHealthCtripMetricFromRows')", 'entry uses extracted Ctrip persisted metric helper');
requireText('public/index.html', "requireDataHealthStatic('collectionHealthCtripMissingDiagnosis')", 'entry uses extracted Ctrip missing diagnosis helper');
requireText('public/index.html', "const collectionHealthCtripRuntimeContext = (options = {}) => ({", 'entry keeps only Ctrip runtime context wiring');
requireText('public/index.html', "requireDataHealthStatic('buildCollectionHealthCtripCoreSnapshotGroups')", 'entry uses extracted Ctrip core snapshot group builder');
requireText('public/index.html', "requireDataHealthStatic('buildCollectionHealthCtripOverviewRevenueMetrics')", 'entry uses extracted Ctrip revenue metric list builder');
requireText('public/index.html', "requireDataHealthStatic('buildCollectionHealthCtripOverviewTrafficMetrics')", 'entry uses extracted Ctrip traffic metric list builder');
requireText('public/index.html', "requireDataHealthStatic('buildCollectionHealthCtripOverviewFunnelRows')", 'entry uses extracted Ctrip funnel rows builder');
requireText('public/index.html', "requireDataHealthStatic('buildCollectionHealthCtripOverviewPanels')", 'entry uses extracted Ctrip overview panels builder');
requireText('public/index.html', "requireDataHealthStatic('buildCollectionHealthCtripMissingActionRows')", 'entry uses extracted Ctrip missing action rows builder');
requireText('public/data-health-static.js', 'const buildCollectionHealthCtripCatalogDetailRows', 'data-health static builds Ctrip catalog detail rows');
requireText('public/data-health-static.js', 'const buildCollectionHealthCtripCatalogActionRows', 'data-health static builds Ctrip catalog action rows');
requireText('public/data-health-static.js', 'const collectionHealthCtripCatalogStatus', 'data-health static maps Ctrip catalog status');
requireText('public/data-health-static.js', 'const collectionHealthCtripCatalogMessage', 'data-health static maps Ctrip catalog message');
requireText('public/data-health-static.js', 'const collectionHealthCtripCatalogGateText', 'data-health static maps Ctrip catalog gate text');
requireText('public/data-health-static.js', 'const buildCollectionHealthCtripPersistedRows', 'data-health static builds Ctrip persisted rows');
requireText('public/data-health-static.js', 'const collectionHealthCtripIdentityBlocked', 'data-health static detects Ctrip identity blocking');
requireText('public/data-health-static.js', 'const collectionHealthCtripIdentityMessage', 'data-health static builds Ctrip identity messages');
requireText('public/data-health-static.js', 'const buildCollectionHealthCtripOverviewAuthState', 'data-health static builds Ctrip overview auth state');
requireText('public/data-health-static.js', 'const collectionHealthCtripMetricPreviewValue', 'data-health static reads Ctrip metric preview values');
requireText('public/data-health-static.js', 'const collectionHealthCtripCalculatedValue', 'data-health static calculates derived Ctrip metrics');
requireText('public/data-health-static.js', 'const collectionHealthCtripMetricKeyMatches', 'data-health static matches compound Ctrip metric keys');
requireText('public/data-health-static.js', 'const collectionHealthCtripMissingDiagnosis', 'data-health static diagnoses missing Ctrip metrics');
requireText('public/data-health-static.js', 'const collectionHealthCtripMetricFromRows', 'data-health static reads Ctrip persisted metrics');
requireText('public/data-health-static.js', 'const collectionHealthCtripMetricValue', 'data-health static resolves Ctrip overview metric values');
requireText('public/data-health-static.js', 'const buildCollectionHealthCtripCoreSnapshotGroups', 'data-health static builds Ctrip core snapshot groups');
requireText('public/data-health-static.js', 'const buildCollectionHealthCtripOverviewRevenueMetrics', 'data-health static builds Ctrip revenue metric lists');
requireText('public/data-health-static.js', 'const buildCollectionHealthCtripOverviewTrafficMetrics', 'data-health static builds Ctrip traffic metric lists');
requireText('public/data-health-static.js', 'const buildCollectionHealthCtripOverviewFunnelRows', 'data-health static builds Ctrip funnel rows');
requireText('public/data-health-static.js', 'const buildCollectionHealthCtripOverviewPanels', 'data-health static builds Ctrip overview panels');
requireText('public/data-health-static.js', 'const buildCollectionHealthCtripMissingActionRows', 'data-health static builds Ctrip missing action rows');
requireText('public/index.html', "requireDataHealthStatic('phase1EmployeeEvidenceStatusText')", 'entry uses extracted Phase1 employee evidence status text mapper');
requireText('public/index.html', "requireDataHealthStatic('phase1EmployeeGapCodeText')", 'entry uses extracted Phase1 employee gap code text mapper');
requireText('public/index.html', "requireDataHealthStatic('phase1EmployeeActionCodeText')", 'entry uses extracted Phase1 employee action code text mapper');
requireText('public/index.html', "requireDataHealthStatic('phase1MissingFieldLabel')", 'entry uses extracted Phase1 missing field label mapper');
requireText('public/index.html', "requireDataHealthStatic('phase1MissingFieldNextActionText')", 'entry uses extracted Phase1 missing field next-action mapper');
requireText('public/index.html', "requireDataHealthStatic('phase1MetricDomainProblemText')", 'entry uses extracted Phase1 metric-domain problem text mapper');
requireText('public/index.html', "requireDataHealthStatic('phase1MetricDomainNextActionText')", 'entry uses extracted Phase1 metric-domain next-action mapper');
requireText('public/index.html', "requireDataHealthStatic('phase1EmployeeActionEntryText')", 'entry uses extracted Phase1 action entry text mapper');
requireText('public/index.html', "requireDataHealthStatic('phase1EmployeeActionEntryOptionText')", 'entry uses extracted Phase1 action entry option text mapper');
requireText('public/index.html', "requireDataHealthStatic('phase1EmployeeActionEntryOptionGuidanceText')", 'entry uses extracted Phase1 action entry option guidance mapper');
requireText('public/index.html', "requireDataHealthStatic('phase1EmployeeActionEntryOptionReadinessText')", 'entry uses extracted Phase1 action entry option readiness mapper');
requireText('public/index.html', "requireDataHealthStatic('phase1EmployeeKnownQuestionText')", 'entry uses extracted Phase1 employee known question mapper');
requireText('public/index.html', "requireDataHealthStatic('phase1EmployeeKnownQuestionListText')", 'entry uses extracted Phase1 employee known question list mapper');
requireText('public/index.html', "requireDataHealthStatic('phase1EmployeeActionSuccessCriteriaText')", 'entry uses extracted Phase1 action success criteria mapper');
requireText('public/index.html', "requireDataHealthStatic('phase1EmployeeActionEvidenceNeededText')", 'entry uses extracted Phase1 action evidence-needed mapper');
requireText('public/index.html', "requireDataHealthStatic('phase1EmployeeActionVerificationStepsText')", 'entry uses extracted Phase1 action verification steps mapper');
requireText('public/index.html', "requireDataHealthStatic('phase1EmployeeActionBlockedActionText')", 'entry uses extracted Phase1 blocked-action mapper');
requireText('public/index.html', "requireDataHealthStatic('phase1EmployeeActionEmployeeExplanationText')", 'entry uses extracted Phase1 action employee explanation mapper');
requireText('public/index.html', "requireDataHealthStatic('phase1EmployeeActionLimitedConclusionsText')", 'entry uses extracted Phase1 limited conclusions mapper');
requireText('public/index.html', "requireDataHealthStatic('phase1EmployeeActionStillUsableMetricsText')", 'entry uses extracted Phase1 still-usable metrics mapper');
requireText('public/index.html', "requireDataHealthStatic('phase1EmployeeActionExplanationNextActionText')", 'entry uses extracted Phase1 explanation next-action mapper');
requireText('public/index.html', "requireDataHealthStatic('phase1EmployeeActionDisplayText')", 'entry uses extracted Phase1 action display mapper');
requireText('public/index.html', "requireDataHealthStatic('phase1EmployeeActionOwnerText')", 'entry uses extracted Phase1 action owner mapper');
requireText('public/index.html', "requireDataHealthStatic('phase1EmployeeActionMetaText')", 'entry uses extracted Phase1 action meta mapper');
requireText('public/index.html', "requireDataHealthStatic('phase1EmployeeActionProtectedBoundaryText')", 'entry uses extracted Phase1 protected boundary mapper');
requireText('public/index.html', "requireDataHealthStatic('buildPhase1EmployeeRequiredActions')", 'entry uses extracted Phase1 required actions builder');
requireText('public/index.html', "requireDataHealthStatic('buildPhase1AiDiagnosisEvidence')", 'entry uses extracted Phase1 AI diagnosis evidence builder');
requireText('public/index.html', "requireDataHealthStatic('phase1EmployeeActionRawCode')", 'entry uses extracted Phase1 action raw-code helper');
requireText('public/index.html', "requireDataHealthStatic('phase1EmployeeActionPlatformText')", 'entry uses extracted Phase1 action platform helper');
requireText('public/index.html', "requireDataHealthStatic('phase1EmployeeSourceSnapshotText')", 'entry uses extracted Phase1 source snapshot text mapper');
requireText('public/index.html', "requireDataHealthStatic('buildPhase1EmployeeQuestionRows')", 'entry uses extracted Phase1 employee question rows builder');
requireText('public/index.html', "requireDataHealthStatic('buildPhase1EmployeeCollectionSourceRows')", 'entry uses extracted Phase1 collection source rows builder');
requireText('public/index.html', "requireDataHealthStatic('buildPhase1EmployeeFieldTrustRows')", 'entry uses extracted Phase1 field trust rows builder');
requireText('public/index.html', "requireDataHealthStatic('buildPhase1EmployeeMissingFieldRows')", 'entry uses extracted Phase1 missing field rows builder');
requireText('public/index.html', "requireDataHealthStatic('buildPhase1EmployeeMetricDomainRows')", 'entry uses extracted Phase1 metric domain rows builder');
requireText('public/index.html', "requireDataHealthStatic('buildPhase1EmployeeAiEvidenceSummary')", 'entry uses extracted Phase1 AI evidence summary builder');
requireText('public/index.html', "requireDataHealthStatic('buildPhase1EmployeeOperationSummary')", 'entry uses extracted Phase1 operation summary builder');
requireText('public/index.html', "requireDataHealthStatic('buildPhase1EmployeeClosureSummary')", 'entry uses extracted Phase1 closure summary builder');
requireText('public/index.html', "requireDataHealthStatic('formatOnlineHistoryRaw')", 'entry uses extracted online history raw formatter');
requireText('public/index.html', 'data-health-static.js?v=20260628-static-router-fix', 'entry bumps data-health static helper version after static router fix');
requireText('public/data-health-static.js', 'const buildOnlineHistoryQueryParams', 'data-health static builds online history query parameters');
requireText('public/data-health-static.js', 'const formatOnlineHistoryHotelOption', 'data-health static formats online history hotel options');
requireText('public/data-health-static.js', 'const formatOnlineHistoryRaw', 'data-health static formats online history raw payloads');
requireText('public/data-health-static.js', 'const buildHotelDataDashboardRequests', 'data-health static builds hotel data dashboard request URLs');
requireText('public/data-health-static.js', 'const buildPhase1MetricDomainReadiness', 'data-health static builds Phase1 metric domain readiness');
requireText('public/data-health-static.js', 'const buildPhase1TrafficP0NextText', 'data-health static builds Phase1 traffic P0 next text');
requireText('public/data-health-static.js', 'const buildPhase1TrafficLatestSyncTaskText', 'data-health static builds Phase1 latest sync task diagnostics');
requireText('public/data-health-static.js', 'traffic_latest_sync_task_message_code_counts', 'data-health static exposes latest sync task diagnosis codes');
requireText('public/data-health-static.js', 'const phase1EmployeeEvidenceStatusText', 'data-health static maps Phase1 employee evidence status text');
requireText('public/data-health-static.js', 'const phase1EmployeeGapCodeText', 'data-health static maps Phase1 employee gap code text');
requireText('public/data-health-static.js', 'const phase1EmployeeActionCodeText', 'data-health static maps Phase1 employee action code text');
requireText('public/data-health-static.js', 'const phase1EmployeeSourceSnapshotText', 'data-health static maps Phase1 source snapshots');
requireText('public/data-health-static.js', 'const phase1EmployeeQuestionNextActionText', 'data-health static maps Phase1 question next actions');
requireText('public/data-health-static.js', 'const phase1EmployeeQuestionEvidenceText', 'data-health static maps Phase1 question evidence text');
requireText('public/data-health-static.js', 'const normalizePhase1EmployeeQuestionRow', 'data-health static normalizes Phase1 employee question rows');
requireText('public/data-health-static.js', 'const phase1MissingFieldLabel', 'data-health static maps Phase1 missing field labels');
requireText('public/data-health-static.js', 'const phase1MissingFieldNextActionText', 'data-health static maps Phase1 missing field next actions');
requireText('public/data-health-static.js', 'const phase1MetricDomainProblemText', 'data-health static maps Phase1 metric-domain problem text');
requireText('public/data-health-static.js', 'const phase1MetricDomainNextActionText', 'data-health static maps Phase1 metric-domain next action text');
requireText('public/data-health-static.js', 'const phase1EmployeeCountItem', 'data-health static builds Phase1 employee count items');
requireText('public/data-health-static.js', 'const phase1EmployeeQuestionBlockingGapCodes', 'data-health static builds Phase1 question blocking gap codes');
requireText('public/data-health-static.js', 'const mergePhase1EmployeeQuestionRow', 'data-health static merges Phase1 question rows');
requireText('public/data-health-static.js', 'const phase1EmployeeQuestionPresentationRow', 'data-health static builds Phase1 presentation question rows');
requireText('public/data-health-static.js', 'const phase1EmployeeActionEntryText', 'data-health static maps Phase1 action entries');
requireText('public/data-health-static.js', 'const phase1EmployeeActionEntryOptionText', 'data-health static maps Phase1 action entry options');
requireText('public/data-health-static.js', 'const phase1EmployeeActionEntryOptionGuidanceText', 'data-health static maps Phase1 action entry option guidance');
requireText('public/data-health-static.js', 'const phase1EmployeeActionEntryOptionReadinessText', 'data-health static maps Phase1 action entry option readiness');
requireText('public/data-health-static.js', 'const phase1EmployeeKnownQuestionText', 'data-health static maps Phase1 known question text');
requireText('public/data-health-static.js', 'const phase1EmployeeKnownQuestionListText', 'data-health static maps Phase1 known question list text');
requireText('public/data-health-static.js', 'const phase1EmployeeActionRawCode', 'data-health static extracts Phase1 action raw codes');
requireText('public/data-health-static.js', 'const phase1EmployeeActionPlatformText', 'data-health static maps Phase1 action platform text');
requireText('public/data-health-static.js', 'const phase1EmployeeActionSuccessCriteriaText', 'data-health static maps Phase1 action success criteria');
requireText('public/data-health-static.js', 'const phase1EmployeeActionEvidenceNeededText', 'data-health static maps Phase1 action evidence needed');
requireText('public/data-health-static.js', 'const phase1EmployeeActionVerificationStepsText', 'data-health static maps Phase1 action verification steps');
requireText('public/data-health-static.js', 'const phase1EmployeeActionBlockedActionText', 'data-health static maps Phase1 blocked actions');
requireText('public/data-health-static.js', 'const phase1EmployeeActionEmployeeExplanationText', 'data-health static maps Phase1 employee explanations');
requireText('public/data-health-static.js', 'const phase1EmployeeActionLimitedConclusionsText', 'data-health static maps Phase1 limited conclusions');
requireText('public/data-health-static.js', 'const phase1EmployeeActionStillUsableMetricsText', 'data-health static maps Phase1 still-usable metrics');
requireText('public/data-health-static.js', 'const phase1EmployeeActionExplanationNextActionText', 'data-health static maps Phase1 explanation next actions');
requireText('public/data-health-static.js', 'const phase1EmployeeActionDisplayText', 'data-health static maps Phase1 action display text');
requireText('public/data-health-static.js', 'const phase1EmployeeActionOwnerText', 'data-health static maps Phase1 action owner text');
requireText('public/data-health-static.js', 'const phase1EmployeeActionMetaText', 'data-health static maps Phase1 action meta text');
requireText('public/data-health-static.js', 'const phase1EmployeeActionProtectedBoundaryText', 'data-health static maps Phase1 protected boundary text');
requireText('public/data-health-static.js', 'const normalizePhase1EmployeeRequiredAction', 'data-health static normalizes Phase1 required actions');
requireText('public/data-health-static.js', 'const phase1LocalActionMeta', 'data-health static maps Phase1 local action metadata');
requireText('public/data-health-static.js', 'const buildPhase1LocalRequiredAction', 'data-health static builds Phase1 local required actions');
requireText('public/data-health-static.js', 'const buildPhase1EmployeeRequiredActions', 'data-health static builds Phase1 required actions');
requireText('public/data-health-static.js', 'const phase1DiagnosisActionItemStatus', 'data-health static maps Phase1 diagnosis action item status');
requireText('public/data-health-static.js', 'const phase1DiagnosisActionItemText', 'data-health static maps Phase1 diagnosis action item text');
requireText('public/data-health-static.js', 'const phase1DiagnosisActionItemBlocked', 'data-health static detects blocked Phase1 diagnosis action items');
requireText('public/data-health-static.js', 'const buildPhase1AiDiagnosisEvidence', 'data-health static builds Phase1 AI diagnosis evidence');
requireText('public/data-health-static.js', 'const phase1EmployeeCollectionDataTypeText', 'data-health static maps Phase1 collection data types');
requireText('public/data-health-static.js', 'const normalizePhase1CollectionSourceSummaryRow', 'data-health static normalizes Phase1 collection source summary rows');
requireText('public/data-health-static.js', 'const buildPhase1EmployeeCollectionSourceRows', 'data-health static builds Phase1 collection source rows');
requireText('public/data-health-static.js', 'const phase1FieldTrustStatusClass', 'data-health static maps Phase1 field trust status class');
requireText('public/data-health-static.js', 'const normalizePhase1EmployeeFieldTrustRow', 'data-health static normalizes Phase1 field trust rows');
requireText('public/data-health-static.js', 'const buildPhase1EmployeeFieldTrustRows', 'data-health static builds Phase1 field trust rows');
requireText('public/data-health-static.js', 'const normalizePhase1EmployeeMissingFieldRow', 'data-health static normalizes Phase1 missing field rows');
requireText('public/data-health-static.js', 'const normalizePhase1EmployeeMissingFieldSummaryRow', 'data-health static normalizes Phase1 missing field summary rows');
requireText('public/data-health-static.js', 'const buildPhase1EmployeeMissingFieldRows', 'data-health static builds Phase1 missing field rows');
requireText('public/data-health-static.js', 'const normalizePhase1EmployeeMetricDomainRow', 'data-health static normalizes Phase1 metric domain rows');
requireText('public/data-health-static.js', 'const normalizePhase1EmployeeMetricDomainSummaryRow', 'data-health static normalizes Phase1 metric domain summary rows');
requireText('public/data-health-static.js', 'const buildPhase1EmployeeMetricDomainRows', 'data-health static builds Phase1 metric domain rows');
requireText('public/data-health-static.js', 'const phase1EmployeeAiJudgementText', 'data-health static maps Phase1 AI judgement text');
requireText('public/data-health-static.js', 'const phase1EmployeeAiLimitText', 'data-health static maps Phase1 AI limit text');
requireText('public/data-health-static.js', 'const phase1EmployeeOperationJudgementText', 'data-health static maps Phase1 operation judgement text');
requireText('public/data-health-static.js', 'const phase1EmployeeOperationLimitText', 'data-health static maps Phase1 operation limit text');
requireText('public/data-health-static.js', 'const buildPhase1EmployeeAiEvidenceSummary', 'data-health static builds Phase1 AI evidence summary');
requireText('public/data-health-static.js', 'const buildPhase1EmployeeOperationSummary', 'data-health static builds Phase1 operation summary');
requireText('public/data-health-static.js', 'const buildPhase1EmployeeClosureSummary', 'data-health static builds Phase1 closure summary');
requireText('public/data-health-static.js', 'const buildPhase1EmployeeQuestionRows', 'data-health static builds Phase1 employee question rows');
requireText('public/index.html', 'const params = buildOnlineHistoryQueryParams({', 'online history loader delegates query parameter construction');
requireText('public/index.html', 'const requests = buildHotelDataDashboardRequests({ selectedHotelId });', 'hotel data dashboard loader delegates request URL construction');
requireText('public/index.html', 'const phase1EmployeeQuestionRows = computed(() => buildPhase1EmployeeQuestionRows({', 'Phase1 employee questions delegate row construction');
requireText('public/data-health-static.js', 'const p0NextText = buildPhase1TrafficP0NextText(row);', 'Phase1 employee question evidence delegates traffic P0 next text construction');
requireNoText('public/index.html', "const accountParams = new URLSearchParams();\n                    accountParams.append('days', '30');", 'hotel data dashboard request parameters are not re-inlined');
requireNoText('public/index.html', 'const phase1HasAnyDataType = (types, needles)', 'Phase1 metric domain type matching is not re-inlined');
requireNoText('public/index.html', 'const trafficP0NextText = (row) => {', 'Phase1 traffic P0 next text builder is not re-inlined');
requireNoText('public/index.html', "key: 'default-sections'", 'Ctrip catalog detail rows are not re-inlined');
requireNoText('public/index.html', 'const actions = Array.isArray(collectionHealthCtripCatalog.value?.capture_gap_next_actions)', 'Ctrip catalog action rows are not re-inlined');
requireNoText('public/index.html', 'reasonText: collectionHealthCtripCatalogActionReasonText(action?.reason)', 'Ctrip catalog action row reason mapping is not re-inlined');
requireNoText('public/index.html', "if (!catalog.available) return 'waiting_config';", 'Ctrip catalog status is not re-inlined');
requireNoText('public/index.html', "if (!catalog.available) return catalog.message || '等待携程采集目录生成';", 'Ctrip catalog message is not re-inlined');
requireNoText('public/index.html', "if (catalog.is_live_capture_ready) return '采集状态：可用';", 'Ctrip catalog gate text is not re-inlined');
requireNoText('public/index.html', ".filter(row => String(row?.source || '').toLowerCase() === 'ctrip')", 'Ctrip persisted row filtering is not re-inlined');
requireNoText('public/index.html', 'Number(report.filtered_count || 0) > 0', 'Ctrip identity blocked logic is not re-inlined');
requireNoText('public/index.html', "if (!rows.length) return { value: '未配置', status: 'waiting_config', className: 'text-amber-700' };", 'Ctrip overview auth state is not re-inlined');
requireNoText('public/index.html', "for (const mapKey of ['metrics', 'raw_metrics', 'rank_metrics'])", 'Ctrip metric preview mapping is not re-inlined');
requireNoText('public/index.html', "if (key === 'avg_price' && amount !== null && quantity && quantity > 0)", 'Ctrip calculated metric logic is not re-inlined');
requireNoText('public/index.html', "[/订单|预订/, ['book_order_num', 'order_count', 'orderCount', 'bookOrderNum']]", 'Ctrip metric label key mapping is not re-inlined');
requireNoText('public/index.html', "const modules = collectionHealthCtripLatestModules.value.filter(module => sectionSet.has(String(module?.section || '').trim()));", 'Ctrip module stats are not re-inlined');
requireNoText('public/index.html', 'return collectionHealthCtripPersistedRows.value.filter(row => {', 'Ctrip context row filtering is not re-inlined');
requireNoText('public/index.html', 'const collectionHealthCtripMetricKeyAliases = (key) => {', 'Ctrip metric aliases are not re-inlined');
requireNoText('public/index.html', 'const metricKeyParts = metricKey.split(/[\\+,\\|\\s]+/).map(part => part.trim()).filter(Boolean);', 'Ctrip compound metric-key matching is not re-inlined');
requireNoText('public/index.html', 'const authState = collectionHealthCtripOverviewAuthState.value;', 'Ctrip missing diagnosis auth wiring is not re-inlined');
requireNoText('public/index.html', 'const rows = collectionHealthCtripPersistedRows.value;', 'Ctrip persisted metric lookup is not re-inlined');
requireNoText('public/index.html', 'const modules = collectionHealthCtripLatestModules.value;', 'Ctrip metric value module snapshots are not re-inlined');
requireNoText('public/index.html', 'const collectionHealthCtripOverviewMetric = (label, sections, labels, options = {}) => ({', 'Ctrip overview metric list construction is not re-inlined');
requireNoText('public/index.html', "const buildGroup = (key, label, sections, metrics) => ({", 'Ctrip core snapshot groups are not re-inlined');
requireNoText('public/index.html', "collectionHealthCtripOverviewMetric('实时预订订单'", 'Ctrip revenue metric definitions are not re-inlined');
requireNoText('public/index.html', "collectionHealthCtripOverviewMetric('实时访客量'", 'Ctrip traffic metric definitions are not re-inlined');
requireNoText('public/index.html', 'const collectionHealthCtripOverviewFunnelMetric = (label, keys, dimensionIncludes = []) => ({', 'Ctrip funnel metric rows are not re-inlined');
requireNoText('public/index.html', "collectionHealthCtripOverviewFunnelMetric('列表页曝光量'", 'Ctrip funnel row definitions are not re-inlined');
requireNoText('public/index.html', "key: 'competitor',\n                    title: '竞争表现',", 'Ctrip overview panel definitions are not re-inlined');
requireNoText('public/index.html', 'const allMetrics = [', 'Ctrip missing action grouping is not re-inlined');
requireNoText('public/index.html', 'const evidenceStatusText = (value) => ({', 'Phase1 employee evidence status mapping is not re-inlined');
requireNoText('public/index.html', "source_date_evidence_missing: '目标日来源证据缺失'", 'Phase1 employee gap code mapping is not re-inlined');
requireNoText('public/index.html', "if (raw === 'phase1_confirm_source_date_evidence')", 'Phase1 employee action code mapping is not re-inlined');
requireNoText('public/index.html', "available_room_nights_missing: '可售房晚缺失'", 'Phase1 missing field labels are not re-inlined');
requireNoText('public/index.html', "按字段资产核对平台返回和入库字段", 'Phase1 missing field next actions are not re-inlined');
requireNoText('public/index.html', "收益、流量、转化均可复核。", 'Phase1 metric-domain problem text is not re-inlined');
requireNoText('public/index.html', "补齐流量/转化事实，再复核漏斗诊断。", 'Phase1 metric-domain next action text is not re-inlined');
requireNoText('public/index.html', "AI 建议已有可追溯证据和可执行动作项。", 'Phase1 AI judgement text is not re-inlined');
requireNoText('public/index.html', "const provedRows = rows.filter(row => ['proved', 'no_gap_reported'].includes(String(row?.status || '')));", 'Phase1 employee closure proved rows are not re-inlined');
requireNoText('public/index.html', "const topAction = actions.find(item => String(item?.status || '') !== 'blocked') || actions[0] || null;", 'Phase1 employee closure top-action selection is not re-inlined');
requireNoText('public/index.html', 'const summaryRows = Array.isArray(backendQuestionSource?.collection_source_summary)', 'Phase1 collection source rows builder is not re-inlined');
requireNoText('public/index.html', "const trustedQuestion = backendRows.find(row => String(row?.key || '') === 'trusted_fields') || {};", 'Phase1 field trust rows builder is not re-inlined');
requireNoText('public/index.html', 'const appendCodes = (codes, source) => {', 'Phase1 missing field rows builder is not re-inlined');
requireNoText('public/index.html', 'const hasType = (needles) => targetTypes.some(type => needles.some(needle => type.includes(needle)));', 'Phase1 metric domain rows builder is not re-inlined');
requireNoText('public/index.html', 'const latestLog = collectionHealthLatestLog.value || {};', 'Phase1 employee question rows builder is not re-inlined');
requireNoText('public/index.html', 'const localRows = [', 'Phase1 employee question local rows are not re-inlined');
requireNoText('public/index.html', 'const normalizedLocalRows = localRows.map(normalizePhase1EmployeeQuestionRow);', 'Phase1 employee question row normalization is not re-inlined');
requireNoText('public/index.html', 'const actions = Array.isArray(backendQuestionSource?.next_required_actions)', 'Phase1 required actions builder is not re-inlined');
requireNoText('public/index.html', '.map(buildPhase1LocalRequiredAction)', 'Phase1 local required action mapping is not re-inlined');
requireNoText('public/index.html', "不能把 blocked 动作项当成可执行经营建议。", 'Phase1 AI limit text is not re-inlined');
requireNoText('public/index.html', "运营动作已有审批、执行、证据、复盘或 ROI 信号。", 'Phase1 operation judgement text is not re-inlined');
requireNoText('public/index.html', "不能把未关联 OTA 诊断的普通执行记录算作闭环。", 'Phase1 operation limit text is not re-inlined');
requireNoText('public/index.html', 'const phase1EmployeeQuestionBlockingGapCodes = (row) => {', 'Phase1 question blocking gap builder is not re-inlined');
requireNoText('public/index.html', 'const mergePhase1EmployeeQuestionRow = (backendRow, localRow) => {', 'Phase1 question row merger is not re-inlined');
requireNoText('public/index.html', 'const phase1EmployeeQuestionPresentationRow = (backendRow, localRow) => ({', 'Phase1 question presentation row builder is not re-inlined');
requireNoText('public/index.html', 'const phase1EmployeeActionEntryText = (entry, item = {}) => {', 'Phase1 action entry text mapper is not re-inlined');
requireNoText('public/index.html', 'const phase1EmployeeActionEntryOptionModeText = (option) => {', 'Phase1 action entry option mode text is not re-inlined');
requireNoText('public/index.html', 'const phase1EmployeeActionEntryOptionContractText = (option) => {', 'Phase1 action entry option contract text is not re-inlined');
requireNoText('public/index.html', 'const phase1EmployeeActionEntryOptionReadinessText = (option) => {', 'Phase1 action entry option readiness text is not re-inlined');
requireNoText('public/index.html', 'const phase1EmployeeKnownQuestionText = (key) => {', 'Phase1 known question text is not re-inlined');
requireNoText('public/index.html', 'const phase1EmployeeKnownQuestionListText = (values) => {', 'Phase1 known question list text is not re-inlined');
requireNoText('public/index.html', 'const phase1EmployeeActionRawCode = (item) => {', 'Phase1 action raw-code helper is not re-inlined');
requireNoText('public/index.html', 'const phase1EmployeeActionPlatformText = (item, rawCode) => {', 'Phase1 action platform helper is not re-inlined');
requireNoText('public/index.html', 'phase1EmployeeGapCodeTextFromStatic', 'Phase1 gap code text does not use an entry adapter');
requireNoText('public/index.html', 'phase1EmployeeActionCodeTextFromStatic', 'Phase1 action code text does not use an entry adapter');
requireNoText('public/index.html', 'const phase1EmployeeSourceSnapshotText = (sourceSnapshot) => {', 'Phase1 source snapshot text is not re-inlined');
requireNoText('public/index.html', 'const phase1EmployeeQuestionNextActionText = (row) => {', 'Phase1 question next-action text is not re-inlined');
requireNoText('public/index.html', 'const phase1EmployeeQuestionEvidenceText = (evidence) => {', 'Phase1 question evidence text is not re-inlined');
requireNoText('public/index.html', 'const normalizePhase1EmployeeQuestionRow = (row) => ({', 'Phase1 employee question row normalizer is not re-inlined');
requireNoText('public/index.html', 'const phase1EmployeeActionSuccessCriteriaText = (item) => {', 'Phase1 action success criteria text is not re-inlined');
requireNoText('public/index.html', 'const phase1EmployeeActionEvidenceNeededText = (item) => {', 'Phase1 action evidence needed text is not re-inlined');
requireNoText('public/index.html', 'const phase1EmployeeActionVerificationStepsText = (item) => {', 'Phase1 action verification steps text is not re-inlined');
requireNoText('public/index.html', 'const phase1EmployeeActionBlockedActionText = (item) => {', 'Phase1 blocked action text is not re-inlined');
requireNoText('public/index.html', 'const phase1EmployeeActionEmployeeExplanationText = (item) => {', 'Phase1 employee explanation text is not re-inlined');
requireNoText('public/index.html', 'const phase1EmployeeActionLimitedConclusionsText = (item) => {', 'Phase1 limited conclusions text is not re-inlined');
requireNoText('public/index.html', 'const phase1EmployeeActionStillUsableMetricsText = (item) => {', 'Phase1 still usable metrics text is not re-inlined');
requireNoText('public/index.html', 'const phase1EmployeeActionExplanationNextActionText = (item) => {', 'Phase1 explanation next action text is not re-inlined');
requireNoText('public/index.html', 'const phase1EmployeeActionDisplayText = (item) => {', 'Phase1 action display text is not re-inlined');
requireNoText('public/index.html', 'const phase1EmployeeActionOwnerText = (item) => {', 'Phase1 action owner text is not re-inlined');
requireNoText('public/index.html', 'const phase1EmployeeActionMetaText = (item) => {', 'Phase1 action meta text is not re-inlined');
requireNoText('public/index.html', 'const phase1EmployeeActionProtectedBoundaryText = (item) => {', 'Phase1 protected boundary text is not re-inlined');
requireNoText('public/index.html', 'const normalizePhase1EmployeeRequiredAction = (item) => {', 'Phase1 required action normalizer is not re-inlined');
requireNoText('public/index.html', 'const phase1LocalActionMeta = (key) => ({', 'Phase1 local action metadata is not re-inlined');
requireNoText('public/index.html', 'const buildPhase1LocalRequiredAction = (row, index = 0) => {', 'Phase1 local required action builder is not re-inlined');
requireNoText('public/index.html', 'const phase1DiagnosisActionItemStatus = (item) =>', 'Phase1 diagnosis action item status is not re-inlined');
requireNoText('public/index.html', 'const phase1DiagnosisActionItemText = (item) =>', 'Phase1 diagnosis action item text is not re-inlined');
requireNoText('public/index.html', 'const phase1DiagnosisActionItemBlocked = (item) => {', 'Phase1 diagnosis action item blocked detector is not re-inlined');
requireNoText('public/index.html', 'const evidenceSources = Array.isArray(diagnosisResult?.evidence_sources)', 'Phase1 AI diagnosis evidence calculation is not re-inlined');
requireNoText('public/index.html', 'const allBlocking = Array.from(new Set([...blocking, ...rowBlocking]));', 'Phase1 AI evidence summary is not re-inlined');
requireNoText('public/index.html', 'const completionSignalCount = Number(evidence.completion_signal_count || 0)', 'Phase1 operation summary is not re-inlined');
requireNoText('public/index.html', 'const phase1EmployeeCollectionDataTypeText = (type) => {', 'Phase1 collection data type text is not re-inlined');
requireNoText('public/index.html', 'const normalizePhase1CollectionSourceSummaryRow = (row) => {', 'Phase1 collection source summary normalizer is not re-inlined');
requireNoText('public/index.html', 'const phase1FieldTrustStatusClass = (status) =>', 'Phase1 field trust status class is not re-inlined');
requireNoText('public/index.html', 'const normalizePhase1EmployeeFieldTrustRow = (row) => {', 'Phase1 field trust normalizer is not re-inlined');
requireNoText('public/index.html', 'const normalizePhase1EmployeeMissingFieldRow = (code, source =', 'Phase1 missing field normalizer is not re-inlined');
requireNoText('public/index.html', 'const normalizePhase1EmployeeMissingFieldSummaryRow = (item) => {', 'Phase1 missing field summary normalizer is not re-inlined');
requireNoText('public/index.html', 'const normalizePhase1EmployeeMetricDomainRow = (row) => {', 'Phase1 metric domain normalizer is not re-inlined');
requireNoText('public/index.html', 'const normalizePhase1EmployeeMetricDomainSummaryRow = (row) => {', 'Phase1 metric domain summary normalizer is not re-inlined');
requireText('public/index.html', 'let onlineHistoryHotelListLoadingPromise = null;', 'online history hotel filter options deduplicate in-flight hotel list loads');
requireText('public/index.html', 'const onlineHistoryHotelListLoaded = ref(false);', 'online history hotel filter options track loaded state');
requireText('public/index.html', 'const refreshOnlineHistory = async (options = {}) => {', 'online history refresh supports skipping hotel filter reloads');
requireText('public/index.html', "const scheduleOnlineHistoryRefresh = () => schedulePostFetchRefresh('online-history', () => refreshOnlineHistory({ refreshHotels: false }), 340);", 'post-fetch history refresh does not reload the hotel filter list');
requireNoText('public/index.html', 'await Promise.all([loadOnlineHistory(), loadOnlineHistoryHotelList()]);', 'online history refresh must not always reload the hotel filter list');
requireNoText('public/index.html', "schedulePostFetchRefresh('online-history', () => refreshOnlineHistory(), 340)", 'post-fetch history refresh must skip hotel filter reloads');
requireNoText('public/index.html', "params.append('hotel_id', filter.hotel_scope);", 'online history hotel scope query construction is not re-inlined');
requireNoText('public/index.html', "text: '销售额(¥)'", 'analysis chart axis labels are not re-inlined in the SPA entry');
{
  const context = { window: {}, URLSearchParams };
  vm.runInNewContext(read('public/data-health-static.js'), context, {
    filename: 'public/data-health-static.js',
  });
  const chartData = { labels: ['2026-06-11'], datasets: [{ label: 'OTA销售额', data: [100] }] };
  const config = context.window.SUXI_DATA_HEALTH_STATIC.buildOnlineAnalysisChartConfig(chartData);
  const historyParams = context.window.SUXI_DATA_HEALTH_STATIC.buildOnlineHistoryQueryParams({
    page: 3,
    pageSize: 50,
    filter: {
      platform: 'ctrip',
      data_type: 'business',
      hotel_scope: '58',
      keyword: 'hotel-key',
      start_date: '2026-06-01',
      end_date: '2026-06-11',
    },
  });
  const competitorParams = context.window.SUXI_DATA_HEALTH_STATIC.buildOnlineHistoryQueryParams({
    filter: { platform: 'all', data_type: 'all', hotel_scope: 'competitor_avg' },
  });
  const dashboardRequests = context.window.SUXI_DATA_HEALTH_STATIC.buildHotelDataDashboardRequests({
    selectedHotelId: '58',
  });
  const phase1MetricReadiness = context.window.SUXI_DATA_HEALTH_STATIC.buildPhase1MetricDomainReadiness({
    sourceDatePlatformRows: [
      { platform: 'ctrip', target_date_rows: 2, target_date_data_types: ['business', 'traffic'] },
      { platform: 'meituan', target_date_rows: 0, target_date_data_types: [] },
    ],
    metricTrustKeys: ['metric.fact'],
    hasCompleteTargetDateCoverage: false,
  });
  const trafficP0NextText = context.window.SUXI_DATA_HEALTH_STATIC.buildPhase1TrafficP0NextText({
    p0_traffic_gate_status: 'missing_target_date_traffic_rows',
    p0_next_action_mode: 'browser_profile',
    p0_pre_import_evidence_status: 'valid_external_evidence_not_ingested',
    p0_payload_candidate_missing_count: 2,
    p0_required_metric_keys: ['曝光', '访客'],
    p0_field_loop_matrix: [{ status: 'no_target_date_traffic_rows' }, { status: 'requires_p0_verifier' }],
    p0_traffic_closure_chain: {
      source: { status: 'no_target_date_traffic_rows' },
      verifier: { status: 'requires_p0_verifier' },
    },
    p0_traffic_field_fact_status: 'no_target_date_traffic_rows',
    p0_next_action_entry: '/api/online-data/ctrip/traffic',
    next_command_policy: 'metadata_only_no_sensitive_commands',
    traffic_latest_sync_task_count: 2,
    traffic_latest_sync_task_status_counts: { waiting_config: 2 },
    traffic_latest_sync_task_message_code_counts: { login_or_profile_not_ready: 2 },
    traffic_latest_sync_task_saved_count: 0,
    traffic_latest_sync_task_normalized_count: 0,
    traffic_latest_sync_task_sensitive_values_exposed: false,
  });
  const phase1EvidenceStatusText = context.window.SUXI_DATA_HEALTH_STATIC.phase1EmployeeEvidenceStatusText;
  const phase1GapCodeText = context.window.SUXI_DATA_HEALTH_STATIC.phase1EmployeeGapCodeText;
  const phase1ActionCodeText = context.window.SUXI_DATA_HEALTH_STATIC.phase1EmployeeActionCodeText;
  checks.push({
    file: 'public/data-health-static.js',
    label: 'online analysis chart config preserves chart data and axis semantics',
    ok: config?.type === 'line'
      && config?.data === chartData
      && config?.options?.scales?.y?.title?.text === '销售额(¥)'
      && config?.options?.scales?.y1?.title?.text === '房晚/订单'
      && config?.options?.scales?.y1?.grid?.drawOnChartArea === false,
    detail: 'buildOnlineAnalysisChartConfig must keep original Chart.js line config semantics',
  });
  checks.push({
    file: 'public/data-health-static.js',
    label: 'online history query builder preserves filter semantics',
    ok: historyParams.get('page') === '3'
      && historyParams.get('page_size') === '50'
      && historyParams.get('platform') === 'ctrip'
      && historyParams.get('data_type') === 'business'
      && historyParams.get('hotel_scope') === 'hotel'
      && historyParams.get('hotel_id') === '58'
      && historyParams.get('keyword') === 'hotel-key'
      && historyParams.get('start_date') === '2026-06-01'
      && historyParams.get('end_date') === '2026-06-11'
      && !competitorParams.has('platform')
      && !competitorParams.has('data_type')
      && competitorParams.get('hotel_scope') === 'competitor_avg'
      && !competitorParams.has('hotel_id'),
    detail: 'buildOnlineHistoryQueryParams samples',
  });
  checks.push({
    file: 'public/data-health-static.js',
    label: 'hotel data dashboard request builder preserves endpoint and hotel filter semantics',
    ok: dashboardRequests.accountOverviewUrl === '/dashboard/account-overview?days=30'
      && dashboardRequests.hotelPortraitUrl === '/dashboard/hotel-portrait?days=30&hotel_id=58'
      && dashboardRequests.dataSourcesUrl === '/dashboard/data-sources?days=30',
    detail: 'buildHotelDataDashboardRequests sample',
  });
  checks.push({
    file: 'public/data-health-static.js',
    label: 'Phase1 metric domain readiness preserves OTA evidence boundaries',
    ok: phase1MetricReadiness.allMetricDomainsReady === false
      && phase1MetricReadiness.revenueReadyPlatforms.includes('ctrip')
      && phase1MetricReadiness.trafficReadyPlatforms.includes('ctrip')
      && phase1MetricReadiness.revenueMissingPlatforms.includes('meituan')
      && phase1MetricReadiness.trafficMissingPlatforms.includes('meituan')
      && phase1MetricReadiness.metricDomainGapCodes.includes('meituan_revenue_metric_inputs_missing')
      && phase1MetricReadiness.metricDomainGapCodes.includes('meituan_traffic_conversion_facts_missing')
      && phase1MetricReadiness.platformFieldTrust.some(row => row.platform === 'ctrip' && row.field_trust_status === 'target_date_revenue_sample_present')
      && phase1MetricReadiness.platformFieldTrust.some(row => row.platform === 'meituan' && row.field_trust_status === 'target_date_source_missing'),
    detail: 'buildPhase1MetricDomainReadiness sample',
  });
  checks.push({
    file: 'public/data-health-static.js',
    label: 'Phase1 traffic P0 next text preserves missing-data evidence wording',
    ok: trafficP0NextText.includes('P0缺目标日流量')
      && trafficP0NextText.includes('外部证据未入库')
      && trafficP0NextText.includes('预期Payload缺失 2 项')
      && trafficP0NextText.includes('最近同步 2 项')
      && trafficP0NextText.includes('登录/Profile未就绪:2')
      && trafficP0NextText.includes('同步诊断已脱敏')
      && trafficP0NextText.includes('需闭环指标 2 项')
      && trafficP0NextText.includes('字段矩阵 2 项')
      && trafficP0NextText.includes('链路未加载 1 项')
      && trafficP0NextText.includes('目标日流量字段未加载')
      && trafficP0NextText.includes('建议浏览器 Profile')
      && trafficP0NextText.includes('不展示敏感命令'),
    detail: 'buildPhase1TrafficP0NextText sample',
  });
  checks.push({
    file: 'public/data-health-static.js',
    label: 'Phase1 employee evidence status mapper preserves explicit blocked wording',
    ok: phase1EvidenceStatusText('blocked_by_verified_ota_gaps') === '已验证 OTA 缺口阻断'
      && phase1EvidenceStatusText('operation_execution_evidence_incomplete') === '运营执行证据不完整'
      && phase1EvidenceStatusText('unknown_new_status') === 'unknown_new_status',
    detail: 'phase1EmployeeEvidenceStatusText sample',
  });
  checks.push({
    file: 'public/data-health-static.js',
    label: 'Phase1 employee gap and action code mappers preserve dynamic labels',
    ok: phase1GapCodeText('today_ota_collected', key => (key === 'today_ota_collected' ? '今天 OTA 数据有没有采到' : '')) === '今天 OTA 数据有没有采到'
      && phase1GapCodeText('meituan_traffic_facts_missing') === '美团流量事实缺失'
      && phase1GapCodeText('unrecognized_gap_code') === '未识别证据缺口'
      && phase1ActionCodeText('local_ai_evidence_required_action', {
        knownQuestionText: key => (key === 'ai_evidence' ? 'AI 建议依据' : ''),
        platformText: value => String(value || '').toUpperCase(),
      }) === '补齐AI 建议依据证据'
      && phase1ActionCodeText('meituan_source_rows_missing_collect_existing_path', {
        platformText: value => (value === 'meituan' ? '美团' : value),
      }) === '使用现有美团入口补齐目标日源数据',
    detail: 'phase1EmployeeGapCodeText/phase1EmployeeActionCodeText samples',
  });
}
{
  const context = { window: {} };
  vm.runInNewContext(read('public/home-static.js'), context, {
    filename: 'public/home-static.js',
  });
  const homeStatic = context.window.SUXI_HOME_STATIC || {};
  const holiday = homeStatic.normalizeHolidayCountdownItem({
    name: 'Future Holiday',
    start_date: '2099-10-01',
    end_date: '2099-10-03',
  });
  const holidaySuggestions = homeStatic.buildHolidayOperationSuggestions({
    nearest: { name: 'Future Holiday', days_left: 5, holiday_days: 3 },
    next: { name: 'Next Holiday', days_left: 40 },
    hotelPool: [{ id: 7, name: 'Hotel 7' }],
    selectedHotelId: '7',
    trendHasSamples: true,
    trendSampleDays: 12,
    trendJudgement: 'up',
    weatherSignal: { level: 'red', status_text: 'alert' },
  });
  const macroFallback = homeStatic.buildMacroSignalFallback('sample');
  const macroCards = homeStatic.buildMacroSignalViewCards([
    { key: 'weather', status: 'pending', status_text: 'sync', metrics: [{ label: 'L1', value: 'V1' }], suggestions: ['S1'] },
  ], {
    weather: { icon: 'weather-icon', meaning: 'meaning', impact: 'impact', action: 'fallback-action' },
  });
  const forecastItems = homeStatic.buildHomeMarketForecastItems({
    trendCards: [{ key: 'demand', value: '100', direction: 'up' }],
    demandSignal: { status: 'ok', status_text: 'ready' },
    priceSignal: { status: 'pending', status_text: 'pending' },
    channelSignal: { status: 'ok', status_text: 'channel-ready' },
    nearestHoliday: { name: 'Future Holiday', distance_text: '5d' },
    weatherValue: 'weather-ready',
    trendHasSamples: true,
  });
  const forecastSummary = homeStatic.buildHomeMarketForecastSummaryRows(
    forecastItems,
    Object.fromEntries([[forecastItems[0]?.name, 'note-demand']]),
  );
  const forecastStatus = homeStatic.homeMarketForecastStatus(forecastItems);
  const forecastAction = homeStatic.resolveHomeMarketForecastAction({
    trendHasSamples: true,
    trendAction: 'Action',
    readinessNextAction: 'Next',
  });
  const metricSample = {
    revenue: { data: ['1,000', '', null, -2, 300] },
    adr: { data: [100, 200, 'bad'] },
  };
  const signalMetric = homeStatic.homeSignalMetricText({
    metrics: [{ label: 'exposure visitors', value: '12', unit: '%' }],
  }, ['visitors']);
  const competitorSample = {
    source_notice: 'source notice',
    display_hotels: [{ isSelf: true }],
    display_summary: { rank_insights: [{ key: 'rank' }] },
  };
  checks.push({
    file: 'public/home-static.js',
    label: 'home static exports holiday, trend, signal, and competitor tag helpers',
    ok: holiday?.start_date === '2099-10-01'
      && holiday?.end_date === '2099-10-03'
      && holiday?.holiday_days === 3
      && homeStatic.homeTrendBadgeClass('green').includes('text-green-700')
      && homeStatic.homeTrendCardHasData({ value: '128', direction: 'up' }) === true
      && homeStatic.homeTrendCardHasData({ value: '待同步', direction: '待同步' }) === false
      && homeStatic.macroSignalLevelClass({ level: 'red' }).includes('text-red-700')
      && homeStatic.homeTextHasValue('未返回') === false
      && homeStatic.homeTextHasValue('12%') === true
      && homeStatic.competitorPlatformTagText({ platform_tag_summary: { status: 'returned', vip_count: 2, returned_count: 5, tag_count: 3 } }).includes('VIP 2家')
      && homeStatic.competitorPlatformTagClass({ platform_tag_summary: { status: 'returned_empty' } }).includes('text-amber-700')
      && typeof homeStatic.holidayOperationStageText({ days_left: 5 }) === 'string'
      && homeStatic.holidayOperationStageText({ days_left: 5 }).length > 0
      && Array.isArray(holidaySuggestions)
      && holidaySuggestions.length > 0
      && holidaySuggestions.length <= 4
      && holidaySuggestions.some(item => String(item).includes('Future Holiday'))
      && Array.isArray(macroFallback)
      && macroFallback.length === 5
      && macroFallback.some(item => item.key === 'weather')
      && macroFallback.every(item => item.status === 'pending')
      && macroCards[0]?.icon === 'weather-icon'
      && macroCards[0]?.primaryAction === 'S1'
      && macroCards[0]?.primaryMetrics?.length === 2
      && forecastItems.length === 5
      && forecastItems.every(item => item.name && item.value && item.entry)
      && forecastSummary.length === 3
      && forecastSummary[0]?.note === 'note-demand'
      && typeof forecastStatus === 'string'
      && forecastStatus.length > 0
      && forecastAction === 'Action'
      && homeStatic.homeMetricSeriesSum(metricSample, 'revenue') === 1300
      && homeStatic.homeMetricSeriesAvg(metricSample, 'adr') === 150
      && homeStatic.homeMetricToneClass(true, 'green').includes('text-emerald-700')
      && homeStatic.homeMetricToneClass(false, 'green').includes('text-gray-500')
      && signalMetric.value === '12%'
      && signalMetric.ready === true
      && homeStatic.homeSignalMetricText(null, ['missing']).ready === false
      && homeStatic.competitorDisplayRows(competitorSample).length === 1
      && homeStatic.competitorDisplaySummary(competitorSample).rank_insights.length === 1
      && homeStatic.competitorSummarySourceNotice(competitorSample) === 'source notice'
      && homeStatic.competitorSummaryReadinessClass({ status: 'error' }).includes('text-red-700'),
    detail: 'home-static extracted helper samples',
  });
}
requireText('public/index.html', ':data-testid="pageTestId(currentPage)"', 'active page container exposes current page test id');
requireText('public/index.html', "const testIdStaticScript = 'testid-static.js'", 'frontend lazy-loads extracted test id helper');
requireText('public/index.html', 'const loadTestIdStatic = () =>', 'entry keeps explicit test id helper lazy loader');
requireText('public/index.html', "if (params.get('testids') === '1' || params.get('e2e') === '1') return true;", 'page-control test ids are explicit URL opt-in');
requireText('public/index.html', "return localStorage.getItem('enablePageTestIds') === '1';", 'page-control test ids are explicit localStorage opt-in');
requireNoText('public/index.html', "host === 'localhost' || host === '127.0.0.1' || host === '::1'", 'ordinary localhost does not auto-load page-control test id helper');
requireText('public/index.html', 'createPageTestIdController', 'entry wires extracted page test id controller after lazy load');
requireText('public/index.html', 'const schedulePageControlTestIdObserverStart = (delayMs = 520) =>', 'entry defers page-control test id observer startup off core page switching');
requireText('public/index.html', 'const schedulePublicSystemConfigRefresh = (delayMs = 1800) =>', 'entry defers public system-config refresh off core OTA page switching');
requireText('public/index.html', "const formOperationSupportScript = 'form-operation-support.js'", 'entry lazy-loads form operation support after login');
requireText('public/index.html', 'const shouldDeferFormOperationSupportLoad = () => isCompassDataPage() || isCoreOtaPageVisible();', 'form operation support does not prewarm on home or core OTA pages');
requireText('public/index.html', 'const pageDelay = shouldDeferFormOperationSupportLoad() ? 6400 : 5200;', 'form operation support loads after the first core OTA interaction window');
requireText('public/index.html', 'if (shouldDeferFormOperationSupportLoad()) return;', 'queued form operation support load rechecks page visibility before loading');
requireText('public/index.html', "const renderHomeTrendChart = (retryCount = 0) => {\n                if (!homeTrendHasSamples.value) {\n                    destroyHomeTrendChart();\n                    return;\n                }\n                const ChartLib = window.Chart;", 'home trend chart does not load Chart.js until usable trend samples exist');
requireText('public/index.html', 'const pageTestId = (page) =>', 'entry keeps page test id available before helper loads');
requireText('public/testid-static.js', 'assignPageControlTestIds', 'page controls receive generated stable test ids');
requireText('public/testid-static.js', 'normalizeTestIdSegment', 'test id helper keeps stable segment normalization');
requireText('public/index.html', 'buildGlobalNotifications({', 'entry uses extracted global notification builder');
requireText('public/notification-static.js', 'const buildGlobalNotifications', 'notification static builds global notification rows');
requireText('app/controller/SystemNotificationController.php', 'visibleNotificationIdsForCurrentUser', 'system notification bulk actions use DB-scoped visible ID query');
requireText('app/controller/SystemNotificationController.php', 'notification_state.is_cleared IS NULL OR notification_state.is_cleared <> 1', 'system notification bulk actions filter per-user cleared state in SQL');
requireNoText('app/controller/SystemNotificationController.php', 'filterRowsByCurrentUserState', 'system notification bulk actions do not reintroduce full-list PHP state filtering');
{
  const source = read('app/controller/SystemConfigController.php');
  const requestedKeyOffset = source.indexOf("$requestedKey = trim((string)$this->request->get('key', ''))");
  const firstFullConfigOffset = source.indexOf('$configs = SystemConfig::getAllConfigs();');
  checks.push({
    file: 'app/controller/SystemConfigController.php',
    label: 'system config single-key reads avoid full config scan',
    ok: requestedKeyOffset >= 0
      && firstFullConfigOffset > requestedKeyOffset
      && source.slice(requestedKeyOffset, firstFullConfigOffset).includes('SystemConfig::getValue($requestedKey'),
    detail: 'requested key branch must return before getAllConfigs',
  });
  checks.push({
    file: 'app/controller/SystemConfigController.php',
    label: 'system config public scope reads only public keys',
    ok: source.includes("request->get('scope', '')")
      && source.includes("SystemConfig::getConfigsByKeys($publicKeys)")
      && source.indexOf("SystemConfig::getConfigsByKeys($publicKeys)") < firstFullConfigOffset,
    detail: 'public scope must return before getAllConfigs',
  });
}
requireText('app/model/SystemConfig.php', 'public static function getConfigsByKeys(array $keys): array', 'system config model supports bounded key reads');
requireText('app/model/SystemConfig.php', 'private static array $valueCache = [];', 'system config model keeps request-local value cache for repeated key reads');
requireText('app/model/SystemConfig.php', 'private const DURABLE_VALUE_CACHE_KEYS = [', 'system config model keeps selected high-frequency keys in short cross-request cache');
requireNoText('app/model/SystemConfig.php', "'protected_capability_policy' => true,", 'protected capability policy must not use cross-request cache');
requireText('app/model/SystemConfig.php', "'ctrip_config_list' => true,", 'Ctrip config list uses short cross-request cache');
requireText('app/model/SystemConfig.php', "'meituan_config_list' => true,", 'Meituan config list uses short cross-request cache');
requireText('app/model/SystemConfig.php', 'if (array_key_exists($key, self::$valueCache)) {', 'system config getValue checks request-local cache before querying');
requireText('app/model/SystemConfig.php', '$cached = self::readDurableValueCache($key);', 'system config getValue checks selected cross-request cache before querying');
requireText('app/model/SystemConfig.php', "self::where('config_key', $key)->field('config_value')->find()", 'system config getValue reads only the config_value column');
requireText('app/model/SystemConfig.php', "self::$valueCache[$key] = ['found' => $found, 'value' => $value];", 'system config cache preserves missing-row vs null-value semantics');
requireText('app/model/SystemConfig.php', 'self::writeDurableValueCache($key, $found, $value);', 'system config getValue refreshes selected cross-request cache after DB reads');
requireText('app/model/SystemConfig.php', 'self::writeDurableValueCache($key, true, $value);', 'system config setValue refreshes selected cross-request cache after writes');
requireNoText('app/service/ProtectedCapabilityService.php', 'mightMatchDefaultCapabilityPath', 'protected capability classification must not be gated by default policy paths');
requireNoText('app/middleware/Auth.php', 'ProtectedCapabilityService::mightMatchDefaultCapabilityPath', 'auth middleware must load the configured protected policy before classification');
requireText('app/middleware/Auth.php', '$protectedCapabilityService = $this->protectedCapabilityService();\n        $capability = $protectedCapabilityService->classifyPath($request->method(), $request->url());', 'auth middleware classifies with the configured protected policy');
requireNoText('public/index.html', 'const isItemVisible = (item) => {', 'visible menu permission filter is not re-inlined');
requireNoText('public/index.html', 'const platformNextActionMeta =', 'platform next action metadata is not re-inlined');
requireNoText('public/index.html', 'const platformAccountStoreText =', 'platform account store text is not re-inlined');
requireNoText('public/index.html', 'const hotelId = String(\n                    form.hotelId', 'Ctrip browser capture hotel id resolution is not re-inlined');
requireNoText('public/index.html', "cookies: activeConfig?.cookies || activeConfig?.cookie || '',", 'Ctrip browser capture cookie payload is not re-inlined');
requireNoText('public/index.html', 'const optionSections = options.sections || options.captureSections ||', 'Ctrip browser capture section normalization is not re-inlined');
requireNoText('public/index.html', 'const normalizeCtripBrowserCaptureErrorResult = (error) => {', 'Ctrip browser capture error normalization is not re-inlined');
requireNoText('public/index.html', 'const targetContext = buildCtripBrowserCaptureTargetContext({', 'Ctrip browser capture target context flow is not re-inlined');
requireNoText('public/index.html', 'const requestContext = buildCtripBrowserCaptureRequestContext({', 'Ctrip browser capture request context flow is not re-inlined');
requireNoText('public/index.html', "const res = await request('/online-data/capture-ctrip-browser', {", 'Ctrip browser capture request flow is not re-inlined');
requireNoText('public/index.html', 'ctripBrowserCaptureResult.value = normalizeCtripBrowserCaptureErrorResult(e);', 'Ctrip browser capture catch flow is not re-inlined');
requireNoText('public/index.html', 'const cookies = ctripForm.value.cookies.trim();', 'Ctrip fetch credential trim is not re-inlined');
requireNoText('public/index.html', 'const nodeId = String(ctripForm.value.nodeId || \'\').trim();', 'Ctrip fetch node id normalization is not re-inlined');
requireNoText('public/index.html', 'const { startDate, endDate } = buildCtripFetchDateRange(ctripForm.value);', 'Ctrip fetch date range construction is not re-inlined');
requireNoText('public/index.html', 'const yesterday = new Date();', 'Ctrip fetch default date calculation is not re-inlined');
requireNoText('public/index.html', 'const ctripFetchBody = {', 'Ctrip fetch request body is not re-inlined');
requireNoText('public/index.html', 'const ctripFetchBody = buildCtripFetchRequestBody({', 'Ctrip fetch request body helper call is not re-inlined');
requireNoText('public/index.html', 'raw: rawResponse.substring(0, 1000)', 'Ctrip fetch raw failure result is not re-inlined');
requireNoText('public/index.html', 'const requestContext = buildCtripFetchRequestContext({', 'Ctrip fetch request context flow is not re-inlined');
requireNoText('public/index.html', 'onlineDataResult.value = selectCtripFetchResponsePayload(res.data || {});', 'Ctrip fetch success result flow is not re-inlined');
requireNoText('public/index.html', 'const currentFetchMeta = buildCtripFetchMeta({', 'Ctrip fetch meta flow is not re-inlined');
requireNoText('public/index.html', 'onlineDataResult.value = buildCtripFetchRawFailureResult({', 'Ctrip fetch raw failure flow is not re-inlined');
requireNoText('public/index.html', 'const rankRows = payload?.rank?.rows || [];', 'Ctrip latest snapshot row slicing is not re-inlined');
requireNoText('public/index.html', 'const trafficUrl = String(form.url || \'\').trim();', 'Ctrip traffic request URL trimming is not re-inlined');
requireNoText('public/index.html', 'const ctripTrafficFetchBody = {', 'Ctrip traffic request body is not re-inlined');
requireNoText('public/index.html', 'const ctripTrafficFetchBody = buildCtripTrafficFetchRequestBody({', 'Ctrip traffic request flow is not re-inlined');
requireNoText('public/index.html', 'const trafficModel = buildCtripTrafficResponseModel(res.data || {});', 'Ctrip traffic response flow is not re-inlined');
requireNoText('public/index.html', 'onlineDataResult.value = trafficModel.onlineResult;', 'Ctrip traffic success result write is not re-inlined');
requireNoText('public/index.html', 'const createCtripProfileFieldForm = () => ({', 'Ctrip Profile field default form is not re-inlined');
requireNoText('public/index.html', 'const ctripProfileSimpleHash = (value) => {', 'Ctrip Profile field key hashing is not re-inlined');
requireNoText('public/index.html', 'const ctripProfileEndpointFromUrl = (url) => {', 'Ctrip Profile endpoint parsing is not re-inlined');
requireNoText('public/index.html', 'const buildCtripProfileFieldSmartDefaults = (source =', 'Ctrip Profile smart defaults are not re-inlined');
requireNoText('public/index.html', 'const buildCtripProfileFieldSavePayload = () => {', 'Ctrip Profile save payload builder is not re-inlined');
requireNoText('public/index.html', 'decoded_data: decoded,', 'Ctrip traffic response model is not re-inlined');
requireNoText('public/index.html', 'request_urls: form.requestUrls,', 'Ctrip overview request body is not re-inlined');
requireNoText('public/index.html', 'request_urls: requestUrls,', 'Ctrip flow overview request body is not re-inlined');
requireNoText('public/index.html', "const requestUrls = form.requestUrls || ctripFlowOverviewDefaultRequestUrls.join('\\n');", 'Ctrip flow overview default URL selection is not re-inlined');
requireNoText('public/index.html', "method: form.method || 'POST',", 'Ctrip overview request method fallback is not re-inlined');
requireNoText('public/index.html', "method: form.method || 'GET',", 'Ctrip flow overview request method fallback is not re-inlined');
requireNoText('public/index.html', "const defaultCtripAdsEffectReportUrl = 'https://", 'Ctrip ads default URL is not re-inlined');
requireNoText('public/index.html', 'const isCtripAdsApiUrl = (url = \'\') => {', 'Ctrip ads URL guard is not re-inlined');
requireNoText('public/index.html', 'api_type: normalizeCtripAdsApiType(form.apiType),', 'Ctrip ads request body is not re-inlined');
requireNoText('public/index.html', 'const ctripAdsFetchBody = buildCtripAdsFetchRequestBody({', 'Ctrip ads request flow is not re-inlined');
requireNoText('public/index.html', 'const url = String(form.url || defaultCtripAdsEffectReportUrl).trim();', 'Ctrip ads URL selection is not re-inlined');
requireNoText('public/index.html', "const cookies = String(form.cookies || ctripForm.value.cookies || activeConfig.cookies || '').trim();", 'Ctrip ads cookie selection is not re-inlined');
requireNoText('public/index.html', 'profile_id: cookieApiProfileId,', 'Ctrip Cookie API request body is not re-inlined');
requireNoText('public/index.html', "method: String(ctripCookieApiForm.value.method || 'GET').toUpperCase(),", 'Ctrip Cookie API request method normalization is not re-inlined');
requireNoText('public/index.html', "payload_json: String(ctripCookieApiForm.value.payloadJson || '').trim(),", 'Ctrip Cookie API payload trimming is not re-inlined');
requireNoText('public/index.html', 'const requestUrl = String(ctripCookieApiForm.value.requestUrl || \'\').trim();', 'Ctrip Cookie API request source validation is not re-inlined');
requireNoText('public/index.html', 'const cookies = String(ctripCookieApiForm.value.cookies || activeConfig?.cookies || activeConfig?.cookie || \'\').trim();', 'Ctrip Cookie API cookie source selection is not re-inlined');
requireNoText('public/index.html', "const res = await request('/online-data/fetch-ctrip-cookie-api', {", 'Ctrip Cookie API capture request flow is not re-inlined');
requireNoText('public/index.html', 'const ctripProfileFieldRecheckSections = (fields = []) => {', 'Ctrip Profile recheck section builder is not re-inlined');
requireNoText('public/index.html', 'const canRecapture = Boolean(selectedCtripHotelId.value || autoFetchHotelId.value || user.value?.hotel_id);', 'Ctrip Profile recheck recapture guard is not re-inlined');
requireNoText('public/index.html', 'body: JSON.stringify({\n                            sections,', 'Ctrip Profile recheck request options are not re-inlined');
requireNoText('public/index.html', 'const captureRes = await runCtripBrowserCapture({', 'Ctrip Profile recheck browser capture flow is not re-inlined');
requireNoText('public/index.html', "const res = await request('/online-data/recheck-ctrip-profile-mismatched-fields', requestOptions);", 'Ctrip Profile recheck request flow is not re-inlined');
requireNoText('public/index.html', 'const recheckResult = buildCtripProfileRecheckSuccessResult({', 'Ctrip Profile recheck success handling is not re-inlined');
requireNoText('public/index.html', 'const recheckResult = buildCtripProfileRecheckErrorResult({', 'Ctrip Profile recheck error handling is not re-inlined');
requireNoText('public/index.html', 'buildCtripProfileRecheckInterruptedState({', 'Ctrip Profile recheck interrupted handling is not re-inlined');
requireNoText('public/index.html', 'if (!ctripConfigForm.value.name) {', 'Ctrip config save validation is not re-inlined');
requireNoText('public/index.html', 'id: ctripConfigForm.value.id,', 'Ctrip config save payload is not re-inlined');
requireNoText('public/index.html', "console.error('携程配置保存失败:', res?.message || res?.msg || '接口返回异常');", 'Ctrip config save failed response handling is not re-inlined');
requireNoText('public/index.html', "const errData = await e.response.json();", 'Ctrip config save response error parsing is not re-inlined');
requireNoText('public/index.html', 'const batchInput = validateMeituanBatchFetchInput({', 'Meituan batch fetch validation flow is not re-inlined');
requireNoText('public/index.html', 'const fetchTasks = buildMeituanBatchFetchTasks({', 'Meituan batch fetch task flow is not re-inlined');
requireNoText('public/index.html', 'results.push(buildMeituanBatchFetchResultEntry(task, res));', 'Meituan batch fetch result flow is not re-inlined');
requireNoText('public/index.html', 'body: JSON.stringify(buildMeituanDisplayModelPayload({ results, form: meituanForm.value }))', 'Meituan display model payload flow is not re-inlined');
requireNoText('public/index.html', "meituanTrafficForm.value.url = (meituanTrafficForm.value.url || '').trim();", 'Meituan traffic URL trim is not re-inlined');
requireNoText('public/index.html', "const res = await request('/online-data/fetch-meituan-traffic', {", 'Meituan traffic request flow is not re-inlined');
requireNoText('public/index.html', 'latestTrafficData.value = res.data.data;', 'Meituan traffic success result write is not re-inlined');
requireNoText('public/index.html', '获取成功！已保存 ${savedCount} 条流量数据', 'Meituan traffic success toast is not re-inlined');
requireNoText('public/index.html', "const res = await request('/online-data/fetch-meituan-orders', {", 'Meituan order request flow is not re-inlined');
requireNoText('public/index.html', "form.url.includes('/order-eb/index.html')", 'Meituan order page URL guard is not re-inlined');
requireNoText('public/index.html', 'meituanOrderResult.value = res.data || {};', 'Meituan order success result write is not re-inlined');
requireNoText('public/index.html', '订单数据获取成功，已入库 ${savedCount} 条', 'Meituan order success toast is not re-inlined');
requireNoText('public/index.html', "const res = await request('/online-data/fetch-meituan-ads', {", 'Meituan ads request flow is not re-inlined');
requireNoText('public/index.html', "form.url.includes('/shopdiy/account/pcCpcEntry')", 'Meituan ads page URL guard is not re-inlined');
requireNoText('public/index.html', 'meituanAdsResult.value = res.data || {};', 'Meituan ads success result write is not re-inlined');
requireNoText('public/index.html', '广告数据获取成功，已入库 ${savedCount} 条', 'Meituan ads success toast is not re-inlined');
requireNoText('public/index.html', 'const prefix = captureSucceeded', 'Ctrip Profile recheck result message is not re-inlined');
requireNoText('public/index.html', "message: '重抓流程已结束，但字段列表在执行中被刷新；请查看当前获取值状态或再次重抓。'", 'Ctrip Profile recheck interrupted state is not re-inlined');
requireNoText('public/index.html', 'const allRankTypes = [', 'Meituan batch rank type list is not re-inlined');
requireNoText('public/index.html', 'const rankTypeNames = {', 'Meituan batch rank labels are not re-inlined');
requireNoText('public/index.html', 'const missingResourceFields = [];', 'Meituan batch fetch input validation is not re-inlined');
requireNoText('public/index.html', "meituanForm.value.dateRanges.includes('custom')", 'Meituan batch custom-date validation is not re-inlined');
requireNoText('public/index.html', 'display_hotels: results.flatMap', 'Meituan display model payload is not re-inlined');
requireNoText('public/index.html', 'const systemHotelId = meituanForm.value.hotelId || autoFetchHotelId.value || user.value?.hotel_id || null;', 'Meituan browser capture target context is not re-inlined');
requireNoText('public/index.html', "const res = await request('/online-data/capture-meituan-browser', {", 'Meituan browser capture request flow is not re-inlined');
requireNoText('public/index.html', 'login_only: loginOnly,', 'Meituan browser capture login-only payload is not re-inlined');
requireNoText('public/index.html', 'meituanBrowserCaptureResult.value = e?.data?.data || { error: e.message };', 'Meituan browser capture exception result is not re-inlined');
requireNoText('public/index.html', 'const rawJson = String(meituanBrowserCaptureForm.value.payloadJson || \'\').trim();', 'Meituan captured payload JSON trimming is not re-inlined');
requireNoText('public/index.html', 'payload = JSON.parse(rawJson);', 'Meituan captured payload JSON parsing is not re-inlined');
requireNoText('public/index.html', 'payload.store_id = payload.store_id || storeId || poiId;', 'Meituan captured payload enrichment is not re-inlined');
requireNoText('public/index.html', "const res = await request('/online-data/save-meituan-captured-data', {", 'Meituan captured payload save request flow is not re-inlined');
requireNoText('public/index.html', "const body = { system_hotel_id: hotelId, data_period: 'realtime_snapshot'", 'auto-fetch trigger request body is not re-inlined');
requireNoText('public/index.html', '已提交后端执行。${autoFetchCtripExecutionText.value}', 'auto-fetch trigger start state is not re-inlined');
requireNoText('public/index.html', 'await openCtripProfileFieldsForReview();', 'auto-fetch trigger success refresh flow is not re-inlined');
requireNoText('public/index.html', 'const getDefaultDataConfigForm = () => ({', 'data config default form is not re-inlined');
requireNoText('public/index.html', 'const getDataConfigTypeDefaults = (type) => ({', 'data config type defaults are not re-inlined');
requireNoText('public/index.html', "system_description: '授权OTA数据驱动的经营诊断、AI建议与动作复盘系统'", 'system config defaults are not re-inlined');
requireNoText('public/index.html', 'const rows = [...globalNotificationBackendItems.value];', 'global notification row aggregation is not re-inlined');
requireNoText('public/index.html', 'autoFetchRecentRuns.value.slice(0, 3).forEach', 'global notification recent-run loop is not re-inlined');
requireNoText('public/index.html', 'const readSet = new Set(globalNotificationReadIds.value);', 'global notification read-set mapping is not re-inlined');
requireText('public/index.html', 'history-strategy-reuse', 'strategy history reuse button has stable selector');
requireText('public/index.html', 'history-simulation-reuse', 'simulation history reuse button has stable selector');
requireText('public/index.html', 'history-expansion-reuse', 'expansion history reuse button has stable selector');
requireText('public/index.html', 'history-transfer-reuse', 'transfer history reuse button has stable selector');
requireText('public/index.html', 'field-strategy-city', 'strategy city field has stable selector');
requireNoText('public/index.html', 'aiNumber(', 'strategy payload uses the defined numeric helper');
requireText('public/index.html', 'field-simulation-adr', 'simulation ADR field has stable selector');
requireText('public/index.html', 'field-market-business-area', 'market business area field has stable selector');
requireText('public/index.html', 'field-transfer-pricing-', 'transfer pricing fields have stable selectors');
requireText('public/index.html', "requireSimulationStatic('buildTransferDecisionLayerRows')", 'entry uses extracted transfer decision layer builder');
requireText('public/simulation-static.js', 'const buildTransferDecisionLayerRows', 'simulation static builds transfer decision layer rows');
requireText('public/index.html', "requireSimulationStatic('buildTransferPricingPayload')", 'entry uses extracted transfer pricing payload builder');
requireText('public/index.html', "requireSimulationStatic('buildTransferTimingPayload')", 'entry uses extracted transfer timing payload builder');
requireText('public/index.html', "requireSimulationStatic('buildTransferDashboardPayload')", 'entry uses extracted transfer dashboard payload builder');
requireText('public/index.html', "requireSimulationStatic('applyDefinedFields')", 'entry uses extracted defined-field merge helper');
requireText('public/index.html', "requireSimulationStatic('buildBenchmarkModelDetailCards')", 'entry uses extracted benchmark model detail cards builder');
requireText('public/index.html', "requireSimulationStatic('benchmarkModelDetailCompletenessText')", 'entry uses extracted benchmark model detail completeness text');
requireText('public/index.html', "requireSimulationStatic('benchmarkModelEstimatedFields')", 'entry uses extracted benchmark model estimated fields reader');
requireText('public/index.html', "requireSimulationStatic('buildTransferPricingCards')", 'entry uses extracted transfer pricing cards builder');
requireText('public/index.html', "requireSimulationStatic('buildTransferPricingValuationRows')", 'entry uses extracted transfer pricing valuation rows builder');
requireText('public/index.html', "requireSimulationStatic('transferPricingAiEvaluationSourceLabel')", 'entry uses extracted transfer pricing AI source label');
requireText('public/index.html', "requireSimulationStatic('resolveTransferCurrentReadiness')", 'entry uses extracted transfer readiness resolver');
requireText('public/index.html', "requireSimulationStatic('expansionRecordTypeForPage')", 'entry uses extracted expansion record type resolver');
requireText('public/index.html', "requireSimulationStatic('filterExpansionRecords')", 'entry uses extracted expansion record filter');
requireText('public/index.html', "requireSimulationStatic('hasExpansionRecordType')", 'entry uses extracted expansion record type presence check');
requireText('public/index.html', "requireSimulationStatic('hasAnyExpansionRecord')", 'entry uses extracted expansion history presence check');
requireText('public/index.html', "requireSimulationStatic('buildSimulationMetricCards')", 'entry uses extracted simulation metric cards builder');
requireText('public/simulation-static.js', 'const buildTransferPricingPayload', 'simulation static builds transfer pricing payloads');
requireText('public/simulation-static.js', 'const buildTransferTimingPayload', 'simulation static builds transfer timing payloads');
requireText('public/simulation-static.js', 'const buildTransferDashboardPayload', 'simulation static builds transfer dashboard payloads');
requireText('public/simulation-static.js', 'const applyDefinedFields', 'simulation static merges defined transfer fields');
requireText('public/simulation-static.js', 'function buildBenchmarkModelDetailCards', 'simulation static builds benchmark model detail cards');
requireText('public/simulation-static.js', 'function benchmarkModelDetailCompletenessText', 'simulation static builds benchmark model detail completeness text');
requireText('public/simulation-static.js', 'function benchmarkModelEstimatedFields', 'simulation static reads benchmark model estimated fields');
requireText('public/simulation-static.js', 'function buildTransferPricingCards', 'simulation static builds transfer pricing cards');
requireText('public/simulation-static.js', 'function buildTransferPricingValuationRows', 'simulation static builds transfer pricing valuation rows');
requireText('public/simulation-static.js', 'function transferPricingAiEvaluationSourceLabel', 'simulation static builds transfer pricing AI source label');
requireText('public/simulation-static.js', 'function resolveTransferCurrentReadiness', 'simulation static owns transfer readiness resolver');
requireText('public/simulation-static.js', 'function expansionRecordTypeForPage', 'simulation static owns expansion record type resolver');
requireText('public/simulation-static.js', 'function filterExpansionRecords', 'simulation static owns expansion record filter');
requireText('public/simulation-static.js', 'function hasExpansionRecordType', 'simulation static owns expansion record type presence check');
requireText('public/simulation-static.js', 'function hasAnyExpansionRecord', 'simulation static owns expansion history presence check');
requireText('public/simulation-static.js', 'function buildSimulationMetricCards', 'simulation static builds simulation metric cards');
requireText('public/index.html', "requireExpansionStaticOption('buildStrategyPayload')", 'entry uses extracted strategy payload builder');
requireText('public/expansion-static-options.js', 'const buildStrategyPayload', 'expansion static builds strategy payloads');
requireText('public/index.html', "requireExpansionStaticOption('buildFeasibilityPayload')", 'entry uses extracted feasibility payload builder');
requireText('public/expansion-static-options.js', 'const buildFeasibilityPayload', 'expansion static builds feasibility payloads');
requireText('public/index.html', "requireExpansionStaticOption('marketEvaluationRiskSeverityClass')", 'entry uses extracted market evaluation risk severity class');
requireText('public/index.html', "requireExpansionStaticOption('formatMarketEvaluationScoreChange')", 'entry uses extracted market evaluation score change formatter');
requireText('public/index.html', "requireExpansionStaticOption('marketEvaluationScoreChangeClass')", 'entry uses extracted market evaluation score change class');
requireText('public/index.html', "requireExpansionStaticOption('marketEvaluationCityOptionsForTier')", 'entry uses extracted market evaluation city tier filter');
requireText('public/index.html', "requireExpansionStaticOption('secondaryMarketEvaluationCustomerOptions')", 'entry uses extracted secondary market customer filter');
requireText('public/index.html', "requireExpansionStaticOption('buildMarketEvaluationAiJudgementRows')", 'entry uses extracted market evaluation AI judgement rows builder');
requireText('public/index.html', "requireExpansionStaticOption('buildMarketEvaluationAiRecommendations')", 'entry uses extracted market evaluation AI recommendations builder');
requireText('public/index.html', "requireExpansionStaticOption('buildMarketEvaluationAiAssumptions')", 'entry uses extracted market evaluation AI assumptions builder');
requireText('public/index.html', "requireExpansionStaticOption('buildMarketEvaluationScoreFormula')", 'entry uses extracted market evaluation score formula builder');
requireText('public/index.html', "requireExpansionStaticOption('buildMarketEvaluationScoreBreakdown')", 'entry uses extracted market evaluation score breakdown builder');
requireText('public/index.html', "requireExpansionStaticOption('buildMarketEvaluationScorePercent')", 'entry uses extracted market evaluation score percent builder');
requireText('public/index.html', "requireExpansionStaticOption('buildMarketEvaluationAiRiskNote')", 'entry uses extracted market evaluation AI risk note builder');
requireText('public/index.html', "requireExpansionStaticOption('benchmarkModelAiSourceLabelForResult')", 'entry uses extracted benchmark model AI source label builder');
requireText('public/index.html', "requireExpansionStaticOption('buildBenchmarkModelAiRecommendations')", 'entry uses extracted benchmark model AI recommendations builder');
requireText('public/index.html', "requireExpansionStaticOption('buildBenchmarkModelAiWatchPoints')", 'entry uses extracted benchmark model AI watch points builder');
requireText('public/index.html', "requireExpansionStaticOption('buildBenchmarkModelAiAssumptionNote')", 'entry uses extracted benchmark model AI assumption note builder');
requireText('public/index.html', "requireExpansionStaticOption('benchmarkModelDataNoticeForResult')", 'entry uses extracted benchmark model data notice builder');
requireText('public/index.html', "requireExpansionStaticOption('buildBenchmarkModelAiOutcomeCards')", 'entry uses extracted benchmark model AI outcome cards builder');
requireText('public/index.html', "requireExpansionStaticOption('resolveExpansionCurrentReadiness')", 'entry uses extracted expansion readiness resolver');
requireText('public/expansion-static-options.js', 'const marketEvaluationRiskSeverityClass', 'expansion static owns market evaluation risk severity class');
requireText('public/expansion-static-options.js', 'const formatMarketEvaluationScoreChange', 'expansion static owns market evaluation score change formatter');
requireText('public/expansion-static-options.js', 'const marketEvaluationScoreChangeClass', 'expansion static owns market evaluation score change class');
requireText('public/expansion-static-options.js', 'const marketEvaluationCityOptionsForTier', 'expansion static owns market evaluation city tier filter');
requireText('public/expansion-static-options.js', 'const secondaryMarketEvaluationCustomerOptions', 'expansion static owns secondary market customer filter');
requireText('public/expansion-static-options.js', 'const normalizeAiRecommendationDisplay', 'expansion static owns AI recommendation display normalizer');
requireText('public/expansion-static-options.js', 'const buildMarketEvaluationAiJudgementRows', 'expansion static owns market evaluation AI judgement rows builder');
requireText('public/expansion-static-options.js', 'const buildMarketEvaluationAiRecommendations', 'expansion static owns market evaluation AI recommendations builder');
requireText('public/expansion-static-options.js', 'const buildMarketEvaluationAiAssumptions', 'expansion static owns market evaluation AI assumptions builder');
requireText('public/expansion-static-options.js', 'const buildMarketEvaluationScoreFormula', 'expansion static owns market evaluation score formula builder');
requireText('public/expansion-static-options.js', 'const buildMarketEvaluationScoreBreakdown', 'expansion static owns market evaluation score breakdown builder');
requireText('public/expansion-static-options.js', 'const buildMarketEvaluationScorePercent', 'expansion static owns market evaluation score percent builder');
requireText('public/expansion-static-options.js', 'const buildMarketEvaluationAiRiskNote', 'expansion static owns market evaluation AI risk note builder');
requireText('public/expansion-static-options.js', 'const benchmarkModelAiSourceLabelForResult', 'expansion static owns benchmark model AI source label builder');
requireText('public/expansion-static-options.js', 'const buildBenchmarkModelAiRecommendations', 'expansion static owns benchmark model AI recommendations builder');
requireText('public/expansion-static-options.js', 'const buildBenchmarkModelAiWatchPoints', 'expansion static owns benchmark model AI watch points builder');
requireText('public/expansion-static-options.js', 'const buildBenchmarkModelAiAssumptionNote', 'expansion static owns benchmark model AI assumption note builder');
requireText('public/expansion-static-options.js', 'const benchmarkModelDataNoticeForResult', 'expansion static owns benchmark model data notice builder');
requireText('public/expansion-static-options.js', 'const buildBenchmarkModelAiOutcomeCards', 'expansion static owns benchmark model AI outcome cards builder');
requireText('public/expansion-static-options.js', 'function resolveExpansionCurrentReadiness', 'expansion static owns expansion readiness resolver');
requireNoText('public/index.html', 'const pricingReady = !!transferPricingResult.value;', 'transfer decision pricing ready state is not re-inlined');
requireNoText('public/index.html', "label: '事实数据',\n                        status: snapshot ? '有快照' : '待取数'", 'transfer decision fact row is not re-inlined');
requireNoText('public/index.html', "evidence: `定价 ${pricingReady ? '有' : '无'} / 时机 ${timingReady ? '有' : '无'}`", 'transfer decision calculation evidence is not re-inlined');
requireTextInFiles(['public/index.html', 'public/ota-diagnosis-static.js'], 'result.diagnosis_sections', 'OTA diagnosis UI renders backend-provided diagnosis sections');
requireNoText('public/index.html', '<script src="ota-diagnosis-static.js', 'frontend lazy-loads extracted OTA diagnosis static helper');
requireText('public/index.html', "const otaDiagnosisStaticScript = 'ota-diagnosis-static.js", 'entry keeps OTA diagnosis static lazy script path');
requireText('public/index.html', 'ota-diagnosis-static.js?v=20260627-decision-closure-v2', 'entry loads OTA diagnosis decision-closure static bundle version');
requireText('public/index.html', '业务闭环拆解', 'OTA diagnosis page exposes business loop breakdown');
requireText('public/index.html', '建议动作与阻断状态', 'OTA diagnosis page exposes action readiness and blocked states');
requireText('public/index.html', '缺口未补齐前，不进入可执行建议', 'OTA diagnosis page keeps evidence gaps separate from executable actions');
requireText('public/index.html', 'const ensureOtaDiagnosisStaticReady = async () =>', 'entry keeps OTA diagnosis static ready guard');
requireText('public/index.html', "requireOtaDiagnosisStatic('runOtaDiagnosisHotelFetchFlow')", 'entry uses extracted OTA diagnosis fetch flow runner');
requireText('public/index.html', "requireOtaDiagnosisStatic('runOtaDiagnosisGenerateFlow')", 'entry uses extracted OTA diagnosis generate flow runner');
requireText('public/index.html', "runPageLoadOnce(newPage, 'ota-diagnosis-static'", 'agent center prewarms OTA diagnosis helper after entering the AI toolbox');
requireText('public/ota-diagnosis-static.js', 'const buildOtaDiagnosisFetchContext', 'OTA diagnosis static builds fetch context');
requireText('public/ota-diagnosis-static.js', 'const buildOtaDiagnosisFetchTasks', 'OTA diagnosis static builds fetch tasks');
requireText('public/ota-diagnosis-static.js', 'const runOtaDiagnosisHotelFetchFlow', 'OTA diagnosis static runs fetch flow');
requireText('public/ota-diagnosis-static.js', 'const buildOtaDiagnosisGenerateRequestBody', 'OTA diagnosis static builds generate request bodies');
requireText('public/ota-diagnosis-static.js', 'const runOtaDiagnosisGenerateFlow', 'OTA diagnosis static runs generate flow');
requireText('public/ota-diagnosis-static.js', 'const buildOtaDiagnosisDecisionClosureCards', 'OTA diagnosis static builds decision closure cards');
requireText('public/ota-diagnosis-static.js', 'const buildOtaDiagnosisBusinessLoopSteps', 'OTA diagnosis static builds business loop steps');
requireText('public/ota-diagnosis-static.js', 'const buildOtaDiagnosisActionRows', 'OTA diagnosis static builds action readiness rows');
requireText('public/ota-diagnosis-static.js', 'const buildOtaDiagnosisDataGapRows', 'OTA diagnosis static builds evidence gap rows');
requireNoText('public/index.html', '<script src="ai-analysis-static.js"></script>', 'frontend lazy-loads extracted AI analysis static helper');
requireText('public/index.html', "const aiAnalysisStaticScript = 'ai-analysis-static.js'", 'entry keeps AI analysis static lazy script path');
requireText('public/index.html', 'const ensureAiAnalysisStaticReady = async () =>', 'entry keeps AI analysis static ready guard');
requireText('public/index.html', "if (tab === 'ai')", 'download center AI tab is the OTA AI static loading boundary');
requireText('public/index.html', "runPageLoadOnce(currentPage.value || 'online-data', 'ai-analysis-static'", 'online analysis tab lazy-loads AI analysis static helper');
requireText('public/index.html', 'await ensureAiAnalysisStaticReady();', 'entry gates AI analysis actions on static helper readiness');
requireText('public/index.html', "requireAiAnalysisStatic('buildCapturedOtaSummaryRequestBody')", 'entry uses extracted AI analysis summary request builder');
requireText('public/index.html', "requireAiAnalysisStatic('buildCapturedOtaAnalysisStartContext')", 'entry uses extracted AI analysis start context builder');
requireText('public/index.html', "requireAiAnalysisStatic('buildCapturedOtaAnalysisRunContext')", 'entry uses extracted AI analysis run context builder');
requireText('public/index.html', "requireAiAnalysisStatic('buildCapturedOtaSummaryResponseResult')", 'entry uses extracted AI analysis summary response builder');
requireText('public/index.html', "requireAiAnalysisStatic('runCapturedOtaAnalysisExecution')", 'entry uses extracted captured OTA AI analysis execution runner');
requireText('public/index.html', "requireAiAnalysisStatic('runCapturedOtaAnalysisStartFlow')", 'entry uses extracted captured OTA AI analysis start flow runner');
requireText('public/index.html', "requireAiAnalysisStatic('buildCtripAiAnalysisHotelSelection')", 'entry uses extracted Ctrip AI analysis hotel selection builder');
requireText('public/index.html', "requireAiAnalysisStatic('sanitizeAiReportHtml')", 'entry uses extracted AI report sanitizer');
requireText('public/index.html', "requireAiAnalysisStatic('aiReportHtmlToText')", 'entry uses extracted AI report text converter');
requireText('public/index.html', "requireAiAnalysisStatic('buildMeituanAiAnalysisHotelList')", 'entry uses extracted Meituan AI hotel list builder');
requireText('public/index.html', "requireAiAnalysisStatic('resolveMeituanAiSelectedData')", 'entry uses extracted Meituan AI selection resolver');
requireText('public/index.html', "requireAiAnalysisStatic('buildMeituanAiAnalysisRequestBody')", 'entry uses extracted Meituan AI request builder');
requireText('public/index.html', "requireAiAnalysisStatic('buildMeituanAiAnalysisHistoryRecord')", 'entry uses extracted Meituan AI history builder');
requireText('public/index.html', "requireAiAnalysisStatic('runMeituanAiAnalysisFlow')", 'entry uses extracted Meituan AI analysis flow runner');
requireText('public/ai-analysis-static.js', 'const buildCapturedOtaHotelPayload', 'AI analysis static builds captured OTA hotel payloads');
requireText('public/ai-analysis-static.js', 'const buildCtripAiAnalysisHotelSelection', 'AI analysis static builds Ctrip hotel selections');
requireText('public/ai-analysis-static.js', 'const buildCapturedOtaAnalysisRunPlan', 'AI analysis static builds captured OTA run plans');
requireText('public/ai-analysis-static.js', 'const buildCapturedOtaAnalysisStartContext', 'AI analysis static builds captured OTA start context');
requireText('public/ai-analysis-static.js', 'const buildCapturedOtaAnalysisRunContext', 'AI analysis static builds captured OTA run context');
requireText('public/ai-analysis-static.js', 'const buildCapturedOtaGroupOutcome', 'AI analysis static builds captured OTA group outcomes');
requireText('public/ai-analysis-static.js', 'const applyCapturedOtaGroupRunState', 'AI analysis static applies captured OTA group state updates');
requireText('public/ai-analysis-static.js', 'const buildCapturedOtaSummaryRequestBody', 'AI analysis static builds captured OTA summary requests');
requireText('public/ai-analysis-static.js', 'const buildCapturedOtaSummaryContext', 'AI analysis static builds captured OTA summary context');
requireText('public/ai-analysis-static.js', 'const buildCapturedOtaSummaryResponseResult', 'AI analysis static builds captured OTA summary response results');
requireText('public/ai-analysis-static.js', 'const buildCapturedFallbackSummaryReport', 'AI analysis static builds fallback summary reports');
requireText('public/ai-analysis-static.js', 'const runCapturedOtaAnalysisExecution', 'AI analysis static runs captured OTA analysis execution');
requireText('public/ai-analysis-static.js', 'const runCapturedOtaAnalysisStartFlow', 'AI analysis static runs captured OTA analysis start flow');
requireText('public/ai-analysis-static.js', 'const resolveAiSelectedData', 'AI analysis static resolves selected hotel rows');
requireText('public/ai-analysis-static.js', 'const validateCapturedOtaAiAnalysisStart', 'AI analysis static validates analysis start inputs');
requireText('public/ai-analysis-static.js', 'const buildCapturedOtaAnalysisCompletion', 'AI analysis static builds captured OTA completion state');
requireText('public/ai-analysis-static.js', 'const sanitizeAiReportHtml', 'AI analysis static sanitizes report HTML');
requireText('public/ai-analysis-static.js', 'const aiReportHtmlToText', 'AI analysis static converts report HTML to text');
requireText('public/ai-analysis-static.js', 'const buildMeituanAiAnalysisHotelList', 'AI analysis static builds Meituan hotel selections');
requireText('public/ai-analysis-static.js', 'const resolveMeituanAiSelectedData', 'AI analysis static resolves Meituan selected hotels');
requireText('public/ai-analysis-static.js', 'const buildMeituanAiAnalysisRequestBody', 'AI analysis static builds Meituan AI request bodies');
requireText('public/ai-analysis-static.js', 'const buildMeituanAiAnalysisHistoryRecord', 'AI analysis static builds Meituan AI history records');
requireText('public/ai-analysis-static.js', 'const validateMeituanAiAnalysisStart', 'AI analysis static validates Meituan AI analysis start');
requireText('public/ai-analysis-static.js', 'const runMeituanAiAnalysisFlow', 'AI analysis static runs Meituan AI analysis flow');
requireNoText('public/index.html', 'const pushOtaDiagnosisFetchTask = (tasks, task) => {', 'OTA diagnosis task push helper is not re-inlined');
requireNoText('public/index.html', 'const fetchContext = buildOtaDiagnosisFetchContext({', 'OTA diagnosis fetch context construction is not re-inlined');
requireNoText('public/index.html', 'tasks.push(...buildOtaDiagnosisFetchTasks({', 'OTA diagnosis fetch task construction is not re-inlined');
requireNoText('public/index.html', 'const genericCtripCookie = String(fetchContext.ctripCookieApiCookies || \'\').trim()', 'OTA diagnosis generic Ctrip Cookie selection is not re-inlined');
requireNoText('public/index.html', 'let useCtripCorePresetForDiagnosis = false;', 'OTA diagnosis core preset decision is not re-inlined');
requireNoText('public/index.html', 'const success = results.filter(item => item.success).length;', 'OTA diagnosis fetch result summary is not re-inlined');
requireNoText('public/index.html', "['P_RZ', 'P_XS', 'P_ZH', 'P_LL'].forEach(rankType => {", 'OTA diagnosis Meituan task list is not re-inlined');
requireNoText('public/index.html', "const res = await request('/agent/ota-diagnosis', {", 'OTA diagnosis generate request flow is not re-inlined');
requireNoText('public/index.html', "const conclusion = String(data.diagnosis?.summary || data.core_conclusion || '');", 'OTA diagnosis empty-result detection is not re-inlined');
requireNoText('public/index.html', 'OTA数据同步完成，${fetchSummary.failed} 项失败', 'OTA diagnosis fetch failure warning is not re-inlined');
requireNoText('public/index.html', 'hotel_id: diagnosisHotelId || 0,', 'OTA diagnosis generate request body is not re-inlined');
requireNoText('public/index.html', 'const aiAnalysisStatusText = (status) => {', 'AI analysis status text helper is not re-inlined');
requireNoText('public/index.html', 'const chunkArray = (items, size) => {', 'AI analysis chunk helper is not re-inlined');
requireNoText('public/index.html', 'const buildCapturedOtaHotelPayload = (hotel) => {', 'AI analysis captured payload builder is not re-inlined');
requireNoText('public/index.html', "const key = (h.hotelId || h.id) + '_' + (h.hotelName || h.name);", 'Ctrip AI analysis hotel selection is not re-inlined');
requireNoText('public/index.html', "const key = h.poiId + '_' + h.hotelName;", 'Meituan AI analysis hotel key building is not re-inlined');
requireNoText('public/index.html', 'existing.amountRank = existing.amountRank === 0 ?', 'Ctrip AI analysis rank merge is not re-inlined');
requireNoText('public/index.html', 'const hotelsPayload = selectedData.map(buildCapturedOtaHotelPayload)', 'AI analysis run plan is not re-inlined');
requireNoText('public/index.html', 'const groupSize = isDeepSeekProAnalysisModel() ? 3 : 5;', 'AI analysis group sizing is not re-inlined');
requireNoText('public/index.html', 'const selectedData = resolveAiSelectedData(aiSelectedHotels.value, aiAnalysisHotelList.value);', 'AI analysis selected data resolution is not re-inlined');
requireNoText('public/index.html', 'const startValidation = validateCapturedOtaAiAnalysisStart({', 'AI analysis start validation context is not re-inlined');
requireNoText('public/index.html', 'const runPlan = buildCapturedOtaAnalysisRunPlan({', 'AI analysis run plan context is not re-inlined');
requireNoText('public/index.html', 'aiSelectedHotels.value.map(key => {', 'AI selected hotel lookup is not re-inlined');
requireNoText('public/index.html', 'if (aiSelectedHotels.value.length === 0) {', 'AI selected hotel start validation is not re-inlined');
requireNoText('public/index.html', 'if (!onlineDataFilter.value.start_date || !onlineDataFilter.value.end_date) {', 'AI date range start validation is not re-inlined');
requireNoText('public/index.html', 'if (onlineDataFilter.value.start_date > onlineDataFilter.value.end_date) {', 'AI date order start validation is not re-inlined');
requireNoText('public/index.html', 'aiAnalysisHistory.value.unshift(buildAiAnalysisHistoryRecord({', 'AI analysis completion history is not re-inlined');
requireNoText('public/index.html', 'if (aiAnalysisHistory.value.length > 10) {', 'AI analysis history trim is not re-inlined');
requireNoText('public/index.html', "item.status === 'success' && item.result", 'AI group success filtering is not re-inlined');
requireNoText('public/index.html', "item.status === 'failed' || item.error", 'AI group failure filtering is not re-inlined');
requireNoText('public/index.html', 'failedGroups.map(item => `第 ${item.group_index} 组：', 'AI group failure reason is not re-inlined');
requireNoText('public/index.html', 'groupState.result = result.result;', 'AI group success result update is not re-inlined');
requireNoText('public/index.html', 'aiAnalysisProgress.value.completedHotels += group.length;', 'AI group success count update is not re-inlined');
requireNoText('public/index.html', 'groupState.error = result.error;', 'AI group failure state update is not re-inlined');
requireNoText('public/index.html', 'aiAnalysisProgress.value.completedHotels += retryResult.successCount;', 'AI retry completed count update is not re-inlined');
requireNoText('public/index.html', 'for (let index = 0; index < groups.length; index++) {', 'AI captured OTA group execution loop is not re-inlined');
requireNoText('public/index.html', 'if (summaryRes.code === 200) {', 'AI summary success response handling is not re-inlined');
requireNoText('public/index.html', 'const summaryData = summaryRes.data || {};', 'AI summary data extraction is not re-inlined');
requireNoText('public/index.html', "reason: summaryRes.message || '汇总失败'", 'AI summary fallback response handling is not re-inlined');
requireNoText('public/index.html', 'selectedCount: hotelsPayload.length,', 'AI summary selected count context is not re-inlined');
requireNoText('public/index.html', 'groupCount: aiAnalysisBatchResults.value.length,', 'AI summary group count context is not re-inlined');
requireNoText('public/index.html', 'completedHotels: aiAnalysisProgress.value.completedHotels,', 'AI summary completed count context is not re-inlined');
requireNoText('public/index.html', 'const buildCapturedOtaSummaryRequestBody = ({', 'AI analysis summary request builder is not re-inlined');
requireNoText('public/index.html', 'if (meituanAiSelectedHotels.value.length === 0) {', 'Meituan AI analysis selection guard is not re-inlined');
requireNoText('public/index.html', 'const selectedData = resolveMeituanAiSelectedData(meituanAiSelectedHotels.value, meituanAiAnalysisHotelList.value);', 'Meituan AI selected data resolution is not re-inlined');
requireNoText('public/index.html', 'const analysisData = buildMeituanAiAnalysisRequestBody(selectedData);', 'Meituan AI request body construction is not re-inlined');
requireNoText('public/index.html', "const res = await request('/online-data/ai-analysis', {", 'Meituan AI request flow is not re-inlined');
requireNoText('public/index.html', 'meituanAiAnalysisHistory.value.unshift(buildMeituanAiAnalysisHistoryRecord({', 'Meituan AI history construction is not re-inlined');
requireNoText('public/index.html', 'meituanAiAnalysisHistory.value = meituanAiAnalysisHistory.value.slice(0, 10);', 'Meituan AI history trimming is not re-inlined');
requireNoText('public/index.html', "console.error('美团AI分析请求失败:', e);", 'Meituan AI exception logging is not re-inlined');
requireNoText('public/index.html', 'total_hotels: selectedData.length,', 'Meituan AI analysis request body is not re-inlined');
requireNoText('public/index.html', 'selectedData.slice(0, 3).map(h => h.hotelName)', 'Meituan AI analysis history naming is not re-inlined');
requireNoText('public/index.html', 'const buildCapturedFallbackSummaryReport = ({', 'AI analysis fallback summary builder is not re-inlined');
requireNoText('public/index.html', 'const sanitizeAiReportHtml = (value) => {', 'AI report sanitizer is not re-inlined');
requireNoText('public/index.html', 'const aiReportHtmlToText = (value) => {', 'AI report text converter is not re-inlined');
requireNoText('public/index.html', "title: '点评问题'", 'OTA diagnosis UI does not render the deprecated comment section');
requireNoText('public/index.html', "openDataConfigModal('ctrip-comments')", 'Ctrip comment capture card is not exposed in UI');
requireNoText('public/index.html', "openDataConfigModal('meituan-comments')", 'Meituan comment capture card is not exposed in UI');
requireNoText('public/index.html', '<option value="comment">评价</option>', 'platform data source form does not offer comment data type');
requireNoText('public/index.html', '<option value="review">点评数据</option>', 'online data history filter does not offer review data type');
requireNoText('public/index.html', "title: '点评问题'", 'OTA diagnosis UI does not render the deprecated comment section');
requireTextInFiles(['public/index.html', 'public/revenue-research-static.js'], "key: 'service-quality'", 'revenue research exposes service-quality product instead of review-topic');
requireNoTextInFiles(['public/index.html', 'public/revenue-research-static.js'], "key: 'review-topic'", 'revenue research does not expose review-topic product');
requireText('app/service/RevenueResearchService.php', "'service-quality' =>", 'revenue research backend supports service-quality product');
requireNoText('app/service/RevenueResearchService.php', "'review-topic' =>", 'revenue research backend does not support review-topic product');
requireTextInFiles(['public/index.html', 'public/operation-static.js'], 'service_quality', 'operation dashboard renders service quality data');
requireText('public/operation-static.js', 'buildOperationSourceBrief', 'operation source brief builder lives in operation static module');
requireText('public/operation-static.js', 'buildOperationDecisionCards', 'operation decision card builder lives in operation static module');
requireText('public/operation-static.js', 'operationCanApproveExecution', 'operation approval action guard lives in operation static module');
requireText('public/operation-static.js', 'operationCanExecuteWithEvidence', 'operation evidence action guard lives in operation static module');
requireText('public/operation-static.js', 'operationCanReviewExecution', 'operation review action guard lives in operation static module');
requireText('public/operation-static.js', 'operationExecutionActionAvailable', 'operation execution action availability lives in operation static module');
requireText('public/operation-static.js', 'buildOperationExecutionTraceRows', 'operation execution trace rows builder lives in operation static module');
requireText('public/operation-static.js', 'buildOperationExecutionSummaryCards', 'operation execution summary cards builder lives in operation static module');
requireText('public/operation-static.js', 'operationExecutionBottleneckText', 'operation execution bottleneck text helper lives in operation static module');
requireText('public/operation-static.js', 'operationExecutionMoneyStatusText', 'operation execution money status text helper lives in operation static module');
requireText('public/operation-static.js', 'operationExecutionMoneyStatusClass', 'operation execution money status class helper lives in operation static module');
requireText('public/operation-static.js', 'operationExecutionSourceText', 'operation execution source text helper lives in operation static module');
requireText('public/operation-static.js', 'operationExecutionActionText', 'operation execution action text helper lives in operation static module');
requireText('public/operation-static.js', 'operationExecutionReviewText', 'operation execution review text helper lives in operation static module');
requireText('public/operation-static.js', 'operationExecutionRoiText', 'operation execution ROI text helper lives in operation static module');
requireText('public/operation-static.js', 'buildOperationClosureSummaryBadge', 'operation closure summary badge builder lives in operation static module');
requireText('public/operation-static.js', 'buildOperationClosureSummaryCards', 'operation closure summary cards builder lives in operation static module');
requireText('public/operation-static.js', 'operationClosureGapText', 'operation closure gap text helper lives in operation static module');
requireText('public/operation-static.js', "status || '') === 'blocked_by_p0_ota_gate'", 'operation closure summary badge treats P0 gate as blocking');
requireText('public/operation-static.js', "text: 'P0未就绪'", 'operation closure summary badge names P0 gate blocking state');
requireText('public/system-static.js', "blocked_by_p0_ota_gate: 'bg-red-50 text-red-700 border-red-100'", 'operation closure module status marks P0 gate as blocking');
requireText('public/operation-static.js', "text: '过程已闭环，ROI待补'", 'operation closure badge separates process closure from ROI readiness');
requireText('public/operation-static.js', "label: '过程闭环'", 'operation closure cards expose process closure count');
requireText('public/operation-static.js', "label: 'ROI就绪'", 'operation closure cards expose ROI readiness count');
requireText('public/operation-static.js', 'summary.roi_ready_module_count', 'operation closure cards use ROI-ready module count');
requireText('public/operation-static.js', 'buildOpeningCategoryProgressCards', 'opening category progress cards builder lives in operation static module');
requireText('public/operation-static.js', 'buildOpeningPositioningImpact', 'opening positioning impact builder lives in operation static module');
requireText('public/operation-static.js', 'buildOpeningTaskProgressCards', 'opening task progress cards builder lives in operation static module');
requireText('public/operation-static.js', 'buildOpeningTaskProgressStages', 'opening task progress stages builder lives in operation static module');
requireText('public/operation-static.js', 'buildOpeningStatusFilterChips', 'opening status filter chips builder lives in operation static module');
requireText('public/operation-static.js', 'buildOpeningAttentionFilterChips', 'opening attention filter chips builder lives in operation static module');
requireText('public/operation-static.js', 'buildOpeningTaskStats', 'opening task stats builder lives in operation static module');
requireText('public/operation-static.js', 'filterOpeningTasks', 'opening task filter lives in operation static module');
requireText('public/operation-static.js', 'selectOpeningTasks', 'opening task selection reader lives in operation static module');
requireText('public/operation-static.js', 'areAllFilteredOpeningTasksSelected', 'opening all-selected check lives in operation static module');
requireText('public/operation-static.js', 'pruneOpeningTaskIds', 'opening selected-id pruning lives in operation static module');
requireText('public/operation-static.js', 'mergeOpeningTaskSelection', 'opening selection merge lives in operation static module');
requireText('public/operation-static.js', 'openingTaskDueLabel', 'opening task due label lives in operation static module');
requireText('public/operation-static.js', 'openingTaskDueClass', 'opening task due class lives in operation static module');
requireText('public/operation-static.js', 'openingTaskProgressStage', 'opening task progress stage lives in operation static module');
requireText('public/operation-static.js', 'openingTaskProgressTextClass', 'opening task progress text class lives in operation static module');
requireText('public/operation-static.js', 'syncOpeningTaskProgressByStatus', 'opening task status-progress sync lives in operation static module');
requireText('public/operation-static.js', 'syncOpeningTaskStatusByProgress', 'opening task progress-status sync lives in operation static module');
requireText('public/operation-static.js', 'buildOpeningTaskUpdatePayload', 'opening task update payload builder lives in operation static module');
requireText('public/operation-static.js', 'snapshotOpeningTaskForRollback', 'opening task rollback snapshot builder lives in operation static module');
requireText('public/operation-static.js', 'openingTaskPatchHasChanges', 'opening task patch guard lives in operation static module');
requireText('public/operation-static.js', 'applyOpeningTaskPatch', 'opening task patch applier lives in operation static module');
requireText('public/operation-static.js', 'openingRiskText', 'opening risk text lives in operation static module');
requireText('public/operation-static.js', 'openingRiskTextClass', 'opening risk text class lives in operation static module');
requireText('public/operation-static.js', 'openingRiskClass', 'opening risk badge class lives in operation static module');
requireText('public/operation-static.js', 'buildOpeningProjectFormDefaults', 'opening project default form builder lives in operation static module');
requireText('public/operation-static.js', 'normalizeOpeningProjectFormForSubmit', 'opening project submit normalizer lives in operation static module');
requireText('public/operation-static.js', 'buildOpeningProjectFormFromProject', 'opening project form hydration lives in operation static module');
requireText('public/index.html', 'buildOperationDecisionCards(operationFullData.value || {}, operationDisplayFormatters)', 'operation dashboard uses extracted decision card builder');
requireText('public/index.html', "operationCanApproveExecution = requireOperationStatic(staticConfig, 'operationCanApproveExecution')", 'operation approval action guard uses extracted helper');
requireText('public/index.html', "operationCanExecuteWithEvidence = requireOperationStatic(staticConfig, 'operationCanExecuteWithEvidence')", 'operation evidence action guard uses extracted helper');
requireText('public/index.html', "operationCanReviewExecution = requireOperationStatic(staticConfig, 'operationCanReviewExecution')", 'operation review action guard uses extracted helper');
requireText('public/index.html', "operationExecutionActionAvailable = requireOperationStatic(staticConfig, 'operationExecutionActionAvailable')", 'operation action availability uses extracted helper');
requireText('public/index.html', "buildOperationExecutionTraceRows = requireOperationStatic(staticConfig, 'buildOperationExecutionTraceRows')", 'operation execution trace rows use extracted helper');
requireText('public/index.html', 'buildOperationExecutionTraceRows(operationExecutionFlow.value?.summary || {})', 'operation execution trace rows call extracted builder');
requireText('public/index.html', "buildOperationExecutionSummaryCards = requireOperationStatic(staticConfig, 'buildOperationExecutionSummaryCards')", 'operation execution summary cards use extracted helper');
requireText('public/index.html', "operationExecutionBottleneckTextForSummary = requireOperationStatic(staticConfig, 'operationExecutionBottleneckText')", 'operation execution bottleneck text uses extracted helper');
requireText('public/index.html', "operationExecutionMoneyStatusTextForStatus = requireOperationStatic(staticConfig, 'operationExecutionMoneyStatusText')", 'operation execution money status text uses extracted helper');
requireText('public/index.html', "operationExecutionMoneyStatusClassForStatus = requireOperationStatic(staticConfig, 'operationExecutionMoneyStatusClass')", 'operation execution money status class uses extracted helper');
requireText('public/index.html', "operationExecutionSourceText = requireOperationStatic(staticConfig, 'operationExecutionSourceText')", 'operation execution source text uses extracted helper');
requireText('public/index.html', "operationExecutionActionTextForItem = requireOperationStatic(staticConfig, 'operationExecutionActionText')", 'operation execution action text uses extracted helper');
requireText('public/index.html', "operationExecutionReviewTextForItem = requireOperationStatic(staticConfig, 'operationExecutionReviewText')", 'operation execution review text uses extracted helper');
requireText('public/index.html', "operationExecutionRoiTextForRoi = requireOperationStatic(staticConfig, 'operationExecutionRoiText')", 'operation execution ROI text uses extracted helper');
requireText('public/index.html', 'buildOperationExecutionSummaryCards(operationExecutionFlow.value?.summary || {}, operationDisplayFormatters)', 'operation execution summary cards call extracted builder');
requireText('public/index.html', 'operationExecutionBottleneckTextForSummary(operationExecutionFlow.value?.summary || {}, { statusLabel: operationExecutionStatusLabel })', 'operation execution bottleneck text calls extracted helper');
requireText('public/index.html', 'operationExecutionActionTextForItem(item, { strategyTypeLabel: operationStrategyTypeLabel })', 'operation execution action text calls extracted helper');
requireText('public/index.html', 'operationExecutionReviewTextForItem(item, { statusLabel: operationExecutionStatusLabel })', 'operation execution review text calls extracted helper');
requireText('public/index.html', 'operationExecutionRoiTextForRoi(roi, operationDisplayFormatters)', 'operation execution ROI text calls extracted helper');
requireText('public/index.html', "String(item?.roi?.status || '') === 'ready'", 'operation execution evidence summary only counts ready ROI evidence');
requireText('public/index.html', "buildOperationClosureSummaryBadge = requireOperationStatic(staticConfig, 'buildOperationClosureSummaryBadge')", 'operation closure summary badge uses extracted helper');
requireText('public/index.html', "buildOperationClosureSummaryCards = requireOperationStatic(staticConfig, 'buildOperationClosureSummaryCards')", 'operation closure summary cards use extracted helper');
requireText('public/index.html', "operationClosureGapText = requireOperationStatic(staticConfig, 'operationClosureGapText')", 'operation closure gap text uses extracted helper');
requireText('public/index.html', 'buildOperationClosureSummaryBadge(operationClosureOverview.value?.summary || {})', 'operation closure summary badge calls extracted builder');
requireText('public/index.html', 'buildOperationClosureSummaryCards(operationClosureOverview.value?.summary || {})', 'operation closure summary cards call extracted builder');
requireText('public/index.html', '{{ module.reviewed_count || 0 }}', 'operation closure module card exposes review count separately from ROI count');
requireText('public/index.html', '{{ module.roi_ready_count || 0 }}', 'operation closure module card exposes ROI-ready count');
requireText('public/index.html', 'buildOpeningCategoryProgressCards(openingOverview.value?.category_progress || [])', 'opening category progress cards use extracted builder');
requireText('public/index.html', 'buildOpeningPositioningImpact(openingProjectForm.value.positioning)', 'opening positioning impact uses extracted builder');
requireText('public/index.html', 'buildOpeningTaskProgressCards(openingTaskStats.value)', 'opening progress cards use extracted builder');
requireText('public/index.html', 'buildOpeningTaskProgressStages(openingTaskStats.value)', 'opening progress stages use extracted builder');
requireText('public/index.html', 'buildOpeningStatusFilterChips(openingTaskStats.value)', 'opening status filter chips use extracted builder');
requireText('public/index.html', 'buildOpeningAttentionFilterChips(openingTaskStats.value)', 'opening attention filter chips use extracted builder');
requireText('public/index.html', 'buildOpeningTaskStats(openingTasks.value)', 'opening task stats use extracted builder');
requireText('public/index.html', 'filterOpeningTasks(openingTasks.value, openingTaskFilter.value)', 'opening task filtering uses extracted helper');
requireText('public/index.html', 'selectOpeningTasks(openingTasks.value, selectedOpeningTaskIds.value)', 'opening task selection uses extracted helper');
requireText('public/index.html', 'areAllFilteredOpeningTasksSelected(filteredOpeningTasks.value, selectedOpeningTaskIds.value)', 'opening all-selected check uses extracted helper');
requireText('public/index.html', 'pruneOpeningTaskIds(openingTasks.value, selectedOpeningTaskIds.value)', 'opening selected-id pruning uses extracted helper');
requireText('public/index.html', 'mergeOpeningTaskSelection(filteredOpeningTasks.value, selectedOpeningTaskIds.value, checked)', 'opening selection merge uses extracted helper');
requireText('public/index.html', "openingTaskDueLabel = requireOperationStatic(staticConfig, 'openingTaskDueLabel')", 'opening task due label uses extracted helper');
requireText('public/index.html', "openingTaskDueClass = requireOperationStatic(staticConfig, 'openingTaskDueClass')", 'opening task due class uses extracted helper');
requireText('public/index.html', "openingTaskProgressStage = requireOperationStatic(staticConfig, 'openingTaskProgressStage')", 'opening task progress stage uses extracted helper');
requireText('public/index.html', "openingTaskProgressTextClass = requireOperationStatic(staticConfig, 'openingTaskProgressTextClass')", 'opening task progress text class uses extracted helper');
requireText('public/index.html', "syncOpeningTaskProgressByStatus = requireOperationStatic(staticConfig, 'syncOpeningTaskProgressByStatus')", 'opening task status-progress sync uses extracted helper');
requireText('public/index.html', "syncOpeningTaskStatusByProgress = requireOperationStatic(staticConfig, 'syncOpeningTaskStatusByProgress')", 'opening task progress-status sync uses extracted helper');
requireText('public/index.html', "buildOpeningTaskUpdatePayload = requireOperationStatic(staticConfig, 'buildOpeningTaskUpdatePayload')", 'opening task update payload uses extracted helper');
requireText('public/index.html', "snapshotOpeningTaskForRollback = requireOperationStatic(staticConfig, 'snapshotOpeningTaskForRollback')", 'opening task rollback snapshot uses extracted helper');
requireText('public/index.html', "openingTaskPatchHasChanges = requireOperationStatic(staticConfig, 'openingTaskPatchHasChanges')", 'opening task patch guard uses extracted helper');
requireText('public/index.html', "applyOpeningTaskPatch = requireOperationStatic(staticConfig, 'applyOpeningTaskPatch')", 'opening task patch applier uses extracted helper');
requireText('public/index.html', 'const payload = buildOpeningTaskUpdatePayload(task)', 'opening task save payload uses extracted helper');
requireText('public/index.html', 'const snapshot = snapshotOpeningTaskForRollback(task)', 'opening task rollback snapshot uses extracted helper at call site');
requireText('public/index.html', 'if (!openingTaskPatchHasChanges(patch)) return', 'opening task batch patch guard uses extracted helper');
requireText('public/index.html', 'applyOpeningTaskPatch(task, patch)', 'opening task batch patch uses extracted helper');
requireText('public/index.html', 'applyOpeningTaskPatch(task, { status })', 'opening task status patch uses extracted helper');
requireText('public/index.html', 'applyOpeningTaskPatch(task, { progress_percent: value })', 'opening task quick progress patch uses extracted helper');
requireText('public/index.html', "openingRiskText = requireOperationStatic(staticConfig, 'openingRiskText')", 'opening risk text uses extracted helper');
requireText('public/index.html', "openingRiskTextClass = requireOperationStatic(staticConfig, 'openingRiskTextClass')", 'opening risk text class uses extracted helper');
requireText('public/index.html', "openingRiskClass = requireOperationStatic(staticConfig, 'openingRiskClass')", 'opening risk badge class uses extracted helper');
requireText('public/index.html', "buildOpeningProjectFormDefaults = requireOperationStatic(staticConfig, 'buildOpeningProjectFormDefaults')", 'opening project default form uses extracted helper');
requireText('public/index.html', "normalizeOpeningProjectFormForSubmitModel = requireOperationStatic(staticConfig, 'normalizeOpeningProjectFormForSubmit')", 'opening project submit normalization uses extracted helper');
requireText('public/index.html', "buildOpeningProjectFormFromProject = requireOperationStatic(staticConfig, 'buildOpeningProjectFormFromProject')", 'opening project form hydration uses extracted helper');
requireNoText('public/index.html', 'const openingTaskDueLabel = (task) => {', 'opening due label helper is not re-inlined in the SPA entry');
requireNoText('public/index.html', 'const openingTaskDueClass = (task) => {', 'opening due class helper is not re-inlined in the SPA entry');
requireNoText('public/index.html', 'const openingTaskProgressStage = (task) => {', 'opening progress stage helper is not re-inlined in the SPA entry');
requireNoText('public/index.html', 'const openingTaskProgressTextClass = (task) => {', 'opening progress text class helper is not re-inlined in the SPA entry');
requireNoText('public/index.html', 'const syncOpeningTaskProgressByStatus = (task) => {', 'opening status-progress sync is not re-inlined in the SPA entry');
requireNoText('public/index.html', "task.progress_percent >= 100", 'opening progress-status sync is not re-inlined in the SPA entry');
requireNoText('public/index.html', 'const payload = {\\n                        owner_name:', 'opening task update payload is not re-inlined in the SPA entry');
requireNoText('public/index.html', 'const snapshot = {\\n                            owner_name:', 'opening task rollback snapshot is not re-inlined in the SPA entry');
requireNoText('public/index.html', "Object.prototype.hasOwnProperty.call(patch, 'progress_percent')", 'opening task patch guard is not re-inlined in the SPA entry');
requireNoText('public/index.html', 'const openingRiskText = (risk) =>', 'opening risk text helper is not re-inlined in the SPA entry');
requireNoText('public/index.html', 'const openingRiskClass = (risk) => ({', 'opening risk badge class helper is not re-inlined in the SPA entry');
requireNoText('public/index.html', "if (!form.project_name && form.hotel_name)", 'opening project submit normalizer is not re-inlined in the SPA entry');
requireNoText('public/index.html', "project_name: project.project_name || ''", 'opening project form hydration is not re-inlined in the SPA entry');
requireNoText('public/index.html', "const operationCanApproveExecution = (item) => item?.approval?.status === 'pending_approval'", 'operation approval action guard is not re-inlined in the SPA entry');
requireNoText('public/index.html', "label: '人工审批',", 'operation execution trace cards are not re-inlined in the SPA entry');
requireNoText('public/index.html', "label: '审批率'", 'operation execution summary cards are not re-inlined in the SPA entry');
requireNoText('public/index.html', "profit_positive: '已验证赚钱'", 'operation execution money status text is not re-inlined in the SPA entry');
requireNoText('public/index.html', "const operationExecutionSourceText = (item) => {", 'operation execution source text helper is not re-inlined in the SPA entry');
requireNoText('public/index.html', "const operationExecutionActionText = (item) => {", 'operation execution action text helper is not re-inlined in the SPA entry');
requireNoText('public/index.html', "const operationExecutionReviewText = (item) => {", 'operation execution review text helper is not re-inlined in the SPA entry');
requireNoText('public/index.html', "const operationExecutionRoiText = (roi) => {", 'operation execution ROI text helper is not re-inlined in the SPA entry');
requireNoText('public/index.html', "label: '板块数'", 'operation closure summary cards are not re-inlined in the SPA entry');
requireNoText('public/index.html', "operationClosureOverview.summary?.status === 'closed'", 'operation closure summary badge is not re-inlined in the SPA entry');
requireNoText('public/index.html', "status: '待生成'", 'opening category progress display model is not re-inlined in the SPA entry');
requireNoText('public/index.html', "items: ['房价体系', 'OTA卖点', '物资标准', '培训话术']", 'opening positioning impact display model is not re-inlined in the SPA entry');
requireNoText('public/index.html', "label: '任务进度均值'", 'opening progress cards are not re-inlined in the SPA entry');
requireNoText('public/index.html', "label: '1%-49%'", 'opening progress stages are not re-inlined in the SPA entry');
requireNoText('public/index.html', "activeClass: 'bg-gray-900 text-white border-gray-900'", 'opening status filter chips are not re-inlined in the SPA entry');
requireNoText('public/index.html', "value: 'dueSoon', label: '7天内到期'", 'opening attention filter chips are not re-inlined in the SPA entry');
{
  const context = { window: {} };
  vm.runInNewContext(read('public/operation-static.js'), context, {
    filename: 'public/operation-static.js',
  });
  const helpers = context.window.SUXI_OPERATION_STATIC;
  const stats = {
    total: 4,
    done: 2,
    doing: 1,
    averageProgress: 63,
    completionRate: 50,
    overdue: 1,
    dueSoon: 2,
    noOwner: 1,
    progressEmpty: 1,
    progressLow: 1,
    progressHigh: 1,
    progressDone: 1,
  };
  const cards = helpers.buildOpeningTaskProgressCards(stats);
  const stages = helpers.buildOpeningTaskProgressStages(stats);
  const categoryCards = helpers.buildOpeningCategoryProgressCards([
    { category: '证照合规', total: 0, done: 0, completion_rate: 0 },
    { category: 'OTA上线配置', total: 3, done: 3, completion_rate: 100 },
    { category: '员工培训演练', total: 4, done: 1, completion_rate: 25 },
    { category: '开业营销推广', total: 2, done: 0, completion_rate: 0 },
  ]);
  const positioningImpact = helpers.buildOpeningPositioningImpact('高端商务');
  const statusChips = helpers.buildOpeningStatusFilterChips(stats);
  const attentionChips = helpers.buildOpeningAttentionFilterChips(stats);
  const p0ClosureBadge = helpers.buildOperationClosureSummaryBadge({
    status: 'blocked_by_p0_ota_gate',
    process_status: 'closed',
    roi_status: 'closed',
  });
  checks.push({
    file: 'public/operation-static.js',
    label: 'opening progress helper preserves card and stage semantics',
    ok: cards.length === 5
      && cards[0]?.value === '63%'
      && cards[1]?.hint === '2/4 项已完成，推进中 1 项'
      && cards[2]?.value === 1
      && cards[2]?.valueClass === 'text-red-600'
      && stages.length === 4
      && stages.map(stage => stage.label).join('|') === '未开始|1%-49%|50%-99%|100%'
      && stages.every(stage => stage.percent === 25),
    detail: 'opening progress helper must keep the original labels, values, warning classes, and percentages',
  });
  checks.push({
    file: 'public/operation-static.js',
    label: 'opening display helpers preserve category, positioning, and filter semantics',
    ok: categoryCards.length === 4
      && categoryCards[0]?.status === '待生成'
      && categoryCards[1]?.progressClass === 'bg-green-600'
      && categoryCards[2]?.status === '推进中'
      && categoryCards[3]?.statusClass === 'bg-yellow-50 text-yellow-700'
      && positioningImpact.summary.includes('高端商务定位会提高品质体验')
      && positioningImpact.items.includes('品质验收')
      && statusChips.map(item => item.value).join('|') === '|todo|doing|done|blocked'
      && attentionChips.map(item => item.value).join('|') === 'overdue|dueSoon|high|blocked|noOwner|core'
      && p0ClosureBadge.text === 'P0未就绪'
      && p0ClosureBadge.className.includes('text-red-700'),
    detail: 'opening display helper extraction must keep labels, classes, positioning branches, chip order, and P0 operation closure blocking state',
  });
}
requireNoText('public/index.html', 'operationFullData.reviews', 'operation dashboard does not render disabled review data');
requireText('app/service/OperationManagementService.php', "'service_quality' => $serviceQuality", 'operation full data returns service quality summary');
requireNoText('app/service/OperationManagementService.php', "'reviews' => $reviews", 'operation full data does not depend on review summary');
requireNoText('public/index.html', "onlineDataTab === 'ctrip-review'", 'Ctrip hidden review tab is removed from frontend');
requireNoText('public/index.html', "onlineDataTab === 'meituan-review'", 'Meituan hidden review tab is removed from frontend');
requireNoText('public/index.html', "currentDataConfigType === 'ctrip-comments'", 'Ctrip comment config modal is removed from frontend');
requireNoText('public/index.html', "currentDataConfigType === 'meituan-comments'", 'Meituan comment config modal is removed from frontend');
requireNoText('public/index.html', "/online-data/fetch-ctrip-comments", 'frontend does not call Ctrip comment fetch endpoint');
requireNoText('public/index.html', "/online-data/capture-ctrip-comments-browser", 'frontend does not call Ctrip browser comment capture endpoint');
requireNoText('public/index.html', "/online-data/fetch-meituan-comments", 'frontend does not call Meituan comment fetch endpoint');
requireText('public/index.html', 'online-data-ota-supplement', 'online data page renders daily OTA supplement summary panel');
requireText('public/index.html', 'ota_channel_supplement', 'frontend consumes OTA supplement summary from daily data summary');
requireOnlineDataControllerText("'ota_channel_supplement' =>", 'daily data summary returns OTA supplement summary');
requireOnlineDataControllerText("'scope' => 'ota_channel'", 'OTA supplement summary is explicitly scoped to OTA channel');
requireText('app/service/OnlineDailyDataPersistenceService.php', 'final class OnlineDailyDataPersistenceService', 'online daily data persistence lives in a focused service');
requireText('app/service/OnlineDailyDataPersistenceService.php', 'public function parseAndSaveTrafficData(', 'traffic persistence service owns traffic save orchestration');
requireOnlineDataControllerText('return (new OnlineDailyDataPersistenceService())->parseAndSaveTrafficData(', 'OnlineData keeps only a compatibility wrapper for traffic persistence');
requireNoText('app/controller/OnlineData.php', '$dataList = $this->extractCtripTrafficRows($responseData);', 'traffic persistence is not re-inlined in OnlineData');

requireText('tests/automation/e2e-helpers.js', 'function modulePath', 'helpers expose module path mapping');
requireText('tests/automation/e2e-helpers.js', 'function testIdForModule', 'helpers expose module test id selector');
requireText('tests/automation/e2e-helpers.js', 'function semanticInputValue', 'helpers generate field-semantic input values');
requireText('tests/automation/e2e-helpers.js', 'async function waitForApiOrState', 'helpers wait by API response or state assertion');
requireText('tests/automation/e2e-helpers.js', 'function classifyApiStatus', 'helpers classify API status by failure type');
requireText('tests/automation/e2e-helpers.js', "status === 400 || status === 422", 'helpers classify validation failures as invalid test data');

requireText('tests/automation/full-click-coverage.spec.js', 'backupDatabase', 'full click test backs up database before mutation');
requireText('tests/automation/full-click-coverage.spec.js', 'restoreDatabase', 'full click test can restore database after mutation');
requireText('tests/automation/full-click-coverage.spec.js', 'semanticInputValue', 'full click test uses semantic input generator');
requireText('tests/automation/full-click-coverage.spec.js', "category: 'safe-skip'", 'full click report classifies safe skips');
requireText('tests/automation/full-click-coverage.spec.js', "'test-data-invalid'", 'full click report classifies invalid test data');
requireText('tests/automation/full-click-coverage.spec.js', "'product-bug'", 'full click report classifies product bugs');
requireText('tests/automation/full-click-coverage.spec.js', 'summary.json', 'full click test writes classified summary');
requireText('tests/automation/full-click-coverage.spec.js', 'MIN_KEY_FUNCTION_LOOPS = 50', 'full click key-function validation starts at 50 loops');
requireText('tests/automation/full-click-coverage.spec.js', 'MAX_KEY_FUNCTION_LOOPS = 100', 'full click key-function validation caps at 100 loops');
requireText('tests/automation/full-click-coverage.spec.js', 'parseKeyFunctionLoopCount', 'full click clamps key-function loop count');
requireText('tests/automation/full-click-coverage.spec.js', 'E2E_FULL_MIN_LOOP', 'full click can lower loop floor for bounded runs');
requireText('tests/automation/full-click-coverage.spec.js', 'E2E_FULL_MAX_LOOP', 'full click can cap loop count for bounded runs');
requireNoText('tests/automation/full-click-coverage.spec.js', 'waitForTimeout', 'full click test avoids fixed sleeps');

requireText('tests/automation/edge-input-guard.spec.js', 'edgeCasesForField', 'edge input guard generates boundary input cases');
requireText('tests/automation/edge-input-guard.spec.js', 'installEdgeApiMocks', 'edge input guard mocks mutating APIs by default');
requireText('tests/automation/edge-input-guard.spec.js', 'E2E_EDGE_LIVE_API', 'edge input guard can opt into live API mode');
requireText('tests/automation/edge-input-guard.spec.js', 'E2E_USERNAME', 'edge input guard uses E2E username override');
requireText('tests/automation/edge-input-guard.spec.js', 'mocked-response', 'edge input guard records mocked validation responses');
requireText('tests/automation/edge-input-guard.spec.js', 'classifyConsoleEvent', 'edge input guard classifies expected console validation errors');
requireText('tests/automation/edge-input-guard.spec.js', "row.category === 'page-error'", 'edge input guard fails on real page-error diagnostics');
requireText('tests/automation/edge-input-guard.spec.js', 'script-like-text', 'edge input guard covers script-like text safely as field input');
requireText('tests/automation/edge-input-guard.spec.js', 'maxFieldsPerModule: clampInt(process.env.E2E_EDGE_MAX_FIELDS_PER_MODULE, 12', 'edge input guard has bounded default field scan');
requireText('tests/automation/edge-input-guard.spec.js', 'maxActionsPerModule: clampInt(process.env.E2E_EDGE_MAX_ACTIONS_PER_MODULE, 8', 'edge input guard has bounded default action scan');
requireNoText('tests/automation/edge-input-guard.spec.js', 'process.env.USERNAME', 'edge input guard avoids OS username environment');
requireNoText('tests/automation/edge-input-guard.spec.js', 'process.env.PASSWORD', 'edge input guard avoids generic password environment');
requireNoText('tests/automation/edge-input-guard.spec.js', 'waitForTimeout', 'edge input guard avoids fixed sleeps');

requireText('tests/automation/module-smoke.spec.js', 'goModule', 'module smoke reuses stable module navigation helper');
requireText('tests/automation/module-smoke.spec.js', 'semanticInputValue', 'module smoke uses semantic input generator');
requireText('tests/automation/module-smoke.spec.js', 'waitForApiOrState', 'module smoke waits by API response or state assertion');
requireText('tests/automation/module-smoke.spec.js', 'category: classifyError(error)', 'module smoke classifies failures in report');
requireNoText('tests/automation/module-smoke.spec.js', 'waitForTimeout', 'module smoke avoids fixed sleeps');
requireNoText('tests/automation/module-smoke.spec.js', 'getByText', 'module smoke avoids text-only navigation selectors');

requireText('tests/automation/async-page-guard.spec.js', 'installHistoryFixtures', 'async guard uses deterministic history fixtures');
requireText('tests/automation/async-page-guard.spec.js', 'waitForResponse', 'async guard waits for delayed detail response');
requireNoText('tests/automation/async-page-guard.spec.js', 'waitForTimeout', 'async guard avoids fixed sleeps');

requireText('tests/automation/business-chains.spec.js', 'business chain: OTA import to revenue', 'business chain covers OTA to operation');
requireText('tests/automation/business-chains.spec.js', 'business chain: market evaluation to transfer', 'business chain covers market to transfer');
requireText('tests/automation/business-chains.spec.js', 'business chain: strategy, quant simulation, feasibility', 'business chain covers investment decision');
requireText('tests/automation/business-chains.spec.js', '/api/online-data/save-daily-data', 'business chain imports OTA data through API');
requireText('tests/automation/business-chains.spec.js', '/api/operation/action-tracking', 'business chain asserts operation action tracking');
requireText('tests/automation/business-chains.spec.js', '/api/transfer/dashboard', 'business chain asserts transfer dashboard reads upstream results');
requireText('tests/automation/business-chains.spec.js', '/api/agent/feasibility-report/generate', 'business chain asserts feasibility report persistence');
requireText('tests/automation/business-chains.spec.js', 'E2E_API_REQUEST_TIMEOUT_MS', 'business chain API client has configurable timeout');
requireText('tests/automation/business-chains.spec.js', 'timeout: apiRequestTimeout', 'business chain applies API timeout to auth and business calls');
requireText('tests/automation/business-chains.spec.js', "'test-data-invalid'", 'business chain classifies invalid test data');
requireText('tests/automation/business-chains.spec.js', "'product-bug'", 'business chain classifies product bugs');
requireNoText('tests/automation/business-chains.spec.js', 'waitForTimeout', 'business chain avoids fixed sleeps');

requireText('tests/automation/README.md', 'test:e2e:business', 'README documents business-chain test command');
requireText('tests/automation/README.md', 'test:e2e:edge', 'README documents edge input guard command');
requireText('tests/automation/README.md', 'E2E_EDGE_LIVE_API=0', 'README documents edge test safe mocked API mode');
requireText('tests/automation/README.md', '`product-bug`', 'README documents product bug category');
requireText('tests/automation/README.md', '`test-data-invalid`', 'README documents invalid test data category');

requireText('package.json', 'test:e2e:quick', 'package exposes quick CI e2e command');
requireText('package.json', 'test:e2e:business', 'package exposes business chain e2e command');
requireText('package.json', 'test:e2e:edge', 'package exposes edge input e2e command');
requireText('package.json', 'test:e2e:ui', 'package exposes UI automation e2e command');
requireText('package.json', 'test:e2e:full:bounded', 'package exposes bounded full-click e2e command');
requireText('package.json', 'slim:local', 'package exposes local slimming command');
requireText('scripts/clean_project_local_artifacts.ps1', 'function Remove-TargetBestEffort', 'local slimming removes artifact contents best-effort');
requireText('scripts/clean_project_local_artifacts.ps1', '$skippedLocked = @()', 'local slimming tracks locked near-zero residual artifacts separately');
requireText('scripts/clean_project_local_artifacts.ps1', '$remaining.files -gt 0 -and $remaining.mb -gt 1', 'local slimming only fails when meaningful residual artifact size remains');
requireText('scripts/clean_project_local_artifacts.ps1', 'Some near-zero-size local artifact files were left in place', 'local slimming reports locked runtime log residuals explicitly');

try {
  const context = { window: {} };
  vm.runInNewContext(read('public/simulation-static.js'), context, {
    filename: 'public/simulation-static.js',
  });
  const simulationStatic = context.window.SUXI_SIMULATION_STATIC || {};
  const buildTransferDecisionLayerRows = simulationStatic.buildTransferDecisionLayerRows;
  if (typeof buildTransferDecisionLayerRows !== 'function') {
    checks.push({
      file: 'public/simulation-static.js',
      label: 'Simulation static exports transfer decision layer builder',
      ok: false,
      detail: 'buildTransferDecisionLayerRows',
    });
  } else {
    const readyRows = buildTransferDecisionLayerRows({
      snapshot: { data_status: 'verified' },
      sourceDate: '2026-06-10',
      pricingResult: { valuation: 280 },
      timingResult: { window: 'now' },
      dashboardResult: { final_judgement: '可进入谈判' },
      pricingForm: { hotel_name: '天成酒店' },
      timingForm: {},
    });
    const emptyRows = buildTransferDecisionLayerRows({});
    checks.push({
      file: 'public/simulation-static.js',
      label: 'Simulation static builds transfer decision rows with explicit fact, assumption, calculation and risk states',
      ok: readyRows.length === 4
        && readyRows[0].key === 'facts'
        && readyRows[0].status === '有快照'
        && readyRows[0].detail.includes('2026-06-10')
        && readyRows[0].evidence === 'data_status: verified'
        && readyRows[1].status === '已填写'
        && readyRows[1].evidence === '表单输入不自动等同于已验证事实。'
        && readyRows[2].status === '已生成'
        && readyRows[2].evidence === '定价 有 / 时机 有'
        && readyRows[3].status === '可汇总'
        && readyRows[3].evidence === '可进入谈判'
        && emptyRows[0].status === '待取数'
        && emptyRows[0].evidence === '暂无经营快照'
        && emptyRows[1].status === '待填写'
        && emptyRows[2].evidence === '定价 无 / 时机 无'
        && emptyRows[3].status === '待汇总'
        && emptyRows[3].evidence === '暂无最终判断',
      detail: 'buildTransferDecisionLayerRows samples',
    });
  }
} catch (error) {
  checks.push({
    file: 'public/simulation-static.js',
    label: 'Simulation static VM smoke check',
    ok: false,
    detail: error.message,
  });
}

try {
  const context = { window: {} };
  vm.runInNewContext(read('public/auto-fetch-static.js'), context, {
    filename: 'public/auto-fetch-static.js',
  });
  const autoFetchStatic = context.window.SUXI_AUTO_FETCH_STATIC || {};
  const buildAutoFetchTriggerRequestBody = autoFetchStatic.buildAutoFetchTriggerRequestBody;
  const buildAutoFetchRunStartState = autoFetchStatic.buildAutoFetchRunStartState;
  const runAutoFetchTriggerFlow = autoFetchStatic.runAutoFetchTriggerFlow;
  const resolveDataConfigTestEndpoint = autoFetchStatic.resolveDataConfigTestEndpoint;
  const buildDataConfigTestRequest = autoFetchStatic.buildDataConfigTestRequest;
  const runDataConfigTestFlow = autoFetchStatic.runDataConfigTestFlow;
  const autoFetchDisplayHelpers = [
    'autoFetchScopeStatusClass',
    'autoFetchModeLabel',
    'formatAutoFetchElapsed',
    'formatAutoFetchMs',
    'autoFetchResultStatusText',
    'autoFetchResultStatusClass',
    'autoFetchModuleLabel',
    'platformProfileStatusLabel',
    'platformProfileStatusRawText',
    'platformProfileStatusBadgeClass',
    'platformProfileCheckClass',
    'platformProfileBindingRawText',
    'platformProfileBindingText',
    'platformProfileStrategyText',
    'platformProfilePrimaryActionText',
    'platformProfileNextActionText',
    'platformProfileLoginTaskText',
    'platformProfileLoginTaskRawText',
    'platformSourceStatusClass',
    'platformTaskStatusClass',
    'platformSyncActionText',
  ];
  if (typeof buildAutoFetchTriggerRequestBody !== 'function'
    || typeof buildAutoFetchRunStartState !== 'function'
    || typeof runAutoFetchTriggerFlow !== 'function'
    || typeof resolveDataConfigTestEndpoint !== 'function'
    || typeof buildDataConfigTestRequest !== 'function'
    || typeof runDataConfigTestFlow !== 'function'
    || autoFetchDisplayHelpers.some(key => typeof autoFetchStatic[key] !== 'function')) {
    checks.push({
      file: 'public/auto-fetch-static.js',
      label: 'auto-fetch static exports trigger flow helpers',
      ok: false,
      detail: 'trigger request, start state, flow runner, data-config test runner, display helpers',
    });
  } else {
    const triggerBody = buildAutoFetchTriggerRequestBody({
      hotelId: 58,
      browserHeadless: true,
      modePayload: { meituan_auto_fetch_mode: 'hybrid_auto', ctrip_section_concurrency: 3 },
    });
    const startState = buildAutoFetchRunStartState({
      startedAt: '2026-06-11 10:00:00',
      ctripExecutionText: '携程 3 页并发',
      modePayload: { meituan_auto_fetch_mode: 'hybrid_auto' },
      modeLabel: () => '接口直连自动',
      browserHeadless: true,
    });
    const runTriggerSample = async (overrides = {}) => {
      const events = [];
      const timestamps = [...(overrides.timestamps || ['2026-06-11 10:00:00', '2026-06-11 10:00:09'])];
      let capturedRequestBody = null;
      let delayedRefreshSettled = false;
      const delayedRefresh = label => new Promise(resolve => {
        setTimeout(() => {
          delayedRefreshSettled = true;
          events.push(['refresh', label]);
          resolve();
        }, 25);
      });
      const result = await runAutoFetchTriggerFlow({
        getHotelId: () => (overrides.hotelId === undefined ? 58 : overrides.hotelId),
        hasPlatformFetchConfig: hotelId => (overrides.hasConfig === undefined ? Boolean(hotelId) : overrides.hasConfig),
        setFetching: value => events.push(['fetching', value]),
        startTimer: () => events.push(['timer', 'start']),
        stopTimer: () => events.push(['timer', 'stop']),
        getTimestamp: () => timestamps.shift() || '2026-06-11 10:00:09',
        getBrowserHeadless: () => (overrides.browserHeadless === undefined ? true : overrides.browserHeadless),
        getCtripExecutionText: () => '携程 3 页并发',
        buildModePayload: () => ({ meituan_auto_fetch_mode: 'hybrid_auto', ctrip_section_concurrency: 3 }),
        modeLabel: value => ({ hybrid_auto: '接口直连自动' }[value] || value),
        getCtripSectionConcurrency: () => 3,
        notify: (message, type = 'success') => events.push(['notify', type, message]),
        setRunState: value => events.push(['state', value.type, value]),
        requestAutoFetch: async (body) => {
          capturedRequestBody = body;
          events.push(['request', body]);
          if (overrides.throwRequest) {
            throw new Error('network failed');
          }
          return overrides.response || { code: 200, message: 'ok', data: { saved_count: 9 } };
        },
        getDurationText: () => '9秒',
        updateLastResult: (response, success, message) => events.push(['lastResult', success, message, response]),
        refreshOnlineData: overrides.delayedRefresh ? () => delayedRefresh('online') : async () => events.push(['refresh', 'online']),
        refreshOnlineHistory: overrides.delayedRefresh ? () => delayedRefresh('history') : async () => events.push(['refresh', 'history']),
        refreshLatestCtripData: overrides.delayedRefresh ? () => delayedRefresh('latest') : async options => events.push(['refresh', 'latest', options]),
        openCtripProfileFieldsForReview: overrides.delayedRefresh ? () => delayedRefresh('profile-review') : async () => events.push(['refresh', 'profile-review']),
        loadAutoFetchStatus: overrides.delayedRefresh ? () => delayedRefresh('status') : async () => events.push(['refresh', 'status']),
        loadBackendGlobalNotifications: overrides.delayedRefresh ? () => delayedRefresh('notifications') : async () => events.push(['refresh', 'notifications']),
      });
      return { result, events, capturedRequestBody, returnedBeforeDelayedRefresh: !delayedRefreshSettled };
    };

    const successRun = await runTriggerSample();
    const acceptedRun = await runTriggerSample({
      response: { code: 200, message: 'queued', data: { status: 'running', task_id: 'task-1', saved_count: 0 } },
    });
    const delayedRefreshRun = await runTriggerSample({ delayedRefresh: true });
    const errorRun = await runTriggerSample({
      response: { code: 500, message: 'upstream failed', data: { saved_count: 0 } },
    });
    const exceptionRun = await runTriggerSample({ throwRequest: true });
    const missingHotelRun = await runTriggerSample({ hotelId: '' });
    const missingConfigRun = await runTriggerSample({ hasConfig: false });
    const ctripAdsEndpoint = resolveDataConfigTestEndpoint('ctrip-ads');
    const unsupportedEndpoint = resolveDataConfigTestEndpoint('booking-ota');
    const invalidEndpoint = resolveDataConfigTestEndpoint('unknown-platform');
    const ctripAdsTestRequest = buildDataConfigTestRequest({
      type: 'ctrip-ads',
      form: {
        url: 'https://m.ctrip.com/restapi/soa2/18320/json/queryCampaignReportList',
        cookies: 'sid=secret',
        payload_json: '{"page":1}',
        start_date: '2026-06-10',
        end_date: '2026-06-11',
        system_hotel_id: 'hotel-58',
      },
      validateCtripAdsApiUrl: url => url.includes('queryCampaignReportList'),
      ctripAdsApiUrlHint: 'ads url hint',
    });
    const invalidCtripAdsTestRequest = buildDataConfigTestRequest({
      type: 'ctrip-ads',
      form: { url: 'https://ebooking.ctrip.com/page' },
      validateCtripAdsApiUrl: url => url.includes('queryCampaignReportList'),
      ctripAdsApiUrlHint: 'ads url hint',
    });
    const runDataConfigTestSample = async (overrides = {}) => {
      const events = [];
      let capturedApiUrl = '';
      let capturedBody = null;
      const result = await runDataConfigTestFlow({
        getType: () => (overrides.type === undefined ? 'ctrip-ads' : overrides.type),
        getForm: () => overrides.form || {
          url: 'https://m.ctrip.com/restapi/soa2/18320/json/queryCampaignReportList',
          cookies: 'sid=secret',
          payload_json: '{"page":1}',
          start_date: '2026-06-10',
          end_date: '2026-06-11',
          system_hotel_id: 'hotel-58',
        },
        setTesting: value => events.push(['testing', value]),
        notify: (message, level = 'success') => events.push(['notify', level, message]),
        validateCtripAdsApiUrl: url => url.includes('queryCampaignReportList'),
        ctripAdsApiUrlHint: 'ads url hint',
        requestTest: async (apiUrl, body) => {
          capturedApiUrl = apiUrl;
          capturedBody = body;
          events.push(['request', apiUrl, body]);
          if (overrides.throwRequest) {
            throw new Error('network failed');
          }
          return overrides.response || { code: 200, message: 'ok', data: { saved_count: 2 } };
        },
      });
      return { result, events, capturedApiUrl, capturedBody };
    };
    const configSuccessRun = await runDataConfigTestSample();
    const configFailedRun = await runDataConfigTestSample({ response: { code: 500, message: 'connection failed' } });
    const configUnsupportedRun = await runDataConfigTestSample({ type: 'booking-ota' });
    const configInvalidUrlRun = await runDataConfigTestSample({ form: { url: 'https://ebooking.ctrip.com/page' } });
    const configExceptionRun = await runDataConfigTestSample({ throwRequest: true });

    checks.push({
      file: 'public/auto-fetch-static.js',
      label: 'auto-fetch static owns display labels, timing, and status classes',
      ok: autoFetchStatic.autoFetchScopeStatusClass('ready').includes('emerald')
        && autoFetchStatic.autoFetchModeLabel('profile_browser').length > 0
        && autoFetchStatic.formatAutoFetchElapsed(65).includes('1')
        && autoFetchStatic.formatAutoFetchElapsed(65).includes('05')
        && autoFetchStatic.formatAutoFetchMs(999) === '999ms'
        && autoFetchStatic.formatAutoFetchMs(1500).includes('2')
        && autoFetchStatic.autoFetchResultStatusText({ skipped: true }).length > 0
        && autoFetchStatic.autoFetchResultStatusClass({ success: true }).includes('green')
        && autoFetchStatic.autoFetchModuleLabel('browser_profile').length > 0
        && autoFetchStatic.autoFetchModuleLabel('custom_module') === 'custom_module'
        && autoFetchStatic.platformProfileStatusLabel({ status_code: 'logged_in' }).length > 0
        && autoFetchStatic.platformProfileStatusBadgeClass('logged_in').includes('emerald')
        && autoFetchStatic.platformProfileCheckClass('error').includes('red')
        && autoFetchStatic.platformProfileBindingText({ platform: 'ctrip', profile_key: '63', binding: { ctrip_hotel_id: '6866634' } }).length > 0
        && autoFetchStatic.platformProfileBindingRawText({ platform: 'meituan', profile_key: 'store-1', binding: { poi_id: 'poi-1', partner_id_configured: true } }).includes('poi-1')
        && autoFetchStatic.platformProfileStrategyText({ platform: 'ctrip' }).length > 0
        && autoFetchStatic.platformProfilePrimaryActionText({ platform: 'meituan' }).length > 0
        && autoFetchStatic.platformProfileNextActionText({ status_code: 'logged_in' }).length > 0
        && autoFetchStatic.platformProfileLoginTaskText({ status: 'running' }).length > 0
        && autoFetchStatic.platformProfileLoginTaskRawText({ task_id: 'task-1' }).includes('task-1')
        && autoFetchStatic.platformSourceStatusClass('failed').includes('red')
        && autoFetchStatic.platformTaskStatusClass('partial_success').includes('amber')
        && autoFetchStatic.platformSyncActionText('login expired').length > 0,
      detail: 'auto-fetch display helper samples',
    });

    checks.push({
      file: 'public/auto-fetch-static.js',
      label: 'auto-fetch trigger request body preserves OTA capture scope',
      ok: triggerBody.system_hotel_id === 58
        && triggerBody.data_period === 'realtime_snapshot'
        && triggerBody.interactive_browser === false
        && triggerBody.browser_headless === true
        && triggerBody.async === true
        && triggerBody.meituan_auto_fetch_mode === 'hybrid_auto'
        && triggerBody.ctrip_section_concurrency === 3,
      detail: 'buildAutoFetchTriggerRequestBody sample',
    });
    checks.push({
      file: 'public/auto-fetch-static.js',
      label: 'auto-fetch trigger running state keeps explicit platform mode text',
      ok: startState.active === true
        && startState.type === 'running'
        && startState.message.includes('携程 3 页并发')
        && startState.message.includes('美团使用接口直连自动')
        && startState.message.includes('浏览器无头运行'),
      detail: 'buildAutoFetchRunStartState sample',
    });
    checks.push({
      file: 'public/auto-fetch-static.js',
      label: 'auto-fetch trigger accepted path returns before OTA collection finishes',
      ok: acceptedRun.result.status === 'accepted'
        && acceptedRun.capturedRequestBody.async === true
        && acceptedRun.events.some(event => event[0] === 'state' && event[1] === 'running' && event[2].active === true)
        && acceptedRun.events.some(event => event[0] === 'lastResult' && event[1] === null && event[2] === 'queued')
        && acceptedRun.events.some(event => event[0] === 'notify' && event[1] === 'info' && event[2] === 'queued')
        && acceptedRun.events.some(event => event[0] === 'refresh' && event[1] === 'status')
        && acceptedRun.events.some(event => event[0] === 'refresh' && event[1] === 'notifications')
        && !acceptedRun.events.some(event => event[0] === 'refresh' && ['online', 'history', 'latest'].includes(event[1]))
        && acceptedRun.events.some(event => event[0] === 'timer' && event[1] === 'start')
        && acceptedRun.events.some(event => event[0] === 'timer' && event[1] === 'stop')
        && acceptedRun.events.some(event => event[0] === 'fetching' && event[1] === false),
      detail: 'runAutoFetchTriggerFlow accepted sample',
    });
    checks.push({
      file: 'public/auto-fetch-static.js',
      label: 'auto-fetch trigger success path refreshes persisted and UI data',
      ok: successRun.result.status === 'success'
        && successRun.capturedRequestBody.system_hotel_id === 58
        && successRun.capturedRequestBody.async === true
        && successRun.events.some(event => event[0] === 'state' && event[1] === 'running')
        && successRun.events.some(event => event[0] === 'state' && event[1] === 'success')
        && successRun.events.some(event => event[0] === 'lastResult' && event[1] === true && event[2] === 'ok')
        && successRun.events.some(event => event[0] === 'refresh' && event[1] === 'online')
        && successRun.events.some(event => event[0] === 'refresh' && event[1] === 'history')
        && successRun.events.some(event => event[0] === 'refresh' && event[1] === 'latest' && event[2]?.silent === true)
        && successRun.events.some(event => event[0] === 'refresh' && event[1] === 'profile-review')
        && successRun.events.some(event => event[0] === 'refresh' && event[1] === 'status')
        && successRun.events.some(event => event[0] === 'refresh' && event[1] === 'notifications')
        && successRun.events.some(event => event[0] === 'timer' && event[1] === 'start')
        && successRun.events.some(event => event[0] === 'timer' && event[1] === 'stop')
        && successRun.events.some(event => event[0] === 'fetching' && event[1] === false)
        && delayedRefreshRun.result.status === 'success'
        && delayedRefreshRun.returnedBeforeDelayedRefresh === true,
      detail: 'runAutoFetchTriggerFlow success sample',
    });
    checks.push({
      file: 'public/auto-fetch-static.js',
      label: 'auto-fetch trigger error response keeps failed state explicit',
      ok: errorRun.result.status === 'error_response'
        && errorRun.events.some(event => event[0] === 'lastResult' && event[1] === false && String(event[2]).includes('upstream failed'))
        && errorRun.events.some(event => event[0] === 'state' && event[1] === 'error' && event[2].message.includes('upstream failed'))
        && errorRun.events.some(event => event[0] === 'notify' && event[1] === 'error' && event[2].includes('upstream failed'))
        && errorRun.events.some(event => event[0] === 'refresh' && event[1] === 'status')
        && errorRun.events.some(event => event[0] === 'refresh' && event[1] === 'notifications')
        && !errorRun.events.some(event => event[0] === 'refresh' && event[1] === 'online'),
      detail: 'runAutoFetchTriggerFlow error response sample',
    });
    checks.push({
      file: 'public/auto-fetch-static.js',
      label: 'auto-fetch trigger exception path exposes failure and releases busy state',
      ok: exceptionRun.result.status === 'exception'
        && exceptionRun.events.some(event => event[0] === 'state' && event[1] === 'error' && event[2].message.includes('network failed'))
        && exceptionRun.events.some(event => event[0] === 'notify' && event[1] === 'error' && event[2].includes('获取失败: network failed'))
        && exceptionRun.events.some(event => event[0] === 'refresh' && event[1] === 'status')
        && exceptionRun.events.some(event => event[0] === 'refresh' && event[1] === 'notifications')
        && exceptionRun.events.some(event => event[0] === 'timer' && event[1] === 'stop')
        && exceptionRun.events.some(event => event[0] === 'fetching' && event[1] === false),
      detail: 'runAutoFetchTriggerFlow exception sample',
    });
    checks.push({
      file: 'public/auto-fetch-static.js',
      label: 'auto-fetch trigger guards missing hotel before mutation',
      ok: missingHotelRun.result.status === 'missing_hotel'
        && missingHotelRun.events.some(event => event[0] === 'notify' && event[1] === 'error' && event[2].includes('请先选择酒店'))
        && !missingHotelRun.events.some(event => event[0] === 'fetching')
        && !missingHotelRun.events.some(event => event[0] === 'timer'),
      detail: 'missing hotel guard',
    });
    checks.push({
      file: 'public/auto-fetch-static.js',
      label: 'auto-fetch trigger guards missing platform config before mutation',
      ok: missingConfigRun.result.status === 'missing_config'
        && missingConfigRun.events.some(event => event[0] === 'notify' && event[1] === 'error' && event[2].includes('保存并关联携程或美团配置'))
        && !missingConfigRun.events.some(event => event[0] === 'fetching')
        && !missingConfigRun.events.some(event => event[0] === 'timer'),
      detail: 'missing config guard',
    });
    checks.push({
      file: 'public/auto-fetch-static.js',
      label: 'data-source config test endpoint mapping keeps explicit unsupported states',
      ok: ctripAdsEndpoint.status === 'ready'
        && ctripAdsEndpoint.apiUrl === '/online-data/fetch-ctrip-ads'
        && unsupportedEndpoint.status === 'unsupported'
        && unsupportedEndpoint.level === 'info'
        && invalidEndpoint.status === 'unknown_type'
        && invalidEndpoint.level === 'error',
      detail: 'resolveDataConfigTestEndpoint samples',
    });
    checks.push({
      file: 'public/auto-fetch-static.js',
      label: 'data-source config test request preserves OTA scope and URL validation',
      ok: ctripAdsTestRequest.status === 'ready'
        && ctripAdsTestRequest.apiUrl === '/online-data/fetch-ctrip-ads'
        && ctripAdsTestRequest.body?.api_type === 'effect_report'
        && ctripAdsTestRequest.body?.cookies === 'sid=secret'
        && ctripAdsTestRequest.body?.payload_json === '{"page":1}'
        && ctripAdsTestRequest.body?.system_hotel_id === 'hotel-58'
        && invalidCtripAdsTestRequest.status === 'invalid_url'
        && invalidCtripAdsTestRequest.message === 'ads url hint',
      detail: 'buildDataConfigTestRequest samples',
    });
    checks.push({
      file: 'public/auto-fetch-static.js',
      label: 'data-source config test flow keeps success, failed and skipped states visible',
      ok: configSuccessRun.result.status === 'success'
        && configSuccessRun.capturedApiUrl === '/online-data/fetch-ctrip-ads'
        && configSuccessRun.capturedBody?.cookies === 'sid=secret'
        && configSuccessRun.events.some(event => event[0] === 'notify' && event[2] === '连接测试成功！数据获取正常')
        && configFailedRun.result.status === 'failed'
        && configFailedRun.events.some(event => event[0] === 'notify' && event[1] === 'error' && event[2] === 'connection failed')
        && configUnsupportedRun.result.status === 'unsupported'
        && configUnsupportedRun.events.some(event => event[0] === 'notify' && event[1] === 'info')
        && !configUnsupportedRun.events.some(event => event[0] === 'request')
        && configInvalidUrlRun.result.status === 'invalid_url'
        && configInvalidUrlRun.events.some(event => event[0] === 'notify' && event[1] === 'error' && event[2] === 'ads url hint')
        && !configInvalidUrlRun.events.some(event => event[0] === 'request')
        && configExceptionRun.result.status === 'exception'
        && configExceptionRun.events.some(event => event[0] === 'notify' && event[1] === 'error' && event[2].includes('network failed'))
        && [configSuccessRun, configFailedRun, configUnsupportedRun, configInvalidUrlRun, configExceptionRun].every(run => run.events[0]?.[0] === 'testing' && run.events[0]?.[1] === true)
        && [configSuccessRun, configFailedRun, configUnsupportedRun, configInvalidUrlRun, configExceptionRun].every(run => run.events[run.events.length - 1]?.[0] === 'testing' && run.events[run.events.length - 1]?.[1] === false),
      detail: 'runDataConfigTestFlow samples',
    });
  }
} catch (error) {
  checks.push({
    file: 'public/auto-fetch-static.js',
    label: 'auto-fetch static runtime validation',
    ok: false,
    detail: error.message,
  });
}

try {
  const context = { window: {} };
  vm.runInNewContext(read('public/meituan-static.js'), context, {
    filename: 'public/meituan-static.js',
  });
  const meituanStatic = context.window.SUXI_MEITUAN_STATIC || {};
  const buildMeituanBatchFetchTasks = meituanStatic.buildMeituanBatchFetchTasks;
  const buildMeituanBatchFetchResultEntry = meituanStatic.buildMeituanBatchFetchResultEntry;
  const buildMeituanDisplayModelPayload = meituanStatic.buildMeituanDisplayModelPayload;
  const validateMeituanBatchFetchInput = meituanStatic.validateMeituanBatchFetchInput;
  const runMeituanBatchFetchFlow = meituanStatic.runMeituanBatchFetchFlow;
  const buildMeituanBrowserCaptureRequestContext = meituanStatic.buildMeituanBrowserCaptureRequestContext;
  const runMeituanBrowserCaptureFlow = meituanStatic.runMeituanBrowserCaptureFlow;
  const getMeituanBrowserCaptureSupplementModules = meituanStatic.getMeituanBrowserCaptureSupplementModules;
  const buildMeituanBrowserCaptureSupplementCounts = meituanStatic.buildMeituanBrowserCaptureSupplementCounts;
  const buildMeituanCapturedPayloadSaveContext = meituanStatic.buildMeituanCapturedPayloadSaveContext;
  const runMeituanCapturedPayloadSaveFlow = meituanStatic.runMeituanCapturedPayloadSaveFlow;
  const normalizeMeituanTrafficFetchForm = meituanStatic.normalizeMeituanTrafficFetchForm;
  const validateMeituanTrafficFetchInput = meituanStatic.validateMeituanTrafficFetchInput;
  const buildMeituanTrafficFetchRequestBody = meituanStatic.buildMeituanTrafficFetchRequestBody;
  const runMeituanTrafficFetchFlow = meituanStatic.runMeituanTrafficFetchFlow;
  const normalizeMeituanOrderFetchForm = meituanStatic.normalizeMeituanOrderFetchForm;
  const validateMeituanOrderFetchInput = meituanStatic.validateMeituanOrderFetchInput;
  const buildMeituanOrderFetchRequestBody = meituanStatic.buildMeituanOrderFetchRequestBody;
  const runMeituanOrderFetchFlow = meituanStatic.runMeituanOrderFetchFlow;
  const buildMeituanOrderDomCollectorScript = meituanStatic.buildMeituanOrderDomCollectorScript;
  const parseMeituanOrderCsvText = meituanStatic.parseMeituanOrderCsvText;
  const buildMeituanOrderCsvImportRequestBody = meituanStatic.buildMeituanOrderCsvImportRequestBody;
  const runMeituanOrderCsvImportFlow = meituanStatic.runMeituanOrderCsvImportFlow;
  const normalizeMeituanAdsFetchForm = meituanStatic.normalizeMeituanAdsFetchForm;
  const validateMeituanAdsFetchInput = meituanStatic.validateMeituanAdsFetchInput;
  const buildMeituanAdsFetchRequestBody = meituanStatic.buildMeituanAdsFetchRequestBody;
  const runMeituanAdsFetchFlow = meituanStatic.runMeituanAdsFetchFlow;
  const runMeituanManualTabSwitch = meituanStatic.runMeituanManualTabSwitch;
  if (typeof buildMeituanBatchFetchTasks !== 'function'
    || typeof buildMeituanBatchFetchResultEntry !== 'function'
    || typeof buildMeituanDisplayModelPayload !== 'function'
    || typeof validateMeituanBatchFetchInput !== 'function'
    || typeof runMeituanBatchFetchFlow !== 'function'
    || typeof buildMeituanBrowserCaptureRequestContext !== 'function'
    || typeof runMeituanBrowserCaptureFlow !== 'function'
    || typeof getMeituanBrowserCaptureSupplementModules !== 'function'
    || typeof buildMeituanBrowserCaptureSupplementCounts !== 'function'
    || typeof buildMeituanCapturedPayloadSaveContext !== 'function'
    || typeof runMeituanCapturedPayloadSaveFlow !== 'function'
    || typeof normalizeMeituanTrafficFetchForm !== 'function'
    || typeof validateMeituanTrafficFetchInput !== 'function'
    || typeof buildMeituanTrafficFetchRequestBody !== 'function'
    || typeof runMeituanTrafficFetchFlow !== 'function'
    || typeof normalizeMeituanOrderFetchForm !== 'function'
    || typeof validateMeituanOrderFetchInput !== 'function'
    || typeof buildMeituanOrderFetchRequestBody !== 'function'
    || typeof runMeituanOrderFetchFlow !== 'function'
    || typeof buildMeituanOrderDomCollectorScript !== 'function'
    || typeof parseMeituanOrderCsvText !== 'function'
    || typeof buildMeituanOrderCsvImportRequestBody !== 'function'
    || typeof runMeituanOrderCsvImportFlow !== 'function'
    || typeof normalizeMeituanAdsFetchForm !== 'function'
    || typeof validateMeituanAdsFetchInput !== 'function'
    || typeof buildMeituanAdsFetchRequestBody !== 'function'
    || typeof runMeituanAdsFetchFlow !== 'function'
    || typeof runMeituanManualTabSwitch !== 'function') {
    checks.push({
      file: 'public/meituan-static.js',
      label: 'Meituan static exports batch/browser/payload/traffic/order/ads/manual-tab builders',
      ok: false,
      detail: 'batch/browser/payload/traffic/order/ads/manual-tab builders and flow runners',
    });
  } else {
    const manualTabEvents = [];
    let activeManualTab = 'meituan-traffic';
    const manualTrafficResult = await runMeituanManualTabSwitch({
      tab: 'meituan-traffic',
      getCurrentPage: () => 'meituan-ebooking',
      getCurrentTab: () => activeManualTab,
      loadConfigList: async () => { manualTabEvents.push('load'); },
      syncTrafficConfig: async () => { manualTabEvents.push('traffic'); },
      syncOrderConfig: async () => { manualTabEvents.push('order'); },
      syncAdsConfig: async () => { manualTabEvents.push('ads'); },
      applyRankingConfig: async () => { manualTabEvents.push('ranking'); },
    });
    const staleManualEvents = [];
    let staleManualTab = 'meituan-orders';
    const staleManualResult = await runMeituanManualTabSwitch({
      tab: 'meituan-orders',
      getCurrentPage: () => 'meituan-ebooking',
      getCurrentTab: () => staleManualTab,
      loadConfigList: async () => {
        staleManualEvents.push('load');
        staleManualTab = 'meituan-ads';
      },
      syncOrderConfig: async () => { staleManualEvents.push('order'); },
    });
    checks.push({
      file: 'public/meituan-static.js',
      label: 'Meituan manual tab switch helper keeps async config loading scoped to the active tab',
      ok: manualTrafficResult.status === 'synced'
        && manualTrafficResult.target === 'traffic'
        && manualTabEvents.join('|') === 'load|traffic'
        && staleManualResult.status === 'stale_after_load'
        && staleManualEvents.join('|') === 'load',
      detail: 'runMeituanManualTabSwitch active/stale samples',
    });

    const csvText = '\uFEFF订单号,房型,入住日期,离店日期,购买时间,底价(元)\n"123456789012345","阳光双床房","2026-05-29","2026-05-30","2026-05-28 20:30","188.50"';
    const parsedCsvRows = parseMeituanOrderCsvText(csvText);
    const csvRequestBody = buildMeituanOrderCsvImportRequestBody({
      csvText,
      form: { poiId: 'poi-1', startDate: '2026-05-28', endDate: '2026-05-30' },
      systemHotelId: 7,
      hotelName: 'Demo Hotel',
    });
    const csvImportEvents = [];
    let csvFlowRequestBody = null;
    const csvFlowResult = await runMeituanOrderCsvImportFlow({
      getForm: () => ({ csvText, poiId: 'poi-1', startDate: '2026-05-28', endDate: '2026-05-30' }),
      getSystemHotelId: () => 7,
      getHotelNameById: () => 'Demo Hotel',
      notify: (message, level) => csvImportEvents.push(`notify:${level}:${message}`),
      setFetching: value => csvImportEvents.push(`fetching:${value}`),
      setOrderResult: value => csvImportEvents.push(`order:${value?.saved_count ?? 'none'}`),
      setOnlineDataResult: value => csvImportEvents.push(`online:${value?.row_count ?? 'none'}`),
      requestSave: async body => {
        csvFlowRequestBody = body;
        return { code: 200, data: { saved_count: 1, row_count: 1 } };
      },
      refreshOnlineHistory: async () => csvImportEvents.push('refresh'),
    });
    checks.push({
      file: 'public/meituan-static.js',
      label: 'Meituan order CSV import parses Tampermonkey export and saves through captured payload endpoint',
      ok: parsedCsvRows.length === 1
        && parsedCsvRows[0].orderNo === '123456789012345'
        && parsedCsvRows[0].roomType === '阳光双床房'
        && parsedCsvRows[0].bottomPrice === '188.50'
        && csvRequestBody.payload.orders.length === 1
        && csvRequestBody.payload.data_period === 'manual_dom_csv'
        && csvRequestBody.payload.system_hotel_id === 7
        && csvFlowResult.status === 'success'
        && csvFlowRequestBody?.payload?.orders?.[0]?.checkIn === '2026-05-29'
        && csvImportEvents.includes('fetching:true')
        && csvImportEvents.includes('fetching:false')
        && csvImportEvents.some(event => event.startsWith('notify:success:')),
      detail: 'parseMeituanOrderCsvText/buildMeituanOrderCsvImportRequestBody/runMeituanOrderCsvImportFlow sample',
    });

    const collectorScript = buildMeituanOrderDomCollectorScript();
    checks.push({
      file: 'public/meituan-static.js',
      label: 'Meituan order DOM collector script is generated from system UI without credentials',
      ok: collectorScript.includes('// ==UserScript==')
        && collectorScript.includes('@match        https://eb.meituan.com/ebooking/order-eb/*')
        && collectorScript.includes('@match        https://me.meituan.com/ebooking/merchant/ebIframe*')
        && collectorScript.includes("var headers = ['订单号', '房型', '入住日期', '离店日期', '购买时间', '底价(元)'];")
        && collectorScript.includes('function extractPageRows()')
        && collectorScript.includes("PANEL_ID = 'suxi-meituan-order-dom-panel'")
        && !/cookie|authorization|token|password/i.test(collectorScript),
      detail: 'buildMeituanOrderDomCollectorScript sample',
    });

    const tasks = buildMeituanBatchFetchTasks({
      form: {
        url: 'https://example.test/rank',
        hotelId: '10',
        dateRanges: ['1', 'custom'],
        startDate: '2026-06-01',
        endDate: '2026-06-10',
        auth_data: { token: 'demo' },
      },
      partnerId: 'partner-1',
      poiId: 'poi-1',
      cookies: 'mt-cookie',
    });
    const missingCookieValidation = validateMeituanBatchFetchInput({
      form: { hotelId: '10', dateRanges: ['1'] },
      cookies: '',
      partnerId: 'partner-1',
      poiId: 'poi-1',
    });
    const missingResourceValidation = validateMeituanBatchFetchInput({
      form: { hotelId: '10', dateRanges: ['1'] },
      cookies: 'mt-cookie',
      partnerId: '',
      poiId: '',
    });
    const missingCustomDateValidation = validateMeituanBatchFetchInput({
      form: { hotelId: '10', dateRanges: ['custom'], startDate: '', endDate: '' },
      cookies: 'mt-cookie',
      partnerId: 'partner-1',
      poiId: 'poi-1',
    });
    const validBatchInput = validateMeituanBatchFetchInput({
      form: { hotelId: '10', dateRanges: ['custom'], startDate: '2026-06-01', endDate: '2026-06-10' },
      cookies: ' mt-cookie ',
      partnerId: ' partner-1 ',
      poiId: ' poi-1 ',
    });
    const customTask = tasks.find(task => task.rankType === 'P_LL' && task.dateRange === 'custom');
    checks.push({
      file: 'public/meituan-static.js',
      label: 'Meituan batch fetch input validator keeps missing-state signals explicit',
      ok: missingCookieValidation.ok === false
        && missingCookieValidation.level === 'error'
        && missingCookieValidation.message.includes('临时 Cookie/API 辅助内容缺失')
        && missingResourceValidation.ok === false
        && missingResourceValidation.level === 'warning'
        && missingResourceValidation.message.includes('平台接口标识 / 平台门店标识')
        && missingCustomDateValidation.ok === false
        && missingCustomDateValidation.message.includes('自定义时间')
        && validBatchInput.ok === true
        && validBatchInput.cookies === 'mt-cookie'
        && validBatchInput.partnerId === 'partner-1'
        && validBatchInput.poiId === 'poi-1',
      detail: 'validateMeituanBatchFetchInput sample',
    });
    checks.push({
      file: 'public/meituan-static.js',
      label: 'Meituan batch fetch task builder covers four rank types and custom dates',
      ok: tasks.length === 8
        && tasks.some(task => task.rankType === 'P_RZ' && task.dateRange === '1')
        && tasks.some(task => task.rankType === 'P_ZH' && task.dateRange === 'custom')
        && customTask?.body?.start_date === '2026-06-01'
        && customTask?.body?.end_date === '2026-06-10'
        && customTask?.body?.partner_id === 'partner-1'
        && customTask?.body?.poi_id === 'poi-1'
        && customTask?.body?.cookies === 'mt-cookie'
        && customTask?.body?.system_hotel_id === '10',
      detail: 'buildMeituanBatchFetchTasks sample',
    });
    const successEntry = buildMeituanBatchFetchResultEntry(tasks[0], {
      code: 200,
      data: {
        data: [{ rank: 1 }],
        saved_count: 3,
        display_hotels: [{ poiId: 'poi-1', hotelName: 'Demo' }],
        display_summary: { total: 1 },
        display_hotel_count: 1,
      },
    });
    const failedEntry = buildMeituanBatchFetchResultEntry(tasks[1], { code: 500, message: 'upstream failed' });
    const modelPayload = buildMeituanDisplayModelPayload({
      results: [successEntry, failedEntry],
      form: {
        competitorRoomCount: '20',
        poiId: 'poi-1',
        dateRanges: ['1', 'custom'],
        startDate: '2026-06-01',
        endDate: '2026-06-10',
      },
    });
    const flowEvents = [];
    const flowStates = [];
    const requestedBodies = [];
    let flowOnlineResult = null;
    let flowBusinessSummary = null;
    let flowDisplayPayload = null;
    let flowSavedCount = 0;
    let flowFetchTime = '';
    const flowResult = await runMeituanBatchFetchFlow({
      getForm: () => ({
        url: 'https://example.test/rank',
        hotelId: '10',
        partnerId: 'partner-1',
        poiId: 'poi-1',
        cookies: ' mt-cookie ',
        dateRanges: ['1'],
        auth_data: { token: 'demo' },
        competitorRoomCount: '20',
      }),
      getSelectedConfig: () => ({
        hotel_id: '10',
        partner_id: 'partner-1',
        poi_id: 'poi-1',
        cookies: 'mt-cookie',
      }),
      ensureMeituanConfigSecret: async config => {
        flowEvents.push('ensure-config');
        return config;
      },
      applyMeituanHotelConfig: async showMessage => flowEvents.push(`apply:${showMessage}`),
      notify: (message, level) => flowEvents.push(`notify:${level || 'info'}:${message}`),
      setFetching: value => flowStates.push(`fetching:${value}`),
      setOnlineDataResult: value => { flowOnlineResult = value; },
      setFetchSuccess: value => flowStates.push(`success:${value}`),
      setHotelsList: value => flowStates.push(`hotels:${value.length}`),
      getEmptyBusinessSummary: () => ({ status: 'empty', metrics: {}, cards: [] }),
      setBusinessSummary: value => { flowBusinessSummary = value; },
      requestFetch: async body => {
        requestedBodies.push(body);
        return {
          code: 200,
          data: {
            data: [{ rank: 1, rankType: body.rank_type }],
            saved_count: 2,
            display_hotels: [{ poiId: body.poi_id, rankType: body.rank_type }],
            display_summary: { rankType: body.rank_type },
            display_hotel_count: 1,
          },
        };
      },
      requestDisplayModel: async payload => {
        flowDisplayPayload = payload;
        return { code: 200, data: { rows: [{ poiId: 'poi-1' }, { poiId: 'poi-2' }] } };
      },
      useDisplayModel: data => data.rows,
      setSavedCount: value => { flowSavedCount = value; },
      setDataFetchTime: value => { flowFetchTime = value; },
      getFetchTime: () => '2026-06-11 12:00:00',
      updateAiAnalysisHotelList: () => flowEvents.push('update-ai-hotels'),
      refreshOnlineHistory: async () => flowEvents.push('history'),
      getOnlineDataTab: () => 'data',
      refreshOnlineData: () => flowEvents.push('refresh-data'),
    });
    const acceptedMeituanEvents = [];
    const acceptedMeituanStates = [];
    const acceptedMeituanBodies = [];
    let acceptedMeituanOnlineResult = null;
    let acceptedMeituanSavedCount = -1;
    const acceptedMeituanResult = await runMeituanBatchFetchFlow({
      getForm: () => ({
        url: 'https://example.test/rank',
        hotelId: '10',
        partnerId: 'partner-1',
        poiId: 'poi-1',
        cookies: ' mt-cookie ',
        dateRanges: ['1'],
      }),
      getSelectedConfig: () => ({
        hotel_id: '10',
        partner_id: 'partner-1',
        poi_id: 'poi-1',
        cookies: 'mt-cookie',
      }),
      ensureMeituanConfigSecret: async config => config,
      applyMeituanHotelConfig: async showMessage => acceptedMeituanEvents.push(`apply:${showMessage}`),
      notify: (message, level) => acceptedMeituanEvents.push(`notify:${level || 'info'}:${message}`),
      setFetching: value => acceptedMeituanStates.push(`fetching:${value}`),
      setOnlineDataResult: value => { acceptedMeituanOnlineResult = value; },
      setFetchSuccess: value => acceptedMeituanStates.push(`success:${value}`),
      setHotelsList: value => acceptedMeituanStates.push(`hotels:${value.length}`),
      getEmptyBusinessSummary: () => ({ status: 'empty', metrics: {}, cards: [] }),
      setBusinessSummary: () => {},
      requestFetch: async body => {
        acceptedMeituanBodies.push(body);
        return {
          code: 200,
          message: 'queued',
          data: {
            status: 'running',
            task_id: 'mt-task-1',
            platform: 'meituan',
            async: true,
            saved_count: 0,
          },
        };
      },
      requestDisplayModel: async () => {
        throw new Error('display model should not run for unexpected background fetch');
      },
      setSavedCount: value => { acceptedMeituanSavedCount = value; },
      refreshOnlineHistory: async () => acceptedMeituanEvents.push('history'),
      getOnlineDataTab: () => 'data',
      refreshOnlineData: () => acceptedMeituanEvents.push('refresh-data'),
    });
    const guardEvents = [];
    const guardResult = await runMeituanBatchFetchFlow({
      getForm: () => ({ hotelId: '', dateRanges: ['1'] }),
      notify: (message, level) => guardEvents.push(`notify:${level}:${message}`),
    });
    checks.push({
      file: 'public/meituan-static.js',
      label: 'Meituan batch fetch result and display payload builders preserve response evidence',
      ok: successEntry.savedCount === 3
        && successEntry.displayCount === 1
        && failedEntry.error === 'upstream failed'
        && Array.isArray(modelPayload.display_hotels)
        && modelPayload.display_hotels.length === 1
        && modelPayload.target_poi_id === 'poi-1'
        && modelPayload.competitor_room_count === '20'
        && flowResult.status === 'success'
        && requestedBodies.length === 4
        && requestedBodies.every(body => body.partner_id === 'partner-1' && body.poi_id === 'poi-1' && body.cookies === 'mt-cookie' && body.async === false && body.background === false)
        && flowOnlineResult.length === requestedBodies.length
        && flowDisplayPayload.display_hotels.length === requestedBodies.length
        && flowDisplayPayload.target_poi_id === 'poi-1'
        && flowSavedCount === requestedBodies.length * 2
        && flowFetchTime === '2026-06-11 12:00:00'
        && flowBusinessSummary === null
        && flowStates[0] === 'fetching:true'
        && flowStates.includes('success:false')
        && flowStates.includes('success:true')
        && !flowStates.includes('hotels:0')
        && flowStates.at(-1) === 'fetching:false'
        && flowEvents.includes('ensure-config')
        && !flowEvents.includes('apply:false')
        && flowEvents.includes('update-ai-hotels')
        && flowEvents.includes('history')
        && flowEvents.includes('refresh-data')
        && flowEvents.some(event => event.startsWith('notify:info:') && event.includes(String(requestedBodies.length * 2)))
        && acceptedMeituanResult.status === 'accepted'
        && acceptedMeituanResult.acceptedCount === 4
        && acceptedMeituanBodies.length === acceptedMeituanResult.acceptedCount
        && acceptedMeituanBodies.every(body => body.async === false && body.background === false)
        && Array.isArray(acceptedMeituanOnlineResult)
        && acceptedMeituanOnlineResult.length === acceptedMeituanBodies.length
        && acceptedMeituanOnlineResult[0].status === 'running'
        && acceptedMeituanOnlineResult[0].taskId === 'mt-task-1'
        && acceptedMeituanSavedCount === 0
        && acceptedMeituanStates[0] === 'fetching:true'
        && acceptedMeituanStates.includes('success:false')
        && acceptedMeituanStates.includes('success:true')
        && !acceptedMeituanStates.includes('hotels:0')
        && acceptedMeituanStates.at(-1) === 'fetching:false'
        && acceptedMeituanEvents.includes('history')
        && acceptedMeituanEvents.includes('refresh-data')
        && acceptedMeituanEvents.some(event => event.startsWith('notify:info:'))
        && guardResult.status === 'missing_hotel'
        && guardEvents[0]?.startsWith('notify:error:'),
      detail: 'Meituan batch result sample',
    });

    const supplementModules = getMeituanBrowserCaptureSupplementModules();
    const supplementCounts = buildMeituanBrowserCaptureSupplementCounts({
      payload_counts: {
        peer_rank: 2,
        traffic_analysis: 3,
        search_keywords: 4,
        traffic_forecast: 5,
        responses: 9,
      },
    });
    const supplementCountsFromPayload = buildMeituanBrowserCaptureSupplementCounts({
      payload: {
        peerRank: [{}, {}],
        flowAnalysis: [{}],
        searchKeywords: [{}, {}, {}],
        trafficForecast: [{}],
        responses: [{}, {}, {}, {}],
      },
    });
    checks.push({
      file: 'public/meituan-static.js',
      label: 'Meituan browser capture supplemental modules and counts are displayable',
      ok: Array.isArray(supplementModules)
        && supplementModules.length === 4
        && supplementModules.some(item => item.key === 'peer_rank' && item.label === '同行排名')
        && supplementCounts.find(item => item.key === 'peer_rank')?.count === 2
        && supplementCounts.find(item => item.key === 'traffic_analysis')?.count === 3
        && supplementCounts.find(item => item.key === 'search_keywords')?.count === 4
        && supplementCounts.find(item => item.key === 'traffic_forecast')?.count === 5
        && supplementCounts.find(item => item.key === 'responses')?.count === 9
        && supplementCountsFromPayload.find(item => item.key === 'peer_rank')?.count === 2
        && supplementCountsFromPayload.find(item => item.key === 'search_keywords')?.count === 3,
      detail: 'buildMeituanBrowserCaptureSupplementCounts sample',
    });

    const browserMissingHotel = buildMeituanBrowserCaptureRequestContext({
      form: { storeId: 'store-1' },
      systemHotelId: null,
    });
    const browserMissingStore = buildMeituanBrowserCaptureRequestContext({
      form: {},
      systemHotelId: '10',
    });
    const browserMissingAdsUrl = buildMeituanBrowserCaptureRequestContext({
      form: { storeId: 'store-1', captureSections: ['ads'], adsUrl: '' },
      systemHotelId: '10',
    });
    const browserRequestContext = buildMeituanBrowserCaptureRequestContext({
      form: {
        storeId: ' store-10 ',
        poiId: 'poi-10',
        poiName: 'POI Demo',
        adsUrl: 'https://ads.example.test',
        captureSections: 'traffic ads',
      },
      systemHotelId: '10',
      fallbackPoiId: 'poi-fallback',
      partnerId: 'partner-10',
      hotelName: 'Hotel 10',
      options: { loginOnly: true, bindDataSource: false },
    });
    const browserSupplementRequestContext = buildMeituanBrowserCaptureRequestContext({
      form: {
        storeId: 'store-supplement',
        captureSections: 'peerRank flowForecast searchKeywords flowAnalysis',
      },
      systemHotelId: '10',
    });
    checks.push({
      file: 'public/meituan-static.js',
      label: 'Meituan browser capture request context keeps missing states explicit',
      ok: browserMissingHotel.status === 'missing_hotel'
        && browserMissingHotel.message === '请选择目标酒店'
        && browserMissingStore.status === 'missing_store_id'
        && browserMissingStore.message === '请填写美团门店标识'
        && browserMissingAdsUrl.status === 'missing_ads_url'
        && browserMissingAdsUrl.message === '请填写推广通广告入口 URL'
        && browserRequestContext.ok === true
        && browserRequestContext.requestBody.system_hotel_id === '10'
        && browserRequestContext.requestBody.store_id === 'store-10'
        && browserRequestContext.requestBody.poi_id === 'poi-10'
        && browserRequestContext.requestBody.poi_name === 'POI Demo'
        && browserRequestContext.requestBody.partner_id === 'partner-10'
        && browserRequestContext.requestBody.ads_url === 'https://ads.example.test'
        && browserRequestContext.requestBody.sections.join(',') === 'traffic,ads'
        && browserSupplementRequestContext.ok === true
        && browserSupplementRequestContext.requestBody.sections.join(',') === 'traffic'
        && browserRequestContext.requestBody.login_only === true
        && browserRequestContext.requestBody.bind_data_source === false,
      detail: 'buildMeituanBrowserCaptureRequestContext sample',
    });

    const browserEvents = [];
    const browserStates = [];
    let browserCapturePayload = null;
    let browserOnlinePayload = null;
    let browserRequestedBody = null;
    const browserFlowResult = await runMeituanBrowserCaptureFlow({
      getForm: () => ({
        storeId: ' store-20 ',
        poiId: 'poi-20',
        poiName: 'POI 20',
        adsUrl: 'https://ads.example.test/20',
        captureSections: ['traffic', 'ads'],
      }),
      getSystemHotelId: () => '20',
      getFallbackPoiId: () => 'poi-fallback',
      getPartnerId: () => 'partner-20',
      getHotelNameById: id => `Hotel ${id}`,
      notify: (message, level) => browserEvents.push(`notify:${level || 'info'}:${message}`),
      setRunning: value => browserStates.push(`running:${value}`),
      setFetching: value => browserStates.push(`fetching:${value}`),
      setCaptureResult: value => { browserCapturePayload = value; },
      setOnlineDataResult: value => { browserOnlinePayload = value; },
      requestCapture: async body => {
        browserRequestedBody = body;
        return { code: 200, message: 'capture ok', data: { saved_count: 9, rows: [{ id: 1 }] } };
      },
      refreshOnlineHistory: async () => browserEvents.push('history'),
      refreshPlatformProfileStatus: async params => browserEvents.push(`profile-status:${params.silent}`),
      refreshPlatformDataSources: async () => browserEvents.push('data-sources'),
    });
    const browserLoginEvents = [];
    const browserLoginResult = await runMeituanBrowserCaptureFlow({
      getForm: () => ({ storeId: 'store-login', captureSections: ['traffic'] }),
      getSystemHotelId: () => '30',
      getFallbackPoiId: () => '',
      getPartnerId: () => '',
      getHotelNameById: id => `Hotel ${id}`,
      options: { loginOnly: true, bindDataSource: false },
      notify: (message, level) => browserLoginEvents.push(`notify:${level || 'info'}:${message}`),
      requestCapture: async () => ({ code: 200, data: { profile_saved: true } }),
      refreshOnlineHistory: async () => browserLoginEvents.push('history'),
      refreshPlatformProfileStatus: async params => browserLoginEvents.push(`profile-status:${params.silent}`),
      refreshPlatformDataSources: async () => browserLoginEvents.push('data-sources'),
    });
    const browserFailedEvents = [];
    const browserFailedStates = [];
    const browserFailedResult = await runMeituanBrowserCaptureFlow({
      getForm: () => ({ storeId: 'store-failed', captureSections: ['traffic'] }),
      getSystemHotelId: () => '40',
      notify: (message, level) => browserFailedEvents.push(`notify:${level}:${message}`),
      setRunning: value => browserFailedStates.push(`running:${value}`),
      setFetching: value => browserFailedStates.push(`fetching:${value}`),
      requestCapture: async () => ({ code: 500, message: 'browser backend failed' }),
    });
    const browserExceptionEvents = [];
    const browserExceptionStates = [];
    let browserExceptionPayload = null;
    const browserExceptionResult = await runMeituanBrowserCaptureFlow({
      getForm: () => ({ storeId: 'store-exception', captureSections: ['traffic'] }),
      getSystemHotelId: () => '50',
      notify: (message, level) => browserExceptionEvents.push(`notify:${level}:${message}`),
      setRunning: value => browserExceptionStates.push(`running:${value}`),
      setFetching: value => browserExceptionStates.push(`fetching:${value}`),
      setCaptureResult: value => { browserExceptionPayload = value; },
      requestCapture: async () => {
        const error = new Error('network down');
        error.data = { data: { stderr: 'stderr details', partial_capture: { saved_count: 1 } } };
        throw error;
      },
    });
    checks.push({
      file: 'public/meituan-static.js',
      label: 'Meituan browser capture flow preserves success, login, failed and exception states',
      ok: browserFlowResult.status === 'success'
        && browserRequestedBody.system_hotel_id === '20'
        && browserRequestedBody.store_id === 'store-20'
        && browserRequestedBody.poi_id === 'poi-20'
        && browserRequestedBody.poi_name === 'POI 20'
        && browserRequestedBody.partner_id === 'partner-20'
        && browserRequestedBody.ads_url === 'https://ads.example.test/20'
        && browserRequestedBody.sections.join(',') === 'traffic,ads'
        && browserCapturePayload.saved_count === 9
        && browserOnlinePayload.saved_count === 9
        && browserStates.join('|') === 'running:true|fetching:true|running:false|fetching:false'
        && browserEvents.includes('history')
        && browserEvents.includes('profile-status:true')
        && browserEvents.includes('data-sources')
        && browserEvents.includes('notify:info:capture ok')
        && browserLoginResult.status === 'success'
        && browserLoginEvents.includes('profile-status:true')
        && !browserLoginEvents.includes('history')
        && !browserLoginEvents.includes('data-sources')
        && browserLoginEvents.some(event => event.includes('美团 Profile 登录状态已保存'))
        && browserFailedResult.status === 'failed'
        && browserFailedEvents[0] === 'notify:error:browser backend failed'
        && browserFailedStates.join('|') === 'running:true|fetching:true|running:false|fetching:false'
        && browserExceptionResult.status === 'exception'
        && browserExceptionEvents[0] === 'notify:error:抓取失败: network down，请查看结果详情'
        && browserExceptionPayload.stderr === 'stderr details'
        && browserExceptionStates.join('|') === 'running:true|fetching:true|running:false|fetching:false',
      detail: 'runMeituanBrowserCaptureFlow state samples',
    });

    const payloadMissingHotel = buildMeituanCapturedPayloadSaveContext({
      form: { payloadJson: '{}' },
      systemHotelId: null,
    });
    const payloadMissingJson = buildMeituanCapturedPayloadSaveContext({
      form: { payloadJson: '' },
      systemHotelId: '10',
    });
    const payloadInvalidJson = buildMeituanCapturedPayloadSaveContext({
      form: { payloadJson: '{bad json' },
      systemHotelId: '10',
    });
    const payloadInvalidObject = buildMeituanCapturedPayloadSaveContext({
      form: { payloadJson: '[]' },
      systemHotelId: '10',
    });
    const payloadSaveContext = buildMeituanCapturedPayloadSaveContext({
      form: {
        payloadJson: '{"source":"browser","saved_count":2}',
        storeId: ' store-60 ',
        poiId: 'poi-60',
        poiName: 'POI 60',
      },
      systemHotelId: '60',
      hotelName: 'Hotel 60',
    });
    checks.push({
      file: 'public/meituan-static.js',
      label: 'Meituan captured payload save context keeps JSON and target gaps explicit',
      ok: payloadMissingHotel.status === 'missing_hotel'
        && payloadMissingHotel.message === '请选择目标酒店'
        && payloadMissingJson.status === 'missing_payload_json'
        && payloadMissingJson.message === '请粘贴抓取结果 JSON'
        && payloadInvalidJson.status === 'invalid_json'
        && payloadInvalidJson.message.includes('抓取结果 JSON 格式不正确')
        && payloadInvalidObject.status === 'invalid_payload_object'
        && payloadInvalidObject.message === '抓取结果必须是 JSON 对象'
        && payloadSaveContext.ok === true
        && payloadSaveContext.requestBody.system_hotel_id === '60'
        && payloadSaveContext.requestBody.payload.store_id === 'store-60'
        && payloadSaveContext.requestBody.payload.poi_id === 'poi-60'
        && payloadSaveContext.requestBody.payload.poi_name === 'POI 60'
        && payloadSaveContext.requestBody.payload.system_hotel_id === 60
        && payloadSaveContext.requestBody.payload.source === 'browser',
      detail: 'buildMeituanCapturedPayloadSaveContext sample',
    });

    const payloadEvents = [];
    const payloadStates = [];
    let payloadRequestedBody = null;
    let payloadCaptureResult = null;
    let payloadOnlineResult = null;
    const payloadFlowResult = await runMeituanCapturedPayloadSaveFlow({
      getForm: () => ({
        payloadJson: '{"rooms":[{"id":1}]}',
        storeId: ' store-70 ',
        poiId: 'poi-70',
        poiName: '',
      }),
      getSystemHotelId: () => '70',
      getHotelNameById: id => `Hotel ${id}`,
      notify: (message, level) => payloadEvents.push(`notify:${level || 'info'}:${message}`),
      setFetching: value => payloadStates.push(`fetching:${value}`),
      setCaptureResult: value => { payloadCaptureResult = value; },
      setOnlineDataResult: value => { payloadOnlineResult = value; },
      requestSave: async body => {
        payloadRequestedBody = body;
        return { code: 200, data: { saved_count: 4, rows: [{ id: 1 }] } };
      },
      refreshOnlineHistory: async () => payloadEvents.push('history'),
    });
    const payloadFailedEvents = [];
    const payloadFailedStates = [];
    const payloadFailedResult = await runMeituanCapturedPayloadSaveFlow({
      getForm: () => ({ payloadJson: '{}', storeId: 'store-failed' }),
      getSystemHotelId: () => '80',
      notify: (message, level) => payloadFailedEvents.push(`notify:${level}:${message}`),
      setFetching: value => payloadFailedStates.push(`fetching:${value}`),
      requestSave: async () => ({ code: 500, message: 'save backend failed' }),
    });
    const payloadExceptionEvents = [];
    const payloadExceptionStates = [];
    const payloadExceptionResult = await runMeituanCapturedPayloadSaveFlow({
      getForm: () => ({ payloadJson: '{}', storeId: 'store-exception' }),
      getSystemHotelId: () => '90',
      notify: (message, level) => payloadExceptionEvents.push(`notify:${level}:${message}`),
      setFetching: value => payloadExceptionStates.push(`fetching:${value}`),
      requestSave: async () => {
        throw new Error('save network down');
      },
    });
    const payloadGuardEvents = [];
    const payloadGuardResult = await runMeituanCapturedPayloadSaveFlow({
      getForm: () => ({ payloadJson: '' }),
      getSystemHotelId: () => '100',
      notify: (message, level) => payloadGuardEvents.push(`notify:${level}:${message}`),
      setFetching: value => payloadGuardEvents.push(`fetching:${value}`),
    });
    checks.push({
      file: 'public/meituan-static.js',
      label: 'Meituan captured payload save flow preserves success, failed and exception states',
      ok: payloadFlowResult.status === 'success'
        && payloadRequestedBody.system_hotel_id === '70'
        && payloadRequestedBody.payload.store_id === 'store-70'
        && payloadRequestedBody.payload.poi_id === 'poi-70'
        && payloadRequestedBody.payload.poi_name === 'Hotel 70'
        && payloadRequestedBody.payload.system_hotel_id === 70
        && payloadCaptureResult.saved_count === 4
        && payloadOnlineResult.saved_count === 4
        && payloadEvents.includes('notify:info:保存成功，已入库 4 条')
        && payloadEvents.includes('history')
        && payloadStates.join('|') === 'fetching:true|fetching:false'
        && payloadFailedResult.status === 'failed'
        && payloadFailedEvents[0] === 'notify:error:save backend failed'
        && payloadFailedStates.join('|') === 'fetching:true|fetching:false'
        && payloadExceptionResult.status === 'exception'
        && payloadExceptionEvents[0] === 'notify:error:保存失败: save network down'
        && payloadExceptionStates.join('|') === 'fetching:true|fetching:false'
        && payloadGuardResult.status === 'missing_payload_json'
        && payloadGuardEvents.join('|') === 'notify:error:请粘贴抓取结果 JSON',
      detail: 'runMeituanCapturedPayloadSaveFlow state samples',
    });

    const trafficForm = {
      url: ' https://example.test/traffic ',
      partnerId: ' partner-traffic ',
      poiId: ' poi-traffic ',
      startDate: '2026-06-01',
      endDate: '2026-06-10',
      cookies: '\nmt-traffic-cookie\n',
      extraParams: 'scope=traffic',
    };
    const normalizedTrafficForm = normalizeMeituanTrafficFetchForm(trafficForm);
    const missingTrafficUrl = validateMeituanTrafficFetchInput({ url: '', partnerId: 'p', poiId: 'poi', cookies: 'cookie' });
    const missingTrafficPartner = validateMeituanTrafficFetchInput({ url: 'https://example.test/traffic', partnerId: '', poiId: 'poi', cookies: 'cookie' });
    const missingTrafficPoi = validateMeituanTrafficFetchInput({ url: 'https://example.test/traffic', partnerId: 'p', poiId: '', cookies: 'cookie' });
    const missingTrafficCookie = validateMeituanTrafficFetchInput({ url: 'https://example.test/traffic', partnerId: 'p', poiId: 'poi', cookies: '' });
    const trafficRequestBody = buildMeituanTrafficFetchRequestBody({
      form: normalizedTrafficForm,
      systemHotelId: '10',
    });
    checks.push({
      file: 'public/meituan-static.js',
      label: 'Meituan traffic fetch input and request builder keep missing states explicit',
      ok: normalizedTrafficForm.url === 'https://example.test/traffic'
        && normalizedTrafficForm.partnerId === 'partner-traffic'
        && normalizedTrafficForm.poiId === 'poi-traffic'
        && normalizedTrafficForm.cookies === 'mt-traffic-cookie'
        && normalizedTrafficForm.extraParams === 'scope=traffic'
        && missingTrafficUrl.status === 'missing_url'
        && missingTrafficPartner.status === 'missing_partner_id'
        && missingTrafficPoi.status === 'missing_poi_id'
        && missingTrafficCookie.status === 'missing_cookies'
        && trafficRequestBody.partner_id === 'partner-traffic'
        && trafficRequestBody.poi_id === 'poi-traffic'
        && trafficRequestBody.auto_save === true
        && trafficRequestBody.system_hotel_id === '10'
        && trafficRequestBody.extra_params === 'scope=traffic',
      detail: 'buildMeituanTrafficFetchRequestBody sample',
    });

    const trafficEvents = [];
    const trafficStates = [];
    let trafficOnlinePayload = null;
    let trafficLatestPayload = null;
    let trafficRequestedBody = null;
    let delayedTrafficHistorySettled = false;
    const trafficFlowResult = await runMeituanTrafficFetchFlow({
      getForm: () => ({
        url: ' https://example.test/traffic ',
        partnerId: ' partner-flow ',
        poiId: ' poi-flow ',
        cookies: ' mt-traffic-flow-cookie ',
        startDate: '2026-06-02',
        endDate: '2026-06-03',
        extraParams: '{"scope":"flow"}',
      }),
      getSystemHotelId: () => '20',
      notify: (message, level) => trafficEvents.push(`notify:${level || 'info'}:${message}`),
      setFetching: value => trafficStates.push(`fetching:${value}`),
      setOnlineDataResult: value => { trafficOnlinePayload = value; },
      setLatestTrafficData: value => { trafficLatestPayload = value; },
      requestFetch: async body => {
        trafficRequestedBody = body;
        return { code: 200, data: { data: [{ exposure: 10 }], saved_count: 6 } };
      },
      refreshOnlineHistory: async () => trafficEvents.push('history'),
      getOnlineDataTab: () => 'data',
      refreshOnlineData: () => trafficEvents.push('refresh-data'),
    });
    const acceptedTrafficEvents = [];
    const acceptedTrafficStates = [];
    let acceptedTrafficOnlinePayload = null;
    let acceptedTrafficLatestPayload = null;
    let acceptedTrafficRequestedBody = null;
    const acceptedTrafficResult = await runMeituanTrafficFetchFlow({
      getForm: () => ({
        url: 'https://example.test/traffic',
        partnerId: 'partner-flow',
        poiId: 'poi-flow',
        cookies: 'mt-traffic-flow-cookie',
        startDate: '2026-06-02',
        endDate: '2026-06-03',
      }),
      getSystemHotelId: () => '20',
      notify: (message, level) => acceptedTrafficEvents.push(`notify:${level || 'info'}:${message}`),
      setFetching: value => acceptedTrafficStates.push(`fetching:${value}`),
      setOnlineDataResult: value => { acceptedTrafficOnlinePayload = value; },
      setLatestTrafficData: value => { acceptedTrafficLatestPayload = value; },
      requestFetch: async body => {
        acceptedTrafficRequestedBody = body;
        return {
          code: 200,
          message: 'traffic queued',
          data: {
            status: 'running',
            task_id: 'mt-traffic-task-1',
            platform: 'meituan',
            async: true,
            saved_count: 0,
            request_start_date: '2026-06-02',
            request_end_date: '2026-06-03',
          },
        };
      },
      refreshOnlineHistory: async () => acceptedTrafficEvents.push('history'),
      getOnlineDataTab: () => 'data',
      refreshOnlineData: () => acceptedTrafficEvents.push('refresh-data'),
    });
    const delayedTrafficFlowResult = await runMeituanTrafficFetchFlow({
      getForm: () => ({
        url: 'https://example.test/traffic',
        partnerId: 'partner-flow',
        poiId: 'poi-flow',
        cookies: 'mt-traffic-flow-cookie',
      }),
      setFetching: value => trafficEvents.push(`delayed-fetching:${value}`),
      setOnlineDataResult: () => {},
      setLatestTrafficData: () => {},
      requestFetch: async () => ({ code: 200, data: { data: [{ exposure: 1 }], saved_count: 1 } }),
      refreshOnlineHistory: () => new Promise(resolve => {
        setTimeout(() => {
          delayedTrafficHistorySettled = true;
          trafficEvents.push('delayed-history');
          resolve();
        }, 25);
      }),
      getOnlineDataTab: () => 'data',
      refreshOnlineData: () => trafficEvents.push('delayed-refresh-data'),
    });
    const delayedTrafficReturnedBeforeHistory = !delayedTrafficHistorySettled;
    const missingTrafficEvents = [];
    const missingTrafficResult = await runMeituanTrafficFetchFlow({
      getForm: () => ({ url: '', partnerId: 'p', poiId: 'poi', cookies: 'cookie' }),
      notify: (message, level) => missingTrafficEvents.push(`notify:${level}:${message}`),
      setFetching: value => missingTrafficEvents.push(`fetching:${value}`),
    });
    const failedTrafficEvents = [];
    const failedTrafficStates = [];
    const failedTrafficResult = await runMeituanTrafficFetchFlow({
      getForm: () => ({ url: 'https://example.test/traffic', partnerId: 'p', poiId: 'poi', cookies: 'cookie' }),
      notify: (message, level) => failedTrafficEvents.push(`notify:${level}:${message}`),
      setFetching: value => failedTrafficStates.push(`fetching:${value}`),
      requestFetch: async () => ({ code: 500, message: 'traffic backend failed' }),
    });
    const exceptionTrafficEvents = [];
    const exceptionTrafficStates = [];
    const exceptionTrafficResult = await runMeituanTrafficFetchFlow({
      getForm: () => ({ url: 'https://example.test/traffic', partnerId: 'p', poiId: 'poi', cookies: 'cookie' }),
      notify: (message, level) => exceptionTrafficEvents.push(`notify:${level}:${message}`),
      setFetching: value => exceptionTrafficStates.push(`fetching:${value}`),
      requestFetch: async () => {
        throw new Error('network down');
      },
    });
    checks.push({
      file: 'public/meituan-static.js',
      label: 'Meituan traffic fetch flow preserves success, failed and exception states',
      ok: trafficFlowResult.status === 'success'
        && trafficRequestedBody.partner_id === 'partner-flow'
        && trafficRequestedBody.async === false
        && trafficRequestedBody.background === false
        && trafficRequestedBody.poi_id === 'poi-flow'
        && trafficRequestedBody.cookies === 'mt-traffic-flow-cookie'
        && trafficRequestedBody.system_hotel_id === '20'
        && trafficOnlinePayload[0].exposure === 10
        && trafficLatestPayload[0].exposure === 10
        && trafficStates.join('|') === 'fetching:true|fetching:false'
        && trafficEvents.includes('history')
        && trafficEvents.includes('refresh-data')
        && delayedTrafficFlowResult.status === 'success'
        && delayedTrafficReturnedBeforeHistory === true
        && trafficEvents.some(event => event === 'notify:info:获取成功！已保存 6 条流量数据')
        && acceptedTrafficResult.status === 'accepted'
        && acceptedTrafficRequestedBody.async === false
        && acceptedTrafficRequestedBody.background === false
        && acceptedTrafficOnlinePayload.status === 'running'
        && acceptedTrafficOnlinePayload.task_id === 'mt-traffic-task-1'
        && acceptedTrafficLatestPayload.status === 'running'
        && acceptedTrafficEvents.includes('history')
        && acceptedTrafficEvents.includes('refresh-data')
        && acceptedTrafficEvents.includes('notify:info:traffic queued')
        && acceptedTrafficStates.join('|') === 'fetching:true|fetching:false'
        && missingTrafficResult.status === 'missing_url'
        && missingTrafficEvents[0] === 'notify:error:需 Network 请求信息：请输入接口地址'
        && !missingTrafficEvents.some(event => event.startsWith('fetching:'))
        && failedTrafficResult.status === 'failed'
        && failedTrafficEvents[0] === 'notify:error:traffic backend failed'
        && failedTrafficStates.join('|') === 'fetching:true|fetching:false'
        && exceptionTrafficResult.status === 'exception'
        && exceptionTrafficEvents[0] === 'notify:error:请求失败: network down'
        && exceptionTrafficStates.join('|') === 'fetching:true|fetching:false',
      detail: 'runMeituanTrafficFetchFlow state samples',
    });

    const orderForm = {
      url: ' https://example.test/orders/list ',
      method: 'post',
      partnerId: ' partner-10 ',
      poiId: ' poi-10 ',
      startDate: '2026-06-01',
      endDate: '2026-06-10',
      cookies: '\nmt-cookie\n',
      payloadJson: ' {"pageNo":1} ',
      extraParams: ' {"pageSize":50} ',
    };
    const normalizedOrderForm = normalizeMeituanOrderFetchForm(orderForm);
    const missingOrderUrl = validateMeituanOrderFetchInput({ url: '', method: 'GET', partnerId: 'p', poiId: 'poi', cookies: 'cookie' });
    const invalidOrderPageUrl = validateMeituanOrderFetchInput({ url: 'https://eb.meituan.com/order-eb/index.html', method: 'GET', partnerId: 'p', poiId: 'poi', cookies: 'cookie' });
    const missingOrderCookie = validateMeituanOrderFetchInput({ url: 'https://example.test/orders/list', method: 'GET', partnerId: 'p', poiId: 'poi', cookies: '' });
    const orderRequestBody = buildMeituanOrderFetchRequestBody({
      form: normalizedOrderForm,
      systemHotelId: '10',
      hotelName: 'Meituan Demo',
    });
    checks.push({
      file: 'public/meituan-static.js',
      label: 'Meituan order fetch input and request builder keep missing states explicit',
      ok: normalizedOrderForm.url === 'https://example.test/orders/list'
        && normalizedOrderForm.method === 'POST'
        && normalizedOrderForm.partnerId === 'partner-10'
        && normalizedOrderForm.poiId === 'poi-10'
        && normalizedOrderForm.cookies === 'mt-cookie'
        && normalizedOrderForm.payloadJson === '{"pageNo":1}'
        && normalizedOrderForm.extraParams === '{"pageSize":50}'
        && missingOrderUrl.status === 'missing_url'
        && invalidOrderPageUrl.status === 'invalid_page_url'
        && missingOrderCookie.status === 'missing_cookies'
        && orderRequestBody.partner_id === 'partner-10'
        && orderRequestBody.poi_id === 'poi-10'
        && orderRequestBody.auto_save === true
        && orderRequestBody.system_hotel_id === '10'
        && orderRequestBody.hotel_name === 'Meituan Demo',
      detail: 'buildMeituanOrderFetchRequestBody sample',
    });

    const orderEvents = [];
    const orderStates = [];
    let orderResultPayload = null;
    let orderOnlinePayload = null;
    let orderRequestedBody = null;
    const orderFlowResult = await runMeituanOrderFetchFlow({
      getForm: () => ({
        url: ' https://example.test/orders/list ',
        method: 'get',
        partnerId: ' partner-20 ',
        poiId: ' poi-20 ',
        cookies: ' mt-cookie-20 ',
        startDate: '2026-06-02',
        endDate: '2026-06-03',
        payloadJson: ' {"pageNo":2} ',
        extraParams: '',
      }),
      getSystemHotelId: () => '20',
      getHotelNameById: id => `Hotel ${id}`,
      notify: (message, level) => orderEvents.push(`notify:${level || 'info'}:${message}`),
      setFetching: value => orderStates.push(`fetching:${value}`),
      setOrderResult: value => { orderResultPayload = value; },
      setOnlineDataResult: value => { orderOnlinePayload = value; },
      requestFetch: async body => {
        orderRequestedBody = body;
        return { code: 200, data: { saved_count: 4, row_count: 6 } };
      },
      refreshOnlineHistory: async () => orderEvents.push('history'),
    });
    const acceptedOrderEvents = [];
    const acceptedOrderStates = [];
    let acceptedOrderResultPayload = null;
    let acceptedOrderOnlinePayload = null;
    let acceptedOrderRequestedBody = null;
    const acceptedOrderResult = await runMeituanOrderFetchFlow({
      getForm: () => ({
        url: 'https://example.test/orders/list',
        method: 'get',
        partnerId: 'partner-20',
        poiId: 'poi-20',
        cookies: 'mt-cookie-20',
        startDate: '2026-06-02',
        endDate: '2026-06-03',
      }),
      getSystemHotelId: () => '20',
      getHotelNameById: id => `Hotel ${id}`,
      notify: (message, level) => acceptedOrderEvents.push(`notify:${level || 'info'}:${message}`),
      setFetching: value => acceptedOrderStates.push(`fetching:${value}`),
      setOrderResult: value => { acceptedOrderResultPayload = value; },
      setOnlineDataResult: value => { acceptedOrderOnlinePayload = value; },
      requestFetch: async body => {
        acceptedOrderRequestedBody = body;
        return {
          code: 200,
          message: 'order queued',
          data: {
            status: 'running',
            task_id: 'mt-order-task-1',
            platform: 'meituan',
            async: true,
            saved_count: 0,
            request_start_date: '2026-06-02',
            request_end_date: '2026-06-03',
          },
        };
      },
      refreshOnlineHistory: async () => acceptedOrderEvents.push('history'),
    });
    const missingOrderEvents = [];
    const missingOrderResult = await runMeituanOrderFetchFlow({
      getForm: () => ({ url: '', partnerId: 'p', poiId: 'poi', cookies: 'cookie' }),
      notify: (message, level) => missingOrderEvents.push(`notify:${level}:${message}`),
      setFetching: value => missingOrderEvents.push(`fetching:${value}`),
    });
    const failedOrderStates = [];
    const failedOrderEvents = [];
    const failedOrderResult = await runMeituanOrderFetchFlow({
      getForm: () => ({ url: 'https://example.test/orders/list', partnerId: 'p', poiId: 'poi', cookies: 'cookie' }),
      notify: (message, level) => failedOrderEvents.push(`notify:${level}:${message}`),
      setFetching: value => failedOrderStates.push(`fetching:${value}`),
      requestFetch: async () => ({ code: 500, message: 'order backend failed' }),
    });
    const exceptionOrderStates = [];
    const exceptionOrderEvents = [];
    const exceptionOrderResult = await runMeituanOrderFetchFlow({
      getForm: () => ({ url: 'https://example.test/orders/list', partnerId: 'p', poiId: 'poi', cookies: 'cookie' }),
      notify: (message, level) => exceptionOrderEvents.push(`notify:${level}:${message}`),
      setFetching: value => exceptionOrderStates.push(`fetching:${value}`),
      requestFetch: async () => {
        throw new Error('network down');
      },
    });
    checks.push({
      file: 'public/meituan-static.js',
      label: 'Meituan order fetch flow preserves success, failed and exception states',
      ok: orderFlowResult.status === 'success'
        && orderRequestedBody.partner_id === 'partner-20'
        && orderRequestedBody.async === false
        && orderRequestedBody.background === false
        && orderRequestedBody.method === 'GET'
        && orderRequestedBody.hotel_name === 'Hotel 20'
        && orderResultPayload.saved_count === 4
        && orderOnlinePayload.row_count === 6
        && orderStates.join('|') === 'fetching:true|fetching:false'
        && orderEvents.includes('history')
        && orderEvents.some(event => event === 'notify:success:订单数据获取成功，已入库 4 条')
        && acceptedOrderResult.status === 'accepted'
        && acceptedOrderRequestedBody.async === false
        && acceptedOrderRequestedBody.background === false
        && acceptedOrderResultPayload.status === 'running'
        && acceptedOrderResultPayload.task_id === 'mt-order-task-1'
        && acceptedOrderOnlinePayload.status === 'running'
        && acceptedOrderEvents.includes('history')
        && acceptedOrderEvents.includes('notify:info:order queued')
        && acceptedOrderStates.join('|') === 'fetching:true|fetching:false'
        && missingOrderResult.status === 'missing_url'
        && missingOrderEvents[0] === 'notify:error:需 Network 请求信息：请填写订单接口 Request URL'
        && !missingOrderEvents.some(event => event.startsWith('fetching:'))
        && failedOrderResult.status === 'failed'
        && failedOrderEvents[0] === 'notify:error:order backend failed'
        && failedOrderStates.join('|') === 'fetching:true|fetching:false'
        && exceptionOrderResult.status === 'exception'
        && exceptionOrderEvents[0] === 'notify:error:订单数据获取失败: network down'
        && exceptionOrderStates.join('|') === 'fetching:true|fetching:false',
      detail: 'runMeituanOrderFetchFlow state samples',
    });

    const adsForm = {
      url: ' https://example.test/cureShops ',
      method: 'post',
      partnerId: ' partner-30 ',
      poiId: ' poi-30 ',
      shopId: ' shop-30 ',
      startDate: '2026-06-04',
      endDate: '2026-06-05',
      cookies: '\nmt-ads-cookie\n',
      payloadJson: ' {"timeUnit":"day"} ',
      extraParams: ' {"scope":"campaign"} ',
    };
    const normalizedAdsForm = normalizeMeituanAdsFetchForm(adsForm);
    const missingAdsUrl = validateMeituanAdsFetchInput({ url: '', shopId: 'shop', cookies: 'cookie' });
    const invalidAdsPageUrl = validateMeituanAdsFetchInput({ url: 'https://ebmidas.dianping.com/shopdiy/account/pcCpcEntry', shopId: 'shop', cookies: 'cookie' });
    const missingAdsTarget = validateMeituanAdsFetchInput({ url: 'https://example.test/cureShops', shopId: '', poiId: '', cookies: 'cookie' });
    const missingAdsCookie = validateMeituanAdsFetchInput({ url: 'https://example.test/cureShops', shopId: 'shop', cookies: '' });
    const adsRequestBody = buildMeituanAdsFetchRequestBody({
      form: normalizedAdsForm,
      systemHotelId: '30',
      hotelName: 'Ads Hotel',
    });
    checks.push({
      file: 'public/meituan-static.js',
      label: 'Meituan ads fetch input and request builder keep missing states explicit',
      ok: normalizedAdsForm.url === 'https://example.test/cureShops'
        && normalizedAdsForm.method === 'POST'
        && normalizedAdsForm.partnerId === 'partner-30'
        && normalizedAdsForm.poiId === 'poi-30'
        && normalizedAdsForm.shopId === 'shop-30'
        && normalizedAdsForm.cookies === 'mt-ads-cookie'
        && normalizedAdsForm.payloadJson === '{"timeUnit":"day"}'
        && normalizedAdsForm.extraParams === '{"scope":"campaign"}'
        && missingAdsUrl.status === 'missing_url'
        && invalidAdsPageUrl.status === 'invalid_page_url'
        && missingAdsTarget.status === 'missing_shop_or_poi_id'
        && missingAdsCookie.status === 'missing_cookies'
        && adsRequestBody.partner_id === 'partner-30'
        && adsRequestBody.poi_id === 'poi-30'
        && adsRequestBody.shop_id === 'shop-30'
        && adsRequestBody.auto_save === true
        && adsRequestBody.system_hotel_id === '30'
        && adsRequestBody.hotel_name === 'Ads Hotel',
      detail: 'buildMeituanAdsFetchRequestBody sample',
    });

    const adsEvents = [];
    const adsStates = [];
    let adsResultPayload = null;
    let adsOnlinePayload = null;
    let adsRequestedBody = null;
    const adsFlowResult = await runMeituanAdsFetchFlow({
      getForm: () => ({
        url: ' https://example.test/cureShops ',
        method: 'get',
        partnerId: ' partner-40 ',
        poiId: '',
        shopId: ' shop-40 ',
        cookies: ' mt-ads-cookie-40 ',
        startDate: '2026-06-06',
        endDate: '2026-06-07',
        payloadJson: ' {"pageNo":1} ',
        extraParams: '',
      }),
      getSystemHotelId: () => '40',
      getHotelNameById: id => `Ads Hotel ${id}`,
      notify: (message, level) => adsEvents.push(`notify:${level || 'info'}:${message}`),
      setFetching: value => adsStates.push(`fetching:${value}`),
      setAdsResult: value => { adsResultPayload = value; },
      setOnlineDataResult: value => { adsOnlinePayload = value; },
      requestFetch: async body => {
        adsRequestedBody = body;
        return { code: 200, data: { saved_count: 5, row_count: 7 } };
      },
      refreshOnlineHistory: async () => adsEvents.push('history'),
    });
    const acceptedAdsEvents = [];
    const acceptedAdsStates = [];
    let acceptedAdsResultPayload = null;
    let acceptedAdsOnlinePayload = null;
    let acceptedAdsRequestedBody = null;
    const acceptedAdsResult = await runMeituanAdsFetchFlow({
      getForm: () => ({
        url: 'https://example.test/cureShops',
        method: 'get',
        partnerId: 'partner-40',
        shopId: 'shop-40',
        cookies: 'mt-ads-cookie-40',
        startDate: '2026-06-06',
        endDate: '2026-06-07',
      }),
      getSystemHotelId: () => '40',
      getHotelNameById: id => `Ads Hotel ${id}`,
      notify: (message, level) => acceptedAdsEvents.push(`notify:${level || 'info'}:${message}`),
      setFetching: value => acceptedAdsStates.push(`fetching:${value}`),
      setAdsResult: value => { acceptedAdsResultPayload = value; },
      setOnlineDataResult: value => { acceptedAdsOnlinePayload = value; },
      requestFetch: async body => {
        acceptedAdsRequestedBody = body;
        return {
          code: 200,
          message: 'ads queued',
          data: {
            status: 'running',
            task_id: 'mt-ads-task-1',
            platform: 'meituan',
            async: true,
            saved_count: 0,
            request_start_date: '2026-06-06',
            request_end_date: '2026-06-07',
          },
        };
      },
      refreshOnlineHistory: async () => acceptedAdsEvents.push('history'),
    });
    const missingAdsEvents = [];
    const missingAdsResult = await runMeituanAdsFetchFlow({
      getForm: () => ({ url: '', shopId: 'shop', cookies: 'cookie' }),
      notify: (message, level) => missingAdsEvents.push(`notify:${level}:${message}`),
      setFetching: value => missingAdsEvents.push(`fetching:${value}`),
    });
    const failedAdsStates = [];
    const failedAdsEvents = [];
    const failedAdsResult = await runMeituanAdsFetchFlow({
      getForm: () => ({ url: 'https://example.test/cureShops', shopId: 'shop', cookies: 'cookie' }),
      notify: (message, level) => failedAdsEvents.push(`notify:${level}:${message}`),
      setFetching: value => failedAdsStates.push(`fetching:${value}`),
      requestFetch: async () => ({ code: 500, message: 'ads backend failed' }),
    });
    const exceptionAdsStates = [];
    const exceptionAdsEvents = [];
    const exceptionAdsResult = await runMeituanAdsFetchFlow({
      getForm: () => ({ url: 'https://example.test/cureShops', shopId: 'shop', cookies: 'cookie' }),
      notify: (message, level) => exceptionAdsEvents.push(`notify:${level}:${message}`),
      setFetching: value => exceptionAdsStates.push(`fetching:${value}`),
      requestFetch: async () => {
        throw new Error('network down');
      },
    });
    checks.push({
      file: 'public/meituan-static.js',
      label: 'Meituan ads fetch flow preserves success, failed and exception states',
      ok: adsFlowResult.status === 'success'
        && adsRequestedBody.partner_id === 'partner-40'
        && adsRequestedBody.async === false
        && adsRequestedBody.background === false
        && adsRequestedBody.method === 'GET'
        && adsRequestedBody.poi_id === 'shop-40'
        && adsRequestedBody.shop_id === 'shop-40'
        && adsRequestedBody.hotel_name === 'Ads Hotel 40'
        && adsResultPayload.saved_count === 5
        && adsOnlinePayload.row_count === 7
        && adsStates.join('|') === 'fetching:true|fetching:false'
        && adsEvents.includes('history')
        && adsEvents.some(event => event === 'notify:success:广告数据获取成功，已入库 5 条')
        && acceptedAdsResult.status === 'accepted'
        && acceptedAdsRequestedBody.async === false
        && acceptedAdsRequestedBody.background === false
        && acceptedAdsResultPayload.status === 'running'
        && acceptedAdsResultPayload.task_id === 'mt-ads-task-1'
        && acceptedAdsOnlinePayload.status === 'running'
        && acceptedAdsEvents.includes('history')
        && acceptedAdsEvents.includes('notify:info:ads queued')
        && acceptedAdsStates.join('|') === 'fetching:true|fetching:false'
        && missingAdsResult.status === 'missing_url'
        && missingAdsEvents[0] === 'notify:error:需 Network 请求信息：请填写广告接口 Request URL'
        && !missingAdsEvents.some(event => event.startsWith('fetching:'))
        && failedAdsResult.status === 'failed'
        && failedAdsEvents[0] === 'notify:error:ads backend failed'
        && failedAdsStates.join('|') === 'fetching:true|fetching:false'
        && exceptionAdsResult.status === 'exception'
        && exceptionAdsEvents[0] === 'notify:error:广告数据获取失败: network down'
        && exceptionAdsStates.join('|') === 'fetching:true|fetching:false',
      detail: 'runMeituanAdsFetchFlow state samples',
    });
  }
} catch (error) {
  checks.push({
    file: 'public/meituan-static.js',
    label: 'Meituan static runtime validation',
    ok: false,
    detail: error.message,
  });
}

try {
  const context = { window: {} };
  vm.runInNewContext(read('public/ota-diagnosis-static.js'), context, {
    filename: 'public/ota-diagnosis-static.js',
  });
  const otaDiagnosisStatic = context.window.SUXI_OTA_DIAGNOSIS_STATIC || {};
  const buildOtaDiagnosisFetchContext = otaDiagnosisStatic.buildOtaDiagnosisFetchContext;
  const buildOtaDiagnosisFetchTasks = otaDiagnosisStatic.buildOtaDiagnosisFetchTasks;
  const runOtaDiagnosisHotelFetchFlow = otaDiagnosisStatic.runOtaDiagnosisHotelFetchFlow;
  const runOtaDiagnosisGenerateFlow = otaDiagnosisStatic.runOtaDiagnosisGenerateFlow;
  const buildOtaDiagnosisDecisionClosureCards = otaDiagnosisStatic.buildOtaDiagnosisDecisionClosureCards;
  const buildOtaDiagnosisBusinessLoopSteps = otaDiagnosisStatic.buildOtaDiagnosisBusinessLoopSteps;
  const buildOtaDiagnosisActionRows = otaDiagnosisStatic.buildOtaDiagnosisActionRows;
  const buildOtaDiagnosisDataGapRows = otaDiagnosisStatic.buildOtaDiagnosisDataGapRows;
  if (typeof buildOtaDiagnosisFetchContext !== 'function'
    || typeof buildOtaDiagnosisFetchTasks !== 'function'
    || typeof runOtaDiagnosisHotelFetchFlow !== 'function'
    || typeof runOtaDiagnosisGenerateFlow !== 'function'
    || typeof buildOtaDiagnosisDecisionClosureCards !== 'function'
    || typeof buildOtaDiagnosisBusinessLoopSteps !== 'function'
    || typeof buildOtaDiagnosisActionRows !== 'function'
    || typeof buildOtaDiagnosisDataGapRows !== 'function') {
    checks.push({
      file: 'public/ota-diagnosis-static.js',
      label: 'OTA diagnosis static exports fetch/generate and decision-closure builders',
      ok: false,
      detail: 'buildOtaDiagnosisFetchContext/buildOtaDiagnosisFetchTasks/runOtaDiagnosisHotelFetchFlow/runOtaDiagnosisGenerateFlow/buildOtaDiagnosisDecisionClosureCards/buildOtaDiagnosisBusinessLoopSteps/buildOtaDiagnosisActionRows/buildOtaDiagnosisDataGapRows',
    });
  } else {
    const fetchContext = buildOtaDiagnosisFetchContext({
      selectedHotel: { system_hotel_id: '10', hotel_id: '10' },
      form: { hotel_id: '10', start_date: '2026-06-01', end_date: '2026-06-10' },
      ctripConfig: { url: 'ctrip-url', node_id: '24588', cookies: 'ctrip-cookie', auth_data: { ok: true }, ctrip_hotel_id: 'ctrip-10', name: 'Ctrip Demo' },
      ctripTrafficConfig: { url: 'traffic-url', cookies: 'traffic-cookie', platform: 'Ctrip', extra_params: 'foo=1' },
      ctripCookieApiConfig: { endpoints_json: '[{"request_url":"u"}]', headers_json: 'Cookie: header-cookie', profile_id: 'profile-10', method: 'POST', system_hotel_id: '10', ctrip_hotel_id: 'hotel-10' },
      meituanConfig: { url: 'meituan-url', partner_id: 'partner-1', poi_id: 'poi-1', cookies: 'meituan-cookie', data_scope: 'vpoi' },
      meituanTrafficConfig: { url: 'meituan-traffic-url', partner_id: 'partner-1', poi_id: 'poi-1', cookies: 'mt-cookie', system_hotel_id: '10' },
    });
    const tasks = buildOtaDiagnosisFetchTasks({ context: fetchContext });
    const taskLabels = tasks.map(task => task.label);
    const cookieApiTask = tasks.find(task => task.label === 'ctrip-cookie-api');
    checks.push({
      file: 'public/ota-diagnosis-static.js',
      label: 'OTA diagnosis fetch task builder keeps Ctrip and Meituan task coverage',
      ok: fetchContext.systemHotelId === '10'
        && fetchContext.ctripCookieApiCookies === 'header-cookie'
        && fetchContext.hasCtripCookieApiRequests === true
        && taskLabels.includes('ctrip-business')
        && taskLabels.includes('ctrip-traffic')
        && taskLabels.includes('ctrip-cookie-api')
        && taskLabels.includes('meituan-P_RZ')
        && taskLabels.includes('meituan-P_LL')
        && taskLabels.includes('meituan-traffic')
        && cookieApiTask?.body?.request_source === 'saved_config',
      detail: 'buildOtaDiagnosisFetchTasks saved config sample',
    });
    const coreContext = buildOtaDiagnosisFetchContext({
      selectedHotel: { system_hotel_id: '20' },
      form: { hotel_id: '20', start_date: '2026-06-02', end_date: '2026-06-02' },
      ctripCookieApiConfig: { profile_id: 'profile-20' },
    });
    const coreTasks = buildOtaDiagnosisFetchTasks({
      context: coreContext,
      genericCtripCookie: { cookies: 'generic-cookie' },
      useCtripCorePresetForDiagnosis: true,
      ctripCorePresetReason: 'generic_cookie',
      ctripCorePresetJson: '[{"request_url":"core"}]',
    });
    const coreTask = coreTasks.find(task => task.label === 'ctrip-cookie-api');
    checks.push({
      file: 'public/ota-diagnosis-static.js',
      label: 'OTA diagnosis fetch task builder keeps core preset source explicit',
      ok: coreTask?.body?.request_source === 'core_preset:generic_cookie'
        && coreTask?.body?.cookies === 'generic-cookie'
        && coreTask?.body?.endpoints_json === '[{"request_url":"core"}]',
      detail: 'core_preset',
    });
    const flowEvents = [];
    const flowStatuses = [];
    const flowResult = await runOtaDiagnosisHotelFetchFlow({
      selectedHotel: { system_hotel_id: '30' },
      form: { hotel_id: '30', start_date: '2026-06-03', end_date: '2026-06-03' },
      readSavedOtaDataConfig: async type => {
        flowEvents.push({ type: 'config', source: type });
        if (type === 'ctrip-cookie-api') return { profile_id: 'profile-30', system_hotel_id: '30' };
        return {};
      },
      readSavedGenericCookieForDiagnosis: async systemHotelId => {
        flowEvents.push({ type: 'generic_cookie', systemHotelId });
        return null;
      },
      checkCtripProfileStatus: async ({ systemHotelId, profileId }) => {
        flowEvents.push({ type: 'profile_status', systemHotelId, profileId });
        return { exists: true, profile_id: profileId };
      },
      applyCtripProfileStatus: status => flowStatuses.push(status),
      getCtripCookieApiCorePresetJson: () => '[{"request_url":"core-flow"}]',
      requestTask: async task => {
        flowEvents.push({ type: 'task', task });
        return { code: 200, message: 'ok', data: { saved_count: 3 } };
      },
      notify: message => flowEvents.push({ type: 'notify', message }),
    });
    const flowTaskEvent = flowEvents.find(event => event.type === 'task') || {};
    checks.push({
      file: 'public/ota-diagnosis-static.js',
      label: 'OTA diagnosis fetch flow keeps profile core preset and task summary explicit',
      ok: flowResult.attempted === 1
        && flowResult.success === 1
        && flowResult.failed === 0
        && flowResult.results[0]?.label === 'ctrip-cookie-api'
        && flowResult.results[0]?.saved_count === 3
        && flowResult.results[0]?.request_source === 'core_preset:profile'
        && flowStatuses[0]?.profile_id === 'profile-30'
        && flowTaskEvent.task?.body?.endpoints_json === '[{"request_url":"core-flow"}]'
        && flowTaskEvent.task?.body?.request_source === 'core_preset:profile'
        && flowEvents.some(event => event.type === 'notify'),
      detail: 'runOtaDiagnosisHotelFetchFlow profile preset sample',
    });

    const generateEvents = [];
    const generateLoading = [];
    let generateError = 'seed';
    let generateResult = 'seed';
    let generateEmpty = true;
    let generateRequestBody = null;
    const generateResultStatus = await runOtaDiagnosisGenerateFlow({
      form: {
        hotel_id: 'hotel-key',
        platform: 'ctrip',
        start_date: '2026-06-04',
        end_date: '2026-06-05',
      },
      hotelOptions: [{
        value: 'hotel-key',
        hotel_id: '40',
        platform_hotel_id: 'platform-40',
        config_id: 'config-40',
        source: 'system',
        name: 'Hotel 40',
      }],
      getModelKey: () => 'deepseek-chat',
      runHotelFetch: async (selectedHotel, form) => {
        generateEvents.push({ type: 'fetch', selectedHotel, form });
        return {
          attempted: 2,
          success: 1,
          failed: 1,
          results: [
            { label: 'ctrip-cookie-api', success: true, message: 'ok' },
            { label: 'meituan-traffic', success: false, message: 'missing auth' },
          ],
        };
      },
      requestDiagnosis: async body => {
        generateRequestBody = body;
        return {
          code: 200,
          data: {
            diagnosis: { summary: '流量稳定' },
            metrics: { record_count: 12 },
          },
        };
      },
      setLoading: value => { generateLoading.push(value); },
      setError: value => { generateError = value; },
      setResult: value => { generateResult = value; },
      setEmpty: value => { generateEmpty = value; },
      notify: (message, level) => generateEvents.push({ type: 'notify', message, level: level || '' }),
    });
    checks.push({
      file: 'public/ota-diagnosis-static.js',
      label: 'OTA diagnosis generate flow keeps fetch warning and success state explicit',
      ok: generateResultStatus.status === 'success'
        && generateLoading[0] === true
        && generateLoading[generateLoading.length - 1] === false
        && generateError === ''
        && generateEmpty === false
        && generateResult?.metrics?.record_count === 12
        && generateRequestBody?.hotel_id === '40'
        && generateRequestBody?.platform_hotel_id === 'platform-40'
        && generateRequestBody?.model_key === 'deepseek-chat'
        && generateEvents.some(event => event.type === 'fetch' && event.selectedHotel?.hotel_id === '40')
        && generateEvents.some(event => event.type === 'notify' && event.level === 'warning' && event.message.includes('继续使用已入库数据生成诊断'))
        && generateEvents.some(event => event.type === 'notify' && event.message === 'OTA诊断已生成'),
      detail: 'runOtaDiagnosisGenerateFlow success sample',
    });

    let emptyResultPayload = null;
    let emptyState = false;
    const emptyResult = await runOtaDiagnosisGenerateFlow({
      form: { hotel_id: 'empty-hotel', platform: 'ctrip', start_date: '2026-06-12', end_date: '2026-06-12' },
      hotelOptions: [],
      runHotelFetch: async () => ({ attempted: 0, success: 0, failed: 0, results: [] }),
      requestDiagnosis: async () => ({
        code: 200,
        data: {
          diagnosis: { summary: '暂无 OTA 数据，不能生成可信经营诊断。' },
          data_gaps: [{ code: 'ota_same_period_source_rows_missing', message: 'missing rows' }],
          action_items: [{ id: 'ota_action_collect_same_period_data', status: 'blocked_by_missing_ota_data' }],
        },
      }),
      setResult: value => { emptyResultPayload = value; },
      setEmpty: value => { emptyState = value; },
      setLoading: () => {},
      setError: () => {},
      notify: () => {},
    });
    checks.push({
      file: 'public/ota-diagnosis-static.js',
      label: 'OTA diagnosis empty result keeps data gaps and action items visible',
      ok: emptyResult.status === 'empty'
        && emptyState === true
        && emptyResultPayload?.data_gaps?.[0]?.code === 'ota_same_period_source_rows_missing'
        && emptyResultPayload?.action_items?.[0]?.status === 'blocked_by_missing_ota_data',
      detail: 'runOtaDiagnosisGenerateFlow empty sample',
    });

    const missingLoading = [];
    let missingError = '';
    const missingHotelResult = await runOtaDiagnosisGenerateFlow({
      form: { hotel_id: '', platform: 'ctrip', start_date: '2026-06-04', end_date: '2026-06-05' },
      setLoading: value => { missingLoading.push(value); },
      setError: value => { missingError = value; },
    });
    const failedLoading = [];
    let failedError = '';
    let failedRequestBody = null;
    const failedResponseResult = await runOtaDiagnosisGenerateFlow({
      form: { hotel_id: 'fallback-hotel', platform: 'meituan', start_date: '2026-06-06', end_date: '2026-06-06' },
      hotelOptions: [],
      getModelKey: () => 'deepseek-reasoner',
      runHotelFetch: async () => ({ attempted: 0, success: 0, failed: 0, results: [] }),
      requestDiagnosis: async body => {
        failedRequestBody = body;
        return { code: 500, message: 'backend failed' };
      },
      setLoading: value => { failedLoading.push(value); },
      setError: value => { failedError = value; },
    });
    const exceptionLoading = [];
    let exceptionError = '';
    const exceptionResult = await runOtaDiagnosisGenerateFlow({
      form: { hotel_id: 'fallback-hotel', platform: 'ctrip', start_date: '2026-06-07', end_date: '2026-06-07' },
      runHotelFetch: async () => ({ attempted: 0, success: 0, failed: 0, results: [] }),
      requestDiagnosis: async () => {
        throw new Error('network down');
      },
      setLoading: value => { exceptionLoading.push(value); },
      setError: value => { exceptionError = value; },
    });
    checks.push({
      file: 'public/ota-diagnosis-static.js',
      label: 'OTA diagnosis generate flow keeps missing, failed and exception states visible',
      ok: missingHotelResult.status === 'missing_hotel'
        && missingError === '请选择酒店'
        && missingLoading.length === 0
        && failedResponseResult.status === 'failed'
        && failedError === 'backend failed'
        && failedRequestBody?.hotel_id === 'fallback-hotel'
        && failedRequestBody?.model_key === 'deepseek-reasoner'
        && failedLoading[0] === true
        && failedLoading[failedLoading.length - 1] === false
        && exceptionResult.status === 'exception'
        && exceptionResult.errorMessage === 'network down'
        && exceptionError === 'network down'
        && exceptionLoading[0] === true
        && exceptionLoading[exceptionLoading.length - 1] === false,
      detail: 'runOtaDiagnosisGenerateFlow error-state samples',
    });
    const closureSample = {
      decision_closure: {
        status: 'blocked',
        data_evidence_input: {
          source_policy: 'database_only_no_synthetic_conclusion',
          evidence_refs: ['ota_no_data_scope'],
          data_gaps: [{ code: 'ota_same_period_source_rows_missing' }],
          enough_for_executable_actions: false,
        },
        diagnostic_conclusion: { summary: '暂无可信诊断', confidence_level: 'low' },
        suggested_actions: {
          ready_count: 0,
          blocked_count: 1,
          items: [{
            id: 'ota_action_collect_same_period_data',
            action: '补齐同日 OTA 数据',
            status: 'blocked_by_missing_ota_data',
            execution_ready: false,
            evidence_refs: ['ota_no_data_scope'],
            required_evidence: ['same_period_ota_data'],
            missing_evidence: [{ code: 'missing_same_period_ota_data', label: '同日 OTA 入库数据' }],
            human_confirmation_status: 'blocked',
          }],
        },
        blocked_state: { is_blocked: true, blocked_reasons: ['ota_same_period_source_rows_missing'] },
        human_confirmation: { required: true, status: 'blocked', reason: 'recommended actions are blocked' },
      },
    };
    const closureCards = buildOtaDiagnosisDecisionClosureCards(closureSample);
    const loopSteps = buildOtaDiagnosisBusinessLoopSteps(closureSample);
    const actionRows = buildOtaDiagnosisActionRows(closureSample);
    const dataGapRows = buildOtaDiagnosisDataGapRows(closureSample);
    checks.push({
      file: 'public/ota-diagnosis-static.js',
      label: 'OTA diagnosis decision closure UI helpers preserve blocked evidence state',
      ok: closureCards.length === 5
        && closureCards[0]?.key === 'data_evidence_input'
        && closureCards[0]?.status === 'blocked'
        && loopSteps.map(step => step.title).join(' -> ') === 'OTA数据 -> 收益分析 -> AI决策 -> 运营管理 -> 投资决策'
        && loopSteps[4]?.status === 'blocked_by_operation_closure'
        && actionRows[0]?.status === 'blocked_by_missing_ota_data'
        && actionRows[0]?.missingText.includes('同日 OTA 入库数据')
        && dataGapRows[0]?.code === 'ota_same_period_source_rows_missing'
        && dataGapRows[0]?.status === 'blocked_by_data_gap',
      detail: 'decision_closure blocked sample',
    });
  }
} catch (error) {
  checks.push({
    file: 'public/ota-diagnosis-static.js',
    label: 'OTA diagnosis static runtime validation',
    ok: false,
    detail: error.message,
  });
}

try {
  const context = { window: {} };
  vm.runInNewContext(read('public/ai-analysis-static.js'), context, {
    filename: 'public/ai-analysis-static.js',
  });
  const aiAnalysisStatic = context.window.SUXI_AI_ANALYSIS_STATIC || {};
  const requiredKeys = [
    'getAiAnalysisHotelKey',
    'sanitizeAiReportHtml',
    'aiReportHtmlToText',
    'aiAnalysisStatusText',
    'aiAnalysisPriorityText',
    'normalizeAiAnalysisList',
    'normalizeAiProblemHotels',
    'maskAiAnalysisError',
    'chunkArray',
    'resolveAiSelectedData',
    'validateCapturedOtaAiAnalysisStart',
    'buildCapturedOtaHotelPayload',
    'buildCtripAiAnalysisHotelSelection',
    'buildAiAnalysisProgress',
    'buildAiAnalysisBatchResults',
    'buildCapturedOtaAnalysisRunPlan',
    'buildCapturedOtaAnalysisStartContext',
    'buildCapturedOtaAnalysisRunContext',
    'buildCapturedOtaGroupOutcome',
    'applyCapturedOtaGroupRunState',
    'buildCapturedOtaSummaryRequestBody',
    'buildCapturedOtaSummaryContext',
    'buildCapturedOtaSummaryResponseResult',
    'buildCapturedFallbackSummaryReport',
    'buildAiAnalysisHistoryRecord',
    'buildCapturedOtaAnalysisCompletion',
    'runCapturedOtaAnalysisExecution',
    'runCapturedOtaAnalysisStartFlow',
    'getMeituanAiAnalysisHotelKey',
    'buildMeituanAiAnalysisHotelList',
    'resolveMeituanAiSelectedData',
    'validateMeituanAiAnalysisStart',
    'buildMeituanAiAnalysisRequestBody',
    'buildMeituanAiAnalysisHistoryRecord',
    'runMeituanAiAnalysisFlow',
  ];
  const missingKeys = requiredKeys.filter(key => typeof aiAnalysisStatic[key] !== 'function');
  if (missingKeys.length > 0) {
    checks.push({
      file: 'public/ai-analysis-static.js',
      label: 'AI analysis static exports required builders',
      ok: false,
      detail: missingKeys.join(', '),
    });
  } else {
    const hotelPayload = aiAnalysisStatic.buildCapturedOtaHotelPayload({
      poiId: 'ctrip-10',
      hotelName: '示例酒店',
      roomNights: '2',
      roomRevenue: '360',
      exposure: '1200',
      views: '88',
      totalOrderNum: '6',
      viewConversion: '7.5',
      payConversion: '3.2',
      amountRank: '5',
      quantityRank: '3',
      commentScore: '4.8',
    });
    const groups = aiAnalysisStatic.chunkArray([hotelPayload, { hotel_name: 'B' }, { hotel_name: 'C' }], 2);
    const hotelSelection = aiAnalysisStatic.buildCtripAiAnalysisHotelSelection({
      ctripHotels: [
        {
          hotelId: 'h1',
          hotelName: 'Alpha',
          quantity: 2,
          amount: 300,
          views: 10,
          exposure: 100,
          amountRank: 5,
        },
        {
          hotelId: 'h1',
          hotelName: 'Alpha',
          roomNights: 3,
          roomRevenue: 480,
          salesRoomNights: 4,
          sales: 620,
          totalDetailNum: 20,
          exposure: 200,
          amountRank: 2,
          quantityRank: 4,
        },
        {
          id: 'h2',
          name: 'Beta',
          convertionRate: '6.5',
          qunarDetailCRRank: 3,
        },
      ],
      selectedKeys: ['h1_Alpha', 'missing_Key'],
    });
    const progress = aiAnalysisStatic.buildAiAnalysisProgress({ hotelCount: 3, groupCount: groups.length });
    const batchResults = aiAnalysisStatic.buildAiAnalysisBatchResults(groups, 12345);
    const runPlan = aiAnalysisStatic.buildCapturedOtaAnalysisRunPlan({
      selectedData: [
        {
          poiId: 'r1',
          hotelName: 'Run One',
          roomNights: 2,
          roomRevenue: 500,
        },
        {
          poiId: 'r2',
          hotelName: 'Run Two',
          roomNights: 1,
          sales: 260,
        },
        {
          poiId: 'r3',
          hotelName: 'Run Three',
          roomNights: 1,
          sales: 220,
        },
        {
          poiId: 'r4',
          hotelName: 'Run Four',
          roomNights: 1,
          sales: 180,
        },
      ],
      isDeepSeekPro: true,
      timestamp: 67890,
    });
    const startContext = aiAnalysisStatic.buildCapturedOtaAnalysisStartContext({
      selectedKeys: ['r1_Run One'],
      hotels: [
        { poiId: 'r1', hotelName: 'Run One', roomNights: 2, roomRevenue: 500 },
        { poiId: 'r2', hotelName: 'Run Two', roomNights: 1, sales: 260 },
      ],
      startDate: '2026-06-01',
      endDate: '2026-06-10',
    });
    const missingStartContext = aiAnalysisStatic.buildCapturedOtaAnalysisStartContext({
      selectedKeys: [],
      hotels: [],
      startDate: '2026-06-01',
      endDate: '2026-06-10',
    });
    const runContext = aiAnalysisStatic.buildCapturedOtaAnalysisRunContext({
      selectedData: startContext.selectedData,
      isDeepSeekPro: false,
      timestamp: 24680,
    });
    const emptyRunContext = aiAnalysisStatic.buildCapturedOtaAnalysisRunContext({
      selectedData: [],
      isDeepSeekPro: false,
      timestamp: 13579,
    });
    const selectedRows = aiAnalysisStatic.resolveAiSelectedData(
      ['r1_Run One', 'missing_Key'],
      [
        { poiId: 'r1', hotelName: 'Run One' },
        { poiId: 'r2', hotelName: 'Run Two' },
      ],
    );
    const missingSelectedValidation = aiAnalysisStatic.validateCapturedOtaAiAnalysisStart({
      selectedKeys: [],
      selectedData: [],
      startDate: '2026-06-01',
      endDate: '2026-06-10',
    });
    const missingDataValidation = aiAnalysisStatic.validateCapturedOtaAiAnalysisStart({
      selectedKeys: ['r1_Run One'],
      selectedData: [],
      startDate: '2026-06-01',
      endDate: '2026-06-10',
    });
    const missingDateValidation = aiAnalysisStatic.validateCapturedOtaAiAnalysisStart({
      selectedKeys: ['r1_Run One'],
      selectedData: [{ poiId: 'r1', hotelName: 'Run One' }],
      startDate: '',
      endDate: '',
    });
    const invalidDateValidation = aiAnalysisStatic.validateCapturedOtaAiAnalysisStart({
      selectedKeys: ['r1_Run One'],
      selectedData: [{ poiId: 'r1', hotelName: 'Run One' }],
      startDate: '2026-06-10',
      endDate: '2026-06-01',
    });
    const validStartValidation = aiAnalysisStatic.validateCapturedOtaAiAnalysisStart({
      selectedKeys: ['r1_Run One'],
      selectedData: [{ poiId: 'r1', hotelName: 'Run One' }],
      startDate: '2026-06-01',
      endDate: '2026-06-10',
    });
    const successGroup = {
      ...batchResults[0],
      status: 'success',
      result: {
        overall_conclusion: '订单转化偏弱',
        key_findings: ['曝光充足'],
        competitor_insights: ['竞对价格更稳'],
        problem_hotels: ['酒店：示例酒店；问题：转化偏低；关键指标：曝光、订单；建议：复核价格'],
        recommended_actions: ['调整促销'],
        priority: 'high',
        data_anomalies: [],
      },
    };
    const summaryBody = aiAnalysisStatic.buildCapturedOtaSummaryRequestBody({
      platform: 'ctrip',
      modelKey: 'deepseek_chat',
      startDate: '2026-06-01',
      endDate: '2026-06-10',
      selectedHotelCount: 3,
      completedHotels: 2,
      failedHotels: 1,
      successGroups: [successGroup],
      failedGroups: [{ group_index: 2, error: 'failed' }],
    });
    const fallback = aiAnalysisStatic.buildCapturedFallbackSummaryReport({
      successGroups: [successGroup],
      failedGroups: [{ group_index: 2, error: 'sk-secret12345678' }],
      selectedCount: 3,
      completedHotels: 2,
      failedHotels: 1,
      groupCount: 2,
      reason: 'Bearer token-secret',
    });
    const summaryContext = aiAnalysisStatic.buildCapturedOtaSummaryContext({
      hotelsPayload: runPlan.hotelsPayload,
      progress: { completedHotels: '3', failedHotels: '1' },
      batchResults: runPlan.batchResults,
      successGroups: [successGroup],
      failedGroups: [{ group_index: 2, error: 'failed' }],
    });
    const summarySuccessResult = aiAnalysisStatic.buildCapturedOtaSummaryResponseResult({
      response: {
        code: 200,
        data: {
          report: { overall_conclusion: '汇总成功' },
          process: { steps: ['汇总'] },
        },
      },
      successGroups: [successGroup],
      failedGroups: [],
      selectedCount: 3,
      completedHotels: 2,
      failedHotels: 1,
      groupCount: 2,
    });
    const summaryFallbackResult = aiAnalysisStatic.buildCapturedOtaSummaryResponseResult({
      response: { code: 500, message: 'Bearer token-secret' },
      successGroups: [successGroup],
      failedGroups: [{ group_index: 2, error: 'sk-secret12345678' }],
      selectedCount: 3,
      completedHotels: 2,
      failedHotels: 1,
      groupCount: 2,
    });
    const history = aiAnalysisStatic.buildAiAnalysisHistoryRecord({
      selectedData: [{ hotelName: 'A' }, { hotelName: 'B' }, { hotelName: 'C' }, { hotelName: 'D' }],
      capturedReport: { overall_conclusion: '已完成' },
      completedHotels: 2,
      failedHotels: 1,
      reportHtml: '<section>ok</section>',
      now: new Date('2026-06-10T00:00:00+08:00'),
    });
    const completion = aiAnalysisStatic.buildCapturedOtaAnalysisCompletion({
      selectedData: [{ hotelName: 'A' }, { hotelName: 'B' }, { hotelName: 'C' }, { hotelName: 'D' }],
      capturedReport: { overall_conclusion: '已完成', key_findings: ['曝光充足'] },
      completedHotels: 2,
      failedHotels: 1,
      existingHistory: [{ id: 1 }, { id: 2 }, { id: 3 }],
      historyLimit: 3,
      now: new Date('2026-06-10T00:00:00+08:00'),
    });
    const groupOutcome = aiAnalysisStatic.buildCapturedOtaGroupOutcome([
      { groupIndex: 1, hotelCount: 2, status: 'success', result: { priority: 'medium' } },
      { groupIndex: 2, hotelCount: 1, status: 'failed', error: 'model failed' },
      { groupIndex: 3, hotelCount: 1, status: 'pending', error: 'timeout' },
    ]);
    const groupStateSuccess = { status: 'running', result: null };
    const progressStateSuccess = { completedHotels: 0, failedHotels: 0 };
    aiAnalysisStatic.applyCapturedOtaGroupRunState({
      groupState: groupStateSuccess,
      progressState: progressStateSuccess,
      group: [{ hotel_name: 'A' }, { hotel_name: 'B' }],
      result: { ok: true, result: { overall_conclusion: '成功' } },
    });
    const groupStateFailure = { status: 'running', error: '', errorDetails: null };
    const progressStateFailure = { completedHotels: 0, failedHotels: 0 };
    aiAnalysisStatic.applyCapturedOtaGroupRunState({
      groupState: groupStateFailure,
      result: { ok: false, error: 'failed', errorDetails: { error_type: 'model_error' } },
    });
    aiAnalysisStatic.applyCapturedOtaGroupRunState({
      progressState: progressStateFailure,
      result: { ok: false },
      retryResult: { successCount: '1', failedCount: '2' },
    });
    const runnerGroups = [[{ hotel_name: 'A' }], [{ hotel_name: 'B' }]];
    const runnerBatchResults = aiAnalysisStatic.buildAiAnalysisBatchResults(runnerGroups, 9988);
    const runnerProgress = aiAnalysisStatic.buildAiAnalysisProgress({ hotelCount: 2, groupCount: 2 });
    const runnerRequests = [];
    const runnerSummaryContexts = [];
    const runnerResult = await aiAnalysisStatic.runCapturedOtaAnalysisExecution({
      groups: runnerGroups,
      batchResults: runnerBatchResults,
      progressState: runnerProgress,
      hotelsPayload: runnerGroups.flat(),
      selectedData: [{ hotelName: 'A' }, { hotelName: 'B' }],
      existingHistory: [{ id: 'old' }],
      requestGroup: async group => {
        runnerRequests.push(group[0]?.hotel_name || '');
        if (group[0]?.hotel_name === 'B') {
          return { ok: false, error: 'temporary failed', errorDetails: { error_type: 'model_error' } };
        }
        return {
          ok: true,
          result: {
            overall_conclusion: 'A成功',
            key_findings: ['A有效'],
            recommended_actions: ['继续观察'],
          },
        };
      },
      retryGroup: async (group, groupState) => {
        groupState.status = 'success';
        groupState.result = {
          overall_conclusion: `${group[0]?.hotel_name || ''}重试成功`,
          key_findings: ['重试有效'],
          recommended_actions: ['复核转化'],
        };
        groupState.retried = true;
        return { successCount: 1, failedCount: 0 };
      },
      requestSummary: async summaryContext => {
        runnerSummaryContexts.push(summaryContext);
        return {
          report: {
            overall_conclusion: '汇总完成',
            key_findings: ['样本有效'],
            recommended_actions: ['保留显式状态'],
          },
          process: { steps: ['summary'] },
        };
      },
    });
    const failedRunnerBatchResults = aiAnalysisStatic.buildAiAnalysisBatchResults([[{ hotel_name: 'Failed' }]], 9989);
    const failedRunnerProgress = aiAnalysisStatic.buildAiAnalysisProgress({ hotelCount: 1, groupCount: 1 });
    const failedRunnerResult = await aiAnalysisStatic.runCapturedOtaAnalysisExecution({
      groups: [[{ hotel_name: 'Failed' }]],
      batchResults: failedRunnerBatchResults,
      progressState: failedRunnerProgress,
      hotelsPayload: [{ hotel_name: 'Failed' }],
      selectedData: [{ hotelName: 'Failed' }],
      requestGroup: async () => ({ ok: false, error: 'sk-secret12345678' }),
      retryGroup: async (group, groupState) => {
        groupState.status = 'failed';
        groupState.error = 'sk-secret12345678';
        return { successCount: 0, failedCount: group.length };
      },
      requestSummary: async () => ({ report: {}, process: null }),
      maskError: value => `masked:${aiAnalysisStatic.maskAiAnalysisError(value)}`,
    });
    const startFlowEvents = [];
    const startFlowStates = [];
    let startFlowProgress = null;
    let startFlowBatchResults = null;
    let startFlowCompletion = null;
    let startFlowCapturedError = 'seed';
    const startFlowResult = await aiAnalysisStatic.runCapturedOtaAnalysisStartFlow({
      selectedKeys: ['r1_Run One'],
      hotels: [
        { poiId: 'r1', hotelName: 'Run One', roomNights: 2, roomRevenue: 500 },
        { poiId: 'r2', hotelName: 'Run Two', roomNights: 1, sales: 260 },
      ],
      startDate: '2026-06-01',
      endDate: '2026-06-10',
      isDeepSeekPro: false,
      existingHistory: [{ id: 'old-start-flow' }],
      notify: (message, level = 'success') => startFlowEvents.push(['notify', level, message]),
      setAnalyzing: value => startFlowStates.push(['analyzing', value]),
      resetState: () => startFlowEvents.push(['reset']),
      setProgress: value => { startFlowProgress = value; },
      setBatchResults: value => { startFlowBatchResults = value; },
      setCompletion: value => { startFlowCompletion = value; },
      setCapturedError: value => { startFlowCapturedError = value; },
      requestGroup: async group => ({ ok: true, result: { overall_conclusion: `${group[0]?.hotel_name || ''} ok` } }),
      retryGroup: async () => ({ successCount: 0, failedCount: 0 }),
      requestSummary: async summaryContext => ({
        report: {
          overall_conclusion: `summary ${summaryContext.completedHotels}`,
          key_findings: ['start flow ok'],
          recommended_actions: ['continue'],
        },
        process: { steps: ['start-flow-summary'] },
      }),
    });
    const startFlowGuardEvents = [];
    const startFlowGuardStates = [];
    const startFlowGuardResult = await aiAnalysisStatic.runCapturedOtaAnalysisStartFlow({
      selectedKeys: [],
      hotels: [],
      startDate: '2026-06-01',
      endDate: '2026-06-10',
      notify: (message, level) => startFlowGuardEvents.push(['notify', level, message]),
      setAnalyzing: value => startFlowGuardStates.push(['analyzing', value]),
    });
    const startFlowExceptionEvents = [];
    const startFlowExceptionStates = [];
    let startFlowExceptionError = '';
    const startFlowExceptionResult = await aiAnalysisStatic.runCapturedOtaAnalysisStartFlow({
      selectedKeys: ['r1_Run One'],
      hotels: [{ poiId: 'r1', hotelName: 'Run One', roomNights: 2, roomRevenue: 500 }],
      startDate: '2026-06-01',
      endDate: '2026-06-10',
      notify: (message, level = 'success') => startFlowExceptionEvents.push(['notify', level, message]),
      setAnalyzing: value => startFlowExceptionStates.push(['analyzing', value]),
      resetState: () => startFlowExceptionEvents.push(['reset']),
      setCapturedError: value => { startFlowExceptionError = value; },
      requestGroup: async () => { throw new Error('network down'); },
      retryGroup: async () => ({ successCount: 0, failedCount: 0 }),
      requestSummary: async () => ({ report: {}, process: null }),
    });
    const meituanHotels = aiAnalysisStatic.buildMeituanAiAnalysisHotelList([
      { poiId: 'm1', hotelName: 'Meituan One', roomNights: '2', roomRevenue: '300', views: '40' },
      { poiId: 'm1', hotelName: 'Meituan One', roomNights: '5', roomRevenue: '800', views: '80' },
      { poiId: 'm2', hotelName: 'Meituan Two', sales: '260', exposure: '900' },
    ]);
    const meituanSelectedData = aiAnalysisStatic.resolveMeituanAiSelectedData(['m1_Meituan One', 'missing_Key'], meituanHotels);
    const meituanRequestBody = aiAnalysisStatic.buildMeituanAiAnalysisRequestBody(meituanSelectedData);
    const meituanHistory = aiAnalysisStatic.buildMeituanAiAnalysisHistoryRecord({
      selectedData: [...meituanSelectedData, { hotelName: 'Meituan Extra A' }, { hotelName: 'Meituan Extra B' }],
      summary: 'Meituan summary',
      report: '<section>meituan</section>',
      now: new Date('2026-06-10T00:00:00+08:00'),
    });
    const meituanMissingSelection = aiAnalysisStatic.validateMeituanAiAnalysisStart({
      selectedKeys: [],
      hotels: meituanHotels,
    });
    const meituanMissingData = aiAnalysisStatic.validateMeituanAiAnalysisStart({
      selectedKeys: ['missing_Key'],
      hotels: meituanHotels,
    });
    const meituanValidStart = aiAnalysisStatic.validateMeituanAiAnalysisStart({
      selectedKeys: ['m1_Meituan One'],
      hotels: meituanHotels,
    });
    const meituanFlowEvents = [];
    const meituanFlowStates = [];
    let meituanFlowRequestBody = null;
    let meituanFlowResultHtml = '';
    let meituanFlowHistory = [];
    const oldMeituanHistory = Array.from({ length: 10 }, (_, index) => ({ id: `old-${index}` }));
    const meituanFlowResult = await aiAnalysisStatic.runMeituanAiAnalysisFlow({
      selectedKeys: ['m1_Meituan One'],
      hotels: meituanHotels,
      requestAnalysis: async body => {
        meituanFlowRequestBody = body;
        return { code: 200, data: { report: '<section>美团报告</section>', summary: '美团汇总' } };
      },
      notify: (message, level) => meituanFlowEvents.push(`notify:${level || 'info'}:${message}`),
      setAnalyzing: value => meituanFlowStates.push(`analyzing:${value}`),
      setResult: value => { meituanFlowResultHtml = value; },
      getHistory: () => oldMeituanHistory,
      setHistory: value => { meituanFlowHistory = value; },
      sanitizeReport: value => `safe:${value}`,
      now: () => new Date('2026-06-10T00:00:00+08:00'),
    });
    const meituanFailedEvents = [];
    const meituanFailedStates = [];
    let meituanFailedResultHtml = 'before';
    const meituanFailedResult = await aiAnalysisStatic.runMeituanAiAnalysisFlow({
      selectedKeys: ['m1_Meituan One'],
      hotels: meituanHotels,
      requestAnalysis: async () => ({ code: 500, message: 'backend failed' }),
      notify: (message, level) => meituanFailedEvents.push(`notify:${level || 'info'}:${message}`),
      setAnalyzing: value => meituanFailedStates.push(`analyzing:${value}`),
      setResult: value => { meituanFailedResultHtml = value; },
    });
    const meituanExceptionEvents = [];
    const meituanExceptionLogs = [];
    const meituanExceptionStates = [];
    let meituanExceptionResultHtml = 'before';
    const meituanExceptionResult = await aiAnalysisStatic.runMeituanAiAnalysisFlow({
      selectedKeys: ['m1_Meituan One'],
      hotels: meituanHotels,
      requestAnalysis: async () => { throw new Error('network down'); },
      notify: (message, level) => meituanExceptionEvents.push(`notify:${level}:${message}`),
      setAnalyzing: value => meituanExceptionStates.push(`analyzing:${value}`),
      setResult: value => { meituanExceptionResultHtml = value; },
      logError: (...args) => meituanExceptionLogs.push(args.map(item => item?.message || item).join('|')),
    });
    const meituanGuardEvents = [];
    const meituanGuardStates = [];
    const meituanGuardResult = await aiAnalysisStatic.runMeituanAiAnalysisFlow({
      selectedKeys: [],
      hotels: meituanHotels,
      notify: (message, level) => meituanGuardEvents.push(`notify:${level}:${message}`),
      setAnalyzing: value => meituanGuardStates.push(`analyzing:${value}`),
    });
    checks.push({
      file: 'public/ai-analysis-static.js',
      label: 'AI analysis static builds captured OTA payload and batch state',
      ok: hotelPayload.hotel_id === 'ctrip-10'
        && hotelPayload.price === 180
        && hotelPayload.exposure === 1200
        && hotelPayload.tags.includes('最好排名3')
        && groups.length === 2
        && progress.totalHotels === 3
        && progress.totalGroups === 2
        && batchResults[0].key === 'group_12345_0'
        && batchResults[0].hotelNames.includes('示例酒店'),
      detail: 'captured payload batch sample',
    });
    checks.push({
      file: 'public/ai-analysis-static.js',
      label: 'AI analysis static builds captured OTA run plans with model-aware group sizing',
      ok: runPlan.hotelsPayload.length === 4
        && runPlan.groups.length === 2
        && runPlan.groups[0].length === 3
        && runPlan.groups[1].length === 1
        && runPlan.progress.totalHotels === 4
        && runPlan.progress.totalGroups === 2
        && runPlan.batchResults[0].key === 'group_67890_0'
        && runPlan.batchResults[0].hotelNames.includes('Run One')
        && runPlan.batchResults[1].hotelCount === 1
        && startContext.ok === true
        && startContext.selectedData.length === 1
        && startContext.selectedData[0].hotelName === 'Run One'
        && missingStartContext.ok === false
        && runContext.ok === true
        && runContext.message.includes('开始分析 1 家酒店')
        && runContext.batchResults[0].key === 'group_24680_0'
        && emptyRunContext.ok === false
        && emptyRunContext.message === '暂无抓取数据',
      detail: 'captured OTA run plan sample',
    });
    checks.push({
      file: 'public/ai-analysis-static.js',
      label: 'AI analysis static resolves selections and group outcomes',
      ok: selectedRows.length === 1
        && selectedRows[0].hotelName === 'Run One'
        && groupOutcome.successGroups.length === 1
        && groupOutcome.failedGroups.length === 2
        && groupOutcome.failedGroups[0].group_index === 2
        && groupOutcome.failedGroups[1].hotel_count === 1
        && groupOutcome.failedReason.includes('第 2 组：model failed')
        && groupOutcome.failedReason.includes('第 3 组：timeout')
        && groupStateSuccess.status === 'success'
        && groupStateSuccess.result.overall_conclusion === '成功'
        && progressStateSuccess.completedHotels === 2
        && groupStateFailure.error === 'failed'
        && groupStateFailure.errorDetails.error_type === 'model_error'
        && progressStateFailure.completedHotels === 1
        && progressStateFailure.failedHotels === 2,
      detail: 'selected data and group outcome sample',
    });
    checks.push({
      file: 'public/ai-analysis-static.js',
      label: 'AI analysis static validates captured OTA start inputs',
      ok: missingSelectedValidation.ok === false
        && missingSelectedValidation.message === '请先选择要分析的酒店'
        && missingDataValidation.ok === false
        && missingDataValidation.message === '未找到选中的酒店数据'
        && missingDateValidation.ok === false
        && missingDateValidation.message === '请选择分析日期范围'
        && invalidDateValidation.ok === false
        && invalidDateValidation.message === '开始日期不能晚于结束日期'
        && validStartValidation.ok === true
        && validStartValidation.level === 'success',
      detail: 'captured OTA start validation sample',
    });
    checks.push({
      file: 'public/ai-analysis-static.js',
      label: 'AI analysis start flow preserves captured OTA success and visible failure states',
      ok: startFlowResult.status === 'success'
        && startFlowProgress?.totalHotels === 1
        && Array.isArray(startFlowBatchResults)
        && startFlowBatchResults.length === 1
        && startFlowCompletion?.capturedReport?.overall_conclusion === 'summary 1'
        && startFlowCompletion?.history?.[0]?.summary === 'summary 1'
        && String(startFlowCompletion?.history?.[0]?.report || '').includes('summary 1')
        && startFlowEvents.some(event => event[0] === 'reset')
        && startFlowEvents.some(event => event[0] === 'notify' && event[2] === 'AI分析完成')
        && startFlowStates[0]?.[0] === 'analyzing'
        && startFlowStates[0]?.[1] === true
        && startFlowStates[startFlowStates.length - 1]?.[1] === false
        && startFlowCapturedError === 'seed'
        && startFlowGuardResult.status === 'invalid_start'
        && startFlowGuardEvents.some(event => event[0] === 'notify' && event[1] === 'error')
        && startFlowGuardStates.length === 0
        && startFlowExceptionResult.status === 'exception'
        && startFlowExceptionError === 'network down'
        && startFlowExceptionEvents.some(event => event[0] === 'notify' && event[1] === 'error' && event[2].includes('network down'))
        && startFlowExceptionStates[0]?.[1] === true
        && startFlowExceptionStates[startFlowExceptionStates.length - 1]?.[1] === false,
      detail: 'runCapturedOtaAnalysisStartFlow samples',
    });
    checks.push({
      file: 'public/ai-analysis-static.js',
      label: 'AI analysis static builds Ctrip hotel selections without losing merged metrics',
      ok: hotelSelection.hotels.length === 2
        && hotelSelection.selectedKeys.length === 1
        && hotelSelection.selectedKeys[0] === 'h1_Alpha'
        && hotelSelection.hotels[0].poiId === 'h1'
        && hotelSelection.hotels[0].hotelName === 'Alpha'
        && hotelSelection.hotels[0].roomNights === 3
        && hotelSelection.hotels[0].roomRevenue === 480
        && hotelSelection.hotels[0].salesRoomNights === 4
        && hotelSelection.hotels[0].sales === 620
        && hotelSelection.hotels[0].views === 20
        && hotelSelection.hotels[0].exposure === 200
        && hotelSelection.hotels[0].amountRank === 2
        && hotelSelection.hotels[0].quantityRank === 4
        && hotelSelection.hotels[1].poiId === 'h2'
        && hotelSelection.hotels[1].convertionRate === 6.5,
      detail: 'Ctrip AI hotel selection sample',
    });
    checks.push({
      file: 'public/ai-analysis-static.js',
      label: 'AI analysis static builds Meituan hotel selections and request bodies',
      ok: meituanHotels.length === 2
        && meituanHotels[0].poiId === 'm1'
        && meituanHotels[0].roomNights === '2'
        && meituanSelectedData.length === 1
        && meituanMissingSelection.status === 'missing_selection'
        && meituanMissingSelection.message === '请先选择要分析的酒店'
        && meituanMissingData.status === 'missing_selected_data'
        && meituanMissingData.message === '未找到选中的酒店数据'
        && meituanValidStart.ok === true
        && meituanValidStart.selectedData.length === 1
        && meituanRequestBody.total_hotels === 1
        && meituanRequestBody.source === 'meituan'
        && meituanRequestBody.include_suggestions === true
        && meituanHistory.hotel_count === 3
        && meituanHistory.hotel_names === 'Meituan One、Meituan Extra A、Meituan Extra B'
        && meituanHistory.summary === 'Meituan summary',
      detail: 'Meituan AI selection request history sample',
    });
    checks.push({
      file: 'public/ai-analysis-static.js',
      label: 'AI analysis static runs Meituan analysis flow with explicit success, failure and guard states',
      ok: meituanFlowResult.status === 'success'
        && meituanFlowRequestBody.source === 'meituan'
        && meituanFlowRequestBody.total_hotels === 1
        && meituanFlowResultHtml === 'safe:<section>美团报告</section>'
        && meituanFlowHistory.length === 10
        && meituanFlowHistory[0].summary === '美团汇总'
        && meituanFlowHistory[0].report === 'safe:<section>美团报告</section>'
        && meituanFlowHistory[9].id === 'old-8'
        && meituanFlowStates.join('|') === 'analyzing:true|analyzing:false'
        && meituanFlowEvents.join('|') === 'notify:info:AI正在分析数据，请稍候...|notify:info:AI分析完成！'
        && meituanFailedResult.status === 'failed'
        && meituanFailedResultHtml === ''
        && meituanFailedEvents.includes('notify:error:backend failed')
        && meituanFailedStates.join('|') === 'analyzing:true|analyzing:false'
        && meituanExceptionResult.status === 'exception'
        && meituanExceptionResultHtml === ''
        && meituanExceptionEvents.includes('notify:error:美团 AI 分析请求失败，请修复后端接口后重试')
        && meituanExceptionLogs[0].includes('美团AI分析请求失败:')
        && meituanExceptionLogs[0].includes('network down')
        && meituanExceptionStates.join('|') === 'analyzing:true|analyzing:false'
        && meituanGuardResult.status === 'missing_selection'
        && meituanGuardEvents[0] === 'notify:error:请先选择要分析的酒店'
        && meituanGuardStates.length === 0,
      detail: 'runMeituanAiAnalysisFlow state samples',
    });
    checks.push({
      file: 'public/ai-analysis-static.js',
      label: 'AI analysis static builds summary and fallback payloads with explicit failures',
      ok: summaryBody.model_key === 'deepseek_chat'
        && summaryBody.group_summaries[0].report.priority === 'high'
        && summaryBody.group_summaries[0].report.problem_hotels[0].problem === '转化偏低'
        && summaryBody.failed_groups.length === 1
        && summaryContext.selectedHotelCount === 4
        && summaryContext.selectedCount === 4
        && summaryContext.completedHotels === 3
        && summaryContext.failedHotels === 1
        && summaryContext.groupCount === 2
        && summaryContext.successGroups.length === 1
        && fallback.fallback === true
        && fallback.summary.failed_hotel_count === 1
        && fallback.fallback_reason === 'Bearer ****'
        && summarySuccessResult.report.overall_conclusion === '汇总成功'
        && summarySuccessResult.process.steps[0] === '汇总'
        && summaryFallbackResult.report.fallback === true
        && summaryFallbackResult.report.fallback_reason === 'Bearer ****'
        && summaryFallbackResult.process === null,
      detail: 'summary fallback sample',
    });
    checks.push({
      file: 'public/ai-analysis-static.js',
      label: 'AI analysis static builds captured OTA completion state',
      ok: completion.reportHtml.includes('已完成')
        && completion.reportHtml.includes('曝光充足')
        && completion.history.length === 3
        && completion.history[0].hotel_names === 'A、B、C等'
        && completion.history[0].summary === '已完成'
        && completion.history[1].id === 1,
      detail: 'captured OTA completion state sample',
    });
    checks.push({
      file: 'public/ai-analysis-static.js',
      label: 'AI analysis execution runner preserves grouped progress and summary context',
      ok: runnerRequests.join(',') === 'A,B'
        && runnerProgress.currentGroup === 2
        && runnerProgress.completedHotels === 2
        && runnerProgress.failedHotels === 0
        && runnerBatchResults[1].retried === true
        && runnerSummaryContexts[0]?.selectedCount === 2
        && runnerSummaryContexts[0]?.successGroups?.length === 2
        && runnerResult.capturedReport?.overall_conclusion === '汇总完成'
        && runnerResult.process?.steps?.[0] === 'summary'
        && runnerResult.history.length === 2,
      detail: 'captured OTA execution runner sample',
    });
    checks.push({
      file: 'public/ai-analysis-static.js',
      label: 'AI analysis execution runner keeps all-failed state explicit and masked',
      ok: failedRunnerProgress.failedHotels === 1
        && failedRunnerResult.capturedReport === null
        && failedRunnerResult.capturedError.includes('全部分析失败')
        && failedRunnerResult.capturedError.includes('masked:')
        && !failedRunnerResult.capturedError.includes('sk-secret12345678'),
      detail: 'captured OTA execution all-failed sample',
    });
    checks.push({
      file: 'public/ai-analysis-static.js',
      label: 'AI analysis static keeps display labels and sensitive error masking',
      ok: aiAnalysisStatic.aiAnalysisStatusText('running') === '分析中'
        && aiAnalysisStatic.aiAnalysisPriorityText('high') === '高优先级'
        && aiAnalysisStatic.normalizeAiAnalysisList([{ 指标: '曝光', 结论: '偏低' }])[0] === '指标: 曝光；结论: 偏低'
        && aiAnalysisStatic.maskAiAnalysisError('api_key=abc123 sk-abcdefghijk').includes('api_key=****')
        && history.hotel_names === 'A、B、C等'
        && history.summary === '已完成',
      detail: 'labels masks history sample',
    });
  }
} catch (error) {
  checks.push({
    file: 'public/ai-analysis-static.js',
    label: 'AI analysis static runtime validation',
    ok: false,
    detail: error.message,
  });
}

try {
  const context = { window: {} };
  vm.runInNewContext(read('public/ctrip-static.js'), context, {
    filename: 'public/ctrip-static.js',
  });
  const ctripStatic = context.window.SUXI_CTRIP_STATIC || {};
  const buildCtripBrowserCaptureTargetContext = ctripStatic.buildCtripBrowserCaptureTargetContext;
  const buildCtripBrowserCapturePayload = ctripStatic.buildCtripBrowserCapturePayload;
  const buildCtripBrowserCaptureRequestContext = ctripStatic.buildCtripBrowserCaptureRequestContext;
  const normalizeCtripBrowserCaptureErrorResult = ctripStatic.normalizeCtripBrowserCaptureErrorResult;
  const runCtripBrowserCaptureFlow = ctripStatic.runCtripBrowserCaptureFlow;
  const buildCtripFetchDateRange = ctripStatic.buildCtripFetchDateRange;
  const buildCtripFetchRequestBody = ctripStatic.buildCtripFetchRequestBody;
  const buildCtripFetchRequestContext = ctripStatic.buildCtripFetchRequestContext;
  const selectCtripFetchResponsePayload = ctripStatic.selectCtripFetchResponsePayload;
  const buildCtripFetchMeta = ctripStatic.buildCtripFetchMeta;
  const buildCtripFetchRawFailureResult = ctripStatic.buildCtripFetchRawFailureResult;
  const runCtripFetchDataFlow = ctripStatic.runCtripFetchDataFlow;
  const isCtripRankingFormAlignedWithConfig = ctripStatic.isCtripRankingFormAlignedWithConfig;
  const buildLatestCtripSnapshotModel = ctripStatic.buildLatestCtripSnapshotModel;
  const buildCtripTrafficFetchRequestBody = ctripStatic.buildCtripTrafficFetchRequestBody;
  const buildCtripTrafficResponseModel = ctripStatic.buildCtripTrafficResponseModel;
  const runCtripTrafficFetchFlow = ctripStatic.runCtripTrafficFetchFlow;
  const buildCtripOverviewFetchRequestBody = ctripStatic.buildCtripOverviewFetchRequestBody;
  const runCtripOverviewFetchFlow = ctripStatic.runCtripOverviewFetchFlow;
  const buildCtripAdsFetchRequestBody = ctripStatic.buildCtripAdsFetchRequestBody;
  const runCtripAdsFetchFlow = ctripStatic.runCtripAdsFetchFlow;
  const buildCtripCookieApiFetchRequestBody = ctripStatic.buildCtripCookieApiFetchRequestBody;
  const runCtripCookieApiCaptureFlow = ctripStatic.runCtripCookieApiCaptureFlow;
  const defaultCtripAdsEffectReportUrl = ctripStatic.defaultCtripAdsEffectReportUrl;
  const isCtripAdsApiUrl = ctripStatic.isCtripAdsApiUrl;
  const normalizeCtripAdsApiType = ctripStatic.normalizeCtripAdsApiType;
  const createCtripConfigForm = ctripStatic.createCtripConfigForm;
  const buildCtripConfigSavePayload = ctripStatic.buildCtripConfigSavePayload;
  const validateCtripConfigSaveInput = ctripStatic.validateCtripConfigSaveInput;
  const runCtripConfigSaveFlow = ctripStatic.runCtripConfigSaveFlow;
  const runCtripManualTabSwitch = ctripStatic.runCtripManualTabSwitch;
  const createCtripProfileFieldForm = ctripStatic.createCtripProfileFieldForm;
  const buildCtripProfileFieldSmartDefaults = ctripStatic.buildCtripProfileFieldSmartDefaults;
  const buildCtripProfileFieldSavePayload = ctripStatic.buildCtripProfileFieldSavePayload;
  const buildCtripProfileFieldSampleHelpers = ctripStatic.buildCtripProfileFieldSampleHelpers;
  const buildCtripProfileFieldDerivationHelpers = ctripStatic.buildCtripProfileFieldDerivationHelpers;
  const normalizeCtripProfileFieldVerificationStatus = ctripStatic.normalizeCtripProfileFieldVerificationStatus;
  const ctripProfileFieldVerificationText = ctripStatic.ctripProfileFieldVerificationText;
  const ctripProfileFieldVerificationBadgeClass = ctripStatic.ctripProfileFieldVerificationBadgeClass;
  const ctripProfileFieldVerificationLightClass = ctripStatic.ctripProfileFieldVerificationLightClass;
  const buildCtripProfileRecheckInitialState = ctripStatic.buildCtripProfileRecheckInitialState;
  const buildCtripProfileRecheckRunContext = ctripStatic.buildCtripProfileRecheckRunContext;
  const buildCtripProfileRecheckCaptureRefreshState = ctripStatic.buildCtripProfileRecheckCaptureRefreshState;
  const buildCtripProfileRecheckSuccessResult = ctripStatic.buildCtripProfileRecheckSuccessResult;
  const buildCtripProfileRecheckErrorResult = ctripStatic.buildCtripProfileRecheckErrorResult;
  const buildCtripProfileRecheckInterruptedState = ctripStatic.buildCtripProfileRecheckInterruptedState;
  const runCtripProfileRecheckFlow = ctripStatic.runCtripProfileRecheckFlow;
  if (typeof createCtripConfigForm !== 'function'
    || typeof buildCtripConfigSavePayload !== 'function'
    || typeof validateCtripConfigSaveInput !== 'function'
    || typeof runCtripConfigSaveFlow !== 'function') {
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip static exports config save builders',
      ok: false,
      detail: 'Ctrip config save builders',
    });
  } else {
    const defaultConfigForm = createCtripConfigForm();
    const overriddenConfigForm = createCtripConfigForm({ hotel_id: '10', name: '携程账号' });
    const missingName = validateCtripConfigSaveInput({ name: '', cookies: 'cookie' });
    const missingCookies = validateCtripConfigSaveInput({ name: '配置', cookies: '' });
    const validConfig = validateCtripConfigSaveInput({ name: '配置', cookies: 'cookie' });
    const savePayload = buildCtripConfigSavePayload({
      id: 9,
      name: '携程账号',
      hotel_id: '10',
      ctrip_hotel_id: 'ctrip-10',
      cookies: 'sid=secret',
      url: 'https://example.test/ctrip',
      node_id: '24588',
      capture_sections: 'default traffic',
      approved_mappings_path: 'approved.json',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip config builders preserve default form, payload and missing states',
      ok: defaultConfigForm.id === null
        && defaultConfigForm.url.includes('getDayReportCompeteHotelReport')
        && defaultConfigForm.node_id === '24588'
        && defaultConfigForm.capture_sections === 'default'
        && overriddenConfigForm.hotel_id === '10'
        && overriddenConfigForm.name === '携程账号'
        && missingName.status === 'missing_name'
        && missingName.message === '请输入配置名称'
        && missingCookies.status === 'missing_cookies'
        && missingCookies.message === '请输入临时 Cookie/API 辅助内容'
        && validConfig.ok === true
        && savePayload.id === 9
        && savePayload.name === '携程账号'
        && savePayload.hotel_id === '10'
        && savePayload.ctrip_hotel_id === 'ctrip-10'
        && savePayload.cookies === 'sid=secret'
        && savePayload.url === 'https://example.test/ctrip'
        && savePayload.node_id === '24588'
        && savePayload.capture_sections === 'default traffic'
        && savePayload.approved_mappings_path === 'approved.json',
      detail: 'Ctrip config save builder sample',
    });
    const saveEvents = [];
    const saveLogs = [];
    let requestedConfigBody = null;
    let resetConfigForm = null;
    const saveResult = await runCtripConfigSaveFlow({
      getForm: () => ({
        id: 10,
        name: '携程保存',
        hotel_id: '20',
        ctrip_hotel_id: 'ctrip-20',
        cookies: 'sid=save',
        url: 'https://example.test/save',
        node_id: '24588',
        capture_sections: 'default',
        approved_mappings_path: '',
      }),
      requestSave: async body => {
        requestedConfigBody = body;
        return { code: 200, data: { id: 10 } };
      },
      notify: (message, level) => saveEvents.push(`notify:${level || 'info'}:${message}`),
      resetForm: form => { resetConfigForm = form; },
      reloadConfigs: () => saveEvents.push('reload'),
      logError: (...args) => saveLogs.push(args.join('|')),
    });
    const failedEvents = [];
    const failedLogs = [];
    const failedResult = await runCtripConfigSaveFlow({
      getForm: () => ({ name: '携程失败', cookies: 'sid=failed' }),
      requestSave: async () => ({ code: 500, message: 'backend failed' }),
      notify: (message, level) => failedEvents.push(`notify:${level}:${message}`),
      logError: (...args) => failedLogs.push(args.join('|')),
    });
    const exceptionEvents = [];
    const exceptionLogs = [];
    const exceptionResult = await runCtripConfigSaveFlow({
      getForm: () => ({ name: '携程异常', cookies: 'sid=exception' }),
      requestSave: async () => {
        throw {
          message: 'network down',
          response: {
            json: async () => ({ msg: 'response parsed failed' }),
          },
        };
      },
      notify: (message, level) => exceptionEvents.push(`notify:${level}:${message}`),
      logError: (...args) => exceptionLogs.push(args.join('|')),
    });
    const guardEvents = [];
    const guardResult = await runCtripConfigSaveFlow({
      getForm: () => ({ name: '', cookies: 'sid=guard' }),
      notify: (message, level) => guardEvents.push(`notify:${level}:${message}`),
      requestSave: async () => {
        throw new Error('should not request');
      },
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip config save flow preserves success, failed, exception and guard states',
      ok: saveResult.status === 'success'
        && requestedConfigBody.id === 10
        && requestedConfigBody.hotel_id === '20'
        && requestedConfigBody.ctrip_hotel_id === 'ctrip-20'
        && requestedConfigBody.cookies === 'sid=save'
        && resetConfigForm.url.includes('getDayReportCompeteHotelReport')
        && resetConfigForm.cookies === ''
        && saveEvents.join('|') === 'notify:info:配置保存成功|reload'
        && saveLogs.length === 0
        && failedResult.status === 'failed'
        && failedEvents[0] === 'notify:error:backend failed'
        && failedLogs[0].includes('携程配置保存失败:')
        && exceptionResult.status === 'exception'
        && exceptionEvents[0] === 'notify:error:保存失败: response parsed failed'
        && exceptionLogs[0].includes('保存失败:')
        && guardResult.status === 'missing_name'
        && guardEvents[0] === 'notify:error:请输入配置名称',
      detail: 'runCtripConfigSaveFlow state samples',
    });
  }
  if (typeof runCtripManualTabSwitch !== 'function') {
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip static exports manual tab switch helper',
      ok: false,
      detail: 'runCtripManualTabSwitch',
    });
  } else {
    const manualTabEvents = [];
    let activeManualTab = 'ctrip-ads';
    const manualAdsResult = await runCtripManualTabSwitch({
      tab: 'ctrip-ads',
      getCurrentPage: () => 'ctrip-ebooking',
      getCurrentTab: () => activeManualTab,
      loadConfigList: async () => { manualTabEvents.push('load'); },
      applyHotelConfig: async flag => { manualTabEvents.push(`apply:${flag}`); },
      syncAdsConfig: async flag => { manualTabEvents.push(`ads:${flag}`); },
      hasSelectedHotel: () => true,
    });
    const staleManualEvents = [];
    let staleManualTab = 'ctrip-flow-overview';
    const staleManualResult = await runCtripManualTabSwitch({
      tab: 'ctrip-flow-overview',
      getCurrentPage: () => 'ctrip-ebooking',
      getCurrentTab: () => staleManualTab,
      loadConfigList: async () => {
        staleManualEvents.push('load');
        staleManualTab = 'ctrip-ads';
      },
      applyHotelConfig: async () => { staleManualEvents.push('apply'); },
      hasSelectedHotel: () => true,
    });
    const healthEvents = [];
    const healthResult = await runCtripManualTabSwitch({
      tab: 'data-health',
      getCurrentPage: () => 'ctrip-ebooking',
      getCurrentTab: () => 'data-health',
      loadDataHealthPanel: async mode => { healthEvents.push(mode); },
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip manual tab switch helper keeps async config loading scoped to the active tab',
      ok: manualAdsResult.status === 'synced'
        && manualAdsResult.target === 'ads'
        && manualTabEvents.join('|') === 'load|apply:false|ads:false'
        && staleManualResult.status === 'stale_after_load'
        && staleManualEvents.join('|') === 'load'
        && healthResult.status === 'synced'
        && healthResult.target === 'data-health'
        && healthEvents.join('|') === 'light',
      detail: 'runCtripManualTabSwitch active/stale/data-health samples',
    });
  }
  if (typeof createCtripProfileFieldForm !== 'function'
    || typeof buildCtripProfileFieldSmartDefaults !== 'function'
    || typeof buildCtripProfileFieldSavePayload !== 'function'
    || typeof buildCtripProfileFieldSampleHelpers !== 'function'
    || typeof buildCtripProfileFieldDerivationHelpers !== 'function'
    || typeof normalizeCtripProfileFieldVerificationStatus !== 'function'
    || typeof ctripProfileFieldVerificationText !== 'function'
    || typeof ctripProfileFieldVerificationBadgeClass !== 'function'
    || typeof ctripProfileFieldVerificationLightClass !== 'function') {
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip static exports Profile field form and sample builders',
      ok: false,
      detail: 'Profile field form and sample builders',
    });
  } else {
    const profileFieldForm = createCtripProfileFieldForm();
    const smartDefaults = buildCtripProfileFieldSmartDefaults({
      page_url: 'https://ebooking.ctrip.com/datacenter/inland/businessreport/flowdata?microJump=true',
      request_url: 'https://ebooking.ctrip.com/restapi/soa2/24588/queryFlowTransforNewV1?hostType=Ebooking',
      json_path: "$.data.metrics[0].visitor_count",
      value_meaning: '访客人数',
    });
    const savePayload = buildCtripProfileFieldSavePayload({
      page_url: 'https://ebooking.ctrip.com/datacenter/inland/businessreport/beneficialdata?microJump=true',
      request_url: 'https://ebooking.ctrip.com/restapi/soa2/24588/queryOrderTrendV1?hostType=Ebooking',
      json_path: "$.data.rows[0].order_amount",
      value_meaning: '收入金额',
      status: 'pending',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip Profile field builders infer defaults and save payloads',
      ok: profileFieldForm.section === 'business_overview'
        && profileFieldForm.notes === ''
        && profileFieldForm.sample_verification_status === 'unverified'
        && smartDefaults.section === 'traffic_report'
        && smartDefaults.sourceKey === 'visitor_count'
        && smartDefaults.endpoint === 'queryFlowTransforNewV1'
        && smartDefaults.valueType === 'integer'
        && smartDefaults.unit === '人'
        && smartDefaults.storageField === 'ota_ctrip_metric_facts.metric_key=visitor_count'
        && savePayload.section === 'sales_report'
        && savePayload.field_key === 'order_amount'
        && savePayload.field_name === '收入金额'
        && savePayload.source_interface === 'queryOrderTrendV1'
        && savePayload.value_type === 'amount'
        && savePayload.unit === '元'
        && savePayload.storage_field === 'online_daily_data.amount'
        && savePayload.status === 'needs_parser',
      detail: 'Profile field builder sample',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip Profile field verification helpers map review statuses',
      ok: normalizeCtripProfileFieldVerificationStatus('ok') === 'matched'
        && normalizeCtripProfileFieldVerificationStatus('wrong') === 'mismatched'
        && normalizeCtripProfileFieldVerificationStatus('') === 'unverified'
        && ctripProfileFieldVerificationText('matched').length > 0
        && ctripProfileFieldVerificationBadgeClass('mismatched').includes('red')
        && ctripProfileFieldVerificationLightClass('matched').includes('emerald'),
      detail: 'Profile field verification helper sample',
    });
    const sampleHelpers = buildCtripProfileFieldSampleHelpers();
    const sampleRows = sampleHelpers.sampleItems({
      latest_values: [
        { value: 12, unit: '间', source_key: 'room_nights', sample_batch_key: 'batch-1' },
        '99',
      ],
    });
    const fallbackRows = sampleHelpers.sampleItems({ latest_value: 'A / B' });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip Profile field sample helpers normalize sample rows and keys',
      ok: sampleHelpers.sampleValueText(sampleRows[0]) === '12间'
        && sampleRows.length === 2
        && fallbackRows.length === 2
        && sampleHelpers.sampleBatchKey(sampleRows[0]) === 'batch-1'
        && sampleHelpers.sampleBatchKey({ sync_task_id: 7 }) === 'sync_task:7'
        && sampleHelpers.sampleText({ latest_value: 'A / B' }).includes('A'),
      detail: 'Profile field sample helper sample',
    });
    const derivationHelpers = buildCtripProfileFieldDerivationHelpers({
      forbiddenFieldKeys: new Set(['guest_phone']),
      captureSectionText: section => `section:${section}`,
      normalizeVerificationStatus: value => String(value || '').trim(),
      sampleTextForField: field => String(field?.latest_value || '').trim(),
    });
    const derivationFields = [
      { field_key: 'order_amount', field_name: 'Revenue', section: 'sales', enabled: 1, status: 'confirmed', latest_value: '100' },
      { field_key: 'visitor_count', field_name: 'Traffic', section: 'traffic', enabled: true, sample_verification_status: 'matched', latest_value: '' },
      { field_key: 'guest_phone', field_name: 'Phone', section: 'order', enabled: true, latest_value: 'secret' },
      { field_key: 'disabled_metric', field_name: 'Disabled', section: 'sales', enabled: 0, latest_value: '' },
    ];
    const filteredTraffic = derivationHelpers.filterFields(derivationFields, { keyword: 'section:traffic' });
    const notReturned = derivationHelpers.filterFields(derivationFields, { sample: 'not_returned' });
    const cards = derivationHelpers.buildAssetLedgerCards({
      fieldVisibleCount: derivationFields.length,
      fieldTotalCount: 5,
      enabledVisibleFieldCount: 2,
      sampledVisibleFieldCount: 1,
      stableVisibleFieldCount: derivationHelpers.countStableFields(derivationFields),
      notReturnedVisibleFieldCount: 1,
      samplesLoaded: true,
      forbiddenFieldCount: 1,
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip Profile field derivation helpers preserve filters, capture boundaries and card counts',
      ok: derivationHelpers.isFieldCollectable(derivationFields[0]) === true
        && derivationHelpers.isFieldForbidden(derivationFields[2]) === true
        && derivationHelpers.isFieldEnabled(derivationFields[3]) === false
        && filteredTraffic.length === 1
        && filteredTraffic[0].field_key === 'visitor_count'
        && notReturned.length === 1
        && notReturned[0].field_key === 'visitor_count'
        && derivationHelpers.buildCaptureResultText({ samplesLoaded: true, enabledCount: 2, sampledCount: 1, missingCount: 1 }).includes('2')
        && cards.length === 6
        && cards.find(card => card.key === 'not_returned')?.value === 1
        && cards.find(card => card.key === 'forbidden')?.value === 1,
      detail: 'Profile field derivation helper sample',
    });
  }
  if (typeof buildCtripBrowserCaptureTargetContext !== 'function'
    || typeof buildCtripBrowserCapturePayload !== 'function'
    || typeof buildCtripBrowserCaptureRequestContext !== 'function'
    || typeof runCtripBrowserCaptureFlow !== 'function') {
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip static exports browser capture context builders',
      ok: false,
      detail: 'buildCtripBrowserCaptureTargetContext/buildCtripBrowserCapturePayload/buildCtripBrowserCaptureRequestContext/runCtripBrowserCaptureFlow',
    });
  } else {
    const missingTarget = buildCtripBrowserCaptureTargetContext({});
    const selectedTarget = buildCtripBrowserCaptureTargetContext({
      selectedCtripHotelId: '',
      autoFetchHotelId: '58',
      userHotelId: '99',
    });
    const payload = buildCtripBrowserCapturePayload({
      systemHotelId: '10',
      hotelId: '24588',
      hotelName: 'Demo Hotel',
      profileId: 'profile-1',
      cookies: 'sid=secret',
      dataDate: '2026-06-10',
      form: { sections: 'default traffic', approvedMappingsPath: '  approved.json  ' },
      options: { captureSections: 'ads reviews', loginOnly: true, bindDataSource: false },
    });
    const fallbackPayload = buildCtripBrowserCapturePayload({
      form: { sections: '' },
      options: {},
    });
    const requestContext = buildCtripBrowserCaptureRequestContext({
      systemHotelId: '58',
      activeConfig: {
        ota_hotel_id: 'ota-58',
        ctrip_hotel_id: 'ctrip-ignored',
        cookies: 'sid=request-context',
      },
      form: { hotelId: '', sections: 'business_overview', approvedMappingsPath: ' approved.json ' },
      overviewForm: { hotelId: 'overview-58', dataDate: '2026-06-10' },
      hotelName: 'Tiancheng Hotel',
      profileId: 'profile-58',
      options: { loginOnly: false, bindDataSource: true },
    });
    const missingProfileContext = buildCtripBrowserCaptureRequestContext({
      systemHotelId: '58',
      activeConfig: { ota_hotel_id: 'ota-58' },
      form: {},
      profileId: '',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip browser capture context keeps target and request fields explicit',
      ok: missingTarget.ok === false
        && missingTarget.result.message === '请选择目标酒店'
        && selectedTarget.ok === true
        && selectedTarget.systemHotelId === '58'
        && requestContext.ok === true
        && requestContext.capturePayload.system_hotel_id === '58'
        && requestContext.capturePayload.hotel_id === 'ota-58'
        && requestContext.capturePayload.hotel_name === 'Tiancheng Hotel'
        && requestContext.capturePayload.profile_id === 'profile-58'
        && requestContext.capturePayload.cookies === 'sid=request-context'
        && requestContext.capturePayload.data_date === '2026-06-10'
        && requestContext.capturePayload.sections[0] === 'business_overview'
        && missingProfileContext.ok === false
        && missingProfileContext.result.message.includes('携程登录会话标识')
        && payload.system_hotel_id === '10'
        && payload.hotel_id === '24588'
        && payload.hotel_name === 'Demo Hotel'
        && payload.profile_id === 'profile-1'
        && payload.cookies === 'sid=secret'
        && payload.data_date === '2026-06-10'
        && payload.login_only === true
        && payload.bind_data_source === false
        && payload.approved_mappings_path === 'approved.json'
        && Array.isArray(payload.sections)
        && payload.sections.join(',') === 'ads,reviews',
      detail: 'buildCtripBrowserCapturePayload sample',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip browser capture payload defaults to default section',
      ok: Array.isArray(fallbackPayload.sections) && fallbackPayload.sections.length === 1 && fallbackPayload.sections[0] === 'default',
      detail: 'sections default',
    });
    const captureFlowEvents = [];
    const captureFlowStates = [];
    let flowSelectedHotelId = '';
    let flowCaptureResult = null;
    let flowOnlineResult = null;
    let flowShowRawData = true;
    let flowCookieProfileId = '';
    let flowProfileStatus = null;
    let flowRequestedPayload = null;
    const flowResult = await runCtripBrowserCaptureFlow({
      options: { captureSections: 'sales_report', bindDataSource: true },
      getSelectedCtripHotelId: () => flowSelectedHotelId,
      setSelectedCtripHotelId: value => {
        flowSelectedHotelId = value;
        captureFlowEvents.push(`selected:${value}`);
      },
      getAutoFetchHotelId: () => '58',
      getUserHotelId: () => '99',
      hasCtripConfigList: () => false,
      loadCtripConfigList: async () => {
        captureFlowEvents.push('load-configs');
      },
      getActiveCtripConfig: () => null,
      findCtripConfigByHotelId: systemHotelId => ({
        system_hotel_id: systemHotelId,
        ota_hotel_id: 'ota-58',
        cookies: 'sid=flow',
      }),
      ensureCtripConfigSecret: async config => {
        captureFlowEvents.push('ensure-secret');
        return config;
      },
      applyCtripConfigObject: config => {
        captureFlowEvents.push(`apply:${config.system_hotel_id}`);
      },
      getBrowserCaptureForm: () => ({ sections: 'default', approvedMappingsPath: ' approved.json ' }),
      getOverviewForm: () => ({ hotelId: 'overview-58', dataDate: '2026-06-10' }),
      getHotelNameById: systemHotelId => `Hotel ${systemHotelId}`,
      resolveProfileId: activeConfig => `profile-${activeConfig.system_hotel_id}`,
      requestCapture: async payload => {
        flowRequestedPayload = payload;
        captureFlowEvents.push('request-capture');
        return { code: 200, message: 'capture ok', data: { saved_count: 5, profile_id: 'profile-58' } };
      },
      setRunning: value => captureFlowStates.push(`running:${value}`),
      setFetching: value => captureFlowStates.push(`fetching:${value}`),
      setCaptureResult: value => { flowCaptureResult = value; },
      setOnlineDataResult: value => { flowOnlineResult = value; },
      setShowRawData: value => { flowShowRawData = value; },
      setCookieApiProfileId: value => { flowCookieProfileId = value; },
      setProfileStatus: value => { flowProfileStatus = value; },
      notify: message => captureFlowEvents.push(`notify:${message}`),
      refreshLatestCtripData: async params => captureFlowEvents.push(`latest:${params.silent}`),
      refreshOnlineHistory: async () => captureFlowEvents.push('history'),
      shouldRefreshDataHealthPanel: () => true,
      refreshDataHealthPanel: async (mode, params) => captureFlowEvents.push(`health:${mode}:${params.force}`),
      refreshPlatformProfileStatus: async params => captureFlowEvents.push(`profile-status:${params.silent}`),
      refreshPlatformDataSources: async () => captureFlowEvents.push('data-sources'),
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip browser capture flow orchestrates request and refresh callbacks',
      ok: flowResult.code === 200
        && flowSelectedHotelId === '58'
        && flowRequestedPayload.system_hotel_id === '58'
        && flowRequestedPayload.hotel_id === 'ota-58'
        && flowRequestedPayload.hotel_name === 'Hotel 58'
        && flowRequestedPayload.profile_id === 'profile-58'
        && flowRequestedPayload.cookies === 'sid=flow'
        && flowRequestedPayload.data_date === '2026-06-10'
        && flowRequestedPayload.sections.join(',') === 'sales_report'
        && flowRequestedPayload.bind_data_source === true
        && flowCaptureResult.saved_count === 5
        && flowOnlineResult.saved_count === 5
        && flowShowRawData === false
        && flowCookieProfileId === ''
        && flowProfileStatus === null
        && captureFlowStates.join('|') === 'running:true|fetching:true|running:false|fetching:false'
        && captureFlowEvents.includes('load-configs')
        && captureFlowEvents.includes('ensure-secret')
        && captureFlowEvents.includes('apply:58')
        && captureFlowEvents.includes('request-capture')
        && captureFlowEvents.includes('latest:true')
        && captureFlowEvents.includes('history')
        && captureFlowEvents.includes('health:light:true')
        && captureFlowEvents.includes('profile-status:true')
        && captureFlowEvents.includes('data-sources'),
      detail: 'runCtripBrowserCaptureFlow success sample',
    });
    const loginFlowEvents = [];
    let loginCookieProfileId = '';
    let loginProfileStatus = null;
    const loginFlowResult = await runCtripBrowserCaptureFlow({
      options: { loginOnly: true, bindDataSource: true, silent: true },
      getSelectedCtripHotelId: () => '58',
      getAutoFetchHotelId: () => '',
      getUserHotelId: () => '',
      hasCtripConfigList: () => true,
      getActiveCtripConfig: () => ({ system_hotel_id: '58', ota_hotel_id: 'ota-58' }),
      ensureCtripConfigSecret: async config => config,
      getBrowserCaptureForm: () => ({}),
      getOverviewForm: () => ({ dataDate: '2026-06-10' }),
      getHotelNameById: () => 'Hotel 58',
      resolveProfileId: () => 'profile-local',
      requestCapture: async () => ({ code: 200, message: 'login ok', data: { profile_id: 'profile-api' } }),
      setCookieApiProfileId: value => { loginCookieProfileId = value; },
      setProfileStatus: value => { loginProfileStatus = value; },
      refreshPlatformProfileStatus: async params => loginFlowEvents.push(`profile-status:${params.silent}`),
      refreshPlatformDataSources: async () => loginFlowEvents.push('data-sources'),
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip browser login flow updates Profile status without data refresh',
      ok: loginFlowResult.code === 200
        && loginCookieProfileId === 'profile-api'
        && loginProfileStatus?.status === 'profile_found'
        && loginFlowEvents.join('|') === 'profile-status:true|data-sources',
      detail: 'runCtripBrowserCaptureFlow login-only sample',
    });
  }
  if (typeof normalizeCtripBrowserCaptureErrorResult !== 'function') {
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip static exports browser capture error normalizer',
      ok: false,
      detail: 'normalizeCtripBrowserCaptureErrorResult',
    });
  } else {
    const errorResult = normalizeCtripBrowserCaptureErrorResult({
      message: 'capture failed',
      data: {
        data: {
          stdout: 'out',
          stderr: 'err',
          partial_capture: { available: true, saved_count: 2 },
        },
      },
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip browser capture error normalizer preserves partial capture evidence',
      ok: errorResult.available === true
        && errorResult.saved_count === 2
        && errorResult.error === 'capture failed'
        && errorResult.stdout === 'out'
        && errorResult.stderr === 'err'
        && errorResult.partial_capture?.available === true,
      detail: 'partial_capture',
    });
  }
  if (typeof buildCtripFetchDateRange !== 'function'
    || typeof buildCtripFetchRequestBody !== 'function'
    || typeof buildCtripFetchRequestContext !== 'function'
    || typeof selectCtripFetchResponsePayload !== 'function'
    || typeof buildCtripFetchMeta !== 'function'
    || typeof buildCtripFetchRawFailureResult !== 'function'
    || typeof runCtripFetchDataFlow !== 'function'
    || typeof isCtripRankingFormAlignedWithConfig !== 'function'
    || typeof buildLatestCtripSnapshotModel !== 'function'
    || typeof buildCtripTrafficFetchRequestBody !== 'function'
    || typeof runCtripTrafficFetchFlow !== 'function'
    || typeof buildCtripOverviewFetchRequestBody !== 'function'
    || typeof runCtripOverviewFetchFlow !== 'function'
    || typeof buildCtripAdsFetchRequestBody !== 'function'
    || typeof runCtripAdsFetchFlow !== 'function'
    || typeof buildCtripCookieApiFetchRequestBody !== 'function'
    || typeof runCtripCookieApiCaptureFlow !== 'function'
    || typeof isCtripAdsApiUrl !== 'function'
    || typeof normalizeCtripAdsApiType !== 'function'
    || typeof buildCtripTrafficResponseModel !== 'function') {
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip static exports fetch request builders',
      ok: false,
      detail: 'Ctrip fetch context, flow, latest snapshot, traffic runner, overview, ads, and Cookie API builders/flow',
    });
  } else {
    const defaultRange = buildCtripFetchDateRange({}, new Date('2026-06-10T12:00:00Z'));
    const explicitRange = buildCtripFetchDateRange({ startDate: '2026-06-01', endDate: '2026-06-10' });
    const fetchBody = buildCtripFetchRequestBody({
      form: { url: ' https://ebooking.ctrip.test/api ', auth_data: { token: 'demo' } },
      cookies: 'sid=abc',
      nodeId: '24588',
      startDate: '2026-06-01',
      endDate: '2026-06-10',
      systemHotelId: '58',
    });
    const fallbackBody = buildCtripFetchRequestBody({
      form: { url: '   ' },
      cookies: 'sid=abc',
      startDate: '2026-06-09',
      endDate: '2026-06-09',
    });
    const fetchContext = buildCtripFetchRequestContext({
      form: {
        url: ' https://ebooking.ctrip.test/api ',
        cookies: ' sid=context ',
        nodeId: '24588',
        startDate: '2026-06-01',
        endDate: '2026-06-10',
        auth_data: { token: 'ctx' },
      },
      selectedCtripHotelId: '58',
    });
    const missingCredentialContext = buildCtripFetchRequestContext({
      form: { cookies: '   ' },
      selectedCtripHotelId: '58',
    });
    const multiDatePayload = selectCtripFetchResponsePayload({
      date_results: [{ date: '2026-06-09' }, { date: '2026-06-10' }],
      data: [{ ignored: true }],
    });
    const singleDatePayload = selectCtripFetchResponsePayload({
      date_results: [{ date: '2026-06-09' }],
      data: [{ kept: true }],
    });
    const fetchMeta = buildCtripFetchMeta({
      hotelId: '58',
      startDate: '2026-06-01',
      endDate: '2026-06-10',
      fetchedAt: '2026-06-10 14:00:00',
      savedCount: 0,
      displayHotelCount: 7,
    });
    const rawFailure = buildCtripFetchRawFailureResult({
      errorMsg: '授权过期',
      rawResponse: 'x'.repeat(1200),
    });
    const fetchFlowEvents = [];
    const fetchFlowStates = [];
    let fetchFlowRequestedBody = null;
    let fetchFlowResultPayload = null;
    let fetchFlowFilterDates = null;
    let fetchFlowLatestMeta = null;
    let fetchFlowTableTab = '';
    let fetchFlowHistorySettled = false;
    let fetchFlowLatestSettled = false;
    const fetchFlowResult = await runCtripFetchDataFlow({
      isLoggedIn: () => true,
      getSelectedCtripHotelId: () => '58',
      notify: (message, level) => fetchFlowEvents.push(`notify:${level || 'info'}:${message}`),
      getActiveCtripConfig: () => ({ id: 1, hotel_id: '58', cookies: 'sid=config' }),
      ensureCtripConfigSecret: async config => {
        fetchFlowEvents.push('ensure-config');
        return config;
      },
      applyCtripConfigObject: config => fetchFlowEvents.push(`apply:${config.hotel_id}`),
      getForm: () => ({
        cookies: ' sid=fetch ',
        nodeId: '24588',
        startDate: '2026-06-01',
        endDate: '2026-06-10',
        auth_data: { token: 'ctx' },
      }),
      setFetching: value => fetchFlowStates.push(`fetching:${value}`),
      setShowRawData: value => fetchFlowStates.push(`raw:${value}`),
      setFetchSuccess: value => fetchFlowStates.push(`success:${value}`),
      setSavedCount: value => fetchFlowStates.push(`saved:${value}`),
      debugLog: (message) => fetchFlowEvents.push(`debug:${message}`),
      requestFetch: async requestBody => {
        fetchFlowRequestedBody = requestBody;
        fetchFlowEvents.push('request-fetch');
        return {
          code: 200,
          data: {
            data: [{ order_id: 'o1' }],
            display_hotels: [{ hotel_id: 'h1' }],
            display_summary: { status: 'ok' },
            saved_count: 4,
            fetched_at: '2026-06-10 14:00:00',
          },
        };
      },
      setOnlineDataResult: value => { fetchFlowResultPayload = value; },
      useDisplayHotels: rows => {
        fetchFlowEvents.push(`display-hotels:${rows.length}`);
        return rows;
      },
      setOnlineDataFilterDates: value => { fetchFlowFilterDates = value; },
      getLatestMeta: () => fetchFlowLatestMeta,
      setLatestMeta: value => { fetchFlowLatestMeta = value; },
      setTableTab: value => { fetchFlowTableTab = value; },
      updateAiAnalysisHotelList: () => fetchFlowEvents.push('update-ai-hotels'),
      refreshOnlineHistory: () => new Promise(resolve => {
        setTimeout(() => {
          fetchFlowHistorySettled = true;
          fetchFlowEvents.push('history');
          resolve();
        }, 25);
      }),
      refreshLatestCtripData: params => new Promise(resolve => {
        setTimeout(() => {
          fetchFlowLatestSettled = true;
          fetchFlowEvents.push(`latest:${params.silent}`);
          resolve();
        }, 25);
      }),
      getOnlineDataTab: () => 'data',
      refreshOnlineData: () => fetchFlowEvents.push('refresh-data'),
      handleFetchFailure: async message => fetchFlowEvents.push(`failure:${message}`),
      hasVisibleSnapshot: () => false,
      logError: (message) => fetchFlowEvents.push(`log-error:${message}`),
    });
    const fetchFlowReturnedBeforePostRefresh = !fetchFlowHistorySettled && !fetchFlowLatestSettled;
    let acceptedFetchFlowRequestedBody = null;
    let acceptedFetchFlowResultPayload = null;
    const acceptedFetchFlowEvents = [];
    const acceptedFetchFlowStates = [];
    const acceptedFetchFlowResult = await runCtripFetchDataFlow({
      isLoggedIn: () => true,
      getSelectedCtripHotelId: () => '58',
      notify: (message, level) => acceptedFetchFlowEvents.push(`notify:${level || 'info'}:${message}`),
      getActiveCtripConfig: () => ({ id: 1, hotel_id: '58', cookies: 'sid=config' }),
      ensureCtripConfigSecret: async config => config,
      applyCtripConfigObject: config => acceptedFetchFlowEvents.push(`apply:${config.hotel_id}`),
      getForm: () => ({
        cookies: ' sid=fetch ',
        nodeId: '24588',
        startDate: '2026-06-10',
        endDate: '2026-06-10',
      }),
      setFetching: value => acceptedFetchFlowStates.push(`fetching:${value}`),
      setShowRawData: value => acceptedFetchFlowStates.push(`raw:${value}`),
      setFetchSuccess: value => acceptedFetchFlowStates.push(`success:${value}`),
      setSavedCount: value => acceptedFetchFlowStates.push(`saved:${value}`),
      requestFetch: async requestBody => {
        acceptedFetchFlowRequestedBody = requestBody;
        return {
          code: 200,
          message: 'queued',
          data: { status: 'running', task_id: 'manual-task-1', saved_count: 0 },
        };
      },
      setOnlineDataResult: value => { acceptedFetchFlowResultPayload = value; },
      refreshOnlineHistory: () => acceptedFetchFlowEvents.push('history'),
      refreshLatestCtripData: params => acceptedFetchFlowEvents.push(`latest:${params.silent}`),
      getOnlineDataTab: () => 'data',
      refreshOnlineData: () => acceptedFetchFlowEvents.push('refresh-data'),
    });
    let failedFlowResultPayload = null;
    let failedFlowShowRawData = false;
    const failedFlowEvents = [];
    const failedFlowResult = await runCtripFetchDataFlow({
      isLoggedIn: () => true,
      getSelectedCtripHotelId: () => '58',
      notify: (message, level) => failedFlowEvents.push(`notify:${level || 'info'}:${message}`),
      getActiveCtripConfig: () => ({ hotel_id: '58' }),
      ensureCtripConfigSecret: async config => config,
      getForm: () => ({ cookies: 'sid=fetch', startDate: '2026-06-10', endDate: '2026-06-10' }),
      requestFetch: async () => ({
        code: 500,
        message: '授权过期',
        data: { raw_response: 'raw-body' },
      }),
      setOnlineDataResult: value => { failedFlowResultPayload = value; },
      setShowRawData: value => { failedFlowShowRawData = value; },
      handleFetchFailure: async message => failedFlowEvents.push(`failure:${message}`),
      hasVisibleSnapshot: () => false,
    });
    const guardFlowEvents = [];
    const guardFlowResult = await runCtripFetchDataFlow({
      isLoggedIn: () => false,
      notify: (message, level) => guardFlowEvents.push(`notify:${level}:${message}`),
    });
    const latestModel = buildLatestCtripSnapshotModel({
      metadata: { status: 'success', data_date: '2026-06-09' },
      rank: {
        rows: [{ row_id: 'rank-1' }],
        display_hotels: [{ hotelId: 'h1' }],
        display_summary: { cards: [{ key: 'amount' }] },
        total: 3,
        data_date: '2026-06-09',
      },
      traffic: {
        rows: [{ date: '2026-06-09' }],
        display_traffic_rows: [{ date: '2026-06-09', compareType: 'self' }],
        display_traffic_summary: { status: 'ok' },
      },
      review: {
        rows: [{ review_id: 'r1' }],
        total: 2,
      },
    });
    const emptyLatestModel = buildLatestCtripSnapshotModel({
      metadata: { status: 'missing', status_label: '暂无入库快照' },
      rank: { rows: [], display_hotels: [] },
      traffic: { rows: [], display_traffic_rows: [] },
      review: { rows: [] },
    });
    const trafficBody = buildCtripTrafficFetchRequestBody({
      form: {
        platform: 'ctrip',
        dateRange: 'custom',
        startDate: '2026-06-01',
        endDate: '2026-06-10',
        url: ' https://ebooking.ctrip.test/traffic ',
        extraParams: '{"scope":"self"}',
      },
      cookies: 'sid=traffic',
      systemHotelId: '58',
    });
    const trafficBodyWithoutUrl = buildCtripTrafficFetchRequestBody({
      form: { platform: 'qunar', dateRange: 'yesterday', url: '   ' },
      cookies: 'sid=traffic',
    });
    const overviewBody = buildCtripOverviewFetchRequestBody({
      systemHotelId: '58',
      hotelId: 'ctrip-hotel-1',
      hotelName: 'Tiancheng Hotel',
      cookies: 'sid=overview',
      requestUrls: 'https://ebooking.ctrip.test/overview',
      form: {
        payloadJson: '{"page":1}',
        spidertoken: 'spider',
        method: 'POST',
        dataDate: '2026-06-09',
      },
      defaultMethod: 'GET',
    });
    const flowOverviewBody = buildCtripOverviewFetchRequestBody({
      systemHotelId: '58',
      hotelId: 'ctrip-hotel-1',
      hotelName: 'Tiancheng Hotel',
      cookies: 'sid=flow',
      requestUrls: 'https://ebooking.ctrip.test/flow',
      form: {
        payloadJson: '',
        spidertoken: '',
        dataDate: '2026-06-10',
      },
      defaultMethod: 'GET',
    });
    const runOverviewSample = async (overrides = {}) => {
      const events = [];
      const states = [];
      const form = {
        requestUrls: '',
        cookies: '',
        payloadJson: '',
        spidertoken: '',
        hotelId: '',
        method: '',
        dataDate: '2026-06-10',
        ...(overrides.form || {}),
      };
      let overviewResultPayload = null;
      let overviewOnlinePayload = null;
      let overviewShowRawData = true;
      let overviewRequestBody = null;
      const result = await runCtripOverviewFetchFlow({
        getSystemHotelId: () => (overrides.systemHotelId === undefined ? '58' : overrides.systemHotelId),
        notify: (message, level = 'success') => events.push(['notify', level, message]),
        getActiveCtripConfig: () => (overrides.activeConfig === undefined
          ? { ota_hotel_id: 'ctrip-hotel-1', cookies: 'sid=config' }
          : overrides.activeConfig),
        ensureCtripConfigSecret: async config => {
          events.push(['ensure-config', Boolean(config)]);
          return config;
        },
        applyCtripConfigObject: config => events.push(['apply-config', config?.ota_hotel_id || '']),
        getForm: () => form,
        getCtripCookies: () => (overrides.ctripCookies === undefined ? 'sid=form' : overrides.ctripCookies),
        getFallbackRequestUrls: () => overrides.fallbackRequestUrls || '',
        getHotelNameById: hotelId => `hotel-${hotelId}`,
        setFetching: value => states.push(['fetching', value]),
        setGlobalFetching: value => states.push(['global-fetching', value]),
        setResult: value => { overviewResultPayload = value; },
        setOnlineDataResult: value => { overviewOnlinePayload = value; },
        setShowRawData: value => { overviewShowRawData = value; },
        requestFetch: async requestBody => {
          overviewRequestBody = requestBody;
          events.push(['request-fetch', requestBody]);
          if (overrides.throwRequest) {
            const error = new Error('network failed');
            error.data = { data: { stderr: 'stderr details', error: 'network failed' } };
            throw error;
          }
          return overrides.response || { code: 200, message: '', data: { saved_count: 6, rows: [{ id: 1 }] } };
        },
        refreshLatestCtripData: async options => events.push(['latest', options]),
        refreshOnlineHistory: async () => events.push(['history']),
        defaultMethod: overrides.defaultMethod || 'GET',
        messages: {
          missingRequestUrls: '未配置可用的流量概要直连接口',
          invalidPageUrl: '请填写 Network 中的 JSON 接口 URL，不是携程概况页面地址',
          missingCookies: '请提供携程 Cookie',
          successPrefix: '流量概要直连获取完成',
          failure: '流量概要抓取失败',
          exceptionPrefix: '流量概要获取失败',
        },
      });
      return { result, events, states, form, overviewResultPayload, overviewOnlinePayload, overviewShowRawData, overviewRequestBody };
    };
    const overviewFlowSuccess = await runOverviewSample({
      fallbackRequestUrls: 'https://ebooking.ctrip.test/flow',
    });
    const overviewFlowFailure = await runOverviewSample({
      form: { requestUrls: 'https://ebooking.ctrip.test/flow', cookies: 'sid=flow' },
      response: { code: 500, message: 'upstream failed', data: { saved_count: 0 } },
    });
    const overviewFlowException = await runOverviewSample({
      form: { requestUrls: 'https://ebooking.ctrip.test/flow', cookies: 'sid=flow' },
      throwRequest: true,
    });
    const overviewMissingHotel = await runOverviewSample({ systemHotelId: '' });
    const overviewMissingConfig = await runOverviewSample({ activeConfig: null });
    const overviewInvalidUrl = await runOverviewSample({
      form: { requestUrls: 'https://ebooking.ctrip.com/datacenter/inland/businessreport/outline?microJump=true' },
    });
    const overviewMissingCookie = await runOverviewSample({
      form: { requestUrls: 'https://ebooking.ctrip.test/flow', cookies: '' },
      ctripCookies: '',
      activeConfig: { ota_hotel_id: 'ctrip-hotel-1', cookies: '' },
    });
    const adsBody = buildCtripAdsFetchRequestBody({
      systemHotelId: '58',
      hotelId: 'ctrip-hotel-1',
      hotelName: 'Tiancheng Hotel',
      url: 'https://ebooking.ctrip.com/toolcenter/api/cpc/queryCampaignReportList?hostType=HE',
      cookies: 'sid=ads',
      form: {
        apiType: 'custom_ignored',
        dateRange: 'custom',
        startDate: '2026-06-01',
        endDate: '2026-06-10',
      },
    });
    const runAdsSample = async (overrides = {}) => {
      const events = [];
      const states = [];
      const form = {
        url: 'https://ebooking.ctrip.com/toolcenter/api/cpc/queryCampaignReportList?hostType=HE',
        cookies: '',
        apiType: 'effect_report',
        dateRange: 'custom',
        startDate: '2026-06-01',
        endDate: '2026-06-10',
        ...(overrides.form || {}),
      };
      let adsResultPayload = null;
      let adsOnlinePayload = null;
      let adsShowRawData = true;
      let adsRequestBody = null;
      const result = await runCtripAdsFetchFlow({
        getSystemHotelId: () => (overrides.systemHotelId === undefined ? '58' : overrides.systemHotelId),
        notify: (message, level = 'success') => events.push(['notify', level, message]),
        getActiveCtripConfig: () => (overrides.activeConfig === undefined
          ? { ota_hotel_id: 'ctrip-hotel-1', cookies: 'sid=config' }
          : overrides.activeConfig),
        ensureCtripConfigSecret: async config => {
          events.push(['ensure-config', Boolean(config)]);
          return config;
        },
        applyCtripConfigObject: config => events.push(['apply-config', config?.ota_hotel_id || '']),
        syncAdsDirectConfig: async showMessage => events.push(['sync-ads', showMessage]),
        getForm: () => form,
        getCtripCookies: () => (overrides.ctripCookies === undefined ? 'sid=form' : overrides.ctripCookies),
        getHotelNameById: hotelId => `hotel-${hotelId}`,
        defaultAdsUrl: defaultCtripAdsEffectReportUrl,
        adsUrlHint: '广告接口 URL 提示',
        setRunning: value => states.push(['running', value]),
        setGlobalFetching: value => states.push(['global-fetching', value]),
        setResult: value => { adsResultPayload = value; },
        setOnlineDataResult: value => { adsOnlinePayload = value; },
        setShowRawData: value => { adsShowRawData = value; },
        requestFetch: async requestBody => {
          adsRequestBody = requestBody;
          events.push(['request-ads', requestBody]);
          if (overrides.throwRequest) {
            const error = new Error('network failed');
            error.data = { data: { error: 'network failed' } };
            throw error;
          }
          return overrides.response || { code: 200, message: '', data: { saved_count: 5, rows: [{ id: 1 }] } };
        },
        refreshLatestCtripData: async options => events.push(['latest', options]),
        refreshOnlineHistory: async () => events.push(['history']),
      });
      return { result, events, states, form, adsResultPayload, adsOnlinePayload, adsShowRawData, adsRequestBody };
    };
    const adsFlowSuccess = await runAdsSample();
    const adsFlowAccepted = await runAdsSample({
      response: {
        code: 200,
        message: 'ads queued',
        data: {
          status: 'running',
          task_id: 'ads-task-1',
          platform: 'ctrip',
          async: true,
          saved_count: 0,
          request_start_date: '2026-06-01',
          request_end_date: '2026-06-10',
        },
      },
    });
    const adsFlowFailure = await runAdsSample({
      response: { code: 500, message: 'upstream failed', data: { saved_count: 0 } },
    });
    const adsFlowException = await runAdsSample({ throwRequest: true });
    const adsMissingHotel = await runAdsSample({ systemHotelId: '' });
    const adsMissingConfig = await runAdsSample({ activeConfig: null });
    const adsPageUrl = await runAdsSample({
      form: { url: 'https://ebooking.ctrip.com/toolcenter/cpc/pyramid?microJump=true' },
    });
    const adsInvalidUrl = await runAdsSample({
      form: { url: 'https://ebooking.ctrip.com/not/ads/api' },
    });
    const adsMissingCookie = await runAdsSample({
      form: { cookies: '' },
      ctripCookies: '',
      activeConfig: { ota_hotel_id: 'ctrip-hotel-1', cookies: '' },
    });
    const adsMissingCustomDates = await runAdsSample({
      form: { dateRange: 'custom', startDate: '', endDate: '' },
    });
    const cookieApiBody = buildCtripCookieApiFetchRequestBody({
      systemHotelId: '58',
      hotelId: 'ctrip-hotel-1',
      hotelName: 'Tiancheng Hotel',
      profileId: 'profile-1',
      dataDate: '2026-06-10',
      requestUrl: 'https://ebooking.ctrip.com/restapi/soa2/24588/queryHomePageRealTimeData',
      form: { method: 'post', payloadJson: ' {"scope":"core"} ' },
      endpointsJson: '[{"section":"homepage"}]',
      cookies: 'sid=cookie-api',
    });
    const cookieFlowEvents = [];
    const cookieFlowStates = [];
    let cookieSelectedHotelId = '';
    let cookieCaptureResult = null;
    let cookieOnlineResult = null;
    let cookieShowRawData = true;
    let cookieProfileId = '';
    let cookieRequestBody = null;
    const cookieFlowResult = await runCtripCookieApiCaptureFlow({
      getSelectedCtripHotelId: () => cookieSelectedHotelId,
      setSelectedCtripHotelId: value => {
        cookieSelectedHotelId = value;
        cookieFlowEvents.push(`selected:${value}`);
      },
      getAutoFetchHotelId: () => '58',
      getUserHotelId: () => '99',
      hasCtripConfigList: () => false,
      loadCtripConfigList: async () => cookieFlowEvents.push('load-configs'),
      getActiveCtripConfig: () => null,
      findCtripConfigByHotelId: systemHotelId => ({
        system_hotel_id: systemHotelId,
        ota_hotel_id: `ota-${systemHotelId}`,
        cookies: 'sid=config',
        profile_id: `profile-${systemHotelId}`,
      }),
      ensureCtripConfigSecret: async config => {
        cookieFlowEvents.push('ensure-secret');
        return config;
      },
      applyCtripConfigObject: (config, showMessage) => cookieFlowEvents.push(`apply:${config.system_hotel_id}:${showMessage}`),
      getForm: () => ({
        requestUrl: '',
        endpointsJson: '[{"section":"homepage"}]',
        cookies: ' sid=form ',
        method: 'post',
        payloadJson: ' {"scope":"core"} ',
      }),
      getOverviewForm: () => ({ dataDate: '2026-06-10' }),
      getHotelNameById: systemHotelId => `Hotel ${systemHotelId}`,
      resolveProfileId: (systemHotelId, activeConfig) => activeConfig.profile_id || `profile-${systemHotelId}`,
      resolveRequestHotelId: systemHotelId => `request-${systemHotelId}`,
      requestCapture: async body => {
        cookieRequestBody = body;
        cookieFlowEvents.push('request-cookie-api');
        return { code: 200, message: 'cookie ok', data: { saved_count: 7, is_ready: true } };
      },
      setProfileId: value => { cookieProfileId = value; },
      setRunning: value => cookieFlowStates.push(`running:${value}`),
      setFetching: value => cookieFlowStates.push(`fetching:${value}`),
      setCaptureResult: value => { cookieCaptureResult = value; },
      setOnlineDataResult: value => { cookieOnlineResult = value; },
      setShowRawData: value => { cookieShowRawData = value; },
      notify: (message, level) => cookieFlowEvents.push(`notify:${level || 'info'}:${message}`),
      refreshLatestCtripData: async params => cookieFlowEvents.push(`latest:${params.silent}`),
      refreshOnlineHistory: async () => cookieFlowEvents.push('history'),
      shouldRefreshDataHealthPanel: () => true,
      refreshDataHealthPanel: async (mode, params) => cookieFlowEvents.push(`health:${mode}:${params.force}`),
    });
    const cookieNotReadyEvents = [];
    const cookieNotReadyResult = await runCtripCookieApiCaptureFlow({
      getSelectedCtripHotelId: () => '58',
      hasCtripConfigList: () => true,
      getActiveCtripConfig: () => ({ system_hotel_id: '58', profile_id: 'profile-58' }),
      ensureCtripConfigSecret: async config => config,
      getForm: () => ({ requestUrl: 'https://ebooking.ctrip.test/api', endpointsJson: '' }),
      getOverviewForm: () => ({ dataDate: '2026-06-10' }),
      resolveProfileId: () => 'profile-58',
      resolveRequestHotelId: () => 'hotel-58',
      requestCapture: async () => ({ code: 200, message: 'not ready', data: { is_ready: false, warning: 'cookie insufficient' } }),
      notify: (message, level) => cookieNotReadyEvents.push(`notify:${level || 'info'}:${message}`),
    });
    let cookieFailureResultPayload = null;
    const cookieFailureEvents = [];
    const cookieFailureResult = await runCtripCookieApiCaptureFlow({
      getSelectedCtripHotelId: () => '58',
      hasCtripConfigList: () => true,
      getActiveCtripConfig: () => ({ system_hotel_id: '58', profile_id: 'profile-58' }),
      ensureCtripConfigSecret: async config => config,
      getForm: () => ({ requestUrl: 'https://ebooking.ctrip.test/api', endpointsJson: '' }),
      resolveProfileId: () => 'profile-58',
      resolveRequestHotelId: () => 'hotel-58',
      requestCapture: async () => ({
        code: 422,
        message: 'identity failed',
        data: { identity_check: { message: 'hotel mismatch' } },
      }),
      setCaptureResult: value => { cookieFailureResultPayload = value; },
      notify: (message, level) => cookieFailureEvents.push(`notify:${level || 'info'}:${message}`),
    });
    let cookieExceptionResultPayload = null;
    const cookieExceptionEvents = [];
    const cookieExceptionResult = await runCtripCookieApiCaptureFlow({
      getSelectedCtripHotelId: () => '58',
      hasCtripConfigList: () => true,
      getActiveCtripConfig: () => ({ system_hotel_id: '58', profile_id: 'profile-58' }),
      ensureCtripConfigSecret: async config => config,
      getForm: () => ({ requestUrl: 'https://ebooking.ctrip.test/api', endpointsJson: '' }),
      resolveProfileId: () => 'profile-58',
      resolveRequestHotelId: () => 'hotel-58',
      requestCapture: async () => {
        const error = new Error('network failed');
        error.data = { data: { message: 'request blocked' } };
        throw error;
      },
      setCaptureResult: value => { cookieExceptionResultPayload = value; },
      notify: (message, level) => cookieExceptionEvents.push(`notify:${level || 'info'}:${message}`),
    });
    const cookieMissingProfileEvents = [];
    const cookieMissingProfileResult = await runCtripCookieApiCaptureFlow({
      getSelectedCtripHotelId: () => '58',
      hasCtripConfigList: () => true,
      getActiveCtripConfig: () => ({ system_hotel_id: '58' }),
      ensureCtripConfigSecret: async config => config,
      getForm: () => ({ requestUrl: 'https://ebooking.ctrip.test/api', endpointsJson: '' }),
      resolveProfileId: () => '',
      notify: (message, level) => cookieMissingProfileEvents.push(`notify:${level}:${message}`),
    });
    const cookieMissingSourceEvents = [];
    const cookieMissingSourceResult = await runCtripCookieApiCaptureFlow({
      getSelectedCtripHotelId: () => '58',
      getForm: () => ({ requestUrl: '   ', endpointsJson: '   ' }),
      notify: (message, level) => cookieMissingSourceEvents.push(`notify:${level}:${message}`),
    });
    const trafficModel = buildCtripTrafficResponseModel({
      http_code: 200,
      saved_count: 4,
      platform: 'ctrip',
      request_start_date: '2026-06-01',
      request_end_date: '2026-06-10',
      decoded_data: [{ decoded: true }],
      traffic_rows: [{ row_id: 'traffic-1' }],
      display_traffic_rows: [{ date: '2026-06-01', compareType: 'self' }],
      display_traffic_summary: { status: 'ok' },
      raw_response: '{"ok":true}',
      derived_analysis: { conversion: 'stable' },
    });
    const trafficFallbackModel = buildCtripTrafficResponseModel({
      data: [{ decoded: 'fallback' }],
    });
    const runTrafficSample = async (overrides = {}) => {
      const events = [];
      const states = [];
      const form = {
        platform: 'ctrip',
        dateRange: 'custom',
        startDate: '2026-06-01',
        endDate: '2026-06-10',
        url: ' https://ebooking.ctrip.test/traffic ',
        cookies: ' sid=traffic-flow ',
        extraParams: '{"scope":"self"}',
        ...(overrides.form || {}),
      };
      let trafficOnlinePayload = null;
      let trafficRequestBody = null;
      let trafficDisplayArgs = null;
      const result = await runCtripTrafficFetchFlow({
        getSelectedCtripHotelId: () => (overrides.selectedHotelId === undefined ? '58' : overrides.selectedHotelId),
        notify: (message, level = 'success') => events.push(['notify', level, message]),
        getActiveCtripConfig: () => (overrides.activeConfig === undefined
          ? { hotel_id: '58', cookies: 'sid=config' }
          : overrides.activeConfig),
        ensureCtripConfigSecret: async config => {
          events.push(['ensure-config', Boolean(config)]);
          return config;
        },
        applyCtripConfigObject: config => events.push(['apply-config', config?.hotel_id || '']),
        getForm: () => form,
        setFetching: value => states.push(['fetching', value]),
        requestFetch: async requestBody => {
          trafficRequestBody = requestBody;
          events.push(['request-traffic', requestBody]);
          if (overrides.throwRequest) {
            throw new Error('network failed');
          }
          return overrides.response || {
            code: 200,
            data: {
              saved_count: 3,
              decoded_data: [{ decoded: true }],
              traffic_rows: [{ row_id: 'traffic-flow-1' }],
              display_traffic_rows: [{ date: '2026-06-01', compareType: 'self' }],
              display_traffic_summary: { status: 'ok' },
              derived_analysis: { conversion: 'stable' },
            },
          };
        },
        useCtripTrafficDisplayRows: (displayRows, displaySummary, trafficRows, derivedAnalysis) => {
          trafficDisplayArgs = { displayRows, displaySummary, trafficRows, derivedAnalysis };
          return overrides.displayRowsReturn === undefined ? displayRows : overrides.displayRowsReturn;
        },
        setOnlineDataResult: value => { trafficOnlinePayload = value; },
        refreshOnlineHistory: async () => events.push(['history']),
        getOnlineDataTab: () => (overrides.onlineDataTab === undefined ? 'data' : overrides.onlineDataTab),
        refreshOnlineData: () => events.push(['refresh-data']),
        handleFetchFailure: async message => events.push(['failure', message]),
      });
      return { result, events, states, form, trafficOnlinePayload, trafficRequestBody, trafficDisplayArgs };
    };
    const trafficFlowSuccess = await runTrafficSample();
    const trafficFlowAccepted = await runTrafficSample({
      response: {
        code: 200,
        message: 'traffic queued',
        data: {
          status: 'running',
          task_id: 'traffic-task-1',
          platform: 'ctrip',
          async: true,
          saved_count: 0,
          request_start_date: '2026-06-01',
          request_end_date: '2026-06-10',
        },
      },
    });
    const trafficFlowEmpty = await runTrafficSample({
      response: { code: 200, data: { saved_count: 0, data: [], display_traffic_rows: [] } },
      displayRowsReturn: [],
    });
    const trafficFlowFailure = await runTrafficSample({
      response: { code: 500, message: 'upstream traffic failed' },
    });
    const trafficFlowException = await runTrafficSample({ throwRequest: true });
    const trafficMissingHotel = await runTrafficSample({ selectedHotelId: '' });
    const trafficMissingConfig = await runTrafficSample({ activeConfig: null });
    const trafficMissingCookie = await runTrafficSample({
      form: { cookies: '   ' },
      activeConfig: { hotel_id: '58', cookies: '' },
    });
    const trafficMissingCustomDates = await runTrafficSample({
      form: { dateRange: 'custom', startDate: '', endDate: '' },
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip fetch builders keep request fields and date defaults',
      ok: defaultRange.startDate === '2026-06-09'
        && defaultRange.endDate === '2026-06-09'
        && explicitRange.startDate === '2026-06-01'
        && explicitRange.endDate === '2026-06-10'
        && fetchContext.ok === true
        && fetchContext.requestBody.cookies === 'sid=context'
        && fetchContext.requestBody.node_id === '24588'
        && fetchContext.requestBody.system_hotel_id === '58'
        && fetchContext.requestBody.start_date === '2026-06-01'
        && fetchContext.requestBody.end_date === '2026-06-10'
        && fetchContext.debugMeta.node_id === '24588'
        && missingCredentialContext.ok === false
        && missingCredentialContext.message.includes('临时 Cookie/API 辅助内容')
        && fetchBody.url === 'https://ebooking.ctrip.test/api'
        && fetchBody.node_id === '24588'
        && fetchBody.system_hotel_id === '58'
        && fetchBody.cookies === 'sid=abc'
        && fallbackBody.url === undefined
        && fallbackBody.node_id === undefined
        && fallbackBody.system_hotel_id === null,
      detail: 'Ctrip fetch request sample',
    });
    const alignedCtripRankingForm = isCtripRankingFormAlignedWithConfig({
      url: 'https://ebooking.ctrip.test/api',
      nodeId: '24588',
      cookies: 'sid=config',
      auth_data: { token: 'ctx' },
    }, {
      hotel_id: '58',
      url: 'https://ebooking.ctrip.test/api',
      node_id: '24588',
      cookies: 'sid=config',
      auth_data: { token: 'ctx' },
    }, { selectedHotelId: '58' });
    const staleCtripRankingForm = isCtripRankingFormAlignedWithConfig({
      url: 'https://ebooking.ctrip.test/api',
      nodeId: '24588',
      cookies: 'sid=old',
    }, {
      hotel_id: '58',
      url: 'https://ebooking.ctrip.test/api',
      node_id: '24588',
      cookies: 'sid=config',
    }, { selectedHotelId: '58' });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip fetch builders keep response and failure evidence explicit',
      ok: Array.isArray(multiDatePayload.date_results)
        && multiDatePayload.date_results.length === 2
        && Array.isArray(singleDatePayload)
        && singleDatePayload[0].kept === true
        && fetchMeta.data_date === '2026-06-01 至 2026-06-10'
        && fetchMeta.total_records === 7
        && rawFailure.error === '授权过期'
        && rawFailure.raw.length === 1000
        && rawFailure.hint.includes('Cookie是否过期')
        && fetchFlowResult.status === 'success'
        && fetchFlowRequestedBody.async === false
        && fetchFlowRequestedBody.background === false
        && fetchFlowRequestedBody.cookies === 'sid=fetch'
        && fetchFlowRequestedBody.node_id === '24588'
        && fetchFlowRequestedBody.system_hotel_id === '58'
        && fetchFlowResultPayload[0].order_id === 'o1'
        && fetchFlowFilterDates.startDate === '2026-06-01'
        && fetchFlowFilterDates.endDate === '2026-06-10'
        && fetchFlowLatestMeta.total_records === 4
        && fetchFlowLatestMeta.data_date === '2026-06-01 至 2026-06-10'
        && fetchFlowTableTab === 'sales'
        && fetchFlowStates.join('|') === 'fetching:true|raw:false|success:false|saved:0|saved:4|success:true|fetching:false'
        && fetchFlowEvents.includes('ensure-config')
        && fetchFlowEvents.includes('apply:58')
        && fetchFlowEvents.includes('request-fetch')
        && fetchFlowEvents.includes('display-hotels:1')
        && fetchFlowReturnedBeforePostRefresh
        && fetchFlowEvents.includes('refresh-data')
        && alignedCtripRankingForm === true
        && staleCtripRankingForm === false
        && acceptedFetchFlowResult.status === 'accepted'
        && acceptedFetchFlowRequestedBody.async === false
        && acceptedFetchFlowRequestedBody.background === false
        && acceptedFetchFlowResultPayload.status === 'running'
        && acceptedFetchFlowResultPayload.task_id === 'manual-task-1'
        && acceptedFetchFlowEvents.includes('notify:info:queued')
        && acceptedFetchFlowEvents.includes('history')
        && acceptedFetchFlowEvents.includes('latest:true')
        && acceptedFetchFlowEvents.includes('refresh-data')
        && acceptedFetchFlowStates.join('|') === 'fetching:true|raw:false|success:false|saved:0|saved:0|success:false|fetching:false'
        && failedFlowResult.status === 'failed'
        && failedFlowResultPayload.error === '授权过期'
        && failedFlowResultPayload.raw === 'raw-body'
        && failedFlowShowRawData === true
        && failedFlowEvents.includes('failure:授权过期')
        && guardFlowResult.status === 'not_logged_in'
        && guardFlowEvents[0] === 'notify:error:请先登录',
      detail: 'Ctrip fetch response sample',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip latest snapshot model keeps payload slices explicit',
      ok: latestModel.metadata.status === 'success'
        && latestModel.hasRank === true
        && latestModel.rankRows.length === 1
        && latestModel.rankDisplayHotels.length === 1
        && latestModel.rankDisplaySummary.cards.length === 1
        && latestModel.rankTotal === 3
        && latestModel.rankDataDate === '2026-06-09'
        && latestModel.hasTraffic === true
        && latestModel.trafficRows.length === 1
        && latestModel.displayTrafficRows.length === 1
        && latestModel.trafficDisplaySummary.status === 'ok'
        && latestModel.hasReview === true
        && latestModel.reviewResult.saved_count === 2
        && latestModel.onlineResult.source === 'latest'
        && emptyLatestModel.metadata.status === 'missing'
        && emptyLatestModel.hasAnySnapshot === false
        && emptyLatestModel.onlineResult === null,
      detail: 'Ctrip latest snapshot sample',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip traffic builders keep request and display model fields',
      ok: trafficBody.url === 'https://ebooking.ctrip.test/traffic'
        && trafficBody.platform === 'ctrip'
        && trafficBody.date_range === 'custom'
        && trafficBody.start_date === '2026-06-01'
        && trafficBody.end_date === '2026-06-10'
        && trafficBody.cookies === 'sid=traffic'
        && trafficBody.system_hotel_id === '58'
        && trafficBody.extra_params === '{"scope":"self"}'
        && trafficBodyWithoutUrl.url === undefined
        && trafficBodyWithoutUrl.system_hotel_id === null
        && trafficModel.savedCount === 4
        && trafficModel.trafficRows[0].row_id === 'traffic-1'
        && trafficModel.displayTrafficRows[0].compareType === 'self'
        && trafficModel.onlineResult.decoded_data[0].decoded === true
        && trafficModel.onlineResult.raw_response === '{"ok":true}'
        && trafficModel.onlineResult.derived_analysis.conversion === 'stable'
        && trafficFallbackModel.trafficRows[0].decoded === 'fallback'
        && trafficFallbackModel.onlineResult.display_traffic_rows.length === 0,
      detail: 'Ctrip traffic builder sample',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip traffic fetch flow preserves success, empty, failed and exception states',
      ok: trafficFlowSuccess.result.status === 'success'
        && trafficFlowSuccess.trafficRequestBody.url === 'https://ebooking.ctrip.test/traffic'
        && trafficFlowSuccess.trafficRequestBody.async === false
        && trafficFlowSuccess.trafficRequestBody.background === false
        && trafficFlowSuccess.trafficRequestBody.cookies === 'sid=traffic-flow'
        && trafficFlowSuccess.trafficRequestBody.system_hotel_id === '58'
        && trafficFlowSuccess.trafficRequestBody.extra_params === '{"scope":"self"}'
        && trafficFlowSuccess.trafficOnlinePayload.saved_count === 3
        && trafficFlowSuccess.trafficOnlinePayload.traffic_rows[0].row_id === 'traffic-flow-1'
        && trafficFlowSuccess.trafficDisplayArgs.displayRows[0].compareType === 'self'
        && trafficFlowSuccess.trafficDisplayArgs.derivedAnalysis.conversion === 'stable'
        && trafficFlowSuccess.states.join('|') === 'fetching,true|fetching,false'
        && trafficFlowSuccess.events.some(event => event[0] === 'history')
        && trafficFlowSuccess.events.some(event => event[0] === 'refresh-data')
        && trafficFlowSuccess.events.some(event => event[0] === 'notify' && event[1] === 'success' && event[2].includes('获取成功，已保存 3 条流量数据'))
        && trafficFlowAccepted.result.status === 'accepted'
        && trafficFlowAccepted.trafficRequestBody.async === false
        && trafficFlowAccepted.trafficRequestBody.background === false
        && trafficFlowAccepted.trafficOnlinePayload.status === 'running'
        && trafficFlowAccepted.trafficOnlinePayload.task_id === 'traffic-task-1'
        && trafficFlowAccepted.trafficOnlinePayload.saved_count === 0
        && trafficFlowAccepted.trafficDisplayArgs === null
        && trafficFlowAccepted.events.some(event => event[0] === 'notify' && event[1] === 'info' && event[2].includes('traffic queued'))
        && trafficFlowAccepted.events.some(event => event[0] === 'history')
        && trafficFlowAccepted.events.some(event => event[0] === 'refresh-data')
        && trafficFlowAccepted.states.join('|') === 'fetching,true|fetching,false'
        && trafficFlowEmpty.result.status === 'empty'
        && trafficFlowEmpty.events.some(event => event[0] === 'notify' && event[1] === 'warning' && event[2].includes('当前日期范围暂无流量数据'))
        && !trafficFlowEmpty.events.some(event => event[0] === 'history')
        && trafficFlowFailure.result.status === 'failed'
        && trafficFlowFailure.events.some(event => event[0] === 'failure' && event[1] === 'upstream traffic failed')
        && trafficFlowFailure.states.some(event => event[0] === 'fetching' && event[1] === false)
        && trafficFlowException.result.status === 'exception'
        && trafficFlowException.events.some(event => event[0] === 'failure' && event[1] === '请求失败: network failed')
        && trafficFlowException.states.some(event => event[0] === 'fetching' && event[1] === false),
      detail: 'runCtripTrafficFetchFlow state samples',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip traffic fetch flow keeps missing states explicit',
      ok: trafficMissingHotel.result.status === 'missing_hotel'
        && trafficMissingHotel.events.some(event => event[0] === 'notify' && event[1] === 'error' && event[2].includes('请选择目标酒店'))
        && trafficMissingConfig.result.status === 'missing_config'
        && trafficMissingConfig.events.some(event => event[0] === 'notify' && event[1] === 'warning' && event[2].includes('当前酒店未配置携程数据源'))
        && trafficMissingCookie.result.status === 'missing_cookies'
        && trafficMissingCookie.events.some(event => event[0] === 'notify' && event[1] === 'error' && event[2].includes('请提供携程 Cookie'))
        && trafficMissingCustomDates.result.status === 'missing_custom_dates'
        && trafficMissingCustomDates.events.some(event => event[0] === 'notify' && event[1] === 'error' && event[2].includes('请选择自定义开始日期和结束日期')),
      detail: 'runCtripTrafficFetchFlow missing-state samples',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip overview builder keeps request fields and method defaults',
      ok: overviewBody.system_hotel_id === '58'
        && overviewBody.hotel_id === 'ctrip-hotel-1'
        && overviewBody.hotel_name === 'Tiancheng Hotel'
        && overviewBody.cookies === 'sid=overview'
        && overviewBody.request_urls === 'https://ebooking.ctrip.test/overview'
        && overviewBody.payload_json === '{"page":1}'
        && overviewBody.spidertoken === 'spider'
        && overviewBody.method === 'POST'
        && overviewBody.data_date === '2026-06-09'
        && flowOverviewBody.cookies === 'sid=flow'
        && flowOverviewBody.request_urls === 'https://ebooking.ctrip.test/flow'
        && flowOverviewBody.payload_json === ''
        && flowOverviewBody.spidertoken === ''
        && flowOverviewBody.method === 'GET'
        && flowOverviewBody.data_date === '2026-06-10',
      detail: 'Ctrip overview request sample',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip overview fetch flow refreshes persisted and UI data on success',
      ok: overviewFlowSuccess.result.status === 'success'
        && overviewFlowSuccess.overviewRequestBody.system_hotel_id === '58'
        && overviewFlowSuccess.overviewRequestBody.hotel_id === 'ctrip-hotel-1'
        && overviewFlowSuccess.overviewRequestBody.hotel_name === 'hotel-58'
        && overviewFlowSuccess.overviewRequestBody.cookies === 'sid=form'
        && overviewFlowSuccess.overviewRequestBody.request_urls === 'https://ebooking.ctrip.test/flow'
        && overviewFlowSuccess.overviewRequestBody.method === 'GET'
        && overviewFlowSuccess.overviewResultPayload?.saved_count === 6
        && overviewFlowSuccess.overviewOnlinePayload?.saved_count === 6
        && overviewFlowSuccess.overviewShowRawData === false
        && overviewFlowSuccess.events.some(event => event[0] === 'latest' && event[1]?.silent === true)
        && overviewFlowSuccess.events.some(event => event[0] === 'history')
        && overviewFlowSuccess.states.some(event => event[0] === 'fetching' && event[1] === false)
        && overviewFlowSuccess.states.some(event => event[0] === 'global-fetching' && event[1] === false),
      detail: 'runCtripOverviewFetchFlow success sample',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip overview fetch flow keeps failed response visible',
      ok: overviewFlowFailure.result.status === 'failed'
        && overviewFlowFailure.events.some(event => event[0] === 'notify' && event[1] === 'error' && event[2].includes('upstream failed'))
        && overviewFlowFailure.states.some(event => event[0] === 'fetching' && event[1] === false),
      detail: 'runCtripOverviewFetchFlow failed response sample',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip overview fetch flow preserves exception evidence',
      ok: overviewFlowException.result.status === 'exception'
        && overviewFlowException.events.some(event => event[0] === 'notify' && event[1] === 'error' && event[2].includes('流量概要获取失败: network failed'))
        && overviewFlowException.overviewResultPayload?.stderr === 'stderr details'
        && overviewFlowException.states.some(event => event[0] === 'global-fetching' && event[1] === false),
      detail: 'runCtripOverviewFetchFlow exception sample',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip overview fetch flow keeps missing states explicit',
      ok: overviewMissingHotel.result.status === 'missing_hotel'
        && overviewMissingConfig.result.status === 'missing_config'
        && overviewInvalidUrl.result.status === 'invalid_page_url'
        && overviewMissingCookie.result.status === 'missing_cookies'
        && overviewInvalidUrl.events.some(event => event[0] === 'notify' && event[1] === 'error' && event[2].includes('不是携程概况页面地址'))
        && overviewMissingCookie.events.some(event => event[0] === 'notify' && event[1] === 'error' && event[2].includes('请提供携程 Cookie')),
      detail: 'runCtripOverviewFetchFlow missing-state samples',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip ads builders keep request fields and URL guard',
      ok: defaultCtripAdsEffectReportUrl.includes('queryCampaignReportList')
        && isCtripAdsApiUrl(defaultCtripAdsEffectReportUrl) === true
        && isCtripAdsApiUrl('https://ebooking.ctrip.com/toolcenter/cpc/pyramid?microJump=true') === false
        && normalizeCtripAdsApiType('anything') === 'effect_report'
        && adsBody.system_hotel_id === '58'
        && adsBody.hotel_id === 'ctrip-hotel-1'
        && adsBody.hotel_name === 'Tiancheng Hotel'
        && adsBody.url.includes('queryCampaignReportList')
        && adsBody.cookies === 'sid=ads'
        && adsBody.api_type === 'effect_report'
        && adsBody.date_range === 'custom'
        && adsBody.start_date === '2026-06-01'
        && adsBody.end_date === '2026-06-10'
        && adsBody.auto_save === true,
      detail: 'Ctrip ads request sample',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip ads fetch flow refreshes persisted and UI data on success',
      ok: adsFlowSuccess.result.status === 'success'
        && adsFlowSuccess.adsRequestBody.system_hotel_id === '58'
        && adsFlowSuccess.adsRequestBody.async === false
        && adsFlowSuccess.adsRequestBody.background === false
        && adsFlowSuccess.adsRequestBody.hotel_id === 'ctrip-hotel-1'
        && adsFlowSuccess.adsRequestBody.hotel_name === 'hotel-58'
        && adsFlowSuccess.adsRequestBody.url.includes('queryCampaignReportList')
        && adsFlowSuccess.adsRequestBody.cookies === 'sid=form'
        && adsFlowSuccess.adsRequestBody.api_type === 'effect_report'
        && adsFlowSuccess.adsRequestBody.date_range === 'custom'
        && adsFlowSuccess.adsRequestBody.start_date === '2026-06-01'
        && adsFlowSuccess.adsRequestBody.end_date === '2026-06-10'
        && adsFlowSuccess.adsResultPayload?.saved_count === 5
        && adsFlowSuccess.adsOnlinePayload?.saved_count === 5
        && adsFlowSuccess.adsShowRawData === false
        && adsFlowSuccess.events.some(event => event[0] === 'sync-ads' && event[1] === false)
        && adsFlowSuccess.events.some(event => event[0] === 'latest' && event[1]?.silent === true)
        && adsFlowSuccess.events.some(event => event[0] === 'history')
        && adsFlowSuccess.states.some(event => event[0] === 'running' && event[1] === false)
        && adsFlowSuccess.states.some(event => event[0] === 'global-fetching' && event[1] === false),
      detail: 'runCtripAdsFetchFlow success sample',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip ads fetch flow treats background accepted state as explicit running task',
      ok: adsFlowAccepted.result.status === 'accepted'
        && adsFlowAccepted.adsRequestBody.async === false
        && adsFlowAccepted.adsRequestBody.background === false
        && adsFlowAccepted.adsResultPayload?.status === 'running'
        && adsFlowAccepted.adsResultPayload?.task_id === 'ads-task-1'
        && adsFlowAccepted.adsOnlinePayload?.status === 'running'
        && adsFlowAccepted.adsOnlinePayload?.saved_count === 0
        && adsFlowAccepted.adsShowRawData === false
        && adsFlowAccepted.events.some(event => event[0] === 'notify' && event[1] === 'info' && event[2].includes('ads queued'))
        && adsFlowAccepted.events.some(event => event[0] === 'latest' && event[1]?.silent === true)
        && adsFlowAccepted.events.some(event => event[0] === 'history')
        && adsFlowAccepted.states.some(event => event[0] === 'running' && event[1] === false)
        && adsFlowAccepted.states.some(event => event[0] === 'global-fetching' && event[1] === false),
      detail: 'runCtripAdsFetchFlow accepted sample',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip ads fetch flow keeps failed response visible',
      ok: adsFlowFailure.result.status === 'failed'
        && adsFlowFailure.events.some(event => event[0] === 'notify' && event[1] === 'error' && event[2].includes('upstream failed'))
        && adsFlowFailure.states.some(event => event[0] === 'running' && event[1] === false),
      detail: 'runCtripAdsFetchFlow failed response sample',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip ads fetch flow preserves exception evidence',
      ok: adsFlowException.result.status === 'exception'
        && adsFlowException.events.some(event => event[0] === 'notify' && event[1] === 'error' && event[2].includes('广告数据获取失败: network failed'))
        && adsFlowException.adsResultPayload?.error === 'network failed'
        && adsFlowException.states.some(event => event[0] === 'global-fetching' && event[1] === false),
      detail: 'runCtripAdsFetchFlow exception sample',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip ads fetch flow keeps missing states explicit',
      ok: adsMissingHotel.result.status === 'missing_hotel'
        && adsMissingConfig.result.status === 'missing_config'
        && adsPageUrl.result.status === 'invalid_page_url'
        && adsInvalidUrl.result.status === 'invalid_api_url'
        && adsMissingCookie.result.status === 'missing_cookies'
        && adsMissingCustomDates.result.status === 'missing_custom_dates'
        && adsPageUrl.events.some(event => event[0] === 'notify' && event[1] === 'error' && event[2].includes('不是广告页面地址'))
        && adsInvalidUrl.events.some(event => event[0] === 'notify' && event[1] === 'error' && event[2].includes('广告接口 URL 提示'))
        && adsMissingCookie.events.some(event => event[0] === 'notify' && event[1] === 'error' && event[2].includes('请提供携程 Cookie'))
        && adsMissingCustomDates.events.some(event => event[0] === 'notify' && event[1] === 'error' && event[2].includes('请选择自定义开始日期和结束日期')),
      detail: 'runCtripAdsFetchFlow missing-state samples',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip Cookie API builder keeps request fields and normalized payload',
      ok: cookieApiBody.system_hotel_id === '58'
        && cookieApiBody.hotel_id === 'ctrip-hotel-1'
        && cookieApiBody.hotel_name === 'Tiancheng Hotel'
        && cookieApiBody.profile_id === 'profile-1'
        && cookieApiBody.data_date === '2026-06-10'
        && cookieApiBody.request_url.includes('queryHomePageRealTimeData')
        && cookieApiBody.method === 'POST'
        && cookieApiBody.payload_json === '{"scope":"core"}'
        && cookieApiBody.endpoints_json === '[{"section":"homepage"}]'
        && cookieApiBody.cookies === 'sid=cookie-api'
        && cookieApiBody.auto_save === true,
      detail: 'Ctrip Cookie API request sample',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip Cookie API flow preserves request, state, and refresh callbacks',
      ok: cookieFlowResult.status === 'success'
        && cookieSelectedHotelId === '58'
        && cookieProfileId === 'profile-58'
        && cookieRequestBody.system_hotel_id === '58'
        && cookieRequestBody.hotel_id === 'request-58'
        && cookieRequestBody.hotel_name === 'Hotel 58'
        && cookieRequestBody.profile_id === 'profile-58'
        && cookieRequestBody.data_date === '2026-06-10'
        && cookieRequestBody.request_url === ''
        && cookieRequestBody.method === 'POST'
        && cookieRequestBody.payload_json === '{"scope":"core"}'
        && cookieRequestBody.endpoints_json === '[{"section":"homepage"}]'
        && cookieRequestBody.cookies === 'sid=form'
        && cookieCaptureResult.saved_count === 7
        && cookieOnlineResult.saved_count === 7
        && cookieShowRawData === false
        && cookieFlowStates.join('|') === 'running:true|fetching:true|running:false|fetching:false'
        && cookieFlowEvents.includes('selected:58')
        && cookieFlowEvents.includes('load-configs')
        && cookieFlowEvents.includes('ensure-secret')
        && cookieFlowEvents.includes('apply:58:false')
        && cookieFlowEvents.includes('request-cookie-api')
        && cookieFlowEvents.includes('latest:true')
        && cookieFlowEvents.includes('history')
        && cookieFlowEvents.includes('health:light:true'),
      detail: 'Ctrip Cookie API flow success sample',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip Cookie API flow keeps not-ready, failure, exception, and missing states explicit',
      ok: cookieNotReadyResult.status === 'success'
        && cookieNotReadyEvents[0] === 'notify:warning:cookie insufficient'
        && cookieFailureResult.status === 'error_response'
        && cookieFailureResultPayload.identity_check.message === 'hotel mismatch'
        && cookieFailureEvents[0] === 'notify:error:hotel mismatch'
        && cookieExceptionResult.status === 'exception'
        && cookieExceptionResultPayload.message === 'request blocked'
        && cookieExceptionEvents[0] === 'notify:error:request blocked'
        && cookieMissingProfileResult.status === 'missing_profile'
        && cookieMissingProfileEvents[0].includes('携程登录会话标识')
        && cookieMissingSourceResult.status === 'missing_request_source'
        && cookieMissingSourceEvents[0].includes('Request URL'),
      detail: 'Ctrip Cookie API flow failure samples',
    });
  }
  if (typeof buildCtripProfileRecheckInitialState !== 'function'
    || typeof buildCtripProfileRecheckRunContext !== 'function'
    || typeof buildCtripProfileRecheckCaptureRefreshState !== 'function'
    || typeof buildCtripProfileRecheckSuccessResult !== 'function'
    || typeof buildCtripProfileRecheckErrorResult !== 'function'
    || typeof buildCtripProfileRecheckInterruptedState !== 'function'
    || typeof runCtripProfileRecheckFlow !== 'function') {
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip static exports Profile recheck state builders',
      ok: false,
      detail: 'Profile recheck state builders',
    });
  } else {
    const initialState = buildCtripProfileRecheckInitialState({
      canRecapture: true,
      targetCount: 3,
      estimatedText: '预计 1 分钟',
      startedAt: '2026-06-10 14:00:00',
      sections: ['business_overview'],
    });
    const runContext = buildCtripProfileRecheckRunContext({
      targets: [
        { section: 'business_overview' },
        { section: 'business_overview' },
        { section: 'traffic_report' },
      ],
      estimatedText: '预计 2 分钟',
      startedAt: '2026-06-10 14:01:00',
      selectedCtripHotelId: 'hotel_001',
    });
    const defaultRunContext = buildCtripProfileRecheckRunContext({
      targets: [{ section: '' }],
      estimatedText: '预计 1 分钟',
      startedAt: '2026-06-10 14:02:00',
    });
    const refreshState = buildCtripProfileRecheckCaptureRefreshState({
      previousState: initialState,
      captureSucceeded: false,
      captureMessage: '',
    });
    const successResult = buildCtripProfileRecheckSuccessResult({
      previousState: refreshState,
      captureSucceeded: false,
      captureSkipped: true,
      result: { refreshed_count: 2, unresolved_count: 1 },
      durationText: '12秒',
      finishedAt: '2026-06-10 14:00:12',
    });
    const errorResult = buildCtripProfileRecheckErrorResult({
      previousState: initialState,
      message: '接口失败',
      durationText: '8秒',
      finishedAt: '2026-06-10 14:00:08',
      prefix: '不符字段重跑失败: ',
    });
    const interruptedState = buildCtripProfileRecheckInterruptedState({
      previousState: initialState,
      finishedAt: '2026-06-10 14:00:20',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip Profile recheck builders keep capture and refresh states explicit',
      ok: initialState.stage === 'capture'
        && initialState.target_count === 3
        && initialState.sections.includes('business_overview')
        && runContext.canRecapture === true
        && runContext.targetCount === 3
        && runContext.sections.length === 2
        && runContext.requestOptions.method === 'POST'
        && JSON.parse(runContext.requestOptions.body).sections.join(',') === 'business_overview,traffic_report'
        && runContext.initialState.stage === 'capture'
        && runContext.startMessage.includes('开始重抓 3 个')
        && defaultRunContext.canRecapture === false
        && defaultRunContext.sections[0] === 'default'
        && defaultRunContext.initialState.stage === 'refresh_samples'
        && refreshState.type === 'warning'
        && refreshState.stage === 'refresh_samples'
        && refreshState.message.includes('后端未返回成功状态')
        && successResult.state.stage === 'partial'
        && successResult.toastType === 'warning'
        && successResult.message.includes('仅刷新历史获取值')
        && successResult.message.includes('待补解析 1 个'),
      detail: 'Profile recheck state sample',
    });
    const flowStates = [];
    const flowToasts = [];
    const flowEvents = [];
    const flowResponses = [];
    const flowResult = await runCtripProfileRecheckFlow({
      recheckRun: runContext,
      requestSeq: 7,
      getCurrentRequestSeq: () => 7,
      getCurrentState: () => flowStates[flowStates.length - 1] || {},
      setState: state => flowStates.push(state),
      notify: (message, type) => flowToasts.push({ message, type }),
      runBrowserCapture: async options => {
        flowEvents.push({ type: 'capture', options });
        return { code: 200, message: 'capture ok' };
      },
      requestRecheck: async options => {
        flowEvents.push({ type: 'request', options });
        return {
          code: 200,
          data: {
            recheck_result: {
              second_confirmation_count: 4,
              unresolved_count: 1,
            },
            fields: [{ id: 1 }],
          },
        };
      },
      applyResponse: data => flowResponses.push(data),
      getDurationText: () => '5s',
      getFinishedAt: () => '2026-06-10 14:03:00',
      shouldFinalize: () => true,
      onStop: () => flowEvents.push({ type: 'stop' }),
    });
    const flowCaptureEvent = flowEvents.find(event => event.type === 'capture') || {};
    const flowRequestEvent = flowEvents.find(event => event.type === 'request') || {};
    const flowRequestBody = JSON.parse(flowRequestEvent.options?.body || '{}');
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip Profile recheck flow runs capture, request, response, and stop callbacks',
      ok: flowResult.status === 'success'
        && flowStates[0]?.stage === 'capture'
        && flowStates.some(state => state.stage === 'refresh_samples')
        && flowStates[flowStates.length - 1]?.stage === 'done'
        && flowStates[flowStates.length - 1]?.type === 'success'
        && flowToasts[0]?.type === 'info'
        && flowToasts[flowToasts.length - 1]?.type === 'success'
        && flowCaptureEvent.options?.bindDataSource === true
        && flowCaptureEvent.options?.silent === true
        && flowRequestBody.sections?.join(',') === 'business_overview,traffic_report'
        && flowEvents[flowEvents.length - 1]?.type === 'stop'
        && flowResponses[0]?.recheck_result?.second_confirmation_count === 4,
      detail: 'Profile recheck flow sample',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip Profile recheck builders keep error and interruption states visible',
      ok: errorResult.state.type === 'error'
        && errorResult.message === '不符字段重跑失败: 接口失败（耗时 8秒）'
        && interruptedState.type === 'warning'
        && interruptedState.stage === 'partial'
        && interruptedState.message.includes('字段列表在执行中被刷新'),
      detail: 'Profile recheck error sample',
    });
  }
} catch (error) {
  checks.push({
    file: 'public/ctrip-static.js',
    label: 'Ctrip static runtime validation',
    ok: false,
    detail: error.message,
  });
}

try {
  const context = { window: {} };
  vm.runInNewContext(read('public/system-static.js'), context, {
    filename: 'public/system-static.js',
  });
  const getDefaultDataConfigForm = context.window.SUXI_SYSTEM_STATIC?.getDefaultDataConfigForm;
  const getDataConfigTypeDefaults = context.window.SUXI_SYSTEM_STATIC?.getDataConfigTypeDefaults;
  const getSystemConfigDefaults = context.window.SUXI_SYSTEM_STATIC?.getSystemConfigDefaults;
  const createLoginForm = context.window.SUXI_SYSTEM_STATIC?.createLoginForm;
  const getRememberedLoginAccount = context.window.SUXI_SYSTEM_STATIC?.getRememberedLoginAccount;
  const buildLoginRequestPayload = context.window.SUXI_SYSTEM_STATIC?.buildLoginRequestPayload;
  const validateLoginRequestPayload = context.window.SUXI_SYSTEM_STATIC?.validateLoginRequestPayload;
  const applyRememberedLoginAccount = context.window.SUXI_SYSTEM_STATIC?.applyRememberedLoginAccount;
  const createRegisterForm = context.window.SUXI_SYSTEM_STATIC?.createRegisterForm;
  const buildRegisterRequestPayload = context.window.SUXI_SYSTEM_STATIC?.buildRegisterRequestPayload;
  const validateRegisterRequestPayload = context.window.SUXI_SYSTEM_STATIC?.validateRegisterRequestPayload;
  const createHotelForm = context.window.SUXI_SYSTEM_STATIC?.createHotelForm;
  const buildHotelSavePayload = context.window.SUXI_SYSTEM_STATIC?.buildHotelSavePayload;
  const buildHotelOtaCtripConfigSavePayload = context.window.SUXI_SYSTEM_STATIC?.buildHotelOtaCtripConfigSavePayload;
  const buildHotelOtaMeituanConfigSavePayload = context.window.SUXI_SYSTEM_STATIC?.buildHotelOtaMeituanConfigSavePayload;
  const buildHotelPlatformBindingRows = context.window.SUXI_SYSTEM_STATIC?.buildHotelPlatformBindingRows;
  if (typeof getDefaultDataConfigForm !== 'function') {
    checks.push({
      file: 'public/system-static.js',
      label: 'system static exports data config default form builder',
      ok: false,
      detail: 'getDefaultDataConfigForm',
    });
  } else {
    const first = getDefaultDataConfigForm();
    const second = getDefaultDataConfigForm();
    checks.push({
      file: 'public/system-static.js',
      label: 'data config default form keeps OTA config defaults',
      ok: first.platform === 'Ctrip'
        && first.rank_type === 'P_RZ'
        && Array.isArray(first.rank_types)
        && first.rank_types.includes('P_ZH')
        && first.api_type === 'effect_report'
        && first.reply_type === '2',
      detail: 'getDefaultDataConfigForm sample',
    });
    first.rank_types.push('mutated');
    checks.push({
      file: 'public/system-static.js',
      label: 'data config default form returns fresh mutable arrays',
      ok: Array.isArray(second.rank_types) && !second.rank_types.includes('mutated'),
      detail: 'rank_types',
    });
  }
  if (typeof getDataConfigTypeDefaults !== 'function') {
    checks.push({
      file: 'public/system-static.js',
      label: 'system static exports data config type defaults',
      ok: false,
      detail: 'getDataConfigTypeDefaults',
    });
  } else {
    const ctripDefaults = getDataConfigTypeDefaults('ctrip-ebooking');
    const meituanDefaults = getDataConfigTypeDefaults('meituan-ebooking');
    const bookingDefaults = getDataConfigTypeDefaults('booking-ota');
    checks.push({
      file: 'public/system-static.js',
      label: 'data config type defaults keep OTA source presets',
      ok: ctripDefaults.node_id === '24588'
        && ctripDefaults.nodeId === '24588'
        && String(ctripDefaults.url || '').includes('ebooking.ctrip.com')
        && meituanDefaults.rank_type === 'P_RZ'
        && meituanDefaults.rankType === 'P_RZ'
        && meituanDefaults.data_scope === 'vpoi'
        && bookingDefaults.platform === 'booking'
        && String(bookingDefaults.extra_params || '').includes('Booking.com房费收入'),
      detail: 'getDataConfigTypeDefaults samples',
    });
    checks.push({
      file: 'public/system-static.js',
      label: 'unknown data config type returns empty defaults',
      ok: Object.keys(getDataConfigTypeDefaults('unknown-platform')).length === 0,
      detail: 'unknown-platform',
    });
  }
  if (typeof getSystemConfigDefaults !== 'function') {
    checks.push({
      file: 'public/system-static.js',
      label: 'system static exports system config defaults',
      ok: false,
      detail: 'getSystemConfigDefaults',
    });
  } else {
    const first = getSystemConfigDefaults();
    const second = getSystemConfigDefaults();
    checks.push({
      file: 'public/system-static.js',
      label: 'system config defaults preserve product and security defaults',
      ok: first.system_name === '宿析OS'
        && first.system_description.includes('授权OTA数据')
        && first.menu_online_data_name === '竞对价格监控'
        && first.complaint_mini_page === 'pages/complaint/index'
        && first.complaint_mini_use_scene === '1'
        && first.login_max_attempts === '5'
        && first.notify_email_port === '587',
      detail: 'getSystemConfigDefaults sample',
    });
    first.system_name = 'mutated';
    checks.push({
      file: 'public/system-static.js',
      label: 'system config defaults return fresh objects',
      ok: second.system_name === '宿析OS',
      detail: 'system_name',
    });
  }
  if (typeof createLoginForm !== 'function'
    || typeof getRememberedLoginAccount !== 'function'
    || typeof buildLoginRequestPayload !== 'function'
    || typeof validateLoginRequestPayload !== 'function'
    || typeof applyRememberedLoginAccount !== 'function') {
    checks.push({
      file: 'public/system-static.js',
      label: 'system static exports login form helpers',
      ok: false,
      detail: 'createLoginForm/getRememberedLoginAccount/buildLoginRequestPayload/validateLoginRequestPayload/applyRememberedLoginAccount',
    });
  } else {
    const storageMap = new Map([
      ['remembered_username', 'manager01'],
      ['remembered_password', 'legacy-secret'],
    ]);
    const storage = {
      getItem: key => storageMap.get(key) || '',
      setItem: (key, value) => storageMap.set(key, String(value)),
      removeItem: key => storageMap.delete(key),
    };
    const remembered = getRememberedLoginAccount(storage);
    const loginPayload = buildLoginRequestPayload({ username: ' manager01 ', password: 'secret123' });
    applyRememberedLoginAccount({ storage, username: loginPayload.username, remember: true });
    const rememberedPasswordAfterSave = storageMap.has('remembered_password');
    applyRememberedLoginAccount({ storage, username: loginPayload.username, remember: false });
    checks.push({
      file: 'public/system-static.js',
      label: 'system static login helpers preserve account-only storage and explicit validation',
      ok: remembered.username === 'manager01'
        && remembered.remember === true
        && remembered.form.username === 'manager01'
        && remembered.form.password === ''
        && !storageMap.has('remembered_password')
        && createLoginForm({ username: 'u1' }).password === ''
        && loginPayload.username === ' manager01 '
        && loginPayload.password === 'secret123'
        && validateLoginRequestPayload(loginPayload) === ''
        && validateLoginRequestPayload({ username: '', password: 'secret123' }).includes('用户名')
        && rememberedPasswordAfterSave === false
        && !storageMap.has('remembered_username'),
      detail: 'login helper samples and remembered_password cleanup',
    });
  }
  if (typeof createRegisterForm !== 'function' || typeof buildRegisterRequestPayload !== 'function' || typeof validateRegisterRequestPayload !== 'function') {
    checks.push({
      file: 'public/system-static.js',
      label: 'system static exports register form helpers',
      ok: false,
      detail: 'createRegisterForm/buildRegisterRequestPayload/validateRegisterRequestPayload',
    });
  } else {
    const first = createRegisterForm();
    const second = createRegisterForm();
    first.username = 'mutated';
    const payload = buildRegisterRequestPayload({
      username: ' test_user ',
      realname: ' 店长 ',
      password: 'secret123',
      confirm_password: 'secret123',
    });
    checks.push({
      file: 'public/system-static.js',
      label: 'system static register helpers preserve defaults, normalization, and explicit validation',
      ok: first.username === 'mutated'
        && second.username === ''
        && payload.username === 'test_user'
        && payload.realname === '店长'
        && payload.password === 'secret123'
        && payload.confirm_password === 'secret123'
        && validateRegisterRequestPayload(payload) === ''
        && validateRegisterRequestPayload({ ...payload, confirm_password: 'other' }).includes('不一致')
        && validateRegisterRequestPayload({ ...payload, username: '' }).includes('用户名'),
      detail: 'createRegisterForm/buildRegisterRequestPayload/validateRegisterRequestPayload samples',
    });
  }
  if (typeof createHotelForm !== 'function' || typeof buildHotelSavePayload !== 'function') {
    checks.push({
      file: 'public/system-static.js',
      label: 'system static exports hotel admin form helpers',
      ok: false,
      detail: 'createHotelForm/buildHotelSavePayload',
    });
  } else {
    const created = createHotelForm({ operatorName: '店长A', code: 'H009' });
    const edited = createHotelForm({
      hotel: { id: 7, name: '门店七', code: 'H007', address: ' 西湖 ', contact_person: '', contact_phone: '138', status: 0 },
      operatorName: '管理员',
      parsedDescription: { description: '旧描述' },
    });
    const payload = buildHotelSavePayload({
      form: { name: ' 门店七 ', address: ' 地址 ', contact_person: '', contact_phone: ' 139 ', status: '1' },
      normalizedCode: 'H007',
      operatorName: '管理员',
      description: '经营画像',
    });
    checks.push({
      file: 'public/system-static.js',
      label: 'hotel admin form helpers preserve defaults and payload normalization',
      ok: created.id === null
        && created.code === 'H009'
        && created.contact_person === '店长A'
        && edited.id === 7
        && edited.name === '门店七'
        && edited.contact_person === '管理员'
        && edited.status === 0
        && edited.description === '旧描述'
        && payload.name === '门店七'
        && payload.address === '地址'
        && payload.contact_person === '管理员'
        && payload.contact_phone === '139'
        && payload.status === 1
        && payload.description === '经营画像',
      detail: 'createHotelForm/buildHotelSavePayload samples',
    });
  }
  if (typeof buildHotelOtaCtripConfigSavePayload !== 'function' || typeof buildHotelOtaMeituanConfigSavePayload !== 'function') {
    checks.push({
      file: 'public/system-static.js',
      label: 'system static exports hotel OTA config payload helpers',
      ok: false,
      detail: 'buildHotelOtaCtripConfigSavePayload/buildHotelOtaMeituanConfigSavePayload',
    });
  } else {
    const ctripPayload = buildHotelOtaCtripConfigSavePayload({
      hotelIdText: '8',
      ctrip: { cookies: 'new-cookie' },
      existing: { id: 3, name: 'Existing Ctrip', ctripHotelId: 'ota-100', ota_hotel_id: 'ota-200', url: 'existing-url', node_id: 'node-old' },
      fallbackName: 'Fallback Ctrip',
      defaultUrl: 'default-url',
    });
    const ctripOverridePayload = buildHotelOtaCtripConfigSavePayload({
      hotelIdText: '9',
      ctrip: { id: 7, name: 'New Ctrip', ctrip_hotel_id: 'ota-new', cookies: 'cookie-new', url: 'new-url', node_id: 'node-new' },
      existing: { id: 3, name: 'Existing Ctrip', ctripHotelId: 'ota-100', url: 'existing-url', node_id: 'node-old' },
      fallbackName: 'Fallback Ctrip',
      defaultUrl: 'default-url',
    });
    const meituanPayload = buildHotelOtaMeituanConfigSavePayload({
      hotelIdText: '8',
      meituan: { partner_id: 'partner', poi_id: 'poi-1', cookies: 'mt-cookie', hotel_room_count: '', competitor_room_count: '20' },
      existing: { id: 4, name: 'Existing Meituan', hotel_room_count: '80', competitor_room_count: '60' },
      fallbackName: 'Fallback Meituan',
    });
    checks.push({
      file: 'public/system-static.js',
      label: 'hotel OTA config payload helpers preserve save-field precedence',
      ok: ctripPayload.id === 3
        && ctripPayload.name === 'Existing Ctrip'
        && ctripPayload.hotel_id === '8'
        && ctripPayload.ctrip_hotel_id === 'ota-100'
        && ctripPayload.cookies === 'new-cookie'
        && ctripPayload.url === 'existing-url'
        && ctripPayload.node_id === 'node-old'
        && ctripOverridePayload.id === 7
        && ctripOverridePayload.name === 'New Ctrip'
        && ctripOverridePayload.ctrip_hotel_id === 'ota-new'
        && ctripOverridePayload.url === 'new-url'
        && ctripOverridePayload.node_id === 'node-new'
        && meituanPayload.id === 4
        && meituanPayload.name === 'Existing Meituan'
        && meituanPayload.hotel_id === '8'
        && meituanPayload.partner_id === 'partner'
        && meituanPayload.poi_id === 'poi-1'
        && meituanPayload.cookies === 'mt-cookie'
        && meituanPayload.hotel_room_count === '80'
        && meituanPayload.competitor_room_count === '20',
      detail: 'buildHotelOtaCtripConfigSavePayload/buildHotelOtaMeituanConfigSavePayload samples',
    });
  }
  if (typeof buildHotelPlatformBindingRows !== 'function') {
    checks.push({
      file: 'public/system-static.js',
      label: 'system static exports hotel platform binding rows helper',
      ok: false,
      detail: 'buildHotelPlatformBindingRows',
    });
  } else {
    const platformRows = buildHotelPlatformBindingRows({
      hotel: { id: 8, name: 'Hotel A' },
      ctripSource: { id: 31, status: 'success', config: { profile_id: 'ctrip-profile', hotel_id: 'ctrip-8' } },
      meituanProfile: { id: 32, status: 'active', config: {} },
      meituanConfig: { id: 4, cookies: 'mt-cookie' },
      meituanMissingFields: ['partner_id', 'poi_id'],
      helpers: {
        hasPlatformHotelMismatch: () => false,
        isPlatformSourceLoginExpired: () => false,
        platformCaptureStatusCode: () => 'success',
        platformAccountReason: statusCode => ({ text: statusCode, className: `reason-${statusCode}` }),
        formatHotelBindingDate: value => value || '-',
        platformLastSuccessText: () => 'last-success',
        platformAccountStatusText: statusCode => statusCode,
        platformAccountStatusClass: statusCode => `status-${statusCode}`,
        platformCaptureStatusText: captureCode => captureCode,
        platformCaptureStatusClass: captureCode => `capture-${captureCode}`,
      },
    });
    const ctripRow = platformRows.find(row => row.platform === 'ctrip');
    const meituanRow = platformRows.find(row => row.platform === 'meituan');
    checks.push({
      file: 'public/system-static.js',
      label: 'hotel platform binding rows preserve fallback source and missing-config semantics',
      ok: platformRows.length === 2
        && ctripRow?.level === 'ready'
        && ctripRow?.loginItem?.binding?.profile_id === 'ctrip-profile'
        && ctripRow?.canUnbind === false
        && meituanRow?.level === 'partial'
        && meituanRow?.statusCode === 'missing_config'
        && meituanRow?.reasonText === 'missing_config',
      detail: 'buildHotelPlatformBindingRows samples',
    });
  }
} catch (error) {
  checks.push({
    file: 'public/system-static.js',
    label: 'system static runtime validation',
    ok: false,
    detail: error.message,
  });
}

try {
  const context = { window: {} };
  vm.runInNewContext(read('public/notification-static.js'), context, {
    filename: 'public/notification-static.js',
  });
  const buildGlobalNotifications = context.window.SUXI_NOTIFICATION_STATIC?.buildGlobalNotifications;
  if (typeof buildGlobalNotifications !== 'function') {
    checks.push({
      file: 'public/notification-static.js',
      label: 'notification static exports global notification builder',
      ok: false,
      detail: 'buildGlobalNotifications',
    });
  } else {
    const rows = buildGlobalNotifications({
      backendItems: [{ id: 'backend-1', backend_id: 1, source: 'backend', is_read: false }],
      autoFetchRunState: { active: true, message: 'token=abc123 13800138000' },
      autoFetchRunElapsedLabel: '10秒',
      autoFetchStatus: {
        last_run_time: '2026-06-10 10:00:00',
        last_result: { success: true, saved_count: 3 },
      },
      autoFetchRecentRuns: [
        { success: false, run_at: '2026-06-09 08:00:00', data_date: '2026-06-09', message: 'cookie=expired' },
      ],
      dataHealthTodayWorkOrders: [
        { priority: 'high', action_type: 'cookie', key: 'auth', title: '授权过期', detail: 'spidertoken=secret', source_label: '携程', platform_label: 'Ctrip' },
      ],
      readIds: ['auto-fetch-running'],
    });
    checks.push({
      file: 'public/notification-static.js',
      label: 'notification builder keeps active auto-fetch notification readable',
      ok: rows.some(row => row.id === 'auto-fetch-running' && row.is_read === true && /token=\*\*\*\*/.test(row.detail) && row.detail.includes('138****8000')),
      detail: 'auto-fetch-running',
    });
    checks.push({
      file: 'public/notification-static.js',
      label: 'notification builder keeps data-health action target',
      ok: rows.some(row => row.category === 'cookie_alert' && row.severity === 'error' && row.target_page === 'online-data' && row.target_tab === 'data-health'),
      detail: 'cookie_alert',
    });
    checks.push({
      file: 'public/notification-static.js',
      label: 'notification builder deduplicates rows',
      ok: rows.length === new Set(rows.map(row => row.id)).size,
      detail: 'unique ids',
    });
  }
} catch (error) {
  checks.push({
    file: 'public/notification-static.js',
    label: 'notification static runtime validation',
    ok: false,
    detail: error.message,
  });
}

const failures = checks.filter((check) => !check.ok);
if (failures.length) {
  console.error('E2E contract verification failed:');
  for (const failure of failures) {
    console.error(`- ${failure.file}: ${failure.label} (${failure.detail})`);
  }
  process.exit(1);
}

console.log(`E2E contract verification passed (${checks.length} checks).`);
