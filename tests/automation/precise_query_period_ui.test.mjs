import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const source=fs.readFileSync('public/components/system/operating-intelligence-components.js','utf8');
const between=(a,b)=>source.slice(source.indexOf(a)+a.length,source.indexOf(b));
const h=(tag,props,children)=>({tag,props:children===undefined?{}:props,children:children===undefined?props:children});
const helpers=between('// PRECISE_METRIC_SET_HELPERS_START','// PRECISE_METRIC_SET_HELPERS_END');
const renderers=between('// PRECISE_QUERY_EXPLANATION_START','// PRECISE_QUERY_EXPLANATION_END');
const {renderPreciseQueryConditions,renderPrecisePeriodEvidence}=new Function('h',helpers+renderers+';return {renderPreciseQueryConditions,renderPrecisePeriodEvidence};')(h);
const content=node=>node==null?'':(Array.isArray(node)?node.map(content).join('\n'):(typeof node==='object'?content(node.children):String(node)));
test('actual renderer exposes interpreted conditions and verified historical parent',()=>{
  const text=content(renderPreciseQueryConditions({precise_query_id:41,persistence_status:'readback_verified',precise_query_scope:{hotel_id:80,hotel_name:'Synthetic酒店',platform:'ctrip',date_start:'2026-09-01',date_end:'2026-09-07',metric_keys:['amount'],date_source:'completed_recent_days',parent_question_id:40}}));
  for(const marker of ['#41','已按编号回读','Synthetic酒店','携程','订单金额','2026-09-01 至 2026-09-07','不含今天','#40']) assert.ok(text.includes(marker),marker);
});
test('partial period renderer never replaces absent full value with subtotal',()=>{
  const text=content(renderPrecisePeriodEvidence({date_start:'2026-09-01',date_end:'2026-09-07',status:'partial',value:null,partial_value:522,subtotal_label:'非全期间金额',unit:'CNY',coverage:{available_days:5,expected_days:7,missing_dates:['2026-09-02','2026-09-04']},formula:'SUM(已核验每日订单额)',daily_facts:[{business_date:'2026-09-01',value:101,unit:'CNY',source_records:['online_daily_data#1001'],readback_status:'readback_verified'}]}));
  for(const marker of ['覆盖：5/7','全期间值：不可计算','非全期间金额：522 CNY','2026-09-02、2026-09-04','online_daily_data#1001','readback_verified']) assert.ok(text.includes(marker),marker);
});
test('zero denominator and incomparable windows remain explicit',()=>{
  const window={status:'ready',date_start:'2026-09-01',date_end:'2026-09-01',value:0,unit:'CNY',coverage:{available_days:1,expected_days:1,missing_dates:[]}};
  const text=content(renderPrecisePeriodEvidence({unit:'CNY',comparison:{current:{...window,value:100},baseline:window,difference:100,change_percent:null,blocked_reason:'对比期为0，变化率不可计算'}}));
  assert.ok(text.includes('变化率：不可计算'));assert.ok(text.includes('差额：100 CNY'));assert.ok(text.includes('对比期为0'));
});
