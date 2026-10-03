import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import {ref} from 'vue';
const source=readFileSync('public/app-main.js','utf8');
const loader=source.slice(source.indexOf('            const applyRevenueAiOverviewReadback ='),source.indexOf('            const loadCompassData = async (options = {}) => {'));
const readStates = ['createRevenueLoadState', 'setRevenueLoadState'].map(name => { const a=source.indexOf('            const '+name+' ='); const b=/\r?\n            (?:const|let) /.exec(source.slice(a+1)); assert.ok(a>=0&&b,name); return source.slice(a,a+1+b.index); }).join('\n');
const deferred=()=>{let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b;});return{promise,resolve,reject};};
const flush=()=>new Promise(resolve=>setImmediate(resolve));
const helperContext={window:{},URLSearchParams};
for(const file of ['revenue-overview-contract-static.js','revenue-cockpit-static.js','revenue-ai-static.js'])vm.runInNewContext(readFileSync(`public/${file}`,'utf8'),helperContext);
function harness({sharedGate=false,actualResponse=false}={}){
  const gates=[],requests=[];
  const state={hotel:ref('64'),date:ref('2026-09-30'),page:ref('compass'),overview:ref({hotel_id:64,business_date:'2026-09-30',marker:'accepted A'}),loading:ref(false),error:ref(''),session:0};
  const context=vm.createContext({console:{error(){}},Map,token:ref('fixture'),canUseRevenueAi:()=>true,
    captureAuthSession:()=>state.session,isAuthSessionCurrent:session=>session===state.session,
    filterReportHotel:state.hotel,currentPage:state.page,revenueAiBusinessDate:state.date,coreOperationsTargetDate:ref(''),
    revenueAiOverview:state.overview,revenueAiOverviewLoading:state.loading,revenueAiOverviewError:state.error,
    currentPageReadPolicy:()=>({businessDate:state.date.value,hotelId:state.hotel.value}),buildPageLoadScopeToken:policy=>JSON.stringify(policy),isCompassDataPage:page=>page==='compass',
    ensureRevenueAiStaticReady:()=>{if(sharedGate&&gates.length)return gates[0].promise;const gate=deferred();gates.push(gate);return gate.promise;},
    revenueAiResolveOverviewRequest:({hotelId,businessDate})=>({shouldLoad:true,endpoint:`/overview?hotel_id=${hotelId}&business_date=${businessDate}`}),
    revenueAiResolveOverviewResponse:actualResponse?helperContext.window.SUXI_REVENUE_AI_STATIC.resolveRevenueAiOverviewResponse:({response,error})=>({overview:response?.data||null,errorMessage:error?.message||response?.message||''}),
    request:endpoint=>{const req=deferred();requests.push({...req,endpoint});return req.promise;},
  });
  context.revenueLoadState=ref({}); vm.runInContext(`${readStates}; revenueLoadState.value=createRevenueLoadState(); let revenueAiOverviewRequestSeq=0;const revenueAiOverviewRequestPromises=new Map();${loader};this.load=loadRevenueAiOverview;`,context);
  return{state,gates,requests,load:context.load};
}

for(const change of ['hotel','date','page','session']){
  test(`lazy module success after ${change} change does not start a request or leave a new scope loading`,async()=>{
    const h=harness(),pending=h.load();
    if(change==='session'){h.state.session++;h.state.loading.value=false;}else h.state[change].value={hotel:'65',date:'2026-10-01',page:'agent-center'}[change];
    h.state.overview.value=null;
    h.gates[0].resolve();await flush();
    // Resolve any wrongly started network read so a failing test cannot hang.
    for(const request of h.requests)request.resolve({data:{marker:'stale'}});
    await pending;assert.equal(h.requests.length,0);assert.equal(h.state.loading.value,false);assert.equal(h.state.overview.value,null);
  });
  test(`lazy module failure after ${change} change cannot restore the old hotel view`,async()=>{
    const h=harness(),pending=h.load();
    if(change==='session')h.state.session++;else h.state[change].value={hotel:'65',date:'2026-10-01',page:'agent-center'}[change];
    h.state.overview.value=null;h.state.error.value='new scope state';
    h.gates[0].reject(new Error('old lazy failure'));await pending;
    assert.equal(h.state.overview.value,null);assert.equal(h.state.error.value,'new scope state');
  });
}
test('older forced lazy failure cannot overwrite a newer accepted result in the same scope',async()=>{
  const h=harness(),old=h.load(),newer=h.load({force:true});
  h.gates[1].resolve();await flush();h.requests[0].resolve({data:{marker:'newer accepted'}});await newer;
  h.gates[0].reject(new Error('old lazy failure'));await old;
  assert.equal(h.state.overview.value.marker,'newer accepted');assert.equal(h.state.error.value,'');
});
test('current-scope lazy failure preserves the accepted view and permits explicit retry',async()=>{
  const h=harness(),failed=h.load();h.gates[0].reject(new Error('current lazy failure'));await failed;
  assert.equal(h.state.overview.value.marker,'accepted A');assert.equal(h.state.error.value,'current lazy failure');assert.equal(h.state.loading.value,false);
  const retry=h.load();h.gates[1].resolve();await flush();assert.match(h.requests[0].endpoint,/hotel_id=64&business_date=2026-09-30/);
  h.requests[0].resolve({data:{marker:'retry accepted'}});await retry;
  assert.equal(h.state.overview.value.marker,'retry accepted');assert.equal(h.state.error.value,'');assert.equal(h.state.loading.value,false);
});
test('forced refresh sharing a delayed module sends only the newest request',async()=>{
  const h=harness({sharedGate:true}),old=h.load(),newer=h.load({force:true});
  assert.equal(h.gates.length,1);h.gates[0].resolve();await flush();
  for(const req of h.requests)req.resolve({data:{marker:'newest accepted'}});
  await Promise.all([old,newer]);assert.equal(h.requests.length,1);assert.equal(h.state.overview.value.marker,'newest accepted');assert.equal(h.state.loading.value,false);
});
test('ordinary duplicate load reuses one in-flight module and network read',async()=>{
  const h=harness(),first=h.load(),second=h.load();assert.equal(h.gates.length,1);
  h.gates[0].resolve();await flush();assert.equal(h.requests.length,1);h.requests[0].resolve({data:{marker:'shared accepted'}});
  await Promise.all([first,second]);assert.equal(h.state.overview.value.marker,'shared accepted');assert.equal(h.state.loading.value,false);
});
for(const mismatch of [{hotel_id:65},{business_date:'2026-10-01'}])test(`current loader rejects a mismatched actual receipt ${JSON.stringify(mismatch)} and recovers on retry`,async()=>{
  const h=harness({actualResponse:true});
  const fixture={hotel_id:64,business_date:'2026-09-30',as_of_date:'2026-09-30',as_of_date_contract_version:'revenue_overview_as_of_date.v1',data_status:'data_missing'};
  const failed=h.load();h.gates[0].resolve();await flush();h.requests[0].resolve({code:200,data:{...fixture,...mismatch,marker:'wrong receipt'}});await failed;
  assert.equal(h.state.overview.value.marker,'accepted A');assert.match(h.state.error.value,/酒店或业务日期不一致/);assert.equal(h.state.loading.value,false);
  const retry=h.load({force:true});h.gates[1].resolve();await flush();h.requests[1].resolve({code:200,data:{...fixture,marker:'accepted retry'}});await retry;
  assert.equal(h.state.overview.value.marker,'accepted retry');assert.equal(h.state.error.value,'');assert.equal(h.state.loading.value,false);
});
