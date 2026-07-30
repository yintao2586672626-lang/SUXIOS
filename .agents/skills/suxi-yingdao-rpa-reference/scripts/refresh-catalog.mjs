import { createHash } from 'node:crypto';
import { mkdir, writeFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const INSTRUCTION_SCHEMA_VERSION = 'suxi_yingdao_rpa_catalog.v1';
const HELP_CENTER_SCHEMA_VERSION = 'suxi_yingdao_rpa_help_center_catalog.v1';
const INSTRUCTION_ROOT_MENU_ID = '711200729240932352';
const MENU_API = 'https://api.yingdao.com/api/noauth/v1/yddoc/menus/getMenuTreeByBrandCode?brandCode=rpa&languageCode=zh-CN';
const ROBOTS_URL = 'https://www.yingdao.com/robots.txt';
const DOC_BASE_URL = 'https://www.yingdao.com/yddoc/rpa/zh-CN';
const LICENSE_URL = 'https://www.yingdao.com/html/user_license.html';
const SKILL_DIR = dirname(dirname(fileURLToPath(import.meta.url)));
const REFERENCE_DIR = join(SKILL_DIR, 'references');

const fetchWithTimeout = async (url, responseType) => {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), 30_000);
  try {
    const response = await fetch(url, {
      headers: {
        Accept: responseType === 'json' ? 'application/json' : 'text/plain',
        'User-Agent': 'SUXIOS-Yingdao-RPA-Reference/1.0 (public metadata catalog)',
      },
      signal: controller.signal,
    });
    if (!response.ok) {
      throw new Error(`HTTP ${response.status} for ${url}`);
    }
    return responseType === 'json' ? response.json() : response.text();
  } finally {
    clearTimeout(timer);
  }
};

const hashJson = (value) => createHash('sha256')
  .update(JSON.stringify(value))
  .digest('hex');

const walkNodes = (node, path = [], topSection = node?.name || null, output = []) => {
  if (!node || node.enable === false) return output;
  const nextPath = [...path, node.name];
  output.push({
    id: String(node.id),
    parentId: node.parentId === null || node.parentId === undefined
      ? ''
      : String(node.parentId),
    title: String(node.name || ''),
    type: String(node.type || ''),
    scope: String(node.scope || ''),
    seoKey: String(node.seoKey || ''),
    path: nextPath,
    topSection,
    childCount: (node.menuTrees || []).filter((child) => child.enable !== false).length,
  });
  for (const child of node.menuTrees || []) {
    walkNodes(child, nextPath, topSection, output);
  }
  return output;
};

const countDescendants = (node) => {
  const nodes = walkNodes(node);
  return {
    documentCount: nodes.filter((item) => item.type === 'doc').length,
    folderCount: nodes.filter((item) => item.type === 'folder').length,
  };
};

const mapHelpDocument = (item) => ({
  id: item.id,
  parentId: item.parentId,
  title: item.title,
  type: 'doc',
  topSection: item.topSection,
  categoryPath: item.path.slice(0, -1),
  path: item.path,
  scope: item.scope,
  seoKey: item.seoKey,
  sourceUrl: `${DOC_BASE_URL}/${item.id}`,
  contentStored: false,
});

const mapHelpFolder = (item) => ({
  id: item.id,
  parentId: item.parentId,
  title: item.title,
  type: 'folder',
  topSection: item.topSection,
  path: item.path,
  childCount: item.childCount,
});

const renderCategoryMap = (catalog) => {
  const rows = catalog.categories.map((category) => (
    `| ${category.title} | ${category.documentCount} | ${category.folderCount} |`
  ));
  return `# 影刀 RPA 指令目录

> 只保存公开菜单元数据，不保存影刀正文、图片、HTML或代码。

- 核对时间：${catalog.generatedAt}
- 官方菜单接口：${catalog.source.menuApi}
- 指令文档：${catalog.counts.documents}
- 标准指令分支：${catalog.counts.standardInstructionDocuments}
- 自定义指令分支：${catalog.counts.customInstructionDocuments}（菜单接口未提供逐条作者或许可）
- 文件夹：${catalog.counts.folders}
- 一级分类：${catalog.counts.topCategories}
- 来源指纹：\`${catalog.source.menuTreeSha256}\`

| 一级分类 | 指令文档 | 文件夹（含本分类根） |
| --- | ---: | ---: |
${rows.join('\n')}

具体标题、路径、ID和官方链接见 [command-index.json](command-index.json)。需要正文时只按任务读取少量官方页面，不批量镜像。
`;
};

const renderHelpCenterMap = (catalog) => {
  const rows = catalog.sections.map((section) => (
    `| ${section.title} | ${section.documentCount} | ${section.folderCount} |`
  ));
  return `# 影刀 RPA 帮助中心目录

> 只保存公开菜单元数据，不保存影刀正文、图片、HTML或代码。

- 核对时间：${catalog.generatedAt}
- 官方菜单接口：${catalog.source.menuApi}
- 顶层栏目：${catalog.counts.topSections}
- 文档：${catalog.counts.documents}
- 文件夹：${catalog.counts.folders}
- 全部节点：${catalog.counts.totalNodes}
- 空文件夹：${catalog.counts.emptyFolders}
- 最大目录深度：${catalog.counts.maxDepth}
- 来源指纹：\`${catalog.source.menuTreeSha256}\`

| 顶层栏目 | 文档 | 文件夹（含栏目根） |
| --- | ---: | ---: |
${rows.join('\n')}

具体标题、路径、ID和官方链接见 [help-center-index.json](help-center-index.json)。需要正文时只按任务读取少量官方页面，不批量镜像。
`;
};

const renderSourceInventory = (helpCatalog, instructionCatalog, manifest) => `# 来源与边界

## 当前快照

- 来源：影刀官方公开 RPA 帮助文档菜单
- 菜单接口：${helpCatalog.source.menuApi}
- 核对时间：${helpCatalog.generatedAt}
- 帮助中心顶层栏目：${helpCatalog.counts.topSections}
- 帮助中心文档：${helpCatalog.counts.documents}
- 帮助中心文件夹：${helpCatalog.counts.folders}
- 帮助中心全部节点：${helpCatalog.counts.totalNodes}
- 指令根 ID：\`${INSTRUCTION_ROOT_MENU_ID}\`
- 指令文档：${instructionCatalog.counts.documents}
- 标准指令分支：${instructionCatalog.counts.standardInstructionDocuments}
- 自定义指令分支：${instructionCatalog.counts.customInstructionDocuments}
- 帮助中心目录 SHA-256：\`${helpCatalog.source.menuTreeSha256}\`
- 指令目录 SHA-256：\`${instructionCatalog.source.menuTreeSha256}\`
- 文档正文更新时间：未知；菜单接口没有提供可证明的编辑时间

## 抓取边界

- robots.txt：${manifest.robots.url}
- \`/yddoc/\` 状态：${manifest.robots.yddocAllowed ? '未被禁止' : '被禁止或无法确认'}
- 许可状态：\`${manifest.license.status}\`
- 用户协议：${manifest.license.url}

本地只保存菜单元数据和原创映射，不保存完整正文、图片、HTML、截图或示例代码。robots 允许不等于取得复制或转载许可。

“自定义指令”分支的公开菜单没有提供逐条作者或许可字段，因此只能确认页面存在，不能把该分支全部表述为影刀官方内置指令。

## 更新规则

运行 \`scripts/refresh-catalog.mjs\` 只刷新帮助中心与指令目录元数据及 robots.txt。目录 SHA 变化时，先查看新增、删除或改名的条目；正文只在真实任务命中后通过 \`scripts/fetch-doc-outline.mjs\` 单页读取。
`;

const main = async () => {
  const generatedAt = new Date().toISOString();
  const [menuPayload, robotsText] = await Promise.all([
    fetchWithTimeout(MENU_API, 'json'),
    fetchWithTimeout(ROBOTS_URL, 'text'),
  ]);
  if (!menuPayload || menuPayload.success !== true || !Array.isArray(menuPayload.data)) {
    throw new Error('Unexpected Yingdao menu API response');
  }

  const root = menuPayload.data.find((item) => String(item.id) === INSTRUCTION_ROOT_MENU_ID);
  if (!root || root.type !== 'folder') {
    throw new Error(`Instruction root ${INSTRUCTION_ROOT_MENU_ID} was not found`);
  }

  const helpNodes = menuPayload.data.flatMap((section) => walkNodes(section));
  const helpFolders = helpNodes.filter((item) => item.type === 'folder');
  const helpFolderMetadata = helpFolders.map(mapHelpFolder);
  const helpDocuments = helpNodes
    .filter((item) => item.type === 'doc')
    .map(mapHelpDocument);
  const helpSections = menuPayload.data
    .filter((item) => item.enable !== false)
    .map((item) => ({
      id: String(item.id),
      title: String(item.name || ''),
      type: String(item.type || ''),
      ...countDescendants(item),
      sourceUrl: item.type === 'doc' ? `${DOC_BASE_URL}/${item.id}` : null,
    }));
  const stableHelpFingerprint = helpNodes.map((item) => ({
    id: item.id,
    title: item.title,
    type: item.type,
    path: item.path,
    scope: item.scope,
  }));
  const helpMenuTreeSha256 = hashJson(stableHelpFingerprint);
  const helpCatalog = {
    schemaVersion: HELP_CENTER_SCHEMA_VERSION,
    generatedAt,
    source: {
      provider: '影刀',
      product: '影刀RPA',
      language: 'zh-CN',
      menuApi: MENU_API,
      docBaseUrl: DOC_BASE_URL,
      menuTreeSha256: helpMenuTreeSha256,
      editorialUpdatedAt: null,
      fullTextStored: false,
    },
    counts: {
      topSections: helpSections.length,
      documents: helpDocuments.length,
      folders: helpFolders.length,
      totalNodes: helpNodes.length,
      emptyFolders: helpFolders.filter((item) => item.childCount === 0).length,
      maxDepth: Math.max(...helpNodes.map((item) => item.path.length)),
    },
    sections: helpSections,
    folders: helpFolderMetadata,
    documents: helpDocuments,
  };

  const instructionNodes = walkNodes(root);
  const instructionFolders = instructionNodes.filter((item) => item.type === 'folder');
  const instructionDocuments = instructionNodes
    .filter((item) => item.type === 'doc')
    .map((item) => ({
      id: item.id,
      title: item.title,
      topCategory: item.path[1] || '',
      catalogBranch: item.path[1] === '自定义指令'
        ? 'custom_instruction'
        : 'standard_instruction',
      authorship: item.path[1] === '自定义指令' ? 'unknown' : 'provider_published',
      categoryPath: item.path.slice(1, -1),
      path: item.path.slice(1),
      scope: item.scope,
      seoKey: item.seoKey,
      sourceUrl: `${DOC_BASE_URL}/${item.id}`,
      contentStored: false,
    }));

  const categories = (root.menuTrees || [])
    .filter((item) => item.enable !== false)
    .map((item) => ({
      id: String(item.id),
      title: String(item.name || ''),
      type: String(item.type || ''),
      ...countDescendants(item),
      sourceUrl: item.type === 'doc' ? `${DOC_BASE_URL}/${item.id}` : null,
    }));

  const stableMenuFingerprint = instructionDocuments.map((item) => ({
    id: item.id,
    title: item.title,
    path: item.path,
    scope: item.scope,
  }));
  const menuTreeSha256 = hashJson(stableMenuFingerprint);
  const customInstructionDocuments = instructionDocuments.filter((item) => (
    item.catalogBranch === 'custom_instruction'
  )).length;
  const standardInstructionDocuments = instructionDocuments.length - customInstructionDocuments;
  const instructionCatalog = {
    schemaVersion: INSTRUCTION_SCHEMA_VERSION,
    generatedAt,
    source: {
      provider: '影刀',
      product: '影刀RPA',
      language: 'zh-CN',
      rootMenuId: INSTRUCTION_ROOT_MENU_ID,
      menuApi: MENU_API,
      docBaseUrl: DOC_BASE_URL,
      menuTreeSha256,
      editorialUpdatedAt: null,
      fullTextStored: false,
    },
    counts: {
      topCategories: categories.length,
      documents: instructionDocuments.length,
      standardInstructionDocuments,
      customInstructionDocuments,
      folders: instructionFolders.length,
    },
    categories,
    documents: instructionDocuments,
  };

  const disallowRules = robotsText
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter((line) => /^disallow:/i.test(line))
    .map((line) => line.replace(/^disallow:\s*/i, ''));
  const yddocBlockedBy = disallowRules.filter((rule) => (
    rule === '/yddoc' || rule === '/yddoc/' || rule === '/yddoc/*'
  ));
  const manifest = {
    schemaVersion: 'suxi_yingdao_rpa_source_manifest.v1',
    generatedAt,
    sourceFingerprint: menuTreeSha256,
    sourceFingerprints: {
      helpCenter: helpMenuTreeSha256,
      instructionCatalog: menuTreeSha256,
    },
    robots: {
      url: ROBOTS_URL,
      checkedAt: generatedAt,
      sha256: createHash('sha256').update(robotsText).digest('hex'),
      yddocAllowed: yddocBlockedBy.length === 0,
      matchingDisallowRules: yddocBlockedBy,
    },
    license: {
      url: LICENSE_URL,
      checkedAt: generatedAt,
      status: 'restricted_or_unknown',
      policy: 'metadata_and_original_summaries_only',
    },
    storage: {
      menuMetadata: true,
      helpCenterMenuMetadata: true,
      fullText: false,
      images: false,
      html: false,
      codeSamples: false,
    },
    provenanceNotes: {
      customInstructionBranch: 'The public menu API does not identify authors or licenses for individual custom instructions.',
    },
  };

  await mkdir(REFERENCE_DIR, { recursive: true });
  await Promise.all([
    writeFile(
      join(REFERENCE_DIR, 'help-center-index.json'),
      `${JSON.stringify(helpCatalog, null, 2)}\n`,
      'utf8',
    ),
    writeFile(
      join(REFERENCE_DIR, 'command-index.json'),
      `${JSON.stringify(instructionCatalog, null, 2)}\n`,
      'utf8',
    ),
    writeFile(
      join(REFERENCE_DIR, 'source-manifest.json'),
      `${JSON.stringify(manifest, null, 2)}\n`,
      'utf8',
    ),
    writeFile(
      join(REFERENCE_DIR, 'help-center-map.md'),
      renderHelpCenterMap(helpCatalog),
      'utf8',
    ),
    writeFile(
      join(REFERENCE_DIR, 'category-map.md'),
      renderCategoryMap(instructionCatalog),
      'utf8',
    ),
    writeFile(
      join(REFERENCE_DIR, 'source-inventory.md'),
      renderSourceInventory(helpCatalog, instructionCatalog, manifest),
      'utf8',
    ),
  ]);

  console.log(JSON.stringify({
    status: 'ok',
    generatedAt,
    helpCenterDocuments: helpDocuments.length,
    helpCenterFolders: helpFolders.length,
    helpCenterNodes: helpNodes.length,
    emptyFolders: helpCatalog.counts.emptyFolders,
    topSections: helpSections.length,
    maxDepth: helpCatalog.counts.maxDepth,
    instructionDocuments: instructionDocuments.length,
    standardInstructionDocuments,
    customInstructionDocuments,
    topCategories: categories.length,
    helpMenuTreeSha256,
    instructionMenuTreeSha256: menuTreeSha256,
    yddocAllowedByRobots: manifest.robots.yddocAllowed,
    fullTextStored: false,
  }, null, 2));
};

main().catch((error) => {
  console.error(`[refresh-catalog] ${error.message}`);
  process.exitCode = 1;
});
