import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import { formatDateInTimeZone } from '../../scripts/lib/shared_helpers.mjs';

test('business date helper uses the configured local calendar day', () => {
  const instant = new Date('2026-07-12T16:30:00Z');

  assert.equal(formatDateInTimeZone(instant, 'Asia/Shanghai'), '2026-07-13');
  assert.equal(formatDateInTimeZone(instant, 'America/Los_Angeles'), '2026-07-12');
});

test('Ctrip external-input report defaults to the Shanghai business date', () => {
  const source = fs.readFileSync('scripts/report_revenue_ai_ctrip_external_input_candidates.mjs', 'utf8');

  assert.match(source, /import \{ formatDateInTimeZone \} from '\.\/lib\/shared_helpers\.mjs';/);
  assert.match(source, /options\.date = formatDateInTimeZone\(new Date\(\), 'Asia\/Shanghai'\);/);
  assert.doesNotMatch(source, /options\.date = new Date\(\)\.toISOString\(\)\.slice\(0, 10\);/);
});
