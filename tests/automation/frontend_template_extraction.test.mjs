import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { parse } from '@vue/compiler-dom';
import { compileFrontendTemplate } from '../../scripts/lib/frontend_template_build.mjs';
import { loadFrontendTemplateSource } from '../../scripts/lib/frontend_template_source.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const source = loadFrontendTemplateSource(repoRoot);
const raw = fs.readFileSync(path.join(repoRoot, 'resources/frontend/templates/fragments/17-page-ops-track.html'), 'utf8');
const find = (node, predicate) => predicate(node) ? node : node.children?.map(child => find(child, predicate)).find(Boolean);
const testId = value => node => node.type === 1 && node.props.some(prop =>
  prop.type === 6 && prop.name === 'data-testid' && prop.value?.content === value);

test('operating-goal extraction retains its complete element without consuming parent closures or sibling states', () => {
  const originalGoal = find(parse(raw), testId('operating-goal-intervention-learning'));
  const view = source.businessClosureViews.find(item => item.id === 'operating-goal-intervention');
  assert.ok(originalGoal);
  assert.equal(view.template.trim(), originalGoal.loc.source);
  assert.doesNotThrow(() => compileFrontendTemplate(view.template));
  const transformed = source.runtimeFragments.find(item => item.id === 'page-ops-track');
  const ast = parse(transformed.source);
  const advanced = find(ast, testId('operation-advanced-tools'));
  assert.ok(advanced);
  assert.ok(find(advanced, node => node.type === 1 && node.tag === 'manager-capability-panel'));
  assert.ok(find(advanced, node => node.type === 1 && node.tag === 'operating-goal-intervention-view'));
  assert.equal(find(advanced, testId('operating-memory-panel')), undefined);
  assert.ok(find(ast, testId('operating-memory-panel')));
  for (const expression of ['operationLoading.actions', 'operationError.actions']) {
    assert.ok(find(ast, node => node.type === 1 && node.props.some(prop =>
      prop.type === 7 && prop.name === 'if' && prop.exp?.content === expression)));
  }
});

test('current runtime fragments compile after business component extraction without writing generated artifacts', () => {
  assert.doesNotThrow(() => compileFrontendTemplate(source.template));
});
