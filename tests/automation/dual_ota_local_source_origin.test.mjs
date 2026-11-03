import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
const ctx={window:{},URLSearchParams};
vm.runInNewContext(readFileSync(new URL('../../public/components/system/dual-ota-field-closure-panel.js',import.meta.url),'utf8'),ctx);
const component=ctx.window.SUXI_DUAL_OTA_FIELD_CLOSURE.createPanel({h:(tag,props,children)=>({tag,props,children})});
const text=t=>typeof t==='string'?t:Array.isArray(t)?t.map(text).join(' '):t&&typeof t==='object'?text(t.children):'';
const render=(origin,surface='data_health')=>{
 const closure={contract_version:'dual_ota_field_closure.v1',tenant_id:10,hotel_id:20,business_date:'2026-08-20',page_identity:'fixture',status:'partial',
  platforms:{ctrip:{platform:'ctrip',fields:[],latest_collection:{},revenue_analysis:{status:'blocked'}}},
  local_source_records:{ctrip:{diagnostic_only:true,state:'local_records_present',record_count:1,records:[{record_ref:'online_daily_data#1',source_origin:origin,validation_status:'unverified',history_status:'unverified',readback_status:'verified',requirements:['record_validation_required']}]}}};
 return text(component.render.call({activeClosure:closure,closure,surface}));
};
test('origin labels and specific safe recovery are independent of readback and quality',()=>{
 for(const surface of ['data_health','revenue_cockpit']) for(const [type,label,recovery] of [
  ['manual_import','人工录入/导入','原始导入文件或人工记录'],
  ['browser_assisted_import','页面辅助导入','不能替代结构化响应'],
  ['collection_registered','浏览器/本地采集登记','采集回执、任务'],
  ['interface_registered','接口方式登记','不能证明官方来源'],
 ]){
  const s=render({type,method:'fixture'},surface);
  for(const expected of [label,recovery,'质量：未验证','数据库回读：已验证','不手动改质量','不发起采集、不改变质量状态']) assert.ok(s.includes(expected),expected);
 }
 assert.ok(render({type:'manual_import',method:'manual_override'}).includes('来源登记：人工更正'));
});
test('old origin contracts and unsupported values remain unknown without leaking arbitrary text',()=>{
 for(const origin of [undefined,{type:'unknown'},{type:'private_arbitrary_text',method:'secret'}]){
  const s=render(origin);
  assert.ok(s.includes('来源登记缺失或未识别'));
  assert.ok(s.includes('不能猜测为线上采集、人工导入或模拟数据'));
  assert.ok(!s.includes('private_arbitrary_text')&&!s.includes('secret'));
 }
});
