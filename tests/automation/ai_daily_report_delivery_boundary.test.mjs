import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

test('training delivery emits only the anonymized package with a date-free filename', async () => {
  const [appMain, deliveryClient, presentationService, rendererService] = await Promise.all([
    readFile(new URL('../../public/app-main.js', import.meta.url), 'utf8'),
    readFile(new URL('../../public/components/system/ai-daily-report-delivery.js', import.meta.url), 'utf8'),
    readFile(new URL('../../app/service/AiDailyReportPresentationSpecService.php', import.meta.url), 'utf8'),
    readFile(new URL('../../app/service/AiDailyReportPresentationRendererService.php', import.meta.url), 'utf8'),
  ]);
  const start = deliveryClient.indexOf('const downloadAiDailyReportPackage = async () => {');
  const end = deliveryClient.indexOf('\n\n        const buildSharePackage', start);
  assert.notEqual(start, -1, 'lazy AI daily presentation delivery method must exist');
  assert.notEqual(end, -1, 'lazy AI daily presentation delivery boundary must exist');
  const delivery = deliveryClient.slice(start, end);
  const jsonStart = deliveryClient.indexOf('const downloadAiDailyReportJsonPackage = async () => {');
  const jsonEnd = deliveryClient.indexOf('\n\n        watch(', jsonStart);
  assert.notEqual(jsonStart, -1, 'lazy AI daily JSON delivery method must exist');
  assert.notEqual(jsonEnd, -1, 'lazy AI daily JSON delivery boundary must exist');
  const jsonDelivery = deliveryClient.slice(jsonStart, jsonEnd);

  assert.match(appMain, /loadAiDailyReportDelivery/);
  assert.match(appMain, /aiDailyReportDeliveryRequest: apiRequest/);
  assert.match(delivery, /body: JSON\.stringify\(\{ audience: identity\.audience \}\)/);
  assert.match(delivery, /artifact\.artifact_readback_verified !== true/);
  assert.match(delivery, /Number\(artifact\.hotel_id \|\| 0\) !== identity\.hotelId/);
  assert.match(delivery, /String\(artifact\.audience \|\| ''\) !== identity\.audience/);
  assert.match(delivery, /String\(artifact\.spec_fingerprint \|\| ''\)\.trim\(\)\.toLowerCase\(\) !== expectedSpecFingerprint/);
  assert.match(delivery, /const proposedFilename = safeFilename\(\s*artifact\.filename,/);
  assert.doesNotMatch(delivery, /downloadAiDailyCompetitionReportHtml|copyAiDailyCompetitionXiaohongshuDraft|report_date/);

  assert.match(jsonDelivery, /const includeCompetition = audience !== 'training'/);
  assert.match(jsonDelivery, /if \(includeCompetition && !downloadAiDailyCompetitionReportHtml\(\)\) return/);
  assert.match(jsonDelivery, /audience === 'training'\s*\? `case-\$\{payload\.case_id \|\| 'unversioned'\}`/);
  assert.match(jsonDelivery, /if \(includeCompetition && context\(\)\.aiDailyReportCompetitionXiaohongshuDraftText\)/);
  assert.doesNotMatch(jsonDelivery, /hasCompetitionReport/);

  assert.match(presentationService, /if \(\$audience !== 'training'\) \{/);
  assert.match(presentationService, /'tenant_id' => \$audience === 'training' \? null : \$tenantId/);
  assert.match(presentationService, /'report_id' => \$audience === 'training' \? null : \$reportId/);
  assert.match(presentationService, /'hotel_id' => \$audience === 'training' \? null : \$hotelId/);
  assert.match(presentationService, /'business_date' => \$audience === 'training' \? null : \$reportDate/);
  assert.match(presentationService, /if \(\$audience === 'training'\) \{\s*\$spec = \$this->sanitizeTrainingSpec\(\$spec, \$reportDate\);/);
  assert.doesNotMatch(presentationService, /competition_report|competition_bundle|xiaohongshu/i);
  assert.match(rendererService, /\$date = preg_match\('\/\^\\d\{4\}-\\d\{2\}-\\d\{2\}\$\/', \$date\) \? \$date : 'training-case';/);
});
