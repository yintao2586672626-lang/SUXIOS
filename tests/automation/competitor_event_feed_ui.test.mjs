import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const appMain = fs.readFileSync(path.join(root, 'public/app-main.js'), 'utf8');
const analysisTemplate = fs.readFileSync(
  path.join(root, 'resources/frontend/templates/fragments/35-page-online-data.html'),
  'utf8',
);
const ctripTemplate = fs.readFileSync(
  path.join(root, 'resources/frontend/templates/fragments/24-page-ctrip-ebooking.html'),
  'utf8',
);

const sliceFrom = (start, end) => {
  const startIndex = appMain.indexOf(start);
  assert.notEqual(startIndex, -1, `missing start marker: ${start}`);
  const endIndex = appMain.indexOf(end, startIndex + start.length);
  assert.notEqual(endIndex, -1, `missing end marker: ${end}`);
  return appMain.slice(startIndex, endIndex);
};

test('unified competitor event loader reuses hotel, platform and date filters with a read-only GET', () => {
  const loader = sliceFrom(
    'const loadCompetitorEventFeed = async (options = {}) => {',
    '\n\n            const refreshOnlineAnalysis',
  );
  const refresh = sliceFrom(
    'const refreshOnlineAnalysis = async (options = {}) => {',
    '\n\n            const openOnlineAnalysisTab',
  );

  assert.match(loader, /options\.systemHotelId \|\| onlineDataFilter\.value\.hotel_id/);
  assert.match(loader, /options\.stayDate \|\| competitorEventFeedStayDate\.value/);
  assert.match(loader, /options\.platform \|\| onlineDataFilter\.value\.source/);
  assert.match(loader, /system_hotel_id: systemHotelId/);
  assert.match(loader, /platform: \['ctrip', 'meituan'\]\.includes\(source\) \? source : 'all'/);
  assert.match(loader, /stay_date: stayDate/);
  assert.match(loader, /request\(`\/competitor\/events\?\$\{params\.toString\(\)\}`\)/);
  assert.doesNotMatch(loader, /method:\s*['"](?:POST|PUT|PATCH|DELETE)['"]/i);
  assert.match(loader, /响应缺少数据对象/);
  assert.match(refresh, /loadCompetitorEventFeed\(\)/);
});

test('OTA analysis page renders complete event fields and honest loading, error and empty states', () => {
  assert.match(analysisTemplate, /data-testid="competitor-event-feed-panel"/);
  assert.match(analysisTemplate, /data-testid="competitor-event-feed-loading"/);
  assert.match(analysisTemplate, /data-testid="competitor-event-feed-error"/);
  assert.match(analysisTemplate, /data-testid="competitor-event-feed-empty"/);
  assert.match(analysisTemplate, /competitorEventFeed\.platforms/);
  assert.match(analysisTemplate, /competitorEventFeed\.system_hotel_id/);
  assert.match(analysisTemplate, /competitorEventFeed\.stay_date/);
  assert.match(analysisTemplate, /competitorEventFeed\.sample_count === null/);
  assert.match(analysisTemplate, /data-testid="competitor-event-feed-truncated"/);
  assert.match(analysisTemplate, /仅评估最新返回的/);
  assert.match(analysisTemplate, /event\.collected_at \|\| '未知'/);
  assert.match(analysisTemplate, /event\.price === null \|\| event\.price === undefined \? '未知'/);
  assert.match(analysisTemplate, /competitorEventAvailabilityText\(event\.availability\)/);
  assert.match(analysisTemplate, /event\.source_ref \|\| '来源引用未知'/);
  assert.match(analysisTemplate, /event\.readback_verified === true/);
  assert.match(analysisTemplate, /competitorEventEvidenceText\(event\)/);
  assert.match(analysisTemplate, /仅表示携程\/美团 OTA 渠道公开竞价与可订状态/);
  assert.doesNotMatch(analysisTemplate, /event\.price \|\| 0/);
});

test('Ctrip eBooking competition view binds the current hotel and end date to a visible event feed', () => {
  const ctripLoader = sliceFrom(
    'const loadCtripCompetitorEventFeed = (options = {}) => loadCompetitorEventFeed({',
    '\n            const handleCtripPublicProfileHotelChange',
  );
  const workspace = sliceFrom(
    'const openCtripCompetitorEventWorkspace = async () => {',
    '\n            const handleCtripPublicProfileHotelChange',
  );
  const tabOpen = sliceFrom(
    "if (tab === 'ctrip-public-profiles') {",
    "\n                if (tab === 'ctrip-traffic') {",
  );

  assert.match(ctripLoader, /selectedCtripHotelId\.value/);
  assert.match(ctripLoader, /platform: 'ctrip'/);
  assert.match(ctripLoader, /ctripCompetitiveOperationsRange\.value\.end_date/);
  assert.match(workspace, /onlineDataFilter\.value\.hotel_id = systemHotelId/);
  assert.match(workspace, /onlineDataFilter\.value\.source = 'ctrip'/);
  assert.match(workspace, /onlineDataFilter\.value\.start_date = stayDate/);
  assert.match(workspace, /onlineDataFilter\.value\.end_date = stayDate/);
  assert.match(workspace, /openOnlineDataEntryTab\('analysis', \{ force: true \}\)/);
  assert.match(tabOpen, /loadCtripCompetitionWorkspace\(\)/);
  assert.match(ctripTemplate, /data-testid="ctrip-competitor-event-feed-panel"/);
  assert.match(ctripTemplate, /@click="loadCtripCompetitionWorkspace"/);
  assert.match(ctripTemplate, /@click="openCtripCompetitorEventWorkspace"/);
  assert.match(ctripTemplate, /data-testid="ctrip-competitor-event-feed-loading"/);
  assert.match(ctripTemplate, /data-testid="ctrip-competitor-event-feed-error"/);
  assert.match(ctripTemplate, /data-testid="ctrip-competitor-event-feed-empty"/);
  assert.match(ctripTemplate, /selectedCtripHotelId \|\| '未选择'/);
  assert.match(ctripTemplate, /ctripCompetitiveOperationsRange\.end_date \|\| '未选择'/);
  assert.match(ctripTemplate, /ctripCompetitorEventRows/);
  assert.match(ctripTemplate, /data-testid="ctrip-competitor-event-feed-truncated"/);
  assert.match(ctripTemplate, /不代表酒店总房态、真实剩余库存或全酒店经营事实/);
});
