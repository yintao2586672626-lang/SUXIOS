import { createHash } from 'node:crypto';

const MAX_UNWRAP_DEPTH = 3;

export function parseCtripResponseBody(text, contentType = '') {
  const source = String(text ?? '').replace(/^\uFEFF/, '').trim();
  const commonEvidence = {
    content_type: classifyContentType(contentType),
    text_length: source.length,
    sensitive_values_exposed: false,
  };

  if (!source) {
    return parseFailure('empty_body', 'empty', source, commonEvidence);
  }
  if (looksLikeHtml(source, contentType)) {
    return parseFailure('html_document', 'html', source, commonEvidence);
  }

  const xssiBody = stripXssiPrefix(source);
  if (xssiBody !== source) {
    const parsed = parseJsonValue(xssiBody);
    if (parsed.ok) {
      return parseSuccess(parsed.body, 'xssi_json', commonEvidence);
    }
  }

  const jsonpBody = extractJsonpBody(source);
  if (jsonpBody !== null) {
    const parsed = parseJsonValue(jsonpBody);
    if (parsed.ok) {
      return parseSuccess(parsed.body, 'jsonp', commonEvidence);
    }
  }

  const parsed = parseJsonValue(source);
  if (parsed.ok) {
    return parseSuccess(parsed.body, parsed.unwrapped ? 'json_string' : 'json', commonEvidence);
  }

  return parseFailure('unrecognized_text', 'unknown', source, commonEvidence);
}

function parseJsonValue(source) {
  let current = source;
  let unwrapped = false;
  for (let depth = 0; depth < MAX_UNWRAP_DEPTH; depth += 1) {
    let parsed;
    try {
      parsed = JSON.parse(current);
    } catch {
      return { ok: false, body: null, unwrapped };
    }
    if (parsed && typeof parsed === 'object') {
      return { ok: true, body: parsed, unwrapped };
    }
    if (typeof parsed !== 'string') {
      return { ok: false, body: null, unwrapped };
    }
    current = parsed.replace(/^\uFEFF/, '').trim();
    unwrapped = true;
  }
  return { ok: false, body: null, unwrapped };
}

function stripXssiPrefix(source) {
  return source
    .replace(/^\)\]\}',?\s*/u, '')
    .replace(/^(?:while\s*\(\s*1\s*\)|for\s*\(\s*;\s*;\s*\))\s*;\s*/u, '');
}

function extractJsonpBody(source) {
  const match = source.match(/^(?:\/\*\*\/\s*)?[$A-Z_a-z][$\w]*(?:\.[$A-Z_a-z][$\w]*)*\s*\(([\s\S]*)\)\s*;?\s*$/u);
  return match ? match[1].trim() : null;
}

function looksLikeHtml(source, contentType) {
  return /\b(?:text\/html|application\/xhtml\+xml)\b/iu.test(String(contentType || ''))
    || /^(?:<!doctype\s+html\b|<html\b|<head\b|<body\b)/iu.test(source);
}

function classifyContentType(contentType) {
  const value = String(contentType || '').toLowerCase();
  if (value.includes('json')) return 'json';
  if (value.includes('javascript')) return 'javascript';
  if (value.includes('html')) return 'html';
  if (value.includes('text/plain')) return 'text';
  return value ? 'other' : 'unknown';
}

function parseSuccess(body, format, commonEvidence) {
  return {
    ok: true,
    body,
    evidence: {
      ...commonEvidence,
      status: 'parsed',
      reason: '',
      format,
    },
  };
}

function parseFailure(reason, format, source, commonEvidence) {
  return {
    ok: false,
    body: null,
    evidence: {
      ...commonEvidence,
      status: 'parse_failed',
      reason,
      format,
      body_sha256: createHash('sha256').update(source).digest('hex').slice(0, 16),
    },
  };
}
