import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
const sandbox={window:{},console};
vm.runInNewContext(readFileSync('public/meituan-static.js','utf8'),sandbox);
const api=sandbox.window.SUXI_MEITUAN_STATIC;
const row=(id,extra={})=>({id,source:'meituan',system_hotel_id:80,data_date:`2026-09-${24+id}`,
  data_type:'review',dimension:'review:meituan',...extra});

test('review subtotals retain zero and disclose independent metric coverage',()=>{
  const data=api.buildMeituanDownloadData([row(1,{comment_score:4.8,quantity:12,data_value:0}),row(2,{comment_score:4.2,quantity:null,data_value:null})]);
  assert.equal(data.reviewAverageScore,4.5);
  assert.equal(data.reviewTotalCount,12);
  assert.equal(data.reviewBadCount,0);
  assert.equal(data.reviewCoverage.score.status,'complete');
  assert.equal(data.reviewCoverage.count.observed,1);
  assert.equal(data.reviewCoverage.badCount.missing,1);
  assert.match(data.reviewCoverage.count.label,/部分小计.*1\/2 条/);
});

test('absent review metrics and absent records remain different from complete real zero',()=>{
  const missing=api.buildMeituanDownloadData([row(1,{comment_score:null,quantity:null,data_value:null})]);
  assert.equal(missing.reviewAverageScore,null);
  assert.equal(missing.reviewTotalCount,null);
  assert.equal(missing.reviewBadCount,null);
  assert.equal(missing.reviewCoverage.count.label,'未取得 · 0/1 条');
  const empty=api.buildMeituanDownloadData([]);
  assert.equal(empty.reviewCoverage.count.label,'暂无记录');
  const zero=api.buildMeituanDownloadData([row(1,{quantity:0,data_value:0})]);
  assert.equal(zero.reviewTotalCount,0);
  assert.equal(zero.reviewBadCount,0);
  assert.equal(zero.reviewCoverage.count.label,'当前页 1/1 条');
});

test('partial score mean stays an arithmetic record mean with separate coverage',()=>{
  const data=api.buildMeituanDownloadData([row(1,{comment_score:4,quantity:100}),row(2,{comment_score:5,quantity:1}),row(3,{quantity:7})]);
  assert.equal(data.reviewAverageScore,4.5);
  assert.equal(data.reviewCoverage.score.observed,2);
  assert.equal(data.reviewCoverage.score.total,3);
  assert.match(data.reviewCoverage.score.label,/部分记录.*2\/3 条/);
  assert.doesNotMatch(data.reviewCoverage.score.label,/小计/);
});

test('coverage counts displayed deduplicated facts and preserves legacy alias semantics',()=>{
  const first=row(1,{comment_score:null,quantity:null,data_value:null,raw_data:JSON.stringify({metrics:{score:4.6,reviewCount:12,badReviewCount:0}})});
  const data=api.buildMeituanDownloadData([first,{...first,id:99},row(2,{source:'ctrip',quantity:5})]);
  assert.equal(data.reviewRowsCount,1);
  assert.equal(data.reviewCoverage.score.total,1);
  assert.equal(data.reviewCoverage.count.observed,1);
  assert.equal(data.reviewCoverage.badCount.observed,1);
  assert.equal(data.reviewBadCount,0);
});
