import { existsSync, mkdirSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { basename, join } from 'node:path';
import {
  SOURCE_FIELD_MAPPINGS,
  formatValidationReport,
  loadRowsFromJson,
  normalizeSourceKey,
  validateMetricFormulas,
  validateRawPayloadMapping,
  validateSourceMappingCompleteness,
  validateSourceParserContracts,
} from './lib/ota_data_validator.mjs';

function assertContract(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

function parseArgs(argv) {
  const args = {
    input: '',
    sourcePayload: '',
    source: '',
    json: false,
    strict: false,
    selfTest: false,
  };

  for (const item of argv) {
    if (item === '--json') args.json = true;
    else if (item === '--strict') args.strict = true;
    else if (item === '--self-test') args.selfTest = true;
    else if (item.startsWith('--input=')) args.input = item.slice('--input='.length);
    else if (item.startsWith('--source-payload=')) args.sourcePayload = item.slice('--source-payload='.length);
    else if (item.startsWith('--source=')) args.source = item.slice('--source='.length);
  }

  return args;
}

function buildSelfTestRows() {
  return [
    {
      source: 'ctrip',
      hotel_id: '1001',
      hotel_name: 'Test Hotel',
      data_date: '2026-05-17',
      amount: 1000,
      quantity: 5,
      adr: 200,
      hotel_adr: 200,
      comp_set_adr: 250,
      ari: 80,
      conversion_rate: 0.12,
      comp_set_conversion_rate: 0.1,
      sci: 120,
      occupancy_rate: 75,
      comp_set_occupancy_rate: 80,
      mpi: 93.75,
      raw_data: {
        hotelId: '1001',
        hotelName: 'Test Hotel',
        dataDate: '2026-05-17',
        amount: 1000,
        quantity: 5,
        bookOrderNum: 3,
        commentScore: 4.8,
        convertionRate: 12,
      },
    },
  ];
}

function runSelfTest() {
  const mappingResult = validateSourceMappingCompleteness(SOURCE_FIELD_MAPPINGS);
  assertContract(mappingResult.errors.length === 0, `source mapping errors: ${JSON.stringify(mappingResult.errors)}`);

  const validRows = buildSelfTestRows();

  const validResult = validateMetricFormulas(validRows);
  assertContract(validResult.errors.length === 0, `valid row should not fail: ${JSON.stringify(validResult.errors)}`);
  assertContract(validResult.record_results?.[0]?.status === 'pass', 'valid row must include a passing per-record result');

  const invalidRows = [
    {
      source: 'ctrip',
      hotel_id: '1002',
      hotel_name: 'Bad Hotel',
      data_date: '2026-05-17',
      amount: 900,
      quantity: 3,
      adr: 200,
      hotel_adr: 300,
      comp_set_adr: 200,
      ari: 90,
      conversion_rate: 0.08,
      comp_set_conversion_rate: 0.1,
      sci: 120,
      occupancy_rate: 0.9,
      comp_set_occupancy_rate: 0.75,
      mpi: 90,
      raw_data: '{"hotelName":"Bad Hotel"}',
    },
  ];

  const invalidResult = validateMetricFormulas(invalidRows);
  const failedMetrics = new Set(invalidResult.errors.map((issue) => issue.metric));
  for (const metric of ['ADR', 'ARI', 'SCI', 'MPI']) {
    assertContract(failedMetrics.has(metric), `${metric} mismatch must be reported`);
  }

  const ctripPayload = {
    data: {
      hotelList: [
        {
          hotelId: '1001',
          hotelName: 'Test Hotel',
          dataDate: '2026-05-17',
          totalAmount: 1000,
          roomNights: 5,
          orderCount: 3,
        },
      ],
    },
  };
  const payloadResult = validateRawPayloadMapping(ctripPayload, 'ctrip_business');
  assertContract(payloadResult.errors.length === 0, `Ctrip payload should map: ${JSON.stringify(payloadResult.errors)}`);

  const meituanPayload = {
    data: {
      peerRankData: [
        {
          dimName: '入住间夜榜',
          aiMetricName: 'P_RZ_NIGHT_COUNT',
          roundRanks: [
            {
              poiId: '68471',
              poiName: 'Test Meituan Hotel',
              dataValue: 80,
              rankType: 'P_RZ',
              date: '2026-05-17',
            },
          ],
        },
      ],
    },
  };
  const meituanPayloadResult = validateRawPayloadMapping(meituanPayload, 'meituan_business');
  assertContract(meituanPayloadResult.errors.length === 0, `Meituan payload alias should map: ${JSON.stringify(meituanPayloadResult.errors)}`);
  assertContract(meituanPayloadResult.details.source === 'meituan_rank', 'meituan_business alias must normalize to meituan_rank');
}

function resolveJsonPath(inputPath, candidates) {
  const stat = statSync(inputPath);
  if (!stat.isDirectory()) {
    return inputPath;
  }

  for (const candidate of candidates) {
    const fullPath = join(inputPath, candidate);
    if (existsSync(fullPath)) {
      return fullPath;
    }
  }

  throw new Error(`No JSON file found in ${inputPath}. Expected one of: ${candidates.join(', ')}`);
}

function readJsonFile(path) {
  return JSON.parse(readFileSync(path, 'utf8').replace(/^\uFEFF/, ''));
}

function applyMetricResult(result, metricResult) {
  result.checkedRows += metricResult.checkedRows;
  result.errors.push(...metricResult.errors);
  result.warnings.push(...metricResult.warnings);
  result.record_results.push(...(metricResult.record_results ?? []));
  result.details.metric_summary = metricResult.details;
  result.summary.checked_rows = result.checkedRows;
  result.summary.passed_records += metricResult.summary?.passed_records ?? 0;
  result.summary.failed_records += metricResult.summary?.failed_records ?? 0;
  result.summary.warning_records += metricResult.summary?.warning_records ?? 0;
}

function buildValidationResult(args) {
  const mappingResult = validateSourceMappingCompleteness(SOURCE_FIELD_MAPPINGS);
  const parserResult = validateSourceParserContracts({
    controllerPath: 'app/controller/OnlineData.php',
    commandPath: 'app/command/AutoFetchOnlineData.php',
  });
  const result = {
    checkedRows: 0,
    errors: [...mappingResult.errors, ...parserResult.errors],
    warnings: [...mappingResult.warnings, ...parserResult.warnings],
    record_results: [],
    summary: {
      checked_rows: 0,
      passed_records: 0,
      failed_records: 0,
      warning_records: 0,
      error_count: 0,
      warning_count: 0,
    },
    details: {
      mappings: mappingResult.details,
      parser_contracts: parserResult.details,
    },
  };

  if (args.input) {
    const inputPath = resolveJsonPath(args.input, ['ota_rows.json', 'rows.json', 'records.json']);
    const rows = loadRowsFromJson(readJsonFile(inputPath));
    const metricResult = validateMetricFormulas(rows);
    applyMetricResult(result, metricResult);
  } else if (args.selfTest) {
    const metricResult = validateMetricFormulas(buildSelfTestRows());
    applyMetricResult(result, metricResult);
  }

  if (args.sourcePayload) {
    const payloadPath = resolveJsonPath(args.sourcePayload, ['ctrip_payload.json', 'meituan_payload.json', 'payload.json', 'source_payload.json']);
    const source = normalizeSourceKey(args.source || 'ctrip_business');
    const payloadResult = validateRawPayloadMapping(readJsonFile(payloadPath), source);
    result.errors.push(...payloadResult.errors);
    result.warnings.push(...payloadResult.warnings);
    result.details.payload_mapping = payloadResult.details;
  }

  result.summary.checked_rows = result.checkedRows;
  result.summary.error_count = result.errors.length;
  result.summary.warning_count = result.warnings.length;

  return result;
}

function saveReports(result, markdown) {
  const reportDir = 'reports';
  mkdirSync(reportDir, { recursive: true });
  writeFileSync(join(reportDir, 'ota_data_validation.md'), `${markdown}\n`, 'utf8');
  writeFileSync(join(reportDir, 'ota_data_validation.json'), `${JSON.stringify(result, null, 2)}\n`, 'utf8');
}

function main() {
  const args = parseArgs(process.argv.slice(2));

  if (args.selfTest || (!args.input && !args.sourcePayload)) {
    runSelfTest();
  }

  const result = buildValidationResult(args);
  const failed = result.errors.length > 0 || (args.strict && result.warnings.length > 0);
  const markdown = formatValidationReport(result, {
    title: `OTA 数据与指标校验 (${basename(process.cwd())})`,
  });

  saveReports(result, markdown);

  if (args.json) {
    console.log(JSON.stringify(result, null, 2));
  } else {
    console.log(markdown);
  }

  if (failed) {
    process.exit(1);
  }
}

main();
