import fs from 'node:fs';
import path from 'node:path';

export const SOURCE_CONCERN_PATHS = Object.freeze({
  'app/controller/Agent.php': Object.freeze([
    'app/controller/concern/AgentOtaExecutionIntentConcern.php',
    'app/controller/concern/AgentCapturedOtaAnalysisConcern.php',
    'app/controller/concern/AgentOtaDiagnosisBuildConcern.php',
    'app/controller/concern/AgentOtaDiagnosisPersistenceConcern.php',
  ]),
  'app/controller/concern/AutoFetchConcern.php': Object.freeze([
    'app/controller/concern/AutoFetchProfileSyncConcern.php',
    'app/controller/concern/CtripAutoFetchExecutionConcern.php',
    'app/controller/concern/MeituanAutoFetchExecutionConcern.php',
  ]),
  'app/service/PlatformDataSyncService.php': Object.freeze([
    'app/service/concern/PlatformDataSourceExecutionConcern.php',
    'app/service/concern/PlatformSyncTaskConcern.php',
    'app/service/concern/PlatformDataPersistenceConcern.php',
  ]),
  'app/service/OperationManagementService.php': Object.freeze([
    'app/service/operation/OperationSnapshotConcern.php',
    'app/service/operation/OperationAlertConcern.php',
  ]),
});

export function readSourceAggregate(relativePath, options = {}) {
  const repoRoot = path.resolve(options.repoRoot || process.cwd());
  const normalizedPath = String(relativePath || '')
    .replaceAll('\\', '/')
    .replace(/^\/+/, '');
  const members = [
    normalizedPath,
    ...(SOURCE_CONCERN_PATHS[normalizedPath] || []),
  ];

  return members.map((member) => {
    const absolutePath = path.join(repoRoot, member);
    if (!fs.existsSync(absolutePath)) {
      throw new Error(`Source aggregate member is missing: ${member}`);
    }
    return fs.readFileSync(absolutePath, 'utf8');
  }).join('\n');
}
