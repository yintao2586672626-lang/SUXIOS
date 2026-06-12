import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const checks = [];

function read(file) {
  const target = path.join(root, file);
  return fs.existsSync(target) ? fs.readFileSync(target, 'utf8') : '';
}

function check(file, label, ok, detail = '') {
  checks.push({ file, label, ok: Boolean(ok), detail });
}

function includesAll(file, label, needles) {
  const source = read(file);
  const missing = needles.filter((needle) => !source.includes(needle));
  check(file, label, missing.length === 0, missing.join(', '));
}

function packageScript(name, command) {
  let actual = '';
  try {
    actual = JSON.parse(read('package.json')).scripts?.[name] ?? '';
  } catch {
    actual = '';
  }
  check('package.json', `package script ${name}`, actual === command, `${name}: ${command}`);
}

includesAll('docs/phase1_ota_trusted_loop_audit.md', 'audit names current phase-one state honestly', [
  '第一阶段已经具备结构化基础，但不能判定为业务完成',
  '美团手动批量 direct result / queued 显式异常合同已闭合',
  '真实当天携程/美团采集结果尚未完成端到端证明',
  'AI 诊断还缺真实当天 OTA 证据样例',
  '运营执行闭环还缺真实样例',
]);

includesAll('docs/phase1_ota_trusted_loop_audit.md', 'audit records structural verification gates', [
  'npm.cmd run verify:phase1-ota-loop',
  'npm.cmd run verify:phase1-employee-console',
  'npm.cmd run verify:phase1-gap-explanations',
  'npm.cmd run verify:public-entry',
  'npm.cmd run verify:e2e-contracts',
  'passed',
]);

includesAll('docs/phase1_ota_trusted_loop_audit.md', 'audit scopes next work to P0/P1/P2', [
  'P0：补真实当天携程/美团采集样例',
  'capture -> persistence -> UI display',
  'P0：补 AI 诊断真实证据样例',
  'P1：补运营执行样例',
  'P2：把字段缺口解释接入更多员工可见位置',
  '不做无基准的性能提升声明',
]);

includesAll('docs/phase1_ota_trusted_loop_audit.md', 'audit keeps protected boundaries', [
  '不重写携程/美团采集逻辑',
  '不新增 OTA 明细表',
  '不把 OTA 渠道数据包装成全酒店经营事实',
]);

includesAll('docs/phase1_ota_trusted_loop_goal.md', 'goal document references audit verifier', [
  'npm.cmd run verify:phase1-ota-audit',
]);

packageScript('verify:phase1-ota-audit', 'node scripts/verify_phase1_ota_trusted_loop_audit.mjs');

const failures = checks.filter((check) => !check.ok);

if (failures.length > 0) {
  console.error('Phase 1 OTA trusted loop audit contract failed:');
  for (const failure of failures) {
    console.error(`- ${failure.file}: ${failure.label}`);
    if (failure.detail) console.error(`  missing/expected: ${failure.detail}`);
  }
  process.exit(1);
}

console.log(`[verify:phase1-ota-audit] ${checks.length} checks passed`);
