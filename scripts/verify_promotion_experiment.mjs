import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { spawn } from 'node:child_process';
import { readFile, writeFile, mkdir } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../', import.meta.url));
const output = path.join(root, 'output/long-goal');
await mkdir(output, { recursive: true });
const database = path.join(output, `promotion-ui-${Date.now()}.sqlite`);
const php = process.env.SUXI_TEST_PHP || 'C:/xampp/php/php.exe';
const apiLog = [];
const bridge = input => new Promise((resolve, reject) => {
    const child = spawn(php, [path.join(root, 'tests/Support/promotion_experiment_http_fixture.php'), database], { cwd: root, windowsHide: true });
    let stdout = '', stderr = '';
    child.stdout.on('data', chunk => stdout += chunk); child.stderr.on('data', chunk => stderr += chunk);
    child.on('error', reject);
    child.on('close', code => {
        if (code) return reject(new Error(stderr || stdout));
        try { resolve(JSON.parse(stdout)); } catch { reject(new Error(stdout + stderr)); }
    });
    child.stdin.end(JSON.stringify(input));
});
const fixture = await bridge({ action: 'fixture' });
const assets = ['vue.runtime.global.prod.js', 'components/revenue/commission-calculator-core.js', 'components/revenue/commission-paid-traffic-core.js', 'components/revenue/promotion-experiment-panel.js', 'components/revenue/commission-acquisition-panel.js'];
const server = createServer(async (req, res) => {
    try {
        const url = new URL(req.url, 'http://fixture');
        if (url.pathname.startsWith('/api/promotion-experiments/')) {
            let raw = ''; for await (const chunk of req) raw += chunk;
            const id = /versions\/(\d+)$/.exec(url.pathname)?.[1];
            const action = url.pathname.endsWith('/preview') ? 'preview' : id ? 'read' : req.method === 'POST' ? 'save' : 'history';
            const result = await bridge({ action, id, body: raw ? JSON.parse(raw) : Object.fromEntries(url.searchParams) });
            apiLog.push({ action, status: result.http_status, id: result.body.data?.id, version: result.body.data?.version_no, readback: result.body.data?.readback_status });
            res.writeHead(result.http_status, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(result.body)); return;
        }
        const asset = url.pathname.slice(1);
        if (assets.includes(asset)) { res.writeHead(200, { 'Content-Type': 'text/javascript' }); res.end(await readFile(path.join(root, 'public', asset))); return; }
        res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
        res.end(`<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>SYNTHETIC L07</title><body style="margin:0;padding:12px;background:#f5f6f2"><p>SYNTHETIC / TEST-ONLY · 隔离SQLite，无真实酒店数据</p><div id="fixture"></div>${assets.map(a => `<script src="/${a}"></script>`).join('')}<script>
        window.fixtureControl={delayNext:false,release:null,failNext:false};
        const {h,ref,createApp}=Vue; createApp({setup(){const hotel=ref(701);window.switchSyntheticHotel=()=>{hotel.value=702;};
        const request=async(p,o={})=>{if(fixtureControl.failNext){fixtureControl.failNext=false;throw new Error('SYNTHETIC network failure');}const data=await fetch('/api'+p,{...o,headers:{'Content-Type':'application/json'}}).then(r=>r.json());if(fixtureControl.delayNext){fixtureControl.delayNext=false;await new Promise(resolve=>fixtureControl.release=resolve);}return data;};
        return()=>h(SUXI_SYSTEM_COMPONENTS.CommissionAcquisitionCalculatorPanel,{hotelId:hotel.value,hotels:[{id:701,name:'SYNTHETIC Hotel'}],request});}}).mount('#fixture');</script></body></html>`);
    } catch (e) { res.writeHead(500); res.end(String(e)); }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const browser = await chromium.launch({ headless: true });
const errors = [];
try {
    const page = await browser.newPage({ viewport: { width: 1280, height: 1000 }, acceptDownloads: true });
    page.on('pageerror', e => errors.push(e.message));
    await page.goto(`http://127.0.0.1:${server.address().port}/`);
    const panel = page.getByTestId('promotion-experiment-panel');
    await panel.waitFor();
    const fill = (key, value) => panel.getByTestId('pe-' + key).first().fill(String(value));
    for (const key of ['platform_store_id', 'period_start', 'period_end']) await fill(key, fixture.scope[key]);
    for (const [key, value] of Object.entries(fixture.plan)) {
        if (key === 'design_quality') await panel.getByTestId('pe-design_quality').selectOption('none');
        else if (key !== 'primary_metric') await fill(key, value);
    }
    await fill('control', 'SYNTHETIC 无对照');
    await fill('as_of', fixture.as_of);
    await panel.getByTestId('pe-save').click();
    await page.waitForFunction(() => document.querySelector('[data-testid=pe-notice]')?.textContent.includes('版本 1'));
    assert.match(await panel.getByTestId('pe-incrementality').innerText(), /增量未知/);
    const observation = panel.locator('details').filter({ has: page.locator('summary', { hasText: '观察记录与可售间夜' }) });
    await observation.locator('summary').click();
    for (const [key, value] of Object.entries(fixture.observation)) {
        if (key.startsWith('control_') || ['source_method', 'source_quality', 'pretrend_status'].includes(key)) continue;
        await observation.getByTestId('pe-' + key).fill(String(value));
    }
    const changes = panel.locator('details').filter({ has: page.locator('summary', { hasText: '同期变化检查' }) });
    await changes.locator('summary').click();
    for (let i = 0; i < 4; i++) {
        await changes.locator('select').nth(i).selectOption(i < 2 ? 'changed' : 'unchanged');
        await changes.locator('input').nth(i).fill(i < 2 ? 'SYNTHETIC 投放同期节假日涨价，未排除影响' : 'SYNTHETIC 同期核对');
    }
    await panel.locator('summary').filter({ hasText: '推广明细' }).click();
    // The public UI uses exactly the controller and storage path tested in PHPUnit.
    await panel.getByTestId('pe-import-file').setInputFiles({ name: 'synthetic.json', mimeType: 'application/json', buffer: Buffer.from(JSON.stringify([fixture.records[0], fixture.records[0]])) });
    await panel.getByTestId('pe-remove-1').waitFor();
    assert.equal(await panel.getByTestId('pe-result').count(), 0, 'editing invalidates prior result');
    await panel.getByTestId('pe-preview').click();
    await panel.getByTestId('pe-result').waitFor();
    let result = JSON.parse(await panel.getByTestId('pe-result-json').inputValue());
    assert.equal(result.accounting.totals.spend, 100);
    assert.equal(result.accounting.deduplication.selected, 1);
    assert.equal(result.accounting.platform_attribution.net_revenue, 900);
    assert.equal(result.accounting.book_return.balance_after_known_scope_costs, 500);
    assert.equal(result.incrementality.room_nights, null);
    assert.ok(result.incrementality.reason_codes.includes('concurrent_price_changed'));
    assert.ok(result.incrementality.reason_codes.includes('concurrent_holiday_changed'));
    assert.equal(result.accounting.source_quality, 'unverified');
    await panel.getByTestId('pe-save').click();
    await page.waitForFunction(() => document.querySelector('[data-testid=pe-notice]')?.textContent.includes('版本 2'));
    const downloadPromise = page.waitForEvent('download'); await panel.getByTestId('pe-download').click();
    const download = await downloadPromise; const exported = JSON.parse(await readFile(await download.path(), 'utf8'));
    assert.equal(exported.version_no, 2); assert.deepEqual(exported.result, result);
    await panel.getByTestId('pe-history').click(); await panel.getByTestId('pe-read-1').waitFor();
    await panel.getByTestId('pe-read-1').click();
    await page.waitForFunction(() => document.querySelector('[data-testid=pe-notice]')?.textContent.includes('版本 1'));
    result = JSON.parse(await panel.getByTestId('pe-result-json').inputValue()); assert.equal(result.accounting.totals.spend, null);
    await panel.getByTestId('pe-read-2').click();
    await page.waitForFunction(() => document.querySelector('[data-testid=pe-notice]')?.textContent.includes('版本 2'));
    // Failure, then recovery, preserves version identity without false success.
    await page.evaluate(() => { fixtureControl.failNext = true; });
    await panel.getByTestId('pe-preview').click(); await panel.getByTestId('pe-error').waitFor();
    assert.equal(await panel.getByTestId('pe-result').count(), 0);
    await panel.getByTestId('pe-preview').click(); await panel.getByTestId('pe-result').waitFor();
    await panel.getByTestId('pe-result').scrollIntoViewIfNeeded();
    await page.screenshot({ path: path.join(output, 'promotion-desktop-synthetic.png') });
    await page.setViewportSize({ width: 390, height: 844 });
    await panel.getByTestId('pe-result').scrollIntoViewIfNeeded();
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'mobile viewport must not overflow');
    await page.screenshot({ path: path.join(output, 'promotion-mobile-synthetic.png') });
    // Delayed old-scope response cannot reappear or clear a new request's state.
    await page.evaluate(() => { fixtureControl.delayNext = true; }); await panel.getByTestId('pe-preview').click();
    await page.waitForFunction(() => typeof fixtureControl.release === 'function');
    await page.evaluate(() => switchSyntheticHotel());
    await page.evaluate(() => fixtureControl.release());
    await page.waitForTimeout(40);
    assert.equal(await panel.getByTestId('pe-result').count(), 0);
    assert.equal(await panel.getByTestId('pe-name').inputValue(), '');
    assert.deepEqual(errors, []);
    await writeFile(path.join(output, 'promotion-ui-evidence.json'), JSON.stringify({ status: 'passed', evidence: 'synthetic isolated browser + real PHP controller + dedicated SQLite', assertions: ['plan save/read', 'duplicate import', 'refunds', 'book return', 'holiday + price change without control stays unknown', 'immutable v1/v2', 'download same result', 'error recovery', 'late hotel request rejected', 'desktop/mobile no overflow'], apiLog, database, pageErrors: errors }, null, 2));
    console.log(JSON.stringify({ status: 'passed', apiCalls: apiLog.length, screenshots: ['promotion-desktop-synthetic.png', 'promotion-mobile-synthetic.png'], pageErrors: errors }));
} finally { await browser.close(); await new Promise(resolve => server.close(resolve)); }
