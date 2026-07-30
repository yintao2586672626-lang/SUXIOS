import fs from 'node:fs';
import path from 'node:path';

export const SOURCE_HOTSPOT_BUDGETS = Object.freeze([
  { path: 'public/app-main.js', max_lines: 45_755, boundary: 'deferred frontend helpers and components; zero-growth until the next extraction' },
  { path: 'tests/OnlineDataTest.php', max_lines: 2_800, boundary: 'platform test-case traits' },
  { path: 'app/controller/Agent.php', max_lines: 2_700, boundary: 'Agent OTA concern traits' },
  { path: 'app/controller/concern/AutoFetchConcern.php', max_lines: 5_200, boundary: 'platform execution concern traits' },
  { path: 'app/service/PlatformDataSyncService.php', max_lines: 3_800, boundary: 'data source, task, and persistence concerns' },
  { path: 'app/service/OperationManagementService.php', max_lines: 4_800, boundary: 'snapshot and alert concerns' },
  { path: 'tests/Support/OnlineData/CtripTestCases.php', max_lines: 3_250, boundary: 'Ctrip contract tests' },
  { path: 'tests/Support/OnlineData/MeituanTestCases.php', max_lines: 2_200, boundary: 'Meituan contract tests' },
  { path: 'tests/Support/OnlineData/ProfileTestCases.php', max_lines: 2_450, boundary: 'Profile contract tests' },
  { path: 'tests/Support/OnlineData/AutoFetchTestCases.php', max_lines: 800, boundary: 'AutoFetch contract tests' },
  { path: 'app/controller/concern/AgentOtaExecutionIntentConcern.php', max_lines: 250, boundary: 'Agent execution intents' },
  { path: 'app/controller/concern/AgentCapturedOtaAnalysisConcern.php', max_lines: 2_450, boundary: 'captured OTA analysis' },
  { path: 'app/controller/concern/AgentOtaDiagnosisBuildConcern.php', max_lines: 1_500, boundary: 'OTA diagnosis build' },
  { path: 'app/controller/concern/AgentOtaDiagnosisPersistenceConcern.php', max_lines: 1_750, boundary: 'OTA diagnosis persistence' },
  { path: 'app/controller/concern/AutoFetchProfileSyncConcern.php', max_lines: 300, boundary: 'Profile sync readback' },
  { path: 'app/controller/concern/CtripAutoFetchExecutionConcern.php', max_lines: 1_700, boundary: 'Ctrip auto-fetch execution' },
  { path: 'app/controller/concern/MeituanAutoFetchExecutionConcern.php', max_lines: 500, boundary: 'Meituan auto-fetch execution' },
  { path: 'app/service/concern/PlatformDataSourceExecutionConcern.php', max_lines: 1_100, boundary: 'platform source execution' },
  { path: 'app/service/concern/PlatformSyncTaskConcern.php', max_lines: 1_900, boundary: 'platform sync tasks' },
  { path: 'app/service/concern/PlatformDataPersistenceConcern.php', max_lines: 1_700, boundary: 'platform persistence' },
  { path: 'app/service/operation/OperationSnapshotConcern.php', max_lines: 2_300, boundary: 'operation snapshots' },
  { path: 'app/service/operation/OperationAlertConcern.php', max_lines: 1_000, boundary: 'operation alerts' },
]);

export function sourceLineCount(source) {
  const normalized = String(source || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
  if (normalized === '') return 0;
  return normalized.endsWith('\n')
    ? normalized.slice(0, -1).split('\n').length
    : normalized.split('\n').length;
}

export function inspectSourceHotspotBudget(repoRoot, budgets = SOURCE_HOTSPOT_BUDGETS) {
  const root = path.resolve(repoRoot);
  const files = [];
  const failures = [];

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
    const row = {
      ...budget,
      actual_lines: actualLines,
      headroom_lines: budget.max_lines - actualLines,
    };
    files.push(row);
    if (actualLines > budget.max_lines) {
      failures.push({
        path: budget.path,
        reason: 'line_budget_exceeded',
        actual_lines: actualLines,
        max_lines: budget.max_lines,
      });
    }
  }

  return {
    schema_version: 1,
    files,
    failures,
  };
}
