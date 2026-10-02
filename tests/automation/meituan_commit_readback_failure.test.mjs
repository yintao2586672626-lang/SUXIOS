import { readSourceAggregate as readStaticContractSource } from '../../scripts/lib/source_aggregate.mjs';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readStaticContractSource('public/meituan-static.js');
const sandbox = { console, window: {} };
vm.runInNewContext(`${source}\nthis.api = window.SUXI_MEITUAN_STATIC;`, sandbox);

async function runScenario(requestCommit) {
  const notices = [], results = [];
  let historyRefreshes = 0;
  const outcome = await sandbox.api.runMeituanBatchFetchFlow({
    getForm: () => ({ hotelId: 58, poiId: 'self', dateRanges: ['0'] }),
    getSelectedConfig: () => ({
      id: 'meituan-58', config_id: 'meituan-58', hotel_id: 58,
      has_cookies: true, credential_status: 'ready', configuration_verified: true,
    }),
    requestFetch: async body => ({
      code: 200,
      data: {
        saved_count: 0,
        rank_candidate: {
          candidate_id: body.rank_type, config_id: body.config_id,
          system_hotel_id: body.system_hotel_id, poi_id: 'self',
          start_date: '2026-07-12', end_date: '2026-07-12',
          date_range: body.date_range, rank_type: body.rank_type,
        },
        data: { status: 0, data: { peerRankData: [
          { aiMetricName: `${body.rank_type}_A`, roundRanks: [{ poiId: 'self', rank: 1, dataValue: 0, percent: 100 }] },
          { aiMetricName: `${body.rank_type}_B`, roundRanks: [{ poiId: 'self', rank: 1, dataValue: 80, percent: 80 }] },
        ] } },
        display_hotels: [{ poiId: 'self', hotelName: 'Self Hotel', isSelf: true }],
      },
    }),
    requestCommit,
    requestDisplayModel: async () => ({ code: 200, data: { display_hotels: [] } }),
    notify: (message, level) => notices.push({ message, level }),
    setOnlineDataResult: value => results.push(value),
    refreshOnlineHistory: async () => { historyRefreshes++; },
  });
  return { outcome, notices, results, historyRefreshes };
}

const readbackFailure = () => ({ code: 500, message: 'Database readback failed', data: {
  reason: 'meituan_rank_candidate_readback_failed', saved_count: 2,
} });

test('explicitly failed Meituan readback cannot inherit a verified status label', () => {
  const task = { rankType: 'P_RZ', rankName: '入住率', dateRange: '0', dateRangeName: '今天' };
  const contradictory = sandbox.api.buildMeituanBatchFetchResultEntry(task, {
    code: 200,
    data: { saved_count: 1, persistence_status: 'readback_verified', readback_verified: false },
  });
  assert.equal(contradictory.readbackVerified, false);
  const [display] = sandbox.api.applyMeituanFetchHealthToRows([{ key: 'P_RZ' }], [{
    ...contradictory, rankDataComplete: true, rankDataMode: 'derived',
  }]);
  assert.equal(display.status, 'partial');
  assert.equal(display.statusText, '回读未核验');
  assert.doesNotMatch(display.sourceLabel, /已完成数据库回读核验/);
  const legacy = sandbox.api.buildMeituanBatchFetchResultEntry(task, {
    code: 200,
    data: { saved_count: 1, persistence_status: 'readback_verified' },
  });
  assert.equal(legacy.readbackVerified, true, 'status-only legacy receipt remains readable');
});

test('Meituan commit readback failure reports unverified writes and refreshes history', async () => {
  const { outcome, notices, results, historyRefreshes } = await runScenario(readbackFailure);
  assert.equal(outcome.status, 'readback_unverified');
  assert.equal(outcome.totalSavedCount, 0, 'failed verification is not a verified save');
  assert.equal(outcome.unverifiedSavedCount, 8);
  assert.equal(historyRefreshes, 1);
  assert.equal(results.at(-1).every(row => row.readbackVerified !== true), true);
  assert.match(notices.at(-1).message, /8 条.*回读未核验/);
  assert.equal(notices.at(-1).level, 'warning');
});

test('Meituan commit with an explicit failed readback remains unverified after merge', async () => {
  const { outcome, notices, results, historyRefreshes } = await runScenario(async () => ({
    code: 200,
    data: { saved_count: 1, persistence_status: 'readback_verified', readback_verified: false },
  }));
  assert.equal(outcome.verifiedSavedCount, 0);
  assert.equal(outcome.unverifiedSavedCount, 4);
  assert.equal(outcome.totalSavedCount, 0);
  assert.equal(outcome.status, 'readback_unverified');
  assert.equal(results.at(-1).every(row => row.readbackVerified !== true), true);
  assert.equal(results.at(-1).every(row => row.savedCount === 0 && row.terminalPartial === true), true);
  assert.equal(sandbox.api.buildMeituanFetchPresentation(results.at(-1)).isPartial, true);
  const [display] = sandbox.api.applyMeituanFetchHealthToRows([{ key: 'P_RZ' }], results.at(-1));
  assert.equal(display.status, 'partial');
  assert.equal(historyRefreshes, 1);
  assert.equal(notices.at(-1).level, 'warning');
});

test('verified and unverified Meituan commits remain separate in a partial batch', async () => {
  const { outcome, notices, historyRefreshes } = await runScenario(async body => (
    body.rank_type === 'P_RZ'
      ? readbackFailure()
      : { code: 200, data: { saved_count: 1, persistence_status: 'readback_verified' } }
  ));
  assert.equal(outcome.status, 'partial');
  assert.equal(outcome.verifiedSavedCount, 3);
  assert.equal(outcome.unverifiedSavedCount, 2);
  assert.equal(historyRefreshes, 1);
  assert.match(notices.at(-1).message, /3 条.*回读核验/);
  assert.match(notices.at(-1).message, /2 条.*回读未核验/);
  assert.equal(notices.at(-1).level, 'warning');
});

test('HTTP error envelope also preserves reported but unverified writes', async () => {
  const { outcome, notices, historyRefreshes } = await runScenario(async () => {
    const error = new Error('Database readback failed');
    error.data = { code: 500, data: {
      reason: 'meituan_rank_candidate_readback_failed', saved_count: 2,
    } };
    throw error;
  });
  assert.equal(outcome.status, 'readback_unverified');
  assert.equal(outcome.unverifiedSavedCount, 8);
  assert.equal(historyRefreshes, 1);
  assert.equal(notices.at(-1).level, 'warning');
});
