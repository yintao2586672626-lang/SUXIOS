import assert from 'node:assert/strict';
import { test } from 'node:test';
import { readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:http';
import { createHash } from 'node:crypto';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium, expect } from '@playwright/test';

// TEST-ONLY synthetic API and isolated headless browser. Never visits the shared application.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const output = path.join(root, 'output/qa/five-hardening-20261003');
const hash = value => createHash('sha256').update(value).digest('hex');
const sources = {
    '/assets/vue.js': 'public/vue.global.prod.js',
    '/assets/economics.js': 'public/components/system/operating-economics-workbench.min.js',
    '/assets/booking.js': 'public/components/system/booking-monitoring-panel.js',
    '/assets/tailwind.css': 'public/tailwind.min.css',
    '/assets/style.css': 'public/style.min.css',
};
const assets = Object.fromEntries(Object.entries(sources).map(([url, file]) => [url, readFileSync(path.join(root, file))]));
const html = `<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>SYNTHETIC five hardening DOM acceptance</title><link rel="stylesheet" href="/assets/tailwind.css"><link rel="stylesheet" href="/assets/style.css">
<style>body{font-family:system-ui,sans-serif;background:#f1f5f9;color:#0f172a;margin:0}main{max-width:1280px;margin:auto;padding:16px}header{position:sticky;top:0;z-index:50;padding:10px;background:#fff7ed;border-bottom:2px solid #b45309;font-size:12px}input,select,textarea{max-width:100%}button:disabled{opacity:.45}section{min-width:0}#app{width:100%}</style>
<header>SYNTHETIC / TEST-ONLY · 本地 mock API · 无真实登录、数据库或 OTA</header><div id="app"></div>
<script src="/assets/vue.js"></script><script src="/assets/booking.js"></script><script src="/assets/economics.js"></script>
<script>
window.__syntheticAdoptions=[];
window.__syntheticCompleted=[];
window.addEventListener('suxi:actual-consumables-reference',event=>window.__syntheticAdoptions.push(event.detail));
const request=async(url,options={})=>{const response=await fetch('/synthetic-api'+url,{method:options.method||'GET',body:options.body}).then(response=>response.json());window.__syntheticCompleted.push(url);return response;};
Vue.createApp({render(){return Vue.h('main',{'data-current-page':'operating-finance'},[
Vue.h(window.SUXI_SYSTEM_COMPONENTS.OperatingEconomicsWorkbench,{request,hotelId:80,periodMonth:'2026-10',platform:'ctrip',canExecute:true}),
Vue.h(window.SUXI_SYSTEM_COMPONENTS.BookingMonitoringPanel,{request,hotels:[{id:80,name:'SYNTHETIC 酒店80'},{id:82,name:'SYNTHETIC 酒店82'}],selectedHotelId:80,canExecute:true,workspaceSettings:{booking_horizon_days:1}})
]);}}).mount('#app');
</script></html>`;

function bookingOverview(url) {
    const ids = url.searchParams.get('hotel_ids').split(',').map(Number);
    const date = url.searchParams.get('business_date');
    const tomorrow = new Date(Date.parse(date + 'T00:00:00+08:00') + 86400000).toLocaleDateString('en-CA', { timeZone: 'Asia/Shanghai' });
    return { contract_version:'booking_fixed_baseline_monitor.v1',tenant_id:7,hotel_ids:ids,platform:url.searchParams.get('platform'),
        business_date:date,fixed_time:url.searchParams.get('fixed_time'),horizon_days:Number(url.searchParams.get('horizon_days')),timezone:'Asia/Shanghai',
        observation_time:date+' 09:00:00',baseline_time:'SYNTHETIC missing baseline',boundaries:{external_write_count:0},status:'partial',
        ready_cell_count:0,cell_count:ids.length,room_types:ids.map(hotel_id=>({id:hotel_id===80?1:2,hotel_id,name:'SYNTHETIC 房型'+hotel_id})),
        cells:ids.map(hotel_id=>({hotel_id,hotel_name:'SYNTHETIC 酒店'+hotel_id,room_type_id:hotel_id===80?1:2,room_type_name:'SYNTHETIC 房型'+hotel_id,
            stay_date:tomorrow,lead_time_days:1,status:'partial',current:{status:'ready',captured_at:date+' 09:00:00',quality_status:'manual_confirmed',on_books_room_nights:0},
            baseline:{status:'missing',captured_at:null},net_pickup_24h_room_nights:null,room_revenue_delta_24h:null,same_lead_time_median_room_nights:null,
            delta_vs_same_lead_time_median:null,history_coverage:0,history:[],data_gaps:['baseline_slot_missing']})) };
}
function snapshot(row, id=77) {
    const captureInput = row.captured_at.replace('T',' ');
    const captured = (captureInput.length===16?captureInput+':00':captureInput).replace(/^(.*:\d{2})$/, '$1.000000');
    return {...row,id,tenant_id:7,source_hotel_id:row.hotel_id,contract_version:'room_type_on_books_snapshot.v1',source_method:'manual_file_import',
        source_ref_hash:hash('on-books-source-v1|'+row.source_ref.trim()),quality_status:row.operator_attested?'manual_confirmed':'unverified',
        captured_at:captured,room_type_name:row.room_type_id===0?'酒店汇总':'SYNTHETIC 房型'+row.hotel_id,
        readback_verified:1,external_write_count:0,idempotency_key:hash('synthetic-key|'+JSON.stringify(row)),content_digest:hash('synthetic-content|'+JSON.stringify(row))};
}
function economicsResult(inputs) {
    const normalized = structuredClone(inputs);
    normalized.occupied_room_nights = Number(inputs.occupied_room_nights);
    normalized.items = normalized.items.map(row=>{
        const result={...row};
        for(const key of ['opening_quantity','purchased_quantity','transfer_in_quantity','closing_quantity','transfer_out_quantity','returned_quantity','written_off_quantity','unit_price','budget_unit_price','budget_usage_per_room_night']) result[key]=row[key]===''?null:Number(row[key]);
        const quantity=result.opening_quantity+result.purchased_quantity+result.transfer_in_quantity-result.closing_quantity-result.transfer_out_quantity-result.returned_quantity-result.written_off_quantity;
        return {...result,consumed_quantity:quantity,consumed_cost:quantity*result.unit_price,loss_cost:result.written_off_quantity*result.unit_price,
            price_variance:null,usage_variance:null,total_variance:null,missing_items:[],status:'calculated'};
    });
    const total=normalized.items.filter(row=>row.enabled).reduce((sum,row)=>sum+row.consumed_cost,0);
    return {status:'calculated',source_quality:inputs.operator_attested?'operator_attested':'unverified',currency:'CNY',inputs:normalized,items:normalized.items,
        actual_consumed_cost:total,known_consumed_cost:total,separate_loss_cost:normalized.items.filter(row=>row.enabled).reduce((sum,row)=>sum+row.loss_cost,0),
        actual_consumables_cost_per_room_night:total/normalized.occupied_room_nights,missing_items:[],boundaries:{source_independently_verified:false,automatic_scenario_adoption:false}};
}

test('real component DOM acceptance uses only synthetic localhost APIs at desktop and 390px', {timeout:120000}, async()=>{
    mkdirSync(output,{recursive:true});
    const evidence={schema_version:'five-hardening-browser.v1',synthetic:true,real_account:false,real_database:false,ota_write_count:0,
        component_sources:Object.fromEntries(Object.entries(sources).map(([url,file])=>[file,hash(assets[url])])),viewports:[],requests:[],page_errors:[],console_errors:[],external_network:[]};
    const server=createServer((req,res)=>{
        const url=new URL(req.url,'http://127.0.0.1');
        if(url.pathname==='/'){res.writeHead(200,{'Content-Type':'text/html;charset=utf-8','Cache-Control':'no-store'}).end(html);return;}
        if(url.pathname==='/favicon.ico'){res.writeHead(204).end();return;}
        if(assets[url.pathname]){res.writeHead(200,{'Content-Type':url.pathname.endsWith('.css')?'text/css':'text/javascript','Cache-Control':'no-store'}).end(assets[url.pathname]);return;}
        res.writeHead(404).end('synthetic server does not expose this route');
    });
    await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
    const base='http://127.0.0.1:'+server.address().port;
    evidence.synthetic_origin=base;
    let browser;
    try{
        browser=await chromium.launch({channel:'chrome',headless:true});
        for(const width of [1365,390]){
            const record={width,height:width===390?844:900,scenarios:[],screenshots:[]};evidence.viewports.push(record);
            const context=await browser.newContext({viewport:{width,height:record.height},acceptDownloads:true});
            const page=await context.newPage();page.setDefaultTimeout(10000);
            const apiState={failPreview:false,failReadback:false,failEconomicsReadback:false,savedEconomics:null,economics:new Map(),economicsKeys:[],booking:new Map(),delayCorrection:false,releaseCorrection:null};
            await page.addInitScript(()=>{
                const NativeDate=Date;
                window.Date=class extends NativeDate { constructor(...args){super(...(args.length?args:['2026-10-03T04:00:00Z']));} static now(){return new NativeDate('2026-10-03T04:00:00Z').getTime();} };
            });
            page.on('pageerror',error=>evidence.page_errors.push({width,message:error.message}));
            page.on('console',message=>{if(message.type()==='error')evidence.console_errors.push({width,message:message.text()});});
            page.on('request',request=>evidence.requests.push({width,method:request.method(),url:request.url(),synthetic:request.url().startsWith(base+'/')}));
            await page.route('**/*',async route=>{
                const request=route.request();const url=new URL(request.url());
                if(url.origin!==base){evidence.external_network.push({width,url:request.url()});await route.abort();return;}
                if(!url.pathname.startsWith('/synthetic-api/')){await route.continue();return;}
                const api=url.pathname.slice('/synthetic-api'.length);let data;let code=200;let message='SYNTHETIC fixture only';
                if(api==='/operating-finance/evidence/overview'){
                    const scope=Object.fromEntries(url.searchParams);scope.hotel_id=Number(scope.hotel_id);
                    data={scope:{tenant_id:7,...scope},history:apiState.savedEconomics?[{snapshot_id:apiState.savedEconomics.snapshot_id,created_at:'SYNTHETIC'}]:[],sources:{},monthly_finance:{status:'blocked'}};
                }else if(['/operating-finance/evidence/preview','/operating-finance/evidence/snapshots'].includes(api)){
                    const body=request.postDataJSON();
                    if(apiState.failPreview&&api.endsWith('/preview')){code=503;message='SYNTHETIC 预览失败，请重试';data=null;}
                    else{
                        const result=economicsResult(body.inputs);const scope={tenant_id:7,hotel_id:body.hotel_id,period_month:body.period_month,platform:body.platform,kind:body.kind};
                        data={scope,inputs:result.inputs,result,status:result.status,source_quality:result.source_quality,readback_verified:api.endsWith('/snapshots')};
                        if(data.readback_verified){
                            const key=JSON.stringify({scope,key:body.idempotency_key});apiState.economicsKeys.push(key);
                            if(!apiState.economics.has(key)){
                                Object.assign(data,{contract_version:'operating_evidence.v1',snapshot_id:101+apiState.economics.size,content_digest:hash(JSON.stringify({scope,result}))});
                                apiState.economics.set(key,data);
                            }
                            data=apiState.economics.get(key);apiState.savedEconomics=data;
                        }
                    }
                }else if(/^\/operating-finance\/evidence\/snapshots\/\d+$/.test(api)){
                    const id=Number(api.split('/').at(-1));const saved=[...apiState.economics.values()].find(row=>row.snapshot_id===id);
                    if(apiState.failEconomicsReadback){code=503;message='SYNTHETIC 经营证据独立回读失败';data=null;}
                    else if(!saved||['hotel_id','period_month','platform','kind'].some(key=>String(saved.scope[key])!==url.searchParams.get(key))){code=404;message='SYNTHETIC 当前范围没有此版本';data=null;}
                    else data=saved;
                }else if(api==='/booking-monitoring/overview'){data=bookingOverview(url);}
                else if(api==='/booking-monitoring/snapshots'){
                    const rows=request.postDataJSON().rows;const snaps=rows.map(row=>snapshot(row));for(const snap of snaps)apiState.booking.set(snap.id,snap);
                    data={contract_version:'booking_fixed_baseline_monitor.v1',tenant_id:7,save_status:'saved_readback_verified',readback_verified:true,external_write_count:0,row_count:rows.length,snapshots:snaps};
                }else if(api==='/booking-monitoring/snapshots/900'){
                    if(apiState.delayCorrection)await new Promise(resolve=>apiState.releaseCorrection=resolve);
                    data=snapshot({hotel_id:Number(url.searchParams.get('hotel_id')),room_type_id:2,platform:'ctrip',fact_scope:'ota_channel',stay_date:'2026-10-04',captured_at:'2026-10-03 09:00:00',
                        on_books_room_nights:9,on_books_room_revenue:90,cumulative_cancel_room_nights:0,gross_booking_room_nights:9,source_ref:'SYNTHETIC old correction',operator_attested:true,supersedes_snapshot_id:null},900);
                }else if(api==='/booking-monitoring/snapshots/77'){
                    if(apiState.failReadback){code=503;message='SYNTHETIC 独立回读失败';data=null;}else data=apiState.booking.get(77);
                }else{code=404;message='Unexpected synthetic API: '+api;data=null;}
                await route.fulfill({status:200,json:{code,message,data}});
            });
            const shot=async name=>{const filename=width+'-'+name+'.png';await page.screenshot({path:path.join(output,filename),fullPage:false});record.screenshots.push(filename);};
            const economics=page.getByTestId('operating-economics-workbench');
            await page.goto(base,{waitUntil:'domcontentloaded'});
            await expect(page.getByText('SYNTHETIC / TEST-ONLY',{exact:false})).toBeVisible();
            await economics.getByRole('button',{name:'实际耗材核算',exact:true}).click();
            await economics.getByRole('button',{name:'添加耗材',exact:true}).click();
            await economics.getByLabel('全酒店本月已售间夜',{exact:true}).fill('100');
            await economics.getByLabel('全酒店已售间夜来源',{exact:true}).fill('SYNTHETIC whole-hotel room ledger');
            await economics.getByPlaceholder('耗材名称').fill('SYNTHETIC 合成耗材');
            for(const [label,value] of Object.entries({'期初数量':'30','采购入库数量':'100','调入':'0','期末数量':'20','调出':'0','退货':'0','单列报损':'10','已确认单位成本':'2','预算单位成本':'1.5','预算每间夜用量':'0.8'}))await economics.getByLabel(label,{exact:true}).fill(value);
            await economics.getByPlaceholder('盘点/领用/计价来源').fill('SYNTHETIC inventory evidence');
            await economics.locator('fieldset input[type=date]').fill('2026-10-03');
            await economics.getByRole('checkbox',{name:'我已核对同酒店同月库存平衡、计价、损耗及全酒店间夜'}).check();
            const save=economics.getByRole('button',{name:'保存新版本并回读',exact:true});
            const preview=economics.getByRole('button',{name:'计算预览',exact:true});
            const exported=economics.getByRole('button',{name:'导出当前结果',exact:true});
            const adopt=economics.getByRole('button',{name:'采用为投资测算参考',exact:true});
            await save.click();await expect(economics.getByText(/经营耗用 200 元/)).toBeVisible();await expect(exported).toBeEnabled();await expect(adopt).toBeEnabled();
            const saveStyle=await save.evaluate(button=>({color:getComputedStyle(button).color,background:getComputedStyle(button).backgroundColor}));
            record.economics_save_button_style=saveStyle;
            assert.ok(!(saveStyle.color==='rgb(255, 255, 255)'&&['rgba(0, 0, 0, 0)','rgb(255, 255, 255)'].includes(saveStyle.background)), 'Economics save action must remain readable: '+JSON.stringify(saveStyle));
            const downloadPromise=page.waitForEvent('download');await exported.click();const download=await downloadPromise;
            const downloadFile=path.join(output,width+'-synthetic-economics-export.json');await download.saveAs(downloadFile);
            const downloaded=JSON.parse(readFileSync(downloadFile,'utf8'));assert.equal(downloaded.source_quality,'operator_attested');assert.equal(downloaded.snapshot_id,101);assert.equal(downloaded.result.actual_consumables_cost_per_room_night,2);
            await adopt.click();const adopted=await page.evaluate(()=>window.__syntheticAdoptions.at(-1));assert.equal(adopted.hotel_id,80);assert.equal(adopted.business_month,'2026-10');assert.equal(adopted.snapshot_id,101);
            await shot('economics-saved');record.scenarios.push({name:'economics DOM save, download and adoption',status:'passed',synthetic:true,snapshot_id:101,unit_cost:2});
            apiState.failPreview=true;await preview.click();await expect(economics.getByRole('alert')).toHaveText(/SYNTHETIC 预览失败/);await expect(exported).toBeDisabled();await expect(adopt).toBeDisabled();
            await expect(economics.getByLabel('全酒店本月已售间夜',{exact:true})).toHaveValue('100');await expect(economics.getByLabel('期末数量',{exact:true})).toHaveValue('20');
            await shot('economics-failed-retained-input');apiState.failPreview=false;await save.click();await expect(exported).toBeEnabled();await expect(adopt).toBeEnabled();
            record.scenarios.push({name:'economics failed preview clears old actions, retains input and retries',status:'passed',synthetic:true});
            await economics.getByPlaceholder('盘点/领用/计价来源').fill('SYNTHETIC independent-read retry');
            const storedBefore=apiState.economics.size;apiState.failEconomicsReadback=true;
            await save.click();await expect(economics.getByRole('alert')).toHaveText(/独立回读.*当前输入保留/);
            await expect(exported).toBeDisabled();await expect(adopt).toBeDisabled();
            await expect(economics.getByLabel('全酒店本月已售间夜',{exact:true})).toHaveValue('100');
            await expect(economics.getByPlaceholder('盘点/领用/计价来源')).toHaveValue('SYNTHETIC independent-read retry');
            assert.equal(apiState.economics.size,storedBefore+1);await shot('economics-independent-read-failed');
            apiState.failEconomicsReadback=false;await save.click();await expect(exported).toBeEnabled();await expect(adopt).toBeEnabled();
            assert.equal(apiState.economics.size,storedBefore+1);assert.equal(apiState.economicsKeys.at(-1),apiState.economicsKeys.at(-2));
            const independentReads=evidence.requests.filter(request=>request.width===width&&/\/operating-finance\/evidence\/snapshots\/\d+\?/.test(request.url));
            assert.ok(independentReads.length>=2);await shot('economics-independent-read-retry-passed');
            record.scenarios.push({name:'economics independent GET failure retains input and unchanged retry creates one version',status:'passed',synthetic:true,snapshot_id:apiState.savedEconomics.snapshot_id,stored_version_delta:1});
            const adoptionsBefore=await page.evaluate(()=>window.__syntheticAdoptions.length);
            // TEST-ONLY historical fixture: current server validation must not save a future actual.
            const legacyFuture=structuredClone(apiState.savedEconomics);legacyFuture.snapshot_id=190;
            legacyFuture.inputs.items[0].source_date='2026-10-04';legacyFuture.content_digest=hash(JSON.stringify({scope:legacyFuture.scope,result:legacyFuture.result}));
            apiState.economics.set('SYNTHETIC legacy future actual',legacyFuture);apiState.savedEconomics=legacyFuture;
            await economics.getByRole('button',{name:'重读版本',exact:true}).click();
            await economics.getByRole('button',{name:/版本 #190/}).click();await expect(exported).toBeEnabled();await expect(adopt).toBeDisabled();
            assert.equal(await page.evaluate(()=>window.__syntheticAdoptions.length),adoptionsBefore);
            record.scenarios.push({name:'legacy same-month future actual remains non-adoptable after exact GET',status:'passed',synthetic:true,source_date:'2026-10-04',shanghai_today:'2026-10-03'});

            const booking=page.getByTestId('booking-fixed-monitor');await booking.getByText('保存或导入真实快照',{exact:true}).click();
            await booking.getByRole('checkbox',{name:'SYNTHETIC 酒店82',exact:true}).check();await expect(booking.getByRole('button',{name:'刷新当前范围',exact:true})).toBeEnabled();
            const form=booking.getByTestId('booking-monitor-form');const metrics=['在手间夜（必填，实际0可填写）','在手房费（未知留空）','累计取消间夜（未知留空）','累计毛预订间夜（未知留空）'];
            const attested=form.getByRole('checkbox',{name:/我已核对酒店、房型、平台/});
            const fillMetrics=async()=>{for(let i=0;i<metrics.length;i++)await form.getByLabel(metrics[i],{exact:true}).fill(String(i+11));await form.getByLabel('授权来源引用或文件指纹',{exact:true}).fill('SYNTHETIC prior hotel');await attested.check();};
            const assertCleared=async()=>{for(const label of metrics)await expect(form.getByLabel(label,{exact:true})).toHaveValue('');await expect(form.getByLabel('授权来源引用或文件指纹',{exact:true})).toHaveValue('');await expect(attested).not.toBeChecked();};
            await fillMetrics();await form.getByRole('combobox',{name:'快照酒店',exact:true}).selectOption('82');await assertCleared();
            await fillMetrics();await form.getByRole('combobox',{name:'房型（0保留汇总）',exact:true}).selectOption('2');await assertCleared();
            record.scenarios.push({name:'booking actual hotel and room-type selects clear previous metrics and attestation',status:'passed',synthetic:true});
            await form.getByLabel('更正原快照ID（选填）',{exact:true}).fill('900');await form.getByLabel(metrics[0],{exact:true}).fill('21');
            apiState.delayCorrection=true;const correctionRequest=page.waitForRequest(request=>request.url().includes('/snapshots/900'));
            await form.getByRole('button',{name:'按ID回读并载入更正',exact:true}).click();await correctionRequest;
            await form.getByLabel(metrics[0],{exact:true}).fill('77');await form.getByLabel('授权来源引用或文件指纹',{exact:true}).fill('SYNTHETIC edited during correction');
            assert.equal(typeof apiState.releaseCorrection,'function');const correctionResponse=page.waitForResponse(response=>response.url().includes('/snapshots/900'));apiState.releaseCorrection();await correctionResponse;
            await page.waitForFunction(()=>window.__syntheticCompleted.some(url=>url.includes('/snapshots/900')));
            await expect(form.getByLabel(metrics[0],{exact:true})).toHaveValue('77');await expect(form.getByLabel('授权来源引用或文件指纹',{exact:true})).toHaveValue('SYNTHETIC edited during correction');
            record.scenarios.push({name:'booking delayed correction GET cannot replace edited DOM draft',status:'passed',synthetic:true});
            await form.getByLabel('更正原快照ID（选填）',{exact:true}).fill('');await form.getByLabel('实际入住日',{exact:true}).fill('2026-10-04');await form.getByLabel('实际捕获时间（上海）',{exact:true}).fill('2026-10-03T09:00');
            for(const [label,value] of [[metrics[0],'7.25'],[metrics[1],'725'],[metrics[2],'0'],[metrics[3],'7.25']])await form.getByLabel(label,{exact:true}).fill(value);
            await form.getByLabel('授权来源引用或文件指纹',{exact:true}).fill('SYNTHETIC retry evidence');await attested.check();apiState.failReadback=true;
            await form.getByRole('button',{name:'保存并精确回读',exact:true}).click();await expect(booking.getByTestId('booking-monitor-error')).toHaveText(/独立快照回读.*当前输入保留/);
            await expect(form.getByLabel(metrics[0],{exact:true})).toHaveValue('7.25');await expect(form.getByLabel('授权来源引用或文件指纹',{exact:true})).toHaveValue('SYNTHETIC retry evidence');await expect(attested).toBeChecked();
            await expect(booking.getByTestId('booking-monitor-save-receipt')).toHaveCount(0);await shot('booking-independent-read-failed');
            apiState.failReadback=false;await form.getByRole('button',{name:'保存并精确回读',exact:true}).click();await expect(booking.getByTestId('booking-monitor-save-receipt')).toContainText('快照#77');
            await expect(booking.getByTestId('booking-monitor-receipt-notice')).toContainText('精确回读1条');await expect(form.getByLabel(metrics[0],{exact:true})).toHaveValue('7.25');await shot('booking-readback-retry-passed');
            record.scenarios.push({name:'booking independent GET failure preserves DOM draft and retry verifies same synthetic snapshot',status:'passed',synthetic:true,snapshot_id:77});
            const overflow=await page.evaluate(()=>({body:document.body.scrollWidth,viewport:window.innerWidth}));assert.ok(overflow.body<=overflow.viewport+1,JSON.stringify(overflow));
            assert.equal(evidence.page_errors.length,0);assert.equal(evidence.console_errors.length,0);record.layout={body_width:overflow.body,viewport_width:overflow.viewport,body_horizontal_overflow:false};
            await context.close();
        }
        assert.equal(evidence.external_network.length,0);assert.ok(evidence.requests.every(request=>request.synthetic));
        evidence.status='passed';evidence.browser='chrome headless';evidence.total_scenarios=evidence.viewports.reduce((count,view)=>count+view.scenarios.length,0);
    }catch(error){evidence.status='failed';evidence.failure={message:error.message,stack:error.stack};throw error;}
    finally{writeFileSync(path.join(output,'evidence.json'),JSON.stringify(evidence,null,2));await browser?.close();await new Promise(resolve=>server.close(resolve));}
});
