import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import {
  extractAuthenticatedAssetReferences,
  stripFrontendAssetQuery,
} from '../../scripts/lib/frontend_authenticated_assets.mjs';

const index = fs.readFileSync('public/index.html', 'utf8');
const bootstrap = fs.readFileSync('public/app-bootstrap.js', 'utf8');
const style = fs.readFileSync('public/style.css', 'utf8');

test('public login shell defers the authenticated application asset chain', () => {
  const references = extractAuthenticatedAssetReferences(index);
  const assets = references.map(stripFrontendAssetQuery);
  assert.equal(assets[0], 'vue.runtime.global.prod.js');
  assert.equal(assets.at(-2), 'app-render.min.js');
  assert.equal(assets.at(-1), 'app-main.min.js');
  assert(assets.includes('ctrip-static.js'));
  assert(assets.includes('meituan-static.js'));
  assert(assets.includes('data-health-static.js'));
  assert.match(index, /<script defer src="app-bootstrap\.js\?v=[^"]+"[^>]*><\/script>/);
  assert.doesNotMatch(index, /<script defer src="(?:vue\.runtime|ctrip-static|meituan-static|data-health-static|app-render|min\.js|app-main)/);
});

test('login bootstrap preserves the existing auth contract without persisting passwords', () => {
  assert.match(bootstrap, /fetchJson\('\/api\/auth\/login'/);
  assert.match(bootstrap, /sessionStorage\.setItem\(AUTH_TOKEN_KEY/);
  assert.match(bootstrap, /localStorage\.removeItem\(LEGACY_PASSWORD_KEY\)/);
  assert.match(bootstrap, /remembered_username/);
  assert.doesNotMatch(bootstrap, /setItem\([^\n]*password/i);
  assert.match(bootstrap, /await loadAuthenticatedApp\(\)/);
});

test('authenticated startup downloads independent helpers in parallel behind explicit runtime and entry barriers', () => {
  assert.match(bootstrap, /assetBaseName\(src\) === 'vue\.runtime\.global\.prod\.js'/);
  assert.match(bootstrap, /assetBaseName\(src\) === 'app-main\.min\.js'/);
  assert.match(bootstrap, /await loadScript\(runtime\);/);
  assert.match(bootstrap, /await Promise\.all\(prerequisites\.map\(\(src\) => loadScript\(src\)\)\);/);
  assert.match(bootstrap, /await loadScript\(entry\);/);
  assert.doesNotMatch(bootstrap, /for \(const src of assets\)/);
});

test('public login feedback, support dialog, and hidden states remain accessible', () => {
  assert.match(bootstrap, /role="alert" aria-live="assertive" aria-atomic="true" hidden/);
  assert.match(bootstrap, /aria-describedby="public-login-error public-login-caps-lock"/);
  assert.match(bootstrap, /aria-labelledby="public-login-support-title" aria-describedby="public-login-support-description"/);
  assert.match(bootstrap, /登录请求超时，请检查网络后重试/);
  assert.match(bootstrap, /开通账号或处理登录问题/);
  assert.doesNotMatch(bootstrap, /申请账号或处理登录问题/);
  assert.match(
    style,
    /\.login-caps-lock\[hidden\],[\s\S]*\.login-error\[hidden\],[\s\S]*\.login-support-backdrop\[hidden\][\s\S]*display:\s*none\s*!important/,
  );
});

test('public login reconciles browser autofill before deciding the submit state', () => {
  assert.match(bootstrap, /LOGIN_AUTOFILL_SYNC_DELAYS = Object\.freeze\(\[0, 100, 300, 800, 1600, 3000, 5000, 8000, 12000\]\)/);
  assert.match(bootstrap, /const scheduleLoginAutofillSync = \(\) =>/);
  assert.match(bootstrap, /input\?\.matches\?\.\(':-webkit-autofill'\)/);
  assert.match(bootstrap, /!password\.value && !hasBrowserAutofill\(password\)/);
  assert.match(bootstrap, /请先点击密码框确认浏览器保存的密码，再登录/);
  assert.match(bootstrap, /window\.addEventListener\('pageshow', scheduleLoginAutofillSync\)/);
  assert.match(bootstrap, /window\.addEventListener\('focus', scheduleLoginAutofillSync\)/);
  assert.match(bootstrap, /form\.addEventListener\('focusin', scheduleLoginAutofillSync\)/);
  assert.match(bootstrap, /password\.addEventListener\('change', handleInput\)/);
});

test('dual OTA loss-chain grid follows the actual node count', () => {
  assert.match(
    style,
    /grid-template-columns:\s*repeat\(var\(--dual-ota-loss-columns,\s*5\),\s*minmax\(0,\s*1fr\)\)/,
  );
});
