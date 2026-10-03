import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { inspectCtripProfileFieldConfigPanel } from '../../scripts/lib/ctrip_profile_panel_contract.mjs';
import { readFrontendContractSource } from './helpers/frontend_source.mjs';

const input = {
  entry: readFrontendContractSource(),
  component: readFileSync('public/components/online-data/ctrip-profile-field-config-panel.js', 'utf8'),
  template: readFileSync('resources/frontend/templates/components/ctrip-profile-field-config-panel.html', 'utf8'),
};

test('Ctrip profile panel guard accepts the current compiled admin-only context proxy', () => {
  assert.equal(inspectCtripProfileFieldConfigPanel(input), true);
});

test('Ctrip profile panel guard rejects a missing lazy loader or component registration', () => {
  assert.equal(inspectCtripProfileFieldConfigPanel({
    ...input,
    entry: input.entry.replaceAll('const ensureCtripProfileFieldConfigPanelReady = async () => {', 'missing-loader'),
  }), false);
  assert.equal(inspectCtripProfileFieldConfigPanel({
    ...input,
    component: input.component.replaceAll('CtripProfileFieldConfigPanelBody', 'MissingPanelBody'),
  }), false);
});

test('Ctrip profile panel guard rejects marker-only source without executable context behavior', () => {
  assert.equal(inspectCtripProfileFieldConfigPanel({
    ...input,
    component: '// CtripProfileFieldConfigPanelBody render: "data-testid":"ctrip-profile-field-config-panel" new Proxy getOwnPropertyDescriptor',
  }), false);
});

test('Ctrip profile panel guard rejects a context proxy that cannot reflect enumerable properties', () => {
  assert.equal(inspectCtripProfileFieldConfigPanel({
    ...input,
    component: input.component.replaceAll('getOwnPropertyDescriptor', 'missingPropertyDescriptor'),
  }), false);
});
