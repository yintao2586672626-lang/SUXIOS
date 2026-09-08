import assert from 'node:assert/strict';
import test from 'node:test';
import { verifyOuterDeliveryPolicy } from '../../hooks/lib/outer_delivery_policy.mjs';

const legacy = [
  'Feature delivery gets roughly 80–90% of effort.',
  'After three targeted inspections without new decisive evidence, take the smallest safe action.',
];
const current = [
  'Prioritize accurate, complete user-visible outcomes.',
  'When inspection stops producing evidence, change the hypothesis or observation method.',
];

test('published legacy and current outer delivery policies both pass', () => {
  assert.deepEqual(verifyOuterDeliveryPolicy(legacy.join('\n')), []);
  assert.deepEqual(verifyOuterDeliveryPolicy(current.join('\n')), []);
});

test('each policy version still requires delivery and inspection clauses', () => {
  for (const version of [legacy, current]) {
    assert.deepEqual(verifyOuterDeliveryPolicy(version[0]), [
      'outer AGENTS.md is missing required evidence-driven inspection policy',
    ]);
    assert.deepEqual(verifyOuterDeliveryPolicy(version[1]), [
      'outer AGENTS.md is missing required delivery completeness policy',
    ]);
  }
  assert.equal(verifyOuterDeliveryPolicy('').length, 2);
  assert.equal(verifyOuterDeliveryPolicy('Prioritize speed. Stop inspecting.').length, 2);
});
