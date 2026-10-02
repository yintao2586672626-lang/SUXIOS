import assert from 'node:assert/strict';import test from 'node:test';import fs from 'node:fs';import vm from 'node:vm';
const source=fs.readFileSync(process.env.WECOM_COMPONENT_SOURCE||'public/components/system/operating-intelligence-components.js','utf8');
const main=fs.readFileSync('public/app-main.js','utf8'),updateStart=main.indexOf('const updateOperatingQuestionScope ='),updateSource=main.slice(updateStart,main.indexOf('\n            watch(',updateStart));
const system=fs.readFileSync('public/system-static.js','utf8'),stateSource=system.slice(system.indexOf('const createOperatingQuestionState ='),system.indexOf('const operatingQuestionScopeCooldown =')),makeState=new Function(stateSource+';return createOperatingQuestionState;')();
const clone=x=>JSON.parse(JSON.stringify(x)),tick=async()=>{for(let i=0;i<24;i++)await Promise.resolve();};
const gate=()=>{let resolve,reject;const promise=new Promise((yes,no)=>{resolve=yes;reject=no;});return{promise,resolve,reject};};
const ok=data=>({code:200,data}),binding=(hotel=7,id=hotel*10)=>({id,hotel_id:hotel,tenant_id:10,transport:'wecom_aibot_websocket',status:'verified',reply_enabled:false,label:`合成酒店${hotel}绑定`}),capability={aibot_websocket:{status:'ready'}};
const code=hotel=>ok({hotel_id:hotel,persistence_status:'readback_verified',single_use:true,binding_code:'TESTCODE',instruction:'synthetic-only'});
const receipt=(action,hotel=7,id=hotel*10)=>ok(action==='reply'?{id,hotel_id:hotel,reply_enabled:true,persistence_status:'readback_verified',automatic_execution:false,ota_write:false}:{id,hotel_id:hotel,status:'disabled',reply_enabled:false,conversation_reference_released:true,historical_events_retained:true,persistence_status:'readback_verified'});
const text=n=>typeof n==='string'?n:!n||typeof n!=='object'?'':(Array.isArray(n.children)?n.children:[n.children]).map(text).join('');
const find=(n,p)=>!n||typeof n!=='object'?null:p(n)?n:(Array.isArray(n.children)?n.children:[n.children]).map(c=>find(c,p)).find(Boolean)||null;
function harness(handler=()=>undefined,{reactive=false}={}){
 let vue=null;if(reactive){const runtime={console,setTimeout,clearTimeout};vm.runInNewContext(fs.readFileSync('public/vue.runtime.global.prod.js','utf8'),runtime);vue=runtime.Vue;}
 const makeRef=vue?.ref||(value=>({value}));
 const state=makeRef(makeState()),form=makeRef({hotel_id:'7',platform:'ctrip',date_start:'2026-08-12',date_end:'2026-08-12'}),epoch=makeRef(1),calls=[],mounted=[],confirmations=[];
 const scope={operatingQuestionScopeRequestId:0,operatingQuestionPanelIsActive:()=>true,operatingQuestionState:state,operatingQuestionForm:form,reportHotelOptionExists:()=>true,invalidateOperatingQuestionHistory:()=>{},operatingQuestionHistoryOpenRequestId:0,operatingQuestionScopeCooldown:()=>{},loadOperatingQuestionScopeOptions:async()=>{},loadOperatingQuestionHistory:async()=>{}};
 vm.createContext(scope);vm.runInContext(updateSource+';globalThis.updateScope=updateOperatingQuestionScope;',scope);
 const ui={state,form,user:{value:{id:17}},sessionEpoch:()=>epoch.value,ensureScope:()=>Number(form.value.hotel_id),hotels:{value:[{id:7,name:'甲店'},{id:8,name:'乙店'}]},updateScope:scope.updateScope,request:async(url,options={})=>{
  if(!url.startsWith('/agent/wecom-inbound/'))return{code:503,message:'isolated auxiliary service'};
  const call={url,method:options.method||'GET',hotel:Number(form.value.hotel_id),epoch:epoch.value,body:options.body?JSON.parse(options.body):null};calls.push(call);
  const custom=handler(call,calls.length);if(custom!==undefined)return custom;
  if(url.endsWith('/capabilities'))return ok(capability);
  if(url.endsWith('/bindings'))return ok({list:[binding(7),binding(8)]});
  if(url.includes('/events?'))return ok({list:[{id:call.hotel*100,hotel_id:call.hotel,processing_status:'processed',delivery_status:'not_sent'}]});
  if(url.endsWith('/aibot-binding-codes'))return code(call.hotel);
  return receipt(url.endsWith('/reply-setting')?'reply':'disable',call.hotel,Number(url.match(/bindings\/(\d+)/)?.[1]));
 }};
 const deps={ref:makeRef,computed:vue?.computed||(fn=>({get value(){return fn();}})),inject:()=>ui,h:(type,props,children)=>({type,props,children}),nextTick:async()=>{},onMounted:fn=>mounted.push(fn),onUnmounted:()=>{}};
 const sandbox={window:{confirm:message=>{confirmations.push(message);return true;}},console};
 vm.runInNewContext(fs.readFileSync('public/components/system/hotel-data-analyst-components.js','utf8'),sandbox);vm.runInNewContext(source,sandbox);
 const render=sandbox.window.SUXI_OPERATING_INTELLIGENCE_COMPONENTS_FULL.create(deps).operatingQuestionPanel.setup();
 const node=id=>find(render(),n=>n.props?.['data-testid']===id),button=label=>find(node('wecom-aibot-workbench'),n=>n.type==='button'&&text(n)===label);
 return{state,form,epoch,calls,confirmations,node,button,render,vue,mount(){mounted.forEach(fn=>fn());},switchHotel(hotel){node('operating-question-hotel').props.onChange({target:{value:String(hotel)}});},refresh(){return (button('刷新')||button('读取中…')||button('处理中…')).props.onClick();},create(){return node('wecom-aibot-binding-code').props.onClick();},act(action){return button(action==='reply'?'主动开启回复':'停用解绑').props.onClick();}};
}
for(const outcome of ['success','failure'])test(`hotel switch starts current read while old read is pending and ignores old ${outcome}`,async()=>{
 const old=gate();let first=true;const h=harness(call=>{if(first&&call.url.includes('/events?')){first=false;return old.promise;}});
 h.mount();h.switchHotel(8);await tick();assert.equal(h.state.value.wecom_bindings[0]?.hotel_id,8);
 if(outcome==='success')old.resolve(ok({list:[{id:700,hotel_id:7}]}));else old.reject(new Error('old failure'));await tick();
 assert.equal(h.state.value.wecom_bindings[0]?.hotel_id,8);assert.equal(h.state.value.wecom_error,'');
});
test('A to B to A keeps the later A readback and its current loading owner',async()=>{
 const old=gate(),latest=gate();let event=0;const h=harness(call=>{if(call.url.includes('/events?')){event++;if(event===1)return old.promise;if(event===3)return latest.promise;}});
 h.mount();h.switchHotel(8);await tick();h.switchHotel(7);await tick();old.resolve(ok({list:[{id:100,hotel_id:7}]}));await tick();
 assert.equal(h.state.value.wecom_loading,true);assert.deepEqual(clone(h.state.value.wecom_events),[]);
 latest.resolve(ok({list:[{id:300,hotel_id:7}]}));await tick();assert.equal(h.state.value.wecom_events[0]?.id,300);assert.equal(h.state.value.wecom_loading,false);
});
for(const mutation of ['state','epoch'])test(`old GET does not populate state after ${mutation} reset`,async()=>{
 const held=gate();const h=harness(call=>call.url.includes('/events?')?held.promise:undefined);h.mount();const captured=h.state.value;
 if(mutation==='state')h.state.value=makeState();else h.epoch.value++;
 held.resolve(ok({list:[{id:700,hotel_id:7}]}));await tick();assert.equal(captured.wecom_capabilities,null);assert.deepEqual(clone(captured.wecom_bindings),[]);
});
for(const mutation of ['hotel','state','epoch'])for(const outcome of ['success','failure'])test(`binding code ${outcome} stays with original ${mutation} owner`,async()=>{
 const held=gate();const h=harness(call=>call.method==='POST'?held.promise:undefined);h.state.value.wecom_capabilities=capability;const pending=h.create();await tick();
 if(mutation==='hotel'){h.switchHotel(8);await tick();}else if(mutation==='state'){h.state.value=makeState();h.state.value.wecom_loading=true;}else h.epoch.value++;
 if(outcome==='success')held.resolve(code(7));else held.reject(new Error('old code error'));
 assert.equal(await pending,null);assert.equal(h.state.value.wecom_binding_code,null);assert.equal(h.state.value.wecom_error,'');if(mutation==='state')assert.equal(h.state.value.wecom_loading,true);
});
for(const action of ['reply','disable']){
 test(`stale ${action} handler cannot submit for another current hotel`,async()=>{
  const h=harness();h.state.value.wecom_bindings=[binding(7)];const click=h.button(action==='reply'?'主动开启回复':'停用解绑').props.onClick;
  h.switchHotel(8);await tick();await click();assert.equal(h.calls.filter(x=>x.method==='POST').length,0);assert.equal(h.confirmations.length,0);
 });
 for(const mutation of ['hotel','state','epoch'])for(const outcome of ['success','failure'])test(`${action} ${outcome} after ${mutation} change cannot refresh or overwrite the current workbench`,async()=>{
  const held=gate();const h=harness(call=>call.method==='POST'?held.promise:undefined);h.state.value.wecom_bindings=[binding(7)];const pending=h.act(action);await tick();
  if(mutation==='hotel'){h.switchHotel(8);await tick();}else if(mutation==='state'){h.state.value=makeState();h.state.value.wecom_reply_loading_id=80;}else h.epoch.value++;
  const count=h.calls.length;if(outcome==='success')held.resolve(receipt(action));else held.reject(new Error('obsolete write response'));
  assert.equal(await pending,null);assert.equal(h.calls.length,count);assert.equal(h.state.value.wecom_error,'');if(mutation==='state')assert.equal(h.state.value.wecom_reply_loading_id,80);
 });
 test(`${action} current receipt checks exact hotel as well as binding ID before refresh`,async()=>{
  const h=harness(call=>call.method==='POST'?receipt(action,8,70):undefined);h.state.value.wecom_bindings=[binding(7)];assert.equal(await h.act(action),null);assert.ok(h.state.value.wecom_error);assert.equal(h.calls.length,1);
 });
 test(`current ${action} succeeds with one mutation then read-only refresh`,async()=>{
  const h=harness();h.state.value.wecom_bindings=[binding(7)];const result=await h.act(action);assert.equal(result.id,70);assert.deepEqual(h.calls.map(x=>x.method),['POST','GET','GET','GET']);assert.equal(h.state.value.wecom_reply_loading_id,0);assert.equal(h.confirmations.length,1);
 });
}
test('current read is single-flight, clears old display while pending, and can recover after failure',async()=>{
 const held=gate();let first=true;const h=harness(call=>{if(first&&call.url.includes('/events?')){first=false;return held.promise;}});h.state.value.wecom_bindings=[binding(7)];
 const pending=h.refresh();await tick();await h.refresh();assert.equal(h.calls.length,3);assert.deepEqual(clone(h.state.value.wecom_bindings),[]);assert.ok(!text(h.render()).includes('尚无已验证企微会话绑定'));
 held.reject(new Error('synthetic current failure'));assert.equal(await pending,null);assert.ok(h.state.value.wecom_error);assert.ok(!text(h.render()).includes('尚无已验证企微会话绑定'));
 await h.refresh();assert.equal(h.state.value.wecom_bindings[0].hotel_id,7);assert.equal(h.state.value.wecom_error,'');
});
test('malformed binding and event lists stay failed rather than empty success',async()=>{
 for(const target of ['bindings','events']){const h=harness(call=>call.url.includes('/'+target)?ok({list:null}):undefined);await h.refresh();assert.ok(h.state.value.wecom_error);assert.ok(!text(h.render()).includes('尚无已验证企微会话绑定'));}
});
test('valid empty lists remain an explicit empty result',async()=>{
 const h=harness(call=>!call.url.endsWith('/capabilities')?ok({list:[]}):undefined);await h.refresh();assert.equal(h.state.value.wecom_error,'');assert.ok(text(h.render()).includes('尚无已验证企微会话绑定'));
});
test('current binding-code failure is recoverable without accepting an unverified response',async()=>{
 let attempt=0;const h=harness(call=>call.method==='POST'&&++attempt===1?{code:503,message:'synthetic unavailable'}:undefined);h.state.value.wecom_capabilities=capability;
 assert.equal(await h.create(),null);assert.ok(h.state.value.wecom_error);assert.equal(h.state.value.wecom_loading,false);assert.equal((await h.create()).hotel_id,7);assert.equal(h.state.value.wecom_error,'');
});
for(const action of ['reply','disable'])test(`current ${action} failure releases its lock and allows a fresh attempt`,async()=>{
 let attempt=0;const h=harness(call=>call.method==='POST'&&++attempt===1?{code:503,message:'synthetic write failed'}:undefined);h.state.value.wecom_bindings=[binding(7)];
 assert.equal(await h.act(action),null);assert.ok(h.state.value.wecom_error);assert.equal(h.state.value.wecom_reply_loading_id,0);assert.equal((await h.act(action)).hotel_id,7);assert.equal(h.state.value.wecom_error,'');
});
test('pending read rejects stale mutation clicks and code creation in the same scope',async()=>{
 const held=gate();const h=harness(call=>call.url.includes('/events?')?held.promise:undefined);h.state.value.wecom_capabilities=capability;h.state.value.wecom_bindings=[binding(7)];
 const reply=h.button('主动开启回复').props.onClick,disable=h.button('停用解绑').props.onClick;const pending=h.refresh();await reply();await disable();await h.create();
 assert.equal(h.calls.filter(x=>x.method==='POST').length,0);assert.equal(h.confirmations.length,0);held.resolve(ok({list:[]}));await pending;
});
test('pending mutation blocks manual read and code creation, then performs its own mandatory refresh',async()=>{
 const held=gate();const h=harness(call=>call.method==='POST'?held.promise:undefined);h.state.value.wecom_capabilities=capability;h.state.value.wecom_bindings=[binding(7)];
 const pending=h.act('reply'),read=h.refresh(),create=h.create();await tick();const count=h.calls.length,disabled=h.node('wecom-aibot-binding-code').props.disabled;
 held.resolve(receipt('reply'));const result=await pending;await read;await create;assert.equal(count,1);assert.equal(disabled,true);assert.equal(result.id,70);assert.deepEqual(h.calls.map(x=>x.method),['POST','GET','GET','GET']);
});
test('obsolete reply completion cannot release the next hotel mutation lock',async()=>{
 const old=gate(),fresh=gate();const h=harness(call=>call.method==='POST'?(call.hotel===7?old.promise:fresh.promise):undefined);h.state.value.wecom_bindings=[binding(7)];
 const first=h.act('reply');h.switchHotel(8);await tick();const second=h.act('reply');old.resolve(receipt('reply'));assert.equal(await first,null);assert.equal(h.state.value.wecom_reply_loading_id,80);
 fresh.resolve(receipt('reply',8,80));assert.equal((await second).id,80);assert.equal(h.state.value.wecom_reply_loading_id,0);
});
test('obsolete binding-code completion cannot release the next hotel read lock',async()=>{
 const old=gate(),fresh=gate();const h=harness(call=>call.method==='POST'?old.promise:call.hotel===8&&call.url.includes('/events?')?fresh.promise:undefined);h.state.value.wecom_capabilities=capability;
 const pending=h.create();h.switchHotel(8);await tick();old.resolve(code(7));assert.equal(await pending,null);assert.equal(h.state.value.wecom_loading,true);
 fresh.resolve(ok({list:[]}));await tick();assert.equal(h.state.value.wecom_loading,false);assert.equal(h.state.value.wecom_binding_code,null);
});
test('real Vue updates busy controls when only operation loading flags change',async()=>{
 const held=gate();const h=harness(call=>call.method==='POST'?held.promise:undefined,{reactive:true});h.state.value.wecom_capabilities=capability;
 let disabled;const stop=h.vue.watchEffect(()=>{disabled=h.node('wecom-aibot-binding-code').props.disabled;});assert.equal(disabled,false);
 const pending=h.create();await h.vue.nextTick();assert.equal(disabled,true);held.resolve(code(7));await pending;await h.vue.nextTick();assert.equal(disabled,false);stop();
});
for(const action of ['reply','disable'])for(const outcome of ['success','failure'])test(`A-B-A read permanently cancels the original ${action} ${outcome} owner`,async()=>{
 const held=gate();const h=harness(call=>call.method==='POST'?held.promise:undefined);h.state.value.wecom_bindings=[binding(7)];
 const pending=h.act(action);h.switchHotel(8);await tick();h.switchHotel(7);await tick();const count=h.calls.length;
 if(outcome==='success')held.resolve(receipt(action));else held.reject(new Error('revived obsolete error'));
 const result=await pending;assert.equal(h.calls.length,count);assert.equal(result,null);assert.equal(h.state.value.wecom_error,'');assert.equal(h.state.value.wecom_reply_loading_id,0);
});
