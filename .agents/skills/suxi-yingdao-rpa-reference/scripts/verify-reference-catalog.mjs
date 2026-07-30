import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

import { searchDocuments } from './search-catalog.mjs';

const SKILL_DIR = dirname(dirname(fileURLToPath(import.meta.url)));
const readJson = async (filename) => JSON.parse(await readFile(
  join(SKILL_DIR, 'references', filename),
  'utf8',
));

const helpCatalog = await readJson('help-center-index.json');
const instructionCatalog = await readJson('command-index.json');
const manifest = await readJson('source-manifest.json');

assert.equal(
  helpCatalog.schemaVersion,
  'suxi_yingdao_rpa_help_center_catalog.v1',
);
assert.equal(helpCatalog.source.fullTextStored, false);
assert.equal(helpCatalog.counts.topSections, 10);
assert.equal(helpCatalog.counts.documents, 3_682);
assert.equal(helpCatalog.counts.folders, 487);
assert.equal(helpCatalog.counts.totalNodes, 4_169);
assert.equal(helpCatalog.counts.emptyFolders, 3);
assert.equal(helpCatalog.counts.maxDepth, 5);
assert.equal(helpCatalog.documents.length, helpCatalog.counts.documents);
assert.equal(helpCatalog.folders.length, helpCatalog.counts.folders);
assert.equal(helpCatalog.sections.length, helpCatalog.counts.topSections);

const expectedSections = new Map([
  ['影刀概述', 1],
  ['快速入门', 11],
  ['功能文档', 46],
  ['指令文档', 2_527],
  ['接口文档', 31],
  ['常见问题', 331],
  ['开放API', 55],
  ['管理文档', 629],
  ['专题文档', 36],
  ['解决方案', 15],
]);
for (const section of helpCatalog.sections) {
  assert.equal(
    section.documentCount,
    expectedSections.get(section.title),
    `unexpected section count: ${section.title}`,
  );
}
assert.deepEqual(
  new Set(helpCatalog.sections.map((item) => item.title)),
  new Set(expectedSections.keys()),
);

const allNodeIds = new Set();
const helpDocumentsById = new Map();
for (const folder of helpCatalog.folders) {
  assert.match(folder.id, /^\d{12,}$/);
  assert.ok(folder.title);
  assert.ok(Array.isArray(folder.path) && folder.path.length >= 1);
  assert.equal(folder.topSection, folder.path[0]);
  assert.ok(!allNodeIds.has(folder.id), `duplicate node id: ${folder.id}`);
  allNodeIds.add(folder.id);
}
for (const document of helpCatalog.documents) {
  assert.match(document.id, /^\d{12,}$/);
  assert.ok(document.title);
  assert.ok(Array.isArray(document.path) && document.path.length >= 1);
  assert.equal(document.topSection, document.path[0]);
  assert.equal(document.contentStored, false);
  assert.match(
    document.sourceUrl,
    new RegExp(`^https://www\\.yingdao\\.com/yddoc/rpa/zh-CN/${document.id}$`),
  );
  assert.ok(!allNodeIds.has(document.id), `duplicate node id: ${document.id}`);
  allNodeIds.add(document.id);
  helpDocumentsById.set(document.id, document);
  for (const forbiddenField of ['content', 'html', 'body', 'images', 'code']) {
    assert.equal(forbiddenField in document, false, `stored forbidden field: ${forbiddenField}`);
  }
}
assert.equal(allNodeIds.size, helpCatalog.counts.totalNodes);

assert.equal(instructionCatalog.schemaVersion, 'suxi_yingdao_rpa_catalog.v1');
assert.equal(instructionCatalog.source.rootMenuId, '711200729240932352');
assert.equal(instructionCatalog.source.fullTextStored, false);
assert.equal(instructionCatalog.counts.topCategories, 20);
assert.equal(instructionCatalog.counts.documents, 2_527);
assert.equal(instructionCatalog.counts.standardInstructionDocuments, 406);
assert.equal(instructionCatalog.counts.customInstructionDocuments, 2_121);
assert.equal(instructionCatalog.documents.length, instructionCatalog.counts.documents);

const instructionIds = new Set();
for (const document of instructionCatalog.documents) {
  assert.ok(['standard_instruction', 'custom_instruction'].includes(document.catalogBranch));
  if (document.catalogBranch === 'custom_instruction') {
    assert.equal(document.authorship, 'unknown');
  }
  assert.ok(!instructionIds.has(document.id), `duplicate instruction id: ${document.id}`);
  instructionIds.add(document.id);
  const helpDocument = helpDocumentsById.get(document.id);
  assert.ok(helpDocument, `instruction missing from help center: ${document.id}`);
  assert.equal(helpDocument.topSection, '指令文档');
  assert.equal(helpDocument.title, document.title);
  assert.deepEqual(helpDocument.path.slice(1), document.path);
  assert.equal(helpDocument.sourceUrl, document.sourceUrl);
}

assert.equal(
  manifest.sourceFingerprint,
  instructionCatalog.source.menuTreeSha256,
);
assert.equal(
  manifest.sourceFingerprints.helpCenter,
  helpCatalog.source.menuTreeSha256,
);
assert.equal(
  manifest.sourceFingerprints.instructionCatalog,
  instructionCatalog.source.menuTreeSha256,
);
assert.equal(manifest.storage.helpCenterMenuMetadata, true);
assert.equal(manifest.storage.fullText, false);
assert.equal(manifest.storage.images, false);
assert.equal(manifest.storage.html, false);
assert.equal(manifest.license.status, 'restricted_or_unknown');
assert.equal(manifest.robots.yddocAllowed, true);

const sectionQueries = new Map([
  ['影刀概述', '影刀概述'],
  ['快速入门', '影刀快速入门'],
  ['功能文档', '影刀功能文档'],
  ['指令文档', '影刀循环指令'],
  ['接口文档', '影刀接口文档'],
  ['常见问题', '影刀常见问题'],
  ['开放API', '影刀开放API'],
  ['管理文档', '影刀管理文档'],
  ['专题文档', '影刀专题文档'],
  ['解决方案', '影刀解决方案'],
]);
const sampledSections = {};
for (const [section, query] of sectionQueries) {
  const results = searchDocuments(helpCatalog, query, 20);
  assert.ok(
    results.some((item) => item.topSection === section),
    `search did not route to section: ${section}`,
  );
  sampledSections[section] = results
    .filter((item) => item.topSection === section)
    .slice(0, 2)
    .map((item) => item.title);
}

const pagination = searchDocuments(helpCatalog, '网页翻页抓取', 20);
assert.ok(pagination.some((item) => (
  item.title.includes('翻页')
  || item.title.includes('滚动')
  || item.title.includes('循环')
  || item.title.includes('点击元素')
  || item.title.includes('等待网页加载')
)));
const currentSolution = searchDocuments(
  helpCatalog,
  '网页数据获取时需要翻页或下拉',
  20,
);
assert.ok(currentSolution.some((item) => item.id === '710435141686378496'));
const openApi = searchDocuments(helpCatalog, '影刀开放API', 20);
assert.equal(openApi[0]?.id, '971279704374095872');
assert.ok(!openApi.slice(0, 5).some((item) => /废弃/.test(item.title)));

console.log(JSON.stringify({
  status: 'ok',
  helpCenterDocuments: helpCatalog.counts.documents,
  helpCenterFolders: helpCatalog.counts.folders,
  helpCenterNodes: helpCatalog.counts.totalNodes,
  topSections: helpCatalog.counts.topSections,
  instructionDocuments: instructionCatalog.counts.documents,
  standardInstructionDocuments: instructionCatalog.counts.standardInstructionDocuments,
  customInstructionDocuments: instructionCatalog.counts.customInstructionDocuments,
  uniqueNodeIds: allNodeIds.size,
  helpMenuTreeSha256: helpCatalog.source.menuTreeSha256,
  instructionMenuTreeSha256: instructionCatalog.source.menuTreeSha256,
  fullTextStored: helpCatalog.source.fullTextStored,
  sampledSections,
  pagination: pagination.slice(0, 3).map((item) => ({
    title: item.title,
    section: item.topSection,
  })),
  openApi: openApi.slice(0, 3).map((item) => item.title),
}, null, 2));
