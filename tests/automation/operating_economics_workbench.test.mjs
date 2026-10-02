import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { randomUUID } from 'node:crypto';
const source = readFileSync(new URL('../../public/components/system/operating-economics-workbench.js', import.meta.url), 'utf8');
function component(request = async () => ({ code:200,data:{} }), code = source) {
    const window = { confirm:()=>true, dispatchEvent:event=>window.event=event };
    class ShanghaiClock extends Date { constructor(...args) { super(...(args.length ? args : ['2026-10-02T16:30:00Z'])); } }
    new Function('window','crypto','CustomEvent','Vue','Blob','URL','document','setTimeout','Date',code)(window,{randomUUID},class { constructor(type,opts){this.type=type;this.detail=opts.detail;} },new Proxy({}, {get:()=>()=>({})}),class {constructor(parts){window.exported=JSON.parse(parts.join(''));}}, {createObjectURL:()=> 'synthetic-blob',revokeObjectURL:()=>{}}, {createElement:()=>({click(){}})},()=>{},ShanghaiClock);
    const definition = window.SUXI_SYSTEM_COMPONENTS.OperatingEconomicsWorkbench;
    const ctx = {...definition.data(),hotelId:80,periodMonth:'2026-10',platform:'ctrip',canExecute:true,request};
    for(const [key,fn] of Object.entries(definition.methods))ctx[key]=fn.bind(ctx);
    for(const [key,fn] of Object.entries(definition.computed))Object.defineProperty(ctx,key,{get:()=>fn.call(ctx)});
    return {ctx,window,definition};
}
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

test('cross-month enabled source cannot be adopted; disabled legacy rows stay compatible',()=>{
    const {ctx,window}=component();ctx.kind='consumables_actual';
    ctx.result={status:'calculated',inputs:{occupied_room_nights_source_ref:'synthetic-pms'},actual_consumables_cost_per_room_night:0,
        items:[{enabled:true,source_date:'2026-09-20'}]};
    ctx.saved={snapshot_id:9,content_digest:'a'.repeat(64),readback_verified:true,source_quality:'operator_attested'};
    assert.equal(ctx.canAdopt,false);ctx.adopt();assert.equal(window.event,undefined);
    ctx.result.items=[{enabled:true,source_date:'2026-10-03'},{enabled:false,source_date:'2026-09-20'}];
    assert.equal(ctx.canAdopt,true);ctx.adopt();assert.equal(window.event.detail.as_of,'2026-10-03');
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
    let resolve;let calls=0;const receipt={snapshot_id:9,content_digest:'a'.repeat(64),readback_verified:true,scope:{tenant_id:10,hotel_id:80,period_month:'2026-10',platform:'ctrip',kind:'channel_economics'},inputs:{source_refs:[]},result:{}};
    const {ctx}=component(async()=>++calls<=2?{code:200,data:receipt}:new Promise(r=>resolve=r));
    const pending=ctx.calculate(true);while(calls<3)await Promise.resolve();ctx.seq++;ctx.overview=null;resolve({code:200,data:{hotel_id:80}});await pending;assert.equal(ctx.overview,null);
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

for (const save of [false, true]) {
    test(`a failed ${save ? 'save' : 'preview'} invalidates the previous result and saved adoption receipt`,async()=>{
        const {ctx}=component(async()=>{throw new Error('合成计算失败');});
        ctx.kind='consumables_actual';ctx.saved={snapshot_id:9,content_digest:'a'.repeat(64),readback_verified:true,source_quality:'operator_attested'};
        ctx.result={status:'calculated',inputs:{occupied_room_nights_source_ref:'synthetic-room-ledger'},actual_consumables_cost_per_room_night:2};
        await ctx.calculate(save);
        assert.equal(ctx.result,null);assert.equal(ctx.saved,null);assert.equal(ctx.canAdopt,false);assert.equal(ctx.resultCurrent,null);
        assert.match(ctx.error,/合成计算失败/);
    });
}

test('a failed history restore invalidates the earlier adopted result instead of leaving it current',async()=>{
    const {ctx}=component(async()=>{throw new Error('合成回读失败');});ctx.kind='consumables_actual';
    ctx.saved={snapshot_id:9,content_digest:'a'.repeat(64),readback_verified:true,source_quality:'operator_attested'};
    ctx.result={status:'calculated',inputs:{occupied_room_nights_source_ref:'synthetic-room-ledger'},actual_consumables_cost_per_room_night:2};
    await ctx.restore(10);assert.equal(ctx.result,null);assert.equal(ctx.saved,null);assert.equal(ctx.canAdopt,false);assert.match(ctx.error,/合成回读失败/);
});

test('an incomplete save receipt cannot make the current actual cost adoptable',()=>{
    const {ctx}=component();ctx.kind='consumables_actual';
    ctx.result={status:'calculated',inputs:{occupied_room_nights_source_ref:'synthetic-room-ledger'},actual_consumables_cost_per_room_night:0};
    for(const receipt of [{readback_verified:true,source_quality:'operator_attested'},
        {snapshot_id:9,readback_verified:true,source_quality:'operator_attested'},
        {snapshot_id:9,content_digest:'invalid',readback_verified:true,source_quality:'operator_attested'}]){
        ctx.saved=receipt;assert.equal(ctx.canAdopt,false);
    }
});

test('a delayed old save cannot replace the result after a newer failed preview',async()=>{
    let resolve;let calls=0;const {ctx}=component(async()=>++calls===1?new Promise(r=>resolve=r):Promise.reject(new Error('合成新请求失败')));
    const first=ctx.calculate(true);await ctx.calculate(false);
    resolve({code:200,data:{scope:{hotel_id:80,period_month:'2026-10',platform:'ctrip',kind:'channel_economics'},readback_verified:true,result:{net_revenue:999}}});
    await first;assert.equal(ctx.result,null);assert.equal(ctx.saved,null);assert.match(ctx.error,/合成新请求失败/);assert.equal(ctx.busy,false);
});

test('export preserves the displayed source quality of an unsaved preview',()=>{
    const {ctx,window}=component();ctx.result={status:'calculated',source_quality:'operator_attested',net_revenue:0};
    ctx.exportSnapshot();assert.equal(window.exported.source_quality,'operator_attested');assert.equal(window.exported.snapshot_id,null);
    assert.deepEqual(window.exported.result,ctx.result);assert.deepEqual(window.exported.scope,ctx.scope);
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

test('a lost save response retries unchanged input with the same key and one stored version',async()=>{
    const records=new Map(), keys=[], reads=[];let loseResponse=true;
    const {ctx}=component(async(path,options)=>{
        if(options?.method==='POST') {
            const body=JSON.parse(options.body);keys.push(body.idempotency_key);
            if(!records.has(body.idempotency_key))records.set(body.idempotency_key,savedActual(ctx));
            if(loseResponse){loseResponse=false;throw new Error('synthetic save response lost after persistence');}
            return {code:200,data:records.get(body.idempotency_key)};
        }
        if(path.includes('/snapshots/')){reads.push(path);return {code:200,data:[...records.values()][0]};}
        return {code:200,data:{scope:{tenant_id:10,...ctx.scope},history:[]}};
    });actualDraft(ctx);const draft=structuredClone(ctx.actual);
    await ctx.calculate(true);assert.equal(ctx.saved,null);assert.deepEqual(ctx.actual,draft);assert.equal(ctx.busy,false);
    await ctx.calculate(true);
    assert.equal(keys.length,2);assert.equal(keys[0],keys[1]);assert.equal(records.size,1);
    assert.equal(reads.length,1);assert.match(reads[0],/snapshots\/9\?/);assert.equal(ctx.saved.snapshot_id,9);assert.equal(ctx.canAdopt,true);
});

test('independent save GET failure retains input and retry reuses the saved key before allowing adoption',async()=>{
    const keys=[], routes=[];let failRead=true, receipt;
    const {ctx,window}=component(async(path,options)=>{
        routes.push(path);
        if(options?.method==='POST'){keys.push(JSON.parse(options.body).idempotency_key);receipt ||= savedActual(ctx);return {code:200,data:receipt};}
        if(path.includes('/snapshots/'))return failRead?{code:503,message:'synthetic independent read failed'}:{code:200,data:receipt};
        return {code:200,data:{scope:{tenant_id:10,...ctx.scope},history:[]}};
    });actualDraft(ctx);const draft=structuredClone(ctx.actual);
    await ctx.calculate(true);assert.equal(ctx.saved,null);assert.equal(ctx.result,null);assert.deepEqual(ctx.actual,draft);assert.equal(ctx.busy,false);
    assert.match(ctx.error,/独立.*回读.*当前输入保留/);ctx.adopt();assert.equal(window.event,undefined);
    failRead=false;await ctx.calculate(true);assert.equal(keys[0],keys[1]);assert.equal(ctx.saved.snapshot_id,9);assert.equal(ctx.error,'');
    assert.equal(routes.filter(path=>path.includes('/snapshots/')).length,2);assert.equal(ctx.canAdopt,true);ctx.adopt();assert.equal(window.event.detail.snapshot_id,9);
});

for(const [name,change] of Object.entries({
    'snapshot id':receipt=>{receipt.snapshot_id=10;}, 'content digest':receipt=>{receipt.content_digest='b'.repeat(64);},
    'tenant':receipt=>{receipt.scope.tenant_id=11;}, 'hotel':receipt=>{receipt.scope.hotel_id=81;},
    'month':receipt=>{receipt.scope.period_month='2026-09';}, 'readback flag':receipt=>{receipt.readback_verified=false;},
    'result content':receipt=>{receipt.result.actual_consumables_cost_per_room_night=999;},
    'input content':receipt=>{receipt.inputs.occupied_room_nights='999';}
})) {
    test(`independent GET rejects changed ${name} and keeps the draft unadopted`,async()=>{
        let receipt;const {ctx,window}=component(async(path,options)=>{
            if(options?.method==='POST'){receipt=savedActual(ctx);return {code:200,data:receipt};}
            const wrong=structuredClone(receipt);change(wrong);return {code:200,data:wrong};
        });actualDraft(ctx);const draft=structuredClone(ctx.actual);await ctx.calculate(true);
        assert.equal(ctx.saved,null);assert.equal(ctx.result,null);assert.deepEqual(ctx.actual,draft);assert.ok(ctx.error);ctx.adopt();assert.equal(window.event,undefined);
    });
}

test('save waits for independent GET before publishing the result or adoption receipt',async()=>{
    let release,receipt;const {ctx}=component(async(path,options)=>{
        if(options?.method==='POST'){receipt=savedActual(ctx);return {code:200,data:receipt};}
        if(path.includes('/snapshots/'))return new Promise(resolve=>{release=()=>resolve({code:200,data:receipt});});
        return {code:200,data:{scope:{tenant_id:10,...ctx.scope},history:[]}};
    });actualDraft(ctx);const pending=ctx.calculate(true);for(let turn=0;turn<10&&!release;turn++)await Promise.resolve();
    assert.equal(typeof release,'function');assert.equal(ctx.saved,null);assert.equal(ctx.result,null);assert.equal(ctx.canAdopt,false);
    release();await pending;assert.equal(ctx.saved.snapshot_id,9);assert.equal(ctx.canAdopt,true);
});

for(const [name,overrides] of [['missing id',{snapshot_id:undefined}],['fractional id',{snapshot_id:9.5}],['invalid digest',{content_digest:'invalid'}]]) {
    test(`an acknowledged save with ${name} stays unusable and does not issue an unbound GET`,async()=>{
        let gets=0;const {ctx}=component(async(path,options)=>{
            if(options?.method==='POST')return {code:200,data:savedActual(ctx,overrides)};
            gets++;throw new Error('unexpected GET');
        });actualDraft(ctx);const draft=structuredClone(ctx.actual);await ctx.calculate(true);
        assert.equal(gets,0);assert.equal(ctx.saved,null);assert.equal(ctx.result,null);assert.deepEqual(ctx.actual,draft);assert.match(ctx.error,/回读凭据不完整/);
    });
}

test('save identity changes for changed input or scope and remains stable for the unchanged draft',async()=>{
    const keys=[];const {ctx,definition}=component(async(path,options)=>{
        if(options?.method==='POST'){keys.push(JSON.parse(options.body).idempotency_key);return {code:200,data:savedActual(ctx)};}
        return {code:503,message:'synthetic save remains incomplete until independent readback succeeds'};
    });actualDraft(ctx);await ctx.calculate(true);await ctx.calculate(true);assert.equal(keys[0],keys[1]);
    ctx.actual.occupied_room_nights='101';await ctx.calculate(true);assert.notEqual(keys[1],keys[2]);
    ctx.actual.occupied_room_nights='102';ctx.edit();await ctx.calculate(true);assert.notEqual(keys[2],keys[3]);
    const previous={...ctx.scope};ctx.hotelId=81;definition.watch.scope.handler.call(ctx,ctx.scope,previous);actualDraft(ctx);await ctx.calculate(true);assert.notEqual(keys[3],keys[4]);
});

for (const refreshFails of [false,true]) {
    test(`completed saves create distinct explicit new versions even when overview refresh fails=${refreshFails}`,async()=>{
        const records=new Map(),keys=[];let lastReceipt;
        const {ctx}=component(async(path,options)=>{
            if(options?.method==='POST') {
                const key=JSON.parse(options.body).idempotency_key;keys.push(key);
                if(!records.has(key))records.set(key,savedActual(ctx,{snapshot_id:101+records.size}));
                lastReceipt=records.get(key);return {code:200,data:lastReceipt};
            }
            if(path.includes('/snapshots/'))return {code:200,data:structuredClone(lastReceipt)};
            if(refreshFails)throw new Error('synthetic overview refresh failed after verified save');
            return {code:200,data:{scope:{tenant_id:10,...ctx.scope},history:[]}};
        });actualDraft(ctx);const draft=structuredClone(ctx.actual);
        await ctx.calculate(true);assert.equal(ctx.saved.snapshot_id,101);assert.equal(ctx.canAdopt,true);
        await ctx.calculate(true);assert.notEqual(keys[0],keys[1]);assert.equal(records.size,2);
        assert.equal(ctx.saved.snapshot_id,102);assert.equal(ctx.canAdopt,true);assert.deepEqual(ctx.actual,draft);
        if(refreshFails)assert.match(ctx.error,/overview refresh failed/);
    });
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
