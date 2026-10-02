import assert from 'node:assert/strict';
import { readFileSync, mkdtempSync, rmSync, mkdirSync, readdirSync, existsSync } from 'node:fs';
import { tmpdir, homedir } from 'node:os';
import { join, resolve } from 'node:path';
import { createServer } from 'node:http';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import test from 'node:test';
import vm from 'node:vm';
import { parseExpressionAt } from 'acorn';
import { chromium } from 'playwright';
import { buildPhpBinaryCandidates, resolvePhpBinary } from '../../scripts/run_node_automation_tests.mjs';

const root = process.cwd();
const source = readFileSync('public/components/system/guest-operations-panel.js', 'utf8');
const qrSource = readFileSync('public/components/system/guest-feedback-qr.js', 'utf8');
const runFile = promisify(execFile);
const php = process.env.SUXIOS_PHP || resolvePhpBinary(buildPhpBinaryCandidates());
assert.ok(php, 'Actual isolated controller acceptance requires a runnable PHP CLI');
const bridge = resolve('tests/fixtures/guest_operations_http_bridge.php');

test('standard QR v6-L decodes through the installed independent ZXing WASM scanner', async t => {
    const pluginRoot = join(homedir(), '.codex/plugins/cache/openai-bundled/browser');
    const installed = existsSync(pluginRoot) ? readdirSync(pluginRoot, { withFileTypes: true }).filter(entry => entry.isDirectory()).map(entry => join(pluginRoot, entry.name, 'scripts')).filter(directory => existsSync(join(directory, 'browser-service.mjs')) && existsSync(join(directory, 'zxing_reader.wasm'))).sort().reverse() : [];
    const directory = process.env.SUXIOS_ZXING_READER_DIR || installed[0];
    let wrapper, binary;
    try { if (!directory) throw new Error('independent decoder unavailable'); wrapper = readFileSync(join(directory, 'browser-service.mjs'), 'utf8'); binary = readFileSync(join(directory, 'zxing_reader.wasm')); }
    catch { t.skip('Independent ZXing decoder is not installed; QR scan acceptance must be run on the host'); return; }
    // Read only the standalone WASM factory. Do not execute the browser service or its authentication code.
    const start = wrapper.indexOf('async function Ik(t={})');
    assert.ok(start >= 0, 'installed ZXing factory entry must be recognized');
    const expression = parseExpressionAt(wrapper, start, { ecmaVersion: 'latest' });
    const factory = new Function(`return (${wrapper.slice(expression.start, expression.end)});`)();
    const scanner = await factory({ wasmBinary: binary });
    const context = { window: {}, TextEncoder }; vm.runInNewContext(qrSource, context);
    const options = { formats: '', tryHarder: true, tryRotate: true, tryInvert: true, tryDownscale: true, tryDenoise: false, binarizer: 2, isPure: true, downscaleFactor: 3, downscaleThreshold: 500, minLineCount: 2, maxNumberOfSymbols: 1, validateOptionalChecksum: false, returnErrors: false, eanAddOnSymbol: 0, textMode: 2, characterSet: 0, tryCode39ExtendedMode: true };
    for (const url of ['http://127.0.0.1:8080/?page=operating-finance&workspace=guests&hotel_id=80&feedback_entry=room101', `https://hotel.example.test/?page=operating-finance&workspace=guests&hotel_id=12345&feedback_entry=${'a'.repeat(32)}`]) {
        const matrix = context.window.SUXI_GUEST_FEEDBACK_QR.encode(url), scale = 5, width = 49 * scale;
        const pixels = new Uint8Array(width * width).fill(255);
        matrix.forEach((row, y) => row.forEach((dark, x) => { if (dark) for (let dy = 0; dy < scale; dy++) for (let dx = 0; dx < scale; dx++) pixels[((y + 4) * scale + dy) * width + (x + 4) * scale + dx] = 0; }));
        const pointer = scanner._malloc(pixels.length); scanner.HEAPU8.set(pixels, pointer);
        const results = scanner.readBarcodesFromPixmap(pointer, width, width, options);
        try { assert.equal(results.size(), 1); assert.equal(results.get(0).text, url); assert.equal(results.get(0).format, 'QRCode'); }
        finally { results.delete(); scanner._free(pointer); }
    }
    assert.throws(() => context.window.SUXI_GUEST_FEEDBACK_QR.encode('x'.repeat(135)), /134/);
});

test('QR link follows current hotel scope after renumber without changing source provenance', () => {
    const context = { window: { location: { origin: 'http://127.0.0.1:8080' } }, TextEncoder, URL, URLSearchParams };
    vm.runInNewContext(qrSource, context); vm.runInNewContext(source, context);
    const record = { hotel_id: 82, source_hotel_id: 80, readback_verified: true, record_key: 'room101', document: { enabled: true, entry_key: 'room101', entry_path: '/?page=guest-operations&hotel_id=80&feedback_entry=room101' } };
    const before = JSON.stringify(record);
    const result = context.window.SUXI_SYSTEM_COMPONENTS.GuestOperationsPanel.methods.qrData.call({ hotelId: '82' }, record);
    assert.equal(result.url, 'http://127.0.0.1:8080/?page=operating-finance&workspace=guests&hotel_id=82&feedback_entry=room101');
    assert.ok(result.image.startsWith('data:image/svg+xml'));
    assert.equal(JSON.stringify(record), before);
    assert.match(context.window.SUXI_SYSTEM_COMPONENTS.GuestOperationsPanel.methods.qrData.call({ hotelId: '80' }, record).error, /酒店范围/);
});

test('mounted Vue component persists and reads real isolated controller/SQLite guest workflows', { timeout: 90000 }, async () => {
    const directory = mkdtempSync(join(tmpdir(), 'suxios-guest-operations-test-'));
    const database = join(directory, 'guest.sqlite');
    const calls = []; let failReadOnce = false;
    const server = createServer(async (request, response) => {
        const url = new URL(request.url, 'http://127.0.0.1');
        if (url.pathname === '/') {
            response.setHeader('Content-Type', 'text/html; charset=utf-8');
            response.end(`<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Synthetic guest operations acceptance</title><style>body{margin:0;padding:16px;background:#f4f6f4;font-family:Arial,sans-serif}button,select,input,textarea{font:inherit}button{cursor:pointer}button:disabled{cursor:default;opacity:.65}th,td{padding:8px;border-bottom:1px solid #d5dfd8}button:focus-visible,a:focus-visible,input:focus-visible,select:focus-visible,textarea:focus-visible{outline:3px solid #b38c32;outline-offset:2px}</style><div id="app"></div><script>${readFileSync('node_modules/vue/dist/vue.runtime.global.prod.js', 'utf8')}</script><script>${qrSource}</script><script>${source}</script><script>window.app=Vue.createApp({data:()=>({settings:{repeat_window_days:7},selectedHotelId:'80'}),render(){return Vue.h(window.SUXI_SYSTEM_COMPONENTS.GuestOperationsPanel,{ref:'panel',hotels:[{id:80,name:'Synthetic Hotel 80'},{id:82,name:'Synthetic Hotel 82'}],selectedHotelId:this.selectedHotelId,initialTab:'repeat',canExecute:true,settings:this.settings,request:async(path,options={})=>{const result=await fetch('/api'+path,options);return result.json();},'onUpdate:selectedHotelId':value=>{this.selectedHotelId=value;}})}}).mount('#app');</script></html>`);
            return;
        }
        if (url.pathname.startsWith('/api/guest-operations/')) {
            try {
                let text = ''; for await (const chunk of request) text += chunk;
                const body = text ? JSON.parse(text) : {}, params = Object.fromEntries(url.searchParams);
                const suffix = url.pathname.slice('/api/guest-operations/'.length);
                let action = ({ overview: 'overview', stays: 'importStays', coverage: 'saveCoverage', feedback: 'saveFeedback', entries: 'saveEntry', history: 'history' })[suffix], args = [];
                const record = /^records\/(\d+)$/.exec(suffix), fact = /^feedback\/([^/]+)\/facts$/.exec(suffix);
                if (record) { action = 'read'; args = [Number(record[1])]; }
                if (fact) { action = 'appendFact'; args = [decodeURIComponent(fact[1])]; }
                calls.push({ action, body, params });
                if (record && failReadOnce) { failReadOnce = false; response.writeHead(500, { 'Content-Type': 'application/json' }); response.end(JSON.stringify({ code: 500, message: 'synthetic forced exact readback failure', data: null })); return; }
                const child = execFile(php, [bridge], { cwd: root, maxBuffer: 1024 * 1024 }, (error, stdout, stderr) => {
                    if (error) { response.writeHead(500, { 'Content-Type': 'application/json' }); response.end(JSON.stringify({ code: 500, message: `synthetic bridge failed: ${stderr || error.message}`, data: null })); return; }
                    try { const output = JSON.parse(stdout); response.writeHead(output.status, { 'Content-Type': 'application/json' }); response.end(JSON.stringify(output.body)); }
                    catch { response.writeHead(500); response.end(JSON.stringify({ code: 500, message: 'synthetic bridge malformed response', data: null })); }
                });
                child.stdin.end(JSON.stringify({ database, action, args, params, body, actor: request.headers['x-synthetic-actor'] == null ? 11 : Number(request.headers['x-synthetic-actor']) }));
            } catch (error) { response.writeHead(500); response.end(JSON.stringify({ code: 500, message: error.message })); }
            return;
        }
        response.writeHead(404); response.end();
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const base = `http://127.0.0.1:${server.address().port}`;
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    const saved = async () => { await page.getByRole('status').waitFor(); assert.match(await page.getByRole('status').innerText(), /精确回读/); };
    try {
        await page.goto(base);
        await page.getByRole('heading', { name: '时期内复购', exact: true }).waitFor();
        const dates = await page.locator('input[type=date]').evaluateAll(inputs => inputs.slice(0, 2).map(input => input.value));
        assert.equal((new Date(`${dates[1]}T00:00:00Z`) - new Date(`${dates[0]}T00:00:00Z`)) / 86400000, 6, 'configured 7-day window must apply');
        await page.getByLabel('时期开始', { exact: true }).fill('2026-10-01');
        await page.getByLabel('时期结束', { exact: true }).fill('2026-10-02');
        await page.getByRole('heading', { name: '时期内复购', exact: true }).waitFor();
        await page.getByLabel('入住来源证据引用', { exact: true }).fill('synthetic UI source');
        const events = [{ event_key: 'guestA-1', guest_hash: 'a'.repeat(64), stay_date: '2026-10-01', status: 'completed' }, { event_key: 'guestA-2', guest_hash: 'a'.repeat(64), stay_date: '2026-10-02', status: 'completed' }, { event_key: 'guestB-1', guest_hash: 'b'.repeat(64), stay_date: '2026-10-02', status: 'completed' }];
        await page.getByLabel('匿名事件JSON数组', { exact: true }).fill(JSON.stringify(events));
        await page.getByRole('button', { name: '导入并精确回读', exact: true }).click(); await saved();
        assert.match(await page.locator('body').innerText(), /复购率：未具备计算条件/);
        await page.getByLabel('来源声明的同期唯一客人数', { exact: true }).fill('2');
        await page.getByLabel('来源覆盖质量', { exact: true }).selectOption('complete');
        await page.getByLabel('覆盖证据引用', { exact: true }).fill('synthetic declared coverage');
        await page.getByRole('button', { name: '保存来源覆盖与分母', exact: true }).click(); await saved();
        assert.match(await page.locator('body').innerText(), /复购率：50.0%/);
        await page.getByLabel('匿名事件JSON数组', { exact: true }).fill(JSON.stringify([{ ...events[1], status: 'void', expected_revision: 1, correction_reason: 'synthetic void correction' }]));
        await page.getByRole('button', { name: '导入并精确回读', exact: true }).click(); await saved();
        assert.match(await page.locator('body').innerText(), /复购率：0.0%/);
        await page.getByRole('button', { name: '反馈闭环', exact: true }).click();
        assert.deepEqual(await page.getByLabel('责任人', { exact: true }).locator('option').evaluateAll(options => options.map(option => option.value)), ['', '11', '15'], 'real parent omits owners; same-scope overview must supply authorized choices');
        assert.equal(await page.evaluate(() => window.app.$refs.panel.owners.length), 0);
        await page.getByLabel('记录键', { exact: true }).fill('case-101');
        await page.getByLabel('发生日期', { exact: true }).fill('2026-10-01');
        await page.getByLabel('责任人', { exact: true }).selectOption('11');
        await page.getByLabel('处理期限（上海时间）', { exact: true }).fill('2026-10-02T18:00');
        await page.getByLabel('反馈来源引用', { exact: true }).fill('synthetic frontdesk register');
        await page.getByLabel('脱敏反馈内容', { exact: true }).fill('synthetic 房间清洁反馈');
        await page.getByRole('button', { name: '保存反馈并回读', exact: true }).click(); await saved();
        await page.getByRole('button', { name: '编辑登记', exact: true }).click();
        await page.getByLabel('脱敏反馈内容', { exact: true }).fill('synthetic 房间清洁复查登记');
        await page.getByLabel('本次编辑原因', { exact: true }).fill('synthetic 更正登记');
        await page.getByRole('button', { name: '保存反馈并回读', exact: true }).click(); await saved();
        await page.getByLabel('反馈记录', { exact: true }).selectOption('case-101');
        await page.getByLabel('事实发生时间（上海时间）', { exact: true }).fill('2026-10-02T10:00');
        await page.getByLabel('处理事实 / 关闭确认事实', { exact: true }).fill('synthetic 已清洁并复查');
        await page.getByLabel('处理证据引用（每行一条）', { exact: true }).fill('synthetic clean evidence');
        await page.getByRole('button', { name: '追加事实并精确回读', exact: true }).click(); await saved();
        assert.match(await page.locator('body').innerText(), /处理中/);
        await page.getByLabel('事实类型', { exact: true }).selectOption('close');
        await page.getByLabel('处理事实 / 关闭确认事实', { exact: true }).fill('synthetic 管理人员复核确认');
        await page.getByRole('button', { name: '追加事实并精确回读', exact: true }).click();
        await page.getByRole('alert').waitFor(); assert.match(await page.getByRole('alert').innerText(), /关闭必须/);
        await page.getByLabel('处理证据引用（每行一条）', { exact: true }).fill('synthetic closure evidence');
        await page.getByLabel('确认者角色', { exact: true }).selectOption('manager');
        await page.getByLabel('我确认已实际取得上述确认，并有对应证据').check();
        await page.getByRole('button', { name: '追加事实并精确回读', exact: true }).click(); await saved();
        assert.match(await page.locator('body').innerText(), /已确认关闭/);
        await page.getByRole('button', { name: '查看完整历史', exact: true }).click();
        await page.getByRole('heading', { name: '精确历史回读', exact: true }).waitFor();
        assert.equal(await page.locator('details').count(), 4); assert.match(await page.locator('body').innerText(), /synthetic 房间清洁反馈/);
        await page.getByRole('button', { name: '鉴权入口', exact: true }).click();
        await page.getByLabel('入口键（最多32位）', { exact: true }).fill('room101');
        await page.getByLabel('房间/区域标签', { exact: true }).fill('101');
        await page.getByLabel('入口名称', { exact: true }).fill('synthetic staff feedback');
        failReadOnce = true;
        await page.getByRole('button', { name: '保存入口配置', exact: true }).click();
        await page.getByRole('alert').waitFor(); assert.match(await page.getByRole('alert').innerText(), /回读失败/);
        await page.getByRole('button', { name: '保存入口配置', exact: true }).click(); await saved();
        const entryWrites = calls.filter(call => call.action === 'saveEntry'); assert.equal(entryWrites.length, 2); assert.equal(entryWrites[0].body.idempotency_key, entryWrites[1].body.idempotency_key, 'uncertain-save retry must preserve idempotency key');
        await page.getByRole('img', { name: '需登录的员工反馈入口二维码', exact: true }).waitFor();
        assert.equal(await page.getByRole('link', { name: '打开已鉴权反馈入口', exact: true }).getAttribute('href'), `${base}/?page=operating-finance&workspace=guests&hotel_id=80&feedback_entry=room101`);
        assert.match(await page.locator('body').innerText(), /不是公开宾客提交链接/);
        await page.getByRole('button', { name: '编辑入口', exact: true }).click();
        await page.getByLabel('启用鉴权员工入口', { exact: true }).uncheck();
        await page.getByRole('button', { name: '保存入口配置', exact: true }).click(); await saved();
        assert.equal(await page.getByRole('img').count(), 0); assert.match(await page.locator('body').innerText(), /入口已停用/);
        await page.evaluate(() => { window.app.settings.repeat_window_days = 3; });
        await page.waitForFunction(() => !!window.app.$refs.panel.overview);
        const configured = await page.locator('input[type=date]').evaluateAll(inputs => inputs.slice(0, 2).map(input => input.value));
        assert.equal((new Date(`${configured[1]}T00:00:00Z`) - new Date(`${configured[0]}T00:00:00Z`)) / 86400000, 2);
        await page.setViewportSize({ width: 320, height: 780 });
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), '320px must not horizontally overflow');
        const output = resolve(`output/guest-operations-acceptance-${process.pid}`); mkdirSync(output, { recursive: true });
        await page.screenshot({ path: join(output, 'guest-entry-320.png'), fullPage: true });
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.screenshot({ path: join(output, 'guest-entry-1280.png'), fullPage: true });
        const unauth = await fetch(`${base}/api/guest-operations/overview?hotel_id=80&date_start=2026-10-01&date_end=2026-10-02&platform=pms`, { headers: { 'x-synthetic-actor': '0' } }); assert.equal(unauth.status, 401);
        await page.getByLabel('酒店', { exact: true }).selectOption('82');
        await page.waitForFunction(() => window.app.$refs.panel.overview?.hotel_id === 82);
        assert.deepEqual(await page.evaluate(() => window.app.$refs.panel.availableOwners.map(owner => owner.id)), [11]);
        assert.equal(await page.getByRole('img').count(), 0); assert.doesNotMatch(await page.locator('body').innerText(), /synthetic staff feedback/);
        assert.deepEqual(errors, []);
        console.log(JSON.stringify({ evidence: 'synthetic HTTP controller + temporary SQLite + mounted Vue', screenshots: output, writes: calls.filter(call => call.body.idempotency_key).length, exactReads: calls.filter(call => call.action === 'read').length }));
    } finally { await browser.close(); await new Promise(resolve => server.close(resolve)); rmSync(directory, { recursive: true, force: true }); }
});
