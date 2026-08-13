import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import { promisify } from 'node:util';
import { fileURLToPath } from 'node:url';
import { gzip as gzipCallback } from 'node:zlib';

const scriptDirectory = path.dirname(fileURLToPath(import.meta.url));
const defaultPublicRoot = path.resolve(scriptDirectory, '..', 'public');
const gzipAsync = promisify(gzipCallback);
const staticGzipLevel = 6;
const staticGzipMinimumBytes = 1_024;
const staticGzipMaximumSourceBytes = 16 * 1024 * 1024;
const staticGzipCacheMaximumBytes = 64 * 1024 * 1024;
const staticGzipCacheMaximumEntries = 64;
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
  ['.avif', 'image/avif'],
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
  ['.ttf', 'font/ttf'],
  ['.txt', 'text/plain; charset=utf-8'],
  ['.webp', 'image/webp'],
  ['.woff', 'font/woff'],
  ['.woff2', 'font/woff2'],
]);
const cacheableStaticExtensions = new Set([
  '.avif', '.css', '.gif', '.ico', '.jpeg', '.jpg', '.js', '.png',
  '.svg', '.ttf', '.webp', '.woff', '.woff2',
]);
const compressibleStaticExtensions = new Set([
  '.css', '.html', '.js', '.json', '.map', '.svg', '.txt',
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
    activeProxyRequests: 0,
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
    // The built-in PHP development server handles one request per worker.
    // A health probe can therefore time out behind a valid in-flight request;
    // keep the last verified state until that request has finished.
    if (worker.healthy && worker.activeProxyRequests > 0) {
      return false;
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

function staticEtag(stat, encoding = 'identity') {
  return `W/"${stat.size.toString(16)}-${Math.trunc(stat.mtimeMs).toString(16)}-${encoding}"`;
}

function encodingQuality(headerValue, encoding) {
  const entries = String(headerValue || '').toLowerCase().split(',');
  let wildcardQuality = null;
  for (const entry of entries) {
    const [rawName, ...parameters] = entry.trim().split(';');
    const name = rawName.trim();
    if (!name) continue;
    let quality = 1;
    for (const parameter of parameters) {
      const match = parameter.trim().match(/^q\s*=\s*(0(?:\.\d+)?|1(?:\.0+)?)$/);
      if (match) quality = Number(match[1]);
    }
    if (name === encoding) return quality;
    if (name === '*') wildcardQuality = quality;
  }
  return wildcardQuality ?? (encoding === 'identity' ? 1 : 0);
}

function requestAcceptsGzip(request) {
  const header = request.headers['accept-encoding'];
  const gzipQuality = encodingQuality(header, 'gzip');
  const identityQuality = encodingQuality(header, 'identity');
  return gzipQuality > 0 && gzipQuality >= identityQuality;
}

function requestHasContentHash(requestUrl, filePath) {
  const basename = path.basename(filePath);
  if (/(?:^|[._-])h[0-9a-f]{10}(?:[._-]|$)/i.test(basename)) return true;
  try {
    const version = new URL(requestUrl || '/', 'http://127.0.0.1').searchParams.get('v') || '';
    return /(?:^|[-_])h[0-9a-f]{10}(?:[-_]|$)/i.test(version);
  } catch {
    return false;
  }
}

function staticCacheHeaders(requestUrl, filePath, extension) {
  if (cacheableStaticExtensions.has(extension)) {
    return {
      'Cache-Control': requestHasContentHash(requestUrl, filePath)
        ? 'public, max-age=2592000, immutable'
        : 'public, max-age=300, must-revalidate',
    };
  }
  if (extension === '.html' && path.basename(filePath).toLowerCase() === 'index.html') {
    return {
      'Cache-Control': 'public, max-age=60, s-maxage=60, stale-while-revalidate=30',
      'CDN-Cache-Control': 'public, max-age=60, stale-while-revalidate=30',
      'Cloudflare-CDN-Cache-Control': 'public, max-age=60, stale-while-revalidate=30',
    };
  }
  return { 'Cache-Control': 'no-cache' };
}

function requestMatchesStaticValidator(request, etag, stat) {
  const ifNoneMatch = String(request.headers['if-none-match'] || '').trim();
  if (ifNoneMatch) {
    return ifNoneMatch === '*' || ifNoneMatch.split(',').some((value) => value.trim() === etag);
  }
  const ifModifiedSince = Date.parse(String(request.headers['if-modified-since'] || ''));
  if (!Number.isFinite(ifModifiedSince)) return false;
  const fileModifiedAtSeconds = Math.trunc(stat.mtimeMs / 1000) * 1000;
  return ifModifiedSince >= fileModifiedAtSeconds;
}

function createStaticGzipCache() {
  const entries = new Map();
  let totalBytes = 0;

  const removeEntry = (key) => {
    const entry = entries.get(key);
    if (!entry) return;
    totalBytes -= entry.size || 0;
    entries.delete(key);
  };

  const prune = (protectedKey) => {
    for (const key of entries.keys()) {
      if (entries.size <= staticGzipCacheMaximumEntries
        && totalBytes <= staticGzipCacheMaximumBytes) break;
      if (key !== protectedKey) removeEntry(key);
    }
  };

  return {
    async get(filePath, stat) {
      const key = `${filePath}\0${stat.size}\0${Math.trunc(stat.mtimeMs)}\0${staticGzipLevel}`;
      for (const [cachedKey, entry] of entries) {
        if (entry.filePath === filePath && cachedKey !== key) removeEntry(cachedKey);
      }
      const cached = entries.get(key);
      if (cached) {
        entries.delete(key);
        entries.set(key, cached);
        return cached.promise;
      }

      const entry = { filePath, size: 0, promise: null };
      entry.promise = fs.promises.readFile(filePath)
        .then((source) => gzipAsync(source, { level: staticGzipLevel }))
        .then((encoded) => {
          if (entries.get(key) !== entry) return encoded;
          entry.size = encoded.length;
          totalBytes += entry.size;
          prune(key);
          return encoded;
        })
        .catch((error) => {
          if (entries.get(key) === entry) removeEntry(key);
          throw error;
        });
      entries.set(key, entry);
      prune(key);
      return entry.promise;
    },
    clear() {
      entries.clear();
      totalBytes = 0;
    },
  };
}

async function serveStaticFile(request, response, filePath, stat, gzipCache) {
  const extension = path.extname(filePath).toLowerCase();
  const gzipSelected = stat.size > staticGzipMinimumBytes
    && stat.size <= staticGzipMaximumSourceBytes
    && compressibleStaticExtensions.has(extension)
    && requestAcceptsGzip(request);
  const encoding = gzipSelected ? 'gzip' : 'identity';
  const etag = staticEtag(stat, encoding);
  const headers = {
    'Content-Type': contentTypes.get(extension) || 'application/octet-stream',
    'Last-Modified': stat.mtime.toUTCString(),
    ETag: etag,
    'X-Content-Type-Options': 'nosniff',
    ...staticCacheHeaders(request.url, filePath, extension),
  };
  if (compressibleStaticExtensions.has(extension)) headers.Vary = 'Accept-Encoding';
  if (gzipSelected) headers['Content-Encoding'] = 'gzip';

  if (requestMatchesStaticValidator(request, etag, stat)) {
    response.writeHead(304, headers);
    response.end();
    return;
  }

  if (gzipSelected) {
    const encoded = await gzipCache.get(filePath, stat);
    if (response.destroyed) return;
    headers['Content-Length'] = encoded.length;
    response.writeHead(200, headers);
    response.end(request.method === 'HEAD' ? undefined : encoded);
    return;
  }

  headers['Content-Length'] = stat.size;
  response.writeHead(200, headers);
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
  worker.activeProxyRequests += 1;
  let workerRequestReleased = false;
  const releaseWorkerRequest = () => {
    if (workerRequestReleased) return;
    workerRequestReleased = true;
    worker.activeProxyRequests = Math.max(0, worker.activeProxyRequests - 1);
  };

  const upstream = http.request({
    protocol: backend.protocol,
    hostname: backend.hostname,
    port: backend.port,
    method: request.method,
    path: request.url,
    headers,
    agent,
  }, (upstreamResponse) => {
    upstreamResponse.once('end', releaseWorkerRequest);
    upstreamResponse.once('close', releaseWorkerRequest);
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
    releaseWorkerRequest();
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
  const gzipCache = createStaticGzipCache();
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
            void serveStaticFile(request, response, filePath, stat, gzipCache).catch((serveError) => {
              if (!response.headersSent) {
                response.writeHead(500, {
                  'Content-Type': 'text/plain; charset=utf-8',
                  'Cache-Control': 'no-store',
                });
                response.end('Static asset compression failed');
                return;
              }
              response.destroy(serveError);
            });
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
  server.on('close', () => {
    gzipCache.clear();
    backendPool.close();
  });
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
