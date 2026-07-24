const finiteNumber = (value, fallback = 0) => Number.isFinite(Number(value))
  ? Number(value)
  : fallback;

const rounded = (value) => Math.round(finiteNumber(value));

const frontendNetworkProfiles = Object.freeze({
  none: null,
  'slow-4g': Object.freeze({
    offline: false,
    latency: 150,
    downloadThroughput: 200_000,
    uploadThroughput: 93_750,
  }),
});

export function resolveFrontendNetworkProfile(value = 'none') {
  const name = String(value || 'none').trim().toLowerCase();
  if (!Object.hasOwn(frontendNetworkProfiles, name)) {
    throw new Error(`Unknown frontend network profile: ${value}`);
  }
  return {
    name,
    conditions: frontendNetworkProfiles[name]
      ? { ...frontendNetworkProfiles[name] }
      : null,
  };
}

function finiteSamples(values = []) {
  return values
    .filter((value) => value !== null && value !== undefined && value !== '')
    .map(Number)
    .filter(Number.isFinite);
}

export function percentile(values, ratio = 0.95) {
  const samples = finiteSamples(values)
    .sort((left, right) => left - right);
  if (!samples.length) return null;
  const boundedRatio = Math.min(1, Math.max(0, Number(ratio) || 0));
  const index = Math.max(0, Math.ceil(samples.length * boundedRatio) - 1);
  return samples[index];
}

function summarizeDurationSamples(values = []) {
  const samples = finiteSamples(values);
  return {
    sample_count: samples.length,
    p50_ms: percentile(samples, 0.5),
    p95_ms: percentile(samples, 0.95),
    max_ms: percentile(samples, 1),
  };
}

function normalizeApiRoute(value) {
  try {
    const parsed = new URL(String(value || ''), 'http://127.0.0.1/');
    if (!parsed.pathname.startsWith('/api/')) return null;
    const queryKeys = [...new Set([...parsed.searchParams.keys()])].sort();
    return queryKeys.length
      ? `${parsed.pathname}?${queryKeys.join('&')}`
      : parsed.pathname;
  } catch (_error) {
    return null;
  }
}

export function summarizeApiPerformance(resources = [], options = {}) {
  const minStartTime = Number(options.min_start_time_ms || 0);
  const samples = (Array.isArray(resources) ? resources : []).flatMap((entry) => {
    const initiatorType = String(entry?.initiatorType || '').toLowerCase();
    const route = normalizeApiRoute(entry?.name);
    const startTime = Number(entry?.startTime);
    const duration = Number(entry?.duration);
    if (!['fetch', 'xmlhttprequest'].includes(initiatorType)
      || !route
      || !Number.isFinite(duration)
      || (Number.isFinite(startTime) && startTime < minStartTime)) {
      return [];
    }
    const status = Number(entry?.responseStatus);
    return [{
      route,
      duration_ms: rounded(duration),
      transfer_bytes: rounded(entry?.transferSize),
      status: Number.isFinite(status) && status > 0 ? status : null,
    }];
  });
  const routeGroups = new Map();
  for (const sample of samples) {
    if (!routeGroups.has(sample.route)) routeGroups.set(sample.route, []);
    routeGroups.get(sample.route).push(sample.duration_ms);
  }
  const byRoute = [...routeGroups.entries()]
    .map(([route, durations]) => ({
      route,
      ...summarizeDurationSamples(durations),
    }))
    .sort((left, right) => (
      Number(right.p95_ms || 0) - Number(left.p95_ms || 0)
      || left.route.localeCompare(right.route)
    ));

  return {
    ...summarizeDurationSamples(samples.map((sample) => sample.duration_ms)),
    samples,
    by_route: byRoute,
    repeated_routes: byRoute
      .filter((entry) => entry.sample_count > 1)
      .map((entry) => ({ route: entry.route, count: entry.sample_count })),
  };
}

export function summarizeFrontendPerformanceRuns(runs = []) {
  const rows = Array.isArray(runs) ? runs : [];
  const metricNames = [
    'ttfb_ms',
    'fcp_ms',
    'lcp_ms',
    'dom_interactive_ms',
    'full_load_ms',
    'login_click_to_interactive_ms',
    'auth_to_interactive_ms',
    'long_task_total_ms',
    'longest_task_ms',
  ];
  const metrics = Object.fromEntries(metricNames.map((name) => [
    name,
    summarizeDurationSamples(rows.map((run) => run?.metrics?.[name])),
  ]));
  const apiSamples = rows.flatMap((run) => (
    Array.isArray(run?.api?.samples) ? run.api.samples : []
  ));

  return {
    run_count: rows.length,
    verified_run_count: rows.filter((run) => run?.verification_status === 'verified').length,
    unverified_run_count: rows.filter((run) => run?.verification_status !== 'verified').length,
    metrics,
    api: summarizeApiPerformance(apiSamples.map((sample) => ({
      name: sample.route,
      initiatorType: 'fetch',
      startTime: 0,
      duration: sample.duration_ms,
      transferSize: sample.transfer_bytes,
      responseStatus: sample.status,
    }))),
  };
}

export function summarizeFrontendPerformance(snapshot = {}) {
  const navigation = snapshot.navigation || {};
  const paints = Array.isArray(snapshot.paints) ? snapshot.paints : [];
  const resources = Array.isArray(snapshot.resources) ? snapshot.resources : [];
  const longTasks = Array.isArray(snapshot.longTasks) ? snapshot.longTasks : [];
  const loginHandoff = snapshot.loginHandoff && typeof snapshot.loginHandoff === 'object'
    ? snapshot.loginHandoff
    : {};
  const fcp = paints.find((entry) => entry?.name === 'first-contentful-paint');
  const resourceBytes = (predicate) => resources
    .filter(predicate)
    .reduce((total, entry) => total + finiteNumber(entry?.transferSize), 0);

  return {
    ttfb_ms: rounded(finiteNumber(navigation.responseStart) - finiteNumber(navigation.requestStart)),
    fcp_ms: fcp ? rounded(fcp.startTime) : null,
    lcp_ms: Number.isFinite(Number(snapshot.lcp)) ? rounded(snapshot.lcp) : null,
    dom_interactive_ms: rounded(navigation.domInteractive),
    dom_complete_ms: rounded(navigation.domComplete),
    full_load_ms: rounded(navigation.loadEventEnd),
    total_requests: resources.length,
    total_transfer_bytes: resourceBytes(() => true),
    js_transfer_bytes: resourceBytes((entry) => (
      entry?.initiatorType === 'script'
      || String(entry?.name || '').split(/[?#]/, 1)[0].endsWith('.js')
    )),
    css_transfer_bytes: resourceBytes((entry) => entry?.initiatorType === 'link'
      && String(entry?.name || '').split('?')[0].endsWith('.css')),
    long_task_count: longTasks.length,
    long_task_total_ms: rounded(longTasks.reduce((total, entry) => total + finiteNumber(entry?.duration), 0)),
    longest_task_ms: rounded(Math.max(0, ...longTasks.map((entry) => finiteNumber(entry?.duration)))),
    login_handoff_status: String(loginHandoff.status || '') || null,
    auth_to_interactive_ms: Number.isFinite(Number(loginHandoff.auth_to_interactive_ms))
      ? rounded(loginHandoff.auth_to_interactive_ms)
      : null,
  };
}
