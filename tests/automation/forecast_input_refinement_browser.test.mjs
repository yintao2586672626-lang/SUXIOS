import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {chromium} from 'playwright';
import {compile,parse} from '@vue/compiler-dom';
import {buildFrontendEntry} from '../../scripts/lib/frontend_entry_build.mjs';

// Actual production panel and save logic, mounted with synthetic memory receipts.
// No account, live API, database, or external network is accessed.
const source = fs.readFileSync('public/app-main.js','utf8');
const lifecycle = source.slice(source.indexOf('const resetDemandForecastForm ='),source.indexOf('const resetCompetitorPriceForm ='));
const template = fs.readFileSync('resources/frontend/templates/fragments/27-page-agent-center.html','utf8');
function panel(nodes) {for(const node of nodes) {if(node.type===1 && node.props?.some(p=>p.type===6 && p.name==='data-testid' && p.value?.content==='agent-suggestion-demand-forecast-manual-input')) return node; const child=node.children&&panel(node.children);if(child)return child;}}
const render = compile(panel(parse(template).children).loc.source,{mode:'function',prefixIdentifiers:true}).code;
const compiledLogic = await buildFrontendEntry(`${lifecycle};window.saveForecast=saveDemandForecastInput;window.clearForecast=resetDemandForecastForm;`);

for(const width of [1280,320])test(`actual forecast panel saves explicit zero, verifies precision and preserves failed input at ${width}px`,async()=>{
    const browser=await chromium.launch({headless:true});
    const page=await browser.newPage({viewport:{width,height:900}});
    const errors=[],network=[];
    page.on('pageerror',e=>errors.push(e.message));
    await page.route('**/*',r=>{network.push(r.request().url());return r.abort();});
    try {
        await page.setContent('<html lang="zh-CN"><body><p>合成验收 · 测试酒店9001 · 人工携程预检输入 · 非真实经营数据</p><div id="app"></div></body></html>');
        await page.addStyleTag({content:fs.readFileSync('public/tailwind.min.css','utf8')});
        await page.addStyleTag({content:fs.readFileSync('public/style.min.css','utf8')});
        await page.addStyleTag({content:'html,body,#app,#app * {font-family:system-ui,sans-serif !important}'});
        await page.addScriptTag({content:fs.readFileSync('public/vue.runtime.global.prod.js','utf8')});
        await page.evaluate(()=>{
            const {ref}=Vue;
            const draft={forecast_date:'2026-10-03',room_type_id:100,predicted_occupancy:75.5,predicted_demand:0,confidence_percent:80,remark:'隔离人工输入'};
            const meta={input_scope:'manual_pricing_configuration',source_scope:'ctrip_ota_channel',target_workflow:'ctrip_revenue_ai_pricing_generation',evidence_status:'operator_provided',auto_write_ota:false};
            Object.assign(window,{
                filterReportHotel:ref('9001'),demandForecastForm:ref({...draft}),demandForecastSaving:ref(false),demandForecastSaveReadback:ref(null),
                forecastFilter:ref({start_date:'2026-10-03',end_date:'2026-10-03'}),revenueLoadState:ref({forecasts:{status:'ready'}}),demandForecasts:ref([]),
                roomTypeConfigList:ref([{id:100,name:'测试房型',is_enabled:1}]),manualCtripPricingInputMeta:meta,
                createDemandForecastForm:()=>({...draft,predicted_occupancy:null,predicted_demand:null,confidence_percent:null}),
                captureAgentRevenueRequestContext:()=>({hotelId:filterReportHotel.value}),isAgentRevenueRequestCurrent:x=>x.hotelId===filterReportHotel.value,
                showToast:()=>{},syncRevenuePricingInputDate:()=>{},lastPayload:null,receiptMode:'ok',
                request:async(url,options)=>{
                    const payload=JSON.parse(options.body);window.lastPayload=payload;
                    return {code:200,data:{id:501,readback_verified:true,forecast:{...payload,id:501,predicted_occupancy:window.receiptMode==='bad'?99:payload.predicted_occupancy}}};
                }
            });
            for(const name of ['loadDemandForecasts','loadRevenueAnalysis','loadRevenueDashboard','loadRevenueAiOverview'])window[name]=async()=>{};
        });
        await page.addScriptTag({content:compiledLogic});
        await page.addScriptTag({content:`Vue.createApp({setup(){return {filterReportHotel,forecastFilter,revenueLoadState,demandForecasts,demandForecastSaving,demandForecastSaveReadback,demandForecastForm,roomTypeConfigList,loadDemandForecasts,resetDemandForecastForm:window.clearForecast,saveDemandForecastInput:window.saveForecast};},render:(function(Vue){${render}})(Vue)}).mount('#app');`});
        assert.deepEqual(errors, [], 'Production panel mounts before any interaction');
        const input=page.getByLabel('人工预测入住率');
        await input.fill('0');await page.getByRole('button',{name:'保存预测',exact:true}).click();
        await page.waitForFunction(()=>window.demandForecastSaveReadback.value?.status==='verified');
        assert.equal(await page.evaluate(()=>lastPayload.predicted_occupancy),0);
        assert.equal(await input.inputValue(),'');
        await page.evaluate(()=>{demandForecastForm.value.confidence_percent=80;demandForecastForm.value.predicted_demand=0;});
        await input.fill('2.675');await page.getByRole('button',{name:'保存预测',exact:true}).click();
        await page.waitForFunction(()=>window.lastPayload.predicted_occupancy===2.68&&window.demandForecastSaveReadback.value?.status==='verified');
        await page.evaluate(()=>{receiptMode='bad';demandForecastForm.value.confidence_percent=80;demandForecastForm.value.predicted_demand=0;});
        await input.fill('42');await page.getByRole('button',{name:'保存预测',exact:true}).click();
        await page.waitForFunction(()=>window.demandForecastSaveReadback.value?.status==='failed');
        assert.equal(await input.inputValue(),'42');
        assert.match(await page.getByTestId('agent-demand-forecast-save-readback').innerText(),/输入已保留/);
        fs.mkdirSync('output/refinement/20261003/screenshots',{recursive:true});
        await page.screenshot({path:`output/refinement/20261003/screenshots/forecast-save-readback-${width}.png`,fullPage:true});
        assert.deepEqual(errors,[]);assert.deepEqual(network,[]);
    } finally {await browser.close();}
});
