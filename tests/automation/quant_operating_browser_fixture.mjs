import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import http from 'node:http';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { compile } from '@vue/compiler-dom';
import { chromium } from 'playwright-core';
import assert from 'node:assert/strict';

const root=process.cwd();
const harnessRoot=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..');
// Inspect the caller's worktree, but keep evidence beside this test runner.
const output=path.resolve(process.env.L09_FIXTURE_OUTPUT || path.join(harnessRoot,'output/long-goal',root===harnessRoot?'':'integrated-browser'));
fs.mkdirSync(output,{recursive:true});
const database=path.join(os.tmpdir(),`l09-synthetic-browser-${process.pid}.sqlite`);
const apiCalls=[];
const errors=[],consoleErrors=[],unexpectedRequests=[],serverErrors=[];
let phase='initialization';
function php(body) {
    return new Promise((resolve,reject)=>{
        const child=spawn('C:/xampp/php/php.exe',[path.join(root,'tests/Support/quant-operating-fixture-api.php'),database],{cwd:root,windowsHide:true});
        let out='',err='';child.stdout.on('data',d=>out+=d);child.stderr.on('data',d=>err+=d);
        child.on('error',reject);child.on('exit',code=>{try{if(code)throw new Error(err||out);resolve(JSON.parse(out));}catch(e){reject(e);}});
        child.stdin.end(JSON.stringify(body));
    });
}
const fixture=(await php({action:'input'})).data;
const main=fs.readFileSync(path.join(root,'public/app-main.js'),'utf8');
const block=main.slice(main.indexOf('            function saveSimulationState('),main.indexOf('            const strategyScoreCards =',main.indexOf('            function saveSimulationState(')));
const componentSource=fs.readFileSync(path.join(root,'public/components/system/app-main-components.js'),'utf8');
const hero=componentSource.slice(componentSource.indexOf('    const SimulationHeroActions ='),componentSource.indexOf('        return Object.freeze({ AiDecisionQualityDetails',componentSource.indexOf('    const SimulationHeroActions =')));
const template=fs.readFileSync(path.join(root,'resources/frontend/templates/fragments/02-page-ai-simulation.html'),'utf8');
if(template.includes('managerCapabilityRequest')) assert.match(main,/managerCapabilityRequest:\s*apiRequest\b/,'The integration must expose the real request alias; the fixture cannot invent an application binding.');
const render=compile(template,{mode:'function'}).code;
const bindings=['currentPage','aiSimulationParams','aiSimulationResult','aiSimulationScenarios','aiSimulationRecords','aiSimulationRecordId','aiSimulationLoading','simulationExecutionLoadingId','operationHotelOptions','simulationHotelSelectionValid','simulationCurrentReadiness','simulationMetricCards','formatCurrency','formatPercent','aiRound','riskBadgeClass',
    'simulationReadinessBadgeClass','simulationReadinessMissingText','simulationInvestmentGroups','simulationInvestmentTotal','simulationInvestmentPerRoom','simulationRevenueSummary','simulationRoomRevenueSegments','simulationCostSummary','simulationCostGroups','simulationOtaCommissionChannels','simulationOtherIncomeFields','simulationCostFields','simulationModelAnalysisVisible','simulationModelSourceLabel','simulationRiskHints','simulationModelAnalysis','baseSimulation',
    'handleSimulation','loadSimulationRecords','loadSimulationDetail','reuseSimulationRecord','createSimulationExecutionIntent','simulationRecordSummary','simulationTaskDisabled','simulationTaskLabel','canArchiveSim','archiveSim','operatingScenarioFields','operatingPaybackText','enableOperatingScenario','syncSimulationCalendar','loadOperatingExample','simulationComparisonRecords','simulationComparisonRows','simulationComparisonError','toggleSimulationComparison','clearSimulationComparison','managerCapabilityRequest'];
const bootstrap=`
const api=window.SUXI_SIMULATION_STATIC;
api.simulationStateStorage={save(){},saveInputOnly(){},load(){return {input:${JSON.stringify(fixture)},result:null,scenarios:null,modelAnalysis:null};}};
const h=Vue.h;
${hero}
const app=Vue.createApp({render:new Function('Vue',${JSON.stringify(render)})(Vue),setup(){
 const {ref,computed,watch}=Vue;
 const token=ref(true), currentPage=ref('ai-simulation'), operationHotelOptions=ref([{id:901,name:'Synthetic L09 Hotel'}]),defaultSimulationInput=computed(()=>api.defaultSimulationInput),hasSimulationStatic=ref(true),aiProject=ref({project_name:'synthetic'});
 const aiSimulationParams=ref({}),aiSimulationResult=ref(null),aiSimulationScenarios=ref([]),aiSimulationRecords=ref([]),aiSimulationRecordId=ref(null),aiSimulationLoading=ref(false),simulationExecutionLoadingId=ref(0),simulationRiskHints=ref([]),simulationModelAnalysis=ref(null);
 let suppressSimulationAutoRefresh=false;
 const simulationHotelSelectionValid=computed(()=>api.simulationHotelSelectionIsPermitted(aiSimulationParams.value,operationHotelOptions.value)),simulationCurrentReadiness=computed(()=>aiSimulationResult.value?.execution_readiness);
 const requireSimulationStatic=k=>api[k],simulationStaticOption=(k,d)=>api[k]??d,ensureSimulationStaticReady=async()=>{};
 const captureAuthSession=()=>1,isAuthSessionCurrent=s=>s===1,isStillOnRequestPage=p=>currentPage.value===p;
 const request=async(url,options={})=>{const r=await fetch('/api'+url,{...options,headers:{'Content-Type':'application/json'}});return r.json();};
 // Production returns managerCapabilityRequest: apiRequest. Keep the same transport
 // boundary here; opening another workbench must not receive an empty success.
 const managerCapabilityRequest=request;
 const showToast=(text,type)=>{const e=document.createElement('p');e.textContent=text;e.dataset.type=type||'success';document.getElementById('notices').replaceChildren(e);};
 const formatCurrency=v=>v===null||v===undefined||v===''?'--':Number(v).toLocaleString('zh-CN',{minimumFractionDigits:2,maximumFractionDigits:2}),formatPercent=v=>v===null||v===undefined?'--':(Number(v)*100).toFixed(1)+'%',aiRound=(v,d)=>v==null?'--':Number(v).toFixed(d),riskBadgeClass=()=>'',getHotelNameById=id=>'Synthetic '+id,openWorkflowFormDialog=()=>{},formatDate=x=>x;
 const {normalizeSimulationInput,normalizeSimulationModelAnalysis,generateRiskHints,buildSimulationInvestmentGroups,simulationInvestmentTotalFromGroups,simulationRevenueSummaryFromInput,buildSimulationRoomRevenueSegments,simulationCostSummaryFromInput,buildSimulationCostGroups,buildSimulationOtaCommissionChannels,isSimulationModelAnalysisVisible,simulationReadinessBadgeClass,simulationReadinessMissingText}=api;
 const simulationInvestmentPerRoomFromInput=api.simulationInvestmentPerRoom,simulationModelSourceLabelForAnalysis=api.simulationModelSourceLabel;
 const simulationOtherIncomeFields=computed(()=>api.simulationOtherIncomeFields),simulationCostFields=computed(()=>api.simulationCostFields);
 ${block}
 const simulationMetricCards=computed(()=>api.buildSimulationMetricCards(baseSimulation.value,formatCurrency));
 const vm={${bindings.join(',')}};window.fixtureVm=vm;return vm;
}});
app.component('simulation-hero-actions',SimulationHeroActions);
app.component('term-help',{props:['term'],render(){return Vue.h('span',this.term);}});
app.component('ai-decision-quality-details',{render(){return null;}});
app.config.errorHandler=(error,instance,info)=>{console.error('L09 fixture Vue error: '+String(error?.stack||error)+' ['+info+']');};
app.mount('#app');
`;
const html=`<!doctype html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/tailwind.css"><link rel="stylesheet" href="/style.css"><style>body{margin:0;padding:12px;background:#f5f7fa}#notices{position:fixed;right:8px;bottom:8px;background:white;z-index:99;max-width:350px;font-size:12px;padding:6px;border:1px solid #ddd}</style></head><body><p style="background:#fff3cd;padding:12px">SYNTHETIC / TEST ONLY · 独立SQLite与浏览器，不代表真实酒店、账号或上线验收</p><div id="app"></div><div id="notices" role="status"></div><script src="/vue.js"></script><script src="/simulation.js"></script><script>${bootstrap}</script></body></html>`;
let failNext=false;
const server=http.createServer(async(req,res)=>{
    try {
        if(req.url.startsWith('/api/')) {
            let body='';for await(const chunk of req)body+=chunk;
            let action;
            if(req.method==='POST' && req.url==='/api/simulation/calculate') action={action:'calculate',payload:JSON.parse(body)};
            else if(req.method==='GET' && req.url==='/api/simulation/records') action={action:'records'};
            else if(req.method==='GET' && /^\/api\/simulation\/records\/\d+$/.test(req.url)) action={action:'detail',id:Number(req.url.split('/').at(-1))};
            else { unexpectedRequests.push({method:req.method,url:req.url}); throw new Error('Unexpected synthetic API request: '+req.method+' '+req.url); }
            const result=failNext&&action.action==='calculate'?(failNext=false,{code:400,message:'synthetic HTTP save failure',data:null}):await php(action);
            apiCalls.push({action:action.action,code:result.code,id:result.data?.id,hotel:result.data?.truth_context?.hotel_id});
            res.setHeader('Content-Type','application/json');res.end(JSON.stringify(result));return;
        }
        const files={'/vue.js':'public/vue.runtime.global.prod.js','/simulation.js':'public/simulation-static.js','/style.css':'public/style.min.css','/tailwind.css':'public/tailwind.min.css'};
        if(req.method==='GET' && files[req.url]) {res.setHeader('Content-Type',req.url.endsWith('.css')?'text/css':'text/javascript');res.end(fs.readFileSync(path.join(root,files[req.url])));return;}
        if(req.method==='GET' && req.url==='/favicon.ico') {res.statusCode=204;res.end();return;}
        if(req.method==='GET' && req.url==='/') {res.setHeader('Content-Type','text/html');res.end(html);return;}
        unexpectedRequests.push({method:req.method,url:req.url});
        throw new Error('Unexpected fixture resource: '+req.method+' '+req.url);
    }catch(e){serverErrors.push(e.message);console.error('[fixture-server] '+e.message);res.statusCode=500;res.setHeader('Content-Type','application/json');res.end(JSON.stringify({code:500,message:e.message}));}
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
let browser,page;
try {
    phase='launch';
    browser=await chromium.launch({channel:'msedge',headless:true});
    page=await browser.newPage({viewport:{width:1440,height:1000}});
    page.setDefaultTimeout(5000);
    page.on('pageerror',e=>{errors.push(e.message);console.error('[pageerror] '+e.stack);});
    page.on('console',message=>{if(message.type()==='error'){consoleErrors.push(message.text());console.error('[browser-console] '+message.text());}});
    phase='mount';
    await page.goto(`http://127.0.0.1:${server.address().port}/`);
    assert.deepEqual(errors,[], 'Page errors during mount');
    assert.deepEqual(consoleErrors,[], 'Vue or browser console errors during mount');
    phase='existing-scenario';
    await page.getByTestId('scenario-name').fill('synthetic existing A');
    await page.getByRole('button',{name:'运行三情景模拟',exact:true}).click();
    await page.getByTestId('operating-scenario-result').waitFor();
    await page.waitForFunction(()=>!window.fixtureVm.aiSimulationLoading.value);
    assert.match(await page.getByTestId('operating-scenario-result').innerText(),/4.22个月/);
    const first=(await php({action:'records'})).data.list[0].id;
    const saved=(await php({action:'detail',id:first})).data;
    assert.equal(saved.input.operatingScenario.case_name,'synthetic existing A');
    await page.getByTestId('scenario-name').fill('synthetic existing B');
    // Change an actual finance field through its rendered input, then save a second revision.
    await page.getByTestId('scenario-opening_cash').fill('20000');
    await page.getByRole('button',{name:'运行三情景模拟',exact:true}).click();
    await page.getByTestId('operating-scenario-result').waitFor();
    await page.waitForFunction(()=>!window.fixtureVm.aiSimulationLoading.value);
    const second=(await php({action:'records'})).data.list[0].id;assert.notEqual(first,second);
    phase='saved-record-comparison';
    await page.getByTestId('history-simulation-compare-'+first).click();
    await page.getByTestId('history-simulation-compare-'+first).filter({hasText:'移出比较'}).waitFor();
    await page.getByTestId('history-simulation-compare-'+second).click();
    await page.getByTestId('scenario-comparison').getByRole('table').waitFor();
    assert.match(await page.getByTestId('scenario-comparison').innerText(),/synthetic existing A/);
    await page.getByTestId('operating-scenario-result').scrollIntoViewIfNeeded();
    await page.screenshot({path:path.join(output,'l09-desktop.png')});
    await page.getByTestId('history-simulation-view-'+first).click();
    await page.waitForFunction(()=>window.fixtureVm.aiSimulationParams.value.operatingScenario.case_name==='synthetic existing A');
    assert.equal(await page.getByTestId('scenario-opening_cash').inputValue(),'40000');
    phase='save-failure-recovery';
    failNext=true;
    await page.getByTestId('scenario-name').fill('synthetic recovery');
    await page.getByRole('button',{name:'运行三情景模拟',exact:true}).click();
    await page.getByText('synthetic HTTP save failure',{exact:true}).waitFor();
    await page.getByRole('button',{name:'运行三情景模拟',exact:true}).click();
    await page.getByTestId('operating-scenario-result').waitFor();
    await page.waitForFunction(()=>!window.fixtureVm.aiSimulationLoading.value);
    await page.getByTestId('scenario-example-proposed').click();
    phase='proposed-scenario';
    await page.getByRole('button',{name:'运行三情景模拟',exact:true}).click();
    await page.getByTestId('operating-scenario-result').waitFor();
    assert.match(await page.getByTestId('operating-scenario-result').innerText(),/拟投资项目/);
    await page.waitForFunction(()=>!window.fixtureVm.aiSimulationLoading.value);
    phase='narrow-viewport';
    await page.setViewportSize({width:390,height:844});
    await page.getByTestId('operating-scenario-result').scrollIntoViewIfNeeded();
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth),true,'Narrow page must not overflow');
    await page.screenshot({path:path.join(output,'l09-mobile.png')});
    assert.deepEqual(errors,[]);
    assert.deepEqual(consoleErrors,[]);
    assert.deepEqual(unexpectedRequests,[]);
    assert.deepEqual(serverErrors,[]);
    const summary={status:'passed',source_root:root,harness_root:harnessRoot,evidence:'synthetic production fragment and handlers + production service/SQLite; no real authentication',screenshots:['l09-desktop.png','l09-mobile.png'],apiCalls,errors,consoleErrors,unexpectedRequests,serverErrors};
    fs.writeFileSync(path.join(output,'browser-verification.json'),JSON.stringify(summary,null,2));console.log(JSON.stringify(summary,null,2));
}catch(error){
    const diagnostics={status:'failed',source_root:root,harness_root:harnessRoot,phase,error:String(error.stack||error),apiCalls,errors,consoleErrors,unexpectedRequests,serverErrors};
    fs.writeFileSync(path.join(output,'browser-verification.json'),JSON.stringify(diagnostics,null,2));
    if(page)await page.screenshot({path:path.join(output,'l09-failure.png')}).catch(()=>{});
    console.error(JSON.stringify(diagnostics,null,2));
    throw error;
}finally{await browser?.close();await new Promise(resolve=>server.close(resolve));if(fs.existsSync(database))fs.unlinkSync(database);}
