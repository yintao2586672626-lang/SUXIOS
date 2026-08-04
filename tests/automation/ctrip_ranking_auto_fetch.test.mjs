import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';

const root = process.cwd();
const appMain = fs.readFileSync(path.join(root, 'public', 'app-main.js'), 'utf8');
const template = fs.readFileSync(path.join(root, 'resources', 'frontend', 'templates', 'fragments', '24-page-ctrip-ebooking.html'), 'utf8');

const sliceFrom = (start, end) => {
  const startIndex = appMain.indexOf(start);
  const endIndex = appMain.indexOf(end, startIndex);
  assert.ok(startIndex >= 0, `missing start marker: ${start}`);
  assert.ok(endIndex > startIndex, `missing end marker: ${end}`);
  return appMain.slice(startIndex, endIndex);
};

const policySource = sliceFrom(
  'const resolveCtripRankingCachePolicy = ({',
  "const buildTruthfulCtripDisplayModel = requireCtripStatic('buildTruthfulCtripDisplayModel');"
);
const policySandbox = {};
vm.runInNewContext(
  `${policySource}\nthis.__resolveCtripRankingCachePolicy = resolveCtripRankingCachePolicy;`,
  policySandbox,
  { filename: 'public/app-main.js#resolveCtripRankingCachePolicy' }
);
const resolveCtripRankingCachePolicy = policySandbox.__resolveCtripRankingCachePolicy;

test('Ctrip competition circle reads a trusted today snapshot before automatically fetching', () => {
  const startup = sliceFrom(
    'const scheduleCtripEbookingDeferredStartupRefresh = () => {',
    'const MEITUAN_EBOOKING_STARTUP_CONFIG_DELAY_MS'
  );
  const tabOpen = sliceFrom(
    'const openCtripManualTab = (tab) => {',
    "if (tab === 'ctrip-public-profiles') {"
  );
  const fetch = sliceFrom(
    'const fetchCtripData = async (options = {}) => {',
    '// 美团ebooking数据获取'
  );

  assert.match(startup, /hydrateDisplay: false/);
  assert.match(startup, /returnSnapshot: true/);
  assert.match(startup, /syncCtripOverviewTargetHotel\(\{ loadConfig: true \}\)/);
  assert.match(startup, /resolveCtripRankingCachePolicy/);
  assert.match(startup, /applyLatestCtripSnapshot\(latestSnapshot\.payload, \{ hydrateDisplay: true \}\)/);
  assert.match(startup, /status: 'cache_hit'/);
  assert.match(startup, /未重复调用携程接口/);
  assert.match(startup, /!fetchingData\.value/);
  assert.match(startup, /canFetchCtripManualData\(\)/);
  assert.match(startup, /fetchCtripData\(\{ automatic: true \}\)/);
  assert.match(tabOpen, /clearCtripRankingDisplayState\(\);/);
  assert.match(tabOpen, /scheduleCtripEbookingDeferredStartupRefresh\(\);/);
  assert.match(fetch, /if \(fetchingData\.value\) return \{ status: 'busy' \};/);
  assert.match(template, /fetchingData \? '获取中\.\.\.' : '重新获取'/);
  assert.match(template, /进入本页后会自动获取当前门店/);
  assert.doesNotMatch(template, /选择门店并点击“获取数据”后/);
});

const trustedPayload = () => ({
  metadata: {
    hotel_id: '80',
    platform: 'ctrip',
    status: 'success',
    target_data_date: '2026-08-03',
    fetched_at: '2026-08-04 09:12:00',
    ranking_cache_eligible: true,
    ranking_cache_reason: 'trusted_today_snapshot',
  },
  rank: {
    status: 'success',
    verification_status: 'source_verified',
    data_date: '2026-08-03',
    target_data_date: '2026-08-03',
    fetched_at: '2026-08-04 09:12:00',
    collected_date: '2026-08-04',
    total: 2,
    display_hotels: [{ hotelId: 'self' }, { hotelId: 'competitor' }],
    identity_check: { ok: true },
    readback_verified: true,
    source_verified: true,
    cache_eligible: true,
    cache_reason: 'trusted_today_snapshot',
    traffic_fallback: null,
  },
});

test('trusted same-hotel, same-date and same-day database snapshot is a cache hit', () => {
  const result = resolveCtripRankingCachePolicy({
    payload: trustedPayload(),
    hotelId: '80',
    today: '2026-08-04',
  });

  assert.equal(result.hit, true);
  assert.equal(result.reason, 'trusted_today_snapshot');
  assert.equal(result.dataDate, '2026-08-03');
});

test('another hotel, an older collection day, or incomplete readback never suppresses fetching', () => {
  const otherHotel = resolveCtripRankingCachePolicy({
    payload: trustedPayload(),
    hotelId: '81',
    today: '2026-08-04',
  });
  assert.equal(otherHotel.hit, false);
  assert.equal(otherHotel.reason, 'hotel_identity_mismatch');

  const oldPayload = trustedPayload();
  oldPayload.rank.collected_date = '2026-08-03';
  const oldCollection = resolveCtripRankingCachePolicy({
    payload: oldPayload,
    hotelId: '80',
    today: '2026-08-04',
  });
  assert.equal(oldCollection.hit, false);
  assert.equal(oldCollection.reason, 'not_collected_today');

  const partialPayload = trustedPayload();
  partialPayload.rank.readback_verified = false;
  partialPayload.rank.cache_eligible = false;
  partialPayload.metadata.ranking_cache_eligible = false;
  const incomplete = resolveCtripRankingCachePolicy({
    payload: partialPayload,
    hotelId: '80',
    today: '2026-08-04',
  });
  assert.equal(incomplete.hit, false);
  assert.equal(incomplete.reason, 'database_readback_incomplete');
});
