import assert from 'node:assert/strict';
import test from 'node:test';
import { mount } from './operating_target_save_recovery.test.mjs';

const snapshot = (id = 1) => ({ id, hotel_id: 90001, tenant_id: 9001, target_date: '2026-09-20', revision_no: id,
    readback_status: 'readback_verified', record: { facts: { actual_revenue: 0 }, calculation: { status: 'ready' } } });
const reply = list => ({ code: 200, data: { target_date: '2026-09-20', list } });
const deferred = () => { let resolve; const promise = new Promise(r => { resolve = r; }); return { promise, resolve }; };
const tick = () => new Promise(r => setImmediate(r));

test('snapshot selection survives refresh without changing current facts or draft', async () => {
    const m = mount(() => reply([snapshot(2), snapshot(1)]));
    m.operatingTargetResult.value = { id: 55, facts: { actual_revenue: 4000 } };
    await m.loadOperatingTargetSnapshots();
    m.operatingTargetSelectedSnapshot.value = m.operatingTargetSnapshots.value.list[1];
    const form = JSON.stringify(m.operatingTargetForm.value);
    await m.loadOperatingTargetSnapshots();
    assert.equal(m.operatingTargetSelectedSnapshot.value.id, 1);
    assert.equal(m.operatingTargetResult.value.id, 55);
    assert.equal(JSON.stringify(m.operatingTargetForm.value), form);
});

test('snapshot refresh failure keeps last readback explicitly stale and retry clears error', async () => {
    let failed = false;
    const m = mount(() => { if (failed) throw Error('synthetic snapshot unavailable'); return reply([snapshot()]); });
    await m.loadOperatingTargetSnapshots(); failed = true;
    await m.loadOperatingTargetSnapshots();
    assert.equal(m.operatingTargetSelectedSnapshot.value.id, 1);
    assert.match(m.operatingTargetSnapshots.value.error, /unavailable/);
    failed = false; await m.loadOperatingTargetSnapshots();
    assert.ok(!m.operatingTargetSnapshots.value.error);
});

for (const changed of ['hotel ABA', 'date ABA', 'page ABA', 'session']) test('snapshot pending read cannot revive after ' + changed, async () => {
    const pending = deferred(), m = mount(() => pending.promise);
    const done = m.loadOperatingTargetSnapshots(); await tick();
    if (changed === 'hotel ABA') { m.operatingTargetForm.value.hotel_id = '90002'; m.operatingTargetForm.value.hotel_id = '90001'; }
    if (changed === 'date ABA') { m.operatingTargetForm.value.target_date = '2026-09-21'; m.operatingTargetForm.value.target_date = '2026-09-20'; }
    if (changed === 'page ABA') { m.currentPage.value = 'home'; m.currentPage.value = 'operating-targets'; }
    if (changed === 'session') m.changeSession();
    pending.resolve(reply([snapshot()])); await done;
    assert.equal(m.operatingTargetSelectedSnapshot.value, null);
    assert.equal(m.operatingTargetSnapshots.value.list.length, 0);
    assert.equal(m.operatingTargetLoading.value.snapshots, false);
});

for (const invalid of [null, {}, { list: null }, { list: [snapshot()], target_date: '2026-09-21' },
    { list: [{ ...snapshot(), hotel_id: 90002 }] }, { list: [{ ...snapshot(), target_date: '2026-09-21' }] }]) {
    test('snapshot malformed or foreign response is not successful empty history ' + JSON.stringify(invalid), async () => {
        const m = mount(() => ({ code: 200, data: invalid }));
        await m.loadOperatingTargetSnapshots();
        assert.ok(m.operatingTargetSnapshots.value.error);
        assert.equal(m.operatingTargetSelectedSnapshot.value, null);
    });
}

test('snapshot empty and integrity-blocked entries remain distinct from verified zero', async () => {
    let list = [snapshot()]; const m = mount(() => reply(list));
    await m.loadOperatingTargetSnapshots(); assert.equal(m.operatingTargetSelectedSnapshot.value.record.facts.actual_revenue, 0);
    list = [{ ...snapshot(2), record: null, readback_status: 'snapshot_integrity_blocked', gaps: [{ message: 'scope mismatch' }] }];
    await m.loadOperatingTargetSnapshots(); assert.equal(m.operatingTargetSelectedSnapshot.value.record, null);
    assert.equal(m.operatingTargetSelectedSnapshot.value.readback_status, 'snapshot_integrity_blocked');
    list = []; await m.loadOperatingTargetSnapshots(); assert.equal(m.operatingTargetSelectedSnapshot.value, null);
    assert.ok(!m.operatingTargetSnapshots.value.error);
});
