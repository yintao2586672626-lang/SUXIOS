import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
const env={window:{Vue:{h:(type,props,children)=>({type,props,children})}},Intl,Date};
vm.runInNewContext(fs.readFileSync('public/components/system/manager-coaching-panel.js','utf8'),env);
const component=env.window.SUXI_SYSTEM_COMPONENTS.ManagerCoachingPanel;
const deferred=()=>{let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b});return {promise,resolve,reject};};
const plan=(id,hotel=7)=>({id,hotel_id:hotel,manager_user_id:42,revision:1,content:{title:'Synthetic '+id}});
const response=p=>({code:200,data:{plan:p,events:[]}});
function fixture(){const state={...component.data(),hotelId:7,managerId:42,cases:[],canManage:true};Object.defineProperty(state,'scopeKey',{get:()=>`${state.hotelId}:${state.managerId}`});for(const [key,fn] of Object.entries(component.methods))state[key]=fn.bind(state);return state;}
test('coaching detail latest click owns the selected plan even when an older read finishes later',async()=>{
 const s=fixture(),a=deferred(),b=deferred();s.request=url=>url.includes('/11?')?a.promise:b.promise;
 const old=s.open(plan(11)),latest=s.open(plan(12));b.resolve(response(plan(12)));await latest;a.resolve(response(plan(11)));await old;
 assert.equal(s.selected.id,12);
});
test('coaching exact detail refuses a different plan ID in the same hotel and manager scope',async()=>{
 const s=fixture();s.request=async()=>response(plan(12));await s.open(plan(11));assert.equal(s.selected,null);assert.match(s.error,/编号|身份|范围/);
});
test('starting a new coaching draft invalidates a pending detail read in the same scope',async()=>{
 const s=fixture(),a=deferred();s.request=()=>a.promise;const old=s.open(plan(11));s.start();s.form.title='New unsaved draft';a.resolve(response(plan(11)));await old;
 assert.equal(s.action,'create');assert.equal(s.selected,null);assert.equal(s.form.title,'New unsaved draft');
});
test('coaching list rejects malformed success without replacing a previously confirmed list',async()=>{
 const s=fixture();s.plans=[plan(11)];s.request=async()=>({code:200,data:{hotel_id:7,manager_user_id:42,list:{}}});await s.load();
 assert.equal(s.plans[0].id,11);assert.match(s.error,/列表|格式/);
});
test('refreshKeepingDraft rejects mismatched identity and handles transport failure while retaining draft',async()=>{
 const s=fixture();s.selected=plan(11);s.form.title='Unsaved edit';s.request=async()=>response(plan(11,8));await s.refreshKeepingDraft();
 assert.equal(s.selected.hotel_id,7);assert.equal(s.form.title,'Unsaved edit');assert.match(s.error,/身份|范围/);
 s.request=async()=>{throw Error('synthetic network failure')};await s.refreshKeepingDraft();assert.match(s.error,/synthetic network failure/);
});
test('coaching fields are disabled while submitted values are being saved and read back',()=>{
 const s=fixture();s.busy=true;
 for(const type of ['text','textarea','checkbox','select']){const rendered=s.field({value:''},'value','Synthetic',type,type==='select'?{a:'A'}:null);assert.equal(rendered.children[1].props.disabled,true,type);}
});

function findSubmit(node) {
 if (!node || typeof node !== 'object') return null;
 if (node.type === 'button' && node.props?.type === 'submit') return node;
 for (const child of Array.isArray(node.children) ? node.children : []) { const found=findSubmit(child); if(found)return found; }
 return null;
}

test('pending reference reads block both submission paths until every selected reference is included',async()=>{
 const s=fixture(),a=deferred(),b=deferred(),writes=[];s.start();s.caseId='1';
 const saved={...plan(11),content_digest:'synthetic-digest',content:{knowledge_chunk_ids:[101,102]}};
 s.request=async(url,options)=>{
  if(url.includes('reference-sources/101'))return a.promise;
  if(url.includes('reference-sources/102'))return b.promise;
  if(options?.method==='POST'){writes.push(JSON.parse(options.body));return response(saved);}
  if(url.includes('/11?'))return response(saved);
  return {code:200,data:{hotel_id:7,manager_user_id:42,list:[saved]}};
 };
 const first=s.addReference({chunk_id:101}),second=s.addReference({chunk_id:102});
 await s.save();assert.equal(writes.length,0);assert.equal(findSubmit(component.render.call(s)).props.disabled,true);
 a.resolve({code:200,data:{chunk_id:101,title:'First source'}});await first;
 await s.save();assert.equal(writes.length,0);assert.equal(s.referencePending,1);
 b.resolve({code:200,data:{chunk_id:102,title:'Second source'}});await second;
 assert.equal(s.referencePending,0);assert.equal(findSubmit(component.render.call(s)).props.disabled,false);
 await s.save();assert.equal(writes.length,1);assert.deepEqual(writes[0].knowledge_chunk_ids,[101,102]);assert.equal(s.selected.id,11);
});

test('failed and duplicate reference reads release only their own pending slot and remain retryable',async()=>{
 const s=fixture(),a=deferred(),b=deferred();s.start();let firstCall=true;
 s.request=url=>url.includes('/101?')?(firstCall?(firstCall=false,a.promise):Promise.resolve({code:200,data:{chunk_id:101}})):b.promise;
 const first=s.addReference({chunk_id:101}),second=s.addReference({chunk_id:102});
 a.reject(Error('Synthetic reference failure'));await first;assert.equal(s.referencePending,1);assert.match(s.error,/Synthetic reference failure/);
 b.resolve({code:200,data:{chunk_id:102}});await second;assert.equal(s.referencePending,0);
 await Promise.all([s.addReference({chunk_id:101}),s.addReference({chunk_id:101})]);
 assert.equal(s.referencePending,0);assert.equal(s.references.length,2);assert.equal(s.form.knowledge_chunk_ids.length,2);
});

for(const transition of ['reopen','different-plan','hotel-change','refresh']){
 test(`reference ownership survives ${transition} without stale completion clearing the new draft pending state`,async()=>{
  const s=fixture(),old=deferred(),fresh=deferred();s.start();
  s.request=async(url)=>{
   if(url.includes('reference-sources/101'))return old.promise;
   if(url.includes('reference-sources/102'))return fresh.promise;
   if(url.includes('/12?'))return response(plan(12,Number(s.hotelId)));
   return {code:200,data:{hotel_id:Number(s.hotelId),manager_user_id:42,list:[]}};
  };
  const previous=s.addReference({chunk_id:101});
  if(transition==='different-plan'){await s.open(plan(12));s.begin('edit');}
  else if(transition==='hotel-change'){s.hotelId=8;component.watch.scopeKey.handler.call(s);s.start();}
  else if(transition==='refresh'){s.selected=plan(12);await s.refreshKeepingDraft();}
  else{s.start();}
  const latest=s.addReference({chunk_id:102});
  old.resolve({code:200,data:{chunk_id:101}});await previous;
  assert.equal(s.referencePending,1);assert.equal(s.references.length,0);
  fresh.resolve({code:200,data:{chunk_id:102}});await latest;
  assert.equal(s.referencePending,0);assert.equal(s.references.length,1);assert.equal(s.references[0].chunk_id,102);
 });
}
