import assert from 'node:assert/strict';import {test} from 'node:test';import fs from 'node:fs';import vm from 'node:vm';
const source=fs.readFileSync(process.env.SUXI_QUESTION_ACTION_SOURCE||'public/app-main.js','utf8');
const slice=(start,end)=>source.slice(source.indexOf(start),source.indexOf(end,source.indexOf(start)));
const handler=slice('const createOperatingQuestionActionIntent =','const openOperatingQuestionActionIntent =');
const eligibility=slice('const operatingQuestionActionIsCurrent =','const otaDiagnosisLoading =');
const scope={tenant_id:7,hotel_id:20,platform:'ctrip',date_start:'2026-09-01',date_end:'2026-09-02'};
const action={contract_version:'operating_question_action_draft.v2',status:'ready_for_human_review',can_create_execution_intent:true,action_digest:'b'.repeat(64),
 decision_quality:{contract_version:'ai_recommendation_quality.v2',complete:true,execution_ready:true},scope:{...scope,source_scope:'ota_channel'},
 boundaries:{human_confirmation_required:true,automatic_collection:false,automatic_execution:false,ota_write:false,external_message:false}};
const question=(id=41)=>({...scope,id,content_digest:'a'.repeat(64),answer_status:'answered_by_grounded_ai',action_intent_readback:{data_status:'ok',list:[]},answer:{status:'answered_by_grounded_ai',confidence:'medium',decision_frame:{requested_object:''},action_drafts:[action],ai_runtime:{status:'ready',provider:'deepseek',finish_reason:'stop',external_llm_called:true,external_llm_call_status:'confirmed_direct_deepseek_v4_pro',prompt_version:'operating_question_grounded_ai.zh-CN.v4'}}});
const fresh=()=>({result:question(),loading:false,action_loading:'',action_error:'',action_intents:{}});
const intent=(status='pending_approval')=>({...scope,id:901,source_module:'operating_question',source_record_id:41,object_type:'operation_checklist',action_type:'ai_reviewed_operating_check',status,tasks:status==='approved'?[{id:101}]:[],target_value:{action_card:{contract_version:'operation_action_card.v1',content_digest:'c'.repeat(64)}},evidence:{question_content_digest:'a'.repeat(64),action_index:0,action_draft_digest:action.action_digest}});
const saved=(value=intent(),reused=false)=>({code:200,data:{execution_intent:value,reused_existing_intent:reused}});
const deferred=()=>{let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b;});return{promise,resolve,reject};};
function harness(responder=()=>null){
 let epoch=0;const calls=[],toasts=[];
 const context={operatingQuestionState:{value:fresh()},operatingQuestionForm:{value:{...scope,decision_object:''}},captureAuthSession:()=>({epoch}),isAuthSessionCurrent:s=>s.epoch===epoch,showToast:(...args)=>toasts.push(args),request:async(url,options)=>{calls.push({url,method:options?.method||'GET'});return await responder(url,options)|| (options?.method==='POST'?saved():{code:200,data:intent()});}};
 const create=vm.runInNewContext(`(()=>{${eligibility}${handler};return createOperatingQuestionActionIntent;})()`,context);
 return{context,create,calls,toasts,get state(){return context.operatingQuestionState.value;},get form(){return context.operatingQuestionForm.value;},change(mode){if(mode==='auth')epoch++;if(mode==='auth'||mode==='state')context.operatingQuestionState.value=fresh();else if(mode==='question')context.operatingQuestionState.value.result=question(42);else if(mode==='reload')context.operatingQuestionState.value.result=question();}};
}
const until=async predicate=>{for(let i=0;i<25&&!predicate();i++)await Promise.resolve();assert.ok(predicate());};
for(const stage of ['save','readback'])for(const mode of ['auth','state','question','reload'])for(const outcome of ['success','failure'])test(`old action ${stage} ${outcome} is ignored after ${mode} change`,async()=>{
 const gate=deferred();const h=harness((url,options)=>(stage==='save'?options?.method==='POST':!options)?gate.promise:null);
 const pending=h.create(action,0);const count=stage==='save'?1:2;await until(()=>h.calls.length===count);
 h.change(mode);h.state.action_error='当前问题状态';h.state.action_intents={retained:{id:999}};
 if(outcome==='failure')gate.reject(new Error('旧问题保存失败'));else gate.resolve(stage==='save'?saved():{code:200,data:intent()});
 assert.equal(await pending,null);assert.equal(h.calls.length,count);assert.equal(h.toasts.length,0);
 assert.equal(h.state.action_error,'当前问题状态');assert.equal(h.state.action_intents.retained.id,999);assert.equal(Object.keys(h.state.action_intents).length,1);
 assert.equal(h.state.action_loading,'','old request ends without locking the current question');
});
for(const status of ['pending_approval','approved','cancelled','rejected'])test(`current ${status} receipt retains its exact approval/task state`,async()=>{
 const value=intent(status);const h=harness((url,options)=>options?.method==='POST'?saved(value,true):{code:200,data:value});
 assert.equal((await h.create(action,0)).id,901);assert.equal(h.state.action_intents['41:0'].status,status);assert.equal(h.state.action_loading,'');
 assert.equal(h.toasts.length,1);assert.equal(h.calls.length,2);assert.equal(h.calls[0].url,'/agent/operating-questions/41/action-drafts/0/execution-intent');
 assert.equal(h.calls[1].url,'/operation/execution-intents/901');assert.equal(h.state.action_intents['41:0'].tasks.length,status==='approved'?1:0);
});
test('new intent stays pending approval without tasks, and a failed save can retry',async()=>{
 let fail=true;const h=harness((url,options)=>fail&&options?.method==='POST'?{code:500,message:'合成保存失败'}:null);
 assert.equal(await h.create(action,0),null);assert.match(h.state.action_error,/保存失败/);assert.equal(h.state.action_loading,'');
 fail=false;const result=await h.create(action,0);assert.equal(result.status,'pending_approval');assert.equal(result.tasks.length,0);assert.equal(h.state.action_error,'');assert.match(h.toasts[0][0],/待人工审批/);
});
for(const [name,changed,reused] of [
 ['hotel',{hotel_id:21},true],['date',{date_start:'2026-08-01'},true],['platform',{platform:'meituan'},true],['id',{id:902},true],
 ['source',{source_record_id:42},true],['digest',{evidence:{question_content_digest:'d'.repeat(64)}},true],
 ['new approved',{status:'approved',tasks:[{id:101}]},false],['approved task count',{status:'approved',tasks:[]},true],
 ['card',{target_value:{action_card:{}}},true],
])test(`current invalid ${name} receipt is rejected without publishing an action`,async()=>{
 const h=harness((url,options)=>options?.method==='POST'?saved(intent(),reused):{code:200,data:{...intent(),...changed}});
 assert.equal(await h.create(action,0),null);assert.notEqual(h.state.action_error,'');assert.equal(h.state.action_loading,'');assert.equal(h.toasts.length,0);assert.equal(Object.keys(h.state.action_intents).length,0);
});
test('ineligible facts or duplicate click cannot create an intent',async()=>{
 const gate=deferred();const h=harness(()=>gate.promise);h.state.result.answer.ai_runtime.external_llm_called=false;
 assert.equal(await h.create(action,0),null);assert.equal(h.calls.length,0);
 h.state.result=question();const pending=h.create(action,0);assert.equal(await h.create(action,0),null);assert.equal(h.calls.length,1);
 gate.resolve({code:500,message:'合成失败'});await pending;assert.equal(h.state.action_loading,'');
});
test('same-scope equivalent revenue cockpit intent keeps its existing cross-entry compatibility',async()=>{
 const value={...intent(),source_module:'revenue_cockpit_action',source_record_id:73,evidence:{}};
 const h=harness((url,options)=>options?.method==='POST'?saved(value,true):{code:200,data:value});
 assert.equal((await h.create(action,0)).id,901);assert.equal(h.state.action_intents['41:0'].source_module,'revenue_cockpit_action');
 assert.equal(h.state.action_loading,'');assert.equal(h.state.action_error,'');
});
for(const [field,value] of [['hotel_id',21],['platform','meituan'],['date_start','2026-08-01'],['decision_object','price']])test(`changed ${field} invalidates an in-flight action before the next request`,async()=>{
 const gate=deferred();const h=harness(()=>gate.promise);const pending=h.create(action,0);
 h.form[field]=value;h.state.action_error='新范围';gate.resolve(saved());
 assert.equal(await pending,null);assert.equal(h.calls.length,1);assert.equal(h.toasts.length,0);assert.equal(h.state.action_error,'新范围');assert.equal(h.state.action_loading,'');
});
test('current failed exact GET can retry without duplicating an already saved intent',async()=>{
 let fail=true;const h=harness((url,options)=>options?.method==='POST'?saved(intent(),true):fail?{code:500,message:'合成精确回读失败'}:null);
 assert.equal(await h.create(action,0),null);assert.match(h.state.action_error,/回读失败/);assert.equal(h.state.action_loading,'');
 fail=false;assert.equal((await h.create(action,0)).id,901);assert.equal(h.state.action_error,'');assert.equal(Object.keys(h.state.action_intents).length,1);
});

const producer=fs.readFileSync('app/service/OperatingQuestionAiAnswerService.php','utf8');
const currentCallStatus=producer.match(/public const DIRECT_CALL_STATUS = '([^']+)'/)[1];
test('the current question producer receipt enables saving and exact readback',async()=>{
 const h=harness();Object.assign(h.state.result.answer.ai_runtime,{external_llm_call_status:currentCallStatus,direct_request_proof:true});
 assert.equal((await h.create(action,0))?.id,901);assert.equal(h.calls.length,2);assert.equal(h.state.action_intents['41:0'].status,'pending_approval');
});
for(const [name,change] of [
 ['old incomplete confirmation',{external_llm_call_status:'confirmed_success'}],['unknown status',{external_llm_call_status:'confirmed_unverified'}],['not called',{external_llm_called:false}],
 ['provider',{provider:'local'}],['truncated',{finish_reason:'length'}],['fallback',{fallback_used:true}],['cache',{cache_hit:true}],['degraded',{degraded:true}],
])test(`current producer ${name} cannot bypass the existing action gate`,async()=>{
 const h=harness();Object.assign(h.state.result.answer.ai_runtime,{external_llm_call_status:currentCallStatus,direct_request_proof:true},change);
 assert.equal(await h.create(action,0),null);assert.equal(h.calls.length,0);assert.notEqual(h.state.action_error,'');
});
for(const field of ['confidence','status'])test(`malformed array ${field} is not a valid answer enum`,async()=>{
 const h=harness();h.state.result.answer[field]=[h.state.result.answer[field]];
 assert.equal(await h.create(action,0),null);assert.equal(h.calls.length,0);
});
test('a malformed action contract cannot pass through string coercion',async()=>{
 const h=harness();const draft={...action,contract_version:[action.contract_version]};
 assert.equal(await h.create(draft,0),null);assert.equal(h.calls.length,0);
});
