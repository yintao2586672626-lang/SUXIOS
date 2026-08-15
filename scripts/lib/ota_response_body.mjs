const DEFAULT_RESPONSE_BODY_TIMEOUT_MS = 5000;
const MAX_RESPONSE_BODY_TIMEOUT_MS = 30000;

export async function readOtaResponseTextWithTimeout(response, options = {}) {
  if (!response || typeof response.text !== 'function') {
    throw new TypeError('ota_response_text_reader_unavailable');
  }

  const requested = Number(options.timeoutMs || options.timeout_ms || DEFAULT_RESPONSE_BODY_TIMEOUT_MS);
  const timeoutMs = Number.isFinite(requested)
    ? Math.max(1, Math.min(MAX_RESPONSE_BODY_TIMEOUT_MS, Math.trunc(requested)))
    : DEFAULT_RESPONSE_BODY_TIMEOUT_MS;
  let timeout = null;

  try {
    return await Promise.race([
      Promise.resolve().then(() => response.text()),
      new Promise((resolve, reject) => {
        timeout = setTimeout(() => {
          const error = new Error('ota_response_body_timeout');
          error.code = 'OTA_RESPONSE_BODY_TIMEOUT';
          reject(error);
        }, timeoutMs);
      }),
    ]);
  } finally {
    if (timeout !== null) {
      clearTimeout(timeout);
    }
  }
}
