import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

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

test('Ctrip UI keeps the requested estimate title and discloses derivation and health formula', () => {
  const html = fs.readFileSync(path.join(root, 'public/index.html'), 'utf8');
  const backend = fs.readFileSync(path.join(root, 'app/controller/concern/BusinessDisplayConcern.php'), 'utf8');

  assert.match(html, /全渠道AI预计总间夜数/);
  assert.match(backend, /AI推导，非平台原始字段/);
  assert.match(backend, /房价离散系数=价格标准差\/圈内平均房价/);
  assert.doesNotMatch(backend, /\[INF,\s*'恶化'/);
});

test('competition-circle copy assigns the result to the selected store and treats ID gaps as warnings', () => {
  const html = fs.readFileSync(path.join(root, 'public/index.html'), 'utf8');

  assert.match(html, /本次结果统一归属于所选门店的竞争圈/);
  assert.match(html, /酒店ID缺失或不一致会提示但不阻断查询/);
  assert.match(html, /明确的本店跨门店冲突才停止入库/);
  assert.doesNotMatch(html, /识别不到时只展示数据、不入库/);
});

test('history labels a competition circle with its owning system hotel', () => {
  const history = fs.readFileSync(path.join(root, 'app/controller/concern/OnlineDataHistoryConcern.php'), 'utf8');

  assert.match(history, /system_hotel_name/);
  assert.match(history, /'竞争圈（'\s*\.\s*number_format\(\$hotelCount\)\s*\.\s*'家）'/);
});

test('Ctrip AI tab hydrates the latest usable competition circle snapshot', () => {
  const html = fs.readFileSync(path.join(root, 'public/index.html'), 'utf8');
  const history = fs.readFileSync(path.join(root, 'app/controller/concern/OnlineDataHistoryConcern.php'), 'utf8');

  assert.match(html, /context\.source === 'ctrip'[\s\S]{0,160}await loadLatestCtripData\(\{ silent: true, hydrateDisplay: true \}\)/);
  assert.match(html, /const aiAnalysisStaticVersion = '20260711-ctrip-competition-circle-v1'/);
  assert.match(html, /script\.src = aiAnalysisStaticScript \+ '\?v=' \+ aiAnalysisStaticVersion/);
  assert.match(html, /v-if="ctripLatestLoading"[\s\S]{0,300}正在读取当前门店最近一批竞争圈数据[\s\S]{0,300}v-else-if="aiAnalysisHotelList\.length === 0"/);
  assert.match(history, /\$section === 'rank'\s*&&\s*\(empty\(\$displayHotels\)\s*\|\|\s*!\$this->ctripBusinessDisplayHotelsHaveTraffic\(\$displayHotels\)\)/);
});

test('historical backfill is dry-run capable, idempotent, and scoped to the competition signature', () => {
  const source = fs.readFileSync(path.join(root, 'scripts/backfill_ctrip_competition_circle_history.php'), 'utf8');

  assert.match(source, /--dry-run/);
  assert.match(source, /hasCompetitionCircleSignature/);
  assert.match(source, /legacy_backfill:/);
  assert.match(source, /competition_circle_hotel/);
  assert.match(source, /already_classified/);
  assert.match(source, /\$selfIds = \$genericSelfIds !== \[\][\s\S]{0,100}\? array_keys\(\$genericSelfIds\)[\s\S]{0,100}: \$configuredSelfIds/);
});

test('legacy Ctrip business parser routes competition-circle signatures to the typed persistence service', () => {
  const source = fs.readFileSync(path.join(root, 'app/controller/concern/BusinessDisplayConcern.php'), 'utf8');
  const parser = source.match(/private function parseAndSaveData[\s\S]*?private function persistCtripCompetitionCircleRowsFromLegacyParser/);

  assert.ok(parser, 'expected legacy Ctrip parser method');
  assert.match(source, /use app\\service\\CtripCompetitionCirclePersistenceService;/);
  assert.match(source, /hasCompetitionCircleSignature/);
  assert.match(source, /persistRows/);
  assert.match(parser[0], /where\('data_type', 'business'\)/);
  assert.match(parser[0], /where\('dimension', ''\)/);
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

test('identity conflicts use only active self bindings and successful saves surface ID warnings', () => {
  const backend = fs.readFileSync(path.join(root, 'app/controller/concern/AutoFetchConcern.php'), 'utf8');
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
  assert.match(source, /\$snapshot[^;]*\. '\|' \. trim\(\(string\)\(\$row\['create_time'\]/);
  assert.match(source, /count\(\$group\['self_hotel_ids'\]\) !== 1/);
  assert.match(source, /binding_missing/);
  assert.match(source, /'update_time' => \$row\['update_time'\]/);
  assert.match(source, /quality_repair_candidate_rows/);
  assert.match(source, /evidence_repair_candidate_rows/);
  assert.match(source, /evidence_missing:data_source_id/);
  assert.match(source, /evidence_missing:sync_task_id/);
});
