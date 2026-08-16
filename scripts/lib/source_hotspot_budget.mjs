import fs from 'node:fs';
import path from 'node:path';

export const SOURCE_HOTSPOT_BUDGETS = Object.freeze([
  // 2026-08-16 integration rebaseline: targets stay unchanged; exact ratchets freeze the combined reviewed baseline so future growth still fails closed.
  { path: 'public/app-main.js', max_lines: 49_934, ratchet_max_lines: 55_369, boundary: 'formal promotion + temporal trial + XLSX verified closure plus authenticated patrol-report export; AI daily pure presentation extracted; zero-growth until another behavior-driven domain extraction' },
  { path: 'public/data-health-static.js', max_lines: 7_000, ratchet_max_lines: 7_447, boundary: 'data-health presentation domains; zero-growth until page-specific extraction' },
  { path: 'tests/OnlineDataTest.php', max_lines: 2_800, boundary: 'platform test-case traits' },
  { path: 'app/controller/Agent.php', max_lines: 2_700, ratchet_max_lines: 3_212, boundary: 'Agent OTA concern traits plus persisted decision-route wiring; zero-growth until route orchestration extraction' },
  { path: 'app/controller/concern/AutoFetchConcern.php', max_lines: 5_200, ratchet_max_lines: 5_579, boundary: 'platform execution concern traits' },
  { path: 'app/service/PlatformDataSyncService.php', max_lines: 3_800, ratchet_max_lines: 4_379, boundary: 'data source, task, persistence, and target-date provenance concerns; zero-growth until another concern extraction' },
  { path: 'app/service/OperationManagementService.php', max_lines: 4_800, ratchet_max_lines: 6_294, boundary: 'snapshot and alert concerns' },
  { path: 'app/service/OtaLocalCollectorService.php', max_lines: 4_800, ratchet_max_lines: 6_440, boundary: 'pairing, scheduling, result persistence, browser-profile status, and idempotency conflict fencing; zero-growth until another concern extraction' },
  { path: 'app/service/RevenueAiOverviewService.php', max_lines: 4_800, ratchet_max_lines: 6_386, boundary: 'revenue fact reads, overview composition, and AI evidence concerns' },
  { path: 'app/service/AiDailyReportService.php', max_lines: 4_800, ratchet_max_lines: 5_059, boundary: 'daily report fact selection, generation, and persistence concerns' },
  { path: 'app/controller/concern/Phase1EmployeeConsoleConcern.php', max_lines: 4_000, ratchet_max_lines: 4_977, boundary: 'phase-one employee console orchestration; zero-growth until page workflow extraction' },
  { path: 'app/controller/concern/BusinessDisplayConcern.php', max_lines: 4_000, ratchet_max_lines: 5_073, boundary: 'business display composition; zero-growth until metric-domain extraction' },
  { path: 'app/controller/concern/OnlineDataManualFetchConcern.php', max_lines: 4_000, ratchet_max_lines: 4_013, boundary: 'manual online-data collection orchestration; zero-growth until platform-flow extraction' },
  { path: 'tests/Support/OnlineData/CtripTestCases.php', max_lines: 3_250, ratchet_max_lines: 3_559, boundary: 'Ctrip contract tests' },
  { path: 'tests/PlatformDataSyncServiceTest.php', max_lines: 5_000, ratchet_max_lines: 6_334, boundary: 'platform sync integration and current-run evidence contracts; zero-growth until fixture-domain extraction' },
  { path: 'tests/Support/OnlineData/MeituanTestCases.php', max_lines: 2_200, boundary: 'Meituan contract tests' },
  { path: 'tests/Support/OnlineData/ProfileTestCases.php', max_lines: 2_450, boundary: 'Profile contract tests' },
  { path: 'tests/Support/OnlineData/AutoFetchTestCases.php', max_lines: 800, boundary: 'AutoFetch contract tests' },
  { path: 'app/command/AutoFetchOnlineData.php', max_lines: 4_000, ratchet_max_lines: 5_235, boundary: 'hotel-scoped collection planning, execution, durable receipts, owner-safe locking, and fail-closed source selection; zero-growth until command orchestration extraction' },
  { path: 'app/controller/concern/AgentOtaExecutionIntentConcern.php', max_lines: 250, boundary: 'Agent execution intents' },
  { path: 'app/controller/concern/AgentCapturedOtaAnalysisConcern.php', max_lines: 2_450, ratchet_max_lines: 2_507, boundary: 'captured OTA analysis plus truthful decision-route composition; zero-growth until route service extraction' },
  { path: 'app/controller/concern/AgentOtaDiagnosisBuildConcern.php', max_lines: 1_500, ratchet_max_lines: 2_457, boundary: 'OTA diagnosis build' },
  { path: 'app/controller/concern/AgentOtaDiagnosisPersistenceConcern.php', max_lines: 1_750, ratchet_max_lines: 2_399, boundary: 'OTA diagnosis persistence including route-version readback; zero-growth until persistence extraction' },
  { path: 'app/controller/concern/AutoFetchProfileSyncConcern.php', max_lines: 300, boundary: 'Profile sync readback' },
  { path: 'app/controller/concern/CtripAutoFetchExecutionConcern.php', max_lines: 1_700, ratchet_max_lines: 1_773, boundary: 'Ctrip auto-fetch execution' },
  { path: 'app/controller/concern/MeituanAutoFetchExecutionConcern.php', max_lines: 500, boundary: 'Meituan auto-fetch execution' },
  { path: 'app/service/concern/PlatformDataSourceExecutionConcern.php', max_lines: 1_100, boundary: 'platform source execution' },
  { path: 'app/service/concern/PlatformSyncTaskConcern.php', max_lines: 1_900, ratchet_max_lines: 2_349, boundary: 'platform sync tasks' },
  { path: 'app/service/concern/PlatformDataPersistenceConcern.php', max_lines: 1_700, boundary: 'platform persistence' },
  { path: 'app/service/operation/OperationSnapshotConcern.php', max_lines: 2_300, boundary: 'operation snapshots' },
  { path: 'app/service/operation/OperationAlertConcern.php', max_lines: 1_000, boundary: 'operation alerts' },
  { path: 'app/service/operation/OperationExecutionReceiptConcern.php', max_lines: 400, boundary: 'auditable execution-receipt truth contract' },
  { path: 'app/service/operation/OperationEffectReadbackConcern.php', max_lines: 500, boundary: 'effect review and controlled-replication source readback' },
  { path: 'app/service/concern/OtaLocalCollectorLeaseConcern.php', max_lines: 500, boundary: 'local collector lease ownership and recovery' },
  { path: 'app/service/concern/OtaLocalCollectorManualLoginConcern.php', max_lines: 250, boundary: 'manual Profile login resume identity contract' },
  { path: 'app/service/concern/AiDailyReportReadinessConcern.php', max_lines: 150, boundary: 'AI daily report authoritative-loop readiness' },
]);

export const SOURCE_HOTSPOT_DISCOVERY = Object.freeze({
  roots: Object.freeze(['app', 'public', 'tests']),
  extensions: Object.freeze(['.php', '.js', '.mjs']),
  max_lines: 5_000,
  max_lines_by_root: Object.freeze({ app: 4_000 }),
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
