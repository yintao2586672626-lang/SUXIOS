import { readFile } from 'node:fs/promises';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const SKILL_DIR = dirname(dirname(fileURLToPath(import.meta.url)));
const HELP_CATALOG_PATH = join(SKILL_DIR, 'references', 'help-center-index.json');
const INSTRUCTION_CATALOG_PATH = join(SKILL_DIR, 'references', 'command-index.json');

const SYNONYM_GROUPS = [
  ['抓取', '采集', '获取', '提取', '爬取'],
  ['翻页', '分页', '下一页', '下拉', '滚动', '加载更多'],
  ['点击', '单击', '双击', '按下'],
  ['输入', '填写', '填表', '录入'],
  ['选择', '下拉框', '单选', '多选', '级联'],
  ['等待', '超时', '重试', '加载完成'],
  ['表格', 'Excel', 'WPS', '数据表格'],
  ['请求', 'HTTP', '网络', '接口', 'API'],
  ['元素', '选择器', '定位', '相似元素'],
  ['AI', '人工智能', '大模型', '文本生成'],
  ['入门', '新手', '开始', '安装', '下载'],
  ['问题', '报错', '故障', 'FAQ', '常见问题'],
  ['开放API', 'OpenAPI', '开发接口'],
  ['管理', '权限', '团队', '企业', '组织'],
  ['方案', '场景', '解决方案', '最佳实践'],
];

const normalize = (value) => String(value || '')
  .normalize('NFKC')
  .toLowerCase()
  .replace(/\s+/g, ' ')
  .trim();

const tokenize = (query) => {
  const normalized = normalize(query);
  const seeds = normalized.split(/[^\p{L}\p{N}]+/u).filter(Boolean);
  const terms = new Set(seeds);
  for (const seed of seeds) {
    if (/^[\p{Script=Han}]+$/u.test(seed) && seed.length > 2) {
      for (let size = 2; size <= Math.min(4, seed.length); size += 1) {
        for (let index = 0; index <= seed.length - size; index += 1) {
          terms.add(seed.slice(index, index + size));
        }
      }
    }
  }
  for (const group of SYNONYM_GROUPS) {
    const normalizedGroup = group.map(normalize);
    if (normalizedGroup.some((term) => [...terms].some((item) => item.includes(term)))) {
      normalizedGroup.forEach((term) => terms.add(term));
    }
  }
  return [...terms].filter((term) => term.length >= 2);
};

const scoreDocument = (document, query, terms) => {
  const title = normalize(document.title);
  const path = normalize((document.path || []).join(' / '));
  const seoKey = normalize(document.seoKey);
  const normalizedQuery = normalize(query);
  let score = 0;
  if (title === normalizedQuery) score += 300;
  if (title.includes(normalizedQuery)) score += 160;
  if (path.includes(normalizedQuery)) score += 80;
  for (const term of terms) {
    if (title === term) score += 60;
    if (title.includes(term)) score += 22 + Math.min(term.length, 8);
    if (path.includes(term)) score += 9 + Math.min(term.length, 6);
    if (seoKey.includes(term)) score += 5;
  }
  const isWebIntent = /网页|浏览器|\bweb\b/.test(normalizedQuery);
  const isPaginationIntent = /翻页|分页|下一页|加载更多|滚动到底|下拉加载/.test(normalizedQuery);
  const isDropdownIntent = /下拉框|级联|单选|多选/.test(normalizedQuery);
  if (isWebIntent && path.includes('网页自动化')) score += 45;
  if (isPaginationIntent) {
    if (/(for次数循环|while条件循环|无限循环)/i.test(title)) {
      score += 110;
    }
    if (/(点击元素\(web\)|等待网页加载完成|鼠标滚动网页|滚动鼠标滚轮|if 元素可见|if 网页包含)/i.test(title)) {
      score += 85;
    }
    if (/(滚动条位置|元素数量)/.test(title)) score += 30;
  }
  if (isDropdownIntent && /下拉框|单选|多选|级联/.test(title)) score += 70;
  const section = normalize(document.topSection || document.path?.[0]);
  const sectionIntents = [
    ['影刀概述', /概述|介绍|是什么|产品定位/],
    ['快速入门', /快速入门|新手|开始使用|安装|下载|注册/],
    ['功能文档', /功能文档|功能说明|产品功能/],
    ['指令文档', /指令|魔法指令|循环|条件判断|元素操作/],
    ['接口文档', /接口文档|自动化接口|指令接口/],
    ['常见问题', /常见问题|问题|报错|故障|faq|为什么/],
    ['开放api', /开放api|openapi|开发接口|api文档/],
    ['管理文档', /管理文档|管理后台|组织|团队|成员|权限|企业/],
    ['专题文档', /专题文档|专题|专项说明/],
    ['解决方案', /解决方案|业务场景|最佳实践|方案/],
  ];
  for (const [sectionName, pattern] of sectionIntents) {
    if (section === normalize(sectionName) && pattern.test(normalizedQuery)) score += 100;
  }
  if (/开放api|openapi/.test(normalizedQuery) && title.includes('开放接口使用指南')) {
    score += 140;
  }
  if (/废弃|已下线|旧版/.test(title) && !/废弃|已下线|旧版/.test(normalizedQuery)) {
    score -= 240;
  }
  if (path.includes('自定义指令') && !normalizedQuery.includes('自定义')) {
    score -= 45;
  }
  return score;
};

export const loadCatalog = async () => (
  JSON.parse(await readFile(INSTRUCTION_CATALOG_PATH, 'utf8'))
);
export const loadInstructionCatalog = loadCatalog;
export const loadHelpCatalog = async () => (
  JSON.parse(await readFile(HELP_CATALOG_PATH, 'utf8'))
);

export const searchDocuments = (catalog, query, limit = 12, section = '') => {
  const terms = tokenize(query);
  return catalog.documents
    .filter((document) => !section || (
      normalize(document.topSection || document.path?.[0]) === normalize(section)
    ))
    .map((document) => ({
      ...document,
      score: scoreDocument(document, query, terms),
    }))
    .filter((document) => document.score > 0)
    .sort((left, right) => (
      right.score - left.score
      || left.path.length - right.path.length
      || left.title.localeCompare(right.title, 'zh-CN')
    ))
    .slice(0, limit);
};

const parseArgs = (argv) => {
  const positional = [];
  let limit = 12;
  let json = false;
  let section = '';
  let scope = 'instructions';
  for (const arg of argv) {
    if (arg.startsWith('--limit=')) {
      limit = Math.max(1, Math.min(50, Number(arg.slice('--limit='.length)) || 12));
    } else if (arg === '--json') {
      json = true;
    } else if (arg.startsWith('--section=')) {
      section = arg.slice('--section='.length).trim();
    } else if (arg.startsWith('--scope=')) {
      scope = arg.slice('--scope='.length).trim();
    } else {
      positional.push(arg);
    }
  }
  return {
    query: positional.join(' ').trim(),
    limit,
    json,
    section,
    scope,
  };
};

const main = async () => {
  const {
    query,
    limit,
    json,
    section,
    scope,
  } = parseArgs(process.argv.slice(2));
  const useHelpCenter = scope === 'help-center' || Boolean(section);
  if (!['instructions', 'help-center'].includes(scope)) {
    throw new Error('Scope must be "instructions" or "help-center"');
  }
  const catalog = useHelpCenter ? await loadHelpCatalog() : await loadCatalog();
  if (!query) {
    const groups = catalog.sections || catalog.categories || [];
    const output = {
      generatedAt: catalog.generatedAt,
      counts: catalog.counts,
      scope: useHelpCenter ? 'help-center' : 'instructions',
      groups,
    };
    console.log(json ? JSON.stringify(output, null, 2) : groups
      .map((item) => `${item.title}: ${item.documentCount}`)
      .join('\n'));
    return;
  }

  const results = searchDocuments(catalog, query, limit, section);
  if (json) {
    console.log(JSON.stringify({
      query,
      scope: useHelpCenter ? 'help-center' : 'instructions',
      section: section || null,
      count: results.length,
      results,
    }, null, 2));
    return;
  }
  if (!results.length) {
    console.log(`未找到与“${query}”相关的影刀帮助文档。`);
    return;
  }
  console.log(results.map((item, index) => [
    `${index + 1}. ${item.title}`,
    `   栏目：${item.topSection || item.path[0]}`,
    `   路径：${item.path.join(' / ')}`,
    `   文档ID：${item.id}`,
    `   来源：${item.sourceUrl}`,
  ].join('\n')).join('\n'));
};

if (process.argv[1] && pathToFileURL(resolve(process.argv[1])).href === import.meta.url) {
  main().catch((error) => {
    console.error(`[search-catalog] ${error.message}`);
    process.exitCode = 1;
  });
}
