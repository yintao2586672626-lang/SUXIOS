import fs from 'node:fs';
import path from 'node:path';

export const FRONTEND_TEMPLATE_FRAGMENT_DEFINITIONS = Object.freeze([
  { id: 'app-shell', domain: 'shell', path: 'fragments/00-app-shell.html', anchor: '<!-- 登录页面 -->' },
  { id: 'page-ai-strategy', domain: 'ai-decision', path: 'fragments/01-page-ai-strategy.html', anchor: '<div v-if="currentPage === \'ai-strategy\'">' },
  { id: 'page-ai-simulation', domain: 'ai-decision', path: 'fragments/02-page-ai-simulation.html', anchor: '<div v-if="currentPage === \'ai-simulation\'">' },
  { id: 'page-ai-feasibility', domain: 'investment', path: 'fragments/03-page-ai-feasibility.html', anchor: '<div v-if="currentPage === \'ai-feasibility\'" class="feasibility-page">' },
  { id: 'page-market-evaluation', domain: 'investment', path: 'fragments/04-page-market-evaluation.html', anchor: '<div v-if="currentPage === \'market-evaluation\' || currentPage === \'market-eval\'">' },
  { id: 'page-benchmark-model', domain: 'investment', path: 'fragments/05-page-benchmark-model.html', anchor: '<div v-if="currentPage === \'benchmark-model\'">' },
  { id: 'page-collaboration-efficiency', domain: 'operations', path: 'fragments/06-page-collaboration-efficiency.html', anchor: '<div v-if="currentPage === \'collaboration-efficiency\' || currentPage === \'sync-efficiency\'">' },
  { id: 'shared-expansion-history', domain: 'shared-investment', path: 'fragments/07-shared-expansion-history.html', anchor: '<div v-if="[\'market-evaluation\', \'market-eval\', \'benchmark-model\', \'collaboration-efficiency\', \'sync-efficiency\'].includes(currentPage)" class="mt-6 bg-white rounded-xl shadow-sm border border-gray-100 p-5">' },
  { id: 'shared-transfer-context', domain: 'shared-investment', path: 'fragments/08-shared-transfer-context.html', anchor: '<div v-if="[\'asset-pricing\', \'timing-strategy\', \'decision-board\'].includes(currentPage)" class="mb-6 bg-white rounded-xl shadow-sm border border-gray-100 p-5">' },
  { id: 'page-asset-pricing', domain: 'investment', path: 'fragments/09-page-asset-pricing.html', anchor: '<div v-if="currentPage === \'asset-pricing\'">' },
  { id: 'page-timing-strategy', domain: 'investment', path: 'fragments/10-page-timing-strategy.html', anchor: '<div v-if="currentPage === \'timing-strategy\'">' },
  { id: 'page-decision-board', domain: 'investment', path: 'fragments/11-page-decision-board.html', anchor: '<div v-if="currentPage === \'decision-board\'">' },
  { id: 'shared-transfer-history', domain: 'shared-investment', path: 'fragments/12-shared-transfer-history.html', anchor: '<div v-if="[\'asset-pricing\', \'timing-strategy\', \'decision-board\'].includes(currentPage)" class="mt-6 bg-white rounded-xl shadow-sm border border-gray-100 p-5">' },
  { id: 'page-opening-overview', domain: 'opening', path: 'fragments/13-page-opening-overview.html', anchor: '<div v-if="currentPage === \'opening-overview\'" class="max-w-7xl mx-auto space-y-6">' },
  { id: 'page-opening-checklist', domain: 'opening', path: 'fragments/14-page-opening-checklist.html', anchor: '<div v-if="currentPage === \'opening-checklist\'" class="max-w-7xl mx-auto space-y-6">' },
  { id: 'page-ops-source', domain: 'operations', path: 'fragments/15a-page-ops-source.html', anchor: '<div v-if="currentPage === \'ops-source\'" class="max-w-7xl mx-auto space-y-6">' },
  { id: 'page-ops-analysis', domain: 'operations', path: 'fragments/15b-page-ops-analysis.html', anchor: '<div v-if="currentPage === \'ops-analysis\'" class="max-w-7xl mx-auto space-y-6">' },
  { id: 'page-ops-insight', domain: 'operations', path: 'fragments/15c-page-ops-insight.html', anchor: '<div v-if="currentPage === \'ops-insight\'" class="max-w-7xl mx-auto space-y-6">' },
  { id: 'page-ops-plan', domain: 'operations', path: 'fragments/15-page-ops-plan.html', anchor: '<div v-if="currentPage === \'ops-plan\'" class="max-w-7xl mx-auto space-y-6">' },
  { id: 'page-ai-daily-report', domain: 'operations', path: 'fragments/16-page-ai-daily-report.html', anchor: '<div v-if="currentPage === \'ai-daily-report\'" class="max-w-7xl mx-auto space-y-6">' },
  { id: 'page-ops-track', domain: 'operations', path: 'fragments/17-page-ops-track.html', anchor: '<div v-if="currentPage === \'ops-track\'" class="max-w-7xl mx-auto space-y-6">' },
  { id: 'page-hotels', domain: 'hotel-admin', path: 'fragments/18-page-hotels.html', anchor: '<div v-if="currentPage === \'hotels\'" class="space-y-5">' },
  { id: 'page-revenue-research-center', domain: 'revenue', path: 'fragments/19-page-revenue-research-center.html', anchor: '<div v-if="currentPage === \'revenue-research-center\'" class="max-w-7xl mx-auto space-y-4">' },
  { id: 'page-knowledge-center', domain: 'knowledge', path: 'fragments/20-page-knowledge-center.html', anchor: '<div v-if="currentPage === \'knowledge-center\'" class="max-w-6xl mx-auto space-y-4">' },
  { id: 'page-users', domain: 'system-admin', path: 'fragments/21-page-users.html', anchor: '<div v-if="currentPage === \'users\'">' },
  { id: 'page-roles', domain: 'system-admin', path: 'fragments/22-page-roles.html', anchor: '<div v-if="currentPage === \'roles\'">' },
  { id: 'home-shell-open', domain: 'decision-workbench', path: 'fragments/23-page-home-shell-open.html', anchor: '<div v-if="currentPage === \'ai-workbench\' || currentPage === \'compass\'" class="compass-dashboard suxi-dashboard-scope">' },
  { id: 'page-compass-summary', domain: 'decision-workbench', path: 'fragments/23a-page-compass-summary.html', anchor: '<section v-if="currentPage === \'compass\'" class="compass-hero-bezel" data-testid="home-executive-answer">' },
  { id: 'page-ai-workbench', domain: 'decision-workbench', path: 'fragments/23b-page-ai-workbench.html', anchor: '<div v-if="currentPage === \'ai-workbench\'" class="dual-ota-home order-first" data-testid="home-ai-workbench">' },
  { id: 'page-compass-detail', domain: 'decision-workbench', path: 'fragments/23c-page-compass-detail.html', anchor: '<details v-if="currentPage === \'compass\'" class="suxi-evidence-fold" data-testid="home-full-detail-fold">' },
  { id: 'home-shell-card-close', domain: 'decision-workbench', path: 'fragments/23d-home-shell-card-close.html', anchor: '                            </div>\n                        </div>\n\n                        <details class="mb-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" data-testid="home-secondary-detail-fold">' },
  { id: 'home-shared-secondary', domain: 'decision-workbench', path: 'fragments/23e-home-shared-secondary.html', anchor: '<details class="mb-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" data-testid="home-secondary-detail-fold">' },
  { id: 'page-ctrip-ebooking', domain: 'ota-ctrip', path: 'fragments/24-page-ctrip-ebooking.html', anchor: '<div v-if="currentPage === \'ctrip-ebooking\'">' },
  { id: 'page-ctrip-fetch-settings', domain: 'ota-ctrip', path: 'fragments/25-page-ctrip-fetch-settings.html', anchor: '<div v-if="currentPage === \'ctrip-ebooking\' &amp;&amp; onlineDataTab === \'ctrip-fetch-settings\'" class="bg-white rounded-lg shadow mt-4">' },
  { id: 'page-meituan-ebooking', domain: 'ota-meituan', path: 'fragments/26-page-meituan-ebooking.html', anchor: '<div v-if="currentPage === \'meituan-ebooking\'">' },
  { id: 'page-agent-center', domain: 'agent-center', path: 'fragments/27-page-agent-center.html', anchor: '<div v-if="currentPage === \'agent-center\'">' },
  { id: 'page-investment-decision', domain: 'investment', path: 'fragments/28-page-investment-decision.html', anchor: '<div v-if="currentPage === \'investment-decision\'" class="max-w-7xl mx-auto space-y-5">' },
  { id: 'page-lifecycle', domain: 'lifecycle', path: 'fragments/29-page-lifecycle.html', anchor: '<div v-if="currentPage === \'lifecycle\'" class="suxi-lifecycle-view max-w-7xl mx-auto space-y-6">' },
  { id: 'page-operation-logs', domain: 'system-admin', path: 'fragments/30-page-operation-logs.html', anchor: '<div v-if="currentPage === \'operation-logs\'">' },
  { id: 'page-system-config', domain: 'system-admin', path: 'fragments/31-page-system-config.html', anchor: '<div v-if="currentPage === \'system-config\'">' },
  { id: 'page-ai-model-config', domain: 'ai-governance', path: 'fragments/32-page-ai-model-config.html', anchor: '<div v-if="currentPage === \'ai-model-config\'">' },
  { id: 'page-ai-governance', domain: 'ai-governance', path: 'fragments/33-page-ai-governance.html', anchor: '<div v-if="currentPage === \'ai-governance\'" class="space-y-6">' },
  { id: 'page-data-config', domain: 'system-admin', path: 'fragments/34-page-data-config.html', anchor: '<div v-if="currentPage === \'data-config\'">' },
  { id: 'page-online-data', domain: 'ota-data', path: 'fragments/35-page-online-data.html', anchor: '<div v-if="currentPage === \'online-data\'">' },
  { id: 'app-shell-close', domain: 'shell', path: 'fragments/36-app-shell-close.html', anchor: '                </div>\n            </div></main>\n        </div>\n\n        <div v-if="showCtripCookieEditorModal"' },
  { id: 'dialog-ctrip-cookie-editor', domain: 'ota-ctrip', path: 'fragments/37-dialog-ctrip-cookie-editor.html', anchor: '<div v-if="showCtripCookieEditorModal"' },
  { id: 'dialogs-knowledge-center', domain: 'knowledge', path: 'fragments/38-dialogs-knowledge-center.html', anchor: '<!-- 智能知识中枢：单元编辑 -->' },
  { id: 'dialogs-access-management', domain: 'system-admin', path: 'fragments/39-dialogs-access-management.html', anchor: '<!-- 用户模态框 -->' },
  { id: 'dialog-hotel', domain: 'hotel-admin', path: 'fragments/40-dialog-hotel.html', anchor: '<!-- 酒店模态框 -->' },
  { id: 'dialog-online-data-edit', domain: 'ota-data', path: 'fragments/41-dialog-online-data-edit.html', anchor: '<!-- 线上数据编辑模态框 -->' },
  { id: 'dialog-ai-model-config', domain: 'ai-governance', path: 'fragments/42-dialog-ai-model-config.html', anchor: '<!-- AI模型配置模态框 -->' },
  { id: 'dialogs-system-config', domain: 'system-admin', path: 'fragments/43-dialogs-system-config.html', anchor: '<!-- 系统配置模态框 -->' },
  { id: 'dialog-operation-log-detail', domain: 'system-admin', path: 'fragments/44-dialog-operation-log-detail.html', anchor: '<!-- 日志详情模态框 -->' },
  { id: 'dialogs-data-config', domain: 'system-admin', path: 'fragments/45-dialogs-data-config.html', anchor: '<!-- 数据配置模态框 -->' },
  { id: 'global-toast', domain: 'shell', path: 'fragments/46-global-toast.html', anchor: '<!-- Toast 提示 -->' },
].map((fragment) => Object.freeze(fragment)));

export const FRONTEND_TEMPLATE_MANIFEST_RELATIVE_PATH = 'resources/frontend/templates/manifest.json';

const templatesRoot = (repoRoot) => path.resolve(repoRoot, 'resources/frontend/templates');
const manifestPath = (repoRoot) => path.resolve(repoRoot, FRONTEND_TEMPLATE_MANIFEST_RELATIVE_PATH);

function resolveFragmentPath(repoRoot, relativePath) {
  if (typeof relativePath !== 'string' || !relativePath.trim() || path.isAbsolute(relativePath)) {
    throw new Error(`Invalid frontend template fragment path: ${String(relativePath)}`);
  }
  if (relativePath.includes('\\') || path.posix.normalize(relativePath) !== relativePath) {
    throw new Error(`Frontend template fragment path must already be normalized: ${relativePath}`);
  }
  const root = templatesRoot(repoRoot);
  const resolved = path.resolve(root, relativePath);
  if (resolved === root || !resolved.startsWith(`${root}${path.sep}`)) {
    throw new Error(`Frontend template fragment escapes the templates root: ${relativePath}`);
  }
  return resolved;
}

export function loadFrontendTemplateManifest(repoRoot) {
  const file = manifestPath(repoRoot);
  if (!fs.existsSync(file)) throw new Error(`Frontend template fragment manifest is missing: ${file}`);

  let manifest;
  try {
    manifest = JSON.parse(fs.readFileSync(file, 'utf8'));
  } catch (error) {
    throw new Error(`Frontend template fragment manifest is invalid JSON: ${error.message}`);
  }
  if (manifest?.schema_version !== 1 || !Array.isArray(manifest.fragments) || !manifest.fragments.length) {
    throw new Error('Frontend template fragment manifest must use schema_version 1 with a non-empty fragments array.');
  }
  if (manifest.source_snapshot !== 'resources/frontend/app-template.html'
    || !/^[a-f0-9]{64}$/.test(String(manifest.source_snapshot_sha256 || ''))
    || !Number.isSafeInteger(manifest.source_snapshot_bytes)
    || manifest.source_snapshot_bytes < 1) {
    throw new Error('Frontend template fragment manifest must pin the compatibility snapshot path, SHA-256, and byte length.');
  }

  const ids = new Set();
  const paths = new Set();
  for (const fragment of manifest.fragments) {
    if (typeof fragment?.id !== 'string' || !/^[a-z0-9-]+$/.test(fragment.id)) {
      throw new Error(`Invalid frontend template fragment id: ${String(fragment?.id)}`);
    }
    if (ids.has(fragment.id)) throw new Error(`Duplicate frontend template fragment id: ${fragment.id}`);
    ids.add(fragment.id);
    const resolvedPath = resolveFragmentPath(repoRoot, fragment.path);
    if (paths.has(resolvedPath)) throw new Error(`Duplicate frontend template fragment path: ${fragment.path}`);
    paths.add(resolvedPath);
  }
  return manifest;
}

export function loadFrontendTemplateSource(repoRoot) {
  const manifest = loadFrontendTemplateManifest(repoRoot);
  const fragments = manifest.fragments.map((fragment) => {
    const absolutePath = resolveFragmentPath(repoRoot, fragment.path);
    if (!fs.existsSync(absolutePath)) {
      throw new Error(`Frontend template fragment is missing: ${fragment.path}`);
    }
    const buffer = fs.readFileSync(absolutePath);
    if (!buffer.length) throw new Error(`Frontend template fragment is empty: ${fragment.path}`);
    return { ...fragment, absolutePath, buffer, source: buffer.toString('utf8') };
  });
  const templateBuffer = Buffer.concat(fragments.map((fragment) => fragment.buffer));
  return {
    manifest,
    manifestPath: manifestPath(repoRoot),
    fragments,
    templateBuffer,
    template: templateBuffer.toString('utf8'),
  };
}
