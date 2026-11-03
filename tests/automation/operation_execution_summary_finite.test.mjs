import assert from 'node:assert/strict';import fs from 'node:fs';import test from 'node:test';import vm from 'node:vm';
const context={window:{},console};vm.runInNewContext(fs.readFileSync('public/operation-static.js','utf8'),context);const api=context.window.SUXI_OPERATION_STATIC;
const summary=()=>({total:2,approved:2,executed:2,evidence_ready:2,roi_ready:2,roi_percent_ready:2,revenue_lift_ready:0,approval_rate:100,execution_rate:100,evidence_rate:100,total_profit:200,total_incremental_revenue:600,avg_roi:50,avg_revenue_lift:null,money_status:'profit_positive'});
const card=(s,label)=>api.buildOperationExecutionSummaryCards(s).find(c=>c.label===label),metric=reason=>({status:'overflow',failure_reason:reason,item_ids:[4,5],source_refs:['private_metrics#4','private_metrics#5']});
test('overflow renders unknown net profit with a field reason and relevant IDs; independent cards retained',()=>{
 const s=summary();s.total_profit=null;s.total_incremental_revenue=null;s.money_status='profit_unverified';s.financial_metrics={total_profit:metric('aggregate_overflow'),total_incremental_revenue:metric('aggregate_overflow')};
 assert.equal(card(s,'净收益').value,'-');assert.match(card(s,'净收益').hint,/净收益：汇总超出可计算范围.*#4.*#5.*增量收入：汇总超出/);assert.equal(card(s,'执行率').value,'100%');assert.equal(card(s,'平均 ROI').value,'50%');assert.match(api.operationExecutionMoneyStatusText(s.money_status),/暂不能判断盈亏/);
 const trace=api.buildOperationExecutionTraceRows(s).find(r=>r.key==='roi');assert.equal(trace.value,'2个样本');assert.match(trace.className,/amber/);assert.match(trace.detail,/逐项样本保留.*超出可计算范围/);
});
test('missing and nonfinite field reasons are visible without pretending source retrieval failed',()=>{
 for(const [reason,text]of [['aggregate_input_missing','输入缺失'],['aggregate_input_non_finite','不可计算收益']]){const s=summary();s.avg_roi=null;s.financial_metrics={avg_roi:metric(reason)};assert.equal(card(s,'平均 ROI').value,'-');assert.match(card(s,'平均 ROI').hint,new RegExp(text));assert.equal(card(s,'净收益').value,'¥200');}
});
test('same-scope refresh correction removes failure hints and restores original success projection',()=>{
 const s=summary();s.financial_metrics={total_profit:{status:'ready',failure_reason:null,item_ids:[4,5]}};assert.equal(card(s,'净收益').value,'¥200');assert.doesNotMatch(card(s,'净收益').hint,/无法|缺失|超出/);const trace=api.buildOperationExecutionTraceRows(s).find(r=>r.key==='roi');assert.match(trace.className,/emerald/);assert.doesNotMatch(trace.detail,/汇总暂不能/);
});
test('legacy responses and explicit zero remain valid; no samples stay missing',()=>{
 const s=summary();s.avg_roi=0;s.total_profit=0;s.total_incremental_revenue=0;assert.equal(card(s,'平均 ROI').value,'0%');assert.equal(card(s,'净收益').value,'¥0');assert.equal(card(s,'价格 Lift').value,'-');s.financial_metrics={avg_revenue_lift:{status:'missing',failure_reason:'no_roi_samples'}};assert.equal(card(s,'价格 Lift').hint,'金额样本 0');
});
test('unread dependency continues taking precedence over financial calculations',()=>{
 const flow={statistics:{task_status_loaded:true,evidence_loaded:false,roi_loaded:false},summary:{...summary(),financial_metrics:{total_profit:metric('aggregate_overflow')}},list:[],stages:[]};const model=api.buildOperationExecutionReadback(flow);assert.equal(api.buildOperationExecutionTraceRows(model.summary).find(r=>r.key==='roi').value,'未读取');assert.equal(model.summary.money_status,'read_unavailable');assert.equal(model.summary.executed,null);
});
test('financial failure keeps independently known increment and sample counts visible',()=>{
 const s=summary();s.total_profit=null;s.avg_roi=null;s.financial_metrics={total_profit:metric('aggregate_input_missing'),avg_roi:metric('aggregate_input_missing')};
 assert.match(card(s,'净收益').hint,/增量收入 ¥600/);assert.match(card(s,'净收益').hint,/净收益.*输入缺失/);assert.match(card(s,'平均 ROI').hint,/百分比样本 2/);assert.match(card(s,'平均 ROI').hint,/输入缺失/);
});
test('unrepresentable residual displays explicit precision gap instead of zero',()=>{
 const s=summary();s.total_profit=null;s.financial_metrics={total_profit:metric('aggregate_precision_loss')};assert.equal(card(s,'净收益').value,'-');assert.match(card(s,'净收益').hint,/汇总精度不足/);assert.match(card(s,'净收益').hint,/增量收入 ¥600/);
});
