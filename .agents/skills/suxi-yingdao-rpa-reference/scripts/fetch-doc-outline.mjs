import { readFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const SKILL_DIR = dirname(dirname(fileURLToPath(import.meta.url)));
const INSTRUCTION_CATALOG_PATH = join(SKILL_DIR, 'references', 'command-index.json');
const HELP_CATALOG_PATH = join(SKILL_DIR, 'references', 'help-center-index.json');
const CONTENT_API = 'https://api.yingdao.com/api/noauth/v1/yddoc/menus';
const OSS_DOMAIN_API = 'https://api.yingdao.com/api/noauth/v1/yddoc/oss/domain';

const fetchJson = async (url) => {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), 30_000);
  try {
    const response = await fetch(url, {
      headers: {
        Accept: 'application/json',
        'User-Agent': 'SUXIOS-Yingdao-RPA-Reference/1.0 (single public document outline)',
      },
      signal: controller.signal,
    });
    if (!response.ok) throw new Error(`HTTP ${response.status} for ${url}`);
    return response.json();
  } finally {
    clearTimeout(timer);
  }
};

const textOf = (node) => {
  if (!node) return '';
  if (node.type === 'text') return String(node.text || '');
  if (node.type === 'hardBreak') return '\n';
  return (node.content || []).map(textOf).join('');
};

const collectOutline = (documentJson, maxChars) => {
  const headings = [];
  const keyPoints = [];
  let observedCharacters = 0;
  for (const node of documentJson.content || []) {
    const text = textOf(node).replace(/\s+/g, ' ').trim();
    if (!text) continue;
    observedCharacters += text.length;
    if (node.type === 'heading') {
      headings.push({
        level: Number(node.attrs?.level || 0),
        text: text.slice(0, 240),
      });
      continue;
    }
    if (['paragraph', 'bulletList', 'orderedList', 'blockquote'].includes(node.type)) {
      keyPoints.push(text.slice(0, 360));
    }
  }

  const boundedPoints = [];
  let emittedCharacters = 0;
  for (const point of keyPoints) {
    if (emittedCharacters + point.length > maxChars) break;
    boundedPoints.push(point);
    emittedCharacters += point.length;
  }
  return {
    headings: headings.slice(0, 80),
    keyPoints: boundedPoints,
    observedCharacters,
    emittedCharacters,
    truncated: boundedPoints.length < keyPoints.length,
    imageCount: (documentJson.content || []).filter((node) => node.type === 'image').length,
  };
};

const parseArgs = (argv) => {
  let documentId = '';
  let maxChars = 6_000;
  for (const arg of argv) {
    if (/^\d{12,}$/.test(arg)) {
      documentId = arg;
    } else if (arg.startsWith('--max-chars=')) {
      maxChars = Math.max(1_000, Math.min(12_000, Number(arg.slice(12)) || 6_000));
    }
  }
  return { documentId, maxChars };
};

const main = async () => {
  const { documentId, maxChars } = parseArgs(process.argv.slice(2));
  if (!documentId) {
    throw new Error('Usage: fetch-doc-outline.mjs <document-id> [--max-chars=6000]');
  }

  const [instructionCatalog, helpCatalog] = await Promise.all([
    readFile(INSTRUCTION_CATALOG_PATH, 'utf8').then(JSON.parse),
    readFile(HELP_CATALOG_PATH, 'utf8').then(JSON.parse),
  ]);
  const metadata = instructionCatalog.documents.find((item) => item.id === documentId)
    || helpCatalog.documents.find((item) => item.id === documentId);
  if (!metadata) {
    throw new Error(`Document ${documentId} is not present in the local help-center catalog`);
  }

  const [contentPointer, ossDomain] = await Promise.all([
    fetchJson(`${CONTENT_API}/${documentId}/contents`),
    fetchJson(OSS_DOMAIN_API),
  ]);
  const relativePath = String(contentPointer?.data?.content || '');
  const domain = String(ossDomain?.data || '');
  if (!contentPointer?.success || !relativePath.startsWith('/yddoc/')) {
    throw new Error(`Unexpected content pointer for document ${documentId}`);
  }
  if (!ossDomain?.success || !/^https:\/\/[^/]+\.yingdao\.com$/i.test(domain)) {
    throw new Error('Unexpected Yingdao document resource domain');
  }

  const documentJson = await fetchJson(`${domain}${relativePath}`);
  const outline = collectOutline(documentJson, maxChars);
  console.log(JSON.stringify({
    status: 'observed',
    checkedAt: new Date().toISOString(),
    sourceUrl: metadata.sourceUrl,
    documentId,
    title: metadata.title,
    topSection: metadata.topSection || '指令文档',
    categoryPath: metadata.path,
    contentStored: false,
    licenseStatus: 'restricted_or_unknown',
    ...outline,
  }, null, 2));
};

main().catch((error) => {
  console.error(`[fetch-doc-outline] ${error.message}`);
  process.exitCode = 1;
});
