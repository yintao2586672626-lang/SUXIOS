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
