import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import test from 'node:test';
import { readFrontendContractSource } from './helpers/frontend_source.mjs';

const html = readFrontendContractSource();
const style = readFileSync('public/style.css', 'utf8');
const systemStatic = readFileSync('public/system-static.js', 'utf8');
const homeStatic = readFileSync('public/home-static.js', 'utf8');
const routes = readFileSync('route/app.php', 'utf8');
const missingModulesVerifier = readFileSync('scripts/verify_missing_modules.php', 'utf8');
const fullAutomation = readFileSync('tests/automation/suxi_full_automation_test.mjs', 'utf8');

test('phase 1 removes the image optimizer while preserving the AI toolbox links', () => {
  assert.equal(existsSync('public/hotel-image-optimizer-static.js'), false);
  assert.doesNotMatch(html, /hotel-image-optimizer|hotelImageOptimizer|SUXI_HOTEL_IMAGE_OPTIMIZER_STATIC/);
  assert.doesNotMatch(style, /hotel-image-optimizer/);
  assert.match(systemStatic, /const hotelAiToolboxLinks = \[/);
  assert.match(systemStatic, /hotelAiToolboxLinks,/);
});

test('phase 1 removes legacy simulated AI endpoints and demo construction data', () => {
  assert.equal(existsSync('app/controller/Ai.php'), false);
  assert.doesNotMatch(routes, /Route::group\('api\/ai',/);
  assert.doesNotMatch(routes, /Ai\/(?:strategy|simulation|feasibility)/);
  assert.doesNotMatch(html, /demo-construction-001|AI 筹建管理状态：本地结构化数据/);
  assert.doesNotMatch(missingModulesVerifier, /read_file\('app\/controller\/Ai\.php'\)/);
  assert.doesNotMatch(fullAutomation, /\/api\/ai\/(?:strategy|simulation|feasibility)/);
});

test('phase 1 hides lifecycle navigation while retaining frozen backend routes and OTA core', () => {
  for (const path of [
    'lifecycle-auxiliary',
    'investment-decision',
    'ai-strategy',
    'ai-simulation',
    'ai-feasibility',
    'opening-overview',
    'opening-checklist',
    'market-evaluation',
    'benchmark-model',
    'collaboration-efficiency',
    'asset-pricing',
    'timing-strategy',
    'decision-board',
  ]) {
    assert.doesNotMatch(systemStatic, new RegExp(`path:\\s*['\"]${path}['\"]`));
  }

  assert.doesNotMatch(homeStatic, /entry:\s*\{\s*page:\s*['"]investment-decision['"]\s*\}/);
  const homeLoopBlock = html.match(/const homeClosedLoopStages = computed\(\(\) => \{[\s\S]*?^\s*\}\);/m)?.[0] || '';
  assert.doesNotMatch(homeLoopBlock, /transferSourceSnapshot|transferSourceDate/);
  for (const group of ['lifecycle', 'investment-decision', 'strategy', 'simulation', 'opening', 'expansion', 'transfer']) {
    assert.match(routes, new RegExp(`Route::group\\('api/${group}'`));
  }
  assert.match(routes, /Route::group\('api\/online-data',/);
  assert.match(routes, /Route::group\('api\/revenue-ai',/);
});

test('phase 1 hidden opening pages keep their lazy static bindings boot-safe', () => {
  for (const marker of [
    'const openingCategories = ref([]);',
    'let buildOpeningOverviewCards = () => [];',
    'let buildOpeningProjectFormDefaults = () => ({',
    "buildOpeningProjectFormDefaults = requireOperationStatic(staticConfig, 'buildOpeningProjectFormDefaults');",
    "buildOpeningProjectFormFromProject = requireOperationStatic(staticConfig, 'buildOpeningProjectFormFromProject');",
    "buildOpeningAiOutputResult = requireOperationStatic(staticConfig, 'buildOpeningAiOutputResult');",
  ]) {
    assert.ok(html.includes(marker), `missing boot-safe opening binding: ${marker}`);
  }
});
