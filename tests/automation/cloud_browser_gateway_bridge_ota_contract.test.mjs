import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

test('cloud browser bridge exposes the exact OTA collection validation action', async () => {
  const bridge = await readFile(
    new URL('../../scripts/cloud_browser_gateway_bridge.php', import.meta.url),
    'utf8',
  );

  const exactOtaDispatch = [
    String.raw`'validate_ota_collection'\s*=>\s*array_key_exists\('data_source_id', \$input\)`,
    String.raw`\s*\?\s*\$service->validateOtaDataSourceCollectionProfile\(`,
    String.raw`\s*requiredId\(\$input, 'profile_id', 'cbp_'\),`,
    String.raw`\s*requiredPositiveInt\(\$input, 'data_source_id'\),`,
    String.raw`\s*requiredPositiveInt\(\$input, 'tenant_id'\),`,
    String.raw`\s*requiredPositiveInt\(\$input, 'hotel_id'\),`,
    String.raw`\s*requiredPositiveInt\(\$input, 'owner_user_id'\),`,
    String.raw`\s*requiredDate\(\$input, 'target_date'\),`,
    String.raw`\s*requiredOtaPlatform\(\$input\)\s*\)`,
    String.raw`\s*:\s*\$service->validateOtaCollectionProfile\(`,
    String.raw`\s*requiredId\(\$input, 'profile_id', 'cbp_'\),`,
    String.raw`\s*requiredPositiveInt\(\$input, 'tenant_id'\),`,
    String.raw`\s*requiredPositiveInt\(\$input, 'hotel_id'\),`,
    String.raw`\s*requiredPositiveInt\(\$input, 'owner_user_id'\),`,
    String.raw`\s*requiredDate\(\$input, 'target_date'\),`,
    String.raw`\s*requiredOtaPlatform\(\$input\)\s*\),`,
  ].join('');

  assert.match(bridge, new RegExp(exactOtaDispatch));
  assert.equal(bridge.match(/validateOtaDataSourceCollectionProfile\(/g)?.length, 1);
  assert.equal(bridge.match(/validateOtaCollectionProfile\(/g)?.length, 1);
  assert.match(bridge, /function requiredOtaPlatform\(array \$input\): string/);
  assert.match(bridge, /\['ctrip', 'meituan'\]/);
});
