import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const template = readFileSync(
  new URL('../../resources/frontend/app-template.html', import.meta.url),
  'utf8',
);
const concern = readFileSync(
  new URL('../../app/controller/concern/CtripReviewOrderMatchConcern.php', import.meta.url),
  'utf8',
);
const policy = readFileSync(
  new URL('../../app/service/OtaReviewRiskPolicyService.php', import.meta.url),
  'utf8',
);

test('Ctrip review-order matching is presented as privacy-policy disabled', () => {
  const tab = template.match(/<button[^>]*data-testid="ctrip-review-match-policy-disabled"[^>]*>[\s\S]*?<\/button>/)?.[0] ?? '';

  assert.ok(tab, 'the Ctrip review matching tab must expose a stable disabled-policy marker');
  assert.match(tab, /disabled/);
  assert.doesNotMatch(tab, /@click=/);
  assert.match(tab, /评价匹配（隐私保护停用）/);
  assert.match(template, /不会反查匿名住客身份，也不会执行评价与订单自动绑定/);
});

test('Ctrip identity lookup and review-order matching remain blocked server-side', () => {
  for (const operation of [
    'ctrip_review_order_lookup',
    'ctrip_review_orderer_identity_preview',
    'ctrip_review_order_match_automation',
    'ctrip_review_order_manual_bind',
    'ctrip_review_order_match_closure_check',
  ]) {
    assert.match(concern, new RegExp(`reviewRiskPolicyBlockedResponse\\('${operation}'`));
  }
  assert.match(policy, /blocked_by_review_privacy_policy/);
});
