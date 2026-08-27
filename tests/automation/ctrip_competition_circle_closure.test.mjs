import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';
import { readSourceAggregate } from '../../scripts/lib/source_aggregate.mjs';
import { readFrontendContractSource } from './helpers/frontend_source.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

function loadAiAnalysisStatic() {
  const source = fs.readFileSync(path.join(root, 'public/ai-analysis-static.js'), 'utf8');
  const context = { window: {}, console, setTimeout, clearTimeout };
  vm.createContext(context);
  vm.runInContext(source, context, { filename: 'ai-analysis-static.js' });
  return context.window.SUXI_AI_ANALYSIS_STATIC;
}

test('Ctrip AI selection exposes only the owned self hotel and keeps peers read-only', () => {
  const api = loadAiAnalysisStatic();
  const result = api.buildCtripAiAnalysisHotelSelection({
    ctripHotels: [
      {
        hotelId: '832085',
        hotelName: '我的酒店',
        systemHotelName: '巢湖测试',
        compareType: 'self',
        isSelf: true,
        quantity: 10,
        amount: 1280,
      },
      {
        hotelId: '688665',
        hotelName: '巢湖碧桂园凤悦凤凰酒店',
        compareType: 'competitor',
        isSelf: false,
        quantity: 20,
        amount: 2600,
      },
    ],
    selectedKeys: [],
  });

  assert.equal(result.hotels.length, 1);
  assert.equal(result.hotels[0].hotelName, '巢湖测试');
  assert.equal(result.hotels[0].poiId, '832085');
  assert.equal(result.comparisonHotels.length, 1);
  assert.equal(result.comparisonHotels[0].hotelName, '巢湖碧桂园凤悦凤凰酒店');
});

test('Ctrip UI removes the unsupported all-channel AI room-night estimate', () => {
  const html = readFrontendContractSource();
  const ctripStatic = fs.readFileSync(path.join(root, 'public/ctrip-static.js'), 'utf8');

  assert.doesNotMatch(html, /全渠道AI预计总间夜数|aiEstimatedTotalRoomNights|ai_estimated_total_room_nights/);
  assert.match(ctripStatic, /buildTruthfulCtripDisplayModel/);
  assert.doesNotMatch(ctripStatic, /field === 'aiEstimatedTotalRoomNights'/);
});

test('OTA pages can read one exact stored business date without triggering collection', () => {
  const appMain = fs.readFileSync(path.join(root, 'public/app-main.js'), 'utf8');
  const ctripTemplate = fs.readFileSync(
    path.join(root, 'resources/frontend/templates/fragments/24-page-ctrip-ebooking.html'),
    'utf8',
  );
  const meituanTemplate = fs.readFileSync(
    path.join(root, 'resources/frontend/templates/fragments/26-page-meituan-ebooking.html'),
    'utf8',
  );

  assert.match(ctripTemplate, /@click="loadSelectedCtripStoredBusinessDate"/);
  assert.match(ctripTemplate, /读取已保存（不采集）/);
  assert.match(appMain, /const loadSelectedCtripStoredBusinessDate = async \(\) => \{/);
  assert.match(appMain, /if \(selectedHotelId\) \{[\s\S]{0,180}params\.append\('hotel_id', selectedHotelId\);[\s\S]{0,180}params\.append\('system_hotel_id', selectedHotelId\);/);
  assert.match(appMain, /businessContext: \{ hotelId: selectedHotelId, platform: 'ctrip' \}/);
  assert.match(appMain, /onlineHistoryFilter\.value\?\.hotel_id[\s\S]{0,500}businessContext: \{ hotelId: scopedHotelId, platform: scopedPlatform \}/);
  assert.match(appMain, /hydrateDisplay: true,[\s\S]{0,80}range: startDate/);
  assert.match(appMain, /未使用其他日期填充/);
  assert.match(appMain, /const targetSelfRows = targetRows\.filter\(row => ctripTrafficRowRole\(row\) === 'self'\)/);
  assert.match(appMain, /仅回读竞争圈数据，本店携程流量事实未返回/);
  assert.match(appMain, /const scopedRows = selfRows;/);
  assert.doesNotMatch(appMain, /const scopedRows = selfRows\.length \? selfRows : rows;/);
  assert.match(appMain, /const formatCtripTrafficSummaryMetric = \(role, metric\) => \{[\s\S]{0,220}return '未返回'/);
  assert.match(ctripTemplate, /formatCtripTrafficSummaryMetric\('self', metric\)/);
  assert.match(ctripTemplate, /formatCtripTrafficSummaryMetric\('avg', metric\)/);
  assert.match(appMain, /const readStoredOtaTrafficGate = async \(\{ hotelId, platform, businessDate \}\) => \{/);
  assert.match(appMain, /hotel_id: String\(hotelId\),[\s\S]{0,120}system_hotel_id: String\(hotelId\)/);
  assert.match(appMain, /p0_traffic_gate_status \|\| 'not_loaded'/);
  assert.match(appMain, /gate\.status !== 'ready'[\s\S]{0,180}不标记为可信流量事实/);

  assert.match(meituanTemplate, /dateRanges\.includes\('custom'\) \? openMeituanStoredBusinessDate\(\) : switchToMeituanDownloadCenter\(\)/);
  assert.match(appMain, /const openMeituanStoredBusinessDate = async \(\) => \{/);
  assert.match(appMain, /onlineDataFilter\.value\.start_date = startDate;[\s\S]{0,120}onlineDataFilter\.value\.end_date = endDate;/);
});

test('competition-circle selector keeps persistence guidance concise', () => {
  const html = readFrontendContractSource();

  assert.match(html, /data-testid="platform-hotel-context-search"[^>]*role="combobox"/);
  assert.match(html, /@mousedown\.prevent="selectPlatformHotelOption\(hotel\)"/);
  assert.match(html, /handlePlatformHotelContextChange\(\{ target: \{ value: hotelId \} \}\)/);
  assert.match(html, /class="text-xs font-medium text-slate-500">当前酒店/);
  assert.match(html, /请先在顶栏选择酒店/);
  assert.doesNotMatch(html, />数据归属门店<\/label>/);
  assert.doesNotMatch(html, /获取携程竞争圈数据/);
  assert.doesNotMatch(html, /选择门店：使用已保存授权并入库；临时 Cookie：仅查询，不入库/);
  assert.match(html, /仅用于本次查询，不保存 Cookie、不创建门店、不入库/);
  assert.doesNotMatch(html, /v-if="ctripBusinessSourceNotice"/);
});

test('history labels a competition circle with its owning system hotel', () => {
  const history = fs.readFileSync(path.join(root, 'app/controller/concern/OnlineDataHistoryConcern.php'), 'utf8');

  assert.match(history, /system_hotel_name/);
  assert.match(history, /'竞争圈（'\s*\.\s*number_format\(\$hotelCount\)\s*\.\s*'家）'/);
});

test('Ctrip AI tab hydrates the latest usable competition circle snapshot', () => {
  const html = readFrontendContractSource();
  const history = fs.readFileSync(path.join(root, 'app/controller/concern/OnlineDataHistoryConcern.php'), 'utf8');

  assert.match(html, /context\.source === 'ctrip'[\s\S]{0,160}await loadLatestCtripData\(\{ silent: true, hydrateDisplay: true \}\)/);
  assert.match(html, /const aiAnalysisStaticVersion = '20260715-unverified-preview-hd13ecd982e'/);
  assert.match(html, /script\.src = aiAnalysisStaticScript \+ '\?v=' \+ aiAnalysisStaticVersion/);
  assert.match(html, /v-if="ctripLatestLoading"[\s\S]{0,300}正在读取当前门店最近一批竞争圈数据[\s\S]{0,300}v-else-if="aiAnalysisHotelList\.length === 0"/);
  assert.match(history, /\$section === 'rank'\s*&&\s*\(empty\(\$displayHotels\)\s*\|\|\s*!\$this->ctripBusinessDisplayHotelsHaveTraffic\(\$displayHotels\)\)/);
});

test('historical backfill is dry-run capable, idempotent, and uses configured hotel IDs', () => {
  const source = fs.readFileSync(path.join(root, 'scripts/backfill_ctrip_competition_circle_history.php'), 'utf8');

  assert.match(source, /--dry-run/);
  assert.match(source, /hasCompetitionCircleSignature/);
  assert.match(source, /legacy_backfill:/);
  assert.match(source, /competition_circle_hotel/);
  assert.match(source, /already_classified/);
  assert.match(source, /\$identitySource = 'current_platform_binding';/);
  assert.match(source, /\$selfIds = \$configuredSelfIds;/);
  assert.doesNotMatch(source, /historical_generic_self_marker/);
});

test('legacy Ctrip business parser routes competition-circle signatures to the typed persistence service', () => {
  const source = fs.readFileSync(path.join(root, 'app/controller/concern/BusinessDisplayConcern.php'), 'utf8');
  const parser = source.match(/private function parseAndSaveData[\s\S]*?private function persistCtripCompetitionCircleRowsFromLegacyParser/);

  assert.ok(parser, 'expected legacy Ctrip parser method');
  assert.match(source, /use app\\service\\CtripCompetitionCirclePersistenceService;/);
  assert.match(source, /hasCompetitionCircleSignature/);
  assert.match(source, /persistRows/);
  assert.match(parser[0], /where\('data_type', 'business'\)/);
  assert.match(parser[0], /\$businessDimension = \$this->ctripBusinessPersistenceDimension\(\$persistenceContext\)/);
  assert.match(parser[0], /where\('dimension', \$businessDimension\)/);
  assert.match(parser[0], /manual_input_unverified/);
  assert.match(parser[0], /'ordamount'/);
  assert.match(parser[0], /'ordquantity'/);
  assert.match(parser[0], /'compare_type'\s*=>[\s\S]{0,120}\?: null/);
});

test('typed competition persistence never reuses an unrelated business row', () => {
  const source = fs.readFileSync(path.join(root, 'app/service/CtripCompetitionCirclePersistenceService.php'), 'utf8');
  const finder = source.match(/private function findExistingCompetitionRow[\s\S]*?private static function appendQualityFlags/);

  assert.ok(finder, 'expected typed competition-row lookup');
  assert.match(finder[0], /where\('data_type', self::DATA_TYPE\)/);
  assert.match(finder[0], /where\('dimension', self::DIMENSION\)/);
  assert.doesNotMatch(finder[0], /where\('data_type', 'business'\)/);
});

test('competition identity is ID-only and legacy persistence creates evidence tasks', () => {
  const persistence = fs.readFileSync(path.join(root, 'app/service/CtripCompetitionCirclePersistenceService.php'), 'utf8');
  const legacy = fs.readFileSync(path.join(root, 'app/controller/concern/BusinessDisplayConcern.php'), 'utf8');
  const autoFetch = readSourceAggregate(
    'app/controller/concern/AutoFetchConcern.php',
    { repoRoot: root },
  );

  assert.match(persistence, /\$isSelf = \$hotelId !== '' && isset\(\$selfHotelIds\[\$hotelId\]\);/);
  assert.doesNotMatch(persistence, /\$isSelf\s*=\s*[^;]*hasExplicitSelfMarker/);
  assert.match(legacy, /resolveOrCreateDataSource\([\s\S]*startSyncTask\([\s\S]*finishSyncTask\(/);
  assert.match(autoFetch, /\$competitionPersistenceContext\['self_hotel_ids'\][\s\S]*\$requestHotelId/);
});

test('identity conflicts use only active self bindings and successful saves surface ID warnings', () => {
  const backend = readSourceAggregate(
    'app/controller/concern/AutoFetchConcern.php',
    { repoRoot: root },
  );
  const frontend = fs.readFileSync(path.join(root, 'public/ctrip-static.js'), 'utf8');
  const conflictMethod = backend.match(/private function findCtripPlatformHotelIdConflicts[\s\S]*?\n    private function getSystemHotelName/);

  assert.ok(conflictMethod, 'expected Ctrip conflict method');
  assert.match(conflictMethod[0], /where\('d\.compare_type', 'self'\)/);
  assert.match(conflictMethod[0], /where\('h\.status', 1\)/);
  assert.match(frontend, /identityCheckWarning\s*=\s*data\.identity_check\?\.warning/);
  assert.match(frontend, /notify\(data\.identity_check\?\.message[^\n]*'warning'\)/);
});

test('historical identity repair is dry-run first and only infers unbound batches from explicit self evidence', () => {
  const source = fs.readFileSync(path.join(root, 'scripts/repair_ctrip_competition_circle_identity.php'), 'utf8');

  assert.match(source, /--dry-run/);
  assert.match(source, /--execute/);
  assert.match(source, /normalizeRowSemantics/);
  assert.match(source, /current_ready_config/);
  assert.match(source, /unique_active_self_history/);
  assert.match(source, /bound_owner_mismatch/);
  assert.match(source, /bound_owner_mismatch_do_not_reassign/);
  assert.doesNotMatch(source, /historical_owner_reassigned_from_bound_owner_mismatch/);
  assert.match(source, /\$snapshot[^;]*\. '\|' \. trim\(\(string\)\(\$row\['create_time'\]/);
  assert.match(source, /count\(\$group\['self_hotel_ids'\]\) !== 1/);
  assert.match(source, /binding_missing/);
  assert.match(source, /'update_time' => \$row\['update_time'\]/);
  assert.match(source, /quality_repair_candidate_rows/);
  assert.match(source, /evidence_repair_candidate_rows/);
  assert.match(source, /evidence_missing:data_source_id/);
  assert.match(source, /evidence_missing:sync_task_id/);
});

test('traffic persistence keeps system hotel ownership separate from the OTA hotel identity', () => {
  const persistence = fs.readFileSync(path.join(root, 'app/service/OnlineDailyDataPersistenceService.php'), 'utf8');
  const analytics = fs.readFileSync(path.join(root, 'app/controller/concern/OnlineDataAnalyticsConcern.php'), 'utf8');
  const manualFetch = fs.readFileSync(path.join(root, 'app/controller/concern/OnlineDataManualFetchConcern.php'), 'utf8');
  const autoFetch = readSourceAggregate(
    'app/controller/concern/AutoFetchConcern.php',
    { repoRoot: root },
  );
  const ctripParser = persistence.match(/private function parseAndSaveCtripTrafficData[\s\S]*?private function parseAndSaveGenericTrafficData/);

  assert.ok(ctripParser, 'expected isolated Ctrip traffic parser');
  assert.match(persistence, /parseAndSaveCtripTrafficData\([^;]*\$expectedPlatformHotelId,\s*\$ingestionMethod\s*\)/);
  assert.match(ctripParser[0], /hash_equals\(\$expectedPlatformHotelId, \(string\)\$hotelId\)/);
  assert.match(ctripParser[0], /explicit self row with a different platform ID[\s\S]*?continue;/i);
  assert.match(ctripParser[0], /\$compareType = \$isAverage \? 'competitor_avg' : \(\$isCompetitor \? 'competitor' : 'self'\)/);
  assert.doesNotMatch(ctripParser[0], /\$hotelId\s*=\s*\$systemHotelId/);
  assert.match(analytics, /\$expectedPlatformHotelId[\s\S]*?OnlineDailyDataPersistenceService[\s\S]*?\$expectedPlatformHotelId/);
  assert.match(manualFetch, /\$credentialPayload\['platform_hotel_id'\][\s\S]*?parseAndSaveTrafficData\([\s\S]*?\$expectedPlatformHotelId/);
  assert.match(autoFetch, /\$credentialPayload\['platform_hotel_id'\][\s\S]*?parseAndSaveTrafficData\([^;]*\$expectedPlatformHotelId,\s*'manual_cookie_api'\s*\)/);
  assert.match(autoFetch, /parseAndSaveTrafficData\(\s*\['data' => \['list' => \$trafficRows\]\][^;]*\$requestHotelId,\s*'browser_profile'\s*\)/);
  assert.match(persistence, /'platform' => \$platform,[\s\S]*?'ingestion_method' => \$ingestionMethod/);
});
