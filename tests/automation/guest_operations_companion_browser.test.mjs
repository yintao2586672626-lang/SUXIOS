import assert from 'node:assert/strict';
import { readFileSync, writeFileSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { createServer } from 'node:http';
import { execFile } from 'node:child_process';
import test from 'node:test';
import { chromium } from 'playwright';
import { buildPhpBinaryCandidates, resolvePhpBinary } from '../../scripts/run_node_automation_tests.mjs';

const root = process.cwd(), php = process.env.SUXIOS_PHP || resolvePhpBinary(buildPhpBinaryCandidates());
assert.ok(php, 'guest companion browser acceptance requires PHP');
const bridge = resolve('tests/fixtures/guest_operations_http_bridge.php');
test('room registry QR bulk download anonymous guest submission and JD06 mapping save through actual controllers', { timeout: 120000 }, async () => {
    const directory = mkdtempSync(join(tmpdir(), 'suxios-guest-operations-test-')), database = join(directory, 'guest.sqlite');
    const scripts = ['node_modules/vue/dist/vue.runtime.global.prod.js', 'public/components/system/operating-finance-control-center.min.js'].map(path => `<script>${readFileSync(path, 'utf8')}</script>`).join('');
    let failUploadOnce = false, failEntryReadOnce = false, failOverviewOnce = false; const previewResults = [];
    const run = input => new Promise((resolveResult, reject) => { const child = execFile(php, [bridge], { cwd: root, maxBuffer: 1024 * 1024 }, (error, stdout, stderr) => { if (error) reject(new Error(stderr || error.message)); else try { resolveResult(JSON.parse(stdout)); } catch { reject(new Error('synthetic fixture malformed result')); } }); child.stdin.end(JSON.stringify({ database, ...input })); });
    const server = createServer(async (request, response) => {
        const url = new URL(request.url, 'http://fixture');
        if (url.pathname === '/') { response.setHeader('Content-Type', 'text/html; charset=utf-8'); response.end(`<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0;padding:16px;background:#f4f6f4;font-family:Arial,sans-serif}input,select,textarea,button{font:inherit}*{box-sizing:border-box}</style><div id="app"></div>${scripts}<script>window.app=Vue.createApp({render(){return Vue.h(window.SUXI_SYSTEM_COMPONENTS.GuestOperationsPanel,{ref:'panel',hotels:[{id:80,name:'Synthetic Hotel 80'},{id:82,name:'Synthetic Hotel 82'}],selectedHotelId:'80',initialTab:'entry',canExecute:true,request:async(path,options={})=>(await fetch('/api'+path,options)).json()});}}).mount('#app');</script></html>`); return; }
        if (['/guest-feedback.html', '/guest-feedback.js'].includes(url.pathname)) { response.setHeader('Content-Type', url.pathname.endsWith('.js') ? 'application/javascript' : 'text/html; charset=utf-8'); response.end(readFileSync(join(root, 'public', url.pathname.slice(1)))); return; }
        try {
            const raw = []; for await (const chunk of request) raw.push(chunk); const bytes = Buffer.concat(raw);
            let body = {}, filePath = '';
            if (String(request.headers['content-type'] || '').includes('multipart/form-data')) {
                const form = await new Response(bytes, { headers: { 'Content-Type': request.headers['content-type'] } }).formData();
                body = { options: String(form.get('options')), hotel_id: Number(form.get('hotel_id')) };
                const file = form.get('file'); filePath = join(directory, `synthetic-upload.${file.name.split('.').pop()}`); writeFileSync(filePath, Buffer.from(await file.arrayBuffer()));
            } else body = bytes.length ? JSON.parse(bytes.toString()) : {};
            const params = Object.fromEntries(url.searchParams), suffix = url.pathname.slice('/api/guest-operations/'.length);
            let action = ({ overview: 'overview', rooms: 'saveRooms', 'public-entries': 'savePublicEntries', 'jd06/preview': 'filePreview', 'jd06/import': 'fileImport' })[suffix], args = [];
            const record = /^records\/(\d+)$/.exec(suffix); if (record) { action = 'read'; args = [Number(record[1])]; }
            if (url.pathname === '/api/guest-feedback/entry') action = 'publicEntry';
            if (url.pathname === '/api/guest-feedback/submit') action = 'publicSubmit';
            if (!action) { response.writeHead(404); response.end(); return; }
            if (action === 'overview' && failOverviewOnce) { failOverviewOnce = false; response.writeHead(503, { 'Content-Type': 'application/json' }); response.end(JSON.stringify({ code: 503, message: 'synthetic overview refresh failure', data: null })); return; }
            if ((action === 'filePreview' && failUploadOnce) || (action === 'read' && failEntryReadOnce)) { failUploadOnce = false; failEntryReadOnce = false; response.writeHead(503, { 'Content-Type': 'application/json' }); response.end(JSON.stringify({ code: 503, message: 'synthetic forced read/upload failure', data: null })); return; }
            const result = await run({ action, args, params, body, file_path: filePath, actor: action.startsWith('public') ? 0 : 11 }); if (action === 'filePreview') previewResults.push({ code: result.body.code, message: result.body.message }); response.writeHead(result.status, { 'Content-Type': 'application/json' }); response.end(JSON.stringify(result.body));
        } catch { response.writeHead(500, { 'Content-Type': 'application/json' }); response.end(JSON.stringify({ code: 500, message: 'synthetic controller fixture failed', data: null })); }
    });
    await new Promise(resolveReady => server.listen(0, '127.0.0.1', resolveReady)); const base = `http://127.0.0.1:${server.address().port}`;
    const browser = await chromium.launch({ headless: true }), page = await browser.newPage({ viewport: { width: 1280, height: 1000 } }), errors = []; page.on('pageerror', error => errors.push(error.message));
    try {
        await page.goto(base); await page.getByRole('heading', { name: '真实房间登记', exact: true }).waitFor();
        await page.getByLabel('人工确认的实际房间编号（每行一个）').fill('101\n102'); await page.getByRole('button', { name: '确认登记本店房间', exact: true }).click();
        await page.getByText(/101 · 房间ID/).waitFor();
        await page.getByLabel(/^101（ID/).check(); await page.getByLabel(/^102（ID/).check(); await page.getByLabel('公开反馈责任人').selectOption('11');
        failEntryReadOnce = true; await page.getByRole('button', { name: '保存所选房间入口（保留已有链接）', exact: true }).click();
        await page.getByRole('alert').waitFor(); assert.match(await page.getByRole('alert').innerText(), /回读失败/);
        await page.getByRole('img', { name: '房间101宾客反馈二维码', exact: true }).waitFor();
        const originalGuestPath = await page.getByRole('link', { name: '打开本房间宾客提交页', exact: true }).first().getAttribute('href');
        // Retry retains the same request; one-time capability delivery stays in panel memory.
        failOverviewOnce = true;
        await page.getByRole('button', { name: '保存所选房间入口（保留已有链接）', exact: true }).click();
        await page.waitForFunction(() => !window.app.$refs.panel.loading && window.app.$refs.panel.error.includes('synthetic overview refresh failure'));
        assert.match(await page.getByRole('alert').innerText(), /synthetic overview refresh failure/);
        assert.equal(await page.getByRole('img', { name: '房间101宾客反馈二维码', exact: true }).count(), 1);
        const guestPath = await page.getByRole('link', { name: '打开本房间宾客提交页', exact: true }).first().getAttribute('href'); assert.match(guestPath, /^\/guest-feedback\.html#[a-f0-9]{64}$/);
        assert.equal(guestPath, originalGuestPath);
        const downloadPromise = page.waitForEvent('download'); await page.getByRole('button', { name: '批量下载房间二维码打印文件', exact: true }).click();
        const downloaded = await downloadPromise; assert.equal(downloaded.suggestedFilename(), 'hotel-80-room-feedback-qr.html'); const downloadedPath = await downloaded.path(); const printable = readFileSync(downloadedPath, 'utf8'); assert.equal((printable.match(/<svg/g) || []).length, 2); assert.match(printable, /101/); assert.match(printable, /102/);
        const guest = await browser.newPage({ viewport: { width: 390, height: 844 } }); guest.on('pageerror', error => errors.push(error.message)); await guest.goto(base + guestPath);
        await guest.getByRole('button', { name: '提交房间反馈', exact: true }).waitFor(); assert.match(await guest.getByRole('status').innerText(), /101/);
        await guest.getByLabel('反馈内容（请勿填写姓名、电话、证件号）').fill('synthetic guest: 空调噪声'); await guest.getByRole('button', { name: '提交房间反馈', exact: true }).click();
        await guest.waitForFunction(() => document.getElementById('feedback-form').hidden && document.getElementById('entry-status').textContent.includes('回执'));
        assert.match(await guest.getByRole('status').innerText(), /反馈已收取/); assert.ok(await guest.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
        await page.getByRole('button', { name: '刷新当前范围', exact: true }).click(); await page.getByRole('button', { name: '反馈闭环', exact: true }).click(); await page.getByText('synthetic guest: 空调噪声', { exact: true }).waitFor();
        await page.getByRole('button', { name: '鉴权入口', exact: true }).click(); await page.getByLabel(/^101（ID/).check(); await page.getByLabel(/^102（ID/).check(); await page.getByLabel('公开反馈责任人').selectOption('11'); await page.getByLabel('启用所选房间宾客提交入口').uncheck(); await page.getByRole('button', { name: '保存所选房间入口（保留已有链接）', exact: true }).click();
        await page.getByText(/101 · 房间ID .*入口停用/).waitFor(); await guest.reload(); await guest.getByRole('alert').waitFor(); assert.match(await guest.getByRole('alert').innerText(), /停用/); assert.equal(await guest.getByRole('button', { name: '提交房间反馈' }).count(), 0);
        await page.getByRole('button', { name: '客群复购', exact: true }).click();
        await page.getByLabel('时期开始', { exact: true }).fill('2026-10-01'); await page.getByLabel('时期结束', { exact: true }).fill('2026-10-03'); await page.getByRole('heading', { name: 'JD06入住文件导入', exact: true }).waitFor();
        await page.getByLabel('JD06文件', { exact: true }).setInputFiles({ name: 'synthetic-jd06.csv', mimeType: 'text/csv', buffer: Buffer.from('订单号,手机号,离店日期,状态,酒店ID\nsynthetic-A,13800000000,2026-10-01,已离店,80\nsynthetic-B,13800000000,2026-10-02,已离店,80\n') });
        failUploadOnce = true; await page.getByRole('button', { name: '读取文件表头', exact: true }).click(); await page.getByRole('alert').waitFor(); assert.match(await page.getByRole('alert').innerText(), /forced/);
        await page.getByRole('button', { name: '读取文件表头', exact: true }).click(); await page.getByLabel('入住事件列', { exact: true }).waitFor({ timeout: 8000 }).catch(async () => { assert.fail(`JD06 columns unavailable: ${JSON.stringify(previewResults)}; alerts: ${(await page.getByRole('alert').allTextContents()).join(' ')}; page errors: ${errors.join(' ')}`); });
        for (const [label, value] of [['入住事件列', '0'], ['客人标识列（上传后匿名化）', '1'], ['完成入住日期列', '2'], ['入住状态列', '3'], ['酒店范围列', '4']]) await page.getByLabel(label, { exact: true }).selectOption(value);
        await page.getByLabel('JD06脱敏来源证据引用（最多80字）').fill('synthetic UI JD06 source'); await page.getByRole('button', { name: '验证映射并预览匿名事件', exact: true }).click(); await page.getByText('预览ready：有效 2；同批重复 0；未完成入住跳过 0', { exact: true }).waitFor();
        assert.doesNotMatch(await page.locator('body').innerText(), /13800000000|synthetic-A/); await page.getByRole('button', { name: '确认导入JD06匿名事件', exact: true }).click(); await page.getByText('JD06匿名事件已保存并精确回读；PMS全量覆盖仍未验证', { exact: true }).waitFor();
        await page.waitForFunction(() => window.app.$refs.panel.overview?.stay_events?.length === 2); assert.match(await page.locator('body').innerText(), /复购率：未具备计算条件/);
        await page.setViewportSize({ width: 390, height: 844 }); assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
        await page.getByLabel('酒店', { exact: true }).selectOption('82'); await page.waitForFunction(() => window.app.$refs.panel.overview?.hotel_id === 82); assert.equal(await page.getByLabel('JD06文件', { exact: true }).inputValue(), ''); assert.doesNotMatch(await page.locator('body').innerText(), /jd06-[a-f0-9]{64}/);
        assert.equal(await page.getByRole('link', { name: '打开本房间宾客提交页', exact: true }).count(),0);
        assert.deepEqual(errors, []);
    } finally { await browser.close(); await new Promise(resolveClosed => server.close(resolveClosed)); rmSync(directory, { recursive: true, force: true }); }
});
