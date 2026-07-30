import { mkdir, readFile, stat } from 'node:fs/promises';
import { dirname, extname, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
// This renderer runs in the deployed hourly monitor. playwright-core is a
// production dependency; @playwright/test is intentionally development-only.
import { chromium } from 'playwright-core';

const MAX_IMAGE_BYTES = 2 * 1024 * 1024;

const escapeHtml = (value) => String(value ?? '')
  .replaceAll('&', '&amp;')
  .replaceAll('<', '&lt;')
  .replaceAll('>', '&gt;')
  .replaceAll('"', '&quot;')
  .replaceAll("'", '&#39;');

const numberText = (value, unit = '') => {
  if (value === null || value === undefined || value === '' || !Number.isFinite(Number(value))) {
    return '<span class="missing">未取得</span>';
  }
  const number = Number(value);
  const digits = unit === '元' ? 2 : (Number.isInteger(number) ? 0 : 2);
  return `${escapeHtml(number.toLocaleString('zh-CN', { maximumFractionDigits: digits }))}${escapeHtml(unit)}`;
};

const changeText = (value) => {
  if (value === null || value === undefined || !Number.isFinite(Number(value))) {
    return '<span class="missing">不可比</span>';
  }
  const number = Number(value);
  const tone = number > 0 ? 'up' : (number < 0 ? 'down' : 'flat');
  const prefix = number > 0 ? '+' : '';
  return `<span class="${tone}">${prefix}${escapeHtml(number.toFixed(1))}%</span>`;
};

const trendSvg = (trend) => {
  const points = Array.isArray(trend?.points)
    ? trend.points.filter((point) => Number.isFinite(Number(point?.value)) && /^\d{4}-\d{2}-\d{2}$/.test(String(point?.date ?? '')))
    : [];
  if (trend?.status !== 'ready' || points.length < 2) {
    return `
      <div class="trend-empty">
        <div class="trend-empty-title">趋势暂不可用</div>
        <div>${escapeHtml(trend?.reason || '同一指标有效日期不足，未生成趋势图。')}</div>
      </div>`;
  }

  const width = 660;
  const height = 190;
  const padX = 28;
  const padTop = 20;
  const padBottom = 34;
  const values = points.map((point) => Number(point.value));
  const rawMin = Math.min(...values);
  const rawMax = Math.max(...values);
  const spread = rawMax === rawMin ? Math.max(Math.abs(rawMax) * 0.1, 1) : rawMax - rawMin;
  const min = rawMin - spread * 0.12;
  const max = rawMax + spread * 0.12;
  const x = (index) => padX + index * ((width - padX * 2) / (points.length - 1));
  const y = (value) => padTop + (max - value) / (max - min) * (height - padTop - padBottom);
  const path = points.map((point, index) => `${index === 0 ? 'M' : 'L'} ${x(index).toFixed(1)} ${y(Number(point.value)).toFixed(1)}`).join(' ');
  const dots = points.map((point, index) => `
    <circle cx="${x(index).toFixed(1)}" cy="${y(Number(point.value)).toFixed(1)}" r="4.5" fill="#c6a45b" />
  `).join('');
  const labels = points
    .map((point, index) => ({ point, index }))
    .filter(({ index }) => index === 0 || index === points.length - 1 || index === Math.floor((points.length - 1) / 2))
    .map(({ point, index }) => `
      <text x="${x(index).toFixed(1)}" y="${height - 8}" text-anchor="${index === 0 ? 'start' : (index === points.length - 1 ? 'end' : 'middle')}" class="axis-label">
        ${escapeHtml(String(point.date).slice(5))}
      </text>
    `).join('');

  return `
    <div class="trend-head">
      <div>${escapeHtml(trend.label || '事实趋势')}</div>
      <div class="trend-unit">单位：${escapeHtml(trend.unit || '-')}</div>
    </div>
    <svg class="trend-chart" viewBox="0 0 ${width} ${height}" role="img" aria-label="${escapeHtml(trend.label || '事实趋势')}">
      <line x1="${padX}" y1="${height - padBottom}" x2="${width - padX}" y2="${height - padBottom}" stroke="#35404b" stroke-width="1" />
      <path d="${path}" fill="none" stroke="#c6a45b" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" />
      ${dots}
      ${labels}
    </svg>
    <div class="trend-note">${escapeHtml(trend.note || '仅展示已保存并回读的事实。')}</div>`;
};

export function buildCardHtml(model) {
  if (!model || model.schema !== 'suxi.wecom.monitor.visual-card.v1') {
    throw new Error('Unsupported visual-card schema.');
  }
  const metrics = Array.isArray(model.metrics) ? model.metrics.slice(0, 6) : [];
  const gaps = Array.isArray(model.gaps) ? model.gaps.slice(0, 6) : [];
  const metricRows = metrics.length > 0
    ? metrics.map((row) => `
        <tr>
          <td class="metric-name">${escapeHtml(row.label || row.key || '指标')}</td>
          <td>${numberText(row.today_value, row.unit)}</td>
          <td>
            ${numberText(row.latest_final_value, row.unit)}
            ${row.latest_final_date ? `<div class="sub">${escapeHtml(row.latest_final_date)}</div>` : ''}
          </td>
          <td>${changeText(row.change_percent)}</td>
        </tr>`).join('')
    : `<tr><td colspan="4" class="no-facts">目标日期尚无可展示事实，未使用 0 或旧数据补位。</td></tr>`;
  const gapItems = gaps.length > 0
    ? gaps.map((gap) => `<li>${escapeHtml(gap)}</li>`).join('')
    : '<li class="no-gap">当前已加载证据未记录阻塞缺口，仍以定稿回读为准。</li>';
  const sources = Array.isArray(model.sources)
    ? model.sources.map((source) => escapeHtml(source)).join('；')
    : '';
  const cardType = ['facts', 'partial', 'gap'].includes(model.card_type) ? model.card_type : 'gap';

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
      color: #f3efe7;
      -webkit-font-smoothing: antialiased;
    }
    #card {
      width: 760px;
      min-height: 860px;
      padding: 36px;
      background:
        radial-gradient(circle at 88% 3%, rgba(198, 164, 91, .16), transparent 30%),
        linear-gradient(145deg, #111820 0%, #17212b 58%, #10171e 100%);
      border: 1px solid #39434c;
      border-radius: 26px;
      overflow: hidden;
    }
    .eyebrow { color: #c6a45b; font-size: 16px; letter-spacing: 3px; margin-bottom: 10px; }
    .title-row { display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; }
    h1 { font-size: 32px; line-height: 1.25; margin: 0; font-weight: 720; }
    .status {
      flex: none; border-radius: 999px; padding: 9px 16px; font-size: 15px; font-weight: 700;
      border: 1px solid rgba(255,255,255,.12);
    }
    .status.facts { background: rgba(64, 161, 116, .18); color: #8bd7b2; }
    .status.partial { background: rgba(198, 164, 91, .18); color: #efd28e; }
    .status.gap { background: rgba(199, 95, 83, .18); color: #f2aaa1; }
    .meta { margin-top: 13px; color: #aab4bd; font-size: 15px; line-height: 1.7; }
    .scope { color: #d0b979; }
    .section {
      margin-top: 25px; padding: 22px; border: 1px solid #303b45; border-radius: 18px;
      background: rgba(255,255,255,.025);
    }
    .section-title { font-size: 18px; font-weight: 700; margin-bottom: 15px; color: #f6f0e4; }
    table { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 15px; }
    th { color: #8f9ca6; font-weight: 500; text-align: left; padding: 0 9px 11px; }
    td { border-top: 1px solid #2d3740; padding: 13px 9px; vertical-align: middle; }
    th:nth-child(1), td:nth-child(1) { width: 23%; }
    th:nth-child(2), td:nth-child(2) { width: 24%; }
    th:nth-child(3), td:nth-child(3) { width: 31%; }
    th:nth-child(4), td:nth-child(4) { width: 22%; }
    .metric-name { color: #f6f0e4; font-weight: 700; }
    .sub { color: #7e8b96; font-size: 12px; margin-top: 4px; }
    .missing { color: #7e8b96; }
    .up { color: #ee9a8e; } .down { color: #82caa9; } .flat { color: #cbd3d9; }
    .no-facts { color: #f0b0a8; line-height: 1.7; text-align: center; padding: 25px 10px; }
    .trend-head { display: flex; justify-content: space-between; font-weight: 700; }
    .trend-unit { color: #8f9ca6; font-size: 13px; font-weight: 400; }
    .trend-chart { display: block; width: 100%; margin-top: 5px; }
    .axis-label { fill: #84919b; font-size: 12px; }
    .trend-note, .footer { color: #8f9ca6; font-size: 12px; line-height: 1.65; }
    .trend-empty { padding: 25px 20px; text-align: center; color: #9ca8b1; line-height: 1.7; }
    .trend-empty-title { color: #e6d5ae; font-weight: 700; font-size: 17px; margin-bottom: 6px; }
    .judgment { color: #e9dfcd; font-size: 15px; line-height: 1.75; }
    .judgment-label { color: #c6a45b; font-weight: 700; margin-bottom: 7px; }
    ul { margin: 0; padding-left: 21px; color: #d2d8dc; font-size: 14px; line-height: 1.75; }
    li + li { margin-top: 5px; }
    .next { margin-top: 13px; color: #ecd59e; font-size: 14px; line-height: 1.7; }
    .footer { margin-top: 22px; padding: 0 4px; }
  </style>
</head>
<body>
  <main id="card" data-card-type="${cardType}">
    <div class="eyebrow">宿析 OS · 酒店经营监控</div>
    <div class="title-row">
      <h1>${escapeHtml(model.hotel?.name || '未命名门店')}</h1>
      <div class="status ${cardType}">${escapeHtml(model.status_label || '数据未齐')}</div>
    </div>
    <div class="meta">
      观察时间：${escapeHtml(model.observed_at || '未返回')}<br>
      目标日期：${escapeHtml(model.target_date || '未返回')}<br>
      <span class="scope">${escapeHtml(model.scope_label || 'OTA 渠道口径')}</span>
    </div>

    <section class="section">
      <div class="section-title">经营事实表</div>
      <table>
        <thead><tr><th>指标</th><th>今日累计</th><th>${escapeHtml(model.latest_final?.column_label || '最近定稿')}</th><th>变化</th></tr></thead>
        <tbody>${metricRows}</tbody>
      </table>
    </section>

    <section class="section">
      <div class="section-title">历史事实趋势</div>
      ${trendSvg(model.trend)}
    </section>

    <section class="section">
      <div class="judgment-label">${escapeHtml(model.judgment?.label || '研判未验证')}</div>
      <div class="judgment">${escapeHtml(model.judgment?.text || '当前不输出经营结论。')}</div>
    </section>

    <section class="section">
      <div class="section-title">数据缺口与下一步</div>
      <ul>${gapItems}</ul>
      <div class="next">下一步：${escapeHtml(model.next_action || '等待下一次更新。')}</div>
    </section>

    <div class="footer">来源：${sources}<br>缺失值不显示为 0；旧数据不冒充今天。</div>
  </main>
</body>
</html>`;
}

async function launchBrowser() {
  const executablePath = String(process.env.SUXIOS_CHROME_EXECUTABLE || '').trim();
  try {
    return await chromium.launch({
      ...(executablePath ? { executablePath } : { channel: 'chrome' }),
      headless: true,
    });
  } catch (chromeError) {
    try {
      return await chromium.launch({ headless: true });
    } catch (bundledError) {
      throw new Error(`Chromium unavailable: ${chromeError.message}; ${bundledError.message}`);
    }
  }
}

export async function renderVisualCard(model, outputPath) {
  const output = resolve(outputPath);
  if (extname(output).toLowerCase() !== '.png') {
    throw new Error('Visual-card output must use a .png extension.');
  }
  await mkdir(dirname(output), { recursive: true });
  const browser = await launchBrowser();
  try {
    const context = await browser.newContext({
      viewport: { width: 820, height: 1400 },
      deviceScaleFactor: 1,
      locale: 'zh-CN',
    });
    const page = await context.newPage();
    await page.setContent(buildCardHtml(model), { waitUntil: 'domcontentloaded' });
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
    throw new Error(`Rendered image size ${file.size} is outside enterprise WeChat limits.`);
  }
  return { output_path: output, bytes: file.size };
}

const parseArgs = (args) => {
  const result = {};
  for (const arg of args) {
    const match = /^--([a-z-]+)=(.*)$/i.exec(arg);
    if (match) result[match[1]] = match[2];
  }
  return result;
};

async function main() {
  const args = parseArgs(process.argv.slice(2));
  if (!args.input || !args.output) {
    throw new Error('Usage: node scripts/render_wechat_monitor_visual_card.mjs --input=<model.json> --output=<card.png>');
  }
  const model = JSON.parse(await readFile(resolve(args.input), 'utf8'));
  const result = await renderVisualCard(model, args.output);
  process.stdout.write(`${JSON.stringify({ status: 'rendered', ...result })}\n`);
}

if (import.meta.url === pathToFileURL(process.argv[1] || '').href) {
  main().catch((error) => {
    process.stderr.write(`${error.message}\n`);
    process.exitCode = 1;
  });
}
