import assert from 'node:assert/strict';
import { registerHooks, stripTypeScriptTypes } from 'node:module';
import { readFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { pathToFileURL, fileURLToPath } from 'node:url';
import { createHash } from 'node:crypto';

// Execute reviewed source services, never its server, deployment or integrations.
const root = resolve(process.argv[2] || '');
const servicePath = resolve(root, 'apps/api/src/modules/training-cycles/service.ts');
const sha = createHash('sha256').update(readFileSync(servicePath)).digest('hex');
assert.equal(sha, 'fa6d687680f819e123a3c7ee096cd535f7458edbf6ab358eb33adb596d79f0e1');
const sourcePrefix = pathToFileURL(root + '/').href;
registerHooks({
  resolve(specifier, context, next) {
    if (context.parentURL?.startsWith(sourcePrefix) && specifier.endsWith('.js')) {
      const candidate = new URL(specifier.replace(/\.js$/, '.ts'), context.parentURL);
      if (existsSync(fileURLToPath(candidate))) return { url: candidate.href, shortCircuit: true };
    }
    return next(specifier, context);
  },
  load(url, context, next) {
    if (url.startsWith(sourcePrefix) && url.endsWith('.ts')) {
      return { format: 'module', source: stripTypeScriptTypes(readFileSync(fileURLToPath(url), 'utf8'), { mode: 'transform' }), shortCircuit: true };
    }
    return next(url, context);
  },
});
const { openDatabase, runMigrations } = await import(pathToFileURL(resolve(root, 'apps/api/src/db/database.ts')));
const { TrainingCycleService, classifyCause } = await import(pathToFileURL(servicePath));
const { attachTrainingTaskEvidence } = await import(pathToFileURL(resolve(root, 'apps/api/src/modules/training-cycles/task-evidence.ts')));
const db = openDatabase(':memory:');
runMigrations(db);
const now = '2026-09-26T00:00:00.000Z';
function insert(table, values) {
  const keys = Object.keys(values);
  db.prepare(`INSERT INTO ${table} (${keys.join(',')}) VALUES (${keys.map(() => '?').join(',')})`).run(...Object.values(values));
}
insert('groups', {id:'g',code:'synthetic',name:'隔离测试集团',created_at:now});
insert('hotels', {id:'h',group_id:'g',code:'synthetic',name:'隔离测试门店',created_at:now});
insert('departments', {id:'d',hotel_id:'h',code:'front',name:'测试前台',created_at:now});
insert('positions', {id:'p',department_id:'d',code:'front',name:'测试岗位',position_type:'FRONT_DESK',created_at:now});
insert('users', {id:'u',username:'synthetic-only',password_hash:'NOT_A_LOGIN_CREDENTIAL',display_name:'隔离样例',created_at:now});
insert('employees', {id:'e',user_id:'u',hotel_id:'h',position_id:'p',employee_no:'synthetic',hired_at:now,created_at:now});
insert('employee_journeys', {id:'j',employee_id:'e',current_stage:'CONTINUOUS_GROWTH',updated_at:now});
for (let i=0;i<14;i++) insert('competency_definitions',{id:`def${i}`,position_type:'FRONT_DESK',competency_key:`k${i}`,name:`测试维度${i}`,threshold:80,weight:1,sort_order:i});
const actor = { userId:'u', role:'HOTEL_MANAGER', hotelId:'h', groupId:'g' };
const service = new TrainingCycleService(db, () => new Date(now));
assert.equal(classifyCause({}), 'UNKNOWN');
assert.equal(classifyCause({ knowledgeScore:40 }), 'KNOWLEDGE');
assert.equal(classifyCause({ objectiveConstraint:'缺少设备', knowledgeScore:40 }), 'OBJECTIVE');
assert.throws(() => classifyCause({knowledgeScore:NaN}), /INVALID_CAUSE_SIGNAL/);
const empty = service.generateMonthly(actor, {employeeId:'e',period:'2026-09'});
assert.equal(empty.cycle, null);
assert.equal(db.prepare('SELECT overall_score FROM competency_snapshots WHERE id=?').get(empty.snapshotId).overall_score,80);
insert('work_metrics',{id:'metric',employee_id:'e',period:'2026-09',metric_key:'k0',value:40,source:'MANUAL',source_record_id:'synthetic',created_at:now});
assert.equal(service.generateMonthly(actor,{employeeId:'e',period:'2026-09'}).snapshotId,empty.snapshotId);
const frozen = JSON.parse(db.prepare('SELECT scores_json FROM competency_snapshots WHERE id=?').get(empty.snapshotId).scores_json);
assert.equal(frozen.lineage.k0.observed,false);
// Seed a task to exercise the exact shipped evidence function independently of course configuration.
insert('training_cycles',{id:'c',employee_id:'e',period:'2026-08',target_competency_key:'k0',baseline_score:40,status:'ASSIGNED',outcome_reason:'SKILL',created_at:now});
insert('training_tasks',{id:'t',cycle_id:'c',task_type:'MENTORING',title:'隔离实操',status:'ASSIGNED',assigned_at:now});
assert.throws(()=>attachTrainingTaskEvidence(db,{taskId:'t',expectedType:'MENTORING',completedAt:now}),/TRAINING_EVIDENCE_REQUIRED/);
attachTrainingTaskEvidence(db,{taskId:'t',expectedType:'MENTORING',evidenceText:'隔离实操记录',completedAt:now});
assert.equal(db.prepare('SELECT status FROM training_cycles WHERE id=?').get('c').status,'AWAITING_VALIDATION');
assert.throws(()=>attachTrainingTaskEvidence(db,{taskId:'t',expectedType:'MENTORING',evidenceText:'覆盖原证据',completedAt:now}),/TRAINING_TASK_EVIDENCE_CONFLICT/);
assert.throws(()=>service.verify(actor,{cycleId:'c',validationPeriod:'2026-09'}),/VALIDATION_METRIC_NOT_OBSERVED/);
assert.throws(()=>service.getCycle({...actor,hotelId:'other'},'c'),/FORBIDDEN_SCOPE/);
db.close();
console.log(JSON.stringify({status:'pass',source_sha256:sha,evidence:'source_services_in_isolated_sqlite',checks:10,observed_caveats:['unobserved_default_80','late_metric_does_not_refresh_snapshot'],external_calls:0}));
