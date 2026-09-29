import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const source = fs.readFileSync('public/operation-static.js', 'utf8');
const ctx = { window: {} }; vm.createContext(ctx); vm.runInContext(source, ctx);
const view = (baseline, form={hotel_id:'7',start_date:'2026-09-01',end_date:'2026-09-03'}) =>
  ctx.window.SUXI_OPERATION_STATIC.buildOperationStrategyEvidence(baseline, form, [{id:7,name:'示例酒店'}]);

test('strategy result shows selected hotel, plan dates and distinct observed baseline window', () => {
  const result = view({window_start_date:'2026-08-01',window_end_date:'2026-08-30',actual_days:30,days:30,data_status:'ok',metric_identities:{}});
  assert.match(result.hotel, /示例酒店.*7/);
  assert.equal(result.planDates, '2026-09-01 至 2026-09-03');
  assert.equal(result.baselineDates, '2026-08-01 至 2026-08-30');
  assert.equal(result.coverage, '30/30 天'); assert.equal(result.status, '完整');
});
test('different metric scopes retain the exact platform, source and daily-average grain', () => {
  const result = view({data_status:'partial',actual_days:1,days:30,metric_identities:{
    orders:[{scope:'whole_hotel_daily_report',platform:'',source:'daily_reports',measurement_grain:'daily_average'}],
    revenue:[{scope:'ota_channel',platform:'ctrip',source:'ctrip_import',measurement_grain:'daily_average'}],
    conversion:[{scope:'ota_channel',platform:'meituan',source:'meituan_import',measurement_grain:'daily_average'}],
  }});
  assert.equal(result.status, '部分'); assert.equal(result.coverage, '1/30 天');
  assert.match(result.metrics.find(row=>row.label==='订单').detail,/全店日报.*daily_reports.*日均/);
  assert.match(result.metrics.find(row=>row.label==='收入').detail,/携程渠道.*ctrip_import.*日均/);
  assert.match(result.metrics.find(row=>row.label==='转化率').detail,/美团渠道.*meituan_import.*日均/);
  assert.match(result.metrics.find(row=>row.label==='间夜').detail,/未返回/);
});
test('missing, read-failed and old response fields stay missing without inventing a zero or scope', () => {
  const result = view({data_status:'read_failed',actual_days:0,days:30,metric_identities:{}},{hotel_id:'9',start_date:'',end_date:''});
  assert.equal(result.status, '读取失败'); assert.equal(result.coverage, '0/30 天');
  assert.match(result.hotel, /酒店 ID 9.*名称未返回/);
  assert.match(result.planDates, /日期未返回/); assert.match(result.baselineDates, /日期未返回/);
  assert.ok(result.metrics.every(row=>/未返回/.test(row.detail)));
  const old = view({}); assert.equal(old.coverage, '覆盖未返回'); assert.equal(old.status, '状态未返回');
});

test('overall date coverage does not hide incomplete per-metric samples or baseline gaps', () => {
  const whole={scope:'whole_hotel_daily_report',platform:'',source:'daily_reports',measurement_grain:'daily_average'};
  const result=view({actual_days:30,days:30,data_status:'partial',metric_identities:{orders:[whole],revenue:[whole]},
    metric_sample_days:{orders:30,revenue:1,room_nights:0},data_gaps:[{code:'baseline_revenue_incomplete',message:'收入仅覆盖 1/30 个请求日期'}]});
  assert.equal(result.coverage,'30/30 天');
  assert.match(result.metrics.find(row=>row.label==='收入').detail,/样本 1\/30 天/);
  assert.match(result.metrics.find(row=>row.label==='间夜').detail,/样本 0\/30 天/);
  assert.match(result.metrics.find(row=>row.label==='转化率').detail,/样本未返回/);
  assert.match(result.gaps.join(' '),/收入仅覆盖 1\/30 个请求日期/);
  const old=view({});
  assert.ok(old.metrics.every(row=>/样本未返回/.test(row.detail)));
  assert.deepEqual(Array.from(old.gaps),[]);
});
