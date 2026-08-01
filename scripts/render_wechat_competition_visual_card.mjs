import { mkdir, readFile, stat } from 'node:fs/promises';
import { dirname, extname, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { chromium } from '@playwright/test';

const MAX_IMAGE_BYTES = 2 * 1024 * 1024;

const escapeHtml = (value) => String(value ?? '')
  .replaceAll('&', '&amp;')
  .replaceAll('<', '&lt;')
  .replaceAll('>', '&gt;')
  .replaceAll('"', '&quot;')
  .replaceAll("'", '&#39;');

const evidenceText = (item) => {
  const parts = [item?.platform || 'OTA'];
  if (Number.isFinite(Number(item?.adr))) parts.push(`ADR ¥${Number(item.adr).toLocaleString('zh-CN')}`);
  if (Number.isFinite(Number(item?.room_nights))) parts.push(`间夜 ${Number(item.room_nights).toLocaleString('zh-CN')}`);
  return parts.map(escapeHtml).join(' · ');
};

export function buildCompetitionCardHtml(model) {
  if (!model || model.schema !== 'suxi.wecom.competition.visual-card.v1') {
    throw new Error('Unsupported competition visual-card schema.');
  }
  const platforms = Array.isArray(model.platforms) ? model.platforms.slice(0, 2) : [];
  const groups = Array.isArray(model.competitor_groups) ? model.competitor_groups.slice(0, 4) : [];
  const gaps = Array.isArray(model.gaps) ? model.gaps.slice(0, 6) : [];
  const actions = Array.isArray(model.actions) ? model.actions.slice(0, 3) : [];
  const statusClass = model.quality_status === 'available'
    ? 'available'
    : (model.quality_status === 'partial' ? 'partial' : 'blocked');

  const platformRows = platforms.map((row) => `
    <tr>
      <td class="strong">${escapeHtml(row.label || row.platform)}</td>
      <td><span class="pill ${row.status === 'available' ? 'ok' : 'warn'}">${escapeHtml(row.status_label)}</span></td>
      <td>${escapeHtml(row.channel_role || '暂不判断')}</td>
      <td>${escapeHtml(row.first_conflict || '等待数据补齐')}</td>
    </tr>`).join('');

  const groupRows = groups.map((group) => {
    const items = Array.isArray(group.items) ? group.items : [];
    const content = group.overlap_note
      ? `<div class="overlap-note">${escapeHtml(group.overlap_note)}</div>`
      : (items.length > 0
      ? items.map((item) => `
          <div class="candidate">
            <div class="candidate-name">${escapeHtml(item.hotel_name)}</div>
            <div class="candidate-evidence">${evidenceText(item)}${item.candidate_only ? ' · 候选待核对' : ''}</div>
          </div>`).join('')
      : '<div class="empty">暂无可核对候选</div>');
    return `<tr><td class="strong group-name">${escapeHtml(group.label)}</td><td colspan="3">${content}</td></tr>`;
  }).join('');

  const decisionTitle = model.status_only ? '数据缺口与行动门槛' : '今日行动建议';
  const decisionContent = model.status_only
    ? `
      <div class="withheld">证据门槛未通过，本次不生成调价、库存或投放建议。</div>
      <ol>${gaps.map((gap) => `<li>${escapeHtml(gap)}</li>`).join('') || '<li>未返回具体缺口，请核对来源状态。</li>'}</ol>`
    : `<ol>${actions.map((action) => `<li>${escapeHtml(action)}</li>`).join('') || '<li>当前没有通过门槛的行动建议。</li>'}</ol>`;

  return `<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; background: transparent; }
    body {
      font-family: "Microsoft YaHei", "PingFang SC", "Noto Sans CJK SC", sans-serif;
      color: #f5f1e9;
      -webkit-font-smoothing: antialiased;
    }
    #card {
      width: 760px;
      min-height: 920px;
      padding: 34px;
      border-radius: 24px;
      overflow: hidden;
      border: 1px solid #39444d;
      background:
        radial-gradient(circle at 90% 2%, rgba(196, 164, 96, .18), transparent 28%),
        linear-gradient(145deg, #101820 0%, #17222c 58%, #10171d 100%);
    }
    .eyebrow { color: #c9aa66; font-size: 15px; letter-spacing: 2px; margin-bottom: 10px; }
    .head { display: flex; align-items: flex-start; justify-content: space-between; gap: 20px; }
    h1 { margin: 0; font-size: 30px; line-height: 1.3; }
    .status {
      flex: none; padding: 9px 14px; border-radius: 999px; font-size: 14px; font-weight: 700;
      border: 1px solid rgba(255,255,255,.12);
    }
    .status.available { background: rgba(63, 156, 113, .18); color: #93dbb9; }
    .status.partial { background: rgba(201, 170, 102, .18); color: #f0d18d; }
    .status.blocked { background: rgba(199, 92, 80, .18); color: #f2aaa1; }
    .meta { margin-top: 12px; color: #aab5bd; font-size: 14px; line-height: 1.8; }
    .scope { color: #d2bc82; }
    .section {
      margin-top: 22px; padding: 20px; border: 1px solid #303c46;
      border-radius: 17px; background: rgba(255,255,255,.025);
    }
    .section-title { margin-bottom: 14px; font-size: 18px; font-weight: 700; color: #f5eddf; }
    table { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 14px; }
    th { padding: 0 8px 10px; text-align: left; color: #8e9ba5; font-weight: 500; }
    td { padding: 12px 8px; border-top: 1px solid #2d3841; vertical-align: top; line-height: 1.55; }
    th:nth-child(1), td:nth-child(1) { width: 16%; }
    th:nth-child(2), td:nth-child(2) { width: 20%; }
    th:nth-child(3), td:nth-child(3) { width: 24%; }
    th:nth-child(4), td:nth-child(4) { width: 40%; }
    .strong { color: #f7f0e4; font-weight: 700; }
    .pill { display: inline-block; border-radius: 999px; padding: 4px 8px; font-size: 12px; }
    .pill.ok { color: #92dab8; background: rgba(63, 156, 113, .17); }
    .pill.warn { color: #f0c98b; background: rgba(201, 160, 85, .16); }
    .group-name { color: #d7bd80; }
    .candidate + .candidate { margin-top: 9px; padding-top: 9px; border-top: 1px dashed #34404a; }
    .candidate-name { color: #eef1f2; }
    .candidate-evidence { color: #8997a1; font-size: 12px; margin-top: 3px; }
    .empty { color: #7f8b95; }
    .overlap-note { color: #b7a77f; line-height: 1.65; }
    .withheld { color: #f0c28d; margin-bottom: 10px; line-height: 1.65; }
    ol { margin: 0; padding-left: 22px; color: #d4dadd; font-size: 14px; line-height: 1.72; }
    li + li { margin-top: 5px; }
    .footer { margin-top: 20px; color: #87939d; font-size: 12px; line-height: 1.7; }
    .fingerprint { color: #b9a46f; }
  </style>
</head>
<body>
  <main id="card" data-quality="${escapeHtml(model.quality_status || 'blocked')}">
    <div class="eyebrow">SUXIOS · OTA竞争商圈</div>
    <div class="head">
      <h1>${escapeHtml(model.hotel_name || '未命名酒店')}</h1>
      <div class="status ${statusClass}">${escapeHtml(model.quality_label || '证据待核对')}</div>
    </div>
    <div class="meta">
      数据日期：${escapeHtml(model.report_date || '未返回')}　版本：${escapeHtml(model.edition_label || '简版')}<br>
      <span class="scope">${escapeHtml(model.scope_note || '仅限OTA渠道')}</span>
    </div>

    <section class="section">
      <div class="section-title">渠道证据与核心判断</div>
      <table>
        <thead><tr><th>平台</th><th>证据状态</th><th>渠道角色</th><th>第一矛盾</th></tr></thead>
        <tbody>${platformRows}</tbody>
      </table>
    </section>

    <section class="section">
      <div class="section-title">竞品分组表</div>
      <table><tbody>${groupRows}</tbody></table>
    </section>

    <section class="section">
      <div class="section-title">${decisionTitle}</div>
      ${decisionContent}
    </section>

    <div class="footer">
      ${escapeHtml(model.automation_note || '')}<br>
      <span class="fingerprint">来源指纹：${escapeHtml(String(model.source_fingerprint || '').slice(0, 16) || '未返回')}</span>
    </div>
  </main>
</body>
</html>`;
}

async function launchBrowser() {
  try {
    return await chromium.launch({ channel: 'chrome', headless: true });
  } catch (chromeError) {
    try {
      return await chromium.launch({ headless: true });
    } catch (bundledError) {
      throw new Error(`Chromium unavailable: ${chromeError.message}; ${bundledError.message}`);
    }
  }
}

export async function renderCompetitionVisualCard(model, outputPath) {
  const output = resolve(outputPath);
  if (extname(output).toLowerCase() !== '.png') {
    throw new Error('Competition visual-card output must use a .png extension.');
  }
  await mkdir(dirname(output), { recursive: true });
  const browser = await launchBrowser();
  try {
    const context = await browser.newContext({
      viewport: { width: 820, height: 1500 },
      deviceScaleFactor: 1,
      locale: 'zh-CN',
    });
    const page = await context.newPage();
    await page.setContent(buildCompetitionCardHtml(model), { waitUntil: 'domcontentloaded' });
    await page.evaluate(() => document.fonts?.ready);
    await page.locator('#card').screenshot({
      path: output,
      type: 'png',
      animations: 'disabled',
    });
    await context.close();
  } finally {
    await browser.close();
  }
  const file = await stat(output);
  if (file.size <= 0 || file.size > MAX_IMAGE_BYTES) {
    throw new Error(`Competition visual-card size is invalid: ${file.size}`);
  }
  return { output_path: output, bytes: file.size };
}

const option = (name) => {
  const prefix = `--${name}=`;
  const match = process.argv.find((argument) => argument.startsWith(prefix));
  return match ? match.slice(prefix.length) : '';
};

async function main() {
  const input = option('input');
  const output = option('output');
  if (!input || !output) {
    throw new Error('Usage: node render_wechat_competition_visual_card.mjs --input=model.json --output=card.png');
  }
  const model = JSON.parse(await readFile(resolve(input), 'utf8'));
  const result = await renderCompetitionVisualCard(model, output);
  process.stdout.write(`${JSON.stringify(result)}\n`);
}

if (process.argv[1] && pathToFileURL(resolve(process.argv[1])).href === import.meta.url) {
  main().catch((error) => {
    process.stderr.write(`${error instanceof Error ? error.message : String(error)}\n`);
    process.exitCode = 1;
  });
}
