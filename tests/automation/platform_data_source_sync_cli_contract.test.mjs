import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const source = readFileSync(path.join(repoRoot, 'scripts', 'run_platform_data_source_sync.php'), 'utf8');

test('operator sync accepts only platform-specific core capture sections', () => {
  assert.match(source, /'capture-sections:'/);
  assert.match(source, /'ctrip' => \['business_overview', 'traffic_report'\]/);
  assert.match(source, /'meituan' => \['orders', 'traffic'\]/);
  assert.match(source, /array_diff\(\$captureSections, \$allowedSections\)/);
  assert.match(source, /capture_sections_invalid_for_source_platform/);
});

test('validated capture sections stay bounded and are reported in the safe summary', () => {
  const validation = source.indexOf("capture_sections_invalid_for_source_platform");
  const boundedOption = source.indexOf("$syncOptions['bounded_capture_sections']");
  const syncCall = source.indexOf('syncDataSource($user, $sourceId, $syncOptions)');

  assert(validation >= 0 && boundedOption > validation && syncCall > boundedOption);
  assert.match(source, /\$syncOptions\['capture_sections'\] = \$boundedSections/);
  assert.match(source, /'capture_sections' => \$captureSections/);
});
