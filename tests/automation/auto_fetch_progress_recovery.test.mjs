import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync('public/app-main.js', 'utf8');
const extract = (from, to) => {
  const start = source.indexOf(from), end = source.indexOf(to, start + from.length);
  assert.ok(start >= 0 && end > start, `source region ${from}`);
  return source.slice(start, end);
};
const ref = value => ({ value });
const response = status => ({code:200, data:{
  detail_loaded:false, running_task:status === 'running' ? {started_at:'2026-09-08 09:00:00'} : null,
  last_result:{status, success:status === 'success'}, last_run_time:'2026-09-08 09:01:00',
}});
function harness() {
  const state = {hotel:'7', epoch:1, now:Date.parse('2026-09-08T01:00:00Z')};
  const requests=[], timers=new Map(), errors=[];
  let timerId=0;
  class TestDate extends Date {static now(){return state.now;}}
  const context = {
    Date:TestDate, URLSearchParams, AUTO_FETCH_PROGRESS_POLL_MS:2000, AUTO_FETCH_PANEL_CACHE_TTL_MS:45000,
    autoFetchProgressTimer:null, autoFetchProgressRequestRunning:false, autoFetchProgressGeneration:0, autoFetchProgressFailureCount:0,
    autoFetchStatus:ref(response('running').data), autoFetchRunState:ref({active:true,type:'running',started_at:'2026-09-08 09:00:00'}),fetchingData:ref(true),
    setTimeout:(fn,delay)=>{timers.set(++timerId,{fn,delay});return timerId;},clearTimeout:id=>timers.delete(id),
    startAutoFetchRunTimer:()=>{},stopAutoFetchRunTimer:()=>{},
    schedulePlatformProfileStatusRefresh:()=>{},scheduleOnlineDataRefresh:()=>{},scheduleOnlineHistoryRefresh:()=>{},
    captureAuthSession:()=>({epoch:state.epoch}),isAuthSessionCurrent:s=>s.epoch===state.epoch,getAutoFetchHotelId:()=>state.hotel,
    console:{error:()=>errors.push('read_failed')},
    request:url=>new Promise((resolve,reject)=>requests.push({url,resolve,reject})),
  };
  for(const name of ['autoFetchEnabled','autoFetchScheduleTime','autoFetchScheduleMinute','autoFetchRealtimeIntervalHours','autoFetchBrowserHeadless','autoFetchCtripSectionConcurrency','autoFetchMode','autoFetchBackfillDate','autoFetchMaxBackfillDate'])context[name]=ref(null);
  vm.createContext(context);
  vm.runInContext(extract('const stopAutoFetchProgressMonitor =','const autoFetchMaxBackfillDate')+'\n'+extract('const autoFetchStatusRequestPromises = new Map();','const platformProfileStatusRequestPromises = new Map();')+'\nthis.api={pollAutoFetchProgress,stopAutoFetchProgressMonitor,loadAutoFetchStatus};',context);
  return {
    context,state,requests,timers,errors,...context.api,
    next:async()=>{const [id,timer]=timers.entries().next().value;timers.delete(id);state.now+=timer.delay;return timer.fn();},
    delay:()=>[...timers.values()][0]?.delay,
    switchHotel:hotel=>{state.hotel=hotel;context.api.stopAutoFetchProgressMonitor();context.autoFetchRunState.value={active:false,type:'scope_changed'};context.autoFetchStatus.value={};},
  };
}
const rejectHttp = (request, status, retryAfter) => {
  const error = new Error('synthetic failure');error.status=status;error.data={code:status,data:{retry_after:retryAfter}};request.reject(error);
};

test('429 waits for Retry-After, exposes unknown task status and recovers from a fresh terminal receipt', async()=>{
  const h=harness(), pending=h.pollAutoFetchProgress();
  rejectHttp(h.requests[0],429,600);await pending;
  assert.equal(h.delay(),600000);
  assert.equal(h.context.autoFetchRunState.value.type,'status_unavailable');
  assert.equal(h.context.autoFetchRunState.value.active,false);
  assert.equal(h.context.autoFetchRunState.value.finished_at,'');
  assert.equal(h.context.fetchingData.value,false);
  const recovered=h.next();h.requests[1].resolve(response('success'));await recovered;
  assert.equal(h.context.autoFetchRunState.value.type,'success');assert.equal(h.timers.size,0);
});
test('network failures back off and a fresh running response restores normal progress',async()=>{
  const h=harness();let pending=h.pollAutoFetchProgress();h.requests[0].reject(new Error('synthetic network'));await pending;
  const first=h.delay();pending=h.next();h.requests[1].reject(new Error('synthetic network'));await pending;
  assert.ok(h.delay()>first);
  pending=h.next();h.requests[2].resolve(response('running'));await pending;
  assert.equal(h.context.autoFetchRunState.value.type,'running');assert.equal(h.delay(),2000);
});
test('permission denial does not keep retrying without user action',async()=>{
  const h=harness(), pending=h.pollAutoFetchProgress();rejectHttp(h.requests[0],403);await pending;
  assert.equal(h.context.autoFetchRunState.value.type,'status_unavailable');assert.equal(h.timers.size,0);
  assert.match(h.context.autoFetchRunState.value.message,/权限/);
});
test('late errors from an old hotel cannot clear a newer poll or replace its status',async()=>{
  const h=harness(), old=h.pollAutoFetchProgress();h.switchHotel('8');const fresh=h.pollAutoFetchProgress();
  rejectHttp(h.requests[0],429,600);await old;
  assert.equal(h.context.autoFetchProgressRequestRunning,true);assert.equal(h.timers.size,0);assert.equal(h.errors.length,0);
  h.requests[1].resolve(response('running'));await fresh;
  assert.equal(h.context.autoFetchRunState.value.type,'running');assert.equal(h.delay(),2000);
});
test('stopping a monitor fences its pending response and prevents resurrection',async()=>{
  const h=harness(), pending=h.pollAutoFetchProgress();h.stopAutoFetchProgressMonitor();
  h.requests[0].resolve(response('running'));await pending;
  assert.equal(h.timers.size,0);assert.equal(h.context.autoFetchProgressRequestRunning,false);
});
test('body-level failures and HTTP-date Retry-After are handled without stale completion',async()=>{
  const h=harness();let pending=h.pollAutoFetchProgress();h.requests[0].resolve({code:429,data:{retry_after:60}});await pending;
  assert.equal(h.delay(),60000);pending=h.next();
  const error=new Error('synthetic');error.status=429;error.retryAfter=new Date(h.state.now+120000).toUTCString();h.requests[1].reject(error);await pending;
  assert.equal(h.delay(),120000);assert.equal(h.context.autoFetchRunState.value.finished_at,'');
});

test('manual refresh clears a stopped unknown state only after a terminal receipt',async()=>{
  const h=harness();h.stopAutoFetchProgressMonitor();
  h.context.autoFetchRunState.value={active:false,type:'status_unavailable',message:'无法确认',finished_at:''};
  let pending=h.loadAutoFetchStatus({detail:false,force:true});
  h.requests[0].resolve({code:200,data:{}});await pending;
  assert.equal(h.context.autoFetchRunState.value.type,'status_unavailable');
  assert.equal(h.context.autoFetchRunState.value.finished_at,'');
  pending=h.loadAutoFetchStatus({detail:false,force:true});h.requests[1].resolve(response('success'));await pending;
  assert.equal(h.context.autoFetchRunState.value.type,'success');
  assert.equal(h.timers.size,0);
});
