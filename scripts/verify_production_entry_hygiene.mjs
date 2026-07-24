import fs from 'node:fs';

const gitignorePath = '.gitignore';

const productionEntryFiles = [
  'route/app.php',
  'app/command/AutoFetchOnlineData.php',
  'app/controller/admin/CompetitorWechatRobotController.php',
  'scripts/auto_fetch_online_data.php',
];

const route = fs.readFileSync('route/app.php', 'utf8');
const publicIndex = fs.readFileSync('public/index.html', 'utf8');
const gitignore = fs.readFileSync(gitignorePath, 'utf8');

const failures = [];

const forbiddenRoutePatterns = [
  /Route::(?:any|get|post|rule)\(\s*['"]api\/test-/,
  /Route::(?:any|get|post|rule)\(\s*['"]api\/db-test['"]/,
  /Route::(?:any|get|post|rule)\(\s*['"]api\/online-data\/clear-cache['"]/,
  /verify_peer['"]?\s*=>\s*false/,
  /verify_peer_name['"]?\s*=>\s*false/,
];

for (const pattern of forbiddenRoutePatterns) {
  if (pattern.test(route)) {
    failures.push(`Forbidden production route/debug pattern found: ${pattern}`);
  }
}

for (const file of productionEntryFiles) {
  const content = fs.readFileSync(file, 'utf8');
  if (/verify_peer['"]?\s*=>\s*false/.test(content) || /verify_peer_name['"]?\s*=>\s*false/.test(content)) {
    failures.push(`SSL peer verification is disabled in production entry file: ${file}`);
  }
}

function hasActiveRoute(pattern) {
  return route
    .split(/\r?\n/)
    .map((line) => line.replace(/\/\/.*$/, '').trim())
    .some((line) => pattern.test(line));
}

const requiredActiveRoutes = [
  {
    name: 'receive-cookies route',
    pattern: /Route::rule\(\s*['"]api\/online-data\/receive-cookies['"]/,
  },
  {
    name: 'cron-trigger route',
    pattern: /Route::get\(\s*['"]api\/online-data\/cron-trigger['"]/,
  },
  {
    name: 'feasibility report generate route',
    pattern: /Route::post\(\s*['"]\/feasibility-report\/generate['"]/,
  },
];

for (const routeCheck of requiredActiveRoutes) {
  if (!hasActiveRoute(routeCheck.pattern)) {
    failures.push(`Required active route missing or commented out: ${routeCheck.name}`);
  }
}

if (!/<link\s+rel=["']stylesheet["']\s+href=["']login-critical\.css(?:\?[^"']*)?["']/.test(publicIndex)) {
  failures.push('public/index.html must load public/login-critical.css for the public shell.');
}
if (!/"src"\s*:\s*"style\.css\?[^"]+"\s*,\s*"type"\s*:\s*"style"/.test(publicIndex)) {
  failures.push('public/index.html must defer public/style.css through the authenticated asset manifest.');
}

const inlineStyleBlocks = [...publicIndex.matchAll(/<style\b[^>]*>([\s\S]*?)<\/style>/gi)];
const largeInlineStyleBlock = inlineStyleBlocks.find((match) => match[1].split(/\r?\n/).length > 20);
if (largeInlineStyleBlock) {
  failures.push('public/index.html still contains a large inline <style> block; move page CSS into public/style.css.');
}

const requiredIgnores = [
  '/HOTEL/',
  'public/assets/',
  'public/app-main.*',
  'public/app-styles.css',
  'public/app.js',
  'public/components.css',
  'public/enhanced-components.css',
  'public/tailwind-custom.css',
  'public/tailwind.min.css.bak',
  'public/nginx.htaccess',
];

for (const ignoreEntry of requiredIgnores) {
  const pattern = new RegExp(`(^|\\n)${ignoreEntry.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}(\\r?\\n|$)`);
  if (!pattern.test(gitignore)) {
    failures.push(`Missing .gitignore entry: ${ignoreEntry}`);
  }
}

if (failures.length > 0) {
  console.error(failures.join('\n'));
  process.exit(1);
}

console.log('Production entry hygiene verification passed.');
