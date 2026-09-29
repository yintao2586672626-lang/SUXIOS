import assert from 'node:assert/strict';
import test from 'node:test';
import { mount, record } from './operating_target_save_recovery.test.mjs';

const receipt = extra => ({ code: 200, data: { status: 'synced', system_hotel_id: 90001, target_date: '2026-09-20', live_read: true, readback_verified: true, ...extra } });
const deferred = () => { let resolve, reject; const promise = new Promise((r,j) => { resolve=r;reject=j; });return {promise,resolve,reject}; };
const tick = () => new Promise(r => setImmediate(r));
const current = () => ({ code: 200, data: { record: record() } });
const ancillary = () => ({ code: 200, data: { list: [] } });

test('realtime normal receipt completes current readback and reports once', async () => {
    const m = mount((url, options) => options?.method === 'POST' ? receipt() : url.includes('/current?') ? current() : ancillary());
    await m.syncOperatingPmsRealtime();
    assert.equal(m.operatingPmsRealtimeSyncResult.value.status, 'synced');
    assert.equal(m.operatingTargetResult.value.id, 1);
    assert.equal(m.toasts.length, 1);
    assert.equal(m.operatingTargetLoading.value.liveSync, false);
});

for (const kind of ['hotel ABA','date ABA','page ABA','session','new draft','explicit read']) {
    for (const failure of [false,true]) test(`realtime ${failure?'failure':'success'} ignores obsolete ${kind}`, async () => {
        const pending=deferred();
        const m=mount((url,options)=>options?.method==='POST'?pending.promise:url.includes('/current?')?current():ancillary());
        const done=m.syncOperatingPmsRealtime();await tick();
        if(kind==='hotel ABA'){m.operatingTargetForm.value.hotel_id='90002';m.operatingTargetForm.value.hotel_id='90001';}
        if(kind==='date ABA'){m.operatingTargetForm.value.target_date='2026-09-21';m.operatingTargetForm.value.target_date='2026-09-20';}
        if(kind==='page ABA'){m.currentPage.value='home';m.currentPage.value='operating-targets';}
        if(kind==='session')m.changeSession();
        if(kind==='explicit read')await m.loadOperatingTarget();
        if(kind==='new draft')m.operatingTargetForm.value.target_revenue=12345;
        const draft=JSON.stringify(m.operatingTargetForm.value);
        const reads=m.calls.length;
        failure?pending.reject(Error('SYNTHETIC delayed failure')):pending.resolve(receipt());await done;
        assert.equal(m.calls.length,reads,'no extra read after invalidation');
        assert.equal(JSON.stringify(m.operatingTargetForm.value),draft);
        assert.equal(m.operatingPmsRealtimeSyncResult.value,null);
        assert.equal(m.toasts.length,0);
        assert.equal(m.operatingTargetLoading.value.liveSync,false);
    });
}

test('realtime current readback and its follow-ups share original input ownership', async () => {
    const get=deferred();const m=mount((url,options)=>options?.method==='POST'?receipt():url.includes('/current?')?get.promise:ancillary());
    const done=m.syncOperatingPmsRealtime();await tick();
    m.operatingTargetForm.value.target_revenue=7654;
    get.resolve(current());await done;
    assert.equal(m.operatingTargetForm.value.target_revenue,7654);
    assert.equal(m.operatingTargetResult.value,null);
    assert.equal(m.toasts.length,0);
    assert.equal(m.operatingPmsRealtimeSyncResult.value,null);
});

test('realtime does not duplicate POST and can retry an actual failure', async () => {
    const pending=deferred();let count=0;
    const m=mount((url,options)=>options?.method==='POST'?(++count===1?pending.promise:receipt()):url.includes('/current?')?current():ancillary());
    const first=m.syncOperatingPmsRealtime();await tick();await m.syncOperatingPmsRealtime();assert.equal(count,1);
    pending.reject(Error('SYNTHETIC provider failed'));await first;
    assert.equal(m.operatingPmsRealtimeSyncResult.value.status,'blocked');
    assert.equal(m.operatingPmsRealtimeSyncResult.value.readback_verified,false);
    await m.syncOperatingPmsRealtime();assert.equal(count,2);assert.equal(m.operatingPmsRealtimeSyncResult.value.status,'synced');
});

test('realtime rejects foreign receipt and preserves explicit blocked reason', async () => {
    let response=receipt({system_hotel_id:90002});
    const m=mount((url,options)=>options?.method==='POST'?response:url.includes('/current?')?current():ancillary());
    await m.syncOperatingPmsRealtime();assert.equal(m.calls.length,1);assert.equal(m.operatingPmsRealtimeSyncResult.value.status,'blocked');
    response=receipt({status:'blocked',live_read:false,readback_verified:false,message:'SYNTHETIC login required'});
    await m.syncOperatingPmsRealtime();assert.equal(m.operatingPmsRealtimeSyncResult.value.status,'blocked');
    assert.equal(m.operatingPmsRealtimeSyncResult.value.message,'SYNTHETIC login required');
    assert.equal(m.toasts[0][1],'warning');
});

test('realtime partial saved capture and historical source are not promoted to complete live success', async () => {
    const data={status:'partial',saved:true,readback_verified:true,live_read:false,historical_read:true,source_scope:'historical_single_date',failure_stage:'operating_target_sync',message:'SYNTHETIC capture saved; downstream incomplete'};
    const m=mount((url,options)=>options?.method==='POST'?receipt(data):url.includes('/current?')?current():ancillary());
    await m.syncOperatingPmsRealtime();
    for(const [key,value]of Object.entries(data))assert.equal(m.operatingPmsRealtimeSyncResult.value[key],value,key);
    assert.equal(m.toasts[0][1],'warning');
});

test('realtime binding child read cannot restore stale PMS facts after editing', async () => {
    const binding=deferred();
    const m=mount((url,options)=>options?.method==='POST'?receipt():url.includes('/current?')?current():url.includes('/pms-binding?')?binding.promise:ancillary());
    const done=m.syncOperatingPmsRealtime();await tick();
    assert.equal(m.operatingHotelPmsBindingLoading.value,true);
    m.operatingTargetForm.value.target_revenue=22222;
    binding.resolve({code:200,data:{binding_status:'configured',capture_id:99}});await done;
    assert.equal(m.operatingHotelPmsBinding.value,null);
    assert.equal(m.operatingTargetForm.value.target_revenue,22222);
    assert.equal(m.operatingHotelPmsBindingLoading.value,false);
    assert.equal(m.toasts.length,0);
});
