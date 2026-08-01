import assert from 'node:assert/strict';
import http from 'node:http';
import test from 'node:test';
import { createLocalOriginServer } from '../../scripts/local_origin_server.mjs';

function listen(server) {
  return new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(0, '127.0.0.1', () => resolve(server.address().port));
  });
}

function close(server) {
  return new Promise((resolve) => server.close(resolve));
}

function worker({ healthy, name, failDynamic = false }) {
  let dynamicRequests = 0;
  const server = http.createServer((request, response) => {
    if (request.url === '/api/health') {
      response.writeHead(healthy ? 200 : 503, { 'Content-Type': 'application/json' });
      response.end(JSON.stringify({ status: healthy ? 'ok' : 'failed' }));
      return;
    }
    dynamicRequests += 1;
    if (failDynamic) {
      response.destroy();
      return;
    }
    response.writeHead(200, { 'Content-Type': 'application/json' });
    response.end(JSON.stringify({ worker: name }));
  });
  return { server, dynamicRequests: () => dynamicRequests };
}

test('local origin dispatches only to healthy PHP workers and reports an empty pool', async () => {
  const unavailable = worker({ healthy: false, name: 'unavailable' });
  const healthy = worker({ healthy: true, name: 'healthy' });
  const unavailablePort = await listen(unavailable.server);
  const healthyPort = await listen(healthy.server);
  const origin = createLocalOriginServer({
    backendUrls: [
      `http://127.0.0.1:${unavailablePort}`,
      `http://127.0.0.1:${healthyPort}`,
    ],
    healthCheckIntervalMs: 50,
    healthCheckTimeoutMs: 100,
  });
  const originPort = await listen(origin);

  try {
    const routed = await fetch(`http://127.0.0.1:${originPort}/api/probe`);
    assert.equal(routed.status, 200);
    assert.equal(routed.headers.get('x-suxios-backend-pool-size'), '2');
    assert.deepEqual(await routed.json(), { worker: 'healthy' });
    assert.equal(unavailable.dynamicRequests(), 0);

    await close(healthy.server);
    await new Promise((resolve) => setTimeout(resolve, 100));
    const emptyPool = await fetch(`http://127.0.0.1:${originPort}/api/probe`);
    assert.equal(emptyPool.status, 503);
    assert.deepEqual(await emptyPool.json(), {
      code: 503,
      message: '没有可用的本机 PHP worker',
    });
  } finally {
    await close(origin);
    await close(unavailable.server);
    if (healthy.server.listening) await close(healthy.server);
  }
});

test('local origin retries one bodyless GET on a different healthy worker', async () => {
  const disconnecting = worker({ healthy: true, name: 'disconnecting', failDynamic: true });
  const fallback = worker({ healthy: true, name: 'fallback' });
  const disconnectingPort = await listen(disconnecting.server);
  const fallbackPort = await listen(fallback.server);
  const origin = createLocalOriginServer({
    backendUrls: [
      `http://127.0.0.1:${disconnectingPort}`,
      `http://127.0.0.1:${fallbackPort}`,
    ],
    healthCheckIntervalMs: 10_000,
    healthCheckTimeoutMs: 100,
  });
  const originPort = await listen(origin);

  try {
    const response = await fetch(`http://127.0.0.1:${originPort}/api/read-probe`);
    assert.equal(response.status, 200);
    assert.deepEqual(await response.json(), { worker: 'fallback' });
    assert.equal(disconnecting.dynamicRequests(), 1);
    assert.equal(fallback.dynamicRequests(), 1);
  } finally {
    await close(origin);
    await close(disconnecting.server);
    await close(fallback.server);
  }
});

test('local origin never retries a POST after the selected worker disconnects', async () => {
  const disconnecting = worker({ healthy: true, name: 'disconnecting', failDynamic: true });
  const fallback = worker({ healthy: true, name: 'fallback' });
  const disconnectingPort = await listen(disconnecting.server);
  const fallbackPort = await listen(fallback.server);
  const origin = createLocalOriginServer({
    backendUrls: [
      `http://127.0.0.1:${disconnectingPort}`,
      `http://127.0.0.1:${fallbackPort}`,
    ],
    healthCheckIntervalMs: 10_000,
    healthCheckTimeoutMs: 100,
  });
  const originPort = await listen(origin);

  try {
    const response = await fetch(`http://127.0.0.1:${originPort}/api/write-probe`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ write: true }),
    });
    assert.equal(response.status, 502);
    assert.deepEqual(await response.json(), {
      code: 502,
      message: '所选本机 PHP worker 已不可用',
    });
    assert.equal(disconnecting.dynamicRequests(), 1);
    assert.equal(fallback.dynamicRequests(), 0);
  } finally {
    await close(origin);
    await close(disconnecting.server);
    await close(fallback.server);
  }
});
