const SAFE_STATUS_VALUE = /^[a-z0-9_.:-]{1,80}$/i;
const SAFE_STATUS_KEY = /^[a-z0-9_.-]{1,80}$/i;

function safeStatusValue(value) {
  const normalized = String(value ?? '').trim();
  return SAFE_STATUS_VALUE.test(normalized) ? normalized : '';
}

export function formatHealthFailure(statusCode, payload) {
  const parts = [`HTTP ${Number(statusCode) || 0}`];
  if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
    return parts[0];
  }

  const status = safeStatusValue(payload.status);
  if (status) {
    parts.push(`status=${status}`);
  }

  if (payload.checks && typeof payload.checks === 'object' && !Array.isArray(payload.checks)) {
    const checks = Object.entries(payload.checks)
      .filter(([key, value]) => SAFE_STATUS_KEY.test(String(key)) && safeStatusValue(value))
      .slice(0, 20)
      .map(([key, value]) => `${key}:${safeStatusValue(value)}`);
    if (checks.length > 0) {
      parts.push(`checks=${checks.join(',')}`);
    }
  }

  if (Array.isArray(payload.failure_codes)) {
    const failureCodes = payload.failure_codes
      .map(safeStatusValue)
      .filter(Boolean)
      .slice(0, 20);
    if (failureCodes.length > 0) {
      parts.push(`failure_codes=${failureCodes.join(',')}`);
    }
  }

  return parts.join(' ');
}
