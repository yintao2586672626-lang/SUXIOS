// local_synthetic_fixture_only: no hotel facts, database writes or operating actions.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const homeSource = readFileSync('public/home-static.js', 'utf8');
const appSource = readFileSync('public/app-main.js', 'utf8');
const context = {window: {Vue: {Fragment: 'fragment', h: (type, props, children) => ({type, props, children})}}};
vm.runInNewContext(homeSource, context);
const component = context.window.SUXI_HOME_STATIC.HomeYesterdayOperatingFacts;
const start = appSource.indexOf('            const openHomeQuickEntry =');
const end = appSource.indexOf('            const openRevenueAiGap =', start);
assert.ok(start > 0 && end > start);
const createHarness = vm.runInNewContext(`(options = {}) => {
 const currentPage = {value: options.page || 'compass'}, onlineDataTab = {value: 'data-health'};
 const document = {documentElement: {dataset: {suxiRenderPhase: options.renderPhase || 'full'}}};
 const user = {value: {id: options.userId || 801}};
 const coreOperationsMaxDate = '2026-10-01';
 let pendingHomeSourceRepairScope = options.pending || null;
 const filterReportHotel = {value: '999'}, dashboardHotelId = {value: '999'};
 const autoFetchHotelId = {value: '999'}, coreOperationsHotelId = {value: '999'};
 const coreOperationsTargetDate = {value: '2026-10-01'};
 const onlineDataFilter = {value: {hotel_id: '999', source: 'meituan', start_date: '2026-10-01', end_date: '2026-10-01'}};
 const permittedHotels = {value: [{id: 901}]};
 const events = [], notices = [];
 const resetCoreOperationsScopedState = () => events.push({kind: 'reset'});
 const showToast = (...args) => notices.push(args);
 const nextTick = callback => callback();
 const scheduleMeituanRankingSummaryRefresh = () => {};
 const openOnlineDataEntryTab = async (tab, args) => {
  events.push({kind: 'readonly-navigation', tab, args, scope: {...onlineDataFilter.value}, date: coreOperationsTargetDate.value});
  if(options.fail) throw new Error('synthetic read failure');
  currentPage.value = 'online-data';
 };
 ${appSource.slice(start,end)}
 return {open: openHomeQuickEntry, restore: restoreHomeSourceRepairNavigation, pending: () => pendingHomeSourceRepairScope, events, notices, currentPage, onlineDataFilter, coreOperationsTargetDate, coreOperationsHotelId};
}`);
const find = (node, predicate) => {
 if (!node || typeof node !== 'object') return null;
 if (predicate(node)) return node;
 for (const child of (Array.isArray(node.children) ? node.children : [node.children])) {
  const match = find(child, predicate); if (match) return match;
 }
 return null;
};
const render = (root = {}, hotel = '901', date = '2026-09-30') => component.render.call({
 model: {hotelName: '合成酒店', selectedBusinessDate: date, yesterday: {date, displayMode: 'error', summary: 'local_synthetic_fixture_only: 采集读取失败', blockingIssues: [{title: '采集失败', detail: '合成失败原因'}]}},
 selectedHotelId: hotel, showControls: true, showHeader: false, compact: true, hotelOptions: [], $root: root, $emit: (...args) => root.emits?.push(args),
});

test('home missing-source repair preserves the displayed hotel and nondefault business date', async () => {
 const harness = createHarness();
 const tree = render({openHomeQuickEntry: harness.open});
 const button = find(tree, node => node.props?.class === 'home-facts-recovery-action' && Array.isArray(node.children) && node.children.some(child => child?.children === '处理数据来源'));
 assert.ok(button);
 await button.props.onClick();
 assert.equal(harness.coreOperationsHotelId.value, '901');
 assert.equal(harness.coreOperationsTargetDate.value, '2026-09-30');
 assert.deepEqual(JSON.parse(JSON.stringify(harness.onlineDataFilter.value)), {hotel_id:'901', source:'all', start_date:'2026-09-30', end_date:'2026-09-30'});
 assert.equal(harness.events.at(-1).scope.start_date, '2026-09-30');
 assert.match(JSON.stringify(tree), /合成失败原因/);
});

test('legacy unscoped quick entries retain their behavior', async () => {
 const harness = createHarness();
 await harness.open({page:'online-data',tab:'data-health'});
 assert.equal(harness.events.at(-1).tab, 'data-health');
 assert.equal(harness.coreOperationsTargetDate.value, '2026-10-01');
 await harness.open({page:'operation'});
 assert.equal(harness.currentPage.value, 'operation');
});

test('invalid or unauthorized scope cannot change filters or start a read', async () => {
 for (const scope of [null, {}, {hotel_id:'902',business_date:'2026-09-30'}, {hotel_id:'901',business_date:'2026-02-30'}, {hotel_id:'901',business_date:'2026-10-02'}, {hotel_id:'901',business_date:'2026-09-30',platform:'pms'}, {hotel_id:'all',business_date:'2026-09-30'}]) {
  const harness=createHarness();
  assert.equal(await harness.open({page:'online-data',tab:'data-health',scope}),false);
  assert.equal(harness.events.length,0);
  assert.equal(harness.coreOperationsHotelId.value,'999');
  assert.equal(harness.coreOperationsTargetDate.value,'2026-10-01');
  assert.equal(harness.notices[0][1],'warning');
 }
});

test('supported OTA channels remain explicit without substituting another channel', async () => {
 for(const platform of ['ctrip','meituan','all']) {
  const harness=createHarness();
  assert.equal(await harness.open({page:'online-data',tab:'data-health',scope:{hotel_id:'901',business_date:'2026-09-30',platform}}),true);
  assert.equal(harness.events.at(-1).scope.source,platform);
  assert.equal(harness.events.at(-1).date,'2026-09-30');
  assert.equal(harness.events.at(-1).args.force,true);
 }
});

test('read failure preserves exact scope and allows retry without creating an action', async () => {
 const options={fail:true};
 const harness=createHarness(options);
 const entry={page:'online-data',tab:'data-health',scope:{hotel_id:'901',business_date:'2026-09-30',platform:'ctrip'}};
 assert.equal(await harness.open(entry),false);
 assert.equal(harness.coreOperationsTargetDate.value,'2026-09-30');
 assert.equal(harness.onlineDataFilter.value.hotel_id,'901');
 assert.equal(harness.notices.at(-1)[1],'error');
 options.fail=false;
 assert.equal(await harness.open(entry),true);
 assert.equal(harness.events.filter(event=>event.kind==='readonly-navigation').length,2);
 assert.ok(harness.events.every(event=>['reset','readonly-navigation'].includes(event.kind)));
});

test('component fallback carries the same scope and refresh navigation reads it again', async () => {
 const emits=[];
 const tree=render({emits});
 const button=find(tree,node=>node.props?.class==='home-facts-recovery-action' && Array.isArray(node.children) && node.children.some(child=>child?.children==='处理数据来源'));
 button.props.onClick();
 assert.deepEqual(JSON.parse(JSON.stringify(emits[0])),['open-data-health',{hotel_id:'901',business_date:'2026-09-30',platform:'all'}]);
 const harness=createHarness();
 const entry={page:'online-data',tab:'data-health',scope:emits[0][1]};
 await harness.open(entry); await harness.open(entry);
 const reads=harness.events.filter(event=>event.kind==='readonly-navigation');
 assert.equal(reads.length,2);
 assert.deepEqual(JSON.parse(JSON.stringify(reads[0].scope)),JSON.parse(JSON.stringify(reads[1].scope)));
 assert.equal(harness.events.filter(event=>event.kind==='reset').length,1);
});

test('cold full-render promotion restores scope once after the current account is verified', async () => {
 const cold=createHarness({renderPhase:'startup'});
 await cold.open({page:'online-data',tab:'data-health',scope:{hotel_id:'901',business_date:'2026-09-30',platform:'all'}});
 const full=createHarness({page:'online-data',pending:cold.pending()});
 assert.equal(await full.restore(),true);
 assert.equal(full.coreOperationsTargetDate.value,'2026-09-30');
 assert.equal(full.onlineDataFilter.value.hotel_id,'901');
 assert.equal(full.pending(),null);
 assert.equal(await full.restore(),false);
 assert.equal(full.events.filter(event=>event.kind==='readonly-navigation').length,1);
 assert.match(appSource,/requestSuxiFullRenderForPage\(currentPage.value\);\s+restoreHomeSourceRepairNavigation\(\);\s+loadData\(\);/);
});

test('promotion cannot restore a previous account scope or replay it on another page', async () => {
 const pending={hotel_id:'901',business_date:'2026-09-30',platform:'all',user_id:802};
 const full=createHarness({page:'online-data',pending});
 assert.equal(await full.restore(),false);
 assert.equal(full.events.length,0);
 assert.equal(full.pending(),null);
 const other=createHarness({page:'system-config',pending});
 assert.equal(await other.restore(),false);
 assert.equal(other.events.length,0);
});
