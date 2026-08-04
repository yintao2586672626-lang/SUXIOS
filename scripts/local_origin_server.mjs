import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const scriptDirectory = path.dirname(fileURLToPath(import.meta.url));
const defaultPublicRoot = path.resolve(scriptDirectory, '..', 'public');
const hopByHopHeaders = new Set([
  'connection',
  'keep-alive',
  'proxy-authenticate',
  'proxy-authorization',
  'te',
  'trailer',
  'transfer-encoding',
  'upgrade',
]);
const contentTypes = new Map([
  ['.css', 'text/css; charset=utf-8'],
  ['.gif', 'image/gif'],
  ['.html', 'text/html; charset=utf-8'],
  ['.ico', 'image/x-icon'],
  ['.jpeg', 'image/jpeg'],
  ['.jpg', 'image/jpeg'],
  ['.js', 'text/javascript; charset=utf-8'],
  ['.json', 'application/json; charset=utf-8'],
  ['.map', 'application/json; charset=utf-8'],
  ['.png', 'image/png'],
  ['.svg', 'image/svg+xml; charset=utf-8'],
  ['.txt', 'text/plain; charset=utf-8'],
  ['.webp', 'image/webp'],
  ['.woff', 'font/woff'],
  ['.woff2', 'font/woff2'],
]);
const healthFailureThreshold = 2;

function parseInteger(value, fallback) {
  const parsed = Number.parseInt(String(value ?? ''), 10);
  return Number.isInteger(parsed) && parsed > 0 && parsed <= 65535 ? parsed : fallback;
}

function cliValue(args, name, fallback = '') {
  const exactIndex = args.indexOf(`--${name}`);
  if (exactIndex >= 0 && exactIndex + 1 < args.length) {
    return args[exactIndex + 1];
  }
  const prefix = `--${name}=`;
  const inline = args.find((arg) => arg.startsWith(prefix));
  return inline ? inline.slice(prefix.length) : fallback;
}

function requestPathname(requestUrl) {
  try {
    return new URL(requestUrl || '/', 'http://127.0.0.1').pathname;
  } catch {
    return '';
  }
}

function staticFilePath(publicRoot, requestUrl) {
  const pathname = requestPathname(requestUrl);
  if (!pathname || pathname.includes('\0')) {
    return null;
  }

  let decoded;
  try {
    decoded = decodeURIComponent(pathname).replaceAll('\\', '/');
  } catch {
    return null;
  }
  if (decoded.split('/').includes('..')) {
    return null;
  }

  const relativePath = decoded === '/' ? 'index.html' : decoded.replace(/^\/+/, '');
  const candidate = path.resolve(publicRoot, relativePath);
  const normalizedRoot = path.resolve(publicRoot);
  if (candidate !== normalizedRoot && !candidate.startsWith(`${normalizedRoot}${path.sep}`)) {
    return null;
  }
  return candidate;
}

function copyProxyHeaders(headers) {
  const result = {};
  for (const [name, value] of Object.entries(headers || {})) {
    if (!hopByHopHeaders.has(name.toLowerCase()) && value !== undefined) {
      result[name] = value;
    }
  }
  return result;
}

function normalizeBackendUrls(backendUrl, backendUrls) {
  const candidates = Array.isArray(backendUrls) && backendUrls.length > 0
    ? backendUrls
    : [backendUrl];
  const normalized = [];
  for (const candidate of candidates.flatMap((value) => String(value || '').split(','))) {
    const value = candidate.trim();
    if (!value) continue;
    const backend = new URL(value);
    if (backend.protocol !== 'http:') {
      throw new Error('Local origin backends must use http://');
    }
    if (!normalized.some((item) => item.href === backend.href)) {
      normalized.push(backend);
    }
  }
  if (normalized.length === 0) {
    throw new Error('Local origin requires at least one backend');
  }
  return normalized;
}

function createBackendPool(backends, {
  healthPath = '/api/health',
  healthCheckIntervalMs = 2_000,
  healthCheckTimeoutMs = 1_500,
} = {}) {
  const workers = backends.map((backend) => ({
    backend,
    healthy: false,
    consecutiveHealthFailures: 0,
    checking: null,
    agent: new http.Agent({ keepAlive: true, maxSockets: 32 }),
  }));
  let cursor = 0;

  const recordHealthResult = (worker, healthy) => {
    if (healthy) {
      worker.healthy = true;
      worker.consecutiveHealthFailures = 0;
      return true;
    }
    worker.consecutiveHealthFailures += 1;
    if (!worker.healthy || worker.consecutiveHealthFailures >= healthFailureThreshold) {
      worker.healthy = false;
    }
    return false;
  };

  const checkWorker = (worker) => {
    if (worker.checking) return worker.checking;
    worker.checking = new Promise((resolve) => {
      const probe = http.request({
        protocol: worker.backend.protocol,
        hostname: worker.backend.hostname,
        port: worker.backend.port,
        method: 'GET',
        path: healthPath,
        agent: worker.agent,
        headers: { Connection: 'keep-alive' },
      }, (probeResponse) => {
        probeResponse.resume();
        probeResponse.once('end', () => {
          resolve(recordHealthResult(worker, probeResponse.statusCode === 200));
        });
      });
      probe.setTimeout(healthCheckTimeoutMs, () => probe.destroy(new Error('health check timeout')));
      probe.once('error', () => {
        resolve(recordHealthResult(worker, false));
      });
      probe.end();
    }).finally(() => {
      worker.checking = null;
    });
    return worker.checking;
  };

  const checkAll = () => Promise.all(workers.map(checkWorker));
  const interval = setInterval(checkAll, healthCheckIntervalMs);
  interval.unref();
  void checkAll();

  return {
    size: workers.length,
    async nextHealthy(excludedWorker = null) {
      let healthyWorkers = workers.filter(
        (worker) => worker.healthy && worker !== excludedWorker,
      );
      if (healthyWorkers.length === 0) {
        await checkAll();
        healthyWorkers = workers.filter(
          (worker) => worker.healthy && worker !== excludedWorker,
        );
      }
      if (healthyWorkers.length === 0) return null;
      const selected = healthyWorkers[cursor % healthyWorkers.length];
      cursor = (cursor + 1) % Number.MAX_SAFE_INTEGER;
      return selected;
    },
    markUnhealthy(worker) {
      worker.healthy = false;
      worker.consecutiveHealthFailures = healthFailureThreshold;
    },
    close() {
      clearInterval(interval);
      for (const worker of workers) worker.agent.destroy();
    },
  };
}

function staticEtag(stat) {
  return `W/"${stat.size.toString(16)}-${Math.trunc(stat.mtimeMs).toString(16)}"`;
}

function serveStaticFile(request, response, filePath, stat) {
  const etag = staticEtag(stat);
  if (request.headers['if-none-match'] === etag) {
    response.writeHead(304, {
      ETag: etag,
      'Last-Modified': stat.mtime.toUTCString(),
    });
    response.end();
    return;
  }

  response.writeHead(200, {
    'Content-Type': contentTypes.get(path.extname(filePath).toLowerCase()) || 'application/octet-stream',
    'Content-Length': stat.size,
    'Last-Modified': stat.mtime.toUTCString(),
    ETag: etag,
    'X-Content-Type-Options': 'nosniff',
  });
  if (request.method === 'HEAD') {
    response.end();
    return;
  }

  const stream = fs.createReadStream(filePath);
  const releaseStream = () => {
    if (!stream.destroyed) stream.destroy();
  };
  response.once('close', releaseStream);
  stream.once('close', () => response.removeListener('close', releaseStream));
  stream.on('error', () => {
    if (!response.headersSent) {
      response.writeHead(500, { 'Content-Type': 'text/plain; charset=utf-8' });
      response.end('Static asset read failed');
      return;
    }
    response.destroy();
  });
  stream.pipe(response);
}

function requestHasBody(request) {
  if (request.headers['transfer-encoding'] !== undefined) return true;
  const contentLength = request.headers['content-length'];
  if (contentLength === undefined) return false;
  return !/^\s*0\s*$/.test(String(contentLength));
}

function canRetryReadRequest(request) {
  return (request.method === 'GET' || request.method === 'HEAD') && !requestHasBody(request);
}

function respondBackendUnavailable(request, response) {
  const isApiRequest = requestPathname(request.url).startsWith('/api/');
  response.writeHead(502, {
    'Content-Type': isApiRequest
      ? 'application/json; charset=utf-8'
      : 'text/plain; charset=utf-8',
    'Cache-Control': 'no-store',
  });
  response.end(isApiRequest
    ? JSON.stringify({ code: 502, message: '所选本机 PHP worker 已不可用' })
    : 'Local application backend unavailable');
}

function proxyToBackend(request, response, worker, backendPool, retriesRemaining = 1) {
  const { backend, agent } = worker;
  const retryableRead = canRetryReadRequest(request);
  const headers = copyProxyHeaders(request.headers);
  headers.host = request.headers.host || backend.host;

  const upstream = http.request({
    protocol: backend.protocol,
    hostname: backend.hostname,
    port: backend.port,
    method: request.method,
    path: request.url,
    headers,
    agent,
  }, (upstreamResponse) => {
    const responseHeaders = copyProxyHeaders(upstreamResponse.headers);
    responseHeaders['X-SUXIOS-Backend-Pool-Size'] = String(backendPool.size);
    response.writeHead(
      upstreamResponse.statusCode || 502,
      upstreamResponse.statusMessage,
      responseHeaders,
    );
    upstreamResponse.pipe(response);
  });

  upstream.on('error', (error) => {
    backendPool.markUnhealthy(worker);
    if (response.headersSent) {
      response.destroy(error);
      return;
    }
    if (retryableRead && retriesRemaining > 0 && !request.aborted && !response.destroyed) {
      void backendPool.nextHealthy(worker).then((nextWorker) => {
        if (!nextWorker || request.aborted || response.destroyed) {
          if (!response.destroyed) respondBackendUnavailable(request, response);
          return;
        }
        proxyToBackend(request, response, nextWorker, backendPool, retriesRemaining - 1);
      });
      return;
    }
    respondBackendUnavailable(request, response);
  });

  request.on('aborted', () => upstream.destroy());
  if (retryableRead) {
    upstream.end();
  } else {
    request.pipe(upstream);
  }
}

function respondNoHealthyBackend(request, response) {
  const isApiRequest = requestPathname(request.url).startsWith('/api/');
  response.writeHead(503, {
    'Content-Type': isApiRequest
      ? 'application/json; charset=utf-8'
      : 'text/plain; charset=utf-8',
    'Cache-Control': 'no-store',
  });
  response.end(isApiRequest
    ? JSON.stringify({ code: 503, message: '没有可用的本机 PHP worker' })
    : 'No healthy local PHP worker is available');
}

export function createLocalOriginServer({
  publicRoot = defaultPublicRoot,
  backendUrl = 'http://127.0.0.1:8081',
  backendUrls,
  healthPath = '/api/health',
  healthCheckIntervalMs = 2_000,
  healthCheckTimeoutMs = 1_500,
} = {}) {
  const normalizedPublicRoot = path.resolve(publicRoot);
  const backends = normalizeBackendUrls(backendUrl, backendUrls);
  const backendPool = createBackendPool(backends, {
    healthPath,
    healthCheckIntervalMs,
    healthCheckTimeoutMs,
  });

  const proxyRequest = async (request, response) => {
    const worker = await backendPool.nextHealthy();
    if (!worker) {
      respondNoHealthyBackend(request, response);
      return;
    }
    proxyToBackend(request, response, worker, backendPool);
  };

  const server = http.createServer((request, response) => {
    if (request.method === 'GET' || request.method === 'HEAD') {
      const filePath = staticFilePath(normalizedPublicRoot, request.url);
      if (filePath) {
        fs.stat(filePath, (error, stat) => {
          if (!error && stat.isFile()) {
            serveStaticFile(request, response, filePath, stat);
            return;
          }
          void proxyRequest(request, response);
        });
        return;
      }
    }

    void proxyRequest(request, response);
  });

  server.requestTimeout = 120_000;
  server.headersTimeout = 65_000;
  server.keepAliveTimeout = 5_000;
  server.on('close', () => backendPool.close());
  return server;
}

export async function startLocalOriginServer({
  host = '127.0.0.1',
  port = 8080,
  publicRoot = defaultPublicRoot,
  backendUrl = 'http://127.0.0.1:8081',
  backendUrls,
} = {}) {
  const server = createLocalOriginServer({ publicRoot, backendUrl, backendUrls });
  await new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(port, host, resolve);
  });
  return server;
}

const invokedDirectly = process.argv[1]
  && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url);
if (invokedDirectly) {
  const args = process.argv.slice(2);
  const host = cliValue(args, 'host', '127.0.0.1');
  const port = parseInteger(cliValue(args, 'port', '8080'), 8080);
  const backendUrl = cliValue(args, 'backend', 'http://127.0.0.1:8081');
  const backendUrls = cliValue(args, 'backends', '')
    .split(',')
    .map((value) => value.trim())
    .filter(Boolean);
  const publicRoot = path.resolve(cliValue(args, 'public-root', defaultPublicRoot));
  const server = await startLocalOriginServer({
    host,
    port,
    backendUrl,
    backendUrls: backendUrls.length > 0 ? backendUrls : undefined,
    publicRoot,
  });
  const backendSummary = backendUrls.length > 0 ? backendUrls.join(', ') : backendUrl;
  console.log(`[OK] SUXIOS local origin listening on http://${host}:${port} -> ${backendSummary}`);

  const stop = () => {
    server.close(() => process.exit(0));
    setTimeout(() => process.exit(1), 5_000).unref();
  };
  process.once('SIGINT', stop);
  process.once('SIGTERM', stop);
}
