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

function worker({ healthy, name, failDynamic = false, dynamicDelayMs = 0 }) {
  let healthResponse = healthy;
  let healthRequests = 0;
  let dynamicRequests = 0;
  const server = http.createServer((request, response) => {
    if (request.url === '/api/health') {
      healthRequests += 1;
      if (healthResponse === 'timeout') return;
      response.writeHead(healthResponse ? 200 : 503, { 'Content-Type': 'application/json' });
      response.end(JSON.stringify({ status: healthResponse ? 'ok' : 'failed' }));
      return;
    }
    dynamicRequests += 1;
    const finishDynamicRequest = () => {
      if (failDynamic) {
        response.destroy();
        return;
      }
      response.writeHead(200, { 'Content-Type': 'application/json' });
      response.end(JSON.stringify({ worker: name }));
    };
    if (dynamicDelayMs > 0) {
      setTimeout(finishDynamicRequest, dynamicDelayMs);
    } else {
      finishDynamicRequest();
    }
  });
  return {
    server,
    dynamicRequests: () => dynamicRequests,
    healthRequests: () => healthRequests,
    setHealthResponse: (value) => {
      healthResponse = value;
    },
  };
}

async function waitFor(predicate, message, timeoutMs = 2_000) {
  const deadline = Date.now() + timeoutMs;
  while (!predicate()) {
    if (Date.now() >= deadline) throw new Error(message);
    await new Promise((resolve) => setTimeout(resolve, 10));
  }
}

test('local origin retains previously healthy workers for one transient pool-wide health failure', async () => {
  const first = worker({ healthy: true, name: 'first' });
  const second = worker({ healthy: true, name: 'second' });
  const firstPort = await listen(first.server);
  const secondPort = await listen(second.server);
  const origin = createLocalOriginServer({
    backendUrls: [
      `http://127.0.0.1:${firstPort}`,
      `http://127.0.0.1:${secondPort}`,
    ],
    healthCheckIntervalMs: 500,
    healthCheckTimeoutMs: 100,
  });
  const originPort = await listen(origin);

  try {
    await waitFor(
      () => first.healthRequests() >= 1 && second.healthRequests() >= 1,
      'workers did not complete their initial healthy probes',
    );
    first.setHealthResponse('timeout');
    second.setHealthResponse('timeout');
    await waitFor(
      () => first.healthRequests() >= 2 && second.healthRequests() >= 2,
      'workers did not complete the transient failed probes',
    );
    await new Promise((resolve) => setTimeout(resolve, 120));

    const transientFailure = await fetch(`http://127.0.0.1:${originPort}/api/transient-probe`);
    assert.equal(transientFailure.status, 200);

    await waitFor(
      () => first.healthRequests() >= 3 && second.healthRequests() >= 3,
      'workers did not complete the sustained failed probes',
    );
    await new Promise((resolve) => setTimeout(resolve, 120));

    const sustainedFailure = await fetch(`http://127.0.0.1:${originPort}/api/sustained-probe`);
    assert.equal(sustainedFailure.status, 503);
  } finally {
    await close(origin);
    await close(first.server);
    await close(second.server);
  }
});

test('local origin does not evict a verified worker while it is serving a slow request', async () => {
  const busy = worker({ healthy: true, name: 'busy', dynamicDelayMs: 280 });
  const busyPort = await listen(busy.server);
  const origin = createLocalOriginServer({
    backendUrl: `http://127.0.0.1:${busyPort}`,
    healthCheckIntervalMs: 25,
    healthCheckTimeoutMs: 15,
  });
  const originPort = await listen(origin);

  try {
    await waitFor(() => busy.healthRequests() >= 1, 'worker did not complete its initial healthy probe');
    busy.setHealthResponse('timeout');
    const slowRequest = fetch(`http://127.0.0.1:${originPort}/api/slow`);
    await waitFor(() => busy.dynamicRequests() >= 1, 'slow request did not reach the worker');
    const completedHealthyProbes = busy.healthRequests();
    await waitFor(
      () => busy.healthRequests() >= completedHealthyProbes + 2,
      'busy worker did not receive sustained health probes',
    );
    await new Promise((resolve) => setTimeout(resolve, 40));

    const duringBusy = await fetch(`http://127.0.0.1:${originPort}/api/during-busy`);
    assert.equal(duringBusy.status, 200);
    assert.deepEqual(await duringBusy.json(), { worker: 'busy' });
    assert.equal((await slowRequest).status, 200);
  } finally {
    await close(origin);
    await close(busy.server);
  }
});

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

    const completedHealthyProbes = healthy.healthRequests();
    healthy.setHealthResponse(false);
    await waitFor(
      () => healthy.healthRequests() >= completedHealthyProbes + 2,
      'healthy worker did not complete the sustained failed probes',
    );
    await new Promise((resolve) => setTimeout(resolve, 20));
    const emptyPool = await fetch(`http://127.0.0.1:${originPort}/api/probe`);
    assert.equal(emptyPool.status, 503);
    assert.deepEqual(await emptyPool.json(), {
      code: 503,
      message: '没有可用的本机 PHP worker',
    });
  } finally {
    await close(origin);
    await close(unavailable.server);
    await close(healthy.server);
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
