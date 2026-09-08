import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import { webcrypto } from 'node:crypto';

const source = fs.readFileSync('public/simulation-static.js', 'utf8');
const app = fs.readFileSync('public/app-main.js', 'utf8');
const sandbox = { window: {}, console };
vm.runInNewContext(source, sandbox);
const api = sandbox.window.SUXI_SIMULATION_STATIC;
const clone = x => JSON.parse(JSON.stringify(x));
function input() {
    const result = clone(api.defaultSimulationInput);
    result.hotel_id = 901;
    result.weekdayDays = 21;
    result.operatingScenario = {...api.createOperatingScenario(), start_month: '2024-02'};
    return result;
}
function record(value, id = 1) {
    return { id, input: {...value, operatingScenario: api.normalizedOperatingScenario(value.operatingScenario)},
        truth_context: {tenant_id: 9, hotel_id: value.hotel_id, persistence: {readback_verified: true}},
        result: {operatingScenario: {case_name: value.operatingScenario.case_name, additional_cash_gap: 0}} };
}

test('new scenario inputs survive normalization and old records receive no invented financing', () => {
    const value=input(); value.operatingScenario.loan_amount=100000;
    assert.deepEqual(clone(api.normalizeSimulationInput(value).operatingScenario), value.operatingScenario);
    assert.equal(api.normalizeSimulationInput({roomCount:10}).operatingScenario, null);
    assert.equal(api.validateOperatingScenario(input()), '');
    for (const [key, value] of [['loan_amount',''],['opening_cash',null],['monetary_unit','wan'],['start_month','2024-13'],['horizon_months',1.2],['ramp_start_occupancy',101]]) {
        const invalid=input(); invalid.operatingScenario[key]=value;
        assert.notEqual(api.validateOperatingScenario(invalid),'', key);
    }
    const invalid=input(); invalid.weekdayDays=22;
    assert.match(api.validateOperatingScenario(invalid),/29/);
});

test('save captures immutable input, validates readback, and suppresses late response', async () => {
    const value=input(); let resolve, body, applied=null, current=true;
    const promise=api.runSimulationCalculationFlow({input:value, clientRequestId:'synthetic-key-1', request:async (url,opts)=>{body=JSON.parse(opts.body);return new Promise(r=>{resolve=r;});}, applyRecord:r=>{applied=r;},loadRecords:async()=>{},isCurrent:()=>current});
    value.operatingScenario.opening_cash=123;
    assert.equal(body.input.operatingScenario.opening_cash,0);
    assert.equal(body.client_request_id,'synthetic-key-1');
    current=false; resolve({code:200,data:record(body.input)});
    assert.equal(await promise,null); assert.equal(applied,null);
    for (const mutate of [r=>{r.truth_context.hotel_id=902;},r=>{r.input.operatingScenario.loan_amount=123;},r=>{r.truth_context.persistence.readback_verified=false;}]) {
        const data=record(input()); mutate(data);
        await assert.rejects(api.runSimulationCalculationFlow({input:input(),request:async()=>({code:200,data}),applyRecord:()=>assert.fail('Mismatched readback applied'),loadRecords:async()=>{}}),/不一致/);
    }
});

test('error is visible, the following retry saves and restores loading', async () => {
    let count=0; const states=[], toasts=[], applied=[];
    const value=input();
    const opts={input:value,hotels:[{id:901}],setLoading:s=>states.push(s),showToast:m=>toasts.push(m),loadRecords:async()=>{},applyRecord:r=>applied.push(r.id),request:async()=>++count===1?{code:400,message:'synthetic write failure'}:{code:200,data:record(value)}};
    assert.equal(await api.runSimulationCalculationUiFlow(opts),null);
    assert.equal((await api.runSimulationCalculationUiFlow(opts)).id,1);
    assert.deepEqual(states,[true,false,true,false]); assert.deepEqual(applied,[1]);
    assert.match(toasts[0],/synthetic write failure/);
});

test('native JSON key order never turns exact readback into a false mismatch', async () => {
    const value=input(), data=record(value);let applied=null;
    data.input.operatingScenario=Object.fromEntries(Object.entries(data.input.operatingScenario).reverse());
    await api.runSimulationCalculationFlow({input:value,request:async()=>({code:200,data}),applyRecord:r=>{applied=r;},loadRecords:async()=>{}});
    assert.equal(applied.id,1);
});

test('comparison enforces exact tenant hotel date type horizon and currency', () => {
    const a=record(input(),1), b=record(input(),2); b.input.operatingScenario.case_name='revised';
    assert.equal(api.compareOperatingRecords([a,b]).length,2);
    for (const change of [r=>r.truth_context.hotel_id++,r=>r.truth_context.tenant_id++,r=>r.input.operatingScenario.start_month='2024-03',r=>r.input.operatingScenario.case_type='proposed_investment',r=>r.input.operatingScenario.horizon_months=24,r=>r.input.operatingScenario.currency='USD',r=>r.truth_context.persistence.readback_verified=false]) {
        const other=clone(b); change(other); assert.throws(()=>api.compareOperatingRecords([a,other]),/比较须/);
    }
    assert.match(api.operatingPaybackText({status:'never_within_horizon',months:null}),/始终不回本/);
    assert.equal(api.operatingPaybackText(null),'未测算');
});

function calculationHarness() {
    const start=app.indexOf('let simulationCalculationRequestId = 0;');
    const end=app.indexOf('const operatingScenarioFields =',start);
    const pending=[], states=[], applied=[], watchers=[], messages=[];
    const state={crypto:webcrypto,JSON, aiSimulationLoading:{value:false},aiSimulationParams:{value:input()},aiProject:{value:{project_name:'synthetic'}},operationHotelOptions:{value:[{id:901}]},currentPage:{value:'ai-simulation'},
        requireSimulationStatic:k=>api[k],ensureSimulationStaticReady:async()=>{},saveSimulationInputOnly:()=>{},loadSimulationRecords:async()=>{},showToast:m=>messages.push(m),
        session:1,captureAuthSession:()=>state.session,isAuthSessionCurrent:s=>s===state.session,isStillOnRequestPage:p=>state.currentPage.value===p,
        watch:(ref,cb)=>watchers.push(cb),applySimulationRecord:r=>applied.push(r.id),request:(url,opts)=>new Promise((resolve,reject)=>pending.push({resolve,reject,body:JSON.parse(opts.body)}))};
    vm.runInNewContext(app.slice(start,end)+'\nthis.run=handleSimulation; this.invalidate=invalidateSimulationCalculation;',state);
    return {state,pending,applied,watchers,messages,states};
}
const flush=()=>new Promise(setImmediate);
test('actual app handler prevents duplicate submission and reuses identity after uncertain failure', async () => {
    const h=calculationHarness(); const first=h.state.run(); await flush(); await h.state.run();
    assert.equal(h.pending.length,1); h.pending[0].reject(new Error('synthetic timeout')); await first;
    const retry=h.state.run(); await flush();
    assert.equal(h.pending[1].body.client_request_id,h.pending[0].body.client_request_id);
    h.pending[1].resolve({code:200,data:record(h.pending[1].body.input,9)});await retry;
    assert.deepEqual(h.applied,[9]);assert.equal(h.state.aiSimulationLoading.value,false);
});
test('actual app handler does not let old completion clear new loading or overwrite new form', async () => {
    const h=calculationHarness(); const old=h.state.run();await flush(); h.state.invalidate();
    h.state.aiSimulationParams.value.operatingScenario.case_name='new';const newer=h.state.run();await flush();
    h.pending[0].resolve({code:200,data:record(h.pending[0].body.input,1)});await old;
    assert.equal(h.state.aiSimulationLoading.value,true);assert.deepEqual(h.applied,[]);
    h.pending[1].resolve({code:200,data:record(h.pending[1].body.input,2)});await newer;
    assert.deepEqual(h.applied,[2]);assert.equal(h.state.aiSimulationLoading.value,false);
});
