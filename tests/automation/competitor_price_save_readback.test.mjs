import nodeTest from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import * as Vue from 'vue';
import {compile,parse} from '@vue/compiler-dom';
import {renderToString} from '@vue/server-renderer';
const test=(name,body)=>nodeTest(name,{timeout:2000},body);
const source=readFileSync('public/app-main.js','utf8');
const lifecycle=source.slice(source.indexOf('const resetCompetitorPriceForm ='),source.indexOf('const loadPriceSuggestionWorkbench ='));
const meta={input_scope:'manual_pricing_configuration',source_scope:'ctrip_ota_channel',target_workflow:'ctrip_revenue_ai_pricing_generation',evidence_status:'operator_provided',auto_write_ota:false};
const draft={analysis_date:'2026-10-01',room_type_id:100,competitor_hotel_id:0,competitor_name:'隔离人工竞品',our_price:328.5,competitor_price:298.2};
const row=(extra={})=>({id:501,hotel_id:9001,analysis_date:draft.analysis_date,room_type_id:100,competitor_hotel_id:0,ota_platform:1,our_price:'328.50',competitor_price:'298.20',competitor_data:{...meta,input_type:'manual_ctrip_competitor_price_sample',competitor_name:draft.competitor_name},...extra});
const flush=()=>new Promise(resolve=>setImmediate(resolve));
function harness(){
    const ref=value=>({value}), calls=[],toasts=[],hotel=ref('9001');
    const context={URLSearchParams,JSON,Object,Number,String,Array,console:{error(){}},
        filterReportHotel:hotel,competitorPriceForm:ref({...draft}),competitorPriceSaving:ref(false),competitorPriceSaveReadback:ref(null),
        manualCtripPricingInputMeta:meta,createCompetitorPriceForm:()=>({...draft,our_price:null,competitor_price:null,competitor_name:''}),
        captureAgentRevenueRequestContext:()=>({hotelId:hotel.value}),isAgentRevenueRequestCurrent:captured=>captured.hotelId===hotel.value,
        syncRevenuePricingInputDate(){},showToast:(...args)=>toasts.push(args),
        request:(url,options={})=>new Promise((resolve,reject)=>calls.push({url,options,resolve,reject})),
    };
    for(const name of ['loadCompetitorAnalysis','loadRevenueAnalysis','loadRevenueDashboard','loadRevenueAiOverview','loadPriceSuggestions'])context[name]=async()=>{};
    vm.createContext(context);vm.runInContext(`${lifecycle};this.save=saveCompetitorPriceInput;this.verify=typeof verifyCompetitorPriceSaveReadback==='function'?verifyCompetitorPriceSaveReadback:null;this.clear=resetCompetitorPriceForm;`,context);
    return {calls,toasts,hotel,form:context.competitorPriceForm,saving:context.competitorPriceSaving,receipt:context.competitorPriceSaveReadback,save:context.save,verify:context.verify,clear:context.clear};
}
async function accept(h,response={code:200,data:{id:501,readback_verified:true,price_sample:row()}}){h.calls[0].resolve(response);await flush();}
async function read(h,rows=[row()]){const call=h.calls.findLast(x=>!x.options.method);assert.ok(call,'an independent scoped GET is required');assert.match(call.url,/hotel_id=9001.*date=2026-10-01/);call.resolve({code:200,data:{date:'2026-10-01',query_scope:{hotel_id:9001,date:'2026-10-01'},price_matrix:{'隔离房型':Object.fromEntries(rows.map((x,i)=>['sample'+i,x]))}}});await flush();}
test('a save keeps the input until independent exact readback confirms the stored sample',async()=>{
    const h=harness(),pending=h.save();await accept(h);
    assert.equal(h.form.value.our_price,328.5);assert.ok(h.receipt.value);assert.notEqual(h.receipt.value.status,'verified');
    await read(h);await pending;assert.equal(h.receipt.value.status,'verified');assert.equal(h.form.value.our_price,null);assert.equal(h.saving.value,false);
});
for(const [name,rows] of [['missing record',[]],['different amount',[row({our_price:329.5})]],['foreign hotel',[row({hotel_id:9002})]],['different room',[row({room_type_id:101})]],['foreign date',[row({analysis_date:'2026-10-02'})]],['foreign platform',[row({ota_platform:2})]],['unverified source',[row({competitor_data:{}})]],['duplicate saved id',[row(),row()]]]){
    test(`${name} preserves the draft instead of claiming successful readback`,async()=>{
        const h=harness(),pending=h.save();await accept(h);await read(h,rows);await pending;
        assert.equal(h.receipt.value.status,'failed');assert.equal(h.form.value.our_price,328.5);
    });
}
test('failed readback retries only GET and cannot create another sample',async()=>{
    const h=harness(),pending=h.save();await accept(h);h.calls.findLast(x=>!x.options.method).reject(new Error('隔离读取失败'));await pending;
    assert.equal(h.receipt.value.status,'failed');await h.save();assert.equal(h.calls.filter(x=>x.options.method==='POST').length,1);
    assert.ok(h.verify);const retry=h.verify();await read(h);await retry;assert.equal(h.receipt.value.status,'verified');assert.equal(h.calls.filter(x=>x.options.method==='POST').length,1);
});
test('legacy id-only save response can be confirmed by exact GET without duplicating POST',async()=>{
    const h=harness(),pending=h.save();await accept(h,{code:200,data:{id:'501'}});await read(h);await pending;assert.equal(h.receipt.value.status,'verified');
});
test('PHP-style cent rounding is accepted and the receipt displays the exact stored amount',async()=>{
    const h=harness();h.form.value.our_price=328.505;const pending=h.save();
    await accept(h,{code:200,data:{id:501,readback_verified:true,price_sample:row({our_price:328.51})}});
    await read(h,[row({our_price:328.51})]);await pending;
    assert.equal(h.receipt.value.status,'verified');assert.match(h.receipt.value.message,/328\.51/);assert.equal(h.form.value.our_price,null);
});
test('unknown transport outcome keeps input and blocks automatic resubmission',async()=>{
    const h=harness(),pending=h.save();h.calls[0].reject(new Error('隔离连接中断'));await pending;
    assert.equal(h.receipt.value.status,'unknown');assert.equal(h.form.value.our_price,328.5);await h.save();assert.equal(h.calls.length,1);
});
test('duplicate clicks while submitting issue one POST',async()=>{
    const h=harness(),pending=h.save();await h.save();assert.equal(h.calls.length,1);h.calls[0].resolve({code:422,message:'隔离拒绝'});await pending;
});
test('hotel change prevents an old save from clearing the new hotel input',async()=>{
    const h=harness(),pending=h.save();h.hotel.value='9002';h.form.value={...draft,competitor_name:'新酒店草稿'};await accept(h);await pending;
    assert.equal(h.form.value.competitor_name,'新酒店草稿');assert.equal(h.calls.length,1);
});
test('a new unsaved edit during verification survives confirmation of the previous sample',async()=>{
    const h=harness(),pending=h.save();await accept(h);h.form.value.our_price=345.6;await read(h);await pending;
    assert.equal(h.receipt.value.status,'verified');assert.equal(h.form.value.our_price,345.6);
});
test('a late previous-hotel GET cannot restore its receipt or clear the new hotel input',async()=>{
    const h=harness(),pending=h.save();await accept(h);h.hotel.value='9002';h.receipt.value=null;h.saving.value=false;h.form.value={...draft,competitor_name:'新酒店草稿'};
    await read(h);await pending;assert.equal(h.receipt.value,null);assert.equal(h.form.value.competitor_name,'新酒店草稿');assert.equal(h.saving.value,false);
});
test('an explicit new save rejection cannot leave an older verified receipt on the new input',async()=>{
    const h=harness();h.receipt.value={status:'verified',message:'previous saved sample'};
    const pending=h.save();assert.equal(h.receipt.value,null);h.calls[0].resolve({code:422,message:'隔离拒绝'});await pending;
    assert.equal(h.receipt.value,null);assert.equal(h.form.value.our_price,328.5);
});
test('a malformed scoped GET receipt cannot confirm a stored sample',async()=>{
    const h=harness(),pending=h.save();await accept(h);h.calls.findLast(x=>!x.options.method).resolve({code:200,data:{price_matrix:{},query_scope:{hotel_id:9002,date:'2026-10-01'}}});await pending;
    assert.equal(h.receipt.value.status,'failed');assert.equal(h.form.value.our_price,328.5);
});
const template=readFileSync('resources/frontend/templates/fragments/27-page-agent-center.html','utf8');
function findPanel(nodes){for(const node of nodes){if(node.type===1&&node.props?.some(prop=>prop.type===6&&prop.name==='data-testid'&&prop.value?.content==='agent-suggestion-ctrip-competitor-price-manual-input'))return node;const found=node.children&&findPanel(node.children);if(found)return found;}}
const panel=findPanel(parse(template).children);
const render=new Function('Vue',compile(panel.loc.source,{mode:'function',prefixIdentifiers:true}).code)(Vue);
for(const status of ['failed','unknown','verified'])test(`actual sample panel displays the ${status} receipt and the appropriate recovery controls`,async()=>{
    const html=await renderToString(Vue.createSSRApp({data:()=>({competitorPriceSaveReadback:{id:status==='unknown'?0:501,status,message:'隔离回读状态 '+status},competitorPriceSaving:false,competitorPriceForm:{...draft},roomTypeConfigList:[]}),methods:{loadCompetitorAnalysis(){},verifyCompetitorPriceSaveReadback(){},saveCompetitorPriceInput(){},resetCompetitorPriceForm(){}},render}));
    assert.match(html,new RegExp('隔离回读状态 '+status));assert.match(html,/data-form-draft="off"/);assert.match(html,/清空并重新录入/);
    if(status==='failed')assert.match(html,/重试准确回读（不重复保存）/);else assert.doesNotMatch(html,/重试准确回读（不重复保存）/);
    const saveButton=html.match(/<button\b[^>]*>[\s\S]*?<\/button>/g)?.find(button=>button.includes('保存样本'))||'';
    assert.ok(saveButton);
    if(status==='verified')assert.doesNotMatch(saveButton,/<button[^>]*\sdisabled(?:[=\s>])/);else assert.match(saveButton,/<button[^>]*\sdisabled(?:[=\s>])/);
});
