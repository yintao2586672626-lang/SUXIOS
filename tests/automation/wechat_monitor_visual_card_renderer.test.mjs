import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, readFile, rm, stat } from 'node:fs/promises';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import {
  buildCardHtml,
  renderVisualCard,
} from '../../scripts/render_wechat_monitor_visual_card.mjs';

const model = {
  schema: 'suxi.wecom.monitor.visual-card.v1',
  card_type: 'partial',
  status_label: '部分可用',
  hotel: { id: 80, name: '敦煌漠蓝新' },
  observed_at: '2026-07-25 02:00:00',
  target_date: '2026-07-24',
  metric_scope: 'ota_channel',
  scope_label: '已授权 OTA 渠道，不代表全酒店完整经营结果',
  present: { status: 'empty', status_label: '暂未取得', as_of_time: '' },
  latest_final: { status: 'ready', status_label: '已取得', date: '2026-07-23', column_label: '最近定稿' },
  metrics: [
    {
      key: 'ota_revenue',
      label: '收益',
      unit: '元',
      today_value: null,
      latest_final_value: 14376.01,
      latest_final_date: '2026-07-23',
      change_percent: null,
    },
    {
      key: 'ota_orders',
      label: '订单',
      unit: '单',
      today_value: null,
      latest_final_value: 9,
      latest_final_date: '2026-07-23',
      change_percent: null,
    },
  ],
  trend: {
    status: 'ready',
    metric_key: 'ota_revenue',
    label: '收益趋势',
    unit: '元',
    points: [
      { date: '2026-07-21', value: 11200 },
      { date: '2026-07-22', value: 12800 },
      { date: '2026-07-23', value: 14376.01 },
    ],
    note: '仅展示已保存并回读的 OTA 渠道历史事实。',
  },
  judgment: {
    status: 'unverified',
    label: '研判未验证',
    text: '目标日经营日报尚未生成，当前图卡只展示事实和缺口。',
  },
  gaps: ['携程：目标日字段尚未回读', '美团：目标日流量尚未回读'],
  next_action: '请在“昨日经营闭环”补齐缺失数据后重新生成图卡。',
  sources: [
    'online_daily_data（已保存并回读的 OTA 渠道事实）',
    'ai_daily_reports（仅使用目标日且状态可用的研判）',
  ],
};

test('visual-card HTML keeps facts, gaps, scope and escaped content explicit', () => {
  const html = buildCardHtml({
    ...model,
    hotel: { id: 80, name: '<script>alert(1)</script>敦煌漠蓝新' },
  });

  assert.match(html, /经营事实表/);
  assert.match(html, /14376\.01|14,376/);
  assert.match(html, /未取得/);
  assert.match(html, /携程：目标日字段尚未回读/);
  assert.match(html, /不代表全酒店完整经营结果/);
  assert.match(html, /缺失值不显示为 0/);
  assert.doesNotMatch(html, /<script>alert\(1\)<\/script>/);
  assert.match(html, /&lt;script&gt;alert\(1\)&lt;\/script&gt;/);
});

test('gap card states missing facts explicitly and does not render placeholder zero', () => {
  const html = buildCardHtml({
    ...model,
    card_type: 'gap',
    status_label: '数据未齐',
    metrics: [],
    trend: {
      status: 'unavailable',
      points: [],
      reason: '同一指标少于 2 个有效日期，未生成虚假趋势。',
    },
  });

  assert.match(html, /目标日期尚无可展示事实/);
  assert.match(html, /未使用 0 或旧数据补位/);
  assert.match(html, /同一指标少于 2 个有效日期/);
  assert.match(html, /data-card-type="gap"/);
});

test('visual-card renderer produces a WeCom-sized PNG without network assets', async () => {
  const directory = await mkdtemp(join(tmpdir(), 'suxi-wecom-card-'));
  const output = join(directory, 'card.png');
  try {
    const result = await renderVisualCard(model, output);
    const file = await stat(output);
    const bytes = await readFile(output);

    assert.equal(result.output_path, output);
    assert.equal(file.size, result.bytes);
    assert.ok(file.size > 10_000);
    assert.ok(file.size <= 2 * 1024 * 1024);
    assert.deepEqual([...bytes.subarray(0, 8)], [137, 80, 78, 71, 13, 10, 26, 10]);
  } finally {
    await rm(directory, { recursive: true, force: true });
  }
});
