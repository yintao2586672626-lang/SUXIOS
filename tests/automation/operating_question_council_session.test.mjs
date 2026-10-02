import assert from 'node:assert/strict';
import { test } from 'node:test';
import fs from 'node:fs';
import vm from 'node:vm';
const main = fs.readFileSync(process.env.SUXI_COUNCIL_SESSION_SOURCE || 'public/app-main.js','utf8');
const loader = fs.readFileSync('public/components/system/operating-intelligence-loader.js','utf8');
const slice = (start,end) => main.slice(main.indexOf(start),main.indexOf(end,main.indexOf(start)));
const run = slice('const runOperatingQuestionCouncil =','const invalidateOperatingQuestionHistory =');
const matcher = slice('const operatingQuestionCouncilReadbackMatches =','const loadLatestOperatingQuestionCouncil =');
const deferred = () => { let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b;});return{promise,resolve,reject}; };
const fresh = () => ({result:{id:41,hotel_id:20},council_generation:0,council_loading:false,council_error:'',council_run:null});
const key = 'council:council:'+'d'.repeat(16);
function exact(status='pending',overrides={}) {
  return {id:7,question_id:41,hotel_id:20,request_key:key,content_digest:(status==='pending'?'a':'b').repeat(64),status,decision_effect:'none',
    boundaries:{action_creation_allowed:false,user_trigger_required:true,external_message:false,automatic_execution:false,ota_write:false,primary_answer_mutated:false,real_human_consensus:false,source_skills_installed:false},
    synthesis:{advisory_source:{source_entry_count:165,outer_zip_sha256:'32c06de45983119efd6f7cfa9b1e8ca5ce59f8a4e5339267dc383a5fc0ee3970'},selected_lenses:[{key:'evidence'},{key:'risk'}]},...overrides};
}
const receipt = () => ({...exact('blocked_not_configured'),accepted:true,persistence_status:'readback_verified',worker_dispatched:false,worker_receipt:{status:'terminal_observed',acknowledged:false,persisted:true}});
function harness(responder=()=>null, initial=fresh()) {
  let epoch=0, now=0; const calls=[], timers=[];
  const window={setTimeout:callback=>{timers.push(callback);return timers.length;}};
  const context={window,URL,Date:{now:()=>now},crypto:{randomUUID:()=> 'd'.repeat(32)},operatingQuestionState:{value:initial},captureAuthSession:()=>({epoch}),isAuthSessionCurrent:session=>session.epoch===epoch,
    request:async(url,options={})=>{calls.push({url,method:options.method||'GET'});return await responder(url,options,calls.length)||{code:200,data:options.method==='POST'?receipt():exact('blocked_not_configured')};}};
  vm.runInNewContext(loader,context);
  const start=vm.runInNewContext(`(()=>{${matcher}${run};return runOperatingQuestionCouncil;})()`,context);
  return {start,calls,timers,context,get state(){return context.operatingQuestionState.value;},reset(mode='both'){if(mode!=='state')epoch++;if(mode!=='epoch')context.operatingQuestionState.value=fresh();},tick(ms=1000){now+=ms;const callback=timers.shift();assert.ok(callback,'poll was waiting');callback();}};
}
const until=async predicate=>{for(let i=0;i<60&&!predicate();i++)await Promise.resolve();assert.ok(predicate(),'expected boundary reached');};
for(const mode of ['both','state','epoch']) {
  for(const stage of ['submit','exact-read','poll-delay','poll-read','stale-resume']) {
    test(`council ${stage} stops on ${mode} session replacement`,async()=>{
      const gate=deferred();let getCount=0,postCount=0,replaced=false;
      const h=harness((url,options)=>{
        if(options.method==='POST') {postCount++;if(stage==='submit'&&postCount===1||stage==='stale-resume'&&postCount===2)return gate.promise;return null;}
        getCount++;if(stage==='exact-read'&&getCount===1||stage==='poll-read'&&getCount===2)return gate.promise;
        return {code:200,data:exact(replaced?'blocked_not_configured':'pending')};
      });
      const pending=h.start();
      if(stage==='submit')await until(()=>h.calls.length===1);
      else if(stage==='exact-read')await until(()=>h.calls.length===2);
      else {await until(()=>h.timers.length===1);if(stage==='poll-read'||stage==='stale-resume'){h.tick(stage==='stale-resume'?150001:1000);await until(()=>stage==='poll-read'?getCount===2:postCount===2);}}
      replaced=true;h.reset(mode);const current=h.state;current.council_error='当前会诊状态';current.council_run=exact('blocked_not_configured',{id:99});
      const before=h.calls.length;
      if(stage==='poll-delay')h.tick();else gate.resolve({code:200,data:stage==='submit'||stage==='stale-resume'?receipt():exact('blocked_not_configured')});
      let settled=false;pending.then(()=>{settled=true;});
      for(let i=0;i<4&&!settled;i++){for(let j=0;j<30;j++)await Promise.resolve();if(h.timers.length)h.tick();}
      assert.equal(settled,true,'both baseline and current reach a bounded terminal');
      assert.equal(await pending,null);
      assert.equal(h.calls.length,before,'no request after account/state replacement');
      assert.equal(h.timers.length,0,'no stale polling scheduled');
      assert.equal(current.council_run.id,99);assert.equal(current.council_error,'当前会诊状态');
    });
  }
}
test('current reserved terminal run completes exact readback and releases busy',async()=>{
  const h=harness();const result=await h.start();
  assert.equal(result.id,7);assert.equal(result.status,'blocked_not_configured');assert.equal(h.state.council_run,result);
  assert.equal(h.state.council_loading,false);assert.equal(h.state.council_error,'');assert.equal(h.calls.length,2);
});
test('current active run is read and polled without a new POST',async()=>{
  let reads=0;const state=fresh();state.council_run=exact('running');
  const h=harness(()=>({code:200,data:exact(++reads===1?'running':'blocked_not_configured')}),state);
  const pending=h.start();await until(()=>h.timers.length===1);h.tick();assert.equal((await pending).id,7);
  assert.equal(h.calls.length,2);assert.ok(h.calls.every(call=>call.method==='GET'));assert.equal(h.state.council_loading,false);
});
for(const status of ['partial','failed','blocked_by_missing_facts','blocked_not_configured'])test(`current ${status} retries through the same run resume`,async()=>{
  const state=fresh();state.council_run=exact(status);const h=harness(()=>null,state);
  assert.equal((await h.start()).id,7);assert.equal(h.calls[0].url,'/agent/operating-questions/41/council-runs/7/resume');assert.equal(h.state.council_loading,false);
});
test('current stale checkpoint recovery keeps the reserved identity and no duplicate run',async()=>{
  let reads=0;const h=harness((url,options)=>options.method==='POST'?null:{code:200,data:exact(++reads<3?'pending':'blocked_not_configured')});
  const pending=h.start();await until(()=>h.timers.length===1);h.tick(150001);await until(()=>h.timers.length===1);h.tick();
  assert.equal((await pending).id,7);assert.equal(h.calls.filter(call=>call.method==='POST').length,2);assert.equal(h.state.council_loading,false);
});
for(const stage of ['submit','exact-read','poll-read'])test(`current ${stage} failure is visible and can retry`,async()=>{
  let fail=true,reads=0;const h=harness((url,options)=>{
    if(options.method==='POST')return stage==='submit'&&fail?{code:500,message:'合成提交失败'}:null;
    reads++;if(fail&&(stage==='exact-read'||stage==='poll-read'&&reads===2))throw new Error('合成回读失败');
    return {code:200,data:exact(fail&&stage==='poll-read'?'pending':'blocked_not_configured')};
  });
  const pending=h.start();if(stage==='poll-read'){await until(()=>h.timers.length===1);h.tick();}
  assert.equal(await pending,null);assert.match(h.state.council_error,/失败/);assert.equal(h.state.council_loading,false);
  fail=false;assert.equal((await h.start()).id,7);assert.equal(h.state.council_error,'');
});
test('invalid worker receipt or exact identity never starts polling',async()=>{
  for(const invalid of ['receipt','hotel','id','key']) {
    const h=harness((url,options)=>options.method==='POST'?{code:200,data:invalid==='receipt'?{...receipt(),worker_receipt:{}}:receipt()}:{code:200,data:exact('pending',invalid==='hotel'?{hotel_id:21}:invalid==='id'?{id:8}:invalid==='key'?{request_key:'other'}:{})});
    assert.equal(await h.start(),null);assert.notEqual(h.state.council_error,'');assert.equal(h.timers.length,0);assert.equal(h.state.council_loading,false);
  }
});
test('changed upstream facts reject resume without posting and duplicate clicks do not submit twice',async()=>{
  const state=fresh();state.council_run=exact('failed',{synthesis:{error_code:'council_terminal_fact_drift'}});const blocked=harness(()=>null,state);
  assert.equal(await blocked.start(),null);assert.equal(blocked.calls.length,0);assert.match(blocked.state.council_error,/事实已变化/);
  const gate=deferred();const h=harness(()=>gate.promise);const first=h.start();assert.equal(await h.start(),null);assert.equal(h.calls.length,1);
  gate.resolve({code:500,message:'合成失败'});await first;assert.equal(h.state.council_loading,false);
});
for(const replacement of ['auth','generation'])test(`old ${replacement} council cannot release a newer run busy flag`,async()=>{
  const old=deferred(),current=deferred();let posts=0;
  const h=harness((url,options)=>options.method==='POST'?(++posts===1?old.promise:current.promise):null);
  const first=h.start();
  if(replacement==='auth')h.reset();else{h.state.council_generation++;h.state.council_loading=false;}
  const second=h.start();old.resolve({code:200,data:receipt()});
  assert.equal(await first,null);assert.equal(h.state.council_loading,true);assert.equal(h.calls.length,2);
  current.resolve({code:200,data:receipt()});assert.equal((await second).id,7);assert.equal(h.state.council_loading,false);
});
