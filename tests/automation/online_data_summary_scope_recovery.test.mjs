import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import * as Vue from 'vue';
import { parse, compile } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';

// Original data controls, current-tab scheduler, both readers and auth/request coordinator.
// Responses are public consumer projections, not backend signing or field evidence.
const repository = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const sourceRoot = path.resolve(process.argv.find(arg => arg.startsWith('--source-root='))?.slice(14) || repository);
const readers = [];
const read = relative => {
  const resolved = path.join(sourceRoot, relative), bytes = fs.readFileSync(resolved);
  readers.push({ path: relative, resolved_path: resolved, sha256: createHash('sha256').update(bytes).digest('hex').toUpperCase() });
  return bytes.toString('utf8').replaceAll('\r\n', '\n');
};
const main = read('public/app-main.js');
const template = read('resources/frontend/templates/fragments/35-page-online-data.html');
const system = read('public/system-static.js');
const cut = (source, from, to) => {
  const a = source.indexOf(from), b = source.indexOf(to, a + from.length); assert.ok(a >= 0 && b > a, from); return source.slice(a, b);
};
const decl = name => {
  const a = main.indexOf(`            const ${name} =`); assert.ok(a >= 0, name);
  const next = /\n            (?:const|let) /.exec(main.slice(a + 1)); assert.ok(next, name); return main.slice(a, a + 1 + next.index);
};
const targetNames = ['permittedHotels', 'captureAuthSession', 'isAuthSessionCurrent', 'readRequestCooldown', 'terminalAuthFailureReason',
  'isTerminalAuthFailureResponse', 'isCompassDataPage', 'isOnlineDataTabVisible', 'isVisibleOnlineDataTab',
  'openOnlineDataTab', 'scheduleOnlineDataTabLoad', 'shouldPrewarmManualOnlineFetchConfig',
  'loadOnlineDataList', 'loadOnlineDataSummary', 'refreshOnlineData', 'isAutoFetchRecordDeletable', 'autoFetchRecordDeletableRows', 'pruneSelectedOnlineDataIds'];
if (main.includes('            const onlineDataSummaryRequestKey =')) targetNames.push('onlineDataSummaryState', 'onlineDataSummaryRequestKey', 'onlineDataSummaryViewState');
const originalSource = [
  cut(main, '    const requireAppSystemStatic = (key) => {', '    const requireUserAdminStatic ='),
  cut(main, '            const BUSINESS_CONTEXT_ENDPOINT_PREFIXES =', '            const userHasPermission ='),
  cut(main, '            const PAGE_LOAD_DEDUP_MS =', '            const activateCoreOperationsAfterLogin ='),
  cut(main, '            const COORDINATED_GET_MAX_CONCURRENCY =', '            // API 请求'),
  cut(main, '            const request = async (', '            const apiRequest = request;'),
  cut(main, '            const ONLINE_DATA_PANEL_CACHE_TTL_MS =', '            const onlineDataCorrectionLedgerFieldLabels ='),
  ...targetNames.map(decl),
  cut(main, '            watch(onlineDataTab, (newTab) => {', '            let meituanHotelConfigApplyVersion ='),
].join('\n');

// Retain complete original ancestors/conditions/if-else chains, omit unrelated siblings.
const ast = parse(template), selected = new Set(), dataStart = template.indexOf('<!-- 数据记录 -->');
const scan = node => {
  if (node.type === 1) {
    const event = node.props.find(prop => prop.name === 'on' && prop.arg?.content === 'click')?.exp?.content;
    const model = node.props.find(prop => prop.name === 'model')?.exp?.content;
    const id = node.props.find(prop => prop.type === 6 && prop.name === 'data-testid')?.value?.content;
    if (event === "openOnlineDataTab('data')" || event === 'refreshOnlineData({ force: true })'
      || (node.loc.start.offset > dataStart && (['onlineDataFilter.start_date', 'onlineDataFilter.end_date', 'onlineDataFilter.source', 'onlineDataFilter.hotel_id'].includes(model)
        || ['online-data-ota-supplement', 'online-data-summary-loading', 'online-data-summary-error', 'online-data-summary-unqueried'].includes(id)))) selected.add(node);
  }
  for (const child of node.children || []) scan(child);
};
scan(ast);
const wrapper = (node, body = '') => node.type === 0 ? body : node.loc.source.slice(0, node.loc.source.indexOf('>') + 1) + body + node.loc.source.slice(node.loc.source.lastIndexOf('</'));
const directive = (node, names) => node.type === 1 && node.props.some(prop => prop.type === 7 && names.includes(prop.name));
const retain = node => {
  if (selected.has(node)) return node.loc.source;
  const children = node.children || [], outputs = children.map(retain);
  for (let i = 0; i < children.length; i++) if (outputs[i] && directive(children[i], ['else', 'else-if'])) {
    for (let j = i - 1; j >= 0; j--) {
      if (children[j].type !== 1) continue;
      if (!directive(children[j], ['if', 'else-if'])) break;
      if (!outputs[j]) outputs[j] = wrapper(children[j]);
      if (directive(children[j], ['if'])) break;
    }
  }
  const body = outputs.join(''); return body ? wrapper(node, body) : '';
};
const render = new Function('Vue', compile(retain(ast), { mode: 'function', prefixIdentifiers: true }).code)(Vue);
const clone = value => JSON.parse(JSON.stringify(value));
const textOf = node => typeof node === 'string' ? node : Array.isArray(node) ? node.map(textOf).join('') : textOf(node?.children || '');
const tick = async () => { await Vue.nextTick(); await new Promise(resolve => setImmediate(resolve)); };
const scopes = {
  A: { hotel_id: '80', source: 'ctrip', start_date: '2026-09-10', end_date: '2026-09-10', spend: 11100 },
  B: { hotel_id: '81', source: 'meituan', start_date: '2026-09-11', end_date: '2026-09-11', spend: 22200 },
};
function harness(t) {
  const requests = [], errors = [], knownErrors = [], timers = [], stops = [], boundaries = [], observations = [];
  const seed = { currentPage: 'online-data', onlineDataTab: 'data', downloadCenterTab: 'ctrip', isLoggedIn: true,
    user: { id: 11, tenant_id: 7, is_super_admin: true, permissions: { can_view_online_data: true } },
    token: 'synthetic-round129-not-a-credential', authContext: { tenantId: 7, hotelId: 80, tokenStatus: 'valid', permissionStatus: 'allowed', platform: 'all' },
    filterReportHotel: '80', coreOperationsTargetDate: '2026-09-19', revenueAiBusinessDate: '2026-09-19',
    onlineDataFilter: { hotel_id: '80', source: 'ctrip', start_date: '2026-09-10', end_date: '2026-09-10', data_type: '' },
    onlineDataPage: 1, onlineDataPagination: { page: 1, page_size: 30, total: null }, onlineDataList: [],
    onlineDataListLoading: false, onlineDataListError: '', onlineDataLoadedQuery: null, onlineDataSummary: null, onlineDataQualitySummary: null,
    selectedOnlineDataIds: [], analysisData: {}, hotels: [{ id: 80, name: '合成酒店 A' }, { id: 81, name: '合成酒店 B' }],
  };
  for (const name of ['dataHealthSecondaryPanelsReady', 'dataHealthDetailPanelsReady', 'dataHealthEmployeePanelsReady',
    'platformAutoSettingsPanelsReady', 'platformAutoSecondaryPanelsReady', 'platformSourceGuidePanelsReady']) seed[name] = false;
  const refs = Object.fromEntries(Object.entries(seed).map(([key, value]) => [key, Vue.ref(clone(value))]));
  const setTimer = (callback, delay = 0) => { const timer = { callback, delay: Number(delay), cleared: false, executed: false }; timers.push(timer); return timer; };
  const clearTimer = timer => { if (timer) timer.cleared = true; };
  const sandbox = { ...refs, ref: Vue.ref, computed: Vue.computed, nextTick: Vue.nextTick, cachedPermittedHotels: clone(seed.hotels),
    watch: (...args) => { const stop = Vue.watch(...args); stops.push(stop); return stop; },
    window: {}, URL, URLSearchParams, Headers, FormData, AbortController, DOMException, Date, TypeError,
    setTimeout: setTimer, clearTimeout: clearTimer, deferUiTask: setTimer, baseScheduleDelayedPageTask: setTimer,
    API_BASE: 'https://synthetic.invalid/api', authSessionEpoch: 1, pageRequestGeneration: 1, dataLoadTimer: null,
    suppressNextOnlineDataTabWatcherLoad: false, suppressNextDataHealthTabLoad: false,
    MANUAL_ONLINE_FETCH_CONFIG_TABS: new Set(['ctrip', 'meituan']), debugLog: () => {},
    clearAuthSessionIfCurrent: () => { throw new Error('Unexpected auth mutation'); },
    console: { error: (...args) => {
      const failure = args.at(-1), call = requests.find(call => call.failure === failure);
      if (call && failure instanceof TypeError && failure.message === 'Failed to fetch'
        && ['API请求失败:', '加载汇总失败:', '刷新数据失败:'].includes(args[0])) { knownErrors.push({ prefix: args[0], path: call.pathname, scope: call.scope }); return; }
      errors.push(args.map(String).join(' '));
    }, warn: (...args) => errors.push(args.map(String).join(' ')) },
    fetch: (url, options) => new Promise((resolve, reject) => {
      const parsed = new URL(url); assert.equal(parsed.origin, 'https://synthetic.invalid'); assert.equal(options.method || 'GET', 'GET');
      assert.ok(['/api/online-data/daily-data-list', '/api/online-data/daily-data-summary'].includes(parsed.pathname), parsed.pathname);
      assert.equal(new Headers(options.headers).get('Authorization'), refs.token.value); assert.ok(options.signal);
      const scope = parsed.searchParams.get('system_hotel_id') === '80' ? 'A' : 'B', expected = scopes[scope];
      for (const [key, value] of Object.entries({ system_hotel_id: expected.hotel_id, source: expected.source, start_date: expected.start_date, end_date: expected.end_date })) assert.equal(parsed.searchParams.get(key), value, key);
      assert.equal(parsed.searchParams.has('data_type'), false);
      const call = { pathname: parsed.pathname, query: parsed.search, scope, resolve, reject, settled: false, aborted: false };
      options.signal.addEventListener('abort', () => { call.aborted = true; if (!call.settled) { call.settled = true; reject(new DOMException('Original abort', 'AbortError')); } });
      requests.push(call);
    }),
  };
  // These original scheduler cleanup branches only release adjacent UI timers.
  for (const name of ['clearManualOnlineFetchConfigPrewarmTimer', 'clearDataHealthSecondaryPanelsReadyTimer', 'clearDataHealthDetailPanelsReadyTimer',
    'clearDataHealthEmployeePanelsReadyTimer', 'clearPlatformAutoSettingsPanelsReadyTimer', 'clearPlatformAutoSecondaryPanelsReadyTimer', 'clearPlatformSourceGuidePanelsReadyTimer']) sandbox[name] = () => boundaries.push(name);
  vm.createContext(sandbox); vm.runInContext(system, sandbox, { filename: 'public/system-static.js' });
  sandbox.appSystemStatic = sandbox.window.SUXI_SYSTEM_STATIC;
  vm.runInContext(originalSource + `\nglobalThis.original = { ${targetNames.join(',')},
    pendingReads: () => [...onlineDataListRequestPromises.values(), ...onlineDataSummaryRequestPromises.values()],
    coordinator: () => ({ active: coordinatedGetActiveCount, queued: coordinatedGetQueue.length, entries: coordinatedGetRequests.size }),
    cache: () => [...onlineDataSummaryResultCache.entries()].map(([key, value]) => ({ key, expiresAt: value.expiresAt })) };`, sandbox);
  let tree;
  const inspect = async () => {
    const app = Vue.createSSRApp({ setup: () => ({ ...refs, ...sandbox.original, formatNumber: sandbox.appSystemStatic.formatNumber }),
      render() { tree = render(this, []); return tree; } });
    app.config.warnHandler = message => { errors.push(message); throw new Error(message); };
    app.config.errorHandler = error => { errors.push(error.message); throw error; };
    const html = await renderToString(app), entries = [];
    const walk = (node, parents = []) => {
      if (Array.isArray(node)) return node.forEach(child => walk(child, parents));
      if (!node || typeof node !== 'object') return;
      entries.push({ node, parents }); walk(node.children, [...parents, node]);
    };
    walk(tree); return { html, entries };
  };
  const enabled = entry => {
    assert.ok(entry); assert.equal(Boolean(entry.node.props?.disabled), false);
    assert.ok(!entry.parents.some(parent => parent.props?.disabled || parent.props?.style?.display === 'none'));
  };
  const button = async text => {
    const page = await inspect(), entry = page.entries.find(({ node }) => node.type === 'button' && textOf(node).trim() === text); enabled(entry); return entry;
  };
  const edit = async key => {
    for (const field of ['hotel_id', 'source', 'start_date', 'end_date']) {
      const page = await inspect(), entry = page.entries.find(({ node }) => ['input', 'select'].includes(node.type)
        && String(node.props?.['onUpdate:modelValue']).includes(`onlineDataFilter.${field}`));
      enabled(entry); entry.node.props['onUpdate:modelValue'](scopes[key][field]); await Vue.nextTick();
    }
    assert.equal(refs.onlineDataTab.value, 'data');
  };
  const tabRead = async () => {
    const entry = await button('记录与下载'); entry.node.props.onClick(); await tick();
    const timer = timers.find(timer => !timer.cleared && !timer.executed && timer.delay === 100); assert.ok(timer);
    timer.executed = true; timer.callback(); await tick();
  };
  const query = async () => {
    const entry = await button('查询'); const pending = entry.node.props.onClick(); await tick(); return { pending };
  };
  const reply = (failSummary = false) => {
    for (const call of requests.filter(call => !call.settled)) {
      call.settled = true;
      if (failSummary && call.pathname.endsWith('summary')) { call.failure = new TypeError('Failed to fetch'); call.reject(call.failure); continue; }
      const scope = scopes[call.scope];
      const data = call.pathname.endsWith('list')
        ? { list: [{ id: Number(scope.hotel_id) * 10, tenant_id: 7, system_hotel_id: Number(scope.hotel_id), source: scope.source, data_date: scope.start_date, data_type: 'advertising' }], pagination: { page: 1, page_size: 30, total: 1 } }
        : { daily: [], total: { total_amount: null, sample_count: 0, data_status: 'pending' }, ota_channel_supplement: {
          scope: 'ota_channel', source_table: 'online_daily_data', data_status: 'partial', data_notice: 'ota_channel_only_not_whole_hotel_scope',
          // Consumed projection of partial advertising aggregates (usable plus excluded rows), not a fabricated signing envelope.
          advertising: { spend: scope.spend, order_amount: null, roas: null, bookings: 0, sample_count: 1, data_status: 'partial' },
          service_quality: { avg_psi_score: null, avg_service_score: null, data_status: 'pending' } } };
      call.resolve(new Response(JSON.stringify({ code: 200, message: '操作成功', time: 1790000000, data }), { status: 200 }));
    }
  };
  const finish = async () => {
    await Promise.allSettled(sandbox.original.pendingReads()); await tick(); assert.deepEqual(errors, []);
    assert.ok(requests.every(call => call.settled && !call.teardown_only));
    assert.deepEqual(clone(sandbox.original.coordinator()), { active: 0, queued: 0, entries: 0 });
  };
  t.after(async () => {
    for (const call of requests.filter(call => !call.settled)) { call.teardown_only = true; call.settled = true; call.reject(new DOMException('Teardown only', 'AbortError')); }
    await Promise.allSettled(sandbox.original.pendingReads()); await tick(); stops.forEach(stop => stop()); timers.forEach(clearTimer);
    t.diagnostic(JSON.stringify({ readers, requests: requests.map(({ pathname, query, scope, settled, aborted, teardown_only }) => ({ pathname, query, scope, settled, aborted, teardown_only })),
      knownErrors, errors, observations, cache: sandbox.original.cache(), coordinator: sandbox.original.coordinator(), boundaries,
      timers: timers.map(({ delay, cleared, executed }) => ({ delay, cleared, executed })), evidence: 'synthetic projections; original controls/read/cache/auth/coordinator; no real HTTP/DB' }));
    await finish();
  });
  return { refs, requests, observations, inspect, edit, tabRead, query, reply, finish, original: sandbox.original };
}

test('original B query failure hides A summary, shows scope state and explicit query retries B', async t => {
  const h = harness(t); await h.tabRead(); h.reply(); await h.finish(); assert.equal(h.requests.length, 2);
  assert.ok((await h.inspect()).html.includes('11,100'));
  await h.edit('B'); const unqueried = (await h.inspect()).html; const { pending } = await h.query();
  const loading = (await h.inspect()).html; h.reply(true); await pending; await h.finish();
  const failed = (await h.inspect()).html;
  h.observations.push({ after_B_edit_unqueried: unqueried.includes('当前筛选范围尚未查询'), B_loading: loading.includes('正在读取当前筛选范围'), failure_kept_A: failed.includes('11,100') });
  assert.equal(h.requests.length, 4); assert.equal(h.refs.onlineDataList.value[0].system_hotel_id, 81);
  assert.equal(failed.includes('11,100'), false, 'B summary failure must not keep the previously successful A summary');
  assert.ok(failed.includes('摘要读取失败')); assert.ok(unqueried.includes('当前筛选范围尚未查询')); assert.ok(loading.includes('正在读取当前筛选范围'));
  const retry = await h.query(); h.reply(); await retry.pending; await h.finish();
  const ready = (await h.inspect()).html; assert.ok(ready.includes('22,200')); assert.equal(ready.includes('摘要读取失败'), false);
  assert.equal(h.requests.length, 6); assert.equal(h.refs.onlineDataSummary.value.ota_channel_supplement.advertising.bookings, 0);
});

test('original current data-tab reads A B A within its real TTL never return B global as cached A', async t => {
  const h = harness(t); await h.tabRead(); h.reply(); await h.finish();
  const firstCache = h.original.cache(); assert.equal(firstCache.length, 1); assert.ok(firstCache[0].expiresAt > Date.now());
  await h.edit('B'); await h.tabRead(); h.reply(); await h.finish(); assert.ok((await h.inspect()).html.includes('22,200'));
  await h.edit('A'); const before = h.requests.length; await h.tabRead(); h.reply(); await h.finish();
  const ready = (await h.inspect()).html;
  h.observations.push({ A_cache_still_valid: firstCache[0].expiresAt > Date.now(), third_read_transports: h.requests.length - before,
    displayed_A: ready.includes('11,100'), displayed_B: ready.includes('22,200') });
  assert.ok(firstCache[0].expiresAt > Date.now(), 'The original 8000ms cache window is still live; no force option is used');
  assert.equal(h.refs.onlineDataList.value[0].system_hotel_id, 80);
  assert.equal(ready.includes('22,200'), false, 'Cached A expiry must not reuse the current global B summary');
  assert.ok(ready.includes('11,100')); assert.equal(h.requests.length, 6, 'Mismatched global snapshot requires its own A summary read');
});
