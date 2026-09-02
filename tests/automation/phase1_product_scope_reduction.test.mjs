import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import test from 'node:test';
import { readRouteContractSource } from '../../scripts/lib/route_contract_source.mjs';
import { readFrontendContractSource } from './helpers/frontend_source.mjs';

const html = readFrontendContractSource();
const style = readFileSync('public/style.css', 'utf8');
const systemStatic = readFileSync('public/system-static.js', 'utf8');
const homeStatic = readFileSync('public/home-static.js', 'utf8');
const routes = readRouteContractSource(process.cwd());
const missingModulesVerifier = readFileSync('scripts/verify_missing_modules.php', 'utf8');
const fullAutomation = readFileSync('tests/automation/suxi_full_automation_test.mjs', 'utf8');
const simulationTemplate = readFileSync('resources/frontend/templates/fragments/02-page-ai-simulation.html', 'utf8');
const appMainComponents = readFileSync('public/components/system/app-main-components.js', 'utf8');

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

test('remaining downstream decision and lifecycle navigation stay hidden while backend routes remain frozen', () => {
  for (const path of [
    'lifecycle-auxiliary',
    'investment-decision',
    'ai-strategy',
    'ai-feasibility',
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

test('existing opening pages are discoverable from the operations center', () => {
  assert.match(systemStatic, /name:\s*'开业准备总览',\s*path:\s*'opening-overview'/);
  assert.match(systemStatic, /name:\s*'开业检查清单',\s*path:\s*'opening-checklist'/);
  assert.match(html, /sourcePath: 'opening-overview',[\s\S]*name: '开业管理总览'/);
  assert.match(html, /sourcePath: 'opening-checklist',[\s\S]*name: '开业检查清单'/);
});

test('existing quant simulation page is discoverable from the revenue workbench', () => {
  assert.match(systemStatic, /name:\s*'智算·量化模拟',\s*path:\s*'ai-simulation'/);
  assert.match(html, /sourcePath: 'ai-simulation',[\s\S]*name: '酒店量化模拟'/);
  assert.match(simulationTemplate, /data-testid="simulation-hotel-selector"/);
  assert.match(appMainComponents, /示例假设 · 未验证/);
});

test('reactivated opening pages keep their lazy static bindings boot-safe', () => {
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
