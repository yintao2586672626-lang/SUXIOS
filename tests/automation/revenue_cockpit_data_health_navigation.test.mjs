import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

const source = readFileSync('public/app-main.js', 'utf8');
const start = source.indexOf('const openRevenueCockpitDataHealth = async');
const end = source.indexOf('const openRevenueCockpitOperatingQuestion = async', start);
assert.ok(start >= 0 && end > start);

function harness({hotelId = '64', targetDate = '2026-09-30', navigate} = {}) {
    const calls = [], notices = [];
    const context = {
        filterReportHotel: {value: hotelId},
        permittedHotels: {value: [{id: 64}, {id: 7}]},
        revenueCockpitDataHealthOpening: {value: false},
        coreOperationsMaxDate: targetDate,
        coreOperationsHotelId: {value: '7'},
        coreOperationsTargetDate: {value: '2026-08-01'},
        dashboardHotelId: {value: '7'},
        onlineDataFilter: {value: {hotel_id: '7', source: 'meituan', start_date: '2026-08-01', end_date: '2026-08-03'}},
        resetCoreOperationsScopedState: () => calls.push({type: 'clear-old-view'}),
        showToast: (...args) => notices.push(args),
        openOnlineDataEntryTab: async (tab, options) => {
            calls.push({type: 'readonly-navigation', tab, options,
                hotelId: context.coreOperationsHotelId.value,
                dashboardHotelId: context.dashboardHotelId.value,
                date: context.coreOperationsTargetDate.value,
                filter: {...context.onlineDataFilter.value},
            });
            if (navigate) await navigate();
        },
    };
    vm.createContext(context);
    vm.runInContext(`${source.slice(start, end)}; this.open = openRevenueCockpitDataHealth;`, context);
    return {context, calls, notices, open: context.open};
}

test('empty revenue facts open only the selected hotel and explicit diagnostic date', async () => {
    const {context, calls, open} = harness();
    assert.equal(await open(), true);
    assert.equal(calls[0].type, 'clear-old-view');
    const navigation = calls[1];
    assert.equal(navigation.tab, 'data-health');
    assert.equal(navigation.options.force, true);
    assert.equal(navigation.options.delayMs, 0);
    assert.equal(navigation.hotelId, '64');
    assert.equal(navigation.dashboardHotelId, '64');
    assert.equal(navigation.date, '2026-09-30');
    assert.deepEqual(navigation.filter, {hotel_id: '64', source: '', start_date: '2026-09-30', end_date: '2026-09-30'});
    assert.equal(context.revenueCockpitDataHealthOpening.value, false);
});

test('an unchanged hotel and diagnostic date retain the current read view', async () => {
    const {context, calls, open} = harness();
    context.coreOperationsHotelId.value = '64';
    context.coreOperationsTargetDate.value = '2026-09-30';
    assert.equal(await open(), true);
    assert.equal(calls.length, 1);
    assert.equal(calls[0].type, 'readonly-navigation');
});

test('missing, malformed and inaccessible hotels cannot change the view or start reads', async () => {
    for (const hotelId of ['', '0', '-64', '64abc', '999']) {
        const {context, calls, notices, open} = harness({hotelId});
        assert.equal(await open(), false);
        assert.equal(calls.length, 0);
        assert.equal(context.coreOperationsHotelId.value, '7');
        assert.equal(notices[0][1], 'warning');
    }
});

test('missing diagnostic date cannot reuse an old hotel date', async () => {
    const {context, calls, notices, open} = harness({targetDate: ''});
    assert.equal(await open(), false);
    assert.equal(calls.length, 0);
    assert.equal(context.coreOperationsTargetDate.value, '2026-08-01');
    assert.equal(notices[0][1], 'warning');
});

test('repeated clicks do not start a second read navigation', async () => {
    let release;
    const pending = new Promise(resolve => { release = resolve; });
    const {context, calls, open} = harness({navigate: () => pending});
    const first = open();
    assert.equal(context.revenueCockpitDataHealthOpening.value, true);
    assert.equal(await open(), false);
    assert.equal(calls.filter(call => call.type === 'readonly-navigation').length, 1);
    release();
    assert.equal(await first, true);
    assert.equal(context.revenueCockpitDataHealthOpening.value, false);
});

test('read failures retain the exact hotel and release the button for retry', async () => {
    let fail = true;
    const {context, calls, notices, open} = harness({navigate: () => {
        if (fail) throw new Error('synthetic read failure');
    }});
    assert.equal(await open(), false);
    assert.equal(context.coreOperationsHotelId.value, '64');
    assert.equal(context.coreOperationsTargetDate.value, '2026-09-30');
    assert.equal(context.revenueCockpitDataHealthOpening.value, false);
    assert.equal(notices[0][1], 'error');
    fail = false;
    assert.equal(await open(), true);
    assert.equal(calls.filter(call => call.type === 'readonly-navigation').length, 2);
});
