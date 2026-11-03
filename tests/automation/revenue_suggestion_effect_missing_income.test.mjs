import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const source = fs.readFileSync('public/app-main.js', 'utf8');
const start = source.indexOf('const optionalFiniteNumber = (value) => {', source.indexOf('const priceSuggestionReview = ref(null);'));
const end = source.indexOf('const competitorAlertPriceText =', start);
assert.ok(start >= 0 && end > start);
const functions = source.slice(start, end);
const before = () => ({system_hotel_id:80,platforms:['ctrip'],source:'online_daily_data',scope:'online_ota_operating_sample',metric_scope:'ota_channel',sample_count:7,data_status:'ok',start_date:'2026-09-01',end_date:'2026-09-07'});
const after = () => ({...before(),start_date:'2026-09-08',end_date:'2026-09-14'});
function render(first, second, amount = 900, adr = 60) {
  const context = {priceSuggestionReview:{value:{before:first,after:second,delta:{amount,adr}}},computed:fn=>({get value(){return fn();}})};
  vm.createContext(context);
  return vm.runInContext(functions + `({comparable:priceSuggestionReviewHasComparableSamples.value,amount:priceSuggestionReviewMetricText(${JSON.stringify(amount)},'¥'),adr:priceSuggestionReviewMetricText(${JSON.stringify(adr)},'¥')})`, context);
}
for (const side of ['before','after']) {
  test(`${side} partial numeric receipt hides income and ADR lift`, () => {
    const first=before(),second=after();(side==='before'?first:second).data_status='partial';
    const result=render(first,second);
    assert.equal(result.comparable,false);assert.equal(result.amount,'—');assert.equal(result.adr,'—');
  });
}
for (const status of ['read_failed','blocked','missing','no_sample','unverified','stale']) {
  test(`${status} evidence is not displayed as a lift`, () => {
    const result=render({...before(),data_status:status},after());
    assert.equal(result.comparable,false);assert.equal(result.amount,'—');assert.equal(result.adr,'—');
  });
}
for (const [name,key,value] of [
  ['hotel','system_hotel_id',81],['platform','platforms',['meituan']],
  ['unknown platform','platforms',[]],['metric scope','metric_scope','whole_hotel'],
  ['source','source','manual_input'],['sample scope','scope','whole_hotel'],
]) {
  test(`different ${name} hides comparison`, () => {
    const result=render(before(),{...after(),[key]:value});
    assert.equal(result.comparable,false);assert.equal(result.amount,'—');assert.equal(result.adr,'—');
  });
}
for (const [name,start_date,end_date] of [
  ['overlap','2026-09-07','2026-09-13'],['non adjacent','2026-09-09','2026-09-15'],
  ['unequal windows','2026-09-08','2026-09-13'],['reversed','2026-09-14','2026-09-08'],
  ['invalid calendar','2026-02-30','2026-03-08'],
]) {
  test(`${name} hides comparison`, () => {
    const result=render(before(),{...after(),start_date,end_date});
    assert.equal(result.comparable,false);assert.equal(result.amount,'—');
  });
}
test('complete adjacent scopes preserve valid lift and explicit zero', () => {
  const zero=render(before(),after(),0,0),lift=render(before(),after(),1500,100);
  assert.equal(zero.comparable,true);assert.equal(zero.amount,'¥0');assert.equal(zero.adr,'¥0');
  assert.equal(lift.amount,'¥1,500');assert.equal(lift.adr,'¥100');
});
test('null remains visibly missing with complete periods', () => {
  const result=render(before(),after(),null,null);assert.equal(result.amount,'—');assert.equal(result.adr,'—');
});
test('incomparable notice explicitly includes incomplete data and scope', () => {
  const page=fs.readFileSync('resources/frontend/templates/fragments/27-page-agent-center.html','utf8');
  assert.match(page,/应用前后数据不全、口径不可比或样本不足，不计算收入、间夜或 ADR 变化/);
});
