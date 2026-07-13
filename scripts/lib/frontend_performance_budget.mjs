import fs from 'node:fs';
import path from 'node:path';
import { gzipSync } from 'node:zlib';

export const DEFAULT_FRONTEND_BUDGET = Object.freeze({
  max_index_bytes: 2_000_000,
  max_startup_gzip_bytes: 850_000,
  max_inline_script_bytes: 20_000,
  max_blocking_script_count: 0,
});

const LIMITS = Object.freeze({
  index_bytes: 'max_index_bytes',
  startup_gzip_bytes: 'max_startup_gzip_bytes',
  inline_script_bytes: 'max_inline_script_bytes',
  blocking_script_count: 'max_blocking_script_count',
});

export function evaluateFrontendBudget(metrics, budget = DEFAULT_FRONTEND_BUDGET) {
  return Object.entries(LIMITS).flatMap(([metric, limitKey]) => {
    const actual = Number(metrics?.[metric] || 0);
    const limit = Number(budget?.[limitKey]);
    return actual > limit ? [{ metric, actual, limit }] : [];
  });
}

export function collectFrontendEntryMetrics(repoRoot) {
  const publicRoot = path.join(repoRoot, 'public');
  const indexPath = path.join(publicRoot, 'index.html');
  const index = fs.readFileSync(indexPath);
  const html = index.toString('utf8');
  const head = html.slice(0, html.indexOf('</head>'));
  const localReferences = [...head.matchAll(/<(?:script|link)\b[^>]*(?:src|href)="([^"?]+)(?:\?[^"]*)?"[^>]*>/g)]
    .map((match) => match[1])
    .filter((reference) => /\.(?:js|css)$/.test(reference));
  const uniqueReferences = [...new Set(localReferences)];
  const startupFiles = [indexPath, ...uniqueReferences.map((reference) => path.join(publicRoot, reference))];
  const missing = startupFiles.filter((file) => !fs.existsSync(file));
  if (missing.length) throw new Error(`Missing startup assets: ${missing.join(', ')}`);
  const inlineScriptBytes = [...html.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/g)]
    .reduce((total, match) => total + Buffer.byteLength(match[1]), 0);
  const blockingScriptCount = [...head.matchAll(/<script\b([^>]*)\bsrc="[^"]+"[^>]*><\/script>/g)]
    .filter((match) => !/\bdefer\b/.test(match[1])).length;

  return {
    index_bytes: index.length,
    startup_gzip_bytes: startupFiles.reduce((total, file) => total + gzipSync(fs.readFileSync(file), { level: 6 }).length, 0),
    inline_script_bytes: inlineScriptBytes,
    blocking_script_count: blockingScriptCount,
    startup_asset_count: uniqueReferences.length,
    startup_assets: uniqueReferences,
  };
}
