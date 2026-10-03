import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { randomUUID } from 'node:crypto';
import * as Vue from 'vue';
import { compile } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';
const source = readFileSync(new URL('../../public/components/system/operating-economics-workbench.js', import.meta.url), 'utf8');
const scopedReceipt = () => ({scope:{tenant_id:7,hotel_id:80,period_month:'2026-10',platform:'ctrip',kind:'channel_economics'},snapshot_id:9,content_digest:'a'.repeat(64),readback_verified:true,source_quality:'unverified',inputs:{source_refs:[],net_revenue:0},result:{net_revenue:0}});
function component(request = async () => ({ code:200,data:{} }), code = source, browser = {}) {
    const window = { confirm:()=>true, dispatchEvent:event=>window.event=event };
    class ShanghaiClock extends Date { constructor(...args) { super(...(args.length ? args : ['2026-10-02T16:30:00Z'])); } }
    new Function('window','crypto','CustomEvent','Vue','document','URL','setTimeout','Date',code)(window,{randomUUID},class { constructor(type,opts){this.type=type;this.detail=opts.detail;} },new Proxy({}, {get:()=>()=>({})}),browser.document,browser.URL || globalThis.URL,browser.setTimeout || globalThis.setTimeout,ShanghaiClock);
    const definition = window.SUXI_SYSTEM_COMPONENTS.OperatingEconomicsWorkbench;
    const ctx = {...definition.data(),hotelId:80,periodMonth:'2026-10',platform:'ctrip',canExecute:true,request};
    for(const [key,fn] of Object.entries(definition.methods))ctx[key]=fn.bind(ctx);
    for(const [key,fn] of Object.entries(definition.computed))Object.defineProperty(ctx,key,{get:()=>fn.call(ctx)});
    return {ctx,window,definition};
}

function exportComponent(request) {
    const blobs=[];const downloads=[];const revoked=[];
    const browser={document:{createElement(){return {click(){downloads.push({href:this.href,name:this.download});}};}},
        URL:{createObjectURL(blob){blobs.push(blob);return 'test-only:operating-export';},revokeObjectURL(url){revoked.push(url);}},
        setTimeout(callback){callback();}};
    return {...component(request,source,browser),blobs,downloads,revoked};
}

const scopedOverview = path => ({code:200,data:{scope:Object.fromEntries(new URLSearchParams(path.split('?')[1])),history:[]}});
async function changeScope(ctx, definition, change) {
    const previous = {...ctx.scope}; Object.assign(ctx, change);
    definition.watch.scope.handler.call(ctx, {...ctx.scope}, previous);
    await new Promise(setImmediate);
}

test('switching calculation tabs retains each draft and its unsaved restore confirmation',async()=>{
    const calls=[]; const {ctx,definition}=component(async path=>{calls.push(path); return scopedOverview(path);});
    ctx.channel.net_revenue='1234'; ctx.channel.source_refs='TEST-ONLY-channel'; ctx.edit();
    await changeScope(ctx,definition,{kind:'consumables_actual'});
    ctx.actual.occupied_room_nights='100'; ctx.actual.occupied_room_nights_source_ref='TEST-ONLY-PMS'; ctx.edit();
    await changeScope(ctx,definition,{kind:'channel_economics'});
    assert.equal(ctx.channel.net_revenue,'1234'); assert.equal(ctx.channel.source_refs,'TEST-ONLY-channel');
    assert.equal(ctx.actual.occupied_room_nights,'100'); assert.equal(ctx.dirty,true);
    const before=calls.length; await ctx.restore(9);
    assert.equal(ctx.restoreRequested,9); assert.equal(calls.length,before,'pending confirmation must not discard the retained draft');
    await changeScope(ctx,definition,{kind:'consumables_actual'});
    assert.equal(ctx.actual.occupied_room_nights_source_ref,'TEST-ONLY-PMS'); assert.equal(ctx.dirty,true);
    assert.equal(ctx.saved,null); assert.equal(ctx.result,null);
});

for (const change of [{hotelId:81},{periodMonth:'2026-11'}]) test('actual hotel or month change clears both retained drafts '+Object.keys(change)[0],async()=>{
    const {ctx,definition}=component(async path=>scopedOverview(path));
    ctx.channel.net_revenue='1234'; ctx.actual.occupied_room_nights='100'; ctx.edit();
    await changeScope(ctx,definition,change);
    assert.equal(ctx.channel.net_revenue,''); assert.equal(ctx.actual.occupied_room_nights,'');
    assert.equal(ctx.dirty,false); assert.equal(ctx.saved,null); assert.equal(ctx.result,null);
});

test('a source change while the actual-cost tab is open cannot relabel the retained channel draft',async()=>{
    const {ctx,definition}=component(async path=>scopedOverview(path));
    ctx.channel.net_revenue='1234'; ctx.edit();
    await changeScope(ctx,definition,{kind:'consumables_actual'});
    ctx.actual.occupied_room_nights='100'; ctx.edit(); ctx.platform='meituan';
    await changeScope(ctx,definition,{kind:'channel_economics'});
    assert.equal(ctx.channel.net_revenue,''); assert.equal(ctx.actual.occupied_room_nights,'100');
    assert.equal(ctx.scope.platform,'meituan'); assert.equal(ctx.dirty,false);
    await changeScope(ctx,definition,{kind:'consumables_actual'});
    assert.equal(ctx.actual.occupied_room_nights,'100'); assert.equal(ctx.dirty,true);
});

test('tiny nonzero inventory quantities are displayed distinctly from a confirmed zero',()=>{
    const {ctx}=component();
    assert.equal(ctx.amount(0.0000004),'0.0000004'); assert.equal(ctx.amount(-0.0000004),'-0.0000004');
    assert.equal(ctx.amount(0),'0'); assert.equal(ctx.amount(null),'未取得');
    assert.equal(ctx.amount(0.004),'0.004'); assert.equal(ctx.amount(1000),'1,000');
    assert.notEqual(ctx.amount(1e-20),'0');
});

test('actual-cost results distinguish known partial totals and show quantity units and excluded rows',async()=>{
    for (const [known,total] of [[null,null],[0,0],[200,null]]) {
        const {ctx,definition}=component(); ctx.kind='consumables_actual';
        ctx.result={status:total===null?'partial':'calculated',source_quality:'operator_attested',known_consumed_cost:known,
            actual_consumed_cost:total,separate_loss_cost:null,actual_consumables_cost_per_room_night:null,
            items:[{id:'TEST-ONLY-small',name:'TEST-ONLY用量',enabled:true,unit:'ml',consumed_quantity:0.0000004,consumed_cost:0.4},
                {id:'TEST-ONLY-excluded',name:'TEST-ONLY停用行',enabled:false,unit:'piece',consumed_quantity:1,consumed_cost:99}]};
        const render=new Function('Vue',compile(definition.template,{mode:'function'}).code)(Vue);
        const html=await renderToString(Vue.createSSRApp({render(){return render(ctx,[]);}}));
        assert.ok(html.includes(`已知耗用 ${known===null?'未取得':known} 元`));
        assert.ok(html.includes(`经营耗用 ${total===null?'未取得':total} 元`));
        assert.match(html,/用量 0\.0000004 毫升/); assert.match(html,/未纳入本期/);
        assert.equal(ctx.canAdopt,false,'a partial or unsaved displayed subtotal cannot become an investment reference');
    }
});

for (const changed of [{snapshot_id:10},{content_digest:'b'.repeat(64)},{inputs:{source_refs:[],net_revenue:999}},{result:{net_revenue:999}},{readback_verified:false},{scope:{hotel_id:81}},{scope:{...scopedReceipt().scope,tenant_id:8}},{source_quality:'operator_attested'}]) {
    test('save independently rereads its exact version and rejects changed '+Object.keys(changed)[0],async()=>{
        const calls=[];const {ctx}=component(async path=>{calls.push(path);return {code:200,data:path.includes('/snapshots/9?')?{...scopedReceipt(),...changed}:scopedReceipt()};});
        await ctx.calculate(true);
        assert.equal(ctx.saved,null);assert.equal(ctx.result,null);assert.equal(ctx.canAdopt,false);
        assert.ok(calls.some(path=>path.includes('/snapshots/9?')),'must independently GET the acknowledged snapshot');assert.ok(ctx.error);
    });
}

test('save adopts independently reread result only after exact identity and content match',async()=>{
    const calls=[];const {ctx}=component(async path=>{calls.push(path);return {code:200,data:path.includes('/overview?')?{scope:scopedReceipt().scope,history:[]}:scopedReceipt()};});
    await ctx.calculate(true);assert.equal(ctx.saved.snapshot_id,9);assert.equal(ctx.result.net_revenue,0);assert.equal(ctx.error,'');
    assert.match(calls[1],/\/snapshots\/9\?/);assert.match(ctx.notice,/精确回读/);
});

test('new snapshot request digest must match independent GET while legacy missing digests remain compatible',async()=>{
    for(const match of [true,false]) {
        const receipt={...scopedReceipt(),request_digest:'c'.repeat(64)};
        const {ctx}=component(async path=>({code:200,data:path.includes('/overview?')?{scope:receipt.scope,history:[]}
            :path.includes('/snapshots/9?')?{...receipt,request_digest:match?receipt.request_digest:'d'.repeat(64)}:receipt}));
        await ctx.calculate(true);
        assert.equal(ctx.writeReceipt.status,match?'verified':'readback_failed');
        if(match)assert.equal(ctx.saved.request_digest,receipt.request_digest);
        else {assert.equal(ctx.saved,null);assert.equal(ctx.result,null);assert.match(ctx.error,/回读内容不一致/);}
    }
    const {ctx}=component(async path=>({code:200,data:path.includes('/overview?')?{scope:scopedReceipt().scope,history:[]}:scopedReceipt()}));
    await ctx.calculate(true);assert.equal(ctx.writeReceipt.status,'verified');assert.equal(ctx.saved.request_digest,undefined);
});

for (const save of [false,true]) test('server normalized input replaces stale draft after '+(save?'verified save':'preview'),async()=>{
    const receipt=scopedReceipt();receipt.inputs={...receipt.inputs,net_revenue:9999,refund_amount:null,evidence_refs_by_metric:{net_revenue:['TEST-ONLY-settlement']}};receipt.result={net_revenue:9999};
    const {ctx}=component(async path=>({code:200,data:path.includes('/overview?')?{scope:receipt.scope,history:[]}:receipt}));
    ctx.channel.net_revenue='1000';ctx.channel.refund_amount='50';await ctx.calculate(save);
    assert.equal(ctx.channel.net_revenue,9999);assert.equal(ctx.channel.refund_amount,null);assert.equal(ctx.result.net_revenue,9999);
    assert.deepEqual(ctx.input.evidence_refs_by_metric.net_revenue,['TEST-ONLY-settlement']);assert.equal(ctx.dirty,false);
});

test('failed fresh preview removes a prior successful result and adoption receipt',async()=>{
    const {ctx}=component(async()=>{throw new Error('TEST-ONLY-source-unavailable');});ctx.kind='consumables_actual';ctx.saved={readback_verified:true,source_quality:'operator_attested'};ctx.result={status:'calculated',inputs:{occupied_room_nights_source_ref:'TEST-ONLY'},actual_consumables_cost_per_room_night:2};
    await ctx.calculate(false);assert.equal(ctx.result,null);assert.equal(ctx.saved,null);assert.equal(ctx.resultCurrent,null);assert.equal(ctx.canAdopt,false);assert.equal(ctx.dirty,true);
});

for(const quality of ['operator_attested','unverified']) test('exported '+quality+' preview retains its current result quality and remains unsaved',async()=>{
    const receipt=scopedReceipt();receipt.result={source_quality:quality,net_revenue:1000,evidence_chain:{independently_verified:false}};
    const {ctx,blobs,downloads,revoked}=exportComponent(async()=>({code:200,data:{scope:receipt.scope,inputs:receipt.inputs,result:receipt.result}}));
    await ctx.calculate(false);assert.equal(ctx.saved,null);ctx.exportSnapshot();
    assert.equal(blobs.length,1);assert.equal(downloads.length,1);assert.deepEqual(revoked,['test-only:operating-export']);
    const exported=JSON.parse(await blobs[0].text());
    assert.equal(exported.source_quality,quality);assert.equal(exported.source_quality,exported.result.source_quality);
    assert.equal(exported.snapshot_id,null);assert.equal(exported.snapshot_status,'preview');assert.equal(exported.readback_verified,false);
    assert.equal(exported.result.evidence_chain.independently_verified,false);assert.deepEqual(exported.scope,{...ctx.scope});
});

test('exported saved version keeps its exact snapshot identity and current result quality',async()=>{
    const receipt=scopedReceipt();receipt.source_quality='operator_attested';receipt.result={source_quality:'operator_attested',net_revenue:1000,evidence_chain:{independently_verified:false}};
    const {ctx,blobs}=exportComponent(async path=>({code:200,data:path.includes('/overview?')?{scope:receipt.scope,history:[]}:receipt}));
    await ctx.calculate(true);ctx.exportSnapshot();const exported=JSON.parse(await blobs[0].text());
    assert.equal(exported.snapshot_id,9);assert.equal(exported.snapshot_status,'saved');assert.equal(exported.readback_verified,true);
    assert.equal(exported.source_quality,'operator_attested');assert.deepEqual(exported.result,receipt.result);
    assert.equal(exported.result.evidence_chain.independently_verified,false);
});

test('no-result and edited result states do not produce any export',()=>{
    const {ctx,blobs,downloads}=exportComponent();ctx.exportSnapshot();
    assert.equal(blobs.length,0);assert.equal(downloads.length,0);
    ctx.result={source_quality:'operator_attested',net_revenue:1000};ctx.edit();ctx.exportSnapshot();
    assert.equal(blobs.length,0);assert.equal(downloads.length,0);
});

test('metric references survive input serialization and old snapshots have no invented provenance',async()=>{
    const {ctx}=component(async()=>({code:200,data:{scope:{hotel_id:80,period_month:'2026-10',platform:'ctrip',kind:'channel_economics'},snapshot_id:9,readback_verified:true,inputs:{source_refs:['legacy-general-ref'],costs:[]},result:{status:'calculated'}}}));
    ctx.setMetricSource('refund_amount',' synthetic-refund\nsynthetic-second');
    assert.deepEqual(ctx.input.evidence_refs_by_metric.refund_amount,['synthetic-refund','synthetic-second']);
    await ctx.restore(9,true);
    assert.deepEqual(ctx.input.evidence_refs_by_metric.refund_amount,[]);
    assert.equal(ctx.metricSource('refund_amount'),'');
    assert.match(ctx.evidenceText({metric:'refund_amount',status:'source_missing'}),/退款：来源缺失/);
});
test('unverified or draft actual cost cannot be adopted; verified zero can',()=>{
    const {ctx}=component();ctx.kind='consumables_actual';ctx.result={status:'calculated',inputs:{occupied_room_nights_source_ref:'synthetic-pms'},actual_consumables_cost_per_room_night:0,items:[{enabled:true,source_date:'2026-10-03'}]};
    ctx.saved={snapshot_id:9,content_digest:'a'.repeat(64),readback_verified:true,source_quality:'unverified'};assert.equal(ctx.canAdopt,false);
    ctx.saved.source_quality='operator_attested';assert.equal(ctx.canAdopt,true);ctx.edit();assert.equal(ctx.canAdopt,false);
});
test('adoption retains same-hotel snapshot reference for next page',()=>{
    const {ctx,window}=component();ctx.kind='consumables_actual';ctx.result={status:'calculated',inputs:{occupied_room_nights_source_ref:'synthetic-pms'},actual_consumables_cost_per_room_night:2,items:[{enabled:true,source_date:'2026-10-03'}]};
    ctx.saved={snapshot_id:9,content_digest:'a'.repeat(64),readback_verified:true,source_quality:'operator_attested'};ctx.adopt();
    assert.equal(window.SUXI_PENDING_ACTUAL_CONSUMABLES_REFERENCE.hotel_id,80);assert.equal(window.event.detail.snapshot_id,9);
});
test('editing invalidates in-flight preview without clearing dirty input',async()=>{
    let resolve;const {ctx}=component(()=>new Promise(r=>resolve=r));const pending=ctx.calculate(false);ctx.edit();resolve({code:200,data:{result:{net_revenue:999}}});await pending;
    assert.equal(ctx.result,null);assert.equal(ctx.dirty,true);assert.equal(ctx.busy,false);
});

test('editing during save keeps the independent write pending and exact receipt without replacing the new draft',async()=>{
    let resolvePost;const calls=[];const {ctx}=component(async(path,options)=>{
        calls.push({path,options});
        if(options?.method==='POST')return new Promise(resolve=>resolvePost=resolve);
        return path.includes('/overview?')?scopedOverview(path):{code:200,data:scopedReceipt()};
    });
    ctx.channel.net_revenue='1000';const pending=ctx.calculate(true);
    ctx.channel.net_revenue='2000';ctx.edit();
    assert.equal(ctx.writePending,true);await ctx.calculate(true);
    assert.equal(calls.filter(call=>call.options?.method==='POST').length,1,'editing cannot unlock a second save');
    resolvePost({code:200,data:scopedReceipt()});await pending;
    assert.ok(calls.some(call=>call.path.includes('/snapshots/9?')));
    assert.equal(ctx.writePending,false);assert.equal(ctx.writeReceipt.status,'verified');
    assert.equal(ctx.writeReceipt.snapshot.snapshot_id,9);assert.equal(ctx.writeReceipt.scope.hotel_id,80);
    assert.equal(ctx.channel.net_revenue,'2000');assert.equal(ctx.dirty,true);assert.equal(ctx.saved,null);assert.equal(ctx.result,null);
    assert.match(ctx.notice,/草稿已保留/);
});

test('scope changes before POST returns retain the old save receipt and reread its original scope only',async()=>{
    let resolvePost;const calls=[];const {ctx,definition}=component(async(path,options)=>{
        calls.push({path,options});if(options?.method==='POST')return new Promise(resolve=>resolvePost=resolve);
        return path.includes('/overview?')?scopedOverview(path):{code:200,data:scopedReceipt()};
    });
    const pending=ctx.calculate(true);await changeScope(ctx,definition,{hotelId:81,platform:'meituan',periodMonth:'2026-11'});
    ctx.channel.net_revenue='new-hotel-draft';ctx.edit();await ctx.calculate(true);
    assert.equal(calls.filter(call=>call.options?.method==='POST').length,1);
    resolvePost({code:200,data:scopedReceipt()});await pending;
    const exact=calls.find(call=>call.path.includes('/snapshots/9?'));
    const query=new URLSearchParams(exact.path.split('?')[1]);
    assert.equal(query.get('hotel_id'),'80');assert.equal(query.get('platform'),'ctrip');assert.equal(query.get('period_month'),'2026-10');
    assert.equal(ctx.writeReceipt.status,'verified');assert.equal(ctx.writeReceipt.scope.hotel_id,80);
    assert.equal(ctx.channel.net_revenue,'new-hotel-draft');assert.equal(ctx.dirty,true);assert.equal(ctx.saved,null);assert.equal(ctx.result,null);
    assert.equal(ctx.overview.scope.hotel_id,'81');
});

test('a failed exact GET retains the saved receipt and retries GET while preserving an edited draft',async()=>{
    let posts=0;let reads=0;const {ctx}=component(async(path,options)=>{
        if(options?.method==='POST'){posts++;return {code:200,data:scopedReceipt()};}
        if(path.includes('/snapshots/9?')&&++reads===1)throw new TypeError('Failed to fetch');
        return path.includes('/overview?')?scopedOverview(path):{code:200,data:scopedReceipt()};
    });
    ctx.channel.net_revenue='1000';await ctx.calculate(true);
    assert.equal(ctx.writeReceipt.status,'readback_failed');assert.equal(ctx.writeReceipt.snapshot.snapshot_id,9);
    ctx.channel.net_revenue='2000';ctx.edit();await ctx.calculate(true);assert.equal(posts,1);
    await ctx.retryWrite();
    assert.equal(posts,1);assert.equal(reads,2);assert.equal(ctx.writeReceipt.status,'verified');
    assert.equal(ctx.channel.net_revenue,'2000');assert.equal(ctx.dirty,true);assert.equal(ctx.saved,null);
});

test('an uncertain POST retry reuses the same idempotency key and original inputs after editing',async()=>{
    const bodies=[];const {ctx}=component(async(path,options)=>{
        if(options?.method==='POST'){bodies.push(JSON.parse(options.body));if(bodies.length===1)throw new TypeError('Failed to fetch');return {code:200,data:scopedReceipt()};}
        return path.includes('/overview?')?scopedOverview(path):{code:200,data:scopedReceipt()};
    });
    ctx.channel.net_revenue='1000';await ctx.calculate(true);assert.equal(ctx.writeReceipt.status,'unconfirmed');
    ctx.channel.net_revenue='2000';ctx.edit();await ctx.calculate(true);assert.equal(bodies.length,1);
    await ctx.retryWrite();assert.equal(bodies.length,2);
    assert.equal(bodies[0].idempotency_key,bodies[1].idempotency_key);assert.deepEqual(bodies[0].inputs,bodies[1].inputs);
    assert.equal(ctx.writeReceipt.status,'verified');assert.equal(ctx.channel.net_revenue,'2000');assert.equal(ctx.dirty,true);
});

test('an idempotency conflict remains unconfirmed while an explicit validation rejection releases a fresh save',async()=>{
    for(const code of [409,422]) {
        const bodies=[];const {ctx}=component(async(path,options)=>{
            if(options?.method==='POST'){bodies.push(JSON.parse(options.body));if(bodies.length===1)return {code,message:'TEST-ONLY rejection'};return {code:200,data:scopedReceipt()};}
            return path.includes('/overview?')?scopedOverview(path):{code:200,data:scopedReceipt()};
        });
        ctx.channel.net_revenue='1000';await ctx.calculate(true);ctx.channel.net_revenue='2000';ctx.edit();
        if(code===409){assert.equal(ctx.writeReceipt.status,'unconfirmed');await ctx.calculate(true);assert.equal(bodies.length,1);await ctx.retryWrite();assert.equal(bodies[0].idempotency_key,bodies[1].idempotency_key);}
        else {assert.equal(ctx.writeReceipt.status,'rejected');assert.equal(ctx.writeAttempt,null);await ctx.calculate(true);assert.notEqual(bodies[0].idempotency_key,bodies[1].idempotency_key);assert.equal(bodies[1].inputs.net_revenue,'2000');}
        assert.equal(ctx.writeReceipt.status,'verified');
    }
});

test('editing during exact GET keeps the pending write and visible receipt while refusing another POST',async()=>{
    let resolveRead;let posts=0;const {ctx}=component(async(path,options)=>{
        if(options?.method==='POST'){posts++;return {code:200,data:scopedReceipt()};}
        if(path.includes('/snapshots/9?'))return new Promise(resolve=>resolveRead=resolve);
        return scopedOverview(path);
    });
    const pending=ctx.calculate(true);while(!resolveRead)await Promise.resolve();
    assert.equal(ctx.writeReceipt.status,'reading');assert.equal(ctx.writeReceipt.snapshot.snapshot_id,9);
    ctx.channel.net_revenue='edited-during-read';ctx.edit();await ctx.calculate(true);await ctx.restore(7,true);
    assert.equal(posts,1);assert.equal(ctx.writePending,true);
    resolveRead({code:200,data:scopedReceipt()});await pending;
    assert.equal(ctx.writeReceipt.status,'verified');assert.equal(ctx.channel.net_revenue,'edited-during-read');assert.equal(ctx.saved,null);assert.equal(ctx.dirty,true);
});

for(const platform of ['dingdandao_pms','manual_all_channels']) test('unsupported channel '+platform+' has an explicit disabled state while whole-hotel consumables still load',async()=>{
    const calls=[];const {ctx,definition}=component(async(path,options)=>{
        calls.push({path,options});return options?.method==='POST'?{code:200,data:{scope:{...ctx.scope},inputs:{...ctx.input},result:{status:'partial'}}}:scopedOverview(path);
    });
    ctx.platform=platform;await ctx.load();await ctx.calculate(false);await ctx.calculate(true);await ctx.restore(9,true);
    assert.equal(calls.length,0);assert.equal(ctx.channelSupported,false);assert.equal(ctx.calculationSupported,false);assert.match(ctx.channelUnavailableMessage,/携程.*美团/);
    const render=new Function('Vue',compile(definition.template,{mode:'function'}).code)(Vue);
    const html=await renderToString(Vue.createSSRApp({render(){return render(ctx,[]);}}));
    assert.match(html,/channel-economics-unavailable/);assert.doesNotMatch(html,/channel-metric-source-inputs/);
    await changeScope(ctx,definition,{kind:'consumables_actual'});assert.equal(ctx.calculationSupported,true);
    assert.equal(new URLSearchParams(calls[0].path.split('?')[1]).get('platform'),'whole_hotel');
    await ctx.calculate(false);assert.equal(ctx.error,'');assert.equal(ctx.result.status,'partial');
    assert.equal(JSON.parse(calls.at(-1).options.body).platform,'whole_hotel');
});
test('same-version save must have exact scoped readback',async()=>{
    const {ctx}=component(async()=>({code:200,data:{readback_verified:true,scope:{hotel_id:81},result:{}}}));await ctx.calculate(true);
    assert.match(ctx.error,/范围不一致/);assert.equal(ctx.saved,null);
});
test('delayed exact save readback cannot cross a changed hotel',async()=>{
    let resolve;let calls=0;const {ctx}=component(async()=>++calls===1?{code:200,data:scopedReceipt()}:new Promise(r=>resolve=r));
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

test('failed historical restore invalidates the previous result and adoption receipt while retaining editable inputs',async()=>{
    for (const failure of ['network', 'identity']) {
        const {ctx}=component(async()=>{
            if(failure==='network')throw new Error('TEST-ONLY historical read unavailable');
            return {code:200,data:{...scopedReceipt(),snapshot_id:8}};
        });
        ctx.kind='consumables_actual';ctx.actual.occupied_room_nights='77';
        ctx.saved={snapshot_id:9,content_digest:'a'.repeat(64),readback_verified:true,source_quality:'operator_attested'};
        ctx.result={status:'calculated',inputs:{occupied_room_nights_source_ref:'TEST-ONLY PMS'},actual_consumables_cost_per_room_night:2,items:[{enabled:true,source_date:'2026-10-03'}]};
        assert.equal(ctx.canAdopt,true);
        await ctx.restore(10,true);
        assert.equal(ctx.result,null,failure);assert.equal(ctx.saved,null,failure);
        assert.ok(!ctx.resultCurrent,failure);assert.equal(ctx.canAdopt,false,failure);
        assert.equal(ctx.actual.occupied_room_nights,'77',failure);assert.equal(ctx.dirty,true,failure);
        assert.ok(ctx.error,failure);assert.equal(ctx.notice,'',failure);
    }
});

test('view-only restored direct costs render every editable control disabled',async()=>{
    const {ctx,definition}=component();ctx.canExecute=false;
    ctx.applyInputs({source_refs:['TEST-ONLY saved source'],costs:[{label:'TEST-ONLY direct cost',amount:1,source_ref:'TEST-ONLY cost source',included_in_net_revenue:false}]});
    const render=new Function('Vue',compile(definition.template,{mode:'function'}).code)(Vue);
    const html=await renderToString(Vue.createSSRApp({render(){return render(ctx,[]);}}));
    const inputs=[...html.matchAll(/<input\b[^>]*>/g)].map(match=>match[0]);
    for(const placeholder of ['直接成本名称','金额（元）','成本来源']) {
        const input=inputs.find(tag=>tag.includes(`placeholder="${placeholder}"`));
        assert.ok(input,placeholder);assert.match(input,/\bdisabled(?:\s|>|=)/,placeholder);
    }
    assert.ok(inputs.filter(tag=>tag.includes('type="checkbox"')).every(tag=>/\bdisabled(?:\s|>|=)/.test(tag)), 'all displayed cost and scope checkboxes are disabled');
    assert.equal(ctx.channel.costs[0].cost_type,'direct','legacy rows retain an explicit ordinary-cost classification');
    assert.match(html,/<select[^>]*disabled[^>]*aria-label="成本类型"/);
});

test('historical restore explains standard network failures in Chinese and preserves server business errors',async()=>{
    for(const message of ['Failed to fetch','NetworkError when attempting to fetch resource.','Load failed']) {
        const {ctx}=component(async()=>{throw new TypeError(message);});
        ctx.saved={snapshot_id:9,readback_verified:true};ctx.result={net_revenue:10};
        await ctx.restore(10,true);
        assert.equal(ctx.error,'版本读取失败，请检查连接后重试。',message);
        assert.equal(ctx.saved,null,message);assert.equal(ctx.result,null,message);assert.ok(!ctx.resultCurrent,message);
    }
    for(const message of ['当前租户不能访问该酒店的经营证据','Failed to fetch']) {
        const {ctx}=component(async()=>({code:403,message}));await ctx.restore(10,true);
        assert.equal(ctx.error,message,'server messages must retain their original business meaning');
    }
});

test('cross-month enabled source cannot be adopted; disabled legacy rows stay compatible',()=>{
    const {ctx,window}=component();ctx.kind='consumables_actual';
    ctx.result={status:'calculated',inputs:{occupied_room_nights_source_ref:'synthetic-pms'},actual_consumables_cost_per_room_night:0,
        items:[{enabled:true,source_date:'2026-09-20'}]};
    ctx.saved={snapshot_id:9,content_digest:'a'.repeat(64),readback_verified:true,source_quality:'operator_attested'};
    assert.equal(ctx.canAdopt,false);ctx.adopt();assert.equal(window.event,undefined);
    ctx.result.items=[{enabled:true,source_date:'2026-10-03'},{enabled:false,source_date:'2026-09-20'}];
    assert.equal(ctx.canAdopt,true);ctx.adopt();assert.equal(window.event.detail.as_of,'2026-10-03');
});

test('an incomplete save receipt cannot make the current actual cost adoptable',()=>{
    const {ctx}=component();ctx.kind='consumables_actual';
    ctx.result={status:'calculated',inputs:{occupied_room_nights_source_ref:'synthetic-room-ledger'},actual_consumables_cost_per_room_night:0,items:[{enabled:true,source_date:'2026-10-03'}]};
    for(const receipt of [{readback_verified:true,source_quality:'operator_attested'},
        {snapshot_id:9,readback_verified:true,source_quality:'operator_attested'},
        {snapshot_id:9,content_digest:'invalid',readback_verified:true,source_quality:'operator_attested'}]){
        ctx.saved=receipt;assert.equal(ctx.canAdopt,false);
    }
});

function savedActual(ctx, overrides = {}) {
    const inputs = structuredClone(ctx.input);
    return { scope:{tenant_id:10,...ctx.scope}, snapshot_id:9, content_digest:'a'.repeat(64), readback_verified:true,
        source_quality:'operator_attested', status:'calculated', inputs,
        result:{status:'calculated',source_quality:'operator_attested',inputs,items:inputs.items,
            actual_consumables_cost_per_room_night:0}, ...overrides };
}
function actualDraft(ctx) {
    ctx.kind='consumables_actual';
    ctx.actual={occupied_room_nights:'100',occupied_room_nights_source_ref:'synthetic whole-hotel ledger',denominator_scope:'whole_hotel',operator_attested:true,
        items:[{enabled:true,source_date:'2026-10-03',source_ref:'synthetic inventory',unit_price:'0'}]};
}


for(const [name,date,month] of [['future date','2026-10-04','2026-10'],['future month','2026-11-01','2026-11']]) {
    test(`same-month saved actual from a ${name} cannot dispatch an adoption reference`,()=>{
        const {ctx,window}=component();actualDraft(ctx);ctx.periodMonth=month;ctx.actual.items[0].source_date=date;
        ctx.saved=savedActual(ctx);ctx.result=ctx.saved.result;assert.equal(ctx.canAdopt,false);ctx.adopt();assert.equal(window.event,undefined);assert.equal(window.SUXI_PENDING_ACTUAL_CONSUMABLES_REFERENCE,undefined);
    });
}

test('actual dated today follows Shanghai midnight even when the UTC calendar is still yesterday',()=>{
    const {ctx,window}=component();actualDraft(ctx);ctx.saved=savedActual(ctx);ctx.result=ctx.saved.result;
    assert.equal(ctx.canAdopt,true);ctx.adopt();assert.equal(window.event.detail.as_of,'2026-10-03');assert.equal(window.event.detail.actual_consumables_cost_per_room_night,0);
});
