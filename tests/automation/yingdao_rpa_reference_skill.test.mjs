import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

import {
  loadCatalog,
  loadHelpCatalog,
  searchDocuments,
} from '../../.agents/skills/suxi-yingdao-rpa-reference/scripts/search-catalog.mjs';

const skillRoot = '.agents/skills/suxi-yingdao-rpa-reference';
const readJson = (filename) => JSON.parse(readFileSync(`${skillRoot}/${filename}`, 'utf8'));
const skill = readFileSync(`${skillRoot}/SKILL.md`, 'utf8');
const openai = readFileSync(`${skillRoot}/agents/openai.yaml`, 'utf8');
const helpCatalog = readJson('references/help-center-index.json');
const instructionCatalog = readJson('references/command-index.json');
const manifest = readJson('references/source-manifest.json');
const triggerEvals = readJson('evals/trigger-evals.json');
const outputEvals = readJson('evals/evals.json');
const fetchScript = readFileSync(
  `${skillRoot}/scripts/fetch-doc-outline.mjs`,
  'utf8',
);

test('Yingdao help-center skill is discoverable and keeps a narrow trigger', () => {
  assert.match(skill, /^---\r?\nname: suxi-yingdao-rpa-reference\r?\ndescription:/);
  assert.match(skill, /影刀RPA/);
  assert.match(skill, /开放API/);
  assert.match(skill, /常见问题/);
  assert.match(skill, /不要用于普通网页抓取/);
  assert.doesNotMatch(skill, /\bTODO\b/);
  assert.match(openai, /display_name: "影刀 RPA 帮助中心参考"/);
  assert.match(openai, /Use \$suxi-yingdao-rpa-reference/);
  assert.equal(triggerEvals.evals.filter((item) => item.should_trigger).length, 11);
  assert.equal(triggerEvals.evals.filter((item) => !item.should_trigger).length, 11);
  assert.ok(outputEvals.evals.some((item) => item.id === 'help-center-routing'));
});

test('Yingdao help-center catalog covers every public menu node without body content', () => {
  assert.equal(helpCatalog.schemaVersion, 'suxi_yingdao_rpa_help_center_catalog.v1');
  assert.equal(helpCatalog.counts.topSections, 10);
  assert.equal(helpCatalog.counts.documents, 3_682);
  assert.equal(helpCatalog.counts.folders, 487);
  assert.equal(helpCatalog.counts.totalNodes, 4_169);
  assert.equal(helpCatalog.counts.emptyFolders, 3);
  assert.equal(helpCatalog.counts.maxDepth, 5);
  assert.equal(helpCatalog.documents.length, 3_682);
  assert.equal(helpCatalog.folders.length, 487);
  assert.equal(helpCatalog.counts.totalNodes, (
    helpCatalog.counts.documents + helpCatalog.counts.folders
  ));
  assert.equal(helpCatalog.source.fullTextStored, false);
  assert.equal(manifest.storage.helpCenterMenuMetadata, true);
  assert.equal(manifest.storage.fullText, false);
  assert.equal(manifest.storage.images, false);
  assert.equal(manifest.license.status, 'restricted_or_unknown');

  const expectedSections = [
    ['712111712911486976', '影刀概述', 0, 1],
    ['711649427620073472', '快速入门', 1, 11],
    ['711626382286766080', '功能文档', 12, 46],
    ['711200729240932352', '指令文档', 314, 2_527],
    ['710994524995190784', '接口文档', 9, 31],
    ['710526109107601408', '常见问题', 34, 331],
    ['710460304655855616', '开放API', 13, 55],
    ['710453193321988096', '管理文档', 88, 629],
    ['710920603531460608', '专题文档', 12, 36],
    ['710399680488800256', '解决方案', 4, 15],
  ];
  assert.deepEqual(
    helpCatalog.sections.map((section) => [
      section.id,
      section.title,
      section.folderCount,
      section.documentCount,
    ]),
    expectedSections,
  );

  const allNodes = [...helpCatalog.folders, ...helpCatalog.documents];
  const nodeIds = new Set(allNodes.map((item) => item.id));
  assert.equal(nodeIds.size, 4_169);
  for (const node of allNodes) {
    assert.match(node.id, /^\d{12,}$/);
    assert.ok(node.title);
    assert.ok(Array.isArray(node.path) && node.path.length >= 1);
    assert.equal(node.path[0], node.topSection);
    if (node.path.length > 1) {
      assert.ok(nodeIds.has(node.parentId), `missing parent: ${node.id}`);
    }
  }
  for (const document of helpCatalog.documents) {
    assert.equal(document.contentStored, false);
    assert.match(
      document.sourceUrl,
      new RegExp(`^https://www\\.yingdao\\.com/yddoc/rpa/zh-CN/${document.id}$`),
    );
    for (const forbiddenField of ['content', 'html', 'body', 'images', 'code']) {
      assert.equal(forbiddenField in document, false);
    }
  }
});

test('legacy instruction catalog remains complete and is a strict help-center subset', () => {
  assert.equal(instructionCatalog.source.rootMenuId, '711200729240932352');
  assert.equal(instructionCatalog.counts.topCategories, 20);
  assert.equal(instructionCatalog.counts.documents, 2_527);
  assert.equal(instructionCatalog.counts.standardInstructionDocuments, 406);
  assert.equal(instructionCatalog.counts.customInstructionDocuments, 2_121);
  assert.equal(instructionCatalog.documents.length, 2_527);
  assert.equal(new Set(instructionCatalog.documents.map((item) => item.id)).size, 2_527);

  const helpById = new Map(helpCatalog.documents.map((item) => [item.id, item]));
  for (const document of instructionCatalog.documents) {
    const helpDocument = helpById.get(document.id);
    assert.ok(helpDocument);
    assert.equal(helpDocument.topSection, '指令文档');
    assert.deepEqual(helpDocument.path.slice(1), document.path);
  }
  assert.equal(
    helpCatalog.documents.filter((item) => item.topSection === '指令文档').length,
    2_527,
  );
});

test('catalog loaders preserve the legacy instruction default and expose full help scope', async () => {
  const legacyDefault = await loadCatalog();
  const fullHelp = await loadHelpCatalog();
  assert.equal(legacyDefault.schemaVersion, 'suxi_yingdao_rpa_catalog.v1');
  assert.equal(legacyDefault.counts.documents, 2_527);
  assert.equal(fullHelp.schemaVersion, 'suxi_yingdao_rpa_help_center_catalog.v1');
  assert.equal(fullHelp.counts.documents, 3_682);
});

test('catalog search routes instruction and non-instruction help requests', () => {
  const pagination = searchDocuments(instructionCatalog, '网页翻页抓取', 20);
  const dropdown = searchDocuments(instructionCatalog, '级联下拉框', 20);
  const http = searchDocuments(instructionCatalog, 'HTTP请求', 20);
  const excel = searchDocuments(instructionCatalog, 'Excel筛选', 20);
  assert.ok(pagination.some((item) => /滚动|循环|点击元素|等待网页/.test(item.title)));
  assert.ok(dropdown.some((item) => /下拉框/.test(item.title)));
  assert.ok(http.some((item) => /HTTP 请求/.test(item.title)));
  assert.ok(excel.some((item) => /筛选|Excel/.test(item.title)));

  const representativeQueries = [
    ['712111712911486976', '影刀是什么'],
    ['711659195984195584', '搭建一个网页自动化流程'],
    ['711641172621627392', '元素捕获'],
    ['711869706933055488', 'xbot.web'],
    ['712487845456834560', '未找到元素'],
    ['971279704374095872', '开放接口使用指南'],
    ['710522802181713920', '控制台使用说明'],
    ['710945906455162880', '如何安装并使用Python第三方库'],
    ['710435141686378496', '网页数据获取时需要翻页或下拉'],
  ];
  for (const [id, query] of representativeQueries) {
    assert.ok(
      searchDocuments(helpCatalog, query, 20).some((item) => item.id === id),
      `help search missed ${id}: ${query}`,
    );
  }
  const openApi = searchDocuments(helpCatalog, '影刀开放API', 20);
  assert.equal(openApi[0]?.id, '971279704374095872');
  assert.ok(!openApi.slice(0, 5).some((item) => /废弃/.test(item.title)));
});

test('single-page fetch helper spans both catalogs but cannot mirror bodies', () => {
  assert.match(fetchScript, /Usage: fetch-doc-outline\.mjs <document-id>/);
  assert.match(fetchScript, /command-index\.json/);
  assert.match(fetchScript, /help-center-index\.json/);
  assert.match(fetchScript, /Math\.min\(12_000/);
  assert.match(fetchScript, /contentStored: false/);
  assert.doesNotMatch(fetchScript, /writeFile|writeFileSync|createWriteStream/);
  assert.doesNotMatch(fetchScript, /--all|documents\.map\(.*fetch/s);
});
