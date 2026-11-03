import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

// synthetic-test-only: execute the actual component against isolated browser failures.
function harness(failure = '') {
    const state={failure,blobs:new Map(),revoked:[],timers:[],anchors:[],clicks:0};
    const document={body:{appendChild(anchor){if(state.failure==='append')throw new Error('synthetic append failure');anchor.isConnected=true;}},createElement(){
        const anchor={isConnected:false,click(){state.clicks+=1;if(state.failure==='click')throw new Error('synthetic click failure');},remove(){this.isConnected=false;this.removed=true;}};
        state.anchors.push(anchor);return anchor;
    }};
    const URL={createObjectURL(blob){if(state.failure==='url')throw new Error('synthetic URL failure');const url=`blob:synthetic-${state.blobs.size+1}`;state.blobs.set(url,blob);return url;},revokeObjectURL(url){state.revoked.push(url);}};
    const window={setTimeout(fn,delay){state.timers.push({fn,delay});return state.timers.length;}};
    vm.runInNewContext(readFileSync('public/components/system/dual-ota-field-closure-panel.js','utf8'),{window,document,URL,URLSearchParams,Blob});
    const api=window.SUXI_DUAL_OTA_FIELD_CLOSURE;
    const h=(tag,props,children)=>({tag,props:props||{},children});
    const component=api.createPanel({h});
    const closure={contract_version:'dual_ota_field_closure.v1',tenant_id:801,hotel_id:901,business_date:'2026-10-01',page_identity:'synthetic-test-only#download-901',platforms:{
        ctrip:{platform:'ctrip',fields:[{key:'revenue',label:'收入',unit:'CNY',status:'strict_readback',value:0}]},
        meituan:{platform:'meituan',fields:[{key:'revenue',label:'收入',unit:'CNY',status:'source_missing',value:null}]},
    }};
    const value={...component.data(),hotelId:901,businessDate:'2026-10-01',surface:'revenue_cockpit',closure,request:async()=>({code:200,data:{dual_ota_field_closure:closure}})};
    for(const [key,fn] of Object.entries(component.methods))value[key]=fn.bind(value);
    for(const [key,getter] of Object.entries(component.computed))Object.defineProperty(value,key,{get:getter.bind(value)});
    const nodes=tree=>[tree,...(Array.isArray(tree?.children)?tree.children.flatMap(nodes):[])].filter(n=>n&&typeof n==='object');
    const find=id=>nodes(component.render.call(value)).find(node=>node.props['data-testid']===id);
    return {state,value,closure,URL,find};
}

test('successful download hands the exact current CSV to a Blob and keeps it until browser ownership',async()=>{
    const {state,value,closure}=harness();
    closure.platforms.ctrip.fields[0].label='收入, "核验"\n来源😀';
    const payload=value.downloadClosure();
    assert.equal(payload.ok,true);
    assert.equal(state.clicks,1);
    const anchor=state.anchors[0];
    assert.match(anchor.href,/^blob:/);
    assert.equal(anchor.download,payload.fileName);
    const blob=state.blobs.get(anchor.href);
    assert.equal(blob.type,'text/csv;charset=utf-8');
    assert.equal(Buffer.from(await blob.arrayBuffer()).toString('utf8'),payload.csv);
    assert.match(payload.csv,/801","901","synthetic-test-only#download-901"/);
    assert.match(payload.csv,/¥0.00/);assert.match(payload.csv,/来源缺失/);
    assert.equal(state.revoked.length,0);
    assert.equal(anchor.isConnected,true);
    assert.equal(state.timers[0].delay,60000);
    state.timers[0].fn();
    assert.equal(anchor.isConnected,false);
    assert.deepEqual(state.revoked,[anchor.href]);
});

for(const failure of ['url','append','click']) {
    test(`${failure} failure is visible and recoverable without losing validated facts`,()=>{
        const {state,value,closure,find}=harness(failure);
        const result=value.downloadClosure();
        assert.equal(result.ok,false);
        assert.match(value.closureDownloadError,/下载.*重试/);
        assert.equal(value.closureError,'');
        assert.equal(value.activeClosure,closure);
        const alert=find('dual-ota-field-download-error-revenue_cockpit');
        assert.equal(alert.props.role,'alert');
        assert.equal(alert.children,value.closureDownloadError);
        assert.ok(state.anchors.every(anchor=>!anchor.isConnected));
        assert.deepEqual(state.revoked,Array.from(state.blobs.keys()));
        state.failure='';
        assert.equal(value.downloadClosure().ok,true);
        assert.equal(value.closureDownloadError,'');
        assert.equal(value.activeClosure,closure);
    });
}

test('unsupported file API stays an explicit download failure',()=>{
    const {value,URL}=harness();
    URL.createObjectURL=undefined;
    const result=value.downloadClosure();
    assert.equal(result.ok,false);
    assert.match(result.message,/下载.*重试/);
    assert.ok(value.activeClosure);
});

test('valid empty diagnostic contract is retained and its download control is disabled',()=>{
    const {value,closure,find}=harness();
    closure.platforms.ctrip.fields=[];closure.platforms.meituan.fields=[];
    assert.equal(find('dual-ota-field-download-revenue_cockpit').props.disabled,true);
    assert.equal(value.downloadClosure().ok,false);
    assert.equal(value.activeClosure,closure);
    assert.equal(value.closureError,'');
    assert.match(value.closureDownloadError,/没有可见字段/);
});

test('refresh clears a download failure while keeping successful scope recovery',async()=>{
    const {state,value}=harness('click');
    value.downloadClosure();state.failure='';
    await value.refreshClosure({forceReload:true});
    assert.equal(value.closureDownloadError,'');
    assert.ok(value.activeClosure);
});

test('invalid Unicode in a label uses the browser visible replacement instead of throwing',async()=>{
    const {state,value,closure}=harness();
    closure.platforms.ctrip.fields[0].label='收入\uD800来源';
    const payload=value.downloadClosure();
    assert.equal(payload.ok,true);
    assert.match(await state.blobs.get(state.anchors[0].href).text(),/收入\uFFFD来源/);
});

test('visible text recovery provides the same current CSV when browser downloads fail',()=>{
    const {value,find}=harness('click');
    const result=value.downloadClosure();
    assert.equal(result.ok,false);
    const preview=find('dual-ota-field-csv-text-revenue_cockpit');
    assert.equal(preview.props.readOnly,true);
    assert.equal(preview.props['aria-label'],'当前字段 CSV 文本');
    assert.equal(preview.props.value,result.csv);
    assert.match(preview.props.value,/801","901","synthetic-test-only#download-901"/);
    assert.match(preview.props.value,/¥0.00/);
    assert.match(preview.props.value,/来源缺失/);
});

test('CSV text recovery cannot expose stale scope, failed reads or empty fields',()=>{
    const {value,closure,find}=harness();
    assert.ok(find('dual-ota-field-csv-text-revenue_cockpit'));
    value.hotelId=902;
    assert.equal(find('dual-ota-field-csv-text-revenue_cockpit'),undefined);
    value.hotelId=901;value.businessDate='2026-10-02';
    assert.equal(find('dual-ota-field-csv-text-revenue_cockpit'),undefined);
    closure.business_date='2026-10-02';closure.page_identity='synthetic-test-only#download-new-day';
    assert.match(find('dual-ota-field-csv-text-revenue_cockpit').props.value,/2026-10-02/);
    assert.match(find('dual-ota-field-csv-text-revenue_cockpit').props.value,/download-new-day/);
    value.closureLoading=true;
    assert.equal(find('dual-ota-field-csv-text-revenue_cockpit'),undefined);
    value.closureLoading=false;value.closureError='synthetic source unavailable';
    assert.equal(find('dual-ota-field-csv-text-revenue_cockpit'),undefined);
    value.closureError='';closure.platforms.ctrip.fields=[];closure.platforms.meituan.fields=[];
    assert.equal(find('dual-ota-field-csv-text-revenue_cockpit'),undefined);
});
