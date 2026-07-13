const finiteNumber = (value, fallback = 0) => Number.isFinite(Number(value))
  ? Number(value)
  : fallback;

const rounded = (value) => Math.round(finiteNumber(value));

export function percentile(values, ratio = 0.95) {
  const samples = values
    .map(Number)
    .filter(Number.isFinite)
    .sort((left, right) => left - right);
  if (!samples.length) return null;
  const boundedRatio = Math.min(1, Math.max(0, Number(ratio) || 0));
  const index = Math.max(0, Math.ceil(samples.length * boundedRatio) - 1);
  return samples[index];
}

export function summarizeFrontendPerformance(snapshot = {}) {
  const navigation = snapshot.navigation || {};
  const paints = Array.isArray(snapshot.paints) ? snapshot.paints : [];
  const resources = Array.isArray(snapshot.resources) ? snapshot.resources : [];
  const longTasks = Array.isArray(snapshot.longTasks) ? snapshot.longTasks : [];
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
    js_transfer_bytes: resourceBytes((entry) => entry?.initiatorType === 'script'),
    css_transfer_bytes: resourceBytes((entry) => entry?.initiatorType === 'link'
      && String(entry?.name || '').split('?')[0].endsWith('.css')),
    long_task_count: longTasks.length,
    long_task_total_ms: rounded(longTasks.reduce((total, entry) => total + finiteNumber(entry?.duration), 0)),
    longest_task_ms: rounded(Math.max(0, ...longTasks.map((entry) => finiteNumber(entry?.duration)))),
  };
}
