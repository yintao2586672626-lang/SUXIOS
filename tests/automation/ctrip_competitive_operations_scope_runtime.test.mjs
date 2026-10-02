import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const app=readFileSync('public/app-main.js','utf8');
const slice=(start,end)=>{const a=app.indexOf(start),b=app.indexOf(end,a);assert.ok(a>=0&&b>a,start);return app.slice(a,b);};
const state=slice('const ctripCompetitiveOperationsPayload =','const ctripChannelOrderUploadOpen =');
const reset=slice('const resetCoreOperationsScopedState =','const refreshCoreOperationsLoop =');
const load=slice('const loadCtripCompetitiveOperations =','const loadCtripCompetitorEventFeed =');
function harness(){
 const requests=[],toasts=[],selectedCtripHotelId={value:'80'};
 const dependencies=Object.fromEntries([...reset.matchAll(/\b(\w+)\.value\s*=/g)].map(m=>[m[1],{value:null}]));
 for(const key of Object.keys(dependencies))if(key.startsWith('ctripCompetitiveOperations'))delete dependencies[key];
 const context=vm.createContext({...dependencies,selectedCtripHotelId,collectionReliabilityRequestSeq:0,
  ref:value=>({value}),computed:get=>({get value(){return get();}}),ctripCompetitiveLocalDate:day=>day===-29?'2026-08-25':'2026-09-23',
  formatOptionalNumber:(value,fallback)=>value==null?fallback:String(value),formatOptionalPercent:(value,fallback)=>value==null?fallback:String(value*100)+'%',
  showToast:(...args)=>toasts.push(args),request:(url,options)=>new Promise((resolve,reject)=>requests.push({url,options,resolve,reject})),
 });
 vm.runInContext(state+reset+load+'\nglobalThis.api={loadCtripCompetitiveOperations,resetCoreOperationsScopedState,ctripCompetitiveOperationsPayload,ctripCompetitiveOperationsLoading,ctripCompetitiveOperationsError,ctripCompetitiveOperationsRange};',context);
 return {...context.api,requests,toasts,selectedCtripHotelId};
}
const good=marker=>({code:200,data:{marker,status:'partial',business_comparison:{self:{amount:0,adr:null}}}});

test('clearing the competition hotel settles loading and ignores the earlier response',async()=>{
 const h=harness(),old=h.loadCtripCompetitiveOperations();
 assert.equal(h.ctripCompetitiveOperationsLoading.value,true);
 h.selectedCtripHotelId.value='';assert.equal(await h.loadCtripCompetitiveOperations(),null);
 assert.equal(h.requests.length,1);assert.equal(h.ctripCompetitiveOperationsLoading.value,false);
 h.requests[0].resolve(good('old'));await old;assert.equal(h.ctripCompetitiveOperationsPayload.value,null);
});
test('invalid competition range settles loading, shows date failure and remains recoverable',async()=>{
 const h=harness(),old=h.loadCtripCompetitiveOperations();
 h.ctripCompetitiveOperationsRange.value.end_date='';await h.loadCtripCompetitiveOperations();
 assert.equal(h.ctripCompetitiveOperationsLoading.value,false);assert.match(h.ctripCompetitiveOperationsError.value,/日期范围/);
 h.requests[0].reject(new Error('late failure'));await old;assert.match(h.ctripCompetitiveOperationsError.value,/日期范围/);
 h.ctripCompetitiveOperationsRange.value.end_date='2026-09-23';const next=h.loadCtripCompetitiveOperations();h.requests[1].resolve(good('recovered'));await next;
 assert.equal(h.ctripCompetitiveOperationsError.value,'');assert.equal(h.ctripCompetitiveOperationsPayload.value.marker,'recovered');
});
test('core scope reset invalidates a pending explicitly scoped competition read',async()=>{
 const h=harness(),old=h.loadCtripCompetitiveOperations({systemHotelId:'81',startDate:'2026-08-24',endDate:'2026-09-22',silent:true});
 h.resetCoreOperationsScopedState();
 h.requests[0].resolve(good('old-core'));await old;assert.equal(h.ctripCompetitiveOperationsPayload.value,null);assert.equal(h.ctripCompetitiveOperationsLoading.value,false);
});
test('account reset cannot be followed by an old competition error or loading flag',async()=>{
 const h=harness(),old=h.loadCtripCompetitiveOperations();
 h.resetCoreOperationsScopedState();h.requests[0].reject(new Error('Authentication session changed'));await old;
 assert.equal(h.ctripCompetitiveOperationsError.value,'');assert.equal(h.ctripCompetitiveOperationsLoading.value,false);assert.equal(h.toasts.length,0);
});
test('an older read cannot clear a newer loading state or replace its result',async()=>{
 const h=harness(),old=h.loadCtripCompetitiveOperations();
 h.resetCoreOperationsScopedState();const next=h.loadCtripCompetitiveOperations({systemHotelId:'81',startDate:'2026-08-24',endDate:'2026-09-22',silent:true});
 h.requests[0].reject(new Error('late'));await old;assert.equal(h.ctripCompetitiveOperationsLoading.value,true);assert.equal(h.ctripCompetitiveOperationsError.value,'');
 assert.match(h.requests[1].url,/system_hotel_id=81&start_date=2026-08-24&end_date=2026-09-22/);
 h.requests[1].resolve(good('new'));await next;assert.equal(h.ctripCompetitiveOperationsPayload.value.marker,'new');assert.equal(h.ctripCompetitiveOperationsLoading.value,false);
});
test('loaded results clear before refresh and a normal read preserves zero and missing values',async()=>{
 const h=harness(),first=h.loadCtripCompetitiveOperations();h.requests[0].resolve(good('first'));await first;
 const next=h.loadCtripCompetitiveOperations();assert.equal(h.ctripCompetitiveOperationsPayload.value,null);
 h.requests[1].resolve(good('second'));await next;assert.equal(h.ctripCompetitiveOperationsPayload.value.business_comparison.self.amount,0);assert.equal(h.ctripCompetitiveOperationsPayload.value.business_comparison.self.adr,null);
});
test('current failure stays explicit and retry is possible without changing source data',async()=>{
 const h=harness(),pending=h.loadCtripCompetitiveOperations({silent:true});h.requests[0].resolve({code:503,message:'合成来源不可用'});await pending;
 assert.equal(h.ctripCompetitiveOperationsPayload.value,null);assert.equal(h.ctripCompetitiveOperationsError.value,'合成来源不可用');assert.equal(h.toasts.length,0);
 const retry=h.loadCtripCompetitiveOperations();h.requests[1].resolve(good('retry'));await retry;
 assert.equal(h.ctripCompetitiveOperationsError.value,'');assert.equal(h.ctripCompetitiveOperationsLoading.value,false);
});
test('auth reset and invalid core scope both use the same competition reset path',()=>{
 const auth=slice('const resetHotelScopedClientState =','const beginAuthSession =');
 const core=slice('const refreshCoreOperationsLoop =','const loadPhase3OperationEffectLoop =');
 assert.match(auth,/resetCoreOperationsScopedState\(\)/);assert.match(core,/resetCoreOperationsScopedState\(\)/);
});
test('silent core range validation does not toast or launch a request',async()=>{
 const h=harness();await h.loadCtripCompetitiveOperations({systemHotelId:'81',startDate:'2026-09-24',endDate:'2026-09-23',silent:true});
 assert.equal(h.requests.length,0);assert.equal(h.toasts.length,0);assert.match(h.ctripCompetitiveOperationsError.value,/日期范围/);assert.equal(h.ctripCompetitiveOperationsLoading.value,false);
});
