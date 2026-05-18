import { existsSync, mkdirSync, readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { basename, join, relative } from 'node:path';
import {
  formatValidationReport,
  loadRowsFromJson,
  validateMetricFormulas,
} from './lib/ota_data_validator.mjs';

function parseArgs(argv) {
  const args = {
    inputDir: '',
    json: false,
    strict: false,
  };

  for (const item of argv) {
    if (item === '--json') args.json = true;
    else if (item === '--strict') args.strict = true;
    else if (item.startsWith('--input-dir=')) args.inputDir = item.slice('--input-dir='.length);
  }

  return args;
}

function readJsonFile(path) {
  return JSON.parse(readFileSync(path, 'utf8').replace(/^\uFEFF/, ''));
}

function collectJsonFiles(dir) {
  const files = [];
  const allowedNames = new Set(['ota_rows.json', 'rows.json', 'records.json']);

  function walk(current) {
    for (const name of readdirSync(current)) {
      const fullPath = join(current, name);
      const stat = statSync(fullPath);
      if (stat.isDirectory()) {
        walk(fullPath);
      } else if (allowedNames.has(name)) {
        files.push(fullPath);
      }
    }
  }

  walk(dir);
  return files.sort();
}

function mergeSummary(results) {
  return results.reduce((summary, item) => {
    summary.file_count += 1;
    summary.failed_file_count += item.status === 'fail' ? 1 : 0;
    summary.warning_file_count += item.status === 'warning' ? 1 : 0;
    summary.checked_rows += item.summary.checked_rows ?? 0;
    summary.failed_records += item.summary.failed_records ?? 0;
    summary.warning_records += item.summary.warning_records ?? 0;
    summary.abnormal_record_count += item.abnormal_records ?? 0;
    summary.error_count += item.error_count ?? 0;
    summary.warning_count += item.warning_count ?? 0;
    return summary;
  }, {
    file_count: 0,
    failed_file_count: 0,
    warning_file_count: 0,
    checked_rows: 0,
    failed_records: 0,
    warning_records: 0,
    abnormal_record_count: 0,
    error_count: 0,
    warning_count: 0,
  });
}

function buildBatchResult(inputDir, strict) {
  if (!inputDir || !existsSync(inputDir) || !statSync(inputDir).isDirectory()) {
    throw new Error('--input-dir must be an existing directory');
  }

  const files = collectJsonFiles(inputDir);
  const results = files.map((file) => {
    const rows = loadRowsFromJson(readJsonFile(file));
    const result = validateMetricFormulas(rows);
    const errorCount = result.errors.length;
    const warningCount = result.warnings.length;
    const status = errorCount > 0 ? 'fail' : (strict && warningCount > 0 ? 'fail' : (warningCount > 0 ? 'warning' : 'pass'));
    return {
      file: relative(process.cwd(), file),
      source_name: basename(file),
      status,
      checked_rows: result.summary.checked_rows,
      abnormal_records: result.summary.abnormal_records ?? 0,
      error_count: errorCount,
      warning_count: warningCount,
      summary: result.summary,
      record_results: result.record_results,
      errors: result.errors,
      warnings: result.warnings,
    };
  });

  return {
    input_dir: relative(process.cwd(), inputDir),
    strict,
    summary: mergeSummary(results),
    results,
  };
}

function formatBatchReport(result) {
  const lines = [
    '# OTA 批量数据校验',
    '',
    '## 汇总',
    '',
    `- 文件数: ${result.summary.file_count}`,
    `- 失败文件数: ${result.summary.failed_file_count}`,
    `- 校验记录数: ${result.summary.checked_rows}`,
    `- 异常记录数: ${result.summary.abnormal_record_count}`,
    `- 错误数: ${result.summary.error_count}`,
    `- 告警数: ${result.summary.warning_count}`,
    '',
    '## 文件结果',
    '',
    '| 文件 | 状态 | 记录数 | 异常记录 | 错误 | 告警 |',
    '|---|---|---:|---:|---:|---:|',
    ...result.results.map((item) => `| ${item.file} | ${item.status} | ${item.checked_rows} | ${item.abnormal_records} | ${item.error_count} | ${item.warning_count} |`),
    '',
    '## 异常记录',
    '',
  ];

  const abnormalRows = result.results.flatMap((item) => (
    item.record_results
      .filter((record) => record.abnormal)
      .map((record) => ({ file: item.file, record }))
  ));

  if (abnormalRows.length === 0) {
    lines.push('无');
  } else {
    lines.push('| 文件 | 行 | 等级 | 酒店ID | 酒店名称 | 原因 |');
    lines.push('|---|---:|---|---|---|---|');
    for (const item of abnormalRows.slice(0, 100)) {
      const reason = item.record.abnormal_reasons.map((issue) => `${issue.field || issue.metric || issue.scope}: ${issue.message}`).join('; ');
      lines.push(`| ${item.file} | ${item.record.index} | ${item.record.abnormal_level} | ${item.record.hotel_id} | ${item.record.hotel_name} | ${reason.replace(/\|/g, '\\|')} |`);
    }
    if (abnormalRows.length > 100) {
      lines.push('');
      lines.push(`> 仅展示前 100 条，共 ${abnormalRows.length} 条。`);
    }
  }

  return lines.join('\n');
}

function saveReports(result, markdown) {
  const reportDir = 'reports';
  mkdirSync(reportDir, { recursive: true });
  writeFileSync(join(reportDir, 'ota_data_batch_validation.md'), `${markdown}\n`, 'utf8');
  writeFileSync(join(reportDir, 'ota_data_batch_validation.json'), `${JSON.stringify(result, null, 2)}\n`, 'utf8');
}

function main() {
  const args = parseArgs(process.argv.slice(2));
  const result = buildBatchResult(args.inputDir, args.strict);
  const markdown = formatBatchReport(result);
  saveReports(result, markdown);

  if (args.json) {
    console.log(JSON.stringify(result, null, 2));
  } else {
    console.log(markdown);
  }

  if (result.summary.failed_file_count > 0 || (args.strict && result.summary.warning_file_count > 0)) {
    process.exit(1);
  }
}

main();
