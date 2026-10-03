import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {chromium} from 'playwright';

// Production min bundle against an explicit synthetic tenant/project ledger.
for(const width of [1280,320])test(`compiled payback independently rejects a complete unpersisted write and verifies the same record on retry at ${width}px`,async()=>{
    const browser=await chromium.launch({headless:true});
    const page=await browser.newPage({viewport:{width,height:900}});
    const errors=[],network=[];
    page.on('pageerror',e=>errors.push(e.message));
    const syntheticOrigin='http://127.0.0.1:8099/__synthetic-investment';
    await page.route('**/*',r=>{
        if(r.request().url()===syntheticOrigin)return r.fulfill({contentType:'text/html; charset=utf-8',body:'<html lang="zh-CN"><head><meta charset="utf-8"></head><body><p>合成验收 · 租户10 / 酒店9001 / 项目1 · 投资人现金 · 非真实经营数据</p><div id="app"></div></body></html>'});
        network.push(r.request().url());return r.abort();
    });
    try {
        // Loopback is a secure browser context, so the actual randomUUID API is available.
        // Playwright fulfills this navigation in memory; no server is contacted.
        await page.goto(syntheticOrigin);
        assert.match(await page.locator('body > p').innerText(),/合成验收/);
        await page.addStyleTag({content:fs.readFileSync('public/tailwind.min.css','utf8')});
        await page.addScriptTag({content:fs.readFileSync('public/vue.runtime.global.prod.js','utf8')});
        await page.addScriptTag({content:fs.readFileSync('public/components/system/investment-payback.min.js','utf8')});
        await page.evaluate(()=>{
            window.saveMode='complete_unpersisted';window.lastInput=null;window.savedRecords=[];window.writeNonces=[];window.detailReadCount=0;
            const project={id:1,tenant_id:10,hotel_id:9001,version:1,project_name:'合成验收项目',investor_name:'测试主体',basis:'investor_cash',currency:'CNY',expected_monthly_amount:null};
            const request=async(url,options={})=>{
                const input=options.body?JSON.parse(options.body):null;
                const cutoff=input?.as_of??new URL(url,'https://synthetic.invalid').searchParams.get('as_of');
                if(url.endsWith('/entries')) {
                    window.lastInput=input;window.writeNonces.push(input.client_request_id);
                    const submitted=[{...input,id:501,project_id:1,tenant_id:10,version:1,
                        amount:Number(input.amount).toFixed(2),date:input.date.trim(),source:input.source.trim(),category:input.category.trim(),notes:input.notes.trim(),
                        original_entry_id:input.original_entry_id??null,voided_at:null}];
                    if(window.saveMode==='ok')window.savedRecords=submitted;
                    else return {code:200,data:{project:{...project},entries:submitted,summary:{as_of:cutoff,basis:'investor_cash',currency:'CNY'},audit_history:[]}};
                }
                if(!input && /\/projects\//.test(url))window.detailReadCount++;
                const summary={as_of:cutoff,basis:'investor_cash',currency:'CNY',state:'unrecovered',invested_amount:window.savedRecords.length?'123.45':'0.00',
                    net_recovered_amount:'0.00',unrecovered_amount:window.savedRecords.length?'123.45':'0.00',data_quality:{history_complete:true,issues:[]},forecast:{status:'monthly_amount_missing'}};
                if(/\/projects\//.test(url))return {code:200,data:{project:{...project},entries:JSON.parse(JSON.stringify(window.savedRecords)),summary,audit_history:[]}};
                return {code:200,data:{list:[{...project,summary}],pagination:{total:1}}};
            };
            window.__ledger=Vue.createApp(window.SUXI_SYSTEM_COMPONENTS.InvestmentPaybackBody,{request,hotels:[{id:9001,name:'测试酒店'}]}).mount('#app');
        });
        await page.evaluate(async()=>{await window.__ledger.selectProject(1);window.__ledger.beginEntry('investment');});
        await page.locator('input[name^="entryForm-amount-"]').fill('123.45');
        await page.getByRole('button',{name:'保存记录',exact:true}).click();
        await page.waitForFunction(()=>window.__ledger.formError.includes('精确回读'));
        assert.equal(await page.locator('input[name^="entryForm-amount-"]').inputValue(),'123.45');
        const nonce=await page.evaluate(()=>window.__ledger.entryForm.client_request_id);
        assert.equal(await page.evaluate(()=>window.__ledger.notice.includes('资金记录已保存')),false);
        assert.equal(await page.evaluate(()=>window.detailReadCount),2,'complete POST must still trigger an independent detail GET');
        fs.mkdirSync('output/refinement/20261003/screenshots',{recursive:true});
        await page.screenshot({path:`output/refinement/20261003/screenshots/payback-save-uncertain-${width}.png`,fullPage:true});
        await page.evaluate(()=>{window.saveMode='ok';});
        await page.getByRole('button',{name:'保存记录',exact:true}).click();
        await page.waitForFunction(()=>window.__ledger.entryForm===null);
        assert.match(await page.evaluate(()=>window.__ledger.notice),/资金记录已保存并读回/);
        assert.equal(await page.evaluate(()=>window.__ledger.detail.entries[0].client_request_id),nonce);
        assert.deepEqual(await page.evaluate(()=>window.writeNonces),[nonce,nonce]);
        assert.equal(await page.evaluate(()=>window.__ledger.detail.entries[0].amount),'123.45');
        assert.equal(await page.evaluate(()=>window.detailReadCount),3);
        assert.deepEqual(errors,[]);assert.deepEqual(network,[]);
    } finally {await browser.close();}
});
