export function liveProfilePageCandidates(context, preferredPage = null, isTrustedUrl = () => true) {
  let contextPages = [];
  try {
    contextPages = typeof context?.pages === 'function' ? context.pages() : [];
  } catch {
    contextPages = [];
  }

  const ordered = [];
  const seen = new Set();
  const append = (page) => {
    if (!page || seen.has(page)) return;
    seen.add(page);
    try {
      if (typeof page.isClosed === 'function' && page.isClosed()) return;
    } catch {
      return;
    }
    ordered.push(page);
  };

  append(preferredPage);
  for (const page of [...contextPages].reverse()) append(page);

  const trusted = [];
  const fallback = [];
  for (const page of ordered) {
    let url = '';
    try {
      url = typeof page.url === 'function' ? String(page.url() || '') : '';
    } catch {
      url = '';
    }
    let isTrusted = false;
    try {
      isTrusted = isTrustedUrl(url, page) === true;
    } catch {
      isTrusted = false;
    }
    (isTrusted ? trusted : fallback).push(page);
  }

  return trusted.length > 0 ? [...trusted, ...fallback] : fallback;
}

export async function resolveProfileLoginPage(
  context,
  preferredPage,
  looksLoggedIn,
  isTrustedUrl = () => true,
) {
  const candidates = liveProfilePageCandidates(context, preferredPage, isTrustedUrl);
  for (const page of candidates) {
    try {
      if (await looksLoggedIn(page)) {
        return { page, loggedIn: true };
      }
    } catch {
      // A redirect may close a candidate between enumeration and inspection.
      // Continue with the remaining pages from the same persistent Profile.
    }
  }

  return {
    page: candidates[0] || null,
    loggedIn: false,
  };
}

export async function waitForProfileLoginPoll(delayMs) {
  const boundedDelay = Math.max(0, Math.min(600000, Number(delayMs) || 0));
  await new Promise(resolve => setTimeout(resolve, boundedDelay));
}
