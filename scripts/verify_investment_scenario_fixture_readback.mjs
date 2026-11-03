import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import {fileURLToPath} from 'node:url';

// Exports from the real component's CSV builder and a saved synthetic fixture; never the business database.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const base = 'http://127.0.0.1:8094';
const get = async endpoint => {
    const response = await fetch(base + '/api/investment-payback' + endpoint, {signal:AbortSignal.timeout(10000)});
    const envelope = await response.json();
    assert.equal(response.status, 200);
    assert.equal(envelope.message, 'isolated synthetic fixture');
    assert.equal(envelope.code, 200);
    return envelope.data;
};
const saved = await get('/projects/1/scenario');
const ledger = await get('/projects/1');
assert.equal(saved.readback, 'exact');
assert.equal(saved.quality_status, 'scenario_assumption');
assert.equal(saved.actual_cash_written, false);
assert.equal(ledger.entries.length, 0);
assert.equal(ledger.summary.invested_amount, null);
assert.equal(ledger.summary.net_recovered_amount, null);
const registry = {};
const Vue = {ref:value=>({value}),computed:getter=>({get value(){return getter();}}),watch:()=>{},onUnmounted:()=>{}};
const source = fs.readFileSync(path.join(root,'public/components/system/investment-scenario.js'),'utf8');
vm.runInNewContext(source, {Vue,window:{SUXI_SYSTEM_COMPONENTS:registry},Intl,Date,Number,Object,JSON,String,Array});
const state = registry.InvestmentScenarioWorkbench.setup({request:async()=>({code:200,data:saved}),project:ledger.project,ledgerBusy:false},{emit:()=>{}});
await state.loadScenario();
const csv = state.buildCsv();
assert.equal(typeof csv, 'string');
assert.ok(csv.includes(saved.content_digest));
assert.ok(csv.includes('scenario_assumption'));
assert.ok(csv.includes('已保存') || csv.includes('exact'));
const directory = path.resolve(root, process.argv[2] || 'output/investment-scenario-20261001');
if (!directory.startsWith(path.join(root, 'output') + path.sep)) throw new Error('Evidence directory must stay under workspace output');
fs.mkdirSync(directory,{recursive:true});
fs.writeFileSync(path.join(directory,'fixture-saved-scenario.json'),JSON.stringify(saved,null,2)+'\n');
fs.writeFileSync(path.join(directory,'隔离验收结果.csv'),csv);
const evidence = {status:'passed',scope:'isolated_synthetic_fixture',project_id:saved.project_id,scenario_version:saved.scenario_version,model_version:saved.model_version,content_digest:saved.content_digest,readback:saved.readback,annual_rows:saved.result.annual_rows.length,ledger_entries:ledger.entries.length,ledger_invested_amount:ledger.summary.invested_amount,ledger_net_recovered_amount:ledger.summary.net_recovered_amount,csv_builder:'actual InvestmentScenarioWorkbench.buildCsv',csv_sha256:crypto.createHash('sha256').update(csv).digest('hex'),browser_file_download_verified:false,authenticated_account_verified:false,business_database_written:false};
const copiedPath = path.join(directory, '浏览器复制结果.csv');
if (fs.existsSync(copiedPath)) {
    const copied = fs.readFileSync(copiedPath, 'utf8');
    evidence.browser_manual_copy_content_verified = copied.replace(/\r\n/g, '\n') === csv.replace(/\r\n/g, '\n');
    evidence.browser_manual_copy_sha256 = crypto.createHash('sha256').update(copied).digest('hex');
    evidence.copy_comparison = 'same content after normalizing textarea CRLF to LF';
    assert.equal(evidence.browser_manual_copy_content_verified, true);
}
fs.writeFileSync(path.join(directory,'fixture-readback.json'),JSON.stringify(evidence,null,2)+'\n');
console.log(JSON.stringify(evidence));
