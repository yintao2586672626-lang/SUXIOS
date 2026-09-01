import fs from 'node:fs';
import path from 'node:path';

export const SOURCE_HOTSPOT_BUDGETS = Object.freeze([
  // 2026-08-16 integration rebaseline: targets stay unchanged; exact ratchets freeze the combined reviewed baseline so future growth still fails closed.
  { path: 'public/app-main.js', max_lines: 49_934, ratchet_max_lines: 55_710, boundary: 'independent home revenue truth, guided three-source onboarding, and hourly notification closure after the generic OTA proxy UI was removed; zero-growth until another behavior-driven domain extraction' },
  { path: 'public/data-health-static.js', max_lines: 7_000, ratchet_max_lines: 7_447, boundary: 'data-health presentation domains; zero-growth until page-specific extraction' },
  { path: 'tests/OnlineDataTest.php', max_lines: 2_800, boundary: 'platform test-case traits' },
  { path: 'tests/Support/OnlineData/DailyOtaReviewTestCases.php', max_lines: 300, boundary: 'daily OTA review platform separation and truthful missing-state tests' },
  { path: 'app/controller/Agent.php', max_lines: 2_700, ratchet_max_lines: 3_212, boundary: 'Agent OTA concern traits plus persisted decision-route wiring; zero-growth until route orchestration extraction' },
  { path: 'app/controller/concern/AutoFetchConcern.php', max_lines: 5_200, ratchet_max_lines: 5_579, boundary: 'platform execution concern traits' },
  { path: 'app/service/PlatformDataSyncService.php', max_lines: 3_800, ratchet_max_lines: 3_935, boundary: 'sync orchestration after collection definitions moved to an immutable registry; zero-growth until another concern extraction' },
  { path: 'app/service/PlatformDataCollectionDefinitionRegistry.php', max_lines: 700, boundary: 'immutable collection-resource and normalized-field definitions' },
  { path: 'app/service/OperationManagementService.php', max_lines: 4_800, ratchet_max_lines: 5_983, boundary: 'operation orchestration after snapshot, alert, analysis, receipt, effect readback, tenant, and persistence concerns were extracted; action-lifecycle cancellation and approval targeting are extracted too; zero-growth until another behavior-driven extraction' },
  { path: 'app/service/OtaLocalCollectorService.php', max_lines: 4_800, ratchet_max_lines: 6_440, boundary: 'pairing, scheduling, result persistence, browser-profile status, and idempotency conflict fencing; zero-growth until another concern extraction' },
  { path: 'app/service/RevenueAiOverviewService.php', max_lines: 4_800, ratchet_max_lines: 6_386, boundary: 'revenue fact reads, overview composition, and AI evidence concerns' },
  { path: 'app/service/AiDailyReportService.php', max_lines: 4_800, ratchet_max_lines: 5_059, boundary: 'daily report fact selection, generation, and persistence concerns' },
  { path: 'app/controller/concern/Phase1EmployeeConsoleConcern.php', max_lines: 4_000, ratchet_max_lines: 4_977, boundary: 'phase-one employee console orchestration; zero-growth until page workflow extraction' },
  { path: 'app/controller/concern/BusinessDisplayConcern.php', max_lines: 4_000, ratchet_max_lines: 5_073, boundary: 'business display composition; zero-growth until metric-domain extraction' },
  { path: 'app/controller/concern/OnlineDataManualFetchConcern.php', max_lines: 4_000, ratchet_max_lines: 4_013, boundary: 'manual online-data collection orchestration; zero-growth until platform-flow extraction' },
  { path: 'app/controller/concern/CtripManualFetchExecutionConcern.php', max_lines: 600, boundary: 'Ctrip manual-fetch execution and source-date evidence' },
  { path: 'tests/Support/OnlineData/CtripTestCases.php', max_lines: 3_250, ratchet_max_lines: 3_559, boundary: 'Ctrip contract tests' },
  { path: 'tests/PlatformDataSyncServiceTest.php', max_lines: 5_000, ratchet_max_lines: 6_149, boundary: 'platform sync integration and current-run evidence contracts after browser-process safety extraction; zero-growth until another fixture-domain extraction' },
  { path: 'tests/PlatformDataSyncBrowserProfileProcessSafetyTest.php', max_lines: 250, boundary: 'browser-profile process failure and diagnostic redaction contracts' },
  { path: 'tests/Support/PlatformDataSyncBrowserProfileFixture.php', max_lines: 200, boundary: 'shared filesystem-only browser-profile adapter fixtures' },
  { path: 'tests/Support/PlatformDataSyncConsistencyTestCases.php', max_lines: 300, boundary: 'platform normalized consistency and forecast timestamp contracts' },
  { path: 'tests/Support/OnlineData/MeituanTestCases.php', max_lines: 2_200, boundary: 'Meituan contract tests' },
  { path: 'tests/Support/OnlineData/ProfileTestCases.php', max_lines: 2_450, boundary: 'Profile contract tests' },
  { path: 'tests/Support/OnlineData/AutoFetchTestCases.php', max_lines: 800, boundary: 'AutoFetch contract tests' },
  { path: 'app/command/AutoFetchOnlineData.php', max_lines: 4_000, ratchet_max_lines: 5_235, boundary: 'hotel-scoped collection planning, execution, durable receipts, owner-safe locking, and fail-closed source selection; zero-growth until command orchestration extraction' },
  { path: 'app/controller/concern/AgentOtaExecutionIntentConcern.php', max_lines: 250, boundary: 'Agent execution intents' },
  { path: 'app/controller/concern/AgentCapturedOtaAnalysisConcern.php', max_lines: 2_450, ratchet_max_lines: 2_507, boundary: 'captured OTA analysis plus truthful decision-route composition; zero-growth until route service extraction' },
  { path: 'app/controller/concern/AgentOtaDiagnosisBuildConcern.php', max_lines: 1_500, ratchet_max_lines: 2_457, boundary: 'OTA diagnosis build' },
  { path: 'app/controller/concern/AgentOtaDiagnosisActionConcern.php', max_lines: 100, boundary: 'OTA diagnosis action selection' },
  { path: 'app/controller/concern/AgentOtaDiagnosisPersistenceConcern.php', max_lines: 1_750, ratchet_max_lines: 2_246, boundary: 'OTA diagnosis persistence after exact-readback identity extraction; zero-growth until another persistence extraction' },
  { path: 'app/controller/concern/AgentOtaDiagnosisSummaryGuardConcern.php', max_lines: 100, boundary: 'OTA diagnosis summary truth guards' },
  { path: 'app/controller/concern/AgentOtaDiagnosisMetricConcern.php', max_lines: 200, boundary: 'OTA diagnosis metric normalization' },
  { path: 'app/controller/concern/AgentOtaDiagnosisReadbackConcern.php', max_lines: 250, boundary: 'OTA diagnosis snapshot and exact-readback identity' },
  { path: 'app/controller/concern/AutoFetchProfileSyncConcern.php', max_lines: 300, boundary: 'Profile sync readback' },
  { path: 'app/controller/concern/CtripAutoFetchExecutionConcern.php', max_lines: 1_700, ratchet_max_lines: 1_773, boundary: 'Ctrip auto-fetch execution' },
  { path: 'app/controller/concern/CtripAutoFetchBusinessConcern.php', max_lines: 150, boundary: 'Ctrip auto-fetch business task orchestration' },
  { path: 'app/controller/concern/MeituanAutoFetchExecutionConcern.php', max_lines: 500, boundary: 'Meituan auto-fetch execution' },
  { path: 'app/service/concern/PlatformDataSourceExecutionConcern.php', max_lines: 1_100, boundary: 'platform source execution' },
  { path: 'app/service/concern/PlatformSyncTaskConcern.php', max_lines: 1_900, boundary: 'platform sync task lifecycle and finalization' },
  { path: 'app/service/concern/PlatformSyncTaskReadbackConcern.php', max_lines: 700, boundary: 'exact-run task readback, metric verification, and capture-strategy evidence' },
  { path: 'app/service/concern/PlatformSyncTaskReadbackCoverageConcern.php', max_lines: 250, boundary: 'complete save-receipt and exact target-date readback coverage' },
  { path: 'app/service/concern/PlatformDataPersistenceConcern.php', max_lines: 1_700, boundary: 'platform persistence' },
  { path: 'app/service/concern/PlatformDataImportParsingConcern.php', max_lines: 300, boundary: 'bounded JSON, CSV, and XLSX import parsing' },
  { path: 'app/service/concern/PlatformMetricNormalizationConcern.php', max_lines: 100, boundary: 'platform metric normalization' },
  { path: 'app/service/concern/PlatformNormalizedConsistencyConcern.php', max_lines: 250, boundary: 'normalized row consistency and conflict quarantine' },
  { path: 'app/service/concern/PlatformSyncIdentityConcern.php', max_lines: 100, boundary: 'platform sync identity and hotel binding' },
  { path: 'app/service/operation/OperationSnapshotConcern.php', max_lines: 2_300, boundary: 'operation snapshots' },
  { path: 'app/service/operation/OperationAlertConcern.php', max_lines: 1_000, boundary: 'operation alerts' },
  { path: 'app/service/operation/OperationAlertAnalysisConcern.php', max_lines: 600, boundary: 'operation alert scoring and comparison analysis' },
  { path: 'app/service/operation/OperationExecutionReceiptConcern.php', max_lines: 400, boundary: 'auditable execution-receipt truth contract' },
  { path: 'app/service/operation/OperationEffectReadbackConcern.php', max_lines: 500, boundary: 'effect review and controlled-replication source readback' },
  { path: 'app/service/operation/OperationExecutionTenantConcern.php', max_lines: 1_050, boundary: 'tenant, hotel, actor, and execution ownership boundaries' },
  { path: 'app/service/operation/OperationExecutionPersistenceConcern.php', max_lines: 1_100, boundary: 'execution intent idempotency, persistence, normalization, and credential boundaries' },
  { path: 'app/service/operation/OperationActionLifecycleConcern.php', max_lines: 700, boundary: 'managed action cancellation and approval-target lifecycle projection' },
  { path: 'app/service/operation/OperationExecutionAssigneeConcern.php', max_lines: 200, boundary: 'server-scoped current-assignee execution queue' },
  { path: 'app/service/concern/OtaLocalCollectorLeaseConcern.php', max_lines: 500, boundary: 'local collector lease ownership and recovery' },
  { path: 'app/service/concern/OtaLocalCollectorManualLoginConcern.php', max_lines: 250, boundary: 'manual Profile login resume identity contract' },
  { path: 'app/service/concern/AiDailyReportReadinessConcern.php', max_lines: 150, boundary: 'AI daily report authoritative-loop readiness' },
  { path: 'app/service/concern/AiDailyReportExecutionReadConcern.php', max_lines: 250, boundary: 'AI daily report operation execution readback' },
  { path: 'app/service/concern/RevenueAiOverviewLabelConcern.php', max_lines: 50, boundary: 'Revenue AI overview label mapping' },
  { path: 'route/app.php', max_lines: 500, ratchet_max_lines: 784, boundary: 'legacy route bootstrap; AI daily reports, Agent guidance, AI governance, operations, and WeCom domains extracted; zero-growth until the next authenticated domain manifest' },
  { path: 'scripts/verify_p0_ota_field_loop_closure.php', max_lines: 4_000, ratchet_max_lines: 8_563, boundary: 'P0 OTA field-loop verifier debt; split by platform and evidence tier before growth' },
  { path: 'scripts/verify_e2e_contracts.mjs', max_lines: 4_000, ratchet_max_lines: 8_071, boundary: 'legacy cross-domain token verifier; zero-growth while checks migrate to domain contracts and runtime assertions' },
  { path: 'scripts/inspect_phase1_ota_live_closure.php', max_lines: 4_000, ratchet_max_lines: 6_925, boundary: 'phase-one live closure inspector; zero-growth until evidence-domain extraction' },
  { path: 'scripts/build_phase1_ota_live_closure_evidence.php', max_lines: 4_000, ratchet_max_lines: 5_433, boundary: 'phase-one evidence builder; zero-growth until evidence-domain extraction' },
  { path: 'scripts/lib/ctrip_capture_catalog.mjs', max_lines: 4_000, ratchet_max_lines: 5_025, boundary: 'Ctrip capture catalog; zero-growth until page-family extraction' },
  { path: 'scripts/verify_public_entry_guard.mjs', max_lines: 3_000, ratchet_max_lines: 3_642, boundary: 'public entry guard; zero-growth until AST and asset-domain extraction' },
  { path: 'scripts/export_revenue_ai_ctrip_operator_bundle.php', max_lines: 3_000, ratchet_max_lines: 3_221, boundary: 'operator bundle exporter; zero-growth until packet-domain extraction' },
  { path: 'scripts/report_business_chain_status.php', max_lines: 3_000, ratchet_max_lines: 3_102, boundary: 'business-chain status report; zero-growth until domain reporter extraction' },
  { path: 'scripts/suxi_skill_behavior_eval.mjs', max_lines: 3_000, ratchet_max_lines: 3_399, boundary: 'Skill behavior replay, evidence sealing, archive verification, and deterministic reporting; zero-growth until archive and runner concerns are extracted' },
]);

export const SOURCE_HOTSPOT_DISCOVERY = Object.freeze({
  roots: Object.freeze(['app', 'public', 'route', 'scripts', 'tests']),
  extensions: Object.freeze(['.php', '.js', '.mjs']),
  max_lines: 5_000,
  max_lines_by_root: Object.freeze({ app: 4_000, route: 800, scripts: 3_000 }),
});

export function sourceLineCount(source) {
  const normalized = String(source || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
  if (normalized === '') return 0;
  return normalized.endsWith('\n')
    ? normalized.slice(0, -1).split('\n').length
    : normalized.split('\n').length;
}

function discoverSourceFiles(root, discovery) {
  const files = [];
  const allowedExtensions = new Set(discovery.extensions || []);
  const walk = (directory) => {
    if (!fs.existsSync(directory)) return;
    for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
      const absolutePath = path.join(directory, entry.name);
      if (entry.isDirectory()) {
        walk(absolutePath);
        continue;
      }
      if (!entry.isFile()
        || entry.name.endsWith('.min.js')
        || !allowedExtensions.has(path.extname(entry.name))) {
        continue;
      }
      files.push(absolutePath);
    }
  };
  for (const relativeRoot of discovery.roots || []) {
    walk(path.join(root, relativeRoot));
  }
  return files;
}

export function inspectSourceHotspotBudget(
  repoRoot,
  budgets = SOURCE_HOTSPOT_BUDGETS,
  discovery = SOURCE_HOTSPOT_DISCOVERY,
) {
  const root = path.resolve(repoRoot);
  const files = [];
  const failures = [];
  const debts = [];

  for (const budget of budgets) {
    const absolutePath = path.join(root, budget.path);
    if (!fs.existsSync(absolutePath)) {
      failures.push({
        path: budget.path,
        reason: 'missing',
        actual_lines: null,
        max_lines: budget.max_lines,
      });
      continue;
    }

    const actualLines = sourceLineCount(fs.readFileSync(absolutePath, 'utf8'));
    const enforcementMaxLines = Number.isInteger(budget.ratchet_max_lines)
      ? budget.ratchet_max_lines
      : budget.max_lines;
    const row = {
      ...budget,
      actual_lines: actualLines,
      enforcement_max_lines: enforcementMaxLines,
      headroom_lines: enforcementMaxLines - actualLines,
      target_headroom_lines: budget.max_lines - actualLines,
      debt_lines: Math.max(0, actualLines - budget.max_lines),
    };
    files.push(row);
    if (actualLines > enforcementMaxLines) {
      failures.push({
        path: budget.path,
        reason: 'line_ratchet_exceeded',
        actual_lines: actualLines,
        max_lines: budget.max_lines,
        enforcement_max_lines: enforcementMaxLines,
      });
    }
    if (row.debt_lines > 0) {
      debts.push({
        path: budget.path,
        actual_lines: actualLines,
        target_max_lines: budget.max_lines,
        ratchet_max_lines: enforcementMaxLines,
        debt_lines: row.debt_lines,
        boundary: budget.boundary,
      });
    }
  }

  const budgetPaths = new Set(budgets.map((budget) => budget.path.replaceAll('\\', '/')));
  const discoveredHotspots = [];
  for (const absolutePath of discoverSourceFiles(root, discovery)) {
    const relativePath = path.relative(root, absolutePath).replaceAll('\\', '/');
    const actualLines = sourceLineCount(fs.readFileSync(absolutePath, 'utf8'));
    const sourceRoot = relativePath.split('/')[0];
    const discoveryMaxLines = Number(discovery.max_lines_by_root?.[sourceRoot] ?? discovery.max_lines);
    if (actualLines <= discoveryMaxLines) continue;
    discoveredHotspots.push({ path: relativePath, actual_lines: actualLines });
    if (!budgetPaths.has(relativePath)) {
      failures.push({
        path: relativePath,
        reason: 'unbudgeted_hotspot',
        actual_lines: actualLines,
        discovery_max_lines: discoveryMaxLines,
      });
    }
  }

  return {
    schema_version: 2,
    files,
    debts,
    discovery: {
      ...discovery,
      hotspots: discoveredHotspots.sort((left, right) => right.actual_lines - left.actual_lines),
    },
    failures,
  };
}
