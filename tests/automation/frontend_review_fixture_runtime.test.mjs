import assert from 'node:assert/strict';
import { mkdirSync, readFileSync } from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import playwright from 'playwright';

const { chromium } = playwright;

const main = readFileSync('public/app-main.js', 'utf8');
const helper = readFileSync('public/form-operation-support.js', 'utf8');
const loaderStart = main.indexOf('const loadFormOperationSupport = () => {');
const loaderEnd = main.indexOf('const clearFormOperationSupportLoadTimer', loaderStart);
assert.ok(loaderStart >= 0 && loaderEnd > loaderStart);

test('real Chromium renders unknown quality truthfully and business drafts remain isolated after clearing and remounting', {
    timeout: 30_000,
}, async t => {
    const browser = await chromium.launch({ headless: true });
    t.after(() => browser.close());
    const page = await browser.newPage({ viewport: { width: 1100, height: 720 } });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    // Every request is fulfilled by this fixture or cancelled. No live application,
    // external OTA, account, browser profile or existing storage is used.
    await page.route('**/*', async route => {
        const pathname = new URL(route.request().url()).pathname;
        if (pathname === '/form-operation-support.js') {
            return route.fulfill({ contentType: 'text/javascript; charset=utf-8', body: helper });
        }
        if (pathname !== '/') return route.abort();
        return route.fulfill({ contentType: 'text/html; charset=utf-8', body: `<!doctype html>
            <html lang="zh-CN"><head><meta charset="utf-8"><title>隔离前端复核</title></head>
            <body style="font-family:sans-serif;padding:24px;background:#f6f8f7;color:#17352b">
                <div id="app"><h1>隔离前端复核 · 模拟数据</h1>
                    <p>经营表单不恢复其他用户或门店的通用草稿。</p>
                    <form data-form-key="business-form"><label>本门店描述
                        <input name="description" aria-label="本门店描述" style="width:380px;padding:8px"></label>
                    </form>
                    <section id="quality-demo" style="margin-top:24px"></section>
                </div>
            </body></html>` });
    });
    await page.addInitScript(() => {
        const memory = new Map([
            ['suxios.form.draft.v1:business-form', JSON.stringify({ description: '另一用户和酒店的旧草稿' })],
        ]);
        window.__reviewDraftAccess = { reads: 0, writes: 0 };
        Object.defineProperty(window, 'localStorage', { configurable: true, value: {
            getItem: key => { window.__reviewDraftAccess.reads += 1; return memory.get(key) ?? null; },
            setItem: (key, value) => { window.__reviewDraftAccess.writes += 1; memory.set(key, value); },
            removeItem: key => memory.delete(key),
        } });
    });
    await page.goto('http://127.0.0.1:19991/');
    await page.addScriptTag({ content: `
        const formOperationSupportScript = 'form-operation-support.js';
        const formOperationSupportScriptVersion = 'synthetic-review';
        let formOperationSupportLoadPromise = null;
        ${main.slice(loaderStart, loaderEnd)}
        window.__reviewLoadDraftSupport = loadFormOperationSupport;
    ` });
    await page.evaluate(() => window.__reviewLoadDraftSupport());
    const field = page.getByRole('textbox', { name: '本门店描述' });
    assert.equal(await field.inputValue(), '');
    await field.fill('本酒店未保存的编辑');
    await field.fill('');
    await page.evaluate(() => document.querySelector('form').appendChild(document.createElement('div')));
    await page.evaluate(() => window.__reviewLoadDraftSupport());
    assert.equal(await field.inputValue(), '');
    assert.deepEqual(await page.evaluate(() => window.__reviewDraftAccess), { reads: 0, writes: 0 });

    await page.addScriptTag({ content: readFileSync('public/vue.global.prod.js', 'utf8') });
    await page.addScriptTag({ content: readFileSync('public/data-health-static.js', 'utf8') });
    await page.evaluate(() => {
        const api = window.SUXI_DATA_HEALTH_STATIC;
        const quality = Vue.ref(null);
        window.__reviewQuality = quality;
        Vue.createApp({
            setup: () => ({ quality, text: api.onlineDataQualityStatusText, classes: api.onlineDataQualityStatusClass }),
            template: '<h2>OTA质量状态</h2><span data-testid="quality-status" :class="classes(quality)">{{ text(quality) }}</span>',
        }).mount('#quality-demo');
    });
    const quality = page.getByTestId('quality-status');
    assert.equal(await quality.textContent(), '未验证');
    assert.match(await quality.getAttribute('class'), /gray/);
    for (const [status, expected] of [['future_status', '未验证'], ['error', '异常'], ['warning', '需复核'], ['ok', '完整']]) {
        await page.evaluate(value => { window.__reviewQuality.value = { status: value }; }, status);
        await quality.filter({ hasText: expected }).waitFor();
        assert.equal(await quality.textContent(), expected);
    }
    await page.evaluate(() => { window.__reviewQuality.value = null; });
    await quality.filter({ hasText: '未验证' }).waitFor();
    assert.deepEqual(errors, []);
    const screenshotPath = process.env.SUXI_REVIEW_BROWSER_SCREENSHOT;
    if (screenshotPath) {
        mkdirSync(path.dirname(screenshotPath), { recursive: true });
        await page.screenshot({ path: screenshotPath, fullPage: true });
    }
});
