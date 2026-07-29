const SANDBOX_ID_PATTERN = /^sbx_[A-Za-z0-9_-]{8,64}$/;
const MARKER_PREFIX = 'about:blank#suxios-browser-sandbox=';

export const BROWSER_SANDBOX_PLATFORMS = Object.freeze({
  ctrip: Object.freeze({
    startUrl: 'https://ebooking.ctrip.com/home/mainland',
    origins: Object.freeze([
      'https://ebooking.ctrip.com',
    ]),
  }),
  meituan: Object.freeze({
    startUrl: 'https://me.meituan.com/ebooking/',
    origins: Object.freeze([
      'https://me.meituan.com',
      'https://pms.meituan.com',
    ]),
  }),
  dingdandao: Object.freeze({
    startUrl: 'https://www.dingdandao.com/pmsManage/report/pro/dataCenter/accommodationData',
    origins: Object.freeze([
      'https://www.dingdandao.com',
    ]),
  }),
});

export function normalizeBrowserSandboxId(value) {
  const normalized = String(value || '').trim();
  if (!SANDBOX_ID_PATTERN.test(normalized)) {
    throw new Error('browser_sandbox_id_invalid');
  }
  return normalized;
}

export function normalizeBrowserSandboxPlatform(value) {
  const normalized = String(value || '').trim().toLowerCase();
  if (!Object.hasOwn(BROWSER_SANDBOX_PLATFORMS, normalized)) {
    throw new Error('browser_sandbox_platform_invalid');
  }
  return normalized;
}

export function browserSandboxMarkerUrl(sandboxId) {
  return `${MARKER_PREFIX}${normalizeBrowserSandboxId(sandboxId)}`;
}

export function isBrowserSandboxMarkerUrl(value) {
  return String(value || '').startsWith(MARKER_PREFIX);
}

function targetContextId(target) {
  return typeof target?.browserContextId === 'string'
    ? target.browserContextId.trim()
    : '';
}

function validContextSet(browserContextIds) {
  if (!Array.isArray(browserContextIds)) return null;
  return new Set(browserContextIds.filter((value) => (
    typeof value === 'string' && value.trim() !== ''
  )));
}

function contextIsCurrent(contextId, validContextIds) {
  return contextId === '' || validContextIds === null || validContextIds.has(contextId);
}

export function resolveBrowserSandboxContext({
  targetInfos,
  browserContextIds = null,
  sandboxId,
  requireIsolated = true,
} = {}) {
  const markerUrl = browserSandboxMarkerUrl(sandboxId);
  const validContextIds = validContextSet(browserContextIds);
  const matchingContextIds = new Set(
    (Array.isArray(targetInfos) ? targetInfos : [])
      .filter((target) => (
        target?.type === 'page'
        && String(target.url || '') === markerUrl
      ))
      .map(targetContextId)
      .filter((contextId) => contextIsCurrent(contextId, validContextIds)),
  );

  if (matchingContextIds.size === 0) {
    throw new Error('browser_sandbox_not_bound');
  }
  if (matchingContextIds.size > 1) {
    throw new Error('browser_sandbox_binding_ambiguous');
  }

  const [browserContextId] = matchingContextIds;
  if (requireIsolated && browserContextId === '') {
    throw new Error('browser_sandbox_not_isolated');
  }
  return {
    sandboxId: normalizeBrowserSandboxId(sandboxId),
    browserContextId: browserContextId || null,
    contextKey: browserContextId,
    isolation: browserContextId ? 'browser_context' : 'default_context',
  };
}

export function platformContextCandidates({
  targetInfos,
  browserContextIds = null,
  platform,
} = {}) {
  const normalizedPlatform = normalizeBrowserSandboxPlatform(platform);
  const allowedOrigins = new Set(BROWSER_SANDBOX_PLATFORMS[normalizedPlatform].origins);
  const validContextIds = validContextSet(browserContextIds);
  const contextIds = new Set();

  for (const target of Array.isArray(targetInfos) ? targetInfos : []) {
    if (target?.type !== 'page') continue;
    let origin = '';
    try {
      origin = new URL(String(target.url || '')).origin;
    } catch {
      continue;
    }
    if (!allowedOrigins.has(origin)) continue;
    const contextId = targetContextId(target);
    if (!contextIsCurrent(contextId, validContextIds)) continue;
    contextIds.add(contextId);
  }
  return [...contextIds];
}

export function assertContextHasNoDifferentSandbox({
  targetInfos,
  browserContextId,
  sandboxId,
} = {}) {
  const expectedMarker = browserSandboxMarkerUrl(sandboxId);
  const conflicts = (Array.isArray(targetInfos) ? targetInfos : []).filter((target) => (
    target?.type === 'page'
    && targetContextId(target) === String(browserContextId || '')
    && isBrowserSandboxMarkerUrl(target.url)
    && String(target.url || '') !== expectedMarker
  ));
  if (conflicts.length > 0) {
    throw new Error('browser_sandbox_context_already_bound');
  }
}
