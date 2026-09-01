import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';

const root = path.resolve(import.meta.dirname, '../..');
const fragmentPath = path.join(root, 'resources/frontend/templates/fragments/20-page-knowledge-center.html');
const fragment = fs.readFileSync(fragmentPath, 'utf8');
const templateSource = fs.readFileSync(path.join(root, 'scripts/lib/frontend_template_source.mjs'), 'utf8');
const templateBuild = fs.readFileSync(path.join(root, 'scripts/lib/frontend_template_build.mjs'), 'utf8');
const componentLoader = fs.readFileSync(path.join(root, 'public/components/system/business-closure-loader.js'), 'utf8');
const authenticatedStyle = fs.readFileSync(path.join(root, 'public/style.css'), 'utf8');

test('knowledge center exposes a searchable task and settings finder', () => {
  assert.match(fragment, /data-testid="knowledge-feature-finder"/);
  assert.match(fragment, /data-testid="knowledge-feature-query"/);
  assert.match(fragment, /v-model="knowledgeCenterFilter\.keyword"/);
  assert.match(fragment, />OTA数据</);
  assert.match(fragment, />收益分析</);
  assert.match(fragment, />运营管理</);
  assert.match(fragment, />设置与绑定</);
});

test('finder cards preserve evidence and authorization boundaries', () => {
  assert.match(fragment, /先做3个能验收的真实任务/);
  assert.match(fragment, /仅打开页面不算完成/);
  assert.match(fragment, /数字存在不等于事实可用/);
  assert.match(fragment, /不与OTA渠道口径混加/);
  assert.match(fragment, /建议不等于已执行、已调价或已产生收益/);
  assert.match(fragment, /外部发送需确认/);
  assert.match(fragment, /只有目标机器人身份正确且真实回执成功，才算已送达/);
  assert.match(fragment, /visibleMenuItems[\s\S]*child\.tab === 'data-health'/);
  assert.match(fragment, /data-testid="knowledge-feature-data-health-unavailable"/);
  assert.match(fragment, /当前账号暂无数据中心入口/);
});

test('finder actions enter real existing SUXIOS pages', () => {
  const expectedTargets = [
    "currentPage = 'operating-opportunities'",
    "currentPage = 'online-data'",
    "currentPage = 'ai-daily-report'",
    'openOnlinePlatformAutoTab({ force: true })',
    "currentPage = 'pms-operating-data'",
    "currentPage = 'revenue-research-center'",
    "currentPage = 'ops-track'",
    "currentPage = 'automation-monitor'",
    "currentPage = 'hotels'",
    "currentPage = 'wechat-notification'",
  ];

  for (const target of expectedTargets) {
    assert.ok(fragment.includes(target), `missing real task target: ${target}`);
  }

  const taskIds = [
    'today-priority',
    'data-health',
    'daily-report',
    'auto-collect',
    'pms',
    'revenue',
    'operations',
    'automation',
    'settings',
    'notification',
  ];
  for (const taskId of taskIds) {
    assert.match(fragment, new RegExp(`<article[^>]+data-testid="knowledge-feature-${taskId}"`));
  }
});

test('every task card opens an accessible native scenario dialog', () => {
  const controls = [...fragment.matchAll(/aria-haspopup="dialog" aria-controls="([^"]+)"/g)].map(match => match[1]);
  const dialogIds = [...fragment.matchAll(/<dialog id="([^"]+)" data-testid="knowledge-feature-[^"]+-dialog"/g)].map(match => match[1]);

  assert.equal(controls.length, 10);
  assert.equal(dialogIds.length, 10);
  assert.deepEqual(new Set(controls), new Set(dialogIds));
  assert.equal((fragment.match(/\.showModal\(\)/g) || []).length, 10);
  assert.equal((fragment.match(/@click\.self="\$event\.currentTarget\.close\(\)"/g) || []).length, 10);
  assert.equal((fragment.match(/三步任务路径/g) || []).length, 10);
  assert.equal((fragment.match(/data-testid="knowledge-feature-[^"]+-dialog-close"/g) || []).length, 10);
});

test('task bluebook V1 highlights exactly three executable evidence-gated journeys', () => {
  assert.equal((fragment.match(/data-bluebook-core="v1"/g) || []).length, 3);
  assert.match(fragment, /宿析任务蓝皮书 · V1/);
  assert.match(fragment, /今天先做什么/);
  assert.match(fragment, /查清数据为什么没进来/);
  assert.match(fragment, /生成可信经营日报/);
  assert.match(fragment, /保存ID并可精确回读/);
  assert.match(fragment, /本版不把企业微信外发算作该快照的完成步骤/);
});

test('task finder motion stays short, scoped, and respects reduced motion', () => {
  assert.match(authenticatedStyle, /\.knowledge-feature-card\s*\{[^}]*180ms/s);
  assert.match(authenticatedStyle, /\.knowledge-feature-card:hover\s*\{[^}]*translateY\(-4px\)/s);
  assert.match(authenticatedStyle, /\.knowledge-feature-action:hover \.fa-arrow-right\s*\{[^}]*translateX\(3px\)/s);
  assert.match(authenticatedStyle, /\.knowledge-feature-dialog::backdrop/);
  assert.match(authenticatedStyle, /\.knowledge-feature-dialog\[open\] \.knowledge-feature-dialog-panel/);
  assert.match(authenticatedStyle, /@media \(prefers-reduced-motion: reduce\)[\s\S]*\.knowledge-feature-dialog\[open\]/);
});

test('task finder visual hierarchy uses the SUXIOS luxury palette without losing responsive density', () => {
  assert.match(fragment, /class="knowledge-feature-hero /);
  assert.match(fragment, /class="knowledge-feature-journey"/);
  assert.match(fragment, /class="knowledge-feature-filter-heading">按任务分类</);
  assert.match(fragment, /01<\/strong> 选择任务/);
  assert.match(fragment, /03<\/strong> 按证据验收/);
  assert.match(authenticatedStyle, /--knowledge-ink: #020706/);
  assert.match(authenticatedStyle, /--knowledge-gold: #dcc591/);
  assert.match(authenticatedStyle, /\.knowledge-feature-hero::before/);
  assert.match(authenticatedStyle, /\.knowledge-feature-finder\s*\{[^}]*grid-template-columns: 196px minmax\(0, 1fr\)/s);
  assert.match(authenticatedStyle, /\.knowledge-feature-hero\s*\{[^}]*grid-column: 1 \/ -1/s);
  assert.match(authenticatedStyle, /\.knowledge-feature-search-panel\s*\{[^}]*backdrop-filter: blur\(8px\)/s);
  assert.match(authenticatedStyle, /\.knowledge-feature-filters\s*\{[^}]*grid-column: 1;[^}]*flex-direction: column;[^}]*border-right:/s);
  assert.match(authenticatedStyle, /\.knowledge-feature-grid\s*\{[^}]*grid-column: 2;[^}]*grid-template-columns: repeat\(3, minmax\(0, 1fr\)\)[^}]*margin: 0;[^}]*border-radius: 0;/s);
  assert.match(authenticatedStyle, /@media \(max-width: 1023px\)[\s\S]*\.knowledge-feature-grid\s*\{[^}]*repeat\(2, minmax\(0, 1fr\)\)/s);
  assert.match(authenticatedStyle, /@media \(max-width: 640px\)[\s\S]*\.knowledge-feature-grid\s*\{[^}]*grid-template-columns: minmax\(0, 1fr\)[^}]*margin: 0;[^}]*border-radius: 0;/s);
  assert.match(authenticatedStyle, /@media \(max-width: 640px\)[\s\S]*\.knowledge-feature-action\s*\{[^}]*width: 100% !important;[^}]*height: auto !important;/s);
  assert.doesNotMatch(authenticatedStyle, /\.knowledge-feature-grid\s*\{[^}]*margin-top:\s*-\d+px/s);
  assert.match(authenticatedStyle, /\.knowledge-feature-dialog \[data-testid\$="-dialog-close"\]/);
});

test('finder is extracted from the root render and loaded through the existing async component contract', () => {
  assert.match(templateSource, /id: 'knowledge-feature-finder'/);
  assert.match(templateSource, /componentKey: 'KnowledgeFeatureFinderBody'/);
  assert.match(templateSource, /<knowledge-feature-finder-view :ctx="\$root"><\/knowledge-feature-finder-view>/);
  assert.match(templateBuild, /'KnowledgeFeatureFinderBody'/);
  assert.match(componentLoader, /\['KnowledgeFeatureFinderView', 'KnowledgeFeatureFinderBody'\]/);
});
