import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, readFile, rm, stat } from 'node:fs/promises';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import {
  buildCompetitionCardHtml,
  renderCompetitionVisualCard,
} from '../../scripts/render_wechat_competition_visual_card.mjs';

const model = {
  schema: 'suxi.wecom.competition.visual-card.v1',
  hotel_name: '敦煌漠蓝新',
  report_date: '2026-07-23',
  edition: 'flagship',
  edition_label: '旗舰版',
  quality_status: 'blocked',
  quality_label: '证据阻断，仅展示缺口',
  status_only: true,
  platforms: [
    {
      platform: 'ctrip',
      label: '携程',
      status: 'blocked',
      status_label: '证据未通过',
      channel_role: '暂不判断',
      first_conflict: '等待数据缺口补齐',
    },
    {
      platform: 'meituan',
      label: '美团',
      status: 'blocked',
      status_label: '证据未通过',
      channel_role: '暂不判断',
      first_conflict: '等待数据缺口补齐',
    },
  ],
  competitor_groups: [
    {
      key: 'direct',
      label: '直接竞品',
      items: [{
        platform: '携程',
        hotel_name: '敦煌风鸣沙度假美宿',
        adr: 1023.33,
        room_nights: 3,
        candidate_only: true,
      }],
    },
    { key: 'attack_benchmark', label: '进攻标杆', items: [] },
    {
      key: 'traffic_benchmark',
      label: '流量标杆',
      items: [],
      overlap_note: '与进攻标杆候选重合，待流量/转化证据补齐后区分。',
    },
    { key: 'conversion_benchmark', label: '转化标杆', items: [] },
  ],
  actions: [],
  gaps: ['携程来源尚未通过数据库精确回读。', '美团本店POI绑定未确认。'],
  source_fingerprint: '9bc0e26b9c39cb6c49389de040db3ad1',
  scope_note: '仅限携程/美团OTA渠道，不代表全酒店经营事实。',
  automation_note: '不自动改价、库存或投放；经营动作仍需人工批准。',
};

test('competition card HTML renders a truthful Chinese table and escapes names', () => {
  const html = buildCompetitionCardHtml({
    ...model,
    hotel_name: '<script>alert(1)</script>敦煌漠蓝新',
  });

  assert.match(html, /渠道证据与核心判断/);
  assert.match(html, /竞品分组表/);
  assert.match(html, /数据缺口与行动门槛/);
  assert.match(html, /证据门槛未通过/);
  assert.match(html, /与进攻标杆候选重合/);
  assert.match(html, /来源指纹：9bc0e26b9c39cb6c/);
  assert.doesNotMatch(html, /<script>alert\(1\)<\/script>/);
  assert.match(html, /&lt;script&gt;alert\(1\)&lt;\/script&gt;/);
});

test('competition renderer produces a WeCom-sized PNG', async () => {
  const directory = await mkdtemp(join(tmpdir(), 'suxi-competition-card-'));
  const output = join(directory, 'card.png');
  try {
    const result = await renderCompetitionVisualCard(model, output);
    const file = await stat(output);
    const bytes = await readFile(output);

    assert.equal(result.output_path, output);
    assert.equal(result.bytes, file.size);
    assert.ok(file.size > 10_000);
    assert.ok(file.size <= 2 * 1024 * 1024);
    assert.deepEqual([...bytes.subarray(0, 8)], [137, 80, 78, 71, 13, 10, 26, 10]);
  } finally {
    await rm(directory, { recursive: true, force: true });
  }
});
