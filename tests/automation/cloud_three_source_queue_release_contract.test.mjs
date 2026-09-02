import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = (relativePath) => fs.readFileSync(path.join(root, relativePath), 'utf8');

const releaseInstaller = read('deploy/cloud/install_release.sh');
const queueInstaller = read('deploy/systemd/install_cloud_three_source_queue.sh');

const functionBody = (name) => releaseInstaller.match(
  new RegExp(`${name}\\(\\) \\{[\\s\\S]*?\\n\\}`)
)?.[0] || '';

test('cloud release snapshots an installed queue without implicitly installing an absent queue', () => {
  const switchRelease = releaseInstaller.indexOf('mv -Tf "$ROLLBACK_LINK" "$CURRENT_LINK"');
  const captureInstalled = releaseInstaller.indexOf('systemctl cat "$THREE_SOURCE_QUEUE_TIMER"');
  const captureEnabled = releaseInstaller.indexOf('systemctl is-enabled --quiet "$THREE_SOURCE_QUEUE_TIMER"');
  const captureActive = releaseInstaller.indexOf('systemctl is-active --quiet "$THREE_SOURCE_QUEUE_TIMER"');
  const healthGate = releaseInstaller.indexOf('if ! verify_health; then');
  const refreshGate = releaseInstaller.indexOf(
    'if ! refresh_three_source_queue_for_release "$RELEASE_DIR"; then'
  );
  const refreshFunction = functionBody('refresh_three_source_queue_for_release');

  assert.match(
    releaseInstaller,
    /THREE_SOURCE_QUEUE_TIMER="suxios-cloud-three-source-queue\.timer"/
  );
  assert.ok(captureInstalled >= 0 && captureInstalled < switchRelease);
  assert.ok(captureEnabled >= 0 && captureEnabled < switchRelease);
  assert.ok(captureActive >= 0 && captureActive < switchRelease);
  assert.ok(healthGate >= 0 && healthGate < refreshGate);
  assert.match(
    releaseInstaller,
    /THREE_SOURCE_QUEUE_PREVIOUS_STATE installed=%s enabled=%s active=%s/
  );
  assert.match(
    refreshFunction,
    /if \[\[ \$THREE_SOURCE_QUEUE_WAS_INSTALLED -ne 1 \]\]; then\s+return 0/
  );
  assert.ok(
    refreshFunction.indexOf('THREE_SOURCE_QUEUE_WAS_INSTALLED -ne 1')
      < refreshFunction.indexOf('queue_installer_for_release')
  );
});

test('installed queue refresh preserves enabled and active independently without enable --now', () => {
  const lifecycleFunction = functionBody('restore_three_source_queue_lifecycle');
  const refreshFunction = functionBody('refresh_three_source_queue_for_release');
  const preserveBranch = queueInstaller.match(
    /if \[\[ \$PRESERVE_LIFECYCLE -eq 1 \]\]; then[\s\S]*?\nfi/
  )?.[0] || '';

  assert.match(queueInstaller, /--preserve-lifecycle\) PRESERVE_LIFECYCLE=1/);
  assert.match(preserveBranch, /INSTALLED_LIFECYCLE_PRESERVED/);
  assert.doesNotMatch(preserveBranch, /systemctl (enable|disable|start|stop|restart)/);
  assert.match(refreshFunction, /--install\s+\\\s*\n\s*--preserve-lifecycle/);
  assert.match(lifecycleFunction, /systemctl enable "\$THREE_SOURCE_QUEUE_TIMER"/);
  assert.match(
    lifecycleFunction,
    /if ! systemctl is-active --quiet "\$THREE_SOURCE_QUEUE_TIMER"; then\s+systemctl start "\$THREE_SOURCE_QUEUE_TIMER"/
  );
  assert.doesNotMatch(lifecycleFunction, /enable --now|start "[^\n]*\.service"/);
  assert.match(
    lifecycleFunction,
    /THREE_SOURCE_QUEUE_WAS_ENABLED[\s\S]*systemctl is-enabled --quiet/
  );
  assert.match(
    lifecycleFunction,
    /THREE_SOURCE_QUEUE_WAS_ACTIVE[\s\S]*systemctl is-active --quiet/
  );
});

test('disabled inactive installed queue does not require an unused Node runtime', () => {
  const releaseRuntimeGuard = releaseInstaller.slice(
    releaseInstaller.indexOf('CLOUD_NODE_RUNTIME_ENABLED='),
    releaseInstaller.indexOf('if [[ "$CLOUD_NODE_RUNTIME_ENABLED" == "1" ]]')
  );

  assert.match(releaseRuntimeGuard, /systemctl is-enabled --quiet "\$THREE_SOURCE_QUEUE_TIMER"/);
  assert.match(releaseRuntimeGuard, /systemctl is-active --quiet "\$THREE_SOURCE_QUEUE_TIMER"/);
  assert.doesNotMatch(releaseRuntimeGuard, /systemctl cat/);
  assert.match(
    queueInstaller,
    /PRESERVE_LIFECYCLE -eq 1[\s\S]*! systemctl is-enabled --quiet "\$TIMER_NAME"[\s\S]*! systemctl is-active --quiet "\$TIMER_NAME"[\s\S]*QUEUE_RUNTIME_REQUIRED=0/
  );
  assert.match(
    queueInstaller,
    /if \[\[ \$QUEUE_RUNTIME_REQUIRED -eq 1 \]\]; then\s+command -v node/
  );
});

test('disabled inactive queue remains disabled and inactive after refresh', () => {
  const lifecycleFunction = functionBody('restore_three_source_queue_lifecycle');

  assert.match(
    lifecycleFunction,
    /else\s+systemctl disable "\$THREE_SOURCE_QUEUE_TIMER"/
  );
  assert.match(
    lifecycleFunction,
    /else\s+systemctl stop "\$THREE_SOURCE_QUEUE_TIMER"/
  );
  assert.match(
    lifecycleFunction,
    /elif systemctl is-enabled --quiet "\$THREE_SOURCE_QUEUE_TIMER"; then\s+return 1/
  );
  assert.match(
    lifecycleFunction,
    /elif systemctl is-active --quiet "\$THREE_SOURCE_QUEUE_TIMER"; then\s+return 1/
  );
});

test('queue refresh failure restores current link, queue unit state and formal unit', () => {
  const installerSelector = functionBody('queue_installer_for_release');
  const queueRestore = functionBody('restore_previous_three_source_queue');
  const rollback = functionBody('rollback_and_verify');
  const queueFailure = releaseInstaller.match(
    /if ! refresh_three_source_queue_for_release "\$RELEASE_DIR"; then[\s\S]*?\nfi/
  )?.[0] || '';

  assert.match(installerSelector, /grep -Fq -- '--preserve-lifecycle\)'/);
  assert.match(installerSelector, /new_installer="\$RELEASE_DIR\/deploy\/systemd\/install_cloud_three_source_queue\.sh"/);
  assert.match(queueRestore, /refresh_three_source_queue_for_release "\$PREVIOUS_RELEASE"/);
  assert.match(rollback, /ln -sfn "\$PREVIOUS_RELEASE" "\$CURRENT_LINK"/);
  assert.match(rollback, /restore_previous_formal_dispatch/);
  assert.match(rollback, /restore_previous_three_source_queue/);
  assert.match(queueFailure, /rollback_and_verify/);
  assert.match(queueFailure, /queue unit and formal unit restored/);
  assert.match(queueFailure, /exit 83/);
});

test('successful release refreshes formal dispatch exactly once before the queue', () => {
  const formalRefreshGate = 'if ! refresh_formal_dispatch_for_release "$RELEASE_DIR"; then';
  const queueRefreshGate = 'if ! refresh_three_source_queue_for_release "$RELEASE_DIR"; then';

  assert.equal(releaseInstaller.split(formalRefreshGate).length - 1, 1);
  assert.equal(releaseInstaller.split(queueRefreshGate).length - 1, 1);
  assert.ok(releaseInstaller.indexOf(formalRefreshGate) < releaseInstaller.indexOf(queueRefreshGate));
});

test('cloud release installs and verifies the two all-active internal operation timers', () => {
  const installFunction = functionBody('install_internal_operation_timers');
  const switchRelease = releaseInstaller.indexOf('mv -Tf "$ROLLBACK_LINK" "$CURRENT_LINK"');
  const dailyInstalled = releaseInstaller.indexOf('systemctl cat "$INTERNAL_DAILY_TIMER"');
  const reviewInstalled = releaseInstaller.indexOf('systemctl cat "$INTERNAL_REVIEW_TIMER"');

  assert.ok(dailyInstalled >= 0 && dailyInstalled < switchRelease);
  assert.ok(reviewInstalled >= 0 && reviewInstalled < switchRelease);
  assert.match(installFunction, /mktemp -d \/run\/suxios-internal-operation-units\.XXXXXX/);
  assert.match(installFunction, /cp -a -- "\$target" "\$INTERNAL_OPERATION_UNIT_BACKUP_DIR\/\$unit"/);
  assert.match(installFunction, /install -o root -g root -m 0644/);
  assert.match(installFunction, /systemd-analyze verify "\$\{installed_paths\[@\]\}"/);
  assert.match(
    installFunction,
    /systemctl enable "\$INTERNAL_DAILY_TIMER" "\$INTERNAL_REVIEW_TIMER"/
  );
  assert.ok(
    installFunction.indexOf('systemctl start "$INTERNAL_REVIEW_TIMER"')
      < installFunction.indexOf('systemctl start "$INTERNAL_DAILY_TIMER"')
  );
  assert.match(installFunction, /systemctl is-enabled --quiet "\$INTERNAL_DAILY_TIMER" \|\| return 1/);
  assert.match(installFunction, /systemctl is-active --quiet "\$INTERNAL_REVIEW_TIMER" \|\| return 1/);
});

test('internal timer install failure restores unit files services and exact prior lifecycle', () => {
  const restoreFunction = functionBody('restore_internal_operation_timer_lifecycle');
  const assertFunction = functionBody('assert_internal_operation_timer_lifecycle');
  const rollbackFunction = functionBody('rollback_and_verify');
  const failureGate = releaseInstaller.match(
    /if ! install_internal_operation_timers; then[\s\S]*?\nfi/
  )?.[0] || '';

  assert.match(restoreFunction, /disable --now "\$INTERNAL_DAILY_TIMER" "\$INTERNAL_REVIEW_TIMER"/);
  assert.match(restoreFunction, /suxios-daily-operating-preparation@all-active\.service/);
  assert.match(restoreFunction, /suxios-operation-scheduled-reviews@all-active\.service/);
  assert.match(restoreFunction, /rm -f -- "\$target"/);
  assert.match(restoreFunction, /cp -a -- "\$backup" "\$target"/);
  assert.match(restoreFunction, /assert_internal_operation_timer_lifecycle[\s\S]*INTERNAL_DAILY_WAS_INSTALLED/);
  assert.match(restoreFunction, /assert_internal_operation_timer_lifecycle[\s\S]*INTERNAL_REVIEW_WAS_INSTALLED/);
  assert.match(assertFunction, /systemctl cat "\$timer"/);
  assert.match(assertFunction, /systemctl is-enabled --quiet "\$timer"/);
  assert.match(assertFunction, /systemctl is-active --quiet "\$timer"/);
  assert.match(rollbackFunction, /restore_internal_operation_timer_lifecycle/);
  assert.ok(
    rollbackFunction.indexOf('restore_internal_operation_timer_lifecycle')
      < rollbackFunction.indexOf('rm -f "$CURRENT_LINK"')
  );
  assert.match(failureGate, /rollback_and_verify/);
  assert.match(failureGate, /timer lifecycle restored/);
  assert.match(failureGate, /exit 84/);
});
