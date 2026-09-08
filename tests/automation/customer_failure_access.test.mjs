import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFileSync} from 'node:fs';
const ctx = {window:{},URLSearchParams,console};
vm.createContext(ctx);
vm.runInContext(readFileSync('public/system-static.js','utf8'),ctx);
const helpers = ctx.window.SUXI_SYSTEM_STATIC;
test('rate-limited reads wait across hotel queries but never reuse a previous login cooldown',()=>{
  let now=1000; const cooldown=helpers.createReadRequestCooldown(()=>now);
  cooldown.record(1,'GET','/agent/revenue-metrics?hotel_id=7',{status:429,data:{data:{retry_after:300}}});
  assert.equal(cooldown.check(1,'GET','/agent/revenue-metrics?hotel_id=8').data.data.retry_after,300);
  assert.equal(cooldown.check(1,'GET','/agent/ota-diagnosis'),null);
  assert.equal(cooldown.check(1,'POST','/agent/revenue-metrics'),null);
  now+=300000; assert.equal(cooldown.check(1,'GET','/agent/revenue-metrics'),null);
  cooldown.record(1,'GET','/agent/revenue-metrics',{status:429,retryAfter:'600'});
  assert.equal(cooldown.check(2,'GET','/agent/revenue-metrics'),null);
});
const manifest = [
  {key:'knowledge',module:'',allowed:true,paths:[{path:'api/knowledge/list',methods:['GET']}]},
  {key:'ai',module:'ai_decision',allowed:false,reason:'module_not_entitled',paths:[{path:'api/agent/*',methods:[]}]},
  {key:'operation',module:'operation_decision',allowed:false,reason:'module_not_entitled',paths:[{path:'api/operation',methods:[]}]},
];
test('ordinary user sees only entitled navigation, admin and legacy snapshots retain existing behavior',()=>{
  const user={modules:{ai:false,operation:false,online_data:true},permissions:{all:true},protected_access:manifest};
  const items=[{path:'compass'},{path:'operation-optimizer'},{path:'ai-daily-report'},{path:'online-data'}];
  assert.deepEqual(Array.from(helpers.filterVisibleMenuItems(items,user),x=>x.path),['compass','online-data']);
  assert.equal(helpers.isPageModuleAvailable('operation-optimizer',user),false);
  assert.equal(helpers.filterVisibleMenuItems(items,{...user,is_super_admin:true}).length,4);
  assert.equal(helpers.filterVisibleMenuItems(items,{permissions:{all:true}}).length,4);
});
test('request availability matches API prefix, query, methods and ordered wildcard contracts',()=>{
  const user={protected_access:manifest};
  for (const url of ['/agent/revenue-metrics?hotel_id=7','/api/agent/ota-diagnosis','/operation/execution-flow']) {
    const error=helpers.protectedRequestDenial(url,'GET',user);
    assert.equal(error.status,403);
    assert.equal(error.data.data.redacted_reason,'module_not_entitled');
    assert.match(error.message,/尚未开通/);
  }
  for(const url of ['/knowledge/list?keyword=a','/operation-other','/online-data/auto-fetch-status']) assert.equal(helpers.protectedRequestDenial(url,'GET',user),null);
  assert.equal(helpers.protectedRequestDenial('/agent/x','GET',{...user,is_super_admin:true}),null);
  const ordered={protected_access:[{allowed:true,paths:[{path:'api/agent/one',methods:['GET']}]},...manifest]};
  assert.equal(helpers.protectedRequestDenial('/agent/one','GET',ordered),null);
  assert.equal(helpers.protectedRequestDenial('/agent/one','POST',ordered).status,403);
});
const app = readFileSync('public/app-main.js','utf8');
test('overview profile probe uses the bound source and refuses missing source before a request',async()=>{
  let sourceId=42;const calls=[];
  const context={URLSearchParams,otaConfigOverviewAccountResolver:(_config,_platform,hotel)=>({data_source_id:hotel==='7'?sourceId:0}),request:async url=>{calls.push(url);return{code:200,data:{status_code:'logged_in'}};}};
  vm.createContext(context);
  const start=app.indexOf('const probeOtaConfigOverviewProfile ='),end=app.indexOf('const probeOtaConfigOverviewRow =',start);
  vm.runInContext(app.slice(start,end)+'this.probe=probeOtaConfigOverviewProfile;',context);
  assert.equal((await context.probe({platform:'ctrip',hotelId:'7'})).ok,true);
  const params=new URLSearchParams(calls[0].split('?')[1]);
  assert.equal(params.get('data_source_id'),'42');assert.equal(params.get('system_hotel_id'),'7');
  sourceId=0;
  assert.equal((await context.probe({platform:'ctrip',hotelId:'7'})).statusCode,'missing_data_source');
  assert.equal((await context.probe({platform:'ctrip',hotelId:'8'})).statusCode,'missing_data_source');
  assert.equal(calls.length,1);
});
