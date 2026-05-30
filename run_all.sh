#!/usr/bin/env bash

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

BASE_URL="${SUXIOS_BASE_URL:-http://localhost:8080/index.html}"
BROWSER="${BROWSER:-chromium}"
REPORT_ROOT="${REPORT_ROOT:-output/playwright/run_all}"
RUN_ID="${RUN_ID:-$(date +%Y%m%d_%H%M%S)}"
RUN_DIR="$REPORT_ROOT/$RUN_ID"
JSON_DIR="$RUN_DIR/json"
HTML_DIR="$RUN_DIR/html"
ARTIFACT_DIR="$RUN_DIR/artifacts"
STATUS_FILE="$RUN_DIR/status.tsv"

mkdir -p "$JSON_DIR" "$HTML_DIR" "$ARTIFACT_DIR"
: > "$STATUS_FILE"

if sort -V </dev/null >/dev/null 2>&1; then
  SORT_CMD=(sort -V)
else
  SORT_CMD=(sort)
fi

discover_tests() {
  find . \
    \( -path './.git' \
      -o -path './node_modules' \
      -o -path './vendor' \
      -o -path './runtime' \
      -o -path './output' \
      -o -path './public/assets' \) -prune \
    -o -type f \( -name '*.test.ts' -o -name '*.spec.ts' \) -print \
    | sed 's#^\./##' \
    | "${SORT_CMD[@]}"
}

if [ "$#" -gt 0 ]; then
  TEST_FILES=("$@")
else
  mapfile -t TEST_FILES < <(discover_tests)
fi

if [ "${#TEST_FILES[@]}" -eq 0 ]; then
  cat >&2 <<EOF
No Playwright test scripts found.

Expected files:
  - *.test.ts
  - *.spec.ts

You can also pass files explicitly:
  ./run_all.sh tests/rc01_full.test.ts tests/rc02_full.test.ts rc03_knowledgeos.test.ts
EOF
  exit 2
fi

if [ -x "$SCRIPT_DIR/node_modules/.bin/playwright" ]; then
  PW_CMD=("$SCRIPT_DIR/node_modules/.bin/playwright" test)
elif command -v npx >/dev/null 2>&1; then
  PW_CMD=(npx --yes --package=@playwright/test playwright test)
else
  cat >&2 <<EOF
Playwright is not available.

Install local test dependencies first:
  npm install --save-dev @playwright/test
  npx playwright install chromium
EOF
  exit 2
fi

printf 'SUXIOS Playwright regression run\n'
printf 'Root: %s\n' "$SCRIPT_DIR"
printf 'Base URL: %s\n' "$BASE_URL"
printf 'Browser: %s\n' "$BROWSER"
printf 'Report dir: %s\n\n' "$RUN_DIR"

overall_status=0
index=0
total="${#TEST_FILES[@]}"

for test_file in "${TEST_FILES[@]}"; do
  index=$((index + 1))

  if [ ! -f "$test_file" ]; then
    printf '[%s/%s] MISSING %s\n' "$index" "$total" "$test_file"
    printf '%s\t%s\t%s\t%s\n' "$test_file" "127" "" "" >> "$STATUS_FILE"
    overall_status=1
    continue
  fi

  safe_name="$(printf '%s' "$test_file" | sed 's#^\./##; s#[^A-Za-z0-9._-]#_#g')"
  prefix="$(printf '%02d' "$index")"
  json_file="$JSON_DIR/${prefix}_${safe_name}.json"
  html_report="$HTML_DIR/${prefix}_${safe_name}"
  artifact_output="$ARTIFACT_DIR/${prefix}_${safe_name}"

  mkdir -p "$html_report" "$artifact_output"

  printf '[%s/%s] RUN %s\n' "$index" "$total" "$test_file"

  SUXIOS_BASE_URL="$BASE_URL" \
  PLAYWRIGHT_JSON_OUTPUT_NAME="$json_file" \
  PLAYWRIGHT_HTML_REPORT="$html_report" \
  PLAYWRIGHT_HTML_OPEN=never \
    "${PW_CMD[@]}" "$test_file" \
      --browser="$BROWSER" \
      --reporter=json,html \
      --output="$artifact_output"

  status=$?
  printf '%s\t%s\t%s\t%s\n' "$test_file" "$status" "$json_file" "$html_report" >> "$STATUS_FILE"

  if [ "$status" -ne 0 ]; then
    overall_status=1
    printf '[%s/%s] FAIL %s (exit %s)\n\n' "$index" "$total" "$test_file" "$status"
  else
    printf '[%s/%s] PASS %s\n\n' "$index" "$total" "$test_file"
  fi
done

node - "$RUN_DIR" "$STATUS_FILE" "$BASE_URL" "$BROWSER" "$overall_status" <<'NODE'
const fs = require('node:fs');
const path = require('node:path');

const [, , runDir, statusFile, baseUrl, browser, overallStatus] = process.argv;

function readJson(file) {
  if (!file || !fs.existsSync(file)) return null;
  try {
    return JSON.parse(fs.readFileSync(file, 'utf8'));
  } catch {
    return null;
  }
}

function countSpecs(suites = []) {
  let total = 0;
  let passed = 0;
  let failed = 0;
  let skipped = 0;

  const walk = (suite) => {
    for (const spec of suite.specs || []) {
      total += 1;
      const tests = spec.tests || [];
      const statuses = tests.flatMap((item) => (item.results || []).map((result) => result.status));
      if (statuses.includes('failed') || statuses.includes('timedOut') || statuses.includes('interrupted')) {
        failed += 1;
      } else if (statuses.includes('skipped') || tests.every((item) => item.expectedStatus === 'skipped')) {
        skipped += 1;
      } else {
        passed += 1;
      }
    }
    for (const child of suite.suites || []) walk(child);
  };

  for (const suite of suites || []) walk(suite);
  return { total, passed, failed, skipped };
}

const rows = fs
  .readFileSync(statusFile, 'utf8')
  .split(/\r?\n/)
  .filter(Boolean)
  .map((line) => {
    const [file, exitCodeRaw, jsonFile, htmlReport] = line.split('\t');
    const json = readJson(jsonFile);
    return {
      file,
      exitCode: Number(exitCodeRaw),
      status: Number(exitCodeRaw) === 0 ? 'passed' : 'failed',
      jsonReport: jsonFile ? path.relative(runDir, jsonFile).replace(/\\/g, '/') : '',
      htmlReport: htmlReport ? path.relative(runDir, path.join(htmlReport, 'index.html')).replace(/\\/g, '/') : '',
      stats: json ? countSpecs(json.suites) : { total: 0, passed: 0, failed: 0, skipped: 0 },
    };
  });

const totals = rows.reduce(
  (acc, row) => {
    acc.files += 1;
    acc.failedFiles += row.exitCode === 0 ? 0 : 1;
    acc.specs += row.stats.total;
    acc.passed += row.stats.passed;
    acc.failed += row.stats.failed;
    acc.skipped += row.stats.skipped;
    return acc;
  },
  { files: 0, failedFiles: 0, specs: 0, passed: 0, failed: 0, skipped: 0 },
);

const summary = {
  runId: path.basename(runDir),
  generatedAt: new Date().toISOString(),
  baseUrl,
  browser,
  status: Number(overallStatus) === 0 ? 'passed' : 'failed',
  totals,
  tests: rows,
};

fs.writeFileSync(path.join(runDir, 'summary.json'), JSON.stringify(summary, null, 2));

const esc = (value) => String(value ?? '')
  .replace(/&/g, '&amp;')
  .replace(/</g, '&lt;')
  .replace(/>/g, '&gt;')
  .replace(/"/g, '&quot;');

const html = `<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>SUXIOS Playwright Regression Report</title>
  <style>
    body { margin: 0; font-family: Arial, "Microsoft YaHei", sans-serif; background: #f8fafc; color: #0f172a; }
    main { max-width: 1120px; margin: 0 auto; padding: 32px 20px; }
    h1 { margin: 0 0 8px; font-size: 24px; }
    .meta { color: #64748b; font-size: 13px; line-height: 1.7; }
    .cards { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 12px; margin: 24px 0; }
    .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; }
    .label { color: #64748b; font-size: 12px; }
    .value { margin-top: 6px; font-size: 22px; font-weight: 700; }
    table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; }
    th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0; font-size: 13px; }
    th { background: #f1f5f9; color: #475569; font-weight: 700; }
    tr:last-child td { border-bottom: 0; }
    a { color: #2563eb; text-decoration: none; }
    .passed { color: #15803d; font-weight: 700; }
    .failed { color: #b91c1c; font-weight: 700; }
    @media (max-width: 760px) { .cards { grid-template-columns: repeat(2, minmax(0, 1fr)); } table { display: block; overflow-x: auto; } }
  </style>
</head>
<body>
<main>
  <h1>SUXIOS Playwright Regression Report</h1>
  <div class="meta">
    Run ID: ${esc(summary.runId)}<br>
    Generated: ${esc(summary.generatedAt)}<br>
    Base URL: ${esc(summary.baseUrl)}<br>
    Browser: ${esc(summary.browser)}
  </div>
  <section class="cards">
    <div class="card"><div class="label">Status</div><div class="value ${summary.status}">${esc(summary.status)}</div></div>
    <div class="card"><div class="label">Files</div><div class="value">${totals.files}</div></div>
    <div class="card"><div class="label">Specs</div><div class="value">${totals.specs}</div></div>
    <div class="card"><div class="label">Passed</div><div class="value passed">${totals.passed}</div></div>
    <div class="card"><div class="label">Failed</div><div class="value failed">${totals.failedFiles + totals.failed}</div></div>
  </section>
  <table>
    <thead>
      <tr>
        <th>Script</th>
        <th>Status</th>
        <th>Specs</th>
        <th>Passed</th>
        <th>Failed</th>
        <th>Skipped</th>
        <th>JSON</th>
        <th>HTML</th>
      </tr>
    </thead>
    <tbody>
      ${rows.map((row) => `
      <tr>
        <td>${esc(row.file)}</td>
        <td class="${row.status}">${esc(row.status)} (${row.exitCode})</td>
        <td>${row.stats.total}</td>
        <td>${row.stats.passed}</td>
        <td>${row.stats.failed}</td>
        <td>${row.stats.skipped}</td>
        <td>${row.jsonReport ? `<a href="${esc(row.jsonReport)}">json</a>` : '-'}</td>
        <td>${row.htmlReport ? `<a href="${esc(row.htmlReport)}">html</a>` : '-'}</td>
      </tr>`).join('')}
    </tbody>
  </table>
</main>
</body>
</html>
`;

fs.writeFileSync(path.join(runDir, 'summary.html'), html);
NODE

printf 'Summary JSON: %s\n' "$RUN_DIR/summary.json"
printf 'Summary HTML: %s\n' "$RUN_DIR/summary.html"

exit "$overall_status"
