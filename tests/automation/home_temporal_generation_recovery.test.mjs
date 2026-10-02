import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import * as Vue from 'vue';
import { compile } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';

let source = fs.readFileSync('public/app-main.js', 'utf8');
if(process.env.SUXI_TEMPORAL_METHODS){
  const start=source.indexOf('            const loadHomeTemporalInsights =');
  const end=source.indexOf('            const submitHomeTemporalRecommendationForReview =',start);
  assert.ok(start>=0&&end>start);
  source=source.slice(0,start)+fs.readFileSync(process.env.SUXI_TEMPORAL_METHODS,'utf8')+source.slice(end);
}
function between(start, end) { const a=source.indexOf(start), b=source.indexOf(end,a); assert.ok(a>=0&&b>a,start); return source.slice(a,b); }
const auth=between('const captureAuthSession =','const createDefaultAuthContext =');
const business=between('const BUSINESS_CONTEXT_ENDPOINT_PREFIXES =','const userHasPermission =');
const policy=between('const currentPageReadPolicy =','const cancelPageLoadRequests =');
const requests=between('const COORDINATED_GET_MAX_CONCURRENCY = 3;','const askSystemUsageGuide =');
const state=between('const createEmptyHomeTemporalData =','const homeTemporalPilotHistoryCoverage =');
const cards=between('const homeTemporalCards =','const homeTemporalReview =');
const domain=between(source.includes('const homeTemporalGenerationScopeKey =')?'const homeTemporalGenerationScopeKey =':'const loadHomeTemporalInsights =','const submitHomeTemporalRecommendationForReview =');
const refresh=between('const refreshCompassDashboard =','            watch(homeRevenueFactBusinessDate,');
const model=between('const homeBusinessTimeModel =','const homeAiWorkbenchMetricFallback =');
const hotelWatch=between('watch(filterReportHotel, (newHotelId, previousHotelId) => {','            watch(weatherLocationName,');
const reset=between('const clearActiveHotelDashboardSnapshots =','const beginAuthSession =')
  .split('\n').filter(line=>/^\s*homeTemporal\w+(?:\.value)?\s*(?:=|\+=)/.test(line)).join('\n');
assert.ok(reset.includes('homeTemporalRequestSeq += 1;')&&!reset.includes('homeTemporalGenerating'), 'actual reset leaves generator lock untouched');
const template=fs.readFileSync('resources/frontend/templates/fragments/23a-page-compass-summary.html','utf8');
const header=template.slice(template.indexOf('<header'),template.indexOf('</header>')+9);
const headerRender=new Function('Vue',compile(header,{mode:'function',prefixIdentifiers:true}).code)(Vue);
const flatten=n=>!n||typeof n!=='object'?[]:[n,...(Array.isArray(n.children)?n.children.flatMap(flatten):[])];
const defer=()=>{let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b;});return{promise,resolve,reject};};
const response=(data,status=200)=>new Response(JSON.stringify(data),{status,headers:{'Content-Type':'application/json'}});
const flush=async()=>{await Vue.nextTick();await new Promise(r=>setTimeout(r,0));await Vue.nextTick();};
let assertions=0;const eq=(a,b,m)=>{assertions++;assert.equal(a,b,m);};const match=(a,b)=>{assertions++;assert.match(a,b);};
function overview(hotel,run='synthetic-run-'+hotel){return{
  metric_scope:'ota_channel',scope_note:'仅反映已授权 OTA 渠道数据。',
  past:{status:'empty',period:{start_date:'2026-08-16',end_date:'2026-09-14'},series:[],metrics:{}},
  present:{status:'empty',snapshot_row_count:0},review:{status:'empty',cohorts:[],items:[]},
  future:{status:'ready',requested_metric_key:'ota_room_nights',requested_horizon_days:1,
    version:{forecast_run_id:run,as_of_date:'2026-09-15',as_of_time:'2026-09-15 09:00:00',model_version:'synthetic',source_start_date:'2026-08-16',source_end_date:'2026-09-14'},
    series:[{date:'2026-09-16',metrics:{ota_room_nights:{forecast_point_id:hotel*100,forecast_run_id:run,horizon_days:1,predicted_value:0,lower_bound:0,upper_bound:2,direction:'stable',confidence_score:0.6,confidence_type:'rule_index',operational_gate:{status:'disabled_insufficient_evidence',reason:'synthetic：来源合格成熟样本不足'}}}}],
    operational_status:'disabled',operation_recommendation:{can_submit_for_review:false,disabled_reason:'synthetic：样本不足'}}};}
function generated(hotel){const data=overview(hotel);return{code:200,data:{status:'generated',system_hotel_id:hotel,forecast_run_id:data.future.version.forecast_run_id,as_of_date:'2026-09-15',as_of_time:'2026-09-15 09:00:00',model_version:'synthetic',metric_scope:'ota_channel',requested_metric_key:'ota_room_nights',requested_horizon_days:1,saved_count:0,readback_count:1,persistence_status:'idempotent_readback_verified',idempotent_replay:true,operational_status:'disabled',points:data.future.series,message:'合成原酒店 '+hotel+' 预测版本已回读，仅观察'}};}
const activeCalls=[];
test.afterEach(async()=>{for(const calls of activeCalls.splice(0))for(const call of calls)call.reject(new Error('synthetic fixture teardown'));await flush();});
function fixture(){
  const calls=[],notices=[],loads=[];activeCalls.push(calls);let headerTree,axisTree,lastGeneration;
  const hotels=Vue.ref([{id:7,name:'合成门店七'},{id:8,name:'合成门店八'}]);
  const s={...Vue,window:{Vue},URL,URLSearchParams,Headers,AbortController,structuredClone,API_BASE:'https://synthetic.invalid/api',
    token:Vue.ref('synthetic-session'),authSessionEpoch:0,pageRequestGeneration:1,currentPage:Vue.ref('compass'),filterReportHotel:Vue.ref('7'),user:Vue.ref({id:42,hotel_id:7,tenant_id:5,is_super_admin:true}),authContext:Vue.ref({tenantId:'5',hotelId:'7'}),
    revenueAiBusinessDate:Vue.ref(''),coreOperationsTargetDate:Vue.ref(''),homeRevenueFactBusinessDate:Vue.ref('2026-09-14'),
    homeRevenueFactLayer:Vue.ref(null),homeRevenueFactLayerLoading:Vue.ref(false),homeRevenueFactLayerError:Vue.ref(''),revenueAiMetricCards:Vue.ref([]),revenueAiOverview:Vue.ref(null),
    compassHotelOptions:hotels,permittedHotels:hotels,hotels,compassLoading:Vue.ref(false),dualOtaPmsSelected:Vue.ref(false),dualOtaConfiguredPms:Vue.ref(null),
    selectedWeatherCity:Vue.ref(''),externalWeatherForecast:Vue.ref([]),externalWeatherCity:Vue.ref(''),weatherError:Vue.ref(''),
    suppressNextReportHotelDashboardRefresh:false,dualOtaSuppressHotelSearchRecord:true,
    resetAgentCenterClientState(){},clearPostFetchRefreshTimers(){},clearPlatformProfileLoginTimers(){},persistDualOtaWorkbenchPreferences(){},recordDualOtaHotelSearch(){},refreshDualOtaWorkbenchData(){},
    reportHotelOptionExists:id=>['7','8'].includes(String(id)),isCompassDataPage:(p)=>String(p??s.currentPage.value)==='compass',
    normalizeSuxiDomAttributeText:v=>String(v??''),formatNumber:v=>String(v),
    isTerminalAuthFailureResponse:()=>false,createRequestAbortError:(message='Request aborted')=>Object.assign(new Error(message),{name:'AbortError'}),
    showToast:(message,type='success')=>notices.push({message,type}),console:{error(){}},
    loadHomeTemporalTrials:async()=>{},loadCompassData:()=>{const pending=s.ui.loadHomeTemporalInsights();loads.push(pending);return pending;},
    refreshCompassDashboard:()=>s.ui.loadHomeTemporalInsights(),
    fetch:(url,options)=>{assert.ok(url.startsWith(s.API_BASE));const parsed=new URL(url);assert.ok(['/api/temporal-insights/forecasts','/api/temporal-insights/overview'].includes(parsed.pathname));const pending=defer();calls.push({url,options,body:options.body?JSON.parse(options.body):null,...pending});return pending.promise;},
  };
  vm.runInNewContext(fs.readFileSync('public/system-static.js','utf8')+'\n'+fs.readFileSync('public/home-static.js','utf8'),s);
  vm.runInNewContext(`const appSystemStatic=window.SUXI_SYSTEM_STATIC;const requireAppSystemStatic=key=>appSystemStatic[key];const readRequestCooldown=appSystemStatic.createReadRequestCooldown();
    ${auth}\n${business}\n${policy}\n${requests}\n${state}\n${cards}\n${domain}\n${refresh}
    const buildHomeBusinessTimeModel=window.SUXI_HOME_STATIC.buildHomeBusinessTimeModel;
    const homeObservation=computed(()=>({hotelName:'合成门店 '+filterReportHotel.value}));
    ${model}
    const clearActiveHotelDashboardSnapshots=()=>{${reset}};
    ${hotelWatch}
    watch(currentPage,()=>{pageRequestGeneration+=1;});
    globalThis.ui={homeTemporalData,homeTemporalError,homeTemporalLoading,homeTemporalGenerating,homeTemporalCards,homeBusinessTimeModel,loadHomeTemporalInsights,generateHomeTemporalForecast,refreshCompassDashboard};`,s);
  async function render(){const base={...s.ui,currentPage:s.currentPage,filterReportHotel:s.filterReportHotel,compassHotelOptions:hotels,homeRevenueFactBusinessDate:s.homeRevenueFactBusinessDate,homeRevenueFactLayerLoading:s.homeRevenueFactLayerLoading,compassLoading:s.compassLoading};
    const app=Vue.createSSRApp({setup:()=>base,render(...args){headerTree=headerRender.apply(this,args);return headerTree;}});
    const head=await renderToString(app);const original=s.window.SUXI_HOME_STATIC.HomeBusinessTimeAxis;
    const axis=Vue.createSSRApp({...original,render(){axisTree=original.render.call(this);return axisTree;}},{model:s.ui.homeBusinessTimeModel.value,generating:s.ui.homeTemporalGenerating.value,selectedHotelId:s.filterReportHotel.value,onGenerate:()=>{lastGeneration=s.ui.generateHomeTemporalForecast();}});
    return head+await renderToString(axis);}
  async function click(){await render();const n=flatten(axisTree).find(n=>n.type==='button');assert.ok(n);if(n.props.disabled)return{disabled:true,done:Promise.resolve()};n.props.onClick();return{disabled:false,done:lastGeneration};}
  async function hotel(id){await render();const n=flatten(headerTree).find(n=>n.type==='select'&&n.props?.['aria-label']==='首页门店');assert.ok(n&&!n.props.disabled);n.props['onUpdate:modelValue'](String(id));await flush();}
  async function refreshClick(){await render();const n=flatten(headerTree).find(n=>n.type==='button');assert.ok(n&&!n.props.disabled);return{done:n.props.onClick()};}
  const reads=()=>calls.filter(c=>!c.body);
  const writes=()=>calls.filter(c=>c.body);
  function finishRead(index=-1,data){const c=reads().at(index);assert.ok(c);const id=Number(new URL(c.url).searchParams.get('hotel_id'));c.resolve(response({code:200,data:data||overview(id)}));}
  return{s,ui:s.ui,calls,notices,loads,render,click,hotel,refreshClick,reads,writes,finishRead};
}

test('current focused replay is verified, keeps zero as forecast and stays observation-only',async()=>{
  const f=fixture(),a=await f.click();await flush();eq((await f.click()).disabled,true);eq(f.writes().length,1);
  f.writes()[0].resolve(response(generated(7)));await flush();eq(f.reads().length,1);f.finishRead();await a.done;
  eq(f.ui.homeTemporalGenerating.value,false);eq(f.notices[0].type,'warning');match(await f.render(),/9月16日预计 0 间夜–2 间夜/);match(await f.render(),/预测不写成事实/);eq(f.ui.homeTemporalData.value.future.operation_recommendation.can_submit_for_review,false);
});
for(const outcome of ['success','failure'])test('old hotel '+outcome+' cannot overwrite B, issue B refresh or unlock new B generation',async()=>{
  const f=fixture(),a=await f.click();await flush();await f.hotel(8);f.finishRead();await f.loads.at(-1);
  const b=await f.click();eq(b.disabled,false);await flush();eq(f.writes().length,2);eq(f.writes()[1].body.hotel_id,8);
  const noticeCount=f.notices.length,readCount=f.reads().length;
  if(outcome==='success')f.writes()[0].resolve(response(generated(7)));else f.writes()[0].resolve(response({code:422,message:'old A rejection'},422));
  await a.done;eq(f.notices.length,noticeCount);eq(f.reads().length,readCount);eq(f.ui.homeTemporalData.value.future.version.forecast_run_id,'synthetic-run-8');eq(f.ui.homeTemporalData.value.future.status,'ready');eq(f.ui.homeTemporalGenerating.value,true);
  f.writes()[1].resolve(response(generated(8)));await flush();f.finishRead();await b.done;eq(f.ui.homeTemporalGenerating.value,false);eq(f.ui.homeTemporalData.value.future.version.forecast_run_id,'synthetic-run-8');
});
test('A to B to A creates a new UI owner and ignores original A failure',async()=>{
  const f=fixture(),a=await f.click();await flush();await f.hotel(8);f.finishRead();await f.loads.at(-1);await f.hotel(7);f.finishRead();await f.loads.at(-1);
  const b=await f.click();eq(b.disabled,false);await flush();f.writes()[0].reject(new Error('obsolete first A'));await a.done;
  eq(f.ui.homeTemporalGenerating.value,true);eq(f.ui.homeTemporalError.value,'');eq(f.notices.length,0);
  f.writes()[1].resolve(response(generated(7)));await flush();f.finishRead();await b.done;eq(f.ui.homeTemporalGenerating.value,false);
});
test('page leave and return isolates old generation while current read remains usable',async()=>{
  const f=fixture(),a=await f.click();await flush();f.s.currentPage.value='revenue-research-center';await flush();f.s.currentPage.value='compass';await flush();
  const b=await f.click();eq(b.disabled,false);await flush();f.writes()[0].reject(new Error('old page error'));await a.done;
  eq(f.ui.homeTemporalError.value,'');eq(f.ui.homeTemporalGenerating.value,true);eq(f.notices.length,0);
  f.writes()[1].resolve(response(generated(7)));await flush();f.finishRead();await b.done;
});
test('auth transition isolates an old POST response and allows current session generation',async()=>{
  const f=fixture(),a=await f.click();await flush();f.s.authSessionEpoch+=1;f.s.token.value='synthetic-other-session';await flush();
  const b=await f.click();eq(b.disabled,false);await flush();f.writes()[0].resolve(response({code:422,message:'old auth response'},422));await a.done;
  eq(f.ui.homeTemporalGenerating.value,true);eq(f.ui.homeTemporalError.value,'');eq(f.notices.length,0);
  f.writes()[1].resolve(response(generated(7)));await flush();f.finishRead();await b.done;eq(f.ui.homeTemporalGenerating.value,false);
});
test('current explicit rejection is shown and only a new click retries',async()=>{
  const f=fixture(),a=await f.click();await flush();f.writes()[0].resolve(response({code:422,message:'current refusal'},422));await a.done;
  eq(f.ui.homeTemporalError.value,'current refusal');eq(f.ui.homeTemporalGenerating.value,false);eq(f.writes().length,1);eq(f.reads().length,0);
  const b=await f.click();await flush();eq(f.writes().length,2);f.writes()[1].resolve(response(generated(7)));await flush();f.finishRead();await b.done;eq(f.ui.homeTemporalError.value,'');
});
const malformed={
  'wrong hotel':d=>{d.system_hotel_id=8;},
  'wrong metric':d=>{d.requested_metric_key='ota_revenue';},
  'wrong horizon':d=>{d.requested_horizon_days=7;},
  'missing run':d=>{d.forecast_run_id='';},
  'mismatched point run':d=>{d.points[0].metrics.ota_room_nights.forecast_run_id='other-run';},
  'boolean point ID':d=>{d.points[0].metrics.ota_room_nights.forecast_point_id=true;},
  'boolean read count':d=>{d.readback_count=true;},
  'missing selected metric':d=>{d.points[0].metrics={};},
  'unverified persistence':d=>{d.persistence_status='saved';},
  'count does not match receipt':d=>{d.readback_count=2;},
  'wrong point business date':d=>{d.points[0].date='2026-09-17';},
  'invalid server as-of date':d=>{d.as_of_date='2026-02-30';},
  'multiple T+1 points with matching count':d=>{const other=structuredClone(d.points[0]);other.metrics.ota_room_nights.forecast_point_id+=1;d.points.push(other);d.readback_count=2;},
};
for(const [name,mutate] of Object.entries(malformed))test('generated receipt with '+name+' is not confirmed or followed by a read',async()=>{
  const f=fixture(),a=await f.click();await flush();const receipt=generated(7);mutate(receipt.data);f.writes()[0].resolve(response(receipt));await flush();
  eq(f.reads().length,0);await a.done;eq(f.notices.at(-1).type,'error');eq(f.ui.homeTemporalData.value.future.status,'blocked');eq(f.ui.homeTemporalGenerating.value,false);
});
test('newly persisted legitimate current generation is accepted alongside replay',async()=>{
  const f=fixture(),a=await f.click();await flush();const receipt=generated(7);Object.assign(receipt.data,{saved_count:1,persistence_status:'saved_and_readback_verified',idempotent_replay:false});f.writes()[0].resolve(response(receipt));await flush();f.finishRead();await a.done;
  eq(f.ui.homeTemporalData.value.future.status,'ready');eq(f.ui.homeTemporalError.value,'');
});
test('legitimate server as-of leap-day transition does not depend on client today',async()=>{
  const f=fixture(),a=await f.click();await flush();const receipt=generated(7);receipt.data.as_of_date='2024-02-28';receipt.data.points[0].date='2024-02-29';
  f.writes()[0].resolve(response(receipt));await flush();eq(f.reads().length,1);f.finishRead();await a.done;eq(f.ui.homeTemporalError.value,'');
});
test('current confirmed generation forces a new GET and late old empty GET cannot erase it',async()=>{
  const f=fixture(),a=await f.click();await flush();const manual=await f.refreshClick();await flush();eq(f.reads().length,1);
  f.writes()[0].resolve(response(generated(7)));await flush();eq(f.reads().length,2,'current confirmed generation must issue a new physical GET');
  f.finishRead(1);await a.done;const empty=overview(7);empty.future={status:'empty',series:[]};f.finishRead(0,empty);await manual.done;await flush();
  eq(f.ui.homeTemporalData.value.future.status,'ready');eq(f.ui.homeTemporalData.value.future.version.forecast_run_id,'synthetic-run-7');eq(f.ui.homeTemporalError.value,'');eq(f.writes().length,1);
});
test('ordinary same-scope overview reads still share one HTTP',async()=>{
  const f=fixture(),a=f.ui.loadHomeTemporalInsights(),b=f.ui.loadHomeTemporalInsights();await flush();eq(f.reads().length,1);f.finishRead();await Promise.all([a,b]);eq(f.ui.homeTemporalData.value.future.status,'ready');eq(f.ui.homeTemporalLoading.value,false);
});
test('confirmed save followed by current overview read failure shows read failure without automatic POST',async()=>{
  const f=fixture(),a=await f.click();await flush();f.writes()[0].resolve(response(generated(7)));await flush();f.reads()[0].resolve(response({code:500,message:'synthetic overview read failed'},500));await a.done;
  eq(f.ui.homeTemporalError.value,'synthetic overview read failed');eq(f.ui.homeTemporalGenerating.value,false);eq(f.writes().length,1);match(f.notices[0].message,/已回读/);
});
