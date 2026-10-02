import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, rmSync, rmdirSync } from 'node:fs';
import { execFile } from 'node:child_process';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { chromium } from 'playwright';
import { fileURLToPath } from 'node:url';
import { buildPhpBinaryCandidates, resolvePhpBinary } from '../../scripts/run_node_automation_tests.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const source = relative => readFileSync(path.join(root, relative), 'utf8');
const php = resolvePhpBinary(buildPhpBinaryCandidates());
assert.ok(php, 'Actual isolated controller acceptance requires a runnable PHP CLI');

test('three-phase settings persist through actual controller, apply execution and survive hotel changes', async () => {
    const directory = mkdtempSync(path.join(tmpdir(), 'business-workspace-browser-'));
    const database = path.join(directory, 'business-workspace-browser-data.sqlite');
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    const errors = [], requests = [];
    page.on('pageerror', error => errors.push(error.message));
    const rpc = (url, options = {}) => new Promise((resolve, reject) => {
        const process = execFile(php, [path.join(root, 'tests/Support/business_workspace_fixture_rpc.php'), database], { cwd: root, maxBuffer: 300000 }, (error, stdout, stderr) => {
            if (error) return reject(new Error(stderr || error.message));
            try { resolve(JSON.parse(stdout)); } catch (error) { reject(error); }
        });
        process.stdin.end(JSON.stringify({ url, options }));
    });
    try {
        let loseFirstSaveReply = true;
        let weeklyMode = 'mismatch';
        const saveKeys = [];
        await page.exposeFunction('__businessRpc', async (url, options) => {
            if (url.startsWith('/operating-opportunities/weekly-plan/latest?')) {
                if (weeklyMode === 'failed') return { code: 503, message: '合成周计划不可访问' };
                const end = new URL(url, 'http://fixture').searchParams.get('week_end');
                return { code: 200, data: { contract_version: 'weekly_operating_plan.v2', hotel_id: 80, week_start: end, week_end: weeklyMode === 'mismatch' ? '2000-01-01' : end, readback_verified: true, snapshot_id: 987 } };
            }
            requests.push(url); const response = await rpc(url, options);
            if (url === '/business-workspace/snapshots') {
                assert.equal(typeof options.body, 'string', 'fetch transport must send serialized JSON');
                saveKeys.push(JSON.parse(options.body).idempotency_key);
                if (loseFirstSaveReply) { loseFirstSaveReply = false; throw new Error('合成网络中断：响应丢失'); }
            }
            return response;
        });
        await page.route('http://127.0.0.1:43129/**', route => route.fulfill({ contentType: 'text/html', body: '<!doctype html><html><body></body></html>' }));
        await page.goto('http://127.0.0.1:43129/');
        await page.setContent('<!doctype html><html lang="zh-CN"><body style="margin:0"><main style="padding:12px"><div id="app"></div></main></body></html>');
        await page.addStyleTag({ content: source('public/tailwind.min.css') });
        await page.addStyleTag({ content: source('public/style.min.css') });
        await page.addScriptTag({ content: source('public/vue.runtime.global.prod.js') });
        await page.addScriptTag({ content: source('public/components/system/operating-finance-control-center.min.js') });
        await page.evaluate(() => {
            window.__workspaceHotel = Vue.ref(80); window.__workspaceEvents = [];
            Vue.createApp({ render() { return Vue.h(window.SUXI_SYSTEM_COMPONENTS.BusinessFeatureWorkspace, {
                request: window.__businessRpc, hotelId: window.__workspaceHotel.value, hotels: [{ id: 80, name: '合成酒店A' }, { id: 81, name: '合成酒店B' }], canExecute: true,
                onNavigate: event => window.__workspaceEvents.push(event), onFinanceTab: event => window.__workspaceEvents.push(event),
            }); } }).mount('#app');
        });
        await page.getByTestId('business-configuration-rows').waitFor();
        assert.equal(await page.locator('[data-module-id]').count(), 31);
        await page.getByLabel('默认平台', { exact: true }).selectOption('meituan');
        await page.getByLabel('预订固定观察时间', { exact: true }).fill('10:30');
        await page.getByLabel('预订未来天数', { exact: true }).fill('14');
        await page.getByLabel('复盘周期', { exact: true }).selectOption('monthly');
        await page.getByLabel('启用报表补录与记录核对', { exact: true }).uncheck();
        await page.getByLabel('宾客舆情与客诉批次', { exact: true }).selectOption('3');
        await page.getByRole('button', { name: '保存设置并应用', exact: true }).click();
        await page.getByRole('alert').filter({ hasText: '响应丢失' }).waitFor();
        await page.getByRole('button', { name: '保存设置并应用', exact: true }).click();
        await page.getByText(/版本 #1 已保存并精确回读/).waitFor();
        assert.equal(saveKeys[0], saveKeys[1], 'uncertain response retries exact same immutable request');
        await page.getByRole('button', { name: '按批次执行', exact: true }).click();
        await page.getByRole('button', { name: '3. 广告数据', exact: true }).click();
        assert.deepEqual(await page.evaluate(() => window.__workspaceEvents.at(-1)), { page: 'meituan-ebooking', tab: 'ads', stored: true });
        await page.getByRole('button', { name: '2. 收益期预订监测', exact: true }).click();
        const booking = await page.evaluate(() => window.__workspaceEvents.at(-1));
        assert.equal(booking.tab, 'booking'); assert.equal(booking.settings.booking_fixed_time, '10:30'); assert.equal(booking.settings.booking_horizon_days, 14);
        await page.getByRole('button', { name: '第3批', exact: true }).click();
        assert.equal(await page.locator('[data-execute-module="9"]').count(), 1);
        assert.equal(await page.locator('[data-execute-module="13"]').count(), 0);
        await page.getByRole('button', { name: '自定义设定', exact: true }).click();
        await page.getByTestId('business-configuration-rows').waitFor();
        assert.equal(await page.getByLabel('预订固定观察时间', { exact: true }).inputValue(), '10:30');
        await page.evaluate(() => { window.__workspaceHotel.value = 81; });
        await page.getByText('当前为建议设置，尚未保存和应用。').waitFor();
        assert.equal(await page.getByLabel('预订固定观察时间', { exact: true }).inputValue(), '09:00');
        await page.evaluate(() => { window.__workspaceHotel.value = 80; });
        await page.getByTestId('business-configuration-rows').waitFor();
        assert.equal(await page.getByLabel('预订固定观察时间', { exact: true }).inputValue(), '10:30');

        await page.getByRole('button', { name: '酒店周报', exact: true }).click();
        assert.match(await page.getByLabel('开始日期', { exact: true }).inputValue(), /^\d{4}-\d{2}-01$/);
        await page.getByRole('button', { name: '读取同酒店周计划', exact: true }).click();
        await page.getByRole('alert').filter({ hasText: '周计划酒店或截止日期范围不一致' }).waitFor();
        weeklyMode = 'matched';
        await page.getByRole('button', { name: '读取同酒店周计划', exact: true }).click();
        await page.getByText(/已读取 .* 的周计划引用/).waitFor();
        const originalEnd = await page.getByLabel('结束日期', { exact: true }).inputValue();
        await page.getByLabel('结束日期', { exact: true }).fill('2020-01-02');
        assert.equal(await page.getByText(/已读取 .* 的周计划引用/).count(), 0, 'editing review dates invalidates the attached source immediately');
        await page.getByLabel('结束日期', { exact: true }).fill(originalEnd);
        await page.getByRole('button', { name: '读取同酒店周计划', exact: true }).click();
        await page.getByText(/已读取 .* 的周计划引用/).waitFor();
        weeklyMode = 'failed';
        await page.getByRole('button', { name: '读取同酒店周计划', exact: true }).click();
        await page.getByRole('alert').filter({ hasText: '合成周计划不可访问' }).waitFor();
        await page.getByLabel('复盘或映射标题', { exact: true }).fill('合成经营周报');
        await page.getByLabel('来源文件或记录引用', { exact: true }).fill('synthetic-week#1');
        await page.getByLabel('评述与未结事项', { exact: true }).fill('广告成本资料缺失，继续核对。');
        await page.getByRole('button', { name: '增加措施', exact: true }).click();
        await page.getByLabel('措施', { exact: true }).fill('核对成本');
        await page.getByLabel('负责人', { exact: true }).fill('合成负责人');
        await page.getByLabel('措施状态', { exact: true }).selectOption('completed');
        await page.getByRole('button', { name: '保存新版本并回读', exact: true }).click();
        await page.getByRole('alert').filter({ hasText: '请检查设置' }).waitFor();
        await page.getByLabel('处理证据引用', { exact: true }).fill('synthetic-receipt#1');
        await page.getByRole('button', { name: '保存新版本并回读', exact: true }).click();
        await page.getByText(/版本 #2 已保存并精确回读/).waitFor();
        await page.getByLabel('复盘或映射标题', { exact: true }).fill('合成经营周报第二版');
        await page.getByRole('button', { name: '保存新版本并回读', exact: true }).click();
        await page.getByText(/版本 #3 已保存并精确回读/).waitFor();
        await page.getByText('已保存版本与精确回读', { exact: true }).click();
        await page.getByRole('button', { name: /^版本 #2 ·/ }).click();
        await page.getByText(/已读取版本 #2/).waitFor();
        assert.equal(await page.getByLabel('复盘或映射标题', { exact: true }).inputValue(), '合成经营周报');
        const downloadPromise = page.waitForEvent('download');
        await page.getByRole('button', { name: '下载已保存复盘', exact: true }).click();
        const download = await downloadPromise;
        const exported = JSON.parse(readFileSync(await download.path(), 'utf8'));
        assert.equal(exported.snapshot_id, 2, 'export must match the saved historical version being displayed');
        assert.equal(exported.inputs.title, '合成经营周报');
        assert.deepEqual(exported.inputs.source_references, [], 'failed or date-invalidated sources are absent from the saved and downloaded review');
        await page.getByRole('button', { name: '来源字段映射', exact: true }).click();
        await page.getByLabel('字段映射JSON', { exact: true }).fill('{"hotel_id":"old-hotel-column"}');
        await page.getByLabel('手工来源行JSON', { exact: true }).fill('[{"hotel_id":80,"guest":"synthetic-old-guest"}]');
        await page.evaluate(() => { window.__workspaceHotel.value = 81; });
        await page.getByLabel('字段映射JSON', { exact: true }).waitFor();
        assert.equal(await page.getByLabel('字段映射JSON', { exact: true }).inputValue(), '{}', 'a new hotel must not inherit another hotel mapping draft');
        assert.equal(await page.getByLabel('手工来源行JSON', { exact: true }).inputValue(), '[]', 'a new hotel must not retain another hotel source rows');
        await page.setViewportSize({ width: 320, height: 734 });
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1));
        assert.deepEqual(errors, []);
        assert.ok(requests.every(url => url.startsWith('/business-workspace/')));
    } finally { await browser.close(); rmSync(database, { force: true }); rmdirSync(directory); }
});

test('generated finance shell routes a saved feedback QR to its hotel and applies settings to the real booking panel', async () => {
    const workspaceDir = mkdtempSync(path.join(tmpdir(), 'business-workspace-browser-'));
    const workspaceDb = path.join(workspaceDir, 'business-workspace-browser-data.sqlite');
    const guestDir = mkdtempSync(path.join(tmpdir(), 'suxios-guest-operations-test-'));
    const guestDb = path.join(guestDir, 'guest.sqlite');
    const invoke = (fixture, args, input) => new Promise((resolve, reject) => {
        const child = execFile(php, [path.join(root, fixture), ...args], { cwd: root, maxBuffer: 300000 }, (error, stdout, stderr) => {
            if (error) return reject(new Error(stderr || error.message));
            try { resolve(JSON.parse(stdout)); } catch (error) { reject(error); }
        }); child.stdin.end(JSON.stringify(input));
    });
    const guest = input => invoke('tests/fixtures/guest_operations_http_bridge.php', [], { database: guestDb, actor: 11, ...input });
    const created = await guest({ action: 'saveEntry', body: { hotel_id: 80, entry_key: 'room101', room_label: '101', label: '合成反馈入口', enabled: true, idempotency_key: 'root-entry-1' } });
    assert.equal(created.body.code, 200);
    const entryPath = created.body.data.records[0].document.entry_path;
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage(); const errors = [], calls = [];
    page.on('pageerror', error => errors.push(error.message));
    try {
        await page.exposeFunction('__rootRpc', async (url, options = {}) => {
            calls.push(url); const parsed = new URL(url, 'http://127.0.0.1');
            if (url.startsWith('/business-workspace/')) return invoke('tests/Support/business_workspace_fixture_rpc.php', [workspaceDb], { url, options });
            if (parsed.pathname === '/guest-operations/overview') return (await guest({ action: 'overview', params: Object.fromEntries(parsed.searchParams) })).body;
            if (parsed.pathname === '/operating-finance/overview') return { code: 200, data: { contract_version: 'operating_finance_control_center.v1', hotel_id: Number(parsed.searchParams.get('hotel_id')), boundaries: { external_write_count: 0 } } };
            if (url.startsWith('/booking-monitoring/')) return { code: 503, message: '合成环境未接真实预订数据' };
            throw new Error('unexpected fixture endpoint: ' + url);
        });
        await page.route('http://127.0.0.1:43129/**', route => route.fulfill({ contentType: 'text/html', body: '<!doctype html><html><body><div id="app"></div></body></html>' }));
        const mount = async url => {
            await page.goto('http://127.0.0.1:43129' + url);
            await page.addScriptTag({ content: source('public/vue.runtime.global.prod.js') });
            await page.addScriptTag({ content: source('public/components/system/booking-monitoring-panel.js') });
            await page.addScriptTag({ content: source('public/components/system/operating-finance-control-center.min.js') });
            await page.evaluate(() => {
                window.__selected = Vue.ref('81');
                Vue.createApp({ render() { return Vue.h(window.SUXI_SYSTEM_COMPONENTS.OperatingFinanceControlCenterBody, {
                    request: window.__rootRpc, hotels: [{ id: 80, name: '合成A' }, { id: 81, name: '合成B' }], selectedHotelId: window.__selected.value, canExecute: true,
                    'onUpdate:selectedHotelId': value => { window.__selected.value = value; },
                }); } }).mount('#app');
            });
        };
        await mount(entryPath);
        await page.waitForFunction(() => [...document.querySelectorAll('input')].some(input => input.value === 'guest_feedback_entry:1 101'));
        assert.equal(await page.evaluate(() => window.__selected.value), '80');
        assert.ok(calls.filter(url => url.includes('/guest-operations/')).every(url => new URL(url, 'http://fixture').searchParams.get('hotel_id') === '80'));
        await page.getByRole('button', { name: '自定义设定', exact: true }).click();
        await page.getByLabel('默认平台', { exact: true }).selectOption('meituan');
        await page.getByLabel('预订固定观察时间', { exact: true }).fill('10:30');
        await page.getByLabel('预订未来天数', { exact: true }).fill('2');
        await page.getByRole('button', { name: '保存设置并应用', exact: true }).click();
        await page.getByText(/版本 #1 已保存并精确回读/).waitFor();
        await page.getByRole('button', { name: '按批次执行', exact: true }).click();
        await page.getByRole('button', { name: '2. 收益期预订监测', exact: true }).click();
        await page.getByText('固定时点预订监测', { exact: true }).waitFor();
        assert.equal(await page.getByLabel('平台/来源', { exact: true }).inputValue(), 'meituan');
        assert.equal(await page.getByLabel('固定观察时点（上海）', { exact: true }).inputValue(), '10:30');
        assert.equal(await page.getByLabel('未来入住日', { exact: true }).inputValue(), '2');
        const before = calls.length;
        await mount('/?page=operating-finance&workspace=guests&hotel_id=999&feedback_entry=room101');
        await page.getByRole('alert').filter({ hasText: '反馈入口酒店不在当前权限范围' }).waitFor();
        assert.equal(calls.length, before, 'unpermitted QR hotel never falls back to another hotel');
        assert.deepEqual(errors, []);
    } finally { await browser.close(); rmSync(workspaceDb, { force: true }); rmdirSync(workspaceDir); rmSync(guestDb, { force: true }); rmdirSync(guestDir); }
});
