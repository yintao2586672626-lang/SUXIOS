import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const defaults={price:'300',nights:'1000',oldRate:'10',newRate:'15',costEnabled:false,cost:'',forecast:'',budgetMode:'commission_gap',budget:'',historicalRoi:'',incrementalityPercent:'100',platform:'',period:'',historicalSource:''};
const payload=(price='400',hotelId=7)=>({schema:'suxi.commission-acquisition-scenario.v1',fact_status:'unverified',hotel_id:hotelId,input:{...defaults,price}});
const gate=()=>{let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b;});return{promise,resolve,reject};};
const walk=node=>[node,...(Array.isArray(node?.children)?node.children.flatMap(walk):[])];
function mount(){
 const context=vm.createContext({console,Blob,setTimeout});
 vm.runInContext(fs.readFileSync('public/vue.runtime.global.prod.js','utf8'),context);
 const unmount=[],blobs=[];
 context.window={Vue:{...context.Vue,onBeforeUnmount:fn=>unmount.push(fn)}};
 context.URL={createObjectURL:blob=>{blobs.push(blob);return 'blob:fixture';},revokeObjectURL(){}};
 context.document={createElement:()=>({click(){},remove(){}}),body:{append(){}}};
 for(const name of ['commission-calculator-core','commission-paid-traffic-core']){
  vm.runInContext(fs.readFileSync('public/components/revenue/'+name+'.js','utf8'),context);
 }
 vm.runInContext(fs.readFileSync(process.env.SUXI_COMMISSION_PANEL_SOURCE||'public/components/revenue/commission-acquisition-panel.js','utf8'),context);
 const Vue=context.Vue,props=Vue.reactive({hotelId:7,hotels:[{id:7,name:'Fixture A'},{id:8,name:'Fixture B'}],request:null});
 const scope=Vue.effectScope();const render=scope.run(()=>context.window.SUXI_SYSTEM_COMPONENTS.CommissionAcquisitionCalculatorPanel.setup(props));
 const nodes=()=>walk(render()),find=id=>nodes().find(n=>n?.props?.['data-testid']===id);
 const edit=(key,value)=>find('commission-field-'+key).props.onInput({target:{value}});
 const select=(key,value)=>find('commission-field-'+key).props.onChange({target:{value}});
 const start=(data=payload(),pending=gate())=>{
  const target={files:[{size:200,text:()=>pending.promise}],value:'fixture.json'};
  const promise=find('commission-import-file').props.onChange({target});
  return{target,promise,resolve:()=>pending.resolve(typeof data==='string'?data:JSON.stringify(data)),reject:()=>pending.reject(new Error('fixture read failed'))};
 };
 return{props,find,edit,select,start,blobs,price:()=>find('commission-field-price').props.value,notice:()=>find('commission-notice')?.children||'',
  reset:()=>nodes().find(n=>n.type==='button'&&n.children==='重置示例').props.onClick(),
  flush:()=>Vue.nextTick(),dispose(){unmount.forEach(fn=>fn());scope.stop();}};
}
async function finish(request){request.resolve();await request.promise;}

test('exported manual scenario imports with exact inputs, explicit zero and unchanged quality',async()=>{
 const ui=mount();ui.edit('price','420.50');ui.edit('forecast','0');ui.select('platform','ctrip');
 ui.find('commission-save-scenario').props.onClick();
 const saved=JSON.parse(await ui.blobs[0].text());assert.equal(saved.hotel_id,7);
 assert.equal(saved.source_method,'user_scenario_input');assert.equal(saved.fact_status,'unverified');
 ui.reset();await finish(ui.start(saved));assert.equal(ui.price(),'420.50');
 assert.equal(ui.find('commission-field-forecast').props.value,'0');assert.equal(ui.find('commission-field-platform').props.value,'ctrip');
 assert.match(ui.notice(),/恢复输入/);ui.dispose();
});
for(const action of ['edit','reset','hotel','aba','unmount']){
 for(const response of ['success','failure']){
  test('pending import cannot override '+action+' on '+response,async()=>{
   const ui=mount();const request=ui.start(payload('450',action==='hotel'?null:7));
   if(action==='edit')ui.edit('price','360');
   if(action==='reset')ui.reset();
   if(action==='hotel')ui.props.hotelId=8;
   if(action==='aba'){ui.props.hotelId=8;await ui.flush();ui.props.hotelId=7;}
   if(action==='unmount')ui.dispose();
   await ui.flush();const before={price:ui.price(),notice:ui.notice()};
   if(response==='success')request.resolve();else request.reject();
   await request.promise;
   assert.equal(ui.price(),before.price);assert.equal(ui.notice(),before.notice);
   if(action!=='unmount')ui.dispose();
  });
 }
}
for(const response of ['success','failure']){
 test('newest file remains authoritative when earlier file ends with '+response,async()=>{
  const ui=mount(),old=ui.start(payload('410')),fresh=ui.start(payload('520'));
  await finish(fresh);assert.equal(ui.price(),'520');const notice=ui.notice();
  if(response==='success')old.resolve();else old.reject();await old.promise;
  assert.equal(ui.price(),'520');assert.equal(ui.notice(),notice);ui.dispose();
 });
}
test('reset file input immediately allows selecting the same file again without late clearing a newer selection',async()=>{
 const ui=mount(),old=ui.start();assert.equal(old.target.value,'');
 old.target.value='newer-selection.json';await finish(old);
 assert.equal(old.target.value,'newer-selection.json');ui.dispose();
});
test('current invalid or foreign file preserves inputs; retry succeeds',async()=>{
 const ui=mount();ui.edit('price','365');
 for(const data of ['{invalid',payload('450',8),{...payload(),input:{...defaults,price:'bad'}}]){
  await finish(ui.start(data));assert.equal(ui.price(),'365');assert.match(ui.notice(),/导入失败/);
 }
 const failed=ui.start();failed.reject();await failed.promise;assert.equal(ui.price(),'365');
 assert.match(ui.notice(),/fixture read failed/);await finish(ui.start(payload('410')));
 assert.equal(ui.price(),'410');assert.match(ui.notice(),/恢复输入/);ui.dispose();
});
