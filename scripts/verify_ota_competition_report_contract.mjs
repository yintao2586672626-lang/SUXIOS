import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';

const root = path.resolve(import.meta.dirname, '..');
const read = (relativePath) => fs.readFileSync(path.join(root, relativePath), 'utf8');
const assertContains = (content, needle, label) => {
  if (!content.includes(needle)) {
    throw new Error(`${label} is missing: ${needle}`);
  }
};
const assertNotContains = (content, needle, label) => {
  if (content.includes(needle)) {
    throw new Error(`${label} must not remain: ${needle}`);
  }
};

const controller = read('app/controller/AiDailyReport.php');
const reportService = read('app/service/AiDailyReportService.php');
const persistenceService = read('app/service/AiDailyCompetitionBundlePersistenceService.php');
const bundleService = read('app/service/OtaCompetitionAnalysisBundleService.php');
const wecomController = read('app/controller/admin/CompetitorWechatRobotController.php');
const wecomRenderer = read('app/service/WechatCompetitionReportRendererService.php');
const wecomDelivery = read('app/service/WechatCompetitionReportDeliveryService.php');
const wecomVisualCard = read('app/service/WechatCompetitionVisualCardService.php');
const wecomVisualRenderer = read('scripts/render_wechat_competition_visual_card.mjs');
const cloudAutomation = read('app/service/CloudAutomationService.php');
const frontend = [
  read('public/app-main.js'),
  read('public/components/system/ai-daily-report-delivery.js'),
].join('\n');
const aiDailyStatic = read('public/ai-daily-report-static.js');
const template = read('resources/frontend/templates/fragments/16-page-ai-daily-report.html');
const ctripTemplate = read('resources/frontend/templates/fragments/24-page-ctrip-ebooking.html');
const workflow = read('.github/workflows/php.yml');
const packageJson = JSON.parse(read('package.json'));
const scripts = packageJson.scripts ?? {};

[
  ['assertGenerationAllowed($edition, $isAdmin)', 'server-side edition authorization'],
  ['editionRequiresAdmin($edition)', 'admin edition background bypass'],
  ['resolveHotelScope', 'hotel-scope authorization'],
].forEach(([needle, label]) => assertContains(controller, needle, label));

[
  ["$snapshot['competition_circle_bundle']", 'bundle persistence in snapshot'],
  ["$snapshot['competition_circle_bundle_persistence']", 'bundle persistence identity contract'],
  ["'competition_circle_bundle' => $competitionBundle", 'bundle rule-report contract'],
  ["$row['competition_circle_bundle']", 'bundle readback exposure'],
  ["$row['competition_bundle_readback']", 'exact bundle readback receipt exposure'],
  ['AiDailyCompetitionBundlePersistenceService::persistReport(', 'transactional report upsert bridge'],
  ['assertGenerationAllowed($edition, $actorIsAdmin)', 'service-layer edition authorization'],
].forEach(([needle, label]) => assertContains(reportService, needle, label));

const transactionIndex = reportService.indexOf('AiDailyCompetitionBundlePersistenceService::persistReport(');
const cacheWriteIndex = reportService.indexOf('if (!$cacheHit && $inputTrust[\'verified\']', transactionIndex);
if (transactionIndex < 0 || cacheWriteIndex <= transactionIndex) {
  throw new Error('input cache write must remain after transactional exact bundle readback');
}

[
  ["public const CONTRACT_VERSION = 'ai_daily_report.competition_bundle_persistence.v1'", 'bundle persistence contract version'],
  ['public static function buildContract(array $bundle)', 'pre-persistence bundle validation'],
  ['public static function receipt(array $snapshot)', 'exact readback receipt'],
  ["'status' => 'exact_readback_verified'", 'exact readback success state'],
  ['Db::transaction(static function () use (', 'transactional report upsert and readback'],
  ["->where('hotel_id', $hotelId)", 'hotel-scoped exact readback'],
  ["->where('report_date', $reportDate)", 'date-scoped exact readback'],
].forEach(([needle, label]) => assertContains(persistenceService, needle, label));

[
  ["public const DEFAULT_EDITION = 'lite'", 'lite default'],
  ["'single_calculation' => true", 'single calculation contract'],
  ["'flagship_generation_requires_admin' => true", 'flagship permission contract'],
  ["'auto_write_ota' => false", 'manual OTA boundary'],
  ["$datasetKind === 'live'", 'live-only decision gate'],
  ["'ctrip_source_trace_unverified'", 'Ctrip source-trace gate'],
  ["'meituan_source_trace_unverified'", 'Meituan source-trace gate'],
  ["'competitor_count' => $competitorCount", 'missing competitor count remains null'],
  ["'schema_version' => 'suxios.ota_competition_report.v1'", 'interactive report document contract'],
  ["'schema_version' => 'suxios.xiaohongshu.content_draft.v1'", 'Xiaohongshu draft contract'],
  ["'bundle_id' => $bundleId", 'interactive report bundle identity'],
  ["$bundle['content_digest'] = $contentDigest", 'complete bundle content digest'],
  ["$bundle['report_document']['render_contract']['content_digest']", 'report digest mirror'],
  ['public static function contentDigest(array $bundle)', 'canonical complete-content verifier'],
  ["'commercial_release_ready' => false", 'interactive report commercial boundary'],
  ["'auto_publish' => false", 'Xiaohongshu manual publication boundary'],
].forEach(([needle, label]) => assertContains(bundleService, needle, label));

assertContains(wecomDelivery, 'competition_bundle_exact_readback_required', 'WeCom exact-readback delivery gate');
assertContains(aiDailyStatic, 'input.report?.competition_bundle_readback', 'HTML export exact-readback receipt fallback');
assertContains(aiDailyStatic, 'competition_bundle_exact_readback_required', 'offline export exact-readback gate');

[
  ['new WechatCompetitionReportRendererService()', 'WeCom competition renderer entry'],
  ['new WechatCompetitionReportDeliveryService()', 'WeCom text and visual delivery entry'],
  ['getPermittedHotelIds()', 'WeCom hotel-scope authorization'],
  ['EDITION_FLAGSHIP && !$isAdmin', 'WeCom flagship admin gate'],
  ["'report_edition' => (string)$rendered['report_edition']", 'WeCom edition delivery context'],
  ["'source_fingerprint' => (string)$rendered['source_fingerprint']", 'WeCom source fingerprint'],
].forEach(([needle, label]) => assertContains(wecomController, needle, label));

[
  ["$qualityStatus !== 'available'", 'partial and blocked status-only gate'],
  ["'status_only' => $statusOnly", 'status-only render contract'],
  ['Lite and flagship', 'same-bundle renderer boundary'],
  ['auto_write_ota=false', 'WeCom manual OTA boundary'],
].forEach(([needle, label]) => assertContains(wecomRenderer, needle, label));

[
  ["'artifact_kind' => 'summary_text'", 'summary text delivery part'],
  ["'artifact_kind' => 'visual_card'", 'visual-card delivery part'],
  ['renderImagePayload($model)', 'visual-card rendering step'],
  ["'error_code' => (string)($delivery['error_code'] ?? '')", 'visual-card failure diagnostics'],
  ["'single_calculation' => true", 'two-part delivery shares one calculation'],
].forEach(([needle, label]) => assertContains(wecomDelivery, needle, label));

[
  ["'schema' => 'suxi.wecom.competition.visual-card.v1'", 'competition visual-card schema'],
  ["'status_only' => $statusOnly", 'visual-card action gate'],
  ["'actions' => $this->actions($bundle, $statusOnly)", 'visual-card saved actions only'],
  ['competition_visual_image_exceeds_wecom_limit', 'WeCom image size gate'],
  ['resolveNodeExecutable()', 'web-runtime Node executable resolution'],
].forEach(([needle, label]) => assertContains(wecomVisualCard, needle, label));

[
  ['渠道证据与核心判断', 'visual channel table'],
  ['竞品分组表', 'visual competitor table'],
  ['数据缺口与行动门槛', 'visual truthful gap gate'],
  ['MAX_IMAGE_BYTES', 'visual WeCom size limit'],
].forEach(([needle, label]) => assertContains(wecomVisualRenderer, needle, label));

[
  ["$statusOnly ? 'daily_report_status' : 'daily_report'", 'status-only durable delivery channel'],
  ["'report_edition' => $reportEdition", 'edition-specific idempotency identity'],
  ["'delivery_mode' => $deliveryMode", 'delivery-mode identity'],
  ["'artifact_kind' => $artifactKind", 'text and visual idempotency identity'],
].forEach(([needle, label]) => assertContains(cloudAutomation, needle, label));

[
  ['aiDailyCompetitionInputRows', 'Ctrip and Meituan competition-source rows'],
  ['aiDailyCompetitionInputsReady', 'dual competition-source generation gate'],
  ['aiDailyCompetitionInputStatusText', 'truthful competition-source readiness copy'],
  ['syncAiDailyCompetitionInputs', 'hotel and date changes automatically refresh competition sources'],
  ['generateCtripCompetitionReport', 'Ctrip competition toolbar report action'],
  ["testId: 'ctrip-competition-report-lite'", 'Ctrip toolbar lite report action'],
  ["testId: 'ctrip-competition-report-flagship'", 'Ctrip toolbar flagship report action'],
  ["testId: 'ctrip-business-download-button'", 'existing Ctrip data download action'],
  ['edition: requestedEdition', 'explicit quick-report edition request'],
  ['downloadCompetitionReport(context(), edition)', 'explicit edition forwarded to the deferred exporter'],
  ["JSON.stringify({ edition: requestedEdition })", 'WeCom edition request payload'],
  ['aiDailyReportWecomConfirmOpen.value = true', 'in-app WeCom confirmation entry'],
  ['confirmAiDailyReportWecomSend', 'confirmed WeCom delivery action'],
  ['delivery.delivery_parts || {}', 'text and visual delivery receipt binding'],
  ["aiDailyReportWecomEdition.value = 'lite'", 'non-admin WeCom edition reset'],
  ['aiDailyReportCompetitionBundle', 'competition bundle frontend binding'],
  ['aiDailyReportCompetitionReportDocument', 'saved interactive report binding'],
  ['downloadAiDailyCompetitionReportHtml', 'offline HTML report export'],
  ['copyAiDailyCompetitionXiaohongshuDraft', 'Xiaohongshu draft copy action'],
  ['includeCompetition && !downloadAiDailyCompetitionReportHtml()', 'report delivery is bound to identity-checked result package action'],
].forEach(([needle, label]) => assertContains(frontend, needle, label));

[
  ['const detailSections = flagship', 'lite and flagship HTML structures'],
  ['suxios-ota-competition-${normalizedEdition}', 'edition-specific report filename'],
  ['data-report-edition=', 'edition identity embedded in offline HTML'],
  ['report.render_contract?.bundle_id', 'report export bundle identity check'],
  ['data-bundle-id=', 'report export embeds bundle identity'],
  ["facts.competitor_count ?? '—'", 'missing competitor count display boundary'],
  ['auto_write_ota=false', 'manual execution boundary copy'],
].forEach(([needle, label]) => assertContains(aiDailyStatic, needle, label));

assertNotContains(frontend, 'edition: aiDailyReportForm.value.edition', 'generation edition request payload');

[
  ['v-for="row in aiDailyCompetitionInputRows"', 'Ctrip and Meituan competition-source status rows'],
  ['data-testid="ai-daily-competition-input-status"', 'competition-source readiness status'],
  [':disabled="operationLoading.aiDailyReport || aiDailyReportGenerationTaskPolling || !aiDailyCompetitionInputsReady"', 'generation is gated by both competition sources'],
  ['>生成分析报告</span>', 'competition report generation action'],
  ['data-testid="ai-daily-report-wecom-edition"', 'WeCom edition selector'],
  ['data-testid="ai-daily-report-wecom-result"', 'WeCom delivery result'],
  ['data-testid="ai-daily-report-wecom-part-results"', 'WeCom part delivery result'],
  ['data-testid="ai-daily-report-wecom-confirm-modal"', 'in-app WeCom confirmation modal'],
  ['data-testid="ai-daily-report-wecom-confirm-submit"', 'in-app WeCom confirmation submit'],
  ["user?.is_super_admin && aiDailyReportWecomEdition === 'flagship'", 'non-admin send button label gate'],
  ['<strong>竞对变化（诊断参考）</strong>', 'always-visible diagnostic heading'],
  ['<pre v-if="aiDailyReportCompetitionSummaryText"', 'competition-circle report entry'],
  ['>产出报告</button>', 'user-facing report output action'],
  ['v-else-if="!aiDailyReportCompetitorChanges.length"', 'truthful competition empty state'],
].forEach(([needle, label]) => assertContains(template, needle, label));

assertNotContains(template, 'v-model="aiDailyReportForm.edition"', 'generation edition selector');
assertNotContains(template, 'value="both"', 'misleading dual-generation option');

[
  ['v-for="action in ctripBusinessToolbarActions"', 'Ctrip toolbar action loop'],
  ['handleCtripBusinessToolbarAction(action)', 'edition-specific toolbar action binding'],
].forEach(([needle, label]) => assertContains(ctripTemplate, needle, label));

const liteButtonIndex = frontend.indexOf("testId: 'ctrip-competition-report-lite'");
const flagshipButtonIndex = frontend.indexOf("testId: 'ctrip-competition-report-flagship'");
const downloadButtonIndex = frontend.indexOf("testId: 'ctrip-business-download-button'");
if (!(liteButtonIndex < flagshipButtonIndex && flagshipButtonIndex < downloadButtonIndex)) {
  throw new Error('Ctrip report actions must appear immediately left of data download');
}

assertContains(
  String(scripts['verify:ota-competition-python'] ?? ''),
  'scripts/run_python_unittest.mjs tests/python/ota_competition_bundle_test.py',
  'tracked Python regression command',
);
assertContains(
  String(scripts['verify:ota-competition-bundle'] ?? ''),
  'node scripts/run_php.mjs scripts/verify_ota_competition_bundle.php',
  'cross-platform PHP regression command',
);
assertContains(
  String(scripts['verify:ota-competition-report'] ?? ''),
  'verify:ota-competition-python',
  'combined competition report regression command',
);
assertContains(workflow, 'uses: actions/setup-python@v6', 'GitHub Python runtime');
assertContains(workflow, 'npm run verify:ota-competition-report', 'GitHub competition report regression');

process.stdout.write(JSON.stringify({
  status: 'passed',
  checks: {
    server_permission: true,
    hotel_scope: true,
    one_bundle_save_readback: true,
    lite_default: true,
    flagship_admin_only: true,
    competition_sources_auto_read: true,
    dual_source_generation_gate: true,
    generation_edition_hidden: true,
    ctrip_toolbar_report_entry: true,
    lite_and_flagship_outputs: true,
    synthetic_guard: true,
    no_ota_auto_write: true,
    wecom_lite_for_hotel_user: true,
    wecom_flagship_admin_only: true,
    wecom_status_only_guard: true,
    wecom_same_bundle_rendering: true,
    wecom_summary_text_and_visual_card: true,
    wecom_page_confirm_and_receipt: true,
    cross_platform_regression: true,
    github_ci_coverage: true,
  },
}, null, 2) + '\n');
