import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const html = fs.readFileSync('public/index.html', 'utf8');
const operationStatic = fs.readFileSync('public/operation-static.js', 'utf8');

const openingStaticKeys = [
  'buildOpeningProjectFormDefaults',
  'normalizeOpeningProjectFormForSubmit',
  'buildOpeningProjectFormFromProject',
  'buildOpeningOverviewCards',
  'buildOpeningCategoryProgressCards',
  'buildOpeningPositioningImpact',
  'buildOpeningTaskStats',
  'buildOpeningTaskProgressCards',
  'buildOpeningTaskProgressStages',
  'buildOpeningStatusFilterChips',
  'buildOpeningAttentionFilterChips',
  'filterOpeningTasks',
  'selectOpeningTasks',
  'areAllFilteredOpeningTasksSelected',
  'pruneOpeningTaskIds',
  'mergeOpeningTaskSelection',
  'buildOpeningAiOutputResult',
  'openingTaskIsOverdue',
  'openingTaskIsDueSoon',
  'openingTaskHasOwner',
  'openingTaskProgressPercent',
  'syncOpeningTaskStatusByProgress',
  'syncOpeningTaskProgressByStatus',
  'buildOpeningTaskUpdatePayload',
  'snapshotOpeningTaskForRollback',
  'openingTaskPatchHasChanges',
  'applyOpeningTaskPatch',
  'normalizeOpeningTaskId',
  'openingTaskDueLabel',
  'openingTaskDueClass',
  'openingTaskProgressStage',
  'openingTaskProgressTextClass',
  'openingRiskText',
  'openingRiskTextClass',
  'openingRiskClass',
  'openingCategories',
  'openingStatusOptions',
  'openingProgressQuickValues',
];

test('opening project helpers have safe setup defaults and are replaced after operation static loads', () => {
  assert.match(html, /let buildOpeningProjectFormDefaults = \(\) => \(\{/);
  assert.match(html, /let buildOpeningProjectFormFromProject = \(\) => buildOpeningProjectFormDefaults\(\);/);
  openingStaticKeys.forEach((key) => {
    assert.match(operationStatic, new RegExp(`\\b${key}\\b`), `${key} must stay exported by operation-static.js`);
    assert.match(html, new RegExp(`requireOperationStatic\\(staticConfig, '${key}'\\)`), `${key} must be bound after operation-static.js loads`);
  });
});
