import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import vm from 'node:vm';
import * as Vue from 'vue';
import { parse, compile } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';
const paths = ['public/app-main.js', 'public/system-static.js', 'public/notification-static.js', 'resources/frontend/templates/fragments/00-app-shell.html'];
const raw = Object.fromEntries(paths.map(path => [path, readFileSync(path, 'utf8')]));
const sha = value => createHash('sha256').update(value).digest('hex').toUpperCase();
const hashes = Object.fromEntries(paths.map(path => [path, sha(raw[path])]));
const main = raw[paths[0]].replaceAll('\r\n', '\n');
const section = (start, end) => { const a = main.indexOf(start), b = main.indexOf(end, a + start.length); assert.ok(a >= 0 && b > a, start); return main.slice(a, b); };
const requestSource = [section('            const BUSINESS_CONTEXT_ENDPOINT_PREFIXES =', '            const userHasPermission ='), section('            const createRequestAbortError =', '            const clearPageLifecycleTimers ='), section('            const currentPageReadPolicy =', '            const cancelPageLoadRequests ='), section('            const COORDINATED_GET_MAX_CONCURRENCY =', '            // API 请求'), section('            const request = async (', '            const apiRequest = request;')].join('\n');
const pieces = {
  read: section('            const loadBackendGlobalNotifications =', '            const refreshGlobalNotifications ='),
  open: section('            const refreshGlobalNotifications =', '            const showStrongOtaReminder ='),
  navigate: section('            const openOnlineDataEntryTab =', '            const openHotelCollectionDeviceOnboarding ='),
};
const astWalk = (node, all = []) => { all.push(node); (node.children || []).forEach(child => astWalk(child, all)); return all; };
// The app-shell fragment intentionally continues its root in later fragments;
// isolate its complete notification subtree before compiling the original UI.
const shell = raw[paths[3]], shellStart = shell.indexOf('<div class="relative header-notification-control">'), shellEnd = shell.indexOf('<div data-testid="header-time-panel"', shellStart);
assert.ok(shellStart >= 0 && shellEnd > shellStart);
const controls = astWalk(parse(shell.slice(shellStart, shellEnd))).find(node => node.type === 1 && node.tag === 'div' && node.props.some(prop => prop.name === 'class' && prop.value?.content === 'relative header-notification-control'));
assert.ok(controls);
const render = new Function('Vue', compile(controls.loc.source, { mode: 'function', prefixIdentifiers: true }).code)(Vue);
const clone = value => JSON.parse(JSON.stringify(value));
const tick = async () => { await Vue.nextTick(); await new Promise(resolve => setImmediate(resolve)); };
let assertions = 0; const eq = (a, b, message) => { assertions++; assert.deepEqual(a, b, message); }; const ok = (value, message) => { assertions++; assert.ok(value, message); };
const row = read => ({ id: 9001, notification_id: 'system-notification-9001', hotel_id: 80, hotel_name: 'Synthetic hotel', platform: 'ctrip', category: 'general', category_label: '系统通知', severity: 'info', title: 'Synthetic ordinary notification', detail: 'Synthetic readonly UI probe', message: 'Synthetic readonly UI probe', is_read: read, created_at: '2026-09-15 09:00:00', updated_at: '2026-09-15 09:00:00', time_label: '2026-09-15 09:00:00', action_type: 'open_page', action_label: '查看处理', target_page: 'online-data', target_tab: 'data-health', action_payload: {}, source_module: 'synthetic_probe', reason_code: '', requires_resolution: false, reminder_level: 'normal', is_direct_recipient: true });
const list = read => ({ code: 200, message: 'success', data: { list: [row(read)], strong_reminders: [], strong_reminder_count: 0, total: 1, unread_count: read ? 0 : 1, poll_interval_ms: 120000 } });
function harness() {
  const requests = [], notices = [], warnings = [], boundaries = [], memoryWrites = [];
  const init = { currentPage: 'knowledge-center', globalNotificationOpen: false, globalNotificationLoading: false, globalNotificationLastLoadedAt: '', globalNotificationBackendItems: [], globalNotificationBackendTotalCount: 0, globalNotificationBackendUnreadCount: 0, globalNotificationPollIntervalMs: 120000, globalNotificationReadIds: [], globalNotificationHiddenIds: [], notificationStaticReady: true, autoFetchRunState: null, autoFetchRunningHint: '', autoFetchRunElapsedSeconds: 0, autoFetchStatus: null, autoFetchRecentRuns: [], dataHealthStaticVersion: 0, onlineDataTab: 'data-health', dataHealthSecondaryPanelsReady: true, dataHealthDetailPanelsReady: true, dataHealthEmployeePanelsReady: true, platformAutoSettingsPanelsReady: true, platformAutoSecondaryPanelsReady: true, platformSourceGuidePanelsReady: true };
  const state = Object.fromEntries(Object.entries(init).map(([key, value]) => [key, Vue.ref(value)]));
  const sandbox = { ...state, computed: Vue.computed, nextTick: Vue.nextTick, window: { confirm: message => { boundaries.push('Synthetic confirmation: ' + message); return true; } }, URL, URLSearchParams, Headers, AbortController, DOMException, Date, Intl, setTimeout, clearTimeout, queueMicrotask,
    console: { error() {}, warn: (...values) => warnings.push(values.map(value => value instanceof Error ? value.message : String(value)).join(' ')) },
    API_BASE: 'https://synthetic.invalid/api', authSessionEpoch: 1, pageRequestGeneration: 0, filterReportHotel: Vue.ref('80'),
    authContext: Vue.ref({ tenantId: 7, hotelId: 80, permissionStatus: 'allowed', platform: 'all' }), permittedHotels: Vue.ref([{ id: 80, tenant_id: 7 }]), hotels: Vue.ref([]),
    user: Vue.ref({ id: 11, is_super_admin: true, tenant_id: 7 }), token: Vue.ref('synthetic-not-a-credential'), revenueAiBusinessDate: Vue.ref('2026-09-15'), coreOperationsTargetDate: Vue.ref('2026-09-15'),
    captureAuthSession: () => ({ epoch: sandbox.authSessionEpoch, token: sandbox.token.value }), isAuthSessionCurrent: session => session.epoch === sandbox.authSessionEpoch,
    isTerminalAuthFailureResponse: () => false, readRequestCooldown: { check: () => null, record() {} },
    showToast: (message, type = 'success') => notices.push({ message, type }),
    fetch: (url, options = {}) => new Promise((resolve, reject) => {
      const parsed = new URL(url); assert.equal(parsed.origin, 'https://synthetic.invalid');
      assert.ok((parsed.pathname === '/api/notifications' && (options.method || 'GET') === 'GET') || (['/api/notifications/read', '/api/notifications/read-all', '/api/notifications/clear'].includes(parsed.pathname) && options.method === 'POST'), 'Closed synthetic notification requests only');
      const req = { url, options, resolve, reject, settled: false }; requests.push(req);
      options.signal?.addEventListener('abort', () => { if (!req.settled) { req.aborted = true; req.settled = true; reject(new DOMException('Synthetic abort', 'AbortError')); } }, { once: true });
    }),
    formatAutoFetchElapsed: () => '', ensureNotificationStaticReady: async () => sandbox.window.SUXI_NOTIFICATION_STATIC,
    syncStrongOtaReminderVisibility: () => { boundaries.push('Strong reminder visibility only; resolution/binding not executed'); },
    scheduleDataHealthPanelRefresh: () => boundaries.push('Data-health GET scheduling closed'),
    clearDataHealthSecondaryPanelsReadyTimer() {}, clearDataHealthDetailPanelsReadyTimer() {}, clearDataHealthEmployeePanelsReadyTimer() {}, clearPlatformAutoSettingsPanelsReadyTimer() {}, clearPlatformAutoSecondaryPanelsReadyTimer() {}, clearPlatformSourceGuidePanelsReadyTimer() {},
    pendingOnlineDataEntryTab: '', openOnlineDataTab: async tab => { assert.ok(['data-health', 'platform-auto'].includes(tab)); boundaries.push('Target data-health/platform-auto loading closed after original page/tab assignment'); },
    globalNotificationReadStorageKey: 'suxios_global_notification_read_ids_v1', saveGlobalNotificationIds: (key, ids) => memoryWrites.push({ key, ids: clone(ids) }),
  };
  vm.createContext(sandbox); vm.runInContext(raw[paths[1]], sandbox); vm.runInContext(raw[paths[2]], sandbox);
  sandbox.appSystemStatic = sandbox.window.SUXI_SYSTEM_STATIC; sandbox.requireAppSystemStatic = key => sandbox.appSystemStatic[key];
  for (const name of ['normalizeBackendGlobalNotification', 'sanitizeGlobalNotificationText', 'buildGlobalNotifications', 'mergeBackendNotificationRows', 'globalNotificationSeverityDotClass', 'globalNotificationBadgeClass']) sandbox[name] = sandbox.window.SUXI_NOTIFICATION_STATIC[name];
  vm.runInContext(requestSource + '\n' + Object.values(pieces).join('\n') + '\nglobalThis.original={markAllGlobalNotificationsRead,clearGlobalNotifications,toggleGlobalNotifications,openGlobalNotification,refreshGlobalNotifications,globalNotificationVisibleItems,globalNotificationUnreadCount,globalNotificationTotalCount,globalNotificationSummaryText};', sandbox);
  const context = { ...state, ...sandbox.original, globalNotificationSeverityDotClass: sandbox.globalNotificationSeverityDotClass, globalNotificationBadgeClass: sandbox.globalNotificationBadgeClass };
  let vnode, nativeInstance;
  sandbox.recoverSuxiRuntimeError = null;
  sandbox.scheduleSuxiStartupError = () => { throw new Error('Unexpected fatal error path'); };
  vm.runInContext(section('            let runtimeErrorRecoveryQueued =', '            const showAuthNotices ='), sandbox);
  const nativeClick = node => {
    assert.ok(node && !node.props.disabled && nativeInstance, 'Real enabled native button and Vue component instance');
    return Vue.callWithAsyncErrorHandling(node.props.onClick, nativeInstance, Vue.ErrorCodes.NATIVE_EVENT_HANDLER, []);
  };
  const html = () => {
    const app = Vue.createSSRApp({ setup: () => { nativeInstance = Vue.getCurrentInstance(); return context; }, render() { vnode = render.call(this, this, []); return vnode; } });
    sandbox.reviewApp = app;
    vm.runInContext('(function(app){' + section('        app.config.errorHandler =', '        app.config.globalProperties.aiModelConfigText =') + '})(reviewApp);', sandbox);
    return renderToString(app);
  };
  const walk = (node, all = []) => { if (Array.isArray(node)) node.forEach(n => walk(n, all)); else if (node && typeof node === 'object') { all.push(node); walk(node.children, all); } return all; };
  const nodes = () => walk(vnode), text = node => typeof node === 'string' ? node : Array.isArray(node) ? node.map(text).join('') : node?.children ? text(node.children) : '';
  const button = label => nodes().find(node => node.type === 'button' && text(node).trim() === label);
  const pending = method => requests.find(req => !req.settled && (req.options.method || 'GET') === method);
  const reply = (req, body, status = 200) => { assert.ok(req && !req.settled); req.settled = true; req.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })); };
  return { state, sandbox, requests, notices, warnings, boundaries, memoryWrites, html, nodes, text, button, pending, reply, nativeClick };
}
async function bell(p) { await p.html(); const control = p.nodes().find(node => node.props?.['data-testid'] === 'header-notification-trigger'); ok(control && !control.props.disabled); control.props.onClick(); await tick(); await p.html(); }
async function ready() { const p = harness(); await bell(p); const req = p.pending('GET'); ok(req); p.reply(req, list(false)); await tick(); await p.html(); eq(p.sandbox.original.globalNotificationUnreadCount.value, 1); return p; }
async function openItem(p, id = 9001) { await p.html(); const item = p.nodes().find(node => node.type === 'button' && node.key === 'system-notification-' + id); ok(item && !item.props.disabled); item.props.onClick(); await tick(); eq(p.state.globalNotificationOpen.value, false); eq(p.state.currentPage.value, 'online-data'); const req = p.pending('POST'); ok(req); eq(JSON.parse(req.options.body), { ids: [id] }); return req; }
const itemState = p => ({ read: p.state.globalNotificationBackendItems.value[0].is_read, unread: p.sandbox.original.globalNotificationUnreadCount.value, notices: clone(p.notices), warnings: clone(p.warnings) });
async function refresh(p, response, status = 200) { await p.html(); const button = p.button('刷新'); ok(button && !button.props.disabled); button.props.onClick(); await tick(); const req = p.pending('GET'); ok(req); p.reply(req, response, status); await tick(); await p.html(); }

import test from 'node:test';

// Helpers above execute the original header subtree, handler, normalizer and
// HTTP coordinator. Only closed synthetic notification POST/GET responses run.
async function settlePost(p, req, receipt = { code: 200, message: 'success', data: { updated_count: 1 } }, status = 200) {
  p.reply(req, receipt, status); await tick(); return p.pending('GET');
}
async function acceptList(p, req, receipt = list(true), status = 200) {
  assert.ok(req, 'Accepted POST starts an authoritative notification list read');
  p.reply(req, receipt, status); await tick(); await p.html();
}
async function switchSessionAndRead(p, oldGet = null) {
  p.sandbox.authSessionEpoch++;
  p.sandbox.token.value = 'synthetic-next-session-not-a-credential';
  p.sandbox.user.value = { id: 12, is_super_admin: true, tenant_id: 7 };
  p.state.globalNotificationBackendItems.value = []; p.state.globalNotificationBackendUnreadCount.value = 0;
  p.state.globalNotificationBackendTotalCount.value = 0; p.state.globalNotificationLastLoadedAt.value = '';
  p.state.globalNotificationOpen.value = false; await bell(p);
  // The original notification queue may wait for the previous physical GET.
  // Deliver that old-session result first; it must not fill the new view.
  if (oldGet && !oldGet.settled) {
    p.reply(oldGet, list(true)); await tick();
    assert.deepEqual(clone(p.state.globalNotificationBackendItems.value), []);
    assert.equal(p.sandbox.original.globalNotificationUnreadCount.value, 0);
  }
  const req = p.requests.findLast(item => !item.settled && (item.options.method || 'GET') === 'GET'); assert.ok(req);
  const current = list(false); current.data.list[0].id = 9002; current.data.list[0].notification_id = 'system-notification-9002'; current.data.list[0].title = 'Synthetic next-session notification';
  p.reply(req, current); await tick(); await p.html(); return JSON.stringify(p.state.globalNotificationBackendItems.value);
}

test('single real item click preserves the verified view until POST and authoritative GET complete', async () => {
  const p = await ready(), req = await openItem(p), pendingView = itemState(p);
  const get = await settlePost(p, req); const acceptedView = itemState(p);
  if (get) await acceptList(p, get);
  assert.equal(pendingView.read, false, 'Pending write cannot prematurely mark the item read'); assert.equal(pendingView.unread, 1);
  assert.equal(acceptedView.read, false, 'Accepted write awaits authoritative list'); assert.equal(acceptedView.unread, 1);
  assert.ok(get); await bell(p); assert.equal(itemState(p).read, true); assert.equal(itemState(p).unread, 0);
  assert.equal(p.requests.length, 3); assert.equal(p.notices.length, 0); assert.ok((await p.html()).includes('is-read'));
});

test('HTTP500 is visible and retains unread state; a second explicit item click can succeed', async () => {
  const p = await ready(), req = await openItem(p); await settlePost(p, req, { code: 500, message: 'Synthetic write failure', data: null }, 500); await bell(p);
  assert.equal(itemState(p).read, false); assert.equal(itemState(p).unread, 1);
  assert.deepEqual(clone(p.notices), [{ type: 'error', message: '通知已读状态未确认，请刷新后重试。' }]);
  assert.equal(p.requests.length, 2, 'No automatic write or read replay');
  const retried = await openItem(p); await acceptList(p, await settlePost(p, retried)); await bell(p);
  assert.equal(itemState(p).read, true); assert.equal(itemState(p).unread, 0);
  assert.equal(p.requests.filter(item => item.options.method === 'POST').length, 2, 'Both writes follow explicit enabled item clicks');
});

test('transport-unknown write preserves unread view and can be resolved by the real refresh GET', async () => {
  const p = await ready(), req = await openItem(p); req.settled = true; req.reject(new TypeError('Synthetic transport lost')); await tick(); await bell(p);
  assert.equal(itemState(p).read, false); assert.equal(itemState(p).unread, 1);
  assert.equal(p.notices.at(-1).message, '通知已读状态未确认，请刷新后重试。'); assert.equal(p.notices.at(-1).type, 'error');
  await refresh(p, list(true)); assert.equal(itemState(p).read, true); assert.equal(itemState(p).unread, 0);
  assert.equal(p.requests.filter(item => item.options.method === 'POST').length, 1, 'GET can confirm a possibly committed write without resubmitting');
});

test('updated_count zero is valid and the newly visible list count is authoritative rather than a guessed delta', async () => {
  const p = await ready(), req = await openItem(p);
  const get = await settlePost(p, req, { code: 200, message: 'success', data: { updated_count: 0 } });
  const current = list(false); current.data.list = [9002, 9003].map(id => ({ ...row(false), id, notification_id: 'system-notification-' + id, title: 'Synthetic current notification ' + id }));
  current.data.total = 2; current.data.unread_count = 2; await acceptList(p, get, current); await bell(p);
  assert.equal(itemState(p).unread, 2); assert.equal(p.state.globalNotificationBackendTotalCount.value, 2);
  assert.deepEqual(clone(p.state.globalNotificationBackendItems.value.map(item => item.backend_id)), [9002, 9003]); assert.equal(p.notices.length, 0);
});

test('accepted POST followed by failed GET is a warning, retains the view and offers manual GET recovery', async () => {
  const p = await ready(), req = await openItem(p); const get = await settlePost(p, req);
  await acceptList(p, get, { code: 500, message: 'Synthetic readback failed', data: null }, 500); await bell(p);
  assert.equal(itemState(p).read, false); assert.equal(itemState(p).unread, 1);
  assert.deepEqual(clone(p.notices), [{ type: 'warning', message: '通知已读已提交，但最新状态刷新失败，请点击刷新重试。' }]);
  await refresh(p, list(true)); assert.equal(itemState(p).unread, 0); assert.equal(itemState(p).read, true);
  assert.equal(p.requests.filter(item => item.options.method === 'POST').length, 1);
});

for (const status of [200, 500]) test(`late prior-session POST ${status} neither starts a new GET nor changes the new session view`, async () => {
  const p = await ready(), req = await openItem(p); const current = await switchSessionAndRead(p); const requestCount = p.requests.length;
  await settlePost(p, req, { code: status, message: 'Synthetic old session', data: status === 200 ? { updated_count: 1 } : null }, status);
  assert.equal(JSON.stringify(p.state.globalNotificationBackendItems.value), current); assert.equal(itemState(p).unread, 1);
  assert.equal(p.requests.length, requestCount); assert.deepEqual(p.notices, []);
});

test('prior-session in-flight authoritative GET cannot overwrite the new session view', async () => {
  const p = await ready(), req = await openItem(p); const oldGet = await settlePost(p, req); assert.ok(oldGet);
  const current = await switchSessionAndRead(p, oldGet);
  assert.equal(JSON.stringify(p.state.globalNotificationBackendItems.value), current); assert.equal(itemState(p).unread, 1); assert.deepEqual(p.notices, []);
});

test('an actual local notification item keeps its original memory-only readIds branch without backend POST', async () => {
  const p = await ready(); p.state.autoFetchRunState.value = { active: true, message: 'Synthetic local activity' }; await p.html();
  const item = p.nodes().find(node => node.type === 'button' && String(node.props?.class || '').includes('header-notification-item') && p.text(node).includes('OTA 自动采集正在运行'));
  assert.ok(item && !item.props.disabled); item.props.onClick(); await tick(); await bell(p);
  assert.deepEqual(clone(p.state.globalNotificationReadIds.value), ['auto-fetch-running']);
  assert.deepEqual(clone(p.memoryWrites), [{ key: 'suxios_global_notification_read_ids_v1', ids: ['auto-fetch-running'] }]);
  assert.equal(p.requests.filter(req => req.options.method === 'POST').length, 0); assert.equal(itemState(p).unread, 1, 'Backend ordinary unread item is unchanged');
});

test('reading a strong reminder does not resolve it or invoke binding actions', async () => {
  const p = harness(); await bell(p);
  const strong = { ...row(false), category: 'ota_auth_required', source_module: 'ota_failure_notifier', reminder_level: 'strong', requires_resolution: true };
  const initial = list(false); initial.data.list = [strong]; initial.data.strong_reminders = [strong]; initial.data.strong_reminder_count = 1;
  p.reply(p.pending('GET'), initial); await tick(); await p.html();
  const req = await openItem(p), get = await settlePost(p, req);
  const saved = clone(initial); saved.data.list[0].is_read = true; saved.data.strong_reminders[0].is_read = true; saved.data.unread_count = 0;
  await acceptList(p, get, saved); await bell(p);
  assert.equal(p.state.globalNotificationBackendItems.value[0].requires_resolution, true); assert.equal(itemState(p).read, true);
  assert.ok(p.requests.every(req => ['/api/notifications', '/api/notifications/read'].includes(new URL(req.url).pathname)));
});

test('POST confirmation supersedes an earlier header GET with a new physical read and no false failure toast', async () => {
  const p = await ready(); await p.html(); const button = p.button('刷新'); assert.ok(button && !button.props.disabled);
  button.props.onClick(); await tick(); const oldGet = p.pending('GET'); assert.ok(oldGet);
  const snapshotBeforeWrite = list(false); // Captured before POST, only delivery is delayed.
  const post = await openItem(p); await settlePost(p, post);
  const fresh = p.requests.find(req => !req.settled && req !== oldGet && (req.options.method || 'GET') === 'GET');
  if (fresh) p.reply(fresh, list(true));
  if (!oldGet.settled) p.reply(oldGet, snapshotBeforeWrite);
  await tick(); await bell(p);
  assert.equal(itemState(p).read, true); assert.equal(itemState(p).unread, 0);
  assert.ok(fresh, 'Acknowledged write needs a distinct HTTP read, not a pre-write snapshot');
  assert.equal(oldGet.aborted, true); assert.equal(p.state.globalNotificationLoading.value, false);
  assert.deepEqual(p.notices, [], 'Expected force supersession is not a failure');
  assert.equal(p.requests.filter(req => (req.options.method || 'GET') === 'GET').length, 3);
  assert.equal(p.requests.filter(req => req.options.method === 'POST').length, 1);
});

test('two explicit notification clicks may supersede the first readback without a false warning', async () => {
  const p = harness(); await bell(p); const initial = list(false);
  initial.data.list.push({ ...row(false), id: 9002, notification_id: 'system-notification-9002', title: 'Synthetic second notification' }); initial.data.total = 2; initial.data.unread_count = 2;
  p.reply(p.pending('GET'), initial); await tick(); await p.html();
  const firstPost = await openItem(p), firstGet = await settlePost(p, firstPost); assert.ok(firstGet);
  const beforeSecondWrite = clone(initial); beforeSecondWrite.data.list[0].is_read = true; beforeSecondWrite.data.unread_count = 1;
  await bell(p); const secondPost = await openItem(p, 9002); await settlePost(p, secondPost);
  const fresh = p.requests.find(req => !req.settled && req !== firstGet && (req.options.method || 'GET') === 'GET');
  const final = clone(initial); final.data.list.forEach(item => { item.is_read = true; }); final.data.unread_count = 0;
  if (fresh) p.reply(fresh, final);
  if (!firstGet.settled) p.reply(firstGet, beforeSecondWrite);
  await tick(); await bell(p);
  assert.equal(itemState(p).unread, 0); assert.ok(p.state.globalNotificationBackendItems.value.every(item => item.is_read));
  assert.ok(fresh); assert.equal(firstGet.aborted, true); assert.deepEqual(p.notices, []);
  assert.equal(p.requests.filter(req => req.options.method === 'POST').length, 2);
});


test('read-all confirmation superseded by a real item read keeps authoritative state without a false warning', async () => {
  const p = await ready();
  p.state.autoFetchRunState.value = { active: true, message: 'Synthetic local activity' };
  await p.html(); const all = p.button('全部已读'); assert.ok(all && !all.props.disabled);
  const completion = p.nativeClick(all); await tick();
  const allPost = p.pending('POST'); assert.ok(allPost);
  assert.equal(new URL(allPost.url).pathname, '/api/notifications/read-all');
  p.reply(allPost, { code: 200, message: 'success', data: { updated_count: 1 } }); await tick();
  const allGet = p.pending('GET'); assert.ok(allGet);
  const snapshotAfterReadAll = list(true); // Its read was accepted before the single-item POST, delivery is delayed.
  await p.html(); assert.equal(p.button('全部已读').props.disabled, true);
  const singlePost = await openItem(p); await settlePost(p, singlePost);
  const fresh = p.requests.find(req => !req.settled && req !== allGet && (req.options.method || 'GET') === 'GET');
  if (fresh) p.reply(fresh, list(true));
  if (!allGet.settled) p.reply(allGet, snapshotAfterReadAll);
  await tick(); await completion; await bell(p);
  assert.ok(fresh); assert.equal(allGet.aborted, true);
  assert.equal(itemState(p).read, true); assert.equal(itemState(p).unread, 0);
  assert.deepEqual(p.notices, [], 'A newer confirmed read replaces this GET; it is not a read-all failure');
  assert.equal(p.state.globalNotificationLoading.value, false);
  assert.deepEqual(clone(p.memoryWrites), [{ key: 'suxios_global_notification_read_ids_v1', ids: ['auto-fetch-running'] }]);
  assert.deepEqual(p.requests.filter(req => req.options.method === 'POST').map(req => new URL(req.url).pathname), ['/api/notifications/read-all', '/api/notifications/read']);
});


test('clear confirmation superseded by a real item read does not escape to the Vue error handler', async () => {
  const p = await ready(); await p.html();
  p.nativeClick(p.button('清空全部普通提醒')); await tick();
  const clearPost = p.pending('POST'); assert.ok(clearPost);
  assert.equal(new URL(clearPost.url).pathname, '/api/notifications/clear');
  assert.deepEqual(JSON.parse(clearPost.options.body), { ids: [] });
  p.reply(clearPost, { code: 200, message: 'success', data: { updated_count: 1, blocked_count: 0 } }); await tick();
  const clearGet = p.pending('GET'); assert.ok(clearGet); assert.equal(p.state.globalNotificationLoading.value, true);
  const singlePost = await openItem(p);
  await settlePost(p, singlePost, { code: 200, message: 'success', data: { updated_count: 0 } });
  const fresh = p.requests.find(req => !req.settled && req !== clearGet && (req.options.method || 'GET') === 'GET'); assert.ok(fresh);
  const empty = list(true); empty.data.list = []; empty.data.total = 0; empty.data.unread_count = 0;
  p.reply(fresh, empty); await tick(); await p.html();
  assert.equal(clearGet.aborted, true);
  assert.deepEqual(clone(p.state.globalNotificationBackendItems.value), []);
  assert.equal(p.sandbox.original.globalNotificationUnreadCount.value, 0);
  assert.deepEqual(p.notices, [], 'Expected read replacement cannot become a global current-operation error');
  assert.equal(p.state.globalNotificationLoading.value, false);
  assert.ok(p.boundaries.some(value => value.startsWith('Synthetic confirmation:')));
  assert.deepEqual(p.requests.filter(req => req.options.method === 'POST').map(req => new URL(req.url).pathname), ['/api/notifications/clear', '/api/notifications/read']);
  assert.equal(p.requests.filter(req => (req.options.method || 'GET') === 'GET').length, 3);
});

test('clear HTTP500 still reaches the original visible Vue error recovery and releases loading', async () => {
  const p = await ready(); await p.html(); p.nativeClick(p.button('清空全部普通提醒')); await tick();
  const post = p.pending('POST'); assert.ok(post); assert.equal(new URL(post.url).pathname, '/api/notifications/clear');
  p.reply(post, { code: 500, message: 'Synthetic clear failed', data: null }, 500); await tick(); await p.html();
  assert.deepEqual(clone(p.notices), [{ type: 'error', message: '当前操作失败：Synthetic clear failed' }]);
  assert.equal(p.state.globalNotificationLoading.value, false);
  assert.equal(itemState(p).read, false); assert.equal(itemState(p).unread, 1);
  assert.ok(!p.button('清空全部普通提醒').props.disabled);
  assert.equal(p.requests.filter(req => req.options.method === 'POST').length, 1);
  assert.equal(p.requests.filter(req => (req.options.method || 'GET') === 'GET').length, 1);
});
