import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
const context = { window: {} };
vm.runInNewContext(readFileSync('public/data-health-static.js','utf8'),context);
const api=context.window.SUXI_DATA_HEALTH_STATIC;
test('poll failure preserves original task and unknown counts without manufacturing task failure or completion',()=>{
  const row=api.manualFetchTaskStatusAfterPollingError({taskId:'original-task-41',error:new TypeError('Synthetic network failure')});
  assert.equal(row.taskId,'original-task-41'); assert.equal(row.status,'unknown'); assert.equal(row.done,false);
  assert.equal(row.savedCount,null); assert.equal(row.savedCountKnown,false);
  assert.equal(row.readbackCount,null); assert.equal(row.readbackVerified,false);
  const summary=api.summarizeManualFetchTaskStatuses([row]);
  assert.equal(summary.status,'result_unknown'); assert.equal(summary.savedCount,null);
  assert.equal(summary.pendingCount,1); assert.match(summary.message,/原任务.*勿重复提交/);
});
test('only matching original-task progress may retain known partial persistence evidence',()=>{
  const last={taskId:'task-1',hotelId:'80',status:'running',savedCount:3,readbackCount:2,readbackVerified:true};
  const matched=api.manualFetchTaskStatusAfterPollingError({taskId:'task-1',lastKnownStatus:last});
  assert.equal(matched.hotelId,'80'); assert.equal(matched.savedCount,3); assert.equal(matched.savedCountKnown,true);
  assert.equal(matched.readbackCount,2); assert.equal(matched.readbackVerified,false); assert.equal(matched.done,false);
  const other=api.manualFetchTaskStatusAfterPollingError({taskId:'task-2',lastKnownStatus:last});
  assert.equal(other.hotelId,''); assert.equal(other.savedCount,null); assert.equal(other.readbackCount,null);
  const summary=api.summarizeManualFetchTaskStatuses([matched,other]);
  assert.equal(summary.savedCount,3); assert.equal(summary.savedCountKnown,false); assert.equal(summary.status,'result_unknown');
});
test('explicit zero remains a known value while missing, negative and invalid counts stay unknown',()=>{
  const zero=api.normalizeManualFetchTaskStatus({saved_count:0,readback_count:0});
  assert.equal(zero.savedCount,0); assert.equal(zero.savedCountKnown,true);
  for(const value of [null,undefined,'',false,-1,1.5,'bad']) {
    const row=api.normalizeManualFetchTaskStatus({saved_count:value,readback_count:value});
    assert.equal(row.savedCount,null); assert.equal(row.readbackCount,null); assert.equal(row.savedCountKnown,false);
  }
});
test('success report without known equal counts and independent readback proof stays unverified',()=>{
  for(const input of [
    {status:'success'},
    {status:'success',saved_count:2},
    {status:'success',saved_count:2,readback_count:2},
    {status:'success',saved_count:2,readback_count:1,readback_verified:true},
    {status:'success',saved_count_known:false,saved_count:2,readback_count:2,readback_verified:true},
    {status:'success',saved_count:2,readback_count_known:false,readback_count:2,readback_verified:true},
  ]) {
    const summary=api.summarizeManualFetchTaskStatuses([{task_id:'original-task-proof',...input}]);
    assert.equal(summary.status,'unverified');
    assert.equal(summary.readbackVerified,false);
    assert.equal(summary.statuses[0].taskId,'original-task-proof');
    assert.match(summary.message,/精确回读证据未核验.*核验原任务/);
    assert.doesNotMatch(summary.message,/null 条|undefined 条|已完成并通过回读/);
  }
});
test('explicit verified zero reports no new persistence without calling it an import success',()=>{
  const summary=api.summarizeManualFetchTaskStatuses([{task_id:'original-empty-task',status:'success',saved_count:0,readback_count:0,readback_verified:true}]);
  assert.equal(summary.status,'no_saved');
  assert.equal(summary.savedCount,0); assert.equal(summary.savedCountKnown,true);
  assert.equal(summary.readbackCount,0); assert.equal(summary.readbackCountKnown,true);
  assert.match(summary.message,/没有新增入库/);
  assert.doesNotMatch(summary.message,/null 条|已完成并通过回读/);
});
test('mixed partial evidence keeps known lower bounds and never renders an unknown count as zero or null',()=>{
  for(const count of [null,3]) {
    const summary=api.summarizeManualFetchTaskStatuses([
      {task_id:'saved-part',status:'success',saved_count:count},
      {task_id:'failed-part',status:'failed'},
    ]);
    assert.equal(summary.status,'partial'); assert.equal(summary.savedCount,count);
    assert.equal(summary.savedCountKnown,false);
    assert.doesNotMatch(summary.message,/null 条|undefined 条|入库 0 条/);
    assert.match(summary.message,count===null ? /入库条数待核验/ : /已知至少入库 3 条/);
  }
});
