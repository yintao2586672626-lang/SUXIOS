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

const controller = read('app/controller/AiDailyReport.php');
const reportService = read('app/service/AiDailyReportService.php');
const bundleService = read('app/service/OtaCompetitionAnalysisBundleService.php');
const frontend = read('public/app-main.js');
const template = read('resources/frontend/templates/fragments/16-page-ai-daily-report.html');
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
  ["'competition_circle_bundle' => $competitionBundle", 'bundle rule-report contract'],
  ["$row['competition_circle_bundle']", 'bundle readback exposure'],
  ['assertGenerationAllowed($edition, $actorIsAdmin)', 'service-layer edition authorization'],
].forEach(([needle, label]) => assertContains(reportService, needle, label));

[
  ["public const DEFAULT_EDITION = 'lite'", 'lite default'],
  ["'single_calculation' => true", 'single calculation contract'],
  ["'flagship_generation_requires_admin' => true", 'flagship permission contract'],
  ["'auto_write_ota' => false", 'manual OTA boundary'],
  ["$datasetKind === 'live'", 'live-only decision gate'],
  ["'ctrip_source_trace_unverified'", 'Ctrip source-trace gate'],
  ["'meituan_source_trace_unverified'", 'Meituan source-trace gate'],
  ["'competitor_count' => $competitorCount", 'missing competitor count remains null'],
].forEach(([needle, label]) => assertContains(bundleService, needle, label));

[
  ["edition: 'lite'", 'frontend lite default'],
  ["edition: aiDailyReportForm.value.edition || 'lite'", 'edition request payload'],
  ['aiDailyReportCompetitionBundle', 'competition bundle frontend binding'],
  ["facts.competitor_count ?? '—'", 'missing competitor count display boundary'],
  ['auto_write_ota=false', 'manual execution boundary copy'],
].forEach(([needle, label]) => assertContains(frontend, needle, label));

[
  ['value="lite"', 'lite option'],
  ['<optgroup v-if="user?.is_super_admin">', 'admin-only flagship options'],
  ['value="flagship"', 'flagship option'],
  ['value="both"', 'dual option'],
  ['<pre v-if="aiDailyReportCompetitionSummaryText"', 'competition-circle report entry'],
].forEach(([needle, label]) => assertContains(template, needle, label));

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
assertContains(workflow, 'uses: actions/setup-python@v5', 'GitHub Python runtime');
assertContains(workflow, 'npm run verify:ota-competition-report', 'GitHub competition report regression');

process.stdout.write(JSON.stringify({
  status: 'passed',
  checks: {
    server_permission: true,
    hotel_scope: true,
    one_bundle_save_readback: true,
    lite_default: true,
    flagship_admin_only: true,
    synthetic_guard: true,
    no_ota_auto_write: true,
    cross_platform_regression: true,
    github_ci_coverage: true,
  },
}, null, 2) + '\n');
