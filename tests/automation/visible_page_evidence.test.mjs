import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

import {
  extractVisiblePageEvidence,
} from '../../scripts/lib/visible_page_evidence.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const fixturePath = path.resolve(__dirname, '../fixtures/visible-page-evidence/ctrip-visible-evidence.html');
const fixtureHtml = fs.readFileSync(fixturePath, 'utf8');

function buildContracts() {
  return [
    {
      key: 'traffic_rank_visible',
      label: '流量排名',
      type: 'rank',
      selector: '[data-ota-evidence="traffic-rank"]',
      labelSelector: '[data-field="label"]',
      valueSelector: '[data-field="value"]',
      tipSelector: '[data-field="tip"]',
      platform: 'ctrip',
      section: 'traffic_report',
      sourcePage: 'https://ebooking.ctrip.com/datacenter/inland/businessreport/flowdata?microJump=true',
    },
    {
      key: 'pricing_tip_visible',
      label: '价格提示',
      type: 'tip',
      selector: '[data-ota-evidence="pricing-tip"]',
      labelSelector: '[data-field="label"]',
      valueSelector: '[data-field="value"]',
      tipSelector: '[data-field="tip"]',
      platform: 'ctrip',
      section: 'traffic_report',
      sourcePage: 'https://ebooking.ctrip.com/datacenter/inland/businessreport/flowdata?microJump=true',
    },
    {
      key: 'supply_label_visible',
      label: '房态标签',
      type: 'label',
      selector: '[data-ota-evidence="supply-label"]',
      labelSelector: '[data-field="label"]',
      valueSelector: '[data-field="value"]',
      platform: 'ctrip',
      section: 'traffic_report',
      sourcePage: 'https://ebooking.ctrip.com/datacenter/inland/businessreport/flowdata?microJump=true',
    },
  ];
}

test('extracts visible page evidence as supplement-only records', () => {
  const result = extractVisiblePageEvidence(fixtureHtml, buildContracts());

  assert.equal(result.status, 'ok');
  assert.equal(result.source, 'visible_page_html_fixture');
  assert.equal(result.parser, 'visible_page_evidence');
  assert.match(result.html_hash, /^[a-f0-9]{64}$/);
  assert.equal(result.summary.contract_count, 3);
  assert.equal(result.summary.record_count, 3);
  assert.equal(result.summary.missing_count, 0);
  assert.equal(result.missing.length, 0);

  for (const record of result.records) {
    assert.equal(record.evidence_scope, 'visible_page_only');
    assert.equal(record.confidence, 'visible_page_supplement');
    assert.equal(record.platform, 'ctrip');
    assert.equal(record.section, 'traffic_report');
    assert.equal(record.storage_target, 'raw_data.visible_page_evidence');
    assert.equal(record.can_fill_business_metric, false);
    assert.equal(record.missing_state, 'ok');
    assert.match(record.source_path, /^selector:/);
    assert.match(record.raw_data.html_hash, /^[a-f0-9]{64}$/);
  }

  const rank = result.records.find((record) => record.key === 'traffic_rank_visible');
  assert.equal(rank.label, '流量排名');
  assert.equal(rank.value, '同商圈第 12 名');
  assert.equal(rank.evidence_type, 'rank');
  assert.equal(rank.raw_data.visible_tip, '近 7 天曝光转化排名，仅作页面可见证据。');

  const priceTip = result.records.find((record) => record.key === 'pricing_tip_visible');
  assert.equal(priceTip.value, '本周末建议关注高峰价差');
  assert.equal(priceTip.evidence_type, 'tip');

  const supplyLabel = result.records.find((record) => record.key === 'supply_label_visible');
  assert.equal(supplyLabel.value, '库存偏紧');
  assert.equal(supplyLabel.evidence_type, 'label');
});

test('reports missing selector without fabricating values', () => {
  const result = extractVisiblePageEvidence(fixtureHtml, [
    {
      key: 'missing_visible_label',
      label: '缺失标签',
      type: 'label',
      selector: '[data-ota-evidence="missing"]',
      valueSelector: '[data-field="value"]',
    },
  ]);

  assert.equal(result.status, 'missing');
  assert.equal(result.records.length, 0);
  assert.equal(result.missing.length, 1);
  assert.equal(result.missing[0].missing_state, 'selector_not_found');
  assert.equal(result.missing[0].can_fill_business_metric, false);
});

test('reports empty visible values explicitly', () => {
  const result = extractVisiblePageEvidence(`
    <article data-ota-evidence="empty">
      <span data-field="label">空值提示</span>
      <strong data-field="value"> </strong>
    </article>
  `, [
    {
      key: 'empty_visible_value',
      label: '空值提示',
      type: 'tip',
      selector: '[data-ota-evidence="empty"]',
      valueSelector: '[data-field="value"]',
    },
  ]);

  assert.equal(result.status, 'missing');
  assert.equal(result.records.length, 0);
  assert.equal(result.missing.length, 1);
  assert.equal(result.missing[0].missing_state, 'empty_value');
});

test('rejects sensitive text before writing evidence records', () => {
  const result = extractVisiblePageEvidence(`
    <article data-ota-evidence="sensitive">
      <span data-field="label">公开提示</span>
      <strong data-field="value">页面可见提示</strong>
      <p data-field="tip">authorization token marker</p>
    </article>
  `, [
    {
      key: 'sensitive_visible_tip',
      label: '敏感提示',
      type: 'tip',
      selector: '[data-ota-evidence="sensitive"]',
      labelSelector: '[data-field="label"]',
      valueSelector: '[data-field="value"]',
      tipSelector: '[data-field="tip"]',
    },
  ]);

  assert.equal(result.status, 'missing');
  assert.equal(result.records.length, 0);
  assert.equal(result.missing.length, 1);
  assert.equal(result.missing[0].missing_state, 'sensitive_text_rejected');
});

test('reports invalid contracts instead of silently dropping them', () => {
  const result = extractVisiblePageEvidence(`
    <article data-ota-evidence="valid">
      <strong data-field="value">visible value</strong>
    </article>
  `, [
    {
      key: 'valid_visible_value',
      selector: '[data-ota-evidence="valid"]',
      valueSelector: '[data-field="value"]',
    },
    {
      key: 'missing_selector_contract',
      label: 'Missing selector contract',
    },
  ]);

  assert.equal(result.status, 'partial');
  assert.equal(result.summary.contract_count, 2);
  assert.equal(result.summary.record_count, 1);
  assert.equal(result.summary.missing_count, 1);
  assert.equal(result.missing[0].key, 'missing_selector_contract');
  assert.equal(result.missing[0].missing_state, 'invalid_contract');
  assert.deepEqual(result.missing[0].raw_data.invalid_fields, ['selector']);
});

test('reports missing contract key explicitly', () => {
  const result = extractVisiblePageEvidence(`
    <article data-ota-evidence="valid">
      <strong data-field="value">visible value</strong>
    </article>
  `, [
    {
      selector: '[data-ota-evidence="valid"]',
      valueSelector: '[data-field="value"]',
    },
  ]);

  assert.equal(result.status, 'missing');
  assert.equal(result.records.length, 0);
  assert.equal(result.summary.contract_count, 1);
  assert.equal(result.summary.missing_count, 1);
  assert.equal(result.missing[0].key, 'invalid_contract_1');
  assert.equal(result.missing[0].missing_state, 'invalid_contract');
  assert.deepEqual(result.missing[0].raw_data.invalid_fields, ['key']);
});

test('rejects invalid numeric html entities without crashing', () => {
  const result = extractVisiblePageEvidence(`
    <article data-ota-evidence="bad-entity">
      <strong data-field="value">&#999999999999999999999;</strong>
    </article>
  `, [
    {
      key: 'bad_entity',
      selector: '[data-ota-evidence="bad-entity"]',
      valueSelector: '[data-field="value"]',
    },
  ]);

  assert.equal(result.status, 'missing');
  assert.equal(result.records.length, 0);
  assert.equal(result.missing.length, 1);
  assert.equal(result.missing[0].missing_state, 'invalid_html_entity');
  assert.equal(result.missing[0].raw_data.entity_errors[0].entity, '&#999999999999999999999;');
});

test('rejects overlarge hex html entities and decodes legal entities', () => {
  const overlarge = extractVisiblePageEvidence(`
    <article data-ota-evidence="bad-hex">
      <strong data-field="value">&#x110000;</strong>
    </article>
  `, [
    {
      key: 'bad_hex_entity',
      selector: '[data-ota-evidence="bad-hex"]',
      valueSelector: '[data-field="value"]',
    },
  ]);

  assert.equal(overlarge.status, 'missing');
  assert.equal(overlarge.missing[0].missing_state, 'invalid_html_entity');
  assert.equal(overlarge.missing[0].raw_data.entity_errors[0].entity, '&#x110000;');

  const legal = extractVisiblePageEvidence(`
    <article data-ota-evidence="legal-entity">
      <strong data-field="value">A&amp;B &#x4E2D;</strong>
    </article>
  `, [
    {
      key: 'legal_entity',
      selector: '[data-ota-evidence="legal-entity"]',
      valueSelector: '[data-field="value"]',
    },
  ]);

  assert.equal(legal.status, 'ok');
  assert.equal(legal.records[0].value, 'A&B 中');
});
