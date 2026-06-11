import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const indexPath = path.join(repoRoot, 'public/index.html');
const publicRouterPath = path.join(repoRoot, 'public/router.php');
const stylePath = path.join(repoRoot, 'public/style.css');
const loginBgPngPath = path.join(repoRoot, 'public/images/login-hotel-lobby-bg.png');
const loginBgWebpPath = path.join(repoRoot, 'public/images/login-hotel-lobby-bg.webp');
const loginBgAvifPath = path.join(repoRoot, 'public/images/login-hotel-lobby-bg.avif');
const failures = [];

const lineNumberForOffset = (content, offset) => content.slice(0, offset).split(/\r?\n/).length;

const openTagStackBefore = (content, endOffset) => {
  const voidTags = new Set([
    'area',
    'base',
    'br',
    'col',
    'embed',
    'hr',
    'img',
    'input',
    'link',
    'meta',
    'param',
    'source',
    'track',
    'wbr',
  ]);
  const stack = [];
  const tagPattern = /<!--[\s\S]*?-->|<![^>]*>|<\/?([a-zA-Z][\w:-]*)([^>]*)>/g;
  let match;

  while ((match = tagPattern.exec(content)) && match.index < endOffset) {
    const raw = match[0];
    if (raw.startsWith('<!--') || raw.startsWith('<!')) continue;

    const tag = match[1].toLowerCase();
    if (raw.startsWith('</')) {
      let matchingIndex = -1;
      for (let i = stack.length - 1; i >= 0; i -= 1) {
        if (stack[i].tag === tag) {
          matchingIndex = i;
          break;
        }
      }
      if (matchingIndex >= 0) stack.splice(matchingIndex);
      continue;
    }

    if (!voidTags.has(tag) && !/\/\s*>$/.test(raw)) {
      stack.push({ tag, raw });
    }
  }

  return stack;
};

const hasOpenVueRoot = (stack) => stack.some((entry) => entry.tag === 'div' && /\bid\s*=\s*["']app["']/.test(entry.raw));

if (!fs.existsSync(indexPath)) {
  failures.push('public/index.html is missing.');
} else {
  const stat = fs.statSync(indexPath);
  const content = fs.readFileSync(indexPath, 'utf8');

  if (stat.size < 500_000) {
    failures.push(`public/index.html is too small (${stat.size} bytes). It may have been overwritten by a frontend build.`);
  }

  const requiredMarkers = [
    { name: 'Vue mount root', pattern: /id=["']app["']/ },
    { name: 'local Vue runtime', pattern: /vue\.global\.prod\.js/ },
    { name: 'local Tailwind stylesheet', pattern: /tailwind\.min\.css/ },
    { name: 'application stylesheet', pattern: /style\.css/ },
    { name: 'Vue app bootstrap', pattern: /createApp|Vue\.createApp/ },
  ];

  for (const marker of requiredMarkers) {
    if (!marker.pattern.test(content)) {
      failures.push(`public/index.html missing marker: ${marker.name}`);
    }
  }

  if (/\/assets\/index-[A-Za-z0-9_-]+\.(?:js|css)/.test(content)) {
    failures.push('public/index.html references Vite hashed assets; do not build Vite into HOTEL/public.');
  }

  const tailwindOffset = content.indexOf('href="tailwind.min.css"');
  const vueScriptOffset = content.indexOf('src="vue.global.prod.js"');
  if (tailwindOffset < 0 || vueScriptOffset < 0 || tailwindOffset > vueScriptOffset) {
    failures.push('public/index.html must discover core stylesheets before synchronous Vue/static scripts.');
  }
  const loginBgPreloadOffset = content.indexOf('href="images/login-hotel-lobby-bg.avif"');
  if (!/<link\s+rel=["']preload["']\s+href=["']images\/login-hotel-lobby-bg\.avif["']\s+as=["']image["']\s+type=["']image\/avif["']\s+fetchpriority=["']high["']/.test(content)) {
    failures.push('public/index.html must preload the optimized AVIF login background with high fetch priority.');
  }
  if (loginBgPreloadOffset < 0 || tailwindOffset < 0 || loginBgPreloadOffset > tailwindOffset) {
    failures.push('public/index.html must discover the AVIF login background preload before core stylesheets.');
  }

  if (/vue-router\.global\.prod\.js/.test(content)) {
    failures.push('public/index.html must not eagerly load vue-router.global.prod.js; the current shell uses currentPage state navigation.');
  }
  if (fs.existsSync(path.join(repoRoot, 'public/vue-router.global.prod.js'))) {
    failures.push('public/vue-router.global.prod.js is unused by the current shell and must not remain as a dead public asset.');
  }

  if (/<script\s+src=["']hotel-image-optimizer-static\.js["']/.test(content)) {
    failures.push('public/index.html must lazy-load hotel-image-optimizer-static.js; it is not required for the initial shell.');
  }
  if (!/const\s+hotelImageOptimizerStaticScript\s*=\s*["']hotel-image-optimizer-static\.js["']/.test(content) || !/const\s+loadHotelImageOptimizerStatic\s*=\s*\(\)\s*=>/.test(content)) {
    failures.push('public/index.html must keep an explicit lazy loader for hotel-image-optimizer-static.js.');
  }
  if (!/newPage === ['"]agent-center['"] \|\| newPage === ['"]hotel-image-optimizer['"]/.test(content) || !/ensureHotelImageOptimizerReady\(\)/.test(content)) {
    failures.push('public/index.html must load hotel image optimizer static data only when agent-center or hotel-image-optimizer is opened.');
  }
  if (/<script\s+src=["']revenue-research-static\.js["']/.test(content)) {
    failures.push('public/index.html must lazy-load revenue-research-static.js; it is only required by revenue-research-center.');
  }
  if (!/const\s+revenueResearchStaticScript\s*=\s*["']revenue-research-static\.js["']/.test(content) || !/const\s+loadRevenueResearchStatic\s*=\s*\(\)\s*=>/.test(content)) {
    failures.push('public/index.html must keep an explicit lazy loader for revenue-research-static.js.');
  }
  if (!/newPage === ['"]revenue-research-center['"]/.test(content) || !/ensureRevenueResearchReady\(\)/.test(content)) {
    failures.push('public/index.html must load revenue research static data only when revenue-research-center is opened.');
  }
  if (/<script\s+src=["']expansion-static-options\.js["']/.test(content)) {
    failures.push('public/index.html must lazy-load expansion-static-options.js; the login and compass shell do not need investment expansion options.');
  }
  if (!/const\s+expansionStaticOptionsScript\s*=\s*["']expansion-static-options\.js["']/.test(content) || !/const\s+loadExpansionStaticOptions\s*=\s*\(\)\s*=>/.test(content)) {
    failures.push('public/index.html must keep an explicit lazy loader for expansion-static-options.js.');
  }
  if (!/ensureExpansionStaticReady\(\)/.test(content) || !/isExpansionStaticPage\(newPage\)/.test(content)) {
    failures.push('public/index.html must load expansion static data only when investment expansion pages are opened.');
  }
  if (/<script\s+src=["']simulation-static\.js["']/.test(content)) {
    failures.push('public/index.html must lazy-load simulation-static.js; the login and compass shell do not need simulation and transfer calculators.');
  }
  if (!/const\s+simulationStaticScript\s*=\s*["']simulation-static\.js["']/.test(content) || !/const\s+loadSimulationStatic\s*=\s*\(\)\s*=>/.test(content)) {
    failures.push('public/index.html must keep an explicit lazy loader for simulation-static.js.');
  }
  if (!/ensureSimulationStaticReady\(\)/.test(content) || !/isSimulationStaticPage\(newPage\)/.test(content)) {
    failures.push('public/index.html must load simulation static data only when simulation, feasibility, benchmark, collaboration, or transfer pages are opened.');
  }
  const simulationDetailLoader = content.slice(
    content.indexOf('const loadSimulationDetail = async'),
    content.indexOf('const reuseSimulationRecord = async')
  );
  if (!simulationDetailLoader.includes('await ensureSimulationStaticReady();')) {
    failures.push('public/index.html must load simulation static data before reusing simulation history input.');
  }
  const transferDetailLoader = content.slice(
    content.indexOf('const loadTransferDetail = async'),
    content.indexOf('const reuseTransferRecord = async')
  );
  if (!transferDetailLoader.includes('await ensureSimulationStaticReady();')) {
    failures.push('public/index.html must load simulation static data before reusing transfer history input.');
  }
  const strategyDetailLoader = content.slice(
    content.indexOf('const loadStrategyDetail = async'),
    content.indexOf('const reuseStrategyRecord = async')
  );
  if (!strategyDetailLoader.includes('await ensureExpansionStaticReady();')) {
    failures.push('public/index.html must load expansion static data before reusing strategy history input.');
  }
  if (/<script\s+src=["']operation-static\.js["']/.test(content)) {
    failures.push('public/index.html must lazy-load operation-static.js; it is only required by operation/opening/lifecycle pages.');
  }
  if (!/const\s+operationStaticScript\s*=\s*["']operation-static\.js["']/.test(content) || !/const\s+loadOperationStatic\s*=\s*\(\)\s*=>/.test(content)) {
    failures.push('public/index.html must keep an explicit lazy loader for operation-static.js.');
  }
  if (!content.includes('await ensureOperationStaticReady();')
    || !/newPage === ['"]lifecycle['"]/.test(content)
    || !/newPage === ['"]opening-overview['"] \|\| newPage === ['"]opening-checklist['"]/.test(content)
    || !/newPage === ['"]ops-source['"]/.test(content)
    || !/newPage === ['"]ops-analysis['"] \|\| newPage === ['"]ops-plan['"]/.test(content)
    || !/newPage === ['"]ops-insight['"]/.test(content)
    || !/newPage === ['"]ops-track['"]/.test(content)) {
    failures.push('public/index.html must load operation static data before operation, opening, and lifecycle page work.');
  }
  if (/<script\s+src=["']notification-static\.js["']/.test(content)) {
    failures.push('public/index.html must lazy-load notification-static.js; the login shell does not need notification rendering helpers.');
  }
  if (!/const\s+notificationStaticScript\s*=\s*["']notification-static\.js["']/.test(content) || !/const\s+loadNotificationStatic\s*=\s*\(\)\s*=>/.test(content)) {
    failures.push('public/index.html must keep an explicit lazy loader for notification-static.js.');
  }
  if (!/await ensureNotificationStaticReady\(\);/.test(content) || !/const\s+globalNotifications\s*=\s*computed\(\(\)\s*=>\s*\{[\s\S]*notificationStaticReady\.value/.test(content)) {
    failures.push('public/index.html must load notification static data before notification refresh and avoid building notifications before the helper is ready.');
  }
  if (/<script\s+src=["']testid-static\.js["']/.test(content)) {
    failures.push('public/index.html must lazy-load testid-static.js; the login shell only needs inline page/menu test id helpers.');
  }
  if (!/const\s+testIdStaticScript\s*=\s*["']testid-static\.js["']/.test(content) || !/const\s+loadTestIdStatic\s*=\s*\(\)\s*=>/.test(content)) {
    failures.push('public/index.html must keep an explicit lazy loader for testid-static.js.');
  }
  if (!/const\s+pageTestId\s*=\s*\(page\)\s*=>/.test(content)
    || !/const\s+menuTestId\s*=\s*\(item\)\s*=>/.test(content)
    || !/createPageTestIdController/.test(content)) {
    failures.push('public/index.html must keep page/menu test ids available before lazy-loading the page-control test id controller.');
  }

  if (!/<script\s+src=["']form-operation-support\.js["']\s+defer\s*><\/script>/.test(content)) {
    failures.push('public/index.html must defer form-operation-support.js because it self-initializes and is not a Vue setup dependency.');
  }

  const vueBoundaryMarkers = [
    { name: 'Ctrip Profile field modal', marker: 'data-testid="ctrip-profile-field-modal"' },
    { name: 'Ctrip Cookie editor modal', marker: 'v-if="showCtripCookieEditorModal"' },
    { name: 'Online data edit modal', marker: 'v-if="showOnlineDataEditModal"' },
    { name: 'Data config modal', marker: 'v-if="showDataConfigModal"' },
    { name: 'Toast container', marker: 'v-if="toast.show"' },
  ];

  for (const marker of vueBoundaryMarkers) {
    const offset = content.indexOf(marker.marker);
    if (offset < 0) {
      failures.push(`public/index.html missing Vue boundary marker: ${marker.name}.`);
      continue;
    }
    if (!hasOpenVueRoot(openTagStackBefore(content, offset))) {
      failures.push(
        `public/index.html Vue boundary broken before ${marker.name} at line ${lineNumberForOffset(content, offset)}. ` +
        'Global modals and toast must stay inside #app; check malformed <div>, <details>, <template>, or <teleport> closures.'
      );
    }
  }
}

if (!fs.existsSync(stylePath)) {
  failures.push('public/style.css is missing.');
} else {
  const styleSource = fs.readFileSync(stylePath, 'utf8');
  if (!fs.existsSync(loginBgPngPath)) {
    failures.push('public/images/login-hotel-lobby-bg.png fallback is missing.');
  }
  if (!fs.existsSync(loginBgWebpPath)) {
    failures.push('public/images/login-hotel-lobby-bg.webp optimized login background is missing.');
  }
  if (!fs.existsSync(loginBgAvifPath)) {
    failures.push('public/images/login-hotel-lobby-bg.avif optimized login background is missing.');
  }
  if (fs.existsSync(loginBgPngPath) && fs.existsSync(loginBgWebpPath) && fs.existsSync(loginBgAvifPath)) {
    const pngSize = fs.statSync(loginBgPngPath).size;
    const webpSize = fs.statSync(loginBgWebpPath).size;
    const avifSize = fs.statSync(loginBgAvifPath).size;
    if (webpSize >= pngSize * 0.25) {
      failures.push('public/images/login-hotel-lobby-bg.webp must remain a substantially smaller first-choice login background.');
    }
    if (avifSize >= webpSize * 0.75) {
      failures.push('public/images/login-hotel-lobby-bg.avif must remain smaller than the WebP login background.');
    }
  }
  const loginPngOffset = styleSource.indexOf('images/login-hotel-lobby-bg.png');
  const loginWebpOffset = styleSource.indexOf('images/login-hotel-lobby-bg.webp');
  const loginAvifOffset = styleSource.indexOf('images/login-hotel-lobby-bg.avif');
  if (loginPngOffset === -1 || loginWebpOffset === -1 || loginAvifOffset === -1) {
    failures.push('public/style.css must keep the original PNG declaration plus optimized AVIF and WebP login background declarations.');
  }
  if (loginPngOffset !== -1 && loginWebpOffset !== -1 && loginAvifOffset !== -1 && (loginAvifOffset < loginPngOffset || loginWebpOffset < loginAvifOffset)) {
    failures.push('public/style.css must declare login backgrounds in PNG legacy, AVIF first-choice, then WebP fallback order.');
  }
  if (!styleSource.includes('-webkit-image-set(') || !styleSource.includes('image-set(') || !styleSource.includes('type("image/avif")') || !styleSource.includes('type("image/webp")')) {
    failures.push('public/style.css must use image-set declarations for the optimized AVIF/WebP login background with PNG fallback.');
  }
}

if (!fs.existsSync(publicRouterPath)) {
  failures.push('public/router.php is missing.');
} else {
  const routerSource = fs.readFileSync(publicRouterPath, 'utf8');
  if (!routerSource.includes("'runtime' . DIRECTORY_SEPARATOR . 'static-gzip'")) {
    failures.push('public/router.php must cache gzip output under runtime/static-gzip to avoid repeated CPU compression on large local assets.');
  }
  if (!routerSource.includes("file_put_contents($gzipCacheFile, $encoded, LOCK_EX)")) {
    failures.push('public/router.php must persist gzip cache files atomically enough for local dev reloads.');
  }
  if (!routerSource.includes("header('Content-Length: ' . (int)filesize($gzipCacheFile))")) {
    failures.push('public/router.php must send Content-Length for cached gzip assets.');
  }
  if (!routerSource.includes("header('Content-Length: ' . strlen($encoded))")) {
    failures.push('public/router.php must send Content-Length for refreshed gzip assets.');
  }
  if (!/gzencode\(\(string\)file_get_contents\(\$staticFile\),\s*1\)/.test(routerSource)) {
    failures.push('public/router.php must use gzip level 1 when refreshing the static gzip cache.');
  }
}

if (failures.length > 0) {
  console.error(failures.join('\n'));
  process.exit(1);
}

console.log('Public entry guard passed.');
