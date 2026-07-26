import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

test('Dingdandao bootstrap and daily collector are wrapped by one gateway-owned Profile lease', async () => {
  const [
    wrapper,
    bootstrap,
    bridge,
    pipeline,
    once,
  ] = await Promise.all([
    readFile(new URL(
      '../../scripts/run_dingdandao_profile_lease_collection.php',
      import.meta.url,
    ), 'utf8'),
    readFile(new URL(
      '../../scripts/run_dingdandao_binding_bootstrap.php',
      import.meta.url,
    ), 'utf8'),
    readFile(new URL(
      '../../scripts/cloud_browser_gateway_bridge.php',
      import.meta.url,
    ), 'utf8'),
    readFile(new URL(
      '../../scripts/run_dingdandao_notification_pipeline.php',
      import.meta.url,
    ), 'utf8'),
    readFile(new URL(
      '../../scripts/run_dingdandao_collection_once.sh',
      import.meta.url,
    ), 'utf8'),
  ]);

  const wrapperOpen = wrapper.indexOf("'/v1/profile-lease/open'");
  const wrapperCollector = wrapper.indexOf('runCollectorProcess(');
  const wrapperClose = wrapper.indexOf("'/v1/profile-lease/close'");
  assert.ok(wrapperOpen > 0);
  assert.ok(wrapperCollector > wrapperOpen);
  assert.ok(wrapperClose > wrapperCollector);
  assert.match(wrapper, /'lease_kind' => 'daily_collection'/);
  assert.match(wrapper, /\$expectedCollectorScript = realpath\(/);
  assert.match(wrapper, /hash_equals\(\$expectedCollectorScript, \$resolvedCollectorScript\)/);
  assert.match(wrapper, /'external_browser_required'[\s\S]{0,80}false/);
  assert.match(wrapper, /'user_browser_closed'[\s\S]{0,80}false/);
  assert.match(wrapper, /\$profileLeaseStatus = 'open'/);
  assert.match(wrapper, /\$profileLeaseStatus = 'closed'/);
  assert.match(wrapper, /\$profileLeaseStatus = 'closure_unverified'/);
  assert.match(
    wrapper,
    /'profile_lease_status' => \$profileLeaseStatus/,
  );
  assert.doesNotMatch(wrapper, /closed_or_expiring/);
  assert.doesNotMatch(wrapper, /echo[\s\S]{0,80}\$controlToken/);

  const bootstrapOpen = bootstrap.indexOf("'/v1/profile-lease/open'");
  const identityProbe = bootstrap.indexOf('runIdentityProbe(');
  const bootstrapClose = bootstrap.indexOf("'/v1/profile-lease/close'");
  assert.ok(bootstrapOpen > 0);
  assert.ok(identityProbe > bootstrapOpen);
  assert.ok(bootstrapClose > identityProbe);
  assert.match(bootstrap, /'lease_kind' => 'binding_identity'/);
  assert.match(bootstrap, /'user_browser_closed'[\s\S]{0,80}false/);

  assert.match(bridge, /'validate_dingdandao_binding_lease'/);
  assert.match(bridge, /bindingBootstrapScope/);

  assert.match(pipeline, /run_dingdandao_profile_lease_collection\.php/);
  assert.match(pipeline, /--collector-script=/);
  assert.match(once, /run_dingdandao_profile_lease_collection\.php/);
  assert.match(once, /--collector-script=/);
});
