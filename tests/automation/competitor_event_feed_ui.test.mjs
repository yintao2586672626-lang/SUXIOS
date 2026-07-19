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
const workflowDialogTemplate = fs.readFileSync(
  path.join(root, 'resources/frontend/templates/fragments/46-global-toast.html'),
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
    '\n\n            const competitorObservationOffsetDate',
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

test('authorized manual public observation saves through the API, requires readback, and refreshes the event line', () => {
  const targetLoader = sliceFrom(
    'const loadCompetitorManualObservationTargets = async (systemHotelId, platform) => {',
    '\n            const openCompetitorManualObservation',
  );
  const saver = sliceFrom(
    'const openCompetitorManualObservation = async () => {',
    '\n\n            const refreshOnlineAnalysis',
  );

  assert.match(targetLoader, /request\(`\/competitor\/targets\?\$\{params\.toString\(\)\}`\)/);
  assert.match(targetLoader, /system_hotel_id: systemHotelId/);
  assert.match(targetLoader, /platform/);
  assert.match(saver, /openWorkflowFormDialog\(\{/);
  assert.match(saver, /name: 'competitor_hotel_id'/);
  assert.match(saver, /name: 'collected_at'/);
  assert.match(saver, /name: 'availability'/);
  assert.match(saver, /name: 'price'/);
  assert.match(saver, /name: 'source_ref'/);
  assert.match(saver, /system_hotel_id: Number\(systemHotelId\)/);
  assert.match(saver, /competitor_hotel_id: Number\(values\.competitor_hotel_id\)/);
  assert.match(saver, /collected_at: String\(values\.collected_at/);
  assert.match(saver, /check_in_date: stayDate/);
  assert.match(saver, /check_out_date: competitorObservationOffsetDate\(stayDate, 1\)/);
  assert.match(saver, /source_ref: String\(values\.source_ref/);
  assert.match(saver, /request\('\/competitor\/manual-observation', \{/);
  assert.match(saver, /method: 'POST'/);
  assert.match(saver, /res\.data\?\.readback_verified !== true/);
  assert.match(saver, /await loadCompetitorEventFeed\(\)/);
  assert.doesNotMatch(saver, /competitor_price_log|INSERT INTO|UPDATE /i);

  assert.match(analysisTemplate, /data-testid="competitor-manual-observation-open"/);
  assert.match(analysisTemplate, /canCollectCompetitorObservations\(\)/);
  assert.match(workflowDialogTemplate, /data-testid="workflow-form-dialog"/);
  assert.match(workflowDialogTemplate, /workflowFormDialog\.fields/);
  assert.match(saver, /实际观测时间/);
  assert.match(saver, /本次观测来源 URL/);
  assert.match(saver, /保存并加入事件线/);
  assert.match(saver, /不会进入收益定价/);
  assert.match(saver, /保持 binding_missing/);
  assert.match(appMain, /competitorEventFeed\.value\?\.can_collect_manual_observation === true/);
  assert.doesNotMatch(appMain, /const canCollectCompetitorObservations = \(\) => !!user\.value\?\.is_super_admin \|\| userHasPermission\('can_fetch_online_data'\)/);
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
  assert.match(analysisTemplate, /event\.price === null \|\| event\.price === undefined \? \(\['sold_out', 'unavailable'\]\.includes\(event\.availability\) \? '无公开报价' : '价格缺失'\)/);
  assert.match(analysisTemplate, /event\.competitor_hotel_name \|\| '竞品名称未回填'/);
  assert.match(analysisTemplate, /competitorEventTypeText\(event\.event_type\)/);
  assert.match(analysisTemplate, /event\.secondary_event_type/);
  assert.match(analysisTemplate, /competitorEventTransitionText\(event\)/);
  assert.match(analysisTemplate, /competitorEventComparableText\(event\)/);
  assert.match(analysisTemplate, /availability_evidence_eligible_sample_count/);
  assert.match(analysisTemplate, /price_evidence_eligible_sample_count/);
  assert.match(analysisTemplate, /data-testid="competitor-event-feed-price-boundary"/);
  assert.match(analysisTemplate, /不进入收益定价/);
  assert.match(analysisTemplate, /event\.source_ref \|\| '来源引用未知'/);
  assert.match(analysisTemplate, /event\.readback_verified === true/);
  assert.match(analysisTemplate, /competitorEventEvidenceText\(event\)/);
  assert.match(analysisTemplate, /competitorEventFeedDataGapsText\(\)/);
  assert.match(appMain, /const competitorEventFeedDataGapsText = \(\) =>/);
  assert.match(appMain, /observation_ota_identity_unverified: '历史观测未记录或未核验 OTA 酒店 ID'/);
  assert.match(analysisTemplate, /event\.event_evidence_gaps\?\.length/);
  assert.match(analysisTemplate, /币种缺失/);
  assert.doesNotMatch(analysisTemplate, /event\.currency \|\| 'CNY'/);
  assert.match(analysisTemplate, /仅限携程\/美团 OTA 渠道/);
  assert.doesNotMatch(analysisTemplate, /event\.price \|\| 0/);

  const transitionFormatter = sliceFrom(
    'const competitorEventTransitionText = (event = {}) => {',
    '\n            const competitorEventComparableText',
  );
  const evidenceFormatter = sliceFrom(
    'const competitorEventEvidenceText = (event = {}) => {',
    '\n            let analysisChart',
  );
  assert.match(transitionFormatter, /transitions\.join\('；'\)/);
  assert.match(transitionFormatter, /币种缺失/);
  assert.doesNotMatch(transitionFormatter, /¥/);
  assert.match(evidenceFormatter, /event\?\.event_evidence_gaps/);
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
