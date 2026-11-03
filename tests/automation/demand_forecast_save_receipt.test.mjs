import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import * as Vue from 'vue';
import {compile,parse} from '@vue/compiler-dom';
import {renderToString} from '@vue/server-renderer';
const source=readFileSync('public/app-main.js','utf8');
const lifecycle=source.slice(source.indexOf('const resetDemandForecastForm ='),source.indexOf('const resetCompetitorPriceForm ='));
const draft={forecast_date:'2026-10-01',room_type_id:100,predicted_occupancy:75.5,predicted_demand:0,confidence_percent:80,remark:'隔离人工输入'};
const meta={input_scope:'manual_pricing_configuration',source_scope:'ctrip_ota_channel',target_workflow:'ctrip_revenue_ai_pricing_generation',evidence_status:'operator_provided',auto_write_ota:false};
const row=(extra={})=>({id:501,hotel_id:9001,room_type_id:100,forecast_date:'2026-10-01',forecast_method:3,predicted_occupancy:75.5,predicted_demand:0,confidence_score:0.8,historical_data:{...meta,input_type:'manual_demand_forecast'},remark:draft.remark,...extra});
function harness(){const ref=value=>({value}),calls=[],toasts=[],hotel=ref('9001');const context={JSON,Object,Number,String,Array,
    filterReportHotel:hotel,demandForecastForm:ref({...draft}),demandForecastSaving:ref(false),demandForecastSaveReadback:ref(null),
    manualCtripPricingInputMeta:meta,createDemandForecastForm:()=>({...draft,predicted_occupancy:null,predicted_demand:null,confidence_percent:null,remark:''}),
    captureAgentRevenueRequestContext:()=>({hotelId:hotel.value}),isAgentRevenueRequestCurrent:captured=>captured.hotelId===hotel.value,
    showToast:(...args)=>toasts.push(args),syncRevenuePricingInputDate(date){context.demandForecastForm.value.forecast_date=date;},
    request:(url,options)=>new Promise((resolve,reject)=>calls.push({url,options,resolve,reject}))};
    for(const name of ['loadDemandForecasts','loadRevenueAnalysis','loadRevenueDashboard','loadRevenueAiOverview'])context[name]=async()=>null;
    vm.createContext(context);vm.runInContext(`${lifecycle};this.save=saveDemandForecastInput;this.clear=resetDemandForecastForm;`,context);
    return {calls,toasts,hotel,form:context.demandForecastForm,saving:context.demandForecastSaving,receipt:context.demandForecastSaveReadback,save:context.save,clear:context.clear};}
const response=(forecast=row(),extra={})=>({code:200,data:{id:501,readback_verified:true,forecast,...extra}});
test('confirmed database receipt clears only the submitted draft and keeps explicit zero demand',async()=>{
    const h=harness(),pending=h.save();assert.equal(JSON.parse(h.calls[0].options.body).predicted_demand,0);h.calls[0].resolve(response());await pending;
    assert.equal(h.receipt.value?.status,'verified');assert.equal(h.form.value.predicted_occupancy,null);assert.equal(h.saving.value,false);
});
for(const [name,receipt]of [['missing contract',{code:200,data:{id:501}}],['false verification',response(row(),{readback_verified:false})],['foreign hotel',response(row({hotel_id:9002}))],['foreign date',response(row({forecast_date:'2026-10-02'}))],['different occupancy',response(row({predicted_occupancy:76}))],['different room',response(row({room_type_id:101}))],['missing source',response(row({historical_data:{}}))]])test(`${name} preserves input and cannot report completed save`,async()=>{
    const h=harness(),pending=h.save();h.calls[0].resolve(receipt);await pending;assert.equal(h.form.value.predicted_occupancy,75.5);assert.notEqual(h.receipt.value?.status,'verified');
});
test('clearing during save cannot destroy the pending draft',async()=>{
    const h=harness(),pending=h.save();h.clear();assert.equal(h.form.value.predicted_occupancy,75.5);h.calls[0].resolve({code:422,message:'隔离拒绝'});await pending;
});
test('a newly edited input survives completion of the earlier submitted draft',async()=>{
    const h=harness(),pending=h.save();h.form.value.predicted_occupancy=88;h.calls[0].resolve(response());await pending;assert.equal(h.form.value.predicted_occupancy,88);
});
test('duplicate calls issue one save request',async()=>{
    const h=harness(),pending=h.save(),second=h.save();assert.equal(h.calls.length,1);for(const call of h.calls)call.resolve({code:422,message:'隔离拒绝'});await Promise.all([pending,second]);
});
test('an old hotel receipt cannot clear a new hotel input',async()=>{
    const h=harness(),pending=h.save();h.hotel.value='9002';h.form.value={...draft,remark:'新酒店草稿'};h.calls[0].resolve(response());await pending;assert.equal(h.form.value.remark,'新酒店草稿');assert.equal(h.receipt.value,null);
});
test('a new forecast date is preserved rather than being synchronized back to the old submitted day',async()=>{
    const h=harness(),pending=h.save();h.form.value.forecast_date='2026-10-02';h.calls[0].resolve(response());await pending;assert.equal(h.form.value.forecast_date,'2026-10-02');
});
test('missing demand cannot masquerade as an explicitly recorded zero',async()=>{
    const h=harness(),pending=h.save();h.calls[0].resolve(response(row({predicted_demand:null})));await pending;
    assert.equal(h.receipt.value.status,'failed');assert.equal(h.form.value.predicted_demand,0);
});
test('unconfirmed transport preserves input and labels the save outcome as unknown',async()=>{
    const h=harness(),pending=h.save();h.calls[0].reject(new Error('隔离中断'));await pending;assert.equal(h.receipt.value.status,'unknown');assert.equal(h.form.value.predicted_occupancy,75.5);
});
const template=readFileSync('resources/frontend/templates/fragments/27-page-agent-center.html','utf8');
function findPanel(nodes){for(const node of nodes){if(node.type===1&&node.props?.some(prop=>prop.type===6&&prop.name==='data-testid'&&prop.value?.content==='agent-suggestion-demand-forecast-manual-input'))return node;const found=node.children&&findPanel(node.children);if(found)return found;}}
const render=new Function('Vue',compile(findPanel(parse(template).children).loc.source,{mode:'function',prefixIdentifiers:true}).code)(Vue);
for(const busy of [false,true])test(`actual forecast panel protects pending input when saving=${busy}`,async()=>{
    const html=await renderToString(Vue.createSSRApp({data:()=>({filterReportHotel:'9001',forecastFilter:{start_date:'2026-10-01',end_date:'2026-10-01'},demandForecastReadState:{status:'failed',error:'隔离列表读取失败'},demandForecasts:[],demandForecastSaveReadback:{status:'unknown',message:'隔离提交结果未确认'},demandForecastSaving:busy,demandForecastForm:{...draft},roomTypeConfigList:[]}),methods:{loadDemandForecasts(){},resetDemandForecastForm(){},saveDemandForecastInput(){}},render}));
    assert.match(html,/隔离提交结果未确认/);assert.match(html,/需求预测读取失败/);assert.match(html,/data-form-draft="off"/);
    const input=html.match(/<input\b[^>]*aria-label="人工预测入住率"[^>]*>/)?.[0];assert.ok(input);
    const clear=html.match(/<button\b[^>]*data-testid="agent-demand-forecast-clear"[^>]*>/)?.[0];assert.ok(clear);
    for(const control of [input,clear])if(busy)assert.match(control,/\sdisabled(?:[=\s>])/);else assert.doesNotMatch(control,/\sdisabled(?:[=\s>])/);
});
