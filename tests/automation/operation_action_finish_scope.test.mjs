import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const ctx={window:{}};vm.createContext(ctx);vm.runInContext(fs.readFileSync('public/operation-static.js','utf8'),ctx);
const make=(overrides={})=>{
  const actions={value:[{id:11,hotel_id:7,status:'active'}]},loading={value:{actions:false}},messages=[];
  let hotel='7',session=1,page='ops-track',reads=0;
  const requests=[];
  const config={actions,loading,showToast:(...args)=>messages.push(args),request:()=>new Promise((resolve,reject)=>requests.push({resolve,reject})),
    captureAuthSession:()=>session,isAuthSessionCurrent:x=>x===session,
    currentHotel:()=>hotel,currentPage:()=>page,
    load:async()=>{reads++;actions.value=[{id:11,hotel_id:7,status:'finished'}];return true},
    errorMessage:e=>e.message,...overrides};
  return {controller:ctx.window.SUXI_OPERATION_STATIC.createOperationActionFinishController(config),actions,loading,messages,requests,
    reads:()=>reads,setHotel:x=>hotel=x,setSession:x=>session=x,setPage:x=>page=x};
};

test('finish sends one scoped write and waits for exact finished row before success',async()=>{
  const s=make(),action=s.actions.value[0];
  const first=s.controller.finish(action),second=s.controller.finish(action);
  assert.equal(s.requests.length,1);
  assert.equal(await second,false);
  s.requests[0].resolve({code:200,data:{id:11}});
  assert.equal(await first,true);
  assert.equal(s.reads(),1);
  assert.equal(s.messages.filter(x=>x[0]==='策略动作已结束').length,1);
  assert.equal(await s.controller.finish(action),false);
  assert.equal(s.requests.length,1);
});

test('wrong hotel, missing hotel identity and stale visible row do not write',async()=>{
  const s=make();
  assert.equal(await s.controller.finish({id:11,hotel_id:8,status:'active'}),false);
  assert.equal(await s.controller.finish({id:11,status:'active'}),false);
  s.actions.value=[];
  assert.equal(await s.controller.finish({id:11,hotel_id:7,status:'active'}),false);
  assert.equal(s.requests.length,0);
});

test('hotel or session change suppresses old feedback and old readback',async()=>{
  for(const change of [s=>s.setHotel('8'),s=>s.setSession(2),s=>s.setPage('home')]){
    const s=make(),pending=s.controller.finish(s.actions.value[0]);
    change(s);s.requests[0].resolve({code:200,data:{id:11}});
    assert.equal(await pending,false);
    assert.equal(s.reads(),0);assert.equal(s.messages.length,0);
  }
});

test('mismatched ack or failed exact readback cannot show success and can retry',async()=>{
  const s=make({load:async()=>false}),action=s.actions.value[0];
  let pending=s.controller.finish(action);
  s.requests[0].resolve({code:200,data:{id:12}});
  assert.equal(await pending,false);assert.ok(s.messages.every(x=>x[0]!=='策略动作已结束'));
  pending=s.controller.finish(action);
  s.requests[1].resolve({code:200,data:{id:11}});
  assert.equal(await pending,false);assert.ok(s.messages.some(x=>/回读/.test(x[0])));
  assert.equal(s.loading.value.actions,false);
});

test('a finished row with the same ID but another hotel is not a confirmed readback',async()=>{
  const s=make({load:async()=>{s.actions.value=[{id:11,hotel_id:8,status:'finished'}];return true}});
  const pending=s.controller.finish(s.actions.value[0]);
  s.requests[0].resolve({code:200,data:{id:11}});
  assert.equal(await pending,false);
  assert.ok(s.messages.some(item=>/回读/.test(item[0])));
  assert.ok(s.messages.every(item=>item[0]!=='策略动作已结束'));
});

test('readback throwing after a successful write does not claim that the write failed',async()=>{
  const s=make({load:async()=>{throw new Error('read unavailable')}});
  const pending=s.controller.finish(s.actions.value[0]);
  s.requests[0].resolve({code:200,data:{id:11}});
  assert.equal(await pending,false);
  assert.ok(s.messages.some(item=>/回读未确认/.test(item[0])));
  assert.ok(s.messages.every(item=>!/^结束策略动作失败/.test(item[0])));
});
