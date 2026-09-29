import fs from 'node:fs';import vm from 'node:vm';import assert from 'node:assert/strict';import test from 'node:test';
import {mount} from './operating_target_save_recovery.test.mjs';
const runtime={window:{}};vm.runInNewContext(fs.readFileSync('public/operation-static.js','utf8'),runtime);
const helpers=runtime.window.SUXI_OPERATION_STATIC;
const context={hotelId:'90001',targetDate:'2026-09-20'};
const record=(source='pms',scope='whole_hotel')=>({id:1,hotel_id:90001,tenant_id:9001,target_date:'2026-09-20',facts:{target_revenue:10000,actual_revenue:4000,sold_room_nights:20,sellable_room_nights:40,fact_scope:scope,source_type:source,source_reference:'SYNTHETIC saved source',quality_status:'manual_confirmed',fact_captured_at:'2026-09-20 08:10:00'},calculation:{metrics:{completion_rate_percent:40,remaining_revenue:6000}}});
test('editing a manual form cannot promote the saved source facts',async()=>{
 const original=record('manual'),form=helpers.buildOperatingTargetForm(original,{});Object.assign(form,{actual_revenue:99999,quality_status:'verified',source_reference:'edited'});let payload;
 await helpers.saveOperatingTargetRecord(form,context,async(_url,options)=>{payload=JSON.parse(options.body);return {code:200,data:{record:original}};},original);
 assert.equal(payload.actual_revenue,4000);assert.equal(payload.quality_status,'manual_confirmed');assert.equal(payload.source_reference,original.facts.source_reference);
});
test('foreign record cannot restore manual facts into the current scope',async()=>{
 const original=record('manual'),form=helpers.buildOperatingTargetForm(original,{});let payload;
 await helpers.saveOperatingTargetRecord(form,context,async(_url,options)=>{payload=JSON.parse(options.body);return {code:200,data:{record:original}};},{...original,hotel_id:90002});
 assert.equal(payload.actual_revenue,null);assert.equal(payload.quality_status,'unverified');assert.equal(payload.source_reference,'');
});
for(const source of ['pms','manual','import','daily_report'])for(const scope of ['whole_hotel','accommodation_room_fee'])test('edit only goal preserves saved '+source+' '+scope+' facts',async()=>{
 const original=record(source,scope);let payload;
 const m=mount((url,options)=>{
  if(options?.method==='POST'){payload=JSON.parse(options.body);return {code:200,data:{record:{...original,facts:payload}}};}
  return {code:200,data:url.includes('/current?')?{record:original}:{list:[]}};
 });
 await m.loadOperatingTarget();assert.equal(m.operatingTargetForm.value.fact_scope,scope);
 m.operatingTargetForm.value.target_revenue=12000;await m.saveOperatingTarget();
 assert.equal(payload.target_revenue,12000);assert.equal(payload.fact_scope,scope);
 for(const key of ['actual_revenue','sold_room_nights','sellable_room_nights','source_type','source_reference','quality_status','fact_captured_at'])assert.equal(payload[key],original.facts[key],key);
});
test('metric labels follow exact amount scope without asserting PMS origin',()=>{
 for(const source of ['pms','manual','import','daily_report'])for(const scope of ['whole_hotel','accommodation_room_fee']){
  const rows=helpers.buildOperatingTargetMetricRows(record(source,scope),String);
  assert.equal(rows[0].label,scope==='whole_hotel'?'全酒店营收总目标':'住宿房费总目标');
  assert.equal(rows[1].label,scope==='whole_hotel'?'全酒店实际营收':'实际住宿房费');
 }
});
for(const action of ['prefillOperatingTargetFromDingdandao','prefillOperatingTargetFromMeituanCloud'])test(action+' must not reuse whole-hotel goal as room-fee goal',async()=>{
 const m=mount(()=>({code:200,data:{capture:{hotel_id:90001,business_date:'2026-09-20'},prefill:{target_date:'2026-09-20',source_type:'pms',fact_scope:'accommodation_room_fee',actual_revenue:0,quality_status:'verified'}}}));
 Object.assign(m.operatingTargetForm.value,record().facts);await m[action]();
 assert.equal(m.operatingTargetForm.value.fact_scope,'accommodation_room_fee');assert.equal(m.operatingTargetForm.value.target_revenue,'');assert.equal(m.operatingTargetForm.value.actual_revenue,0);
 assert.equal((action.includes('Dingdandao')?m.operatingTargetPmsStatus:m.operatingTargetMeituanCloudPmsStatus).value.hotel_id,90001);
});
test('PMS facts retain zero, unknown and whitespace without creating rates',()=>{
 for(const value of [null,undefined,'','  ']){
  const rows=helpers.buildOperatingTargetPmsFactRows({...record('pms','accommodation_room_fee').facts,actual_revenue:value},String);
  assert.equal(rows.find(x=>x.key==='actual_revenue').value,null);assert.equal(rows.find(x=>x.key==='adr').value,null);
 }
 const rows=helpers.buildOperatingTargetPmsFactRows({...record('pms','accommodation_room_fee').facts,actual_revenue:0},String);
 assert.equal(rows.find(x=>x.key==='actual_revenue').value,0);assert.equal(rows.find(x=>x.key==='adr').value,0);
});
test('whole-hotel PMS money does not derive room ADR or RevPAR in the read-only panel',()=>{
 const source=fs.readFileSync('public/app-main.js','utf8');
 const a=source.indexOf('const operatingTargetNumberOrNull ='),b=source.indexOf('const operatingTargetSnapshotMetricRows =',a);
 const c=vm.createContext({window:runtime.window,computed:fn=>({value:fn()}),operatingTargetForm:{value:record().facts},operatingTargetHasValue:v=>v!==null&&v!==undefined&&v!=='',operatingTargetMetricText:String});
 vm.runInContext(source.slice(a,b)+';globalThis.rows=operatingTargetPmsFactRows.value;',c);
 assert.equal(c.rows.find(x=>x.key==='actual_revenue').label,'全酒店实际营收');
 assert.equal(c.rows.find(x=>x.key==='adr').value,null);assert.equal(c.rows.find(x=>x.key==='revpar').value,null);
 assert.equal(c.rows.find(x=>x.key==='occupancy_rate').value,50);
});
for(const action of ['prefillOperatingTargetFromDingdandao','prefillOperatingTargetFromMeituanCloud'])for(const failure of ['network','missing','wrong-scope'])test(action+' failed scope transition preserves the saved record: '+failure,async()=>{
 const original=record();let fail=true,payload;
 const m=mount((url,options)=>{
  if(url.includes('/prefill/')){
   if(fail&&failure==='network')throw Error('SYNTHETIC unavailable');
   if(fail&&failure==='missing')return {code:200,data:{prefill:null,gaps:[{message:'SYNTHETIC missing'}]}};
   return {code:200,data:{capture:{hotel_id:fail?90002:90001,business_date:context.targetDate},prefill:{target_date:context.targetDate,fact_scope:'accommodation_room_fee',source_type:'pms',actual_revenue:0}}};
  }
  if(options?.method==='POST'){payload=JSON.parse(options.body);return {code:200,data:{record:{...original,facts:payload}}};}
  return {code:200,data:url.includes('/current?')?{record:original}:{list:[]}};
 });
 await m.loadOperatingTarget();await m[action]();assert.ok(m.operatingTargetError.value);
 assert.equal(m.operatingTargetForm.value.fact_scope,'whole_hotel');assert.equal(m.operatingTargetForm.value.target_revenue,10000);
 assert.equal(m.operatingTargetResult.value.id,original.id);
 m.operatingTargetForm.value.target_revenue=12000;await m.saveOperatingTarget();
 assert.equal(payload.fact_scope,'whole_hotel');assert.equal(payload.actual_revenue,4000);assert.equal(payload.quality_status,'manual_confirmed');
 fail=false;await m[action]();assert.equal(m.operatingTargetForm.value.target_revenue,'');assert.equal(m.operatingTargetForm.value.actual_revenue,0);
});
