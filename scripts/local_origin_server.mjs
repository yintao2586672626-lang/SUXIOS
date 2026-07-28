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

function proxyToBackend(request, response, backend, agent) {
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
    response.writeHead(
      upstreamResponse.statusCode || 502,
      upstreamResponse.statusMessage,
      copyProxyHeaders(upstreamResponse.headers),
    );
    upstreamResponse.pipe(response);
  });

  upstream.on('error', (error) => {
    if (response.headersSent) {
      response.destroy(error);
      return;
    }
    const isApiRequest = requestPathname(request.url).startsWith('/api/');
    response.writeHead(502, {
      'Content-Type': isApiRequest
        ? 'application/json; charset=utf-8'
        : 'text/plain; charset=utf-8',
      'Cache-Control': 'no-store',
    });
    response.end(isApiRequest
      ? JSON.stringify({ code: 502, message: '本机应用服务暂不可用' })
      : 'Local application backend unavailable');
  });

  request.on('aborted', () => upstream.destroy());
  request.pipe(upstream);
}

export function createLocalOriginServer({
  publicRoot = defaultPublicRoot,
  backendUrl = 'http://127.0.0.1:8081',
} = {}) {
  const normalizedPublicRoot = path.resolve(publicRoot);
  const backend = new URL(backendUrl);
  if (backend.protocol !== 'http:') {
    throw new Error('Local origin backend must use http://');
  }
  const backendAgent = new http.Agent({ keepAlive: true, maxSockets: 32 });

  const server = http.createServer((request, response) => {
    if (request.method === 'GET' || request.method === 'HEAD') {
      const filePath = staticFilePath(normalizedPublicRoot, request.url);
      if (filePath) {
        fs.stat(filePath, (error, stat) => {
          if (!error && stat.isFile()) {
            serveStaticFile(request, response, filePath, stat);
            return;
          }
          proxyToBackend(request, response, backend, backendAgent);
        });
        return;
      }
    }

    proxyToBackend(request, response, backend, backendAgent);
  });

  server.requestTimeout = 120_000;
  server.headersTimeout = 65_000;
  server.keepAliveTimeout = 5_000;
  server.on('close', () => backendAgent.destroy());
  return server;
}

export async function startLocalOriginServer({
  host = '127.0.0.1',
  port = 8080,
  publicRoot = defaultPublicRoot,
  backendUrl = 'http://127.0.0.1:8081',
} = {}) {
  const server = createLocalOriginServer({ publicRoot, backendUrl });
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
  const publicRoot = path.resolve(cliValue(args, 'public-root', defaultPublicRoot));
  const server = await startLocalOriginServer({ host, port, backendUrl, publicRoot });
  console.log(`[OK] SUXIOS local origin listening on http://${host}:${port} -> ${backendUrl}`);

  const stop = () => {
    server.close(() => process.exit(0));
    setTimeout(() => process.exit(1), 5_000).unref();
  };
  process.once('SIGINT', stop);
  process.once('SIGTERM', stop);
}
