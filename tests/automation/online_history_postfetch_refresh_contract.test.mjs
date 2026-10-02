import assert from 'node:assert/strict';
import {test} from 'node:test';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync('public/app-main.js', 'utf8');
const schedulerStart = source.indexOf('const scheduleOnlineHistoryRefresh =');
const schedulerEnd = source.indexOf('const scheduleLatestCtripRefresh =', schedulerStart);
const refreshStart = source.indexOf('const refreshOnlineHistory = async');
const refreshEnd = source.indexOf('const resetOnlineHistoryFilter =', refreshStart);
assert.ok(schedulerStart >= 0 && schedulerEnd > schedulerStart && refreshStart >= 0 && refreshEnd > refreshStart);

const script = `${source.slice(refreshStart, refreshEnd)}
${source.slice(schedulerStart, schedulerEnd)}
globalThis.schedule = scheduleOnlineHistoryRefresh;
globalThis.refresh = refreshOnlineHistory;`;

test('post-fetch history refresh reloads records without re-reading unchanged hotel options', async () => {
  const scheduled = [];
  const hotelOptions = [{value: '80', label: 'Current hotel'}];
  let historyRefreshes = 0;
  const env = {
    window: {SUXI_DATA_HEALTH_STATIC: {}},
    schedulePostFetchRefresh: (...args) => scheduled.push(args),
    onlineHistoryHotelListLoaded: {value: true},
    onlineHistoryHotelList: {value: hotelOptions},
    loadOnlineHistory: async () => { historyRefreshes += 1; return true; },
    loadOnlineHistoryHotelList: async () => assert.fail('post-fetch refresh should not reload hotel options'),
  };
  const context = vm.createContext(env);
  vm.runInContext(script, context);

  const isCurrentRequest = () => true;
  context.schedule(isCurrentRequest);
  assert.equal(scheduled.length, 1);
  const [key, run, delay, guard] = scheduled[0];
  assert.equal(key, 'online-history');
  assert.equal(delay, 340);
  assert.equal(guard, isCurrentRequest);

  await run();
  assert.equal(historyRefreshes, 1);
  assert.equal(env.onlineHistoryHotelListLoaded.value, true);
  assert.equal(env.onlineHistoryHotelList.value, hotelOptions);
});
