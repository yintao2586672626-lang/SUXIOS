// Isolated browser + synthetic SQLite-generated report. Never uses the shared 8080 app.
import assert from 'node:assert/strict';
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';
import { spawn } from 'node:child_process';

const root = process.cwd();
const out = path.join(root, 'output/long-goal');
const database = path.join(root, 'output/long-goals', `ai-workflow-synthetic-${Date.now()}.sqlite`).replaceAll('\\', '/');
const apiEvidence = [];
const bridge = input => new Promise((resolve, reject) => {
  const child = spawn('C:/xampp/php/php.exe', [path.join(root, 'tests/Support/ai_workflow_http_fixture.php'), database], { cwd: root, windowsHide: true });
  let stdout = '', stderr = '';
  child.stdout.on('data', value => stdout += value); child.stderr.on('data', value => stderr += value);
  child.on('error', reject); child.on('exit', code => { try { if (code) throw new Error(stderr || stdout); const result = JSON.parse(stdout); apiEvidence.push({ action: input.action, ...result }); resolve(result); } catch (error) { reject(error); } });
  child.stdin.end(JSON.stringify(input));
});
const report = JSON.parse(fs.readFileSync(path.join(out, 'synthetic-report.json'), 'utf8'));
assert.equal(report.evidence_snapshot.fact_pack.dataset_kind, 'synthetic');
const html = `<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/tailwind.min.css"><body style="background:#f1f5f9;padding:16px"><main style="max-width:1100px;margin:auto">
<h1 style="font:600 18px sans-serif;margin-bottom:12px">L04 · SYNTHETIC 隔离页面验收</h1><div id="app"></div></main>
<script src="/vue.runtime.global.prod.js"></script><script src="/ai-daily-report-delivery.js"></script><script src="/business-closure-views.js"></script>
<script>fetch('/report.json').then(r=>r.json()).then(report=>{
window.__ctx=Vue.reactive({aiDailyReport:report,aiDailyReportForm:{hotel_id:904,report_date:'2026-09-08'},
showToast:(message,type)=>{window.__lastToast={message,type}},aiDailyReportDeliveryRequest:async(url,options)=>{
if(url.startsWith('/ai-daily-reports/broadcast-snapshots/latest?'))return {code:200,data:{status:'not_generated'}};
if(url==='/ai-daily-reports/'+window.__ctx.aiDailyReport.id+'/presentation-artifacts?audience=owner')return {code:200,data:{status:'not_generated'}};
if(url!=='/operation/task-workflow-proposals'){(window.__unexpectedApi??=[]).push(url);throw new Error('Unexpected synthetic API '+url);}
window.__proposalCalls=(window.__proposalCalls||0)+1;window.__lastProposalBody=options.body;
const result=await fetch('/api'+url,{...options,headers:{'Content-Type':'application/json'}}).then(r=>r.json());
window.__actualIntentId=result.data?.intent?.id;return result;}});
Vue.createApp({render(){return Vue.h(window.SUXI_SYSTEM_COMPONENTS.AiDailyPresentationDeliveryBody,{ctx:window.__ctx})}}).mount('#app');});</script></body></html>`;
const routes = {
  '/vue.runtime.global.prod.js': 'public/vue.runtime.global.prod.js', '/tailwind.min.css': 'public/tailwind.min.css',
  '/ai-daily-report-delivery.js': 'public/components/system/ai-daily-report-delivery.js',
  '/business-closure-views.js': 'public/components/system/business-closure-views.js',
};
const server = http.createServer(async (req, res) => {
  if (req.url === '/api/operation/task-workflow-proposals' && req.method === 'POST') {
    try { let body=''; for await (const chunk of req) body += chunk; const result=await bridge({action:'propose',body:JSON.parse(body)}); res.statusCode=result.http_status; res.setHeader('Content-Type','application/json'); res.end(JSON.stringify(result.body)); }
    catch (error) { res.statusCode=500; res.end(JSON.stringify({code:500,message:error.message})); } return;
  }
  if (req.url === '/') { res.setHeader('Content-Type', 'text/html;charset=utf-8'); res.end(html); }
  else if (req.url === '/report.json') { res.setHeader('Content-Type', 'application/json'); res.end(JSON.stringify(report)); }
  else if (routes[req.url]) { res.setHeader('Content-Type', req.url.endsWith('.css') ? 'text/css' : 'application/javascript'); res.end(fs.readFileSync(path.join(root, routes[req.url]))); }
  else { res.statusCode = 404; res.end(); }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
let browser;
try {
  browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 1365, height: 1100 }, acceptDownloads: true,
    permissions: ['clipboard-read', 'clipboard-write'] });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.goto(`http://127.0.0.1:${server.address().port}/`);
  await page.getByTestId('ai-evidence-text').waitFor();
  assert.equal(await page.getByTestId('ai-evidence-text').textContent(), report.final_text);
  await page.getByTestId('ai-evidence-copy').click();
  await page.waitForFunction(() => window.__lastToast?.type === 'success');
  assert.equal(await page.evaluate(() => navigator.clipboard.readText()), report.final_text);
  const pendingDownload = page.waitForEvent('download');
  await page.getByTestId('ai-evidence-export').click();
  const download = await pendingDownload;
  const exportPath = path.join(out, 'synthetic-export.json');
  await download.saveAs(exportPath);
  assert.deepEqual(JSON.parse(fs.readFileSync(exportPath, 'utf8')), report.evidence_snapshot);
  await page.getByTestId('ai-evidence-propose').first().click();
  await page.waitForFunction(() => window.__proposalCalls === 1 && window.__actualIntentId > 0 && window.__lastToast?.message.includes('#'+window.__actualIntentId));
  const savedId = await page.evaluate(() => window.__actualIntentId);
  const exact = await bridge({action:'read',id:savedId,body:{hotel_id:904}});
  assert.equal(exact.body.code,200,JSON.stringify(exact));
  assert.equal(exact.body.data.id,savedId);
  assert.equal(exact.body.data.status,'pending_approval');
  assert.equal(exact.task_count,0);
  const originalProposal = JSON.parse(await page.evaluate(() => window.__lastProposalBody));
  assert.deepEqual(exact.body.data.evidence.workflow_proposal,originalProposal.recommendation);
  const replay = await bridge({action:'propose',body:originalProposal});
  assert.equal(replay.body.data.replayed,true);
  assert.equal(replay.body.data.intent.id,savedId);
  assert.equal(replay.proposal_count,1);
  assert.equal(replay.task_count,0);
  assert.equal(await page.getByTestId('ai-evidence-propose').first().isDisabled(), true);
  await page.screenshot({ path: path.join(out, 'diagnosis-desktop.png'), fullPage: true });
  await page.setViewportSize({ width: 390, height: 844 });
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true);
  await page.screenshot({ path: path.join(out, 'diagnosis-mobile.png'), fullPage: true });
  await page.evaluate(() => { window.__ctx.aiDailyReportForm.hotel_id = 905; });
  assert.equal(await page.getByTestId('ai-evidence-copy').isDisabled(), true);
  assert.equal(await page.getByTestId('ai-evidence-text').count(), 0);
  await page.evaluate(() => { window.__ctx.aiDailyReportForm.hotel_id = 904; });
  await page.getByTestId('ai-evidence-text').waitFor();
  assert.equal(await page.getByTestId('ai-evidence-text').textContent(), report.final_text);
  await page.evaluate(() => { window.__ctx.aiDailyReport = { id: 2, hotel_id: 904, tenant_id: 9004, report_date: '2026-09-08' }; });
  assert.equal(await page.getByTestId('ai-evidence-copy').isDisabled(), true);
  assert.equal(await page.getByTestId('ai-evidence-export').isDisabled(), true);
  assert.deepEqual(errors, []);
  assert.deepEqual(await page.evaluate(() => window.__unexpectedApi || []), []);
  fs.writeFileSync(path.join(out, 'ui-verification.json'), JSON.stringify({ status: 'passed', dataset_kind: 'synthetic',
    rendered_component: 'AiDailyPresentationDeliveryBody', viewports: ['1365x1100', '390x844'],
    checks: ['rendered final_text exact', 'clipboard exact', 'JSON snapshot exact', 'scope switch blocks stale text',
      'scope recovery exact', 'legacy blocked', 'no horizontal overflow', 'no page errors', 'actual controller and synthetic SQLite proposal, exact ID readback, no duplicate or approval'],
    shared_8080_used: false, authenticated_account_tested: false }, null, 2));
  fs.writeFileSync(path.join(root,'output/long-goals/L04-L06-api-evidence.json'),JSON.stringify({dataset_kind:'synthetic',apiEvidence},null,2));
  console.log('PASS: isolated generated Vue component, exact display/copy/export, scope recovery, legacy, desktop/mobile.');
} finally { if (browser) await browser.close(); await new Promise(resolve => server.close(resolve)); if (fs.existsSync(database)) fs.unlinkSync(database); }
