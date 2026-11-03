import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
const ctx={window:{},URLSearchParams};
vm.runInNewContext(readFileSync(new URL('../../public/components/system/dual-ota-field-closure-panel.js',import.meta.url),'utf8'),ctx);
const component=ctx.window.SUXI_DUAL_OTA_FIELD_CLOSURE.createPanel({h:(tag,props,children)=>({tag,props,children})});
const text=tree=> typeof tree==='string'?tree:Array.isArray(tree)?tree.map(text).join(' '):tree&&typeof tree==='object'?text(tree.children):'';
const render=(diagnostic,surface='data_health')=>{
  const closure={contract_version:'dual_ota_field_closure.v1',tenant_id:10,hotel_id:20,business_date:'2026-08-20',page_identity:'fixture',status:'partial',
    platforms:{ctrip:{platform:'ctrip',fields:[],latest_collection:{},revenue_analysis:{status:'blocked'}}},
    ...(diagnostic===undefined?{}:{local_source_records:{ctrip:diagnostic}})};
  return text(component.render.call({activeClosure:closure,closure,surface}));
};
test('local rows show unverified requirements on health and revenue without becoming formal facts',()=>{
  for(const surface of ['data_health','revenue_cockpit']){
    const result=render({diagnostic_only:true,state:'local_records_present',record_count:1,records:[{record_ref:'online_daily_data#1',validation_status:'unverified',history_status:'failed',readback_status:'unknown',requirements:['record_validation_required','record_readback_required','current_receipt_binding_required']}]},surface);
    for(const expected of ['本地记录 1 条','质量：未验证','历史：失败','数据库回读：未知','记录质量须为已验证','当前已接受回执','整批正式回读 0 条','不代表字段事实可用'])assert.ok(result.includes(expected),expected);
    assert.ok(result.includes('不发起采集、不改变质量状态'));
  }
});
test('successful empty diagnostics is distinct from absent legacy diagnostics',()=>{
  assert.ok(render({diagnostic_only:true,state:'no_local_records',record_count:0,records:[]}).includes('读取成功，无本地记录'));
  const legacy=render();
  assert.ok(legacy.includes('诊断未返回，数量与质量未知'));
  assert.ok(!legacy.includes('本地记录 0 条'));
});
test('inconsistent or unsafe diagnostic payload remains unknown',()=>{
  for(const diagnostic of [{diagnostic_only:true,state:'local_records_present',record_count:3,records:[]},{diagnostic_only:false,state:'no_local_records',record_count:0,records:[]}]){
    assert.ok(render(diagnostic).includes('数量与质量未知'));
  }
});
