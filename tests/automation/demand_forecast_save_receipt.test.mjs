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

for (const [input, expected] of [[0.499999, 1], [12.49999, 13], [12.49994, 12]]) test(`demand ${input} uses the API's four-decimal then integer precision`, async () => {
    const h = harness(); h.form.value.predicted_demand = input; const pending = h.save();
    assert.equal(JSON.parse(h.calls[0].options.body).predicted_demand, expected);
    h.calls[0].resolve(response(row({predicted_demand: expected}))); await pending;
    assert.equal(h.receipt.value.status, 'verified');
});

for (const malformed of [false, []]) test(`malformed numeric readback ${JSON.stringify(malformed)} cannot verify an explicit zero`, async () => {
    const h = harness(); h.form.value.predicted_occupancy = 0; const pending = h.save();
    h.calls[0].resolve(response(row({predicted_occupancy: malformed}))); await pending;
    assert.equal(h.receipt.value.status, 'failed'); assert.equal(h.form.value.predicted_occupancy, 0);
});

test('explicit zero occupancy submits and exact readback completes the draft',async()=>{
    const h=harness();h.form.value.predicted_occupancy=0;const pending=h.save();
    assert.equal(h.calls.length,1);assert.equal(JSON.parse(h.calls[0].options.body).predicted_occupancy,0);
    h.calls[0].resolve(response(row({predicted_occupancy:0})));await pending;
    assert.equal(h.receipt.value.status,'verified');assert.equal(h.form.value.predicted_occupancy,null);
});

for(const [input,expected] of [[75.5555,75.56],[2.675,2.68],[75.55499,75.55]])test(`occupancy ${input} submits in database precision`,async()=>{
    const h=harness();h.form.value.predicted_occupancy=input;const pending=h.save();
    assert.equal(JSON.parse(h.calls[0].options.body).predicted_occupancy,expected);
    h.calls[0].resolve(response(row({predicted_occupancy:expected})));await pending;
    assert.equal(h.receipt.value.status,'verified');
});

for(const invalid of [null,'',undefined,false,NaN,Infinity,-0.00001,100.00001])test(`invalid occupancy ${String(invalid)} preserves draft without submitting`,async()=>{
    const h=harness();h.form.value.predicted_occupancy=invalid;const pending=h.save();
    if(h.calls[0])h.calls[0].resolve({code:422,message:'invalid synthetic input'});await pending;
    assert.equal(h.calls.length,0);assert.deepEqual(h.form.value.predicted_occupancy,invalid);
});

test('demand outside persisted unsigned integer range cannot be submitted',async()=>{
    const h=harness();h.form.value.predicted_demand=4294967296;const pending=h.save();
    if(h.calls[0])h.calls[0].resolve({code:422,message:'invalid synthetic input'});await pending;assert.equal(h.calls.length,0);
});
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
    const html=await renderToString(Vue.createSSRApp({data:()=>({filterReportHotel:'9001',forecastFilter:{start_date:'2026-10-01',end_date:'2026-10-01'},revenueLoadState:{forecasts:{status:'failed',error:'隔离列表读取失败'}},demandForecasts:[],demandForecastSaveReadback:{status:'unknown',message:'隔离提交结果未确认'},demandForecastSaving:busy,demandForecastForm:{...draft},roomTypeConfigList:[]}),methods:{loadDemandForecasts(){},resetDemandForecastForm(){},saveDemandForecastInput(){}},render}));
    assert.match(html,/隔离提交结果未确认/);assert.match(html,/需求预测读取失败/);assert.match(html,/data-form-draft="off"/);
    const input=html.match(/<input\b[^>]*aria-label="人工预测入住率"[^>]*>/)?.[0];assert.ok(input);
    const clear=html.match(/<button\b[^>]*data-testid="agent-demand-forecast-clear"[^>]*>/)?.[0];assert.ok(clear);
    for(const control of [input,clear])if(busy)assert.match(control,/\sdisabled(?:[=\s>])/);else assert.doesNotMatch(control,/\sdisabled(?:[=\s>])/);
});
