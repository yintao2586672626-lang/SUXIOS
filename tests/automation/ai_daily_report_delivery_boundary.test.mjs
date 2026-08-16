import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

test('training delivery emits only the anonymized package with a date-free filename', async () => {
  const appMain = await readFile(new URL('../../public/app-main.js', import.meta.url), 'utf8');
  const start = appMain.indexOf('const downloadAiDailyReportPackage = () => {');
  const end = appMain.indexOf('\n            const buildAiDailyCompetitionReportExport', start);
  const delivery = appMain.slice(start, end);

  assert.match(delivery, /const includeCompetition = audience !== 'training'/);
  assert.match(delivery, /if \(includeCompetition && !downloadAiDailyCompetitionReportHtml\(\)\) return/);
  assert.match(delivery, /audience === 'training' \? `case-\$\{payload\.case_id \|\| 'unversioned'\}`/);
  assert.match(delivery, /if \(includeCompetition && aiDailyReportCompetitionXiaohongshuDraftText\.value\) copyAiDailyCompetitionXiaohongshuDraft\(\)/);
  assert.doesNotMatch(delivery, /hasCompetitionReport/);
});
