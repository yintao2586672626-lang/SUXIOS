import fs from 'node:fs';
import path from 'node:path';
import { gzipSync } from 'node:zlib';
import {
  extractAuthenticatedDeferredAssetReferences,
  extractAuthenticatedAssetReferences,
  extractAuthenticatedStartupAssetReferences,
  stripFrontendAssetQuery,
} from './frontend_authenticated_assets.mjs';

export const DEFAULT_FRONTEND_BUDGET = Object.freeze({
  max_index_bytes: 2_000_000,
  max_public_shell_gzip_bytes: 180_000,
  target_startup_gzip_bytes: 650_000,
  warning_startup_gzip_bytes: 800_000,
  max_startup_gzip_bytes: 850_000,
  max_inline_script_bytes: 20_000,
  max_blocking_script_count: 0,
});

const LIMITS = Object.freeze({
  index_bytes: 'max_index_bytes',
  public_shell_gzip_bytes: 'max_public_shell_gzip_bytes',
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

export function assessStartupGzipBudget(metrics, budget = DEFAULT_FRONTEND_BUDGET) {
  const actual = Number(metrics?.startup_gzip_bytes || 0);
  const target = Number(budget?.target_startup_gzip_bytes);
  const warning = Number(budget?.warning_startup_gzip_bytes);
  const hardLimit = Number(budget?.max_startup_gzip_bytes);

  let status = 'within_target';
  if (actual > hardLimit) status = 'failed';
  else if (actual >= warning) status = 'warning';
  else if (actual > target) status = 'above_target';

  return {
    metric: 'startup_gzip_bytes',
    status,
    actual,
    target,
    warning,
    hard_limit: hardLimit,
  };
}

export function collectFrontendEntryMetrics(repoRoot) {
  const publicRoot = path.join(repoRoot, 'public');
  const indexPath = path.join(publicRoot, 'index.html');
  const index = fs.readFileSync(indexPath);
  const html = index.toString('utf8');
  const headEnd = html.indexOf('</head>');
  const head = headEnd >= 0 ? html.slice(0, headEnd) : html;
  const isLocalAsset = (reference) => (
    /\.(?:js|css)$/.test(reference)
    && !reference.includes('://')
    && !reference.startsWith('//')
  );
  const publicShellReferences = [...html.matchAll(/<(?:script|link)\b[^>]*(?:src|href)="([^"]+)"[^>]*>/g)]
    .map((match) => stripFrontendAssetQuery(match[1]))
    .filter(isLocalAsset);
  const uniquePublicShellReferences = [...new Set(publicShellReferences)];
  const authenticatedReferences = extractAuthenticatedAssetReferences(html)
    .map(stripFrontendAssetQuery)
    .filter(isLocalAsset);
  const uniqueAuthenticatedReferences = [...new Set(authenticatedReferences)];
  const startupAuthenticatedReferences = extractAuthenticatedStartupAssetReferences(html)
    .map(stripFrontendAssetQuery)
    .filter(isLocalAsset);
  const uniqueStartupAuthenticatedReferences = [...new Set(startupAuthenticatedReferences)];
  const deferredAuthenticatedReferences = extractAuthenticatedDeferredAssetReferences(html)
    .map(stripFrontendAssetQuery)
    .filter(isLocalAsset);
  const uniqueDeferredAuthenticatedReferences = [...new Set(deferredAuthenticatedReferences)];
  const uniqueStartupReferences = [...new Set([
    ...uniquePublicShellReferences,
    ...uniqueStartupAuthenticatedReferences,
  ])];
  const uniqueFullAuthenticatedReferences = [...new Set([
    ...uniquePublicShellReferences,
    ...uniqueAuthenticatedReferences,
  ])];
  const publicShellFiles = [indexPath, ...uniquePublicShellReferences.map((reference) => path.join(publicRoot, reference))];
  const startupFiles = [indexPath, ...uniqueStartupReferences.map((reference) => path.join(publicRoot, reference))];
  const fullAuthenticatedFiles = [indexPath, ...uniqueFullAuthenticatedReferences.map((reference) => path.join(publicRoot, reference))];
  const deferredAuthenticatedFiles = uniqueDeferredAuthenticatedReferences.map((reference) => path.join(publicRoot, reference));
  const missing = fullAuthenticatedFiles.filter((file) => !fs.existsSync(file));
  if (missing.length) throw new Error(`Missing startup assets: ${missing.join(', ')}`);
  const inlineScriptBytes = [...html.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/g)]
    .reduce((total, match) => total + Buffer.byteLength(match[1]), 0);
  const blockingScriptCount = [...head.matchAll(/<script\b([^>]*)\bsrc="[^"]+"[^>]*><\/script>/g)]
    .filter((match) => !/\bdefer\b/.test(match[1])).length;

  return {
    index_bytes: index.length,
    public_shell_gzip_bytes: publicShellFiles.reduce((total, file) => total + gzipSync(fs.readFileSync(file), { level: 6 }).length, 0),
    startup_gzip_bytes: startupFiles.reduce((total, file) => total + gzipSync(fs.readFileSync(file), { level: 6 }).length, 0),
    full_authenticated_gzip_bytes: fullAuthenticatedFiles.reduce((total, file) => total + gzipSync(fs.readFileSync(file), { level: 6 }).length, 0),
    deferred_authenticated_gzip_bytes: deferredAuthenticatedFiles.reduce((total, file) => total + gzipSync(fs.readFileSync(file), { level: 6 }).length, 0),
    inline_script_bytes: inlineScriptBytes,
    blocking_script_count: blockingScriptCount,
    public_shell_asset_count: uniquePublicShellReferences.length,
    public_shell_assets: uniquePublicShellReferences,
    authenticated_asset_count: uniqueAuthenticatedReferences.length,
    authenticated_assets: uniqueAuthenticatedReferences,
    deferred_authenticated_asset_count: uniqueDeferredAuthenticatedReferences.length,
    deferred_authenticated_assets: uniqueDeferredAuthenticatedReferences,
    startup_asset_count: uniqueStartupReferences.length,
    startup_assets: uniqueStartupReferences,
  };
}
