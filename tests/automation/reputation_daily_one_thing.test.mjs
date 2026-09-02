import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const signalService = readFileSync('app/service/OtaReputationDailySignalService.php', 'utf8');
const inputService = readFileSync('app/service/DailyOneThingInputService.php', 'utf8');

test('reputation signals require exact readback and never inspect review text or write externally', () => {
  assert.match(signalService, /ota_reputation_daily_signal\.v1/);
  assert.match(signalService, /readback_verified/);
  assert.match(signalService, /unreplied_reviews/);
  assert.match(signalService, /bad_reviews_increased/);
  assert.match(signalService, /score_declined/);
  assert.match(signalService, /'review_text_read' => false/);
  assert.match(signalService, /'reviewer_identity_inferred' => false/);
  assert.match(signalService, /'automatic_reply' => false/);
  assert.match(signalService, /'automatic_appeal' => false/);
  assert.match(signalService, /'external_write_count' => 0/);
});

test('reputation reuses daily_one_thing strict fact selection instead of creating a second task system', () => {
  assert.match(inputService, /new OtaReputationDailySignalService/);
  assert.match(inputService, /reputationCandidates/);
  assert.match(inputService, /'strict_fact_signal'/);
  assert.match(inputService, /'human_reviewed_reputation_check'/);
  assert.match(inputService, /线上经营数据 → 点评口碑/);
  assert.match(inputService, /不读取点评正文，不推断住客身份，不自动回复或申诉/);
  assert.match(inputService, /需要登录、验证码、住客身份或平台外部写入时等待用户主动操作/);
});
