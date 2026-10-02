import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
import * as Vue from 'vue';

const source=fs.readFileSync(process.env.SUXI_FORECAST_COMPONENT_SOURCE||'public/components/revenue/forecast-decision-workbench.js','utf8');
const deferred=()=>{let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b;});return{promise,resolve,reject};};
const walk=v=>[v,...(Array.isArray(v?.children)?v.children.flatMap(walk):[])];
const scope={hotel_id:701,platform:'ctrip',platform_store_id:'synthetic-store',room_scope:'synthetic-room'};
const original=JSON.stringify({seed:1},null,2),id='a'.repeat(64);
const result=()=>({replay:{scope,source_kind:'synthetic',input_evidence:{},comparisons:Object.fromEntries([7,14,30].map(n=>[n,{metrics:{model:{},weekly:{},mean7:{}},assessment:{}}])),forecasts:Object.fromEntries([7,14,30].map(n=>[n,{}])),applicability:[]},scenario:null});
const saved=()=>({code:200,data:{id,readback_verified:true,payload:{scope,input:{evidence:{seed:1}},result:result()}}});
function mount(override){
 const hooks=[],contexts=[],requests=[];const context={window:{Vue:{...Vue,onBeforeUnmount:fn=>hooks.push(fn)}},URLSearchParams};vm.runInNewContext(source,context);
 const request=async(path,options={})=>{requests.push(path);const custom=override?.(path,options);if(custom!==undefined)return custom;if(path.includes('/context?')){const pending=deferred();contexts.push(pending);return pending.promise;}if(path.includes('/plans?'))return{code:200,data:{scope,page:1,page_size:50,total:1,total_pages:1,items:[{id}]}};return path.endsWith('/preview')?{code:200,data:result()}:saved();};
 const props=Vue.reactive({hotelId:701,hotels:[{id:701},{id:702}],request});const effects=Vue.effectScope();
 const render=effects.run(()=>context.window.SUXI_SYSTEM_COMPONENTS.ForecastDecisionWorkbench.setup(props));
 const nodes=()=>walk(render()),by=id=>nodes().find(v=>v?.props?.['data-testid']===id);
 const fill=(key,value)=>by('forecast-'+key).props.onInput({target:{value}});
 fill('platform_store_id',scope.platform_store_id);fill('room_scope',scope.room_scope);fill('evidence',original);
 const upload=(target={value:'selected.json'},size=100)=>{const pending=deferred();target.files=[{size,text:()=>pending.promise}];const handler=nodes().find(v=>v?.type==='input'&&v.props.type==='file').props.onChange;const done=Promise.resolve(handler({target})).then(()=>null,e=>e);return{...pending,target,done};};
 const sample=()=>{const done=by('forecast-sample').props.onClick();return{...contexts.at(-1),done};};
 return{by,fill,upload,sample,props,requests,value:()=>by('forecast-evidence').props.value,error:()=>nodes().filter(v=>v?.props?.role==='alert').map(v=>v.children).join(' '),dispose(){hooks.forEach(fn=>fn());effects.stop();}};
}

for(const producer of ['file','sample'])for(const change of ['input-aba','scope-aba','save','read','unmount']){
 test(`${producer} cannot replace current evidence after ${change}`,async()=>{
  const ui=mount();if(change==='read')await ui.by('forecast-history').props.onClick();
  const pending=producer==='file'?ui.upload():ui.sample();
  if(change==='input-aba'){ui.fill('evidence','EDITED');ui.fill('evidence',original);}
  if(change==='scope-aba'){ui.fill('platform_store_id','OTHER');ui.fill('platform_store_id',scope.platform_store_id);}
  if(change==='save')await ui.by('forecast-save').props.onClick();
  if(change==='read')await ui.by('forecast-read').props.onClick();
  if(change==='unmount')ui.dispose();
  const before={value:ui.value(),saved:ui.by('forecast-saved')?.children,error:ui.error()};
  pending.resolve(producer==='file'?'STALE FILE':{code:200,data:{scope}});await pending.done;
  assert.equal(ui.value(),before.value);assert.equal(ui.by('forecast-saved')?.children,before.saved);assert.equal(ui.error(),before.error);
  if(change!=='unmount')ui.dispose();
 });
}

for(const first of ['file','sample'])for(const second of ['file','sample']){
 test(`new ${second} owns evidence before earlier ${first} completes`,async()=>{
  const ui=mount(),old=first==='file'?ui.upload():ui.sample(),fresh=second==='file'?ui.upload():ui.sample();
  old.resolve(first==='file'?'OLD FILE':{code:200,data:{scope}});await old.done;
  assert.equal(ui.value(),original,'an older producer must not displace a newer pending choice');
  fresh.resolve(second==='file'?'NEW FILE':{code:200,data:{scope}});await fresh.done;
  if(second==='file')assert.equal(ui.value(),'NEW FILE');else assert.equal(JSON.parse(ui.value()).source_kind,'synthetic');
  ui.dispose();
 });
}

test('current file read failure is visible and selection is cleared for same-file retry',async()=>{
 const ui=mount(),failed=ui.upload();failed.reject(new Error('SYNTHETIC file read failed'));
 assert.equal(await failed.done,null,'file errors must not escape the event handler');assert.match(ui.error(),/file read failed/);assert.equal(failed.target.value,'');assert.equal(ui.value(),original);
 const retry=ui.upload(failed.target);retry.resolve('{"source_kind":"manual_unverified","value":0}');await retry.done;
 assert.equal(ui.error(),'');assert.deepEqual(JSON.parse(ui.value()),{source_kind:'manual_unverified',value:0});assert.equal(retry.target.value,'');ui.dispose();
});

test('older file failure cannot affect newer selection, evidence or result',async()=>{
 const ui=mount(),old=ui.upload(),fresh=ui.upload(old.target);old.reject(new Error('OLD read error'));
 assert.equal(await old.done,null);assert.equal(ui.error(),'');assert.equal(fresh.target.value,'selected.json');
 fresh.resolve(original);await fresh.done;await ui.by('forecast-save').props.onClick();assert.ok(ui.by('forecast-saved'));ui.dispose();
});

test('oversized selection cancels an older file and can recover with a valid selection',async()=>{
 const ui=mount(),old=ui.upload(),large=ui.upload({value:'large.json'},2000001);await large.done;
 assert.match(ui.error(),/2MB/);assert.equal(large.target.value,'');old.resolve('OLD');await old.done;assert.equal(ui.value(),original);
 const next=ui.upload();next.resolve(original);await next.done;assert.equal(ui.error(),'');ui.dispose();
});

test('late sample failure cannot replace a current calculation after input ABA',async()=>{
 const ui=mount(),old=ui.sample();ui.fill('evidence','CHANGED');ui.fill('evidence',original);await ui.by('forecast-run').props.onClick();
 old.reject(new Error('OLD sample failure'));await old.done;assert.equal(ui.error(),'');assert.ok(ui.by('forecast-result'));ui.dispose();
});

test('new evidence selection invalidates a saved result even when text later equals previous input',async()=>{
 const ui=mount();await ui.by('forecast-save').props.onClick();assert.ok(ui.by('forecast-saved'));
 const next=ui.upload();assert.equal(ui.by('forecast-saved'),undefined);next.resolve(original);await next.done;assert.equal(ui.by('forecast-saved'),undefined);ui.dispose();
});

test('new file choice prevents an earlier pending preview from republishing a result',async()=>{
 const pending=deferred(),ui=mount(path=>path.endsWith('/preview')?pending.promise:undefined);
 const running=ui.by('forecast-run').props.onClick();assert.equal(ui.by('forecast-run').props.disabled,true);
 const selected=ui.upload();selected.resolve(original);await selected.done;
 pending.resolve({code:200,data:result()});await running;
 assert.equal(ui.by('forecast-result'),undefined);assert.equal(ui.by('forecast-run').props.disabled,false);assert.equal(ui.value(),original);ui.dispose();
});

test('a current failed sample is visible and the next sample recovers without upgrading its source',async()=>{
 const ui=mount(),first=ui.sample();first.reject(new Error('SYNTHETIC context unavailable'));await first.done;
 assert.match(ui.error(),/context unavailable/);assert.equal(ui.value(),original);
 const retry=ui.sample();retry.resolve({code:200,data:{scope}});await retry.done;
 assert.equal(ui.error(),'');assert.equal(JSON.parse(ui.value()).source_kind,'synthetic');ui.dispose();
});
