import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const read = (relativePath) => readFileSync(path.join(repoRoot, relativePath), 'utf8');
const command = read('app/command/AutoFetchOnlineData.php');
const checker = read('scripts/check_ctrip_data_availability.php');
const runner = read('scripts/run_ctrip_data_availability_probe.ps1');
const registration = read('scripts/register_ctrip_data_availability_probe_task.ps1');

test('availability probe is fixed to the day-minus-2 Ctrip source and permits only bounded reruns', () => {
  assert.match(command, /->addOption\('force-rerun'/);
  assert.match(command, /force-rerun requires explicit hotel-id, target-date, and source-ids/);
  assert.match(command, /if \(\$executedReceipt && !\$forceRerun\)/);
  assert.match(command, /\$retryState = \$forceRerun \? \[\] : Cache::get/);
  assert.match(command, /if \(!\$forceRerun && !\$this->isScheduleRetryDue/);
  assert.match(runner, /\[int\]\$HotelId = 80/);
  assert.match(runner, /\[int\]\$CtripSourceId = 25/);
  assert.match(runner, /\.Date\.AddDays\(-2\)/);
  assert.match(runner, /'--force-rerun'/);
});

test('availability is claimed only after Ctrip readback and positive Qunar traffic', () => {
  assert.match(checker, /->where\('readback_verified', 1\)/);
  assert.match(checker, /source_trace_id/);
  assert.match(checker, /\['traffic', 'flow', 'conversion'\]/);
  assert.match(checker, /\['list_exposure', 'detail_exposure', 'order_filling_num', 'order_submit_num'\]/);
  assert.match(checker, /\$available = \$ctripReadbackPresent && \$qunarTrafficPositive/);
  assert.match(checker, /\$collectionTaskVerified = \$collectionTaskStatus === 'success'/);
  assert.match(checker, /\$taskTargetDate === \$options\['target_date'\]/);
  assert.match(checker, /'claim_allowed' => \$available/);
  assert.doesNotMatch(checker, /raw_data/);
  assert.match(runner, /first_available_at/);
  assert.match(runner, /\$observationValid = \$null -ne \$availability -and \(\$receiptVerified -or \$collectionTaskVerified\)/);
  assert.match(runner, /System\.Collections\.Generic\.List\[object\]/);
  assert.match(runner, /\$attemptHistory\.Add\(\[pscustomobject\]\$attempt\)/);
  assert.match(runner, /runtime\\ctrip-availability/);
});

test('task runs at 06:05 and 07:05 without 25-minute repetition or overlap', () => {
  assert.match(registration, /\$triggerTimes = @\('06:05', '07:05'\)/);
  assert.match(registration, /New-ScheduledTaskTrigger -Daily -At \$_/);
  assert.match(registration, /-MultipleInstances IgnoreNew/);
  assert.match(registration, /-ExecutionTimeLimit \(New-TimeSpan -Minutes 50\)/);
  assert.match(registration, /-LogonType Interactive/);
  assert.match(registration, /starts_task_immediately = \$false/);
  assert.doesNotMatch(registration, /RepetitionInterval|Start-ScheduledTask|25\s*minutes/i);
});

test('scheduled action carries no browser credential material', () => {
  assert.match(registration, /credentials_in_arguments = \$false/);
  assert.match(registration, /-Run -HotelId \{1\} -CtripSourceId \{2\}/);
  assert.doesNotMatch(registration, /-IdentityFile|\.pem|--cookie|--token|--password/i);
});
