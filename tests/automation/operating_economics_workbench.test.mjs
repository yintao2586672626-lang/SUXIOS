import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { randomUUID } from 'node:crypto';
const source = readFileSync(new URL('../../public/components/system/operating-economics-workbench.js', import.meta.url), 'utf8');
function component(request = async () => ({ code:200,data:{} }), code = source) {
    const window = { confirm:()=>true, dispatchEvent:event=>window.event=event };
    new Function('window','crypto','CustomEvent','Vue',code)(window,{randomUUID},class { constructor(type,opts){this.type=type;this.detail=opts.detail;} },new Proxy({}, {get:()=>()=>({})}));
    const definition = window.SUXI_SYSTEM_COMPONENTS.OperatingEconomicsWorkbench;
    const ctx = {...definition.data(),hotelId:80,periodMonth:'2026-10',platform:'ctrip',canExecute:true,request};
    for(const [key,fn] of Object.entries(definition.methods))ctx[key]=fn.bind(ctx);
    for(const [key,fn] of Object.entries(definition.computed))Object.defineProperty(ctx,key,{get:()=>fn.call(ctx)});
    return {ctx,window,definition};
}
test('unverified or draft actual cost cannot be adopted; verified zero can',()=>{
    const {ctx}=component();ctx.kind='consumables_actual';ctx.result={status:'calculated',inputs:{occupied_room_nights_source_ref:'synthetic-pms'},actual_consumables_cost_per_room_night:0};
    ctx.saved={readback_verified:true,source_quality:'unverified'};assert.equal(ctx.canAdopt,false);
    ctx.saved.source_quality='operator_attested';assert.equal(ctx.canAdopt,true);ctx.edit();assert.equal(ctx.canAdopt,false);
});
test('adoption retains same-hotel snapshot reference for next page',()=>{
    const {ctx,window}=component();ctx.kind='consumables_actual';ctx.result={status:'calculated',inputs:{occupied_room_nights_source_ref:'synthetic-pms'},actual_consumables_cost_per_room_night:2};
    ctx.saved={snapshot_id:9,content_digest:'a'.repeat(64),readback_verified:true,source_quality:'operator_attested'};ctx.adopt();
    assert.equal(window.SUXI_PENDING_ACTUAL_CONSUMABLES_REFERENCE.hotel_id,80);assert.equal(window.event.detail.snapshot_id,9);
});
test('editing invalidates in-flight preview without clearing dirty input',async()=>{
    let resolve;const {ctx}=component(()=>new Promise(r=>resolve=r));const pending=ctx.calculate(false);ctx.edit();resolve({code:200,data:{result:{net_revenue:999}}});await pending;
    assert.equal(ctx.result,null);assert.equal(ctx.dirty,true);assert.equal(ctx.busy,false);
});
test('same-version save must have exact scoped readback',async()=>{
    const {ctx}=component(async()=>({code:200,data:{readback_verified:true,scope:{hotel_id:81},result:{}}}));await ctx.calculate(true);
    assert.match(ctx.error,/范围不一致/);assert.equal(ctx.saved,null);
});
test('stale history refresh cannot cross a changed hotel',async()=>{
    let resolve;let calls=0;const {ctx}=component(async()=>++calls===1?{code:200,data:{readback_verified:true,scope:{hotel_id:80,period_month:'2026-10',platform:'ctrip',kind:'channel_economics'},result:{}}}:new Promise(r=>resolve=r));
    const pending=ctx.calculate(true);while(calls<2)await Promise.resolve();ctx.seq++;ctx.overview=null;resolve({code:200,data:{hotel_id:80}});await pending;assert.equal(ctx.overview,null);
});
test('view-only caller cannot invoke save',async()=>{
    let calls=0;const {ctx}=component(async()=>{calls++;return {code:200,data:{}};});ctx.canExecute=false;await ctx.calculate(true);assert.equal(calls,0);
});
test('served compiled artifact preserves literal API booleans',()=>{
    const {ctx}=component(undefined,readFileSync(new URL('../../public/components/system/operating-economics-workbench.min.js',import.meta.url),'utf8'));
    assert.equal(ctx.channel.advertising_included_in_net_revenue,false);assert.equal(ctx.channel.advertising_in_direct_costs,false);
    assert.equal(ctx.newItem().enabled,true);assert.equal(ctx.actual.operator_attested,false);
});
test('old saved version without room-night provenance remains ineligible for adoption',()=>{
    const {ctx}=component();ctx.kind='consumables_actual';ctx.saved={readback_verified:true,source_quality:'operator_attested'};ctx.result={status:'calculated',actual_consumables_cost_per_room_night:2};assert.equal(ctx.canAdopt,false);
});

for (const [field, wrong] of Object.entries({hotel_id:81, period_month:'2026-09', platform:'meituan', kind:'consumables_actual'})) {
    test(`overview and preview reject a mismatched ${field} instead of displaying foreign results`,async()=>{
        const scope={hotel_id:80,period_month:'2026-10',platform:'ctrip',kind:'channel_economics',[field]:wrong};
        const {ctx}=component(async()=>({code:200,data:{scope,history:[{snapshot_id:999}],result:{net_revenue:999}}}));
        await ctx.load(); assert.equal(ctx.overview,null); assert.match(ctx.error,/范围不一致/);
        await ctx.calculate(false); assert.equal(ctx.result,null); assert.equal(ctx.saved,null); assert.match(ctx.error,/范围不一致/);
    });
}

test('failed overview refresh removes an earlier successful history rather than presenting it as current',async()=>{
    const {ctx}=component(async()=>{throw new Error('来源读取失败');});ctx.overview={history:[{snapshot_id:9}]};
    await ctx.load();assert.equal(ctx.overview,null);assert.match(ctx.error,/来源读取失败/);
});

test('history restore must return the requested snapshot id as well as the correct scope',async()=>{
    const {ctx}=component(async()=>({code:200,data:{snapshot_id:8,readback_verified:true,scope:{hotel_id:80,period_month:'2026-10',platform:'ctrip',kind:'channel_economics'},inputs:{source_refs:[]},result:{net_revenue:999}}}));
    await ctx.restore(9);assert.equal(ctx.saved,null);assert.equal(ctx.result,null);assert.match(ctx.error,/版本不一致/);
});

test('matching overview, preview and historical snapshot remain usable with an additional server tenant identity',async()=>{
    const scope={tenant_id:10,hotel_id:80,period_month:'2026-10',platform:'ctrip',kind:'channel_economics'};
    const {ctx}=component(async path=>({code:200,data:path.includes('overview')?{scope,history:[]}:{scope,snapshot_id:9,readback_verified:true,inputs:{source_refs:[]},result:{net_revenue:0}}}));
    await ctx.load();assert.deepEqual(ctx.overview.scope,scope);await ctx.calculate(false);assert.equal(ctx.result.net_revenue,0);assert.equal(ctx.saved,null);
    await ctx.restore(9);assert.equal(ctx.saved.snapshot_id,9);assert.equal(ctx.result.net_revenue,0);assert.equal(ctx.error,'');
});

test('unavailable Ctrip marketing coverage does not display an empty list as zero missing days',()=>{
    const {ctx}=component();ctx.overview={sources:{marketing:{complete:false,covered_days:[],missing_days:[],reason:'ctrip_marketing_period_requires_manual_evidence'}}};
    assert.match(ctx.marketingCoverageMessage,/携程.*人工资料/);assert.doesNotMatch(ctx.marketingCoverageMessage,/缺 0 天/);
});

test('unknown marketing day coverage is distinct from verified complete zero gaps and known missing days',()=>{
    const {ctx}=component();ctx.overview={sources:{marketing:{complete:false,missing_days:[]}}};assert.match(ctx.marketingCoverageMessage,/缺失天数未取得/);
    ctx.overview.sources.marketing={complete:true,missing_days:[]};assert.match(ctx.marketingCoverageMessage,/已取得.*缺 0 天/);
    ctx.overview.sources.marketing={complete:false,missing_days:['2026-10-01']};assert.match(ctx.marketingCoverageMessage,/尚不完整.*缺 1 天/);
});
