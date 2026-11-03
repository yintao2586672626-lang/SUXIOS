import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { compileFrontendTemplate } from '../../scripts/lib/frontend_template_build.mjs';

const app = fs.readFileSync('public/app-main.js', 'utf8');
const template = fs.readFileSync('resources/frontend/templates/fragments/24-page-ctrip-ebooking.html', 'utf8');
const vue = { console };
vm.runInNewContext(fs.readFileSync('public/vue.runtime.global.prod.js', 'utf8'), vue);
const formatterSource = app.slice(app.indexOf('const ctripTrafficChannelText ='), app.indexOf('const ctripTrafficChannelSecondaryText ='));
const formatter = {
  formatOptionalNumber: value => Number.isFinite(value) ? String(value) : '未返回',
  formatOptionalPercent: value => Number.isFinite(value) ? `${value}%` : '未返回',
};
vm.runInNewContext(`${formatterSource}; format = ctripTrafficChannelText;`, formatter);
const fields = ['ctripOrderEstimate', 'qunarOrderEstimate', 'ctripUndistributedOrderEstimate'];

test('available reference estimates stay numeric despite an unverified source badge', () => {
  for (const field of fields) {
    for (const value of [0, 2, 57]) {
      assert.equal(formatter.format({ [field]: value, channelOrderBreakdownMeta: { status: 'source_unverified' } }, { field }), String(value));
    }
    for (const value of [null, undefined, NaN, Infinity]) {
      assert.equal(formatter.format({ [field]: value, channelOrderBreakdownMeta: { status: 'source_unverified' } }, { field }), '未核验');
    }
  }
});

test('the actual sales total cell prefers an available number and retains real missing states', () => {
  const cells = [...template.matchAll(/<td class="ctrip-sales-order-cell px-3[\s\S]*?<\/td>/g)].map(match => match[0]);
  assert.equal(cells.length, 2, 'both current and historical tables must use the same presentation');
  const leafText = node => {
    if (typeof node === 'string') return node;
    if (!node || typeof node !== 'object') return '';
    return Array.isArray(node.children) ? node.children.map(leafText).join('') : typeof node.children === 'string' ? node.children : '';
  };
  for (const cell of cells) {
    const render = Function('Vue', compileFrontendTemplate(`<table v-if="visible"><tbody><tr>${cell}</tr></tbody></table>`))(vue.Vue);
    for (const [value, status, expected] of [[57, 'source_unverified', '57'], [0, 'source_unverified', '0'], [null, 'source_unverified', '未核验'], [null, 'input_missing', '缺来源'], [NaN, 'source_unverified', '未核验']]) {
      const node = render({ visible: true, hotel: { totalOrderIncludingCancelledEstimate: value, channelOrderBreakdownMeta: { status } }, formatOptionalNumber: formatter.formatOptionalNumber }, []);
      assert.equal(leafText(node).trim(), expected);
    }
  }
});
