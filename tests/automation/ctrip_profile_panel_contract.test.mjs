import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { inspectCtripProfileFieldConfigPanel } from '../../scripts/lib/ctrip_profile_panel_contract.mjs';
import { readFrontendContractSource } from './helpers/frontend_source.mjs';

const actual = {
  entry: readFrontendContractSource(),
  component: readFileSync('public/components/online-data/ctrip-profile-field-config-panel.js', 'utf8'),
  template: readFileSync('resources/frontend/templates/components/ctrip-profile-field-config-panel.html', 'utf8'),
};
const override = code => `${actual.component}\n${code}`;

test('the current compiled Ctrip field panel satisfies the shared lazy-load and editable-admin contract', () => {
  assert.equal(inspectCtripProfileFieldConfigPanel(actual), true);
});

test('missing lazy version, loading marker, source template marker or body registration fails the contract', () => {
  for (const modified of [
    { entry: actual.entry.replaceAll('components/online-data/ctrip-profile-field-config-panel.js?v=20260613-profile-template-split', 'unversioned-field-panel.js') },
    { entry: actual.entry.replaceAll("'data-testid': 'ctrip-profile-field-config-loading'", "'data-testid': 'unmarked-loading'") },
    { template: actual.template.replaceAll('data-testid="ctrip-profile-field-config-panel"', '') },
    { component: actual.component.replaceAll('CtripProfileFieldConfigPanelBody', 'WrongBody') },
  ]) assert.equal(inspectCtripProfileFieldConfigPanel({ ...actual, ...modified }), false);
});

test('compiled body mutations that lose root reads, root writes, descriptors or admin exclusion are rejected', () => {
  const name = 'window.SUXI_ONLINE_DATA_COMPONENTS.CtripProfileFieldConfigPanelBody';
  for (const code of [
    `${name}.render = undefined;`,
    `${name}.setup = () => new Proxy({}, { get: () => undefined });`,
    `${name}.setup = ({ctx}) => new Proxy({}, {get: (_t,k) => k==='ctx' ? ctx : ctx[k], set: () => true});`,
    `${name}.setup = ({ctx}) => new Proxy({}, {get: (_t,k) => k==='ctx' ? ctx : ctx[k], set: (_t,k,v) => (ctx[k]=v,true), has: () => true});`,
    `${name}.render = () => ({type:'visible-admin-panel'});`,
  ]) assert.equal(inspectCtripProfileFieldConfigPanel({ ...actual, component: override(code) }), false);
});
