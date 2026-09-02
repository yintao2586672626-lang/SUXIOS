import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import { readAppMainContractSource } from './helpers/frontend_source.mjs';
import { readRouteContractSource } from '../../scripts/lib/route_contract_source.mjs';

const appMain = readAppMainContractSource();
const shell = fs.readFileSync('resources/frontend/templates/fragments/23-page-home-shell-open.html', 'utf8');
const summary = fs.readFileSync('resources/frontend/templates/fragments/23a-page-compass-summary.html', 'utf8');
const detail = fs.readFileSync('resources/frontend/templates/fragments/23c-page-compass-detail.html', 'utf8');
const style = fs.readFileSync('public/compass-authority-polish.css', 'utf8');
const routes = readRouteContractSource(process.cwd());

test('Compass is the only canonical landing surface while the old workbench remains an alias', () => {
  assert.match(appMain, /const currentPage = ref\(initialPageOverride \|\| 'compass'\)/);
  assert.match(appMain, /const landingPage = initialPageOverride \|\| 'compass'/);
  assert.match(
    appMain,
    /const normalizeCanonicalPage = \(page\) => String\(page \|\| ''\)\.trim\(\) === 'ai-workbench'[\s\S]*?\? 'compass'/,
  );
  assert.match(shell, /v-if="currentPage === 'compass'"/);
  assert.doesNotMatch(shell, /currentPage === 'ai-workbench'/);
  assert.equal((appMain.match(/sourcePath: 'compass'/g) || []).length, 1);
  assert.doesNotMatch(appMain, /sourcePath: 'ai-workbench'/);
  assert.match(appMain, /testid: 'nav-operating-loop-kernel'/);
});

test('Compass projects the kernel answers and reconciles only against an explicit business date', () => {
  assert.match(summary, /<operating-loop-authority/);
  assert.match(appMain, /'data-testid': 'operating-loop-authority'/);
  for (const answer of ['什么是真的', '最重要的问题', '下一步谁做什么', '昨天动作有没有结果']) {
    assert.match(appMain, new RegExp(answer));
  }
  assert.match(appMain, /loop\.readback_verified/);
  assert.match(appMain, /scope\.metric_version/);
  assert.match(appMain, /Array\.isArray\(loop\.stages\)/);
  assert.match(appMain, /'data-testid': 'operating-loop-empty-state'/);
  assert.match(appMain, /建立并检查闭环/);
  assert.match(appMain, /查看八阶段明细/);
  assert.match(appMain, /openHomeQuickEntry\(\{ page: 'online-data', tab: 'data-health' \}\)/);
  assert.match(style, /\.operating-loop-authority-shell[\s\S]*?linear-gradient\(135deg, #06110d/);
  assert.match(style, /\.home-facts-shell[\s\S]*?background: transparent !important/);
  assert.match(style, /\.compass-hero-core[\s\S]*?#fffdf9/);
  assert.match(style, /\.compass-temporal-fold[\s\S]*?background: #f6f8f5/);
  assert.match(style, /\.home-facts-loading-state[\s\S]*?min-height: 88px/);
  assert.match(appMain, /params\.append\('business_date', operationYesterday\)/);
  assert.match(appMain, /request\('\/operating-loop\/reconcile'/);
  assert.match(appMain, /business_date: operationYesterday/);
  assert.match(routes, /Route::get\('\/current', 'OperatingLoop\/current'\)/);
  assert.match(routes, /Route::post\('\/reconcile', 'OperatingLoop\/reconcile'\)/);
});

test('Professional drilldowns cannot label their component result as the authoritative loop', () => {
  assert.match(summary, /以下内容不决定权威闭环状态/);
  assert.match(detail, /P1 收益分析诊断（不决定权威闭环）/);
  assert.doesNotMatch(detail, /P1 收益分析闭环/);
  assert.doesNotMatch(detail, /数据缺口闭环/);
  assert.match(appMain, /investmentParams\.set\('business_date', operationYesterday\)/);
  assert.match(appMain, /closureParams\.set\('business_date', operationYesterday\)/);
});
