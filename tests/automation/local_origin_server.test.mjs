import assert from 'node:assert/strict';
import fsSync from 'node:fs';
import fs from 'node:fs/promises';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { gunzipSync } from 'node:zlib';
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

function requestBuffer({ port, pathname, method = 'GET', headers = {} }) {
  return new Promise((resolve, reject) => {
    const request = http.request({
      host: '127.0.0.1',
      port,
      path: pathname,
      method,
      headers: { Connection: 'close', ...headers },
    }, (response) => {
      const chunks = [];
      response.on('data', (chunk) => chunks.push(chunk));
      response.on('end', () => resolve({
        status: response.statusCode,
        headers: response.headers,
        body: Buffer.concat(chunks),
      }));
    });
    request.once('error', reject);
    request.end();
  });
}

const waitForStreamClose = async (stream, timeoutMs = 1000) => {
  if (stream?.closed) return true;
  return new Promise((resolve) => {
    const timeout = setTimeout(() => resolve(false), timeoutMs);
    stream.once('close', () => {
      clearTimeout(timeout);
      resolve(true);
    });
  });
};

test('local origin serves static files concurrently and proxies dynamic requests', async () => {
  const temporaryRoot = await fs.mkdtemp(path.join(os.tmpdir(), 'suxios-local-origin-'));
  const publicRoot = path.join(temporaryRoot, 'public');
  await fs.mkdir(publicRoot);
  await fs.writeFile(path.join(publicRoot, 'index.html'), '<title>SUXIOS test</title>');
  await fs.writeFile(path.join(publicRoot, 'app.js'), 'window.SUXIOS_TEST = true;');
  await fs.writeFile(path.join(publicRoot, 'large.js'), Buffer.alloc(8 * 1024 * 1024, 65));

  const backend = http.createServer((request, response) => {
    const chunks = [];
    request.on('data', (chunk) => chunks.push(chunk));
    request.on('end', () => {
      response.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
      response.end(JSON.stringify({
        method: request.method,
        path: request.url,
        body: Buffer.concat(chunks).toString('utf8'),
      }));
    });
  });
  const backendPort = await listen(backend);
  const origin = createLocalOriginServer({
    publicRoot,
    backendUrl: `http://127.0.0.1:${backendPort}`,
  });
  const originPort = await listen(origin);

  let largeRequest;
  try {
    const staticResponse = await fetch(`http://127.0.0.1:${originPort}/app.js`);
    assert.equal(staticResponse.status, 200);
    assert.match(staticResponse.headers.get('content-type') || '', /javascript/);
    assert.equal(await staticResponse.text(), 'window.SUXIOS_TEST = true;');

    const apiResponse = await fetch(`http://127.0.0.1:${originPort}/api/auth/probe`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ probe: true }),
    });
    assert.deepEqual(await apiResponse.json(), {
      method: 'POST',
      path: '/api/auth/probe',
      body: '{"probe":true}',
    });

    const largeHeaders = new Promise((resolve, reject) => {
      largeRequest = http.get(`http://127.0.0.1:${originPort}/large.js`, (response) => {
        response.once('data', () => {
          response.pause();
          resolve(response);
        });
      });
      largeRequest.once('error', reject);
    });
    const pausedLargeResponse = await largeHeaders;

    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 2_000);
    const concurrentStartedAt = Date.now();
    const concurrentApi = await fetch(`http://127.0.0.1:${originPort}/api/health`, {
      signal: controller.signal,
    });
    const concurrentElapsedMs = Date.now() - concurrentStartedAt;
    clearTimeout(timeout);
    assert.equal(concurrentApi.status, 200);
    assert.ok(concurrentElapsedMs < 1_500, `dynamic request waited ${concurrentElapsedMs}ms behind static transfer`);

    pausedLargeResponse.resume();
    largeRequest.destroy();
  } finally {
    largeRequest?.destroy();
    await close(origin);
    await close(backend);
    await fs.rm(temporaryRoot, { recursive: true, force: true });
  }
});

test('local origin serves versioned text assets with negotiated gzip and representation validators', async () => {
  const temporaryRoot = await fs.mkdtemp(path.join(os.tmpdir(), 'suxios-local-origin-gzip-'));
  const publicRoot = path.join(temporaryRoot, 'public');
  await fs.mkdir(publicRoot);
  const source = Buffer.from('window.SUXIOS_COMPRESSED = true;\n'.repeat(10_000));
  await fs.writeFile(path.join(publicRoot, 'bundle.js'), source);

  const origin = createLocalOriginServer({
    publicRoot,
    backendUrl: 'http://127.0.0.1:1',
    healthCheckIntervalMs: 60_000,
    healthCheckTimeoutMs: 50,
  });
  const originPort = await listen(origin);
  const pathname = '/bundle.js?v=20260805-local-origin-h1234567890';

  try {
    const compressed = await requestBuffer({
      port: originPort,
      pathname,
      headers: { 'Accept-Encoding': 'gzip' },
    });
    assert.equal(compressed.status, 200);
    assert.equal(compressed.headers['content-encoding'], 'gzip');
    assert.equal(compressed.headers.vary, 'Accept-Encoding');
    assert.equal(compressed.headers['cache-control'], 'public, max-age=2592000, immutable');
    assert.equal(Number(compressed.headers['content-length']), compressed.body.length);
    assert.ok(compressed.body.length < source.length / 5);
    assert.deepEqual(gunzipSync(compressed.body), source);

    const compressedEtag = compressed.headers.etag;
    assert.match(compressedEtag, /-gzip"$/);
    const head = await requestBuffer({
      port: originPort,
      pathname,
      method: 'HEAD',
      headers: { 'Accept-Encoding': 'gzip' },
    });
    assert.equal(head.status, 200);
    assert.equal(head.body.length, 0);
    assert.equal(head.headers['content-encoding'], 'gzip');
    assert.equal(head.headers['content-length'], compressed.headers['content-length']);
    assert.equal(head.headers.etag, compressedEtag);

    const notModified = await requestBuffer({
      port: originPort,
      pathname,
      headers: {
        'Accept-Encoding': 'gzip',
        'If-None-Match': compressedEtag,
      },
    });
    assert.equal(notModified.status, 304);
    assert.equal(notModified.body.length, 0);
    assert.equal(notModified.headers.etag, compressedEtag);

    const identity = await requestBuffer({
      port: originPort,
      pathname,
      headers: { 'Accept-Encoding': 'gzip;q=0, identity;q=1' },
    });
    assert.equal(identity.status, 200);
    assert.equal(identity.headers['content-encoding'], undefined);
    assert.equal(Number(identity.headers['content-length']), source.length);
    assert.notEqual(identity.headers.etag, compressedEtag);
    assert.deepEqual(identity.body, source);

    const unversioned = await requestBuffer({
      port: originPort,
      pathname: '/bundle.js',
      method: 'HEAD',
      headers: { 'Accept-Encoding': 'gzip' },
    });
    assert.equal(unversioned.status, 200);
    assert.equal(unversioned.headers['cache-control'], 'public, max-age=300, must-revalidate');
  } finally {
    await close(origin);
    await fs.rm(temporaryRoot, { recursive: true, force: true });
  }
});

test('local origin releases a static file stream when the client disconnects', async () => {
  const temporaryRoot = await fs.mkdtemp(path.join(os.tmpdir(), 'suxios-local-origin-abort-'));
  const publicRoot = path.join(temporaryRoot, 'public');
  await fs.mkdir(publicRoot);
  await fs.writeFile(path.join(publicRoot, 'abort.js'), Buffer.alloc(32 * 1024 * 1024, 65));

  const originalCreateReadStream = fsSync.createReadStream;
  let servedStream = null;
  fsSync.createReadStream = (...args) => {
    const stream = originalCreateReadStream(...args);
    if (path.basename(String(args[0] || '')) === 'abort.js') servedStream = stream;
    return stream;
  };

  const origin = createLocalOriginServer({
    publicRoot,
    backendUrl: 'http://127.0.0.1:1',
    healthCheckIntervalMs: 60_000,
    healthCheckTimeoutMs: 50,
  });
  const originPort = await listen(origin);
  let request;
  let response;

  try {
    response = await new Promise((resolve, reject) => {
      request = http.get(`http://127.0.0.1:${originPort}/abort.js`, (incoming) => {
        incoming.once('data', () => {
          incoming.pause();
          resolve(incoming);
        });
      });
      request.once('error', reject);
    });

    assert.ok(servedStream, 'the test must observe the server-side static stream');
    response.destroy();
    request.destroy();
    assert.equal(await waitForStreamClose(servedStream), true, 'server-side static stream stayed open after disconnect');
  } finally {
    response?.destroy();
    request?.destroy();
    servedStream?.destroy();
    fsSync.createReadStream = originalCreateReadStream;
    await close(origin);
    await fs.rm(temporaryRoot, { recursive: true, force: true });
  }
});
