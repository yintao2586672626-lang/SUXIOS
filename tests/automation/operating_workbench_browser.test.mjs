import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { execFile } from 'node:child_process';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { chromium } from 'playwright';
import { fileURLToPath } from 'node:url';
import { buildPhpBinaryCandidates, resolvePhpBinary } from '../../scripts/run_node_automation_tests.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const php = resolvePhpBinary(buildPhpBinaryCandidates());
const source = p => readFileSync(path.join(root,p),'utf8');

test('operating workbench performs budget/report/appeal save-readback through real isolated controllers', async () => {
    const directory = mkdtempSync(path.join(tmpdir(),'operating-workbench-browser-'));
    const database = path.join(directory,'operating-workbench-browser-data.sqlite');
    const rpc = (url,options={},extra={}) => new Promise((resolve,reject)=>{
        const child=execFile(php,[path.join(root,'tests/Support/operating_workbench_fixture_rpc.php'),database],{cwd:root,maxBuffer:1000000},(error,stdout,stderr)=>{
            if(error)return reject(new Error(stderr||error.message));try{resolve(JSON.parse(stdout));}catch(error){reject(error);}
        });child.stdin.end(JSON.stringify({url,options,...extra}));
    });
    const browser=await chromium.launch({headless:true});const page=await browser.newPage({viewport:{width:1280,height:900}});const errors=[];
    page.on('pageerror',e=>errors.push(e.message));
    try {
        await page.exposeFunction('__workbenchRpc',rpc);
        await page.route('http://workbench.test/**',route=>route.fulfill({contentType:'text/html',body:'<!doctype html><meta charset="utf-8"><div id="app"></div>'}));
        await page.goto('http://workbench.test/');await page.addStyleTag({content:source('public/tailwind.min.css')});
        await page.addScriptTag({content:source('public/vue.runtime.global.prod.js')});await page.addScriptTag({content:source('public/components/system/operating-workbench-panel.js')});
        await page.evaluate(()=>{const h=Vue.h;window.vm=Vue.createApp({data:()=>({hotel:80}),render(){return h(window.SUXI_SYSTEM_COMPONENTS.OperatingWorkbenchPanel,{ref:'panel',request:window.__workbenchRpc,hotelId:this.hotel,hotels:[{id:80,name:'合成A'},{id:81,name:'合成B'}],periodMonth:'2026-10',businessDate:'2026-10-03',canExecute:true});}}).mount('#app');});
        await page.waitForFunction(()=>window.vm.$refs.panel.overview&&!window.vm.$refs.panel.busy);
        await page.getByLabel('月总营收预算',{exact:true}).fill('1000');await page.getByLabel('月线上房费目标',{exact:true}).fill('600');
        await page.getByLabel('月线下房费目标',{exact:true}).fill('300');await page.getByLabel('月其他营收目标',{exact:true}).fill('100');
        await page.getByLabel('月保本营收门槛',{exact:true}).fill('800');await page.getByLabel('预算依据（必填）',{exact:true}).fill('synthetic-budget-proof');
        await page.getByLabel('保本门槛依据（填写门槛时必填）',{exact:true}).fill('synthetic-budget-assumption');
        await page.getByTestId('workbench-save').click();await page.waitForFunction(()=>window.vm.$refs.panel.latest.budget>0&&!window.vm.$refs.panel.busy);
        assert.equal(await page.evaluate(()=>window.vm.$refs.panel.overview.items[0].comparison.matrix.revenue_budget.completion_percent),30);
        const budgetId=await page.evaluate(()=>window.vm.$refs.panel.latest.budget);await page.getByRole('button',{name:'重新读取',exact:true}).click();
        await page.waitForFunction(()=>!window.vm.$refs.panel.busy);assert.equal(await page.getByLabel('月总营收预算',{exact:true}).inputValue(),'1000');
        await page.getByTestId('workbench-tab-report').click();await page.waitForFunction(()=>window.vm.$refs.panel.report&&!window.vm.$refs.panel.busy);
        assert.equal(await page.evaluate(()=>window.vm.$refs.panel.report.facts.admitted_revenue),700);
        await page.getByLabel('运营分析与下周重点（人工）',{exact:true}).fill('synthetic-human-judgment');await page.getByTestId('workbench-save').click();
        await page.waitForFunction(()=>window.vm.$refs.panel.latest.report>0&&!window.vm.$refs.panel.busy);
        assert.equal(await page.evaluate(()=>window.vm.$refs.panel.report.human_review_status),'pending');
        const reportId=await page.evaluate(()=>window.vm.$refs.panel.latest.report);
        const saved=await rpc(`/operating-workbench/snapshots/${reportId}?hotel_id=80&kind=report_2026-10-03`);assert.equal(saved.data.inputs.human_judgment,'synthetic-human-judgment');assert.equal(saved.data.readback_verified,true);
        await page.getByTestId('workbench-tab-table').click();await page.waitForFunction(()=>window.vm.$refs.panel.overview&&!window.vm.$refs.panel.busy);
        await page.getByLabel('合成B',{exact:true}).check();await page.getByRole('button',{name:'读取所选门店',exact:true}).click();await page.waitForFunction(()=>window.vm.$refs.panel.overview?.items.length===2&&!window.vm.$refs.panel.busy);
        assert.equal(await page.evaluate(()=>window.vm.$refs.panel.overview.items[1].month.admitted_revenue),null);
        await page.getByTestId('workbench-tab-appeal').click();await page.waitForFunction(()=>!window.vm.$refs.panel.busy);
        await page.getByRole('button',{name:'新建评价申诉',exact:true}).click();await page.getByLabel('评价引用（不填住客身份）',{exact:true}).fill('synthetic-review');
        await page.getByLabel('可核对事实描述（勿填个人身份信息）',{exact:true}).fill('synthetic facts');await page.getByLabel('申请复核理由',{exact:true}).fill('synthetic reason');
        await page.getByRole('button',{name:'添加证据',exact:true}).click();await page.getByLabel('证据说明',{exact:true}).fill('synthetic evidence');await page.getByLabel('证据引用（勿填私密链接）',{exact:true}).fill('synthetic-ref');
        await page.getByTestId('workbench-save').click();await page.waitForFunction(()=>window.vm.$refs.panel.latest.appeal>0&&!window.vm.$refs.panel.busy);
        assert.match(await page.locator('pre').innerText(),/未自动发送/);
        await page.getByLabel('处理阶段',{exact:true}).selectOption('submitted');await page.getByLabel('在平台手工提交后的凭据',{exact:true}).fill('synthetic-platform');
        await page.getByTestId('workbench-save').click();await page.waitForFunction(()=>!window.vm.$refs.panel.busy);assert.match(await page.getByRole('alert').innerText(),/版本或证据/);
        await page.setViewportSize({width:320,height:900});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true);
        assert.equal((await rpc(`/operating-workbench/snapshots/${budgetId}?hotel_id=82&kind=budget_2026-10`)).code,403);
        assert.equal((await rpc('/operating-workbench/overview?hotel_ids=80&period_month=2026-10&business_date=2026-10-03',{}, {anonymous:true})).code,401);
        await page.evaluate(()=>{window.vm.hotel=81;});await page.waitForFunction(()=>!window.vm.$refs.panel.busy&&window.vm.$refs.panel.hotelId===81);
        assert.equal(await page.evaluate(()=>window.vm.$refs.panel.cases.length),0);assert.equal(await page.evaluate(()=>window.vm.$refs.panel.latest.appeal),0);
        assert.deepEqual(errors,[]);
    } finally {await browser.close();rmSync(directory,{recursive:true,force:true});}
});

test('query changes release busy state and discard old hotel/time/report responses', async () => {
    const browser=await chromium.launch({headless:true});const page=await browser.newPage();
    try {
        await page.route('http://workbench.test/**',route=>route.fulfill({contentType:'text/html',body:'<!doctype html><meta charset="utf-8"><div id="app"></div>'}));
        await page.goto('http://workbench.test/');await page.addScriptTag({content:source('public/vue.runtime.global.prod.js')});await page.addScriptTag({content:source('public/components/system/operating-workbench-panel.js')});
        await page.evaluate(()=>{
            const request=async url=>{const query=new URL(url,location.origin).searchParams;const ids=(query.get('hotel_ids')||'80').split(',').map(Number);
                if(window.blockNext){window.blockNext=false;await new Promise(resolve=>{window.releaseOld=resolve;});}
                if(url.includes('/booking'))return {code:200,data:{hotel_ids:ids,business_date:query.get('business_date'),fixed_time:query.get('fixed_time'),horizon_days:Number(query.get('horizon_days')),cells:[],group_rollup:[],contexts:{}}};
                if(url.includes('/report'))return {code:200,data:{scope:{hotel_id:80,kind:`report_${query.get('period_end')}`},period_start:'2026-09-27',period_end:query.get('period_end'),facts:{admitted_days:0,expected_days:7,admitted_revenue:null,series:[]},prior_snapshot:null}};
                return {code:200,data:{hotel_ids:ids,period_month:query.get('period_month'),business_date:query.get('business_date'),items:ids.map(hotel_id=>({hotel_id,hotel_name:`合成${hotel_id}`,budget:null,daily:{},month:{admitted_days:0,expected_days:3,reported_days:0},comparison:{matrix:{revenue_budget:{completion_percent:null}}}}))}};
            };
            window.vm=Vue.createApp({render(){return Vue.h(window.SUXI_SYSTEM_COMPONENTS.OperatingWorkbenchPanel,{ref:'panel',request,hotelId:80,hotels:[{id:80,name:'合成80'},{id:81,name:'合成81'}],periodMonth:'2026-10',businessDate:'2026-10-03',initialTab:'table'});}}).mount('#app');
        });
        await page.waitForFunction(()=>!window.vm.$refs.panel.busy&&window.vm.$refs.panel.overview);
        await page.evaluate(()=>{window.blockNext=true;void window.vm.$refs.panel.load();});await page.waitForFunction(()=>window.releaseOld);
        assert.equal(await page.getByLabel('合成81',{exact:true}).count(),1,await page.locator('body').innerText());
        await page.getByLabel('合成81',{exact:true}).check();assert.equal(await page.evaluate(()=>window.vm.$refs.panel.busy),false);
        await page.evaluate(()=>window.releaseOld());await new Promise(resolve=>setTimeout(resolve,20));assert.equal(await page.evaluate(()=>window.vm.$refs.panel.overview),null);
        await page.getByRole('button',{name:'读取所选门店',exact:true}).click();await page.waitForFunction(()=>window.vm.$refs.panel.overview?.items.length===2&&!window.vm.$refs.panel.busy);
        await page.evaluate(()=>{window.blockNext=true;window.releaseOld=null;window.vm.$refs.panel.tab='booking';});await page.waitForFunction(()=>window.releaseOld);
        await page.evaluate(()=>{window.vm.$refs.panel.fixedTime='10:00';window.vm.$refs.panel.horizon=9;});await page.waitForFunction(()=>!window.vm.$refs.panel.busy);
        await page.evaluate(()=>window.releaseOld());await new Promise(resolve=>setTimeout(resolve,20));assert.equal(await page.evaluate(()=>window.vm.$refs.panel.booking),null);
        await page.getByRole('button',{name:'读取预订扩展',exact:true}).click();await page.waitForFunction(()=>window.vm.$refs.panel.booking&&!window.vm.$refs.panel.busy);
        assert.equal(await page.evaluate(()=>window.vm.$refs.panel.booking.fixed_time),'10:00');assert.equal(await page.evaluate(()=>window.vm.$refs.panel.booking.horizon_days),9);
        await page.getByTestId('workbench-tab-report').click();await page.waitForFunction(()=>window.vm.$refs.panel.report&&!window.vm.$refs.panel.busy);
        await page.getByLabel('周末日期',{exact:true}).fill('2026-10-02');await page.waitForFunction(()=>window.vm.$refs.panel.report===null);
        assert.equal(await page.getByRole('button',{name:'导出当前周报（未发送）',exact:true}).count(),0);
    } finally {await browser.close();}
});
