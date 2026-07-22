import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import test from 'node:test';
import { chromium } from 'playwright';

const delay = ms => new Promise(resolve => setTimeout(resolve, ms));

const listen = server => new Promise((resolve, reject) => {
  server.once('error', reject);
  server.listen(0, '127.0.0.1', () => {
    server.off('error', reject);
    resolve(server.address());
  });
});

const close = server => new Promise(resolve => server.close(resolve));

test('slow authenticated assets remain interactive while browser password storage is hung', async () => {
  const bootstrap = fs.readFileSync('public/app-bootstrap.js', 'utf8');
  const index = `<!doctype html>
    <html><head><meta charset="utf-8"><title>Login handoff test</title></head>
    <body>
      <div id="app"></div>
      <script id="suxi-authenticated-assets" type="application/json">
        ["vue.runtime.global.prod.js", "app-main.min.js"]
      </script>
      <script src="/app-bootstrap.js"></script>
    </body></html>`;

  const server = http.createServer(async (request, response) => {
    const pathname = new URL(request.url || '/', 'http://127.0.0.1').pathname;
    if (pathname === '/') {
      response.writeHead(200, { 'content-type': 'text/html; charset=utf-8' });
      response.end(index);
      return;
    }
    if (pathname === '/app-bootstrap.js') {
      response.writeHead(200, { 'content-type': 'text/javascript; charset=utf-8' });
      response.end(bootstrap);
      return;
    }
    if (pathname === '/api/health') {
      response.writeHead(200, { 'content-type': 'application/json' });
      response.end('{"status":"ok"}');
      return;
    }
    if (pathname === '/api/auth/login') {
      response.writeHead(200, { 'content-type': 'application/json' });
      response.end(JSON.stringify({
        code: 200,
        data: {
          token: 'slow-network-test-token',
          user: { id: 13, username: 'VIP013', realname: 'VIP013' },
        },
      }));
      return;
    }
    if (pathname === '/vue.runtime.global.prod.js') {
      await delay(120);
      response.writeHead(200, { 'content-type': 'text/javascript; charset=utf-8' });
      response.end('window.Vue = {};');
      return;
    }
    if (pathname === '/app-main.min.js') {
      await delay(180);
      response.writeHead(200, { 'content-type': 'text/javascript; charset=utf-8' });
      response.end("document.getElementById('app').innerHTML = '<main data-testid=\"mock-home\"><button type=\"button\">首页可操作</button></main>'; ");
      return;
    }
    response.writeHead(404);
    response.end('not found');
  });

  const address = await listen(server);
  let browser = null;
  try {
    browser = await chromium.launch({ headless: true });
    const page = await browser.newPage();
    await page.addInitScript(() => {
      window.__credentialStoreCalls = 0;
      window.PasswordCredential = class PasswordCredential {
        constructor(data) {
          Object.assign(this, data);
        }
      };
      Object.defineProperty(navigator, 'credentials', {
        configurable: true,
        value: {
          store: () => {
            window.__credentialStoreCalls += 1;
            return new Promise(() => {});
          },
        },
      });
    });

    await page.goto(`http://127.0.0.1:${address.port}/`);
    await page.locator('#login-username').fill('VIP013');
    await page.locator('#login-password').fill('test-password');
    await page.locator('#public-login-remember').check();
    await page.locator('#public-login-submit').click();
    await page.locator('[data-testid="mock-home"] button').waitFor({ state: 'visible' });
    await page.waitForFunction(
      () => window.SUXI_LOGIN_HANDOFF_METRICS?.status === 'interactive',
      undefined,
      { timeout: 3000 },
    );

    const result = await page.evaluate(() => ({
      metrics: window.SUXI_LOGIN_HANDOFF_METRICS,
      credentialStoreCalls: window.__credentialStoreCalls,
    }));
    assert.equal(result.credentialStoreCalls, 1);
    assert.equal(result.metrics?.status, 'interactive');
    assert.equal(result.metrics?.source, 'public-login');
    assert(result.metrics?.auth_to_interactive_ms >= 220, 'metric must include throttled authenticated assets');
    assert(result.metrics?.auth_to_interactive_ms < 1200, 'hung password storage must not hold the 1.5s login handoff');
  } finally {
    try {
      if (browser) {
        await browser.close();
      }
    } finally {
      await close(server);
    }
  }
});
