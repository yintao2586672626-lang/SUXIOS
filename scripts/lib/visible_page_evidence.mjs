import { createHash } from 'node:crypto';

const DEFAULT_STORAGE_TARGET = 'raw_data.visible_page_evidence';
const VALID_EVIDENCE_TYPES = new Set(['label', 'rank', 'tip', 'summary', 'status']);

export function extractVisiblePageEvidence(html, contracts = [], options = {}) {
  const sourceHtml = String(html || '');
  const normalizedContracts = (Array.isArray(contracts) ? contracts : [])
    .map((contract, index) => normalizeVisibleEvidenceContract(contract, index));
  const htmlHash = hashText(sourceHtml);
  const records = [];
  const missing = [];

  for (const contract of normalizedContracts) {
    const context = {
      htmlHash,
      platform: options.platform || contract.platform || '',
      section: options.section || contract.section || '',
      sourcePage: options.sourcePage || contract.sourcePage || '',
    };
    if (contract.invalid) {
      missing.push(buildMissing(contract, 'invalid_contract', context));
      continue;
    }
    const result = extractVisibleEvidenceContract(sourceHtml, contract, {
      ...context,
    });
    if (result.record) {
      records.push(result.record);
    }
    if (result.missing) {
      missing.push(result.missing);
    }
  }

  return {
    status: sourceHtml.trim() === ''
      ? 'missing_html'
      : (missing.length === 0 ? 'ok' : (records.length > 0 ? 'partial' : 'missing')),
    source: 'visible_page_html_fixture',
    parser: 'visible_page_evidence',
    html_hash: htmlHash,
    summary: {
      contract_count: normalizedContracts.length,
      record_count: records.length,
      missing_count: missing.length,
    },
    records,
    missing,
  };
}

export function textFromVisibleHtml(html, entityErrors = []) {
  return decodeHtmlEntities(
    String(html || '')
      .replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, ' ')
      .replace(/<style\b[^>]*>[\s\S]*?<\/style>/gi, ' ')
      .replace(/<!--[\s\S]*?-->/g, ' ')
      .replace(/<[^>]+>/g, ' '),
    entityErrors,
  ).replace(/\s+/g, ' ').trim();
}

export function selectVisibleText(html, selector, entityErrors = []) {
  const matches = selectHtmlBlocks(html, selector);
  if (matches.length === 0) {
    return '';
  }
  return textFromVisibleHtml(matches[0].inner_html, entityErrors);
}

export function selectHtmlBlocks(html, selector) {
  const parts = String(selector || '').trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) {
    return [];
  }

  let scopes = [{ inner_html: String(html || '') }];
  for (const part of parts) {
    const next = [];
    for (const scope of scopes) {
      next.push(...selectDirectHtmlBlocks(scope.inner_html, part));
    }
    scopes = next;
    if (scopes.length === 0) {
      break;
    }
  }
  return scopes;
}

function extractVisibleEvidenceContract(html, contract, context) {
  const entityErrors = [];
  const rootMatches = selectHtmlBlocks(html, contract.selector);
  if (rootMatches.length === 0) {
    return {
      missing: buildMissing(contract, 'selector_not_found', context),
    };
  }

  const root = rootMatches[0];
  const valueSelector = contract.valueSelector || '';
  const valueText = valueSelector
    ? selectVisibleText(root.inner_html, valueSelector, entityErrors)
    : textFromVisibleHtml(root.inner_html, entityErrors);

  if (!valueText) {
    return {
      missing: buildMissing(contract, 'empty_value', context),
    };
  }
  const labelText = contract.labelSelector
    ? selectVisibleText(root.inner_html, contract.labelSelector, entityErrors)
    : contract.label;
  const tipText = contract.tipSelector
    ? selectVisibleText(root.inner_html, contract.tipSelector, entityErrors)
    : '';
  if (entityErrors.length > 0) {
    return {
      missing: buildMissing(contract, 'invalid_html_entity', {
        ...context,
        entityErrors,
      }),
    };
  }
  if (containsSensitiveVisibleText([valueText, labelText, tipText].join(' '))) {
    return {
      missing: buildMissing(contract, 'sensitive_text_rejected', context),
    };
  }

  return {
    record: {
      key: contract.key,
      label: labelText || contract.label || contract.key,
      value: valueText,
      evidence_type: contract.type,
      evidence_scope: 'visible_page_only',
      confidence: 'visible_page_supplement',
      platform: context.platform || contract.platform || '',
      section: context.section || contract.section || '',
      source_page: context.sourcePage || contract.sourcePage || '',
      selector: contract.selector,
      value_selector: valueSelector,
      source_path: `selector:${contract.selector}${valueSelector ? `>${valueSelector}` : ''}`,
      missing_state: 'ok',
      storage_target: contract.storageTarget || DEFAULT_STORAGE_TARGET,
      can_fill_business_metric: false,
      raw_data: {
        source: 'visible_page_html_fixture',
        parser: 'visible_page_evidence',
        html_hash: context.htmlHash,
        text_excerpt: valueText.slice(0, 240),
        ...(tipText ? { visible_tip: tipText.slice(0, 240) } : {}),
      },
    },
  };
}

function normalizeVisibleEvidenceContract(contract, index = 0) {
  const fallbackKey = `invalid_contract_${index + 1}`;
  if (!contract || typeof contract !== 'object') {
    return {
      key: fallbackKey,
      selector: '',
      type: 'label',
      label: fallbackKey,
      valueSelector: '',
      labelSelector: '',
      tipSelector: '',
      platform: '',
      section: '',
      sourcePage: '',
      storageTarget: DEFAULT_STORAGE_TARGET,
      invalid: true,
      invalidFields: ['contract'],
      invalidReason: 'contract must be an object',
    };
  }
  const key = String(contract.key || contract.field_key || contract.id || '').trim();
  const selector = String(contract.selector || '').trim();
  const invalidFields = [
    ...(!key ? ['key'] : []),
    ...(!selector ? ['selector'] : []),
  ];
  const type = String(contract.type || contract.evidence_type || 'label').trim().toLowerCase();
  return {
    key: key || fallbackKey,
    selector,
    type: VALID_EVIDENCE_TYPES.has(type) ? type : 'label',
    label: String(contract.label || key || fallbackKey).trim(),
    valueSelector: String(contract.valueSelector || contract.value_selector || '').trim(),
    labelSelector: String(contract.labelSelector || contract.label_selector || '').trim(),
    tipSelector: String(contract.tipSelector || contract.tip_selector || '').trim(),
    platform: String(contract.platform || '').trim(),
    section: String(contract.section || '').trim(),
    sourcePage: String(contract.sourcePage || contract.source_page || '').trim(),
    storageTarget: String(contract.storageTarget || contract.storage_target || DEFAULT_STORAGE_TARGET).trim(),
    invalid: invalidFields.length > 0,
    invalidFields,
    invalidReason: invalidFields.length > 0 ? `missing required field(s): ${invalidFields.join(', ')}` : '',
  };
}

function buildMissing(contract, missingState, context) {
  return {
    key: contract.key,
    label: contract.label || contract.key,
    evidence_type: contract.type,
    evidence_scope: 'visible_page_only',
    platform: context.platform || contract.platform || '',
    section: context.section || contract.section || '',
    source_page: context.sourcePage || contract.sourcePage || '',
    selector: contract.selector,
    value_selector: contract.valueSelector || '',
    missing_state: missingState,
    storage_target: contract.storageTarget || DEFAULT_STORAGE_TARGET,
    can_fill_business_metric: false,
    raw_data: {
      source: 'visible_page_html_fixture',
      parser: 'visible_page_evidence',
      html_hash: context.htmlHash,
      ...(contract.invalidReason ? { contract_error: contract.invalidReason } : {}),
      ...(contract.invalidFields?.length ? { invalid_fields: contract.invalidFields } : {}),
      ...(context.entityErrors?.length ? { entity_errors: context.entityErrors } : {}),
    },
  };
}

function selectDirectHtmlBlocks(html, selector) {
  const parsed = parseSimpleSelector(selector);
  if (!parsed) {
    return [];
  }

  const source = String(html || '');
  const tagRegex = /<([a-zA-Z][\w:-]*)(\s[^<>]*?)?>/g;
  const blocks = [];
  let match;
  while ((match = tagRegex.exec(source)) !== null) {
    const full = match[0];
    if (/^<\//.test(full)) {
      continue;
    }
    const tagName = match[1];
    const attrText = match[2] || '';
    const attrs = parseAttributes(attrText);
    if (!matchesSimpleSelector(tagName, attrs, parsed)) {
      continue;
    }
    const openEnd = tagRegex.lastIndex;
    const selfClosing = /\/\s*>$/.test(full);
    const close = selfClosing
      ? { closeStart: openEnd, closeEnd: openEnd }
      : findClosingTag(source, tagName, openEnd);
    blocks.push({
      tag: tagName.toLowerCase(),
      attrs,
      outer_html: source.slice(match.index, close.closeEnd),
      inner_html: source.slice(openEnd, close.closeStart),
    });
    tagRegex.lastIndex = Math.max(tagRegex.lastIndex, close.closeEnd);
  }
  return blocks;
}

function parseSimpleSelector(selector) {
  let rest = String(selector || '').trim();
  if (!rest || rest.includes(',')) {
    return null;
  }

  const attrs = [];
  rest = rest.replace(/\[([^\]=\s]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\]]+)))?\]/g, (_, name, dq, sq, bare) => {
    attrs.push({
      name: String(name || '').trim().toLowerCase(),
      value: dq ?? sq ?? (bare ? String(bare).trim().replace(/^['"]|['"]$/g, '') : null),
    });
    return '';
  });

  let id = '';
  rest = rest.replace(/#([a-zA-Z0-9_-]+)/g, (_, value) => {
    id = value;
    return '';
  });

  const classes = [];
  rest = rest.replace(/\.([a-zA-Z0-9_-]+)/g, (_, value) => {
    classes.push(value);
    return '';
  });

  const tag = rest.trim().toLowerCase();
  if (tag && !/^[a-zA-Z][\w:-]*$/.test(tag)) {
    return null;
  }
  return { tag, id, classes, attrs };
}

function matchesSimpleSelector(tagName, attrs, selector) {
  if (selector.tag && selector.tag !== String(tagName || '').toLowerCase()) {
    return false;
  }
  if (selector.id && String(attrs.id || '') !== selector.id) {
    return false;
  }
  const classSet = new Set(String(attrs.class || '').split(/\s+/).filter(Boolean));
  for (const className of selector.classes) {
    if (!classSet.has(className)) {
      return false;
    }
  }
  for (const attr of selector.attrs) {
    if (!Object.prototype.hasOwnProperty.call(attrs, attr.name)) {
      return false;
    }
    if (attr.value !== null && String(attrs[attr.name]) !== attr.value) {
      return false;
    }
  }
  return true;
}

function parseAttributes(attrText) {
  const attrs = {};
  const attrRegex = /([a-zA-Z_:][\w:.-]*)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'>]+)))?/g;
  let match;
  while ((match = attrRegex.exec(String(attrText || ''))) !== null) {
    attrs[String(match[1]).toLowerCase()] = decodeHtmlEntities(match[2] ?? match[3] ?? match[4] ?? '');
  }
  return attrs;
}

function findClosingTag(html, tagName, fromIndex) {
  const source = String(html || '');
  const tag = String(tagName || '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const tokenRegex = new RegExp(`</?${tag}(?:\\s[^<>]*?)?>`, 'gi');
  tokenRegex.lastIndex = fromIndex;
  let depth = 1;
  let match;
  while ((match = tokenRegex.exec(source)) !== null) {
    const token = match[0];
    if (/^<\//.test(token)) {
      depth -= 1;
      if (depth === 0) {
        return { closeStart: match.index, closeEnd: tokenRegex.lastIndex };
      }
      continue;
    }
    if (!/\/\s*>$/.test(token)) {
      depth += 1;
    }
  }
  return { closeStart: fromIndex, closeEnd: fromIndex };
}

function containsSensitiveVisibleText(text) {
  const value = String(text || '');
  return /cookie|authorization|token|password|spidertoken|mtgsig/i.test(value)
    || /1[3-9]\d{9}/.test(value)
    || /\b\d{15}(\d{2}[\dXx])?\b/.test(value);
}

function decodeHtmlEntities(text, entityErrors = []) {
  const named = {
    amp: '&',
    lt: '<',
    gt: '>',
    quot: '"',
    apos: "'",
    nbsp: ' ',
  };
  return String(text || '').replace(/&(#x?[0-9a-fA-F]+|#[^;]+|[a-zA-Z]+);/g, (_, entity) => {
    if (entity.startsWith('#x') || entity.startsWith('#X')) {
      return decodeNumericHtmlEntity(entity, 16, entityErrors);
    }
    if (entity.startsWith('#')) {
      return decodeNumericHtmlEntity(entity, 10, entityErrors);
    }
    return named[entity] ?? `&${entity};`;
  });
}

function decodeNumericHtmlEntity(entity, base, entityErrors) {
  const rawEntity = `&${entity};`;
  const digits = base === 16 ? entity.slice(2) : entity.slice(1);
  const validDigits = base === 16
    ? /^[0-9a-fA-F]+$/.test(digits)
    : /^[0-9]+$/.test(digits);
  const codePoint = validDigits ? Number.parseInt(digits, base) : Number.NaN;

  if (!Number.isInteger(codePoint)
    || codePoint < 0
    || codePoint > 0x10ffff
    || (codePoint >= 0xd800 && codePoint <= 0xdfff)) {
    entityErrors.push({
      entity: rawEntity,
      reason: 'invalid_numeric_entity',
    });
    return rawEntity;
  }

  return String.fromCodePoint(codePoint);
}

function hashText(text) {
  return createHash('sha256').update(String(text || '')).digest('hex');
}
