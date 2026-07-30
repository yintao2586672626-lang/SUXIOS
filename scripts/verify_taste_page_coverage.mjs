import fs from 'node:fs';

const html = [
  fs.readFileSync('public/index.html', 'utf8'),
  fs.readFileSync('resources/frontend/app-template.html', 'utf8'),
  fs.readFileSync('public/app-main.js', 'utf8'),
].join('\n');
const css = fs.readFileSync('public/style.css', 'utf8');

const failures = [];
const tasteMarker = 'SUXIOS logged-in app taste polish';

function addMatches(set, pattern) {
  let match;
  while ((match = pattern.exec(html))) {
    set.add(match[1]);
  }
}

const pageKeys = new Set();
addMatches(pageKeys, /currentPage\s*===\s*['"]([^'"]+)['"]/g);
addMatches(pageKeys, /currentPage\s*=\s*['"]([^'"]+)['"]/g);
addMatches(pageKeys, /currentPage\.value\s*=\s*['"]([^'"]+)['"]/g);

for (const match of html.matchAll(/\[([^\]]*?)\]\.includes\(currentPage\)/g)) {
  for (const item of match[1].matchAll(/['"]([^'"]+)['"]/g)) {
    pageKeys.add(item[1]);
  }
}

const menuGroupOnlyKeys = new Set([
  'ai-construction',
  'ai-expansion',
  'ai-opening',
  'ai-ops',
  'ai-transfer',
]);

const requiredPageKeys = [...pageKeys]
  .filter((key) => !menuGroupOnlyKeys.has(key))
  .sort();

if (!html.includes('class="suxi-app-shell')) {
  failures.push('logged-in app shell is missing .suxi-app-shell');
}

if (!html.includes(':data-current-page="currentPage"')) {
  failures.push('main app surface must expose :data-current-page="currentPage"');
}

if (!html.includes('class="suxi-page-body')) {
  failures.push('page body wrapper is missing .suxi-page-body');
}

if (!css.includes(tasteMarker)) {
  failures.push('public/style.css is missing the taste polish marker');
}

const tasteSection = css.slice(css.indexOf(tasteMarker));

if (tasteSection.includes('.login-bg')) {
  failures.push('taste polish section must not target the login page .login-bg');
}

for (const required of [
  '--sx-page-accent',
  '--sx-page-glow-rgb',
  '.suxi-page-body::before',
  ':focus-visible',
  '@media (prefers-reduced-motion: reduce)',
]) {
  if (!tasteSection.includes(required)) {
    failures.push(`taste polish section missing required rule: ${required}`);
  }
}

for (const pageKey of requiredPageKeys) {
  const literal = `main[data-current-page="${pageKey}"]`;
  if (!tasteSection.includes(literal)) {
    failures.push(`page key lacks explicit taste coverage: ${pageKey}`);
  }
}

if (failures.length > 0) {
  console.error(failures.join('\n'));
  process.exit(1);
}

console.log(`Taste page coverage verification passed (${requiredPageKeys.length} page keys).`);
