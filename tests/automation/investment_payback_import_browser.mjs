import assert from 'node:assert/strict';
import { test } from 'node:test';
import { readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { createServer } from 'node:http';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium, expect } from '@playwright/test';

// Isolated synthetic APIs and the actual component with production CSS; no shared app/account.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const files = { '/vue.js': 'public/vue.global.prod.js', '/import.js': 'public/components/system/investment-payback-import.js', '/tailwind.css': 'public/tailwind.min.css', '/style.css': 'public/style.min.css' };
const assets = Object.fromEntries(Object.entries(files).map(([url, file]) => [url, readFileSync(path.join(root, file))]));
const output = path.join(root, 'output/qa/payback-import-hardening-20261003');
const html = `<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/tailwind.css"><link rel="stylesheet" href="/style.css"><div id="app"></div><script src="/vue.js"></script><script src="/import.js"></script><script>
window.__saved=[]; window.__closed=0;
const request=async(url,options)=>fetch('/synthetic'+url,options).then(r=>r.json());
Vue.createApp({render(){return Vue.h(window.SUXI_SYSTEM_COMPONENTS.InvestmentPaybackImport,{request,today:'2026-10-03',project:{id:81,project_name:'SYNTHETIC 投资'},projects:[{id:81,project_name:'SYNTHETIC 投资'}],onSaved:value=>window.__saved.push(value),onClose:()=>window.__closed++});}}).mount('#app');
</script></html>`;

test('actual import DOM retains a mismatched receipt and safely retries the same reviewed batch at desktop and mobile', { timeout: 90000 }, async () => {
    const evidence = { status: 'running', synthetic: true, real_account: false, real_database: false, external_write_count: 0, total_scenarios: 0, viewports: [], component_sources: Object.fromEntries(Object.entries(files).map(([url,file])=>[file,createHash('sha256').update(assets[url]).digest('hex')])) };
    const server = createServer((req,res) => {
        if (req.url === '/') return res.writeHead(200, { 'Content-Type': 'text/html;charset=utf-8' }).end(html);
        if (assets[req.url]) return res.writeHead(200, { 'Content-Type': req.url.endsWith('.css') ? 'text/css' : 'text/javascript' }).end(assets[req.url]);
        res.writeHead(404).end();
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const base = `http://127.0.0.1:${server.address().port}`;
    let browser;
    try {
        browser = await chromium.launch({ channel: 'chrome', headless: true });
        for (const width of [1365,390]) {
            const context = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 900 } });
            const page = await context.newPage(); page.setDefaultTimeout(10000);
            const errors = [], writes = [];
            page.on('pageerror', error => errors.push(error.message));
            await page.route('**/synthetic/investment-payback/import/**', async route => {
                const payload = route.request().postDataJSON();
                let data;
                if (payload.review_rows) data = { review_token: 'b'.repeat(64), rows: payload.rows.map(row => ({ row_number: row.row_number, selected: row.selected !== false, errors: [], exact_matches: [], batch_duplicates: [], similar_matches: [], similar_match_count: 0, impact_excluded_reason: null })), can_confirm: true, similar_count: 0, invalid_count: 0, exact_count: 0, as_of: '2026-10-03', impact: { actual_invested_delta: '0.00', actual_net_recovered_delta: '123.45', opening_invested_total: '0.00', opening_net_recovered_total: '0.00' } };
                else {
                    writes.push(payload);
                    data = { imported_count: payload.rows.length, mode: payload.mode, project_ids: [writes.length === 1 ? 999 : 81], entry_ids: payload.rows.map((row,index)=>101+index), source_sha256: writes.length === 1 ? 'c'.repeat(64) : payload.source_sha256, source_file_name: payload.source_file_name, source_method: payload.source_method, replayed: writes.length > 1 };
                }
                await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ code: 200, data }) });
            });
            await page.goto(base);
            const dialog = page.getByTestId('payback-import-dialog');
            await expect(dialog).toBeVisible();
            await dialog.getByText('粘贴 Excel 单元格或 CSV 表格', { exact: true }).click();
            await dialog.getByLabel('粘贴表格内容').fill('日期\t类型\t金额\t备注\n2026-09-30\t收回\t123.45\tSYNTHETIC 核对备注');
            await dialog.getByRole('button', { name: '读取粘贴表格', exact: true }).click();
            await dialog.getByRole('button', { name: '生成入账预览', exact: true }).click();
            const reviewed = dialog.getByRole('checkbox', { name: '我已核对项目、日期、单位和金额，确认导入选中记录。' });
            await expect(reviewed).toBeEnabled(); await reviewed.check();
            const confirm = dialog.getByRole('button', { name: '确认导入 1 行', exact: true });
            await expect(confirm).toBeEnabled(); await confirm.click();
            await expect(dialog.getByText('导入范围、来源或记录编号未确认，请用同一份预览重试。', { exact: true })).toBeVisible();
            await expect(dialog.getByLabel('第2行金额')).toHaveValue('123.45');
            await expect(dialog.getByLabel('第2行备注')).toHaveValue('SYNTHETIC 核对备注');
            assert.equal(await page.evaluate(() => window.__saved.length), 0);
            assert.equal(await page.evaluate(() => window.__closed), 0);
            await expect(confirm).toBeEnabled(); await confirm.click();
            await expect.poll(() => page.evaluate(() => window.__saved.length)).toBe(1);
            assert.equal(writes.length, 2);
            assert.equal(writes[0].client_request_id, writes[1].client_request_id);
            assert.equal(writes[0].source_sha256, writes[1].source_sha256);
            assert.deepEqual(writes[0].rows, writes[1].rows);
            assert.deepEqual(errors, []);
            mkdirSync(output, { recursive: true });
            await page.screenshot({ path: path.join(output, `receipt-retry-${width}.png`), fullPage: true });
            evidence.viewports.push({ width, status: 'passed', scenarios: 1, project_id: 81, attempts: 2, same_request_identity: true });
            evidence.total_scenarios++;
            await context.close();
        }
        evidence.status = 'passed';
    } finally {
        mkdirSync(output, { recursive: true });
        writeFileSync(path.join(output, 'evidence.json'), JSON.stringify(evidence, null, 2) + '\n');
        await browser?.close(); await new Promise(resolve => server.close(resolve));
    }
});
