import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

test('all cloud daily timers consume the post-collection 09:00 gate', async () => {
  const files = [
    '../../deploy/systemd/suxios-cloud-daily.timer',
    '../../deploy/systemd/suxios-cloud-hotel-daily@.timer',
  ];
  for (const path of files) {
    const source = await readFile(new URL(path, import.meta.url), 'utf8');
    assert.match(source, /OnCalendar=\*-\*-\* 09:00:00 Asia\/Shanghai/);
    assert.doesNotMatch(source, /08:10:00/);
  }
});
