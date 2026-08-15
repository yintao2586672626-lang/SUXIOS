import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

test('cloud browser bridge exposes the exact OTA collection validation action', async () => {
  const bridge = await readFile(
    new URL('../../scripts/cloud_browser_gateway_bridge.php', import.meta.url),
    'utf8',
  );

  assert.match(
    bridge,
    /'validate_ota_collection'\s*=>\s*array_key_exists\('data_source_id', \$input\)/,
  );
  assert.match(bridge, /\? \$service->validateOtaDataSourceCollectionProfile\(/);
  assert.match(bridge, /: \$service->validateOtaCollectionProfile\(/);
  assert.match(bridge, /requiredPositiveInt\(\$input, 'data_source_id'\)/);
  assert.match(bridge, /function requiredOtaPlatform\(array \$input\): string/);
  assert.match(bridge, /\['ctrip', 'meituan'\]/);
});
