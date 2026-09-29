import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync('scripts/run_molanxin_collection_preview.php', 'utf8');

test('missing Profile lease runner blocks preview before application or collection startup', () => {
  const guard = source.indexOf('if (!is_file($profileLeaseRunner))');
  assert.ok(guard > 0);
  assert.ok(guard < source.indexOf("require $root . '/vendor/autoload.php'"));
  assert.ok(guard < source.indexOf('(new App($root))->initialize()'));
  assert.ok(guard < source.indexOf('$runs->start('));
  assert.match(source.slice(guard, source.indexOf('require ', guard)), /molanxinFail\('molanxin_collection_profile_lease_runner_unavailable', 2\)/);
  assert.match(source, /\$phpBinary,\s*\$profileLeaseRunner,/);
  const failure = source.slice(source.indexOf('function molanxinFail('));
  assert.match(failure, /'status' => 'blocked'/);
  for (const key of ['dispatch_requested', 'message_sent', 'webhook_read']) {
    assert.match(failure, new RegExp(`'${key}' => false`));
  }
});
